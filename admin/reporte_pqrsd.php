<?php
/**
 * REPORTE PQRSD - CANAL CIUDADANO
 * Informe del canal en el periodo: cuántas solicitudes entraron y por qué tipo, cuántas se respondieron
 * dentro de los términos de la Ley 1755 de 2015, cuántas actuaciones de ley se hicieron (prórrogas,
 * requerimientos, traslados y desistimientos), el detalle de cada incumplimiento y la tendencia de
 * 6 meses. Exportable a CSV e imprimible para presentarlo a la dirección.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/reportes.php';
require_once __DIR__ . '/../includes/pqrsd.php';
require_once __DIR__ . '/../includes/utilidades.php';
$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'reporte_pqrsd');

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([(int) $usuario_actual['id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$fecha_ok = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)); };
$desde = $fecha_ok($_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = $fecha_ok($_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
if ($hasta < $desde) list($desde, $hasta) = [$hasta, $desde];

$titulo = 'Reporte PQRSD';
$subtitulo = 'Informe del canal ciudadano: volumen, cumplimiento de los términos de ley y actuaciones';

$disponible = pqrsdDisponible($db);
$cfg = configPqrsd($db);
$meta = (float) $cfg['meta_cumplimiento'];
$r = $disponible ? reportePqrsd($db, $desde, $hasta) : ['kpi' => [], 'por_tipo' => [], 'por_estado' => [], 'por_responsable' => [], 'por_medio' => [], 'incumplimientos' => [], 'tendencia' => []];
$k = $r['kpi'];

$pct = function ($v) { return $v === null ? '—' : number_format($v, 1, ',', '.') . '%'; };
$num = function ($v) { return $v === null ? '—' : number_format((float) $v, 1, ',', '.'); };
$url = function (array $cambios) use ($desde, $hasta) { return 'reporte_pqrsd.php?' . http_build_query(array_merge(['desde' => $desde, 'hasta' => $hasta], $cambios)); };
$meses = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
$nombreMes = function ($ym) use ($meses) { return $meses[substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4); };

if (($_GET['exportar'] ?? '') === 'csv' && $disponible) {
    $filas = [['Reporte PQRSD - Canal Ciudadano', "Del $desde al $hasta"], [], ['Indicador', 'Valor']];
    foreach ([
        'Solicitudes radicadas' => $k['radicadas'], 'Respondidas en el periodo' => $k['respondidas'],
        'Respondidas dentro del término' => $k['a_tiempo'], 'Cumplimiento (%)' => $k['cumplimiento'],
        'Días hábiles promedio de respuesta' => $k['dias_promedio'], 'Meta de cumplimiento (%)' => $meta,
        'En trámite hoy' => $k['abiertas'], 'Con término vencido hoy' => $k['vencidas'], 'Por vencer (3 días o menos)' => $k['por_vencer'],
        'Esperando información del ciudadano' => $k['esperando'], 'Prórrogas (art. 14)' => $k['prorrogadas'],
        'Requerimientos al ciudadano (art. 17)' => $k['requeridas'], 'Desistimientos (art. 17)' => $k['desistidas'],
        'Traslados por competencia (art. 21)' => $k['trasladadas'], 'Atención prioritaria (art. 20)' => $k['prioritarias'],
        'Denuncias anónimas' => $k['anonimas'], 'Con soporte adjunto' => $k['con_adjunto'],
    ] as $indicador => $valor) {
        $filas[] = [$indicador, $valor];
    }
    $filas[] = [];
    $filas[] = ['Por tipo de solicitud', 'Radicadas', 'Respondidas', 'Dentro del término', 'Cumplimiento (%)', 'Días hábiles promedio', 'En trámite', 'Vencidas'];
    foreach ($r['por_tipo'] as $tipo => $g) {
        $filas[] = [$tipo . ' (' . diasTerminoPqrsd($tipo) . ' días hábiles)', $g['radicadas'], $g['respondidas'], $g['a_tiempo'], $g['cumplimiento'], $g['dias_promedio'], $g['abiertas'], $g['vencidas']];
    }
    $filas[] = [];
    $filas[] = ['Por tema', 'Radicadas', 'Respondidas', 'Dentro del término', 'Cumplimiento (%)', 'Días hábiles promedio', 'En trámite', 'Vencidas'];
    foreach ($r['por_tema'] as $tema => $g) {
        $filas[] = [$tema, $g['radicadas'], $g['respondidas'], $g['a_tiempo'], $g['cumplimiento'], $g['dias_promedio'], $g['abiertas'], $g['vencidas']];
    }
    $filas[] = [];
    $filas[] = ['Por responsable', 'Radicadas', 'Respondidas', 'Dentro del término', 'Cumplimiento (%)', 'Días hábiles promedio', 'En trámite', 'Vencidas'];
    foreach ($r['por_responsable'] as $quien => $g) {
        $filas[] = [$quien, $g['radicadas'], $g['respondidas'], $g['a_tiempo'], $g['cumplimiento'], $g['dias_promedio'], $g['abiertas'], $g['vencidas']];
    }
    $filas[] = [];
    $filas[] = ['Incumplimientos', 'Tipo', 'Responsable', 'Término', 'Respondida el', 'Días hábiles de más', 'Motivo'];
    foreach ($r['incumplimientos'] as $i) {
        $filas[] = [$i['radicado'], $i['tipo'], $i['responsable'], $i['termino'], $i['fecha'], $i['exceso'], $i['motivo']];
    }
    enviarCsv("reporte_pqrsd_{$desde}_a_{$hasta}.csv", $filas);
    exit;
}
$aviso = tomarAviso();
$colorMeta = function ($valor) use ($meta) { return $valor === null ? '#94a3b8' : ($valor >= $meta ? '#059669' : ($valor >= $meta - 10 ? '#d97706' : '#dc2626')); };
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte PQRSD | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .kpi-rep { background: #fff; border: 0; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); padding: 1rem; height: 100%; }
        .kpi-rep .etiqueta { font-size: .68rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .kpi-rep .valor { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
        .tabla-rep { min-width: 820px; }
        .tabla-rep th { font-size: .7rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .grafico { position: relative; height: 300px; }
        .nota-legal { background: #f8fafc; border-left: 4px solid #0A2E4F; border-radius: .5rem; padding: 1rem; font-size: .85rem; color: #475569; }
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
        <?php if (!$disponible): ?>
            <div class="alert alert-warning border-0 shadow-sm rounded-4">El canal de PQRSD todavía no está habilitado en la base de datos.</div>
        <?php endif; ?>

        <div class="bg-white p-4 rounded-4 shadow-sm mb-4 border-0 no-imprimir">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="small fw-bold text-muted mb-1" for="desde">Desde</label>
                    <input type="date" class="form-control rounded-pill px-4" id="desde" name="desde" value="<?= esc($desde) ?>"></div>
                <div class="col-md-3"><label class="small fw-bold text-muted mb-1" for="hasta">Hasta</label>
                    <input type="date" class="form-control rounded-pill px-4" id="hasta" name="hasta" value="<?= esc($hasta) ?>"></div>
                <div class="col-md-2 d-grid"><button type="submit" class="btn btn-dark fw-bold rounded-pill" style="background: var(--azul-sonda);" id="btnFiltrarReportePqrsd">VER</button></div>
                <div class="col-md-4 d-flex gap-2 justify-content-md-end">
                    <a href="<?= esc($url(['exportar' => 'csv'])) ?>" class="btn btn-outline-success rounded-pill fw-bold px-3" id="btnExportarPqrsd"><i class="fas fa-file-csv me-1"></i>CSV</a>
                    <button type="button" class="btn btn-outline-secondary rounded-pill fw-bold px-3" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir</button>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiRadicadas"><div class="etiqueta">Radicadas en el periodo</div><div class="valor"><?= (int) ($k['radicadas'] ?? 0) ?></div><div class="small text-muted"><?= (int) ($k['prioritarias'] ?? 0) ?> prioritarias · <?= (int) ($k['anonimas'] ?? 0) ?> anónimas</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiRespondidasPqrsd"><div class="etiqueta">Respondidas</div><div class="valor text-success"><?= (int) ($k['respondidas'] ?? 0) ?></div><div class="small text-muted"><?= (int) ($k['a_tiempo'] ?? 0) ?> dentro del término</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiCumplimientoPqrsd"><div class="etiqueta">Cumplimiento</div><div class="valor" style="color: <?= $colorMeta($k['cumplimiento'] ?? null) ?>"><?= $pct($k['cumplimiento'] ?? null) ?></div><div class="small text-muted">Meta: <?= (int) $meta ?>%</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiDiasPromedio"><div class="etiqueta">Días hábiles promedio</div><div class="valor"><?= $num($k['dias_promedio'] ?? null) ?></div><div class="small text-muted">De la radicación a la respuesta</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiEnTramite"><div class="etiqueta">En trámite hoy</div><div class="valor text-primary"><?= (int) ($k['abiertas'] ?? 0) ?></div><div class="small text-muted"><?= (int) ($k['por_vencer'] ?? 0) ?> por vencer · <?= (int) ($k['esperando'] ?? 0) ?> esperando al ciudadano</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiVencidasRep"><div class="etiqueta">Término vencido hoy</div><div class="valor text-danger"><?= (int) ($k['vencidas'] ?? 0) ?></div><div class="small text-muted">Sin responder</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiActuaciones"><div class="etiqueta">Actuaciones de ley</div><div class="valor"><?= (int) (($k['prorrogadas'] ?? 0) + ($k['requeridas'] ?? 0) + ($k['trasladadas'] ?? 0) + ($k['desistidas'] ?? 0)) ?></div>
                <div class="small text-muted"><?= (int) ($k['prorrogadas'] ?? 0) ?> prórrogas · <?= (int) ($k['requeridas'] ?? 0) ?> requerimientos</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-rep" id="kpiCierresLey"><div class="etiqueta">Cerradas por la ley</div><div class="valor"><?= (int) (($k['trasladadas'] ?? 0) + ($k['desistidas'] ?? 0)) ?></div>
                <div class="small text-muted"><?= (int) ($k['trasladadas'] ?? 0) ?> trasladadas · <?= (int) ($k['desistidas'] ?? 0) ?> desistidas</div></div></div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1"><i class="fas fa-list-check me-2 text-primary"></i>Por tipo de solicitud</h2>
                <p class="small text-muted">Cada tipo tiene su propio término de ley: 15 días hábiles peticiones, quejas, reclamos, sugerencias y denuncias; 10 información y copias; 30 consultas.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-rep" id="tablaPorTipo">
                        <thead><tr><th class="ps-3">Tipo</th><th>Término</th><th>Radicadas</th><th>Respondidas</th><th>A tiempo</th><th>Cumplimiento</th><th>Días háb.</th><th>En trámite</th><th class="pe-3">Vencidas</th></tr></thead>
                        <tbody>
                            <?php foreach ($r['por_tipo'] as $tipo => $g): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= esc($tipo) ?></td>
                                    <td class="small text-muted"><?= diasTerminoPqrsd($tipo) ?> días háb.</td>
                                    <td><?= (int) $g['radicadas'] ?></td>
                                    <td><?= (int) $g['respondidas'] ?></td>
                                    <td><?= (int) $g['a_tiempo'] ?></td>
                                    <td class="fw-bold" style="color: <?= $colorMeta($g['cumplimiento']) ?>"><?= $pct($g['cumplimiento']) ?></td>
                                    <td><?= $num($g['dias_promedio']) ?></td>
                                    <td><?= (int) $g['abiertas'] ?></td>
                                    <td class="pe-3"><?= $g['vencidas'] ? '<span class="badge text-bg-danger">' . (int) $g['vencidas'] . '</span>' : '0' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1"><i class="fas fa-tags me-2 text-primary"></i>Por tema</h2>
                <p class="small text-muted">El tema se deduce del asunto de la solicitud y es lo primero que mira el reparto para escoger a quién le toca.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-rep" id="tablaPorTema">
                        <thead><tr><th class="ps-3">Tema</th><th>Radicadas</th><th>Respondidas</th><th>A tiempo</th><th>Cumplimiento</th><th>Días háb.</th><th>En trámite</th><th class="pe-3">Vencidas</th></tr></thead>
                        <tbody>
                            <?php foreach ($r['por_tema'] as $tema => $g): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= esc($tema) ?></td>
                                    <td><?= (int) $g['radicadas'] ?></td>
                                    <td><?= (int) $g['respondidas'] ?></td>
                                    <td><?= (int) $g['a_tiempo'] ?></td>
                                    <td class="fw-bold" style="color: <?= $colorMeta($g['cumplimiento']) ?>"><?= $pct($g['cumplimiento']) ?></td>
                                    <td><?= $num($g['dias_promedio']) ?></td>
                                    <td><?= (int) $g['abiertas'] ?></td>
                                    <td class="pe-3"><?= $g['vencidas'] ? '<span class="badge text-bg-danger">' . (int) $g['vencidas'] . '</span>' : '0' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Tendencia de los últimos 6 meses</h2>
                    <div class="grafico"><canvas id="graficoTendenciaPqrsd" aria-label="Solicitudes radicadas, respondidas y cumplimiento por mes" role="img"></canvas></div>
                </div></div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>En qué estado están</h2>
                    <div class="grafico"><canvas id="graficoEstados" aria-label="Distribución de las solicitudes por estado" role="img"></canvas></div>
                </div></div>
            </div>
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-chart-column me-2 text-primary"></i>Volumen y cumplimiento por tipo</h2>
                    <div class="grafico"><canvas id="graficoTipos" aria-label="Solicitudes radicadas y respondidas por tipo, con su cumplimiento" role="img"></canvas></div>
                </div></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="fas fa-user-check me-2 text-primary"></i>Por responsable</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-rep" id="tablaPorResponsable">
                        <thead><tr><th class="ps-3">Responsable</th><th>Radicadas</th><th>Respondidas</th><th>A tiempo</th><th>Cumplimiento</th><th>Días háb.</th><th>En trámite</th><th class="pe-3">Vencidas</th></tr></thead>
                        <tbody>
                            <?php foreach ($r['por_responsable'] as $quien => $g): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= esc($quien) ?></td>
                                    <td><?= (int) $g['radicadas'] ?></td>
                                    <td><?= (int) $g['respondidas'] ?></td>
                                    <td><?= (int) $g['a_tiempo'] ?></td>
                                    <td class="fw-bold" style="color: <?= $colorMeta($g['cumplimiento']) ?>"><?= $pct($g['cumplimiento']) ?></td>
                                    <td><?= $num($g['dias_promedio']) ?></td>
                                    <td><?= (int) $g['abiertas'] ?></td>
                                    <td class="pe-3"><?= $g['vencidas'] ? '<span class="badge text-bg-danger">' . (int) $g['vencidas'] . '</span>' : '0' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$r['por_responsable']): ?><tr><td colspan="8" class="text-center py-4 text-muted">Sin movimiento en el periodo.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-3 mb-0">El desempeño de cada persona, junto con los casos de la mesa de servicios y la bonificación, está en
                    <a href="reporte_desempeno.php" class="text-decoration-none">Desempeño y Bonos</a>.</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1"><i class="fas fa-triangle-exclamation me-2 text-danger"></i>Incumplimientos (<?= count($r['incumplimientos']) ?>)</h2>
                <p class="small text-muted">Las respondidas fuera del término dentro del periodo y las que hoy siguen sin responder con el término vencido. Este es el listado que hay que explicar y corregir.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-rep" id="tablaIncumplimientos">
                        <thead><tr><th class="ps-3">Radicado</th><th>Tipo</th><th>Responsable</th><th>Término</th><th>Respondida</th><th>Días de más</th><th class="pe-3">Motivo</th></tr></thead>
                        <tbody>
                            <?php foreach ($r['incumplimientos'] as $i): ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-nowrap"><?= esc($i['radicado']) ?></td>
                                    <td class="small"><?= esc($i['tipo']) ?></td>
                                    <td class="small"><?= esc($i['responsable']) ?></td>
                                    <td class="small"><?= esc($i['termino']) ?></td>
                                    <td class="small"><?= esc($i['fecha']) ?></td>
                                    <td><span class="badge text-bg-danger"><?= (int) $i['exceso'] ?></span></td>
                                    <td class="pe-3 small"><?= esc($i['motivo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$r['incumplimientos']): ?><tr><td colspan="7" class="text-center py-4 text-success fw-semibold">Sin incumplimientos en el periodo: todo se respondió dentro del término.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="nota-legal mb-4">
            <b>Base normativa.</b> Los términos son los de la Ley 1755 de 2015 (arts. 13 a 33), contados en días hábiles desde el día siguiente a la radicación:
            15 días para peticiones, quejas, reclamos, sugerencias y denuncias; 10 para información y copias; 30 para consultas. Las prórrogas (art. 14) se avisan
            antes del vencimiento y no pasan del doble del término. Los requerimientos al ciudadano (art. 17) suspenden el término y dan un mes, vencido el cual
            se entiende el desistimiento. Los traslados por competencia (art. 21) van dentro de los 5 días siguientes. La atención prioritaria (art. 20) aplica a
            niños, niñas y adolescentes, personas adultas mayores, personas en situación de discapacidad, periodistas en ejercicio y casos con riesgo de un
            perjuicio irremediable a un derecho fundamental. Los datos personales se tratan conforme a la Ley 1581 de 2012.
            Informe generado el <?= date('d/m/Y h:i A') ?>.
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const TENDENCIA = <?= jsJson(array_map(function ($m) use ($nombreMes) {
    return ['mes' => $nombreMes($m['mes']), 'radicadas' => $m['radicadas'], 'respondidas' => $m['respondidas'], 'cumplimiento' => $m['cumplimiento']];
}, $r['tendencia'])) ?>;
const TIPOS = <?= jsJson(array_map(function ($tipo, $g) { return ['tipo' => $tipo, 'radicadas' => $g['radicadas'], 'respondidas' => $g['respondidas'], 'cumplimiento' => $g['cumplimiento']]; }, array_keys($r['por_tipo']), $r['por_tipo'])) ?>;
const ESTADOS = <?= jsJson(array_map(function ($estado, $n) { return ['estado' => $estado, 'total' => $n]; }, array_keys($r['por_estado']), $r['por_estado'])) ?>;
const META = <?= jsJson($meta) ?>;

if (window.Chart && TENDENCIA.length) {
    new Chart(document.getElementById('graficoTendenciaPqrsd'), {
        data: {
            labels: TENDENCIA.map((m) => m.mes),
            datasets: [
                { type: 'bar', label: 'Radicadas', data: TENDENCIA.map((m) => m.radicadas), backgroundColor: '#0B4F8A', yAxisID: 'casos' },
                { type: 'bar', label: 'Respondidas', data: TENDENCIA.map((m) => m.respondidas), backgroundColor: '#3FAE49', yAxisID: 'casos' },
                { type: 'line', label: 'Cumplimiento (%)', data: TENDENCIA.map((m) => m.cumplimiento), borderColor: '#f59e0b', backgroundColor: '#f59e0b', cubicInterpolationMode: 'monotone', spanGaps: true, yAxisID: 'porcentaje' },
                { type: 'line', label: 'Meta', data: TENDENCIA.map(() => META), borderColor: '#dc2626', borderDash: [6, 4], pointRadius: 0, yAxisID: 'porcentaje' },
            ],
        },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                casos: { type: 'linear', position: 'left', beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Solicitudes' } },
                porcentaje: { type: 'linear', position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: '% cumplimiento' } },
            },
        },
    });
}
if (window.Chart && ESTADOS.some((e) => e.total > 0)) {
    new Chart(document.getElementById('graficoEstados'), {
        type: 'doughnut',
        data: {
            labels: ESTADOS.map((e) => e.estado),
            datasets: [{ data: ESTADOS.map((e) => e.total), backgroundColor: ['#94a3b8', '#0B4F8A', '#0ea5e9', '#3FAE49', '#334155', '#f59e0b', '#a855f7'] }],
        },
        options: { maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'bottom' } } },
    });
}
if (window.Chart && TIPOS.length) {
    new Chart(document.getElementById('graficoTipos'), {
        data: {
            labels: TIPOS.map((t) => t.tipo),
            datasets: [
                { type: 'bar', label: 'Radicadas', data: TIPOS.map((t) => t.radicadas), backgroundColor: '#0B4F8A', yAxisID: 'casos' },
                { type: 'bar', label: 'Respondidas', data: TIPOS.map((t) => t.respondidas), backgroundColor: '#3FAE49', yAxisID: 'casos' },
                { type: 'line', label: 'Cumplimiento (%)', data: TIPOS.map((t) => t.cumplimiento), borderColor: '#f59e0b', backgroundColor: '#f59e0b', spanGaps: true, yAxisID: 'porcentaje' },
            ],
        },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                casos: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Solicitudes' } },
                porcentaje: { type: 'linear', position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: '% cumplimiento' } },
            },
        },
    });
}
</script>
</body>
</html>
