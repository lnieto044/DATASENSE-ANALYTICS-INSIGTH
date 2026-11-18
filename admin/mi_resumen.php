<?php
/**
 * MI RESUMEN - EL PANEL DEL CLIENTE
 * Todo lo suyo en una sola pantalla: cuánto ha gastado y cuánto debe, su gasto mes a mes, qué ha
 * comprado (servicios y tienda) y cómo le han respondido sus casos. Cada consulta filtra por el
 * usuario que entró: aquí no se ve nada de la empresa ni de otros clientes.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/itsm.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'mi_resumen');
// Es el resumen de un cliente (sus compras, facturas y casos). El equipo ve el de la empresa en su página de inicio.
if (($_SESSION['admin_rol'] ?? 'Cliente') !== 'Cliente') {
    $inicio_rol = paginaInicio($_SESSION['admin_rol'], $db);
    if ($inicio_rol !== 'mi_resumen.php') {
        header('Location: ' . $inicio_rol);
        exit;
    }
}
actualizarVencimientos($db);

$id_usuario = (int) $_SESSION['admin_id'];
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$id_usuario]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = 'Mi Resumen';
$subtitulo = 'Tus compras, tu facturación y tus casos, en un solo lugar';

// ------------------------------------------------------------------ facturación (con los recaudos ya aplicados)
$aplicado_sql = sqlAplicadoFacturas();
$stmt_kpi = $db->prepare("SELECT
        COUNT(*) AS facturas,
        COALESCE(SUM(CASE WHEN f.estado <> 'Anulada' THEN f.monto_total ELSE 0 END), 0) AS facturado,
        COALESCE(SUM(CASE WHEN f.estado <> 'Anulada' THEN COALESCE(a.recaudado, 0) ELSE 0 END), 0) AS pagado,
        COALESCE(SUM(CASE WHEN f.estado NOT IN ('Pagado', 'Anulada') THEN GREATEST(f.monto_total - COALESCE(a.aprobado, 0), 0) ELSE 0 END), 0) AS saldo,
        SUM(f.estado NOT IN ('Pagado', 'Anulada') AND f.fecha_vencimiento < CURDATE()) AS vencidas
    FROM facturas f
    LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
    WHERE f.id_usuario = ?");
$stmt_kpi->execute([$id_usuario]);
$k = $stmt_kpi->fetch(PDO::FETCH_ASSOC) ?: ['facturas' => 0, 'facturado' => 0, 'pagado' => 0, 'saldo' => 0, 'vencidas' => 0];

// Gasto de los últimos 12 meses, incluidos los meses en cero para que la línea no engañe
$stmt_mes = $db->prepare("SELECT DATE_FORMAT(fecha_emision, '%Y-%m') AS mes, SUM(monto_total) AS total
                          FROM facturas WHERE id_usuario = ? AND estado <> 'Anulada'
                            AND fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                          GROUP BY mes ORDER BY mes");
$stmt_mes->execute([$id_usuario]);
$por_mes = $stmt_mes->fetchAll(PDO::FETCH_KEY_PAIR);
$meses_nombre = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun',
                 '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
$grafica = [];
$cursor = new DateTime(date('Y-m-01'));
$cursor->modify('-11 months');
for ($i = 0; $i < 12; $i++) {
    $clave = $cursor->format('Y-m');
    $grafica[] = ['mes' => $meses_nombre[$cursor->format('m')] . ' ' . $cursor->format('y'),
                  'total' => (float) ($por_mes[$clave] ?? 0)];
    $cursor->modify('+1 month');
}

// ------------------------------------------------------------------ qué ha comprado
$stmt_serv = $db->prepare("SELECT f.numero_factura, f.servicio_contratado, f.fecha_emision, f.fecha_vencimiento,
                                  f.monto_total, f.estado, GREATEST(f.monto_total - COALESCE(a.aprobado, 0), 0) AS saldo
                           FROM facturas f LEFT JOIN ($aplicado_sql) a ON a.factura_id = f.id
                           WHERE f.id_usuario = ? ORDER BY f.fecha_emision DESC, f.id DESC LIMIT 50");
$stmt_serv->execute([$id_usuario]);
$servicios = $stmt_serv->fetchAll(PDO::FETCH_ASSOC);

$pedidos = [];
try {
    $stmt_ped = $db->prepare("SELECT numero, total, estado, created_at FROM tienda_pedidos
                              WHERE usuario_id = ? ORDER BY id DESC LIMIT 20");
    $stmt_ped->execute([$id_usuario]);
    $pedidos = $stmt_ped->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Base sin tienda
}

// ------------------------------------------------------------------ sus casos y cómo le respondieron
$mis_casos = ticketsItsm($db, 't.usuario_id = ?', [$id_usuario]);
$casos = ['total' => count($mis_casos), 'abiertos' => 0, 'por_confirmar' => 0, 'resueltos' => 0, 'a_tiempo' => 0, 'minutos' => 0];
foreach ($mis_casos as $t) {
    if ($t['comportamiento'] === COMP_CERRADO) $casos['resueltos']++;
    elseif ($t['comportamiento'] === COMP_SOLUCIONADO) $casos['por_confirmar']++;
    else $casos['abiertos']++;
    if (!empty($t['fecha_resolucion']) && !empty($t['sla'])) {
        if (!empty($t['sla']['resolucion']['cumplido'])) $casos['a_tiempo']++;
        $casos['minutos'] += (int) $t['sla']['resolucion']['consumido'];
    }
}
$medidos = $casos['resueltos'] + $casos['por_confirmar'];
$casos['cumplimiento'] = $medidos ? round($casos['a_tiempo'] * 100 / $medidos, 1) : null;
$casos['promedio'] = $medidos ? (int) round($casos['minutos'] / $medidos) : null;
$cfg_itsm = configItsm($db);
$aviso = tomarAviso();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($titulo) ?> | DataSense Enterprise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .kpi-mio { background: #fff; border: 0; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); padding: 1.1rem; height: 100%; }
        .kpi-mio .etiqueta { font-size: .7rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .kpi-mio .valor { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
        .tabla-mia th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .grafico-mio { position: relative; height: 300px; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0"><i class="fas fa-circle-info me-2"></i><?= esc($aviso['texto']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <?php if ((int) $k['vencidas'] > 0): ?>
            <div class="alert alert-warning border-0 shadow-sm rounded-4" id="avisoVencidas"><i class="fas fa-triangle-exclamation me-2"></i>
                Tienes <strong><?= (int) $k['vencidas'] ?></strong> factura(s) vencida(s). <a href="mis_facturas.php" class="fw-bold">Págalas aquí</a>.</div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="kpi-mio" id="kpiFacturado"><div class="etiqueta">Has contratado</div><div class="valor"><?= dinero((float) $k['facturado']) ?></div><div class="small text-muted"><?= (int) $k['facturas'] ?> factura(s) en total</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-mio" id="kpiPagado"><div class="etiqueta">Has pagado</div><div class="valor text-success"><?= dinero((float) $k['pagado']) ?></div><div class="small text-muted">Pagos confirmados</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-mio" id="kpiSaldo"><div class="etiqueta">Te falta por pagar</div><div class="valor <?= (float) $k['saldo'] > 0 ? 'text-danger' : '' ?>"><?= dinero((float) $k['saldo']) ?></div><div class="small text-muted"><a href="mis_facturas.php" class="text-decoration-none">Ver mis facturas</a></div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-mio" id="kpiCasos"><div class="etiqueta">Mis casos</div><div class="valor text-primary"><?= (int) $casos['abiertos'] ?></div><div class="small text-muted"><?= (int) $casos['total'] ?> en total · <a href="soporte.php" class="text-decoration-none">abrir uno nuevo</a></div></div></div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1"><i class="fas fa-chart-column me-2 text-primary"></i>Tu gasto mes a mes</h2>
                <p class="small text-muted">Lo que te hemos facturado en los últimos 12 meses, sin contar las facturas anuladas.</p>
                <div class="grafico-mio"><canvas id="graficoGasto" aria-label="Gasto por mes" role="img"></canvas></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="fas fa-bag-shopping me-2 text-primary"></i>Qué has contratado</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-mia" id="tablaMisCompras">
                        <thead><tr><th class="ps-3">Factura</th><th>Servicio</th><th>Fecha</th><th class="text-end">Valor</th><th class="text-end">Saldo</th><th class="pe-3">Estado</th></tr></thead>
                        <tbody>
                            <?php foreach ($servicios as $s): ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-nowrap"><a href="mis_facturas.php" class="text-decoration-none"><?= esc($s['numero_factura']) ?></a></td>
                                    <td class="small"><?= esc($s['servicio_contratado'] ?: 'Servicio contratado') ?></td>
                                    <td class="small text-nowrap"><?= date('d/m/Y', strtotime($s['fecha_emision'])) ?></td>
                                    <td class="text-end fw-semibold text-nowrap"><?= dinero((float) $s['monto_total']) ?></td>
                                    <td class="text-end text-nowrap <?= (float) $s['saldo'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= dinero((float) $s['saldo']) ?></td>
                                    <td class="pe-3"><span class="badge text-bg-<?= $s['estado'] === 'Pagado' ? 'success' : ($s['estado'] === 'Anulada' ? 'secondary' : 'warning') ?>"><?= esc($s['estado']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$servicios): ?><tr><td colspan="6" class="text-center py-4 text-muted">Todavía no tienes servicios facturados.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pedidos): ?>
                    <h3 class="h6 fw-bold mt-4 mb-3"><i class="fas fa-store me-2 text-primary"></i>Compras en la tienda</h3>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-mia" id="tablaMisPedidos">
                            <thead><tr><th class="ps-3">Pedido</th><th>Fecha</th><th class="text-end">Total</th><th class="pe-3">Estado</th></tr></thead>
                            <tbody>
                                <?php foreach ($pedidos as $p): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-nowrap"><a href="mis_pedidos.php" class="text-decoration-none"><?= esc($p['numero']) ?></a></td>
                                        <td class="small text-nowrap"><?= date('d/m/Y', strtotime($p['created_at'])) ?></td>
                                        <td class="text-end fw-semibold text-nowrap"><?= dinero((float) $p['total']) ?></td>
                                        <td class="pe-3"><span class="badge text-bg-light border"><?= esc($p['estado']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1"><i class="fas fa-headset me-2 text-primary"></i>Cómo te hemos respondido</h2>
                <p class="small text-muted">Los tiempos se cuentan en horario de atención: <?= esc(textoHorarioItsm($cfg_itsm)) ?>.</p>
                <div class="row g-3" id="misTiempos">
                    <div class="col-6 col-lg-3"><div class="kpi-mio"><div class="etiqueta">Casos resueltos</div><div class="valor text-success"><?= (int) $casos['resueltos'] ?></div><div class="small text-muted"><?= (int) $casos['por_confirmar'] ?> esperando tu confirmación</div></div></div>
                    <div class="col-6 col-lg-3"><div class="kpi-mio"><div class="etiqueta">Abiertos hoy</div><div class="valor text-primary"><?= (int) $casos['abiertos'] ?></div><div class="small text-muted"><a href="soporte.php" class="text-decoration-none">Ver mis casos</a></div></div></div>
                    <div class="col-6 col-lg-3"><div class="kpi-mio"><div class="etiqueta">Respondidos a tiempo</div><div class="valor"><?= $casos['cumplimiento'] === null ? '—' : esc(number_format($casos['cumplimiento'], 1)) . '%' ?></div><div class="small text-muted">Del tiempo pactado</div></div></div>
                    <div class="col-6 col-lg-3"><div class="kpi-mio"><div class="etiqueta">Tiempo promedio</div><div class="valor"><?= $casos['promedio'] === null ? '—' : esc(duracionTexto($casos['promedio'])) ?></div><div class="small text-muted">En resolverte un caso</div></div></div>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const GASTO = <?= jsJson($grafica) ?>;
if (window.Chart) {
    new Chart(document.getElementById('graficoGasto'), {
        type: 'bar',
        data: {
            labels: GASTO.map((m) => m.mes),
            datasets: [{ label: 'Facturado en el mes', data: GASTO.map((m) => m.total), backgroundColor: '#0B4F8A', borderRadius: 6 }],
        },
        options: {
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (c) => '$ ' + Number(c.raw).toLocaleString('es-CO') } },
            },
            scales: { y: { beginAtZero: true, ticks: { callback: (v) => '$ ' + Number(v).toLocaleString('es-CO') } } },
        },
    });
}
</script>
</body>
</html>
