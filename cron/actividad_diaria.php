<?php
/**
 * ACTIVIDAD DEL DÍA EN LA DEMOSTRACIÓN
 * Para que la operación se vea viva todos los días (casos de hoy por atender, mensajes nuevos…), una vez al día:
 *   1. Atiende los casos de ejemplo que llevan días abiertos: les da solución y cierre dentro de tiempos razonables.
 *   2. Crea la actividad de hoy hasta la hora actual: casos nuevos (sin atender, en proceso, en espera o resueltos por
 *      confirmar), mensajes del sitio, conversaciones del chat y, a veces, una PQRSD. Algún caso queda a nombre de la
 *      cuenta real que tenga historial de cliente (la del administrador preparada en Mi perfil).
 * Solo en la demostración o en una base creada con sus datos, nunca en una base real. Lo corre deploy/entrypoint.sh
 * al arrancar y cada 10 minutos; la marca 3 de ds_instalacion guarda el día ya hecho. DATOS_MUESTRA=0 lo apaga.
 *
 * Uso: php cron/actividad_diaria.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$db = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/muestra_utilidades.php';
require_once __DIR__ . '/../includes/pqrsd.php';

if (($_ENV['DATOS_MUESTRA'] ?? '1') === '0') exit;
$db->exec("CREATE TABLE IF NOT EXISTS ds_instalacion (id TINYINT PRIMARY KEY, estado VARCHAR(20) NOT NULL, actualizada DATETIME NOT NULL)");
$marcas = $db->query("SELECT id, estado FROM ds_instalacion")->fetchAll(PDO::FETCH_KEY_PAIR);
if (($_ENV['APP_ENV'] ?? '') !== 'demo' && !isset($marcas[1])) exit;   // base real: nada
if (!isset($marcas[2])) exit;                                           // todavía sin datos de muestra
$hoy = new DateTimeImmutable('today');
$dia = 'dia ' . $hoy->format('Y-m-d');
if (($marcas[3] ?? '') === $dia) exit;

mt_srand(crc32($dia));
$ahora = new DateTimeImmutable();
$cfg = configItsm($db);

$db->beginTransaction();
try {
    // Otro servidor pudo empezar a la vez: la fila de la marca queda bloqueada hasta terminar
    $db->exec("INSERT INTO ds_instalacion (id, estado, actualizada) VALUES (3, 'dia', NOW()) ON DUPLICATE KEY UPDATE actualizada = actualizada");
    if ($db->query("SELECT estado FROM ds_instalacion WHERE id = 3 FOR UPDATE")->fetchColumn() === $dia) {
        $db->rollBack();
        exit;
    }
    $historial = $db->prepare("INSERT INTO ticket_historial (ticket_id, fecha, usuario_id, evento, detalle, estado_anterior, estado_nuevo, publico) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    // ---------------------------------------------------------------- 1. Casos de ejemplo que llevan días abiertos
    $atendidos = [];
    $viejos = $db->prepare("SELECT t.* FROM soporte_tickets t JOIN usuarios u ON u.id = t.usuario_id
                            WHERE t.estado NOT LIKE 'Cerrado%' AND t.created_at < ? AND u.correo LIKE '%@example.com'");
    $viejos->execute([f($ahora->modify('-3 days'))]);
    $cerrar = $db->prepare("UPDATE soporte_tickets SET estado = 'Cerrado - Exitoso', tecnico_id = COALESCE(tecnico_id, ?), fecha_primera_respuesta = ?, fecha_respuesta = ?,
                            respuesta_tecnica = COALESCE(respuesta_tecnica, 'Caso recibido; revisamos la información y avanzamos con la corrección.'),
                            fecha_resolucion = ?, solucion = COALESCE(solucion, 'Se atendió la solicitud y se validó el resultado con el usuario.'),
                            fecha_cierre = ?, ultima_gestion_fecha = ?, fecha_pausa = NULL, codigo_cierre = 'Solución definitiva' WHERE id = ?");
    $tecnicos = array_map('intval', $db->query("SELECT usuario_id FROM itsm_especialistas")->fetchAll(PDO::FETCH_COLUMN)) ?: [1];
    foreach ($viejos->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $creado = inmutable($t['created_at']);
        $primera = $t['fecha_primera_respuesta'] ? inmutable($t['fecha_primera_respuesta']) : inmutable(sumarMinutosHabiles($t['created_at'], azar(20, 200), 'habil', $cfg));
        $resuelto = $t['fecha_resolucion'] ? inmutable($t['fecha_resolucion']) : inmutable(sumarMinutosHabiles(f($primera), azar(60, 900), 'habil', $cfg));
        $cerrado = inmutable(sumarMinutosHabiles(f($resuelto), azar(60, 600), 'habil', $cfg));
        if ($cerrado > $ahora) $cerrado = $ahora->modify('-' . azar(5, 120) . ' minutes');
        if ($resuelto > $cerrado) $resuelto = $cerrado->modify('-30 minutes');
        if ($primera > $resuelto) $primera = max($creado, $resuelto->modify('-60 minutes'));
        $tecnico = $t['tecnico_id'] ?: elegir($tecnicos);
        $cerrar->execute([$tecnico, f($primera), f($primera), f($resuelto), f($cerrado), f($cerrado), $t['id']]);
        if (!$t['fecha_primera_respuesta']) $historial->execute([$t['id'], f($primera), $tecnico, 'Primera respuesta', 'Primera respuesta enviada al cliente', $t['estado'], 'En Progreso', 1]);
        if (!$t['fecha_resolucion']) $historial->execute([$t['id'], f($resuelto), $tecnico, 'Solución', 'Solución registrada', 'En Progreso', 'Resuelto', 1]);
        $historial->execute([$t['id'], f($cerrado), $t['usuario_id'], 'Confirmación del cliente', 'El cliente confirmó la solución', 'Resuelto', 'Cerrado - Exitoso', 1]);
        $atendidos[] = (int) $t['id'];
    }

    // ---------------------------------------------------------------- 2. Actividad de hoy (hasta la hora actual)
    $desde = $hoy->setTime(7, 0);
    $momento = function () use ($desde, $ahora, $hoy) {
        if ($ahora <= $desde) return $ahora->modify('-' . azar(1, 40) . ' minutes');
        return $desde->modify('+' . azar(0, max(1, (int) (($ahora->getTimestamp() - $desde->getTimestamp()) / 60) - 1)) . ' minutes');
    };
    $es_habil = esHabil($hoy);
    $clientes = array_map('intval', $db->query("SELECT id FROM usuarios WHERE rol = 'Cliente' AND estado_cuenta = 'Activo' AND correo LIKE '%@example.com'")->fetchAll(PDO::FETCH_COLUMN));
    // Cuenta real con historial de cliente (preparada en Mi perfil): a veces también reporta un caso
    $cuenta_real = (int) $db->query("SELECT u.id FROM usuarios u WHERE u.correo NOT LIKE '%@example.com' AND u.estado_cuenta = 'Activo'
                                     AND EXISTS (SELECT 1 FROM facturas f WHERE f.id_usuario = u.id) ORDER BY u.id LIMIT 1")->fetchColumn();
    $catalogo = $db->query("SELECT tipo_solicitud, servicio, subcategoria, grupo_resolutor FROM catalogo_servicios WHERE estado = 1")->fetchAll(PDO::FETCH_ASSOC);
    $crear = $db->prepare("INSERT INTO soporte_tickets (codigo_ticket, usuario_id, tecnico_id, asunto, servicio, categoria, impacto, urgencia, grupo_resolutor, prioridad, estado,
            descripcion, respuesta_tecnica, solucion, fecha_respuesta, created_at, fecha_vencimiento, fecha_pausa, ultima_gestion_fecha, calificacion_estrellas, notificar_tecnico,
            tipo, fecha_primera_respuesta, fecha_resolucion, minutos_pausa, vence_respuesta, sla_respuesta_min, sla_resolucion_min, calendario, reaperturas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, ?, ?, ?, ?, 0)");
    $ultimo = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo_ticket, '-', -1) AS UNSIGNED)), 0) FROM soporte_tickets")->fetchColumn();
    $nuevos = 0;
    $cantidad = $clientes && $catalogo ? ($es_habil ? azar(3, 6) : azar(0, 1)) : 0;
    $casos_hoy = [];
    for ($k = 0; $k < $cantidad; $k++) $casos_hoy[] = $momento();
    sort($casos_hoy);
    foreach ($casos_hoy as $k => $creado) {
        $cat = elegir($catalogo);
        $tipo = $cat['tipo_solicitud'] === 'RF' ? 'RF' : 'INC';
        $impacto = elegirPeso(['Alto' => 2, 'Medio' => 5, 'Bajo' => 3]);
        $urgencia = elegirPeso(['Alta' => 3, 'Media' => 5, 'Baja' => 2]);
        $prioridad = prioridadDe($impacto, $urgencia);
        [$resp_min, $resol_min, $calendario] = objetivoSla($db, $tipo, $prioridad);
        $usuario = ($cuenta_real && $k === 0 && probable(0.6)) ? $cuenta_real : elegir($clientes);
        $minutos = (int) (($ahora->getTimestamp() - $creado->getTimestamp()) / 60);
        $estado = $minutos < 25 ? elegir(['Registrado', 'Asignado'])
                : elegirPeso(['Asignado' => 2, 'En Progreso' => 4, 'En Espera' => 1, 'Resuelto' => $minutos > 120 ? 2 : 0]);
        $tecnico = $estado === 'Registrado' ? null : elegir($tecnicos);
        $primera = in_array($estado, ['En Progreso', 'En Espera', 'Resuelto'], true) ? min($creado->modify('+' . azar(5, max(6, min($minutos - 2, (int) ($resp_min * 0.9)))) . ' minutes'), $ahora) : null;
        $resuelto = $estado === 'Resuelto' && $primera ? min($primera->modify('+' . azar(20, max(21, $minutos - 10)) . ' minutes'), $ahora) : null;
        $pausa = $estado === 'En Espera' && $primera ? min($primera->modify('+' . azar(5, 40) . ' minutes'), $ahora) : null;
        $t = ['created_at' => f($creado), 'tipo' => $tipo, 'prioridad' => $prioridad, 'sla_respuesta_min' => $resp_min, 'sla_resolucion_min' => $resol_min,
              'calendario' => $calendario, 'estado' => $estado, 'comportamiento' => COMP_ABIERTO, 'minutos_pausa' => 0,
              'fecha_primera_respuesta' => null, 'fecha_resolucion' => null, 'fecha_cierre' => null, 'fecha_pausa' => null];
        $vence = camposVencimiento($t, $cfg);
        $crear->execute([
            $tipo . '-' . str_pad((string) (++$ultimo), 5, '0', STR_PAD_LEFT), $usuario, $tecnico, $cat['subcategoria'], $cat['servicio'], $cat['subcategoria'], $impacto, $urgencia,
            $cat['grupo_resolutor'], $prioridad, $estado,
            elegir(['Desde esta mañana', 'Hace un momento', 'Desde la actualización de anoche']) . ': ' . mb_strtolower(rtrim($cat['subcategoria'], '.')) . '. '
                . elegir(['Lo necesitamos para hoy.', 'Afecta a varias personas del área.', 'Adjuntamos captura.', 'Agradecemos su apoyo.']),
            $primera ? 'Recibimos tu caso y ya lo estamos revisando.' : null, $resuelto ? 'Se corrigió y se validó con el usuario. Quedamos atentos a tu confirmación.' : null,
            $primera ? f($primera) : null, f($creado), $vence['fecha_vencimiento'] ?? null, $pausa ? f($pausa) : null, f($resuelto ?: ($pausa ?: ($primera ?: $creado))),
            $tipo, $primera ? f($primera) : null, $resuelto ? f($resuelto) : null, $vence['vence_respuesta'] ?? null, $resp_min, $resol_min, $calendario,
        ]);
        $id = (int) $db->lastInsertId();
        if ($tecnico) $historial->execute([$id, f(min($creado->modify('+' . azar(1, 5) . ' minutes'), $ahora)), null, 'Cambio de estado', 'Asignación automática al especialista', 'Registrado', 'Asignado', 0]);
        if ($primera) $historial->execute([$id, f($primera), $tecnico, 'Primera respuesta', 'Primera respuesta enviada al cliente', 'Asignado', 'En Progreso', 1]);
        if ($pausa) $historial->execute([$id, f($pausa), $tecnico, 'Cambio de estado', 'En espera de información del cliente', 'En Progreso', 'En Espera', 1]);
        if ($resuelto) $historial->execute([$id, f($resuelto), $tecnico, 'Solución', 'Solución registrada', 'En Progreso', 'Resuelto', 1]);
        $atendidos[] = $id;
        $nuevos++;
    }
    // ANS de los casos tocados, con la misma regla de la mesa
    if ($atendidos) {
        $guardar = $db->prepare("UPDATE soporte_tickets SET sla_respuesta_cumplido = ?, sla_resolucion_cumplido = ? WHERE id = ?");
        foreach (ticketsItsm($db, 't.id IN (' . implode(',', array_map('intval', $atendidos)) . ')') as $caso) {
            if (empty($caso['sla'])) continue;
            $r = $caso['sla']['respuesta']['cumplido'];
            $s = $caso['sla']['resolucion']['cumplido'];
            $guardar->execute([$r === null ? null : (int) $r, $s === null ? null : (int) $s, $caso['id']]);
        }
    }

    // Mensajes del sitio y conversaciones del chat de hoy
    $mensajes = ['BI' => 'Queremos un tablero de indicadores para la gerencia.', 'DS' => 'Nos interesa un modelo para pronosticar ventas.',
                 'CGD' => 'Necesitamos mejorar la calidad de los datos de clientes.', 'DSW' => 'Buscamos desarrollar una plataforma web interna.',
                 'ASP' => 'La entidad requiere seguimiento al plan de desarrollo.', 'PSI' => 'Queremos una conferencia sobre trabajo en equipo.'];
    $crear_contacto = $db->prepare("INSERT INTO contactos (nombre, correo, mensaje, estado, tipo_cliente, servicio, fecha_registro, leido) VALUES (?, ?, ?, 'Nuevo', ?, ?, ?, 0)");
    for ($k = 0, $n = azar(1, 3); $k < $n; $k++) {
        $nombre = persona();
        $servicio = elegir(array_keys($mensajes));
        $crear_contacto->execute([$nombre, correoDe($nombre), $mensajes[$servicio], elegirPeso(['Privado' => 6, 'Público' => 2, 'mixto' => 2]), $servicio, f($momento())]);
    }
    $crear_sesion = $db->prepare("INSERT INTO ds_chat_sessions (nombre_usuario, empresa_entidad, fecha_inicio, estado, visto) VALUES (?, ?, ?, 'finalizado', 0)");
    $crear_mensaje = $db->prepare("INSERT INTO ds_chat_messages (session_id, remitente, mensaje, timestamp) VALUES (?, ?, ?, ?)");
    for ($k = 0, $n = azar(1, 3); $k < $n; $k++) {
        $inicio_chat = $momento();
        $crear_sesion->execute([elegir(NOMBRES) . ' ' . elegir(APELLIDOS), elegir(['Independiente', 'Empresa de logística', 'Colegio', '']), f($inicio_chat)]);
        $sesion = (int) $db->lastInsertId();
        $crear_mensaje->execute([$sesion, 'user', '¿Qué servicios ofrecen?', f($inicio_chat->modify('+20 seconds'))]);
        $crear_mensaje->execute([$sesion, 'bot', 'Ofrecemos inteligencia de negocios, ciencia de datos, gobierno de datos, analítica para el sector público y desarrollo de software. ¿Sobre cuál quieres saber más?', f($inicio_chat->modify('+24 seconds'))]);
    }

    // A veces, una PQRSD radicada hoy
    $pqrsd = 0;
    if ($es_habil && probable(0.5)) {
        $registro = $momento();
        asegurarConsecutivo($db, 'PQR', (int) $registro->format('Y'));
        $nombre = persona();
        [$tipo, $asunto, $texto] = elegir([['Petición', 'Solicitud de certificación de servicios', 'Solicito una certificación de los servicios prestados durante este año.'],
                                           ['Sugerencia', 'Exportar los reportes a PDF', 'Sería útil descargar en PDF los reportes del tablero.'],
                                           ['Reclamo', 'Demora en la actualización del tablero', 'El tablero no muestra los datos de ayer y el sistema presenta error.']]);
        $responsables = array_map('intval', $db->query("SELECT usuario_id FROM pqrsd_responsables WHERE recibe_casos = 1")->fetchAll(PDO::FETCH_COLUMN));
        $asignado = $responsables ? elegir($responsables) : null;
        $db->prepare("INSERT INTO pqrsd (radicado, token_consulta, tipo, tema, anonima, prioritaria, nombre, tipo_documento, documento, correo, telefono, ciudad, medio_respuesta,
                      asunto, descripcion, estado, asignado_a, dias_termino, fecha_registro, fecha_limite) VALUES (?, ?, ?, ?, 0, 0, ?, 'CC', ?, ?, ?, ?, 'Correo', ?, ?, ?, ?, ?, ?, ?)")
           ->execute([siguienteConsecutivo($db, 'PQR', (int) $registro->format('Y')), bin2hex(random_bytes(8)), $tipo, temaPqrsd($asunto, $texto), $nombre, cedula(), correoDe($nombre),
                      telefono(), elegirPeso(CIUDADES), $asunto, $texto, $asignado ? 'En trámite' : 'Radicada', $asignado, diasTerminoPqrsd($tipo), f($registro),
                      sumarDiasHabilesPqrsd(f($registro), diasTerminoPqrsd($tipo))]);
        $pqrsd_id = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO pqrsd_historial (pqrsd_id, fecha, usuario_id, evento, detalle, estado_anterior, estado_nuevo, publico) VALUES (?, ?, NULL, 'Radicación', 'Solicitud radicada por el canal web', NULL, 'Radicada', 1)")
           ->execute([$pqrsd_id, f($registro)]);
        $pqrsd = 1;
    }

    $db->prepare("UPDATE ds_instalacion SET estado = ?, actualizada = NOW() WHERE id = 3")->execute([$dia]);
    $db->commit();
    echo date('Y-m-d H:i:s') . " Actividad del día: $nuevos casos nuevos, " . (count($atendidos) - $nuevos) . " casos atendidos, $pqrsd PQRSD\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Actividad del día: no se generó (' . $e->getMessage() . ' en la línea ' . $e->getLine() . ")\n");
    exit(1);
}
