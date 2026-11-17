/**
 * AFICHES PARA REDES SOCIALES - DATASENSE
 * Dibuja en un <canvas> las piezas que se publican en Facebook, Instagram, LinkedIn y WhatsApp.
 * Lo usa el módulo "Redes Sociales" del panel: el diseñador y las publicaciones listas pintan con este mismo motor,
 * así un afiche se ve igual en la vista previa, en la miniatura y en el archivo que se descarga.
 *
 * Un diseño es un objeto sencillo:
 *   { plantilla, formato, tema, etiqueta, titulo, texto, puntos: [], boton, pie,
 *     precio, precioAntes, insignia, autor, cargo, imagen, imagenRecorte }
 * En el título, lo que vaya entre *asteriscos* sale en el color de resalte. Una idea que empiece con "A · " (o B, C, 1, 2…)
 * se pinta como opción de encuesta: con la letra dentro del círculo.
 */
(function (global) {
    'use strict';

    const FORMATOS = {
        cuadrado:   { ancho: 1080, alto: 1080, nombre: 'Cuadrado',   detalle: 'Instagram, Facebook y LinkedIn' },
        vertical:   { ancho: 1080, alto: 1350, nombre: 'Vertical',   detalle: 'Instagram y Facebook' },
        historia:   { ancho: 1080, alto: 1920, nombre: 'Historia',   detalle: 'Historias y estados' },
        horizontal: { ancho: 1200, alto: 628,  nombre: 'Horizontal', detalle: 'LinkedIn y Facebook' },
    };

    // velo: color (r,g,b) con el que se cubre la foto de fondo para que el texto se lea
    const TEMAS = {
        azul:  { nombre: 'Azul corporativo', fondo: ['#0A2E4F', '#0F4C6E'], texto: '#FFFFFF', suave: 'rgba(255,255,255,.84)', acento: '#3FAE49', sobreAcento: '#FFFFFF', resalte: '#86E08F', chip: 'rgba(255,255,255,.12)', trazo: 'rgba(255,255,255,.07)', velo: '10,46,79' },
        verde: { nombre: 'Verde DataSense',  fondo: ['#0B4A3C', '#23934F'], texto: '#FFFFFF', suave: 'rgba(255,255,255,.88)', acento: '#0A2E4F', sobreAcento: '#FFFFFF', resalte: '#D9F99D', chip: 'rgba(255,255,255,.15)', trazo: 'rgba(255,255,255,.09)', velo: '11,74,60' },
        claro: { nombre: 'Claro',            fondo: ['#FFFFFF', '#E6EFF5'], texto: '#0A2E4F', suave: '#475569',               acento: '#3FAE49', sobreAcento: '#FFFFFF', resalte: '#2B8A38', chip: 'rgba(10,46,79,.07)',    trazo: 'rgba(10,46,79,.06)',    velo: '255,255,255' },
        noche: { nombre: 'Noche',            fondo: ['#0B1220', '#1B2B4B'], texto: '#FFFFFF', suave: 'rgba(255,255,255,.82)', acento: '#00CCFF', sobreAcento: '#062434', resalte: '#7DD3FC', chip: 'rgba(255,255,255,.10)', trazo: 'rgba(255,255,255,.06)', velo: '11,18,32' },
    };

    // Campos que usa cada plantilla (el diseñador muestra solo esos)
    const PLANTILLAS = {
        texto:  { nombre: 'Mensaje',           icono: 'fa-align-left', campos: ['etiqueta', 'titulo', 'texto', 'puntos', 'boton', 'pie'] },
        foto:   { nombre: 'Con foto',          icono: 'fa-image',      campos: ['etiqueta', 'titulo', 'texto', 'puntos', 'boton', 'pie', 'imagen'] },
        fondo:  { nombre: 'Foto de fondo',     icono: 'fa-panorama',   campos: ['etiqueta', 'titulo', 'texto', 'boton', 'pie', 'imagen'] },
        oferta: { nombre: 'Producto u oferta', icono: 'fa-tag',        campos: ['etiqueta', 'titulo', 'texto', 'precio', 'precioAntes', 'insignia', 'boton', 'pie', 'imagen'] },
        resena: { nombre: 'Reseña',            icono: 'fa-quote-left', campos: ['etiqueta', 'texto', 'autor', 'cargo', 'pie', 'imagen'] },
    };

    let rutaLogo = '../img/logo.png';

    // ---------------------------------------------------------------- Recursos

    const imagenes = new Map();
    function cargarImagen(url) {
        if (!url) return Promise.resolve(null);
        if (!imagenes.has(url)) {
            imagenes.set(url, new Promise(function (listo) {
                const img = new Image();
                img.onload = function () { listo(img); };
                img.onerror = function () { listo(null); };
                img.src = url;
            }));
        }
        return imagenes.get(url);
    }

    // El canvas solo pinta con una fuente web si ya está cargada; se espera a Inter (con un tope, por si no hay red)
    let fuentes = null;
    function esperarFuentes() {
        if (!fuentes) {
            const carga = (document.fonts && document.fonts.load)
                ? Promise.all(['400', '500', '600', '700', '800'].map(function (peso) { return document.fonts.load(peso + ' 40px Inter'); }))
                : Promise.resolve();
            const tope = new Promise(function (listo) { setTimeout(listo, 2500); });
            fuentes = Promise.race([carga, tope]).catch(function () { /* se pinta con la fuente del sistema */ });
        }
        return fuentes;
    }

    // ---------------------------------------------------------------- Texto

    function fuente(peso, px) {
        return peso + ' ' + Math.round(px) + 'px Inter, "Segoe UI", system-ui, -apple-system, sans-serif';
    }

    // Palabras del texto; las que van entre *asteriscos* quedan marcadas para resaltarlas
    function palabras(texto) {
        const lista = [];
        let resaltado = false, terminaEnEspacio = true;
        String(texto || '').split(/(\*)/).forEach(function (trozo) {
            if (trozo === '*') { resaltado = !resaltado; return; }
            if (trozo === '') return;
            // "pegada": va unida a la palabra anterior, como el punto de "*datos*."
            let pegada = !terminaEnEspacio && !/^\s/.test(trozo);
            terminaEnEspacio = /\s$/.test(trozo);
            trozo.split(/(\n)/).forEach(function (parte) {
                if (parte === '\n') { lista.push({ salto: true }); pegada = false; return; }
                parte.split(/\s+/).forEach(function (palabra) {
                    if (palabra === '') { pegada = false; return; }
                    lista.push({ t: palabra, r: resaltado, pegada: pegada });
                    pegada = false;
                });
            });
        });
        return lista;
    }

    function lineas(ctx, texto, anchoMax) {
        const espacio = ctx.measureText(' ').width;
        const filas = [];
        let fila = [], ancho = 0;
        const cerrar = function () { filas.push({ palabras: fila, ancho: ancho }); fila = []; ancho = 0; };
        palabras(texto).forEach(function (p) {
            if (p.salto) { cerrar(); return; }
            const w = ctx.measureText(p.t).width;
            if (fila.length && !p.pegada && ancho + espacio + w > anchoMax) cerrar();
            const sep = (fila.length && !p.pegada) ? espacio : 0;
            fila.push({ t: p.t, r: p.r, x: ancho + sep, w: w });
            ancho += sep + w;
        });
        if (fila.length) cerrar();
        return filas;
    }

    // Reduce la letra hasta que el texto quepa en el ancho y en el número de renglones; si aun así no cabe, lo corta con "…"
    function encajar(ctx, texto, anchoMax, peso, pxMax, pxMin, maxLineas) {
        let px = pxMax, filas;
        for (;;) {
            ctx.font = fuente(peso, px);
            filas = lineas(ctx, texto, anchoMax);
            const cabe = filas.length <= maxLineas && filas.every(function (f) { return f.ancho <= anchoMax; });
            if (cabe || px <= pxMin) break;
            px = Math.max(pxMin, px - 2);
        }
        if (filas.length > maxLineas) {
            filas = filas.slice(0, maxLineas);
            const ultimas = filas[maxLineas - 1].palabras;
            if (ultimas.length) ultimas[ultimas.length - 1].t = ultimas[ultimas.length - 1].t.replace(/[.,;:]$/, '') + '…';
        }
        return { px: px, peso: peso, filas: filas };
    }

    function pintarLineas(ctx, bloque, x, y, color, resalte, interlineado) {
        ctx.font = fuente(bloque.peso, bloque.px);
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';
        bloque.filas.forEach(function (fila, i) {
            fila.palabras.forEach(function (p) {
                ctx.fillStyle = p.r ? resalte : color;
                ctx.fillText(p.t, x + p.x, y + i * bloque.px * interlineado);
            });
        });
    }

    // ---------------------------------------------------------------- Formas

    function rectRedondo(ctx, x, y, w, h, r) {
        r = Math.max(0, Math.min(r, w / 2, h / 2));
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    // Imagen que llena el recuadro sin deformarse (se recorta lo que sobre); focoY 0 = conserva la parte de arriba.
    // recorte: fracción de la imagen que se descarta por abajo (las fotos de la tienda traen ahí su propio rótulo y logo)
    function cubrir(ctx, img, x, y, w, h, radio, focoY, recorte) {
        ctx.save();
        rectRedondo(ctx, x, y, w, h, radio);
        ctx.clip();
        const fw = img.naturalWidth, fh = img.naturalHeight * (1 - (recorte || 0));
        const k = Math.max(w / fw, h / fh);
        const iw = fw * k, ih = fh * k;
        ctx.drawImage(img, 0, 0, fw, fh, x + (w - iw) / 2, y + (h - ih) * focoY, iw, ih);
        ctx.restore();
    }

    function estrella(ctx, cx, cy, radio) {
        ctx.beginPath();
        for (let i = 0; i < 10; i++) {
            const r = i % 2 === 0 ? radio : radio * 0.45;
            const a = -Math.PI / 2 + i * Math.PI / 5;
            ctx[i === 0 ? 'moveTo' : 'lineTo'](cx + r * Math.cos(a), cy + r * Math.sin(a));
        }
        ctx.closePath();
    }

    function colorAlfa(hex, alfa) {
        const n = parseInt(hex.slice(1), 16);
        return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + alfa + ')';
    }

    // ---------------------------------------------------------------- Composición

    function pintar(ctx, d, imagen, logo) {
        const F = FORMATOS[d.formato] || FORMATOS.cuadrado;
        const T = TEMAS[d.tema] || TEMAS.azul;
        const plantilla = PLANTILLAS[d.plantilla] ? d.plantilla : 'texto';
        const W = F.ancho, H = F.alto;
        const horizontal = W > H * 1.2, historia = H > W * 1.6;
        // u: unidad de medida. Todo se diseñó sobre 1080 px; el horizontal lleva la letra un poco más grande para que se lea en el muro
        const u = Math.min(W, H) / 1080 * (horizontal ? 1.3 : 1);
        const m = (horizontal ? 56 : 72) * u;
        const arriba = historia ? 170 * u : m;       // en las historias la aplicación tapa las franjas de arriba y de abajo
        const reservaAbajo = historia ? 150 * u : 0;
        const barra = 14 * u;
        const puntos = (Array.isArray(d.puntos) ? d.puntos : []).map(function (p) { return String(p).trim(); }).filter(Boolean).slice(0, 5);
        const usaPuntos = PLANTILLAS[plantilla].campos.indexOf('puntos') !== -1;

        // Fondo
        const degradado = ctx.createLinearGradient(0, 0, W, H);
        degradado.addColorStop(0, T.fondo[0]);
        degradado.addColorStop(1, T.fondo[1]);
        ctx.fillStyle = degradado;
        ctx.fillRect(0, 0, W, H);

        const selloAlto = 86 * u;
        const razonLogo = logo ? logo.naturalWidth / logo.naturalHeight : 3.4;
        const recorte = Math.min(0.6, Math.max(0, Number(d.imagenRecorte) || 0));
        const razonImagen = imagen ? imagen.naturalWidth / (imagen.naturalHeight * (1 - recorte)) : 0;
        const quiereFoto = plantilla === 'foto' || plantilla === 'oferta' || (plantilla === 'resena' && !!d.imagen);
        let area, panel = null, alineacion = 'centro', tituloMax = 88;
        let sello = { x: m, y: arriba, k: 1 };                                   // k: tamaño del sello (1 = normal)
        let pieAlto = barra + reservaAbajo + (d.pie ? 92 * u : 46 * u);         // franja de abajo que el contenido no pisa
        let pieAncho = W - 2 * m, pieY = H - barra - reservaAbajo - 46 * u;

        if (plantilla === 'fondo') {
            if (imagen) cubrir(ctx, imagen, 0, 0, W, H, 0, 0.3, recorte);
            const velo = ctx.createLinearGradient(0, 0, 0, H);
            // Deja ver la foto arriba y oscurece abajo, donde va el texto
            velo.addColorStop(0, 'rgba(' + T.velo + ',.30)');
            velo.addColorStop(0.35, 'rgba(' + T.velo + ',.45)');
            velo.addColorStop(0.62, 'rgba(' + T.velo + ',.86)');
            velo.addColorStop(1, 'rgba(' + T.velo + ',.95)');
            ctx.fillStyle = velo;
            ctx.fillRect(0, 0, W, H);
            const y = arriba + selloAlto + 30 * u;
            area = { x: m, y: y, w: horizontal ? W * 0.7 : W - 2 * m, h: H - y - pieAlto };
            alineacion = 'abajo';
            tituloMax = 84;
        } else if (quiereFoto) {
            // La foto va a un lado en los formatos anchos y arriba en los altos. En el cuadrado depende de la foto:
            // un retrato queda mejor a un lado y una imagen apaisada (un tablero, un producto), arriba.
            const apaisada = imagen ? razonImagen > 1.15 : plantilla !== 'foto';
            const alLado = horizontal || (H === W && !apaisada);
            const borde = m * 0.62;
            if (alLado) {
                // El recuadro toma la forma de la foto (dentro de unos límites) para recortarla lo menos posible
                const pw = W * (horizontal ? 0.42 : 0.43);
                const razon = Math.min(1.7, Math.max(0.62, razonImagen || 0.8));
                const altoMax = H - 2 * borde - barra;
                const ph = Math.min(altoMax, pw / razon);
                panel = { x: W - borde - pw, y: borde + (altoMax - ph) / 2, w: pw, h: ph };
                const y = arriba + selloAlto + 30 * u;
                area = { x: m, y: y, w: panel.x - m - 44 * u, h: H - y - pieAlto };
                pieAncho = panel.x - m - 30 * u;
                tituloMax = 70;
            } else {
                // Foto arriba: el sello baja al pie, a la derecha, para no tapar la imagen
                const parte = plantilla === 'resena' ? (H === W ? 0.27 : 0.29) : (H === W ? 0.41 : (historia ? 0.40 : 0.44));
                const py = historia ? arriba : borde;
                panel = { x: borde, y: py, w: W - 2 * borde, h: H * parte - (historia ? 0 : py) };
                const kSello = 0.84, anchoSello = (58 * razonLogo + 44) * u * kSello;
                sello = { x: W - m - anchoSello, y: H - barra - reservaAbajo - 24 * u - selloAlto * kSello, k: kSello };
                pieAlto = barra + reservaAbajo + selloAlto * kSello + 48 * u;
                pieAncho = W - 2 * m - anchoSello - 30 * u;
                pieY = sello.y + selloAlto * kSello / 2;
                const y = panel.y + panel.h + 44 * u;
                area = { x: m, y: y, w: W - 2 * m, h: H - y - pieAlto };
                tituloMax = 74;
            }
        } else {
            const y = arriba + selloAlto + 30 * u;
            area = { x: m, y: y, w: horizontal ? W * 0.64 : W - 2 * m, h: H - y - pieAlto };
        }

        // Contenido: se mide antes de pintar nada, porque los adornos del fondo necesitan saber dónde queda el texto.
        // En los formatos altos puede crecer para aprovechar el espacio; si no cabe, se achica.
        let c = null;
        for (let k = historia ? 1.4 : (H > W ? 1.12 : 1); k >= 0.5; k -= 0.05) {
            c = componer(k);
            if (c.total <= area.h) break;
        }
        const s = c.s, x = area.x;
        (function () {
            let y = alineacion === 'abajo' ? area.y + area.h - c.total : area.y + Math.max(0, (area.h - c.total) / 2);
            const anchoFilas = function (t) { return t.filas.reduce(function (mayor, f) { return Math.max(mayor, f.ancho); }, 0); };
            c.bloques.forEach(function (b) {
                b.y = y;
                // Hasta dónde llega el bloque hacia la derecha (aproximado para los que no son texto corrido)
                if (b.tipo === 'titulo' || b.tipo === 'texto') b.derecha = x + anchoFilas(b.t);
                else if (b.tipo === 'punto') b.derecha = x + 56 * s + anchoFilas(b.t);
                else if (b.tipo === 'boton') { ctx.font = fuente('700', 31 * s); b.derecha = x + ctx.measureText(String(d.boton) + '  →').width + 84 * s; }
                else if (b.tipo === 'etiqueta') { ctx.font = fuente('700', 23 * s); b.derecha = x + ctx.measureText(String(d.etiqueta).toUpperCase()).width * 1.12 + 64 * s; }
                else if (b.tipo === 'precio') { ctx.font = fuente('800', 72 * s); b.derecha = x + ctx.measureText(String(d.precio)).width + (d.precioAntes ? 190 * s : 0); }
                else b.derecha = x + 330 * s;
                y += b.alto + b.sep;
            });
        })();

        // Adornos de marca: un resplandor, dos anillos y unas barras que suben (sin tapar el texto)
        let adorno = null;   // medidas de lo que se pintó, para el editor libre
        if (plantilla !== 'fondo') {
            const brillo = ctx.createRadialGradient(W, 0, 0, W, 0, W * 0.75);
            brillo.addColorStop(0, colorAlfa(T.acento, T === TEMAS.claro ? 0.16 : 0.26));
            brillo.addColorStop(1, colorAlfa(T.acento, 0));
            ctx.fillStyle = brillo;
            ctx.fillRect(0, 0, W, H);
            if (!panel) {
                ctx.strokeStyle = T.trazo;
                ctx.lineWidth = 2 * u;
                [190, 310].forEach(function (radio) {
                    ctx.beginPath();
                    ctx.arc(W - 40 * u, 30 * u, radio * u, 0, Math.PI * 2);
                    ctx.stroke();
                });
                const alturas = [110, 180, 150, 250, 330].map(function (a) { return a * u * (horizontal ? 0.8 : 1); });
                const anchoBarra = 56 * u, hueco = 22 * u;
                const inicio = W - m * 0.7 - alturas.length * anchoBarra - (alturas.length - 1) * hueco;
                // Cada barra puede subir hasta el último bloque de texto que llega a su columna; todas se reducen en la misma
                // proporción para conservar la forma de la gráfica, y si quedarían diminutas no se pintan.
                let factor = 1;
                alturas.forEach(function (alto, i) {
                    const bx = inicio + i * (anchoBarra + hueco);
                    let tope = 0;
                    c.bloques.forEach(function (b) { if (b.derecha > bx - 22 * u) tope = Math.max(tope, b.y + b.alto); });
                    factor = Math.min(factor, (H - barra - tope - 64 * u) / alto);
                });
                if (factor >= 0.35) {
                    const cimas = [];
                    adorno = { barras: [], cimas: cimas };
                    ctx.fillStyle = T.trazo;
                    alturas.forEach(function (alto, i) {
                        const bx = inicio + i * (anchoBarra + hueco), h = alto * factor;
                        rectRedondo(ctx, bx, H - barra - h, anchoBarra, h + 20 * u, 10 * u);
                        ctx.fill();
                        adorno.barras.push([bx, H - barra - h, anchoBarra, h]);
                        cimas.push([bx + anchoBarra / 2, H - barra - h - 26 * u]);
                    });
                    ctx.strokeStyle = colorAlfa(T.acento, 0.45);
                    ctx.lineWidth = 4 * u;
                    ctx.lineJoin = 'round';
                    ctx.beginPath();
                    cimas.forEach(function (cima, i) { ctx[i === 0 ? 'moveTo' : 'lineTo'](cima[0], cima[1]); });
                    ctx.stroke();
                    ctx.fillStyle = colorAlfa(T.acento, 0.7);
                    cimas.forEach(function (cima) { ctx.beginPath(); ctx.arc(cima[0], cima[1], 8 * u, 0, Math.PI * 2); ctx.fill(); });
                }
            }
        }

        // Foto en su recuadro
        if (panel) {
            ctx.save();
            ctx.shadowColor = 'rgba(0,0,0,.30)';
            ctx.shadowBlur = 44 * u;
            ctx.shadowOffsetY = 16 * u;
            ctx.fillStyle = T.chip;
            rectRedondo(ctx, panel.x, panel.y, panel.w, panel.h, 34 * u);
            ctx.fill();
            ctx.restore();
            if (imagen) {
                // Encuadre: las imágenes de la tienda muestran su ventana, las apaisadas conservan la parte de arriba
                // (el encabezado de un pantallazo) y los retratos, la cara
                cubrir(ctx, imagen, panel.x, panel.y, panel.w, panel.h, 34 * u, recorte ? 0.35 : (razonImagen > 1.15 ? 0.08 : 0.22), recorte);
            } else {
                ctx.fillStyle = T.suave;
                ctx.font = fuente('600', 30 * u);
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('Elige o sube una foto', panel.x + panel.w / 2, panel.y + panel.h / 2);
            }
            if (plantilla === 'oferta' && d.insignia) {
                const r = 74 * u, cx = panel.x + panel.w - r - 22 * u, cy = panel.y + r + 22 * u;
                ctx.save();
                ctx.shadowColor = 'rgba(0,0,0,.28)';
                ctx.shadowBlur = 20 * u;
                ctx.fillStyle = '#DC2626';
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
                const t = encajar(ctx, String(d.insignia).replace(/\*/g, ''), r * 1.6, '800', 46 * u, 22 * u, 1);
                ctx.font = fuente('800', t.px);
                ctx.fillStyle = '#FFFFFF';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(t.filas[0].palabras.map(function (p) { return p.t; }).join(' '), cx, cy + 2 * u);
            }
        }

        // Sello con el logo (fondo blanco: el logo no se lee sobre colores oscuros)
        (function () {
            const e = u * sello.k, alto = 58 * e, ancho = alto * razonLogo;
            ctx.save();
            ctx.shadowColor = 'rgba(0,0,0,.18)';
            ctx.shadowBlur = 18 * e;
            ctx.shadowOffsetY = 6 * e;
            ctx.fillStyle = '#FFFFFF';
            rectRedondo(ctx, sello.x, sello.y, ancho + 44 * e, 86 * e, 20 * e);
            ctx.fill();
            ctx.restore();
            if (logo) {
                ctx.drawImage(logo, sello.x + 22 * e, sello.y + 14 * e, ancho, alto);
            } else {
                ctx.fillStyle = '#0A2E4F';
                ctx.font = fuente('800', 34 * e);
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText('DataSense', sello.x + 22 * e, sello.y + 43 * e, ancho);
            }
        })();

        // Bloques de contenido: se miden con un factor k y, si no caben en el área, se vuelve a medir más pequeño
        function componer(k) {
            const s = u * k, ancho = area.w, B = [];
            if (d.etiqueta) B.push({ tipo: 'etiqueta', alto: 50 * s, sep: 26 * s });
            if (plantilla === 'resena') B.push({ tipo: 'comilla', alto: 58 * s, sep: 16 * s });
            if (d.titulo && plantilla !== 'resena') {
                const t = encajar(ctx, d.titulo, ancho, '800', tituloMax * s, 40 * s, horizontal ? 3 : 5);
                B.push({ tipo: 'titulo', t: t, alto: t.filas.length * t.px * 1.12, sep: 24 * s });
            }
            if (d.texto) {
                const px = (plantilla === 'resena' ? 40 : 33) * s;
                const t = encajar(ctx, d.texto, ancho, plantilla === 'resena' ? '500' : '400', px, px * 0.8, plantilla === 'resena' ? 10 : 6);
                B.push({ tipo: 'texto', t: t, alto: t.filas.length * t.px * 1.42, sep: 30 * s });
            }
            if (usaPuntos) {
                puntos.forEach(function (punto, i) {
                    // "A · Opción" (encuestas): la letra va dentro del círculo en lugar del chulo
                    const opcion = /^([A-Z0-9])\s*[·.)-]\s+(.+)$/.exec(punto);
                    const t = encajar(ctx, opcion ? opcion[2] : punto, ancho - 58 * s, '600', 31 * s, 25 * s, 2);
                    B.push({ tipo: 'punto', t: t, letra: opcion ? opcion[1] : '', alto: Math.max(40 * s, t.filas.length * t.px * 1.3), sep: (i === puntos.length - 1 ? 32 : 16) * s });
                });
            }
            if (plantilla === 'oferta' && d.precio) B.push({ tipo: 'precio', alto: 80 * s, sep: 28 * s });
            if (plantilla === 'resena') {
                B.push({ tipo: 'estrellas', alto: 40 * s, sep: 26 * s });
                if (d.autor) B.push({ tipo: 'autor', alto: 76 * s, sep: 26 * s });
            }
            if (d.boton && plantilla !== 'resena') B.push({ tipo: 'boton', alto: 82 * s, sep: 0 });
            if (B.length) B[B.length - 1].sep = 0;
            return { s: s, bloques: B, total: B.reduce(function (suma, b) { return suma + b.alto + b.sep; }, 0) };
        }
        c.bloques.forEach(function (b) {
            const y = b.y;
            if (b.tipo === 'etiqueta') {
                const texto = String(d.etiqueta).replace(/\*/g, '').toUpperCase();
                ctx.font = fuente('700', 23 * s);
                if ('letterSpacing' in ctx) ctx.letterSpacing = (2 * s).toFixed(1) + 'px';
                const ancho = Math.min(ctx.measureText(texto).width, area.w - 64 * s);
                ctx.fillStyle = T.chip;
                rectRedondo(ctx, x, y, ancho + 64 * s, b.alto, b.alto / 2);
                ctx.fill();
                ctx.fillStyle = T.resalte;
                ctx.beginPath();
                ctx.arc(x + 24 * s, y + b.alto / 2, 6 * s, 0, Math.PI * 2);
                ctx.fill();
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText(texto, x + 42 * s, y + b.alto / 2 + 1 * s, area.w - 64 * s);
                if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
            } else if (b.tipo === 'comilla') {
                ctx.font = '800 ' + Math.round(170 * s) + 'px Georgia, "Times New Roman", serif';
                ctx.fillStyle = T.resalte;
                ctx.textAlign = 'left';
                ctx.textBaseline = 'top';
                ctx.fillText('“', x - 8 * s, y - 22 * s);
            } else if (b.tipo === 'titulo') {
                pintarLineas(ctx, b.t, x, y, T.texto, T.resalte, 1.12);
            } else if (b.tipo === 'texto') {
                pintarLineas(ctx, b.t, x, y, plantilla === 'resena' ? T.texto : T.suave, T.resalte, 1.42);
            } else if (b.tipo === 'punto') {
                ctx.fillStyle = T.acento;
                ctx.beginPath();
                ctx.arc(x + 19 * s, y + 19 * s, 18 * s, 0, Math.PI * 2);
                ctx.fill();
                if (b.letra) {
                    ctx.fillStyle = T.sobreAcento;
                    ctx.font = fuente('800', 21 * s);
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(b.letra, x + 19 * s, y + 20 * s);
                } else {
                    ctx.strokeStyle = T.sobreAcento;
                    ctx.lineWidth = 4.5 * s;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.beginPath();
                    ctx.moveTo(x + 11 * s, y + 19 * s);
                    ctx.lineTo(x + 17 * s, y + 25 * s);
                    ctx.lineTo(x + 28 * s, y + 13 * s);
                    ctx.stroke();
                }
                pintarLineas(ctx, b.t, x + 56 * s, y + (38 * s - b.t.px * 1.08) / 2, T.texto, T.resalte, 1.3);
            } else if (b.tipo === 'precio') {
                const precio = String(d.precio).replace(/\*/g, '');
                ctx.font = fuente('800', 72 * s);
                ctx.fillStyle = T.texto;
                ctx.textAlign = 'left';
                ctx.textBaseline = 'top';
                ctx.fillText(precio, x, y);
                const anchoPrecio = ctx.measureText(precio).width;
                if (d.precioAntes) {
                    const antes = String(d.precioAntes).replace(/\*/g, '');
                    ctx.font = fuente('500', 32 * s);
                    ctx.fillStyle = T.suave;
                    ctx.fillText(antes, x + anchoPrecio + 26 * s, y + 30 * s);
                    ctx.strokeStyle = T.suave;
                    ctx.lineWidth = 3 * s;
                    ctx.beginPath();
                    ctx.moveTo(x + anchoPrecio + 22 * s, y + 46 * s);
                    ctx.lineTo(x + anchoPrecio + 30 * s + ctx.measureText(antes).width, y + 46 * s);
                    ctx.stroke();
                }
            } else if (b.tipo === 'estrellas') {
                ctx.fillStyle = '#FBBF24';
                for (let i = 0; i < 5; i++) { estrella(ctx, x + 20 * s + i * 48 * s, y + 20 * s, 20 * s); ctx.fill(); }
            } else if (b.tipo === 'autor') {
                const inicial = String(d.autor).trim().charAt(0).toUpperCase();
                ctx.fillStyle = T.acento;
                ctx.beginPath();
                ctx.arc(x + 36 * s, y + 38 * s, 36 * s, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = T.sobreAcento;
                ctx.font = fuente('800', 34 * s);
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(inicial, x + 36 * s, y + 40 * s);
                ctx.textAlign = 'left';
                ctx.textBaseline = 'top';
                ctx.fillStyle = T.texto;
                ctx.font = fuente('700', 33 * s);
                ctx.fillText(String(d.autor), x + 92 * s, y + (d.cargo ? 2 : 20) * s, area.w - 92 * s);
                if (d.cargo) {
                    ctx.fillStyle = T.suave;
                    ctx.font = fuente('400', 26 * s);
                    ctx.fillText(String(d.cargo), x + 92 * s, y + 44 * s, area.w - 92 * s);
                }
            } else if (b.tipo === 'boton') {
                const texto = String(d.boton).replace(/\*/g, '') + '  →';
                ctx.font = fuente('700', 31 * s);
                const ancho = Math.min(ctx.measureText(texto).width + 84 * s, area.w);
                ctx.save();
                ctx.shadowColor = 'rgba(0,0,0,.22)';
                ctx.shadowBlur = 22 * s;
                ctx.shadowOffsetY = 8 * s;
                ctx.fillStyle = T.acento;
                rectRedondo(ctx, x, y, ancho, b.alto, b.alto / 2);
                ctx.fill();
                ctx.restore();
                ctx.fillStyle = T.sobreAcento;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(texto, x + ancho / 2, y + b.alto / 2 + 1 * s, ancho - 40 * s);
            }
        });

        // Pie y franja de marca
        if (d.pie) {
            ctx.font = fuente('500', 25 * u);
            ctx.fillStyle = T.suave;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(d.pie).replace(/\*/g, ''), m, pieY, pieAncho);
        }
        ctx.fillStyle = T.acento;
        ctx.fillRect(0, H - barra, W, barra);

        // Plano de lo pintado: el editor libre lo usa para convertir el afiche en elementos sueltos
        return {
            ancho: W, alto: H, tema: T, plantilla: plantilla, u: u, s: s, margen: m, barra: barra, area: area, panel: panel,
            recorte: recorte, razonImagen: razonImagen, adorno: adorno, conAnillos: plantilla !== 'fondo' && !panel,
            sello: { x: sello.x, y: sello.y, ancho: (58 * razonLogo + 44) * u * sello.k, alto: 86 * u * sello.k, k: sello.k },
            bloques: c.bloques, pie: d.pie ? { x: m, y: pieY, ancho: pieAncho, px: 25 * u } : null,
        };
    }

    // ---------------------------------------------------------------- Lo que usa la página

    const turnos = new WeakMap();   // si llegan varios cambios seguidos, solo se pinta el último

    /** Pinta el diseño en el canvas. escala 1 = tamaño real; 0.5 sirve para las miniaturas. */
    function dibujar(canvas, diseno, escala) {
        const d = Object.assign({}, diseno);
        const F = FORMATOS[d.formato] || FORMATOS.cuadrado;
        const k = escala || 1;
        const turno = (turnos.get(canvas) || 0) + 1;
        turnos.set(canvas, turno);
        return Promise.all([cargarImagen(d.imagen), cargarImagen(rutaLogo), esperarFuentes()]).then(function (r) {
            if (turnos.get(canvas) !== turno) return;
            canvas.width = Math.round(F.ancho * k);
            canvas.height = Math.round(F.alto * k);
            const ctx = canvas.getContext('2d');
            ctx.setTransform(k, 0, 0, k, 0, 0);
            pintar(ctx, d, r[0], r[1]);
        });
    }

    /** Devuelve el afiche a tamaño real como archivo PNG (Blob). */
    function exportar(diseno) {
        const canvas = document.createElement('canvas');
        return dibujar(canvas, diseno, 1).then(function () {
            return new Promise(function (listo, fallo) {
                canvas.toBlob(function (blob) { blob ? listo(blob) : fallo(new Error('No se pudo generar la imagen')); }, 'image/png');
            });
        });
    }

    /** Devuelve el plano del afiche (posiciones y medidas de cada parte) junto con la foto y el logo ya cargados. */
    function plano(diseno) {
        const d = Object.assign({}, diseno);
        const F = FORMATOS[d.formato] || FORMATOS.cuadrado;
        return Promise.all([cargarImagen(d.imagen), cargarImagen(rutaLogo), esperarFuentes()]).then(function (r) {
            const canvas = document.createElement('canvas');
            canvas.width = F.ancho;
            canvas.height = F.alto;
            const L = pintar(canvas.getContext('2d'), d, r[0], r[1]);
            L.imagen = r[0];
            L.logo = r[1];
            return L;
        });
    }

    global.Afiches = {
        FORMATOS: FORMATOS, TEMAS: TEMAS, PLANTILLAS: PLANTILLAS,
        dibujar: dibujar, exportar: exportar, plano: plano, colorAlfa: colorAlfa,
        usarLogo: function (ruta) { rutaLogo = ruta; },
    };
})(window);
