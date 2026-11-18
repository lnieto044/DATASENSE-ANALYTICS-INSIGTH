<?php
/**
 * ESTADO DEL SITIO
 * Lo consultan Docker (healthcheck de docker-compose.yml), Render (healthCheckPath) y cualquier monitor externo.
 * Responde 200 si la base de datos contesta y 503 si no. Dice si la base es la permanente (externa) o la temporal de
 * la demostración y, si falla, un motivo corto. Nunca muestra servidores, usuarios ni claves.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

require __DIR__ . '/config/conexion.php';

$c = configuracionBaseDatos();
$respuesta = ['estado' => 'ok', 'base' => $c['externa'] ? 'permanente' : 'temporal'];
if (!$c['externa'] && trim((string) ($_ENV['DATABASE_URL'] ?? '')) !== '') {
    $respuesta['aviso'] = 'DATABASE_URL no tiene la forma mysql://usuario:clave@servidor:puerto/base';
}
try {
    $db = conectarBaseDatos($c, 3);
    if ((int) $db->query('SELECT 1')->fetchColumn() !== 1) throw new RuntimeException('sin respuesta');
} catch (Throwable $e) {
    error_log('Chequeo de salud: la base de datos no responde: ' . $e->getMessage());
    $codigo = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    $respuesta['estado'] = 'sin base de datos';
    $respuesta['motivo'] = $codigo === 1045 ? 'la base rechazó el usuario o la clave'
                         : ($codigo === 1049 ? 'la base de datos no existe' : 'el servidor de la base no responde');
}

http_response_code($respuesta['estado'] === 'ok' ? 200 : 503);
$respuesta['hora'] = date('c');
echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
