<?php
/**
 * DESEMPEÑO Y BONOS - MESA DE SERVICIOS Y CANAL PQRSD
 * Cuánto cerró cada persona y cuánto cumplió: casos de la mesa con su ANS (ITIL) y solicitudes del canal
 * ciudadano con los términos de la Ley 1755 de 2015. Marca quién gana bonificación y quién necesita un
 * plan de mejora, con las gráficas del periodo. Exportable a CSV e imprimible.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/reportes.php';
require_once __DIR__ . '/../includes/itsm.php';
require_once __DIR__ . '/../includes/pqrsd.php';
require_once __DIR__ . '/../includes/utilidades.php';
$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'reporte_desempeno');

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([(int) $usuario_actual['id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------- periodo
$fecha_ok = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)); };
$desde = $fecha_ok($_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = $fecha_ok($_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
if ($hasta < $desde) list($desde, $hasta) = [$hasta, $desde];

$titulo = 'Desempeño y Bonos';
$subtitulo = 'Casos cerrados y cumplimiento de cada persona en la mesa de servicios y en el canal ciudadano';

sincronizarTickets($db);
$cfg_itsm = configItsm($db);
$cfg_pqrsd = configPqrsd($db);
$meta_ans = (float) $cfg_itsm['meta_cumplimiento'];
$meta_pqrsd = (float) $cfg_pqrsd['meta_cumplimiento'];
$bono = (float) $cfg_pqrsd['bono_cumplimiento'];
$alerta = (float) $cfg_pqrsd['alerta_cumplimiento'];
$minimo = (int) $cfg_pqrsd['minimo_para_medir'];

$reporte = reporteEjecutivoTic($db, $desde, $hasta);
$pqrsd_disponible = pqrsdDisponible($db);
$desempeno_pqrsd = $pqrsd_disponible ? desempenoPqrsd($db, $desde, $hasta) : [];

// ---------------------------------------------------------------- una fila por persona (se cruzan por nombre)
$personas = [];
$fila = function ($nombre, $rol = '') {
    return ['nombre' => $nombre, 'rol' => $rol,
            'casos_resueltos' => 0, 'casos_cumplidos' => 0, 'casos_registrados' => 0, 'mttr' => null, 'cumplimiento_ans' => null,
            'pqrsd_respondidas' => 0, 'pqrsd_a_tiempo' => 0, 'pqrsd_vencidas' => 0, 'pqrsd_dias' => null, 'cumplimiento_pqrsd' => null,
            'carga' => 0, 'atendidos' => 0, 'cumplidos' => 0, 'cumplimiento' => null, 'calificacion' => 'sin_datos'];
};
foreach ($reporte['por_tecnico'] as $nombre => $t) {
    if ($nombre === 'Sin asignar') continue;
    $personas[$nombre] = $fila($nombre, 'Mesa de servicios');
    $personas[$nombre]['casos_registrados'] = (int) $t['registrados'];
    $personas[$nombre]['casos_resueltos'] = (int) $t['resueltos'];
    $personas[$nombre]['casos_cumplidos'] = (int) $t['sol_cumplidos'];
    $personas[$nombre]['cumplimiento_ans'] = $t['cumplimiento_solucion'];
    $personas[$nombre]['mttr'] = $t['mttr'];
}
foreach ($desempeno_pqrsd as $d) {
    $nombre = $d['nombre'];
    if (!isset($personas[$nombre])) $personas[$nombre] = $fila($nombre, $d['rol']);
    elseif ($d['rol']) $personas[$nombre]['rol'] = $d['rol'];
    $personas[$nombre]['pqrsd_respondidas'] = (int) $d['respondidas'];
    $personas[$nombre]['pqrsd_a_tiempo'] = (int) $d['a_tiempo'];
    $personas[$nombre]['pqrsd_vencidas'] = (int) $d['vencidas'];
    $personas[$nombre]['pqrsd_dias'] = $d['dias_promedio'];
    $personas[$nombre]['cumplimiento_pqrsd'] = $d['cumplimiento'];
    $personas[$nombre]['carga'] = (int) $d['carga'];
}
// Cumplimiento global de cada persona: lo que cerró a tiempo entre todo lo que cerró (mesa + canal)
foreach ($personas as $nombre => &$p) {
    $p['atendidos'] = $p['casos_resueltos'] + $p['pqrsd_respondidas'];
    $p['cumplidos'] = $p['casos_cumplidos'] + $p['pqrsd_a_tiempo'];
    $p['cumplimiento'] = $p['atendidos'] ? round($p['cumplidos'] * 100 / $p['atendidos'], 1) : null;
    if ($p['atendidos'] < $minimo) $p['calificacion'] = 'sin_datos';
    elseif ($p['cumplimiento'] >= $bono) $p['calificacion'] = 'bonificacion';
    elseif ($p['cumplimiento'] < $alerta) $p['calificacion'] = 'plan_mejora';
    else $p['calificacion'] = 'cumple';
}
unset($p);
uasort($personas, function ($a, $b) {
    return [$b['cumplimiento'] ?? -1, $b['atendidos']] <=> [$a['cumplimiento'] ?? -1, $a['atendidos']];
});

$evaluadas = array_filter($personas, function ($p) { return $p['calificacion'] !== 'sin_datos'; });
$con_bono = array_filter($personas, function ($p) { return $p['calificacion'] === 'bonificacion'; });
$en_mejora = array_filter($personas, function ($p) { return $p['calificacion'] === 'plan_mejora'; });
$atendidos_equipo = array_sum(array_column($personas, 'atendidos'));
$cumplidos_equipo = array_sum(array_column($personas, 'cumplidos'));
$cumplimiento_equipo = $atendidos_equipo ? round($cumplidos_equipo * 100 / $atendidos_equipo, 1) : null;

$pct = function ($v) { return $v === null ? '—' : number_format($v, 1, ',', '.') . '%'; };
$url = function (array $cambios) use ($desde, $hasta) { return 'reporte_desempeno.php?' . http_build_query(array_merge(['desde' => $desde, 'hasta' => $hasta], $cambios)); };
$meses = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
$nombreMes = function ($ym) use ($meses) { return $meses[substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4); };

// ---------------------------------------------------------------- exportar
if (($_GET['exportar'] ?? '') === 'csv') {
    $filas = [['Desempeño y Bonos', "Del $desde al $hasta"], [],
              ['Persona', 'Rol', 'Casos resueltos', 'Casos a tiempo', 'Cumplimiento ANS (%)', 'MTTR (min hábiles)',
               'PQRSD respondidas', 'PQRSD a tiempo', 'Cumplimiento término (%)', 'Días hábiles promedio',
               'Total atendidos', 'Total a tiempo', 'Cumplimiento global (%)', 'Calificación']];
    foreach ($personas as $p) {
        $filas[] = [$p['nombre'], $p['rol'], $p['casos_resueltos'], $p['casos_cumplidos'], $p['cumplimiento_ans'], $p['mttr'],
                    $p['pqrsd_respondidas'], $p['pqrsd_a_tiempo'], $p['cumplimiento_pqrsd'], $p['pqrsd_dias'],
                    $p['atendidos'], $p['cumplidos'], $p['cumplimiento'], CALIFICACIONES_DESEMPENO[$p['calificacion']]['texto']];
    }
    $filas[] = [];
    $filas[] = ['Reglas', "Bonificación desde $bono% · plan de mejora por debajo de $alerta% · mínimo $minimo atendidos para calificar"];
    enviarCsv("desempeno_{$desde}_a_{$hasta}.csv", $filas);
    exit;
}
$aviso = tomarAviso();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desempeño y Bonos | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .kpi-des { background: #fff; border: 0; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); padding: 1rem; height: 100%; }
        .kpi-des .etiqueta { font-size: .7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .kpi-des .valor { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
        .tabla-des { min-width: 1040px; }
        .tabla-des th { font-size: .7rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .grafico { position: relative; height: 320px; }
        @media print {
            .sidebar, .header-admin, .no-imprimir, .btn { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0"><i class="fas fa-circle-info me-2"></i><?= esc($aviso['texto']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="bg-white p-4 rounded-4 shadow-sm mb-4 border-0 no-imprimir">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="small fw-bold text-muted mb-1" for="desde">Desde</label>
                    <input type="date" class="form-control rounded-pill px-4" id="desde" name="desde" value="<?= esc($desde) ?>"></div>
                <div class="col-md-3"><label class="small fw-bold text-muted mb-1" for="hasta">Hasta</label>
                    <input type="date" class="form-control rounded-pill px-4" id="hasta" name="hasta" value="<?= esc($hasta) ?>"></div>
                <div class="col-md-2 d-grid"><button type="submit" class="btn btn-dark fw-bold rounded-pill" style="background: var(--azul-sonda);" id="btnFiltrarDesempeno">VER</button></div>
                <div class="col-md-4 d-flex gap-2 justify-content-md-end">
                    <a href="<?= esc($url(['exportar' => 'csv'])) ?>" class="btn btn-outline-success rounded-pill fw-bold px-3" id="btnExportarDesempeno"><i class="fas fa-file-csv me-1"></i>CSV</a>
                    <button type="button" class="btn btn-outline-secondary rounded-pill fw-bold px-3" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir</button>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="kpi-des" id="kpiPersonas"><div class="etiqueta">Personas calificadas</div><div class="valor"><?= count($evaluadas) ?></div><div class="small text-muted">De <?= count($personas) ?> con trabajo en el periodo</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-des" id="kpiCumplimientoEquipo"><div class="etiqueta">Cumplimiento del equipo</div><div class="valor" style="color: <?= $cumplimiento_equipo !== null && $cumplimiento_equipo >= $meta_ans ? '#059669' : '#dc2626' ?>"><?= $pct($cumplimiento_equipo) ?></div><div class="small text-muted"><?= (int) $cumplidos_equipo ?> de <?= (int) $atendidos_equipo ?> a tiempo</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-des" id="kpiBonificacion"><div class="etiqueta">Con bonificación</div><div class="valor text-success"><?= count($con_bono) ?></div><div class="small text-muted">Desde <?= (int) $bono ?>% de cumplimiento</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-des" id="kpiPlanMejora"><div class="etiqueta">Plan de mejora</div><div class="valor text-danger"><?= count($en_mejora) ?></div><div class="small text-muted">Por debajo de <?= (int) $alerta ?>%</div></div></div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1"><i class="fas fa-ranking-star me-2 text-primary"></i>Desempeño por persona</h2>
                <p class="small text-muted">Del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?>.
                    En la mesa de servicios el cumplimiento es el del ANS de solución (meta <?= (int) $meta_ans ?>%); en el canal ciudadano, las respuestas dentro del término de ley (meta <?= (int) $meta_pqrsd ?>%).</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-des" id="tablaDesempeno">
                        <thead>
                            <tr>
                                <th class="ps-3" rowspan="2">Persona</th>
                                <th colspan="3" class="text-center">Mesa de servicios (ANS)</th>
                                <th colspan="4" class="text-center">Canal PQRSD (Ley 1755)</th>
                                <th colspan="2" class="text-center">Total</th>
                                <th class="pe-3" rowspan="2">Calificación</th>
                            </tr>
                            <tr>
                                <th>Resueltos</th><th>A tiempo</th><th>Cumple</th>
                                <th>Respondidas</th><th>A tiempo</th><th>Cumple</th><th>Días háb.</th>
                                <th>Atendidos</th><th>Cumplimiento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($personas as $p): $cal = CALIFICACIONES_DESEMPENO[$p['calificacion']]; ?>
                                <tr>
                                    <td class="ps-3"><span class="fw-semibold"><?= esc($p['nombre']) ?></span>
                                        <div class="small text-muted"><?= esc($p['rol'] ?: 'sin rol') ?><?= $p['carga'] ? ' · ' . (int) $p['carga'] . ' PQRSD en trámite' : '' ?></div></td>
                                    <td><?= (int) $p['casos_resueltos'] ?></td>
                                    <td><?= (int) $p['casos_cumplidos'] ?></td>
                                    <td style="color: <?= $p['cumplimiento_ans'] === null ? '#94a3b8' : ($p['cumplimiento_ans'] >= $meta_ans ? '#059669' : '#dc2626') ?>" class="fw-bold"><?= $pct($p['cumplimiento_ans']) ?></td>
                                    <td><?= (int) $p['pqrsd_respondidas'] ?></td>
                                    <td><?= (int) $p['pqrsd_a_tiempo'] ?><?= $p['pqrsd_vencidas'] ? ' <span class="badge text-bg-danger">' . (int) $p['pqrsd_vencidas'] . ' vencidas</span>' : '' ?></td>
                                    <td style="color: <?= $p['cumplimiento_pqrsd'] === null ? '#94a3b8' : ($p['cumplimiento_pqrsd'] >= $meta_pqrsd ? '#059669' : '#dc2626') ?>" class="fw-bold"><?= $pct($p['cumplimiento_pqrsd']) ?></td>
                                    <td><?= $p['pqrsd_dias'] === null ? '—' : esc(number_format($p['pqrsd_dias'], 1, ',', '')) ?></td>
                                    <td class="fw-bold"><?= (int) $p['atendidos'] ?></td>
                                    <td class="fw-bold" style="color: <?= $p['cumplimiento'] === null ? '#94a3b8' : ($p['cumplimiento'] >= $bono ? '#059669' : ($p['cumplimiento'] < $alerta ? '#dc2626' : '#0B4F8A')) ?>"><?= $pct($p['cumplimiento']) ?></td>
                                    <td class="pe-3"><span class="badge text-bg-<?= esc($cal['clase']) ?>"><i class="fas <?= esc($cal['icono']) ?> me-1"></i><?= esc($cal['texto']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$personas): ?><tr><td colspan="11" class="text-center py-4 text-muted">Nadie cerró casos ni solicitudes en este periodo.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-3 mb-0">Bonificación desde <strong><?= (int) $bono ?>%</strong> · plan de mejora por debajo de <strong><?= (int) $alerta ?>%</strong> ·
                    se califica con <strong><?= (int) $minimo ?></strong> casos o solicitudes atendidas o más. Los umbrales se cambian en
                    <a href="supervisor_pqrsd.php?vista=configuracion" class="text-decoration-none">Supervisión PQRSD</a>.
                    La plataforma mide; la decisión de bonificar o de abrir un plan de mejora es de la empresa y conviene hablarla con la persona.</p>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-chart-column me-2 text-primary"></i>Cumplimiento por persona</h2>
                    <div class="grafico"><canvas id="graficoCumplimiento" aria-label="Cumplimiento de cada persona frente a la meta" role="img"></canvas></div>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-layer-group me-2 text-primary"></i>Volumen atendido por persona</h2>
                    <div class="grafico"><canvas id="graficoVolumen" aria-label="Casos de la mesa y solicitudes PQRSD atendidas por persona" role="img"></canvas></div>
                </div></div>
            </div>
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Tendencia de la mesa en los últimos 6 meses</h2>
                    <div class="grafico"><canvas id="graficoTendenciaDes" aria-label="Casos registrados, resueltos y cumplimiento del ANS por mes" role="img"></canvas></div>
                </div></div>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const PERSONAS = <?= jsJson(array_values(array_map(function ($p) {
    return ['nombre' => $p['nombre'], 'cumplimiento' => $p['cumplimiento'], 'casos' => $p['casos_resueltos'],
            'pqrsd' => $p['pqrsd_respondidas'], 'calificacion' => $p['calificacion']];
}, $personas))) ?>;
const TENDENCIA = <?= jsJson(array_map(function ($m) use ($nombreMes) {
    return ['mes' => $nombreMes($m['mes']), 'registrados' => $m['registrados'], 'resueltos' => $m['resueltos'], 'cumplimiento' => $m['cumplimiento']];
}, $reporte['tendencia'])) ?>;
const META = <?= jsJson($meta_ans) ?>;
const BONO = <?= jsJson($bono) ?>;
const ALERTA = <?= jsJson($alerta) ?>;

if (window.Chart && PERSONAS.length) {
    const color = (p) => p.calificacion === 'bonificacion' ? '#059669' : (p.calificacion === 'plan_mejora' ? '#dc2626' : (p.calificacion === 'sin_datos' ? '#94a3b8' : '#0B4F8A'));
    new Chart(document.getElementById('graficoCumplimiento'), {
        data: {
            labels: PERSONAS.map((p) => p.nombre),
            datasets: [
                { type: 'bar', label: 'Cumplimiento (%)', data: PERSONAS.map((p) => p.cumplimiento), backgroundColor: PERSONAS.map(color) },
                { type: 'line', label: 'Bonificación (' + BONO + '%)', data: PERSONAS.map(() => BONO), borderColor: '#059669', borderDash: [6, 4], pointRadius: 0 },
                { type: 'line', label: 'Plan de mejora (' + ALERTA + '%)', data: PERSONAS.map(() => ALERTA), borderColor: '#dc2626', borderDash: [6, 4], pointRadius: 0 },
            ],
        },
        options: {
            indexAxis: 'y', maintainAspectRatio: false, animation: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { x: { min: 0, max: 100, title: { display: true, text: '% dentro del tiempo pactado' } } },
        },
    });
    new Chart(document.getElementById('graficoVolumen'), {
        data: {
            labels: PERSONAS.map((p) => p.nombre),
            datasets: [
                { type: 'bar', label: 'Casos de la mesa', data: PERSONAS.map((p) => p.casos), backgroundColor: '#0B4F8A' },
                { type: 'bar', label: 'Solicitudes PQRSD', data: PERSONAS.map((p) => p.pqrsd), backgroundColor: '#3FAE49' },
            ],
        },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}
if (window.Chart && TENDENCIA.length) {
    new Chart(document.getElementById('graficoTendenciaDes'), {
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
            maintainAspectRatio: false, animation: false,
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
