<?php
// Certificado de retención en la fuente de un año gravable para un tercero: base, tarifa y valor retenido por concepto
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/impuestos.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'impuestos');

$documento = normalizarDocumento(is_string($_GET['documento'] ?? null) ? $_GET['documento'] : '');
$anio = preg_match('/^\d{4}$/', (string) (is_string($_GET['anio'] ?? null) ? $_GET['anio'] : '')) ? (int) $_GET['anio'] : (int) date('Y') - 1;
$filas = $documento !== '' ? retencionesPracticadas($db, "$anio-01-01", "$anio-12-31", $documento) : [];
if (!$filas) {
    avisoSiguiente('warning', "Ese tercero no tiene retenciones practicadas en $anio.");
    header('Location: impuestos.php?vista=certificados&anio=' . $anio);
    exit;
}

$conceptos = [];
foreach ($filas as $f) {
    $cuenta = (string) $f['cuenta'];
    if (!isset($conceptos[$cuenta])) $conceptos[$cuenta] = ['concepto' => $f['concepto'], 'base' => 0.0, 'retenido' => 0.0];
    $conceptos[$cuenta]['base'] += (float) $f['base'];
    $conceptos[$cuenta]['retenido'] += (float) $f['retenido'];
}
ksort($conceptos);
$total_base = round(array_sum(array_column($conceptos, 'base')), 2);
$total_retenido = round(array_sum(array_column($conceptos, 'retenido')), 2);
$tercero = end($filas)['tercero_nombre'];
$empresa = datosEmpresa();

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Certificado de Retención";
$subtitulo = "Año gravable $anio · " . esc($tercero); // includes/header.php lo imprime tal cual
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado de retención <?= $anio ?> | DataSense</title>
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
            <div class="no-print d-flex justify-content-between align-items-center mb-3">
                <a href="impuestos.php?vista=certificados&amp;anio=<?= $anio ?>" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver</a>
                <button type="button" onclick="window.print()" class="btn btn-dark rounded-pill fw-bold px-4" style="background: var(--azul-sonda);"><i class="fas fa-print me-2"></i> Imprimir / PDF</button>
            </div>

            <div class="doc-papel certificado" id="certificado">
                <div class="doc-cabecera">
                    <div>
                        <img src="../img/logo.png" alt="DataSense Analytics" class="mb-2">
                        <div class="doc-empresa"><b><?= esc(mb_strtoupper($empresa['nombre'], 'UTF-8')) ?></b><br>NIT: <?= esc($empresa['nit']) ?><br><?= esc($empresa['ciudad']) ?></div>
                    </div>
                    <div class="doc-titulo">
                        <h2>CERTIFICADO DE RETENCIÓN<br>EN LA FUENTE</h2>
                        <div class="doc-numero">Año gravable <?= $anio ?></div>
                    </div>
                </div>

                <div class="doc-datos">
                    <div class="doc-fila"><div class="doc-etiqueta">Agente retenedor</div><div class="doc-valor fw-bold"><?= esc($empresa['nombre']) ?> · NIT <?= esc($empresa['nit']) ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">Retenido</div><div class="doc-valor fw-bold text-uppercase" id="terceroCertificado"><?= esc($tercero) ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">NIT / C.C.</div><div class="doc-valor"><?= esc($documento) ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">Periodo</div><div class="doc-valor">Del 01/01/<?= $anio ?> al 31/12/<?= $anio ?></div></div>
                </div>

                <div class="doc-subtitulo">Retenciones practicadas</div>
                <div class="table-responsive">
                    <table class="doc-causacion" id="tablaCertificado">
                        <thead><tr><th>Concepto</th><th style="width: 22%;">Base sujeta</th><th style="width: 14%;">Tarifa</th><th style="width: 22%;">Valor retenido</th></tr></thead>
                        <tbody>
                        <?php foreach ($conceptos as $cuenta => $c): ?>
                            <tr data-cuenta="<?= esc($cuenta) ?>">
                                <td class="fw-bold"><?= esc($c['concepto']) ?></td>
                                <td class="money"><?= dinero($c['base'], 2) ?></td>
                                <td class="text-center"><?= $c['base'] > 0 ? number_format($c['retenido'] * 100 / $c['base'], 2, ',', '.') . '%' : '—' ?></td>
                                <td class="money"><?= dinero($c['retenido'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td class="text-end text-uppercase small">Totales</td><td class="money"><?= dinero($total_base, 2) ?></td><td></td>
                                <td class="money" id="totalCertificado" data-valor="<?= $total_retenido ?>"><?= dinero($total_retenido, 2) ?></td></tr>
                        </tfoot>
                    </table>
                </div>

                <p class="small">Valor total retenido en letras: <b id="retenidoLetras"><?= esc(numeroALetras($total_retenido)) ?></b>.</p>
                <p class="small">Las retenciones fueron declaradas y consignadas oportunamente a nombre de la Dirección de Impuestos y Aduanas Nacionales (DIAN) en la ciudad de <?= esc($empresa['ciudad']) ?>.</p>
                <p class="small mb-5">Fecha de expedición: <?= date('d/m/Y') ?>.</p>

                <div class="reporte-firmas">
                    <div><div class="linea"></div><div class="small fw-bold"><?= esc($empresa['representante'] ?: 'Representante legal') ?></div><div class="small text-muted">Agente retenedor</div></div>
                    <div><div class="linea"></div><div class="small fw-bold"><?= esc($empresa['contador'] ?: 'Contador público') ?></div><div class="small text-muted"><?= $empresa['tp'] ? 'T.P. ' . esc($empresa['tp']) : 'Contador' ?></div></div>
                </div>
                <p class="leyenda mt-4 mb-0">Certificado informativo generado con los documentos contables aprobados. Se puede expedir sin firma autógrafa según el artículo 10 del Decreto 836 de 1991. Verifica los valores con tu contador antes de entregarlo.</p>
            </div>
        </div>
    </main>
</div>
</body>
</html>
