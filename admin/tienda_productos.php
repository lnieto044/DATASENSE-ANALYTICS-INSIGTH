<?php
/**
 * TIENDA - PRODUCTOS, CATEGORÍAS Y CUPONES (Administrador y rol con el módulo "tienda")
 * Catálogo con precio final (IVA incluido), oferta, inventario, imagen o portada con icono y color, destacados y estado;
 * categorías de la tienda y cupones de descuento con mínimo de compra, límite de usos y vencimiento.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/tienda.php';
$db = require __DIR__ . '/../config/database.php';

$usuario = requiereAcceso($db, 'tienda');
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario['id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = 'Productos y Cupones';
$subtitulo = 'Catálogo, inventario, categorías y descuentos de la tienda en línea';

$volver = function ($tipo, $texto, $pestana = 'productos') {
    avisoSiguiente($tipo, $texto);
    header('Location: tienda_productos.php?tab=' . $pestana);
    exit;
};
$slugUnico = function ($tabla, $texto, $id) use ($db) {
    $base = slugTienda($texto) ?: 'item';
    $slug = $base;
    for ($n = 2; ; $n++) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM $tabla WHERE slug = ? AND id <> ?");
        $stmt->execute([$slug, (int) $id]);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $n;
    }
};
$numero = function ($campo) { $v = trim((string) ($_POST[$campo] ?? '')); return $v === '' ? null : filter_var($v, FILTER_VALIDATE_FLOAT); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'guardar_producto') {
        $id = (int) ($_POST['id'] ?? 0);
        $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo = (string) ($_POST['tipo'] ?? '');
        $precio = $numero('precio');
        $anterior = $numero('precio_anterior');
        $stock_texto = trim((string) ($_POST['stock'] ?? ''));
        $stock = $stock_texto === '' ? null : filter_var($stock_texto, FILTER_VALIDATE_INT);
        $categoria = (int) ($_POST['categoria_id'] ?? 0);
        $icono = preg_match('/^fa-[a-z0-9-]{2,36}$/', (string) ($_POST['icono'] ?? '')) ? $_POST['icono'] : 'fa-box';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#0A2E4F';
        $estado = ($_POST['estado'] ?? '') === 'Inactivo' ? 'Inactivo' : 'Activo';

        $error = null;
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{2,29}$/', $sku)) $error = 'El SKU debe tener de 3 a 30 letras, números o guiones.';
        elseif (mb_strlen($nombre) < 3) $error = 'Escribe el nombre del producto.';
        elseif (!isset(TIPOS_PRODUCTO[$tipo])) $error = 'Elige el tipo de producto.';
        elseif ($precio === null || $precio === false || $precio <= 0) $error = 'El precio debe ser mayor que cero.';
        elseif ($anterior === false || ($anterior !== null && $anterior <= $precio)) $error = 'El precio anterior (oferta) debe ser mayor que el precio actual, o déjalo vacío.';
        elseif ($stock === false || ($stock !== null && $stock < 0)) $error = 'El inventario debe ser un número entero (vacío = sin límite).';
        else {
            $repetido = $db->prepare("SELECT COUNT(*) FROM tienda_productos WHERE sku = ? AND id <> ?");
            $repetido->execute([$sku, $id]);
            if ($repetido->fetchColumn()) $error = "Ya existe otro producto con el SKU $sku.";
        }
        if ($error) $volver('danger', 'No se guardó el producto: ' . $error);

        $actual = null;
        if ($id) {
            $stmt = $db->prepare("SELECT imagen FROM tienda_productos WHERE id = ?");
            $stmt->execute([$id]);
            $actual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$actual) $volver('danger', 'El producto no existe.');
        }
        list($imagen_nueva, $error_imagen) = guardarImagenProducto($_FILES['imagen'] ?? []);
        if ($error_imagen) $volver('danger', 'No se guardó el producto: ' . $error_imagen);
        $imagen = $actual ? $actual['imagen'] : null;
        if ($imagen_nueva || !empty($_POST['quitar_imagen'])) {
            if ($imagen && preg_match('/^prod_[a-f0-9]{16}\.(jpg|png|webp)$/', $imagen)) @unlink(__DIR__ . '/../img/productos/' . $imagen);
            $imagen = $imagen_nueva;
        }

        $valores = [$categoria ?: null, $sku, $nombre, $slugUnico('tienda_productos', $nombre, $id), mb_substr(trim((string) ($_POST['resumen'] ?? '')), 0, 255),
                    trim((string) ($_POST['descripcion'] ?? '')), trim((string) ($_POST['incluye'] ?? '')), $tipo, round($precio, 2), $anterior !== null ? round($anterior, 2) : null,
                    $stock, $tipo === 'Físico' ? 1 : 0, $icono, $color, $imagen, empty($_POST['destacado']) ? 0 : 1, $estado];
        if ($id) {
            $db->prepare("UPDATE tienda_productos SET categoria_id = ?, sku = ?, nombre = ?, slug = ?, resumen = ?, descripcion = ?, incluye = ?, tipo = ?, precio = ?, precio_anterior = ?,
                          stock = ?, requiere_envio = ?, icono = ?, color = ?, imagen = ?, destacado = ?, estado = ?, updated_at = NOW() WHERE id = ?")->execute(array_merge($valores, [$id]));
            $volver('success', "Producto «{$nombre}» actualizado.");
        }
        $db->prepare("INSERT INTO tienda_productos (categoria_id, sku, nombre, slug, resumen, descripcion, incluye, tipo, precio, precio_anterior, stock, requiere_envio, icono, color, imagen, destacado, estado)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($valores);
        $volver('success', "Producto «{$nombre}» creado" . ($estado === 'Activo' ? ' y publicado en la tienda.' : ' (inactivo: no se muestra en la tienda).'));
    }

    if ($accion === 'estado_producto') {
        $db->prepare("UPDATE tienda_productos SET estado = IF(estado = 'Activo', 'Inactivo', 'Activo'), updated_at = NOW() WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
        $volver('success', 'Estado del producto actualizado.');
    }

    if ($accion === 'guardar_categoria') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        if (mb_strlen($nombre) < 3) $volver('danger', 'Escribe el nombre de la categoría.', 'categorias');
        $icono = preg_match('/^fa-[a-z0-9-]{2,36}$/', (string) ($_POST['icono'] ?? '')) ? $_POST['icono'] : 'fa-tag';
        $valores = [mb_substr($nombre, 0, 80), $slugUnico('tienda_categorias', $nombre, $id), $icono, (int) ($_POST['orden'] ?? 0), empty($_POST['activa']) ? 0 : 1];
        if ($id) $db->prepare("UPDATE tienda_categorias SET nombre = ?, slug = ?, icono = ?, orden = ?, activa = ? WHERE id = ?")->execute(array_merge($valores, [$id]));
        else $db->prepare("INSERT INTO tienda_categorias (nombre, slug, icono, orden, activa) VALUES (?, ?, ?, ?, ?)")->execute($valores);
        $volver('success', "Categoría «{$nombre}» guardada.", 'categorias');
    }

    if ($accion === 'guardar_cupon') {
        $id = (int) ($_POST['id'] ?? 0);
        $codigo = strtoupper(trim((string) ($_POST['codigo'] ?? '')));
        $tipo = ($_POST['tipo'] ?? '') === 'Valor' ? 'Valor' : 'Porcentaje';
        $valor = $numero('valor');
        $minimo = $numero('minimo_compra');
        $usos_texto = trim((string) ($_POST['usos_maximos'] ?? ''));
        $usos = $usos_texto === '' ? null : filter_var($usos_texto, FILTER_VALIDATE_INT);
        $vence = trim((string) ($_POST['vence'] ?? ''));
        $error = null;
        if (!preg_match('/^[A-Z0-9]{3,30}$/', $codigo)) $error = 'El código debe tener de 3 a 30 letras o números, sin espacios.';
        elseif ($valor === null || $valor === false || $valor <= 0 || ($tipo === 'Porcentaje' && $valor > 90)) $error = 'El descuento debe ser mayor que cero (máximo 90 %).';
        elseif ($minimo === false || ($minimo !== null && $minimo < 0)) $error = 'El mínimo de compra no es válido.';
        elseif ($usos === false || ($usos !== null && $usos < 1)) $error = 'El límite de usos debe ser 1 o más (vacío = sin límite).';
        elseif ($vence !== '' && !fechaValida($vence)) $error = 'La fecha de vencimiento no es válida.';
        else {
            $repetido = $db->prepare("SELECT COUNT(*) FROM tienda_cupones WHERE codigo = ? AND id <> ?");
            $repetido->execute([$codigo, $id]);
            if ($repetido->fetchColumn()) $error = "Ya existe el cupón $codigo.";
        }
        if ($error) $volver('danger', 'No se guardó el cupón: ' . $error, 'cupones');
        $valores = [$codigo, mb_substr(trim((string) ($_POST['descripcion'] ?? '')), 0, 150), $tipo, round($valor, 2), round((float) $minimo, 2), $usos, $vence ?: null, empty($_POST['activo']) ? 0 : 1];
        if ($id) $db->prepare("UPDATE tienda_cupones SET codigo = ?, descripcion = ?, tipo = ?, valor = ?, minimo_compra = ?, usos_maximos = ?, vence = ?, activo = ? WHERE id = ?")->execute(array_merge($valores, [$id]));
        else $db->prepare("INSERT INTO tienda_cupones (codigo, descripcion, tipo, valor, minimo_compra, usos_maximos, vence, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute($valores);
        $volver('success', "Cupón $codigo guardado.", 'cupones');
    }
}

$productos = productosTienda($db, ['incluir_inactivos' => 1, 'orden' => 'nuevos']);
$categorias = categoriasTienda($db, false);
$cupones = $db->query("SELECT * FROM tienda_cupones ORDER BY activo DESC, codigo")->fetchAll(PDO::FETCH_ASSOC);
$pestana = in_array($_GET['tab'] ?? '', ['productos', 'categorias', 'cupones'], true) ? $_GET['tab'] : 'productos';
$aviso = tomarAviso();
$kpi = ['activos' => 0, 'agotados' => 0, 'bajo' => 0, 'valor_inventario' => 0];
foreach ($productos as $p) {
    if ($p['estado'] !== 'Activo') continue;
    $kpi['activos']++;
    if ($p['stock'] !== null && (int) $p['stock'] === 0) $kpi['agotados']++;
    if ($p['stock'] !== null && (int) $p['stock'] > 0 && (int) $p['stock'] <= 5) $kpi['bajo']++;
    if ($p['stock'] !== null) $kpi['valor_inventario'] += (float) $p['precio'] * (int) $p['stock'];
}
$productos_js = [];
foreach ($productos as $p) {
    $productos_js[(int) $p['id']] = array_intersect_key($p, array_flip(['id', 'categoria_id', 'sku', 'nombre', 'resumen', 'descripcion', 'incluye', 'tipo', 'precio', 'precio_anterior', 'stock', 'icono', 'color', 'imagen', 'destacado', 'estado', 'slug']));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Productos y Cupones | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/tienda.css?v=20260915">
    <style>
        .kpi-tienda { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; height: 100%; display: flex; gap: 12px; align-items: center; }
        .kpi-tienda .icono { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .kpi-tienda small { display: block; font-size: .7rem; text-transform: uppercase; font-weight: 700; color: #64748b; }
        .kpi-tienda b { font-size: 1.25rem; color: #0f172a; }
        .tabla-tienda th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .tabla-tienda td { vertical-align: middle; font-size: .88rem; }
        .miniatura { width: 52px; flex-shrink: 0; }
        .miniatura .producto-media { border-radius: 10px; font-size: 1.2rem; aspect-ratio: 1; }
        #vistaPrevia .producto-media { border-radius: 14px; font-size: 3rem; }
        body.tienda-admin .main-content { background: #f5f7fb; }
    </style>
</head>
<body class="tienda-admin">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show shadow-sm border-0" role="alert" id="avisoProductos"><?= esc($aviso['texto']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3"><div class="kpi-tienda"><span class="icono bg-success bg-opacity-10 text-success"><i class="fas fa-store"></i></span><div><small>Productos publicados</small><b><?= $kpi['activos'] ?></b></div></div></div>
            <div class="col-6 col-xl-3"><div class="kpi-tienda"><span class="icono bg-danger bg-opacity-10 text-danger"><i class="fas fa-ban"></i></span><div><small>Agotados</small><b><?= $kpi['agotados'] ?></b></div></div></div>
            <div class="col-6 col-xl-3"><div class="kpi-tienda"><span class="icono bg-warning bg-opacity-10 text-warning"><i class="fas fa-triangle-exclamation"></i></span><div><small>Stock bajo (≤ 5)</small><b><?= $kpi['bajo'] ?></b></div></div></div>
            <div class="col-6 col-xl-3"><div class="kpi-tienda"><span class="icono bg-primary bg-opacity-10 text-primary"><i class="fas fa-warehouse"></i></span><div><small>Inventario a precio de venta</small><b><?= dinero($kpi['valor_inventario']) ?></b></div></div></div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <ul class="nav nav-pills" role="tablist">
                    <li class="nav-item"><a class="nav-link <?= $pestana === 'productos' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab_productos" id="pestanaProductos"><i class="fas fa-box me-1"></i>Productos <span class="badge text-bg-light"><?= count($productos) ?></span></a></li>
                    <li class="nav-item"><a class="nav-link <?= $pestana === 'categorias' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab_categorias"><i class="fas fa-layer-group me-1"></i>Categorías</a></li>
                    <li class="nav-item"><a class="nav-link <?= $pestana === 'cupones' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab_cupones"><i class="fas fa-ticket me-1"></i>Cupones</a></li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="../tienda.php" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="fas fa-arrow-up-right-from-square me-1"></i>Ver tienda</a>
                    <a href="tienda_pedidos.php" class="btn btn-sm btn-outline-primary rounded-pill"><i class="fas fa-truck-ramp-box me-1"></i>Pedidos</a>
                </div>
            </div>
            <div class="card-body tab-content">
                <div class="tab-pane fade <?= $pestana === 'productos' ? 'show active' : '' ?>" id="tab_productos">
                    <div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
                        <input type="search" id="buscarProducto" class="form-control form-control-sm rounded-pill" style="max-width:320px" placeholder="Buscar por nombre, SKU o categoría" aria-label="Buscar productos">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" id="btnNuevoProducto"><i class="fas fa-plus me-1"></i>Nuevo producto</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover tabla-tienda mb-0" id="tablaProductosTienda">
                            <thead><tr><th>Producto</th><th>Categoría</th><th class="text-end">Precio</th><th class="text-center">Inventario</th><th class="text-center">Vendidos</th><th>Calificación</th><th>Estado</th><th class="text-center">Acciones</th></tr></thead>
                            <tbody>
                            <?php foreach ($productos as $p): list($texto_stock, $clase_stock) = stockProducto($p); ?>
                                <tr data-busqueda="<?= esc(mb_strtolower($p['nombre'] . ' ' . $p['sku'] . ' ' . $p['categoria'], 'UTF-8')) ?>" data-sku="<?= esc($p['sku']) ?>">
                                    <td><div class="d-flex gap-2 align-items-center"><div class="miniatura"><?= imagenProductoTienda($p, '../') ?></div><div><b><?= esc($p['nombre']) ?></b><div class="small text-muted"><?= esc($p['sku']) ?> · <?= esc($p['tipo']) ?><?= (int) $p['destacado'] ? ' · <i class="fas fa-star text-warning"></i>' : '' ?></div></div></div></td>
                                    <td class="small"><?= esc($p['categoria'] ?: 'Sin categoría') ?></td>
                                    <td class="text-end text-nowrap"><b><?= dinero($p['precio']) ?></b><?php if (descuentoProducto($p)): ?><div class="small text-muted text-decoration-line-through"><?= dinero($p['precio_anterior']) ?></div><?php endif; ?></td>
                                    <td class="text-center"><span class="badge text-bg-<?= $clase_stock ?> bg-opacity-75"><?= $p['stock'] === null ? 'Sin límite' : (int) $p['stock'] ?></span></td>
                                    <td class="text-center"><?= (int) $p['vendidos'] ?></td>
                                    <td class="small"><?= (int) $p['resenas'] ? estrellasTienda($p['calificacion']) . ' (' . (int) $p['resenas'] . ')' : '<span class="text-muted">Sin opiniones</span>' ?></td>
                                    <td>
                                        <form method="post" class="d-inline"><input type="hidden" name="accion" value="estado_producto"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="badge border-0 rounded-pill text-bg-<?= $p['estado'] === 'Activo' ? 'success' : 'secondary' ?>" title="Cambiar a <?= $p['estado'] === 'Activo' ? 'Inactivo' : 'Activo' ?>"><?= esc($p['estado']) ?></button></form>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button type="button" class="btn btn-sm btn-light border" data-editar-producto="<?= (int) $p['id'] ?>" aria-label="Editar <?= esc($p['nombre']) ?>"><i class="fas fa-pen"></i></button>
                                        <a href="../producto.php?p=<?= esc(urlencode($p['slug'])) ?>" target="_blank" class="btn btn-sm btn-light border" title="Ver en la tienda"><i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$productos): ?><tr><td colspan="8" class="text-center text-muted py-5">Aún no hay productos. Crea el primero con «Nuevo producto».</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade <?= $pestana === 'categorias' ? 'show active' : '' ?>" id="tab_categorias">
                    <div class="text-end mb-3"><button type="button" class="btn btn-sm btn-success rounded-pill px-3" data-editar-categoria="0"><i class="fas fa-plus me-1"></i>Nueva categoría</button></div>
                    <div class="table-responsive">
                        <table class="table tabla-tienda mb-0" id="tablaCategoriasTienda">
                            <thead><tr><th>Categoría</th><th>Enlace</th><th class="text-center">Productos activos</th><th class="text-center">Orden</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($categorias as $c): ?>
                                <tr><td><i class="fas <?= esc($c['icono']) ?> me-2 text-primary"></i><b><?= esc($c['nombre']) ?></b></td><td class="small text-muted">tienda.php?categoria=<?= esc($c['slug']) ?></td>
                                    <td class="text-center"><?= (int) $c['productos'] ?></td><td class="text-center"><?= (int) $c['orden'] ?></td>
                                    <td><span class="badge rounded-pill text-bg-<?= (int) $c['activa'] ? 'success' : 'secondary' ?>"><?= (int) $c['activa'] ? 'Visible' : 'Oculta' ?></span></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-light border" data-editar-categoria="<?= (int) $c['id'] ?>" data-datos="<?= esc(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade <?= $pestana === 'cupones' ? 'show active' : '' ?>" id="tab_cupones">
                    <div class="text-end mb-3"><button type="button" class="btn btn-sm btn-success rounded-pill px-3" data-editar-cupon="0" id="btnNuevoCupon"><i class="fas fa-plus me-1"></i>Nuevo cupón</button></div>
                    <div class="table-responsive">
                        <table class="table tabla-tienda mb-0" id="tablaCuponesTienda">
                            <thead><tr><th>Código</th><th>Descuento</th><th class="text-end">Compra mínima</th><th class="text-center">Usos</th><th>Vence</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($cupones as $c): $vencido = $c['vence'] && $c['vence'] < date('Y-m-d'); ?>
                                <tr data-cupon="<?= esc($c['codigo']) ?>"><td><b class="font-monospace"><?= esc($c['codigo']) ?></b><div class="small text-muted"><?= esc($c['descripcion']) ?></div></td>
                                    <td><?= $c['tipo'] === 'Valor' ? dinero($c['valor']) : rtrim(rtrim(number_format((float) $c['valor'], 2, ',', ''), '0'), ',') . ' %' ?></td>
                                    <td class="text-end"><?= dinero($c['minimo_compra']) ?></td>
                                    <td class="text-center"><?= (int) $c['usos'] ?><?= $c['usos_maximos'] !== null ? ' / ' . (int) $c['usos_maximos'] : '' ?></td>
                                    <td class="small <?= $vencido ? 'text-danger' : '' ?>"><?= $c['vence'] ? date('d/m/Y', strtotime($c['vence'])) . ($vencido ? ' (vencido)' : '') : 'Sin vencimiento' ?></td>
                                    <td><span class="badge rounded-pill text-bg-<?= (int) $c['activo'] && !$vencido ? 'success' : 'secondary' ?>"><?= (int) $c['activo'] ? ($vencido ? 'Vencido' : 'Activo') : 'Inactivo' ?></span></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-light border" data-editar-cupon="<?= (int) $c['id'] ?>" data-datos="<?= esc(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$cupones): ?><tr><td colspan="7" class="text-center text-muted py-4">No hay cupones.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalProducto" tabindex="-1" aria-labelledby="tituloModalProducto">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <form class="modal-content border-0" method="post" enctype="multipart/form-data" id="formProducto">
            <input type="hidden" name="accion" value="guardar_producto">
            <input type="hidden" name="id" id="prod_id" value="0">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="tituloModalProducto">Nuevo producto</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="row g-3">
                            <div class="col-md-8"><label class="form-label small fw-semibold" for="prod_nombre">Nombre *</label><input type="text" name="nombre" id="prod_nombre" class="form-control" maxlength="150" required></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_sku">SKU *</label><input type="text" name="sku" id="prod_sku" class="form-control text-uppercase font-monospace" maxlength="30" required placeholder="PBI-VEN-01"></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_tipo">Tipo *</label><select name="tipo" id="prod_tipo" class="form-select"><?php foreach (TIPOS_PRODUCTO as $tipo => $icono): ?><option><?= esc($tipo) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_categoria">Categoría</label><select name="categoria_id" id="prod_categoria" class="form-select"><option value="">Sin categoría</option><?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>"><?= esc($c['nombre']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_estado">Estado</label><select name="estado" id="prod_estado" class="form-select"><option>Activo</option><option>Inactivo</option></select></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_precio">Precio final (IVA incluido) *</label><div class="input-group"><span class="input-group-text">$</span><input type="number" name="precio" id="prod_precio" class="form-control" min="1" step="any" required></div></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_anterior">Precio antes de la oferta</label><div class="input-group"><span class="input-group-text">$</span><input type="number" name="precio_anterior" id="prod_anterior" class="form-control" min="1" step="any" placeholder="Opcional"></div></div>
                            <div class="col-md-4"><label class="form-label small fw-semibold" for="prod_stock">Inventario</label><input type="number" name="stock" id="prod_stock" class="form-control" min="0" step="1" placeholder="Vacío = sin límite"></div>
                            <div class="col-12"><label class="form-label small fw-semibold" for="prod_resumen">Resumen (tarjeta del catálogo)</label><input type="text" name="resumen" id="prod_resumen" class="form-control" maxlength="255"></div>
                            <div class="col-md-6"><label class="form-label small fw-semibold" for="prod_descripcion">Descripción</label><textarea name="descripcion" id="prod_descripcion" rows="5" class="form-control"></textarea></div>
                            <div class="col-md-6"><label class="form-label small fw-semibold" for="prod_incluye">Qué incluye (una línea por característica)</label><textarea name="incluye" id="prod_incluye" rows="5" class="form-control"></textarea></div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="small fw-semibold mb-1">Vista previa</div>
                        <div id="vistaPrevia" class="mb-3"></div>
                        <label class="form-label small fw-semibold" for="prod_imagen">Imagen (JPG, PNG o WEBP · máx. 2 MB)</label>
                        <input type="file" name="imagen" id="prod_imagen" class="form-control form-control-sm mb-2" accept="image/jpeg,image/png,image/webp">
                        <div class="form-check mb-3" id="bloqueQuitarImagen" hidden><input class="form-check-input" type="checkbox" name="quitar_imagen" value="1" id="prod_quitar_imagen"><label class="form-check-label small" for="prod_quitar_imagen">Quitar la imagen actual</label></div>
                        <div class="row g-2">
                            <div class="col-8"><label class="form-label small fw-semibold" for="prod_icono">Icono sin imagen</label><input type="text" name="icono" id="prod_icono" class="form-control form-control-sm font-monospace" value="fa-box" placeholder="fa-chart-line"></div>
                            <div class="col-4"><label class="form-label small fw-semibold" for="prod_color">Color</label><input type="color" name="color" id="prod_color" class="form-control form-control-color w-100" value="#0A2E4F"></div>
                        </div>
                        <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="destacado" value="1" id="prod_destacado"><label class="form-check-label small" for="prod_destacado">Destacado en la portada de la tienda</label></div>
                        <div class="small text-muted mt-3"><i class="fas fa-circle-info me-1"></i>Los productos «Físico» piden dirección de entrega y cobran envío (gratis desde <?= dinero(TIENDA_ENVIO_GRATIS_DESDE) ?>).</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success fw-bold px-4" id="btnGuardarProducto"><i class="fas fa-save me-1"></i>Guardar producto</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCategoria" tabindex="-1" aria-labelledby="tituloModalCategoria">
    <div class="modal-dialog">
        <form class="modal-content border-0" method="post">
            <input type="hidden" name="accion" value="guardar_categoria"><input type="hidden" name="id" id="cat_id" value="0">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="tituloModalCategoria">Categoría</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label class="form-label small fw-semibold" for="cat_nombre">Nombre *</label><input type="text" name="nombre" id="cat_nombre" class="form-control" maxlength="80" required></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cat_icono">Icono</label><input type="text" name="icono" id="cat_icono" class="form-control font-monospace" value="fa-tag"></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cat_orden">Orden</label><input type="number" name="orden" id="cat_orden" class="form-control" value="0"></div>
                <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="activa" value="1" id="cat_activa" checked><label class="form-check-label small" for="cat_activa">Visible en la tienda</label></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success fw-bold">Guardar categoría</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCupon" tabindex="-1" aria-labelledby="tituloModalCupon">
    <div class="modal-dialog">
        <form class="modal-content border-0" method="post">
            <input type="hidden" name="accion" value="guardar_cupon"><input type="hidden" name="id" id="cup_id" value="0">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="tituloModalCupon">Cupón de descuento</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label class="form-label small fw-semibold" for="cup_codigo">Código *</label><input type="text" name="codigo" id="cup_codigo" class="form-control text-uppercase font-monospace" maxlength="30" required></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cup_tipo">Tipo</label><select name="tipo" id="cup_tipo" class="form-select"><option value="Porcentaje">Porcentaje (%)</option><option value="Valor">Valor fijo ($)</option></select></div>
                <div class="col-12"><label class="form-label small fw-semibold" for="cup_descripcion">Descripción</label><input type="text" name="descripcion" id="cup_descripcion" class="form-control" maxlength="150"></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cup_valor">Descuento *</label><input type="number" name="valor" id="cup_valor" class="form-control" min="1" step="1" required></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cup_minimo">Compra mínima</label><input type="number" name="minimo_compra" id="cup_minimo" class="form-control" min="0" step="any" value="0"></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cup_usos">Límite de usos</label><input type="number" name="usos_maximos" id="cup_usos" class="form-control" min="1" placeholder="Sin límite"></div>
                <div class="col-6"><label class="form-label small fw-semibold" for="cup_vence">Vence</label><input type="date" name="vence" id="cup_vence" class="form-control"></div>
                <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="activo" value="1" id="cup_activo" checked><label class="form-check-label small" for="cup_activo">Activo</label></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success fw-bold" id="btnGuardarCupon">Guardar cupón</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PRODUCTOS = <?= jsJson($productos_js) ?>;
const campo = (id) => document.getElementById(id);

function vistaPrevia() {
    const icono = /^fa-[a-z0-9-]+$/.test(campo('prod_icono').value) ? campo('prod_icono').value : 'fa-box';
    const precio = Number(campo('prod_precio').value || 0).toLocaleString('es-CO');
    campo('vistaPrevia').innerHTML = `<div class="producto-card"><div class="producto-media" style="--color-producto:${campo('prod_color').value}"><i class="fas ${icono}"></i></div>
        <div class="producto-cuerpo"><div class="producto-nombre">${(campo('prod_nombre').value || 'Nombre del producto').replace(/[<>&]/g, '')}</div><span class="precio-actual">$ ${precio}</span></div></div>`;
}
['prod_icono', 'prod_color', 'prod_nombre', 'prod_precio'].forEach((id) => campo(id).addEventListener('input', vistaPrevia));

function abrirProducto(p) {
    campo('tituloModalProducto').innerText = p ? 'Editar producto' : 'Nuevo producto';
    campo('formProducto').reset();
    campo('prod_id').value = p ? p.id : 0;
    const valores = { prod_nombre: 'nombre', prod_sku: 'sku', prod_tipo: 'tipo', prod_categoria: 'categoria_id', prod_estado: 'estado', prod_precio: 'precio', prod_anterior: 'precio_anterior',
                      prod_stock: 'stock', prod_resumen: 'resumen', prod_descripcion: 'descripcion', prod_incluye: 'incluye', prod_icono: 'icono', prod_color: 'color' };
    Object.entries(valores).forEach(([id, clave]) => { if (p) campo(id).value = p[clave] === null ? '' : String(p[clave]).replace(/\.00$/, ''); });
    campo('prod_destacado').checked = !!(p && Number(p.destacado));
    campo('bloqueQuitarImagen').hidden = !(p && p.imagen);
    vistaPrevia();
    bootstrap.Modal.getOrCreateInstance(campo('modalProducto')).show();
}
campo('btnNuevoProducto').addEventListener('click', () => abrirProducto(null));
campo('buscarProducto').addEventListener('input', function () {
    const texto = this.value.trim().toLowerCase();
    document.querySelectorAll('#tablaProductosTienda tbody tr[data-busqueda]').forEach((fila) => { fila.hidden = texto !== '' && !fila.dataset.busqueda.includes(texto); });
});
document.addEventListener('click', function (e) {
    const producto = e.target.closest('[data-editar-producto]');
    if (producto) abrirProducto(PRODUCTOS[producto.dataset.editarProducto]);
    const categoria = e.target.closest('[data-editar-categoria]');
    if (categoria) {
        const c = categoria.dataset.datos ? JSON.parse(categoria.dataset.datos) : null;
        campo('cat_id').value = c ? c.id : 0;
        campo('cat_nombre').value = c ? c.nombre : '';
        campo('cat_icono').value = c ? c.icono : 'fa-tag';
        campo('cat_orden').value = c ? c.orden : 0;
        campo('cat_activa').checked = c ? Number(c.activa) === 1 : true;
        bootstrap.Modal.getOrCreateInstance(campo('modalCategoria')).show();
    }
    const cupon = e.target.closest('[data-editar-cupon]');
    if (cupon) {
        const c = cupon.dataset.datos ? JSON.parse(cupon.dataset.datos) : null;
        campo('cup_id').value = c ? c.id : 0;
        campo('cup_codigo').value = c ? c.codigo : '';
        campo('cup_tipo').value = c ? c.tipo : 'Porcentaje';
        campo('cup_descripcion').value = c ? c.descripcion : '';
        campo('cup_valor').value = c ? Number(c.valor) : '';
        campo('cup_minimo').value = c ? Number(c.minimo_compra) : 0;
        campo('cup_usos').value = c && c.usos_maximos !== null ? c.usos_maximos : '';
        campo('cup_vence').value = c && c.vence ? c.vence : '';
        campo('cup_activo').checked = c ? Number(c.activo) === 1 : true;
        bootstrap.Modal.getOrCreateInstance(campo('modalCupon')).show();
    }
});
</script>
</body>
</html>
