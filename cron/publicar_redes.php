<?php
/**
 * PUBLICADOR DE REDES SOCIALES
 * Publica lo que ya llegó a su fecha en la cola de Redes Sociales › Publicación automática.
 * Se ejecuta cada minuto:
 *   - En el contenedor (Render y la copia local) lo lanza deploy/entrypoint.sh.
 *   - En un hosting con cron (por ejemplo Hostinger): php /ruta/del/proyecto/cron/publicar_redes.php
 * Solo funciona desde la línea de comandos; por la web responde 404.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/redes_publicar.php';
$db = require __DIR__ . '/../config/database.php';

$resumen = procesarColaRedes($db, 10);
foreach ($resumen['publicaciones'] as $p) {
    $detalle = [];
    foreach ($p['resultado'] as $red => $r) $detalle[] = $red . ': ' . (!empty($r['ok']) ? 'publicada' : ($r['error'] ?? 'error'));
    echo date('Y-m-d H:i:s') . " publicación {$p['id']} → {$p['estado']} (" . implode('; ', $detalle) . ")\n";
}
