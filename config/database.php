<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

/* =========================
   CARGAR VARIABLES .ENV
========================= */

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

/* =========================
   DATOS DE CONEXIÓN
========================= */

$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$port = $_ENV['DB_PORT'] ?? '3306';

/* =========================
   DSN MYSQL
========================= */

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

/* =========================
   OPCIONES PDO
========================= */

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

/* =========================
   CONEXIÓN
========================= */

try {

    return new PDO($dsn, $user, $pass, $options);

} catch (PDOException $e) {

    // Registra el error real en los logs del servidor (invisible para el usuario)
    error_log("Error de conexión PDO: " . $e->getMessage());
    
    // Muestra un mensaje genérico y detiene la ejecución
    die("Ocurrió un problema interno al conectar con la base de datos. Por favor, intenta más tarde.");

}