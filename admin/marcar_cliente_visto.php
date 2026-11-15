<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'clientes', 'api');

if (!isset($_POST['id'])) {
    exit('error');
}
$id = intval($_POST['id']);

$stmt = $db->prepare("UPDATE usuarios SET visto = 1 WHERE id = ?");
if($stmt->execute([$id])) {
    echo "ok";
} else {
    echo "error";
}
?>