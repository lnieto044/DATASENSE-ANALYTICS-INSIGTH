<?php
// Flujo de los comprobantes: revisar (Finanzas), aprobar (Administrador) y anular (con motivo)
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereSesion($db); // También valida el token CSRF del POST

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . paginaInicio($usuario['rol'], $db));
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$accion = (string) ($_POST['accion'] ?? '');
$motivo = trim((string) ($_POST['motivo'] ?? ''));

$stmt = $db->prepare("SELECT tipo FROM comprobantes WHERE id = ?");
$stmt->execute([$id]);
$tipo = $stmt->fetchColumn();
if (!$tipo) {
    avisoSiguiente('warning', 'El comprobante no existe.');
    header('Location: ' . paginaInicio($usuario['rol'], $db));
    exit;
}
$cfg = TIPOS_COMPROBANTE[$tipo];
if (!tieneAcceso($usuario['rol'], $cfg['modulo'], $db)) {
    denegarAcceso('pagina', 403);
}
$destino = $cfg['pagina'] . (($_POST['volver'] ?? '') === 'listado' ? '' : '?id=' . $id);

try {
    $db->beginTransaction();
    // El bloqueo evita que dos personas aprueben o anulen el mismo comprobante a la vez
    $bloqueo = $db->prepare("SELECT * FROM comprobantes WHERE id = ? FOR UPDATE");
    $bloqueo->execute([$id]);
    $comp = $bloqueo->fetch(PDO::FETCH_ASSOC);

    $error = errorFlujoComprobante($comp, $usuario, $accion);
    if ($error === null) $error = errorPeriodoCerrado($db, $comp['fecha']);
    if ($error === null && $comp['tipo'] === 'CC') $error = 'Los comprobantes de cierre se generan y se anulan desde Cierre Contable.';
    // Una cuenta por pagar con pagos activos no se anula: primero se anulan sus egresos
    if ($error === null && $accion === 'anular' && $comp['tipo'] === 'FP') {
        $pagos = $db->prepare("SELECT GROUP_CONCAT(numero SEPARATOR ', ') FROM comprobantes WHERE tipo = 'CE' AND origen_id = ? AND estado <> 'Anulado'");
        $pagos->execute([$id]);
        $lista_pagos = $pagos->fetchColumn();
        if ($lista_pagos) $error = "Tiene pagos registrados ($lista_pagos): anúlalos primero.";
    }
    // Lo que ya se concilió con el extracto del banco no se anula sin desconciliarlo antes
    if ($error === null && $accion === 'anular') {
        $conciliado = $db->prepare("SELECT CONCAT(k.cuenta, ' de ', k.periodo, ' (', k.estado, ')')
                                    FROM extracto_movimientos e
                                    JOIN comprobantes_detalle d ON d.id = e.detalle_id
                                    JOIN conciliaciones k ON k.id = e.conciliacion_id
                                    WHERE d.comprobante_id = ? LIMIT 1");
        $conciliado->execute([$id]);
        $conciliacion = $conciliado->fetchColumn();
        if ($conciliacion) $error = "Está conciliado en la conciliación bancaria $conciliacion: desconcílialo primero.";
    }
    if ($error === null && $accion === 'anular' && mb_strlen($motivo) < 10) {
        $error = 'Escribe el motivo de la anulación (mínimo 10 caracteres).';
    }
    // Un egreso (o una nota que descuente caja o bancos) no se aprueba si no hay saldo para cubrirlo
    if ($error === null && $accion === 'aprobar' && in_array($tipo, ['CE', 'NC'], true)) {
        $salidas = $db->prepare("SELECT codigo_puc, SUM(credito - debito) FROM comprobantes_detalle WHERE comprobante_id = ? AND codigo_puc LIKE '11%' GROUP BY codigo_puc");
        $salidas->execute([$id]);
        foreach ($salidas->fetchAll(PDO::FETCH_KEY_PAIR) as $cuenta => $valor) {
            $saldo = saldoCuenta($db, (string) $cuenta);
            if ((float) $valor > $saldo + 0.005) {
                $error = 'Saldo insuficiente en ' . (nombresCuentas($db)[$cuenta] ?? $cuenta) . ' (' . dinero($saldo) . ') para aprobar un pago de ' . dinero($valor) . '.';
            }
        }
    }
    if ($error !== null) {
        $db->rollBack();
        avisoSiguiente('danger', $comp['numero'] . ': ' . $error);
        header('Location: ' . $destino);
        exit;
    }

    if ($accion === 'revisar') {
        $db->prepare("UPDATE comprobantes SET estado = 'Revisado', revisado_por = ?, fecha_revision = NOW() WHERE id = ?")->execute([$usuario['id'], $id]);
        $mensaje = "Revisión de {$comp['numero']} firmada. Queda pendiente de la aprobación de un Administrador.";
    } elseif ($accion === 'aprobar') {
        $db->prepare("UPDATE comprobantes SET estado = 'Aprobado', aprobado_por = ?, fecha_aprobacion = NOW() WHERE id = ?")->execute([$usuario['id'], $id]);
        if ($comp['pago_id']) {
            $db->prepare("UPDATE pagos_registro SET estado_transaccion = 'Aprobado' WHERE id = ?")->execute([$comp['pago_id']]);
        }
        $mensaje = "{$comp['numero']} aprobado: ya afecta la contabilidad.";
    } else {
        $db->prepare("UPDATE comprobantes SET estado = 'Anulado', anulado_por = ?, fecha_anulacion = NOW(), motivo_anulacion = ? WHERE id = ?")
           ->execute([$usuario['id'], mb_substr($motivo, 0, 255), $id]);
        if ($comp['pago_id']) {
            // La referencia bancaria queda libre por si el pago se vuelve a registrar en la factura correcta
            $db->prepare("UPDATE pagos_registro
                          SET estado_transaccion = 'Anulado',
                              observaciones = CONCAT(COALESCE(observaciones, ''), ' | Anulado con ', ?, ' (ref. ', COALESCE(referencia_transaccion, '-'), ')'),
                              referencia_transaccion = NULL
                          WHERE id = ?")->execute([$comp['numero'], $comp['pago_id']]);
        }
        $mensaje = "{$comp['numero']} anulado.";
    }

    if ($comp['factura_id']) {
        $mensaje .= ' Estado de la factura: ' . recalcularFactura($db, $comp['factura_id']) . '.';
    }
    $db->commit();
    avisoSiguiente('success', $mensaje);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('procesar_comprobante.php: ' . $e->getMessage());
    avisoSiguiente('danger', 'No se pudo completar la acción. Intenta de nuevo.');
}

header('Location: ' . $destino);
exit;
