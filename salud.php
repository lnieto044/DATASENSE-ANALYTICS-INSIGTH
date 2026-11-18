<?php
/**
 * ESTADO DEL SITIO
 * Lo consultan Docker (healthcheck de docker-compose.yml), Render (healthCheckPath) y cualquier monitor externo.
 * Responde 200 si la base de datos contesta y 503 si no. No muestra datos internos.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

require __DIR__ . '/config/conexion.php';

$bien = false;
try {
    $db = conectarBaseDatos(null, 3);
    $bien = (int) $db->query('SELECT 1')->fetchColumn() === 1;
} catch (Throwable $e) {
    error_log('Chequeo de salud: la base de datos no responde: ' . $e->getMessage());
}

http_response_code($bien ? 200 : 503);
echo json_encode(['estado' => $bien ? 'ok' : 'sin base de datos', 'hora' => date('c')]);
