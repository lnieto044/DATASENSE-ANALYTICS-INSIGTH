<?php
/**
 * MESA DE SERVICIOS (ITSM) - ACCIONES
 * - crear: radicación por el cliente, o por la mesa de servicios en nombre de un cliente.
 * - actualizar_bitacora: gestión del especialista (estado, asignación, clasificación, notas y solución).
 * - responder_cliente, confirmar_solucion y reabrir: acciones del cliente dueño del caso.
 * - enviar_correo y registrar_contacto: la mesa escribe al cliente o abre WhatsApp desde el caso (respuesta JSON); todo queda en la traza.
 * - reasignar: supervisión de cargas. guardar_configuracion: ANS, horario y umbrales (solo Administrador).
 * Las fechas del ciclo de vida y del ANS se calculan en includes/itsm.php.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/itsm.php';

$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereSesion($db);

// Guarda el adjunto solo si la extensión está en la lista permitida (nunca .php ni ejecutables)
function subirArchivo($input_name) {
    if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION));
        if (!isset(TIPOS_ADJUNTOS[$ext])) return null;

        $dir = __DIR__ . '/../uploads/tickets/';
        if (!file_exists($dir)) { mkdir($dir, 0755, true); }
        $nombre_archivo = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($_FILES[$input_name]['tmp_name'], $dir . $nombre_archivo)) return $nombre_archivo;
    }
    return null;
}

function archivoRechazado($input_name, $guardado) {
    return isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] !== UPLOAD_ERR_NO_FILE && !$guardado;
}

function volver($pagina, array $params = []) {
    header('Location: ' . $pagina . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

function ticketPorId(PDO $db, $id) {
    $filas = ticketsItsm($db, 't.id = ?', [(int) $id]);
    return $filas ? $filas[0] : null;
}

// Especialista válido: usuario activo con acceso a la consola de Gestión IT. Devuelve su nombre.
function especialistaValido(PDO $db, $id) {
    $stmt = $db->prepare("SELECT nombre, rol FROM usuarios WHERE id = ? AND estado_cuenta = 'Activo'");
    $stmt->execute([(int) $id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($u && tieneAcceso($u['rol'], 'gestion_it', $db)) ? $u['nombre'] : null;
}

// $eventos: [[evento, detalle, estado_anterior, estado_nuevo, publico], ...]
function guardarGestion(PDO $db, array $t, array $campos, array $eventos, $usuario_id, $ahora) {
    $db->beginTransaction();
    actualizarCamposTicket($db, $t['id'], $campos);
    foreach ($eventos as $e) {
        registrarEventoTicket($db, $t['id'], $usuario_id, $e[0], $e[1], $e[2] ?? null, $e[3] ?? null, !empty($e[4]), $ahora);
    }
    $db->commit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['accion'])) volver('soporte.php');

$accion = (string) $_POST['accion'];
$ahora = date('Y-m-d H:i:s');
$cfg = configItsm($db);
$es_mesa = tieneAcceso($usuario_actual['rol'], 'gestion_it', $db);
$yo = (int) $usuario_actual['id'];

// =========================================================================
// RADICAR UN CASO (cliente, o la mesa de servicios en su nombre)
// =========================================================================
if ($accion === 'crear') {
    $en_nombre = $es_mesa && !empty($_POST['cliente_id']);
    if (!$en_nombre && !tieneAcceso($usuario_actual['rol'], 'soporte', $db)) denegarAcceso('pagina', 403);
    $pagina = $en_nombre ? 'gestion_it.php' : 'soporte.php';

    $cliente_id = $yo;
    if ($en_nombre) {
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND estado_cuenta = 'Activo'");
        $stmt->execute([(int) $_POST['cliente_id']]);
        $cliente_id = (int) $stmt->fetchColumn();
    }
    $stmt = $db->prepare("SELECT * FROM catalogo_servicios WHERE id = ? AND estado = 1");
    $stmt->execute([(int) ($_POST['id_servicio_catalogo'] ?? 0)]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    $asunto = mb_substr(textoPost('asunto'), 0, 150);
    $descripcion = textoPost('descripcion');
    $impacto = textoPost('impacto');
    $urgencia = textoPost('urgencia');
    if (!$cliente_id || !$cat || $asunto === '' || $descripcion === '' || !isset(MATRIZ_PRIORIDAD[$impacto][$urgencia])) {
        volver($pagina, ['err' => 'datos']);
    }

    $tipo = $cat['tipo_solicitud'] === 'RF' ? 'RF' : 'INC';
    $prioridad = prioridadDe($impacto, $urgencia);
    $estado = primerEstado($db, COMP_ABIERTO);
    $sla = camposObjetivoSla($db, ['created_at' => $ahora, 'minutos_pausa' => 0, 'comportamiento' => COMP_ABIERTO], $tipo, $prioridad, $cfg);
    $archivo = subirArchivo('adjunto');

    $db->prepare("INSERT INTO soporte_tickets
                    (usuario_id, asunto, servicio, categoria, impacto, urgencia, grupo_resolutor, prioridad, descripcion, estado, adjunto, created_at,
                     tipo, sla_respuesta_min, sla_resolucion_min, calendario, vence_respuesta, fecha_vencimiento, minutos_pausa, ultima_gestion_fecha)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)")
       ->execute([$cliente_id, $asunto, $cat['servicio'], $cat['subcategoria'], $impacto, $urgencia, $cat['grupo_resolutor'], $prioridad, $descripcion, $estado, $archivo, $ahora,
                  $tipo, $sla['sla_respuesta_min'], $sla['sla_resolucion_min'], $sla['calendario'], $sla['vence_respuesta'], $sla['fecha_vencimiento'], $ahora]);
    $id = (int) $db->lastInsertId();
    $codigo = $tipo . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE soporte_tickets SET codigo_ticket = ? WHERE id = ?")->execute([$codigo, $id]);

    $compromiso = 'Primera respuesta antes de ' . date('d/m/Y H:i', strtotime($sla['vence_respuesta'])) . ' y solución antes de '
                . date('d/m/Y H:i', strtotime($sla['fecha_vencimiento'])) . ' (' . ($sla['calendario'] === '24x7' ? '24x7' : 'horario hábil') . ').';
    $inicio_conteo = proximoInicioHabil($ahora, $cfg)->format('Y-m-d H:i:s');
    if ($sla['calendario'] === 'habil' && $inicio_conteo > $ahora) {
        $compromiso .= ' Radicado fuera del horario de atención: el tiempo empieza a contar el ' . date('d/m/Y H:i', strtotime($inicio_conteo)) . '.';
    }
    registrarEventoTicket($db, $id, $yo, 'Registro', ($en_nombre ? 'Registrado por la mesa de servicios. ' : 'Radicado por el cliente. ')
        . TIPOS_TICKET[$tipo] . " de prioridad $prioridad. $compromiso", null, $estado, true, $ahora);
    // Sin pasar por el supervisor: el caso queda con el especialista adecuado de menor carga
    asignarAutomaticamente($db, ticketPorId($db, $id), $cfg, $ahora);
    correoRadicadoTicket($db, ticketPorId($db, $id), $compromiso);

    $params = ['res' => 'ticket_creado', 'codigo' => $codigo];
    if (archivoRechazado('adjunto', $archivo)) $params['adjunto'] = 'rechazado';
    volver($pagina, $params);
}

// =========================================================================
// GESTIÓN DEL ESPECIALISTA (consola de Gestión IT)
// =========================================================================
elseif ($accion === 'actualizar_bitacora') {
    if (!$es_mesa) denegarAcceso('pagina', 403);
    $t = ticketPorId($db, $_POST['ticket_id'] ?? 0);
    if (!$t) volver('gestion_it.php', ['err' => 'no_existe']);
    $error = function ($codigo) use ($t) {
        volver('gestion_it.php', ['err' => $codigo, 'abrir' => $t['codigo_ticket']]);
    };
    if ($t['comportamiento'] === COMP_CERRADO) $error('cerrado');

    // Estado: uno activo de estados_ticket, o el que ya tiene el caso
    $estado = trim((string) ($_POST['estado'] ?? $t['estado']));
    $despues = null;
    foreach (estadosTicket($db) as $e) {
        if ((int) $e['activo'] === 1 && $e['nombre'] === $estado) $despues = $e['comportamiento'];
    }
    if ($despues === null && $estado === $t['estado']) $despues = $t['comportamiento'];
    if ($despues === null) $error('estado');
    $antes = $t['comportamiento'];

    $impacto = trim((string) ($_POST['impacto'] ?? ''));
    $urgencia = trim((string) ($_POST['urgencia'] ?? ''));
    if (!isset(MATRIZ_PRIORIDAD[$impacto][$urgencia])) {
        $impacto = (string) $t['impacto'];
        $urgencia = (string) $t['urgencia'];
    }
    $prioridad = isset(MATRIZ_PRIORIDAD[$impacto][$urgencia]) ? prioridadDe($impacto, $urgencia) : $t['prioridad'];

    $tecnico_id = (int) ($_POST['tecnico_id'] ?? 0);
    if ($tecnico_id && !especialistaValido($db, $tecnico_id)) $error('especialista');
    if (!$tecnico_id && $despues !== COMP_ABIERTO) $tecnico_id = $yo; // Quien atiende el caso lo toma
    $grupo = trim((string) ($_POST['grupo_resolutor'] ?? ''));
    if ($grupo === '') $grupo = (string) $t['grupo_resolutor'];
    $nota = trim((string) ($_POST['nueva_nota'] ?? ''));
    $publica = isset($_POST['notificar']);
    $solucion = trim((string) ($_POST['texto_solucion'] ?? ''));
    if ($solucion === '') $solucion = trim((string) $t['solucion']);

    $codigo_cierre = trim((string) ($_POST['codigo_cierre'] ?? ''));
    if ($codigo_cierre !== '' && !in_array($codigo_cierre, CODIGOS_CIERRE, true)) $error('codigo_cierre');
    if ($codigo_cierre === '') $codigo_cierre = (string) ($t['codigo_cierre'] ?? '');
    $causa_raiz = mb_substr(trim((string) ($_POST['causa_raiz'] ?? ($t['causa_raiz'] ?? ''))), 0, 500);

    if (in_array($despues, [COMP_SOLUCIONADO, COMP_CERRADO], true) && $despues !== $antes && $solucion === '') $error('solucion');
    if (in_array($despues, [COMP_SOLUCIONADO, COMP_CERRADO], true) && $despues !== $antes && $codigo_cierre === '') $error('codigo_cierre');
    if ($despues === COMP_PAUSA && $antes !== COMP_PAUSA && $nota === '') $error('motivo_pausa');

    $campos = [];
    $eventos = [];
    $archivo = subirArchivo('adjunto_tecnico');
    if ($nota !== '' || $archivo) {
        $campos['respuesta_tecnica'] = agregarNotaBitacora($t['respuesta_tecnica'], $publica ? ETIQUETA_NOTA_PUBLICA : ETIQUETA_NOTA_INTERNA, $usuario_actual['nombre'], $nota, $archivo);
        $eventos[] = [$publica ? 'Nota al cliente' : 'Nota interna', $nota !== '' ? $nota : 'Anexó un documento técnico.', null, null, $publica];
    }
    if ($solucion !== trim((string) $t['solucion'])) $campos['solucion'] = $solucion;
    if ($codigo_cierre !== (string) ($t['codigo_cierre'] ?? '')) $campos['codigo_cierre'] = $codigo_cierre !== '' ? $codigo_cierre : null;
    if ($causa_raiz !== (string) ($t['causa_raiz'] ?? '')) $campos['causa_raiz'] = $causa_raiz !== '' ? $causa_raiz : null;
    if ($grupo !== (string) $t['grupo_resolutor']) {
        $campos['grupo_resolutor'] = $grupo;
        $eventos[] = ['Escalamiento', 'Grupo resolutor: ' . ($t['grupo_resolutor'] ?: 'sin grupo') . " → $grupo.", null, null, false];
    }
    if ($tecnico_id !== (int) $t['tecnico_id']) {
        $campos['tecnico_id'] = $tecnico_id ?: null;
        $nuevo = $tecnico_id ? especialistaValido($db, $tecnico_id) : null;
        $detalle = $nuevo
            ? ($t['nom_tecnico'] ? "Reasignado de {$t['nom_tecnico']} a $nuevo" : "Caso asignado a $nuevo") . ' por ' . $usuario_actual['nombre'] . ($tecnico_id === $yo ? ' (lo tomó para atenderlo)' : '') . '.'
            : 'El caso quedó sin especialista (lo tenía ' . ($t['nom_tecnico'] ?: 'nadie') . '); ' . $usuario_actual['nombre'] . ' lo liberó.';
        $eventos[] = ['Asignación', $detalle, null, null, true];
    }
    if ($impacto !== (string) $t['impacto'] || $urgencia !== (string) $t['urgencia']) {
        $campos['impacto'] = $impacto;
        $campos['urgencia'] = $urgencia;
    }
    if ($prioridad !== $t['prioridad']) {
        // Reclasificación: los objetivos del ANS pasan a ser los de la nueva prioridad, contados desde el registro
        list($respuesta_min, $resolucion_min, $calendario) = objetivoSla($db, $t['tipo'], $prioridad);
        $campos += ['prioridad' => $prioridad, 'sla_respuesta_min' => $respuesta_min, 'sla_resolucion_min' => $resolucion_min, 'calendario' => $calendario];
        $eventos[] = ['Reclasificación', "Prioridad {$t['prioridad']} → $prioridad (impacto $impacto, urgencia $urgencia): primera respuesta "
            . duracionTexto($respuesta_min) . ', solución ' . duracionTexto($resolucion_min) . '.', null, null, true];
    }

    // Ciclo de vida: pausas, primera respuesta, solución, cierre y reapertura
    list($campos_ciclo, $eventos_ciclo) = transicionTicket(array_merge($t, $campos), $antes, $despues, $ahora, $cfg);
    $campos = array_merge($campos, $campos_ciclo);
    if ($estado !== $t['estado']) {
        $campos['estado'] = $estado;
        $eventos[] = ['Cambio de estado', "{$t['estado']} → $estado", $t['estado'], $estado, true];
    }
    foreach ($eventos_ciclo as $e) {
        if ($e[0] === 'Solución' && $codigo_cierre !== '') $e[1] .= " Código de cierre: $codigo_cierre.";
        $eventos[] = [$e[0], $e[1], null, null, true];
    }
    // La causa raíz es información técnica interna: queda en un evento que el cliente no ve
    if ($causa_raiz !== '' && $causa_raiz !== (string) ($t['causa_raiz'] ?? '')) $eventos[] = ['Causa raíz', $causa_raiz, null, null, false];

    // Primera respuesta: el caso sale de "Abierto" o el especialista escribe al cliente
    if (empty($t['fecha_primera_respuesta']) && empty($campos['fecha_primera_respuesta']) && $publica && $nota !== '') {
        $campos = array_merge($campos, camposPrimeraRespuesta(array_merge($t, $campos), $ahora, $cfg));
    }
    $final = array_merge($t, $campos, ['comportamiento' => $despues]);
    if (!empty($campos['fecha_primera_respuesta'])) {
        $eventos[] = ['Primera respuesta', 'Atendido por ' . $usuario_actual['nombre'] . ' en ' . duracionTexto(minutosHabiles($t['created_at'], $ahora, $final['calendario'], $cfg)) . ' ('
            . ($campos['sla_respuesta_cumplido'] ? 'dentro' : 'fuera') . ' del ANS de ' . duracionTexto($final['sla_respuesta_min']) . ').', null, null, true];
    } elseif (isset($campos['sla_respuesta_min']) && !empty($t['fecha_primera_respuesta'])) {
        $campos['sla_respuesta_cumplido'] = minutosHabiles($t['created_at'], $t['fecha_primera_respuesta'], $final['calendario'], $cfg) <= $final['sla_respuesta_min'] ? 1 : 0;
    }
    if (isset($campos['sla_resolucion_min']) && !empty($final['fecha_resolucion']) && !array_key_exists('sla_resolucion_cumplido', $campos)) {
        $campos['sla_resolucion_cumplido'] = slaTicket($final, $cfg)['resolucion']['cumplido'] ? 1 : 0;
    }
    $final = array_merge($t, $campos, ['comportamiento' => $despues]);
    $campos = array_merge($campos, camposVencimiento($final, $cfg));
    if (isset($campos['prioridad'])) $campos['sla_alerta_nivel'] = nivelAlertaSla(slaTicket($final, $cfg)['resolucion']['pct'], $cfg);
    $campos += ['ultima_gestion_fecha' => $ahora, 'fecha_respuesta' => $ahora, 'notificar_tecnico' => 0];

    guardarGestion($db, $t, $campos, $eventos, $yo, $ahora);
    $actualizado = ticketPorId($db, $t['id']);
    // Un caso abierto nunca queda sin especialista: si se dejó sin asignar, lo toma el especialista adecuado de menor carga
    if (!$actualizado['tecnico_id'] && !in_array($despues, [COMP_SOLUCIONADO, COMP_CERRADO], true) && asignarAutomaticamente($db, $actualizado, $cfg, $ahora)) {
        $actualizado = ticketPorId($db, $t['id']);
    }

    if (isset($campos['tecnico_id']) && $tecnico_id && $tecnico_id !== $yo) correoAsignacionTicket($db, $actualizado, $tecnico_id);
    if ($despues !== $antes && $despues === COMP_SOLUCIONADO) correoSolucionTicket($db, $actualizado, $cfg, $yo);
    elseif ($despues !== $antes && $despues === COMP_CERRADO) correoCierreTicket($db, $actualizado, $yo);
    elseif ($publica && $nota !== '') correoActualizacionTicket($db, $actualizado, $nota, $despues === COMP_PAUSA, $yo);

    $params = ['res' => 'actualizado', 'caso' => $t['codigo_ticket']];
    if (archivoRechazado('adjunto_tecnico', $archivo)) $params['adjunto'] = 'rechazado';
    volver('gestion_it.php', $params);
}

// =========================================================================
// CONTACTO CON EL CLIENTE DESDE EL CASO (mesa de servicios, respuesta JSON)
// enviar_correo: correo con la imagen de DataSense, guardado completo en la traza.
// registrar_contacto: deja constancia de que se abrió WhatsApp o se llamó (la conversación ocurre fuera de la plataforma).
// =========================================================================
elseif (in_array($accion, ['enviar_correo', 'registrar_contacto'], true)) {
    $responder = function (array $datos, $codigo = 200) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo jsJson($datos);
        exit;
    };
    $t = $es_mesa ? ticketPorId($db, $_POST['ticket_id'] ?? 0) : null;
    if (!$t) $responder(['ok' => false, 'error' => 'El caso no existe o no tienes acceso.'], 403);

    if ($accion === 'registrar_contacto') {
        $canal = (string) ($_POST['canal'] ?? '');
        if (!in_array($canal, ['WhatsApp', 'Llamada'], true)) $responder(['ok' => false, 'error' => 'Canal no válido.'], 422);
        $telefono = $t['telefono_cliente'] ?: 'un cliente sin teléfono registrado';
        registrarEventoTicket($db, $t['id'], $yo, $canal === 'WhatsApp' ? 'Contacto por WhatsApp' : 'Llamada al cliente',
            ($canal === 'WhatsApp' ? "Abrió un chat de WhatsApp con $telefono" : "Marcó al $telefono") . ' desde el caso. La conversación ocurre fuera de la plataforma: lo acordado debe quedar en Notas.',
            null, null, false, $ahora);
        $responder(['ok' => true]);
    }

    $asunto = trim(mb_substr((string) ($_POST['asunto'] ?? ''), 0, 200));
    $mensaje = trim((string) ($_POST['mensaje'] ?? ''));
    if ($asunto === '' || $mensaje === '' || mb_strlen($mensaje) > 5000) $responder(['ok' => false, 'error' => 'Escribe el asunto y el mensaje (máximo 5.000 caracteres).'], 422);
    if (strpos($asunto, '[' . $t['codigo_ticket'] . ']') === false) $asunto = '[' . $t['codigo_ticket'] . '] ' . $asunto;
    $html = plantillaCorreoTicket('#0A2E4F', saludoCorreo($t['cliente'])
        . '<p>' . nl2br(htmlspecialchars($mensaje)) . '</p>'
        . '<p style="color:#64748b;font-size:13px">Caso <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong>: ' . htmlspecialchars($t['asunto'])
        . '. Puedes responder desde el <a href="' . htmlspecialchars(urlBase()) . 'admin/soporte.php">Centro de Soporte</a>.</p>'
        . '<p><strong>' . htmlspecialchars($usuario_actual['nombre']) . '</strong><br>Equipo de Operaciones IT</p>');
    $enviado = correoClienteConTraza($db, $t, 'Correo del especialista', $asunto, $html, 'Soporte IT - DataSense', $yo);
    if ($enviado) {
        // Escribirle al cliente es gestionar el caso y, si aún no la tenía, cuenta como su primera respuesta
        $campos = ['ultima_gestion_fecha' => $ahora];
        if (empty($t['fecha_primera_respuesta']) && !in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true)) {
            $campos = array_merge($campos, camposPrimeraRespuesta($t, $ahora, $cfg));
            registrarEventoTicket($db, $t['id'], $yo, 'Primera respuesta', 'Atendido por ' . $usuario_actual['nombre'] . ' con un correo al cliente en '
                . duracionTexto(minutosHabiles($t['created_at'], $ahora, $t['calendario'], $cfg)) . ' (' . (!empty($campos['sla_respuesta_cumplido']) ? 'dentro' : 'fuera')
                . ' del ANS de ' . duracionTexto($t['sla_respuesta_min']) . ').', null, null, true, $ahora);
        }
        actualizarCamposTicket($db, $t['id'], $campos);
    }
    $responder(['ok' => $enviado, 'error' => $enviado ? null : 'El servidor de correo no aceptó el mensaje; quedó registrado como no enviado.', 'correos' => correosTicket($db, (int) $t['id'])]);
}

// =========================================================================
// ACCIONES DEL CLIENTE SOBRE SU CASO
// =========================================================================
elseif (in_array($accion, ['responder_cliente', 'confirmar_solucion', 'reabrir'], true)) {
    $t = ticketPorId($db, $_POST['ticket_id'] ?? 0);
    if (!$t || (int) $t['usuario_id'] !== $yo) denegarAcceso('pagina', 403);
    $error = function ($codigo) use ($t) {
        volver('soporte.php', ['err' => $codigo, 'abrir' => $t['codigo_ticket']]);
    };
    $campos = [];
    $eventos = [];

    if ($accion === 'responder_cliente') {
        // Información adicional del cliente; si el caso esperaba su respuesta, el reloj del ANS se reanuda
        if (!in_array($t['comportamiento'], [COMP_ABIERTO, COMP_PROCESO, COMP_PAUSA], true)) $error('no_permitido');
        $texto = trim((string) ($_POST['texto_respuesta'] ?? ''));
        $archivo = subirArchivo('adjunto_respuesta');
        if ($texto === '' && !$archivo) $error('respuesta_vacia');
        $campos['respuesta_tecnica'] = agregarNotaBitacora($t['respuesta_tecnica'], ETIQUETA_NOTA_CLIENTE, $usuario_actual['nombre'], $texto, $archivo);
        $campos['notificar_tecnico'] = 1;
        $eventos[] = ['Respuesta del cliente', $texto !== '' ? $texto : 'El cliente adjuntó un archivo.', null, null, true];
        if ($t['comportamiento'] === COMP_PAUSA) {
            $estado = primerEstado($db, COMP_PROCESO);
            list($campos_ciclo, $eventos_ciclo) = transicionTicket($t, COMP_PAUSA, COMP_PROCESO, $ahora, $cfg);
            $campos = array_merge($campos, $campos_ciclo, ['estado' => $estado]);
            $eventos[] = ['Cambio de estado', "{$t['estado']} → $estado", $t['estado'], $estado, true];
            foreach ($eventos_ciclo as $e) $eventos[] = [$e[0], $e[1], null, null, true];
            $campos = array_merge($campos, camposVencimiento(array_merge($t, $campos, ['comportamiento' => COMP_PROCESO]), $cfg));
        }
        guardarGestion($db, $t, $campos, $eventos, $yo, $ahora);
        correoTecnicoTicket($db, $t, $t['tecnico_id'], '[' . $t['codigo_ticket'] . '] El cliente respondió',
            '<p>El cliente respondió en el caso <strong>' . esc($t['codigo_ticket']) . '</strong>' . ($t['comportamiento'] === COMP_PAUSA ? ' y el reloj del ANS se reanudó' : '') . ':</p>'
            . '<blockquote style="border-left:3px solid #0A2E4F;padding-left:12px;color:#334155">' . nl2br(esc($texto !== '' ? $texto : 'Adjuntó un archivo.')) . '</blockquote>');
        $params = ['res' => 'respuesta', 'codigo' => $t['codigo_ticket']];
        if (archivoRechazado('adjunto_respuesta', $archivo)) $params['adjunto'] = 'rechazado';
        volver('soporte.php', $params);
    }

    if ($accion === 'confirmar_solucion') {
        if ($t['comportamiento'] !== COMP_SOLUCIONADO) $error('no_permitido');
        $estrellas = (int) ($_POST['calificacion'] ?? 0);
        if ($estrellas < 1 || $estrellas > 5) $error('calificacion');
        $comentario = trim((string) ($_POST['comentario'] ?? ''));
        $estado = primerEstado($db, COMP_CERRADO);
        list($campos, ) = transicionTicket($t, COMP_SOLUCIONADO, COMP_CERRADO, $ahora, $cfg);
        $campos += ['estado' => $estado, 'calificacion_estrellas' => $estrellas, 'comentario_usuario' => $comentario !== '' ? $comentario : null];
        $eventos[] = ['Confirmación del cliente', "El cliente confirmó la solución y calificó la atención con $estrellas de 5." . ($comentario !== '' ? " «{$comentario}»" : ''), $t['estado'], $estado, true];
        guardarGestion($db, $t, $campos, $eventos, $yo, $ahora);
        correoTecnicoTicket($db, $t, $t['tecnico_id'], '[' . $t['codigo_ticket'] . "] Solución confirmada · $estrellas/5",
            '<p>El cliente confirmó la solución del caso <strong>' . esc($t['codigo_ticket']) . '</strong> y calificó la atención con <strong>' . str_repeat('★', $estrellas) . str_repeat('☆', 5 - $estrellas) . '</strong>.</p>'
            . ($comentario !== '' ? '<p>«' . esc($comentario) . '»</p>' : ''));
        volver('soporte.php', ['res' => 'confirmado', 'codigo' => $t['codigo_ticket']]);
    }

    // Reapertura: si la solución no funcionó (caso solucionado, o cerrado hace menos de N días hábiles)
    if (!puedeReabrirTicket($t, $cfg, $ahora)) $error('no_permitido');
    $motivo = trim((string) ($_POST['motivo'] ?? ''));
    if ($motivo === '') $error('motivo');
    $estado = primerEstado($db, COMP_PROCESO);
    list($campos, $eventos_ciclo) = transicionTicket($t, $t['comportamiento'], COMP_PROCESO, $ahora, $cfg);
    $campos += ['estado' => $estado, 'notificar_tecnico' => 1,
                'respuesta_tecnica' => agregarNotaBitacora($t['respuesta_tecnica'], ETIQUETA_NOTA_CLIENTE, $usuario_actual['nombre'], 'Solicitó reabrir el caso: ' . $motivo)];
    $campos = array_merge($campos, camposVencimiento(array_merge($t, $campos, ['comportamiento' => COMP_PROCESO]), $cfg));
    $eventos[] = ['Reapertura solicitada', $motivo, $t['estado'], $estado, true];
    foreach ($eventos_ciclo as $e) $eventos[] = [$e[0], $e[1], null, null, true];
    guardarGestion($db, $t, $campos, $eventos, $yo, $ahora);
    correoTecnicoTicket($db, $t, $t['tecnico_id'], '[' . $t['codigo_ticket'] . '] El cliente reabrió el caso',
        '<p>El cliente reabrió el caso <strong>' . esc($t['codigo_ticket']) . '</strong>. El reloj del ANS continúa donde quedó.</p><p>Motivo: «' . esc($motivo) . '»</p>');
    volver('soporte.php', ['res' => 'reabierto', 'codigo' => $t['codigo_ticket']]);
}

// =========================================================================
// REASIGNACIÓN (supervisión de cargas o consola)
// =========================================================================
elseif ($accion === 'reasignar') {
    if (!$es_mesa && !tieneAcceso($usuario_actual['rol'], 'supervisor_it', $db)) denegarAcceso('pagina', 403);
    $pagina = ($_POST['volver'] ?? '') === 'supervisor' ? 'supervisor_it.php' : 'gestion_it.php';
    $t = ticketPorId($db, $_POST['ticket_id'] ?? 0);
    $tecnico_id = (int) ($_POST['tecnico_id'] ?? 0);
    $nombre = $tecnico_id ? especialistaValido($db, $tecnico_id) : null;
    if (!$t || !$nombre || in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true)) volver($pagina, ['err' => 'reasignar']);
    if ($tecnico_id !== (int) $t['tecnico_id']) {
        $detalle = ($t['nom_tecnico'] ? "Reasignado de {$t['nom_tecnico']} a $nombre" : "Caso asignado a $nombre") . ' por ' . $usuario_actual['nombre'] . '.';
        guardarGestion($db, $t, ['tecnico_id' => $tecnico_id], [['Asignación', $detalle, null, null, true]], $yo, $ahora);
        if ($tecnico_id !== $yo) correoAsignacionTicket($db, ticketPorId($db, $t['id']), $tecnico_id);
    }
    volver($pagina, ['res' => 'reasignado', 'caso' => $t['codigo_ticket']]);
}

// =========================================================================
// CONFIGURACIÓN DEL ANS (solo Administrador)
// =========================================================================
elseif ($accion === 'guardar_configuracion') {
    if ($usuario_actual['rol'] !== 'Admin') denegarAcceso('pagina', 403);
    $error = function ($codigo) { volver('supervisor_it.php', ['err' => $codigo, 'vista' => 'configuracion']); };
    $hora = function ($valor) { return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $valor) ? (string) $valor : null; };

    $valores = ['hora_inicio' => $hora($_POST['hora_inicio'] ?? ''), 'hora_fin' => $hora($_POST['hora_fin'] ?? '')];
    if (!$valores['hora_inicio'] || !$valores['hora_fin'] || $valores['hora_fin'] <= $valores['hora_inicio']) $error('horario');
    $almuerzo_inicio = trim((string) ($_POST['almuerzo_inicio'] ?? ''));
    $almuerzo_fin = trim((string) ($_POST['almuerzo_fin'] ?? ''));
    if ($almuerzo_inicio === '' && $almuerzo_fin === '') {
        $valores['almuerzo_inicio'] = $valores['almuerzo_fin'] = '';
    } elseif ($hora($almuerzo_inicio) && $hora($almuerzo_fin) && $almuerzo_inicio > $valores['hora_inicio'] && $almuerzo_fin < $valores['hora_fin'] && $almuerzo_fin > $almuerzo_inicio) {
        $valores['almuerzo_inicio'] = $almuerzo_inicio;
        $valores['almuerzo_fin'] = $almuerzo_fin;
    } else {
        $error('almuerzo');
    }
    $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['dias_habiles'] ?? [])), function ($d) { return $d >= 1 && $d <= 7; })));
    sort($dias);
    if (!$dias) $error('dias');
    $valores['dias_habiles'] = implode(',', $dias);
    $valores['festivos_colombia'] = isset($_POST['festivos_colombia']) ? '1' : '0';
    $rangos = ['umbral_atencion' => [1, 99], 'umbral_riesgo' => [1, 99], 'umbral_critico' => [1, 100], 'cierre_automatico_dias' => [0, 30],
               'reapertura_dias' => [0, 60], 'horas_sin_gestion' => [1, 240], 'meta_cumplimiento' => [1, 100]];
    foreach ($rangos as $clave => $rango) {
        $valor = filter_var($_POST[$clave] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $rango[0], 'max_range' => $rango[1]]]);
        if ($valor === false) $error('valor_' . $clave);
        $valores[$clave] = (string) $valor;
    }
    if (!((int) $valores['umbral_atencion'] < (int) $valores['umbral_riesgo'] && (int) $valores['umbral_riesgo'] < (int) $valores['umbral_critico'])) $error('umbrales');

    $objetivos = [];
    foreach (array_keys(TIPOS_TICKET) as $tipo) {
        foreach (PRIORIDADES_TICKET as $prioridad) {
            $respuesta = filter_var($_POST['respuesta'][$tipo][$prioridad] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            $resolucion = filter_var($_POST['resolucion'][$tipo][$prioridad] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($respuesta === false || $resolucion === false || $resolucion < $respuesta) $error('objetivos');
            $objetivos[] = [$tipo, $prioridad, $respuesta, $resolucion, ($_POST['calendario'][$tipo][$prioridad] ?? '') === '24x7' ? '24x7' : 'habil'];
        }
    }

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO itsm_configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    foreach ($valores as $clave => $valor) $stmt->execute([$clave, $valor]);
    $stmt = $db->prepare("INSERT INTO sla_objetivos (tipo, prioridad, respuesta_min, resolucion_min, calendario) VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE respuesta_min = VALUES(respuesta_min), resolucion_min = VALUES(resolucion_min), calendario = VALUES(calendario)");
    foreach ($objetivos as $objetivo) $stmt->execute($objetivo);
    $db->commit();
    volver('supervisor_it.php', ['res' => 'configuracion', 'vista' => 'configuracion']);
}

// =========================================================================
// ESPECIALISTAS Y ASIGNACIÓN AUTOMÁTICA (solo Administrador)
// =========================================================================
elseif ($accion === 'guardar_especialistas') {
    if ($usuario_actual['rol'] !== 'Admin') denegarAcceso('pagina', 403);
    $grupos_validos = array_column($db->query("SELECT DISTINCT grupo_resolutor FROM catalogo_servicios WHERE grupo_resolutor IS NOT NULL AND grupo_resolutor <> ''")->fetchAll(PDO::FETCH_ASSOC), 'grupo_resolutor');
    $filas = [];
    foreach (especialistasItsm($db) as $id => $especialista) {
        $nivel = filter_var($_POST['nivel'][$id] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
        $capacidad = filter_var($_POST['capacidad'][$id] ?? CAPACIDAD_POR_DEFECTO, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($nivel === false || $capacidad === false) volver('supervisor_it.php', ['err' => 'especialistas', 'vista' => 'especialistas']);
        $grupos = array_values(array_intersect($grupos_validos, (array) ($_POST['grupos'][$id] ?? [])));
        $filas[] = [$id, $nivel, implode('|', $grupos), $capacidad, isset($_POST['recibe'][$id]) ? 1 : 0];
    }
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO itsm_especialistas (usuario_id, nivel, grupos, capacidad, recibe_casos) VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE nivel = VALUES(nivel), grupos = VALUES(grupos), capacidad = VALUES(capacidad), recibe_casos = VALUES(recibe_casos)");
    foreach ($filas as $fila) $stmt->execute($fila);
    $db->prepare("INSERT INTO itsm_configuracion (clave, valor) VALUES ('asignacion_automatica', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
       ->execute([isset($_POST['asignacion_automatica']) ? '1' : '0']);
    $db->commit();
    volver('supervisor_it.php', ['res' => 'especialistas', 'vista' => 'especialistas']);
}

volver($es_mesa ? 'gestion_it.php' : 'soporte.php');
