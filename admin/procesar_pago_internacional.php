<?php
// Pagos internacionales reportados por el cliente (Zelle, Western Union, USDT): quedan "Pendiente de validación"
// hasta que Finanzas los valide en Facturación (se crea el comprobante de ingreso) o los rechace con un motivo.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pagos_internacionales.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'mis_facturas');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: mis_facturas.php');
    exit;
}

$factura_id = (int) ($_POST['factura_id'] ?? 0);
$metodo = is_string($_POST['metodo'] ?? null) ? $_POST['metodo'] : '';
$pestana = in_array($_POST['pestana'] ?? '', ['zelle', 'wu', 'usdt'], true) ? $_POST['pestana'] : 'paypal';
$volver = 'pagos_internacionales.php?id=' . $factura_id . '&metodo=' . $pestana;
$rechazar = function ($texto) use ($volver) {
    avisoSiguiente('danger', $texto);
    header('Location: ' . $volver);
    exit;
};

// La factura debe ser del cliente y tener saldo por pagar
$stmt = $db->prepare("SELECT f.*, COALESCE(a.aprobado, 0) AS aprobado, COALESCE(a.en_tramite, 0) AS en_tramite
                      FROM facturas f LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
                      WHERE f.id = ? AND f.id_usuario = ?");
$stmt->execute([$factura_id, $usuario['id']]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$factura || in_array($factura['estado'], ['Pagado', 'Anulada'], true) || (float) $factura['monto_total'] - (float) $factura['aprobado'] - (float) $factura['en_tramite'] <= 0.5) {
    header('Location: mis_facturas.php?msg=pago_no_disponible');
    exit;
}

if (!in_array($metodo, METODOS_REPORTADOS, true) || !metodosInternacionalesDisponibles()[$metodo]) {
    $rechazar('Ese método de pago no está disponible.');
}
list($referencia, $error_referencia) = validarReferenciaInternacional($metodo, $_POST['referencia'] ?? '');
if ($error_referencia) $rechazar($error_referencia);

$monto = filter_var($_POST['monto_usd'] ?? '', FILTER_VALIDATE_FLOAT);
if ($monto === false || $monto <= 0 || $monto > 1000000) $rechazar('Escribe el valor que enviaste (mayor que cero).');

$trm = obtenerTRM($db);
if (!$trm) $rechazar('Hoy no hay tasa de cambio disponible. Intenta más tarde.');
if (pagoInternacionalPendiente($db, $factura_id)) $rechazar('Ya tienes un pago reportado en validación para esta factura.');

// Comprobante: obligatorio en Zelle y Western Union; en USDT el hash es la prueba y la captura es opcional
$archivo = null;
$directorio = __DIR__ . '/../uploads/pagos/';
$subido = $_FILES['comprobante'] ?? null;
if ($subido && $subido['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($subido['error'] !== UPLOAD_ERR_OK || $subido['size'] > 5 * 1024 * 1024) $rechazar('No se pudo recibir el comprobante (máximo 5 MB).');
    $ext = strtolower(pathinfo((string) $subido['name'], PATHINFO_EXTENSION));
    $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($subido['tmp_name']) : null;
    $tipos = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    // Se revisa la extensión y también el contenido real del archivo
    if (!isset($tipos[$ext]) || ($mime !== null && $mime !== $tipos[$ext])) $rechazar('El comprobante debe ser una imagen JPG o PNG, o un PDF.');
    if (!is_dir($directorio)) mkdir($directorio, 0755, true);
    $archivo = 'PAGO_' . $factura_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($subido['tmp_name'], $directorio . $archivo)) $rechazar('No se pudo guardar el comprobante. Intenta de nuevo.');
} elseif ($metodo !== 'Criptomonedas (USDT)') {
    $rechazar('Adjunta el comprobante del pago.');
}

$moneda = $metodo === 'Criptomonedas (USDT)' ? 'USDT' : 'USD';
$valor_cop = round($monto * $trm['valor'], 2);
try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, referencia_transaccion, monto_pagado, moneda, monto_moneda, tasa_cambio, estado_transaccion, soporte_visual, observaciones, fecha_pago)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente_Validacion', ?, ?, NOW())")
       ->execute([
           $factura_id, $metodo, $referencia, $valor_cop, $moneda, round($monto, 2), $trm['valor'], $archivo,
           'Reportado por el cliente: ' . number_format($monto, 2, ',', '.') . " $moneda a TRM " . number_format($trm['valor'], 2, ',', '.') . '. Pendiente de validar por Finanzas.',
       ]);
    recalcularFactura($db, $factura_id); // La factura queda "En Proceso"
    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($archivo) @unlink($directorio . $archivo);
    if (($e->errorInfo[1] ?? 0) == 1062) $rechazar('Esa referencia ya fue reportada en otro pago. Verifica el código.');
    error_log('procesar_pago_internacional.php: ' . $e->getMessage());
    $rechazar('No se pudo registrar el pago. Intenta de nuevo.');
}

header('Location: mis_facturas.php?msg=pago_reportado');
exit;
