<?php
/* ================= SESIÓN Y TOKEN CSRF ================= */
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/redes.php'; // tarjeta al compartir, iconos de las redes y botones de compartir
iniciarSesionSegura();

if (empty($_SESSION['token'])) {
  $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Antispam: contacto.php rechaza envíos hechos en menos de 3 segundos desde que se cargó el formulario
$_SESSION['form_contacto_desde'] = time();

/* Proyectos del portafolio de Luis Guillermo Nieto (portafolio-guillermo-nieto.onrender.com), con sus imágenes originales.
   Aparecen después de los proyectos principales; en "Todos" se muestran al tocar "Ver más proyectos".
   [imagen en img/proyectos/portafolio, áreas del filtro, etiqueta del área, título, descripción, tecnologías, página del portafolio, servicio del formulario] */
$portafolio_luis = [
    ['arquitectura-datos.jpg', 'analitica', 'Analítica y BI', 'Arquitectura de inteligencia de datos',
     'Flujo de datos completo, de la fuente a la decisión: extracción desde bases de datos, APIs y archivos; orquestación y validación en Python; limpieza, deduplicación y enriquecimiento; modelo dimensional y datos maestros; y paneles interactivos con indicadores.',
     'Python · Data Science · Big Data', 'arquitectura-de-inteligencia-de-datos', 'BI'],
    ['marketing-analytics.jpg', 'analitica diseno', 'Analítica de marketing', 'Marketing Analytics: inteligencia de datos',
     'Tablero que reúne el rendimiento de las campañas, el embudo de conversión etapa por etapa, la audiencia por edad, el alcance geográfico y el reparto por canal (búsqueda, redes, display, correo y video), para decidir dónde invertir con cifras.',
     'Analítica · Marketing digital', 'marketing-analytics-inteligencia-de-datos', 'BI'],
    ['centro-operaciones.jpg', 'analitica software', 'Analítica y automatización', 'Centro de operaciones y automatización administrativa',
     'Automatización de la operación administrativa de principio a fin —captura de datos, tareas repetitivas, correo y atención al cliente— con un tablero de volumen de solicitudes, nivel de servicio, satisfacción y tiempos de respuesta.',
     'Excel avanzado · Automatización', 'centro-de-operaciones-y-automatizacion-administrativa', 'BI'],
    ['salud-eps.jpg', 'software', 'Desarrollo de software', 'Salud+ EPS: plataforma de servicios de salud',
     'Portal para afiliados: citas médicas, odontológicas y virtuales, resultados de laboratorio, certificaciones y autorizaciones, historial, directorio de profesionales, grupo familiar, y pagos y facturación.',
     'PHP · WordPress', 'salud-eps-plataforma-de-gestion-de-servicios-de-salud', 'DSW'],
    ['droguerias-app.jpg', 'software', 'Desarrollo de software', 'DrogueriasApp: gestión integral para droguerías',
     'Ventas y facturación rápida, inventario y existencias, clientes, proveedores y compras, y reportes con estadísticas, con roles y permisos, respaldo automático y acceso web seguro.',
     'Aplicación web · Diseño adaptable', 'drogueriasapp-sistema-de-gestion-integral-para-droguerias', 'DSW'],
    ['agrosmartic.jpg', 'software analitica', 'Desarrollo de software', 'AgroSmartic: gestión agrícola inteligente',
     'Cultivos y parcelas, alertas climáticas, demanda y precios de mercado, reportes y analítica, e indicadores de sostenibilidad y uso de recursos, en escritorio, tableta y celular.',
     'Python · Flutter', 'agrosmartic-plataforma-inteligente-para-gestion-agricola', 'DSW'],
    ['ayuda-pev.jpg', 'software humano', 'Tecnología con impacto social', 'AYUDA (PEV): prevención y erradicación de la violencia',
     'Plataforma web y móvil de apoyo a víctimas: denuncia anónima con seguimiento del caso, línea de atención, directorio de centros de ayuda y refugios, asesoría legal y psicológica, y guía de derechos.',
     'Python · Flutter', 'ayuda-pev-plataforma-integral-para-la-prevencion-y-erradicacion-de-la-violencia', 'DSW'],
    ['store-fashion.jpg', 'software diseno', 'Comercio electrónico', 'Store Fashion Colombia: tienda en línea con panel de gestión',
     'Tienda en línea con panel propio en tiempo real: usuarios, clientes, productos y pedidos, ventas mensuales, reparto por método de pago y productos más vendidos.',
     'WordPress · Comercio electrónico', 'store-fashion-colombia-e-commerce-infantil-y-gestion', 'DSW'],
    ['tiendita.jpg', 'software diseno', 'Comercio electrónico', 'Tiendita: tienda en línea de productos frescos',
     'Catálogo por categorías con envío a todo el país, ofertas, precios por peso, carrito, ubicación de entrega y pago seguro, en web y celular.',
     'MySQL · Diseño de interfaces', 'tiendita-e-commerce-de-productos-frescos', 'DSW'],
    ['gestion-comercial-almacen.jpg', 'diseno software', 'Diseño de interfaces', 'Sistema de gestión comercial y almacén',
     'Rediseño de la interfaz de un sistema de almacén y punto de venta: artículos, compras con detalle por línea, proveedores, ventas, reportes, y usuarios y roles con sus permisos.',
     'Diseño UI/UX', 'sistema-de-gestion-comercial-y-almacen-rediseno-ui-ux', 'DSW'],
    ['innovate-tech.jpg', 'diseno', 'Identidad de marca', 'Innovate Tech: identidad visual',
     'Identidad visual completa para una empresa de tecnología: símbolo, paleta de color, tipografía, iconografía y aplicación de la marca en soportes digitales e impresos.',
     'Branding · Adobe Photoshop', 'innovate-tech-identidad-y-branding-visual', 'DSW'],
    ['identidad-tecnologicas.jpg', 'diseno', 'Identidad de marca', 'Identidad visual de perfil tecnológico',
     'Símbolo construido sobre un trazado de circuito con una figura humana en el centro, con sus variantes de color y versiones para fondo claro y oscuro.',
     'Branding · Adobe Photoshop', 'diseno-de-identidad-visual-tecnologicas', 'DSW'],
    ['marketing-omnicanal.jpg', 'diseno', 'Marketing', 'Estrategia de marketing omnicanal',
     'Un mismo mensaje en todos los canales —sitio web, correo, redes sociales, móvil, eventos y comunicación interna— con objetivos, audiencias y una secuencia de planificación, producción, lanzamiento, optimización y crecimiento.',
     'Marketing digital', 'estrategia-de-marketing-omnicanal', 'DSW'],
    ['livestream-pro.jpg', 'diseno', 'Contenido digital', 'LiveStream Pro: producción de transmisiones en vivo',
     'Edición en tiempo real, cambio de cámaras, escenas y superposiciones, emisión simultánea en varias plataformas y seguimiento de audiencia, alcance e interacciones durante la transmisión.',
     'Marketing digital · Google Analytics', 'livestream-pro-gestion-y-produccion-de-contenido', 'DSW'],
    ['ecosistema-digital.jpg', 'diseno software', 'Presencia digital', 'Ecosistema digital multicanal',
     'Sitio web, versión móvil y gestión de contenidos sobre una misma base de información, para que un cambio se refleje en todos los canales a la vez.',
     'WordPress', 'ecosistema-digital-multicanal', 'DSW'],
];

// Sitio de demostración (Render pone APP_ENV=demo): ahí se ven también los ejemplos marcados 'solo_demo'
$es_demo = (($_ENV['APP_ENV'] ?? '') === 'demo');

/* Testimonios REALES de clientes (solo con su autorización): se muestran en la sección "Proyectos".
   Formato: ['nombre' => 'Nombre Apellido', 'cargo' => 'Cargo', 'organizacion' => 'Empresa o entidad',
             'proyecto' => 'Proyecto que se hizo', 'texto' => 'Lo que dijo el cliente', 'calificacion' => 5 (1 a 5),
             'enlace' => 'https://... (evidencia, opcional)'].
   Con 'solo_demo' => true la entrada solo aparece en la demo, marcada como ejemplo. Sin testimonios, el bloque no aparece. */
$testimonios = array_values(array_filter([
    // Autorizadas por los clientes (texto libre y 5 estrellas), según Luis, el 15/11/2026
    ['nombre' => 'Laura Roja', 'imagen' => 'cuentavoz.jpg', 'cargo' => 'Coordinadora de Inventario', 'organizacion' => 'Colsubsidio', 'proyecto' => 'CuentaVoz: inventario por voz con agente inteligente', 'calificacion' => 5,
     'texto' => 'CuentaVoz cambió la forma en que contamos el inventario: el equipo dicta los conteos por voz en cada bodega y el agente nos responde al instante. Con el panel gerencial vemos de inmediato las alertas, los saldos por corregir y los tiempos de conteo. Una solución práctica, moderna y pensada para la operación real.',
     'enlace' => 'https://www.youtube.com/watch?v=V-ZtG-wGlo4'],
    ['nombre' => 'Andrés Soto', 'imagen' => 'tour-mountain-adventure.jpg', 'cargo' => 'Gerente', 'organizacion' => 'Tour Mountain Adventure', 'proyecto' => 'Plataforma web de caminatas guiadas', 'calificacion' => 5,
     'texto' => 'DataSense nos dio una plataforma a la altura de una empresa: los viajeros consultan las caminatas y el calendario de salidas, las empresas cotizan sus salidas en línea y dejamos atrás el trabajo manual de responder y organizar cada solicitud. Hoy proyectamos una imagen mucho más profesional.',
     'enlace' => 'https://tour-mountain-adventure.onrender.com/'],
    ['nombre' => 'Diana Linares', 'imagen' => 'colpensiones-fraude.jpg', 'cargo' => 'Apoyo de Gerencia de Fraude', 'organizacion' => 'Colpensiones', 'proyecto' => 'Tablero de indicadores para la Gerencia de Fraude', 'calificacion' => 5,
     'texto' => 'El tablero que construyó DataSense le dio a la Gerencia de Fraude una vista clara y actualizada de los casos y las alertas. Ahora priorizamos con datos, identificamos más rápido los patrones que requieren revisión y presentamos la información a la dirección sin armar informes a mano.'],
], function ($t) use ($es_demo) {
    return trim((string) ($t['nombre'] ?? '')) !== '' && trim((string) ($t['texto'] ?? '')) !== '' && ($es_demo || empty($t['solo_demo']));
}));

/* Conferencias de Marcela Pacheco Parra en YouTube: se muestran en la sección "Conferencias corporativas".
   Para agregar una, copia el código del video (lo que va después de "watch?v=" en el enlace) y su título.
   Ejemplo: ['id' => 'abcdEFGhijk', 'titulo' => 'Bienestar emocional en el trabajo']. Sin videos, el bloque no aparece. */
$videos_marcela = array_values(array_filter([
    ['id' => 'SvjMfxZB0nA', 'titulo' => 'Los 5 lenguajes del amor · Psicología de pareja'],
], function ($v) { return preg_match('/^[A-Za-z0-9_-]{11}$/', (string) ($v['id'] ?? '')); }));
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>DataSense Analytics Insights | Consultoría en Datos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Analítica de datos, ciencia de datos y business intelligence para sector público y privado.">
  <?= metaSociales('DataSense Analytics Insights | Consultoría en Datos',
        'Analítica de datos, inteligencia de negocios y plataformas de atención para el sector público y privado: tableros, mesa de servicios con ANS, canal de PQRSD conforme a la Ley 1755 de 2015, facturación y portal de clientes.') ?>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  
  <link rel="stylesheet" href="css/style.css">
  <link rel="icon" type="image/png" href="img/favicon.png">
</head>

<body>

<header class="header-pro" id="mainHeader">
  <div class="header-content">
    <a href="./"><img src="img/logo.png" alt="DataSense Analytics Insights" class="logo"></a>
    <button type="button" class="menu-toggle-pro" id="menuTogglePro" aria-label="Abrir el menú" aria-expanded="false" aria-controls="menuPrincipal"><i class="fas fa-bars"></i></button>
    <nav id="menuPrincipal">
      <a href="#inicio">Inicio</a>
      <a href="#quienes">Quiénes Somos</a>
      <a href="#servicios">Servicios</a>
      <a href="#conferencias">Conferencias</a>
      <a href="#proyectos">Proyectos</a>
      <a href="tienda.php" class="nav-tienda"><i class="fas fa-store"></i> Tienda</a>
      <a href="#contacto">Contacto</a>
      <a href="pqrsd.php">PQRSD</a>
      <a href="carrito.php" class="nav-carrito" aria-label="Ver el carrito de compras" title="Carrito de compras" id="enlaceCarritoInicio" hidden><i class="fas fa-cart-shopping"></i><span id="contadorCarritoInicio" hidden>0</span></a>
      <a href="admin/login.php" class="btn-login-pro">Iniciar sesión</a>
    </nav>
  </div>
</header>
<script>
// Menú del sitio en celulares: se abre con la hamburguesa y se cierra al elegir una sección
(function () {
  const cabecera = document.getElementById('mainHeader');
  const boton = document.getElementById('menuTogglePro');
  const abrir = function (abierto) {
    cabecera.classList.toggle('nav-abierta', abierto);
    boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    boton.setAttribute('aria-label', abierto ? 'Cerrar el menú' : 'Abrir el menú');
    boton.innerHTML = abierto ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
  };
  boton.addEventListener('click', function () { abrir(!cabecera.classList.contains('nav-abierta')); });
  document.querySelectorAll('#menuPrincipal a').forEach(function (enlace) { enlace.addEventListener('click', function () { abrir(false); }); });
})();
</script>

<section id="inicio" class="hero-pro" style="background-image:url('img/banner.png')">
  <div class="hero-overlay"></div>
  <div class="hero-content-wrapper">
    <div class="hero-text-box">
      <h1>Transformamos datos en decisiones inteligentes</h1>
      <p>Analítica, ciencia de datos e insights estratégicos para impulsar el crecimiento de organizaciones públicas y privadas.</p>
      <div class="hero-acciones">
        <a href="#contacto" class="btn-cta-pro">Solicitar asesoría gratuita</a>
        <a href="#conferencias" class="btn-cta-pro btn-cta-secundario"><i class="fas fa-microphone-lines"></i>Conferencias corporativas</a>
      </div>
    </div>
  </div>
</section>

<section id="quienes" class="section light section-padding">
  <div class="container">
    <div class="section-header">
      <h2>Quiénes Somos</h2>
      <span class="section-line"></span>
      <p class="text-muted" style="margin-top:20px; font-size: 1.15rem; max-width: 800px; margin-left: auto; margin-right: auto;">
        Somos una firma experta en ciencia de datos e inteligencia de negocio. Nuestro objetivo es aportar <b>rigor técnico, transparencia y resultados viables</b> para la toma de decisiones estratégicas.
      </p>
    </div>

    <div class="team-grid">
      <div class="team-card">
        <div class="team-img-wrapper">
          <img src="img/marcela.jpg" alt="Marcela Pacheco Parra" onerror="this.src='https://ui-avatars.com/api/?name=Marcela+Pacheco&background=0A2E4F&color=fff&size=200&bold=true'">
        </div>
        <h4 class="team-name">Marcela Pacheco Parra</h4>
        <span class="team-role">Co-Fundadora & CTO</span>
        <div class="team-degree"><i class="fas fa-graduation-cap me-2"></i> Psicóloga (UNAD) · Magíster en Desarrollo Humano (FLACSO)</div>
        <p class="team-desc">Psicóloga y magíster en Desarrollo Humano de FLACSO Argentina, con trayectoria en entidades públicas y privadas: Prosperidad Social, el Fondo Nacional de Gestión del Riesgo de Desastres y el bienestar universitario. Experta en formular, hacer seguimiento y evaluar planes, programas y políticas públicas, con formación en Análisis de Datos certificada por el MinTIC y dominio de Power BI.</p>
        <p class="team-desc"><i class="fas fa-trophy me-2" style="color:#d4a017"></i><b>Primer lugar</b>, junto con Luis Guillermo Nieto Patiño, en la Hackatón Talento Tech Región 7 Boyacá–Cundinamarca (MinTIC, 2025).</p>
        <p class="team-desc mb-0"><i class="fas fa-comments me-2"></i><b>Conferencista en psicología</b> para organizaciones nacionales e internacionales: bienestar, trabajo en equipo, psicología de pareja, orientación vocacional y desarrollo humano.</p>
        <div class="team-contacto">
          <a href="#contacto" class="btn-team">Solicita una conferencia</a>
          <?php if ($videos_marcela): ?>
          <a href="#videosMarcela" class="btn-team btn-team-yt"><i class="fa-brands fa-youtube"></i>Sus conferencias</a>
          <?php endif; ?>
          <?php $wpp = preg_replace('/\D+/', '', (string) ($_ENV['SOCIAL_WHATSAPP'] ?? '')); if (strlen($wpp) >= 10): ?>
          <a href="https://wa.me/<?= esc($wpp) ?>?text=<?= rawurlencode('Hola Marcela, vengo del sitio de DataSense y quiero información sobre sus conferencias de psicología.') ?>" class="btn-team btn-team-wpp" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="team-card">
        <div class="team-img-wrapper">
          <img src="img/luis.jpg" style="object-position: 50% 20%;" alt="Luis Guillermo Nieto Patiño" onerror="this.src='https://ui-avatars.com/api/?name=Luis+Guillermo&background=0A2E4F&color=fff&size=200&bold=true'">
        </div>
        <h4 class="team-name">Luis Guillermo Nieto Patiño</h4>
        <span class="team-role">Co-Fundador & CEO</span>
        <div class="team-degree"><i class="fas fa-graduation-cap me-2"></i> Ingeniero de Sistemas · Maestría en Administración con énfasis en Análisis de Datos (en curso)</div>
        <p class="team-desc">Ingeniero de Sistemas con trayectoria en tecnología desde 2013, especializado en analítica y calidad de la información. Ha trabajado en el sector público y en el privado: administró los sistemas de información del Ministerio de las Culturas, validó información académica y laboral en la Gerencia de Prevención del Fraude de Colpensiones y lideró mesa de ayuda bajo ITIL para la banca. Lidera la estrategia de negocio y la visión tecnológica de DataSense, y acompaña a gerencias y entidades a convertir sus datos en decisiones: tableros ejecutivos, verificación de información, analítica avanzada y software a la medida.</p>
        <p class="team-desc"><i class="fas fa-award me-2" style="color:#d4a017"></i><b>Premio Alfonso Palacio Rudas 2026</b> de la Contraloría General de la República, categoría Académico, por una metodología de verificación de información pública.</p>
        <p class="team-desc"><i class="fas fa-trophy me-2" style="color:#d4a017"></i><b>Primer lugar</b>, junto con Marcela Pacheco Parra, en la Hackatón Talento Tech Región 7 Boyacá–Cundinamarca (MinTIC, 2025). En 2026 creó CuentaVoz y Kauta en la Hackatón 30X, ReActiva Territorio en la hackatón de Platzi Conf Bogotá y Desenmascara en la Hackatón Colombia 5.0.</p>
        <p class="team-desc"><i class="fas fa-screwdriver-wrench me-2"></i>Power BI · SQL Server y MySQL · Python · calidad y cruce de datos · Amazon Cognito y doble factor · ITIL y ANS · desarrollo web y APIs.</p>
        <p class="team-desc mb-0"><i class="fas fa-handshake me-2"></i><b>¿Tu organización necesita decidir con datos?</b> Conversemos sobre tu reto.</p>
        <div class="team-contacto">
          <a href="#contacto" class="btn-team">Agenda una reunión</a>
          <?php $wpp = preg_replace('/\D+/', '', (string) ($_ENV['SOCIAL_WHATSAPP'] ?? '')); if (strlen($wpp) >= 10): ?>
          <a href="https://wa.me/<?= esc($wpp) ?>?text=<?= rawurlencode('Hola Luis, vengo del sitio de DataSense y quiero conversar sobre un proyecto de datos.') ?>" class="btn-team btn-team-wpp" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section section-padding" style="background: linear-gradient(to bottom, #ffffff, #f8fafc);">
  <div class="container">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 40px;">
      
      <div style="background: #fff; padding: 50px 40px; border-radius: 20px; box-shadow: 0 15px 40px rgba(10, 46, 79, 0.06); border-left: 6px solid #3FAE49; position: relative; overflow: hidden; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
        <div style="position: absolute; top: -20px; right: -20px; opacity: 0.03; font-size: 180px; color: #0A2E4F; z-index: 0;">
          <i class="fas fa-bullseye"></i>
        </div>
        
        <div style="display: flex; align-items: center; margin-bottom: 25px; position: relative; z-index: 1;">
            <div style="width: 65px; height: 65px; background: rgba(63, 174, 73, 0.1); border-radius: 15px; display: flex; align-items: center; justify-content: center; margin-right: 20px;">
                <i class="fas fa-bullseye" style="font-size: 30px; color: #3FAE49;"></i>
            </div>
            <h3 style="color: #0A2E4F; font-size: 1.9rem; margin: 0; font-weight: 800; letter-spacing: -0.5px;">Nuestra Misión</h3>
        </div>
        <p style="color: #475569; font-size: 1.1rem; line-height: 1.7; margin: 0; position: relative; z-index: 1;">
          Transformar datos complejos en insights claros, confiables y accionables que generen valor sostenible, fortalezcan la gestión institucional y apoyen la toma de decisiones estratégicas.
        </p>
      </div>

      <div style="background: #fff; padding: 50px 40px; border-radius: 20px; box-shadow: 0 15px 40px rgba(10, 46, 79, 0.06); border-left: 6px solid #0A2E4F; position: relative; overflow: hidden; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
        <div style="position: absolute; top: -20px; right: -20px; opacity: 0.03; font-size: 180px; color: #0A2E4F; z-index: 0;">
          <i class="fas fa-eye"></i>
        </div>
        
        <div style="display: flex; align-items: center; margin-bottom: 25px; position: relative; z-index: 1;">
            <div style="width: 65px; height: 65px; background: rgba(10, 46, 79, 0.06); border-radius: 15px; display: flex; align-items: center; justify-content: center; margin-right: 20px;">
                <i class="fas fa-eye" style="font-size: 30px; color: #0A2E4F;"></i>
            </div>
            <h3 style="color: #0A2E4F; font-size: 1.9rem; margin: 0; font-weight: 800; letter-spacing: -0.5px;">Nuestra Visión</h3>
        </div>
        <p style="color: #475569; font-size: 1.1rem; line-height: 1.7; margin: 0; position: relative; z-index: 1;">
          Consolidarnos en los próximos cinco años como una firma referente en analítica de datos en Latinoamérica, reconocida por su rigor técnico, ética profesional y enfoque estratégico.
        </p>
      </div>

    </div>
  </div>
</section>

<section id="servicios" class="section light section-padding">
  <div class="container">
    <div class="section-header text-center" style="margin-bottom: 50px;">
      <h2 style="font-size: 2.5rem; color: #0A2E4F; margin-bottom:10px;">Servicios</h2>
      <span class="section-line" style="display:block; width:60px; height:4px; background:#3FAE49; margin: 0 auto; border-radius:2px;"></span>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
      <div class="premium-card">
        <div class="premium-icon-box icon-blue">
          <i class="fas fa-chart-line"></i>
        </div>
        <h3>Business Intelligence</h3>
        <p>Diseño e implementación de tableros de control, indicadores clave de desempeño (KPIs) y reportes ejecutivos para el seguimiento estratégico y la toma de decisiones.</p>
      </div>

      <div class="premium-card">
        <div class="premium-icon-box icon-blue">
          <i class="fas fa-brain"></i>
        </div>
        <h3>Ciencia de Datos</h3>
        <p>Análisis avanzado, modelamiento estadístico y predictivo y explotación de datos para identificar patrones, riesgos y oportunidades de mejora.</p>
      </div>

      <div class="premium-card">
        <div class="premium-icon-box icon-blue">
          <i class="fas fa-landmark"></i>
        </div>
        <h3>Analítica Sector Público</h3>
        <p>Soluciones analíticas orientadas a la transparencia, el control, la evaluación de políticas públicas y el fortalecimiento de la gestión institucional.</p>
      </div>

      <div class="premium-card" id="servicioDesarrollo">
        <div class="premium-icon-box icon-blue">
          <i class="fas fa-code"></i>
        </div>
        <h3>Desarrollo de Software</h3>
        <p><strong>Backend:</strong> APIs, bases de datos e integraciones entre sistemas. <strong>Frontend:</strong> aplicaciones web responsivas y móviles. Software a la medida de tu operación.</p>
      </div>

      <div class="premium-card" id="servicioPsicologia">
        <div class="premium-icon-box icon-blue">
          <i class="fas fa-comments"></i>
        </div>
        <h3>Conferencias de Psicología y Desarrollo Humano</h3>
        <p>Conferencias y talleres para empresas, instituciones educativas y entidades sobre bienestar, trabajo en equipo, psicología de pareja, orientación vocacional y desarrollo humano, dirigidos por Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano. <a href="#contacto" class="fw-bold">Solicítala aquí</a>.</p>
      </div>
    </div>
    
    <p class="text-center mt-5 fw-bold" style="color: #0A2E4F; padding-top: 20px;">
      <i class="fas fa-database text-success me-2" style="color:#3FAE49;"></i> Todos nuestros servicios se apoyan en una sólida gestión, calidad, integración y gobierno de datos.
    </p>
  </div>
</section>

<section id="conferencias" class="section section-padding conferencias-seccion">
  <div class="container">
    <div class="conferencias-grid">
      <div class="conferencias-foto">
        <img src="img/marcela.jpg" alt="Marcela Pacheco Parra, psicóloga y conferencista de DataSense" loading="lazy">
        <div class="conferencias-sello">
          <i class="fas fa-microphone-lines" aria-hidden="true"></i>
          <span><b>Marcela Pacheco Parra</b>Co-Fundadora · Conferencista</span>
        </div>
      </div>
      <div class="conferencias-texto">
        <span class="conferencias-etiqueta">Conferencias corporativas</span>
        <h2>Bienestar y Desarrollo Humano para equipos que crecen</h2>
        <p>Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano de FLACSO Argentina, con trayectoria en Prosperidad Social, la gestión del riesgo de desastres y el bienestar universitario, lleva a su organización conferencias y talleres que fortalecen a las personas y a los equipos, con la mirada de la psicología y el respaldo de los datos.</p>
        <ul class="conferencias-temas">
          <li><i class="fas fa-brain" aria-hidden="true"></i>Bienestar y salud mental</li>
          <li><i class="fas fa-people-group" aria-hidden="true"></i>Trabajo en equipo</li>
          <li><i class="fas fa-heart" aria-hidden="true"></i>Psicología de pareja</li>
          <li><i class="fas fa-compass" aria-hidden="true"></i>Orientación vocacional</li>
          <li><i class="fas fa-handshake-angle" aria-hidden="true"></i>Inclusión y diversidad</li>
          <li><i class="fas fa-seedling" aria-hidden="true"></i>Desarrollo humano y liderazgo</li>
        </ul>
        <p class="conferencias-modalidad"><i class="fas fa-earth-americas" aria-hidden="true"></i>Presencial o virtual · Empresas, instituciones educativas y entidades nacionales e internacionales</p>
        <div class="conferencias-acciones">
          <a href="tienda.php?categoria=conferencias-corporativas" class="btn-team">Ver en la tienda</a>
          <a href="#contacto" class="btn-team btn-team-claro">Solicitar propuesta</a>
          <?php $wpp = preg_replace('/\D+/', '', (string) ($_ENV['SOCIAL_WHATSAPP'] ?? '')); if (strlen($wpp) >= 10): ?>
          <a href="https://wa.me/<?= esc($wpp) ?>?text=<?= rawurlencode('Hola Marcela, vengo del sitio de DataSense y quiero información sobre sus conferencias de psicología.') ?>" class="btn-team btn-team-wpp" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($videos_marcela): ?>
    <div class="conferencias-videos<?= count($videos_marcela) === 1 ? ' un-video' : '' ?>" id="videosMarcela">
      <h3><i class="fa-brands fa-youtube"></i>Mira a Marcela en sus conferencias</h3>
      <div class="videos-rejilla">
        <?php foreach ($videos_marcela as $v): ?>
        <div class="video-marco">
          <button type="button" class="video-yt" data-video="<?= esc($v['id']) ?>" data-titulo="<?= esc($v['titulo'] ?? '') ?>" aria-label="Reproducir el video: <?= esc($v['titulo'] ?? '') ?>">
            <img src="https://i.ytimg.com/vi/<?= esc($v['id']) ?>/hqdefault.jpg" alt="" loading="lazy">
            <span class="video-play" aria-hidden="true"><i class="fas fa-play"></i></span>
          </button>
          <p class="video-titulo"><?= esc($v['titulo'] ?? '') ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section id="proyectos" class="section light section-padding">
  <div class="container">
    <div class="section-header text-center" style="margin-bottom: 18px;">
      <h2 style="font-size: 2.5rem; color: #0A2E4F; margin-bottom:10px;">Proyectos</h2>
      <span class="section-line" style="display:block; width:60px; height:4px; background:#3FAE49; margin: 0 auto; border-radius:2px;"></span>
    </div>
    <p class="proyectos-intro">Soluciones que hemos construido e investigado. Si quieres algo así para tu organización, cuéntanos tu reto y lo hacemos realidad.</p>

    <div class="proyectos-filtros" role="group" aria-label="Filtrar proyectos por área">
      <button type="button" class="filtro-proyecto activo" data-filtro="todos" aria-pressed="true">Todos</button>
      <button type="button" class="filtro-proyecto" data-filtro="analitica" aria-pressed="false"><i class="fas fa-chart-line"></i>Analítica y BI</button>
      <button type="button" class="filtro-proyecto" data-filtro="publico" aria-pressed="false"><i class="fas fa-landmark"></i>Sector público</button>
      <button type="button" class="filtro-proyecto" data-filtro="software" aria-pressed="false"><i class="fas fa-code"></i>Desarrollo de software</button>
      <button type="button" class="filtro-proyecto" data-filtro="diseno" aria-pressed="false"><i class="fas fa-pen-nib"></i>Diseño y marketing</button>
      <button type="button" class="filtro-proyecto" data-filtro="humano" aria-pressed="false"><i class="fas fa-seedling"></i>Desarrollo humano</button>
      <button type="button" class="filtro-proyecto" data-filtro="hackaton" aria-pressed="false"><i class="fas fa-trophy"></i>Hackatones</button>
    </div>

    <div class="proyectos-rejilla">
      <!-- CuentaVoz (Colsubsidio) -->
      <article class="proyecto-tarjeta proyecto-destacado" data-areas="analitica software hackaton">
        <div class="proyecto-media"><img src="img/proyectos/portadas/cuentavoz.jpg" data-grande="img/proyectos/cuentavoz.jpg" alt="CuentaVoz: reportes de análisis de consumo con recomendación automática del agente" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-building"></i>Colsubsidio</span>
          <span class="proyecto-area"><i class="fas fa-microphone-lines"></i>Analítica e IA · Software</span>
          <span class="proyecto-distincion"><i class="fas fa-trophy"></i>Hackatón Colsubsidio × 30X · 2026</span>
          <h3>CuentaVoz: inventario por voz con agente inteligente</h3>
          <p>Conteo de inventario por voz en bodega, con un agente que responde por voz o texto, análisis de consumo frente a la receta y un panel gerencial con alertas. Probada con datos reales de la operación: 53 bodegas, 1.040 artículos y 79 inconsistencias detectadas antes del cargue, con acceso protegido por Amazon Cognito y doble factor.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="CuentaVoz (inventario por voz con agente inteligente)" data-servicio="DS">Quiero un proyecto así</a>
            <a href="https://www.youtube.com/watch?v=V-ZtG-wGlo4" class="btn-proyecto-sec" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-youtube me-2"></i>Ver el recorrido</a>
          </div>
        </div>
      </article>

      <!-- Premio Alfonso Palacio Rudas 2026 (Contraloría General de la República) -->
      <article class="proyecto-tarjeta" data-areas="analitica publico">
        <div class="proyecto-media"><img src="img/proyectos/premio-alfonso-palacio-rudas.jpg" alt="Luis Guillermo Nieto Patiño con el reconocimiento de la Contraloría General de la República" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-landmark"></i>Contraloría General de la República</span>
          <span class="proyecto-area"><i class="fas fa-magnifying-glass-chart"></i>Calidad de la información · Sector público</span>
          <span class="proyecto-distincion"><i class="fas fa-award"></i>Premio Alfonso Palacio Rudas · 2026</span>
          <h3>Metodología de verificación de información pública</h3>
          <p>Análisis de datos aplicado a la veracidad, calidad y consistencia de la información que reportan las entidades públicas: cruza fuentes independientes (SECOP II, informes de control fiscal, actos administrativos y estados financieros) para detectar inconsistencias y sustentar cada hallazgo con trazabilidad documental. Distinción nacional en la categoría Académico.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Verificación de información pública" data-servicio="ASP">Quiero un proyecto así</a>
          </div>
        </div>
      </article>

      <!-- Hackatón Talento Tech Región 7 (MinTIC): primer lugar -->
      <article class="proyecto-tarjeta" data-areas="analitica hackaton">
        <div class="proyecto-media"><img src="img/proyectos/portadas/talento-tech-marcela-luis.jpg" data-grande="img/proyectos/talento-tech-marcela-luis.jpg" alt="Marcela Pacheco Parra y Luis Guillermo Nieto Patiño con el trofeo y sus certificados del primer lugar en la Hackatón Talento Tech Región 7 Boyacá–Cundinamarca 2025" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-flag"></i>Ministerio TIC · Talento Tech</span>
          <span class="proyecto-area"><i class="fas fa-chart-line"></i>Analítica avanzada</span>
          <span class="proyecto-distincion"><i class="fas fa-trophy"></i>Primer lugar · Hackatón Talento Tech · 2025</span>
          <h3>Analítica avanzada con pronósticos y minería de procesos</h3>
          <p>Solución con la que Marcela Pacheco Parra y Luis Guillermo Nieto Patiño ganaron juntos el primer lugar regional en Boyacá y Cundinamarca en 2025, reconocida por su excelencia, creatividad e innovación: integración de datos en MySQL, pronósticos con modelos de series de tiempo (ARIMA y Prophet), minería de procesos con PM4Py y tableros en Power BI, construida con datos reales y bajo presión de tiempo.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Analítica avanzada con pronósticos" data-servicio="DS">Quiero un proyecto así</a>
          </div>
        </div>
      </article>

      <!-- Kauta: Hackatón 30X 2026, reto Blinka -->
      <article class="proyecto-tarjeta" data-areas="analitica software hackaton">
        <div class="proyecto-media"><img src="img/proyectos/portadas/kauta.jpg" data-grande="img/proyectos/kauta.jpg" alt="Kauta: panel de cuentas por pagar con las facturas que el agente consulta, pagos exitosos y vencimientos" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-flag"></i>Hackatón 30X · Reto Blinka</span>
          <span class="proyecto-area"><i class="fas fa-robot"></i>IA aplicada · Finanzas</span>
          <span class="proyecto-distincion"><i class="fas fa-trophy"></i>Hackatón 30X · 2026</span>
          <h3>Kauta: agente de cuentas por pagar</h3>
          <p>Lee facturas en cualquier formato (correo, chat, PDF, foto o XML), las revisa contra 13 reglas de la empresa y paga desde la wallet: paga lo que cumple, frena lo que no y consulta a una persona lo dudoso. La IA solo lee; las decisiones las toma código auditable, con token para aprobar y pagar, aprobación del CEO para montos altos y un registro que nadie puede alterar. Desarrollado en equipo.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Kauta (agente de cuentas por pagar)" data-servicio="DSW">Quiero un proyecto así</a>
            <a href="https://kauta.vercel.app/" class="btn-proyecto-sec" target="_blank" rel="noopener noreferrer"><i class="fas fa-arrow-up-right-from-square"></i>Ver el proyecto</a>
          </div>
        </div>
      </article>

      <!-- Desenmascara: Hackatón Colombia 5.0 (2026) -->
      <article class="proyecto-tarjeta" data-areas="software hackaton">
        <div class="proyecto-media con-video" style="--reproducir-x: 76%">
          <img src="img/proyectos/portadas/desenmascara-video.jpg" data-video="https://desenmascara.onrender.com/pitch/desenmascara-pitch.mp4" data-pagina="https://desenmascara.onrender.com/pitch/" alt="Desenmascara · «El día de Doña Rosa»: pitch en video del bot antiestafas (Hackatón Colombia 5.0)" loading="lazy">
          <span class="proyecto-reproducir" aria-hidden="true"><i class="fas fa-play"></i></span>
          <span class="proyecto-video-etiqueta" aria-hidden="true"><i class="fas fa-video"></i>Pitch en video</span>
        </div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-flag"></i>Hackatón Colombia 5.0</span>
          <span class="proyecto-area"><i class="fas fa-shield-halved"></i>IA aplicada · Prevención de estafas</span>
          <span class="proyecto-distincion"><i class="fas fa-trophy"></i>Hackatón Colombia 5.0 · 2026</span>
          <h3>Desenmascara: bot y plataforma antiestafas</h3>
          <p>Reenvías el mensaje o el número sospechoso a un bot de Telegram y en segundos recibes un semáforo con el porqué; cuando puede, confirma con la fuente: tu familiar o tu banco. Incluye una app de Android que avisa antes de contestar, Llamada Verificada para las entidades, banco de números reportados y un radar de campañas de estafa. En la prueba con mensajes reales de Colombia detectó 20 de 20 estafas, sin falsos positivos.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Desenmascara (bot antiestafas)" data-servicio="DSW">Quiero un proyecto así</a>
            <a href="https://desenmascara.onrender.com/pitch/" class="btn-proyecto-sec" data-abrir-video target="_blank" rel="noopener noreferrer"><i class="fas fa-play"></i>Ver el video</a>
          </div>
        </div>
      </article>

      <!-- ReActiva Territorio: hackatón de Platzi Conf Bogotá (2026) -->
      <article class="proyecto-tarjeta" data-areas="software publico hackaton">
        <div class="proyecto-media con-video" style="--reproducir-x: 85%; --reproducir-y: 68%">
          <img src="img/proyectos/portadas/reactiva-territorio.jpg" data-youtube="ZHtUpvOqVfI" data-pagina="https://reactivaterritorio.web.app/" data-pagina-texto="Visitar la plataforma" alt="ReActiva Territorio: pitch en video de la plataforma de recuperación después del sismo (hackatón de Platzi Conf Bogotá)" loading="lazy">
          <span class="proyecto-reproducir" aria-hidden="true"><i class="fas fa-play"></i></span>
          <span class="proyecto-video-etiqueta" aria-hidden="true"><i class="fas fa-video"></i>Pitch en video</span>
        </div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-flag"></i>Hackatón Platzi Conf · Bogotá</span>
          <span class="proyecto-area"><i class="fas fa-house-crack"></i>Gestión del riesgo · Recuperación tras desastres</span>
          <span class="proyecto-distincion"><i class="fas fa-trophy"></i>Hackatón Platzi Conf Bogotá · 2026</span>
          <h3>ReActiva Territorio: recuperación verificable después de un sismo</h3>
          <p>Nació del sismo de magnitud 7,4 del 10 de agosto de 2026 en San José del Palmar (Chocó). Los líderes comunitarios registran a las personas y negocios afectados, incluso sin conexión; cada caso aparece en un mapa, se verifica, se prioriza y se relaciona con ofertas de ayuda compatibles mediante reglas explicables. La organización registra la entrega con evidencia, la comunidad confirma el cierre y un tablero muestra los resultados, con alertas y verificación en dos pasos.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="ReActiva Territorio (recuperación después de desastres)" data-servicio="DSW">Quiero un proyecto así</a>
            <a href="https://www.youtube.com/watch?v=ZHtUpvOqVfI" class="btn-proyecto-sec" data-abrir-video target="_blank" rel="noopener noreferrer"><i class="fas fa-play"></i>Ver el video</a>
          </div>
        </div>
      </article>

      <article class="proyecto-tarjeta" data-areas="software">
        <div class="proyecto-media"><img src="img/proyectos/portadas/tour-mountain-adventure.jpg" data-grande="img/proyectos/tour-mountain-adventure.jpg" alt="Sitio web de Tour Mountain Adventure: caminatas guiadas por Cundinamarca" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-mountain-sun"></i>Tour Mountain Adventure</span>
          <span class="proyecto-area"><i class="fas fa-code"></i>Desarrollo web · Turismo</span>
          <h3>Plataforma web de caminatas guiadas por Cundinamarca</h3>
          <p>Sitio de la agencia con catálogo de caminatas por tipo de aventura, calendario de salidas, cotización de salidas empresariales, tienda, blog y cuentas para los viajeros.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="la plataforma web de Tour Mountain Adventure" data-servicio="DSW">Quiero un proyecto así</a>
            <a href="https://tour-mountain-adventure.onrender.com/" class="btn-proyecto-sec" target="_blank" rel="noopener noreferrer"><i class="fas fa-arrow-up-right-from-square"></i>Visitar el sitio</a>
          </div>
        </div>
      </article>

      <article class="proyecto-tarjeta" data-areas="analitica publico">
        <div class="proyecto-media"><img src="img/proyectos/colpensiones-fraude.jpg" alt="Tablero de la Gerencia de Fraude con casos en revisión, alertas por tipo y casos por nivel de riesgo (imagen ilustrativa con datos ficticios)" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-building-columns"></i>Colpensiones</span>
          <span class="proyecto-area"><i class="fas fa-shield-halved"></i>Analítica de fraude · Sector público</span>
          <h3>Tablero de indicadores para la Gerencia de Fraude</h3>
          <p>Casos y alertas actualizados en un solo tablero, priorizados por nivel de riesgo y por tipo de alerta, con la información lista para presentar a la dirección.</p>
          <p class="proyecto-nota"><i class="fas fa-lock"></i>Imagen ilustrativa con datos ficticios: la información del cliente es confidencial.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="un tablero de indicadores como el de la Gerencia de Fraude de Colpensiones" data-servicio="BI">Quiero un proyecto así</a>
            <a href="#testimonios" class="btn-proyecto-sec"><i class="fas fa-star"></i>Ver su reseña</a>
          </div>
        </div>
      </article>

      <article class="proyecto-tarjeta" data-areas="software publico">
        <div class="proyecto-media"><img src="img/proyectos/portadas/plataforma-datasense.jpg" data-grande="img/proyectos/plataforma-datasense.jpg" alt="Panel de la plataforma DataSense con sus módulos de operación, mesa de servicios, PQRSD, facturación y contabilidad" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-area"><i class="fas fa-code"></i>Desarrollo de software</span>
          <h3>Plataforma de gestión DataSense</h3>
          <p>Una sola plataforma con más de 35 módulos: mesa de servicios ITIL con ANS en horario hábil, canal de PQRSD conforme a la Ley 1755 de 2015, facturación, contabilidad, pagos y tienda en línea, con permisos por rol.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Plataforma de gestión DataSense" data-servicio="DSW">Quiero un proyecto así</a>
            <a href="#contacto" class="btn-proyecto-sec" data-proyecto="una demostración de la Plataforma de gestión DataSense" data-servicio="DSW">Solicitar una demostración</a>
          </div>
        </div>
      </article>

      <article class="proyecto-tarjeta" data-areas="analitica publico">
        <div class="proyecto-media"><img src="img/proyectos/portadas/transparencia-presupuestal.jpg" data-grande="img/productos/prod_6d6fee582c7499d3.jpg" alt="Tablero de ejecución presupuestal con indicadores, ejecución por sector y porcentaje de ejecución" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-area"><i class="fas fa-landmark"></i>Analítica sector público</span>
          <h3>Tablero de Transparencia Presupuestal</h3>
          <p>Ejecución presupuestal por sector, contratación y metas del plan de desarrollo en un tablero claro, listo para publicar en la web de la entidad y rendir cuentas a la ciudadanía.</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Tablero de Transparencia Presupuestal" data-servicio="ASP">Quiero un proyecto así</a>
            <a href="producto.php?p=tablero-transparencia-presupuestal" class="btn-proyecto-sec">Ver en la tienda</a>
          </div>
        </div>
      </article>

      <article class="proyecto-tarjeta" data-areas="humano">
        <div class="proyecto-media"><img src="img/proyectos/portadas/inclusion-estudiantes-sordos.jpg" alt="Investigación de Marcela Pacheco Parra, magíster en Desarrollo Humano (FLACSO Argentina): inclusión educativa de estudiantes sordos, estudio de caso en Fusagasugá" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-area"><i class="fas fa-seedling"></i>Desarrollo humano</span>
          <h3>Inclusión educativa de estudiantes sordos</h3>
          <p>Estudio de caso dirigido por Marcela Pacheco Parra en Fusagasugá, Colombia: entrevistas a estudiantes, docentes, intérpretes y familias, análisis de equidad, pertinencia y relevancia, y propuestas de política pública. Publicado en la Biblioteca Digital de Vanguardia para la Investigación en Ciencias Sociales (Ecuador).</p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="Inclusión educativa de estudiantes sordos" data-servicio="PSI">Quiero un proyecto así</a>
            <a href="#conferencias" class="btn-proyecto-sec">Conferencias de Marcela</a>
          </div>
        </div>
      </article>

      <?php foreach ($portafolio_luis as [$imagen, $areas, $etiqueta, $titulo, $texto, $tecnologias, $pagina, $servicio]): ?>
      <article class="proyecto-tarjeta proyecto-extra" data-areas="<?= esc($areas) ?>">
        <div class="proyecto-media"><img src="img/proyectos/portafolio/<?= esc($imagen) ?>" data-grande="img/proyectos/portafolio/completas/<?= esc($imagen) ?>" alt="<?= esc($titulo) ?>" loading="lazy"></div>
        <div class="proyecto-cuerpo">
          <span class="proyecto-cliente"><i class="fas fa-user-tie"></i>Portafolio de Luis Nieto</span>
          <span class="proyecto-area"><i class="fas fa-layer-group"></i><?= esc($etiqueta) ?></span>
          <h3><?= esc($titulo) ?></h3>
          <p><?= esc($texto) ?></p>
          <p class="proyecto-tecnologias"><i class="fas fa-screwdriver-wrench me-1"></i><?= esc($tecnologias) ?></p>
          <div class="proyecto-acciones">
            <a href="#contacto" class="btn-team" data-proyecto="<?= esc($titulo) ?>" data-servicio="<?= esc($servicio) ?>">Quiero un proyecto así</a>
            <a href="https://portafolio-guillermo-nieto.onrender.com/project/<?= esc($pagina) ?>/" class="btn-proyecto-sec" target="_blank" rel="noopener noreferrer">Ver en el portafolio</a>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if ($portafolio_luis): ?>
    <div class="ver-mas-proyectos"><button type="button" class="btn-proyecto-sec" id="verMasProyectos" aria-expanded="false" hidden><i class="fas fa-plus me-2"></i><span class="ver-mas-texto">Ver más proyectos</span></button></div>
    <?php endif; ?>

    <?php if ($testimonios):
      $notas = array_filter(array_map(function ($t) { return (int) ($t['calificacion'] ?? 0); }, $testimonios), function ($n) { return $n >= 1 && $n <= 5; });
      $promedio = $notas ? array_sum($notas) / count($notas) : 0; ?>
    <div class="testimonios" id="testimonios">
      <div class="testimonios-cabecera">
        <div>
          <span class="testimonios-etiqueta">Clientes</span>
          <h3 class="testimonios-titulo">Lo que dicen quienes han trabajado con nosotros</h3>
        </div>
        <?php if ($notas): ?>
        <div class="testimonios-resumen" role="img" aria-label="Calificación promedio <?= number_format($promedio, 1, ',', '') ?> de 5 en <?= count($notas) ?> reseñas">
          <span class="resumen-nota"><?= number_format($promedio, 1, ',', '') ?></span>
          <span class="resumen-detalle">
            <span class="resumen-estrellas" aria-hidden="true"><?php for ($e = 1; $e <= 5; $e++): ?><i class="fa<?= $e <= round($promedio) ? 's' : 'r' ?> fa-star"></i><?php endfor; ?></span>
            <span><?= count($notas) ?> reseña<?= count($notas) === 1 ? '' : 's' ?> de clientes</span>
          </span>
        </div>
        <?php endif; ?>
      </div>
      <div class="testimonios-rejilla">
        <?php foreach ($testimonios as $t): $cal = (int) ($t['calificacion'] ?? 0); $tiene_enlace = !empty($t['enlace']) && preg_match('#^https://#i', $t['enlace']); ?>
        <figure class="testimonio">
          <?php if (!empty($t['imagen']) && preg_match('/^[a-z0-9-]+\.(jpg|png|webp)$/', $t['imagen'])): ?>
          <div class="testimonio-media">
            <img src="img/proyectos/<?= esc($t['imagen']) ?>" alt="<?= esc($t['proyecto'] ?? '') ?>" loading="lazy">
            <span class="testimonio-empresa"><?= esc($t['organizacion'] ?? '') ?></span>
          </div>
          <?php endif; ?>
          <div class="testimonio-cuerpo">
            <?php if ($cal >= 1 && $cal <= 5): ?>
            <div class="testimonio-estrellas" role="img" aria-label="Calificación: <?= $cal ?> de 5"><?php for ($e = 1; $e <= 5; $e++): ?><i class="fa<?= $e <= $cal ? 's' : 'r' ?> fa-star" aria-hidden="true"></i><?php endfor; ?></div>
            <?php endif; ?>
            <blockquote>“<?= esc($t['texto']) ?>”</blockquote>
            <figcaption>
              <span class="testimonio-inicial" aria-hidden="true"><?= esc(mb_strtoupper(mb_substr(trim($t['nombre']), 0, 1))) ?></span>
              <span class="testimonio-autor">
                <b><?= esc($t['nombre']) ?></b>
                <span><?= esc($t['cargo'] ?? '') ?></span>
                <strong><?= esc($t['organizacion'] ?? '') ?></strong>
              </span>
            </figcaption>
            <div class="testimonio-pie">
              <?php if (!empty($t['proyecto'])): ?><span><i class="fas fa-briefcase"></i><?= esc($t['proyecto']) ?></span><?php endif; ?>
              <?php if ($tiene_enlace): ?><a href="<?= esc($t['enlace']) ?>" target="_blank" rel="noopener noreferrer">Ver evidencia <i class="fas fa-arrow-up-right-from-square"></i></a><?php endif; ?>
            </div>
          </div>
        </figure>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="proyectos-cta">
      <div>
        <h3>¿Tienes un proyecto en mente?</h3>
        <p>Trabajamos con empresas, instituciones educativas y entidades públicas, en Colombia y en el exterior.</p>
      </div>
      <a href="#contacto" class="btn-cta-pro">Proponer un proyecto</a>
    </div>
  </div>
</section>

<section id="elegirnos" class="section section-padding enfoque-seccion">
  <div class="container">
    <div class="enfoque-pro">
      <div class="enfoque-intro">
        <span class="enfoque-ceja">Nuestro enfoque</span>
        <h2 class="enfoque-titulo">Analítica con rigor, pensada para decidir</h2>
        <p class="enfoque-texto">Cada proyecto combina método técnico, trazabilidad de la información y uso responsable de los datos. Así acompañamos a entidades públicas y empresas privadas a pasar de los datos a decisiones que se pueden sustentar.</p>
        <div class="enfoque-sellos">
          <span class="enfoque-sello"><i class="fas fa-award"></i>Premio Alfonso Palacio Rudas 2026</span>
          <span class="enfoque-sello"><i class="fas fa-trophy"></i>Primer lugar Talento Tech 2025</span>
          <span class="enfoque-sello"><i class="fas fa-user-shield"></i>Datos protegidos · Ley 1581 de 2012</span>
        </div>
      </div>

      <div class="enfoque-lista">
        <article class="enfoque-item">
          <div class="enfoque-icono"><i class="fas fa-bullseye"></i><span class="enfoque-numero">01</span></div>
          <div>
            <h3>Decisiones, no informes</h3>
            <p>Tableros e indicadores que responden las preguntas concretas de la gerencia. Menos reportes extensos y más decisiones a tiempo.</p>
          </div>
        </article>
        <article class="enfoque-item">
          <div class="enfoque-icono"><i class="fas fa-magnifying-glass-chart"></i><span class="enfoque-numero">02</span></div>
          <div>
            <h3>Rigor y trazabilidad</h3>
            <p>Cada cifra se puede rastrear hasta su fuente: cruzamos fuentes independientes, documentamos el método y sustentamos cada hallazgo.</p>
          </div>
        </article>
        <article class="enfoque-item">
          <div class="enfoque-icono"><i class="fas fa-scale-balanced"></i><span class="enfoque-numero">03</span></div>
          <div>
            <h3>Uso responsable de los datos</h3>
            <p>Soluciones viables y medibles, con protección de datos personales, acceso seguro con verificación en dos pasos y respeto por la confidencialidad.</p>
          </div>
        </article>
      </div>
    </div>
  </div>
</section>

<section id="tienda" class="section section-padding tienda-inicio">
  <div class="container">
    <div class="section-header text-center" style="margin-bottom: 40px;">
      <h2 style="font-size: 2.5rem; color: #0A2E4F; margin-bottom:10px;">Tienda DataSense</h2>
      <span class="section-line" style="display:block; width:60px; height:4px; background:#3FAE49; margin: 0 auto 16px; border-radius:2px;"></span>
      <p class="tienda-inicio-lema">Productos bioenergéticos y saludables, plantillas de tableros, cursos, licencias, kits IoT, productos de marca y revistas. Compra en línea y recibe tu factura al instante.</p>
    </div>
    <div class="tienda-inicio-rejilla" id="destacadosInicio" aria-live="polite">
      <div class="tienda-esqueleto"></div><div class="tienda-esqueleto"></div><div class="tienda-esqueleto"></div><div class="tienda-esqueleto"></div>
    </div>
    <div class="tienda-inicio-cta">
      <a href="tienda.php" class="btn-cta-pro"><i class="fas fa-store"></i> Ver toda la tienda</a>
      <a href="tienda.php?categoria=bienestar-saludable" class="btn-saludable-inicio"><i class="fas fa-leaf"></i> Productos saludables</a>
    </div>
  </div>
</section>

<section id="contacto" class="contact-section-pro section-padding">
    <div class="container">
        <div class="contact-grid">
            
            <div class="contact-info-pro">
                <div class="section-header" style="text-align: left;">
                    <h2>Hablemos de <br><span style="color: var(--ds-green);">tus datos</span></h2>
                    <span class="section-line" style="margin: 0;"></span>
                </div>
                <p>Conversemos sobre cómo podemos transformar los desafíos de tu organización en activos estratégicos.</p>
                
                <ul class="contact-list-pro">
                    <li><i class="fas fa-envelope"></i> info@saludgo.org</li>
                    <li><i class="fas fa-map-marker-alt"></i> Atención remota y presencial</li>
                    <li><i class="fas fa-clock"></i> Respuesta en menos de 24 horas</li>
                    <li><i class="fas fa-shield-alt"></i> Manejo confidencial de información</li>
                </ul>
            </div>

            <div class="contact-card-pro">
              <?php
              $mensajeEnviado = isset($_GET['enviado']);
              $mensajeError = isset($_GET['error']);
              ?>

              <?php if ($mensajeEnviado): ?>
                  <div class="success-card-pro">
                      <div class="success-icon-circle">
                          <i class="fas fa-check"></i>
                      </div>
                      <h3>¡Mensaje Recibido!</h3>
                      <p>
                          Gracias por contactar a <strong>DataSense</strong>. <br>
                          Nuestro equipo de especialistas revisará tu desafío de datos y te responderá en menos de 24 horas.
                      </p>
                      <a href="./#contacto" class="btn-return-pro">
                          <i class="fas fa-arrow-left me-2"></i> Volver
                      </a>
                  </div>

              <?php elseif ($mensajeError): ?>
                  <div class="alert alert-danger" style="border-radius: 15px; padding: 20px;">
                      <i class="fas fa-exclamation-circle me-2"></i>
                      <strong>Hubo un problema.</strong> No pudimos procesar tu solicitud. 
                      Por favor, intenta de nuevo o escríbenos a info@saludgo.org
                      <br><br>
                      <a href="./#contacto" class="btn-return-pro" style="font-size: 0.8rem; padding: 8px 15px;">Reintentar</a>
                  </div>

              <?php else: ?>
                  <form action="contacto.php" method="POST" id="contactForm">
                      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['token']; ?>">
                      
                      <div class="form-grid-inner">
                          <div class="input-group-pro">
                              <i class="fas fa-user"></i>
                              <input type="text" name="nombre" class="form-input" placeholder="Nombre completo" required>
                          </div>
                          <div class="input-group-pro">
                              <i class="fas fa-envelope"></i>
                              <input type="email" name="correo" class="form-input" placeholder="Correo electrónico" required>
                          </div>
                      </div>

                      <div class="form-grid-inner">
                          <div class="input-group-pro">
                              <i class="fas fa-building"></i>
                              <select name="tipo_cliente" class="form-input" required style="padding-left: 45px;">
                                  <option value="" disabled selected>Sector...</option>
                                  <option value="Público">Sector Público</option>
                                  <option value="Privado">Sector Privado</option>
                                  <option value="Mixto">Sector Mixto / ONG</option>
                              </select>
                          </div>
                          <div class="input-group-pro">
                              <i class="fas fa-lightbulb"></i>
                              <select name="servicio" class="form-input" required style="padding-left: 45px;">
                                  <option value="" disabled selected>Interés en...</option>
                                  <option value="BI">Business Intelligence</option>
                                  <option value="DS">Ciencia de Datos</option>
                                  <option value="ASP">Analítica Sector Público</option>
                                  <option value="CGD">Gobierno y Calidad de Datos</option>
                                  <option value="DSW">Desarrollo de Software (backend / frontend)</option>
                                  <option value="PSI">Conferencias de Psicología y Desarrollo Humano</option>
                              </select>
                          </div>
                      </div>

                      <div class="input-group-pro">
                          <textarea name="mensaje" class="form-input" placeholder="¿Cuál es tu desafío de datos?" rows="4" required style="padding-top: 15px;"></textarea>
                      </div>

                      <button type="submit" class="btn-send-pro shadow" id="sendBtn">
                          <span id="btnText">Solicitar Análisis Estratégico</span>
                          <span id="btnLoader" style="display:none;"><i class="fas fa-circle-notch fa-spin"></i> Enviando...</span>
                      </button>
                  </form>
              <?php endif; ?>
          </div>
        </div>
    </div>
</section>

<div id="ds-chatbot" style="position: fixed; bottom: 24px; right: 24px; z-index: 9999;">
  <button id="ds-chat-toggle" class="ds-chat-btn">
      <i class="fas fa-comment-dots" style="font-size: 30px;"></i>
  </button>

  <div id="ds-chat-panel" class="ds-chat-panel">
      <div class="ds-chat-header">
          <div style="display: flex; align-items: center; gap: 15px;">
              <div style="background: white; color: #0A2E4F; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px;"><i class="fas fa-robot"></i></div>
              <div>
                  <div style="font-weight: 800; font-size: 1.1rem; letter-spacing: 0.5px;">SenseBot</div>
                  <div style="font-size: 0.75rem; color: #7dd3fc;"><i class="fas fa-circle" style="font-size: 8px;"></i> En línea</div>
              </div>
          </div>
          <button id="ds-chat-close" style="background:none; border:none; color:white; font-size: 28px; cursor:pointer;">&times;</button>
      </div>

      <div id="ds-chat-messages" class="ds-chat-messages">
          </div>
      
      <div id="typing-indicator" class="typing-indicator"><i class="fas fa-ellipsis-h fa-fade"></i> SenseBot está escribiendo...</div>

      <form id="ds-chat-form" class="ds-chat-input-area">
          <input id="ds-chat-input" type="text" placeholder="Escribe tu mensaje..." style="flex: 1; border: 1px solid #cbd5e1; padding: 12px 15px; border-radius: 25px; outline: none; font-size: 0.95rem;" autocomplete="off">
          <button type="submit" style="background: var(--ds-blue, #0A2E4F); color: white; border: none; width: 45px; height: 45px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;">
              <i class="fas fa-paper-plane"></i>
          </button>
      </form>
  </div>
</div>

<footer class="footer">
  <div class="footer-interior">
    <div class="footer-grid">
      <div class="footer-col">
        <div class="footer-logo"><img src="img/logo.png" alt="DataSense Analytics Insights"></div>
        <p>Analítica de datos, inteligencia de negocios y plataformas de atención para el sector público y privado:
           tableros, mesa de servicios con tiempos medidos, canal de PQRSD y facturación, en un solo lugar.</p>
        <?php if (!empty($_ENV['EMPRESA_NIT']) || !empty($_ENV['EMPRESA_CIUDAD'])): ?>
          <p class="footer-dato"><?= !empty($_ENV['EMPRESA_NIT']) ? 'NIT ' . esc($_ENV['EMPRESA_NIT']) : '' ?><?= !empty($_ENV['EMPRESA_NIT']) && !empty($_ENV['EMPRESA_CIUDAD']) ? ' · ' : '' ?><?= esc($_ENV['EMPRESA_CIUDAD'] ?? '') ?></p>
        <?php endif; ?>
      </div>
      <div class="footer-col">
        <div class="footer-titulo">Enlaces</div>
        <ul class="footer-lista">
          <li><a href="#conferencias"><i class="fas fa-microphone-lines"></i>Conferencias corporativas</a></li>
          <li><a href="#proyectos"><i class="fas fa-diagram-project"></i>Proyectos</a></li>
          <li><a href="pqrsd.php"><i class="fas fa-scale-balanced"></i>Canal de PQRSD</a></li>
          <li><a href="tienda.php"><i class="fas fa-store"></i>Tienda</a></li>
          <li><a href="admin/login.php"><i class="fas fa-lock"></i>Portal de clientes</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <div class="footer-titulo">Atención</div>
        <div class="footer-atencion">
          <strong>Lunes a viernes</strong>
          8:00 a.m. a 5:00 p.m., sin festivos.<br>
          <a href="pqrsd.php">Radica una PQRSD</a> y te respondemos dentro de los términos de ley.
        </div>
        <?php $redes_pie = iconosRedes(''); ?>
        <?php if ($redes_pie !== ''): ?>
          <div class="footer-titulo" style="margin-top: 26px;">Síguenos</div>
          <?= $redes_pie ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="footer-abajo">
      <p>© <?= date('Y') ?> DataSense Analytics Insights. Todos los derechos reservados. · Hecho en Colombia</p>
      <div class="footer-compartir"><?= botonesCompartir('Conoce la plataforma de DataSense Analytics Insights: tableros, soporte con tiempos medidos y canal de PQRSD.', '', 'Comparte') ?></div>
    </div>
  </div>
  <?= estiloRedes() ?>
  <?= botonWhatsapp('Hola, vengo del sitio de DataSense Analytics y quiero saber más sobre la plataforma.') ?>
</footer>

<button id="backToTop">
  <i class="fas fa-chevron-up"></i>
</button>

<script>
// Efecto Glassmorphism en la cabecera al hacer Scroll
const header = document.getElementById("mainHeader");
window.addEventListener("scroll", () => {
  if (window.scrollY > 50) {
    header.style.padding = "10px 0";
    header.style.background = "rgba(255, 255, 255, 0.98)";
    header.style.boxShadow = "0 10px 30px rgba(0,0,0,0.08)";
  } else {
    header.style.padding = "15px 0";
    header.style.background = "rgba(255, 255, 255, 0.95)";
    header.style.boxShadow = "0 4px 20px rgba(0,0,0,0.05)";
  }
});

// Botón volver arriba
const backToTop = document.getElementById("backToTop");
window.addEventListener("scroll", () => {
  if (window.scrollY > 300) {
    backToTop.style.display = "flex";
  } else {
    backToTop.style.display = "none";
  }
});

backToTop.addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
});

// Animación del botón de formulario
const form = document.getElementById("contactForm");
if (form) {
  form.addEventListener("submit", function() {
    const btn = document.getElementById("sendBtn");
    const text = document.getElementById("btnText");
    const loader = document.getElementById("btnLoader");
    btn.disabled = true;
    text.style.display = "none";
    loader.style.display = "inline";
  });
}
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const toggleBtn = document.getElementById("ds-chat-toggle");
    const panel = document.getElementById("ds-chat-panel");
    const closeBtn = document.getElementById("ds-chat-close");
    const form = document.getElementById("ds-chat-form");
    const input = document.getElementById("ds-chat-input");
    const msgsContainer = document.getElementById("ds-chat-messages");
    const typingIndicator = document.getElementById("typing-indicator");

    let estado = "bienvenida";
    let userData = { nombre: "", empresa: "", correo: "" };
    let currentSessionId = null;

    toggleBtn.onclick = () => { panel.style.display = 'flex'; toggleBtn.style.display = 'none'; iniciarSaludo(); };
    closeBtn.onclick = () => { panel.style.display = 'none'; toggleBtn.style.display = 'flex'; };

    async function guardarBD(remitente, texto) {
        try {
            const resp = await fetch('procesar_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= jsJson($_SESSION['token']) ?> },
                body: JSON.stringify({
                    session_id: currentSessionId,
                    nombre: userData.nombre,
                    empresa: userData.empresa,
                    remitente: remitente,
                    mensaje: texto
                })
            });
            const result = await resp.json();
            if (result.session_id) currentSessionId = result.session_id;
        } catch (error) { console.error("Error BD:", error); }
    }

    function printMsg(texto, sender) {
        const div = document.createElement("div");
        div.className = `ds-msg ${sender}`;
        div.innerText = texto;
        msgsContainer.appendChild(div);
        scrollToBottom();
        guardarBD(sender, texto);
    }

    function scrollToBottom() { msgsContainer.scrollTop = msgsContainer.scrollHeight; }

    function botReply(texto, delay = 1000, callback = null) {
        typingIndicator.style.display = "block";
        scrollToBottom();
        setTimeout(() => {
            typingIndicator.style.display = "none";
            printMsg(texto, "bot");
            if(callback) callback();
        }, delay);
    }

    // ==========================================
    // CREADOR DINÁMICO DE BOTONES (LA MAGIA)
    // ==========================================
    function generarBotones(pregunta, opciones) {
        botReply(pregunta, 1200, () => {
            const wrap = document.createElement("div");
            wrap.className = "ds-quick-replies";
            
            opciones.forEach(opc => {
                const btn = document.createElement("button");
                btn.className = "ds-quick-reply-btn";
                btn.innerText = opc.texto;
                btn.onclick = () => {
                    wrap.remove(); // Desaparecen los botones elegidos
                    printMsg(opc.texto, "user");
                    if (opc.accion) opc.accion(); // Ejecuta el siguiente paso
                };
                wrap.appendChild(btn);
            });
            msgsContainer.appendChild(wrap);
            scrollToBottom();
        });
    }

    // ==========================================
    // FLUJOS CONVERSACIONALES PROFUNDOS
    // ==========================================

    // FLUJO 1: Diagnóstico
    function flujoDiagnostico_Q1() {
        generarBotones("Para enfocar bien el diagnóstico, ¿qué área de la empresa genera el mayor volumen de datos?", [
            { texto: "💰 Ventas y Comercial", accion: flujoDiagnostico_Q2 },
            { texto: "⚙️ Operaciones y Logística", accion: flujoDiagnostico_Q2 },
            { texto: "📊 Finanzas y Contabilidad", accion: flujoDiagnostico_Q2 }
        ]);
    }
    function flujoDiagnostico_Q2() {
        generarBotones("Entendido. Y actualmente, ¿sienten que esos datos son 100% confiables para tomar decisiones clave?", [
            { texto: "✅ Sí, pero es lento procesarlos", accion: pedirCorreoFinal },
            { texto: "❌ No, hay inconsistencias o errores", accion: pedirCorreoFinal }
        ]);
    }

    // FLUJO 2: BI y Ciencia de Datos
    function flujoBI_Q1() {
        generarBotones("El Business Intelligence transforma organizaciones. ¿En qué etapa se encuentran hoy?", [
            { texto: "📄 Seguimos usando solo Excel", accion: flujoBI_Q2 },
            { texto: "📉 Tenemos Dashboards pero básicos", accion: flujoBI_Q2 },
            { texto: "🤖 Buscamos modelos predictivos (IA)", accion: flujoBI_Q2 }
        ]);
    }
    function flujoBI_Q2() {
        generarBotones("Perfecto. Si tuvieras una varita mágica, ¿cuál sería el mayor logro a resolver este mes?", [
            { texto: "⏱️ Automatizar reportes (ahorrar tiempo)", accion: pedirCorreoFinal },
            { texto: "📈 Ver rentabilidad en tiempo real", accion: pedirCorreoFinal },
            { texto: "🔮 Predecir demanda o fuga de clientes", accion: pedirCorreoFinal }
        ]);
    }

    // FLUJO 3: Sector Público
    function flujoPublico_Q1() {
        generarBotones("La analítica en el sector público requiere rigor. ¿Para qué nivel de entidad buscas apoyo?", [
            { texto: "🏛️ Alcaldía / Municipio", accion: flujoPublico_Q2 },
            { texto: "🗺️ Gobernación / Departamento", accion: flujoPublico_Q2 },
            { texto: "🇨🇴 Entidad Nacional", accion: flujoPublico_Q2 }
        ]);
    }
    function flujoPublico_Q2() {
        generarBotones("Comprendido. ¿El objetivo principal del proyecto está orientado hacia adentro o hacia los ciudadanos?", [
            { texto: "👥 Tableros de Transparencia (Ciudadanía)", accion: pedirCorreoFinal },
            { texto: "⚙️ Optimización de Presupuesto (Interno)", accion: pedirCorreoFinal }
        ]);
    }

    // FLUJO 4: Gobierno de Datos
    function flujoGobierno_Q1() {
        generarBotones("El Gobierno de Datos es la zapata del edificio analítico. ¿Cuál es el síntoma que más les preocupa hoy?", [
            { texto: "🔄 Datos duplicados o desactualizados", accion: flujoGobierno_Q2 },
            { texto: "🔒 Falta de roles y seguridad", accion: flujoGobierno_Q2 },
            { texto: "🧩 Sistemas desconectados (Silos)", accion: flujoGobierno_Q2 }
        ]);
    }
    function flujoGobierno_Q2() {
        generarBotones("Es un dolor clásico que cuesta mucho dinero mensual. ¿Cuentan actualmente con un equipo de TI dedicado a esto?", [
            { texto: "👨‍💻 Sí, pero están saturados", accion: pedirCorreoFinal },
            { texto: "🤷‍♂️ No, necesitamos apoyo externo", accion: pedirCorreoFinal }
        ]);
    }

    // FLUJO 5: Desarrollo de Software
    function flujoSoftware_Q1() {
        generarBotones("Construimos software a la medida. ¿Qué necesitas desarrollar?", [
            { texto: "🧩 Backend: APIs, bases de datos o integraciones", accion: flujoSoftware_Q2 },
            { texto: "🖥️ Frontend: aplicación web o móvil", accion: flujoSoftware_Q2 },
            { texto: "🔧 Mejorar una aplicación que ya tengo", accion: flujoSoftware_Q2 }
        ]);
    }
    function flujoSoftware_Q2() {
        generarBotones("Perfecto. ¿En qué punto está el proyecto?", [
            { texto: "💡 Es una idea y necesito definirla", accion: pedirCorreoFinal },
            { texto: "📋 Tengo los requerimientos claros", accion: pedirCorreoFinal },
            { texto: "🚀 Ya existe y necesito escalarla", accion: pedirCorreoFinal }
        ]);
    }

    // FLUJO 6: Conferencias de Psicología (Marcela Pacheco Parra)
    function flujoPsicologia_Q1() {
        generarBotones("Nuestras conferencias las dirige Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano (FLACSO). ¿Para quién sería la conferencia?", [
            { texto: "🏢 Equipo de trabajo / empresa", accion: flujoPsicologia_Q2 },
            { texto: "🏫 Colegio o universidad", accion: flujoPsicologia_Q2 },
            { texto: "🏛️ Entidad pública o comunidad", accion: flujoPsicologia_Q2 }
        ]);
    }
    function flujoPsicologia_Q2() {
        generarBotones("¿Qué tema les interesa más?", [
            { texto: "🧠 Bienestar y salud mental", accion: pedirCorreoFinal },
            { texto: "👥 Trabajo en equipo", accion: pedirCorreoFinal },
            { texto: "❤️ Psicología de pareja", accion: pedirCorreoFinal },
            { texto: "🧭 Orientación vocacional", accion: pedirCorreoFinal },
            { texto: "🌱 Inclusión y desarrollo humano", accion: pedirCorreoFinal }
        ]);
    }

    // EL CIERRE (PEDIR EL CORREO)
    function pedirCorreoFinal() {
        estado = "pedir_correo";
        botReply("Excelente, ya tengo una radiografía clara de lo que necesitan. Tenemos casos de éxito resolviendo exactamente este escenario. 🚀\n\nPara enviarte una propuesta preliminar o agendar una llamada de 15 minutos, por favor escribe tu correo electrónico corporativo:");
    }

    // ==========================================
    // MENÚ PRINCIPAL
    // ==========================================
    function mostrarMenuPrincipal() {
        generarBotones("Aquí tienes nuestras líneas de especialidad. Elige la que más resuene con tu necesidad actual:", [
            { texto: "📊 Agendar diagnóstico", accion: flujoDiagnostico_Q1 },
            { texto: "🎤 Conferencias de Psicología", accion: flujoPsicologia_Q1 },
            { texto: "⚙️ BI y Ciencia de Datos", accion: flujoBI_Q1 },
            { texto: "🏛️ Analítica Sector Público", accion: flujoPublico_Q1 },
            { texto: "🛡️ Gobierno y Calidad de Datos", accion: flujoGobierno_Q1 },
            { texto: "💻 Desarrollo de Software", accion: flujoSoftware_Q1 }
        ]);
    }

    function preguntarAlgoMas() {
        generarBotones("¿Hay algún otro tema que quisieras explorar hoy?", [
            { texto: "✅ Sí, volver al menú", accion: mostrarMenuPrincipal },
            { texto: "❌ No, es todo por hoy", accion: despedidaFinal }
        ]);
    }

    function despedidaFinal() {
        botReply("¡Ha sido un verdadero placer! Que tengas un excelente día. Quedamos a tu entera disposición en DataSense. 👋", 1000, () => {
            estado = "fin";
            input.disabled = true;
            input.placeholder = "Conversación finalizada";
        });
    }

    // ==========================================
    // INPUT DE TEXTO (SOLO PARA NOMBRE Y CORREO)
    // ==========================================
    let saludoIniciado = false;
    function iniciarSaludo() {
        if(saludoIniciado) return;
        saludoIniciado = true;
        botReply("👋 ¡Hola! Soy SenseBot, tu consultor analítico en DataSense. Para hacer esto más personal, ¿cómo te llamas?", 1500);
    }

    form.onsubmit = (e) => {
        e.preventDefault();
        const msg = input.value.trim();
        if(!msg) return;

        printMsg(msg, "user");
        input.value = "";

        if(estado === "bienvenida") {
            userData.nombre = msg;
            estado = "empresa";
            botReply(`Mucho gusto, ${userData.nombre}. ¿De qué empresa o entidad nos contactas?`);
        } 
        else if(estado === "empresa") {
            userData.empresa = msg;
            estado = "menu";
            botReply(`¡Genial! En DataSense estamos listos para potenciar a ${userData.empresa}.`);
            mostrarMenuPrincipal();
        }
        else if(estado === "pedir_correo") {
            if(msg.includes("@") && msg.includes(".")) {
                userData.correo = msg;
                estado = "esperando_opcion";
                botReply(`¡Confirmado! 📩 Hemos registrado el correo: ${userData.correo}. Nuestro equipo evaluará tus respuestas y te contactaremos a la brevedad.`, 1500, preguntarAlgoMas);
            } else {
                botReply("Mmm, ese no parece un correo válido. Por favor, asegúrate de incluir el '@' y el dominio (ejemplo: juan@empresa.com).");
            }
        }
        else if (estado !== "fin") {
            botReply("Por favor, utiliza los botones que aparecen arriba para guiar la conversación de forma más rápida. 👆");
        }
    };
});
</script>
<script>
// Tienda en la portada: destacados y unidades del carrito. Si la tienda no responde, queda el botón "Ver toda la tienda"
(function () {
  const destino = document.getElementById('destacadosInicio');
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  fetch('tienda_api.php?accion=destacados', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then((r) => (r.ok ? r.json() : Promise.reject(new Error('sin tienda'))))
    .then((d) => {
      const contador = document.getElementById('contadorCarritoInicio');
      // El carrito solo aparece en el menú cuando tiene productos (el enlace "Tienda" ya lleva al catálogo)
      if (contador && d.unidades) { contador.textContent = d.unidades; contador.hidden = false; document.getElementById('enlaceCarritoInicio').hidden = false; }
      if (!destino) return;
      if (!d.productos || !d.productos.length) { destino.remove(); return; }
      destino.innerHTML = d.productos.map((p) => `<a class="tienda-inicio-card" href="${esc(p.url)}">
          <div class="tienda-inicio-media" style="--color-producto:${esc(p.color)}">${p.imagen ? `<img src="${esc(p.imagen)}" alt="" loading="lazy">` : `<i class="fas ${esc(p.icono)}" aria-hidden="true"></i>`}${p.descuento ? `<span>-${Number(p.descuento)}%</span>` : ''}</div>
          <div class="tienda-inicio-cuerpo"><small>${esc(p.categoria)}</small><h3>${esc(p.nombre)}</h3>
            <div class="tienda-inicio-precio"><b>${esc(p.precio)}</b>${p.anterior ? `<s>${esc(p.anterior)}</s>` : ''}</div>
            <span class="tienda-inicio-ver">Ver producto <i class="fas fa-arrow-right"></i></span></div></a>`).join('');
    })
    .catch(() => { if (destino) destino.remove(); });
})();
</script>
<script>
// Proyectos: filtros por área; se ocultan los botones de áreas que no tienen proyectos.
// En "Todos" se ven los principales y "Ver más proyectos" muestra también los del portafolio (.proyecto-extra).
(function () {
  const tarjetas = Array.from(document.querySelectorAll('.proyectos-rejilla .proyecto-tarjeta'));
  const botones = Array.from(document.querySelectorAll('.filtro-proyecto'));
  const verMas = document.getElementById('verMasProyectos');
  if (!tarjetas.length || !botones.length) return;
  const areas = (t) => (t.dataset.areas || '').split(' ');
  const extras = tarjetas.filter((t) => t.classList.contains('proyecto-extra'));
  let filtro = 'todos';
  let expandido = false;
  const aplicar = function () {
    tarjetas.forEach((t) => {
      const coincide = filtro === 'todos' || areas(t).includes(filtro);
      t.hidden = !coincide || (filtro === 'todos' && !expandido && t.classList.contains('proyecto-extra'));
    });
    if (verMas) {
      verMas.hidden = !(filtro === 'todos' && extras.length);
      verMas.setAttribute('aria-expanded', expandido ? 'true' : 'false');
      verMas.querySelector('i').className = (expandido ? 'fas fa-minus' : 'fas fa-plus') + ' me-2';
      verMas.querySelector('.ver-mas-texto').textContent = expandido ? 'Ver menos proyectos' : 'Ver más proyectos (' + extras.length + ')';
    }
  };
  botones.forEach(function (b) {
    const f = b.dataset.filtro;
    if (f !== 'todos' && !tarjetas.some((t) => areas(t).includes(f))) b.hidden = true;
    b.addEventListener('click', function () {
      botones.forEach((o) => { o.classList.toggle('activo', o === b); o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      filtro = f;
      aplicar();
    });
  });
  if (verMas) {
    verMas.addEventListener('click', function () {
      expandido = !expandido;
      aplicar();
      // Al recoger la lista, se vuelve al comienzo de los proyectos para no quedar más abajo de la página
      if (!expandido) document.getElementById('proyectos').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  aplicar();
})();
</script>
<div class="visor-imagen" id="visorImagen" hidden role="dialog" aria-modal="true" aria-label="Imagen ampliada">
  <button type="button" class="visor-cerrar" aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
  <img src="" alt="">
  <video controls playsinline preload="none" hidden></video>
  <iframe class="visor-marco" hidden title="Video del proyecto" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
  <p class="visor-titulo"></p>
  <a class="visor-enlace" target="_blank" rel="noopener noreferrer" hidden><span>Abrir la página del pitch</span><i class="fas fa-arrow-up-right-from-square"></i></a>
</div>
<script>
// Imágenes de los proyectos: al tocarlas se ven en grande (se cierra con la X, tocando afuera o con Esc).
// Si la imagen tiene data-video, se abre el video con sonido y controles; solo se descarga cuando alguien lo pide.
(function () {
  const visor = document.getElementById('visorImagen');
  if (!visor) return;
  const grande = visor.querySelector('img');
  const video = visor.querySelector('video');
  const marco = visor.querySelector('.visor-marco');
  const titulo = visor.querySelector('.visor-titulo');
  const enlace = visor.querySelector('.visor-enlace');
  const cerrar = visor.querySelector('.visor-cerrar');
  let origen = null;
  // data-video: archivo de video; data-youtube: identificador de un video de YouTube; sin ninguno: imagen
  const abrir = function (img, desde) {
    origen = desde || img;
    const tipo = img.dataset.youtube ? 'youtube' : (img.dataset.video ? 'video' : 'imagen');
    grande.hidden = tipo !== 'imagen';
    video.hidden = tipo !== 'video';
    marco.hidden = tipo !== 'youtube';
    enlace.hidden = tipo === 'imagen' || !img.dataset.pagina;
    if (!enlace.hidden) {
      enlace.href = img.dataset.pagina;
      enlace.querySelector('span').textContent = img.dataset.paginaTexto || 'Abrir la página del pitch';
    }
    titulo.textContent = img.alt;
    visor.setAttribute('aria-label', tipo === 'imagen' ? 'Imagen ampliada' : 'Video del proyecto');
    if (tipo === 'youtube') {
      marco.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(img.dataset.youtube) + '?autoplay=1&rel=0&playsinline=1';
    } else if (tipo === 'video') {
      video.poster = img.currentSrc || img.src;
      video.src = img.dataset.video;
      const intento = video.play();
      if (intento && intento.catch) intento.catch(function () {});   // si el navegador no deja arrancar solo, quedan los controles
    } else {
      grande.src = img.dataset.grande || img.currentSrc || img.src;
      grande.alt = img.alt;
    }
    visor.hidden = false;
    document.body.style.overflow = 'hidden';
    cerrar.focus();
  };
  const salir = function () {
    visor.hidden = true;
    grande.removeAttribute('src');
    if (video.getAttribute('src')) {
      video.pause();
      video.removeAttribute('src');
      video.load();   // corta la descarga del video
    }
    const enMarco = marco.getAttribute('src');
    if (enMarco && enMarco !== 'about:blank') marco.src = 'about:blank';   // descarga el reproductor: el video de YouTube deja de sonar
    document.body.style.overflow = '';
    if (origen) origen.focus();
  };
  // El reproductor de YouTube toma el foco al cargar y así Esc ya no llega a esta página: se devuelve a la X
  marco.addEventListener('load', function () {
    [0, 400, 1200].forEach(function (ms) {
      setTimeout(function () { if (!visor.hidden && document.activeElement === marco) cerrar.focus(); }, ms);
    });
  });
  video.addEventListener('error', function () {
    if (visor.hidden || !video.getAttribute('src')) return;
    titulo.textContent = 'No se pudo cargar el video aquí. Puedes verlo en la página del pitch.';
    enlace.hidden = !enlace.getAttribute('href');
  });
  document.querySelectorAll('.proyecto-media img').forEach(function (img) {
    img.tabIndex = 0;
    img.setAttribute('role', 'button');
    img.title = (img.dataset.video || img.dataset.youtube) ? 'Ver el video' : 'Ver en grande';
    img.addEventListener('click', function () { abrir(img); });
    img.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrir(img); } });
  });
  // Botones "Ver el video" de las tarjetas: abren el mismo visor (sin JavaScript, el enlace lleva a la página del pitch)
  document.querySelectorAll('[data-abrir-video]').forEach(function (boton) {
    const tarjeta = boton.closest('.proyecto-tarjeta');
    const img = tarjeta && tarjeta.querySelector('.proyecto-media img[data-video], .proyecto-media img[data-youtube]');
    if (!img) return;
    boton.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      abrir(img, boton);
    });
  });
  cerrar.addEventListener('click', salir);
  visor.addEventListener('click', function (e) { if (e.target === visor) salir(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !visor.hidden) salir(); });
})();
</script>
<script>
// Proyectos: "Quiero un proyecto así" deja elegido el servicio y empieza el mensaje del formulario de contacto
document.querySelectorAll('[data-proyecto]').forEach(function (enlace) {
  enlace.addEventListener('click', function () {
    const servicio = document.querySelector('#contactForm select[name="servicio"]');
    const mensaje = document.querySelector('#contactForm textarea[name="mensaje"]');
    if (servicio && enlace.dataset.servicio && servicio.querySelector('option[value="' + enlace.dataset.servicio + '"]')) {
      servicio.value = enlace.dataset.servicio;
    }
    if (mensaje && mensaje.value.trim() === '') {
      mensaje.value = 'Hola, me interesa ' + (enlace.dataset.proyecto.indexOf('una demostración') === 0 ? '' : 'un proyecto como ') + enlace.dataset.proyecto + '. ';
    }
  });
});
</script>
<script>
// Videos de las conferencias: se muestra la miniatura y YouTube solo se carga cuando la persona da clic (página más liviana)
document.querySelectorAll('.video-yt').forEach(function (boton) {
  boton.addEventListener('click', function () {
    const id = boton.dataset.video;
    if (!/^[A-Za-z0-9_-]{11}$/.test(id)) return;
    const marco = document.createElement('iframe');
    marco.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
    marco.title = boton.dataset.titulo || 'Conferencia en video';
    marco.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
    marco.allowFullscreen = true;
    marco.className = 'video-iframe';
    boton.replaceWith(marco);
  });
});
</script>
</body>
</html>