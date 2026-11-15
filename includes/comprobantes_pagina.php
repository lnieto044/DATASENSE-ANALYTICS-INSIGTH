<?php
/**
 * Listado y documento imprimible de los comprobantes de ingreso (CI), de egreso (CE) y de las notas contables (NC).
 * Lo incluyen admin/comprobante_ingreso.php, admin/comprobante_egreso.php y admin/nota_contable.php, que definen $tipo_comprobante.
 */
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';

$cfg = TIPOS_COMPROBANTE[$tipo_comprobante];
$usuario_actual = requiereAcceso($db, $cfg['modulo']);
$es_ingreso = ($tipo_comprobante === 'CI');
$es_nota = ($tipo_comprobante === 'NC');
$es_nota_factura = in_array($tipo_comprobante, ['NCV', 'NDV'], true);
$es_cxp = ($tipo_comprobante === 'FP');
$es_cierre = ($tipo_comprobante === 'CC');
// Textos que cambian con el tipo de documento
$etiqueta_tercero = ['CI' => 'Recibido de', 'CE' => 'Pagado a', 'NC' => 'Tercero', 'NCV' => 'Cliente', 'NDV' => 'Cliente', 'FP' => 'Proveedor', 'CC' => 'Tercero'][$tipo_comprobante];
$etiqueta_kpi = ['CI' => 'Recaudado este mes', 'CE' => 'Pagado este mes', 'NC' => 'Registrado este mes', 'NCV' => 'Acreditado este mes',
                 'NDV' => 'Cargado este mes', 'FP' => 'Causado este mes', 'CC' => 'Cerrado este mes'][$tipo_comprobante];
$etiqueta_nuevo = ['CI' => 'Nuevo ingreso', 'CE' => 'Nuevo egreso', 'NC' => 'Nueva nota contable', 'NCV' => 'Nueva nota crédito',
                   'NDV' => 'Nueva nota débito', 'FP' => 'Registrar factura de proveedor', 'CC' => ''][$tipo_comprobante];
$etiqueta_valor = ['NC' => 'Valor (sumas iguales)', 'CC' => 'Valor (sumas iguales)', 'NCV' => 'Valor de la nota', 'NDV' => 'Valor de la nota', 'FP' => 'Neto por pagar'][$tipo_comprobante] ?? 'Valor';

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = $cfg['plural'];
$subtitulo = [
    'CI'  => 'Recaudos de clientes y otros ingresos con su causación contable',
    'CE'  => 'Pagos a proveedores y gastos con su causación contable',
    'NC'  => 'Ajustes, depreciaciones, provisiones, causaciones y reclasificaciones que no son recaudos ni pagos',
    'NCV' => 'Descuentos, devoluciones y anulaciones que disminuyen lo que debe el cliente',
    'NDV' => 'Intereses, gastos y ajustes de precio que aumentan lo que debe el cliente',
    'FP'  => 'Facturas de proveedores causadas: se pagan después con comprobantes de egreso',
    'CC'  => 'Cierre anual: traslado de ingresos, costos y gastos a la utilidad del ejercicio',
][$tipo_comprobante];
$aviso = tomarAviso();

// Acciones del flujo que el usuario actual puede ejecutar sobre un comprobante
$acciones_permitidas = function (array $comp) use ($usuario_actual) {
    if ($comp['tipo'] === 'CC') return []; // Se generan y se anulan desde Cierre Contable
    $permitidas = [];
    foreach (['revisar', 'aprobar', 'anular'] as $accion) {
        if (errorFlujoComprobante($comp, $usuario_actual, $accion) === null) $permitidas[] = $accion;
    }
    return $permitidas;
};

$doc = null;
$id_doc = (int) ($_GET['id'] ?? 0);

if ($id_doc > 0) {
    // ---------------- DOCUMENTO
    $stmt = $db->prepare("SELECT c.*, f.numero_factura, f.monto_total AS total_factura, pr.soporte_visual, pr.moneda, pr.monto_moneda, pr.tasa_cambio,
                                 o.numero AS numero_origen, o.tipo AS tipo_origen,
                                 up.nombre AS nombre_preparador, ur.nombre AS nombre_revisor, ua.nombre AS nombre_aprobador, un.nombre AS nombre_anulador
                          FROM comprobantes c
                          LEFT JOIN facturas f ON f.id = c.factura_id
                          LEFT JOIN comprobantes o ON o.id = c.origen_id
                          LEFT JOIN pagos_registro pr ON pr.id = c.pago_id
                          LEFT JOIN usuarios up ON up.id = c.preparado_por
                          LEFT JOIN usuarios ur ON ur.id = c.revisado_por
                          LEFT JOIN usuarios ua ON ua.id = c.aprobado_por
                          LEFT JOIN usuarios un ON un.id = c.anulado_por
                          WHERE c.id = ? AND c.tipo = ?");
    $stmt->execute([$id_doc, $tipo_comprobante]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        avisoSiguiente('warning', 'El comprobante solicitado no existe.');
        header('Location: ' . $cfg['pagina']);
        exit;
    }

    $stmt_l = $db->prepare("SELECT d.codigo_puc, d.detalle, d.debito, d.credito, p.nombre AS nombre_cuenta
                            FROM comprobantes_detalle d
                            LEFT JOIN puc_cuentas p ON p.codigo = d.codigo_puc
                            WHERE d.comprobante_id = ?
                            ORDER BY (d.debito > 0) DESC, d.id");
    $stmt_l->execute([$id_doc]);
    $lineas = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
    $saldo_factura = $doc['factura_id'] ? (float) $doc['total_factura'] - aplicadoFactura($db, $doc['factura_id'])['aprobado'] : null;
    $puede = $acciones_permitidas($doc);

    // Cuenta por pagar: lo pagado, el saldo y los egresos que la pagan
    $cxp = $es_cxp ? cuentaPorPagar($db, $id_doc) : null;
    $estado_pago = $cxp ? estadoPagoCuentaPorPagar($cxp) : null;
    $pagos_cxp = [];
    if ($es_cxp) {
        $stmt_p = $db->prepare("SELECT id, numero, estado, valor_total FROM comprobantes WHERE tipo = 'CE' AND origen_id = ? ORDER BY fecha, id");
        $stmt_p->execute([$id_doc]);
        $pagos_cxp = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    // ---------------- LISTADO
    $filtro_estado = in_array($_GET['estado'] ?? '', array_merge(ESTADOS_COMPROBANTE, ['por_gestionar']), true) ? $_GET['estado'] : '';
    $buscar = trim((string) ($_GET['buscar'] ?? ''));
    $mes = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : '';
    $clases_filtro = $es_nota ? CLASES_NOTA : ($tipo_comprobante === 'NCV' ? MOTIVOS_NOTA_CREDITO : ($tipo_comprobante === 'NDV' ? MOTIVOS_NOTA_DEBITO : []));
    $filtro_clase = $clases_filtro && in_array($_GET['clase'] ?? '', $clases_filtro, true) ? $_GET['clase'] : '';
    $filtro_pago = $es_cxp && in_array($_GET['pago'] ?? '', ['saldo', 'vencidas'], true) ? $_GET['pago'] : '';

    $sql = "SELECT c.*, f.numero_factura, o.numero AS numero_origen, o.tipo AS tipo_origen,
                   COALESCE(px.pagado, 0) AS pagado, COALESCE(px.pago_en_tramite, 0) AS pago_en_tramite
            FROM comprobantes c
            LEFT JOIN facturas f ON f.id = c.factura_id
            LEFT JOIN comprobantes o ON o.id = c.origen_id
            LEFT JOIN (" . sqlPagadoCuentasPorPagar() . ") px ON px.cxp_id = c.id
            WHERE c.tipo = ?";
    $params = [$tipo_comprobante];
    if ($filtro_estado === 'por_gestionar') {
        $sql .= " AND c.estado IN ('Pendiente', 'Revisado')";
    } elseif ($filtro_estado !== '') {
        $sql .= " AND c.estado = ?";
        $params[] = $filtro_estado;
    }
    if ($buscar !== '') {
        $sql .= " AND (c.numero LIKE ? OR c.tercero_nombre LIKE ? OR c.tercero_documento LIKE ? OR c.concepto LIKE ? OR c.referencia LIKE ? OR f.numero_factura LIKE ?)";
        $params = array_merge($params, array_fill(0, 6, "%$buscar%"));
    }
    if ($mes !== '') {
        $sql .= " AND DATE_FORMAT(c.fecha, '%Y-%m') = ?";
        $params[] = $mes;
    }
    if ($filtro_clase !== '') {
        $sql .= " AND c.clase_nota = ?";
        $params[] = $filtro_clase;
    }
    $sql .= " ORDER BY c.fecha DESC, c.id DESC LIMIT 300";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $comprobantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($filtro_pago !== '') {
        // Cuentas por pagar con saldo pendiente, o solo las vencidas
        $comprobantes = array_values(array_filter($comprobantes, function ($c) use ($filtro_pago) {
            $estado_pago = estadoPagoCuentaPorPagar($c);
            return $filtro_pago === 'vencidas' ? $estado_pago === 'Vencida' : in_array($estado_pago, ['Por pagar', 'Pagada parcialmente', 'Vencida', 'Pago en trámite'], true);
        }));
    }

    // Indicadores: lo aprobado y anulado en el mes en curso y lo que espera revisión o aprobación
    $kpi = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN estado = 'Aprobado' AND fecha BETWEEN ? AND ? THEN valor_total END), 0) AS aprobado_mes,
            COALESCE(SUM(estado = 'Pendiente'), 0) AS por_revisar,
            COALESCE(SUM(CASE WHEN estado = 'Pendiente' THEN valor_total END), 0) AS valor_por_revisar,
            COALESCE(SUM(estado = 'Revisado'), 0) AS por_aprobar,
            COALESCE(SUM(CASE WHEN estado = 'Revisado' THEN valor_total END), 0) AS valor_por_aprobar,
            COALESCE(SUM(estado = 'Anulado' AND fecha BETWEEN ? AND ?), 0) AS anulados_mes
        FROM comprobantes WHERE tipo = ?");
    $kpi->execute([date('Y-m-01'), date('Y-m-t'), date('Y-m-01'), date('Y-m-t'), $tipo_comprobante]);
    $kpi = $kpi->fetch(PDO::FETCH_ASSOC);
    if ($es_cxp) {
        $kpi_cxp = $db->query("SELECT COALESCE(SUM(c.valor_total - COALESCE(p.pagado, 0)), 0) AS saldo,
                                      COALESCE(SUM(CASE WHEN c.fecha_vencimiento < CURDATE() THEN c.valor_total - COALESCE(p.pagado, 0) END), 0) AS vencido
                               FROM comprobantes c
                               LEFT JOIN (" . sqlPagadoCuentasPorPagar() . ") p ON p.cxp_id = c.id
                               WHERE c.tipo = 'FP' AND c.estado = 'Aprobado' AND c.valor_total - COALESCE(p.pagado, 0) > 0.5")->fetch(PDO::FETCH_ASSOC);
    }
}

$empresa_nit = $_ENV['EMPRESA_NIT'] ?? '900.000.000-1';
$empresa_ciudad = $_ENV['EMPRESA_CIUDAD'] ?? 'Bogotá, Colombia';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $doc ? esc($doc['numero']) : esc($cfg['plural']) ?> | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/contabilidad.css">
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <?php include __DIR__ . '/header.php'; ?>

        <div class="container-fluid py-4">
            <?php if ($aviso): ?>
                <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4 no-print" role="alert">
                    <i class="fas <?= $aviso['tipo'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i> <?= esc($aviso['texto']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

<?php if (!$doc): ?>
            <!-- ================= LISTADO ================= -->
            <?php if ($es_nota_factura): ?>
                <div class="d-flex gap-2 mb-4 pestanas-doc">
                    <a href="nota_credito.php" class="btn rounded-pill fw-bold px-4 <?= $tipo_comprobante === 'NCV' ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-file-circle-minus me-2"></i> Notas crédito</a>
                    <a href="nota_debito.php" class="btn rounded-pill fw-bold px-4 <?= $tipo_comprobante === 'NDV' ? 'btn-dark' : 'btn-outline-secondary bg-white' ?>"><i class="fas fa-file-circle-plus me-2"></i> Notas débito</a>
                </div>
            <?php endif; ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-xl-3">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-success shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase"><?= esc($etiqueta_kpi) ?></small>
                        <h4 class="fw-bold mb-0 text-success" data-kpi="aprobado_mes"><?= dinero($kpi['aprobado_mes']) ?></h4>
                        <small class="text-muted"><?= $es_nota || $es_nota_factura ? 'Notas aprobadas' : 'Comprobantes aprobados' ?></small>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <a href="?estado=Pendiente" class="text-decoration-none">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-warning shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Por revisar</small>
                        <h4 class="fw-bold mb-0 text-warning" data-kpi="por_revisar"><?= (int) $kpi['por_revisar'] ?></h4>
                        <small class="text-muted"><?= dinero($kpi['valor_por_revisar']) ?> esperan a Finanzas</small>
                    </div>
                    </a>
                </div>
                <div class="col-6 col-xl-3">
                    <a href="?estado=Revisado" class="text-decoration-none">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-primary shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Por aprobar</small>
                        <h4 class="fw-bold mb-0 text-primary" data-kpi="por_aprobar"><?= (int) $kpi['por_aprobar'] ?></h4>
                        <small class="text-muted"><?= dinero($kpi['valor_por_aprobar']) ?> esperan a Gerencia</small>
                    </div>
                    </a>
                </div>
                <div class="col-6 col-xl-3">
                    <?php if ($es_cxp): ?>
                    <a href="?pago=saldo" class="text-decoration-none">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-danger shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Saldo por pagar</small>
                        <h4 class="fw-bold mb-0 text-danger" data-kpi="saldo_por_pagar"><?= dinero($kpi_cxp['saldo']) ?></h4>
                        <small class="text-muted">Vencido: <span data-kpi="vencido_por_pagar"><?= dinero($kpi_cxp['vencido']) ?></span></small>
                    </div>
                    </a>
                    <?php else: ?>
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-secondary shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Anulados este mes</small>
                        <h4 class="fw-bold mb-0 text-secondary" data-kpi="anulados_mes"><?= (int) $kpi['anulados_mes'] ?></h4>
                        <small class="text-muted">Conservan su número sin efecto contable</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
                <form method="GET" class="d-flex flex-wrap gap-2 flex-grow-1">
                    <input type="search" name="buscar" class="form-control rounded-pill" style="max-width: 320px;" placeholder="N°, tercero, NIT, concepto o factura" value="<?= esc($buscar) ?>">
                    <select name="estado" class="form-select rounded-pill" style="max-width: 220px;">
                        <option value="">Todos los estados</option>
                        <option value="por_gestionar" <?= $filtro_estado === 'por_gestionar' ? 'selected' : '' ?>>Por revisar o aprobar</option>
                        <?php foreach (ESTADOS_COMPROBANTE as $estado): ?>
                            <option value="<?= $estado ?>" <?= $filtro_estado === $estado ? 'selected' : '' ?>><?= $estado ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="month" name="mes" class="form-control rounded-pill" style="max-width: 190px;" value="<?= esc($mes) ?>">
                    <?php if ($clases_filtro): ?>
                        <select name="clase" class="form-select rounded-pill" style="max-width: 240px;">
                            <option value=""><?= $es_nota ? 'Todas las clases' : 'Todos los motivos' ?></option>
                            <?php foreach ($clases_filtro as $clase): ?>
                                <option value="<?= esc($clase) ?>" <?= $filtro_clase === $clase ? 'selected' : '' ?>><?= esc($clase) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if ($es_cxp): ?>
                        <select name="pago" class="form-select rounded-pill" style="max-width: 220px;">
                            <option value="">Todas</option>
                            <option value="saldo" <?= $filtro_pago === 'saldo' ? 'selected' : '' ?>>Con saldo por pagar</option>
                            <option value="vencidas" <?= $filtro_pago === 'vencidas' ? 'selected' : '' ?>>Vencidas</option>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold" style="background: var(--azul-sonda);">Filtrar</button>
                    <?php if ($buscar !== '' || $filtro_estado !== '' || $mes !== '' || $filtro_clase !== '' || $filtro_pago !== ''): ?>
                        <a href="<?= $cfg['pagina'] ?>" class="btn btn-link text-decoration-none">Limpiar</a>
                    <?php endif; ?>
                </form>
                <?php if ($cfg['nuevo']): ?>
                    <a href="<?= esc($cfg['nuevo']) ?>" class="btn btn-success rounded-pill fw-bold px-4 shadow-sm" style="background: var(--verde-datasense); border: none;" id="btnNuevoDocumento">
                        <i class="fas fa-plus-circle me-2"></i> <?= esc($etiqueta_nuevo) ?>
                    </a>
                <?php else: ?>
                    <a href="cierre_contable.php" class="btn btn-outline-secondary rounded-pill fw-bold px-4 bg-white"><i class="fas fa-lock me-2"></i> Ir a Cierre Contable</a>
                <?php endif; ?>
            </div>

            <div class="panel-conta overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-conta">
                        <thead>
                            <tr>
                                <th class="ps-4">N°</th>
                                <th>Fecha</th>
                                <th><?= esc($etiqueta_tercero) ?></th>
                                <th>Concepto</th>
                                <?php if ($es_cxp): ?><th class="text-end">Saldo</th><?php endif; ?>
                                <th class="text-end">Valor</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$comprobantes): ?>
                                <tr><td colspan="<?= $es_cxp ? 8 : 7 ?>" class="text-center text-muted p-5">No hay documentos con esos filtros.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($comprobantes as $c): $puede_fila = $acciones_permitidas($c); ?>
                            <tr id="fila-comprobante-<?= (int) $c['id'] ?>">
                                <td class="ps-4"><a class="num-doc <?= $tipo_comprobante ?>" href="<?= $cfg['pagina'] ?>?id=<?= (int) $c['id'] ?>"><?= esc($c['numero']) ?></a></td>
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($c['fecha'])) ?>
                                    <?php if ($es_cxp && $c['fecha_vencimiento']): ?>
                                        <div class="small <?= $c['fecha_vencimiento'] < date('Y-m-d') ? 'text-danger fw-bold' : 'text-muted' ?>">Vence <?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= esc($c['tercero_nombre']) ?></div>
                                    <div class="small text-muted"><?= esc($c['tercero_documento'] ?: 'Sin documento') ?></div>
                                </td>
                                <td>
                                    <div class="text-truncate" style="max-width: 240px;" title="<?= esc($c['concepto']) ?>"><?= esc($c['concepto']) ?></div>
                                    <?php if ($c['numero_factura']): ?>
                                        <span class="small text-info fw-bold"><i class="fas fa-link"></i> <?= esc($c['numero_factura']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($c['numero_origen']): ?>
                                        <a class="small fw-bold text-decoration-none" href="<?= esc(urlDocumento($c['tipo_origen'], $c['origen_id'])) ?>"><i class="fas fa-link"></i> <?= esc($c['numero_origen']) ?></a>
                                    <?php endif; ?>
                                    <?php if ($es_cxp && $c['referencia']): ?>
                                        <span class="small text-muted">Fact. <?= esc($c['referencia']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['clase_nota'])): ?>
                                        <span class="badge bg-light text-secondary border"><?= esc($c['clase_nota']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($c['origen'] !== 'Manual'): ?>
                                        <span class="badge bg-light text-secondary border ms-1"><?= esc($c['origen']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($es_cxp): $estado_pago_fila = estadoPagoCuentaPorPagar($c); $saldo_fila = max(0, (float) $c['valor_total'] - (float) $c['pagado']); ?>
                                    <td class="money" data-saldo-cxp="<?= round($saldo_fila, 2) ?>">
                                        <?= dinero($saldo_fila, 2) ?>
                                        <div><span class="estado-doc <?= CLASES_ESTADO_PAGO[$estado_pago_fila] ?? '' ?>" data-estado-pago="<?= esc($estado_pago_fila) ?>"><?= esc($estado_pago_fila) ?></span></div>
                                    </td>
                                <?php endif; ?>
                                <td class="money"><?= dinero($c['valor_total'], 2) ?></td>
                                <td class="text-center"><span class="estado-doc estado-<?= esc($c['estado']) ?>"><?= esc($c['estado']) ?></span></td>
                                <td class="text-center pe-4 text-nowrap">
                                    <a href="<?= $cfg['pagina'] ?>?id=<?= (int) $c['id'] ?>" class="btn-circle" title="Ver documento"><i class="fas fa-file-alt text-primary"></i></a>
                                    <?php foreach (['revisar' => ['fa-check-double text-info', 'Firmar revisión', '¿Confirmas que la causación es correcta? Tu revisión quedará firmada.'],
                                                    'aprobar' => ['fa-pen-nib text-success', 'Aprobar', '¿Aprobar el comprobante? Desde ese momento afecta la contabilidad.']] as $accion => [$icono, $texto, $confirmacion]): ?>
                                        <?php if (in_array($accion, $puede_fila, true)): ?>
                                            <form method="POST" action="procesar_comprobante.php" class="d-inline" onsubmit="return confirm(<?= jsAttr($confirmacion) ?>);">
                                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                <input type="hidden" name="accion" value="<?= $accion ?>">
                                                <input type="hidden" name="volver" value="listado">
                                                <button type="submit" class="btn-circle ms-1" title="<?= $texto ?>"><i class="fas <?= $icono ?>"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (in_array('anular', $puede_fila, true)): ?>
                                        <button type="button" class="btn-circle ms-1" title="Anular" data-bs-toggle="modal" data-bs-target="#modalAnular"
                                                onclick="prepararAnulacion(<?= (int) $c['id'] ?>, <?= jsAttr($c['numero']) ?>, 'listado')">
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
            <p class="small text-muted mt-3 mb-0">
                <i class="fas fa-info-circle me-1"></i> Flujo: quien <b>prepara</b> registra el comprobante, <b>Finanzas</b> revisa la causación (no puede ser quien lo preparó)
                y un <b>Administrador</b> distinto a quien revisó lo aprueba. Solo los aprobados afectan el libro auxiliar<?= $es_ingreso ? ' y el saldo de las facturas' : '' ?>.
            </p>

<?php else: ?>
            <!-- ================= DOCUMENTO ================= -->
            <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <a href="<?= $cfg['pagina'] ?>" class="btn btn-outline-secondary rounded-pill px-4 bg-white"><i class="fas fa-arrow-left me-2"></i> Volver al listado</a>
                <div class="d-flex flex-wrap gap-2">
                    <?php if (in_array('revisar', $puede, true)): ?>
                        <form method="POST" action="procesar_comprobante.php" onsubmit="return confirm('¿Confirmas que la causación es correcta? Tu revisión quedará firmada.');">
                            <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>"><input type="hidden" name="accion" value="revisar">
                            <button type="submit" class="btn btn-info text-white rounded-pill fw-bold px-4" id="btnRevisar"><i class="fas fa-check-double me-2"></i> Firmar revisión</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array('aprobar', $puede, true)): ?>
                        <form method="POST" action="procesar_comprobante.php" onsubmit="return confirm('¿Aprobar el comprobante? Desde ese momento afecta la contabilidad.');">
                            <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>"><input type="hidden" name="accion" value="aprobar">
                            <button type="submit" class="btn btn-success rounded-pill fw-bold px-4" id="btnAprobar"><i class="fas fa-pen-nib me-2"></i> Aprobar</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array('anular', $puede, true)): ?>
                        <button type="button" class="btn btn-outline-danger rounded-pill fw-bold px-4 bg-white" id="btnAnular" data-bs-toggle="modal" data-bs-target="#modalAnular"
                                onclick="prepararAnulacion(<?= (int) $doc['id'] ?>, <?= jsAttr($doc['numero']) ?>, 'documento')">
                            <i class="fas fa-ban me-2"></i> Anular
                        </button>
                    <?php endif; ?>
                    <?php if ($es_cxp && $doc['estado'] === 'Aprobado' && $cxp['saldo'] > 0.5 && tieneAcceso($usuario_actual['rol'], 'comprobante_egreso', $db)): ?>
                        <a href="nuevo_egreso.php?cxp=<?= (int) $doc['id'] ?>" class="btn btn-success rounded-pill fw-bold px-4" id="btnPagarCxp"><i class="fas fa-money-check-alt me-2"></i> Registrar pago</a>
                    <?php endif; ?>
                    <?php if ($es_nota_factura && $doc['factura_id']): ?>
                        <a href="generar_pdf.php?id=<?= (int) $doc['factura_id'] ?>" target="_blank" class="btn btn-outline-secondary rounded-pill fw-bold px-4 bg-white"><i class="fas fa-file-invoice me-2"></i> Ver factura</a>
                    <?php endif; ?>
                    <?php if ($es_nota && $doc['estado'] === 'Aprobado'): ?>
                        <a href="nueva_nota_contable.php?reversar=<?= (int) $doc['id'] ?>" class="btn btn-outline-primary rounded-pill fw-bold px-4 bg-white" id="btnReversar" title="Crea una nota nueva con los débitos y créditos invertidos">
                            <i class="fas fa-undo me-2"></i> Nota de reversión
                        </a>
                    <?php endif; ?>
                    <button type="button" onclick="window.print()" class="btn btn-dark rounded-pill fw-bold px-4" style="background: var(--azul-sonda);"><i class="fas fa-print me-2"></i> Imprimir / PDF</button>
                </div>
            </div>

            <?php if ($doc['estado'] === 'Anulado'): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm no-print">
                    <i class="fas fa-ban me-2"></i> Anulado por <b><?= esc($doc['nombre_anulador'] ?? 'usuario eliminado') ?></b>
                    el <?= date('d/m/Y H:i', strtotime($doc['fecha_anulacion'])) ?>. Motivo: <i><?= esc($doc['motivo_anulacion']) ?></i>
                </div>
            <?php elseif ($doc['estado'] !== 'Aprobado'): ?>
                <div class="alert alert-warning rounded-4 border-0 shadow-sm no-print">
                    <i class="fas fa-hourglass-half me-2"></i> Documento <b><?= esc($doc['estado']) ?></b>: aún no afecta la contabilidad<?= $doc['factura_id'] ? ' ni el saldo de la factura' : ($es_cxp ? ' ni se puede pagar' : '') ?>.
                    <?= $doc['estado'] === 'Pendiente' ? 'Falta la revisión de Finanzas.' : 'Falta la aprobación de un Administrador.' ?>
                </div>
            <?php endif; ?>

            <div class="doc-papel <?= $tipo_comprobante ?>">
                <?php if ($doc['estado'] === 'Anulado'): ?>
                    <div class="doc-marca">Anulado - sin efecto</div>
                <?php elseif ($doc['estado'] !== 'Aprobado'): ?>
                    <div class="doc-marca">Preliminar - sin aprobar</div>
                <?php endif; ?>

                <div class="doc-cabecera">
                    <div>
                        <img src="../img/logo.png" alt="DataSense Analytics" class="mb-2">
                        <div class="doc-empresa"><b>DATASENSE ANALYTICS S.A.S.</b><br>NIT: <?= esc($empresa_nit) ?><br><?= esc($empresa_ciudad) ?></div>
                    </div>
                    <div class="doc-titulo">
                        <h2><?= mb_strtoupper($cfg['nombre'], 'UTF-8') ?></h2>
                        <div class="doc-numero">N° <?= esc($doc['numero']) ?></div>
                        <span class="estado-doc estado-<?= esc($doc['estado']) ?>"><?= esc($doc['estado']) ?></span>
                    </div>
                </div>

                <div class="doc-datos">
                    <div class="doc-fila"><div class="doc-etiqueta"><?= esc($etiqueta_tercero) ?></div><div class="doc-valor fw-bold text-uppercase"><?= esc($doc['tercero_nombre']) ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">NIT / C.C.</div><div class="doc-valor"><?= esc($doc['tercero_documento'] ?: 'No registrado') ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">Fecha</div><div class="doc-valor"><?= date('d/m/Y', strtotime($doc['fecha'])) ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">Por concepto de</div><div class="doc-valor fst-italic"><?= esc($doc['concepto']) ?></div></div>
                    <?php if ($doc['factura_id']): ?>
                        <div class="doc-fila"><div class="doc-etiqueta"><?= $es_nota_factura ? 'Factura afectada' : 'Factura abonada' ?></div><div class="doc-valor">
                            <b><?= esc($doc['numero_factura']) ?></b> · total <?= dinero($doc['total_factura']) ?> ·
                            <span id="saldoFactura">saldo por recaudar <?= dinero(max(0, $saldo_factura)) ?></span>
                        </div></div>
                    <?php endif; ?>
                    <?php if ($doc['origen_id']): ?>
                        <div class="doc-fila"><div class="doc-etiqueta"><?= $doc['tipo_origen'] === 'FP' ? 'Paga la cuenta por pagar' : 'Documento de origen' ?></div><div class="doc-valor">
                            <a class="num-doc <?= esc($doc['tipo_origen']) ?>" href="<?= esc(urlDocumento($doc['tipo_origen'], $doc['origen_id'])) ?>" id="documentoOrigen"><?= esc($doc['numero_origen']) ?></a>
                        </div></div>
                    <?php endif; ?>
                    <?php if ($es_cxp): ?>
                        <div class="doc-fila"><div class="doc-etiqueta">Factura del proveedor</div><div class="doc-valor fw-bold" id="facturaProveedorDoc"><?= esc($doc['referencia'] ?: '-') ?></div></div>
                        <div class="doc-fila"><div class="doc-etiqueta">Vence</div><div class="doc-valor"><?= $doc['fecha_vencimiento'] ? date('d/m/Y', strtotime($doc['fecha_vencimiento'])) : '-' ?></div></div>
                        <div class="doc-fila"><div class="doc-etiqueta">Estado de pago</div><div class="doc-valor" id="estadoPagoCxp">
                            <span class="estado-doc <?= CLASES_ESTADO_PAGO[$estado_pago] ?? '' ?>"><?= esc($estado_pago) ?></span>
                            <span class="small ms-2">Pagado <?= dinero($cxp['pagado'], 2) ?> · Saldo <b id="saldoCxp" data-valor="<?= round(max(0, (float) $cxp['valor_total'] - (float) $cxp['pagado']), 2) ?>"><?= dinero(max(0, (float) $cxp['valor_total'] - (float) $cxp['pagado']), 2) ?></b></span>
                            <?php foreach ($pagos_cxp as $pago_cxp): ?>
                                <a class="num-doc CE small ms-1 no-print" href="comprobante_egreso.php?id=<?= (int) $pago_cxp['id'] ?>" title="<?= esc($pago_cxp['estado']) ?>"><?= esc($pago_cxp['numero']) ?></a>
                            <?php endforeach; ?>
                        </div></div>
                    <?php elseif ($es_nota || $es_nota_factura || $es_cierre): ?>
                        <div class="doc-fila"><div class="doc-etiqueta"><?= $es_nota ? 'Clase de nota' : ($es_cierre ? 'Tipo de cierre' : 'Motivo') ?></div><div class="doc-valor fw-bold" id="claseNotaDoc"><?= esc($doc['clase_nota'] ?: 'Sin clasificar') ?></div></div>
                        <?php if ($es_nota): ?>
                            <div class="doc-fila"><div class="doc-etiqueta">Soporte</div><div class="doc-valor"><?= esc($doc['referencia'] ?: 'Sin referencia') ?></div></div>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="doc-fila"><div class="doc-etiqueta">Forma de pago</div><div class="doc-valor">
                        <?= esc($doc['metodo_pago']) ?><?= $doc['referencia'] ? ' · Ref. ' . esc($doc['referencia']) : '' ?>
                        <?php if ($doc['soporte_visual']): ?>
                            <a href="descargar_soporte_pago.php?id=<?= (int) $doc['pago_id'] ?>" target="_blank" class="no-print small ms-2" id="verSoporte"><i class="fas fa-paperclip"></i> Ver soporte</a>
                        <?php endif; ?>
                    </div></div>
                    <?php endif; ?>
                    <?php if ($doc['moneda']): ?>
                        <div class="doc-fila"><div class="doc-etiqueta">Moneda extranjera</div><div class="doc-valor" id="monedaExtranjera">
                            <?= number_format((float) $doc['monto_moneda'], 2, ',', '.') ?> <?= esc($doc['moneda']) ?> a TRM <?= number_format((float) $doc['tasa_cambio'], 2, ',', '.') ?>
                        </div></div>
                    <?php endif; ?>
                    <div class="doc-fila"><div class="doc-etiqueta"><?= esc($etiqueta_valor) ?></div><div class="doc-valor fw-bold fs-5" style="color: var(--doc-color);"><?= dinero($doc['valor_total'], 2) ?></div></div>
                    <div class="doc-fila"><div class="doc-etiqueta">En letras</div><div class="doc-valor fw-bold text-uppercase" id="valorLetras"><?= esc(numeroALetras($doc['valor_total'])) ?></div></div>
                </div>

                <div class="doc-subtitulo">Causación contable</div>
                <div class="table-responsive">
                    <table class="doc-causacion">
                        <thead><tr><th style="width: 12%;">Código</th><th style="width: 30%;">Cuenta</th><th>Detalle</th><th style="width: 15%;">Débito</th><th style="width: 15%;">Crédito</th></tr></thead>
                        <tbody>
                            <?php $total_debito = 0; $total_credito = 0; ?>
                            <?php foreach ($lineas as $l): $total_debito += $l['debito']; $total_credito += $l['credito']; ?>
                                <tr>
                                    <td class="text-center fw-bold text-secondary"><?= esc($l['codigo_puc']) ?></td>
                                    <td class="fw-bold"><?= esc($l['nombre_cuenta'] ?? 'Cuenta no registrada') ?></td>
                                    <td class="small text-muted"><?= esc($l['detalle']) ?></td>
                                    <td class="money"><?= $l['debito'] > 0 ? dinero($l['debito'], 2) : '-' ?></td>
                                    <td class="money"><?= $l['credito'] > 0 ? dinero($l['credito'], 2) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-uppercase small">Sumas iguales</td>
                                <td class="money" id="totalDebito"><?= dinero($total_debito, 2) ?></td>
                                <td class="money" id="totalCredito"><?= dinero($total_credito, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php
                $automatico = in_array($doc['origen'], ['Pasarela', 'Migración'], true) && !$doc['preparado_por'];
                $sellos = [
                    ['Preparado por', $doc['nombre_preparador'] ?: ($automatico ? ($doc['origen'] === 'Pasarela' ? 'Pasarela de pagos' : 'Migración de datos') : null), $doc['created_at'], 'Registro en sistema', 'azul'],
                    ['Revisado por (Finanzas)', $doc['nombre_revisor'] ?: ($doc['estado'] === 'Aprobado' ? ($automatico ? 'Confirmado por el banco' : ($doc['origen'] === 'Cierre' ? 'Cierre contable' : null)) : null), $doc['fecha_revision'], 'Revisión contable', 'azul'],
                    ['Aprobado por (Gerencia)', $doc['estado'] === 'Aprobado' ? ($doc['nombre_aprobador'] ?: 'Aprobación automática') : null, $doc['fecha_aprobacion'], 'Aprobado electrónicamente', ''],
                ];
                ?>
                <div class="doc-firmas">
                    <?php foreach ($sellos as [$rol_firma, $nombre_firma, $fecha_firma, $titulo_sello, $color_sello]): ?>
                        <div>
                            <?php if ($nombre_firma): ?>
                                <div class="doc-sello <?= $color_sello ?>">
                                    <div class="doc-sello-titulo text-uppercase"><?= $titulo_sello ?></div>
                                    <div class="doc-sello-nombre"><?= esc($nombre_firma) ?></div>
                                    <div class="doc-sello-meta"><?= $fecha_firma ? date('d/m/Y H:i', strtotime($fecha_firma)) : '' ?> · <?= esc($doc['numero']) ?></div>
                                </div>
                            <?php else: ?>
                                <div class="doc-sello vacio"><div class="doc-sello-titulo">PENDIENTE</div></div>
                            <?php endif; ?>
                            <div class="doc-rol"><?= $rol_firma ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
<?php endif; ?>
        </div>
    </main>
</div>

<div class="modal fade" id="modalAnular" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="procesar_comprobante.php">
                <div class="modal-header border-0 bg-danger bg-opacity-10">
                    <h5 class="fw-bold text-danger mb-0"><i class="fas fa-ban me-2"></i> Anular <span id="anularNumero"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id" id="anularId">
                    <input type="hidden" name="accion" value="anular">
                    <input type="hidden" name="volver" id="anularVolver" value="listado">
                    <p class="small text-muted">El comprobante no se borra: queda <b>Anulado</b>, sin efecto contable y conserva su número.<?= $es_ingreso ? ' Si abonaba una factura, el saldo de la factura se restablece.' : '' ?></p>
                    <label class="small fw-bold text-muted mb-2">Motivo de la anulación *</label>
                    <textarea name="motivo" class="form-control" rows="3" minlength="10" maxlength="255" required placeholder="Ej: El valor se digitó mal; se registra de nuevo."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill fw-bold px-4">Anular comprobante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function prepararAnulacion(id, numero, volver) {
    document.getElementById('anularId').value = id;
    document.getElementById('anularNumero').textContent = numero;
    document.getElementById('anularVolver').value = volver;
}
</script>
</body>
</html>
