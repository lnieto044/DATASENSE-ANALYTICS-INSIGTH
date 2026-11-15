<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/utilidades.php';
$db = require __DIR__ . '/../config/database.php';
iniciarSesionSegura(); // Necesaria para el token CSRF del formulario

// Cargamos las variables de entorno si no están cargadas
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();
}

$mensaje = "";
$tipo_alert = "";

// Mismo mensaje exista o no el correo: así el formulario no revela qué cuentas están registradas
$mensaje_generico = "Si el correo está registrado, recibirás en unos minutos un enlace para restablecer la clave.";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValido()) {
    // Sin token válido no se envía nada: evita que otro sitio dispare correos de recuperación
    $mensaje = "El formulario caducó o no es válido. Vuelve a intentarlo.";
    $tipo_alert = "alert-danger";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    $stmt = $db->prepare("SELECT id, nombre FROM usuarios WHERE correo = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Máximo 5 solicitudes por hora desde la misma IP (evita usar el formulario para enviar correos en masa)
    if (registrarIntento('recuperar|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 3600) > 5) {
        $mensaje = "Demasiadas solicitudes. Intenta de nuevo más tarde.";
        $tipo_alert = "alert-danger";
    } elseif ($user) {
        $token = bin2hex(random_bytes(32)); 
        $expira = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $upd = $db->prepare("UPDATE usuarios SET reset_token = ?, token_expira = ? WHERE id = ?");
        $upd->execute([$token, $expira, $user['id']]);

        try {
            $mail = nuevoCorreo('Seguridad DataSense');
            $mail->addAddress($email, $user['nombre']);
            
            // Incrustar Logo (Asegúrate que la ruta al logo sea correcta)
            $mail->addEmbeddedImage(__DIR__ . '/../img/logo.png', 'logo_datasense');

            $url = urlBase() . "admin/restablecer.php?token=$token"; // En producción define APP_URL en .env

            $mail->isHTML(true);
            $mail->Subject = 'Restablecer tu contraseña – DataSense Analytics';

            // Diseño de correo limpio y profesional
            $mail->Body = '
            <html>
            <body style="background:#f4f6f8;font-family:Arial,sans-serif;padding:30px;">
                <table width="600" align="center" style="background:#ffffff;border-radius:12px;border:1px solid #e1e4e8;overflow:hidden">
                    <tr>
                        <td style="padding:30px;text-align:center;border-bottom:1px solid #f0f0f0">
                            <img src="cid:logo_datasense" style="max-width:180px">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;color:#1f2937;font-size:16px;line-height:1.6">
                            <p>Hola 👋 <strong>' . htmlspecialchars($user['nombre']) . '</strong>,</p>
                            <p>Has solicitado restablecer tu acceso a la plataforma de <strong>DataSense Analytics</strong>.</p>
                            <p>Para crear una nueva contraseña, haz clic en el siguiente botón:</p>
                            
                            <div style="text-align:center;padding:25px 0">
                                <a href="' . $url . '" style="background:#3FAE49;color:#ffffff;padding:15px 30px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;box-shadow:0 4px 6px rgba(0,0,0,0.1)">Restablecer Contraseña</a>
                            </div>
                            
                            <p style="font-size:13px;color:#6b7280;text-align:center">Este enlace caducará en 1 hora por motivos de seguridad.</p>
                            <br>
                            <p><strong>Equipo DataSense Analytics</strong><br><span style="color:#6b7280;font-size:13px">Analítica · Ciencia de datos · Business Intelligence</span></p>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';

            $mail->send();
            $mensaje = $mensaje_generico;
            $tipo_alert = "alert-success";
        } catch (Exception $e) {
            $mensaje = "Error al enviar el correo. Por favor contacte a soporte.";
            $tipo_alert = "alert-danger";
        }
    } else {
        $mensaje = $mensaje_generico;
        $tipo_alert = "alert-success";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Acceso - DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .recovery-card { max-width: 450px; width: 100%; background: white; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.08); padding: 2.5rem; }
        .btn-primary-custom { background-color: #0A2E4F; border: none; padding: 0.8rem; font-weight: 600; border-radius: 10px; transition: all 0.3s; color:white; }
        .btn-primary-custom:hover { background-color: #113d66; transform: translateY(-2px); color:white; }
    </style>
</head>
<body>
    <div class="recovery-card text-center">
        <div class="mb-4">
            <img src="../img/logo.png" style="max-height: 35px;" class="mb-3">
            <h4 class="fw-bold">¿Problemas para entrar?</h4>
            <p class="text-muted small">Ingresa tu correo y te ayudaremos a recuperar tu cuenta.</p>
        </div>

        <?php if($mensaje): ?>
            <div class="alert <?php echo $tipo_alert; ?> small py-2"><?php echo $mensaje; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
            <div class="mb-4 text-start">
                <label class="form-label small fw-bold">CORREO REGISTRADO</label>
                <input type="email" name="email" class="form-control" required placeholder="tu@empresa.com">
            </div>
            <button type="submit" class="btn btn-primary-custom w-100">ENVIAR ENLACE</button>
            <div class="mt-3">
                <a href="login.php" class="text-decoration-none small fw-bold" style="color: #0A2E4F;">Volver al login</a>
            </div>
        </form>
    </div>
</body>
</html>