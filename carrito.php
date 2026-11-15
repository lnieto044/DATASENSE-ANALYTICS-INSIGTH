<?php
/**
 * TIENDA - CARRITO DE COMPRAS
 * Cantidades, quitar productos, cupón de descuento, envío gratis desde TIENDA_ENVIO_GRATIS_DESDE y resumen con IVA incluido.
 */
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/tienda.php';
require_once __DIR__ . '/includes/tienda_layout.php';

iniciarSesionSegura();
$db = require __DIR__ . '/config/database.php';
if (!tiendaDisponible($db)) {
    http_response_code(503);
    exit('La tienda estará disponible muy pronto.');
}

$lineas = lineasCarrito($db);
$t = totalesTienda($db, $lineas, cuponSesion());
$sugeridos = $lineas ? [] : destacadosVariados($db, 4, ['disponibles' => 1]);

cabeceraTienda($db, 'Carrito de compras', ['migas' => [['Tienda', 'tienda.php'], ['Carrito', null]]]);
?>
<div class="container py-4" id="paginaCarrito">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 fw-bold mb-0"><i class="fas fa-cart-shopping me-2 text-success"></i>Carrito de compras</h1>
        <div class="pasos-compra">
            <span class="actual"><b>1</b>Carrito</span><i class="fas fa-chevron-right"></i>
            <span><b>2</b>Datos y confirmación</span><i class="fas fa-chevron-right"></i>
            <span><b>3</b>Pago</span>
        </div>
    </div>

    <?php if (!$lineas): ?>
        <div class="vacio-tienda">
            <i class="fas fa-cart-shopping d-block"></i>
            <p class="fw-bold mb-1">Tu carrito está vacío</p>
            <p>Agrega plantillas, cursos, licencias o kits y vuelve aquí para finalizar tu compra.</p>
            <a href="tienda.php" class="btn btn-carrito"><i class="fas fa-store me-2"></i>Ir a la tienda</a>
        </div>
        <?php if ($sugeridos): ?>
        <h2 class="h5 fw-bold mt-5 mb-3">Te recomendamos</h2>
        <div class="rejilla-productos"><?php foreach ($sugeridos as $p) echo tarjetaProductoTienda($p); ?></div>
        <?php endif; ?>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="panel-tienda">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <b><?= (int) $t['unidades'] ?> unidad(es)</b>
                    <button type="button" class="btn btn-link btn-sm text-danger p-0" id="btnVaciarCarrito"><i class="far fa-trash-can me-1"></i>Vaciar carrito</button>
                </div>
                <?php foreach ($lineas as $l): $p = $l['producto']; $maximo = maximoCompraProducto($p); ?>
                <div class="linea-carrito" data-linea="<?= (int) $p['id'] ?>">
                    <a href="producto.php?p=<?= esc(urlencode($p['slug'])) ?>"><?= imagenProductoTienda($p) ?></a>
                    <div>
                        <div class="producto-categoria"><?= esc($p['categoria'] ?: $p['tipo']) ?></div>
                        <h3><a href="producto.php?p=<?= esc(urlencode($p['slug'])) ?>" class="text-reset"><?= esc($p['nombre']) ?></a></h3>
                        <div class="small text-muted"><?= dinero($p['precio']) ?> c/u · <i class="fas <?= esc(TIPOS_PRODUCTO[$p['tipo']] ?? 'fa-box') ?> me-1"></i><?= esc(ENTREGA_TIPO_PRODUCTO[$p['tipo']] ?? '') ?></div>
                        <?php if ($l['aviso']): ?><div class="small text-warning fw-semibold mt-1"><i class="fas fa-triangle-exclamation me-1"></i><?= esc($l['aviso']) ?></div><?php endif; ?>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-2">
                        <div class="fw-bold fs-6"><?= dinero($l['total']) ?></div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="selector-cantidad">
                                <button type="button" data-paso="-1" data-objetivo="cant_<?= (int) $p['id'] ?>" aria-label="Menos">−</button>
                                <input type="number" id="cant_<?= (int) $p['id'] ?>" value="<?= (int) $l['cantidad'] ?>" min="1" max="<?= $maximo ?>" data-actualizar="<?= (int) $p['id'] ?>" aria-label="Cantidad de <?= esc($p['nombre']) ?>">
                                <button type="button" data-paso="1" data-objetivo="cant_<?= (int) $p['id'] ?>" aria-label="Más">+</button>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-quitar="<?= (int) $p['id'] ?>" aria-label="Quitar <?= esc($p['nombre']) ?>" title="Quitar"><i class="far fa-trash-can"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="tienda.php" class="btn btn-link text-secondary mt-2 px-0"><i class="fas fa-arrow-left me-2"></i>Seguir comprando</a>
        </div>

        <div class="col-lg-4">
            <div class="panel-tienda resumen-compra">
                <h2 class="h5 fw-bold mb-3">Resumen de tu compra</h2>
                <?php if ($t['falta_envio_gratis'] > 0): ?>
                    <div class="barra-envio mb-3"><i class="fas fa-truck-fast me-1"></i>Te faltan <b><?= dinero($t['falta_envio_gratis']) ?></b> para el envío gratis</div>
                <?php elseif ($t['requiere_envio']): ?>
                    <div class="barra-envio mb-3"><i class="fas fa-circle-check me-1"></i>¡Tu pedido tiene <b>envío gratis</b>!</div>
                <?php endif; ?>
                <div class="fila"><span>Subtotal</span><span id="resumenSubtotal"><?= dinero($t['subtotal']) ?></span></div>
                <?php if ($t['descuento'] > 0): ?><div class="fila text-success"><span>Descuento (<?= esc($t['cupon']['codigo']) ?>)</span><span id="resumenDescuento">−<?= dinero($t['descuento']) ?></span></div><?php endif; ?>
                <?php if ($t['requiere_envio']): ?><div class="fila"><span>Envío</span><span><?= $t['envio'] > 0 ? dinero($t['envio']) : 'Gratis' ?></span></div><?php endif; ?>
                <div class="fila total"><span>Total</span><span id="resumenTotal"><?= dinero($t['total']) ?></span></div>
                <div class="small text-muted mb-3">Incluye IVA (<?= TIENDA_IVA ?> %) por <?= dinero($t['iva']) ?></div>

                <?php if (cuponSesion() !== '' && !$t['error_cupon']): ?>
                    <div class="d-flex justify-content-between align-items-center border rounded-3 px-3 py-2 mb-3 bg-light">
                        <span class="small"><i class="fas fa-ticket text-success me-1"></i>Cupón <b><?= esc(cuponSesion()) ?></b></span>
                        <button type="button" class="btn btn-link btn-sm text-danger p-0" id="btnQuitarCupon">Quitar</button>
                    </div>
                <?php else: ?>
                    <?php if ($t['error_cupon']): ?>
                        <div class="alert alert-warning small py-2 d-flex justify-content-between align-items-center gap-2"><span><?= esc($t['error_cupon']) ?></span><button type="button" class="btn btn-link btn-sm p-0" id="btnQuitarCupon">Quitar</button></div>
                    <?php endif; ?>
                    <form class="input-group input-group-sm mb-3" id="formCupon">
                        <span class="input-group-text"><i class="fas fa-ticket"></i></span>
                        <input type="text" class="form-control text-uppercase" name="codigo" id="codigoCupon" maxlength="30" placeholder="Código de descuento" aria-label="Código de descuento">
                        <button class="btn btn-outline-secondary" type="submit">Aplicar</button>
                    </form>
                <?php endif; ?>

                <a href="checkout.php" class="btn btn-comprar w-100 py-3" id="btnIrCheckout"><i class="fas fa-lock me-2"></i>Finalizar compra</a>
                <div class="text-center small text-muted mt-3"><i class="fas fa-shield-halved me-1"></i>Compra segura · Factura en cada pedido</div>
                <div class="tienda-pagos justify-content-center mt-2" style="color:#475569;font-size:1.7rem"><i class="fab fa-cc-visa"></i><i class="fab fa-cc-mastercard"></i><i class="fab fa-cc-paypal"></i><span style="background:#eef2f7">PSE</span><span style="background:#eef2f7">Nequi</span></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.body.dataset.pagina = 'carrito';
document.addEventListener('DOMContentLoaded', function () {
    const ui = window.TiendaUI;
    const recargar = function (d) {
        ui.aviso(d.mensaje, d.ok ? 'success' : 'danger');
        setTimeout(function () { window.location.reload(); }, d.ok ? 500 : 1500);
    };
    document.querySelectorAll('[data-actualizar]').forEach(function (input) {
        input.addEventListener('change', async function () {
            recargar(await ui.llamar('actualizar', { producto_id: input.dataset.actualizar, cantidad: input.value }));
        });
    });
    document.querySelectorAll('[data-quitar]').forEach(function (boton) {
        boton.addEventListener('click', async function () {
            boton.disabled = true;
            recargar(await ui.llamar('actualizar', { producto_id: boton.dataset.quitar, cantidad: 0 }));
        });
    });
    const vaciar = document.getElementById('btnVaciarCarrito');
    if (vaciar) vaciar.addEventListener('click', async function () {
        if (!confirm('¿Quitar todos los productos del carrito?')) return;
        const ids = Array.from(document.querySelectorAll('[data-quitar]')).map(function (b) { return b.dataset.quitar; });
        for (const id of ids) await ui.llamar('actualizar', { producto_id: id, cantidad: 0 });
        window.location.reload();
    });
    const cupon = document.getElementById('formCupon');
    if (cupon) cupon.addEventListener('submit', async function (e) {
        e.preventDefault();
        recargar(await ui.llamar('cupon', { codigo: document.getElementById('codigoCupon').value }));
    });
    const quitarCupon = document.getElementById('btnQuitarCupon');
    if (quitarCupon) quitarCupon.addEventListener('click', async function () { recargar(await ui.llamar('quitar_cupon')); });
});
</script>
<?php pieTienda($db); ?>
