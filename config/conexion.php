<?php
/**
 * DATOS DE CONEXIÓN A LA BASE DE DATOS
 * Los usan config/database.php (toda la plataforma), salud.php y los scripts de cron/.
 *
 * Variables (del entorno del servidor o del archivo .env):
 *   DATABASE_URL=mysql://usuario:clave@servidor:4000/datasense
 *       Una sola línea con todo, como la entregan TiDB Cloud y otros servicios. Si la base del final es una del sistema
 *       ("test", "sys", "mysql"…) o no viene, se usa "datasense". Tiene prioridad sobre las variables sueltas.
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

/**
 * Lee DATABASE_URL aunque se haya pegado con detalles de más: el nombre de la variable delante ("DATABASE_URL=…"),
 * comillas, espacios o saltos de línea, una clave con @ o #, o la línea de comando de mysql que muestran algunos
 * paneles (mysql -u usuario -h servidor -P puerto -D base -pclave). Devuelve host, port, user, pass, base y ssl
 * (false si pide conexión sin cifrar), o null si no la entiende.
 */
function leerDatabaseUrl($texto) {
    $t = trim((string) $texto, " \t\n\r\"'");
    $t = trim(preg_replace('/^(export\s+)?DATABASE_URL\s*=\s*/i', '', $t), " \t\n\r\"'");
    if ($t === '') return null;

    // Línea de comando de mysql
    if (preg_match('/^(mysql|mariadb)\s+-/i', $t)) {
        $opcion = function (array $patrones) use ($t) {
            foreach ($patrones as $p) {
                if (preg_match($p, $t, $m)) return trim($m[1], "'\"");
            }
            return '';
        };
        $host = $opcion(['/(?:^|\s)-h\s*(\S+)/', '/--host[=\s](\S+)/']);
        if ($host === '') return null;
        return [
            'host' => $host,
            'port' => $opcion(['/(?:^|\s)-P\s*(\d+)/', '/--port[=\s](\d+)/']) ?: '3306',
            'user' => $opcion(["/(?:^|\\s)-u\\s*('[^']*'|\"[^\"]*\"|\\S+)/", "/--user[=\\s]('[^']*'|\\S+)/"]),
            'pass' => $opcion(["/(?:^|\\s)-p('[^']*'|\"[^\"]*\"|\\S+)/", "/--password[=\\s]('[^']*'|\\S+)/"]),
            'base' => $opcion(["/(?:^|\\s)-D\\s*('[^']*'|\\S+)/", "/--database[=\\s]('[^']*'|\\S+)/"]),
            'ssl'  => stripos($t, 'ssl-mode=disabled') !== false ? false : null,
        ];
    }

    // Dirección mysql://usuario:clave@servidor:puerto/base?opciones (una URL no lleva espacios)
    $t = preg_replace('/\s+/', '', $t);
    if (!preg_match('#^(?:mysql2?|mariadb)://([^:@/]+)(?::(.*))?@([^:@/?]+)(?::(\d+))?(?:/([^?]*))?(?:\?(.*))?$#i', $t, $m)) return null;
    if (preg_match('/<[A-Z_]+>/', $m[2] ?? '')) return null;   // quedó el texto de ejemplo <PASSWORD> en lugar de la clave
    parse_str($m[6] ?? '', $opciones);
    $modo = strtolower((string) ($opciones['ssl-mode'] ?? $opciones['sslmode'] ?? ''));
    return [
        'host' => $m[3],
        'port' => ($m[4] ?? '') !== '' ? $m[4] : '3306',
        'user' => rawurldecode($m[1]),
        'pass' => rawurldecode($m[2] ?? ''),
        'base' => rawurldecode(trim($m[5] ?? '', '/')),
        'ssl'  => ($modo === 'disabled' || $modo === 'disable' || ($opciones['ssl'] ?? '') === 'false') ? false : null,
    ];
}

// Pista para salud.php cuando DATABASE_URL no se entiende (sin mostrar nada de su contenido)
function pistaDatabaseUrl($texto) {
    $t = trim((string) $texto);
    if ($t === '') return null;
    if (strpos($t, '<PASSWORD>') !== false || strpos($t, '<') !== false) return 'la línea todavía tiene <PASSWORD>: cámbialo por tu contraseña';
    if (!preg_match('#mysql2?://|mariadb://#i', $t) && !preg_match('/^(mysql|mariadb)\s+-/i', $t)) return 'no empieza por mysql:// (¿se pegó solo la contraseña u otro dato?)';
    if (substr_count($t, 'mysql://') > 1) return 'la línea está pegada dos veces';
    return 'la línea está incompleta: debe tener usuario, contraseña y servidor';
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

    $partes = leerDatabaseUrl((string) ($_ENV['DATABASE_URL'] ?? ''));
    if ($partes) {
        $c['host'] = $partes['host'];
        $c['port'] = $partes['port'];
        $c['user'] = $partes['user'];
        $c['pass'] = $partes['pass'];
        // Las bases que trae el servidor (test, sys, mysql…) no son para datos propios: se usa "datasense"
        $c['name'] = in_array(strtolower($partes['base']), ['', 'test', 'sys', 'mysql', 'information_schema', 'performance_schema', 'metrics_schema'], true)
            ? 'datasense' : $partes['base'];
        if ($partes['ssl'] === false) $c['ssl'] = false;
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
