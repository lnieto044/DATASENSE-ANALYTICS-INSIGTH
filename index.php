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

/* Conferencias de Marcela Pacheco Parra en YouTube: se muestran en la sección "Conferencias corporativas".
   Para agregar una, copia el código del video (lo que va después de "watch?v=" en el enlace) y su título.
   Ejemplo: ['id' => 'abcdEFGhijk', 'titulo' => 'Bienestar emocional en el trabajo']. Sin videos, el bloque no aparece. */
$videos_marcela = array_values(array_filter([
    // TEMPORAL: ejemplos mientras llegan los videos de Marcela (uno de ONU Derechos Humanos y dos del canal de Luis, youtube.com/@LUISGUILLERMONIETO)
    ['id' => 'MkR7aCgOkUc', 'titulo' => 'Principios Rectores sobre las Empresas y los Derechos Humanos (ONU Derechos Humanos)'],
    ['id' => 'ie6oFx1yuZo', 'titulo' => 'Estrategias de organización de eventos'],
    ['id' => 'rWJvVq5Gu4I', 'titulo' => 'Oportunidades de mejora en medio ambiente y SST'],
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
      <a href="#elegirnos">Nuestro enfoque</a>
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
        <div class="team-degree"><i class="fas fa-graduation-cap me-2"></i> Psicóloga · Magíster en Desarrollo Humano (FLACSO)</div>
        <p class="team-desc">Magíster en Desarrollo Humano de FLACSO Argentina, con formación en Análisis de Datos certificada por el MinTIC. Integra la psicología y la analítica para que cada decisión basada en datos ponga a las personas en el centro y genere un impacto humano medible.</p>
        <p class="team-desc mb-0"><i class="fas fa-comments me-2"></i><b>Conferencista en psicología</b> para organizaciones nacionales e internacionales: bienestar, inclusión y desarrollo humano.</p>
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
        <div class="team-degree"><i class="fas fa-graduation-cap me-2"></i> Candidato a Magíster en Analítica de Datos</div>
        <p class="team-desc">Candidato a magíster en Analítica de Datos, con formación en Análisis de Datos certificada por el MinTIC. Lidera la estrategia de negocio y la visión tecnológica de DataSense. Acompaña a gerencias y entidades nacionales e internacionales a convertir sus datos en decisiones rentables: tableros ejecutivos, analítica avanzada y software a la medida que reducen costos, aceleran la gestión y muestran resultados desde el primer mes.</p>
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
        <p>Conferencias y talleres corporativos para empresas, instituciones educativas y entidades sobre bienestar, inclusión y desarrollo humano, dirigidos por Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano. <a href="#contacto" class="fw-bold">Solicítala aquí</a>.</p>
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
        <p>Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano de FLACSO Argentina, lleva a su organización conferencias y talleres que fortalecen el bienestar, la inclusión y el liderazgo, con la mirada de la psicología y el respaldo de los datos.</p>
        <ul class="conferencias-temas">
          <li><i class="fas fa-brain" aria-hidden="true"></i>Bienestar y salud mental</li>
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
    <div class="conferencias-videos" id="videosMarcela">
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

<section id="elegirnos" class="section gray section-padding" style="background:#f8fafc;">
  <div class="container">
    <div class="section-header text-center" style="margin-bottom: 50px;">
      <h2 style="font-size: 2.5rem; color: #0A2E4F; margin-bottom:10px;">Nuestro Enfoque</h2>
      <span class="section-line" style="display:block; width:60px; height:4px; background:#3FAE49; margin: 0 auto; border-radius:2px;"></span>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
      
      <div class="enfoque-card">
        <div class="enfoque-icon-box">
          <i class="fas fa-check-circle"></i>
        </div>
        <h3>Datos que se usan</h3>
        <p>Diseñamos análisis orientados a la toma de decisiones reales, evitando informes extensos que no generan impacto.</p>
      </div>

      <div class="enfoque-card">
        <div class="enfoque-icon-box">
          <i class="fas fa-shield-alt"></i>
        </div>
        <h3>Respaldo técnico</h3>
        <p>Nuestros análisis brindan sustento técnico y trazabilidad para respaldar decisiones estratégicas.</p>
      </div>

      <div class="enfoque-card">
        <div class="enfoque-icon-box">
          <i class="fas fa-balance-scale"></i>
        </div>
        <h3>Uso responsable</h3>
        <p>Priorizamos soluciones viables, sostenibles y medibles para organizaciones públicas y privadas.</p>
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
            { texto: "🤝 Inclusión y diversidad", accion: pedirCorreoFinal },
            { texto: "🌱 Desarrollo humano y liderazgo", accion: pedirCorreoFinal }
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