<?php
// 1. Conexión a Base de Datos (Sin sesiones, acceso directo del sistema)
$db = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/contabilidad.php';

// 2. Token de MercadoPago: se configura en .env como MP_ACCESS_TOKEN (nunca escrito en el código)
$access_token = $_ENV['MP_ACCESS_TOKEN'] ?? '';
if ($access_token === '') {
    error_log('webhook_pagos.php: falta MP_ACCESS_TOKEN en .env');
    http_response_code(503); // MercadoPago reintentará cuando la pasarela esté configurada
    die("Pasarela no configurada");
}

// 3. Capturar la notificación que envía el Banco (JSON Payload)
$json_event = file_get_contents('php://input');
$event = json_decode($json_event, true);

// MercadoPago puede enviar el ID por URL (GET) o dentro del cuerpo JSON
$payment_id = null;

if (isset($_GET['topic']) && $_GET['topic'] == 'payment') {
    $payment_id = $_GET['id'] ?? null;
} elseif (isset($event['type']) && $event['type'] == 'payment') {
    $payment_id = $event['data']['id'] ?? null;
}

// Si no hay un ID de pago numérico, terminamos pero le decimos al banco que todo está "OK" para que no moleste
if (!is_scalar($payment_id) || !preg_match('/^\d+$/', (string) $payment_id)) {
    http_response_code(200);
    die("No es un evento de pago");
}

// ========================================================================
// 4. PREGUNTA DE SEGURIDAD: Vamos a MP a confirmar el estado real del pago
// ========================================================================
$ch = curl_init("https://api.mercadopago.com/v1/payments/" . $payment_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // MercadoPago espera la respuesta unos 22 segundos antes de reintentar
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token
]);
$response = curl_exec($ch);
$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error_curl = $response === false ? curl_error($ch) : '';
curl_close($ch);

// El pago no existe en MercadoPago (aviso de prueba o ID inventado): no hay nada que registrar ni que reintentar
if ($http_code === 404) {
    http_response_code(200);
    die("Pago no encontrado");
}

// No se pudo confirmar el pago (MercadoPago sin responder, sin red, token vencido...): se responde con error para
// que MercadoPago reintente el aviso más tarde. Responder 200 aquí dejaría el pago sin registrar.
$payment_info = $http_code === 200 ? json_decode((string) $response, true) : null;
if (!is_array($payment_info)) {
    error_log("webhook_pagos.php: no se pudo confirmar el pago $payment_id (HTTP $http_code" . ($error_curl !== '' ? ", $error_curl" : '') . ')');
    http_response_code(502);
    die("No se pudo confirmar el pago");
}

// Si MercadoPago nos responde correctamente
if ($http_code === 200) {

    // Extraemos los datos clave que nos da el banco
    $status      = $payment_info['status'] ?? ''; // Puede ser: 'approved', 'pending', 'rejected'
    $factura_id  = (int) ($payment_info['external_reference'] ?? 0); // El ID de nuestra factura (La pieza clave)
    $monto_real  = round((float) ($payment_info['transaction_amount'] ?? 0), 2);
    $metodo_pago = strtoupper((string) ($payment_info['payment_method_id'] ?? 'MP')); // Ej: 'pse', 'efecty', 'master'
    $tipo_pago   = strtolower((string) ($payment_info['payment_type_id'] ?? ''));

    // ========================================================================
    // 5. LÓGICA DE NEGOCIO: registrar el pago y contabilizarlo
    // ========================================================================
    $stmt_fac = $db->prepare("SELECT f.*, u.nombre, u.organizacion FROM facturas f JOIN usuarios u ON u.id = f.id_usuario WHERE f.id = ?");
    $stmt_fac->execute([$factura_id]);
    $factura = $stmt_fac->fetch(PDO::FETCH_ASSOC);

    if ($factura) {

        // A) PAGO APROBADO (dinero en cuenta): comprobante de ingreso aprobado por el valor realmente recibido.
        //    Si cubre el saldo la factura queda Pagada; si es menor, queda como abono y conserva el saldo.
        if ($status === 'approved' && $monto_real > 0) {

            // MercadoPago notifica varias veces el mismo pago: solo se contabiliza una vez
            $ya_registrado = $db->prepare("SELECT COUNT(*) FROM pagos_registro p JOIN comprobantes c ON c.pago_id = p.id AND c.estado <> 'Anulado' WHERE p.referencia_transaccion = ?");
            $ya_registrado->execute([(string) $payment_id]);

            if (!$ya_registrado->fetchColumn()) {
                $metodo_comprobante = in_array($tipo_pago, ['credit_card', 'debit_card', 'prepaid_card'], true) ? 'Tarjeta' : 'PSE';
                $nota_auditoria = "Pago online confirmado por MercadoPago ($metodo_pago). Ref MP: " . $payment_id;
                try {
                    asegurarConsecutivo($db, 'CI', date('Y'));
                    $db->beginTransaction();
                    $db->prepare("SELECT id FROM facturas WHERE id = ? FOR UPDATE")->execute([$factura_id]);

                    // Segunda comprobación, ya con la factura bloqueada: MercadoPago puede enviar dos avisos del mismo pago
                    // a la vez. Si el otro se registró mientras este esperaba el bloqueo, no se contabiliza de nuevo.
                    $ya_registrado->execute([(string) $payment_id]);
                    if ($ya_registrado->fetchColumn()) {
                        $db->rollBack();
                    } else {
                        // Reutilizamos el registro de esta misma transacción o el intento pendiente creado al ir a la pasarela
                        $stmt_check = $db->prepare("SELECT id FROM pagos_registro WHERE factura_id = ? AND (referencia_transaccion = ? OR estado_transaccion = 'Pendiente_Validacion') ORDER BY (referencia_transaccion = ?) DESC, id DESC LIMIT 1");
                        $stmt_check->execute([$factura_id, $payment_id, $payment_id]);
                        $pago_id = (int) $stmt_check->fetchColumn();

                        if ($pago_id) {
                            $db->prepare("UPDATE pagos_registro SET estado_transaccion = 'Aprobado', metodo_pago = ?, referencia_transaccion = ?, monto_pagado = ?, observaciones = ? WHERE id = ?")
                               ->execute([$metodo_pago, $payment_id, $monto_real, $nota_auditoria, $pago_id]);
                        } else {
                            $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, referencia_transaccion, monto_pagado, estado_transaccion, observaciones) VALUES (?, ?, ?, ?, 'Aprobado', ?)")
                               ->execute([$factura_id, $metodo_pago, $payment_id, $monto_real, $nota_auditoria]);
                            $pago_id = (int) $db->lastInsertId();
                        }

                        registrarIngresoFactura($db, $factura, [
                            'fecha' => date('Y-m-d'), 'metodo' => $metodo_comprobante, 'referencia' => (string) $payment_id, 'valor' => $monto_real,
                            'estado' => 'Aprobado', 'origen' => 'Pasarela', 'usuario' => null, 'pago_id' => $pago_id,
                        ]);
                        recalcularFactura($db, $factura_id);
                        $db->commit();
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('webhook_pagos.php: ' . $e->getMessage());
                    http_response_code(500); // MercadoPago reintentará la notificación
                    exit('Error al registrar el pago');
                }
            }
        }

        // B) SI EL PAGO FUE RECHAZADO (Fondos insuficientes, tarjeta bloqueada, etc)
        elseif ($status === 'rejected') {

            // Solo actualizamos el historial de pagos para que Finanzas sepa que falló, la factura sigue sin pagar
            $stmt_check = $db->prepare("SELECT id FROM pagos_registro WHERE factura_id = ? AND estado_transaccion = 'Pendiente_Validacion' ORDER BY id DESC LIMIT 1");
            $stmt_check->execute([$factura_id]);

            if ($registro = $stmt_check->fetch(PDO::FETCH_ASSOC)) {
                $stmt_upd = $db->prepare("UPDATE pagos_registro SET estado_transaccion = 'Rechazado', observaciones = 'Pago rechazado por el banco (Fondos insuficientes o bloqueo)' WHERE id = ?");
                $stmt_upd->execute([$registro['id']]);
                recalcularFactura($db, $factura_id);
            }
        }
    }
}

// 6. FUNDAMENTAL: Siempre responder 200 OK al banco para finalizar la conexión
http_response_code(200);
echo "Webhook recibido y procesado OK";
