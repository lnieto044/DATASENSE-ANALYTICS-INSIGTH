<?php
/**
 * REDES SOCIALES (Administrador y rol con el módulo "redes")
 * Todo lo que se necesita para mover las redes de la empresa y traer clientes:
 *   1. Publicaciones listas: afiches ya diseñados con su texto, en orden de publicación. El plan les pone fecha según los días
 *      de la semana y la hora que se elijan, y las descarga todas en un .zip para dejarlas programadas en Meta y LinkedIn.
 *   2. Diseñador de afiches: para armar una pieza propia con plantillas, colores de marca y fotos del sitio o subidas.
 *      Desde ahí se pasa al editor libre (editor_afiches.php), donde cada elemento se mueve a mano.
 *   3. Respuestas automáticas: los mensajes para la respuesta instantánea, la ausencia y las preguntas frecuentes,
 *      con los pasos para activarlos en cada red.
 * Los afiches se dibujan en el navegador (js/afiches.js) y se descargan como PNG: no se guarda nada en el servidor.
 * La plataforma no publica por su cuenta: la persona programa una vez en el Planificador de Meta y en LinkedIn, y esas redes
 * publican solas en la fecha elegida.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/redes_contenido.php';
require_once __DIR__ . '/../includes/itsm.php';   // festivosColombia(): el plan no publica en festivos
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'redes');

// Bases anteriores a este módulo: se registra para que aparezca en "Seguridad y Roles"
try {
    $db->exec("INSERT IGNORE INTO modulos_sistema (id_modulo, etiqueta, icono, orden) VALUES ('redes', 'Redes Sociales', 'fa-bullhorn', 35)");
} catch (Exception $e) {
    // Sin la tabla de módulos el panel sigue funcionando para el Administrador
}

$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = "Redes Sociales";
$subtitulo = "Afiches, publicaciones listas y respuestas automáticas";

$galeria = galeriaAfiches($db);
$publicaciones = publicacionesListas($db);
$respuestas = respuestasAutomaticas();
$perfiles = redesSociales();

$iconos_red = [
    'Facebook' => ['fa-facebook-f', '#1877f2'], 'Instagram' => ['fa-instagram', '#e4405f'],
    'LinkedIn' => ['fa-linkedin-in', '#0a66c2'], 'WhatsApp' => ['fa-whatsapp', '#25d366'],
];
$insignias_red = function (array $redes) use ($iconos_red) {
    $html = '';
    foreach ($redes as $red) {
        if (!isset($iconos_red[$red])) continue;
        $html .= '<span class="red-insignia" style="--color-red: ' . esc($iconos_red[$red][1]) . '" title="' . esc($red) . '">'
               . '<i class="fa-brands ' . esc($iconos_red[$red][0]) . '" aria-hidden="true"></i><span class="visually-hidden">' . esc($red) . '</span></span>';
    }
    return $html;
};
// Las redes en orden: primero las que ya tienen perfil configurado
$accesos = [];
foreach (['SOCIAL_FACEBOOK' => 'Facebook', 'SOCIAL_INSTAGRAM' => 'Instagram', 'SOCIAL_LINKEDIN' => 'LinkedIn', 'SOCIAL_WHATSAPP' => 'WhatsApp'] as $clave => $nombre) {
    if (isset($perfiles[$clave])) $accesos[$nombre] = $perfiles[$clave]['url'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redes Sociales | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/redes.css">
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="container-fluid py-4 redes">

            <ul class="nav nav-pills redes-pestanas" id="redesPestanas" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="pestanaPublicaciones" data-bs-toggle="pill" data-bs-target="#panelPublicaciones" type="button" role="tab" aria-controls="panelPublicaciones" aria-selected="true">
                        <i class="fas fa-calendar-check" aria-hidden="true"></i> Publicaciones listas <span class="redes-conteo"><?= count($publicaciones) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pestanaDisenador" data-bs-toggle="pill" data-bs-target="#panelDisenador" type="button" role="tab" aria-controls="panelDisenador" aria-selected="false">
                        <i class="fas fa-palette" aria-hidden="true"></i> Diseñador de afiches
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pestanaRespuestas" data-bs-toggle="pill" data-bs-target="#panelRespuestas" type="button" role="tab" aria-controls="panelRespuestas" aria-selected="false">
                        <i class="fas fa-reply-all" aria-hidden="true"></i> Respuestas automáticas
                    </button>
                </li>
                <li class="nav-item">
                    <a class="nav-link redes-pestana-editor" href="editor_afiches.php"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Editor libre</a>
                </li>
            </ul>

            <div class="tab-content">

                <!-- ============================================================ PUBLICACIONES LISTAS -->
                <section class="tab-pane fade show active" id="panelPublicaciones" role="tabpanel" aria-labelledby="pestanaPublicaciones" tabindex="0">

                    <div class="redes-banda">
                        <div>
                            <h3>Programa el mes una vez y se publica solo</h3>
                            <p>Elige los días y la hora. Cada publicación toma su fecha; la descargas toda de una vez y la dejas programada en tus redes.</p>
                        </div>
                        <div class="redes-accesos">
                            <a class="btn-red" href="https://business.facebook.com/latest/content_calendar" target="_blank" rel="noopener noreferrer" style="--color-red: #1877f2">
                                <i class="fa-brands fa-meta" aria-hidden="true"></i> Planificador de Meta
                            </a>
                            <?php foreach ($accesos as $nombre => $url): if ($nombre === 'WhatsApp') continue; ?>
                            <a class="btn-red" href="<?= esc($url) ?>" target="_blank" rel="noopener noreferrer" style="--color-red: <?= esc($iconos_red[$nombre][1]) ?>">
                                <i class="fa-brands <?= esc($iconos_red[$nombre][0]) ?>" aria-hidden="true"></i> <?= esc($nombre) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="redes-caja redes-plan" id="redesPlan">
                        <div class="redes-plan-campos">
                            <div>
                                <label class="form-label" for="planInicio">Empieza el</label>
                                <input type="date" class="form-control" id="planInicio">
                            </div>
                            <div>
                                <span class="form-label d-block" id="planDiasEtiqueta">Días de publicación</span>
                                <div class="plan-dias" id="planDias" role="group" aria-labelledby="planDiasEtiqueta">
                                    <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'] as $numero => $dia): ?>
                                    <button type="button" data-dia="<?= $numero ?>" title="<?= esc($dia) ?>" aria-label="<?= esc($dia) ?>" aria-pressed="false"><?= esc(mb_substr($dia, 0, $numero === 3 ? 2 : 1)) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label class="form-label" for="planHora">Hora</label>
                                <input type="time" class="form-control" id="planHora" step="900">
                            </div>
                        </div>
                        <div class="redes-plan-resumen">
                            <p id="planResumen" aria-live="polite"></p>
                            <button type="button" class="btn-pub btn-pub-principal" id="planDescargar"><i class="fas fa-file-zipper" aria-hidden="true"></i> Descargar todo el plan</button>
                        </div>
                    </div>

                    <details class="redes-guia">
                        <summary><i class="fas fa-circle-question" aria-hidden="true"></i> ¿Cómo dejo el mes programado para que se publique solo?</summary>
                        <div class="redes-guia-cuerpo">
                            <div class="redes-paso">
                                <span class="redes-paso-numero">1</span>
                                <div><b>Arma el plan.</b> Elige desde qué día empiezas, los días de la semana y la hora. Si quieres, cambia el texto de cualquier publicación: la descarga usa lo que dejes escrito.</div>
                            </div>
                            <div class="redes-paso">
                                <span class="redes-paso-numero">2</span>
                                <div><b>Descarga todo el plan.</b> Recibes un archivo .zip con las imágenes numeradas en orden de fecha y un documento con la fecha, la hora, las redes y el texto de cada una.</div>
                            </div>
                            <div class="redes-paso">
                                <span class="redes-paso-numero">3</span>
                                <div><b>Facebook e Instagram, de una sola vez.</b> Abre el <b>Planificador de Meta</b>, toca <b>Crear publicación</b>, marca Facebook e Instagram, sube la imagen, pega el texto y en <b>Opciones de programación</b> pon la fecha y la hora del documento. Repite con cada una: Meta las publica solas en su fecha, aunque no estés conectado.</div>
                            </div>
                            <div class="redes-paso">
                                <span class="redes-paso-numero">4</span>
                                <div><b>LinkedIn.</b> En la página de la empresa toca <b>Iniciar publicación</b>, agrega la imagen y el texto, y con el icono del reloj elige la fecha y la hora.</div>
                            </div>
                            <div class="redes-paso">
                                <span class="redes-paso-numero">5</span>
                                <div><b>Historias.</b> En la mayoría de cuentas no se pueden programar: súbelas el día que quieras desde el celular con el botón <b>Compartir</b>.</div>
                            </div>
                            <p class="redes-nota"><i class="fas fa-comments" aria-hidden="true"></i> Varias publicaciones invitan a comentar <b>INFO</b>, <b>CONFERENCIA</b> o <b>DEMO</b>. Activa en <b>Respuestas automáticas</b> el mensaje de cada palabra: quien comente la recibe por mensaje privado al instante.</p>
                            <p class="redes-nota"><i class="fas fa-lightbulb" aria-hidden="true"></i> En Instagram los enlaces del texto no se pueden tocar: deja la dirección del sitio en la biografía del perfil.</p>
                        </div>
                    </details>

                    <div class="row g-4">
                        <?php foreach ($publicaciones as $pub): ?>
                        <div class="col-md-6 col-xxl-4">
                            <article class="pub-tarjeta" data-id="<?= esc($pub['id']) ?>">
                                <div class="pub-lienzo">
                                    <canvas data-publicacion="<?= esc($pub['id']) ?>" role="img" aria-label="Afiche: <?= esc(str_replace('*', '', (string) ($pub['diseno']['titulo'] ?? $pub['diseno']['texto'] ?? $pub['tema']))) ?>"></canvas>
                                </div>
                                <div class="pub-cuerpo">
                                    <div class="pub-meta">
                                        <span class="pub-momento"><i class="far <?= $pub['historia'] ? 'fa-circle-play' : 'fa-calendar' ?>" aria-hidden="true"></i> <span data-fecha><?= $pub['historia'] ? 'Historia · cuando quieras' : '' ?></span></span>
                                        <span class="pub-redes"><?= $insignias_red($pub['redes']) ?></span>
                                    </div>
                                    <div class="pub-tema"><?= esc($pub['tema']) ?></div>
                                    <label class="visually-hidden" for="pubTexto-<?= esc($pub['id']) ?>">Texto de la publicación</label>
                                    <textarea class="form-control pub-texto" id="pubTexto-<?= esc($pub['id']) ?>" rows="7"><?= esc($pub['texto']) ?></textarea>
                                    <div class="pub-acciones">
                                        <button type="button" class="btn-pub btn-pub-principal" data-accion="descargar"><i class="fas fa-download" aria-hidden="true"></i> Imagen</button>
                                        <button type="button" class="btn-pub" data-copiar="#pubTexto-<?= esc($pub['id']) ?>"><i class="far fa-copy" aria-hidden="true"></i> Copiar texto</button>
                                        <button type="button" class="btn-pub" data-accion="compartir" hidden><i class="fas fa-share-nodes" aria-hidden="true"></i> Compartir</button>
                                        <button type="button" class="btn-pub" data-accion="editar"><i class="fas fa-pen" aria-hidden="true"></i> Editar</button>
                                    </div>
                                </div>
                            </article>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- ============================================================ DISEÑADOR -->
                <section class="tab-pane fade" id="panelDisenador" role="tabpanel" aria-labelledby="pestanaDisenador" tabindex="0">
                    <div class="row g-4">
                        <div class="col-xl-5">
                            <div class="redes-caja">
                                <h3 class="redes-caja-titulo"><span>1</span> Elige una plantilla</h3>
                                <div class="af-plantillas" id="afPlantillas" role="group" aria-label="Plantilla del afiche"></div>

                                <h3 class="redes-caja-titulo"><span>2</span> Tamaño y colores</h3>
                                <div class="row g-3">
                                    <div class="col-sm-7">
                                        <label class="form-label" for="afFormato">Tamaño</label>
                                        <select class="form-select" id="afFormato"></select>
                                    </div>
                                    <div class="col-sm-5">
                                        <span class="form-label d-block" id="afTemasEtiqueta">Colores</span>
                                        <div class="af-temas" id="afTemas" role="group" aria-labelledby="afTemasEtiqueta"></div>
                                    </div>
                                </div>

                                <h3 class="redes-caja-titulo"><span>3</span> Escribe tu mensaje</h3>
                                <div class="af-campo" data-campo="etiqueta">
                                    <label class="form-label" for="afEtiqueta">Etiqueta</label>
                                    <input type="text" class="form-control" id="afEtiqueta" maxlength="45" placeholder="Por ejemplo: Nuevo servicio">
                                </div>
                                <div class="af-campo" data-campo="titulo">
                                    <label class="form-label" for="afTitulo">Título</label>
                                    <textarea class="form-control" id="afTitulo" rows="2" maxlength="110"></textarea>
                                    <div class="form-text">Pon entre *asteriscos* las palabras que quieras resaltar.</div>
                                </div>
                                <div class="af-campo" data-campo="texto">
                                    <label class="form-label" for="afTexto">Texto</label>
                                    <textarea class="form-control" id="afTexto" rows="3" maxlength="320"></textarea>
                                </div>
                                <div class="af-campo" data-campo="puntos">
                                    <label class="form-label" for="afPuntos">Lista de ideas</label>
                                    <textarea class="form-control" id="afPuntos" rows="3" maxlength="400" placeholder="Una idea por renglón"></textarea>
                                    <div class="form-text">Una idea por renglón, máximo cinco.</div>
                                </div>
                                <div class="row g-3 af-campo" data-campo="precio">
                                    <div class="col-5">
                                        <label class="form-label" for="afPrecio">Precio</label>
                                        <input type="text" class="form-control" id="afPrecio" maxlength="18" placeholder="$ 249.000">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="afPrecioAntes">Antes</label>
                                        <input type="text" class="form-control" id="afPrecioAntes" maxlength="18" placeholder="$ 320.000">
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label" for="afInsignia">Sello</label>
                                        <input type="text" class="form-control" id="afInsignia" maxlength="8" placeholder="-22%">
                                    </div>
                                </div>
                                <div class="row g-3 af-campo" data-campo="autor">
                                    <div class="col-sm-5">
                                        <label class="form-label" for="afAutor">Quién lo dijo</label>
                                        <input type="text" class="form-control" id="afAutor" maxlength="50">
                                    </div>
                                    <div class="col-sm-7">
                                        <label class="form-label" for="afCargo">Cargo y empresa</label>
                                        <input type="text" class="form-control" id="afCargo" maxlength="70">
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-sm-6 af-campo" data-campo="boton">
                                        <label class="form-label" for="afBoton">Botón</label>
                                        <input type="text" class="form-control" id="afBoton" maxlength="34" placeholder="Solicita tu asesoría">
                                    </div>
                                    <div class="col-sm-6 af-campo" data-campo="pie">
                                        <label class="form-label" for="afPie">Pie</label>
                                        <input type="text" class="form-control" id="afPie" maxlength="60">
                                    </div>
                                </div>

                                <div class="af-campo" data-campo="imagen">
                                    <h3 class="redes-caja-titulo"><span>4</span> Pon una foto</h3>
                                    <div class="row g-3 align-items-end">
                                        <div class="col-sm-7">
                                            <label class="form-label" for="afGaleria">Fotos del sitio</label>
                                            <select class="form-select" id="afGaleria"></select>
                                        </div>
                                        <div class="col-sm-5">
                                            <label class="btn-pub w-100 justify-content-center mb-0" for="afSubir"><i class="fas fa-upload" aria-hidden="true"></i> Subir una foto</label>
                                            <input type="file" class="visually-hidden" id="afSubir" accept="image/png,image/jpeg,image/webp">
                                        </div>
                                    </div>
                                    <div class="form-text">La foto que subes se usa solo en este afiche: no se guarda en la plataforma.</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-7">
                            <div class="redes-caja af-vista">
                                <div class="af-vista-encabezado">
                                    <h3 class="redes-caja-titulo mb-0"><i class="fas fa-eye" aria-hidden="true"></i> Así queda tu afiche</h3>
                                    <span class="af-medidas" id="afMedidas"></span>
                                </div>
                                <div class="af-lienzo"><canvas id="afLienzo" role="img" aria-label="Vista previa del afiche"></canvas></div>
                                <div class="pub-acciones af-acciones">
                                    <button type="button" class="btn-pub btn-pub-principal" id="afDescargar"><i class="fas fa-download" aria-hidden="true"></i> Descargar imagen</button>
                                    <button type="button" class="btn-pub" id="afCompartir" hidden><i class="fas fa-share-nodes" aria-hidden="true"></i> Compartir</button>
                                    <button type="button" class="btn-pub" id="afCopiarImagen" hidden><i class="far fa-copy" aria-hidden="true"></i> Copiar imagen</button>
                                    <button type="button" class="btn-pub btn-pub-suave" id="afReiniciar"><i class="fas fa-rotate-left" aria-hidden="true"></i> Empezar de nuevo</button>
                                </div>
                                <div class="af-libre">
                                    <div>
                                        <b>¿Quieres mover cada cosa a tu gusto?</b>
                                        <span>En el editor libre arrastras textos, fotos, formas e iconos, cambias fuentes y guardas tus diseños.</span>
                                    </div>
                                    <button type="button" class="btn-pub btn-pub-verde" id="afEditorLibre"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Abrir en el editor libre</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================================ RESPUESTAS AUTOMÁTICAS -->
                <section class="tab-pane fade" id="panelRespuestas" role="tabpanel" aria-labelledby="pestanaRespuestas" tabindex="0">

                    <div class="redes-banda">
                        <div>
                            <h3>Que nadie se quede sin respuesta</h3>
                            <p>Estos mensajes se envían solos cuando alguien te escribe. Cópialos y actívalos una sola vez en cada red.</p>
                        </div>
                        <div class="redes-compromiso">
                            <div><i class="fas fa-stopwatch" aria-hidden="true"></i><span><b>Menos de 24 horas</b>Tiempo de respuesta que prometes</span></div>
                            <div><i class="fas fa-clock" aria-hidden="true"></i><span><b>Lunes a viernes, 8:00 a.m. a 5:00 p.m.</b>Horario de atención</span></div>
                        </div>
                    </div>

                    <h3 class="redes-subtitulo">Mensajes automáticos</h3>
                    <div class="row g-4">
                        <?php foreach ($respuestas['principales'] as $r): ?>
                        <div class="col-lg-6">
                            <article class="redes-caja resp-tarjeta">
                                <div class="resp-encabezado">
                                    <span class="resp-icono"><i class="fas <?= esc($r['icono']) ?>" aria-hidden="true"></i></span>
                                    <div>
                                        <h4><?= esc($r['titulo']) ?></h4>
                                        <p><?= esc($r['cuando']) ?></p>
                                    </div>
                                    <span class="pub-redes"><?= $insignias_red($r['donde']) ?></span>
                                </div>
                                <label class="visually-hidden" for="resp-<?= esc($r['id']) ?>"><?= esc($r['titulo']) ?></label>
                                <textarea class="form-control pub-texto" id="resp-<?= esc($r['id']) ?>" rows="7" data-contar="#cuenta-<?= esc($r['id']) ?>"><?= esc($r['texto']) ?></textarea>
                                <div class="resp-pie">
                                    <span class="resp-cuenta" id="cuenta-<?= esc($r['id']) ?>"></span>
                                    <button type="button" class="btn-pub btn-pub-principal" data-copiar="#resp-<?= esc($r['id']) ?>"><i class="far fa-copy" aria-hidden="true"></i> Copiar mensaje</button>
                                </div>
                            </article>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="redes-subtitulo">Comentarios con palabra clave</h3>
                    <p class="redes-ayuda">Las publicaciones invitan a comentar estas palabras. Con la automatización de comentarios de Meta, quien comente una de ellas recibe al instante este mensaje privado: el comentario se vuelve una conversación con un posible cliente.</p>
                    <div class="row g-4">
                        <?php foreach ($respuestas['palabras'] as $r): ?>
                        <div class="col-lg-4">
                            <article class="redes-caja resp-tarjeta">
                                <div class="resp-encabezado">
                                    <span class="resp-palabra"><?= esc($r['palabra']) ?></span>
                                    <div>
                                        <h4><?= esc($r['titulo']) ?></h4>
                                        <p>Mensaje privado automático</p>
                                    </div>
                                </div>
                                <label class="visually-hidden" for="resp-<?= esc($r['id']) ?>">Mensaje privado para quien comente <?= esc($r['palabra']) ?></label>
                                <textarea class="form-control pub-texto" id="resp-<?= esc($r['id']) ?>" rows="7"><?= esc($r['texto']) ?></textarea>
                                <div class="resp-pie">
                                    <span></span>
                                    <button type="button" class="btn-pub btn-pub-principal" data-copiar="#resp-<?= esc($r['id']) ?>"><i class="far fa-copy" aria-hidden="true"></i> Copiar mensaje</button>
                                </div>
                            </article>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="redes-subtitulo">Preguntas frecuentes y respuestas rápidas</h3>
                    <p class="redes-ayuda">Sirven para las preguntas frecuentes de Facebook e Instagram y como respuestas rápidas de WhatsApp Business: allá escribes el atajo y el mensaje aparece completo.</p>
                    <div class="row g-4">
                        <?php foreach ($respuestas['frecuentes'] as $r): ?>
                        <div class="col-lg-6 col-xxl-4">
                            <article class="redes-caja resp-tarjeta">
                                <div class="resp-encabezado">
                                    <div>
                                        <h4><?= esc($r['pregunta']) ?></h4>
                                    </div>
                                    <code class="resp-atajo" title="Atajo para WhatsApp Business"><?= esc($r['atajo']) ?></code>
                                </div>
                                <label class="visually-hidden" for="resp-<?= esc($r['id']) ?>">Respuesta a: <?= esc($r['pregunta']) ?></label>
                                <textarea class="form-control pub-texto" id="resp-<?= esc($r['id']) ?>" rows="6"><?= esc($r['texto']) ?></textarea>
                                <div class="resp-pie">
                                    <span></span>
                                    <button type="button" class="btn-pub" data-copiar="#resp-<?= esc($r['id']) ?>"><i class="far fa-copy" aria-hidden="true"></i> Copiar respuesta</button>
                                </div>
                            </article>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="redes-subtitulo">Dónde se activan</h3>
                    <div class="accordion redes-acordeon" id="redesActivar">
                        <div class="accordion-item">
                            <h4 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#activarMeta" aria-expanded="true" aria-controls="activarMeta">
                                    <span class="red-insignia" style="--color-red: #1877f2"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></span>
                                    <span class="red-insignia" style="--color-red: #e4405f"><i class="fa-brands fa-instagram" aria-hidden="true"></i></span>
                                    Facebook e Instagram
                                </button>
                            </h4>
                            <div id="activarMeta" class="accordion-collapse collapse show" data-bs-parent="#redesActivar">
                                <div class="accordion-body">
                                    <ol>
                                        <li>Entra a <a href="https://business.facebook.com/latest/inbox" target="_blank" rel="noopener noreferrer">Meta Business Suite</a> con la cuenta que administra la página.</li>
                                        <li>Si Instagram aún no aparece, conéctalo en <b>Configuración › Cuentas › Cuentas de Instagram</b>. Debe ser una cuenta profesional.</li>
                                        <li>Abre la <b>Bandeja de entrada</b> y toca <b>Automatizaciones</b>.</li>
                                        <li>Activa <b>Respuesta instantánea</b> y pega el mensaje de respuesta instantánea.</li>
                                        <li>Activa <b>Mensaje de ausencia</b>, marca el horario en que no atiendes y pega el mensaje de ausencia.</li>
                                        <li>En <b>Preguntas frecuentes</b> agrega cada pregunta con su respuesta.</li>
                                        <li>Crea una automatización de <b>comentarios con palabras clave</b> por cada palabra (INFO, CONFERENCIA y DEMO), elige las publicaciones donde aplica y pega su mensaje privado.</li>
                                    </ol>
                                    <p class="redes-nota"><i class="fas fa-lightbulb" aria-hidden="true"></i> Si el mensaje de ausencia está encendido, es el que se envía en ese horario en lugar de la respuesta instantánea. Las automatizaciones solo responden a quien escribe la palabra clave: no envíes mensajes a todos los que comentan, porque Meta puede limitar la cuenta. Los nombres del menú pueden cambiar un poco según la versión de Meta; si tu página aún no muestra la automatización de comentarios, responde esos comentarios a mano con el mismo mensaje.</p>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h4 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#activarWhatsapp" aria-expanded="false" aria-controls="activarWhatsapp">
                                    <span class="red-insignia" style="--color-red: #25d366"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></span>
                                    WhatsApp Business
                                </button>
                            </h4>
                            <div id="activarWhatsapp" class="accordion-collapse collapse" data-bs-parent="#redesActivar">
                                <div class="accordion-body">
                                    <ol>
                                        <li>Usa la aplicación <b>WhatsApp Business</b> (la del logo con la letra B), no el WhatsApp personal.</li>
                                        <li>Entra a <b>Ajustes › Herramientas para la empresa</b>.</li>
                                        <li>En <b>Perfil de empresa</b> registra el horario de atención.</li>
                                        <li>En <b>Mensaje de bienvenida</b> pega el mensaje de respuesta instantánea.</li>
                                        <li>En <b>Mensaje de ausencia</b> elige <b>Fuera del horario comercial</b> y pega el mensaje de ausencia.</li>
                                        <li>En <b>Respuestas rápidas</b> crea una por cada pregunta frecuente, con su atajo (por ejemplo <code>/precios</code>).</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h4 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#activarLinkedin" aria-expanded="false" aria-controls="activarLinkedin">
                                    <span class="red-insignia" style="--color-red: #0a66c2"><i class="fa-brands fa-linkedin-in" aria-hidden="true"></i></span>
                                    LinkedIn
                                </button>
                            </h4>
                            <div id="activarLinkedin" class="accordion-collapse collapse" data-bs-parent="#redesActivar">
                                <div class="accordion-body">
                                    <p>La bandeja de la página de empresa en LinkedIn no envía respuestas automáticas, así que allí se responde a mano:</p>
                                    <ol>
                                        <li>En la página de la empresa abre la <b>Bandeja de entrada</b> y deja encendido el botón <b>Mensaje</b>.</li>
                                        <li>Revísala una vez al día y contesta con estos mismos textos.</li>
                                        <li>Usa la <b>respuesta a comentarios</b> cuando alguien pregunte en una publicación.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="redes-aviso" id="redesAviso" role="status" aria-live="polite" hidden></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
window.REDES_DATOS = <?= jsJson(['galeria' => $galeria, 'publicaciones' => $publicaciones, 'pie' => pieAfiches(), 'logo' => '../img/logo.png',
                                  'festivos' => festivosColombia((int) date('Y')) + festivosColombia((int) date('Y') + 1)]) ?>;
</script>
<script src="../js/afiches.js?v=3"></script>
<script src="../js/redes.js?v=3"></script>
</body>
</html>
