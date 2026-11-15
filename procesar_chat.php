<?php
// procesar_chat.php (Debe estar en la carpeta raíz del proyecto)
require_once __DIR__ . '/includes/seguridad.php';
iniciarSesionSegura();
header('Content-Type: application/json');

// Conexión a la base de datos
$pdo = require __DIR__ . '/config/database.php';

// Solo peticiones POST enviadas desde el sitio (llevan el token de la sesión creado en index.php)
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['token']) || !is_string($token) || !hash_equals($_SESSION['token'], $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Solicitud no válida']);
    exit;
}

// Freno contra abuso: máximo 300 mensajes por hora desde una misma IP
if (registrarIntento('chat|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 3600) > 300) {
    http_response_code(429);
    echo json_encode(['error' => 'Demasiados mensajes, intenta más tarde']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode(['error' => 'No se recibieron datos']);
    exit;
}

// Recorta los textos para no guardar contenido desmedido
$recortar = function ($valor, $maximo) {
    return is_scalar($valor) ? mb_substr(trim((string) $valor), 0, $maximo) : '';
};

try {
    $nombre = $recortar($data['nombre'] ?? '', 100) ?: 'Desconocido';
    $empresa = $recortar($data['empresa'] ?? '', 150) ?: 'No especificada';
    $mensaje = $recortar($data['mensaje'] ?? '', 1000);
    $remitente = in_array($data['remitente'] ?? '', ['user', 'bot'], true) ? $data['remitente'] : 'user';

    // Solo se escribe en conversaciones creadas desde esta misma sesión del navegador
    $mis_chats = $_SESSION['chat_sesiones'] ?? [];
    $session_id = (int) ($data['session_id'] ?? 0);
    if (!in_array($session_id, $mis_chats, true)) {
        $session_id = 0;
    }

    // 1. Si no hay sesión creada aún, la creamos
    if (!$session_id) {
        if (count($mis_chats) >= 20) {
            http_response_code(429);
            echo json_encode(['error' => 'Demasiadas conversaciones, intenta más tarde']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO ds_chat_sessions (nombre_usuario, empresa_entidad, estado) VALUES (?, ?, 'activo')");
        $stmt->execute([$nombre, $empresa]);
        $session_id = (int) $pdo->lastInsertId();
        $_SESSION['chat_sesiones'][] = $session_id;
    } else {
        // Actualizamos el nombre y empresa si el usuario los acaba de dar
        $stmtUpdate = $pdo->prepare("UPDATE ds_chat_sessions SET nombre_usuario = ?, empresa_entidad = ? WHERE id = ?");
        $stmtUpdate->execute([$nombre, $empresa, $session_id]);
    }

    // 2. Guardamos el mensaje en el historial
    if ($mensaje !== '') {
        $stmtMsg = $pdo->prepare("INSERT INTO ds_chat_messages (session_id, remitente, mensaje) VALUES (?, ?, ?)");
        $stmtMsg->execute([$session_id, $remitente, $mensaje]);
    }

    echo json_encode(['success' => true, 'session_id' => $session_id]);

} catch (PDOException $e) {
    error_log("Error en ChatBot: " . $e->getMessage());
    echo json_encode(['error' => 'Error interno al guardar']);
}
