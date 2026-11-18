<?php
/**
 * VERIFICACIÓN EN DOS PASOS (2FA) - DATASENSE
 * Después de la contraseña se pide un código de 6 dígitos que cambia cada 30 segundos, generado por una app
 * autenticadora (Google Authenticator, Microsoft Authenticator, Authy…). Es el estándar TOTP (RFC 6238), el mismo
 * que usa AWS Cognito para su MFA por software.
 *
 *   - La clave secreta de cada usuario se guarda cifrada (AES-256-GCM).
 *   - Cada código sirve una sola vez y se aceptan los de los 30 segundos anteriores y siguientes (relojes desfasados).
 *   - Al activarla se entregan 8 códigos de recuperación de un solo uso, por si se pierde el celular.
 *   - El flujo (contraseña correcta, falta el código) vive en la sesión y caduca a los 10 minutos.
 *
 * Variables del .env:
 *   MFA_MODO=opcional      opcional (por defecto: al registrarse se ofrece configurarla y "Hacerlo después" deja
 *                          entrar; cada quien la activa cuando quiera en Mi perfil), obligatorio (todos la configuran
 *                          en su próximo ingreso y no se puede omitir) o desactivado.
 *   MFA_EQUIPO_OBLIGATORIO=1  en modo opcional, exige la verificación al equipo interno (todo rol distinto de
 *                          Cliente). Por defecto (0) también es opcional para el equipo.
 *   Quien ya la activó siempre debe escribir el código al entrar, en cualquier modo salvo desactivado.
 *   APP_KEY=...            clave para cifrar los secretos (32 bytes en base64). Sin ella se deriva de la clave de la base.
 *
 * Todas las cuentas, también las de la demostración, la configuran con su propia app: nadie ve el código en pantalla.
 */
require_once __DIR__ . '/seguridad.php';

const MFA_EMISOR = 'DataSense Analytics';
const MFA_DIGITOS = 6;
const MFA_PERIODO = 30;
const MFA_CODIGOS_RECUPERACION = 8;
const MFA_MINUTOS_FLUJO = 10;
const MFA_ALFABETO_RECUPERACION = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I, que se confunden al copiarlos

function modoMfa() {
    $modo = strtolower(trim((string) ($_ENV['MFA_MODO'] ?? 'opcional')));
    return in_array($modo, ['obligatorio', 'opcional', 'desactivado'], true) ? $modo : 'opcional';
}

// Bases anteriores a este módulo: se agregan las columnas la primera vez que se necesitan
function asegurarColumnasMfa(PDO $db) {
    static $lista = false;
    if ($lista) return;
    $existe = $db->query("SHOW COLUMNS FROM usuarios LIKE 'mfa_activo'")->fetch();
    if (!$existe) {
        $db->exec("ALTER TABLE usuarios
            ADD COLUMN mfa_activo TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN mfa_secreto VARCHAR(255) DEFAULT NULL,
            ADD COLUMN mfa_recuperacion TEXT DEFAULT NULL,
            ADD COLUMN mfa_ultimo_paso BIGINT DEFAULT NULL,
            ADD COLUMN mfa_activado_en DATETIME DEFAULT NULL");
    }
    $lista = true;
}

// ================================================================
// CIFRADO DE SECRETOS (también lo usan los tokens de las redes sociales)
// ================================================================

function claveCifrado() {
    $app = trim((string) ($_ENV['APP_KEY'] ?? ''));
    if ($app !== '') {
        $bruta = base64_decode(preg_replace('/^base64:/', '', $app), true);
        return hash('sha256', $bruta !== false && strlen($bruta) >= 16 ? $bruta : $app, true);
    }
    // Sin APP_KEY: se deriva de la clave de la base (la de DB_PASS o la que viene dentro de DATABASE_URL)
    require_once __DIR__ . '/../config/conexion.php';
    return hash('sha256', 'datasense-secretos|' . configuracionBaseDatos()['pass'], true);
}

function cifrarSecreto($texto) {
    $iv = random_bytes(12);
    $etiqueta = '';
    $cifrado = openssl_encrypt((string) $texto, 'aes-256-gcm', claveCifrado(), OPENSSL_RAW_DATA, $iv, $etiqueta);
    return 'v1:' . base64_encode($iv . $etiqueta . $cifrado);
}

// Devuelve el texto original, o null si el valor no se puede descifrar (por ejemplo, porque cambió la clave)
function descifrarSecreto($valor) {
    if (!is_string($valor) || strncmp($valor, 'v1:', 3) !== 0) return null;
    $datos = base64_decode(substr($valor, 3), true);
    if ($datos === false || strlen($datos) < 29) return null;
    $texto = openssl_decrypt(substr($datos, 28), 'aes-256-gcm', claveCifrado(), OPENSSL_RAW_DATA, substr($datos, 0, 12), substr($datos, 12, 16));
    return $texto === false ? null : $texto;
}

// ================================================================
// TOTP (RFC 6238)
// ================================================================

function base32Codificar($bytes) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $salida = '';
    foreach (str_split($bits, 5) as $grupo) $salida .= $alfabeto[bindec(str_pad($grupo, 5, '0'))];
    return $salida;
}

function base32Decodificar($texto) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $texto = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $texto));
    $bits = '';
    foreach (str_split($texto) as $letra) $bits .= str_pad(decbin(strpos($alfabeto, $letra)), 5, '0', STR_PAD_LEFT);
    $salida = '';
    foreach (str_split($bits, 8) as $grupo) {
        if (strlen($grupo) === 8) $salida .= chr(bindec($grupo));
    }
    return $salida;
}

function nuevoSecretoTotp() {
    return base32Codificar(random_bytes(20));
}

function pasoActual() {
    return intdiv(time(), MFA_PERIODO);
}

function codigoTotp($secreto, $paso) {
    $mensaje = pack('N*', 0) . pack('N*', $paso);
    $hash = hash_hmac('sha1', $mensaje, base32Decodificar($secreto), true);
    $desfase = ord($hash[19]) & 0x0f;
    $numero = (unpack('N', substr($hash, $desfase, 4))[1] & 0x7fffffff) % (10 ** MFA_DIGITOS);
    return str_pad((string) $numero, MFA_DIGITOS, '0', STR_PAD_LEFT);
}

// Paso de tiempo del código si es válido (y posterior al último usado), o false
function verificarTotp($secreto, $codigo, $ultimoPaso = null) {
    $codigo = preg_replace('/\D/', '', (string) $codigo);
    if (strlen($codigo) !== MFA_DIGITOS || !$secreto) return false;
    $ahora = pasoActual();
    foreach ([0, -1, 1] as $ajuste) {
        $paso = $ahora + $ajuste;
        if ($ultimoPaso !== null && $paso <= (int) $ultimoPaso) continue; // un código ya usado no vuelve a servir
        if (hash_equals(codigoTotp($secreto, $paso), $codigo)) return $paso;
    }
    return false;
}

// Dirección que leen las apps autenticadoras desde el código QR
function uriTotp($secreto, $correo) {
    $etiqueta = rawurlencode(MFA_EMISOR . ':' . $correo);
    return 'otpauth://totp/' . $etiqueta . '?secret=' . $secreto . '&issuer=' . rawurlencode(MFA_EMISOR)
         . '&algorithm=SHA1&digits=' . MFA_DIGITOS . '&period=' . MFA_PERIODO;
}

// ================================================================
// CUENTAS
// ================================================================

function secretoUsuario(array $usuario) {
    return descifrarSecreto($usuario['mfa_secreto'] ?? null);
}

// ¿Esta persona debe tener la verificación (no puede omitirla ni desactivarla)?
function mfaObligatoria(array $usuario) {
    if (modoMfa() === 'desactivado') return false;
    if (modoMfa() === 'obligatorio') return true;
    return ($usuario['rol'] ?? 'Cliente') !== 'Cliente' && ($_ENV['MFA_EQUIPO_OBLIGATORIO'] ?? '0') === '1';
}

// Qué le falta a la persona después de la contraseña: 'verificar' (escribir el código), 'configurar' (activar) o 'no'
function pasoMfaPendiente(array $usuario) {
    if (modoMfa() === 'desactivado') return 'no';
    if (!empty($usuario['mfa_activo']) && secretoUsuario($usuario)) return 'verificar';
    return mfaObligatoria($usuario) ? 'configurar' : 'no';
}

function generarCodigosRecuperacion() {
    $codigos = [];
    $largo = strlen(MFA_ALFABETO_RECUPERACION);
    for ($i = 0; $i < MFA_CODIGOS_RECUPERACION; $i++) {
        $codigo = '';
        for ($j = 0; $j < 8; $j++) $codigo .= MFA_ALFABETO_RECUPERACION[random_int(0, $largo - 1)];
        $codigos[] = substr($codigo, 0, 4) . '-' . substr($codigo, 4);
    }
    return $codigos;
}

function normalizarCodigoRecuperacion($codigo) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $codigo));
}

// Activa la verificación con el secreto ya confirmado; devuelve los códigos de recuperación (se muestran una sola vez)
function activarMfa(PDO $db, $usuario_id, $secreto, $paso) {
    asegurarColumnasMfa($db);
    $codigos = generarCodigosRecuperacion();
    $hashes = array_map(function ($c) { return password_hash(normalizarCodigoRecuperacion($c), PASSWORD_DEFAULT); }, $codigos);
    $stmt = $db->prepare("UPDATE usuarios SET mfa_activo = 1, mfa_secreto = ?, mfa_recuperacion = ?, mfa_ultimo_paso = ?, mfa_activado_en = NOW() WHERE id = ?");
    $stmt->execute([cifrarSecreto($secreto), json_encode($hashes), (int) $paso, (int) $usuario_id]);
    return $codigos;
}

function nuevosCodigosRecuperacion(PDO $db, $usuario_id) {
    $codigos = generarCodigosRecuperacion();
    $hashes = array_map(function ($c) { return password_hash(normalizarCodigoRecuperacion($c), PASSWORD_DEFAULT); }, $codigos);
    $db->prepare("UPDATE usuarios SET mfa_recuperacion = ? WHERE id = ?")->execute([json_encode($hashes), (int) $usuario_id]);
    return $codigos;
}

function desactivarMfa(PDO $db, $usuario_id) {
    asegurarColumnasMfa($db);
    $db->prepare("UPDATE usuarios SET mfa_activo = 0, mfa_secreto = NULL, mfa_recuperacion = NULL, mfa_ultimo_paso = NULL, mfa_activado_en = NULL WHERE id = ?")
       ->execute([(int) $usuario_id]);
}

function codigosRecuperacionRestantes(array $usuario) {
    $lista = json_decode((string) ($usuario['mfa_recuperacion'] ?? ''), true);
    return is_array($lista) ? count($lista) : 0;
}

// Comprueba un código de recuperación y, si sirve, lo gasta
function usarCodigoRecuperacion(PDO $db, array $usuario, $codigo) {
    $codigo = normalizarCodigoRecuperacion($codigo);
    if (strlen($codigo) !== 8) return false;
    $lista = json_decode((string) ($usuario['mfa_recuperacion'] ?? ''), true);
    if (!is_array($lista)) return false;
    foreach ($lista as $i => $hash) {
        if (is_string($hash) && password_verify($codigo, $hash)) {
            unset($lista[$i]);
            $db->prepare("UPDATE usuarios SET mfa_recuperacion = ? WHERE id = ?")->execute([json_encode(array_values($lista)), (int) $usuario['id']]);
            return true;
        }
    }
    return false;
}

// Verifica el código de la app (o de recuperación) de un usuario y registra el paso usado. true si es válido.
function comprobarCodigoUsuario(PDO $db, array $usuario, $codigo, $esRecuperacion = false) {
    if ($esRecuperacion) {
        return usarCodigoRecuperacion($db, $usuario, $codigo);
    }
    $paso = verificarTotp(secretoUsuario($usuario), $codigo, $usuario['mfa_ultimo_paso'] ?? null);
    if ($paso === false) return false;
    $db->prepare("UPDATE usuarios SET mfa_ultimo_paso = ? WHERE id = ?")->execute([$paso, (int) $usuario['id']]);
    return true;
}

// ================================================================
// FLUJO DE INGRESO
// ================================================================

// Deja la sesión a medio camino: contraseña correcta, falta el segundo paso
function iniciarFlujoMfa(array $usuario, $tipo, $origen, $volver = '') {
    iniciarSesionSegura();
    session_regenerate_id(true);
    if ($origen !== 'perfil') {
        // Ingreso o registro: si en este navegador había otra sesión abierta, se cierra mientras se completa el paso
        unset($_SESSION['admin'], $_SESSION['admin_id'], $_SESSION['admin_nombre'], $_SESSION['admin_rol'], $_SESSION['csrf_panel']);
    }
    unset($_SESSION['mfa_codigos']);
    $_SESSION['mfa_flujo'] = [
        'id' => (int) $usuario['id'], 'tipo' => $tipo, 'origen' => $origen, 'volver' => (string) $volver,
        'desde' => time(), 'fallos' => 0,
        'secreto_nuevo' => $tipo === 'configurar' ? nuevoSecretoTotp() : null,
    ];
}

function flujoMfaVigente() {
    $flujo = $_SESSION['mfa_flujo'] ?? null;
    if (!is_array($flujo)) return null;
    if (time() - (int) $flujo['desde'] > MFA_MINUTOS_FLUJO * 60) {
        unset($_SESSION['mfa_flujo']);
        return null;
    }
    return $flujo;
}

// Abre la sesión del panel (después de la contraseña y, si aplica, del código) y dice a dónde ir
function completarInicioSesion(PDO $db, array $usuario, $volver = '') {
    iniciarSesionSegura();
    session_regenerate_id(true);
    unset($_SESSION['csrf_panel'], $_SESSION['mfa_flujo'], $_SESSION['mfa_codigos']); // la sesión nueva recibe un token CSRF nuevo
    $_SESSION['admin']        = true;
    $_SESSION['admin_id']     = (int) $usuario['id'];
    $_SESSION['admin_nombre'] = $usuario['nombre'];
    $_SESSION['admin_rol']    = $usuario['rol'];
    if (usaClavePublica($usuario)) {
        $_SESSION['cambiar_clave'] = 1;
        return 'cambiar_clave.php?res=obligatorio';
    }
    return destinoTrasLogin($volver, paginaInicio($usuario['rol'], $db));
}
