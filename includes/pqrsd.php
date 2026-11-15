<?php
/**
 * PQRSD - MOTOR COMPARTIDO
 * Peticiones, Quejas, Reclamos, Sugerencias y Denuncias: términos legales en días hábiles (Ley 1755 de 2015),
 * radicado consecutivo, estados y historial. Lo usan la página pública pqrsd.php y el panel admin/pqrsd.php.
 */
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/contabilidad.php'; // consecutivos (radicado) y dinero()
require_once __DIR__ . '/itsm.php';         // festivos de Colombia para contar días hábiles

// Tipo de solicitud => días hábiles de término legal
const TIPOS_PQRSD = [
    'Petición'                => ['dias' => 15, 'texto' => 'Solicitud de interés general o particular.'],
    'Queja'                   => ['dias' => 15, 'texto' => 'Inconformidad con la conducta de un colaborador.'],
    'Reclamo'                 => ['dias' => 15, 'texto' => 'Inconformidad con la prestación de un servicio.'],
    'Sugerencia'              => ['dias' => 15, 'texto' => 'Propuesta para mejorar el servicio.'],
    'Denuncia'                => ['dias' => 15, 'texto' => 'Reporte de una irregularidad. Puede ser anónima.'],
    'Solicitud de información' => ['dias' => 10, 'texto' => 'Información o copia de documentos.'],
    'Consulta'                => ['dias' => 30, 'texto' => 'Concepto sobre temas a cargo de la empresa.'],
];

const ESTADOS_PQRSD = ['Radicada', 'En trámite', 'En espera del ciudadano', 'Respondida', 'Cerrada', 'Trasladada', 'Desistida'];
const COLORES_PQRSD = ['Radicada' => 'secondary', 'En trámite' => 'primary', 'En espera del ciudadano' => 'info',
                       'Respondida' => 'success', 'Cerrada' => 'dark', 'Trasladada' => 'warning', 'Desistida' => 'secondary'];
const ESTADOS_CERRADOS_PQRSD = ['Respondida', 'Cerrada', 'Trasladada', 'Desistida'];
// Los que se ponen a mano en la bandeja: la espera del ciudadano, el traslado y el desistimiento
// solo salen de las actuaciones de ley, nunca del combo de estados.
const ESTADOS_MANUALES_PQRSD = ['Radicada', 'En trámite', 'Respondida', 'Cerrada'];
const MEDIOS_RESPUESTA_PQRSD = ['Correo' => 'Correo electrónico', 'Fisico' => 'Correo físico', 'Ninguno' => 'No requiere respuesta'];

/**
 * Tema de la solicitud: sirve para que el reparto mire de qué se trata y no solo el tipo,
 * así un reclamo de facturación no le cae a quien atiende lo técnico. A cada tema le siguen
 * las palabras que lo reconocen, escritas sin tildes y en minúscula.
 */
const TEMAS_PQRSD = [
    'Facturación y cartera' => ['factur', 'cobro', 'cobra', 'pago', 'recaudo', 'cartera', 'nota credito', 'nota debito', 'saldo', 'iva', 'tarifa', 'precio'],
    'Servicio técnico'      => ['tablero', 'dashboard', 'error', 'falla', 'no carga', 'lento', 'acceso', 'clave', 'contrasena', 'plataforma', 'sistema', 'api', 'integracion', 'actualiza'],
    'Datos personales'      => ['datos personales', 'habeas data', 'tratamiento de datos', 'supresion', 'privacidad', 'confidencial', 'informacion de clientes'],
    'Comercial y contratos' => ['contrato', 'cotizacion', 'propuesta', 'renovacion', 'comercial', 'servicio contratado', 'garantia'],
    'Atención y servicio'   => ['atencion', 'llamada', 'no me respondieron', 'demora', 'trato', 'asesor', 'capacitacion', 'entrenamiento', 'sin respuesta'],
];
const TEMA_PQRSD_OTROS = 'Otros';

// ¿La base ya guarda el tema de cada solicitud?
function temaPqrsdDisponible(PDO $db) {
    static $existe = null;
    if ($existe === null) {
        try {
            $db->query('SELECT tema FROM pqrsd LIMIT 1');
            $existe = true;
        } catch (Exception $e) {
            $existe = false;
        }
    }
    return $existe;
}

// ¿La tabla de responsables ya tiene la columna de temas? (se comprueba aparte: puede existir la de
// pqrsd.tema sin que exista esta, según el orden en que se aplicaron los archivos)
function temasResponsablesDisponible(PDO $db) {
    static $existe = null;
    if ($existe === null) {
        try {
            $db->query('SELECT temas FROM pqrsd_responsables LIMIT 1');
            $existe = true;
        } catch (Exception $e) {
            $existe = false;
        }
    }
    return $existe;
}

// Sin tildes y en minúscula, para comparar sin sorpresas
function normalizarTextoPqrsd($texto) {
    return strtr(mb_strtolower((string) $texto, 'UTF-8'),
        ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']);
}

// Deduce el tema con las palabras del asunto (que pesa doble) y del detalle. Se puede corregir en la ficha.
function temaPqrsd($asunto, $descripcion = '') {
    $texto_asunto = normalizarTextoPqrsd($asunto);
    $texto = $texto_asunto . ' ' . normalizarTextoPqrsd($descripcion);
    $elegido = TEMA_PQRSD_OTROS;
    $mejor = 0;
    foreach (TEMAS_PQRSD as $tema => $palabras) {
        $puntos = 0;
        foreach ($palabras as $palabra) {
            if (mb_strpos($texto_asunto, $palabra) !== false) $puntos += 2;
            elseif (mb_strpos($texto, $palabra) !== false) $puntos++;
        }
        if ($puntos > $mejor) { $mejor = $puntos; $elegido = $tema; }
    }
    return $elegido;
}

// Atención prioritaria (Ley 1755 de 2015, art. 20)
const MOTIVOS_PRIORITARIOS_PQRSD = [
    'Niño, niña o adolescente', 'Persona adulta mayor', 'Persona en situación de discapacidad',
    'Periodista, en ejercicio de su actividad', 'Riesgo de un perjuicio irremediable a un derecho fundamental',
];
// Plazos de la Ley 1755 de 2015: el traslado por competencia (art. 21) y el mes que tiene el ciudadano
// para completar la información que se le pide (art. 17). La prórroga (art. 14) no puede pasar del doble del término.
const DIAS_TRASLADO_PQRSD = 5;
const MESES_DESISTIMIENTO_PQRSD = 1;

function pqrsdDisponible(PDO $db) {
    static $existe = null;
    if ($existe === null) {
        horarioPqrsd($db); // deja el horario de atención listo para los cálculos de términos
        try {
            $db->query('SELECT 1 FROM pqrsd LIMIT 1');
            $existe = true;
        } catch (Exception $e) {
            $existe = false;
        }
    }
    return $existe;
}

/**
 * Horario de atención del canal: es el mismo de la mesa de servicios, el que se configura en
 * Supervisión y Cargas › ANS y horario (por defecto lunes a viernes de 8:00 a 17:00, sin festivos
 * de Colombia). La primera llamada con la conexión lo carga; después queda en memoria.
 */
function horarioPqrsd(PDO $db = null) {
    static $cfg = null;
    if ($cfg === null && $db !== null) $cfg = configItsm($db);
    if ($cfg === null) $cfg = CONFIG_ITSM_POR_DEFECTO;
    return $cfg;
}

// Días de la semana que se atienden (1 = lunes ... 7 = domingo)
function diasSemanaPqrsd() {
    $cfg = horarioPqrsd();
    $dias = array_values(array_filter(array_map('intval', explode(',', (string) $cfg['dias_habiles'])), function ($d) { return $d >= 1 && $d <= 7; }));
    return $dias ?: [1, 2, 3, 4, 5];
}

// ¿Ese día se atiende? (día de la semana y festivos de Colombia)
function esDiaHabilPqrsd(DateTime $dia) {
    $cfg = horarioPqrsd();
    if (!in_array((int) $dia->format('N'), diasSemanaPqrsd(), true)) return false;
    return !(($cfg['festivos_colombia'] ?? '1') === '1' && isset(festivosColombia((int) $dia->format('Y'))[$dia->format('Y-m-d')]));
}

// "lunes a viernes de 8:00 a.m. a 5:00 p.m." para mostrárselo al ciudadano
function horarioTextoPqrsd() {
    $cfg = horarioPqrsd();
    $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    $dias = diasSemanaPqrsd();
    $seguidos = $dias === range(min($dias), max($dias));
    $texto_dias = $seguidos && count($dias) > 1
        ? $nombres[min($dias)] . ' a ' . $nombres[max($dias)]
        : implode(', ', array_map(function ($d) use ($nombres) { return $nombres[$d]; }, $dias));
    $hora = function ($h) {
        $t = strtotime($h ?: '08:00');
        return ltrim(date('g:i', $t), '0') . ' ' . (date('a', $t) === 'am' ? 'a.m.' : 'p.m.');
    };
    return $texto_dias . ' de ' . $hora($cfg['hora_inicio']) . ' a ' . $hora($cfg['hora_fin']);
}

function diasTerminoPqrsd($tipo) {
    return isset(TIPOS_PQRSD[$tipo]) ? TIPOS_PQRSD[$tipo]['dias'] : 15;
}

// Suma días hábiles según el horario de atención. El término empieza el día hábil siguiente a la
// radicación y vence a la hora de cierre de la jornada (por defecto 5:00 p.m.).
function sumarDiasHabilesPqrsd($desde, $dias) {
    $fecha = new DateTime((string) $desde);
    $sumados = 0;
    $tope = 0; // por si la configuración quedara sin días hábiles
    while ($sumados < (int) $dias && $tope++ < 3650) {
        $fecha->modify('+1 day');
        if (esDiaHabilPqrsd($fecha)) $sumados++;
    }
    $cierre = horarioPqrsd()['hora_fin'] ?: '17:00';
    return $fecha->format('Y-m-d') . ' ' . substr($cierre, 0, 5) . ':00';
}

// Días hábiles que faltan (negativo si el término ya venció)
function diasHabilesRestantesPqrsd($fecha_limite, $ahora = null) {
    $limite = new DateTime(substr((string) $fecha_limite, 0, 10));
    $hoy = new DateTime(substr((string) ($ahora ?: date('Y-m-d H:i:s')), 0, 10));
    $signo = $limite < $hoy ? -1 : 1;
    list($inicio, $fin) = $signo === 1 ? [$hoy, $limite] : [$limite, $hoy];
    $cursor = clone $inicio;
    $dias = 0;
    while ($cursor < $fin) {
        $cursor->modify('+1 day');
        if (esDiaHabilPqrsd($cursor)) $dias++;
    }
    return $signo * $dias;
}

// Fecha en la que vence de verdad: la prórroga del art. 14 corre el término inicial
function fechaLimitePqrsd(array $p) {
    return !empty($p['prorroga_hasta']) ? $p['prorroga_hasta'] : $p['fecha_limite'];
}

// Hasta dónde se puede prorrogar: al plazo inicial se le puede sumar, cuando más, otro tanto igual (art. 14)
function topeProrrogaPqrsd(array $p) {
    return sumarDiasHabilesPqrsd($p['fecha_limite'], (int) $p['dias_termino']);
}

// Un mes calendario para que el ciudadano complete lo que se le pidió (art. 17); si no, se entiende desistida
function fechaDesistimientoPqrsd($desde = null) {
    $fecha = new DateTime((string) ($desde ?: date('Y-m-d H:i:s')));
    $fecha->modify('+' . MESES_DESISTIMIENTO_PQRSD . ' month');
    return $fecha->format('Y-m-d 17:00:00');
}

// Fecha máxima para trasladar por competencia: 5 días desde la radicación (art. 21)
function fechaTrasladoPqrsd(array $p) {
    return sumarDiasHabilesPqrsd($p['fecha_registro'], DIAS_TRASLADO_PQRSD);
}

// "Término: 14/10/2026 · quedan 8 días hábiles" (nunca solo "vencida hace X").
// Tiene en cuenta la prórroga, la espera del ciudadano y los cierres por traslado o desistimiento.
function textoTerminoPqrsd(array $p, $ahora = null) {
    $limite_real = fechaLimitePqrsd($p);
    $limite = date('d/m/Y', strtotime($limite_real));
    $prorrogada = !empty($p['prorroga_hasta']);
    $sufijo = $prorrogada ? ' (prorrogado)' : '';

    if ($p['estado'] === 'Trasladada') {
        return ['texto' => 'Trasladada por competencia el ' . (!empty($p['fecha_traslado']) ? date('d/m/Y', strtotime($p['fecha_traslado'])) : '—')
                           . (!empty($p['trasladada_a']) ? ' a ' . $p['trasladada_a'] : ''), 'clase' => 'warning', 'etiqueta' => 'Trasladada'];
    }
    if ($p['estado'] === 'Desistida') {
        return ['texto' => 'Se entendió desistida: el ciudadano no completó la información dentro del mes (art. 17).', 'clase' => 'secondary', 'etiqueta' => 'Desistida'];
    }
    if (in_array($p['estado'], ['Respondida', 'Cerrada'], true)) {
        $respondida = $p['fecha_respuesta'] ?: $p['fecha_cierre'];
        $a_tiempo = $respondida && strtotime($respondida) <= strtotime($limite_real);
        return ['texto' => 'Término: ' . $limite . $sufijo . ' · respondida el ' . ($respondida ? date('d/m/Y', strtotime($respondida)) : '—'),
                'clase' => $a_tiempo ? 'success' : 'danger', 'etiqueta' => $a_tiempo ? 'Dentro del término' : 'Fuera del término'];
    }
    if ($p['estado'] === 'En espera del ciudadano') {
        $vence = !empty($p['desiste_el']) ? date('d/m/Y', strtotime($p['desiste_el'])) : '—';
        return ['texto' => 'El término está suspendido: se pidió información al ciudadano y tiene hasta el ' . $vence . ' para completarla.',
                'clase' => 'info', 'etiqueta' => 'Esperando al ciudadano'];
    }

    $restantes = diasHabilesRestantesPqrsd($limite_real, $ahora);
    if ($restantes < 0) return ['texto' => 'Término: ' . $limite . $sufijo . ' · lleva ' . abs($restantes) . ' día(s) hábil(es) de más', 'clase' => 'danger', 'etiqueta' => 'Vencida'];
    if ($restantes === 0) return ['texto' => 'Término: ' . $limite . $sufijo . ' · vence hoy', 'clase' => 'warning', 'etiqueta' => 'Vence hoy'];
    return ['texto' => 'Término: ' . $limite . $sufijo . ' · quedan ' . $restantes . ' día(s) hábil(es)',
            'clase' => $restantes <= 3 ? 'warning' : 'success', 'etiqueta' => $restantes <= 3 ? 'Por vencer' : 'En término'];
}

function registrarEventoPqrsd(PDO $db, $pqrsd_id, $usuario_id, $evento, $detalle = null, $estado_anterior = null, $estado_nuevo = null, $publico = true, $fecha = null) {
    $db->prepare("INSERT INTO pqrsd_historial (pqrsd_id, fecha, usuario_id, evento, detalle, estado_anterior, estado_nuevo, publico) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([(int) $pqrsd_id, $fecha ?: date('Y-m-d H:i:s'), $usuario_id ?: null, mb_substr($evento, 0, 40),
                  $detalle !== null ? mb_substr($detalle, 0, 500) : null, $estado_anterior, $estado_nuevo, $publico ? 1 : 0]);
}

function historialPqrsd(PDO $db, $pqrsd_id, $solo_publico = false) {
    $sql = "SELECT h.*, u.nombre AS usuario FROM pqrsd_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE h.pqrsd_id = ?";
    if ($solo_publico) $sql .= " AND h.publico = 1";
    $stmt = $db->prepare($sql . " ORDER BY h.fecha ASC, h.id ASC");
    $stmt->execute([(int) $pqrsd_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pqrsdPorId(PDO $db, $id) {
    $stmt = $db->prepare("SELECT p.*, a.nombre AS nombre_asignado, r.nombre AS nombre_respondio
                          FROM pqrsd p LEFT JOIN usuarios a ON a.id = p.asignado_a LEFT JOIN usuarios r ON r.id = p.respondido_por
                          WHERE p.id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pqrsdPorRadicado(PDO $db, $radicado, $token) {
    $stmt = $db->prepare("SELECT * FROM pqrsd WHERE radicado = ? AND token_consulta = ?");
    $stmt->execute([trim((string) $radicado), trim((string) $token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Radica una PQRSD y devuelve [id, radicado, token]. Calcula el término legal según el tipo.
 * $datos: tipo, anonima, nombre, tipo_documento, documento, correo, telefono, direccion, ciudad,
 *         medio_respuesta, asunto, descripcion, adjunto, usuario_id, ip
 */
function radicarPqrsd(PDO $db, array $datos) {
    $tipo = isset(TIPOS_PQRSD[$datos['tipo']]) ? $datos['tipo'] : 'Petición';
    $dias = diasTerminoPqrsd($tipo);
    $ahora = date('Y-m-d H:i:s');
    $anio = (int) date('Y');
    asegurarConsecutivo($db, 'PQR', $anio);

    $db->beginTransaction();
    do {
        $radicado = siguienteConsecutivo($db, 'PQR', $anio);
        $existe = $db->prepare("SELECT COUNT(*) FROM pqrsd WHERE radicado = ?");
        $existe->execute([$radicado]);
    } while ($existe->fetchColumn());

    $token = bin2hex(random_bytes(8));
    $anonima = !empty($datos['anonima']) ? 1 : 0;
    // Atención prioritaria (art. 20): el ciudadano la puede pedir al radicar, indicando el motivo
    $motivo_prioritaria = mb_substr(trim((string) ($datos['motivo_prioritaria'] ?? '')), 0, 120);
    $prioritaria = !empty($datos['prioritaria']) && in_array($motivo_prioritaria, MOTIVOS_PRIORITARIOS_PQRSD, true) && pqrsdNormativaDisponible($db);
    // Tema deducido del asunto: con él se busca a quien atiende ese tema
    $tema = temaPqrsdDisponible($db) ? temaPqrsd($datos['asunto'] ?? '', $datos['descripcion'] ?? '') : null;
    $db->prepare("INSERT INTO pqrsd (radicado, token_consulta, tipo, anonima, nombre, tipo_documento, documento, correo, telefono, direccion, ciudad,
                                     medio_respuesta, asunto, descripcion, adjunto, estado, usuario_id, dias_termino, fecha_registro, fecha_limite, ip"
                  . ($prioritaria ? ", prioritaria, motivo_prioritaria" : "") . ($tema !== null ? ", tema" : "") . ")
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Radicada', ?, ?, ?, ?, ?"
                  . ($prioritaria ? ", 1, ?" : "") . ($tema !== null ? ", ?" : "") . ")")
       ->execute(array_merge([$radicado, $token, $tipo, $anonima,
                  $anonima ? null : mb_substr(trim((string) ($datos['nombre'] ?? '')), 0, 150),
                  $anonima ? null : (trim((string) ($datos['tipo_documento'] ?? '')) ?: null),
                  $anonima ? null : (trim((string) ($datos['documento'] ?? '')) ?: null),
                  $anonima ? null : (trim((string) ($datos['correo'] ?? '')) ?: null),
                  $anonima ? null : (trim((string) ($datos['telefono'] ?? '')) ?: null),
                  $anonima ? null : (trim((string) ($datos['direccion'] ?? '')) ?: null),
                  trim((string) ($datos['ciudad'] ?? '')) ?: null,
                  isset(MEDIOS_RESPUESTA_PQRSD[$datos['medio_respuesta'] ?? '']) ? $datos['medio_respuesta'] : 'Correo',
                  mb_substr(trim((string) $datos['asunto']), 0, 180), trim((string) $datos['descripcion']),
                  $datos['adjunto'] ?? null, $datos['usuario_id'] ?? null, $dias, $ahora,
                  sumarDiasHabilesPqrsd($ahora, $dias), $datos['ip'] ?? null],
                  $prioritaria ? [$motivo_prioritaria] : [], $tema !== null ? [$tema] : []));
    $id = (int) $db->lastInsertId();
    $db->commit();

    registrarEventoPqrsd($db, $id, $datos['usuario_id'] ?? null, 'Radicación',
        "Radicada por el canal web como $tipo. Término legal: $dias días hábiles.", null, 'Radicada', true, $ahora);
    if ($prioritaria) {
        registrarEventoPqrsd($db, $id, $datos['usuario_id'] ?? null, 'Atención prioritaria',
            'Se pidió atención prioritaria (Ley 1755 de 2015, art. 20): ' . $motivo_prioritaria . '.', null, null, true, $ahora);
    }
    return [$id, $radicado, $token];
}

// ---------------------------------------------------------------------------------------------
// ACTUACIONES DE LEY (Ley 1755 de 2015). Cada una deja traza en el historial y devuelve
// [correcto, mensaje]; el correo al ciudadano lo envía quien la llama (admin/procesar_pqrsd.php).
// ---------------------------------------------------------------------------------------------

// ¿La base ya tiene las columnas de la normativa? (prórroga, requerimiento, traslado, prioritaria)
function pqrsdNormativaDisponible(PDO $db) {
    static $existe = null;
    if ($existe === null) {
        try {
            $db->query('SELECT prorroga_hasta, desiste_el, trasladada_a, prioritaria FROM pqrsd LIMIT 1');
            $existe = true;
        } catch (Exception $e) {
            $existe = false;
        }
    }
    return $existe;
}

function actualizarPqrsd(PDO $db, $id, array $campos) {
    if (!$campos) return;
    $sets = [];
    $valores = [];
    foreach ($campos as $columna => $valor) {
        $sets[] = "`$columna` = ?";
        $valores[] = $valor;
    }
    $valores[] = (int) $id;
    $db->prepare("UPDATE pqrsd SET " . implode(', ', $sets) . " WHERE id = ?")->execute($valores);
}

// Las que ya salieron de trámite no admiten actuaciones nuevas
function pqrsdEnTramite(array $p) {
    return !in_array($p['estado'], ESTADOS_CERRADOS_PQRSD, true);
}

/**
 * PRÓRROGA (art. 14). Antes de que venza el término se le avisa al ciudadano explicando los motivos
 * y la fecha en que se responderá. El nuevo plazo no puede pasar del doble del inicial.
 */
function prorrogarPqrsd(PDO $db, $id, $usuario_id, $dias, $motivo) {
    $p = pqrsdPorId($db, $id);
    $dias = (int) $dias;
    $motivo = trim((string) $motivo);
    if (!$p) return [false, 'Esa solicitud no existe.'];
    if (!pqrsdNormativaDisponible($db)) return [false, 'La base todavía no tiene las columnas de la normativa.'];
    if (!pqrsdEnTramite($p)) return [false, 'La solicitud ya salió de trámite: no se puede prorrogar.'];
    if ($dias < 1) return [false, 'Indica cuántos días hábiles más necesitas.'];
    if (mb_strlen($motivo) < 15) return [false, 'La ley exige explicar los motivos de la prórroga: escríbelos con claridad.'];

    $nueva = sumarDiasHabilesPqrsd(fechaLimitePqrsd($p), $dias);
    $tope = topeProrrogaPqrsd($p);
    if (strtotime($nueva) > strtotime($tope)) {
        return [false, 'La prórroga no puede pasar del doble del término: la fecha máxima es el ' . date('d/m/Y', strtotime($tope)) . '.'];
    }
    $estado = $p['estado'] === 'Radicada' ? 'En trámite' : $p['estado'];
    actualizarPqrsd($db, $id, ['fecha_prorroga' => date('Y-m-d H:i:s'), 'prorroga_hasta' => $nueva,
                               'prorroga_motivo' => mb_substr($motivo, 0, 300), 'estado' => $estado]);
    registrarEventoPqrsd($db, $id, $usuario_id, 'Prórroga del término',
        'El término se prorrogó hasta el ' . date('d/m/Y', strtotime($nueva)) . ' (Ley 1755 de 2015, art. 14). Motivos: ' . $motivo,
        $p['estado'], $estado);
    return [true, 'Prórroga registrada: el término va hasta el ' . date('d/m/Y', strtotime($nueva)) . '.'];
}

/**
 * REQUERIMIENTO AL CIUDADANO (art. 17). Si falta información, se le pide y el término queda suspendido.
 * Tiene un mes para completarla; si no lo hace, se entiende que desistió.
 */
function requerirInformacionPqrsd(PDO $db, $id, $usuario_id, $detalle) {
    $p = pqrsdPorId($db, $id);
    $detalle = trim((string) $detalle);
    if (!$p) return [false, 'Esa solicitud no existe.'];
    if (!pqrsdNormativaDisponible($db)) return [false, 'La base todavía no tiene las columnas de la normativa.'];
    if (!pqrsdEnTramite($p)) return [false, 'La solicitud ya salió de trámite.'];
    if ($p['estado'] === 'En espera del ciudadano') return [false, 'Ya hay un requerimiento pendiente en esta solicitud.'];
    if (!empty($p['anonima'])) return [false, 'La denuncia es anónima: no hay a quién pedirle información.'];
    if (mb_strlen($detalle) < 10) return [false, 'Escribe qué información o documento le hace falta al ciudadano.'];

    $ahora = date('Y-m-d H:i:s');
    $desiste = fechaDesistimientoPqrsd($ahora);
    actualizarPqrsd($db, $id, ['estado' => 'En espera del ciudadano', 'fecha_requerimiento' => $ahora,
                               'requerimiento_detalle' => mb_substr($detalle, 0, 300), 'desiste_el' => $desiste]);
    registrarEventoPqrsd($db, $id, $usuario_id, 'Requerimiento al ciudadano',
        'Se le pidió completar la información (Ley 1755 de 2015, art. 17). Tiene hasta el ' . date('d/m/Y', strtotime($desiste))
        . '; si no responde, se entenderá que desistió. Falta: ' . $detalle, $p['estado'], 'En espera del ciudadano');
    return [true, 'Requerimiento registrado: el término queda suspendido hasta el ' . date('d/m/Y', strtotime($desiste)) . '.'];
}

/**
 * El ciudadano completó lo que se le pidió: el término vuelve a correr completo desde hoy.
 * La prórroga anterior pertenecía al término viejo, así que se suelta (queda en el historial).
 */
function completarRequerimientoPqrsd(PDO $db, $id, $usuario_id, $detalle = null) {
    $p = pqrsdPorId($db, $id);
    if (!$p) return [false, 'Esa solicitud no existe.'];
    if (!pqrsdNormativaDisponible($db)) return [false, 'La base todavía no tiene las columnas de la normativa.'];
    if ($p['estado'] !== 'En espera del ciudadano') return [false, 'Esta solicitud no tiene un requerimiento pendiente.'];

    $ahora = date('Y-m-d H:i:s');
    $limite = sumarDiasHabilesPqrsd($ahora, (int) $p['dias_termino']);
    actualizarPqrsd($db, $id, ['estado' => 'En trámite', 'fecha_limite' => $limite, 'desiste_el' => null,
                               'prorroga_hasta' => null, 'fecha_prorroga' => null, 'prorroga_motivo' => null]);
    registrarEventoPqrsd($db, $id, $usuario_id, 'Información completada',
        'El ciudadano completó lo que se le pidió: el término vuelve a correr y vence el ' . date('d/m/Y', strtotime($limite)) . '.'
        . ($detalle ? ' ' . trim($detalle) : ''), 'En espera del ciudadano', 'En trámite');
    return [true, 'Listo: el término vuelve a correr y vence el ' . date('d/m/Y', strtotime($limite)) . '.'];
}

// DESISTIMIENTO (art. 17): pasó el mes sin que el ciudadano completara la información
function desistirPqrsd(PDO $db, $id, $usuario_id = null, $nota = null) {
    $p = pqrsdPorId($db, $id);
    if (!$p) return [false, 'Esa solicitud no existe.'];
    if (!pqrsdNormativaDisponible($db)) return [false, 'La base todavía no tiene las columnas de la normativa.'];
    if ($p['estado'] !== 'En espera del ciudadano') return [false, 'Solo se entiende desistida una solicitud con requerimiento pendiente.'];

    $ahora = date('Y-m-d H:i:s');
    actualizarPqrsd($db, $id, ['estado' => 'Desistida', 'fecha_cierre' => $ahora, 'cerrado_por' => $usuario_id ?: null]);
    registrarEventoPqrsd($db, $id, $usuario_id, 'Desistimiento',
        $nota ?: 'Se cumplió el mes del art. 17 sin que el ciudadano completara la información: se entiende desistida y se archiva.',
        'En espera del ciudadano', 'Desistida');
    return [true, 'La solicitud ' . $p['radicado'] . ' quedó archivada por desistimiento.'];
}

// Barrido: archiva las que se pasaron del mes del art. 17. Se llama al abrir la bandeja.
function marcarDesistidasPqrsd(PDO $db) {
    if (!pqrsdNormativaDisponible($db)) return 0;
    $vencidas = $db->query("SELECT id FROM pqrsd WHERE estado = 'En espera del ciudadano' AND desiste_el IS NOT NULL AND desiste_el < NOW()")
                   ->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($vencidas as $f) {
        list($ok) = desistirPqrsd($db, $f['id']);
        if ($ok) $n++;
    }
    return $n;
}

/**
 * TRASLADO POR COMPETENCIA (art. 21). Si el asunto no nos corresponde, se traslada dentro de los
 * cinco (5) días siguientes a la radicación y se le informa al ciudadano.
 */
function trasladarPqrsd(PDO $db, $id, $usuario_id, $entidad, $detalle = null) {
    $p = pqrsdPorId($db, $id);
    $entidad = trim((string) $entidad);
    $detalle = trim((string) $detalle);
    if (!$p) return [false, 'Esa solicitud no existe.'];
    if (!pqrsdNormativaDisponible($db)) return [false, 'La base todavía no tiene las columnas de la normativa.'];
    if (!pqrsdEnTramite($p)) return [false, 'La solicitud ya salió de trámite.'];
    if (mb_strlen($entidad) < 4) return [false, 'Escribe a qué entidad o dependencia se traslada.'];

    $ahora = date('Y-m-d H:i:s');
    actualizarPqrsd($db, $id, ['estado' => 'Trasladada', 'trasladada_a' => mb_substr($entidad, 0, 150), 'fecha_traslado' => $ahora,
                               'traslado_detalle' => $detalle !== '' ? mb_substr($detalle, 0, 300) : null,
                               'fecha_cierre' => $ahora, 'cerrado_por' => $usuario_id ?: null]);
    registrarEventoPqrsd($db, $id, $usuario_id, 'Traslado por competencia',
        'Se trasladó a ' . $entidad . ' y se le informó al ciudadano (Ley 1755 de 2015, art. 21).' . ($detalle ? ' ' . $detalle : ''),
        $p['estado'], 'Trasladada');
    $tarde = strtotime($ahora) > strtotime(fechaTrasladoPqrsd($p));
    return [true, 'Traslado registrado a ' . $entidad . '.' . ($tarde ? ' Quedó fuera de los 5 días que da el art. 21: el historial lo deja anotado.' : '')];
}

/**
 * ATENCIÓN PRIORITARIA (art. 20): niños y niñas, adultos mayores, personas en situación de
 * discapacidad, periodistas o riesgo de un perjuicio irremediable a un derecho fundamental.
 */
function marcarPrioritariaPqrsd(PDO $db, $id, $usuario_id, $prioritaria, $motivo = null) {
    $p = pqrsdPorId($db, $id);
    $motivo = trim((string) $motivo);
    if (!$p) return [false, 'Esa solicitud no existe.'];
    if (!pqrsdNormativaDisponible($db)) return [false, 'La base todavía no tiene las columnas de la normativa.'];
    $prioritaria = !empty($prioritaria);
    if ($prioritaria && !in_array($motivo, MOTIVOS_PRIORITARIOS_PQRSD, true)) {
        return [false, 'Escoge por cuál de los motivos del art. 20 se atiende de forma prioritaria.'];
    }
    if ($prioritaria == (bool) $p['prioritaria'] && $motivo === (string) $p['motivo_prioritaria']) {
        return [true, 'La atención prioritaria quedó como estaba.'];
    }
    actualizarPqrsd($db, $id, ['prioritaria' => $prioritaria ? 1 : 0, 'motivo_prioritaria' => $prioritaria ? $motivo : null]);
    registrarEventoPqrsd($db, $id, $usuario_id, 'Atención prioritaria',
        $prioritaria ? 'Se atiende de forma prioritaria (Ley 1755 de 2015, art. 20): ' . $motivo . '.'
                     : 'Se quitó la atención prioritaria: no se acreditó ninguno de los motivos del art. 20.');
    return [true, $prioritaria ? 'Queda marcada para atención prioritaria.' : 'Se quitó la atención prioritaria.'];
}

// Resumen para los indicadores del panel
function resumenPqrsd(PDO $db) {
    $r = ['total' => 0, 'abiertas' => 0, 'vencidas' => 0, 'por_vencer' => 0, 'respondidas' => 0, 'a_tiempo' => 0,
          'prorrogadas' => 0, 'esperando' => 0, 'prioritarias' => 0];
    $columnas = 'estado, fecha_limite, fecha_respuesta, fecha_cierre';
    try {
        $filas = $db->query("SELECT $columnas, prorroga_hasta, prioritaria FROM pqrsd")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { // base sin las columnas de la normativa
        $filas = $db->query("SELECT $columnas FROM pqrsd")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($filas as $p) {
        $r['total']++;
        if (!empty($p['prorroga_hasta'])) $r['prorrogadas']++;
        if (!empty($p['prioritaria'])) $r['prioritarias']++;
        $limite = !empty($p['prorroga_hasta']) ? $p['prorroga_hasta'] : $p['fecha_limite'];
        if (in_array($p['estado'], ['Respondida', 'Cerrada'], true)) {
            $r['respondidas']++;
            $fin = $p['fecha_respuesta'] ?: $p['fecha_cierre'];
            if ($fin && strtotime($fin) <= strtotime($limite)) $r['a_tiempo']++;
            continue;
        }
        if (in_array($p['estado'], ['Trasladada', 'Desistida'], true)) continue; // salieron del trámite conforme a la ley
        if ($p['estado'] === 'En espera del ciudadano') { $r['esperando']++; continue; } // el término está suspendido
        $r['abiertas']++;
        $restantes = diasHabilesRestantesPqrsd($limite);
        if ($restantes < 0) $r['vencidas']++;
        elseif ($restantes <= 3) $r['por_vencer']++;
    }
    $r['cumplimiento'] = $r['respondidas'] ? round($r['a_tiempo'] * 100 / $r['respondidas'], 1) : 0.0;
    return $r;
}

// ---------------------------------------------------------------------------------------------
// REPARTO AUTOMÁTICO Y DESEMPEÑO
// Mismo criterio que la mesa de servicios: especialidad primero, luego capacidad y carga.
// ---------------------------------------------------------------------------------------------

const CONFIG_PQRSD_POR_DEFECTO = [
    'asignacion_automatica' => '1', // reparte sola cada solicitud al radicarse
    'meta_cumplimiento'     => '95', // % de respuestas dentro del término que se espera de cada persona
    'bono_cumplimiento'     => '100', // desde este % se gana la bonificación
    'alerta_cumplimiento'   => '85', // por debajo de este % se abre un plan de mejora
    'minimo_para_medir'     => '3',  // con menos solicitudes atendidas no se califica todavía
];
const CAPACIDAD_PQRSD_POR_DEFECTO = 10;

function configPqrsd(PDO $db, $recargar = false) {
    static $cache = null;
    if ($cache === null || $recargar) {
        $cache = CONFIG_PQRSD_POR_DEFECTO;
        try {
            foreach ($db->query("SELECT clave, valor FROM pqrsd_configuracion")->fetchAll(PDO::FETCH_KEY_PAIR) as $clave => $valor) {
                if (array_key_exists($clave, $cache)) $cache[$clave] = (string) $valor;
            }
        } catch (PDOException $e) {
            // Base sin la migración del reparto: valores por defecto
        }
    }
    return $cache;
}

function repartoPqrsdDisponible(PDO $db) {
    static $existe = null;
    if ($existe === null) {
        try {
            $db->query('SELECT usuario_id, tipos, capacidad, recibe_casos FROM pqrsd_responsables LIMIT 1');
            $existe = true;
        } catch (Exception $e) {
            $existe = false;
        }
    }
    return $existe;
}

// Cuentas internas activas con acceso al módulo, con su configuración de reparto
function responsablesPqrsd(PDO $db) {
    $configuracion = [];
    try {
        foreach ($db->query("SELECT * FROM pqrsd_responsables")->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $configuracion[(int) $fila['usuario_id']] = $fila;
        }
    } catch (PDOException $e) {
        // Base sin la migración: se usa el valor por defecto de cada campo
    }
    $lista = [];
    foreach ($db->query("SELECT id, nombre, correo, rol, cargo FROM usuarios WHERE estado_cuenta = 'Activo' AND rol <> 'Cliente' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!function_exists('tieneAcceso') || !tieneAcceso($u['rol'], 'pqrsd', $db)) continue;
        $c = $configuracion[(int) $u['id']] ?? null;
        $lista[(int) $u['id']] = [
            'id' => (int) $u['id'], 'nombre' => $u['nombre'], 'correo' => $u['correo'], 'rol' => $u['rol'], 'cargo' => $u['cargo'],
            'tipos' => $c ? array_values(array_filter(array_map('trim', explode('|', (string) $c['tipos'])))) : [],
            'temas' => $c ? array_values(array_filter(array_map('trim', explode('|', (string) ($c['temas'] ?? ''))))) : [],
            'capacidad' => $c ? max(1, (int) $c['capacidad']) : CAPACIDAD_PQRSD_POR_DEFECTO,
            // Sin configuración reciben las cuentas de Soporte: el Administrador supervisa
            'recibe_casos' => $c ? (int) $c['recibe_casos'] === 1 : $u['rol'] === 'Soporte',
        ];
    }
    return $lista;
}

// Lo que pesa una solicitud en la carga: las prioritarias pesan doble y las que esperan al ciudadano, la mitad
function pesoPqrsd(array $p) {
    if ($p['estado'] === 'En espera del ciudadano') return 0.5;
    return !empty($p['prioritaria']) ? 2.0 : 1.0;
}

// Carga de cada responsable: suma del peso de sus solicitudes en trámite
function cargasResponsablesPqrsd(PDO $db) {
    $columnas = 'asignado_a, estado';
    try {
        $filas = $db->query("SELECT $columnas, prioritaria FROM pqrsd WHERE asignado_a IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $filas = $db->query("SELECT $columnas FROM pqrsd WHERE asignado_a IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    }
    $cargas = [];
    foreach ($filas as $p) {
        if (in_array($p['estado'], ESTADOS_CERRADOS_PQRSD, true)) continue;
        $id = (int) $p['asignado_a'];
        if (!isset($cargas[$id])) $cargas[$id] = ['carga' => 0.0, 'abiertas' => 0];
        $cargas[$id]['carga'] += pesoPqrsd($p);
        $cargas[$id]['abiertas']++;
    }
    return $cargas;
}

/**
 * Escoge quién atiende una solicitud. En orden:
 * 1) solo quienes reciben solicitudes (y no el responsable actual, si se está reasignando);
 * 2) especialidad: primero quienes atienden ese tema (facturación, técnico...); si nadie, quienes atienden
 *    ese tipo de PQRSD; si nadie, los que atienden de todo; si tampoco, cualquiera;
 * 3) capacidad: quienes no se pasarían de su tope con esta solicitud, si hay alguno;
 * 4) el de menor carga relativa (carga ÷ capacidad); a igualdad, menos solicitudes abiertas y luego el de menor id.
 * Devuelve [responsable, motivo] o null si nadie recibe solicitudes.
 */
function elegirResponsablePqrsd(array $responsables, array $cargas, array $p, $excluir = 0) {
    $candidatos = array_values(array_filter($responsables, function ($r) use ($excluir) {
        return $r['recibe_casos'] && $r['id'] !== (int) $excluir;
    }));
    if (!$candidatos) return null;
    $tipo = (string) $p['tipo'];
    $tema = trim((string) ($p['tema'] ?? ''));
    $peso = pesoPqrsd($p);
    $motivos = [];

    $del_tema = $tema === '' ? [] : array_values(array_filter($candidatos, function ($r) use ($tema) { return in_array($tema, $r['temas'], true); }));
    $del_tipo = array_values(array_filter($candidatos, function ($r) use ($tipo) { return in_array($tipo, $r['tipos'], true); }));
    if ($del_tema) {
        $candidatos = $del_tema;
        $motivos[] = "atiende «{$tema}»";
    } elseif ($del_tipo) {
        $candidatos = $del_tipo;
        $motivos[] = "atiende «{$tipo}»" . ($tema !== '' ? " (nadie tiene «{$tema}»)" : '');
    } else {
        $generales = array_values(array_filter($candidatos, function ($r) { return !$r['tipos'] && !$r['temas']; }));
        if ($generales) $candidatos = $generales;
        $motivos[] = $generales ? 'atiende de todo' : 'nadie tiene asignado ese tema ni ese tipo';
    }
    $carga = function ($r) use ($cargas) { return (float) ($cargas[$r['id']]['carga'] ?? 0); };
    $abiertas = function ($r) use ($cargas) { return (int) ($cargas[$r['id']]['abiertas'] ?? 0); };
    $con_cupo = array_values(array_filter($candidatos, function ($r) use ($carga, $peso) { return $carga($r) + $peso <= $r['capacidad']; }));
    if ($con_cupo) $candidatos = $con_cupo;
    usort($candidatos, function ($a, $b) use ($carga, $abiertas) {
        return [$carga($a) / $a['capacidad'], $abiertas($a), $a['id']] <=> [$carga($b) / $b['capacidad'], $abiertas($b), $b['id']];
    });
    $elegido = $candidatos[0];
    if (!empty($p['prioritaria'])) $motivos[] = 'atención prioritaria (art. 20)';
    $motivos[] = 'carga ' . rtrim(rtrim(number_format($carga($elegido), 1, ',', ''), '0'), ',') . ' de ' . $elegido['capacidad']
               . ($con_cupo ? ', la menor del grupo' : ', todos están a tope');
    return [$elegido, implode(' · ', $motivos)];
}

/**
 * Asigna (o reasigna) una solicitud automáticamente. $contexto = [responsables, cargas] permite repartir
 * varias en una pasada con las cargas al día. Devuelve el responsable elegido o null.
 */
function asignarPqrsdAutomaticamente(PDO $db, $id, array &$contexto = null, $forzar = false) {
    $cfg = configPqrsd($db);
    if (!$forzar && ($cfg['asignacion_automatica'] ?? '1') !== '1') return null;
    $p = pqrsdPorId($db, $id);
    if (!$p || !pqrsdEnTramite($p)) return null;
    if ($contexto === null) $contexto = [responsablesPqrsd($db), cargasResponsablesPqrsd($db)];
    $anterior = (int) $p['asignado_a'];
    $eleccion = elegirResponsablePqrsd($contexto[0], $contexto[1], $p, $anterior);
    if (!$eleccion) return null;
    list($elegido, $motivo) = $eleccion;

    $campos = ['asignado_a' => $elegido['id']];
    if ($p['estado'] === 'Radicada') $campos['estado'] = 'En trámite';
    actualizarPqrsd($db, $id, $campos);
    registrarEventoPqrsd($db, $id, null, 'Asignación automática', 'Asignada a ' . $elegido['nombre'] . ': ' . $motivo . '.',
        isset($campos['estado']) ? $p['estado'] : null, $campos['estado'] ?? null, true);

    $peso = pesoPqrsd($p);
    foreach ([[$elegido['id'], 1], [$anterior, -1]] as $movimiento) {
        list($quien, $signo) = $movimiento;
        if (!$quien) continue;
        if (!isset($contexto[1][$quien])) $contexto[1][$quien] = ['carga' => 0.0, 'abiertas' => 0];
        $contexto[1][$quien]['carga'] = max(0, $contexto[1][$quien]['carga'] + $signo * $peso);
        $contexto[1][$quien]['abiertas'] = max(0, $contexto[1][$quien]['abiertas'] + $signo);
    }
    return $elegido;
}

/**
 * Le avisa por correo a quien recibió la solicitud, con el término y el enlace a la ficha.
 * Solo funciona si quien llama ya cargó las utilidades de correo (el canal público y el panel).
 */
function avisarResponsablePqrsd(PDO $db, $id, array $responsable) {
    if (!function_exists('nuevoCorreo') || !filter_var((string) ($responsable['correo'] ?? ''), FILTER_VALIDATE_EMAIL)) return false;
    $p = pqrsdPorId($db, $id);
    if (!$p) return false;
    $termino = textoTerminoPqrsd($p);
    try {
        $mail = nuevoCorreo('Canal PQRSD - DataSense');
        $mail->addAddress($responsable['correo'], (string) $responsable['nombre']);
        $mail->isHTML(true);
        $mail->Subject = '[' . $p['radicado'] . '] Te asignaron una ' . mb_strtolower($p['tipo'], 'UTF-8') . ' · ' . $termino['etiqueta'];
        $mail->Body = '<p>Hola ' . esc($responsable['nombre']) . ',</p>'
            . '<p>Te asignamos la solicitud <strong>' . esc($p['radicado']) . '</strong> (' . esc($p['tipo']) . ')'
            . (!empty($p['prioritaria']) ? ' con <strong>atención prioritaria</strong> (Ley 1755 de 2015, art. 20)' : '') . '.</p>'
            . '<p><strong>Asunto:</strong> ' . esc($p['asunto']) . '<br><strong>' . esc($termino['texto']) . '</strong></p>'
            . '<div style="background:#f8fafc;padding:14px;border-left:4px solid #0A2E4F;line-height:1.6">'
            . nl2br(esc(mb_strimwidth((string) $p['descripcion'], 0, 600, '…', 'UTF-8'))) . '</div>'
            . '<p>Ábrela en el panel: ' . esc(urlBase()) . 'admin/pqrsd.php?abrir=' . (int) $p['id'] . '</p>';
        $mail->send();
        registrarEventoPqrsd($db, $id, null, 'Aviso al responsable',
            'Se le avisó a ' . $responsable['nombre'] . ' al correo ' . $responsable['correo'] . '.', null, null, false);
        return true;
    } catch (Exception $e) {
        error_log('PQRSD, aviso al responsable: ' . $e->getMessage());
        return false;
    }
}

// Reparte las que quedaron sin responsable. Devuelve cuántas se asignaron.
function repartirPqrsdPendientes(PDO $db, $forzar = false) {
    $cfg = configPqrsd($db);
    if (!$forzar && ($cfg['asignacion_automatica'] ?? '1') !== '1') return 0;
    $pendientes = $db->query("SELECT id FROM pqrsd WHERE asignado_a IS NULL AND estado IN ('Radicada', 'En trámite')")->fetchAll(PDO::FETCH_ASSOC);
    $contexto = null;
    $n = 0;
    foreach ($pendientes as $f) {
        if (asignarPqrsdAutomaticamente($db, $f['id'], $contexto, $forzar)) $n++;
    }
    return $n;
}

/**
 * Desempeño de cada persona en el canal ciudadano dentro del periodo: cuántas atendió, cuántas
 * respondió dentro del término, los días hábiles que tardó en promedio y si gana la bonificación.
 */
function desempenoPqrsd(PDO $db, $desde, $hasta) {
    $cfg = configPqrsd($db);
    $inicio = $desde . ' 00:00:00';
    $fin = $hasta . ' 23:59:59';
    $base = function ($id, $nombre, $rol) {
        return ['id' => (int) $id, 'nombre' => $nombre, 'rol' => $rol, 'abiertas' => 0, 'vencidas' => 0, 'esperando' => 0,
                'respondidas' => 0, 'a_tiempo' => 0, 'prorrogadas' => 0, 'trasladadas' => 0, 'prioritarias' => 0, 'dias_total' => 0];
    };
    $filas = [];
    foreach (responsablesPqrsd($db) as $r) $filas[$r['id']] = $base($r['id'], $r['nombre'], $r['rol']);

    $columnas = 'p.asignado_a, p.estado, p.fecha_limite, p.fecha_registro, p.fecha_respuesta, p.fecha_cierre, u.nombre AS nom, u.rol AS rol';
    $sql = "SELECT $columnas, p.prorroga_hasta, p.prioritaria FROM pqrsd p LEFT JOIN usuarios u ON u.id = p.asignado_a WHERE p.asignado_a IS NOT NULL";
    try {
        $solicitudes = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $solicitudes = $db->query("SELECT $columnas FROM pqrsd p LEFT JOIN usuarios u ON u.id = p.asignado_a WHERE p.asignado_a IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($solicitudes as $p) {
        $id = (int) $p['asignado_a'];
        if (!isset($filas[$id])) $filas[$id] = $base($id, $p['nom'] ?: "Usuario $id", (string) $p['rol']);
        $limite = !empty($p['prorroga_hasta']) ? $p['prorroga_hasta'] : $p['fecha_limite'];
        $fin_real = $p['fecha_respuesta'] ?: $p['fecha_cierre'];

        // Lo que tiene encima hoy
        if (pqrsdEnTramite($p)) {
            if ($p['estado'] === 'En espera del ciudadano') $filas[$id]['esperando']++;
            else {
                $filas[$id]['abiertas']++;
                if (diasHabilesRestantesPqrsd($limite) < 0) $filas[$id]['vencidas']++;
            }
            if (!empty($p['prioritaria'])) $filas[$id]['prioritarias']++;
        }
        // Lo que cerró dentro del periodo
        if ($p['estado'] === 'Trasladada' && !empty($p['fecha_cierre']) && $p['fecha_cierre'] >= $inicio && $p['fecha_cierre'] <= $fin) {
            $filas[$id]['trasladadas']++;
        }
        if (in_array($p['estado'], ['Respondida', 'Cerrada'], true) && $fin_real && $fin_real >= $inicio && $fin_real <= $fin) {
            $filas[$id]['respondidas']++;
            if (strtotime($fin_real) <= strtotime($limite)) $filas[$id]['a_tiempo']++;
            if (!empty($p['prorroga_hasta'])) $filas[$id]['prorrogadas']++;
            // Días hábiles que tardó: de la radicación a la respuesta
            $filas[$id]['dias_total'] += max(0, diasHabilesRestantesPqrsd($fin_real, $p['fecha_registro']));
        }
    }

    $bono = (float) $cfg['bono_cumplimiento'];
    $alerta = (float) $cfg['alerta_cumplimiento'];
    $minimo = (int) $cfg['minimo_para_medir'];
    foreach ($filas as &$f) {
        $f['cumplimiento'] = $f['respondidas'] ? round($f['a_tiempo'] * 100 / $f['respondidas'], 1) : null;
        $f['dias_promedio'] = $f['respondidas'] ? round($f['dias_total'] / $f['respondidas'], 1) : null;
        $f['carga'] = $f['abiertas'] + $f['esperando'];
        if ($f['respondidas'] < $minimo) $f['calificacion'] = 'sin_datos';
        elseif ($f['cumplimiento'] >= $bono) $f['calificacion'] = 'bonificacion';
        elseif ($f['cumplimiento'] < $alerta) $f['calificacion'] = 'plan_mejora';
        else $f['calificacion'] = 'cumple';
    }
    unset($f);
    usort($filas, function ($a, $b) {
        return [$b['cumplimiento'] ?? -1, $b['respondidas']] <=> [$a['cumplimiento'] ?? -1, $a['respondidas']];
    });
    return $filas;
}

/**
 * Informe del canal ciudadano en el periodo: volumen por tipo, cumplimiento de los términos de ley,
 * actuaciones (prórrogas, requerimientos, traslados, desistimientos), incumplimientos uno por uno y
 * tendencia de los últimos 6 meses. Es el insumo del informe de PQRSD que se presenta a la dirección.
 */
function reportePqrsd(PDO $db, $desde, $hasta) {
    $inicio = $desde . ' 00:00:00';
    $fin = $hasta . ' 23:59:59';
    $vacio = function () {
        return ['radicadas' => 0, 'respondidas' => 0, 'a_tiempo' => 0, 'dias_total' => 0, 'abiertas' => 0, 'vencidas' => 0];
    };
    $r = ['kpi' => $vacio() + ['por_vencer' => 0, 'esperando' => 0, 'prorrogadas' => 0, 'requeridas' => 0, 'desistidas' => 0,
                              'trasladadas' => 0, 'prioritarias' => 0, 'anonimas' => 0, 'con_adjunto' => 0],
          'por_tipo' => [], 'por_tema' => [], 'por_estado' => [], 'por_responsable' => [], 'por_medio' => [], 'incumplimientos' => [], 'tendencia' => []];
    foreach (array_keys(TIPOS_PQRSD) as $t) $r['por_tipo'][$t] = $vacio();
    foreach (array_merge(array_keys(TEMAS_PQRSD), [TEMA_PQRSD_OTROS]) as $t) $r['por_tema'][$t] = $vacio();
    foreach (ESTADOS_PQRSD as $e) $r['por_estado'][$e] = 0;

    $columnas = 'p.*, u.nombre AS nombre_asignado';
    try {
        $solicitudes = $db->query("SELECT $columnas FROM pqrsd p LEFT JOIN usuarios u ON u.id = p.asignado_a")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $r;
    }
    $sumar = function (array &$grupo, $clave, $campo, $cuanto = 1) use ($vacio) {
        if ($clave === '') $clave = 'Sin dato';
        if (!isset($grupo[$clave])) $grupo[$clave] = $vacio();
        $grupo[$clave][$campo] += $cuanto;
    };

    foreach ($solicitudes as $p) {
        $limite = fechaLimitePqrsd($p);
        $fin_real = $p['fecha_respuesta'] ?: $p['fecha_cierre'];
        $en_periodo = function ($fecha) use ($inicio, $fin) { return !empty($fecha) && $fecha >= $inicio && $fecha <= $fin; };
        $responsable = (string) ($p['nombre_asignado'] ?: 'Sin responsable');
        $medio = MEDIOS_RESPUESTA_PQRSD[$p['medio_respuesta']] ?? (string) $p['medio_respuesta'];
        $tema = trim((string) ($p['tema'] ?? '')) ?: TEMA_PQRSD_OTROS;

        // Lo que entró en el periodo
        if ($en_periodo($p['fecha_registro'])) {
            $r['kpi']['radicadas']++;
            $sumar($r['por_tipo'], $p['tipo'], 'radicadas');
            $sumar($r['por_tema'], $tema, 'radicadas');
            $sumar($r['por_responsable'], $responsable, 'radicadas');
            $sumar($r['por_medio'], $medio, 'radicadas');
            if (!empty($p['prioritaria'])) $r['kpi']['prioritarias']++;
            if (!empty($p['anonima'])) $r['kpi']['anonimas']++;
            if (!empty($p['adjunto'])) $r['kpi']['con_adjunto']++;
        }
        // Lo que se respondió en el periodo
        if (in_array($p['estado'], ['Respondida', 'Cerrada'], true) && $en_periodo($fin_real)) {
            $a_tiempo = strtotime($fin_real) <= strtotime($limite);
            $dias = max(0, diasHabilesRestantesPqrsd($fin_real, $p['fecha_registro']));
            foreach ([[&$r['kpi'], null]] as $nada) { /* el KPI se suma aparte para no repetir el arreglo */ }
            $r['kpi']['respondidas']++;
            $r['kpi']['dias_total'] += $dias;
            if ($a_tiempo) $r['kpi']['a_tiempo']++;
            $sumar($r['por_tipo'], $p['tipo'], 'respondidas');
            $sumar($r['por_tipo'], $p['tipo'], 'dias_total', $dias);
            $sumar($r['por_tema'], $tema, 'respondidas');
            $sumar($r['por_tema'], $tema, 'dias_total', $dias);
            $sumar($r['por_responsable'], $responsable, 'respondidas');
            $sumar($r['por_responsable'], $responsable, 'dias_total', $dias);
            $sumar($r['por_medio'], $medio, 'respondidas');
            if ($a_tiempo) {
                $sumar($r['por_tipo'], $p['tipo'], 'a_tiempo');
                $sumar($r['por_tema'], $tema, 'a_tiempo');
                $sumar($r['por_responsable'], $responsable, 'a_tiempo');
                $sumar($r['por_medio'], $medio, 'a_tiempo');
            } else {
                $r['incumplimientos'][] = ['radicado' => $p['radicado'], 'tipo' => $p['tipo'], 'estado' => $p['estado'],
                                           'responsable' => $responsable, 'termino' => date('d/m/Y', strtotime($limite)),
                                           'fecha' => date('d/m/Y', strtotime($fin_real)),
                                           'exceso' => abs(diasHabilesRestantesPqrsd($limite, $fin_real)),
                                           'motivo' => 'Respondida fuera del término'];
            }
        }
        // Actuaciones de ley hechas en el periodo
        if ($en_periodo($p['fecha_prorroga'] ?? null)) $r['kpi']['prorrogadas']++;
        if ($en_periodo($p['fecha_requerimiento'] ?? null)) $r['kpi']['requeridas']++;
        if ($en_periodo($p['fecha_traslado'] ?? null)) $r['kpi']['trasladadas']++;
        if ($p['estado'] === 'Desistida' && $en_periodo($p['fecha_cierre'])) $r['kpi']['desistidas']++;

        // Foto de hoy: lo que sigue en trámite
        $r['por_estado'][$p['estado']] = ($r['por_estado'][$p['estado']] ?? 0) + 1;
        if (pqrsdEnTramite($p)) {
            if ($p['estado'] === 'En espera del ciudadano') {
                $r['kpi']['esperando']++;
            } else {
                $restantes = diasHabilesRestantesPqrsd($limite);
                $r['kpi']['abiertas']++;
                $sumar($r['por_tipo'], $p['tipo'], 'abiertas');
                $sumar($r['por_tema'], $tema, 'abiertas');
                $sumar($r['por_responsable'], $responsable, 'abiertas');
                if ($restantes < 0) {
                    $r['kpi']['vencidas']++;
                    $sumar($r['por_tipo'], $p['tipo'], 'vencidas');
                    $sumar($r['por_responsable'], $responsable, 'vencidas');
                    $r['incumplimientos'][] = ['radicado' => $p['radicado'], 'tipo' => $p['tipo'], 'estado' => $p['estado'],
                                               'responsable' => $responsable, 'termino' => date('d/m/Y', strtotime($limite)),
                                               'fecha' => '—', 'exceso' => abs($restantes), 'motivo' => 'Sin responder, término vencido'];
                } elseif ($restantes <= 3) {
                    $r['kpi']['por_vencer']++;
                }
            }
        }
    }

    // Promedios y porcentajes
    $cerrar = function (array &$grupo) {
        foreach ($grupo as $clave => $g) {
            $grupo[$clave]['cumplimiento'] = $g['respondidas'] ? round($g['a_tiempo'] * 100 / $g['respondidas'], 1) : null;
            $grupo[$clave]['dias_promedio'] = $g['respondidas'] ? round($g['dias_total'] / $g['respondidas'], 1) : null;
        }
    };
    $r['kpi']['cumplimiento'] = $r['kpi']['respondidas'] ? round($r['kpi']['a_tiempo'] * 100 / $r['kpi']['respondidas'], 1) : null;
    $r['kpi']['dias_promedio'] = $r['kpi']['respondidas'] ? round($r['kpi']['dias_total'] / $r['kpi']['respondidas'], 1) : null;
    $cerrar($r['por_tipo']);
    $cerrar($r['por_tema']);
    $cerrar($r['por_responsable']);
    $cerrar($r['por_medio']);
    uasort($r['por_responsable'], function ($a, $b) { return $b['respondidas'] <=> $a['respondidas']; });
    uasort($r['por_medio'], function ($a, $b) { return $b['radicadas'] <=> $a['radicadas']; });
    usort($r['incumplimientos'], function ($a, $b) { return $b['exceso'] <=> $a['exceso']; });

    // Tendencia de los 6 meses que terminan en el mes de corte
    $mes = new DateTime(substr($hasta, 0, 7) . '-01');
    $mes->modify('-5 months');
    for ($i = 0; $i < 6; $i++) {
        $a = $mes->format('Y-m-01 00:00:00');
        $b = $mes->format('Y-m-t 23:59:59');
        $fila = ['mes' => $mes->format('Y-m'), 'radicadas' => 0, 'respondidas' => 0, 'a_tiempo' => 0];
        foreach ($solicitudes as $p) {
            $cierre = $p['fecha_respuesta'] ?: $p['fecha_cierre'];
            if ($p['fecha_registro'] >= $a && $p['fecha_registro'] <= $b) $fila['radicadas']++;
            if (in_array($p['estado'], ['Respondida', 'Cerrada'], true) && $cierre && $cierre >= $a && $cierre <= $b) {
                $fila['respondidas']++;
                if (strtotime($cierre) <= strtotime(fechaLimitePqrsd($p))) $fila['a_tiempo']++;
            }
        }
        $fila['cumplimiento'] = $fila['respondidas'] ? round($fila['a_tiempo'] * 100 / $fila['respondidas'], 1) : null;
        $r['tendencia'][] = $fila;
        $mes->modify('+1 month');
    }
    return $r;
}

const CALIFICACIONES_DESEMPENO = [
    'bonificacion' => ['texto' => 'Bonificación', 'clase' => 'success', 'icono' => 'fa-award'],
    'cumple'       => ['texto' => 'Cumple', 'clase' => 'primary', 'icono' => 'fa-circle-check'],
    'plan_mejora'  => ['texto' => 'Plan de mejora', 'clase' => 'danger', 'icono' => 'fa-triangle-exclamation'],
    'sin_datos'    => ['texto' => 'Sin datos suficientes', 'clase' => 'secondary', 'icono' => 'fa-hourglass-half'],
];
