<?php
// Acciones del canal ciudadano: asignar responsable, cambiar el estado, responder al ciudadano y cerrar.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pqrsd.php';
require_once __DIR__ . '/../includes/utilidades.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;

$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'pqrsd');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido()) {
    denegarAcceso('pagina', 403);
}

$id = (int) ($_POST['id'] ?? 0);
$solicitud = pqrsdPorId($db, $id);
if (!$solicitud) {
    avisoSiguiente('danger', 'Esa solicitud no existe.');
    header('Location: pqrsd.php');
    exit;
}

$yo = (int) $usuario_actual['id'];
$ahora = date('Y-m-d H:i:s');

/**
 * Le avisa al ciudadano de la actuación y lo deja en el seguimiento (arts. 14, 17 y 21 de la Ley 1755 de 2015).
 * Devuelve el texto que se le suma al aviso del panel.
 */
function avisarActuacionPqrsd(PDO $db, $accion, $id, $usuario_id) {
    $p = pqrsdPorId($db, $id);
    if (!empty($p['anonima']) || !filter_var((string) $p['correo'], FILTER_VALIDATE_EMAIL)) {
        return ' No hay correo del ciudadano: la actuación queda en el seguimiento.';
    }
    $tipo = esc(mb_strtolower($p['tipo'], 'UTF-8'));
    $radicado = esc($p['radicado']);
    if ($accion === 'prorrogar') {
        $asunto = 'Prórroga del término · ' . $p['radicado'];
        $cuerpo = '<p>Estamos atendiendo tu ' . $tipo . ' <strong>' . $radicado . '</strong> y necesitamos más tiempo para responderte de fondo.</p>'
            . '<p><strong>Motivos:</strong> ' . nl2br(esc((string) $p['prorroga_motivo'])) . '</p>'
            . '<p>El término queda prorrogado hasta el <strong>' . date('d/m/Y', strtotime($p['prorroga_hasta'])) . '</strong>. '
            . 'Te avisamos antes de que venciera el plazo inicial, como lo exige el artículo 14 de la Ley 1755 de 2015.</p>';
    } elseif ($accion === 'requerir') {
        $asunto = 'Nos falta información para atender tu ' . mb_strtolower($p['tipo'], 'UTF-8') . ' ' . $p['radicado'];
        $cuerpo = '<p>Para resolver tu ' . $tipo . ' <strong>' . $radicado . '</strong> necesitamos que completes lo siguiente:</p>'
            . '<div style="background:#f8fafc;padding:16px;border-left:4px solid #0A2E4F;border-radius:6px;line-height:1.6">'
            . nl2br(esc((string) $p['requerimiento_detalle'])) . '</div>'
            . '<p>Tienes <strong>un (1) mes</strong>, hasta el <strong>' . date('d/m/Y', strtotime($p['desiste_el'])) . '</strong>. '
            . 'Mientras tanto el término queda suspendido. Si no recibimos respuesta se entenderá que desististe y archivaremos la '
            . 'solicitud, conforme al artículo 17 de la Ley 1755 de 2015.</p>';
    } else {
        $asunto = 'Tu ' . mb_strtolower($p['tipo'], 'UTF-8') . ' ' . $p['radicado'] . ' fue trasladada por competencia';
        $cuerpo = '<p>Tu ' . $tipo . ' <strong>' . $radicado . '</strong> trata un asunto que no es de nuestra competencia.</p>'
            . '<p>La trasladamos a <strong>' . esc((string) $p['trasladada_a']) . '</strong> el '
            . date('d/m/Y', strtotime($p['fecha_traslado'])) . ', dentro del término del artículo 21 de la Ley 1755 de 2015. '
            . 'Esa entidad continúa con el trámite y te responderá.</p>'
            . ($p['traslado_detalle'] ? '<p>' . nl2br(esc($p['traslado_detalle'])) . '</p>' : '');
    }
    try {
        $mail = nuevoCorreo('DataSense Analytics');
        $mail->addAddress($p['correo'], (string) $p['nombre']);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = '<p>Hola ' . esc($p['nombre']) . ',</p>' . $cuerpo
            . '<p>Puedes consultar el estado en ' . esc(urlBase()) . 'pqrsd.php con tu número de radicado y tu clave.</p>'
            . '<p>Equipo DataSense Analytics</p>';
        $mail->send();
        registrarEventoPqrsd($db, $id, $usuario_id, 'Aviso al ciudadano', 'Se le avisó de la actuación al correo ' . $p['correo'] . '.');
        return ' Le avisamos al ciudadano por correo.';
    } catch (Exception $e) {
        error_log('PQRSD, aviso de actuación: ' . $e->getMessage());
        registrarEventoPqrsd($db, $id, $usuario_id, 'Correo no enviado',
            'No fue posible avisarle al ciudadano de la actuación; queda registrada en la plataforma.', null, null, false);
        return ' No fue posible enviar el correo: revísalo con el administrador.';
    }
}

// ----------------------------------------------- ACTUACIONES DE LEY (Ley 1755 de 2015, arts. 14, 17, 20 y 21)
$accion = (string) ($_POST['accion'] ?? 'gestionar');
if (in_array($accion, ['prorrogar', 'requerir', 'completar', 'trasladar', 'prioritaria'], true)) {
    $detalle = trim((string) ($_POST['detalle'] ?? ''));
    if ($accion === 'prorrogar')     list($ok, $mensaje) = prorrogarPqrsd($db, $id, $yo, (int) ($_POST['dias'] ?? 0), $detalle);
    elseif ($accion === 'requerir')  list($ok, $mensaje) = requerirInformacionPqrsd($db, $id, $yo, $detalle);
    elseif ($accion === 'completar') list($ok, $mensaje) = completarRequerimientoPqrsd($db, $id, $yo, $detalle);
    elseif ($accion === 'trasladar') list($ok, $mensaje) = trasladarPqrsd($db, $id, $yo, (string) ($_POST['entidad'] ?? ''), $detalle);
    else                             list($ok, $mensaje) = marcarPrioritariaPqrsd($db, $id, $yo, empty($_POST['quitar']), (string) ($_POST['motivo_prioritaria'] ?? ''));

    // La prórroga, el requerimiento y el traslado hay que informárselos al ciudadano
    if ($ok && in_array($accion, ['prorrogar', 'requerir', 'trasladar'], true)) $mensaje .= avisarActuacionPqrsd($db, $accion, $id, $yo);
    avisoSiguiente($ok ? 'success' : 'danger', $mensaje);
    header('Location: pqrsd.php?abrir=' . $id);
    exit;
}

// Los estados de las actuaciones de ley no se ponen a mano
$estado_nuevo = in_array($_POST['estado'] ?? '', ESTADOS_MANUALES_PQRSD, true) ? $_POST['estado'] : $solicitud['estado'];
$respuesta = trim((string) ($_POST['respuesta'] ?? ''));
$nota = trim((string) ($_POST['nota'] ?? ''));
$asignado = (int) ($_POST['asignado_a'] ?? 0);
$enviar_correo = !empty($_POST['enviar_correo']);

// El responsable debe ser una cuenta interna con acceso al módulo
if ($asignado > 0) {
    $stmt = $db->prepare("SELECT rol FROM usuarios WHERE id = ? AND estado_cuenta = 'Activo'");
    $stmt->execute([$asignado]);
    $rol = $stmt->fetchColumn();
    if (!$rol || !tieneAcceso($rol, 'pqrsd', $db)) $asignado = 0;
}

// Responder o cerrar exige una respuesta escrita
if (in_array($estado_nuevo, ['Respondida', 'Cerrada'], true) && $respuesta === '') {
    avisoSiguiente('danger', 'Para marcarla como ' . mb_strtolower($estado_nuevo, 'UTF-8') . ' escribe primero la respuesta al ciudadano.');
    header('Location: pqrsd.php?abrir=' . $id);
    exit;
}

$campos = [];
$eventos = [];
if ($asignado !== (int) $solicitud['asignado_a']) {
    $campos['asignado_a'] = $asignado ?: null;
    $nombre_asignado = 'sin responsable';
    if ($asignado) {
        $stmt = $db->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $stmt->execute([$asignado]);
        $nombre_asignado = (string) $stmt->fetchColumn();
    }
    $eventos[] = ['Asignación', 'Responsable: ' . $nombre_asignado . '.', null, null, true];
}
if ($respuesta !== (string) $solicitud['respuesta'] && $respuesta !== '') {
    $campos['respuesta'] = $respuesta;
    $campos['respondido_por'] = $yo;
    if (empty($solicitud['fecha_respuesta'])) $campos['fecha_respuesta'] = $ahora;
}
if ($nota !== '') $eventos[] = ['Nota interna', $nota, null, null, false];

// El tema orienta el reparto; se deduce del asunto y aquí se puede corregir
$tema = (string) ($_POST['tema'] ?? '');
if ($tema !== '' && $tema !== (string) ($solicitud['tema'] ?? '') && temaPqrsdDisponible($db)
    && (isset(TEMAS_PQRSD[$tema]) || $tema === TEMA_PQRSD_OTROS)) {
    $campos['tema'] = $tema;
    $eventos[] = ['Tema de la solicitud', 'Tema: ' . $tema . '.', null, null, false];
}

if ($estado_nuevo !== $solicitud['estado']) {
    $campos['estado'] = $estado_nuevo;
    $eventos[] = ['Cambio de estado', $solicitud['estado'] . ' → ' . $estado_nuevo, $solicitud['estado'], $estado_nuevo, true];
    if ($estado_nuevo === 'Cerrada') {
        $campos['fecha_cierre'] = $ahora;
        $campos['cerrado_por'] = $yo;
    }
    // Si se responde con un requerimiento pendiente, el plazo del desistimiento pierde sentido
    if (in_array($estado_nuevo, ['Respondida', 'Cerrada'], true) && $solicitud['estado'] === 'En espera del ciudadano' && pqrsdNormativaDisponible($db)) {
        $campos['desiste_el'] = null;
    }
}

if ($campos) {
    $asignaciones = [];
    $valores = [];
    foreach ($campos as $columna => $valor) {
        $asignaciones[] = "`$columna` = ?";
        $valores[] = $valor;
    }
    $valores[] = $id;
    $db->prepare("UPDATE pqrsd SET " . implode(', ', $asignaciones) . " WHERE id = ?")->execute($valores);
}
foreach ($eventos as $e) {
    registrarEventoPqrsd($db, $id, $yo, $e[0], $e[1], $e[2], $e[3], !empty($e[4]), $ahora);
}

// Correo con la respuesta (solo si el ciudadano dejó correo y no es anónima)
$actualizada = pqrsdPorId($db, $id);
$correo_enviado = null;
if ($enviar_correo && $respuesta !== '' && !$actualizada['anonima'] && filter_var((string) $actualizada['correo'], FILTER_VALIDATE_EMAIL)) {
    try {
        $mail = nuevoCorreo('DataSense Analytics');
        $mail->addAddress($actualizada['correo'], (string) $actualizada['nombre']);
        $mail->isHTML(true);
        $mail->Subject = 'Respuesta a tu ' . mb_strtolower($actualizada['tipo'], 'UTF-8') . ' ' . $actualizada['radicado'];
        $mail->Body = '<p>Hola ' . esc($actualizada['nombre']) . ',</p>'
            . '<p>Esta es la respuesta a tu ' . esc(mb_strtolower($actualizada['tipo'], 'UTF-8')) . ' radicada con el número <strong>' . esc($actualizada['radicado']) . '</strong>:</p>'
            . '<div style="background:#f8fafc;padding:16px;border-left:4px solid #3FAE49;border-radius:6px;line-height:1.6">' . nl2br(esc($respuesta)) . '</div>'
            . '<p style="color:#475569;font-size:14px">Respondemos dentro del término que fija la Ley 1755 de 2015. Si consideras que esta '
            . 'respuesta no resuelve tu solicitud, puedes insistir por este mismo canal citando el número de radicado.</p>'
            . '<p>Puedes consultar el estado en ' . esc(urlBase()) . 'pqrsd.php con tu número de radicado y tu clave.</p>'
            . '<p>Equipo DataSense Analytics</p>';
        $mail->send();
        $correo_enviado = true;
        $limite_real = fechaLimitePqrsd($actualizada);
        registrarEventoPqrsd($db, $id, $yo, 'Respuesta enviada', 'Se envió la respuesta al correo ' . $actualizada['correo']
            . '. Término: ' . date('d/m/Y', strtotime($limite_real))
            . (strtotime($ahora) <= strtotime($limite_real) ? ' · dentro del término.' : ' · fuera del término.'), null, null, true, $ahora);
    } catch (Exception $e) {
        $correo_enviado = false;
        error_log('PQRSD, respuesta al ciudadano: ' . $e->getMessage());
        registrarEventoPqrsd($db, $id, $yo, 'Correo no enviado', 'No fue posible enviar la respuesta por correo; queda registrada en la plataforma.', null, null, false, $ahora);
    }
}

$texto = 'Gestión guardada en ' . $actualizada['radicado'] . '.';
if ($correo_enviado === true) $texto .= ' La respuesta se envió al ciudadano.';
elseif ($correo_enviado === false) $texto .= ' No fue posible enviar el correo: revísalo con el administrador.';
elseif ($respuesta !== '' && $actualizada['anonima']) $texto .= ' La denuncia es anónima: la respuesta queda registrada, sin correo.';
avisoSiguiente($correo_enviado === false ? 'warning' : 'success', $texto);
header('Location: pqrsd.php?abrir=' . $id);
exit;
