<?php
// Seguridad: sesión activa y acceso al módulo de mensajes
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'mensajes', 'api');

if (!isset($_POST['id'])) {
    exit('error');
}
$id = intval($_POST['id']);

// Actualizamos el mensaje para que deje de contar como "Nuevo"
$stmt = $db->prepare("UPDATE contactos SET leido = 1 WHERE id = ?");
if($stmt->execute([$id])) {
    echo "ok";
} else {
    echo "error";
}
?>