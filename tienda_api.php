<?php
/**
 * TIENDA - API DEL CARRITO (JSON)
 * GET  ?accion=resumen            Líneas y totales del carrito.
 * GET  ?accion=tarjetas&ids=1,2   Tarjetas HTML de productos (favoritos guardados en el navegador).
 * POST agregar | actualizar       producto_id, cantidad (agregar suma; actualizar fija; 0 quita).
 * POST cupon | quitar_cupon       codigo.
 * POST resena                     producto_id, calificacion, titulo, comentario (solo compradores con sesión).
 * Todo POST exige el token CSRF (cabecera X-CSRF-Token). Precios, inventario y cupones se validan siempre en el servidor.
 */
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/tienda.php';
require_once __DIR__ . '/includes/tienda_layout.php';

iniciarSesionSegura();
$db = require __DIR__ . '/config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderTienda(array $datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function resumenCarritoApi(PDO $db) {
    $lineas = lineasCarrito($db);
    $t = totalesTienda($db, $lineas, cuponSesion());
    return [
        'unidades' => $t['unidades'],
        'lineas' => array_map(function ($l) {
            $p = $l['producto'];
            return ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'url' => 'producto.php?p=' . urlencode($p['slug']), 'cantidad' => $l['cantidad'],
                    'maximo' => maximoCompraProducto($p), 'precio' => dinero($p['precio']), 'total' => dinero($l['total']), 'media' => imagenProductoTienda($p)];
        }, $lineas),
        'subtotal' => dinero($t['subtotal']),
        'descuento' => $t['descuento'] > 0 ? dinero($t['descuento']) : null,
        'envio' => $t['requiere_envio'] ? ($t['envio'] > 0 ? dinero($t['envio']) : 'Gratis') : null,
        'iva' => dinero($t['iva']),
        'total' => dinero($t['total']),
        'cupon' => cuponSesion() ?: null,
        'error_cupon' => $t['error_cupon'],
        'falta_envio_gratis' => $t['falta_envio_gratis'] > 0 ? dinero($t['falta_envio_gratis']) : null,
    ];
}

if (!tiendaDisponible($db)) responderTienda(['ok' => false, 'mensaje' => 'La tienda no está disponible en este momento.'], 503);

$accion = (string) ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['accion'] ?? '') : ($_GET['accion'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($accion === 'resumen') responderTienda(['ok' => true] + resumenCarritoApi($db));
    // Destacados para la página principal (se cargan desde el navegador: la portada no depende de la base de datos)
    if ($accion === 'destacados') {
        $productos = array_map(function ($p) {
            $imagen = (string) $p['imagen'];
            return [
                'nombre' => $p['nombre'], 'url' => 'producto.php?p=' . urlencode($p['slug']), 'categoria' => $p['categoria'] ?: $p['tipo'],
                'precio' => dinero($p['precio']), 'anterior' => descuentoProducto($p) ? dinero($p['precio_anterior']) : null, 'descuento' => descuentoProducto($p),
                'icono' => preg_match('/^fa-[a-z0-9-]+$/', (string) $p['icono']) ? $p['icono'] : 'fa-box',
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $p['color']) ? $p['color'] : '#0A2E4F',
                'imagen' => preg_match('/^prod_[a-f0-9]{16}\.(jpg|png|webp)$/', $imagen) && is_file(__DIR__ . '/img/productos/' . $imagen) ? 'img/productos/' . $imagen : null,
            ];
        }, destacadosVariados($db, 4, ['disponibles' => 1]));
        responderTienda(['ok' => true, 'productos' => $productos, 'unidades' => unidadesCarrito()]);
    }
    if ($accion === 'tarjetas') {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))), 0, 60);
        $html = '';
        foreach (productosTienda($db, ['ids' => $ids]) as $p) $html .= tarjetaProductoTienda($p);
        responderTienda(['ok' => true, 'html' => $html]);
    }
    responderTienda(['ok' => false, 'mensaje' => 'Acción no válida.'], 400);
}

if (!csrfValido()) responderTienda(['ok' => false, 'mensaje' => 'La página expiró. Recárgala e inténtalo de nuevo.'], 403);

switch ($accion) {
    case 'agregar':
    case 'actualizar':
        $cantidad = filter_var($_POST['cantidad'] ?? 1, FILTER_VALIDATE_INT);
        if ($cantidad === false || $cantidad < 0 || $cantidad > 999) responderTienda(['ok' => false, 'mensaje' => 'La cantidad no es válida.'], 422);
        list($ok, $mensaje) = ponerEnCarrito($db, (int) ($_POST['producto_id'] ?? 0), $cantidad, $accion === 'agregar');
        $resumen = resumenCarritoApi($db);
        responderTienda(['ok' => $ok, 'mensaje' => $mensaje, 'unidades' => $resumen['unidades'], 'resumen' => $resumen], $ok ? 200 : 422);

    case 'cupon':
        $codigo = strtoupper(trim((string) ($_POST['codigo'] ?? '')));
        $lineas = lineasCarrito($db);
        if (!$lineas) responderTienda(['ok' => false, 'mensaje' => 'Agrega productos antes de usar un cupón.'], 422);
        $subtotal = array_sum(array_column($lineas, 'total'));
        list($cupon, $error) = cuponTienda($db, $codigo, $subtotal);
        if (!$cupon) responderTienda(['ok' => false, 'mensaje' => $error ?: 'Escribe el código del cupón.'], 422);
        guardarCuponSesion($cupon['codigo']);
        responderTienda(['ok' => true, 'mensaje' => "Cupón {$cupon['codigo']} aplicado: " . ($cupon['descripcion'] ?: 'descuento en tu compra') . '.', 'resumen' => resumenCarritoApi($db)]);

    case 'quitar_cupon':
        guardarCuponSesion('');
        responderTienda(['ok' => true, 'mensaje' => 'Quitamos el cupón.', 'resumen' => resumenCarritoApi($db)]);

    case 'resena':
        $sesion = sesionTienda();
        if (!$sesion) responderTienda(['ok' => false, 'mensaje' => 'Inicia sesión para calificar el producto.'], 401);
        $error = guardarResena($db, $sesion['id'], (int) ($_POST['producto_id'] ?? 0), $_POST['calificacion'] ?? 0, $_POST['titulo'] ?? '', $_POST['comentario'] ?? '');
        responderTienda(['ok' => $error === null, 'mensaje' => $error ?: '¡Gracias! Tu opinión quedó publicada.'], $error ? 422 : 200);
}

responderTienda(['ok' => false, 'mensaje' => 'Acción no válida.'], 400);
