<?php
/**
 * CONEXIÓN A LA BASE DE DATOS
 * Uso: $db = require __DIR__ . '/../config/database.php';
 * Los datos salen del .env o del entorno del servidor (detalles en config/conexion.php).
 */
require_once __DIR__ . '/conexion.php';

try {
    return conectarBaseDatos();
} catch (PDOException $e) {
    // Registra el error real en los logs del servidor (invisible para el usuario)
    error_log("Error de conexión PDO: " . $e->getMessage());

    // Muestra un mensaje genérico y detiene la ejecución
    die("Ocurrió un problema interno al conectar con la base de datos. Por favor, intenta más tarde.");
}
