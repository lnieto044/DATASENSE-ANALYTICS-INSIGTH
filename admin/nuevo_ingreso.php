<?php
// Nuevo comprobante de ingreso: abono a una factura de venta u otro ingreso (aporte, préstamo, intereses...)
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'comprobante_ingreso');
actualizarVencimientos($db);

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Nuevo Comprobante de Ingreso";
$subtitulo = "Registra un recaudo: queda pendiente de revisión y aprobación";

// Facturas con saldo que aún se puede aplicar (total - aprobado - lo que ya está en trámite)
$facturas = $db->query("SELECT f.id, f.id_usuario, f.numero_factura, f.servicio_contratado, f.monto_total, f.fecha_vencimiento, f.estado,
                               u.nombre, u.organizacion, t.documento,
                               COALESCE(a.aprobado, 0) AS aprobado, COALESCE(a.en_tramite, 0) AS en_tramite
                        FROM facturas f
                        JOIN usuarios u ON u.id = f.id_usuario
                        LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
                        LEFT JOIN terceros t ON t.id_usuario = u.id
                        WHERE f.estado NOT IN ('Pagado', 'Anulada')
                        ORDER BY f.fecha_emision")->fetchAll(PDO::FETCH_ASSOC);
$facturas_disponibles = [];
foreach ($facturas as $f) {
    $f['disponible'] = round((float) $f['monto_total'] - (float) $f['aprobado'] - (float) $f['en_tramite'], 2);
    $f['cliente'] = trim((string) $f['organizacion']) !== '' ? $f['organizacion'] : $f['nombre'];
    if ($f['disponible'] > 0.5) $facturas_disponibles[(int) $f['id']] = $f;
}

// Otros ingresos: cuentas de ingresos (4), aportes de capital (31) y préstamos bancarios (21)
$cuentas_ingreso = [];
foreach (nombresCuentas($db) as $codigo => $nombre) {
    $codigo = (string) $codigo;
    if (strlen($codigo) === 6 && $codigo !== CTA_INGRESO_SERVICIOS && ($codigo[0] === '4' || in_array(substr($codigo, 0, 2), ['31', '21'], true))) {
        $cuentas_ingreso[$codigo] = $nombre;
    }
}

$errores = [];
$factura_elegida = (int) ($_POST['factura_id'] ?? $_GET['factura'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_ingreso = ($_POST['tipo_ingreso'] ?? '') === 'otro' ? 'otro' : 'factura';
    $fecha = (string) ($_POST['fecha'] ?? '');
    $metodo = (string) ($_POST['metodo_pago'] ?? '');
    $referencia = trim((string) ($_POST['referencia'] ?? ''));
    $valor = filter_var($_POST['valor'] ?? '', FILTER_VALIDATE_FLOAT);
    $concepto = trim((string) ($_POST['concepto'] ?? ''));
    $documento = normalizarDocumento($_POST['documento'] ?? '');

    if (!fechaValida($fecha) || $fecha > date('Y-m-d')) $errores[] = 'La fecha debe ser válida y no puede ser futura.';
    if (!isset(METODOS_PAGO[$metodo])) $errores[] = 'Elige una forma de pago válida.';
    if ($valor === false || $valor <= 0) $errores[] = 'El valor recibido debe ser mayor que cero.';
    if (mb_strlen($referencia) > 100 || mb_strlen($concepto) > 255) $errores[] = 'La referencia o el concepto son demasiado largos.';

    if ($tipo_ingreso === 'factura') {
        $factura = $facturas_disponibles[$factura_elegida] ?? null;
        $retencion = filter_var($_POST['retencion'] ?? 0, FILTER_VALIDATE_FLOAT);
        if (!$factura) $errores[] = 'Elige una factura con saldo por recaudar.';
        if ($retencion === false || $retencion < 0) $errores[] = 'La retención no puede ser negativa.';
        if ($documento !== '' && !documentoValido($documento)) $errores[] = 'El NIT o la cédula del cliente no tiene un formato válido.';
    } else {
        $tercero_nombre = trim((string) ($_POST['tercero_nombre'] ?? ''));
        $cuenta = (string) ($_POST['cuenta_ingreso'] ?? '');
        if ($tercero_nombre === '' || mb_strlen($tercero_nombre) > 150) $errores[] = 'Escribe de quién se recibe el dinero.';
        if (!documentoValido($documento)) $errores[] = 'Escribe un NIT o una cédula válida para el tercero.';
        if (!isset($cuentas_ingreso[$cuenta])) $errores[] = 'Elige la cuenta contable del ingreso.';
        if ($concepto === '') $errores[] = 'Escribe el concepto del ingreso.';
    }

    if (!$errores) {
        try {
            asegurarConsecutivo($db, 'CI', substr($fecha, 0, 4));
            $db->beginTransaction();
            if ($tipo_ingreso === 'factura') {
                // Se bloquea la factura y se recalcula su saldo: dos recaudos simultáneos no pueden pasarse del total
                $db->prepare("SELECT id FROM facturas WHERE id = ? FOR UPDATE")->execute([$factura['id']]);
                $aplicado = aplicadoFactura($db, $factura['id']);
                $disponible = (float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'];
                if ($valor + $retencion > $disponible + 0.5) {
                    throw new InvalidArgumentException('El valor aplicado (' . dinero($valor + $retencion) . ') supera el saldo disponible de la factura (' . dinero($disponible) . ').');
                }
                if ($documento !== '') {
                    guardarTercero($db, $documento, $factura['cliente'], 'Cliente', $factura['id_usuario']);
                }
                $resultado = registrarIngresoFactura($db, $factura, [
                    'fecha' => $fecha, 'metodo' => $metodo, 'referencia' => $referencia, 'valor' => $valor, 'retencion' => $retencion,
                    'estado' => 'Pendiente', 'origen' => 'Manual', 'usuario' => $usuario['id'], 'concepto' => $concepto !== '' ? $concepto : null,
                ]);
                recalcularFactura($db, $factura['id']);
            } else {
                $tercero = guardarTercero($db, $documento, $tercero_nombre, 'Otro');
                $resultado = guardarComprobante($db, [
                    'tipo' => 'CI', 'fecha' => $fecha, 'tercero_id' => $tercero['id'], 'tercero_nombre' => $tercero['nombre'], 'tercero_documento' => $tercero['documento'],
                    'concepto' => $concepto, 'metodo_pago' => $metodo, 'referencia' => $referencia, 'valor_total' => $valor,
                    'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $usuario['id'],
                ], [
                    [cuentaDisponible($metodo), $concepto, $valor, 0],
                    [$cuenta, $concepto, 0, $valor],
                ]);
            }
            $db->commit();
            avisoSiguiente('success', "Comprobante {$resultado['numero']} registrado por " . dinero($valor) . '. Queda pendiente de la revisión de Finanzas.');
            header('Location: comprobante_ingreso.php?id=' . $resultado['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = $e->getMessage();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('nuevo_ingreso.php: ' . $e->getMessage());
            $errores[] = 'No se pudo guardar el comprobante. Intenta de nuevo.';
        }
    }
}

$campo = function ($nombre, $defecto = '') { return esc($_POST[$nombre] ?? $defecto); };
$tipo_actual = ($_POST['tipo_ingreso'] ?? '') === 'otro' ? 'otro' : 'factura';
$cuentas_js = [];
foreach (nombresCuentas($db) as $codigo => $nombre) $cuentas_js[(string) $codigo] = $nombre;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Ingreso | DataSense</title>
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
                <a href="comprobante_ingreso.php" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver</a>
                <span class="small text-muted">N° que se asignará al guardar: <b><?= esc(consecutivoPropuesto($db, 'CI', date('Y'))) ?></b></span>
            </div>

            <?php if ($errores): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" id="erroresFormulario">
                    <i class="fas fa-exclamation-triangle me-2"></i> <b>No se guardó el comprobante:</b>
                    <ul class="mb-0 mt-2"><?php foreach ($errores as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="formIngreso" autocomplete="off">
                <div class="panel-conta p-4 mb-4">
                    <div class="btn-group w-100 mb-4" role="group">
                        <input type="radio" class="btn-check" name="tipo_ingreso" id="tipoFactura" value="factura" <?= $tipo_actual === 'factura' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-success fw-bold py-2" for="tipoFactura"><i class="fas fa-file-invoice-dollar me-2"></i> Abono a factura de venta</label>
                        <input type="radio" class="btn-check" name="tipo_ingreso" id="tipoOtro" value="otro" <?= $tipo_actual === 'otro' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-success fw-bold py-2" for="tipoOtro"><i class="fas fa-coins me-2"></i> Otro ingreso</label>
                    </div>

                    <div id="seccionFactura" class="row g-3">
                        <div class="col-md-8">
                            <label class="small fw-bold text-muted mb-1">Factura a abonar *</label>
                            <select name="factura_id" id="facturaId" class="form-select">
                                <option value="">-- Facturas con saldo por recaudar --</option>
                                <?php foreach ($facturas_disponibles as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= $factura_elegida === (int) $f['id'] ? 'selected' : '' ?>>
                                        <?= esc($f['numero_factura']) ?> · <?= esc($f['cliente']) ?> · saldo <?= dinero($f['disponible']) ?><?= $f['estado'] === 'Vencido' ? ' · VENCIDA' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="infoFactura" class="small mt-2 text-muted"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">NIT / C.C. del cliente</label>
                            <input type="text" name="documento" id="documentoCliente" class="form-control" maxlength="20" placeholder="Opcional" value="<?= $tipo_actual === 'factura' ? $campo('documento') : '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Retención practicada por el cliente</label>
                            <input type="number" name="retencion" id="retencion" class="form-control text-end" min="0" step="0.01" value="<?= $campo('retencion', '0') ?>">
                            <div class="form-text">Se registra como anticipo de impuestos (135515).</div>
                        </div>
                    </div>

                    <div id="seccionOtro" class="row g-3">
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">Recibido de *</label>
                            <input type="text" name="tercero_nombre" class="form-control" maxlength="150" value="<?= $campo('tercero_nombre') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">NIT / C.C. *</label>
                            <input type="text" name="documento" class="form-control" maxlength="20" value="<?= $tipo_actual === 'otro' ? $campo('documento') : '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Cuenta del ingreso *</label>
                            <select name="cuenta_ingreso" id="cuentaIngreso" class="form-select">
                                <option value="">-- Elegir cuenta --</option>
                                <?php foreach ($cuentas_ingreso as $codigo => $nombre): ?>
                                    <option value="<?= esc($codigo) ?>" <?= ($_POST['cuenta_ingreso'] ?? '') === (string) $codigo ? 'selected' : '' ?>><?= esc($codigo) ?> · <?= esc($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="panel-conta p-4 mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Fecha *</label>
                            <input type="date" name="fecha" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= $campo('fecha', date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Forma de pago *</label>
                            <select name="metodo_pago" id="metodoPago" class="form-select" required>
                                <?php foreach (array_keys(METODOS_PAGO) as $m): ?>
                                    <option value="<?= esc($m) ?>" <?= ($_POST['metodo_pago'] ?? 'Transferencia') === $m ? 'selected' : '' ?>><?= esc($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Referencia bancaria</label>
                            <input type="text" name="referencia" class="form-control" maxlength="100" value="<?= $campo('referencia') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Valor recibido *</label>
                            <input type="number" name="valor" id="valor" class="form-control text-end fw-bold" min="0.01" step="0.01" value="<?= $campo('valor') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Concepto <span id="conceptoOpcional">(opcional: por defecto «Recaudo de la factura…»)</span></label>
                            <input type="text" name="concepto" class="form-control" maxlength="255" value="<?= $campo('concepto') ?>">
                        </div>
                    </div>
                </div>

                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: #059669;">Vista previa de la causación</div>
                    <table class="table table-sm linea-previa mb-0">
                        <thead><tr><th>Cuenta</th><th class="text-end">Débito</th><th class="text-end">Crédito</th></tr></thead>
                        <tbody id="previaCausacion"></tbody>
                        <tfoot><tr class="fila-total"><td>Sumas iguales</td><td class="text-end money" id="previaDebito">$ 0</td><td class="text-end money" id="previaCredito">$ 0</td></tr></tfoot>
                    </table>
                </div>

                <div class="text-end mb-5">
                    <button type="submit" id="btnGuardar" class="btn btn-success btn-lg rounded-pill fw-bold px-5 shadow" style="background: var(--verde-datasense); border: none;">
                        <i class="fas fa-save me-2"></i> Registrar ingreso
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
const FACTURAS = <?= jsJson(array_values($facturas_disponibles)) ?>;
const CUENTAS = <?= jsJson($cuentas_js) ?>;
const METODOS = <?= jsJson(METODOS_PAGO) ?>;
const pesos = v => '$ ' + (Math.round(v * 100) / 100).toLocaleString('es-CO', { maximumFractionDigits: 2 });
const $ = id => document.getElementById(id);

function tipoActual() { return document.querySelector('input[name="tipo_ingreso"]:checked').value; }

// Muestra solo la sección del tipo elegido; los campos ocultos se deshabilitan para no enviarse
function cambiarTipo() {
    const esFactura = tipoActual() === 'factura';
    for (const [id, visible] of [['seccionFactura', esFactura], ['seccionOtro', !esFactura]]) {
        $(id).hidden = !visible;
        $(id).querySelectorAll('input, select').forEach(el => { el.disabled = !visible; });
    }
    $('facturaId').required = esFactura;
    $('cuentaIngreso').required = !esFactura;
    document.querySelector('#seccionOtro input[name="tercero_nombre"]').required = !esFactura;
    document.querySelector('#seccionOtro input[name="documento"]').required = !esFactura;
    document.querySelector('input[name="concepto"]').required = !esFactura;
    $('conceptoOpcional').hidden = !esFactura;
    previsualizar();
}

function facturaElegida() { return FACTURAS.find(f => String(f.id) === $('facturaId').value); }

$('facturaId').addEventListener('change', () => {
    const f = facturaElegida();
    $('infoFactura').textContent = f ? `${f.cliente} · ${f.servicio_contratado} · total ${pesos(f.monto_total)} · disponible ${pesos(f.disponible)}` : '';
    if (f) {
        $('valor').value = f.disponible;
        $('retencion').value = 0;
        if (f.documento) $('documentoCliente').value = f.documento;
    }
    previsualizar();
});

function previsualizar() {
    const valor = parseFloat($('valor').value) || 0;
    const disponible = METODOS[$('metodoPago').value];
    let lineas;
    if (tipoActual() === 'factura') {
        const retencion = parseFloat($('retencion').value) || 0;
        lineas = [[disponible, valor, 0]];
        if (retencion > 0) lineas.push(['<?= CTA_ANTICIPO_RETENCION ?>', retencion, 0]);
        lineas.push(['<?= CTA_CLIENTES ?>', 0, valor + retencion]);
    } else {
        lineas = [[disponible, valor, 0], [$('cuentaIngreso').value || '', 0, valor]];
    }
    const cuerpo = $('previaCausacion');
    cuerpo.textContent = '';
    let deb = 0, cre = 0;
    for (const [cuenta, d, c] of lineas) {
        deb += d; cre += c;
        const fila = cuerpo.insertRow();
        fila.insertCell().textContent = cuenta ? `${cuenta} · ${CUENTAS[cuenta] || ''}` : '(elige la cuenta)';
        Object.assign(fila.insertCell(), { className: 'text-end money', textContent: d ? pesos(d) : '-' });
        Object.assign(fila.insertCell(), { className: 'text-end money', textContent: c ? pesos(c) : '-' });
    }
    $('previaDebito').textContent = pesos(deb);
    $('previaCredito').textContent = pesos(cre);
}

document.querySelectorAll('input[name="tipo_ingreso"]').forEach(r => r.addEventListener('change', cambiarTipo));
['valor', 'retencion', 'metodoPago', 'cuentaIngreso'].forEach(id => $(id).addEventListener('input', previsualizar));
$('metodoPago').addEventListener('change', previsualizar);
$('cuentaIngreso').addEventListener('change', previsualizar);
cambiarTipo();
if ($('facturaId').value && !$('valor').value) $('facturaId').dispatchEvent(new Event('change'));
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
