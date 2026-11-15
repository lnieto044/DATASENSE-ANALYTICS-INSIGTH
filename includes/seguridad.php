<?php
/**
 * SEGURIDAD CENTRALIZADA - DATASENSE
 * Sesión segura, control de acceso por rol/módulo, CSRF, límite de intentos,
 * política de contraseñas y escapado de salida.
 */
require_once __DIR__ . '/funciones_permisos.php';
require_once __DIR__ . '/utilidades.php';

// Estados de cuenta que no pueden usar el sistema
const ESTADOS_BLOQUEADOS = ['Suspendido', 'Retirado', 'Inactivo'];

// Extensiones aceptadas en adjuntos y el tipo MIME con el que se entregan al descargarlos
const TIPOS_ADJUNTOS = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip'  => 'application/zip',
];

// ================================================================
// SESIÓN Y CONTROL DE ACCESO
// ================================================================

// Inicia la sesión con cookie HttpOnly (JS no puede leerla) y SameSite=Lax (mitiga CSRF)
function iniciarSesionSegura() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Corta la petición: las páginas redirigen y los endpoints AJAX responden con código HTTP
function denegarAcceso($modo, $codigo) {
    if ($modo === 'api') {
        http_response_code($codigo);
        exit('no_autorizado');
    }
    if ($codigo === 401) {
        // Tras iniciar sesión se regresa a la página pedida (por ejemplo, el enlace de una factura enviado por correo)
        $volver = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $volver = basename($_SERVER['SCRIPT_NAME'] ?? '') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        }
        header('Location: login.php' . ($volver !== '' ? '?volver=' . urlencode($volver) : ''));
        exit;
    }
    header('Location: perfil.php?err=acceso_denegado');
    exit;
}

// Destino tras el login: solo páginas del panel (sin rutas, dominios ni esquemas) para evitar redirecciones abiertas
function destinoTrasLogin($volver, $porDefecto = 'dashboard.php') {
    // Páginas de la tienda pública (catálogo, carrito y pago): el comprador vuelve a donde estaba
    if (is_string($volver) && preg_match('/^\.\.\/(tienda|producto|carrito|checkout)\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $volver)) {
        return $volver;
    }
    if (!is_string($volver) || !preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $volver)) {
        return $porDefecto;
    }
    return in_array(strtok($volver, '?'), ['login.php', 'logout.php'], true) ? $porDefecto : $volver;
}

// Primera página del panel que el rol puede abrir (un cliente, por ejemplo, entra a su Centro de Soporte)
function paginaInicio($rol, PDO $db) {
    $paginas = [
        'dashboard' => 'dashboard.php', 'mi_resumen' => 'mi_resumen.php', 'soporte' => 'soporte.php', 'gestion_it' => 'gestion_it.php', 'supervisor_it' => 'supervisor_it.php',
        'reporte_ejecutivo' => 'reporte_ejecutivo.php', 'mis_facturas' => 'mis_facturas.php',
        'mensajes' => 'mensajes.php', 'facturacion' => 'facturacion.php', 'clientes' => 'clientes.php',
        'reuniones' => 'salas_reuniones.php', 'encuestas' => 'encuestas.php', 'catalogo' => 'catalogo.php',
        'plantillas' => 'plantillas.php', 'chats' => 'admin_chats.php', 'equipo' => 'equipo.php',
        'comprobante_ingreso' => 'comprobante_ingreso.php', 'comprobante_egreso' => 'comprobante_egreso.php', 'nota_contable' => 'nota_contable.php',
        'notas_factura' => 'nota_credito.php', 'cuentas_por_pagar' => 'cuentas_por_pagar.php', 'conciliacion_bancaria' => 'conciliacion_bancaria.php',
        'impuestos' => 'impuestos.php', 'cierre_contable' => 'cierre_contable.php',
        'libro_auxiliar' => 'libro_auxiliar.php', 'estado_resultados' => 'estado_resultados.php',
        'balance_general' => 'balance_general.php', 'libro_mayor' => 'libro_mayor.php',
        'mis_pedidos' => 'mis_pedidos.php', 'tienda' => 'tienda_productos.php',
    ];
    foreach ($paginas as $modulo => $pagina) {
        if (tieneAcceso($rol, $modulo, $db)) return $pagina;
    }
    return 'perfil.php';
}

// Exige un usuario logueado y activo. Relee rol y estado desde la BD en cada petición,
// así un cambio de rol o una suspensión se aplican de inmediato y no al volver a iniciar sesión.
function requiereSesion(PDO $db, $modo = 'pagina') {
    iniciarSesionSegura();

    $usuario = false;
    if (!empty($_SESSION['admin_id'])) {
        $stmt = $db->prepare("SELECT id, nombre, rol, estado_cuenta FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$usuario || in_array($usuario['estado_cuenta'] ?? 'Activo', ESTADOS_BLOQUEADOS, true)) {
        $_SESSION = [];
        session_destroy();
        denegarAcceso($modo, 401);
    }

    $_SESSION['admin_rol'] = $usuario['rol'];

    // Todo POST del panel debe traer el token CSRF (lo agrega el script de includes/sidebar.php)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrfValido()) {
        http_response_code(403);
        if ($modo === 'api') exit('csrf_invalido');
        header('Content-Type: text/plain; charset=utf-8');
        exit('La sesión expiró o el formulario no es válido. Vuelve atrás, recarga la página e inténtalo de nuevo.');
    }

    return $usuario;
}

// Exige acceso a un módulo según la matriz de "Seguridad y Roles" (el Admin siempre pasa)
function requiereAcceso(PDO $db, $modulo, $modo = 'pagina') {
    $usuario = requiereSesion($db, $modo);
    if (!tieneAcceso($usuario['rol'], $modulo, $db)) {
        denegarAcceso($modo, 403);
    }
    return $usuario;
}

// Exige que el usuario tenga uno de los roles indicados
function requiereRol(PDO $db, array $roles, $modo = 'pagina') {
    $usuario = requiereSesion($db, $modo);
    if (!in_array($usuario['rol'], $roles, true)) {
        denegarAcceso($modo, 403);
    }
    return $usuario;
}

// ================================================================
// CSRF
// ================================================================

// Token CSRF del panel: uno por sesión, se renueva al iniciar sesión
function tokenCsrf() {
    iniciarSesionSegura();
    if (empty($_SESSION['csrf_panel'])) {
        $_SESSION['csrf_panel'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_panel'];
}

// El token llega como campo csrf_token (formularios) o como cabecera X-CSRF-Token (fetch)
function csrfValido() {
    $enviado = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($enviado) && !empty($_SESSION['csrf_panel']) && hash_equals($_SESSION['csrf_panel'], $enviado);
}

// ================================================================
// AVISOS ENTRE REDIRECCIONES (se muestran una sola vez en la página siguiente)
// ================================================================

function avisoSiguiente($tipo, $texto) {
    iniciarSesionSegura();
    $_SESSION['aviso_panel'] = ['tipo' => $tipo, 'texto' => $texto];
}

function tomarAviso() {
    iniciarSesionSegura();
    $aviso = $_SESSION['aviso_panel'] ?? null;
    unset($_SESSION['aviso_panel']);
    return $aviso;
}

// ================================================================
// LÍMITE DE INTENTOS (sin tablas nuevas: un archivo por clave en la carpeta temporal del sistema)
// ================================================================

function rutaLimitador($clave) {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'datasense_limites';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', $clave) . '.json';
}

// Suma un intento a la clave y devuelve cuántos lleva dentro de la ventana (en segundos)
function registrarIntento($clave, $ventana) {
    $ruta = rutaLimitador($clave);
    $datos = json_decode((string) @file_get_contents($ruta), true);
    if (!is_array($datos) || time() - (int) ($datos['desde'] ?? 0) > $ventana) {
        $datos = ['total' => 0, 'desde' => time()];
    }
    $datos['total']++;
    @file_put_contents($ruta, json_encode($datos), LOCK_EX);
    return $datos['total'];
}

// Segundos que faltan para desbloquear la clave si ya alcanzó el máximo (0 = sin bloqueo)
function segundosBloqueo($clave, $maximo, $ventana) {
    $datos = json_decode((string) @file_get_contents(rutaLimitador($clave)), true);
    if (!is_array($datos) || (int) ($datos['total'] ?? 0) < $maximo) {
        return 0;
    }
    return max(0, $ventana - (time() - (int) $datos['desde']));
}

function reiniciarIntentos($clave) {
    @unlink(rutaLimitador($clave));
}

// ================================================================
// CONTRASEÑAS
// ================================================================

// Política: 8 a 72 caracteres (límite de bcrypt) combinando letras y números. Devuelve el error o null.
function errorPoliticaClave($clave) {
    if (!is_string($clave) || strlen($clave) < 8) return 'La contraseña debe tener al menos 8 caracteres.';
    if (strlen($clave) > 72) return 'La contraseña no puede superar los 72 caracteres.';
    if (!preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $clave) || !preg_match('/\d/', $clave)) return 'La contraseña debe combinar letras y números.';
    return null;
}

// Contraseña temporal aleatoria para cuentas creadas por un administrador (cumple la política)
function generarClaveTemporal() {
    return 'Ds' . random_int(10, 99) . '-' . bin2hex(random_bytes(4));
}

// ================================================================
// LECTURA DE FORMULARIOS
// ================================================================

// Campo de texto de un formulario, sin espacios a los lados. Si falta o llega como arreglo (campo[]=x) devuelve ''
// en lugar de provocar un error fatal en trim().
function textoPost($campo) {
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

// ================================================================
// ESCAPADO DE SALIDA
// ================================================================

// Escapa texto para HTML (contenido y atributos). Acepta null.
function esc($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

// Convierte un valor PHP en un literal JSON seguro para insertar dentro de <script>
function jsJson($valor) {
    return json_encode($valor, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// Literal JS seguro dentro de un atributo HTML, por ejemplo en onclick="abrir(...)"
function jsAttr($valor) {
    return esc(jsJson($valor));
}
