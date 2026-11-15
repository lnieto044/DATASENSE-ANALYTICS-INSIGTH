<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// Seguridad: solo quien tenga el módulo "roles" (Seguridad y Roles) puede cambiar el rol o el estado de una cuenta
$actor = requiereAcceso($db, 'roles', 'api');

$roles_validos = ['Admin', 'Soporte', 'Supervisor', 'Analista', 'Finanzas', 'Comercial', 'Cliente'];
$estados_validos = ['Activo', 'Vacaciones', 'Suspendido', 'Retirado', 'Inactivo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id_usuario'] ?? 0);
    $nuevo_rol = $_POST['nuevo_rol'] ?? null;
    $nuevo_estado = $_POST['nuevo_estado'] ?? null;

    if (!$id) die('ID de usuario no proporcionado');

    $stmt_obj = $db->prepare("SELECT rol FROM usuarios WHERE id = ?");
    $stmt_obj->execute([$id]);
    $rol_objetivo = $stmt_obj->fetchColumn();
    if ($rol_objetivo === false) die('Usuario no encontrado');

    // Nadie cambia su propio rol ni se bloquea a sí mismo
    if ($id === (int) $actor['id']) {
        echo 'error_auto_bloqueo';
        exit;
    }

    // Solo un Admin puede otorgar el rol Admin o modificar cuentas de Admin
    if ($actor['rol'] !== 'Admin' && ($rol_objetivo === 'Admin' || $nuevo_rol === 'Admin')) {
        echo 'error_permiso';
        exit;
    }

    try {
        if ($nuevo_rol) {
            // Caso 1: Actualizar el ROL
            if (!in_array($nuevo_rol, $roles_validos, true)) { echo 'error_datos'; exit; }
            $stmt = $db->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
            $stmt->execute([$nuevo_rol, $id]);
            echo 'ok';
        } elseif ($nuevo_estado) {
            // Caso 2: Actualizar el ESTADO (Activo/Suspendido)
            if (!in_array($nuevo_estado, $estados_validos, true)) { echo 'error_datos'; exit; }
            $stmt = $db->prepare("UPDATE usuarios SET estado_cuenta = ? WHERE id = ?");
            $stmt->execute([$nuevo_estado, $id]);
            echo 'ok';
        }
    } catch (PDOException $e) {
        echo 'error_db';
    }
}
