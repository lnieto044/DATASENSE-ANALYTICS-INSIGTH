<?php
/**
 * CONECTAR TIKTOK (paso 1 de 2): lleva a la persona a TikTok para que autorice la app DataSense Publicador.
 * TikTok vuelve a tiktok_callback.php con un código que se cambia por los tokens.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/redes_publicar.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'redes');

if (!tiktokConfigurado()) {
    avisoSiguiente('danger', 'Para conectar TikTok faltan TIKTOK_CLIENT_KEY y TIKTOK_CLIENT_SECRET en la configuración del servidor (en Vercel: Environment Variables).');
    header('Location: redes_sociales.php#automatica');
    exit;
}

// El estado viaja a TikTok y vuelve: así se comprueba que la respuesta corresponde a esta sesión
$_SESSION['tiktok_estado'] = bin2hex(random_bytes(16));
header('Location: ' . urlAutorizacionTiktok($_SESSION['tiktok_estado']));
exit;
