<?php
/**
 * PÁGINAS LEGALES - ENCABEZADO, PIE Y DATOS DE LA EMPRESA
 * Las usan privacidad.php (Política de tratamiento de datos, Ley 1581 de 2012) y terminos.php (Términos de uso).
 * Las redes sociales (Meta, LinkedIn, TikTok) piden la dirección de estas dos páginas para aprobar las apps que publican.
 *
 * Datos opcionales del .env / variables del servidor:
 *   EMPRESA_NOMBRE   razón social (por defecto, el nombre comercial)
 *   EMPRESA_NIT      solo se muestra si está definido
 *   EMPRESA_CIUDAD   domicilio (por defecto, Colombia)
 *   EMPRESA_CORREO   correo para ejercer los derechos sobre los datos (si no está, se usa el canal de PQRSD)
 */
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/utilidades.php';
require_once __DIR__ . '/redes.php';
// Estas páginas no usan la base de datos: el .env se carga aquí (en el hosting las variables ya vienen del servidor)
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

const LEGAL_VIGENCIA = '24 de noviembre de 2026';

function datosLegales() {
    $valor = function ($clave, $porDefecto = '') { return trim((string) ($_ENV[$clave] ?? '')) ?: $porDefecto; };
    $correo = $valor('EMPRESA_CORREO');
    $whatsapp = preg_replace('/\D+/', '', (string) ($_ENV['SOCIAL_WHATSAPP'] ?? ''));
    return [
        'comercial' => 'DataSense Analytics Insights',
        'razon'     => $valor('EMPRESA_NOMBRE', 'DataSense Analytics Insights'),
        'nit'       => $valor('EMPRESA_NIT'),
        'ciudad'    => $valor('EMPRESA_CIUDAD', 'Colombia'),
        'correo'    => filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '',
        'whatsapp'  => strlen($whatsapp) >= 10 ? $whatsapp : '',
        'sitio'     => urlBase(),
    ];
}

// Teléfono de WhatsApp para mostrar: 573133898638 -> +57 313 389 8638
function telefonoLegible($numero) {
    if (preg_match('/^57(\d{3})(\d{3})(\d{4})$/', $numero, $m)) return '+57 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    return '+' . $numero;
}

function cabeceraLegal($titulo, $descripcion, $actual) {
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($titulo) ?> | DataSense Analytics Insights</title>
    <meta name="description" content="<?= esc($descripcion) ?>">
    <?= metaSociales($titulo . ' | DataSense Analytics Insights', $descripcion) ?>
    <link rel="icon" href="img/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { --azul: #0A2E4F; --verde: #3FAE49; --texto: #1f2937; --suave: #f4f6fa; }
        body { background: var(--suave); color: var(--texto); }
        .barra-legal { background: rgba(255, 255, 255, .95); border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; z-index: 20; }
        .barra-legal img { height: 40px; }
        .enlace-barra { color: #475569; font-weight: 600; text-decoration: none; }
        .enlace-barra:hover, .enlace-barra.activo { color: var(--azul); }
        .enlace-barra.activo { border-bottom: 2px solid var(--verde); }
        .portada-legal { background: linear-gradient(120deg, #0A2E4F 0%, #14507f 60%, #1b6fb0 100%); color: #fff; }
        .portada-legal .vigencia { background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .25); border-radius: 999px;
            padding: .3rem .9rem; font-size: .82rem; font-weight: 600; display: inline-block; }
        .documento { background: #fff; border-radius: 18px; box-shadow: 0 2px 16px rgba(15, 23, 42, .06); padding: clamp(1.25rem, 4vw, 2.75rem); margin-top: -2rem; position: relative; }
        .documento h2 { font-size: 1.2rem; font-weight: 800; color: var(--azul); margin-top: 2rem; padding-top: .5rem; scroll-margin-top: 80px; }
        .documento h2:first-of-type { margin-top: 0; }
        .documento p, .documento li { line-height: 1.7; }
        .documento .indice { background: var(--suave); border-radius: 14px; padding: 1rem 1.25rem; }
        .documento .indice ol { margin: 0; padding-left: 1.2rem; columns: 2 260px; }
        .documento .indice a { text-decoration: none; }
        .documento .datos { border-left: 4px solid var(--verde); background: #f0fdf4; border-radius: 10px; padding: 1rem 1.25rem; }
        footer.pie { color: #64748b; font-size: .85rem; }
        footer.pie a { text-decoration: none; }
    </style>
</head>
<body>
<header class="barra-legal py-2">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
        <a href="index.php"><img src="img/logo.png" alt="DataSense Analytics Insights"></a>
        <nav class="d-flex flex-wrap gap-3 align-items-center">
            <a href="index.php" class="enlace-barra">Inicio</a>
            <a href="privacidad.php" class="enlace-barra<?= $actual === 'privacidad' ? ' activo' : '' ?>">Privacidad</a>
            <a href="terminos.php" class="enlace-barra<?= $actual === 'terminos' ? ' activo' : '' ?>">Términos</a>
            <a href="pqrsd.php" class="enlace-barra">PQRSD</a>
        </nav>
    </div>
</header>
<section class="portada-legal pt-5 pb-5">
    <div class="container pb-4">
        <h1 class="fw-bold mb-2"><?= esc($titulo) ?></h1>
        <p class="mb-3 opacity-75"><?= esc($descripcion) ?></p>
        <span class="vigencia"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i> Vigente desde el <?= LEGAL_VIGENCIA ?></span>
    </div>
</section>
<main class="container pb-5">
    <article class="documento">
    <?php
}

function pieLegal() {
    ?>
    </article>
</main>
<footer class="pie text-center py-4">
    © <?= date('Y') ?> DataSense Analytics Insights ·
    <a href="privacidad.php">Política de privacidad</a> · <a href="terminos.php">Términos de uso</a> · <a href="index.php">Volver al sitio</a>
    <div class="d-flex flex-wrap justify-content-center align-items-center gap-3 mt-3"><?= iconosRedes('Síguenos') ?></div>
</footer>
<?= estiloRedes() ?>
</body>
</html>
    <?php
}

// Bloque con los datos del responsable y los canales de atención (se repite en las dos páginas)
function bloqueResponsable(array $d) {
    ?>
    <div class="datos">
        <p class="mb-1"><strong><?= esc($d['razon']) ?></strong><?= $d['razon'] !== $d['comercial'] ? ' (' . esc($d['comercial']) . ')' : '' ?></p>
        <?php if ($d['nit'] !== ''): ?><p class="mb-1">NIT: <?= esc($d['nit']) ?></p><?php endif; ?>
        <p class="mb-1">Domicilio: <?= esc($d['ciudad']) ?></p>
        <p class="mb-1">Sitio web: <a href="<?= esc($d['sitio']) ?>"><?= esc(rtrim($d['sitio'], '/')) ?></a></p>
        <p class="mb-1">Canal de PQRSD: <a href="pqrsd.php"><?= esc(rtrim($d['sitio'], '/')) ?>/pqrsd.php</a></p>
        <?php if ($d['correo'] !== ''): ?><p class="mb-1">Correo: <a href="mailto:<?= esc($d['correo']) ?>"><?= esc($d['correo']) ?></a></p><?php endif; ?>
        <?php if ($d['whatsapp'] !== ''): ?><p class="mb-0">WhatsApp: <a href="https://wa.me/<?= esc($d['whatsapp']) ?>" target="_blank" rel="noopener noreferrer"><?= esc(telefonoLegible($d['whatsapp'])) ?></a></p><?php endif; ?>
    </div>
    <?php
}
