<?php
/**
 * PUBLICACIÓN AUTOMÁTICA EN REDES SOCIALES
 * La plataforma publica sola en la fecha programada, sin que nadie tenga que entrar a cada red:
 *   - Facebook (página de la empresa) y su cuenta de Instagram profesional, con la API Graph de Meta.
 *   - LinkedIn (página de la empresa), con la API de publicaciones de LinkedIn.
 *
 * Cómo funciona:
 *   1. En Redes Sociales › Publicación automática se conectan las cuentas pegando un token de acceso (se guarda cifrado).
 *   2. "Programar el plan" sube cada afiche (JPG) con su texto, sus redes y su fecha a la tabla redes_programadas.
 *   3. El publicador (cron/publicar_redes.php) revisa la cola cada minuto y publica lo que ya llegó a su fecha.
 *      Si una red falla, reintenta hasta 3 veces (a los 15 y 30 minutos) y deja el error a la vista.
 *
 * Instagram solo acepta imágenes por dirección pública: el afiche se sube primero a la página de Facebook sin publicar
 * y se usa la dirección que entrega Facebook. Así funciona aunque la plataforma no tenga una dirección pública.
 * Las historias se publican solo en Instagram.
 *
 * Los tokens también pueden quedar en la configuración del servidor, y entonces el panel no los pide:
 *   META_PAGE_TOKEN=...     token de la página de Facebook (o de un usuario que la administra)
 *   LINKEDIN_TOKEN=...      token de LinkedIn con w_organization_social
 *   LINKEDIN_ORG_ID=...     número de la página de empresa en LinkedIn
 * En la copia local van en el archivo .env (Git no lo sube) y en Render en Environment. Nunca en el código:
 * el repositorio es público y con un token cualquiera podría publicar a nombre de la empresa.
 *
 * Variables opcionales del .env (para cambiar de versión de API o para pruebas):
 *   META_GRAPH_VERSION=v24.0   META_GRAPH_URL=https://graph.facebook.com
 *   META_APP_ID / META_APP_SECRET   si están, un token corto de usuario se cambia por uno de larga duración
 *   LINKEDIN_VERSION=202608    LINKEDIN_API_URL=https://api.linkedin.com
 */
require_once __DIR__ . '/doble_factor.php';   // cifrarSecreto() y descifrarSecreto()
require_once __DIR__ . '/utilidades.php';     // urlBase(): dirección de regreso de TikTok

const REDES_AUTO = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok'];
// Quién puede ver una publicación de TikTok (la persona elige una de las que TikTok ofrece para su cuenta)
const TIKTOK_PRIVACIDAD = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];
const REDES_MAX_INTENTOS = 3;
const REDES_ESTADOS = ['Programada', 'Publicando', 'Publicada', 'Parcial', 'Error', 'Cancelada'];

function carpetaRedes() {
    $dir = __DIR__ . '/../uploads/redes';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function asegurarTablasRedes(PDO $db) {
    static $listas = false;
    if ($listas) return;
    $db->exec("CREATE TABLE IF NOT EXISTS redes_conexiones (
        red VARCHAR(20) NOT NULL PRIMARY KEY,
        cuenta_id VARCHAR(80) NOT NULL,
        nombre VARCHAR(150) NOT NULL DEFAULT '',
        token TEXT NOT NULL,
        conectado_por INT DEFAULT NULL,
        actualizado DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS redes_programadas (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ref VARCHAR(40) NOT NULL DEFAULT '',
        redes VARCHAR(60) NOT NULL,
        tipo VARCHAR(10) NOT NULL DEFAULT 'feed',
        fecha DATETIME NOT NULL,
        texto TEXT NOT NULL,
        imagen VARCHAR(80) NOT NULL,
        estado VARCHAR(12) NOT NULL DEFAULT 'Programada',
        resultado TEXT DEFAULT NULL,
        intentos TINYINT NOT NULL DEFAULT 0,
        proximo DATETIME DEFAULT NULL,
        bloqueado_en DATETIME DEFAULT NULL,
        creado_por INT DEFAULT NULL,
        creado DATETIME NOT NULL,
        publicado DATETIME DEFAULT NULL,
        KEY idx_redes_estado_fecha (estado, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Copia de cada afiche en la base (en base64): en Vercel la carpeta uploads se borra cuando el servidor se reinicia,
    // y sin esta copia una publicación programada para otro día se quedaría sin imagen
    $db->exec("CREATE TABLE IF NOT EXISTS redes_imagenes (
        imagen VARCHAR(80) NOT NULL PRIMARY KEY,
        datos MEDIUMTEXT CHARACTER SET ascii NOT NULL,
        creado DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // huella: resumen del token de la configuración del servidor con el que se conectó (vacío si se pegó en el panel)
    if (!$db->query("SHOW COLUMNS FROM redes_conexiones LIKE 'huella'")->fetch()) {
        $db->exec("ALTER TABLE redes_conexiones ADD COLUMN huella VARCHAR(64) DEFAULT NULL");
    }
    // opciones: ajustes de cada red para esa publicación (TikTok: privacidad, comentarios y contenido comercial)
    if (!$db->query("SHOW COLUMNS FROM redes_programadas LIKE 'opciones'")->fetch()) {
        $db->exec("ALTER TABLE redes_programadas ADD COLUMN opciones TEXT DEFAULT NULL");
    }
    $listas = true;
}

// Guarda en la base la copia de un afiche que ya está en la carpeta de redes
function guardarImagenRedes(PDO $db, $nombre, $ruta) {
    $datos = @file_get_contents($ruta);
    if ($datos === false || $datos === '') return false;
    try {
        $db->prepare("REPLACE INTO redes_imagenes (imagen, datos, creado) VALUES (?, ?, ?)")
           ->execute([$nombre, base64_encode($datos), date('Y-m-d H:i:s')]);
        return true;
    } catch (Throwable $e) {
        error_log('No se pudo guardar en la base la copia del afiche ' . $nombre . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Ruta local de un afiche de la cola. Si el archivo ya no está en el servidor (Vercel lo reinició), lo vuelve a escribir
 * desde la copia de la base. Devuelve null si no hay imagen en ninguno de los dos lados.
 */
function rutaImagenRedes(PDO $db, $nombre) {
    $nombre = basename((string) $nombre);
    if ($nombre === '') return null;
    $ruta = carpetaRedes() . '/' . $nombre;
    if (is_file($ruta)) return $ruta;
    asegurarTablasRedes($db);
    $stmt = $db->prepare("SELECT datos FROM redes_imagenes WHERE imagen = ?");
    $stmt->execute([$nombre]);
    $datos = base64_decode((string) $stmt->fetchColumn(), true);
    if (!$datos || @file_put_contents($ruta, $datos) === false) return null;
    return $ruta;
}

/**
 * Conecta las redes con los tokens de la configuración del servidor (META_PAGE_TOKEN, LINKEDIN_TOKEN y
 * LINKEDIN_ORG_ID). Solo llama a las API cuando el token cambia; si uno falla, no lo vuelve a probar en 10 minutos.
 * Devuelve, por red configurada así, ['configurado' => true, 'error' => mensaje o ''].
 */
function sincronizarConexionesEntorno(PDO $db) {
    static $estado = null;
    if ($estado !== null) return $estado;
    $estado = [];
    $meta = trim((string) ($_ENV['META_PAGE_TOKEN'] ?? ''));
    $linkedin = trim((string) ($_ENV['LINKEDIN_TOKEN'] ?? ''));
    $organizacion = trim((string) ($_ENV['LINKEDIN_ORG_ID'] ?? ''));
    $casos = [];
    if ($meta !== '') {
        $casos['facebook'] = [$meta, ['facebook', 'instagram'], function () use ($db, $meta) { return conectarMeta($db, $meta, null); }];
    }
    if ($linkedin !== '' && $organizacion !== '') {
        $casos['linkedin'] = [$linkedin . '|' . $organizacion, ['linkedin'], function () use ($db, $linkedin, $organizacion) { return conectarLinkedin($db, $linkedin, $organizacion, null); }];
    }
    foreach ($casos as $red => [$valor, $redes, $conectar]) {
        $huella = hash('sha256', $valor);
        $stmt = $db->prepare("SELECT huella FROM redes_conexiones WHERE red = ?");
        $stmt->execute([$red]);
        if ($stmt->fetchColumn() === $huella) { $estado[$red] = ['configurado' => true, 'error' => '']; continue; }

        $marca = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'datasense_redes_fallo_' . substr($huella, 0, 16) . '.txt';
        if (is_file($marca) && time() - filemtime($marca) < 600) {
            $estado[$red] = ['configurado' => true, 'error' => (string) @file_get_contents($marca)];
            continue;
        }
        $r = $conectar();
        if ($r['ok']) {
            $marcas = implode(',', array_fill(0, count($redes), '?'));
            $db->prepare("UPDATE redes_conexiones SET huella = ? WHERE red IN ($marcas)")->execute(array_merge([$huella], $redes));
            @unlink($marca);
            $estado[$red] = ['configurado' => true, 'error' => ''];
        } else {
            @file_put_contents($marca, $r['mensaje']);
            @chmod($marca, 0666);
            $estado[$red] = ['configurado' => true, 'error' => $r['mensaje']];
        }
    }
    return $estado;
}

// ================================================================
// HTTP
// ================================================================

function urlGraph($ruta) {
    $base = rtrim((string) ($_ENV['META_GRAPH_URL'] ?? '') ?: 'https://graph.facebook.com', '/');
    $version = trim((string) ($_ENV['META_GRAPH_VERSION'] ?? '')) ?: 'v24.0';
    return $base . '/' . $version . '/' . ltrim($ruta, '/');
}

function urlLinkedin($ruta) {
    return rtrim((string) ($_ENV['LINKEDIN_API_URL'] ?? '') ?: 'https://api.linkedin.com', '/') . '/' . ltrim($ruta, '/');
}

function cabecerasLinkedin($token) {
    return ['Authorization: Bearer ' . $token, 'LinkedIn-Version: ' . (trim((string) ($_ENV['LINKEDIN_VERSION'] ?? '')) ?: '202608'),
            'X-Restli-Protocol-Version: 2.0.0'];
}

/**
 * Petición HTTP. $op: consulta (array), campos (array, se envía como multipart si trae CURLFile), json (array),
 * binario (string), cabeceras (array). Devuelve codigo, cuerpo, json, cabeceras (en minúsculas) y error de red.
 */
function pedirHttp($metodo, $url, array $op = []) {
    if (!empty($op['consulta'])) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($op['consulta']);
    $cabeceras = $op['cabeceras'] ?? [];
    $recibidas = [];
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90,
        CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$recibidas) {
            $partes = explode(':', $linea, 2);
            if (count($partes) === 2) $recibidas[strtolower(trim($partes[0]))] = trim($partes[1]);
            return strlen($linea);
        },
    ]);
    if (isset($op['json'])) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($op['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cabeceras[] = 'Content-Type: application/json';
    } elseif (isset($op['binario'])) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $op['binario']);
        $cabeceras[] = 'Content-Type: application/octet-stream';
    } elseif (isset($op['campos'])) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $op['campos']);
    }
    if ($cabeceras) curl_setopt($c, CURLOPT_HTTPHEADER, $cabeceras);
    $cuerpo = curl_exec($c);
    $error = $cuerpo === false ? curl_error($c) : '';
    $codigo = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    $json = is_string($cuerpo) ? json_decode($cuerpo, true) : null;
    return ['codigo' => $codigo, 'cuerpo' => (string) $cuerpo, 'json' => is_array($json) ? $json : null, 'cabeceras' => $recibidas, 'error' => $error];
}

// Mensaje legible de un error de la API (Meta: error.message; LinkedIn: message)
function errorApi(array $r, $red) {
    if ($r['error'] !== '') return 'No hubo conexión con ' . $red . ': ' . $r['error'];
    $m = $r['json']['error']['message'] ?? ($r['json']['message'] ?? '');
    $m = trim(preg_replace('/\s+/', ' ', (string) $m));
    return $red . ' respondió ' . $r['codigo'] . ($m !== '' ? ': ' . mb_substr($m, 0, 300) : '');
}

function graph($metodo, $ruta, $token, array $op = []) {
    if ($metodo === 'GET') $op['consulta'] = ($op['consulta'] ?? []) + ['access_token' => $token];
    else $op['campos'] = ($op['campos'] ?? []) + ['access_token' => $token];
    return pedirHttp($metodo, urlGraph($ruta), $op);
}

// ================================================================
// CONEXIONES
// ================================================================

function conexionesRedes(PDO $db) {
    asegurarTablasRedes($db);
    sincronizarConexionesEntorno($db);
    $lista = [];
    foreach ($db->query("SELECT red, cuenta_id, nombre, actualizado, huella FROM redes_conexiones") as $fila) {
        $fila['servidor'] = !empty($fila['huella']); // conectada con un token de la configuración del servidor
        unset($fila['huella']);
        $lista[$fila['red']] = $fila;
    }
    return $lista;
}

function conexionRed(PDO $db, $red) {
    asegurarTablasRedes($db);
    sincronizarConexionesEntorno($db);
    $stmt = $db->prepare("SELECT * FROM redes_conexiones WHERE red = ?");
    $stmt->execute([$red]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) return null;
    $fila['token'] = descifrarSecreto($fila['token']);
    return $fila['token'] ? $fila : null;
}

function guardarConexion(PDO $db, $red, $cuenta_id, $nombre, $token, $usuario_id) {
    $db->prepare("REPLACE INTO redes_conexiones (red, cuenta_id, nombre, token, conectado_por, actualizado, huella) VALUES (?, ?, ?, ?, ?, ?, NULL)")
       ->execute([$red, (string) $cuenta_id, mb_substr((string) $nombre, 0, 150), cifrarSecreto($token), $usuario_id, date('Y-m-d H:i:s')]);
}

function quitarConexion(PDO $db, $red) {
    asegurarTablasRedes($db);
    $db->prepare("DELETE FROM redes_conexiones WHERE red = ?")->execute([$red]);
}

/**
 * Conecta Facebook (y su Instagram, si la página tiene una cuenta profesional vinculada) con un token de Meta.
 * Sirve el token de la página o el de un usuario que administra la página (se toma la página de ese usuario).
 */
function conectarMeta(PDO $db, $token, $usuario_id) {
    asegurarTablasRedes($db);
    $token = trim((string) $token);
    if (strlen($token) < 20) return ['ok' => false, 'mensaje' => 'Pega el token de acceso completo.'];

    // Token corto de usuario + datos de la app: se cambia por uno de larga duración (las páginas heredan uno sin vencimiento)
    $app_id = trim((string) ($_ENV['META_APP_ID'] ?? ''));
    $app_secreto = trim((string) ($_ENV['META_APP_SECRET'] ?? ''));

    $campos = 'id,name,instagram_business_account{id,username}';
    $r = graph('GET', 'me', $token, ['consulta' => ['fields' => $campos]]);
    if ($r['codigo'] === 200 && !empty($r['json']['id'])) {
        $pagina = $r['json'] + ['access_token' => $token];
    } else {
        // No es un token de página: se intenta como token de usuario y se busca la página que administra
        if ($app_id !== '' && $app_secreto !== '') {
            $largo = pedirHttp('GET', urlGraph('oauth/access_token'), ['consulta' => ['grant_type' => 'fb_exchange_token',
                'client_id' => $app_id, 'client_secret' => $app_secreto, 'fb_exchange_token' => $token]]);
            if (!empty($largo['json']['access_token'])) $token = $largo['json']['access_token'];
        }
        $cuentas = graph('GET', 'me/accounts', $token, ['consulta' => ['fields' => $campos . ',access_token', 'limit' => 50]]);
        $paginas = $cuentas['json']['data'] ?? null;
        if ($cuentas['codigo'] !== 200 || !is_array($paginas)) return ['ok' => false, 'mensaje' => errorApi($r['codigo'] ? $r : $cuentas, 'Facebook')];
        if (!$paginas) return ['ok' => false, 'mensaje' => 'Ese usuario no administra ninguna página de Facebook (o el token no tiene el permiso pages_show_list).'];
        $pagina = $paginas[0];
        $preferida = trim((string) ($_ENV['META_PAGE_ID'] ?? ''));
        foreach ($paginas as $p) if ($preferida !== '' && (string) $p['id'] === $preferida) $pagina = $p;
    }
    if (empty($pagina['access_token'])) return ['ok' => false, 'mensaje' => 'No se obtuvo el token de la página.'];

    guardarConexion($db, 'facebook', $pagina['id'], $pagina['name'] ?? 'Página de Facebook', $pagina['access_token'], $usuario_id);
    $ig = $pagina['instagram_business_account'] ?? null;
    if (!empty($ig['id'])) {
        guardarConexion($db, 'instagram', $ig['id'], '@' . ($ig['username'] ?? $ig['id']), $pagina['access_token'], $usuario_id);
    } else {
        quitarConexion($db, 'instagram');
    }
    return ['ok' => true, 'facebook' => $pagina['name'] ?? '', 'instagram' => !empty($ig['id']) ? '@' . ($ig['username'] ?? $ig['id']) : null,
            'mensaje' => 'Facebook conectado: ' . ($pagina['name'] ?? '') . (!empty($ig['id']) ? '. Instagram conectado: @' . ($ig['username'] ?? '') . '.'
                         : '. La página no tiene una cuenta de Instagram profesional vinculada, así que Instagram queda sin conectar.')];
}

// Conecta LinkedIn con el token (permiso w_organization_social) y el número de la página de empresa
function conectarLinkedin(PDO $db, $token, $organizacion, $usuario_id) {
    asegurarTablasRedes($db);
    $token = trim((string) $token);
    // Se acepta el número, la URN o la dirección del panel de administración (linkedin.com/company/12345678/admin)
    $org = preg_match('/(\d{3,})/', (string) $organizacion, $m) ? $m[1] : '';
    if (strlen($token) < 20) return ['ok' => false, 'mensaje' => 'Pega el token de acceso completo.'];
    if ($org === '') return ['ok' => false, 'mensaje' => 'Escribe el número de la página de empresa (está en la dirección del panel de administración).'];

    $r = pedirHttp('GET', urlLinkedin('rest/organizations/' . $org), ['cabeceras' => cabecerasLinkedin($token)]);
    if ($r['codigo'] === 401) return ['ok' => false, 'mensaje' => 'LinkedIn rechazó el token: venció o está mal copiado.'];
    $nombre = $r['codigo'] === 200 ? ($r['json']['localizedName'] ?? ('Página ' . $org)) : ('Página ' . $org);
    guardarConexion($db, 'linkedin', $org, $nombre, $token, $usuario_id);
    $aviso = $r['codigo'] === 200 ? '' : ' No se pudo leer el nombre de la página (' . errorApi($r, 'LinkedIn') . '); se comprobará en la primera publicación.';
    return ['ok' => true, 'mensaje' => 'LinkedIn conectado: ' . $nombre . '.' . $aviso];
}

// ================================================================
// PUBLICAR EN CADA RED
// ================================================================

function publicarFacebook(array $con, $ruta, $texto) {
    $r = graph('POST', $con['cuenta_id'] . '/photos', $con['token'], ['campos' => [
        'source' => new CURLFile($ruta, 'image/jpeg', 'afiche.jpg'), 'message' => $texto, 'published' => 'true']]);
    if ($r['codigo'] !== 200 || empty($r['json']['id'])) return ['ok' => false, 'error' => errorApi($r, 'Facebook')];
    $id = $r['json']['post_id'] ?? $r['json']['id'];
    return ['ok' => true, 'id' => $id, 'enlace' => 'https://www.facebook.com/' . $id];
}

// Dirección pública del afiche para Instagram: se sube a la página de Facebook sin publicarlo
function urlImagenEnFacebook(array $con, $ruta) {
    $r = graph('POST', $con['cuenta_id'] . '/photos', $con['token'], ['campos' => [
        'source' => new CURLFile($ruta, 'image/jpeg', 'afiche.jpg'), 'published' => 'false']]);
    if ($r['codigo'] !== 200 || empty($r['json']['id'])) return ['ok' => false, 'error' => errorApi($r, 'Facebook (imagen para Instagram)')];
    $foto = graph('GET', $r['json']['id'], $con['token'], ['consulta' => ['fields' => 'images']]);
    $imagenes = $foto['json']['images'] ?? [];
    usort($imagenes, function ($a, $b) { return (int) ($b['width'] ?? 0) <=> (int) ($a['width'] ?? 0); });
    if (empty($imagenes[0]['source'])) return ['ok' => false, 'error' => errorApi($foto, 'Facebook (imagen para Instagram)')];
    return ['ok' => true, 'url' => $imagenes[0]['source']];
}

function publicarInstagram(array $con, $facebook, $ruta, $texto, $tipo) {
    if (!$facebook) return ['ok' => false, 'error' => 'Instagram necesita la página de Facebook conectada.', 'definitivo' => true];
    $imagen = urlImagenEnFacebook($facebook, $ruta);
    if (!$imagen['ok']) return $imagen;

    $campos = ['image_url' => $imagen['url']];
    if ($tipo === 'historia') $campos['media_type'] = 'STORIES';
    else $campos['caption'] = $texto;
    $contenedor = graph('POST', $con['cuenta_id'] . '/media', $con['token'], ['campos' => $campos]);
    if ($contenedor['codigo'] !== 200 || empty($contenedor['json']['id'])) return ['ok' => false, 'error' => errorApi($contenedor, 'Instagram')];
    $creacion = $contenedor['json']['id'];

    // Instagram procesa la imagen antes de dejarla publicar
    for ($i = 0; $i < 8; $i++) {
        $estado = graph('GET', $creacion, $con['token'], ['consulta' => ['fields' => 'status_code']]);
        $codigo = $estado['json']['status_code'] ?? '';
        if ($codigo === 'FINISHED') break;
        if ($codigo === 'ERROR' || $codigo === 'EXPIRED') return ['ok' => false, 'error' => 'Instagram no pudo procesar la imagen (' . $codigo . ').'];
        sleep(2);
    }
    $publicada = graph('POST', $con['cuenta_id'] . '/media_publish', $con['token'], ['campos' => ['creation_id' => $creacion]]);
    if ($publicada['codigo'] !== 200 || empty($publicada['json']['id'])) return ['ok' => false, 'error' => errorApi($publicada, 'Instagram')];
    $id = $publicada['json']['id'];
    $enlace = graph('GET', $id, $con['token'], ['consulta' => ['fields' => 'permalink']]);
    return ['ok' => true, 'id' => $id, 'enlace' => $enlace['json']['permalink'] ?? 'https://www.instagram.com/'];
}

// LinkedIn interpreta algunos signos como formato: se escapan, y las etiquetas #Palabra quedan como hashtags reales
function textoLinkedin($texto) {
    // Solo es etiqueta el # que empieza una palabra: el de una dirección (sitio.com/#contacto) se deja como texto
    $partes = preg_split('/(?<!\S)#([\p{L}\p{N}_]+)/u', (string) $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
    $salida = '';
    foreach ($partes as $i => $parte) {
        if ($i % 2 === 1) $salida .= '{hashtag|\\#|' . $parte . '}';
        else $salida .= preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $parte);
    }
    return $salida;
}

function publicarLinkedin(array $con, $ruta, $texto) {
    $autor = 'urn:li:organization:' . $con['cuenta_id'];
    $cab = cabecerasLinkedin($con['token']);
    $inicio = pedirHttp('POST', urlLinkedin('rest/images?action=initializeUpload'), ['cabeceras' => $cab, 'json' => ['initializeUploadRequest' => ['owner' => $autor]]]);
    $subida = $inicio['json']['value']['uploadUrl'] ?? '';
    $imagen = $inicio['json']['value']['image'] ?? '';
    if ($inicio['codigo'] !== 200 || $subida === '' || $imagen === '') return ['ok' => false, 'error' => errorApi($inicio, 'LinkedIn')];

    $carga = pedirHttp('PUT', $subida, ['cabeceras' => ['Authorization: Bearer ' . $con['token']], 'binario' => (string) file_get_contents($ruta)]);
    if ($carga['codigo'] < 200 || $carga['codigo'] >= 300) return ['ok' => false, 'error' => errorApi($carga, 'LinkedIn (imagen)')];

    $post = pedirHttp('POST', urlLinkedin('rest/posts'), ['cabeceras' => $cab, 'json' => [
        'author' => $autor, 'commentary' => textoLinkedin($texto), 'visibility' => 'PUBLIC',
        'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
        'content' => ['media' => ['id' => $imagen]], 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false,
    ]]);
    if ($post['codigo'] !== 201 && $post['codigo'] !== 200) return ['ok' => false, 'error' => errorApi($post, 'LinkedIn')];
    $urn = $post['cabeceras']['x-restli-id'] ?? ($post['json']['id'] ?? '');
    return ['ok' => true, 'id' => $urn, 'enlace' => $urn !== '' ? 'https://www.linkedin.com/feed/update/' . $urn . '/' : 'https://www.linkedin.com/'];
}

// ================================================================
// TIKTOK (Login Kit + Content Posting API, publicación directa de fotos)
// ================================================================
// Variables del servidor: TIKTOK_CLIENT_KEY y TIKTOK_CLIENT_SECRET (las de la app en TikTok for Developers; en pruebas,
// las del Sandbox). Opcional: TIKTOK_REDIRECT_URI si la dirección de regreso no es <sitio>/admin/tiktok_callback.php.
// La conexión guarda, cifrado, un JSON con el token de acceso (dura 24 horas), el de renovación (dura un año) y la
// dirección pública del sitio, que el publicador (sin navegador) necesita para que TikTok descargue la foto.
// Mientras TikTok no apruebe la app (auditoría), solo deja publicar en cuentas privadas y como «Solo yo».

function tiktokConfigurado() {
    return trim((string) ($_ENV['TIKTOK_CLIENT_KEY'] ?? '')) !== '' && trim((string) ($_ENV['TIKTOK_CLIENT_SECRET'] ?? '')) !== '';
}

function urlRegresoTiktok() {
    return trim((string) ($_ENV['TIKTOK_REDIRECT_URI'] ?? '')) ?: urlBase() . 'admin/tiktok_callback.php';
}

function urlAutorizacionTiktok($estado) {
    return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
        'client_key' => trim((string) $_ENV['TIKTOK_CLIENT_KEY']), 'scope' => 'user.info.basic,video.publish',
        'response_type' => 'code', 'redirect_uri' => urlRegresoTiktok(), 'state' => $estado,
    ]);
}

function apiTiktok($metodo, $ruta, $token, array $op = []) {
    $op['cabeceras'] = array_merge($op['cabeceras'] ?? [], ['Authorization: Bearer ' . $token]);
    return pedirHttp($metodo, 'https://open.tiktokapis.com/' . ltrim($ruta, '/'), $op);
}

// Mensaje legible de un error de TikTok, con los casos que se ven al probar una app sin auditoría
function errorTiktok(array $r) {
    if ($r['error'] !== '') return 'No hubo conexión con TikTok: ' . $r['error'];
    $codigo = (string) ($r['json']['error']['code'] ?? ($r['json']['error'] ?? ''));
    $mensaje = (string) ($r['json']['error']['message'] ?? ($r['json']['error_description'] ?? ''));
    $conocidos = [
        'unaudited_client_can_only_post_to_private_accounts' => 'TikTok todavía no ha aprobado la app: mientras tanto solo deja publicar si la cuenta de TikTok está en privado.',
        'privacy_level_option_mismatch' => 'TikTok no acepta esa privacidad para esta cuenta. Mientras la app no esté aprobada, elige «Solo yo».',
        'spam_risk_too_many_posts' => 'TikTok frenó la publicación por demasiadas publicaciones seguidas. Se reintentará más tarde.',
        'spam_risk_user_banned_from_posting' => 'TikTok no deja publicar en esta cuenta por ahora.',
        'url_ownership_unverified' => 'TikTok no puede descargar la imagen: falta verificar el dominio del sitio en Content Posting API › Verify domains.',
        'access_token_invalid' => 'La conexión con TikTok venció o se revocó. Conecta TikTok de nuevo.',
        'scope_not_authorized' => 'Al conectar TikTok no se aceptó el permiso para publicar. Conecta TikTok de nuevo y acepta todos los permisos.',
    ];
    if (isset($conocidos[$codigo])) return $conocidos[$codigo];
    return 'TikTok respondió ' . $r['codigo'] . ($codigo !== '' && $codigo !== 'ok' ? ' (' . $codigo . ')' : '') . ($mensaje !== '' ? ': ' . mb_substr($mensaje, 0, 250) : '');
}

function pedirTokenTiktok(array $campos) {
    return pedirHttp('POST', 'https://open.tiktokapis.com/v2/oauth/token/', [
        'campos' => http_build_query($campos + ['client_key' => trim((string) $_ENV['TIKTOK_CLIENT_KEY']), 'client_secret' => trim((string) $_ENV['TIKTOK_CLIENT_SECRET'])]),
        'cabeceras' => ['Content-Type: application/x-www-form-urlencoded', 'Cache-Control: no-cache'],
    ]);
}

// Paquete que se guarda cifrado como token de la conexión
function paqueteTiktok(array $t, $sitio) {
    return json_encode(['acceso' => $t['access_token'], 'renovacion' => $t['refresh_token'] ?? '', 'sitio' => $sitio,
        'vence' => time() + (int) ($t['expires_in'] ?? 86400), 'renovacion_vence' => time() + (int) ($t['refresh_expires_in'] ?? 31536000)]);
}

/**
 * Cambia el código que devuelve TikTok al autorizar por los tokens y guarda la conexión.
 */
function conectarTiktok(PDO $db, $codigo, $usuario_id) {
    asegurarTablasRedes($db);
    if (!tiktokConfigurado()) return ['ok' => false, 'mensaje' => 'Faltan TIKTOK_CLIENT_KEY y TIKTOK_CLIENT_SECRET en la configuración del servidor.'];
    $r = pedirTokenTiktok(['code' => (string) $codigo, 'grant_type' => 'authorization_code', 'redirect_uri' => urlRegresoTiktok()]);
    if ($r['codigo'] !== 200 || empty($r['json']['access_token'])) return ['ok' => false, 'mensaje' => errorTiktok($r)];
    $t = $r['json'];
    if (strpos((string) ($t['scope'] ?? ''), 'video.publish') === false) {
        return ['ok' => false, 'mensaje' => 'TikTok no dio el permiso para publicar (video.publish). Vuelve a conectar y acepta todos los permisos.'];
    }
    $nombre = 'TikTok';
    $perfil = apiTiktok('GET', 'v2/user/info/', $t['access_token'], ['consulta' => ['fields' => 'open_id,display_name,avatar_url']]);
    if (!empty($perfil['json']['data']['user']['display_name'])) $nombre = $perfil['json']['data']['user']['display_name'];
    guardarConexion($db, 'tiktok', $t['open_id'] ?? '', $nombre, paqueteTiktok($t, urlBase()), $usuario_id);
    return ['ok' => true, 'mensaje' => 'TikTok conectado: ' . $nombre . '.'];
}

// Token de acceso vigente: si vence en menos de 5 minutos lo renueva con el de renovación y guarda el nuevo
function tokenTiktok(PDO $db, array $con) {
    $p = json_decode((string) $con['token'], true);
    if (!is_array($p) || empty($p['acceso'])) return ['ok' => false, 'error' => 'La conexión con TikTok está incompleta. Conecta TikTok de nuevo.'];
    if ((int) ($p['vence'] ?? 0) - 300 > time()) return ['ok' => true, 'acceso' => $p['acceso'], 'sitio' => $p['sitio'] ?? ''];
    if (!tiktokConfigurado() || empty($p['renovacion']) || (int) ($p['renovacion_vence'] ?? 0) < time()) {
        return ['ok' => false, 'error' => 'La conexión con TikTok venció. Conecta TikTok de nuevo.'];
    }
    $r = pedirTokenTiktok(['grant_type' => 'refresh_token', 'refresh_token' => $p['renovacion']]);
    if ($r['codigo'] !== 200 || empty($r['json']['access_token'])) return ['ok' => false, 'error' => errorTiktok($r)];
    $paquete = paqueteTiktok($r['json'], $p['sitio'] ?? '');
    $db->prepare("UPDATE redes_conexiones SET token = ?, actualizado = ? WHERE red = 'tiktok'")->execute([cifrarSecreto($paquete), date('Y-m-d H:i:s')]);
    return ['ok' => true, 'acceso' => $r['json']['access_token'], 'sitio' => $p['sitio'] ?? ''];
}

/**
 * Datos del creador que TikTok exige mostrar antes de publicar: nombre, foto, privacidades permitidas y si la cuenta
 * tiene apagados los comentarios. Devuelve ['ok', 'creador' => [...]] o ['ok' => false, 'mensaje'].
 */
function creadorTiktok(PDO $db) {
    $con = conexionRed($db, 'tiktok');
    if (!$con) return ['ok' => false, 'mensaje' => 'TikTok no está conectado.'];
    $t = tokenTiktok($db, $con);
    if (!$t['ok']) return ['ok' => false, 'mensaje' => $t['error']];
    $r = apiTiktok('POST', 'v2/post/publish/creator_info/query/', $t['acceso'], ['json' => new stdClass()]);
    $d = $r['json']['data'] ?? null;
    if ($r['codigo'] !== 200 || !is_array($d) || ($r['json']['error']['code'] ?? 'ok') !== 'ok') return ['ok' => false, 'mensaje' => errorTiktok($r)];
    return ['ok' => true, 'creador' => [
        'usuario' => (string) ($d['creator_username'] ?? ''), 'nombre' => (string) ($d['creator_nickname'] ?? ''),
        'foto' => (string) ($d['creator_avatar_url'] ?? ''), 'privacidades' => array_values(array_intersect(TIKTOK_PRIVACIDAD, (array) ($d['privacy_level_options'] ?? []))),
        'sin_comentarios' => !empty($d['comment_disabled']),
    ]];
}

// Valida los ajustes de TikTok que eligió la persona al programar (no hay privacidad por defecto: TikTok exige elegirla)
function opcionesTiktok(array $tk) {
    $privacidad = (string) ($tk['privacidad'] ?? '');
    if (!in_array($privacidad, TIKTOK_PRIVACIDAD, true)) return ['ok' => false, 'mensaje' => 'Elige quién puede ver la publicación en TikTok.'];
    $marca_propia = !empty($tk['marca_propia']);
    $contenido_marca = !empty($tk['contenido_marca']);
    if (!empty($tk['comercial']) && !$marca_propia && !$contenido_marca) {
        return ['ok' => false, 'mensaje' => 'Si la publicación promociona una marca, indica si es tu marca, contenido de marca o ambas.'];
    }
    if ($contenido_marca && $privacidad === 'SELF_ONLY') {
        return ['ok' => false, 'mensaje' => 'TikTok no permite que el contenido de marca sea privado («Solo yo»).'];
    }
    return ['ok' => true, 'mensaje' => '', 'privacidad' => $privacidad, 'comentarios' => !empty($tk['comentarios']),
            'marca_propia' => $marca_propia, 'contenido_marca' => $contenido_marca];
}

// Dirección pública y firmada de un afiche de la cola (TikTok descarga la foto desde el sitio verificado)
function urlPublicaImagenRedes($sitio, $nombre) {
    return rtrim($sitio, '/') . '/redes_imagen.php?' . http_build_query(['f' => $nombre, 't' => firmaImagenRedes($nombre)]);
}

function firmaImagenRedes($nombre) {
    return substr(hash_hmac('sha256', 'redes-imagen|' . basename((string) $nombre), claveCifrado()), 0, 32);
}

// Título corto para TikTok (hasta 90 caracteres): la primera línea del texto, sin enlaces
function tituloTiktok($texto) {
    $linea = trim(strtok(str_replace("\r", '', (string) $texto), "\n") ?: '');
    $linea = trim(preg_replace('#https?://\S+#', '', $linea));
    return mb_strlen($linea) > 90 ? rtrim(mb_substr($linea, 0, 89)) . '…' : $linea;
}

/**
 * Publica un afiche como foto en TikTok (publicación directa) y espera hasta un minuto a que TikTok la procese.
 */
function publicarTiktok(PDO $db, array $con, $nombre_imagen, $texto, array $op) {
    if (empty($op['privacidad'])) return ['ok' => false, 'error' => 'Falta elegir quién puede verla en TikTok. Prográmala de nuevo.', 'definitivo' => true];
    $t = tokenTiktok($db, $con);
    if (!$t['ok']) return ['ok' => false, 'error' => $t['error'], 'definitivo' => true];
    if ($t['sitio'] === '') return ['ok' => false, 'error' => 'Conecta TikTok de nuevo desde el panel para guardar la dirección del sitio.', 'definitivo' => true];

    $creador = creadorTiktok($db);
    if (!$creador['ok']) return ['ok' => false, 'error' => $creador['mensaje']];
    if (!in_array($op['privacidad'], $creador['creador']['privacidades'], true)) {
        return ['ok' => false, 'error' => 'TikTok no permite esa privacidad para esta cuenta en este momento. Mientras la app no esté aprobada, usa «Solo yo».', 'definitivo' => true];
    }

    $inicio = apiTiktok('POST', 'v2/post/publish/content/init/', $t['acceso'], ['json' => [
        'post_info' => [
            'title' => tituloTiktok($texto), 'description' => mb_substr((string) $texto, 0, 4000),
            'privacy_level' => $op['privacidad'], 'disable_comment' => empty($op['comentarios']) || $creador['creador']['sin_comentarios'],
            'auto_add_music' => true, 'brand_organic_toggle' => !empty($op['marca_propia']), 'brand_content_toggle' => !empty($op['contenido_marca']),
        ],
        'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => [urlPublicaImagenRedes($t['sitio'], $nombre_imagen)]],
        'post_mode' => 'DIRECT_POST', 'media_type' => 'PHOTO',
    ]]);
    $id = $inicio['json']['data']['publish_id'] ?? '';
    if ($inicio['codigo'] !== 200 || $id === '') return ['ok' => false, 'error' => errorTiktok($inicio)];

    $perfil = $creador['creador']['usuario'] !== '' ? 'https://www.tiktok.com/@' . rawurlencode($creador['creador']['usuario']) : '';
    for ($i = 0; $i < 12; $i++) {
        sleep(5);
        $s = apiTiktok('POST', 'v2/post/publish/status/fetch/', $t['acceso'], ['json' => ['publish_id' => $id]]);
        $estado = (string) ($s['json']['data']['status'] ?? '');
        if ($estado === 'PUBLISH_COMPLETE') {
            $publicas = (array) ($s['json']['data']['publicaly_available_post_id'] ?? []);
            return ['ok' => true, 'id' => $id, 'enlace' => $publicas && $perfil !== '' ? $perfil . '/photo/' . rawurlencode((string) $publicas[0]) : $perfil];
        }
        if ($estado === 'FAILED') {
            return ['ok' => false, 'error' => 'TikTok no pudo publicarla (' . ($s['json']['data']['fail_reason'] ?? 'sin motivo') . ').'];
        }
    }
    // TikTok la recibió y la sigue procesando: queda como enviada para no duplicarla en un reintento
    return ['ok' => true, 'id' => $id, 'enlace' => $perfil, 'nota' => 'TikTok la está procesando; aparecerá en el perfil en unos minutos.'];
}

// Revoca el permiso en TikTok al desconectar (si falla, igual se borra la conexión local)
function revocarTiktok(PDO $db) {
    $con = conexionRed($db, 'tiktok');
    $p = $con ? json_decode((string) $con['token'], true) : null;
    if (is_array($p) && !empty($p['acceso']) && tiktokConfigurado()) {
        pedirHttp('POST', 'https://open.tiktokapis.com/v2/oauth/revoke/', [
            'campos' => http_build_query(['client_key' => trim((string) $_ENV['TIKTOK_CLIENT_KEY']), 'client_secret' => trim((string) $_ENV['TIKTOK_CLIENT_SECRET']), 'token' => $p['acceso']]),
            'cabeceras' => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
    }
    quitarConexion($db, 'tiktok');
}

// ================================================================
// COLA
// ================================================================

// Redes válidas para un tipo de publicación (las historias solo van a Instagram)
function redesValidas($redes, $tipo) {
    $lista = array_values(array_intersect(array_keys(REDES_AUTO), array_map('strtolower', array_map('trim', explode(',', (string) $redes)))));
    return $tipo === 'historia' ? array_values(array_intersect($lista, ['instagram'])) : $lista;
}

/**
 * Agrega una publicación a la cola. $archivo es la imagen subida ($_FILES[...]). Devuelve ['ok', 'id' | 'mensaje'].
 */
function programarPublicacion(PDO $db, array $datos, array $archivo, $usuario_id) {
    asegurarTablasRedes($db);
    $tipo = ($datos['tipo'] ?? '') === 'historia' ? 'historia' : 'feed';
    $redes = redesValidas($datos['redes'] ?? '', $tipo);
    $texto = trim(str_replace("\r\n", "\n", (string) ($datos['texto'] ?? '')));
    $fecha = DateTime::createFromFormat('Y-m-d H:i', (string) ($datos['fecha'] ?? ''));
    $ahora = new DateTime();

    if (!$redes) return ['ok' => false, 'mensaje' => $tipo === 'historia' ? 'Las historias se publican solo en Instagram.' : 'Elige al menos una red: Facebook, Instagram, LinkedIn o TikTok.'];
    $opciones = [];
    if (in_array('tiktok', $redes, true)) {
        $tk = json_decode((string) ($datos['tiktok'] ?? ''), true) ?: [];
        $opciones['tiktok'] = opcionesTiktok($tk);
        if (!$opciones['tiktok']['ok']) return ['ok' => false, 'mensaje' => $opciones['tiktok']['mensaje']];
        unset($opciones['tiktok']['ok'], $opciones['tiktok']['mensaje']);
    }
    if ($tipo === 'feed' && $texto === '') return ['ok' => false, 'mensaje' => 'La publicación no tiene texto.'];
    if (mb_strlen($texto) > 2200) return ['ok' => false, 'mensaje' => 'El texto supera los 2.200 caracteres que acepta Instagram.'];
    if (!$fecha) return ['ok' => false, 'mensaje' => 'La fecha no es válida.'];
    if ($fecha < (clone $ahora)->modify('-5 minutes')) return ['ok' => false, 'mensaje' => 'La fecha ' . $fecha->format('d/m/Y h:i a') . ' ya pasó. Elige una fecha futura.'];
    if ($fecha > (clone $ahora)->modify('+1 year')) return ['ok' => false, 'mensaje' => 'Programa con menos de un año de anticipación.'];

    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) return ['ok' => false, 'mensaje' => 'No llegó la imagen del afiche.'];
    if ($archivo['size'] > 8 * 1024 * 1024) return ['ok' => false, 'mensaje' => 'La imagen pesa más de 8 MB.'];
    $info = @getimagesize($archivo['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 320 || $info[0] > 4000 || $info[1] > 4000) return ['ok' => false, 'mensaje' => 'La imagen debe ser un JPG entre 320 y 4000 píxeles.'];

    $nombre = bin2hex(random_bytes(16)) . '.jpg';
    if (!move_uploaded_file($archivo['tmp_name'], carpetaRedes() . '/' . $nombre)) return ['ok' => false, 'mensaje' => 'No se pudo guardar la imagen en el servidor.'];
    guardarImagenRedes($db, $nombre, carpetaRedes() . '/' . $nombre);

    $db->prepare("INSERT INTO redes_programadas (ref, redes, tipo, fecha, texto, imagen, estado, creado_por, creado, opciones) VALUES (?, ?, ?, ?, ?, ?, 'Programada', ?, ?, ?)")
       ->execute([mb_substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($datos['ref'] ?? ''))), 0, 40), implode(',', $redes), $tipo,
                  $fecha->format('Y-m-d H:i:00'), $texto, $nombre, $usuario_id, $ahora->format('Y-m-d H:i:s'),
                  $opciones ? json_encode($opciones, JSON_UNESCAPED_UNICODE) : null]);
    return ['ok' => true, 'id' => (int) $db->lastInsertId()];
}

function archivoUltimaRevision() {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'datasense_redes_ultima_revision.txt';
}

function ultimaRevisionRedes() {
    $t = (int) @file_get_contents(archivoUltimaRevision());
    return $t > 0 ? $t : null;
}

/**
 * Publica lo que ya llegó a su fecha. $solo_id publica únicamente esa (botón "Publicar ahora").
 * Devuelve un resumen: cuántas se revisaron y el resultado de cada una.
 */
function procesarColaRedes(PDO $db, $limite = 5, $solo_id = null) {
    asegurarTablasRedes($db);
    // La comparten el publicador (línea de comandos) y el panel ("Publicar ahora"): ambos deben poder escribirla
    @file_put_contents(archivoUltimaRevision(), (string) time());
    @chmod(archivoUltimaRevision(), 0666);
    $ahora = date('Y-m-d H:i:s');

    // Una publicación que quedó a medias (el proceso se cortó) vuelve a la cola a los 15 minutos
    $db->prepare("UPDATE redes_programadas SET estado = 'Programada' WHERE estado = 'Publicando' AND bloqueado_en < ?")
       ->execute([date('Y-m-d H:i:s', time() - 900)]);

    // De vez en cuando se borran de la base las copias de afiches que ya no se van a publicar: las terminadas hace más
    // de 45 días (el panel muestra hasta esa fecha) y las que no tienen publicación en la cola
    if (mt_rand(1, 60) === 1) {
        try {
            $db->prepare("DELETE FROM redes_imagenes WHERE imagen NOT IN (SELECT imagen FROM redes_programadas
                          WHERE estado IN ('Programada', 'Publicando') OR fecha >= ?) AND creado < ?")
               ->execute([date('Y-m-d H:i:s', time() - 45 * 86400), date('Y-m-d H:i:s', time() - 86400)]);
        } catch (Throwable $e) {
            error_log('Limpieza de afiches de redes: ' . $e->getMessage());
        }
    }

    $sql = "SELECT id FROM redes_programadas WHERE estado = 'Programada' AND fecha <= ? AND (proximo IS NULL OR proximo <= ?)";
    $params = [$ahora, $ahora];
    if ($solo_id !== null) { $sql .= " AND id = ?"; $params[] = (int) $solo_id; }
    $stmt = $db->prepare($sql . " ORDER BY fecha, id LIMIT " . max(1, (int) $limite));
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $conexiones = [];
    foreach (array_keys(REDES_AUTO) as $red) $conexiones[$red] = conexionRed($db, $red);

    $resumen = [];
    foreach ($ids as $id) {
        // Se toma la publicación solo si sigue libre: dos publicadores al mismo tiempo no la duplican
        $toma = $db->prepare("UPDATE redes_programadas SET estado = 'Publicando', bloqueado_en = ?, intentos = intentos + 1 WHERE id = ? AND estado = 'Programada'");
        $toma->execute([date('Y-m-d H:i:s'), $id]);
        if ($toma->rowCount() !== 1) continue;

        $fila = $db->query("SELECT * FROM redes_programadas WHERE id = " . (int) $id)->fetch(PDO::FETCH_ASSOC);
        $resultado = json_decode((string) $fila['resultado'], true) ?: [];
        $ruta = rutaImagenRedes($db, $fila['imagen']);

        foreach (explode(',', $fila['redes']) as $red) {
            if (!empty($resultado[$red]['ok'])) continue; // ya salió en esa red en un intento anterior
            if (!$ruta) { $resultado[$red] = ['ok' => false, 'error' => 'No se encontró la imagen del afiche.', 'definitivo' => true]; continue; }
            $con = $conexiones[$red] ?? null;
            if (!$con) { $resultado[$red] = ['ok' => false, 'error' => REDES_AUTO[$red] . ' no está conectado.', 'definitivo' => true]; continue; }
            try {
                if ($red === 'facebook') $r = publicarFacebook($con, $ruta, $fila['texto']);
                elseif ($red === 'instagram') $r = publicarInstagram($con, $conexiones['facebook'], $ruta, $fila['texto'], $fila['tipo']);
                elseif ($red === 'tiktok') $r = publicarTiktok($db, $con, basename($fila['imagen']), $fila['texto'],
                                                                (json_decode((string) ($fila['opciones'] ?? ''), true) ?: [])['tiktok'] ?? []);
                else $r = publicarLinkedin($con, $ruta, $fila['texto']);
            } catch (Throwable $e) {
                $r = ['ok' => false, 'error' => 'Error interno: ' . $e->getMessage()];
            }
            $resultado[$red] = $r + ['fecha' => date('Y-m-d H:i:s')];
        }

        $bien = count(array_filter($resultado, function ($x) { return !empty($x['ok']); }));
        $reintentables = count(array_filter($resultado, function ($x) { return empty($x['ok']) && empty($x['definitivo']); }));
        $proximo = null;
        if ($bien === count($resultado)) {
            $estado = 'Publicada';
        } elseif ($reintentables > 0 && (int) $fila['intentos'] < REDES_MAX_INTENTOS) {
            $estado = 'Programada';
            $proximo = date('Y-m-d H:i:s', time() + 900 * (int) $fila['intentos']);
        } else {
            $estado = $bien > 0 ? 'Parcial' : 'Error';
        }
        $db->prepare("UPDATE redes_programadas SET estado = ?, resultado = ?, proximo = ?, bloqueado_en = NULL, publicado = ? WHERE id = ?")
           ->execute([$estado, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $proximo, $bien > 0 ? date('Y-m-d H:i:s') : null, $id]);
        $resumen[] = ['id' => (int) $id, 'estado' => $estado, 'resultado' => $resultado];
    }
    return ['revisadas' => count($resumen), 'publicaciones' => $resumen];
}

// Cola para el panel: las próximas y las recientes
function colaRedes(PDO $db) {
    asegurarTablasRedes($db);
    $filas = $db->query("SELECT id, ref, redes, tipo, fecha, texto, estado, resultado, intentos, proximo, publicado FROM redes_programadas
                         WHERE estado IN ('Programada', 'Publicando') OR fecha >= DATE_SUB(NOW(), INTERVAL 45 DAY)
                         ORDER BY (estado IN ('Programada', 'Publicando')) DESC, fecha ASC, id ASC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as &$f) {
        $f['resultado'] = json_decode((string) $f['resultado'], true) ?: new stdClass();
        $f['texto'] = mb_substr($f['texto'], 0, 160);
    }
    return $filas;
}
