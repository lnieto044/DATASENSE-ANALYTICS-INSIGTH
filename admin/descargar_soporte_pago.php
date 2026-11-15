<?php
/**
 * Descarga controlada de soportes de pago (conciliaciones y pagos internacionales reportados).
 * La carpeta uploads/ está bloqueada por URL: los archivos solo se entregan a quien gestiona facturación
 * o comprobantes de ingreso, o al cliente dueño de la factura.
 */
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereSesion($db);

$stmt = $db->prepare("SELECT p.soporte_visual, f.id_usuario FROM pagos_registro p JOIN facturas f ON f.id = p.factura_id WHERE p.id = ?");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$pago = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pago || !$pago['soporte_visual']) {
    http_response_code(404);
    exit('Soporte no encontrado.');
}

$gestiona = tieneAcceso($usuario['rol'], 'facturacion', $db) || tieneAcceso($usuario['rol'], 'comprobante_ingreso', $db);
if (!$gestiona && (int) $pago['id_usuario'] !== (int) $usuario['id']) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$archivo = basename($pago['soporte_visual']);
if (!preg_match('/^PAGO_[A-Za-z0-9_]+\.(jpg|jpeg|png|pdf)$/i', $archivo, $partes) || !is_file($ruta = __DIR__ . '/../uploads/pagos/' . $archivo)) {
    http_response_code(404);
    exit('Soporte no encontrado.');
}

header('Content-Type: ' . TIPOS_ADJUNTOS[strtolower($partes[1])]);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $archivo . '"');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
