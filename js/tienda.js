// Tienda DataSense: carrito lateral, agregar al carrito, favoritos (en este navegador), avisos y selectores de cantidad
(function () {
    const T = window.TIENDA || {};
    const CLAVE_FAVORITOS = 'ds_favoritos';

    function escHtml(texto) {
        return String(texto ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function aviso(texto, tipo = 'success') {
        const contenedor = document.getElementById('avisosTienda');
        if (!contenedor || !window.bootstrap) return;
        const iconos = { success: 'fa-circle-check', danger: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        const el = document.createElement('div');
        el.className = `toast align-items-center text-bg-${tipo} border-0 shadow`;
        el.setAttribute('role', 'status');
        el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="fas ${iconos[tipo] || iconos.info} me-2"></i>${escHtml(texto)}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button></div>`;
        contenedor.appendChild(el);
        el.addEventListener('hidden.bs.toast', () => el.remove());
        new bootstrap.Toast(el, { delay: 3500 }).show();
    }

    async function llamar(accion, datos = {}) {
        const cuerpo = new FormData();
        cuerpo.append('accion', accion);
        Object.entries(datos).forEach(([k, v]) => cuerpo.append(k, v));
        const r = await fetch(T.api, { method: 'POST', body: cuerpo, headers: { 'X-CSRF-Token': T.csrf, 'Accept': 'application/json' }, credentials: 'same-origin' });
        const d = await r.json().catch(() => ({ ok: false, mensaje: 'No fue posible completar la acción.' }));
        if (d.unidades !== undefined) contador(d.unidades);
        return d;
    }

    async function resumen() {
        const r = await fetch(`${T.api}?accion=resumen`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const d = await r.json();
        contador(d.unidades);
        return d;
    }

    function contador(unidades) {
        const el = document.getElementById('contadorCarrito');
        if (!el) return;
        el.textContent = unidades;
        el.hidden = !unidades;
        el.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.35)' }, { transform: 'scale(1)' }], { duration: 350 });
    }

    function pintarMiniCarrito(r) {
        const cuerpo = document.getElementById('miniCarritoCuerpo');
        if (!cuerpo) return;
        if (!r.lineas || !r.lineas.length) {
            cuerpo.innerHTML = `<div class="text-center my-auto py-5"><i class="fas fa-cart-shopping fa-3x text-secondary opacity-50 mb-3"></i>
                <p class="fw-bold mb-1">Tu carrito está vacío</p><p class="text-muted small">Explora plantillas, cursos y kits para empezar.</p>
                <a href="tienda.php" class="btn btn-carrito">Ir a la tienda</a></div>`;
            return;
        }
        cuerpo.innerHTML = `<div class="flex-grow-1 overflow-auto">${r.lineas.map((l) => `
            <div class="mini-linea" data-linea="${l.id}">
                <a href="${escHtml(l.url)}">${l.media}</a>
                <div><b><a href="${escHtml(l.url)}" class="text-reset">${escHtml(l.nombre)}</a></b>
                    <div class="selector-cantidad mt-1" style="transform:scale(.85);transform-origin:left">
                        <button type="button" data-mini-cantidad="${l.id}" data-valor="${l.cantidad - 1}" aria-label="Quitar una unidad">−</button>
                        <input type="text" value="${l.cantidad}" readonly aria-label="Cantidad">
                        <button type="button" data-mini-cantidad="${l.id}" data-valor="${l.cantidad + 1}" ${l.cantidad >= l.maximo ? 'disabled' : ''} aria-label="Agregar una unidad">+</button>
                    </div></div>
                <div class="text-end"><div class="fw-bold small">${escHtml(l.total)}</div>
                    <button type="button" class="btn btn-link btn-sm text-danger p-0" data-mini-cantidad="${l.id}" data-valor="0" aria-label="Quitar"><i class="far fa-trash-can"></i></button></div>
            </div>`).join('')}</div>
            <div class="border-top pt-3 mt-2">
                ${r.falta_envio_gratis ? `<div class="barra-envio mb-2"><i class="fas fa-truck-fast me-1"></i>Te faltan <b>${escHtml(r.falta_envio_gratis)}</b> para el envío gratis</div>` : ''}
                <div class="d-flex justify-content-between small"><span>Subtotal</span><span>${escHtml(r.subtotal)}</span></div>
                ${r.descuento ? `<div class="d-flex justify-content-between small text-success"><span>Descuento (${escHtml(r.cupon)})</span><span>−${escHtml(r.descuento)}</span></div>` : ''}
                ${r.envio ? `<div class="d-flex justify-content-between small"><span>Envío</span><span>${escHtml(r.envio)}</span></div>` : ''}
                <div class="d-flex justify-content-between fw-bold fs-5 mt-1"><span>Total</span><span>${escHtml(r.total)}</span></div>
                <div class="text-muted small mb-3">IVA incluido: ${escHtml(r.iva)}</div>
                <div class="d-grid gap-2"><a href="checkout.php" class="btn btn-comprar"><i class="fas fa-lock me-2"></i>Finalizar compra</a>
                    <a href="carrito.php" class="btn btn-outline-secondary">Ver carrito completo</a></div>
            </div>`;
    }

    // ---------------------------------------------------------------- favoritos (lista local de ids)
    function favoritos() {
        try { return JSON.parse(localStorage.getItem(CLAVE_FAVORITOS) || '[]').map(Number).filter(Boolean); } catch (e) { return []; }
    }
    function guardarFavoritos(lista) {
        try { localStorage.setItem(CLAVE_FAVORITOS, JSON.stringify(lista)); } catch (e) { /* sin almacenamiento */ }
        marcarFavoritos();
    }
    function marcarFavoritos() {
        const lista = favoritos();
        document.querySelectorAll('[data-favorito]').forEach((b) => {
            const activo = lista.includes(Number(b.dataset.favorito));
            b.classList.toggle('activo', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
            b.title = activo ? 'Quitar de favoritos' : 'Agregar a favoritos';
            const icono = b.querySelector('i');
            if (icono) icono.className = `${activo ? 'fas' : 'far'} fa-heart`;
        });
        const c = document.getElementById('contadorFavoritos');
        if (c) { c.textContent = lista.length; c.hidden = !lista.length; }
    }
    async function vistaFavoritos() {
        const destino = document.getElementById('vistaFavoritos');
        if (!destino) return;
        const lista = favoritos();
        if (!lista.length) {
            destino.innerHTML = '<div class="vacio-tienda"><i class="far fa-heart d-block"></i><p class="fw-bold mb-1">Aún no tienes favoritos</p><p>Toca el corazón de un producto para guardarlo aquí.</p><a href="tienda.php" class="btn btn-carrito">Explorar la tienda</a></div>';
            return;
        }
        const r = await fetch(`${T.api}?accion=tarjetas&ids=${lista.join(',')}`, { credentials: 'same-origin' });
        const d = await r.json().catch(() => ({ html: '' }));
        destino.innerHTML = d.html ? `<div class="rejilla-productos">${d.html}</div>` : '<div class="vacio-tienda">Tus favoritos ya no están disponibles.</div>';
        marcarFavoritos();
    }

    // ---------------------------------------------------------------- eventos
    document.addEventListener('click', async (e) => {
        const paso = e.target.closest('[data-paso]');
        if (paso) {
            const input = document.getElementById(paso.dataset.objetivo);
            if (input) {
                const max = Number(input.max || 99), min = Number(input.min || 1);
                input.value = Math.min(max, Math.max(min, Number(input.value || 1) + Number(paso.dataset.paso)));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }

        const agregar = e.target.closest('[data-agregar]');
        if (agregar) {
            e.preventDefault();
            const campo = agregar.dataset.cantidad ? document.getElementById(agregar.dataset.cantidad) : null;
            const original = agregar.innerHTML;
            agregar.disabled = true;
            agregar.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Agregando...';
            try {
                const d = await llamar('agregar', { producto_id: agregar.dataset.agregar, cantidad: campo ? campo.value : 1 });
                aviso(d.mensaje, d.ok ? 'success' : 'danger');
                if (d.ok && agregar.dataset.ir) { window.location.href = agregar.dataset.ir; return; }
                if (d.ok) agregar.innerHTML = '<i class="fas fa-check me-2"></i>Agregado';
                setTimeout(() => { agregar.innerHTML = original; agregar.disabled = false; }, d.ok ? 1200 : 0);
            } catch (err) {
                agregar.innerHTML = original;
                agregar.disabled = false;
                aviso('No fue posible agregar el producto. Revisa tu conexión.', 'danger');
            }
            return;
        }

        const fav = e.target.closest('[data-favorito]');
        if (fav) {
            const id = Number(fav.dataset.favorito);
            const lista = favoritos();
            const esta = lista.includes(id);
            guardarFavoritos(esta ? lista.filter((x) => x !== id) : [...lista, id]);
            aviso(esta ? 'Quitado de tus favoritos.' : 'Guardado en tus favoritos.', 'info');
            if (esta && document.getElementById('vistaFavoritos')) vistaFavoritos();
            return;
        }

        const mini = e.target.closest('[data-mini-cantidad]');
        if (mini) {
            mini.disabled = true;
            const d = await llamar('actualizar', { producto_id: mini.dataset.miniCantidad, cantidad: mini.dataset.valor });
            if (!d.ok) aviso(d.mensaje, 'danger');
            pintarMiniCarrito(d.resumen || await resumen());
            if (document.body.dataset.pagina === 'carrito') window.location.reload();
        }
    });

    const miniCarrito = document.getElementById('miniCarrito');
    if (miniCarrito) {
        miniCarrito.addEventListener('show.bs.offcanvas', async () => {
            try { pintarMiniCarrito(await resumen()); } catch (err) { aviso('No fue posible cargar el carrito.', 'danger'); }
        });
    }

    // Sombra de la cabecera al desplazarse
    const cabecera = document.getElementById('cabeceraTienda');
    if (cabecera) window.addEventListener('scroll', () => cabecera.classList.toggle('shadow', window.scrollY > 10), { passive: true });

    // Barra de categorías: flechas para avanzar y retroceder, rueda del mouse y la categoría activa centrada a la vista
    const pista = document.getElementById('pistaCategorias');
    if (pista) {
        const anterior = document.querySelector('.categorias-flecha.anterior');
        const siguiente = document.querySelector('.categorias-flecha.siguiente');
        const limite = () => pista.scrollWidth - pista.clientWidth;
        const actualizarFlechas = () => {
            anterior.hidden = pista.scrollLeft <= 2;
            siguiente.hidden = pista.scrollLeft >= limite() - 2;
        };
        document.querySelectorAll('[data-desplazar]').forEach((boton) => boton.addEventListener('click', () => {
            pista.scrollBy({ left: Number(boton.dataset.desplazar) * Math.max(220, pista.clientWidth * 0.7), behavior: 'smooth' });
        }));
        pista.addEventListener('wheel', (e) => {
            const delta = Math.abs(e.deltaY) > Math.abs(e.deltaX) ? e.deltaY : e.deltaX;
            const puede = delta > 0 ? pista.scrollLeft < limite() - 1 : pista.scrollLeft > 1;
            if (delta && puede) { e.preventDefault(); pista.scrollLeft += delta; }
        }, { passive: false });
        pista.addEventListener('scroll', actualizarFlechas, { passive: true });
        window.addEventListener('resize', actualizarFlechas);
        const activo = pista.querySelector('a.activo');
        if (activo) pista.scrollLeft = Math.max(0, activo.offsetLeft - (pista.clientWidth - activo.offsetWidth) / 2);
        actualizarFlechas();
    }

    marcarFavoritos();
    vistaFavoritos();
    window.TiendaUI = { aviso, llamar, resumen, escHtml };
})();
