<?php
// Impuestos: liquidación de IVA por periodo (borrador del formulario 300), retenciones en la fuente del mes
// (borrador del formulario 350) y certificados de retención por tercero. Todo sale de facturas y documentos aprobados.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/impuestos.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'impuestos');

$get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };
$vista = in_array($get('vista'), ['iva', 'retenciones', 'certificados'], true) ? $get('vista') : 'iva';
$anio = preg_match('/^\d{4}$/', $get('anio')) ? (int) $get('anio') : (int) date('Y');
$anios = range((int) date('Y'), (int) date('Y') - 4);

if ($vista === 'iva') {
    $periodicidad = $get('periodicidad') === 'cuatrimestral' ? 'cuatrimestral' : 'bimestral';
    $meses_periodo = $periodicidad === 'cuatrimestral' ? 4 : 2;
    $numero = (int) $get('periodo');
    if ($numero < 1 || $numero > 12 / $meses_periodo) {
        $numero = $anio === (int) date('Y') ? (int) ceil((int) date('n') / $meses_periodo) : 1;
    }
    list($desde, $hasta) = periodoIva($anio, $periodicidad, $numero);
    $iva = liquidacionIva($db, $desde, $hasta);

    if ($get('exportar') === 'csv') {
        $filas = [['Liquidación de IVA', "Del $desde al $hasta"], ['Concepto', 'Base', 'IVA']];
        foreach ($iva['ventas'] as $v) $filas[] = ['Ventas al ' . (float) $v['tarifa'] . '%', (float) $v['base'], (float) $v['iva']];
        $filas[] = ['(+) IVA en notas débito', '', $iva['iva_notas_debito']];
        $filas[] = ['(-) IVA reversado en notas crédito', '', $iva['iva_notas_credito']];
        $filas[] = ['(+/-) Ajustes en notas contables', '', $iva['iva_ajustes']];
        $filas[] = ['Total IVA generado', '', $iva['iva_generado']];
        $filas[] = ['IVA descontable en compras y gastos', $iva['base_compras'], $iva['iva_descontable']];
        $filas[] = [$iva['saldo'] >= 0 ? 'Saldo a pagar' : 'Saldo a favor', '', abs($iva['saldo'])];
        $filas[] = [''];
        $filas[] = ['Documento', 'Fecha', 'Tercero', 'NIT', 'IVA generado', 'IVA descontable'];
        foreach ($iva['documentos'] as $d) $filas[] = [$d['numero'], $d['fecha'], $d['tercero_nombre'], $d['tercero_documento'], (float) $d['iva_generado'], (float) $d['iva_descontable']];
        enviarCsv("iva_{$anio}_{$periodicidad}_$numero.csv", $filas);
        exit;
    }
}

if ($vista === 'retenciones') {
    $mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $get('mes')) ? $get('mes') : date('Y-m');
    $desde = "$mes-01";
    $hasta = date('Y-m-t', strtotime($desde));
    $retenciones = retencionesPracticadas($db, $desde, $hasta);
    $por_concepto = [];
    foreach ($retenciones as $r) {
        $cuenta = (string) $r['cuenta'];
        if (!isset($por_concepto[$cuenta])) $por_concepto[$cuenta] = ['concepto' => $r['concepto'], 'base' => 0.0, 'retenido' => 0.0, 'documentos' => 0];
        $por_concepto[$cuenta]['base'] += (float) $r['base'];
        $por_concepto[$cuenta]['retenido'] += (float) $r['retenido'];
        $por_concepto[$cuenta]['documentos']++;
    }
    ksort($por_concepto);
    $total_base = round(array_sum(array_column($por_concepto, 'base')), 2);
    $total_retenido = round(array_sum(array_column($por_concepto, 'retenido')), 2);
    $libro_retenciones = retencionesSegunLibro($db, $desde, $hasta);
    $nos_practicaron = retencionesQueNosPracticaron($db, $desde, $hasta);
    $total_nos_practicaron = round(array_sum(array_column($nos_practicaron, 'valor')), 2);

    if ($get('exportar') === 'csv') {
        $filas = [['Retenciones en la fuente practicadas', "Del $desde al $hasta"], ['Cuenta', 'Concepto', 'Documentos', 'Base', 'Retenido']];
        foreach ($por_concepto as $cuenta => $c) $filas[] = [(string) $cuenta, $c['concepto'], $c['documentos'], round($c['base'], 2), round($c['retenido'], 2)];
        $filas[] = ['Total', '', '', $total_base, $total_retenido];
        $filas[] = [''];
        $filas[] = ['Documento', 'Fecha', 'Tercero', 'NIT', 'Concepto', 'Base', 'Retenido'];
        foreach ($retenciones as $r) $filas[] = [$r['numero'], $r['fecha'], $r['tercero_nombre'], $r['tercero_documento'], $r['concepto'], (float) $r['base'], (float) $r['retenido']];
        enviarCsv("retenciones_$mes.csv", $filas);
        exit;
    }
}

if ($vista === 'certificados') {
    $terceros_retenidos = [];
    foreach (retencionesPracticadas($db, "$anio-01-01", "$anio-12-31") as $r) {
        $documento = (string) $r['tercero_documento'];
        if ($documento === '') continue;
        if (!isset($terceros_retenidos[$documento])) {
            $terceros_retenidos[$documento] = ['nombre' => $r['tercero_nombre'], 'base' => 0.0, 'retenido' => 0.0, 'conceptos' => []];
        }
        $terceros_retenidos[$documento]['base'] += (float) $r['base'];
        $terceros_retenidos[$documento]['retenido'] += (float) $r['retenido'];
        $terceros_retenidos[$documento]['conceptos'][$r['concepto']] = true;
    }
    uasort($terceros_retenidos, function ($a, $b) { return $b['retenido'] <=> $a['retenido']; });
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Impuestos y Retenciones";
$subtitulo = "IVA por periodo, retenciones en la fuente y certificados para proveedores";
$aviso = tomarAviso();
$url = function (array $cambios) use ($get) {
    $base = array_filter(['vista' => $get('vista'), 'anio' => $get('anio'), 'periodicidad' => $get('periodicidad'), 'periodo' => $get('periodo'), 'mes' => $get('mes')], 'strlen');
    return 'impuestos.php?' . http_build_query(array_merge($base, $cambios));
};
$porcentaje = function ($parte, $total) { return $total > 0 ? number_format($parte * 100 / $total, 2, ',', '.') . '%' : '—'; };
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impuestos | DataSense</title>
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
            <?php if ($aviso): ?>
                <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4 no-print" role="alert">
                    <i class="fas fa-info-circle me-2"></i> <?= esc($aviso['texto']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-4 pestanas-doc no-print">
                <a href="impuestos.php?vista=iva" id="tabIva" class="btn rounded-pill fw-bold px-4 <?= $vista === 'iva' ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-percent me-2"></i> IVA</a>
                <a href="impuestos.php?vista=retenciones" id="tabRetenciones" class="btn rounded-pill fw-bold px-4 <?= $vista === 'retenciones' ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-hand-holding-usd me-2"></i> Retenciones en la fuente</a>
                <a href="impuestos.php?vista=certificados" id="tabCertificados" class="btn rounded-pill fw-bold px-4 <?= $vista === 'certificados' ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-certificate me-2"></i> Certificados de retención</a>
            </div>

<?php if ($vista === 'iva'): ?>
            <div class="panel-conta p-4 mb-4 no-print">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="vista" value="iva">
                    <div class="col-md-2"><label class="small fw-bold text-muted mb-1">Año</label>
                        <select name="anio" class="form-select"><?php foreach ($anios as $a): ?><option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="small fw-bold text-muted mb-1">Periodicidad</label>
                        <select name="periodicidad" class="form-select" onchange="this.form.periodo.value = ''; this.form.submit();">
                            <option value="bimestral" <?= $periodicidad === 'bimestral' ? 'selected' : '' ?>>Bimestral</option>
                            <option value="cuatrimestral" <?= $periodicidad === 'cuatrimestral' ? 'selected' : '' ?>>Cuatrimestral</option>
                        </select></div>
                    <div class="col-md-3"><label class="small fw-bold text-muted mb-1">Periodo</label>
                        <select name="periodo" class="form-select">
                            <?php foreach (NOMBRES_PERIODOS_IVA[$periodicidad] as $i => $nombre): ?><option value="<?= $i + 1 ?>" <?= $i + 1 === $numero ? 'selected' : '' ?>><?= $i + 1 ?> · <?= $nombre ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold" style="background: var(--azul-sonda);">Generar</button></div>
                    <div class="col-md-2 d-flex gap-2">
                        <a href="<?= esc($url(['exportar' => 'csv', 'vista' => 'iva', 'anio' => $anio, 'periodicidad' => $periodicidad, 'periodo' => $numero])) ?>" class="btn btn-sm btn-success rounded-pill" id="exportarIva"><i class="fas fa-file-csv"></i> CSV</a>
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark rounded-pill"><i class="fas fa-print"></i></button>
                    </div>
                </form>
            </div>

            <div class="reporte-papel" id="vistaIva">
                <?php encabezadoReporte('Liquidación de IVA', 'Periodo ' . $numero . ' (' . NOMBRES_PERIODOS_IVA[$periodicidad][$numero - 1] . ' de ' . $anio . ') · del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta))); ?>
                <div class="text-end mb-2">
                    <?php if ($iva['cuadra']): ?>
                        <span class="badge bg-success-subtle text-success border border-success p-2" id="controlIva"><i class="fas fa-check-circle me-1"></i> Coincide con el movimiento de las cuentas de IVA del libro</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger p-2" id="controlIva"><i class="fas fa-exclamation-triangle me-1"></i> No coincide con el libro: generado <?= dinero($iva['libro_generado'], 2) ?>, descontable <?= dinero($iva['libro_descontable'], 2) ?></span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaIva">
                        <thead><tr><th>Concepto</th><th class="text-end">Base</th><th class="text-end">IVA</th></tr></thead>
                        <tbody>
                            <tr class="seccion"><td colspan="3">IVA generado</td></tr>
                            <?php if (!$iva['ventas']): ?><tr><td class="small text-muted" colspan="3">Sin facturas de venta en el periodo</td></tr><?php endif; ?>
                            <?php foreach ($iva['ventas'] as $v): ?>
                                <tr class="cuenta" data-tarifa="<?= (float) $v['tarifa'] ?>">
                                    <td><?= (float) $v['tarifa'] > 0 ? 'Ventas gravadas al ' . (float) $v['tarifa'] . '%' : 'Ventas exentas o excluidas (0%)' ?> <span class="text-muted small">(<?= (int) $v['documentos'] ?> factura<?= (int) $v['documentos'] === 1 ? '' : 's' ?>)</span></td>
                                    <td class="money"><?= dinero($v['base'], 2) ?></td><td class="money"><?= dinero($v['iva'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="cuenta"><td>(+) IVA en notas débito</td><td></td><td class="money" data-iva="notas_debito" data-valor="<?= $iva['iva_notas_debito'] ?>"><?= dinero($iva['iva_notas_debito'], 2) ?></td></tr>
                            <tr class="cuenta"><td>(−) IVA reversado en notas crédito (devoluciones y descuentos)</td><td></td><td class="money" data-iva="notas_credito" data-valor="<?= $iva['iva_notas_credito'] ?>"><?= dinero($iva['iva_notas_credito'], 2) ?></td></tr>
                            <?php if (abs($iva['iva_ajustes']) >= 0.005): ?>
                                <tr class="cuenta"><td>(±) Ajustes en notas contables</td><td></td><td class="money"><?= dinero($iva['iva_ajustes'], 2) ?></td></tr>
                            <?php endif; ?>
                            <tr class="subtotal"><td>Total IVA generado</td><td class="money"><?= dinero($iva['base_facturas'], 2) ?></td><td class="money" id="ivaGenerado" data-valor="<?= $iva['iva_generado'] ?>"><?= dinero($iva['iva_generado'], 2) ?></td></tr>
                            <tr class="seccion"><td colspan="3">IVA descontable</td></tr>
                            <tr class="cuenta"><td>Compras y gastos con IVA <span class="text-muted small">(<?= (int) $iva['documentos_compra'] ?> documento<?= (int) $iva['documentos_compra'] === 1 ? '' : 's' ?>)</span></td>
                                <td class="money"><?= dinero($iva['base_compras'], 2) ?></td><td class="money" id="ivaDescontable" data-valor="<?= $iva['iva_descontable'] ?>"><?= dinero($iva['iva_descontable'], 2) ?></td></tr>
                            <tr class="resultado-final"><td><?= $iva['saldo'] >= 0 ? 'SALDO A PAGAR' : 'SALDO A FAVOR' ?></td><td></td><td class="money" id="saldoIva" data-valor="<?= $iva['saldo'] ?>"><?= dinero(abs($iva['saldo']), 2) ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <h6 class="fw-bold text-uppercase mt-5 mb-2" style="color: #0A2E4F;">Documentos con IVA diferentes a las facturas de venta</h6>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="documentosIva">
                        <thead><tr><th>Documento</th><th>Fecha</th><th>Tercero</th><th class="text-end">Base compra</th><th class="text-end">IVA generado</th><th class="text-end">IVA descontable</th></tr></thead>
                        <tbody>
                        <?php if (!$iva['documentos']): ?><tr><td colspan="6" class="text-center text-muted py-3">Sin documentos con IVA en el periodo.</td></tr><?php endif; ?>
                        <?php foreach ($iva['documentos'] as $d): ?>
                            <tr>
                                <td><a class="num-doc <?= esc($d['tipo']) ?>" href="<?= esc(urlDocumento($d['tipo'], $d['id'])) ?>"><?= esc($d['numero']) ?></a></td>
                                <td class="small"><?= date('d/m/Y', strtotime($d['fecha'])) ?></td>
                                <td class="small"><?= esc($d['tercero_nombre']) ?><div class="text-muted"><?= esc($d['tercero_documento']) ?></div></td>
                                <td class="money"><?= abs((float) $d['base_compra']) >= 0.005 ? dinero($d['base_compra'], 2) : '—' ?></td>
                                <td class="money"><?= abs((float) $d['iva_generado']) >= 0.005 ? dinero($d['iva_generado'], 2) : '—' ?></td>
                                <td class="money"><?= abs((float) $d['iva_descontable']) >= 0.005 ? dinero($d['iva_descontable'], 2) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="reporte-nota">Borrador informativo para preparar el formulario 300 de la DIAN, calculado con las facturas no anuladas y los documentos aprobados del periodo. Verifica la periodicidad que te corresponde, los ingresos no gravados y la proporcionalidad del IVA descontable con tu contador antes de declarar.</p>
            </div>

<?php elseif ($vista === 'retenciones'): ?>
            <div class="panel-conta p-4 mb-4 no-print">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="vista" value="retenciones">
                    <div class="col-md-3"><label class="small fw-bold text-muted mb-1">Mes</label><input type="month" name="mes" class="form-control" value="<?= esc($mes) ?>"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold" style="background: var(--azul-sonda);">Generar</button></div>
                    <div class="col-md-3 d-flex gap-2">
                        <a href="<?= esc($url(['exportar' => 'csv', 'vista' => 'retenciones', 'mes' => $mes])) ?>" class="btn btn-sm btn-success rounded-pill" id="exportarRetenciones"><i class="fas fa-file-csv"></i> CSV</a>
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark rounded-pill"><i class="fas fa-print"></i></button>
                    </div>
                </form>
            </div>

            <div class="reporte-papel" id="vistaRetenciones">
                <?php encabezadoReporte('Retenciones en la fuente practicadas', 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta))); ?>
                <div class="text-end mb-2">
                    <?php if (abs($libro_retenciones - $total_retenido) < 0.01): ?>
                        <span class="badge bg-success-subtle text-success border border-success p-2" id="controlRetenciones"><i class="fas fa-check-circle me-1"></i> Coincide con los créditos a retención en la fuente (2365) del libro</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger p-2" id="controlRetenciones"><i class="fas fa-exclamation-triangle me-1"></i> El libro muestra <?= dinero($libro_retenciones, 2) ?> en 2365</span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaRetenciones">
                        <thead><tr><th>Cuenta</th><th>Concepto</th><th class="text-center">Documentos</th><th class="text-end">Base sujeta</th><th class="text-end">Tarifa efectiva</th><th class="text-end">Retenido</th></tr></thead>
                        <tbody>
                        <?php if (!$por_concepto): ?><tr><td colspan="6" class="text-center text-muted py-3">No hubo retenciones practicadas en el mes.</td></tr><?php endif; ?>
                        <?php foreach ($por_concepto as $cuenta => $c): ?>
                            <tr class="cuenta fila-concepto" data-cuenta="<?= esc($cuenta) ?>" data-base="<?= round($c['base'], 2) ?>" data-retenido="<?= round($c['retenido'], 2) ?>">
                                <td class="small text-muted"><?= esc($cuenta) ?></td><td><?= esc($c['concepto']) ?></td><td class="text-center"><?= (int) $c['documentos'] ?></td>
                                <td class="money"><?= dinero($c['base'], 2) ?></td><td class="text-end small"><?= $porcentaje($c['retenido'], $c['base']) ?></td><td class="money"><?= dinero($c['retenido'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="resultado-final"><td colspan="3">TOTAL RETENCIONES A DECLARAR Y PAGAR</td><td class="money"><?= dinero($total_base, 2) ?></td><td></td><td class="money" id="totalRetenido" data-valor="<?= $total_retenido ?>"><?= dinero($total_retenido, 2) ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <h6 class="fw-bold text-uppercase mt-5 mb-2" style="color: #0A2E4F;">Detalle por documento</h6>
                <div class="table-responsive">
                    <table class="tabla-reporte">
                        <thead><tr><th>Documento</th><th>Fecha</th><th>Tercero</th><th>Concepto</th><th class="text-end">Base</th><th class="text-end">Retenido</th></tr></thead>
                        <tbody>
                        <?php foreach ($retenciones as $r): ?>
                            <tr>
                                <td><a class="num-doc <?= esc($r['tipo']) ?>" href="<?= esc(urlDocumento($r['tipo'], $r['id'])) ?>"><?= esc($r['numero']) ?></a></td>
                                <td class="small"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
                                <td class="small"><?= esc($r['tercero_nombre']) ?><div class="text-muted"><?= esc($r['tercero_documento']) ?></div></td>
                                <td class="small"><?= esc($r['concepto']) ?></td>
                                <td class="money"><?= dinero($r['base'], 2) ?></td><td class="money"><?= dinero($r['retenido'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h6 class="fw-bold text-uppercase mt-5 mb-2" style="color: #0A2E4F;">Retenciones que te practicaron los clientes (anticipo de renta)</h6>
                <div class="table-responsive">
                    <table class="tabla-reporte" id="tablaNosPracticaron">
                        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th class="text-end">Valor</th></tr></thead>
                        <tbody>
                        <?php if (!$nos_practicaron): ?><tr><td colspan="4" class="text-center text-muted py-3">Ningún cliente practicó retención en el mes.</td></tr><?php endif; ?>
                        <?php foreach ($nos_practicaron as $n): ?>
                            <tr>
                                <td><a class="num-doc <?= esc($n['tipo']) ?>" href="<?= esc(urlDocumento($n['tipo'], $n['id'])) ?>"><?= esc($n['numero']) ?></a></td>
                                <td class="small"><?= date('d/m/Y', strtotime($n['fecha'])) ?></td>
                                <td class="small"><?= esc($n['tercero_nombre']) ?></td><td class="money"><?= dinero($n['valor'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="subtotal"><td colspan="3">Total</td><td class="money" id="totalNosPracticaron" data-valor="<?= $total_nos_practicaron ?>"><?= dinero($total_nos_practicaron, 2) ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="reporte-nota">Borrador informativo para el formulario 350 (retenciones en la fuente) con los documentos aprobados del mes. La base se toma del gasto, costo o activo de cada documento. Las retenciones que te practicaron son un anticipo del impuesto de renta y se descuentan en la declaración anual.</p>
            </div>

<?php else: ?>
            <div class="panel-conta p-4 mb-4 no-print">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="vista" value="certificados">
                    <div class="col-md-2"><label class="small fw-bold text-muted mb-1">Año gravable</label>
                        <select name="anio" class="form-select"><?php foreach ($anios as $a): ?><option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold" style="background: var(--azul-sonda);">Consultar</button></div>
                </form>
            </div>

            <div class="panel-conta overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 tabla-conta" id="tablaCertificados">
                        <thead><tr><th class="ps-4">Tercero</th><th>NIT / C.C.</th><th>Conceptos</th><th class="text-end">Base</th><th class="text-end">Retenido en <?= $anio ?></th><th class="text-center pe-4">Certificado</th></tr></thead>
                        <tbody>
                        <?php if (!$terceros_retenidos): ?><tr><td colspan="6" class="text-center text-muted p-5">No hubo retenciones practicadas en <?= $anio ?>.</td></tr><?php endif; ?>
                        <?php foreach ($terceros_retenidos as $documento => $t): ?>
                            <tr class="fila-certificado" data-documento="<?= esc($documento) ?>" data-retenido="<?= round($t['retenido'], 2) ?>">
                                <td class="ps-4 fw-bold"><?= esc($t['nombre']) ?></td>
                                <td><?= esc($documento) ?></td>
                                <td class="small"><?= esc(implode(', ', array_keys($t['conceptos']))) ?></td>
                                <td class="money"><?= dinero($t['base'], 2) ?></td>
                                <td class="money"><?= dinero($t['retenido'], 2) ?></td>
                                <td class="text-center pe-4"><a href="certificado_retencion.php?<?= esc(http_build_query(['documento' => $documento, 'anio' => $anio])) ?>" class="btn btn-sm btn-outline-primary rounded-pill ver-certificado"><i class="fas fa-certificate me-1"></i> Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
<?php endif; ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
