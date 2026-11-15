<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'facturacion', 'api');

try {
    // Cuenta cuántas facturas están en estado 'Pagado' y visto = 0
    $stmt = $db->query("SELECT COUNT(*) FROM facturas WHERE estado = 'Pagado' AND visto = 0");
    echo $stmt->fetchColumn();
} catch (Exception $e) {
    echo 0;
}
?>