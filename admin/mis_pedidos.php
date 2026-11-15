<?php
/**
 * MIS PEDIDOS (cliente)
 * Pedidos de la tienda con su seguimiento (recibido → pagado → en preparación → enviado → entregado), productos, entrega,
 * historial, factura en PDF, pago directo en Mis Facturas y cancelación mientras no haya pagos.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/tienda.php';
$db = require __DIR__ . '/../config/database.php';

$usuario = requiereAcceso($db, 'mis_pedidos');
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario['id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = 'Mis Pedidos';
$subtitulo = 'Compras en la tienda, pagos y seguimiento de entrega';

sincronizarPedidos($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar') {
    $propio = pedidosTienda($db, ['id' => (int) ($_POST['pedido_id'] ?? 0), 'usuario_id' => $usuario['id']]);
    if (!$propio) {
        avisoSiguiente('danger', 'El pedido no existe.');
    } else {
        $error = cambiarEstadoPedido($db, $propio[0]['id'], 'Cancelado', $usuario, 'Cancelado por el cliente');
        avisoSiguiente($error ? 'danger' : 'success', $error ?: "Cancelaste el pedido {$propio[0]['numero']}.");
    }
    header('Location: mis_pedidos.php?pedido=' . (int) ($_POST['pedido_id'] ?? 0));
    exit;
}

$pedidos = pedidosTienda($db, ['usuario_id' => $usuario['id']]);
$seleccionado = (int) ($_GET['pedido'] ?? 0);
$nuevo = null;
foreach ($pedidos as $p) if ((int) $p['id'] === $seleccionado && ($_GET['nuevo'] ?? '') === '1') $nuevo = $p;
$aviso = tomarAviso();

$conteo = ['por_pagar' => 0, 'valor_por_pagar' => 0, 'en_curso' => 0, 'entregados' => 0];
foreach ($pedidos as $p) {
    if ($p['estado'] === 'Pendiente de pago') { $conteo['por_pagar']++; $conteo['valor_por_pagar'] += (float) $p['total']; }
    if (in_array($p['estado'], ['Pagado', 'En preparación', 'Enviado'], true)) $conteo['en_curso']++;
    if ($p['estado'] === 'Entregado') $conteo['entregados']++;
}

// Pasos del seguimiento según el pedido (el envío solo aplica con productos físicos)
$pasosPedido = function (array $p) {
    $pasos = ['Pendiente de pago' => ['Recibido', 'fa-receipt'], 'Pagado' => ['Pagado', 'fa-credit-card'], 'En preparación' => ['En preparación', 'fa-box-open']];
    if ((int) $p['fisicos'] > 0) $pasos['Enviado'] = ['Enviado', 'fa-truck-fast'];
    $pasos['Entregado'] = ['Entregado', 'fa-circle-check'];
    return $pasos;
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis Pedidos | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/tienda.css?v=20260915">
    <style>
        .pedido-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; margin-bottom: 14px; overflow: hidden; }
        .pedido-card.seleccionado { border-color: #3FAE49; box-shadow: 0 0 0 3px rgba(63,174,73,.15); }
        .pedido-cabecera { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; padding: 14px 18px; background: #f8fafc; border-bottom: 1px solid #eef2f6; }
        .pedido-cabecera .dato small { display: block; font-size: .68rem; text-transform: uppercase; color: #64748b; font-weight: 700; letter-spacing: .04em; }
        .seguimiento { display: flex; justify-content: space-between; position: relative; margin: 8px 0 4px; }
        .seguimiento::before { content: ''; position: absolute; top: 17px; left: 8%; right: 8%; height: 3px; background: #e2e8f0; }
        .seguimiento .paso { position: relative; text-align: center; flex: 1; font-size: .72rem; color: #94a3b8; font-weight: 600; }
        .seguimiento .paso i { width: 36px; height: 36px; border-radius: 50%; background: #e2e8f0; color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; position: relative; }
        .seguimiento .paso.hecho { color: #0f172a; }
        .seguimiento .paso.hecho i { background: #3FAE49; }
        .seguimiento .paso.actual i { background: #0A2E4F; box-shadow: 0 0 0 4px rgba(10,46,79,.15); }
        .mini-linea .producto-media { width: 60px; }
        .historial-pedido { list-style: none; padding: 0; margin: 0; border-left: 2px solid #e2e8f0; }
        .historial-pedido li { position: relative; padding: 0 0 12px 16px; font-size: .84rem; }
        .historial-pedido li::before { content: ''; position: absolute; left: -7px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #0A2E4F; border: 2px solid #fff; }
        .confirmacion-pedido { background: linear-gradient(120deg, #0A2E4F, #134574); color: #fff; border-radius: 18px; padding: 28px; }
        .confirmacion-pedido h3 { color: #fff; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show shadow-sm border-0" role="alert"><?= esc($aviso['texto']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>
        <?php endif; ?>

        <?php if ($nuevo): ?>
        <div class="confirmacion-pedido mb-4 d-flex flex-wrap gap-3 align-items-center justify-content-between" id="confirmacionPedido">
            <div>
                <div class="small text-uppercase opacity-75 fw-bold"><i class="fas fa-circle-check text-success me-1"></i>Pedido recibido</div>
                <h3 class="fw-bold mb-1">¡Gracias por tu compra! Tu pedido es el <?= esc($nuevo['numero']) ?></h3>
                <div class="opacity-75">Generamos la factura <b><?= esc($nuevo['numero_factura']) ?></b> por <b><?= dinero($nuevo['total']) ?></b> y te la enviamos por correo. Págala en los próximos <?= TIENDA_DIAS_PAGO ?> días.</div>
            </div>
            <a href="mis_facturas.php?pagar=<?= (int) $nuevo['factura_id'] ?>" class="btn btn-success btn-lg fw-bold rounded-pill px-4" id="btnPagarNuevo"><i class="fas fa-credit-card me-2"></i>Pagar ahora</a>
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm p-3 h-100"><small class="text-muted fw-bold text-uppercase">Pedidos</small><div class="fs-3 fw-bold"><?= count($pedidos) ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm p-3 h-100"><small class="text-muted fw-bold text-uppercase">Por pagar</small><div class="fs-3 fw-bold text-warning"><?= $conteo['por_pagar'] ?></div><small class="text-muted"><?= dinero($conteo['valor_por_pagar']) ?></small></div></div>
            <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm p-3 h-100"><small class="text-muted fw-bold text-uppercase">En curso</small><div class="fs-3 fw-bold text-primary"><?= $conteo['en_curso'] ?></div><small class="text-muted">Pagados, en preparación o enviados</small></div></div>
            <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm p-3 h-100"><small class="text-muted fw-bold text-uppercase">Entregados</small><div class="fs-3 fw-bold text-success"><?= $conteo['entregados'] ?></div></div></div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="fw-bold mb-0"><i class="fas fa-bag-shopping me-2 text-success"></i>Historial de compras</h5>
            <a href="../tienda.php" class="btn btn-sm btn-success rounded-pill px-3"><i class="fas fa-store me-1"></i>Seguir comprando</a>
        </div>

        <?php if (!$pedidos): ?>
            <div class="vacio-tienda"><i class="fas fa-bag-shopping d-block"></i><p class="fw-bold mb-1">Aún no tienes pedidos</p><p>Explora plantillas, cursos, licencias y kits en nuestra tienda.</p><a href="../tienda.php" class="btn btn-carrito">Ir a la tienda</a></div>
        <?php endif; ?>

        <?php foreach ($pedidos as $p):
            $items = itemsPedido($db, $p['id']);
            $pasos = $pasosPedido($p);
            $claves = array_keys($pasos);
            $actual = array_search($p['estado'], $claves, true);
            $abierto = (int) $p['id'] === $seleccionado;
            $puede_pagar = $p['estado'] === 'Pendiente de pago' && !in_array($p['estado_factura'], ['En Proceso', 'Pagado', 'Anulada'], true);
        ?>
        <article class="pedido-card <?= $abierto ? 'seleccionado' : '' ?>" id="pedido-<?= (int) $p['id'] ?>" data-pedido="<?= esc($p['numero']) ?>">
            <div class="pedido-cabecera">
                <div class="d-flex flex-wrap gap-4">
                    <div class="dato"><small>Pedido</small><b><?= esc($p['numero']) ?></b></div>
                    <div class="dato"><small>Fecha</small><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></div>
                    <div class="dato"><small>Total</small><b><?= dinero($p['total']) ?></b></div>
                    <div class="dato"><small>Factura</small><?= esc($p['numero_factura'] ?: '—') ?></div>
                </div>
                <span class="badge rounded-pill text-bg-<?= ESTADOS_PEDIDO[$p['estado']] ?? 'secondary' ?> px-3 py-2 estado-pedido"><?= esc($p['estado']) ?><?= $p['estado'] === 'Pendiente de pago' && $p['estado_factura'] === 'En Proceso' ? ' · pago en validación' : '' ?></span>
            </div>
            <div class="p-3 p-md-4">
                <?php if ($p['estado'] !== 'Cancelado'): ?>
                <div class="seguimiento mb-3" aria-label="Seguimiento del pedido">
                    <?php foreach ($claves as $i => $clave): ?>
                        <div class="paso <?= $actual !== false && $i <= $actual ? 'hecho' : '' ?> <?= $i === $actual ? 'actual' : '' ?>"><i class="fas <?= $pasos[$clave][1] ?>"></i><?= esc($pasos[$clave][0]) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="row g-3 align-items-center">
                    <div class="col-lg-7">
                        <?php foreach (array_slice($items, 0, 3) as $i): ?>
                        <div class="mini-linea">
                            <?= imagenProductoTienda(['nombre' => $i['nombre'], 'imagen' => $i['imagen'], 'icono' => $i['icono'], 'color' => $i['color']], '../') ?>
                            <div><b><?= esc($i['nombre']) ?></b><span class="small text-muted"><?= (int) $i['cantidad'] ?> × <?= dinero($i['precio_unitario']) ?></span></div>
                            <div class="fw-bold small text-nowrap"><?= dinero($i['total']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($items) > 3): ?><div class="small text-muted mt-1">y <?= count($items) - 3 ?> producto(s) más</div><?php endif; ?>
                    </div>
                    <div class="col-lg-5 d-flex flex-wrap gap-2 justify-content-lg-end">
                        <?php if ($puede_pagar): ?>
                            <a href="mis_facturas.php?pagar=<?= (int) $p['factura_id'] ?>" class="btn btn-success fw-bold" data-pagar-pedido="<?= (int) $p['id'] ?>"><i class="fas fa-credit-card me-1"></i>Pagar <?= dinero($p['total']) ?></a>
                        <?php endif; ?>
                        <?php if ($p['factura_id']): ?>
                            <a href="generar_pdf.php?id=<?= (int) $p['factura_id'] ?>" target="_blank" class="btn btn-outline-primary"><i class="fas fa-file-pdf me-1"></i>Factura</a>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#detalle-<?= (int) $p['id'] ?>" aria-expanded="<?= $abierto ? 'true' : 'false' ?>"><i class="fas fa-list me-1"></i>Detalle</button>
                    </div>
                </div>

                <div class="collapse <?= $abierto ? 'show' : '' ?>" id="detalle-<?= (int) $p['id'] ?>">
                    <hr>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <h6 class="fw-bold">Productos</h6>
                            <?php foreach ($items as $i): ?>
                                <div class="d-flex justify-content-between small py-1 border-bottom"><span><?= esc($i['nombre']) ?> <span class="text-muted">× <?= (int) $i['cantidad'] ?></span></span><span><?= dinero($i['total']) ?></span></div>
                            <?php endforeach; ?>
                            <div class="d-flex justify-content-between small py-1"><span>Subtotal</span><span><?= dinero($p['subtotal']) ?></span></div>
                            <?php if ((float) $p['descuento'] > 0): ?><div class="d-flex justify-content-between small py-1 text-success"><span>Descuento <?= $p['cupon'] ? '(' . esc($p['cupon']) . ')' : '' ?></span><span>−<?= dinero($p['descuento']) ?></span></div><?php endif; ?>
                            <?php if ((int) $p['fisicos'] > 0): ?><div class="d-flex justify-content-between small py-1"><span>Envío</span><span><?= (float) $p['envio'] > 0 ? dinero($p['envio']) : 'Gratis' ?></span></div><?php endif; ?>
                            <div class="d-flex justify-content-between small py-1 text-muted"><span>IVA incluido</span><span><?= dinero($p['iva']) ?></span></div>
                            <div class="d-flex justify-content-between fw-bold py-1"><span>Total</span><span><?= dinero($p['total']) ?></span></div>
                            <?php if ($p['entrega_direccion']): ?>
                                <h6 class="fw-bold mt-3">Entrega</h6>
                                <div class="small"><?= esc($p['entrega_nombre']) ?> · <?= esc($p['entrega_telefono']) ?><br><?= esc($p['entrega_direccion']) ?>, <?= esc($p['entrega_ciudad']) ?><?= $p['guia_envio'] ? '<br><b>Guía de envío:</b> ' . esc($p['guia_envio']) : '' ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6">
                            <h6 class="fw-bold">Historial</h6>
                            <ul class="historial-pedido">
                                <?php foreach (historialPedido($db, $p['id']) as $h): ?>
                                    <li><b><?= esc($h['estado']) ?></b> <span class="text-muted">· <?= date('d/m/Y H:i', strtotime($h['fecha'])) ?></span><div><?= esc($h['detalle']) ?></div></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($puede_pagar): ?>
                            <form method="post" class="mt-2" onsubmit="return confirm('¿Cancelar el pedido <?= esc($p['numero']) ?>? Se anulará su factura.');">
                                <input type="hidden" name="accion" value="cancelar">
                                <input type="hidden" name="pedido_id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-link text-danger px-0"><i class="fas fa-ban me-1"></i>Cancelar pedido</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const seleccionado = document.querySelector('.pedido-card.seleccionado');
    if (seleccionado && !document.getElementById('confirmacionPedido')) seleccionado.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>
</body>
</html>
