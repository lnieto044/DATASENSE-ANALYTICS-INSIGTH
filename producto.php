<?php
/**
 * TIENDA - DETALLE DE PRODUCTO
 * Precio y ahorro, inventario, cantidad, agregar o comprar ahora, qué incluye, reseñas de compradores verificados,
 * productos relacionados y datos estructurados (schema.org Product) para buscadores.
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

$slug = is_string($_GET['p'] ?? null) ? $_GET['p'] : '';
$p = $slug !== '' ? productoTienda($db, ['slug' => $slug]) : null;
if (!$p) {
    http_response_code(404);
    cabeceraTienda($db, 'Producto no encontrado', ['migas' => [['Tienda', 'tienda.php'], ['Producto no encontrado', null]]]);
    echo '<div class="container py-5"><div class="vacio-tienda"><i class="fas fa-box-open d-block"></i><p class="fw-bold mb-1">Este producto no existe o ya no está disponible</p>'
       . '<a href="tienda.php" class="btn btn-carrito mt-2">Volver a la tienda</a></div></div>';
    pieTienda($db);
    exit;
}

$descuento = descuentoProducto($p);
list($texto_stock, $clase_stock) = stockProducto($p);
$maximo = maximoCompraProducto($p);
$resenas = resenasProducto($db, $p['id']);
$distribucion = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($resenas as $r) $distribucion[(int) $r['calificacion']]++;
$relacionados = productosTienda($db, ['categoria' => $p['categoria_slug'], 'excluir' => $p['id'], 'limite' => 4]);
$sesion = sesionTienda();
$puede_resenar = $sesion && puedeResenar($db, $sesion['id'], $p['id']);
$mi_resena = null;
foreach ($resenas as $r) if ($sesion && (int) $r['usuario_id'] === $sesion['id']) $mi_resena = $r;
$incluye = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $p['incluye']))));

cabeceraTienda($db, $p['nombre'], [
    'descripcion' => $p['resumen'], 'categoria' => $p['categoria_slug'],
    'migas' => [['Tienda', 'tienda.php'], [$p['categoria'] ?: $p['tipo'], $p['categoria_slug'] ? 'tienda.php?categoria=' . urlencode($p['categoria_slug']) : 'tienda.php'], [$p['nombre'], null]],
]);

$datos_estructurados = [
    '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['nombre'], 'sku' => $p['sku'], 'description' => $p['resumen'],
    'brand' => ['@type' => 'Brand', 'name' => 'DataSense'],
    'offers' => ['@type' => 'Offer', 'priceCurrency' => 'COP', 'price' => (float) $p['precio'], 'availability' => $maximo > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'],
];
if ($resenas) $datos_estructurados['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float) $p['calificacion'], 1), 'reviewCount' => count($resenas)];
?>
<script type="application/ld+json"><?= json_encode($datos_estructurados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="container py-4" data-producto-detalle="<?= (int) $p['id'] ?>">
    <div class="row g-4 align-items-start">
        <div class="col-lg-6">
            <div class="detalle-media position-relative">
                <?= imagenProductoTienda($p) ?>
                <div class="producto-insignias">
                    <?php if ($descuento): ?><span class="insignia insignia-oferta">-<?= $descuento ?>%</span><?php endif; ?>
                    <?php if ((int) $p['destacado']): ?><span class="insignia insignia-destacado"><i class="fas fa-star me-1"></i>Destacado</span><?php endif; ?>
                </div>
                <button type="button" class="producto-favorito" data-favorito="<?= (int) $p['id'] ?>" aria-label="Agregar a favoritos"><i class="far fa-heart"></i></button>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="detalle-card">
                <a href="tienda.php?categoria=<?= esc(urlencode((string) $p['categoria_slug'])) ?>" class="producto-categoria"><i class="fas <?= esc(TIPOS_PRODUCTO[$p['tipo']] ?? 'fa-box') ?> me-1"></i><?= esc($p['categoria'] ?: $p['tipo']) ?> · <?= esc($p['tipo']) ?></a>
                <h1 class="mt-2 mb-2"><?= esc($p['nombre']) ?></h1>
                <div class="producto-calificacion mb-3" style="font-size:.88rem">
                    <?= estrellasTienda($p['calificacion']) ?>
                    <a href="#pestanaOpiniones" class="text-muted" onclick="bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target=&quot;#pestanaOpiniones&quot;]')).show()">
                        <?= count($resenas) ? number_format((float) $p['calificacion'], 1, ',', '') . ' · ' . count($resenas) . ' opinión(es)' : 'Sé el primero en opinar' ?></a>
                    <?php if ((int) $p['vendidos'] > 0): ?><span>· <?= (int) $p['vendidos'] ?> vendidos</span><?php endif; ?>
                </div>
                <p class="text-muted"><?= esc($p['resumen']) ?></p>

                <div class="detalle-precio d-flex flex-wrap align-items-baseline gap-2 mb-1">
                    <span class="precio-actual"><?= dinero($p['precio']) ?></span>
                    <?php if ($descuento): ?>
                        <span class="precio-anterior"><?= dinero($p['precio_anterior']) ?></span>
                        <span class="ahorro">Ahorras <?= dinero((float) $p['precio_anterior'] - (float) $p['precio']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="small text-muted mb-3">IVA incluido · SKU <?= esc($p['sku']) ?></div>
                <div class="producto-stock text-<?= $clase_stock ?> mb-3" id="estadoStock"><i class="fas fa-circle me-1"></i><?= esc($texto_stock) ?></div>

                <?php if ($maximo > 0): ?>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="selector-cantidad">
                        <button type="button" data-paso="-1" data-objetivo="cantidadProducto" aria-label="Menos">−</button>
                        <input type="number" id="cantidadProducto" value="1" min="1" max="<?= $maximo ?>" aria-label="Cantidad">
                        <button type="button" data-paso="1" data-objetivo="cantidadProducto" aria-label="Más">+</button>
                    </div>
                    <button type="button" class="btn btn-carrito flex-grow-1" data-agregar="<?= (int) $p['id'] ?>" data-cantidad="cantidadProducto"><i class="fas fa-cart-plus me-2"></i>Agregar al carrito</button>
                    <button type="button" class="btn btn-comprar flex-grow-1" id="btnComprarAhora" data-agregar="<?= (int) $p['id'] ?>" data-cantidad="cantidadProducto" data-ir="checkout.php"><i class="fas fa-bolt me-2"></i>Comprar ahora</button>
                </div>
                <?php else: ?>
                <div class="alert alert-secondary mb-0"><i class="fas fa-ban me-2"></i>Producto agotado por ahora. Guárdalo en favoritos o <a href="index.php#contacto">escríbenos</a> para avisarte cuando llegue.</div>
                <?php endif; ?>

                <?php if ($p['categoria_slug'] === 'bienestar-saludable'): ?>
                <div class="alert alert-success bg-success bg-opacity-10 border-0 small mt-3 mb-0" id="avisoBienestar"><i class="fas fa-leaf me-2"></i>Producto de bienestar: complementa una alimentación balanceada y hábitos saludables. No es un medicamento. Si estás en embarazo, lactancia o tratamiento médico, consulta a tu médico antes de consumirlo.</div>
                <?php endif; ?>
                <div class="beneficios-detalle">
                    <div><i class="fas <?= esc(TIPOS_PRODUCTO[$p['tipo']] ?? 'fa-box') ?>"></i><span><b>Entrega:</b> <?= esc(ENTREGA_TIPO_PRODUCTO[$p['tipo']] ?? 'Coordinada con nuestro equipo') ?></span></div>
                    <div><i class="fas fa-shield-halved"></i><span><b>Pago seguro:</b> PSE, tarjetas, Nequi, efectivo, PayPal, Zelle y más</span></div>
                    <div><i class="fas fa-file-invoice"></i><span><b>Factura</b> con el IVA discriminado en cada compra</span></div>
                    <?php if ((int) $p['requiere_envio']): ?><div><i class="fas fa-truck-fast"></i><span><b>Envío gratis</b> en compras desde <?= dinero(TIENDA_ENVIO_GRATIS_DESDE) ?> (si no, <?= dinero(TIENDA_ENVIO) ?>)</span></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel-tienda mt-4">
        <ul class="nav pestanas-detalle border-bottom mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pestanaDescripcion" type="button" role="tab">Descripción</button></li>
            <?php if ($incluye): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pestanaIncluye" type="button" role="tab">Qué incluye</button></li><?php endif; ?>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pestanaOpiniones" type="button" role="tab">Opiniones (<?= count($resenas) ?>)</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="pestanaDescripcion" role="tabpanel"><p class="mb-0" style="max-width:820px"><?= nl2br(esc($p['descripcion'] ?: $p['resumen'])) ?></p></div>
            <?php if ($incluye): ?>
            <div class="tab-pane fade" id="pestanaIncluye" role="tabpanel">
                <ul class="lista-incluye"><?php foreach ($incluye as $linea): ?><li><i class="fas fa-circle-check"></i><span><?= esc($linea) ?></span></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>
            <div class="tab-pane fade" id="pestanaOpiniones" role="tabpanel">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="text-center text-md-start">
                            <div class="display-5 fw-bold text-dark"><?= count($resenas) ? number_format((float) $p['calificacion'], 1, ',', '') : '—' ?></div>
                            <?= estrellasTienda($p['calificacion']) ?>
                            <div class="small text-muted mb-3"><?= count($resenas) ?> opinión(es) de compradores verificados</div>
                        </div>
                        <?php foreach ($distribucion as $estrellas => $cantidad): ?>
                        <div class="d-flex align-items-center gap-2 small mb-1">
                            <span style="width:52px"><?= $estrellas ?> <i class="fas fa-star text-warning"></i></span>
                            <div class="barra-calificacion flex-grow-1"><span style="width:<?= count($resenas) ? round($cantidad * 100 / count($resenas)) : 0 ?>%"></span></div>
                            <span class="text-muted" style="width:24px"><?= $cantidad ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-md-8">
                        <?php if ($puede_resenar): ?>
                        <form id="formResena" class="border rounded-3 p-3 mb-3 bg-light">
                            <div class="fw-bold mb-1"><?= $mi_resena ? 'Actualiza tu opinión' : 'Cuéntanos qué te pareció' ?></div>
                            <div class="selector-estrellas mb-2" role="radiogroup" aria-label="Calificación">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <input type="radio" name="calificacion" id="estrella<?= $i ?>" value="<?= $i ?>" <?= $mi_resena && (int) $mi_resena['calificacion'] === $i ? 'checked' : '' ?>><label for="estrella<?= $i ?>" title="<?= $i ?> estrella(s)"><i class="fas fa-star"></i></label>
                                <?php endfor; ?>
                            </div>
                            <input type="text" name="titulo" maxlength="100" class="form-control form-control-sm mb-2" placeholder="Título (opcional)" value="<?= esc($mi_resena['titulo'] ?? '') ?>">
                            <textarea name="comentario" maxlength="1000" rows="3" class="form-control form-control-sm mb-2" placeholder="¿Qué te gustó? ¿Qué mejorarías?"><?= esc($mi_resena['comentario'] ?? '') ?></textarea>
                            <button type="submit" class="btn btn-carrito btn-sm"><i class="fas fa-paper-plane me-1"></i>Publicar opinión</button>
                        </form>
                        <?php elseif (!$sesion): ?>
                        <div class="alert alert-light border small"><i class="fas fa-circle-info me-1"></i>Las opiniones son de compradores verificados. <a href="<?= esc(enlaceIngresoTienda()) ?>">Ingresa</a> para calificar tus compras.</div>
                        <?php else: ?>
                        <div class="alert alert-light border small"><i class="fas fa-circle-info me-1"></i>Podrás calificar este producto cuando lo compres y se confirme el pago.</div>
                        <?php endif; ?>
                        <?php foreach ($resenas as $r): ?>
                        <div class="resena">
                            <div class="d-flex justify-content-between flex-wrap gap-2"><span><?= estrellasTienda($r['calificacion']) ?> <b class="ms-1"><?= esc($r['titulo'] ?: '') ?></b></span><small class="text-muted"><?= date('d/m/Y', strtotime($r['created_at'])) ?></small></div>
                            <?php if ($r['comentario']): ?><p class="mb-1 mt-1"><?= nl2br(esc($r['comentario'])) ?></p><?php endif; ?>
                            <small class="text-muted"><i class="fas fa-circle-check text-success me-1"></i><?= esc(strtok((string) $r['nombre'], ' ')) ?><?= $r['organizacion'] ? ' · ' . esc($r['organizacion']) : '' ?> · Compra verificada</small>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!$resenas): ?><p class="text-muted small mb-0">Aún no hay opiniones para este producto.</p><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($relacionados): ?>
    <section class="mt-5" aria-labelledby="tituloRelacionados">
        <h2 class="h4 fw-bold mb-3" id="tituloRelacionados">También te puede interesar</h2>
        <div class="rejilla-productos"><?php foreach ($relacionados as $r) echo tarjetaProductoTienda($r); ?></div>
    </section>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formResena');
    if (!form) return;
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const datos = Object.fromEntries(new FormData(form).entries());
        const d = await window.TiendaUI.llamar('resena', Object.assign({ producto_id: <?= (int) $p['id'] ?> }, datos));
        window.TiendaUI.aviso(d.mensaje, d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(function () { window.location.reload(); }, 900);
    });
});
</script>
<?php pieTienda($db); ?>
