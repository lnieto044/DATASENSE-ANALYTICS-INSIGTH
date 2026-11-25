<?php
/**
 * IMAGEN PÚBLICA DE UNA PUBLICACIÓN PROGRAMADA
 * TikTok publica fotos descargándolas desde una dirección del sitio verificado (PULL_FROM_URL). Esta página entrega
 * el afiche de la cola solo si la dirección trae la firma que genera la plataforma (urlPublicaImagenRedes), así que
 * nadie puede recorrer las imágenes de la cola adivinando nombres.
 */
require_once __DIR__ . '/includes/redes_publicar.php';
// Primero la base: carga el .env, del que sale la clave con la que se firman las direcciones
$db = require __DIR__ . '/config/database.php';

$nombre = basename((string) ($_GET['f'] ?? ''));
$firma = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $nombre) || !hash_equals(firmaImagenRedes($nombre), $firma)) {
    http_response_code(404);
    exit;
}

$ruta = rutaImagenRedes($db, $nombre);
if (!$ruta) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex, nofollow');
readfile($ruta);
