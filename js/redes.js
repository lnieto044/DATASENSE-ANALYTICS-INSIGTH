/**
 * REDES SOCIALES - COMPORTAMIENTO DE LA PÁGINA (admin/redes_sociales.php)
 * Publicaciones listas (miniaturas, descargar, copiar, compartir y editar), diseñador de afiches y botones de copiar.
 * El dibujo lo hace js/afiches.js; los datos llegan en window.REDES_DATOS.
 */
(function () {
    'use strict';

    const DATOS = window.REDES_DATOS || { galeria: [], publicaciones: [], pie: '', logo: '../img/logo.png' };
    const A = window.Afiches;
    A.usarLogo(DATOS.logo);

    const $ = function (selector, raiz) { return (raiz || document).querySelector(selector); };
    const $$ = function (selector, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(selector)); };

    // ---------------------------------------------------------------- Utilidades

    let temporizadorAviso = null;
    function aviso(texto) {
        const caja = $('#redesAviso');
        caja.textContent = texto;
        caja.hidden = false;
        clearTimeout(temporizadorAviso);
        temporizadorAviso = setTimeout(function () { caja.hidden = true; }, 2600);
    }

    function copiarTexto(texto) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(texto);
        // Navegadores sin portapapeles moderno (o página sin https)
        return new Promise(function (listo, fallo) {
            const area = document.createElement('textarea');
            area.value = texto;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            const ok = document.execCommand('copy');
            area.remove();
            ok ? listo() : fallo(new Error('No se pudo copiar'));
        });
    }

    function nombreArchivo(d, base) {
        const limpio = String(base || d.titulo || d.autor || 'afiche').replace(/\*/g, '').normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'afiche';
        return 'datasense-' + limpio + '-' + (d.formato || 'cuadrado') + '.png';
    }

    function descargar(d, base) {
        return A.exportar(d).then(function (blob) {
            const enlace = document.createElement('a');
            enlace.href = URL.createObjectURL(blob);
            enlace.download = nombreArchivo(d, base);
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
            setTimeout(function () { URL.revokeObjectURL(enlace.href); }, 4000);
            aviso('Imagen descargada');
        }).catch(function () { aviso('No se pudo generar la imagen. Inténtalo de nuevo.'); });
    }

    // Compartir con el menú del teléfono o del computador (solo donde el navegador deja compartir archivos)
    const puedeCompartir = (function () {
        try {
            const prueba = new File([new Blob(['x'], { type: 'image/png' })], 'prueba.png', { type: 'image/png' });
            return !!(navigator.canShare && navigator.canShare({ files: [prueba] }));
        } catch (err) { return false; }
    })();

    function compartir(d, texto, base) {
        return A.exportar(d).then(function (blob) {
            const archivo = new File([blob], nombreArchivo(d, base), { type: 'image/png' });
            // Varias aplicaciones descartan el texto cuando reciben una imagen: se deja copiado para pegarlo
            const copia = texto ? copiarTexto(texto).catch(function () {}) : Promise.resolve();
            return copia.then(function () { return navigator.share({ files: [archivo], text: texto || '' }); });
        }).then(function () {
            if (texto) aviso('El texto quedó copiado: pégalo en la publicación');
        }).catch(function (err) {
            if (err && err.name !== 'AbortError') aviso('No se pudo compartir desde este navegador. Descarga la imagen.');
        });
    }

    // Botones "Copiar": toman el texto del campo indicado en data-copiar (el usuario puede haberlo cambiado)
    document.addEventListener('click', function (e) {
        const boton = e.target.closest('[data-copiar]');
        if (!boton) return;
        const campo = $(boton.getAttribute('data-copiar'));
        if (!campo) return;
        copiarTexto(campo.value).then(function () {
            const original = boton.innerHTML;
            boton.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Copiado';
            boton.classList.add('copiado');
            setTimeout(function () { boton.innerHTML = original; boton.classList.remove('copiado'); }, 1800);
            aviso('Texto copiado');
        }).catch(function () { campo.focus(); campo.select(); aviso('Selecciona el texto y cópialo con Ctrl + C'); });
    });

    // Contador de caracteres de los mensajes automáticos (Meta recorta los muy largos)
    $$('[data-contar]').forEach(function (campo) {
        const salida = $(campo.getAttribute('data-contar'));
        const contar = function () {
            const n = campo.value.length;
            salida.textContent = n + ' caracteres' + (n > 500 ? ' · conviene acortarlo a 500' : '');
            salida.classList.toggle('largo', n > 500);
        };
        campo.addEventListener('input', contar);
        contar();
    });

    // ---------------------------------------------------------------- Publicaciones listas

    const publicaciones = {};
    DATOS.publicaciones.forEach(function (p) { publicaciones[p.id] = p; });

    // Las miniaturas se pintan a medida que entran en pantalla, para no dibujar catorce afiches de una vez
    const pintarMiniatura = function (canvas) {
        const pub = publicaciones[canvas.getAttribute('data-publicacion')];
        if (pub && !canvas.dataset.pintado) {
            canvas.dataset.pintado = '1';
            A.dibujar(canvas, pub.diseno, 0.5);
        }
    };
    if ('IntersectionObserver' in window) {
        const observador = new IntersectionObserver(function (entradas) {
            entradas.forEach(function (entrada) {
                if (entrada.isIntersecting) { pintarMiniatura(entrada.target); observador.unobserve(entrada.target); }
            });
        }, { rootMargin: '300px' });
        $$('canvas[data-publicacion]').forEach(function (c) { observador.observe(c); });
    } else {
        $$('canvas[data-publicacion]').forEach(pintarMiniatura);
    }

    $$('.pub-tarjeta').forEach(function (tarjeta) {
        const pub = publicaciones[tarjeta.getAttribute('data-id')];
        if (!pub) return;
        const texto = function () { return $('.pub-texto', tarjeta).value; };
        const botonCompartir = $('[data-accion="compartir"]', tarjeta);
        if (puedeCompartir) botonCompartir.hidden = false;
        $('[data-accion="descargar"]', tarjeta).addEventListener('click', function () { descargar(pub.diseno, pub.id); });
        botonCompartir.addEventListener('click', function () { compartir(pub.diseno, texto(), pub.id); });
        $('[data-accion="editar"]', tarjeta).addEventListener('click', function () {
            cargarDiseno(pub.diseno);
            bootstrap.Tab.getOrCreateInstance($('#pestanaDisenador')).show();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    // ---------------------------------------------------------------- Plan de publicación

    // Fechas según los días de la semana y la hora elegidos. Las historias no llevan fecha: en la mayoría de cuentas
    // no se pueden programar. El plan se recuerda en este navegador.
    const PLAN_CLAVE = 'ds_plan_redes_v1';
    const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    const DIAS_CORTOS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    function aTextoFecha(f) { return f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0'); }
    function deTextoFecha(texto) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(texto || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function proximoLunes() {
        const f = new Date();
        f.setHours(0, 0, 0, 0);
        f.setDate(f.getDate() + ((8 - f.getDay()) % 7 || 7));
        return f;
    }
    function hora12(hhmm) {
        const partes = String(hhmm || '09:00').split(':');
        const h = +partes[0] || 0;
        return ((h % 12) || 12) + ':' + (partes[1] || '00') + (h < 12 ? ' a. m.' : ' p. m.');
    }
    function fechaLarga(f) { return DIAS[f.getDay()] + ' ' + f.getDate() + ' de ' + MESES[f.getMonth()]; }
    function mayuscula(texto) { return texto.charAt(0).toUpperCase() + texto.slice(1); }

    const plan = (function () {
        let guardado = null;
        try { guardado = JSON.parse(localStorage.getItem(PLAN_CLAVE) || 'null'); } catch (err) { guardado = null; }
        const inicio = guardado && deTextoFecha(guardado.inicio);
        const hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        return {
            // Un plan viejo (con fecha ya pasada) arranca de nuevo el próximo lunes
            inicio: inicio && inicio >= hoy ? guardado.inicio : aTextoFecha(proximoLunes()),
            dias: guardado && Array.isArray(guardado.dias) && guardado.dias.length ? guardado.dias : [1, 3, 5],
            hora: guardado && /^\d{2}:\d{2}$/.test(guardado.hora || '') ? guardado.hora : '09:00',
        };
    })();

    // Publicaciones del feed en orden, cada una con su fecha. Los festivos de Colombia se saltan (quedan en 'saltados').
    const FESTIVOS = DATOS.festivos || {};
    let saltados = [];
    function calendario() {
        const salida = [];
        saltados = [];
        const f = deTextoFecha(plan.inicio) || proximoLunes();
        const pendientes = DATOS.publicaciones.filter(function (p) { return !p.historia; });
        for (let vueltas = 0; pendientes.length && vueltas < 400; vueltas++) {
            if (plan.dias.indexOf(f.getDay()) !== -1) {
                if (FESTIVOS[aTextoFecha(f)]) saltados.push(f.getDate() + ' de ' + MESES[f.getMonth()] + ' (' + FESTIVOS[aTextoFecha(f)] + ')');
                else salida.push({ pub: pendientes.shift(), fecha: new Date(f) });
            }
            f.setDate(f.getDate() + 1);
        }
        return salida;
    }

    function pintarPlan() {
        try { localStorage.setItem(PLAN_CLAVE, JSON.stringify(plan)); } catch (err) { /* sin almacenamiento */ }
        $('#planInicio').value = plan.inicio;
        $('#planHora').value = plan.hora;
        $$('#planDias [data-dia]').forEach(function (b) {
            const activo = plan.dias.indexOf(+b.getAttribute('data-dia')) !== -1;
            b.classList.toggle('activo', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
        const fechas = calendario();
        fechas.forEach(function (item) {
            const caja = $('.pub-tarjeta[data-id="' + item.pub.id + '"] [data-fecha]');
            if (caja) caja.textContent = DIAS_CORTOS[item.fecha.getDay()] + ' ' + item.fecha.getDate() + ' ' + MESES[item.fecha.getMonth()].slice(0, 3) + ' · ' + hora12(plan.hora);
        });
        const historias = DATOS.publicaciones.length - fechas.length;
        const dias = plan.dias.slice().sort(function (a, b) { return ((a + 6) % 7) - ((b + 6) % 7); }).map(function (d) { return DIAS[d]; });
        $('#planResumen').innerHTML = fechas.length
            ? '<b>' + fechas.length + ' publicaciones</b>, ' + (dias.length > 1 ? 'los ' + dias.slice(0, -1).join(', ') + ' y ' + dias[dias.length - 1] : 'los ' + dias[0])
              + ' a las ' + hora12(plan.hora) + ': del ' + fechaLarga(fechas[0].fecha) + ' al ' + fechaLarga(fechas[fechas.length - 1].fecha) + '.'
              + (historias ? ' Además, ' + historias + (historias === 1 ? ' historia' : ' historias') + ' para subir cuando quieras.' : '')
              + (saltados.length ? ' <span class="plan-festivos">No se publica en festivos: ' + saltados.join(', ') + '.</span>' : '')
            : '';
    }

    $('#planInicio').addEventListener('change', function () {
        if (deTextoFecha(this.value)) { plan.inicio = this.value; pintarPlan(); }
    });
    $('#planHora').addEventListener('change', function () {
        if (/^\d{2}:\d{2}$/.test(this.value)) { plan.hora = this.value; pintarPlan(); }
    });
    $('#planDias').addEventListener('click', function (e) {
        const boton = e.target.closest('[data-dia]');
        if (!boton) return;
        const dia = +boton.getAttribute('data-dia');
        const i = plan.dias.indexOf(dia);
        if (i !== -1 && plan.dias.length === 1) { aviso('Deja al menos un día de publicación'); return; }
        if (i !== -1) plan.dias.splice(i, 1); else plan.dias.push(dia);
        pintarPlan();
    });

    // Todo el plan en un .zip: imágenes numeradas por fecha y un documento con la fecha, las redes y el texto de cada una
    $('#planDescargar').addEventListener('click', function () {
        const boton = this;
        if (!window.JSZip) { aviso('No se pudo cargar el compresor. Revisa la conexión y recarga la página.'); return; }
        const original = boton.innerHTML;
        boton.disabled = true;
        const zip = new JSZip();
        const fechas = calendario();
        const historias = DATOS.publicaciones.filter(function (p) { return p.historia; });
        const total = fechas.length + historias.length;
        const texto = function (pub) { const campo = $('#pubTexto-' + pub.id); return campo ? campo.value : pub.texto; };
        const lineas = ['PLAN DE PUBLICACIONES · DataSense Analytics Insights', '',
            'Cada imagen tiene su número. Prográmala en el Planificador de Meta (Facebook e Instagram) y en LinkedIn',
            'con la fecha y la hora que aparecen aquí, y pega el texto que va debajo.', ''];
        let hechas = 0;
        const avanzar = function () { hechas++; boton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Preparando ' + hechas + ' de ' + total + '…'; };
        boton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Preparando…';

        // Una por una: así el navegador no tiene que dibujar todas a la vez
        let cadena = Promise.resolve();
        fechas.forEach(function (item, i) {
            cadena = cadena.then(function () {
                return A.exportar(item.pub.diseno).then(function (blob) {
                    const nombre = String(i + 1).padStart(2, '0') + '-' + aTextoFecha(item.fecha) + '-' + DIAS[item.fecha.getDay()].normalize('NFD').replace(/[̀-ͯ]/g, '') + '-' + item.pub.id + '.png';
                    zip.file(nombre, blob);
                    lineas.push('==================================================',
                        (i + 1) + '. ' + mayuscula(fechaLarga(item.fecha)) + ' de ' + item.fecha.getFullYear() + ', ' + hora12(plan.hora),
                        'Redes: ' + item.pub.redes.join(', '), 'Imagen: ' + nombre, '', texto(item.pub), '');
                    avanzar();
                });
            });
        });
        historias.forEach(function (pub, i) {
            cadena = cadena.then(function () {
                return A.exportar(pub.diseno).then(function (blob) {
                    const nombre = 'historia-' + (i + 1) + '-' + pub.id + '.png';
                    zip.file(nombre, blob);
                    lineas.push('==================================================', 'HISTORIA ' + (i + 1) + ' · súbela el día que quieras',
                        'Redes: ' + pub.redes.join(', '), 'Imagen: ' + nombre, '', texto(pub), '');
                    avanzar();
                });
            });
        });
        cadena.then(function () {
            // Saltos de línea de Windows para que el Bloc de notas lo muestre bien
            zip.file('LEEME-plan-de-publicaciones.txt', '﻿' + lineas.join('\r\n'));
            return zip.generateAsync({ type: 'blob' });
        }).then(function (contenido) {
            const enlace = document.createElement('a');
            enlace.href = URL.createObjectURL(contenido);
            enlace.download = 'datasense-plan-' + plan.inicio + '.zip';
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
            setTimeout(function () { URL.revokeObjectURL(enlace.href); }, 6000);
            aviso('Plan descargado: ' + total + ' imágenes y el documento con fechas y textos');
        }).catch(function () {
            aviso('No se pudo preparar el plan. Inténtalo de nuevo.');
        }).then(function () {
            boton.disabled = false;
            boton.innerHTML = original;
        });
    });

    pintarPlan();

    // ---------------------------------------------------------------- Diseñador

    const CLAVE = 'ds_afiche_v1';
    const BASE = {
        plantilla: 'texto', formato: 'cuadrado', tema: 'azul',
        etiqueta: 'DataSense Analytics Insights',
        titulo: 'Transformamos datos en *decisiones inteligentes*',
        texto: 'Analítica, ciencia de datos y software a la medida para organizaciones públicas y privadas.',
        puntos: ['Tableros e indicadores', 'Software a la medida', 'Conferencias corporativas'],
        boton: 'Solicita tu asesoría gratuita', pie: DATOS.pie,
        precio: '', precioAntes: '', insignia: '', autor: '', cargo: '', imagen: '', imagenRecorte: 0,
    };
    const CAMPOS = { etiqueta: 'afEtiqueta', titulo: 'afTitulo', texto: 'afTexto', boton: 'afBoton', pie: 'afPie',
                     precio: 'afPrecio', precioAntes: 'afPrecioAntes', insignia: 'afInsignia', autor: 'afAutor', cargo: 'afCargo' };

    let diseno = Object.assign({}, BASE);

    // Las fotos de la tienda traen su rótulo y su logo en la franja de abajo: en el afiche esa franja se descarta
    function ponerFoto(url) {
        const foto = DATOS.galeria.filter(function (f) { return f.url === url; })[0];
        diseno.imagen = url;
        diseno.imagenRecorte = foto && foto.recorte ? foto.recorte : 0;
    }
    let fotoSubida = '';   // dirección temporal (blob:) de la foto que subió el usuario

    const lienzo = $('#afLienzo');
    const galeria = $('#afGaleria');

    function guardar() {
        try {
            const copia = Object.assign({}, diseno);
            if (/^blob:/.test(copia.imagen)) copia.imagen = '';   // la foto subida no sobrevive a recargar la página
            localStorage.setItem(CLAVE, JSON.stringify(copia));
        } catch (err) { /* navegación privada o almacenamiento lleno */ }
    }

    function refrescar() {
        const F = A.FORMATOS[diseno.formato];
        $('#afMedidas').textContent = F.ancho + ' × ' + F.alto + ' px';
        lienzo.classList.toggle('alto', F.alto > F.ancho);
        A.dibujar(lienzo, diseno, 1);
        guardar();
    }

    function mostrarCampos() {
        const usados = A.PLANTILLAS[diseno.plantilla].campos;
        $$('.af-campo').forEach(function (caja) {
            caja.hidden = usados.indexOf(caja.getAttribute('data-campo')) === -1;
        });
        $('label[for="afTexto"]').textContent = diseno.plantilla === 'resena' ? 'Lo que dijo el cliente' : 'Texto';
        $$('.af-plantilla').forEach(function (b) {
            const activa = b.getAttribute('data-plantilla') === diseno.plantilla;
            b.classList.toggle('activa', activa);
            b.setAttribute('aria-pressed', activa ? 'true' : 'false');
        });
        $$('.af-tema').forEach(function (b) {
            const activo = b.getAttribute('data-tema') === diseno.tema;
            b.classList.toggle('activa', activo);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
    }

    function llenarFormulario() {
        Object.keys(CAMPOS).forEach(function (campo) { $('#' + CAMPOS[campo]).value = diseno[campo] || ''; });
        $('#afPuntos').value = (diseno.puntos || []).join('\n');
        $('#afFormato').value = diseno.formato;
        const enGaleria = $$('option', galeria).some(function (o) { return o.value === diseno.imagen; });
        galeria.value = enGaleria ? diseno.imagen : (diseno.imagen && diseno.imagen === fotoSubida ? '__subida' : '');
        mostrarCampos();
    }

    function cargarDiseno(nuevo) {
        diseno = Object.assign({}, BASE, { puntos: [], imagen: '', imagenRecorte: 0 }, nuevo);
        diseno.puntos = (diseno.puntos || []).slice();
        if (!A.PLANTILLAS[diseno.plantilla]) diseno.plantilla = 'texto';
        if (!A.FORMATOS[diseno.formato]) diseno.formato = 'cuadrado';
        if (!A.TEMAS[diseno.tema]) diseno.tema = 'azul';
        llenarFormulario();
        refrescar();
    }

    // Plantillas, tamaños y colores
    $('#afPlantillas').innerHTML = Object.keys(A.PLANTILLAS).map(function (clave) {
        const p = A.PLANTILLAS[clave];
        return '<button type="button" class="af-plantilla" data-plantilla="' + clave + '"><i class="fas ' + p.icono + '" aria-hidden="true"></i><span>' + p.nombre + '</span></button>';
    }).join('');
    $('#afFormato').innerHTML = Object.keys(A.FORMATOS).map(function (clave) {
        const f = A.FORMATOS[clave];
        return '<option value="' + clave + '">' + f.nombre + ' · ' + f.detalle + '</option>';
    }).join('');
    $('#afTemas').innerHTML = Object.keys(A.TEMAS).map(function (clave) {
        const t = A.TEMAS[clave];
        return '<button type="button" class="af-tema" data-tema="' + clave + '" title="' + t.nombre + '" aria-label="' + t.nombre + '"'
             + ' style="--tema-a: ' + t.fondo[0] + '; --tema-b: ' + t.fondo[1] + '; --tema-c: ' + t.acento + '"></button>';
    }).join('');

    // Galería de fotos del sitio, por grupos
    (function () {
        galeria.appendChild(new Option('Sin foto', ''));
        const grupos = {};
        DATOS.galeria.forEach(function (foto) {
            if (!grupos[foto.grupo]) {
                grupos[foto.grupo] = document.createElement('optgroup');
                grupos[foto.grupo].label = foto.grupo;
                galeria.appendChild(grupos[foto.grupo]);
            }
            grupos[foto.grupo].appendChild(new Option(foto.nombre, foto.url));
        });
    })();

    $('#afPlantillas').addEventListener('click', function (e) {
        const boton = e.target.closest('.af-plantilla');
        if (!boton) return;
        diseno.plantilla = boton.getAttribute('data-plantilla');
        // Una plantilla con foto no debe quedar vacía: se propone la primera de la galería
        const pideFoto = ['foto', 'fondo', 'oferta'].indexOf(diseno.plantilla) !== -1;
        if (pideFoto && !diseno.imagen && DATOS.galeria.length) {
            const tienda = DATOS.galeria.filter(function (f) { return f.grupo === 'Tienda'; })[0];
            ponerFoto((diseno.plantilla === 'oferta' && tienda ? tienda : DATOS.galeria[0]).url);
        }
        llenarFormulario();
        refrescar();
    });
    $('#afTemas').addEventListener('click', function (e) {
        const boton = e.target.closest('.af-tema');
        if (!boton) return;
        diseno.tema = boton.getAttribute('data-tema');
        mostrarCampos();
        refrescar();
    });
    $('#afFormato').addEventListener('change', function () { diseno.formato = this.value; refrescar(); });

    Object.keys(CAMPOS).forEach(function (campo) {
        $('#' + CAMPOS[campo]).addEventListener('input', function () { diseno[campo] = this.value; refrescar(); });
    });
    $('#afPuntos').addEventListener('input', function () {
        diseno.puntos = this.value.split('\n').map(function (p) { return p.trim(); }).filter(Boolean).slice(0, 5);
        refrescar();
    });

    galeria.addEventListener('change', function () {
        ponerFoto(this.value === '__subida' ? fotoSubida : this.value);
        refrescar();
    });
    $('#afSubir').addEventListener('change', function () {
        const archivo = this.files && this.files[0];
        this.value = '';
        if (!archivo) return;
        if (!/^image\/(png|jpeg|webp)$/.test(archivo.type)) { aviso('Sube una imagen JPG, PNG o WebP'); return; }
        if (archivo.size > 12 * 1024 * 1024) { aviso('La foto pesa más de 12 MB. Usa una más liviana.'); return; }
        if (fotoSubida) URL.revokeObjectURL(fotoSubida);
        fotoSubida = URL.createObjectURL(archivo);
        let opcion = $('option[value="__subida"]', galeria);
        if (!opcion) {
            opcion = new Option('Tu foto', '__subida');
            galeria.insertBefore(opcion, galeria.children[1] || null);   // justo después de "Sin foto"
        }
        opcion.textContent = 'Tu foto: ' + archivo.name.slice(0, 28);
        galeria.value = '__subida';
        ponerFoto(fotoSubida);
        refrescar();
    });

    $('#afDescargar').addEventListener('click', function () { descargar(diseno); });
    if (puedeCompartir) {
        $('#afCompartir').hidden = false;
        $('#afCompartir').addEventListener('click', function () { compartir(diseno, ''); });
    }
    if (navigator.clipboard && window.ClipboardItem && window.isSecureContext) {
        $('#afCopiarImagen').hidden = false;
        $('#afCopiarImagen').addEventListener('click', function () {
            // El portapapeles exige recibir la promesa de la imagen dentro del mismo clic
            navigator.clipboard.write([new ClipboardItem({ 'image/png': A.exportar(diseno) })])
                .then(function () { aviso('Imagen copiada: pégala en la publicación con Ctrl + V'); })
                .catch(function () { aviso('Este navegador no deja copiar la imagen. Descárgala.'); });
        });
    }
    $('#afReiniciar').addEventListener('click', function () { cargarDiseno(BASE); aviso('Afiche reiniciado'); });

    // Editor libre: recibe el afiche tal como está y lo convierte en elementos que se mueven a mano.
    // La foto subida vive solo en esta página, así que viaja dentro del propio diseño (reducida).
    $('#afEditorLibre').addEventListener('click', function () {
        const envio = Object.assign({}, diseno);
        const salir = function () {
            try { sessionStorage.setItem('ds_editor_entrada', JSON.stringify(envio)); } catch (err) { /* sin espacio: el editor abre su plantilla inicial */ }
            window.location.href = 'editor_afiches.php';
        };
        if (!/^blob:/.test(envio.imagen)) { salir(); return; }
        const img = new Image();
        img.onload = function () {
            const k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
            const c = document.createElement('canvas');
            c.width = Math.round(img.naturalWidth * k);
            c.height = Math.round(img.naturalHeight * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            envio.imagen = c.toDataURL('image/jpeg', 0.88);
            salir();
        };
        img.onerror = function () { envio.imagen = ''; salir(); };
        img.src = envio.imagen;
    });

    // Arranque: se retoma el último afiche que la persona estaba armando en este navegador
    (function () {
        let guardado = null;
        try { guardado = JSON.parse(localStorage.getItem(CLAVE) || 'null'); } catch (err) { guardado = null; }
        cargarDiseno(guardado && typeof guardado === 'object' ? guardado : BASE);
    })();

    // Enlace directo a una pestaña: redes_sociales.php#disenador o #respuestas
    const pestanas = { '#disenador': '#pestanaDisenador', '#respuestas': '#pestanaRespuestas' };
    if (pestanas[location.hash]) bootstrap.Tab.getOrCreateInstance($(pestanas[location.hash])).show();
})();
