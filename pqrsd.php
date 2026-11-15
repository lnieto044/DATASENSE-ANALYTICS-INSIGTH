<?php
/**
 * PQRSD - CANAL CIUDADANO (página pública)
 * Radica Peticiones, Quejas, Reclamos, Sugerencias y Denuncias, y permite consultar el estado con el
 * número de radicado y su clave. Términos legales en días hábiles (Ley 1755 de 2015).
 */
require_once __DIR__ . '/includes/seguridad.php';
iniciarSesionSegura();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/pqrsd.php';
require_once __DIR__ . '/includes/utilidades.php';
require_once __DIR__ . '/includes/redes.php'; // iconos de las redes y botón de WhatsApp

use PHPMailer\PHPMailer\Exception;

$db = require __DIR__ . '/config/database.php';

if (empty($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(32));
if (empty($_SESSION['form_pqrsd_desde'])) $_SESSION['form_pqrsd_desde'] = time();

$error = '';
$consulta = null;      // solicitud encontrada al consultar
$historial_publico = [];
$datos = ['tipo' => 'Petición', 'anonima' => '', 'nombre' => '', 'tipo_documento' => 'CC', 'documento' => '', 'correo' => '',
          'telefono' => '', 'direccion' => '', 'ciudad' => '', 'medio_respuesta' => 'Correo', 'asunto' => '', 'descripcion' => '',
          'prioritaria' => '', 'motivo_prioritaria' => ''];
$valor = function ($campo) use (&$datos) { return esc($datos[$campo] ?? ''); };

// Icono de cada tipo de solicitud (solo presentación)
$iconos_pqrsd = ['Petición' => 'fa-hand-point-up', 'Queja' => 'fa-face-frown', 'Reclamo' => 'fa-triangle-exclamation',
                 'Sugerencia' => 'fa-lightbulb', 'Denuncia' => 'fa-user-secret', 'Solicitud de información' => 'fa-folder-open',
                 'Consulta' => 'fa-circle-question'];

if (!pqrsdDisponible($db)) {
    $error = 'El canal de PQRSD todavía no está habilitado en la base de datos.';
}

// ---------------------------------------------------------------- RADICAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'radicar' && !$error) {
    foreach (array_keys($datos) as $campo) $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
    $desde = (int) ($_SESSION['form_pqrsd_desde'] ?? 0);
    $anonima = $datos['anonima'] === '1' && $datos['tipo'] === 'Denuncia';

    if (!isset($_POST['csrf_token'], $_SESSION['token']) || !hash_equals($_SESSION['token'], (string) $_POST['csrf_token'])) {
        $error = 'La sesión del formulario venció. Vuelve a enviarlo.';
    } elseif (!$desde || time() - $desde < 3 || registrarIntento('pqrsd|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 3600) > 5) {
        $error = 'Espera un momento antes de enviar otra solicitud desde este equipo.';
    } elseif (!isset(TIPOS_PQRSD[$datos['tipo']])) {
        $error = 'Elige el tipo de solicitud.';
    } elseif ($datos['asunto'] === '' || mb_strlen($datos['descripcion']) < 20) {
        $error = 'Escribe el asunto y explica tu solicitud (mínimo 20 caracteres).';
    } elseif (!$anonima && ($datos['nombre'] === '' || !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL))) {
        $error = 'Escribe tu nombre y un correo válido. Solo las denuncias pueden ser anónimas.';
    } elseif (empty($_POST['autorizacion'])) {
        $error = 'Debes autorizar el tratamiento de tus datos personales.';
    } elseif ($datos['prioritaria'] === '1' && !in_array($datos['motivo_prioritaria'], MOTIVOS_PRIORITARIOS_PQRSD, true)) {
        $error = 'Si pides atención prioritaria, escoge por cuál de los motivos del artículo 20 de la Ley 1755 de 2015.';
    }
    // La atención prioritaria hay que poder acreditarla: en una denuncia anónima no aplica
    if ($anonima) $datos['prioritaria'] = '';

    // Adjunto opcional: PDF o imagen, hasta 5 MB
    $adjunto = null;
    if (!$error && !empty($_FILES['adjunto']['name'])) {
        $archivo = $_FILES['adjunto'];
        $extension = mb_strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if ($archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] > 5 * 1024 * 1024) {
            $error = 'El archivo debe pesar menos de 5 MB.';
        } elseif (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], true)) {
            $error = 'El archivo debe ser PDF, imagen o documento de Word.';
        } else {
            $carpeta = __DIR__ . '/uploads/pqrsd';
            if (!is_dir($carpeta)) @mkdir($carpeta, 0775, true);
            $nombre_archivo = 'pqrsd_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            if (@move_uploaded_file($archivo['tmp_name'], "$carpeta/$nombre_archivo")) $adjunto = "pqrsd/$nombre_archivo";
            else $error = 'No fue posible guardar el archivo adjunto.';
        }
    }

    if (!$error) {
        try {
            list($id, $radicado, $token) = radicarPqrsd($db, array_merge($datos, [
                'anonima' => $anonima, 'adjunto' => $adjunto,
                'usuario_id' => $_SESSION['admin_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]));
            $_SESSION['form_pqrsd_desde'] = time();
            $solicitud = pqrsdPorId($db, $id);
            $termino = date('d/m/Y', strtotime($solicitud['fecha_limite']));

            // Aviso interno
            try {
                $aviso = nuevoCorreo('DataSense Analytics');
                $aviso->addAddress($_ENV['MAIL_USER']);
                if (!$anonima && $datos['correo']) $aviso->addReplyTo($datos['correo'], $datos['nombre']);
                $aviso->isHTML(true);
                $aviso->Subject = "[$radicado] Nueva {$datos['tipo']} en el canal PQRSD";
                $aviso->Body = '<p><strong>' . esc($datos['tipo']) . '</strong> radicada el ' . date('d/m/Y H:i') . '.</p>'
                    . '<p><strong>Radicado:</strong> ' . esc($radicado) . '<br><strong>Término legal:</strong> ' . esc($solicitud['dias_termino']) . ' días hábiles (vence el ' . $termino . ')</p>'
                    . '<p><strong>Ciudadano:</strong> ' . ($anonima ? 'Anónimo' : esc($datos['nombre']) . ' · ' . esc($datos['correo'])) . '</p>'
                    . '<p><strong>Asunto:</strong> ' . esc($datos['asunto']) . '</p>'
                    . '<div style="background:#f8fafc;padding:14px;border-left:4px solid #0A2E4F">' . nl2br(esc($datos['descripcion'])) . '</div>';
                $aviso->send();
            } catch (Exception $e) {
                error_log('PQRSD, aviso interno: ' . $e->getMessage());
            }

            // Acuse de recibo al ciudadano
            if (!$anonima && $datos['correo']) {
                try {
                    $acuse = nuevoCorreo('DataSense Analytics');
                    $acuse->addAddress($datos['correo'], $datos['nombre']);
                    $acuse->isHTML(true);
                    $acuse->Subject = "Radicado $radicado · recibimos tu " . mb_strtolower($datos['tipo'], 'UTF-8');
                    $acuse->Body = '<p>Hola ' . esc($datos['nombre']) . ',</p>'
                        . '<p>Recibimos tu ' . esc(mb_strtolower($datos['tipo'], 'UTF-8')) . ' y quedó radicada así:</p>'
                        . '<p style="font-size:18px"><strong>' . esc($radicado) . '</strong><br>Clave de consulta: <strong>' . esc($token) . '</strong></p>'
                        . '<p>Tenemos <strong>' . (int) $solicitud['dias_termino'] . ' días hábiles</strong> para responderte; el término vence el <strong>' . $termino . '</strong>.</p>'
                        . '<p style="color:#475569;font-size:14px">Atendemos ' . esc(horarioTextoPqrsd()) . ', sin festivos de Colombia; los días hábiles se cuentan con ese horario.</p>'
                        . (!empty($solicitud['prioritaria'])
                            ? '<p><strong>Pediste atención prioritaria</strong> (Ley 1755 de 2015, art. 20). La revisaremos y, si se acredita la condición, te atenderemos de forma preferente.</p>' : '')
                        . '<p style="color:#475569;font-size:14px">Si necesitamos más tiempo te avisaremos <strong>antes</strong> de que venza el término, '
                        . 'con los motivos y la nueva fecha (art. 14). Si nos falta información te la pediremos y tendrás un mes para completarla (art. 17). '
                        . 'Y si el asunto no es de nuestra competencia, lo trasladaremos a quien corresponda dentro de los 5 días siguientes y te lo '
                        . 'informaremos (art. 21). Todo conforme a la Ley 1755 de 2015.</p>'
                        . '<p>Puedes consultar el estado en ' . esc(urlBase()) . 'pqrsd.php con tu número de radicado y la clave.</p>'
                        . '<p>Equipo DataSense Analytics</p>';
                    $acuse->send();
                    registrarEventoPqrsd($db, $id, null, 'Acuse de recibo', 'Se envió el acuse con el radicado y el término al correo del ciudadano.');
                } catch (Exception $e) {
                    error_log('PQRSD, acuse al ciudadano: ' . $e->getMessage());
                }
            }
            // Reparto automático: la solicitud queda con responsable desde el primer momento
            $responsable = asignarPqrsdAutomaticamente($db, $id);
            if ($responsable) avisarResponsablePqrsd($db, $id, $responsable);

            header("Location: pqrsd.php?radicado=" . urlencode($radicado) . "&clave=" . urlencode($token) . "&nueva=1");
            exit;
        } catch (Exception $e) {
            error_log('PQRSD, radicación: ' . $e->getMessage());
            $error = 'No fue posible radicar la solicitud. Intenta de nuevo en unos minutos.';
        }
    }
}

// ---------------------------------------------------------------- CONSULTAR
$radicado_consulta = trim((string) ($_GET['radicado'] ?? $_POST['radicado_consulta'] ?? ''));
$clave_consulta = trim((string) ($_GET['clave'] ?? $_POST['clave_consulta'] ?? ''));
$es_nueva = ($_GET['nueva'] ?? '') === '1';
if (!$error && $radicado_consulta !== '' && $clave_consulta !== '') {
    $consulta = pqrsdPorRadicado($db, $radicado_consulta, $clave_consulta);
    if ($consulta) $historial_publico = historialPqrsd($db, $consulta['id'], true);
    elseif (!$es_nueva) $error = 'No encontramos una solicitud con ese radicado y esa clave.';
}

// ---------------------------------------------------------------- Indicadores públicos (lo que la gente quiere saber)
$indicadores = ['atendidas' => 0, 'cumplimiento' => 0, 'promedio' => 0];
if (pqrsdDisponible($db)) {
    $resumen = resumenPqrsd($db);
    $indicadores['atendidas'] = (int) $resumen['respondidas'];
    $indicadores['cumplimiento'] = (int) round($resumen['cumplimiento']);
    // Promedio de días hábiles entre la radicación y la respuesta
    $dias = [];
    foreach ($db->query("SELECT fecha_registro, COALESCE(fecha_respuesta, fecha_cierre) AS fin FROM pqrsd
                         WHERE estado IN ('Respondida', 'Cerrada') AND COALESCE(fecha_respuesta, fecha_cierre) IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $cursor = new DateTime(substr($p['fecha_registro'], 0, 10));
        $fin = new DateTime(substr($p['fin'], 0, 10));
        $habiles = 0;
        while ($cursor < $fin) {
            $cursor->modify('+1 day');
            if ((int) $cursor->format('N') <= 5 && !isset(festivosColombia((int) $cursor->format('Y'))[$cursor->format('Y-m-d')])) $habiles++;
        }
        $dias[] = $habiles;
    }
    $indicadores['promedio'] = $dias ? (int) round(array_sum($dias) / count($dias)) : 0;
}
$csrf = $_SESSION['token'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PQRSD · Peticiones, Quejas, Reclamos, Sugerencias y Denuncias | DataSense Analytics</title>
    <meta name="description" content="Radica tu petición, queja, reclamo, sugerencia o denuncia ante DataSense Analytics y consulta su estado con el número de radicado.">
    <link rel="icon" href="img/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { --azul: #0A2E4F; --azul-claro: #14507f; --verde: #3FAE49; --texto: #1f2937; --suave: #f4f6fa; }
        * { scroll-behavior: smooth; }
        body { background: var(--suave); color: var(--texto); }

        /* ---------------------------------------------------------------- barra superior */
        .barra-pqrsd { background: rgba(255, 255, 255, .92); backdrop-filter: blur(10px); border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; z-index: 20; transition: box-shadow .25s ease, padding .25s ease; }
        .barra-pqrsd.encogida { box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
        .barra-pqrsd img { height: 42px; transition: height .25s ease; }
        .barra-pqrsd.encogida img { height: 34px; }
        .enlace-barra { color: #475569; font-weight: 600; text-decoration: none; position: relative; }
        .enlace-barra::after { content: ''; position: absolute; left: 0; bottom: -4px; width: 0; height: 2px; background: var(--verde); transition: width .25s ease; }
        .enlace-barra:hover { color: var(--azul); }
        .enlace-barra:hover::after { width: 100%; }

        /* ---------------------------------------------------------------- portada */
        /* Fondo: la misma imagen de la portada del sitio, con un velo azul degradado que se mueve despacio */
        .hero-pqrsd { position: relative; overflow: hidden; color: #fff;
            background-image: linear-gradient(120deg, rgba(10, 46, 79, .94) 0%, rgba(20, 80, 127, .88) 55%, rgba(27, 111, 176, .80) 100%), url('img/banner.png');
            background-size: 180% 180%, cover; background-position: 0% 50%, center; background-repeat: no-repeat;
            animation: mover-fondo 18s ease infinite; }
        @keyframes mover-fondo { 0%, 100% { background-position: 0% 50%, center; } 50% { background-position: 100% 50%, center; } }
        .hero-pqrsd::after { content: ''; position: absolute; inset: 0; background-image: radial-gradient(rgba(255, 255, 255, .14) 1px, transparent 1px); background-size: 22px 22px; opacity: .45; }
        .hero-pqrsd h1 { text-shadow: 0 2px 18px rgba(0, 0, 0, .25); }
        .hero-pqrsd > .container { position: relative; z-index: 1; }
        .chip-termino { background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .25); border-radius: 999px; padding: .35rem .9rem; font-size: .82rem; font-weight: 600; display: inline-flex; align-items: center; gap: .4rem; }

        /* ---------------------------------------------------------------- indicadores */
        .tira-indicadores { margin-top: -2.5rem; position: relative; z-index: 2; }
        .indicador { background: #fff; border-radius: 16px; box-shadow: 0 8px 28px rgba(15, 23, 42, .08); padding: 1.1rem 1.2rem; height: 100%; display: flex; align-items: center; gap: .9rem; }
        .indicador i { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: #eef6ff; color: var(--azul); font-size: 1.1rem; flex-shrink: 0; }
        .indicador .numero { font-size: 1.6rem; font-weight: 800; color: #0f172a; line-height: 1; }
        .indicador .texto { font-size: .78rem; color: #64748b; }

        /* ---------------------------------------------------------------- tarjetas y formulario */
        .tarjeta { background: #fff; border-radius: 18px; box-shadow: 0 2px 16px rgba(15, 23, 42, .06); border: 0; }
        .paso-numero { width: 26px; height: 26px; border-radius: 50%; background: var(--azul); color: #fff; font-size: .8rem; font-weight: 700; display: inline-grid; place-items: center; margin-right: .5rem; }
        .tipo-card { cursor: pointer; border: 2px solid #e5e7eb; border-radius: 14px; padding: .9rem; height: 100%; transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease, background .18s ease; position: relative; display: block; }
        .tipo-card:hover { transform: translateY(-3px); border-color: #cbd5e1; box-shadow: 0 10px 22px rgba(15, 23, 42, .08); }
        .tipo-card input { position: absolute; opacity: 0; }
        .tipo-card:has(input:checked) { border-color: var(--verde); background: #f0fdf4; box-shadow: 0 10px 22px rgba(63, 174, 73, .18); }
        .tipo-card:has(input:focus-visible) { outline: 3px solid rgba(63, 174, 73, .45); outline-offset: 2px; }
        .tipo-card .icono { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; background: #eef6ff; color: var(--azul); margin-bottom: .5rem; transition: background .18s ease, color .18s ease; }
        .tipo-card:has(input:checked) .icono { background: var(--verde); color: #fff; }
        .tipo-card:has(input:checked)::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; top: .6rem; right: .7rem; color: var(--verde); font-size: .85rem; }
        .tipo-card .dias { font-size: .72rem; color: #64748b; display: block; }
        .form-label { font-weight: 600; font-size: .85rem; color: #334155; }
        .form-control, .form-select { border-radius: 10px; border-color: #e2e8f0; padding: .6rem .8rem; }
        .form-control:focus, .form-select:focus { border-color: var(--verde); box-shadow: 0 0 0 .2rem rgba(63, 174, 73, .15); }
        .btn-radicar { background: var(--verde); color: #fff; border: 0; border-radius: 999px; padding: .75rem 2rem; font-weight: 700; transition: transform .15s ease, box-shadow .15s ease, filter .15s ease; }
        .btn-radicar:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(63, 174, 73, .3); color: #fff; }
        .btn-radicar:disabled { filter: grayscale(.4); transform: none; }
        .panel-consulta { position: sticky; top: 92px; }

        /* ---------------------------------------------------------------- resultado de la consulta */
        .dato-radicado { font-size: 1.7rem; font-weight: 800; letter-spacing: 1px; color: var(--azul); }
        .linea-tiempo-pqrsd { list-style: none; padding: 0; margin: 0; }
        .linea-tiempo-pqrsd li { position: relative; padding: 0 0 1rem 1.9rem; animation: aparecer .4s ease both; }
        .linea-tiempo-pqrsd li::before { content: ''; position: absolute; left: .45rem; top: 1.3rem; bottom: 0; width: 2px; background: #e2e8f0; }
        .linea-tiempo-pqrsd li:last-child::before { display: none; }
        .linea-tiempo-pqrsd .punto { position: absolute; left: 0; top: .35rem; width: 1rem; height: 1rem; border-radius: 50%; background: var(--verde); box-shadow: 0 0 0 4px rgba(63, 174, 73, .18); }

        /* ---------------------------------------------------------------- animaciones */
        /* La animación solo agrega movimiento: el contenido siempre queda visible aunque el navegador no la dispare */
        .aparece { animation: aparecer .55s ease both; }
        @keyframes aparecer { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            *, .hero-pqrsd, .aparece { animation: none !important; transition: none !important; }
        }
        footer.pie { color: #64748b; font-size: .85rem; }
    </style>
</head>
<body>
<header class="barra-pqrsd py-2" id="barraPqrsd">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
        <a href="index.php"><img src="img/logo.png" alt="DataSense Analytics"></a>
        <nav class="d-flex flex-wrap gap-3 align-items-center">
            <a href="index.php" class="enlace-barra">Inicio</a>
            <a href="tienda.php" class="enlace-barra">Tienda</a>
            <a href="index.php#contacto" class="enlace-barra">Contacto</a>
            <a href="admin/login.php" class="btn btn-sm text-white fw-bold rounded-pill px-3" style="background: var(--azul);">Iniciar sesión</a>
        </nav>
    </div>
</header>

<section class="hero-pqrsd py-5">
    <div class="container py-4">
        <span class="chip-termino mb-3"><i class="fas fa-scale-balanced"></i> Ley 1755 de 2015</span>
        <h1 class="fw-bold mt-3 mb-2" style="letter-spacing: -.5px;">Canal de PQRSD</h1>
        <p class="mb-4" style="max-width: 720px; font-size: 1.05rem;">Radica tu <strong>petición, queja, reclamo, sugerencia o denuncia</strong> y haz seguimiento con tu número de radicado. Te respondemos dentro de los términos de ley.</p>
        <div class="d-flex flex-wrap gap-2">
            <span class="chip-termino"><i class="fas fa-clock"></i> 15 días hábiles · peticiones, quejas, reclamos, sugerencias y denuncias</span>
            <span class="chip-termino"><i class="fas fa-business-time"></i> Atención <?= esc(horarioTextoPqrsd()) ?> · sin festivos</span>
            <span class="chip-termino"><i class="fas fa-file-lines"></i> 10 · información y copias</span>
            <span class="chip-termino"><i class="fas fa-comments"></i> 30 · consultas</span>
        </div>
    </div>
</section>

<div class="container tira-indicadores">
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="indicador aparece"><i class="fas fa-circle-check"></i>
                <div><div class="numero" data-contador="<?= (int) $indicadores['atendidas'] ?>">0</div><div class="texto">solicitudes atendidas</div></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="indicador aparece"><i class="fas fa-gauge-high"></i>
                <div><div class="numero"><span data-contador="<?= (int) $indicadores['cumplimiento'] ?>">0</span>%</div><div class="texto">respondidas dentro del término</div></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="indicador aparece"><i class="fas fa-stopwatch"></i>
                <div><div class="numero" data-contador="<?= (int) $indicadores['promedio'] ?>">0</div><div class="texto">días hábiles en promedio</div></div>
            </div>
        </div>
    </div>
</div>

<main class="container py-5">
    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4 shadow-sm border-0" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?= esc($error) ?></div>
    <?php endif; ?>

    <?php if ($consulta): ?>
        <?php $termino = textoTerminoPqrsd($consulta); ?>
        <div class="tarjeta p-4 p-md-5 mb-4 aparece" id="resultadoConsulta">
            <?php if ($es_nueva): ?>
                <div class="alert alert-success border-0 rounded-4"><i class="fas fa-circle-check me-2"></i><strong>¡Listo!</strong> Tu solicitud quedó radicada. Guarda el número y la clave: con ellos consultas el estado.</div>
            <?php endif; ?>
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="small text-muted text-uppercase fw-bold">Radicado</div>
                    <div class="dato-radicado" id="numeroRadicado"><?= esc($consulta['radicado']) ?></div>
                    <div class="small text-muted">Clave de consulta: <code id="claveConsulta"><?= esc($consulta['token_consulta']) ?></code>
                        <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-decoration-none" id="btnCopiarClave" title="Copiar el radicado y la clave"><i class="far fa-copy"></i></button></div>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <span class="badge text-bg-<?= esc(COLORES_PQRSD[$consulta['estado']] ?? 'secondary') ?> px-3 py-2"><?= esc($consulta['estado']) ?></span>
                        <span class="badge text-bg-<?= esc($termino['clase']) ?> px-3 py-2" id="estadoTermino"><?= esc($termino['etiqueta']) ?></span>
                    </div>
                    <div class="small text-muted mt-2" id="textoTermino"><?= esc($termino['texto']) ?></div>
                </div>
                <div class="col-md-7">
                    <div class="small text-muted text-uppercase fw-bold">Tipo</div>
                    <div class="fw-semibold mb-2"><?= esc($consulta['tipo']) ?> · radicada el <?= date('d/m/Y H:i', strtotime($consulta['fecha_registro'])) ?></div>
                    <div class="small text-muted text-uppercase fw-bold">Asunto</div>
                    <div class="fw-semibold mb-2"><?= esc($consulta['asunto']) ?></div>
                    <?php if (!empty($consulta['prioritaria'])): ?>
                        <div class="alert alert-danger border-0 rounded-3 small py-2" id="avisoPrioritaria"><i class="fas fa-bolt me-1"></i>
                            <strong>Atención prioritaria</strong> · <?= esc($consulta['motivo_prioritaria']) ?> (art. 20)</div>
                    <?php endif; ?>
                    <?php if (!empty($consulta['prorroga_hasta'])): ?>
                        <div class="alert alert-warning border-0 rounded-3 small" id="avisoProrroga"><i class="fas fa-hourglass-half me-1"></i>
                            <strong>Prórroga del término</strong> (art. 14): te responderemos a más tardar el <strong><?= date('d/m/Y', strtotime($consulta['prorroga_hasta'])) ?></strong>.
                            <?php if (!empty($consulta['prorroga_motivo'])): ?><br><span class="text-muted">Motivos: <?= esc($consulta['prorroga_motivo']) ?></span><?php endif; ?></div>
                    <?php endif; ?>
                    <?php if ($consulta['estado'] === 'En espera del ciudadano'): ?>
                        <div class="alert alert-info border-0 rounded-3 small" id="avisoRequerimiento"><i class="fas fa-circle-question me-1"></i>
                            <strong>Nos falta información</strong> (art. 17): <?= esc($consulta['requerimiento_detalle']) ?><br>
                            Tienes hasta el <strong><?= !empty($consulta['desiste_el']) ? date('d/m/Y', strtotime($consulta['desiste_el'])) : '—' ?></strong> para completarla.
                            Mientras tanto el término está suspendido; si no recibimos respuesta se entenderá que desististe.</div>
                    <?php endif; ?>
                    <?php if (!empty($consulta['trasladada_a'])): ?>
                        <div class="alert alert-secondary border-0 rounded-3 small" id="avisoTraslado"><i class="fas fa-share-from-square me-1"></i>
                            <strong>Trasladada por competencia</strong> (art. 21) a <?= esc($consulta['trasladada_a']) ?>
                            el <?= !empty($consulta['fecha_traslado']) ? date('d/m/Y', strtotime($consulta['fecha_traslado'])) : '—' ?>. Esa entidad continúa con el trámite.</div>
                    <?php endif; ?>
                    <?php if (!empty($consulta['respuesta'])): ?>
                        <div class="small text-muted text-uppercase fw-bold">Respuesta</div>
                        <div class="p-3 bg-light rounded-3 border-start border-4 border-success" id="respuestaPqrsd"><?= nl2br(esc($consulta['respuesta'])) ?></div>
                    <?php elseif ($consulta['estado'] === 'Desistida'): ?>
                        <div class="alert alert-secondary border-0 rounded-3 mb-0 small">La solicitud se archivó por desistimiento (art. 17). Si todavía necesitas la respuesta, puedes radicarla de nuevo.</div>
                    <?php elseif ($consulta['estado'] !== 'Trasladada'): ?>
                        <div class="alert alert-info border-0 rounded-3 mb-0 small">Tu solicitud está en trámite. Te responderemos dentro del término legal.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($historial_publico): ?>
                <hr class="my-4">
                <div class="small text-muted text-uppercase fw-bold mb-3">Seguimiento</div>
                <ul class="linea-tiempo-pqrsd">
                    <?php foreach ($historial_publico as $i => $h): ?>
                        <li style="animation-delay: <?= min(6, $i) * 80 ?>ms">
                            <span class="punto"></span>
                            <div class="fw-semibold"><?= esc($h['evento']) ?> <span class="small text-muted fw-normal">· <?= date('d/m/Y H:i', strtotime($h['fecha'])) ?></span></div>
                            <?php if ($h['detalle']): ?><div class="small text-muted"><?= esc($h['detalle']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="tarjeta p-4 p-md-5 aparece">
                <h2 class="h5 fw-bold mb-4"><i class="fas fa-pen-to-square text-primary me-2"></i>Radicar una solicitud</h2>
                <form method="POST" enctype="multipart/form-data" id="formPqrsd" novalidate>
                    <input type="hidden" name="accion" value="radicar">
                    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">

                    <label class="form-label d-flex align-items-center"><span class="paso-numero">1</span>¿Qué vas a radicar? *</label>
                    <div class="row g-2 mb-4" id="tiposPqrsd">
                        <?php foreach (TIPOS_PQRSD as $tipo => $info): ?>
                            <div class="col-6 col-md-4 col-xl-3">
                                <label class="tipo-card">
                                    <input type="radio" name="tipo" value="<?= esc($tipo) ?>" <?= $datos['tipo'] === $tipo ? 'checked' : '' ?> required>
                                    <span class="icono"><i class="fas <?= esc($iconos_pqrsd[$tipo] ?? 'fa-file-lines') ?>"></i></span>
                                    <span class="fw-bold d-block"><?= esc($tipo) ?></span>
                                    <span class="dias"><?= (int) $info['dias'] ?> días hábiles</span>
                                    <span class="dias"><?= esc($info['texto']) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="alert alert-warning border-0 rounded-3 py-2 px-3" id="bloqueAnonima" hidden>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="anonima" value="1" id="anonima" <?= $datos['anonima'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="anonima">Quiero que la denuncia sea <strong>anónima</strong>. No pediremos tus datos, pero no podremos enviarte la respuesta por correo.</label>
                        </div>
                    </div>

                    <div id="datosCiudadano">
                        <label class="form-label d-flex align-items-center mt-2"><span class="paso-numero">2</span>Tus datos</label>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="nombre">Nombre completo *</label><input type="text" class="form-control" id="nombre" name="nombre" maxlength="150" value="<?= $valor('nombre') ?>" placeholder="Como aparece en tu documento"></div>
                            <div class="col-md-3"><label class="form-label" for="tipo_documento">Documento</label>
                                <select class="form-select" id="tipo_documento" name="tipo_documento">
                                    <?php foreach (['CC' => 'Cédula', 'CE' => 'Cédula de extranjería', 'NIT' => 'NIT', 'PA' => 'Pasaporte'] as $codigo => $nombre_doc): ?>
                                        <option value="<?= esc($codigo) ?>" <?= $datos['tipo_documento'] === $codigo ? 'selected' : '' ?>><?= esc($nombre_doc) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label" for="documento">Número</label><input type="text" class="form-control" id="documento" name="documento" maxlength="30" value="<?= $valor('documento') ?>"></div>
                            <div class="col-md-6"><label class="form-label" for="correo">Correo electrónico *</label><input type="email" class="form-control" id="correo" name="correo" maxlength="150" value="<?= $valor('correo') ?>" placeholder="Ahí te llega el radicado"></div>
                            <div class="col-md-3"><label class="form-label" for="telefono">Teléfono</label><input type="text" class="form-control" id="telefono" name="telefono" maxlength="30" value="<?= $valor('telefono') ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="ciudad">Ciudad</label><input type="text" class="form-control" id="ciudad" name="ciudad" maxlength="80" value="<?= $valor('ciudad') ?>"></div>
                            <div class="col-md-8"><label class="form-label" for="direccion">Dirección (si quieres respuesta física)</label><input type="text" class="form-control" id="direccion" name="direccion" maxlength="200" value="<?= $valor('direccion') ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="medio_respuesta">¿Cómo te respondemos?</label>
                                <select class="form-select" id="medio_respuesta" name="medio_respuesta">
                                    <?php foreach (MEDIOS_RESPUESTA_PQRSD as $codigo => $texto): ?>
                                        <option value="<?= esc($codigo) ?>" <?= $datos['medio_respuesta'] === $codigo ? 'selected' : '' ?>><?= esc($texto) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="p-3 rounded-3" style="background: #eff6ff;">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="prioritaria" value="1" id="prioritaria" <?= $datos['prioritaria'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="prioritaria">Pido <strong>atención prioritaria</strong> (Ley 1755 de 2015, art. 20)</label>
                                    </div>
                                    <div id="bloqueMotivoPrioritaria" class="mt-2" <?= $datos['prioritaria'] === '1' ? '' : 'hidden' ?>>
                                        <label class="form-label small mb-1" for="motivo_prioritaria">¿Por cuál motivo?</label>
                                        <select class="form-select form-select-sm" id="motivo_prioritaria" name="motivo_prioritaria">
                                            <?php foreach (MOTIVOS_PRIORITARIOS_PQRSD as $m): ?>
                                                <option value="<?= esc($m) ?>" <?= $datos['motivo_prioritaria'] === $m ? 'selected' : '' ?>><?= esc($m) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Podemos pedirte el soporte que acredite la condición.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <label class="form-label d-flex align-items-center mt-4"><span class="paso-numero">3</span>Cuéntanos qué pasó</label>
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label" for="asunto">Asunto *</label><input type="text" class="form-control" id="asunto" name="asunto" maxlength="180" required value="<?= $valor('asunto') ?>" placeholder="Ej: Solicitud de copia de mi factura de agosto"></div>
                        <div class="col-12"><label class="form-label" for="descripcion">Detalle *</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="6" required minlength="20" placeholder="Describe los hechos, las fechas y lo que necesitas."><?= esc($datos['descripcion']) ?></textarea>
                            <div class="d-flex justify-content-between"><span class="form-text">Mínimo 20 caracteres.</span><span class="form-text" id="contadorTexto">0</span></div>
                        </div>
                        <div class="col-12"><label class="form-label" for="adjunto">Adjuntar un soporte (opcional)</label>
                            <input type="file" class="form-control" id="adjunto" name="adjunto" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                            <div class="form-text">PDF, imagen o Word, hasta 5 MB.</div>
                        </div>
                    </div>

                    <label class="form-label d-flex align-items-center mt-4"><span class="paso-numero">4</span>Autorización y envío</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="autorizacion" value="1" id="autorizacion" required>
                        <label class="form-check-label small" for="autorizacion">Autorizo el tratamiento de mis datos personales para atender esta solicitud, conforme a la Ley 1581 de 2012. *</label>
                    </div>
                    <button type="submit" class="btn btn-radicar mt-3 shadow-sm" id="btnRadicar">
                        <i class="fas fa-paper-plane me-2"></i><span id="textoBoton">RADICAR SOLICITUD</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="panel-consulta">
                <div class="tarjeta p-4 mb-4 aparece">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-magnifying-glass text-primary me-2"></i>Consultar el estado</h2>
                    <form method="GET" class="row g-2">
                        <div class="col-12"><label class="form-label" for="radicado">Número de radicado</label><input type="text" class="form-control" id="radicado" name="radicado" placeholder="PQR-<?= date('Y') ?>-0001" value="<?= esc($radicado_consulta) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="clave">Clave de consulta</label><input type="text" class="form-control" id="clave" name="clave" placeholder="La que te llegó al correo" value="<?= esc($clave_consulta) ?>" required></div>
                        <div class="col-12"><button type="submit" class="btn btn-outline-primary w-100 rounded-pill fw-bold" id="btnConsultar">CONSULTAR</button></div>
                    </form>
                </div>
                <div class="tarjeta p-4 aparece">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-circle-info text-primary me-2"></i>Lo que debes saber</h2>
                    <ul class="small text-muted mb-0 ps-3">
                        <li class="mb-2">Atendemos <strong><?= esc(horarioTextoPqrsd()) ?></strong>, sin festivos de Colombia. Puedes radicar a cualquier hora: el término empieza a contar el día hábil siguiente.</li>
                        <li class="mb-2">Los términos se cuentan en <strong>días hábiles</strong> desde el día siguiente a la radicación, sin sábados, domingos ni festivos de Colombia: <strong>15</strong> para peticiones, quejas, reclamos, sugerencias y denuncias; <strong>10</strong> para información y copias; <strong>30</strong> para consultas (Ley 1755 de 2015). El plazo vence a la hora de cierre de la jornada.</li>
                        <li class="mb-2">Si necesitamos más tiempo te avisamos <strong>antes</strong> de que venza el término, con los motivos y la nueva fecha; la prórroga no puede pasar del doble del plazo inicial (art. 14).</li>
                        <li class="mb-2">Si nos falta información te la pedimos y tienes <strong>un mes</strong> para completarla; mientras tanto el término se suspende. Si no la completas, se entiende que desististe (art. 17).</li>
                        <li class="mb-2">Si el asunto no es de nuestra competencia lo <strong>trasladamos</strong> a quien corresponda dentro de los 5 días siguientes y te lo informamos (art. 21).</li>
                        <li class="mb-2">Tienen <strong>atención prioritaria</strong> los niños, niñas y adolescentes, las personas adultas mayores, las personas en situación de discapacidad, los periodistas en ejercicio y los casos con riesgo de un perjuicio irremediable a un derecho fundamental (art. 20).</li>
                        <li class="mb-2">Las <strong>denuncias</strong> pueden ser anónimas; en ese caso no podremos enviarte la respuesta, pero sí la investigaremos.</li>
                        <li class="mb-0">Tus datos se tratan solo para atender la solicitud (Ley 1581 de 2012). Guarda tu radicado y tu clave: con ellos consultas el estado en esta misma página.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</main>

<footer class="pie text-center py-4">
    © <?= date('Y') ?> DataSense Analytics Insights · Canal de PQRSD ·
    <a href="index.php" class="text-decoration-none">Volver al sitio</a>
    <div class="d-flex flex-wrap justify-content-center align-items-center gap-3 mt-3"><?= iconosRedes('Síguenos') ?></div>
</footer>
<?= estiloRedes() ?>
<?= botonWhatsapp('Hola, tengo una duda sobre una solicitud radicada en el canal de PQRSD.') ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ------------------------------------------------------- la opción anónima solo aplica a las denuncias
(function () {
    const bloque = document.getElementById('bloqueAnonima');
    const anonima = document.getElementById('anonima');
    const datos = document.getElementById('datosCiudadano');
    const nombre = document.getElementById('nombre');
    const correo = document.getElementById('correo');
    const pintar = function () {
        const tipo = document.querySelector('input[name="tipo"]:checked');
        const esDenuncia = tipo && tipo.value === 'Denuncia';
        bloque.hidden = !esDenuncia;
        if (!esDenuncia) anonima.checked = false;
        const ocultar = esDenuncia && anonima.checked;
        datos.hidden = ocultar;
        nombre.required = !ocultar;
        correo.required = !ocultar;
    };
    document.querySelectorAll('input[name="tipo"]').forEach((r) => r.addEventListener('change', pintar));
    anonima.addEventListener('change', pintar);
    pintar();
})();

// ------------------------------------------------------- atención prioritaria: el motivo solo si la piden
(function () {
    const casilla = document.getElementById('prioritaria');
    const bloque = document.getElementById('bloqueMotivoPrioritaria');
    if (!casilla || !bloque) return;
    const pintar = () => { bloque.hidden = !casilla.checked; };
    casilla.addEventListener('change', pintar);
    pintar();
})();

// ------------------------------------------------------- barra que se encoge, contadores y aparición al bajar
(function () {
    const barra = document.getElementById('barraPqrsd');
    window.addEventListener('scroll', () => barra.classList.toggle('encogida', window.scrollY > 24), { passive: true });

    const suave = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const contar = function (el) {
        const destino = Number(el.dataset.contador || 0);
        if (suave || destino === 0) { el.innerText = destino; return; }
        const inicio = performance.now();
        const paso = function (ahora) {
            const avance = Math.min(1, (ahora - inicio) / 900);
            el.innerText = Math.round(destino * (1 - Math.pow(1 - avance, 3)));
            if (avance < 1) requestAnimationFrame(paso);
        };
        requestAnimationFrame(paso);
    };

    const contarTodo = function () { document.querySelectorAll('[data-contador]').forEach(contar); };
    if ('IntersectionObserver' in window && !suave) {
        const observador = new IntersectionObserver((entradas) => {
            entradas.forEach((e) => {
                if (!e.isIntersecting) return;
                e.target.classList.add('visible');
                e.target.querySelectorAll('[data-contador]').forEach(contar);
                observador.unobserve(e.target);
            });
        }, { threshold: .18 });
        document.querySelectorAll('.aparece').forEach((el) => observador.observe(el));
        // Respaldo: si el navegador no alcanza a disparar el observador, los números se muestran igual
        setTimeout(contarTodo, 1200);
    } else {
        document.querySelectorAll('.aparece').forEach((el) => el.classList.add('visible'));
        contarTodo();
    }
})();

// ------------------------------------------------------- contador de caracteres y botón con estado
(function () {
    const texto = document.getElementById('descripcion');
    const contador = document.getElementById('contadorTexto');
    const pintar = () => { contador.innerText = texto.value.trim().length + ' caracteres'; };
    texto.addEventListener('input', pintar);
    pintar();

    document.getElementById('formPqrsd').addEventListener('submit', function () {
        const boton = document.getElementById('btnRadicar');
        setTimeout(() => {
            boton.disabled = true;
            document.getElementById('textoBoton').innerText = 'ENVIANDO...';
        }, 0);
    });
})();

// ------------------------------------------------------- copiar el radicado y la clave
(function () {
    const boton = document.getElementById('btnCopiarClave');
    if (!boton) return;
    boton.addEventListener('click', function () {
        const texto = document.getElementById('numeroRadicado').innerText.trim() + ' · ' + document.getElementById('claveConsulta').innerText.trim();
        const avisar = (ok) => { boton.innerHTML = ok ? '<i class="fas fa-check text-success"></i>' : '<i class="far fa-copy"></i>'; setTimeout(() => { boton.innerHTML = '<i class="far fa-copy"></i>'; }, 1800); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(() => avisar(true)).catch(() => avisar(false));
        else avisar(false);
    });
})();
</script>
</body>
</html>
