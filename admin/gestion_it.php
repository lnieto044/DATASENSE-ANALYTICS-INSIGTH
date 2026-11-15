<?php
/**
 * GESTIÓN IT - CONSOLA DE LA MESA DE SERVICIOS (ITSM)
 * Bandejas por ciclo de vida, filtros de trabajo, ANS de primera respuesta y de solución calculados por el servidor
 * (includes/itsm.php) con reloj en vivo, notas, evidencias, historial del caso y registro de casos en nombre de un cliente.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/itsm.php';
$p = 'gestion_it.php';

$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'gestion_it');

$titulo = 'Gestión IT';
$subtitulo = 'Consola de especialistas: incidentes y requerimientos con ANS exacto (ITIL)';

$mi_id = (int) $usuario['id'];
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$mi_id]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

sincronizarTickets($db);
$cfg = configItsm($db);
$objetivos = objetivosSla($db);

$tecnicos = array_values(array_filter(
    $db->query("SELECT id, nombre, rol FROM usuarios WHERE estado_cuenta = 'Activo' AND rol <> 'Cliente' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC),
    function ($u) use ($db) { return tieneAcceso($u['rol'], 'gestion_it', $db); }
));
$clientes = $db->query("SELECT id, nombre, correo FROM usuarios WHERE rol = 'Cliente' AND estado_cuenta = 'Activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$catalogo = $db->query("SELECT id, tipo_solicitud, servicio, subcategoria, grupo_resolutor FROM catalogo_servicios WHERE estado = 1 ORDER BY tipo_solicitud, subcategoria")->fetchAll(PDO::FETCH_ASSOC);
$estados = array_values(array_filter(estadosTicket($db), function ($e) { return (int) $e['activo'] === 1; }));
$impactos = $db->query("SELECT valor_tecnico, nombre_cliente FROM parametros_impacto")->fetchAll(PDO::FETCH_ASSOC);
$urgencias = $db->query("SELECT valor_tecnico, nombre_cliente FROM parametros_urgencia")->fetchAll(PDO::FETCH_ASSOC);

// Casos en curso y cerrados de los últimos 120 días
$tickets = ticketsItsm($db, '(t.fecha_cierre IS NULL OR t.fecha_cierre >= ?)', [date('Y-m-d', strtotime('-120 days'))]);
$grupos = array_values(array_unique(array_filter(array_merge(array_column($catalogo, 'grupo_resolutor'), array_column($tickets, 'grupo_resolutor')))));
sort($grupos);

$en_gestion = $solucionados = $cerrados = [];
foreach ($tickets as $t) {
    if ($t['comportamiento'] === COMP_CERRADO) $cerrados[] = $t;
    elseif ($t['comportamiento'] === COMP_SOLUCIONADO) $solucionados[] = $t;
    else $en_gestion[] = $t;
}
// Primero lo más urgente: mayor consumo del ANS de solución y mayor prioridad; los casos en pausa al final
usort($en_gestion, function ($a, $b) {
    $pausa = ($a['comportamiento'] === COMP_PAUSA) <=> ($b['comportamiento'] === COMP_PAUSA);
    if ($pausa !== 0) return $pausa;
    $consumo = ($b['sla']['resolucion']['pct'] ?? 0) <=> ($a['sla']['resolucion']['pct'] ?? 0);
    return $consumo !== 0 ? $consumo : (PESOS_PRIORIDAD[$b['prioridad']] ?? 0) <=> (PESOS_PRIORIDAD[$a['prioridad']] ?? 0);
});
usort($solucionados, function ($a, $b) { return strcmp((string) $a['fecha_resolucion'], (string) $b['fecha_resolucion']); });
usort($cerrados, function ($a, $b) { return strcmp((string) $b['fecha_cierre'], (string) $a['fecha_cierre']); });
$cerrados = array_slice($cerrados, 0, 150);
$listados = array_merge($en_gestion, $solucionados, $cerrados);

// Atributos de cada fila para los filtros de la bandeja
$atributosCaso = function (array $t) use ($cfg) {
    $abierto = !in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true);
    return [
        'comp' => $t['comportamiento'],
        'nivel' => $t['sla'] && $abierto ? $t['sla']['resolucion']['nivel'] : '',
        'tecnico' => (string) (int) $t['tecnico_id'],
        'prioridad' => (string) $t['prioridad'],
        'grupo' => (string) $t['grupo_resolutor'],
        'sin-respuesta' => $abierto && empty($t['fecha_primera_respuesta']) ? '1' : '0',
        'estancado' => esEstancado($t, $cfg) ? '1' : '0',
        'novedad' => $abierto && (int) $t['notificar_tecnico'] === 1 ? '1' : '0',
    ];
};
$conteo = ['todos' => count($en_gestion), 'mios' => 0, 'sin_asignar' => 0, 'sin_respuesta' => 0, 'riesgo' => 0, 'vencidos' => 0, 'pausa' => 0, 'estancados' => 0, 'novedades' => 0];
foreach ($en_gestion as $t) {
    $a = $atributosCaso($t);
    if ((int) $a['tecnico'] === $mi_id) $conteo['mios']++;
    if ($a['tecnico'] === '0') $conteo['sin_asignar']++;
    if ($a['sin-respuesta'] === '1') $conteo['sin_respuesta']++;
    if (in_array($a['nivel'], ['riesgo', 'critico'], true)) $conteo['riesgo']++;
    if ($a['nivel'] === 'vencido') $conteo['vencidos']++;
    if ($a['comp'] === COMP_PAUSA) $conteo['pausa']++;
    if ($a['estancado'] === '1') $conteo['estancados']++;
    if ($a['novedad'] === '1') $conteo['novedades']++;
}

$casos_js = [];
foreach ($listados as $t) {
    $casos_js[(int) $t['id']] = [
        'id' => (int) $t['id'], 'codigo' => $t['codigo_ticket'], 'tipo' => $t['tipo'], 'cliente' => $t['cliente'], 'correo' => $t['correo_cliente'],
        'asunto' => $t['asunto'], 'servicio' => $t['servicio'], 'categoria' => $t['categoria'], 'grupo' => $t['grupo_resolutor'],
        'tecnico_id' => $t['tecnico_id'] ? (int) $t['tecnico_id'] : null, 'tecnico' => $t['nom_tecnico'], 'impacto' => $t['impacto'], 'urgencia' => $t['urgencia'],
        'prioridad' => $t['prioridad'], 'estado' => $t['estado'], 'comportamiento' => $t['comportamiento'], 'descripcion' => $t['descripcion'],
        'adjunto' => $t['adjunto'], 'solucion' => $t['solucion'], 'creado' => $t['created_at'], 'resuelto' => $t['fecha_resolucion'], 'cerrado' => $t['fecha_cierre'],
        'cierre_automatico' => fechaCierreAutomatico($t, $cfg), 'reaperturas' => (int) $t['reaperturas'], 'calificacion' => (int) $t['calificacion_estrellas'],
        'comentario' => $t['comentario_usuario'], 'novedad' => (int) $t['notificar_tecnico'] === 1, 'notas' => notasBitacora($t['respuesta_tecnica']),
        'organizacion' => $t['organizacion_cliente'], 'telefono' => $t['telefono_cliente'], 'codigo_cierre' => $t['codigo_cierre'], 'causa_raiz' => $t['causa_raiz'],
        'clase_color' => $t['clase_color'], 'whatsapp' => numeroWhatsapp($t['telefono_cliente']), 'sla' => $t['sla'],
    ];
}

$fecha = function ($valor, $formato = 'd/m/y H:i') { return $valor ? date($formato, strtotime($valor)) : '—'; };
$badgePrioridad = function ($prioridad) {
    $clases = ['Crítica' => 'bg-danger', 'Alta' => 'bg-orange', 'Media' => 'bg-warning text-dark', 'Baja' => 'bg-success'];
    return '<span class="badge ' . ($clases[$prioridad] ?? 'bg-secondary') . '">' . esc($prioridad) . '</span>';
};
$celdaRespuesta = function (array $t) {
    $corta = function ($f) { return preg_replace('~/\d{4}~', '', diaHoraTexto($f)); };
    if (!$t['sla']) return '<span class="text-muted">—</span>';
    $r = $t['sla']['respuesta'];
    if ($r['finalizado']) {
        return $r['cumplido']
            ? '<span class="text-success fw-bold small" title="Primera respuesta dentro del ANS"><i class="fas fa-check-circle me-1"></i>' . duracionTexto($r['consumido']) . '</span>'
            : '<span class="text-danger fw-bold small" title="Primera respuesta fuera del ANS"><i class="fas fa-times-circle me-1"></i>' . duracionTexto($r['consumido']) . '</span>';
    }
    // Fecha límite explícita: "vencida hace 5 h" confunde porque son horas hábiles
    return $r['restante'] >= 0
        ? '<span class="small fw-bold" style="color:' . COLORES_NIVEL_SLA[$r['nivel']] . '">Vence ' . $corta($r['vence']) . '</span><div class="text-muted" style="font-size:.7rem">Quedan ' . duracionTexto($r['restante']) . ' hábiles</div>'
        : '<span class="small fw-bold text-danger">Vencida</span><div class="text-muted" style="font-size:.7rem" title="' . duracionTexto(-$r['restante']) . ' hábiles de retraso">desde ' . $corta($r['vence']) . '</div>';
};
$celdaSolucion = function (array $t) use ($fecha) {
    if (!$t['sla']) return '<span class="text-muted">—</span>';
    $s = $t['sla']['resolucion'];
    if ($s['finalizado']) {
        return '<span class="small fw-bold">' . ($s['cumplido'] ? '<i class="fas fa-check-circle text-success me-1"></i>Cumplido' : '<i class="fas fa-times-circle text-danger me-1"></i>Incumplido')
             . ' · ' . duracionTexto($s['consumido']) . '</span>';
    }
    $color = COLORES_NIVEL_SLA[$s['nivel']];
    if ($t['sla']['reloj'] === 'detenido') $detalle = '<i class="fas fa-pause-circle text-warning me-1"></i>En pausa';
    elseif ($t['sla']['reloj'] === 'fuera_horario') $detalle = '<i class="fas fa-moon text-secondary me-1"></i>Detenido hasta ' . $fecha($t['sla']['reanuda'], 'd/m H:i');
    else $detalle = 'Vence ' . $fecha($s['vence'], 'd/m H:i');
    return '<div class="ans-mini"><span class="small fw-bold" style="color:' . $color . '">' . number_format($s['pct'], 0) . '% · ' . $s['etiqueta'] . '</span>'
         . '<div class="progress progress-ans my-1"><div class="progress-bar" style="width:' . min(100, $s['pct']) . '%;background:' . $color . '"></div></div>'
         . '<div class="text-muted" style="font-size:.7rem">' . $detalle . '</div></div>';
};
$filaCaso = function (array $t, $columnas) use ($atributosCaso, $badgePrioridad, $celdaRespuesta, $celdaSolucion, $fecha, $cfg) {
    $datos = '';
    foreach ($atributosCaso($t) as $clave => $valor) $datos .= ' data-' . $clave . '="' . esc($valor) . '"';
    $iconos = ((int) $t['notificar_tecnico'] === 1 && !in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true) ? ' <i class="fas fa-bell text-warning" title="Novedad: respuesta del cliente o alerta de ANS"></i>' : '')
            . ((int) $t['reaperturas'] > 0 ? ' <i class="fas fa-redo text-danger" title="Reabierto ' . (int) $t['reaperturas'] . ' vez/veces"></i>' : '');
    $html = '<tr data-caso="' . (int) $t['id'] . '"' . $datos . '>'
          . '<td class="fw-bold ps-4 text-nowrap" style="color:#0d6efd">' . esc($t['codigo_ticket']) . $iconos
          . '<div class="small text-muted fw-normal">' . $fecha($t['created_at']) . '</div>'
          . '<span class="badge ' . esc($t['clase_color'] ?: 'bg-secondary text-white') . ' mt-1" style="font-size:.62rem">' . esc(mb_strtoupper((string) $t['estado'], 'UTF-8')) . '</span></td>';
    foreach ($columnas as $columna) {
        switch ($columna) {
            case 'registro': $html .= '<td class="text-muted small text-nowrap">' . $fecha($t['created_at']) . '</td>'; break;
            case 'solucionado': $html .= '<td class="text-muted small text-nowrap">' . $fecha($t['fecha_resolucion']) . '</td>'; break;
            case 'cierre_auto':
                $cierre = fechaCierreAutomatico($t, $cfg);
                $html .= '<td class="small text-nowrap">' . ($cierre ? '<b>' . diaHoraTexto($cierre) . '</b><div class="text-muted" style="font-size:.7rem">si el cliente no responde</div>' : '—') . '</td>';
                break;
            case 'cierre': $html .= '<td class="text-muted small text-nowrap">' . $fecha($t['fecha_cierre']) . '</td>'; break;
            case 'estado': $html .= '<td><span class="badge ' . esc($t['clase_color'] ?: 'bg-secondary text-white') . ' shadow-sm">' . esc(mb_strtoupper((string) $t['estado'], 'UTF-8')) . '</span></td>'; break;
            case 'cliente': $html .= '<td class="fw-bold text-dark">' . esc($t['cliente']) . '<div class="small text-muted fw-normal text-truncate" style="max-width:220px">' . esc($t['asunto']) . '</div></td>'; break;
            case 'servicio': $html .= '<td class="small">' . esc($t['servicio'] ?: 'N/A') . '<div class="text-muted text-truncate" style="max-width:200px">' . esc($t['grupo_resolutor']) . '</div></td>'; break;
            case 'prioridad': $html .= '<td>' . $badgePrioridad($t['prioridad']) . ' <span class="small text-muted">' . esc($t['tipo']) . '</span><div class="small text-muted text-truncate" style="max-width:170px" title="' . esc($t['servicio']) . '">' . esc($t['grupo_resolutor']) . '</div></td>'; break;
            case 'especialista': $html .= '<td class="small fw-bold ' . ($t['nom_tecnico'] ? 'text-muted' : 'text-danger') . '">' . esc($t['nom_tecnico'] ?: 'Sin asignar') . '</td>'; break;
            case 'respuesta': $html .= '<td>' . $celdaRespuesta($t) . '</td>'; break;
            case 'ans': $html .= '<td style="min-width:150px">' . $celdaSolucion($t) . '</td>'; break;
            case 'calificacion': $html .= '<td class="text-warning text-nowrap">' . ((int) $t['calificacion_estrellas'] > 0 ? str_repeat('★', (int) $t['calificacion_estrellas']) . '<span class="text-muted">' . str_repeat('☆', 5 - (int) $t['calificacion_estrellas']) . '</span>' : '<span class="text-muted small">—</span>') . '</td>'; break;
        }
    }
    return $html . '<td class="text-center pe-4"><button type="button" class="btn btn-sm btn-light border shadow-sm" onclick="abrirCaso(' . (int) $t['id'] . ')" title="Gestionar caso"><i class="fas fa-edit text-dark"></i></button></td></tr>';
};
$vacio = function ($columnas, $icono, $titulo_vacio, $texto) {
    return '<tr><td colspan="' . $columnas . '" class="border-0"><div class="p-5 text-center my-3 mx-auto" style="max-width:450px">'
         . '<div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:72px;height:72px"><i class="fas ' . $icono . ' fa-2x"></i></div>'
         . '<h5 class="fw-bold text-dark mb-2">' . $titulo_vacio . '</h5><p class="text-muted mb-0">' . $texto . '</p></div></td></tr>';
};

$mensajes_error = [
    'no_existe' => 'El caso no existe.', 'cerrado' => 'El caso está cerrado y ya no admite cambios.', 'estado' => 'El estado seleccionado no es válido.',
    'especialista' => 'El especialista seleccionado no está activo o no tiene acceso a Gestión IT.',
    'solucion' => 'Para solucionar o cerrar el caso es obligatorio documentar la solución técnica aplicada.',
    'motivo_pausa' => 'Para pausar el ANS es obligatorio escribir en Notas qué información se espera del cliente.',
    'datos' => 'Revisa los datos del caso: cliente, servicio, impacto, urgencia, asunto y descripción son obligatorios.', 'reasignar' => 'No fue posible reasignar el caso.',
    'codigo_cierre' => 'Para solucionar o cerrar el caso selecciona el código de cierre (cómo se resolvió).',
];
$kpis = [
    ['todos', 'En gestión', 'fa-inbox', '#0A2E4F'], ['mios', 'Mis casos', 'fa-user-check', '#0d6efd'], ['sin_asignar', 'Sin asignar', 'fa-user-clock', '#7c3aed'],
    ['sin_respuesta', 'Sin 1ª respuesta', 'fa-reply', '#0891b2'], ['riesgo', 'En riesgo', 'fa-exclamation-triangle', COLORES_NIVEL_SLA['riesgo']],
    ['vencidos', 'Vencidos', 'fa-fire', COLORES_NIVEL_SLA['vencido']], ['pausa', 'En pausa', 'fa-pause-circle', '#ca8a04'], ['novedades', 'Novedades', 'fa-bell', '#f59e0b'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión IT · Mesa de Servicios | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .asm-header-bar { background-color: #343a40; color: white; padding: 10px 20px; border-bottom: 3px solid #f26522; }
        .label-ans { font-size: 0.65rem; font-weight: 700; color: #6c757d; text-transform: uppercase; margin-bottom: 3px; display: block; }
        .box-section { border: 1px solid #dee2e6; border-radius: 4px; padding: 12px; background-color: #fff; height: 100%; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .section-title { font-size: 0.75rem; font-weight: 800; color: #495057; border-bottom: 1px solid #dee2e6; padding-bottom: 6px; margin-bottom: 12px; text-transform: uppercase; }
        .form-control-sm, .form-select-sm { font-size: 0.8rem; border-color: #ced4da; padding: 4px 8px; margin-bottom: 10px; }
        textarea.desc-box { background-color: #f8f9fa; border: 1px solid #ced4da; font-size: 0.85rem; color: #212529; resize: none; cursor: default; }
        .nav-tabs-aranda .nav-link { font-size: 0.85rem; font-weight: 600; color: #64748b; border-radius: 0; padding: 10px 18px; cursor: pointer; transition: all 0.2s ease-in-out; }
        .nav-tabs-aranda .nav-link:hover { background-color: #f8fafc; color: #f26522; }
        .nav-tabs-aranda .nav-link.active { color: #f26522; border-bottom: 3px solid #f26522; background: transparent; font-weight: 700; }
        .table-itsm th { background-color: #f8f9fa; font-size: 0.72rem; color: #495057; border-bottom: 2px solid #dee2e6; text-transform: uppercase; white-space: nowrap; }
        .table-itsm td { vertical-align: middle; font-size: 0.85rem; }
        .progress-ans { height: 6px; border-radius: 3px; background-color: #e9ecef; }
        .bg-orange { background-color: #ea580c !important; color: white !important; }
        .kpi-mesa { width: 100%; border: 1px solid #e2e8f0; background: #fff; border-radius: 10px; padding: 10px 12px; text-align: left; display: flex; align-items: center; gap: 10px; transition: .15s; }
        .kpi-mesa:hover { box-shadow: 0 4px 12px rgba(15,23,42,.08); }
        .kpi-mesa.activo { border-color: #f26522; box-shadow: 0 0 0 2px rgba(242,101,34,.25); }
        .kpi-mesa .icono { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .kpi-mesa .valor { font-size: 1.25rem; font-weight: 800; line-height: 1; color: #0f172a; }
        .kpi-mesa .etiqueta { font-size: .66rem; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .medidor + .medidor { border-top: 1px dashed #dee2e6; padding-top: 8px; margin-top: 8px; }
        .nota { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; background: #fff; font-size: .85rem; }
        .nota-interna { background: #fff7ed; border-color: #fed7aa; }
        .nota-cliente { background: #eff6ff; border-color: #bfdbfe; }
        .linea-tiempo { list-style: none; padding-left: 0; margin: 0; }
        .linea-tiempo li { position: relative; padding: 0 0 12px 20px; border-left: 2px solid #e2e8f0; margin-left: 6px; font-size: .82rem; }
        .linea-tiempo li::before { content: ''; position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 50%; background: #0A2E4F; border: 2px solid #fff; box-shadow: 0 0 0 1px #cbd5e1; }
        .linea-tiempo li.privado::before { background: #f59e0b; }
        .linea-tiempo li.sistema::before { background: #dc2626; }
        #simulacion_ans { font-size: .75rem; }
        .ficha-caso { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 8px; }
        .ficha-caso .dato { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; padding: 6px 10px; min-width: 0; }
        .ficha-caso .dato b { display: block; font-size: .85rem; color: #0f172a; }
        .ficha-caso .dato small { display: block; color: #6c757d; font-size: .7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .analisis-ans { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
        .analisis-ans li { margin-bottom: 4px; }
        .estado-tiempo { margin-bottom: 8px; }
        .linea-tiempo li.evento-asignacion::before { background: #7c3aed; }
        .linea-tiempo li.evento-solucion::before, .linea-tiempo li.evento-cierre::before { background: #16a34a; }
        .linea-tiempo li.evento-pausa::before { background: #ca8a04; }
        .linea-tiempo li.evento-cliente::before { background: #0891b2; }
        .linea-tiempo li.evento-alerta::before, .linea-tiempo li.evento-reapertura::before { background: #dc2626; }
        .linea-tiempo li.hito::before { background: #fff; border-color: #64748b; box-shadow: 0 0 0 2px #64748b; }
        .linea-tiempo li.hito-ok::before { box-shadow: 0 0 0 2px #16a34a; }
        .linea-tiempo li.hito-mal::before { background: #dc2626; box-shadow: 0 0 0 2px #dc2626; }
        .linea-tiempo li.hito-pendiente::before { box-shadow: 0 0 0 2px #ca8a04; }
        .linea-tiempo li.hito { background: #f8fafc; border-radius: 0 6px 6px 0; }
        .linea-tiempo li.futuro { opacity: .75; }
        /* Vista del caso: encabezado, acuerdo de servicio y paneles laterales (estilo consola ITSM) */
        .min-w-0 { min-width: 0; }
        @media (min-width: 992px) { .caso-dialogo { max-width: min(1400px, calc(100vw - 48px)); } #modalASM .modal-content { border-radius: 14px; overflow: hidden; } }
        #modalASM .modal-content { background: #f4f6f9; }
        .caso-header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 22px; align-items: flex-start; gap: 12px; }
        .caso-encabezado { min-width: 0; flex: 1 1 auto; }
        .caso-migas { font-size: .7rem; color: #64748b; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
        .caso-codigo { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; background: #0A2E4F; color: #fff; border-radius: 6px; padding: 2px 9px; font-size: .9rem; font-weight: 700; white-space: nowrap; }
        .caso-asunto { font-size: 1.1rem; font-weight: 700; color: #0f172a; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .caso-acciones { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .caso-header .btn-close { margin: 0; }
        .btn-guardar { background: #0A2E4F; color: #fff; font-weight: 600; padding: 7px 16px; border-radius: 8px; }
        .btn-guardar:hover, .btn-guardar:focus { background: #0d3d68; color: #fff; }
        .chip { display: inline-flex; align-items: center; gap: 5px; font-size: .72rem; font-weight: 600; border-radius: 999px; padding: 3px 10px; border: 1px solid #e2e8f0; background: #f8fafc; color: #334155; white-space: nowrap; }
        .chip.chip-color { color: #fff; border-color: transparent; }
        .caso-cuerpo { padding: 18px 22px; }
        .panel-caso { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        .panel-titulo { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #475569; padding: 10px 14px; border-bottom: 1px solid #eef2f6; display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
        .panel-cuerpo { padding: 12px 14px; }
        .dato-fila { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-top: 1px dashed #e9eef4; font-size: .8rem; }
        .dato-fila > span:first-child { color: #64748b; flex-shrink: 0; }
        .dato-fila .valor { text-align: right; min-width: 0; font-weight: 600; color: #0f172a; overflow-wrap: anywhere; }
        .dato-fila .valor small { display: block; font-weight: 400; color: #64748b; font-size: .7rem; }
        .campo-prioridad { border: 0; background: transparent; text-align: right; font-weight: 800; width: 50%; padding: 0; }
        .campo-prioridad:focus { outline: none; }
        .reloj-banner { display: flex; gap: 10px; align-items: flex-start; border-radius: 8px; padding: 9px 12px; font-size: .8rem; line-height: 1.4; border: 1px solid; }
        .reloj-banner > i { margin-top: 3px; }
        .reloj-banner.corriendo { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .reloj-banner.fuera { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        .reloj-banner.pausa { background: #fefce8; color: #854d0e; border-color: #fde68a; }
        .reloj-banner.fin { background: #f8fafc; color: #334155; border-color: #e2e8f0; }
        .pulso { width: 9px; height: 9px; border-radius: 50%; background: #10b981; margin-top: 5px; flex-shrink: 0; animation: pulso 1.8s infinite; }
        @keyframes pulso { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.55); } 70% { box-shadow: 0 0 0 8px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
        @media (prefers-reduced-motion: reduce) { .pulso { animation: none; } }
        .medidor-ans { border: 1px solid #eef2f6; border-radius: 8px; padding: 10px 12px; height: 100%; background: #fcfdfe; }
        .medidor-cabeza { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .medidor-titulo { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #475569; cursor: help; }
        .medidor-ans .frase { font-size: 1.02rem; font-weight: 700; color: #0f172a; line-height: 1.3; margin: 6px 0 8px; }
        .medidor-ans .progress-ans { height: 8px; border-radius: 4px; }
        .medidor-pie { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 2px 12px; font-size: .72rem; color: #64748b; margin-top: 6px; }
        .pill-ans { display: inline-block; font-size: .68rem; font-weight: 700; border-radius: 999px; padding: 3px 10px; color: #fff; white-space: nowrap; letter-spacing: .02em; }
        .siguiente-paso { display: flex; align-items: flex-start; font-size: .8rem; background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 12px; }
        .siguiente-paso.urgente { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .siguiente-paso > i { margin-top: 3px; }
        .caso-tabs { border-bottom: 1px solid #e2e8f0; flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; }
        .caso-tabs .nav-link { white-space: nowrap; padding: 11px 14px; border: 0; border-bottom: 3px solid transparent; }
        .caso-tab-contenido { padding: 18px; min-height: 340px; }
        .meta-ans { font-size: .72rem; color: #475569; margin-top: 2px; }
        .btn-whatsapp { background: #25d366; color: #fff; border: 0; }
        .btn-whatsapp:hover, .btn-whatsapp:focus { background: #1ebe5b; color: #fff; }
        .btn-whatsapp.disabled { background: #cbd5e1; color: #475569; }
        .correo-item { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; margin-bottom: 8px; background: #fff; }
        .correo-item summary { cursor: pointer; list-style: none; }
        .correo-item summary::-webkit-details-marker { display: none; }
        .correo-contenido { white-space: pre-line; font-size: .8rem; color: #334155; border-top: 1px dashed #e2e8f0; margin-top: 8px; padding-top: 8px; }
        .linea-tiempo li.evento-correo::before { background: #0d6efd; }
        .linea-tiempo li.evento-contacto::before { background: #25d366; }
        #modalASM .form-select-sm { padding-right: 2rem; text-overflow: ellipsis; }
        @media (max-width: 1199.98px) { .caso-tabs .nav-link { padding: 11px 9px; font-size: .8rem; } }
        @media (max-width: 991.98px) { .caso-header { padding: 12px 14px; } .caso-cuerpo { padding: 12px; } .caso-asunto { white-space: normal; font-size: 1rem; } }
        @media (max-width: 575.98px) { .caso-header { flex-wrap: wrap; } .caso-acciones { width: 100%; justify-content: space-between; } .caso-tab-contenido { padding: 12px; } }
    </style>
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="toolbar-pro px-4 mb-3 mt-2 flex-wrap gap-2">
            <div class="search-container-pro shadow-sm flex-grow-1">
                <i class="fas fa-search"></i>
                <input type="text" id="buscadorTickets" placeholder="Buscar por No. de caso, cliente, asunto, especialista o servicio...">
            </div>
            <select id="filtro_prioridad" class="form-select shadow-sm" style="max-width:160px" aria-label="Prioridad">
                <option value="">Toda prioridad</option>
                <?php foreach (PRIORIDADES_TICKET as $prioridad): ?><option value="<?= esc($prioridad) ?>"><?= esc($prioridad) ?></option><?php endforeach; ?>
            </select>
            <select id="filtro_grupo" class="form-select shadow-sm" style="max-width:220px" aria-label="Grupo resolutor">
                <option value="">Todos los grupos</option>
                <?php foreach ($grupos as $grupo): ?><option value="<?= esc($grupo) ?>"><?= esc($grupo) ?></option><?php endforeach; ?>
            </select>
            <button class="btn-nuevo-cliente shadow" data-bs-toggle="modal" data-bs-target="#modalRegistrar" style="background:#0A2E4F;border:none;">
                <i class="fas fa-plus-circle me-2"></i> REGISTRAR CASO
            </button>
            <button class="btn-nuevo-cliente shadow" onclick="exportarExcel()" style="background:#198754;border:none;">
                <i class="fas fa-file-excel me-2"></i> EXPORTAR LISTADO
            </button>
        </div>

        <div class="px-4">
            <?php if (($_GET['res'] ?? '') === 'ticket_creado'): ?>
                <div class="alert alert-success shadow-sm py-2 mb-3"><i class="fas fa-check-circle me-2"></i> Caso <strong><?= esc($_GET['codigo'] ?? '') ?></strong> registrado; se envió la confirmación al cliente.</div>
            <?php elseif (($_GET['res'] ?? '') === 'reasignado'): ?>
                <div class="alert alert-success shadow-sm py-2 mb-3"><i class="fas fa-check-circle me-2"></i> Caso <strong><?= esc($_GET['caso'] ?? '') ?></strong> reasignado.</div>
            <?php elseif (isset($_GET['res'])): ?>
                <div class="alert alert-success shadow-sm py-2 mb-3"><i class="fas fa-check-circle me-2"></i> Gestión del caso <strong><?= esc($_GET['caso'] ?? '') ?></strong> almacenada correctamente.</div>
            <?php endif; ?>
            <?php if (($_GET['adjunto'] ?? '') === 'rechazado'): ?>
                <div class="alert alert-warning shadow-sm py-2 mb-3"><i class="fas fa-paperclip me-2"></i> El archivo no fue aceptado porque su formato no está permitido. El resto de la gestión sí se guardó.</div>
            <?php endif; ?>
            <?php if (isset($_GET['err'])): ?>
                <div class="alert alert-danger shadow-sm py-2 mb-3"><i class="fas fa-exclamation-circle me-2"></i> <?= esc($mensajes_error[$_GET['err']] ?? 'No fue posible guardar la gestión.') ?></div>
            <?php endif; ?>

            <div class="row g-2 mb-3" id="kpisMesa">
                <?php foreach ($kpis as $k): ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <button type="button" class="kpi-mesa <?= $k[0] === 'todos' ? 'activo' : '' ?>" data-filtro="<?= $k[0] ?>">
                        <span class="icono" style="background:<?= $k[3] ?>1a;color:<?= $k[3] ?>"><i class="fas <?= $k[2] ?>"></i></span>
                        <span><span class="valor d-block" id="kpi_<?= $k[0] ?>"><?= (int) $conteo[$k[0]] ?></span><span class="etiqueta"><?= $k[1] ?></span></span>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <ul class="nav nav-tabs nav-tabs-aranda bg-white px-3 pt-2 shadow-sm rounded-top" id="tabsITSM">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#pendientes" id="btn_tab_abiertos"><i class="fas fa-inbox me-1"></i> Casos en gestión <span class="badge bg-danger ms-1"><?= count($en_gestion) ?></span></a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#solucionados" id="btn_tab_solucionados"><i class="fas fa-user-check me-1"></i> Solucionados sin confirmar <span class="badge bg-success ms-1"><?= count($solucionados) ?></span></a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#cerrados" id="btn_tab_cerrados"><i class="fas fa-archive me-1"></i> Cerrados</a></li>
            </ul>

            <div class="tab-content mb-5">
                <div class="tab-pane fade show active" id="pendientes">
                    <div class="card border-0 shadow-sm rounded-0 rounded-bottom table-responsive">
                        <table class="table table-hover table-itsm mb-0 bg-white tabla-busqueda" id="tabla_pendientes">
                            <thead><tr><th class="ps-4">Caso / Estado</th><th>Cliente / Asunto</th><th>Prioridad / Grupo</th><th>Especialista</th><th>1ª respuesta</th><th>ANS solución</th><th class="text-center pe-4">Ver</th></tr></thead>
                            <tbody>
                                <?php foreach ($en_gestion as $t) echo $filaCaso($t, ['cliente', 'prioridad', 'especialista', 'respuesta', 'ans']); ?>
                                <?php if (!$en_gestion) echo $vacio(7, 'fa-check-double', '¡Bandeja al día!', 'No hay incidentes ni requerimientos pendientes por atender.'); ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="solucionados">
                    <div class="card border-0 shadow-sm rounded-0 rounded-bottom table-responsive">
                        <div class="px-4 pt-3" id="explicacion_por_confirmar">
                            <div class="alert alert-light border small mb-2">
                                <b><i class="fas fa-info-circle text-primary me-1"></i>¿Qué son estos casos?</b> El especialista ya registró la solución, pero el caso no se cierra hasta que el cliente la revise:
                                <ol class="mb-0 mt-1 ps-3">
                                    <li>Al solucionar, el cliente recibe un correo con la solución aplicada.</li>
                                    <li>Desde su Centro de Soporte la <b>confirma</b> (y califica la atención) o la <b>reabre</b> si no quedó bien. Si la reabre, el caso vuelve a "Casos en gestión".</li>
                                    <li>Si no responde, el caso <b>se cierra solo</b> <?= (int) $cfg['cierre_automatico_dias'] ?> días hábiles después de la solución (columna "Se cierra solo").</li>
                                </ol>
                            </div>
                        </div>
                        <table class="table table-hover table-itsm mb-0 bg-white tabla-busqueda" id="tabla_solucionados">
                            <thead><tr><th class="ps-4">Caso / Estado</th><th>Solucionado</th><th>Se cierra solo</th><th>Cliente / Asunto</th><th>Prioridad / Grupo</th><th>Especialista</th><th>ANS solución</th><th class="text-center pe-4">Ver</th></tr></thead>
                            <tbody>
                                <?php foreach ($solucionados as $t) echo $filaCaso($t, ['solucionado', 'cierre_auto', 'cliente', 'prioridad', 'especialista', 'ans']); ?>
                                <?php if (!$solucionados) echo $vacio(8, 'fa-user-check', 'Sin casos solucionados por confirmar', 'Los casos que soluciones aparecerán aquí hasta que el cliente los confirme o se cierren solos.'); ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="cerrados">
                    <div class="card border-0 shadow-sm rounded-0 rounded-bottom table-responsive">
                        <table class="table table-hover table-itsm mb-0 bg-white tabla-busqueda" id="tabla_historico">
                            <thead><tr><th class="ps-4">Caso / Estado final</th><th>Cierre</th><th>Cliente / Asunto</th><th>Prioridad / Grupo</th><th>Especialista</th><th>ANS solución</th><th>Calificación</th><th class="text-center pe-4">Ver</th></tr></thead>
                            <tbody>
                                <?php foreach ($cerrados as $t) echo $filaCaso($t, ['cierre', 'cliente', 'prioridad', 'especialista', 'ans', 'calificacion']); ?>
                                <?php if (!$cerrados) echo $vacio(8, 'fa-archive', 'Sin histórico', 'Aún no hay casos cerrados en los últimos 120 días.'); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalASM" tabindex="-1" data-bs-backdrop="static" aria-labelledby="mod_codigo">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down caso-dialogo">
        <form class="modal-content border-0 shadow-lg" method="POST" action="procesar_soporte.php" enctype="multipart/form-data" id="formAtencion" onsubmit="return validarCierreObligatorio(event)">
            <input type="hidden" name="accion" value="actualizar_bitacora">
            <input type="hidden" name="ticket_id" id="mod_id_input">
            <input type="hidden" name="prioridad" id="input_prioridad_hidden">

            <div class="modal-header caso-header">
                <div class="caso-encabezado">
                    <div class="caso-migas"><i class="fas fa-headset me-1"></i>Mesa de servicios <i class="fas fa-chevron-right mx-1"></i><span id="mod_tipo_reg">Caso</span></div>
                    <div class="d-flex align-items-center gap-2 mt-1 min-w-0">
                        <span id="mod_codigo" class="caso-codigo">INC-000</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-secondary" id="btnCopiarCodigo" title="Copiar el número del caso" aria-label="Copiar el número del caso"><i class="far fa-copy"></i></button>
                        <h5 class="caso-asunto mb-0" id="mod_asunto">—</h5>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-2" id="mod_insignias"></div>
                </div>
                <div class="caso-acciones">
                    <button type="submit" id="btnGuardarGestion" class="btn btn-guardar btn-sm"><i class="fas fa-save me-2"></i>Guardar gestión</button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
            </div>

            <div class="modal-body caso-cuerpo">
                <div id="aviso_caso" class="alert py-2 mb-3" hidden></div>

                <section class="panel-caso mb-3" id="caja_reloj">
                    <div class="panel-titulo">
                        <span><i class="fas fa-stopwatch me-1"></i> Acuerdo de nivel de servicio (ANS)</span>
                        <span id="ans_semaforo" class="pill-ans bg-secondary">—</span>
                    </div>
                    <div class="panel-cuerpo">
                        <div id="ans_reloj_estado" class="reloj-banner mb-3"></div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="medidor-ans">
                                    <div class="medidor-cabeza">
                                        <span class="medidor-titulo" title="Tiempo máximo para que un especialista tome el caso y le escriba al cliente o cambie su estado. Solo cuentan horas hábiles."><i class="fas fa-reply me-1"></i>Primera respuesta <i class="far fa-question-circle ms-1"></i></span>
                                        <span id="ans_resp_estado">—</span>
                                    </div>
                                    <div class="meta-ans" id="ans_resp_meta"></div>
                                    <div class="frase" id="ans_resp_frase">—</div>
                                    <div class="progress progress-ans"><div class="progress-bar" id="ans_resp_barra" style="width:0%"></div></div>
                                    <div class="medidor-pie"><span id="ans_resp_detalle"></span><span id="ans_resp_vence"></span></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="medidor-ans">
                                    <div class="medidor-cabeza">
                                        <span class="medidor-titulo" id="lbl_reloj_titulo" title="Tiempo máximo para resolver el caso o entregar lo solicitado. Solo cuentan horas hábiles y el tiempo en pausa no cuenta."><i class="fas fa-check-double me-1"></i>Solución <i class="far fa-question-circle ms-1"></i></span>
                                        <span id="ans_pct" class="pill-ans bg-secondary">0 %</span>
                                    </div>
                                    <div class="meta-ans" id="ans_sol_meta"></div>
                                    <div class="frase" id="ans_reloj">—</div>
                                    <div class="progress progress-ans"><div class="progress-bar" id="ans_barra" style="width:0%"></div></div>
                                    <div class="medidor-pie"><span id="ans_info"></span><span id="ans_vence"></span></div>
                                </div>
                            </div>
                        </div>
                        <div id="ans_siguiente" class="siguiente-paso mt-3" hidden></div>
                    </div>
                </section>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="panel-caso">
                            <ul class="nav nav-tabs nav-tabs-aranda caso-tabs px-2">
                                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_descripcion"><i class="fas fa-align-left me-1"></i>Descripción</a></li>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_notas"><i class="fas fa-comments me-1"></i>Notas <span class="badge rounded-pill bg-secondary" id="contador_notas">0</span></a></li>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_correos"><i class="fas fa-envelope me-1"></i>Correos <span class="badge rounded-pill bg-secondary" id="contador_correos">0</span></a></li>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_solucion"><i class="fas fa-check-double me-1"></i>Solución</a></li>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_adjuntos"><i class="fas fa-paperclip me-1"></i>Evidencias</a></li>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_historial"><i class="fas fa-history me-1"></i>Historial y tiempos</a></li>
                                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_cliente"><i class="fas fa-user me-1"></i>Cliente</a></li>
                            </ul>

                            <div class="tab-content caso-tab-contenido">
                                <div class="tab-pane fade show active" id="tab_descripcion">
                                    <label class="label-ans mb-2" for="mod_descripcion">Descripción del cliente</label>
                                    <textarea class="form-control desc-box w-100 p-3" id="mod_descripcion" rows="10" readonly></textarea>
                                </div>
                                <div class="tab-pane fade" id="tab_notas">
                                    <div id="historial_chat" class="mb-3 p-3 bg-light border rounded" style="min-height:110px; max-height:320px; overflow-y:auto;"></div>
                                    <label class="label-ans text-primary" for="nueva_nota">Añadir comentario o nota</label>
                                    <textarea name="nueva_nota" id="nueva_nota" class="form-control form-control-sm border-primary mb-2" rows="3" placeholder="Avance, información que necesitas del cliente o nota interna del equipo..."></textarea>
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notificar" id="chk_notificar" checked><label class="form-check-label small fw-bold" for="chk_notificar">Visible para el cliente (se le notifica por correo)</label></div>
                                </div>
                                <div class="tab-pane fade" id="tab_correos">
                                    <div class="analisis-ans mb-3">
                                        <div class="section-title mb-2"><i class="fas fa-paper-plane me-1"></i> Escribir al cliente</div>
                                        <div class="small text-muted mb-2">Para: <b id="correo_para">—</b>. Sale desde la plataforma con la imagen de DataSense y queda guardado, con su contenido, en el historial del caso. Si el caso aún no tenía primera respuesta, este correo la registra.</div>
                                        <label class="label-ans" for="correo_asunto">Asunto</label>
                                        <input type="text" id="correo_asunto" class="form-control form-control-sm" maxlength="200" data-correo>
                                        <label class="label-ans" for="correo_mensaje">Mensaje</label>
                                        <textarea id="correo_mensaje" class="form-control form-control-sm mb-2" rows="5" maxlength="5000" data-correo placeholder="Escribe el mensaje para el cliente..."></textarea>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <button type="button" class="btn btn-guardar btn-sm" id="btnEnviarCorreo"><i class="fas fa-paper-plane me-2"></i>Enviar correo</button>
                                            <span id="correo_estado" class="small"></span>
                                        </div>
                                    </div>
                                    <div class="section-title mb-2"><i class="fas fa-inbox me-1"></i> Correos que recibió el cliente en este caso</div>
                                    <div id="lista_correos"><span class="small text-muted">Cargando...</span></div>
                                </div>
                                <div class="tab-pane fade" id="tab_solucion">
                                    <div class="alert alert-success border-success bg-success bg-opacity-10 py-2 px-3 mb-3 d-flex align-items-center">
                                        <i class="fas fa-info-circle me-3 fa-lg"></i>
                                        <div><strong class="d-block">Documentación para cierre (requerida)</strong><small>Obligatoria para solucionar o cerrar el caso. Se envía al cliente y alimenta la base de conocimiento.</small></div>
                                    </div>
                                    <label class="label-ans text-success" for="input_solucion">Solución técnica aplicada *</label>
                                    <textarea name="texto_solucion" id="input_solucion" class="form-control border-success mt-1" rows="5"></textarea>
                                    <div class="row g-2 mt-1">
                                        <div class="col-md-5">
                                            <label class="label-ans text-success" for="mod_codigo_cierre">Código de cierre *</label>
                                            <select name="codigo_cierre" id="mod_codigo_cierre" class="form-select form-select-sm border-success">
                                                <option value="">¿Cómo se resolvió?</option>
                                                <?php foreach (CODIGOS_CIERRE as $codigo): ?><option value="<?= esc($codigo) ?>"><?= esc($codigo) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="label-ans" for="mod_causa_raiz">Causa raíz (opcional, interna)</label>
                                            <input type="text" name="causa_raiz" id="mod_causa_raiz" maxlength="500" class="form-control form-control-sm" placeholder="Qué originó la falla o la solicitud">
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="tab_adjuntos">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="label-ans"><i class="fas fa-file-image me-1"></i> Evidencia del cliente</label>
                                            <div id="enlace_adjunto_cliente" class="mt-2"></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="label-ans text-primary" for="adjunto_tecnico"><i class="fas fa-upload me-1"></i> Cargar archivo técnico</label>
                                            <input type="file" name="adjunto_tecnico" id="adjunto_tecnico" class="form-control form-control-sm mt-2">
                                            <small class="text-muted">Queda como nota (visible o interna según el interruptor de Notas).</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="tab_historial">
                                    <div id="flujo_cargando" class="text-muted small"><i class="fas fa-circle-notch fa-spin me-1"></i> Cargando el flujo del caso...</div>
                                    <div id="flujo_contenido" hidden>
                                        <div class="analisis-ans mb-3">
                                            <div class="section-title mb-2"><i class="fas fa-search me-1"></i> Análisis del ANS</div>
                                            <ul id="flujo_explicacion" class="small mb-0 ps-3"></ul>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-7">
                                                <div class="section-title mb-2"><i class="fas fa-stream me-1"></i> Flujo del caso paso a paso</div>
                                                <ul class="linea-tiempo" id="lista_historial"></ul>
                                            </div>
                                            <div class="col-md-5">
                                                <div class="section-title mb-2"><i class="fas fa-hourglass-half me-1"></i> Tiempo en cada estado</div>
                                                <div id="flujo_estados"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="tab_cliente">
                                    <div class="row g-3">
                                        <div class="col-md-4"><div class="analisis-ans" id="cliente_datos"><span class="small text-muted">Cargando...</span></div></div>
                                        <div class="col-md-8">
                                            <div class="section-title mb-2">Casos anteriores del cliente</div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle mb-0"><thead><tr><th>Caso</th><th>Asunto</th><th>Estado</th><th>Prioridad</th><th>Registro</th></tr></thead><tbody id="casos_cliente"></tbody></table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <aside class="col-lg-4" id="ficha_caso">
                        <section class="panel-caso mb-3">
                            <div class="panel-titulo"><span><i class="fas fa-sliders-h me-1"></i> Estado y prioridad</span></div>
                            <div class="panel-cuerpo">
                                <label class="label-ans" for="mod_estado">Estado del caso *</label>
                                <select name="estado" id="mod_estado" class="form-select form-select-sm fw-bold" onchange="verificarCierre()">
                                    <?php foreach ($estados as $estado): ?>
                                        <option value="<?= esc($estado['nombre']) ?>" data-comportamiento="<?= esc($estado['comportamiento']) ?>"><?= esc($estado['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="ayuda_estado" class="small text-muted mb-2" hidden></div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="label-ans" for="mod_impacto">Impacto</label>
                                        <select name="impacto" id="mod_impacto" class="form-select form-select-sm" onchange="calcularPrioridad()">
                                            <?php foreach ($impactos as $i): ?><option value="<?= esc($i['valor_tecnico']) ?>"><?= esc($i['valor_tecnico']) ?> · <?= esc($i['nombre_cliente']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="label-ans" for="mod_urgencia">Urgencia</label>
                                        <select name="urgencia" id="mod_urgencia" class="form-select form-select-sm" onchange="calcularPrioridad()">
                                            <?php foreach ($urgencias as $u): ?><option value="<?= esc($u['valor_tecnico']) ?>"><?= esc($u['valor_tecnico']) ?> · <?= esc($u['nombre_cliente']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="dato-fila align-items-center"><span>Prioridad resultante</span><input type="text" id="mod_prioridad_display" class="campo-prioridad" readonly aria-label="Prioridad resultante"></div>
                                <div id="simulacion_ans" class="alert alert-info py-1 px-2 mb-0 mt-2" hidden></div>
                            </div>
                        </section>

                        <section class="panel-caso mb-3">
                            <div class="panel-titulo"><span><i class="fas fa-user-cog me-1"></i> Asignación</span></div>
                            <div class="panel-cuerpo">
                                <label class="label-ans" for="mod_grupo">Grupo resolutor</label>
                                <select name="grupo_resolutor" id="mod_grupo" class="form-select form-select-sm">
                                    <?php foreach ($grupos as $grupo): ?><option value="<?= esc($grupo) ?>"><?= esc($grupo) ?></option><?php endforeach; ?>
                                </select>
                                <label class="label-ans" for="mod_tecnico">Especialista asignado</label>
                                <select name="tecnico_id" id="mod_tecnico" class="form-select form-select-sm fw-bold">
                                    <option value=""><?= $cfg['asignacion_automatica'] === '1' ? '-- Asignación automática --' : '-- Sin Asignar --' ?></option>
                                    <?php foreach ($tecnicos as $tec): ?><option value="<?= (int) $tec['id'] ?>"><?= esc($tec['nombre']) ?> (<?= esc($tec['rol']) ?>)</option><?php endforeach; ?>
                                </select>
                                <div class="dato-fila"><span>Atiende hoy</span><span class="valor"><span id="ficha_especialista">—</span><small id="ficha_asignaciones"></small></span></div>
                            </div>
                        </section>

                        <section class="panel-caso mb-3">
                            <div class="panel-titulo"><span><i class="fas fa-id-card me-1"></i> Solicitante</span></div>
                            <div class="panel-cuerpo">
                                <div class="fw-bold text-dark" id="mod_cliente">—</div>
                                <div class="small text-muted text-break" id="mod_cliente_contacto"></div>
                                <div class="d-flex flex-wrap gap-2 mt-2" id="acciones_contacto">
                                    <a class="btn btn-sm btn-whatsapp" id="btn_whatsapp" target="_blank" rel="noopener"><i class="fab fa-whatsapp me-1"></i>WhatsApp</a>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn_redactar_correo"><i class="fas fa-envelope me-1"></i>Enviar correo</button>
                                    <a class="btn btn-sm btn-outline-secondary" id="btn_llamar"><i class="fas fa-phone me-1"></i>Llamar</a>
                                </div>
                                <div class="text-muted mt-1" style="font-size:.7rem"><i class="fas fa-shield-alt me-1"></i>Cada contacto queda registrado en el historial del caso.</div>
                                <div class="dato-fila mt-2"><span>Servicio</span><span class="valor" id="mod_servicio">—</span></div>
                            </div>
                        </section>

                        <section class="panel-caso">
                            <div class="panel-titulo"><span><i class="far fa-calendar-alt me-1"></i> Fechas clave</span></div>
                            <div class="panel-cuerpo">
                                <div class="dato-fila"><span>Registrado</span><span class="valor"><span id="ficha_registro">—</span><small id="ficha_registro_por"></small></span></div>
                                <div class="dato-fila"><span>Última gestión</span><span class="valor"><span id="ficha_ultima">—</span><small id="ficha_ultima_por"></small></span></div>
                                <div class="dato-fila"><span>Límite de solución</span><span class="valor"><span id="ficha_vence">—</span><small id="ficha_vence_detalle"></small></span></div>
                                <div class="dato-fila"><span>Pausas y reaperturas</span><span class="valor"><span id="ficha_pausa">—</span><small id="ficha_reaperturas"></small></span></div>
                            </div>
                        </section>
                    </aside>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalLlamada" tabindex="-1" aria-labelledby="llamada_titulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h6 class="fw-bold mb-0 text-white" id="llamada_titulo"><i class="fas fa-phone me-2"></i>Llamar al cliente</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="small text-muted" id="llamada_cliente"></div>
                <div class="fw-bold text-dark my-2" style="font-size: 1.6rem; letter-spacing: 1px;" id="llamada_numero">—</div>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" id="llamada_marcar" href="#"><i class="fas fa-phone me-1"></i>Marcar desde el computador</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btn_copiar_numero"><i class="far fa-copy me-1"></i>Copiar número</button>
                </div>
                <p class="small text-muted mt-3 mb-2">El timbre suena en tu celular vinculado y hablas con el micrófono del computador.
                    La llamada queda registrada en el historial del caso al pulsar este botón.</p>
                <details class="text-start small">
                    <summary class="fw-semibold text-primary" style="cursor: pointer;" id="llamada_ayuda">¿Por qué pregunta con qué aplicación llamar?</summary>
                    <p class="text-muted mt-2 mb-1">El navegador no marca por sí mismo: le entrega el número a una aplicación del computador. Para que marque
                        con tu propio celular, una sola vez:</p>
                    <ol class="text-muted ps-3 mb-1">
                        <li>En el celular Android instala <b>Vincular a Windows</b> (en Samsung ya viene: Ajustes › Dispositivos conectados › Vínculo con Windows).</li>
                        <li>En el computador abre <b>Vínculo al teléfono</b>, vincula con el código QR y acepta el permiso de <b>llamadas</b> (necesita el Bluetooth encendido).</li>
                        <li>La próxima vez que el computador pregunte, escoge <b>Vínculo al teléfono</b> y marca <b>«Usar siempre esta aplicación»</b>.</li>
                    </ol>
                    <p class="text-muted mb-0">También sirve Teams, Skype o el softphone del operador: el paso 3 es el mismo. Mientras tanto, copia el número y
                        marca en el celular; con «Ya llamé: registrar» la llamada queda en el historial del caso.</p>
                </details>
                <div class="small text-success mt-2" id="llamada_estado" role="status"></div>
            </div>
            <div class="modal-footer border-0 pt-0 pb-4 justify-content-center">
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" id="btn_registrar_llamada"><i class="fas fa-check me-1"></i>Ya llamé: registrar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRegistrar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content border-0 shadow-lg" method="POST" action="procesar_soporte.php" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="crear">
            <div class="modal-header caso-header align-items-center">
                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-phone-volume me-2 text-secondary"></i> Registrar caso en nombre de un cliente</h5>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="label-ans" for="reg_cliente">Cliente *</label>
                        <select name="cliente_id" id="reg_cliente" class="form-select" required>
                            <option value="">Selecciona el cliente...</option>
                            <?php foreach ($clientes as $cli): ?><option value="<?= (int) $cli['id'] ?>"><?= esc($cli['nombre']) ?> · <?= esc($cli['correo']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="label-ans" for="reg_servicio">Servicio del catálogo *</label>
                        <select name="id_servicio_catalogo" id="reg_servicio" class="form-select" required onchange="previsualizarRegistro()">
                            <option value="">Selecciona...</option>
                            <?php foreach (TIPOS_TICKET as $tipo => $nombre_tipo): ?>
                            <optgroup label="<?= $nombre_tipo === 'Incidente' ? 'INCIDENTES' : 'REQUERIMIENTOS' ?>">
                                <?php foreach ($catalogo as $item): if ($item['tipo_solicitud'] !== $tipo) continue; ?>
                                <option value="<?= (int) $item['id'] ?>" data-tipo="<?= esc($tipo) ?>"><?= esc($item['subcategoria']) ?> (<?= esc($item['grupo_resolutor']) ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="label-ans" for="reg_impacto">Impacto *</label>
                        <select name="impacto" id="reg_impacto" class="form-select" onchange="previsualizarRegistro()">
                            <?php foreach ($impactos as $i): ?><option value="<?= esc($i['valor_tecnico']) ?>"><?= esc($i['nombre_cliente']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="label-ans" for="reg_urgencia">Urgencia *</label>
                        <select name="urgencia" id="reg_urgencia" class="form-select" onchange="previsualizarRegistro()">
                            <?php foreach ($urgencias as $u): ?><option value="<?= esc($u['valor_tecnico']) ?>"><?= esc($u['nombre_cliente']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><div id="reg_preview" class="alert alert-info py-2 mb-0 small" hidden></div></div>
                    <div class="col-12">
                        <label class="label-ans" for="reg_asunto">Asunto *</label>
                        <input type="text" name="asunto" id="reg_asunto" class="form-control" maxlength="150" required>
                    </div>
                    <div class="col-12">
                        <label class="label-ans" for="reg_descripcion">Descripción *</label>
                        <textarea name="descripcion" id="reg_descripcion" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="col-12">
                        <label class="label-ans" for="reg_adjunto">Evidencia (opcional)</label>
                        <input type="file" name="adjunto" id="reg_adjunto" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary fw-bold px-4"><i class="fas fa-save me-2"></i> Registrar caso</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CASOS = <?= jsJson($casos_js) ?>;
const OBJETIVOS = <?= jsJson($objetivos) ?>;
const MATRIZ = <?= jsJson(MATRIZ_PRIORIDAD) ?>;
const UMBRALES = <?= jsJson(['atencion' => (float) $cfg['umbral_atencion'], 'riesgo' => (float) $cfg['umbral_riesgo'], 'critico' => (float) $cfg['umbral_critico']]) ?>;
const ETIQUETAS_NIVEL = <?= jsJson(ETIQUETAS_NIVEL_SLA) ?>;
const COLORES_NIVEL = <?= jsJson(COLORES_NIVEL_SLA) ?>;
const MI_ID = <?= $mi_id ?>;
const DIAS_CIERRE = <?= (int) $cfg['cierre_automatico_dias'] ?>;
const HORARIO = <?= jsJson(textoHorarioItsm($cfg)) ?>;
const JORNADA = <?= (int) minutosJornada($cfg) ?>;
const ORDEN_NIVEL = ['a_tiempo', 'atencion', 'riesgo', 'critico', 'vencido'];
const MI_NOMBRE = <?= jsJson((string) $perfil['nombre']) ?>;
const ABRIR = <?= jsJson((string) ($_GET['abrir'] ?? '')) ?>;
const COLORES_PRIORIDAD = { 'Crítica': '#dc3545', 'Alta': '#ea580c', 'Media': '#ca8a04', 'Baja': '#198754' };

// Escapa texto antes de insertarlo como HTML
function escHtml(txt) {
    return String(txt ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
}
// Enlace de descarga protegido para un adjunto de ticket (uploads/ no es accesible por URL)
function urlAdjunto(archivo) {
    return 'descargar_adjunto.php?archivo=' + encodeURIComponent(archivo);
}
function duracion(min) {
    min = Math.round(min);
    const signo = min < 0 ? '-' : '';
    min = Math.abs(min);
    const h = Math.floor(min / 60), m = min % 60;
    return signo + (h ? h + ' h' + (m ? ' ' + m + ' min' : '') : m + ' min');
}
function fecha(valor) {
    if (!valor) return '—';
    const [d, h] = String(valor).split(' ');
    const [a, me, di] = d.split('-');
    return `${di}/${me}/${a} ${(h || '00:00').slice(0, 5)}`;
}
// "lun 15/09 08:00"
function diaHora(valor) {
    if (!valor) return '';
    const d = new Date(String(valor).replace(' ', 'T'));
    return ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'][d.getDay()] + ' ' + fecha(valor).replace(/\/\d{4}/, '');
}
function nivelDe(pct) {
    return pct > 100 ? 'vencido' : pct >= UMBRALES.critico ? 'critico' : pct >= UMBRALES.riesgo ? 'riesgo' : pct >= UMBRALES.atencion ? 'atencion' : 'a_tiempo';
}

// ------------------------------------------------------------------ reloj del ANS (datos del servidor + avance en vivo)
let casoActual = null, slaActual = null, slaRecibido = 0, relojANS = null, refrescoANS = null;

function medir(m, avanza) {
    const extra = avanza ? Math.floor((Date.now() - slaRecibido) / 60000) : 0;
    const consumido = m.consumido + extra;
    const pct = m.objetivo > 0 ? Math.round(consumido * 1000 / m.objetivo) / 10 : 0;
    return { consumido, restante: m.objetivo - consumido, pct, nivel: m.finalizado ? m.nivel : nivelDe(pct) };
}

// Tiempo del ANS como lo entiende una persona: "5 h hábiles", "1 día hábil y 7 h" (con la jornada configurada)
function tiempoAns(min, cal) {
    min = Math.round(Math.abs(min));
    const jornada = cal === '24x7' ? 1440 : JORNADA;
    if (!jornada || min < jornada) {
        const base = duracion(min);
        return cal === '24x7' ? base : base + (/^1 (h|min)$/.test(base) ? ' hábil' : ' hábiles');
    }
    const dias = Math.floor(min / jornada), resto = min - dias * jornada;
    const unidad = cal === '24x7' ? (dias === 1 ? 'día' : 'días') : (dias === 1 ? 'día hábil' : 'días hábiles');
    return `${dias} ${unidad}${resto ? ' y ' + duracion(resto) : ''}`;
}

// "Lleva 9 h de 4 h hábiles": si el objetivo es menor a una jornada se habla en horas para no mezclar unidades
function consumoAns(consumido, objetivo, cal) {
    const jornada = cal === '24x7' ? 1440 : JORNADA;
    if (jornada && objetivo < jornada) return `${duracion(consumido)} de ${tiempoAns(objetivo, cal)}`;
    return `${tiempoAns(consumido, cal)} de ${tiempoAns(objetivo, cal)}`;
}

function pill(texto, color, icono) {
    return `<span class="pill-ans" style="background:${color}">${icono ? `<i class="fas ${icono} me-1"></i>` : ''}${texto}</span>`;
}

function pintarSla() {
    const s = slaActual;
    if (!s) return;
    const corriendo = s.reloj === 'corriendo', cal = s.calendario;
    // Cada caso tiene un tiempo máximo para responder y otro para solucionar, según su tipo y prioridad
    const tipoCaso = casoActual ? `${casoActual.tipo === 'INC' ? 'incidente' : 'requerimiento'} de prioridad ${casoActual.prioridad}` : '';
    document.getElementById('ans_resp_meta').innerText = `Meta: responder en máximo ${tiempoAns(s.respuesta.objetivo, cal)} (${tipoCaso})`;
    document.getElementById('ans_sol_meta').innerText = `Meta: solucionar en máximo ${tiempoAns(s.resolucion.objetivo, cal)} (${tipoCaso})`;

    // Primera respuesta: el especialista toma el caso y le escribe al cliente (o cambia su estado)
    const r = s.respuesta, mr = medir(r, corriendo && !r.finalizado);
    const colorR = r.finalizado ? (r.cumplido ? COLORES_NIVEL.a_tiempo : COLORES_NIVEL.vencido) : COLORES_NIVEL[mr.nivel];
    const barraR = document.getElementById('ans_resp_barra');
    barraR.style.width = (r.finalizado ? 100 : Math.min(100, mr.pct)) + '%';
    barraR.style.background = colorR;
    let estadoR, fraseR, detalleR, venceR;
    if (r.finalizado) {
        estadoR = r.cumplido ? pill('A tiempo', colorR, 'fa-check') : pill('Fuera del ANS', colorR, 'fa-times');
        fraseR = r.fecha ? `Respondida el ${diaHora(r.fecha)}` : 'Respondida';
        detalleR = `Tomó ${consumoAns(mr.consumido, r.objetivo, cal)} permitidas`;
        venceR = r.cumplido ? '' : `El límite era ${diaHora(r.vence)}`;
    } else if (mr.restante >= 0) {
        estadoR = pill(ETIQUETAS_NIVEL[mr.nivel], colorR);
        fraseR = mr.consumido === 0 && s.inicio_conteo && s.inicio_conteo > s.calculado
            ? `Empieza a contar el ${diaHora(s.inicio_conteo)}`
            : `Quedan ${tiempoAns(mr.restante, cal)}`;
        detalleR = `Lleva ${consumoAns(mr.consumido, r.objetivo, cal)}`;
        venceR = `Límite: ${diaHora(r.vence)}`;
    } else {
        estadoR = pill('Vencida', COLORES_NIVEL.vencido, 'fa-exclamation-triangle');
        fraseR = `Debía responderse antes del ${diaHora(r.vence)}`;
        detalleR = `Lleva ${consumoAns(mr.consumido, r.objetivo, cal)}`;
        venceR = `${tiempoAns(-mr.restante, cal)} de retraso`;
    }
    document.getElementById('ans_resp_estado').innerHTML = estadoR;
    document.getElementById('ans_resp_frase').innerText = fraseR;
    document.getElementById('ans_resp_frase').style.color = !r.finalizado && mr.restante < 0 ? COLORES_NIVEL.vencido : '';
    document.getElementById('ans_resp_detalle').innerText = detalleR;
    document.getElementById('ans_resp_vence').innerText = venceR;

    // Solución: resolver el caso o entregar lo solicitado (la pausa no cuenta)
    const o = s.resolucion, mo = medir(o, corriendo);
    const color = o.finalizado ? (o.cumplido ? COLORES_NIVEL.a_tiempo : COLORES_NIVEL.vencido) : (s.reloj === 'detenido' && mo.restante >= 0 ? '#ca8a04' : COLORES_NIVEL[mo.nivel]);
    const barra = document.getElementById('ans_barra');
    barra.style.width = (o.finalizado ? 100 : Math.min(100, mo.pct)) + '%';
    barra.style.background = color;
    barra.className = 'progress-bar' + (corriendo && ['riesgo', 'critico', 'vencido'].includes(mo.nivel) ? ' progress-bar-striped progress-bar-animated' : '');
    const pausa = o.minutos_pausa ? ` · ${duracion(o.minutos_pausa)} en pausa (no cuentan)` : '';
    let fraseO, venceO, etiquetaO;
    if (o.finalizado) {
        etiquetaO = o.cumplido ? 'Cumplido' : 'Incumplido';
        fraseO = o.fecha ? `Solucionado el ${diaHora(o.fecha)}` : 'Solucionado';
        venceO = o.vence ? `${o.cumplido ? 'Límite' : 'El límite era'}: ${diaHora(o.vence)}` : '';
    } else if (s.reloj === 'detenido') {
        etiquetaO = 'En pausa';
        fraseO = mo.restante >= 0 ? `Quedan ${tiempoAns(mo.restante, cal)} (reloj en pausa)` : `Vencida · ${tiempoAns(-mo.restante, cal)} de retraso`;
        venceO = 'El límite se recalcula al reanudar';
    } else if (mo.restante >= 0) {
        etiquetaO = ETIQUETAS_NIVEL[mo.nivel];
        fraseO = mo.consumido === 0 && s.inicio_conteo && s.inicio_conteo > s.calculado ? `Empieza a contar el ${diaHora(s.inicio_conteo)}` : `Quedan ${tiempoAns(mo.restante, cal)}`;
        venceO = `Límite: ${diaHora(o.vence)}`;
    } else {
        etiquetaO = 'Vencida';
        fraseO = `Debía solucionarse antes del ${diaHora(o.vence)}`;
        venceO = `${tiempoAns(-mo.restante, cal)} de retraso`;
    }
    const pct = document.getElementById('ans_pct');
    pct.className = 'pill-ans';
    pct.style.background = color;
    pct.innerText = `${Math.round(mo.pct)} % · ${etiquetaO}`;
    const frase = document.getElementById('ans_reloj');
    frase.innerText = fraseO;
    frase.style.color = !o.finalizado && mo.restante < 0 ? COLORES_NIVEL.vencido : '';
    document.getElementById('ans_info').innerText = `${o.finalizado ? 'Tomó' : 'Lleva'} ${consumoAns(mo.consumido, o.objetivo, cal)}${pausa}`;
    document.getElementById('ans_vence').innerText = venceO;

    // Estado del reloj en una frase: por qué avanza o por qué está quieto
    const banner = document.getElementById('ans_reloj_estado');
    const horario = cal === '24x7' ? 'este caso cuenta las 24 horas, todos los días' : 'solo cuentan horas hábiles: ' + HORARIO;
    if (s.reloj === 'finalizado') {
        banner.className = 'reloj-banner fin mb-3';
        banner.innerHTML = `<i class="fas fa-flag-checkered"></i><div><b>Reloj finalizado.</b> El caso se solucionó${o.fecha ? ' el ' + escHtml(diaHora(o.fecha)) : ''}; sus tiempos quedaron fijos.</div>`;
    } else if (s.reloj === 'detenido') {
        banner.className = 'reloj-banner pausa mb-3';
        banner.innerHTML = '<i class="fas fa-pause-circle"></i><div><b>Reloj de solución en pausa.</b> El caso espera información o una acción del cliente y ese tiempo no cuenta. Vuelve a correr cuando el caso regrese a gestión.</div>';
    } else if (s.reloj === 'fuera_horario') {
        banner.className = 'reloj-banner fuera mb-3';
        banner.innerHTML = `<i class="fas fa-moon"></i><div><b>Reloj detenido: fuera del horario de atención.</b> Se reanuda el <b>${escHtml(diaHora(s.reanuda))}</b>. Noches, fines de semana y festivos no cuentan (${escHtml(horario)}).</div>`;
    } else {
        banner.className = 'reloj-banner corriendo mb-3';
        banner.innerHTML = `<span class="pulso"></span><div><b>Reloj en curso.</b> ${escHtml(horario.charAt(0).toUpperCase() + horario.slice(1))}.</div>`;
    }

    // Semáforo general: muestra lo más grave del caso
    const semaforo = document.getElementById('ans_semaforo');
    let textoSemaforo, fondo;
    if (s.reloj === 'finalizado') {
        textoSemaforo = o.cumplido ? 'ANS CUMPLIDO' : 'ANS INCUMPLIDO';
        fondo = o.cumplido ? COLORES_NIVEL.a_tiempo : COLORES_NIVEL.vencido;
    } else if (mo.nivel === 'vencido') {
        textoSemaforo = 'SOLUCIÓN VENCIDA'; fondo = COLORES_NIVEL.vencido;
    } else if (!r.finalizado && mr.restante < 0) {
        textoSemaforo = '1ª RESPUESTA VENCIDA'; fondo = COLORES_NIVEL.vencido;
    } else if (s.reloj === 'detenido') {
        textoSemaforo = 'EN PAUSA'; fondo = '#ca8a04';
    } else {
        const peor = ORDEN_NIVEL[Math.max(ORDEN_NIVEL.indexOf(mo.nivel), r.finalizado ? 0 : ORDEN_NIVEL.indexOf(mr.nivel))];
        textoSemaforo = ETIQUETAS_NIVEL[peor].toUpperCase(); fondo = COLORES_NIVEL[peor];
    }
    semaforo.className = 'pill-ans';
    semaforo.style.background = fondo;
    semaforo.innerText = textoSemaforo;

    // Siguiente paso sugerido al especialista
    const siguiente = document.getElementById('ans_siguiente');
    let paso = '';
    if (s.reloj !== 'finalizado' && !r.finalizado) {
        paso = `<i class="fas fa-reply me-2"></i><div><b>Siguiente paso:</b> escribe al cliente con una nota visible o cambia el estado del caso. Eso registra la primera respuesta${mr.restante < 0 ? ' (ya está vencida: hazlo cuanto antes)' : ''}.</div>`;
    } else if (s.reloj !== 'finalizado' && s.reloj !== 'detenido' && ['riesgo', 'critico', 'vencido'].includes(mo.nivel)) {
        paso = '<i class="fas fa-bolt me-2"></i><div><b>Siguiente paso:</b> prioriza la solución; si necesitas apoyo, reasigna o escala el caso en el panel de Asignación.</div>';
    }
    siguiente.innerHTML = paso;
    siguiente.className = 'siguiente-paso mt-3' + (paso && ((!r.finalizado && mr.restante < 0) || mo.nivel === 'vencido') ? ' urgente' : '');
    siguiente.hidden = paso === '';
}

function refrescarSla() {
    if (!casoActual) return;
    fetch('api_ticket.php?id=' + casoActual.id)
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => { if (d && d.ok) { slaActual = d.sla; slaRecibido = Date.now(); pintarSla(); } })
        .catch(() => {});
}

// ------------------------------------------------------------------ gestión del caso
function calcularPrioridad(simular = true) {
    const imp = document.getElementById('mod_impacto').value, urg = document.getElementById('mod_urgencia').value;
    const prio = (MATRIZ[imp] && MATRIZ[imp][urg]) || (casoActual ? casoActual.prioridad : 'Media');
    const display = document.getElementById('mod_prioridad_display');
    display.value = prio;
    display.style.color = COLORES_PRIORIDAD[prio] || '#0f172a';
    document.getElementById('input_prioridad_hidden').value = prio;

    const aviso = document.getElementById('simulacion_ans');
    if (!simular || !casoActual || prio === casoActual.prioridad) { aviso.hidden = true; return; }
    aviso.hidden = false;
    aviso.innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i> Calculando el nuevo ANS...';
    fetch(`api_ticket.php?id=${casoActual.id}&impacto=${encodeURIComponent(imp)}&urgencia=${encodeURIComponent(urg)}`)
        .then((r) => r.json())
        .then((d) => {
            if (!d.ok || !d.simulacion) { aviso.hidden = true; return; }
            const n = d.simulacion.sla;
            aviso.innerHTML = `<i class="fas fa-random me-1"></i> Con prioridad <b>${escHtml(d.simulacion.prioridad)}</b>: 1ª respuesta ${duracion(n.respuesta.objetivo)}, solución ${duracion(n.resolucion.objetivo)}`
                + (n.resolucion.vence ? ` (vence ${fecha(n.resolucion.vence)})` : '') + ` · ${n.resolucion.pct}% consumido. Se aplica al guardar.`;
        })
        .catch(() => { aviso.hidden = true; });
}

function comportamientoSeleccionado() {
    const sel = document.getElementById('mod_estado');
    return sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].getAttribute('data-comportamiento') : '';
}

// Explica qué pasará con el ANS al guardar el estado elegido
function verificarCierre() {
    const ayuda = document.getElementById('ayuda_estado');
    const comp = comportamientoSeleccionado();
    const antes = casoActual ? casoActual.comportamiento : comp;
    let texto = '';
    if (comp !== antes) {
        if (comp === 'Pausa') texto = '<i class="fas fa-pause-circle text-warning me-1"></i>El reloj de solución se detiene. Escribe en Notas qué necesitas del cliente (obligatorio).';
        else if (comp === 'Solucionado') texto = `<i class="fas fa-user-check text-success me-1"></i>Se envía la solución al cliente para que la confirme; si no responde, se cierra en ${DIAS_CIERRE} días hábiles.`;
        else if (comp === 'Cerrado') texto = '<i class="fas fa-lock text-dark me-1"></i>El caso queda cerrado y se envía la encuesta de satisfacción.';
        else if (antes === 'Pausa') texto = '<i class="fas fa-play-circle text-primary me-1"></i>El reloj de solución se reanuda y la pausa no cuenta contra el ANS.';
        else if (antes === 'Solucionado') texto = '<i class="fas fa-redo text-danger me-1"></i>Reapertura: el reloj continúa donde quedó.';
        else if (antes === 'Abierto') texto = '<i class="fas fa-reply text-primary me-1"></i>Cuenta como primera respuesta del caso.';
    }
    ayuda.innerHTML = texto;
    ayuda.hidden = texto === '';
}

function validarCierreObligatorio(e) {
    const comp = comportamientoSeleccionado();
    const cambio = !casoActual || comp !== casoActual.comportamiento;
    const sol = document.getElementById('input_solucion').value.trim();
    const nota = document.getElementById('nueva_nota').value.trim();

    if (cambio && (comp === 'Solucionado' || comp === 'Cerrado') && sol === '') {
        e.preventDefault();
        new bootstrap.Tab(document.querySelector('a[href="#tab_solucion"]')).show();
        setTimeout(() => {
            alert("⚠️ ALERTA DE CALIDAD (ITIL):\n\nEs OBLIGATORIO documentar la 'Solución Técnica Aplicada' para poder solucionar o cerrar este caso. Esta información se envía al cliente y se usa para la Base de Conocimiento.");
            document.getElementById('input_solucion').focus();
        }, 200);
        return false;
    }
    if (cambio && (comp === 'Solucionado' || comp === 'Cerrado') && document.getElementById('mod_codigo_cierre').value === '') {
        e.preventDefault();
        new bootstrap.Tab(document.querySelector('a[href="#tab_solucion"]')).show();
        setTimeout(() => {
            alert("⚠️ ALERTA DE CALIDAD (ITIL):\n\nEs OBLIGATORIO seleccionar el 'Código de cierre' (cómo se resolvió el caso) para solucionarlo o cerrarlo.");
            document.getElementById('mod_codigo_cierre').focus();
        }, 200);
        return false;
    }
    if (cambio && comp === 'Pausa' && nota === '') {
        e.preventDefault();
        new bootstrap.Tab(document.querySelector('a[href="#tab_notas"]')).show();
        setTimeout(() => {
            alert("⏸️ PAUSA DEL ANS:\n\nEs OBLIGATORIO escribir en Notas qué información o acción esperas del cliente para detener el reloj de solución.");
            document.getElementById('nueva_nota').focus();
        }, 200);
        return false;
    }
    const btn = document.getElementById('btnGuardarGestion');
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Guardando y notificando...';
    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.8';
    return true;
}

function seleccionar(idSelect, valor, texto, atributos) {
    const sel = document.getElementById(idSelect);
    let opcion = Array.from(sel.options).find((o) => o.value.toLowerCase() === String(valor ?? '').toLowerCase());
    if (!opcion && valor) {
        opcion = new Option(texto || valor, valor);
        Object.entries(atributos || {}).forEach(([k, v]) => opcion.setAttribute(k, v));
        sel.add(opcion);
    }
    sel.value = opcion ? opcion.value : '';
}

function abrirCaso(id) {
    const c = CASOS[id];
    if (!c) return;
    casoActual = c;
    const btn = document.getElementById('btnGuardarGestion');
    btn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar gestión';
    btn.style.pointerEvents = 'auto';
    btn.style.opacity = '1';

    document.getElementById('formAtencion').reset();
    document.getElementById('mod_id_input').value = c.id;
    document.getElementById('mod_codigo').innerText = c.codigo;
    document.getElementById('mod_tipo_reg').innerText = c.tipo === 'INC' ? 'Incidente' : 'Requerimiento';
    document.getElementById('mod_cliente').innerText = c.cliente || 'Sin cliente';
    // Sin enlaces directos de correo o teléfono: el contacto se hace con los botones, que dejan traza en el historial
    document.getElementById('mod_cliente_contacto').innerText = [c.organizacion, c.telefono, c.correo].filter(Boolean).join(' · ');
    prepararContacto(c);
    // Ficha del caso: lo inmediato sale de la bandeja; quién lo registró, asignaciones y última gestión llegan con el flujo
    document.getElementById('ficha_registro').innerText = diaHora(c.creado).split(' ')[0] + ' ' + fecha(c.creado);
    document.getElementById('ficha_registro_por').innerText = '';
    document.getElementById('ficha_especialista').innerText = c.tecnico || 'Sin asignar';
    document.getElementById('ficha_asignaciones').innerText = '';
    document.getElementById('ficha_ultima').innerText = '…';
    document.getElementById('ficha_ultima_por').innerText = '';
    const resolucion = c.sla ? c.sla.resolucion : null;
    const fichaVence = document.getElementById('ficha_vence');
    fichaVence.innerText = !resolucion ? '—' : (resolucion.finalizado ? (resolucion.cumplido ? 'Cumplido' : 'Incumplido')
        : (!resolucion.vence ? 'En pausa' : (resolucion.nivel === 'vencido' ? `Venció el ${diaHora(resolucion.vence)}` : diaHora(resolucion.vence))));
    fichaVence.style.color = resolucion && (resolucion.nivel === 'vencido' || resolucion.cumplido === false) ? '#991b1b' : '';
    const rr = c.sla ? c.sla.respuesta : null;
    document.getElementById('ficha_vence_detalle').innerText = !rr ? '' : (rr.finalizado ? (rr.cumplido ? '1ª respuesta a tiempo' : '1ª respuesta fuera del ANS')
        : (rr.nivel === 'vencido' ? '1ª respuesta vencida desde el ' : '1ª respuesta hasta el ') + diaHora(rr.vence));
    document.getElementById('ficha_pausa').innerText = resolucion ? duracion(resolucion.minutos_pausa) + ' en pausa' : '—';
    document.getElementById('ficha_reaperturas').innerText = `${c.reaperturas} reapertura(s)`;
    document.getElementById('mod_asunto').innerText = c.asunto || '';
    document.getElementById('mod_asunto').title = c.asunto || '';
    document.getElementById('mod_servicio').innerText = (c.servicio || 'No definido') + (c.categoria ? ' · ' + c.categoria : '');
    document.getElementById('mod_descripcion').value = c.descripcion || '';
    seleccionar('mod_grupo', c.grupo);
    seleccionar('mod_tecnico', c.tecnico_id ? String(c.tecnico_id) : '', c.tecnico);
    seleccionar('mod_estado', c.estado, c.estado, { 'data-comportamiento': c.comportamiento });
    seleccionar('mod_impacto', c.impacto || 'Medio');
    seleccionar('mod_urgencia', c.urgencia || 'Media');
    calcularPrioridad(false);
    verificarCierre();
    document.getElementById('input_solucion').value = c.solucion || '';
    document.getElementById('mod_codigo_cierre').value = c.codigo_cierre || '';
    document.getElementById('mod_causa_raiz').value = c.causa_raiz || '';

    const insignias = [
        `<span class="chip"><i class="fas ${c.tipo === 'INC' ? 'fa-bolt' : 'fa-clipboard-list'}"></i>${c.tipo === 'INC' ? 'Incidente' : 'Requerimiento'}</span>`,
        `<span class="chip chip-color ${escHtml(c.clase_color || 'bg-secondary')}">${escHtml(c.estado)}</span>`,
        `<span class="chip chip-color" style="background:${COLORES_PRIORIDAD[c.prioridad] || '#64748b'}">Prioridad ${escHtml(c.prioridad)}</span>`,
        `<span class="chip"><i class="fas fa-layer-group"></i>${escHtml(c.grupo || 'Sin grupo')}</span>`,
    ];
    if (c.reaperturas > 0) insignias.push(`<span class="chip chip-color bg-danger"><i class="fas fa-redo"></i>Reabierto ${c.reaperturas}</span>`);
    if (c.calificacion > 0) insignias.push(`<span class="chip text-warning">${'★'.repeat(c.calificacion)}${'☆'.repeat(5 - c.calificacion)}</span>`);
    document.getElementById('mod_insignias').innerHTML = insignias.join(' ');

    const aviso = document.getElementById('aviso_caso');
    aviso.hidden = true;
    if (c.comportamiento === 'Cerrado') {
        aviso.className = 'alert alert-dark py-2 mb-2';
        aviso.innerHTML = `<i class="fas fa-lock me-2"></i>Caso cerrado el ${fecha(c.cerrado)}. Solo lectura.` + (c.comentario ? ` Comentario del cliente: «${escHtml(c.comentario)}»` : '');
        aviso.hidden = false;
    } else if (c.comportamiento === 'Solucionado') {
        aviso.className = 'alert alert-success py-2 mb-2';
        aviso.innerHTML = `<i class="fas fa-user-check me-2"></i>Solucionado el ${fecha(c.resuelto)}; esperando la confirmación del cliente.` + (c.cierre_automatico ? ` Cierre automático: ${fecha(c.cierre_automatico)}.` : '');
        aviso.hidden = false;
    } else if (c.novedad) {
        aviso.className = 'alert alert-warning py-2 mb-2';
        aviso.innerHTML = '<i class="fas fa-bell me-2"></i>Novedad sin revisar: el cliente respondió o el caso generó una alerta de ANS. Se marca como revisada al guardar.';
        aviso.hidden = false;
    }
    const soloLectura = c.comportamiento === 'Cerrado';
    document.querySelectorAll('#formAtencion select, #formAtencion textarea:not([readonly]):not([data-correo]), #formAtencion input[type=file], #formAtencion input[type=checkbox]').forEach((el) => { el.disabled = soloLectura; });
    btn.hidden = soloLectura;

    document.getElementById('enlace_adjunto_cliente').innerHTML = c.adjunto
        ? `<a href="${urlAdjunto(c.adjunto)}" target="_blank" class="btn btn-primary btn-sm fw-bold shadow-sm"><i class="fas fa-download me-2"></i> Descargar Documento</a>`
        : '<span class="text-muted small"><i class="fas fa-ban me-1"></i> El cliente no adjuntó archivos.</span>';

    const etiquetas = { publica: ['bg-primary', 'Visible al cliente'], interna: ['bg-warning text-dark', 'Nota interna'], cliente: ['bg-info text-dark', 'Cliente'] };
    document.getElementById('historial_chat').innerHTML = c.notas.length
        ? c.notas.map((n) => `<div class="nota nota-${n.tipo}"><div class="d-flex justify-content-between mb-1"><span><span class="badge ${etiquetas[n.tipo][0]} me-1">${etiquetas[n.tipo][1]}</span><b>${escHtml(n.autor)}</b></span><small class="text-muted">${escHtml(n.fecha)}</small></div>${n.html}</div>`).join('')
        : '<span class="text-muted small">Sin notas todavía.</span>';
    document.getElementById('contador_notas').innerText = c.notas.length;
    cargarFlujo(c.id);

    slaActual = c.sla;
    slaRecibido = Date.now();
    clearInterval(relojANS);
    clearInterval(refrescoANS);
    if (slaActual) {
        pintarSla();
        relojANS = setInterval(pintarSla, 1000);
        refrescoANS = setInterval(refrescarSla, 60000);
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalASM')).show();
}

// ------------------------------------------------------------------ flujo del caso (quién, qué, cuándo y cuánto tiempo)
const ICONOS_EVENTO = { registro: 'fa-plus-circle', asignacion: 'fa-user-check', clasificacion: 'fa-random', estado: 'fa-exchange-alt', pausa: 'fa-pause-circle', ans: 'fa-reply',
    solucion: 'fa-check-double', reapertura: 'fa-redo', cliente: 'fa-user', cierre: 'fa-lock', alerta: 'fa-bell', nota: 'fa-comment-dots', hito: 'fa-flag-checkered', correo: 'fa-envelope', contacto: 'fa-phone-alt', otro: 'fa-circle' };

function duracionCalendario(min) {
    min = Math.round(min);
    const d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60);
    return d ? `${d} d${h ? ' ' + h + ' h' : ''}` : duracion(min);
}

function cargarFlujo(id) {
    const cargando = document.getElementById('flujo_cargando');
    cargando.hidden = false;
    cargando.innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i> Cargando el flujo del caso...';
    document.getElementById('flujo_contenido').hidden = true;
    document.getElementById('cliente_datos').innerHTML = '<span class="small text-muted">Cargando...</span>';
    document.getElementById('casos_cliente').innerHTML = '';
    document.getElementById('lista_correos').innerHTML = '<span class="small text-muted">Cargando...</span>';
    fetch(`api_ticket.php?id=${id}&flujo=1`)
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => {
            if (!casoActual || casoActual.id !== id) return;
            if (!d || !d.ok || !d.flujo) { cargando.innerText = 'No fue posible cargar el flujo del caso.'; return; }
            pintarFlujo(d.flujo);
            pintarCliente(d.cliente);
            pintarCorreos(d.correos || []);
            cargando.hidden = true;
            document.getElementById('flujo_contenido').hidden = false;
        })
        .catch(() => { cargando.innerText = 'No fue posible cargar el flujo del caso.'; });
}

function pintarFlujo(f) {
    const r = f.resumen;
    document.getElementById('ficha_registro_por').innerText = `${r.canal} · ${r.registrado_por}`;
    document.getElementById('ficha_asignaciones').innerText = r.asignaciones ? `${r.asignaciones} asignación(es) · ${r.reasignaciones} reasignación(es)` : 'Sin asignaciones registradas';
    document.getElementById('ficha_ultima').innerText = r.ultima_gestion ? diaHora(r.ultima_gestion.fecha) : 'Sin gestión';
    document.getElementById('ficha_ultima_por').innerText = r.ultima_gestion ? `${r.ultima_gestion.evento} · ${r.ultima_gestion.usuario}` : 'La mesa aún no ha gestionado el caso';
    document.getElementById('ficha_reaperturas').innerText = `${r.reaperturas} reapertura(s) · ${r.reclasificaciones} reclasificación(es)`;

    document.getElementById('flujo_explicacion').innerHTML = f.explicacion.map((texto) => `<li>${escHtml(texto)}</li>`).join('');
    const total = f.estados.reduce((s, e) => s + e.habiles, 0) || 1;
    document.getElementById('flujo_estados').innerHTML = (f.estados.length
        ? f.estados.map((e) => `<div class="estado-tiempo">
            <div class="d-flex justify-content-between small gap-2"><b>${escHtml(e.estado)}${e.actual ? ' <span class="badge bg-primary">actual</span>' : ''}</b>
            <span class="text-nowrap">${duracion(e.habiles)} hábiles${e.calendario !== e.habiles ? ' · ' + duracionCalendario(e.calendario) + ' calendario' : ''}</span></div>
            <div class="progress" style="height:5px"><div class="progress-bar" style="width:${Math.round(e.habiles * 100 / total)}%"></div></div></div>`).join('')
        : '<span class="small text-muted">Sin datos.</span>')
        + (r.estados_aproximados ? '<div class="small text-muted mt-1">Aproximado: el caso tuvo cambios de estado antes de que existiera el historial.</div>' : '')
        + (r.codigo_cierre ? `<div class="small mt-2"><b>Código de cierre:</b> ${escHtml(r.codigo_cierre)}${r.causa_raiz ? '<br><b>Causa raíz:</b> ' + escHtml(r.causa_raiz) : ''}</div>` : '');

    document.getElementById('lista_historial').innerHTML = f.eventos.map((e) => {
        const cambio = e.estado_nuevo
            ? `<div class="small my-1">${e.estado_anterior ? `<span class="badge bg-light text-dark border">${escHtml(e.estado_anterior)}</span> <i class="fas fa-arrow-right text-muted mx-1"></i>` : ''}<span class="badge bg-primary">${escHtml(e.estado_nuevo)}</span></div>` : '';
        const tiempo = e.desde_anterior
            ? ` · +${duracion(e.desde_anterior.habiles)} hábiles${e.desde_anterior.calendario !== e.desde_anterior.habiles ? ' (' + duracionCalendario(e.desde_anterior.calendario) + ' calendario)' : ''} desde el paso anterior` : '';
        return `<li class="evento-${e.tipo}${e.publico ? '' : ' privado'}${e.tipo === 'hito' ? ' hito hito-' + e.resultado : ''}${e.futuro ? ' futuro' : ''}">
            <div class="d-flex justify-content-between gap-2"><b><i class="fas ${ICONOS_EVENTO[e.tipo] || ICONOS_EVENTO.otro} me-1"></i>${escHtml(e.evento)}</b><small class="text-muted text-nowrap">${diaHora(e.fecha)}</small></div>
            ${cambio}${e.evento === 'Cambio de estado' ? '' : `<div class="small">${escHtml(e.detalle || '')}</div>`}
            <small class="text-muted">${escHtml(e.usuario || '')}${e.publico ? '' : ' · interno'}${e.calculado && e.tipo !== 'hito' ? ' · dato del caso' : ''}${tiempo}</small></li>`;
    }).join('');
}

function pintarCliente(cliente) {
    if (!cliente) return;
    document.getElementById('cliente_datos').innerHTML = `<div class="fw-bold">${escHtml(cliente.nombre)}</div>
        <div class="small text-muted mb-2">${escHtml(cliente.organizacion || 'Sin organización')}</div>
        ${cliente.correo ? `<div class="small"><i class="fas fa-envelope me-1 text-muted"></i><a href="mailto:${escHtml(cliente.correo)}">${escHtml(cliente.correo)}</a></div>` : ''}
        ${cliente.telefono ? `<div class="small"><i class="fas fa-phone me-1 text-muted"></i><a href="#" class="enlace-llamar" data-numero="${escHtml(String(cliente.telefono).replace(/[^\d+]/g, ''))}">${escHtml(cliente.telefono)}</a></div>` : ''}
        <div class="small mt-2"><b>${cliente.abiertos}</b> caso(s) abiertos · <b>${cliente.total_casos}</b> en total</div>`;
    document.getElementById('casos_cliente').innerHTML = cliente.casos.length
        ? cliente.casos.map((c) => `<tr><td class="fw-bold text-nowrap"><a href="gestion_it.php?abrir=${encodeURIComponent(c.codigo_ticket)}">${escHtml(c.codigo_ticket)}</a></td>
            <td class="small">${escHtml(c.asunto)}</td><td><span class="badge bg-light text-dark border">${escHtml(c.estado)}</span></td><td class="small">${escHtml(c.prioridad)}</td><td class="small text-nowrap">${fecha(c.created_at)}</td></tr>`).join('')
        : '<tr><td colspan="5" class="text-muted small">Es el primer caso de este cliente.</td></tr>';
}

// ------------------------------------------------------------------ contacto con el cliente (WhatsApp, llamada y correo, todo con traza)
function registrarContacto(canal) {
    if (!casoActual) return;
    const id = casoActual.id;
    const datos = new FormData();
    datos.append('accion', 'registrar_contacto');
    datos.append('ticket_id', id);
    datos.append('canal', canal);
    fetch('procesar_soporte.php', { method: 'POST', body: datos })
        .then(() => { if (casoActual && casoActual.id === id) cargarFlujo(id); })
        .catch(() => {});
}

function prepararContacto(c) {
    const whatsapp = document.getElementById('btn_whatsapp');
    const movil = window.innerWidth < 992 || /Android|iPhone|iPad/i.test(navigator.userAgent);
    const saludo = `Hola ${String(c.cliente || '').split(' ')[0]}, te escribe ${MI_NOMBRE} de DataSense sobre tu caso ${c.codigo} («${c.asunto}»). `;
    if (c.whatsapp) {
        whatsapp.href = movil ? `https://wa.me/${c.whatsapp}?text=${encodeURIComponent(saludo)}` : `https://web.whatsapp.com/send?phone=${c.whatsapp}&text=${encodeURIComponent(saludo)}`;
        whatsapp.classList.remove('disabled');
        whatsapp.removeAttribute('aria-disabled');
        whatsapp.title = movil ? 'Abrir el chat en WhatsApp' : 'Abrir el chat en WhatsApp Web';
    } else {
        whatsapp.removeAttribute('href');
        whatsapp.classList.add('disabled');
        whatsapp.setAttribute('aria-disabled', 'true');
        whatsapp.title = 'El cliente no tiene un celular válido registrado';
    }
    // En el computador un enlace "tel:" no hace nada: se abre una ventana con el número para marcarlo desde el teléfono
    const llamar = document.getElementById('btn_llamar');
    const numeroLlamada = String(c.telefono || '').replace(/[^\d+]/g, '');
    llamar.hidden = false;
    llamar.classList.toggle('disabled', !numeroLlamada);
    llamar.title = numeroLlamada
        ? (movil ? 'Llamar al cliente' : 'Ver el número para llamar y dejar la llamada registrada')
        : 'El cliente no tiene teléfono registrado';
    llamar.removeAttribute('href');
    if (numeroLlamada) {
        llamar.dataset.numero = numeroLlamada;
        llamar.removeAttribute('aria-disabled');
        if (movil) llamar.href = 'tel:' + numeroLlamada; // en celular marca directo
    } else {
        delete llamar.dataset.numero;
        llamar.setAttribute('aria-disabled', 'true');
    }
    document.getElementById('correo_para').innerText = c.correo ? `${c.cliente} <${c.correo}>` : 'el cliente no tiene correo registrado';
    document.getElementById('btn_redactar_correo').disabled = !c.correo;
    document.getElementById('btnEnviarCorreo').disabled = !c.correo;
    document.getElementById('correo_asunto').value = 'Seguimiento de tu caso';
    document.getElementById('correo_mensaje').value = '';
    document.getElementById('correo_estado').innerText = '';
    document.getElementById('contador_correos').innerText = '0';
}

function pintarCorreos(correos) {
    document.getElementById('contador_correos').innerText = correos.length;
    document.getElementById('lista_correos').innerHTML = correos.length
        ? correos.map((m) => `<details class="correo-item"><summary>
            <div class="d-flex justify-content-between gap-2 flex-wrap"><b>${escHtml(m.asunto)}</b><span class="badge ${Number(m.enviado) ? 'bg-success' : 'bg-danger'}">${Number(m.enviado) ? 'Enviado' : 'No enviado'}</span></div>
            <div class="small text-muted">${escHtml(m.tipo)} · ${escHtml(diaHora(m.fecha))} · ${escHtml(m.usuario || 'Automático de la plataforma')} · para ${escHtml(m.destinatario || 'cliente sin correo')}</div></summary>
            <div class="correo-contenido">${escHtml(m.contenido || '')}</div></details>`).join('')
        : '<span class="small text-muted">Aún no se han enviado correos al cliente en este caso.</span>';
}

document.getElementById('btn_whatsapp').addEventListener('click', (e) => {
    if (e.currentTarget.classList.contains('disabled')) { e.preventDefault(); return; }
    registrarContacto('WhatsApp');
});
function abrirModalLlamada(numero) {
    document.getElementById('llamada_numero').innerText = numero || '';
    document.getElementById('llamada_cliente').innerText = casoActual ? (casoActual.cliente || '') : '';
    document.getElementById('llamada_marcar').href = 'tel:' + (numero || '');
    document.getElementById('llamada_estado').innerText = '';
    document.getElementById('btn_registrar_llamada').disabled = false;
    new bootstrap.Modal(document.getElementById('modalLlamada')).show();
}
document.getElementById('btn_llamar').addEventListener('click', (e) => {
    const boton = e.currentTarget;
    if (boton.classList.contains('disabled')) { e.preventDefault(); return; }
    // En celular el enlace "tel:" marca solo y la llamada queda registrada de una vez
    if (boton.hasAttribute('href')) { registrarContacto('Llamada'); return; }
    e.preventDefault();
    abrirModalLlamada(boton.dataset.numero);
});
// El teléfono del panel del cliente: en el celular marca directo y en el computador abre la ventana con el
// número. La decisión se toma al hacer clic y no al pintarlo, porque ese bloque lo dibuja otra función.
document.addEventListener('click', (e) => {
    const enlace = e.target.closest('.enlace-llamar');
    if (!enlace) return;
    e.preventDefault();
    const numero = enlace.dataset.numero || '';
    const enCelular = window.innerWidth < 992 || /Android|iPhone|iPad/i.test(navigator.userAgent);
    if (enCelular && numero) {
        registrarContacto('Llamada');
        window.location.href = 'tel:' + numero;
        return;
    }
    abrirModalLlamada(numero);
});
// Al marcar se registra la llamada de una vez: el historial del caso no depende de que se pulse el otro botón
document.getElementById('llamada_marcar').addEventListener('click', () => {
    registrarContacto('Llamada');
    document.getElementById('llamada_estado').innerText = 'Llamada registrada en el historial del caso. Si el computador pregunta con qué aplicación llamar, abre la nota de abajo: se escoge una sola vez.';
    document.getElementById('btn_registrar_llamada').disabled = true;
});
document.getElementById('btn_copiar_numero').addEventListener('click', () => {
    const numero = document.getElementById('llamada_numero').innerText;
    const estado = document.getElementById('llamada_estado');
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(numero).then(() => { estado.innerText = 'Número copiado.'; }).catch(() => { estado.innerText = 'Cópialo a mano: ' + numero; });
    } else {
        estado.innerText = 'Cópialo a mano: ' + numero;
    }
});
document.getElementById('btn_registrar_llamada').addEventListener('click', () => {
    registrarContacto('Llamada');
    document.getElementById('llamada_estado').innerText = 'Llamada registrada en el historial del caso.';
    setTimeout(() => {
        const ventana = bootstrap.Modal.getInstance(document.getElementById('modalLlamada'));
        if (ventana) ventana.hide();
    }, 900);
});
document.getElementById('btn_redactar_correo').addEventListener('click', () => {
    new bootstrap.Tab(document.querySelector('a[href="#tab_correos"]')).show();
    setTimeout(() => document.getElementById('correo_mensaje').focus(), 200);
});
document.getElementById('correo_asunto').addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
document.getElementById('btnEnviarCorreo').addEventListener('click', () => {
    if (!casoActual) return;
    const id = casoActual.id;
    const asunto = document.getElementById('correo_asunto').value.trim();
    const mensaje = document.getElementById('correo_mensaje').value.trim();
    const estado = document.getElementById('correo_estado');
    const boton = document.getElementById('btnEnviarCorreo');
    if (!asunto || !mensaje) { estado.className = 'small text-danger'; estado.innerText = 'Escribe el asunto y el mensaje.'; return; }
    const datos = new FormData();
    datos.append('accion', 'enviar_correo');
    datos.append('ticket_id', id);
    datos.append('asunto', asunto);
    datos.append('mensaje', mensaje);
    boton.disabled = true;
    estado.className = 'small text-muted';
    estado.innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i>Enviando...';
    fetch('procesar_soporte.php', { method: 'POST', body: datos })
        .then((r) => r.json())
        .then((d) => {
            boton.disabled = false;
            if (!casoActual || casoActual.id !== id) return;
            estado.className = 'small ' + (d.ok ? 'text-success' : 'text-danger');
            estado.innerText = d.ok ? 'Correo enviado. Quedó registrado en el historial del caso.' : (d.error || 'No fue posible enviar el correo.');
            if (d.ok) document.getElementById('correo_mensaje').value = '';
            if (d.correos) pintarCorreos(d.correos);
            cargarFlujo(id);
            refrescarSla();
        })
        .catch(() => { boton.disabled = false; estado.className = 'small text-danger'; estado.innerText = 'No fue posible enviar el correo.'; });
});

document.getElementById('btnCopiarCodigo').addEventListener('click', (e) => {
    const boton = e.currentTarget;
    if (!casoActual || !navigator.clipboard) return;
    navigator.clipboard.writeText(casoActual.codigo).then(() => {
        boton.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(() => { boton.innerHTML = '<i class="far fa-copy"></i>'; }, 1500);
    }).catch(() => {});
});

document.getElementById('modalASM').addEventListener('hidden.bs.modal', () => {
    clearInterval(relojANS);
    clearInterval(refrescoANS);
    casoActual = null;
});

// ------------------------------------------------------------------ filtros, búsqueda y exportación
let filtroActivo = 'todos';
const CRITERIOS = {
    todos: () => true,
    mios: (f) => Number(f.dataset.tecnico) === MI_ID,
    sin_asignar: (f) => f.dataset.tecnico === '0',
    sin_respuesta: (f) => f.dataset.sinRespuesta === '1',
    riesgo: (f) => f.dataset.nivel === 'riesgo' || f.dataset.nivel === 'critico',
    vencidos: (f) => f.dataset.nivel === 'vencido',
    pausa: (f) => f.dataset.comp === 'Pausa',
    estancados: (f) => f.dataset.estancado === '1',
    novedades: (f) => f.dataset.novedad === '1',
};

function aplicarFiltros() {
    const q = document.getElementById('buscadorTickets').value.toLowerCase().trim();
    const prioridad = document.getElementById('filtro_prioridad').value;
    const grupo = document.getElementById('filtro_grupo').value;
    const coincidencias = {};
    document.querySelectorAll('.tabla-busqueda').forEach((tabla) => {
        let n = 0;
        tabla.querySelectorAll('tbody tr[data-caso]').forEach((fila) => {
            const visible = (!q || fila.innerText.toLowerCase().includes(q))
                && (tabla.id !== 'tabla_pendientes' || CRITERIOS[filtroActivo](fila))
                && (!prioridad || fila.dataset.prioridad === prioridad)
                && (!grupo || fila.dataset.grupo === grupo);
            fila.style.display = visible ? '' : 'none';
            if (visible) n++;
        });
        coincidencias[tabla.id] = n;
    });
    if (q !== '') {
        const pestanas = [['btn_tab_abiertos', 'tabla_pendientes'], ['btn_tab_solucionados', 'tabla_solucionados'], ['btn_tab_cerrados', 'tabla_historico']];
        const activa = pestanas.find(([boton]) => document.getElementById(boton).classList.contains('active'));
        if (activa && !coincidencias[activa[1]]) {
            const otra = pestanas.find(([, tabla]) => coincidencias[tabla] > 0);
            if (otra) new bootstrap.Tab(document.getElementById(otra[0])).show();
        }
    }
}

document.getElementById('buscadorTickets').addEventListener('input', aplicarFiltros);
document.getElementById('filtro_prioridad').addEventListener('change', aplicarFiltros);
document.getElementById('filtro_grupo').addEventListener('change', aplicarFiltros);
document.querySelectorAll('.kpi-mesa').forEach((boton) => boton.addEventListener('click', () => {
    filtroActivo = boton.dataset.filtro;
    document.querySelectorAll('.kpi-mesa').forEach((b) => b.classList.toggle('activo', b === boton));
    new bootstrap.Tab(document.getElementById('btn_tab_abiertos')).show();
    aplicarFiltros();
}));

function exportarExcel() {
    const pestana = document.querySelector('#tabsITSM .nav-link.active').getAttribute('href');
    const tabla = document.getElementById({ '#pendientes': 'tabla_pendientes', '#solucionados': 'tabla_solucionados', '#cerrados': 'tabla_historico' }[pestana]);
    if (!tabla) return;
    const csv = [];
    tabla.querySelectorAll('tr').forEach((fila) => {
        if (fila.closest('tbody') && (!fila.dataset.caso || fila.style.display === 'none')) return;
        const celdas = Array.from(fila.querySelectorAll('td, th')).slice(0, -1);
        if (celdas.length) csv.push(celdas.map((c) => '"' + c.innerText.replace(/\s+/g, ' ').replace(/"/g, '""').trim() + '"').join(','));
    });
    const enlace = document.createElement('a');
    enlace.download = { '#pendientes': 'Casos_En_Gestion_DataSense.csv', '#solucionados': 'Casos_Por_Confirmar_DataSense.csv', '#cerrados': 'Casos_Cerrados_DataSense.csv' }[pestana];
    enlace.href = URL.createObjectURL(new Blob(['﻿' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' }));
    enlace.style.display = 'none';
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
}

// ------------------------------------------------------------------ registro en nombre del cliente
function previsualizarRegistro() {
    const sel = document.getElementById('reg_servicio');
    const opcion = sel.options[sel.selectedIndex];
    const tipo = opcion ? opcion.dataset.tipo : '';
    const imp = document.getElementById('reg_impacto').value, urg = document.getElementById('reg_urgencia').value;
    const caja = document.getElementById('reg_preview');
    if (!tipo || !MATRIZ[imp] || !MATRIZ[imp][urg]) { caja.hidden = true; return; }
    const prio = MATRIZ[imp][urg];
    const [resp, sol, cal] = OBJETIVOS[tipo][prio];
    caja.innerHTML = `<i class="fas fa-stopwatch me-1"></i> Prioridad <b>${prio}</b> · primera respuesta en ${duracion(resp)} · solución en ${duracion(sol)} (${cal === '24x7' ? '24x7' : 'horario hábil'}).`;
    caja.hidden = false;
}

if (ABRIR) {
    const caso = Object.values(CASOS).find((c) => c.codigo === ABRIR);
    if (caso) abrirCaso(caso.id);
}
</script>
</body>
</html>
