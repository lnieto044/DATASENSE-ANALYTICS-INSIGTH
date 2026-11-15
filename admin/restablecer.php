<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
iniciarSesionSegura(); // Necesaria para el token CSRF del formulario
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$mensaje = "";
$error = false;

// 1. Validar el token
$stmt = $db->prepare("SELECT id FROM usuarios WHERE reset_token = ? AND token_expira > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("<div style='text-align:center;margin-top:50px;font-family:sans-serif;'>
            <h3>⚠️ Enlace vencido o inválido</h3>
            <p>El enlace de recuperación ha expirado. Por favor solicita uno nuevo.</p>
            <a href='recuperar_clave.php' style='color:#3FAE49'>Volver a intentar</a>
         </div>");
}

// 2. Procesar el cambio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValido()) {
    $mensaje = "El formulario caducó o no es válido. Vuelve a intentarlo.";
    $error = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nueva = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if ($error_clave = errorPoliticaClave($nueva)) {
        $mensaje = $error_clave;
        $error = true;
    } elseif ($nueva !== $confirm) {
        $mensaje = "Las contraseñas no coinciden.";
        $error = true;
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $upd = $db->prepare("UPDATE usuarios SET password = ?, reset_token = NULL, token_expira = NULL WHERE id = ?");
        $upd->execute([$hash, $user['id']]);
        
        header("Location: login.php?res=success_reset");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Contraseña - DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .reset-card { max-width: 400px; width: 100%; background: white; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 2.5rem; }
        .btn-primary-custom { background-color: #0A2E4F; border: none; padding: 0.8rem; font-weight: 600; border-radius: 10px; color: white; }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="text-center mb-4">
            <img src="../img/logo.png" style="max-height: 30px;" class="mb-3">
            <h4 class="fw-bold">Nueva Contraseña</h4>
            <p class="text-muted small">Crea una credencial segura para tu cuenta.</p>
        </div>

        <?php if($mensaje): ?>
            <div class="alert alert-danger py-2 small"><?php echo $mensaje; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
            <div class="mb-3">
                <label class="form-label small fw-bold">NUEVA CONTRASEÑA</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password" class="form-control" required placeholder="Mínimo 8 caracteres">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold">CONFIRMAR CONTRASEÑA</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-check"></i></span>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Repite la contraseña">
                </div>
            </div>
            <button type="submit" class="btn btn-primary-custom w-100">ACTUALIZAR Y ENTRAR</button>
        </form>
    </div>
</body>
</html>