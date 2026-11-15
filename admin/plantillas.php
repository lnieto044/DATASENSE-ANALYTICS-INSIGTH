<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. CARGA DE DEPENDENCIAS Y SEGURIDAD
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'plantillas');
require_once __DIR__ . '/../includes/utilidades.php';

$status_res = "";
$error_detalle = "";

// 2. CARGAR PERFIL DEL ADMINISTRADOR
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = "<i class='fas fa-magic text-primary me-2'></i> Centro de Comunicaciones";
$subtitulo = "Gestión centralizada de prospectos y solicitudes de análisis";

// --- CONSULTAS DE DATOS ---

// Contactos (Traemos servicio y mensaje para la inteligencia de la vista)
$stmt_mensajes = $db->query("SELECT id, id_usuario_final, nombre, correo, servicio, mensaje FROM contactos ORDER BY nombre ASC");
$mensajes_pendientes = $stmt_mensajes->fetchAll(PDO::FETCH_ASSOC);

// Todos los correos para el envío masivo
$stmt_todos = $db->query("SELECT correo FROM contactos WHERE correo IS NOT NULL AND correo != ''");
$todos_los_correos = $stmt_todos->fetchAll(PDO::FETCH_COLUMN);

// Facturas
$stmt_fac = $db->query("SELECT id, id_usuario, numero_factura, servicio_contratado, fecha_emision, fecha_vencimiento, monto_total, estado FROM facturas ORDER BY fecha_emision DESC");
$facturas_agrupadas = [];
while($row = $stmt_fac->fetch(PDO::FETCH_ASSOC)) {
    $facturas_agrupadas[$row['id_usuario']][] = $row;
}
$json_facturas = json_encode($facturas_agrupadas);

// Catálogo de Servicios (Traemos codigo_servicio para agrupar inteligentemente)
$stmt_serv = $db->query("SELECT id, codigo_servicio, nombre as nombre_servicio, descripcion as descripcion_estandar, precio_base, impuesto_porcentaje FROM servicios_catalogo WHERE estado = 'Activo' ORDER BY codigo_servicio ASC");
$catalogo_servicios = $stmt_serv->fetchAll(PDO::FETCH_ASSOC);
$json_servicios = json_encode($catalogo_servicios);

// Encuestas
$stmt_enc = $db->query("SELECT titulo as clave, token_url as valor FROM encuestas WHERE estado = 'Activo'");
$encuestas_disponibles = $stmt_enc->fetchAll(PDO::FETCH_ASSOC);
$dominio_enc = urlBase() . "responder_encuesta.php?token=";
foreach ($encuestas_disponibles as &$enc) { $enc['valor'] = $dominio_enc . urlencode($enc['valor']); }

// Reuniones
$stmt_reuniones = $db->query("SELECT id, titulo, link_reunion, fecha, hora_inicio, estado, evidencia FROM reuniones_virtuales WHERE estado IN ('Programada', 'Notificada', 'Finalizada') ORDER BY fecha DESC");
$reuniones_db = $stmt_reuniones->fetchAll(PDO::FETCH_ASSOC);
$json_reuniones = json_encode($reuniones_db);

// Plantillas
$stmt_plantillas = $db->query("SELECT * FROM plantillas_correo ORDER BY titulo ASC");
$plantillas = $stmt_plantillas->fetchAll(PDO::FETCH_ASSOC);

// 3. PROCESAMIENTO DE ENVÍO (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo_cliente = $_POST['correo_cliente']; // Recibe correo o "MASIVO"
    $nombre_cliente = trim($_POST['nombre_cliente']);
    $asunto = trim($_POST['asunto']);
    $cuerpo_procesado = trim($_POST['cuerpo']); 
    
    $id_contacto = $_POST['id_contacto'] ?? '';
    $id_factura_pdf = $_POST['id_factura_hidden'] ?? '';
    $id_reunion_hidden = $_POST['id_reunion_hidden'] ?? '';
    $mostrar_boton_pdf = $_POST['mostrar_boton_pdf_hidden'] ?? '0'; 
    $mostrar_boton_acta = $_POST['mostrar_boton_acta_hidden'] ?? '0'; 

    try {
        $mail = nuevoCorreo('DataSense Analytics');

        // LÓGICA DE DESTINATARIOS
        if ($correo_cliente === "MASIVO") {
            $mail->addAddress($_ENV['MAIL_USER'], 'Comunicado General DataSense'); 
            if (!empty($todos_los_correos)) {
                foreach ($todos_los_correos as $bcc_email) {
                    if (filter_var($bcc_email, FILTER_VALIDATE_EMAIL)) {
                        $mail->addBCC($bcc_email);
                    }
                }
            } else {
                throw new Exception("La lista de correos para envío masivo está vacía.");
            }
        } else {
            $mail->addAddress($correo_cliente, $nombre_cliente);
        }
        
        if(file_exists(__DIR__ . '/../img/logo.png')){
            $mail->addEmbeddedImage(__DIR__ . '/../img/logo.png', 'logo_datasense');
        }

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        
        $html_adicional = "";

        // Botón Reunión
        $regex_reunion = '/https?:\/\/(meet\.jit\.si|meet\.google\.com|teams\.microsoft\.com|us0[0-9]web\.zoom\.us)[^\s<"]+/i';
        if (preg_match($regex_reunion, $cuerpo_procesado, $matches) && $mostrar_boton_acta === '0') {
            $url_reunion = $matches[0];
            $cuerpo_procesado = str_replace($url_reunion, "<strong>(Enlace de acceso seguro en el botón inferior)</strong>", $cuerpo_procesado);
            $html_adicional .= '
            <div style="text-align:center; margin:35px 0;">
                <a href="'.$url_reunion.'" target="_blank" style="background-color:#0d6efd; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px; box-shadow:0 5px 15px rgba(13,110,253,0.3);">
                    🚀 CONECTAR A LA REUNIÓN
                </a>
                <p style="color:#64748b; font-size:11px; margin-top:10px;">Conexión encriptada. Por favor conectarse puntual.</p>
            </div>';
        }

        // Botón Encuesta
        if (strpos($cuerpo_procesado, 'responder_encuesta.php') !== false) {
            $regex_e = '/https?:\/\/[^\s<"]+token=[^\s&<"]+(&cid=\d+)?/';
            if (preg_match($regex_e, $cuerpo_procesado, $matches)) {
                $url_e = $matches[0];
                $cuerpo_procesado = str_replace($url_e, "<em>(Acceda mediante el botón inferior)</em>", $cuerpo_procesado);
                $html_adicional .= '
                <div style="text-align:center; margin:35px 0;">
                    <a href="'.$url_e.'" target="_blank" style="background-color:#0A2E4F; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px; box-shadow:0 5px 15px rgba(10,46,79,0.25);">
                        ⭐ CALIFICAR SERVICIO
                    </a>
                </div>';
            }
        }

        // Botón Factura
        if(!empty($id_factura_pdf) && $mostrar_boton_pdf === '1'){
            $url_p = urlBase() . "admin/generar_pdf.php?id=" . (int) $id_factura_pdf;
            $html_adicional .= '
            <div style="text-align:center; margin:35px 0;">
                <a href="'.$url_p.'" target="_blank" style="background-color:#3FAE49; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px; box-shadow:0 5px 15px rgba(63,174,73,0.3);">
                    📥 DESCARGAR FACTURA
                </a>
            </div>';
        }

        // Botón Acta
        if(!empty($id_reunion_hidden) && $mostrar_boton_acta === '1'){
            $url_acta = urlBase() . "admin/generar_acta.php?id=" . (int) $id_reunion_hidden;
            $html_adicional .= '
            <div style="text-align:center; margin:35px 0;">
                <a href="'.$url_acta.'" target="_blank" style="background-color:#0d6efd; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px; box-shadow:0 5px 15px rgba(13,110,253,0.3);">
                    📄 DESCARGAR ACTA FIRMADA
                </a>
                <p style="color:#64748b; font-size:11px; margin-top:10px;">Documento legal en formato PDF.</p>
            </div>';
        }

        $mail->Body = '
        <html>
        <body style="background-color:#f8fafc; margin:0; padding:40px 0;">
            <table width="600" align="center" style="background-color:#ffffff; border-radius:20px; border-collapse:separate; border:1px solid #e2e8f0; box-shadow:0 10px 25px rgba(0,0,0,0.05);">
                <tr><td align="center" style="padding:35px; border-bottom:1px solid #f1f5f9;"><img src="cid:logo_datasense" width="200" alt="DataSense"></td></tr>
                <tr>
                    <td style="padding:45px; font-family:Arial,sans-serif; color:#334155; line-height:1.6;">
                        <p style="font-size:18px; color:#0A2E4F; font-weight:bold; margin-top:0;">Hola, ' . htmlspecialchars($nombre_cliente) . '</p>
                        <div style="font-size:15px;">' . nl2br(htmlspecialchars($cuerpo_procesado)) . '</div>
                        ' . $html_adicional . '
                        <hr style="border:0; border-top:1px solid #f1f5f9; margin:30px 0;">
                        <p style="margin:0; font-weight:bold; color:#0A2E4F; font-size:14px;">Equipo DataSense Analytics</p>
                        <p style="margin:0; font-size:12px; color:#64748b;">Analítica · Ciencia de datos · BI</p>
                    </td>
                </tr>
            </table>
        </body>
        </html>';

        $mail->send();

        if (!empty($id_contacto) && $correo_cliente !== "MASIVO") {
            $db->prepare("UPDATE contactos SET estado = 'Contactado' WHERE id = ?")->execute([$id_contacto]);
        }
        if (!empty($id_reunion_hidden) && $mostrar_boton_acta === '0' && $correo_cliente !== "MASIVO") {
            $db->prepare("UPDATE reuniones_virtuales SET estado = 'Notificada' WHERE id = ? AND estado = 'Programada'")->execute([$id_reunion_hidden]);
        }

        $status_res = "success";
    } catch (Exception $e) { 
        $status_res = "error"; 
        $error_detalle = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Centro de Comunicaciones | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="container-fluid py-4">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                        <form action="" method="POST" id="formPlantilla">
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-muted"><i class="fas fa-user me-2 text-primary"></i>1. DESTINATARIO</label>
                                <select class="form-select border-0 bg-light p-3 rounded-3 fw-bold" id="selectorCliente" required onchange="actualizarCerebro()">
                                    <option value="">-- Buscar contacto en BD --</option>
                                    <option value="MASIVO" data-nombre="Estimado Cliente" style="background:#0A2E4F; color:white;">
                                        📢 ENVIAR CORREO MASIVO (TODOS)
                                    </option>
                                    <optgroup label="Clientes Activos">
                                        <?php foreach($mensajes_pendientes as $m): ?>
                                            <option value="<?= esc($m['correo']) ?>"
                                                    data-nombre="<?= esc($m['nombre']) ?>"
                                                    data-id="<?= (int) $m['id'] ?>"
                                                    data-id-usuario="<?= esc($m['id_usuario_final'] ?? '') ?>"
                                                    data-mensaje="<?= esc($m['mensaje']) ?>"
                                                    data-interes="<?= esc(nombreServicio($m['servicio'])) ?>">
                                                <?= esc($m['nombre']) ?> (<?= esc(nombreServicio($m['servicio'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold small text-muted"><i class="fas fa-envelope-open-text me-2 text-primary"></i>2. TIPO DE COMUNICACIÓN</label>
                                <select class="form-select border-0 bg-light p-3 rounded-3" id="selectorPlantilla" required onchange="actualizarCerebro()">
                                    <option value="">-- Seleccionar tipo de mensaje --</option>
                                    <?php foreach($plantillas as $p): ?>
                                        <option value="<?= $p['id'] ?>" 
                                                data-asunto="<?= htmlspecialchars($p['asunto'], ENT_QUOTES, 'UTF-8') ?>" 
                                                data-cuerpo="<?= htmlspecialchars($p['cuerpo'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="zonaDinamica" class="mb-4"></div>

                            <input type="hidden" name="id_contacto" id="hidId">
                            <input type="hidden" name="nombre_cliente" id="hidNom">
                            <input type="hidden" name="correo_cliente" id="hidCor">
                            <input type="hidden" name="asunto" id="hidAsu">
                            <input type="hidden" name="cuerpo" id="hidCue">
                            <input type="hidden" name="id_factura_hidden" id="id_factura_hidden">
                            <input type="hidden" name="id_reunion_hidden" id="id_reunion_hidden">
                            <input type="hidden" name="id_servicio_hidden" id="id_servicio_hidden">
                            <input type="hidden" name="mostrar_boton_pdf_hidden" id="hid_mostrar_boton" value="0">
                            <input type="hidden" name="mostrar_boton_acta_hidden" id="hid_mostrar_acta" value="0">

                            <button type="submit" class="btn btn-primary w-100 p-3 rounded-pill fw-bold shadow-lg" id="btnEnviar" disabled style="background:#0A2E4F; border:none;">
                                ENVIAR COMUNICACIÓN PROFESIONAL <i class="fas fa-paper-plane ms-2"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-white overflow-hidden">
                        <div class="p-3 bg-dark text-white text-center small fw-bold"><i class="fas fa-eye me-2 text-info"></i> VISTA PREVIA DE CORREO</div>
                        <div class="p-4" id="boxPreview">
                            <div class="text-center py-5 text-muted opacity-50">Esperando selección...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// 1. CARGA DE DATOS DESDE PHP
const facturasDB = <?= $json_facturas ?: '{}' ?>;
const serviciosDB = <?= $json_servicios ?: '[]' ?>;
const encuestasDB = <?= json_encode($encuestas_disponibles) ?: '[]' ?>;
const reunionesDB = <?= $json_reuniones ?: '[]' ?>;

let cuerpoBase = "";
let esEnvioFactura = false;
let esEnvioActa = false;

// Escapa texto antes de insertarlo como HTML (nombres y mensajes llegan del formulario público)
function escHtml(txt) {
    return String(txt ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
}

// 2. FUNCIÓN PRINCIPAL: ACTUALIZAR CEREBRO
function actualizarCerebro() {
    const selC = document.getElementById('selectorCliente');
    const selP = document.getElementById('selectorPlantilla');
    const zona = document.getElementById('zonaDinamica');
    const btn = document.getElementById('btnEnviar');

    if(!selC.value) { zona.innerHTML = ""; btn.disabled = true; return; }

    const esMasivo = (selC.value === "MASIVO");

    // Filtro de Plantillas Masivo vs Individual
    Array.from(selP.options).forEach(opt => {
        if(!opt.value) return;
        const txt = opt.text.toUpperCase();
        const esMante = txt.includes("MANTENIMIENTO");
        opt.style.display = esMasivo ? (esMante ? "block" : "none") : (esMante ? "none" : "block");
    });

    if(selP.selectedIndex !== -1 && selP.options[selP.selectedIndex].style.display === "none") {
        selP.value = ""; zona.innerHTML = ""; btn.disabled = true;
    }

    if(selC.value && selP.value) {
        const optSeleccionada = selC.options[selC.selectedIndex];
        const idC = optSeleccionada.getAttribute('data-id');
        const idUsuario = optSeleccionada.getAttribute('data-id-usuario'); 
        const interesCliente = (optSeleccionada.getAttribute('data-interes') || '').toUpperCase();
        const mensajeCliente = optSeleccionada.getAttribute('data-mensaje') || '';

        const tituloPlantilla = selP.options[selP.selectedIndex].text.toLowerCase();
        cuerpoBase = selP.options[selP.selectedIndex].getAttribute('data-cuerpo');
        let variables = [...new Set(cuerpoBase.match(/\[[A-Z_0-9]+\]/g) || [])];
        
        esEnvioFactura = (tituloPlantilla.includes('factura') && !tituloPlantilla.includes('recordatorio') && !tituloPlantilla.includes('suspensi') && !tituloPlantilla.includes('pago'));
        document.getElementById('hid_mostrar_boton').value = esEnvioFactura ? '1' : '0';

        esEnvioActa = (tituloPlantilla.includes('minuta') || tituloPlantilla.includes('resumen') || tituloPlantilla.includes('acta'));
        document.getElementById('hid_mostrar_acta').value = esEnvioActa ? '1' : '0';
        
        zona.innerHTML = '<div class="alert alert-info border-0 bg-info-subtle py-2 px-3 small fw-bold text-info mb-3">AUTOMATIZACIÓN DE DATOS:</div>';
        if(esMasivo) zona.innerHTML += '<div class="alert alert-primary border-0 small mb-3"><b>MODO MASIVO ACTIVADO:</b> El correo llegará a todos los clientes.</div>';

        variables.sort((a, b) => {
            let isLinkA = (a.includes("REUNION") || a.includes("MEET") || a.includes("TEAMS") || a.includes("ENLACE") || a.includes("RESUMEN") || a.includes("ACUERDOS"));
            let isLinkB = (b.includes("REUNION") || b.includes("MEET") || b.includes("TEAMS") || b.includes("ENLACE") || b.includes("RESUMEN") || b.includes("ACUERDOS"));
            return (isLinkA && !isLinkB) ? -1 : (!isLinkA && isLinkB ? 1 : 0);
        });

        variables.forEach(v => {
            if(v === "[NOMBRE]") return;
            let label = v.replace(/\[|\]/g, '').replace(/_/g, ' ');
            let cleanId = v.replace(/\[|\]/g, ''); 
            
            if(v.includes("FACTURA") && !v.includes("CONCEPTO") && !v.includes("VALOR")) {
                let facturasDelCliente = idUsuario ? (facturasDB[idUsuario] || []) : [];
                if (facturasDelCliente.length > 0) {
                    let options = '<option value="">-- Seleccionar factura --</option>';
                    facturasDelCliente.forEach(f => { 
                        let origIdx = facturasDB[idUsuario].findIndex(origF => origF.id === f.id);
                        options += `<option value="${escHtml(f.numero_factura)}" data-idx="${origIdx}">${escHtml(f.numero_factura)} - $${new Intl.NumberFormat('es-CO').format(f.monto_total)} (${escHtml(f.estado)})</option>`; 
                    });
                    zona.innerHTML += `<div class="mb-3"><label class="small fw-bold text-secondary mb-1">${label}</label><select class="form-select border-0 bg-light p-3 rounded-3 v-din shadow-sm" data-var="${v}" onchange="autollenadoFactura(${idUsuario}, this)" required>${options}</select></div>`;
                } else {
                    let adv = idUsuario ? 'El usuario no tiene facturas registradas.' : 'Contacto no registrado como facturable.';
                    zona.innerHTML += `<div class="alert alert-warning border-0 small mb-3">⚠️ ${adv}</div>`; btn.disabled = true; return; 
                }

            } else if(v.includes("SERVICIO_A_PRESTAR") || v.includes("NOMBRE_DEL_SERVICIO")) {
                let hBI = ''; let hCD = ''; let hOT = '';
                serviciosDB.forEach((s, i) => { 
                    let cod = (s.codigo_servicio || '').toUpperCase();
                    let optH = `<option value="${escHtml(s.nombre_servicio)}" data-idx="${i}">[${escHtml(cod)}] ${escHtml(s.nombre_servicio)}</option>`;
                    if (cod.startsWith("BI") || cod.startsWith("CS")) hBI += optH;
                    else if (cod.startsWith("AI") || cod.startsWith("DA") || cod.startsWith("AR") || cod.startsWith("DB")) hCD += optH;
                    else hOT += optH;
                });
                let lBI = interesCliente.includes("BUSINESS") ? "⭐ BUSINESS INTELLIGENCE (RECOMENDADO)" : "📊 BUSINESS INTELLIGENCE";
                let lCD = interesCliente.includes("CIENCIA") ? "⭐ CIENCIA DE DATOS E IA (RECOMENDADO)" : "🧠 CIENCIA DE DATOS E IA";
                let lOT = (interesCliente.includes("PÚBLICO") || interesCliente.includes("PUBLICO")) ? "⭐ SECTOR PÚBLICO Y OTROS (RECOMENDADO)" : "📦 SECTOR PÚBLICO Y OTROS";
                let optsF = '<option value="">-- Seleccionar servicio --</option>';
                if (hBI) optsF += `<optgroup label="${lBI}">${hBI}</optgroup>`;
                if (hCD) optsF += `<optgroup label="${lCD}">${hCD}</optgroup>`;
                if (hOT) optsF += `<optgroup label="${lOT}">${hOT}</optgroup>`;
                zona.innerHTML += `<div class="mb-3"><label class="small fw-bold">${label}</label><select class="form-select v-din fw-bold" data-var="${v}" onchange="autollenadoServicio(this)" required>${optsF}</select></div>`;
            
            } else if(v.includes("ENCUESTA")) {
                let options = '<option value="">-- Seleccionar Encuesta --</option>';
                encuestasDB.forEach(e => { options += `<option value="${escHtml(e.valor)}&amp;cid=${escHtml(idC)}">${escHtml(e.clave)}</option>`; });
                zona.innerHTML += `<div class="mb-3"><label class="small fw-bold text-secondary mb-1">${label}</label><select class="form-select border-0 bg-light p-3 rounded-3 v-din shadow-sm" id="in_${cleanId}" data-var="${v}" onchange="dibujarPreview()" required>${options}</select></div>`;
            
            } else if(v.includes("REUNION") || v.includes("MEET") || v.includes("TEAMS") || v.includes("ENLACE_MEET")) {
                let pendientes = reunionesDB.filter(r => r.estado === 'Programada' || r.estado === 'Notificada');
                if (pendientes.length > 0) {
                    let options = '<option value="">-- Seleccionar reunión programada --</option>';
                    pendientes.forEach((r) => { options += `<option value="${r.link_reunion}" data-idx="${reunionesDB.indexOf(r)}">📅 ${r.titulo} (${r.fecha})</option>`; });
                    zona.innerHTML += `<div class="mb-3"><label class="small fw-bold text-secondary mb-1">${label}</label><select class="form-select border-0 bg-info bg-opacity-10 p-3 rounded-3 text-primary fw-bold v-din shadow-sm" id="in_${cleanId}" data-var="${v}" onchange="autollenadoReunion(this)" required>${options}</select></div>`;
                } else {
                    zona.innerHTML += `<div class="alert alert-warning border-0 small mb-3">⚠️ No hay reuniones 'Programadas'.</div>`;
                }

            } else if(v.includes("RESUMEN") || v.includes("MINUTA") || v.includes("ACTA") || v.includes("ACUERDOS")) {
                let finalizadas = reunionesDB.filter(r => r.estado === 'Finalizada');
                let optsActa = '<option value="">-- Buscar acta finalizada para extraer --</option>';
                finalizadas.forEach((r) => { optsActa += `<option value="${r.id}" data-idx="${reunionesDB.indexOf(r)}">✅ ${r.titulo} (${r.fecha})</option>`; });

                zona.innerHTML += `
                <div class="mb-4 p-3 border border-success-subtle rounded-3 bg-success bg-opacity-10 shadow-sm">
                    <label class="small fw-bold text-success mb-2"><i class="fas fa-file-signature me-1"></i> 1. SELECCIONAR SESIÓN FINALIZADA</label>
                    <select class="form-select border-0 shadow-sm mb-3 text-dark fw-bold" onchange="autollenadoActa(this)">${optsActa}</select>
                    <label class="small fw-bold text-secondary mb-1">2. ${label} (Editable)</label>
                    <textarea class="form-control border-0 bg-white p-3 rounded-3 v-din shadow-sm" id="in_${cleanId}" data-var="${v}" rows="6" oninput="dibujarPreview()" required placeholder="El acta se pegará aquí..."></textarea>
                </div>`;

            } else if(v.includes("ESTADO_DEL_PROYECTO")) {
                zona.innerHTML += `<div class="mb-3"><label class="small fw-bold text-secondary mb-1">${label}</label><select class="form-select border-0 bg-light p-3 rounded-3 v-din shadow-sm" id="in_${cleanId}" data-var="${v}" onchange="dibujarPreview()" required><option value="">-- Seleccionar --</option><option value="En Ejecución 🚀">En Ejecución 🚀</option><option value="Finalizado ✅">Finalizado ✅</option></select></div>`;
            
            } else {
                // DISEÑO PROFESIONAL DE INPUTS GENÉRICOS
                let tipoInput = "text"; let icono = "fa-pen"; 
                let atributosExtra = ""; let prefijoDineroHTML = ""; 
                let mostrarIconoEnTitulo = true; let valorPorDefecto = ""; 
                
                if (v.includes("FECHA")) { tipoInput = "date"; icono = "fa-calendar-alt"; }
                if (v.includes("HORA")) { tipoInput = "time"; icono = "fa-clock"; }
                
                if (v.includes("DIAS") || v.includes("TIEMPO") || v.includes("CANTIDAD")) { 
                    tipoInput = "number"; icono = "fa-hashtag"; atributosExtra = 'min="1" step="1"'; 
                }
                if (v.includes("VALOR") || v.includes("PRECIO") || v.includes("INVERSION")) {
                    mostrarIconoEnTitulo = false; prefijoDineroHTML = `<span class="input-group-text bg-light border-0 text-success fw-bold px-3 fs-5">$</span>`;
                }
                if (v.includes("DESCRIPCION") || v.includes("ALCANCE") || v.includes("DETALLE")) icono = "fa-align-left";

                if (v.includes("RETO") || v.includes("NECESIDAD") || v.includes("PROBLEMA")) {
                    icono = "fa-bullseye"; valorPorDefecto = mensajeCliente;
                }

                let campoHTML = '';
                if (v.includes("DESCRIPCION") || v.includes("ALCANCE") || v.includes("DETALLE") || v.includes("RETO")) {
                    campoHTML = `<textarea class="form-control border-0 bg-light p-3 v-din text-secondary" id="in_${cleanId}" data-var="${v}" rows="4" oninput="dibujarPreview()" required placeholder="Escribir ${label.toLowerCase()}...">${escHtml(valorPorDefecto)}</textarea>`;
                } else {
                    if (prefijoDineroHTML !== "") {
                        campoHTML = `<div class="input-group">${prefijoDineroHTML}<input type="${tipoInput}" ${atributosExtra} class="form-control border-0 bg-light p-3 v-din text-secondary" id="in_${cleanId}" data-var="${v}" oninput="dibujarPreview()" required placeholder="Ej: 1500000"></div>`;
                    } else {
                        campoHTML = `<input type="${tipoInput}" ${atributosExtra} class="form-control border-0 bg-light p-3 v-din text-secondary" id="in_${cleanId}" data-var="${v}" oninput="dibujarPreview()" required placeholder="Completar ${label.toLowerCase()}...">`;
                    }
                }

                let htmlIconoFinal = mostrarIconoEnTitulo ? `<i class="fas ${icono} me-1 text-primary" id="icon_${cleanId}"></i> ` : `<i class="" id="icon_${cleanId}"></i> `;
                zona.innerHTML += `<div class="mb-3"><label class="small fw-bold text-secondary mb-1">${htmlIconoFinal}${label}</label><div class="border border-light-subtle rounded-3 shadow-sm bg-white overflow-hidden">${campoHTML}</div></div>`;
            }
        });
        
        if(!zona.innerHTML.includes('alert-warning')) { btn.disabled = false; dibujarPreview(); }
    } else { zona.innerHTML = ""; btn.disabled = true; }
}

// 3. AUTOLLENADOS Y UTILIDADES
function pintarCampoCeleste(el) {
    if(!el) return;
    el.classList.remove('bg-light'); 
    el.style.setProperty('background-color', '#e0f2fe', 'important'); 
    el.style.setProperty('color', '#0A2E4F', 'important'); 
    el.style.fontWeight = 'bold';
}

function autollenadoFactura(idUsuario, select) {
    try {
        const idx = select.options[select.selectedIndex].getAttribute('data-idx');
        if(idx !== null && idx !== "") {
            const f = facturasDB[idUsuario][idx]; 
            let hiddenF = document.getElementById('id_factura_hidden');
            if(hiddenF) hiddenF.value = f.id; 
            const mapa = { "DESCRIPCION_DEL_SERVICIO": f.servicio_contratado, "CONCEPTO_FACTURA": f.servicio_contratado, "FECHA_EMISION": f.fecha_emision, "FECHA_VENCIMIENTO": f.fecha_vencimiento, "TOTAL_A_PAGAR": new Intl.NumberFormat('es-CO').format(f.monto_total), "VALOR_A_PAGAR": new Intl.NumberFormat('es-CO').format(f.monto_total), "VALOR_FACTURA": new Intl.NumberFormat('es-CO').format(f.monto_total), "ESTADO_DEL_PAGO": f.estado };
            for (const [k, v] of Object.entries(mapa)) { let el = document.getElementById("in_" + k); if(el) { el.value = v; pintarCampoCeleste(el); } }
        }
        dibujarPreview();
    } catch(e) {}
}

function autollenadoReunion(select) {
    try {
        const idx = select.options[select.selectedIndex].getAttribute('data-idx');
        if(idx !== null && idx !== "") {
            const r = reunionesDB[idx];
            document.getElementById('id_reunion_hidden').value = r.id; 
            let fechaDB = r.fecha ? r.fecha.toString().substring(0, 10) : ""; 
            let horaDB = r.hora_inicio ? r.hora_inicio.toString().substring(0, 5) : ""; 
            document.querySelectorAll('.v-din').forEach(inp => {
                let varAttr = (inp.getAttribute('data-var') || "").toUpperCase();
                if (varAttr.includes("FECHA")) { inp.value = fechaDB; pintarCampoCeleste(inp); } 
                else if (varAttr.includes("HORA")) { inp.value = horaDB; pintarCampoCeleste(inp); }
            });
        }
        dibujarPreview(); 
    } catch(e) {}
}

function autollenadoActa(select) {
    try {
        const idx = select.options[select.selectedIndex].getAttribute('data-idx');
        if(idx !== null && idx !== "") {
            const r = reunionesDB[idx];
            document.getElementById('id_reunion_hidden').value = r.id; 
            let fechaDB = r.fecha ? r.fecha.toString().substring(0, 10) : ""; 
            let evidenciaDB = r.evidencia ? r.evidencia : "Acta firmada pero sin texto de evidencia registrado."; 
            document.querySelectorAll('.v-din').forEach(inp => {
                let varAttr = (inp.getAttribute('data-var') || "").toUpperCase();
                if (varAttr.includes("FECHA")) { inp.value = fechaDB; pintarCampoCeleste(inp); } 
                else if (varAttr.includes("RESUMEN") || varAttr.includes("MINUTA") || varAttr.includes("ACTA") || varAttr.includes("ACUERDOS")) { inp.value = evidenciaDB; pintarCampoCeleste(inp); }
            });
        }
        dibujarPreview(); 
    } catch(e) {}
}

function autollenadoServicio(select) {
    try {
        const idx = select.options[select.selectedIndex].getAttribute('data-idx');
        if(idx !== null && idx !== "") {
            const s = serviciosDB[idx];
            let hiddenServ = document.getElementById('id_servicio_hidden');
            if(hiddenServ) { hiddenServ.value = s.id; }
            let val = parseFloat(s.precio_base) * (1 + (parseFloat(s.impuesto_porcentaje) / 100));
            let total = new Intl.NumberFormat('es-CO').format(val);
            const mapaServicio = { "PRECIO_ESTANDAR": total, "PRECIO_TOTAL": total, "VALOR_PROPUESTA": total, "DESCRIPCION_DETALLADA": s.descripcion_estandar };
            for (const [k, v] of Object.entries(mapaServicio)) { 
                let el = document.getElementById("in_" + k); 
                if(el) { 
                    el.value = v; 
                    el.setAttribute('readonly', true); 
                    el.classList.remove('bg-light', 'text-secondary');
                    el.style.backgroundColor = '#f8fafc'; 
                    el.style.color = '#334155'; el.style.fontWeight = 'bold'; el.style.cursor = 'not-allowed'; 
                    let iconEl = document.getElementById("icon_" + k);
                    if(iconEl) iconEl.className = "fas fa-lock me-1 text-success";
                } 
            }
        }
        dibujarPreview(); 
    } catch(e) { console.error(e); }
}

// 4. DIBUJAR VISTA PREVIA
function dibujarPreview() {
    const selCliente = document.getElementById('selectorCliente');
    const optC = selCliente.options[selCliente.selectedIndex];
    if(!optC.value) return;

    const nom = optC.getAttribute('data-nombre');
    const selP = document.getElementById('selectorPlantilla');
    const asu = selP.options[selP.selectedIndex].getAttribute('data-asunto');
    
    let temp = cuerpoBase.replace(/\[NOMBRE\]/g, escHtml(nom));
    let raw = cuerpoBase.replace(/\[NOMBRE\]/g, nom);
    let htmlBotonesPreview = "";

    document.querySelectorAll('.v-din').forEach(inp => {
        const v = inp.getAttribute('data-var');
        let val = inp.value || v; 

        if (v.includes("ENCUESTA") && inp.tagName === "SELECT" && inp.value && inp.value !== "") {
            htmlBotonesPreview += `<div style="text-align:center; margin:35px 0;"><a href="#" style="background-color:#0A2E4F; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px;">⭐ CALIFICAR SERVICIO</a></div>`;
            temp = temp.replace(new RegExp(v.replace(/\[/g, '\\[').replace(/\]/g, '\\]'), 'g'), `<em>(Acceda mediante el botón inferior)</em>`);
        } else if ((v.includes("REUNION") || v.includes("MEET") || v.includes("TEAMS") || v.includes("ENLACE_MEET")) && inp.tagName === "SELECT" && inp.value && inp.value !== "" && !esEnvioActa) {
            htmlBotonesPreview += `<div style="text-align:center; margin:35px 0;"><a href="#" style="background-color:#0d6efd; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px;">🚀 CONECTAR A LA REUNIÓN</a><p style="color:#64748b; font-size:11px; margin-top:10px;">Conexión encriptada. Por favor conectarse puntual.</p></div>`;
            temp = temp.replace(new RegExp(v.replace(/\[/g, '\\[').replace(/\]/g, '\\]'), 'g'), `<em>(Enlace de acceso seguro en el botón inferior)</em>`);
        } else {
            const esTextarea = inp.tagName.toLowerCase() === 'textarea';
            if(esTextarea) { val = escHtml(val).replace(/\n/g, '<br>'); }
            if (inp.type === 'date' && val !== v && val.includes('-')) { let partes = val.split('-'); if (partes.length === 3) val = `${partes[2]}/${partes[1]}/${partes[0]}`; }
            if (val !== v && val !== "") { temp = temp.replace(new RegExp(v.replace(/\[/g, '\\[').replace(/\]/g, '\\]'), 'g'), `<b style="color:#0A2E4F; background:#e0f2fe; padding:2px 6px; border-radius:4px;">${esTextarea ? val : escHtml(val)}</b>`); }
        }
        raw = raw.replace(new RegExp(v.replace(/\[/g, '\\[').replace(/\]/g, '\\]'), 'g'), inp.value || v);
    });

    document.getElementById('hidId').value = optC.getAttribute('data-id');
    document.getElementById('hidNom').value = nom;
    document.getElementById('hidCor').value = optC.value;
    document.getElementById('hidAsu').value = asu;
    document.getElementById('hidCue').value = raw;

    if(esEnvioFactura && document.getElementById('id_factura_hidden').value) {
        htmlBotonesPreview += `<div style="text-align:center; margin:35px 0;"><a href="#" style="background-color:#3FAE49; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px;">📥 DESCARGAR FACTURA</a></div>`;
    }
    if(esEnvioActa && document.getElementById('id_reunion_hidden').value) {
        htmlBotonesPreview += `<div style="text-align:center; margin:35px 0;"><a href="#" style="background-color:#0d6efd; border-radius:12px; color:#ffffff; display:inline-block; font-family:Arial, sans-serif; font-size:16px; font-weight:bold; line-height:55px; text-align:center; text-decoration:none; width:290px;">📄 DESCARGAR ACTA FIRMADA</a><p style="color:#64748b; font-size:11px; margin-top:10px;">Documento legal en formato PDF.</p></div>`;
    }

    document.getElementById('boxPreview').innerHTML = `
        <div style="border:1px solid #e2e8f0; border-radius:20px; background:white; overflow:hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); max-width:600px; margin:0 auto;">
            <div style="padding:35px; text-align:center; border-bottom:1px solid #f1f5f9;"><img src="../img/logo.png" width="200" onerror="this.style.display='none'"></div>
            <div style="padding:45px; font-size:15px; line-height:1.6; color:#334155; font-family: 'Segoe UI', Helvetica, Arial, sans-serif;">
                <p style="font-size:18px; color:#0A2E4F; font-weight:bold; margin-top:0;">Hola, ${escHtml(nom)}</p>
                ${temp.replace(/\n/g, '<br>')}
                ${htmlBotonesPreview}
                <hr style="border:0; border-top:1px solid #f1f5f9; margin:30px 0;">
                <p style="margin:0; font-weight:bold; color:#0A2E4F; font-size:14px;">Equipo DataSense Analytics</p>
                <p style="margin:0; font-size:12px; color:#64748b;">Analítica · Ciencia de datos · BI</p>
            </div>
        </div>`;
}

// 5. ALERTAS DE ENVÍO
<?php if ($status_res === "success"): ?>
    Swal.fire({ icon: 'success', title: '¡Comunicación Enviada!', text: 'El correo fue entregado con éxito.', confirmButtonColor: '#0A2E4F' });
<?php elseif ($status_res === "error"): ?>
    Swal.fire({ icon: 'error', title: 'Error de envío', text: <?= jsJson('Detalle: ' . $error_detalle) ?>, confirmButtonColor: '#d33' });
<?php endif; ?>
</script>
</body>
</html>