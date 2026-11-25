<?php
/**
 * TÉRMINOS DE USO (página pública)
 * Condiciones para usar el sitio, la tienda y la plataforma de clientes. Las redes sociales piden esta dirección
 * junto con la de privacidad.php para aprobar las apps que publican.
 */
require_once __DIR__ . '/includes/legal_layout.php';
$d = datosLegales();

cabeceraLegal('Términos de uso',
    'Condiciones para usar el sitio web, la tienda en línea y la plataforma de clientes de DataSense Analytics Insights.',
    'terminos');
?>
    <div class="indice mb-4">
        <p class="fw-bold mb-2">Contenido</p>
        <ol>
            <li><a href="#aceptacion">Aceptación</a></li>
            <li><a href="#quienes">Quiénes somos</a></li>
            <li><a href="#servicios">Información sobre servicios</a></li>
            <li><a href="#tienda">Tienda en línea</a></li>
            <li><a href="#cuentas">Cuentas de la plataforma</a></li>
            <li><a href="#uso">Uso permitido</a></li>
            <li><a href="#propiedad">Propiedad intelectual</a></li>
            <li><a href="#terceros">Servicios de terceros</a></li>
            <li><a href="#responsabilidad">Responsabilidad</a></li>
            <li><a href="#datos">Datos personales</a></li>
            <li><a href="#pqrsd">Peticiones, quejas y reclamos</a></li>
            <li><a href="#ley">Ley aplicable</a></li>
            <li><a href="#cambios">Cambios</a></li>
        </ol>
    </div>

    <h2 id="aceptacion">1. Aceptación</h2>
    <p>Al navegar este sitio, comprar en la tienda o usar la plataforma de clientes aceptas estos términos. Si no estás de acuerdo con ellos,
       no uses el sitio.</p>

    <h2 id="quienes">2. Quiénes somos</h2>
    <?php bloqueResponsable($d); ?>

    <h2 id="servicios">3. Información sobre servicios</h2>
    <p>Las descripciones de consultoría, analítica, inteligencia de negocios, automatización y conferencias son informativas. El alcance, el precio y
       los tiempos de cada servicio se acuerdan en una propuesta o contrato escrito, que prevalece sobre lo publicado en el sitio.</p>

    <h2 id="tienda">4. Tienda en línea</h2>
    <ul>
        <li>Los precios, impuestos y condiciones de cada producto se muestran antes de pagar. La compra queda confirmada cuando el pago es aprobado
            por la pasarela.</li>
        <li>Por cada compra emitimos la factura correspondiente.</li>
        <li>Tus derechos como consumidor (información, garantía, derecho de retracto y reversión del pago cuando aplican) se rigen por el
            <strong>Estatuto del Consumidor, Ley 1480 de 2011</strong>. Para ejercerlos, usa el <a href="pqrsd.php">canal de PQRSD</a>.</li>
        <li>Los cupones tienen las condiciones y la vigencia que se indican al publicarlos y no son acumulables salvo que se diga lo contrario.</li>
    </ul>

    <h2 id="cuentas">5. Cuentas de la plataforma de clientes</h2>
    <p>Eres responsable de mantener en reserva tu usuario y tu contraseña y de lo que se haga con tu cuenta. Te recomendamos activar la verificación en
       dos pasos. Si sospechas un uso no autorizado, avísanos de inmediato. Podemos suspender cuentas que incumplan estos términos o que representen
       un riesgo para la seguridad de la plataforma.</p>

    <h2 id="uso">6. Uso permitido</h2>
    <p>No está permitido: intentar acceder a cuentas o datos ajenos; probar vulnerabilidades sin autorización escrita; enviar contenido ilegal,
       ofensivo o con virus; usar el sitio para enviar publicidad no solicitada; ni copiar el sitio de forma automatizada de manera que afecte
       su funcionamiento.</p>

    <h2 id="propiedad">7. Propiedad intelectual</h2>
    <p>La marca DataSense Analytics Insights, los logos, textos, diseños, afiches y el software de la plataforma pertenecen a DataSense o a sus
       titulares y están protegidos por las normas de derechos de autor y propiedad industrial. Puedes compartir los enlaces y las publicaciones
       de nuestras redes; para cualquier otro uso necesitas nuestra autorización escrita. Las fotos de productos de terceros se usan con sus
       licencias respectivas.</p>

    <h2 id="terceros">8. Servicios de terceros</h2>
    <p>El sitio enlaza y se integra con servicios de terceros, como pasarelas de pago y redes sociales (Facebook, Instagram, LinkedIn, TikTok,
       YouTube y WhatsApp). Su uso se rige por las condiciones de cada uno, y no respondemos por su disponibilidad ni por sus contenidos.</p>

    <h2 id="responsabilidad">9. Responsabilidad</h2>
    <p>Trabajamos para que el sitio esté disponible y su información sea correcta, pero puede haber interrupciones por mantenimiento o fallas de
       los proveedores. En la medida que lo permita la ley, no respondemos por daños indirectos derivados del uso del sitio. Nada de lo anterior
       limita los derechos que te reconoce el Estatuto del Consumidor.</p>

    <h2 id="datos">10. Datos personales</h2>
    <p>Tratamos tus datos según nuestra <a href="privacidad.php">Política de privacidad y tratamiento de datos personales</a>.</p>

    <h2 id="pqrsd">11. Peticiones, quejas y reclamos</h2>
    <p>Puedes radicar peticiones, quejas, reclamos, sugerencias y denuncias en el <a href="pqrsd.php">canal de PQRSD</a>, donde recibes un número
       de radicado para consultar el estado. Las respondemos dentro de los términos de la Ley 1755 de 2015.</p>

    <h2 id="ley">12. Ley aplicable</h2>
    <p>Estos términos se rigen por las leyes de la República de Colombia. Cualquier diferencia se intentará resolver primero de forma directa; si no es
       posible, la resolverán las autoridades colombianas competentes.</p>

    <h2 id="cambios">13. Cambios</h2>
    <p>Estos términos rigen desde el <?= LEGAL_VIGENCIA ?>. Podemos actualizarlos; la versión vigente es siempre la publicada en esta página, con su
       fecha.</p>
<?php pieLegal(); ?>
