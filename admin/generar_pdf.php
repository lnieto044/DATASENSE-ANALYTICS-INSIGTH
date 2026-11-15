<?php
// 1. BLINDAJE
ob_start(); 
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/contabilidad.php';

$ruta_db = __DIR__ . '/../config/database.php';
$ruta_fpdf = __DIR__ . '/../libs/fpdf.php';

if (!file_exists($ruta_db) || !file_exists($ruta_fpdf)) { die("Error de rutas."); }

$db = require $ruta_db;
require $ruta_fpdf; 

$usuario = requiereSesion($db);

if (!isset($_GET['id'])) { die("Error: No se especificó ninguna factura."); }
$id_factura = (int) $_GET['id'];

// OBTENER LA FACTURA Y EL CLIENTE
// NOTA: Como ahora usamos la tabla de usuarios, actualizamos el JOIN para que no falle.
$sql = "SELECT f.*, u.nombre as cliente_nombre, u.organizacion, u.correo, u.telefono 
        FROM facturas f 
        JOIN usuarios u ON f.id_usuario = u.id 
        WHERE f.id = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$id_factura]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) { die("Error: La factura no existe o se rompió la relación."); }

// Quien gestiona facturación ve todas las facturas; el resto de usuarios solo las propias
if (!tieneAcceso($usuario['rol'], 'facturacion', $db) && (int) $factura['id_usuario'] !== (int) $usuario['id']) {
    die("Error: Acceso denegado.");
}

// --- LÓGICA DE RETRO-COMPATIBILIDAD PARA FACTURAS VIEJAS ---
$subtotal_factura = (!empty($factura['subtotal']) && $factura['subtotal'] > 0) ? $factura['subtotal'] : $factura['monto_total'];
$porcentaje_iva = $factura['porcentaje_iva'] ?? 0;
$valor_iva = $subtotal_factura * ($porcentaje_iva / 100);
$total_pagar = $factura['monto_total'];
// Abonos confirmados (comprobantes de ingreso aprobados) y saldo pendiente
$abonado = aplicadoFactura($db, $id_factura)['aprobado'];
$saldo_pendiente = max(0, (float) $total_pagar - $abonado);
// -----------------------------------------------------------

class PDF extends FPDF {
    function Header() {
        $this->SetFillColor(63, 174, 73); 
        $this->Rect(0, 0, 210, 4, 'F');
        if(file_exists('../img/logo.png')){
            $this->Image('../img/logo.png', 15, 12, 55); 
        }
        $this->SetXY(110, 15);
        $this->SetFont('Arial', 'B', 28);
        $this->SetTextColor(10, 46, 79);
        $this->Cell(85, 10, 'FACTURA', 0, 1, 'R');
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.4);
        $this->Line(15, 32, 195, 32);
        $this->Ln(15);
    }
    
    function Footer() {
        $this->SetY(-35);
        $this->SetDrawColor(220, 220, 220);
        $this->Line(15, 262, 195, 262);
        $this->SetY(-28);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(10, 46, 79);
        $this->Cell(0, 5, 'GRACIAS POR CONFIAR EN DATASENSE', 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, textoPdf('Este documento es una representación gráfica de facturación.'), 0, 1, 'C');
        $this->Cell(0, 5, textoPdf('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }

    // =========================================================================
    // FUNCIÓN PARA DIBUJAR CÓDIGO DE BARRAS (Code39)
    // =========================================================================
    function Code39($xpos, $ypos, $code, $baseline=0.5, $height=5){
        $wide = $baseline;
        $narrow = $baseline / 3 ; 
        $gap = $narrow;

        $barChar['0'] = 'nnnwwnwnn'; $barChar['1'] = 'wnnwnnnnw'; $barChar['2'] = 'nnwwnnnnw';
        $barChar['3'] = 'wnwwnnnnn'; $barChar['4'] = 'nnnwwnnnw'; $barChar['5'] = 'wnnwwnnnn';
        $barChar['6'] = 'nnwwwnnnn'; $barChar['7'] = 'nnnwnnwnw'; $barChar['8'] = 'wnnwnnwnn';
        $barChar['9'] = 'nnwwnnwnn'; $barChar['A'] = 'wnnnnwnnw'; $barChar['B'] = 'nnwnnwnnw';
        $barChar['C'] = 'wnwnnwnnn'; $barChar['D'] = 'nnnnwwnnw'; $barChar['E'] = 'wnnnwwnnn';
        $barChar['F'] = 'nnwnwwnnn'; $barChar['G'] = 'nnnnnwwnw'; $barChar['H'] = 'wnnnnwwnn';
        $barChar['I'] = 'nnwnnwwnn'; $barChar['J'] = 'nnnnwwwnn'; $barChar['K'] = 'wnnnnnnww';
        $barChar['L'] = 'nnwnnnnww'; $barChar['M'] = 'wnwnnnnwn'; $barChar['N'] = 'nnnnwnnww';
        $barChar['O'] = 'wnnnwnnwn'; $barChar['P'] = 'nnwnwnnwn'; $barChar['Q'] = 'nnnnnnwww';
        $barChar['R'] = 'wnnnnnwwn'; $barChar['S'] = 'nnwnnnwwn'; $barChar['T'] = 'nnnnwnwwn';
        $barChar['U'] = 'wwnnnnnnw'; $barChar['V'] = 'nwwnnnnnw'; $barChar['W'] = 'wwwnnnnnn';
        $barChar['X'] = 'nwnnwnnnw'; $barChar['Y'] = 'wwnnwnnnn'; $barChar['Z'] = 'nwwnwnnnn';
        $barChar['-'] = 'nwnnnnwnw'; $barChar['.'] = 'wwnnnnwnn'; $barChar[' '] = 'nwwnnnwnn';
        $barChar['*'] = 'nwnnwnwnn'; $barChar['$'] = 'nwnwnwnnn'; $barChar['/'] = 'nwnwnnnwn';
        $barChar['+'] = 'nwnnnwnwn'; $barChar['%'] = 'nnnwnwnwn';

        $this->SetFont('Arial','',10);
        $this->SetFillColor(0);

        $code = '*'.strtoupper($code).'*';
        for($i=0; $i<strlen($code); $i++){
            $char = $code[$i];
            if(!isset($barChar[$char])) $this->Error('Carácter inválido en el código de barras: '.$char);
            $seq = $barChar[$char];
            for($bar=0; $bar<9; $bar++){
                if($seq[$bar] == 'n'){ $lineWidth = $narrow; } else { $lineWidth = $wide; }
                if($bar % 2 == 0){ $this->Rect($xpos, $ypos, $lineWidth, $height, 'F'); }
                $xpos += $lineWidth;
            }
            $xpos += $gap;
        }
    }
}

$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// BLOQUE 1: GRILLA
$pdf->SetY(40);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(150, 150, 150);
$pdf->SetX(15); $pdf->Cell(60, 6, 'EMITIDO POR:', 0, 0, 'L');
$pdf->SetX(75); $pdf->Cell(65, 6, 'FACTURAR A:', 0, 0, 'L');
$pdf->SetX(140); $pdf->Cell(55, 6, 'RESUMEN:', 0, 1, 'L');

$x1 = 15; $x2 = 75; $x3 = 140; $y_datos = 46;

$pdf->SetXY($x1, $y_datos);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(60, 5, 'DataSense Analytics', 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX($x1); $pdf->Cell(60, 5, 'NIT: 900.000.000-1', 0, 1, 'L');
$pdf->SetX($x1); $pdf->Cell(60, 5, 'Bogota, Colombia', 0, 1, 'L');
$pdf->SetX($x1); $pdf->Cell(60, 5, 'contacto@datasense.com', 0, 1, 'L');

$pdf->SetXY($x2, $y_datos);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(65, 5, textoPdf($factura['cliente_nombre']), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX($x2); $pdf->Cell(65, 5, textoPdf(mb_strtoupper($factura['organizacion'] ? $factura['organizacion'] : 'Independiente', 'UTF-8')), 0, 1, 'L');
$pdf->SetX($x2); $pdf->Cell(65, 5, textoPdf($factura['correo']), 0, 1, 'L');
$telefono_cliente = isset($factura['telefono']) ? $factura['telefono'] : 'No registrado';
$pdf->SetX($x2); $pdf->Cell(65, 5, 'Tel: ' . $telefono_cliente, 0, 1, 'L');

$pdf->SetXY($x3, $y_datos);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(25, 5, textoPdf('N° Factura:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(30, 5, $factura['numero_factura'], 0, 1, 'R');

$pdf->SetX($x3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(25, 5, 'Emision:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, 5, date('d/m/Y', strtotime($factura['fecha_emision'])), 0, 1, 'R');

$pdf->SetX($x3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 5, 'Vencimiento:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, 5, date('d/m/Y', strtotime($factura['fecha_vencimiento'])), 0, 1, 'R');

$pdf->SetX($x3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 6, 'Estado:', 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 9);
if($factura['estado'] == 'Vencido') $pdf->SetTextColor(239, 68, 68);
elseif(in_array($factura['estado'], ['Pendiente', 'En Proceso'], true)) $pdf->SetTextColor(245, 158, 11);
elseif($factura['estado'] == 'Anulada') $pdf->SetTextColor(100, 116, 139);
else $pdf->SetTextColor(63, 174, 73);
$pdf->Cell(30, 6, strtoupper($factura['estado']), 0, 1, 'R');

$pdf->Ln(15);

// BLOQUE 2: TABLA DE SERVICIOS
$pdf->SetX(15);
$pdf->SetFillColor(10, 46, 79); 
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(135, 10, '   DESCRIPCION DEL SERVICIO', 0, 0, 'L', true);
$pdf->Cell(45, 10, 'IMPORTE BASE   ', 0, 1, 'R', true);

$pdf->SetX(15);
$pdf->SetFillColor(248, 250, 252);
$pdf->SetTextColor(40, 40, 40);
$pdf->SetFont('Arial', '', 10);
$pdf->SetDrawColor(230, 230, 230);
$pdf->Cell(135, 14, textoPdf('   ' . ($factura['servicio_contratado'] ? $factura['servicio_contratado'] : 'Servicios Analíticos')), 'B', 0, 'L', true);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 14, '$ ' . number_format($subtotal_factura, 0, ',', '.') . '   ', 'B', 1, 'R', true);

$pdf->Ln(8);

// BLOQUE 3: TOTALES FINANCIEROS REALES
$x_totales = 120;
$pdf->SetX($x_totales);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(40, 7, 'Subtotal:', 0, 0, 'L');
$pdf->SetTextColor(40, 40, 40);
$pdf->Cell(35, 7, '$ ' . number_format($subtotal_factura, 0, ',', '.'), 0, 1, 'R');

$pdf->SetX($x_totales);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(40, 7, 'IVA (' . floatval($porcentaje_iva) . '%):', 'B', 0, 'L'); 
$pdf->SetTextColor(40, 40, 40);
$pdf->Cell(35, 7, '$ ' . number_format($valor_iva, 0, ',', '.'), 'B', 1, 'R');

$pdf->Ln(3);
$pdf->SetX($x_totales);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(40, 10, 'TOTAL A PAGAR:', 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(63, 174, 73); 
$pdf->Cell(35, 10, '$ ' . number_format($total_pagar, 0, ',', '.'), 0, 1, 'R');

// Abonos parciales: cuánto se ha pagado y cuánto falta
// (abonado neto: recaudos y notas crédito aprobados, menos notas débito)
if (abs($abonado) > 0.5 && !in_array($factura['estado'], ['Pagado', 'Anulada'], true)) {
    $pdf->SetX($x_totales);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(40, 6, textoPdf($abonado > 0 ? 'Abonos y notas crédito:' : 'Notas débito:'), 0, 0, 'L');
    $pdf->Cell(35, 6, '$ ' . number_format(abs($abonado), 0, ',', '.'), 0, 1, 'R');
    $pdf->SetX($x_totales);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(239, 68, 68);
    $pdf->Cell(40, 6, 'Saldo por pagar:', 0, 0, 'L');
    $pdf->Cell(35, 6, '$ ' . number_format($saldo_pendiente, 0, ',', '.'), 0, 1, 'R');
}

// BLOQUE 4: NOTAS Y CÓDIGO DE BARRAS
$pdf->SetY(140); 
$pdf->SetX(15);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(100, 6, 'INFORMACION DE PAGO', 0, 1, 'L');

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(100, 100, 100);

if ($factura['estado'] == 'Anulada') {
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(220, 38, 38);
    $pdf->Cell(100, 10, 'FACTURA ANULADA', 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(180, 5, textoPdf('Motivo: ' . ($factura['motivo_anulacion'] ?? 'No registrado') . '. Este documento no tiene valor de cobro.'), 0, 'L');
} elseif ($factura['estado'] == 'Pagado') {
    $pdf->SetX(15);
    $pdf->Cell(100, 5, textoPdf('Esta factura ya ha sido cancelada en su totalidad.'), 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->Cell(100, 5, textoPdf('No es necesario realizar ninguna accion adicional.'), 0, 1, 'L');
} else {
    // Si NO está pagada, mostramos la cuenta y el código de barras
    $pdf->SetX(15);
    $pdf->Cell(100, 5, textoPdf('Por favor realizar la transferencia a la siguiente cuenta:'), 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(40, 40, 40);
    
    // Aquí actualicé con los datos de tu cuenta real
    $pdf->Cell(100, 5, textoPdf('Bancolombia - Cuenta de Ahorros 567 - 786902 - 55'), 0, 1, 'L');
    
    $pdf->Ln(5);
    
    // DIBUJAR CÓDIGO DE BARRAS
    // Limpiamos el número de factura para quitar guiones o espacios (Code39 es delicado con caracteres raros)
    $codigo_limpio = str_replace('-', '', $factura['numero_factura']);
    
    // Llamamos a la función: Posición X, Posición Y, Texto a convertir, Ancho, Alto
    $pdf->Code39(15, 160, $codigo_limpio, 1.2, 12);
    
    // Imprimir el número legible debajo del código de barras
    $pdf->SetXY(15, 175);
    $pdf->SetFont('Courier', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(60, 5, '*' . $codigo_limpio . '*', 0, 1, 'C');
    
    // Instrucciones
    $pdf->SetY(185);
    $pdf->SetX(15);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(100, 4, textoPdf('Puede realizar el pago en cualquier sucursal presentando este documento.'), 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->Cell(100, 4, textoPdf('* Este recibo es válido hasta la fecha de vencimiento.'), 0, 1, 'L');
}

ob_end_clean();
$pdf->Output('I', 'Factura_' . $factura['numero_factura'] . '.pdf');
?>