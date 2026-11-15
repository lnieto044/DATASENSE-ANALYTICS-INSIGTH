<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD
requiereAcceso($db, 'clientes');

// ================================================================
// LÓGICA DE PROCESAMIENTO (CRUD: CREAR, EDITAR, ELIMINAR)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $correo = trim($_POST['correo'] ?? '');

    // El correo identifica la cuenta al iniciar sesión: debe ser válido y no estar en uso por otro usuario
    if (in_array($accion, ['crear', 'editar'], true)) {
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || trim($_POST['nombre'] ?? '') === '') {
            header("Location: clientes.php?err=datos_invalidos"); exit;
        }
        $duplicado = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ? AND id <> ?");
        $duplicado->execute([$correo, (int) ($_POST['id'] ?? 0)]);
        if ($duplicado->fetchColumn()) {
            header("Location: clientes.php?err=correo_duplicado"); exit;
        }
        $estado_cuenta = in_array($_POST['estado_cuenta'] ?? '', ['Activo', 'Inactivo'], true) ? $_POST['estado_cuenta'] : 'Activo';
    }

    // CREAR NUEVO CLIENTE (Si lo creas tú, automáticamente queda "visto = 1")
    if ($accion === 'crear') {
        $clave_temporal = generarClaveTemporal(); // Se muestra una sola vez al administrador
        $stmt = $db->prepare("INSERT INTO usuarios (nombre, correo, telefono, organizacion, estado_cuenta, rol, password, visto) VALUES (?, ?, ?, ?, ?, 'Cliente', ?, 1)");
        $stmt->execute([
            trim($_POST['nombre']),
            $correo,
            trim($_POST['telefono'] ?? ''),
            trim($_POST['organizacion'] ?? ''),
            $estado_cuenta,
            password_hash($clave_temporal, PASSWORD_DEFAULT)
        ]);
        $_SESSION['clave_temporal'] = ['correo' => $correo, 'clave' => $clave_temporal];
        header("Location: clientes.php?msg=creado");
        exit;
    }

    // EDITAR CLIENTE EXISTENTE
    if ($accion === 'editar') {
        // Solo cuentas de clientes: evita cambiar el correo de un Admin y tomar su cuenta con "recuperar clave"
        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, correo=?, telefono=?, organizacion=?, estado_cuenta=? WHERE id=? AND rol = 'Cliente'");
        $stmt->execute([
            trim($_POST['nombre']),
            $correo,
            trim($_POST['telefono'] ?? ''),
            trim($_POST['organizacion'] ?? ''),
            $estado_cuenta,
            (int) $_POST['id']
        ]);
        header("Location: clientes.php?msg=editado");
        exit;
    }

    // ELIMINAR CLIENTE: solo si no tiene facturas ni tickets, para no dejar registros huérfanos
    if ($accion === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        $historial = $db->prepare("SELECT (SELECT COUNT(*) FROM facturas WHERE id_usuario = ?) + (SELECT COUNT(*) FROM soporte_tickets WHERE usuario_id = ?)");
        $historial->execute([$id, $id]);
        if ($historial->fetchColumn() > 0) {
            header("Location: clientes.php?err=tiene_historial"); exit;
        }
        $db->prepare("UPDATE contactos SET id_usuario_final = NULL WHERE id_usuario_final = ?")->execute([$id]);
        $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'Cliente'");
        $stmt->execute([$id]);
        header("Location: clientes.php?msg=eliminado");
        exit;
    }
}

// ================================================================
// LÓGICA DE VISUALIZACIÓN
// ================================================================

// Obtener perfil
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$titulo = "Gestión de Clientes";
$subtitulo = "Cartera oficial de organizaciones vinculadas";

// Búsqueda
$buscar = $_GET['buscar'] ?? '';
$sql = "SELECT * FROM usuarios WHERE rol = 'Cliente'";
$params = [];

if ($buscar) {
    $sql .= " AND (nombre LIKE ? OR organizacion LIKE ? OR correo LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}

// ORDENAMOS PRIMERO POR LOS NO VISTOS (0), LUEGO POR NOMBRE
$stmt_c = $db->prepare($sql . " ORDER BY visto ASC, nombre ASC");
$stmt_c->execute($params);
$clientes = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | DataSense Enterprise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php 
        $tipo_campana = 'clientes'; 
        include '../includes/header.php'; 
        ?>

        <?php if (!empty($_SESSION['clave_temporal'])): $nueva_cuenta = $_SESSION['clave_temporal']; unset($_SESSION['clave_temporal']); ?>
            <div class="alert alert-warning shadow-sm rounded-4 border-0" role="alert">
                <i class="fas fa-key me-2"></i> Cuenta creada para <strong><?= esc($nueva_cuenta['correo']) ?></strong>. Contraseña temporal:
                <code class="fs-6 user-select-all"><?= esc($nueva_cuenta['clave']) ?></code>
                <div class="small mt-1">Se muestra una sola vez: compártela por un canal seguro y pide cambiarla en "Mi Perfil".</div>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-4" role="alert">
                <i class="fas fa-check-circle me-2"></i> Operación realizada con éxito en la base de datos.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php
        $errores_clientes = [
            'correo_duplicado' => 'Ese correo ya está registrado en otra cuenta. Usa un correo distinto.',
            'datos_invalidos'  => 'Revisa los datos: el nombre es obligatorio y el correo debe tener un formato válido.',
            'tiene_historial'  => 'No se puede eliminar: el cliente tiene facturas o tickets de soporte asociados. Puedes marcar su cuenta como Inactiva.',
            'cliente_no_existe' => 'Ese cliente no existe o la cuenta no tiene el rol Cliente.',
        ];
        ?>
        <?php if (isset($errores_clientes[$_GET['err'] ?? ''])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-4" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?= $errores_clientes[$_GET['err']] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="toolbar-pro">
            <form action="" method="GET" class="flex-grow-1">
                <div class="search-container-pro shadow-sm">
                    <i class="fas fa-search"></i>
                    <input type="text" name="buscar" placeholder="Buscar por nombre, empresa o ID..." value="<?= htmlspecialchars($buscar) ?>">
                    <button type="submit" class="btn-buscar-int">BUSCAR</button>
                </div>
            </form>

            <button class="btn-nuevo-cliente shadow" data-bs-toggle="modal" data-bs-target="#modalNuevoCliente">
                <i class="fas fa-plus-circle"></i> NUEVO CLIENTE
            </button>
        </div>

        <div class="card-table">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">CLIENTE / REFERENCIA</th>
                        <th>ORGANIZACIÓN</th>
                        <th>CONTACTO</th>
                        <th>ESTADO CUENTA</th>
                        <th class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($clientes)): ?>
                        <tr><td colspan="5" class="text-center p-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($clientes as $c): 
                        // VERIFICAR SI ES NUEVO
                        $es_nuevo = isset($c['visto']) && $c['visto'] == 0;
                        $clase_fila = $es_nuevo ? 'table-warning bg-opacity-25' : '';
                    ?>
                    <tr class="<?= $clase_fila ?>" id="fila-cliente-<?= $c['id'] ?>">
                        <td class="ps-4 py-3">
                            <div class="fw-bold text-dark" style="font-size: 1.05rem;">
                                <a href="cliente_ficha.php?id=<?= (int) $c['id'] ?>" class="text-dark text-decoration-none" title="Ver ficha del cliente"><?= htmlspecialchars($c['nombre']) ?></a>
                                <?php if($es_nuevo): ?>
                                    <span class="badge bg-danger ms-1" style="font-size: 0.6rem;" id="badge-cliente-<?= $c['id'] ?>">NUEVO</span>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-light text-muted fw-normal border" style="font-size: 0.65rem;">
                                REF ID: #<?= str_pad($c['id'], 4, "0", STR_PAD_LEFT) ?>
                            </span>
                        </td>
                        <td>
                            <div class="text-primary fw-bold small">
                                <?= esc(mb_strtoupper($c['organizacion'] ?? 'Independiente', 'UTF-8')) ?>
                            </div>
                        </td>
                        <td>
                            <div class="small mb-1 text-dark"><i class="fas fa-envelope text-muted me-2"></i><?= htmlspecialchars($c['correo']) ?></div>
                            <div class="small text-muted"><i class="fas fa-phone text-muted me-2"></i><?= htmlspecialchars($c['telefono'] ?? 'N/A') ?></div>
                        </td>
                        <td>
                            <?php $activo = ($c['estado_cuenta'] ?? 'Activo') == 'Activo'; ?>
                            <span class="status-badge <?= $activo ? 'bg-activo' : 'bg-inactivo' ?>">
                                <i class="fas fa-circle me-1" style="font-size:0.4rem"></i>
                                <?= strtoupper($activo ? 'Activo' : 'Inactivo') ?>
                            </span>
                        </td>
                        <td class="text-center">
                            
                            <?php if($es_nuevo): ?>
                                <button class="btn-circle me-1" title="Marcar como revisado" onclick="marcarClienteVisto(<?= $c['id'] ?>)" id="btn-visto-<?= $c['id'] ?>">
                                    <i class="fas fa-check-double text-primary"></i>
                                </button>
                            <?php endif; ?>

                            <a href="cliente_ficha.php?id=<?= (int) $c['id'] ?>" class="btn-circle me-1 d-inline-flex align-items-center justify-content-center text-decoration-none btn-ficha-cliente" title="Ver ficha del cliente" aria-label="Ver ficha de <?= esc($c['nombre']) ?>">
                                <i class="fas fa-id-card text-primary"></i>
                            </a>

                            <button class="btn-circle" title="Editar"
                                onclick="abrirModalEditar(<?= (int) $c['id'] ?>, <?= jsAttr($c['nombre']) ?>, <?= jsAttr($c['correo']) ?>, <?= jsAttr($c['telefono'] ?? '') ?>, <?= jsAttr($c['organizacion'] ?? '') ?>, <?= jsAttr($c['estado_cuenta'] ?? 'Activo') ?>)">
                                <i class="fas fa-pen text-success"></i>
                            </button>

                            <form action="" method="POST" style="display:inline;" onsubmit="return confirm('¿Confirma que desea eliminar este cliente del sistema permanentemente?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn-circle ms-2" title="Eliminar"><i class="fas fa-trash text-danger"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<div class="modal fade" id="modalNuevoCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="overflow: hidden;">
            <div class="modal-header border-0 p-4" style="background: rgba(10, 46, 79, 0.03); border-bottom: 3px solid var(--azul-sonda) !important;">
                <h5 class="fw-bold mb-0" style="color: var(--azul-sonda);">
                    <i class="fas fa-user-plus me-2"></i> Registrar Cliente
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
                            <input type="text" name="nombre" class="form-control border-start-0 rounded-end-3 py-2 px-2" placeholder="Nombre del representante" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Organización / Empresa</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3 text-secondary"><i class="fas fa-building"></i></span>
                            <input type="text" name="organizacion" class="form-control border-start-0 rounded-end-3 py-2 px-2" placeholder="Ej: Tech Solutions SAS">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Correo Electrónico</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--azul-sonda);"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="correo" class="form-control border-start-0 rounded-end-3 py-2 px-2" placeholder="cliente@empresa.com" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Teléfono</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-3" style="color: var(--verde-datasense);"><i class="fas fa-phone"></i></span>
                                <input type="text" name="telefono" class="form-control border-start-0 rounded-end-3 py-2 px-2" placeholder="Opcional">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Estado de Cuenta</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3 text-success"><i class="fas fa-toggle-on"></i></span>
                            <select name="estado_cuenta" class="form-select border-start-0 rounded-end-3 py-2 px-2">
                                <option value="Activo">Activo</option>
                                <option value="Inactivo">Inactivo / Bloqueado</option>
                            </select>
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 p-4 bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2 fw-bold" data-bs-dismiss="modal">CANCELAR</button>
                    <button type="submit" class="btn text-white rounded-3 px-4 py-2 fw-bold shadow-sm" style="background: var(--verde-datasense);">
                        <i class="fas fa-save me-2"></i> GUARDAR CLIENTE
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="overflow: hidden;">
            <div class="modal-header border-0 p-4" style="background: rgba(10, 46, 79, 0.03); border-bottom: 3px solid var(--verde-datasense) !important;">
                <h5 class="fw-bold mb-0" style="color: var(--verde-datasense);">
                    <i class="fas fa-pen me-2"></i> Editar Cliente
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

                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-2">Organización / Empresa</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3 text-secondary"><i class="fas fa-building"></i></span>
                            <input type="text" name="organizacion" id="edit_organizacion" class="form-control border-start-0 rounded-end-3 py-2 px-2">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-dark mb-2">Correo Electrónico</label>
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
                        <label class="small fw-bold text-dark mb-2">Estado de Cuenta</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-3 text-success"><i class="fas fa-toggle-on"></i></span>
                            <select name="estado_cuenta" id="edit_estado" class="form-select border-start-0 rounded-end-3 py-2 px-2">
                                <option value="Activo">Activo</option>
                                <option value="Inactivo">Inactivo / Bloqueado</option>
                            </select>
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 p-4 bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2 fw-bold" data-bs-dismiss="modal">CANCELAR</button>
                    <button type="submit" class="btn text-white rounded-3 px-4 py-2 fw-bold shadow-sm" style="background: var(--azul-sonda);">
                        <i class="fas fa-sync-alt me-2"></i> ACTUALIZAR DATOS
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function abrirModalEditar(id, nombre, correo, telefono, organizacion, estado) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_nombre').value = nombre;
        document.getElementById('edit_correo').value = correo;
        document.getElementById('edit_telefono').value = telefono;
        document.getElementById('edit_organizacion').value = organizacion;
        document.getElementById('edit_estado').value = estado;
        var myModal = new bootstrap.Modal(document.getElementById('modalEditarCliente'));
        myModal.show();
    }

    // SCRIPT PARA QUITAR LA ETIQUETA "NUEVO" SIN RECARGAR
    function marcarClienteVisto(id) {
        fetch('marcar_cliente_visto.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id
        })
        .then(response => response.text())
        .then(data => {
            if(data === 'ok') {
                // Quita el fondo amarillo
                document.getElementById('fila-cliente-' + id).classList.remove('table-warning', 'bg-opacity-25');
                // Quita la etiqueta roja de NUEVO
                let badge = document.getElementById('badge-cliente-' + id);
                if(badge) badge.remove();
                // Oculta el botón de check
                let btn = document.getElementById('btn-visto-' + id);
                if(btn) btn.remove();

                if(typeof revisarNotificacionesLocales === 'function') {
                revisarNotificacionesLocales(); 
            }
            }
        })
        .catch(error => console.error('Error:', error));
    }
</script>

</body>
</html>