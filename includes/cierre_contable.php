<?php
/**
 * CIERRE CONTABLE - DATASENSE
 * - Los meses se cierran en orden y solo cuando terminaron y no tienen documentos sin aprobar.
 * - Con un mes cerrado no se registra, aprueba ni anula nada con fecha de ese mes (guardarComprobante y procesar_comprobante).
 * - Al cerrar diciembre se genera el comprobante de cierre (CC): ingresos, costos y gastos del año contra la utilidad
 *   del ejercicio (360505), y el 1 de enero el traslado a utilidades acumuladas (370505).
 * - Solo un Administrador reabre, con motivo y desde el último mes cerrado; reabrir diciembre anula su cierre anual.
 */
require_once __DIR__ . '/contabilidad.php';
require_once __DIR__ . '/reportes.php';

function nombreMes($mes) {
    $nombres = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $numero = (int) substr((string) $mes, 5, 2);
    return $numero >= 1 && $numero <= 12 ? $nombres[$numero - 1] . ' de ' . substr((string) $mes, 0, 4) : (string) $mes;
}

// Primer mes (AAAA-MM) con facturas o comprobantes vigentes
function primerMesContable(PDO $db) {
    $fecha = $db->query("SELECT MIN(fecha) FROM (SELECT MIN(fecha_emision) AS fecha FROM facturas WHERE estado <> 'Anulada'
                                                UNION ALL SELECT MIN(fecha) FROM comprobantes WHERE estado <> 'Anulado') f")->fetchColumn();
    return $fecha ? substr($fecha, 0, 7) : null;
}

// Meses desde el primero con documentos hasta el actual: estado, documentos, pendientes de aprobar y conciliaciones abiertas
function mesesContables(PDO $db) {
    $primero = primerMesContable($db) ?: date('Y-m');
    $registros = [];
    foreach ($db->query("SELECT p.*, u.nombre AS nombre_cierre, r.nombre AS nombre_reapertura FROM periodos_contables p
                         LEFT JOIN usuarios u ON u.id = p.cerrado_por LEFT JOIN usuarios r ON r.id = p.reabierto_por") as $p) {
        $registros[sprintf('%04d-%02d', $p['anio'], $p['mes'])] = $p;
    }
    $pendientes = $db->query("SELECT DATE_FORMAT(fecha, '%Y-%m'), COUNT(*) FROM comprobantes WHERE estado IN ('Pendiente', 'Revisado') GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    $documentos = $db->query("SELECT mes, SUM(n) FROM (
                                  SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, COUNT(*) AS n FROM comprobantes WHERE estado <> 'Anulado' GROUP BY 1
                                  UNION ALL SELECT DATE_FORMAT(fecha_emision, '%Y-%m'), COUNT(*) FROM facturas WHERE estado <> 'Anulada' GROUP BY 1
                              ) d GROUP BY mes")->fetchAll(PDO::FETCH_KEY_PAIR);
    $conciliaciones = $db->query("SELECT periodo, SUM(estado = 'Abierta') FROM conciliaciones GROUP BY periodo")->fetchAll(PDO::FETCH_KEY_PAIR);

    $meses = [];
    for ($mes = min($primero, date('Y-m')); $mes <= date('Y-m'); $mes = date('Y-m', strtotime($mes . '-01 +1 month'))) {
        $registro = $registros[$mes] ?? null;
        $meses[$mes] = [
            'mes' => $mes, 'cerrado' => $registro && $registro['estado'] === 'Cerrado', 'registro' => $registro,
            'documentos' => (int) ($documentos[$mes] ?? 0), 'pendientes' => (int) ($pendientes[$mes] ?? 0),
            'conciliaciones_abiertas' => (int) ($conciliaciones[$mes] ?? 0),
        ];
    }
    return $meses;
}

// null si el mes se puede cerrar; si no, el motivo
function errorCierreMes(PDO $db, $mes, $meses = null) {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes)) return 'Mes no válido.';
    if ($mes >= date('Y-m')) return 'Solo se cierran meses terminados.';
    $meses = $meses ?: mesesContables($db);
    if (!isset($meses[$mes])) return 'No hay movimientos contables en ese mes ni antes.';
    if ($meses[$mes]['cerrado']) return 'El mes ya está cerrado.';
    foreach ($meses as $clave => $m) {
        if ($clave >= $mes) break;
        if (!$m['cerrado']) return 'Primero cierra ' . nombreMes($clave) . ': los meses se cierran en orden.';
    }
    if ($meses[$mes]['pendientes'] > 0) {
        return 'Hay ' . $meses[$mes]['pendientes'] . ' documento(s) de ' . nombreMes($mes) . ' sin aprobar: apruébalos o anúlalos antes de cerrar.';
    }
    return null;
}

// Cierra el mes; si es diciembre genera el cierre anual. Devuelve los números de los comprobantes de cierre generados.
function cerrarMes(PDO $db, $mes, $usuario_id) {
    $error = errorCierreMes($db, $mes);
    if ($error) throw new InvalidArgumentException($error);
    $anio = (int) substr($mes, 0, 4);
    $numero_mes = (int) substr($mes, 5, 2);
    if ($numero_mes === 12) {
        asegurarConsecutivo($db, 'CC', $anio);
        asegurarConsecutivo($db, 'CC', $anio + 1);
    }
    $db->beginTransaction();
    try {
        $documentos = $numero_mes === 12 ? generarCierreAnual($db, $anio, $usuario_id) : [];
        $db->prepare("INSERT INTO periodos_contables (anio, mes, estado, cerrado_por, fecha_cierre) VALUES (?, ?, 'Cerrado', ?, NOW())
                      ON DUPLICATE KEY UPDATE estado = 'Cerrado', cerrado_por = VALUES(cerrado_por), fecha_cierre = NOW()")
           ->execute([$anio, $numero_mes, $usuario_id]);
        $db->commit();
        return $documentos;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Comprobantes de cierre del año (requiere transacción activa y los consecutivos CC del año y del siguiente)
function generarCierreAnual(PDO $db, $anio, $usuario_id) {
    $existe = $db->prepare("SELECT numero FROM comprobantes WHERE tipo = 'CC' AND estado = 'Aprobado' AND referencia = ? LIMIT 1");
    $existe->execute(["Cierre $anio"]);
    $numero_existente = $existe->fetchColumn();
    if ($numero_existente) throw new InvalidArgumentException("El año $anio ya tiene comprobante de cierre ($numero_existente).");

    $lineas = [];
    $utilidad = 0.0;
    foreach (saldosPorCuenta($db, "$anio-01-01", "$anio-12-31", 6, [4, 5, 6, 7], true) as $s) {
        $neto = round((float) $s['debitos'] - (float) $s['creditos'], 2);
        if (abs($neto) < 0.005) continue;
        $utilidad -= $neto;
        $lineas[] = [(string) $s['cuenta'], "Cierre de la cuenta al 31/12/$anio", $neto < 0 ? -$neto : 0, $neto > 0 ? $neto : 0];
    }
    if (!$lineas) return [];
    $utilidad = round($utilidad, 2);
    if (abs($utilidad) >= 0.005) {
        $lineas[] = [CTA_UTILIDAD_EJERCICIO, ($utilidad > 0 ? 'Utilidad' : 'Pérdida') . " del ejercicio $anio", $utilidad < 0 ? -$utilidad : 0, $utilidad > 0 ? $utilidad : 0];
    }

    $empresa = datosEmpresa();
    $comunes = [
        'tercero_id' => null, 'tercero_nombre' => $empresa['nombre'], 'tercero_documento' => normalizarDocumento($empresa['nit']),
        'metodo_pago' => 'No aplica', 'referencia' => "Cierre $anio", 'estado' => 'Aprobado', 'origen' => 'Cierre', 'preparado_por' => $usuario_id,
    ];
    $cierre = guardarComprobante($db, $comunes + [
        'tipo' => 'CC', 'fecha' => "$anio-12-31", 'concepto' => "Cierre de ingresos, costos y gastos del año $anio",
        'valor_total' => round(array_sum(array_column($lineas, 2)), 2), 'clase_nota' => 'Cierre del ejercicio',
    ], $lineas);
    $numeros = [$cierre['numero']];

    if (abs($utilidad) >= 0.005) {
        $traslado = $utilidad > 0
            ? [[CTA_UTILIDAD_EJERCICIO, "Traslado de la utilidad de $anio", $utilidad, 0], [CTA_UTILIDADES_ACUMULADAS, "Utilidad del ejercicio $anio", 0, $utilidad]]
            : [[CTA_UTILIDADES_ACUMULADAS, "Pérdida del ejercicio $anio", -$utilidad, 0], [CTA_UTILIDAD_EJERCICIO, "Traslado de la pérdida de $anio", 0, -$utilidad]];
        $documento = guardarComprobante($db, $comunes + [
            'tipo' => 'CC', 'fecha' => ($anio + 1) . '-01-01', 'concepto' => "Traslado del resultado de $anio a resultados acumulados",
            'valor_total' => abs($utilidad), 'clase_nota' => 'Traslado de resultados',
        ], $traslado);
        $numeros[] = $documento['numero'];
    }
    return $numeros;
}

// null si el usuario puede reabrir el mes; si no, el motivo
function errorReaperturaMes(PDO $db, $mes, array $usuario) {
    if ($usuario['rol'] !== 'Admin') return 'Solo un Administrador puede reabrir un mes cerrado.';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes)) return 'Mes no válido.';
    $anio = (int) substr($mes, 0, 4);
    $numero_mes = (int) substr($mes, 5, 2);
    $estado = $db->prepare("SELECT estado FROM periodos_contables WHERE anio = ? AND mes = ?");
    $estado->execute([$anio, $numero_mes]);
    if ($estado->fetchColumn() !== 'Cerrado') return 'El mes no está cerrado.';
    $posterior = $db->prepare("SELECT anio, mes FROM periodos_contables WHERE estado = 'Cerrado' AND (anio > ? OR (anio = ? AND mes > ?)) ORDER BY anio, mes LIMIT 1");
    $posterior->execute([$anio, $anio, $numero_mes]);
    $siguiente = $posterior->fetch(PDO::FETCH_ASSOC);
    if ($siguiente) return 'Primero reabre ' . nombreMes(sprintf('%04d-%02d', $siguiente['anio'], $siguiente['mes'])) . ': los meses se reabren desde el último cerrado.';
    return null;
}

// Reabre el mes; si es diciembre anula su cierre anual. Devuelve los números anulados.
function reabrirMes(PDO $db, $mes, array $usuario, $motivo) {
    $error = errorReaperturaMes($db, $mes, $usuario);
    if ($error) throw new InvalidArgumentException($error);
    $motivo = trim((string) $motivo);
    if (mb_strlen($motivo) < 10) throw new InvalidArgumentException('Escribe el motivo de la reapertura (mínimo 10 caracteres).');
    $anio = (int) substr($mes, 0, 4);
    $numero_mes = (int) substr($mes, 5, 2);

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE periodos_contables SET estado = 'Abierto', reabierto_por = ?, fecha_reapertura = NOW(), motivo_reapertura = ? WHERE anio = ? AND mes = ?")
           ->execute([$usuario['id'], mb_substr($motivo, 0, 255), $anio, $numero_mes]);
        $anulados = [];
        if ($numero_mes === 12) {
            // El cierre y el traslado se generan de nuevo al volver a cerrar diciembre
            $cierres = $db->prepare("SELECT id, numero FROM comprobantes WHERE tipo = 'CC' AND estado = 'Aprobado' AND referencia = ? FOR UPDATE");
            $cierres->execute(["Cierre $anio"]);
            $anular = $db->prepare("UPDATE comprobantes SET estado = 'Anulado', anulado_por = ?, fecha_anulacion = NOW(), motivo_anulacion = ? WHERE id = ?");
            foreach ($cierres->fetchAll(PDO::FETCH_ASSOC) as $cc) {
                $anular->execute([$usuario['id'], mb_substr("Reapertura de diciembre de $anio: $motivo", 0, 255), $cc['id']]);
                $anulados[] = $cc['numero'];
            }
        }
        $db->commit();
        return $anulados;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
