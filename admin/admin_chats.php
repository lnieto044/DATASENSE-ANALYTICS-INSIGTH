<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD
requiereAcceso($db, 'chats');

// 2. OBTENER PERFIL (Para el Header Modular)
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

// Variables del Header
$titulo = "Historial SenseBot";
$subtitulo = "Conversaciones y leads generados por inteligencia artificial";

// 3. CAPTURAR FILTROS DE LA URL
$buscar = $_GET['buscar'] ?? '';
$mes = $_GET['mes'] ?? '';

// 4. CONSTRUCCIÓN DE LA CONSULTA
$sql = "SELECT * FROM ds_chat_sessions WHERE 1=1";
$params = [];

if ($buscar) {
    $sql .= " AND (nombre_usuario LIKE ? OR empresa_entidad LIKE ?)";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}
if ($mes) {
    $sql .= " AND DATE_FORMAT(fecha_inicio, '%Y-%m') = ?";
    $params[] = $mes;
}

$sql .= " ORDER BY fecha_inicio DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial SenseBot | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php 
        $tipo_campana = 'mensajes'; // Mantiene activa la sección correcta
        include '../includes/header.php'; 
        ?>
        
        <div class="bg-white p-4 rounded-4 shadow-sm mb-4 border-0">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="small fw-bold text-muted mb-2"><i class="fas fa-search me-1"></i> BUSCAR USUARIO / EMPRESA</label>
                    <input type="text" name="buscar" class="form-control rounded-pill px-4 py-2 bg-light border-0" placeholder="Ej: Tech Global, Juan..." value="<?php echo htmlspecialchars($buscar); ?>">
                </div>
                <div class="col-md-4">
                    <label class="small fw-bold text-muted mb-2"><i class="fas fa-calendar-alt me-1"></i> FILTRAR POR MES</label>
                    <input type="month" name="mes" class="form-control rounded-pill px-4 py-2 bg-light border-0" value="<?php echo htmlspecialchars($mes); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark w-100 fw-bold rounded-pill py-2 shadow-sm" style="background:var(--azul-sonda, #0A2E4F);">
                        APLICAR FILTROS
                    </button>
                </div>
            </form>
        </div>

        <div class="card-table">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">INTERESADO</th>
                        <th>EMPRESA / ENTIDAD</th>
                        <th>ESTADO SESIÓN</th>
                        <th>FECHA INTERACCIÓN</th>
                        <th class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($sesiones)): ?>
                        <tr><td colspan="5" class="text-center p-5 text-muted">No hay conversaciones registradas aún.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($sesiones as $s): ?>
                    <tr>
                        <td class="ps-4 py-3">
                            <div class="fw-bold text-dark" style="font-size: 1.05rem;"><?php echo htmlspecialchars($s['nombre_usuario']); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill shadow-sm">
                                <i class="fas fa-building me-1 opacity-50"></i> <?php echo htmlspecialchars($s['empresa_entidad']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                                $is_activo = ($s['estado'] == 'activo');
                                $clase_bg = $is_activo ? 'bg-success' : 'bg-secondary';
                            ?>
                            <div class="badge <?php echo $clase_bg; ?> rounded-pill px-3 py-2 shadow-sm">
                                <i class="fas fa-circle me-1" style="font-size:0.4rem"></i>
                                <?php echo esc(strtoupper($s['estado'] ?? '')); ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($s['fecha_inicio'])); ?></div>
                            <div class="text-muted small"><i class="far fa-clock me-1"></i><?php echo date('h:i A', strtotime($s['fecha_inicio'])); ?></div>
                        </td>
                        <td class="text-center">
                            <button class="btn-circle" title="Leer Chat" onclick="verChat(<?php echo (int) $s['id']; ?>, <?php echo jsAttr($s['nombre_usuario']); ?>)">
                                <i class="fas fa-eye text-primary"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<div class="modal fade" id="chatModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fas fa-robot text-success me-2"></i> Charla con <span id="modalClientName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="background-color: #f8fafc;">
                <div id="chatMessagesContent" style="display: flex; flex-direction: column; max-height: 450px; overflow-y: auto;">
                    </div>
            </div>
            <div class="modal-footer border-0 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar Historial</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function verChat(sessionId, clientName) {
    // 1. Asignar el nombre al título
    document.getElementById('modalClientName').innerText = clientName;
    
    // 2. Mostrar loader de tu plantilla
    document.getElementById('chatMessagesContent').innerHTML = `
        <div class="text-center text-muted py-5">
            <i class="fas fa-circle-notch fa-spin fa-2x mb-3 text-primary"></i>
            <p class="fw-bold">Cargando conversación...</p>
        </div>`;
    
    // 3. Mostrar el Modal de Bootstrap
    var chatModal = new bootstrap.Modal(document.getElementById('chatModal'));
    chatModal.show();

    // 4. Traer datos por AJAX
    fetch('obtener_mensajes.php?id=' + sessionId)
        .then(response => response.text())
        .then(html => {
            let container = document.getElementById('chatMessagesContent');
            container.innerHTML = html;
            // Bajar el scroll al último mensaje
            container.scrollTop = container.scrollHeight;
        })
        .catch(error => {
            document.getElementById('chatMessagesContent').innerHTML = '<div class="alert alert-danger m-3">Error al cargar la conversación.</div>';
        });
}
</script>

</body>
</html>