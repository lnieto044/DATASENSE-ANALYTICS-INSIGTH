<?php
/**
 * REDES SOCIALES - API DEL PANEL (Publicación automática)
 * GET  accion=estado                  conexiones, cola y última revisión del publicador
 * GET  accion=imagen&id=N             afiche de una publicación de la cola
 * POST accion=conectar_meta           token de Meta (página o usuario administrador)
 * POST accion=conectar_linkedin       token y número de la página de empresa
 * POST accion=desconectar             red
 * GET  accion=tiktok_creador          cuenta de TikTok conectada y privacidades que permite (se muestran antes de publicar)
 * POST accion=programar               imagen (JPG), texto, fecha (AAAA-MM-DD HH:MM), redes, ref, tipo
 * POST accion=publicar_ahora          lo mismo, sin fecha: se publica en ese momento
 * POST accion=cancelar | reintentar   id
 * POST accion=cancelar_refs           refs (separadas por coma): cancela lo pendiente de esas publicaciones
 * Requiere el módulo "redes" y, en los POST, el token CSRF (lo agrega includes/sidebar.php a todo fetch).
 */
require_once __DIR__ . '/../includes/redes_publicar.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'redes', 'api');
asegurarTablasRedes($db);

function responder(array $datos, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$accion = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['accion'] ?? '') : ($_GET['accion'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($accion === 'imagen') {
        $stmt = $db->prepare("SELECT imagen FROM redes_programadas WHERE id = ?");
        $stmt->execute([(int) ($_GET['id'] ?? 0)]);
        $ruta = rutaImagenRedes($db, $stmt->fetchColumn());
        if (!$ruta) { http_response_code(404); exit; }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        readfile($ruta);
        exit;
    }
    if ($accion === 'tiktok_creador') {
        $r = creadorTiktok($db);
        responder($r, $r['ok'] ? 200 : 422);
    }
    $ultima = ultimaRevisionRedes();
    responder(['conexiones' => conexionesRedes($db), 'entorno' => sincronizarConexionesEntorno($db), 'cola' => colaRedes($db),
               'ultima_revision' => $ultima, 'hace' => $ultima ? time() - $ultima : null, 'tiktok_configurado' => tiktokConfigurado()]);
}

$id = (int) ($_POST['id'] ?? 0);
switch ($accion) {
    case 'conectar_meta':
        $r = conectarMeta($db, $_POST['token'] ?? '', $usuario['id']);
        responder($r, $r['ok'] ? 200 : 422);
    case 'conectar_linkedin':
        $r = conectarLinkedin($db, $_POST['token'] ?? '', $_POST['organizacion'] ?? '', $usuario['id']);
        responder($r, $r['ok'] ? 200 : 422);
    case 'desconectar':
        $red = $_POST['red'] ?? '';
        if (!isset(REDES_AUTO[$red])) responder(['ok' => false, 'mensaje' => 'Red no válida.'], 422);
        if (isset(sincronizarConexionesEntorno($db)[$red === 'instagram' ? 'facebook' : $red])) {
            responder(['ok' => false, 'mensaje' => 'Esta cuenta se conecta con un token de la configuración del servidor: para quitarla, borra ese token del archivo .env (o de Environment en Render).'], 422);
        }
        if ($red === 'tiktok') { revocarTiktok($db); responder(['ok' => true]); }
        quitarConexion($db, $red);
        if ($red === 'facebook') quitarConexion($db, 'instagram'); // Instagram publica con el token de la página
        responder(['ok' => true]);
    case 'programar':
    case 'publicar_ahora':
        @set_time_limit(180);
        $datos = $_POST;
        if ($accion === 'publicar_ahora') $datos['fecha'] = date('Y-m-d H:i');
        $r = programarPublicacion($db, $datos, $_FILES['imagen'] ?? [], $usuario['id']);
        if (!$r['ok']) responder($r, 422);
        if ($accion === 'publicar_ahora') {
            $proceso = procesarColaRedes($db, 1, $r['id']);
            $r['publicacion'] = $proceso['publicaciones'][0] ?? null;
        }
        responder($r);
    case 'cancelar':
        $stmt = $db->prepare("UPDATE redes_programadas SET estado = 'Cancelada' WHERE id = ? AND estado IN ('Programada', 'Error', 'Parcial')");
        $stmt->execute([$id]);
        responder(['ok' => $stmt->rowCount() === 1]);
    case 'reintentar':
        $stmt = $db->prepare("UPDATE redes_programadas SET estado = 'Programada', intentos = 0, proximo = NULL,
                              fecha = GREATEST(fecha, ?) WHERE id = ? AND estado IN ('Error', 'Parcial')");
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
        responder(['ok' => $stmt->rowCount() === 1]);
    case 'cancelar_refs':
        $refs = array_values(array_filter(array_map(function ($r) { return preg_replace('/[^a-z0-9-]/', '', strtolower(trim($r))); }, explode(',', (string) ($_POST['refs'] ?? '')))));
        if (!$refs) responder(['ok' => true, 'canceladas' => 0]);
        $marcas = implode(',', array_fill(0, count($refs), '?'));
        $stmt = $db->prepare("UPDATE redes_programadas SET estado = 'Cancelada' WHERE estado = 'Programada' AND ref IN ($marcas)");
        $stmt->execute($refs);
        responder(['ok' => true, 'canceladas' => $stmt->rowCount()]);
}
responder(['ok' => false, 'mensaje' => 'Acción no válida.'], 400);
