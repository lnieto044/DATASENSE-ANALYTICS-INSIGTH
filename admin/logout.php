<?php
require_once __DIR__ . '/../includes/seguridad.php';
iniciarSesionSegura();
$_SESSION = [];

// Borra también la cookie de sesión del navegador
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
}
session_destroy();

// Ruta relativa a admin/: funciona en la raíz del dominio o en cualquier subcarpeta
header("Location: ../");
exit;
