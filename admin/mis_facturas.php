<?php
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';

// Validar que el usuario tenga acceso a este módulo
requiereAcceso($db, 'mis_facturas');
actualizarVencimientos($db);

// 1. Ahora usamos el ID del usuario oficial del sistema
$id_usuario = $_SESSION['admin_id'];

// 2. OBTENER PERFIL (Para el Header Modular que sale arriba a la derecha)
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$id_usuario]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = "Mis Facturas";
$subtitulo = "Historial de facturación y pagos en línea";

// 3. KPIs: lo que falta por pagar (saldo) y lo pagado con recaudos confirmados
$aplicado_sql = sqlAplicadoFacturas();
$stmt_kpi = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN f.estado NOT IN ('Pagado', 'Anulada') THEN GREATEST(f.monto_total - COALESCE(a.aprobado, 0), 0) ELSE 0 END), 0) AS total_deuda,
        COALESCE(SUM(CASE WHEN f.estado <> 'Anulada' THEN COALESCE(a.recaudado, 0) ELSE 0 END), 0) AS total_pagado
    FROM facturas f
    LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
    WHERE f.id_usuario = ?");
$stmt_kpi->execute([$id_usuario]);
$kpis = $stmt_kpi->fetch(PDO::FETCH_ASSOC);

// 4. Obtener las facturas del cliente (consulta anti-duplicados, con lo abonado y lo que está en aprobación)
$query_facturas = "
    SELECT f.*,
           (SELECT estado_transaccion FROM pagos_registro WHERE factura_id = f.id ORDER BY id DESC LIMIT 1) AS estado_transaccion,
           (SELECT observaciones FROM pagos_registro WHERE factura_id = f.id ORDER BY id DESC LIMIT 1) AS ultima_observacion,
           sc.nombre AS nombre_servicio_catalogo,
           COALESCE(a.aprobado, 0) AS aprobado,
           COALESCE(a.en_tramite, 0) AS en_tramite,
           COALESCE(a.recaudado, 0) AS recaudado,
           COALESCE(a.notas_credito, 0) AS notas_credito,
           COALESCE(a.notas_debito, 0) AS notas_debito
    FROM facturas f
    LEFT JOIN servicios_catalogo sc ON f.id_servicio = sc.id
    LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
    WHERE f.id_usuario = ?
    ORDER BY f.fecha_emision DESC, f.id DESC
";
$stmt_facturas = $db->prepare($query_facturas);
$stmt_facturas->execute([$id_usuario]);
$facturas = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo ?> | DataSense Enterprise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php
        // Respuestas de la pasarela (back_urls de MercadoPago) y del simulador local
        $avisos_pago = [
            'pago_exitoso'   => ['success', 'fa-check-circle', '<strong>¡Pago confirmado!</strong> La factura quedó registrada como pagada.'],
            'pago_pendiente' => ['warning', 'fa-clock', '<strong>Pago en proceso.</strong> Te avisaremos cuando la entidad lo confirme.'],
            'pago_fallido'   => ['danger', 'fa-times-circle', '<strong>El pago no se completó.</strong> Puedes intentarlo de nuevo.'],
            'pago_reportado' => ['info', 'fa-hourglass-half', '<strong>Pago reportado.</strong> Finanzas validará tu comprobante y la factura quedará pagada cuando se confirme.'],
            'pago_no_disponible' => ['warning', 'fa-info-circle', 'Esa factura ya está pagada, anulada o tiene un pago en proceso.'],
        ];
        $aviso = $avisos_pago[$_GET['msg'] ?? ''] ?? null;
        ?>
        <?php if($aviso): ?>
            <div class="alert alert-<?= $aviso[0] ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                <i class="fas <?= $aviso[1] ?> me-2"></i> <?= $aviso[2] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm" style="border-left: 5px solid #dc3545 !important;">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase fw-bold mb-1">Total Pendiente de Pago</h6>
                        <h3 class="mb-0 text-danger" data-kpi="deuda"><?= dinero($kpis['total_deuda']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm" style="border-left: 5px solid #198754 !important;">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase fw-bold mb-1">Total Pagado Histórico</h6>
                        <h3 class="mb-0 text-success" data-kpi="pagado"><?= dinero($kpis['total_pagado']) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 px-4 border-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-file-invoice me-2 text-primary"></i> Historial de Facturas</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">No. Factura</th>
                            <th>Servicio Contratado</th>
                            <th>Fecha Emisión</th>
                            <th>Vencimiento</th>
                            <th>Monto Total</th>
                            <th>Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($facturas) > 0): ?>
                            <?php foreach($facturas as $f):
                                // Lo que el cliente todavía puede pagar: total menos lo abonado y lo que está en aprobación
                                $por_pagar = round((float) $f['monto_total'] - (float) $f['aprobado'] - (float) $f['en_tramite'], 2);
                                $saldo = max(0, (float) $f['monto_total'] - (float) $f['aprobado']);
                                $intento_pendiente = ($f['estado_transaccion'] ?? '') === 'Pendiente_Validacion';
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?= esc($f['numero_factura']) ?></td>
                                <td>
                                    <?= esc($f['nombre_servicio_catalogo'] ?? $f['servicio_contratado']) ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
                                <td><?= date('d/m/Y', strtotime($f['fecha_vencimiento'])) ?></td>
                                <td class="fw-bold">
                                    <?= dinero($f['monto_total']) ?>
                                    <?php if ((float) $f['notas_credito'] > 0 && $f['estado'] !== 'Anulada'): ?>
                                        <div class="small text-warning fw-bold">Nota crédito: −<?= dinero($f['notas_credito']) ?></div>
                                    <?php endif; ?>
                                    <?php if ((float) $f['notas_debito'] > 0 && $f['estado'] !== 'Anulada'): ?>
                                        <div class="small text-secondary fw-bold">Nota débito: +<?= dinero($f['notas_debito']) ?></div>
                                    <?php endif; ?>
                                    <?php if (abs((float) $f['aprobado']) > 0.5 && !in_array($f['estado'], ['Pagado', 'Anulada'], true)): ?>
                                        <?php if ((float) $f['recaudado'] > 0): ?><div class="small text-success">Abonado: <?= dinero($f['recaudado']) ?></div><?php endif; ?>
                                        <div class="small text-danger">Saldo: <?= dinero($saldo) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if ($f['estado'] == 'Pagado') {
                                        echo '<span class="badge bg-success">Pagado</span>';
                                    } elseif ($f['estado'] == 'Anulada') {
                                        echo '<span class="badge bg-dark">Anulada</span>';
                                    } elseif ($f['estado'] == 'En Proceso' || $intento_pendiente) {
                                        echo '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> En Proceso</span>';
                                    } elseif ($f['estado'] == 'Vencido') {
                                        echo '<span class="badge bg-danger">Vencido</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">Pendiente</span>';
                                    }
                                    ?>
                                    <?php if (($f['estado_transaccion'] ?? '') === 'Rechazado' && !in_array($f['estado'], ['Pagado', 'Anulada'], true) && preg_match('/Rechazado: (.+)$/u', (string) $f['ultima_observacion'], $rechazo)): ?>
                                        <div class="small text-danger mt-1" data-rechazo="<?= (int) $f['id'] ?>"><i class="fas fa-times-circle"></i> Tu pago reportado fue rechazado: <?= esc($rechazo[1]) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="generar_pdf.php?id=<?= (int) $f['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Descargar Factura PDF">
                                        <i class="fas fa-download"></i>
                                    </a>

                                    <?php // Siempre se puede reintentar: un intento abandonado en la pasarela ya no deja la factura bloqueada ?>
                                    <?php if (!in_array($f['estado'], ['Pagado', 'Anulada'], true) && $por_pagar > 0.5): ?>
                                        <button class="btn btn-sm btn-success ms-1" title="Pagar Factura" data-pagar="<?= (int) $f['id'] ?>"
                                                data-bs-toggle="modal" data-bs-target="#modalPagoGateway"
                                                onclick="prepararModalPago(<?= (int) $f['id'] ?>, <?= jsAttr($f['numero_factura']) ?>, <?= jsAttr($por_pagar) ?>, <?= $intento_pendiente ? 'true' : 'false' ?>)">
                                            <i class="fas <?= $intento_pendiente ? 'fa-redo' : 'fa-credit-card' ?>"></i> <?= $intento_pendiente ? 'Reintentar' : 'Pagar' ?>
                                        </button>
                                    <?php elseif ($f['estado'] === 'En Proceso'): ?>
                                        <span class="small text-muted ms-1" title="Tu pago está en revisión"><i class="fas fa-hourglass-half"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No tienes facturas registradas en este momento.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalPagoGateway" tabindex="-1" aria-labelledby="modalPagoLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold text-dark" id="modalPagoLabel"><i class="fas fa-shield-alt text-success me-2"></i> Pago Seguro Centralizado</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="procesar_pago_online.php" method="POST">
          <div class="modal-body p-4">
              <input type="hidden" id="modal_factura_id" name="factura_id">

              <div class="text-center mb-4">
                  <p class="text-muted mb-1">Estás a punto de pagar la factura</p>
                  <h4 class="fw-bold text-dark mb-0">#<span id="modal_num_factura"></span></h4>
                  <h2 class="text-primary fw-bold mt-2" id="modal_monto_texto"></h2>
              </div>

              <div id="aviso_pago_en_proceso" class="alert alert-warning d-none small" role="alert">
                  <i class="fas fa-exclamation-triangle me-1"></i> Esta factura tiene un pago <strong>en proceso</strong>. Si ya pagaste (por ejemplo, en efectivo), espera la confirmación antes de intentarlo otra vez.
              </div>

              <label class="form-label fw-bold text-muted small text-uppercase">Selecciona tu método de pago</label>

              <div class="row g-3">
                  <div class="col-6">
                      <input type="radio" name="tipo_pago" value="online" id="pay_online" class="d-none" checked>
                      <div id="card_online" class="card h-100 p-3 text-center cursor-pointer border-2 border-primary bg-primary bg-opacity-10 shadow-sm" onclick="seleccionarMetodo('online')" style="cursor: pointer; transition: 0.2s;">
                          <i class="fas fa-mobile-alt fa-2x text-primary mb-2"></i>
                          <h6 class="fw-bold mb-1 text-dark">Pago en Línea</h6>
                          <small class="text-muted" style="font-size: 0.70rem;">PSE, Tarjeta, Nequi</small>
                      </div>
                  </div>

                  <div class="col-6">
                      <input type="radio" name="tipo_pago" value="efectivo" id="pay_efectivo" class="d-none">
                      <div id="card_efectivo" class="card h-100 p-3 text-center cursor-pointer border-2" onclick="seleccionarMetodo('efectivo')" style="cursor: pointer; transition: 0.2s;">
                          <i class="fas fa-university fa-2x text-success mb-2"></i>
                          <h6 class="fw-bold mb-1 text-dark">Efectivo / Banco</h6>
                          <small class="text-muted" style="font-size: 0.70rem;">Recibo (Bancolombia, Efecty)</small>
                      </div>
                  </div>
              </div>

              <div class="alert alert-info mt-4 mb-0" style="font-size: 0.8rem; border-left: 4px solid #0dcaf0;">
                  <i class="fas fa-lock me-1"></i> Tu pago será procesado a través de nuestra pasarela cifrada. La factura se marcará como pagada automáticamente al confirmarse la transacción.
              </div>

              <a id="enlaceInternacional" href="#" class="btn btn-outline-info w-100 rounded-pill fw-bold mt-3">
                  <i class="fas fa-globe-americas me-2"></i> ¿Pagas desde el exterior? PayPal, Zelle, Western Union o USDT
              </a>
          </div>
          <div class="modal-footer bg-light border-0 p-3">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Continuar al Pago <i class="fas fa-arrow-right ms-2"></i></button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Prepara los datos del modal al abrirlo (el monto es el saldo por pagar)
function prepararModalPago(id, numero, monto, enProceso = false) {
    // Si ya hay un intento pendiente se avisa antes de iniciar otro (evita pagar dos veces)
    document.getElementById('aviso_pago_en_proceso').classList.toggle('d-none', !enProceso);
    document.getElementById('modal_factura_id').value = id;
    document.getElementById('enlaceInternacional').href = 'pagos_internacionales.php?id=' + id;
    document.getElementById('modal_num_factura').innerText = numero;
    document.getElementById('modal_monto_texto').innerText = '$ ' + parseFloat(monto).toLocaleString('es-CO', {maximumFractionDigits: 0});

    // Por defecto, selecciona el pago en línea cada vez que se abre
    seleccionarMetodo('online');
}

// Enlace directo al pago de una factura (?pagar=ID), por ejemplo desde Mis Pedidos
document.addEventListener('DOMContentLoaded', function () {
    const id = new URLSearchParams(window.location.search).get('pagar');
    const boton = id && /^\d+$/.test(id) ? document.querySelector('[data-pagar="' + id + '"]') : null;
    if (boton) boton.click();
});

// Función JS para hacer el cambio de color al seleccionar
function seleccionarMetodo(metodo) {
    let cardOnline = document.getElementById('card_online');
    let cardEfectivo = document.getElementById('card_efectivo');

    // 1. Limpiamos ambos cuadros (los dejamos en gris)
    cardOnline.className = "card h-100 p-3 text-center border-2";
    cardEfectivo.className = "card h-100 p-3 text-center border-2";

    // 2. Aplicamos el color al seleccionado y marcamos el input oculto
    if(metodo === 'online') {
        cardOnline.className = "card h-100 p-3 text-center border-2 border-primary bg-primary bg-opacity-10 shadow-sm";
        document.getElementById('pay_online').checked = true;
    } else {
        cardEfectivo.className = "card h-100 p-3 text-center border-2 border-success bg-success bg-opacity-10 shadow-sm";
        document.getElementById('pay_efectivo').checked = true;
    }
}
</script>
</body>
</html>
