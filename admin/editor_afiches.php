<?php
/**
 * EDITOR LIBRE DE AFICHES (Administrador y rol con el módulo "redes")
 * Lienzo a pantalla completa para diseñar piezas de redes sociales moviendo cada elemento:
 * textos con varias fuentes, formas, iconos, fotos del sitio o subidas, logo y colores de la marca, capas,
 * deshacer y rehacer, cambio de tamaño, diseños guardados y descarga en PNG o JPG.
 * Las plantillas son las publicaciones listas del módulo Redes Sociales, convertidas en elementos editables.
 *
 * Todo ocurre en el navegador (js/editor_afiches.js sobre la biblioteca Fabric.js): los diseños se guardan en el
 * equipo de la persona y las fotos que sube no llegan al servidor.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/redes_contenido.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'redes');

$galeria = galeriaAfiches($db);
$publicaciones = publicacionesListas($db);

// Iconos que se ofrecen en "Elementos" (clases de Font Awesome; el navegador entrega el dibujo de cada una)
$iconos = [
    'fa-solid fa-chart-line', 'fa-solid fa-chart-pie', 'fa-solid fa-chart-column', 'fa-solid fa-database', 'fa-solid fa-laptop-code',
    'fa-solid fa-lightbulb', 'fa-solid fa-rocket', 'fa-solid fa-bullseye', 'fa-solid fa-handshake', 'fa-solid fa-trophy',
    'fa-solid fa-award', 'fa-solid fa-thumbs-up', 'fa-solid fa-comments', 'fa-solid fa-bullhorn', 'fa-solid fa-gift',
    'fa-solid fa-tag', 'fa-solid fa-cart-shopping', 'fa-solid fa-circle-check', 'fa-solid fa-shield-halved', 'fa-solid fa-gear',
    'fa-solid fa-microphone-lines', 'fa-solid fa-graduation-cap', 'fa-solid fa-brain', 'fa-solid fa-leaf', 'fa-solid fa-heart',
    'fa-solid fa-star', 'fa-solid fa-bolt', 'fa-solid fa-users', 'fa-solid fa-user-tie', 'fa-solid fa-building',
    'fa-solid fa-phone', 'fa-solid fa-envelope', 'fa-solid fa-location-dot', 'fa-solid fa-globe', 'fa-solid fa-calendar-days',
    'fa-solid fa-clock', 'fa-solid fa-arrow-right', 'fa-solid fa-quote-left', 'fa-solid fa-magnifying-glass', 'fa-solid fa-lock',
    'fa-brands fa-facebook-f', 'fa-brands fa-instagram', 'fa-brands fa-linkedin-in', 'fa-brands fa-whatsapp', 'fa-brands fa-youtube',
];
$paneles = [
    'plantillas' => ['fa-table-cells-large', 'Plantillas'], 'texto' => ['fa-font', 'Texto'], 'elementos' => ['fa-shapes', 'Elementos'],
    'fotos' => ['fa-image', 'Fotos'], 'marca' => ['fa-swatchbook', 'Marca'], 'capas' => ['fa-layer-group', 'Capas'],
    'disenos' => ['fa-folder-open', 'Mis diseños'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de afiches | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Dancing+Script:wght@400;700&family=Inter:wght@400;500;600;700;800&family=Lora:wght@400;600;700&family=Merriweather:wght@400;700;900&family=Montserrat:wght@400;600;700;800&family=Oswald:wght@400;600;700&family=Pacifico&family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@400;600;700;800&family=Raleway:wght@400;600;700;800&family=Roboto+Slab:wght@400;600;700;800&family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/editor_afiches.css?v=1">
</head>
<body class="editor">

<header class="ed-barra">
    <a class="ed-volver" href="redes_sociales.php#disenador" title="Volver a Redes Sociales">
        <i class="fas fa-arrow-left" aria-hidden="true"></i><span>Redes Sociales</span>
    </a>
    <input type="text" class="ed-nombre" id="edNombre" value="Afiche sin título" maxlength="60" aria-label="Nombre del diseño">

    <div class="ed-grupo">
        <button type="button" class="ed-icono" id="edDeshacer" title="Deshacer (Ctrl + Z)" aria-label="Deshacer" disabled><i class="fas fa-rotate-left" aria-hidden="true"></i></button>
        <button type="button" class="ed-icono" id="edRehacer" title="Rehacer (Ctrl + Y)" aria-label="Rehacer" disabled><i class="fas fa-rotate-right" aria-hidden="true"></i></button>
    </div>
    <div class="ed-grupo ed-grupo-zoom">
        <button type="button" class="ed-icono" id="edAlejar" title="Alejar" aria-label="Alejar"><i class="fas fa-minus" aria-hidden="true"></i></button>
        <span class="ed-zoom" id="edZoom" aria-live="polite">100%</span>
        <button type="button" class="ed-icono" id="edAcercar" title="Acercar" aria-label="Acercar"><i class="fas fa-plus" aria-hidden="true"></i></button>
        <button type="button" class="ed-icono" id="edAjustar" title="Ajustar a la pantalla" aria-label="Ajustar a la pantalla"><i class="fas fa-expand" aria-hidden="true"></i></button>
    </div>

    <div class="ed-grupo ed-final">
        <span class="ed-estado" id="edEstado" aria-live="polite"></span>
        <button type="button" class="ed-btn" id="edGuardar"><i class="fas fa-floppy-disk" aria-hidden="true"></i><span>Guardar</span></button>
        <div class="dropdown">
            <button type="button" class="ed-btn ed-btn-principal dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-download" aria-hidden="true"></i><span>Descargar</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><button type="button" class="dropdown-item" data-exportar="png"><i class="far fa-file-image" aria-hidden="true"></i> Imagen PNG <small>mejor calidad</small></button></li>
                <li><button type="button" class="dropdown-item" data-exportar="jpg"><i class="far fa-file-image" aria-hidden="true"></i> Imagen JPG <small>más liviana</small></button></li>
                <li><button type="button" class="dropdown-item" id="edCopiar" hidden><i class="far fa-copy" aria-hidden="true"></i> Copiar imagen</button></li>
                <li><button type="button" class="dropdown-item" id="edCompartir" hidden><i class="fas fa-share-nodes" aria-hidden="true"></i> Compartir</button></li>
            </ul>
        </div>
    </div>
</header>

<div class="ed-cuerpo">

    <!-- Riel de herramientas -->
    <nav class="ed-riel" aria-label="Herramientas">
        <?php foreach ($paneles as $clave => [$icono, $nombre]): ?>
        <button type="button" class="ed-riel-boton<?= $clave === 'plantillas' ? ' activo' : '' ?>" data-panel="<?= esc($clave) ?>" aria-pressed="<?= $clave === 'plantillas' ? 'true' : 'false' ?>">
            <i class="fas <?= esc($icono) ?>" aria-hidden="true"></i><span><?= esc($nombre) ?></span>
        </button>
        <?php endforeach; ?>
    </nav>

    <!-- Panel de la herramienta elegida -->
    <aside class="ed-panel" id="edPanel" aria-label="Opciones de la herramienta">
        <button type="button" class="ed-panel-cerrar" id="edPanelCerrar" aria-label="Cerrar el panel"><i class="fas fa-xmark" aria-hidden="true"></i></button>

        <section class="ed-seccion" data-panel="plantillas">
            <h2>Plantillas</h2>
            <p class="ed-ayuda">Elige una para empezar. Después puedes mover, cambiar o borrar cada elemento.</p>
            <button type="button" class="ed-btn ed-btn-ancho" id="edEnBlanco"><i class="far fa-file" aria-hidden="true"></i> Empezar en blanco</button>
            <div class="ed-rejilla ed-rejilla-plantillas" id="edPlantillas"></div>
        </section>

        <section class="ed-seccion" data-panel="texto" hidden>
            <h2>Texto</h2>
            <p class="ed-ayuda">Agrega un texto y haz doble clic sobre él para escribir.</p>
            <button type="button" class="ed-texto-nuevo ed-texto-titulo" data-texto="titulo">Agregar un título</button>
            <button type="button" class="ed-texto-nuevo ed-texto-subtitulo" data-texto="subtitulo">Agregar un subtítulo</button>
            <button type="button" class="ed-texto-nuevo ed-texto-parrafo" data-texto="parrafo">Agregar un párrafo</button>
            <h3>Con fondo</h3>
            <div class="ed-fila">
                <button type="button" class="ed-muestra-boton" data-pieza="boton">Botón →</button>
                <button type="button" class="ed-muestra-etiqueta" data-pieza="etiqueta">● ETIQUETA</button>
                <button type="button" class="ed-muestra-insignia" data-pieza="insignia">-20%</button>
            </div>
        </section>

        <section class="ed-seccion" data-panel="elementos" hidden>
            <h2>Elementos</h2>
            <h3>Formas</h3>
            <div class="ed-rejilla ed-rejilla-formas">
                <button type="button" data-forma="rectangulo" title="Rectángulo" aria-label="Rectángulo"><svg viewBox="0 0 40 40"><rect x="5" y="9" width="30" height="22"/></svg></button>
                <button type="button" data-forma="redondeado" title="Rectángulo redondeado" aria-label="Rectángulo redondeado"><svg viewBox="0 0 40 40"><rect x="5" y="9" width="30" height="22" rx="7"/></svg></button>
                <button type="button" data-forma="circulo" title="Círculo" aria-label="Círculo"><svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="14"/></svg></button>
                <button type="button" data-forma="triangulo" title="Triángulo" aria-label="Triángulo"><svg viewBox="0 0 40 40"><path d="M20 6 35 33H5Z"/></svg></button>
                <button type="button" data-forma="estrella" title="Estrella" aria-label="Estrella"><svg viewBox="0 0 40 40"><path d="m20 4 4.7 10.6L36 15.8l-8.5 7.7L30 35l-10-5.8L10 35l2.5-11.5L4 15.8l11.3-1.2Z"/></svg></button>
                <button type="button" data-forma="anillo" title="Anillo" aria-label="Anillo"><svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="none" stroke="currentColor" stroke-width="4"/></svg></button>
                <button type="button" data-forma="linea" title="Línea" aria-label="Línea"><svg viewBox="0 0 40 40"><path d="M5 20h30" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg></button>
                <button type="button" data-forma="flecha" title="Flecha" aria-label="Flecha"><svg viewBox="0 0 40 40"><path d="M4 16h20V9l12 11-12 11v-7H4Z"/></svg></button>
            </div>
            <h3>Iconos</h3>
            <div class="ed-rejilla ed-rejilla-iconos" id="edIconos">
                <?php foreach ($iconos as $icono): ?>
                <button type="button" data-icono="<?= esc($icono) ?>" aria-label="Icono <?= esc(str_replace(['fa-solid fa-', 'fa-brands fa-', '-'], ['', '', ' '], $icono)) ?>"><i class="<?= esc($icono) ?>" aria-hidden="true"></i></button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ed-seccion" data-panel="fotos" hidden>
            <h2>Fotos</h2>
            <label class="ed-btn ed-btn-principal ed-btn-ancho" for="edSubir"><i class="fas fa-upload" aria-hidden="true"></i> Subir fotos</label>
            <input type="file" class="visually-hidden" id="edSubir" accept="image/png,image/jpeg,image/webp" multiple>
            <p class="ed-ayuda">Las fotos que subes se quedan en tu equipo: no se guardan en la plataforma.</p>
            <?php $grupo_actual = ''; foreach ($galeria as $foto): ?>
                <?php if ($foto['grupo'] !== $grupo_actual): ?>
                    <?= $grupo_actual !== '' ? '</div>' : '' ?>
                    <h3><?= esc($foto['grupo']) ?></h3>
                    <div class="ed-rejilla ed-rejilla-fotos">
                    <?php $grupo_actual = $foto['grupo']; ?>
                <?php endif; ?>
                <button type="button" data-foto="<?= esc($foto['url']) ?>" data-recorte="<?= esc((string) ($foto['recorte'] ?? 0)) ?>" title="<?= esc($foto['nombre']) ?>">
                    <img src="<?= esc($foto['url']) ?>" alt="<?= esc($foto['nombre']) ?>" loading="lazy">
                </button>
            <?php endforeach; ?>
            <?= $grupo_actual !== '' ? '</div>' : '' ?>
        </section>

        <section class="ed-seccion" data-panel="marca" hidden>
            <h2>Marca</h2>
            <h3>Logo</h3>
            <div class="ed-rejilla ed-rejilla-logos">
                <button type="button" data-logo="sello" title="Logo sobre fondo blanco"><span class="ed-logo-sello"><img src="../img/logo.png" alt="Logo sobre fondo blanco"></span></button>
                <button type="button" data-logo="solo" title="Logo sin fondo"><img src="../img/logo.png" alt="Logo sin fondo"></button>
            </div>
            <h3>Colores de la marca</h3>
            <p class="ed-ayuda">Selecciona un elemento y toca un color para aplicarlo.</p>
            <div class="ed-paleta" data-paleta="marca"></div>
            <h3>Fondos</h3>
            <div class="ed-degradados" id="edDegradadosMarca"></div>
        </section>

        <section class="ed-seccion" data-panel="capas" hidden>
            <h2>Capas</h2>
            <p class="ed-ayuda">Lo de arriba tapa lo de abajo. Toca una capa para seleccionarla.</p>
            <ul class="ed-capas" id="edCapas"></ul>
        </section>

        <section class="ed-seccion" data-panel="disenos" hidden>
            <h2>Mis diseños</h2>
            <p class="ed-ayuda">Se guardan en este equipo y en este navegador.</p>
            <div class="ed-rejilla ed-rejilla-plantillas" id="edDisenos"></div>
            <p class="ed-vacio" id="edDisenosVacio">Aún no has guardado diseños. Usa el botón <b>Guardar</b>.</p>
        </section>
    </aside>

    <!-- Lienzo -->
    <main class="ed-escenario" id="edEscenario">
        <div class="ed-hoja" id="edHoja"><canvas id="edLienzo"></canvas></div>
    </main>

    <!-- Propiedades de lo seleccionado -->
    <aside class="ed-props" id="edProps" aria-label="Propiedades">

        <section data-para="pagina">
            <h2>Página</h2>
            <label class="ed-etq" for="prFormato">Tamaño</label>
            <select class="form-select form-select-sm" id="prFormato"></select>
            <div class="ed-medidas" id="prMedidas" hidden>
                <input type="number" class="form-control form-control-sm" id="prAncho" min="200" max="4000" aria-label="Ancho en píxeles">
                <span>×</span>
                <input type="number" class="form-control form-control-sm" id="prAlto" min="200" max="4000" aria-label="Alto en píxeles">
                <button type="button" class="ed-btn" id="prAplicarTamano">Aplicar</button>
            </div>
            <p class="ed-ayuda">Al cambiar el tamaño, los elementos se acomodan a la nueva página.</p>
            <span class="ed-etq">Color de fondo</span>
            <div class="ed-paleta" data-paleta="fondo"></div>
            <span class="ed-etq">Degradados</span>
            <div class="ed-degradados" id="prDegradados"></div>
            <p class="ed-pista"><i class="fas fa-arrow-pointer" aria-hidden="true"></i> Toca un elemento del afiche para ver aquí sus opciones.</p>
        </section>

        <section data-para="texto" hidden>
            <h2>Texto</h2>
            <label class="ed-etq" for="prFuente">Fuente</label>
            <select class="form-select form-select-sm" id="prFuente"></select>
            <div class="ed-dos">
                <div>
                    <label class="ed-etq" for="prTamano">Tamaño</label>
                    <input type="number" class="form-control form-control-sm" id="prTamano" min="8" max="600">
                </div>
                <div>
                    <label class="ed-etq" for="prPeso">Grosor</label>
                    <select class="form-select form-select-sm" id="prPeso">
                        <option value="400">Normal</option><option value="600">Seminegrita</option><option value="700">Negrita</option><option value="800">Extra negrita</option>
                    </select>
                </div>
            </div>
            <div class="ed-botonera">
                <button type="button" data-estilo="cursiva" title="Cursiva" aria-label="Cursiva"><i class="fas fa-italic" aria-hidden="true"></i></button>
                <button type="button" data-estilo="subrayado" title="Subrayado" aria-label="Subrayado"><i class="fas fa-underline" aria-hidden="true"></i></button>
                <button type="button" data-estilo="mayusculas" title="Mayúsculas" aria-label="Pasar a mayúsculas"><i class="fas fa-a" aria-hidden="true"></i><i class="fas fa-a" aria-hidden="true"></i></button>
                <span class="ed-separador"></span>
                <button type="button" data-alinear-texto="left" title="Alinear a la izquierda" aria-label="Alinear a la izquierda"><i class="fas fa-align-left" aria-hidden="true"></i></button>
                <button type="button" data-alinear-texto="center" title="Centrar" aria-label="Centrar el texto"><i class="fas fa-align-center" aria-hidden="true"></i></button>
                <button type="button" data-alinear-texto="right" title="Alinear a la derecha" aria-label="Alinear a la derecha"><i class="fas fa-align-right" aria-hidden="true"></i></button>
            </div>
            <span class="ed-etq">Color</span>
            <div class="ed-paleta" data-paleta="relleno"></div>
            <p class="ed-ayuda">Para colorear solo unas palabras, haz doble clic en el texto, selecciónalas y elige el color.</p>
            <label class="ed-etq" for="prInterlineado">Espacio entre renglones</label>
            <input type="range" class="form-range" id="prInterlineado" min="0.7" max="2.2" step="0.05">
            <label class="ed-etq" for="prEspaciado">Espacio entre letras</label>
            <input type="range" class="form-range" id="prEspaciado" min="-50" max="500" step="10">
        </section>

        <section data-para="pieza" hidden>
            <h2>Texto con fondo</h2>
            <label class="ed-etq" for="prTextoPieza">Texto</label>
            <input type="text" class="form-control form-control-sm" id="prTextoPieza" maxlength="60">
            <span class="ed-etq">Color del fondo</span>
            <div class="ed-paleta" data-paleta="piezaFondo"></div>
            <span class="ed-etq">Color del texto</span>
            <div class="ed-paleta" data-paleta="piezaTexto"></div>
        </section>

        <section data-para="forma" hidden>
            <h2>Forma</h2>
            <span class="ed-etq">Relleno</span>
            <div class="ed-paleta" data-paleta="relleno"></div>
            <span class="ed-etq">Borde</span>
            <div class="ed-paleta" data-paleta="borde"></div>
            <label class="ed-etq" for="prBorde">Grosor del borde</label>
            <input type="range" class="form-range" id="prBorde" min="0" max="40" step="1">
            <div id="prRadioCaja">
                <label class="ed-etq" for="prRadio">Esquinas redondeadas</label>
                <input type="range" class="form-range" id="prRadio" min="0" max="200" step="2">
            </div>
        </section>

        <section data-para="icono" hidden>
            <h2>Icono</h2>
            <span class="ed-etq">Color</span>
            <div class="ed-paleta" data-paleta="relleno"></div>
        </section>

        <section data-para="imagen" hidden>
            <h2>Foto</h2>
            <span class="ed-etq">Forma</span>
            <div class="ed-botonera">
                <button type="button" data-mascara="ninguna" title="Sin recorte" aria-label="Sin recorte"><i class="far fa-square" aria-hidden="true"></i></button>
                <button type="button" data-mascara="redonda" title="Esquinas redondeadas" aria-label="Esquinas redondeadas"><i class="fas fa-square" style="border-radius: 6px; overflow: hidden" aria-hidden="true"></i></button>
                <button type="button" data-mascara="circulo" title="En círculo" aria-label="En círculo"><i class="fas fa-circle" aria-hidden="true"></i></button>
            </div>
            <div class="form-check form-switch ed-interruptor">
                <input class="form-check-input" type="checkbox" role="switch" id="prByN">
                <label class="form-check-label" for="prByN">Blanco y negro</label>
            </div>
            <label class="ed-etq" for="prBrillo">Brillo</label>
            <input type="range" class="form-range" id="prBrillo" min="-60" max="60" step="2">
            <label class="ed-etq" for="prContraste">Contraste</label>
            <input type="range" class="form-range" id="prContraste" min="-60" max="60" step="2">
            <label class="ed-etq" for="prDesenfoque">Desenfoque</label>
            <input type="range" class="form-range" id="prDesenfoque" min="0" max="100" step="2">
            <button type="button" class="ed-btn ed-btn-ancho" id="prComoFondo"><i class="fas fa-panorama" aria-hidden="true"></i> Usar como fondo</button>
        </section>

        <section data-para="objeto" hidden>
            <h2>Posición y efectos</h2>
            <label class="ed-etq" for="prOpacidad">Transparencia</label>
            <input type="range" class="form-range" id="prOpacidad" min="5" max="100" step="5">
            <div class="form-check form-switch ed-interruptor">
                <input class="form-check-input" type="checkbox" role="switch" id="prSombra">
                <label class="form-check-label" for="prSombra">Sombra</label>
            </div>
            <span class="ed-etq">Alinear en la página</span>
            <div class="ed-botonera">
                <button type="button" data-alinear="izquierda" title="A la izquierda" aria-label="Alinear a la izquierda de la página"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
                <button type="button" data-alinear="centro" title="Centrar a lo ancho" aria-label="Centrar a lo ancho"><i class="fas fa-arrows-left-right" aria-hidden="true"></i></button>
                <button type="button" data-alinear="derecha" title="A la derecha" aria-label="Alinear a la derecha de la página"><i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                <button type="button" data-alinear="arriba" title="Arriba" aria-label="Alinear arriba"><i class="fas fa-arrow-up" aria-hidden="true"></i></button>
                <button type="button" data-alinear="medio" title="Centrar a lo alto" aria-label="Centrar a lo alto"><i class="fas fa-arrows-up-down" aria-hidden="true"></i></button>
                <button type="button" data-alinear="abajo" title="Abajo" aria-label="Alinear abajo"><i class="fas fa-arrow-down" aria-hidden="true"></i></button>
            </div>
            <span class="ed-etq">Capa</span>
            <div class="ed-botonera">
                <button type="button" data-capa="frente" title="Traer al frente" aria-label="Traer al frente"><i class="fas fa-angles-up" aria-hidden="true"></i></button>
                <button type="button" data-capa="adelante" title="Subir una capa" aria-label="Subir una capa"><i class="fas fa-angle-up" aria-hidden="true"></i></button>
                <button type="button" data-capa="atras" title="Bajar una capa" aria-label="Bajar una capa"><i class="fas fa-angle-down" aria-hidden="true"></i></button>
                <button type="button" data-capa="fondo" title="Enviar al fondo" aria-label="Enviar al fondo"><i class="fas fa-angles-down" aria-hidden="true"></i></button>
                <span class="ed-separador"></span>
                <button type="button" data-voltear="x" title="Voltear a los lados" aria-label="Voltear a los lados"><i class="fas fa-left-right" aria-hidden="true"></i></button>
                <button type="button" data-voltear="y" title="Voltear de arriba abajo" aria-label="Voltear de arriba abajo"><i class="fas fa-up-down" aria-hidden="true"></i></button>
            </div>
            <div class="ed-acciones">
                <button type="button" class="ed-btn" id="prDuplicar" title="Duplicar (Ctrl + D)"><i class="far fa-clone" aria-hidden="true"></i> Duplicar</button>
                <button type="button" class="ed-btn" id="prBloquear"><i class="fas fa-lock" aria-hidden="true"></i> Bloquear</button>
                <button type="button" class="ed-btn" id="prAgrupar" hidden><i class="far fa-object-group" aria-hidden="true"></i> Agrupar</button>
                <button type="button" class="ed-btn" id="prDesagrupar" hidden><i class="far fa-object-ungroup" aria-hidden="true"></i> Desagrupar</button>
                <button type="button" class="ed-btn ed-btn-peligro" id="prEliminar" title="Eliminar (Supr)"><i class="far fa-trash-can" aria-hidden="true"></i> Eliminar</button>
            </div>
        </section>
    </aside>
</div>

<div class="ed-aviso" id="edAviso" role="status" aria-live="polite" hidden></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script>
window.EDITOR_DATOS = <?= jsJson(['publicaciones' => $publicaciones, 'logo' => '../img/logo.png', 'pie' => pieAfiches()]) ?>;
</script>
<script src="../js/afiches.js?v=3"></script>
<script src="../js/editor_afiches.js?v=2"></script>
</body>
</html>
