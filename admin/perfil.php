<?php
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/doble_factor.php';
$db = require __DIR__ . '/../config/database.php';
requiereSesion($db);
asegurarColumnasMfa($db);
$aviso = tomarAviso();

$user_id = $_SESSION['admin_id'];
$status_save = ""; 
$error_msg = "";

// ==========================================
// LÓGICA DE ACTUALIZACIÓN DE PERFIL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = textoPost('nombre');
    $correo = textoPost('correo');
    // Limpiamos el teléfono de espacios o guiones
    $telefono = preg_replace('/[\s\-\+]/', '', textoPost('telefono'));
    $cargo = textoPost('cargo');

    // --- VALIDACIONES ---
    if (empty($nombre) || empty($correo)) {
        $status_save = "error";
        $error_msg = "El nombre y el correo son campos obligatorios.";
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $status_save = "error";
        $error_msg = "El formato del correo electrónico no es válido.";
    } elseif (!empty($telefono) && !preg_match('/^[0-9]{7,15}$/', $telefono)) {
        $status_save = "error";
        $error_msg = "El teléfono debe contener entre 7 y 15 dígitos numéricos.";
    } else {
        try {
            $stmt = $db->prepare("UPDATE usuarios SET nombre = ?, correo = ?, telefono = ?, cargo = ? WHERE id = ?");
            $stmt->execute([$nombre, $correo, $telefono, $cargo, $user_id]);
            $_SESSION['admin_nombre'] = $nombre;

            // Procesamiento de la Foto
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $file_info = getimagesize($_FILES['foto']['tmp_name']);
                if ($file_info !== false) {
                    $foto_data = file_get_contents($_FILES['foto']['tmp_name']);
                    $stmt_foto = $db->prepare("UPDATE usuarios SET foto = ? WHERE id = ?");
                    $stmt_foto->execute([$foto_data, $user_id]);
                } else {
                    $status_save = "error";
                    $error_msg = "El archivo subido no es una imagen válida.";
                }
            }
            if ($status_save !== "error") $status_save = "success";
            
        } catch (PDOException $e) { 
            $status_save = "error"; 
            $error_msg = "Error de conexión con la base de datos.";
        }
    }
}

// ==========================================
// CARGAR DATOS DEL USUARIO
// ==========================================
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$titulo = "Mi Perfil";
$subtitulo = "Gestión de identidad profesional";

// LÓGICA DEL AVATAR (Igual al Header)
$nombre_usuario = $perfil['nombre'] ?? 'Usuario';
if (!empty($perfil['foto'])) {
    $avatar = imagenDataUri($perfil['foto']);
} else {
    // Genera el avatar con las iniciales
    $avatar = "https://ui-avatars.com/api/?name=" . urlencode($nombre_usuario) . "&background=0A2E4F&color=fff&bold=true&size=256";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Perfil | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .main-content { padding: 30px !important; }
        .card-identity { background: linear-gradient(135deg, #0A2E4F 0%, #1a4d7c 100%); border: none; border-radius: 20px; color: white; padding: 35px 20px; box-shadow: 0 15px 30px rgba(10, 46, 79, 0.15); }
        
        /* AQUÍ REGRESAMOS AL TAMAÑO ORIGINAL ELEGANTE */
        .img-wrapper img { width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 4px solid rgba(255,255,255,0.2); transition: transform 0.3s; background: white; }
        .img-wrapper img:hover { transform: scale(1.05); border-color: rgba(255,255,255,0.5); }
        
        .role-badge { background: var(--verde-datasense, #3FAE49); font-size: 0.65rem; padding: 5px 15px; border-radius: 50px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
        .card-form { background: white; border: none; border-radius: 20px; box-shadow: 0 8px 25px rgba(0,0,0,0.03); padding: 35px !important; height: 100%; }
        .form-label { font-weight: 700; color: #0A2E4F; font-size: 0.75rem; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px; }
        .input-group-text { background: #f8fafc; border: 1px solid #e2e8f0; border-right: none; color: #94a3b8; padding-left: 15px; padding-right: 15px; }
        .form-control { border: 1px solid #e2e8f0; background: #f8fafc; padding: 12px 15px; font-size: 0.95rem; border-radius: 0 10px 10px 0; color: #334155; font-weight: 500; }
        .form-control:focus { background: white; border-color: #0A2E4F; box-shadow: 0 0 0 0.25rem rgba(10, 46, 79, 0.1); }
        .btn-save { background: #0A2E4F; color: white; border: none; padding: 14px 40px; border-radius: 12px; font-weight: 700; transition: 0.3s; letter-spacing: 0.5px; }
        .btn-save:hover { background: #175d9e; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(10, 46, 79, 0.2); color:white; }
        .security-box { background: #fffdf5; border: 1px solid #fde68a; border-radius: 15px; padding: 25px; margin-top: 25px; transition: all 0.3s; }
        .security-box:hover { background: #fef3c7; border-color: #fcd34d; }
    </style>
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="container-fluid px-0 mt-3">
            <div class="row g-4">
                
                <div class="col-xl-4 col-lg-5">
                    <div class="card card-identity text-center h-auto mb-4">
                        <div class="mb-3 img-wrapper d-flex justify-content-center">
                            <?php
                            // Preparamos el avatar de respaldo por si la foto falla
                            $nombre_url = urlencode($perfil['nombre'] ?? 'Usuario');
                            $fallback_avatar = "https://ui-avatars.com/api/?name={$nombre_url}&background=3FAE49&color=fff&size=256&bold=true";
                            ?>
                            <img src="<?= $avatar ?>" 
                                 onerror="this.src='<?= $fallback_avatar ?>'" 
                                 class="shadow-lg mb-3" 
                                 alt="Avatar de perfil"
                                 style="background-color: #fff;">
                        </div>
                        <h4 class="fw-bold mb-2"><?= htmlspecialchars($perfil['nombre']) ?></h4>
                        <div class="mb-4"><span class="role-badge"><?= htmlspecialchars($perfil['rol']) ?></span></div>
                        
                        <div class="text-start border-top border-white border-opacity-10 pt-4 px-3">
                            <small class="text-white-50 d-block mb-1" style="font-size:0.65rem; letter-spacing: 1px;">CARGO ACTUAL</small>
                            <p class="small fw-bold mb-3"><i class="fas fa-briefcase me-2 text-white-50"></i> <?= htmlspecialchars($perfil['cargo'] ?? 'No especificado') ?></p>
                            
                            <small class="text-white-50 d-block mb-1 mt-3" style="font-size:0.65rem; letter-spacing: 1px;">CORREO CORPORATIVO</small>
                            <p class="small fw-bold mb-0 text-truncate"><i class="fas fa-envelope me-2 text-white-50"></i> <?= htmlspecialchars($perfil['correo']) ?></p>
                        </div>
                    </div>

                    <?php
                    $mfa_activa = !empty($perfil['mfa_activo']);
                    $mfa_restantes = codigosRecuperacionRestantes($perfil);
                    ?>
                    <div class="card card-form p-4 h-auto shadow-sm mb-4" id="dobleFactor">
                        <h6 class="fw-bold small mb-3 text-uppercase" style="color: #0A2E4F; letter-spacing: 0.5px;"><i class="fas fa-shield-halved me-2"></i> Verificación en dos pasos</h6>
                        <?php if ($mfa_activa): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="text-success fw-bold bg-success bg-opacity-10 px-3 py-1 rounded-pill small"><i class="fas fa-circle-check me-1"></i> ACTIVA</span>
                                <?php if (!empty($perfil['mfa_activado_en'])): ?><span class="small text-muted">desde el <?= esc(date('d/m/Y', strtotime($perfil['mfa_activado_en']))) ?></span><?php endif; ?>
                            </div>
                            <p class="small text-muted mb-3">Cada ingreso pide el código de tu app autenticadora. Te quedan <b class="<?= $mfa_restantes <= 2 ? 'text-danger' : 'text-dark' ?>"><?= (int) $mfa_restantes ?></b> códigos de recuperación.</p>
                            <form method="post" action="seguridad_cuenta.php" class="d-grid gap-2">
                                <label for="mfaCodigo" class="small fw-bold text-muted mb-0">Código actual de tu app (para confirmar cambios)</label>
                                <input type="text" class="form-control text-center fw-bold" id="mfaCodigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required style="letter-spacing: .3em;">
                                <button type="submit" name="accion" value="codigos" class="btn btn-outline-primary btn-sm fw-bold"><i class="fas fa-key me-1"></i> Generar códigos de recuperación nuevos</button>
                                <?php if (!mfaObligatoria($perfil)): ?>
                                <button type="submit" name="accion" value="desactivar" class="btn btn-outline-danger btn-sm fw-bold"><i class="fas fa-power-off me-1"></i> Desactivar la verificación</button>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <span class="text-warning fw-bold bg-warning bg-opacity-10 px-3 py-1 rounded-pill small d-inline-block mb-2"><i class="fas fa-triangle-exclamation me-1"></i> INACTIVA</span>
                            <p class="small text-muted mb-3">Protege tu cuenta: además de la contraseña, cada ingreso pedirá un código de tu celular.</p>
                            <a href="doble_factor.php?activar=1" class="btn btn-success btn-sm fw-bold w-100"><i class="fas fa-shield-halved me-1"></i> Activar ahora</a>
                        <?php endif; ?>
                    </div>

                    <div class="card card-form p-4 h-auto shadow-sm">
                        <h6 class="fw-bold small mb-3 text-uppercase" style="color: #0A2E4F; letter-spacing: 0.5px;"><i class="fas fa-chart-line me-2"></i> Estadísticas de Actividad</h6>
                        <div class="d-flex justify-content-between align-items-center small mb-3 border-bottom pb-2">
                            <span class="text-muted fw-bold">Último Ingreso</span>
                            <span class="text-dark fw-bold bg-light px-2 py-1 rounded"><?= date('d/m/Y') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center small">
                            <span class="text-muted fw-bold">Estado de Cuenta</span>
                            <span class="text-success fw-bold bg-success bg-opacity-10 px-3 py-1 rounded-pill"><i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i> ACTIVO</span>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 col-lg-7">
                    <div class="card card-form shadow-sm">
                        <h5 class="fw-bold text-dark mb-4 border-bottom pb-3"><i class="fas fa-user-edit text-primary me-2"></i> Editar Información Personal</h5>

                        <form action="" method="POST" enctype="multipart/form-data" id="formPerfil">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label">Nombre Completo *</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-user text-primary"></i></span>
                                        <input type="text" name="nombre" class="form-control rounded-end-3" value="<?= htmlspecialchars($perfil['nombre']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Correo Electrónico *</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-envelope text-primary"></i></span>
                                        <input type="email" name="correo" class="form-control rounded-end-3" value="<?= htmlspecialchars($perfil['correo']) ?>" required placeholder="ejemplo@correo.com">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Teléfono de Contacto</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-phone text-success"></i></span>
                                        <input type="text" name="telefono" id="inputTelefono" class="form-control rounded-end-3" value="<?= htmlspecialchars($perfil['telefono'] ?? '') ?>" placeholder="Ej: 300 123 4567">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Solo números (Mín. 7, Máx. 15 dígitos)</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Cargo / Función Operativa</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-briefcase text-secondary"></i></span>
                                        <input type="text" name="cargo" class="form-control rounded-end-3" value="<?= htmlspecialchars($perfil['cargo'] ?? '') ?>" placeholder="Ej: Consultor BI">
                                    </div>
                                </div>
                                
                                <div class="col-12 mt-4">
                                    <label class="form-label">Actualizar Foto de Perfil (Opcional)</label>
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-camera text-muted"></i></span>
                                        <input type="file" name="foto" class="form-control rounded-end-3 bg-white" accept="image/jpeg, image/png, image/webp">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Se recomienda una imagen cuadrada en formato JPG o PNG.</small>
                                </div>
                            </div>

                            <div class="mt-5 d-flex justify-content-end border-top pt-4">
                                <button type="submit" class="btn-save shadow"><i class="fas fa-save me-2"></i> GUARDAR CAMBIOS DE PERFIL</button>
                            </div>
                        </form>

                        <div class="security-box d-flex justify-content-between align-items-center shadow-sm mt-5">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><i class="fas fa-shield-alt text-warning me-2"></i> Seguridad de la Cuenta</h6>
                                <p class="text-muted mb-0" style="font-size: 0.8rem;">Modifica tu contraseña regularmente para mantener tu cuenta segura.</p>
                            </div>
                            <a href="cambiar_clave.php" class="btn btn-dark fw-bold px-4 py-2 rounded-pill shadow-sm" style="letter-spacing: 0.5px;">GESTIONAR CLAVE <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Limpiar entrada de teléfono para no aceptar letras
    document.getElementById('inputTelefono').addEventListener('input', function (e) {
        this.value = this.value.replace(/[^0-9+\s-]/g, '');
    });

    <?php if ($aviso): ?>
        Swal.fire({ text: <?= jsJson($aviso['texto']) ?>, icon: <?= jsJson(in_array($aviso['tipo'], ['success', 'error', 'warning', 'info'], true) ? $aviso['tipo'] : 'info') ?>, confirmButtonColor: '#0A2E4F' });
    <?php endif; ?>

    <?php if (($_GET['err'] ?? '') === 'acceso_denegado'): ?>
        Swal.fire({
            title: 'Acceso restringido',
            text: 'Tu rol no tiene permiso para abrir ese módulo. Si lo necesitas, solicítalo al administrador.',
            icon: 'warning',
            confirmButtonColor: '#0A2E4F'
        });
    <?php endif; ?>

    <?php if ($status_save === "success"): ?>
        Swal.fire({ 
            title: '¡Perfil Actualizado!', 
            text: 'Tus datos se han guardado correctamente.', 
            icon: 'success', 
            confirmButtonColor: '#0A2E4F',
            timer: 2500,
            timerProgressBar: true
        }).then(() => {
            window.location.href = 'perfil.php'; 
        });
    <?php elseif ($status_save === "error"): ?>
        Swal.fire({ 
            title: 'No se pudo actualizar', 
            text: '<?= $error_msg ?>', 
            icon: 'error', 
            confirmButtonColor: '#0A2E4F' 
        });
    <?php endif; ?>
</script>
</body>
</html>