<?php
/**
 * Datos en vivo de un caso: ANS calculado por el servidor (mismo motor que guarda los tiempos) e historial.
 * - Mesa de servicios (módulo gestion_it): cualquier caso, historial completo y simulación de reclasificación
 *   (?impacto=Alto&urgencia=Media muestra cómo quedaría el ANS con esa prioridad).
 * - Cliente: solo sus casos y los eventos públicos (nunca la traza de correos ni las notas internas).
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/itsm.php';

$db = require __DIR__ . '/../config/database.php';
$usuario = requiereSesion($db, 'api');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$filas = ticketsItsm($db, 't.id = ?', [(int) ($_GET['id'] ?? 0)]);
$t = $filas ? $filas[0] : null;
$es_mesa = tieneAcceso($usuario['rol'], 'gestion_it', $db);
if (!$t || !$t['sla'] || (!$es_mesa && (int) $t['usuario_id'] !== (int) $usuario['id'])) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

$respuesta = [
    'ok' => true,
    'codigo' => $t['codigo_ticket'],
    'estado' => $t['estado'],
    'comportamiento' => $t['comportamiento'],
    'prioridad' => $t['prioridad'],
    'sla' => $t['sla'],
    'historial' => historialTickets($db, [(int) $t['id']], !$es_mesa)[(int) $t['id']] ?? [],
];

$impacto = (string) ($_GET['impacto'] ?? '');
$urgencia = (string) ($_GET['urgencia'] ?? '');
if ($es_mesa && isset(MATRIZ_PRIORIDAD[$impacto][$urgencia])) {
    $prioridad = prioridadDe($impacto, $urgencia);
    list($respuesta_min, $resolucion_min, $calendario) = objetivoSla($db, $t['tipo'], $prioridad);
    $respuesta['simulacion'] = [
        'prioridad' => $prioridad,
        'sla' => slaTicket(array_merge($t, ['sla_respuesta_min' => $respuesta_min, 'sla_resolucion_min' => $resolucion_min, 'calendario' => $calendario]), configItsm($db)),
    ];
}

// Flujo completo del caso (?flujo=1); la mesa recibe además el contacto y los casos anteriores del cliente
if (($_GET['flujo'] ?? '') === '1') {
    $respuesta['flujo'] = flujoTicket($db, $t, configItsm($db), !$es_mesa);
    if ($es_mesa) {
        $stmt = $db->prepare("SELECT id, codigo_ticket, asunto, estado, prioridad, created_at FROM soporte_tickets WHERE usuario_id = ? ORDER BY created_at DESC");
        $stmt->execute([(int) $t['usuario_id']]);
        $casos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $abiertos = 0;
        foreach ($casos as $caso) {
            if (!in_array(comportamientoEstado($db, $caso['estado']), [COMP_SOLUCIONADO, COMP_CERRADO], true)) $abiertos++;
        }
        $otros = array_values(array_filter($casos, function ($caso) use ($t) { return (int) $caso['id'] !== (int) $t['id']; }));
        $respuesta['cliente'] = [
            'nombre' => $t['cliente'], 'correo' => $t['correo_cliente'], 'telefono' => $t['telefono_cliente'], 'organizacion' => $t['organizacion_cliente'],
            'total_casos' => count($casos), 'abiertos' => $abiertos, 'casos' => array_slice($otros, 0, 8), 'whatsapp' => numeroWhatsapp($t['telefono_cliente']),
        ];
        // Traza de los correos que recibió el cliente en este caso (solo la mesa y la supervisión)
        $respuesta['correos'] = correosTicket($db, (int) $t['id']);
    }
}

echo jsJson($respuesta);
