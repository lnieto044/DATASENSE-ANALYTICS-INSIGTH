<?php
/**
 * Piezas comunes de los estados financieros: datos de la empresa, encabezado, firmas, variaciones y exportación CSV.
 * Datos opcionales del .env: EMPRESA_NOMBRE, EMPRESA_NIT, EMPRESA_CIUDAD, EMPRESA_REPRESENTANTE, EMPRESA_CONTADOR, EMPRESA_CONTADOR_TP.
 */
require_once __DIR__ . '/contabilidad.php';

function datosEmpresa() {
    return [
        'nombre'        => $_ENV['EMPRESA_NOMBRE'] ?? 'DataSense Analytics S.A.S.',
        'nit'           => $_ENV['EMPRESA_NIT'] ?? '900.000.000-1',
        'ciudad'        => $_ENV['EMPRESA_CIUDAD'] ?? 'Bogotá, Colombia',
        'representante' => $_ENV['EMPRESA_REPRESENTANTE'] ?? '',
        'contador'      => $_ENV['EMPRESA_CONTADOR'] ?? '',
        'tp'            => $_ENV['EMPRESA_CONTADOR_TP'] ?? '',
    ];
}

function encabezadoReporte($titulo, $detalle, $nota = 'Cifras en pesos colombianos (COP)') {
    $e = datosEmpresa();
    echo '<div class="reporte-encabezado"><img src="../img/logo.png" alt="DataSense Analytics">'
       . '<div class="text-end small text-muted"><b class="text-dark">' . esc(mb_strtoupper($e['nombre'], 'UTF-8')) . '</b><br>NIT ' . esc($e['nit']) . '<br>' . esc($e['ciudad']) . '</div></div>'
       . '<div class="reporte-titulo"><h2>' . esc($titulo) . '</h2><div class="small fw-bold">' . esc($detalle) . '</div>'
       . ($nota !== '' ? '<div class="small text-muted">' . esc($nota) . '</div>' : '') . '</div>';
}

function firmasReporte() {
    $e = datosEmpresa();
    $firma = function ($nombre, $cargo) {
        return '<div><div class="linea"></div><div class="fw-bold text-uppercase small">' . ($nombre !== '' ? esc($nombre) : '&nbsp;') . '</div>'
             . '<div class="small text-muted">' . esc($cargo) . '</div></div>';
    };
    echo '<div class="reporte-firmas">' . $firma($e['representante'], 'Representante legal')
       . $firma($e['contador'], 'Contador público' . ($e['tp'] !== '' ? ' · T.P. ' . $e['tp'] : '')) . '</div>';
}

function notaReporte() {
    echo '<div class="reporte-nota">Cifras tomadas de las facturas de venta y de los comprobantes de ingreso y egreso <b>aprobados</b> en DataSense '
       . '(los pendientes, revisados y anulados no se incluyen). Documento generado el ' . date('d/m/Y h:i A')
       . '; para efectos legales requiere la revisión y firma del contador público.</div>';
}

function variacionPorcentual($actual, $anterior) {
    return abs((float) $anterior) >= 0.01 ? ((float) $actual - (float) $anterior) * 100 / abs((float) $anterior) : null;
}

function textoVariacion($pct) {
    if ($pct === null) return '<span class="text-muted">—</span>';
    return '<span class="' . ($pct >= 0 ? 'text-success' : 'text-danger') . '">' . ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%</span>';
}

// Descarga un CSV para Excel en español: separador ";", BOM UTF-8, números con coma decimal y texto protegido contra fórmulas
function enviarCsv($nombre_archivo, array $filas) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre_archivo) . '"');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    foreach ($filas as $fila) {
        fputcsv($salida, array_map(function ($valor) {
            if (is_float($valor) || is_int($valor)) return number_format((float) $valor, 2, ',', '');
            $valor = (string) $valor;
            return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
        }, $fila), ';');
    }
    fclose($salida);
    exit;
}
