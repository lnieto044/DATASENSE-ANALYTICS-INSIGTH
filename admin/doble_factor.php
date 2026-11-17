<?php
/**
 * SEGUNDO PASO DEL INGRESO (verificación en dos pasos)
 *   - Verificar: después de la contraseña se escribe el código de 6 dígitos de la app (o un código de recuperación).
 *   - Configurar: se escanea el código QR con la app y se confirma con un código. Ocurre al registrarse, en el primer
 *     ingreso cuando MFA_MODO=obligatorio, o desde Mi Perfil (?activar=1).
 *   - Códigos: al activarla se muestran una sola vez los códigos de recuperación.
 * La lógica está en includes/doble_factor.php.
 */
require_once __DIR__ . '/../includes/doble_factor.php';
iniciarSesionSegura();
$db = require __DIR__ . '/../config/database.php';
asegurarColumnasMfa($db);

function irA($destino) {
    header('Location: ' . $destino);
    exit;
}

// Desde Mi Perfil: la persona ya inició sesión y quiere activarla
if (isset($_GET['activar'])) {
    $actual = requiereSesion($db);
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$actual['id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (esCuentaDemoMfa($u) || !empty($u['mfa_activo'])) irA('perfil.php');
    iniciarFlujoMfa($u, 'configurar', 'perfil');
    irA('doble_factor.php');
}

if (isset($_GET['cancelar'])) {
    $origen = $_SESSION['mfa_flujo']['origen'] ?? ($_SESSION['mfa_codigos']['origen'] ?? 'login');
    unset($_SESSION['mfa_flujo'], $_SESSION['mfa_codigos']);
    irA($origen === 'perfil' ? 'perfil.php' : 'login.php?res=mfa_cancelado');
}

$codigos = $_SESSION['mfa_codigos'] ?? null;   // recién activada: se muestran los códigos de recuperación
$flujo = $codigos ? null : flujoMfaVigente();
if (!$codigos && !$flujo) irA('login.php?res=mfa_vencido');

$usuario_id = (int) ($codigos['id'] ?? $flujo['id']);
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuario || in_array($usuario['estado_cuenta'] ?? 'Activo', ESTADOS_BLOQUEADOS, true)) {
    unset($_SESSION['mfa_flujo'], $_SESSION['mfa_codigos']);
    irA('login.php');
}

$error = '';
$clave_limite = 'mfa|' . $usuario_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $error = 'El formulario caducó. Vuelve a escribir el código.';
    } elseif ($codigos) {
        // Ya guardó los códigos: entra al panel (o vuelve a su perfil si la activó desde ahí)
        unset($_SESSION['mfa_codigos']);
        if ($codigos['origen'] === 'perfil') {
            avisoSiguiente('success', 'La verificación en dos pasos quedó activa.');
            irA('perfil.php');
        }
        irA(completarInicioSesion($db, $usuario, $codigos['volver']));
    } else {
        $espera = segundosBloqueo($clave_limite, 5, 900);
        $es_recuperacion = ($_POST['modo'] ?? '') === 'recuperacion' && $flujo['tipo'] === 'verificar';
        $codigo = textoPost('codigo');
        $valido = false;

        if ($espera > 0) {
            $error = 'Demasiados códigos incorrectos. Intenta de nuevo en ' . ceil($espera / 60) . ' minuto(s).';
        } elseif ($flujo['tipo'] === 'configurar') {
            $paso = verificarTotp($flujo['secreto_nuevo'], $codigo);
            if ($paso !== false) {
                $lista = activarMfa($db, $usuario_id, $flujo['secreto_nuevo'], $paso);
                $_SESSION['mfa_codigos'] = ['id' => $usuario_id, 'codigos' => $lista, 'origen' => $flujo['origen'], 'volver' => $flujo['volver']];
                unset($_SESSION['mfa_flujo']);
                reiniciarIntentos($clave_limite);
                irA('doble_factor.php');
            }
        } else {
            $valido = comprobarCodigoUsuario($db, $usuario, $codigo, $es_recuperacion);
            if ($valido) {
                reiniciarIntentos($clave_limite);
                irA(completarInicioSesion($db, $usuario, $flujo['volver']));
            }
        }

        if (!$valido && $error === '') {
            registrarIntento($clave_limite, 900);
            $_SESSION['mfa_flujo']['fallos'] = (int) ($flujo['fallos'] ?? 0) + 1;
            if ($_SESSION['mfa_flujo']['fallos'] >= 5) {
                unset($_SESSION['mfa_flujo']);
                irA('login.php?res=mfa_intentos');
            }
            $error = $es_recuperacion ? 'Ese código de recuperación no es válido o ya se usó.'
                                      : 'El código no es correcto o ya venció. Escribe el que muestra ahora la app.';
        }
    }
}

$vista = $codigos ? 'codigos' : $flujo['tipo'];
$es_demo = esCuentaDemoMfa($usuario);
$codigo_demo = ($vista === 'verificar' && $es_demo) ? codigoTotp(secretoDemo($usuario['correo']), pasoActual()) : '';
$segundos_demo = MFA_PERIODO - (time() % MFA_PERIODO);
$secreto_nuevo = $vista === 'configurar' ? $flujo['secreto_nuevo'] : '';
$titulos = [
    'verificar' => ['Verificación en dos pasos', 'Escribe el código de 6 dígitos que muestra tu app autenticadora para DataSense.'],
    'configurar' => [$flujo && $flujo['origen'] === 'registro' ? 'Protege tu cuenta nueva' : 'Activa la verificación en dos pasos',
                     'Además de tu contraseña, cada ingreso pedirá un código que cambia cada 30 segundos en tu celular.'],
    'codigos' => ['Guarda tus códigos de recuperación', 'Si pierdes el celular, cada código te deja entrar una vez. Guárdalos en un lugar seguro: no se vuelven a mostrar.'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($titulos[$vista][0]) ?> | DataSense Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../img/favicon.png">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; margin: 0; color: #0f172a;
            background: radial-gradient(circle at 10% 10%, rgba(63,174,73,.10), transparent 35%), radial-gradient(circle at 90% 90%, rgba(10,46,79,.12), transparent 40%), #eef2f7; }
        .mfa { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
        .mfa-tarjeta { width: 100%; max-width: 520px; background: #fff; border-radius: 22px; box-shadow: 0 30px 70px rgba(10,46,79,.14); overflow: hidden; }
        .mfa-cabeza { background: linear-gradient(150deg, #0A2E4F 0%, #134574 100%); color: #fff; padding: 26px 32px; display: flex; align-items: center; gap: 16px; }
        .mfa-escudo { width: 52px; height: 52px; border-radius: 15px; background: rgba(63,174,73,.25); border: 1px solid rgba(134,224,143,.5); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: #86e08f; flex-shrink: 0; }
        .mfa-cabeza h1 { font-size: 1.25rem; font-weight: 800; margin: 0 0 3px; letter-spacing: -.01em; }
        .mfa-cabeza p { margin: 0; font-size: .86rem; color: rgba(255,255,255,.82); }
        .mfa-cuerpo { padding: 28px 32px 30px; }
        .mfa-cuenta { display: flex; align-items: center; gap: 10px; background: #f1f5f9; border-radius: 12px; padding: 10px 14px; font-size: .86rem; margin-bottom: 20px; }
        .mfa-cuenta i { color: #3FAE49; }
        .mfa-codigo { height: 62px; text-align: center; font-size: 1.9rem; font-weight: 800; letter-spacing: .4em; padding-left: .4em; border-radius: 14px; border: 1.5px solid #e2e8f0; font-variant-numeric: tabular-nums; }
        .mfa-codigo.recuperacion { letter-spacing: .14em; font-size: 1.4rem; }
        .mfa-codigo:focus { border-color: #3FAE49; box-shadow: 0 0 0 4px rgba(63,174,73,.14); }
        .btn-mfa { height: 50px; border-radius: 12px; font-weight: 700; background: #0A2E4F; color: #fff; border: 0; }
        .btn-mfa:hover, .btn-mfa:focus-visible { background: #134574; color: #fff; }
        .btn-mfa:disabled { background: #94a3b8; }
        .mfa-enlace { background: none; border: 0; padding: 0; color: #0A2E4F; font-weight: 600; font-size: .85rem; text-decoration: underline; text-underline-offset: 3px; }
        .mfa-demo { background: #fff7e0; border: 1px dashed #f0b429; border-radius: 12px; padding: 12px 14px; font-size: .84rem; color: #6b4a00; margin-bottom: 18px; }
        .mfa-demo b { font-size: 1.15rem; letter-spacing: .12em; color: #0A2E4F; font-variant-numeric: tabular-nums; }
        .mfa-paso { display: flex; gap: 14px; margin-bottom: 18px; }
        .mfa-paso > span { width: 28px; height: 28px; border-radius: 50%; background: #3FAE49; color: #fff; font-weight: 800; font-size: .82rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
        .mfa-paso > div > b { display: block; font-size: .92rem; }
        .mfa-paso p { margin: 2px 0 0; font-size: .84rem; color: #475569; }
        .mfa-qr { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; margin-top: 10px; }
        .mfa-qr #qr { background: #fff; padding: 8px; border-radius: 10px; line-height: 0; }
        .mfa-qr #qr img, .mfa-qr #qr canvas { width: 168px; height: 168px; }
        .mfa-clave { font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace; font-size: .9rem; font-weight: 700; color: #0A2E4F; word-break: break-all; background: #fff; border: 1px solid #e2e8f0; border-radius: 9px; padding: 8px 10px; }
        .mfa-qr-texto { flex: 1 1 180px; min-width: 0; font-size: .8rem; color: #475569; }
        .mfa-lista { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; margin-bottom: 14px; }
        .mfa-lista code { font-size: 1.02rem; font-weight: 700; color: #0A2E4F; text-align: center; background: #fff; border-radius: 8px; padding: 7px 4px; letter-spacing: .08em; }
        .mfa-pie { font-size: .76rem; color: #64748b; text-align: center; margin-top: 18px; }
        .mfa-alerta { border: 0; border-left: 4px solid #dc3545; border-radius: 10px; font-size: .86rem; padding: 10px 14px; }
        @media (max-width: 480px) { .mfa-cuerpo, .mfa-cabeza { padding-left: 20px; padding-right: 20px; } .mfa-codigo { font-size: 1.6rem; letter-spacing: .3em; } }
    </style>
</head>
<body>
<main class="mfa">
    <div class="mfa-tarjeta">
        <div class="mfa-cabeza">
            <span class="mfa-escudo"><i class="fas <?= $vista === 'codigos' ? 'fa-key' : 'fa-shield-halved' ?>" aria-hidden="true"></i></span>
            <div>
                <h1><?= esc($titulos[$vista][0]) ?></h1>
                <p><?= esc($titulos[$vista][1]) ?></p>
            </div>
        </div>
        <div class="mfa-cuerpo">
            <div class="mfa-cuenta"><i class="fas fa-user-check" aria-hidden="true"></i><span><?= esc($usuario['nombre']) ?> · <?= esc($usuario['correo']) ?></span></div>

            <?php if ($error): ?>
                <div class="alert alert-danger mfa-alerta" role="alert"><i class="fas fa-circle-exclamation me-2"></i><?= esc($error) ?></div>
            <?php endif; ?>

            <?php if ($vista === 'verificar'): ?>
                <?php if ($es_demo): ?>
                    <div class="mfa-demo"><i class="fas fa-flask me-1" aria-hidden="true"></i> <strong>Cuenta de demostración.</strong> En la demo todos comparten esta cuenta, así que aquí ves el código del momento:
                        <b id="codigoDemo"><?= esc(substr($codigo_demo, 0, 3) . ' ' . substr($codigo_demo, 3)) ?></b>
                        <span class="d-block mt-1">Cambia en <span id="segundosDemo"><?= (int) $segundos_demo ?></span> s. Con tu cuenta real lo verás solo en tu celular.</span></div>
                <?php endif; ?>
                <form method="post" action="doble_factor.php" id="formCodigo" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
                    <input type="hidden" name="modo" id="modoCodigo" value="app">
                    <label for="codigo" class="form-label small fw-semibold" id="etiquetaCodigo">Código de la app autenticadora</label>
                    <input type="text" class="form-control mfa-codigo" id="codigo" name="codigo" required inputmode="numeric" autocomplete="one-time-code"
                           pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" autofocus>
                    <button type="submit" class="btn btn-mfa w-100 mt-3">Verificar y entrar <i class="fas fa-arrow-right ms-2" aria-hidden="true"></i></button>
                </form>
                <div class="d-flex justify-content-between flex-wrap gap-2 mt-3">
                    <?php if (!$es_demo): ?>
                        <button type="button" class="mfa-enlace" id="usarRecuperacion">¿Perdiste el celular? Usa un código de recuperación</button>
                    <?php endif; ?>
                    <a class="mfa-enlace ms-auto" href="doble_factor.php?cancelar=1">Volver al inicio de sesión</a>
                </div>

            <?php elseif ($vista === 'configurar'): ?>
                <div class="mfa-paso"><span>1</span><div><b>Instala una app autenticadora</b><p>Google Authenticator, Microsoft Authenticator o Authy, gratis en la tienda de tu celular.</p></div></div>
                <div class="mfa-paso"><span>2</span><div><b>Escanea este código con la app</b><p>En la app toca <b>+</b> y luego <b>Escanear código QR</b>.</p>
                    <div class="mfa-qr">
                        <div id="qr" role="img" aria-label="Código QR para agregar DataSense a tu app autenticadora"></div>
                        <div class="mfa-qr-texto">
                            ¿No puedes escanearlo? Escribe esta clave en la app (tipo: basada en tiempo):
                            <div class="mfa-clave mt-2" id="claveManual"><?= esc(trim(chunk_split($secreto_nuevo, 4, ' '))) ?></div>
                            <a class="mfa-enlace d-inline-block mt-2" href="<?= esc(uriTotp($secreto_nuevo, $usuario['correo'])) ?>">Abrir en la app de este celular</a>
                        </div>
                    </div>
                </div></div>
                <div class="mfa-paso"><span>3</span><div class="flex-grow-1"><b>Escribe el código que aparece en la app</b><p>Así confirmamos que quedó bien configurada.</p>
                    <form method="post" action="doble_factor.php" class="mt-2" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
                        <label for="codigo" class="visually-hidden">Código de 6 dígitos</label>
                        <input type="text" class="form-control mfa-codigo" id="codigo" name="codigo" required inputmode="numeric" autocomplete="one-time-code"
                               pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000">
                        <button type="submit" class="btn btn-mfa w-100 mt-3"><i class="fas fa-shield-halved me-2" aria-hidden="true"></i>Activar la verificación</button>
                    </form>
                </div></div>
                <div class="text-end"><a class="mfa-enlace" href="doble_factor.php?cancelar=1"><?= $flujo['origen'] === 'perfil' ? 'Cancelar' : 'Hacerlo después y salir' ?></a></div>

            <?php else: ?>
                <div class="mfa-lista" id="listaCodigos">
                    <?php foreach ($codigos['codigos'] as $c): ?><code><?= esc($c) ?></code><?php endforeach; ?>
                </div>
                <div class="d-flex gap-2 mb-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="copiarCodigos"><i class="far fa-copy me-1" aria-hidden="true"></i> Copiar</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="descargarCodigos"><i class="fas fa-download me-1" aria-hidden="true"></i> Descargar .txt</button>
                </div>
                <form method="post" action="doble_factor.php">
                    <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="guardados" required>
                        <label class="form-check-label small" for="guardados">Ya guardé mis códigos de recuperación en un lugar seguro.</label>
                    </div>
                    <button type="submit" class="btn btn-mfa w-100" id="continuar" disabled><?= $codigos['origen'] === 'perfil' ? 'Volver a mi perfil' : 'Entrar al panel' ?> <i class="fas fa-arrow-right ms-2" aria-hidden="true"></i></button>
                </form>
            <?php endif; ?>

            <div class="mfa-pie"><i class="fas fa-lock me-1 text-success" aria-hidden="true"></i> Verificación en dos pasos (TOTP) · Tu clave secreta se guarda cifrada</div>
        </div>
    </div>
</main>

<?php if ($vista === 'configurar'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr'), { text: <?= jsJson(uriTotp($secreto_nuevo, $usuario['correo'])) ?>, width: 168, height: 168, colorDark: '#0A2E4F', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
</script>
<?php endif; ?>
<script>
(function () {
    // Solo dígitos en el código de la app (los de recuperación llevan letras)
    const campo = document.getElementById('codigo');
    if (campo) campo.addEventListener('input', function () {
        if (document.getElementById('modoCodigo') && document.getElementById('modoCodigo').value === 'recuperacion') return;
        campo.value = campo.value.replace(/[^\d ]/g, '');
    });

    const recuperacion = document.getElementById('usarRecuperacion');
    if (recuperacion) recuperacion.addEventListener('click', function () {
        const modo = document.getElementById('modoCodigo');
        const usar = modo.value !== 'recuperacion';
        modo.value = usar ? 'recuperacion' : 'app';
        campo.value = '';
        campo.classList.toggle('recuperacion', usar);
        campo.setAttribute('inputmode', usar ? 'text' : 'numeric');
        campo.setAttribute('maxlength', usar ? '9' : '7');
        campo.setAttribute('pattern', usar ? '[A-Za-z0-9-]{8,9}' : '[0-9 ]{6,7}');
        campo.setAttribute('placeholder', usar ? 'XXXX-XXXX' : '000000');
        document.getElementById('etiquetaCodigo').textContent = usar ? 'Código de recuperación' : 'Código de la app autenticadora';
        recuperacion.textContent = usar ? 'Usar el código de la app' : '¿Perdiste el celular? Usa un código de recuperación';
        campo.focus();
    });

    // Demostración: cuenta regresiva del código mostrado; al vencer se recarga para ver el siguiente
    const segundos = document.getElementById('segundosDemo');
    if (segundos) {
        let quedan = parseInt(segundos.textContent, 10);
        setInterval(function () {
            quedan--;
            if (quedan <= 0 && !(campo && campo.value)) { window.location.reload(); return; }
            segundos.textContent = Math.max(0, quedan);
        }, 1000);
    }

    const lista = document.getElementById('listaCodigos');
    if (lista) {
        const texto = 'Códigos de recuperación de DataSense (' + <?= jsJson($usuario['correo']) ?> + ')\r\nCada código sirve una sola vez.\r\n\r\n'
            + Array.prototype.map.call(lista.querySelectorAll('code'), function (c) { return c.textContent; }).join('\r\n') + '\r\n';
        document.getElementById('copiarCodigos').addEventListener('click', function () {
            const boton = this;
            const listo = function () { boton.innerHTML = '<i class="fas fa-check me-1"></i> Copiados'; };
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(listo).catch(function () {});
            else {
                const area = document.createElement('textarea'); area.value = texto; document.body.appendChild(area); area.select();
                try { document.execCommand('copy'); listo(); } catch (e) { /* el usuario puede seleccionarlos a mano */ }
                area.remove();
            }
        });
        document.getElementById('descargarCodigos').addEventListener('click', function () {
            const enlace = document.createElement('a');
            enlace.href = URL.createObjectURL(new Blob(['﻿' + texto], { type: 'text/plain;charset=utf-8' }));
            enlace.download = 'datasense-codigos-recuperacion.txt';
            document.body.appendChild(enlace); enlace.click(); enlace.remove();
        });
        document.getElementById('guardados').addEventListener('change', function () {
            document.getElementById('continuar').disabled = !this.checked;
        });
    }
})();
</script>
</body>
</html>
