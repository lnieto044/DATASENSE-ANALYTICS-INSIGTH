<?php
// Nuevo comprobante de egreso: la causación (gasto, IVA descontable, retención y salida de caja o bancos) la calcula el servidor
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'comprobante_egreso');

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Nuevo Comprobante de Egreso";
$subtitulo = "Registra un pago: la causación se calcula sola y queda pendiente de revisión";

// Cuentas que puede afectar un pago: gastos (5), costos (6) y compras de activos fijos (15)
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
$conceptos = array_values(array_unique(array_merge(
    ['Arrendamiento de la oficina', 'Honorarios profesionales', 'Servicio de nube y hosting', 'Servicios públicos', 'Papelería y útiles de oficina', 'Compra de equipos de cómputo', 'Nómina y seguridad social'],
    $db->query("SELECT DISTINCT concepto FROM comprobantes WHERE tipo = 'CE' AND origen_id IS NULL ORDER BY concepto LIMIT 50")->fetchAll(PDO::FETCH_COLUMN)
)));

// Cuentas por pagar aprobadas con saldo: se pagan desde aquí (débito a proveedores y salida de caja o bancos)
$cxp_abiertas = [];
foreach ($db->query("SELECT c.id, c.numero, c.fecha, c.fecha_vencimiento, c.tercero_nombre, c.referencia, c.valor_total,
                            COALESCE(p.pagado, 0) AS pagado, COALESCE(p.pago_en_tramite, 0) AS pago_en_tramite
                     FROM comprobantes c
                     LEFT JOIN (" . sqlPagadoCuentasPorPagar() . ") p ON p.cxp_id = c.id
                     WHERE c.tipo = 'FP' AND c.estado = 'Aprobado'
                     ORDER BY c.fecha_vencimiento, c.id") as $c) {
    $c['saldo'] = round((float) $c['valor_total'] - (float) $c['pagado'] - (float) $c['pago_en_tramite'], 2);
    if ($c['saldo'] > 0.5) $cxp_abiertas[(int) $c['id']] = $c;
}
$cxp_elegida = (int) ($_POST['cxp_id'] ?? $_GET['cxp'] ?? 0);
$tipo_egreso = ($_POST['tipo_egreso'] ?? ($cxp_elegida ? 'cxp' : 'gasto')) === 'cxp' ? 'cxp' : 'gasto';

$errores = [];
$texto = function ($campo) { return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : ''; };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tipo_egreso === 'cxp') {
    // Pago de una cuenta por pagar: débito a proveedores y salida de caja o bancos
    $fecha = $texto('fecha');
    $metodo = $texto('metodo_pago');
    $referencia = $texto('referencia');
    $valor = filter_var($texto('valor_pago'), FILTER_VALIDATE_FLOAT);
    $cxp = $cxp_abiertas[$cxp_elegida] ?? null;

    if (!$cxp) $errores[] = 'Elige una cuenta por pagar aprobada que tenga saldo.';
    if (!fechaValida($fecha) || $fecha > date('Y-m-d')) $errores[] = 'La fecha del pago debe ser válida y no puede ser futura.';
    elseif ($cxp && $fecha < $cxp['fecha']) $errores[] = 'El pago no puede ser anterior a la factura del proveedor.';
    if (!isset(METODOS_PAGO[$metodo])) $errores[] = 'Elige una forma de pago válida.';
    if (mb_strlen($referencia) > 100) $errores[] = 'La referencia es demasiado larga.';
    if ($valor === false || $valor <= 0) $errores[] = 'El valor a pagar debe ser mayor que cero.';

    if (!$errores) {
        $valor = round($valor, 2);
        $concepto = 'Pago de la factura ' . $cxp['referencia'] . ' (' . $cxp['numero'] . ')';
        try {
            asegurarConsecutivo($db, 'CE', substr($fecha, 0, 4));
            $db->beginTransaction();
            // Se bloquea la cuenta por pagar y se recalcula su saldo: dos pagos simultáneos no pueden pasarse del total
            $db->prepare("SELECT id FROM comprobantes WHERE id = ? FOR UPDATE")->execute([$cxp['id']]);
            $actual = cuentaPorPagar($db, $cxp['id']);
            if (!$actual || $actual['estado'] !== 'Aprobado') throw new InvalidArgumentException('La cuenta por pagar ya no está aprobada.');
            if ($valor > $actual['saldo'] + 0.005) {
                throw new InvalidArgumentException('El pago (' . dinero($valor, 2) . ') supera el saldo de la cuenta por pagar (' . dinero($actual['saldo'], 2) . ').');
            }
            $resultado = guardarComprobante($db, [
                'tipo' => 'CE', 'fecha' => $fecha, 'tercero_id' => $actual['tercero_id'], 'tercero_nombre' => $actual['tercero_nombre'], 'tercero_documento' => $actual['tercero_documento'],
                'concepto' => $concepto, 'metodo_pago' => $metodo, 'referencia' => $referencia, 'valor_total' => $valor, 'origen_id' => (int) $actual['id'],
                'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $usuario['id'],
            ], [
                [CTA_PROVEEDORES, $concepto, $valor, 0],
                [cuentaDisponible($metodo), "Pago $metodo" . ($referencia !== '' ? " ref. $referencia" : ''), 0, $valor],
            ]);
            $db->commit();
            avisoSiguiente('success', "Comprobante {$resultado['numero']} registrado por " . dinero($valor, 2) . " para pagar {$actual['numero']}. Queda pendiente de la revisión de Finanzas.");
            header('Location: comprobante_egreso.php?id=' . $resultado['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = $e->getMessage();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('nuevo_egreso.php (cuenta por pagar): ' . $e->getMessage());
            $errores[] = 'No se pudo guardar el comprobante. Intenta de nuevo.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tipo_egreso === 'gasto') {
    $documento = normalizarDocumento($texto('documento'));
    $beneficiario = $texto('beneficiario');
    $fecha = $texto('fecha');
    $metodo = $texto('metodo_pago');
    $referencia = $texto('referencia');
    $concepto = $texto('concepto');
    $cuenta = $texto('cuenta_gasto');
    $base = filter_var($texto('base'), FILTER_VALIDATE_FLOAT);
    $iva_pct = $texto('iva_pct');
    $retencion_pct = $texto('retencion_pct');

    if (!documentoValido($documento)) $errores[] = 'Escribe el NIT o la cédula del beneficiario (números, con guion antes del dígito de verificación si aplica).';
    if ($beneficiario === '' || mb_strlen($beneficiario) > 150) $errores[] = 'Escribe el nombre del beneficiario.';
    if (!fechaValida($fecha) || $fecha > date('Y-m-d')) $errores[] = 'La fecha del pago debe ser válida y no puede ser futura.';
    if (!isset(METODOS_PAGO[$metodo])) $errores[] = 'Elige una forma de pago válida.';
    if ($concepto === '' || mb_strlen($concepto) > 255) $errores[] = 'Escribe el concepto del pago.';
    if (mb_strlen($referencia) > 100) $errores[] = 'La referencia es demasiado larga.';
    if (!isset($cuentas_gasto[$cuenta])) $errores[] = 'Elige la cuenta del gasto o del activo.';
    if ($base === false || $base <= 0) $errores[] = 'La base (valor antes de impuestos) debe ser mayor que cero.';
    if (!in_array($iva_pct, TARIFAS_IVA, true)) $errores[] = 'Tarifa de IVA no válida.';
    if (!isset(RETENCIONES_EGRESO[$retencion_pct])) $errores[] = 'Porcentaje de retención no válido.';

    if (!$errores) {
        // Liquidación: IVA y retención se redondean al peso; el neto es lo que sale de caja o bancos
        $base = round($base, 2);
        $iva = round($base * (float) $iva_pct / 100);
        list($cuenta_retencion, $descripcion_retencion) = RETENCIONES_EGRESO[$retencion_pct];
        $retencion = round($base * (float) $retencion_pct / 100);
        $neto = round($base + $iva - $retencion, 2);

        $lineas = [[$cuenta, $concepto, $base, 0]];
        if ($iva > 0) $lineas[] = [CTA_IVA_DESCONTABLE, "IVA descontable $iva_pct%", $iva, 0];
        if ($retencion > 0) $lineas[] = [$cuenta_retencion, "Retención en la fuente $descripcion_retencion", 0, $retencion];
        $lineas[] = [cuentaDisponible($metodo), "Pago $metodo" . ($referencia !== '' ? " ref. $referencia" : ''), 0, $neto];

        try {
            asegurarConsecutivo($db, 'CE', substr($fecha, 0, 4));
            $db->beginTransaction();
            $tercero = guardarTercero($db, $documento, $beneficiario, 'Proveedor');
            $resultado = guardarComprobante($db, [
                'tipo' => 'CE', 'fecha' => $fecha, 'tercero_id' => $tercero['id'], 'tercero_nombre' => $tercero['nombre'], 'tercero_documento' => $tercero['documento'],
                'concepto' => $concepto, 'metodo_pago' => $metodo, 'referencia' => $referencia, 'valor_total' => $neto,
                'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $usuario['id'],
            ], $lineas);
            $db->commit();
            $nota = mb_strtolower($tercero['nombre']) !== mb_strtolower($beneficiario)
                ? " El documento $documento ya estaba registrado como «{$tercero['nombre']}» y se conservó ese nombre." : '';
            avisoSiguiente('success', "Comprobante {$resultado['numero']} registrado por " . dinero($neto) . ". Queda pendiente de la revisión de Finanzas.$nota");
            header('Location: comprobante_egreso.php?id=' . $resultado['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = $e->getMessage();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('nuevo_egreso.php: ' . $e->getMessage());
            $errores[] = 'No se pudo guardar el comprobante. Intenta de nuevo.';
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
    <title>Nuevo Egreso | DataSense</title>
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
                <a href="comprobante_egreso.php" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver</a>
                <span class="small text-muted">N° que se asignará al guardar: <b><?= esc(consecutivoPropuesto($db, 'CE', date('Y'))) ?></b></span>
            </div>

            <?php if ($errores): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" id="erroresFormulario">
                    <i class="fas fa-exclamation-triangle me-2"></i> <b>No se guardó el comprobante:</b>
                    <ul class="mb-0 mt-2"><?php foreach ($errores as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="formEgreso" autocomplete="off">
                <div class="panel-conta p-3 mb-4 d-flex flex-wrap gap-4 align-items-center">
                    <span class="small fw-bold text-muted text-uppercase">¿Qué vas a registrar?</span>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="tipo_egreso" id="tipoGasto" value="gasto" <?= $tipo_egreso === 'gasto' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="tipoGasto">Gasto o compra pagada de contado</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="tipo_egreso" id="tipoCxp" value="cxp" <?= $tipo_egreso === 'cxp' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="tipoCxp">Pago de una cuenta por pagar <span class="badge bg-light text-dark border ms-1"><?= count($cxp_abiertas) ?></span></label>
                    </div>
                </div>

                <fieldset id="seccionCxp" <?= $tipo_egreso === 'cxp' ? '' : 'disabled hidden' ?>>
                    <div class="panel-conta p-4 mb-4">
                        <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-file-invoice me-2"></i> Cuenta por pagar y pago</div>
                        <?php if (!$cxp_abiertas): ?>
                            <div class="alert alert-info small">No hay cuentas por pagar aprobadas con saldo. Las facturas de proveedores se registran en <a href="cuentas_por_pagar.php">Cuentas por Pagar</a>.</div>
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="small fw-bold text-muted mb-1">Cuenta por pagar *</label>
                                <select name="cxp_id" id="cxpId" class="form-select" required>
                                    <option value="">-- Elegir --</option>
                                    <?php foreach ($cxp_abiertas as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" data-saldo="<?= esc($c['saldo']) ?>" <?= $cxp_elegida === (int) $c['id'] ? 'selected' : '' ?>>
                                            <?= esc($c['numero']) ?> · <?= esc($c['tercero_nombre']) ?> · Fact. <?= esc($c['referencia']) ?> · saldo <?= dinero($c['saldo'], 2) ?><?= $c['fecha_vencimiento'] ? ' · vence ' . date('d/m/Y', strtotime($c['fecha_vencimiento'])) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted mb-1">Valor a pagar *</label>
                                <input type="number" name="valor_pago" id="valorPago" class="form-control text-end fw-bold" min="0.01" step="0.01" required value="<?= $campo('valor_pago') ?>">
                                <div class="form-text" id="infoCxp"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted mb-1">Fecha del pago *</label>
                                <input type="date" name="fecha" class="form-control" max="<?= date('Y-m-d') ?>" required value="<?= $campo('fecha', date('Y-m-d')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted mb-1">Forma de pago *</label>
                                <select name="metodo_pago" id="metodoPagoCxp" class="form-select" required>
                                    <?php foreach (array_keys(METODOS_PAGO) as $m): ?>
                                        <option value="<?= esc($m) ?>" <?= $elegido('metodo_pago', $m, 'Transferencia') ?>><?= esc($m) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted mb-1">Referencia</label>
                                <input type="text" name="referencia" class="form-control" maxlength="100" value="<?= $campo('referencia') ?>">
                            </div>
                        </div>
                    </div>
                </fieldset>

                <fieldset id="seccionGasto" <?= $tipo_egreso === 'gasto' ? '' : 'disabled hidden' ?>>
                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-user-tie me-2"></i> 1. Beneficiario y pago</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">NIT / C.C. *</label>
                            <input type="text" name="documento" id="documento" class="form-control fw-bold" list="listaTerceros" maxlength="20" required placeholder="Ej: 901234567-1" value="<?= $campo('documento') ?>">
                            <datalist id="listaTerceros">
                                <?php foreach ($terceros as $t): ?><option value="<?= esc($t['documento']) ?>"><?= esc($t['nombre']) ?></option><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">Pagado a (beneficiario) *</label>
                            <input type="text" name="beneficiario" id="beneficiario" class="form-control" maxlength="150" required value="<?= $campo('beneficiario') ?>">
                            <div id="avisoTercero" class="form-text text-success"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Fecha del pago *</label>
                            <input type="date" name="fecha" class="form-control" max="<?= date('Y-m-d') ?>" required value="<?= $campo('fecha', date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Forma de pago *</label>
                            <select name="metodo_pago" id="metodoPago" class="form-select" required>
                                <?php foreach (array_keys(METODOS_PAGO) as $m): ?>
                                    <option value="<?= esc($m) ?>" <?= $elegido('metodo_pago', $m, 'Transferencia') ?>><?= esc($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Referencia</label>
                            <input type="text" name="referencia" class="form-control" maxlength="100" value="<?= $campo('referencia') ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">Por concepto de *</label>
                            <input type="text" name="concepto" class="form-control" list="listaConceptos" maxlength="255" required value="<?= $campo('concepto') ?>">
                            <datalist id="listaConceptos">
                                <?php foreach ($conceptos as $c): ?><option value="<?= esc($c) ?>"></option><?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                </div>

                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-calculator me-2"></i> 2. Valores e impuestos</div>
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
                            <div class="h-100 rounded-4 p-4 text-center text-white d-flex flex-column justify-content-center" style="background: var(--azul-sonda);">
                                <small class="text-uppercase fw-bold opacity-75">Valor neto a pagar</small>
                                <div class="fs-2 fw-bold" id="netoPagar">$ 0</div>
                                <small class="opacity-75" id="detalleNeto">Base + IVA − retención</small>
                            </div>
                        </div>
                    </div>
                </div>
                </fieldset>

                <div class="text-end mb-5">
                    <button type="submit" id="btnGuardar" class="btn btn-dark btn-lg rounded-pill fw-bold px-5 shadow" style="background: var(--azul-sonda);">
                        <i class="fas fa-save me-2"></i> Registrar egreso
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
const CUENTAS = <?= jsJson($cuentas) ?>;
const TERCEROS = <?= jsJson($terceros) ?>;
const METODOS = <?= jsJson(METODOS_PAGO) ?>;
const RETENCIONES = <?= jsJson(RETENCIONES_EGRESO) ?>;
const pesos = v => '$ ' + (Math.round(v * 100) / 100).toLocaleString('es-CO', { maximumFractionDigits: 2 });
const $ = id => document.getElementById(id);

// Si el NIT ya existe se usa el nombre registrado (el servidor hace lo mismo)
$('documento').addEventListener('input', () => {
    const doc = $('documento').value.toUpperCase().replace(/[^0-9A-Z-]/g, '');
    const tercero = TERCEROS.find(t => t.documento === doc);
    $('avisoTercero').textContent = tercero ? `Tercero registrado: ${tercero.nombre}` : '';
    if (tercero) $('beneficiario').value = tercero.nombre;
});

function previsualizar() {
    const base = parseFloat($('base').value) || 0;
    const iva = Math.round(base * parseFloat($('ivaPct').value) / 100);
    const pctRet = $('retencionPct').value;
    const retencion = Math.round(base * parseFloat(pctRet) / 100);
    const neto = base + iva - retencion;
    const lineas = [[$('cuentaGasto').value, base, 0]];
    if (iva > 0) lineas.push(['<?= CTA_IVA_DESCONTABLE ?>', iva, 0]);
    if (retencion > 0) lineas.push([RETENCIONES[pctRet][0], 0, retencion]);
    lineas.push([METODOS[$('metodoPago').value], 0, neto]);

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
['base', 'ivaPct', 'retencionPct', 'cuentaGasto', 'metodoPago'].forEach(id => {
    $(id).addEventListener('input', previsualizar);
    $(id).addEventListener('change', previsualizar);
});
previsualizar();

// Tipo de egreso: la sección que no aplica queda deshabilitada (no se envía ni se valida)
function mostrarTipoEgreso() {
    const cxp = $('tipoCxp').checked;
    [['seccionCxp', cxp], ['seccionGasto', !cxp]].forEach(([id, activa]) => { $(id).disabled = !activa; $(id).hidden = !activa; });
}
document.querySelectorAll('input[name="tipo_egreso"]').forEach(radio => radio.addEventListener('change', mostrarTipoEgreso));
function elegirCxp() {
    const opcion = $('cxpId').selectedOptions[0];
    const saldo = opcion && opcion.value ? parseFloat(opcion.dataset.saldo) : 0;
    $('valorPago').max = saldo || '';
    $('infoCxp').textContent = saldo ? `Saldo por pagar: ${pesos(saldo)}` : '';
    if (saldo && !$('valorPago').value) $('valorPago').value = saldo;
}
$('cxpId').addEventListener('change', () => { $('valorPago').value = ''; elegirCxp(); });
elegirCxp();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
