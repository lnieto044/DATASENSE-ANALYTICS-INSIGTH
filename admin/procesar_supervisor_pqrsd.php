<?php
/**
 * Acciones del supervisor del canal ciudadano: quién recibe solicitudes y con qué capacidad,
 * la configuración del reparto y de las metas, y el reparto de las que quedaron sin responsable.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pqrsd.php';
require_once __DIR__ . '/../includes/utilidades.php';
require __DIR__ . '/../vendor/autoload.php';

$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'supervisor_pqrsd');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido()) {
    denegarAcceso('pagina', 403);
}

$accion = (string) ($_POST['accion'] ?? '');
$yo = (int) $usuario_actual['id'];
$vista = in_array($_POST['vista'] ?? '', ['responsables', 'configuracion'], true) ? $_POST['vista'] : '';
$volver = 'supervisor_pqrsd.php' . ($vista ? '?vista=' . $vista : '');

if (!repartoPqrsdDisponible($db)) {
    avisoSiguiente('warning', 'Para usar el reparto aplica database/datasense_analytics.sql: falta la tabla de responsables.');
    header('Location: ' . $volver);
    exit;
}

// ------------------------------------------------------------------ quién recibe solicitudes
if ($accion === 'guardar_responsables') {
    $recibe = (array) ($_POST['recibe'] ?? []);
    $tipos = (array) ($_POST['tipos'] ?? []);
    $temas_post = (array) ($_POST['temas'] ?? []);
    $capacidades = (array) ($_POST['capacidad'] ?? []);
    $con_temas = temasResponsablesDisponible($db);
    $guardados = 0;
    $sin_reparto = [];

    foreach (responsablesPqrsd($db) as $id => $r) {
        $suyos = array_values(array_intersect((array) ($tipos[$id] ?? []), array_keys(TIPOS_PQRSD)));
        $sus_temas = array_values(array_intersect((array) ($temas_post[$id] ?? []), array_merge(array_keys(TEMAS_PQRSD), [TEMA_PQRSD_OTROS])));
        $capacidad = max(1, min(200, (int) ($capacidades[$id] ?? CAPACIDAD_PQRSD_POR_DEFECTO)));
        $lo_recibe = !empty($recibe[$id]) ? 1 : 0;
        if ($con_temas) {
            $db->prepare("INSERT INTO pqrsd_responsables (usuario_id, tipos, temas, capacidad, recibe_casos) VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE tipos = VALUES(tipos), temas = VALUES(temas), capacidad = VALUES(capacidad), recibe_casos = VALUES(recibe_casos)")
               ->execute([$id, $suyos ? implode('|', $suyos) : null, $sus_temas ? implode('|', $sus_temas) : null, $capacidad, $lo_recibe]);
        } else {
            $db->prepare("INSERT INTO pqrsd_responsables (usuario_id, tipos, capacidad, recibe_casos) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE tipos = VALUES(tipos), capacidad = VALUES(capacidad), recibe_casos = VALUES(recibe_casos)")
               ->execute([$id, $suyos ? implode('|', $suyos) : null, $capacidad, $lo_recibe]);
        }
        $guardados++;
        if ($lo_recibe) $sin_reparto[] = $id;
    }

    $texto = "Quedó guardado el reparto de $guardados persona(s).";
    if (!$sin_reparto) $texto .= ' Ojo: nadie está recibiendo solicitudes, así que no se asignarán solas.';
    avisoSiguiente($sin_reparto ? 'success' : 'warning', $texto);
    header('Location: ' . $volver);
    exit;
}

// ------------------------------------------------------------------ configuración del canal
if ($accion === 'guardar_configuracion') {
    $limites = ['meta_cumplimiento' => [1, 100], 'bono_cumplimiento' => [1, 100], 'alerta_cumplimiento' => [1, 100], 'minimo_para_medir' => [1, 100]];
    $valores = ['asignacion_automatica' => empty($_POST['asignacion_automatica']) ? '0' : '1'];
    foreach ($limites as $clave => $rango) {
        list($min, $max) = $rango;
        $valores[$clave] = (string) max($min, min($max, (int) ($_POST[$clave] ?? 0)));
    }
    if ((int) $valores['alerta_cumplimiento'] > (int) $valores['meta_cumplimiento']) {
        avisoSiguiente('danger', 'El umbral del plan de mejora no puede ser mayor que la meta de cumplimiento.');
        header('Location: ' . $volver);
        exit;
    }
    foreach ($valores as $clave => $valor) {
        $db->prepare("INSERT INTO pqrsd_configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
           ->execute([$clave, $valor]);
    }
    avisoSiguiente('success', 'Configuración guardada. El reparto automático queda ' . ($valores['asignacion_automatica'] === '1' ? 'activo' : 'apagado') . '.');
    header('Location: ' . $volver);
    exit;
}

// ------------------------------------------------------------------ repartir las que no tienen responsable
if ($accion === 'repartir') {
    $contexto = null;
    $pendientes = $db->query("SELECT id FROM pqrsd WHERE asignado_a IS NULL AND estado IN ('Radicada', 'En trámite')")->fetchAll(PDO::FETCH_ASSOC);
    $asignadas = 0;
    $avisados = 0;
    foreach ($pendientes as $f) {
        $responsable = asignarPqrsdAutomaticamente($db, $f['id'], $contexto, true); // a mano se reparte aunque esté apagado
        if (!$responsable) continue;
        $asignadas++;
        if (avisarResponsablePqrsd($db, $f['id'], $responsable)) $avisados++;
    }
    if (!$pendientes) avisoSiguiente('info', 'No hay solicitudes sin responsable.');
    elseif (!$asignadas) avisoSiguiente('warning', 'No fue posible repartirlas: revisa que alguien esté recibiendo solicitudes.');
    else avisoSiguiente('success', "Se repartieron $asignadas solicitud(es) y se avisó por correo a $avisados responsable(s).");
    header('Location: ' . $volver);
    exit;
}

// ------------------------------------------------------------------ reasignar una solicitud
if ($accion === 'reasignar') {
    $id = (int) ($_POST['id'] ?? 0);
    $solicitud = pqrsdPorId($db, $id);
    if (!$solicitud) {
        avisoSiguiente('danger', 'Esa solicitud no existe.');
        header('Location: ' . $volver);
        exit;
    }
    if (!pqrsdEnTramite($solicitud)) {
        avisoSiguiente('warning', 'La solicitud ' . $solicitud['radicado'] . ' ya salió de trámite: no se reasigna.');
        header('Location: ' . $volver);
        exit;
    }
    $destino = (int) ($_POST['asignado_a'] ?? 0);
    $responsables = responsablesPqrsd($db);
    $contexto = null;

    if ($destino === 0) { // automático
        $responsable = asignarPqrsdAutomaticamente($db, $id, $contexto, true);
        if (!$responsable) {
            avisoSiguiente('warning', 'Nadie está recibiendo solicitudes: configúralo en la pestaña de responsables.');
            header('Location: ' . $volver);
            exit;
        }
    } elseif (isset($responsables[$destino])) {
        $responsable = $responsables[$destino];
        actualizarPqrsd($db, $id, ['asignado_a' => $destino] + ($solicitud['estado'] === 'Radicada' ? ['estado' => 'En trámite'] : []));
        registrarEventoPqrsd($db, $id, $yo, 'Asignación', 'Responsable: ' . $responsable['nombre'] . ' (asignado por el supervisor).',
            $solicitud['estado'] === 'Radicada' ? 'Radicada' : null, $solicitud['estado'] === 'Radicada' ? 'En trámite' : null);
    } else {
        avisoSiguiente('danger', 'Esa persona no puede atender PQRSD.');
        header('Location: ' . $volver);
        exit;
    }
    $aviso = avisarResponsablePqrsd($db, $id, $responsable);
    avisoSiguiente('success', $solicitud['radicado'] . ' queda con ' . $responsable['nombre'] . '.' . ($aviso ? ' Le avisamos por correo.' : ''));
    header('Location: ' . $volver);
    exit;
}

avisoSiguiente('danger', 'Acción no reconocida.');
header('Location: ' . $volver);
exit;
