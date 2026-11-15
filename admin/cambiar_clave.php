<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereSesion($db);

$user_id = $_SESSION['admin_id'];
$status_res = $_GET['res'] ?? ""; 

$stmt_perfil = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_perfil->execute([$user_id]);
$perfil = $stmt_perfil->fetch(PDO::FETCH_ASSOC);

if (!$perfil) { header("Location: logout.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $actual = $_POST['clave_actual'];
    $nueva = $_POST['nueva_clave'];
    $confirma = $_POST['confirma_clave'];

    if ($nueva !== $confirma) {
        header("Location: cambiar_clave.php?res=coincidencia");
        exit;
    }

    if (errorPoliticaClave($nueva)) {
        header("Location: cambiar_clave.php?res=debil");
        exit;
    }

    if (password_verify($actual, $perfil['password'])) {
        $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
        $update = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
        
        if ($update->execute([$nuevo_hash, $user_id])) {
            try {
                $mail = nuevoCorreo('Seguridad DataSense');
                $mail->addAddress($perfil['correo'], $perfil['nombre']);
                $mail->addEmbeddedImage(__DIR__ . '/../img/logo.png', 'logo_datasense');
                $mail->isHTML(true);
                $mail->Subject = 'Tu contraseña ha sido cambiada – DataSense Analytics';
                $mail->Body = '
                <html>
                <body style="background:#f4f6f8;font-family:Arial,sans-serif;padding:30px;">
                    <table width="600" align="center" style="background:#ffffff;border-radius:12px;border:1px solid #e1e4e8;overflow:hidden;border-collapse:collapse;">
                        <tr>
                            <td style="padding:30px;text-align:center;border-bottom:1px solid #f0f0f0;background-color:#ffffff;">
                                <img src="cid:logo_datasense" style="max-width:180px" alt="Logo DataSense">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:40px;color:#1f2937;font-size:16px;line-height:1.6;">
                                <p>Hola 👋 <strong>' . htmlspecialchars($perfil['nombre']) . '</strong>,</p>
                                <p>Te informamos que la contraseña de tu cuenta en <strong>DataSense Analytics</strong> ha sido actualizada correctamente.</p>
                                <p>Si tú realizaste este cambio, puedes ignorar este correo. Si no lo hiciste, por favor contacta al administrador de inmediato para proteger tu cuenta.</p>
                                <br>
                                <p><strong>Equipo de Seguridad - DataSense Analytics</strong><br>
                                <span style="color:#6b7280;font-size:13px">Analítica · Ciencia de datos · Business Intelligence</span></p>
                            </td>
                        </tr>
                    </table>
                </body>
                </html>'; 
                $mail->send();
            } catch (Exception $e) { }
            header("Location: cambiar_clave.php?res=success");
        } else { header("Location: cambiar_clave.php?res=db"); }
    } else { header("Location: cambiar_clave.php?res=error_pass"); }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seguridad | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/admin.css">
    
    <style>
        .main-content { padding: 15px !important; }
        
        .card-security-nano {
            max-width: 380px; /* Reducido de 500px a 380px */
            margin: 20px auto; 
            background: white; 
            border-radius: 20px;
            padding: 25px !important; /* Reducido el espacio interno */
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .icon-box {
            width: 60px; height: 60px; /* Reducido de 80px a 60px */
            background: #fff9db; 
            color: #fab005;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; 
            margin: 0 auto 15px; 
            font-size: 1.4rem;
        }

        .form-label { font-weight: 700; color: #1e293b; font-size: 0.7rem; text-transform: uppercase; margin-bottom: 4px; }
        .input-group-text { background: #f8fafc; border-right: none; color: #94a3b8; }
        .form-control { border-left: none; background: #f8fafc; font-size: 0.85rem; padding: 10px; }
        
        .btn-update { 
            background: #0A2E4F; color: white; border: none; padding: 12px; 
            border-radius: 10px; font-weight: 700; width: 100%; 
            transition: 0.3s; font-size: 0.85rem; 
        }
        .btn-update:hover { background: #3FAE49; transform: translateY(-2px); }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="container-fluid">
            <div class="card card-security-nano text-center">
                <div class="icon-box shadow-sm">
                    <i class="fas fa-lock"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">Cambiar Clave</h5>
                <p class="text-muted mb-4" style="font-size: 0.72rem;">Actualización de seguridad</p>

                <form action="" method="POST" class="text-start">
                    <div class="mb-3">
                        <label class="form-label">Contraseña Actual</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-unlock-alt"></i></span>
                            <input type="password" name="clave_actual" class="form-control" required placeholder="••••••••">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" name="nueva_clave" class="form-control" required minlength="8" maxlength="72" placeholder="Mín. 8, con letras y números">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirmar Clave</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fas fa-key"></i></span>
                            <input type="password" name="confirma_clave" class="form-control" required placeholder="Repite la clave">
                        </div>
                    </div>
                    <button type="submit" class="btn-update shadow-sm">
                        <i class="fas fa-sync-alt me-2"></i> ACTUALIZAR CREDENCIALES
                    </button>
                    <div class="mt-3 text-center">
                        <a href="perfil.php" class="text-muted text-decoration-none small" style="font-size: 0.9rem;">
                            <i class="fas fa-arrow-left me-1"></i> Volver al Perfil
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const res = urlParams.get('res');
        if (res) {
            window.history.replaceState({}, document.title, window.location.pathname);
            if(res === "success") {
                Swal.fire({ title: '¡Éxito!', text: 'Clave actualizada y correo enviado.', icon: 'success', confirmButtonColor: '#3FAE49' });
            } else if(res === "error_pass") {
                Swal.fire({ title: 'Error', text: 'La clave actual no es correcta.', icon: 'error', confirmButtonColor: '#0A2E4F' });
            } else if(res === "coincidencia") {
                Swal.fire({ title: 'Aviso', text: 'Las nuevas contraseñas no coinciden.', icon: 'warning' });
            } else if(res === "debil") {
                Swal.fire({ title: 'Contraseña débil', text: 'Usa entre 8 y 72 caracteres combinando letras y números.', icon: 'warning', confirmButtonColor: '#0A2E4F' });
            } else if(res === "db") {
                Swal.fire({ title: 'Error', text: 'No se pudo guardar la nueva clave. Intenta de nuevo.', icon: 'error', confirmButtonColor: '#0A2E4F' });
            }
        }
    });
</script>
</body>
</html>