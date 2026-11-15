<?php
/**
 * REDES SOCIALES Y DIFUSIÓN
 * Tres cosas, todas para que la plataforma se conozca:
 *   1. metaSociales()    las etiquetas del <head> para que al pegar el enlace en WhatsApp, LinkedIn o
 *                        Facebook salga una tarjeta con logo, título y descripción (Open Graph y Twitter Card),
 *                        más los datos estructurados que Google usa para mostrar la empresa y sus perfiles.
 *   2. iconosRedes()     los iconos de los perfiles para el pie del sitio.
 *   3. botonesCompartir() los botones con los que un visitante comparte la página.
 *
 * Los perfiles se configuran en el .env y el que no esté configurado simplemente no se muestra:
 *   SOCIAL_LINKEDIN=https://www.linkedin.com/company/tu-empresa
 *   SOCIAL_FACEBOOK=https://www.facebook.com/tu-pagina
 *   SOCIAL_INSTAGRAM=https://www.instagram.com/tu-cuenta
 *   SOCIAL_YOUTUBE=https://www.youtube.com/@tu-canal
 *   SOCIAL_TIKTOK=https://www.tiktok.com/@tu-cuenta
 *   SOCIAL_X=https://x.com/tu-cuenta
 *   SOCIAL_WHATSAPP=573000000000
 * Para que las direcciones queden absolutas conviene definir también APP_URL con el dominio real.
 */
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/utilidades.php';

// Clave del .env => cómo se muestra
const REDES_DISPONIBLES = [
    'SOCIAL_LINKEDIN'  => ['nombre' => 'LinkedIn',  'icono' => 'fa-brands fa-linkedin-in', 'color' => '#0a66c2'],
    'SOCIAL_FACEBOOK'  => ['nombre' => 'Facebook',  'icono' => 'fa-brands fa-facebook-f',  'color' => '#1877f2'],
    'SOCIAL_INSTAGRAM' => ['nombre' => 'Instagram', 'icono' => 'fa-brands fa-instagram',   'color' => '#e4405f'],
    'SOCIAL_YOUTUBE'   => ['nombre' => 'YouTube',   'icono' => 'fa-brands fa-youtube',     'color' => '#ff0000'],
    'SOCIAL_TIKTOK'    => ['nombre' => 'TikTok',    'icono' => 'fa-brands fa-tiktok',      'color' => '#111111'],
    'SOCIAL_X'         => ['nombre' => 'X',         'icono' => 'fa-brands fa-x-twitter',   'color' => '#111111'],
];

// Perfiles configurados de verdad (solo direcciones completas, para no dejar enlaces roto)
function redesSociales() {
    $lista = [];
    foreach (REDES_DISPONIBLES as $clave => $red) {
        $url = trim((string) ($_ENV[$clave] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        $lista[$clave] = $red + ['url' => $url];
    }
    // WhatsApp se arma con el número, no con una dirección
    $numero = preg_replace('/\D+/', '', (string) ($_ENV['SOCIAL_WHATSAPP'] ?? ''));
    if (strlen((string) $numero) >= 10) {
        $lista['SOCIAL_WHATSAPP'] = ['nombre' => 'WhatsApp', 'icono' => 'fa-brands fa-whatsapp', 'color' => '#25d366',
                                     'url' => 'https://wa.me/' . $numero];
    }
    return $lista;
}

// Dirección de la página actual, sin lo que venga después del "?": ahí pueden ir claves de consulta
function urlPaginaActual() {
    $pagina = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    return urlBase() . ($pagina === 'index.php' ? '' : $pagina);
}

/**
 * Etiquetas del <head>. $imagen es una ruta del proyecto (por ejemplo img/banner.png) y se vuelve absoluta,
 * que es lo único que aceptan las redes. Las medidas de la imagen ayudan a que se vea completa.
 */
function metaSociales($titulo, $descripcion, $imagen = 'img/banner.png', $tipo = 'website') {
    $base = urlBase();
    $url = urlPaginaActual();
    $img = preg_match('#^https?://#i', (string) $imagen) ? $imagen : $base . ltrim((string) $imagen, '/');
    $empresa = function_exists('datosEmpresa') ? datosEmpresa()['nombre'] : 'DataSense Analytics Insights';
    $titulo = trim((string) $titulo);
    $descripcion = trim(preg_replace('/\s+/', ' ', (string) $descripcion));

    $html = '<link rel="canonical" href="' . esc($url) . '">' . "\n"
          . '  <meta property="og:type" content="' . esc($tipo) . '">' . "\n"
          . '  <meta property="og:site_name" content="' . esc($empresa) . '">' . "\n"
          . '  <meta property="og:locale" content="es_CO">' . "\n"
          . '  <meta property="og:title" content="' . esc($titulo) . '">' . "\n"
          . '  <meta property="og:description" content="' . esc($descripcion) . '">' . "\n"
          . '  <meta property="og:url" content="' . esc($url) . '">' . "\n"
          . '  <meta property="og:image" content="' . esc($img) . '">' . "\n"
          . '  <meta property="og:image:alt" content="' . esc($empresa) . '">' . "\n"
          . '  <meta name="twitter:card" content="summary_large_image">' . "\n"
          . '  <meta name="twitter:title" content="' . esc($titulo) . '">' . "\n"
          . '  <meta name="twitter:description" content="' . esc($descripcion) . '">' . "\n"
          . '  <meta name="twitter:image" content="' . esc($img) . '">' . "\n"
          . '  <meta name="theme-color" content="#0A2E4F">' . "\n";

    // Datos estructurados: con esto Google puede mostrar la empresa y sus perfiles
    $perfiles = array_values(array_map(function ($r) { return $r['url']; }, redesSociales()));
    $datos = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $empresa,
              'url' => $base, 'logo' => $base . 'img/logo.png', 'description' => $descripcion];
    if ($perfiles) $datos['sameAs'] = $perfiles;
    $html .= '  <script type="application/ld+json">' . json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    return $html;
}

// Iconos de los perfiles para el pie. Si no hay ninguno configurado, no pinta nada.
function iconosRedes($titulo = 'Síguenos') {
    $redes = redesSociales();
    if (!$redes) return '';
    $html = '<div class="redes-sociales">';
    if ($titulo !== '') $html .= '<span class="redes-titulo">' . esc($titulo) . '</span>';
    foreach ($redes as $red) {
        $html .= '<a href="' . esc($red['url']) . '" class="red-social" target="_blank" rel="noopener noreferrer me"'
               . ' style="--color-red: ' . esc($red['color']) . '" aria-label="' . esc($red['nombre']) . '"'
               . ' title="' . esc($red['nombre']) . '"><i class="' . esc($red['icono']) . '" aria-hidden="true"></i></a>';
    }
    return $html . '</div>';
}

/**
 * Botones para que el visitante comparta la página. $titulo es el texto que se propone;
 * $url vacío significa "esta página".
 */
function botonesCompartir($titulo, $url = '', $etiqueta = 'Compartir') {
    $url = $url !== '' ? $url : urlPaginaActual();
    $t = rawurlencode(trim((string) $titulo));
    $u = rawurlencode($url);
    $destinos = [
        ['WhatsApp', 'fa-brands fa-whatsapp', '#25d366', "https://api.whatsapp.com/send?text=$t%20$u"],
        ['LinkedIn', 'fa-brands fa-linkedin-in', '#0a66c2', "https://www.linkedin.com/sharing/share-offsite/?url=$u"],
        ['Facebook', 'fa-brands fa-facebook-f', '#1877f2', "https://www.facebook.com/sharer/sharer.php?u=$u"],
        ['X', 'fa-brands fa-x-twitter', '#111111', "https://x.com/intent/post?text=$t&url=$u"],
    ];
    $html = '<div class="compartir"><span class="compartir-titulo">' . esc($etiqueta) . '</span>';
    foreach ($destinos as $d) {
        list($nombre, $icono, $color, $enlace) = $d;
        $html .= '<a href="' . esc($enlace) . '" class="compartir-boton" target="_blank" rel="noopener noreferrer"'
               . ' style="--color-red: ' . esc($color) . '" aria-label="Compartir en ' . esc($nombre) . '"'
               . ' title="Compartir en ' . esc($nombre) . '"><i class="' . esc($icono) . '" aria-hidden="true"></i></a>';
    }
    return $html . '</div>';
}

/**
 * Botón flotante de WhatsApp para el sitio público, con el mensaje ya escrito.
 * Solo aparece si hay un número en SOCIAL_WHATSAPP; si no, no pinta nada.
 */
function botonWhatsapp($mensaje = '') {
    $numero = preg_replace('/\D+/', '', (string) ($_ENV['SOCIAL_WHATSAPP'] ?? ''));
    if (strlen((string) $numero) < 10) return '';
    $texto = trim((string) $mensaje) !== '' ? $mensaje : 'Hola, vengo del sitio de DataSense Analytics y quiero más información.';
    $enlace = 'https://wa.me/' . $numero . '?text=' . rawurlencode($texto);
    return '<a href="' . esc($enlace) . '" class="wpp-flotante" target="_blank" rel="noopener noreferrer"'
         . ' aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>'
         . '<style>
        /* A la izquierda: a la derecha vive el botón del chat y se encaramaban uno sobre otro */
        .wpp-flotante { position: fixed; left: 18px; bottom: 18px; z-index: 1080; width: 56px; height: 56px; border-radius: 50%;
            background: #25d366; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px;
            box-shadow: 0 6px 20px rgba(37, 211, 102, .45); text-decoration: none; transition: transform .2s ease, box-shadow .2s ease; }
        .wpp-flotante:hover, .wpp-flotante:focus-visible { color: #fff; transform: scale(1.08); box-shadow: 0 10px 26px rgba(37, 211, 102, .55); }
        @media (max-width: 575px) { .wpp-flotante { left: 14px; bottom: 14px; width: 50px; height: 50px; font-size: 25px; } }
        @media (prefers-reduced-motion: reduce) { .wpp-flotante { transition: none; } }
        @media print { .wpp-flotante { display: none; } }
    </style>';
}

// Estilos y comportamiento de los iconos y de los botones (se incluye una vez, al final de la página)
function estiloRedes() {
    return '<style>
        .redes-sociales, .compartir { display: inline-flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .redes-titulo, .compartir-titulo { font-size: .85rem; opacity: .8; }
        .red-social, .compartir-boton { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 50%; border: 1px solid rgba(148, 163, 184, .45); color: inherit; background: transparent;
            text-decoration: none; transition: background-color .2s ease, color .2s ease, transform .2s ease; }
        .red-social:hover, .red-social:focus-visible, .compartir-boton:hover, .compartir-boton:focus-visible {
            background: var(--color-red, #0A2E4F); border-color: var(--color-red, #0A2E4F); color: #fff; transform: translateY(-2px); }
        @media (prefers-reduced-motion: reduce) { .red-social, .compartir-boton { transition: none; } }
    </style>';
}
