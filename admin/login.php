<?php
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/doble_factor.php';   // verificación en dos pasos después de la contraseña
iniciarSesionSegura();

// 1. Cargar la conexión a la base de datos
$db = require __DIR__ . '/../config/database.php';
asegurarColumnasMfa($db);

$error_login = false;
$mensaje_error = "";
$mensaje_registro = "";
$error_registro = false; 

// Los formularios públicos también llevan token CSRF: otro sitio no puede enviar el login o el registro en nombre del visitante
$csrf_rechazado = $_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValido();
$mensaje_csrf = "El formulario caducó o no es válido. Vuelve a intentarlo.";

// 2. LÓGICA DE LOGIN (ACCESO Y RESTRICCIÓN DE ESTADOS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    
    $user_email = textoPost('username');
    // La clave llega tal como se escribió: el registro y el restablecimiento la guardan sin recortar espacios
    $pass       = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    // Límite de intentos: 5 fallos por correo+IP o 20 por IP en 15 minutos bloquean el acceso temporalmente
    $ip_cliente = $_SERVER['REMOTE_ADDR'] ?? '';
    $clave_correo = 'login|' . strtolower($user_email) . '|' . $ip_cliente;
    $clave_ip = 'login|' . $ip_cliente;
    $espera = max(segundosBloqueo($clave_correo, 5, 900), segundosBloqueo($clave_ip, 20, 900));

    if ($csrf_rechazado) {
        $error_login = true;
        $mensaje_error = $mensaje_csrf;
    } elseif ($espera > 0) {
        $error_login = true;
        $mensaje_error = "Demasiados intentos fallidos. Intenta de nuevo en " . ceil($espera / 60) . " minuto(s).";
    } elseif (!empty($user_email) && !empty($pass)) {
        try {
            $stmt = $db->prepare("SELECT * FROM usuarios WHERE correo = ? LIMIT 1");
            $stmt->execute([$user_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            // Se compara la clave exacta y, si no coincide, sin los espacios de los lados (lo habitual al pegarla desde un correo)
            $hash_guardado = (string) ($usuario['password'] ?? '');
            $clave_correcta = $usuario && (password_verify($pass, $hash_guardado)
                || ($pass !== trim($pass) && password_verify(trim($pass), $hash_guardado)));

            if ($clave_correcta) {
                
                // === VALIDACIÓN DEL ESTADO DE LA CUENTA ===
                $estado_actual = $usuario['estado_cuenta'] ?? 'Activo';
                
                if (in_array($estado_actual, ['Suspendido', 'Retirado', 'Inactivo'])) {
                    // Si la cuenta está bloqueada, rechazamos el login
                    $error_login = true;
                    if ($estado_actual === 'Suspendido') {
                        $mensaje_error = "Tu acceso ha sido suspendido temporalmente. Contacta a soporte.";
                    } elseif ($estado_actual === 'Retirado') {
                        $mensaje_error = "Tu cuenta ha sido dada de baja del sistema.";
                    } else {
                        $mensaje_error = "Tu cuenta se encuentra inactiva. Verifica tu estado.";
                    }
                } else {
                    // Si está 'Activo' o en 'Vacaciones': contraseña correcta. Falta el segundo paso si la cuenta lo tiene
                    // activo (o debe configurarlo porque MFA_MODO=obligatorio); si no, entra directo.
                    reiniciarIntentos($clave_correo);
                    $volver = is_string($_GET['volver'] ?? null) ? $_GET['volver'] : '';
                    $paso_mfa = pasoMfaPendiente($usuario);
                    if ($paso_mfa !== 'no') {
                        iniciarFlujoMfa($usuario, $paso_mfa, 'login', $volver);
                        header("Location: doble_factor.php");
                        exit;
                    }
                    header("Location: " . completarInicioSesion($db, $usuario, $volver)); // Página pedida antes del login, o la primera que su rol puede abrir
                    exit;
                }
                
            } else {
                $error_login = true;
                $mensaje_error = "Correo o contraseña incorrectos.";
                registrarIntento($clave_correo, 900);
                registrarIntento($clave_ip, 900);
            }
        } catch (PDOException $e) {
            error_log("Error en Login: " . $e->getMessage());
            $error_login = true;
            $mensaje_error = "Error de conexión con el servidor de datos.";
        }
    } else {
        $error_login = true;
        $mensaje_error = "Por favor, completa todos los campos.";
    }
}

// 3. LÓGICA DE REGISTRO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $nombre   = textoPost('nombre_full');
    $email    = textoPost('email');
    $empresa  = textoPost('empresa');
    $pass_reg = is_string($_POST['password_reg'] ?? null) ? $_POST['password_reg'] : '';

    $error_clave = errorPoliticaClave($pass_reg);

    if ($csrf_rechazado) {
        $error_registro = true;
        $mensaje_registro = $mensaje_csrf;
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_registro = true;
        $mensaje_registro = "El correo electrónico no tiene un formato válido.";
    } elseif (!empty($pass_reg) && $error_clave) {
        $error_registro = true;
        $mensaje_registro = $error_clave;
    } elseif (!empty($nombre) && !empty($email) && !empty($empresa) && !empty($pass_reg)) {
        try {
            $check = $db->prepare("SELECT id FROM usuarios WHERE correo = ?");
            $check->execute([$email]);

            if ($check->rowCount() > 0) {
                $error_registro = true;
                $mensaje_registro = "El correo ingresado ya se encuentra registrado.";
            } else {
                $hashed_password = password_hash($pass_reg, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO usuarios (nombre, correo, organizacion, password, rol, estado_cuenta) VALUES (?, ?, ?, ?, 'Cliente', 'Activo')");
                $stmt->execute([$nombre, $email, $empresa, $hashed_password]);
                
                // Enlace con sus solicitudes previas: solo por correo EXACTO. Enlazar por dominio corporativo
                // permitiría que cualquier persona con un correo @empresa viera los leads de sus compañeros.
                $nuevo_id_usuario = $db->lastInsertId();
                $stmt_enlace_exacto = $db->prepare("UPDATE contactos SET id_usuario_final = ? WHERE correo = ? AND id_usuario_final IS NULL");
                $stmt_enlace_exacto->execute([$nuevo_id_usuario, $email]);

                // A la cuenta nueva se le ofrece configurar la verificación; si no es obligatoria, puede hacerlo después
                if (modoMfa() !== 'desactivado') {
                    iniciarFlujoMfa(['id' => $nuevo_id_usuario], 'configurar', 'registro', is_string($_GET['volver'] ?? null) ? $_GET['volver'] : '');
                    header("Location: doble_factor.php");
                    exit;
                }
                $error_registro = false;
                $mensaje_registro = "Registro exitoso. Ya puedes ingresar en la pestaña 'Iniciar Sesión'.";
            }
        } catch (PDOException $e) {
            error_log("Error en Registro: " . $e->getMessage());
            $error_registro = true;
            $mensaje_registro = "Ocurrió un error al intentar guardar los datos.";
        }
    } else {
        $error_registro = true;
        $mensaje_registro = "Por favor, completa todos los campos del formulario.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso | DataSense Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="icon" type="image/png" href="../img/favicon.png">
    <style>
        body.acceso { font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; background: radial-gradient(circle at 10% 10%, rgba(63,174,73,.10), transparent 35%), radial-gradient(circle at 90% 90%, rgba(10,46,79,.12), transparent 40%), #eef2f7; }
        .acceso-contenedor { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
        .acceso-tarjeta { width: 100%; max-width: 1000px; background: #fff; border-radius: 22px; overflow: hidden; box-shadow: 0 30px 70px rgba(10,46,79,.14); }
        .acceso-hero { background: radial-gradient(circle at 80% 15%, rgba(63,174,73,.35), transparent 40%), linear-gradient(150deg, #0A2E4F 0%, #134574 100%); color: #fff; padding: 44px 40px; position: relative; overflow: hidden; }
        .acceso-hero::after { content: ''; position: absolute; inset: 0; background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px); background-size: 18px 18px; pointer-events: none; }
        .acceso-hero > * { position: relative; z-index: 1; }
        .acceso-logo-hero { background: #fff; border-radius: 12px; padding: 8px 12px; display: inline-block; align-self: flex-start; }
        .acceso-logo-hero img { height: 32px; width: auto; }
        .acceso-hero h1 { color: #fff; font-size: 1.9rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.15; }
        .acceso-hero p { color: rgba(255,255,255,.8); font-size: .92rem; }
        .etiqueta-hero { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; background: rgba(63,174,73,.22); border: 1px solid rgba(63,174,73,.45); color: #d9f99d; padding: 5px 12px; border-radius: 999px; }
        .beneficio { display: flex; gap: 14px; align-items: flex-start; margin-top: 18px; }
        .beneficio .icono { width: 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.14); display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
        .beneficio b { display: block; font-size: .92rem; }
        .beneficio span { font-size: .8rem; color: rgba(255,255,255,.72); }
        .acceso-formulario { padding: 40px 44px; }
        .acceso-volver { font-size: .85rem; font-weight: 600; color: #0A2E4F; text-decoration: none; }
        .acceso-volver:hover { color: #3FAE49; }
        .acceso-titulo { font-size: 1.6rem; font-weight: 800; color: #0f172a; letter-spacing: -.02em; }
        .selector-acceso { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; background: #f1f5f9; border-radius: 14px; padding: 5px; border: 0; }
        .selector-acceso .nav-link { border: 0; border-radius: 10px; color: #64748b; font-weight: 600; font-size: .9rem; padding: 10px; width: 100%; }
        .selector-acceso .nav-link.active { background: #fff; color: #0A2E4F; box-shadow: 0 4px 12px rgba(15,23,42,.08); }
        .campo-acceso { margin-bottom: 16px; }
        .campo-acceso label { font-size: .8rem; font-weight: 600; color: #334155; margin-bottom: 6px; display: block; }
        .control-icono { position: relative; }
        .control-icono > i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .95rem; pointer-events: none; transition: color .2s; }
        .control-icono .form-control { height: 48px; padding-left: 44px; border-radius: 12px; border: 1.5px solid #e2e8f0; font-size: .92rem; background: #fbfcfe; }
        .control-icono .form-control:focus { border-color: #3FAE49; box-shadow: 0 0 0 4px rgba(63,174,73,.14); background: #fff; }
        .control-icono:focus-within > i { color: #3FAE49; }
        .control-icono .ver-clave { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: #64748b; width: 38px; height: 38px; border-radius: 10px; }
        .control-icono .ver-clave:hover { background: #f1f5f9; color: #0A2E4F; }
        .control-icono.con-ojo .form-control { padding-right: 48px; }
        .btn-acceso { height: 50px; border-radius: 12px; font-weight: 700; font-size: .95rem; border: 0; color: #fff; }
        .btn-entrar { background: #0A2E4F; }
        .btn-entrar:hover { background: #134574; color: #fff; }
        .btn-registrar { background: #3FAE49; }
        .btn-registrar:hover { background: #35993f; color: #fff; }
        .fuerza-clave { height: 5px; border-radius: 4px; background: #e2e8f0; overflow: hidden; margin-top: 8px; }
        .fuerza-clave span { display: block; height: 100%; width: 0; transition: width .25s, background .25s; }
        .acceso-alerta { border: 0; border-left: 4px solid; border-radius: 10px; font-size: .86rem; padding: 10px 14px; }
        .acceso-seguro { font-size: .76rem; color: #64748b; }
        .acceso-logo-movil img { height: 30px; }
        @media (max-width: 575.98px) { .acceso-formulario { padding: 26px 20px; } .acceso-titulo { font-size: 1.35rem; } }
    </style>
</head>
<body class="acceso">
<div class="acceso-contenedor">
    <div class="acceso-tarjeta">
        <div class="row g-0">
            <aside class="col-lg-5 d-none d-lg-flex flex-column acceso-hero">
                <div class="acceso-logo-hero mb-4"><img src="../img/logo.png" alt="DataSense Analytics Insights"></div>
                <span class="etiqueta-hero mb-3"><i class="fas fa-chart-pie"></i> Business Intelligence</span>
                <h1 class="mb-3">Decisiones impulsadas por datos</h1>
                <p class="mb-2">Tu panel centralizado para visualizar indicadores, gestionar la operación y comprar soluciones de datos.</p>
                <div class="beneficio"><span class="icono"><i class="fas fa-chart-line"></i></span><div><b>Analítica en tiempo real</b><span>Tableros y reportes siempre actualizados.</span></div></div>
                <div class="beneficio"><span class="icono"><i class="fas fa-shield-halved"></i></span><div><b>Verificación en dos pasos</b><span>Contraseña más un código de tu celular, y bloqueo ante intentos fallidos.</span></div></div>
                <div class="beneficio"><span class="icono"><i class="fas fa-headset"></i></span><div><b>Soporte con ANS</b><span>Radica y sigue tus casos con tiempos claros.</span></div></div>
                <div class="beneficio"><span class="icono"><i class="fas fa-store"></i></span><div><b>Tienda en línea</b><span>Plantillas, cursos, licencias y kits con factura.</span></div></div>
            </aside>

            <section class="col-lg-7 acceso-formulario">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="../" class="acceso-logo-movil d-lg-none"><img src="../img/logo.png" alt="DataSense"></a>
                    <a href="../" class="acceso-volver ms-auto"><i class="fas fa-arrow-left me-1"></i> Volver al sitio</a>
                </div>

                <div class="mx-auto" style="max-width: 420px;">
                    <h2 class="acceso-titulo mb-1">Bienvenido a DataSense</h2>
                    <p class="text-muted small mb-4">Ingresa con tu cuenta o crea una nueva en segundos.</p>

                    <ul class="nav selector-acceso mb-4" id="authTabs" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" id="ingresar-tab" data-bs-toggle="tab" data-bs-target="#ingresar" type="button" role="tab" aria-controls="ingresar" aria-selected="true"><i class="fas fa-right-to-bracket me-2"></i>Iniciar sesión</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" id="registrarme-tab" data-bs-toggle="tab" data-bs-target="#registrarme" type="button" role="tab" aria-controls="registrarme" aria-selected="false"><i class="fas fa-user-plus me-2"></i>Crear cuenta</button></li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="ingresar" role="tabpanel" aria-labelledby="ingresar-tab">
                            <?php if (($_GET['res'] ?? '') === 'success_reset'): ?>
                                <div class="alert alert-success acceso-alerta" role="alert" style="border-color:#198754"><i class="fas fa-circle-check me-2"></i>Contraseña actualizada. Ya puedes iniciar sesión.</div>
                            <?php endif; ?>
                            <?php $avisos_mfa = ['mfa_vencido' => 'La verificación tardó demasiado. Vuelve a escribir tu correo y contraseña.',
                                                 'mfa_intentos' => 'Demasiados códigos incorrectos. Por seguridad, vuelve a iniciar sesión.',
                                                 'mfa_cancelado' => 'No se completó la verificación en dos pasos. Inicia sesión cuando quieras configurarla.'];
                                  if (isset($avisos_mfa[$_GET['res'] ?? ''])): ?>
                                <div class="alert alert-warning acceso-alerta" role="alert" style="border-color:#f0b429"><i class="fas fa-shield-halved me-2"></i><?= esc($avisos_mfa[$_GET['res']]) ?></div>
                            <?php endif; ?>
                            <?php if ($error_login): ?>
                                <div class="alert alert-danger acceso-alerta" role="alert" style="border-color:#dc3545"><i class="fas fa-circle-exclamation me-2"></i><?= esc($mensaje_error) ?></div>
                            <?php endif; ?>

                            <form method="post" action="" id="formIngreso" novalidate>
                                <input type="hidden" name="action" value="login">
                                <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
                                <div class="campo-acceso">
                                    <label for="acceso_correo">Correo electrónico</label>
                                    <div class="control-icono"><i class="fas fa-envelope"></i><input type="email" class="form-control" id="acceso_correo" name="username" required autocomplete="username" placeholder="tu@empresa.com"></div>
                                </div>
                                <div class="campo-acceso">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label for="acceso_clave">Contraseña</label>
                                        <a href="recuperar_clave.php" class="small text-decoration-none fw-semibold" style="color:#0A2E4F"><i class="fas fa-key me-1"></i>¿La olvidaste?</a>
                                    </div>
                                    <div class="control-icono con-ojo"><i class="fas fa-lock"></i><input type="password" class="form-control" id="acceso_clave" name="password" required autocomplete="current-password" placeholder="••••••••">
                                        <button type="button" class="ver-clave" data-ver-clave="acceso_clave" aria-label="Mostrar la contraseña" title="Mostrar la contraseña"><i class="far fa-eye"></i></button></div>
                                </div>
                                <button type="submit" class="btn btn-acceso btn-entrar w-100 mt-1">Entrar al panel <i class="fas fa-arrow-right ms-2"></i></button>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="registrarme" role="tabpanel" aria-labelledby="registrarme-tab">
                            <?php if ($mensaje_registro): ?>
                                <div class="alert <?= $error_registro ? 'alert-danger' : 'alert-success' ?> acceso-alerta" role="alert" style="border-color:<?= $error_registro ? '#dc3545' : '#198754' ?>">
                                    <i class="fas <?= $error_registro ? 'fa-circle-exclamation' : 'fa-circle-check' ?> me-2"></i><?= esc($mensaje_registro) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="" id="formRegistro">
                                <input type="hidden" name="action" value="register">
                                <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
                                <div class="campo-acceso">
                                    <label for="reg_nombre">Nombre completo</label>
                                    <div class="control-icono"><i class="fas fa-user"></i><input type="text" class="form-control" id="reg_nombre" name="nombre_full" required maxlength="150" autocomplete="name" placeholder="Nombre y apellido"></div>
                                </div>
                                <div class="campo-acceso">
                                    <label for="reg_correo">Correo corporativo</label>
                                    <div class="control-icono"><i class="fas fa-envelope"></i><input type="email" class="form-control" id="reg_correo" name="email" required autocomplete="email" placeholder="tu@empresa.com"></div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6 campo-acceso mb-0">
                                        <label for="reg_empresa">Organización</label>
                                        <div class="control-icono"><i class="fas fa-building"></i><input type="text" class="form-control" id="reg_empresa" name="empresa" required maxlength="150" autocomplete="organization" placeholder="Empresa o entidad"></div>
                                    </div>
                                    <div class="col-md-6 campo-acceso mb-0">
                                        <label for="reg_clave">Contraseña</label>
                                        <div class="control-icono con-ojo"><i class="fas fa-lock"></i><input type="password" class="form-control" id="reg_clave" name="password_reg" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Mín. 8 caracteres">
                                            <button type="button" class="ver-clave" data-ver-clave="reg_clave" aria-label="Mostrar la contraseña" title="Mostrar la contraseña"><i class="far fa-eye"></i></button></div>
                                    </div>
                                </div>
                                <div class="fuerza-clave" aria-hidden="true"><span id="barraFuerza"></span></div>
                                <div class="small text-muted mt-1 mb-3" id="textoFuerza">Usa al menos 8 caracteres con letras y números.</div>
                                <?php if (modoMfa() !== 'desactivado'): ?>
                                <p class="small text-muted mb-3"><i class="fas fa-mobile-screen-button me-1" style="color:#3FAE49"></i> Después activarás la verificación en dos pasos con una app autenticadora en tu celular.</p>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-acceso btn-registrar w-100"><i class="fas fa-user-plus me-2"></i>Crear mi cuenta</button>
                                <p class="text-muted text-center mt-3 mb-0" style="font-size:.74rem">Al crear tu cuenta aceptas el tratamiento de tus datos personales según la Ley 1581 de 2012.</p>
                            </form>
                        </div>
                    </div>

                    <div class="acceso-seguro text-center mt-4"><i class="fas fa-lock me-1 text-success"></i> Conexión protegida · Verificación en dos pasos · Tus datos viajan cifrados</div>
                </div>
            </section>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Pestaña de registro: tras intentar registrarse o al llegar desde la tienda con ?tab=registro
    <?php if ($mensaje_registro || ($_GET['tab'] ?? '') === 'registro'): ?>
    bootstrap.Tab.getOrCreateInstance(document.querySelector('#registrarme-tab')).show();
    <?php endif; ?>

    document.querySelectorAll('[data-ver-clave]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const campo = document.getElementById(boton.dataset.verClave);
            const mostrar = campo.type === 'password';
            campo.type = mostrar ? 'text' : 'password';
            boton.innerHTML = mostrar ? '<i class="far fa-eye-slash"></i>' : '<i class="far fa-eye"></i>';
            boton.setAttribute('aria-label', mostrar ? 'Ocultar la contraseña' : 'Mostrar la contraseña');
        });
    });

    const clave = document.getElementById('reg_clave');
    const barra = document.getElementById('barraFuerza');
    const texto = document.getElementById('textoFuerza');
    clave.addEventListener('input', function () {
        const v = clave.value;
        let puntos = 0;
        if (v.length >= 8) puntos++;
        if (/[a-zA-Z]/.test(v) && /\d/.test(v)) puntos++;
        if (v.length >= 12) puntos++;
        if (/[^a-zA-Z0-9]/.test(v) || (/[a-z]/.test(v) && /[A-Z]/.test(v))) puntos++;
        const niveles = [['0%', '#e2e8f0', 'Usa al menos 8 caracteres con letras y números.'], ['30%', '#dc2626', 'Débil: agrega letras y números.'],
                         ['55%', '#f59e0b', 'Aceptable.'], ['80%', '#3FAE49', 'Segura.'], ['100%', '#15803d', 'Muy segura.']];
        const nivel = niveles[v ? Math.max(1, puntos) : 0];
        barra.style.width = nivel[0];
        barra.style.background = nivel[1];
        texto.textContent = nivel[2];
    });
});
</script>
</body>
</html>
