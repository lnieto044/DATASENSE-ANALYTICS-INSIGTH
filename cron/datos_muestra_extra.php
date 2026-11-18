<?php
/**
 * DATOS DE MUESTRA (SEGUNDA PARTE): CONTABILIDAD COMPLETA Y PEDIDOS DE LA TIENDA
 * Completa lo que cron/datos_muestra.php no llena, para que ningún módulo del menú se vea vacío:
 *   - Cuentas por pagar: compra del equipo de cómputo, honorarios de desarrollo externo y publicidad de cada mes, con
 *     sus pagos (comprobantes de egreso); las últimas quedan por pagar y una vencida.
 *   - Notas contables (depreciación mensual del equipo), notas crédito (descuentos) y notas débito (intereses de mora).
 *   - Pedidos de la tienda de los clientes de ejemplo, con su factura, pago y entrega. No cambian los "vendidos" ni el
 *     inventario que ve el público: esos contadores solo suben con compras reales.
 *   - En una base permanente: conciliación bancaria de los dos meses anteriores y cierre contable de los meses
 *     terminados (incluido el cierre del año anterior). En la demostración temporal no, porque sus fechas se mueven.
 *
 * Mismas condiciones que la primera parte: solo en la demostración o en una base creada con sus datos, una sola vez
 * (marca 4 en ds_instalacion) y en una transacción. Requiere que la primera parte ya esté (marca 2).
 *
 * Uso: php cron/datos_muestra_extra.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$db = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/muestra_utilidades.php';
require_once __DIR__ . '/../includes/tienda.php';
require_once __DIR__ . '/../includes/cierre_contable.php';
require_once __DIR__ . '/../includes/conciliacion.php';
require_once __DIR__ . '/../includes/reportes.php';

function avisoExtra($texto) {
    echo date('Y-m-d H:i:s') . " Datos de muestra (contabilidad y tienda): $texto\n";
}

if (($_ENV['DATOS_MUESTRA'] ?? '1') === '0') exit;
$db->exec("CREATE TABLE IF NOT EXISTS ds_instalacion (id TINYINT PRIMARY KEY, estado VARCHAR(20) NOT NULL, actualizada DATETIME NOT NULL)");
$marcas = $db->query("SELECT id, estado FROM ds_instalacion")->fetchAll(PDO::FETCH_KEY_PAIR);
if (($_ENV['APP_ENV'] ?? '') !== 'demo' && !isset($marcas[1])) { avisoExtra('no aplica'); exit; }
if (!isset($marcas[2])) { avisoExtra('falta la primera parte (cron/datos_muestra.php)'); exit; }
if (isset($marcas[4])) { avisoExtra('ya estaban'); exit; }

mt_srand(20261119);
$hoy = new DateTimeImmutable('today');
$fin = $hoy->modify('-1 day');
$limite = $fin->setTime(23, 59, 59);
$inicio = $hoy->modify('first day of this month')->modify('-11 months');
$meses = [];
for ($m = $inicio; $m <= $fin; $m = $m->modify('first day of next month')) $meses[] = $m;
$nombres_mes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$permanente = configuracionBaseDatos()['externa'];

$roles = [];
foreach ($db->query("SELECT id, rol FROM usuarios WHERE estado_cuenta = 'Activo'")->fetchAll(PDO::FETCH_ASSOC) as $u) $roles[$u['rol']][] = (int) $u['id'];
$aprobadores = $roles['Admin'] ?? [1];
$finanzas = $roles['Finanzas'] ?? $aprobadores;
$empresa = datosEmpresa();
$flujo = $db->prepare("UPDATE comprobantes SET created_at = ?, estado = ?, revisado_por = ?, fecha_revision = ?, aprobado_por = ?, fecha_aprobacion = ? WHERE id = ?");
// Prepara Finanzas, revisa otra persona de Finanzas y aprueba un administrador (lo reciente queda en trámite)
$aprobar = function ($id, DateTimeImmutable $fecha, $preparo, $siempre = false) use ($flujo, $finanzas, $aprobadores, $limite) {
    $creado = $fecha->setTime(azar(8, 11), azar(0, 59));
    $revision = $creado->modify('+' . azar(2, 26) . ' hours');
    $aprobacion = $revision->modify('+' . azar(2, 30) . ' hours');
    $revisor = elegir(array_values(array_diff($finanzas, [$preparo])) ?: $aprobadores);
    $aprobador = elegir(array_values(array_diff($aprobadores, [$revisor])) ?: $aprobadores);
    if ($siempre || $aprobacion <= $limite) {
        $flujo->execute([f($creado), 'Aprobado', $revisor, f(min($revision, $limite)), $aprobador, f(min($aprobacion, $limite)), $id]);
    } elseif ($revision <= $limite) {
        $flujo->execute([f($creado), 'Revisado', $revisor, f($revision), null, null, $id]);
    } else {
        $flujo->execute([f($creado), 'Pendiente', null, null, null, null, $id]);
    }
};
$habil = function (DateTimeImmutable $d) use ($fin) {
    while (!esHabil($d)) $d = $d->modify('+1 day');
    return min($d, $fin);
};

$totales = ['cxp' => 0, 'pagos_cxp' => 0, 'notas' => 0, 'ncv' => 0, 'ndv' => 0, 'pedidos' => 0];
$db->beginTransaction();
try {
    $db->exec("INSERT INTO ds_instalacion (id, estado, actualizada) VALUES (4, 'generando', NOW())");
    for ($anio = (int) $inicio->format('Y'); $anio <= (int) $fin->format('Y'); $anio++) {
        foreach (['FP', 'CE', 'NC', 'NCV', 'NDV', 'CI', 'FAC', 'PED'] as $tipo) asegurarConsecutivo($db, $tipo, $anio);
    }

    // ================================================================
    // CUENTAS POR PAGAR Y SUS PAGOS
    // ================================================================
    $cuenta_por_pagar = function (DateTimeImmutable $fecha, $proveedor, $concepto, array $lineas, $total, $dias_plazo) use ($db, $finanzas, $aprobar, &$totales) {
        $tercero = guardarTercero($db, (string) (800000000 + abs(crc32($proveedor)) % 99999999), $proveedor, 'Proveedor');
        $preparo = elegir($finanzas);
        $fp = guardarComprobante($db, ['tipo' => 'FP', 'fecha' => $fecha->format('Y-m-d'), 'fecha_vencimiento' => $fecha->modify("+$dias_plazo days")->format('Y-m-d'),
                                       'tercero_id' => $tercero['id'], 'tercero_nombre' => $proveedor, 'tercero_documento' => $tercero['documento'],
                                       'concepto' => $concepto, 'metodo_pago' => 'No aplica', 'valor_total' => $total, 'estado' => 'Pendiente',
                                       'origen' => 'Manual', 'preparado_por' => $preparo], $lineas);
        $aprobar($fp['id'], $fecha, $preparo, true);   // una cuenta por pagar causada queda aprobada
        $totales['cxp']++;
        return ['id' => $fp['id'], 'tercero' => $tercero, 'proveedor' => $proveedor, 'total' => $total, 'concepto' => $concepto];
    };
    $pagar = function (array $cxp, DateTimeImmutable $fecha) use ($db, $finanzas, $aprobar, &$totales) {
        $preparo = elegir($finanzas);
        $ce = guardarComprobante($db, ['tipo' => 'CE', 'fecha' => $fecha->format('Y-m-d'), 'tercero_id' => $cxp['tercero']['id'], 'tercero_nombre' => $cxp['proveedor'],
                                       'tercero_documento' => $cxp['tercero']['documento'], 'concepto' => 'Pago a proveedor: ' . mb_strtolower($cxp['concepto']),
                                       'metodo_pago' => 'Transferencia', 'valor_total' => $cxp['total'], 'estado' => 'Pendiente', 'origen' => 'Manual',
                                       'preparado_por' => $preparo, 'origen_id' => $cxp['id']],
                                 [[CTA_PROVEEDORES, 'Pago de la cuenta por pagar', $cxp['total'], 0], [CTA_BANCOS, 'Transferencia al proveedor', 0, $cxp['total']]]);
        $aprobar($ce['id'], $fecha, $preparo);
        $totales['pagos_cxp']++;
    };

    // Equipo de cómputo del equipo de trabajo (activo que luego se deprecia)
    $compra = $habil($inicio->modify('+2 days'));
    $equipo = 9600000;
    $cxp = $cuenta_por_pagar($compra, 'Tecnología y Equipos del Norte S.A.S.', 'Compra de equipos de cómputo para el equipo de análisis',
                             [['152805', 'Equipos de cómputo (4 portátiles)', $equipo, 0], [CTA_IVA_DESCONTABLE, 'IVA descontable', round($equipo * 0.19), 0],
                              [CTA_PROVEEDORES, 'Cuenta por pagar al proveedor', 0, round($equipo * 1.19)]], round($equipo * 1.19), 30);
    $pagar($cxp, $habil($compra->modify('+' . azar(18, 28) . ' days')));

    foreach ($meses as $i => $mes) {
        $dias = diasHabilesEntre($mes, min($mes->modify('last day of this month'), $fin));
        if (count($dias) < 3) continue;
        $etiqueta = $nombres_mes[(int) $mes->format('n')] . ' de ' . $mes->format('Y');
        $ultimos = $i >= count($meses) - 2;   // los dos meses más recientes

        // Desarrollo de software externo (honorarios con retención del 11 %)
        $base = redondear(decimal(2400000, 3600000), 50000);
        $ret = round($base * 0.11);
        $cxp = $cuenta_por_pagar($dias[min(4, count($dias) - 1)], 'Juan Esteban Ríos Ospina', "Honorarios de desarrollo de software externo - $etiqueta",
                                 [['511025', 'Desarrollo de software externo', $base, 0], ['236515', 'Retención en la fuente 11%', 0, $ret],
                                  [CTA_PROVEEDORES, 'Cuenta por pagar al proveedor', 0, $base - $ret]], $base - $ret, 30);
        $pago = $habil($dias[min(4, count($dias) - 1)]->modify('+' . azar(20, 32) . ' days'));
        if (!$ultimos || ($pago <= $fin && probable(0.5))) $pagar($cxp, $pago);

        // Publicidad digital (con IVA). La del penúltimo mes queda vencida y la del último, por pagar
        $base = redondear(decimal(450000, 900000), 10000);
        $cxp = $cuenta_por_pagar($dias[min(9, count($dias) - 1)], 'Agencia Pixel Creativo S.A.S.', "Publicidad digital en redes sociales - $etiqueta",
                                 [['519595', 'Publicidad digital', $base, 0], [CTA_IVA_DESCONTABLE, 'IVA descontable', round($base * 0.19), 0],
                                  [CTA_PROVEEDORES, 'Cuenta por pagar al proveedor', 0, round($base * 1.19)]], round($base * 1.19), $ultimos ? 15 : 30);
        if (!$ultimos) $pagar($cxp, $habil($dias[min(9, count($dias) - 1)]->modify('+' . azar(15, 29) . ' days')));

        // ================================================================
        // NOTAS CONTABLES: depreciación mensual del equipo (36 meses), desde el mes siguiente a la compra
        // ================================================================
        if ($mes > $compra) {
            $cuota = round($equipo / 36);
            $nota = guardarComprobante($db, ['tipo' => 'NC', 'fecha' => end($dias)->format('Y-m-d'), 'tercero_nombre' => $empresa['nombre'],
                                             'tercero_documento' => normalizarDocumento($empresa['nit']), 'concepto' => "Depreciación del equipo de cómputo - $etiqueta",
                                             'metodo_pago' => 'No aplica', 'valor_total' => $cuota, 'estado' => 'Pendiente', 'origen' => 'Manual',
                                             'clase_nota' => 'Depreciación y amortización', 'preparado_por' => elegir($finanzas)],
                                       [['516020', 'Depreciación del mes', $cuota, 0], ['159220', 'Depreciación acumulada', 0, $cuota]]);
            $aprobar($nota['id'], end($dias), elegir($finanzas));
            $totales['notas']++;
        }
    }

    // ================================================================
    // NOTAS CRÉDITO Y DÉBITO DE FACTURAS
    // ================================================================
    $sin_pagar = $db->query("SELECT f.*, u.nombre, u.organizacion FROM facturas f JOIN usuarios u ON u.id = f.id_usuario
                             WHERE f.estado IN ('Pendiente', 'Vencido') ORDER BY f.fecha_emision")->fetchAll(PDO::FETCH_ASSOC);
    $nota_factura = function (array $f, $tipo, DateTimeImmutable $fecha, $motivo, array $lineas, $valor) use ($db, $finanzas, $aprobar, &$totales) {
        $tercero = terceroDeUsuario($db, $f['id_usuario']);
        $preparo = elegir($finanzas);
        $nota = guardarComprobante($db, ['tipo' => $tipo, 'fecha' => $fecha->format('Y-m-d'), 'tercero_id' => $tercero['id'] ?? null,
                                         'tercero_nombre' => $tercero['nombre'] ?? ($f['organizacion'] ?: $f['nombre']), 'tercero_documento' => $tercero['documento'] ?? null,
                                         'concepto' => "$motivo - " . $f['servicio_contratado'], 'metodo_pago' => 'No aplica', 'valor_total' => $valor,
                                         'factura_id' => (int) $f['id'], 'estado' => 'Pendiente', 'origen' => 'Manual', 'clase_nota' => $motivo, 'preparado_por' => $preparo], $lineas);
        $aprobar($nota['id'], $fecha, $preparo, true);
        recalcularFactura($db, $f['id']);
        $totales[strtolower($tipo)]++;
    };
    foreach ($sin_pagar as $f) {
        $emision = new DateTimeImmutable($f['fecha_emision']);
        $vence = new DateTimeImmutable($f['fecha_vencimiento']);
        $base_factura = (float) $f['subtotal'] > 0 ? (float) $f['subtotal'] : round((float) $f['monto_total'] / 1.19, 2);
        if ($f['estado'] === 'Vencido' && $totales['ndv'] < 3 && $vence->modify('+10 days') <= $fin) {
            $interes = round($base_factura * 0.02);
            $nota_factura($f, 'NDV', $habil($vence->modify('+10 days')), 'Intereses de mora',
                          [[CTA_CLIENTES, 'Intereses de mora por pago tardío', $interes, 0], [CTA_INTERESES, 'Intereses de mora', 0, $interes]], $interes);
        } elseif ($f['estado'] === 'Pendiente' && $totales['ncv'] < 3 && $emision->modify('+3 days') <= $fin) {
            $base = round($base_factura * 0.1, 2);
            $iva = round($base * (float) $f['porcentaje_iva'] / 100, 2);
            $nota_factura($f, 'NCV', $habil($emision->modify('+3 days')), 'Rebaja o descuento',
                          [[CTA_DEVOLUCIONES_VENTAS, 'Descuento comercial del 10%', $base, 0], [CTA_IVA_GENERADO, 'IVA del descuento', $iva, 0],
                           [CTA_CLIENTES, 'Menor valor de la factura', 0, $base + $iva]], $base + $iva);
        }
    }

    // ================================================================
    // PEDIDOS DE LA TIENDA (sin tocar "vendidos" ni el inventario)
    // ================================================================
    $productos = $db->query("SELECT id, sku, nombre, tipo, precio, requiere_envio FROM tienda_productos WHERE estado = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $clientes = $db->query("SELECT id, nombre, organizacion, telefono FROM usuarios WHERE rol = 'Cliente' AND estado_cuenta = 'Activo' AND correo LIKE '%@example.com'")->fetchAll(PDO::FETCH_ASSOC);
    $pedidos = [];
    if ($productos && $clientes) {
        foreach ($meses as $mes) {
            $dias = diasHabilesEntre($mes, min($mes->modify('last day of this month'), $fin));
            for ($k = 0, $n = azar(4, 7); $k < $n && $dias; $k++) $pedidos[] = [enJornada(elegir($dias), 7, 22), elegir($clientes)];
        }
        usort($pedidos, function ($a, $b) { return $a[0] <=> $b[0]; });
    }
    $crear_factura = $db->prepare("INSERT INTO facturas (id_usuario, id_servicio, numero_factura, servicio_contratado, fecha_emision, fecha_vencimiento, subtotal, porcentaje_iva, monto_total, estado, creado_en, visto)
                                   VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?, 1)");
    $crear_pedido = $db->prepare("INSERT INTO tienda_pedidos (numero, usuario_id, factura_id, estado, subtotal, descuento, envio, base, iva, total, entrega_nombre, entrega_telefono,
                                  entrega_direccion, entrega_ciudad, guia_envio, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $crear_item = $db->prepare("INSERT INTO tienda_pedido_items (pedido_id, producto_id, sku, nombre, tipo, precio_unitario, cantidad, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $crear_pago = $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, referencia_transaccion, fecha_pago, monto_pagado, observaciones, estado_transaccion, moneda)
                                VALUES (?, ?, ?, ?, ?, 'Pago en línea de la tienda', 'Aprobado', 'COP')");
    $historial = $db->prepare("INSERT INTO tienda_pedido_historial (pedido_id, fecha, usuario_id, estado, detalle) VALUES (?, ?, ?, ?, ?)");
    $calles = ['Calle', 'Carrera', 'Avenida', 'Transversal', 'Diagonal'];
    foreach ($pedidos as [$momento, $cliente]) {
        $lineas = [];
        foreach ((array) array_rand($productos, azar(1, min(3, count($productos)))) as $indice) {
            $p = $productos[$indice];
            $cantidad = $p['tipo'] === 'Físico' ? azar(1, 3) : 1;
            $lineas[] = [$p, $cantidad, round((float) $p['precio'] * $cantidad, 2)];
        }
        $subtotal = array_sum(array_column($lineas, 2));
        $envio = (array_filter($lineas, function ($l) { return (int) $l[0]['requiere_envio'] === 1; }) && $subtotal < TIENDA_ENVIO_GRATIS_DESDE) ? TIENDA_ENVIO : 0;
        $base = round(($subtotal + $envio) / (1 + TIENDA_IVA / 100), 2);
        $total = round($base * (1 + TIENDA_IVA / 100), 2);
        $fisico = $envio > 0 || array_filter($lineas, function ($l) { return $l[0]['tipo'] === 'Físico'; });
        $anio = (int) $momento->format('Y');
        $numero = siguienteConsecutivo($db, 'PED', $anio);
        $unidades = array_sum(array_column($lineas, 1));
        $crear_factura->execute([$cliente['id'], 'TMP-' . bin2hex(random_bytes(5)), "Pedido $numero · Tienda en línea ($unidades unidad(es))", $momento->format('Y-m-d'),
                                 $momento->modify('+' . TIENDA_DIAS_PAGO . ' days')->format('Y-m-d'), $base, TIENDA_IVA, $total, f($momento)]);
        $factura_id = (int) $db->lastInsertId();

        // Pagó en línea casi enseguida; luego la preparación, el envío y la entrega según la antigüedad
        $pagado = $momento->modify('+' . azar(3, 90) . ' minutes');
        if ($pagado > $limite) $pagado = $limite;
        $edad = (int) $momento->diff($limite)->format('%a');
        $estado = $edad > 8 ? 'Entregado' : ($edad > 3 ? ($fisico ? elegir(['Enviado', 'En preparación']) : 'Entregado') : 'Pagado');
        $direccion = elegir($calles) . ' ' . azar(2, 150) . ' # ' . azar(1, 99) . '-' . azar(1, 99);
        $crear_pedido->execute([$numero, $cliente['id'], $factura_id, $estado, $subtotal, $envio, $base, round($total - $base, 2), $total, $cliente['nombre'],
                                $cliente['telefono'] ?: telefono(), $fisico ? $direccion : null, $fisico ? elegirPeso(CIUDADES) : null,
                                $fisico && in_array($estado, ['Enviado', 'Entregado'], true) ? 'GU' . azar(10000000, 99999999) : null, f($momento), f($pagado)]);
        $pedido_id = (int) $db->lastInsertId();
        foreach ($lineas as [$p, $cantidad, $valor]) $crear_item->execute([$pedido_id, $p['id'], $p['sku'], $p['nombre'], $p['tipo'], $p['precio'], $cantidad, $valor]);

        $metodo = elegirPeso(['PSE' => 6, 'Tarjeta' => 4]);
        $crear_pago->execute([$factura_id, $metodo, 'MP-' . azar(100000000, 999999999), f($pagado), $total]);
        $pago_id = (int) $db->lastInsertId();
        $fila = $db->query("SELECT f.*, u.nombre, u.organizacion FROM facturas f JOIN usuarios u ON u.id = f.id_usuario WHERE f.id = $factura_id")->fetch(PDO::FETCH_ASSOC);
        $ci = registrarIngresoFactura($db, $fila, ['fecha' => $pagado->format('Y-m-d'), 'metodo' => $metodo, 'referencia' => null, 'valor' => $total, 'estado' => 'Pendiente',
                                                  'origen' => 'Pasarela', 'usuario' => null, 'pago_id' => $pago_id]);
        $flujo->execute([f($pagado), 'Aprobado', null, null, null, f($pagado), $ci['id']]);   // la pasarela confirma el pago sola
        recalcularFactura($db, $factura_id);

        $historial->execute([$pedido_id, f($momento), $cliente['id'], 'Pendiente de pago', 'Pedido creado en la tienda por ' . dinero($total) . '. Pendiente de pago.']);
        $historial->execute([$pedido_id, f($pagado), null, 'Pagado', 'Pago confirmado por la pasarela.']);
        // Pasos después del pago hasta el estado actual
        $pasos = $fisico ? ['En preparación', 'Enviado', 'Entregado'] : ['Entregado'];
        $hasta = array_search($estado, $pasos, true);
        $siguiente = $pagado;
        foreach ($hasta === false ? [] : array_slice($pasos, 0, $hasta + 1) as $paso) {
            $siguiente = min($siguiente->modify('+' . ($paso === 'Entregado' && $fisico ? azar(2, 4) * 24 : azar(2, 20)) . ' hours'), $limite);
            $historial->execute([$pedido_id, f($siguiente), elegir($aprobadores), $paso,
                                 $paso === 'Enviado' ? 'Pedido despachado con la transportadora.' : ($paso === 'Entregado' ? 'Entrega confirmada.' : 'Alistando el pedido.')]);
        }
        $totales['pedidos']++;
    }

    // Facturas y comprobantes nuevos con fechas anteriores: todo vuelve a quedar numerado en orden
    renumerarDocumentos($db);

    // ================================================================
    // CONCILIACIÓN BANCARIA (solo en base permanente): cada mes con su extracto; los terminados, cerrados; el actual, en proceso
    // ================================================================
    $conciliaciones = 0;
    if ($permanente) {
        $crear_conciliacion = $db->prepare("INSERT INTO conciliaciones (cuenta, periodo, saldo_extracto, estado, creado_por, created_at) VALUES (?, ?, NULL, 'Abierta', ?, ?)");
        $crear_movimiento = $db->prepare("INSERT INTO extracto_movimientos (conciliacion_id, fecha, descripcion, referencia, valor) VALUES (?, ?, ?, ?, ?)");
        $periodos = $meses;                     // todos los meses del periodo, hasta el actual
        foreach ($periodos as $j => $mes) {
            $atras = count($periodos) - $j;        // 1 = el mes en curso, que queda en proceso
            $periodo = $mes->format('Y-m');
            $fin_mes = min($mes->modify('last day of this month'), $fin);   // el mes en curso llega hasta ayer
            $crear_conciliacion->execute([CTA_BANCOS, $periodo, elegir($finanzas), f(min($fin_mes->modify('+3 days')->setTime(9, 0), $limite))]);
            $con_id = (int) $db->lastInsertId();
            $movs = $db->prepare("SELECT c.tipo, c.fecha, c.referencia, ROUND(d.debito - d.credito, 2) AS valor FROM comprobantes_detalle d JOIN comprobantes c ON c.id = d.comprobante_id
                                  WHERE c.estado = 'Aprobado' AND d.codigo_puc = ? AND DATE_FORMAT(c.fecha, '%Y-%m') = ? ORDER BY c.fecha, d.id");
            $movs->execute([CTA_BANCOS, $periodo]);
            $filas = $movs->fetchAll(PDO::FETCH_ASSOC);
            foreach ($filas as $i => $m) {
                // En el mes en proceso, los dos últimos pagos aún no aparecen en el banco (partidas en tránsito)
                if ($atras === 1 && $i >= count($filas) - 2 && (float) $m['valor'] < 0) continue;
                $descripcion = (float) $m['valor'] > 0 ? elegir(['ABONO TRANSFERENCIA', 'PAGO PSE RECIBIDO', 'CONSIGNACION NACIONAL'])
                                                       : ($m['tipo'] === 'CE' ? elegir(['PAGO A PROVEEDOR', 'TRANSFERENCIA ENVIADA', 'PAGO PROGRAMADO']) : 'DEBITO');
                $crear_movimiento->execute([$con_id, (new DateTimeImmutable($m['fecha']))->modify('+' . azar(0, 1) . ' days')->format('Y-m-d'), $descripcion, $m['referencia'], $m['valor']]);
            }
            // El banco abonó intereses que todavía no están en los libros
            if ($atras === 1) $crear_movimiento->execute([$con_id, $fin_mes->format('Y-m-d'), 'ABONO INTERESES CUENTA', null, azar(2500, 6800)]);
            $con = $db->query("SELECT * FROM conciliaciones WHERE id = $con_id")->fetch(PDO::FETCH_ASSOC);
            autoConciliar($db, $con, elegir($finanzas));
            $datos = datosConciliacion($db, $con);
            $db->prepare("UPDATE conciliaciones SET saldo_extracto = ? WHERE id = ?")->execute([$datos['calculado'], $con_id]);
            if ($atras > 1) {
                $db->prepare("UPDATE conciliaciones SET estado = 'Cerrada', cerrado_por = ?, fecha_cierre = ?, saldo_libro = ?, pendiente_libro = ?, pendiente_extracto = ? WHERE id = ?")
                   ->execute([elegir($aprobadores), f($mes->modify('last day of this month')->modify('+5 days')->setTime(16, 0)), $datos['saldo_libro'],
                              round($datos['transito'] + $datos['por_cobrar'], 2), $datos['pendiente_extracto'], $con_id]);
            }
            $conciliaciones++;
        }
    }

    $db->exec("UPDATE ds_instalacion SET estado = 'extra lista', actualizada = NOW() WHERE id = 4");
    $db->commit();
    avisoExtra("{$totales['cxp']} cuentas por pagar ({$totales['pagos_cxp']} pagadas), {$totales['notas']} notas contables, {$totales['ncv']} notas crédito, "
               . "{$totales['ndv']} notas débito, {$totales['pedidos']} pedidos de la tienda y $conciliaciones conciliaciones");
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($db->query("SELECT COUNT(*) FROM ds_instalacion WHERE id = 4")->fetchColumn()) {
        avisoExtra('otro servidor ya los generó');
        exit(0);
    }
    fwrite(STDERR, 'Datos de muestra (contabilidad y tienda): no se generaron (' . $e->getMessage() . ' en la línea ' . $e->getLine() . ")\n");
    exit(1);
}

// ================================================================
// CIERRE CONTABLE (solo en base permanente): meses terminados hasta hace dos meses, en orden; diciembre cierra el año
// ================================================================
if ($permanente) {
    $ultimo = $hoy->modify('first day of this month')->modify('-2 months')->format('Y-m');
    $cerrados = 0;
    foreach (mesesContables($db) as $mes => $datos) {
        if ($mes > $ultimo || $datos['cerrado']) continue;
        try {
            cerrarMes($db, $mes, $aprobadores[0]);
            $cerrados++;
        } catch (Throwable $e) {
            avisoExtra("no se cerró $mes: " . $e->getMessage());
            break;
        }
    }
    if ($cerrados) avisoExtra("$cerrados meses cerrados");
}
