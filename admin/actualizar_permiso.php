<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereRol($db, ['Admin'], 'api');

// El Admin siempre tiene acceso total: solo se configuran los demás roles
$roles_validos = ['Soporte', 'Supervisor', 'Analista', 'Finanzas', 'Comercial', 'Cliente'];

$rol = $_POST['rol'] ?? '';
$modulo = $_POST['modulo'] ?? '';
$acceso = (($_POST['acceso'] ?? '0') === '1') ? 1 : 0;

$stmt_mod = $db->prepare("SELECT COUNT(*) FROM modulos_sistema WHERE id_modulo = ?");
$stmt_mod->execute([$modulo]);

if (in_array($rol, $roles_validos, true) && $stmt_mod->fetchColumn()) {
    $stmt = $db->prepare("INSERT INTO permisos_roles (rol, modulo, acceso) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE acceso = ?");
    echo ($stmt->execute([$rol, $modulo, $acceso, $acceso])) ? 'ok' : 'error';
}
