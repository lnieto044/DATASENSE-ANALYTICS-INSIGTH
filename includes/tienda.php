<?php
/**
 * TIENDA EN LÍNEA - MOTOR COMPARTIDO
 * Catálogo, carrito en la sesión, cupones, pedidos, inventario y reseñas para el sitio público (tienda.php, producto.php,
 * carrito.php, checkout.php, tienda_api.php) y el panel (tienda_productos.php, tienda_pedidos.php, mis_pedidos.php).
 * Los precios incluyen IVA. Cada pedido genera su factura de venta (consecutivo FAC) y se paga con los medios de
 * Mis Facturas; sincronizarPedidos() pasa el pedido a "Pagado" cuando la factura queda pagada.
 */
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/contabilidad.php';

const TIENDA_IVA = 19;
const TIENDA_ENVIO = 18000;
const TIENDA_ENVIO_GRATIS_DESDE = 300000;
const TIENDA_DIAS_PAGO = 3;
const TIENDA_MAX_UNIDADES = 20;
const TIENDA_IMAGEN_MAX_BYTES = 2097152;

const TIPOS_PRODUCTO = ['Digital' => 'fa-download', 'Curso' => 'fa-graduation-cap', 'Licencia' => 'fa-key', 'Servicio' => 'fa-handshake', 'Físico' => 'fa-truck'];
const ENTREGA_TIPO_PRODUCTO = [
    'Digital' => 'Descarga enviada a tu correo al confirmarse el pago',
    'Curso' => 'Acceso al aula virtual al confirmarse el pago',
    'Licencia' => 'Activación en 1 día hábil después del pago',
    'Servicio' => 'Un consultor te contacta en 1 día hábil para agendar',
    'Físico' => 'Envío a toda Colombia en 3 a 5 días hábiles',
];
const ESTADOS_PEDIDO = ['Pendiente de pago' => 'warning', 'Pagado' => 'primary', 'En preparación' => 'info', 'Enviado' => 'secondary', 'Entregado' => 'success', 'Cancelado' => 'dark'];
const TRANSICIONES_PEDIDO = [
    'Pendiente de pago' => ['Cancelado'],
    'Pagado' => ['En preparación', 'Entregado'],
    'En preparación' => ['Enviado', 'Entregado'],
    'Enviado' => ['Entregado'],
    'Entregado' => [],
    'Cancelado' => [],
];
const ORDENES_TIENDA = ['relevancia' => 'Relevancia', 'vendidos' => 'Más vendidos', 'precio_asc' => 'Precio: menor a mayor', 'precio_desc' => 'Precio: mayor a menor', 'nuevos' => 'Novedades', 'calificacion' => 'Mejor calificados'];

// La base aún no tiene la migración 7 (por ejemplo, el hosting antes de actualizarse): la página principal no debe romperse
function tiendaDisponible(PDO $db) {
    static $disponible = null;
    if ($disponible === null) {
        try {
            $disponible = $db->query("SELECT 1 FROM tienda_productos LIMIT 1") !== false;
        } catch (Exception $e) {
            $disponible = false;
        }
    }
    return $disponible;
}

function slugTienda($texto) {
    $texto = mb_strtolower(trim((string) $texto), 'UTF-8');
    $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    return trim(substr(preg_replace('/[^a-z0-9]+/', '-', $texto), 0, 160), '-');
}

// ================================================================
// CATÁLOGO
// ================================================================

function categoriasTienda(PDO $db, $solo_activas = true) {
    return $db->query("SELECT c.*, COUNT(p.id) AS productos FROM tienda_categorias c
                       LEFT JOIN tienda_productos p ON p.categoria_id = c.id AND p.estado = 'Activo'
                       " . ($solo_activas ? 'WHERE c.activa = 1 ' : '') . "GROUP BY c.id ORDER BY c.orden, c.nombre")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Productos con su categoría y calificación. Filtros: q, categoria (slug), tipo, min, max, oferta, destacados, disponibles,
 * slug, id, ids, excluir, orden (ORDENES_TIENDA), limite, incluir_inactivos.
 */
function productosTienda(PDO $db, array $f = []) {
    $where = [];
    $params = [];
    if (empty($f['incluir_inactivos'])) $where[] = "p.estado = 'Activo'";
    if (!empty($f['slug'])) { $where[] = 'p.slug = ?'; $params[] = (string) $f['slug']; }
    if (!empty($f['id'])) { $where[] = 'p.id = ?'; $params[] = (int) $f['id']; }
    if (isset($f['ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array) $f['ids'])));
        if (!$ids) return [];
        $where[] = 'p.id IN (' . implode(',', $ids) . ')';
    }
    if (!empty($f['categoria'])) { $where[] = 'c.slug = ?'; $params[] = (string) $f['categoria']; }
    if (!empty($f['tipo']) && isset(TIPOS_PRODUCTO[$f['tipo']])) { $where[] = 'p.tipo = ?'; $params[] = $f['tipo']; }
    if (isset($f['min']) && is_numeric($f['min']) && (float) $f['min'] > 0) { $where[] = 'p.precio >= ?'; $params[] = (float) $f['min']; }
    if (isset($f['max']) && is_numeric($f['max']) && (float) $f['max'] > 0) { $where[] = 'p.precio <= ?'; $params[] = (float) $f['max']; }
    if (!empty($f['oferta'])) $where[] = 'p.precio_anterior > p.precio';
    if (!empty($f['destacados'])) $where[] = 'p.destacado = 1';
    if (!empty($f['disponibles'])) $where[] = '(p.stock IS NULL OR p.stock > 0)';
    if (!empty($f['excluir'])) { $where[] = 'p.id <> ?'; $params[] = (int) $f['excluir']; }
    if (isset($f['q']) && trim((string) $f['q']) !== '') {
        $q = '%' . addcslashes(trim((string) $f['q']), '%_\\') . '%';
        $where[] = '(p.nombre LIKE ? OR p.resumen LIKE ? OR p.sku LIKE ? OR c.nombre LIKE ?)';
        array_push($params, $q, $q, $q, $q);
    }
    $ordenes = [
        'relevancia' => 'p.destacado DESC, p.vendidos DESC, p.id DESC', 'vendidos' => 'p.vendidos DESC, p.id DESC',
        'precio_asc' => 'p.precio ASC, p.id', 'precio_desc' => 'p.precio DESC, p.id', 'nuevos' => 'p.created_at DESC, p.id DESC',
        'calificacion' => 'calificacion DESC, resenas DESC, p.vendidos DESC',
    ];
    $orden = $ordenes[(string) ($f['orden'] ?? '')] ?? $ordenes['relevancia'];
    $stmt = $db->prepare("SELECT p.*, c.nombre AS categoria, c.slug AS categoria_slug, COALESCE(r.promedio, 0) AS calificacion, COALESCE(r.total, 0) AS resenas
                          FROM tienda_productos p
                          LEFT JOIN tienda_categorias c ON c.id = p.categoria_id
                          LEFT JOIN (SELECT producto_id, AVG(calificacion) AS promedio, COUNT(*) AS total FROM tienda_resenas GROUP BY producto_id) r ON r.producto_id = p.id"
                       . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $orden" . (!empty($f['limite']) ? ' LIMIT ' . (int) $f['limite'] : ''));
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function productoTienda(PDO $db, array $f) {
    $filas = productosTienda($db, $f + ['limite' => 1]);
    return $filas ? $filas[0] : null;
}

function descuentoProducto(array $p) {
    return (float) $p['precio_anterior'] > (float) $p['precio'] ? (int) round(100 - (float) $p['precio'] * 100 / (float) $p['precio_anterior']) : 0;
}

// Unidades que se pueden comprar de una vez (sin inventario: hasta TIENDA_MAX_UNIDADES)
function maximoCompraProducto(array $p) {
    return $p['stock'] === null ? TIENDA_MAX_UNIDADES : max(0, min(TIENDA_MAX_UNIDADES, (int) $p['stock']));
}

// [texto, clase de color] del inventario
function stockProducto(array $p) {
    if ($p['stock'] === null) return ['Disponible', 'success'];
    $stock = (int) $p['stock'];
    if ($stock <= 0) return ['Agotado', 'danger'];
    if ($stock <= 5) return ["¡Últimas $stock unidades!", 'warning'];
    return ["$stock disponibles", 'success'];
}

function estrellasTienda($promedio) {
    $promedio = (float) $promedio;
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $clase = $promedio >= $i - 0.25 ? 'fas fa-star' : ($promedio >= $i - 0.75 ? 'fas fa-star-half-stroke' : 'far fa-star');
        $html .= '<i class="' . $clase . '" aria-hidden="true"></i>';
    }
    return '<span class="estrellas" title="' . number_format($promedio, 1, ',', '') . ' de 5">' . $html . '</span>';
}

// Imagen del producto o, si no tiene, una portada con su icono y color. $prefijo: '' en la raíz, '../' en el panel
function imagenProductoTienda(array $p, $prefijo = '', $clase = 'producto-media') {
    $imagen = (string) ($p['imagen'] ?? '');
    if (preg_match('/^prod_[a-f0-9]{16}\.(jpg|png|webp)$/', $imagen) && is_file(__DIR__ . '/../img/productos/' . $imagen)) {
        $alt = trim((string) ($p['nombre'] ?? '')) ?: 'Producto';
        return '<div class="' . esc($clase) . ' con-imagen"><img src="' . esc($prefijo . 'img/productos/' . $imagen) . '" alt="' . esc($alt) . '" loading="lazy" decoding="async"></div>';
    }
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['color'] ?? '')) ? $p['color'] : '#0A2E4F';
    $icono = preg_match('/^fa-[a-z0-9-]+$/', (string) ($p['icono'] ?? '')) ? $p['icono'] : 'fa-box';
    return '<div class="' . esc($clase) . '" style="--color-producto:' . $color . '"><i class="fas ' . $icono . '" aria-hidden="true"></i></div>';
}

// Guarda la imagen subida en img/productos/ con nombre aleatorio. Devuelve [archivo|null, error|null]
function guardarImagenProducto(array $archivo) {
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) return [null, 'No se pudo recibir la imagen.'];
    if ($archivo['size'] > TIENDA_IMAGEN_MAX_BYTES) return [null, 'La imagen supera los 2 MB.'];
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $info = @getimagesize($archivo['tmp_name']);
    if (!$info || !isset($tipos[$info[2]])) return [null, 'La imagen debe ser JPG, PNG o WEBP.'];
    $extension = $tipos[$info[2]];
    if (class_exists('finfo') && (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']) !== $mimes[$extension]) return [null, 'El archivo no es una imagen válida.'];
    $carpeta = __DIR__ . '/../img/productos/';
    if (!is_dir($carpeta)) @mkdir($carpeta, 0755, true);
    $nombre = 'prod_' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($archivo['tmp_name'], $carpeta . $nombre)) return [null, 'No se pudo guardar la imagen.'];
    return [$nombre, null];
}

// ================================================================
// CARRITO (en la sesión: [producto_id => cantidad]) Y CUPONES
// ================================================================

function carritoSesion() {
    iniciarSesionSegura();
    $carrito = $_SESSION['tienda_carrito'] ?? [];
    return is_array($carrito) ? $carrito : [];
}

function guardarCarrito(array $carrito) {
    iniciarSesionSegura();
    $_SESSION['tienda_carrito'] = array_filter(array_map('intval', $carrito), function ($cantidad) { return $cantidad > 0; });
}

function unidadesCarrito() {
    return (int) array_sum(carritoSesion());
}

function cuponSesion() {
    iniciarSesionSegura();
    return (string) ($_SESSION['tienda_cupon'] ?? '');
}

function guardarCuponSesion($codigo) {
    iniciarSesionSegura();
    $_SESSION['tienda_cupon'] = strtoupper(trim((string) $codigo));
}

// Agrega ($sumar) o fija la cantidad de un producto respetando el inventario. Devuelve [ok, mensaje]
function ponerEnCarrito(PDO $db, $producto_id, $cantidad, $sumar = true) {
    $p = productoTienda($db, ['id' => (int) $producto_id]);
    if (!$p) return [false, 'El producto ya no está disponible.'];
    $carrito = carritoSesion();
    $nueva = $sumar ? (int) ($carrito[$p['id']] ?? 0) + (int) $cantidad : (int) $cantidad;
    if ($nueva <= 0) {
        unset($carrito[$p['id']]);
        guardarCarrito($carrito);
        return [true, 'Quitamos «' . $p['nombre'] . '» del carrito.'];
    }
    $maximo = maximoCompraProducto($p);
    if ($maximo <= 0) return [false, '«' . $p['nombre'] . '» está agotado.'];
    $mensaje = $sumar ? 'Agregaste «' . $p['nombre'] . '» al carrito.' : 'Cantidad actualizada.';
    if ($nueva > $maximo) {
        $nueva = $maximo;
        $mensaje = "Puedes llevar máximo $maximo unidad(es) de «{$p['nombre']}»: ajustamos la cantidad.";
    }
    $carrito[$p['id']] = $nueva;
    guardarCarrito($carrito);
    return [true, $mensaje];
}

// Líneas vigentes del carrito en el orden en que se agregaron; lo inactivo o agotado sale y lo que supera el inventario se ajusta
function lineasCarrito(PDO $db) {
    $carrito = carritoSesion();
    if (!$carrito) return [];
    $posicion = array_flip(array_map('intval', array_keys($carrito)));
    $lineas = [];
    foreach (productosTienda($db, ['ids' => array_keys($carrito)]) as $p) {
        $maximo = maximoCompraProducto($p);
        if ($maximo <= 0) continue;
        $pedida = (int) $carrito[$p['id']];
        $cantidad = min($pedida, $maximo);
        $lineas[] = ['producto' => $p, 'cantidad' => $cantidad, 'total' => round((float) $p['precio'] * $cantidad, 2),
                     'aviso' => $cantidad < $pedida ? "Ajustamos la cantidad a las $maximo unidad(es) disponibles." : ''];
    }
    usort($lineas, function ($a, $b) use ($posicion) { return $posicion[(int) $a['producto']['id']] <=> $posicion[(int) $b['producto']['id']]; });
    $vigente = [];
    foreach ($lineas as $l) $vigente[(int) $l['producto']['id']] = $l['cantidad'];
    if ($vigente != array_map('intval', $carrito)) guardarCarrito($vigente);
    return $lineas;
}

// [cupón|null, error|null]; $bloquear lo lee con FOR UPDATE (dentro de la transacción del pedido)
function cuponTienda(PDO $db, $codigo, $subtotal, $bloquear = false) {
    $codigo = strtoupper(trim((string) $codigo));
    if ($codigo === '') return [null, null];
    $stmt = $db->prepare("SELECT * FROM tienda_cupones WHERE codigo = ?" . ($bloquear ? ' FOR UPDATE' : ''));
    $stmt->execute([$codigo]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c || !(int) $c['activo']) return [null, "El cupón $codigo no existe o no está activo."];
    if ($c['vence'] && $c['vence'] < date('Y-m-d')) return [null, "El cupón $codigo ya venció."];
    if ($c['usos_maximos'] !== null && (int) $c['usos'] >= (int) $c['usos_maximos']) return [null, "El cupón $codigo ya alcanzó su límite de usos."];
    if ((float) $subtotal < (float) $c['minimo_compra']) return [null, "El cupón $codigo aplica para compras desde " . dinero($c['minimo_compra']) . '.'];
    return [$c, null];
}

/**
 * Totales del carrito. Los precios incluyen IVA: la base (subtotal de la factura) se obtiene del total y el IVA es la diferencia.
 * El envío aplica si hay productos físicos y lo comprado (con descuento) no llega a TIENDA_ENVIO_GRATIS_DESDE.
 */
function totalesTienda(PDO $db, array $lineas, $codigo_cupon = '', $bloquear = false) {
    $subtotal = 0;
    $unidades = 0;
    $requiere_envio = false;
    foreach ($lineas as $l) {
        $subtotal += $l['total'];
        $unidades += $l['cantidad'];
        if ((int) $l['producto']['requiere_envio'] === 1) $requiere_envio = true;
    }
    list($cupon, $error_cupon) = $lineas ? cuponTienda($db, $codigo_cupon, $subtotal, $bloquear) : [null, null];
    $descuento = 0;
    if ($cupon) $descuento = $cupon['tipo'] === 'Valor' ? min((float) $cupon['valor'], $subtotal) : round($subtotal * (float) $cupon['valor'] / 100);
    $neto = $subtotal - $descuento;
    $envio = $requiere_envio && $neto < TIENDA_ENVIO_GRATIS_DESDE ? TIENDA_ENVIO : 0;
    $base = round(($neto + $envio) / (1 + TIENDA_IVA / 100), 2);
    $total = round($base * (1 + TIENDA_IVA / 100), 2);
    return [
        'subtotal' => round($subtotal, 2), 'descuento' => round($descuento, 2), 'envio' => $envio, 'base' => $base, 'iva' => round($total - $base, 2),
        'total' => $total, 'unidades' => $unidades, 'requiere_envio' => $requiere_envio, 'cupon' => $cupon, 'error_cupon' => $error_cupon,
        'falta_envio_gratis' => $envio > 0 ? TIENDA_ENVIO_GRATIS_DESDE - $neto : 0,
    ];
}

// ================================================================
// PEDIDOS
// ================================================================

function historialPedidoTienda(PDO $db, $pedido_id, $usuario_id, $estado, $detalle) {
    $db->prepare("INSERT INTO tienda_pedido_historial (pedido_id, fecha, usuario_id, estado, detalle) VALUES (?, ?, ?, ?, ?)")
       ->execute([(int) $pedido_id, date('Y-m-d H:i:s'), $usuario_id ?: null, $estado, mb_substr((string) $detalle, 0, 500)]);
}

/**
 * Convierte el carrito en pedido: vuelve a leer precios e inventario con bloqueo, valida el cupón, crea la factura de venta
 * (consecutivo FAC, vence en TIENDA_DIAS_PAGO días), el pedido (consecutivo PED), sus líneas, descuenta el inventario y el uso
 * del cupón, todo en una transacción. $datos: documento, entrega_nombre, entrega_telefono, entrega_direccion, entrega_ciudad, notas.
 * Devuelve [pedido_id|null, error|null].
 */
function crearPedidoTienda(PDO $db, array $usuario, array $datos) {
    $lineas = lineasCarrito($db);
    if (!$lineas) return [null, 'Tu carrito está vacío.'];
    $texto = function ($campo, $largo) use ($datos) { return mb_substr(trim((string) ($datos[$campo] ?? '')), 0, $largo); };
    $entrega = [$texto('entrega_nombre', 150), $texto('entrega_telefono', 30), $texto('entrega_direccion', 200), $texto('entrega_ciudad', 80)];
    $requiere_envio = false;
    foreach ($lineas as $l) if ((int) $l['producto']['requiere_envio'] === 1) $requiere_envio = true;
    if ($requiere_envio && (mb_strlen($entrega[0]) < 3 || !preg_match('/^[0-9+()\s-]{7,20}$/', $entrega[1]) || mb_strlen($entrega[2]) < 5 || mb_strlen($entrega[3]) < 3)) {
        return [null, 'Completa los datos de entrega: nombre de quien recibe, teléfono, dirección y ciudad.'];
    }
    if (!$requiere_envio) $entrega = [null, null, null, null];
    $documento = normalizarDocumento($datos['documento'] ?? '');
    if ($documento !== '' && !documentoValido($documento)) return [null, 'El NIT o la cédula no tiene un formato válido.'];
    $hoy = date('Y-m-d');
    if (periodoCerrado($db, $hoy)) return [null, 'La facturación de este periodo está cerrada. Escríbenos y te ayudamos con tu compra.'];

    $anio = (int) date('Y');
    try {
        asegurarConsecutivo($db, 'FAC', $anio);
        asegurarConsecutivo($db, 'PED', $anio);
        $db->beginTransaction();
        $ids = array_map(function ($l) { return (int) $l['producto']['id']; }, $lineas);
        $bloqueados = [];
        foreach ($db->query("SELECT id, nombre, precio, stock, estado FROM tienda_productos WHERE id IN (" . implode(',', $ids) . ") FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $bloqueados[(int) $p['id']] = $p;
        }
        foreach ($lineas as $i => $l) {
            $p = $bloqueados[(int) $l['producto']['id']] ?? null;
            if (!$p || $p['estado'] !== 'Activo') throw new DomainException('«' . $l['producto']['nombre'] . '» ya no está disponible. Revisa tu carrito.');
            if ($p['stock'] !== null && (int) $p['stock'] < $l['cantidad']) throw new DomainException('Solo quedan ' . max(0, (int) $p['stock']) . ' unidad(es) de «' . $p['nombre'] . '». Revisa tu carrito.');
            $lineas[$i]['producto']['precio'] = $p['precio'];
            $lineas[$i]['total'] = round((float) $p['precio'] * $l['cantidad'], 2);
        }
        $t = totalesTienda($db, $lineas, cuponSesion(), true);
        if ($t['error_cupon']) throw new DomainException($t['error_cupon'] . ' Quítalo del carrito para continuar.');

        $existe_factura = $db->prepare("SELECT COUNT(*) FROM facturas WHERE numero_factura = ?");
        do {
            $numero_factura = siguienteConsecutivo($db, 'FAC', $anio);
            $existe_factura->execute([$numero_factura]);
        } while ($existe_factura->fetchColumn());
        $existe_pedido = $db->prepare("SELECT COUNT(*) FROM tienda_pedidos WHERE numero = ?");
        do {
            $numero = siguienteConsecutivo($db, 'PED', $anio);
            $existe_pedido->execute([$numero]);
        } while ($existe_pedido->fetchColumn());

        $db->prepare("INSERT INTO facturas (id_usuario, id_servicio, numero_factura, servicio_contratado, fecha_emision, fecha_vencimiento, subtotal, porcentaje_iva, monto_total, estado, visto)
                      VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', 1)")
           ->execute([(int) $usuario['id'], $numero_factura, mb_substr("Pedido $numero · Tienda en línea ({$t['unidades']} unidad(es))", 0, 200), $hoy,
                      date('Y-m-d', strtotime('+' . TIENDA_DIAS_PAGO . ' days')), $t['base'], TIENDA_IVA, $t['total']]);
        $factura_id = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO tienda_pedidos (numero, usuario_id, factura_id, estado, subtotal, descuento, envio, base, iva, total, cupon,
                      entrega_nombre, entrega_telefono, entrega_direccion, entrega_ciudad, notas, created_at)
                      VALUES (?, ?, ?, 'Pendiente de pago', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$numero, (int) $usuario['id'], $factura_id, $t['subtotal'], $t['descuento'], $t['envio'], $t['base'], $t['iva'], $t['total'],
                      $t['cupon'] ? $t['cupon']['codigo'] : null, $entrega[0], $entrega[1], $entrega[2], $entrega[3], $texto('notas', 500) ?: null, date('Y-m-d H:i:s')]);
        $pedido_id = (int) $db->lastInsertId();

        $item = $db->prepare("INSERT INTO tienda_pedido_items (pedido_id, producto_id, sku, nombre, tipo, precio_unitario, cantidad, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $inventario = $db->prepare("UPDATE tienda_productos SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL");
        foreach ($lineas as $l) {
            $p = $l['producto'];
            $item->execute([$pedido_id, (int) $p['id'], $p['sku'], $p['nombre'], $p['tipo'], $p['precio'], $l['cantidad'], $l['total']]);
            $inventario->execute([$l['cantidad'], (int) $p['id']]);
        }
        if ($t['cupon']) $db->prepare("UPDATE tienda_cupones SET usos = usos + 1 WHERE id = ?")->execute([(int) $t['cupon']['id']]);
        if ($documento !== '' && !terceroDeUsuario($db, $usuario['id'])) guardarTercero($db, $documento, $usuario['nombre'], 'Cliente', (int) $usuario['id']);
        historialPedidoTienda($db, $pedido_id, (int) $usuario['id'], 'Pendiente de pago',
            "Pedido creado en la tienda con $numero_factura por " . dinero($t['total']) . ($t['cupon'] ? " (cupón {$t['cupon']['codigo']})" : '') . '. Pendiente de pago.');
        $db->commit();
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        return [null, $e->getMessage()];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('tienda (crear pedido): ' . $e->getMessage());
        return [null, 'No fue posible registrar el pedido. Intenta de nuevo.'];
    }
    guardarCarrito([]);
    guardarCuponSesion('');
    correoPedidoTienda($db, $pedido_id);
    return [$pedido_id, null];
}

function restaurarInventarioPedido(PDO $db, array $pedido) {
    $db->prepare("UPDATE tienda_productos p JOIN (SELECT producto_id, SUM(cantidad) AS unidades FROM tienda_pedido_items WHERE pedido_id = ? GROUP BY producto_id) i ON i.producto_id = p.id
                  SET p.stock = p.stock + i.unidades WHERE p.stock IS NOT NULL")->execute([(int) $pedido['id']]);
    if (!empty($pedido['cupon'])) $db->prepare("UPDATE tienda_cupones SET usos = GREATEST(usos - 1, 0) WHERE codigo = ?")->execute([$pedido['cupon']]);
}

// Estado del pedido según su factura: pagada → "Pagado" (suma a vendidos); anulada → "Cancelado" (devuelve inventario)
function sincronizarPedidos(PDO $db) {
    if (!tiendaDisponible($db)) return;
    $cambios = $db->query("SELECT p.id FROM tienda_pedidos p JOIN facturas f ON f.id = p.factura_id
                           WHERE p.estado = 'Pendiente de pago' AND f.estado IN ('Pagado', 'Anulada')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cambios as $id) {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT p.*, f.estado AS estado_factura, f.numero_factura FROM tienda_pedidos p JOIN facturas f ON f.id = p.factura_id WHERE p.id = ? FOR UPDATE");
            $stmt->execute([(int) $id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($p && $p['estado'] === 'Pendiente de pago' && $p['estado_factura'] === 'Pagado') {
                $db->prepare("UPDATE tienda_pedidos SET estado = 'Pagado', updated_at = NOW() WHERE id = ?")->execute([(int) $id]);
                $db->prepare("UPDATE tienda_productos pr JOIN (SELECT producto_id, SUM(cantidad) AS unidades FROM tienda_pedido_items WHERE pedido_id = ? GROUP BY producto_id) i ON i.producto_id = pr.id
                              SET pr.vendidos = pr.vendidos + i.unidades")->execute([(int) $id]);
                historialPedidoTienda($db, $id, null, 'Pagado', "Pago confirmado: la factura {$p['numero_factura']} quedó pagada.");
            } elseif ($p && $p['estado'] === 'Pendiente de pago' && $p['estado_factura'] === 'Anulada') {
                $db->prepare("UPDATE tienda_pedidos SET estado = 'Cancelado', updated_at = NOW() WHERE id = ?")->execute([(int) $id]);
                restaurarInventarioPedido($db, $p);
                historialPedidoTienda($db, $id, null, 'Cancelado', "La factura {$p['numero_factura']} fue anulada: el pedido se canceló y las unidades volvieron al inventario.");
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('tienda (sincronizar pedido ' . $id . '): ' . $e->getMessage());
        }
    }
}

// Cambia el estado siguiendo TRANSICIONES_PEDIDO. Cancelar un pedido sin pagos anula su factura y devuelve el inventario. Devuelve el error o null
function cambiarEstadoPedido(PDO $db, $pedido_id, $nuevo, array $usuario, $detalle = '', $guia = '') {
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT p.*, f.estado AS estado_factura, f.numero_factura, f.fecha_emision,
                                     (SELECT COUNT(*) FROM tienda_pedido_items i WHERE i.pedido_id = p.id AND i.tipo = 'Físico') AS fisicos
                              FROM tienda_pedidos p LEFT JOIN facturas f ON f.id = p.factura_id WHERE p.id = ? FOR UPDATE");
        $stmt->execute([(int) $pedido_id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new DomainException('El pedido no existe.');
        if (!in_array($nuevo, TRANSICIONES_PEDIDO[$p['estado']] ?? [], true)) throw new DomainException("Un pedido «{$p['estado']}» no puede pasar a «{$nuevo}».");
        if ($nuevo === 'Enviado' && (int) $p['fisicos'] === 0) throw new DomainException('Solo se envían los pedidos con productos físicos.');
        $texto = trim(mb_substr((string) $detalle, 0, 300));

        if ($nuevo === 'Cancelado') {
            if ($p['factura_id']) {
                $recaudos = $db->prepare("SELECT COUNT(*) FROM comprobantes WHERE factura_id = ? AND estado <> 'Anulado'");
                $recaudos->execute([(int) $p['factura_id']]);
                $validando = $db->prepare("SELECT COUNT(*) FROM pagos_registro WHERE factura_id = ? AND estado_transaccion = 'Pendiente_Validacion'");
                $validando->execute([(int) $p['factura_id']]);
                if ($recaudos->fetchColumn() || $validando->fetchColumn() || in_array($p['estado_factura'], ['Pagado', 'En Proceso'], true)) {
                    throw new DomainException('El pedido tiene un pago registrado o en validación: no se puede cancelar.');
                }
                if (periodoCerrado($db, $p['fecha_emision'])) throw new DomainException(errorPeriodoCerrado($db, $p['fecha_emision']));
                if ($p['estado_factura'] !== 'Anulada') {
                    $db->prepare("UPDATE facturas SET estado = 'Anulada', motivo_anulacion = ?, anulada_por = ?, fecha_anulacion = NOW() WHERE id = ?")
                       ->execute([mb_substr("Pedido {$p['numero']} cancelado" . ($texto !== '' ? ": $texto" : '') . '.', 0, 255), (int) $usuario['id'], (int) $p['factura_id']]);
                }
            }
            restaurarInventarioPedido($db, $p);
            $texto = ($texto !== '' ? $texto . '. ' : '') . ($p['factura_id'] ? "Factura {$p['numero_factura']} anulada; " : '') . 'las unidades volvieron al inventario.';
        }
        $guia = mb_substr(trim((string) $guia), 0, 80);
        $db->prepare("UPDATE tienda_pedidos SET estado = ?, guia_envio = IF(? = '', guia_envio, ?), updated_at = NOW() WHERE id = ?")->execute([$nuevo, $guia, $guia, (int) $pedido_id]);
        historialPedidoTienda($db, $pedido_id, (int) $usuario['id'], $nuevo, trim($texto . ($guia !== '' ? " Guía de envío: $guia." : '')));
        $db->commit();
        return null;
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        return $e->getMessage();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('tienda (cambiar estado): ' . $e->getMessage());
        return 'No fue posible actualizar el pedido.';
    }
}

// Pedidos con cliente y factura. Filtros: id, usuario_id, estado, q, limite
function pedidosTienda(PDO $db, array $f = []) {
    $where = [];
    $params = [];
    if (!empty($f['id'])) { $where[] = 'p.id = ?'; $params[] = (int) $f['id']; }
    if (!empty($f['usuario_id'])) { $where[] = 'p.usuario_id = ?'; $params[] = (int) $f['usuario_id']; }
    if (!empty($f['estado']) && isset(ESTADOS_PEDIDO[$f['estado']])) { $where[] = 'p.estado = ?'; $params[] = $f['estado']; }
    if (isset($f['q']) && trim((string) $f['q']) !== '') {
        $q = '%' . addcslashes(trim((string) $f['q']), '%_\\') . '%';
        $where[] = '(p.numero LIKE ? OR u.nombre LIKE ? OR u.correo LIKE ? OR f.numero_factura LIKE ?)';
        array_push($params, $q, $q, $q, $q);
    }
    $stmt = $db->prepare("SELECT p.*, u.nombre AS cliente, u.correo AS correo_cliente, u.telefono AS telefono_cliente, u.organizacion,
                                 f.numero_factura, f.estado AS estado_factura,
                                 (SELECT COALESCE(SUM(i.cantidad), 0) FROM tienda_pedido_items i WHERE i.pedido_id = p.id) AS unidades,
                                 (SELECT COUNT(*) FROM tienda_pedido_items i WHERE i.pedido_id = p.id AND i.tipo = 'Físico') AS fisicos
                          FROM tienda_pedidos p JOIN usuarios u ON u.id = p.usuario_id LEFT JOIN facturas f ON f.id = p.factura_id"
                       . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . (int) ($f['limite'] ?? 300));
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function itemsPedido(PDO $db, $pedido_id) {
    $stmt = $db->prepare("SELECT i.*, pr.slug, pr.icono, pr.color, pr.imagen FROM tienda_pedido_items i LEFT JOIN tienda_productos pr ON pr.id = i.producto_id WHERE i.pedido_id = ? ORDER BY i.id");
    $stmt->execute([(int) $pedido_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function historialPedido(PDO $db, $pedido_id) {
    $stmt = $db->prepare("SELECT h.*, u.nombre AS usuario FROM tienda_pedido_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE h.pedido_id = ? ORDER BY h.fecha, h.id");
    $stmt->execute([(int) $pedido_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Indicadores de la tienda: ventas pagadas y pedidos del periodo, más lo que está por atender hoy
function resumenTienda(PDO $db, $desde, $hasta) {
    $stmt = $db->prepare("SELECT COUNT(*) AS pedidos,
                                 COALESCE(SUM(CASE WHEN estado NOT IN ('Pendiente de pago', 'Cancelado') THEN total END), 0) AS ventas,
                                 COALESCE(SUM(estado NOT IN ('Pendiente de pago', 'Cancelado')), 0) AS pagados
                          FROM tienda_pedidos WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
    $stmt->execute([$desde, $hasta]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $r += $db->query("SELECT COALESCE(SUM(estado = 'Pendiente de pago'), 0) AS por_pagar, COALESCE(SUM(estado IN ('Pagado', 'En preparación', 'Enviado')), 0) AS por_atender FROM tienda_pedidos")->fetch(PDO::FETCH_ASSOC);
    $r += $db->query("SELECT COALESCE(SUM(estado = 'Activo'), 0) AS activos, COALESCE(SUM(estado = 'Activo' AND stock = 0), 0) AS agotados,
                             COALESCE(SUM(estado = 'Activo' AND stock BETWEEN 1 AND 5), 0) AS bajo_stock FROM tienda_productos")->fetch(PDO::FETCH_ASSOC);
    $r['ticket_promedio'] = (int) $r['pagados'] > 0 ? round((float) $r['ventas'] / (int) $r['pagados']) : 0;
    return $r;
}

// ================================================================
// RESEÑAS (solo compradores con el pedido pagado)
// ================================================================

function puedeResenar(PDO $db, $usuario_id, $producto_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tienda_pedido_items i JOIN tienda_pedidos p ON p.id = i.pedido_id
                          WHERE p.usuario_id = ? AND i.producto_id = ? AND p.estado IN ('Pagado', 'En preparación', 'Enviado', 'Entregado')");
    $stmt->execute([(int) $usuario_id, (int) $producto_id]);
    return (int) $stmt->fetchColumn() > 0;
}

function guardarResena(PDO $db, $usuario_id, $producto_id, $calificacion, $titulo, $comentario) {
    $calificacion = (int) $calificacion;
    if ($calificacion < 1 || $calificacion > 5) return 'Elige una calificación de 1 a 5 estrellas.';
    if (!puedeResenar($db, $usuario_id, $producto_id)) return 'Solo quienes compraron y pagaron este producto pueden calificarlo.';
    $db->prepare("INSERT INTO tienda_resenas (producto_id, usuario_id, calificacion, titulo, comentario, created_at) VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE calificacion = VALUES(calificacion), titulo = VALUES(titulo), comentario = VALUES(comentario), created_at = VALUES(created_at)")
       ->execute([(int) $producto_id, (int) $usuario_id, $calificacion, mb_substr(trim((string) $titulo), 0, 100) ?: null, mb_substr(trim((string) $comentario), 0, 1000) ?: null, date('Y-m-d H:i:s')]);
    return null;
}

function resenasProducto(PDO $db, $producto_id) {
    $stmt = $db->prepare("SELECT r.*, u.nombre, u.organizacion FROM tienda_resenas r JOIN usuarios u ON u.id = r.usuario_id WHERE r.producto_id = ? ORDER BY r.created_at DESC LIMIT 50");
    $stmt->execute([(int) $producto_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================================================================
// CORREO DE CONFIRMACIÓN DEL PEDIDO
// ================================================================

function correoPedidoTienda(PDO $db, $pedido_id) {
    if (!function_exists('nuevoCorreo')) return false;
    $filas = pedidosTienda($db, ['id' => $pedido_id]);
    $p = $filas ? $filas[0] : null;
    if (!$p || empty($p['correo_cliente'])) return false;
    $celda = 'padding:10px;border-bottom:1px solid #e5e7eb';
    $lineas = '';
    foreach (itemsPedido($db, $pedido_id) as $i) {
        $lineas .= '<tr><td style="' . $celda . '">' . esc($i['nombre']) . ' <span style="color:#64748b">× ' . (int) $i['cantidad'] . '</span></td><td style="' . $celda . ';text-align:right">' . dinero($i['total']) . '</td></tr>';
    }
    $fila = function ($etiqueta, $valor, $fuerte = false) use ($celda) {
        return '<tr><td style="' . $celda . ($fuerte ? ';font-weight:bold' : '') . '">' . $etiqueta . '</td><td style="' . $celda . ';text-align:right' . ($fuerte ? ';font-weight:bold' : '') . '">' . $valor . '</td></tr>';
    };
    $html = '<html><body style="font-family:Arial,sans-serif;background:#f1f5f9;padding:24px"><table width="100%" style="max-width:620px;margin:auto;background:#fff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#0A2E4F;color:#fff;padding:22px 28px;font-size:20px;font-weight:bold">Tienda DataSense</td></tr><tr><td style="padding:28px;color:#1f2937;font-size:15px;line-height:1.6">'
        . '<p>Hola <strong>' . esc($p['cliente']) . '</strong>, recibimos tu pedido <strong>' . esc($p['numero']) . '</strong>.</p>'
        . '<table width="100%" style="border-collapse:collapse;font-size:14px">' . $lineas
        . ((float) $p['descuento'] > 0 ? $fila('Descuento' . ($p['cupon'] ? ' (' . esc($p['cupon']) . ')' : ''), '−' . dinero($p['descuento'])) : '')
        . ((int) $p['fisicos'] > 0 ? $fila('Envío', (float) $p['envio'] > 0 ? dinero($p['envio']) : 'Gratis') : '')
        . $fila('IVA incluido', dinero($p['iva'])) . $fila('Total', dinero($p['total']), true) . '</table>'
        . '<p>Generamos la factura <strong>' . esc($p['numero_factura']) . '</strong>. Págala antes de ' . TIENDA_DIAS_PAGO . ' días con PSE, tarjeta, Nequi, efectivo o pagos internacionales:</p>'
        . '<p style="text-align:center"><a href="' . esc(urlBase() . 'admin/mis_pedidos.php?pedido=' . (int) $p['id']) . '" style="background:#3FAE49;color:#fff;padding:13px 26px;border-radius:8px;text-decoration:none;font-weight:bold">Ver mi pedido y pagar</a></p>'
        . '<p style="color:#64748b;font-size:13px">Cuando el pago se confirme te avisaremos y empezaremos a preparar tu pedido.</p></td></tr></table></body></html>';
    try {
        $mail = nuevoCorreo('Tienda DataSense');
        $mail->addAddress($p['correo_cliente'], $p['cliente']);
        $mail->isHTML(true);
        $mail->Subject = '[' . $p['numero'] . '] Recibimos tu pedido – DataSense';
        $mail->Body = $html;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('tienda (correo del pedido ' . $p['numero'] . '): ' . $e->getMessage());
        return false;
    }
}
