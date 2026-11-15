<?php
// Descarga protegida del soporte que adjuntó el ciudadano: la carpeta uploads no se sirve por la web.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pqrsd.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'pqrsd');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT radicado, adjunto FROM pqrsd WHERE id = ?");
$stmt->execute([$id]);
$solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$solicitud || empty($solicitud['adjunto'])) {
    http_response_code(404);
    exit('La solicitud no tiene un soporte adjunto.');
}

// El nombre se guardó como "pqrsd/archivo.ext": se reconstruye sin permitir rutas hacia otras carpetas
$archivo = __DIR__ . '/../uploads/pqrsd/' . basename((string) $solicitud['adjunto']);
if (!is_file($archivo)) {
    http_response_code(404);
    exit('El archivo ya no está disponible.');
}

$tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
          'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$extension = mb_strtolower(pathinfo($archivo, PATHINFO_EXTENSION));

header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($archivo));
header('Content-Disposition: inline; filename="soporte_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $solicitud['radicado']) . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
readfile($archivo);
