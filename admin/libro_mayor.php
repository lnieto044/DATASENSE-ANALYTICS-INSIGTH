<?php
// Libro mayor y balances (saldos por cuenta de mayor) y libro diario (asientos por documento), con lo aprobado
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/reportes.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'libro_mayor');

$get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };
$vista = $get('vista') === 'diario' ? 'diario' : 'mayor';
$desde = fechaValida($get('desde')) ? $get('desde') : date('Y-m-01');
$hasta = fechaValida($get('hasta')) ? $get('hasta') : date('Y-m-t');
if ($hasta < $desde) list($desde, $hasta) = [$hasta, $desde];
$buscar = mb_substr($get('buscar'), 0, 80);
$cuentas = nombresCuentas($db);

if ($vista === 'mayor') {
    // Saldos por cuenta de mayor (4 dígitos) agrupados por clase
    $clases = [];
    $sumas = ['anterior' => 0.0, 'debitos' => 0.0, 'creditos' => 0.0];
    foreach (saldosPorCuenta($db, $desde, $hasta, 4) as $f) {
        $cuenta = $f['cuenta'];
        $nombre_cuenta = nombreCuenta($db, $cuenta);
        if ($buscar !== '' && mb_stripos("$cuenta $nombre_cuenta", $buscar) === false) continue;
        $signo = naturalezaCuenta($cuenta) === 'D' ? 1 : -1;
        $anterior = (float) $f['neto_anterior'];
        $debitos = (float) $f['debitos'];
        $creditos = (float) $f['creditos'];
        $clases[substr($cuenta, 0, 1)][] = [
            'cuenta' => $cuenta, 'nombre' => $nombre_cuenta, 'naturaleza' => $signo > 0 ? 'Débito' : 'Crédito',
            'saldo_anterior' => $signo * $anterior, 'debitos' => $debitos, 'creditos' => $creditos, 'saldo_final' => $signo * ($anterior + $debitos - $creditos),
        ];
        $sumas['anterior'] += $anterior;
        $sumas['debitos'] += $debitos;
        $sumas['creditos'] += $creditos;
    }
    ksort($clases);
    $cuadra = abs($sumas['debitos'] - $sumas['creditos']) < 0.01 && abs($sumas['anterior']) < 0.01;
} else {
    // Libro diario: cada documento aprobado es un asiento con sus partidas
    $stmt = $db->prepare("SELECT m.* FROM (" . sqlMovimientos(false) . ") m WHERE m.fecha BETWEEN ? AND ? ORDER BY m.fecha, m.tipo, m.numero, m.debito DESC LIMIT 5000");
    $stmt->execute([$desde, $hasta]);
    $asientos = [];
    $sumas = ['debitos' => 0.0, 'creditos' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $m['nombre_cuenta'] = $cuentas[$m['cuenta']] ?? nombreCuenta($db, $m['cuenta']);
        if ($buscar !== '' && mb_stripos(implode(' ', [$m['numero'], $m['tercero'], $m['documento'], $m['detalle'], $m['cuenta'], $m['nombre_cuenta']]), $buscar) === false) continue;
        $clave = $m['tipo'] . '-' . $m['doc_id'];
        if (!isset($asientos[$clave])) {
            $asientos[$clave] = ['tipo' => $m['tipo'], 'doc_id' => $m['doc_id'], 'numero' => $m['numero'], 'fecha' => $m['fecha'], 'tercero' => $m['tercero'], 'partidas' => [], 'debitos' => 0.0, 'creditos' => 0.0];
        }
        $asientos[$clave]['partidas'][] = $m;
        $asientos[$clave]['debitos'] += (float) $m['debito'];
        $asientos[$clave]['creditos'] += (float) $m['credito'];
        $sumas['debitos'] += (float) $m['debito'];
        $sumas['creditos'] += (float) $m['credito'];
    }
    $cuadra = abs($sumas['debitos'] - $sumas['creditos']) < 0.01;
}

if ($get('exportar') === 'csv') {
    if ($vista === 'mayor') {
        $filas = [['Libro mayor y balances', "Del $desde al $hasta"], ['Cuenta', 'Nombre', 'Naturaleza', 'Saldo anterior', 'Débitos', 'Créditos', 'Saldo final']];
        foreach ($clases as $filas_clase) {
            foreach ($filas_clase as $r) $filas[] = [$r['cuenta'], $r['nombre'], $r['naturaleza'], $r['saldo_anterior'], $r['debitos'], $r['creditos'], $r['saldo_final']];
        }
        $filas[] = ['', 'Sumas del periodo', '', '', $sumas['debitos'], $sumas['creditos'], ''];
    } else {
        $filas = [['Libro diario', "Del $desde al $hasta"], ['Fecha', 'Documento', 'Tercero', 'Cuenta', 'Nombre de la cuenta', 'Detalle', 'Débito', 'Crédito']];
        foreach ($asientos as $asiento) {
            foreach ($asiento['partidas'] as $p) $filas[] = [$p['fecha'], $p['numero'], $p['tercero'], $p['cuenta'], $p['nombre_cuenta'], $p['detalle'], (float) $p['debito'], (float) $p['credito']];
        }
        $filas[] = ['', '', '', '', '', 'Sumas del periodo', $sumas['debitos'], $sumas['creditos']];
    }
    enviarCsv("libro_{$vista}_{$desde}_{$hasta}.csv", $filas);
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Libro Mayor";
$subtitulo = "Saldos por cuenta de mayor y asientos del libro diario";

$url = function (array $cambios) use ($vista, $desde, $hasta, $buscar) {
    return 'libro_mayor.php?' . http_build_query(array_filter(array_merge(['vista' => $vista, 'desde' => $desde, 'hasta' => $hasta, 'buscar' => $buscar], $cambios), 'strlen'));
};
$rangos = [
    'Este mes'     => [date('Y-m-01'), date('Y-m-t')],
    'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'Año actual'   => [date('Y-01-01'), date('Y-12-31')],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro Mayor | DataSense</title>
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
                <ul class="nav nav-pills mb-3 gap-2">
                    <li class="nav-item"><a class="nav-link rounded-pill <?= $vista === 'mayor' ? 'active' : 'border' ?>" id="vistaMayor" href="<?= esc($url(['vista' => 'mayor'])) ?>"><i class="fas fa-book-reader me-1"></i> Mayor y balances</a></li>
                    <li class="nav-item"><a class="nav-link rounded-pill <?= $vista === 'diario' ? 'active' : 'border' ?>" id="vistaDiario" href="<?= esc($url(['vista' => 'diario'])) ?>"><i class="fas fa-stream me-1"></i> Libro diario</a></li>
                </ul>
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="vista" value="<?= $vista ?>">
                    <div class="col-6 col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Desde</label>
                        <input type="date" name="desde" class="form-control" value="<?= esc($desde) ?>">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?= esc($hasta) ?>">
                    </div>
                    <div class="col-lg-4">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Buscar</label>
                        <input type="search" name="buscar" class="form-control" value="<?= esc($buscar) ?>" placeholder="<?= $vista === 'mayor' ? 'Código o nombre de la cuenta' : 'Documento, tercero, cuenta o detalle' ?>">
                    </div>
                    <div class="col-lg-2">
                        <button type="submit" class="btn btn-dark w-100 fw-bold" style="background: var(--azul-sonda);"><i class="fas fa-search me-1"></i> Consultar</button>
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
                <?php encabezadoReporte($vista === 'mayor' ? 'Libro Mayor y Balances' : 'Libro Diario', 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)) . ($buscar !== '' ? ' · filtro: ' . $buscar : '')); ?>

                <div class="text-end mb-2 no-print">
                    <?php if ($buscar !== ''): ?>
                        <span class="badge bg-light text-secondary border p-2" id="controlMayor">Filtrado: las sumas solo incluyen lo que coincide con la búsqueda</span>
                    <?php elseif ($cuadra): ?>
                        <span class="badge bg-success-subtle text-success border border-success p-2" id="controlMayor"><i class="fas fa-balance-scale me-1"></i> Sumas iguales: la contabilidad cuadra</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger p-2" id="controlMayor"><i class="fas fa-exclamation-triangle me-1"></i> Descuadre de <?= dinero($sumas['debitos'] - $sumas['creditos'], 2) ?></span>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                <?php if ($vista === 'mayor'): ?>
                    <table class="tabla-reporte" id="tablaMayor">
                        <thead>
                            <tr><th style="width: 9%;">Cuenta</th><th>Nombre</th><th>Naturaleza</th><th class="text-end">Saldo anterior</th><th class="text-end">Débitos</th><th class="text-end">Créditos</th><th class="text-end">Saldo final</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$clases): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No hay cuentas con saldo o movimientos hasta la fecha final.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($clases as $clase => $filas_clase): $deb_clase = array_sum(array_column($filas_clase, 'debitos')); $cre_clase = array_sum(array_column($filas_clase, 'creditos')); ?>
                                <tr class="seccion"><td colspan="7">Clase <?= $clase ?> · <?= esc(CLASES_PUC[$clase] ?? '') ?></td></tr>
                                <?php foreach ($filas_clase as $r): ?>
                                    <tr class="cuenta fila-mayor" data-cuenta="<?= esc($r['cuenta']) ?>" data-saldo="<?= round($r['saldo_final'], 2) ?>">
                                        <td><a href="libro_auxiliar.php?<?= esc(http_build_query(['cuenta' => $r['cuenta'], 'desde' => $desde, 'hasta' => $hasta])) ?>" class="fw-bold"><?= esc($r['cuenta']) ?></a></td>
                                        <td><?= esc($r['nombre']) ?></td>
                                        <td class="small text-muted"><?= $r['naturaleza'] ?></td>
                                        <td class="money <?= $r['saldo_anterior'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($r['saldo_anterior'], 2) ?></td>
                                        <td class="money"><?= $r['debitos'] > 0 ? dinero($r['debitos'], 2) : '-' ?></td>
                                        <td class="money"><?= $r['creditos'] > 0 ? dinero($r['creditos'], 2) : '-' ?></td>
                                        <td class="money <?= $r['saldo_final'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($r['saldo_final'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="subtotal"><td></td><td colspan="3">Movimientos de la clase <?= $clase ?></td><td class="money"><?= dinero($deb_clase, 2) ?></td><td class="money"><?= dinero($cre_clase, 2) ?></td><td></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="resultado-final">
                                <td></td><td colspan="3" class="text-uppercase">Sumas iguales del periodo</td>
                                <td class="money" id="sumaDebitos" data-valor="<?= round($sumas['debitos'], 2) ?>"><?= dinero($sumas['debitos'], 2) ?></td>
                                <td class="money" id="sumaCreditos" data-valor="<?= round($sumas['creditos'], 2) ?>"><?= dinero($sumas['creditos'], 2) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php else: ?>
                    <table class="tabla-reporte" id="tablaDiario">
                        <thead>
                            <tr><th style="width: 9%;">Cuenta</th><th>Nombre de la cuenta</th><th>Detalle</th><th class="text-end" style="width: 15%;">Débito</th><th class="text-end" style="width: 15%;">Crédito</th></tr>
                        </thead>
                        <?php if (!$asientos): ?>
                            <tbody><tr><td colspan="5" class="text-center text-muted py-4">No hay asientos aprobados en el periodo.</td></tr></tbody>
                        <?php endif; ?>
                        <?php foreach ($asientos as $asiento): ?>
                            <tbody class="asiento" data-numero="<?= esc($asiento['numero']) ?>">
                                <tr class="seccion">
                                    <td colspan="5">
                                        <?= date('d/m/Y', strtotime($asiento['fecha'])) ?> ·
                                        <a href="<?= esc(urlDocumento($asiento['tipo'], $asiento['doc_id'])) ?>" target="_blank" class="num-doc <?= esc($asiento['tipo']) ?> text-none"><?= esc($asiento['numero']) ?></a>
                                        · <span class="text-dark"><?= esc($asiento['tercero']) ?></span>
                                    </td>
                                </tr>
                                <?php foreach ($asiento['partidas'] as $p): ?>
                                    <tr class="cuenta">
                                        <td class="small"><?= esc($p['cuenta']) ?></td>
                                        <td><?= esc($p['nombre_cuenta']) ?></td>
                                        <td class="small text-muted"><?= esc($p['detalle']) ?></td>
                                        <td class="money"><?= $p['debito'] > 0 ? dinero($p['debito'], 2) : '-' ?></td>
                                        <td class="money"><?= $p['credito'] > 0 ? dinero($p['credito'], 2) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="subtotal">
                                    <td></td><td colspan="2" class="small">Sumas del asiento <?= abs($asiento['debitos'] - $asiento['creditos']) < 0.01 ? '<i class="fas fa-check text-success"></i>' : '<span class="text-danger">(parcial por el filtro)</span>' ?></td>
                                    <td class="money"><?= dinero($asiento['debitos'], 2) ?></td><td class="money"><?= dinero($asiento['creditos'], 2) ?></td>
                                </tr>
                            </tbody>
                        <?php endforeach; ?>
                        <tfoot>
                            <tr class="resultado-final">
                                <td></td><td colspan="2" class="text-uppercase">Sumas iguales del periodo (<?= count($asientos) ?> asientos)</td>
                                <td class="money" id="sumaDebitos" data-valor="<?= round($sumas['debitos'], 2) ?>"><?= dinero($sumas['debitos'], 2) ?></td>
                                <td class="money" id="sumaCreditos" data-valor="<?= round($sumas['creditos'], 2) ?>"><?= dinero($sumas['creditos'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
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
