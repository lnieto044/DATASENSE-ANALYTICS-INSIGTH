<?php
/**
 * CENTRO DE SOPORTE (CLIENTE)
 * Radicación con catálogo, impacto y urgencia (la prioridad y el compromiso del ANS se ven antes de enviar),
 * seguimiento con línea de tiempo, respuesta al especialista, confirmación de la solución con calificación y reapertura.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/itsm.php';
$p = 'soporte.php';

$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'soporte');

$titulo = "Centro de Soporte";
$subtitulo = "Radica y sigue tus incidentes y requerimientos";

$cl_user_id = (int) $usuario['id'];
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$cl_user_id]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

sincronizarTickets($db);
$cfg = configItsm($db);
$objetivos = objetivosSla($db);
$impactos_db = $db->query("SELECT valor_tecnico, nombre_cliente FROM parametros_impacto")->fetchAll(PDO::FETCH_ASSOC);
$urgencias_db = $db->query("SELECT valor_tecnico, nombre_cliente FROM parametros_urgencia")->fetchAll(PDO::FETCH_ASSOC);

$mis_casos = ticketsItsm($db, 't.usuario_id = ?', [$cl_user_id]);
$activos = $cerrados = [];
$conteo = ['total' => count($mis_casos), 'gestion' => 0, 'por_confirmar' => 0, 'cerrados' => 0];
foreach ($mis_casos as $t) {
    if ($t['comportamiento'] === COMP_CERRADO) {
        $cerrados[] = $t;
        $conteo['cerrados']++;
    } else {
        $activos[] = $t;
        $conteo[$t['comportamiento'] === COMP_SOLUCIONADO ? 'por_confirmar' : 'gestion']++;
    }
}
// Su resumen: además de los casos, lo que debe y lo que viene en camino, para que el portal
// muestre todo lo suyo en un solo lugar (el Dashboard de la empresa no es para el cliente)
$mis_facturas = ['pendientes' => 0, 'por_pagar' => 0.0, 'vencidas' => 0];
try {
    $consulta_fact = $db->prepare("SELECT SUM(estado <> 'Pagado' AND estado <> 'Anulada') AS pendientes,
                COALESCE(SUM(CASE WHEN estado <> 'Pagado' AND estado <> 'Anulada' THEN monto_total ELSE 0 END), 0) AS por_pagar,
                SUM(estado <> 'Pagado' AND estado <> 'Anulada' AND fecha_vencimiento < CURDATE()) AS vencidas
              FROM facturas WHERE id_usuario = ?");
    $consulta_fact->execute([$cl_user_id]);
    $fila_fact = $consulta_fact->fetch(PDO::FETCH_ASSOC);
    if ($fila_fact) {
        $mis_facturas = ['pendientes' => (int) $fila_fact['pendientes'], 'por_pagar' => (float) $fila_fact['por_pagar'],
                         'vencidas' => (int) $fila_fact['vencidas']];
    }
} catch (PDOException $e) {
    // Base sin facturación: el resumen sale en cero
}
$mis_pedidos_curso = 0;
try {
    $consulta_ped = $db->prepare("SELECT COUNT(*) FROM tienda_pedidos WHERE usuario_id = ? AND estado NOT IN ('Entregado', 'Anulado')");
    $consulta_ped->execute([$cl_user_id]);
    $mis_pedidos_curso = (int) $consulta_ped->fetchColumn();
} catch (PDOException $e) {
    // Base sin tienda
}

// Primero lo que requiere acción del cliente: confirmar una solución o responder al especialista
$orden = [COMP_SOLUCIONADO => 0, COMP_PAUSA => 1, COMP_PROCESO => 2, COMP_ABIERTO => 3];
usort($activos, function ($a, $b) use ($orden) {
    $c = ($orden[$a['comportamiento']] ?? 9) <=> ($orden[$b['comportamiento']] ?? 9);
    return $c !== 0 ? $c : strcmp($b['created_at'], $a['created_at']);
});
usort($cerrados, function ($a, $b) { return strcmp((string) $b['fecha_cierre'], (string) $a['fecha_cierre']); });

// Solo datos para el cliente: sin notas internas ni eventos privados
$casos = [];
foreach ($mis_casos as $t) {
    $casos[(int) $t['id']] = [
        'id' => (int) $t['id'], 'codigo' => $t['codigo_ticket'], 'asunto' => $t['asunto'], 'servicio' => $t['servicio'], 'categoria' => $t['categoria'],
        'tipo' => $t['tipo'], 'prioridad' => $t['prioridad'], 'estado' => $t['estado'], 'comportamiento' => $t['comportamiento'],
        'clase' => $t['clase_color'] ?: 'bg-secondary text-white', 'descripcion' => $t['descripcion'], 'adjunto' => $t['adjunto'], 'solucion' => $t['solucion'],
        'tecnico' => $t['nom_tecnico'], 'creado' => $t['created_at'], 'primera_respuesta' => $t['fecha_primera_respuesta'], 'resuelto' => $t['fecha_resolucion'],
        'cerrado' => $t['fecha_cierre'], 'compromiso' => $t['sla'] ? $t['sla']['resolucion']['vence'] : null,
        'cumplido' => $t['sla'] ? $t['sla']['resolucion']['cumplido'] : null, 'cierre_automatico' => fechaCierreAutomatico($t, $cfg), 'reanuda' => $t['sla']['reanuda'] ?? null,
        'vence_respuesta' => $t['sla']['respuesta']['vence'] ?? null, 'respuesta_cumplida' => $t['sla']['respuesta']['cumplido'] ?? null,
        'puede_reabrir' => puedeReabrirTicket($t, $cfg), 'calificacion' => (int) $t['calificacion_estrellas'], 'comentario' => $t['comentario_usuario'],
        'notas' => notasBitacora($t['respuesta_tecnica'], false), 'flujo' => flujoTicket($db, $t, $cfg, true),
    ];
}

$catalogo = $db->query("SELECT * FROM catalogo_servicios WHERE estado = 1 ORDER BY tipo_solicitud ASC, subcategoria ASC")->fetchAll(PDO::FETCH_ASSOC);
$incidentes = []; $requerimientos = [];
foreach ($catalogo as $item) {
    if ($item['tipo_solicitud'] == 'INC') $incidentes[] = $item;
    else $requerimientos[] = $item;
}

$fecha = function ($valor, $formato = 'd/m/Y H:i') { return $valor ? date($formato, strtotime($valor)) : '—'; };
$clasesPrioridad = ['Crítica' => 'bg-danger', 'Alta' => 'bg-orange', 'Media' => 'bg-warning text-dark', 'Baja' => 'bg-success'];

// Tiempos de atención explicados al cliente: duración en días hábiles y fecha límite si radica el caso en este momento
$descripcionPrioridad = [
    'Crítica' => 'Afecta a toda la organización y no puede esperar',
    'Alta' => 'Afecta a un área completa o es muy urgente',
    'Media' => 'Afecta a un grupo o puede esperar unas horas',
    'Baja' => 'Afecta solo a una persona y puede esperar',
];
$ahora = date('Y-m-d H:i:s');
$tiempos = [];
foreach (array_keys(TIPOS_TICKET) as $tipo) {
    foreach (PRIORIDADES_TICKET as $prioridad) {
        list($respuesta_min, $solucion_min, $calendario) = $objetivos[$tipo][$prioridad];
        $tiempos[$tipo][$prioridad] = [
            duracionHabilTexto($respuesta_min, $cfg, $calendario), duracionHabilTexto($solucion_min, $cfg, $calendario),
            diaHoraTexto(sumarMinutosHabiles($ahora, $respuesta_min, $calendario, $cfg)->format('Y-m-d H:i:s')),
            diaHoraTexto(sumarMinutosHabiles($ahora, $solucion_min, $calendario, $cfg)->format('Y-m-d H:i:s')),
            $calendario,
        ];
    }
}
$celdaTiempo = function ($tipo, $prioridad) use ($tiempos) {
    list($respuesta, $solucion, , $vence_solucion, $calendario) = $tiempos[$tipo][$prioridad];
    return '<div><i class="fas fa-reply text-primary me-1"></i>Respuesta: <b>' . esc($respuesta) . '</b></div>'
         . '<div><i class="fas fa-check-double text-success me-1"></i>Solución: <b>' . esc($solucion) . '</b>' . ($calendario === '24x7' ? ' (24x7)' : '') . '</div>'
         . '<div class="text-muted" style="font-size:.72rem">Si lo radicas ahora: solución antes del ' . esc($vence_solucion) . '</div>';
};
$compromiso = function (array $t) use ($fecha) {
    switch ($t['comportamiento']) {
        case COMP_SOLUCIONADO: return '<span class="accion-cliente text-success fw-bold" role="link" tabindex="0" onclick="verDetalle(' . (int) $t['id'] . ')"><i class="fas fa-user-check me-1"></i>Confirma la solución</span>';
        case COMP_PAUSA: return '<span class="accion-cliente text-warning fw-bold" role="link" tabindex="0" onclick="verDetalle(' . (int) $t['id'] . ')"><i class="fas fa-reply me-1"></i>Esperando tu respuesta</span>';
        default:
            if (!$t['sla'] || !$t['sla']['resolucion']['vence']) return '<span class="text-muted">—</span>';
            return '<span class="small">Antes de ' . $fecha($t['sla']['resolucion']['vence']) . '</span>'
                 . ($t['sla']['reanuda'] ? '<div class="text-muted" style="font-size:.72rem"><i class="fas fa-moon me-1"></i>Reloj detenido hasta ' . $fecha($t['sla']['reanuda'], 'd/m H:i') . '</div>' : '');
    }
};
$mensajes = [
    'respuesta' => 'Tu respuesta quedó registrada en el caso %s y el especialista fue notificado.',
    'confirmado' => '¡Gracias! Confirmaste la solución y el caso %s quedó cerrado.',
    'reabierto' => 'El caso %s se reabrió y el especialista fue notificado.',
];
$errores = [
    'datos' => 'Revisa los datos: servicio, impacto, urgencia, asunto y descripción son obligatorios.',
    'no_permitido' => 'Esta acción no está disponible para el estado actual del caso.', 'calificacion' => 'Selecciona de 1 a 5 estrellas para calificar la atención.',
    'respuesta_vacia' => 'Escribe tu respuesta o adjunta un archivo.', 'motivo' => 'Cuéntanos por qué la solución no funcionó para reabrir el caso.',
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soporte | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .modal-ds-header { background-color: #0A2E4F; border-bottom: 0; }
        .label-premium { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .box-premium { border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff; padding: 16px; }
        .nav-tabs-cliente .nav-link { font-size: 0.9rem; font-weight: 700; color: #64748b; border: none; padding: 15px 25px; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.2s; }
        .nav-tabs-cliente .nav-link:hover { color: #0d6efd; background-color: #f8fafc; }
        .nav-tabs-cliente .nav-link.active { color: #0d6efd; background: transparent; border-bottom: 3px solid #0d6efd; }
        .card-kpi-pro { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .card-kpi-pro:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important; }
        .table-hover tbody tr:hover { background-color: #f8fafc; transition: background-color 0.2s ease; }
        .bg-orange { background-color: #ea580c !important; color: #fff !important; }
        .accion-cliente { cursor: pointer; font-size: .85rem; }
        .dato-caso { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; height: 100%; }
        .dato-caso .etiqueta { font-size: .66rem; font-weight: 800; text-transform: uppercase; color: #64748b; display: block; }
        .estrellas { display: inline-flex; flex-direction: row-reverse; gap: 4px; }
        .estrellas input { position: absolute; opacity: 0; pointer-events: none; }
        .estrellas label { font-size: 1.9rem; color: #cbd5e1; cursor: pointer; transition: color .15s; }
        .estrellas input:checked ~ label, .estrellas label:hover, .estrellas label:hover ~ label { color: #f59e0b; }
        .estrellas input:focus-visible + label { outline: 2px solid #0d6efd; border-radius: 4px; }
        .nota { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; font-size: .9rem; }
        .nota-cliente { background: #eff6ff; border-color: #bfdbfe; }
        .explica-tiempo { display: flex; gap: 10px; align-items: flex-start; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; height: 100%; }
        .explica-tiempo > i { font-size: 1.2rem; margin-top: 3px; }
        .tabla-tiempos td { vertical-align: top; }
        .linea-tiempo { list-style: none; padding-left: 0; margin: 0; }
        .linea-tiempo li { position: relative; padding: 0 0 12px 20px; border-left: 2px solid #e2e8f0; margin-left: 6px; font-size: .85rem; }
        .linea-tiempo li::before { content: ''; position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 50%; background: #0d6efd; border: 2px solid #fff; box-shadow: 0 0 0 1px #cbd5e1; }
    </style>
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="toolbar-pro px-4 mb-4 mt-4">
            <div class="search-container-pro shadow-sm flex-grow-1">
                <i class="fas fa-search"></i>
                <input type="text" id="buscadorTickets" placeholder="Buscar por número de ticket o asunto...">
            </div>
            <button class="btn-nuevo-cliente shadow" data-bs-toggle="modal" data-bs-target="#modalTicket" style="background: #0d6efd; border:none;">
                <i class="fas fa-plus-circle me-2"></i> NUEVO CASO
            </button>
        </div>

        <?php if (($_GET['res'] ?? '') === 'ticket_creado'): ?>
            <div class="alert alert-success shadow-sm rounded-4 mb-4 p-4 mx-4 border-0" style="background-color: #ecfdf5; border-left: 5px solid #10b981 !important;">
                <div class="d-flex align-items-center">
                    <div class="me-4"><i class="fas fa-check-double fa-2x" style="color: #10b981;"></i></div>
                    <div><h5 class="fw-bold mb-1 text-success">¡Caso Radicado Exitosamente!</h5><p class="mb-0 text-dark">Tu número de seguimiento es: <strong><?= esc($_GET['codigo'] ?? '') ?></strong>. Te enviamos la confirmación con los tiempos de atención.</p></div>
                </div>
            </div>
        <?php elseif (isset($mensajes[$_GET['res'] ?? ''])): ?>
            <div class="alert alert-success shadow-sm rounded-4 mb-4 mx-4 border-0"><i class="fas fa-check-circle me-2"></i> <?= esc(sprintf($mensajes[$_GET['res']], $_GET['codigo'] ?? '')) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="alert alert-danger shadow-sm rounded-4 mb-4 mx-4 border-0"><i class="fas fa-exclamation-circle me-2"></i> <?= esc($errores[$_GET['err']] ?? 'No fue posible completar la acción.') ?></div>
        <?php endif; ?>
        <?php if (($_GET['adjunto'] ?? '') === 'rechazado'): ?>
            <div class="alert alert-warning shadow-sm rounded-4 mb-4 mx-4 border-0">
                <i class="fas fa-paperclip me-2"></i> El archivo adjunto no fue aceptado porque su formato no está permitido (usa imágenes, PDF, Office, TXT, CSV o ZIP). El resto sí quedó registrado.
            </div>
        <?php endif; ?>
        <?php if ($conteo['por_confirmar'] > 0): ?>
            <div class="alert alert-info shadow-sm rounded-4 mb-4 mx-4 border-0" id="aviso_por_confirmar">
                <i class="fas fa-user-check me-2"></i> Tienes <strong><?= $conteo['por_confirmar'] ?></strong> caso(s) resuelto(s) esperando tu confirmación. Si la solución no funcionó, puedes reabrirlos.
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4 px-4">
            <?php
            // [título, valor, icono, color, enlace (opcional), detalle (opcional)]
            $tarjetas_cliente = [
                ['TOTAL CASOS', $conteo['total'], 'fa-layer-group', '#0d6efd', '', ''],
                ['EN GESTIÓN', $conteo['gestion'], 'fa-hourglass-half', '#f59e0b', '', ''],
                ['POR CONFIRMAR', $conteo['por_confirmar'], 'fa-user-check', '#0891b2', '', ''],
                ['CERRADOS', $conteo['cerrados'], 'fa-check-circle', '#10b981', '', ''],
                ['FACTURAS POR PAGAR', $mis_facturas['pendientes'], 'fa-file-invoice-dollar', '#dc2626', 'mis_facturas.php',
                 '$ ' . number_format($mis_facturas['por_pagar'], 0, ',', '.') . ($mis_facturas['vencidas'] > 0 ? ' · ' . $mis_facturas['vencidas'] . ' vencida(s)' : '')],
                ['PEDIDOS EN CURSO', $mis_pedidos_curso, 'fa-bag-shopping', '#7c3aed', 'mis_pedidos.php', ''],
            ];
            foreach ($tarjetas_cliente as $k): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <?php if ($k[4] !== ''): ?><a href="<?= esc($k[4]) ?>" class="text-decoration-none d-block h-100"><?php endif; ?>
                <div class="card card-kpi-pro p-3 shadow-sm bg-white h-100" style="border:0; border-bottom: 4px solid <?= $k[3] ?>; border-radius: 12px;">
                    <div class="d-flex align-items-center">
                        <div class="me-3 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px; height:46px; background:<?= $k[3] ?>1a; color:<?= $k[3] ?>"><i class="fas <?= $k[2] ?> fa-lg"></i></div>
                        <div class="min-w-0"><span class="d-block text-muted small fw-bold text-uppercase"><?= $k[0] ?></span><h3 class="mb-0 fw-bold text-dark"><?= (int) $k[1] ?></h3>
                            <?php if ($k[5] !== ''): ?><span class="d-block text-muted" style="font-size: .72rem;"><?= esc($k[5]) ?></span><?php endif; ?></div>
                    </div>
                </div>
                <?php if ($k[4] !== ''): ?></a><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mx-4 mb-4 bg-white" id="horarioAtencion">
            <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                <i class="fas fa-business-time text-primary fa-lg me-1"></i>
                <div class="flex-grow-1 small"><strong>Horario de atención:</strong> <?= esc(textoHorarioItsm($cfg)) ?>. Fuera de ese horario el tiempo de tus casos se detiene y continúa el siguiente día hábil.</div>
                <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#tiemposAtencion" aria-expanded="false" aria-controls="tiemposAtencion"><i class="fas fa-stopwatch me-1"></i> ¿Cuánto tardamos en atenderte?</button>
            </div>
            <div class="collapse" id="tiemposAtencion">
                <div class="px-3 pb-3">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><div class="explica-tiempo"><i class="fas fa-reply text-primary"></i><div><b>Respuesta</b><br><span class="small">Tiempo máximo para que un especialista tome tu caso y te escriba.</span></div></div></div>
                        <div class="col-md-6"><div class="explica-tiempo"><i class="fas fa-check-double text-success"></i><div><b>Solución</b><br><span class="small">Tiempo máximo para dejar resuelto el incidente o entregado el requerimiento.</span></div></div></div>
                    </div>
                    <ul class="small text-muted mb-3 ps-3">
                        <li><b>Solo cuentan horas hábiles:</b> <?= esc(textoHorarioItsm($cfg)) ?>. Un día hábil equivale a <?= esc(duracionTexto(minutosJornada($cfg))) ?> de atención.</li>
                        <li><b>Fuera del horario:</b> si radicas de noche, en fin de semana o festivo, el tiempo empieza el siguiente día hábil a las <?= esc($cfg['hora_inicio']) ?>.</li>
                        <li><b>Mientras esperamos tu respuesta</b> (cuando te pedimos información), el tiempo se detiene.</li>
                        <li><b>En cada caso</b> verás la fecha exacta de respuesta y de solución comprometidas.</li>
                    </ul>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small tabla-tiempos">
                            <thead class="text-muted"><tr><th>Prioridad</th><th>Incidente <span class="fw-normal">(algo dejó de funcionar)</span></th><th>Requerimiento <span class="fw-normal">(solicitas algo nuevo)</span></th></tr></thead>
                            <tbody>
                                <?php foreach (PRIORIDADES_TICKET as $prioridad): ?>
                                <tr>
                                    <td><span class="badge <?= $clasesPrioridad[$prioridad] ?>"><?= esc($prioridad) ?></span><div class="text-muted" style="font-size:.72rem; max-width: 190px"><?= esc($descripcionPrioridad[$prioridad]) ?></div></td>
                                    <td><?= $celdaTiempo('INC', $prioridad) ?></td>
                                    <td><?= $celdaTiempo('RF', $prioridad) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted mb-0 mt-2" style="font-size:.75rem">La prioridad la calcula la plataforma con el impacto y la urgencia que eliges al radicar. Ejemplo: un incidente de prioridad Alta radicado un viernes a las 16:00 tiene 1 hora ese viernes y continúa el lunes a las 08:00.</p>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5 mx-4 bg-white">
            <ul class="nav nav-tabs nav-tabs-cliente px-4 pt-2 bg-light border-bottom" id="tabsCliente">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_abiertos" id="btn_tab_abiertos"><i class="fas fa-folder-open me-2"></i> Casos Abiertos</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_cerrados" id="btn_tab_cerrados"><i class="fas fa-archive me-2"></i> Histórico / Cerrados</a></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab_abiertos">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-busqueda">
                            <thead class="bg-light text-muted small fw-bold text-uppercase">
                                <tr><th class="ps-4 py-3">NÚMERO TICKET</th><th>FECHA</th><th>ASUNTO</th><th>PRIORIDAD</th><th>ESTADO</th><th>COMPROMISO</th><th class="text-end pe-4">DETALLE</th></tr>
                            </thead>
                            <tbody class="bg-white">
                                <?php foreach ($activos as $t): ?>
                                <tr data-caso="<?= (int) $t['id'] ?>">
                                    <td class="ps-4 fw-bold text-nowrap" style="color: #0d6efd;">#<?= esc($t['codigo_ticket']) ?></td>
                                    <td class="small text-muted"><?= $fecha($t['created_at']) ?></td>
                                    <td><div class="fw-bold text-dark text-truncate" style="max-width: 250px;"><?= esc($t['asunto']) ?></div><small class="text-muted"><?= esc($t['categoria']) ?></small></td>
                                    <td><span class="badge <?= $clasesPrioridad[$t['prioridad']] ?? 'bg-secondary' ?>"><?= esc($t['prioridad']) ?></span></td>
                                    <td><span class="badge <?= esc($t['clase_color'] ?: 'bg-secondary text-white') ?> shadow-sm rounded-pill px-3"><?= esc(mb_strtoupper((string) $t['estado'], 'UTF-8')) ?></span></td>
                                    <td><?= $compromiso($t) ?></td>
                                    <td class="text-end pe-4"><button class="btn btn-sm btn-light border rounded-circle shadow-sm" style="width:35px; height:35px;" onclick="verDetalle(<?= (int) $t['id'] ?>)" title="Ver detalle"><i class="fas fa-eye text-primary"></i></button></td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($activos)): ?>
                                    <tr>
                                        <td colspan="7" class="border-0">
                                            <div class="p-5 text-center my-4 mx-auto" style="max-width: 450px;">
                                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;"><i class="fas fa-inbox fa-3x"></i></div>
                                                <h4 class="fw-bold text-dark mb-2">¡Todo al día!</h4>
                                                <p class="text-muted">No tienes requerimientos ni incidentes en proceso en este momento.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab_cerrados">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-busqueda">
                            <thead class="bg-light text-muted small fw-bold text-uppercase">
                                <tr><th class="ps-4 py-3">NÚMERO TICKET</th><th>CIERRE</th><th>ASUNTO</th><th>ESTADO</th><th>TU CALIFICACIÓN</th><th class="text-end pe-4">DETALLE</th></tr>
                            </thead>
                            <tbody class="bg-white">
                                <?php foreach ($cerrados as $t): ?>
                                <tr data-caso="<?= (int) $t['id'] ?>" style="opacity: 0.9;">
                                    <td class="ps-4 fw-bold text-secondary text-nowrap">#<?= esc($t['codigo_ticket']) ?></td>
                                    <td class="small text-muted"><?= $fecha($t['fecha_cierre']) ?></td>
                                    <td><div class="fw-bold text-dark text-truncate" style="max-width: 250px;"><?= esc($t['asunto']) ?></div></td>
                                    <td><span class="badge <?= esc($t['clase_color'] ?: 'bg-dark text-white') ?> shadow-sm rounded-pill px-3"><?= esc(mb_strtoupper((string) $t['estado'], 'UTF-8')) ?></span></td>
                                    <td class="text-warning"><?= (int) $t['calificacion_estrellas'] > 0 ? str_repeat('★', (int) $t['calificacion_estrellas']) . '<span class="text-muted">' . str_repeat('☆', 5 - (int) $t['calificacion_estrellas']) . '</span>' : '<span class="text-muted small">—</span>' ?></td>
                                    <td class="text-end pe-4"><button class="btn btn-sm btn-light border rounded-circle shadow-sm" style="width:35px; height:35px;" onclick="verDetalle(<?= (int) $t['id'] ?>)" title="Ver detalle"><i class="fas fa-eye text-dark"></i></button></td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($cerrados)): ?>
                                    <tr>
                                        <td colspan="6" class="border-0">
                                            <div class="p-5 text-center my-4 mx-auto" style="max-width: 450px;">
                                                <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;"><i class="fas fa-archive fa-3x"></i></div>
                                                <h4 class="fw-bold text-dark mb-2">Sin histórico</h4>
                                                <p class="text-muted">Aún no tienes casos cerrados en el sistema.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalTicket" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" method="POST" action="procesar_soporte.php" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="crear">
            <div class="modal-header modal-ds-header p-4 d-flex align-items-center">
                <div class="icon-circle me-3"><i class="fas fa-headset fa-lg text-white"></i></div>
                <h5 class="fw-bold mb-0 text-white">Mesa de Servicios ITSM</h5>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4" style="background-color: #f8fafc;">
                <div class="row g-4">
                    <div class="col-md-5">
                        <div class="box-premium h-100">
                            <h6 class="fw-bold text-primary mb-2"><i class="fas fa-list-ul me-2"></i>Catálogo</h6>
                            <p class="text-muted mb-4" style="font-size: 0.75rem; line-height: 1.3;">
                                <strong class="text-danger">Incidente:</strong> Reportar algo que falló o se rompió.<br>
                                <strong class="text-primary">Requerimiento:</strong> Solicitar algo nuevo, accesos o cambios.
                            </p>
                            <div class="mb-4">
                                <label class="label-premium text-danger mb-2" for="nuevo_servicio">¿EN QUÉ TE AYUDAMOS? *</label>
                                <select name="id_servicio_catalogo" id="nuevo_servicio" class="form-select bg-light" required onchange="previsualizarAns()">
                                    <option value="" disabled selected>Selecciona tu caso...</option>
                                    <optgroup label="🔴 INCIDENTES (Reportar falla técnica)">
                                        <?php foreach ($incidentes as $inc): ?><option value="<?= (int) $inc['id'] ?>" data-tipo="INC"><?= esc($inc['subcategoria']) ?></option><?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="🔵 REQUERIMIENTOS (Solicitar servicio)">
                                        <?php foreach ($requerimientos as $req): ?><option value="<?= (int) $req['id'] ?>" data-tipo="RF"><?= esc($req['subcategoria']) ?></option><?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="label-premium text-dark mb-2" for="nuevo_impacto">IMPACTO</label>
                                <select name="impacto" id="nuevo_impacto" class="form-select bg-light" required onchange="previsualizarAns()">
                                    <?php foreach ($impactos_db as $i): ?><option value="<?= esc($i['valor_tecnico']) ?>"><?= esc($i['nombre_cliente']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="label-premium text-dark mb-2" for="nuevo_urgencia">URGENCIA</label>
                                <select name="urgencia" id="nuevo_urgencia" class="form-select bg-light" required onchange="previsualizarAns()">
                                    <?php foreach ($urgencias_db as $u): ?><option value="<?= esc($u['valor_tecnico']) ?>"><?= esc($u['nombre_cliente']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div id="preview_ans" class="alert alert-info py-2 px-3 mb-0 small" hidden></div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="box-premium h-100">
                            <h6 class="fw-bold text-primary mb-4"><i class="fas fa-align-left me-2"></i>Detalles del Caso</h6>
                            <div class="mb-4">
                                <label class="label-premium" for="nuevo_asunto">ASUNTO CORTO</label>
                                <input type="text" name="asunto" id="nuevo_asunto" class="form-control bg-light" maxlength="150" placeholder="Ej: Falla en inicio de sesión..." required>
                            </div>
                            <div class="mb-4">
                                <label class="label-premium" for="nuevo_descripcion">DESCRIPCIÓN DETALLADA</label>
                                <textarea name="descripcion" id="nuevo_descripcion" class="form-control bg-light" rows="5" placeholder="Describe lo mejor posible tu requerimiento o incidente..." required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="label-premium" for="nuevo_adjunto">ADJUNTAR EVIDENCIA (Opcional)</label>
                                <input type="file" name="adjunto" id="nuevo_adjunto" class="form-control bg-light">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white p-4">
                <button type="submit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold shadow-sm" style="background:#0d6efd;">
                    <i class="fas fa-paper-plane me-2"></i> RADICAR REQUERIMIENTO / INCIDENTE
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header modal-ds-header p-3 align-items-center">
                <h5 class="fw-bold mb-0 text-white" id="det_id">INC-00000</h5>
                <span class="badge shadow-sm px-3 py-2 rounded-pill ms-3" id="det_estado"></span>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0" style="background-color: #f8fafc;">
                <div class="bg-white p-4 border-bottom shadow-sm">
                    <h3 class="fw-bold text-dark mb-1" id="det_asunto"></h3>
                    <span class="text-muted small fw-bold" id="det_servicio"></span>
                </div>
                <div class="p-4">
                    <div class="row g-2 mb-4">
                        <div class="col-6 col-md-4"><div class="dato-caso"><span class="etiqueta">Prioridad</span><span id="det_prioridad"></span></div></div>
                        <div class="col-6 col-md-4"><div class="dato-caso"><span class="etiqueta">Especialista</span><span class="fw-bold text-primary" id="det_especialista">Pendiente</span></div></div>
                        <div class="col-6 col-md-4"><div class="dato-caso"><span class="etiqueta">Radicado</span><span class="fw-bold" id="det_radicado"></span></div></div>
                        <div class="col-6 col-md-6"><div class="dato-caso"><span class="etiqueta"><i class="fas fa-reply me-1"></i>Respuesta</span><span class="fw-bold" id="det_respuesta"></span></div></div>
                        <div class="col-12 col-md-6"><div class="dato-caso"><span class="etiqueta"><i class="fas fa-check-double me-1"></i>Solución</span><span class="fw-bold" id="det_compromiso"></span></div></div>
                        <div class="col-12"><div class="small text-muted">Los tiempos se cuentan en horas hábiles: <?= esc(textoHorarioItsm($cfg)) ?>.</div></div>
                    </div>

                    <div id="panel_pausa" class="alert alert-warning border-0 shadow-sm" hidden>
                        <i class="fas fa-pause-circle me-2"></i> <strong>El especialista necesita información para continuar.</strong> Responde abajo: al enviar tu respuesta el caso vuelve a atención.
                    </div>

                    <div id="det_solucion_box" class="mb-4" style="display: none;">
                        <div class="label-premium text-success mb-2 d-flex align-items-center"><i class="fas fa-check-double text-success me-2"></i> SOLUCIÓN TÉCNICA APLICADA</div>
                        <div class="box-premium text-dark shadow-sm border-success" id="det_solucion" style="white-space: pre-wrap; font-size: 0.95rem; background-color: #f0fdf4;"></div>
                    </div>

                    <form id="panel_confirmar" class="box-premium shadow-sm mb-4 border-success" method="POST" action="procesar_soporte.php" hidden>
                        <input type="hidden" name="accion" value="confirmar_solucion">
                        <input type="hidden" name="ticket_id" class="input-ticket-id">
                        <div class="label-premium text-success mb-2"><i class="fas fa-user-check me-2"></i>¿La solución resolvió tu caso?</div>
                        <p class="small text-muted mb-2" id="det_cierre_automatico"></p>
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                            <span class="small fw-bold">Califica la atención:</span>
                            <div class="estrellas" role="radiogroup" aria-label="Calificación de 1 a 5 estrellas">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="calificacion" id="estrella_<?= $i ?>" value="<?= $i ?>" required><label for="estrella_<?= $i ?>" title="<?= $i ?> de 5"><i class="fas fa-star"></i></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <textarea name="comentario" class="form-control mb-2" rows="2" placeholder="Comentario opcional sobre la atención recibida"></textarea>
                        <button type="submit" class="btn btn-success fw-bold rounded-pill px-4" id="btnConfirmarSolucion"><i class="fas fa-check me-2"></i>Confirmar solución y cerrar</button>
                    </form>

                    <form id="panel_reabrir" class="box-premium shadow-sm mb-4 border-danger" method="POST" action="procesar_soporte.php" hidden>
                        <input type="hidden" name="accion" value="reabrir">
                        <input type="hidden" name="ticket_id" class="input-ticket-id">
                        <div class="label-premium text-danger mb-2"><i class="fas fa-redo me-2"></i>¿La solución no funcionó?</div>
                        <textarea name="motivo" class="form-control mb-2" rows="2" placeholder="Cuéntanos qué sigue fallando" required></textarea>
                        <button type="submit" class="btn btn-outline-danger fw-bold rounded-pill px-4" id="btnReabrir"><i class="fas fa-redo me-2"></i>Reabrir caso</button>
                    </form>

                    <div id="det_calificacion" class="alert alert-light border shadow-sm mb-4" hidden></div>

                    <div class="label-premium text-secondary mb-2 d-flex align-items-center"><i class="fas fa-align-justify text-secondary me-2"></i> Descripción de tu solicitud</div>
                    <div class="mb-3"><div class="box-premium text-dark shadow-sm bg-white" id="det_desc" style="white-space: pre-wrap; font-size: 0.95rem;"></div></div>
                    <div class="mb-4" id="det_adjunto_box"></div>

                    <div class="mb-4">
                        <div class="label-premium text-secondary mb-2 d-flex align-items-center"><i class="fas fa-comments text-secondary me-2"></i> NOTAS Y AVANCES DEL SOPORTE</div>
                        <div id="det_resp" style="max-height: 300px; overflow-y: auto;"></div>
                    </div>

                    <form id="panel_responder" class="box-premium shadow-sm mb-4" method="POST" action="procesar_soporte.php" enctype="multipart/form-data" hidden>
                        <input type="hidden" name="accion" value="responder_cliente">
                        <input type="hidden" name="ticket_id" class="input-ticket-id">
                        <label class="label-premium text-primary mb-2" for="texto_respuesta"><i class="fas fa-reply me-2"></i>Responder al especialista</label>
                        <textarea name="texto_respuesta" id="texto_respuesta" class="form-control mb-2" rows="3" placeholder="Información adicional, respuesta a lo solicitado..."></textarea>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <input type="file" name="adjunto_respuesta" class="form-control form-control-sm" style="max-width: 320px;" aria-label="Adjuntar archivo">
                            <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 ms-auto" id="btnResponder"><i class="fas fa-paper-plane me-2"></i>Enviar respuesta</button>
                        </div>
                    </form>

                    <div class="label-premium text-secondary mb-2 d-flex align-items-center"><i class="fas fa-history text-secondary me-2"></i> LÍNEA DE TIEMPO</div>
                    <ul class="linea-tiempo box-premium shadow-sm" id="det_timeline"></ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CASOS = <?= jsJson($casos) ?>;
const OBJETIVOS = <?= jsJson($objetivos) ?>;
const TIEMPOS = <?= jsJson($tiempos) ?>;
const MATRIZ = <?= jsJson(MATRIZ_PRIORIDAD) ?>;
const HORARIO = <?= jsJson(textoHorarioItsm($cfg)) ?>;
const ABRIR = <?= jsJson((string) ($_GET['abrir'] ?? '')) ?>;
const CLASES_PRIORIDAD = <?= jsJson($clasesPrioridad) ?>;

document.getElementById('buscadorTickets').addEventListener('input', function () {
    const filtro = this.value.toLowerCase().trim();
    let enAbiertos = false, enCerrados = false;
    document.querySelectorAll('#tab_abiertos .tabla-busqueda tbody tr[data-caso]').forEach((fila) => { const v = fila.innerText.toLowerCase().includes(filtro); fila.style.display = v ? '' : 'none'; enAbiertos = enAbiertos || v; });
    document.querySelectorAll('#tab_cerrados .tabla-busqueda tbody tr[data-caso]').forEach((fila) => { const v = fila.innerText.toLowerCase().includes(filtro); fila.style.display = v ? '' : 'none'; enCerrados = enCerrados || v; });
    if (filtro !== '') {
        const abiertos = document.getElementById('btn_tab_abiertos'), cerrados = document.getElementById('btn_tab_cerrados');
        if (!enAbiertos && enCerrados && !cerrados.classList.contains('active')) new bootstrap.Tab(cerrados).show();
        else if (enAbiertos && !enCerrados && !abiertos.classList.contains('active')) new bootstrap.Tab(abiertos).show();
    }
});

// Escapa texto antes de insertarlo como HTML
function escHtml(txt) {
    return String(txt ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
}
// Enlace de descarga protegido para un adjunto de ticket (uploads/ no es accesible por URL)
function urlAdjunto(archivo) {
    return 'descargar_adjunto.php?archivo=' + encodeURIComponent(archivo);
}
function duracion(min) {
    const h = Math.floor(min / 60), m = min % 60;
    return h ? h + ' h' + (m ? ' ' + m + ' min' : '') : m + ' min';
}
function fecha(valor) {
    if (!valor) return '—';
    const [d, h] = String(valor).split(' ');
    const [a, me, di] = d.split('-');
    return `${di}/${me}/${a} ${(h || '00:00').slice(0, 5)}`;
}

// Prioridad y compromiso del ANS antes de radicar (mismos objetivos que aplica el servidor)
function previsualizarAns() {
    const sel = document.getElementById('nuevo_servicio');
    const opcion = sel.options[sel.selectedIndex];
    const tipo = opcion ? opcion.dataset.tipo : '';
    const imp = document.getElementById('nuevo_impacto').value, urg = document.getElementById('nuevo_urgencia').value;
    const caja = document.getElementById('preview_ans');
    if (!tipo || !MATRIZ[imp] || !MATRIZ[imp][urg]) { caja.hidden = true; return; }
    const prio = MATRIZ[imp][urg];
    const [respuesta, solucion, venceRespuesta, venceSolucion, cal] = TIEMPOS[tipo][prio];
    caja.innerHTML = `<i class="fas fa-stopwatch me-1"></i> Prioridad <b>${prio}</b>: te respondemos en máximo <b>${escHtml(respuesta)}</b> y lo solucionamos en máximo <b>${escHtml(solucion)}</b> `
        + (cal === '24x7' ? '(24 horas, 7 días).' : '(en horario hábil).')
        + `<div class="mt-1">Si lo radicas ahora: respuesta antes del <b>${escHtml(venceRespuesta)}</b> y solución antes del <b>${escHtml(venceSolucion)}</b>.</div>`;
    caja.hidden = false;
}

function compromisoTexto(t) {
    if (t.comportamiento === 'Solucionado') return 'Resuelto el ' + fecha(t.resuelto);
    if (t.comportamiento === 'Cerrado') return 'Cerrado el ' + fecha(t.cerrado);
    if (t.comportamiento === 'Pausa') return '<span class="text-warning"><i class="fas fa-pause-circle me-1"></i>Detenido: esperamos tu respuesta</span>';
    if (!t.compromiso) return '—';
    return 'Te la entregamos antes del ' + fecha(t.compromiso) + (t.reanuda ? `<div class="small text-muted fw-normal"><i class="fas fa-moon me-1"></i>Fuera del horario de atención: el tiempo sigue el ${fecha(t.reanuda)}</div>` : '');
}

function respuestaTexto(t) {
    if (t.primera_respuesta) return 'Te respondimos el ' + fecha(t.primera_respuesta) + (t.respuesta_cumplida === true ? ' <span class="badge bg-success">a tiempo</span>' : '');
    if (['Solucionado', 'Cerrado'].includes(t.comportamiento)) return '—';
    return t.vence_respuesta ? 'Te escribimos antes del ' + fecha(t.vence_respuesta) : '—';
}

function verDetalle(id) {
    const t = CASOS[id];
    if (!t) return;
    document.getElementById('det_id').innerText = t.codigo;
    document.getElementById('det_asunto').innerText = t.asunto;
    document.getElementById('det_servicio').innerHTML = '<i class="fas fa-folder-open me-1"></i> ' + escHtml((t.servicio || 'General') + ' · ' + (t.categoria || 'General'));
    const badge = document.getElementById('det_estado');
    badge.innerText = String(t.estado || '').toUpperCase();
    badge.className = 'badge shadow-sm px-3 py-2 rounded-pill ms-3 ' + t.clase;
    document.getElementById('det_prioridad').innerHTML = `<span class="badge ${CLASES_PRIORIDAD[t.prioridad] || 'bg-secondary'}">${escHtml(t.prioridad)}</span> <small class="text-muted">${t.tipo === 'INC' ? 'Incidente' : 'Requerimiento'}</small>`;
    document.getElementById('det_especialista').innerText = t.tecnico ? t.tecnico : 'Pendiente de asignación';
    document.getElementById('det_radicado').innerText = fecha(t.creado);
    document.getElementById('det_compromiso').innerHTML = compromisoTexto(t);
    document.getElementById('det_respuesta').innerHTML = respuestaTexto(t);
    document.getElementById('det_desc').innerText = t.descripcion;
    document.getElementById('det_adjunto_box').innerHTML = t.adjunto
        ? `<a href="${urlAdjunto(t.adjunto)}" target="_blank" class="btn btn-sm btn-outline-primary fw-bold shadow-sm rounded-pill px-3"><i class="fas fa-paperclip me-2"></i> Ver mi archivo adjunto</a>` : '';

    const boxSolucion = document.getElementById('det_solucion_box');
    if (t.solucion && t.solucion.trim() !== '' && (t.comportamiento === 'Solucionado' || t.comportamiento === 'Cerrado')) {
        boxSolucion.style.display = 'block';
        document.getElementById('det_solucion').innerHTML = escHtml(t.solucion).replace(/\n/g, '<br>');
    } else {
        boxSolucion.style.display = 'none';
    }

    document.querySelectorAll('.input-ticket-id').forEach((campo) => { campo.value = t.id; });
    document.querySelectorAll('#modalDetalle form').forEach((formulario) => formulario.reset());
    document.getElementById('panel_pausa').hidden = t.comportamiento !== 'Pausa';
    document.getElementById('panel_responder').hidden = !['Abierto', 'En Proceso', 'Pausa'].includes(t.comportamiento);
    document.getElementById('panel_confirmar').hidden = t.comportamiento !== 'Solucionado';
    document.getElementById('panel_reabrir').hidden = !t.puede_reabrir;
    document.getElementById('det_cierre_automatico').innerText = t.cierre_automatico ? `Si no respondes, el caso se cerrará automáticamente el ${fecha(t.cierre_automatico)}.` : '';
    const calificacion = document.getElementById('det_calificacion');
    calificacion.hidden = !(t.calificacion > 0);
    calificacion.innerHTML = t.calificacion > 0
        ? `<span class="text-warning fs-5">${'★'.repeat(t.calificacion)}<span class="text-muted">${'☆'.repeat(5 - t.calificacion)}</span></span> <span class="small ms-2">Tu calificación${t.comentario ? ': «' + escHtml(t.comentario) + '»' : ''}</span>` : '';

    const notas = t.notas.map((n) => `<div class="nota ${n.tipo === 'cliente' ? 'nota-cliente' : ''}"><div class="d-flex justify-content-between mb-1"><b>${n.tipo === 'cliente' ? '<i class="fas fa-user me-1"></i>Tú' : '<i class="fas fa-user-cog me-1 text-primary"></i>' + escHtml(n.autor || 'Soporte')}</b><small class="text-muted">${escHtml(n.fecha)}</small></div>${n.html}</div>`).join('');
    document.getElementById('det_resp').innerHTML = notas || `<div class="text-center py-4 bg-white border border-light rounded-4 shadow-sm"><i class="fas fa-clock text-secondary fa-lg"></i><strong class="d-block text-dark mt-2">En revisión por el equipo técnico.</strong><small class="text-muted">Pronto verás actualizaciones aquí.</small></div>`;

    const eventos = (t.flujo && t.flujo.eventos) || [];
    document.getElementById('det_timeline').innerHTML = eventos.length
        ? eventos.map((h) => `<li><div class="d-flex justify-content-between gap-2"><b>${escHtml(h.evento)}</b><small class="text-muted text-nowrap">${fecha(h.fecha)}</small></div>`
            + (h.evento === 'Cambio de estado' ? `<div class="small">${escHtml(h.estado_anterior || '')} → <b>${escHtml(h.estado_nuevo || '')}</b></div>` : `<div class="small">${escHtml(h.detalle || '')}</div>`)
            + `<small class="text-muted">${escHtml(h.usuario || '')}${h.desde_anterior ? ' · ' + duracion(h.desde_anterior.habiles) + ' hábiles después del paso anterior' : ''}</small></li>`).join('')
        : '<li class="text-muted">Sin eventos registrados.</li>';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetalle')).show();
}

if (ABRIR) {
    const caso = Object.values(CASOS).find((c) => c.codigo === ABRIR);
    if (caso) verDetalle(caso.id);
}
</script>
</body>
</html>
