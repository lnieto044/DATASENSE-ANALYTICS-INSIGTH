<?php
/**
 * UTILIDADES GENERALES - DATASENSE
 */

// URL base del proyecto (con "/" final) para enlaces en correos y en la pasarela de pagos.
// En producción define APP_URL en .env: así un encabezado Host manipulado no puede alterar los enlaces.
function urlBase() {
    if (!empty($_ENV['APP_URL'])) {
        return rtrim($_ENV['APP_URL'], '/') . '/';
    }

    $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $ruta_web = $_SERVER['SCRIPT_NAME'] ?? '/';

    // Ruta del script relativa a la raíz del proyecto (ej: admin/plantillas.php) para descontarla de la URL
    $raiz = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($raiz !== '' && stripos($script, $raiz . '/') === 0) {
        $relativo = substr($script, strlen($raiz) + 1);
        $base = substr($ruta_web, 0, strlen($ruta_web) - strlen($relativo));
    } else {
        $base = rtrim(dirname($ruta_web), '/\\') . '/';
    }

    return $protocolo . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
}

// PHPMailer configurado desde .env (MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_ENCRYPTION) con el remitente
// de la plataforma. MAIL_ENCRYPTION: tls (STARTTLS, 587), ssl (SMTPS, 465) o none (solo pruebas locales).
// Lanza PHPMailer\PHPMailer\Exception si la configuración o el remitente no son válidos.
function nuevoCorreo($nombreRemitente) {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host     = $_ENV['MAIL_HOST'] ?? '';
    $mail->Port     = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $mail->CharSet  = 'UTF-8';
    $mail->Timeout  = 15; // Si el servidor SMTP no responde, no bloquear la página 5 minutos
    $mail->SMTPAuth = ($_ENV['MAIL_USER'] ?? '') !== '';
    $mail->Username = $_ENV['MAIL_USER'] ?? '';
    $mail->Password = $_ENV['MAIL_PASS'] ?? '';

    $cifrado = strtolower(trim($_ENV['MAIL_ENCRYPTION'] ?? 'tls'));
    if ($cifrado === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($cifrado === 'none') {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($_ENV['MAIL_USER'] ?? '', $nombreRemitente);
    return $mail;
}

// Nombre legible de las líneas de servicio que guardan el formulario de contacto y el chatbot (BI, DS, ASP, CGD, DSW, PSI)
function nombreServicio($codigo) {
    $nombres = [
        'BI'  => 'Business Intelligence',
        'DS'  => 'Ciencia de Datos',
        'ASP' => 'Analítica Sector Público',
        'CGD' => 'Gobierno y Calidad de Datos',
        'DSW' => 'Desarrollo de Software (backend / frontend)',
        'PSI' => 'Conferencias de Psicología y Desarrollo Humano',
    ];
    return $nombres[$codigo] ?? (string) $codigo;
}

// Texto UTF-8 en Windows-1252, la codificación de las fuentes estándar de FPDF (reemplaza a utf8_decode, obsoleta en PHP 8.2)
function textoPdf($texto) {
    return mb_convert_encoding((string) $texto, 'Windows-1252', 'UTF-8');
}

// Imagen guardada en la base (binario) como data URI con su tipo real: PNG, JPEG, WEBP...
function imagenDataUri($binario) {
    $info = @getimagesizefromstring((string) $binario);
    return 'data:' . ($info['mime'] ?? 'image/jpeg') . ';base64,' . base64_encode($binario);
}
