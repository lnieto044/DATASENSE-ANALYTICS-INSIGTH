<?php
// Estado de resultados integral (base causación) con comparativo del año anterior,
// y recaudos/pagos del periodo por forma de pago (base caja): tarjeta, efectivo, PSE, PayPal, Zelle, Western Union, USDT...
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/reportes.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'estado_resultados');

$get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };
$desde = fechaValida($get('desde')) ? $get('desde') : date('Y-01-01');
$hasta = fechaValida($get('hasta')) ? $get('hasta') : date('Y-m-d');
if ($hasta < $desde) list($desde, $hasta) = [$hasta, $desde];
$comparar = $get('comparar') !== 'no';
$desde_anterior = date('Y-m-d', strtotime("$desde -1 year"));
$hasta_anterior = date('Y-m-d', strtotime("$hasta -1 year"));

// Secciones: [título, prefijos PUC, signo]. El signo deja ingresos, costos y gastos en positivo para presentarlos
$secciones = [
    'ingresos'       => ['Ingresos operacionales', ['41'], 1],
    'costos'         => ['Costo de ventas y de prestación de servicios', ['6', '7'], -1],
    'gastos_admin'   => ['Gastos operacionales de administración', ['51'], -1],
    'gastos_ventas'  => ['Gastos operacionales de ventas', ['52'], -1],
    'otros_ingresos' => ['Ingresos no operacionales', ['42', '43', '44', '45', '46', '47', '48', '49'], 1],
    'otros_gastos'   => ['Gastos no operacionales (financieros y otros)', ['53', '55', '56', '57', '58', '59'], -1],
    'impuesto'       => ['Impuesto de renta y complementarios', ['54'], -1],
];
// Resultado que se presenta después de cada sección
$resultados_tras = [
    'costos'        => ['utilidad_bruta', 'Utilidad bruta'],
    'gastos_ventas' => ['utilidad_operacional', 'Utilidad operacional'],
    'otros_gastos'  => ['antes_impuestos', 'Utilidad antes de impuestos'],
    'impuesto'      => ['utilidad_neta', 'Utilidad neta del ejercicio'],
];

$seccionDe = function ($cuenta) use ($secciones) {
    foreach ($secciones as $clave => $datos) {
        foreach ($datos[1] as $prefijo) {
            if (strpos((string) $cuenta, $prefijo) === 0) return $clave;
        }
    }
    return substr((string) $cuenta, 0, 1) === '4' ? 'otros_ingresos' : 'otros_gastos';
};

// Valor de cada cuenta en un periodo, con el signo de presentación de su sección
$valoresPeriodo = function ($d, $h) use ($db, $secciones, $seccionDe) {
    $valores = [];
    foreach (saldosPorCuenta($db, $d, $h, 6, [4, 5, 6, 7], true) as $fila) { // Sin el comprobante de cierre anual
        $seccion = $seccionDe($fila['cuenta']);
        $valores[$seccion][$fila['cuenta']] = $secciones[$seccion][2] * ((float) $fila['creditos'] - (float) $fila['debitos']);
    }
    return $valores;
};

$totales = function (array $valores) use ($secciones) {
    $s = [];
    foreach (array_keys($secciones) as $clave) $s[$clave] = array_sum($valores[$clave] ?? []);
    $s['utilidad_bruta'] = $s['ingresos'] - $s['costos'];
    $s['utilidad_operacional'] = $s['utilidad_bruta'] - $s['gastos_admin'] - $s['gastos_ventas'];
    $s['antes_impuestos'] = $s['utilidad_operacional'] + $s['otros_ingresos'] - $s['otros_gastos'];
    $s['utilidad_neta'] = $s['antes_impuestos'] - $s['impuesto'];
    return $s;
};

$actual = $valoresPeriodo($desde, $hasta);
$anterior = $comparar ? $valoresPeriodo($desde_anterior, $hasta_anterior) : [];
$t = $totales($actual);
$ta = $totales($anterior);

// Control: la utilidad neta debe ser igual a ingresos − gastos − costos del libro
$control = resultadoPeriodo($db, $desde, $hasta, true);
$cuadra = abs($control - $t['utilidad_neta']) < 0.01;
$formas_pago = movimientosPorFormaPago($db, $desde, $hasta);

// Cuentas de una sección con valor en alguno de los dos periodos
$cuentasDe = function ($clave) use ($actual, $anterior) {
    $cuentas = array_map('strval', array_unique(array_merge(array_keys($actual[$clave] ?? []), array_keys($anterior[$clave] ?? []))));
    sort($cuentas);
    return array_values(array_filter($cuentas, function ($c) use ($actual, $anterior, $clave) {
        return abs($actual[$clave][$c] ?? 0) >= 0.01 || abs($anterior[$clave][$c] ?? 0) >= 0.01;
    }));
};

if ($get('exportar') === 'csv') {
    $filas = [['Estado de resultados', "Del $desde al $hasta", $comparar ? "Comparado con $desde_anterior a $hasta_anterior" : '']];
    $filas[] = ['Sección', 'Cuenta', 'Nombre', 'Periodo actual', 'Periodo anterior'];
    foreach ($secciones as $clave => $datos) {
        foreach ($cuentasDe($clave) as $c) {
            $filas[] = [$datos[0], $c, nombreCuenta($db, $c), (float) ($actual[$clave][$c] ?? 0), (float) ($anterior[$clave][$c] ?? 0)];
        }
        $filas[] = [$datos[0], '', 'Total ' . $datos[0], (float) $t[$clave], (float) $ta[$clave]];
        if (isset($resultados_tras[$clave])) {
            $filas[] = ['', '', $resultados_tras[$clave][1], (float) $t[$resultados_tras[$clave][0]], (float) $ta[$resultados_tras[$clave][0]]];
        }
    }
    $filas[] = [];
    $filas[] = ['Forma de pago', 'Recaudado', 'Recaudos', 'USD recibidos', 'Pagado', 'Pagos'];
    foreach ($formas_pago as $fp) {
        $filas[] = [$fp['metodo_pago'], (float) $fp['recaudado'], (int) $fp['recaudos'], (float) $fp['usd_recibidos'], (float) $fp['pagado'], (int) $fp['pagos']];
    }
    enviarCsv("estado_resultados_{$desde}_{$hasta}.csv", $filas);
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Estado de Resultados";
$subtitulo = "Ingresos, costos, gastos y utilidad del periodo";

$columnas = $comparar ? 5 : 3;
$margen = function ($valor) use ($t) { return $t['ingresos'] > 0 ? number_format($valor * 100 / $t['ingresos'], 1, ',', '.') . '%' : '—'; };
$url = function (array $cambios) use ($desde, $hasta, $comparar) {
    return 'estado_resultados.php?' . http_build_query(array_merge(['desde' => $desde, 'hasta' => $hasta, 'comparar' => $comparar ? '' : 'no'], $cambios));
};
$mes_trimestre = (int) (ceil(date('n') / 3) * 3 - 2);
$rangos = [
    'Este mes'     => [date('Y-m-01'), date('Y-m-d')],
    'Trimestre'    => [date('Y-') . str_pad((string) $mes_trimestre, 2, '0', STR_PAD_LEFT) . '-01', date('Y-m-d')],
    'Año actual'   => [date('Y-01-01'), date('Y-m-d')],
    'Año anterior' => [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'],
];
$iconos_metodo = [
    'Tarjeta' => 'fa-credit-card', 'PSE' => 'fa-university', 'Transferencia' => 'fa-exchange-alt', 'Consignación' => 'fa-piggy-bank',
    'Efectivo (Caja)' => 'fa-money-bill-wave', 'Cheque' => 'fa-money-check', 'PayPal' => 'fa-paypal', 'Zelle' => 'fa-bolt',
    'Western Union' => 'fa-globe-americas', 'Criptomonedas (USDT)' => 'fa-coins',
];
$fila_valor = function ($valor, $anterior) use ($comparar) {
    $html = '<td class="money">' . dinero($valor, 2) . '</td>';
    if ($comparar) $html .= '<td class="money text-muted">' . dinero($anterior, 2) . '</td><td class="text-end small">' . textoVariacion(variacionPorcentual($valor, $anterior)) . '</td>';
    return $html;
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de Resultados | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/contabilidad.css">
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="container-fluid py-4">

            <div class="panel-conta p-4 mb-4 no-print" style="border-top: 5px solid var(--azul-sonda);">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-6 col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Desde</label>
                        <input type="date" name="desde" class="form-control" value="<?= esc($desde) ?>">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?= esc($hasta) ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Comparativo</label>
                        <select name="comparar" class="form-select">
                            <option value="">Mismo periodo del año anterior</option>
                            <option value="no" <?= $comparar ? '' : 'selected' ?>>Sin comparativo</option>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <button type="submit" class="btn btn-dark w-100 fw-bold" style="background: var(--azul-sonda);"><i class="fas fa-sync-alt me-2"></i> Generar</button>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                        <?php foreach ($rangos as $etiqueta => [$r_desde, $r_hasta]): ?>
                            <a href="<?= esc($url(['desde' => $r_desde, 'hasta' => $r_hasta])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><?= $etiqueta ?></a>
                        <?php endforeach; ?>
                        <a href="<?= esc($url(['exportar' => 'csv'])) ?>" class="btn btn-sm btn-success rounded-pill" id="exportarCsv"><i class="fas fa-file-csv me-1"></i> Exportar CSV</a>
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark rounded-pill"><i class="fas fa-print me-1"></i> Imprimir / PDF</button>
                    </div>
                </form>
            </div>

            <div class="reporte-papel">
                <?php encabezadoReporte('Estado de Resultados Integral', 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta))); ?>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3"><div class="kpi-reporte" style="border-left-color: #0B4F8A;"><div class="etiqueta">Ingresos operacionales</div><div class="valor"><?= dinero($t['ingresos']) ?></div><div class="small"><?= $comparar ? textoVariacion(variacionPorcentual($t['ingresos'], $ta['ingresos'])) . ' vs. año anterior' : '&nbsp;' ?></div></div></div>
                    <div class="col-6 col-lg-3"><div class="kpi-reporte" style="border-left-color: #3FAE49;"><div class="etiqueta">Utilidad bruta</div><div class="valor"><?= dinero($t['utilidad_bruta']) ?></div><div class="small text-muted">Margen bruto <?= $margen($t['utilidad_bruta']) ?></div></div></div>
                    <div class="col-6 col-lg-3"><div class="kpi-reporte" style="border-left-color: #F1C40F;"><div class="etiqueta">Utilidad operacional</div><div class="valor"><?= dinero($t['utilidad_operacional']) ?></div><div class="small text-muted">Margen operacional <?= $margen($t['utilidad_operacional']) ?></div></div></div>
                    <div class="col-6 col-lg-3"><div class="kpi-reporte" style="border-left-color: <?= $t['utilidad_neta'] >= 0 ? '#059669' : '#dc2626' ?>;"><div class="etiqueta">Utilidad neta</div><div class="valor <?= $t['utilidad_neta'] < 0 ? 'text-danger' : '' ?>"><?= dinero($t['utilidad_neta']) ?></div><div class="small text-muted">Margen neto <?= $margen($t['utilidad_neta']) ?></div></div></div>
                </div>

                <div class="text-end mb-2 no-print">
                    <?php if ($cuadra): ?>
                        <span class="badge bg-success-subtle text-success border border-success p-2" id="controlResultado"><i class="fas fa-check-circle me-1"></i> La utilidad coincide con el libro contable</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger p-2" id="controlResultado"><i class="fas fa-exclamation-triangle me-1"></i> Diferencia de <?= dinero($control - $t['utilidad_neta'], 2) ?> con el libro contable</span>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaResultados">
                        <thead>
                            <tr>
                                <th style="width: 11%;">Cuenta</th>
                                <th>Concepto</th>
                                <th class="text-end" style="width: 17%;">Periodo actual</th>
                                <?php if ($comparar): ?>
                                    <th class="text-end" style="width: 17%;"><?= date('Y', strtotime($desde_anterior)) === date('Y', strtotime($hasta_anterior)) ? 'Año ' . date('Y', strtotime($hasta_anterior)) : 'Periodo anterior' ?></th>
                                    <th class="text-end" style="width: 9%;">Var.</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($secciones as $clave => [$titulo_seccion, , $signo]): $cuentas_seccion = $cuentasDe($clave); ?>
                            <?php if ($cuentas_seccion || $clave === 'ingresos'): ?>
                                <tr class="seccion"><td colspan="<?= $columnas ?>"><?= $signo < 0 ? '(−) ' : '(+) ' ?><?= esc($titulo_seccion) ?></td></tr>
                                <?php foreach ($cuentas_seccion as $c): ?>
                                    <tr class="cuenta" data-cuenta="<?= esc($c) ?>">
                                        <td class="small text-muted"><a href="libro_auxiliar.php?<?= esc(http_build_query(['cuenta' => $c, 'desde' => $desde, 'hasta' => $hasta])) ?>"><?= esc($c) ?></a></td>
                                        <td><?= esc(nombreCuenta($db, $c)) ?></td>
                                        <?= $fila_valor($actual[$clave][$c] ?? 0, $anterior[$clave][$c] ?? 0) ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$cuentas_seccion): ?>
                                    <tr><td></td><td class="small text-muted" colspan="<?= $columnas - 1 ?>">Sin movimientos en el periodo</td></tr>
                                <?php endif; ?>
                                <tr class="subtotal">
                                    <td></td><td>Total <?= esc(mb_strtolower($titulo_seccion, 'UTF-8')) ?></td>
                                    <?= str_replace('<td class="money">', '<td class="money" data-total="' . $clave . '" data-valor="' . round($t[$clave], 2) . '">', $fila_valor($t[$clave], $ta[$clave])) ?>
                                </tr>
                            <?php endif; ?>
                            <?php if (isset($resultados_tras[$clave])): [$clave_resultado, $etiqueta_resultado] = $resultados_tras[$clave]; ?>
                                <tr class="<?= $clave_resultado === 'utilidad_neta' ? 'resultado-final' : 'resultado' ?>">
                                    <td></td><td class="text-uppercase">= <?= $etiqueta_resultado ?></td>
                                    <td class="money <?= $t[$clave_resultado] < 0 ? 'saldo-negativo' : '' ?>" <?= $clave_resultado === 'utilidad_neta' ? 'id="utilidadNeta"' : '' ?> data-total="<?= $clave_resultado ?>" data-valor="<?= round($t[$clave_resultado], 2) ?>"><?= dinero($t[$clave_resultado], 2) ?></td>
                                    <?php if ($comparar): ?>
                                        <td class="money text-muted"><?= dinero($ta[$clave_resultado], 2) ?></td>
                                        <td class="text-end small"><?= textoVariacion(variacionPorcentual($t[$clave_resultado], $ta[$clave_resultado])) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h6 class="fw-bold text-uppercase mt-5 mb-1" style="color: #0A2E4F;">Recaudos y pagos por forma de pago</h6>
                <p class="small text-muted mb-2">Dinero que realmente entró y salió en el periodo según los comprobantes aprobados (base caja). Complementa el estado de resultados, que se prepara por causación.</p>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaFormasPago">
                        <thead>
                            <tr><th>Forma de pago</th><th class="text-end">Recaudado</th><th class="text-center">Recaudos</th><th class="text-end">Moneda extranjera</th><th class="text-end">Pagado</th><th class="text-center">Pagos</th><th class="text-end">Neto</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$formas_pago): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No hay recaudos ni pagos aprobados en el periodo.</td></tr>
                            <?php endif; ?>
                            <?php $sum_recaudado = 0; $sum_pagado = 0; $sum_usd = 0; ?>
                            <?php foreach ($formas_pago as $fp): $sum_recaudado += $fp['recaudado']; $sum_pagado += $fp['pagado']; $sum_usd += $fp['usd_recibidos']; ?>
                                <tr data-metodo="<?= esc($fp['metodo_pago']) ?>" data-recaudado="<?= round((float) $fp['recaudado'], 2) ?>" data-pagado="<?= round((float) $fp['pagado'], 2) ?>" data-usd="<?= round((float) $fp['usd_recibidos'], 2) ?>">
                                    <td class="fw-bold"><i class="<?= $fp['metodo_pago'] === 'PayPal' ? 'fab' : 'fas' ?> <?= $iconos_metodo[$fp['metodo_pago']] ?? 'fa-wallet' ?> metodo-icono me-1"></i> <?= esc($fp['metodo_pago']) ?></td>
                                    <td class="money"><?= dinero($fp['recaudado'], 2) ?></td>
                                    <td class="text-center"><?= (int) $fp['recaudos'] ?></td>
                                    <td class="money"><?= (float) $fp['usd_recibidos'] > 0 ? 'US$ ' . number_format((float) $fp['usd_recibidos'], 2, ',', '.') : '—' ?></td>
                                    <td class="money"><?= dinero($fp['pagado'], 2) ?></td>
                                    <td class="text-center"><?= (int) $fp['pagos'] ?></td>
                                    <td class="money <?= $fp['recaudado'] - $fp['pagado'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($fp['recaudado'] - $fp['pagado'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($formas_pago): ?>
                                <tr class="subtotal">
                                    <td>Totales</td><td class="money"><?= dinero($sum_recaudado, 2) ?></td><td></td>
                                    <td class="money"><?= $sum_usd > 0 ? 'US$ ' . number_format($sum_usd, 2, ',', '.') : '—' ?></td>
                                    <td class="money"><?= dinero($sum_pagado, 2) ?></td><td></td>
                                    <td class="money"><?= dinero($sum_recaudado - $sum_pagado, 2) ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php notaReporte(); ?>
                <?php firmasReporte(); ?>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
