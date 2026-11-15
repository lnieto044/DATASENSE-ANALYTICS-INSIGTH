<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'mis_facturas');
require_once __DIR__ . '/../includes/utilidades.php';
require_once __DIR__ . '/../includes/contabilidad.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['action'])) {
    header("Location: mis_facturas.php"); exit;
}

$id_usuario = $_SESSION['admin_id'];
$factura_id = (int) ($_POST['factura_id'] ?? $_GET['id_fac'] ?? 0);
$tipo_pago = $_POST['tipo_pago'] ?? 'online'; 

if (!$factura_id) { die("Error: No se identificó la factura."); }

// =======================================================================================
// ⚙️ EL INTERRUPTOR MAESTRO (LOCAL vs PRODUCCION)
// =======================================================================================
// Se configura en .env: PAGOS_ENTORNO=LOCAL activa el simulador. Si no está definido, solo se simula con APP_ENV=local;
// en cualquier otro caso se usa la pasarela real (así el simulador nunca queda activo por olvido en el hosting).
$entorno_configurado = strtoupper($_ENV['PAGOS_ENTORNO'] ?? (strtolower($_ENV['APP_ENV'] ?? '') === 'local' ? 'LOCAL' : 'PRODUCCION'));
$ENTORNO = ($entorno_configurado === 'LOCAL') ? 'LOCAL' : 'PRODUCCION';

// Configuración para cuando esté en PRODUCCIÓN (Dinero Real)
$MP_ACCESS_TOKEN = $_ENV['MP_ACCESS_TOKEN'] ?? ''; 
$URL_SISTEMA_REAL = urlBase() . 'admin'; // En producción toma APP_URL de .env 
// =======================================================================================

// Buscar los datos de la factura
$stmt = $db->prepare("SELECT f.*, u.nombre, u.correo, u.telefono, u.organizacion FROM facturas f JOIN usuarios u ON f.id_usuario = u.id WHERE f.id = ? AND f.id_usuario = ?");
$stmt->execute([$factura_id, $id_usuario]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) { die("Error de seguridad: Factura no encontrada."); }
if ($factura['estado'] === 'Anulada') { header("Location: mis_facturas.php"); exit; }

// Lo que falta por pagar: total menos recaudos aprobados y los que ya están en aprobación (evita pagar dos veces)
$aplicado = aplicadoFactura($db, $factura_id);
$por_pagar = round((float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'], 2);


// =======================================================================================
// 🟢 MODO LOCAL: EL SIMULADOR INTERACTIVO (XAMPP)
// =======================================================================================
if ($ENTORNO === 'LOCAL') {
    
    // Si viene del modal inicial, mostrar la pantalla de selección
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // El intento solo se registra cuando el cliente elige una opción del simulador: cancelar ya no deja la factura "En Proceso"
        if ($factura['estado'] === 'Pagado') { header("Location: mis_facturas.php?msg=pago_exitoso"); exit; }
        if ($por_pagar <= 0.5) { header("Location: mis_facturas.php?msg=pago_pendiente"); exit; }
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8"><title>Pasarela Segura (Simulador)</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
            <style>body{background:#f3f4f6}.checkout-box{max-width:600px;margin:50px auto;background:#fff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);overflow:hidden}.method-btn{border:1px solid #e5e7eb;border-radius:8px;padding:15px;display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;text-decoration:none;color:inherit;transition:0.2s}.method-btn:hover{border-color:#0d6efd;background:#f8fbff;transform:translateY(-2px)}</style>
        </head>
        <body>
            <div class="checkout-box">
                <div class="bg-dark text-white p-3 text-center"><h5 class="mb-0"><i class="fas fa-vial me-2"></i> Simulador de Pagos (XAMPP)</h5></div>
                <div class="p-4">
                    <div class="text-center mb-4"><p class="text-muted mb-1">Total a pagar</p><h1 class="fw-bold">$ <?= number_format(max(0, $por_pagar), 0, ',', '.') ?></h1></div>
                    <?php if($tipo_pago == 'online'): ?>
                        <a href="?action=simular_exito&id_fac=<?= $factura_id ?>&metodo=PSE" class="method-btn"><div><i class="fas fa-university text-primary fa-lg me-3"></i><span class="fw-bold">Simular Pago PSE Exitoso</span></div><i class="fas fa-chevron-right text-muted"></i></a>
                        <a href="?action=simular_exito&id_fac=<?= $factura_id ?>&metodo=Tarjeta" class="method-btn"><div><i class="fas fa-credit-card text-success fa-lg me-3"></i><span class="fw-bold">Simular Pago con Tarjeta Exitoso</span></div><i class="fas fa-chevron-right text-muted"></i></a>
                    <?php else: ?>
                        <a href="?action=simular_recibo&id_fac=<?= $factura_id ?>&metodo=Bancolombia" class="method-btn"><div><i class="fas fa-barcode text-dark fa-lg me-3"></i><span class="fw-bold">Generar Recibo de Banco</span></div><i class="fas fa-download text-primary"></i></a>
                    <?php endif; ?>
                    <div class="text-center mt-3"><a href="mis_facturas.php" class="text-danger fw-bold text-decoration-none">Cancelar</a></div>
                </div>
            </div>
        </body></html>
        <?php
        exit;
    }

    // Acciones del simulador (Éxito o Recibo)
    if (isset($_GET['action'])) {
        if ($_GET['action'] == 'simular_exito') {
            // La pasarela confirma el dinero: el recaudo se contabiliza aprobado y la factura se recalcula
            $metodo_simulado = ($_GET['metodo'] ?? '') === 'Tarjeta' ? 'Tarjeta' : 'PSE';
            try {
                asegurarConsecutivo($db, 'CI', date('Y'));
                $db->beginTransaction();
                $db->prepare("SELECT id FROM facturas WHERE id = ? FOR UPDATE")->execute([$factura_id]);
                $aplicado = aplicadoFactura($db, $factura_id);
                $por_pagar = round((float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'], 2);
                if ($por_pagar > 0.5) {
                    // Se completa el intento pendiente del cliente (recibo en efectivo) o se registra la transacción
                    $pendiente = $db->prepare("SELECT p.id FROM pagos_registro p WHERE p.factura_id = ? AND p.estado_transaccion = 'Pendiente_Validacion'
                                                 AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado') ORDER BY p.id DESC LIMIT 1");
                    $pendiente->execute([$factura_id]);
                    $pago_id = (int) $pendiente->fetchColumn();
                    if ($pago_id) {
                        $db->prepare("UPDATE pagos_registro SET metodo_pago = 'Simulador', monto_pagado = ?, estado_transaccion = 'Aprobado', observaciones = 'Simulación exitosa', fecha_pago = NOW() WHERE id = ?")->execute([$por_pagar, $pago_id]);
                    } else {
                        $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, monto_pagado, estado_transaccion, observaciones, fecha_pago) VALUES (?, 'Simulador', ?, 'Aprobado', 'Simulación exitosa', NOW())")->execute([$factura_id, $por_pagar]);
                        $pago_id = (int) $db->lastInsertId();
                    }
                    $db->prepare("UPDATE pagos_registro SET referencia_transaccion = ? WHERE id = ? AND referencia_transaccion IS NULL")->execute(['SIM-' . $pago_id, $pago_id]);
                    registrarIngresoFactura($db, $factura, [
                        'fecha' => date('Y-m-d'), 'metodo' => $metodo_simulado, 'referencia' => 'SIM-' . $pago_id, 'valor' => $por_pagar,
                        'estado' => 'Aprobado', 'origen' => 'Pasarela', 'usuario' => null, 'pago_id' => $pago_id,
                    ]);
                    recalcularFactura($db, $factura_id);
                }
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('procesar_pago_online.php (simulador): ' . $e->getMessage());
                header("Location: mis_facturas.php?msg=pago_fallido"); exit;
            }
            header("Location: mis_facturas.php?msg=pago_exitoso"); exit;
        } 
        elseif ($_GET['action'] == 'simular_recibo') {
            $metodo_banco = is_string($_GET['metodo'] ?? null) ? $_GET['metodo'] : 'Entidad Bancaria';

            // El recibo en efectivo queda "En Proceso" hasta que Finanzas concilie el pago (sin duplicar intentos pendientes)
            $pendiente = $db->prepare("SELECT COUNT(*) FROM pagos_registro WHERE factura_id = ? AND estado_transaccion = 'Pendiente_Validacion'");
            $pendiente->execute([$factura_id]);
            if ($factura['estado'] !== 'Pagado' && $por_pagar > 0.5 && !$pendiente->fetchColumn()) {
                $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, monto_pagado, estado_transaccion, fecha_pago) VALUES (?, 'Recibo_Banco', ?, 'Pendiente_Validacion', NOW())")->execute([$factura_id, $por_pagar]);
                recalcularFactura($db, $factura_id); // Queda "En Proceso" hasta que Finanzas concilie el pago
            }
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <title>Comprobante de Recaudo - <?= htmlspecialchars($factura['numero_factura']) ?></title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
                <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap" rel="stylesheet">
                <style>
                    body { background: #e9ecef; font-family: 'Segoe UI', sans-serif; }
                    .receipt-wrapper { max-width: 750px; margin: 40px auto; background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-top: 10px solid #0A2E4F; }
                    .barcode { font-family: 'Libre Barcode 39', cursive; font-size: 65px; text-align: center; color: #000; line-height: 1; margin-top: 15px;}
                    .section-title { font-size: 0.85rem; font-weight: bold; color: #6c757d; text-transform: uppercase; border-bottom: 2px solid #eee; padding-bottom: 5px; margin-bottom: 15px; }
                    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
                    .total-box { background: #f8f9fa; border-left: 5px solid #198754; padding: 20px; border-radius: 0 8px 8px 0; }
                    
                    /* Magia para que imprima perfecto en 1 sola página */
                    @media print {
                        body { background: #fff; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                        .receipt-wrapper { box-shadow: none; margin: 0; width: 100%; max-width: 100%; border: none; padding: 15px; }
                        .no-print { display: none !important; }
                        @page { size: auto; margin: 10mm; }
                    }
                </style>
            </head>
            <body>
                <div class="receipt-wrapper">
                    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                        <div>
                            <h3 class="fw-bold text-dark mb-0">COMPROBANTE DE RECAUDO</h3>
                            <span class="badge bg-secondary">Válido para pago en caja</span>
                        </div>
                        <div class="text-end">
                            <h2 class="fw-bold text-primary mb-0"><i class="fas fa-university"></i> <?= htmlspecialchars($metodo_banco) ?></h2>
                        </div>
                    </div>

                    <div class="info-grid mb-4">
                        <div>
                            <div class="section-title">Datos del Recaudador (Empresa)</div>
                            <h6 class="fw-bold text-dark mb-1">DataSense Analytics</h6>
                            <div class="small text-muted mb-1"><strong>NIT:</strong> 900.123.456-7</div>
                            <div class="small text-muted mb-1"><strong>Cuenta de Ahorros:</strong> 567 - 786902 - 55</div>
                            <div class="small text-muted mb-1"><strong>Convenio N°:</strong> 884422</div>
                        </div>

                        <div>
                            <div class="section-title">Datos del Pagador (Cliente)</div>
                            <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($factura['nombre']) ?></h6>
                            <div class="small text-muted mb-1"><strong>Empresa:</strong> <?= htmlspecialchars($factura['organizacion'] ?: 'Independiente') ?></div>
                            <div class="small text-muted mb-1"><strong>Correo:</strong> <?= htmlspecialchars($factura['correo']) ?></div>
                            <div class="small text-muted mb-1"><strong>Referencia:</strong> <?= htmlspecialchars($factura['numero_factura']) ?></div>
                        </div>
                    </div>

                    <div class="total-box mb-4">
                        <div class="row align-items-center">
                            <div class="col-sm-6">
                                <div class="small text-muted text-uppercase fw-bold mb-1">Concepto del pago:</div>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($factura['servicio_contratado']) ?></div>
                                <div class="small text-danger mt-2 fw-bold"><i class="fas fa-calendar-times"></i> Pagar antes de: <?= date('d/m/Y', strtotime($factura['fecha_vencimiento'])) ?></div>
                            </div>
                            <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                                <div class="small text-muted text-uppercase fw-bold mb-1">Total a Pagar:</div>
                                <h1 class="fw-bold text-success mb-0">$ <?= number_format(max(0, $por_pagar), 0, ',', '.') ?></h1>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-5 pt-3 border-top">
                        <div class="small text-muted fw-bold mb-2">ESPACIO EXCLUSIVO PARA EL CAJERO / LECTOR ÓPTICO</div>
                        
                        <?php $codigo_limpio = str_replace('-', '', $factura['numero_factura']); ?>
                        <div class="barcode">*FAC<?= $codigo_limpio ?>*</div>
                        <div class="text-dark fw-bold mb-1" style="font-family: monospace; letter-spacing: 2px;">(415)77099980(8020)FAC<?= $codigo_limpio ?></div>
                        
                        <p class="small text-muted mt-3">
                            Señor cajero: Por favor escanear el código de barras o digitar la referencia y el número de convenio manualmente.<br>
                            El pago debe realizarse únicamente en efectivo o cheque de gerencia.
                        </p>
                    </div>

                    <div class="mt-5 text-center no-print">
                        <button onclick="window.print()" class="btn btn-primary px-5 py-2 fw-bold shadow-sm me-2">
                            <i class="fas fa-print me-2"></i> IMPRIMIR RECIBO
                        </button>
                        <a href="mis_facturas.php" class="btn btn-outline-secondary px-4 py-2">
                            Volver a Mis Facturas
                        </a>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}


// =======================================================================================
// 🔴 MODO PRODUCCIÓN: CONEXIÓN REAL AL BANCO / MERCADOPAGO (Dinero Real)
// =======================================================================================
if ($ENTORNO === 'PRODUCCION') {
    
    if ($MP_ACCESS_TOKEN === '') {
        error_log('procesar_pago_online.php: falta MP_ACCESS_TOKEN en .env');
        die("<h3>La pasarela de pagos no está configurada.</h3><p>Contacta a DataSense para completar tu pago.</p>");
    }

    $preference_data = [
        "items" => [
            [
                "id" => "FAC-" . $factura_id,
                "title" => "Pago de Factura " . $factura['numero_factura'],
                "quantity" => 1,
                "currency_id" => "COP",
                "unit_price" => $por_pagar
            ]
        ],
        "payer" => [
            "name" => $factura['nombre'],
            "email" => $factura['correo']
        ],
        "back_urls" => [
            "success" => $URL_SISTEMA_REAL . "/mis_facturas.php?msg=pago_exitoso",
            "failure" => $URL_SISTEMA_REAL . "/mis_facturas.php?msg=pago_fallido",
            "pending" => $URL_SISTEMA_REAL . "/mis_facturas.php?msg=pago_pendiente"
        ],
        "auto_return" => "approved",
        "external_reference" => $factura_id, 
        "notification_url" => $URL_SISTEMA_REAL . "/webhook_pagos.php" 
    ];

    if ($tipo_pago === 'efectivo') {
        $preference_data["payment_methods"] = [
            "excluded_payment_types" => [ ["id" => "credit_card"], ["id" => "debit_card"] ]
        ];
    }

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preference_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $MP_ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 || $http_code == 201) {
        $data = json_decode($response, true);
        $url_checkout = $data['init_point']; 

        $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, monto_pagado, estado_transaccion, fecha_pago) VALUES (?, 'Pasarela_Real', ?, 'Pendiente_Validacion', NOW())")->execute([$factura_id, $por_pagar]);
        recalcularFactura($db, $factura_id);

        header("Location: " . $url_checkout);
        exit;
    } else {
        die("<h3>Error crítico del servidor bancario.</h3>");
    }
}
?>