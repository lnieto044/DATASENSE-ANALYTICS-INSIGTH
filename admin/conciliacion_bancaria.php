<?php
// Conciliación bancaria: el extracto del banco contra el libro de la cuenta (solo movimientos aprobados).
// Se importa el extracto (CSV) o se digita, se emparejan los movimientos (automático o manual) y la diferencia
// se explica con las partidas pendientes. Lo conciliado no se puede anular sin desconciliarlo primero.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/conciliacion.php';
require_once __DIR__ . '/../includes/reportes.php';
$db = require __DIR__ . '/../config/database.php';
$usuario = requiereAcceso($db, 'conciliacion_bancaria');

$cuentas = cuentasConciliables($db);
$texto_get = function ($campo) { return is_string($_GET[$campo] ?? null) ? trim($_GET[$campo]) : ''; };
$texto_post = function ($campo) { return is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : ''; };
$periodo_valido = function ($p) { return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p); };

$leer = $_SERVER['REQUEST_METHOD'] === 'POST' ? $texto_post : $texto_get;
reset($cuentas);
$cuenta = isset($cuentas[$leer('cuenta')]) ? $leer('cuenta') : (isset($cuentas[CTA_BANCOS]) ? CTA_BANCOS : (string) key($cuentas));
$periodo = $periodo_valido($leer('periodo')) ? $leer('periodo') : date('Y-m', strtotime('first day of last month'));
$inicio = $periodo . '-01';
$fin = finDePeriodo($periodo);

if ($texto_get('plantilla') === '1') {
    enviarCsv('plantilla_extracto_bancario.csv', [
        ['fecha', 'descripcion', 'referencia', 'valor'],
        [$periodo . '-05', 'Consignación de cliente', 'TRF-00001', 1500000],
        [$periodo . '-20', 'Pago a proveedor', 'TRF-00002', -350000],
        [$fin, 'Cuota de manejo', '', -15000],
    ]);
    exit;
}

$ir = function ($mensaje = null, $tipo = 'success') use ($cuenta, $periodo) {
    if ($mensaje !== null) avisoSiguiente($tipo, $mensaje);
    header('Location: conciliacion_bancaria.php?' . http_build_query(['cuenta' => $cuenta, 'periodo' => $periodo]));
    exit;
};
$stmt = $db->prepare("SELECT * FROM conciliaciones WHERE cuenta = ? AND periodo = ?");
$stmt->execute([$cuenta, $periodo]);
$conciliacion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $texto_post('accion');
    try {
        if ($accion === 'iniciar') {
            if ($conciliacion) $ir();
            if ($periodo >= date('Y-m')) $ir('Solo se concilian meses terminados: el extracto del mes en curso todavía no está completo.', 'danger');
            $anterior = $db->prepare("SELECT periodo FROM conciliaciones WHERE cuenta = ? AND periodo < ? AND estado = 'Abierta' ORDER BY periodo LIMIT 1");
            $anterior->execute([$cuenta, $periodo]);
            $periodo_abierto = $anterior->fetchColumn();
            if ($periodo_abierto) $ir("Primero cierra la conciliación de $periodo_abierto de esta cuenta.", 'danger');
            $posterior = $db->prepare("SELECT periodo FROM conciliaciones WHERE cuenta = ? AND periodo > ? ORDER BY periodo LIMIT 1");
            $posterior->execute([$cuenta, $periodo]);
            $periodo_posterior = $posterior->fetchColumn();
            if ($periodo_posterior) $ir("Ya existe la conciliación de $periodo_posterior: no se abre un mes anterior.", 'danger');
            $saldo = $texto_post('saldo_extracto') === '' ? null : numeroExtracto($texto_post('saldo_extracto'));
            $db->prepare("INSERT INTO conciliaciones (cuenta, periodo, saldo_extracto, creado_por) VALUES (?, ?, ?, ?)")->execute([$cuenta, $periodo, $saldo, $usuario['id']]);
            $ir('Conciliación iniciada. Importa el extracto del banco o agrega sus movimientos.');
        }
        if (!$conciliacion) $ir('Primero inicia la conciliación de ese mes.', 'danger');

        if ($accion === 'reabrir') {
            if ($usuario['rol'] !== 'Admin') $ir('Solo un Administrador puede reabrir una conciliación cerrada.', 'danger');
            if ($conciliacion['estado'] !== 'Cerrada') $ir();
            $posterior = $db->prepare("SELECT periodo FROM conciliaciones WHERE cuenta = ? AND periodo > ? ORDER BY periodo LIMIT 1");
            $posterior->execute([$cuenta, $periodo]);
            $periodo_posterior = $posterior->fetchColumn();
            if ($periodo_posterior) $ir("No se puede reabrir: ya existe la conciliación de $periodo_posterior.", 'danger');
            $db->prepare("UPDATE conciliaciones SET estado = 'Abierta', cerrado_por = NULL, fecha_cierre = NULL, saldo_libro = NULL, pendiente_libro = NULL, pendiente_extracto = NULL WHERE id = ?")
               ->execute([$conciliacion['id']]);
            $ir('Conciliación reabierta.');
        }
        if ($conciliacion['estado'] === 'Cerrada') $ir('La conciliación está cerrada: un Administrador debe reabrirla para modificarla.', 'danger');

        if ($accion === 'guardar_saldo') {
            $saldo = numeroExtracto($texto_post('saldo_extracto'));
            if ($saldo === null) $ir('Escribe el saldo final que muestra el extracto.', 'danger');
            $db->prepare("UPDATE conciliaciones SET saldo_extracto = ? WHERE id = ?")->execute([$saldo, $conciliacion['id']]);
            $ir('Saldo del extracto guardado.');
        }

        if ($accion === 'importar') {
            $archivo = $_FILES['extracto'] ?? null;
            if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $ir('Elige el archivo CSV del extracto.', 'danger');
            if ($archivo['size'] > 1024 * 1024) $ir('El archivo supera 1 MB.', 'danger');
            if (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) $ir('El extracto debe ser un archivo .csv separado por punto y coma o coma.', 'danger');
            list($movimientos, $errores_csv) = leerExtractoCsv($archivo['tmp_name']);
            $existe = $db->prepare("SELECT COUNT(*) FROM extracto_movimientos WHERE conciliacion_id = ? AND fecha = ? AND valor = ? AND descripcion = ? AND COALESCE(referencia, '') = ?");
            $insertar = $db->prepare("INSERT INTO extracto_movimientos (conciliacion_id, fecha, descripcion, referencia, valor) VALUES (?, ?, ?, ?, ?)");
            $nuevos = 0; $fuera = 0; $repetidos = 0;
            foreach ($movimientos as $m) {
                list($fecha, $descripcion, $referencia, $valor) = $m;
                if ($fecha < $inicio || $fecha > $fin) { $fuera++; continue; }
                $existe->execute([$conciliacion['id'], $fecha, $valor, $descripcion, $referencia]);
                if ($existe->fetchColumn()) { $repetidos++; continue; }
                $insertar->execute([$conciliacion['id'], $fecha, $descripcion, $referencia !== '' ? $referencia : null, $valor]);
                $nuevos++;
            }
            $mensaje = "Extracto importado: $nuevos movimiento(s) nuevo(s)" . ($repetidos ? ", $repetidos ya estaban cargados" : '') . ($fuera ? ", $fuera fuera de $periodo (no se cargaron)" : '') . '.';
            if ($errores_csv) $mensaje .= ' ' . implode(' ', array_slice($errores_csv, 0, 3)) . (count($errores_csv) > 3 ? ' (y ' . (count($errores_csv) - 3) . ' más)' : '');
            $ir($mensaje, $nuevos ? 'success' : 'warning');
        }

        if ($accion === 'agregar') {
            $fecha = fechaExtracto($texto_post('fecha'));
            $descripcion = $texto_post('descripcion');
            $referencia = $texto_post('referencia');
            $valor = numeroExtracto($texto_post('valor'));
            if (!$fecha || $fecha < $inicio || $fecha > $fin) $ir("La fecha del movimiento debe estar dentro de $periodo.", 'danger');
            if ($descripcion === '' || mb_strlen($descripcion) > 255 || mb_strlen($referencia) > 100) $ir('Escribe la descripción del movimiento (y una referencia corta, si la tiene).', 'danger');
            if ($valor === null || abs($valor) < 0.005) $ir('Escribe el valor: positivo si entró al banco, negativo si salió.', 'danger');
            $db->prepare("INSERT INTO extracto_movimientos (conciliacion_id, fecha, descripcion, referencia, valor) VALUES (?, ?, ?, ?, ?)")
               ->execute([$conciliacion['id'], $fecha, $descripcion, $referencia !== '' ? $referencia : null, $valor]);
            $ir('Movimiento del extracto agregado.');
        }

        if ($accion === 'eliminar') {
            $db->prepare("DELETE FROM extracto_movimientos WHERE id = ? AND conciliacion_id = ? AND detalle_id IS NULL")->execute([(int) $texto_post('extracto_id'), $conciliacion['id']]);
            $ir('Movimiento del extracto eliminado.');
        }

        if ($accion === 'auto') {
            $emparejados = autoConciliar($db, $conciliacion, $usuario['id']);
            $ir($emparejados ? "$emparejados movimiento(s) conciliado(s) automáticamente." : 'No hubo coincidencias por valor con la misma referencia o con fechas a 5 días o menos.', $emparejados ? 'success' : 'warning');
        }

        if ($accion === 'conciliar') {
            $datos = datosConciliacion($db, $conciliacion);
            $extracto_id = (int) $texto_post('extracto_id');
            $detalle_id = (int) $texto_post('detalle_id');
            $mov_extracto = null;
            foreach ($datos['extracto'] as $e) if ((int) $e['id'] === $extracto_id && !$e['detalle_id']) $mov_extracto = $e;
            $mov_libro = null;
            foreach ($datos['libro'] as $l) if ((int) $l['id'] === $detalle_id && !$l['extracto_id']) $mov_libro = $l;
            if (!$mov_extracto || !$mov_libro) $ir('Elige un movimiento del extracto y uno del libro que estén pendientes.', 'danger');
            if (abs((float) $mov_extracto['valor'] - (float) $mov_libro['valor']) > 0.005) {
                $ir('Solo se concilian movimientos por el mismo valor: ' . dinero($mov_extracto['valor'], 2) . ' en el extracto y ' . dinero($mov_libro['valor'], 2) . ' en el libro.', 'danger');
            }
            $db->prepare("UPDATE extracto_movimientos SET detalle_id = ?, conciliado_por = ? WHERE id = ? AND detalle_id IS NULL")->execute([$detalle_id, $usuario['id'], $extracto_id]);
            $ir('Movimiento conciliado con ' . $mov_libro['numero'] . '.');
        }

        if ($accion === 'desconciliar') {
            $db->prepare("UPDATE extracto_movimientos SET detalle_id = NULL, conciliado_por = NULL WHERE id = ? AND conciliacion_id = ?")->execute([(int) $texto_post('extracto_id'), $conciliacion['id']]);
            $ir('Movimiento desconciliado.');
        }

        if ($accion === 'cerrar') {
            $datos = datosConciliacion($db, $conciliacion);
            if ($conciliacion['saldo_extracto'] === null) $ir('Registra el saldo final del extracto antes de cerrar.', 'danger');
            if (abs($datos['diferencia']) >= 0.01) $ir('No se puede cerrar: hay una diferencia de ' . dinero($datos['diferencia'], 2) . ' sin explicar.', 'danger');
            $db->prepare("UPDATE conciliaciones SET estado = 'Cerrada', cerrado_por = ?, fecha_cierre = NOW(), saldo_libro = ?, pendiente_libro = ?, pendiente_extracto = ? WHERE id = ?")
               ->execute([$usuario['id'], $datos['saldo_libro'], round($datos['transito'] + $datos['por_cobrar'], 2), $datos['pendiente_extracto'], $conciliacion['id']]);
            $ir('Conciliación cerrada: el libro y el extracto cuadran.');
        }
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) $ir('Ese movimiento del libro ya está conciliado con otro del extracto.', 'danger');
        error_log('conciliacion_bancaria.php: ' . $e->getMessage());
        $ir('No se pudo completar la acción. Intenta de nuevo.', 'danger');
    }
    $ir();
}

$datos = $conciliacion ? datosConciliacion($db, $conciliacion) : null;
$cerrada = $conciliacion && $conciliacion['estado'] === 'Cerrada';
if ($cerrada) {
    // Una conciliación cerrada muestra el resumen que quedó guardado al cerrarla
    $datos['saldo_libro'] = (float) $conciliacion['saldo_libro'];
    $datos['calculado'] = round((float) $conciliacion['saldo_libro'] - (float) $conciliacion['pendiente_libro'] + (float) $conciliacion['pendiente_extracto'], 2);
    $datos['diferencia'] = round((float) $conciliacion['saldo_extracto'] - $datos['calculado'], 2);
}
$pendientes_por_valor = [];
if ($datos) {
    foreach ($datos['libro'] as $l) {
        if (!$l['extracto_id']) $pendientes_por_valor[number_format((float) $l['valor'], 2, '.', '')][] = $l;
    }
}
$historial = $db->prepare("SELECT k.*, u.nombre AS nombre_cierre FROM conciliaciones k LEFT JOIN usuarios u ON u.id = k.cerrado_por WHERE k.cuenta = ? ORDER BY k.periodo DESC LIMIT 24");
$historial->execute([$cuenta]);
$historial = $historial->fetchAll(PDO::FETCH_ASSOC);
$puede_nota = tieneAcceso($usuario['rol'], 'nota_contable', $db);

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);
$titulo = "Conciliación Bancaria";
$subtitulo = "Extracto del banco contra el libro de bancos, con partidas pendientes";
$aviso = tomarAviso();
$campo_oculto = '<input type="hidden" name="cuenta" value="' . esc($cuenta) . '"><input type="hidden" name="periodo" value="' . esc($periodo) . '">';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación Bancaria | DataSense</title>
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
                <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert" id="avisoConciliacion">
                    <i class="fas <?= $aviso['tipo'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i> <?= esc($aviso['texto']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="panel-conta p-4 mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="small fw-bold text-muted mb-1">Cuenta</label>
                        <select name="cuenta" id="cuenta" class="form-select">
                            <?php foreach ($cuentas as $codigo => $nombre): ?>
                                <option value="<?= esc($codigo) ?>" <?= (string) $codigo === $cuenta ? 'selected' : '' ?>><?= esc($codigo) ?> · <?= esc($nombre) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted mb-1">Mes del extracto</label>
                        <input type="month" name="periodo" id="periodo" class="form-control" max="<?= date('Y-m') ?>" value="<?= esc($periodo) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold" style="background: var(--azul-sonda);"><i class="fas fa-search me-1"></i> Ver</button>
                    </div>
                    <div class="col-md-2 text-end">
                        <a href="?<?= esc(http_build_query(['cuenta' => $cuenta, 'periodo' => $periodo, 'plantilla' => 1])) ?>" class="small"><i class="fas fa-file-csv me-1"></i> Plantilla CSV</a>
                    </div>
                </form>
            </div>

<?php if (!$conciliacion): ?>
            <div class="panel-conta p-5 text-center" id="sinConciliacion">
                <i class="fas fa-university fa-3x text-muted opacity-50 mb-3"></i>
                <h5 class="fw-bold">No hay conciliación de <?= esc($cuenta) ?> · <?= esc($cuentas[$cuenta] ?? '') ?> para <?= esc($periodo) ?></h5>
                <p class="text-muted small mb-4">Inicia la conciliación con el saldo final del extracto; después importa el CSV del banco o agrega los movimientos a mano.</p>
                <form method="POST" class="d-inline-flex flex-wrap gap-2 justify-content-center">
                    <?= $campo_oculto ?>
                    <input type="hidden" name="accion" value="iniciar">
                    <input type="text" name="saldo_extracto" class="form-control rounded-pill text-end" style="max-width: 240px;" placeholder="Saldo final del extracto">
                    <button type="submit" class="btn btn-success rounded-pill fw-bold px-4" id="btnIniciarConciliacion"><i class="fas fa-play me-2"></i> Iniciar conciliación</button>
                </form>
            </div>
<?php else: ?>
            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="panel-conta p-4 h-100" id="resumenConciliacion">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="doc-subtitulo mb-0" style="color: var(--azul-sonda);">Resumen al <?= date('d/m/Y', strtotime($datos['fin'])) ?></div>
                            <span class="estado-doc <?= $cerrada ? 'estado-Aprobado' : 'estado-Pendiente' ?>" id="estadoConciliacion"><?= esc($conciliacion['estado']) ?></span>
                        </div>
                        <table class="table table-sm resumen-conciliacion mb-3">
                            <tr><td>Saldo según libro</td><td class="money" id="saldoLibro" data-valor="<?= $datos['saldo_libro'] ?>"><?= dinero($datos['saldo_libro'], 2) ?></td></tr>
                            <?php if (!$cerrada): ?>
                                <tr><td>(−) Entradas del libro que el banco aún no refleja</td><td class="money" id="transito" data-valor="<?= $datos['transito'] ?>"><?= dinero($datos['transito'], 2) ?></td></tr>
                                <tr><td>(+) Pagos del libro que el banco aún no descuenta</td><td class="money" id="pendientesCobro" data-valor="<?= $datos['por_cobrar'] ?>"><?= dinero(-$datos['por_cobrar'], 2) ?></td></tr>
                                <tr><td>(±) Movimientos del extracto sin registrar en libros</td><td class="money" id="extractoSinRegistrar" data-valor="<?= $datos['pendiente_extracto'] ?>"><?= dinero($datos['pendiente_extracto'], 2) ?></td></tr>
                            <?php else: ?>
                                <tr><td>(−/+) Partidas del libro pendientes al cerrar</td><td class="money"><?= dinero(-(float) $conciliacion['pendiente_libro'], 2) ?></td></tr>
                                <tr><td>(±) Movimientos del extracto sin registrar al cerrar</td><td class="money"><?= dinero($conciliacion['pendiente_extracto'], 2) ?></td></tr>
                            <?php endif; ?>
                            <tr class="total"><td>= Saldo que debe mostrar el extracto</td><td class="money" id="saldoCalculado" data-valor="<?= $datos['calculado'] ?>"><?= dinero($datos['calculado'], 2) ?></td></tr>
                            <tr><td>Saldo final del extracto</td><td class="money" id="saldoExtracto" data-valor="<?= $conciliacion['saldo_extracto'] === null ? '' : (float) $conciliacion['saldo_extracto'] ?>"><?= $conciliacion['saldo_extracto'] === null ? 'Sin registrar' : dinero($conciliacion['saldo_extracto'], 2) ?></td></tr>
                            <tr class="total">
                                <td>Diferencia</td>
                                <td class="money <?= $datos['diferencia'] !== null && abs($datos['diferencia']) >= 0.01 ? 'saldo-negativo' : 'text-success' ?>" id="diferenciaConciliacion" data-valor="<?= $datos['diferencia'] === null ? '' : $datos['diferencia'] ?>">
                                    <?= $datos['diferencia'] === null ? '—' : dinero($datos['diferencia'], 2) ?>
                                </td>
                            </tr>
                        </table>
                        <?php if ($datos['diferencia'] !== null && abs($datos['diferencia']) < 0.01): ?>
                            <div class="alert alert-success small py-2 mb-3" id="controlConciliacion"><i class="fas fa-check-circle me-1"></i> El libro y el extracto cuadran.</div>
                        <?php else: ?>
                            <div class="alert alert-warning small py-2 mb-3" id="controlConciliacion"><i class="fas fa-exclamation-triangle me-1"></i> Hay diferencia sin explicar o falta el saldo del extracto.</div>
                        <?php endif; ?>

                        <?php if (!$cerrada): ?>
                            <form method="POST" class="d-flex gap-2 mb-3">
                                <?= $campo_oculto ?><input type="hidden" name="accion" value="guardar_saldo">
                                <input type="text" name="saldo_extracto" id="inputSaldoExtracto" class="form-control form-control-sm text-end" placeholder="Saldo final del extracto" value="<?= $conciliacion['saldo_extracto'] === null ? '' : esc(number_format((float) $conciliacion['saldo_extracto'], 2, '.', '')) ?>" required>
                                <button type="submit" class="btn btn-sm btn-outline-dark rounded-pill px-3 text-nowrap" id="btnGuardarSaldo">Guardar saldo</button>
                            </form>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST"><?= $campo_oculto ?><input type="hidden" name="accion" value="auto">
                                    <button type="submit" class="btn btn-primary rounded-pill fw-bold px-3" id="btnAutoConciliar"><i class="fas fa-magic me-1"></i> Conciliar automáticamente</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('¿Cerrar la conciliación? Los movimientos conciliados ya no se podrán anular.');"><?= $campo_oculto ?><input type="hidden" name="accion" value="cerrar">
                                    <button type="submit" class="btn btn-success rounded-pill fw-bold px-3" id="btnCerrarConciliacion" <?= $datos['diferencia'] !== null && abs($datos['diferencia']) < 0.01 ? '' : 'disabled' ?>><i class="fas fa-lock me-1"></i> Cerrar conciliación</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <p class="small text-muted mb-2">Cerrada el <?= date('d/m/Y H:i', strtotime($conciliacion['fecha_cierre'])) ?>.</p>
                            <?php if ($usuario['rol'] === 'Admin'): ?>
                                <form method="POST" onsubmit="return confirm('¿Reabrir la conciliación?');"><?= $campo_oculto ?><input type="hidden" name="accion" value="reabrir">
                                    <button type="submit" class="btn btn-outline-danger rounded-pill fw-bold px-3" id="btnReabrirConciliacion"><i class="fas fa-lock-open me-1"></i> Reabrir</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-7">
                    <?php if (!$cerrada): ?>
                    <div class="panel-conta p-4 mb-4">
                        <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-file-import me-2"></i> Importar extracto (CSV)</div>
                        <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2" id="formImportar">
                            <?= $campo_oculto ?><input type="hidden" name="accion" value="importar">
                            <input type="file" name="extracto" id="archivoExtracto" class="form-control" accept=".csv,text/csv,text/plain" required style="max-width: 380px;">
                            <button type="submit" class="btn btn-dark rounded-pill fw-bold px-4" style="background: var(--azul-sonda);" id="btnImportar">Importar</button>
                        </form>
                        <p class="small text-muted mt-2 mb-0">Columnas: <b>fecha; descripcion; referencia; valor</b> (positivo si entró, negativo si salió), o <b>debito</b> y <b>credito</b> en lugar de valor. Solo se cargan los movimientos de <?= esc($periodo) ?>.</p>
                    </div>
                    <div class="panel-conta p-4">
                        <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-plus-circle me-2"></i> Agregar movimiento del extracto</div>
                        <form method="POST" class="row g-2" id="formAgregarMovimiento">
                            <?= $campo_oculto ?><input type="hidden" name="accion" value="agregar">
                            <div class="col-md-3"><input type="date" name="fecha" class="form-control" min="<?= $inicio ?>" max="<?= $fin ?>" required></div>
                            <div class="col-md-4"><input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="Descripción" required></div>
                            <div class="col-md-2"><input type="text" name="referencia" class="form-control" maxlength="100" placeholder="Referencia"></div>
                            <div class="col-md-2"><input type="text" name="valor" class="form-control text-end" placeholder="-15000" required></div>
                            <div class="col-md-1"><button type="submit" class="btn btn-outline-dark w-100" title="Agregar"><i class="fas fa-plus"></i></button></div>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="panel-conta p-4 h-100 d-flex align-items-center">
                        <p class="text-muted mb-0"><i class="fas fa-lock me-2"></i> Conciliación cerrada: los movimientos conciliados quedan protegidos y ya no se pueden anular en los comprobantes.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-xl-6">
                    <div class="panel-conta overflow-hidden">
                        <div class="p-3 fw-bold border-bottom"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> Extracto del banco (<?= count($datos['extracto']) ?>)</div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 tabla-conta" id="tablaExtracto">
                                <thead><tr><th class="ps-3">Fecha</th><th>Descripción</th><th class="text-end">Valor</th><th>Libro</th></tr></thead>
                                <tbody>
                                <?php if (!$datos['extracto']): ?>
                                    <tr><td colspan="4" class="text-center text-muted p-4">Todavía no hay movimientos del extracto.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($datos['extracto'] as $e): $candidatos = $pendientes_por_valor[number_format((float) $e['valor'], 2, '.', '')] ?? []; ?>
                                    <tr class="fila-extracto <?= $e['detalle_id'] ? 'fila-conciliada' : 'fila-pendiente' ?>" data-id="<?= (int) $e['id'] ?>" data-valor="<?= (float) $e['valor'] ?>" data-conciliado="<?= $e['detalle_id'] ? 1 : 0 ?>">
                                        <td class="ps-3 text-nowrap small"><?= date('d/m/Y', strtotime($e['fecha'])) ?></td>
                                        <td><div class="small fw-bold"><?= esc($e['descripcion']) ?></div><?php if ($e['referencia']): ?><div class="small text-muted"><?= esc($e['referencia']) ?></div><?php endif; ?></td>
                                        <td class="money <?= (float) $e['valor'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($e['valor'], 2) ?></td>
                                        <td class="small">
                                            <?php if ($e['detalle_id']): ?>
                                                <a class="num-doc <?= esc($e['tipo_libro']) ?>" href="<?= esc(urlDocumento($e['tipo_libro'], $e['comprobante_libro'])) ?>"><?= esc($e['numero_libro']) ?></a>
                                                <?php if (!$cerrada): ?>
                                                    <form method="POST" class="d-inline"><?= $campo_oculto ?><input type="hidden" name="accion" value="desconciliar"><input type="hidden" name="extracto_id" value="<?= (int) $e['id'] ?>">
                                                        <button type="submit" class="btn btn-link btn-sm text-danger p-0 ms-1 btn-desconciliar" title="Desconciliar"><i class="fas fa-unlink"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php elseif (!$cerrada): ?>
                                                <?php if ($candidatos): ?>
                                                    <form method="POST" class="d-flex gap-1 form-conciliar"><?= $campo_oculto ?><input type="hidden" name="accion" value="conciliar"><input type="hidden" name="extracto_id" value="<?= (int) $e['id'] ?>">
                                                        <select name="detalle_id" class="form-select form-select-sm" style="max-width: 190px;">
                                                            <?php foreach ($candidatos as $l): ?>
                                                                <option value="<?= (int) $l['id'] ?>"><?= esc($l['numero']) ?> · <?= date('d/m', strtotime($l['fecha'])) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-success" title="Conciliar"><i class="fas fa-link"></i></button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-warning fw-bold">Sin registrar</span>
                                                    <?php if ($puede_nota): ?>
                                                        <a href="nueva_nota_contable.php?extracto=<?= (int) $e['id'] ?>" class="ms-1 registrar-nota" title="Registrar con una nota contable"><i class="fas fa-file-signature"></i> Nota</a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este movimiento del extracto?');"><?= $campo_oculto ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="extracto_id" value="<?= (int) $e['id'] ?>">
                                                    <button type="submit" class="btn btn-link btn-sm text-muted p-0 ms-1" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">Sin registrar</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="panel-conta overflow-hidden">
                        <div class="p-3 fw-bold border-bottom"><i class="fas fa-book-open me-2 text-success"></i> Libro de <?= esc($cuenta) ?> hasta el <?= date('d/m/Y', strtotime($datos['fin'])) ?></div>
                        <div class="table-responsive" style="max-height: 620px;">
                            <table class="table align-middle mb-0 tabla-conta" id="tablaLibro">
                                <thead><tr><th class="ps-3">Fecha</th><th>Documento</th><th class="text-end">Valor</th><th>Estado</th></tr></thead>
                                <tbody>
                                <?php if (!$datos['libro']): ?>
                                    <tr><td colspan="4" class="text-center text-muted p-4">No hay movimientos aprobados en la cuenta.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($datos['libro'] as $l): ?>
                                    <tr class="fila-libro <?= $l['extracto_id'] ? 'fila-conciliada' : '' ?>" data-detalle="<?= (int) $l['id'] ?>" data-valor="<?= (float) $l['valor'] ?>" data-conciliado="<?= $l['extracto_id'] ? 1 : 0 ?>">
                                        <td class="ps-3 text-nowrap small"><?= date('d/m/Y', strtotime($l['fecha'])) ?></td>
                                        <td>
                                            <a class="num-doc <?= esc($l['tipo']) ?>" href="<?= esc(urlDocumento($l['tipo'], $l['comprobante_id'])) ?>"><?= esc($l['numero']) ?></a>
                                            <div class="small text-muted text-truncate" style="max-width: 230px;"><?= esc($l['tercero_nombre']) ?><?= $l['referencia'] ? ' · ' . esc($l['referencia']) : '' ?></div>
                                        </td>
                                        <td class="money <?= (float) $l['valor'] < 0 ? 'saldo-negativo' : '' ?>"><?= dinero($l['valor'], 2) ?></td>
                                        <td class="small"><?= $l['extracto_id'] ? '<span class="text-success fw-bold"><i class="fas fa-check"></i> Conciliado</span>' : '<span class="text-warning fw-bold">Pendiente</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
<?php endif; ?>

            <?php if ($historial): ?>
            <div class="panel-conta p-4">
                <div class="doc-subtitulo" style="color: var(--azul-sonda);"><i class="fas fa-history me-2"></i> Conciliaciones de <?= esc($cuenta) ?></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="historialConciliaciones">
                        <thead><tr><th>Mes</th><th class="text-end">Saldo del extracto</th><th>Estado</th><th>Cerrada por</th></tr></thead>
                        <tbody>
                        <?php foreach ($historial as $h): ?>
                            <tr>
                                <td><a href="?<?= esc(http_build_query(['cuenta' => $cuenta, 'periodo' => $h['periodo']])) ?>"><?= esc($h['periodo']) ?></a></td>
                                <td class="money"><?= $h['saldo_extracto'] === null ? '—' : dinero($h['saldo_extracto'], 2) ?></td>
                                <td><span class="estado-doc <?= $h['estado'] === 'Cerrada' ? 'estado-Aprobado' : 'estado-Pendiente' ?>"><?= esc($h['estado']) ?></span></td>
                                <td class="small"><?= $h['nombre_cierre'] ? esc($h['nombre_cierre']) . ' · ' . date('d/m/Y', strtotime($h['fecha_cierre'])) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
