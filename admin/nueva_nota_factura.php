<?php
// Nota crédito (disminuye lo que debe el cliente: devoluciones, descuentos, anulación total) o nota débito
// (lo aumenta: intereses de mora, gastos, ajustes de precio) sobre una factura de venta.
// La causación la calcula el servidor con la tarifa de IVA de la factura; la nota queda pendiente de revisión
// y, al aprobarse, cambia el saldo y el estado de la factura.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'notas_factura');
actualizarVencimientos($db);

$tipo = (($_POST['tipo'] ?? $_GET['tipo'] ?? '') === 'NDV') ? 'NDV' : 'NCV';
$es_credito = ($tipo === 'NCV');
$cfg = TIPOS_COMPROBANTE[$tipo];
$motivos = $es_credito ? MOTIVOS_NOTA_CREDITO : MOTIVOS_NOTA_DEBITO;

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = 'Nueva ' . $cfg['nombre'];
$subtitulo = $es_credito ? 'Disminuye lo que debe el cliente en una factura de venta' : 'Aumenta lo que debe el cliente en una factura de venta';

// Facturas no anuladas; la nota crédito solo puede tocar el saldo que el cliente aún debe
$facturas = [];
foreach ($db->query("SELECT f.id, f.id_usuario, f.numero_factura, f.servicio_contratado, f.fecha_emision, f.monto_total, f.porcentaje_iva, f.estado,
                            u.nombre, u.organizacion, COALESCE(a.aprobado, 0) AS aprobado, COALESCE(a.en_tramite, 0) AS en_tramite
                     FROM facturas f
                     JOIN usuarios u ON u.id = f.id_usuario
                     LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
                     WHERE f.estado <> 'Anulada'
                     ORDER BY f.fecha_emision DESC, f.id DESC") as $f) {
    $f['saldo'] = round((float) $f['monto_total'] - (float) $f['aprobado'] - (float) $f['en_tramite'], 2);
    $f['cliente'] = trim((string) $f['organizacion']) !== '' ? $f['organizacion'] : $f['nombre'];
    if (!$es_credito || $f['saldo'] > 0.5) $facturas[(int) $f['id']] = $f;
}

$errores = [];
$form = ['factura_id' => (string) (int) ($_GET['factura'] ?? 0), 'fecha' => date('Y-m-d'), 'motivo' => '', 'concepto' => '', 'base' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $campo) {
        $form[$campo] = is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : '';
    }
    $factura = $facturas[(int) $form['factura_id']] ?? null;
    $base = filter_var($form['base'], FILTER_VALIDATE_FLOAT);

    if (!$factura) $errores[] = $es_credito ? 'Elige una factura con saldo por cobrar.' : 'Elige una factura de venta vigente.';
    if (!in_array($form['motivo'], $motivos, true)) $errores[] = 'Elige el motivo de la nota.';
    if ($form['concepto'] === '' || mb_strlen($form['concepto']) > 255) $errores[] = 'Describe la razón de la nota.';
    if (!fechaValida($form['fecha']) || $form['fecha'] > date('Y-m-d')) $errores[] = 'La fecha de la nota debe ser válida y no puede ser futura.';
    elseif ($factura && $form['fecha'] < $factura['fecha_emision']) $errores[] = 'La nota no puede ser anterior a la fecha de la factura.';
    if ($base === false || $base <= 0) $errores[] = 'El valor antes de IVA debe ser mayor que cero.';

    if (!$errores) {
        // Los intereses de mora no llevan IVA; lo demás usa la tarifa de la factura
        $tarifa = (!$es_credito && $form['motivo'] === 'Intereses de mora') ? 0.0 : (float) $factura['porcentaje_iva'];
        $base = round($base, 2);
        $iva = round($base * $tarifa / 100, 2);
        $total = round($base + $iva, 2);
        $tarifa_texto = rtrim(rtrim(number_format($tarifa, 2, ',', ''), '0'), ',');
        $detalle = $form['motivo'] . ' - factura ' . $factura['numero_factura'];
        if ($es_credito) {
            $lineas = [[CTA_DEVOLUCIONES_VENTAS, $detalle, $base, 0]];
            if ($iva > 0) $lineas[] = [CTA_IVA_GENERADO, "IVA $tarifa_texto% que se reversa", $iva, 0];
            $lineas[] = [CTA_CLIENTES, 'Nota crédito a la factura ' . $factura['numero_factura'], 0, $total];
        } else {
            $lineas = [
                [CTA_CLIENTES, 'Nota débito a la factura ' . $factura['numero_factura'], $total, 0],
                [$form['motivo'] === 'Intereses de mora' ? CTA_INTERESES : CTA_INGRESO_SERVICIOS, $detalle, 0, $base],
            ];
            if ($iva > 0) $lineas[] = [CTA_IVA_GENERADO, "IVA $tarifa_texto% de la nota", 0, $iva];
        }

        try {
            asegurarConsecutivo($db, $tipo, substr($form['fecha'], 0, 4));
            $db->beginTransaction();
            // Se bloquea la factura y se recalcula su saldo: la nota crédito no puede superar lo que el cliente aún debe
            $db->prepare("SELECT id FROM facturas WHERE id = ? FOR UPDATE")->execute([$factura['id']]);
            $aplicado = aplicadoFactura($db, $factura['id']);
            $saldo = round((float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'], 2);
            if ($es_credito && $total > $saldo + 0.5) {
                throw new InvalidArgumentException('La nota crédito (' . dinero($total, 2) . ') supera el saldo de la factura (' . dinero($saldo, 2) . ').');
            }
            if ($form['motivo'] === 'Anulación total de la factura' && abs($total - (float) $factura['monto_total']) > 0.5) {
                throw new InvalidArgumentException('La anulación total debe ser por el valor completo de la factura (' . dinero($factura['monto_total'], 2) . ') y la factura no puede tener abonos.');
            }
            $tercero = terceroDeUsuario($db, $factura['id_usuario']);
            $resultado = guardarComprobante($db, [
                'tipo' => $tipo, 'fecha' => $form['fecha'],
                'tercero_id' => $tercero['id'] ?? null, 'tercero_nombre' => $tercero['nombre'] ?? $factura['cliente'], 'tercero_documento' => $tercero['documento'] ?? null,
                'concepto' => $form['concepto'], 'metodo_pago' => 'No aplica', 'referencia' => $factura['numero_factura'], 'valor_total' => $total,
                'factura_id' => (int) $factura['id'], 'estado' => 'Pendiente', 'origen' => 'Manual', 'clase_nota' => $form['motivo'], 'preparado_por' => $usuario['id'],
            ], $lineas);
            recalcularFactura($db, $factura['id']);
            $db->commit();
            avisoSiguiente('success', "{$cfg['nombre']} {$resultado['numero']} registrada por " . dinero($total, 2) . " sobre la factura {$factura['numero_factura']}. Queda pendiente de revisión; al aprobarse cambia el saldo de la factura.");
            header('Location: ' . $cfg['pagina'] . '?id=' . $resultado['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = $e->getMessage();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('nueva_nota_factura.php: ' . $e->getMessage());
            $errores[] = 'No se pudo guardar la nota. Intenta de nuevo.';
        }
    }
}

$datos_facturas = [];
foreach ($facturas as $id => $f) {
    $datos_facturas[$id] = ['numero' => $f['numero_factura'], 'cliente' => $f['cliente'], 'total' => (float) $f['monto_total'], 'saldo' => $f['saldo'],
                            'tarifa' => (float) $f['porcentaje_iva'], 'emision' => date('d/m/Y', strtotime($f['fecha_emision'])), 'estado' => $f['estado']];
}
$color = $es_credito ? '#c2410c' : '#a16207';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($titulo) ?> | DataSense</title>
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
        <div class="container-fluid py-4" style="max-width: 1100px;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <a href="<?= $cfg['pagina'] ?>" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver</a>
                <div class="d-flex gap-2 pestanas-doc">
                    <?php $sufijo = (int) $form['factura_id'] ? '&amp;factura=' . (int) $form['factura_id'] : ''; ?>
                    <a href="nueva_nota_factura.php?tipo=NCV<?= $sufijo ?>" class="btn rounded-pill fw-bold px-3 <?= $es_credito ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-file-circle-minus me-1"></i> Nota crédito</a>
                    <a href="nueva_nota_factura.php?tipo=NDV<?= $sufijo ?>" class="btn rounded-pill fw-bold px-3 <?= !$es_credito ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-file-circle-plus me-1"></i> Nota débito</a>
                </div>
                <span class="small text-muted">N° que se asignará al guardar: <b id="consecutivoPropuesto"><?= esc(consecutivoPropuesto($db, $tipo, date('Y'))) ?></b></span>
            </div>

            <?php if ($errores): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" id="erroresFormulario">
                    <i class="fas fa-exclamation-triangle me-2"></i> <b>No se guardó la nota:</b>
                    <ul class="mb-0 mt-2"><?php foreach ($errores as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="formNotaFactura" autocomplete="off">
                <input type="hidden" name="tipo" value="<?= $tipo ?>">
                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: <?= $color ?>;"><i class="fas <?= $es_credito ? 'fa-file-circle-minus' : 'fa-file-circle-plus' ?> me-2"></i> 1. Factura y motivo</div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="small fw-bold text-muted mb-1">Factura de venta *</label>
                            <select name="factura_id" id="facturaId" class="form-select" required>
                                <option value="">-- Elegir factura --</option>
                                <?php foreach ($facturas as $id => $f): ?>
                                    <option value="<?= (int) $id ?>" <?= (int) $form['factura_id'] === (int) $id ? 'selected' : '' ?>>
                                        <?= esc($f['numero_factura']) ?> · <?= esc($f['cliente']) ?> · <?= $es_credito ? 'saldo ' . dinero($f['saldo']) : 'total ' . dinero($f['monto_total']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="infoFactura"><?= $facturas ? '' : ($es_credito ? 'No hay facturas con saldo por cobrar.' : 'No hay facturas vigentes.') ?></div>
                        </div>
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">Motivo *</label>
                            <select name="motivo" id="motivo" class="form-select" required>
                                <option value="">-- Elegir --</option>
                                <?php foreach ($motivos as $motivo): ?>
                                    <option value="<?= esc($motivo) ?>" <?= $form['motivo'] === $motivo ? 'selected' : '' ?>><?= esc($motivo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Fecha *</label>
                            <input type="date" name="fecha" class="form-control" max="<?= date('Y-m-d') ?>" required value="<?= esc($form['fecha']) ?>">
                        </div>
                        <div class="col-md-9">
                            <label class="small fw-bold text-muted mb-1">Descripción *</label>
                            <input type="text" name="concepto" id="concepto" class="form-control" maxlength="255" required value="<?= esc($form['concepto']) ?>"
                                   placeholder="<?= $es_credito ? 'Ej: El cliente devolvió 4 de las 10 horas contratadas' : 'Ej: Intereses por 20 días de mora' ?>">
                        </div>
                    </div>
                </div>

                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: <?= $color ?>;"><i class="fas fa-calculator me-2"></i> 2. Valores</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Valor antes de IVA *</label>
                            <div class="input-group">
                                <input type="number" name="base" id="baseNota" class="form-control text-end fw-bold" min="0.01" step="0.01" required value="<?= esc($form['base']) ?>">
                                <?php if ($es_credito): ?>
                                    <button type="button" class="btn btn-outline-secondary" id="usarSaldo" title="Acreditar todo el saldo de la factura">Todo el saldo</button>
                                <?php endif; ?>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-6"><div class="kpi-reporte"><div class="etiqueta">IVA (<span id="tarifaNota">0</span>%)</div><div class="valor" id="ivaNota">$ 0</div></div></div>
                                <div class="col-6"><div class="kpi-reporte"><div class="etiqueta">Total de la nota</div><div class="valor" id="totalNota">$ 0</div></div></div>
                                <div class="col-12"><div class="kpi-reporte"><div class="etiqueta">Saldo de la factura después de aprobarla</div><div class="valor" id="saldoDespues">$ 0</div></div></div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <table class="table table-sm linea-previa mb-0">
                                <thead><tr><th>Cuenta (vista previa de la causación)</th><th class="text-end">Débito</th><th class="text-end">Crédito</th></tr></thead>
                                <tbody id="previaNota"></tbody>
                                <tfoot><tr class="fila-total"><td>Sumas iguales</td><td class="text-end money" id="previaDebito">$ 0</td><td class="text-end money" id="previaCredito">$ 0</td></tr></tfoot>
                            </table>
                            <p class="small text-muted mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>
                                <?= $es_credito
                                    ? 'La nota crédito reversa el ingreso (devoluciones, rebajas y descuentos) y el IVA con la tarifa de la factura. No puede superar el saldo que el cliente aún debe.'
                                    : 'La nota débito aumenta lo que debe el cliente. Los intereses de mora van a ingresos financieros y no llevan IVA.' ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="text-end mb-5">
                    <button type="submit" id="btnGuardar" class="btn btn-lg rounded-pill fw-bold px-5 shadow text-white" style="background: <?= $color ?>;">
                        <i class="fas fa-save me-2"></i> Registrar <?= mb_strtolower($cfg['nombre'], 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
const FACTURAS = <?= jsJson($datos_facturas) ?>;
const ES_CREDITO = <?= $es_credito ? 'true' : 'false' ?>;
const CUENTAS = <?= jsJson([
    CTA_CLIENTES => 'Clientes nacionales', CTA_DEVOLUCIONES_VENTAS => 'Devoluciones, rebajas y descuentos en ventas',
    CTA_IVA_GENERADO => 'IVA generado', CTA_INGRESO_SERVICIOS => 'Ingresos por servicios de consultoría', CTA_INTERESES => 'Intereses',
]) ?>;
const $ = id => document.getElementById(id);
const pesos = v => {
    const [entero, decimales] = (Math.round(Math.abs(v) * 100) / 100).toFixed(2).split('.');
    return (v < 0 ? '−' : '') + '$ ' + entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (decimales !== '00' ? ',' + decimales : '');
};

function calcular() {
    const factura = FACTURAS[$('facturaId').value];
    const intereses = !ES_CREDITO && $('motivo').value === 'Intereses de mora';
    const tarifa = factura && !intereses ? factura.tarifa : 0;
    const base = Math.round((parseFloat($('baseNota').value) || 0) * 100) / 100;
    const iva = Math.round(base * tarifa) / 100;
    const total = Math.round((base + iva) * 100) / 100;
    $('tarifaNota').textContent = tarifa.toString().replace('.', ',');
    $('ivaNota').textContent = pesos(iva);
    $('totalNota').textContent = pesos(total);
    $('saldoDespues').textContent = factura ? pesos(factura.saldo + (ES_CREDITO ? -total : total)) : '$ 0';
    $('infoFactura').textContent = factura ? `${factura.cliente} · emitida el ${factura.emision} · total ${pesos(factura.total)} · saldo ${pesos(factura.saldo)} · IVA ${factura.tarifa}% · ${factura.estado}` : '';

    const numero = factura ? factura.numero : '';
    const lineas = ES_CREDITO
        ? [['<?= CTA_DEVOLUCIONES_VENTAS ?>', base, 0], ['<?= CTA_IVA_GENERADO ?>', iva, 0], ['<?= CTA_CLIENTES ?>', 0, total]]
        : [['<?= CTA_CLIENTES ?>', total, 0], [intereses ? '<?= CTA_INTERESES ?>' : '<?= CTA_INGRESO_SERVICIOS ?>', 0, base], ['<?= CTA_IVA_GENERADO ?>', 0, iva]];
    const cuerpo = $('previaNota');
    cuerpo.textContent = '';
    let debitos = 0, creditos = 0;
    for (const [cuenta, d, c] of lineas) {
        if (!d && !c && cuenta === '<?= CTA_IVA_GENERADO ?>') continue;
        debitos += d; creditos += c;
        const fila = cuerpo.insertRow();
        fila.insertCell().textContent = `${cuenta} · ${CUENTAS[cuenta]}${cuenta === '<?= CTA_CLIENTES ?>' && numero ? ' (' + numero + ')' : ''}`;
        Object.assign(fila.insertCell(), { className: 'text-end money', textContent: d ? pesos(d) : '-' });
        Object.assign(fila.insertCell(), { className: 'text-end money', textContent: c ? pesos(c) : '-' });
    }
    $('previaDebito').textContent = pesos(debitos);
    $('previaCredito').textContent = pesos(creditos);
}

['facturaId', 'motivo', 'baseNota'].forEach(id => { $(id).addEventListener('input', calcular); $(id).addEventListener('change', calcular); });
if ($('usarSaldo')) {
    $('usarSaldo').addEventListener('click', () => {
        const factura = FACTURAS[$('facturaId').value];
        if (!factura) return;
        $('baseNota').value = (Math.round(factura.saldo / (1 + factura.tarifa / 100) * 100) / 100).toFixed(2);
        calcular();
    });
}
calcular();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
