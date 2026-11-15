<?php
// Nueva cuenta por pagar: se causa la factura del proveedor (gasto o activo, IVA descontable, retención y cuenta por pagar)
// sin mover caja ni bancos. Se paga después, total o parcialmente, con comprobantes de egreso.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'cuentas_por_pagar');

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Registrar Factura de Proveedor";
$subtitulo = "Causa la cuenta por pagar: se paga después con un comprobante de egreso";

// Cuentas que puede afectar una compra: gastos (5), costos (6) y activos fijos (15)
$cuentas = [];
$cuentas_gasto = [];
foreach (nombresCuentas($db) as $codigo => $nombre) {
    $codigo = (string) $codigo;
    $cuentas[$codigo] = $nombre;
    if (strlen($codigo) === 6 && ($codigo[0] === '5' || $codigo[0] === '6' || substr($codigo, 0, 2) === '15')) {
        $cuentas_gasto[$codigo] = $nombre;
    }
}
$terceros = $db->query("SELECT documento, nombre FROM terceros ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto = function ($campo) { return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : ''; };
    $documento = normalizarDocumento($texto('documento'));
    $proveedor = $texto('proveedor');
    $factura_proveedor = mb_strtoupper(preg_replace('/\s+/', ' ', $texto('factura_proveedor')), 'UTF-8');
    $fecha = $texto('fecha');
    $vencimiento = $texto('vencimiento');
    $concepto = $texto('concepto');
    $cuenta = $texto('cuenta_gasto');
    $base = filter_var($texto('base'), FILTER_VALIDATE_FLOAT);
    $iva_pct = $texto('iva_pct');
    $retencion_pct = $texto('retencion_pct');

    if (!documentoValido($documento)) $errores[] = 'Escribe el NIT o la cédula del proveedor (números, con guion antes del dígito de verificación si aplica).';
    if ($proveedor === '' || mb_strlen($proveedor) > 150) $errores[] = 'Escribe el nombre del proveedor.';
    if ($factura_proveedor === '' || mb_strlen($factura_proveedor) > 40) $errores[] = 'Escribe el número de la factura o cuenta de cobro del proveedor.';
    if (!fechaValida($fecha) || $fecha > date('Y-m-d')) $errores[] = 'La fecha de la factura debe ser válida y no puede ser futura.';
    if (!fechaValida($vencimiento) || (fechaValida($fecha) && $vencimiento < $fecha)) $errores[] = 'La fecha de vencimiento no puede ser anterior a la de la factura.';
    if ($concepto === '' || mb_strlen($concepto) > 255) $errores[] = 'Escribe el concepto de la compra.';
    if (!isset($cuentas_gasto[$cuenta])) $errores[] = 'Elige la cuenta del gasto o del activo.';
    if ($base === false || $base <= 0) $errores[] = 'La base (valor antes de impuestos) debe ser mayor que cero.';
    if (!in_array($iva_pct, TARIFAS_IVA, true)) $errores[] = 'Tarifa de IVA no válida.';
    if (!isset(RETENCIONES_EGRESO[$retencion_pct])) $errores[] = 'Porcentaje de retención no válido.';

    if (!$errores) {
        // Liquidación: IVA y retención se redondean al peso; el neto es lo que se le debe al proveedor
        $base = round($base, 2);
        $iva = round($base * (float) $iva_pct / 100);
        list($cuenta_retencion, $descripcion_retencion) = RETENCIONES_EGRESO[$retencion_pct];
        $retencion = round($base * (float) $retencion_pct / 100);
        $neto = round($base + $iva - $retencion, 2);

        try {
            asegurarConsecutivo($db, 'FP', substr($fecha, 0, 4));
            $db->beginTransaction();
            $tercero = guardarTercero($db, $documento, $proveedor, 'Proveedor');
            // La misma factura del proveedor no se causa dos veces
            $duplicada = $db->prepare("SELECT numero FROM comprobantes WHERE tipo = 'FP' AND tercero_documento = ? AND referencia = ? AND estado <> 'Anulado' LIMIT 1");
            $duplicada->execute([$tercero['documento'], $factura_proveedor]);
            $numero_duplicado = $duplicada->fetchColumn();
            if ($numero_duplicado) {
                throw new InvalidArgumentException("La factura $factura_proveedor de este proveedor ya está registrada en $numero_duplicado.");
            }

            $lineas = [[$cuenta, $concepto, $base, 0]];
            if ($iva > 0) $lineas[] = [CTA_IVA_DESCONTABLE, "IVA descontable $iva_pct%", $iva, 0];
            if ($retencion > 0) $lineas[] = [$cuenta_retencion, "Retención en la fuente $descripcion_retencion", 0, $retencion];
            $lineas[] = [CTA_PROVEEDORES, "Factura $factura_proveedor de {$tercero['nombre']}", 0, $neto];

            $resultado = guardarComprobante($db, [
                'tipo' => 'FP', 'fecha' => $fecha, 'fecha_vencimiento' => $vencimiento,
                'tercero_id' => $tercero['id'], 'tercero_nombre' => $tercero['nombre'], 'tercero_documento' => $tercero['documento'],
                'concepto' => $concepto, 'metodo_pago' => 'No aplica', 'referencia' => $factura_proveedor, 'valor_total' => $neto,
                'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $usuario['id'],
            ], $lineas);
            $db->commit();
            $nota = mb_strtolower($tercero['nombre']) !== mb_strtolower($proveedor)
                ? " El documento $documento ya estaba registrado como «{$tercero['nombre']}» y se conservó ese nombre." : '';
            avisoSiguiente('success', "Cuenta por pagar {$resultado['numero']} registrada por " . dinero($neto) . ". Queda pendiente de la revisión de Finanzas; una vez aprobada se puede pagar.$nota");
            header('Location: cuentas_por_pagar.php?id=' . $resultado['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = $e->getMessage();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('nueva_cuenta_por_pagar.php: ' . $e->getMessage());
            $errores[] = 'No se pudo guardar la cuenta por pagar. Intenta de nuevo.';
        }
    }
}

$campo = function ($nombre, $defecto = '') { return esc(is_string($_POST[$nombre] ?? null) ? $_POST[$nombre] : $defecto); };
$elegido = function ($nombre, $valor, $defecto = '') { return (is_string($_POST[$nombre] ?? null) ? $_POST[$nombre] : $defecto) === (string) $valor ? 'selected' : ''; };
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Cuenta por Pagar | DataSense</title>
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
        <div class="container-fluid py-4" style="max-width: 1150px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="cuentas_por_pagar.php" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver</a>
                <span class="small text-muted">N° que se asignará al guardar: <b id="consecutivoPropuesto"><?= esc(consecutivoPropuesto($db, 'FP', date('Y'))) ?></b></span>
            </div>

            <?php if ($errores): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" id="erroresFormulario">
                    <i class="fas fa-exclamation-triangle me-2"></i> <b>No se guardó la cuenta por pagar:</b>
                    <ul class="mb-0 mt-2"><?php foreach ($errores as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="formCuentaPorPagar" autocomplete="off">
                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: #0f766e;"><i class="fas fa-truck me-2"></i> 1. Proveedor y factura</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">NIT / C.C. *</label>
                            <input type="text" name="documento" id="documento" class="form-control fw-bold" list="listaTerceros" maxlength="20" required placeholder="Ej: 901234567-1" value="<?= $campo('documento') ?>">
                            <datalist id="listaTerceros">
                                <?php foreach ($terceros as $t): ?><option value="<?= esc($t['documento']) ?>"><?= esc($t['nombre']) ?></option><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">Proveedor *</label>
                            <input type="text" name="proveedor" id="proveedor" class="form-control" maxlength="150" required value="<?= $campo('proveedor') ?>">
                            <div id="avisoTercero" class="form-text text-success"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">N° de factura del proveedor *</label>
                            <input type="text" name="factura_proveedor" id="facturaProveedor" class="form-control" maxlength="40" required placeholder="Ej: FE-10293" value="<?= $campo('factura_proveedor') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Fecha de la factura *</label>
                            <input type="date" name="fecha" id="fechaFactura" class="form-control" max="<?= date('Y-m-d') ?>" required value="<?= $campo('fecha', date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Vence *</label>
                            <input type="date" name="vencimiento" id="vencimiento" class="form-control" required value="<?= $campo('vencimiento', date('Y-m-d', strtotime('+30 days'))) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">Por concepto de *</label>
                            <input type="text" name="concepto" class="form-control" maxlength="255" required value="<?= $campo('concepto') ?>">
                        </div>
                    </div>
                </div>

                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: #0f766e;"><i class="fas fa-calculator me-2"></i> 2. Valores e impuestos</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Cuenta del gasto o activo *</label>
                            <select name="cuenta_gasto" id="cuentaGasto" class="form-select" required>
                                <option value="">-- Elegir cuenta --</option>
                                <?php foreach ($cuentas_gasto as $codigo => $nombre): ?>
                                    <option value="<?= esc($codigo) ?>" <?= $elegido('cuenta_gasto', $codigo) ?>><?= esc($codigo) ?> · <?= esc($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Base (antes de impuestos) *</label>
                            <input type="number" name="base" id="base" class="form-control text-end fw-bold" min="0.01" step="0.01" required value="<?= $campo('base') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted mb-1">IVA</label>
                            <select name="iva_pct" id="ivaPct" class="form-select">
                                <?php foreach (TARIFAS_IVA as $tarifa): ?>
                                    <option value="<?= $tarifa ?>" <?= $elegido('iva_pct', $tarifa, '0') ?>><?= $tarifa ?>%</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Retención en la fuente</label>
                            <select name="retencion_pct" id="retencionPct" class="form-select">
                                <?php foreach (RETENCIONES_EGRESO as $pct => $datos): ?>
                                    <option value="<?= esc($pct) ?>" <?= $elegido('retencion_pct', $pct, '0') ?>><?= esc($datos[1]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-2 align-items-stretch">
                        <div class="col-lg-8">
                            <table class="table table-sm linea-previa mb-0">
                                <thead><tr><th>Cuenta (vista previa de la causación)</th><th class="text-end">Débito</th><th class="text-end">Crédito</th></tr></thead>
                                <tbody id="previaCausacion"></tbody>
                                <tfoot><tr class="fila-total"><td>Sumas iguales</td><td class="text-end money" id="previaDebito">$ 0</td><td class="text-end money" id="previaCredito">$ 0</td></tr></tfoot>
                            </table>
                        </div>
                        <div class="col-lg-4">
                            <div class="h-100 rounded-4 p-4 text-center text-white d-flex flex-column justify-content-center" style="background: #0f766e;">
                                <small class="text-uppercase fw-bold opacity-75">Neto por pagar al proveedor</small>
                                <div class="fs-2 fw-bold" id="netoPagar">$ 0</div>
                                <small class="opacity-75" id="detalleNeto">Base + IVA − retención</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end mb-5">
                    <button type="submit" id="btnGuardar" class="btn btn-lg rounded-pill fw-bold px-5 shadow text-white" style="background: #0f766e;">
                        <i class="fas fa-save me-2"></i> Registrar cuenta por pagar
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
const CUENTAS = <?= jsJson($cuentas) ?>;
const TERCEROS = <?= jsJson($terceros) ?>;
const RETENCIONES = <?= jsJson(RETENCIONES_EGRESO) ?>;
const $ = id => document.getElementById(id);
const pesos = v => {
    const [entero, decimales] = (Math.round(Math.abs(v) * 100) / 100).toFixed(2).split('.');
    return (v < 0 ? '−' : '') + '$ ' + entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (decimales !== '00' ? ',' + decimales : '');
};

// Si el NIT ya existe se usa el nombre registrado (el servidor hace lo mismo)
$('documento').addEventListener('input', () => {
    const doc = $('documento').value.toUpperCase().replace(/[^0-9A-Z-]/g, '');
    const tercero = TERCEROS.find(t => t.documento === doc);
    $('avisoTercero').textContent = tercero ? `Tercero registrado: ${tercero.nombre}` : '';
    if (tercero) $('proveedor').value = tercero.nombre;
});
$('fechaFactura').addEventListener('change', () => { $('vencimiento').min = $('fechaFactura').value; });

function previsualizar() {
    const base = parseFloat($('base').value) || 0;
    const iva = Math.round(base * parseFloat($('ivaPct').value) / 100);
    const pctRet = $('retencionPct').value;
    const retencion = Math.round(base * parseFloat(pctRet) / 100);
    const neto = base + iva - retencion;
    const lineas = [[$('cuentaGasto').value, base, 0]];
    if (iva > 0) lineas.push(['<?= CTA_IVA_DESCONTABLE ?>', iva, 0]);
    if (retencion > 0) lineas.push([RETENCIONES[pctRet][0], 0, retencion]);
    lineas.push(['<?= CTA_PROVEEDORES ?>', 0, neto]);

    const cuerpo = $('previaCausacion');
    cuerpo.textContent = '';
    let deb = 0, cre = 0;
    for (const [cuenta, d, c] of lineas) {
        deb += d; cre += c;
        const fila = cuerpo.insertRow();
        fila.insertCell().textContent = cuenta ? `${cuenta} · ${CUENTAS[cuenta] || ''}` : '(elige la cuenta del gasto)';
        Object.assign(fila.insertCell(), { className: 'text-end money', textContent: d ? pesos(d) : '-' });
        Object.assign(fila.insertCell(), { className: 'text-end money', textContent: c ? pesos(c) : '-' });
    }
    $('previaDebito').textContent = pesos(deb);
    $('previaCredito').textContent = pesos(cre);
    $('netoPagar').textContent = pesos(neto);
    $('detalleNeto').textContent = `${pesos(base)} + IVA ${pesos(iva)} − retención ${pesos(retencion)}`;
}
['base', 'ivaPct', 'retencionPct', 'cuentaGasto'].forEach(id => {
    $(id).addEventListener('input', previsualizar);
    $(id).addEventListener('change', previsualizar);
});
previsualizar();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
