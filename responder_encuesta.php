<?php
/**
 * DATASENSE ANALYTICS - ENCUESTA DE SATISFACCIÓN (PÚBLICA)
 * No requiere login de administrador.
 */
require_once __DIR__ . '/includes/seguridad.php';
iniciarSesionSegura();

$db = require __DIR__ . '/config/database.php';

// Token del formulario: solo se aceptan envíos hechos desde esta página
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Texto libre que llega por la URL del correo: solo cadenas y con longitud acotada
$textoEnlace = function ($valor, $maximo, $defecto) {
    $texto = is_string($valor) ? trim(mb_substr($valor, 0, $maximo)) : '';
    return $texto !== '' ? $texto : $defecto;
};

// Leer los datos que vienen por la URL del correo
$token_url = $_GET['token'] ?? $_POST['token_encuesta'] ?? null;
$token_url = is_string($token_url) ? $token_url : null;
$ticket_info = $textoEnlace($_GET['ticket'] ?? $_POST['ticket_relacionado'] ?? null, 60, 'Soporte General');
$cliente_info = $textoEnlace($_GET['cliente'] ?? $_POST['cliente_nombre'] ?? null, 120, 'Cliente DataSense');
$es_vista_previa = !empty($_GET['preview']) || !empty($_POST['preview']);

$encuesta = null;
$preguntas = [];
$estado_respuesta = "";

if ($token_url) {
    // Buscar la encuesta en la base de datos
    $stmt = $db->prepare("SELECT * FROM encuestas WHERE token_url = ? AND estado = 'Activo'");
    $stmt->execute([$token_url]);
    $encuesta = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($encuesta) {
        // Traer las preguntas de esta encuesta
        $stmt_p = $db->prepare("SELECT id, pregunta_texto, icono FROM encuestas_preguntas WHERE id_encuesta = ? ORDER BY id ASC");
        $stmt_p->execute([$encuesta['id']]);
        $preguntas = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    }
}

// PROCESAR GUARDADO (la encuesta se identifica por el token; nunca por un id enviado en el formulario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $encuesta) {
    $clave_envio = $encuesta['id'] . '|' . $ticket_info . '|' . $cliente_info;
    $comentarios = is_string($_POST['comentarios'] ?? null) ? trim(mb_substr($_POST['comentarios'], 0, 2000)) : '';
    $respuestas_raw = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];

    // Cada pregunta de ESTA encuesta debe tener una calificación entera de 1 a 5
    $respuestas = [];
    foreach ($preguntas as $pregunta) {
        $valor = filter_var($respuestas_raw[$pregunta['id']] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        if ($valor !== false) {
            $respuestas[(int) $pregunta['id']] = $valor;
        }
    }

    $token_valido = is_string($_POST['csrf_token'] ?? null) && hash_equals($_SESSION['token'], $_POST['csrf_token']);

    if (!$token_valido) {
        $estado_respuesta = "invalida";
    } elseif ($es_vista_previa) {
        $estado_respuesta = "vista_previa"; // El simulador del panel no guarda respuestas
    } elseif (in_array($clave_envio, $_SESSION['encuestas_respondidas'] ?? [], true)) {
        $estado_respuesta = "duplicada";
    } elseif (!$preguntas || count($respuestas) !== count($preguntas)) {
        $estado_respuesta = "incompleta";
    } elseif (registrarIntento('encuesta|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 3600) > 20) {
        $estado_respuesta = "limite";
    } else {
        $promedio = round(array_sum($respuestas) / count($respuestas), 1);
        $nombre_trazabilidad = $cliente_info . " | " . $ticket_info;

        $ins_m = $db->prepare("INSERT INTO encuestas_respuestas (id_encuesta, cliente_nombre, calificacion, comentarios, fecha_respuesta) VALUES (?, ?, ?, ?, NOW())");
        $ins_m->execute([$encuesta['id'], $nombre_trazabilidad, $promedio, $comentarios]);
        $id_maestro = $db->lastInsertId();

        $ins_d = $db->prepare("INSERT INTO encuestas_resultados_detalle (id_respuesta_maestra, id_pregunta, valor_calificacion) VALUES (?, ?, ?)");
        foreach ($respuestas as $id_preg => $valor) {
            $ins_d->execute([$id_maestro, $id_preg, $valor]);
        }

        $_SESSION['encuestas_respondidas'][] = $clave_envio;
        $estado_respuesta = "exito";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluación de Servicio | DataSense Analytics</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        body { 
            background-color: #f1f5f9; 
            font-family: 'Inter', sans-serif; 
            padding-top: 40px; 
            padding-bottom: 60px; 
        }
        .survey-container { 
            max-width: 600px; 
            margin: auto; 
            background: #ffffff; 
            border-radius: 16px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.05); 
            overflow: hidden; 
        }
        .survey-header { 
            background: #ffffff; 
            padding: 40px 30px 20px; 
            text-align: center; 
            border-bottom: 1px solid #f1f5f9;
        }
        .survey-header img { 
            max-width: 180px; 
            margin-bottom: 20px; 
        }
        .survey-header h3 { 
            font-weight: 800; 
            color: #0f172a;
            font-size: 1.5rem;
            margin-bottom: 25px;
        }
        
        /* Etiqueta Verde de Bienvenida */
        .welcome-badge { 
            background-color: #ecfdf5; 
            color: #10b981; 
            display: inline-flex; 
            align-items: center;
            padding: 8px 20px; 
            border-radius: 50px; 
            font-size: 0.85rem; 
            font-weight: 600;
            border: 1px solid #d1fae5;
            margin-bottom: 20px;
        }
        
        .welcome-text {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 30px;
            padding: 0 15px;
            text-align: center;
        }

        .question-card { 
            background: #f8fafc; 
            border-radius: 12px; 
            padding: 25px; 
            margin-bottom: 20px; 
            text-align: center; 
            transition: all 0.3s ease; 
        }
        .question-card.error { 
            border: 2px solid #ef4444; 
            background: #fef2f2; 
        }
        
        .question-title { 
            font-weight: 700; 
            color: #1e293b; 
            margin-bottom: 20px; 
            font-size: 1rem; 
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .question-title i {
            background: #0f172a;
            color: white;
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
            font-size: 0.85rem;
        }
        
        .stars-container { display: flex; flex-direction: row-reverse; justify-content: center; gap: 12px; }
        .stars-container input { display: none; }
        .stars-container label { font-size: 38px; color: #cbd5e1; cursor: pointer; transition: 0.2s; }
        .stars-container label:hover,
        .stars-container label:hover ~ label,
        .stars-container input:checked ~ label { color: #cbd5e1; } /* Color por defecto */
        .stars-container input:checked ~ label { color: #facc15; } /* Amarillo brillante al seleccionar */

        textarea.form-control { 
            border-radius: 12px; 
            resize: none; 
            border: 1px solid #e2e8f0; 
            background-color: #ffffff;
            font-size: 0.95rem;
        }
        textarea.form-control:focus { box-shadow: none; border-color: #94a3b8; }
        
        .form-label-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            display: block;
        }

        .btn-submit { 
            background-color: #0f172a; 
            color: white; 
            font-weight: 700; 
            border-radius: 12px; 
            padding: 16px; 
            width: 100%; 
            font-size: 1.05rem; 
            transition: 0.3s; 
        }
        .btn-submit:hover { 
            background-color: #1e293b; 
            transform: translateY(-2px); 
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.2); 
            color: white;
        }
    </style>
</head>
<body>

<div class="survey-container">

    <?php
    // Pantallas finales: respuesta guardada, vista previa del panel o evaluación ya enviada
    $pantallas_finales = [
        'exito'        => ['fa-check-circle', '#10b981', '¡Muchas Gracias!', 'Tus respuestas han sido enviadas correctamente. Tu opinión nos ayuda a ser mejores.'],
        'vista_previa' => ['fa-eye', '#0d6efd', 'Vista previa', 'Este es el simulador del panel: las respuestas no se guardaron.'],
        'duplicada'    => ['fa-info-circle', '#f59e0b', 'Evaluación ya registrada', 'Ya recibimos tu evaluación para este caso. ¡Gracias por tu tiempo!'],
    ];
    ?>
    <?php if (isset($pantallas_finales[$estado_respuesta])): [$icono_final, $color_final, $titulo_final, $texto_final] = $pantallas_finales[$estado_respuesta]; ?>
        <div class="p-5 text-center">
            <i class="fas <?= $icono_final ?> mb-4" style="font-size: 90px; color: <?= $color_final ?>;"></i>
            <h2 class="fw-bold text-dark mb-3"><?= $titulo_final ?></h2>
            <p class="text-muted fs-5 mb-0"><?= $texto_final ?></p>
        </div>

    <?php elseif (!$encuesta): ?>
        <div class="p-5 text-center">
            <i class="fas fa-file-excel mb-4 text-muted" style="font-size: 70px;"></i>
            <h3 class="fw-bold text-dark">Encuesta no disponible</h3>
            <p class="text-muted mb-0">El enlace proporcionado es incorrecto o la encuesta ha expirado.</p>
        </div>

    <?php else: ?>
        
        <div class="survey-header">
            <img src="img/logo.png" alt="DataSense Analytics" onerror="this.style.display='none'">
            <h3><?= htmlspecialchars($encuesta['titulo']) ?></h3>
            
            <div class="welcome-badge">
                <i class="fas fa-user-alt me-2"></i> Bienvenido, <?= htmlspecialchars($cliente_info) ?>
            </div>
            
            <p class="welcome-text">
                <?= htmlspecialchars($encuesta['descripcion']) ?> <br>
                <strong>Caso Evaluado:</strong> <span class="text-primary fw-bold"><?= htmlspecialchars($ticket_info) ?></span>
            </p>
        </div>

        <div class="p-4 p-md-5 pt-0">
            <?php
            $avisos_envio = [
                'invalida'   => 'El formulario expiró. Recarga la página e inténtalo de nuevo.',
                'incompleta' => 'Por favor califica todas las preguntas con 1 a 5 estrellas.',
                'limite'     => 'Recibimos demasiadas evaluaciones desde tu conexión. Intenta de nuevo más tarde.',
            ];
            ?>
            <?php if (isset($avisos_envio[$estado_respuesta])): ?>
                <div class="alert alert-warning rounded-3 small"><i class="fas fa-exclamation-triangle me-2"></i><?= $avisos_envio[$estado_respuesta] ?></div>
            <?php endif; ?>

            <form id="formEncuesta" action="responder_encuesta.php" method="POST">
                
                <input type="hidden" name="id_encuesta" value="<?= $encuesta['id'] ?>">
                <input type="hidden" name="token_encuesta" value="<?= htmlspecialchars($token_url) ?>">
                <input type="hidden" name="ticket_relacionado" value="<?= htmlspecialchars($ticket_info) ?>">
                <input type="hidden" name="cliente_nombre" value="<?= htmlspecialchars($cliente_info) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
                <?php if ($es_vista_previa): ?><input type="hidden" name="preview" value="1"><?php endif; ?>

                <?php foreach($preguntas as $p): ?>
                <div class="question-card" id="q-box-<?= $p['id'] ?>">
                    <div class="question-title">
                        <i class="fas <?= htmlspecialchars($p['icono'] ?? '') ?> me-3"></i> <?= htmlspecialchars($p['pregunta_texto']) ?>
                    </div>
                    
                    <div class="stars-container">
                        <?php for($star=5; $star>=1; $star--): ?>
                            <input type="radio" id="p<?= $p['id'] ?>_<?= $star ?>" name="p[<?= $p['id'] ?>]" value="<?= $star ?>" onclick="quitarError(<?= $p['id'] ?>)">
                            <label for="p<?= $p['id'] ?>_<?= $star ?>"><i class="fas fa-star"></i></label>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="mb-4 mt-4 pt-2">
                    <label class="form-label-title">¿ALGÚN COMENTARIO ADICIONAL?</label>
                    <textarea name="comentarios" class="form-control p-3 shadow-sm" rows="4" placeholder="Tu opinión nos ayuda a mejorar..."></textarea>
                </div>

                <button type="submit" class="btn btn-submit border-0 mt-2" id="btnEnviar">
                    <i class="fas fa-paper-plane me-2"></i> ENVIAR EVALUACIÓN DE SERVICIO
                </button>

            </form>
        </div>

    <?php endif; ?>

</div>

<script>
function quitarError(id) {
    document.getElementById('q-box-' + id).classList.remove('error');
}

document.getElementById('formEncuesta')?.addEventListener('submit', function(e) {
    const cards = document.querySelectorAll('.question-card');
    let hayError = false;
    let primerError = null;

    cards.forEach(card => {
        const radios = card.querySelectorAll('input[type="radio"]');
        const respondido = Array.from(radios).some(r => r.checked);
        
        if (!respondido) {
            hayError = true;
            card.classList.add('error');
            if(!primerError) primerError = card;
        }
    });

    if (hayError) {
        e.preventDefault();
        // Hace scroll suave hasta la pregunta que le faltó responder
        const offset = primerError.getBoundingClientRect().top + window.scrollY - 30;
        window.scrollTo({ top: offset, behavior: 'smooth' });
    } else {
        const btn = document.getElementById('btnEnviar');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> ENVIANDO RESPUESTAS...';
        btn.style.opacity = '0.7';
        btn.style.pointerEvents = 'none';
    }
});
</script>

</body>
</html>