<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'encuestas');

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = "Sistema de Encuestas Nativas";
$subtitulo = "Análisis detallado de satisfacción por indicadores";

// ==========================================
// LÓGICA DE PROCESAMIENTO (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    // 1. Crear nueva encuesta, con preguntas base para que pueda responderse desde el primer momento
    if ($_POST['accion'] === 'crear') {
        $titulo = trim($_POST['titulo'] ?? '');
        if ($titulo === '') { header("Location: encuestas.php?err=datos_invalidos"); exit; }

        // Token único y difícil de adivinar (el enlace de la encuesta es público)
        do {
            $token = 'CSAT-' . strtoupper(bin2hex(random_bytes(4)));
            $existe = $db->prepare("SELECT COUNT(*) FROM encuestas WHERE token_url = ?");
            $existe->execute([$token]);
        } while ($existe->fetchColumn());

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO encuestas (titulo, descripcion, token_url, estado) VALUES (?, ?, ?, 'Activo')");
        $stmt->execute([$titulo, trim($_POST['descripcion'] ?? ''), $token]);
        $id_encuesta = $db->lastInsertId();
        $preguntas_base = [
            ['Calidad del servicio recibido', 'fa-star'],
            ['Tiempo de respuesta', 'fa-clock'],
            ['Probabilidad de recomendarnos', 'fa-thumbs-up'],
        ];
        $stmt_p = $db->prepare("INSERT INTO encuestas_preguntas (id_encuesta, pregunta_texto, icono) VALUES (?, ?, ?)");
        foreach ($preguntas_base as [$texto, $icono]) {
            $stmt_p->execute([$id_encuesta, $texto, $icono]);
        }
        $db->commit();
        header("Location: encuestas.php?msg=creado"); exit;
    }
    // 2. Editar encuesta base
    elseif ($_POST['accion'] === 'editar') {
        $stmt = $db->prepare("UPDATE encuestas SET titulo = ?, descripcion = ? WHERE id = ?");
        $stmt->execute([trim($_POST['titulo'] ?? ''), trim($_POST['descripcion'] ?? ''), (int) $_POST['id_encuesta']]);
        header("Location: encuestas.php?msg=editado"); exit;
    }
    // 3. Modificar preguntas: editar las existentes de ESTA encuesta y, si se escribió, agregar una nueva
    elseif ($_POST['accion'] === 'editar_preguntas') {
        $id_encuesta = (int) ($_POST['id_encuesta'] ?? 0);
        $stmt_upd = $db->prepare("UPDATE encuestas_preguntas SET pregunta_texto = ? WHERE id = ? AND id_encuesta = ?");
        foreach ((array) ($_POST['preguntas'] ?? []) as $id_pregunta => $texto) {
            if (trim($texto) !== '') {
                $stmt_upd->execute([trim($texto), (int) $id_pregunta, $id_encuesta]);
            }
        }
        $nueva = trim($_POST['pregunta_nueva'] ?? '');
        if ($nueva !== '' && $id_encuesta > 0) {
            $db->prepare("INSERT INTO encuestas_preguntas (id_encuesta, pregunta_texto, icono) VALUES (?, ?, 'fa-question-circle')")->execute([$id_encuesta, $nueva]);
        }
        header("Location: encuestas.php?msg=preguntas_editadas"); exit;
    }
}

// ==========================================
// OBTENCIÓN DE DATOS
// ==========================================

// Listado de Encuestas
$encuestas = $db->query("SELECT e.*, 
    (SELECT COUNT(id) FROM encuestas_respuestas WHERE id_encuesta = e.id) as total_respuestas,
    (SELECT AVG(calificacion) FROM encuestas_respuestas WHERE id_encuesta = e.id) as promedio_estrellas
    FROM encuestas e ORDER BY e.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Preguntas agrupadas para los modales
$preguntas_db = $db->query("SELECT * FROM encuestas_preguntas ORDER BY id_encuesta, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$preguntas_agrupadas = [];
foreach($preguntas_db as $p) {
    $preguntas_agrupadas[$p['id_encuesta']][] = $p;
}

// Feedback Reciente (Análisis)
$ultimas_respuestas = $db->query("SELECT r.*, e.titulo as nombre_encuesta 
    FROM encuestas_respuestas r 
    JOIN encuestas e ON r.id_encuesta = e.id 
    ORDER BY r.fecha_respuesta DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Centro de Encuestas | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .nav-pills .nav-link { color: #64748b; border-radius: 50px; padding: 10px 25px; font-weight: bold; margin-right: 10px; transition: 0.3s; }
        .nav-pills .nav-link.active { background-color: #0A2E4F; color: white; box-shadow: 0 4px 10px rgba(10, 46, 79, 0.3); }
        .input-group-text { background-color: #ffffff; border: none; padding-left: 20px; color: #0A2E4F; }
        .form-control:focus { box-shadow: none; border-color: transparent; }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        
        <div class="container-fluid py-4">
            <?php
            $avisos_encuestas = [
                'creado'             => ['success', 'fa-check-circle', 'Encuesta creada con tres preguntas base. Puedes ajustarlas en "Modificar Preguntas".'],
                'editado'            => ['success', 'fa-check-circle', 'Encuesta actualizada correctamente.'],
                'preguntas_editadas' => ['success', 'fa-check-circle', 'Preguntas actualizadas correctamente.'],
            ];
            $aviso = $avisos_encuestas[$_GET['msg'] ?? ''] ?? (($_GET['err'] ?? '') === 'datos_invalidos' ? ['danger', 'fa-exclamation-triangle', 'El título de la encuesta es obligatorio.'] : null);
            ?>
            <?php if ($aviso): ?>
                <div class="alert alert-<?= $aviso[0] ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mt-2 mb-0">
                    <i class="fas <?= $aviso[1] ?> me-2"></i> <?= $aviso[2] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <div class="toolbar-pro px-0 mb-4 mt-2">
                <div class="search-container-pro shadow-sm flex-grow-1">
                    <i class="fas fa-search"></i>
                    <input type="text" id="buscadorEncuestas" placeholder="Buscar encuestas por título o rendimiento...">
                </div>
                <button class="btn btn-dark rounded-pill fw-bold px-4 shadow-sm" style="background-color: #0A2E4F;" data-bs-toggle="modal" data-bs-target="#modalEncuesta" onclick="prepararModalNuevo()">
                    <i class="fas fa-plus-circle me-2"></i> NUEVA ENCUESTA
                </button>
            </div>

            <ul class="nav nav-pills mb-4" id="pills-tab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-gestion" type="button">
                        <i class="fas fa-folder-open me-2"></i> Gestión de Formularios
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-analisis" type="button">
                        <i class="fas fa-chart-line me-2"></i> Análisis de Respuestas
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                
                <div class="tab-pane fade show active" id="tab-gestion">
                    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small fw-bold text-muted">
                                <tr>
                                    <th class="ps-4">TÍTULO Y RENDIMIENTO DE SATISFACCIÓN</th>
                                    <th class="text-end pe-4">GESTIÓN AVANZADA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($encuestas as $e): 
                                    $link_preview = "../responder_encuesta.php?token=" . $e['token_url'] . "&preview=1";
                                    $preguntas_de_esta = $preguntas_agrupadas[$e['id']] ?? [];
                                ?>
                                <tr>
                                    <td class="ps-4 py-4">
                                        <div class="fw-bold text-dark" style="font-size: 1.1rem;"><?= htmlspecialchars($e['titulo']) ?></div>
                                        <div class="text-muted small mt-1">
                                            <span class="text-warning fw-bold"><i class="fas fa-star me-1"></i><?= number_format($e['promedio_estrellas'] ?? 0, 1) ?> Promedio</span> 
                                            <span class="mx-2">|</span> 
                                            <i class="fas fa-users me-1"></i> <?= $e['total_respuestas'] ?> Respuestas Recibidas
                                        </div>
                                    </td>
                                    <td class="text-end pe-4 py-4">
                                        <button class="btn btn-sm btn-light text-primary rounded-circle shadow-sm mx-1" title="Configurar Textos" onclick="prepararModalEditar(<?= (int) $e['id'] ?>, <?= jsAttr($e['titulo']) ?>, <?= jsAttr(str_replace(["\r","\n"], " ", $e['descripcion'] ?? '')) ?>)"><i class="fas fa-cog"></i></button>

                                        <button class="btn btn-sm btn-light text-warning rounded-circle shadow-sm mx-1" title="Modificar Preguntas" onclick="abrirModalPreguntas(<?= (int) $e['id'] ?>, <?= jsAttr($preguntas_de_esta) ?>)"><i class="fas fa-list-ol"></i></button>

                                        <a href="<?= esc($link_preview) ?>" target="_blank" class="btn btn-sm btn-light text-success rounded-circle shadow-sm ms-1" title="Simulador Interactivo">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-analisis">
                    <div class="row">
                        <?php if(empty($ultimas_respuestas)): ?>
                            <div class="col-12 text-center py-5 text-muted"><h4>Aún no hay respuestas para analizar</h4></div>
                        <?php endif; ?>

                        <?php foreach($ultimas_respuestas as $r): ?>
                        <div class="col-xl-4 col-lg-6 mb-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                                <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0 d-flex justify-content-between">
                                    <h6 class="fw-bold text-primary mb-0"><i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($r['cliente_nombre']) ?></h6>
                                    <span class="text-muted small"><?= $r['fecha_respuesta'] ? date('d/m/y', strtotime($r['fecha_respuesta'])) : 'Sin fecha' ?></span>
                                </div>
                                <div class="card-body px-4 py-3">
                                    <div class="mb-3">
                                        <span class="badge bg-light text-dark border w-100 py-2 text-truncate"><?= htmlspecialchars($r['nombre_encuesta']) ?></span>
                                    </div>
                                    
                                    <div class="text-center mb-3">
                                        <div class="text-warning fs-4">
                                            <?php for($i=1;$i<=5;$i++) echo $i<=$r['calificacion']?'<i class="fas fa-star"></i>':'<i class="far fa-star"></i>'; ?>
                                        </div>
                                        <small class="text-muted fw-bold">Promedio: <?= esc($r['calificacion']) ?> / 5</small>
                                    </div>

                                    <?php if($r['comentarios']): ?>
                                        <div class="p-2 bg-light rounded-3 mb-3 small fst-italic border-start border-primary border-3">
                                            "<?= htmlspecialchars($r['comentarios']) ?>"
                                        </div>
                                    <?php endif; ?>
                                    
                                    <button class="btn btn-outline-primary btn-sm w-100 rounded-pill fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#det-<?= $r['id'] ?>">
                                        DETALLE POR INDICADOR <i class="fas fa-chevron-down ms-1"></i>
                                    </button>
                                    
                                    <div class="collapse mt-3" id="det-<?= $r['id'] ?>">
                                        <div class="p-3 bg-light rounded-4 border small">
                                            <?php 
                                            // CLAVE: DISTINCT y filtro estricto por id_respuesta_maestra
                                            $det = $db->prepare("SELECT DISTINCT p.pregunta_texto, d.valor_calificacion, p.icono 
                                                                FROM encuestas_resultados_detalle d 
                                                                JOIN encuestas_preguntas p ON d.id_pregunta = p.id 
                                                                WHERE d.id_respuesta_maestra = ? 
                                                                ORDER BY p.id ASC");
                                            $det->execute([$r['id']]);
                                            while($item = $det->fetch(PDO::FETCH_ASSOC)): ?>
                                                <div class="d-flex justify-content-between border-bottom border-white py-2 align-items-center">
                                                    <span class="text-muted"><i class="fas <?= esc($item['icono']) ?> text-secondary me-2"></i><?= esc($item['pregunta_texto']) ?></span>
                                                    <span class="fw-bold text-dark"><?= esc($item['valor_calificacion']) ?> <i class="fas fa-star text-warning small"></i></span>
                                                </div>
                                            <?php endwhile; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalEncuesta" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-0" style="background: linear-gradient(135deg, #0A2E4F 0%, #175d9e 100%); color: white; border-radius: 1rem 1rem 0 0;">
                <h5 class="fw-bold mb-0"><i class="fas fa-cogs me-2"></i> Configuración de Encuesta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="accion" id="formAccion" value="crear">
                <input type="hidden" name="id_encuesta" id="formId">
                <div class="modal-body p-4 bg-light">
                    <div class="mb-4">
                        <label class="small fw-bold text-secondary mb-2 text-uppercase">Título de la Encuesta</label>
                        <div class="input-group shadow-sm rounded-3 overflow-hidden border border-light">
                            <span class="input-group-text"><i class="fas fa-heading"></i></span>
                            <input type="text" name="titulo" id="formTitulo" class="form-control border-0 py-2" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold text-secondary mb-2 text-uppercase">Mensaje de Bienvenida</label>
                        <div class="input-group shadow-sm rounded-3 overflow-hidden border border-light">
                            <span class="input-group-text align-items-start pt-3"><i class="fas fa-comment-dots"></i></span>
                            <textarea name="descripcion" id="formDesc" class="form-control border-0 py-2" rows="4" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light p-4 pt-0">
                    <button type="submit" class="btn w-100 rounded-pill fw-bold text-white shadow-sm py-3" style="background:#0A2E4F;">GUARDAR CAMBIOS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPreguntas" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-0 bg-warning" style="border-radius: 1rem 1rem 0 0;">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-list-ol me-2"></i> Modificar Preguntas del Formulario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="accion" value="editar_preguntas">
                <input type="hidden" name="id_encuesta" id="preguntasIdEncuesta">
                <div class="modal-body p-4 bg-light">
                    <div id="contenedorPreguntas"></div>
                    <div class="border-top pt-4">
                        <label class="small fw-bold text-success mb-2 text-uppercase"><i class="fas fa-plus-circle me-1"></i> Agregar nueva pregunta (opcional)</label>
                        <div class="input-group shadow-sm rounded-3 overflow-hidden border border-white">
                            <span class="input-group-text bg-white border-0 text-success"><i class="fas fa-question-circle"></i></span>
                            <input type="text" name="pregunta_nueva" id="preguntaNueva" maxlength="255" class="form-control border-0 py-3 bg-white" placeholder="Ej: Claridad de la información entregada">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light p-4 pt-0">
                    <button type="submit" class="btn btn-warning w-100 rounded-pill fw-bold text-dark shadow-sm py-3">ACTUALIZAR PREGUNTAS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Escapa texto antes de insertarlo como HTML
    function escHtml(txt) {
        return String(txt ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
    }

    function prepararModalNuevo() { 
        document.getElementById('formAccion').value = 'crear'; 
        document.getElementById('formId').value = ''; 
        document.getElementById('formTitulo').value = ''; 
        document.getElementById('formDesc').value = ''; 
    }
    function prepararModalEditar(id, tit, desc) {
        document.getElementById('formAccion').value = 'editar'; 
        document.getElementById('formId').value = id;
        document.getElementById('formTitulo').value = tit; 
        document.getElementById('formDesc').value = desc; 
        new bootstrap.Modal(document.getElementById('modalEncuesta')).show();
    }
    function abrirModalPreguntas(idEncuesta, arrayPreguntas) {
        const contenedor = document.getElementById('contenedorPreguntas');
        document.getElementById('preguntasIdEncuesta').value = idEncuesta;
        document.getElementById('preguntaNueva').value = '';
        contenedor.innerHTML = '<p class="text-muted small mb-4">Edita el texto de los indicadores que el cliente calificará.</p>';
        if(arrayPreguntas.length === 0) {
            contenedor.innerHTML += '<div class="alert alert-warning">Esta encuesta aún no tiene preguntas: agrega la primera abajo.</div>';
        } else {
            arrayPreguntas.forEach((p, index) => {
                contenedor.innerHTML += `
                <div class="mb-4">
                    <label class="small fw-bold text-dark mb-2 text-uppercase">Pregunta ${index + 1}</label>
                    <div class="input-group shadow-sm rounded-3 overflow-hidden border border-white">
                        <span class="input-group-text bg-white border-0" style="color: #f59e0b;"><i class="fas ${escHtml(p.icono)}"></i></span>
                        <input type="text" name="preguntas[${escHtml(p.id)}]" value="${escHtml(p.pregunta_texto)}" class="form-control border-0 py-3 bg-white" required>
                    </div>
                </div>`;
            });
        }
        new bootstrap.Modal(document.getElementById('modalPreguntas')).show();
    }
        // --- LÓGICA DE BÚSQUEDA EN ENCUESTAS ---
    document.getElementById('buscadorEncuestas').addEventListener('input', function() {
        let filtro = this.value.toLowerCase().trim();
        // Filtramos las filas de la tabla de Gestión
        let filas = document.querySelectorAll('#tab-gestion table tbody tr');
        
        filas.forEach(fila => {
            let contenido = fila.innerText.toLowerCase();
            fila.style.display = contenido.includes(filtro) ? '' : 'none';
        });
    });
</script>
</body>
</html>