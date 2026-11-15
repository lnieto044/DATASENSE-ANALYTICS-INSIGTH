<?php
/**
 * SUPERVISIÓN Y CARGAS - MESA DE SERVICIOS
 * Torre de control del líder de la mesa: indicadores en vivo, carga ponderada por especialista, casos sin asignar,
 * en riesgo o vencidos, estancados y por confirmar, con reasignación inmediata; y configuración del ANS (Administrador).
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/itsm.php';
$p = 'supervisor_it.php';

$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'supervisor_it');
$es_admin = $usuario['rol'] === 'Admin';
$vista = in_array($_GET['vista'] ?? '', ['configuracion', 'especialistas'], true) ? $_GET['vista'] : 'tablero';

$titulo = 'Supervisión y Cargas';
$subtitulo = 'Torre de control de la mesa de servicios: cargas, riesgos de ANS y escalamientos';
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([(int) $usuario['id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

sincronizarTickets($db);
$cfg = configItsm($db);
$objetivos = objetivosSla($db);
$desde_30 = date('Y-m-d H:i:s', strtotime('-30 days'));
$tickets = ticketsItsm($db, '(t.fecha_cierre IS NULL OR t.fecha_cierre >= ?)', [$desde_30]);
$resumen = resumenMesa($tickets, $cfg);
$carga = cargaEspecialistas($db, $tickets, 30);
$especialistas = especialistasItsm($db);
$cargas_actuales = cargasEspecialistas($db);
$grupos_catalogo = array_column($db->query("SELECT DISTINCT grupo_resolutor FROM catalogo_servicios WHERE estado = 1 AND grupo_resolutor IS NOT NULL AND grupo_resolutor <> '' ORDER BY grupo_resolutor")->fetchAll(PDO::FETCH_ASSOC), 'grupo_resolutor');

// Simulador del calendario hábil: cuándo vence un objetivo radicado en una fecha y hora dadas
$prueba = null;
$desde_prueba = str_replace('T', ' ', is_string($_GET['probar_desde'] ?? null) ? $_GET['probar_desde'] : '');
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $desde_prueba)) {
    $minutos_prueba = max(1, min(100000, (int) ($_GET['probar_minutos'] ?? 240)));
    $vence_prueba = sumarMinutosHabiles("$desde_prueba:00", $minutos_prueba, 'habil', $cfg);
    $prueba = ['desde' => "$desde_prueba:00", 'minutos' => $minutos_prueba, 'inicio' => proximoInicioHabil("$desde_prueba:00", $cfg)->format('Y-m-d H:i:s'),
               'vence' => $vence_prueba->format('Y-m-d H:i:s'), 'no_habiles' => diasNoHabilesEntre($desde_prueba, $vence_prueba, $cfg)];
}
$diaHora = function ($valor) {
    $dias = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    return $dias[(int) date('N', strtotime($valor))] . ' ' . date('d/m/Y H:i', strtotime($valor));
};

$tecnicos = array_values(array_filter(
    $db->query("SELECT id, nombre, rol FROM usuarios WHERE estado_cuenta = 'Activo' AND rol <> 'Cliente' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC),
    function ($u) use ($db) { return tieneAcceso($u['rol'], 'gestion_it', $db); }
));
// Sugerencia de asignación: el especialista activo con menor carga ponderada
$ids_tecnicos = array_map('intval', array_column($tecnicos, 'id'));
$sugerido = 0;
$menor = PHP_INT_MAX;
foreach ($carga as $c) {
    if (in_array($c['id'], $ids_tecnicos, true) && !empty($especialistas[$c['id']]['recibe_casos']) && $c['carga'] < $menor) { $menor = $c['carga']; $sugerido = $c['id']; }
}

$abiertos = array_values(array_filter($tickets, function ($t) { return !in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true); }));
$sin_asignar = array_values(array_filter($abiertos, function ($t) { return empty($t['tecnico_id']); }));
$en_riesgo = array_values(array_filter($abiertos, function ($t) { return $t['sla'] && in_array($t['sla']['resolucion']['nivel'], ['riesgo', 'critico', 'vencido'], true); }));
usort($en_riesgo, function ($a, $b) { return $b['sla']['resolucion']['pct'] <=> $a['sla']['resolucion']['pct']; });
$estancados = array_values(array_filter($abiertos, function ($t) use ($cfg) { return esEstancado($t, $cfg); }));
usort($estancados, function ($a, $b) { return strcmp((string) ($a['ultima_gestion_fecha'] ?: $a['created_at']), (string) ($b['ultima_gestion_fecha'] ?: $b['created_at'])); });
$por_confirmar = array_values(array_filter($tickets, function ($t) { return $t['comportamiento'] === COMP_SOLUCIONADO; }));

$resueltos_30 = array_filter($tickets, function ($t) use ($desde_30) { return !empty($t['fecha_resolucion']) && $t['fecha_resolucion'] >= $desde_30 && $t['sla_resolucion_cumplido'] !== null; });
$cumplidos_30 = count(array_filter($resueltos_30, function ($t) { return (int) $t['sla_resolucion_cumplido'] === 1; }));
$cumplimiento_30 = $resueltos_30 ? round($cumplidos_30 * 100 / count($resueltos_30), 1) : null;
$calificados = array_filter($tickets, function ($t) use ($desde_30) { return (int) $t['calificacion_estrellas'] > 0 && ($t['fecha_cierre'] ?: $t['fecha_resolucion']) >= $desde_30; });
$csat_30 = $calificados ? round(array_sum(array_map(function ($t) { return (int) $t['calificacion_estrellas']; }, $calificados)) / count($calificados), 2) : null;

$por_grupo = [];
foreach ($abiertos as $t) {
    $g = $t['grupo_resolutor'] ?: 'Sin grupo';
    if (!isset($por_grupo[$g])) $por_grupo[$g] = ['abiertos' => 0, 'vencidos' => 0, 'sin_asignar' => 0, 'prioridades' => array_fill_keys(PRIORIDADES_TICKET, 0)];
    $por_grupo[$g]['abiertos']++;
    if ($t['sla'] && $t['sla']['resolucion']['nivel'] === 'vencido') $por_grupo[$g]['vencidos']++;
    if (empty($t['tecnico_id'])) $por_grupo[$g]['sin_asignar']++;
    if (isset($por_grupo[$g]['prioridades'][$t['prioridad']])) $por_grupo[$g]['prioridades'][$t['prioridad']]++;
}
uasort($por_grupo, function ($a, $b) { return $b['abiertos'] <=> $a['abiertos']; });

$max_carga = max(1, max(array_merge([0], array_column($carga, 'carga'))));
$promedio_carga = $carga ? array_sum(array_column($carga, 'carga')) / count($carga) : 0;
$fecha = function ($valor, $formato = 'd/m/y H:i') { return $valor ? date($formato, strtotime($valor)) : '—'; };
$meta = (float) $cfg['meta_cumplimiento'];
$colorMeta = function ($valor) use ($meta) { return $valor === null ? '#94a3b8' : ($valor >= $meta ? '#059669' : ($valor >= $meta - 10 ? '#d97706' : '#dc2626')); };
$clasesPrioridad = ['Crítica' => 'bg-danger', 'Alta' => 'bg-orange', 'Media' => 'bg-warning text-dark', 'Baja' => 'bg-success'];

$formReasignar = function (array $t) use ($tecnicos, $sugerido) {
    $actual = (int) $t['tecnico_id'] ?: $sugerido;
    $html = '<form method="POST" action="procesar_soporte.php" class="d-flex gap-1 form-reasignar">'
          . '<input type="hidden" name="accion" value="reasignar"><input type="hidden" name="volver" value="supervisor"><input type="hidden" name="ticket_id" value="' . (int) $t['id'] . '">'
          . '<select name="tecnico_id" class="form-select form-select-sm" required aria-label="Especialista para ' . esc($t['codigo_ticket']) . '">';
    foreach ($tecnicos as $tec) {
        $html .= '<option value="' . (int) $tec['id'] . '"' . ((int) $tec['id'] === $actual ? ' selected' : '') . '>' . esc($tec['nombre']) . '</option>';
    }
    return $html . '</select><button class="btn btn-sm btn-primary text-nowrap" type="submit" title="' . ($t['tecnico_id'] ? 'Reasignar' : 'Asignar') . '"><i class="fas fa-user-check"></i></button></form>';
};
$enlaceCaso = function (array $t) {
    return '<a class="fw-bold text-decoration-none text-nowrap" href="gestion_it.php?abrir=' . urlencode($t['codigo_ticket']) . '">' . esc($t['codigo_ticket']) . '</a>';
};
$barraSla = function (array $t) {
    if (!$t['sla']) return '—';
    $s = $t['sla']['resolucion'];
    $color = COLORES_NIVEL_SLA[$s['nivel']];
    return '<div class="small fw-bold" style="color:' . $color . '">' . number_format($s['pct'], 0) . '% · ' . esc($s['etiqueta']) . '</div>'
         . '<div class="progress" style="height:5px"><div class="progress-bar" style="width:' . min(100, $s['pct']) . '%;background:' . $color . '"></div></div>'
         . '<div class="text-muted" style="font-size:.7rem">' . ($t['sla']['reloj'] === 'detenido' ? 'En pausa' : ($s['vence'] ? 'Vence ' . date('d/m H:i', strtotime($s['vence'])) : '')) . '</div>';
};

$proximos_festivos = [];
foreach ([(int) date('Y'), (int) date('Y') + 1] as $anio) {
    foreach (festivosColombia($anio) as $dia => $nombre) {
        if ($dia >= date('Y-m-d') && count($proximos_festivos) < 8) $proximos_festivos[$dia] = $nombre;
    }
}
$mensajes_error = [
    'horario' => 'La hora final de la jornada debe ser posterior a la inicial (formato HH:MM).', 'almuerzo' => 'El almuerzo debe estar dentro de la jornada, o dejar ambos campos vacíos.',
    'dias' => 'Selecciona al menos un día hábil.', 'umbrales' => 'Los umbrales deben ser crecientes: atención < riesgo < crítico.',
    'objetivos' => 'Cada objetivo debe ser un número de minutos mayor que cero y la solución no puede ser menor que la primera respuesta.', 'reasignar' => 'No fue posible reasignar el caso.',
    'especialistas' => 'Revisa el nivel (1 a 3) y la capacidad (1 a 200) de cada especialista.',
];
$kpis = [
    ['En gestión', $resumen['abiertos'], 'fa-inbox', '#0A2E4F', 'Casos abiertos, en proceso o en pausa'],
    ['Sin asignar', $resumen['sin_asignar'], 'fa-user-clock', '#7c3aed', 'Esperan especialista'],
    ['Sin 1ª respuesta', $resumen['sin_respuesta'], 'fa-reply', '#0891b2', 'Aún no atendidos'],
    ['En riesgo', $resumen['en_riesgo'], 'fa-exclamation-triangle', COLORES_NIVEL_SLA['riesgo'], "≥ {$cfg['umbral_riesgo']}% del ANS consumido"],
    ['Vencidos', $resumen['vencidos'], 'fa-fire', COLORES_NIVEL_SLA['vencido'], 'Superaron el ANS de solución'],
    ['Estancados', $resumen['estancados'], 'fa-hourglass-end', '#64748b', "Sin gestión en {$cfg['horas_sin_gestion']} h hábiles"],
    ['Solucionados sin confirmar', $resumen['por_confirmar'], 'fa-user-check', '#0d9488', 'El cliente confirma o se cierran solos'],
    ['Cumplimiento 30 días', $cumplimiento_30 === null ? '—' : number_format($cumplimiento_30, 1, ',', '.') . '%', 'fa-bullseye', $colorMeta($cumplimiento_30), "Meta {$cfg['meta_cumplimiento']}% · " . count($resueltos_30) . ' resueltos'],
];
$nombres_dias = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
$dias_activos = array_map('intval', explode(',', $cfg['dias_habiles']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supervisión y Cargas | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .panel-sup { background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(15,23,42,.05); padding: 18px; height: 100%; }
        .panel-sup h6 { font-weight: 800; color: #0A2E4F; text-transform: uppercase; font-size: .8rem; letter-spacing: .04em; }
        .kpi-sup { background: #fff; border-radius: 12px; padding: 14px; border-left: 4px solid; box-shadow: 0 4px 14px rgba(15,23,42,.05); height: 100%; }
        .kpi-sup .valor { font-size: 1.5rem; font-weight: 800; line-height: 1.1; color: #0f172a; }
        .kpi-sup .etiqueta { font-size: .68rem; font-weight: 800; text-transform: uppercase; color: #64748b; }
        .kpi-sup .ayuda { font-size: .7rem; color: #94a3b8; }
        .tabla-sup th { font-size: .68rem; text-transform: uppercase; color: #64748b; white-space: nowrap; background: #f8fafc; }
        .tabla-sup td { font-size: .84rem; vertical-align: middle; }
        .barra-carga { height: 8px; border-radius: 4px; background: #e2e8f0; overflow: hidden; min-width: 90px; }
        .barra-carga span { display: block; height: 100%; }
        .chip-prio { display: inline-block; min-width: 22px; text-align: center; border-radius: 6px; font-size: .7rem; font-weight: 800; padding: 1px 5px; margin-right: 2px; }
        .bg-orange { background-color: #ea580c !important; color: #fff !important; }
        .form-reasignar select { min-width: 150px; }
        .nav-vista .nav-link { font-weight: 700; color: #64748b; }
        .nav-vista .nav-link.active { color: #0A2E4F; border-bottom: 3px solid #f26522; }
        .objetivo-ayuda { font-size: .68rem; color: #64748b; }
        #formConfiguracion .panel-sup { height: auto; }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="container-fluid py-3 px-4">

            <ul class="nav nav-vista border-bottom mb-3">
                <li class="nav-item"><a class="nav-link <?= $vista === 'tablero' ? 'active' : '' ?>" href="supervisor_it.php" id="vista_tablero"><i class="fas fa-satellite-dish me-1"></i> Torre de control</a></li>
                <li class="nav-item"><a class="nav-link <?= $vista === 'configuracion' ? 'active' : '' ?>" href="supervisor_it.php?vista=configuracion" id="vista_configuracion"><i class="fas fa-sliders-h me-1"></i> ANS y horario</a></li>
                <li class="nav-item"><a class="nav-link <?= $vista === 'especialistas' ? 'active' : '' ?>" href="supervisor_it.php?vista=especialistas" id="vista_especialistas"><i class="fas fa-user-cog me-1"></i> Especialistas y asignación</a></li>
                <li class="nav-item ms-auto"><a class="nav-link" href="reporte_ejecutivo.php"><i class="fas fa-medal me-1"></i> Reporte Ejecutivo TIC</a></li>
            </ul>

            <?php if (($_GET['res'] ?? '') === 'reasignado'): ?>
                <div class="alert alert-success py-2"><i class="fas fa-check-circle me-2"></i> Caso <strong><?= esc($_GET['caso'] ?? '') ?></strong> asignado; el especialista fue notificado.</div>
            <?php elseif (($_GET['res'] ?? '') === 'configuracion'): ?>
                <div class="alert alert-success py-2" id="aviso_configuracion"><i class="fas fa-check-circle me-2"></i> Configuración del ANS guardada. Aplica a los casos nuevos y a los que se reclasifiquen.</div>
            <?php elseif (($_GET['res'] ?? '') === 'especialistas'): ?>
                <div class="alert alert-success py-2" id="aviso_especialistas"><i class="fas fa-check-circle me-2"></i> Especialistas guardados. Los casos nuevos y los que queden sin especialista se asignan con estas reglas.</div>
            <?php endif; ?>
            <?php if (isset($_GET['err'])): ?>
                <div class="alert alert-danger py-2"><i class="fas fa-exclamation-circle me-2"></i> <?= esc($mensajes_error[$_GET['err']] ?? 'Revisa los valores: hay un dato fuera del rango permitido.') ?></div>
            <?php endif; ?>

            <?php if ($vista === 'tablero'): ?>
            <div class="row g-3 mb-3">
                <?php foreach ($kpis as $k): ?>
                <div class="col-6 col-md-3">
                    <div class="kpi-sup" style="border-left-color: <?= $k[3] ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><div class="etiqueta"><?= $k[0] ?></div><div class="valor"><?= esc((string) $k[1]) ?></div></div>
                            <i class="fas <?= $k[2] ?> fa-lg" style="color: <?= $k[3] ?>"></i>
                        </div>
                        <div class="ayuda"><?= esc($k[4]) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="panel-sup mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="fas fa-users-cog me-2"></i>Carga por especialista</h6>
                    <small class="text-muted">Carga ponderada: Crítica 4 · Alta 3 · Media 2 · Baja 1. Desempeño de los últimos 30 días<?= $csat_30 !== null ? ' · CSAT de la mesa ' . number_format($csat_30, 2, ',', '.') . '/5' : '' ?>.</small>
                </div>
                <div class="table-responsive">
                    <table class="table tabla-sup mb-0" id="tablaCarga">
                        <thead><tr><th>Especialista</th><th>Abiertos</th><th>Por prioridad</th><th>Carga ponderada</th><th>Pausa · Riesgo · Vencidos</th><th>Resueltos 30 d</th><th>Cumplimiento</th><th>MTTR</th><th>CSAT</th></tr></thead>
                        <tbody>
                        <?php foreach ($carga as $c):
                            $nivel_carga = $c['carga'] > $promedio_carga * 1.5 && $c['carga'] > 0 ? '#dc2626' : ($c['carga'] > $promedio_carga ? '#d97706' : '#059669'); ?>
                            <tr data-especialista="<?= (int) $c['id'] ?>">
                                <td class="fw-bold text-nowrap"><?= esc($c['nombre']) ?> <?php if ($c['id'] === $sugerido): ?><span class="badge bg-success-subtle text-success border border-success" title="Menor carga: sugerido para nuevas asignaciones">Sugerido</span><?php endif; ?><div class="small text-muted fw-normal"><?php $esp = $especialistas[$c['id']] ?? null; ?><?= $esp ? esc(substr(NIVELES_ESPECIALISTA[$esp['nivel']], 0, 2)) . ' · ' . esc($esp['grupos'] ? implode(', ', $esp['grupos']) : 'Generalista') . ($esp['recibe_casos'] ? '' : ' · no recibe casos automáticos') : esc($c['rol']) ?></div></td>
                                <td class="fw-bold"><?= $c['abiertos'] ?></td>
                                <td class="text-nowrap"><?php foreach ($c['prioridades'] as $prio => $n): ?><span class="chip-prio <?= $n ? $clasesPrioridad[$prio] : 'bg-light text-muted' ?>" title="<?= esc($prio) ?>"><?= $n ?></span><?php endforeach; ?></td>
                                <td><div class="d-flex align-items-center gap-2"><div class="barra-carga flex-grow-1"><span style="width: <?= round($c['carga'] * 100 / $max_carga) ?>%; background: <?= $nivel_carga ?>"></span></div><b><?= $c['carga'] ?></b></div></td>
                                <td class="text-nowrap">
                                    <span class="chip-prio <?= $c['en_pausa'] ? 'bg-warning text-dark' : 'bg-light text-muted' ?>" title="En pausa"><i class="fas fa-pause me-1"></i><?= $c['en_pausa'] ?></span>
                                    <span class="chip-prio <?= $c['en_riesgo'] ? 'bg-orange' : 'bg-light text-muted' ?>" title="En riesgo"><i class="fas fa-exclamation-triangle me-1"></i><?= $c['en_riesgo'] ?></span>
                                    <span class="chip-prio <?= $c['vencidos'] ? 'bg-danger' : 'bg-light text-muted' ?>" title="Vencidos"><i class="fas fa-fire me-1"></i><?= $c['vencidos'] ?></span>
                                </td>
                                <td><?= $c['resueltos'] ?></td>
                                <td class="fw-bold" style="color: <?= $colorMeta($c['cumplimiento']) ?>"><?= $c['cumplimiento'] === null ? '—' : number_format($c['cumplimiento'], 1, ',', '.') . '%' ?></td>
                                <td class="text-nowrap"><?= $c['mttr'] === null ? '—' : duracionTexto($c['mttr']) ?></td>
                                <td class="text-warning text-nowrap"><?= $c['csat'] === null ? '<span class="text-muted">—</span>' : '★ ' . number_format($c['csat'], 1, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$carga): ?><tr><td colspan="9" class="text-center text-muted py-4">No hay especialistas activos.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="panel-sup">
                        <h6><i class="fas fa-user-clock me-2 text-purple"></i>Sin asignar <span class="badge bg-secondary"><?= count($sin_asignar) ?></span></h6>
                        <p class="small text-muted mb-2"><?= $cfg['asignacion_automatica'] === '1'
                            ? '<i class="fas fa-magic me-1 text-success"></i>La asignación automática reparte cada caso al radicarse, según especialidad, complejidad y carga. Aquí solo quedan casos si ningún especialista recibe casos de ese tipo.'
                            : '<i class="fas fa-hand-paper me-1 text-warning"></i>La asignación automática está desactivada: asigna estos casos manualmente o actívala en "Especialistas y asignación".' ?></p>
                        <div class="table-responsive">
                            <table class="table tabla-sup mb-0" id="tablaSinAsignar">
                                <thead><tr><th>Caso</th><th>Prioridad</th><th>Grupo</th><th>ANS solución</th><th>Asignar a</th></tr></thead>
                                <tbody>
                                <?php foreach ($sin_asignar as $t): ?>
                                    <tr data-caso="<?= esc($t['codigo_ticket']) ?>">
                                        <td><?= $enlaceCaso($t) ?><div class="small text-muted text-truncate" style="max-width:180px"><?= esc($t['asunto']) ?></div></td>
                                        <td><span class="badge <?= $clasesPrioridad[$t['prioridad']] ?? 'bg-secondary' ?>"><?= esc($t['prioridad']) ?></span></td>
                                        <td class="small"><?= esc($t['grupo_resolutor']) ?></td>
                                        <td style="min-width:120px"><?= $barraSla($t) ?></td>
                                        <td><?= $formReasignar($t) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$sin_asignar): ?><tr><td colspan="5" class="text-center text-success py-3"><i class="fas fa-check-circle me-1"></i> Todos los casos tienen especialista.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="panel-sup">
                        <h6><i class="fas fa-fire me-2 text-danger"></i>En riesgo o vencidos <span class="badge bg-danger"><?= count($en_riesgo) ?></span></h6>
                        <div class="table-responsive">
                            <table class="table tabla-sup mb-0" id="tablaRiesgo">
                                <thead><tr><th>Caso</th><th>Prioridad</th><th>Especialista</th><th>ANS solución</th><th>Escalar a</th></tr></thead>
                                <tbody>
                                <?php foreach ($en_riesgo as $t): ?>
                                    <tr data-caso="<?= esc($t['codigo_ticket']) ?>">
                                        <td><?= $enlaceCaso($t) ?><div class="small text-muted text-truncate" style="max-width:180px"><?= esc($t['asunto']) ?></div></td>
                                        <td><span class="badge <?= $clasesPrioridad[$t['prioridad']] ?? 'bg-secondary' ?>"><?= esc($t['prioridad']) ?></span></td>
                                        <td class="small fw-bold <?= $t['nom_tecnico'] ? '' : 'text-danger' ?>"><?= esc($t['nom_tecnico'] ?: 'Sin asignar') ?></td>
                                        <td style="min-width:120px"><?= $barraSla($t) ?></td>
                                        <td><?= $formReasignar($t) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$en_riesgo): ?><tr><td colspan="5" class="text-center text-success py-3"><i class="fas fa-check-circle me-1"></i> Ningún caso en riesgo de incumplir el ANS.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-xl-4">
                    <div class="panel-sup">
                        <h6><i class="fas fa-hourglass-end me-2 text-secondary"></i>Estancados <span class="badge bg-secondary"><?= count($estancados) ?></span></h6>
                        <p class="small text-muted mb-2">Sin gestión del especialista en <?= (int) $cfg['horas_sin_gestion'] ?> horas hábiles o más.</p>
                        <ul class="list-group list-group-flush" id="listaEstancados">
                            <?php foreach (array_slice($estancados, 0, 8) as $t): ?>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    <span><?= $enlaceCaso($t) ?> <small class="text-muted"><?= esc($t['nom_tecnico'] ?: 'Sin asignar') ?></small></span>
                                    <small class="text-muted text-end">Última gestión<br><?= $fecha($t['ultima_gestion_fecha'] ?: $t['created_at']) ?></small>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$estancados): ?><li class="list-group-item px-0 text-success small"><i class="fas fa-check-circle me-1"></i> Todos los casos tienen gestión reciente.</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="panel-sup">
                        <h6><i class="fas fa-user-check me-2 text-success"></i>Solucionados sin confirmar <span class="badge bg-success"><?= count($por_confirmar) ?></span></h6>
                        <p class="small text-muted mb-2">Solucionados esperando al cliente; se cierran solos a los <?= (int) $cfg['cierre_automatico_dias'] ?> días hábiles.</p>
                        <ul class="list-group list-group-flush" id="listaPorConfirmar">
                            <?php foreach (array_slice($por_confirmar, 0, 8) as $t): ?>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    <span><?= $enlaceCaso($t) ?> <small class="text-muted"><?= esc($t['cliente']) ?></small></span>
                                    <small class="text-muted text-end">Cierre automático<br><?= $fecha(fechaCierreAutomatico($t, $cfg)) ?></small>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$por_confirmar): ?><li class="list-group-item px-0 text-muted small">No hay casos esperando confirmación.</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="panel-sup">
                        <h6><i class="fas fa-sitemap me-2"></i>Backlog por grupo resolutor</h6>
                        <div class="table-responsive">
                        <table class="table tabla-sup mb-0" id="tablaGrupos">
                            <thead><tr><th>Grupo</th><th class="text-center">Abiertos</th><th class="text-center">Sin asig.</th><th class="text-center">Vencidos</th></tr></thead>
                            <tbody>
                            <?php foreach ($por_grupo as $grupo => $g): ?>
                                <tr><td class="small fw-bold"><?= esc($grupo) ?></td><td class="text-center"><?= $g['abiertos'] ?></td><td class="text-center"><?= $g['sin_asignar'] ?></td><td class="text-center <?= $g['vencidos'] ? 'text-danger fw-bold' : '' ?>"><?= $g['vencidos'] ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$por_grupo): ?><tr><td colspan="4" class="text-center text-muted small">Sin casos abiertos.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($vista === 'especialistas'): ?>
            <form method="POST" action="procesar_soporte.php" id="formEspecialistas">
                <input type="hidden" name="accion" value="guardar_especialistas">
                <?php if (!$es_admin): ?><div class="alert alert-info py-2"><i class="fas fa-lock me-2"></i> Solo el Administrador puede cambiar la configuración de los especialistas. Vista de consulta.</div><?php endif; ?>
                <fieldset <?= $es_admin ? '' : 'disabled' ?>>
                <div class="panel-sup mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h6><i class="fas fa-magic me-2"></i>Asignación automática de casos</h6>
                            <ol class="small text-muted mb-0 ps-3">
                                <li><b>Especialidad:</b> el caso va a quienes atienden su grupo resolutor; si nadie lo atiende, a los generalistas.</li>
                                <li><b>Complejidad:</b> los casos de prioridad Crítica buscan un experto (N3) y los de prioridad Alta, un especialista (N2).</li>
                                <li><b>Carga:</b> entre ellos, el de menor carga ponderada respecto a su capacidad (Crítica 4 · Alta 3 · Media 2 · Baja 1; en pausa cuenta la mitad).</li>
                                <li>Se asigna al radicar, cuando un caso queda sin especialista y cuando su especialista deja de estar activo. El especialista recibe un correo.</li>
                            </ol>
                        </div>
                        <div class="form-check form-switch fs-6">
                            <input class="form-check-input" type="checkbox" role="switch" name="asignacion_automatica" id="asignacion_automatica" <?= $cfg['asignacion_automatica'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="asignacion_automatica">Asignación automática activa</label>
                        </div>
                    </div>
                </div>
                <div class="panel-sup mb-3">
                    <h6><i class="fas fa-user-cog me-2"></i>Especialistas de la mesa</h6>
                    <p class="small text-muted">Aparecen los usuarios activos con acceso a Gestión IT. Para agregar técnicos créalos en <a href="equipo.php">Mi Equipo</a> con el rol Soporte.</p>
                    <div class="table-responsive">
                        <table class="table tabla-sup align-middle mb-0" id="tablaEspecialistas">
                            <thead><tr><th>Especialista</th><th>Recibe casos</th><th>Nivel</th><th>Capacidad</th><th>Carga actual</th><th>Grupos que atiende (vacío = generalista)</th></tr></thead>
                            <tbody>
                            <?php foreach ($especialistas as $id => $e): $actual = $cargas_actuales[$id] ?? ['carga' => 0, 'abiertos' => 0]; ?>
                                <tr data-especialista="<?= (int) $id ?>">
                                    <td class="fw-bold text-nowrap"><?= esc($e['nombre']) ?><div class="small text-muted fw-normal"><?= esc($e['cargo'] ?: $e['rol']) ?></div></td>
                                    <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="recibe[<?= (int) $id ?>]" id="recibe_<?= (int) $id ?>" <?= $e['recibe_casos'] ? 'checked' : '' ?> aria-label="<?= esc($e['nombre']) ?> recibe casos"></div></td>
                                    <td><select name="nivel[<?= (int) $id ?>]" class="form-select form-select-sm" style="min-width:150px" aria-label="Nivel de <?= esc($e['nombre']) ?>">
                                        <?php foreach (NIVELES_ESPECIALISTA as $n => $nombre_nivel): ?><option value="<?= $n ?>" <?= $e['nivel'] === $n ? 'selected' : '' ?>><?= esc($nombre_nivel) ?></option><?php endforeach; ?>
                                    </select></td>
                                    <td><input type="number" min="1" max="200" name="capacidad[<?= (int) $id ?>]" value="<?= (int) $e['capacidad'] ?>" class="form-control form-control-sm" style="width:90px" aria-label="Capacidad de <?= esc($e['nombre']) ?>"></td>
                                    <td class="text-nowrap"><b><?= formatoCarga($actual['carga']) ?></b> de <?= (int) $e['capacidad'] ?><div class="small text-muted"><?= (int) $actual['abiertos'] ?> caso(s) abiertos</div></td>
                                    <td style="min-width:260px">
                                        <?php foreach ($grupos_catalogo as $g => $grupo): $id_check = 'grupo_' . (int) $id . '_' . $g; ?>
                                            <div class="form-check form-check-inline small me-2"><input class="form-check-input" type="checkbox" name="grupos[<?= (int) $id ?>][]" value="<?= esc($grupo) ?>" id="<?= $id_check ?>" <?= in_array($grupo, $e['grupos'], true) ? 'checked' : '' ?>><label class="form-check-label" for="<?= $id_check ?>"><?= esc($grupo) ?></label></div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$especialistas): ?><tr><td colspan="6" class="text-center text-muted py-4">No hay usuarios activos con acceso a Gestión IT.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($es_admin): ?>
                <div class="text-end mb-4"><button type="submit" class="btn btn-primary fw-bold px-5" id="btnGuardarEspecialistas" style="background:#0A2E4F"><i class="fas fa-save me-2"></i> Guardar especialistas</button></div>
                <?php endif; ?>
                </fieldset>
            </form>

            <?php else: ?>
            <div class="panel-sup mb-3" id="simuladorCalendario">
                <h6><i class="fas fa-calendar-check me-2"></i>Probar el calendario hábil</h6>
                <p class="small text-muted">Escribe cuándo se radicaría un caso y cuántos minutos da el ANS: la plataforma muestra desde cuándo cuenta, cuándo vence y qué días no cuentan (fines de semana y festivos de Colombia).</p>
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="vista" value="configuracion">
                    <div class="col-sm-5 col-lg-4"><label class="small fw-bold text-muted" for="probar_desde">Radicado el</label><input type="datetime-local" class="form-control" id="probar_desde" name="probar_desde" value="<?= esc($prueba ? date('Y-m-d\TH:i', strtotime($prueba['desde'])) : date('Y-m-d\TH:i')) ?>" required></div>
                    <div class="col-sm-4 col-lg-3"><label class="small fw-bold text-muted" for="probar_minutos">Objetivo (minutos)</label><input type="number" min="1" max="100000" class="form-control" id="probar_minutos" name="probar_minutos" value="<?= (int) ($prueba['minutos'] ?? 240) ?>" required></div>
                    <div class="col-sm-3 col-lg-2"><button type="submit" class="btn btn-outline-primary w-100 fw-bold" id="btnProbarCalendario">Calcular</button></div>
                </form>
                <?php if ($prueba): ?>
                <div class="alert alert-light border mt-3 mb-0 small" id="resultadoCalendario">
                    <div><b>Radicado:</b> <?= esc($diaHora($prueba['desde'])) ?></div>
                    <div><b>Empieza a contar:</b> <?= esc($diaHora($prueba['inicio'])) ?><?= $prueba['inicio'] > $prueba['desde'] ? ' (se radicó fuera del horario de atención)' : '' ?></div>
                    <div><b>Vence (<?= esc(duracionTexto($prueba['minutos'])) ?> hábiles):</b> <span data-vence="<?= esc($prueba['vence']) ?>"><?= esc($diaHora($prueba['vence'])) ?></span></div>
                    <?php if ($prueba['no_habiles']): ?>
                    <div class="mt-1"><b>No cuentan:</b> <?= esc(implode(' · ', array_map(function ($fecha, $motivo) { return date('d/m', strtotime($fecha)) . ' ' . $motivo; }, array_keys($prueba['no_habiles']), $prueba['no_habiles']))) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <form method="POST" action="procesar_soporte.php" id="formConfiguracion">
                <input type="hidden" name="accion" value="guardar_configuracion">
                <?php if (!$es_admin): ?><div class="alert alert-info py-2"><i class="fas fa-lock me-2"></i> Solo el Administrador puede modificar los acuerdos de nivel de servicio. Vista de consulta.</div><?php endif; ?>
                <fieldset <?= $es_admin ? '' : 'disabled' ?>>
                <div class="row g-3 mb-3">
                    <div class="col-xl-7">
                        <div class="panel-sup">
                            <h6><i class="fas fa-stopwatch me-2"></i>Objetivos del ANS por tipo y prioridad (minutos)</h6>
                            <p class="small text-muted">ITIL no fija tiempos: son los que la organización pacta con sus clientes. Los casos conservan los objetivos con los que se registraron, salvo que se reclasifiquen.</p>
                            <div class="table-responsive">
                                <table class="table tabla-sup align-middle" id="tablaObjetivos">
                                    <thead><tr><th>Tipo</th><th>Prioridad</th><th>1ª respuesta</th><th>Solución</th><th>Calendario</th></tr></thead>
                                    <tbody>
                                    <?php foreach (TIPOS_TICKET as $tipo => $nombre_tipo): foreach (PRIORIDADES_TICKET as $prio): list($resp, $sol, $cal) = $objetivos[$tipo][$prio]; ?>
                                        <tr>
                                            <td class="small fw-bold"><?= esc($nombre_tipo) ?></td>
                                            <td><span class="badge <?= $clasesPrioridad[$prio] ?>"><?= esc($prio) ?></span></td>
                                            <td><input type="number" min="1" max="100000" class="form-control form-control-sm minutos" name="respuesta[<?= $tipo ?>][<?= esc($prio) ?>]" value="<?= (int) $resp ?>" required aria-label="Primera respuesta <?= esc("$nombre_tipo $prio") ?>"><div class="objetivo-ayuda"><?= duracionTexto($resp) ?></div></td>
                                            <td><input type="number" min="1" max="100000" class="form-control form-control-sm minutos" name="resolucion[<?= $tipo ?>][<?= esc($prio) ?>]" value="<?= (int) $sol ?>" required aria-label="Solución <?= esc("$nombre_tipo $prio") ?>"><div class="objetivo-ayuda"><?= duracionTexto($sol) ?></div></td>
                                            <td><select class="form-select form-select-sm" name="calendario[<?= $tipo ?>][<?= esc($prio) ?>]" aria-label="Calendario <?= esc("$nombre_tipo $prio") ?>"><option value="habil" <?= $cal === 'habil' ? 'selected' : '' ?>>Hábil</option><option value="24x7" <?= $cal === '24x7' ? 'selected' : '' ?>>24x7</option></select></td>
                                        </tr>
                                    <?php endforeach; endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <h6 class="mt-2"><i class="fas fa-th me-2"></i>Matriz de prioridad (impacto × urgencia)</h6>
                            <table class="table table-bordered text-center small mb-0" style="max-width: 420px">
                                <thead><tr><th>Impacto \ Urgencia</th><th>Alta</th><th>Media</th><th>Baja</th></tr></thead>
                                <tbody>
                                <?php foreach (MATRIZ_PRIORIDAD as $impacto => $fila): ?>
                                    <tr><th><?= esc($impacto) ?></th><?php foreach ($fila as $prio): ?><td><span class="badge <?= $clasesPrioridad[$prio] ?>"><?= esc($prio) ?></span></td><?php endforeach; ?></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="panel-sup mb-3">
                            <h6><i class="fas fa-business-time me-2"></i>Calendario hábil</h6>
                            <div class="row g-2">
                                <div class="col-6"><label class="small fw-bold text-muted" for="hora_inicio">Inicio de jornada</label><input type="time" class="form-control" id="hora_inicio" name="hora_inicio" value="<?= esc($cfg['hora_inicio']) ?>" required></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="hora_fin">Fin de jornada</label><input type="time" class="form-control" id="hora_fin" name="hora_fin" value="<?= esc($cfg['hora_fin']) ?>" required></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="almuerzo_inicio">Almuerzo desde (opcional)</label><input type="time" class="form-control" id="almuerzo_inicio" name="almuerzo_inicio" value="<?= esc($cfg['almuerzo_inicio']) ?>"></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="almuerzo_fin">Almuerzo hasta</label><input type="time" class="form-control" id="almuerzo_fin" name="almuerzo_fin" value="<?= esc($cfg['almuerzo_fin']) ?>"></div>
                            </div>
                            <div class="mt-2 small fw-bold text-muted">Días hábiles</div>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php foreach ($nombres_dias as $n => $dia): ?>
                                    <div class="form-check form-check-inline me-1"><input class="form-check-input" type="checkbox" name="dias_habiles[]" value="<?= $n ?>" id="dia_<?= $n ?>" <?= in_array($n, $dias_activos, true) ? 'checked' : '' ?>><label class="form-check-label small" for="dia_<?= $n ?>"><?= $dia ?></label></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="festivos_colombia" id="festivos_colombia" <?= $cfg['festivos_colombia'] === '1' ? 'checked' : '' ?>><label class="form-check-label small fw-bold" for="festivos_colombia">Excluir festivos de Colombia (Ley Emiliani y Semana Santa)</label></div>
                            <div class="small text-muted mt-2">Jornada efectiva: <b><?= duracionTexto(minutosJornada($cfg)) ?></b> · <?= esc(textoHorarioItsm($cfg)) ?>.</div>
                            <details class="small mt-2"><summary class="fw-bold text-muted">Próximos festivos</summary>
                                <ul class="mb-0 mt-1"><?php foreach ($proximos_festivos as $dia => $nombre): ?><li><?= date('d/m/Y', strtotime($dia)) ?> · <?= esc($nombre) ?></li><?php endforeach; ?></ul>
                            </details>
                        </div>
                        <div class="panel-sup">
                            <h6><i class="fas fa-bell me-2"></i>Escalamiento y cierre</h6>
                            <div class="row g-2">
                                <div class="col-4"><label class="small fw-bold text-muted" for="umbral_atencion">Atención (%)</label><input type="number" min="1" max="99" class="form-control" id="umbral_atencion" name="umbral_atencion" value="<?= (int) $cfg['umbral_atencion'] ?>" required></div>
                                <div class="col-4"><label class="small fw-bold text-muted" for="umbral_riesgo">Riesgo (%)</label><input type="number" min="1" max="99" class="form-control" id="umbral_riesgo" name="umbral_riesgo" value="<?= (int) $cfg['umbral_riesgo'] ?>" required></div>
                                <div class="col-4"><label class="small fw-bold text-muted" for="umbral_critico">Crítico (%)</label><input type="number" min="1" max="100" class="form-control" id="umbral_critico" name="umbral_critico" value="<?= (int) $cfg['umbral_critico'] ?>" required></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="cierre_automatico_dias">Cierre automático (días hábiles)</label><input type="number" min="0" max="30" class="form-control" id="cierre_automatico_dias" name="cierre_automatico_dias" value="<?= (int) $cfg['cierre_automatico_dias'] ?>" required></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="reapertura_dias">Reapertura tras cierre (días hábiles)</label><input type="number" min="0" max="60" class="form-control" id="reapertura_dias" name="reapertura_dias" value="<?= (int) $cfg['reapertura_dias'] ?>" required></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="horas_sin_gestion">Estancado sin gestión (horas hábiles)</label><input type="number" min="1" max="240" class="form-control" id="horas_sin_gestion" name="horas_sin_gestion" value="<?= (int) $cfg['horas_sin_gestion'] ?>" required></div>
                                <div class="col-6"><label class="small fw-bold text-muted" for="meta_cumplimiento">Meta de cumplimiento (%)</label><input type="number" min="1" max="100" class="form-control" id="meta_cumplimiento" name="meta_cumplimiento" value="<?= (int) $cfg['meta_cumplimiento'] ?>" required></div>
                            </div>
                            <p class="small text-muted mt-2 mb-0">Al llegar a riesgo, crítico y vencido se envía una alerta por correo al especialista (una vez por nivel). El tiempo en pausa no cuenta.</p>
                        </div>
                    </div>
                </div>
                <?php if ($es_admin): ?>
                <div class="text-end mb-4"><button type="submit" class="btn btn-primary fw-bold px-5" id="btnGuardarConfiguracion" style="background:#0A2E4F"><i class="fas fa-save me-2"></i> Guardar configuración</button></div>
                <?php endif; ?>
                </fieldset>
            </form>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Muestra cada objetivo en horas y minutos mientras se edita
function duracion(min) {
    min = Math.max(0, Math.round(Number(min) || 0));
    const h = Math.floor(min / 60), m = min % 60;
    return h ? h + ' h' + (m ? ' ' + m + ' min' : '') : m + ' min';
}
document.querySelectorAll('input.minutos').forEach((campo) => campo.addEventListener('input', () => {
    campo.nextElementSibling.innerText = duracion(campo.value);
}));
</script>
</body>
</html>
