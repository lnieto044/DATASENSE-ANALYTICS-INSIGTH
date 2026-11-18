<?php
/**
 * PREPARAR LA BASE PERMANENTE (externa)
 * Lo corre deploy/entrypoint.sh al arrancar el servidor cuando la base está fuera del contenedor (DATABASE_URL o
 * DB_HOST de otro servidor, por ejemplo TiDB Cloud). Con la base local de la demostración no hace nada.
 *
 *   1. Espera a que la base conteste (hasta un minuto) y crea la base de datos si no existe.
 *   2. Si está vacía, carga los datos de demostración UNA sola vez (/opt/datasense/datasense_demo.sql), pone sus
 *      fechas al mes en curso y cambia la clave pública de las cuentas de ejemplo por una al azar (nadie la conoce),
 *      salvo la de admin@example.com, que queda para el primer ingreso.
 *      Una base que ya tiene datos no se toca nunca: lo que se guarde en ella es permanente.
 *   3. Crea, si faltan, las tablas de sesiones e intentos fallidos (includes/seguridad.php).
 *
 * Uso: php cron/preparar_base.php      (sale con código 1 si la base no responde)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/conexion.php';

const VOLCADO_DEMO = '/opt/datasense/datasense_demo.sql';

function aviso($texto) {
    echo date('Y-m-d H:i:s') . " Base permanente: $texto\n";
}

// Divide un volcado SQL en sentencias, respetando textos entre comillas y comentarios
function sentenciasSql($sql) {
    $sentencias = [];
    $actual = '';
    $largo = strlen($sql);
    for ($i = 0; $i < $largo; $i++) {
        $car = $sql[$i];
        $sig = $i + 1 < $largo ? $sql[$i + 1] : '';
        if ($car === '-' && $sig === '-' && ($i + 2 >= $largo || ctype_space($sql[$i + 2]))) {   // comentario de línea
            $fin = strpos($sql, "\n", $i);
            $i = $fin === false ? $largo : $fin;
            $actual .= "\n";
            continue;
        }
        if ($car === '/' && $sig === '*') {                                                       // comentario de bloque
            $fin = strpos($sql, '*/', $i + 2);
            $fin = $fin === false ? $largo : $fin + 2;
            $actual .= substr($sql, $i, $fin - $i);   // se conserva: /*!40101 ...*/ y /*T! ...*/ son instrucciones
            $i = $fin - 1;
            continue;
        }
        if ($car === "'" || $car === '"' || $car === '`') {                                       // texto entre comillas
            $j = $i + 1;
            while ($j < $largo) {
                if ($sql[$j] === '\\' && $car !== '`') { $j += 2; continue; }
                if ($sql[$j] === $car) {
                    if ($j + 1 < $largo && $sql[$j + 1] === $car) { $j += 2; continue; }   // comilla doblada
                    break;
                }
                $j++;
            }
            $actual .= substr($sql, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        if ($car === ';') {
            if (trim($actual) !== '') $sentencias[] = trim($actual);
            $actual = '';
            continue;
        }
        $actual .= $car;
    }
    if (trim($actual) !== '') $sentencias[] = trim($actual);
    return $sentencias;
}

$c = configuracionBaseDatos();
if (!$c['externa']) {
    aviso('no aplica (la base está dentro de este servidor)');
    exit(0);
}

// ---------------------------------------------------------------- 1. Conexión
$servidor = null;
for ($intento = 1; $intento <= 12 && !$servidor; $intento++) {
    try {
        $servidor = conectarBaseDatos($c, 10, false);
    } catch (Throwable $e) {
        aviso("sin respuesta de {$c['host']} (intento $intento): " . $e->getMessage());
        sleep(5);
    }
}
if (!$servidor) {
    fwrite(STDERR, "La base permanente no respondió. Revisa DATABASE_URL y que el servicio acepte conexiones.\n");
    exit(1);
}
$servidor->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '``', $c['name']) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$db = conectarBaseDatos($c);
$es_tidb = stripos((string) $db->query("SELECT VERSION()")->fetchColumn(), 'tidb') !== false;

// ---------------------------------------------------------------- 2. Datos iniciales (una sola vez)
$tablas = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
$estado = null;
if (in_array('ds_instalacion', $tablas, true)) {
    $estado = $db->query("SELECT estado FROM ds_instalacion WHERE id = 1")->fetchColumn() ?: null;
}
// Se carga si está vacía, o si una carga anterior quedó a medias. Una base con datos propios no se toca.
$cargar = count(array_diff($tablas, ['ds_instalacion', 'ds_sesiones', 'ds_intentos'])) === 0 || $estado === 'cargando';

if ($cargar) {
    if (!is_readable(VOLCADO_DEMO)) {
        fwrite(STDERR, "No se encontró " . VOLCADO_DEMO . "\n");
        exit(1);
    }
    aviso('base vacía: se cargan los datos de demostración (una sola vez)');
    $db->exec("CREATE TABLE IF NOT EXISTS ds_instalacion (id TINYINT PRIMARY KEY, estado VARCHAR(20) NOT NULL, actualizada DATETIME NOT NULL)");
    $db->exec("REPLACE INTO ds_instalacion (id, estado, actualizada) VALUES (1, 'cargando', NOW())");
    // Carga anterior interrumpida: se empieza de cero
    foreach (array_diff($tablas, ['ds_instalacion', 'ds_sesiones', 'ds_intentos']) as $tabla) {
        $db->exec("DROP TABLE IF EXISTS `" . str_replace('`', '``', $tabla) . "`");
    }

    $total = 0;
    foreach (sentenciasSql(file_get_contents(VOLCADO_DEMO)) as $sentencia) {
        // TiDB numera los registros en bloques por servidor (saltos de miles); así numera seguido, como MySQL
        if ($es_tidb && stripos($sentencia, 'CREATE TABLE') === 0) {
            $sentencia = preg_replace('/\)\s*ENGINE=InnoDB/i', ') ENGINE=InnoDB /*T![auto_id_cache] AUTO_ID_CACHE=1 */', $sentencia, 1);
        }
        $db->exec($sentencia);
        $total++;
    }
    aviso("$total instrucciones cargadas");

    // Las cuentas de ejemplo comparten una clave publicada en el README: en una base permanente cualquiera podría
    // entrar con ella y quedarse con la cuenta al configurar su propio celular. Reciben una clave al azar que nadie
    // conoce (siguen activas, así la demostración se ve igual). admin@example.com conserva la suya para el primer
    // ingreso: después conviene cambiarla en Mi perfil. Para dar acceso a alguien, se crea su cuenta en Equipo.
    $cambiar = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
    $ejemplo = $db->query("SELECT id FROM usuarios WHERE correo LIKE '%@example.com' AND correo <> 'admin@example.com'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ejemplo as $id) {
        $cambiar->execute([password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $id]);
    }
    aviso(count($ejemplo) . ' cuentas de ejemplo con clave al azar (solo admin@example.com conserva la publicada)');

    // Fechas de los datos de prueba al mes en curso, solo esta vez: después la base guarda fechas reales
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fechas_demo.php'), $resultado);
    if ($resultado !== 0) aviso('no se pudieron poner al día las fechas de la demostración (los datos quedan igual)');
    $db->exec("UPDATE ds_instalacion SET estado = 'lista', actualizada = NOW() WHERE id = 1");
} elseif ($estado === null) {
    aviso('la base ya tenía datos: no se modifica');
} else {
    aviso('datos guardados intactos');
}

// ---------------------------------------------------------------- 3. Sesiones e intentos fallidos
$db->exec("CREATE TABLE IF NOT EXISTS ds_sesiones (
    id CHAR(64) NOT NULL PRIMARY KEY,
    datos MEDIUMTEXT NOT NULL,
    actualizada INT NOT NULL,
    KEY idx_actualizada (actualizada)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
$db->exec("CREATE TABLE IF NOT EXISTS ds_intentos (
    clave CHAR(64) NOT NULL PRIMARY KEY,
    total INT NOT NULL,
    desde INT NOT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
aviso('lista en ' . $c['host'] . ($c['ssl'] ? ' (conexión cifrada)' : ''));
