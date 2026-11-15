<?php
/**
 * REPORTE EJECUTIVO TIC - MESA DE SERVICIOS
 * Informe gerencial del periodo según las prácticas de ITIL (gestión de incidentes, solicitudes de servicio y niveles de servicio):
 * volumen, cumplimiento de ANS, MTTA y MTTR en tiempo hábil, reaperturas, satisfacción, desgloses, tendencia de 6 meses,
 * incumplimientos y conclusiones. Exportable a CSV e imprimible.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/reportes.php';
require_once __DIR__ . '/../includes/itsm.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'reporte_ejecutivo');

$get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };
$desde = fechaValida($get('desde')) ? $get('desde') : date('Y-m-01');
$hasta = fechaValida($get('hasta')) ? $get('hasta') : date('Y-m-d');
if ($hasta < $desde) list($desde, $hasta) = [$hasta, $desde];

sincronizarTickets($db);
$cfg = configItsm($db);
$objetivos = objetivosSla($db);
$r = reporteEjecutivoTic($db, $desde, $hasta);
$k = $r['kpi'];
$meta = (float) $cfg['meta_cumplimiento'];

$pct = function ($valor) { return $valor === null ? '—' : number_format($valor, 1, ',', '.') . '%'; };
$dur = function ($minutos) { return $minutos === null ? '—' : duracionTexto($minutos); };
$colorMeta = function ($valor) use ($meta) { return $valor === null ? '#94a3b8' : ($valor >= $meta ? '#059669' : ($valor >= $meta - 10 ? '#d97706' : '#dc2626')); };
$url = function (array $cambios) use ($desde, $hasta) { return 'reporte_ejecutivo.php?' . http_build_query(array_merge(['desde' => $desde, 'hasta' => $hasta], $cambios)); };
$meses = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
$nombreMes = function ($ym) use ($meses) { return $meses[substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4); };

if ($get('exportar') === 'csv') {
    $filas = [['Reporte Ejecutivo TIC - Mesa de Servicios', "Del $desde al $hasta"], [], ['Indicador', 'Valor']];
    foreach ([
        'Casos registrados' => $k['registrados'], 'Incidentes' => $k['incidentes'], 'Requerimientos' => $k['requerimientos'], 'Casos resueltos' => $k['resueltos'],
        'Casos cerrados' => $k['cerrados'], 'Backlog al corte' => $k['backlog'], 'Abiertos con ANS vencido (hoy)' => $k['vencidos_abiertos'],
        'Cumplimiento ANS primera respuesta (%)' => $k['cumplimiento_respuesta'], 'Cumplimiento ANS solución (%)' => $k['cumplimiento_solucion'], 'Meta de cumplimiento (%)' => $meta,
        'MTTA (minutos hábiles)' => $k['mtta'], 'MTTR (minutos hábiles, sin pausas)' => $k['mttr'], 'Tasa de reapertura (%)' => $k['tasa_reapertura'], 'CSAT (1 a 5)' => $k['csat'],
    ] as $indicador => $valor) {
        $filas[] = [$indicador, $valor === null ? '' : (is_float($valor) ? $valor : (string) $valor)];
    }
    foreach (['por_prioridad' => 'Prioridad', 'por_tipo' => 'Tipo', 'por_servicio' => 'Servicio', 'por_grupo' => 'Grupo resolutor', 'por_tecnico' => 'Especialista'] as $grupo => $titulo_grupo) {
        $filas[] = [];
        $filas[] = [$titulo_grupo, 'Registrados', 'Resueltos', 'Cumplimiento primera respuesta (%)', 'Cumplimiento solución (%)', 'MTTR (minutos hábiles)'];
        foreach ($r[$grupo] as $clave => $g) {
            $filas[] = [$grupo === 'por_tipo' ? TIPOS_TICKET[$clave] : $clave, (string) $g['registrados'], (string) $g['resueltos'],
                        $g['cumplimiento_respuesta'] === null ? '' : $g['cumplimiento_respuesta'], $g['cumplimiento_solucion'] === null ? '' : $g['cumplimiento_solucion'], $g['mttr'] === null ? '' : (string) $g['mttr']];
        }
    }
    $filas[] = [];
    $filas[] = ['Incumplimientos', 'Prioridad', 'Servicio', 'Especialista', 'Objetivo (min)', 'Tiempo neto (min)', 'Solucionado'];
    foreach ($r['incumplimientos'] as $i) $filas[] = [$i['codigo'], $i['prioridad'], $i['servicio'], $i['tecnico'], (string) $i['objetivo'], (string) $i['consumido'], $i['fecha']];
    $filas[] = [];
    $filas[] = ['Mes', 'Registrados', 'Resueltos', 'Cumplimiento solución (%)'];
    foreach ($r['tendencia'] as $m) $filas[] = [$m['mes'], (string) $m['registrados'], (string) $m['resueltos'], $m['cumplimiento'] === null ? '' : $m['cumplimiento']];
    $filas[] = [];
    $filas[] = ['Código de cierre', 'Casos resueltos'];
    foreach ($r['por_codigo_cierre'] as $codigo => $cantidad) $filas[] = [$codigo, (string) $cantidad];
    $filas[] = [];
    $filas[] = ['Conclusiones'];
    foreach ($r['conclusiones'] as $c) $filas[] = [$c];
    enviarCsv("reporte_ejecutivo_tic_{$desde}_{$hasta}.csv", $filas);
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = 'Reporte Ejecutivo TIC';
$subtitulo = 'Desempeño de la mesa de servicios frente a los ANS (ITIL)';
$rangos = [
    'Este mes' => [date('Y-m-01'), date('Y-m-d')],
    'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Últimos 90 días' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    'Año actual' => [date('Y-01-01'), date('Y-m-d')],
];
$clasesPrioridad = ['Crítica' => 'bg-danger', 'Alta' => 'bg-orange', 'Media' => 'bg-warning text-dark', 'Baja' => 'bg-success'];

// Tabla de desglose: registrados, resueltos, cumplimiento de respuesta y solución, MTTR
$tablaDesglose = function ($id, $titulo_tabla, array $grupos, $etiqueta, $formato_clave = null, $limite = 0) use ($pct, $dur, $colorMeta) {
    $html = '<h6 class="doc-subtitulo mt-4">' . $titulo_tabla . '</h6><div class="table-responsive"><table class="tabla-reporte" id="' . $id . '"><thead><tr><th>' . $etiqueta . '</th>'
          . '<th class="text-end">Registrados</th><th class="text-end">Resueltos</th><th class="text-end">Cumpl. 1ª respuesta</th><th class="text-end">Cumpl. solución</th><th class="text-end">MTTR</th></tr></thead><tbody>';
    $n = 0;
    foreach ($grupos as $clave => $g) {
        if ($limite && ++$n > $limite) break;
        if (!$g['registrados'] && !$g['resueltos'] && !$g['resp_medidos']) continue;
        $html .= '<tr><td>' . ($formato_clave ? $formato_clave($clave) : esc($clave)) . '</td><td class="text-end">' . $g['registrados'] . '</td><td class="text-end">' . $g['resueltos'] . '</td>'
               . '<td class="text-end fw-bold" style="color:' . $colorMeta($g['cumplimiento_respuesta']) . '">' . $pct($g['cumplimiento_respuesta']) . '</td>'
               . '<td class="text-end fw-bold" style="color:' . $colorMeta($g['cumplimiento_solucion']) . '">' . $pct($g['cumplimiento_solucion']) . '</td>'
               . '<td class="text-end text-nowrap">' . $dur($g['mttr']) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
};
$kpis = [
    ['Casos registrados', $k['registrados'], "{$k['incidentes']} incidentes · {$k['requerimientos']} requerimientos", '#0B4F8A'],
    ['Casos resueltos', $k['resueltos'], "{$k['cerrados']} cerrados en el periodo", '#3FAE49'],
    ['Backlog al corte', $k['backlog'], "{$k['vencidos_abiertos']} con ANS vencido hoy", $k['vencidos_abiertos'] ? '#dc2626' : '#64748b'],
    ['Cumplimiento ANS solución', $pct($k['cumplimiento_solucion']), "Meta {$pct($meta)}", $colorMeta($k['cumplimiento_solucion'])],
    ['Cumplimiento 1ª respuesta', $pct($k['cumplimiento_respuesta']), "{$k['resp_medidos']} casos medidos", $colorMeta($k['cumplimiento_respuesta'])],
    ['MTTA', $dur($k['mtta']), 'Tiempo medio a la 1ª respuesta', '#0891b2'],
    ['MTTR', $dur($k['mttr']), 'Tiempo medio de solución, sin pausas', '#7c3aed'],
    ['Satisfacción (CSAT)', $k['csat'] === null ? '—' : number_format($k['csat'], 2, ',', '.') . ' / 5', "{$k['csat_n']} calificación(es) · reapertura " . $pct($k['tasa_reapertura']), '#f59e0b'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Ejecutivo TIC | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/contabilidad.css">
    <style>
        .doc-subtitulo { font-weight: 800; color: #0A2E4F; text-transform: uppercase; font-size: .82rem; letter-spacing: .04em; }
        .kpi-reporte .valor { font-family: inherit; font-size: 1.35rem; }
        .conclusiones li { margin-bottom: .45rem; }
        .bg-orange { background-color: #ea580c !important; color: #fff !important; }
        .grafico-tendencia { position: relative; height: 260px; }
        @media print { .grafico-tendencia { height: 200px; } .kpi-reporte, .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="container-fluid py-4">

            <div class="panel-conta p-4 mb-4 no-print" style="border-top: 5px solid var(--azul-sonda);">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-6 col-lg-4">
                        <label class="small fw-bold text-muted text-uppercase mb-1" for="desde">Desde</label>
                        <input type="date" name="desde" id="desde" class="form-control" value="<?= esc($desde) ?>">
                    </div>
                    <div class="col-6 col-lg-4">
                        <label class="small fw-bold text-muted text-uppercase mb-1" for="hasta">Hasta</label>
                        <input type="date" name="hasta" id="hasta" class="form-control" value="<?= esc($hasta) ?>">
                    </div>
                    <div class="col-lg-4">
                        <button type="submit" class="btn btn-dark w-100 fw-bold" style="background: var(--azul-sonda);"><i class="fas fa-sync-alt me-2"></i> Generar</button>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                        <?php foreach ($rangos as $etiqueta => $rango): ?>
                            <a href="<?= esc($url(['desde' => $rango[0], 'hasta' => $rango[1]])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><?= $etiqueta ?></a>
                        <?php endforeach; ?>
                        <a href="<?= esc($url(['exportar' => 'csv'])) ?>" class="btn btn-sm btn-success rounded-pill" id="exportarCsv"><i class="fas fa-file-csv me-1"></i> Exportar CSV</a>
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark rounded-pill"><i class="fas fa-print me-1"></i> Imprimir / PDF</button>
                    </div>
                </form>
            </div>

            <div class="reporte-papel">
                <?php encabezadoReporte('Reporte Ejecutivo TIC · Mesa de Servicios', 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)), 'Tiempos en horario hábil o 24x7 según el ANS de cada caso · Generado el ' . date('d/m/Y h:i A')); ?>

                <div class="row g-3 mb-3" id="kpisReporte">
                    <?php foreach ($kpis as $kpi): ?>
                    <div class="col-6 col-lg-3">
                        <div class="kpi-reporte" style="border-left-color: <?= $kpi[3] ?>;">
                            <div class="etiqueta"><?= $kpi[0] ?></div>
                            <div class="valor" style="color: <?= in_array($kpi[0], ['Cumplimiento ANS solución', 'Cumplimiento 1ª respuesta'], true) ? $kpi[3] : '#0f172a' ?>"><?= esc((string) $kpi[1]) ?></div>
                            <div class="small text-muted"><?= esc($kpi[2]) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h6 class="doc-subtitulo mt-4"><i class="fas fa-lightbulb me-2 text-warning"></i>Conclusiones</h6>
                <ul class="conclusiones" id="conclusiones">
                    <?php foreach ($r['conclusiones'] as $conclusion): ?><li><?= esc($conclusion) ?></li><?php endforeach; ?>
                </ul>

                <h6 class="doc-subtitulo mt-4">Tendencia de los últimos 6 meses</h6>
                <div class="grafico-tendencia"><canvas id="graficoTendencia" aria-label="Casos registrados, resueltos y cumplimiento del ANS por mes" role="img"></canvas></div>
                <div class="table-responsive">
                    <table class="tabla-reporte mt-2" id="tablaTendencia">
                        <thead><tr><th>Mes</th><?php foreach ($r['tendencia'] as $m): ?><th class="text-end"><?= $nombreMes($m['mes']) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <tr><td>Registrados</td><?php foreach ($r['tendencia'] as $m): ?><td class="text-end"><?= $m['registrados'] ?></td><?php endforeach; ?></tr>
                            <tr><td>Resueltos</td><?php foreach ($r['tendencia'] as $m): ?><td class="text-end"><?= $m['resueltos'] ?></td><?php endforeach; ?></tr>
                            <tr><td>Cumplimiento solución</td><?php foreach ($r['tendencia'] as $m): ?><td class="text-end fw-bold" style="color: <?= $colorMeta($m['cumplimiento']) ?>"><?= $pct($m['cumplimiento']) ?></td><?php endforeach; ?></tr>
                        </tbody>
                    </table>
                </div>

                <?= $tablaDesglose('tablaPrioridad', 'Cumplimiento por prioridad', $r['por_prioridad'], 'Prioridad', function ($prio) use ($clasesPrioridad, $objetivos) {
                    return '<span class="badge ' . ($clasesPrioridad[$prio] ?? 'bg-secondary') . '">' . esc($prio) . '</span> <small class="text-muted">INC ' . duracionTexto($objetivos['INC'][$prio][0]) . ' / ' . duracionTexto($objetivos['INC'][$prio][1])
                         . ' · RF ' . duracionTexto($objetivos['RF'][$prio][0]) . ' / ' . duracionTexto($objetivos['RF'][$prio][1]) . '</small>';
                }) ?>
                <?= $tablaDesglose('tablaTipo', 'Incidentes y requerimientos', $r['por_tipo'], 'Tipo', function ($tipo) { return esc(TIPOS_TICKET[$tipo]); }) ?>
                <?= $tablaDesglose('tablaServicios', 'Servicios con más casos', $r['por_servicio'], 'Servicio', null, 10) ?>
                <?= $tablaDesglose('tablaGruposReporte', 'Desempeño por grupo resolutor', $r['por_grupo'], 'Grupo resolutor') ?>
                <?= $tablaDesglose('tablaEspecialistas', 'Desempeño por especialista', $r['por_tecnico'], 'Especialista') ?>

                <h6 class="doc-subtitulo mt-4">Casos resueltos fuera del ANS <span class="badge bg-danger"><?= count($r['incumplimientos']) ?></span></h6>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaIncumplimientos">
                        <thead><tr><th>Caso</th><th>Prioridad</th><th>Servicio</th><th>Especialista</th><th class="text-end">Objetivo</th><th class="text-end">Tiempo neto</th><th class="text-end">Exceso</th><th>Solucionado</th></tr></thead>
                        <tbody>
                        <?php foreach ($r['incumplimientos'] as $i): ?>
                            <tr><td class="fw-bold text-nowrap"><?= esc($i['codigo']) ?></td><td><?= esc($i['prioridad']) ?></td><td class="small"><?= esc($i['servicio']) ?></td><td class="small"><?= esc($i['tecnico'] ?: 'Sin asignar') ?></td>
                                <td class="text-end text-nowrap"><?= duracionTexto($i['objetivo']) ?></td><td class="text-end text-nowrap"><?= duracionTexto($i['consumido']) ?></td>
                                <td class="text-end text-danger fw-bold text-nowrap">+<?= duracionTexto($i['consumido'] - $i['objetivo']) ?></td><td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($i['fecha'])) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$r['incumplimientos']): ?><tr><td colspan="8" class="text-center text-success py-3"><i class="fas fa-check-circle me-1"></i> Todos los casos resueltos en el periodo cumplieron el ANS.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h6 class="doc-subtitulo mt-4">Cómo se resolvieron los casos (código de cierre)</h6>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaCodigosCierre">
                        <thead><tr><th>Código de cierre</th><th class="text-end">Casos resueltos</th><th class="text-end">Participación</th></tr></thead>
                        <tbody>
                        <?php $total_codigos = array_sum($r['por_codigo_cierre']); foreach ($r['por_codigo_cierre'] as $codigo => $cantidad): ?>
                            <tr><td><?= esc($codigo) ?></td><td class="text-end"><?= (int) $cantidad ?></td><td class="text-end"><?= $pct($total_codigos ? $cantidad * 100 / $total_codigos : null) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$r['por_codigo_cierre']): ?><tr><td colspan="3" class="text-center text-muted py-3">No hay casos resueltos en el periodo.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="reporte-nota">
                    <strong>Metodología.</strong> Tiempos medidos en minutos del calendario de cada caso: hábil (<?= esc(textoHorarioItsm($cfg)) ?>) o 24x7 para los incidentes críticos configurados así.
                    El tiempo en pausa (en espera del cliente o de un tercero) y el tiempo en que un caso estuvo solucionado antes de reabrirse no cuentan contra el ANS.
                    Cumplimiento = casos resueltos (o con primera respuesta) en el periodo dentro de su objetivo ÷ casos medidos. MTTA = tiempo medio hasta la primera respuesta; MTTR = tiempo medio de solución neto de pausas.
                    Backlog = casos registrados hasta el corte y no resueltos a esa fecha. Prioridad por matriz impacto × urgencia; objetivos pactados en los ANS de la organización (ITIL no fija tiempos).
                </p>
                <div class="reporte-firmas">
                    <div><div class="linea"></div><strong>Líder de la Mesa de Servicios</strong></div>
                    <div><div class="linea"></div><strong>Dirección de Tecnología</strong></div>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const TENDENCIA = <?= jsJson(array_map(function ($m) use ($nombreMes) { return ['mes' => $nombreMes($m['mes']), 'registrados' => $m['registrados'], 'resueltos' => $m['resueltos'], 'cumplimiento' => $m['cumplimiento']]; }, $r['tendencia'])) ?>;
const META = <?= jsJson($meta) ?>;
if (window.Chart) {
    new Chart(document.getElementById('graficoTendencia'), {
        data: {
            labels: TENDENCIA.map((m) => m.mes),
            datasets: [
                { type: 'bar', label: 'Registrados', data: TENDENCIA.map((m) => m.registrados), backgroundColor: '#0B4F8A', yAxisID: 'casos' },
                { type: 'bar', label: 'Resueltos', data: TENDENCIA.map((m) => m.resueltos), backgroundColor: '#3FAE49', yAxisID: 'casos' },
                { type: 'line', label: 'Cumplimiento ANS (%)', data: TENDENCIA.map((m) => m.cumplimiento), borderColor: '#f59e0b', backgroundColor: '#f59e0b', cubicInterpolationMode: 'monotone', spanGaps: true, yAxisID: 'porcentaje' },
                { type: 'line', label: 'Meta', data: TENDENCIA.map(() => META), borderColor: '#dc2626', borderDash: [6, 4], pointRadius: 0, yAxisID: 'porcentaje' },
            ],
        },
        options: {
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                casos: { type: 'linear', position: 'left', beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Casos' } },
                porcentaje: { type: 'linear', position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: '% cumplimiento' } },
            },
        },
    });
}
</script>
</body>
</html>
