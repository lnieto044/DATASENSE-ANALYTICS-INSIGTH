<?php
/**
 * IMPUESTOS - DATASENSE
 * Liquidación de IVA (generado en facturas y notas, descontable en compras y gastos) y retenciones en la fuente
 * practicadas a terceros (2365) y que nos practicaron los clientes (135515). Todo sale de las facturas no anuladas
 * y de los documentos aprobados, sin los comprobantes de cierre anual.
 */
require_once __DIR__ . '/contabilidad.php';
require_once __DIR__ . '/reportes.php';

const NOMBRES_PERIODOS_IVA = [
    'bimestral'     => ['Enero - Febrero', 'Marzo - Abril', 'Mayo - Junio', 'Julio - Agosto', 'Septiembre - Octubre', 'Noviembre - Diciembre'],
    'cuatrimestral' => ['Enero - Abril', 'Mayo - Agosto', 'Septiembre - Diciembre'],
];

// [desde, hasta] de un periodo de IVA bimestral (1 a 6) o cuatrimestral (1 a 3)
function periodoIva($anio, $periodicidad, $numero) {
    $meses = $periodicidad === 'cuatrimestral' ? 4 : 2;
    $inicio = sprintf('%04d-%02d-01', (int) $anio, ((int) $numero - 1) * $meses + 1);
    return [$inicio, date('Y-m-t', strtotime("$inicio +" . ($meses - 1) . ' month'))];
}

function liquidacionIva(PDO $db, $desde, $hasta) {
    $base = "CASE WHEN f.subtotal > 0 THEN f.subtotal ELSE ROUND(f.monto_total / (1 + f.porcentaje_iva / 100), 2) END";
    $stmt = $db->prepare("SELECT f.porcentaje_iva AS tarifa, COUNT(*) AS documentos, COALESCE(SUM($base), 0) AS base, COALESCE(SUM(f.monto_total - $base), 0) AS iva
                          FROM facturas f
                          WHERE f.estado <> 'Anulada' AND f.fecha_emision BETWEEN ? AND ?
                          GROUP BY f.porcentaje_iva
                          ORDER BY f.porcentaje_iva DESC");
    $stmt->execute([$desde, $hasta]);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Documentos aprobados que movieron IVA: notas de factura, compras, gastos y ajustes
    $stmt = $db->prepare("SELECT c.id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento,
                                 SUM(CASE WHEN d.codigo_puc = ? THEN d.credito - d.debito ELSE 0 END) AS iva_generado,
                                 SUM(CASE WHEN d.codigo_puc = ? THEN d.debito - d.credito ELSE 0 END) AS iva_descontable,
                                 SUM(CASE WHEN d.codigo_puc LIKE '5%' OR d.codigo_puc LIKE '6%' OR d.codigo_puc LIKE '15%' THEN d.debito - d.credito ELSE 0 END) AS base_compra
                          FROM comprobantes c
                          JOIN comprobantes_detalle d ON d.comprobante_id = c.id
                          WHERE c.estado = 'Aprobado' AND c.tipo <> 'CC' AND c.fecha BETWEEN ? AND ?
                          GROUP BY c.id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento
                          HAVING ABS(iva_generado) >= 0.005 OR ABS(iva_descontable) >= 0.005
                          ORDER BY c.fecha, c.id");
    $stmt->execute([CTA_IVA_GENERADO, CTA_IVA_DESCONTABLE, $desde, $hasta]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $r = ['ventas' => $ventas, 'documentos' => $documentos, 'base_facturas' => 0.0, 'iva_facturas' => 0.0, 'iva_notas_credito' => 0.0,
          'iva_notas_debito' => 0.0, 'iva_ajustes' => 0.0, 'iva_descontable' => 0.0, 'base_compras' => 0.0, 'documentos_compra' => 0];
    foreach ($ventas as $v) {
        $r['base_facturas'] += (float) $v['base'];
        $r['iva_facturas'] += (float) $v['iva'];
    }
    foreach ($documentos as $d) {
        $generado = (float) $d['iva_generado'];
        if ($d['tipo'] === 'NCV') $r['iva_notas_credito'] -= $generado;
        elseif ($d['tipo'] === 'NDV') $r['iva_notas_debito'] += $generado;
        else $r['iva_ajustes'] += $generado;
        if (abs((float) $d['iva_descontable']) >= 0.005) {
            $r['iva_descontable'] += (float) $d['iva_descontable'];
            $r['base_compras'] += (float) $d['base_compra'];
            $r['documentos_compra']++;
        }
    }
    foreach (['base_facturas', 'iva_facturas', 'iva_notas_credito', 'iva_notas_debito', 'iva_ajustes', 'iva_descontable', 'base_compras'] as $campo) {
        $r[$campo] = round($r[$campo], 2);
    }
    $r['iva_generado'] = round($r['iva_facturas'] - $r['iva_notas_credito'] + $r['iva_notas_debito'] + $r['iva_ajustes'], 2);
    $r['saldo'] = round($r['iva_generado'] - $r['iva_descontable'], 2);

    // Control: lo mismo debe salir del movimiento de las cuentas de IVA en el libro
    $control = $db->prepare("SELECT COALESCE(SUM(CASE WHEN m.cuenta = ? THEN m.credito - m.debito END), 0) AS generado,
                                    COALESCE(SUM(CASE WHEN m.cuenta = ? THEN m.debito - m.credito END), 0) AS descontable
                             FROM (" . sqlMovimientos(false, true) . ") m
                             WHERE m.fecha BETWEEN ? AND ?");
    $control->execute([CTA_IVA_GENERADO, CTA_IVA_DESCONTABLE, $desde, $hasta]);
    $libro = $control->fetch(PDO::FETCH_ASSOC);
    $r['libro_generado'] = round((float) $libro['generado'], 2);
    $r['libro_descontable'] = round((float) $libro['descontable'], 2);
    $r['cuadra'] = abs($r['libro_generado'] - $r['iva_generado']) < 0.01 && abs($r['libro_descontable'] - $r['iva_descontable']) < 0.01;
    return $r;
}

// Retenciones en la fuente practicadas (cuentas 2365) por documento y concepto, con la base del gasto, costo o activo
function retencionesPracticadas(PDO $db, $desde, $hasta, $documento = null) {
    $sql = "SELECT c.id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento, d.codigo_puc AS cuenta, COALESCE(p.nombre, d.codigo_puc) AS concepto,
                   SUM(d.credito - d.debito) AS retenido,
                   (SELECT COALESCE(SUM(b.debito - b.credito), 0) FROM comprobantes_detalle b
                    WHERE b.comprobante_id = c.id AND (b.codigo_puc LIKE '5%' OR b.codigo_puc LIKE '6%' OR b.codigo_puc LIKE '15%')) AS base
            FROM comprobantes c
            JOIN comprobantes_detalle d ON d.comprobante_id = c.id AND d.codigo_puc LIKE '2365%'
            LEFT JOIN puc_cuentas p ON p.codigo = d.codigo_puc
            WHERE c.estado = 'Aprobado' AND c.tipo <> 'CC' AND c.fecha BETWEEN ? AND ?" . ($documento !== null ? ' AND c.tercero_documento = ?' : '') . "
            GROUP BY c.id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento, d.codigo_puc, p.nombre
            HAVING retenido >= 0.005
            ORDER BY c.fecha, c.id";
    $stmt = $db->prepare($sql);
    $stmt->execute($documento !== null ? [$desde, $hasta, $documento] : [$desde, $hasta]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Retenciones que los clientes le practicaron a la empresa al pagar (anticipo de impuestos 135515)
function retencionesQueNosPracticaron(PDO $db, $desde, $hasta) {
    $stmt = $db->prepare("SELECT c.id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento, SUM(d.debito - d.credito) AS valor
                          FROM comprobantes c
                          JOIN comprobantes_detalle d ON d.comprobante_id = c.id AND d.codigo_puc = ?
                          WHERE c.estado = 'Aprobado' AND c.tipo <> 'CC' AND c.fecha BETWEEN ? AND ?
                          GROUP BY c.id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.tercero_documento
                          HAVING valor >= 0.005
                          ORDER BY c.fecha, c.id");
    $stmt->execute([CTA_ANTICIPO_RETENCION, $desde, $hasta]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Créditos a las cuentas de retención (2365) en el libro, para el control del reporte de retenciones
function retencionesSegunLibro(PDO $db, $desde, $hasta) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(m.credito), 0) FROM (" . sqlMovimientos(false, true) . ") m WHERE m.cuenta LIKE '2365%' AND m.fecha BETWEEN ? AND ?");
    $stmt->execute([$desde, $hasta]);
    return round((float) $stmt->fetchColumn(), 2);
}
