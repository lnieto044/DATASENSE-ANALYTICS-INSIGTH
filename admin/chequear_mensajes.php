<?php
// 1. Validar sesión y acceso al módulo (si no, responde 401/403 y el header oculta la campana)
require_once __DIR__ . '/../includes/seguridad.php';

// 2. Conectar a la base de datos
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'mensajes', 'api');

try {
    // 3. Consultar cuántos mensajes NO han sido leídos
    $stmt = $db->query("SELECT COUNT(*) FROM contactos WHERE leido = 0");
    $total_no_leidos = $stmt->fetchColumn();

    // 4. Devolver el número exacto al JavaScript del header
    echo $total_no_leidos;

} catch (Exception $e) {
    // Si hay un error con la base de datos, por seguridad devolvemos 0
    echo 0;
}
?>