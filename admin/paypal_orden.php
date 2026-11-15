<?php
// PayPal: crea la orden con el valor calculado en el servidor y la captura (real contra la API de PayPal, o simulada en modo local).
// Al capturar se registra el pago en USD y el comprobante de ingreso aprobado (neto en la billetera PayPal + comisión como gasto).
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pagos_internacionales.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'mis_facturas', 'api'); // También valida el token CSRF

header('Content-Type: application/json; charset=utf-8');
$responder = function ($codigo, array $datos) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $responder(405, ['error' => 'Método no permitido.']);

$factura_id = (int) ($_POST['factura_id'] ?? 0);
$accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

$stmt = $db->prepare("SELECT f.*, u.nombre, u.organizacion FROM facturas f JOIN usuarios u ON u.id = f.id_usuario WHERE f.id = ? AND f.id_usuario = ?");
$stmt->execute([$factura_id, $usuario['id']]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$factura || in_array($factura['estado'], ['Pagado', 'Anulada'], true)) $responder(404, ['error' => 'La factura no está disponible para pago.']);
if (!metodosInternacionalesDisponibles()['PayPal']) $responder(503, ['error' => 'PayPal no está configurado.']);
$trm = obtenerTRM($db);
if (!$trm) $responder(503, ['error' => 'No hay tasa de cambio disponible hoy.']);

$aplicado = aplicadoFactura($db, $factura_id);
$por_pagar = round((float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'], 2);

if ($accion === 'crear') {
    if ($por_pagar <= 0.5) $responder(409, ['error' => 'La factura no tiene saldo por pagar.']);
    $usd = copAUsd($por_pagar, $trm['valor']);
    try {
        $order_id = pagosEnModoLocal() ? 'SIMULADO-' . strtoupper(bin2hex(random_bytes(6))) : paypalCrearOrden($factura, $usd);
    } catch (Exception $e) {
        error_log('paypal_orden.php (crear): ' . $e->getMessage());
        $responder(502, ['error' => 'PayPal no respondió. Intenta de nuevo en unos minutos.']);
    }
    // Se recuerda la orden con su valor y su TRM: la captura no acepta montos enviados por el navegador
    $_SESSION['paypal_ordenes'][$order_id] = ['factura_id' => $factura_id, 'usd' => $usd, 'trm' => $trm['valor'], 'creada' => time()];
    $responder(200, ['id' => $order_id, 'usd' => $usd]);
}

if ($accion === 'capturar') {
    $order_id = is_string($_POST['order_id'] ?? null) ? $_POST['order_id'] : '';
    $orden = $_SESSION['paypal_ordenes'][$order_id] ?? null;
    if (!$orden || (int) $orden['factura_id'] !== $factura_id || time() - $orden['creada'] > 3 * 3600) {
        $responder(400, ['error' => 'La orden de pago no es válida o expiró. Vuelve a intentarlo.']);
    }
    unset($_SESSION['paypal_ordenes'][$order_id]); // Una orden solo se captura una vez
    // Si el saldo cambió desde que se creó la orden (otro pago), no se cobra un valor que ya no corresponde
    if (abs(copAUsd($por_pagar, $orden['trm']) - $orden['usd']) > 0.01) {
        $responder(409, ['error' => 'El saldo de la factura cambió. Recarga la página y vuelve a intentarlo.']);
    }

    try {
        $captura = pagosEnModoLocal()
            ? ['id' => 'SIM' . strtoupper(bin2hex(random_bytes(6))), 'usd' => $orden['usd'], 'comision_usd' => round($orden['usd'] * 0.054 + 0.30, 2), 'factura_id' => $factura_id]
            : paypalCapturar($order_id);
    } catch (Exception $e) {
        error_log('paypal_orden.php (capturar ' . $order_id . '): ' . $e->getMessage());
        $responder(502, ['error' => 'PayPal no confirmó el pago. No se registró ningún cobro.']);
    }
    if ($captura['factura_id'] !== $factura_id || abs($captura['usd'] - $orden['usd']) > 0.01) {
        error_log("paypal_orden.php: la captura {$captura['id']} no coincide con la orden $order_id (factura $factura_id, US$ {$orden['usd']})");
        $responder(409, ['error' => 'El valor confirmado por PayPal no coincide con la factura. Finanzas revisará el caso.']);
    }

    $bruto = round($captura['usd'] * $orden['trm'], 2);          // Lo que se aplica a la factura
    $comision = round($captura['comision_usd'] * $orden['trm'], 2); // Lo que se quedó PayPal
    $referencia = 'PAYPAL-' . $captura['id'];
    try {
        asegurarConsecutivo($db, 'CI', date('Y'));
        $db->beginTransaction();
        $db->prepare("SELECT id FROM facturas WHERE id = ? FOR UPDATE")->execute([$factura_id]);
        // Los USD se redondean hacia arriba al centavo: a la factura se abona como máximo su saldo y el excedente
        // (unos pesos) es ingreso por diferencia en cambio
        $aplicado = aplicadoFactura($db, $factura_id);
        $saldo = round((float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'], 2);
        if ($saldo <= 0.5) throw new RuntimeException('La factura ya no tiene saldo por pagar.');
        $diferencia = round($bruto - min($bruto, $saldo), 2);
        $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, referencia_transaccion, monto_pagado, moneda, monto_moneda, tasa_cambio, estado_transaccion, observaciones, fecha_pago)
                      VALUES (?, 'PayPal', ?, ?, 'USD', ?, ?, 'Aprobado', ?, NOW())")
           ->execute([$factura_id, $referencia, $bruto, $captura['usd'], $orden['trm'], "Orden $order_id. Comisión PayPal " . formatoUsd($captura['comision_usd']) . '.']);
        $pago_id = (int) $db->lastInsertId();
        $comprobante = registrarIngresoFactura($db, $factura, [
            'fecha' => date('Y-m-d'), 'metodo' => 'PayPal', 'referencia' => $referencia,
            'valor' => round($bruto - $comision, 2), 'comision' => $comision, 'diferencia_cambio' => $diferencia,
            'estado' => 'Aprobado', 'origen' => 'Pasarela', 'usuario' => null, 'pago_id' => $pago_id,
            'concepto' => 'Pago PayPal de la factura ' . $factura['numero_factura'] . ' - ' . formatoUsd($captura['usd']) . ' a TRM ' . number_format($orden['trm'], 2, ',', '.'),
        ]);
        $estado = recalcularFactura($db, $factura_id);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        // El dinero ya se capturó en PayPal: se deja rastro para que Finanzas lo registre a mano
        error_log("paypal_orden.php: PAGO CAPTURADO SIN REGISTRAR $referencia (factura $factura_id, US$ {$captura['usd']}): " . $e->getMessage());
        $responder(500, ['error' => 'PayPal recibió tu pago, pero no pudimos registrarlo. Finanzas lo aplicará; guarda la referencia ' . $referencia . '.']);
    }
    $responder(200, ['ok' => true, 'comprobante' => $comprobante['numero'], 'estado' => $estado, 'redirigir' => 'mis_facturas.php?msg=pago_exitoso']);
}

$responder(400, ['error' => 'Acción no válida.']);
