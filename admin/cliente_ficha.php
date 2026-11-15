<?php
// Ficha del cliente: todo lo relacionado con una cuenta de cliente en un solo lugar (solicitudes, facturas y pagos,
// casos de soporte, reuniones, compras y encuestas) y una revisión de la calidad de esos datos.
// Cada sección se muestra solo si el rol puede abrir el módulo correspondiente.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/utilidades.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/itsm.php';
$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'clientes');

$id_cliente = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ? AND rol = 'Cliente'");
$stmt->execute([$id_cliente]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cliente) {
    header('Location: clientes.php?err=cliente_no_existe');
    exit;
}

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$organizacion = trim((string) ($cliente['organizacion'] ?? ''));
$nombre_visible = $organizacion !== '' ? $organizacion : $cliente['nombre'];
$titulo = 'Ficha del cliente';
$subtitulo = esc($nombre_visible); // includes/header.php lo imprime tal cual: el nombre lo escribe el cliente al registrarse

$rol = $usuario_actual['rol'];
$tabla_existe = function ($tabla) use ($db) {
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tabla]);
    return (bool) $stmt->fetchColumn();
};
$ver = [
    'solicitudes' => tieneAcceso($rol, 'mensajes', $db),
    'facturas'    => tieneAcceso($rol, 'facturacion', $db),
    'casos'       => tieneAcceso($rol, 'gestion_it', $db),
    'reuniones'   => tieneAcceso($rol, 'reuniones', $db),
    'compras'     => tieneAcceso($rol, 'tienda', $db) && $tabla_existe('tienda_pedidos'),
    'encuestas'   => tieneAcceso($rol, 'encuestas', $db),
];
$reuniones_con_cliente = (bool) $db->query("SHOW COLUMNS FROM reuniones_virtuales LIKE 'cliente_id'")->fetch();

// Consulta tolerante: una base sin alguna migración deja la sección vacía en vez de romper la ficha
$consultar = function ($sql, array $params) use ($db) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('cliente_ficha.php: ' . $e->getMessage());
        return [];
    }
};
$fecha = function ($valor, $con_hora = false) {
    return $valor ? date($con_hora ? 'd/m/Y H:i' : 'd/m/Y', strtotime((string) $valor)) : '—';
};
$hoy = date('Y-m-d');
$ahora = date('Y-m-d H:i:s');
$tercero = terceroDeUsuario($db, $id_cliente);
$documento = $tercero['documento'] ?? trim((string) ($cliente['documento'] ?? ''));
$estado_cuenta = $cliente['estado_cuenta'] ?: 'Activo';
$texto_estado_cuenta = ['Activo' => 'activa', 'Inactivo' => 'inactiva', 'Suspendido' => 'suspendida', 'Retirado' => 'retirada', 'Vacaciones' => 'en vacaciones'][$estado_cuenta] ?? mb_strtolower($estado_cuenta, 'UTF-8');

// ------------------------------------------------------------------ Solicitudes del formulario (por vínculo o por correo)
$solicitudes = $ver['solicitudes'] ? $consultar(
    "SELECT id, nombre, servicio, mensaje, estado, tipo_cliente, fecha_registro FROM contactos
     WHERE id_usuario_final = ? OR LOWER(correo) = LOWER(?) ORDER BY fecha_registro DESC, id DESC",
    [$id_cliente, $cliente['correo']]
) : [];

// Estados al día antes de mostrarlos: facturas vencidas y pedidos cuya factura ya se pagó o se anuló
if ($ver['facturas']) actualizarVencimientos($db);
if ($ver['compras']) {
    require_once __DIR__ . '/../includes/tienda.php';
    sincronizarPedidos($db);
}

// ------------------------------------------------------------------ Facturas, lo recaudado y pagos
$facturas = [];
$pagos = [];
$resumen = ['facturas' => 0, 'facturado' => 0.0, 'recaudado' => 0.0, 'saldo' => 0.0, 'vencido' => 0.0];
if ($ver['facturas']) {
    $facturas = $consultar(
        "SELECT f.id, f.numero_factura, f.servicio_contratado, f.fecha_emision, f.fecha_vencimiento, f.subtotal, f.porcentaje_iva, f.monto_total, f.estado,
                COALESCE(a.aprobado, 0) AS aplicado, COALESCE(a.en_tramite, 0) AS en_tramite
         FROM facturas f LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
         WHERE f.id_usuario = ? ORDER BY f.fecha_emision DESC, f.id DESC",
        [$id_cliente]
    );
    foreach ($facturas as &$f) {
        $anulada = $f['estado'] === 'Anulada';
        $f['saldo'] = $anulada ? 0.0 : max(0.0, round((float) $f['monto_total'] - (float) $f['aplicado'], 2));
        $f['total_esperado'] = (float) $f['subtotal'] > 0 ? round((float) $f['subtotal'] * (1 + (float) $f['porcentaje_iva'] / 100), 2) : (float) $f['monto_total'];
        if ($anulada) continue;
        $resumen['facturas']++;
        $resumen['facturado'] += (float) $f['monto_total'];
        $resumen['recaudado'] += min((float) $f['aplicado'], (float) $f['monto_total']);
        $resumen['saldo'] += $f['saldo'];
        if ($f['fecha_vencimiento'] < $hoy && $f['saldo'] > 0.5) $resumen['vencido'] += $f['saldo'];
    }
    unset($f);
    $pagos = $consultar(
        "SELECT p.*, f.numero_factura FROM pagos_registro p JOIN facturas f ON f.id = p.factura_id
         WHERE f.id_usuario = ? ORDER BY p.fecha_pago DESC, p.id DESC",
        [$id_cliente]
    );
}

// ------------------------------------------------------------------ Casos de soporte con su ANS
$casos = [];
$cfg = $ver['casos'] ? configItsm($db) : null;
if ($ver['casos']) {
    foreach ($consultar(
        "SELECT t.*, tec.nombre AS tecnico_nombre FROM soporte_tickets t LEFT JOIN usuarios tec ON tec.id = t.tecnico_id
         WHERE t.usuario_id = ? ORDER BY t.created_at DESC, t.id DESC",
        [$id_cliente]
    ) as $t) {
        $t['comportamiento'] = comportamientoEstado($db, $t['estado']) ?: COMP_ABIERTO;
        $t['abierto'] = !in_array($t['comportamiento'], [COMP_SOLUCIONADO, COMP_CERRADO], true);
        $t['sla'] = null;
        if (!empty($t['sla_resolucion_min']) && !empty($t['created_at'])) {
            try {
                $t['sla'] = slaTicket($t, $cfg);
            } catch (Exception $e) {
                error_log('cliente_ficha.php (ANS): ' . $e->getMessage());
            }
        }
        $casos[] = $t;
    }
}
$casos_abiertos = count(array_filter($casos, function ($t) { return $t['abierto']; }));

// ------------------------------------------------------------------ Reuniones, compras y encuestas
$reuniones = ($ver['reuniones'] && $reuniones_con_cliente) ? $consultar(
    "SELECT id, titulo, fecha, hora_inicio, hora_fin, estado, link_reunion, objetivo FROM reuniones_virtuales
     WHERE cliente_id = ? ORDER BY fecha DESC, hora_inicio DESC",
    [$id_cliente]
) : [];
$reuniones_proximas = count(array_filter($reuniones, function ($r) use ($ahora) { return $r['estado'] !== 'Finalizada' && $r['fecha'] . ' ' . $r['hora_fin'] >= $ahora; }));

$compras = $ver['compras'] ? $consultar(
    "SELECT p.id, p.numero, p.estado, p.total, p.created_at, f.numero_factura FROM tienda_pedidos p
     LEFT JOIN facturas f ON f.id = p.factura_id WHERE p.usuario_id = ? ORDER BY p.created_at DESC, p.id DESC",
    [$id_cliente]
) : [];
$total_compras = array_sum(array_map(function ($c) { return $c['estado'] === 'Cancelado' ? 0 : (float) $c['total']; }, $compras));

$encuestas = $ver['encuestas'] ? $consultar(
    "SELECT r.id, r.calificacion, r.comentarios, r.fecha_respuesta, e.titulo, t.codigo_ticket
     FROM encuestas_respuestas r JOIN encuestas e ON e.id = r.id_encuesta LEFT JOIN soporte_tickets t ON t.id = r.ticket_id
     WHERE t.usuario_id = ? OR r.id_contacto IN (SELECT c.id FROM contactos c WHERE c.id_usuario_final = ? OR LOWER(c.correo) = LOWER(?))
     ORDER BY r.fecha_respuesta DESC",
    [$id_cliente, $id_cliente, $cliente['correo']]
) : [];

// ------------------------------------------------------------------ Revisión de datos
$revision = []; // [nivel: error|aviso, texto]
if ($organizacion === '') $revision[] = ['aviso', 'La cuenta no tiene organización registrada.'];
if ($documento === '') $revision[] = ['aviso', 'No tiene NIT o cédula: la contabilidad y los certificados usan solo el nombre de la cuenta.'];
if (trim((string) ($cliente['telefono'] ?? '')) === '') $revision[] = ['aviso', 'No tiene teléfono: desde los casos no se le puede escribir por WhatsApp.'];
if (in_array($estado_cuenta, ['Suspendido', 'Retirado', 'Inactivo'], true)) {
    $revision[] = ['aviso', "La cuenta está $texto_estado_cuenta: el cliente no puede ingresar a la plataforma."];
}
foreach ($facturas as $f) {
    if ($f['estado'] === 'Anulada') continue;
    if ($f['estado'] === 'Pagado' && (float) $f['aplicado'] < (float) $f['monto_total'] - 0.5) {
        $revision[] = ['error', "La factura {$f['numero_factura']} figura como Pagado, pero solo tiene " . dinero($f['aplicado']) . ' de ' . dinero($f['monto_total']) . ' en recaudos aprobados.'];
    }
    if (abs($f['total_esperado'] - (float) $f['monto_total']) > 1) {
        $revision[] = ['error', "La factura {$f['numero_factura']} tiene un total de " . dinero($f['monto_total']) . ' que no corresponde al subtotal ' . dinero($f['subtotal']) . " más IVA del {$f['porcentaje_iva']} % (" . dinero($f['total_esperado']) . ').'];
    }
    if ($f['fecha_vencimiento'] < $hoy && $f['saldo'] > 0.5 && $f['estado'] !== 'Pagado') {
        $revision[] = ['aviso', "La factura {$f['numero_factura']} venció el " . $fecha($f['fecha_vencimiento']) . ' con saldo de ' . dinero($f['saldo']) . '.'];
    }
}
foreach ($casos as $t) {
    if (!$t['abierto']) continue;
    if ($t['sla'] && $t['sla']['resolucion']['nivel'] === 'vencido') {
        $revision[] = ['error', "El caso {$t['codigo_ticket']} superó su tiempo de solución (fecha límite " . $fecha($t['sla']['resolucion']['vence'], true) . ', en horas hábiles).'];
    }
    if (empty($t['tecnico_id'])) $revision[] = ['aviso', "El caso {$t['codigo_ticket']} está abierto sin especialista asignado."];
}
foreach ($reuniones as $r) {
    if ($r['estado'] !== 'Finalizada' && $r['fecha'] . ' ' . $r['hora_fin'] < $ahora) {
        $revision[] = ['aviso', 'La reunión «' . $r['titulo'] . '» del ' . $fecha($r['fecha']) . ' ya pasó y sigue sin acta firmada.'];
    }
}

// ------------------------------------------------------------------ Actividad reciente (todo junto, lo más nuevo primero)
$actividad = [];
foreach ($solicitudes as $s) $actividad[] = [$s['fecha_registro'], 'fa-envelope-open-text', 'Solicitud por el formulario', nombreServicio($s['servicio']) . ' · ' . $s['estado']];
foreach ($facturas as $f) $actividad[] = [$f['fecha_emision'] . ' 00:00:00', 'fa-file-invoice-dollar', 'Factura ' . $f['numero_factura'], $f['servicio_contratado'] . ' · ' . dinero($f['monto_total']) . ' · ' . $f['estado']];
foreach ($pagos as $p) $actividad[] = [$p['fecha_pago'], 'fa-money-check-dollar', 'Pago de la factura ' . $p['numero_factura'], dinero($p['monto_pagado']) . ' · ' . str_replace('_', ' ', (string) $p['metodo_pago']) . ' · ' . str_replace('_', ' ', (string) $p['estado_transaccion'])];
foreach ($casos as $t) $actividad[] = [$t['created_at'], 'fa-headset', 'Caso ' . $t['codigo_ticket'], $t['asunto'] . ' · ' . $t['estado']];
foreach ($reuniones as $r) $actividad[] = [$r['fecha'] . ' ' . $r['hora_inicio'], 'fa-video', 'Reunión', $r['titulo'] . ' · ' . ($r['estado'] ?: 'Sin estado')];
foreach ($compras as $c) $actividad[] = [$c['created_at'], 'fa-bag-shopping', 'Pedido ' . $c['numero'], dinero($c['total']) . ' · ' . $c['estado']];
usort($actividad, function ($a, $b) { return strcmp((string) $b[0], (string) $a[0]); });
$actividad = array_slice($actividad, 0, 12);

$clase_factura = ['Pagado' => 'text-bg-success', 'Pendiente' => 'text-bg-warning', 'Vencido' => 'text-bg-danger', 'En Proceso' => 'text-bg-info', 'Anulada' => 'text-bg-secondary'];
$clase_caso = [COMP_ABIERTO => 'text-bg-secondary', COMP_PROCESO => 'text-bg-primary', COMP_PAUSA => 'text-bg-warning', COMP_SOLUCIONADO => 'text-bg-success', COMP_CERRADO => 'text-bg-dark'];
$clase_prioridad = ['Crítica' => 'text-bg-danger', 'Alta' => 'text-bg-warning', 'Media' => 'text-bg-info', 'Baja' => 'text-bg-success'];
$clase_pedido = ['Pagado' => 'text-bg-success', 'Entregado' => 'text-bg-success', 'Enviado' => 'text-bg-info', 'En preparación' => 'text-bg-primary', 'Cancelado' => 'text-bg-secondary'];

// Tiempo del ANS de solución: fecha límite y tiempo consumido en horas hábiles (nunca solo "vencido hace X")
$texto_sla = function (array $t) use ($cfg, $fecha) {
    if (empty($t['sla'])) return '<span class="small text-muted">Sin ANS calculado</span>';
    $calendario = $t['sla']['calendario'];
    $res = $t['sla']['resolucion'];
    $tiempo = esc(duracionHabilTexto($res['consumido'], $cfg, $calendario)) . ' de ' . esc(duracionHabilTexto($res['objetivo'], $cfg, $calendario));
    $cuentan = $calendario === '24x7' ? 'cuentan las 24 horas' : 'solo cuentan horas hábiles';
    if (!$t['abierto']) {
        $ok = !empty($res['cumplido']);
        return '<span class="badge ' . ($ok ? 'text-bg-success' : 'text-bg-danger') . '">' . ($ok ? 'Solucionado dentro del ANS' : 'Solucionado fuera del ANS') . '</span>'
            . '<div class="small text-muted">' . $tiempo . ' · ' . $cuentan . '</div>';
    }
    if ($res['vence'] === null) {
        return '<span class="badge text-bg-secondary">Reloj detenido (en pausa)</span><div class="small text-muted">Consumido ' . $tiempo . '</div>';
    }
    $clases = ['a_tiempo' => 'text-bg-success', 'atencion' => 'text-bg-info', 'riesgo' => 'text-bg-warning', 'critico' => 'text-bg-danger', 'vencido' => 'text-bg-danger'];
    return '<div class="small fw-semibold text-dark">Fecha límite: ' . esc($fecha($res['vence'], true)) . '</div>'
        . '<span class="badge ' . ($clases[$res['nivel']] ?? 'text-bg-secondary') . '">' . esc($res['etiqueta']) . '</span>'
        . '<div class="small text-muted">Consumido ' . $tiempo . ' · ' . $cuentan . '</div>';
};
$iniciales = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', $nombre_visible), 0, 2, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($nombre_visible) ?> · Ficha del cliente | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .ficha-cabecera { background: linear-gradient(135deg, #0A2E4F 0%, #14507f 100%); color: #fff; border-radius: 1.25rem; }
        .ficha-avatar { width: 64px; height: 64px; border-radius: 18px; background: rgba(255, 255, 255, .16); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.4rem; flex-shrink: 0; }
        .ficha-dato { font-size: .86rem; color: rgba(255, 255, 255, .88); overflow-wrap: anywhere; }
        .ficha-cabecera .btn-light { font-weight: 700; }
        .kpi-ficha { border: 0; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); height: 100%; background: #fff; padding: 1rem; }
        .kpi-ficha .etiqueta { font-size: .7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .kpi-ficha .valor { font-size: 1.2rem; font-weight: 800; color: #0f172a; overflow-wrap: anywhere; }
        .kpi-ficha .detalle { font-size: .75rem; color: #64748b; }
        .nav-ficha { flex-wrap: nowrap; overflow-x: auto; gap: .25rem; border-bottom: 1px solid #e2e8f0; scrollbar-width: thin; }
        .nav-ficha .nav-link { white-space: nowrap; color: #475569; font-weight: 600; border: 0; border-bottom: 3px solid transparent; border-radius: 0; padding: .7rem .9rem; }
        .nav-ficha .nav-link.active { color: #0A2E4F; border-bottom-color: #3FAE49; background: transparent; }
        .tarjeta-ficha { background: #fff; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); }
        .tabla-ficha { min-width: 720px; }
        .tabla-ficha th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .linea-tiempo { list-style: none; padding: 0; margin: 0; }
        .linea-tiempo li { position: relative; padding: 0 0 1rem 2.5rem; }
        .linea-tiempo li::before { content: ''; position: absolute; left: .9rem; top: 1.9rem; bottom: 0; width: 2px; background: #e2e8f0; }
        .linea-tiempo li:last-child::before { display: none; }
        .linea-tiempo .icono { position: absolute; left: 0; top: 0; width: 1.9rem; height: 1.9rem; border-radius: 50%; background: #e0f2fe; color: #0A2E4F; display: flex; align-items: center; justify-content: center; font-size: .8rem; }
        .revision { list-style: none; padding: 0; margin: 0; }
        .revision li { display: flex; gap: .6rem; align-items: flex-start; padding: .6rem 0; border-bottom: 1px dashed #e2e8f0; font-size: .9rem; }
        .revision li:last-child { border-bottom: 0; }
        .vacio-ficha { text-align: center; color: #64748b; padding: 2.5rem 1rem; }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div id="fichaCliente" data-cliente="<?= (int) $id_cliente ?>">
            <?php if (($_GET['res'] ?? '') === 'reunion'): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4" role="alert">
                    <i class="fas fa-calendar-check me-2"></i> La reunión quedó agendada para este cliente.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            <?php endif; ?>

            <section class="ficha-cabecera p-4 mb-4 shadow-sm">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <div class="ficha-avatar" aria-hidden="true"><?= esc($iniciales) ?></div>
                    <div class="flex-grow-1" style="min-width: 220px;">
                        <div class="small text-uppercase fw-bold opacity-75">Cliente · REF #<?= str_pad((string) $id_cliente, 4, '0', STR_PAD_LEFT) ?></div>
                        <h2 class="h4 fw-bold mb-1 text-white" id="fichaNombre"><?= esc($nombre_visible) ?></h2>
                        <div class="d-flex flex-wrap gap-3 ficha-dato">
                            <?php if ($organizacion !== ''): ?><span><i class="fas fa-user me-1"></i><?= esc($cliente['nombre']) ?></span><?php endif; ?>
                            <span><i class="fas fa-envelope me-1"></i><?= esc($cliente['correo']) ?></span>
                            <span><i class="fas fa-phone me-1"></i><?= esc(($cliente['telefono'] ?? '') !== '' ? $cliente['telefono'] : 'Sin teléfono') ?></span>
                            <span><i class="fas fa-id-badge me-1"></i><?= esc($documento !== '' ? 'NIT/CC ' . $documento : 'Sin documento') ?></span>
                            <?php if (!empty($cliente['fecha_registro'])): ?><span><i class="fas fa-calendar me-1"></i>Cliente desde <?= esc($fecha($cliente['fecha_registro'])) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge rounded-pill <?= $estado_cuenta === 'Activo' ? 'text-bg-success' : 'text-bg-danger' ?> px-3 py-2" id="estadoCuenta">Cuenta <?= esc($texto_estado_cuenta) ?></span>
                        <?php if ($ver['reuniones'] && $reuniones_con_cliente): ?>
                            <a href="salas_reuniones.php?agendar=1&amp;cliente=<?= (int) $id_cliente ?>" class="btn btn-light btn-sm rounded-pill px-3" id="btnAgendarReunion"><i class="fas fa-calendar-plus me-1"></i> Agendar reunión</a>
                        <?php endif; ?>
                        <a href="clientes.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i> Clientes</a>
                    </div>
                </div>
            </section>

            <div class="row g-3 mb-4">
                <?php if ($ver['facturas']): ?>
                <div class="col-6 col-lg-4 col-xl-2"><div class="kpi-ficha" id="kpiFacturado"><div class="etiqueta">Facturado</div><div class="valor"><?= dinero($resumen['facturado']) ?></div><div class="detalle"><?= $resumen['facturas'] ?> factura<?= $resumen['facturas'] === 1 ? '' : 's' ?></div></div></div>
                <div class="col-6 col-lg-4 col-xl-2"><div class="kpi-ficha" id="kpiRecaudado"><div class="etiqueta">Recaudado</div><div class="valor text-success"><?= dinero($resumen['recaudado']) ?></div><div class="detalle">Recaudos aprobados</div></div></div>
                <div class="col-6 col-lg-4 col-xl-2"><div class="kpi-ficha" id="kpiSaldo"><div class="etiqueta">Saldo por cobrar</div><div class="valor <?= $resumen['saldo'] > 0.5 ? 'text-danger' : '' ?>"><?= dinero($resumen['saldo']) ?></div><div class="detalle"><?= $resumen['vencido'] > 0.5 ? 'Vencido ' . dinero($resumen['vencido']) : 'Nada vencido' ?></div></div></div>
                <?php endif; ?>
                <?php if ($ver['casos']): ?>
                <div class="col-6 col-lg-4 col-xl-2"><div class="kpi-ficha" id="kpiCasos"><div class="etiqueta">Casos de soporte</div><div class="valor"><?= count($casos) ?></div><div class="detalle"><?= $casos_abiertos ?> abierto<?= $casos_abiertos === 1 ? '' : 's' ?></div></div></div>
                <?php endif; ?>
                <?php if ($ver['reuniones']): ?>
                <div class="col-6 col-lg-4 col-xl-2"><div class="kpi-ficha" id="kpiReuniones"><div class="etiqueta">Reuniones</div><div class="valor"><?= count($reuniones) ?></div><div class="detalle"><?= $reuniones_proximas ?> próxima<?= $reuniones_proximas === 1 ? '' : 's' ?></div></div></div>
                <?php endif; ?>
                <?php if ($ver['compras']): ?>
                <div class="col-6 col-lg-4 col-xl-2"><div class="kpi-ficha" id="kpiCompras"><div class="etiqueta">Compras en tienda</div><div class="valor"><?= count($compras) ?></div><div class="detalle"><?= dinero($total_compras) ?></div></div></div>
                <?php endif; ?>
            </div>

            <ul class="nav nav-ficha mb-3" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#resumen" type="button" role="tab"><i class="fas fa-stream me-1"></i> Resumen</button></li>
                <?php if ($ver['facturas']): ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#facturas" type="button" role="tab"><i class="fas fa-file-invoice-dollar me-1"></i> Facturas y pagos <span class="badge text-bg-light"><?= count($facturas) ?></span></button></li><?php endif; ?>
                <?php if ($ver['casos']): ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#casos" type="button" role="tab"><i class="fas fa-headset me-1"></i> Casos <span class="badge text-bg-light"><?= count($casos) ?></span></button></li><?php endif; ?>
                <?php if ($ver['reuniones']): ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#reuniones" type="button" role="tab"><i class="fas fa-video me-1"></i> Reuniones <span class="badge text-bg-light"><?= count($reuniones) ?></span></button></li><?php endif; ?>
                <?php if ($ver['compras']): ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#compras" type="button" role="tab"><i class="fas fa-bag-shopping me-1"></i> Compras <span class="badge text-bg-light"><?= count($compras) ?></span></button></li><?php endif; ?>
                <?php if ($ver['solicitudes']): ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#solicitudes" type="button" role="tab"><i class="fas fa-envelope-open-text me-1"></i> Solicitudes <span class="badge text-bg-light"><?= count($solicitudes) ?></span></button></li><?php endif; ?>
                <?php if ($ver['encuestas']): ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#encuestas" type="button" role="tab"><i class="fas fa-star me-1"></i> Encuestas <span class="badge text-bg-light"><?= count($encuestas) ?></span></button></li><?php endif; ?>
            </ul>

            <div class="tab-content">
                <!-- RESUMEN -->
                <div class="tab-pane fade show active" id="resumen" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="tarjeta-ficha p-4 h-100">
                                <h3 class="h6 fw-bold mb-3"><i class="fas fa-clipboard-check text-primary me-2"></i>Revisión de datos</h3>
                                <ul class="revision" id="revisionDatos">
                                    <?php foreach ($revision as $item): ?>
                                        <li class="revision-<?= esc($item[0]) ?>">
                                            <i class="fas <?= $item[0] === 'error' ? 'fa-circle-exclamation text-danger' : 'fa-triangle-exclamation text-warning' ?> mt-1"></i>
                                            <span><?= esc($item[1]) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$revision): ?>
                                        <li class="revision-ok"><i class="fas fa-circle-check text-success mt-1"></i><span>No se encontraron inconsistencias en los datos de este cliente.</span></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="tarjeta-ficha p-4 h-100">
                                <h3 class="h6 fw-bold mb-3"><i class="fas fa-clock-rotate-left text-primary me-2"></i>Actividad reciente</h3>
                                <?php if ($actividad): ?>
                                    <ul class="linea-tiempo" id="actividadCliente">
                                        <?php foreach ($actividad as $a): ?>
                                            <li>
                                                <span class="icono"><i class="fas <?= esc($a[1]) ?>"></i></span>
                                                <div class="fw-semibold text-dark"><?= esc($a[2]) ?> <span class="small text-muted fw-normal">· <?= esc($fecha($a[0], substr((string) $a[0], 11, 8) !== '00:00:00')) ?><?= (string) $a[0] > $ahora ? ' (próxima)' : '' ?></span></div>
                                                <div class="small text-muted"><?= esc($a[3]) ?></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="vacio-ficha"><i class="fas fa-inbox fa-2x mb-2 opacity-50"></i><div>Todavía no hay actividad registrada con este cliente.</div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($ver['facturas']): ?>
                <!-- FACTURAS Y PAGOS -->
                <div class="tab-pane fade" id="facturas" role="tabpanel">
                    <div class="tarjeta-ficha mb-4 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 tabla-ficha" id="tablaFacturas">
                                <thead><tr><th class="ps-4">Factura</th><th>Servicio</th><th>Emisión</th><th>Vence</th><th class="text-end">Total</th><th class="text-end">Recaudado</th><th class="text-end">Saldo</th><th>Estado</th><th class="text-end pe-4">PDF</th></tr></thead>
                                <tbody>
                                    <?php foreach ($facturas as $f): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark text-nowrap"><?= esc($f['numero_factura']) ?></td>
                                            <td class="small"><?= esc($f['servicio_contratado']) ?></td>
                                            <td class="small"><?= esc($fecha($f['fecha_emision'])) ?></td>
                                            <td class="small <?= $f['fecha_vencimiento'] < $hoy && $f['saldo'] > 0.5 ? 'text-danger fw-semibold' : '' ?>"><?= esc($fecha($f['fecha_vencimiento'])) ?></td>
                                            <td class="text-end text-nowrap"><?= dinero($f['monto_total']) ?></td>
                                            <td class="text-end text-success text-nowrap"><?= dinero(min((float) $f['aplicado'], (float) $f['monto_total'])) ?><?php if ((float) $f['en_tramite'] > 0): ?><div class="small text-muted">+<?= dinero($f['en_tramite']) ?> por aprobar</div><?php endif; ?></td>
                                            <td class="text-end fw-semibold text-nowrap"><?= dinero($f['saldo']) ?></td>
                                            <td><span class="badge <?= $clase_factura[$f['estado']] ?? 'text-bg-secondary' ?>"><?= esc($f['estado'] ?: 'Sin estado') ?></span></td>
                                            <td class="text-end pe-4"><a href="generar_pdf.php?id=<?= (int) $f['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger rounded-pill" title="Ver PDF de <?= esc($f['numero_factura']) ?>"><i class="fas fa-file-pdf"></i></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$facturas): ?><tr><td colspan="9" class="vacio-ficha">Este cliente no tiene facturas.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <h3 class="h6 fw-bold mb-3"><i class="fas fa-money-check-dollar text-success me-2"></i>Pagos registrados</h3>
                    <div class="tarjeta-ficha overflow-hidden">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 tabla-ficha" id="tablaPagos">
                                <thead><tr><th class="ps-4">Fecha</th><th>Factura</th><th>Medio</th><th>Referencia</th><th class="text-end">Valor</th><th class="pe-4">Estado</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pagos as $p): ?>
                                        <tr>
                                            <td class="ps-4 small"><?= esc($fecha($p['fecha_pago'], true)) ?></td>
                                            <td class="fw-semibold"><?= esc($p['numero_factura']) ?></td>
                                            <td class="small"><?= esc(str_replace('_', ' ', (string) $p['metodo_pago'])) ?></td>
                                            <td class="small text-muted"><?= esc($p['referencia_transaccion'] ?: '—') ?></td>
                                            <td class="text-end text-nowrap"><?= dinero($p['monto_pagado']) ?><?php if (!empty($p['moneda']) && $p['moneda'] !== 'COP' && !empty($p['monto_moneda'])): ?><div class="small text-muted"><?= esc($p['moneda'] . ' ' . number_format((float) $p['monto_moneda'], 2, ',', '.')) ?></div><?php endif; ?></td>
                                            <td class="pe-4"><span class="badge <?= $p['estado_transaccion'] === 'Aprobado' ? 'text-bg-success' : ($p['estado_transaccion'] === 'Rechazado' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= esc(str_replace('_', ' ', (string) $p['estado_transaccion'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$pagos): ?><tr><td colspan="6" class="vacio-ficha">No hay pagos registrados.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ver['casos']): ?>
                <!-- CASOS DE SOPORTE -->
                <div class="tab-pane fade" id="casos" role="tabpanel">
                    <div class="tarjeta-ficha overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 tabla-ficha" id="tablaCasos">
                                <thead><tr><th class="ps-4">Caso</th><th>Asunto y servicio</th><th>Prioridad</th><th>Estado</th><th>Especialista</th><th>Registrado</th><th class="pe-4">Tiempo de solución</th></tr></thead>
                                <tbody>
                                    <?php foreach ($casos as $t): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-nowrap"><a href="gestion_it.php?abrir=<?= urlencode((string) $t['codigo_ticket']) ?>" class="text-decoration-none"><?= esc($t['codigo_ticket'] ?: '#' . $t['id']) ?></a></td>
                                            <td><div class="fw-semibold text-dark"><?= esc($t['asunto']) ?></div><div class="small text-muted"><?= esc($t['categoria'] ?: $t['servicio']) ?><?= $t['grupo_resolutor'] ? ' · ' . esc($t['grupo_resolutor']) : '' ?></div></td>
                                            <td><span class="badge <?= $clase_prioridad[$t['prioridad']] ?? 'text-bg-secondary' ?>"><?= esc($t['prioridad']) ?></span> <span class="small text-muted"><?= esc($t['tipo']) ?></span></td>
                                            <td><span class="badge <?= $clase_caso[$t['comportamiento']] ?? 'text-bg-secondary' ?>"><?= esc($t['estado']) ?></span></td>
                                            <td class="small"><?= esc($t['tecnico_nombre'] ?: 'Sin asignar') ?></td>
                                            <td class="small"><?= esc($fecha($t['created_at'], true)) ?></td>
                                            <td class="pe-4"><?= $texto_sla($t) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$casos): ?><tr><td colspan="7" class="vacio-ficha">Este cliente no ha radicado casos de soporte.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ver['reuniones']): ?>
                <!-- REUNIONES -->
                <div class="tab-pane fade" id="reuniones" role="tabpanel">
                    <?php if (!$reuniones_con_cliente): ?>
                        <div class="alert alert-warning border-0 shadow-sm rounded-4">Para ver las reuniones de cada cliente, la base debe tener la columna <strong>reuniones_virtuales.cliente_id</strong> (viene incluida en <strong>database/datasense_analytics.sql</strong>).</div>
                    <?php else: ?>
                    <div class="tarjeta-ficha overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 tabla-ficha" id="tablaReuniones">
                                <thead><tr><th class="ps-4">Fecha y hora</th><th>Reunión</th><th>Estado</th><th class="text-end pe-4">Acciones</th></tr></thead>
                                <tbody>
                                    <?php foreach ($reuniones as $r): $pasada = $r['fecha'] . ' ' . $r['hora_fin'] < $ahora; ?>
                                        <tr>
                                            <td class="ps-4 small fw-semibold"><?= esc($fecha($r['fecha'])) ?><div class="text-muted fw-normal"><?= esc(substr((string) $r['hora_inicio'], 0, 5) . ' - ' . substr((string) $r['hora_fin'], 0, 5)) ?></div></td>
                                            <td><div class="fw-semibold text-dark"><?= esc($r['titulo']) ?></div><?php if (!empty($r['objetivo'])): ?><div class="small text-muted"><?= esc($r['objetivo']) ?></div><?php endif; ?></td>
                                            <td>
                                                <?php if ($r['estado'] === 'Finalizada'): ?><span class="badge text-bg-success">Acta firmada</span>
                                                <?php elseif ($pasada): ?><span class="badge text-bg-danger">Sin acta</span>
                                                <?php else: ?><span class="badge text-bg-primary"><?= esc($r['estado'] ?: 'Programada') ?></span><?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <?php if ($r['estado'] === 'Finalizada'): ?>
                                                    <a href="generar_acta.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger rounded-pill"><i class="fas fa-file-pdf me-1"></i> Acta</a>
                                                <?php elseif (!$pasada && !empty($r['link_reunion'])): ?>
                                                    <a href="<?= esc($r['link_reunion']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-video me-1"></i> Entrar</a>
                                                <?php else: ?>
                                                    <a href="salas_reuniones.php" class="btn btn-sm btn-outline-success rounded-pill"><i class="fas fa-pen-nib me-1"></i> Cerrar acta</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$reuniones): ?><tr><td colspan="4" class="vacio-ficha">No hay reuniones asociadas a este cliente.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($ver['compras']): ?>
                <!-- COMPRAS -->
                <div class="tab-pane fade" id="compras" role="tabpanel">
                    <div class="tarjeta-ficha overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 tabla-ficha" id="tablaCompras">
                                <thead><tr><th class="ps-4">Pedido</th><th>Fecha</th><th>Factura</th><th class="text-end">Total</th><th class="pe-4">Estado</th></tr></thead>
                                <tbody>
                                    <?php foreach ($compras as $c): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-nowrap"><a href="tienda_pedidos.php?pedido=<?= (int) $c['id'] ?>" class="text-decoration-none"><?= esc($c['numero']) ?></a></td>
                                            <td class="small"><?= esc($fecha($c['created_at'], true)) ?></td>
                                            <td class="small"><?= esc($c['numero_factura'] ?: '—') ?></td>
                                            <td class="text-end text-nowrap"><?= dinero($c['total']) ?></td>
                                            <td class="pe-4"><span class="badge <?= $clase_pedido[$c['estado']] ?? 'text-bg-warning' ?>"><?= esc($c['estado']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$compras): ?><tr><td colspan="5" class="vacio-ficha">Este cliente no ha comprado en la tienda.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ver['solicitudes']): ?>
                <!-- SOLICITUDES DEL FORMULARIO -->
                <div class="tab-pane fade" id="solicitudes" role="tabpanel">
                    <div class="tarjeta-ficha overflow-hidden">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 tabla-ficha" id="tablaSolicitudes">
                                <thead><tr><th class="ps-4">Fecha</th><th>Servicio de interés</th><th>Mensaje</th><th class="pe-4">Estado</th></tr></thead>
                                <tbody>
                                    <?php foreach ($solicitudes as $s): ?>
                                        <tr>
                                            <td class="ps-4 small"><?= esc($fecha($s['fecha_registro'], true)) ?></td>
                                            <td class="small fw-semibold"><?= esc(nombreServicio($s['servicio'])) ?><div class="text-muted fw-normal"><?= esc($s['tipo_cliente']) ?></div></td>
                                            <td class="small"><?= esc(mb_strimwidth((string) $s['mensaje'], 0, 180, '…', 'UTF-8')) ?></td>
                                            <td class="pe-4"><span class="badge text-bg-light border"><?= esc($s['estado']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$solicitudes): ?><tr><td colspan="4" class="vacio-ficha">No hay solicitudes del formulario con este cliente o su correo.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ver['encuestas']): ?>
                <!-- ENCUESTAS -->
                <div class="tab-pane fade" id="encuestas" role="tabpanel">
                    <div class="tarjeta-ficha overflow-hidden">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 tabla-ficha" id="tablaEncuestas">
                                <thead><tr><th class="ps-4">Fecha</th><th>Encuesta</th><th>Calificación</th><th class="pe-4">Comentario</th></tr></thead>
                                <tbody>
                                    <?php foreach ($encuestas as $e): ?>
                                        <tr>
                                            <td class="ps-4 small"><?= esc($fecha($e['fecha_respuesta'], true)) ?></td>
                                            <td class="small fw-semibold"><?= esc($e['titulo']) ?><?php if (!empty($e['codigo_ticket'])): ?><div class="text-muted fw-normal">Caso <?= esc($e['codigo_ticket']) ?></div><?php endif; ?></td>
                                            <td class="text-warning text-nowrap" title="<?= (int) $e['calificacion'] ?> de 5"><?= str_repeat('★', max(0, min(5, (int) $e['calificacion']))) ?><span class="text-muted"><?= str_repeat('☆', 5 - max(0, min(5, (int) $e['calificacion']))) ?></span></td>
                                            <td class="small pe-4"><?= esc($e['comentarios'] ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$encuestas): ?><tr><td colspan="4" class="vacio-ficha">Este cliente no ha respondido encuestas.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// La pestaña se recuerda en la dirección (#facturas, #casos...) para volver a ella al recargar o desde otros módulos
(function () {
    var abrir = function (hash) {
        var boton = hash && document.querySelector('.nav-ficha [data-bs-target="' + hash + '"]');
        if (boton) bootstrap.Tab.getOrCreateInstance(boton).show();
    };
    abrir(window.location.hash);
    document.querySelectorAll('.nav-ficha [data-bs-toggle="tab"]').forEach(function (boton) {
        boton.addEventListener('shown.bs.tab', function () {
            history.replaceState(null, '', window.location.pathname + window.location.search + boton.getAttribute('data-bs-target'));
        });
    });
})();
</script>
</body>
</html>
