<?php
/**
 * TIENDA - PEDIDOS (Administrador y rol con el módulo "tienda")
 * Indicadores del mes, filtros por estado y búsqueda, detalle del pedido (cliente, entrega, productos, totales e historial)
 * y cambio de estado con guía de envío. El pago lo confirma la factura (sincronizarPedidos); cancelar anula la factura.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/tienda.php';
$db = require __DIR__ . '/../config/database.php';

$usuario = requiereAcceso($db, 'tienda');
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario['id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = 'Pedidos de la Tienda';
$subtitulo = 'Pagos, preparación, envíos y entregas';

sincronizarPedidos($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'estado') {
    $pedido_id = (int) ($_POST['pedido_id'] ?? 0);
    $nuevo = (string) ($_POST['estado'] ?? '');
    $error = isset(ESTADOS_PEDIDO[$nuevo]) ? cambiarEstadoPedido($db, $pedido_id, $nuevo, $usuario, $_POST['detalle'] ?? '', $_POST['guia'] ?? '') : 'Elige un estado válido.';
    avisoSiguiente($error ? 'danger' : 'success', $error ?: "Pedido actualizado a «{$nuevo}».");
    header('Location: tienda_pedidos.php?pedido=' . $pedido_id);
    exit;
}

$estado = is_string($_GET['estado'] ?? null) && isset(ESTADOS_PEDIDO[$_GET['estado']]) ? $_GET['estado'] : '';
$busqueda = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$todos = pedidosTienda($db, ['limite' => 1000]);
$conteo = array_fill_keys(array_keys(ESTADOS_PEDIDO), 0);
foreach ($todos as $p) $conteo[$p['estado']] = ($conteo[$p['estado']] ?? 0) + 1;
$pedidos = pedidosTienda($db, ['estado' => $estado, 'q' => $busqueda]);
$resumen = resumenTienda($db, date('Y-m-01'), date('Y-m-d'));
$aviso = tomarAviso();

$datos_js = [];
foreach ($pedidos as $p) {
    $transiciones = array_values(array_filter(TRANSICIONES_PEDIDO[$p['estado']] ?? [], function ($e) use ($p) { return $e !== 'Enviado' || (int) $p['fisicos'] > 0; }));
    $datos_js[(int) $p['id']] = [
        'id' => (int) $p['id'], 'numero' => $p['numero'], 'estado' => $p['estado'], 'color' => ESTADOS_PEDIDO[$p['estado']] ?? 'secondary', 'fecha' => date('d/m/Y H:i', strtotime($p['created_at'])),
        'cliente' => $p['cliente'], 'correo' => $p['correo_cliente'], 'telefono' => $p['telefono_cliente'], 'organizacion' => $p['organizacion'],
        'factura' => $p['numero_factura'], 'factura_id' => (int) $p['factura_id'], 'estado_factura' => $p['estado_factura'],
        'entrega' => $p['entrega_direccion'] ? [$p['entrega_nombre'], $p['entrega_telefono'], $p['entrega_direccion'], $p['entrega_ciudad']] : null, 'guia' => $p['guia_envio'], 'notas' => $p['notas'],
        'subtotal' => dinero($p['subtotal']), 'descuento' => (float) $p['descuento'] > 0 ? dinero($p['descuento']) : null, 'cupon' => $p['cupon'],
        'envio' => (int) $p['fisicos'] > 0 ? ((float) $p['envio'] > 0 ? dinero($p['envio']) : 'Gratis') : null, 'iva' => dinero($p['iva']), 'total' => dinero($p['total']),
        'items' => array_map(function ($i) { return [$i['nombre'], $i['sku'], (int) $i['cantidad'], dinero($i['precio_unitario']), dinero($i['total'])]; }, itemsPedido($db, $p['id'])),
        'historial' => array_map(function ($h) { return [date('d/m/Y H:i', strtotime($h['fecha'])), $h['estado'], $h['detalle'], $h['usuario'] ?: 'Plataforma']; }, historialPedido($db, $p['id'])),
        'transiciones' => $transiciones,
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedidos de la Tienda | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .kpi-tienda { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; height: 100%; display: flex; gap: 12px; align-items: center; }
        .kpi-tienda .icono { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .kpi-tienda small { display: block; font-size: .7rem; text-transform: uppercase; font-weight: 700; color: #64748b; }
        .kpi-tienda b { font-size: 1.25rem; color: #0f172a; }
        .filtro-estado { border: 1px solid #e2e8f0; background: #fff; border-radius: 999px; padding: 6px 14px; font-size: .82rem; font-weight: 600; color: #334155; }
        .filtro-estado.activo { background: #0A2E4F; color: #fff; border-color: #0A2E4F; }
        .tabla-pedidos th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .tabla-pedidos td { vertical-align: middle; font-size: .88rem; }
        .linea-historial { border-left: 2px solid #e2e8f0; padding: 0 0 12px 14px; position: relative; font-size: .84rem; }
        .linea-historial::before { content: ''; position: absolute; left: -7px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #0A2E4F; border: 2px solid #fff; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show shadow-sm border-0" role="alert" id="avisoPedidos"><?= esc($aviso['texto']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-2"><div class="kpi-tienda"><span class="icono bg-success bg-opacity-10 text-success"><i class="fas fa-sack-dollar"></i></span><div><small>Ventas pagadas (mes)</small><b data-kpi="ventas"><?= dinero($resumen['ventas']) ?></b></div></div></div>
            <div class="col-6 col-xl-2"><div class="kpi-tienda"><span class="icono bg-primary bg-opacity-10 text-primary"><i class="fas fa-receipt"></i></span><div><small>Pedidos del mes</small><b><?= (int) $resumen['pedidos'] ?></b></div></div></div>
            <div class="col-6 col-xl-2"><div class="kpi-tienda"><span class="icono bg-info bg-opacity-10 text-info"><i class="fas fa-scale-balanced"></i></span><div><small>Ticket promedio</small><b><?= dinero($resumen['ticket_promedio']) ?></b></div></div></div>
            <div class="col-6 col-xl-2"><div class="kpi-tienda"><span class="icono bg-warning bg-opacity-10 text-warning"><i class="fas fa-hourglass-half"></i></span><div><small>Por pagar</small><b data-kpi="por_pagar"><?= (int) $resumen['por_pagar'] ?></b></div></div></div>
            <div class="col-6 col-xl-2"><div class="kpi-tienda"><span class="icono bg-danger bg-opacity-10 text-danger"><i class="fas fa-box-open"></i></span><div><small>Por atender</small><b data-kpi="por_atender"><?= (int) $resumen['por_atender'] ?></b></div></div></div>
            <div class="col-6 col-xl-2"><div class="kpi-tienda"><span class="icono bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-warehouse"></i></span><div><small>Agotados / bajo stock</small><b><?= (int) $resumen['agotados'] ?> / <?= (int) $resumen['bajo_stock'] ?></b></div></div></div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <div class="d-flex flex-wrap gap-2" role="group" aria-label="Filtrar por estado">
                        <a href="tienda_pedidos.php<?= $busqueda !== '' ? '?q=' . urlencode($busqueda) : '' ?>" class="filtro-estado <?= $estado === '' ? 'activo' : '' ?>">Todos <span class="opacity-75"><?= count($todos) ?></span></a>
                        <?php foreach (ESTADOS_PEDIDO as $nombre => $color): ?>
                            <a href="?<?= esc(http_build_query(array_filter(['estado' => $nombre, 'q' => $busqueda]))) ?>" class="filtro-estado <?= $estado === $nombre ? 'activo' : '' ?>"><?= esc($nombre) ?> <span class="opacity-75"><?= (int) $conteo[$nombre] ?></span></a>
                        <?php endforeach; ?>
                    </div>
                    <form class="d-flex gap-2" method="get">
                        <?php if ($estado !== ''): ?><input type="hidden" name="estado" value="<?= esc($estado) ?>"><?php endif; ?>
                        <input type="search" name="q" value="<?= esc($busqueda) ?>" class="form-control form-control-sm rounded-pill" placeholder="Pedido, cliente, correo o factura" aria-label="Buscar pedidos">
                        <button class="btn btn-sm btn-dark rounded-pill px-3" style="background:var(--azul-sonda)"><i class="fas fa-magnifying-glass"></i></button>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover tabla-pedidos mb-0" id="tablaPedidosTienda">
                        <thead><tr><th>Pedido</th><th>Cliente</th><th>Productos</th><th class="text-end">Total</th><th>Factura</th><th>Estado</th><th class="text-center">Gestionar</th></tr></thead>
                        <tbody>
                            <?php foreach ($pedidos as $p): ?>
                            <tr data-pedido="<?= esc($p['numero']) ?>">
                                <td><b><?= esc($p['numero']) ?></b><div class="small text-muted"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></div></td>
                                <td><?= esc($p['cliente']) ?><div class="small text-muted"><?= esc($p['correo_cliente']) ?></div></td>
                                <td><?= (int) $p['unidades'] ?> unidad(es)<?= (int) $p['fisicos'] > 0 ? ' <i class="fas fa-truck text-secondary" title="Incluye productos físicos"></i>' : '' ?></td>
                                <td class="text-end fw-bold text-nowrap"><?= dinero($p['total']) ?></td>
                                <td><?= esc($p['numero_factura'] ?: '—') ?><div><span class="badge text-bg-light border"><?= esc($p['estado_factura'] ?: '') ?></span></div></td>
                                <td><span class="badge rounded-pill text-bg-<?= ESTADOS_PEDIDO[$p['estado']] ?? 'secondary' ?>"><?= esc($p['estado']) ?></span></td>
                                <td class="text-center"><button type="button" class="btn btn-sm btn-light border" data-gestionar="<?= (int) $p['id'] ?>" aria-label="Gestionar <?= esc($p['numero']) ?>"><i class="fas fa-pen-to-square"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$pedidos): ?>
                                <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-bag-shopping fa-2x d-block mb-2 opacity-50"></i>No hay pedidos<?= $estado || $busqueda ? ' con esos filtros' : ' todavía' ?>.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalPedido" tabindex="-1" aria-labelledby="tituloPedido">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <div class="modal-content border-0">
            <div class="modal-header">
                <div>
                    <div class="small text-muted text-uppercase fw-bold">Pedido de la tienda</div>
                    <h5 class="modal-title fw-bold" id="tituloPedido">—</h5>
                </div>
                <span class="badge rounded-pill ms-3" id="estadoPedidoModal"></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6"><div class="border rounded-3 p-3 h-100"><div class="small text-muted fw-bold text-uppercase mb-1">Cliente</div><div id="pedidoCliente"></div></div></div>
                            <div class="col-md-6"><div class="border rounded-3 p-3 h-100"><div class="small text-muted fw-bold text-uppercase mb-1">Entrega</div><div id="pedidoEntrega"></div></div></div>
                        </div>
                        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">Unitario</th><th class="text-end">Total</th></tr></thead><tbody id="pedidoItems"></tbody><tfoot id="pedidoTotales"></tfoot></table></div>
                        <div class="small text-muted" id="pedidoNotas"></div>
                    </div>
                    <div class="col-lg-5">
                        <div class="border rounded-3 p-3 mb-3 bg-light">
                            <div class="fw-bold mb-2"><i class="fas fa-arrows-rotate me-1"></i>Actualizar estado</div>
                            <form method="post" id="formEstadoPedido">
                                <input type="hidden" name="accion" value="estado">
                                <input type="hidden" name="pedido_id" id="pedido_id">
                                <div id="sinTransiciones" class="small text-muted" hidden></div>
                                <div id="bloqueTransiciones">
                                    <label class="form-label small fw-semibold" for="pedido_estado">Nuevo estado</label>
                                    <select name="estado" id="pedido_estado" class="form-select form-select-sm mb-2"></select>
                                    <div id="bloqueGuia" hidden><label class="form-label small fw-semibold" for="pedido_guia">Guía de envío</label><input type="text" name="guia" id="pedido_guia" maxlength="80" class="form-control form-control-sm mb-2" placeholder="Transportadora y número de guía"></div>
                                    <label class="form-label small fw-semibold" for="pedido_detalle">Detalle (queda en el historial)</label>
                                    <textarea name="detalle" id="pedido_detalle" rows="2" maxlength="300" class="form-control form-control-sm mb-2"></textarea>
                                    <div class="small text-danger mb-2" id="avisoCancelar" hidden><i class="fas fa-triangle-exclamation me-1"></i>Cancelar anula la factura y devuelve las unidades al inventario.</div>
                                    <button type="submit" class="btn btn-sm btn-dark w-100" style="background:var(--azul-sonda)" id="btnActualizarPedido"><i class="fas fa-save me-1"></i>Actualizar pedido</button>
                                </div>
                                <div class="small text-muted mt-2">El paso a «Pagado» es automático cuando la factura queda pagada.</div>
                            </form>
                        </div>
                        <div class="fw-bold mb-2"><i class="fas fa-clock-rotate-left me-1"></i>Historial</div>
                        <div id="pedidoHistorial"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PEDIDOS = <?= jsJson($datos_js) ?>;
function escHtml(t) { return String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function abrirPedido(id) {
    const p = PEDIDOS[id];
    if (!p) return;
    document.getElementById('tituloPedido').innerText = `${p.numero} · ${p.fecha}`;
    const estado = document.getElementById('estadoPedidoModal');
    estado.className = `badge rounded-pill ms-3 text-bg-${p.color}`;
    estado.innerText = p.estado;
    document.getElementById('pedido_id').value = p.id;
    document.getElementById('pedidoCliente').innerHTML = `<b>${escHtml(p.cliente)}</b><div class="small">${escHtml(p.organizacion || '')}</div>
        <div class="small"><i class="far fa-envelope me-1"></i>${escHtml(p.correo)}</div>${p.telefono ? `<div class="small"><i class="fas fa-phone me-1"></i>${escHtml(p.telefono)}</div>` : ''}
        <div class="small mt-1">Factura <b>${escHtml(p.factura || '—')}</b> · ${escHtml(p.estado_factura || '')} ${p.factura_id ? `<a href="generar_pdf.php?id=${p.factura_id}" target="_blank">PDF</a>` : ''}</div>`;
    document.getElementById('pedidoEntrega').innerHTML = p.entrega
        ? `<b>${escHtml(p.entrega[0])}</b> · ${escHtml(p.entrega[1])}<div class="small">${escHtml(p.entrega[2])}, ${escHtml(p.entrega[3])}</div>${p.guia ? `<div class="small mt-1"><b>Guía:</b> ${escHtml(p.guia)}</div>` : ''}`
        : '<span class="small text-muted">Productos digitales o servicios: sin envío físico.</span>';
    document.getElementById('pedidoItems').innerHTML = p.items.map((i) => `<tr><td>${escHtml(i[0])}<div class="small text-muted">${escHtml(i[1])}</div></td><td class="text-center">${i[2]}</td><td class="text-end">${escHtml(i[3])}</td><td class="text-end">${escHtml(i[4])}</td></tr>`).join('');
    const fila = (a, b, clase = '') => `<tr class="${clase}"><td colspan="3" class="text-end">${a}</td><td class="text-end">${b}</td></tr>`;
    document.getElementById('pedidoTotales').innerHTML = fila('Subtotal', escHtml(p.subtotal))
        + (p.descuento ? fila(`Descuento ${p.cupon ? '(' + escHtml(p.cupon) + ')' : ''}`, '−' + escHtml(p.descuento), 'text-success') : '')
        + (p.envio ? fila('Envío', escHtml(p.envio)) : '') + fila('IVA incluido', escHtml(p.iva), 'text-muted') + fila('<b>Total</b>', `<b>${escHtml(p.total)}</b>`);
    document.getElementById('pedidoNotas').innerText = p.notas ? 'Notas del cliente: ' + p.notas : '';
    document.getElementById('pedidoHistorial').innerHTML = p.historial.map((h) => `<div class="linea-historial"><b>${escHtml(h[1])}</b> <span class="text-muted">· ${escHtml(h[0])} · ${escHtml(h[3])}</span><div>${escHtml(h[2] || '')}</div></div>`).join('');
    const select = document.getElementById('pedido_estado');
    select.innerHTML = p.transiciones.map((t) => `<option value="${escHtml(t)}">${escHtml(t)}</option>`).join('');
    document.getElementById('bloqueTransiciones').hidden = !p.transiciones.length;
    const sin = document.getElementById('sinTransiciones');
    sin.hidden = !!p.transiciones.length;
    sin.innerText = p.estado === 'Pendiente de pago' ? '' : `El pedido está «${p.estado}»: no tiene más cambios de estado.`;
    document.getElementById('pedido_detalle').value = '';
    document.getElementById('pedido_guia').value = p.guia || '';
    actualizarAyudas();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPedido')).show();
}
function actualizarAyudas() {
    const valor = document.getElementById('pedido_estado').value;
    document.getElementById('bloqueGuia').hidden = valor !== 'Enviado';
    document.getElementById('avisoCancelar').hidden = valor !== 'Cancelado';
}
document.getElementById('pedido_estado').addEventListener('change', actualizarAyudas);
document.getElementById('formEstadoPedido').addEventListener('submit', function (e) {
    if (document.getElementById('pedido_estado').value === 'Cancelado' && !confirm('¿Cancelar el pedido? Se anulará su factura.')) e.preventDefault();
});
document.addEventListener('click', function (e) {
    const boton = e.target.closest('[data-gestionar]');
    if (boton) abrirPedido(Number(boton.dataset.gestionar));
});
const pedidoUrl = Number(new URLSearchParams(window.location.search).get('pedido'));
if (pedidoUrl && PEDIDOS[pedidoUrl]) abrirPedido(pedidoUrl);
</script>
</body>
</html>
