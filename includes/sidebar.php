<?php
/**
 * SIDEBAR UNIFICADO COMPLETO - DATASENSE ENTERPRISE
 * Arquitectura: Operación, Mesa de Servicios, Finanzas, Tienda, Mercadeo, Automatización y Administración.
 */
require_once __DIR__ . '/seguridad.php';

$p = basename($_SERVER['PHP_SELF']);
// Páginas secundarias que marcan como activo el enlace de su módulo
$menu_alias = [
    'nota_debito.php' => 'nota_credito.php', 'nueva_nota_factura.php' => 'nota_credito.php', 'nueva_cuenta_por_pagar.php' => 'cuentas_por_pagar.php',
    'nuevo_ingreso.php' => 'comprobante_ingreso.php', 'nuevo_egreso.php' => 'comprobante_egreso.php', 'nueva_nota_contable.php' => 'nota_contable.php',
    'comprobante_cierre.php' => 'cierre_contable.php', 'certificado_retencion.php' => 'impuestos.php',
    'cliente_ficha.php' => 'clientes.php',
];
$p_menu = $menu_alias[$p] ?? $p;
$rol_user = $_SESSION['admin_rol'] ?? 'Cliente';
$es_admin = in_array($rol_user, ['Admin', 'Soporte', 'Supervisor'], true); // Mesa de Servicios (los mismos de gestion_it.php, más quien supervisa)

// Menú por secciones: [módulo de permisos, página, icono, texto]. El módulo null indica que solo lo ve la Mesa de Servicios.
// Una sección solo se muestra si el rol puede ver al menos uno de sus enlaces.
$menu_secciones = [
    'Operación y Análisis' => [
        ['dashboard', 'dashboard.php', 'fa-chart-line', 'Dashboard'],
        ['mi_resumen', 'mi_resumen.php', 'fa-chart-pie', 'Mi Resumen'],
        ['mensajes', 'mensajes.php', 'fa-envelope-open-text', 'Mensajes Web'],
        ['chats', 'admin_chats.php', 'fa-robot', 'Historial SenseBot'],
        ['clientes', 'clientes.php', 'fa-users-cog', 'Gestión Clientes'],
    ],
    'Mesa de Servicios (ITSM)' => [
        ['soporte', 'soporte.php', 'fa-headset', 'Centro de Soporte'],
        ['gestion_it', 'gestion_it.php', 'fa-laptop-code', 'Gestión IT'],
        ['supervisor_it', 'supervisor_it.php', 'fa-eye', 'Supervisión y Cargas'],
        ['reporte_ejecutivo', 'reporte_ejecutivo.php', 'fa-medal', 'Reporte Ejecutivo TIC'],
        ['reporte_desempeno', 'reporte_desempeno.php', 'fa-ranking-star', 'Desempeño y Bonos'],
    ],
    'Atención Ciudadana' => [
        ['pqrsd', 'pqrsd.php', 'fa-scale-balanced', 'PQRSD'],
        ['supervisor_pqrsd', 'supervisor_pqrsd.php', 'fa-user-shield', 'Supervisión PQRSD'],
        ['reporte_pqrsd', 'reporte_pqrsd.php', 'fa-file-contract', 'Reporte PQRSD'],
        // Atajo a la página que ve el ciudadano: solo para quien supervisa el canal (la página es pública)
        [['supervisor_pqrsd'], '../pqrsd.php', 'fa-globe', 'Canal público'],
    ],
    // El ciclo se lee en orden: lo que se cobra, lo que se paga y la contabilidad con sus informes
    'Facturación y Cartera' => [
        ['facturacion', 'facturacion.php', 'fa-file-invoice-dollar', 'Facturación'],
        ['notas_factura', 'nota_credito.php', 'fa-file-circle-minus', 'Notas Crédito y Débito'],
        ['comprobante_ingreso', 'comprobante_ingreso.php', 'fa-hand-holding-usd', 'Comprobantes de Ingreso'],
        ['mis_facturas', 'mis_facturas.php', 'fa-receipt', 'Mis Facturas'],
    ],
    'Tesorería y Bancos' => [
        ['cuentas_por_pagar', 'cuentas_por_pagar.php', 'fa-file-invoice', 'Cuentas por Pagar'],
        ['comprobante_egreso', 'comprobante_egreso.php', 'fa-money-check-alt', 'Comprobantes de Egreso'],
        ['conciliacion_bancaria', 'conciliacion_bancaria.php', 'fa-university', 'Conciliación Bancaria'],
    ],
    'Contabilidad e Informes' => [
        ['nota_contable', 'nota_contable.php', 'fa-file-signature', 'Notas Contables'],
        ['libro_auxiliar', 'libro_auxiliar.php', 'fa-book-open', 'Libro Auxiliar NIIF'],
        ['libro_mayor', 'libro_mayor.php', 'fa-book-reader', 'Libro Mayor'],
        ['impuestos', 'impuestos.php', 'fa-percent', 'Impuestos y Retenciones'],
        ['cierre_contable', 'cierre_contable.php', 'fa-lock', 'Cierre Contable'],
        ['estado_resultados', 'estado_resultados.php', 'fa-chart-pie', 'Estado Resultados'],
        ['balance_general', 'balance_general.php', 'fa-balance-scale-right', 'Balance General'],
    ],
    'Tienda en Línea' => [
        ['tienda', 'tienda_productos.php', 'fa-store', 'Productos y Cupones'],
        ['tienda', 'tienda_pedidos.php', 'fa-truck-ramp-box', 'Pedidos de la Tienda'],
        ['mis_pedidos', 'mis_pedidos.php', 'fa-bag-shopping', 'Mis Pedidos'],
        [['tienda', 'mis_pedidos'], '../tienda.php', 'fa-cart-shopping', 'Ir a la Tienda'],
    ],
    'Mercadeo y Redes' => [
        ['redes', 'redes_sociales.php', 'fa-bullhorn', 'Redes Sociales'],
    ],
    'Automatización' => [
        ['plantillas', 'plantillas.php', 'fa-magic', 'Plantillas Auto'],
        ['catalogo', 'catalogo.php', 'fa-boxes', 'Catálogo Servicios'],
        ['encuestas', 'encuestas.php', 'fa-poll-h', 'Encuestas NPS'],
        ['reuniones', 'salas_reuniones.php', 'fa-video', 'Salas de Reunión'],
    ],
    'Administración del Sistema' => [
        ['equipo', 'equipo.php', 'fa-user-tie', 'Mi Equipo'],
        ['roles', 'roles.php', 'fa-user-shield', 'Seguridad y Roles'],
    ],
];
?>

<nav class="sidebar" id="sidebar" aria-label="Menú principal">

    <button type="button" class="btn-cerrar-menu" id="btnCerrarMenu" aria-label="Cerrar el menú"><i class="fas fa-times"></i></button>
    <div class="sidebar-logo text-center">
        <a href="<?= esc(paginaInicio($rol_user, $db)) ?>">
            <img src="../img/logo.png" alt="DataSense Analytics" class="logo_img">
        </a>
    </div>

    <div class="nav flex-column mb-5">

        <?php $menu_panel_visible = []; foreach ($menu_secciones as $menu_titulo => $menu_enlaces):
            $menu_visibles = array_filter($menu_enlaces, function ($enlace) use ($rol_user, $db, $es_admin) {
                if ($enlace[0] === null) return $es_admin;
                foreach ((array) $enlace[0] as $modulo_menu) {
                    if (tieneAcceso($rol_user, $modulo_menu, $db)) return true; // Enlaces de varios módulos: basta con uno
                }
                return false;
            });
            if (!$menu_visibles) continue;
            // Bases que aún no tienen la migración 7 (tienda): la sección no se muestra en lugar de llevar a páginas con error
            if ($menu_titulo === 'Tienda en Línea') {
                try {
                    $db->query("SELECT 1 FROM tienda_productos LIMIT 1");
                } catch (Exception $e) {
                    continue;
                }
            }
            $menu_panel_visible[$menu_titulo] = $menu_visibles; // El Dashboard lo usa para mostrar todos los módulos del rol
        ?>
        <div class="sidebar-header"><?= $menu_titulo ?></div>

        <?php foreach ($menu_visibles as [$menu_modulo, $menu_pagina, $menu_icono, $menu_texto]): ?>
        <a href="<?= $menu_pagina ?>" class="nav-link <?= ($p_menu == $menu_pagina) ? 'active' : ''; ?>">
            <i class="fas <?= $menu_icono ?>"></i> <span class="texto-menu"><?= $menu_texto ?></span>
        </a>
        <?php endforeach; ?>

        <?php endforeach; ?>

        <div class="mt-auto pb-3 pt-4 border-top border-secondary border-opacity-10">
            <a href="perfil.php" class="nav-link <?= ($p == 'perfil.php') ? 'active' : ''; ?>">
                <i class="fas fa-user-circle"></i> <span class="texto-menu">Mi Perfil</span>
            </a>
            <a href="logout.php" class="nav-link text-danger">
                <i class="fas fa-power-off"></i> <span class="texto-menu">Salir del Sistema</span>
            </a>
        </div>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

<style>
/* Estilos Enterprise para DataSense Analytics */
.sidebar {
    background: #0A2E4F; /* Azul Marino Corporativo */
    min-width: 260px;
    max-width: 260px;
    min-height: 100vh;
    color: #cbd5e1;
    transition: all 0.3s;
    overflow-y: auto;
}

.sidebar-logo {
    padding: 25px 15px;
}

.logo_img {
    max-width: 170px;
    height: auto;
}

.sidebar-header {
    padding: 20px 24px 8px;
    font-size: 0.62rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: #7dd3fc !important; /* Azul Celeste Aranda */
    border-bottom: 1px solid rgba(125, 211, 252, 0.1);
    margin-bottom: 5px;
}

/* Solo los enlaces del menú: las pestañas (nav-link) dentro de las páginas conservan su estilo */
.sidebar .nav-link {
    padding: 11px 24px;
    color: #cbd5e1;
    font-size: 0.88rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    transition: 0.2s;
    text-decoration: none;
    border-left: 4px solid transparent;
}

.sidebar .nav-link i {
    width: 25px;
    margin-right: 12px;
    font-size: 1.05rem;
    text-align: center;
    opacity: 0.7;
}

.sidebar .nav-link:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #fff;
}

.sidebar .nav-link.active {
    background: rgba(125, 211, 252, 0.1);
    color: #7dd3fc;
    border-left: 4px solid #7dd3fc;
    font-weight: 600;
}

.sidebar .nav-link.active i {
    opacity: 1;
}

.sidebar .text-danger:hover {
    background: rgba(248, 113, 113, 0.1) !important;
}

/* Scrollbar del sidebar en el verde de la marca (igual que css/admin.css) */
.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
.sidebar::-webkit-scrollbar-thumb { background: var(--verde-datasense, #3FAE49); border-radius: 10px; }
@supports not selector(::-webkit-scrollbar) {
    .sidebar { scrollbar-width: thin; scrollbar-color: var(--verde-datasense, #3FAE49) rgba(255, 255, 255, 0.05); }
}
</style>

<script>
// Protección CSRF del panel: todo formulario POST y todo fetch POST al mismo sitio envían el token de la sesión
window.CSRF_TOKEN = <?= jsJson(tokenCsrf()) ?>;

document.addEventListener('submit', function (evento) {
    const formulario = evento.target;
    if ((formulario.getAttribute('method') || '').toLowerCase() !== 'post' || formulario.querySelector('input[name="csrf_token"]')) return;
    const campo = document.createElement('input');
    campo.type = 'hidden';
    campo.name = 'csrf_token';
    campo.value = window.CSRF_TOKEN;
    formulario.appendChild(campo);
}, true);

(function () {
    const fetchOriginal = window.fetch;
    window.fetch = function (recurso, opciones = {}) {
        const url = new URL(recurso instanceof Request ? recurso.url : recurso, window.location.href);
        if (url.origin === window.location.origin && String(opciones.method || 'GET').toUpperCase() === 'POST') {
            const cabeceras = new Headers(opciones.headers || {});
            cabeceras.set('X-CSRF-Token', window.CSRF_TOKEN);
            opciones = Object.assign({}, opciones, { headers: cabeceras });
        }
        return fetchOriginal.call(this, recurso, opciones);
    };
})();

// Menú en celulares y tabletas: botón hamburguesa, fondo oscuro y cierre con Escape, al tocar fuera o al elegir una opción
(function () {
    const sidebar = document.getElementById('sidebar');
    const fondo = document.getElementById('sidebarOverlay');
    const abrirMenu = function (abrir) {
        sidebar.classList.toggle('active', abrir);
        fondo.hidden = !abrir;
        document.body.classList.toggle('menu-abierto', abrir);
        document.querySelectorAll('.btn-menu-movil').forEach(function (b) { b.setAttribute('aria-expanded', abrir ? 'true' : 'false'); });
    };
    // En computador el mismo botón oculta el menú para usar toda la pantalla; la preferencia se recuerda en este navegador
    const ocultarEnEscritorio = function (ocultar, guardar) {
        document.body.classList.toggle('menu-oculto', ocultar);
        document.querySelectorAll('.btn-menu-movil').forEach(function (b) {
            b.setAttribute('title', ocultar ? 'Mostrar el menú' : 'Ocultar el menú');
            if (window.innerWidth > 992) b.setAttribute('aria-expanded', ocultar ? 'false' : 'true');
        });
        if (guardar) {
            try { localStorage.setItem('ds_menu_oculto', ocultar ? '1' : '0'); } catch (err) { /* navegación privada */ }
            // Gráficos y tablas se ajustan al nuevo ancho cuando termina la animación
            setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 350);
        }
    };
    try { if (localStorage.getItem('ds_menu_oculto') === '1') ocultarEnEscritorio(true, false); } catch (err) { /* sin almacenamiento */ }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.btn-menu-movil')) {
            if (window.innerWidth > 992) ocultarEnEscritorio(!document.body.classList.contains('menu-oculto'), true);
            else abrirMenu(!sidebar.classList.contains('active'));
        }
        else if (e.target.closest('#btnCerrarMenu') || e.target === fondo) abrirMenu(false);
        else if (e.target.closest('#sidebar a[href]')) {
            // Cualquier enlace del menú (opciones y logo). Celular: se cierra el menú. Computador: la página elegida abre a pantalla completa
            // con el menú oculto; se vuelve a mostrar con la hamburguesa
            if (window.innerWidth <= 992) abrirMenu(false);
            else if (!e.target.closest('a[href="logout.php"]')) { try { localStorage.setItem('ds_menu_oculto', '1'); } catch (err) { /* sin almacenamiento */ } }
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sidebar.classList.contains('active')) abrirMenu(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 992 && sidebar.classList.contains('active')) abrirMenu(false); });

    document.addEventListener('DOMContentLoaded', function () {
        // Páginas sin el encabezado común: botón flotante
        if (!document.querySelector('.btn-menu-movil')) {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'btn-menu-movil btn-menu-flotante';
            boton.setAttribute('aria-label', 'Abrir el menú');
            boton.setAttribute('aria-controls', 'sidebar');
            boton.innerHTML = '<i class="fas fa-bars"></i>';
            document.body.appendChild(boton);
        }
        // Las tablas anchas se desplazan dentro de su propio contenedor y no ensanchan la página en el celular
        document.querySelectorAll('.main-content table').forEach(function (tabla) {
            if (tabla.closest('.table-responsive, .modal, [data-sin-envoltura]')) return;
            const envoltura = document.createElement('div');
            envoltura.className = 'table-responsive';
            tabla.parentNode.insertBefore(envoltura, tabla);
            envoltura.appendChild(tabla);
        });
    });
})();
</script>
