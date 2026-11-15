<?php
/**
 * CONTABILIDAD - DATASENSE
 * Reglas compartidas por las facturas de venta, los comprobantes de ingreso (CI) y de egreso (CE), las notas contables (NC) y los libros:
 * - Consecutivos sin duplicados aunque dos personas guarden al mismo tiempo.
 * - Partida doble validada en el servidor: débitos = créditos; en CI y CE el valor coincide con lo que entra o sale de caja/bancos.
 * - El estado de una factura se deriva de lo recaudado con comprobantes de ingreso aprobados.
 * - Segregación de funciones: quien prepara no revisa y quien revisa no aprueba.
 * No usa la sesión: también lo cargan el webhook de la pasarela y los scripts de migración.
 */

// Cuentas PUC que usa la causación automática
const CTA_CAJA               = '110505';
const CTA_BANCOS             = '111005';
const CTA_CLIENTES           = '130505';
const CTA_ANTICIPO_RETENCION = '135515';
const CTA_IVA_GENERADO       = '240805';
const CTA_IVA_DESCONTABLE    = '240810';
const CTA_INGRESO_SERVICIOS  = '415540';

// Disponible en el exterior y gasto financiero de las pasarelas
const CTA_BANCOS_EXTERIOR = '111015';
const CTA_PAYPAL          = '111020';
const CTA_CRIPTOACTIVOS   = '111025';
const CTA_COMISIONES      = '530515';
const CTA_DIFERENCIA_CAMBIO = '421020';

// Cartera de proveedores, notas de las facturas de venta y cierre del ejercicio
const CTA_PROVEEDORES           = '220505';
const CTA_DEVOLUCIONES_VENTAS   = '417505';
const CTA_INTERESES             = '421005';
const CTA_UTILIDAD_EJERCICIO    = '360505';
const CTA_UTILIDADES_ACUMULADAS = '370505';

// Formas de pago de los comprobantes y la cuenta de disponible que mueven
const METODOS_PAGO = [
    'Transferencia'        => CTA_BANCOS,
    'Consignación'         => CTA_BANCOS,
    'PSE'                  => CTA_BANCOS,
    'Tarjeta'              => CTA_BANCOS,
    'Cheque'               => CTA_BANCOS,
    'Efectivo (Caja)'      => CTA_CAJA,
    'PayPal'               => CTA_PAYPAL,
    'Zelle'                => CTA_BANCOS_EXTERIOR,
    'Western Union'        => CTA_CAJA,          // El giro se cobra en efectivo en la agencia
    'Criptomonedas (USDT)' => CTA_CRIPTOACTIVOS,
];

// Formas de pago que usan los clientes del exterior (en USD, convertidos con la TRM)
const METODOS_INTERNACIONALES = ['PayPal', 'Zelle', 'Western Union', 'Criptomonedas (USDT)'];
// Las que el cliente reporta con comprobante y Finanzas valida (PayPal se confirma solo)
const METODOS_REPORTADOS = ['Zelle', 'Western Union', 'Criptomonedas (USDT)'];

// Retención en la fuente que se practica al pagar un egreso: porcentaje => [cuenta por pagar, descripción]
const RETENCIONES_EGRESO = [
    '0'   => [null, 'Sin retención'],
    '1'   => ['236540', 'Compras 1%'],
    '2.5' => ['236540', 'Compras 2,5%'],
    '3.5' => ['236530', 'Arrendamientos 3,5%'],
    '4'   => ['236525', 'Servicios 4%'],
    '6'   => ['236525', 'Servicios 6%'],
    '10'  => ['236515', 'Honorarios 10%'],
    '11'  => ['236515', 'Honorarios 11%'],
];

const TARIFAS_IVA = ['0', '5', '19'];

const TIPOS_COMPROBANTE = [
    'CI' => ['nombre' => 'Comprobante de Ingreso', 'plural' => 'Comprobantes de Ingreso', 'modulo' => 'comprobante_ingreso', 'pagina' => 'comprobante_ingreso.php', 'nuevo' => 'nuevo_ingreso.php'],
    'CE' => ['nombre' => 'Comprobante de Egreso',  'plural' => 'Comprobantes de Egreso',  'modulo' => 'comprobante_egreso',  'pagina' => 'comprobante_egreso.php',  'nuevo' => 'nuevo_egreso.php'],
    'NC' => ['nombre' => 'Nota Contable',          'plural' => 'Notas Contables',         'modulo' => 'nota_contable',       'pagina' => 'nota_contable.php',       'nuevo' => 'nueva_nota_contable.php'],
    'NCV' => ['nombre' => 'Nota Crédito',          'plural' => 'Notas Crédito',           'modulo' => 'notas_factura',       'pagina' => 'nota_credito.php',        'nuevo' => 'nueva_nota_factura.php?tipo=NCV'],
    'NDV' => ['nombre' => 'Nota Débito',           'plural' => 'Notas Débito',            'modulo' => 'notas_factura',       'pagina' => 'nota_debito.php',         'nuevo' => 'nueva_nota_factura.php?tipo=NDV'],
    'FP' => ['nombre' => 'Cuenta por Pagar',       'plural' => 'Cuentas por Pagar',       'modulo' => 'cuentas_por_pagar',   'pagina' => 'cuentas_por_pagar.php',   'nuevo' => 'nueva_cuenta_por_pagar.php'],
    'CC' => ['nombre' => 'Comprobante de Cierre',  'plural' => 'Comprobantes de Cierre',  'modulo' => 'cierre_contable',     'pagina' => 'comprobante_cierre.php',  'nuevo' => null],
];

// Estado de pago de las cuentas por pagar => clase visual
const CLASES_ESTADO_PAGO = ['Pagada' => 'estado-Aprobado', 'Pago en trámite' => 'estado-Revisado', 'Pagada parcialmente' => 'estado-Pendiente',
                            'Por pagar' => 'estado-Pendiente', 'Vencida' => 'estado-vencida', 'Sin aprobar' => 'estado-Anulado', 'Anulada' => 'estado-Anulado'];

// Clases de nota contable: registros que no son recaudos ni pagos
const CLASES_NOTA = ['Ajuste contable', 'Depreciación y amortización', 'Provisión', 'Causación de nómina', 'Gastos bancarios y GMF',
                     'Diferencia en cambio', 'Reclasificación', 'Corrección de error', 'Otra'];
const MAX_LINEAS_NOTA = 30;

// Motivos de las notas crédito y débito de las facturas de venta (conceptos de corrección de la DIAN)
const MOTIVOS_NOTA_CREDITO = ['Devolución parcial', 'Anulación total de la factura', 'Rebaja o descuento', 'Ajuste de precio', 'Otro'];
const MOTIVOS_NOTA_DEBITO  = ['Intereses de mora', 'Gastos por cobrar', 'Ajuste de precio', 'Otro'];

const ESTADOS_COMPROBANTE = ['Pendiente', 'Revisado', 'Aprobado', 'Anulado'];

// Clases (primer dígito) y grupos (dos dígitos) del PUC para los estados financieros
const CLASES_PUC = ['1' => 'Activo', '2' => 'Pasivo', '3' => 'Patrimonio', '4' => 'Ingresos', '5' => 'Gastos', '6' => 'Costos de ventas', '7' => 'Costos de producción'];
const GRUPOS_PUC = [
    '11' => 'Disponible', '12' => 'Inversiones', '13' => 'Deudores', '14' => 'Inventarios', '15' => 'Propiedades, planta y equipo',
    '16' => 'Intangibles', '17' => 'Diferidos', '18' => 'Otros activos', '19' => 'Valorizaciones',
    '21' => 'Obligaciones financieras', '22' => 'Proveedores', '23' => 'Cuentas por pagar', '24' => 'Impuestos, gravámenes y tasas',
    '25' => 'Obligaciones laborales', '26' => 'Pasivos estimados y provisiones', '27' => 'Diferidos', '28' => 'Otros pasivos', '29' => 'Bonos y papeles comerciales',
    '31' => 'Capital social', '32' => 'Superávit de capital', '33' => 'Reservas', '34' => 'Revalorización del patrimonio', '35' => 'Dividendos decretados',
    '36' => 'Resultados del ejercicio', '37' => 'Resultados de ejercicios anteriores', '38' => 'Superávit por valorizaciones',
    '41' => 'Ingresos operacionales', '42' => 'Ingresos no operacionales',
    '51' => 'Gastos operacionales de administración', '52' => 'Gastos operacionales de ventas', '53' => 'Gastos no operacionales', '54' => 'Impuesto de renta y complementarios',
    '61' => 'Costo de ventas y de prestación de servicios',
];

// ================================================================
// UTILIDADES
// ================================================================

function dinero($valor, $decimales = 0) {
    return '$ ' . number_format((float) $valor, $decimales, ',', '.');
}

function fechaValida($fecha) {
    if (!is_string($fecha)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Cuenta de caja o bancos que corresponde a la forma de pago (acepta también los métodos antiguos de conciliación)
function cuentaDisponible($metodo) {
    if (isset(METODOS_PAGO[$metodo])) return METODOS_PAGO[$metodo];
    return (stripos((string) $metodo, 'efectivo') === 0 && stripos((string) $metodo, 'banco') === false) ? CTA_CAJA : CTA_BANCOS;
}

// Naturaleza de la cuenta: activos, gastos y costos son débito ('D'); pasivos, patrimonio e ingresos, crédito ('C')
function naturalezaCuenta($codigo) {
    return in_array(substr((string) $codigo, 0, 1), ['1', '5', '6', '7'], true) ? 'D' : 'C';
}

// Catálogo PUC como [codigo => nombre] (una consulta por petición)
function nombresCuentas(PDO $db) {
    static $cache = null;
    if ($cache === null) {
        $cache = $db->query("SELECT codigo, nombre FROM puc_cuentas ORDER BY codigo")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache;
}

function normalizarDocumento($documento) {
    return strtoupper(preg_replace('/[^0-9A-Za-z-]/', '', (string) $documento));
}

// NIT (con o sin dígito de verificación), cédula, cédula de extranjería o pasaporte
function documentoValido($documento) {
    return (bool) preg_match('/^[0-9A-Z]{5,15}(-[0-9A-Z])?$/', $documento);
}

// ================================================================
// CONSECUTIVOS (FAC, CI, CE, NC)
// ================================================================

// Crea el contador del tipo y año si no existe, arrancando en el mayor número ya usado.
// Se llama ANTES de abrir la transacción del documento (así su INSERT no retiene bloqueos).
function asegurarConsecutivo(PDO $db, $tipo, $anio) {
    $anio = (int) $anio;
    $existe = $db->prepare("SELECT 1 FROM consecutivos WHERE tipo = ? AND anio = ?");
    $existe->execute([$tipo, $anio]);
    if ($existe->fetchColumn()) return;

    if ($tipo === 'FAC') {
        $max = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(numero_factura, '-', -1) AS UNSIGNED)), 0) FROM facturas WHERE numero_factura LIKE ?");
        $max->execute(["FAC-$anio-%"]);
    } else {
        $max = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)), 0) FROM comprobantes WHERE tipo = ? AND numero LIKE ?");
        $max->execute([$tipo, "$tipo-$anio-%"]);
    }
    $db->prepare("INSERT IGNORE INTO consecutivos (tipo, anio, ultimo) VALUES (?, ?, ?)")->execute([$tipo, $anio, (int) $max->fetchColumn()]);
}

// Siguiente número. Va DENTRO de la transacción que guarda el documento: el UPDATE bloquea la fila del contador
// hasta el COMMIT, así dos usuarios nunca reciben el mismo número y, si se revierte, el número no queda saltado.
function siguienteConsecutivo(PDO $db, $tipo, $anio) {
    if (!$db->inTransaction()) {
        throw new LogicException('siguienteConsecutivo() requiere una transacción activa.');
    }
    $upd = $db->prepare("UPDATE consecutivos SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE tipo = ? AND anio = ?");
    $upd->execute([$tipo, (int) $anio]);
    if ($upd->rowCount() !== 1) {
        throw new RuntimeException("No existe el consecutivo $tipo-$anio: llame antes a asegurarConsecutivo().");
    }
    $n = (int) $db->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    return $tipo . '-' . (int) $anio . '-' . str_pad((string) $n, $tipo === 'FAC' ? 3 : 4, '0', STR_PAD_LEFT);
}

// Número que se asignará si nadie más guarda antes (solo informativo en los formularios)
function consecutivoPropuesto(PDO $db, $tipo, $anio) {
    asegurarConsecutivo($db, $tipo, $anio);
    $stmt = $db->prepare("SELECT ultimo + 1 FROM consecutivos WHERE tipo = ? AND anio = ?");
    $stmt->execute([$tipo, (int) $anio]);
    return $tipo . '-' . (int) $anio . '-' . str_pad((string) $stmt->fetchColumn(), $tipo === 'FAC' ? 3 : 4, '0', STR_PAD_LEFT);
}

// ================================================================
// TERCEROS
// ================================================================

function terceroDeUsuario(PDO $db, $id_usuario) {
    $stmt = $db->prepare("SELECT id, documento, nombre FROM terceros WHERE id_usuario = ?");
    $stmt->execute([(int) $id_usuario]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Busca el tercero por documento o lo crea. Si ya existe se conserva el nombre registrado:
// un mismo NIT nunca queda con dos nombres distintos en la contabilidad.
function guardarTercero(PDO $db, $documento, $nombre, $tipo, $id_usuario = null) {
    $documento = normalizarDocumento($documento);
    $stmt = $db->prepare("SELECT id, documento, nombre, id_usuario FROM terceros WHERE documento = ?");
    $stmt->execute([$documento]);
    if ($tercero = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($id_usuario && !$tercero['id_usuario'] && !terceroDeUsuario($db, $id_usuario)) {
            $db->prepare("UPDATE terceros SET id_usuario = ? WHERE id = ?")->execute([(int) $id_usuario, $tercero['id']]);
        }
        return $tercero;
    }
    $db->prepare("INSERT INTO terceros (documento, nombre, tipo, id_usuario) VALUES (?, ?, ?, ?)")
       ->execute([$documento, mb_substr(trim($nombre), 0, 150), $tipo, $id_usuario ? (int) $id_usuario : null]);
    return ['id' => (int) $db->lastInsertId(), 'documento' => $documento, 'nombre' => trim($nombre), 'id_usuario' => $id_usuario];
}

// ================================================================
// COMPROBANTES
// ================================================================

// Valida la causación: cuentas auxiliares existentes, un valor por línea, débitos = créditos
// y el valor del comprobante igual al neto que entra (CI) o sale (CE) de caja y bancos. Devuelve el error o null.
function errorCausacion(PDO $db, $tipo, array $lineas, $valor_total) {
    $cuentas = nombresCuentas($db);
    if (count($lineas) < 2) return 'La causación necesita al menos dos movimientos.';

    $debitos = 0.0; $creditos = 0.0; $disponible = 0.0; $clientes = 0.0; $proveedores = 0.0;
    foreach ($lineas as $linea) {
        list($cuenta, , $debito, $credito) = $linea;
        $cuenta = (string) $cuenta;
        $debito = (float) $debito; $credito = (float) $credito;
        if (strlen($cuenta) !== 6 || !isset($cuentas[$cuenta])) return "La cuenta $cuenta no existe o no es una cuenta auxiliar.";
        // Clientes y proveedores solo se mueven con sus documentos: así el saldo de cada factura y de cada cuenta por pagar cuadra con el libro
        if ($tipo === 'NC' && $cuenta === CTA_CLIENTES) return 'La cuenta ' . CTA_CLIENTES . ' (clientes) se mueve con facturas y comprobantes de ingreso, no con notas contables.';
        if ($tipo === 'NC' && $cuenta === CTA_PROVEEDORES) return 'La cuenta ' . CTA_PROVEEDORES . ' (proveedores) se mueve con cuentas por pagar y comprobantes de egreso, no con notas contables.';
        if ($debito < 0 || $credito < 0 || ($debito > 0) === ($credito > 0)) return 'Cada movimiento debe tener un valor positivo en débito o en crédito, no en ambos.';
        $debitos += $debito; $creditos += $credito;
        if (substr($cuenta, 0, 2) === '11') $disponible += ($tipo === 'CI') ? $debito - $credito : $credito - $debito;
        if ($cuenta === CTA_CLIENTES) $clientes += $debito - $credito;
        if ($cuenta === CTA_PROVEEDORES) $proveedores += $credito - $debito;
    }
    if (abs($debitos - $creditos) > 0.005) return 'La causación no cuadra: la suma de débitos debe ser igual a la de créditos.';
    $valor_total = (float) $valor_total;
    if ($valor_total <= 0) return 'El valor del comprobante debe ser mayor que cero.';
    switch ($tipo) {
        // Notas contables y cierres no son recaudos ni pagos: su valor es la suma de sus débitos
        case 'NC':  return abs($debitos - $valor_total) > 0.005 ? 'El valor de la nota contable debe ser igual a la suma de sus débitos.' : null;
        case 'CC':  return abs($debitos - $valor_total) > 0.005 ? 'El valor del comprobante de cierre debe ser igual a la suma de sus débitos.' : null;
        // Las notas de factura cambian lo que debe el cliente; la cuenta por pagar, lo que se le debe al proveedor
        case 'NCV': return abs(-$clientes - $valor_total) > 0.005 ? 'El valor de la nota crédito debe ser igual al crédito a la cuenta de clientes.' : null;
        case 'NDV': return abs($clientes - $valor_total) > 0.005 ? 'El valor de la nota débito debe ser igual al débito a la cuenta de clientes.' : null;
        case 'FP':  return abs($proveedores - $valor_total) > 0.005 ? 'El valor de la cuenta por pagar debe ser igual al crédito a proveedores.' : null;
    }
    if (abs($disponible - $valor_total) > 0.005) return 'El valor del comprobante debe coincidir con el movimiento de caja o bancos.';
    return null;
}

/**
 * Guarda un comprobante con su causación y devuelve ['id' => , 'numero' => ].
 * $c: tipo, fecha, tercero_id, tercero_nombre, tercero_documento, concepto, metodo_pago, referencia, valor_total,
 *     factura_id, pago_id, estado (Pendiente | Aprobado), origen, preparado_por, clase_nota (notas y cierres),
 *     fecha_vencimiento (cuentas por pagar), origen_id (documento que se paga o se reversa).
 * No se guarda en un periodo cerrado.
 * $lineas: [[codigo_puc, detalle, debito, credito], ...]. Requiere una transacción activa (usa el consecutivo).
 */
function guardarComprobante(PDO $db, array $c, array $lineas) {
    if (!isset(TIPOS_COMPROBANTE[$c['tipo']])) throw new InvalidArgumentException('Tipo de comprobante no válido.');
    if (!fechaValida($c['fecha'])) throw new InvalidArgumentException('La fecha del comprobante no es válida.');
    if ($error = errorCausacion($db, $c['tipo'], $lineas, $c['valor_total'])) throw new InvalidArgumentException($error);
    if ($error = errorPeriodoCerrado($db, $c['fecha'])) throw new InvalidArgumentException($error);

    $estado = ($c['estado'] ?? 'Pendiente') === 'Aprobado' ? 'Aprobado' : 'Pendiente';
    $numero = siguienteConsecutivo($db, $c['tipo'], (int) substr($c['fecha'], 0, 4));

    $db->prepare("INSERT INTO comprobantes (tipo, numero, fecha, fecha_vencimiento, tercero_id, tercero_nombre, tercero_documento, concepto, metodo_pago, referencia,
                                            valor_total, factura_id, pago_id, origen_id, estado, origen, clase_nota, preparado_por, fecha_aprobacion)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($estado === 'Aprobado' ? 'NOW()' : 'NULL') . ")")
       ->execute([
           $c['tipo'], $numero, $c['fecha'], $c['fecha_vencimiento'] ?? null, $c['tercero_id'] ?? null, mb_substr(trim($c['tercero_nombre']), 0, 150),
           ($c['tercero_documento'] ?? '') !== '' ? $c['tercero_documento'] : null, mb_substr(trim($c['concepto']), 0, 255),
           $c['metodo_pago'], ($c['referencia'] ?? '') !== '' ? mb_substr($c['referencia'], 0, 100) : null,
           round((float) $c['valor_total'], 2), $c['factura_id'] ?? null, $c['pago_id'] ?? null, $c['origen_id'] ?? null,
           $estado, $c['origen'] ?? 'Manual', $c['clase_nota'] ?? null, $c['preparado_por'] ?? null,
       ]);
    $id = (int) $db->lastInsertId();

    $detalle = $db->prepare("INSERT INTO comprobantes_detalle (comprobante_id, codigo_puc, detalle, debito, credito) VALUES (?, ?, ?, ?, ?)");
    foreach ($lineas as $linea) {
        $detalle->execute([$id, $linea[0], mb_substr((string) $linea[1], 0, 255), round((float) $linea[2], 2), round((float) $linea[3], 2)]);
    }
    return ['id' => $id, 'numero' => $numero];
}

/**
 * Comprobante de ingreso por el recaudo de una factura:
 * Débito caja/bancos (valor recibido) + Débito anticipo de retención (si el cliente la practicó) / Crédito clientes (abono total).
 * $factura: fila de facturas unida a usuarios (id, id_usuario, numero_factura, servicio_contratado, nombre, organizacion).
 * $d: fecha, metodo, referencia, valor, retencion, estado, origen, usuario (null = sistema), pago_id, concepto.
 */
function registrarIngresoFactura(PDO $db, array $factura, array $d) {
    $valor = round((float) $d['valor'], 2);
    $retencion = round((float) ($d['retencion'] ?? 0), 2);
    $tercero = terceroDeUsuario($db, $factura['id_usuario']);
    $cliente = trim((string) ($factura['organizacion'] ?? '')) !== '' ? $factura['organizacion'] : $factura['nombre'];

    // Comisión de la pasarela o del banco (o lo recibido de menos por la tasa de cambio): gasto financiero
    $comision = round((float) ($d['comision'] ?? 0), 2);

    $lineas = [[cuentaDisponible($d['metodo']), 'Recaudo factura ' . $factura['numero_factura'], $valor, 0]];
    if ($retencion > 0) {
        $lineas[] = [CTA_ANTICIPO_RETENCION, 'Retención practicada por el cliente', $retencion, 0];
    }
    if ($comision > 0) {
        $lineas[] = [CTA_COMISIONES, 'Comisión de la plataforma de pago o diferencia en cambio', $comision, 0];
    }
    // Lo recibido de más al redondear la conversión a USD: ingreso por diferencia en cambio, no abona la factura
    $diferencia = round((float) ($d['diferencia_cambio'] ?? 0), 2);
    $lineas[] = [CTA_CLIENTES, 'Abono a factura ' . $factura['numero_factura'], 0, round($valor + $retencion + $comision - $diferencia, 2)];
    if ($diferencia > 0) {
        $lineas[] = [CTA_DIFERENCIA_CAMBIO, 'Diferencia en cambio a favor', 0, $diferencia];
    }

    return guardarComprobante($db, [
        'tipo' => 'CI', 'fecha' => $d['fecha'],
        'tercero_id' => $tercero['id'] ?? null, 'tercero_nombre' => $tercero['nombre'] ?? $cliente, 'tercero_documento' => $tercero['documento'] ?? null,
        'concepto' => $d['concepto'] ?? ('Recaudo de la factura ' . $factura['numero_factura'] . ' - ' . $factura['servicio_contratado']),
        'metodo_pago' => $d['metodo'], 'referencia' => $d['referencia'] ?? null, 'valor_total' => $valor,
        'factura_id' => (int) $factura['id'], 'pago_id' => $d['pago_id'] ?? null,
        'estado' => $d['estado'], 'origen' => $d['origen'], 'preparado_por' => $d['usuario'] ?? null,
    ], $lineas);
}

// null si el usuario puede ejecutar la acción sobre el comprobante; si no, el motivo
function errorFlujoComprobante(array $comp, array $usuario, $accion) {
    $es_mio = (int) $comp['preparado_por'] === (int) $usuario['id'];
    $rol = $usuario['rol'];
    switch ($accion) {
        case 'revisar':
            if ($comp['estado'] !== 'Pendiente') return 'Solo se revisan comprobantes en estado Pendiente.';
            if (!in_array($rol, ['Finanzas', 'Admin'], true)) return 'La revisión contable corresponde a Finanzas o a un Administrador.';
            if ($es_mio) return 'No puedes revisar un comprobante que tú mismo preparaste.';
            return null;
        case 'aprobar':
            if ($comp['estado'] !== 'Revisado') return 'Solo se aprueban comprobantes que ya fueron revisados.';
            if ($rol !== 'Admin') return 'La aprobación final corresponde a un Administrador.';
            if ((int) $comp['revisado_por'] === (int) $usuario['id']) return 'Quien revisó el comprobante no puede aprobarlo.';
            return null;
        case 'anular':
            if ($comp['estado'] === 'Anulado') return 'El comprobante ya está anulado.';
            if ($comp['estado'] === 'Aprobado' && $rol !== 'Admin') return 'Un comprobante aprobado solo puede anularlo un Administrador.';
            if (!in_array($rol, ['Finanzas', 'Admin'], true) && !$es_mio) return 'No tienes permiso para anular este comprobante.';
            return null;
    }
    return 'Acción no válida.';
}

// ================================================================
// FACTURAS DE VENTA
// ================================================================

// Tabla derivada con lo aplicado a cada factura (crédito a Clientes de sus comprobantes de ingreso)
// Por factura: aprobado = lo que ya disminuyó su saldo (recaudos y notas crédito aprobados, menos notas débito aprobadas),
// en_tramite = recaudos y notas crédito por aprobar; además lo recaudado y las notas por separado.
// Saldo que aún se puede aplicar = monto_total − aprobado − en_tramite.
function sqlAplicadoFacturas($filtro = '') {
    return "SELECT c.factura_id,
                   SUM(CASE WHEN c.estado = 'Aprobado' THEN d.credito - d.debito ELSE 0 END) AS aprobado,
                   SUM(CASE WHEN c.estado IN ('Pendiente', 'Revisado') AND c.tipo <> 'NDV' THEN d.credito - d.debito ELSE 0 END) AS en_tramite,
                   SUM(CASE WHEN c.estado = 'Aprobado' AND c.tipo = 'CI' THEN d.credito - d.debito ELSE 0 END) AS recaudado,
                   SUM(CASE WHEN c.estado = 'Aprobado' AND c.tipo = 'NCV' THEN d.credito ELSE 0 END) AS notas_credito,
                   SUM(CASE WHEN c.estado = 'Aprobado' AND c.tipo = 'NDV' THEN d.debito ELSE 0 END) AS notas_debito
            FROM comprobantes c
            JOIN comprobantes_detalle d ON d.comprobante_id = c.id AND d.codigo_puc = '" . CTA_CLIENTES . "'
            WHERE c.tipo IN ('CI', 'NCV', 'NDV') AND c.factura_id IS NOT NULL$filtro
            GROUP BY c.factura_id";
}

function aplicadoFactura(PDO $db, $factura_id) {
    $stmt = $db->prepare(sqlAplicadoFacturas(' AND c.factura_id = ?'));
    $stmt->execute([(int) $factura_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $valores = [];
    foreach (['aprobado', 'en_tramite', 'recaudado', 'notas_credito', 'notas_debito'] as $campo) {
        $valores[$campo] = (float) ($r[$campo] ?? 0);
    }
    return $valores;
}

// Estado de la factura según lo recaudado: Pagado (aprobado cubre el total), En Proceso (recaudo por aprobar
// o pago del cliente en validación), Vencido o Pendiente. Una factura Anulada no cambia.
function recalcularFactura(PDO $db, $factura_id) {
    $stmt = $db->prepare("SELECT monto_total, estado, fecha_vencimiento < CURDATE() AS vencida FROM facturas WHERE id = ?");
    $stmt->execute([(int) $factura_id]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f || $f['estado'] === 'Anulada') return $f ? $f['estado'] : null;

    $aplicado = aplicadoFactura($db, $factura_id);
    $validando = $db->prepare("SELECT COUNT(*) FROM pagos_registro WHERE factura_id = ? AND estado_transaccion = 'Pendiente_Validacion'");
    $validando->execute([(int) $factura_id]);

    if ($aplicado['aprobado'] >= (float) $f['monto_total'] - 0.5) $nuevo = 'Pagado';
    elseif ($aplicado['en_tramite'] > 0 || $validando->fetchColumn() > 0) $nuevo = 'En Proceso';
    elseif ($f['vencida']) $nuevo = 'Vencido';
    else $nuevo = 'Pendiente';

    if ($nuevo !== $f['estado']) {
        // Un pago confirmado aparece como "NUEVO PAGO" en Facturación hasta que alguien lo marca revisado
        $db->prepare("UPDATE facturas SET estado = ?, visto = IF(? = 'Pagado', 0, visto) WHERE id = ?")->execute([$nuevo, $nuevo, (int) $factura_id]);
    }
    return $nuevo;
}

// Las facturas pendientes cuya fecha de vencimiento ya pasó quedan como Vencido
function actualizarVencimientos(PDO $db) {
    $db->exec("UPDATE facturas SET estado = 'Vencido' WHERE estado = 'Pendiente' AND fecha_vencimiento < CURDATE()");
}

// ================================================================
// LIBRO AUXILIAR
// ================================================================

/**
 * Todos los movimientos contables como tabla derivada con columnas:
 * tipo (FV/CI/CE), doc_id, numero, fecha, tercero, documento, detalle, cuenta, debito, credito, estado.
 * Las facturas de venta no anuladas se causan solas (Clientes / Ingresos / IVA generado). De los comprobantes
 * solo cuentan los aprobados, salvo que se pidan también los preliminares (pendientes y revisados).
 */
// $excluir_cierre: sin los comprobantes de cierre anual (el estado de resultados muestra lo que pasó en el año, no su cierre)
function sqlMovimientos($incluir_preliminares = false, $excluir_cierre = false) {
    $base = "CASE WHEN f.subtotal > 0 THEN f.subtotal ELSE ROUND(f.monto_total / (1 + f.porcentaje_iva / 100), 2) END";
    $factura = "SELECT 'FV' AS tipo, f.id AS doc_id, f.numero_factura AS numero, f.fecha_emision AS fecha,
                       COALESCE(NULLIF(u.organizacion, ''), u.nombre) AS tercero, t.documento AS documento,
                       %s AS detalle, '%s' AS cuenta, %s AS debito, %s AS credito, 'Aprobado' AS estado
                FROM facturas f
                JOIN usuarios u ON u.id = f.id_usuario
                LEFT JOIN terceros t ON t.id_usuario = u.id
                WHERE f.estado <> 'Anulada'%s";
    $estados = $incluir_preliminares ? "'Pendiente', 'Revisado', 'Aprobado'" : "'Aprobado'";

    return sprintf($factura, "CONCAT('Factura de venta - ', f.servicio_contratado)", CTA_CLIENTES, 'f.monto_total', '0', '')
        . "\nUNION ALL\n" . sprintf($factura, "'Ingreso por servicios'", CTA_INGRESO_SERVICIOS, '0', $base, '')
        . "\nUNION ALL\n" . sprintf($factura, "'IVA generado'", CTA_IVA_GENERADO, '0', "f.monto_total - $base", " AND f.monto_total - $base > 0")
        . "\nUNION ALL\n"
        . "SELECT c.tipo, c.id, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento, d.detalle, d.codigo_puc, d.debito, d.credito, c.estado
           FROM comprobantes_detalle d
           JOIN comprobantes c ON c.id = d.comprobante_id
           WHERE c.estado IN ($estados)" . ($excluir_cierre ? " AND c.tipo <> 'CC'" : '');
}

// Saldo de una cuenta o grupo con los movimientos aprobados, expresado según su naturaleza
function saldoCuenta(PDO $db, $cuenta) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(m.debito - m.credito), 0) FROM (" . sqlMovimientos(false) . ") m WHERE m.cuenta LIKE ?");
    $stmt->execute([$cuenta . '%']);
    $neto = (float) $stmt->fetchColumn();
    return naturalezaCuenta($cuenta) === 'D' ? $neto : -$neto;
}

// Enlace al documento que originó un movimiento
function urlDocumento($tipo, $id) {
    if ($tipo === 'FV') return 'generar_pdf.php?id=' . (int) $id;
    return TIPOS_COMPROBANTE[$tipo]['pagina'] . '?id=' . (int) $id;
}

// ================================================================
// VALOR EN LETRAS
// ================================================================

function numeroALetras($valor) {
    $valor = round((float) $valor, 2);
    $entero = (int) floor($valor);
    $centavos = (int) round(($valor - $entero) * 100);
    $texto = $entero === 0 ? 'CERO' : letrasEntero($entero);
    $de = preg_match('/(MILLÓN|MILLONES)$/u', $texto) ? ' DE' : ''; // "UN MILLÓN DE PESOS"
    return $texto . $de . ' PESOS' . ($centavos > 0 ? ' CON ' . str_pad((string) $centavos, 2, '0', STR_PAD_LEFT) . '/100' : '') . ' M/CTE';
}

function letrasEntero($n) {
    if ($n >= 1000000) {
        $millones = intdiv($n, 1000000);
        $resto = $n % 1000000;
        $texto = $millones === 1 ? 'UN MILLÓN' : apocopar(letrasEntero($millones)) . ' MILLONES';
        return $texto . ($resto ? ' ' . letrasEntero($resto) : '');
    }
    if ($n >= 1000) {
        $miles = intdiv($n, 1000);
        $resto = $n % 1000;
        $texto = $miles === 1 ? 'MIL' : apocopar(letrasCentenas($miles)) . ' MIL';
        return $texto . ($resto ? ' ' . letrasCentenas($resto) : '');
    }
    return letrasCentenas($n);
}

function letrasCentenas($n) {
    static $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE',
        'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS',
        'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];
    static $decenas = [3 => 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    static $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    if ($n === 100) return 'CIEN';
    $resto = $n % 100;
    $texto = $resto < 30 ? $unidades[$resto] : $decenas[intdiv($resto, 10)] . ($resto % 10 ? ' Y ' . $unidades[$resto % 10] : '');
    return trim($centenas[intdiv($n, 100)] . ' ' . $texto);
}

// "VEINTIUNO MIL" -> "VEINTIÚN MIL", "CIENTO UNO MIL" -> "CIENTO UN MIL"
function apocopar($texto) {
    return preg_replace(['/VEINTIUNO$/u', '/UNO$/u'], ['VEINTIÚN', 'UN'], $texto);
}

// ================================================================
// ESTADOS FINANCIEROS (salen del mismo libro: facturas + comprobantes aprobados)
// ================================================================

/**
 * Saldos por cuenta hasta $hasta: [cuenta, neto_anterior, debitos, creditos] con neto = débitos − créditos.
 * Lo anterior a $desde queda en neto_anterior y lo del periodo en débitos/créditos ($desde null = todo es del periodo).
 * $nivel: dígitos del código (4 = cuenta de mayor, 6 = auxiliar). $clases: primeros dígitos a incluir.
 */
function saldosPorCuenta(PDO $db, $desde, $hasta, $nivel = 6, array $clases = [], $excluir_cierre = false) {
    $nivel = max(1, min(6, (int) $nivel));
    $filtro = $clases ? " AND LEFT(m.cuenta, 1) IN ('" . implode("', '", array_map('intval', $clases)) . "')" : '';
    $desde = $desde ?: '0001-01-01';
    $stmt = $db->prepare("SELECT LEFT(m.cuenta, $nivel) AS cuenta,
                                 SUM(CASE WHEN m.fecha < ? THEN m.debito - m.credito ELSE 0 END) AS neto_anterior,
                                 SUM(CASE WHEN m.fecha >= ? THEN m.debito ELSE 0 END) AS debitos,
                                 SUM(CASE WHEN m.fecha >= ? THEN m.credito ELSE 0 END) AS creditos
                          FROM (" . sqlMovimientos(false, $excluir_cierre) . ") m
                          WHERE m.fecha <= ?$filtro
                          GROUP BY LEFT(m.cuenta, $nivel)
                          ORDER BY cuenta");
    $stmt->execute([$desde, $desde, $desde, $hasta]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Utilidad (o pérdida) entre dos fechas: ingresos − gastos − costos contabilizados
// Con $excluir_cierre = false (balance) un año ya cerrado da cero: su utilidad quedó en 3605/3705
function resultadoPeriodo(PDO $db, $desde, $hasta, $excluir_cierre = false) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(m.credito - m.debito), 0) FROM (" . sqlMovimientos(false, $excluir_cierre) . ") m
                          WHERE LEFT(m.cuenta, 1) IN ('4', '5', '6', '7') AND m.fecha BETWEEN ? AND ?");
    $stmt->execute([$desde ?: '0001-01-01', $hasta]);
    return (float) $stmt->fetchColumn();
}

// Nombre de una cuenta de cualquier nivel: el del PUC o, si no está registrada, el de su grupo o clase
function nombreCuenta(PDO $db, $codigo) {
    $codigo = (string) $codigo;
    $cuentas = nombresCuentas($db);
    if (isset($cuentas[$codigo])) return $cuentas[$codigo];
    if (isset(GRUPOS_PUC[substr($codigo, 0, 2)])) return GRUPOS_PUC[substr($codigo, 0, 2)] . ($codigo !== substr($codigo, 0, 2) ? " ($codigo)" : '');
    return CLASES_PUC[substr($codigo, 0, 1)] ?? "Cuenta $codigo";
}

// Recaudos (CI) y pagos (CE) aprobados del periodo agrupados por forma de pago, con lo recibido en moneda extranjera
function movimientosPorFormaPago(PDO $db, $desde, $hasta) {
    $stmt = $db->prepare("SELECT c.metodo_pago,
                                 COALESCE(SUM(CASE WHEN c.tipo = 'CI' THEN c.valor_total END), 0) AS recaudado,
                                 COALESCE(SUM(c.tipo = 'CI'), 0) AS recaudos,
                                 COALESCE(SUM(CASE WHEN c.tipo = 'CE' THEN c.valor_total END), 0) AS pagado,
                                 COALESCE(SUM(c.tipo = 'CE'), 0) AS pagos,
                                 COALESCE(SUM(CASE WHEN c.tipo = 'CI' AND p.moneda IN ('USD', 'USDT') THEN p.monto_moneda END), 0) AS usd_recibidos
                          FROM comprobantes c
                          LEFT JOIN pagos_registro p ON p.id = c.pago_id
                          WHERE c.tipo IN ('CI', 'CE') AND c.estado = 'Aprobado' AND c.fecha BETWEEN ? AND ?
                          GROUP BY c.metodo_pago
                          ORDER BY recaudado DESC, pagado DESC");
    $stmt->execute([$desde, $hasta]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================================================================
// CUENTAS POR PAGAR A PROVEEDORES
// ================================================================

// Lo pagado a cada cuenta por pagar con comprobantes de egreso: aprobado y en trámite (pendiente o revisado)
function sqlPagadoCuentasPorPagar($filtro = '') {
    return "SELECT c.origen_id AS cxp_id,
                   SUM(CASE WHEN c.estado = 'Aprobado' THEN d.debito - d.credito ELSE 0 END) AS pagado,
                   SUM(CASE WHEN c.estado IN ('Pendiente', 'Revisado') THEN d.debito - d.credito ELSE 0 END) AS pago_en_tramite
            FROM comprobantes c
            JOIN comprobantes_detalle d ON d.comprobante_id = c.id AND d.codigo_puc = '" . CTA_PROVEEDORES . "'
            WHERE c.tipo = 'CE' AND c.origen_id IS NOT NULL$filtro
            GROUP BY c.origen_id";
}

// Cuenta por pagar con lo pagado, lo que está en trámite y el saldo que todavía se puede pagar
function cuentaPorPagar(PDO $db, $id) {
    $stmt = $db->prepare("SELECT c.*, COALESCE(p.pagado, 0) AS pagado, COALESCE(p.pago_en_tramite, 0) AS pago_en_tramite
                          FROM comprobantes c
                          LEFT JOIN (" . sqlPagadoCuentasPorPagar() . ") p ON p.cxp_id = c.id
                          WHERE c.id = ? AND c.tipo = 'FP'");
    $stmt->execute([(int) $id]);
    $cxp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cxp) return null;
    $cxp['saldo'] = round((float) $cxp['valor_total'] - (float) $cxp['pagado'] - (float) $cxp['pago_en_tramite'], 2);
    return $cxp;
}

// Estado de pago (fila con estado, valor_total, pagado, pago_en_tramite y fecha_vencimiento)
function estadoPagoCuentaPorPagar(array $cxp) {
    if ($cxp['estado'] === 'Anulado') return 'Anulada';
    if ($cxp['estado'] !== 'Aprobado') return 'Sin aprobar';
    if ((float) $cxp['valor_total'] - (float) $cxp['pagado'] <= 0.5) return 'Pagada';
    if ((float) $cxp['pago_en_tramite'] > 0) return 'Pago en trámite';
    if (!empty($cxp['fecha_vencimiento']) && $cxp['fecha_vencimiento'] < date('Y-m-d')) return 'Vencida';
    return (float) $cxp['pagado'] > 0 ? 'Pagada parcialmente' : 'Por pagar';
}

// ================================================================
// PERIODOS CONTABLES
// ================================================================

function periodoCerrado(PDO $db, $fecha) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM periodos_contables WHERE anio = ? AND mes = ? AND estado = 'Cerrado'");
    $stmt->execute([(int) substr((string) $fecha, 0, 4), (int) substr((string) $fecha, 5, 2)]);
    return (bool) $stmt->fetchColumn();
}

// null si se pueden registrar o modificar documentos con esa fecha; si no, el motivo
function errorPeriodoCerrado(PDO $db, $fecha) {
    return periodoCerrado($db, $fecha)
        ? 'El periodo ' . substr((string) $fecha, 5, 2) . '/' . substr((string) $fecha, 0, 4) . ' está cerrado: no se pueden registrar ni modificar documentos con esa fecha.'
        : null;
}
