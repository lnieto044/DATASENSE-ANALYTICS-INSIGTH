<?php
/**
 * MESA DE SERVICIOS (ITSM) - DATASENSE
 * Motor de los acuerdos de nivel de servicio (ANS), alineado con las prácticas de ITIL de gestión de incidentes
 * y de solicitudes de servicio:
 * - Prioridad = matriz impacto x urgencia.
 * - Dos objetivos por tipo (INC incidente, RF requerimiento) y prioridad: primera respuesta y solución, en minutos.
 *   ITIL no fija tiempos: son los que la organización pacta en sus ANS (tabla sla_objetivos, editable).
 * - Calendario hábil (días y horario configurables, sin festivos de Colombia) o 24x7.
 * - Las pausas (en espera del cliente o de un tercero) detienen el reloj de solución, y el tiempo en que el caso
 *   estuvo solucionado antes de una reapertura tampoco cuenta.
 * - Cierre automático de casos solucionados sin respuesta del cliente y alertas de escalamiento por umbral.
 * Las fechas se manejan con la zona horaria de PHP (nunca con NOW() de MySQL).
 */
require_once __DIR__ . '/utilidades.php';
date_default_timezone_set('America/Bogota'); // Los tiempos del ANS se miden en la hora de Colombia

// Comportamientos de los estados (ciclo de vida del caso)
const COMP_ABIERTO     = 'Abierto';      // Registrado o asignado, sin atención todavía
const COMP_PROCESO     = 'En Proceso';   // En atención: corre el reloj
const COMP_PAUSA       = 'Pausa';        // En espera del cliente o de un tercero: reloj detenido
const COMP_SOLUCIONADO = 'Solucionado';  // Resuelto, esperando la confirmación del cliente
const COMP_CERRADO     = 'Cerrado';
const COMPORTAMIENTOS_TICKET = [COMP_ABIERTO, COMP_PROCESO, COMP_PAUSA, COMP_SOLUCIONADO, COMP_CERRADO];

const TIPOS_TICKET = ['INC' => 'Incidente', 'RF' => 'Requerimiento'];
const PRIORIDADES_TICKET = ['Crítica', 'Alta', 'Media', 'Baja'];

// Matriz de prioridad de ITIL: [impacto][urgencia] => prioridad
const MATRIZ_PRIORIDAD = [
    'Alto'  => ['Alta' => 'Crítica', 'Media' => 'Alta',  'Baja' => 'Media'],
    'Medio' => ['Alta' => 'Alta',    'Media' => 'Media', 'Baja' => 'Baja'],
    'Bajo'  => ['Alta' => 'Media',   'Media' => 'Baja',  'Baja' => 'Baja'],
];

// Objetivos por defecto [tipo][prioridad] => [primera respuesta (min), solución (min), calendario]
const SLA_POR_DEFECTO = [
    'INC' => ['Crítica' => [15, 240, 'habil'], 'Alta' => [30, 480, 'habil'], 'Media' => [120, 960, 'habil'], 'Baja' => [240, 2160, 'habil']],
    'RF'  => ['Crítica' => [60, 960, 'habil'], 'Alta' => [120, 1440, 'habil'], 'Media' => [240, 2400, 'habil'], 'Baja' => [480, 4320, 'habil']],
];

const CONFIG_ITSM_POR_DEFECTO = [
    'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'almuerzo_inicio' => '', 'almuerzo_fin' => '', 'dias_habiles' => '1,2,3,4,5', 'festivos_colombia' => '1',
    'umbral_atencion' => '50', 'umbral_riesgo' => '75', 'umbral_critico' => '90',
    'cierre_automatico_dias' => '3', 'reapertura_dias' => '5', 'horas_sin_gestion' => '8', 'meta_cumplimiento' => '95',
    'asignacion_automatica' => '1',
];

const ETIQUETAS_NIVEL_SLA = ['a_tiempo' => 'A tiempo', 'atencion' => 'Atención', 'riesgo' => 'En riesgo', 'critico' => 'Crítico', 'vencido' => 'Vencido'];
const COLORES_NIVEL_SLA = ['a_tiempo' => '#16a34a', 'atencion' => '#ca8a04', 'riesgo' => '#ea580c', 'critico' => '#dc2626', 'vencido' => '#991b1b'];
const PESOS_PRIORIDAD = ['Crítica' => 4, 'Alta' => 3, 'Media' => 2, 'Baja' => 1];

// Etiquetas de la bitácora del caso (soporte_tickets.respuesta_tecnica)
const ETIQUETA_NOTA_PUBLICA = '[VISIBLE CLIENTE]';
const ETIQUETA_NOTA_INTERNA = '[NOTA INTERNA]';
const ETIQUETA_NOTA_CLIENTE = '[CLIENTE]';

// ================================================================
// CONFIGURACIÓN
// ================================================================

function configItsm(PDO $db, $recargar = false) {
    static $cache = null;
    if ($cache === null || $recargar) {
        $cache = CONFIG_ITSM_POR_DEFECTO;
        try {
            foreach ($db->query("SELECT clave, valor FROM itsm_configuracion")->fetchAll(PDO::FETCH_KEY_PAIR) as $clave => $valor) {
                if (array_key_exists($clave, $cache)) $cache[$clave] = (string) $valor;
            }
        } catch (PDOException $e) {
            // Sin la tabla (base sin migrar) se usan los valores por defecto
        }
    }
    return $cache;
}

function objetivosSla(PDO $db, $recargar = false) {
    static $cache = null;
    if ($cache === null || $recargar) {
        $cache = SLA_POR_DEFECTO;
        try {
            foreach ($db->query("SELECT tipo, prioridad, respuesta_min, resolucion_min, calendario FROM sla_objetivos")->fetchAll(PDO::FETCH_ASSOC) as $o) {
                if (isset($cache[$o['tipo']][$o['prioridad']])) {
                    $cache[$o['tipo']][$o['prioridad']] = [(int) $o['respuesta_min'], (int) $o['resolucion_min'], $o['calendario'] === '24x7' ? '24x7' : 'habil'];
                }
            }
        } catch (PDOException $e) {
        }
    }
    return $cache;
}

function objetivoSla(PDO $db, $tipo, $prioridad) {
    $objetivos = objetivosSla($db);
    $tipo = isset($objetivos[$tipo]) ? $tipo : 'INC';
    return $objetivos[$tipo][in_array($prioridad, PRIORIDADES_TICKET, true) ? $prioridad : 'Media'];
}

function minutosDelDia($hora) {
    $partes = explode(':', (string) $hora);
    return (int) $partes[0] * 60 + (int) ($partes[1] ?? 0);
}

// Tramos de trabajo de un día en minutos desde medianoche, descontando el almuerzo si está configurado
function tramosJornada(array $cfg) {
    $inicio = minutosDelDia($cfg['hora_inicio']);
    $fin = minutosDelDia($cfg['hora_fin']);
    if ($fin <= $inicio) return [];
    if (preg_match('/^\d{1,2}:\d{2}$/', $cfg['almuerzo_inicio']) && preg_match('/^\d{1,2}:\d{2}$/', $cfg['almuerzo_fin'])) {
        $a = max($inicio, minutosDelDia($cfg['almuerzo_inicio']));
        $b = min($fin, minutosDelDia($cfg['almuerzo_fin']));
        if ($b > $a) return array_values(array_filter([[$inicio, $a], [$b, $fin]], function ($t) { return $t[1] > $t[0]; }));
    }
    return [[$inicio, $fin]];
}

function minutosJornada(array $cfg) {
    $total = 0;
    foreach (tramosJornada($cfg) as $tramo) $total += $tramo[1] - $tramo[0];
    return $total;
}

// Duración expresada como la entiende un cliente: "8 h", "1 día hábil y 7 h", "4 días hábiles" (con la jornada configurada)
function duracionHabilTexto($minutos, array $cfg, $calendario = 'habil') {
    $minutos = (int) round($minutos);
    $jornada = $calendario === '24x7' ? 1440 : minutosJornada($cfg);
    if ($jornada <= 0 || $minutos < $jornada) return duracionTexto($minutos);
    $dias = intdiv($minutos, $jornada);
    $resto = $minutos - $dias * $jornada;
    $unidad = $calendario === '24x7' ? ($dias === 1 ? 'día' : 'días') : ($dias === 1 ? 'día hábil' : 'días hábiles');
    return "$dias $unidad" . ($resto ? ' y ' . duracionTexto($resto) : '');
}

// "lunes a viernes de 08:00 a 17:00, sin festivos de Colombia"
function textoHorarioItsm(array $cfg) {
    $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    $dias = array_values(array_filter(array_map('intval', explode(',', $cfg['dias_habiles']))));
    if (!$dias) return 'sin días hábiles configurados';
    $ultimo = $dias[count($dias) - 1];
    $texto = count($dias) > 1 && $dias === range($dias[0], $ultimo)
        ? $nombres[$dias[0]] . ' a ' . $nombres[$ultimo]
        : implode(', ', array_map(function ($d) use ($nombres) { return $nombres[$d] ?? (string) $d; }, $dias));
    $texto .= " de {$cfg['hora_inicio']} a {$cfg['hora_fin']}";
    if ($cfg['almuerzo_inicio'] !== '' && $cfg['almuerzo_fin'] !== '') $texto .= " (almuerzo de {$cfg['almuerzo_inicio']} a {$cfg['almuerzo_fin']})";
    return $texto . ($cfg['festivos_colombia'] === '1' ? ', sin festivos de Colombia' : '');
}

// ================================================================
// CALENDARIO: FESTIVOS DE COLOMBIA Y MINUTOS HÁBILES
// ================================================================

// Domingo de Pascua (algoritmo de Meeus/Jones/Butcher, calendario gregoriano)
function pascua($anio) {
    $a = $anio % 19; $b = intdiv($anio, 100); $c = $anio % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $n = $h + $l - 7 * $m + 114;
    return sprintf('%04d-%02d-%02d', $anio, intdiv($n, 31), ($n % 31) + 1);
}

// Festivos de Colombia [fecha => nombre]: fijos, trasladables al lunes (Ley 51 de 1983) y los que dependen de la Pascua
function festivosColombia($anio) {
    static $cache = [];
    $anio = (int) $anio;
    if (isset($cache[$anio])) return $cache[$anio];
    $lunes = function ($fecha) {
        $dia = new DateTime($fecha);
        if ((int) $dia->format('N') !== 1) $dia->modify('next monday');
        return $dia->format('Y-m-d');
    };
    $festivos = [];
    foreach (['01-01' => 'Año Nuevo', '05-01' => 'Día del Trabajo', '07-20' => 'Independencia de Colombia', '08-07' => 'Batalla de Boyacá',
              '12-08' => 'Inmaculada Concepción', '12-25' => 'Navidad'] as $mes_dia => $nombre) {
        $festivos["$anio-$mes_dia"] = $nombre;
    }
    foreach (['01-06' => 'Reyes Magos', '03-19' => 'San José', '06-29' => 'San Pedro y San Pablo', '08-15' => 'Asunción de la Virgen',
              '10-12' => 'Día de la Raza', '11-01' => 'Todos los Santos', '11-11' => 'Independencia de Cartagena'] as $mes_dia => $nombre) {
        $festivos[$lunes("$anio-$mes_dia")] = $nombre;
    }
    $domingo = new DateTime(pascua($anio));
    foreach ([-3 => 'Jueves Santo', -2 => 'Viernes Santo', 43 => 'Ascensión del Señor', 64 => 'Corpus Christi', 71 => 'Sagrado Corazón'] as $dias => $nombre) {
        $dia = clone $domingo;
        $dia->modify(($dias >= 0 ? '+' : '') . $dias . ' days');
        $festivos[$dia->format('Y-m-d')] = $nombre;
    }
    ksort($festivos);
    return $cache[$anio] = $festivos;
}

function fechaSla($valor) {
    return $valor instanceof DateTime ? clone $valor : new DateTime((string) $valor);
}

// Tramos hábiles de un día [[inicio, fin] en timestamps], o [] si el día no es hábil (fin de semana o festivo)
function jornadaDelDia(DateTime $dia, array $cfg) {
    $fecha = $dia->format('Y-m-d');
    if (!in_array((int) $dia->format('N'), array_map('intval', explode(',', $cfg['dias_habiles'])), true)) return [];
    if ($cfg['festivos_colombia'] === '1' && isset(festivosColombia((int) $dia->format('Y'))[$fecha])) return [];
    $medianoche = new DateTime("$fecha 00:00:00");
    $tramos = [];
    foreach (tramosJornada($cfg) as $tramo) {
        $inicio = clone $medianoche;
        $fin = clone $medianoche;
        $tramos[] = [$inicio->modify("+{$tramo[0]} minutes")->getTimestamp(), $fin->modify("+{$tramo[1]} minutes")->getTimestamp()];
    }
    return $tramos;
}

// Minutos que cuentan para el ANS entre dos momentos (calendario 'habil' o '24x7')
function minutosHabiles($desde, $hasta, $calendario, array $cfg) {
    $desde = fechaSla($desde);
    $hasta = fechaSla($hasta);
    if ($hasta <= $desde) return 0;
    if ($calendario === '24x7') return intdiv($hasta->getTimestamp() - $desde->getTimestamp(), 60);
    $segundos = 0;
    $dia = new DateTime($desde->format('Y-m-d'));
    $ultimo = $hasta->format('Y-m-d');
    while ($dia->format('Y-m-d') <= $ultimo) {
        foreach (jornadaDelDia($dia, $cfg) as $tramo) {
            $inicio = max($desde->getTimestamp(), $tramo[0]);
            $fin = min($hasta->getTimestamp(), $tramo[1]);
            if ($fin > $inicio) $segundos += $fin - $inicio;
        }
        $dia->modify('+1 day');
    }
    return intdiv($segundos, 60);
}

// Momento en que se cumplen $minutos del ANS contados desde $desde
function sumarMinutosHabiles($desde, $minutos, $calendario, array $cfg) {
    $fecha = fechaSla($desde);
    $segundos = max(0, (int) round($minutos * 60));
    if ($calendario === '24x7') {
        $fecha->modify("+$segundos seconds");
        return $fecha;
    }
    $dia = new DateTime($fecha->format('Y-m-d'));
    for ($i = 0; $i < 3700; $i++) { // Tope de 10 años
        foreach (jornadaDelDia($dia, $cfg) as $tramo) {
            $inicio = max($fecha->getTimestamp(), $tramo[0]);
            $disponible = $tramo[1] - $inicio;
            if ($disponible <= 0) continue;
            if ($segundos <= $disponible) {
                $resultado = new DateTime();
                $resultado->setTimestamp($inicio + $segundos);
                return $resultado;
            }
            $segundos -= $disponible;
        }
        $dia->modify('+1 day');
    }
    return $dia;
}

function enHorarioSla($momento, $calendario, array $cfg) {
    if ($calendario === '24x7') return true;
    $momento = fechaSla($momento)->getTimestamp();
    foreach (jornadaDelDia(new DateTime(date('Y-m-d', $momento)), $cfg) as $tramo) {
        if ($momento >= $tramo[0] && $momento < $tramo[1]) return true;
    }
    return false;
}

// Momento en que vuelve a correr el reloj hábil: el mismo momento si ya está en la jornada, si no el inicio de la siguiente
function proximoInicioHabil($momento, array $cfg) {
    return sumarMinutosHabiles($momento, 0, 'habil', $cfg);
}

// Días que el reloj hábil no cuenta entre dos fechas [fecha => motivo]: festivos de Colombia y días no laborales
function diasNoHabilesEntre($desde, $hasta, array $cfg) {
    $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    $dias = [];
    $dia = new DateTime(fechaSla($desde)->format('Y-m-d'));
    $ultimo = fechaSla($hasta)->format('Y-m-d');
    while ($dia->format('Y-m-d') <= $ultimo && count($dias) < 120) {
        if (!jornadaDelDia($dia, $cfg)) {
            $fecha = $dia->format('Y-m-d');
            $festivo = $cfg['festivos_colombia'] === '1' ? (festivosColombia((int) $dia->format('Y'))[$fecha] ?? null) : null;
            $dias[$fecha] = $festivo ? "Festivo: $festivo" : 'No laboral (' . $nombres[(int) $dia->format('N')] . ')';
        }
        $dia->modify('+1 day');
    }
    return $dias;
}

function duracionTexto($minutos) {
    $minutos = (int) round($minutos);
    $signo = $minutos < 0 ? '-' : '';
    $minutos = abs($minutos);
    $horas = intdiv($minutos, 60);
    $resto = $minutos % 60;
    if ($horas === 0) return "$signo$resto min";
    return $signo . $horas . ' h' . ($resto ? " $resto min" : '');
}

// ================================================================
// CLASIFICACIÓN Y ESTADOS
// ================================================================

function prioridadDe($impacto, $urgencia) {
    return MATRIZ_PRIORIDAD[$impacto][$urgencia] ?? 'Media';
}

function tipoTicket(array $t) {
    if (!empty($t['tipo']) && isset(TIPOS_TICKET[$t['tipo']])) return $t['tipo'];
    return strpos((string) ($t['codigo_ticket'] ?? ''), 'INC-') === 0 ? 'INC' : 'RF';
}

// Comportamiento canónico, también para estados de versiones anteriores ('Activo') o que no están en estados_ticket
function normalizarComportamiento($comportamiento, $nombre = '') {
    $comportamiento = trim((string) $comportamiento);
    if (in_array($comportamiento, COMPORTAMIENTOS_TICKET, true)) return $comportamiento;
    $nombre = mb_strtolower(trim((string) $nombre), 'UTF-8');
    if (in_array($nombre, ['abierto', 'registrado', 'asignado', 'nuevo'], true)) return COMP_ABIERTO;
    if (in_array($nombre, ['resuelto', 'solucionado'], true)) return COMP_SOLUCIONADO;
    if (strpos($nombre, 'cerrado') === 0 || $nombre === 'cancelado') return COMP_CERRADO;
    if (strpos($nombre, 'espera') !== false || strpos($nombre, 'pausa') !== false) return COMP_PAUSA;
    return COMP_PROCESO;
}

function estadosTicket(PDO $db, $recargar = false) {
    static $cache = null;
    if ($cache === null || $recargar) {
        $cache = [];
        foreach ($db->query("SELECT id, nombre, comportamiento, clase_color, activo FROM estados_ticket ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $e['comportamiento'] = normalizarComportamiento($e['comportamiento'], $e['nombre']);
            $cache[] = $e;
        }
    }
    return $cache;
}

function comportamientoEstado(PDO $db, $nombre) {
    foreach (estadosTicket($db) as $e) {
        if (strcasecmp($e['nombre'], (string) $nombre) === 0) return $e['comportamiento'];
    }
    return normalizarComportamiento('', $nombre);
}

// Primer estado activo con ese comportamiento (el que se usa al radicar, reanudar o cerrar automáticamente)
function primerEstado(PDO $db, $comportamiento) {
    foreach (estadosTicket($db) as $e) {
        if ((int) $e['activo'] === 1 && $e['comportamiento'] === $comportamiento) return $e['nombre'];
    }
    return ['Abierto' => 'Abierto', 'En Proceso' => 'En Progreso', 'Pausa' => 'En Espera del Cliente', 'Solucionado' => 'Resuelto', 'Cerrado' => 'Cerrado'][$comportamiento];
}

// Estado del cierre automático: el de "inactividad" si existe (p. ej. "Cerrado - Inactividad"); si no, el primer estado cerrado
function estadoCierreAutomatico(PDO $db) {
    foreach (estadosTicket($db) as $e) {
        if ((int) $e['activo'] === 1 && $e['comportamiento'] === COMP_CERRADO && mb_stripos($e['nombre'], 'inactividad', 0, 'UTF-8') !== false) return $e['nombre'];
    }
    return primerEstado($db, COMP_CERRADO);
}

// ================================================================
// CÁLCULO DEL ANS DE UN CASO
// ================================================================

function medidaSla($objetivo, $consumido, $finalizado, array $cfg) {
    $objetivo = (int) $objetivo;
    $pct = $objetivo > 0 ? round($consumido * 100 / $objetivo, 1) : 0.0;
    if ($pct > 100) $nivel = 'vencido';
    elseif ($pct >= (float) $cfg['umbral_critico']) $nivel = 'critico';
    elseif ($pct >= (float) $cfg['umbral_riesgo']) $nivel = 'riesgo';
    elseif ($pct >= (float) $cfg['umbral_atencion']) $nivel = 'atencion';
    else $nivel = 'a_tiempo';
    return [
        'objetivo' => $objetivo, 'consumido' => (int) $consumido, 'restante' => $objetivo - (int) $consumido, 'pct' => $pct,
        'nivel' => $nivel, 'etiqueta' => ETIQUETAS_NIVEL_SLA[$nivel], 'finalizado' => (bool) $finalizado,
        'cumplido' => $finalizado ? $consumido <= $objetivo : null,
    ];
}

// Nivel de escalamiento alcanzado por el ANS de solución: 0, umbral de riesgo, umbral crítico o 101 (vencido)
function nivelAlertaSla($pct, array $cfg) {
    if ($pct > 100) return 101;
    if ($pct >= (float) $cfg['umbral_critico']) return (int) $cfg['umbral_critico'];
    if ($pct >= (float) $cfg['umbral_riesgo']) return (int) $cfg['umbral_riesgo'];
    return 0;
}

/**
 * ANS de primera respuesta y de solución de un caso en un momento dado.
 * $t: fila con created_at, calendario, sla_respuesta_min, sla_resolucion_min, fecha_primera_respuesta, fecha_resolucion,
 *     fecha_cierre, fecha_pausa, minutos_pausa y comportamiento.
 */
function slaTicket(array $t, array $cfg, $ahora = null) {
    $ahora = $ahora ? fechaSla($ahora) : new DateTime();
    $calendario = ($t['calendario'] ?? '') === '24x7' ? '24x7' : 'habil';
    $creado = fechaSla($t['created_at']);
    $comportamiento = $t['comportamiento'] ?? COMP_PROCESO;
    $finalizado = in_array($comportamiento, [COMP_SOLUCIONADO, COMP_CERRADO], true);

    // Primera respuesta: el reloj corre desde el registro hasta la primera atención (no se pausa)
    $fin_respuesta = $t['fecha_primera_respuesta'] ?? null;
    if (!$fin_respuesta && $finalizado) $fin_respuesta = $t['fecha_resolucion'] ?? ($t['fecha_cierre'] ?? null);
    $respuesta = medidaSla($t['sla_respuesta_min'], minutosHabiles($creado, $fin_respuesta ?: $ahora, $calendario, $cfg), (bool) $fin_respuesta, $cfg);
    $respuesta['vence'] = sumarMinutosHabiles($creado, (int) $t['sla_respuesta_min'], $calendario, $cfg)->format('Y-m-d H:i:s');
    $respuesta['fecha'] = $fin_respuesta ? (string) $fin_respuesta : null;

    // Solución: desde el registro hasta la resolución, sin el tiempo en pausa ni el que estuvo solucionado antes de reabrirse
    $fin_solucion = ($t['fecha_resolucion'] ?? null) ?: (($comportamiento === COMP_CERRADO ? ($t['fecha_cierre'] ?? null) : null) ?: $ahora);
    $pausa = (int) ($t['minutos_pausa'] ?? 0);
    $pausa_actual = (!$finalizado && !empty($t['fecha_pausa'])) ? minutosHabiles($t['fecha_pausa'], $ahora, $calendario, $cfg) : 0;
    $consumido = max(0, minutosHabiles($creado, $fin_solucion, $calendario, $cfg) - $pausa - $pausa_actual);
    $resolucion = medidaSla($t['sla_resolucion_min'], $consumido, $finalizado, $cfg);
    $resolucion['vence'] = ($pausa_actual > 0 || (!$finalizado && !empty($t['fecha_pausa'])))
        ? null
        : sumarMinutosHabiles($creado, (int) $t['sla_resolucion_min'] + $pausa, $calendario, $cfg)->format('Y-m-d H:i:s');
    $resolucion['minutos_pausa'] = $pausa + $pausa_actual;
    $resolucion['fecha'] = $finalizado ? (($t['fecha_resolucion'] ?? null) ?: ($t['fecha_cierre'] ?? null)) : null;

    if ($finalizado) $reloj = 'finalizado';
    elseif (!empty($t['fecha_pausa'])) $reloj = 'detenido';
    elseif (!enHorarioSla($ahora, $calendario, $cfg)) $reloj = 'fuera_horario';
    else $reloj = 'corriendo';

    return [
        'calendario' => $calendario, 'respuesta' => $respuesta, 'resolucion' => $resolucion, 'reloj' => $reloj, 'calculado' => $ahora->format('Y-m-d H:i:s'),
        // Fuera del horario de atención: cuándo vuelve a correr el reloj
        'reanuda' => $reloj === 'fuera_horario' ? proximoInicioHabil($ahora, $cfg)->format('Y-m-d H:i:s') : null,
        // Casos radicados fuera del horario: el conteo empieza al inicio de la siguiente jornada
        'inicio_conteo' => $calendario === 'habil' ? proximoInicioHabil($creado, $cfg)->format('Y-m-d H:i:s') : $creado->format('Y-m-d H:i:s'),
    ];
}

/**
 * Campos que cambian al pasar el caso de un comportamiento a otro: pausas, primera respuesta, resolución, cierre y reapertura.
 * Devuelve [campos, eventos] (eventos: [[evento, detalle], ...]).
 */
function transicionTicket(array $t, $antes, $despues, $ahora, array $cfg) {
    $calendario = ($t['calendario'] ?? '') === '24x7' ? '24x7' : 'habil';
    $campos = [];
    $eventos = [];
    if ($antes === $despues) return [$campos, $eventos];
    $resuelto = [COMP_SOLUCIONADO, COMP_CERRADO];

    if ($antes === COMP_PAUSA && !empty($t['fecha_pausa'])) {
        $minutos = minutosHabiles($t['fecha_pausa'], $ahora, $calendario, $cfg);
        $campos['minutos_pausa'] = (int) ($t['minutos_pausa'] ?? 0) + $minutos;
        $campos['fecha_pausa'] = null;
        $eventos[] = ['Reanudación', 'El reloj del ANS se reanudó después de ' . duracionTexto($minutos) . ' en pausa.'];
    }
    if ($despues === COMP_PAUSA) {
        $campos['fecha_pausa'] = $ahora;
        $eventos[] = ['Pausa', 'El reloj del ANS de solución quedó detenido.'];
    }
    if (empty($t['fecha_primera_respuesta']) && $despues !== COMP_ABIERTO) {
        $campos += camposPrimeraRespuesta($t, $ahora, $cfg);
    }
    if (in_array($antes, $resuelto, true) && !in_array($despues, $resuelto, true)) {
        // Reapertura: el tiempo que estuvo solucionado no cuenta contra el ANS
        if (!empty($t['fecha_resolucion'])) {
            $campos['minutos_pausa'] = (int) ($campos['minutos_pausa'] ?? ($t['minutos_pausa'] ?? 0)) + minutosHabiles($t['fecha_resolucion'], $ahora, $calendario, $cfg);
        }
        $campos['fecha_resolucion'] = null;
        $campos['fecha_cierre'] = null;
        $campos['sla_resolucion_cumplido'] = null;
        $campos['sla_alerta_nivel'] = 0;
        $campos['reaperturas'] = (int) ($t['reaperturas'] ?? 0) + 1;
        $eventos[] = ['Reapertura', 'El caso se reabrió y el reloj del ANS continúa donde quedó.'];
    }
    if (in_array($despues, $resuelto, true) && !in_array($antes, $resuelto, true)) {
        $campos['fecha_resolucion'] = $ahora;
        $sla = slaTicket(array_merge($t, $campos, ['comportamiento' => $despues]), $cfg, $ahora);
        $campos['sla_resolucion_cumplido'] = $sla['resolucion']['cumplido'] ? 1 : 0;
        $eventos[] = ['Solución', 'Solución registrada en ' . duracionTexto($sla['resolucion']['consumido']) . ' de ' . duracionTexto($sla['resolucion']['objetivo'])
            . ' (' . ($sla['resolucion']['cumplido'] ? 'dentro del ANS' : 'fuera del ANS') . ').'];
    }
    if ($despues === COMP_CERRADO) $campos['fecha_cierre'] = $ahora;
    return [$campos, $eventos];
}

function camposPrimeraRespuesta(array $t, $ahora, array $cfg) {
    $calendario = ($t['calendario'] ?? '') === '24x7' ? '24x7' : 'habil';
    $minutos = minutosHabiles($t['created_at'], $ahora, $calendario, $cfg);
    return ['fecha_primera_respuesta' => $ahora, 'sla_respuesta_cumplido' => $minutos <= (int) $t['sla_respuesta_min'] ? 1 : 0];
}

// Objetivos del ANS según tipo y prioridad, y vencimientos recalculados
function camposObjetivoSla(PDO $db, array $t, $tipo, $prioridad, array $cfg) {
    list($respuesta, $solucion, $calendario) = objetivoSla($db, $tipo, $prioridad);
    $campos = ['tipo' => $tipo, 'prioridad' => $prioridad, 'sla_respuesta_min' => $respuesta, 'sla_resolucion_min' => $solucion, 'calendario' => $calendario];
    return $campos + camposVencimiento(array_merge($t, $campos), $cfg);
}

function camposVencimiento(array $t, array $cfg) {
    $sla = slaTicket($t, $cfg);
    $campos = ['vence_respuesta' => $sla['respuesta']['vence']];
    if ($sla['resolucion']['vence'] !== null) $campos['fecha_vencimiento'] = $sla['resolucion']['vence'];
    return $campos;
}

// El cliente puede reabrir un caso solucionado, o uno cerrado hace menos de "reapertura_dias" días hábiles
function puedeReabrirTicket(array $t, array $cfg, $ahora = null) {
    if ($t['comportamiento'] === COMP_SOLUCIONADO) return true;
    if ($t['comportamiento'] !== COMP_CERRADO || empty($t['fecha_cierre']) || (int) $cfg['reapertura_dias'] <= 0) return false;
    return minutosHabiles($t['fecha_cierre'], $ahora ?: new DateTime(), 'habil', $cfg) < (int) $cfg['reapertura_dias'] * minutosJornada($cfg);
}

// Fecha en que un caso solucionado se cerrará solo si el cliente no responde
function fechaCierreAutomatico(array $t, array $cfg) {
    if ($t['comportamiento'] !== COMP_SOLUCIONADO || empty($t['fecha_resolucion']) || (int) $cfg['cierre_automatico_dias'] <= 0) return null;
    return sumarMinutosHabiles($t['fecha_resolucion'], (int) $cfg['cierre_automatico_dias'] * minutosJornada($cfg), 'habil', $cfg)->format('Y-m-d H:i:s');
}

// ================================================================
// ASIGNACIÓN AUTOMÁTICA POR CARGA, ESPECIALIDAD Y COMPLEJIDAD
// ================================================================

const NIVELES_ESPECIALISTA = [1 => 'N1 · Mesa de ayuda', 2 => 'N2 · Especialista', 3 => 'N3 · Experto'];
const CAPACIDAD_POR_DEFECTO = 20;

// Complejidad del caso de 1 a 4 según su prioridad (impacto × urgencia)
function complejidadTicket(array $t) {
    return PESOS_PRIORIDAD[$t['prioridad'] ?? 'Media'] ?? 2;
}

// Nivel mínimo sugerido: los casos críticos van a un experto (N3) y los de prioridad alta a un especialista (N2)
function nivelRequerido($complejidad) {
    return $complejidad >= 4 ? 3 : ($complejidad >= 3 ? 2 : 1);
}

function formatoCarga($valor) {
    return rtrim(rtrim(number_format((float) $valor, 1, ',', ''), '0'), ',');
}

// Especialistas activos con acceso a Gestión IT y su configuración (nivel, grupos que atienden, capacidad y si reciben casos)
function especialistasItsm(PDO $db) {
    $configuracion = [];
    try {
        foreach ($db->query("SELECT usuario_id, nivel, grupos, capacidad, recibe_casos FROM itsm_especialistas")->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $configuracion[(int) $fila['usuario_id']] = $fila;
        }
    } catch (PDOException $e) {
        // Base sin la migración: todos con la configuración por defecto
    }
    $lista = [];
    foreach ($db->query("SELECT id, nombre, correo, rol, cargo FROM usuarios WHERE estado_cuenta = 'Activo' AND rol <> 'Cliente' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!function_exists('tieneAcceso') || !tieneAcceso($u['rol'], 'gestion_it', $db)) continue;
        $c = $configuracion[(int) $u['id']] ?? null;
        $lista[(int) $u['id']] = [
            'id' => (int) $u['id'], 'nombre' => $u['nombre'], 'correo' => $u['correo'], 'rol' => $u['rol'], 'cargo' => $u['cargo'],
            'nivel' => $c ? max(1, min(3, (int) $c['nivel'])) : 1,
            'grupos' => $c ? array_values(array_filter(array_map('trim', explode('|', (string) $c['grupos'])))) : [],
            'capacidad' => $c ? max(1, (int) $c['capacidad']) : CAPACIDAD_POR_DEFECTO,
            // Sin configuración, reciben casos los usuarios con rol Soporte (el Administrador supervisa)
            'recibe_casos' => $c ? (int) $c['recibe_casos'] === 1 : $u['rol'] === 'Soporte',
        ];
    }
    return $lista;
}

// Carga ponderada de cada especialista: suma de la complejidad de sus casos abiertos (los que esperan al cliente pesan la mitad)
function cargasEspecialistas(PDO $db) {
    $cargas = [];
    foreach ($db->query("SELECT tecnico_id, prioridad, estado FROM soporte_tickets WHERE tecnico_id IS NOT NULL AND fecha_resolucion IS NULL AND fecha_cierre IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $comportamiento = comportamientoEstado($db, $f['estado']);
        if (in_array($comportamiento, [COMP_SOLUCIONADO, COMP_CERRADO], true)) continue;
        $id = (int) $f['tecnico_id'];
        if (!isset($cargas[$id])) $cargas[$id] = ['carga' => 0.0, 'abiertos' => 0];
        $cargas[$id]['carga'] += (PESOS_PRIORIDAD[$f['prioridad']] ?? 2) * ($comportamiento === COMP_PAUSA ? 0.5 : 1);
        $cargas[$id]['abiertos']++;
    }
    return $cargas;
}

/**
 * Elige el especialista de un caso. Reglas, en orden:
 * 1) solo quienes reciben casos (y no el especialista actual, si se está reasignando);
 * 2) especialidad: quienes atienden el grupo resolutor del caso; si nadie, los generalistas (sin grupos); si tampoco, todos;
 * 3) complejidad: entre ellos, quienes tienen el nivel requerido, si hay alguno;
 * 4) capacidad: quienes no superarían su capacidad con este caso, si hay alguno;
 * 5) el de menor carga relativa (carga ÷ capacidad); a igualdad, menos casos abiertos y luego el de menor id.
 * Devuelve [especialista, motivo] o null si nadie recibe casos.
 */
function elegirEspecialista(array $especialistas, array $cargas, array $t, $excluir = 0) {
    $candidatos = array_values(array_filter($especialistas, function ($e) use ($excluir) { return $e['recibe_casos'] && $e['id'] !== (int) $excluir; }));
    if (!$candidatos) return null;
    $grupo = trim((string) ($t['grupo_resolutor'] ?? ''));
    $peso = complejidadTicket($t);
    $requerido = nivelRequerido($peso);
    $motivos = [];

    $minusculas = function ($texto) { return mb_strtolower($texto, 'UTF-8'); };
    $del_grupo = array_values(array_filter($candidatos, function ($e) use ($grupo, $minusculas) {
        return $grupo !== '' && in_array($minusculas($grupo), array_map($minusculas, $e['grupos']), true);
    }));
    if ($del_grupo) {
        $candidatos = $del_grupo;
        $motivos[] = "especialidad «{$grupo}»";
    } else {
        $generalistas = array_values(array_filter($candidatos, function ($e) { return !$e['grupos']; }));
        if ($generalistas) $candidatos = $generalistas;
        $motivos[] = ($generalistas ? 'generalista' : 'sin especialista') . ($grupo !== '' ? " (nadie tiene asignado el grupo «{$grupo}»)" : '');
    }
    $con_nivel = array_values(array_filter($candidatos, function ($e) use ($requerido) { return $e['nivel'] >= $requerido; }));
    if ($con_nivel) $candidatos = $con_nivel;
    $carga = function ($e) use ($cargas) { return (float) ($cargas[$e['id']]['carga'] ?? 0); };
    $abiertos = function ($e) use ($cargas) { return (int) ($cargas[$e['id']]['abiertos'] ?? 0); };
    $con_cupo = array_values(array_filter($candidatos, function ($e) use ($carga, $peso) { return $carga($e) + $peso <= $e['capacidad']; }));
    if ($con_cupo) $candidatos = $con_cupo;
    usort($candidatos, function ($a, $b) use ($carga, $abiertos) {
        return [$carga($a) / $a['capacidad'], $abiertos($a), $a['id']] <=> [$carga($b) / $b['capacidad'], $abiertos($b), $b['id']];
    });
    $elegido = $candidatos[0];
    $motivos[] = "complejidad $peso de 4 ({$t['prioridad']}, pide N$requerido" . ($con_nivel ? '' : '; no hay especialistas de ese nivel disponibles') . ')';
    $motivos[] = 'carga ' . formatoCarga($carga($elegido)) . ' de ' . $elegido['capacidad'] . ($con_cupo ? ', la menor del grupo' : ', todos están a tope de capacidad');
    return [$elegido, implode(' · ', $motivos)];
}

// Estado "Asignado" (si la base lo tiene) para los casos abiertos que reciben especialista
function estadoAsignado(PDO $db) {
    foreach (estadosTicket($db) as $e) {
        if ((int) $e['activo'] === 1 && $e['comportamiento'] === COMP_ABIERTO && mb_stripos($e['nombre'], 'asignad', 0, 'UTF-8') !== false) return $e['nombre'];
    }
    return null;
}

/**
 * Asigna (o reasigna) un caso automáticamente si la opción está activa. $contexto = [especialistas, cargas] permite
 * repartir varios casos en una pasada con las cargas actualizadas. Devuelve el especialista elegido o null.
 */
function asignarAutomaticamente(PDO $db, array $t, array $cfg, $ahora, array &$contexto = null, $motivo_previo = '') {
    if (($cfg['asignacion_automatica'] ?? '1') !== '1') return null;
    if ($contexto === null) $contexto = [especialistasItsm($db), cargasEspecialistas($db)];
    $anterior = (int) ($t['tecnico_id'] ?? 0);
    $eleccion = elegirEspecialista($contexto[0], $contexto[1], $t, $anterior);
    if (!$eleccion) return null;
    list($especialista, $motivo) = $eleccion;

    $campos = ['tecnico_id' => $especialista['id']];
    $estado_asignado = estadoAsignado($db);
    $comportamiento = $t['comportamiento'] ?? COMP_ABIERTO;
    if ($estado_asignado && $comportamiento === COMP_ABIERTO && $t['estado'] !== $estado_asignado) $campos['estado'] = $estado_asignado;
    actualizarCamposTicket($db, $t['id'], $campos);
    registrarEventoTicket($db, $t['id'], null, 'Asignación automática', trim($motivo_previo . ' Asignado a ' . $especialista['nombre'] . ': ' . $motivo . '.'),
        isset($campos['estado']) ? $t['estado'] : null, $campos['estado'] ?? null, true, $ahora);

    $peso = complejidadTicket($t) * ($comportamiento === COMP_PAUSA ? 0.5 : 1);
    foreach ([[$especialista['id'], 1], [$anterior, -1]] as $movimiento) {
        list($id, $signo) = $movimiento;
        if (!$id) continue;
        if (!isset($contexto[1][$id])) $contexto[1][$id] = ['carga' => 0.0, 'abiertos' => 0];
        $contexto[1][$id]['carga'] = max(0, $contexto[1][$id]['carga'] + $signo * $peso);
        $contexto[1][$id]['abiertos'] = max(0, $contexto[1][$id]['abiertos'] + $signo);
    }
    correoAsignacionTicket($db, array_merge($t, $campos, ['nom_tecnico' => $especialista['nombre']]), $especialista['id']);
    return $especialista;
}

// ================================================================
// PERSISTENCIA
// ================================================================

function actualizarCamposTicket(PDO $db, $id, array $campos) {
    if (!$campos) return;
    $asignaciones = [];
    $valores = [];
    foreach ($campos as $columna => $valor) {
        $asignaciones[] = "`$columna` = ?";
        $valores[] = $valor;
    }
    $valores[] = (int) $id;
    $db->prepare("UPDATE soporte_tickets SET " . implode(', ', $asignaciones) . " WHERE id = ?")->execute($valores);
}

function registrarEventoTicket(PDO $db, $ticket_id, $usuario_id, $evento, $detalle = null, $estado_anterior = null, $estado_nuevo = null, $publico = false, $fecha = null) {
    $db->prepare("INSERT INTO ticket_historial (ticket_id, fecha, usuario_id, evento, detalle, estado_anterior, estado_nuevo, publico) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([(int) $ticket_id, $fecha ?: date('Y-m-d H:i:s'), $usuario_id ?: null, mb_substr($evento, 0, 40), $detalle !== null ? mb_substr($detalle, 0, 500) : null,
                  $estado_anterior, $estado_nuevo, $publico ? 1 : 0]);
}

// Eventos por caso [ticket_id => [[fecha, evento, detalle, estado_anterior, estado_nuevo, publico, usuario], ...]]
function historialTickets(PDO $db, array $ids, $solo_publico = false) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $stmt = $db->prepare("SELECT h.ticket_id, h.fecha, h.evento, h.detalle, h.estado_anterior, h.estado_nuevo, h.publico, u.nombre AS usuario
                          FROM ticket_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
                          WHERE h.ticket_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")" . ($solo_publico ? ' AND h.publico = 1' : '') . "
                          ORDER BY h.fecha, h.id");
    $stmt->execute($ids);
    $historial = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $id = (int) $h['ticket_id'];
        unset($h['ticket_id']);
        $h['publico'] = (int) $h['publico'];
        if ($h['usuario'] === null) $h['usuario'] = 'Plataforma';
        $historial[$id][] = $h;
    }
    return $historial;
}

// ================================================================
// FLUJO DEL CASO: QUIÉN HIZO QUÉ, CUÁNDO Y CUÁNTO TIEMPO PASÓ
// ================================================================

// Cómo se resolvió el caso (categoría de cierre de ITIL); obligatorio al solucionar o cerrar desde la mesa
const CODIGOS_CIERRE = [
    'Solución definitiva', 'Solución temporal (mientras se corrige la causa)', 'Configuración o ajuste', 'Error de uso / capacitación al usuario',
    'Falla de un proveedor o tercero', 'Duplicado de otro caso', 'No se pudo reproducir', 'Cancelado por el cliente',
];

// "mié 09/09/2026 01:52"
function diaHoraTexto($fecha) {
    if (empty($fecha)) return '—';
    $dias = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];
    $ts = strtotime($fecha);
    return $dias[(int) date('N', $ts)] . ' ' . date('d/m/Y H:i', $ts);
}

function tipoEvento($evento) {
    $tipos = [
        'Registro' => 'registro', 'Asignación' => 'asignacion', 'Asignación automática' => 'asignacion', 'Escalamiento' => 'asignacion',
        'Reclasificación' => 'clasificacion', 'Cambio de estado' => 'estado', 'Pausa' => 'pausa', 'Reanudación' => 'pausa',
        'Primera respuesta' => 'ans', 'Solución' => 'solucion', 'Reapertura' => 'reapertura', 'Reapertura solicitada' => 'reapertura',
        'Confirmación del cliente' => 'cliente', 'Respuesta del cliente' => 'cliente', 'Cierre automático' => 'cierre', 'Cierre' => 'cierre',
        'Alerta ANS' => 'alerta', 'Nota al cliente' => 'nota', 'Nota interna' => 'nota', 'Causa raíz' => 'nota',
        'Correo al cliente' => 'correo', 'Correo no enviado' => 'alerta', 'Contacto por WhatsApp' => 'contacto', 'Llamada al cliente' => 'contacto',
    ];
    return $tipos[$evento] ?? 'otro';
}

/**
 * Flujo completo de un caso. Para la mesa: todos los eventos con quién los hizo y el tiempo hábil y calendario desde el paso
 * anterior, los hitos del ANS calculados (inicio del conteo, vencimientos, cierre automático), el tiempo en cada estado,
 * los datos clave y la explicación de por qué va a tiempo o se venció. Con $publico (cliente): solo los eventos públicos.
 */
function flujoTicket(PDO $db, array $t, array $cfg, $publico = false, $ahora = null) {
    $ahora = $ahora ?: date('Y-m-d H:i:s');
    $calendario = ($t['calendario'] ?? '') === '24x7' ? '24x7' : 'habil';
    $sla = !empty($t['sla']) ? $t['sla'] : (!empty($t['sla_resolucion_min']) ? slaTicket($t, $cfg, $ahora) : null);
    $historial = historialTickets($db, [(int) $t['id']], $publico)[(int) $t['id']] ?? [];
    $cliente = (string) ($t['cliente'] ?? '');

    $eventos = [];
    foreach ($historial as $h) {
        $tipo = tipoEvento($h['evento']);
        $detalle = (string) $h['detalle'];
        // El cliente ve a quién quedó asignado su caso, sin los criterios internos de carga
        if ($publico && $tipo === 'asignacion' && preg_match('/ a ([^:]+?)(?: por [^.]+)?[:.]/u', $detalle, $m)) $detalle = 'Tu caso quedó asignado a ' . trim($m[1]) . '.';
        $eventos[] = ['n' => count($eventos), 'fecha' => $h['fecha'], 'evento' => $h['evento'], 'detalle' => $detalle, 'usuario' => $h['usuario'],
                      'estado_anterior' => $h['estado_anterior'], 'estado_nuevo' => $h['estado_nuevo'], 'publico' => (int) $h['publico'],
                      'tipo' => $tipo, 'calculado' => false, 'resultado' => null];
    }
    $hay = function ($nombres) use ($historial) {
        foreach ($historial as $h) if (in_array($h['evento'], (array) $nombres, true)) return true;
        return false;
    };
    $agregar = function ($fecha, $evento, $detalle, $usuario, $tipo = null, $resultado = null) use (&$eventos) {
        $eventos[] = ['n' => count($eventos), 'fecha' => $fecha, 'evento' => $evento, 'detalle' => $detalle, 'usuario' => $usuario, 'estado_anterior' => null,
                      'estado_nuevo' => null, 'publico' => 1, 'tipo' => $tipo ?: tipoEvento($evento), 'calculado' => true, 'resultado' => $resultado];
    };

    // Casos anteriores al historial: los pasos principales se toman de las fechas guardadas en el caso
    if (!$hay('Registro')) $agregar($t['created_at'], 'Registro', 'Fecha de registro del caso.', $cliente ?: 'Cliente');
    if (!empty($t['fecha_primera_respuesta']) && !$hay('Primera respuesta')) $agregar($t['fecha_primera_respuesta'], 'Primera respuesta', 'Fecha tomada de los datos del caso.', $t['nom_tecnico'] ?: 'Mesa de servicios');
    if (!empty($t['fecha_resolucion']) && !$hay('Solución')) $agregar($t['fecha_resolucion'], 'Solución', 'Fecha tomada de los datos del caso.', $t['nom_tecnico'] ?: 'Mesa de servicios');
    if (!empty($t['fecha_cierre']) && !$hay(['Cierre automático', 'Confirmación del cliente'])) {
        $cerrado = false;
        foreach ($historial as $h) if ($h['estado_nuevo'] && comportamientoEstado($db, $h['estado_nuevo']) === COMP_CERRADO) $cerrado = true;
        if (!$cerrado) $agregar($t['fecha_cierre'], 'Cierre', 'Fecha tomada de los datos del caso.', $t['nom_tecnico'] ?: 'Mesa de servicios', 'cierre');
    }

    // Hitos del ANS (solo la mesa)
    if (!$publico && $sla) {
        $r = $sla['respuesta'];
        $s = $sla['resolucion'];
        if ($calendario === 'habil' && $sla['inicio_conteo'] > $t['created_at']) {
            $agregar($sla['inicio_conteo'], 'Inicio del conteo', 'Se radicó fuera del horario de atención: el reloj empezó en la siguiente jornada.', 'ANS (calculado)', 'hito', 'info');
        }
        if ($r['finalizado']) {
            $texto = ($r['cumplido'] ? 'Cumplido' : 'Incumplido') . ': la primera respuesta llegó a los ' . duracionTexto($r['consumido']) . ' hábiles.';
            $resultado = $r['cumplido'] ? 'ok' : 'mal';
        } else {
            $texto = $r['vence'] <= $ahora ? 'Venció sin primera respuesta.' : 'Pendiente: aún no hay primera respuesta.';
            $resultado = $r['vence'] <= $ahora ? 'mal' : 'pendiente';
        }
        $agregar($r['vence'], 'Vence la primera respuesta', 'Objetivo ' . duracionTexto($r['objetivo']) . '. ' . $texto, 'ANS (calculado)', 'hito', $resultado);
        if ($s['vence']) {
            if ($s['finalizado']) {
                $texto = ($s['cumplido'] ? 'Cumplido' : 'Incumplido') . ': se solucionó con ' . duracionTexto($s['consumido']) . ' hábiles netos.';
                $resultado = $s['cumplido'] ? 'ok' : 'mal';
            } elseif ($s['vence'] <= $ahora) {
                $texto = 'Venció sin solución.';
                $resultado = 'mal';
            } else {
                $texto = 'Pendiente: faltan ' . duracionTexto($s['restante']) . ' hábiles.';
                $resultado = 'pendiente';
            }
            $agregar($s['vence'], 'Vence la solución', 'Objetivo ' . duracionTexto($s['objetivo']) . ($s['minutos_pausa'] ? ' + ' . duracionTexto($s['minutos_pausa']) . ' descontados' : '') . '. ' . $texto, 'ANS (calculado)', 'hito', $resultado);
        }
        $cierre_automatico = fechaCierreAutomatico($t, $cfg);
        if ($cierre_automatico) $agregar($cierre_automatico, 'Cierre automático programado', 'Si el cliente no confirma la solución antes, el caso se cierra solo.', 'Plataforma', 'hito', 'pendiente');
    }

    // Orden cronológico; a la misma hora, los eventos reales antes que los hitos
    usort($eventos, function ($a, $b) {
        return [$a['fecha'], $a['tipo'] === 'hito', $a['n']] <=> [$b['fecha'], $b['tipo'] === 'hito', $b['n']];
    });
    $anterior = null;
    foreach ($eventos as $i => $e) {
        unset($eventos[$i]['n']);
        $eventos[$i]['futuro'] = $e['fecha'] > $ahora;
        $eventos[$i]['desde_registro'] = minutosHabiles($t['created_at'], $e['fecha'], $calendario, $cfg);
        $eventos[$i]['desde_anterior'] = null;
        if ($e['tipo'] === 'hito') continue;
        if ($anterior !== null) {
            $eventos[$i]['desde_anterior'] = ['habiles' => minutosHabiles($anterior, $e['fecha'], $calendario, $cfg), 'calendario' => max(0, intdiv(strtotime($e['fecha']) - strtotime($anterior), 60))];
        }
        $anterior = $e['fecha'];
    }

    // Tiempo en cada estado, a partir de los cambios de estado registrados
    $tramos = [];
    $sumarTramo = function ($estado, $desde, $hasta) use (&$tramos, $calendario, $cfg) {
        if (!isset($tramos[$estado])) $tramos[$estado] = ['estado' => $estado, 'habiles' => 0, 'calendario' => 0];
        if ($hasta <= $desde) return;
        $tramos[$estado]['habiles'] += minutosHabiles($desde, $hasta, $calendario, $cfg);
        $tramos[$estado]['calendario'] += intdiv(strtotime($hasta) - strtotime($desde), 60);
    };
    $estado = null;
    $desde = $t['created_at'];
    foreach ($eventos as $e) {
        if ($e['calculado'] || empty($e['estado_nuevo'])) continue;
        if ($estado === null) {
            if ($e['evento'] === 'Registro') { $estado = $e['estado_nuevo']; continue; }
            $estado = $e['estado_anterior'] ?: primerEstado($db, COMP_ABIERTO);
        }
        $sumarTramo($estado, $desde, $e['fecha']);
        $estado = $e['estado_nuevo'];
        $desde = $e['fecha'];
    }
    $aproximado = $estado === null || $estado !== $t['estado'];
    if ($aproximado) $estado = $t['estado'];
    if (comportamientoEstado($db, $estado) !== COMP_CERRADO) $sumarTramo($estado, $desde, !empty($t['fecha_cierre']) ? $t['fecha_cierre'] : $ahora);
    $estados = [];
    foreach ($tramos as $tramo) {
        $tramo['comportamiento'] = comportamientoEstado($db, $tramo['estado']);
        $tramo['actual'] = $tramo['estado'] === $t['estado'];
        $estados[] = $tramo;
    }

    // Datos clave
    $registro = null;
    $asignaciones = 0;
    $reclasificaciones = 0;
    $ultima = null;
    foreach ($eventos as $e) {
        if ($e['evento'] === 'Registro' && !$registro) $registro = $e;
        if ($e['calculado']) continue;
        if (in_array($e['evento'], ['Asignación', 'Asignación automática'], true)) $asignaciones++;
        if ($e['evento'] === 'Reclasificación') $reclasificaciones++;
        if ($e['usuario'] !== 'Plataforma' && $e['usuario'] !== $cliente && !in_array($e['evento'], ['Registro', 'Respuesta del cliente', 'Confirmación del cliente', 'Reapertura solicitada'], true)) $ultima = $e;
    }
    $por_mesa = $registro && stripos((string) $registro['detalle'], 'mesa de servicios') !== false;
    $resumen = [
        'registrado' => $t['created_at'], 'registrado_texto' => diaHoraTexto($t['created_at']),
        'registrado_por' => $registro ? $registro['usuario'] : ($cliente ?: 'Cliente'), 'canal' => $por_mesa ? 'Registrado por la mesa de servicios' : 'Portal del cliente',
        'inicio_conteo' => $sla['inicio_conteo'] ?? null, 'vence_respuesta' => $sla['respuesta']['vence'] ?? null, 'vence_solucion' => $sla['resolucion']['vence'] ?? null,
        'primera_respuesta' => $t['fecha_primera_respuesta'] ?? null, 'solucion' => $t['fecha_resolucion'] ?? null, 'cierre' => $t['fecha_cierre'] ?? null,
        'minutos_pausa' => $sla ? $sla['resolucion']['minutos_pausa'] : (int) ($t['minutos_pausa'] ?? 0), 'reaperturas' => (int) ($t['reaperturas'] ?? 0),
        'asignaciones' => $asignaciones, 'reasignaciones' => max(0, $asignaciones - 1), 'reclasificaciones' => $reclasificaciones,
        'ultima_gestion' => $ultima ? ['fecha' => $ultima['fecha'], 'usuario' => $ultima['usuario'], 'evento' => $ultima['evento']] : null,
        'codigo_cierre' => $t['codigo_cierre'] ?? null, 'causa_raiz' => $t['causa_raiz'] ?? null, 'estados_aproximados' => $aproximado,
    ];

    // Por qué va a tiempo o se venció (solo la mesa)
    $explicacion = [];
    if (!$publico && $sla) {
        $r = $sla['respuesta'];
        $s = $sla['resolucion'];
        $pct = function ($valor) { return number_format((float) $valor, 0, ',', '.') . ' %'; };
        $explicacion[] = 'Radicado el ' . diaHoraTexto($t['created_at']) . ($calendario === 'habil' && $sla['inicio_conteo'] > $t['created_at']
            ? ', fuera del horario de atención: el reloj empezó el ' . diaHoraTexto($sla['inicio_conteo']) . '.' : ', dentro del horario de atención.');
        $explicacion[] = (TIPOS_TICKET[$t['tipo'] ?? ''] ?? 'Caso') . " de prioridad {$t['prioridad']} (impacto " . ($t['impacto'] ?: '—') . ', urgencia ' . ($t['urgencia'] ?: '—') . '): primera respuesta en '
            . duracionTexto($r['objetivo']) . ' y solución en ' . duracionTexto($s['objetivo']) . ($calendario === 'habil' ? ' hábiles.' : ' en 24x7.');
        if ($r['finalizado']) {
            $explicacion[] = 'Primera respuesta' . (!empty($t['fecha_primera_respuesta']) ? ' el ' . diaHoraTexto($t['fecha_primera_respuesta']) : '') . ' a los ' . duracionTexto($r['consumido']) . ' hábiles: '
                . ($r['cumplido'] ? 'dentro del ANS.' : 'fuera del ANS (vencía el ' . diaHoraTexto($r['vence']) . ').');
        } else {
            $explicacion[] = $r['restante'] >= 0 ? 'Aún sin primera respuesta; vence el ' . diaHoraTexto($r['vence']) . '.'
                : 'Sin primera respuesta: venció el ' . diaHoraTexto($r['vence']) . ', hace ' . duracionTexto(-$r['restante']) . ' hábiles.';
        }
        if ($s['finalizado']) {
            $explicacion[] = 'Solucionado el ' . diaHoraTexto($t['fecha_resolucion'] ?: $t['fecha_cierre']) . ' con ' . duracionTexto($s['consumido']) . ' hábiles netos de ' . duracionTexto($s['objetivo']) . ': '
                . ($s['cumplido'] ? 'dentro del ANS.' : 'fuera del ANS por ' . duracionTexto($s['consumido'] - $s['objetivo']) . ' hábiles.');
        } elseif (!empty($t['fecha_pausa'])) {
            $explicacion[] = 'En pausa desde el ' . diaHoraTexto($t['fecha_pausa']) . ': el reloj de solución está detenido con ' . duracionTexto($s['consumido']) . ' hábiles de ' . duracionTexto($s['objetivo']) . ' (' . $pct($s['pct']) . ').';
        } elseif ($s['nivel'] === 'vencido') {
            $explicacion[] = '¿Por qué está vencido? La solución vencía el ' . diaHoraTexto($s['vence']) . ' y el caso sigue sin solución: lleva ' . duracionTexto($s['consumido']) . ' hábiles de '
                . duracionTexto($s['objetivo']) . ' (' . $pct($s['pct']) . '), es decir, está vencido hace ' . duracionTexto(-$s['restante']) . ' hábiles.';
        } else {
            $explicacion[] = 'Solución: lleva ' . duracionTexto($s['consumido']) . ' hábiles de ' . duracionTexto($s['objetivo']) . ' (' . $pct($s['pct']) . '); vence el ' . diaHoraTexto($s['vence']) . '.';
        }
        if ($s['minutos_pausa'] > 0) $explicacion[] = 'Se descontaron ' . duracionTexto($s['minutos_pausa']) . ' hábiles en pausa o mientras estuvo solucionado antes de reabrirse.';
        if ($reclasificaciones) $explicacion[] = "Se reclasificó $reclasificaciones vez/veces: el ANS se recalculó desde el registro con la prioridad nueva.";
        if ((int) $t['reaperturas'] > 0) $explicacion[] = 'Se reabrió ' . (int) $t['reaperturas'] . ' vez/veces.';
        if (!$s['finalizado'] && in_array($s['nivel'], ['riesgo', 'critico', 'vencido'], true)) {
            $mayor = null;
            foreach ($estados as $tramo) if ($tramo['comportamiento'] !== COMP_CERRADO && ($mayor === null || $tramo['habiles'] > $mayor['habiles'])) $mayor = $tramo;
            if ($mayor && $mayor['habiles'] > 0) $explicacion[] = "Donde más tiempo lleva: «{$mayor['estado']}», " . duracionTexto($mayor['habiles']) . ' hábiles.';
            $explicacion[] = $ultima
                ? 'Última gestión de la mesa: ' . mb_strtolower($ultima['evento'], 'UTF-8') . ' el ' . diaHoraTexto($ultima['fecha']) . ' por ' . $ultima['usuario'] . ', hace ' . duracionTexto(minutosHabiles($ultima['fecha'], $ahora, $calendario, $cfg)) . ' hábiles.'
                : 'La mesa no ha registrado gestiones desde el registro.';
            if ($asignaciones <= 1 && !$hay('Escalamiento')) $explicacion[] = 'No se ha escalado ni reasignado: considera reasignarlo desde Supervisión y Cargas.';
        }
    }

    if ($publico) {
        // El cliente solo recibe sus eventos públicos y datos básicos: nada de causa raíz, código de cierre, tiempos por estado ni gestión interna
        $resumen = array_intersect_key($resumen, array_flip(['registrado', 'registrado_texto', 'canal', 'primera_respuesta', 'solucion', 'cierre', 'reaperturas']));
        $estados = [];
    }
    return ['eventos' => $eventos, 'estados' => $estados, 'resumen' => $resumen, 'explicacion' => $explicacion];
}

// Agrega una nota a la bitácora: "[d/m/Y H:i] [ETIQUETA] Autor: texto". El anexo queda como enlace que la vista convierte en descarga protegida.
function agregarNotaBitacora($bitacora, $etiqueta, $autor, $texto, $archivo = null) {
    $texto = trim((string) $texto);
    // "|--|" separa las notas de la bitácora: si viniera dentro del texto partiría la nota en dos y el resto quedaría
    // sin etiqueta (una nota interna se vería como pública, o un cliente podría fabricar una nota "del especialista")
    while (strpos($texto, '|--|') !== false) $texto = str_replace('|--|', '|- -|', $texto);
    if ($archivo) {
        $texto .= ($texto !== '' ? '<br>' : '') . "📎 <a href='../uploads/tickets/$archivo' target='_blank'>"
                . ($etiqueta === ETIQUETA_NOTA_CLIENTE ? 'Archivo adjunto del cliente' : 'Documento anexo de soporte técnico') . '</a>';
    }
    $autor = trim(preg_replace('/[\[\]:\r\n]+/u', ' ', (string) $autor));
    return (string) $bitacora . "\n|--|\n[" . date('d/m/Y H:i') . "] $etiqueta" . ($autor !== '' ? " $autor" : '') . ': ' . $texto;
}

// Notas de la bitácora: [fecha, tipo (publica | interna | cliente), autor, html seguro]. Sin $incluir_internas nunca salen las internas.
function notasBitacora($bitacora, $incluir_internas = true) {
    $notas = [];
    foreach (explode('|--|', (string) $bitacora) as $bloque) {
        $bloque = trim($bloque);
        if ($bloque === '') continue;
        $nota = ['fecha' => '', 'tipo' => 'publica', 'autor' => '', 'html' => ''];
        $texto = $bloque;
        if (preg_match('/^\[([^\]]*)\]\s*\[(VISIBLE CLIENTE|NOTA INTERNA|CLIENTE)\]\s*([^:\n]*):\s?(.*)$/su', $bloque, $m)) {
            $nota['fecha'] = $m[1];
            $nota['tipo'] = $m[2] === 'NOTA INTERNA' ? 'interna' : ($m[2] === 'CLIENTE' ? 'cliente' : 'publica');
            $nota['autor'] = trim($m[3]);
            $texto = $m[4];
        } elseif (strpos($bloque, ETIQUETA_NOTA_INTERNA) !== false) {
            $nota['tipo'] = 'interna';
        }
        if ($nota['tipo'] === 'interna' && !$incluir_internas) continue;
        $nota['html'] = htmlNota($texto);
        $notas[] = $nota;
    }
    return $notas;
}

// Texto de una nota como HTML seguro: saltos de línea y solo los anexos generados por la plataforma (descarga protegida)
function htmlNota($texto) {
    $html = htmlspecialchars(trim((string) $texto), ENT_QUOTES, 'UTF-8');
    $html = str_replace('&lt;br&gt;', "\n", $html);
    $html = preg_replace_callback('/&lt;a href=&#0?39;\.\.\/uploads\/tickets\/(doc_[A-Za-z0-9_]+\.[A-Za-z0-9]+)&#0?39; target=&#0?39;_blank&#0?39;&gt;(.*?)&lt;\/a&gt;/u',
        function ($m) { return '<a href="descargar_adjunto.php?archivo=' . rawurlencode($m[1]) . '" target="_blank" rel="noopener">' . $m[2] . '</a>'; }, $html);
    return nl2br($html, false);
}

// Casos con comportamiento normalizado y ANS calculado
function ticketsItsm(PDO $db, $where = '1 = 1', array $params = []) {
    $cfg = configItsm($db);
    $stmt = $db->prepare("SELECT t.*, u.nombre AS cliente, u.correo AS correo_cliente, u.telefono AS telefono_cliente, u.organizacion AS organizacion_cliente,
                                 tech.nombre AS nom_tecnico, e.comportamiento AS comportamiento_bd, e.clase_color
                          FROM soporte_tickets t
                          LEFT JOIN usuarios u ON u.id = t.usuario_id
                          LEFT JOIN usuarios tech ON tech.id = t.tecnico_id
                          LEFT JOIN estados_ticket e ON e.nombre = t.estado
                          WHERE $where
                          ORDER BY t.created_at DESC, t.id DESC");
    $stmt->execute($params);
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $t['comportamiento'] = normalizarComportamiento($t['comportamiento_bd'], $t['estado']);
        $t['tipo'] = tipoTicket($t);
        $t['sla'] = $t['sla_resolucion_min'] !== null ? slaTicket($t, $cfg) : null;
        $filas[] = $t;
    }
    return $filas;
}

/**
 * Mantenimiento que corre al abrir las páginas de la mesa (no requiere tareas programadas):
 * 1) calcula el ANS de los casos que aún no lo tienen (creados antes de este módulo),
 * 2) cierra los casos solucionados que el cliente no confirmó en el plazo,
 * 3) envía las alertas de escalamiento por umbral (una vez por nivel).
 */
function sincronizarTickets(PDO $db) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $cfg = configItsm($db);
    $ahora = date('Y-m-d H:i:s');

    foreach (ticketsItsm($db, 't.sla_resolucion_min IS NULL') as $t) {
        $campos = [];
        $c = $t['comportamiento'];
        if ($c !== COMP_ABIERTO && empty($t['fecha_primera_respuesta'])) $campos['fecha_primera_respuesta'] = $t['fecha_respuesta'] ?: $t['created_at'];
        if (in_array($c, [COMP_SOLUCIONADO, COMP_CERRADO], true) && empty($t['fecha_resolucion'])) $campos['fecha_resolucion'] = $t['fecha_respuesta'] ?: $t['created_at'];
        if ($c === COMP_CERRADO && empty($t['fecha_cierre'])) $campos['fecha_cierre'] = $t['fecha_respuesta'] ?: ($campos['fecha_resolucion'] ?? $t['created_at']);
        if ($c === COMP_PAUSA && empty($t['fecha_pausa'])) $campos['fecha_pausa'] = $t['fecha_respuesta'] ?: $ahora;
        $prioridad = in_array($t['prioridad'], PRIORIDADES_TICKET, true) ? $t['prioridad'] : prioridadDe($t['impacto'], $t['urgencia']);
        $campos += camposObjetivoSla($db, array_merge($t, $campos), $t['tipo'], $prioridad, $cfg);
        $sla = slaTicket(array_merge($t, $campos), $cfg);
        if (!empty($campos['fecha_primera_respuesta']) || !empty($t['fecha_primera_respuesta'])) $campos['sla_respuesta_cumplido'] = $sla['respuesta']['cumplido'] ? 1 : 0;
        if ($sla['resolucion']['finalizado']) $campos['sla_resolucion_cumplido'] = $sla['resolucion']['cumplido'] ? 1 : 0;
        if (empty($t['ultima_gestion_fecha'])) $campos['ultima_gestion_fecha'] = $t['fecha_respuesta'] ?: $t['created_at'];
        // Sin alertas retroactivas: el nivel ya alcanzado por los casos existentes no genera correos
        $campos['sla_alerta_nivel'] = $sla['resolucion']['finalizado'] ? 0 : nivelAlertaSla($sla['resolucion']['pct'], $cfg);
        actualizarCamposTicket($db, $t['id'], $campos);
    }

    // Asignación automática: casos abiertos sin especialista, o con uno que ya no está activo en la mesa de servicios
    if (($cfg['asignacion_automatica'] ?? '1') === '1') {
        $contexto = null;
        foreach (ticketsItsm($db, 't.fecha_resolucion IS NULL AND t.fecha_cierre IS NULL') as $t) {
            if (in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true)) continue;
            if ($contexto === null) $contexto = [especialistasItsm($db), cargasEspecialistas($db)];
            $tecnico = (int) $t['tecnico_id'];
            if ($tecnico && isset($contexto[0][$tecnico])) continue;
            asignarAutomaticamente($db, $t, $cfg, $ahora, $contexto, $tecnico ? 'El especialista anterior ya no está activo en la mesa de servicios.' : '');
        }
    }

    // Cierre automático: solucionados sin confirmación del cliente después de N días hábiles
    $jornada = minutosJornada($cfg);
    $limite = (int) $cfg['cierre_automatico_dias'] * $jornada;
    if ($limite > 0) {
        $estado_cierre = estadoCierreAutomatico($db);
        foreach (ticketsItsm($db, 't.fecha_resolucion IS NOT NULL AND t.fecha_cierre IS NULL') as $t) {
            if ($t['comportamiento'] !== COMP_SOLUCIONADO) continue;
            $transcurrido = minutosHabiles($t['fecha_resolucion'], $ahora, 'habil', $cfg);
            if ($transcurrido < $limite) continue;
            list($campos, ) = transicionTicket($t, COMP_SOLUCIONADO, COMP_CERRADO, $ahora, $cfg);
            actualizarCamposTicket($db, $t['id'], $campos + ['estado' => $estado_cierre]);
            registrarEventoTicket($db, $t['id'], null, 'Cierre automático',
                'Cerrado sin respuesta del cliente ' . (int) $cfg['cierre_automatico_dias'] . ' días hábiles después de la solución.', $t['estado'], $estado_cierre, true, $ahora);
            // Los casos que llevaban mucho más tiempo solucionados (p. ej. anteriores a este módulo) se cierran sin enviar correos
            if ($transcurrido < $limite + $jornada) correoCierreTicket($db, array_merge($t, $campos, ['estado' => $estado_cierre]));
        }
    }

    evaluarAlertasSla($db, $cfg);
}

// Alertas de escalamiento del ANS de solución (umbral de riesgo, crítico y vencido), una sola vez por nivel
function evaluarAlertasSla(PDO $db, array $cfg) {
    $textos = [101 => 'venció', (int) $cfg['umbral_critico'] => 'llegó al nivel crítico', (int) $cfg['umbral_riesgo'] => 'está en riesgo'];
    foreach (ticketsItsm($db, 't.sla_resolucion_min IS NOT NULL AND t.fecha_resolucion IS NULL AND t.fecha_cierre IS NULL') as $t) {
        if (!$t['sla'] || in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO, COMP_PAUSA], true)) continue;
        $pct = $t['sla']['resolucion']['pct'];
        $nivel = nivelAlertaSla($pct, $cfg);
        if ($nivel <= (int) $t['sla_alerta_nivel']) continue;
        actualizarCamposTicket($db, $t['id'], ['sla_alerta_nivel' => $nivel, 'notificar_tecnico' => 1]);
        $detalle = "El ANS de solución {$textos[$nivel]}: " . number_format($pct, 1, ',', '.') . '% consumido (' . duracionTexto($t['sla']['resolucion']['consumido']) . ' de ' . duracionTexto($t['sla']['resolucion']['objetivo']) . ').';
        registrarEventoTicket($db, $t['id'], null, 'Alerta ANS', $detalle);
        correoAlertaSla($db, $t, $detalle);
    }
}

// ================================================================
// CORREOS
// ================================================================

function plantillaCorreoTicket($color, $contenido) {
    return '<html><body style="background:#f4f6f8;font-family:Arial;padding:30px">
        <table width="600" align="center" style="background:#ffffff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.08)">
            <tr><td style="padding:30px;text-align:center;background-color:#ffffff;border-bottom:3px solid ' . $color . ';border-radius:12px 12px 0 0;"><img src="cid:logo_datasense" style="max-width:220px"></td></tr>
            <tr><td style="padding:35px;color:#1f2937;font-size:15px;line-height:1.6">' . $contenido . '</td></tr>
        </table></body></html>';
}

function enviarCorreoTicket($para, $nombre, $asunto, $html, $remitente = 'Soporte IT - DataSense') {
    if (!function_exists('nuevoCorreo') || !$para) return false;
    try {
        $mail = nuevoCorreo($remitente);
        $mail->addAddress($para, $nombre);
        if (is_file(__DIR__ . '/../img/logo.png')) $mail->addEmbeddedImage(__DIR__ . '/../img/logo.png', 'logo_datasense');
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $html;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Correo de la mesa de servicios no enviado (' . $asunto . '): ' . $e->getMessage());
        return false;
    }
}

// Texto plano del cuerpo de un correo (sin la plantilla) para guardar en la traza lo que realmente recibió el cliente
function textoPlanoCorreo($html) {
    $html = (string) $html;
    if (preg_match('~<td style="padding:35px[^"]*">(.*)</td></tr>\s*</table></body></html>\s*$~s', $html, $m)) $html = $m[1];
    $texto = preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', '', $html);
    $texto = preg_replace('~<(br|/p|/div|/tr|/h\d|/li|/table)\b[^>]*>~i', "\n", $texto);
    $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8');
    $texto = preg_replace('~[ \t]+~u', ' ', $texto);
    return trim(preg_replace('~\s*\n\s*~u', "\n", $texto));
}

/**
 * Correo al cliente con traza: se envía, se guarda completo en ticket_correos (para quién, asunto, contenido y si salió)
 * y queda como evento interno del historial, para que el supervisor sepa qué se le comunicó, cuándo y quién lo hizo.
 * $usuario_id null = correo automático de la plataforma.
 */
function correoClienteConTraza(PDO $db, array $t, $tipo, $asunto, $html, $remitente = 'Soporte IT - DataSense', $usuario_id = null) {
    list($correo, $nombre) = datosClienteTicket($db, $t);
    $enviado = enviarCorreoTicket($correo, $nombre, $asunto, $html, $remitente);
    $ahora = date('Y-m-d H:i:s');
    try {
        $db->prepare("INSERT INTO ticket_correos (ticket_id, fecha, usuario_id, tipo, destinatario, asunto, contenido, enviado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([(int) $t['id'], $ahora, $usuario_id ?: null, mb_substr($tipo, 0, 40), $correo ? mb_substr($correo, 0, 150) : null, mb_substr($asunto, 0, 255), textoPlanoCorreo($html), $enviado ? 1 : 0]);
    } catch (PDOException $e) {
        error_log('Traza del correo no guardada (falta la tabla ticket_correos de la migración 6): ' . $e->getMessage());
    }
    registrarEventoTicket($db, $t['id'], $usuario_id, $enviado ? 'Correo al cliente' : 'Correo no enviado',
        $tipo . ': «' . $asunto . '» para ' . ($correo ?: 'un cliente sin correo registrado') . ($enviado ? '.' : '. El servidor de correo no lo aceptó.'), null, null, false, $ahora);
    return $enviado;
}

// Correos enviados al cliente en un caso, del más reciente al más antiguo
function correosTicket(PDO $db, $ticket_id) {
    try {
        $stmt = $db->prepare("SELECT c.id, c.fecha, c.tipo, c.destinatario, c.asunto, c.contenido, c.enviado, u.nombre AS usuario
                              FROM ticket_correos c LEFT JOIN usuarios u ON u.id = c.usuario_id
                              WHERE c.ticket_id = ? ORDER BY c.fecha DESC, c.id DESC");
        $stmt->execute([(int) $ticket_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Número para WhatsApp con indicativo: celulares colombianos de 10 dígitos reciben el 57; otros deben traer su indicativo
function numeroWhatsapp($telefono) {
    $digitos = preg_replace('/\D+/', '', (string) $telefono);
    if (strlen($digitos) === 10 && $digitos[0] === '3') return '57' . $digitos;
    if (strlen($digitos) >= 11 && strlen($digitos) <= 15) return $digitos;
    return null;
}

function datosClienteTicket(PDO $db, array $t) {
    if (!empty($t['correo_cliente'])) return [$t['correo_cliente'], $t['cliente']];
    $stmt = $db->prepare("SELECT correo, nombre FROM usuarios WHERE id = ?");
    $stmt->execute([(int) $t['usuario_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['correo' => null, 'nombre' => ''];
    return [$u['correo'], $u['nombre']];
}

function saludoCorreo($nombre) {
    return '<p>Hola 👋 <strong>' . htmlspecialchars(mb_convert_case((string) $nombre, MB_CASE_TITLE, 'UTF-8')) . '</strong></p>';
}

// Caso solucionado: el cliente confirma o reabre desde el Centro de Soporte
function correoSolucionTicket(PDO $db, array $t, array $cfg, $usuario_id = null) {
    list($correo, $nombre) = datosClienteTicket($db, $t);
    $html = plantillaCorreoTicket('#10b981', saludoCorreo($nombre)
        . '<h3 style="color:#10b981;margin-top:0">Tu caso fue resuelto</h3>'
        . '<p>El ticket <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong> ("' . htmlspecialchars($t['asunto']) . '") tiene una solución registrada:</p>'
        . '<div style="background:#ecfdf5;border:1px solid #a7f3d0;padding:20px;border-radius:8px"><span style="color:#065f46;font-size:14px">' . nl2br(htmlspecialchars((string) $t['solucion'])) . '</span></div>'
        . '<p>Entra al <a href="' . htmlspecialchars(urlBase()) . 'admin/soporte.php">Centro de Soporte</a> para <strong>confirmar la solución</strong> o <strong>reabrir el caso</strong>. '
        . 'Si no recibimos respuesta, se cerrará automáticamente en ' . (int) $cfg['cierre_automatico_dias'] . ' días hábiles.</p><p><strong>Equipo de Operaciones IT</strong></p>');
    return correoClienteConTraza($db, $t, 'Solución del caso', '[' . $t['codigo_ticket'] . '] Caso resuelto: confirma la solución – DataSense', $html, 'Soporte IT - DataSense', $usuario_id);
}

// Caso cerrado: resumen de la solución y encuesta de satisfacción (si el cliente aún no calificó)
function correoCierreTicket(PDO $db, array $t, $usuario_id = null) {
    list($correo, $nombre) = datosClienteTicket($db, $t);
    $html = plantillaCorreoTicket('#10b981', saludoCorreo($nombre)
        . '<h3 style="color:#10b981;margin-top:0">Tu caso ha sido finalizado</h3>'
        . '<p>El ticket <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong> ("' . htmlspecialchars($t['asunto']) . '") quedó cerrado.</p>'
        . '<div style="background:#ecfdf5;border:1px solid #a7f3d0;padding:20px;border-radius:8px"><strong style="color:#047857;font-size:13px">SOLUCIÓN TÉCNICA APLICADA:</strong><br>'
        . '<span style="color:#065f46;font-size:14px">' . nl2br(htmlspecialchars((string) $t['solucion'])) . '</span></div><br><p><strong>Equipo de Operaciones IT</strong></p>');
    correoClienteConTraza($db, $t, 'Cierre del caso', '[' . $t['codigo_ticket'] . '] Caso Resuelto / Cerrado – DataSense', $html, 'Soporte IT - DataSense', $usuario_id);

    if ((int) ($t['calificacion_estrellas'] ?? 0) > 0) return; // Ya calificó al confirmar la solución
    // Encuesta de soporte: la de .env (ENCUESTA_SOPORTE_TOKEN) o CSAT-001; si no existe, la primera activa
    $stmt = $db->prepare("SELECT token_url FROM encuestas WHERE estado = 'Activo' ORDER BY (token_url = ?) DESC, id ASC LIMIT 1");
    $stmt->execute([$_ENV['ENCUESTA_SOPORTE_TOKEN'] ?? 'CSAT-001']);
    $token = $stmt->fetchColumn();
    if (!$token) return;
    $url = urlBase() . 'responder_encuesta.php?token=' . urlencode($token) . '&ticket=' . urlencode($t['codigo_ticket']) . '&cliente=' . urlencode($nombre);
    $html = plantillaCorreoTicket('#f59e0b', '<div style="text-align:center"><h2 style="margin-top:0;color:#0f172a">¡Tu opinión nos ayuda a mejorar! 🌟</h2>'
        . '<p>Queremos asegurarnos de que recibiste el mejor servicio con tu caso <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong>.</p>'
        . '<p>Tómate 1 minuto para calificar la atención.</p><br><a href="' . htmlspecialchars($url) . '" style="background:#f59e0b;color:#fff;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:bold">📝 Responder Encuesta</a></div>');
    correoClienteConTraza($db, $t, 'Encuesta de satisfacción', 'Por favor, califica nuestra atención (Ticket ' . $t['codigo_ticket'] . ')', $html, 'Calidad IT - DataSense', $usuario_id);
}

// Confirmación de radicado con el compromiso del ANS
function correoRadicadoTicket(PDO $db, array $t, $compromiso) {
    list($correo, $nombre) = datosClienteTicket($db, $t);
    $fila = function ($etiqueta, $valor) {
        return '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb"><strong>' . $etiqueta . '</strong></td><td style="padding:10px;border-bottom:1px solid #e5e7eb">' . $valor . '</td></tr>';
    };
    $html = plantillaCorreoTicket('#0A2E4F', saludoCorreo($nombre)
        . '<p>Hemos recibido tu solicitud correctamente. Nuestro equipo validará el caso para darle solución.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:20px;font-size:14px">'
        . $fila('Número de Ticket:', '<span style="color:#0d6efd;font-weight:bold;font-size:16px">' . htmlspecialchars($t['codigo_ticket']) . '</span>')
        . $fila('Clasificación:', TIPOS_TICKET[$t['tipo']] ?? 'Caso')
        . $fila('Servicio:', htmlspecialchars((string) $t['categoria']))
        . $fila('Prioridad:', htmlspecialchars($t['prioridad']))
        . (!empty($t['nom_tecnico']) ? $fila('Especialista asignado:', htmlspecialchars($t['nom_tecnico'])) : '')
        . $fila('Compromiso (ANS):', htmlspecialchars($compromiso))
        . '</table><br><p><strong>Equipo de Operaciones IT</strong></p>');
    return correoClienteConTraza($db, $t, 'Confirmación de radicado', '[' . $t['codigo_ticket'] . '] Confirmación de Radicado – DataSense', $html);
}

// Nota del especialista visible para el cliente (o solicitud de información que detiene el ANS)
function correoActualizacionTicket(PDO $db, array $t, $nota, $pide_informacion, $usuario_id = null) {
    list($correo, $nombre) = datosClienteTicket($db, $t);
    $html = plantillaCorreoTicket('#0A2E4F', saludoCorreo($nombre)
        . '<p>' . ($pide_informacion ? 'Para continuar con tu caso <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong> necesitamos información adicional:'
                                     : 'Hay una actualización en tu caso <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong>:') . '</p>'
        . '<div style="background:#f1f5f9;border-left:4px solid #0A2E4F;padding:16px;border-radius:6px">' . nl2br(htmlspecialchars($nota)) . '</div>'
        . '<p>Responde desde el <a href="' . htmlspecialchars(urlBase()) . 'admin/soporte.php">Centro de Soporte</a>'
        . ($pide_informacion ? '; mientras tanto el tiempo de solución queda en pausa.' : '.') . '</p><p><strong>Equipo de Operaciones IT</strong></p>');
    return correoClienteConTraza($db, $t, $pide_informacion ? 'Solicitud de información' : 'Actualización del caso',
        '[' . $t['codigo_ticket'] . '] ' . ($pide_informacion ? 'Necesitamos tu respuesta' : 'Actualización de tu caso') . ' – DataSense', $html, 'Soporte IT - DataSense', $usuario_id);
}

// Aviso al especialista asignado (respuesta del cliente, confirmación, reapertura)
function correoTecnicoTicket(PDO $db, array $t, $tecnico_id, $asunto, $contenido, $color = '#0A2E4F') {
    if (empty($tecnico_id)) return false;
    $stmt = $db->prepare("SELECT nombre, correo FROM usuarios WHERE id = ?");
    $stmt->execute([(int) $tecnico_id]);
    $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tecnico) return false;
    $html = plantillaCorreoTicket($color, saludoCorreo($tecnico['nombre']) . $contenido
        . '<p><a href="' . htmlspecialchars(urlBase()) . 'admin/gestion_it.php?abrir=' . urlencode($t['codigo_ticket']) . '">Abrir el caso en Gestión IT</a></p>');
    return enviarCorreoTicket($tecnico['correo'], $tecnico['nombre'], $asunto, $html);
}

function correoAsignacionTicket(PDO $db, array $t, $tecnico_id) {
    $sla = $t['sla'] ?? null;
    $contenido = '<p>Se te asignó el caso <strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong>: ' . htmlspecialchars($t['asunto']) . '.</p>'
        . '<p>Prioridad <strong>' . htmlspecialchars($t['prioridad']) . '</strong>';
    if ($sla) {
        $contenido .= $sla['respuesta']['finalizado'] ? '' : ' · primera respuesta antes de <strong>' . date('d/m/Y H:i', strtotime($sla['respuesta']['vence'])) . '</strong>';
        $contenido .= $sla['resolucion']['vence'] ? ' · solución antes de <strong>' . date('d/m/Y H:i', strtotime($sla['resolucion']['vence'])) . '</strong>' : '';
    }
    return correoTecnicoTicket($db, $t, $tecnico_id, '[' . $t['codigo_ticket'] . '] Caso asignado – prioridad ' . $t['prioridad'], $contenido . '.</p>');
}

function correoAlertaSla(PDO $db, array $t, $detalle) {
    return correoTecnicoTicket($db, $t, $t['tecnico_id'], '[' . $t['codigo_ticket'] . '] Alerta de ANS – ' . number_format($t['sla']['resolucion']['pct'], 0) . '% consumido',
        '<p><strong>' . htmlspecialchars($t['codigo_ticket']) . '</strong> · ' . htmlspecialchars($t['asunto']) . '</p><p>' . htmlspecialchars($detalle) . '</p>', '#dc2626');
}

// ================================================================
// INDICADORES: MESA, SUPERVISIÓN Y REPORTE EJECUTIVO
// ================================================================

function resumenMesa(array $tickets, array $cfg) {
    $r = ['abiertos' => 0, 'sin_asignar' => 0, 'en_pausa' => 0, 'por_confirmar' => 0, 'en_riesgo' => 0, 'vencidos' => 0, 'estancados' => 0, 'sin_respuesta' => 0];
    $limite = (int) $cfg['horas_sin_gestion'] * 60;
    $ahora = new DateTime();
    foreach ($tickets as $t) {
        if ($t['comportamiento'] === COMP_CERRADO) continue;
        if ($t['comportamiento'] === COMP_SOLUCIONADO) { $r['por_confirmar']++; continue; }
        $r['abiertos']++;
        if (empty($t['tecnico_id'])) $r['sin_asignar']++;
        if ($t['comportamiento'] === COMP_PAUSA) $r['en_pausa']++;
        if ($t['sla']) {
            $nivel = $t['sla']['resolucion']['nivel'];
            if ($nivel === 'vencido') $r['vencidos']++;
            elseif ($nivel === 'riesgo' || $nivel === 'critico') $r['en_riesgo']++;
            if (empty($t['fecha_primera_respuesta'])) $r['sin_respuesta']++;
        }
        if ($t['comportamiento'] !== COMP_PAUSA && minutosHabiles($t['ultima_gestion_fecha'] ?: $t['created_at'], $ahora, 'habil', $cfg) >= $limite) $r['estancados']++;
    }
    return $r;
}

function esEstancado(array $t, array $cfg) {
    return !in_array($t['comportamiento'], [COMP_PAUSA, COMP_SOLUCIONADO, COMP_CERRADO], true)
        && minutosHabiles($t['ultima_gestion_fecha'] ?: $t['created_at'], new DateTime(), 'habil', $cfg) >= (int) $cfg['horas_sin_gestion'] * 60;
}

// Carga y desempeño por especialista (casos abiertos ahora y resueltos en los últimos $dias días)
function cargaEspecialistas(PDO $db, array $tickets, $dias = 30) {
    $desde = date('Y-m-d H:i:s', strtotime("-$dias days"));
    $base = function ($id, $nombre, $rol) {
        return ['id' => (int) $id, 'nombre' => $nombre, 'rol' => $rol, 'abiertos' => 0, 'prioridades' => array_fill_keys(PRIORIDADES_TICKET, 0), 'carga' => 0,
                'en_pausa' => 0, 'en_riesgo' => 0, 'vencidos' => 0, 'resueltos' => 0, 'a_tiempo' => 0, 'mttr_total' => 0, 'csat_total' => 0, 'csat_n' => 0];
    };
    $filas = [];
    foreach ($db->query("SELECT id, nombre, rol FROM usuarios WHERE rol IN ('Admin', 'Soporte') AND estado_cuenta = 'Activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $filas[(int) $u['id']] = $base($u['id'], $u['nombre'], $u['rol']);
    }
    foreach ($tickets as $t) {
        $id = (int) $t['tecnico_id'];
        if (!$id) continue;
        if (!isset($filas[$id])) $filas[$id] = $base($id, $t['nom_tecnico'] ?: "Usuario $id", '');
        if (!in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true)) {
            $filas[$id]['abiertos']++;
            if (isset($filas[$id]['prioridades'][$t['prioridad']])) $filas[$id]['prioridades'][$t['prioridad']]++;
            $filas[$id]['carga'] += PESOS_PRIORIDAD[$t['prioridad']] ?? 1;
            if ($t['comportamiento'] === COMP_PAUSA) $filas[$id]['en_pausa']++;
            if ($t['sla'] && $t['sla']['resolucion']['nivel'] === 'vencido') $filas[$id]['vencidos']++;
            elseif ($t['sla'] && in_array($t['sla']['resolucion']['nivel'], ['riesgo', 'critico'], true)) $filas[$id]['en_riesgo']++;
        }
        if (!empty($t['fecha_resolucion']) && $t['fecha_resolucion'] >= $desde && $t['sla']) {
            $filas[$id]['resueltos']++;
            if ($t['sla']['resolucion']['cumplido']) $filas[$id]['a_tiempo']++;
            $filas[$id]['mttr_total'] += $t['sla']['resolucion']['consumido'];
        }
        if ((int) $t['calificacion_estrellas'] > 0 && ($t['fecha_cierre'] ?: $t['fecha_resolucion']) >= $desde) {
            $filas[$id]['csat_total'] += (int) $t['calificacion_estrellas'];
            $filas[$id]['csat_n']++;
        }
    }
    foreach ($filas as &$f) {
        $f['cumplimiento'] = $f['resueltos'] ? round($f['a_tiempo'] * 100 / $f['resueltos'], 1) : null;
        $f['mttr'] = $f['resueltos'] ? (int) round($f['mttr_total'] / $f['resueltos']) : null;
        $f['csat'] = $f['csat_n'] ? round($f['csat_total'] / $f['csat_n'], 1) : null;
    }
    unset($f);
    usort($filas, function ($a, $b) { return [$b['carga'], $b['abiertos']] <=> [$a['carga'], $a['abiertos']]; });
    return $filas;
}

/**
 * Reporte ejecutivo del periodo: volumen, cumplimiento de ANS, tiempos medios, reaperturas, satisfacción,
 * desgloses, tendencia de 6 meses, incumplimientos y conclusiones.
 */
function reporteEjecutivoTic(PDO $db, $desde, $hasta) {
    $cfg = configItsm($db);
    $inicio = "$desde 00:00:00";
    $fin = "$hasta 23:59:59";
    $tickets = ticketsItsm($db, 't.created_at <= ?', [$fin]);
    $en = function ($fecha, $a = null, $b = null) use ($inicio, $fin) {
        return !empty($fecha) && $fecha >= ($a ?: $inicio) && $fecha <= ($b ?: $fin);
    };
    $vacio = function () { return ['registrados' => 0, 'resueltos' => 0, 'resp_medidos' => 0, 'resp_cumplidos' => 0, 'sol_cumplidos' => 0, 'mttr_total' => 0]; };
    $r = ['kpi' => ['registrados' => 0, 'resueltos' => 0, 'cerrados' => 0, 'backlog' => 0, 'resp_medidos' => 0, 'resp_cumplidos' => 0, 'sol_cumplidos' => 0,
                    'mtta_total' => 0, 'mttr_total' => 0, 'reabiertos' => 0, 'csat_total' => 0, 'csat_n' => 0, 'vencidos_abiertos' => 0, 'incidentes' => 0, 'requerimientos' => 0],
          'por_prioridad' => [], 'por_tipo' => [], 'por_servicio' => [], 'por_grupo' => [], 'por_tecnico' => [], 'por_codigo_cierre' => [], 'incumplimientos' => [], 'tendencia' => []];
    foreach (PRIORIDADES_TICKET as $p) $r['por_prioridad'][$p] = $vacio();
    foreach (TIPOS_TICKET as $tipo => $nombre) $r['por_tipo'][$tipo] = $vacio();
    $sumar = function (array &$grupo, $clave, array $t, $registrado, $respondido, $resuelto) use ($vacio) {
        if (!isset($grupo[$clave])) $grupo[$clave] = $vacio();
        if ($registrado) $grupo[$clave]['registrados']++;
        if ($respondido) {
            $grupo[$clave]['resp_medidos']++;
            if ((int) $t['sla_respuesta_cumplido'] === 1) $grupo[$clave]['resp_cumplidos']++;
        }
        if ($resuelto) {
            $grupo[$clave]['resueltos']++;
            if ((int) $t['sla_resolucion_cumplido'] === 1) $grupo[$clave]['sol_cumplidos']++;
            $grupo[$clave]['mttr_total'] += $t['sla']['resolucion']['consumido'];
        }
    };
    $k = &$r['kpi'];
    foreach ($tickets as $t) {
        if (!$t['sla']) continue;
        $registrado = $en($t['created_at']);
        $respondido = $en($t['fecha_primera_respuesta']) && $t['sla_respuesta_cumplido'] !== null;
        $resuelto = $en($t['fecha_resolucion']) && $t['sla_resolucion_cumplido'] !== null;
        if ($registrado) { $k['registrados']++; $t['tipo'] === 'INC' ? $k['incidentes']++ : $k['requerimientos']++; }
        if ($respondido) {
            $k['resp_medidos']++;
            if ((int) $t['sla_respuesta_cumplido'] === 1) $k['resp_cumplidos']++;
            $k['mtta_total'] += minutosHabiles($t['created_at'], $t['fecha_primera_respuesta'], $t['sla']['calendario'], $cfg);
        }
        if ($resuelto) {
            $k['resueltos']++;
            if ((int) $t['sla_resolucion_cumplido'] === 1) {
                $k['sol_cumplidos']++;
            } else {
                $r['incumplimientos'][] = ['codigo' => $t['codigo_ticket'], 'prioridad' => $t['prioridad'], 'servicio' => $t['categoria'] ?: $t['servicio'], 'tecnico' => $t['nom_tecnico'],
                                           'objetivo' => $t['sla']['resolucion']['objetivo'], 'consumido' => $t['sla']['resolucion']['consumido'], 'fecha' => $t['fecha_resolucion']];
            }
            $k['mttr_total'] += $t['sla']['resolucion']['consumido'];
            if ((int) $t['reaperturas'] > 0) $k['reabiertos']++;
            $codigo = !empty($t['codigo_cierre']) ? $t['codigo_cierre'] : 'Sin código de cierre';
            $r['por_codigo_cierre'][$codigo] = ($r['por_codigo_cierre'][$codigo] ?? 0) + 1;
        }
        if ($en($t['fecha_cierre'])) $k['cerrados']++;
        $resuelto_al_corte = (!empty($t['fecha_resolucion']) && $t['fecha_resolucion'] <= $fin) || (!empty($t['fecha_cierre']) && $t['fecha_cierre'] <= $fin);
        if (!$resuelto_al_corte) {
            $k['backlog']++;
            if ($t['sla']['resolucion']['nivel'] === 'vencido' && !$t['sla']['resolucion']['finalizado']) $k['vencidos_abiertos']++;
        }
        if ((int) $t['calificacion_estrellas'] > 0 && $en($t['fecha_cierre'] ?: $t['fecha_resolucion'])) {
            $k['csat_total'] += (int) $t['calificacion_estrellas'];
            $k['csat_n']++;
        }
        if ($registrado || $respondido || $resuelto) {
            $sumar($r['por_prioridad'], $t['prioridad'], $t, $registrado, $respondido, $resuelto);
            $sumar($r['por_tipo'], $t['tipo'], $t, $registrado, $respondido, $resuelto);
            $sumar($r['por_servicio'], ($t['servicio'] ?: 'Sin servicio') . ' · ' . ($t['categoria'] ?: 'General'), $t, $registrado, $respondido, $resuelto);
            $sumar($r['por_grupo'], $t['grupo_resolutor'] ?: 'Sin grupo', $t, $registrado, $respondido, $resuelto);
            $sumar($r['por_tecnico'], $t['nom_tecnico'] ?: 'Sin asignar', $t, $registrado, $respondido, $resuelto);
        }
    }
    unset($k);
    $pct = function ($parte, $total) { return $total > 0 ? round($parte * 100 / $total, 1) : null; };
    $kpi = &$r['kpi'];
    $kpi['cumplimiento_respuesta'] = $pct($kpi['resp_cumplidos'], $kpi['resp_medidos']);
    $kpi['cumplimiento_solucion'] = $pct($kpi['sol_cumplidos'], $kpi['resueltos']);
    $kpi['mtta'] = $kpi['resp_medidos'] ? (int) round($kpi['mtta_total'] / $kpi['resp_medidos']) : null;
    $kpi['mttr'] = $kpi['resueltos'] ? (int) round($kpi['mttr_total'] / $kpi['resueltos']) : null;
    $kpi['tasa_reapertura'] = $pct($kpi['reabiertos'], $kpi['resueltos']);
    $kpi['csat'] = $kpi['csat_n'] ? round($kpi['csat_total'] / $kpi['csat_n'], 2) : null;
    unset($kpi);
    foreach (['por_prioridad', 'por_tipo', 'por_servicio', 'por_grupo', 'por_tecnico'] as $grupo) {
        foreach ($r[$grupo] as $clave => $g) {
            $r[$grupo][$clave]['cumplimiento_respuesta'] = $pct($g['resp_cumplidos'], $g['resp_medidos']);
            $r[$grupo][$clave]['cumplimiento_solucion'] = $pct($g['sol_cumplidos'], $g['resueltos']);
            $r[$grupo][$clave]['mttr'] = $g['resueltos'] ? (int) round($g['mttr_total'] / $g['resueltos']) : null;
        }
    }
    uasort($r['por_servicio'], function ($a, $b) { return $b['registrados'] <=> $a['registrados']; });
    uasort($r['por_tecnico'], function ($a, $b) { return $b['resueltos'] <=> $a['resueltos']; });
    arsort($r['por_codigo_cierre']);

    // Tendencia de los 6 meses que terminan en el mes de corte
    $mes = new DateTime(substr($hasta, 0, 7) . '-01');
    $mes->modify('-5 months');
    for ($i = 0; $i < 6; $i++) {
        $a = $mes->format('Y-m-01 00:00:00');
        $b = $mes->format('Y-m-t 23:59:59');
        $fila = ['mes' => $mes->format('Y-m'), 'registrados' => 0, 'resueltos' => 0, 'cumplidos' => 0];
        foreach ($tickets as $t) {
            if ($en($t['created_at'], $a, $b)) $fila['registrados']++;
            if ($en($t['fecha_resolucion'], $a, $b) && $t['sla_resolucion_cumplido'] !== null) {
                $fila['resueltos']++;
                if ((int) $t['sla_resolucion_cumplido'] === 1) $fila['cumplidos']++;
            }
        }
        $fila['cumplimiento'] = $pct($fila['cumplidos'], $fila['resueltos']);
        $r['tendencia'][] = $fila;
        $mes->modify('+1 month');
    }

    $r['conclusiones'] = conclusionesReporteTic($r, $cfg);
    return $r;
}

function conclusionesReporteTic(array $r, array $cfg) {
    $k = $r['kpi'];
    $meta = (float) $cfg['meta_cumplimiento'];
    $p = function ($valor) { return number_format((float) $valor, 1, ',', '.') . '%'; };
    $c = [];
    if ($k['registrados'] === 0 && $k['resueltos'] === 0) return ['No hubo actividad de la mesa de servicios en el periodo.'];
    if ($k['cumplimiento_solucion'] !== null) {
        $c[] = $k['cumplimiento_solucion'] >= $meta
            ? 'El cumplimiento del ANS de solución fue ' . $p($k['cumplimiento_solucion']) . ', dentro de la meta de ' . $p($meta) . '.'
            : 'El cumplimiento del ANS de solución fue ' . $p($k['cumplimiento_solucion']) . ', por debajo de la meta de ' . $p($meta) . ': ' . count($r['incumplimientos']) . ' caso(s) se resolvieron fuera de tiempo.';
    }
    if ($k['cumplimiento_respuesta'] !== null && $k['cumplimiento_respuesta'] < $meta) {
        $c[] = 'La primera respuesta llegó a tiempo en el ' . $p($k['cumplimiento_respuesta']) . ' de los casos; conviene reforzar la atención inicial de la mesa.';
    }
    $peor = null;
    foreach ($r['por_prioridad'] as $prioridad => $g) {
        if ($g['cumplimiento_solucion'] !== null && ($peor === null || $g['cumplimiento_solucion'] < $r['por_prioridad'][$peor]['cumplimiento_solucion'])) $peor = $prioridad;
    }
    if ($peor !== null && $r['por_prioridad'][$peor]['cumplimiento_solucion'] < $meta) {
        $c[] = "La prioridad con menor cumplimiento fue {$peor} (" . $p($r['por_prioridad'][$peor]['cumplimiento_solucion']) . ').';
    }
    $diferencia = $k['registrados'] - $k['resueltos'];
    if ($diferencia > 0) $c[] = "Entraron $diferencia caso(s) más de los que se resolvieron: el backlog crece ({$k['backlog']} abiertos al corte).";
    elseif ($k['registrados'] > 0) $c[] = "Se resolvieron tantos o más casos de los que entraron: el backlog se mantiene controlado ({$k['backlog']} abiertos al corte).";
    if ($k['vencidos_abiertos'] > 0) $c[] = "{$k['vencidos_abiertos']} caso(s) abiertos ya superaron su ANS y requieren escalamiento.";
    if ($r['por_servicio']) {
        $servicio = array_keys($r['por_servicio'])[0];
        if ($r['por_servicio'][$servicio]['registrados'] > 0) $c[] = "El servicio con más casos fue «{$servicio}» ({$r['por_servicio'][$servicio]['registrados']}): candidato a análisis de problemas.";
    }
    if ($k['tasa_reapertura'] !== null && $k['tasa_reapertura'] > 10) $c[] = 'El ' . $p($k['tasa_reapertura']) . ' de los casos resueltos se reabrió: revisar la calidad de las soluciones.';
    if ($k['csat'] !== null) $c[] = 'La satisfacción de los clientes fue ' . number_format($k['csat'], 2, ',', '.') . " / 5 ({$k['csat_n']} calificación(es)).";
    return $c;
}
