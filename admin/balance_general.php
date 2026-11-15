<?php
// Estado de situación financiera (balance general) a una fecha de corte, con comparativo, ecuación contable e indicadores
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/reportes.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'balance_general');

$get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };
$corte = fechaValida($get('corte')) ? $get('corte') : date('Y-m-d');
$comparar = $get('comparar') !== 'no';
$corte_anterior = fechaValida($get('corte_anterior')) ? $get('corte_anterior') : ((int) substr($corte, 0, 4) - 1) . '-12-31';

// Estructura del balance: bloque => sub-bloque => grupos PUC
$estructura = [
    'Activo'     => ['Activo corriente' => ['11', '12', '13', '14'], 'Activo no corriente' => ['15', '16', '17', '18', '19']],
    'Pasivo'     => ['Pasivo corriente' => ['21', '22', '23', '24', '25', '26'], 'Pasivo no corriente' => ['27', '28', '29']],
    'Patrimonio' => ['Patrimonio' => ['31', '32', '33', '34', '35', '36', '37', '38']],
];
$nombres_calculados = ['3605' => 'Utilidad (o pérdida) del ejercicio', '3705' => 'Utilidades (o pérdidas) de ejercicios anteriores'];

// Saldos a una fecha por cuenta de mayor (el disponible se detalla a 6 dígitos: caja, bancos, PayPal, Zelle, USDT...)
$situacion = function ($fecha) use ($db, $estructura) {
    $saldos = [];
    foreach (saldosPorCuenta($db, null, $fecha, 6, [1, 2, 3]) as $fila) {
        $cuenta = substr($fila['cuenta'], 0, 2) === '11' ? $fila['cuenta'] : substr($fila['cuenta'], 0, 4);
        $neto = (float) $fila['debitos'] - (float) $fila['creditos'];
        $saldos[$cuenta] = ($saldos[$cuenta] ?? 0) + (substr($cuenta, 0, 1) === '1' ? $neto : -$neto);
    }
    // Resultados: no hay cierre contable, así que se calculan con los ingresos, costos y gastos del libro
    $inicio_anio = substr($fecha, 0, 4) . '-01-01';
    $saldos['3605'] = ($saldos['3605'] ?? 0) + resultadoPeriodo($db, $inicio_anio, $fecha);
    $saldos['3705'] = ($saldos['3705'] ?? 0) + resultadoPeriodo($db, null, date('Y-m-d', strtotime("$inicio_anio -1 day")));

    $r = ['cuentas' => [], 'grupos' => [], 'sub' => [], 'bloques' => []];
    foreach ($saldos as $cuenta => $valor) {
        $cuenta = (string) $cuenta;
        $grupo = substr($cuenta, 0, 2);
        $r['cuentas'][$grupo][$cuenta] = $valor;
        $r['grupos'][$grupo] = ($r['grupos'][$grupo] ?? 0) + $valor;
    }
    foreach ($estructura as $bloque => $subs) {
        $r['bloques'][$bloque] = 0;
        foreach ($subs as $sub => $grupos) {
            $r['sub'][$sub] = 0;
            foreach ($grupos as $g) $r['sub'][$sub] += $r['grupos'][$g] ?? 0;
            $r['bloques'][$bloque] += $r['sub'][$sub];
        }
    }
    return $r;
};

$a = $situacion($corte);
$b = $comparar ? $situacion($corte_anterior) : null;
$diferencia = $a['bloques']['Activo'] - ($a['bloques']['Pasivo'] + $a['bloques']['Patrimonio']);
$cuadra = abs($diferencia) < 0.01;

$indicadores = [
    'Capital de trabajo' => dinero($a['sub']['Activo corriente'] - $a['sub']['Pasivo corriente']),
    'Razón corriente'    => $a['sub']['Pasivo corriente'] > 0 ? number_format($a['sub']['Activo corriente'] / $a['sub']['Pasivo corriente'], 2, ',', '.') . ' veces' : 'Sin pasivo corriente',
    'Endeudamiento'      => $a['bloques']['Activo'] > 0 ? number_format($a['bloques']['Pasivo'] * 100 / $a['bloques']['Activo'], 1, ',', '.') . '%' : '—',
    'Caja y bancos'      => dinero($a['grupos']['11'] ?? 0),
];

$nombre = function ($cuenta) use ($db, $nombres_calculados) {
    return $nombres_calculados[(string) $cuenta] ?? nombreCuenta($db, $cuenta);
};
// Cuentas de un grupo presentes en cualquiera de los dos cortes
$cuentasGrupo = function ($grupo) use ($a, $b) {
    $cuentas = array_map('strval', array_unique(array_merge(array_keys($a['cuentas'][$grupo] ?? []), array_keys($b['cuentas'][$grupo] ?? []))));
    sort($cuentas);
    return array_values(array_filter($cuentas, function ($c) use ($a, $b, $grupo) {
        return abs($a['cuentas'][$grupo][$c] ?? 0) >= 0.01 || abs($b['cuentas'][$grupo][$c] ?? 0) >= 0.01;
    }));
};

if ($get('exportar') === 'csv') {
    $filas = [['Balance general', "Corte $corte", $comparar ? "Comparado con $corte_anterior" : ''], ['Bloque', 'Grupo', 'Cuenta', 'Nombre', 'Saldo al corte', 'Saldo comparativo']];
    foreach ($estructura as $bloque => $subs) {
        foreach ($subs as $sub => $grupos) {
            foreach ($grupos as $g) {
                foreach ($cuentasGrupo($g) as $c) {
                    $filas[] = [$bloque, GRUPOS_PUC[$g] ?? $g, $c, $nombre($c), (float) ($a['cuentas'][$g][$c] ?? 0), (float) ($b['cuentas'][$g][$c] ?? 0)];
                }
            }
            $filas[] = [$bloque, 'Total ' . $sub, '', '', (float) $a['sub'][$sub], (float) ($b['sub'][$sub] ?? 0)];
        }
        $filas[] = ['Total ' . $bloque, '', '', '', (float) $a['bloques'][$bloque], (float) ($b['bloques'][$bloque] ?? 0)];
    }
    enviarCsv("balance_general_$corte.csv", $filas);
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Balance General";
$subtitulo = "Estado de situación financiera: activos, pasivos y patrimonio";

$url = function (array $cambios) use ($corte, $corte_anterior, $comparar) {
    return 'balance_general.php?' . http_build_query(array_merge(['corte' => $corte, 'corte_anterior' => $corte_anterior, 'comparar' => $comparar ? '' : 'no'], $cambios));
};

// Tabla de un bloque (o de pasivo + patrimonio juntos)
$tablaBloques = function (array $bloques, $id_total, $etiqueta_total) use ($estructura, $a, $b, $comparar, $corte, $nombre, $cuentasGrupo, $nombres_calculados) {
    $columnas = $comparar ? 4 : 3;
    echo '<div class="table-responsive"><table class="tabla-reporte"><thead><tr><th style="width:16%">Cuenta</th><th>Nombre</th><th class="text-end" style="width:22%">'
       . date('d/m/Y', strtotime($corte)) . '</th>' . ($comparar ? '<th class="text-end text-muted" style="width:20%">Comparativo</th>' : '') . '</tr></thead><tbody>';
    $total_a = 0; $total_b = 0;
    foreach ($bloques as $bloque) {
        echo '<tr class="seccion"><td colspan="' . $columnas . '">' . esc($bloque) . '</td></tr>';
        foreach ($estructura[$bloque] as $sub => $grupos) {
            if (count($estructura[$bloque]) > 1) echo '<tr class="subseccion"><td colspan="' . $columnas . '">' . esc($sub) . '</td></tr>';
            foreach ($grupos as $g) {
                $cuentas = $cuentasGrupo($g);
                if (!$cuentas) continue;
                foreach ($cuentas as $c) {
                    $va = $a['cuentas'][$g][$c] ?? 0;
                    $enlace = isset($nombres_calculados[$c]) ? esc($c) : '<a href="libro_auxiliar.php?' . esc(http_build_query(['cuenta' => $c, 'desde' => '2000-01-01', 'hasta' => $corte])) . '">' . esc($c) . '</a>';
                    echo '<tr class="cuenta" data-cuenta="' . esc($c) . '" data-valor="' . round($va, 2) . '"><td class="small text-muted">' . $enlace . '</td><td>' . esc($nombre($c)) . '</td>'
                       . '<td class="money ' . ($va < 0 ? 'saldo-negativo' : '') . '">' . dinero($va, 2) . '</td>'
                       . ($comparar ? '<td class="money text-muted">' . dinero($b['cuentas'][$g][$c] ?? 0, 2) . '</td>' : '') . '</tr>';
                }
                echo '<tr class="subtotal"><td></td><td>' . esc(GRUPOS_PUC[$g] ?? "Grupo $g") . '</td><td class="money">' . dinero($a['grupos'][$g] ?? 0, 2) . '</td>'
                   . ($comparar ? '<td class="money text-muted">' . dinero($b['grupos'][$g] ?? 0, 2) . '</td>' : '') . '</tr>';
            }
            echo '<tr class="resultado"><td></td><td class="text-uppercase">Total ' . esc(mb_strtolower($sub, 'UTF-8')) . '</td><td class="money">' . dinero($a['sub'][$sub], 2) . '</td>'
               . ($comparar ? '<td class="money">' . dinero($b['sub'][$sub], 2) . '</td>' : '') . '</tr>';
        }
        $total_a += $a['bloques'][$bloque];
        $total_b += $comparar ? $b['bloques'][$bloque] : 0;
        if (count($bloques) > 1) {
            echo '<tr class="subtotal"><td></td><td class="text-uppercase">Total ' . esc(mb_strtolower($bloque, 'UTF-8')) . '</td><td class="money" id="total' . $bloque . '" data-valor="' . round($a['bloques'][$bloque], 2) . '">'
               . dinero($a['bloques'][$bloque], 2) . '</td>' . ($comparar ? '<td class="money">' . dinero($b['bloques'][$bloque], 2) . '</td>' : '') . '</tr>';
        }
    }
    echo '<tr class="total-bloque"><td></td><td class="text-uppercase">' . esc($etiqueta_total) . '</td><td class="money" id="' . $id_total . '" data-valor="' . round($total_a, 2) . '">' . dinero($total_a, 2) . '</td>'
       . ($comparar ? '<td class="money">' . dinero($total_b, 2) . '</td>' : '') . '</tr></tbody></table></div>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance General | DataSense</title>
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
                        <label class="small fw-bold text-muted text-uppercase mb-1">Fecha de corte</label>
                        <input type="date" name="corte" class="form-control" value="<?= esc($corte) ?>">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Comparar con el corte</label>
                        <input type="date" name="corte_anterior" class="form-control" value="<?= esc($corte_anterior) ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Comparativo</label>
                        <select name="comparar" class="form-select">
                            <option value="">Mostrar comparativo</option>
                            <option value="no" <?= $comparar ? '' : 'selected' ?>>Sin comparativo</option>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <button type="submit" class="btn btn-dark w-100 fw-bold" style="background: var(--azul-sonda);"><i class="fas fa-sync-alt me-2"></i> Generar</button>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                        <a href="<?= esc($url(['corte' => date('Y-m-d')])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">Hoy</a>
                        <a href="<?= esc($url(['corte' => date('Y-m-t', strtotime('first day of last month'))])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">Cierre del mes anterior</a>
                        <a href="<?= esc($url(['corte' => (date('Y') - 1) . '-12-31', 'corte_anterior' => (date('Y') - 2) . '-12-31'])) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">Cierre del año anterior</a>
                        <a href="<?= esc($url(['exportar' => 'csv'])) ?>" class="btn btn-sm btn-success rounded-pill" id="exportarCsv"><i class="fas fa-file-csv me-1"></i> Exportar CSV</a>
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark rounded-pill"><i class="fas fa-print me-1"></i> Imprimir / PDF</button>
                    </div>
                </form>
            </div>

            <div class="reporte-papel">
                <?php encabezadoReporte('Estado de Situación Financiera', 'Balance general al ' . date('d/m/Y', strtotime($corte)) . ($comparar ? ' · comparativo al ' . date('d/m/Y', strtotime($corte_anterior)) : '')); ?>

                <div class="row g-3 mb-4">
                    <?php foreach ($indicadores as $etiqueta => $valor_indicador): ?>
                        <div class="col-6 col-lg-3"><div class="kpi-reporte" style="border-left-color: #0B4F8A;"><div class="etiqueta"><?= $etiqueta ?></div><div class="valor"><?= $valor_indicador ?></div></div></div>
                    <?php endforeach; ?>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-xl-6"><?php $tablaBloques(['Activo'], 'totalActivo', 'Total activo'); ?></div>
                    <div class="col-12 col-xl-6"><?php $tablaBloques(['Pasivo', 'Patrimonio'], 'totalPasivoPatrimonio', 'Total pasivo + patrimonio'); ?></div>
                </div>

                <div class="d-flex flex-wrap justify-content-center align-items-center gap-3 mt-4 p-3 rounded-4" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                    <div class="text-center"><div class="small text-muted text-uppercase fw-bold">Activo</div><div class="money fs-5"><?= dinero($a['bloques']['Activo'], 2) ?></div></div>
                    <div class="fs-3 text-muted">=</div>
                    <div class="text-center"><div class="small text-muted text-uppercase fw-bold">Pasivo</div><div class="money fs-5"><?= dinero($a['bloques']['Pasivo'], 2) ?></div></div>
                    <div class="fs-3 text-muted">+</div>
                    <div class="text-center"><div class="small text-muted text-uppercase fw-bold">Patrimonio</div><div class="money fs-5"><?= dinero($a['bloques']['Patrimonio'], 2) ?></div></div>
                    <?php if ($cuadra): ?>
                        <span class="badge bg-success-subtle text-success border border-success p-2 ms-lg-3" id="ecuacionContable"><i class="fas fa-balance-scale me-1"></i> La ecuación contable cuadra</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger p-2 ms-lg-3" id="ecuacionContable"><i class="fas fa-exclamation-triangle me-1"></i> Descuadre de <?= dinero($diferencia, 2) ?></span>
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
