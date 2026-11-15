<?php
// Libro auxiliar por cuenta y balance de comprobación del periodo (facturas de venta + comprobantes de ingreso y egreso)
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'libro_auxiliar');

$get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };

$cuentas = [];
foreach (nombresCuentas($db) as $codigo => $nombre) $cuentas[(string) $codigo] = $nombre;

$cuenta = $get('cuenta');
if (!isset($cuentas[$cuenta])) $cuenta = '';
$desde = fechaValida($get('desde')) ? $get('desde') : date('Y-01-01');
$hasta = fechaValida($get('hasta')) ? $get('hasta') : date('Y-m-d');
if ($hasta < $desde) list($desde, $hasta) = [$hasta, $desde];
$tercero = $get('tercero');
$preliminares = $get('preliminares') !== '';

$movimientos = sqlMovimientos($preliminares);
$filtro_tercero = '';
$params_tercero = [];
if ($tercero !== '') {
    $filtro_tercero = ' AND (m.tercero LIKE ? OR m.documento LIKE ?)';
    $params_tercero = ["%$tercero%", "%$tercero%"];
}

// ---------------- BALANCE DE COMPROBACIÓN (todas las cuentas con movimiento hasta la fecha final)
$stmt = $db->prepare("SELECT m.cuenta,
                             SUM(CASE WHEN m.fecha < ? THEN m.debito - m.credito ELSE 0 END) AS neto_anterior,
                             SUM(CASE WHEN m.fecha >= ? THEN m.debito ELSE 0 END) AS debitos,
                             SUM(CASE WHEN m.fecha >= ? THEN m.credito ELSE 0 END) AS creditos
                      FROM ($movimientos) m
                      WHERE m.fecha <= ?$filtro_tercero
                      GROUP BY m.cuenta
                      ORDER BY m.cuenta");
$stmt->execute(array_merge([$desde, $desde, $desde, $hasta], $params_tercero));
$balance = [];
$suma_debitos = 0.0;
$suma_creditos = 0.0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $signo = naturalezaCuenta($fila['cuenta']) === 'D' ? 1 : -1;
    $fila['nombre'] = $cuentas[$fila['cuenta']] ?? 'Cuenta no registrada';
    $fila['saldo_anterior'] = $signo * (float) $fila['neto_anterior'];
    $fila['saldo_final'] = $signo * ((float) $fila['neto_anterior'] + (float) $fila['debitos'] - (float) $fila['creditos']);
    $suma_debitos += (float) $fila['debitos'];
    $suma_creditos += (float) $fila['creditos'];
    $balance[] = $fila;
}
$cuadra = abs($suma_debitos - $suma_creditos) < 0.01;

// ---------------- AUXILIAR DE LA CUENTA ELEGIDA (una cuenta de 6 dígitos o un grupo de 4)
$detalle = [];
$saldo_anterior = 0.0;
$saldo_final = 0.0;
$total_debito = 0.0;
$total_credito = 0.0;
if ($cuenta !== '') {
    $signo_cuenta = naturalezaCuenta($cuenta) === 'D' ? 1 : -1;
    $stmt = $db->prepare("SELECT COALESCE(SUM(m.debito - m.credito), 0) FROM ($movimientos) m WHERE m.cuenta LIKE ? AND m.fecha < ?$filtro_tercero");
    $stmt->execute(array_merge([$cuenta . '%', $desde], $params_tercero));
    $saldo_anterior = $signo_cuenta * (float) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT m.* FROM ($movimientos) m WHERE m.cuenta LIKE ? AND m.fecha BETWEEN ? AND ?$filtro_tercero ORDER BY m.fecha, m.numero, m.debito DESC");
    $stmt->execute(array_merge([$cuenta . '%', $desde, $hasta], $params_tercero));
    $saldo_final = $saldo_anterior;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $saldo_final += $signo_cuenta * ((float) $m['debito'] - (float) $m['credito']);
        $total_debito += (float) $m['debito'];
        $total_credito += (float) $m['credito'];
        $m['saldo'] = $saldo_final;
        $detalle[] = $m;
    }
}

// ---------------- EXPORTAR A CSV (Excel en español: separador ";" y coma decimal)
if ($get('exportar') === 'csv') {
    $celda = function ($valor) { $valor = (string) $valor; return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor; };
    $numero = function ($valor) { return number_format((float) $valor, 2, ',', ''); };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($cuenta !== '' ? "libro_auxiliar_$cuenta" : 'balance_comprobacion') . "_{$desde}_{$hasta}.csv\"");
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    if ($cuenta !== '') {
        fputcsv($salida, ['Libro auxiliar', "$cuenta - {$cuentas[$cuenta]}", 'Desde', $desde, 'Hasta', $hasta], ';');
        fputcsv($salida, ['Fecha', 'Documento', 'Tercero', 'NIT/C.C.', 'Detalle', 'Cuenta', 'Débito', 'Crédito', 'Saldo'], ';');
        fputcsv($salida, ['', '', '', '', 'Saldo anterior', '', '', '', $numero($saldo_anterior)], ';');
        foreach ($detalle as $m) {
            fputcsv($salida, [$m['fecha'], $m['numero'], $celda($m['tercero']), $celda($m['documento']), $celda($m['detalle']), $m['cuenta'], $numero($m['debito']), $numero($m['credito']), $numero($m['saldo'])], ';');
        }
        fputcsv($salida, ['', '', '', '', 'Totales del periodo', '', $numero($total_debito), $numero($total_credito), $numero($saldo_final)], ';');
    } else {
        fputcsv($salida, ['Balance de comprobación', '', 'Desde', $desde, 'Hasta', $hasta], ';');
        fputcsv($salida, ['Cuenta', 'Nombre', 'Saldo anterior', 'Débitos', 'Créditos', 'Saldo final'], ';');
        foreach ($balance as $b) {
            fputcsv($salida, [$b['cuenta'], $celda($b['nombre']), $numero($b['saldo_anterior']), $numero($b['debitos']), $numero($b['creditos']), $numero($b['saldo_final'])], ';');
        }
        fputcsv($salida, ['', 'Sumas del periodo', '', $numero($suma_debitos), $numero($suma_creditos), ''], ';');
    }
    fclose($salida);
    exit;
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Libro Auxiliar NIIF";
$subtitulo = "Movimientos por cuenta, saldos y balance de comprobación";

$filtros = ['desde' => $desde, 'hasta' => $hasta, 'tercero' => $tercero, 'preliminares' => $preliminares ? '1' : ''];
$url = function (array $cambios) use ($filtros, $cuenta) {
    return 'libro_auxiliar.php?' . http_build_query(array_filter(array_merge($filtros, ['cuenta' => $cuenta], $cambios), 'strlen'));
};
$rangos = [
    'Este mes'     => [date('Y-m-01'), date('Y-m-d')],
    'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'Año actual'   => [date('Y-01-01'), date('Y-m-d')],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro Auxiliar NIIF | DataSense</title>
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
                    <div class="col-lg-4">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Cuenta (auxiliar o grupo)</label>
                        <select name="cuenta" class="form-select" id="filtroCuenta">
                            <option value="">-- Solo balance de comprobación --</option>
                            <?php foreach ($cuentas as $codigo => $nombre): $codigo = (string) $codigo; ?>
                                <option value="<?= esc($codigo) ?>" <?= $cuenta === $codigo ? 'selected' : '' ?> <?= strlen($codigo) < 6 ? 'class="fw-bold"' : '' ?>>
                                    <?= strlen($codigo) < 6 ? '' : '&nbsp;&nbsp;&nbsp;' ?><?= esc($codigo) ?> · <?= esc($nombre) ?><?= strlen($codigo) < 6 ? ' (grupo)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Desde</label>
                        <input type="date" name="desde" class="form-control" value="<?= esc($desde) ?>">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?= esc($hasta) ?>">
                    </div>
                    <div class="col-lg-2">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Tercero</label>
                        <input type="search" name="tercero" class="form-control" placeholder="Nombre o NIT" value="<?= esc($tercero) ?>">
                    </div>
                    <div class="col-lg-2">
                        <button type="submit" class="btn btn-dark w-100 fw-bold" style="background: var(--azul-sonda);"><i class="fas fa-search me-2"></i> Consultar</button>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="preliminares" value="1" id="preliminares" <?= $preliminares ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="preliminares">Incluir comprobantes sin aprobar (vista preliminar)</label>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($rangos as $etiqueta => [$r_desde, $r_hasta]): ?>
                                <a href="<?= esc($url(['desde' => $r_desde, 'hasta' => $r_hasta])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><?= $etiqueta ?></a>
                            <?php endforeach; ?>
                            <a href="<?= esc($url(['exportar' => 'csv'])) ?>" class="btn btn-sm btn-success rounded-pill" id="exportarCsv"><i class="fas fa-file-csv me-1"></i> Exportar CSV</a>
                            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark rounded-pill"><i class="fas fa-print me-1"></i> Imprimir</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($preliminares): ?>
                <div class="alert alert-warning rounded-4 border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> Vista <b>preliminar</b>: incluye comprobantes pendientes y revisados que todavía no están aprobados.</div>
            <?php endif; ?>

            <?php if ($cuenta !== ''): ?>
            <div class="panel-conta overflow-hidden mb-4" id="auxiliar">
                <div class="p-4 pb-2 d-flex flex-wrap justify-content-between align-items-end gap-2">
                    <div>
                        <h5 class="fw-bold mb-0" style="color: var(--azul-sonda);"><?= esc($cuenta) ?> · <?= esc($cuentas[$cuenta]) ?></h5>
                        <small class="text-muted">Naturaleza <?= naturalezaCuenta($cuenta) === 'D' ? 'débito' : 'crédito' ?> · del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?><?= $tercero !== '' ? ' · tercero: ' . esc($tercero) : '' ?></small>
                    </div>
                    <div class="text-end">
                        <small class="text-muted text-uppercase fw-bold">Saldo final</small>
                        <div class="fs-4 fw-bold money <?= $saldo_final < 0 ? 'saldo-negativo' : '' ?>" id="saldoFinalCuenta"><?= dinero($saldo_final, 2) ?></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-conta">
                        <thead>
                            <tr><th class="ps-4">Fecha</th><th>Documento</th><th>Tercero</th><th>Detalle</th><th>Cuenta</th><th class="text-end">Débito</th><th class="text-end">Crédito</th><th class="text-end pe-4">Saldo</th></tr>
                        </thead>
                        <tbody>
                            <tr class="fila-saldo">
                                <td colspan="7" class="ps-4 text-end">Saldo anterior al <?= date('d/m/Y', strtotime($desde)) ?></td>
                                <td class="money pe-4 <?= $saldo_anterior < 0 ? 'saldo-negativo' : '' ?>" id="saldoAnterior"><?= dinero($saldo_anterior, 2) ?></td>
                            </tr>
                            <?php if (!$detalle): ?>
                                <tr><td colspan="8" class="text-center text-muted p-4">Sin movimientos en el periodo.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($detalle as $m): ?>
                                <tr class="movimiento">
                                    <td class="ps-4 text-nowrap"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                                    <td>
                                        <a href="<?= esc(urlDocumento($m['tipo'], $m['doc_id'])) ?>" target="_blank" class="num-doc <?= esc($m['tipo']) ?>"><?= esc($m['numero']) ?></a>
                                        <?php if ($m['estado'] !== 'Aprobado'): ?><span class="estado-doc estado-<?= esc($m['estado']) ?> ms-1"><?= esc($m['estado']) ?></span><?php endif; ?>
                                    </td>
                                    <td><div class="fw-bold"><?= esc($m['tercero']) ?></div><div class="small text-muted"><?= esc($m['documento']) ?></div></td>
                                    <td class="small text-muted"><?= esc($m['detalle']) ?></td>
                                    <td class="small"><?= esc($m['cuenta']) ?></td>
                                    <td class="money"><?= $m['debito'] > 0 ? dinero($m['debito'], 2) : '-' ?></td>
                                    <td class="money"><?= $m['credito'] > 0 ? dinero($m['credito'], 2) : '-' ?></td>
                                    <td class="money pe-4 <?= $m['saldo'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($m['saldo'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-total">
                                <td colspan="5" class="ps-4 text-end text-uppercase">Totales del periodo</td>
                                <td class="money" id="totalDebitoCuenta"><?= dinero($total_debito, 2) ?></td>
                                <td class="money" id="totalCreditoCuenta"><?= dinero($total_credito, 2) ?></td>
                                <td class="money pe-4"><?= dinero($saldo_final, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="panel-conta overflow-hidden" id="balance">
                <div class="p-4 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-0" style="color: var(--azul-sonda);">Balance de comprobación</h5>
                        <small class="text-muted">Del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?> · clic en una cuenta para ver su auxiliar</small>
                    </div>
                    <?php if ($tercero !== ''): ?>
                        <span class="badge bg-light text-secondary border p-2">Filtrado por tercero: las sumas no tienen que coincidir</span>
                    <?php elseif ($cuadra): ?>
                        <span class="badge bg-success-subtle text-success border border-success p-2" id="controlSumas"><i class="fas fa-balance-scale me-1"></i> Sumas iguales: la contabilidad cuadra</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger p-2" id="controlSumas"><i class="fas fa-exclamation-triangle me-1"></i> Descuadre de <?= dinero($suma_debitos - $suma_creditos, 2) ?></span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-conta">
                        <thead><tr><th class="ps-4">Cuenta</th><th>Nombre</th><th class="text-end">Saldo anterior</th><th class="text-end">Débitos</th><th class="text-end">Créditos</th><th class="text-end pe-4">Saldo final</th></tr></thead>
                        <tbody>
                            <?php if (!$balance): ?>
                                <tr><td colspan="6" class="text-center text-muted p-4">No hay movimientos contables hasta la fecha final.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($balance as $b): ?>
                                <tr class="fila-balance" data-cuenta="<?= esc($b['cuenta']) ?>">
                                    <td class="ps-4"><a href="<?= esc($url(['cuenta' => $b['cuenta']])) ?>" class="fw-bold text-decoration-none"><?= esc($b['cuenta']) ?></a></td>
                                    <td><?= esc($b['nombre']) ?></td>
                                    <td class="money <?= $b['saldo_anterior'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($b['saldo_anterior'], 2) ?></td>
                                    <td class="money"><?= dinero($b['debitos'], 2) ?></td>
                                    <td class="money"><?= dinero($b['creditos'], 2) ?></td>
                                    <td class="money pe-4 <?= $b['saldo_final'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($b['saldo_final'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-total">
                                <td colspan="3" class="ps-4 text-end text-uppercase">Sumas del periodo</td>
                                <td class="money" id="sumaDebitos"><?= dinero($suma_debitos, 2) ?></td>
                                <td class="money" id="sumaCreditos"><?= dinero($suma_creditos, 2) ?></td>
                                <td class="pe-4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-0 no-print">
                <i class="fas fa-info-circle me-1"></i> Las facturas de venta se causan solas (Clientes 130505 / Ingresos 415540 / IVA generado 240805).
                Los comprobantes de ingreso y egreso cuentan desde su aprobación; los anulados nunca suman.
            </p>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
