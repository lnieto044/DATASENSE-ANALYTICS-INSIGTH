<?php
/**
 * FECHAS DE LA DEMOSTRACIÓN AL DÍA
 * Los datos de prueba se crearon entre enero y septiembre de 2026; sin esto, los tableros del mes actual salen vacíos.
 * Al arrancar el servidor (deploy/entrypoint.sh) mueve las fechas hacia adelante para que lo más reciente de cada
 * módulo quede en los últimos días: así el mes en curso muestra casos, PQRSD, mensajes, pagos y reuniones.
 *
 * - Solo corre con APP_ENV=demo (y se apaga con DEMO_FECHAS_ACTUALES=0). Nunca toca una base real.
 * - Cada módulo se mueve como un bloque, el mismo número de días: un pago sigue siendo posterior a su factura y la
 *   respuesta de un caso sigue siendo posterior a su apertura. Fechas límite y vencimientos se mueven con su registro.
 * - Solo mueve hacia adelante y se calcula sobre los datos que hay: si se corre dos veces el mismo día no cambia nada,
 *   y si alguien crea registros nuevos hoy, el módulo ya está al día y no se mueve.
 * - La primera vez corrige fechas de relleno del volcado (cierres de casos y aprobaciones fechados todos el 20 de
 *   septiembre), para que cada historia tenga un orden creíble.
 *
 * Uso: php cron/fechas_demo.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$db = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/itsm.php';   // ticketsItsm(): cumplimiento del ANS calculado igual que en la mesa

if (($_ENV['APP_ENV'] ?? '') !== 'demo' || ($_ENV['DEMO_FECHAS_ACTUALES'] ?? '1') === '0') {
    echo "Fechas de demostración: no aplica (solo con APP_ENV=demo)\n";
    exit;
}

function existeTabla(PDO $db, $tabla) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$tabla]);
    return (bool) $stmt->fetchColumn();
}

function columnasFecha(PDO $db, $tabla) {
    $stmt = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                          AND DATA_TYPE IN ('date', 'datetime', 'timestamp') ORDER BY ORDINAL_POSITION");
    $stmt->execute([$tabla]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ---------------------------------------------------------------- 1. Fechas de relleno del volcado (una sola vez)

// Casos: la primera respuesta (y otras gestiones) quedaron meses después de la apertura: van horas después de abrirse.
// En MariaDB las asignaciones de un UPDATE se aplican en orden, así que fecha_pausa usa la primera respuesta ya corregida.
// Las sumas con expresión van en DATE_ADD(): TiDB lee "+ INTERVAL (expresión)" como la función INTERVAL().
$db->exec("UPDATE soporte_tickets SET
        fecha_primera_respuesta = IF(fecha_primera_respuesta > created_at + INTERVAL 30 DAY, DATE_ADD(created_at, INTERVAL (1 + id % 6) HOUR), fecha_primera_respuesta),
        fecha_respuesta = IF(fecha_respuesta > created_at + INTERVAL 30 DAY, DATE_ADD(created_at, INTERVAL (1 + id % 6) HOUR), fecha_respuesta),
        fecha_pausa = IF(fecha_pausa > created_at + INTERVAL 30 DAY, COALESCE(fecha_primera_respuesta, created_at) + INTERVAL 1 HOUR, fecha_pausa)
    WHERE fecha_primera_respuesta > created_at + INTERVAL 30 DAY OR fecha_respuesta > created_at + INTERVAL 30 DAY
       OR fecha_pausa > created_at + INTERVAL 30 DAY");

// Casos: solución y cierre quedaron meses después de la apertura. Se ubican minutos después de la primera respuesta.
$casos_corregidos = $db->exec("UPDATE soporte_tickets SET
        fecha_resolucion = DATE_ADD(COALESCE(fecha_primera_respuesta, created_at), INTERVAL (30 + (id * 7) % 90) MINUTE),
        fecha_cierre = DATE_ADD(DATE_ADD(COALESCE(fecha_primera_respuesta, created_at), INTERVAL (30 + (id * 7) % 90) MINUTE),
                       INTERVAL (CASE WHEN estado LIKE '%Inactividad%' THEN 72 ELSE 20 END) HOUR),
        ultima_gestion_fecha = DATE_ADD(DATE_ADD(COALESCE(fecha_primera_respuesta, created_at), INTERVAL (30 + (id * 7) % 90) MINUTE),
                       INTERVAL (CASE WHEN estado LIKE '%Inactividad%' THEN 72 ELSE 20 END) HOUR)
    WHERE fecha_cierre IS NOT NULL AND fecha_cierre > created_at + INTERVAL 30 DAY");

// Con los tiempos corregidos, el cumplimiento del ANS guardado en cada caso se recalcula con la misma regla de la mesa
if ($casos_corregidos > 0) {
    $guardar = $db->prepare("UPDATE soporte_tickets SET sla_respuesta_cumplido = ?, sla_resolucion_cumplido = ? WHERE id = ?");
    foreach (ticketsItsm($db, 't.fecha_resolucion IS NOT NULL') as $caso) {
        if (empty($caso['sla'])) continue;
        $respuesta = $caso['sla']['respuesta']['cumplido'];
        $resolucion = $caso['sla']['resolucion']['cumplido'];
        $guardar->execute([$respuesta === null ? null : (int) $respuesta, $resolucion === null ? null : (int) $resolucion, $caso['id']]);
    }
}

// Historial de casos: cada evento en su momento del caso
$db->exec("UPDATE ticket_historial h JOIN soporte_tickets t ON t.id = h.ticket_id SET h.fecha = CASE
        WHEN h.evento = 'Primera respuesta' THEN COALESCE(t.fecha_primera_respuesta, t.created_at + INTERVAL 2 HOUR)
        WHEN h.evento IN ('Correo al cliente', 'Llamada al cliente') THEN COALESCE(t.fecha_primera_respuesta, t.created_at) + INTERVAL 1 HOUR
        WHEN h.evento = 'Cambio de estado' THEN DATE_ADD(COALESCE(t.fecha_primera_respuesta, t.created_at), INTERVAL (10 + h.id % 50) MINUTE)
        WHEN h.evento = 'Solución' THEN COALESCE(t.fecha_resolucion, t.created_at)
        ELSE COALESCE(t.fecha_cierre, t.created_at) END
    WHERE h.fecha > COALESCE(t.fecha_cierre, t.created_at) + INTERVAL 1 DAY OR h.fecha < t.created_at");

// Comprobantes: registrados y aprobados el día siguiente a su fecha, no meses después
$db->exec("UPDATE comprobantes SET created_at = fecha + INTERVAL 9 HOUR,
        fecha_revision = IF(fecha_revision IS NULL, NULL, fecha + INTERVAL 1 DAY + INTERVAL 10 HOUR),
        fecha_aprobacion = IF(fecha_aprobacion IS NULL, NULL, fecha + INTERVAL 1 DAY + INTERVAL 15 HOUR)
    WHERE created_at > fecha + INTERVAL 30 DAY OR fecha_aprobacion > fecha + INTERVAL 30 DAY");

// Asientos contables sueltos muy posteriores al último comprobante: van a la fecha de ese comprobante
$db->exec("UPDATE asientos_contables a JOIN (SELECT MAX(fecha) AS ultima FROM comprobantes) c
    SET a.fecha = c.ultima WHERE c.ultima IS NOT NULL AND a.fecha > c.ultima + INTERVAL 30 DAY");

// ---------------------------------------------------------------- 2. Mover cada módulo para que esté al día

/*
 * grupo => [tablas que se mueven juntas, consulta de la fecha más reciente de algo que YA pasó, margen en días]
 * Lo más reciente queda "margen" días antes de hoy. Las reuniones programadas y los plazos quedan en el futuro.
 */
$grupos = [
    'Mesa de servicios' => [['soporte_tickets', 'ticket_historial', 'ticket_correos', 'encuestas_respuestas'],
        "SELECT GREATEST(COALESCE(MAX(created_at), 0), COALESCE(MAX(fecha_cierre), 0), COALESCE(MAX(ultima_gestion_fecha), 0)) FROM soporte_tickets", 1],
    'Facturación y contabilidad' => [['facturas', 'comprobantes', 'asientos_contables', 'ingresos', 'egresos', 'pagos_registro',
                                      'pagos_auditoria', 'conciliaciones', 'extracto_movimientos'],
        "SELECT GREATEST(COALESCE((SELECT MAX(fecha_emision) FROM facturas), 0), COALESCE((SELECT MAX(fecha) FROM comprobantes), 0),
                         COALESCE((SELECT MAX(fecha_pago) FROM pagos_registro), 0), COALESCE((SELECT MAX(fecha) FROM asientos_contables), 0))", 1],
    'PQRSD' => [['pqrsd', 'pqrsd_historial'],
        "SELECT GREATEST(COALESCE((SELECT MAX(fecha_registro) FROM pqrsd), 0), COALESCE((SELECT MAX(fecha) FROM pqrsd_historial), 0))", 1],
    'Mensajes del sitio' => [['contactos'], "SELECT MAX(fecha_registro) FROM contactos", 1],
    'Chatbot' => [['ds_chat_sessions', 'ds_chat_messages'], "SELECT MAX(timestamp) FROM ds_chat_messages", 1],
    'Reuniones' => [['reuniones_virtuales'], "SELECT MAX(fecha) FROM reuniones_virtuales WHERE estado = 'Finalizada'", 1],
];

$hoy = new DateTime('today');
$resumen = [];
foreach ($grupos as $nombre => [$tablas, $consulta, $margen]) {
    $tablas = array_values(array_filter($tablas, function ($t) use ($db) { return existeTabla($db, $t); }));
    if (!$tablas) continue;
    $ultima = $db->query($consulta)->fetchColumn();
    if (!$ultima || strpos((string) $ultima, '0000') === 0) continue;
    $objetivo = (clone $hoy)->modify("-{$margen} day");
    $dias = (int) (new DateTime(substr((string) $ultima, 0, 10)))->diff($objetivo)->format('%r%a');
    if ($dias < 1) { $resumen[] = "$nombre: al día"; continue; }

    $db->beginTransaction();
    try {
        foreach ($tablas as $tabla) {
            $columnas = columnasFecha($db, $tabla);
            if (!$columnas) continue;
            // Todas las fechas de la tabla en una sola sentencia: así una columna "ON UPDATE CURRENT_TIMESTAMP" no se pisa
            $sets = implode(', ', array_map(function ($c) use ($dias) { return "`$c` = `$c` + INTERVAL $dias DAY"; }, $columnas));
            $db->exec("UPDATE `$tabla` SET $sets");
        }
        $db->commit();
        $resumen[] = "$nombre: +$dias días";
    } catch (Throwable $e) {
        $db->rollBack();
        $resumen[] = "$nombre: sin cambios (" . $e->getMessage() . ")";
    }
}

echo date('Y-m-d H:i:s') . ' Fechas de demostración: ' . implode('; ', $resumen) . "\n";
