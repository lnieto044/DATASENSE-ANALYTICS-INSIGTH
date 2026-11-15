<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// El acceso lo decide el módulo "roles" de Seguridad y Roles (hoy solo el Administrador), no una lista fija de roles
requiereAcceso($db, 'roles');

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$titulo = "Seguridad y Roles";
$subtitulo = "Control total de accesos, permisos y estados de cuenta";

$conteo_roles = $db->query("SELECT rol, COUNT(*) as total FROM usuarios GROUP BY rol")->fetchAll(PDO::FETCH_KEY_PAIR);

$res = $db->query("SELECT * FROM permisos_roles");
$permisos_db = [];
while($row = $res->fetch(PDO::FETCH_ASSOC)){
    $permisos_db[$row['rol']][$row['modulo']] = $row['acceso'];
}

$stmt_modulos = $db->query("SELECT id_modulo, etiqueta, icono FROM modulos_sistema ORDER BY orden ASC");
$modulos_sistema = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

// Lista de usuarios incluyendo la columna estado_cuenta
$stmt_all_users = $db->query("SELECT id, nombre, correo, rol, estado_cuenta FROM usuarios ORDER BY nombre ASC");
$lista_usuarios = $stmt_all_users->fetchAll(PDO::FETCH_ASSOC);

$roles_mostrar = ['Admin', 'Soporte', 'Supervisor', 'Analista', 'Finanzas', 'Comercial', 'Cliente'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Roles | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        .role-card { border-radius: 16px; border: none; transition: 0.3s; background: #fff; }
        .mod-pill { font-size: 0.68rem; padding: 4px 10px; border-radius: 50px; background: #f1f3f5; color: #495057; display: inline-block; margin: 2px; border: 1px solid #e9ecef; }
        .search-container { position: relative; }
        .search-container i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .search-input { border-radius: 10px; border: 1px solid #e2e8f0; padding: 8px 15px 8px 40px; font-size: 0.9rem; min-width: 250px; }
        .btn-excel { background-color: #1D6F42; color: white; border: none; font-weight: bold; border-radius: 10px; padding: 8px 20px; transition: 0.3s; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="row g-4 mb-5">
            <?php 
            $estilos = [
                'Admin' => ['color' => 'danger', 'icon' => 'fa-user-shield'],
                'Soporte' => ['color' => 'success', 'icon' => 'fa-headset'],
                'Supervisor' => ['color' => 'dark', 'icon' => 'fa-eye'],
                'Analista' => ['color' => 'primary', 'icon' => 'fa-chart-pie'],
                'Finanzas' => ['color' => 'info', 'icon' => 'fa-file-invoice-dollar'],
                'Comercial' => ['color' => 'warning', 'icon' => 'fa-handshake'],
                'Cliente' => ['color' => 'secondary', 'icon' => 'fa-briefcase']
            ];
            // Si algún día se agrega un rol sin estilo, se pinta con el gris de reserva en vez de romper la página
            foreach($roles_mostrar as $r_name): $e = $estilos[$r_name] ?? ['color' => 'secondary', 'icon' => 'fa-user-tag']; ?>
            <div class="col-md-6 col-xl-4">
                <div class="card role-card shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="p-3 rounded-3 bg-<?= $e['color'] ?> bg-opacity-10 text-<?= $e['color'] ?>"><i class="fas <?= $e['icon'] ?> fs-4"></i></div>
                            <div class="text-end"><h3 class="fw-bold mb-0"><?= $conteo_roles[$r_name] ?? 0 ?></h3><small class="text-muted fw-bold">USUARIOS</small></div>
                        </div>
                        <h5 class="fw-bold text-dark"><?= $r_name ?></h5>
                        <div id="container-<?= $r_name ?>" class="d-flex flex-wrap">
                            <?php foreach($modulos_sistema as $mod): if(isset($permisos_db[$r_name][$mod['id_modulo']]) && $permisos_db[$r_name][$mod['id_modulo']] == 1): ?>
                                <span class="mod-pill" id="pill-<?= $r_name ?>-<?= esc($mod['id_modulo']) ?>"><i class="fas fa-check-circle"></i> <?= esc($mod['etiqueta']) ?></span>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
            <div class="card-header bg-white py-4 px-4 border-0"><h5 class="fw-bold mb-0 text-dark"><i class="fas fa-th me-2 text-primary"></i> Matriz de Permisos por Rol</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">MÓDULO</th>
                            <?php foreach($roles_mostrar as $r): ?><th class="text-center small fw-bold"><?= strtoupper($r) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($modulos_sistema as $mod): ?>
                        <tr>
                            <td class="ps-4 py-3"><span class="fw-bold text-dark"><i class="fas <?= esc($mod['icono']) ?> me-2 text-muted"></i> <?= esc($mod['etiqueta']) ?></span></td>
                            <?php foreach($roles_mostrar as $r): ?>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox" onchange="savePermiso(<?= jsAttr($r) ?>, <?= jsAttr($mod['id_modulo']) ?>, <?= jsAttr($mod['etiqueta']) ?>, this.checked)"
                                    <?= (isset($permisos_db[$r][$mod['id_modulo']]) && $permisos_db[$r][$mod['id_modulo']] == 1) ? 'checked' : '' ?>
                                    <?= ($r == 'Admin') ? 'disabled checked' : '' ?>>
                                </div>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-4 px-4 border-0 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-user-tag me-2 text-success"></i> Usuarios y Accesos Globales</h5>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="search-container"><i class="fas fa-search"></i><input type="text" id="userSearch" class="form-control search-input" placeholder="Buscar..." onkeyup="filterUsers()"></div>
                    <button onclick="exportarExcel()" class="btn btn-excel shadow-sm"><i class="fas fa-file-excel me-2"></i> EXCEL</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="userTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">NOMBRE COMPLETO</th>
                            <th>CORREO</th>
                            <th class="text-center">ROL</th>
                            <th class="text-center">ESTADO</th> <th class="text-center">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($lista_usuarios as $user): ?>
                        <tr class="user-row">
                            <td class="ps-4"><div class="fw-bold text-dark user-name"><?= htmlspecialchars($user['nombre']) ?></div></td>
                            <td class="user-email text-muted small"><?= htmlspecialchars($user['correo']) ?></td>
                            <td class="text-center"><span class="badge rounded-pill bg-primary bg-opacity-10 text-primary" id="badge-rol-<?= (int) $user['id'] ?>"><?= esc($user['rol']) ?></span></td>
                            
                            <td class="text-center user-status">
                                <?php $bloqueado = ($user['estado_cuenta'] === 'Suspendido'); ?>
                                <span class="badge rounded-pill <?= $bloqueado ? 'bg-danger' : 'bg-success' ?>" id="badge-status-<?= $user['id'] ?>">
                                    <?= esc($user['estado_cuenta'] ?? 'Activo') ?>
                                </span>
                            </td>

                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-center gap-2">
                                    <select class="form-select form-select-sm fw-bold border-0 bg-light" style="width: 120px;" onchange="cambiarRolUsuario(<?= $user['id'] ?>, this.value)">
                                        <?php foreach($roles_mostrar as $op): ?>
                                            <option value="<?= $op ?>" <?= ($user['rol'] == $op) ? 'selected' : '' ?>><?= $op ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button id="btn-lock-<?= $user['id'] ?>" class="btn btn-sm <?= $bloqueado ? 'btn-danger' : 'btn-outline-success' ?> rounded-circle" 
                                            onclick="toggleEstadoUsuario(<?= (int) $user['id'] ?>, <?= jsAttr($user['estado_cuenta'] ?? 'Activo') ?>)" style="width: 32px; height: 32px; padding: 0;">
                                        <i class="fas <?= $bloqueado ? 'fa-lock' : 'fa-unlock' ?>"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 1. Guardar Permisos (Matriz)
function savePermiso(rol, modId, label, estado) {
    const params = new URLSearchParams();
    params.append('rol', rol); params.append('modulo', modId); params.append('acceso', estado ? 1 : 0);
    fetch('actualizar_permiso.php', { method: 'POST', body: params }).then(() => location.reload());
}

// 2. Cambiar Rol
function cambiarRolUsuario(userId, rol) {
    const params = new URLSearchParams();
    params.append('id_usuario', userId); params.append('nuevo_rol', rol);
    fetch('actualizar_rol_usuario.php', { method: 'POST', body: params })
    .then(res => res.text()).then(data => {
        if(data === 'ok') {
            document.getElementById(`badge-rol-${userId}`).innerText = rol;
        } else {
            alert('No se pudo cambiar el rol: nadie modifica su propia cuenta y solo un Admin gestiona cuentas de Admin.');
            location.reload();
        }
    });
}

// 3. Bloqueo de Cuenta (Actualizado para cambiar el texto del badge de estado)
function toggleEstadoUsuario(userId, estadoActual) {
    const nuevoEstado = (estadoActual === 'Suspendido') ? 'Activo' : 'Suspendido';
    if(!confirm(`¿Deseas ${nuevoEstado.toUpperCase()} este usuario?`)) return;

    const params = new URLSearchParams();
    params.append('id_usuario', userId); params.append('nuevo_estado', nuevoEstado);

    fetch('actualizar_rol_usuario.php', { method: 'POST', body: params })
    .then(res => res.text()).then(data => {
        if(data === 'ok') {
            const btn = document.getElementById(`btn-lock-${userId}`);
            const badgeStatus = document.getElementById(`badge-status-${userId}`);

            if(nuevoEstado === 'Suspendido') {
                btn.classList.replace('btn-outline-success', 'btn-danger');
                btn.innerHTML = '<i class="fas fa-lock"></i>';
                badgeStatus.innerText = 'Suspendido';
                badgeStatus.classList.replace('bg-success', 'bg-danger');
            } else {
                btn.classList.replace('btn-danger', 'btn-outline-success');
                btn.innerHTML = '<i class="fas fa-unlock"></i>';
                badgeStatus.innerText = 'Activo';
                badgeStatus.classList.replace('bg-danger', 'bg-success');
            }
            // Importante: Actualizar el atributo para el siguiente clic
            btn.setAttribute('onclick', `toggleEstadoUsuario(${userId}, '${nuevoEstado}')`);
        } else if(data === 'error_auto_bloqueo') {
            alert("No puedes bloquear tu propia cuenta.");
        } else {
            alert("No tienes permiso para cambiar el estado de este usuario.");
        }
    });
}

// 4. Buscador
function filterUsers() {
    const f = document.getElementById('userSearch').value.toLowerCase();
    const rows = document.getElementsByClassName('user-row');
    for (let r of rows) {
        const txt = r.querySelector('.user-name').innerText + r.querySelector('.user-email').innerText;
        r.style.display = txt.toLowerCase().includes(f) ? "" : "none";
    }
}

// 5. Excel (Actualizado para exportar 4 columnas: Nombre, Correo, Rol y Estado)
function exportarExcel() {
    let tempTable = document.getElementById("userTable").cloneNode(true);
    for (let row of tempTable.rows) {
        row.deleteCell(-1); // Elimina la columna de "ACCIONES" (la última)
    }
    const ws = XLSX.utils.table_to_sheet(tempTable);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Usuarios_DataSense");
    XLSX.writeFile(wb, "Reporte_Roles_y_Estados.xlsx");
}
</script>
</body>
</html>