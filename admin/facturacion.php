<?php
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/pagos_internacionales.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD DE ACCESO
$usuario_actual = requiereAcceso($db, 'facturacion');

// ================================================================
// LÓGICA DE PROCESAMIENTO (CREAR Y ANULAR FACTURAS DE VENTA)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    if ($_POST['accion'] === 'crear_factura') {
        $id_usuario = (int) ($_POST['id_usuario'] ?? 0);
        $id_servicio = (int) ($_POST['id_servicio'] ?? 0);
        $subtotal = filter_var($_POST['subtotal'] ?? '', FILTER_VALIDATE_FLOAT);
        $iva = filter_var($_POST['porcentaje_iva'] ?? '', FILTER_VALIDATE_FLOAT);
        $emision = is_string($_POST['fecha_emision'] ?? null) ? $_POST['fecha_emision'] : '';
        $vence = is_string($_POST['fecha_vencimiento'] ?? null) ? $_POST['fecha_vencimiento'] : '';

        $cliente = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND rol = 'Cliente'");
        $cliente->execute([$id_usuario]);
        // El nombre del servicio se toma del catálogo (no del formulario) y queda congelado en la factura
        $servicio = $db->prepare("SELECT nombre FROM servicios_catalogo WHERE id = ? AND estado = 'Activo'");
        $servicio->execute([$id_servicio]);
        $nombre_servicio = $servicio->fetchColumn();

        $error = null;
        if (!$cliente->fetchColumn()) $error = 'Elige un cliente registrado.';
        elseif (!$nombre_servicio) $error = 'Elige un servicio activo del catálogo.';
        elseif ($subtotal === false || $subtotal <= 0) $error = 'El subtotal debe ser mayor que cero.';
        elseif ($iva === false || $iva < 0 || $iva > 100) $error = 'El IVA debe estar entre 0 y 100%.';
        elseif (!fechaValida($emision) || $emision > date('Y-m-d')) $error = 'La fecha de emisión debe ser válida y no puede ser futura.';
        elseif (!fechaValida($vence) || $vence < $emision) $error = 'La fecha de vencimiento no puede ser anterior a la de emisión.';
        elseif (periodoCerrado($db, $emision)) $error = errorPeriodoCerrado($db, $emision);

        if ($error !== null) {
            avisoSiguiente('danger', 'No se creó la factura: ' . $error);
            header("Location: facturacion.php");
            exit;
        }

        try {
            $anio = (int) substr($emision, 0, 4);
            asegurarConsecutivo($db, 'FAC', $anio);
            $db->beginTransaction();
            // El número se asigna dentro de la transacción: dos personas facturando a la vez reciben números distintos
            do {
                $numero = siguienteConsecutivo($db, 'FAC', $anio);
                $existe = $db->prepare("SELECT COUNT(*) FROM facturas WHERE numero_factura = ?");
                $existe->execute([$numero]);
            } while ($existe->fetchColumn()); // Salta números usados fuera del sistema (por ejemplo, cargados a mano)

            $total = round($subtotal * (1 + $iva / 100), 2); // Total recalculado en el servidor
            $db->prepare("INSERT INTO facturas (id_usuario, id_servicio, numero_factura, servicio_contratado, fecha_emision, fecha_vencimiento, subtotal, porcentaje_iva, monto_total, estado, visto)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
               ->execute([$id_usuario, $id_servicio, $numero, $nombre_servicio, $emision, $vence, round($subtotal, 2), $iva, $total, $vence < date('Y-m-d') ? 'Vencido' : 'Pendiente']);
            $db->commit();
            avisoSiguiente('success', "Factura $numero creada por " . dinero($total) . '.');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('facturacion.php (crear): ' . $e->getMessage());
            avisoSiguiente('danger', 'No se pudo crear la factura. Intenta de nuevo.');
        }
        header("Location: facturacion.php");
        exit;
    }

    if ($_POST['accion'] === 'anular_factura') {
        $id = (int) ($_POST['id_factura'] ?? 0);
        $motivo = is_string($_POST['motivo'] ?? null) ? trim($_POST['motivo']) : '';
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT id, numero_factura, estado, fecha_emision FROM facturas WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $factura = $stmt->fetch(PDO::FETCH_ASSOC);
            $activos = $db->prepare("SELECT GROUP_CONCAT(numero SEPARATOR ', ') FROM comprobantes WHERE factura_id = ? AND estado <> 'Anulado'");
            $activos->execute([$id]);
            $comprobantes_activos = $activos->fetchColumn();

            $error = null;
            if (!$factura) $error = 'La factura no existe.';
            elseif ($factura['estado'] === 'Anulada') $error = 'La factura ya está anulada.';
            elseif ($factura['estado'] === 'Pagado') $error = 'Una factura pagada no se puede anular.';
            elseif ($comprobantes_activos) $error = "Tiene recaudos o notas registrados ($comprobantes_activos): anúlalos primero.";
            elseif (periodoCerrado($db, $factura['fecha_emision'])) $error = errorPeriodoCerrado($db, $factura['fecha_emision']);
            elseif (mb_strlen($motivo) < 10) $error = 'Escribe el motivo de la anulación (mínimo 10 caracteres).';

            if ($error !== null) {
                $db->rollBack();
                avisoSiguiente('danger', 'No se anuló la factura: ' . $error);
            } else {
                $db->prepare("UPDATE facturas SET estado = 'Anulada', motivo_anulacion = ?, anulada_por = ?, fecha_anulacion = NOW() WHERE id = ?")
                   ->execute([mb_substr($motivo, 0, 255), $usuario_actual['id'], $id]);
                // Los intentos de pago del cliente que quedaron abiertos pierden efecto
                $db->prepare("UPDATE pagos_registro SET estado_transaccion = 'Anulado' WHERE factura_id = ? AND estado_transaccion = 'Pendiente_Validacion'")->execute([$id]);
                $db->commit();
                avisoSiguiente('success', "Factura {$factura['numero_factura']} anulada: ya no suma en la cartera ni en la contabilidad.");
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('facturacion.php (anular): ' . $e->getMessage());
            avisoSiguiente('danger', 'No se pudo anular la factura. Intenta de nuevo.');
        }
        header("Location: facturacion.php");
        exit;
    }

    // Rechazar un pago internacional reportado que no se pudo verificar (no llegó, valor o referencia errados...)
    if ($_POST['accion'] === 'rechazar_pago') {
        $pago_id = (int) ($_POST['pago_id'] ?? 0);
        $motivo = is_string($_POST['motivo'] ?? null) ? trim($_POST['motivo']) : '';
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT p.id, p.factura_id, p.referencia_transaccion FROM pagos_registro p
                                  WHERE p.id = ? AND p.estado_transaccion = 'Pendiente_Validacion'
                                    AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado')
                                  FOR UPDATE");
            $stmt->execute([$pago_id]);
            $pago = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pago) {
                $db->rollBack();
                avisoSiguiente('danger', 'Ese pago ya no está pendiente de validación.');
            } elseif (mb_strlen($motivo) < 10) {
                $db->rollBack();
                avisoSiguiente('danger', 'Escribe el motivo del rechazo (mínimo 10 caracteres): el cliente lo verá.');
            } else {
                // La referencia queda libre por si el cliente reporta de nuevo el mismo pago con los datos correctos
                $db->prepare("UPDATE pagos_registro SET estado_transaccion = 'Rechazado', referencia_transaccion = NULL,
                                     observaciones = CONCAT(COALESCE(observaciones, ''), ' | Ref. ', COALESCE(referencia_transaccion, '-'), ' | Rechazado: ', ?)
                              WHERE id = ?")->execute([mb_substr($motivo, 0, 255), $pago_id]);
                recalcularFactura($db, $pago['factura_id']);
                $db->commit();
                avisoSiguiente('success', "Pago {$pago['referencia_transaccion']} rechazado. El cliente verá el motivo en Mis Facturas.");
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('facturacion.php (rechazar pago): ' . $e->getMessage());
            avisoSiguiente('danger', 'No se pudo rechazar el pago. Intenta de nuevo.');
        }
        header("Location: facturacion.php");
        exit;
    }

    // TRM registrada a mano (cuando la fuente oficial no responde o para fijar la del día)
    if ($_POST['accion'] === 'guardar_trm') {
        $valor = filter_var($_POST['trm'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($valor === false || $valor < 1000 || $valor > 20000) {
            avisoSiguiente('danger', 'La TRM debe estar entre 1.000 y 20.000 pesos por dólar.');
        } else {
            guardarTRM($db, date('Y-m-d'), $valor, 'Registrada por ' . $usuario_actual['nombre']);
            avisoSiguiente('success', 'TRM de hoy registrada: ' . dinero($valor, 2) . ' por dólar.');
        }
        header("Location: facturacion.php");
        exit;
    }
}

// ================================================================
// CONSULTAS PARA LA INTERFAZ
// ================================================================
actualizarVencimientos($db);

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = "Control de Facturación";
$subtitulo = "Facturas de venta, cartera y recaudos";
$aviso = tomarAviso();
$puede_notas = tieneAcceso($usuario_actual['rol'], 'notas_factura', $db);

// KPIs sobre saldos reales: lo recaudado con comprobantes aprobados y lo que falta por cobrar
$aplicado_sql = sqlAplicadoFacturas();
$kpis = $db->query("SELECT
        COALESCE(SUM(COALESCE(a.recaudado, 0)), 0) AS recaudado,
        COALESCE(SUM(CASE WHEN f.estado <> 'Pagado' AND f.fecha_vencimiento >= CURDATE() THEN GREATEST(f.monto_total - COALESCE(a.aprobado, 0), 0) ELSE 0 END), 0) AS pendiente,
        COALESCE(SUM(CASE WHEN f.estado <> 'Pagado' AND f.fecha_vencimiento < CURDATE() THEN GREATEST(f.monto_total - COALESCE(a.aprobado, 0), 0) ELSE 0 END), 0) AS vencido
    FROM facturas f
    LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
    WHERE f.estado <> 'Anulada'")->fetch(PDO::FETCH_ASSOC);
$total_recaudado = (float) $kpis['recaudado'];
$total_pendiente = (float) $kpis['pendiente'];
$total_vencido   = (float) $kpis['vencido'];

// LISTA DE USUARIOS CON DATOS DEL CRM
// Un cliente puede tener varios leads enlazados: se toma el más reciente para no repetirlo en el selector
$clientes_lista = $db->query("
    SELECT u.id, u.nombre, u.organizacion,
           (SELECT c.servicio FROM contactos c WHERE c.id_usuario_final = u.id ORDER BY c.id DESC LIMIT 1) AS servicio_interes
    FROM usuarios u
    WHERE u.rol = 'Cliente'
    ORDER BY u.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// CATÁLOGO DE SERVICIOS PARA EL SELECTOR
$catalogo_servicios = $db->query("
    SELECT id, nombre as nombre_servicio, precio_base, impuesto_porcentaje
    FROM servicios_catalogo
    WHERE estado = 'Activo'
    ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// CONSECUTIVO: solo informativo; el número definitivo se asigna al guardar
$nuevo_numero_factura = consecutivoPropuesto($db, 'FAC', date('Y'));

// Pagos que los clientes reportaron desde el exterior y esperan validación
$pagos_reportados = $db->query("SELECT p.*, f.numero_factura, f.monto_total, u.nombre AS cliente_nombre, COALESCE(NULLIF(u.organizacion, ''), u.nombre) AS cliente,
                                       COALESCE(a.aprobado, 0) AS aprobado, COALESCE(a.en_tramite, 0) AS en_tramite
                                FROM pagos_registro p
                                JOIN facturas f ON f.id = p.factura_id
                                JOIN usuarios u ON u.id = f.id_usuario
                                LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
                                WHERE p.estado_transaccion = 'Pendiente_Validacion' AND p.metodo_pago IN ('" . implode("', '", METODOS_REPORTADOS) . "')
                                  AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado')
                                ORDER BY p.fecha_pago")->fetchAll(PDO::FETCH_ASSOC);
$trm = obtenerTRM($db);

// LISTADO DE FACTURAS
$buscar = is_string($_GET['buscar'] ?? null) ? $_GET['buscar'] : '';
// El lead de origen sale de una subconsulta (el más antiguo) para no repetir facturas cuando el cliente tiene varios leads
$sql = "SELECT f.*,
               u.nombre as cliente_nombre,
               u.organizacion,
               u.correo,
               (SELECT c.nombre FROM contactos c WHERE c.id_usuario_final = u.id ORDER BY c.id ASC LIMIT 1) as prospecto_original,
               COALESCE(a.aprobado, 0) AS aprobado,
               COALESCE(a.en_tramite, 0) AS en_tramite,
               COALESCE(a.recaudado, 0) AS recaudado,
               COALESCE(a.notas_credito, 0) AS notas_credito,
               COALESCE(a.notas_debito, 0) AS notas_debito
        FROM facturas f
        LEFT JOIN usuarios u ON f.id_usuario = u.id
        LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
        WHERE 1=1";
$params = [];

if ($buscar) {
    $sql .= " AND (f.numero_factura LIKE ? OR u.nombre LIKE ? OR u.organizacion LIKE ? OR f.servicio_contratado LIKE ? OR EXISTS (SELECT 1 FROM contactos c WHERE c.id_usuario_final = u.id AND c.nombre LIKE ?))";
    $params = array_fill(0, 5, "%$buscar%");
}
$sql .= " ORDER BY f.visto ASC, f.fecha_emision DESC, f.id DESC";
$stmt_f = $db->prepare($sql);
$stmt_f->execute($params);
$facturas = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

$clases_estado = [
    'Pagado'     => 'text-success border-success bg-success bg-opacity-10',
    'Pendiente'  => 'text-warning border-warning bg-warning bg-opacity-10',
    'En Proceso' => 'text-info border-info bg-info bg-opacity-10',
    'Anulada'    => 'text-secondary border-secondary bg-secondary bg-opacity-10',
    'Vencido'    => 'text-danger border-danger bg-danger bg-opacity-10',
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturación | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php
            $tipo_campana = 'facturas';
            include '../includes/header.php';
        ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
                <i class="fas <?= $aviso['tipo'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i> <?= esc($aviso['texto']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                <i class="fas fa-check-circle me-2"></i>
                <?php if ($_GET['msg'] === 'recaudo_registrado'): ?>
                    Recaudo registrado<?= isset($_GET['ci']) ? ' con el comprobante <b>' . esc($_GET['ci']) . '</b>' : '' ?>.
                    La factura queda <b>En Proceso</b> hasta que Finanzas revise y un Administrador apruebe el comprobante.
                <?php else: ?>
                    Operación realizada con éxito.
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php
        // Motivos de rechazo que devuelve procesar_conciliacion.php
        $errores_conciliacion = [
            'formato_invalido'      => 'Formato de archivo no permitido: el soporte de pago debe ser una imagen (JPG o PNG) o un PDF.',
            'archivo_faltante'      => 'Adjunta el soporte del pago para poder conciliarlo.',
            'ref_duplicada'         => 'Esa referencia bancaria ya está registrada en otro pago' . (isset($_GET['ref']) ? ' (' . esc($_GET['ref']) . ')' : '') . '.',
            'monto_excede'          => 'El valor del recaudo (más la retención) supera el saldo por recaudar de la factura.',
            'factura_no_disponible' => 'La factura ya está pagada o anulada: no admite más recaudos.',
            'datos_invalidos'       => 'Revisa el método, la referencia y el valor recibido.',
            'pago_no_disponible'    => 'Ese pago reportado ya fue validado o rechazado.',
            'sistema_db'            => 'No se pudo registrar el pago. Intenta de nuevo.',
        ];
        ?>
        <?php if (isset($errores_conciliacion[$_GET['error'] ?? ''])): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                <i class="fas fa-exclamation-triangle me-2"></i> <?= $errores_conciliacion[$_GET['error']] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-12 col-md-4">
                <div class="card card-kpi p-4 border-0 border-bottom border-4 border-success shadow-sm h-100">
                    <small class="text-muted fw-bold d-block mb-1 text-uppercase">Recaudo Confirmado</small>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0 text-success" data-kpi="recaudado"><?= dinero($total_recaudado) ?></h3>
                        <i class="fas fa-check-double fa-2x text-success opacity-25"></i>
                    </div>
                    <small class="text-muted">Comprobantes de ingreso aprobados</small>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-kpi p-4 border-0 border-bottom border-4 border-warning shadow-sm h-100">
                    <small class="text-muted fw-bold d-block mb-1 text-uppercase">Saldo por Cobrar (al día)</small>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0 text-warning" data-kpi="pendiente"><?= dinero($total_pendiente) ?></h3>
                        <i class="fas fa-hourglass-half fa-2x text-warning opacity-25"></i>
                    </div>
                    <small class="text-muted">Facturas que aún no vencen</small>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-kpi p-4 border-0 border-bottom border-4 border-danger shadow-sm h-100">
                    <small class="text-muted fw-bold d-block mb-1 text-uppercase">Cartera Vencida</small>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0 text-danger" data-kpi="vencido"><?= dinero($total_vencido) ?></h3>
                        <i class="fas fa-exclamation-circle fa-2x text-danger opacity-25"></i>
                    </div>
                    <small class="text-muted">Saldo de facturas vencidas</small>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
            <div class="small text-muted" id="trmActual">
                <i class="fas fa-globe-americas me-1"></i> Tasa de cambio USD (TRM):
                <?php if ($trm): ?>
                    <b class="text-dark"><?= dinero($trm['valor'], 2) ?></b> · <?= date('d/m/Y', strtotime($trm['fecha'])) ?> · <?= esc($trm['fuente']) ?>
                    <?php if (!$trm['vigente']): ?><span class="badge bg-warning text-dark ms-1">No es la de hoy</span><?php endif; ?>
                <?php else: ?>
                    <span class="badge bg-danger">Sin TRM: los pagos internacionales no se pueden calcular</span>
                <?php endif; ?>
            </div>
            <form method="POST" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="accion" value="guardar_trm">
                <input type="number" name="trm" class="form-control form-control-sm rounded-pill" style="width: 150px;" min="1000" max="20000" step="0.01" placeholder="TRM de hoy" required>
                <button type="submit" class="btn btn-sm btn-outline-dark rounded-pill px-3">Registrar TRM</button>
            </form>
        </div>

        <?php if ($pagos_reportados): ?>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4" id="pagosReportados">
            <div class="card-header bg-warning bg-opacity-10 border-0 py-3 px-4 fw-bold">
                <i class="fas fa-globe-americas text-warning me-2"></i> Pagos internacionales reportados por clientes, pendientes de validar (<?= count($pagos_reportados) ?>)
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light"><tr><th class="ps-4">FACTURA / CLIENTE</th><th>MÉTODO Y REFERENCIA</th><th>VALOR REPORTADO</th><th>REPORTADO</th><th class="text-center">GESTIÓN</th></tr></thead>
                    <tbody>
                    <?php foreach ($pagos_reportados as $p):
                        $disponible_pago = max(0, round((float) $p['monto_total'] - (float) $p['aprobado'] - (float) $p['en_tramite'], 2));
                        $hash = $p['metodo_pago'] === 'Criptomonedas (USDT)' ? substr((string) $p['referencia_transaccion'], 5) : '';
                    ?>
                        <tr id="pago-reportado-<?= (int) $p['id'] ?>">
                            <td class="ps-4"><div class="fw-bold"><?= esc($p['numero_factura']) ?></div><div class="small text-muted"><?= esc($p['cliente']) ?></div></td>
                            <td>
                                <span class="badge bg-info-subtle text-info border border-info"><?= esc($p['metodo_pago']) ?></span>
                                <div class="small font-monospace text-truncate" style="max-width: 230px;" title="<?= esc($p['referencia_transaccion']) ?>"><?= esc($p['referencia_transaccion']) ?></div>
                                <?php if ($hash !== ''): ?><a class="small" href="https://tronscan.org/#/transaction/<?= esc($hash) ?>" target="_blank" rel="noopener">Verificar en Tronscan <i class="fas fa-external-link-alt"></i></a><?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold"><?= number_format((float) $p['monto_moneda'], 2, ',', '.') ?> <?= esc($p['moneda']) ?></div>
                                <div class="small text-muted">≈ <?= dinero($p['monto_pagado']) ?> (TRM <?= number_format((float) $p['tasa_cambio'], 2, ',', '.') ?>)</div>
                                <div class="small">Saldo de la factura: <?= dinero($disponible_pago) ?></div>
                            </td>
                            <td class="small">
                                <?= date('d/m/Y h:i A', strtotime($p['fecha_pago'])) ?>
                                <?php if ($p['soporte_visual']): ?><br><a href="descargar_soporte_pago.php?id=<?= (int) $p['id'] ?>" target="_blank"><i class="fas fa-paperclip"></i> Ver comprobante</a><?php endif; ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-success rounded-pill fw-bold" title="Validar pago"
                                        onclick="abrirValidacion(<?= (int) $p['id'] ?>, <?= (int) $p['factura_id'] ?>, <?= jsAttr($p['numero_factura']) ?>, <?= jsAttr($p['cliente_nombre']) ?>, <?= jsAttr($p['metodo_pago']) ?>, <?= jsAttr($p['referencia_transaccion']) ?>, <?= jsAttr((float) $p['monto_pagado']) ?>, <?= jsAttr($disponible_pago) ?>, <?= $p['soporte_visual'] ? 'true' : 'false' ?>)">
                                    <i class="fas fa-check me-1"></i> Validar
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill fw-bold ms-1" title="Rechazar pago" data-bs-toggle="modal" data-bs-target="#modalRechazarPago"
                                        onclick="prepararRechazo(<?= (int) $p['id'] ?>, <?= jsAttr($p['referencia_transaccion']) ?>)">
                                    <i class="fas fa-times me-1"></i> Rechazar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="toolbar-pro d-flex align-items-center justify-content-between mb-2">
            <form action="" method="GET" class="flex-grow-1 me-3">
                <div class="search-container-pro shadow-sm">
                    <i class="fas fa-search"></i>
                    <input type="text" name="buscar" placeholder="Buscar por Cliente, organización o N° de factura..." value="<?= esc($buscar) ?>">
                    <button type="submit" class="btn-buscar-int">FILTRAR</button>
                </div>
            </form>
            <button class="btn-nuevo-cliente shadow" data-bs-toggle="modal" data-bs-target="#modalNuevaFactura">
                <i class="fas fa-plus-circle me-2"></i> NUEVA FACTURA
            </button>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">N° FACTURA / DETALLE</th>
                        <th>CLIENTE / EMPRESA</th>
                        <th>EMISIÓN / VENCE</th>
                        <th>DESGLOSE FINANCIERO</th>
                        <th>ESTADO</th>
                        <th class="text-center">GESTIÓN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($facturas)): ?>
                        <tr><td colspan="6" class="text-center p-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($facturas as $f):
                        $es_pago_nuevo = ($f['estado'] === 'Pagado' && isset($f['visto']) && $f['visto'] == 0);
                        $clase_fila = $es_pago_nuevo ? 'table-warning bg-opacity-25' : '';

                        $sub = (!empty($f['subtotal']) && $f['subtotal'] > 0) ? (float) $f['subtotal'] : (float) $f['monto_total'];
                        $iva_pct = $f['porcentaje_iva'] ?? 0;
                        $iva_val = (float) $f['monto_total'] - $sub;
                        $recaudado = (float) $f['recaudado'];
                        $aplicado_neto = (float) $f['aprobado']; // Recaudos y notas crédito aprobados, menos notas débito
                        $notas_credito = (float) $f['notas_credito'];
                        $notas_debito = (float) $f['notas_debito'];
                        $en_tramite = (float) $f['en_tramite'];
                        $saldo = max(0, (float) $f['monto_total'] - $aplicado_neto);
                        $disponible = (float) $f['monto_total'] - $aplicado_neto - $en_tramite;
                        $con_movimientos = $recaudado > 0 || $en_tramite > 0 || $notas_credito > 0 || $notas_debito > 0;
                        $abierta = !in_array($f['estado'], ['Pagado', 'Anulada'], true);
                    ?>
                    <tr class="<?= $clase_fila ?>" id="fila-factura-<?= (int) $f['id'] ?>">
                        <td class="ps-4 py-3">
                            <div class="fw-bold text-dark">
                                <i class="fas fa-file-invoice text-muted me-2"></i><?= esc($f['numero_factura']) ?>
                                <?php if($es_pago_nuevo): ?>
                                    <span class="badge bg-danger ms-1" style="font-size: 0.6rem;" id="badge-factura-<?= (int) $f['id'] ?>">NUEVO PAGO</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small text-truncate" style="max-width:180px"><?= esc($f['servicio_contratado']) ?></div>
                            <?php if ($f['estado'] === 'Anulada'): ?>
                                <div class="small text-danger text-truncate" style="max-width:220px" title="<?= esc($f['motivo_anulacion']) ?>"><i class="fas fa-ban"></i> <?= esc($f['motivo_anulacion']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?= esc($f['cliente_nombre'] ?? 'Usuario Desconocido') ?></div>
                            <div class="text-primary fw-bold small text-uppercase">
                                <?= !empty($f['organizacion']) ? esc($f['organizacion']) : 'Sin Organización' ?>
                            </div>

                            <?php if(!empty($f['prospecto_original'])): ?>
                                <div class="small text-muted mt-1" style="font-size: 0.70rem;">
                                    <i class="fas fa-link text-info"></i> Origen: <?= esc($f['prospecto_original']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="small"><span class="text-muted">E:</span> <?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></div>
                            <div class="small text-danger fw-bold"><span class="text-muted">V:</span> <?= date('d/m/Y', strtotime($f['fecha_vencimiento'])) ?></div>
                        </td>
                        <td>
                            <div class="small text-muted mb-1">Subtotal: <?= dinero($sub) ?></div>
                            <div class="small text-muted mb-1">IVA (<?= floatval($iva_pct) ?>%): <?= dinero($iva_val) ?></div>
                            <div class="fw-bold text-dark fs-6 border-top pt-1 mt-1" style="display:inline-block;">
                                Total: <?= dinero($f['monto_total']) ?>
                            </div>
                            <?php if ($f['estado'] !== 'Anulada' && $con_movimientos): ?>
                                <?php if ($notas_credito > 0): ?><div class="small text-warning fw-bold" data-notas-credito="<?= (int) $f['id'] ?>">Notas crédito: −<?= dinero($notas_credito) ?></div><?php endif; ?>
                                <?php if ($notas_debito > 0): ?><div class="small text-secondary fw-bold" data-notas-debito="<?= (int) $f['id'] ?>">Notas débito: +<?= dinero($notas_debito) ?></div><?php endif; ?>
                                <?php if ($recaudado > 0): ?><div class="small text-success fw-bold">Recaudado: <?= dinero($recaudado) ?></div><?php endif; ?>
                                <?php if ($en_tramite > 0): ?><div class="small text-info fw-bold">En aprobación: <?= dinero($en_tramite) ?></div><?php endif; ?>
                                <?php if ($saldo > 0): ?><div class="small text-danger fw-bold" data-saldo="<?= (int) $f['id'] ?>">Saldo: <?= dinero($saldo) ?></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="badge border <?= $clases_estado[$f['estado']] ?? $clases_estado['Vencido'] ?>">
                                <i class="fas fa-circle me-1" style="font-size:0.4rem"></i>
                                <?= esc(mb_strtoupper($f['estado'] ?? '', 'UTF-8')) ?>
                            </div>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php if($es_pago_nuevo): ?>
                                <button class="btn-circle me-1" title="Marcar revisado" onclick="marcarFacturaVista(<?= (int) $f['id'] ?>)" id="btn-visto-factura-<?= (int) $f['id'] ?>">
                                    <i class="fas fa-check-double text-primary"></i>
                                </button>
                            <?php endif; ?>

                            <a href="generar_pdf.php?id=<?= (int) $f['id'] ?>" target="_blank" class="btn-circle" title="Descargar Factura">
                                <i class="fas fa-download text-primary"></i>
                            </a>

                            <?php if ($recaudado > 0 || $en_tramite > 0): ?>
                                <a href="comprobante_ingreso.php?buscar=<?= urlencode($f['numero_factura']) ?>" class="btn-circle ms-1" title="Ver recaudos">
                                    <i class="fas fa-receipt text-info"></i>
                                </a>
                            <?php endif; ?>

                            <?php if ($abierta && $disponible > 0.5): ?>
                                <button class="btn-circle ms-1" onclick="abrirModalPago(<?= (int) $f['id'] ?>, <?= jsAttr($f['numero_factura']) ?>, <?= jsAttr($f['cliente_nombre'] ?? '') ?>, <?= jsAttr(round($disponible, 2)) ?>)" title="Conciliar Pago">
                                    <i class="fas fa-hand-holding-usd text-success"></i>
                                </button>
                            <?php elseif (!$abierta): ?>
                                <button class="btn-circle ms-1 opacity-25" disabled><i class="fas fa-check text-muted"></i></button>
                            <?php endif; ?>

                            <?php if ($f['estado'] !== 'Anulada' && $puede_notas): ?>
                                <?php if ($disponible > 0.5): ?>
                                    <a href="nueva_nota_factura.php?tipo=NCV&amp;factura=<?= (int) $f['id'] ?>" class="btn-circle ms-1" title="Nota crédito"><i class="fas fa-file-circle-minus text-warning"></i></a>
                                <?php endif; ?>
                                <a href="nueva_nota_factura.php?tipo=NDV&amp;factura=<?= (int) $f['id'] ?>" class="btn-circle ms-1" title="Nota débito"><i class="fas fa-file-circle-plus text-secondary"></i></a>
                            <?php endif; ?>

                            <?php if ($abierta && !$con_movimientos): ?>
                                <button class="btn-circle ms-1" title="Anular factura" data-bs-toggle="modal" data-bs-target="#modalAnularFactura"
                                        onclick="prepararAnularFactura(<?= (int) $f['id'] ?>, <?= jsAttr($f['numero_factura']) ?>)">
                                    <i class="fas fa-ban text-danger"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalNuevaFactura" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0 py-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice-dollar text-primary me-2"></i> Generar Nueva Factura
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="accion" value="crear_factura">
                <div class="modal-body p-4">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">Seleccionar Cliente</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill text-muted"><i class="fas fa-user-tie"></i></span>
                                <select name="id_usuario" class="form-select border-start-0 rounded-end-pill bg-white" onchange="mostrarInfoCRM(this)" required>
                                    <option value="" data-servicio="">-- Buscar Cliente --</option>
                                    <?php foreach($clientes_lista as $cli): ?>
                                        <option value="<?= (int) $cli['id'] ?>" data-servicio="<?= esc(nombreServicio($cli['servicio_interes'] ?? '')) ?>">
                                            <?= esc($cli['nombre']) ?>
                                            <?= !empty($cli['organizacion']) ? " (" . esc($cli['organizacion']) . ")" : "" ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="info_crm" class="alert alert-warning mt-2 py-2 d-none shadow-sm" style="font-size: 0.8rem; border-left: 4px solid #ffc107;"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">N° Factura (se asigna al guardar)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-pill text-muted"><i class="fas fa-hashtag"></i></span>
                                <input type="text" name="numero_factura" class="form-control border-start-0 rounded-end-pill bg-light text-primary fw-bold" value="<?= esc($nuevo_numero_factura) ?>" readonly>
                            </div>
                            <div class="form-text">Si alguien factura al mismo tiempo, el sistema asigna el siguiente número libre.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-2">Servicio a Facturar</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 rounded-start-pill text-muted"><i class="fas fa-briefcase"></i></span>
                            <select name="id_servicio" class="form-select border-start-0 rounded-end-pill bg-white" onchange="autollenarServicio(this)" required>
                                <option value="">-- Elegir del Catálogo --</option>
                                <?php foreach($catalogo_servicios as $srv): ?>
                                    <option value="<?= (int) $srv['id'] ?>"
                                            data-precio="<?= esc($srv['precio_base']) ?>"
                                            data-iva="<?= esc($srv['impuesto_porcentaje']) ?>">
                                        <?= esc($srv['nombre_servicio']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info border-0 bg-info-subtle rounded-4 p-3 mb-3">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted mb-1">Subtotal ($ COP)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 rounded-start-pill text-muted"><i class="fas fa-dollar-sign"></i></span>
                                    <input type="number" name="subtotal" id="facSubtotal" class="form-control border-start-0 rounded-end-pill bg-white" placeholder="0" min="1" step="0.01" oninput="calcularTotalFactura()" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted mb-1">% IVA</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 rounded-start-pill text-muted"><i class="fas fa-percentage"></i></span>
                                    <input type="number" step="0.01" min="0" max="100" name="porcentaje_iva" id="facIva" class="form-control border-start-0 rounded-end-pill bg-white" value="19.00" oninput="calcularTotalFactura()" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-dark mb-1">Total a Pagar</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-pill text-success fw-bold"><i class="fas fa-money-bill-wave"></i></span>
                                    <input type="number" name="monto_total" id="facTotal" class="form-control border-start-0 rounded-end-pill bg-light text-success fw-bold" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">Fecha Emisión</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill text-muted"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" name="fecha_emision" class="form-control border-start-0 rounded-end-pill bg-white" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">Fecha Vencimiento</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill text-muted"><i class="fas fa-calendar-check"></i></span>
                                <input type="date" name="fecha_vencimiento" class="form-control border-start-0 rounded-end-pill bg-white" required>
                            </div>
                        </div>
                    </div>
                    <p class="small text-muted mb-0"><i class="fas fa-info-circle me-1"></i> La factura nace <b>Pendiente</b>. Pasa a Pagado solo cuando se aprueba el comprobante de ingreso de su recaudo.</p>

                </div>
                <div class="modal-footer border-0 bg-light p-3">
                    <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold shadow-sm" style="background: #0A2E4F; padding: 12px;">
                        <i class="fas fa-save me-2"></i> REGISTRAR Y GUARDAR FACTURA
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRegistrarPago" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-check-double text-success me-2"></i> <span id="pago_titulo">Registrar Recaudo</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="procesar_conciliacion.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <input type="hidden" name="factura_id" id="pago_factura_id">
                    <input type="hidden" name="pago_id" id="pago_reportado_id">
                    <div id="pago_reportado_info" class="alert alert-warning small d-none"></div>

                    <div class="alert alert-info border-0 rounded-4 mb-4 shadow-sm" style="background: rgba(59, 130, 246, 0.05);">
                        <div class="d-flex justify-content-between mb-1 small text-muted">
                            <span>Factura N°: <strong id="pago_num_factura" class="text-dark"></strong></span>
                            <span>Cliente: <strong id="pago_cliente" class="text-dark"></strong></span>
                        </div>
                        <div class="text-center h5 mb-0 pt-2 border-top mt-2">
                            <span class="text-muted small">SALDO POR RECAUDAR:</span> <br>
                            <span class="fw-bold text-primary" id="pago_monto_texto"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">Método</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-wallet text-muted"></i></span>
                                <select name="metodo" id="pago_metodo" class="form-select border-start-0 rounded-end-pill bg-white" required>
                                    <option value="Efectivo_Banco">Efectivo (Banco)</option>
                                    <option value="Transferencia">Transferencia</option>
                                    <option value="Nequi_Daviplata">Billetera Digital</option>
                                    <option value="PSE">PSE / Online</option>
                                    <option value="Tarjeta">Tarjeta débito / crédito</option>
                                    <option value="PayPal">PayPal</option>
                                    <option value="Zelle">Zelle</option>
                                    <option value="Western Union">Western Union</option>
                                    <option value="Criptomonedas (USDT)">Criptomonedas (USDT)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">Valor Recibido</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-coins text-muted"></i></span>
                                <input type="number" name="monto_real" id="pago_monto_input" class="form-control border-start-0 rounded-end-pill bg-white" required min="1" step="any">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">N° Comprobante / Referencia</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-barcode text-muted"></i></span>
                                <input type="text" name="referencia" id="pago_referencia" class="form-control border-start-0 rounded-end-pill bg-white" placeholder="Ej: BAU-9921" maxlength="100" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-2">Retención del Cliente</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-percent text-muted"></i></span>
                                <input type="number" name="retencion" id="pago_retencion" class="form-control border-start-0 rounded-end-pill bg-white" min="0" step="any" value="0">
                            </div>
                        </div>
                    </div>

                    <label class="small fw-bold text-muted mb-2">Comisión o diferencia en cambio</label>
                    <div class="input-group mb-1">
                        <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-exchange-alt text-muted"></i></span>
                        <input type="number" name="comision" id="pago_comision" class="form-control border-start-0 rounded-end-pill bg-white" min="0" step="any" value="0">
                    </div>
                    <div class="form-text mb-3">Lo que cobró la plataforma o el banco, o lo que llegó de menos por la tasa de cambio. Se registra como gasto financiero (530515) y también abona la factura.</div>

                    <label class="small fw-bold text-muted mb-2">Subir Recibo (Imagen/PDF)</label>
                    <div class="input-group mb-3">
                        <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-cloud-upload-alt text-muted"></i></span>
                        <input type="file" name="soporte_archivo" id="pago_soporte" class="form-control border-start-0 rounded-end-pill bg-white" required accept="image/*,.pdf">
                    </div>

                    <label class="small fw-bold text-muted mb-2">Notas de Auditoría</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 rounded-start-4"><i class="fas fa-comment-alt text-muted"></i></span>
                        <textarea name="nota_admin" class="form-control border-start-0 rounded-end-4 bg-white" rows="2" placeholder="Ej: Dinero verificado en cuenta de ahorros..." required></textarea>
                    </div>
                    <p class="small text-muted mt-3 mb-0"><i class="fas fa-info-circle me-1"></i> Se genera un <b>comprobante de ingreso</b> pendiente de revisión (Finanzas) y aprobación (Administrador). Al aprobarse, la factura se marca como pagada.</p>
                </div>
                <div class="modal-footer border-0 bg-light p-3">
                    <button type="submit" class="btn btn-success w-100 rounded-pill fw-bold shadow-sm" style="background:var(--verde-datasense); border:none;">REGISTRAR RECAUDO</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRechazarPago" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form action="" method="POST">
                <input type="hidden" name="accion" value="rechazar_pago">
                <input type="hidden" name="pago_id" id="rechazo_pago_id">
                <div class="modal-header border-0 bg-danger bg-opacity-10">
                    <h5 class="fw-bold text-danger mb-0 text-truncate"><i class="fas fa-times-circle me-2"></i> Rechazar pago <span id="rechazo_referencia" class="small"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted">El pago queda <b>Rechazado</b> y la factura vuelve a quedar por pagar. El cliente verá el motivo en Mis Facturas y podrá reportar de nuevo.</p>
                    <label class="small fw-bold text-muted mb-2">Motivo del rechazo *</label>
                    <textarea name="motivo" class="form-control" rows="3" minlength="10" maxlength="255" required placeholder="Ej: El dinero no ha llegado a la cuenta Zelle."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill fw-bold px-4">Rechazar pago</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAnularFactura" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form action="" method="POST">
                <input type="hidden" name="accion" value="anular_factura">
                <input type="hidden" name="id_factura" id="anular_factura_id">
                <div class="modal-header border-0 bg-danger bg-opacity-10">
                    <h5 class="fw-bold text-danger mb-0"><i class="fas fa-ban me-2"></i> Anular factura <span id="anular_factura_numero"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted">La factura no se borra: queda <b>Anulada</b>, conserva su número y deja de sumar en la cartera y en la contabilidad. Solo se anulan facturas sin recaudos.</p>
                    <label class="small fw-bold text-muted mb-2">Motivo de la anulación *</label>
                    <textarea name="motivo" class="form-control" rows="3" minlength="10" maxlength="255" required placeholder="Ej: Se facturó al cliente equivocado."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill fw-bold px-4">Anular factura</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Autocompletar precios al elegir un servicio del catálogo
    function autollenarServicio(selectElement) {
        let opcionSeleccionada = selectElement.options[selectElement.selectedIndex];

        if (opcionSeleccionada.value !== "") {
            document.getElementById('facSubtotal').value = opcionSeleccionada.getAttribute('data-precio');
            document.getElementById('facIva').value = opcionSeleccionada.getAttribute('data-iva');
            calcularTotalFactura();
        } else {
            document.getElementById('facSubtotal').value = "";
            document.getElementById('facTotal').value = "";
        }
    }

    // Calcular el total de la factura (el servidor lo vuelve a calcular al guardar)
    function calcularTotalFactura() {
        let base = parseFloat(document.getElementById('facSubtotal').value) || 0;
        let iva = parseFloat(document.getElementById('facIva').value) || 0;
        let total = base + (base * (iva / 100));
        document.getElementById('facTotal').value = total.toFixed(2);
    }

    // Mostrar el historial del CRM al seleccionar un cliente
    function mostrarInfoCRM(selectElement) {
        let infoDiv = document.getElementById('info_crm');
        let selectedOption = selectElement.options[selectElement.selectedIndex];
        let servicio = selectedOption.getAttribute('data-servicio');

        if (servicio && servicio.trim() !== '') {
            infoDiv.innerHTML = "💡 <b>Dato del CRM:</b> El cliente contactó originalmente interesado en: <b class='text-dark'></b>";
            infoDiv.querySelector('b.text-dark').textContent = servicio; // Texto del formulario público: nunca como HTML
            infoDiv.classList.remove('d-none');
        } else {
            infoDiv.classList.add('d-none');
        }
    }

    // Modal de registro de recaudo: propone el saldo que aún se puede aplicar
    function abrirModalPago(id, num, cli, monto) {
        document.getElementById('pago_factura_id').value = id;
        document.getElementById('pago_monto_input').value = monto;
        document.getElementById('pago_retencion').value = 0;
        document.getElementById('pago_comision').value = 0;
        document.getElementById('pago_reportado_id').value = '';
        document.getElementById('pago_referencia').value = '';
        document.getElementById('pago_referencia').readOnly = false;
        document.getElementById('pago_soporte').required = true;
        document.getElementById('pago_reportado_info').classList.add('d-none');
        document.getElementById('pago_titulo').textContent = 'Registrar Recaudo';

        document.getElementById('pago_num_factura').innerText = num;
        document.getElementById('pago_cliente').innerText = cli;

        let formateador = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
        document.getElementById('pago_monto_texto').innerText = formateador.format(monto);

        var myModal = new bootstrap.Modal(document.getElementById('modalRegistrarPago'));
        myModal.show();
    }

    // Validar un pago internacional reportado: se registra con lo que realmente llegó a la cuenta
    function abrirValidacion(pagoId, facturaId, numero, cliente, metodo, referencia, montoCop, disponible, tieneSoporte) {
        abrirModalPago(facturaId, numero, cliente, disponible);
        document.getElementById('pago_monto_input').value = Math.min(montoCop, disponible);
        document.getElementById('pago_reportado_id').value = pagoId;
        document.getElementById('pago_metodo').value = metodo;
        document.getElementById('pago_referencia').value = referencia;
        document.getElementById('pago_referencia').readOnly = true;
        document.getElementById('pago_soporte').required = !tieneSoporte;
        document.getElementById('pago_titulo').textContent = 'Validar pago reportado';
        const info = document.getElementById('pago_reportado_info');
        info.textContent = 'Escribe en "Valor Recibido" los pesos que realmente llegaron. Si llegó menos por comisión o tasa de cambio, pon la diferencia en "Comisión o diferencia en cambio".';
        info.classList.remove('d-none');
    }

    function prepararRechazo(pagoId, referencia) {
        document.getElementById('rechazo_pago_id').value = pagoId;
        document.getElementById('rechazo_referencia').textContent = referencia;
    }

    function prepararAnularFactura(id, numero) {
        document.getElementById('anular_factura_id').value = id;
        document.getElementById('anular_factura_numero').textContent = numero;
    }

    // Quitar notificación visual de "NUEVO PAGO"
    function marcarFacturaVista(id) {
        fetch('marcar_factura_vista.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id
        })
        .then(response => response.text())
        .then(data => {
            if(data === 'ok') {
                document.getElementById('fila-factura-' + id).classList.remove('table-warning', 'bg-opacity-25');
                let badge = document.getElementById('badge-factura-' + id);
                if(badge) badge.remove();
                let btn = document.getElementById('btn-visto-factura-' + id);
                if(btn) btn.remove();
            }
        });
    }
</script>
</body>
</html>
