<?php
/**
 * CONTENIDO PARA REDES SOCIALES
 * Lo que alimenta el módulo "Redes Sociales" del panel (admin/redes_sociales.php):
 *   1. galeriaAfiches()        las fotos que se pueden poner en un afiche (equipo, proyectos y productos de la tienda).
 *   2. publicacionesListas()   las piezas ya diseñadas, cada una con su texto para publicar y el día sugerido.
 *   3. respuestasAutomaticas() los mensajes que se pegan en las respuestas automáticas de cada red.
 *
 * Los afiches no se guardan como imágenes: cada publicación es un "diseño" (plantilla, colores y textos) que el
 * navegador dibuja con js/afiches.js. Por eso los precios y las fotos de la tienda salen siempre al día.
 *
 * Dato opcional del .env:
 *   REDES_ENLACE=https://tu-dominio.com/   dirección que se escribe en los textos (por defecto, la del sitio).
 */
require_once __DIR__ . '/redes.php';
require_once __DIR__ . '/tienda.php';
require_once __DIR__ . '/contabilidad.php';

// Las imágenes de la tienda traen su propio rótulo y el logo en la franja de abajo (los pone deploy/herramientas/tienda_imagenes.py).
// En un afiche sobrarían, porque el afiche ya lleva logo: se descarta esa fracción de la imagen.
const RECORTE_FOTO_TIENDA = 0.235;

// Dirección del sitio que va en los textos de las publicaciones (con "/" final)
function enlaceRedes() {
    $enlace = trim((string) ($_ENV['REDES_ENLACE'] ?? ''));
    return preg_match('#^https?://#i', $enlace) ? rtrim($enlace, '/') . '/' : urlBase();
}

// Lo que va en el pie de cada afiche: la cuenta de Instagram si está configurada; si no, el nombre de la empresa
function pieAfiches() {
    $instagram = trim((string) ($_ENV['SOCIAL_INSTAGRAM'] ?? ''));
    if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $instagram, $m)) {
        return 'Escríbenos · @' . $m[1];
    }
    return 'DataSense Analytics Insights';
}

// Nombre de un producto sin la duración entre paréntesis ("Curso Power BI (40 h)" => "Curso Power BI")
function nombreParaAfiche($nombre) {
    return trim(preg_replace('/\s*\(\s*\d+\s*h(oras)?\s*\)/iu', '', (string) $nombre));
}

/**
 * Fotos disponibles para los afiches, por grupos. Las rutas son relativas a la carpeta admin/.
 */
function galeriaAfiches(PDO $db) {
    $galeria = [];
    $fijas = [
        ['Equipo', 'Marcela Pacheco Parra', 'img/marcela.jpg'],
        ['Equipo', 'Luis Guillermo Nieto Patiño', 'img/luis.jpg'],
        ['Equipo', 'Marcela y Luis, fundadores', 'img/equipo/fundadores.jpg'],
        ['Equipo', 'Equipo DataSense', 'img/equipo/equipo-datasense.jpg'],
        ['Reconocimientos', 'Primer lugar Talento Tech 2025', 'img/proyectos/talento-tech-primer-lugar.jpg'],
        ['Reconocimientos', 'Premio Alfonso Palacio Rudas 2026', 'img/proyectos/premio-alfonso-palacio-rudas.jpg'],
        ['Proyectos', 'CuentaVoz · inventario por voz', 'img/proyectos/cuentavoz.jpg'],
        ['Proyectos', 'Tour Mountain Adventure', 'img/proyectos/tour-mountain-adventure.jpg'],
        ['Proyectos', 'Plataforma de gestión DataSense', 'img/proyectos/plataforma-datasense.jpg'],
        ['Proyectos', 'Tablero de indicadores', 'img/proyectos/colpensiones-fraude.jpg'],
    ];
    foreach ($fijas as [$grupo, $nombre, $ruta]) {
        if (is_file(__DIR__ . '/../' . $ruta)) {
            $galeria[] = ['grupo' => $grupo, 'nombre' => $nombre, 'url' => '../' . $ruta];
        }
    }
    if (tiendaDisponible($db)) {
        foreach (productosTienda($db) as $p) {
            if (empty($p['imagen']) || !is_file(__DIR__ . '/../img/productos/' . $p['imagen'])) continue;
            $galeria[] = ['grupo' => 'Tienda', 'nombre' => nombreParaAfiche($p['nombre']), 'url' => '../img/productos/' . $p['imagen'], 'recorte' => RECORTE_FOTO_TIENDA];
        }
    }
    return $galeria;
}

/**
 * Publicaciones listas: el diseño del afiche y el texto para acompañarlo, en el orden en que conviene publicarlos.
 * Las de la tienda se arman con el producto real (nombre, precio, descuento y foto); si el producto no existe, no salen.
 */
function publicacionesListas(PDO $db) {
    $sitio = enlaceRedes();
    $pie = pieAfiches();
    $todas = ['Facebook', 'Instagram', 'LinkedIn'];

    // En el orden en que se publican. Las fechas las pone el plan de la página (días de la semana y hora que elija la persona).
    // Las historias van aparte: en la mayoría de cuentas no se pueden programar y se suben el día que se quiera.
    // Las palabras en MAYÚSCULAS que se piden comentar (INFO, CONFERENCIA, DEMO) son las de las respuestas automáticas a comentarios.
    $lista = [
        [
            'id' => 'marca', 'redes' => $todas, 'tema_contenido' => 'Presentación',
            'diseno' => ['plantilla' => 'texto', 'formato' => 'cuadrado', 'tema' => 'azul',
                'etiqueta' => 'DataSense Analytics Insights',
                'titulo' => 'Transformamos datos en *decisiones inteligentes*',
                'texto' => 'Analítica, ciencia de datos y software a la medida para organizaciones públicas y privadas.',
                'puntos' => ['Business Intelligence y tableros gerenciales', 'Ciencia de datos y analítica avanzada', 'Desarrollo de software a la medida'],
                'boton' => 'Solicita tu asesoría gratuita'],
            'texto' => "¿Tus datos ya trabajan para ti? 📊\n\nEn DataSense Analytics Insights convertimos la información de tu organización en decisiones claras: tableros gerenciales, analítica avanzada y software a la medida.\n\n💬 Comenta INFO y te escribimos por mensaje privado, o agenda tu asesoría gratuita 👉 {$sitio}#contacto",
            'hashtags' => '#AnaliticaDeDatos #BusinessIntelligence #TransformacionDigital #DataSense',
        ],
        [
            'id' => 'tableros', 'redes' => $todas, 'tema_contenido' => 'Servicio',
            'producto' => 'PBI-VEN-01',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'cuadrado', 'tema' => 'noche',
                'etiqueta' => 'Business Intelligence',
                'titulo' => 'Tableros que tu gerencia *sí usa*',
                'texto' => 'Indicadores claros y actualizados, conectados a tus fuentes de datos.',
                'puntos' => ['Ventas, finanzas y operación en un solo lugar', 'Conexión a Excel, ERP o bases de datos'],
                'boton' => 'Hablemos de tus datos'],
            'texto' => "Un buen tablero no es el que tiene más gráficos: es el que responde las preguntas de la gerencia. 📈\n\nDiseñamos e implementamos tableros de control, indicadores clave (KPI) y reportes ejecutivos para que el seguimiento sea sencillo y las decisiones lleguen a tiempo.\n\n👇 ¿Cuál es el primer indicador que revisas cada lunes? Te leemos en los comentarios.\n\nConversemos sobre los indicadores de tu organización 👉 {$sitio}#servicios",
            'hashtags' => '#BusinessIntelligence #PowerBI #KPI #Tableros #DataSense',
        ],
        [
            'id' => 'conferencia', 'redes' => $todas, 'tema_contenido' => 'Conferencias',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'vertical', 'tema' => 'verde', 'imagen' => '../img/marcela.jpg',
                'etiqueta' => 'Conferencias corporativas',
                'titulo' => 'Bienestar y *Desarrollo Humano* para equipos que crecen',
                'texto' => 'Con Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano (FLACSO).',
                'puntos' => ['Bienestar y trabajo en equipo', 'Psicología de pareja', 'Orientación vocacional'],
                'boton' => 'Solicita tu propuesta'],
            'texto' => "Los equipos que se sienten bien, trabajan mejor. 🌱\n\nMarcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano, con trayectoria en Prosperidad Social, la gestión del riesgo de desastres y el bienestar universitario, lleva a tu organización conferencias y talleres sobre bienestar y salud mental, trabajo en equipo, psicología de pareja, orientación vocacional y desarrollo humano.\n\nPresencial o virtual, para empresas, instituciones educativas y entidades.\n\n💬 Comenta CONFERENCIA y te enviamos la propuesta por mensaje privado 👉 {$sitio}#conferencias",
            'hashtags' => '#BienestarLaboral #DesarrolloHumano #SaludMental #Conferencias #DataSense',
        ],
        [
            'id' => 'video-pareja', 'redes' => ['Facebook', 'Instagram'], 'tema_contenido' => 'Charla de Marcela',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'cuadrado', 'tema' => 'noche', 'imagen' => '../img/marcela.jpg',
                'etiqueta' => 'Psicología de pareja',
                'titulo' => 'Los 5 lenguajes *del amor*',
                'texto' => 'Charla de Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano.',
                'boton' => 'Mírala en YouTube'],
            'texto' => "¿Sabes cuál es tu lenguaje del amor? ❤️\n\nEn esta charla, Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano, explica los 5 lenguajes del amor y cómo entenderlos mejora la comunicación en la pareja, en la familia y en el trabajo.\n\nMírala completa 👉 https://www.youtube.com/watch?v=SvjMfxZB0nA\n\n👇 ¿Cuál es el tuyo? Cuéntanos en los comentarios.\n💬 Comenta CONFERENCIA si quieres llevar esta charla a tu organización.",
            'hashtags' => '#PsicologiaDePareja #LenguajesDelAmor #DesarrolloHumano #Bienestar #DataSense',
        ],
        [
            'id' => 'ceo', 'redes' => $todas, 'tema_contenido' => 'Nuestro CEO',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'vertical', 'tema' => 'azul', 'imagen' => '../img/luis.jpg',
                'etiqueta' => 'Co-Fundador & CEO',
                'titulo' => 'Decisiones con *datos*, no con suposiciones',
                'texto' => 'Luis Guillermo Nieto Patiño, ingeniero de sistemas y Premio Alfonso Palacio Rudas 2026 de la Contraloría General de la República.',
                'puntos' => ['Estrategia de datos para tu organización', 'Tableros, analítica y software a la medida'],
                'boton' => 'Agenda una reunión'],
            'texto' => "Conoce a Luis Guillermo Nieto Patiño, Co-Fundador y CEO de DataSense Analytics Insights. 💼\n\nIngeniero de sistemas, especializado en analítica y calidad de la información y Premio Alfonso Palacio Rudas 2026 de la Contraloría General de la República, lidera la estrategia de la empresa y acompaña a organizaciones públicas y privadas a convertir sus datos en decisiones con resultados.\n\n¿Tu organización necesita ordenar sus datos o medir mejor su gestión? Agenda una reunión con él 👉 {$sitio}#contacto",
            'hashtags' => '#Liderazgo #AnaliticaDeDatos #EstrategiaDeDatos #DataSense',
        ],
        [
            'id' => 'premio-contraloria', 'redes' => $todas, 'tema_contenido' => 'Reconocimiento',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'vertical', 'tema' => 'azul', 'imagen' => '../img/luis.jpg',
                'etiqueta' => 'Premio Alfonso Palacio Rudas 2026',
                'titulo' => 'Verificar la información *también es gestión*',
                'texto' => 'Luis Guillermo Nieto Patiño recibió de la Contraloría General de la República el premio en la categoría Académico.',
                'puntos' => ['Cruce de fuentes públicas independientes', 'Hallazgos con trazabilidad documental'],
                'boton' => 'Conoce la metodología'],
            'texto' => "Un orgullo para DataSense Analytics Insights. 🏆\n\nNuestro CEO, Luis Guillermo Nieto Patiño, recibió el Premio Alfonso Palacio Rudas 2026 de la Contraloría General de la República, categoría Académico, por una metodología que aplica análisis de datos a la verificación de la información que reportan las entidades públicas: cruza fuentes independientes como SECOP II, informes de control fiscal, actos administrativos y estados financieros para detectar inconsistencias con trazabilidad documental.\n\n¿Tu entidad quiere fortalecer la calidad de su información? Hablemos 👉 {$sitio}#contacto",
            'hashtags' => '#ControlFiscal #CalidadDeDatos #SectorPublico #AnaliticaDeDatos #DataSense',
        ],
        [
            'id' => 'encuesta', 'redes' => $todas, 'tema_contenido' => 'Encuesta',
            'diseno' => ['plantilla' => 'texto', 'formato' => 'cuadrado', 'tema' => 'noche',
                'etiqueta' => 'Encuesta',
                'titulo' => '¿Cuál es tu mayor reto con *los datos*?',
                'puntos' => ['A · Los informes se arman a mano', 'B · La información está en muchos lugares', 'C · No sé qué indicadores medir', 'D · Nadie revisa los tableros'],
                'boton' => 'Responde con tu letra'],
            'texto' => "Queremos conocerte 👇\n\n¿Cuál es tu mayor reto con los datos?\nA. Los informes se arman a mano\nB. La información está en muchos lugares\nC. No sé qué indicadores medir\nD. Nadie revisa los tableros\n\nResponde con tu letra en los comentarios y te contamos cómo lo resolvemos en otras organizaciones.",
            'hashtags' => '#Encuesta #AnaliticaDeDatos #Gerencia #DataSense',
        ],
        [
            'id' => 'resena-cuentavoz', 'redes' => $todas, 'tema_contenido' => 'Reseña de cliente',
            'diseno' => ['plantilla' => 'resena', 'formato' => 'cuadrado', 'tema' => 'azul', 'imagen' => '../img/proyectos/cuentavoz.jpg',
                'etiqueta' => 'Lo que dicen nuestros clientes',
                'texto' => 'CuentaVoz cambió la forma en que contamos el inventario: el equipo dicta los conteos por voz en cada bodega y el agente nos responde al instante.',
                'autor' => 'Laura Roja', 'cargo' => 'Coordinadora de Inventario · Colsubsidio'],
            'texto' => "Gracias a Laura Roja y al equipo de inventario de Colsubsidio por su confianza. ⭐⭐⭐⭐⭐\n\nCon CuentaVoz los conteos se dictan por voz en cada bodega y un agente inteligente responde al instante, con alertas y tiempos de conteo en un panel gerencial.\n\n¿Cómo cuentan el inventario en tu empresa? Cuéntanos 👇\n\nConoce el proyecto 👉 {$sitio}#proyectos",
            'hashtags' => '#CasosDeExito #Inventario #InteligenciaArtificial #DataSense',
        ],
        [
            'id' => 'tienda-curso', 'redes' => $todas, 'tema_contenido' => 'Tienda',
            'producto' => 'CUR-PBI-01', 'oferta' => true,
            'diseno' => ['plantilla' => 'oferta', 'formato' => 'cuadrado', 'tema' => 'noche', 'boton' => 'Inscríbete en línea'],
            'hashtags' => '#PowerBI #Cursos #AnaliticaDeDatos #DataSense',
        ],
        [
            'id' => 'fundadores', 'redes' => $todas, 'tema_contenido' => 'Quiénes somos',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'cuadrado', 'tema' => 'verde', 'imagen' => '../img/equipo/fundadores.jpg',
                'etiqueta' => 'Quiénes somos',
                'titulo' => 'Psicología y analítica de datos *en un mismo equipo*',
                'texto' => 'Marcela Pacheco Parra, Co-Fundadora y CTO, y Luis Guillermo Nieto Patiño, Co-Fundador y CEO.',
                'boton' => 'Conócenos'],
            'texto' => "Detrás de DataSense hay dos fundadores con una misma convicción: las organizaciones crecen cuando cuidan a su gente y deciden con datos. 🤝\n\nMarcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano (FLACSO), y Luis Guillermo Nieto Patiño, candidato a magíster en Analítica de Datos, unen el desarrollo humano y la analítica para acompañar a empresas y entidades.\n\n¿Qué te gustaría preguntarles? Déjalo en los comentarios 👇\n\nConócenos 👉 {$sitio}#quienes",
            'hashtags' => '#Emprendimiento #DesarrolloHumano #AnaliticaDeDatos #DataSense',
        ],
        [
            'id' => 'senales', 'redes' => $todas, 'tema_contenido' => 'Contenido de valor',
            'diseno' => ['plantilla' => 'texto', 'formato' => 'vertical', 'tema' => 'claro',
                'etiqueta' => 'Guía rápida',
                'titulo' => '4 señales de que necesitas un *tablero de indicadores*',
                'puntos' => ['Los informes se arman a mano en Excel', 'Cada área maneja cifras distintas', 'Las decisiones esperan al cierre del mes', 'No sabes qué producto o cliente deja más margen'],
                'boton' => 'Hablemos de tus datos'],
            'texto' => "¿Te suena alguna? 👇\n\n1. Los informes se arman a mano en Excel.\n2. Cada área maneja cifras distintas.\n3. Las decisiones esperan al cierre del mes.\n4. No sabes qué producto o cliente deja más margen.\n\nResponde con el número que más te pasa. Si marcaste dos o más, es momento de tener tus indicadores en un solo tablero, siempre al día.\n\n💬 Comenta INFO y te orientamos sin costo 👉 {$sitio}#contacto",
            'hashtags' => '#Indicadores #KPI #AnaliticaDeDatos #Gerencia #DataSense',
        ],
        [
            'id' => 'sector-publico', 'redes' => ['LinkedIn', 'Facebook'], 'tema_contenido' => 'Sector público',
            'producto' => 'PBI-PUB-03',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'horizontal', 'tema' => 'azul',
                'etiqueta' => 'Analítica para el sector público',
                'titulo' => 'Transparencia que la ciudadanía *entiende*',
                'texto' => 'Ejecución presupuestal, contratación y metas en un tablero listo para publicar.',
                'boton' => 'Conoce cómo'],
            'texto' => "Rendir cuentas también es cuestión de claridad. 🏛️\n\nConstruimos tableros de ejecución presupuestal, contratación y metas del plan de desarrollo, listos para publicar en la web de la entidad y fáciles de entender para la ciudadanía.\n\n¿Tu entidad ya publica sus datos así? Cuéntanos en los comentarios.\n\nConoce nuestras soluciones para entidades públicas 👉 {$sitio}#proyectos",
            'hashtags' => '#SectorPublico #Transparencia #GobiernoAbierto #AnaliticaDeDatos #DataSense',
        ],
        [
            'id' => 'tienda-bienestar', 'redes' => ['Facebook', 'Instagram'], 'tema_contenido' => 'Tienda',
            'producto' => 'BIO-KIT-08', 'oferta' => true,
            'diseno' => ['plantilla' => 'oferta', 'formato' => 'vertical', 'tema' => 'verde', 'boton' => 'Compra en línea'],
            'hashtags' => '#Bienestar #VidaSaludable #TiendaEnLinea #DataSense',
        ],
        [
            'id' => 'resena-tour', 'redes' => $todas, 'tema_contenido' => 'Reseña de cliente',
            'diseno' => ['plantilla' => 'resena', 'formato' => 'cuadrado', 'tema' => 'verde', 'imagen' => '../img/proyectos/tour-mountain-adventure.jpg',
                'etiqueta' => 'Lo que dicen nuestros clientes',
                'texto' => 'DataSense nos dio una plataforma a la altura de una empresa: los viajeros consultan las caminatas y el calendario de salidas, las empresas cotizan sus salidas en línea y dejamos atrás el trabajo manual de responder y organizar cada solicitud.',
                'autor' => 'Andrés Soto', 'cargo' => 'Gerente · Tour Mountain Adventure'],
            'texto' => "Gracias a Andrés Soto y a Tour Mountain Adventure por su confianza. ⭐⭐⭐⭐⭐\n\nHoy los viajeros consultan las caminatas y el calendario de salidas, y las empresas cotizan en línea, sin el trabajo manual de responder cada solicitud.\n\n¿Qué proceso de tu empresa te gustaría dejar de hacer a mano? Te leemos 👇\n\nConoce el proyecto 👉 {$sitio}#proyectos",
            'hashtags' => '#CasosDeExito #Turismo #DesarrolloWeb #DataSense',
        ],
        [
            'id' => 'plataforma', 'redes' => $todas, 'tema_contenido' => 'Proyecto',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'cuadrado', 'tema' => 'azul', 'imagen' => '../img/proyectos/plataforma-datasense.jpg',
                'etiqueta' => 'Plataforma de gestión',
                'titulo' => 'Una sola plataforma para *operar tu empresa*',
                'texto' => 'Mesa de servicios, PQRSD, facturación, contabilidad y tienda en línea.',
                'boton' => 'Solicita una demostración'],
            'texto' => "Menos sistemas sueltos, más control. ⚙️\n\nNuestra plataforma de gestión reúne mesa de servicios, canal de PQRSD, facturación, contabilidad, pagos y tienda en línea, con permisos por rol.\n\n💬 Comenta DEMO y te enviamos el enlace para conocerla por dentro 👉 {$sitio}#proyectos",
            'hashtags' => '#Plataforma #GestionEmpresarial #TransformacionDigital #DataSense',
        ],
        [
            'id' => 'software', 'redes' => $todas, 'tema_contenido' => 'Servicio',
            'diseno' => ['plantilla' => 'texto', 'formato' => 'cuadrado', 'tema' => 'claro',
                'etiqueta' => 'Desarrollo de software',
                'titulo' => 'Software *a la medida* de tu operación',
                'texto' => 'Dejamos atrás el trabajo manual con sistemas hechos para tu forma de trabajar.',
                'puntos' => ['APIs, bases de datos e integraciones entre sistemas', 'Aplicaciones web responsivas y móviles'],
                'boton' => 'Cuéntanos tu proyecto'],
            'texto' => "Cuando el proceso no cabe en una hoja de cálculo, necesita su propio sistema. 💻\n\nDesarrollamos software a la medida de tu operación: APIs, bases de datos, integraciones entre sistemas y aplicaciones web responsivas y móviles.\n\n¿Qué tarea repetitiva te gustaría automatizar? Escríbela en los comentarios 👇\n\nCuéntanos tu proyecto 👉 {$sitio}#contacto",
            'hashtags' => '#DesarrolloDeSoftware #SoftwareALaMedida #Automatizacion #DataSense',
        ],
        [
            'id' => 'tienda-plantilla', 'redes' => $todas, 'tema_contenido' => 'Tienda',
            'producto' => 'XLS-KPI-04', 'oferta' => true,
            'diseno' => ['plantilla' => 'oferta', 'formato' => 'cuadrado', 'tema' => 'azul', 'boton' => 'Compra en línea'],
            'hashtags' => '#KPI #Excel #Indicadores #Plantillas #DataSense',
        ],
        [
            'id' => 'cuentavoz', 'redes' => $todas, 'tema_contenido' => 'Proyecto',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'cuadrado', 'tema' => 'noche', 'imagen' => '../img/proyectos/cuentavoz.jpg',
                'etiqueta' => 'Proyecto · Colsubsidio',
                'titulo' => 'CuentaVoz: inventario *por voz* con agente inteligente',
                'texto' => 'Conteos dictados en bodega, respuestas al instante y un panel gerencial con alertas.',
                'boton' => 'Conoce el proyecto'],
            'texto' => "¿Y si el inventario se contara hablando? 🎙️\n\nCuentaVoz es la aplicación que construimos para contar inventario por voz: el equipo dicta los conteos en cada bodega, un agente inteligente responde preguntas al instante y la gerencia ve alertas y tiempos de conteo en su panel.\n\n💬 Comenta INFO si quieres algo así para tu operación.\n\nMira el recorrido 👉 {$sitio}#proyectos",
            'hashtags' => '#InteligenciaArtificial #Inventario #Logistica #DataSense',
        ],
        [
            'id' => 'asesoria', 'redes' => ['Instagram', 'Facebook', 'WhatsApp'], 'tema_contenido' => 'Llamado a contactar',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'historia', 'tema' => 'verde', 'imagen' => '../img/luis.jpg',
                'etiqueta' => 'Asesoría gratuita',
                'titulo' => '¿Tus datos ya *trabajan para ti*?',
                'texto' => 'Agenda una conversación con nuestro CEO y recibe una primera orientación sin costo.',
                'boton' => 'Escríbenos hoy'],
            'texto' => "Agenda una conversación con nuestro CEO, Luis Guillermo Nieto Patiño, y recibe una primera orientación sin costo. Te respondemos en menos de 24 horas 👉 {$sitio}#contacto",
            'hashtags' => '#AsesoriaGratuita #AnaliticaDeDatos #DataSense',
        ],
        [
            'id' => 'historia-conferencia', 'redes' => ['Instagram', 'Facebook', 'WhatsApp'], 'tema_contenido' => 'Conferencias',
            'diseno' => ['plantilla' => 'foto', 'formato' => 'historia', 'tema' => 'azul', 'imagen' => '../img/marcela.jpg',
                'etiqueta' => 'Conferencias corporativas',
                'titulo' => 'Lleva a Marcela a *tu organización*',
                'texto' => 'Bienestar, trabajo en equipo, pareja y orientación vocacional. Presencial o virtual.',
                'boton' => 'Pide tu propuesta'],
            'texto' => "Conferencias y talleres de bienestar y desarrollo humano con Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano. Pide tu propuesta 👉 {$sitio}#conferencias",
            'hashtags' => '#BienestarLaboral #Conferencias #DataSense',
        ],
    ];

    // Productos que usan las publicaciones, buscados una sola vez
    $por_sku = [];
    if (tiendaDisponible($db)) {
        foreach (productosTienda($db) as $p) $por_sku[$p['sku']] = $p;
    }

    $salida = [];
    foreach ($lista as $pub) {
        $d = $pub['diseno'] + ['pie' => $pie];
        if (!empty($pub['producto'])) {
            $p = $por_sku[$pub['producto']] ?? null;
            if (!$p) continue;
            if (!empty($p['imagen'])) {
                $d['imagen'] = '../img/productos/' . $p['imagen'];
                $d['imagenRecorte'] = RECORTE_FOTO_TIENDA;
            }
            if (!empty($pub['oferta'])) {
                $nombre = nombreParaAfiche($p['nombre']);
                $descuento = descuentoProducto($p);
                $d += [
                    'etiqueta' => (string) ($p['categoria'] ?? 'Tienda DataSense'),
                    'titulo' => $nombre,
                    'texto' => (string) $p['resumen'],
                    'precio' => dinero($p['precio']),
                    'precioAntes' => $descuento > 0 ? dinero($p['precio_anterior']) : '',
                    'insignia' => $descuento > 0 ? '-' . $descuento . '%' : '',
                ];
                $enlace = $sitio . 'producto.php?p=' . rawurlencode($p['slug']);
                $ahorro = $descuento > 0 ? " Hoy con {$descuento} % de descuento." : '';
                $pub['texto'] = "{$nombre} 🛒\n\n{$p['resumen']}{$ahorro}\n\nCompra en línea con PSE, tarjetas, Nequi o PayPal y recibe tu factura al instante 👉 {$enlace}\n\n💬 ¿Tienes dudas? Comenta INFO y te escribimos.";
            }
        }
        $salida[] = [
            'id' => $pub['id'], 'redes' => $pub['redes'], 'tema' => $pub['tema_contenido'],
            'historia' => ($d['formato'] ?? '') === 'historia',
            'diseno' => $d, 'texto' => $pub['texto'] . "\n\n" . $pub['hashtags'],
        ];
    }
    return $salida;
}

/**
 * Mensajes para las respuestas automáticas. 'donde' dice en qué herramienta se pega cada uno.
 */
function respuestasAutomaticas() {
    $sitio = enlaceRedes();
    $horario = 'lunes a viernes, de 8:00 a.m. a 5:00 p.m.';
    $envio_gratis = dinero(TIENDA_ENVIO_GRATIS_DESDE);

    return [
        'principales' => [
            [
                'id' => 'saludo', 'titulo' => 'Respuesta instantánea', 'icono' => 'fa-bolt',
                'cuando' => 'Se envía sola en cuanto alguien te escribe por primera vez.',
                'donde' => ['Facebook', 'Instagram', 'WhatsApp'],
                'texto' => "¡Hola! 👋 Gracias por escribir a DataSense Analytics Insights.\n\nYa recibimos tu mensaje y un asesor te responderá en menos de 24 horas ({$horario}).\n\nPara atenderte más rápido, cuéntanos qué te interesa:\n1. Analítica de datos y tableros\n2. Desarrollo de software\n3. Conferencias corporativas\n4. Tienda en línea",
            ],
            [
                'id' => 'ausencia', 'titulo' => 'Mensaje de ausencia', 'icono' => 'fa-moon',
                'cuando' => 'Se envía cuando escriben fuera del horario de atención.',
                'donde' => ['Facebook', 'Instagram', 'WhatsApp'],
                'texto' => "¡Hola! Gracias por escribirnos. 🌙\n\nEn este momento estamos fuera de nuestro horario de atención ({$horario}, hora de Colombia).\n\nTu mensaje quedó registrado y te responderemos el siguiente día hábil. Si prefieres, déjanos tu nombre, tu empresa y un teléfono, o escríbenos desde {$sitio}#contacto",
            ],
            [
                'id' => 'comentarios', 'titulo' => 'Respuesta a comentarios', 'icono' => 'fa-comment-dots',
                'cuando' => 'Para contestar a quien pregunta en los comentarios de una publicación.',
                'donde' => ['Facebook', 'Instagram', 'LinkedIn'],
                'texto' => "¡Gracias por tu interés! 🙌 Te escribimos por mensaje privado con toda la información.",
            ],
            [
                'id' => 'seguimiento', 'titulo' => 'Seguimiento', 'icono' => 'fa-reply',
                'cuando' => 'Para retomar una conversación que quedó sin respuesta del cliente.',
                'donde' => ['Facebook', 'Instagram', 'LinkedIn', 'WhatsApp'],
                'texto' => "¡Hola de nuevo! Queríamos saber si pudiste revisar la información que te enviamos. Si te parece, agendamos una reunión corta para resolver tus dudas y armar la propuesta a tu medida. ¿Qué día te queda bien?",
            ],
        ],
        // Respuesta privada a quien comente la palabra en una publicación (las publicaciones la piden)
        'palabras' => [
            [
                'id' => 'palabra-info', 'palabra' => 'INFO', 'titulo' => 'Información general',
                'texto' => "¡Hola! Gracias por comentar 🙌\n\nSomos DataSense Analytics Insights: analítica de datos, tableros de indicadores, software a la medida y conferencias corporativas.\n\nCuéntanos qué necesita tu organización y un asesor te responde en menos de 24 horas. También puedes agendar tu asesoría gratuita aquí: {$sitio}#contacto",
            ],
            [
                'id' => 'palabra-conferencia', 'palabra' => 'CONFERENCIA', 'titulo' => 'Conferencias de Marcela',
                'texto' => "¡Hola! Gracias por tu interés en las conferencias de Marcela Pacheco Parra 🌱\n\nPara enviarte la propuesta, cuéntanos:\n• Fecha tentativa\n• Número de asistentes\n• Ciudad, o si es virtual\n• Tema: bienestar y salud mental, trabajo en equipo, psicología de pareja, orientación vocacional o desarrollo humano\n\nMás información: {$sitio}#conferencias",
            ],
            [
                'id' => 'palabra-demo', 'palabra' => 'DEMO', 'titulo' => 'Demostración de la plataforma',
                'texto' => "¡Hola! Gracias por comentar ⚙️\n\nTe mostramos la plataforma de gestión en una demostración guiada por videollamada: mesa de servicios, PQRSD, facturación, contabilidad y tienda en línea.\n\nIndícanos dos opciones de día y hora ({$horario}) y te enviamos el enlace. También puedes escribirnos desde {$sitio}#contacto",
            ],
        ],
        'frecuentes' => [
            [
                'id' => 'servicios', 'atajo' => '/servicios', 'pregunta' => '¿Qué servicios ofrecen?',
                'texto' => "Trabajamos en cuatro frentes:\n• Business Intelligence y tableros de indicadores\n• Ciencia de datos y analítica para el sector público\n• Desarrollo de software a la medida\n• Conferencias de psicología y desarrollo humano\n\nConócelos en {$sitio}#servicios",
            ],
            [
                'id' => 'precios', 'atajo' => '/precios', 'pregunta' => '¿Cuánto cuesta? ¿Cómo cotizo?',
                'texto' => "Cada proyecto se cotiza según su alcance. Cuéntanos qué necesitas, qué tipo de organización eres y para cuándo lo requieres, y te enviamos una propuesta. La primera asesoría es gratuita: {$sitio}#contacto",
            ],
            [
                'id' => 'conferencias', 'atajo' => '/conferencias', 'pregunta' => 'Quiero una conferencia para mi empresa',
                'texto' => "¡Con gusto! Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano, dicta conferencias y talleres sobre bienestar y salud mental, trabajo en equipo, psicología de pareja, orientación vocacional y desarrollo humano, en modalidad presencial o virtual.\n\nIndícanos la fecha tentativa, el número de asistentes y la ciudad para enviarte la propuesta. Más información: {$sitio}#conferencias",
            ],
            [
                'id' => 'tienda', 'atajo' => '/tienda', 'pregunta' => '¿Cómo compro en la tienda?',
                'texto' => "Puedes comprar en línea en {$sitio}tienda.php. Recibimos PSE, tarjetas, Nequi y PayPal, y recibes tu factura en cada compra. El envío es gratis desde {$envio_gratis}.",
            ],
            [
                'id' => 'reunion', 'atajo' => '/reunion', 'pregunta' => '¿Podemos tener una reunión?',
                'texto' => "¡Claro! Atendemos de forma remota y presencial. Indícanos dos opciones de día y hora ({$horario}) y te enviamos el enlace de la reunión.",
            ],
            [
                'id' => 'pqrsd', 'atajo' => '/pqrsd', 'pregunta' => 'Tengo una petición, queja o reclamo',
                'texto' => "Puedes radicarla en nuestro canal de PQRSD: {$sitio}pqrsd.php. Recibirás un número de radicado para hacer seguimiento y te respondemos dentro de los términos de ley.",
            ],
        ],
    ];
}
