<?php
require_once __DIR__ . '/../includes/seguridad.php';
$p = 'salas_reuniones.php';

// 1. SEGURIDAD Y CONEXIÓN
$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'reuniones');

// Encabezado común del panel (título, hamburguesa del menú y perfil), igual que en los demás módulos
$stmt_perfil = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_perfil->execute([$usuario_actual['id']]);
$perfil = $stmt_perfil->fetch(PDO::FETCH_ASSOC);
$titulo = 'Panel de Control de Sesiones';
$subtitulo = 'Gestión de reuniones, actas y productividad';

// Cliente de cada reunión (migración 8): así la reunión aparece en la Ficha del cliente
$reunion_con_cliente = (bool) $db->query("SHOW COLUMNS FROM reuniones_virtuales LIKE 'cliente_id'")->fetch();
$ver_ficha = tieneAcceso($usuario_actual['rol'], 'clientes', $db);
// Solo se asocian cuentas con rol Cliente; cualquier otro valor deja la reunión como interna
$cliente_valido = function ($id) use ($db) {
    $id = (int) $id;
    if ($id <= 0) return 0;
    $stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND rol = 'Cliente'");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ? $id : 0;
};

// 2. LÓGICA DE PROCESAMIENTO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    
    // Fecha válida (AAAA-MM-DD), horas HH:MM y hora de fin posterior a la de inicio
    $horario_valido = function ($fecha, $inicio, $fin) {
        $dia = DateTime::createFromFormat('Y-m-d', (string) $fecha);
        return $dia && $dia->format('Y-m-d') === $fecha
            && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $inicio) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $fin)
            && strtotime("$fecha $fin") > strtotime("$fecha $inicio");
    };

    // ACCIÓN: CREAR REUNIÓN
    if ($_POST['accion'] === 'crear') {
        $tit = trim($_POST['titulo'] ?? ''); $fec = $_POST['fecha'] ?? ''; $ini = $_POST['hora_inicio'] ?? ''; $fin = $_POST['hora_fin'] ?? ''; $obj = trim($_POST['objetivo'] ?? '');
        if ($tit === '' || !$horario_valido($fec, $ini, $fin)) {
            header("Location: salas_reuniones.php?res=horario_invalido"); exit;
        }
        $cod = "DataSense_" . bin2hex(random_bytes(6));
        $lnk = "https://meet.jit.si/" . $cod;
        $cliente_id = $reunion_con_cliente ? $cliente_valido($_POST['cliente_id'] ?? 0) : 0;
        if ($reunion_con_cliente) {
            $sql = "INSERT INTO reuniones_virtuales (titulo, codigo_sala, link_reunion, fecha, hora_inicio, hora_fin, objetivo, cliente_id, estado) VALUES (?,?,?,?,?,?,?,?, 'Programada')";
            $db->prepare($sql)->execute([$tit, $cod, $lnk, $fec, $ini, $fin, $obj, $cliente_id ?: null]);
        } else {
            $sql = "INSERT INTO reuniones_virtuales (titulo, codigo_sala, link_reunion, fecha, hora_inicio, hora_fin, objetivo, estado) VALUES (?,?,?,?,?,?,?, 'Programada')";
            $db->prepare($sql)->execute([$tit, $cod, $lnk, $fec, $ini, $fin, $obj]);
        }
        // Agendada desde la Ficha del cliente: vuelve a la ficha
        if ($cliente_id && ($_POST['volver'] ?? '') === 'ficha' && $ver_ficha) {
            header("Location: cliente_ficha.php?id=$cliente_id&res=reunion#reuniones");
            exit;
        }
        header("Location: salas_reuniones.php?res=agendada");
        exit;
    }

    // ACCIÓN: REPROGRAMAR REUNIÓN (las actas ya finalizadas no se mueven)
    elseif ($_POST['accion'] === 'reprogramar') {
        $id_r = (int) ($_POST['id_reunion'] ?? 0);
        $fec = $_POST['fecha'] ?? '';
        $ini = $_POST['hora_inicio'] ?? '';
        $fin = $_POST['hora_fin'] ?? '';
        if (!$horario_valido($fec, $ini, $fin)) {
            header("Location: salas_reuniones.php?res=horario_invalido"); exit;
        }
        if ($reunion_con_cliente) {
            $sql = "UPDATE reuniones_virtuales SET fecha = ?, hora_inicio = ?, hora_fin = ?, cliente_id = ? WHERE id = ? AND estado <> 'Finalizada'";
            $db->prepare($sql)->execute([$fec, $ini, $fin, $cliente_valido($_POST['cliente_id'] ?? 0) ?: null, $id_r]);
        } else {
            $sql = "UPDATE reuniones_virtuales SET fecha = ?, hora_inicio = ?, hora_fin = ? WHERE id = ? AND estado <> 'Finalizada'";
            $db->prepare($sql)->execute([$fec, $ini, $fin, $id_r]);
        }
        header("Location: salas_reuniones.php?res=reprogramada");
        exit;
    }

    // ACCIÓN: GUARDAR ACTA Y FIRMA
    elseif ($_POST['accion'] === 'guardar_acta') {
        $id_r = $_POST['id_reunion'];
        $acta = $_POST['acta_texto'];
        $firma_data = $_POST['firma_b64'];
        
        // Si la base tiene la columna firmado_por (bases anteriores a la versión actual pueden no tenerla), se guarda quién firma
        $guarda_firmante = (bool) $db->query("SHOW COLUMNS FROM reuniones_virtuales LIKE 'firmado_por'")->fetch();
        if ($guarda_firmante) {
            $sql = "UPDATE reuniones_virtuales SET evidencia = ?, firma_digital = ?, firmado_por = ?, estado = 'Finalizada' WHERE id = ?";
            $db->prepare($sql)->execute([$acta, $firma_data, $_SESSION['admin_id'], $id_r]);
        } else {
            $sql = "UPDATE reuniones_virtuales SET evidencia = ?, firma_digital = ?, estado = 'Finalizada' WHERE id = ?";
            $db->prepare($sql)->execute([$acta, $firma_data, $id_r]);
        }
        
        header("Location: salas_reuniones.php?pdf_open=" . $id_r);
        exit;
    }
}

// 3. CONSULTAS PARA INDICADORES (TU LÓGICA OPERATIVA)

// Citas para hoy: Cuenta regresiva (las que son hoy y NO has cerrado)
$total_hoy = $db->query("SELECT COUNT(*) FROM reuniones_virtuales WHERE fecha = CURRENT_DATE() AND estado != 'Finalizada'")->fetchColumn();

// Horas Producidas: Solo de las actas ya legalizadas y cerradas
$horas_prod = $db->query("SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(hora_fin, hora_inicio))) / 3600, 0) FROM reuniones_virtuales WHERE estado = 'Finalizada'")->fetchColumn();

// Actas por Cerrar (ALERTA): Reuniones que YA PASARON su fecha/hora y siguen sin acta firmada.
$pendientes_firma = $db->query("SELECT COUNT(*) FROM reuniones_virtuales 
                                WHERE estado != 'Finalizada' 
                                AND (fecha < CURRENT_DATE() OR (fecha = CURRENT_DATE() AND hora_fin <= CURRENT_TIME()))")->fetchColumn();

// 4. LISTADOS PARA LA VISTA
$desde_reuniones = $reunion_con_cliente
    ? "SELECT r.*, COALESCE(NULLIF(u.organizacion, ''), u.nombre) AS cliente_nombre FROM reuniones_virtuales r LEFT JOIN usuarios u ON u.id = r.cliente_id"
    : "SELECT r.*, NULL AS cliente_id, NULL AS cliente_nombre FROM reuniones_virtuales r";
$proximas = $db->query("$desde_reuniones WHERE r.estado != 'Finalizada' ORDER BY r.fecha ASC, r.hora_inicio ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$historial = $db->query("$desde_reuniones WHERE r.estado = 'Finalizada' ORDER BY r.fecha DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
// Todos los registros, sin límite: incluye las que ya pasaron sin acta y las que quedaron sin estado
$todas_reuniones = $db->query("$desde_reuniones ORDER BY r.fecha DESC, r.hora_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
$ahora_reuniones = date('Y-m-d H:i:s');

// Clientes que se pueden asociar a una reunión (las cuentas inactivas se marcan para no perder la asociación al reprogramar)
$clientes_reunion = $reunion_con_cliente
    ? $db->query("SELECT id, nombre, organizacion, estado_cuenta FROM usuarios WHERE rol = 'Cliente' ORDER BY COALESCE(NULLIF(organizacion, ''), nombre), nombre")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$opciones_clientes = function () use ($clientes_reunion) {
    $html = '<option value="">Sin cliente (reunión interna)</option>';
    foreach ($clientes_reunion as $c) {
        $nombre = trim((string) $c['organizacion']) !== '' ? $c['organizacion'] . ' · ' . $c['nombre'] : $c['nombre'];
        $inactivo = in_array($c['estado_cuenta'] ?? 'Activo', ['Suspendido', 'Retirado', 'Inactivo'], true) ? ' (cuenta ' . mb_strtolower($c['estado_cuenta'], 'UTF-8') . ')' : '';
        $html .= '<option value="' . (int) $c['id'] . '">' . esc($nombre . $inactivo) . '</option>';
    }
    return $html;
};
// Nombre del cliente con enlace a su ficha (si quien consulta puede ver Clientes)
$cliente_reunion = function (array $r) use ($ver_ficha) {
    if (empty($r['cliente_nombre'])) return '';
    $nombre = esc($r['cliente_nombre']);
    return '<div class="small cliente-reunion"><i class="fas fa-building me-1 text-muted"></i>'
        . ($ver_ficha ? '<a href="cliente_ficha.php?id=' . (int) $r['cliente_id'] . '" class="text-decoration-none fw-semibold">' . $nombre . '</a>' : $nombre) . '</div>';
};
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Salas de Reuniones | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .fc a { text-decoration: none; } /* Bootstrap subraya los números de día del calendario */
        .fc .fc-toolbar { flex-wrap: wrap; gap: .5rem; }
        .fc .fc-toolbar-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; }
        .fc .fc-toolbar-title::first-letter { text-transform: uppercase; } /* "Septiembre de 2026", no "Septiembre De 2026" */
        .fc .fc-button { border-radius: 10px; }
        .fc .fc-button-primary { background: #0A2E4F; border-color: #0A2E4F; }
        .fc .fc-button-primary:not(:disabled).fc-button-active, .fc .fc-button-primary:not(:disabled):active { background: #3FAE49; border-color: #3FAE49; }
        @media (max-width: 576px) {
            .calendario-card { padding: .75rem !important; }
            .fc .fc-toolbar.fc-header-toolbar { margin-bottom: .75rem; }
            /* Título en su propia fila arriba; debajo, flechas a la izquierda y "Hoy" a la derecha (igual en cualquier ancho) */
            .fc .fc-header-toolbar .fc-toolbar-chunk:nth-child(2) { order: -1; flex-basis: 100%; text-align: center; }
            .fc .fc-toolbar-title { font-size: 1rem; }
            .fc .fc-button { padding: .3rem .6rem; font-size: .8rem; }
            .fc .fc-daygrid-day-number { font-size: .75rem; padding: 2px 4px; }
            .fc .fc-col-header-cell-cushion { font-size: .7rem; padding: 4px 0; }
            .fc .fc-daygrid-event { font-size: .65rem; }
        }
    </style>
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <main class="main-content">
        
        <?php include '../includes/header.php'; ?>

        <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-4">
            <button class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow" data-bs-toggle="modal" data-bs-target="#modalAdd">
                <i class="fas fa-plus-circle me-2"></i> AGENDAR REUNIÓN
            </button>
        </div>

        <?php if(isset($_GET['res']) && $_GET['res'] == 'reprogramada'): ?>
        <div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-calendar-check me-2"></i> <strong>¡Actualizado!</strong> La reunión fue reprogramada con éxito.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php elseif(($_GET['res'] ?? '') === 'agendada'): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-calendar-plus me-2"></i> <strong>¡Listo!</strong> La reunión quedó agendada y su sala virtual ya está disponible.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php elseif(($_GET['res'] ?? '') === 'horario_invalido'): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-clock me-2"></i> <strong>Horario no válido.</strong> Indica el título, una fecha y una hora de fin posterior a la hora de inicio.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card card-kpi-pro p-4 border-bottom-primary" style="border-bottom-color: #0d6efd;">
                    <div class="d-flex align-items-center">
                        <div class="icon-shape bg-primary bg-opacity-10 text-primary me-3"><i class="fas fa-calendar-day"></i></div>
                        <div><span class="d-block text-muted small fw-bold">CITAS RESTANTES HOY</span><h3 class="mb-0 fw-bold"><?= $total_hoy ?></h3></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-kpi-pro p-4" style="border-bottom-color: #198754;">
                    <div class="d-flex align-items-center">
                        <div class="icon-shape bg-success bg-opacity-10 text-success me-3"><i class="fas fa-history"></i></div>
                        <div><span class="d-block text-muted small fw-bold">HORAS PRODUCIDAS</span><h3 class="mb-0 fw-bold"><?= number_format($horas_prod, 1) ?>h</h3></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-kpi-pro p-4" style="border-bottom-color: <?= ($pendientes_firma > 0) ? '#dc3545' : '#6c757d' ?>;">
                    <div class="d-flex align-items-center">
                        <div class="icon-shape <?= ($pendientes_firma > 0) ? 'bg-danger text-danger' : 'bg-secondary text-secondary' ?> bg-opacity-10 me-3"><i class="fas fa-file-signature"></i></div>
                        <div><span class="d-block text-muted small fw-bold">ACTAS POR CERRAR (ATRASADAS)</span><h3 class="mb-0 fw-bold <?= ($pendientes_firma > 0) ? 'text-danger' : '' ?>"><?= $pendientes_firma ?></h3></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card card-ds border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4 calendario-card"><div id="calendar_native"></div></div>
                </div>

                <h5 class="fw-bold text-dark mb-3"><i class="fas fa-archive text-success me-2"></i> Historial de Actas Legalizadas</h5>
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted small fw-bold">
                                <tr>
                                    <th class="ps-4">FECHA</th>
                                    <th>PROYECTO / ASUNTO</th>
                                    <th class="text-end pe-4">DOCUMENTO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($historial as $h): ?>
                                <tr>
                                    <td class="ps-4 small text-muted fw-bold"><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                                    <td><div class="fw-bold text-dark"><?= htmlspecialchars($h['titulo']) ?></div><?= $cliente_reunion($h) ?></td>
                                    <td class="text-end pe-4">
                                        <a href="generar_acta.php?id=<?= $h['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold shadow-sm"><i class="fas fa-file-pdf me-1"></i> VER PDF</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($historial)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">No hay actas legalizadas aún.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <h5 class="fw-bold text-dark mb-3"><i class="fas fa-tasks text-warning me-2"></i> Próximas Sesiones</h5>
                
                <?php if(empty($proximas)): ?>
                    <div class="alert alert-success border-0 shadow-sm text-center p-4 rounded-4">
                        <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-75"></i>
                        <h6 class="fw-bold">¡Todo al día!</h6><small>No tienes sesiones programadas.</small>
                    </div>
                <?php endif; ?>

                <div class="sessions-container" style="max-height: 800px; overflow-y: auto;">
                    <?php foreach($proximas as $r): ?>
                    <div class="card border-0 shadow-sm rounded-4 mb-3 border-start border-5" style="border-left-color: var(--azul-sonda) !important;">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($r['titulo']) ?></h6>
                            </div>
                            <?= $cliente_reunion($r) ?>
                            <p class="text-muted small mb-2">
                                <i class="far fa-calendar-alt me-1"></i> <?= date('d/m/Y', strtotime($r['fecha'])) ?> | 
                                <i class="far fa-clock me-1 ms-1"></i> <?= substr($r['hora_inicio'],0,5) ?> - <?= substr($r['hora_fin'],0,5) ?>
                            </p>
                            
                            <div class="row g-2 mt-2">
                                <div class="col-6">
                                    <a href="<?= esc($r['link_reunion']) ?>" target="_blank" class="btn btn-primary btn-sm rounded-pill w-100 fw-bold text-white shadow-sm">
                                        <i class="fas fa-video me-1"></i> ENTRAR
                                    </a>
                                </div>
                                <div class="col-6">
                                    <button class="btn btn-warning btn-sm rounded-pill w-100 fw-bold shadow-sm text-dark" onclick="abrirReprogramar(<?= jsAttr(['id' => $r['id'], 'titulo' => $r['titulo'], 'fecha' => $r['fecha'], 'hora_inicio' => $r['hora_inicio'], 'hora_fin' => $r['hora_fin'], 'cliente_id' => $r['cliente_id'] ?? null]) ?>)">
                                        <i class="fas fa-calendar-day me-1"></i> MOVER
                                    </button>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-success btn-sm rounded-pill w-100 fw-bold shadow-sm" onclick="abrirActa(<?= $r['id'] ?>)">
                                        <i class="fas fa-pen-nib me-1"></i> CERRAR ACTA Y FIRMAR
                                    </button>
                                </div>
                            </div>

                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-4" id="todasReuniones">
            <div class="card-body p-4 pb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list-ul text-primary me-2"></i> Todas las reuniones <span class="badge text-bg-light border"><?= count($todas_reuniones) ?></span></h5>
                <input type="search" id="buscarReunion" class="form-control form-control-sm rounded-pill" style="max-width: 280px;" placeholder="Buscar por asunto, cliente, estado o fecha..." aria-label="Buscar reuniones">
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tablaTodasReuniones" style="min-width: 760px;">
                    <thead class="bg-light text-muted small fw-bold">
                        <tr>
                            <th class="ps-4">FECHA Y HORA</th>
                            <th>ASUNTO Y OBJETIVO</th>
                            <th>CLIENTE</th>
                            <th>ESTADO</th>
                            <th class="text-end pe-4">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todas_reuniones as $r):
                            $estado_r = (string) ($r['estado'] ?? '');
                            $pasada = $r['fecha'] . ' ' . $r['hora_fin'] < $ahora_reuniones;
                            if ($estado_r === 'Finalizada') $etiqueta = ['text-bg-success', 'Acta firmada'];
                            elseif ($pasada) $etiqueta = ['text-bg-danger', 'Pasó sin acta' . ($estado_r === '' ? ' (sin estado)' : '')];
                            else $etiqueta = ['text-bg-primary', $estado_r !== '' ? $estado_r : 'Sin estado'];
                        ?>
                        <tr data-fila>
                            <td class="ps-4 small fw-semibold text-nowrap"><?= date('d/m/Y', strtotime($r['fecha'])) ?><div class="text-muted fw-normal"><?= substr((string) $r['hora_inicio'], 0, 5) ?> - <?= substr((string) $r['hora_fin'], 0, 5) ?></div></td>
                            <td><div class="fw-bold text-dark"><?= esc($r['titulo']) ?></div><?php if (trim((string) $r['objetivo']) !== ''): ?><div class="small text-muted"><?= esc($r['objetivo']) ?></div><?php endif; ?></td>
                            <td class="small"><?= $cliente_reunion($r) ?: '<span class="text-muted">Sin cliente</span>' ?></td>
                            <td><span class="badge <?= $etiqueta[0] ?>"><?= esc($etiqueta[1]) ?></span></td>
                            <td class="text-end pe-4 text-nowrap">
                                <?php if ($estado_r === 'Finalizada'): ?>
                                    <a href="generar_acta.php?id=<?= (int) $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold"><i class="fas fa-file-pdf me-1"></i> PDF</a>
                                <?php else: ?>
                                    <?php if (!$pasada): ?><a href="<?= esc($r['link_reunion']) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold"><i class="fas fa-video me-1"></i> Entrar</a><?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-warning rounded-pill px-3 fw-bold text-dark" onclick="abrirReprogramar(<?= jsAttr(['id' => $r['id'], 'titulo' => $r['titulo'], 'fecha' => $r['fecha'], 'hora_inicio' => $r['hora_inicio'], 'hora_fin' => $r['hora_fin'], 'cliente_id' => $r['cliente_id'] ?? null]) ?>)"><i class="fas fa-calendar-day me-1"></i> Mover</button>
                                    <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold" onclick="abrirActa(<?= (int) $r['id'] ?>)"><i class="fas fa-pen-nib me-1"></i> Acta</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($todas_reuniones)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">Todavía no hay reuniones registradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal fade modal-ds" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST">
            <input type="hidden" name="accion" value="crear">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="fw-bold mb-0 text-white"><i class="fas fa-calendar-plus me-2"></i> Nueva Sesión Virtual</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3"><label class="form-label-ds">Asunto / Proyecto</label><input type="text" name="titulo" class="form-control-ds" required></div>
                <?php if ($reunion_con_cliente): ?>
                <div class="mb-3">
                    <label class="form-label-ds" for="nuevo_cliente_reunion">Cliente</label>
                    <select name="cliente_id" id="nuevo_cliente_reunion" class="form-select form-control-ds"><?= $opciones_clientes() ?></select>
                    <input type="hidden" name="volver" id="nuevo_volver" value="">
                </div>
                <?php endif; ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label-ds">Fecha</label><input type="date" name="fecha" class="form-control-ds" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-md-3"><label class="form-label-ds">Inicio</label><input type="time" name="hora_inicio" class="form-control-ds" required></div>
                    <div class="col-md-3"><label class="form-label-ds">Fin</label><input type="time" name="hora_fin" class="form-control-ds" required></div>
                </div>
                <div class="mb-2"><label class="form-label-ds">Objetivo</label><textarea name="objetivo" class="form-control-ds" rows="2"></textarea></div>
            </div>
            <div class="modal-footer bg-white border-0 p-4"><button type="submit" class="btn btn-primary rounded-pill w-100 py-3 fw-bold shadow">CREAR SALA Y AGENDAR</button></div>
        </form>
    </div>
</div>

<div class="modal fade modal-ds" id="modalReprogramar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST">
            <input type="hidden" name="accion" value="reprogramar">
            <input type="hidden" name="id_reunion" id="rep_id">
            
            <div class="modal-header" style="background-color: #ffc107;">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-calendar-alt me-2"></i> Reprogramar Sesión</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <p class="text-muted small mb-3">Modificando fecha para: <strong id="rep_titulo" class="text-dark d-block mt-1"></strong></p>
                <?php if ($reunion_con_cliente): ?>
                <div class="mb-3">
                    <label class="form-label-ds" for="rep_cliente">Cliente</label>
                    <select name="cliente_id" id="rep_cliente" class="form-select form-control-ds"><?= $opciones_clientes() ?></select>
                </div>
                <?php endif; ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label-ds">Nueva Fecha</label>
                        <input type="date" name="fecha" id="rep_fecha" class="form-control-ds" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-ds">Nueva Hora Inicio</label>
                        <input type="time" name="hora_inicio" id="rep_inicio" class="form-control-ds" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-ds">Nueva Hora Fin</label>
                        <input type="time" name="hora_fin" id="rep_fin" class="form-control-ds" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-0 p-4">
                <button type="submit" class="btn btn-warning rounded-pill w-100 py-3 fw-bold shadow text-dark">
                    <i class="fas fa-save me-2"></i> GUARDAR CAMBIOS
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade modal-ds" id="modalActa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" method="POST" id="formActa">
            <input type="hidden" name="accion" value="guardar_acta">
            <input type="hidden" name="id_reunion" id="acta_id">
            <input type="hidden" name="firma_b64" id="firma_b64">

            <div class="modal-header bg-success text-white p-4">
                <h5 class="fw-bold mb-0 text-white"><i class="fas fa-file-signature me-2"></i> Cerrar Sesión y Firmar Evidencia</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label-ds mb-0">ACUERDOS ALCANZADOS (ACTA)</label>
                    <button type="button" class="btn btn-sm btn-danger rounded-pill fw-bold" id="btnDictar"><i class="fas fa-microphone me-1"></i> DICTAR</button>
                </div>
                <textarea name="acta_texto" id="acta_texto" class="form-control-ds mb-4" rows="5" placeholder="¿Qué se acordó en la sesión hoy?..." required></textarea>

                <div class="row g-4">
                    <div class="col-md-6 border-end">
                        <label class="form-label-ds text-primary">OPCIÓN 1: SUBIR IMAGEN DE FIRMA</label>
                        <div class="p-3 bg-white border rounded-3 text-center mt-2">
                            <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2 rounded-pill fw-bold" onclick="document.getElementById('firma_file').click()"><i class="fas fa-upload me-1"></i> Cargar JPG / PNG</button>
                            <input type="file" id="firma_file" style="display:none" accept="image/*">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-ds text-primary">OPCIÓN 2: FIRMAR DIGITALMENTE</label>
                        <div class="mt-2 text-center">
                            <canvas id="signature-pad" width="350" height="120" class="w-100 bg-white border rounded-3 shadow-sm" style="cursor: crosshair; touch-action: none;"></canvas>
                            <button type="button" class="btn btn-link btn-sm text-danger mt-1 text-decoration-none fw-bold" onclick="limpiarFirma()"><i class="fas fa-eraser me-1"></i> Borrar Trazo</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-0 p-4">
                <button type="submit" class="btn btn-success rounded-pill w-100 py-3 fw-bold shadow-lg"><i class="fas fa-check-double me-2"></i> FINALIZAR Y GENERAR ACTA PDF</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

<script>
    // --- LÓGICA DE REPROGRAMAR REUNIÓN ---
    function abrirReprogramar(reunion) {
        document.getElementById('rep_id').value = reunion.id;
        document.getElementById('rep_titulo').innerText = reunion.titulo;
        document.getElementById('rep_fecha').value = reunion.fecha;
        document.getElementById('rep_inicio').value = reunion.hora_inicio;
        document.getElementById('rep_fin').value = reunion.hora_fin;
        var repCliente = document.getElementById('rep_cliente');
        if (repCliente) repCliente.value = reunion.cliente_id ? String(reunion.cliente_id) : '';
        new bootstrap.Modal(document.getElementById('modalReprogramar')).show();
    }

    // --- LÓGICA DE LA FIRMA (CANVAS Y UPLOAD) ---
    const canvas = document.getElementById('signature-pad');
    const ctx = canvas.getContext('2d');
    const hiddenFirma = document.getElementById('firma_b64');
    let drawing = false;

    document.getElementById('firma_file').addEventListener('change', function(e) {
        if (!e.target.files[0]) return;
        const reader = new FileReader();
        reader.onload = function(event) {
            const img = new Image();
            img.onload = function() {
                limpiarFirma();
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                hiddenFirma.value = canvas.toDataURL('image/png');
            };
            img.src = event.target.result;
        };
        reader.readAsDataURL(e.target.files[0]);
    });

    canvas.addEventListener('mousedown', () => drawing = true);
    canvas.addEventListener('mouseup', () => { drawing = false; ctx.beginPath(); hiddenFirma.value = canvas.toDataURL('image/png'); });
    canvas.addEventListener('mousemove', (e) => {
        if (!drawing) return;
        ctx.lineWidth = 2.5; 
        ctx.lineCap = 'round'; 
        ctx.strokeStyle = '#0A2E4F'; 
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        ctx.lineTo((e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY);
        ctx.stroke();
        ctx.beginPath(); 
        ctx.moveTo((e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY);
    });

    function limpiarFirma() { ctx.clearRect(0, 0, canvas.width, canvas.height); hiddenFirma.value = ""; }

    function abrirActa(id) { 
        document.getElementById('acta_id').value = id; 
        limpiarFirma();
        document.getElementById('acta_texto').value = '';
        new bootstrap.Modal(document.getElementById('modalActa')).show(); 
    }

    // --- LÓGICA DE DICTADO POR VOZ ---
    const btnDictar = document.getElementById('btnDictar');
    if ('webkitSpeechRecognition' in window) {
        const rec = new webkitSpeechRecognition();
        rec.lang = 'es-CO'; rec.continuous = true;
        btnDictar.onclick = () => {
            if (btnDictar.classList.contains('active')) {
                rec.stop(); btnDictar.classList.remove('active'); btnDictar.innerHTML = '<i class="fas fa-microphone me-1"></i> DICTAR';
            } else {
                rec.start(); btnDictar.classList.add('active'); btnDictar.innerHTML = '<i class="fas fa-stop-circle me-1"></i> ESCUCHANDO...';
            }
        };
        rec.onresult = (e) => { 
            let t = ""; for (let i = e.resultIndex; i < e.results.length; i++) { t += e.results[i][0].transcript; }
            document.getElementById('acta_texto').value += " " + t;
        };
    } else { btnDictar.style.display = 'none'; }

    // --- CALENDARIO FULLCALENDAR ---
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar_native');
        if(calendarEl) {
            // En celular la barra del calendario se reparte en dos filas (navegación arriba, vistas abajo) para que nada se salga
            var estrecho = window.matchMedia('(max-width: 576px)');
            var barras = function () {
                return estrecho.matches
                    ? { header: { left: 'prev,next', center: 'title', right: 'today' }, footer: { center: 'dayGridMonth,timeGridWeek' }, alto: 'auto' }
                    : { header: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' }, footer: false, alto: 500 };
            };
            var inicial = barras();
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth', locale: 'es', events: 'obtener_eventos.php', eventColor: '#0A2E4F', height: inicial.alto,
                buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana' }, // El paquete global no trae los textos en español
                headerToolbar: inicial.header, footerToolbar: inicial.footer,
                windowResize: function () {
                    var b = barras();
                    calendar.setOption('headerToolbar', b.header);
                    calendar.setOption('footerToolbar', b.footer);
                    calendar.setOption('height', b.alto);
                }
            });
            calendar.render();
        }
    });

    <?php if(isset($_GET['pdf_open'])): ?> window.open('generar_acta.php?id=<?= (int) $_GET['pdf_open'] ?>', '_blank'); <?php endif; ?>

    // Buscador de "Todas las reuniones"
    (function () {
        var campo = document.getElementById('buscarReunion');
        if (!campo) return;
        campo.addEventListener('input', function () {
            var texto = campo.value.trim().toLowerCase();
            document.querySelectorAll('#tablaTodasReuniones tbody tr[data-fila]').forEach(function (fila) {
                fila.hidden = texto !== '' && fila.textContent.toLowerCase().indexOf(texto) === -1;
            });
        });
    })();

    // Desde la Ficha del cliente (?agendar=1&cliente=ID): abre "Nueva sesión" con el cliente elegido y vuelve a la ficha al guardar
    (function () {
        var params = new URLSearchParams(window.location.search);
        var selector = document.getElementById('nuevo_cliente_reunion');
        if (params.get('agendar') !== '1') return;
        var cliente = (params.get('cliente') || '').replace(/\D/g, '');
        if (selector && cliente && selector.querySelector('option[value="' + cliente + '"]')) {
            selector.value = cliente;
            document.getElementById('nuevo_volver').value = 'ficha';
        }
        new bootstrap.Modal(document.getElementById('modalAdd')).show();
    })();
</script>

</body>
</html>