<?php
/**
 * MAPA DEL SITIO para los buscadores.
 * Lista las páginas públicas y, si la tienda está disponible, sus productos activos.
 * Se arma con urlBase(), así que funciona igual en este computador y en el hosting;
 * para que las direcciones queden con tu dominio, define APP_URL en el .env.
 * Regístralo en Google Search Console como https://tudominio/sitemap.php
 */
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/utilidades.php';

$base = urlBase();
$hoy = date('Y-m-d');

// [ruta, con qué frecuencia cambia, importancia]
$paginas = [
    ['', 'weekly', '1.0'],
    ['tienda.php', 'daily', '0.9'],
    ['pqrsd.php', 'monthly', '0.8'],
    ['privacidad.php', 'yearly', '0.4'],
    ['terminos.php', 'yearly', '0.4'],
    ['admin/login.php', 'yearly', '0.3'],
];

$productos = [];
try {
    $db = require __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/tienda.php';
    if (function_exists('tiendaDisponible') && tiendaDisponible($db)) {
        // Las fechas de la tienda se llaman created_at y updated_at
        $stmt = $db->query("SELECT slug, COALESCE(updated_at, created_at) AS fecha FROM tienda_productos
                            WHERE estado = 'Activo' AND slug <> '' ORDER BY id LIMIT 500");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (trim((string) $p['slug']) === '') continue;
            $productos[] = ['producto.php?p=' . rawurlencode($p['slug']), substr((string) $p['fecha'], 0, 10) ?: $hoy];
        }
    }
} catch (Exception $e) {
    // Sin base de datos el mapa sale igual con las páginas fijas
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($paginas as $p) {
    list($ruta, $frecuencia, $peso) = $p;
    echo "  <url>\n    <loc>" . esc($base . $ruta) . "</loc>\n    <lastmod>$hoy</lastmod>\n"
       . "    <changefreq>$frecuencia</changefreq>\n    <priority>$peso</priority>\n  </url>\n";
}
foreach ($productos as $p) {
    echo "  <url>\n    <loc>" . esc($base . $p[0]) . "</loc>\n    <lastmod>" . esc($p[1]) . "</lastmod>\n"
       . "    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>\n";
}
echo '</urlset>' . "\n";
