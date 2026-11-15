<?php
// Iniciar sesión para validar CSRF
require_once __DIR__ . '/includes/seguridad.php';
iniciarSesionSegura();

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ==========================
   1. SEGURIDAD Y VALIDACIÓN
========================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token'], $_SESSION['token']) || !hash_equals($_SESSION['token'], $_POST['csrf_token'])) {
    header('Location: index.php?error=1#contacto');
    exit;
}

// Anti-spam: mínimo 3 segundos desde que se cargó el formulario (index.php guarda la hora en la sesión)
// y máximo 5 envíos por hora desde la misma IP
$formTime = (int) ($_SESSION['form_contacto_desde'] ?? 0);
if (!$formTime || time() - $formTime < 3 || registrarIntento('contacto|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 3600) > 5) {
    header('Location: index.php?error=1#contacto');
    exit;
}

/* ==========================
   2. CONFIGURACIÓN DE ENTORNO Y DB
========================== */
// safeLoad: en servidores que configuran todo con variables de entorno (Render, Docker) no hay archivo .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$db = require __DIR__ . '/config/database.php';

/* ==========================
   3. CAPTURA DE DATOS
========================== */
$nombre       = textoPost('nombre');
$correo       = textoPost('correo');
$mensaje      = textoPost('mensaje');
$servicio     = textoPost('servicio');
$tipo_cliente = textoPost('tipo_cliente');

// Validación de campos obligatorios
if (empty($nombre) || !filter_var($correo, FILTER_VALIDATE_EMAIL) || empty($servicio)) {
    header('Location: index.php?error=1#contacto');
    exit;
}


/* ==========================
   4. GUARDAR EN BASE DE DATOS
========================== */
$ip = $_SERVER['REMOTE_ADDR']; // Capturamos la IP

try {
    // AQUÍ ESTÁ EL AJUSTE: Cambiamos 'Pendiente' por 'Nuevo' y agregamos el "0" para la columna "leido"
    $stmt = $db->prepare("
        INSERT INTO contactos (nombre, correo, mensaje, servicio, tipo_cliente, ip, estado, leido)
        VALUES (:nombre, :correo, :mensaje, :servicio, :tipo_cliente, :ip, 'Nuevo', 0)
    ");

    $stmt->execute([
        ':nombre'       => $nombre,
        ':correo'       => $correo,
        ':mensaje'      => $mensaje,
        ':servicio'     => $servicio,
        ':tipo_cliente' => $tipo_cliente,
        ':ip'           => $ip
    ]);

} catch (PDOException $e) {
    error_log("Error DataSense DB: " . $e->getMessage());
    header('Location: index.php?error=1#contacto');
    exit;
}

/* ==========================
   8. CORREO AL ADMIN
========================== */
try {
    $mail = nuevoCorreo('DataSense Analytics');
    $mail->addReplyTo($correo, $nombre);
    $mail->addAddress($_ENV['MAIL_USER']);

    $mail->addEmbeddedImage(
        __DIR__ . '/img/logo.png',
        'logo_datasense'
    );

    $mail->isHTML(true);
    $mail->Subject = "Nuevo lead [$tipo_cliente]: $servicio – DataSense Analytics";

    $mail->Body = '
    <html>
    <body style="font-family:Arial;background:#f3f4f6;padding:30px">
        <table width="600" align="center" style="background:#ffffff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.08)">
            <tr>
                <td style="padding:28px;text-align:center;border-bottom:4px solid #0A2E4F">
                    <img src="cid:logo_datasense" style="max-width:220px">
                    <p style="margin:12px 0 0;color:#6b7280;font-size:14px">Nuevo mensaje recibido desde el formulario web</p>
                </td>
            </tr>
            <tr>
                <td style="padding:28px;color:#1f2937;font-size:15px">
                    <p><strong>Nombre:</strong> ' . htmlspecialchars(mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8')) . '</p>
                    <p><strong>Correo:</strong> <a href="mailto:' . htmlspecialchars($correo) . '">' . htmlspecialchars($correo) . '</a></p>
                    <p><strong>Sector:</strong> ' . esc($tipo_cliente) . ' &nbsp;·&nbsp; <strong>Servicio:</strong> ' . esc($servicio) . '</p>
                    <hr>
                    <p><strong>Mensaje:</strong></p>
                    <div style="background:#f9fafb;padding:18px;border-left:4px solid #0A2E4F;border-radius:6px;line-height:1.6">
                        ' . nl2br(htmlspecialchars($mensaje)) . '
                    </div>
                </td>
            </tr>
            <tr>
                <td style="background:#f5f7fa;padding:14px;text-align:center;font-size:12px;color:#6b7280">
                    DataSense Analytics Insights · Formulario Web
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $mail->send();
} catch (Exception $e) {
    // El lead ya quedó guardado: si falla el aviso interno se registra el error y el visitante igual ve la confirmación
    error_log("Error enviando aviso de contacto al admin: " . $e->getMessage());
}

/* ==========================
   9. CORREO AUTOMÁTICO AL CLIENTE
========================== */
$clienteEnviado = true;
try {
    $mailCliente = nuevoCorreo('Guillermo Nieto');
    $mailCliente->addAddress($correo, $nombre);
    $mailCliente->addReplyTo($_ENV['MAIL_USER'], 'DataSense Analytics');

    $mailCliente->addEmbeddedImage(
        __DIR__ . '/img/logo.png',
        'logo_datasense'
    );

    $mailCliente->isHTML(true);
    $mailCliente->Subject = 'Hemos recibido tu mensaje – DataSense Analytics';

    $mailCliente->Body = '
    <html>
    <body style="background:#f4f6f8;font-family:Arial;padding:30px">
        <table width="600" align="center" style="background:#ffffff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.08)">
            <tr>
                <td style="padding:30px;text-align:center">
                    <img src="cid:logo_datasense" style="max-width:220px">
                </td>
            </tr>
            <tr>
                <td style="padding:35px;color:#1f2937;font-size:15px;line-height:1.6">
                    <p>Hola 👋 <strong>' . htmlspecialchars(mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8')) . '</strong></p>
                    <p>Hemos recibido correctamente tu mensaje. Uno de nuestros consultores se pondrá en contacto contigo en las próximas <strong>24 horas</strong>.</p>
                    <p>Si deseas complementar información, puedes responder directamente a este correo.</p><br>
                    <p><strong>Equipo DataSense Analytics</strong><br><span style="color:#6b7280;font-size:13px">Analítica · Ciencia de datos · Business Intelligence</span></p>
                </td>
            </tr>
            <tr>
                <td style="background:#f9fafb;padding:16px;text-align:center;font-size:12px;color:#6b7280">
                    Este mensaje confirma la recepción de tu solicitud.
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $mailCliente->send();
} catch (Exception $e) {
    $clienteEnviado = false;
}

/* ==========================
   10. REDIRECCIÓN FINAL
========================== */
if ($clienteEnviado) {
    header('Location: ./?enviado=1#contacto');
} else {
    header('Location: ./?enviado=1&cliente_error=1#contacto');
}
exit;

