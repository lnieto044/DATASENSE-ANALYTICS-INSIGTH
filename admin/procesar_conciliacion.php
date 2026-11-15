<?php
// Registro de recaudos en Facturación: un pago recibido con su soporte, o la validación de un pago internacional
// que reportó el cliente (Zelle, Western Union, USDT). Se crea el comprobante de ingreso en estado Pendiente y la factura
// queda "En Proceso" hasta que Finanzas lo revise y un Administrador lo apruebe.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'facturacion');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: facturacion.php");
    exit;
}

// Método del formulario => forma de pago del comprobante (los nombres antiguos siguen funcionando)
$metodos_conciliacion = [
    'Efectivo_Banco' => 'Consignación', 'Transferencia' => 'Transferencia', 'Nequi_Daviplata' => 'Transferencia', 'PSE' => 'PSE', 'Tarjeta' => 'Tarjeta',
    'PayPal' => 'PayPal', 'Zelle' => 'Zelle', 'Western Union' => 'Western Union', 'Criptomonedas (USDT)' => 'Criptomonedas (USDT)',
];
$texto = function ($campo) { return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : ''; };
$opcional = function ($campo) { $valor = $_POST[$campo] ?? ''; return filter_var($valor === '' ? '0' : $valor, FILTER_VALIDATE_FLOAT); };
$ir = function ($parametros) { header('Location: facturacion.php?' . $parametros); exit; };

$factura_id = (int) ($_POST['factura_id'] ?? 0);
$pago_reportado_id = (int) ($_POST['pago_id'] ?? 0);
$metodo = $texto('metodo');
$referencia = $texto('referencia');
$monto = filter_var($_POST['monto_real'] ?? '', FILTER_VALIDATE_FLOAT);
$retencion = $opcional('retencion');
$comision = $opcional('comision');
$nota = $texto('nota_admin') !== '' ? $texto('nota_admin') : 'Conciliación manual con soporte bancario.';

$stmt = $db->prepare("SELECT f.*, u.nombre, u.organizacion FROM facturas f JOIN usuarios u ON u.id = f.id_usuario WHERE f.id = ?");
$stmt->execute([$factura_id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$factura || in_array($factura['estado'], ['Pagado', 'Anulada'], true)) $ir('error=factura_no_disponible');

// Validación de un pago reportado: se usan su método y su referencia, no los del formulario
$pago_reportado = null;
if ($pago_reportado_id) {
    $stmt_p = $db->prepare("SELECT p.* FROM pagos_registro p
                            WHERE p.id = ? AND p.factura_id = ? AND p.estado_transaccion = 'Pendiente_Validacion'
                              AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado')");
    $stmt_p->execute([$pago_reportado_id, $factura_id]);
    $pago_reportado = $stmt_p->fetch(PDO::FETCH_ASSOC);
    if (!$pago_reportado) $ir('error=pago_no_disponible');
    $metodo = $pago_reportado['metodo_pago'];
    $referencia = (string) $pago_reportado['referencia_transaccion'];
}

if (!isset($metodos_conciliacion[$metodo]) || $referencia === '' || mb_strlen($referencia) > 100 || $monto === false || $monto <= 0
    || $retencion === false || $retencion < 0 || $comision === false || $comision < 0) {
    $ir('error=datos_invalidos');
}

// 1. SOPORTE: obligatorio, salvo al validar un pago reportado que ya trae el suyo o que se verifica con el hash (USDT)
$hay_archivo = isset($_FILES['soporte_archivo']) && $_FILES['soporte_archivo']['error'] !== UPLOAD_ERR_NO_FILE;
$soporte_opcional = $pago_reportado && ($pago_reportado['soporte_visual'] || $pago_reportado['metodo_pago'] === 'Criptomonedas (USDT)');
if (!$hay_archivo && !$soporte_opcional) $ir('error=archivo_faltante');

$directorio = __DIR__ . "/../uploads/pagos/";
$nombre_archivo = null;
if ($hay_archivo) {
    if ($_FILES['soporte_archivo']['error'] !== UPLOAD_ERR_OK) $ir('error=archivo_faltante');
    $ext = strtolower(pathinfo($_FILES['soporte_archivo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) $ir('error=formato_invalido');
    if (!is_dir($directorio)) { mkdir($directorio, 0755, true); }
    $nombre_archivo = "PAGO_" . $factura_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    if (!move_uploaded_file($_FILES['soporte_archivo']['tmp_name'], $directorio . $nombre_archivo)) $ir('error=sistema_db');
}
$borrar_soporte = function () use ($nombre_archivo, $directorio) {
    if ($nombre_archivo) @unlink($directorio . $nombre_archivo); // El soporte no quedó asociado a ningún pago
};

// 2. PAGO + COMPROBANTE DE INGRESO EN UNA SOLA TRANSACCIÓN
try {
    asegurarConsecutivo($db, 'CI', date('Y'));
    $db->beginTransaction();

    // Bloqueo de la factura: dos registros simultáneos no pueden pasarse del saldo
    $db->prepare("SELECT id FROM facturas WHERE id = ? FOR UPDATE")->execute([$factura_id]);
    $aplicado = aplicadoFactura($db, $factura_id);
    $disponible = (float) $factura['monto_total'] - $aplicado['aprobado'] - $aplicado['en_tramite'];
    if ($monto + $retencion + $comision > $disponible + 0.5) {
        $db->rollBack();
        $borrar_soporte();
        $ir('error=monto_excede');
    }

    if ($pago_reportado) {
        $db->prepare("UPDATE pagos_registro
                      SET monto_pagado = ?, soporte_visual = COALESCE(?, soporte_visual),
                          observaciones = CONCAT(COALESCE(observaciones, ''), ' | Validado por ', ?, ': ', ?)
                      WHERE id = ?")
           ->execute([$monto, $nombre_archivo, $usuario['nombre'], $nota, $pago_reportado['id']]);
        $pago_id = (int) $pago_reportado['id'];
    } else {
        // Si el cliente ya había generado un recibo en efectivo, se completa ese intento en lugar de duplicarlo
        $pendiente = $db->prepare("SELECT p.id FROM pagos_registro p
                                   WHERE p.factura_id = ? AND p.estado_transaccion = 'Pendiente_Validacion'
                                     AND p.metodo_pago NOT IN ('" . implode("', '", METODOS_REPORTADOS) . "')
                                     AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado')
                                   ORDER BY p.id DESC LIMIT 1");
        $pendiente->execute([$factura_id]);
        $pago_id = (int) $pendiente->fetchColumn();
        if ($pago_id) {
            $db->prepare("UPDATE pagos_registro SET metodo_pago = ?, referencia_transaccion = ?, monto_pagado = ?, observaciones = ?, soporte_visual = ?, fecha_pago = NOW() WHERE id = ?")
               ->execute([$metodo, $referencia, $monto, $nota, $nombre_archivo, $pago_id]);
        } else {
            $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, referencia_transaccion, monto_pagado, observaciones, soporte_visual, estado_transaccion) VALUES (?, ?, ?, ?, ?, ?, 'Pendiente_Validacion')")
               ->execute([$factura_id, $metodo, $referencia, $monto, $nota, $nombre_archivo]);
            $pago_id = (int) $db->lastInsertId();
        }
    }

    $comprobante = registrarIngresoFactura($db, $factura, [
        'fecha' => date('Y-m-d'), 'metodo' => $metodos_conciliacion[$metodo], 'referencia' => $referencia,
        'valor' => $monto, 'retencion' => $retencion, 'comision' => $comision,
        'estado' => 'Pendiente', 'origen' => $pago_reportado ? 'Pago reportado' : 'Conciliación', 'usuario' => $usuario['id'], 'pago_id' => $pago_id,
    ]);
    recalcularFactura($db, $factura_id);
    $db->commit();

    $ir('msg=recaudo_registrado&ci=' . urlencode($comprobante['numero']));
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    $borrar_soporte();
    if (($e->errorInfo[1] ?? 0) == 1062) $ir('error=ref_duplicada&ref=' . urlencode($referencia));
    error_log('procesar_conciliacion.php: ' . $e->getMessage());
    $ir('error=sistema_db');
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $borrar_soporte();
    error_log('procesar_conciliacion.php: ' . $e->getMessage());
    $ir('error=sistema_db');
}
