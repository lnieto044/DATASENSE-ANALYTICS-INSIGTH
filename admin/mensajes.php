<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD
requiereAcceso($db, 'mensajes');

// 2. OBTENER PERFIL (Para el Header Modular)
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

// Variables del Header
$titulo = "Mensajes del Sitio Web";
$subtitulo = "Gestión de prospectos y solicitudes de análisis";

// 3. CAPTURAR FILTROS DE LA URL
$buscar = $_GET['buscar'] ?? '';
$mes = $_GET['mes'] ?? '';

// 4. CONSTRUCCIÓN DE LA CONSULTA
$sql = "SELECT * FROM contactos WHERE 1=1";
$params = [];

if ($buscar) {
    $sql .= " AND (nombre LIKE ? OR correo LIKE ?)";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}
if ($mes) {
    $sql .= " AND DATE_FORMAT(fecha_registro, '%Y-%m') = ?";
    $params[] = $mes;
}

$sql .= " ORDER BY fecha_registro DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bandeja de Entrada | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
       <?php 
        $tipo_campana = 'mensajes'; 
        include '../includes/header.php';
        ?>
        <?php if (($_GET['update'] ?? '') === 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                <i class="fas fa-check-circle me-2"></i> Etapa del lead actualizada correctamente.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <div class="bg-white p-4 rounded-4 shadow-sm mb-4 border-0">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="small fw-bold text-muted mb-2"><i class="fas fa-search me-1"></i> BUSCAR ENTIDAD</label>
                    <input type="text" name="buscar" class="form-control rounded-pill px-4 py-2 bg-light border-0" placeholder="Ej: Tech Global, Juan..." value="<?php echo htmlspecialchars($buscar); ?>">
                </div>
                <div class="col-md-4">
                    <label class="small fw-bold text-muted mb-2"><i class="fas fa-calendar-alt me-1"></i> FILTRAR POR MES</label>
                    <input type="month" name="mes" class="form-control rounded-pill px-4 py-2 bg-light border-0" value="<?php echo esc($mes); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark w-100 fw-bold rounded-pill py-2 shadow-sm" style="background:var(--azul-sonda);">
                        APLICAR FILTROS
                    </button>
                </div>
            </form>
        </div>

        <div class="card-table">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">ENTIDAD / NOMBRE</th>
                        <th>SECTOR</th>
                        <th>ESTADO ACTUAL</th>
                        <th>FECHA REGISTRO</th>
                        <th class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($contactos)): ?>
                        <tr><td colspan="5" class="text-center p-5 text-muted">No hay mensajes en esta bandeja.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($contactos as $c): ?>
                    <tr>
                        <td class="ps-4 py-3">
                            <div class="fw-bold text-dark" style="font-size: 1.05rem;"><?php echo htmlspecialchars($c['nombre']); ?></div>
                            <div class="text-muted small"><i class="fas fa-envelope me-1 opacity-50"></i><?php echo htmlspecialchars($c['correo']); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill shadow-sm">
                                <?php echo htmlspecialchars($c['tipo_cliente']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                                // Limpia el nombre del estado para que coincida con las clases del admin.css
                                // Ej: "En Calificación" -> "EnCalificacion"
                                $estado_limpio = str_replace([' ', 'ó', 'í'], ['', 'o', 'i'], $c['estado'] ?? '');
                                $clase_estado = "st-" . preg_replace('/[^A-Za-z0-9_-]/', '', $estado_limpio);
                            ?>
                            <div class="status-badge <?php echo $clase_estado; ?>">
                                <i class="fas fa-circle me-1" style="font-size:0.4rem"></i>
                                <?php echo esc(mb_strtoupper($c['estado'] ?? '', 'UTF-8')); ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($c['fecha_registro'])); ?></div>
                            <div class="text-muted small"><i class="far fa-clock me-1"></i><?php echo date('h:i A', strtotime($c['fecha_registro'])); ?></div>
                        </td>
                        <td class="text-center">
                            <button class="btn-circle" data-bs-toggle="modal" data-bs-target="#leer<?php echo $c['id']; ?>" title="Leer Mensaje" onclick="marcarComoLeido(<?php echo $c['id']; ?>)">
                                <i class="fas fa-eye text-primary"></i>
                            </button>
                            <button class="btn-circle ms-2" data-bs-toggle="modal" data-bs-target="#edit<?php echo $c['id']; ?>" title="Cambiar Estado">
                                <i class="fas fa-edit text-success"></i>
                            </button>
                        </td>
                    </tr>

                    <div class="modal fade" id="leer<?php echo $c['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content rounded-4 border-0 shadow-lg">
                                <div class="modal-header bg-light border-0">
                                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-comment-dots text-primary me-2"></i> Mensaje de <?php echo htmlspecialchars($c['nombre']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <p class="text-muted small fw-bold mb-1">SERVICIO SOLICITADO:</p>
                                    <h6 class="fw-bold text-primary mb-4 border-bottom pb-2"><?php echo esc(nombreServicio($c['servicio'])); ?></h6>
                                    
                                    <p class="text-muted small fw-bold mb-1">DESAFÍO / MENSAJE:</p>
                                    <div class="bg-light p-3 rounded-3 text-dark" style="border-left: 4px solid var(--azul-sonda);">
                                        "<?php echo nl2br(htmlspecialchars($c['mensaje'])); ?>"
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="edit<?php echo $c['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-sm modal-dialog-centered">
                            <div class="modal-content rounded-4 border-0 shadow-lg">
                                <form action="actualizar_estado.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                    <div class="modal-body p-4 text-center">
                                        <div class="mb-3 text-success">
                                            <i class="fas fa-sync-alt fa-2x"></i>
                                        </div>
                                        <h6 class="fw-bold mb-3">Actualizar Etapa del Lead</h6>
                                        <select name="nuevo_estado" class="form-select rounded-pill mb-4 bg-light">
                                            <?php 
                                            $opciones = ['Nuevo','En Calificación','Contactado','En Propuesta','Negociación','Convertido','Descartado'];
                                            foreach($opciones as $op):
                                                $sel = ($c['estado'] == $op) ? 'selected' : '';
                                                echo "<option value='$op' $sel>$op</option>";
                                            endforeach;
                                            ?>
                                        </select>
                                        <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill" style="background:var(--verde-datasense); border:none;">
                                            GUARDAR CAMBIO
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function marcarComoLeido(id) {
    fetch('marcar_leido.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    })
    .then(response => response.text())
    .then(data => {
        if(typeof revisarNotificacionesLocales === 'function') {
            revisarNotificacionesLocales(); 
        }
    });
}
</script>
</body>
</html>