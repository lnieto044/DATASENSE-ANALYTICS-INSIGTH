<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'mensajes');

// Etapas válidas del lead (las mismas del selector de mensajes.php)
$estados_validos = ['Nuevo', 'En Calificación', 'Contactado', 'En Propuesta', 'Negociación', 'Convertido', 'Descartado'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $nuevo_estado = $_POST['nuevo_estado'] ?? '';

    if ($id > 0 && in_array($nuevo_estado, $estados_validos, true)) {
        $stmt = $db->prepare("UPDATE contactos SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $id]);
    }

    header("Location: mensajes.php?update=success");
    exit;
}
