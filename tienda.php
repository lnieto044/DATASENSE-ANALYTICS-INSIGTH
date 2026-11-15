<?php
/**
 * TIENDA - CATÁLOGO
 * Portada con categorías y destacados, búsqueda, filtros (categoría, tipo, precio, ofertas, disponibles), orden, paginación
 * y la vista de favoritos guardados en el navegador (?vista=favoritos).
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

$texto = function ($clave) { return is_string($_GET[$clave] ?? null) ? trim($_GET[$clave]) : ''; };
$filtros = [
    'q' => mb_substr($texto('q'), 0, 80), 'categoria' => $texto('categoria'), 'tipo' => $texto('tipo'),
    'min' => ctype_digit($texto('min')) ? $texto('min') : '', 'max' => ctype_digit($texto('max')) ? $texto('max') : '',
    'oferta' => $texto('oferta') === '1', 'disponibles' => $texto('disponibles') === '1', 'orden' => isset(ORDENES_TIENDA[$texto('orden')]) ? $texto('orden') : 'relevancia',
];
$favoritos = $texto('vista') === 'favoritos';
$categorias = categoriasTienda($db);
$categoria_actual = null;
foreach ($categorias as $c) if ($c['slug'] === $filtros['categoria']) $categoria_actual = $c;
$filtrando = $filtros['q'] !== '' || $filtros['categoria'] !== '' || $filtros['tipo'] !== '' || $filtros['min'] !== '' || $filtros['max'] !== '' || $filtros['oferta'] || $filtros['disponibles'];

$productos = $favoritos ? [] : productosTienda($db, $filtros);
$por_pagina = 12;
$total = count($productos);
$paginas = max(1, (int) ceil($total / $por_pagina));
$pagina = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));
$visibles = array_slice($productos, ($pagina - 1) * $por_pagina, $por_pagina);
$destacados = (!$filtrando && !$favoritos && $pagina === 1) ? destacadosVariados($db, 4) : [];

// Enlace con los filtros actuales y los cambios indicados (sin los vacíos)
$enlace = function (array $cambios = []) use ($filtros) {
    $parametros = array_merge(['q' => $filtros['q'], 'categoria' => $filtros['categoria'], 'tipo' => $filtros['tipo'], 'min' => $filtros['min'], 'max' => $filtros['max'],
                               'oferta' => $filtros['oferta'] ? '1' : '', 'disponibles' => $filtros['disponibles'] ? '1' : '', 'orden' => $filtros['orden'] === 'relevancia' ? '' : $filtros['orden']], $cambios);
    $parametros = array_filter($parametros, function ($v) { return $v !== '' && $v !== null && $v !== false; });
    return 'tienda.php' . ($parametros ? '?' . http_build_query($parametros) : '');
};

if ($favoritos) $titulo = 'Mis favoritos';
elseif ($categoria_actual) $titulo = $categoria_actual['nombre'];
elseif ($filtros['q'] !== '') $titulo = 'Resultados para «' . $filtros['q'] . '»';
elseif ($filtros['oferta']) $titulo = 'Ofertas';
else $titulo = 'Tienda';

cabeceraTienda($db, $titulo, [
    'activo' => $filtrando || $favoritos ? '' : 'tienda', 'categoria' => $filtros['categoria'], 'oferta' => $filtros['oferta'],
    'migas' => $filtrando || $favoritos ? [['Tienda', 'tienda.php'], [$titulo, null]] : [],
]);
?>
<div class="container py-4">
    <?php if ($favoritos): ?>
        <h1 class="h3 fw-bold mb-1"><i class="fas fa-heart text-danger me-2"></i>Mis favoritos</h1>
        <p class="text-muted">Productos que guardaste en este navegador.</p>
        <div id="vistaFavoritos"><div class="text-center text-muted py-5"><i class="fas fa-circle-notch fa-spin"></i></div></div>
    <?php else: ?>

    <?php if (!$filtrando && $pagina === 1): ?>
    <?php
    // Collage del banner: imágenes de los destacados del momento (la conferencia de Marcela va primero)
    $collage = array_slice(array_values(array_filter($destacados ?: [], function ($p) {
        $i = (string) ($p['imagen'] ?? '');
        return preg_match('/^prod_[a-f0-9]{16}\.(jpg|png|webp)$/', $i) && is_file(__DIR__ . '/img/productos/' . $i);
    })), 0, 3);
    ?>
    <section class="tienda-hero mb-4<?= count($collage) === 3 ? ' con-collage' : '' ?>">
        <i class="fas fa-chart-pie hero-icono" aria-hidden="true"></i>
        <?php if (count($collage) === 3): ?>
        <div class="hero-collage" aria-hidden="true">
            <?php foreach ($collage as $k => $pc): ?><img src="img/productos/<?= esc($pc['imagen']) ?>" alt="" class="collage-<?= $k ?>"><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <span class="badge rounded-pill text-bg-light text-uppercase mb-3" style="letter-spacing:.06em"><i class="fas fa-bolt text-warning me-1"></i>Tienda DataSense</span>
        <h1>Todo lo que necesitas para decidir con datos</h1>
        <p class="mb-4">Productos bioenergéticos y saludables, plantillas de tableros, cursos, licencias, kits IoT, productos de marca y revistas. Pagas en línea y recibes tu factura al instante.</p>
        <div class="d-flex flex-wrap gap-2">
            <a href="tienda.php?orden=vendidos#catalogo" class="btn btn-hero"><i class="fas fa-fire me-2"></i>Lo más vendido</a>
            <a href="tienda.php?oferta=1" class="btn btn-outline-light rounded-pill px-4 fw-semibold"><i class="fas fa-tags me-2"></i>Ver ofertas</a>
        </div>
    </section>

    <section class="mb-4" aria-label="Categorías">
        <div class="row g-3">
            <?php foreach ($categorias as $c): ?>
            <div class="col-6 col-md-4 col-xl">
                <a href="tienda.php?categoria=<?= esc(urlencode($c['slug'])) ?>" class="chip-categoria">
                    <i class="fas <?= esc($c['icono']) ?>"></i>
                    <span><b><?= esc($c['nombre']) ?></b><small><?= (int) $c['productos'] ?> producto(s)</small></span>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="banner-saludable mb-4" id="bannerBienestar" aria-label="Bienestar y vida saludable">
        <div>
            <span class="badge rounded-pill text-bg-light text-success mb-2"><i class="fas fa-leaf me-1"></i>Nuevo</span>
            <h2>Bienestar y vida saludable</h2>
            <p>Productos bioenergéticos y naturales para cuidar tu energía y la de tu equipo: café con ganoderma, colágeno, espirulina, té verde, bebidas naturales y más.</p>
        </div>
        <a href="tienda.php?categoria=bienestar-saludable" class="btn"><i class="fas fa-seedling me-2"></i>Ver productos saludables</a>
    </section>

    <?php if ($destacados): ?>
    <section class="mb-5" aria-labelledby="tituloDestacados">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <h2 class="h4 fw-bold mb-0" id="tituloDestacados"><i class="fas fa-star text-warning me-2"></i>Destacados</h2>
        </div>
        <div class="rejilla-productos"><?php foreach ($destacados as $p) echo tarjetaProductoTienda($p); ?></div>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <div class="row g-4" id="catalogo">
        <aside class="col-lg-3">
            <button class="btn btn-outline-secondary w-100 d-lg-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#panelFiltros" aria-expanded="false" aria-controls="panelFiltros">
                <i class="fas fa-sliders me-2"></i>Filtros<?= $filtrando ? ' (activos)' : '' ?>
            </button>
            <form class="tienda-filtros collapse d-lg-block" id="panelFiltros" method="get" action="tienda.php">
                <?php if ($filtros['q'] !== ''): ?><input type="hidden" name="q" value="<?= esc($filtros['q']) ?>"><?php endif; ?>
                <?php if ($filtros['orden'] !== 'relevancia'): ?><input type="hidden" name="orden" value="<?= esc($filtros['orden']) ?>"><?php endif; ?>
                <h6><i class="fas fa-layer-group me-1"></i>Categoría</h6>
                <div class="form-check"><input class="form-check-input" type="radio" name="categoria" id="cat_todas" value="" <?= $filtros['categoria'] === '' ? 'checked' : '' ?>><label class="form-check-label" for="cat_todas">Todas</label></div>
                <?php foreach ($categorias as $c): ?>
                <div class="form-check"><input class="form-check-input" type="radio" name="categoria" id="cat_<?= (int) $c['id'] ?>" value="<?= esc($c['slug']) ?>" <?= $filtros['categoria'] === $c['slug'] ? 'checked' : '' ?>>
                    <label class="form-check-label d-flex justify-content-between" for="cat_<?= (int) $c['id'] ?>"><?= esc($c['nombre']) ?> <span class="text-muted small ms-2"><?= (int) $c['productos'] ?></span></label></div>
                <?php endforeach; ?>
                <h6><i class="fas fa-cube me-1"></i>Tipo</h6>
                <select name="tipo" class="form-select form-select-sm" aria-label="Tipo de producto">
                    <option value="">Todos los tipos</option>
                    <?php foreach (TIPOS_PRODUCTO as $tipo => $icono): ?><option value="<?= esc($tipo) ?>" <?= $filtros['tipo'] === $tipo ? 'selected' : '' ?>><?= esc($tipo) ?></option><?php endforeach; ?>
                </select>
                <h6><i class="fas fa-dollar-sign me-1"></i>Precio (COP)</h6>
                <div class="d-flex gap-2">
                    <input type="number" name="min" min="0" step="any" class="form-control form-control-sm" placeholder="Mínimo" value="<?= esc($filtros['min']) ?>" aria-label="Precio mínimo">
                    <input type="number" name="max" min="0" step="any" class="form-control form-control-sm" placeholder="Máximo" value="<?= esc($filtros['max']) ?>" aria-label="Precio máximo">
                </div>
                <h6><i class="fas fa-filter me-1"></i>Mostrar</h6>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="oferta" value="1" id="f_oferta" <?= $filtros['oferta'] ? 'checked' : '' ?>><label class="form-check-label" for="f_oferta">Solo ofertas</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="disponibles" value="1" id="f_disponibles" <?= $filtros['disponibles'] ? 'checked' : '' ?>><label class="form-check-label" for="f_disponibles">Solo disponibles</label></div>
                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-carrito btn-sm"><i class="fas fa-check me-1"></i>Aplicar filtros</button>
                    <?php if ($filtrando): ?><a href="tienda.php" class="btn btn-link btn-sm text-secondary">Limpiar filtros</a><?php endif; ?>
                </div>
            </form>
        </aside>

        <section class="col-lg-9" aria-live="polite">
            <div class="barra-resultados">
                <div>
                    <h2 class="h5 fw-bold mb-1"><?= esc($filtrando ? $titulo : 'Todos los productos') ?></h2>
                    <div class="d-flex flex-wrap gap-2 align-items-center small text-muted">
                        <span id="totalResultados"><?= $total ?> producto(s)</span>
                        <?php if ($filtros['q'] !== ''): ?><a class="filtro-activo" href="<?= esc($enlace(['q' => ''])) ?>">«<?= esc($filtros['q']) ?>» <i class="fas fa-xmark"></i></a><?php endif; ?>
                        <?php if ($categoria_actual): ?><a class="filtro-activo" href="<?= esc($enlace(['categoria' => ''])) ?>"><?= esc($categoria_actual['nombre']) ?> <i class="fas fa-xmark"></i></a><?php endif; ?>
                        <?php if ($filtros['tipo'] !== ''): ?><a class="filtro-activo" href="<?= esc($enlace(['tipo' => ''])) ?>"><?= esc($filtros['tipo']) ?> <i class="fas fa-xmark"></i></a><?php endif; ?>
                        <?php if ($filtros['oferta']): ?><a class="filtro-activo" href="<?= esc($enlace(['oferta' => ''])) ?>">Ofertas <i class="fas fa-xmark"></i></a><?php endif; ?>
                    </div>
                </div>
                <label class="d-flex align-items-center gap-2 small text-muted mb-0">Ordenar por
                    <select class="form-select form-select-sm" id="ordenCatalogo" onchange="window.location.href = this.value">
                        <?php foreach (ORDENES_TIENDA as $clave => $nombre): ?>
                            <option value="<?= esc($enlace(['orden' => $clave === 'relevancia' ? '' : $clave])) ?>" <?= $filtros['orden'] === $clave ? 'selected' : '' ?>><?= esc($nombre) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <?php if ($visibles): ?>
                <div class="rejilla-productos" id="rejillaCatalogo"><?php foreach ($visibles as $p) echo tarjetaProductoTienda($p); ?></div>
                <?php if ($paginas > 1): ?>
                <nav class="mt-4" aria-label="Páginas del catálogo">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $paginas; $i++): ?>
                            <li class="page-item <?= $i === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= esc($enlace(['pagina' => $i > 1 ? $i : ''])) ?>#catalogo"><?= $i ?></a></li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="vacio-tienda">
                    <i class="fas fa-magnifying-glass d-block"></i>
                    <p class="fw-bold mb-1">No encontramos productos con esos filtros</p>
                    <p>Prueba con otra búsqueda o revisa todas las categorías.</p>
                    <a href="tienda.php" class="btn btn-carrito">Ver toda la tienda</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>
<?php pieTienda($db); ?>
