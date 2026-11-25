<?php
/**
 * CONECTAR TIKTOK (paso 2 de 2): TikTok vuelve aquí después de que la persona autoriza (o cancela).
 * Esta dirección debe estar registrada como Redirect URI en Login Kit, en TikTok for Developers.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/redes_publicar.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'redes');

$estado = (string) ($_GET['state'] ?? '');
$esperado = (string) ($_SESSION['tiktok_estado'] ?? '');
unset($_SESSION['tiktok_estado']);

if (isset($_GET['error'])) {
    $motivo = (string) ($_GET['error_description'] ?? $_GET['error']);
    avisoSiguiente('warning', $_GET['error'] === 'access_denied' ? 'Cancelaste la conexión con TikTok. Puedes intentarlo de nuevo cuando quieras.'
                                                              : 'TikTok no autorizó la conexión: ' . mb_substr($motivo, 0, 200));
} elseif ($esperado === '' || !hash_equals($esperado, $estado) || empty($_GET['code'])) {
    avisoSiguiente('danger', 'La respuesta de TikTok no corresponde a esta sesión. Pulsa «Conectar TikTok» de nuevo.');
} else {
    $r = conectarTiktok($db, (string) $_GET['code'], $usuario['id']);
    avisoSiguiente($r['ok'] ? 'success' : 'danger', $r['mensaje']);
}
header('Location: redes_sociales.php#automatica');
exit;
