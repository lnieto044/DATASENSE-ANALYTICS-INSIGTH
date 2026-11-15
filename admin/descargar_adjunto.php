<?php
/**
 * Descarga controlada de adjuntos de tickets.
 * La carpeta uploads/ está bloqueada por URL: los archivos solo se entregan desde aquí,
 * a la Mesa de Servicios (módulo Gestión IT) o al dueño del ticket.
 */
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereSesion($db);

$archivo = basename($_GET['archivo'] ?? '');
if (!preg_match('/^doc_[A-Za-z0-9_]+\.([A-Za-z0-9]+)$/', $archivo, $partes) || !isset(TIPOS_ADJUNTOS[strtolower($partes[1])])) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

if (!tieneAcceso($usuario['rol'], 'gestion_it', $db)) {
    // El cliente solo descarga lo que adjuntó él o lo que el técnico anexó en sus tickets
    $stmt = $db->prepare("SELECT COUNT(*) FROM soporte_tickets WHERE usuario_id = ? AND (adjunto = ? OR respuesta_tecnica LIKE ?)");
    $stmt->execute([$usuario['id'], $archivo, '%' . $archivo . '%']);
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

$ruta = __DIR__ . '/../uploads/tickets/' . $archivo;
if (!is_file($ruta)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$ext = strtolower($partes[1]);
$ver_en_navegador = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true);

header('Content-Type: ' . TIPOS_ADJUNTOS[$ext]);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($ver_en_navegador ? 'inline' : 'attachment') . '; filename="' . $archivo . '"');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
