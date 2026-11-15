<?php
/**
 * CONCILIACIÓN BANCARIA - DATASENSE
 * Extracto del banco contra el libro de la cuenta (solo movimientos aprobados):
 * saldo según extracto = saldo según libro − partidas del libro sin conciliar + partidas del extracto sin registrar.
 * Una línea del libro se concilia una sola vez (índice único en extracto_movimientos.detalle_id).
 */
require_once __DIR__ . '/contabilidad.php';

// Cuentas de bancos y billeteras que se concilian (todo el disponible menos la caja)
function cuentasConciliables(PDO $db) {
    $cuentas = [];
    foreach (nombresCuentas($db) as $codigo => $nombre) {
        $codigo = (string) $codigo;
        if (strlen($codigo) === 6 && substr($codigo, 0, 2) === '11' && substr($codigo, 0, 4) !== '1105') $cuentas[$codigo] = $nombre;
    }
    return $cuentas;
}

function finDePeriodo($periodo) {
    return date('Y-m-t', strtotime($periodo . '-01'));
}

// "1.234.567,89", "1234567.89", "-50.000", "(15.000)", "$ 1,200.50" => número; null si no se puede leer
function numeroExtracto($texto) {
    $t = preg_replace('/[\s$]/u', '', (string) $texto);
    $negativo = false;
    if (preg_match('/^\((.+)\)$/', $t, $m)) { $t = $m[1]; $negativo = true; }
    if ($t !== '' && ($t[0] === '-' || $t[0] === '+')) {
        $negativo = ($negativo xor ($t[0] === '-')); // Con paréntesis: "xor" tiene menor precedencia que "="
        $t = substr($t, 1);
    }
    if ($t === '' || !preg_match('/^[0-9.,]+$/', $t)) return null;

    $punto = strrpos($t, '.');
    $coma = strrpos($t, ',');
    if ($punto !== false && $coma !== false) {
        $decimal = $punto > $coma ? '.' : ','; // El último separador es el decimal
    } elseif ($coma !== false || $punto !== false) {
        // Un solo tipo de separador: es decimal si aparece una vez con 1 o 2 dígitos después (1,5 / 1.50); si no, es de miles
        $separador = $coma !== false ? ',' : '.';
        $posicion = $coma !== false ? $coma : $punto;
        $decimal = substr_count($t, $separador) === 1 && strlen($t) - $posicion - 1 <= 2 ? $separador : null;
    } else {
        $decimal = null;
    }
    if ($decimal === null) {
        $t = str_replace(['.', ','], '', $t);
    } else {
        $t = str_replace($decimal === ',' ? '.' : ',', '', $t);
        $t = str_replace($decimal, '.', $t);
    }
    return is_numeric($t) ? round(($negativo ? -1 : 1) * (float) $t, 2) : null;
}

// "2026-09-14", "14/09/2026" o "14-09-2026" => "2026-09-14"; null si no es una fecha válida
function fechaExtracto($texto) {
    $t = trim((string) $texto);
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $t, $m)) {
        $fecha = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    } elseif (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $t, $m)) {
        $fecha = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    } else {
        return null;
    }
    return fechaValida($fecha) ? $fecha : null;
}

/**
 * Lee un extracto en CSV (separado por ; o ,). Encabezados: fecha, descripcion, referencia (opcional) y
 * valor (positivo entra al banco, negativo sale) o debito/credito (débito sale, crédito entra).
 * Devuelve [movimientos, errores]; cada movimiento es [fecha, descripcion, referencia, valor].
 */
function leerExtractoCsv($ruta, $max_filas = 2000) {
    $contenido = @file_get_contents($ruta);
    if ($contenido === false) return [[], ['No se pudo leer el archivo.']];
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    if (!mb_check_encoding($contenido, 'UTF-8')) $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
    $lineas = preg_split('/\r\n|\r|\n/', trim($contenido));
    if (count($lineas) < 2) return [[], ['El archivo no tiene movimientos: la primera fila debe tener los encabezados.']];

    $separador = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
    $normalizar = function ($texto) {
        return strtr(mb_strtolower(trim($texto), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    };
    $encabezados = array_map($normalizar, str_getcsv($lineas[0], $separador));
    $columna = function (array $nombres) use ($encabezados) {
        foreach ($nombres as $nombre) {
            $i = array_search($nombre, $encabezados, true);
            if ($i !== false) return $i;
        }
        return null;
    };
    $c_fecha = $columna(['fecha']);
    $c_descripcion = $columna(['descripcion', 'concepto', 'detalle']);
    $c_referencia = $columna(['referencia', 'documento', 'ref']);
    $c_valor = $columna(['valor', 'monto', 'importe']);
    $c_debito = $columna(['debito', 'debitos', 'retiros']);
    $c_credito = $columna(['credito', 'creditos', 'consignaciones', 'depositos']);
    if ($c_fecha === null || $c_descripcion === null || ($c_valor === null && ($c_debito === null || $c_credito === null))) {
        return [[], ['El archivo debe tener las columnas fecha, descripcion y valor (o debito y credito).']];
    }

    $movimientos = [];
    $errores = [];
    foreach (array_slice($lineas, 1) as $n => $linea) {
        if (trim($linea) === '') continue;
        if (count($movimientos) >= $max_filas) {
            $errores[] = "Se leyeron solo las primeras $max_filas filas.";
            break;
        }
        $celdas = str_getcsv($linea, $separador);
        $fecha = fechaExtracto($celdas[$c_fecha] ?? '');
        $descripcion = trim((string) ($celdas[$c_descripcion] ?? ''));
        if ($c_valor !== null) {
            $valor = numeroExtracto($celdas[$c_valor] ?? '');
        } else {
            $debito = numeroExtracto($celdas[$c_debito] ?? '');
            $credito = numeroExtracto($celdas[$c_credito] ?? '');
            $valor = ($debito === null && $credito === null) ? null : round(abs((float) $credito) - abs((float) $debito), 2);
        }
        if (!$fecha || $descripcion === '' || $valor === null || abs($valor) < 0.005) {
            $errores[] = 'Fila ' . ($n + 2) . ': fecha, descripción o valor no válidos.';
            continue;
        }
        $referencia = $c_referencia !== null ? trim((string) ($celdas[$c_referencia] ?? '')) : '';
        $movimientos[] = [$fecha, mb_substr($descripcion, 0, 255), mb_substr($referencia, 0, 100), $valor];
    }
    return [$movimientos, $errores];
}

// Libro, extracto, partidas pendientes y diferencia de una conciliación
function datosConciliacion(PDO $db, array $conciliacion) {
    $fin = finDePeriodo($conciliacion['periodo']);
    $saldo = $db->prepare("SELECT COALESCE(SUM(d.debito - d.credito), 0) FROM comprobantes_detalle d JOIN comprobantes c ON c.id = d.comprobante_id
                           WHERE c.estado = 'Aprobado' AND d.codigo_puc = ? AND c.fecha <= ?");
    $saldo->execute([$conciliacion['cuenta'], $fin]);
    $saldo_libro = round((float) $saldo->fetchColumn(), 2);

    // Movimientos del libro hasta el fin del periodo que no quedaron conciliados en otra conciliación
    $libro = $db->prepare("SELECT d.id, c.id AS comprobante_id, c.tipo, c.numero, c.fecha, c.tercero_nombre, c.referencia, d.detalle,
                                  ROUND(d.debito - d.credito, 2) AS valor, e.id AS extracto_id
                           FROM comprobantes_detalle d
                           JOIN comprobantes c ON c.id = d.comprobante_id
                           LEFT JOIN extracto_movimientos e ON e.detalle_id = d.id
                           WHERE c.estado = 'Aprobado' AND d.codigo_puc = ? AND c.fecha <= ? AND (e.id IS NULL OR e.conciliacion_id = ?)
                           ORDER BY c.fecha, d.id");
    $libro->execute([$conciliacion['cuenta'], $fin, $conciliacion['id']]);
    $movimientos_libro = $libro->fetchAll(PDO::FETCH_ASSOC);

    $extracto = $db->prepare("SELECT e.*, c.numero AS numero_libro, c.tipo AS tipo_libro, c.id AS comprobante_libro
                              FROM extracto_movimientos e
                              LEFT JOIN comprobantes_detalle d ON d.id = e.detalle_id
                              LEFT JOIN comprobantes c ON c.id = d.comprobante_id
                              WHERE e.conciliacion_id = ?
                              ORDER BY e.fecha, e.id");
    $extracto->execute([$conciliacion['id']]);
    $movimientos_extracto = $extracto->fetchAll(PDO::FETCH_ASSOC);

    $transito = 0.0; $por_cobrar = 0.0; $pendiente_extracto = 0.0;
    foreach ($movimientos_libro as $m) {
        if ($m['extracto_id']) continue;
        if ((float) $m['valor'] > 0) $transito += (float) $m['valor']; else $por_cobrar += (float) $m['valor'];
    }
    foreach ($movimientos_extracto as $m) {
        if (!$m['detalle_id']) $pendiente_extracto += (float) $m['valor'];
    }
    $calculado = round($saldo_libro - $transito - $por_cobrar + $pendiente_extracto, 2);
    return [
        'fin' => $fin, 'saldo_libro' => $saldo_libro, 'libro' => $movimientos_libro, 'extracto' => $movimientos_extracto,
        'transito' => round($transito, 2), 'por_cobrar' => round($por_cobrar, 2), 'pendiente_extracto' => round($pendiente_extracto, 2),
        'calculado' => $calculado,
        'diferencia' => $conciliacion['saldo_extracto'] === null ? null : round((float) $conciliacion['saldo_extracto'] - $calculado, 2),
    ];
}

// Empareja movimientos pendientes por el mismo valor: primero los de igual referencia y luego el más cercano en fecha (máximo 5 días)
function autoConciliar(PDO $db, array $conciliacion, $usuario_id) {
    $datos = datosConciliacion($db, $conciliacion);
    $libres = array_values(array_filter($datos['libro'], function ($m) { return !$m['extracto_id']; }));
    $usados = [];
    $emparejados = 0;
    $actualizar = $db->prepare("UPDATE extracto_movimientos SET detalle_id = ?, conciliado_por = ? WHERE id = ? AND detalle_id IS NULL");
    foreach ($datos['extracto'] as $e) {
        if ($e['detalle_id']) continue;
        $mejor = null;
        $mejor_puntaje = null;
        foreach ($libres as $i => $m) {
            if (isset($usados[$i]) || abs((float) $m['valor'] - (float) $e['valor']) > 0.005) continue;
            $dias = abs(strtotime($m['fecha']) - strtotime($e['fecha'])) / 86400;
            $misma_referencia = trim((string) $e['referencia']) !== '' && strcasecmp(trim((string) $e['referencia']), trim((string) $m['referencia'])) === 0;
            if (!$misma_referencia && $dias > 5) continue;
            $puntaje = ($misma_referencia ? 0 : 1000) + $dias;
            if ($mejor_puntaje === null || $puntaje < $mejor_puntaje) {
                $mejor = $i;
                $mejor_puntaje = $puntaje;
            }
        }
        if ($mejor !== null) {
            $actualizar->execute([$libres[$mejor]['id'], $usuario_id, $e['id']]);
            if ($actualizar->rowCount()) {
                $usados[$mejor] = true;
                $emparejados++;
            }
        }
    }
    return $emparejados;
}
