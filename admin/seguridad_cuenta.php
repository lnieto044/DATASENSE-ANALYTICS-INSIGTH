<?php
/**
 * VERIFICACIÓN EN DOS PASOS DESDE MI PERFIL
 * Con el código actual de la app: generar códigos de recuperación nuevos o desactivar la verificación
 * (esto último solo si MFA_MODO no es obligatorio). Activarla se hace en doble_factor.php?activar=1.
 */
require_once __DIR__ . '/../includes/doble_factor.php';
$db = require __DIR__ . '/../config/database.php';
$actual = requiereSesion($db);   // exige además el token CSRF del formulario
asegurarColumnasMfa($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: perfil.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$actual['id']]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
$accion = $_POST['accion'] ?? '';
$clave_limite = 'mfa|' . (int) $usuario['id'];

if (empty($usuario['mfa_activo'])) {
    header('Location: perfil.php');
    exit;
}
if (segundosBloqueo($clave_limite, 5, 900) > 0) {
    avisoSiguiente('error', 'Demasiados códigos incorrectos. Espera unos minutos e inténtalo de nuevo.');
    header('Location: perfil.php');
    exit;
}
if (!comprobarCodigoUsuario($db, $usuario, textoPost('codigo'))) {
    registrarIntento($clave_limite, 900);
    avisoSiguiente('error', 'El código no es correcto o ya venció. Escribe el que muestra ahora tu app.');
    header('Location: perfil.php');
    exit;
}
reiniciarIntentos($clave_limite);

if ($accion === 'codigos') {
    // Los nuevos reemplazan a los anteriores y se muestran una sola vez
    $_SESSION['mfa_codigos'] = ['id' => (int) $usuario['id'], 'codigos' => nuevosCodigosRecuperacion($db, $usuario['id']), 'origen' => 'perfil', 'volver' => ''];
    header('Location: doble_factor.php');
    exit;
}
if ($accion === 'desactivar' && modoMfa() !== 'obligatorio') {
    desactivarMfa($db, $usuario['id']);
    avisoSiguiente('warning', 'Desactivaste la verificación en dos pasos. Tu cuenta queda protegida solo con la contraseña.');
}
header('Location: perfil.php');
