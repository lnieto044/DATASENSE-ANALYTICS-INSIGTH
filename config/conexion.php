<?php
/**
 * DATOS DE CONEXIÓN A LA BASE DE DATOS
 * Los usan config/database.php (toda la plataforma), salud.php y los scripts de cron/.
 *
 * Variables (del entorno del servidor o del archivo .env):
 *   DATABASE_URL=mysql://usuario:clave@servidor:4000/datasense
 *       Una sola línea con todo, como la entregan TiDB Cloud y otros servicios. Si la base del final es "test" o no
 *       viene, se usa "datasense". Tiene prioridad sobre las variables sueltas.
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *       Las mismas datos por separado (XAMPP, la base dentro del contenedor de Render o de docker-compose).
 *   DB_SSL=1 / 0
 *       Conexión cifrada verificando el certificado del servidor. Una base EXTERNA (fuera de este servidor) la usa
 *       siempre salvo DB_SSL=0; una local, solo con DB_SSL=1. DB_SSL_CA: archivo de certificados (el del sistema).
 *
 * Una base externa es permanente: lo que se guarda no se pierde cuando Vercel o Render reinician el servidor.
 */

// Carga el .env una sola vez (las variables del servidor tienen prioridad: no se sobrescriben)
function cargarEntorno() {
    static $cargado = false;
    if ($cargado) return;
    $cargado = true;
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists('Dotenv\Dotenv')) Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

function configuracionBaseDatos() {
    cargarEntorno();
    $c = [
        'host' => (string) ($_ENV['DB_HOST'] ?? 'localhost'),
        'port' => (string) ($_ENV['DB_PORT'] ?? '3306'),
        'name' => (string) ($_ENV['DB_NAME'] ?? ''),
        'user' => (string) ($_ENV['DB_USER'] ?? 'root'),
        'pass' => (string) ($_ENV['DB_PASS'] ?? ''),
        'ssl'  => null,
    ];

    $url = trim((string) ($_ENV['DATABASE_URL'] ?? ''), " \t\n\r\"'");
    $partes = $url !== '' ? parse_url($url) : false;
    if ($partes && !empty($partes['host'])) {
        $c['host'] = $partes['host'];
        $c['port'] = (string) ($partes['port'] ?? 3306);
        $c['user'] = rawurldecode((string) ($partes['user'] ?? ''));
        $c['pass'] = rawurldecode((string) ($partes['pass'] ?? ''));
        $base = rawurldecode(trim((string) ($partes['path'] ?? ''), '/'));
        $c['name'] = ($base === '' || $base === 'test') ? 'datasense' : $base;
        parse_str((string) ($partes['query'] ?? ''), $opciones);
        $modo = strtolower((string) ($opciones['ssl-mode'] ?? $opciones['sslmode'] ?? ''));
        if ($modo === 'disabled' || $modo === 'disable' || ($opciones['ssl'] ?? '') === 'false') $c['ssl'] = false;
    }

    $c['externa'] = !in_array(strtolower($c['host']), ['', 'localhost', '127.0.0.1', '::1'], true);
    $ssl = strtolower(trim((string) ($_ENV['DB_SSL'] ?? '')));
    if ($ssl !== '') $c['ssl'] = in_array($ssl, ['1', 'true', 'si', 'sí', 'on'], true);
    if ($c['ssl'] === null) $c['ssl'] = $c['externa'];
    return $c;
}

function opcionesBaseDatos(array $c, $segundos = 10) {
    $opciones = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => (int) $segundos,
    ];
    if ($c['ssl']) {
        $opciones[PDO::MYSQL_ATTR_SSL_CA] = trim((string) ($_ENV['DB_SSL_CA'] ?? '')) ?: '/etc/ssl/certs/ca-certificates.crt';
        $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    if ($c['externa']) {
        // Una base externa no comparte la zona horaria ni la configuración de la local: hora de Colombia (la de PHP)
        // y el mismo modo SQL con el que se probó la plataforma (deploy/mariadb.cnf)
        $opciones[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET time_zone = '" . date('P') . "', sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'";
    }
    return $opciones;
}

// Conexión nueva. $conBase = false conecta al servidor sin elegir base (para crearla).
function conectarBaseDatos(?array $c = null, $segundos = 10, $conBase = true) {
    $c = $c ?: configuracionBaseDatos();
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ($conBase ? ';dbname=' . $c['name'] : '') . ';charset=utf8mb4';
    return new PDO($dsn, $c['user'], $c['pass'], opcionesBaseDatos($c, $segundos));
}
