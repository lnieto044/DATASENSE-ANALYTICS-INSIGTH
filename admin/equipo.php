<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD DE ACCESO
$usuario_actual = requiereAcceso($db, 'equipo');

// ================================================================
// LÓGICA DE PROCESAMIENTO (CRUD: CREAR, EDITAR, SUSPENDER, ACTIVAR)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $roles_validos = ['Comercial', 'Analista', 'Finanzas', 'Soporte', 'Supervisor', 'Admin'];
    $estados_validos = ['Activo', 'Vacaciones', 'Suspendido', 'Retirado'];
    $soy_admin = ($usuario_actual['rol'] === 'Admin');
    $es_mi_cuenta = isset($_POST['id']) && (int) $_POST['id'] === (int) $usuario_actual['id'];

    // Rol actual del colaborador afectado (editar / suspender / activar)
    $rol_objetivo = null;
    if (in_array($accion, ['editar', 'eliminar', 'activar'], true)) {
        $stmt_obj = $db->prepare("SELECT rol FROM usuarios WHERE id = ?");
        $stmt_obj->execute([$_POST['id'] ?? 0]);
        $rol_objetivo = $stmt_obj->fetchColumn();
    }

    $rol_pedido = $_POST['rol'] ?? null;
    $estado_pedido = $_POST['estado_cuenta'] ?? null;

    $accion_invalida =
        // Roles y estados solo de la lista permitida
        ($rol_pedido !== null && !in_array($rol_pedido, $roles_validos, true))
        || ($estado_pedido !== null && !in_array($estado_pedido, $estados_validos, true))
        // Este módulo solo gestiona colaboradores existentes, no clientes
        || ($rol_objetivo === false || $rol_objetivo === 'Cliente')
        // Solo un Admin crea, edita, suspende o nombra Admins
        || (!$soy_admin && ($rol_pedido === 'Admin' || $rol_objetivo === 'Admin'))
        // Nadie se suspende, se cambia el rol ni se bloquea a sí mismo
        || ($es_mi_cuenta && $accion === 'eliminar')
        || ($es_mi_cuenta && $accion === 'editar' && ($rol_pedido !== $usuario_actual['rol'] || in_array($estado_pedido, ESTADOS_BLOQUEADOS, true)));

    if ($accion_invalida) {
        header("Location: equipo.php?err=accion_no_permitida");
        exit;
    }

    // El correo identifica la cuenta al iniciar sesión: debe ser válido y no estar en uso por otro usuario
    if (in_array($accion, ['crear', 'editar'], true)) {
        $correo = trim($_POST['correo'] ?? '');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || trim($_POST['nombre'] ?? '') === '') {
            header("Location: equipo.php?err=datos_invalidos"); exit;
        }
        $duplicado = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ? AND id <> ?");
        $duplicado->execute([$correo, (int) ($_POST['id'] ?? 0)]);
        if ($duplicado->fetchColumn()) {
            header("Location: equipo.php?err=correo_duplicado"); exit;
        }
    }

    // CREAR NUEVO MIEMBRO DEL EQUIPO
    if ($accion === 'crear') {
        $clave_temporal = generarClaveTemporal(); // Se muestra una sola vez al administrador
        $pass_hash = password_hash($clave_temporal, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO usuarios (nombre, correo, telefono, cargo, rol, estado_cuenta, password, organizacion) VALUES (?, ?, ?, ?, ?, ?, ?, 'DataSense')");
        $stmt->execute([
            trim($_POST['nombre']),
            $correo,
            trim($_POST['telefono'] ?? ''),
            trim($_POST['cargo'] ?? ''),
            $_POST['rol'],
            $_POST['estado_cuenta'],
            $pass_hash
        ]);
        $_SESSION['clave_temporal'] = ['correo' => $correo, 'clave' => $clave_temporal];
        header("Location: equipo.php?msg=creado");
        exit;
    }

    // EDITAR MIEMBRO EXISTENTE
    if ($accion === 'editar') {
        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, correo=?, telefono=?, cargo=?, rol=?, estado_cuenta=? WHERE id=?");
        $stmt->execute([
            trim($_POST['nombre']),
            $correo,
            trim($_POST['telefono'] ?? ''),
            trim($_POST['cargo'] ?? ''),
            $_POST['rol'],
            $_POST['estado_cuenta'],
            (int) $_POST['id']
        ]);
        header("Location: equipo.php?msg=editado");
        exit;
    }

    // SUSPENDER (Botón de pánico rojo)
    if ($accion === 'eliminar') {
        $stmt = $db->prepare("UPDATE usuarios SET estado_cuenta = 'Suspendido' WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header("Location: equipo.php?msg=suspendido");
        exit;
    }

    // REACTIVAR (Botón verde)
    if ($accion === 'activar') {
        $stmt = $db->prepare("UPDATE usuarios SET estado_cuenta = 'Activo' WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header("Location: equipo.php?msg=activado");
        exit;
    }
}

// ================================================================
// LÓGICA DE VISUALIZACIÓN
// ================================================================

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$titulo = "Equipo de Trabajo";
$subtitulo = "Gestión de colaboradores, accesos y roles del sistema";

// KPIs REALES DEL EQUIPO
$total_equipo = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol != 'Cliente'")->fetchColumn();
$activos = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol != 'Cliente' AND estado_cuenta = 'Activo'")->fetchColumn();
$admins = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'Admin'")->fetchColumn();

// BÚSQUEDA Y LISTADO
$buscar = $_GET['buscar'] ?? '';
$sql = "SELECT * FROM usuarios WHERE rol != 'Cliente'";
$params = [];

if ($buscar) {
    $sql .= " AND (nombre LIKE ? OR correo LIKE ? OR cargo LIKE ? OR rol LIKE ? OR estado_cuenta LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}

$sql .= " ORDER BY rol ASC, nombre ASC";
$stmt_eq = $db->prepare($sql);
$stmt_eq->execute($params);
$equipo = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipo | DataSense Enterprise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                <i class="fas fa-check-circle me-2"></i> Operación realizada con éxito en la base de datos.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['clave_temporal'])): $nueva_cuenta = $_SESSION['clave_temporal']; unset($_SESSION['clave_temporal']); ?>
            <div class="alert alert-warning rounded-4 shadow-sm border-0 mb-4" role="alert">
                <i class="fas fa-key me-2"></i> Acceso creado para <strong><?= esc($nueva_cuenta['correo']) ?></strong>. Contraseña temporal:
                <code class="fs-6 user-select-all"><?= esc($nueva_cuenta['clave']) ?></code>
                <div class="small mt-1">Se muestra una sola vez: compártela por un canal seguro y pide cambiarla en "Mi Perfil".</div>
            </div>
        <?php endif; ?>

        <?php
        $errores_equipo = [
            'correo_duplicado' => 'Ese correo ya está registrado en otra cuenta. Usa un correo distinto.',
            'datos_invalidos'  => 'Revisa los datos: el nombre es obligatorio y el correo debe tener un formato válido.',
        ];
        ?>
        <?php if(isset($_GET['err'])): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                <i class="fas fa-ban me-2"></i> <?= $errores_equipo[$_GET['err']] ?? 'Acción no permitida: revisa el rol o estado elegido. Solo un Admin puede gestionar cuentas de Admin y nadie puede suspenderse o cambiarse el rol a sí mismo.' ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-12 col-md-4">
                <div class="card card-kpi p-4 border-0 border-bottom border-4 shadow-sm h-100" style="border-color: var(--azul-sonda) !important;">
                    <small class="text-muted fw-bold d-block mb-1 text-uppercase">Total del Personal</small>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0" style="color: var(--azul-sonda);"><?= $total_equipo ?></h3>
                        <i class="fas fa-users fa-2x opacity-25" style="color: var(--azul-sonda);"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-kpi p-4 border-0 border-bottom border-4 border-success shadow-sm h-100">
                    <small class="text-muted fw-bold d-block mb-1 text-uppercase">Usuarios Activos</small>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0 text-success"><?= $activos ?></h3>
                        <i class="fas fa-user-check fa-2x text-success opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-kpi p-4 border-0 border-bottom border-4 border-danger shadow-sm h-100">
                    <small class="text-muted fw-bold d-block mb-1 text-uppercase">Administradores</small>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0 text-danger"><?= $admins ?></h3>
                        <i class="fas fa-user-shield fa-2x text-danger opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="toolbar-pro d-flex align-items-center justify-content-between mb-2">
            <form action="" method="GET" class="flex-grow-1 me-3">
                <div class="search-container-pro shadow-sm">
                    <i class="fas fa-search"></i>
                    <input type="text" name="buscar" placeholder="Buscar por nombre, correo, rol o estado..." value="<?= htmlspecialchars($buscar) ?>">
                    <button type="submit" class="btn-buscar-int">BUSCAR</button>
                </div>
            </form>
            <button class="btn-nuevo-cliente shadow" data-bs-toggle="modal" data-bs-target="#modalNuevoMiembro">
                <i class="fas fa-user-plus me-2"></i> NUEVO MIEMBRO
            </button>
        </div>

        <div class="card-table">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">COLABORADOR</th>
                        <th>CONTACTO</th>
                        <th>ROL Y CARGO</th>
                        <th>ESTADO HR</th>
                        <th class="text-center">GESTIÓN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($equipo)): ?>
                        <tr><td colspan="5" class="text-center p-5 text-muted">No se encontraron colaboradores registrados.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($equipo as $e): ?>
                    <tr>
                        <td class="ps-4 py-3">
                            <div class="d-flex align-items-center gap-3">
                                <?php 
                                    if (!empty($e['foto'])) {
                                        $avatar = imagenDataUri($e['foto']);
                                    } else {
                                        $avatar = '../assets/img/perfiles/default.png';
                                    }
                                ?>
                                <?php if(empty($e['foto'])): ?>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-light text-secondary border" style="width: 40px; height: 40px;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php else: ?>
                                    <img src="<?= $avatar ?>" class="rounded-circle border shadow-sm" style="width: 40px; height: 40px; object-fit: cover;" alt="Foto">
                                <?php endif; ?>
                                
                                <div>
                                    <div class="fw-bold text-dark" style="font-size: 1.05rem;"><?= htmlspecialchars($e['nombre']) ?></div>
                                    <span class="badge bg-light text-muted fw-normal border" style="font-size: 0.65rem;">
                                        ID: #<?= str_pad($e['id'], 4, "0", STR_PAD_LEFT) ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="small mb-1 text-dark"><i class="fas fa-envelope text-muted me-2"></i><?= htmlspecialchars($e['correo']) ?></div>
                            <div class="small text-muted"><i class="fas fa-phone text-muted me-2"></i><?= htmlspecialchars($e['telefono'] ?? 'N/A') ?></div>
                        </td>
                        <td>
                            <?php 
                                switch ($e['rol']) {
                                    case 'Admin': $clase_rol = 'bg-danger bg-opacity-10 text-danger'; break;
                                    case 'Comercial': $clase_rol = 'bg-warning bg-opacity-10 text-dark'; break;
                                    case 'Analista': $clase_rol = 'bg-primary bg-opacity-10 text-primary'; break;
                                    case 'Finanzas': $clase_rol = 'bg-info bg-opacity-10 text-info'; break;
                                    case 'Soporte': $clase_rol = 'bg-success bg-opacity-10 text-success'; break;
                                    case 'Supervisor': $clase_rol = 'bg-dark bg-opacity-10 text-dark'; break;
                                    default: $clase_rol = 'bg-secondary bg-opacity-10 text-secondary'; break;
                                }
                            ?>
                            <div class="mb-1">
                                <span class="badge rounded-pill <?= $clase_rol ?> border-0 px-3 py-2">
                                    <i class="fas fa-user-tag me-1"></i> <?= htmlspecialchars($e['rol']) ?>
                                </span>
                            </div>
                            <div class="text-muted small ms-1 fw-bold"><?= htmlspecialchars($e['cargo'] ?? 'Sin cargo asignado') ?></div>
                        </td>
                        <td>
                            <?php 
                                $estado_actual = $e['estado_cuenta'] ?? 'Activo';
                                switch ($estado_actual) {
                                    case 'Activo':
                                        $bg_estado = 'bg-success';
                                        $icono_estado = 'fa-check-circle';
                                        break;
                                    case 'Vacaciones':
                                        $bg_estado = 'bg-warning text-dark';
                                        $icono_estado = 'fa-umbrella-beach'; 
                                        break;
                                    case 'Suspendido':
                                        $bg_estado = 'bg-danger';
                                        $icono_estado = 'fa-user-lock'; 
                                        break;
                                    case 'Retirado':
                                        $bg_estado = 'bg-secondary';
                                        $icono_estado = 'fa-user-slash'; 
                                        break;
                                    default:
                                        $bg_estado = 'bg-dark';
                                        $icono_estado = 'fa-circle';
                                        break;
                                }
                            ?>
                            <span class="badge rounded-pill <?= $bg_estado ?> px-3 py-2 shadow-sm border-0">
                                <i class="fas <?= $icono_estado ?> me-1"></i> <?= esc(strtoupper($estado_actual)) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            
                            <button class="btn-circle" title="Editar Permisos"
                                onclick="abrirModalEditar(<?= (int) $e['id'] ?>, <?= jsAttr($e['nombre']) ?>, <?= jsAttr($e['correo']) ?>, <?= jsAttr($e['telefono'] ?? '') ?>, <?= jsAttr($e['cargo'] ?? '') ?>, <?= jsAttr($e['rol']) ?>, <?= jsAttr($e['estado_cuenta'] ?? 'Activo') ?>)">
                                <i class="fas fa-pen text-primary"></i>
                            </button>

                            <?php if($e['id'] != $_SESSION['admin_id']): ?>
                                <?php if(($e['estado_cuenta'] ?? 'Activo') === 'Activo'): ?>
                                    <form action="" method="POST" style="display:inline;" onsubmit="return confirm('¿Deseas SUSPENDER el acceso de este colaborador al sistema?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                        <button type="submit" class="btn-circle ms-2" title="Bloquear Acceso"><i class="fas fa-ban text-danger"></i></button>
                                    </form>
                                <?php else: ?>
                                    <form action="" method="POST" style="display:inline;" onsubmit="return confirm('¿Deseas REACTIVAR el acceso de este colaborador?');">
                                        <input type="hidden" name="accion" value="activar">
                                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                        <button type="submit" class="btn-circle ms-2" title="Reactivar Acceso"><i class="fas fa-check-circle text-success"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <button class="btn-circle ms-2 opacity-25" title="No puedes suspender tu propia cuenta" disabled><i class="fas fa-user-shield text-muted"></i></button>
                            <?php endif; ?>

                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<div class="modal fade" id="modalNuevoMiembro" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="overflow: hidden;">
            <div class="modal-header border-0 p-4" style="background: rgba(10, 46, 79, 0.03); border-bottom: 3px solid var(--azul-sonda) !important;">
                <h5 class="fw-bold mb-0" style="color: var(--azul-sonda);">
                    <i class="fas fa-user-plus me-2"></i> Registrar Colaborador
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="accion" value="crear">
                <div class="modal-body p-4 bg-white">
                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Nombre Completo</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--azul-sonda);"><i class="fas fa-user"></i></span>
                            <input type="text" name="nombre" class="form-control border-start-0 rounded-end-3 py-2 px-2" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Correo Corporativo</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--azul-sonda);"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="correo" class="form-control border-start-0 rounded-end-3 py-2 px-2" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Teléfono</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--verde-datasense);"><i class="fas fa-phone"></i></span>
                                <input type="text" name="telefono" class="form-control border-start-0 rounded-end-3 py-2 px-2">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Cargo / Puesto de Trabajo</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3 text-secondary"><i class="fas fa-briefcase"></i></span>
                            <input type="text" name="cargo" class="form-control border-start-0 rounded-end-3 py-2 px-2" placeholder="Ej: Analista de Datos, Comercial..." required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Nivel de Acceso (Rol)</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3 text-danger"><i class="fas fa-user-shield"></i></span>
                                <select name="rol" class="form-select border-start-0 rounded-end-3 py-2 px-2" required>
                                    <option value="Comercial">Comercial / Ventas</option>
                                    <option value="Analista">Analista de Datos</option>
                                    <option value="Finanzas">Finanzas / Contabilidad</option>
                                    <option value="Soporte">Soporte Técnico</option>
                                    <option value="Supervisor">Supervisor (mesa de servicios y PQRSD)</option>
                                    <option value="Admin">Administrador (Total)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Estado de Cuenta</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3 text-success"><i class="fas fa-toggle-on"></i></span>
                                <select name="estado_cuenta" class="form-select border-start-0 rounded-end-3 py-2 px-2">
                                    <option value="Activo">🟢 Activo (Trabajando)</option>
                                    <option value="Vacaciones">🟡 De Vacaciones</option>
                                    <option value="Suspendido">🔴 Suspendido (Bloqueado)</option>
                                    <option value="Retirado">⚫ Retirado / Ex-empleado</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="alert border-0 rounded-3 mt-3 mb-0 small d-flex align-items-center shadow-sm" style="background: rgba(10, 46, 79, 0.05); color: var(--azul-sonda); border-left: 4px solid var(--azul-sonda) !important;">
                        <i class="fas fa-info-circle fs-5 me-3"></i> 
                        <div>Se generará una <b>contraseña temporal segura</b> que verás una sola vez al guardar. Compártela con el colaborador y pídele cambiarla en <b>Mi Perfil</b>.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2 fw-bold" data-bs-dismiss="modal">CANCELAR</button>
                    <button type="submit" class="btn text-white rounded-3 px-4 py-2 fw-bold shadow-sm" style="background: var(--azul-sonda);">
                        <i class="fas fa-save me-2"></i> CREAR ACCESO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarMiembro" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="overflow: hidden;">
            <div class="modal-header border-0 p-4" style="background: rgba(10, 46, 79, 0.03); border-bottom: 3px solid var(--verde-datasense) !important;">
                <h5 class="fw-bold mb-0" style="color: var(--verde-datasense);">
                    <i class="fas fa-user-edit me-2"></i> Editar Permisos
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body p-4 bg-white">
                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Nombre Completo</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--azul-sonda);"><i class="fas fa-user"></i></span>
                            <input type="text" name="nombre" id="edit_nombre" class="form-control border-start-0 rounded-end-3 py-2 px-2" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Correo Corporativo</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--azul-sonda);"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="correo" id="edit_correo" class="form-control border-start-0 rounded-end-3 py-2 px-2" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Teléfono</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--verde-datasense);"><i class="fas fa-phone"></i></span>
                                <input type="text" name="telefono" id="edit_telefono" class="form-control border-start-0 rounded-end-3 py-2 px-2">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Cargo / Puesto de Trabajo</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3 text-secondary"><i class="fas fa-briefcase"></i></span>
                            <input type="text" name="cargo" id="edit_cargo" class="form-control border-start-0 rounded-end-3 py-2 px-2" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Nivel de Acceso (Rol)</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3 text-danger"><i class="fas fa-user-shield"></i></span>
                                <select name="rol" id="edit_rol" class="form-select border-start-0 rounded-end-3 py-2 px-2" required>
                                    <option value="Comercial">Comercial / Ventas</option>
                                    <option value="Analista">Analista de Datos</option>
                                    <option value="Finanzas">Finanzas / Contabilidad</option>
                                    <option value="Soporte">Soporte Técnico</option>
                                    <option value="Supervisor">Supervisor (mesa de servicios y PQRSD)</option>
                                    <option value="Admin">Administrador (Total)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Estado de Cuenta</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3 text-success"><i class="fas fa-toggle-on"></i></span>
                                <select name="estado_cuenta" id="edit_estado" class="form-select border-start-0 rounded-end-3 py-2 px-2">
                                    <option value="Activo">🟢 Activo (Trabajando)</option>
                                    <option value="Vacaciones">🟡 De Vacaciones</option>
                                    <option value="Suspendido">🔴 Suspendido (Bloqueado)</option>
                                    <option value="Retirado">⚫ Retirado / Ex-empleado</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2 fw-bold" data-bs-dismiss="modal">CANCELAR</button>
                    <button type="submit" class="btn text-white rounded-3 px-4 py-2 fw-bold shadow-sm" style="background: var(--verde-datasense);">
                        <i class="fas fa-sync-alt me-2"></i> GUARDAR CAMBIOS
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function abrirModalEditar(id, nombre, correo, telefono, cargo, rol, estado) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_nombre').value = nombre;
        document.getElementById('edit_correo').value = correo;
        document.getElementById('edit_telefono').value = telefono;
        document.getElementById('edit_cargo').value = cargo;
        document.getElementById('edit_rol').value = rol;
        document.getElementById('edit_estado').value = estado;
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEditarMiembro'));
        myModal.show();
    }
</script>

</body>
</html>