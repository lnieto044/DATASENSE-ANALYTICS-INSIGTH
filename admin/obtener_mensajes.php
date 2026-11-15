<?php
// obtener_mensajes.php
require_once __DIR__ . '/../includes/seguridad.php';
$pdo = require __DIR__ . '/../config/database.php';
requiereAcceso($pdo, 'chats', 'api');
$session_id = (int) ($_GET['id'] ?? 0);

// Buscamos los mensajes de esta sesión específica
$stmt = $pdo->prepare("SELECT * FROM ds_chat_messages WHERE session_id = ? ORDER BY timestamp ASC");
$stmt->execute([$session_id]);
$mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($mensajes)) {
    echo "<div class='text-center text-muted mt-4'>No se detectaron mensajes en esta sesión.</div>";
    exit;
}

// Dibujamos cada burbuja de chat
foreach ($mensajes as $m) {
    $isUser = ($m['remitente'] == 'user');

    // Lógica visual: Cliente a la izquierda (gris), Bot a la derecha (verde)
    $align = $isUser ? 'align-self: flex-start;' : 'align-self: flex-end;';
    $bg = $isUser ? 'background: #e2e8f0; color: #0A2E4F; border-radius: 15px 15px 15px 0;' : 'background: #3FAE49; color: white; border-radius: 15px 15px 0 15px;';
    $icon = $isUser ? '<i class="fas fa-user-circle me-1"></i>' : '<i class="fas fa-robot ms-1"></i>';
    $nombre = $isUser ? 'Cliente' : 'SenseBot';

    // Formatear hora
    $fecha = date('h:i A', strtotime($m['timestamp']));

    // Imprimir el HTML de la burbuja
    echo "
    <div style='max-width: 85%; padding: 12px 18px; margin-bottom: 15px; font-size: 0.95rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); $align $bg'>
        <div style='font-size: 0.75rem; opacity: 0.8; margin-bottom: 5px; font-weight: bold;'>
            $icon $nombre <span style='font-weight: normal; margin-left: 8px;'><i class='far fa-clock'></i> $fecha</span>
        </div>
        " . nl2br(esc($m['mensaje'])) . "
    </div>";
}
?>