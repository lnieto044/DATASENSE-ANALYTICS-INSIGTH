/**
 * EDITOR LIBRE DE AFICHES - DATASENSE (admin/editor_afiches.php)
 * Lienzo donde cada elemento se mueve, se gira y se cambia de tamaño con el ratón o con el dedo.
 * Usa Fabric.js para el lienzo y js/afiches.js para convertir las plantillas en elementos sueltos.
 *
 *   - Texto (varias fuentes, color por palabras), formas, iconos, fotos del sitio o subidas, logo y colores de marca.
 *   - Capas, bloquear, agrupar, alinear en la página, guías de centrado, deshacer y rehacer, atajos de teclado.
 *   - Cambio de tamaño de la página, diseños guardados en el equipo (IndexedDB) y descarga en PNG o JPG.
 *
 * Cada elemento puede llevar datos propios (terminan en "Ds") que se guardan con el diseño: ver PROPIAS.
 */
(function () {
    'use strict';

    const DATOS = window.EDITOR_DATOS || { publicaciones: [], logo: '../img/logo.png', pie: '' };
    const A = window.Afiches;
    A.usarLogo(DATOS.logo);

    const $ = function (selector, raiz) { return (raiz || document).querySelector(selector); };
    const $$ = function (selector, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(selector)); };

    // nombreDs: nombre en la lista de capas · tipoDs: icono, boton, etiqueta o insignia · ajustesDs: con qué se armó una pieza
    // cubreDs: elemento que ocupa toda la página ('todo') o la franja de abajo ('abajo') · fondoDs: definición del fondo
    const PROPIAS = ['nombreDs', 'tipoDs', 'ajustesDs', 'bloqueado', 'esFondo', 'fondoDs', 'cubreDs', 'mascaraDs', 'filtrosDs', 'selectable', 'evented'];
    const COLORES = ['#0A2E4F', '#0F4C6E', '#3FAE49', '#86E08F', '#00CCFF', '#FBBF24', '#DC2626', '#FFFFFF', '#E2E8F0', '#94A3B8', '#475569', '#0F172A'];
    const DEGRADADOS = [['#0A2E4F', '#0F4C6E'], ['#0B4A3C', '#23934F'], ['#0B1220', '#1B2B4B'], ['#FFFFFF', '#E6EFF5'],
                        ['#0F4C6E', '#3FAE49'], ['#1E1B4B', '#5B21B6'], ['#7C2D12', '#EA580C'], ['#111827', '#374151']];
    const FUENTES = ['Inter', 'Poppins', 'Montserrat', 'Raleway', 'Space Grotesk', 'Oswald', 'Bebas Neue', 'Playfair Display',
                     'Merriweather', 'Lora', 'Roboto Slab', 'Dancing Script', 'Pacifico'];
    const NOMBRES_PIEZA = { boton: 'Botón', etiqueta: 'Etiqueta', insignia: 'Sello' };
    const BLOQUEO = { bloqueado: true, selectable: false, evented: false };

    Object.assign(fabric.Object.prototype, {
        transparentCorners: false, cornerColor: '#FFFFFF', cornerStrokeColor: '#3FAE49', borderColor: '#3FAE49',
        cornerSize: 13, cornerStyle: 'circle', borderScaleFactor: 1.6, padding: 3, strokeUniform: true,
    });
    fabric.textureSize = 4096;

    // Fabric 5.3 pide la línea base 'alphabetical', que los navegadores rechazan con un aviso en la consola por cada letra.
    // Es la misma función de Fabric con el valor válido, 'alphabetic' (el que el navegador termina usando de todos modos).
    fabric.Text.prototype._setTextStyles = function (ctx, estiloLetra, midiendo) {
        ctx.textBaseline = 'alphabetic';
        if (this.path) {
            if (this.pathAlign === 'center') ctx.textBaseline = 'middle';
            else if (this.pathAlign === 'ascender') ctx.textBaseline = 'top';
            else if (this.pathAlign === 'descender') ctx.textBaseline = 'bottom';
        }
        ctx.font = this._getFontDeclaration(estiloLetra, midiendo);
    };

    const lienzo = new fabric.Canvas('edLienzo', {
        preserveObjectStacking: true, stopContextMenu: true,
        selectionColor: 'rgba(63, 174, 73, .12)', selectionBorderColor: '#3FAE49', selectionLineWidth: 1.5,
    });

    let doc = { id: null, nombre: 'Afiche sin título', ancho: 1080, alto: 1080 };
    let zoom = 1, acercamiento = 1;        // acercamiento: cuánto se amplió sobre el "ajustar a la pantalla"
    let panelActivo = 'plantillas';

    // ---------------------------------------------------------------- Utilidades

    let temporizadorAviso = null;
    function aviso(texto) {
        const caja = $('#edAviso');
        caja.textContent = texto;
        caja.hidden = false;
        clearTimeout(temporizadorAviso);
        temporizadorAviso = setTimeout(function () { caja.hidden = true; }, 2800);
    }

    function escapar(texto) {
        return String(texto).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function unidad() { return Math.min(doc.ancho, doc.alto) / 1080; }

    function esTexto(o) { return !!o && (o.type === 'textbox' || o.type === 'i-text') && o.tipoDs !== 'icono'; }

    function activos() {
        const a = lienzo.getActiveObject();
        if (!a) return [];
        return a.type === 'activeSelection' ? a.getObjects() : [a];
    }

    function cargarFuente(familia, peso) {
        if (!document.fonts || !document.fonts.load) return Promise.resolve();
        return document.fonts.load((peso || '400') + ' 32px "' + familia + '"').catch(function () { /* se usa la fuente del sistema */ });
    }

    // Claro u oscuro, para proponer un color de texto que se lea sobre el fondo
    function esOscuro(color) {
        const m = /^#([0-9a-f]{6})$/i.exec(String(color));
        if (!m) return true;
        const n = parseInt(m[1], 16);
        return (0.299 * (n >> 16 & 255) + 0.587 * (n >> 8 & 255) + 0.114 * (n & 255)) < 150;
    }
    function colorSugerido() {
        const f = fondo(), def = f && f.fondoDs;
        const base = def ? (def.tipo === 'degradado' ? def.colores[0] : def.color) : '#FFFFFF';
        return esOscuro(base) ? '#FFFFFF' : '#0A2E4F';
    }

    function puntosEstrella(radio) {
        const puntos = [];
        for (let i = 0; i < 10; i++) {
            const r = i % 2 === 0 ? radio : radio * 0.45, a = -Math.PI / 2 + i * Math.PI / 5;
            puntos.push({ x: radio + r * Math.cos(a), y: radio + r * Math.sin(a) });
        }
        return puntos;
    }

    // Quita los *asteriscos* de resalte y dice entre qué letras estaban
    function sinMarcas(texto) {
        let limpio = '', dentro = false, inicio = 0;
        const rangos = [];
        String(texto || '').split('').forEach(function (letra) {
            if (letra !== '*') { limpio += letra; return; }
            if (dentro) rangos.push([inicio, limpio.length]); else inicio = limpio.length;
            dentro = !dentro;
        });
        return { texto: limpio, rangos: rangos };
    }

    // ---------------------------------------------------------------- Vista y página

    function ajustarVista() {
        const caja = $('#edEscenario');
        const base = Math.min((caja.clientWidth - 56) / doc.ancho, (caja.clientHeight - 56) / doc.alto);
        zoom = Math.max(0.05, base * acercamiento);
        lienzo.setZoom(zoom);
        lienzo.setDimensions({ width: Math.round(doc.ancho * zoom), height: Math.round(doc.alto * zoom) });
        $('#edZoom').textContent = Math.round(zoom * 100) + '%';
        lienzo.requestRenderAll();
    }

    function fondo() { return lienzo.getObjects().filter(function (o) { return o.esFondo; })[0]; }

    // def: { tipo: 'color', color } o { tipo: 'degradado', colores: [a, b] }
    function ponerFondo(def) {
        let f = fondo();
        if (!f) {
            f = new fabric.Rect({ left: 0, top: 0, esFondo: true, nombreDs: 'Fondo', selectable: false, evented: false, strokeWidth: 0 });
            lienzo.insertAt(f, 0);
        }
        f.set({ width: doc.ancho, height: doc.alto, fondoDs: def });
        f.set('fill', def.tipo === 'degradado'
            ? new fabric.Gradient({ type: 'linear', gradientUnits: 'percentage', coords: { x1: 0, y1: 0, x2: 1, y2: 1 },
                                    colorStops: [{ offset: 0, color: def.colores[0] }, { offset: 1, color: def.colores[1] }] })
            : def.color);
        f.setCoords();
        lienzo.sendToBack(f);
        lienzo.requestRenderAll();
    }

    function centrar(o) {
        o.set({ left: (doc.ancho - o.getScaledWidth()) / 2, top: (doc.alto - o.getScaledHeight()) / 2 });
        o.setCoords();
    }

    function agregar(o) {
        lienzo.add(o);
        lienzo.setActiveObject(o);
        lienzo.requestRenderAll();
        registrar();
    }

    // El texto se agranda cambiando el tamaño de letra, no estirándolo: así el número de "Tamaño" siempre dice la verdad
    function normalizarTexto(o) {
        if (!esTexto(o) || (Math.abs(o.scaleX - 1) < 0.001 && Math.abs(o.scaleY - 1) < 0.001)) return;
        const k = o.scaleX;
        const cambios = { fontSize: Math.max(6, Math.round(o.fontSize * k)), scaleX: 1, scaleY: 1 };
        if (o.type === 'textbox') cambios.width = o.width * k;
        o.set(cambios);
        o.initDimensions();
        o.setCoords();
    }

    function cambiarTamano(ancho, alto) {
        ancho = Math.round(ancho);
        alto = Math.round(alto);
        if (!(ancho >= 200 && ancho <= 4000 && alto >= 200 && alto <= 4000)) { aviso('El tamaño debe estar entre 200 y 4000 píxeles'); return; }
        if (ancho === doc.ancho && alto === doc.alto) return;
        const f = Math.min(ancho / doc.ancho, alto / doc.alto), cx = doc.ancho / 2, cy = doc.alto / 2;
        lienzo.discardActiveObject();
        lienzo.getObjects().forEach(function (o) {
            if (o.esFondo) return;
            if (o.cubreDs === 'todo') {
                // Lo que cubría toda la página la sigue cubriendo
                const k = Math.max(ancho / o.width, alto / o.height);
                if (o.type === 'image') o.set({ scaleX: k, scaleY: k, left: (ancho - o.width * k) / 2, top: (alto - o.height * k) / 2 });
                else o.set({ left: 0, top: 0, width: ancho, height: alto, scaleX: 1, scaleY: 1 });
            } else if (o.cubreDs === 'abajo') {
                const grosor = o.getScaledHeight() * f;
                o.set({ left: 0, top: alto - grosor, width: ancho, height: grosor, scaleX: 1, scaleY: 1 });
            } else {
                const c = o.getCenterPoint();
                o.set({ scaleX: o.scaleX * f, scaleY: o.scaleY * f });
                o.setPositionByOrigin(new fabric.Point(ancho / 2 + (c.x - cx) * f, alto / 2 + (c.y - cy) * f), 'center', 'center');
                normalizarTexto(o);
            }
            o.setCoords();
        });
        const def = (fondo() || {}).fondoDs || { tipo: 'color', color: '#FFFFFF' };
        doc.ancho = ancho;
        doc.alto = alto;
        ponerFondo(def);
        ajustarVista();
        registrar();
        pintarProps();
    }

    // ---------------------------------------------------------------- Historial (deshacer y rehacer)

    let historial = [], posicion = -1, enPausa = false;
    let silencio = false;   // true mientras se reemplaza un elemento por otro: no se repinta el panel de propiedades

    function instantanea() {
        const datos = lienzo.toJSON(PROPIAS);
        datos.docDs = { ancho: doc.ancho, alto: doc.alto };
        return JSON.stringify(datos);
    }

    function botonesHistorial() {
        $('#edDeshacer').disabled = posicion <= 0;
        $('#edRehacer').disabled = posicion >= historial.length - 1;
    }

    function registrar() {
        if (enPausa) return;
        const ahora = instantanea();
        if (historial[posicion] === ahora) return;
        historial = historial.slice(0, posicion + 1);
        historial.push(ahora);
        if (historial.length > 30) historial.shift();
        posicion = historial.length - 1;
        botonesHistorial();
        pintarCapas();
        programarAutoguardado();
    }

    // Fuentes que usa un diseño guardado: hay que tenerlas cargadas antes de pintarlo
    function fuentesDe(objetos, lista) {
        (objetos || []).forEach(function (o) {
            if (o.fontFamily) lista.push(cargarFuente(o.fontFamily, o.fontWeight));
            if (o.objects) fuentesDe(o.objects, lista);
        });
        return lista;
    }

    function cargarInstantanea(texto) {
        enPausa = true;
        const datos = JSON.parse(texto);
        return Promise.all(fuentesDe(datos.objects, [])).then(function () {
            return new Promise(function (listo) {
                lienzo.loadFromJSON(datos, function () {
                    if (datos.docDs) { doc.ancho = datos.docDs.ancho; doc.alto = datos.docDs.alto; }
                    enPausa = false;
                    ajustarVista();
                    pintarCapas();
                    pintarProps();
                    listo();
                });
            });
        });
    }

    function deshacer() {
        if (enPausa || posicion <= 0) return;
        posicion--;
        cargarInstantanea(historial[posicion]).then(function () { botonesHistorial(); programarAutoguardado(); });
    }
    function rehacer() {
        if (enPausa || posicion >= historial.length - 1) return;
        posicion++;
        cargarInstantanea(historial[posicion]).then(function () { botonesHistorial(); programarAutoguardado(); });
    }
    function reiniciarHistorial() {
        historial = [];
        posicion = -1;
        registrar();
    }

    // ---------------------------------------------------------------- Diseños guardados (en el equipo de la persona)

    const BD = (function () {
        let conexion = null;
        function abrir() {
            if (!conexion) {
                conexion = new Promise(function (listo, fallo) {
                    const pedido = indexedDB.open('ds_editor_afiches', 1);
                    pedido.onupgradeneeded = function () { pedido.result.createObjectStore('disenos', { keyPath: 'id' }); };
                    pedido.onsuccess = function () { listo(pedido.result); };
                    pedido.onerror = function () { fallo(pedido.error); };
                });
            }
            return conexion;
        }
        function operar(modo, accion) {
            return abrir().then(function (bd) {
                return new Promise(function (listo, fallo) {
                    const tx = bd.transaction('disenos', modo);
                    const pedido = accion(tx.objectStore('disenos'));
                    tx.oncomplete = function () { listo(pedido ? pedido.result : undefined); };
                    tx.onerror = tx.onabort = function () { fallo(tx.error); };
                });
            });
        }
        return {
            poner: function (valor) { return operar('readwrite', function (s) { return s.put(valor); }); },
            leer: function (id) { return operar('readonly', function (s) { return s.get(id); }); },
            todos: function () { return operar('readonly', function (s) { return s.getAll(); }); },
            borrar: function (id) { return operar('readwrite', function (s) { return s.delete(id); }); },
        };
    })();

    // Imagen final del afiche. Se pinta a tamaño real (sin el acercamiento de la pantalla) y sin los controles de selección.
    function imagenFinal(formato, anchoSalida) {
        const z = zoom;
        lienzo.setZoom(1);
        lienzo.setDimensions({ width: doc.ancho, height: doc.alto });
        const url = lienzo.toDataURL({ format: formato === 'jpg' ? 'jpeg' : 'png', quality: 0.92, multiplier: anchoSalida ? anchoSalida / doc.ancho : 1 });
        lienzo.setZoom(z);
        lienzo.setDimensions({ width: Math.round(doc.ancho * z), height: Math.round(doc.alto * z) });
        lienzo.renderAll();
        return url;
    }

    let temporizadorAuto = null;
    function programarAutoguardado() {
        clearTimeout(temporizadorAuto);
        $('#edEstado').textContent = '';
        temporizadorAuto = setTimeout(autoguardar, 900);
    }
    // Copia de trabajo: si la persona cierra o recarga, al volver encuentra el afiche como lo dejó
    function autoguardar() {
        clearTimeout(temporizadorAuto);
        if (enPausa) return;
        BD.poner({ id: 'actual', docId: doc.id, nombre: doc.nombre, fecha: Date.now(), json: instantanea() })
            .then(function () { $('#edEstado').textContent = 'Cambios guardados'; })
            .catch(function () { /* sin almacenamiento (navegación privada): se sigue trabajando */ });
    }

    function guardar() {
        const ahora = Date.now();
        if (!doc.id) doc.id = 'd' + ahora;
        doc.nombre = $('#edNombre').value.trim() || 'Afiche sin título';
        BD.poner({ id: doc.id, nombre: doc.nombre, ancho: doc.ancho, alto: doc.alto, fecha: ahora, miniatura: imagenFinal('jpg', 280), json: instantanea() })
            .then(function () { aviso('Diseño guardado en "Mis diseños"'); autoguardar(); pintarDisenos(); })
            .catch(function () { aviso('No se pudo guardar en este navegador. Descarga la imagen para no perderla.'); });
    }

    function pintarDisenos() {
        BD.todos().then(function (todos) {
            const lista = (todos || []).filter(function (d) { return d.id !== 'actual'; }).sort(function (a, b) { return b.fecha - a.fecha; });
            $('#edDisenosVacio').hidden = lista.length > 0;
            $('#edDisenos').innerHTML = lista.map(function (d) {
                return '<div class="ed-diseno" data-id="' + escapar(d.id) + '">'
                     + '<button type="button" class="ed-diseno-abrir" title="Abrir"><img src="' + d.miniatura + '" alt=""><span>' + escapar(d.nombre) + '</span>'
                     + '<small>' + new Date(d.fecha).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) + ' · ' + d.ancho + ' × ' + d.alto + '</small></button>'
                     + '<button type="button" class="ed-diseno-borrar" title="Eliminar" aria-label="Eliminar ' + escapar(d.nombre) + '"><i class="far fa-trash-can" aria-hidden="true"></i></button></div>';
            }).join('');
        }).catch(function () { $('#edDisenosVacio').textContent = 'Este navegador no deja guardar diseños. Descarga la imagen al terminar.'; });
    }

    function hayCambios() { return historial.length > 1; }
    function confirmarReemplazo() {
        return !hayCambios() || window.confirm('Se va a reemplazar el afiche que tienes abierto. Si no lo has guardado, se pierde. ¿Continuar?');
    }

    $('#edDisenos').addEventListener('click', function (e) {
        const tarjeta = e.target.closest('.ed-diseno');
        if (!tarjeta) return;
        const id = tarjeta.getAttribute('data-id');
        if (e.target.closest('.ed-diseno-borrar')) {
            if (!window.confirm('¿Eliminar este diseño guardado?')) return;
            BD.borrar(id).then(function () { if (doc.id === id) doc.id = null; pintarDisenos(); aviso('Diseño eliminado'); });
            return;
        }
        if (!confirmarReemplazo()) return;
        BD.leer(id).then(function (d) {
            if (!d) return;
            doc.id = d.id;
            doc.nombre = d.nombre;
            $('#edNombre').value = d.nombre;
            return cargarInstantanea(d.json).then(reiniciarHistorial);
        });
    });

    // ---------------------------------------------------------------- Piezas de texto con fondo (botón, etiqueta y sello)

    // aj: { tipo, texto, fondo, color, px, flecha }. Se arman siempre igual, así cambiar el texto reacomoda el fondo.
    function crearPieza(aj) {
        const px = aj.px;
        let grupo;
        if (aj.tipo === 'insignia') {
            const r = px * 1.75;
            const t = new fabric.Text(String(aj.texto), { fontFamily: 'Inter', fontWeight: '800', fontSize: px, fill: aj.color });
            if (t.width > r * 1.6) t.scale(r * 1.6 / t.width);
            t.set({ left: r - t.getScaledWidth() / 2, top: r - t.getScaledHeight() / 2 });
            grupo = new fabric.Group([new fabric.Circle({ radius: r, left: 0, top: 0, fill: aj.fondo, strokeWidth: 0 }), t]);
        } else if (aj.tipo === 'etiqueta') {
            const t = new fabric.Text(String(aj.texto).toUpperCase(), { fontFamily: 'Inter', fontWeight: '700', fontSize: px, charSpacing: 90, fill: aj.color });
            const alto = px * 2.17, ancho = t.width + px * 2.6;
            t.set({ left: px * 1.83, top: (alto - t.height) / 2 });
            grupo = new fabric.Group([
                new fabric.Rect({ left: 0, top: 0, width: ancho, height: alto, rx: alto / 2, ry: alto / 2, fill: aj.fondo, strokeWidth: 0 }),
                new fabric.Circle({ radius: px * 0.26, left: px * 0.78, top: alto / 2 - px * 0.26, fill: aj.color, strokeWidth: 0 }),
                t,
            ]);
        } else {
            const t = new fabric.Text(String(aj.texto) + (aj.flecha ? '  →' : ''), { fontFamily: 'Inter', fontWeight: '700', fontSize: px, fill: aj.color });
            const alto = px * 2.65, ancho = t.width + px * 2.7;
            t.set({ left: (ancho - t.width) / 2, top: (alto - t.height) / 2 });
            grupo = new fabric.Group([
                new fabric.Rect({ left: 0, top: 0, width: ancho, height: alto, rx: alto / 2, ry: alto / 2, fill: aj.fondo, strokeWidth: 0,
                                  shadow: new fabric.Shadow({ color: 'rgba(0,0,0,.22)', blur: px * 0.7, offsetX: 0, offsetY: px * 0.26 }) }),
                t,
            ]);
        }
        grupo.set({ tipoDs: aj.tipo, ajustesDs: aj, nombreDs: NOMBRES_PIEZA[aj.tipo] + ': ' + aj.texto });
        return grupo;
    }

    function rehacerPieza(vieja, cambios) {
        const nueva = crearPieza(Object.assign({}, vieja.ajustesDs, cambios));
        nueva.set({ left: vieja.left, top: vieja.top, scaleX: vieja.scaleX, scaleY: vieja.scaleY, angle: vieja.angle, opacity: vieja.opacity });
        const indice = lienzo.getObjects().indexOf(vieja);
        // Mientras se cambia una por otra no se repinta el panel: se ocultaría el campo donde la persona está escribiendo
        silencio = true;
        lienzo.remove(vieja);
        lienzo.insertAt(nueva, indice);
        lienzo.setActiveObject(nueva);
        silencio = false;
        lienzo.requestRenderAll();
        return nueva;
    }

    // ---------------------------------------------------------------- Agregar elementos

    function nuevoTexto(tipo) {
        const u = unidad();
        const def = { titulo: ['Escribe tu título', 96, '800'], subtitulo: ['Escribe un subtítulo', 56, '600'],
                      parrafo: ['Escribe aquí tu mensaje. Haz doble clic para cambiar el texto.', 36, '400'] }[tipo];
        const t = new fabric.Textbox(def[0], { width: doc.ancho * 0.72, fontFamily: 'Inter', fontWeight: def[2], fontSize: Math.round(def[1] * u),
                                               lineHeight: tipo === 'parrafo' ? 1.25 : 1.02, fill: colorSugerido() });
        centrar(t);
        agregar(t);
    }

    function nuevaPieza(tipo) {
        const u = unidad();
        const pieza = crearPieza({
            boton: { tipo: 'boton', texto: 'Solicita tu asesoría', flecha: true, fondo: '#3FAE49', color: '#FFFFFF', px: 34 * u },
            etiqueta: { tipo: 'etiqueta', texto: 'Nuevo servicio', fondo: esOscuro(colorSugerido()) ? 'rgba(10,46,79,.08)' : 'rgba(255,255,255,.14)', color: esOscuro(colorSugerido()) ? '#2B8A38' : '#86E08F', px: 25 * u },
            insignia: { tipo: 'insignia', texto: '-20%', fondo: '#DC2626', color: '#FFFFFF', px: 46 * u },
        }[tipo]);
        centrar(pieza);
        agregar(pieza);
    }

    function nuevaForma(tipo) {
        const u = unidad(), verde = '#3FAE49';
        let o;
        if (tipo === 'rectangulo') o = new fabric.Rect({ width: 380 * u, height: 250 * u, fill: verde, strokeWidth: 0 });
        else if (tipo === 'redondeado') o = new fabric.Rect({ width: 380 * u, height: 250 * u, rx: 36 * u, ry: 36 * u, fill: verde, strokeWidth: 0 });
        else if (tipo === 'circulo') o = new fabric.Circle({ radius: 150 * u, fill: verde, strokeWidth: 0 });
        else if (tipo === 'triangulo') o = new fabric.Triangle({ width: 300 * u, height: 260 * u, fill: verde, strokeWidth: 0 });
        else if (tipo === 'estrella') o = new fabric.Polygon(puntosEstrella(150 * u), { fill: '#FBBF24', strokeWidth: 0 });
        else if (tipo === 'anillo') o = new fabric.Circle({ radius: 150 * u, fill: 'transparent', stroke: verde, strokeWidth: 18 * u });
        else if (tipo === 'linea') o = new fabric.Line([0, 0, 440 * u, 0], { stroke: verde, strokeWidth: 10 * u, strokeLineCap: 'round' });
        else o = new fabric.Path('M 0 30 L 200 30 L 200 0 L 290 50 L 200 100 L 200 70 L 0 70 Z', { fill: verde, strokeWidth: 0, scaleX: 1.3 * u, scaleY: 1.3 * u });
        o.set({ nombreDs: { rectangulo: 'Rectángulo', redondeado: 'Rectángulo redondeado', circulo: 'Círculo', triangulo: 'Triángulo',
                            estrella: 'Estrella', anillo: 'Anillo', linea: 'Línea', flecha: 'Flecha' }[tipo] });
        centrar(o);
        agregar(o);
    }

    // El dibujo de cada icono lo entrega la hoja de estilos de Font Awesome: se lee de un <i> de prueba
    function nuevoIcono(clase) {
        const muestra = document.createElement('i');
        muestra.className = clase;
        muestra.style.cssText = 'position:absolute;visibility:hidden';
        document.body.appendChild(muestra);
        const glifo = getComputedStyle(muestra, '::before').content.replace(/["']/g, '');
        muestra.remove();
        if (!glifo || glifo === 'none') { aviso('No se pudo cargar el icono'); return; }
        const deMarca = clase.indexOf('fa-brands') !== -1;
        const familia = deMarca ? 'Font Awesome 6 Brands' : 'Font Awesome 6 Free', peso = deMarca ? '400' : '900';
        cargarFuente(familia, peso).then(function () {
            const icono = new fabric.Text(glifo, { fontFamily: familia, fontWeight: peso, fontSize: 200 * unidad(), fill: colorSugerido() === '#FFFFFF' ? '#86E08F' : '#3FAE49',
                                                   tipoDs: 'icono', nombreDs: 'Icono' });
            centrar(icono);
            agregar(icono);
        });
    }

    function nuevaImagen(url, opciones) {
        opciones = opciones || {};
        fabric.Image.fromURL(url, function (img) {
            if (!img || !img.width) { aviso('No se pudo cargar la foto'); return; }
            // Las fotos de la tienda traen su rótulo y su logo abajo: esa franja no entra
            if (opciones.recorte) img.set({ height: Math.round(img.height * (1 - opciones.recorte)) });
            const k = Math.min(doc.ancho * 0.62 / img.width, doc.alto * 0.62 / img.height);
            img.set({ scaleX: k, scaleY: k, nombreDs: opciones.nombre || 'Foto' });
            if (opciones.logo === 'sello') {
                // Logo sobre una placa blanca, para fondos oscuros
                const ancho = doc.ancho * 0.26, kl = ancho / img.width, margen = ancho * 0.12;
                img.set({ scaleX: kl, scaleY: kl, left: margen, top: margen * 0.62 });
                const placa = new fabric.Rect({ left: 0, top: 0, width: ancho + margen * 2, height: img.height * kl + margen * 1.24, rx: ancho * 0.09, ry: ancho * 0.09, fill: '#FFFFFF', strokeWidth: 0,
                                                shadow: new fabric.Shadow({ color: 'rgba(0,0,0,.18)', blur: 18, offsetX: 0, offsetY: 6 }) });
                img = new fabric.Group([placa, img], { nombreDs: 'Logo' });
            } else if (opciones.logo) {
                const kl = doc.ancho * 0.3 / img.width;
                img.set({ scaleX: kl, scaleY: kl, nombreDs: 'Logo' });
            }
            centrar(img);
            agregar(img);
        }, { crossOrigin: 'anonymous' });
    }

    // La foto subida se reduce (2000 px bastan para redes) y se guarda dentro del diseño: no sale del equipo
    function leerFoto(archivo) {
        return new Promise(function (listo, fallo) {
            const lector = new FileReader();
            lector.onerror = fallo;
            lector.onload = function () {
                const img = new Image();
                img.onerror = fallo;
                img.onload = function () {
                    const png = archivo.type === 'image/png';
                    const k = Math.min(1, (png ? 1400 : 2000) / Math.max(img.naturalWidth, img.naturalHeight));
                    const c = document.createElement('canvas');
                    c.width = Math.round(img.naturalWidth * k);
                    c.height = Math.round(img.naturalHeight * k);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    listo(c.toDataURL(png ? 'image/png' : 'image/jpeg', 0.9));
                };
                img.src = lector.result;
            };
            lector.readAsDataURL(archivo);
        });
    }

    // ---------------------------------------------------------------- Fotos: forma, filtros y fondo

    function ponerMascara(img, tipo) {
        if (tipo === 'circulo') {
            img.set({ clipPath: new fabric.Circle({ radius: Math.min(img.width, img.height) / 2, originX: 'center', originY: 'center', left: 0, top: 0 }) });
        } else if (tipo === 'redonda') {
            const r = Math.min(img.width, img.height) * 0.07;
            img.set({ clipPath: new fabric.Rect({ width: img.width, height: img.height, rx: r, ry: r, originX: 'center', originY: 'center', left: 0, top: 0 }) });
        } else {
            img.set({ clipPath: null });
        }
        img.set({ mascaraDs: tipo, dirty: true });
    }

    // f: { byn, brillo, contraste, desenfoque } con los valores de los controles
    function ponerFiltros(img, f) {
        const F = fabric.Image.filters, lista = [];
        if (f.byn) lista.push(new F.Grayscale());
        if (f.brillo) lista.push(new F.Brightness({ brightness: f.brillo / 100 }));
        if (f.contraste) lista.push(new F.Contrast({ contrast: f.contraste / 100 }));
        if (f.desenfoque) lista.push(new F.Blur({ blur: f.desenfoque / 250 }));
        img.filters = lista;
        img.filtrosDs = f;
        img.applyFilters();
    }

    function comoFondo(img) {
        const k = Math.max(doc.ancho / img.width, doc.alto / img.height);
        img.set({ clipPath: null, mascaraDs: 'ninguna', angle: 0, scaleX: k, scaleY: k, shadow: null, cubreDs: 'todo',
                  left: (doc.ancho - img.width * k) / 2, top: (doc.alto - img.height * k) / 2, nombreDs: 'Foto de fondo' });
        img.setCoords();
        lienzo.sendToBack(img);
        const f = fondo();
        if (f) lienzo.sendToBack(f);
        lienzo.requestRenderAll();
        registrar();
        aviso('La foto quedó de fondo. Si el texto no se lee, bájale el brillo.');
    }

    // ---------------------------------------------------------------- Acciones sobre lo seleccionado

    function conActivos(accion) {
        const lista = activos();
        if (!lista.length) return;
        lista.forEach(function (o) { accion(o); o.setCoords(); });
        lienzo.requestRenderAll();
    }

    function rellenar(o, color) {
        if (o.type === 'image' || o.esFondo) return;
        if (o.type === 'group') { o.getObjects().forEach(function (hijo) { rellenar(hijo, color); }); o.set('dirty', true); return; }
        const sinRelleno = !o.fill || o.fill === 'transparent';
        if (o.type === 'line' || o.type === 'polyline' || (sinRelleno && o.stroke)) o.set('stroke', color);
        else o.set('fill', color);
    }

    function aplicarColor(destino, color) {
        const a = lienzo.getActiveObject();
        if (destino === 'fondo' || (destino === 'marca' && !a)) { ponerFondo({ tipo: 'color', color: color }); return; }
        if (!a) return;
        if (destino === 'piezaFondo' || destino === 'piezaTexto') {
            if (a.ajustesDs) rehacerPieza(a, destino === 'piezaFondo' ? { fondo: color } : { color: color });
            return;
        }
        if (destino === 'borde') { conActivos(function (o) { if (o.type !== 'image' && o.type !== 'group') o.set({ stroke: color, strokeWidth: o.strokeWidth || Math.round(6 * unidad()) }); }); pintarProps(); return; }
        // Texto en edición con palabras seleccionadas: solo esas cambian de color
        if (esTexto(a) && a.isEditing && a.selectionStart !== a.selectionEnd) { a.setSelectionStyles({ fill: color }); a.set('dirty', true); lienzo.requestRenderAll(); return; }
        if (a.ajustesDs && a.type === 'group') { rehacerPieza(a, { fondo: color }); return; }
        conActivos(function (o) { rellenar(o, color); });
    }

    function moverCapa(accion) {
        const lista = activos();
        if (!lista.length) return;
        if (lista.length > 1) lienzo.discardActiveObject();
        lista.forEach(function (o) {
            if (accion === 'frente') lienzo.bringToFront(o);
            else if (accion === 'adelante') lienzo.bringForward(o);
            else if (accion === 'atras') lienzo.sendBackwards(o);
            else lienzo.sendToBack(o);
        });
        const f = fondo();
        if (f) lienzo.sendToBack(f);
        lienzo.requestRenderAll();
        registrar();
    }

    function alinear(donde) {
        const a = lienzo.getActiveObject();
        if (!a) return;
        const r = a.getBoundingRect(true, true);
        let dx = 0, dy = 0;
        if (donde === 'izquierda') dx = -r.left;
        else if (donde === 'centro') dx = (doc.ancho - r.width) / 2 - r.left;
        else if (donde === 'derecha') dx = doc.ancho - r.width - r.left;
        else if (donde === 'arriba') dy = -r.top;
        else if (donde === 'medio') dy = (doc.alto - r.height) / 2 - r.top;
        else dy = doc.alto - r.height - r.top;
        a.set({ left: a.left + dx, top: a.top + dy });
        a.setCoords();
        lienzo.requestRenderAll();
        registrar();
    }

    function ponerCopia(copia) {
        lienzo.discardActiveObject();
        copia.set({ left: copia.left + 30, top: copia.top + 30, evented: true, selectable: true, bloqueado: false });
        if (copia.type === 'activeSelection') {
            copia.canvas = lienzo;
            copia.forEachObject(function (o) { lienzo.add(o); });
            copia.setCoords();
        } else {
            lienzo.add(copia);
        }
        lienzo.setActiveObject(copia);
        lienzo.requestRenderAll();
        registrar();
    }
    function duplicar() {
        const a = lienzo.getActiveObject();
        if (a) a.clone(ponerCopia, PROPIAS);
    }
    let portapapeles = null;
    function copiar() {
        const a = lienzo.getActiveObject();
        if (a) a.clone(function (copia) { portapapeles = copia; }, PROPIAS);
    }
    function pegar() {
        if (!portapapeles) return;
        portapapeles.clone(function (copia) {
            portapapeles.set({ left: portapapeles.left + 30, top: portapapeles.top + 30 });   // cada pegado cae un poco más allá
            ponerCopia(copia);
        }, PROPIAS);
    }

    function eliminar() {
        const lista = activos();
        if (!lista.length) return;
        lienzo.discardActiveObject();
        lista.forEach(function (o) { lienzo.remove(o); });
        lienzo.requestRenderAll();
        registrar();
        pintarProps();
    }

    function bloquear(o, si) {
        o.set({ bloqueado: si, selectable: !si, evented: !si });
    }

    // ---------------------------------------------------------------- Panel de propiedades

    function tipoDe(o) {
        if (!o) return 'pagina';
        if (o.type === 'activeSelection') return 'varios';
        if (o.tipoDs === 'icono') return 'icono';
        if (o.ajustesDs && o.type === 'group') return 'pieza';
        if (esTexto(o)) return 'texto';
        if (o.type === 'image') return 'imagen';
        if (o.type === 'group') return 'grupo';
        return 'forma';
    }
    const SECCIONES = { pagina: ['pagina'], varios: ['objeto'], icono: ['icono', 'objeto'], pieza: ['pieza', 'objeto'],
                        texto: ['texto', 'objeto'], imagen: ['imagen', 'objeto'], grupo: ['objeto'], forma: ['forma', 'objeto'] };

    function marcar(selector, atributo, valor) {
        $$(selector).forEach(function (b) { b.classList.toggle('activo', b.getAttribute(atributo) === valor); });
    }
    function poner(id, valor) {
        const campo = $('#' + id);
        if (String(campo.value) !== String(valor)) campo.value = valor;
    }

    function pintarProps() {
        const o = lienzo.getActiveObject(), tipo = tipoDe(o);
        $$('#edProps > section').forEach(function (seccion) { seccion.hidden = SECCIONES[tipo].indexOf(seccion.getAttribute('data-para')) === -1; });
        $('#edProps').classList.toggle('con-seleccion', tipo !== 'pagina');
        if (panelActivo === 'capas') pintarCapas();

        if (tipo === 'pagina') {
            const clave = Object.keys(A.FORMATOS).filter(function (k) { return A.FORMATOS[k].ancho === doc.ancho && A.FORMATOS[k].alto === doc.alto; })[0] || 'propio';
            poner('prFormato', clave);
            $('#prMedidas').hidden = clave !== 'propio';
            poner('prAncho', doc.ancho);
            poner('prAlto', doc.alto);
            return;
        }
        poner('prOpacidad', Math.round((o.opacity == null ? 1 : o.opacity) * 100));
        $('#prSombra').checked = !!o.shadow;
        $('#prAgrupar').hidden = tipo !== 'varios';
        $('#prDesagrupar').hidden = tipo !== 'grupo';
        $('#prBloquear').innerHTML = o.bloqueado ? '<i class="fas fa-lock-open" aria-hidden="true"></i> Desbloquear' : '<i class="fas fa-lock" aria-hidden="true"></i> Bloquear';

        if (tipo === 'texto') {
            if (!$('#prFuente option[value="' + o.fontFamily + '"]')) $('#prFuente').appendChild(new Option(o.fontFamily, o.fontFamily));
            poner('prFuente', o.fontFamily);
            poner('prTamano', Math.round(o.fontSize * (o.scaleX || 1)));
            poner('prPeso', ['400', '600', '700', '800'].indexOf(String(o.fontWeight)) !== -1 ? String(o.fontWeight) : (parseInt(o.fontWeight, 10) >= 700 || o.fontWeight === 'bold' ? '700' : '400'));
            $('[data-estilo="cursiva"]').classList.toggle('activo', o.fontStyle === 'italic');
            $('[data-estilo="subrayado"]').classList.toggle('activo', !!o.underline);
            marcar('[data-alinear-texto]', 'data-alinear-texto', o.textAlign);
            poner('prInterlineado', o.lineHeight);
            poner('prEspaciado', o.charSpacing || 0);
        } else if (tipo === 'forma') {
            poner('prBorde', o.stroke ? (o.strokeWidth || 0) : 0);
            $('#prRadioCaja').hidden = o.type !== 'rect';
            poner('prRadio', Math.round(o.rx || 0));
        } else if (tipo === 'imagen') {
            const f = o.filtrosDs || {};
            $('#prByN').checked = !!f.byn;
            poner('prBrillo', f.brillo || 0);
            poner('prContraste', f.contraste || 0);
            poner('prDesenfoque', f.desenfoque || 0);
            marcar('[data-mascara]', 'data-mascara', o.mascaraDs || 'ninguna');
        } else if (tipo === 'pieza') {
            poner('prTextoPieza', o.ajustesDs.texto);
        }
    }

    // Paletas: los colores de la marca y un selector libre
    $$('.ed-paleta').forEach(function (caja) {
        const destino = caja.getAttribute('data-paleta');
        caja.innerHTML = COLORES.map(function (c) {
            return '<button type="button" class="ed-color" data-color="' + c + '" style="--c: ' + c + '" title="' + c + '" aria-label="Color ' + c + '"></button>';
        }).join('') + '<label class="ed-color ed-color-libre" title="Otro color"><i class="fas fa-eye-dropper" aria-hidden="true"></i>'
            + '<input type="color" value="#3FAE49" aria-label="Elegir otro color"></label>';
        // Sin esto, tocar un color sacaría el cursor del texto que se está escribiendo y se perdería la selección de palabras
        caja.addEventListener('mousedown', function (e) { if (e.target.closest('[data-color]')) e.preventDefault(); });
        caja.addEventListener('click', function (e) {
            const boton = e.target.closest('[data-color]');
            if (!boton) return;
            aplicarColor(destino, boton.getAttribute('data-color'));
            registrar();
        });
        const libre = $('input', caja);
        libre.addEventListener('input', function () { aplicarColor(destino, this.value); });
        libre.addEventListener('change', registrar);
    });

    function pintarDegradados(caja) {
        caja.innerHTML = DEGRADADOS.map(function (d, i) {
            return '<button type="button" class="ed-degradado" data-degradado="' + i + '" style="background: linear-gradient(135deg, ' + d[0] + ', ' + d[1] + ')" aria-label="Fondo degradado ' + (i + 1) + '"></button>';
        }).join('');
        caja.addEventListener('click', function (e) {
            const boton = e.target.closest('[data-degradado]');
            if (!boton) return;
            ponerFondo({ tipo: 'degradado', colores: DEGRADADOS[+boton.getAttribute('data-degradado')].slice() });
            registrar();
        });
    }
    pintarDegradados($('#prDegradados'));
    pintarDegradados($('#edDegradadosMarca'));

    // Página
    $('#prFormato').innerHTML = Object.keys(A.FORMATOS).map(function (clave) {
        const f = A.FORMATOS[clave];
        return '<option value="' + clave + '">' + f.nombre + ' · ' + f.ancho + ' × ' + f.alto + '</option>';
    }).join('') + '<option value="propio">Otro tamaño…</option>';
    $('#prFormato').addEventListener('change', function () {
        if (this.value === 'propio') { $('#prMedidas').hidden = false; $('#prAncho').focus(); return; }
        cambiarTamano(A.FORMATOS[this.value].ancho, A.FORMATOS[this.value].alto);
    });
    $('#prAplicarTamano').addEventListener('click', function () { cambiarTamano(+$('#prAncho').value, +$('#prAlto').value); });

    // Texto
    $('#prFuente').innerHTML = FUENTES.map(function (f) { return '<option value="' + f + '" style="font-family: \'' + f + '\'">' + f + '</option>'; }).join('');
    function conTexto(accion) {
        conActivos(function (o) { if (esTexto(o)) { accion(o); o.initDimensions(); } });
    }
    $('#prFuente').addEventListener('change', function () {
        const familia = this.value;
        const pesos = activos().filter(esTexto).map(function (o) { return cargarFuente(familia, o.fontWeight); });
        Promise.all(pesos).then(function () {
            fabric.util.clearFabricFontCache(familia);
            conTexto(function (o) { o.set('fontFamily', familia); });
            registrar();
        });
    });
    $('#prTamano').addEventListener('input', function () {
        const px = Math.min(600, Math.max(8, +this.value || 0));
        if (px) conTexto(function (o) { o.set({ fontSize: px, scaleX: 1, scaleY: 1 }); });
    });
    $('#prTamano').addEventListener('change', registrar);
    $('#prPeso').addEventListener('change', function () {
        const peso = this.value;
        Promise.all(activos().filter(esTexto).map(function (o) { return cargarFuente(o.fontFamily, peso); })).then(function () {
            conTexto(function (o) { o.set('fontWeight', peso); });
            registrar();
        });
    });
    $$('[data-estilo]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const estilo = boton.getAttribute('data-estilo');
            conTexto(function (o) {
                if (estilo === 'cursiva') o.set('fontStyle', o.fontStyle === 'italic' ? 'normal' : 'italic');
                else if (estilo === 'subrayado') o.set('underline', !o.underline);
                else o.set('text', o.text === o.text.toUpperCase() ? o.text.toLowerCase().replace(/(^|[.!?¿¡]\s*)([a-záéíóúñ])/g, function (m, a, b) { return a + b.toUpperCase(); }) : o.text.toUpperCase());
            });
            registrar();
            pintarProps();
        });
    });
    $$('[data-alinear-texto]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            conTexto(function (o) { o.set('textAlign', boton.getAttribute('data-alinear-texto')); });
            registrar();
            pintarProps();
        });
    });
    $('#prInterlineado').addEventListener('input', function () { const v = +this.value; conTexto(function (o) { o.set('lineHeight', v); }); });
    $('#prEspaciado').addEventListener('input', function () { const v = +this.value; conTexto(function (o) { o.set('charSpacing', v); }); });

    // Pieza de texto con fondo
    $('#prTextoPieza').addEventListener('input', function () {
        const a = lienzo.getActiveObject();
        if (a && a.ajustesDs) rehacerPieza(a, { texto: this.value || ' ' });
    });

    // Forma
    $('#prBorde').addEventListener('input', function () {
        const v = +this.value;
        conActivos(function (o) { if (o.type !== 'image' && o.type !== 'group') o.set({ strokeWidth: v, stroke: v ? (o.stroke || '#0A2E4F') : o.stroke }); });
    });
    $('#prRadio').addEventListener('input', function () {
        const v = +this.value;
        conActivos(function (o) { if (o.type === 'rect') o.set({ rx: v, ry: v }); });
    });

    // Foto
    $$('[data-mascara]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            conActivos(function (o) { if (o.type === 'image') ponerMascara(o, boton.getAttribute('data-mascara')); });
            registrar();
            pintarProps();
        });
    });
    function filtrosDelPanel() {
        return { byn: $('#prByN').checked, brillo: +$('#prBrillo').value, contraste: +$('#prContraste').value, desenfoque: +$('#prDesenfoque').value };
    }
    ['prByN', 'prBrillo', 'prContraste', 'prDesenfoque'].forEach(function (id) {
        $('#' + id).addEventListener('input', function () {
            const f = filtrosDelPanel();
            conActivos(function (o) { if (o.type === 'image') ponerFiltros(o, f); });
        });
    });
    $('#prComoFondo').addEventListener('click', function () {
        const a = lienzo.getActiveObject();
        if (a && a.type === 'image') comoFondo(a);
    });

    // Los controles deslizantes registran el cambio al soltarlos (no en cada paso)
    ['prInterlineado', 'prEspaciado', 'prTextoPieza', 'prBorde', 'prRadio', 'prByN', 'prBrillo', 'prContraste', 'prDesenfoque', 'prOpacidad'].forEach(function (id) {
        $('#' + id).addEventListener('change', registrar);
    });

    // Posición y efectos
    $('#prOpacidad').addEventListener('input', function () { const v = +this.value / 100; conActivos(function (o) { o.set('opacity', v); }); });
    $('#prSombra').addEventListener('change', function () {
        const si = this.checked, u = unidad();
        conActivos(function (o) { o.set('shadow', si ? new fabric.Shadow({ color: 'rgba(0,0,0,.35)', blur: 26 * u, offsetX: 0, offsetY: 10 * u }) : null); });
        registrar();
    });
    $$('[data-alinear]').forEach(function (b) { b.addEventListener('click', function () { alinear(b.getAttribute('data-alinear')); }); });
    $$('[data-capa]').forEach(function (b) { b.addEventListener('click', function () { moverCapa(b.getAttribute('data-capa')); }); });
    $$('[data-voltear]').forEach(function (b) {
        b.addEventListener('click', function () {
            const eje = b.getAttribute('data-voltear') === 'x' ? 'flipX' : 'flipY';
            conActivos(function (o) { o.set(eje, !o[eje]); });
            registrar();
        });
    });
    $('#prDuplicar').addEventListener('click', duplicar);
    $('#prEliminar').addEventListener('click', eliminar);
    $('#prBloquear').addEventListener('click', function () {
        const a = lienzo.getActiveObject();
        if (!a) return;
        const si = !a.bloqueado;
        activos().forEach(function (o) { bloquear(o, si); });
        if (si) { lienzo.discardActiveObject(); aviso('Elemento bloqueado. Se desbloquea desde "Capas".'); }
        lienzo.requestRenderAll();
        registrar();
        pintarProps();
    });
    $('#prAgrupar').addEventListener('click', function () {
        const a = lienzo.getActiveObject();
        if (!a || a.type !== 'activeSelection') return;
        a.toGroup().set({ nombreDs: 'Grupo' });
        lienzo.requestRenderAll();
        registrar();
        pintarProps();
    });
    $('#prDesagrupar').addEventListener('click', function () {
        const a = lienzo.getActiveObject();
        if (!a || a.type !== 'group') return;
        a.toActiveSelection();
        lienzo.requestRenderAll();
        registrar();
        pintarProps();
    });

    // ---------------------------------------------------------------- Capas

    function nombreDe(o) {
        if (esTexto(o)) return (o.text || 'Texto').replace(/\s+/g, ' ').slice(0, 34);
        return o.nombreDs || { rect: 'Rectángulo', circle: 'Círculo', image: 'Foto', group: 'Grupo', path: 'Forma', polygon: 'Forma', line: 'Línea', triangle: 'Triángulo' }[o.type] || 'Elemento';
    }
    function iconoDe(o) {
        if (esTexto(o)) return 'fa-font';
        if (o.type === 'image') return 'fa-image';
        if (o.tipoDs === 'icono') return 'fa-icons';
        if (o.type === 'group') return 'fa-object-group';
        return 'fa-shapes';
    }
    let capas = [];
    function pintarCapas() {
        if (panelActivo !== 'capas') return;
        const elegidos = activos();
        capas = lienzo.getObjects().filter(function (o) { return !o.esFondo; }).reverse();
        $('#edCapas').innerHTML = capas.map(function (o, i) {
            return '<li class="' + (elegidos.indexOf(o) !== -1 ? 'activa ' : '') + (o.bloqueado ? 'bloqueada ' : '') + (o.visible === false ? 'oculta' : '') + '" data-i="' + i + '">'
                 + '<button type="button" class="ed-capa-nombre"><i class="fas ' + iconoDe(o) + '" aria-hidden="true"></i><span>' + escapar(nombreDe(o)) + '</span></button>'
                 + '<button type="button" class="ed-capa-accion" data-accion="ver" title="' + (o.visible === false ? 'Mostrar' : 'Ocultar') + '" aria-label="' + (o.visible === false ? 'Mostrar' : 'Ocultar') + '"><i class="fas ' + (o.visible === false ? 'fa-eye-slash' : 'fa-eye') + '" aria-hidden="true"></i></button>'
                 + '<button type="button" class="ed-capa-accion" data-accion="candado" title="' + (o.bloqueado ? 'Desbloquear' : 'Bloquear') + '" aria-label="' + (o.bloqueado ? 'Desbloquear' : 'Bloquear') + '"><i class="fas ' + (o.bloqueado ? 'fa-lock' : 'fa-lock-open') + '" aria-hidden="true"></i></button></li>';
        }).join('') || '<li class="ed-vacio">El afiche está vacío.</li>';
    }
    $('#edCapas').addEventListener('click', function (e) {
        const fila = e.target.closest('li[data-i]');
        if (!fila) return;
        const o = capas[+fila.getAttribute('data-i')];
        if (!o) return;
        const accion = e.target.closest('[data-accion]');
        if (accion && accion.getAttribute('data-accion') === 'ver') {
            o.set('visible', o.visible === false);
            if (o.visible === false) lienzo.discardActiveObject();
        } else if (accion) {
            bloquear(o, !o.bloqueado);
            if (o.bloqueado) lienzo.discardActiveObject();
        } else if (o.visible !== false) {
            lienzo.setActiveObject(o);
        }
        lienzo.requestRenderAll();
        if (accion) registrar();
        pintarProps();
    });

    // ---------------------------------------------------------------- Plantillas: un afiche del diseñador convertido en elementos

    // Foto encuadrada en un recuadro, igual que la pinta js/afiches.js: se recorta lo que sobra y se redondean las esquinas
    function fotoEncuadrada(el, x, y, w, h, radio, focoY, recorte) {
        const fw = el.naturalWidth, fh = el.naturalHeight * (1 - (recorte || 0));
        const k = Math.max(w / fw, h / fh), vw = w / k, vh = h / k;
        const foto = new fabric.Image(el, { left: x, top: y, cropX: (fw - vw) / 2, cropY: (fh - vh) * focoY, width: vw, height: vh, scaleX: k, scaleY: k });
        if (radio) {
            foto.set({ mascaraDs: 'redonda', clipPath: new fabric.Rect({ width: vw, height: vh, rx: radio / k, ry: radio / k, originX: 'center', originY: 'center', left: 0, top: 0 }) });
        }
        return foto;
    }

    function abrirDiseno(d, nombre) {
        return A.plano(d).then(function (L) {
            enPausa = true;
            lienzo.clear();
            doc = { id: null, nombre: nombre || 'Afiche sin título', ancho: L.ancho, alto: L.alto };
            $('#edNombre').value = doc.nombre;

            const T = L.tema, u = L.u, s = L.s, W = L.ancho, H = L.alto, x = L.area.x;
            const pon = function (o, nombreCapa, extra) {
                o.set(Object.assign(nombreCapa ? { nombreDs: nombreCapa } : {}, extra || {}));
                lienzo.add(o);
                return o;
            };
            const degradado = function (tipo, coords, paradas) { return new fabric.Gradient({ type: tipo, gradientUnits: 'percentage', coords: coords, colorStops: paradas }); };
            const letra = function (peso, px, color, extra) { return Object.assign({ fontFamily: 'Inter', fontWeight: peso, fontSize: px, fill: color }, extra || {}); };
            const textoDe = function (b) { return b.t.filas.map(function (f) { return f.palabras.map(function (p) { return p.t; }).join(' '); }).join(' '); };
            // Fabric deja más aire arriba del primer renglón cuando el interlineado es mayor que 1: se compensa
            const subir = function (px, interlineado) { return px * 1.13 * (interlineado - 1); };

            ponerFondo({ tipo: 'degradado', colores: T.fondo.slice() });

            if (L.plantilla === 'fondo') {
                if (L.imagen) pon(fotoEncuadrada(L.imagen, 0, 0, W, H, 0, 0.3, L.recorte), 'Foto de fondo', Object.assign({ cubreDs: 'todo' }, BLOQUEO));
                pon(new fabric.Rect({ left: 0, top: 0, width: W, height: H, strokeWidth: 0, fill: degradado('linear', { x1: 0, y1: 0, x2: 0, y2: 1 }, [
                    { offset: 0, color: 'rgba(' + T.velo + ',.30)' }, { offset: 0.35, color: 'rgba(' + T.velo + ',.45)' },
                    { offset: 0.62, color: 'rgba(' + T.velo + ',.86)' }, { offset: 1, color: 'rgba(' + T.velo + ',.95)' }]) }),
                    'Velo sobre la foto', Object.assign({ cubreDs: 'todo' }, BLOQUEO));
            } else {
                pon(new fabric.Rect({ left: 0, top: 0, width: W, height: H, strokeWidth: 0, fill: degradado('radial', { x1: 1, y1: 0, r1: 0, x2: 1, y2: 0, r2: 0.75 }, [
                    { offset: 0, color: A.colorAlfa(T.acento, T.texto === '#FFFFFF' ? 0.26 : 0.16) }, { offset: 1, color: A.colorAlfa(T.acento, 0) }]) }),
                    'Brillo', Object.assign({ cubreDs: 'todo' }, BLOQUEO));
                if (L.conAnillos) {
                    pon(new fabric.Group([190, 310].map(function (r) {
                        return new fabric.Circle({ radius: r * u, left: W - 40 * u - r * u, top: 30 * u - r * u, fill: 'transparent', stroke: T.trazo, strokeWidth: 2 * u });
                    })), 'Anillos de adorno', BLOQUEO);
                }
                if (L.adorno) {
                    const partes = L.adorno.barras.map(function (b) {
                        return new fabric.Rect({ left: b[0], top: b[1], width: b[2], height: b[3], rx: 10 * u, ry: 10 * u, fill: T.trazo, strokeWidth: 0 });
                    });
                    partes.push(new fabric.Polyline(L.adorno.cimas.map(function (c) { return { x: c[0], y: c[1] }; }),
                        { fill: 'transparent', stroke: A.colorAlfa(T.acento, 0.45), strokeWidth: 4 * u, strokeLineJoin: 'round' }));
                    L.adorno.cimas.forEach(function (c) {
                        partes.push(new fabric.Circle({ radius: 8 * u, left: c[0] - 8 * u, top: c[1] - 8 * u, fill: A.colorAlfa(T.acento, 0.7), strokeWidth: 0 }));
                    });
                    pon(new fabric.Group(partes), 'Gráfica de adorno', BLOQUEO);
                }
            }

            if (L.panel) {
                const p = L.panel, radio = 34 * u;
                if (L.imagen) {
                    const foto = fotoEncuadrada(L.imagen, p.x, p.y, p.w, p.h, radio, L.recorte ? 0.35 : (L.razonImagen > 1.15 ? 0.08 : 0.22), L.recorte);
                    foto.set('shadow', new fabric.Shadow({ color: 'rgba(0,0,0,.30)', blur: 44 * u, offsetX: 0, offsetY: 16 * u }));
                    pon(foto, 'Foto');
                } else {
                    pon(new fabric.Rect({ left: p.x, top: p.y, width: p.w, height: p.h, rx: radio, ry: radio, fill: T.chip, strokeWidth: 0 }), 'Espacio para la foto');
                }
                if (L.plantilla === 'oferta' && d.insignia) {
                    const sello = crearPieza({ tipo: 'insignia', texto: String(d.insignia), fondo: '#DC2626', color: '#FFFFFF', px: 42 * u });
                    pon(sello, null, { left: p.x + p.w - sello.width - 22 * u, top: p.y + 22 * u });
                }
            }

            // Logo sobre su placa blanca
            (function () {
                const S = L.sello, e = u * S.k;
                const partes = [new fabric.Rect({ left: S.x, top: S.y, width: S.ancho, height: S.alto, rx: 20 * e, ry: 20 * e, fill: '#FFFFFF', strokeWidth: 0,
                                                  shadow: new fabric.Shadow({ color: 'rgba(0,0,0,.18)', blur: 18 * e, offsetX: 0, offsetY: 6 * e }) })];
                if (L.logo) {
                    const k = 58 * e / L.logo.naturalHeight;
                    partes.push(new fabric.Image(L.logo, { left: S.x + 22 * e, top: S.y + 14 * e, scaleX: k, scaleY: k }));
                }
                pon(new fabric.Group(partes), 'Logo');
            })();

            L.bloques.forEach(function (b) {
                const y = b.y;
                if (b.tipo === 'etiqueta') {
                    pon(crearPieza({ tipo: 'etiqueta', texto: sinMarcas(d.etiqueta).texto, fondo: T.chip, color: T.resalte, px: 23 * s }), null, { left: x, top: y });
                } else if (b.tipo === 'comilla') {
                    pon(new fabric.Text('“', { left: x - 8 * s, top: y - 34 * s, fontFamily: 'Georgia', fontWeight: '700', fontSize: 170 * s, fill: T.resalte, lineHeight: 1 }), 'Comillas');
                } else if (b.tipo === 'titulo') {
                    const limpio = sinMarcas(d.titulo);
                    const t = new fabric.Textbox(limpio.texto, letra('800', b.t.px, T.texto, { left: x, top: y, width: L.area.w + 4, lineHeight: 0.99 }));
                    limpio.rangos.forEach(function (r) { t.setSelectionStyles({ fill: T.resalte }, r[0], r[1]); });
                    pon(t);
                } else if (b.tipo === 'texto') {
                    const lh = 1.257;
                    pon(new fabric.Textbox(sinMarcas(d.texto).texto, letra(b.t.peso, b.t.px, L.plantilla === 'resena' ? T.texto : T.suave,
                        { left: x, top: y - subir(b.t.px, lh), width: L.area.w + 4, lineHeight: lh })));
                } else if (b.tipo === 'punto') {
                    const circulo = new fabric.Circle({ radius: 18 * s, left: 1 * s, top: 1 * s, fill: T.acento, strokeWidth: 0 });
                    let marca;
                    if (b.letra) {
                        marca = new fabric.Text(b.letra, letra('800', 21 * s, T.sobreAcento));
                        marca.set({ left: 19 * s - marca.width / 2, top: 20 * s - marca.height / 2 });
                    } else {
                        marca = new fabric.Polyline([{ x: 11 * s, y: 19 * s }, { x: 17 * s, y: 25 * s }, { x: 28 * s, y: 13 * s }],
                            { fill: 'transparent', stroke: T.sobreAcento, strokeWidth: 4.5 * s, strokeLineCap: 'round', strokeLineJoin: 'round' });
                    }
                    pon(new fabric.Group([circulo, marca]), b.letra ? 'Opción ' + b.letra : 'Viñeta', { left: x, top: y });
                    const lh = 1.15;
                    pon(new fabric.Textbox(textoDe(b), letra('600', b.t.px, T.texto,
                        { left: x + 56 * s, top: y + (38 * s - b.t.px * 1.08) / 2 - subir(b.t.px, lh), width: L.area.w - 54 * s, lineHeight: lh })));
                } else if (b.tipo === 'precio') {
                    const precio = pon(new fabric.IText(String(d.precio), letra('800', 72 * s, T.texto, { left: x, top: y - 6 * s })));
                    if (d.precioAntes) pon(new fabric.IText(String(d.precioAntes), letra('500', 32 * s, T.suave, { left: x + precio.width + 26 * s, top: y + 28 * s, linethrough: true })));
                } else if (b.tipo === 'estrellas') {
                    const estrellas = [];
                    for (let i = 0; i < 5; i++) estrellas.push(new fabric.Polygon(puntosEstrella(20 * s), { left: i * 48 * s, top: 0, fill: '#FBBF24', strokeWidth: 0 }));
                    pon(new fabric.Group(estrellas), 'Estrellas', { left: x, top: y });
                } else if (b.tipo === 'autor') {
                    const inicial = new fabric.Text(String(d.autor).trim().charAt(0).toUpperCase(), letra('800', 34 * s, T.sobreAcento));
                    inicial.set({ left: 36 * s - inicial.width / 2, top: 36 * s - inicial.height / 2 });
                    pon(new fabric.Group([new fabric.Circle({ radius: 36 * s, left: 0, top: 0, fill: T.acento, strokeWidth: 0 }), inicial]), 'Inicial del cliente', { left: x, top: y + 2 * s });
                    pon(new fabric.IText(String(d.autor), letra('700', 33 * s, T.texto, { left: x + 92 * s, top: y + (d.cargo ? 0 : 18) * s })));
                    if (d.cargo) pon(new fabric.IText(String(d.cargo), letra('400', 26 * s, T.suave, { left: x + 92 * s, top: y + 42 * s })));
                } else if (b.tipo === 'boton') {
                    pon(crearPieza({ tipo: 'boton', texto: sinMarcas(d.boton).texto, flecha: true, fondo: T.acento, color: T.sobreAcento, px: 31 * s }), null, { left: x, top: y });
                }
            });

            if (L.pie) pon(new fabric.IText(sinMarcas(d.pie).texto, letra('500', L.pie.px, T.suave, { left: L.pie.x, top: L.pie.y - L.pie.px * 0.62 })));
            pon(new fabric.Rect({ left: 0, top: H - L.barra, width: W, height: L.barra, fill: T.acento, strokeWidth: 0 }), 'Franja de marca', { cubreDs: 'abajo' });

            enPausa = false;
            lienzo.discardActiveObject();
            acercamiento = 1;
            ajustarVista();
            reiniciarHistorial();
            pintarProps();
        });
    }

    function enBlanco() {
        enPausa = true;
        lienzo.clear();
        doc = { id: null, nombre: 'Afiche sin título', ancho: doc.ancho, alto: doc.alto };
        $('#edNombre').value = doc.nombre;
        ponerFondo({ tipo: 'color', color: '#FFFFFF' });
        enPausa = false;
        ajustarVista();
        reiniciarHistorial();
        pintarProps();
    }

    // Miniaturas de las plantillas (las publicaciones listas del módulo)
    $('#edPlantillas').innerHTML = DATOS.publicaciones.map(function (p, i) {
        return '<button type="button" class="ed-plantilla" data-plantilla="' + i + '" title="' + escapar(p.tema) + '"><canvas></canvas><span>' + escapar(p.tema) + '</span></button>';
    }).join('');
    $$('#edPlantillas .ed-plantilla').forEach(function (boton) {
        const pub = DATOS.publicaciones[+boton.getAttribute('data-plantilla')];
        A.dibujar($('canvas', boton), pub.diseno, 0.25);
        boton.addEventListener('click', function () {
            if (!confirmarReemplazo()) return;
            abrirDiseno(pub.diseno, pub.tema).then(function () { cerrarPanelEnCelular(); });
        });
    });
    $('#edEnBlanco').addEventListener('click', function () { if (confirmarReemplazo()) { enBlanco(); cerrarPanelEnCelular(); } });

    // ---------------------------------------------------------------- Paneles de herramientas

    function abrirPanel(clave) {
        panelActivo = clave;
        $$('.ed-riel-boton').forEach(function (b) {
            const activo = b.getAttribute('data-panel') === clave;
            b.classList.toggle('activo', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
        $$('.ed-seccion').forEach(function (seccion) { seccion.hidden = seccion.getAttribute('data-panel') !== clave; });
        document.body.classList.add('panel-abierto');
        if (clave === 'capas') pintarCapas();
        if (clave === 'disenos') pintarDisenos();
    }
    function cerrarPanelEnCelular() { document.body.classList.remove('panel-abierto'); }
    $$('.ed-riel-boton').forEach(function (b) {
        b.addEventListener('click', function () {
            const clave = b.getAttribute('data-panel');
            // En pantallas pequeñas, tocar la herramienta abierta la cierra para dejar ver el afiche
            if (clave === panelActivo && document.body.classList.contains('panel-abierto') && window.innerWidth <= 900) { cerrarPanelEnCelular(); return; }
            abrirPanel(clave);
        });
    });
    $('#edPanelCerrar').addEventListener('click', cerrarPanelEnCelular);

    $$('[data-texto]').forEach(function (b) { b.addEventListener('click', function () { nuevoTexto(b.getAttribute('data-texto')); cerrarPanelEnCelular(); }); });
    $$('[data-pieza]').forEach(function (b) { b.addEventListener('click', function () { nuevaPieza(b.getAttribute('data-pieza')); cerrarPanelEnCelular(); }); });
    $$('[data-forma]').forEach(function (b) { b.addEventListener('click', function () { nuevaForma(b.getAttribute('data-forma')); cerrarPanelEnCelular(); }); });
    $$('[data-icono]').forEach(function (b) { b.addEventListener('click', function () { nuevoIcono(b.getAttribute('data-icono')); cerrarPanelEnCelular(); }); });
    $$('[data-foto]').forEach(function (b) {
        b.addEventListener('click', function () {
            nuevaImagen(b.getAttribute('data-foto'), { recorte: +b.getAttribute('data-recorte') || 0, nombre: b.getAttribute('title') });
            cerrarPanelEnCelular();
        });
    });
    $$('[data-logo]').forEach(function (b) { b.addEventListener('click', function () { nuevaImagen(DATOS.logo, { logo: b.getAttribute('data-logo') }); cerrarPanelEnCelular(); }); });
    $('#edSubir').addEventListener('change', function () {
        const archivos = Array.prototype.slice.call(this.files || []).filter(function (a) { return /^image\/(png|jpeg|webp)$/.test(a.type); }).slice(0, 6);
        this.value = '';
        if (!archivos.length) { aviso('Sube imágenes JPG, PNG o WebP'); return; }
        archivos.forEach(function (archivo) {
            leerFoto(archivo).then(function (url) { nuevaImagen(url, { nombre: archivo.name.replace(/\.[^.]+$/, '').slice(0, 30) }); })
                .catch(function () { aviso('No se pudo leer ' + archivo.name); });
        });
        cerrarPanelEnCelular();
    });

    // ---------------------------------------------------------------- Barra superior

    $('#edNombre').addEventListener('change', function () { doc.nombre = this.value.trim() || 'Afiche sin título'; programarAutoguardado(); });
    $('#edDeshacer').addEventListener('click', deshacer);
    $('#edRehacer').addEventListener('click', rehacer);
    $('#edAlejar').addEventListener('click', function () { acercamiento = Math.max(0.3, acercamiento / 1.25); ajustarVista(); });
    $('#edAcercar').addEventListener('click', function () { acercamiento = Math.min(6, acercamiento * 1.25); ajustarVista(); });
    $('#edAjustar').addEventListener('click', function () { acercamiento = 1; ajustarVista(); });
    $('#edGuardar').addEventListener('click', guardar);

    function nombreArchivo(extension) {
        const base = (doc.nombre || 'afiche').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50) || 'afiche';
        return 'datasense-' + base + '.' + extension;
    }
    function comoArchivo(formato) {
        return fetch(imagenFinal(formato)).then(function (r) { return r.blob(); });
    }
    $$('[data-exportar]').forEach(function (b) {
        b.addEventListener('click', function () {
            const formato = b.getAttribute('data-exportar');
            comoArchivo(formato).then(function (blob) {
                const enlace = document.createElement('a');
                enlace.href = URL.createObjectURL(blob);
                enlace.download = nombreArchivo(formato);
                document.body.appendChild(enlace);
                enlace.click();
                enlace.remove();
                setTimeout(function () { URL.revokeObjectURL(enlace.href); }, 4000);
                aviso('Imagen descargada (' + doc.ancho + ' × ' + doc.alto + ' px)');
            }).catch(function () { aviso('No se pudo generar la imagen. Inténtalo de nuevo.'); });
        });
    });
    if (navigator.clipboard && window.ClipboardItem && window.isSecureContext) {
        $('#edCopiar').hidden = false;
        $('#edCopiar').addEventListener('click', function () {
            navigator.clipboard.write([new ClipboardItem({ 'image/png': comoArchivo('png') })])
                .then(function () { aviso('Imagen copiada: pégala en la publicación con Ctrl + V'); })
                .catch(function () { aviso('Este navegador no deja copiar la imagen. Descárgala.'); });
        });
    }
    (function () {
        let puede = false;
        try { puede = !!(navigator.canShare && navigator.canShare({ files: [new File([new Blob(['x'], { type: 'image/png' })], 'p.png', { type: 'image/png' })] })); } catch (err) { puede = false; }
        if (!puede) return;
        $('#edCompartir').hidden = false;
        $('#edCompartir').addEventListener('click', function () {
            comoArchivo('png').then(function (blob) { return navigator.share({ files: [new File([blob], nombreArchivo('png'), { type: 'image/png' })] }); })
                .catch(function (err) { if (err && err.name !== 'AbortError') aviso('No se pudo compartir desde este navegador. Descarga la imagen.'); });
        });
    })();

    // ---------------------------------------------------------------- Lienzo: selección, guías y teclado

    lienzo.on('object:added', function (e) {
        // Estirar un texto por arriba o por abajo lo deformaría: esos dos controles se quitan
        if (esTexto(e.target) || e.target.tipoDs === 'icono') e.target.setControlsVisibility({ mt: false, mb: false });
    });
    const alSeleccionar = function () { if (!silencio) pintarProps(); };
    lienzo.on('selection:created', alSeleccionar);
    lienzo.on('selection:updated', alSeleccionar);
    lienzo.on('selection:cleared', alSeleccionar);
    lienzo.on('object:modified', function (e) {
        normalizarTexto(e.target);
        registrar();
        pintarProps();
    });
    let temporizadorTexto = null;
    lienzo.on('text:changed', function () {
        clearTimeout(temporizadorTexto);
        temporizadorTexto = setTimeout(registrar, 700);
    });

    // Guías: al arrastrar, el elemento se pega al centro de la página y aparece una línea que lo indica
    let guias = { v: false, h: false };
    lienzo.on('object:moving', function (e) {
        const o = e.target, tolerancia = 9 / zoom;
        let c = o.getCenterPoint();
        guias = { v: false, h: false };
        if (Math.abs(c.x - doc.ancho / 2) < tolerancia) { o.setPositionByOrigin(new fabric.Point(doc.ancho / 2, c.y), 'center', 'center'); guias.v = true; }
        c = o.getCenterPoint();
        if (Math.abs(c.y - doc.alto / 2) < tolerancia) { o.setPositionByOrigin(new fabric.Point(c.x, doc.alto / 2), 'center', 'center'); guias.h = true; }
    });
    let guiasPintadas = false;
    lienzo.on('before:render', function () {
        if (guiasPintadas && lienzo.contextTop) { lienzo.clearContext(lienzo.contextTop); guiasPintadas = false; }
    });
    lienzo.on('after:render', function () {
        if (!guias.v && !guias.h) return;
        const ctx = lienzo.contextTop;
        guiasPintadas = true;
        ctx.save();
        ctx.strokeStyle = '#EC4899';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([6, 4]);
        ctx.beginPath();
        if (guias.v) { ctx.moveTo(doc.ancho * zoom / 2, 0); ctx.lineTo(doc.ancho * zoom / 2, doc.alto * zoom); }
        if (guias.h) { ctx.moveTo(0, doc.alto * zoom / 2); ctx.lineTo(doc.ancho * zoom, doc.alto * zoom / 2); }
        ctx.stroke();
        ctx.restore();
    });
    lienzo.on('mouse:up', function () {
        if (!guias.v && !guias.h) return;
        guias = { v: false, h: false };
        lienzo.requestRenderAll();
    });

    let temporizadorFlechas = null;
    document.addEventListener('keydown', function (e) {
        // Dentro de un campo (o mientras se escribe en un texto del afiche) las teclas son del campo
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable) return;
        const a = lienzo.getActiveObject(), ctrl = e.ctrlKey || e.metaKey, tecla = e.key.toLowerCase();
        if (ctrl && tecla === 'z') { e.preventDefault(); if (e.shiftKey) rehacer(); else deshacer(); }
        else if (ctrl && tecla === 'y') { e.preventDefault(); rehacer(); }
        else if (ctrl && tecla === 's') { e.preventDefault(); guardar(); }
        else if (ctrl && tecla === 'd') { e.preventDefault(); duplicar(); }
        else if (ctrl && tecla === 'c') { copiar(); }
        else if (ctrl && tecla === 'v') { pegar(); }
        else if ((e.key === 'Delete' || e.key === 'Backspace') && a) { e.preventDefault(); eliminar(); }
        else if (e.key === 'Escape') { lienzo.discardActiveObject(); lienzo.requestRenderAll(); }
        else if (a && e.key.indexOf('Arrow') === 0) {
            e.preventDefault();
            const paso = e.shiftKey ? 10 : 1;
            a.set({ left: a.left + (e.key === 'ArrowLeft' ? -paso : e.key === 'ArrowRight' ? paso : 0),
                    top: a.top + (e.key === 'ArrowUp' ? -paso : e.key === 'ArrowDown' ? paso : 0) });
            a.setCoords();
            lienzo.requestRenderAll();
            clearTimeout(temporizadorFlechas);
            temporizadorFlechas = setTimeout(registrar, 500);
        }
    });

    let temporizadorVentana = null;
    window.addEventListener('resize', function () {
        clearTimeout(temporizadorVentana);
        temporizadorVentana = setTimeout(ajustarVista, 150);
    });
    document.addEventListener('visibilitychange', function () { if (document.hidden) autoguardar(); });

    // ---------------------------------------------------------------- Arranque

    // Viene del diseñador rápido con un afiche, retoma el trabajo anterior o abre la primera plantilla
    Promise.all([cargarFuente('Inter', '800'), cargarFuente('Inter', '400'), cargarFuente('Font Awesome 6 Free', '900'), cargarFuente('Font Awesome 6 Brands', '400')]).then(function () {
        const porDefecto = function () {
            const primera = DATOS.publicaciones[0];
            return primera ? abrirDiseno(primera.diseno, 'Afiche sin título') : Promise.resolve(enBlanco());
        };
        let entrada = null;
        try {
            entrada = JSON.parse(sessionStorage.getItem('ds_editor_entrada') || 'null');
            sessionStorage.removeItem('ds_editor_entrada');
        } catch (err) { entrada = null; }
        if (entrada && typeof entrada === 'object') return abrirDiseno(entrada, 'Afiche sin título');
        return BD.leer('actual').then(function (trabajo) {
            if (!trabajo || !trabajo.json) return porDefecto();
            doc.id = trabajo.docId || null;
            doc.nombre = trabajo.nombre || 'Afiche sin título';
            $('#edNombre').value = doc.nombre;
            return cargarInstantanea(trabajo.json).then(reiniciarHistorial);
        }).catch(porDefecto);
    }).then(function () {
        document.body.classList.add('listo');
    });

    // Para las pruebas automáticas y para depurar desde la consola del navegador
    window.EditorAfiches = { lienzo: lienzo, documento: function () { return doc; }, imagenFinal: imagenFinal, abrirDiseno: abrirDiseno };
})();
