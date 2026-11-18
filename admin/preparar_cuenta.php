<?php
/**
 * PREPARAR MI CUENTA (solo base de demostración, solo administradores)
 * Botón de Mi perfil: deja a la persona como único administrador y le pasa el historial de un cliente de ejemplo
 * para que sus páginas personales no se vean vacías. La lógica está en includes/cuenta_demo.php.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/cuenta_demo.php';
$db = require __DIR__ . '/../config/database.php';
$actual = requiereRol($db, ['Admin']);   // exige además el token CSRF del formulario

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !esDemostracion($db)) {
    header('Location: perfil.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$actual['id']]);
$yo = $stmt->fetch(PDO::FETCH_ASSOC);

try {
    $r = prepararCuentaDemo($db, $yo);
    $partes = [];
    if ($r['admins']) $partes[] = 'quedaste como único administrador (se borraron ' . $r['admins'] . ' cuentas de administrador de ejemplo)';
    if ($r['cliente']) $partes[] = "tu cuenta recibió el historial de {$r['cliente']}: {$r['facturas']} facturas, {$r['casos']} casos y {$r['reuniones']} reuniones";
    avisoSiguiente('success', $partes ? 'Listo: ' . implode('; ', $partes) . '.' : 'Tu cuenta ya estaba preparada.');
} catch (Throwable $e) {
    error_log('preparar_cuenta.php: ' . $e->getMessage());
    avisoSiguiente('error', 'No se pudo preparar la cuenta. No se cambió nada; intenta de nuevo.');
}
header('Location: perfil.php');
