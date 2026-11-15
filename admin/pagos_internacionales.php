<?php
// Pagos desde el exterior para el cliente: PayPal (se confirma solo) y Zelle, Western Union o USDT (se reportan y Finanzas los valida)
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pagos_internacionales.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'mis_facturas');

$factura_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT f.*, COALESCE(a.aprobado, 0) AS aprobado, COALESCE(a.en_tramite, 0) AS en_tramite
                      FROM facturas f
                      LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
                      WHERE f.id = ? AND f.id_usuario = ?");
$stmt->execute([$factura_id, $usuario['id']]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);
$por_pagar = $factura ? round((float) $factura['monto_total'] - (float) $factura['aprobado'] - (float) $factura['en_tramite'], 2) : 0;
if (!$factura || in_array($factura['estado'], ['Pagado', 'Anulada'], true) || $por_pagar <= 0.5) {
    header('Location: mis_facturas.php?msg=pago_no_disponible');
    exit;
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Pagos Internacionales";
$subtitulo = "Paga tu factura desde el exterior en dólares";
$aviso = tomarAviso();

$trm = obtenerTRM($db);
$usd = $trm ? copAUsd($por_pagar, $trm['valor']) : null;
$disponibles = metodosInternacionalesDisponibles();
$datos = datosCobroInternacional();
$modo_local = pagosEnModoLocal();
$reportado = pagoInternacionalPendiente($db, $factura_id);
$pestanas = ['paypal' => ['PayPal', 'fab fa-paypal'], 'zelle' => ['Zelle', 'fas fa-bolt'], 'wu' => ['Western Union', 'fas fa-money-bill-transfer'], 'usdt' => ['USDT (TRC20)', 'fas fa-coins']];
$activa = isset($pestanas[$_GET['metodo'] ?? '']) ? $_GET['metodo'] : 'paypal';

// Formulario para reportar un pago manual (Zelle, Western Union o USDT)
$formulario = function ($metodo, $pestana, $etiqueta_referencia, $ayuda_referencia, $soporte_obligatorio, $unidad) use ($factura_id, $usd, $reportado) {
    if ($reportado) {
        echo '<div class="alert alert-info small mb-0"><i class="fas fa-hourglass-half me-1"></i> Ya reportaste un pago para esta factura. Finanzas lo está validando.</div>';
        return;
    }
    ?>
    <form method="POST" action="procesar_pago_internacional.php" enctype="multipart/form-data" class="form-reporte" data-metodo="<?= esc($metodo) ?>">
        <input type="hidden" name="factura_id" value="<?= (int) $factura_id ?>">
        <input type="hidden" name="metodo" value="<?= esc($metodo) ?>">
        <input type="hidden" name="pestana" value="<?= esc($pestana) ?>">
        <div class="mb-3">
            <label class="small fw-bold text-muted mb-1"><?= esc($etiqueta_referencia) ?> *</label>
            <input type="text" name="referencia" class="form-control font-monospace" maxlength="100" required>
            <div class="form-text"><?= esc($ayuda_referencia) ?></div>
        </div>
        <div class="mb-3">
            <label class="small fw-bold text-muted mb-1">Valor enviado (<?= esc($unidad) ?>) *</label>
            <input type="number" name="monto_usd" class="form-control" min="0.01" step="0.01" value="<?= number_format((float) $usd, 2, '.', '') ?>" required>
        </div>
        <div class="mb-3">
            <label class="small fw-bold text-muted mb-1">Comprobante del pago <?= $soporte_obligatorio ? '*' : '(opcional)' ?></label>
            <input type="file" name="comprobante" class="form-control" accept="image/jpeg,image/png,application/pdf" <?= $soporte_obligatorio ? 'required' : '' ?>>
            <div class="form-text">Imagen JPG o PNG, o PDF de máximo 5 MB.</div>
        </div>
        <button type="submit" class="btn btn-dark rounded-pill fw-bold px-4" style="background: var(--azul-sonda);"><i class="fas fa-paper-plane me-2"></i> Reportar pago</button>
    </form>
    <?php
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagos Internacionales | DataSense</title>
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
            <a href="mis_facturas.php" class="btn btn-outline-secondary rounded-pill px-4 bg-white mb-3"><i class="fas fa-arrow-left me-2"></i> Volver a Mis Facturas</a>

            <?php if ($aviso): ?>
                <div class="alert alert-<?= esc($aviso['tipo']) ?> rounded-4 border-0 shadow-sm" id="avisoPago"><i class="fas fa-exclamation-triangle me-2"></i> <?= esc($aviso['texto']) ?></div>
            <?php endif; ?>

            <div class="panel-conta p-4 mb-4">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-5">
                        <div class="small text-muted text-uppercase fw-bold">Factura</div>
                        <div class="fs-4 fw-bold"><?= esc($factura['numero_factura']) ?></div>
                        <div class="small text-muted"><?= esc($factura['servicio_contratado']) ?> · vence <?= date('d/m/Y', strtotime($factura['fecha_vencimiento'])) ?></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="small text-muted text-uppercase fw-bold">Saldo a pagar</div>
                        <div class="fs-5 fw-bold" id="saldoCop"><?= dinero($por_pagar) ?> COP</div>
                    </div>
                    <div class="col-6 col-lg-4 text-lg-end">
                        <div class="small text-muted text-uppercase fw-bold">Equivalente en dólares</div>
                        <?php if ($trm): ?>
                            <div class="monto-usd" id="montoUsd" data-usd="<?= number_format($usd, 2, '.', '') ?>"><?= formatoUsd($usd) ?></div>
                            <div class="small text-muted" id="trmInfo">TRM <?= dinero($trm['valor'], 2) ?> del <?= date('d/m/Y', strtotime($trm['fecha'])) ?> (<?= esc($trm['fuente']) ?>)</div>
                        <?php else: ?>
                            <div class="text-danger fw-bold">No disponible</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$trm): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm">
                    <i class="fas fa-exclamation-triangle me-2"></i> Hoy no hay una tasa de cambio disponible para calcular el valor en dólares. Intenta más tarde o paga desde Colombia en Mis Facturas.
                </div>
            <?php else: ?>
                <?php if ($reportado): ?>
                    <div class="alert alert-info rounded-4 border-0 shadow-sm" id="pagoEnValidacion">
                        <i class="fas fa-hourglass-half me-2"></i> Reportaste un pago por <b><?= esc($reportado['metodo_pago']) ?></b> de <b><?= formatoUsd($reportado['monto_moneda']) ?></b>
                        el <?= date('d/m/Y', strtotime($reportado['fecha_pago'])) ?> (ref. <?= esc($reportado['referencia_transaccion']) ?>). Finanzas lo está validando; te confirmaremos cuando quede aplicado.
                    </div>
                <?php endif; ?>

                <ul class="nav nav-pills mb-3 gap-2" role="tablist">
                    <?php foreach ($pestanas as $clave => [$etiqueta, $icono]): ?>
                        <li class="nav-item">
                            <button class="nav-link rounded-pill fw-bold <?= $activa === $clave ? 'active' : 'border bg-white' ?>" id="tab-<?= $clave ?>" data-bs-toggle="pill" data-bs-target="#panel-<?= $clave ?>" type="button" role="tab">
                                <i class="<?= $icono ?> me-1"></i> <?= $etiqueta ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade <?= $activa === 'paypal' ? 'show active' : '' ?>" id="panel-paypal" role="tabpanel">
                        <div class="panel-conta p-4">
                            <h5 class="fw-bold"><i class="fab fa-paypal me-2" style="color: #003087;"></i> PayPal</h5>
                            <p class="text-muted">Paga con tu saldo PayPal o con tarjeta de crédito o débito internacional. El pago se confirma de inmediato y la factura queda pagada.</p>
                            <?php if (!$disponibles['PayPal']): ?>
                                <div class="alert alert-secondary mb-0">PayPal todavía no está habilitado. Usa otro método o contacta a DataSense.</div>
                            <?php else: ?>
                                <?php if ($modo_local): ?>
                                    <div class="alert alert-warning small"><i class="fas fa-vial me-1"></i> Modo de pruebas: se simula la aprobación de PayPal, sin cobro real.</div>
                                    <button type="button" id="btnPaypalSimulado" class="btn btn-lg rounded-pill fw-bold px-4 text-white" style="background: #003087;">
                                        <i class="fab fa-paypal me-2"></i> Pagar <?= formatoUsd($usd) ?> con PayPal
                                    </button>
                                <?php else: ?>
                                    <div id="paypal-botones" style="max-width: 420px;"></div>
                                <?php endif; ?>
                                <div id="paypalEstado" class="small mt-3"></div>
                                <p class="small text-muted mt-3 mb-0">Se te cobran exactamente <?= formatoUsd($usd) ?>. La comisión de PayPal la asume DataSense.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tab-pane fade <?= $activa === 'zelle' ? 'show active' : '' ?>" id="panel-zelle" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-lg-5"><div class="panel-conta p-4 h-100">
                                <h6 class="fw-bold mb-3">1. Envía el pago por Zelle</h6>
                                <?php if (!$disponibles['Zelle']): ?>
                                    <div class="alert alert-secondary mb-0">Zelle no está habilitado.</div>
                                <?php else: ?>
                                    <div class="dato-cobro"><small>Correo Zelle</small><span class="valor-copiable" id="zelleCorreo"><?= esc($datos['zelle']['correo']) ?></span></div>
                                    <div class="dato-cobro"><small>Titular</small><?= esc($datos['zelle']['titular']) ?></div>
                                    <div class="dato-cobro"><small>Valor a enviar</small><b><?= formatoUsd($usd) ?></b></div>
                                    <div class="dato-cobro"><small>Concepto / memo</small>Factura <?= esc($factura['numero_factura']) ?></div>
                                <?php endif; ?>
                            </div></div>
                            <div class="col-lg-7"><div class="panel-conta p-4 h-100">
                                <h6 class="fw-bold mb-3">2. Reporta tu pago</h6>
                                <?php if ($disponibles['Zelle']) $formulario('Zelle', 'zelle', 'Código de confirmación de Zelle', 'Lo muestra tu banco al terminar la transferencia.', true, 'USD'); ?>
                            </div></div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?= $activa === 'wu' ? 'show active' : '' ?>" id="panel-wu" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-lg-5"><div class="panel-conta p-4 h-100">
                                <h6 class="fw-bold mb-3">1. Envía el giro por Western Union</h6>
                                <?php if (!$disponibles['Western Union']): ?>
                                    <div class="alert alert-secondary mb-0">Western Union no está habilitado.</div>
                                <?php else: ?>
                                    <div class="dato-cobro"><small>Beneficiario</small><?= esc($datos['western_union']['beneficiario']) ?></div>
                                    <div class="dato-cobro"><small>Documento</small><?= esc($datos['western_union']['documento']) ?></div>
                                    <div class="dato-cobro"><small>Ciudad de pago</small><?= esc($datos['western_union']['ciudad']) ?></div>
                                    <div class="dato-cobro"><small>Valor a enviar</small><b><?= formatoUsd($usd) ?></b></div>
                                <?php endif; ?>
                            </div></div>
                            <div class="col-lg-7"><div class="panel-conta p-4 h-100">
                                <h6 class="fw-bold mb-3">2. Reporta tu giro</h6>
                                <?php if ($disponibles['Western Union']) $formulario('Western Union', 'wu', 'Número de control del giro (MTCN)', 'Son 10 dígitos que aparecen en el recibo de Western Union.', true, 'USD'); ?>
                            </div></div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?= $activa === 'usdt' ? 'show active' : '' ?>" id="panel-usdt" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-lg-5"><div class="panel-conta p-4 h-100">
                                <h6 class="fw-bold mb-3">1. Envía USDT por la red TRC20</h6>
                                <?php if (!$disponibles['Criptomonedas (USDT)']): ?>
                                    <div class="alert alert-secondary mb-0">El pago con USDT no está habilitado.</div>
                                <?php else: ?>
                                    <div class="text-center mb-3"><div id="qrUsdt" class="d-inline-block p-2 bg-white border rounded-3"></div></div>
                                    <div class="dato-cobro">
                                        <small>Billetera USDT · <?= esc($datos['usdt']['red']) ?></small>
                                        <span class="valor-copiable" id="billeteraUsdt"><?= esc($datos['usdt']['billetera']) ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill mt-2" onclick="copiar('billeteraUsdt', this)"><i class="fas fa-copy me-1"></i> Copiar</button>
                                    </div>
                                    <div class="dato-cobro"><small>Valor exacto a enviar</small><b><?= number_format((float) $usd, 2, ',', '.') ?> USDT</b></div>
                                    <div class="alert alert-warning small mb-0"><i class="fas fa-exclamation-triangle me-1"></i> Usa solo la red <b>TRC20 (Tron)</b>. Un envío por otra red se pierde.</div>
                                <?php endif; ?>
                            </div></div>
                            <div class="col-lg-7"><div class="panel-conta p-4 h-100">
                                <h6 class="fw-bold mb-3">2. Reporta la transacción</h6>
                                <?php if ($disponibles['Criptomonedas (USDT)']) $formulario('Criptomonedas (USDT)', 'usdt', 'Hash de la transacción (TxID)', 'Código de 64 caracteres que te da tu billetera o exchange (Binance, Trust Wallet...).', false, 'USDT'); ?>
                            </div></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const FACTURA_ID = <?= (int) $factura_id ?>;

function copiar(idElemento, boton) {
    const texto = document.getElementById(idElemento).textContent.trim();
    if (navigator.clipboard) navigator.clipboard.writeText(texto).then(() => { boton.innerHTML = '<i class="fas fa-check me-1"></i> Copiado'; });
}

const qr = document.getElementById('qrUsdt');
if (qr && window.QRCode) new QRCode(qr, { text: document.getElementById('billeteraUsdt').textContent.trim(), width: 150, height: 150 });

// La orden y la captura se hacen en el servidor: el navegador nunca decide el valor ni confirma el pago
async function llamarPaypal(accion, extra = {}) {
    const respuesta = await fetch('paypal_orden.php', { method: 'POST', body: new URLSearchParams({ accion, factura_id: FACTURA_ID, ...extra }) });
    const datos = await respuesta.json().catch(() => ({ error: 'Respuesta inválida del servidor.' }));
    if (!respuesta.ok || datos.error) throw new Error(datos.error || 'No se pudo procesar el pago.');
    return datos;
}

function mostrarEstado(texto, tipo) {
    const estado = document.getElementById('paypalEstado');
    if (!estado) return;
    estado.className = 'small mt-3 fw-bold text-' + tipo;
    estado.textContent = texto;
}

const botonSimulado = document.getElementById('btnPaypalSimulado');
if (botonSimulado) {
    botonSimulado.addEventListener('click', async () => {
        botonSimulado.disabled = true;
        mostrarEstado('Procesando el pago con PayPal…', 'muted');
        try {
            const orden = await llamarPaypal('crear');
            const resultado = await llamarPaypal('capturar', { order_id: orden.id });
            location.href = resultado.redirigir;
        } catch (e) {
            mostrarEstado(e.message, 'danger');
            botonSimulado.disabled = false;
        }
    });
}
</script>
<?php if ($trm && !$modo_local && $disponibles['PayPal']): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= esc(urlencode($datos['paypal']['client_id'])) ?>&currency=USD&intent=capture"></script>
<script>
if (window.paypal) {
    paypal.Buttons({
        createOrder: async () => (await llamarPaypal('crear')).id,
        onApprove: async (data) => {
            mostrarEstado('Confirmando el pago…', 'muted');
            try { location.href = (await llamarPaypal('capturar', { order_id: data.orderID })).redirigir; }
            catch (e) { mostrarEstado(e.message, 'danger'); }
        },
        onError: () => mostrarEstado('PayPal no pudo completar el pago. Intenta de nuevo.', 'danger'),
    }).render('#paypal-botones');
} else {
    mostrarEstado('No se pudo cargar PayPal. Revisa tu conexión.', 'danger');
}
</script>
<?php endif; ?>
</body>
</html>
