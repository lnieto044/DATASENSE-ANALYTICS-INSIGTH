/**
 * PANEL VIVO: animaciones de entrada, cifras que se cuentan, filas en cascada, tarjetas que aparecen al bajar y barra de
 * carga al cambiar de módulo. Lo carga includes/sidebar.php en todas las páginas del panel (con css/panel_vivo.css).
 * No cambia ningún dato: al terminar, cada cifra queda exactamente con el texto original.
 */
(function () {
    'use strict';
    const sinMovimiento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------------------------------------------------------------- Barra de carga al cambiar de módulo
    const barra = document.createElement('div');
    barra.id = 'ds-progreso';
    const arrancar = function () { barra.classList.remove('fin'); void barra.offsetWidth; barra.classList.add('activo'); };
    const terminar = function () { if (barra.classList.contains('activo')) { barra.classList.remove('activo'); barra.classList.add('fin'); } };
    document.addEventListener('click', function (e) {
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const href = a.getAttribute('href') || '';
        if (a.target && a.target !== '_self' || a.hasAttribute('download') || href.charAt(0) === '#' || /^(javascript|mailto|tel|data):/i.test(href)) return;
        if (a.hasAttribute('data-bs-toggle') || a.origin !== window.location.origin) return;
        if (/\.(pdf|csv|xlsx?|zip|txt)(\?|$)/i.test(a.pathname) || /descargar|exportar|generar_pdf/i.test(a.pathname)) return;
        arrancar();
    });
    document.addEventListener('submit', function (e) {
        const f = e.target;
        if (!e.defaultPrevented && f.method && f.method.toLowerCase() === 'post' && !f.target) setTimeout(function () { if (!e.defaultPrevented) arrancar(); }, 0);
    });
    window.addEventListener('pageshow', terminar);

    // ---------------------------------------------------------------- Cifras que se cuentan hasta su valor
    // Acepta "$ 48.500.000", "1.248", "72,6 %", "USD 57.354", "3,2". Formato colombiano: punto de miles y coma decimal.
    const PATRON = /^(\s*(?:US\$|USD|COP|\$)?\s*)(-?\d{1,3}(?:\.\d{3})+(?:,\d+)?|-?\d+(?:,\d+)?)(\s*(?:%|M|K|MM|mil|días|d)?\s*)$/;
    const contar = function (el) {
        const original = el.textContent;
        const m = original.match(PATRON);
        if (!m) return;
        const numero = m[2];
        const decimales = (numero.split(',')[1] || '').length;
        const valor = parseFloat(numero.replace(/\./g, '').replace(',', '.'));
        if (!isFinite(valor) || Math.abs(valor) < 2) return;
        const miles = /\.\d{3}/.test(numero);
        const formato = new Intl.NumberFormat('es-CO', { minimumFractionDigits: decimales, maximumFractionDigits: decimales, useGrouping: miles });
        const duracion = Math.min(1400, 700 + Math.log10(Math.abs(valor) + 1) * 120);
        const inicio = performance.now();
        el.dataset.dsContado = '1';
        const paso = function (ahora) {
            const t = Math.min(1, (ahora - inicio) / duracion);
            const suave = 1 - Math.pow(1 - t, 3);
            if (t < 1) {
                el.textContent = m[1] + formato.format(valor * suave) + m[3];
                requestAnimationFrame(paso);
            } else {
                el.textContent = original;   // queda exactamente como lo escribió el servidor
            }
        };
        requestAnimationFrame(paso);
    };

    const iniciar = function () {
        document.body.appendChild(barra);
        const main = document.querySelector('.main-content');
        if (!main || sinMovimiento) return;

        // Bloques del módulo: entran uno tras otro (sin tocar ventanas emergentes ni scripts)
        let i = 0;
        Array.prototype.forEach.call(main.children, function (bloque) {
            if (/^(SCRIPT|STYLE|TEMPLATE|LINK)$/.test(bloque.tagName) || bloque.classList.contains('modal') || bloque.matches('header, .top-header, .topbar')) return;
            if (i > 10) return;
            bloque.style.setProperty('--ds-retraso', (i++ * 70) + 'ms');
            bloque.classList.add('ds-entra');
            bloque.addEventListener('animationend', function fin(ev) {
                if (ev.target !== bloque) return;
                bloque.classList.remove('ds-entra');   // sin transformaciones que afecten ventanas emergentes
                bloque.removeEventListener('animationend', fin);
            });
        });

        // Indicadores (tarjetas de cifras) con impulso, en orden
        const indicadores = main.querySelectorAll('.card-kpi, [class^="kpi-"], [class*=" kpi-"], .kpi, .ds-pendiente, .tarjeta-modulo');
        Array.prototype.forEach.call(indicadores, function (el, n) {
            if (el.closest('.modal')) return;
            el.style.setProperty('--ds-retraso', (120 + Math.min(n, 24) * 45) + 'ms');
            el.classList.add('ds-indicador');
            el.addEventListener('animationend', function () { el.classList.remove('ds-indicador'); }, { once: true });
        });

        // Filas de las tablas en cascada (las primeras de cada tabla)
        main.querySelectorAll('table').forEach(function (tabla) {
            if (tabla.closest('.modal')) return;
            Array.prototype.slice.call(tabla.querySelectorAll('tbody > tr'), 0, 25).forEach(function (fila, n) {
                fila.style.setProperty('--ds-retraso', (200 + n * 22) + 'ms');
                fila.classList.add('ds-fila');
                fila.addEventListener('animationend', function () { fila.classList.remove('ds-fila'); }, { once: true });
            });
        });

        // Cifras: se cuentan cuando se ven
        const candidatas = [];
        main.querySelectorAll('.card-kpi *, [class^="kpi-"] *, [class*=" kpi-"] *, .kpi *, .ds-pendiente b, [data-contar]').forEach(function (el) {
            if (el.children.length === 0 && !el.closest('.modal, table, form') && PATRON.test(el.textContent)) candidatas.push(el);
        });
        if ('IntersectionObserver' in window) {
            const vistas = new IntersectionObserver(function (entradas) {
                entradas.forEach(function (en) {
                    if (en.isIntersecting && !en.target.dataset.dsContado) { contar(en.target); vistas.unobserve(en.target); }
                });
            }, { threshold: 0.4 });
            candidatas.forEach(function (el) { vistas.observe(el); });

            // Tarjetas que están más abajo: aparecen al bajar
            const reveladas = new IntersectionObserver(function (entradas) {
                entradas.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    const el = en.target;
                    el.classList.remove('ds-oculta');
                    el.classList.add('ds-revela');
                    el.addEventListener('animationend', function () { el.classList.remove('ds-revela'); }, { once: true });
                    reveladas.unobserve(el);
                });
            }, { threshold: 0.12 });
            const alto = window.innerHeight;
            main.querySelectorAll('.card, .chart-container, canvas').forEach(function (el) {
                if (el.closest('.modal, .ds-entra .card .card') || el.getBoundingClientRect().top < alto) return;
                el.classList.add('ds-oculta');
                reveladas.observe(el);
            });
        } else {
            candidatas.forEach(contar);
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();

    // ---------------------------------------------------------------- Reloj de la bienvenida del inicio
    const reloj = document.getElementById('dsReloj');
    if (reloj) {
        const pintar = function () {
            reloj.textContent = new Date().toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'America/Bogota' });
        };
        pintar();
        setInterval(pintar, 15000);
    }
})();
