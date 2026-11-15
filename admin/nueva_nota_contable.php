<?php
// Nueva nota contable: registros que no son recaudos ni pagos (ajustes, depreciaciones, provisiones, causación de nómina,
// gastos bancarios del extracto, diferencia en cambio y reclasificaciones). La partida doble se valida en el servidor
// y la nota queda pendiente de revisión, igual que los comprobantes de ingreso y egreso.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/reportes.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'nota_contable');

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Nueva Nota Contable";
$subtitulo = "Ajustes, depreciaciones, provisiones y reclasificaciones con partida doble";
$empresa = datosEmpresa();

// Cuentas auxiliares que admite una nota (la cartera de clientes solo se mueve con facturas y comprobantes de ingreso)
$cuentas = [];
foreach (nombresCuentas($db) as $codigo => $nombre) {
    $codigo = (string) $codigo;
    if (strlen($codigo) === 6 && !in_array($codigo, [CTA_CLIENTES, CTA_PROVEEDORES], true)) $cuentas[$codigo] = $nombre;
}
// Saldo aprobado de cada cuenta según su naturaleza: ayuda a ver qué se está ajustando
$saldos = [];
foreach (saldosPorCuenta($db, null, date('Y-m-d'), 6) as $s) {
    $neto = (float) $s['debitos'] - (float) $s['creditos'];
    $saldos[(string) $s['cuenta']] = round(naturalezaCuenta($s['cuenta']) === 'D' ? $neto : -$neto, 2);
}
$terceros = $db->query("SELECT documento, nombre FROM terceros ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Plantillas: [texto del botón, icono, clase, concepto sugerido, [[cuenta, detalle, D|C], ...]]. Los valores los escribe el usuario.
$plantillas = [
    'bancos'          => ['Gastos bancarios y 4x1000', 'fa-university', 'Gastos bancarios y GMF', 'Gastos bancarios y 4x1000 según extracto',
                          [['530505', 'Cuota de manejo y comisiones del extracto', 'D'], ['511595', 'Gravamen a los movimientos financieros (4x1000)', 'D'], [CTA_BANCOS, 'Débitos del extracto bancario', 'C']]],
    'depreciacion'    => ['Depreciación del mes', 'fa-laptop', 'Depreciación y amortización', 'Depreciación mensual de equipos de cómputo',
                          [['516020', 'Depreciación del mes', 'D'], ['159220', 'Depreciación acumulada', 'C']]],
    'nomina'          => ['Causación de nómina', 'fa-users', 'Causación de nómina', 'Causación de la nómina del mes',
                          [['510506', 'Sueldos del mes', 'D'], ['250505', 'Salarios por pagar', 'C']]],
    'cambio'          => ['Diferencia en cambio', 'fa-exchange-alt', 'Diferencia en cambio', 'Ajuste de saldos en dólares a la TRM de cierre',
                          [[CTA_PAYPAL, 'Ajuste del saldo a la TRM de cierre', 'D'], [CTA_DIFERENCIA_CAMBIO, 'Diferencia en cambio a favor', 'C']]],
    'reclasificacion' => ['Reclasificación', 'fa-random', 'Reclasificación', 'Reclasificación de un valor registrado en otra cuenta',
                          [['', 'Cuenta correcta', 'D'], ['', 'Cuenta en la que se registró', 'C']]],
];

$errores = [];
$form = ['fecha' => date('Y-m-d'), 'clase_nota' => '', 'concepto' => '', 'referencia' => '', 'documento' => '', 'tercero' => ''];
$lineas_form = [['', '', '', ''], ['', '', '', '']];

// Nota de reversión: copia una nota aprobada con débitos y créditos invertidos (así se corrige sin borrar nada)
$reversar = (int) ($_GET['reversar'] ?? 0);
$original = null;
if ($reversar && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->prepare("SELECT * FROM comprobantes WHERE id = ? AND tipo = 'NC' AND estado = 'Aprobado'");
    $stmt->execute([$reversar]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$original) {
        avisoSiguiente('warning', 'Solo se pueden reversar notas contables aprobadas.');
        header('Location: nota_contable.php');
        exit;
    }
    $detalle = $db->prepare("SELECT codigo_puc, detalle, debito, credito FROM comprobantes_detalle WHERE comprobante_id = ? ORDER BY id");
    $detalle->execute([$reversar]);
    $lineas_form = [];
    foreach ($detalle->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $lineas_form[] = [(string) $l['codigo_puc'], mb_substr('Reversión: ' . $l['detalle'], 0, 255),
                          (float) $l['credito'] > 0 ? (string) (float) $l['credito'] : '', (float) $l['debito'] > 0 ? (string) (float) $l['debito'] : ''];
    }
    $a_nombre_empresa = $original['tercero_id'] === null;
    $form = ['fecha' => date('Y-m-d'), 'clase_nota' => 'Corrección de error', 'concepto' => mb_substr('Reversión de ' . $original['numero'] . ': ' . $original['concepto'], 0, 255),
             'referencia' => $original['numero'], 'documento' => $a_nombre_empresa ? '' : (string) $original['tercero_documento'], 'tercero' => $a_nombre_empresa ? '' : $original['tercero_nombre']];
}

// Movimiento del extracto bancario que no está en los libros (cuota de manejo, 4x1000, intereses): se registra con una nota
$movimiento_extracto = null;
$extracto_id = (int) ($_GET['extracto'] ?? 0);
if ($extracto_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->prepare("SELECT e.*, k.cuenta, k.periodo FROM extracto_movimientos e JOIN conciliaciones k ON k.id = e.conciliacion_id
                          WHERE e.id = ? AND e.detalle_id IS NULL AND k.estado = 'Abierta'");
    $stmt->execute([$extracto_id]);
    $movimiento_extracto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($movimiento_extracto) {
        $valor = abs((float) $movimiento_extracto['valor']);
        $descripcion = mb_substr($movimiento_extracto['descripcion'], 0, 200);
        $sale_del_banco = (float) $movimiento_extracto['valor'] < 0;
        $lineas_form = $sale_del_banco
            ? [['530505', $descripcion, (string) $valor, ''], [(string) $movimiento_extracto['cuenta'], 'Débito del extracto: ' . $descripcion, '', (string) $valor]]
            : [[(string) $movimiento_extracto['cuenta'], 'Crédito del extracto: ' . $descripcion, (string) $valor, ''], [CTA_INTERESES, $descripcion, '', (string) $valor]];
        $form = ['fecha' => $movimiento_extracto['fecha'], 'clase_nota' => $sale_del_banco ? 'Gastos bancarios y GMF' : 'Ajuste contable',
                 'concepto' => mb_substr('Extracto ' . $movimiento_extracto['periodo'] . ': ' . $movimiento_extracto['descripcion'], 0, 255),
                 'referencia' => $movimiento_extracto['referencia'] ?: 'Extracto ' . $movimiento_extracto['periodo'], 'documento' => '', 'tercero' => ''];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto = function ($campo) { return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : ''; };
    $lista = function ($campo) { return is_array($_POST[$campo] ?? null) ? array_values($_POST[$campo]) : []; };
    foreach (array_keys($form) as $campo) $form[$campo] = $texto($campo);
    $documento = normalizarDocumento($form['documento']);

    if (!fechaValida($form['fecha']) || $form['fecha'] > date('Y-m-d')) $errores[] = 'La fecha de la nota debe ser válida y no puede ser futura.';
    if (!in_array($form['clase_nota'], CLASES_NOTA, true)) $errores[] = 'Elige la clase de nota.';
    if ($form['concepto'] === '' || mb_strlen($form['concepto']) > 255) $errores[] = 'Escribe el concepto de la nota.';
    if (mb_strlen($form['referencia']) > 100) $errores[] = 'La referencia del soporte es demasiado larga.';
    // Sin tercero, la nota queda a nombre de la empresa (depreciaciones, provisiones, ajustes internos)
    $con_tercero = $form['documento'] !== '' || $form['tercero'] !== '';
    if ($con_tercero && !documentoValido($documento)) $errores[] = 'Escribe un NIT o cédula válido para el tercero, o deja ambos campos vacíos para registrar la nota a nombre de la empresa.';
    if ($con_tercero && ($form['tercero'] === '' || mb_strlen($form['tercero']) > 150)) $errores[] = 'Escribe el nombre del tercero.';

    $c_cuentas = $lista('cuenta'); $c_detalles = $lista('detalle'); $c_debitos = $lista('debito'); $c_creditos = $lista('credito');
    $total_filas = max(count($c_cuentas), count($c_debitos), count($c_creditos));
    if ($total_filas > MAX_LINEAS_NOTA) $errores[] = 'Una nota admite máximo ' . MAX_LINEAS_NOTA . ' movimientos.';

    $lineas = [];
    $lineas_form = [];
    for ($i = 0; $i < min($total_filas, MAX_LINEAS_NOTA); $i++) {
        $valor_de = function ($arreglo) use ($i) { return is_string($arreglo[$i] ?? null) ? trim($arreglo[$i]) : ''; };
        $cuenta = $valor_de($c_cuentas); $detalle_linea = $valor_de($c_detalles); $debito_txt = $valor_de($c_debitos); $credito_txt = $valor_de($c_creditos);
        $lineas_form[] = [$cuenta, $detalle_linea, $debito_txt, $credito_txt];
        if ($cuenta === '' && $debito_txt === '' && $credito_txt === '') continue; // Fila en blanco

        $fila = $i + 1;
        $debito = $debito_txt === '' ? 0.0 : filter_var($debito_txt, FILTER_VALIDATE_FLOAT);
        $credito = $credito_txt === '' ? 0.0 : filter_var($credito_txt, FILTER_VALIDATE_FLOAT);
        if ($cuenta === '') { $errores[] = "Línea $fila: elige la cuenta."; continue; }
        if ($debito === false || $credito === false || $debito < 0 || $credito < 0) { $errores[] = "Línea $fila: los valores deben ser números positivos."; continue; }
        if (mb_strlen($detalle_linea) > 255) { $errores[] = "Línea $fila: el detalle es demasiado largo."; continue; }
        $lineas[] = [$cuenta, $detalle_linea !== '' ? $detalle_linea : $form['concepto'], round($debito, 2), round($credito, 2)];
    }
    if (count($lineas_form) < 2) $lineas_form = array_pad($lineas_form, 2, ['', '', '', '']);

    if (!$errores) {
        $total = round(array_sum(array_column($lineas, 2)), 2);
        try {
            asegurarConsecutivo($db, 'NC', substr($form['fecha'], 0, 4));
            $db->beginTransaction();
            $tercero = $con_tercero ? guardarTercero($db, $documento, $form['tercero'], 'Otro') : null;
            $resultado = guardarComprobante($db, [
                'tipo' => 'NC', 'fecha' => $form['fecha'],
                'tercero_id' => $tercero['id'] ?? null, 'tercero_nombre' => $tercero['nombre'] ?? $empresa['nombre'],
                'tercero_documento' => $tercero['documento'] ?? normalizarDocumento($empresa['nit']),
                'concepto' => $form['concepto'], 'metodo_pago' => 'No aplica', 'referencia' => $form['referencia'], 'valor_total' => $total,
                'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $usuario['id'], 'clase_nota' => $form['clase_nota'],
            ], $lineas);
            $db->commit();
            avisoSiguiente('success', "Nota contable {$resultado['numero']} registrada por " . dinero($total, 2) . '. Queda pendiente de la revisión de Finanzas.');
            header('Location: nota_contable.php?id=' . $resultado['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = $e->getMessage();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('nueva_nota_contable.php: ' . $e->getMessage());
            $errores[] = 'No se pudo guardar la nota. Intenta de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Nota Contable | DataSense</title>
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
                <a href="nota_contable.php" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver</a>
                <span class="small text-muted">N° que se asignará al guardar: <b id="consecutivoPropuesto"><?= esc(consecutivoPropuesto($db, 'NC', date('Y'))) ?></b></span>
            </div>

            <?php if ($movimiento_extracto): ?>
                <div class="alert alert-info rounded-4 border-0 shadow-sm" id="avisoExtracto">
                    <i class="fas fa-university me-2"></i> Movimiento del extracto de <b><?= esc($movimiento_extracto['periodo']) ?></b> que no está en los libros. Revisa la cuenta de contrapartida, guarda la nota y, cuando esté aprobada, concílialo.
                </div>
            <?php endif; ?>
            <?php if ($original): ?>
                <div class="alert alert-info rounded-4 border-0 shadow-sm" id="avisoReversion">
                    <i class="fas fa-undo me-2"></i> Nota de reversión de <b><?= esc($original['numero']) ?></b>: los débitos y créditos quedaron invertidos. Revisa los valores y guarda.
                </div>
            <?php endif; ?>

            <?php if ($errores): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm" id="erroresFormulario">
                    <i class="fas fa-exclamation-triangle me-2"></i> <b>No se guardó la nota:</b>
                    <ul class="mb-0 mt-2"><?php foreach ($errores as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="formNota" autocomplete="off">
                <div class="panel-conta p-4 mb-4">
                    <div class="doc-subtitulo" style="color: #6d28d9;"><i class="fas fa-file-signature me-2"></i> 1. Datos de la nota</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">Fecha *</label>
                            <input type="date" name="fecha" class="form-control" max="<?= date('Y-m-d') ?>" required value="<?= esc($form['fecha']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Clase *</label>
                            <select name="clase_nota" id="claseNota" class="form-select" required>
                                <option value="">-- Elegir --</option>
                                <?php foreach (CLASES_NOTA as $clase): ?>
                                    <option value="<?= esc($clase) ?>" <?= $form['clase_nota'] === $clase ? 'selected' : '' ?>><?= esc($clase) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">Soporte o referencia</label>
                            <input type="text" name="referencia" class="form-control" maxlength="100" placeholder="Ej: Extracto bancario de agosto, planilla de nómina" value="<?= esc($form['referencia']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Concepto *</label>
                            <input type="text" name="concepto" id="concepto" class="form-control" maxlength="255" required value="<?= esc($form['concepto']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">NIT / C.C. del tercero</label>
                            <input type="text" name="documento" id="documento" class="form-control" list="listaTerceros" maxlength="20" value="<?= esc($form['documento']) ?>">
                            <datalist id="listaTerceros">
                                <?php foreach ($terceros as $t): ?><option value="<?= esc($t['documento']) ?>"><?= esc($t['nombre']) ?></option><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-8">
                            <label class="small fw-bold text-muted mb-1">Nombre del tercero</label>
                            <input type="text" name="tercero" id="tercero" class="form-control" maxlength="150" value="<?= esc($form['tercero']) ?>">
                            <div class="form-text" id="avisoTercero">Déjalos vacíos para registrar la nota a nombre de <?= esc($empresa['nombre']) ?>.</div>
                        </div>
                    </div>
                </div>

                <div class="panel-conta p-4 mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="doc-subtitulo mb-0" style="color: #6d28d9;"><i class="fas fa-balance-scale me-2"></i> 2. Movimientos (partida doble)</div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="small text-muted fw-bold">Plantillas:</span>
                            <?php foreach ($plantillas as $clave => [$texto_boton, $icono]): ?>
                                <button type="button" class="plantilla-nota" data-plantilla="<?= esc($clave) ?>"><i class="fas <?= esc($icono) ?> me-1"></i> <?= esc($texto_boton) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table tabla-nota mb-2">
                            <thead><tr><th style="width: 34%;">Cuenta</th><th>Detalle</th><th class="text-end" style="width: 16%;">Débito</th><th class="text-end" style="width: 16%;">Crédito</th><th style="width: 44px;"></th></tr></thead>
                            <tbody id="lineasNota"></tbody>
                        </table>
                    </div>
                    <button type="button" id="agregarLinea" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fas fa-plus me-1"></i> Agregar línea</button>

                    <div class="row g-3 mt-2 align-items-stretch">
                        <div class="col-md-8">
                            <table class="table table-sm linea-previa mb-0">
                                <tbody>
                                    <tr><td>Total débitos</td><td class="text-end money" id="totalDebitos">$ 0</td></tr>
                                    <tr><td>Total créditos</td><td class="text-end money" id="totalCreditos">$ 0</td></tr>
                                </tbody>
                            </table>
                            <p class="small text-muted mt-2 mb-0"><i class="fas fa-info-circle me-1"></i> Cada línea lleva un solo valor (débito o crédito). La cuenta de clientes no se mueve con notas: los abonos a facturas se registran con comprobante de ingreso.</p>
                        </div>
                        <div class="col-md-4">
                            <div class="cuadre-nota descuadrada h-100 d-flex flex-column justify-content-center">
                                <small class="text-uppercase fw-bold opacity-75">Cuadre</small>
                                <div class="fw-bold fs-5" id="estadoCuadre">Escribe los valores</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end mb-5">
                    <button type="submit" id="btnGuardar" class="btn btn-lg rounded-pill fw-bold px-5 shadow text-white" style="background: #6d28d9;">
                        <i class="fas fa-save me-2"></i> Registrar nota contable
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<template id="plantillaLinea">
    <tr class="linea-nota">
        <td>
            <select name="cuenta[]" class="form-select form-select-sm">
                <option value="">-- Cuenta --</option>
                <?php foreach ($cuentas as $codigo => $nombre): ?>
                    <option value="<?= esc($codigo) ?>"><?= esc($codigo) ?> · <?= esc($nombre) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="saldo-cuenta text-muted"></small>
        </td>
        <td><input type="text" name="detalle[]" class="form-control form-control-sm" maxlength="255"></td>
        <td><input type="number" name="debito[]" class="form-control form-control-sm valor-nota" min="0" step="0.01"></td>
        <td><input type="number" name="credito[]" class="form-control form-control-sm valor-nota" min="0" step="0.01"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger quitar-linea" title="Quitar línea"><i class="fas fa-times"></i></button></td>
    </tr>
</template>

<script>
const CUENTAS = <?= jsJson($cuentas) ?>;
const SALDOS = <?= jsJson($saldos) ?>;
const PLANTILLAS = <?= jsJson($plantillas) ?>;
const TERCEROS = <?= jsJson($terceros) ?>;
const LINEAS_INICIALES = <?= jsJson($lineas_form) ?>;
const MAX_LINEAS = <?= (int) MAX_LINEAS_NOTA ?>;
const $ = id => document.getElementById(id);
const cuerpo = $('lineasNota');
// Formato colombiano sin depender del navegador: $ 1.234.567,89
const pesos = v => {
    const [entero, decimales] = (Math.round(Math.abs(v) * 100) / 100).toFixed(2).split('.');
    return (v < 0 ? '−' : '') + '$ ' + entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (decimales !== '00' ? ',' + decimales : '');
};
const campo = (fila, nombre) => fila.querySelector(`[name="${nombre}[]"]`);

function mostrarSaldo(fila) {
    const cuenta = campo(fila, 'cuenta').value;
    fila.querySelector('.saldo-cuenta').textContent = cuenta ? `Saldo actual: ${pesos(SALDOS[cuenta] || 0)}` : '';
}

function agregarLinea([cuenta = '', detalle = '', debito = '', credito = ''] = []) {
    const fila = $('plantillaLinea').content.firstElementChild.cloneNode(true);
    campo(fila, 'cuenta').value = CUENTAS[cuenta] ? cuenta : '';
    campo(fila, 'detalle').value = detalle;
    campo(fila, 'debito').value = debito;
    campo(fila, 'credito').value = credito;
    cuerpo.appendChild(fila);
    mostrarSaldo(fila);
}

function recalcular() {
    let debitos = 0, creditos = 0;
    cuerpo.querySelectorAll('tr.linea-nota').forEach(fila => {
        debitos += parseFloat(campo(fila, 'debito').value) || 0;
        creditos += parseFloat(campo(fila, 'credito').value) || 0;
    });
    debitos = Math.round(debitos * 100) / 100;
    creditos = Math.round(creditos * 100) / 100;
    const diferencia = Math.round((debitos - creditos) * 100) / 100;
    $('totalDebitos').textContent = pesos(debitos);
    $('totalCreditos').textContent = pesos(creditos);
    const cuadrada = debitos > 0 && diferencia === 0;
    $('estadoCuadre').closest('.cuadre-nota').classList.toggle('descuadrada', !cuadrada);
    $('estadoCuadre').textContent = debitos === 0 && creditos === 0 ? 'Escribe los valores'
        : cuadrada ? 'Sumas iguales: la nota cuadra'
        : `Diferencia de ${pesos(Math.abs(diferencia))} ${diferencia > 0 ? 'a débito' : 'a crédito'}`;
}

cuerpo.addEventListener('input', evento => {
    const fila = evento.target.closest('tr.linea-nota');
    if (!fila) return;
    // Cada línea lleva un solo valor: al escribir el débito se borra el crédito y viceversa
    if (evento.target.name === 'debito[]' && evento.target.value !== '') campo(fila, 'credito').value = '';
    if (evento.target.name === 'credito[]' && evento.target.value !== '') campo(fila, 'debito').value = '';
    recalcular();
});
cuerpo.addEventListener('change', evento => {
    if (evento.target.name === 'cuenta[]') mostrarSaldo(evento.target.closest('tr.linea-nota'));
});
cuerpo.addEventListener('click', evento => {
    const boton = evento.target.closest('.quitar-linea');
    if (!boton) return;
    const fila = boton.closest('tr.linea-nota');
    if (cuerpo.querySelectorAll('tr.linea-nota').length > 2) {
        fila.remove();
    } else {
        fila.querySelectorAll('input').forEach(i => { i.value = ''; });
        campo(fila, 'cuenta').value = '';
        mostrarSaldo(fila);
    }
    recalcular();
});
$('agregarLinea').addEventListener('click', () => {
    if (cuerpo.querySelectorAll('tr.linea-nota').length < MAX_LINEAS) agregarLinea();
});
document.querySelectorAll('.plantilla-nota').forEach(boton => boton.addEventListener('click', () => {
    const [, , clase, concepto, lineas] = PLANTILLAS[boton.dataset.plantilla];
    $('claseNota').value = clase;
    if (!$('concepto').value.trim()) $('concepto').value = concepto;
    cuerpo.textContent = '';
    lineas.forEach(([cuenta, detalle]) => agregarLinea([cuenta, detalle]));
    recalcular();
}));

// Si el NIT ya existe se usa el nombre registrado (el servidor hace lo mismo)
$('documento').addEventListener('input', () => {
    const doc = $('documento').value.toUpperCase().replace(/[^0-9A-Z-]/g, '');
    const tercero = TERCEROS.find(t => t.documento === doc);
    if (tercero) $('tercero').value = tercero.nombre;
});

LINEAS_INICIALES.forEach(linea => agregarLinea(linea));
recalcular();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
