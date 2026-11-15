<?php
// Cierre contable: estado de cada mes, cierre en orden (bloquea registrar o modificar documentos con esa fecha),
// reapertura con motivo por un Administrador y cierre anual automático al cerrar diciembre.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/cierre_contable.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'cierre_contable');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mes = is_string($_POST['mes'] ?? null) ? $_POST['mes'] : '';
    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
    try {
        if ($accion === 'cerrar') {
            $documentos = cerrarMes($db, $mes, $usuario['id']);
            avisoSiguiente('success', 'Mes de ' . nombreMes($mes) . ' cerrado: ya no se pueden registrar ni modificar documentos con esa fecha.'
                . ($documentos ? ' Cierre anual generado: ' . implode(', ', $documentos) . '.' : ''));
        } elseif ($accion === 'reabrir') {
            $anulados = reabrirMes($db, $mes, $usuario, is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '');
            avisoSiguiente('success', 'Mes de ' . nombreMes($mes) . ' reabierto.'
                . ($anulados ? ' Se anularon los comprobantes de cierre ' . implode(', ', $anulados) . '; se generan de nuevo al volver a cerrar diciembre.' : ''));
        }
    } catch (InvalidArgumentException $e) {
        avisoSiguiente('danger', $e->getMessage());
    } catch (Exception $e) {
        error_log('cierre_contable.php: ' . $e->getMessage());
        avisoSiguiente('danger', 'No se pudo completar la acción. Intenta de nuevo.');
    }
    header('Location: cierre_contable.php');
    exit;
}

$meses = mesesContables($db);
$bloqueos = [];
foreach ($meses as $clave => $m) {
    $bloqueos[$clave] = $m['cerrado'] ? null : errorCierreMes($db, $clave, $meses);
}
$ultimo = $db->query("SELECT anio, mes FROM periodos_contables WHERE estado = 'Cerrado' ORDER BY anio DESC, mes DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ultimo_mes = $ultimo ? sprintf('%04d-%02d', $ultimo['anio'], $ultimo['mes']) : null;
$mes_actual = date('Y-m');
$abiertos_terminados = count(array_filter($meses, function ($m) use ($mes_actual) { return !$m['cerrado'] && $m['mes'] < $mes_actual; }));
$por_aprobar = array_sum(array_map(function ($m) use ($mes_actual) { return !$m['cerrado'] && $m['mes'] < $mes_actual ? $m['pendientes'] : 0; }, $meses));
$cierres = $db->query("SELECT id, numero, fecha, valor_total, estado, clase_nota FROM comprobantes WHERE tipo = 'CC' ORDER BY fecha DESC, id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$es_admin = $usuario['rol'] === 'Admin';

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Cierre Contable";
$subtitulo = "Cierre mensual en orden y cierre anual del ejercicio";
$aviso = tomarAviso();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre Contable | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/contabilidad.css">
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="container-fluid py-4">
            <?php if ($aviso): ?>
                <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert" id="avisoCierre">
                    <i class="fas <?= $aviso['tipo'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i> <?= esc($aviso['texto']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-success shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Último mes cerrado</small>
                        <h4 class="fw-bold mb-0 text-success" id="ultimoMesCerrado"><?= $ultimo_mes ? esc(ucfirst(nombreMes($ultimo_mes))) : 'Ninguno' ?></h4>
                        <small class="text-muted">Nada con fecha de un mes cerrado se puede registrar ni modificar</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-warning shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Meses terminados sin cerrar</small>
                        <h4 class="fw-bold mb-0 text-warning" id="mesesAbiertos"><?= (int) $abiertos_terminados ?></h4>
                        <small class="text-muted">Se cierran en orden, del más antiguo al más reciente</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-kpi p-4 border-0 border-bottom border-4 border-danger shadow-sm h-100">
                        <small class="text-muted fw-bold d-block mb-1 text-uppercase">Documentos por aprobar</small>
                        <h4 class="fw-bold mb-0 text-danger" id="porAprobarCierre"><?= (int) $por_aprobar ?></h4>
                        <small class="text-muted">En meses terminados: impiden el cierre</small>
                    </div>
                </div>
            </div>

            <div class="panel-conta overflow-hidden mb-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 tabla-conta" id="tablaMeses">
                        <thead>
                            <tr><th class="ps-4">Mes</th><th class="text-center">Documentos</th><th class="text-center">Por aprobar</th><th class="text-center">Conciliaciones abiertas</th><th>Estado</th><th class="text-end pe-4">Acción</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($meses, true) as $clave => $m): $registro = $m['registro']; ?>
                            <tr data-mes="<?= esc($clave) ?>" data-estado="<?= $m['cerrado'] ? 'Cerrado' : 'Abierto' ?>" class="<?= $m['cerrado'] ? 'mes-cerrado' : '' ?>">
                                <td class="ps-4 fw-bold">
                                    <?= esc(ucfirst(nombreMes($clave))) ?>
                                    <?php if (substr($clave, 5, 2) === '12'): ?><span class="badge bg-light text-dark border ms-1 text-lowercase">con cierre anual</span><?php endif; ?>
                                </td>
                                <td class="text-center"><?= (int) $m['documentos'] ?></td>
                                <td class="text-center"><?= $m['pendientes'] ? '<span class="badge bg-warning text-dark">' . (int) $m['pendientes'] . '</span>' : '0' ?></td>
                                <td class="text-center"><?= $m['conciliaciones_abiertas'] ? '<span class="badge bg-info text-dark">' . (int) $m['conciliaciones_abiertas'] . '</span>' : '0' ?></td>
                                <td class="small">
                                    <?php if ($m['cerrado']): ?>
                                        <span class="estado-doc estado-Aprobado"><i class="fas fa-lock"></i> Cerrado</span>
                                        <div class="text-muted mt-1">Por <?= esc($registro['nombre_cierre'] ?? '—') ?> el <?= date('d/m/Y H:i', strtotime($registro['fecha_cierre'])) ?></div>
                                    <?php elseif ($clave === $mes_actual): ?>
                                        <span class="estado-doc estado-Revisado">En curso</span>
                                    <?php else: ?>
                                        <span class="estado-doc estado-Pendiente">Abierto</span>
                                        <?php if ($registro && $registro['fecha_reapertura']): ?>
                                            <div class="text-muted mt-1">Reabierto por <?= esc($registro['nombre_reapertura'] ?? '—') ?>: <i><?= esc($registro['motivo_reapertura']) ?></i></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if (!$m['cerrado'] && $clave < $mes_actual): ?>
                                        <?php if ($bloqueos[$clave] === null): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm(<?= jsAttr('¿Cerrar ' . nombreMes($clave) . '? No se podrán registrar ni modificar documentos con esa fecha.') ?>);">
                                                <input type="hidden" name="accion" value="cerrar">
                                                <input type="hidden" name="mes" value="<?= esc($clave) ?>">
                                                <button type="submit" class="btn btn-sm btn-success rounded-pill fw-bold px-3 btn-cerrar-mes"><i class="fas fa-lock me-1"></i> Cerrar mes</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="small text-muted motivo-bloqueo"><?= esc($bloqueos[$clave]) ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($m['cerrado'] && $es_admin && $clave === $ultimo_mes): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill fw-bold px-3 btn-reabrir-mes" data-bs-toggle="modal" data-bs-target="#modalReabrir"
                                                onclick="prepararReapertura(<?= jsAttr($clave) ?>, <?= jsAttr(nombreMes($clave)) ?>)">
                                            <i class="fas fa-lock-open me-1"></i> Reabrir
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="panel-conta p-4 h-100">
                        <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-info-circle me-2"></i> Cómo funciona</div>
                        <ul class="small text-muted mb-0">
                            <li>Un mes se cierra cuando terminó, los meses anteriores ya están cerrados y no tiene documentos pendientes o revisados.</li>
                            <li>Con el mes cerrado no se registran, aprueban ni anulan facturas, comprobantes o notas con esa fecha.</li>
                            <li>Al cerrar <b>diciembre</b> se genera el comprobante de cierre: ingresos, costos y gastos del año contra la cuenta 360505 (utilidad del ejercicio), y el 1 de enero se traslada a 370505 (utilidades acumuladas). El Estado de Resultados sigue mostrando el año sin el asiento de cierre.</li>
                            <li>Solo un Administrador reabre, con motivo y empezando por el último mes cerrado. Reabrir diciembre anula su cierre anual.</li>
                            <li>Conviene cerrar primero las conciliaciones bancarias del mes.</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="panel-conta p-4 h-100">
                        <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-file-contract me-2"></i> Comprobantes de cierre</div>
                        <?php if (!$cierres): ?>
                            <p class="small text-muted mb-0">Todavía no se ha cerrado ningún año.</p>
                        <?php else: ?>
                            <table class="table table-sm align-middle mb-0" id="tablaCierres">
                                <?php foreach ($cierres as $cc): ?>
                                    <tr>
                                        <td><a class="num-doc CC" href="comprobante_cierre.php?id=<?= (int) $cc['id'] ?>"><?= esc($cc['numero']) ?></a></td>
                                        <td class="small"><?= date('d/m/Y', strtotime($cc['fecha'])) ?><div class="text-muted"><?= esc($cc['clase_nota']) ?></div></td>
                                        <td class="money small"><?= dinero($cc['valor_total'], 2) ?></td>
                                        <td><span class="estado-doc estado-<?= esc($cc['estado']) ?>"><?= esc($cc['estado']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalReabrir" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="accion" value="reabrir">
                <input type="hidden" name="mes" id="reabrirMes">
                <div class="modal-header border-0 bg-danger bg-opacity-10">
                    <h5 class="fw-bold text-danger mb-0"><i class="fas fa-lock-open me-2"></i> Reabrir <span id="reabrirNombre"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted">Se podrán volver a registrar y modificar documentos con fecha de ese mes. Si es diciembre, se anula el cierre anual.</p>
                    <label class="small fw-bold text-muted mb-2">Motivo de la reapertura *</label>
                    <textarea name="motivo" class="form-control" rows="3" minlength="10" maxlength="255" required placeholder="Ej: Llegó la factura del proveedor de agosto después del cierre."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill fw-bold px-4">Reabrir mes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function prepararReapertura(mes, nombre) {
    document.getElementById('reabrirMes').value = mes;
    document.getElementById('reabrirNombre').textContent = nombre;
}
</script>
</body>
</html>
