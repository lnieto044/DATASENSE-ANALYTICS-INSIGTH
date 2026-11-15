<?php
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD
$usuario_actual = requiereAcceso($db, 'dashboard');
$rol = $usuario_actual['rol'];

// 2. PERFIL (para el header modular)
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$titulo = "Resumen Ejecutivo";
$subtitulo = "Control financiero, cartera y operación en tiempo real";

// Cada bloque se muestra solo a quien puede abrir el módulo del que salen sus datos
$ver_finanzas   = tieneAcceso($rol, 'facturacion', $db) || tieneAcceso($rol, 'libro_auxiliar', $db);
$ver_comercial  = tieneAcceso($rol, 'mensajes', $db);
$ver_clientes   = tieneAcceso($rol, 'clientes', $db);
$ver_reuniones  = tieneAcceso($rol, 'reuniones', $db);
require_once __DIR__ . '/../includes/itsm.php';
$ver_mesa       = tieneAcceso($rol, 'gestion_it', $db);
$pagina_tickets = $ver_mesa ? 'gestion_it.php' : 'soporte.php';
require_once __DIR__ . '/../includes/tienda.php';
$ver_tienda     = tieneAcceso($rol, 'tienda', $db) && tiendaDisponible($db);

actualizarVencimientos($db);

$consultar = function ($sql, array $params = []) use ($db) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
};
$variacion = function ($actual, $anterior) {
    return $anterior > 0 ? (int) round(($actual - $anterior) * 100 / $anterior) : null;
};

// 3. PERIODO ANALIZADO: el mes elegido (por defecto el actual) y los 5 anteriores para las tendencias
$meses_cortos = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$meses_largos = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$mes_pedido = is_string($_GET['mes'] ?? null) ? $_GET['mes'] : '';
$mes = (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes_pedido) && $mes_pedido <= date('Y-m')) ? $mes_pedido : date('Y-m');
$inicio = "$mes-01";
$fin = date('Y-m-t', strtotime($inicio));
$inicio_anterior = date('Y-m-01', strtotime("$inicio -1 month"));
$fin_anterior = date('Y-m-t', strtotime($inicio_anterior));
$inicio_serie = date('Y-m-01', strtotime("$inicio -5 month"));
$nombre_periodo = $meses_largos[(int) substr($mes, 5, 2) - 1] . ' ' . substr($mes, 0, 4);

$claves_serie = [];
$etiquetas_serie = [];
for ($i = 5; $i >= 0; $i--) {
    $t = strtotime("$inicio -$i month");
    $claves_serie[] = date('Y-m', $t);
    $etiquetas_serie[] = $meses_cortos[(int) date('n', $t) - 1];
}
// Convierte [YYYY-MM => valor] en la serie de 6 meses (los meses sin datos quedan en 0)
$serie = function (array $por_mes) use ($claves_serie) {
    return array_map(function ($clave) use ($por_mes) { return round((float) ($por_mes[$clave] ?? 0)); }, $claves_serie);
};

// 4. OPERACIÓN COMERCIAL Y DE SERVICIO
$total_mensajes = (int) $db->query("SELECT COUNT(*) FROM contactos")->fetchColumn();
$total_clientes = (int) $db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'Cliente'")->fetchColumn();
// Leads sin gestionar ('Pendiente' es el nombre anterior del estado 'Nuevo')
$pendientes = (int) $db->query("SELECT COUNT(*) FROM contactos WHERE estado IN ('Nuevo', 'Pendiente')")->fetchColumn();
$leads_sin_gestionar = (int) $db->query("SELECT COUNT(*) FROM contactos WHERE estado IN ('Nuevo', 'Pendiente') AND fecha_registro < NOW() - INTERVAL 2 DAY")->fetchColumn();
// Mesa de servicios: mismo motor de ANS que Gestión IT (casos en gestión, en riesgo y vencidos)
sincronizarTickets($db);
$tickets = resumenMesa(ticketsItsm($db, 't.fecha_cierre IS NULL'), configItsm($db));
$reuniones_hoy = (int) $db->query("SELECT COUNT(*) FROM reuniones_virtuales WHERE fecha = CURDATE() AND estado <> 'Finalizada'")->fetchColumn();
$actas_pendientes = (int) $db->query("SELECT COUNT(*) FROM reuniones_virtuales WHERE estado <> 'Finalizada' AND (fecha < CURDATE() OR (fecha = CURDATE() AND hora_fin <= CURTIME()))")->fetchColumn();

$flujo_totales = $serie($consultar("SELECT DATE_FORMAT(fecha_registro, '%Y-%m'), COUNT(*) FROM contactos WHERE DATE(fecha_registro) BETWEEN ? AND ? GROUP BY 1", [$inicio_serie, $fin])->fetchAll(PDO::FETCH_KEY_PAIR));

// Indicadores operativos reales, en porcentaje (0 a 100)
$porcentaje = function ($parte, $total) { return $total > 0 ? (int) round($parte * 100 / $total) : 0; };
$leads_atendidos = (int) $db->query("SELECT COUNT(*) FROM contactos WHERE estado NOT IN ('Nuevo', 'Pendiente')")->fetchColumn();
$leads_convertidos = (int) $db->query("SELECT COUNT(*) FROM contactos WHERE estado = 'Convertido'")->fetchColumn();
$facturas_total = (int) $db->query("SELECT COUNT(*) FROM facturas WHERE estado <> 'Anulada'")->fetchColumn();
$facturas_pagadas = (int) $db->query("SELECT COUNT(*) FROM facturas WHERE estado = 'Pagado'")->fetchColumn();
$satisfaccion = (float) $db->query("SELECT COALESCE(AVG(calificacion), 0) FROM encuestas_respuestas")->fetchColumn();
$tickets_cerrados = $db->query("SELECT COUNT(*) AS cerrados, COALESCE(SUM(sla_resolucion_cumplido = 1), 0) AS a_tiempo FROM soporte_tickets WHERE sla_resolucion_cumplido IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
$indicadores = [
    'Leads atendidos'   => $porcentaje($leads_atendidos, $total_mensajes),
    'Conversión'        => $porcentaje($leads_convertidos, $total_mensajes),
    'Facturas cobradas' => $porcentaje($facturas_pagadas, $facturas_total),
    'Satisfacción'      => (int) round($satisfaccion * 20), // promedio de 1 a 5 estrellas llevado a 100
    'Tickets a tiempo'  => $porcentaje((int) $tickets_cerrados['a_tiempo'], (int) $tickets_cerrados['cerrados']),
];
$res_sectores = $db->query("SELECT tipo_cliente, COUNT(*) as total FROM contactos GROUP BY tipo_cliente")->fetchAll(PDO::FETCH_ASSOC);
$res_servicios = $db->query("SELECT servicio, COUNT(*) as total FROM contactos GROUP BY servicio ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);

// 5. FINANZAS
if ($ver_finanzas) {
    $suma = function ($sql, $desde, $hasta) use ($consultar) { return (float) $consultar($sql, [$desde, $hasta])->fetchColumn(); };
    $sql_ventas  = "SELECT COALESCE(SUM(monto_total), 0) FROM facturas WHERE estado <> 'Anulada' AND fecha_emision BETWEEN ? AND ?";
    $sql_recaudo = "SELECT COALESCE(SUM(valor_total), 0) FROM comprobantes WHERE tipo = 'CI' AND estado = 'Aprobado' AND fecha BETWEEN ? AND ?";
    $sql_egresos = "SELECT COALESCE(SUM(valor_total), 0) FROM comprobantes WHERE tipo = 'CE' AND estado = 'Aprobado' AND fecha BETWEEN ? AND ?";

    $ventas_mes = $suma($sql_ventas, $inicio, $fin);
    $ventas_anterior = $suma($sql_ventas, $inicio_anterior, $fin_anterior);
    $recaudo_mes = $suma($sql_recaudo, $inicio, $fin);
    $recaudo_anterior = $suma($sql_recaudo, $inicio_anterior, $fin_anterior);
    $egresos_mes = $suma($sql_egresos, $inicio, $fin);
    $egresos_anterior = $suma($sql_egresos, $inicio_anterior, $fin_anterior);
    $flujo_mes = $recaudo_mes - $egresos_mes;
    $flujo_anterior = $recaudo_anterior - $egresos_anterior;

    // Cartera: saldo de cada factura sin pagar ni anular, clasificado por días de vencimiento
    $cartera = $db->query("SELECT f.id, f.numero_factura, f.fecha_vencimiento, COALESCE(NULLIF(u.organizacion, ''), u.nombre) AS cliente,
                                  f.monto_total - COALESCE(a.aprobado, 0) AS saldo, DATEDIFF(CURDATE(), f.fecha_vencimiento) AS dias
                           FROM facturas f
                           JOIN usuarios u ON u.id = f.id_usuario
                           LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
                           WHERE f.estado NOT IN ('Pagado', 'Anulada') AND f.monto_total - COALESCE(a.aprobado, 0) > 0.5
                           ORDER BY dias DESC")->fetchAll(PDO::FETCH_ASSOC);
    $edades = ['Por vencer' => 0, '1 a 30 días' => 0, '31 a 60 días' => 0, '61 a 90 días' => 0, 'Más de 90 días' => 0];
    $cartera_total = 0.0;
    $cartera_vencida = 0.0;
    $facturas_vencidas = 0;
    $por_vencer = [];
    foreach ($cartera as $c) {
        $dias = (int) $c['dias'];
        $saldo = (float) $c['saldo'];
        $cartera_total += $saldo;
        if ($dias <= 0) {
            $edades['Por vencer'] += $saldo;
            if ($dias >= -7) $por_vencer[] = $c;
        } else {
            $cartera_vencida += $saldo;
            $facturas_vencidas++;
            $edades[$dias <= 30 ? '1 a 30 días' : ($dias <= 60 ? '31 a 60 días' : ($dias <= 90 ? '61 a 90 días' : 'Más de 90 días'))] += $saldo;
        }
    }

    // Comprobantes que esperan revisión o aprobación
    $docs_por_gestionar = 0;
    $valor_por_gestionar = 0.0;
    foreach ($db->query("SELECT COUNT(*) AS n, COALESCE(SUM(valor_total), 0) AS v FROM comprobantes WHERE estado IN ('Pendiente', 'Revisado')") as $r) {
        $docs_por_gestionar = (int) $r['n'];
        $valor_por_gestionar = (float) $r['v'];
    }
    // Lo que le toca firmar al usuario actual (con las mismas reglas de segregación del flujo)
    $mis_firmas = $consultar("SELECT tipo,
                                     COALESCE(SUM(estado = 'Pendiente' AND ? IN ('Finanzas', 'Admin') AND COALESCE(preparado_por, 0) <> ?), 0) AS revisar,
                                     COALESCE(SUM(estado = 'Revisado' AND ? = 'Admin' AND COALESCE(revisado_por, 0) <> ?), 0) AS aprobar
                              FROM comprobantes WHERE estado IN ('Pendiente', 'Revisado') GROUP BY tipo",
                             [$rol, $usuario_actual['id'], $rol, $usuario_actual['id']])->fetchAll(PDO::FETCH_ASSOC);

    // Tendencia de 6 meses: facturado, recaudado, pagado y flujo neto
    $serie_facturado = $serie($consultar("SELECT DATE_FORMAT(fecha_emision, '%Y-%m'), SUM(monto_total) FROM facturas WHERE estado <> 'Anulada' AND fecha_emision BETWEEN ? AND ? GROUP BY 1", [$inicio_serie, $fin])->fetchAll(PDO::FETCH_KEY_PAIR));
    $serie_recaudado = $serie($consultar("SELECT DATE_FORMAT(fecha, '%Y-%m'), SUM(valor_total) FROM comprobantes WHERE tipo = 'CI' AND estado = 'Aprobado' AND fecha BETWEEN ? AND ? GROUP BY 1", [$inicio_serie, $fin])->fetchAll(PDO::FETCH_KEY_PAIR));
    $serie_pagado = $serie($consultar("SELECT DATE_FORMAT(fecha, '%Y-%m'), SUM(valor_total) FROM comprobantes WHERE tipo = 'CE' AND estado = 'Aprobado' AND fecha BETWEEN ? AND ? GROUP BY 1", [$inicio_serie, $fin])->fetchAll(PDO::FETCH_KEY_PAIR));
    $serie_flujo = array_map(function ($r, $p) { return $r - $p; }, $serie_recaudado, $serie_pagado);

    // Gastos del mes por cuenta contable (lo causado en egresos aprobados)
    $gastos_cuenta = $consultar("SELECT CONCAT(d.codigo_puc, ' · ', COALESCE(p.nombre, '')) AS cuenta, SUM(d.debito - d.credito) AS valor
                                 FROM comprobantes c
                                 JOIN comprobantes_detalle d ON d.comprobante_id = c.id
                                 LEFT JOIN puc_cuentas p ON p.codigo = d.codigo_puc
                                 WHERE c.tipo = 'CE' AND c.estado = 'Aprobado' AND c.fecha BETWEEN ? AND ?
                                   AND (d.codigo_puc LIKE '5%' OR d.codigo_puc LIKE '6%' OR d.codigo_puc LIKE '15%')
                                 GROUP BY d.codigo_puc, p.nombre
                                 ORDER BY valor DESC", [$inicio, $fin])->fetchAll(PDO::FETCH_KEY_PAIR);

    // Principales clientes del año: facturado y saldo por cobrar
    $top_clientes = $consultar("SELECT COALESCE(NULLIF(u.organizacion, ''), u.nombre) AS cliente, COUNT(*) AS facturas, SUM(f.monto_total) AS facturado,
                                       SUM(CASE WHEN f.estado <> 'Pagado' THEN GREATEST(f.monto_total - COALESCE(a.aprobado, 0), 0) ELSE 0 END) AS saldo
                                FROM facturas f
                                JOIN usuarios u ON u.id = f.id_usuario
                                LEFT JOIN (" . sqlAplicadoFacturas() . ") a ON a.factura_id = f.id
                                WHERE f.estado <> 'Anulada' AND YEAR(f.fecha_emision) = ?
                                GROUP BY u.id, u.organizacion, u.nombre
                                ORDER BY facturado DESC
                                LIMIT 5", [(int) substr($mes, 0, 4)])->fetchAll(PDO::FETCH_ASSOC);

    // Controles contables: sumas iguales en todo lo contabilizado y saldo de caja y bancos
    $descuadre = (float) $db->query("SELECT COALESCE(SUM(debito), 0) - COALESCE(SUM(credito), 0) FROM (" . sqlMovimientos() . ") m")->fetchColumn();
    $saldo_disponible = saldoCuenta($db, '11');

    // Cómo entró y salió el dinero del mes (tarjeta, efectivo, PSE, PayPal, Zelle...) y pagos internacionales por validar
    $formas_pago = movimientosPorFormaPago($db, $inicio, $fin);
    $recaudo_por_forma = [];
    foreach ($formas_pago as $fp) {
        if ((float) $fp['recaudado'] > 0) $recaudo_por_forma[$fp['metodo_pago']] = (float) $fp['recaudado'];
    }
    $pagos_por_validar = (int) $db->query("SELECT COUNT(*) FROM pagos_registro p
                                           WHERE p.estado_transaccion = 'Pendiente_Validacion' AND p.metodo_pago IN ('" . implode("', '", METODOS_REPORTADOS) . "')
                                             AND NOT EXISTS (SELECT 1 FROM comprobantes c WHERE c.pago_id = p.id AND c.estado <> 'Anulado')")->fetchColumn();
}

// 6. CENTRO DE ALERTAS: lo que requiere acción, de lo más urgente a lo informativo
$alertas = [];
if ($ver_finanzas) {
    if ($facturas_vencidas > 0) {
        $mas_atrasada = $cartera[0];
        $alertas[] = ['danger', 'fa-file-invoice-dollar', "$facturas_vencidas factura(s) vencida(s) por " . dinero($cartera_vencida) . ". La más atrasada: {$mas_atrasada['numero_factura']} de {$mas_atrasada['cliente']} ({$mas_atrasada['dias']} días).", 'facturacion.php'];
    }
    if (abs($descuadre) >= 0.01) {
        $alertas[] = ['danger', 'fa-balance-scale', 'La contabilidad no cuadra: diferencia de ' . dinero($descuadre, 2) . ' entre débitos y créditos.', 'libro_auxiliar.php'];
    }
    if ($pagos_por_validar > 0) {
        $alertas[] = ['warning', 'fa-globe-americas', "$pagos_por_validar pago(s) internacional(es) reportado(s) por clientes esperan validación.", 'facturacion.php#pagosReportados'];
    }
    if ($saldo_disponible < 0) {
        $alertas[] = ['danger', 'fa-university', 'Caja y bancos tienen saldo negativo (' . dinero($saldo_disponible) . '): revisa los egresos aprobados.', 'libro_auxiliar.php?cuenta=11'];
    }
    foreach ($mis_firmas as $firma) {
        $cfg = TIPOS_COMPROBANTE[$firma['tipo']];
        foreach (['revisar' => ['warning', 'fa-check-double', 'Pendiente'], 'aprobar' => ['primary', 'fa-pen-nib', 'Revisado']] as $accion => [$nivel, $icono, $estado]) {
            $n = (int) $firma[$accion];
            if ($n > 0) {
                $alertas[] = [$nivel, $icono, "Tienes $n " . mb_strtolower($n === 1 ? $cfg['nombre'] : $cfg['plural'], 'UTF-8') . " por $accion.", $cfg['pagina'] . '?estado=' . $estado];
            }
        }
    }
    if ($por_vencer) {
        $alertas[] = ['warning', 'fa-calendar-day', count($por_vencer) . ' factura(s) vencen en los próximos 7 días por ' . dinero(array_sum(array_column($por_vencer, 'saldo'))) . '.', 'facturacion.php'];
    }
    // Cuentas por pagar a proveedores vencidas o que vencen en los próximos 7 días
    $cxp_alerta = $db->query("SELECT COALESCE(SUM(c.fecha_vencimiento < CURDATE()), 0) AS vencidas,
                                     COALESCE(SUM(CASE WHEN c.fecha_vencimiento < CURDATE() THEN c.valor_total - COALESCE(p.pagado, 0) END), 0) AS valor_vencido,
                                     COALESCE(SUM(c.fecha_vencimiento BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY), 0) AS por_vencer
                              FROM comprobantes c
                              LEFT JOIN (" . sqlPagadoCuentasPorPagar() . ") p ON p.cxp_id = c.id
                              WHERE c.tipo = 'FP' AND c.estado = 'Aprobado' AND c.valor_total - COALESCE(p.pagado, 0) > 0.5")->fetch(PDO::FETCH_ASSOC);
    if ((int) $cxp_alerta['vencidas'] > 0) {
        $alertas[] = ['danger', 'fa-file-invoice', "{$cxp_alerta['vencidas']} cuenta(s) por pagar a proveedores vencida(s) por " . dinero($cxp_alerta['valor_vencido']) . '.', 'cuentas_por_pagar.php?pago=vencidas'];
    }
    if ((int) $cxp_alerta['por_vencer'] > 0) {
        $alertas[] = ['warning', 'fa-file-invoice', "{$cxp_alerta['por_vencer']} cuenta(s) por pagar a proveedores vencen en los próximos 7 días.", 'cuentas_por_pagar.php?pago=saldo'];
    }
}
if ((int) $tickets['vencidos'] > 0) {
    $alertas[] = ['danger', 'fa-headset', "{$tickets['vencidos']} ticket(s) de soporte con el ANS vencido.", $pagina_tickets];
}
if ($ver_mesa && (int) $tickets['en_riesgo'] > 0) {
    $alertas[] = ['warning', 'fa-stopwatch', "{$tickets['en_riesgo']} ticket(s) de soporte en riesgo de incumplir el ANS.", 'gestion_it.php'];
}
if ($ver_mesa && (int) $tickets['sin_asignar'] > 0) {
    $alertas[] = ['warning', 'fa-user-clock', "{$tickets['sin_asignar']} ticket(s) de soporte sin especialista asignado.", 'gestion_it.php'];
}
if ($ver_comercial && $leads_sin_gestionar > 0) {
    $alertas[] = ['warning', 'fa-envelope-open-text', "$leads_sin_gestionar solicitud(es) web llevan más de 48 horas sin gestionar.", 'mensajes.php'];
}
if ($ver_reuniones && $actas_pendientes > 0) {
    $alertas[] = ['info', 'fa-file-signature', "$actas_pendientes reunión(es) ya realizadas sin acta cerrada.", 'salas_reuniones.php'];
}
if ($ver_reuniones && $reuniones_hoy > 0) {
    $alertas[] = ['info', 'fa-video', "$reuniones_hoy reunión(es) programadas para hoy.", 'salas_reuniones.php'];
}

// Tarjeta KPI reutilizable
$tarjeta = function ($etiqueta, $valor, $clave, $color, $icono, $pie = '') {
    echo '<div class="col-6 col-xl-3"><div class="card card-kpi p-4 border-0 border-bottom border-4 border-' . $color . ' shadow-sm h-100">'
       . '<div class="d-flex justify-content-between align-items-start gap-2"><small class="text-muted fw-bold text-uppercase">' . $etiqueta . '</small>'
       . '<i class="fas ' . $icono . ' fa-lg text-' . $color . ' opacity-50"></i></div>'
       . '<h3 class="fw-bold mb-1 mt-2 fs-4 text-nowrap" data-kpi="' . $clave . '">' . $valor . '</h3>'
       . '<div class="small text-muted">' . $pie . '</div></div></div>';
};
$tendencia = function ($pct, $subir_es_bueno = true) {
    if ($pct === null) return 'Sin datos del mes anterior';
    $color = $pct === 0 ? 'secondary' : ((($pct > 0) === $subir_es_bueno) ? 'success' : 'danger');
    return '<span class="fw-bold text-' . $color . '"><i class="fas fa-arrow-' . ($pct >= 0 ? 'up' : 'down') . ' me-1"></i>' . abs($pct) . '%</span> vs. mes anterior';
};
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | DataSense Enterprise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/contabilidad.css">
    <style>
        .seccion-dashboard { font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #64748b; margin: 0 0 1rem; display: flex; align-items: center; gap: .6rem; }
        .seccion-dashboard::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
        .alerta-item { display: flex; gap: .85rem; align-items: flex-start; padding: .8rem .9rem; border-radius: .8rem; text-decoration: none; color: #0f172a; border: 1px solid transparent; transition: .2s; }
        .alerta-item:hover { transform: translateX(3px); color: #0f172a; }
        .alerta-item .icono { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .alerta-danger  { background: #fef2f2; border-color: #fecaca; } .alerta-danger .icono  { background: #fee2e2; color: #dc2626; }
        .alerta-warning { background: #fffbeb; border-color: #fde68a; } .alerta-warning .icono { background: #fef3c7; color: #b45309; }
        .alerta-primary { background: #eff6ff; border-color: #bfdbfe; } .alerta-primary .icono { background: #dbeafe; color: #1d4ed8; }
        .alerta-info    { background: #f0f9ff; border-color: #bae6fd; } .alerta-info .icono    { background: #e0f2fe; color: #0369a1; }
        .alerta-success { background: #f0fdf4; border-color: #bbf7d0; } .alerta-success .icono { background: #dcfce7; color: #15803d; }
        .lista-alertas { max-height: 330px; overflow-y: auto; display: grid; gap: .55rem; }
        .vacio-grafica { height: 100%; display: flex; align-items: center; justify-content: center; color: #94a3b8; text-align: center; }
        .lanzador-modulos { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 20px; }
        .grupo-modulos + .grupo-modulos { margin-top: 16px; }
        .grupo-titulo { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--color-seccion, #64748b); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .rejilla-modulos { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
        .tarjeta-modulo { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; color: #0f172a; text-decoration: none; font-weight: 600; font-size: .85rem; transition: .2s; min-width: 0; }
        .tarjeta-modulo span:last-child { overflow: hidden; text-overflow: ellipsis; }
        .tarjeta-modulo .icono { width: 36px; height: 36px; border-radius: 10px; background: color-mix(in srgb, var(--color-seccion, #0A2E4F) 12%, #fff); color: var(--color-seccion, #0A2E4F); display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: .2s; }
        .tarjeta-modulo:hover { border-color: var(--color-seccion, #3FAE49); box-shadow: 0 8px 20px rgba(15,23,42,.08); transform: translateY(-2px); color: #0f172a; }
        .tarjeta-modulo:hover .icono { background: var(--color-seccion, #3FAE49); color: #fff; }
        .tarjeta-modulo.actual { border-color: var(--color-seccion, #0A2E4F); background: #f8fafc; }
        @media (max-width: 575.98px) { .lanzador-modulos { padding: 14px; } .rejilla-modulos { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; } .tarjeta-modulo { font-size: .76rem; padding: 8px; gap: 8px; } .tarjeta-modulo .icono { width: 30px; height: 30px; } }
        @media (prefers-reduced-motion: reduce) { .tarjeta-modulo, .tarjeta-modulo .icono { transition: none; } }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php
        // Todos los módulos que el rol puede abrir (los mismos del menú lateral), visibles aunque el menú esté oculto
        $colores_seccion = ['Operación y Análisis' => '#0369a1', 'Mesa de Servicios (ITSM)' => '#7c3aed', 'Atención Ciudadana' => '#0d9488',
                            'Facturación y Cartera' => '#15803d', 'Tesorería y Bancos' => '#0f766e', 'Contabilidad e Informes' => '#b45309',
                            'Tienda en Línea' => '#e11d48', 'Automatización' => '#0891b2', 'Administración del Sistema' => '#475569'];
        $total_modulos = array_sum(array_map('count', $menu_panel_visible ?? []));
        ?>
        <?php if ($total_modulos): ?>
        <section class="lanzador-modulos mb-4" id="lanzadorModulos" aria-labelledby="tituloModulos">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <h6 class="fw-bold mb-0 text-dark" id="tituloModulos"><i class="fas fa-grip me-2 text-success"></i>Todos los módulos <span class="badge rounded-pill text-bg-light border ms-1"><?= $total_modulos ?></span></h6>
                <button class="btn btn-sm btn-light border rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#rejillaModulos" aria-expanded="true" aria-controls="rejillaModulos" id="btnPlegarModulos"><i class="fas fa-chevron-up me-1"></i><span>Ocultar</span></button>
            </div>
            <div class="collapse show" id="rejillaModulos">
                <?php foreach ($menu_panel_visible as $seccion_menu => $enlaces_menu): ?>
                <div class="grupo-modulos" style="--color-seccion: <?= $colores_seccion[$seccion_menu] ?? '#0A2E4F' ?>">
                    <div class="grupo-titulo"><?= esc($seccion_menu) ?></div>
                    <div class="rejilla-modulos">
                        <?php foreach ($enlaces_menu as $enlace_menu): ?>
                        <a href="<?= esc($enlace_menu[1]) ?>" class="tarjeta-modulo <?= $enlace_menu[1] === 'dashboard.php' ? 'actual' : '' ?>" title="<?= esc($enlace_menu[3]) ?>">
                            <span class="icono"><i class="fas <?= esc($enlace_menu[2]) ?>"></i></span><span><?= esc($enlace_menu[3]) ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <script>
        // El bloque de módulos recuerda si se plegó (en este navegador)
        (function () {
            const rejilla = document.getElementById('rejillaModulos');
            const boton = document.getElementById('btnPlegarModulos');
            const pintar = function (abierto) {
                boton.querySelector('span').textContent = abierto ? 'Ocultar' : 'Mostrar';
                boton.querySelector('i').className = 'fas ' + (abierto ? 'fa-chevron-up' : 'fa-chevron-down') + ' me-1';
                boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
            };
            try { if (localStorage.getItem('ds_modulos_plegados') === '1') { rejilla.classList.remove('show'); pintar(false); } } catch (e) { /* sin almacenamiento */ }
            rejilla.addEventListener('shown.bs.collapse', function () { pintar(true); try { localStorage.setItem('ds_modulos_plegados', '0'); } catch (e) {} });
            rejilla.addEventListener('hidden.bs.collapse', function () { pintar(false); try { localStorage.setItem('ds_modulos_plegados', '1'); } catch (e) {} });
        })();
        </script>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div class="text-muted small"><i class="far fa-clock me-1"></i> Corte: <?= date('d/m/Y h:i A') ?> · Periodo analizado: <b id="periodo"><?= $nombre_periodo ?></b></div>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <label for="mes" class="small text-muted fw-bold">Mes</label>
                <input type="month" name="mes" id="mes" class="form-control form-control-sm rounded-pill" value="<?= esc($mes) ?>" max="<?= date('Y-m') ?>">
                <button type="submit" class="btn btn-sm btn-dark rounded-pill px-3" style="background: var(--azul-sonda);">Ver</button>
            </form>
        </div>

        <?php if ($ver_finanzas): ?>
        <h6 class="seccion-dashboard"><i class="fas fa-coins"></i> Finanzas del periodo</h6>
        <div class="row g-4 mb-4">
            <?php
            $tarjeta('Ventas facturadas', dinero($ventas_mes), 'ventas_mes', 'primary', 'fa-file-invoice-dollar', $tendencia($variacion($ventas_mes, $ventas_anterior)));
            $tarjeta('Recaudado', dinero($recaudo_mes), 'recaudo_mes', 'success', 'fa-hand-holding-usd', $tendencia($variacion($recaudo_mes, $recaudo_anterior)));
            $tarjeta('Egresos pagados', dinero($egresos_mes), 'egresos_mes', 'danger', 'fa-money-check-alt', $tendencia($variacion($egresos_mes, $egresos_anterior), false));
            $tarjeta('Flujo neto de caja', dinero($flujo_mes), 'flujo_mes', $flujo_mes >= 0 ? 'info' : 'warning', 'fa-water', 'Recaudado − egresos pagados');
            ?>
        </div>
        <div class="row g-4 mb-4">
            <?php
            $tarjeta('Cartera por cobrar', dinero($cartera_total), 'cartera_total', 'warning', 'fa-hourglass-half', count($cartera) . ' factura(s) con saldo');
            $tarjeta('Cartera vencida', dinero($cartera_vencida), 'cartera_vencida', 'danger', 'fa-exclamation-circle',
                     $facturas_vencidas . ' vencida(s)' . ($cartera_total > 0 ? ' · ' . round($cartera_vencida * 100 / $cartera_total) . '% de la cartera' : ''));
            $tarjeta('Comprobantes por aprobar', (string) $docs_por_gestionar, 'docs_por_gestionar', 'secondary', 'fa-stamp', dinero($valor_por_gestionar) . ' sin efecto contable aún');
            $tarjeta('Caja y bancos', dinero($saldo_disponible), 'saldo_disponible', $saldo_disponible >= 0 ? 'success' : 'danger', 'fa-university', 'Saldo contable aprobado');
            ?>
        </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-12 col-xl-5">
                <div class="panel-conta p-4 h-100">
                    <h6 class="fw-bold mb-3 text-uppercase small"><i class="fas fa-bell text-warning me-2"></i> Centro de alertas</h6>
                    <div class="lista-alertas" id="alertas">
                        <?php foreach ($alertas as [$nivel, $icono, $texto, $enlace]): ?>
                            <a href="<?= esc($enlace) ?>" class="alerta-item alerta-<?= $nivel ?>">
                                <span class="icono"><i class="fas <?= $icono ?>"></i></span>
                                <span class="small fw-semibold"><?= esc($texto) ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($ver_finanzas && abs($descuadre) < 0.01): ?>
                            <a href="libro_auxiliar.php" class="alerta-item alerta-success" id="controlContable">
                                <span class="icono"><i class="fas fa-balance-scale"></i></span>
                                <span class="small fw-semibold">Contabilidad cuadrada: débitos y créditos iguales en todos los documentos aprobados.</span>
                            </a>
                        <?php endif; ?>
                        <?php if (!$alertas && !$ver_finanzas): ?>
                            <div class="alerta-item alerta-success"><span class="icono"><i class="fas fa-check"></i></span><span class="small fw-semibold">Todo al día: no hay pendientes urgentes.</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-7">
                <div class="chart-box">
                    <?php if ($ver_finanzas): ?>
                        <h6 class="fw-bold mb-3 text-uppercase small">Facturado, recaudado y pagado · últimos 6 meses</h6>
                        <div class="chart-lienzo"><canvas id="finChart"></canvas></div>
                    <?php else: ?>
                        <h6 class="fw-bold mb-3 text-uppercase small">Flujo Mensual de Interacción</h6>
                        <div class="chart-lienzo"><canvas id="lineChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($ver_finanzas): ?>
        <div class="row g-4 mb-5">
            <div class="col-12 col-lg-4">
                <div class="chart-box">
                    <h6 class="fw-bold mb-3 text-uppercase small">Cartera por edades</h6>
                    <?php if ($cartera_total > 0): ?>
                        <div class="chart-lienzo"><canvas id="carteraChart"></canvas></div>
                    <?php else: ?>
                        <div class="vacio-grafica"><div><i class="fas fa-check-circle fa-2x text-success mb-2"></i><br>Sin cartera pendiente</div></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="chart-box">
                    <h6 class="fw-bold mb-3 text-uppercase small">Gastos del mes por cuenta</h6>
                    <?php if ($gastos_cuenta): ?>
                        <div class="chart-lienzo"><canvas id="gastosChart"></canvas></div>
                    <?php else: ?>
                        <div class="vacio-grafica"><div><i class="fas fa-receipt fa-2x mb-2"></i><br>No hay egresos aprobados en <?= $nombre_periodo ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="chart-box" style="overflow: auto;">
                    <h6 class="fw-bold mb-3 text-uppercase small">Principales clientes <?= substr($mes, 0, 4) ?></h6>
                    <table class="table table-sm align-middle mb-0 tabla-conta" id="topClientes">
                        <thead><tr><th>Cliente</th><th class="text-end">Facturado</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            <?php if (!$top_clientes): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">Sin facturas en el año</td></tr>
                            <?php endif; ?>
                            <?php foreach ($top_clientes as $cli): ?>
                                <tr>
                                    <td><div class="fw-bold text-truncate" style="max-width: 150px;"><?= esc($cli['cliente']) ?></div><small class="text-muted"><?= (int) $cli['facturas'] ?> factura(s)</small></td>
                                    <td class="money"><?= dinero($cli['facturado']) ?></td>
                                    <td class="money <?= $cli['saldo'] > 0 ? 'text-danger' : 'text-success' ?>"><?= dinero($cli['saldo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($ver_finanzas): ?>
        <div class="row g-4 mb-5">
            <div class="col-12 col-lg-5">
                <div class="chart-box">
                    <h6 class="fw-bold mb-3 text-uppercase small">Recaudo del mes por forma de pago</h6>
                    <?php if ($recaudo_por_forma): ?>
                        <div class="chart-lienzo"><canvas id="formasPagoChart"></canvas></div>
                    <?php else: ?>
                        <div class="vacio-grafica"><div><i class="fas fa-hand-holding-usd fa-2x mb-2"></i><br>Sin recaudos aprobados en <?= $nombre_periodo ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12 col-lg-7">
                <div class="chart-box" style="overflow: auto;">
                    <h6 class="fw-bold mb-3 text-uppercase small">Entradas y salidas por forma de pago · <?= $nombre_periodo ?></h6>
                    <table class="table table-sm align-middle mb-0 tabla-conta" id="formasPagoDashboard">
                        <thead><tr><th>Forma de pago</th><th class="text-end">Recaudado</th><th class="text-end">Pagado</th><th class="text-end">Moneda extranjera</th></tr></thead>
                        <tbody>
                            <?php if (!$formas_pago): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Sin movimientos aprobados en el periodo</td></tr>
                            <?php endif; ?>
                            <?php foreach ($formas_pago as $fp): ?>
                                <tr data-metodo="<?= esc($fp['metodo_pago']) ?>">
                                    <td class="fw-bold"><?= esc($fp['metodo_pago']) ?></td>
                                    <td class="money"><?= dinero($fp['recaudado']) ?></td>
                                    <td class="money"><?= dinero($fp['pagado']) ?></td>
                                    <td class="money"><?= (float) $fp['usd_recibidos'] > 0 ? 'US$ ' . number_format((float) $fp['usd_recibidos'], 2, ',', '.') : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="estado_resultados.php?desde=<?= $inicio ?>&hasta=<?= $fin ?>#tablaFormasPago" class="small d-inline-block mt-2">Ver el detalle en el Estado de Resultados <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($ver_tienda): sincronizarPedidos($db); $tienda = resumenTienda($db, $inicio, $fin); ?>
        <h6 class="seccion-dashboard" id="seccionTienda"><i class="fas fa-store"></i> Tienda en línea</h6>
        <div class="row g-4 mb-4">
            <?php
            $tarjeta('Ventas de la tienda', dinero($tienda['ventas']), 'tienda_ventas', 'success', 'fa-cart-shopping', (int) $tienda['pagados'] . ' pedido(s) pagados en ' . $nombre_periodo);
            $tarjeta('Pedidos del mes', (string) (int) $tienda['pedidos'], 'tienda_pedidos', 'primary', 'fa-receipt', 'Ticket promedio ' . dinero($tienda['ticket_promedio']));
            $tarjeta('Pedidos por pagar', (string) (int) $tienda['por_pagar'], 'tienda_por_pagar', 'warning', 'fa-hourglass-half', 'Con la factura pendiente de pago');
            $tarjeta('Pedidos por atender', '<a href="tienda_pedidos.php" class="text-reset">' . (int) $tienda['por_atender'] . '</a>', 'tienda_por_atender', 'danger', 'fa-box-open',
                     (int) $tienda['agotados'] . ' producto(s) agotado(s) · ' . (int) $tienda['bajo_stock'] . ' con stock bajo');
            ?>
        </div>
        <?php endif; ?>

        <?php if ($ver_comercial || $ver_clientes || $ver_mesa): ?>
        <h6 class="seccion-dashboard"><i class="fas fa-users"></i> Operación comercial y de servicio</h6>
        <div class="row g-4 mb-4">
            <?php
            // Cada cifra se muestra solo a quien puede abrir el módulo del que sale: un cliente no tiene
            // por qué ver cuántos prospectos, cuántos clientes ni cuántos casos vencidos hay en la empresa
            if ($ver_comercial) $tarjeta('Solicitudes', (string) $total_mensajes, 'solicitudes', 'primary', 'fa-envelope-open-text', "$leads_sin_gestionar sin gestionar hace más de 48 h");
            if ($ver_clientes)  $tarjeta('Clientes', (string) $total_clientes, 'clientes', 'success', 'fa-user-check', 'Cuentas de clientes registradas');
            if ($ver_comercial) $tarjeta('Pendientes', (string) $pendientes, 'pendientes', 'warning', 'fa-stopwatch', 'Leads nuevos por atender');
            if ($ver_mesa)      $tarjeta('Tickets abiertos', (string) (int) $tickets['abiertos'], 'tickets_abiertos', 'info', 'fa-headset', (int) $tickets['vencidos'] . ' con ANS vencido');
            ?>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <?php if ($ver_finanzas): ?>
            <div class="col-12 col-xl-7">
                <div class="chart-box">
                    <h6 class="fw-bold mb-4 text-uppercase small">Flujo Mensual de Interacción</h6>
                    <div class="chart-lienzo"><canvas id="lineChart"></canvas></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-12 col-xl-5">
                <div class="chart-box">
                    <h6 class="fw-bold mb-4 text-uppercase small text-center">Alcance por Sector</h6>
                    <div class="chart-lienzo"><canvas id="polarChart"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="chart-box">
                    <h6 class="fw-bold mb-4 text-uppercase small">Servicios con Mayor Demanda</h6>
                    <div class="chart-lienzo"><canvas id="barHorizChart"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="chart-box">
                    <h6 class="fw-bold mb-4 text-uppercase small">Rendimiento Operativo</h6>
                    <div class="chart-lienzo"><canvas id="radarChart"></canvas></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const colors = ['#0A2E4F', '#3FAE49', '#0B4F8A', '#F1C40F', '#E74C3C'];
const pesos = v => '$ ' + Math.round(v).toLocaleString('es-CO');
const abreviar = v => Math.abs(v) >= 1e6 ? '$ ' + (v / 1e6).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' M' : pesos(v);
const tooltipPesos = { callbacks: { label: c => `${c.dataset.label || c.label}: ${pesos(c.parsed.y ?? c.parsed)}` } };

// 1. LÍNEA: solicitudes recibidas
new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels: <?= jsJson($etiquetas_serie) ?>,
        datasets: [{ label: 'Solicitudes', data: <?= jsJson($flujo_totales) ?>, borderColor: '#3FAE49', backgroundColor: 'rgba(63, 174, 73, 0.1)', fill: true, tension: 0.4 }]
    },
    options: { maintainAspectRatio: false, responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

// 2. POLAR AREA: sectores
new Chart(document.getElementById('polarChart'), {
    type: 'polarArea',
    data: {
        labels: <?= jsJson(array_column($res_sectores, 'tipo_cliente')) ?>,
        datasets: [{ data: <?= jsJson(array_map('intval', array_column($res_sectores, 'total'))) ?>, backgroundColor: colors }]
    },
    options: { maintainAspectRatio: false, responsive: true, scales: { r: { ticks: { display: false } } } }
});

// 3. BARRA HORIZONTAL: servicios más pedidos
new Chart(document.getElementById('barHorizChart'), {
    type: 'bar',
    data: {
        labels: <?= jsJson(array_map('nombreServicio', array_column($res_servicios, 'servicio'))) ?>,
        datasets: [{ label: 'Solicitudes', data: <?= jsJson(array_map('intval', array_column($res_servicios, 'total'))) ?>, backgroundColor: '#0B4F8A' }]
    },
    options: { indexAxis: 'y', maintainAspectRatio: false, responsive: true, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
});

// 4. RADAR: indicadores operativos
new Chart(document.getElementById('radarChart'), {
    type: 'radar',
    data: {
        labels: <?= jsJson(array_keys($indicadores)) ?>,
        datasets: [{ label: '% de cumplimiento', data: <?= jsJson(array_values($indicadores)) ?>, borderColor: '#3FAE49', backgroundColor: 'rgba(63, 174, 73, 0.2)' }]
    },
    options: { maintainAspectRatio: false, responsive: true, scales: { r: { ticks: { display: false }, min: 0, max: 100 } } }
});

<?php if ($ver_finanzas): ?>
// 5. FINANZAS: facturado, recaudado y pagado con la línea de flujo neto
new Chart(document.getElementById('finChart'), {
    type: 'bar',
    data: {
        labels: <?= jsJson($etiquetas_serie) ?>,
        datasets: [
            { label: 'Facturado', data: <?= jsJson($serie_facturado) ?>, backgroundColor: 'rgba(11, 79, 138, 0.85)', borderRadius: 6 },
            { label: 'Recaudado', data: <?= jsJson($serie_recaudado) ?>, backgroundColor: 'rgba(63, 174, 73, 0.85)', borderRadius: 6 },
            { label: 'Egresos pagados', data: <?= jsJson($serie_pagado) ?>, backgroundColor: 'rgba(231, 76, 60, 0.8)', borderRadius: 6 },
            { type: 'line', label: 'Flujo neto', data: <?= jsJson($serie_flujo) ?>, borderColor: '#F1C40F', backgroundColor: '#F1C40F', tension: 0.35, pointRadius: 4 }
        ]
    },
    options: { maintainAspectRatio: false, responsive: true, plugins: { tooltip: tooltipPesos }, scales: { y: { ticks: { callback: abreviar } } } }
});

<?php if ($recaudo_por_forma): ?>
// 8. RECAUDO POR FORMA DE PAGO
new Chart(document.getElementById('formasPagoChart'), {
    type: 'doughnut',
    data: {
        labels: <?= jsJson(array_keys($recaudo_por_forma)) ?>,
        datasets: [{ data: <?= jsJson(array_map('round', array_values($recaudo_por_forma))) ?>, backgroundColor: ['#3FAE49', '#0B4F8A', '#003087', '#F1C40F', '#8E44AD', '#16A085', '#E67E22', '#E74C3C'] }]
    },
    options: { maintainAspectRatio: false, responsive: true, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } }, tooltip: { callbacks: { label: c => `${c.label}: ${pesos(c.parsed)}` } } } }
});
<?php endif; ?>

<?php if ($cartera_total > 0): ?>
// 6. CARTERA POR EDADES
new Chart(document.getElementById('carteraChart'), {
    type: 'doughnut',
    data: {
        labels: <?= jsJson(array_keys($edades)) ?>,
        datasets: [{ data: <?= jsJson(array_map('round', array_values($edades))) ?>, backgroundColor: ['#3FAE49', '#F1C40F', '#F39C12', '#E67E22', '#E74C3C'] }]
    },
    options: { maintainAspectRatio: false, responsive: true, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } }, tooltip: { callbacks: { label: c => `${c.label}: ${pesos(c.parsed)}` } } } }
});
<?php endif; ?>

<?php if ($gastos_cuenta): ?>
// 7. GASTOS DEL MES POR CUENTA
new Chart(document.getElementById('gastosChart'), {
    type: 'doughnut',
    data: {
        labels: <?= jsJson(array_keys($gastos_cuenta)) ?>,
        datasets: [{ data: <?= jsJson(array_map('round', array_map('floatval', array_values($gastos_cuenta)))) ?>, backgroundColor: ['#0A2E4F', '#0B4F8A', '#3FAE49', '#F1C40F', '#E74C3C', '#8E44AD', '#16A085'] }]
    },
    options: { maintainAspectRatio: false, responsive: true, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } }, tooltip: { callbacks: { label: c => `${c.label}: ${pesos(c.parsed)}` } } } }
});
<?php endif; ?>
<?php endif; ?>
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
