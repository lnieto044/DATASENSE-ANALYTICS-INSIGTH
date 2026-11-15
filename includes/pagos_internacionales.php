<?php
/**
 * PAGOS INTERNACIONALES - DATASENSE
 * TRM oficial (COP por USD), datos de cobro de cada método y cliente de la API de PayPal.
 * El valor en USD siempre se calcula en el servidor a partir del saldo en COP de la factura.
 * Claves del .env: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_ENTORNO (sandbox|live), ZELLE_CORREO, ZELLE_TITULAR,
 * WU_BENEFICIARIO, WU_CIUDAD, WU_DOCUMENTO, USDT_BILLETERA_TRC20, TRM_RESPALDO.
 */
require_once __DIR__ . '/contabilidad.php';

// Modo simulación: PAGOS_ENTORNO=LOCAL, o APP_ENV=local si PAGOS_ENTORNO no está definido (igual que el simulador de MercadoPago)
function pagosEnModoLocal() {
    $entorno = strtoupper($_ENV['PAGOS_ENTORNO'] ?? (strtolower($_ENV['APP_ENV'] ?? '') === 'local' ? 'LOCAL' : 'PRODUCCION'));
    return $entorno === 'LOCAL';
}

function guardarTRM(PDO $db, $fecha, $valor, $fuente) {
    $db->prepare("INSERT INTO tasas_cambio (fecha, moneda, valor, fuente) VALUES (?, 'USD', ?, ?)
                  ON DUPLICATE KEY UPDATE valor = VALUES(valor), fuente = VALUES(fuente)")
       ->execute([$fecha, round((float) $valor, 2), mb_substr((string) $fuente, 0, 80)]);
}

/**
 * TRM vigente: la registrada hoy; si no hay, la consulta en los datos abiertos de la Superintendencia Financiera y la guarda;
 * si la fuente no responde, usa la última conocida o TRM_RESPALDO. Devuelve [valor, fecha, fuente, vigente] o null.
 */
function obtenerTRM(PDO $db) {
    $hoy = date('Y-m-d');
    $stmt = $db->prepare("SELECT valor, fecha, fuente FROM tasas_cambio WHERE moneda = 'USD' AND fecha <= ? ORDER BY fecha DESC LIMIT 1");
    $stmt->execute([$hoy]);
    $ultima = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ultima && $ultima['fecha'] === $hoy) {
        return ['valor' => (float) $ultima['valor'], 'fecha' => $hoy, 'fuente' => $ultima['fuente'], 'vigente' => true];
    }

    if (!pagosEnModoLocal()) {
        $consulta = http_build_query(['$where' => "vigenciadesde <= '{$hoy}T00:00:00.000'", '$order' => 'vigenciadesde DESC', '$limit' => 1]);
        $contexto = stream_context_create(['http' => ['timeout' => 4, 'header' => "Accept: application/json\r\n"]]);
        $respuesta = @file_get_contents('https://www.datos.gov.co/resource/32sa-8pi3.json?' . $consulta, false, $contexto);
        $datos = json_decode((string) $respuesta, true);
        $valor = (float) ($datos[0]['valor'] ?? 0);
        if ($valor > 1000 && $valor < 20000) {
            guardarTRM($db, $hoy, $valor, 'Superintendencia Financiera');
            return ['valor' => $valor, 'fecha' => $hoy, 'fuente' => 'Superintendencia Financiera', 'vigente' => true];
        }
    }

    if ($ultima) {
        return ['valor' => (float) $ultima['valor'], 'fecha' => $ultima['fecha'], 'fuente' => $ultima['fuente'], 'vigente' => false];
    }
    $respaldo = (float) ($_ENV['TRM_RESPALDO'] ?? 0);
    return $respaldo > 1000 ? ['valor' => $respaldo, 'fecha' => $hoy, 'fuente' => 'TRM de respaldo (.env)', 'vigente' => false] : null;
}

// USD a cobrar por un valor en COP: se redondea hacia arriba al centavo para que el cobro nunca quede corto
function copAUsd($cop, $trm) {
    return ceil(round((float) $cop / (float) $trm * 100, 6)) / 100;
}

function formatoUsd($usd) {
    return 'US$ ' . number_format((float) $usd, 2, ',', '.');
}

// Datos de cobro de cada método (del .env). En modo local se muestran datos de demostración.
function datosCobroInternacional() {
    $local = pagosEnModoLocal();
    $env = function ($clave, $demo) use ($local) {
        $valor = trim((string) ($_ENV[$clave] ?? ''));
        return $valor !== '' ? $valor : ($local ? $demo : '');
    };
    return [
        'paypal' => [
            'client_id' => trim((string) ($_ENV['PAYPAL_CLIENT_ID'] ?? '')),
            'secret'    => trim((string) ($_ENV['PAYPAL_CLIENT_SECRET'] ?? '')),
            'entorno'   => strtolower((string) ($_ENV['PAYPAL_ENTORNO'] ?? 'sandbox')) === 'live' ? 'live' : 'sandbox',
        ],
        'zelle' => ['correo' => $env('ZELLE_CORREO', 'pagos@datasense.demo'), 'titular' => $env('ZELLE_TITULAR', 'DataSense Analytics S.A.S. (demo)')],
        'western_union' => [
            'beneficiario' => $env('WU_BENEFICIARIO', 'DataSense Analytics S.A.S. (demo)'),
            'ciudad'       => $env('WU_CIUDAD', 'Bogotá, Colombia'),
            'documento'    => $env('WU_DOCUMENTO', '900.000.000-1'),
        ],
        'usdt' => ['billetera' => $env('USDT_BILLETERA_TRC20', 'TDemoDataSenseWalletTRC20xxxxxxxxx'), 'red' => 'TRC20 (Tron)'],
    ];
}

// Qué métodos se pueden ofrecer: PayPal necesita credenciales (salvo en modo local); los manuales, sus datos de cobro
function metodosInternacionalesDisponibles() {
    $d = datosCobroInternacional();
    return [
        'PayPal'               => pagosEnModoLocal() || ($d['paypal']['client_id'] !== '' && $d['paypal']['secret'] !== ''),
        'Zelle'                => $d['zelle']['correo'] !== '',
        'Western Union'        => $d['western_union']['beneficiario'] !== '',
        'Criptomonedas (USDT)' => $d['usdt']['billetera'] !== '',
    ];
}

// Valida y normaliza la referencia que reporta el cliente. Devuelve [referencia, error|null]
function validarReferenciaInternacional($metodo, $referencia) {
    $referencia = trim((string) $referencia);
    switch ($metodo) {
        case 'Criptomonedas (USDT)':
            return preg_match('/^[0-9a-fA-F]{64}$/', $referencia)
                ? ['USDT-' . strtolower($referencia), null]
                : [$referencia, 'El hash de la transacción (TxID) de la red Tron debe tener 64 caracteres hexadecimales.'];
        case 'Western Union':
            $mtcn = preg_replace('/\D/', '', $referencia);
            return strlen($mtcn) === 10 ? ['WU-' . $mtcn, null] : [$referencia, 'El número de control del giro (MTCN) debe tener 10 dígitos.'];
        case 'Zelle':
            return preg_match('/^[A-Za-z0-9-]{6,40}$/', $referencia)
                ? ['ZELLE-' . strtoupper($referencia), null]
                : [$referencia, 'Escribe el código de confirmación de Zelle (de 6 a 40 letras o números).'];
    }
    return [$referencia, 'Método de pago no válido.'];
}

// Pago internacional reportado que sigue esperando validación en la factura (evita reportar dos veces)
function pagoInternacionalPendiente(PDO $db, $factura_id) {
    $stmt = $db->prepare("SELECT p.* FROM pagos_registro p
                          WHERE p.factura_id = ? AND p.estado_transaccion = 'Pendiente_Validacion'
                            AND p.metodo_pago IN ('" . implode("', '", METODOS_REPORTADOS) . "')
                            AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado')
                          ORDER BY p.id DESC LIMIT 1");
    $stmt->execute([(int) $factura_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ================================================================
// API DE PAYPAL (Orders v2)
// ================================================================

function paypalApi($metodo, $ruta, $cuerpo = null, $token = null) {
    $cfg = datosCobroInternacional()['paypal'];
    $base = $cfg['entorno'] === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init($base . $ruta);
    $cabeceras = ['Accept: application/json'];
    if ($token === null) {
        curl_setopt($ch, CURLOPT_USERPWD, $cfg['client_id'] . ':' . $cfg['secret']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    } else {
        $cabeceras[] = 'Authorization: Bearer ' . $token;
        $cabeceras[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo === null ? new stdClass() : $cuerpo));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_TIMEOUT => 20]);
    $respuesta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $datos = json_decode((string) $respuesta, true);
    if ($codigo < 200 || $codigo >= 300 || !is_array($datos)) {
        throw new RuntimeException("PayPal respondió HTTP $codigo en $ruta");
    }
    return $datos;
}

function paypalCrearOrden(array $factura, $usd) {
    $token = paypalApi('POST', '/v1/oauth2/token')['access_token'];
    return paypalApi('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'FAC-' . (int) $factura['id'],
            'custom_id'    => (string) (int) $factura['id'],
            'description'  => 'DataSense Analytics - Factura ' . $factura['numero_factura'],
            'amount'       => ['currency_code' => 'USD', 'value' => number_format($usd, 2, '.', '')],
        ]],
    ], $token)['id'];
}

// Captura la orden en PayPal y devuelve [id, usd, comision_usd, factura_id]; falla si no quedó completada en USD
function paypalCapturar($order_id) {
    $token = paypalApi('POST', '/v1/oauth2/token')['access_token'];
    $orden = paypalApi('POST', '/v2/checkout/orders/' . rawurlencode($order_id) . '/capture', null, $token);
    $unidad = $orden['purchase_units'][0] ?? [];
    $captura = $unidad['payments']['captures'][0] ?? null;
    if (($orden['status'] ?? '') !== 'COMPLETED' || !$captura || ($captura['status'] ?? '') !== 'COMPLETED' || ($captura['amount']['currency_code'] ?? '') !== 'USD') {
        throw new RuntimeException('La orden de PayPal no quedó completada.');
    }
    return [
        'id'           => $captura['id'],
        'usd'          => (float) $captura['amount']['value'],
        'comision_usd' => (float) ($captura['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0),
        'factura_id'   => (int) ($captura['custom_id'] ?? $unidad['custom_id'] ?? 0),
    ];
}
