<?php
/**
 * POLÍTICA DE TRATAMIENTO DE DATOS PERSONALES (página pública)
 * Ley 1581 de 2012 y Decreto 1074 de 2015 (capítulo 25, que recoge el Decreto 1377 de 2013).
 * También explica qué hace la plataforma con los permisos de Facebook, Instagram, LinkedIn y TikTok:
 * esas redes piden esta dirección para aprobar las apps que publican.
 */
require_once __DIR__ . '/includes/legal_layout.php';
$d = datosLegales();

cabeceraLegal('Política de privacidad y tratamiento de datos personales',
    'Cómo DataSense Analytics Insights recolecta, usa, protege y elimina tus datos personales, y cómo ejercer tus derechos según la Ley 1581 de 2012.',
    'privacidad');
?>
    <div class="indice mb-4">
        <p class="fw-bold mb-2">Contenido</p>
        <ol>
            <li><a href="#responsable">Responsable del tratamiento</a></li>
            <li><a href="#marco">Marco legal</a></li>
            <li><a href="#datos">Datos que recolectamos</a></li>
            <li><a href="#finalidades">Para qué los usamos</a></li>
            <li><a href="#redes">Redes sociales conectadas</a></li>
            <li><a href="#sensibles">Datos sensibles y de menores</a></li>
            <li><a href="#terceros">Proveedores y transferencias</a></li>
            <li><a href="#seguridad">Seguridad</a></li>
            <li><a href="#conservacion">Cuánto tiempo los guardamos</a></li>
            <li><a href="#cookies">Cookies</a></li>
            <li><a href="#derechos">Tus derechos</a></li>
            <li><a href="#procedimiento">Cómo ejercerlos</a></li>
            <li><a href="#vigencia">Vigencia y cambios</a></li>
        </ol>
    </div>

    <h2 id="responsable">1. Responsable del tratamiento</h2>
    <p>El responsable de los datos personales que se recolectan en este sitio y en su plataforma de clientes es:</p>
    <?php bloqueResponsable($d); ?>

    <h2 id="marco">2. Marco legal</h2>
    <p>Esta política cumple la <strong>Ley 1581 de 2012</strong> (protección de datos personales), el <strong>Decreto 1074 de 2015</strong>
       (capítulo 25, que recoge el Decreto 1377 de 2013) y demás normas que las modifiquen o complementen. Las solicitudes del canal de PQRSD
       se atienden además según la <strong>Ley 1755 de 2015</strong> (derecho de petición).</p>
    <p>Al entregarnos tus datos en cualquiera de los formularios del sitio nos autorizas a tratarlos con las finalidades de esta política.
       Puedes revocar esa autorización cuando quieras (ver <a href="#derechos">tus derechos</a>).</p>

    <h2 id="datos">3. Datos que recolectamos</h2>
    <ul>
        <li><strong>Formulario de contacto:</strong> nombre, correo, sector, servicio de interés, mensaje y la dirección IP desde la que se envía.</li>
        <li><strong>Canal de PQRSD:</strong> tipo de solicitud, nombre, tipo y número de documento, correo, teléfono, dirección, ciudad,
            medio de respuesta preferido, el contenido de la solicitud y los archivos que adjuntes. Las denuncias pueden radicarse de forma anónima.</li>
        <li><strong>Tienda en línea:</strong> datos de tu cuenta y de facturación (nombre, documento, correo, teléfono y dirección),
            los productos que compras y el estado de los pagos. <strong>No guardamos datos de tarjetas</strong>: los pagos los procesan directamente
            las pasarelas de pago.</li>
        <li><strong>Chat SenseBot:</strong> los mensajes que escribes en el chat del sitio.</li>
        <li><strong>Plataforma de clientes:</strong> datos de tu usuario (nombre, correo, rol), los casos de soporte que abras, sus mensajes y adjuntos,
            tus respuestas a las encuestas de satisfacción y, si la activas, la configuración de la verificación en dos pasos.</li>
        <li><strong>Datos técnicos:</strong> dirección IP, registros de inicio de sesión e intentos fallidos (para proteger las cuentas) y las
            cookies necesarias para mantener la sesión.</li>
    </ul>

    <h2 id="finalidades">4. Para qué los usamos</h2>
    <ul>
        <li>Responder tus mensajes, preparar propuestas y agendar reuniones sobre nuestros servicios.</li>
        <li>Radicar, tramitar y responder las peticiones, quejas, reclamos, sugerencias y denuncias dentro de los términos de ley.</li>
        <li>Procesar compras, emitir facturas y documentos contables, y cumplir obligaciones tributarias.</li>
        <li>Prestar el soporte técnico contratado y medir la calidad del servicio.</li>
        <li>Proteger el sitio y las cuentas contra accesos no autorizados y fraudes.</li>
        <li>Enviarte información comercial de DataSense, solo si nos lo autorizas; puedes pedir que dejemos de hacerlo en cualquier momento.</li>
    </ul>
    <p>No vendemos ni alquilamos tus datos personales.</p>

    <h2 id="redes">5. Redes sociales conectadas a la plataforma</h2>
    <p>DataSense conecta a su plataforma sus <strong>propias</strong> cuentas de empresa en Facebook, Instagram, LinkedIn y TikTok para publicar
       contenido de la marca en las fechas programadas. Con los permisos que esas redes conceden, la plataforma solo:</p>
    <ul>
        <li>lee el nombre y el identificador de las cuentas de DataSense para saber dónde publicar, y</li>
        <li>publica en esas cuentas las imágenes, videos y textos que el equipo de DataSense programa.</li>
    </ul>
    <p>La plataforma <strong>no</strong> lee mensajes, seguidores, contactos ni datos personales de otras personas en esas redes, y no publica en
       cuentas de terceros. Los permisos (tokens de acceso) se guardan cifrados y solo los usa el publicador de la plataforma. Se pueden revocar en
       cualquier momento desde la configuración de cada red; al revocarlos, la plataforma deja de publicar y elimina la conexión.</p>
    <p>Cuando visitas nuestros perfiles en esas redes, el tratamiento de tus datos lo hace cada red según su propia política de privacidad.</p>

    <h2 id="sensibles">6. Datos sensibles y de menores de edad</h2>
    <p>No pedimos datos sensibles (salud, origen étnico, orientación política o religiosa, datos biométricos, entre otros). Si al radicar una
       solicitud decides incluir alguno porque es necesario para atenderla, responder sobre ellos es <strong>facultativo</strong> y los trataremos
       solo para ese fin. Nuestros servicios están dirigidos a empresas y personas mayores de edad; no recolectamos a sabiendas datos de menores.</p>

    <h2 id="terceros">7. Proveedores y transferencias</h2>
    <p>Para operar usamos proveedores tecnológicos que tratan datos por cuenta nuestra (encargados del tratamiento), con quienes exigimos
       confidencialidad y seguridad:</p>
    <ul>
        <li><strong>Alojamiento y base de datos en la nube</strong>, con servidores que pueden estar fuera de Colombia (por ejemplo, en Estados Unidos).</li>
        <li><strong>Pasarelas de pago</strong> (como MercadoPago o PayPal), que reciben directamente los datos del pago.</li>
        <li><strong>Correo electrónico</strong> para enviar respuestas, facturas y notificaciones.</li>
    </ul>
    <p>Al aceptar esta política autorizas la transmisión de tus datos a estos proveedores, incluso fuera del país, con las garantías que exige la ley.
       También entregaremos datos a autoridades cuando una norma o una orden judicial lo exija.</p>

    <h2 id="seguridad">8. Seguridad</h2>
    <p>El sitio funciona con conexión cifrada (HTTPS). Las contraseñas se guardan con cifrado de una sola vía (bcrypt), los formularios tienen
       protección contra envíos falsificados, el acceso a la plataforma se limita por rol y puede protegerse con verificación en dos pasos, y los
       tokens de las redes sociales se guardan cifrados. Ningún sistema es infalible: si detectamos un incidente que afecte tus datos, te avisaremos
       y lo reportaremos a la Superintendencia de Industria y Comercio como manda la ley.</p>

    <h2 id="conservacion">9. Cuánto tiempo guardamos los datos</h2>
    <p>Mientras sean necesarios para la finalidad con la que se recolectaron. Los documentos contables y tributarios se conservan por el tiempo que
       exige la ley (en general, diez años). Las solicitudes de PQRSD se conservan como soporte de la respuesta dada. Cumplido el plazo, los datos se
       eliminan o se anonimizan.</p>

    <h2 id="cookies">10. Cookies</h2>
    <p>Usamos solo las cookies necesarias para que funcionen la sesión, el carrito de compras y la protección de los formularios. No usamos cookies
       de publicidad ni de seguimiento de terceros. Los videos del sitio se reproducen desde YouTube en su modo de privacidad mejorada y solo se
       cargan cuando pulsas reproducir. Si bloqueas las cookies en tu navegador, es posible que no puedas iniciar sesión ni comprar.</p>

    <h2 id="derechos">11. Tus derechos</h2>
    <p>Como titular de los datos tienes derecho a:</p>
    <ul>
        <li>Conocer, actualizar y rectificar tus datos personales.</li>
        <li>Pedir prueba de la autorización que nos diste.</li>
        <li>Ser informado sobre el uso que les hemos dado.</li>
        <li>Revocar la autorización y pedir que eliminemos tus datos, cuando no exista un deber legal o contractual de conservarlos.</li>
        <li>Acceder gratuitamente a tus datos.</li>
        <li>Presentar quejas ante la <strong>Superintendencia de Industria y Comercio</strong> (SIC) por infracciones a la ley, después de haber
            agotado el trámite de consulta o reclamo con nosotros.</li>
    </ul>

    <h2 id="procedimiento">12. Cómo ejercer tus derechos</h2>
    <p>Radica tu solicitud en el <a href="pqrsd.php">canal de PQRSD</a> (elige «Petición» para una consulta o «Reclamo» para pedir corrección,
       actualización o eliminación)<?= $d['correo'] !== '' ? ' o escríbenos a <a href="mailto:' . esc($d['correo']) . '">' . esc($d['correo']) . '</a>' : '' ?>.
       Indica tu nombre, tu documento, lo que solicitas y cómo quieres recibir la respuesta.</p>
    <ul>
        <li><strong>Consultas:</strong> las respondemos en máximo <strong>10 días hábiles</strong>. Si no es posible, te avisamos el motivo y la
            respondemos dentro de los 5 días hábiles siguientes.</li>
        <li><strong>Reclamos:</strong> los resolvemos en máximo <strong>15 días hábiles</strong>. Si no es posible, te avisamos el motivo y lo
            resolvemos dentro de los 8 días hábiles siguientes. Si al reclamo le falta información, te la pediremos dentro de los 5 días hábiles
            siguientes a recibirlo.</li>
    </ul>

    <h2 id="vigencia">13. Vigencia y cambios</h2>
    <p>Esta política rige desde el <?= LEGAL_VIGENCIA ?>. Si la cambiamos de forma importante, lo publicaremos en esta misma página con la nueva fecha
       de vigencia antes de aplicar los cambios.</p>
<?php pieLegal(); ?>
