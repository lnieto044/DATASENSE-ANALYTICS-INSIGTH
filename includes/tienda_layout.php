<?php
/**
 * TIENDA PÚBLICA - ENCABEZADO Y PIE COMUNES
 * Búsqueda, categorías, favoritos, cuenta, carrito lateral (se llena con tienda_api.php) y pie con medios de pago.
 */

function sesionTienda() {
    iniciarSesionSegura();
    if (empty($_SESSION['admin_id'])) return null;
    return ['id' => (int) $_SESSION['admin_id'], 'nombre' => (string) ($_SESSION['admin_nombre'] ?? ''), 'rol' => (string) ($_SESSION['admin_rol'] ?? '')];
}

// Enlace al login que regresa a la página actual de la tienda
function enlaceIngresoTienda() {
    $actual = '../' . basename($_SERVER['SCRIPT_NAME'] ?? 'tienda.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
    return 'admin/login.php?volver=' . rawurlencode($actual);
}

require_once __DIR__ . '/redes.php'; // tarjeta al compartir e iconos de las redes

function cabeceraTienda(PDO $db, $titulo, array $op = []) {
    $sesion = sesionTienda();
    $categorias = categoriasTienda($db);
    $unidades = unidadesCarrito();
    $busqueda = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
    $migas = $op['migas'] ?? [];
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($titulo) ?> | Tienda DataSense</title>
    <meta name="description" content="<?= esc($op['descripcion'] ?? 'Productos bioenergéticos y saludables, plantillas de tableros, cursos, licencias, kits IoT, productos de marca y revistas.') ?>">
    <?= metaSociales($titulo . ' | Tienda DataSense',
          $op['descripcion'] ?? 'Productos bioenergéticos y saludables, plantillas de tableros, cursos, licencias, kits IoT, productos de marca y revistas.',
          $op['imagen'] ?? 'img/banner.jpg', $op['og_tipo'] ?? 'website') ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/tienda.css?v=20260915">
    <link rel="icon" type="image/png" href="img/favicon.png">
</head>
<body class="tienda">
<?= botonWhatsapp('Hola, vengo de la tienda de DataSense y quiero información sobre un producto.') ?>

<div class="tienda-topbar">
    <div class="container d-flex justify-content-between align-items-center gap-3">
        <span><i class="fas fa-truck-fast me-1"></i> Envío gratis desde <?= dinero(TIENDA_ENVIO_GRATIS_DESDE) ?></span>
        <span class="d-none d-md-inline"><i class="fas fa-shield-halved me-1"></i> Pago seguro · PSE, tarjetas, Nequi y PayPal</span>
        <span class="d-none d-lg-inline"><i class="fas fa-file-invoice me-1"></i> Factura en cada compra</span>
    </div>
</div>

<header class="tienda-header" id="cabeceraTienda">
    <div class="container d-flex align-items-center gap-3 py-2">
        <a href="./" class="tienda-logo" aria-label="Ir al inicio de DataSense"><img src="img/logo.png" alt="DataSense Analytics Insights"></a>
        <form class="tienda-busqueda flex-grow-1 d-none d-md-flex" action="tienda.php" method="get" role="search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= esc($busqueda) ?>" placeholder="Buscar plantillas, cursos, revistas, productos de marca..." aria-label="Buscar productos">
            <button type="submit">Buscar</button>
        </form>
        <nav class="tienda-acciones ms-auto" aria-label="Cuenta y carrito">
            <a href="tienda.php?vista=favoritos" class="tienda-icono" title="Mis favoritos" aria-label="Mis favoritos">
                <i class="far fa-heart"></i><span class="tienda-contador" id="contadorFavoritos" hidden>0</span>
            </a>
            <?php if ($sesion): ?>
            <div class="dropdown">
                <button class="tienda-icono tienda-cuenta" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mi cuenta">
                    <i class="far fa-user"></i><span class="d-none d-lg-inline ms-2 text-truncate"><?= esc(strtok($sesion['nombre'], ' ')) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                    <li><a class="dropdown-item" href="admin/mis_pedidos.php"><i class="fas fa-bag-shopping me-2 text-success"></i>Mis pedidos</a></li>
                    <li><a class="dropdown-item" href="admin/mis_facturas.php"><i class="fas fa-receipt me-2 text-primary"></i>Mis facturas</a></li>
                    <li><a class="dropdown-item" href="admin/soporte.php"><i class="fas fa-headset me-2 text-info"></i>Soporte</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="admin/logout.php"><i class="fas fa-power-off me-2"></i>Cerrar sesión</a></li>
                </ul>
            </div>
            <?php else: ?>
            <a href="<?= esc(enlaceIngresoTienda()) ?>" class="tienda-icono tienda-cuenta" title="Ingresar o crear cuenta">
                <i class="far fa-user"></i><span class="d-none d-lg-inline ms-2">Ingresar</span>
            </a>
            <?php endif; ?>
            <button type="button" class="tienda-icono tienda-carrito" data-bs-toggle="offcanvas" data-bs-target="#miniCarrito" aria-controls="miniCarrito" aria-label="Ver carrito">
                <i class="fas fa-cart-shopping"></i><span class="tienda-contador" id="contadorCarrito" <?= $unidades ? '' : 'hidden' ?>><?= $unidades ?></span>
            </button>
        </nav>
    </div>
    <form class="tienda-busqueda container d-flex d-md-none mb-2" action="tienda.php" method="get" role="search">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?= esc($busqueda) ?>" placeholder="¿Qué buscas?" aria-label="Buscar productos">
        <button type="submit" aria-label="Buscar"><i class="fas fa-arrow-right"></i></button>
    </form>
    <nav class="tienda-categorias" aria-label="Categorías">
        <div class="container categorias-marco">
            <button type="button" class="categorias-flecha anterior" data-desplazar="-1" aria-label="Ver las categorías anteriores" title="Anteriores" hidden><i class="fas fa-chevron-left"></i></button>
            <div class="categorias-pista" id="pistaCategorias">
            <a href="./"><i class="fas fa-house me-1"></i>Inicio</a>
            <a href="tienda.php" class="<?= ($op['activo'] ?? '') === 'tienda' ? 'activo' : '' ?>"><i class="fas fa-store me-1"></i>Toda la tienda</a>
            <?php foreach ($categorias as $c): ?>
                <a href="tienda.php?categoria=<?= esc(urlencode($c['slug'])) ?>" class="<?= ($op['categoria'] ?? '') === $c['slug'] ? 'activo' : '' ?> <?= $c['slug'] === 'bienestar-saludable' ? 'saludable' : '' ?>"><i class="fas <?= esc($c['icono']) ?> me-1"></i><?= esc($c['nombre']) ?></a>
            <?php endforeach; ?>
            <a href="tienda.php?oferta=1" class="oferta <?= !empty($op['oferta']) ? 'activo' : '' ?>"><i class="fas fa-tags me-1"></i>Ofertas</a>
            </div>
            <button type="button" class="categorias-flecha siguiente" data-desplazar="1" aria-label="Ver más categorías" title="Más categorías" hidden><i class="fas fa-chevron-right"></i></button>
        </div>
    </nav>
</header>

<?php if ($migas): ?>
<nav class="container mt-3" aria-label="Ruta de navegación">
    <ol class="breadcrumb tienda-migas mb-0">
        <li class="breadcrumb-item"><a href="./">Inicio</a></li>
        <?php foreach ($migas as $i => $miga): ?>
            <?php if ($i === count($migas) - 1 || empty($miga[1])): ?>
                <li class="breadcrumb-item active" aria-current="page"><?= esc($miga[0]) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= esc($miga[1]) ?>"><?= esc($miga[0]) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
<main class="tienda-main">
    <?php
}

function pieTienda(PDO $db) {
    $sesion = sesionTienda();
    ?>
</main>

<section class="tienda-garantias">
    <div class="container">
        <div class="row g-3 text-center text-md-start">
            <div class="col-6 col-lg-3"><i class="fas fa-lock"></i><div><b>Pago 100 % seguro</b><span>PSE, tarjetas, Nequi, efectivo y PayPal</span></div></div>
            <div class="col-6 col-lg-3"><i class="fas fa-file-invoice-dollar"></i><div><b>Factura en cada compra</b><span>Con el IVA discriminado</span></div></div>
            <div class="col-6 col-lg-3"><i class="fas fa-headset"></i><div><b>Soporte experto</b><span>Mesa de servicios con ANS</span></div></div>
            <div class="col-6 col-lg-3"><i class="fas fa-truck-fast"></i><div><b>Envíos a toda Colombia</b><span>Gratis desde <?= dinero(TIENDA_ENVIO_GRATIS_DESDE) ?></span></div></div>
        </div>
    </div>
</section>

<footer class="tienda-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <img src="img/logo.png" alt="DataSense" class="tienda-footer-logo mb-3">
                <p>Soluciones de datos listas para usar: tableros, formación, software y dispositivos para medir tu operación.</p>
            </div>
            <div class="col-6 col-lg-2">
                <h6>Tienda</h6>
                <a href="tienda.php">Todos los productos</a>
                <a href="tienda.php?oferta=1">Ofertas</a>
                <a href="tienda.php?orden=vendidos">Más vendidos</a>
                <a href="tienda.php?vista=favoritos">Favoritos</a>
            </div>
            <div class="col-6 col-lg-3">
                <h6>Mi cuenta</h6>
                <?php if ($sesion): ?>
                    <a href="admin/mis_pedidos.php">Mis pedidos</a>
                    <a href="admin/mis_facturas.php">Mis facturas</a>
                <?php else: ?>
                    <a href="<?= esc(enlaceIngresoTienda()) ?>">Ingresar o crear cuenta</a>
                <?php endif; ?>
                <a href="carrito.php">Carrito de compras</a>
                <a href="index.php#contacto">Contáctanos</a>
            </div>
            <div class="col-lg-3">
                <h6>Medios de pago</h6>
                <div class="tienda-pagos">
                    <i class="fab fa-cc-visa" title="Visa"></i><i class="fab fa-cc-mastercard" title="Mastercard"></i><i class="fab fa-cc-amex" title="American Express"></i><i class="fab fa-cc-paypal" title="PayPal"></i>
                    <span>PSE</span><span>Nequi</span>
                </div>
            </div>
        </div>
        <div class="tienda-footer-legal">© <?= date('Y') ?> DataSense Analytics Insights · Precios en pesos colombianos con IVA incluido. · <a href="privacidad.php">Política de privacidad</a> · <a href="terminos.php">Términos de uso</a></div>
    </div>
</footer>

<div class="offcanvas offcanvas-end tienda-minicarrito" tabindex="-1" id="miniCarrito" aria-labelledby="miniCarritoTitulo">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold" id="miniCarritoTitulo"><i class="fas fa-cart-shopping me-2"></i>Tu carrito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column" id="miniCarritoCuerpo">
        <div class="text-center text-muted py-5"><i class="fas fa-circle-notch fa-spin"></i></div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="avisosTienda" aria-live="polite"></div>

<script>
window.TIENDA = { csrf: <?= jsJson(tokenCsrf()) ?>, api: 'tienda_api.php' };
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/tienda.js?v=20260915"></script>
</body>
</html>
    <?php
}

// Tarjeta de producto del catálogo, la página principal y los relacionados
function tarjetaProductoTienda(array $p, $prefijo = '') {
    $descuento = descuentoProducto($p);
    list($texto_stock, $clase_stock) = stockProducto($p);
    $agotado = maximoCompraProducto($p) <= 0;
    $url = $prefijo . 'producto.php?p=' . urlencode($p['slug']);
    ob_start();
    ?>
    <article class="producto-card" data-producto="<?= (int) $p['id'] ?>">
        <a href="<?= esc($url) ?>" class="producto-enlace" aria-label="<?= esc($p['nombre']) ?>">
            <?= imagenProductoTienda($p, $prefijo) ?>
        </a>
        <div class="producto-insignias">
            <?php if ($descuento): ?><span class="insignia insignia-oferta">-<?= $descuento ?>%</span><?php endif; ?>
            <?php if ((int) $p['destacado']): ?><span class="insignia insignia-destacado"><i class="fas fa-star me-1"></i>Destacado</span><?php endif; ?>
            <?php if ($agotado): ?><span class="insignia insignia-agotado">Agotado</span><?php endif; ?>
        </div>
        <button type="button" class="producto-favorito" data-favorito="<?= (int) $p['id'] ?>" aria-label="Agregar a favoritos" title="Agregar a favoritos"><i class="far fa-heart"></i></button>
        <div class="producto-cuerpo">
            <div class="producto-categoria"><i class="fas <?= esc(TIPOS_PRODUCTO[$p['tipo']] ?? 'fa-box') ?> me-1"></i><?= esc($p['categoria'] ?: $p['tipo']) ?></div>
            <h3 class="producto-nombre"><a href="<?= esc($url) ?>"><?= esc($p['nombre']) ?></a></h3>
            <div class="producto-calificacion">
                <?= estrellasTienda($p['calificacion']) ?>
                <span><?= (int) $p['resenas'] ? '(' . (int) $p['resenas'] . ')' : 'Nuevo' ?></span>
                <?php if ((int) $p['vendidos'] > 0): ?><span class="ms-auto"><?= (int) $p['vendidos'] ?> vendidos</span><?php endif; ?>
            </div>
            <div class="producto-precio">
                <span class="precio-actual"><?= dinero($p['precio']) ?></span>
                <?php if ($descuento): ?><span class="precio-anterior"><?= dinero($p['precio_anterior']) ?></span><?php endif; ?>
            </div>
            <div class="producto-stock text-<?= $clase_stock ?>"><i class="fas fa-circle me-1"></i><?= esc($texto_stock) ?></div>
            <button type="button" class="btn-agregar" data-agregar="<?= (int) $p['id'] ?>" <?= $agotado ? 'disabled' : '' ?>>
                <i class="fas <?= $agotado ? 'fa-ban' : 'fa-cart-plus' ?> me-2"></i><?= $agotado ? 'Agotado' : 'Agregar al carrito' ?>
            </button>
        </div>
    </article>
    <?php
    return ob_get_clean();
}
