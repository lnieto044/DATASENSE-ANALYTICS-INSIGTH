<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'clientes', 'api');

try {
    // Cuenta cuántos usuarios tienen el rol 'Cliente' y no han sido vistos (0)
    $stmt = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'Cliente' AND visto = 0");
    echo $stmt->fetchColumn();
} catch (Exception $e) {
    echo 0;
}
?>