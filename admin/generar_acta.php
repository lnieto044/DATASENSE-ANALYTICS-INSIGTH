<?php
// 1. CONTROL DE SALIDA Y SESIÓN
ob_start(); 
require_once __DIR__ . '/../includes/seguridad.php';
require('../libs/fpdf.php');

// 2. SEGURIDAD Y CONEXIÓN
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'reuniones');

if (!isset($_GET['id'])) { die("Error: ID de acta no especificado."); }
$id = (int) $_GET['id'];

// 3. CONSULTA DE DATOS (Traemos la reunión y los datos de firma guardados)
// Firmante: quien cerró el acta (columna firmado_por, tras la migración de database/); si aún no existe, quien la descarga
$tiene_firmante = (bool) $db->query("SHOW COLUMNS FROM reuniones_virtuales LIKE 'firmado_por'")->fetch();
$sql = "SELECT r.*, u.nombre as consultor, u.cargo
        FROM reuniones_virtuales r
        JOIN usuarios u ON u.id = " . ($tiene_firmante ? "COALESCE(r.firmado_por, ?)" : "?") . "
        WHERE r.id = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$_SESSION['admin_id'], $id]);
$datos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos) { die("Error: Acta no encontrada o acceso denegado."); }

// 4. CLASE PDF CON ESTILO CORPORATIVO DATASENSE
class PDF extends FPDF {
    function Header() {
        // Barra verde superior DataSense (Igual a tu factura)
        $this->SetFillColor(63, 174, 73); 
        $this->Rect(0, 0, 210, 4, 'F');
        
        // Logo
        if(file_exists('../img/logo.png')){
            $this->Image('../img/logo.png', 15, 12, 55); 
        }
        
        // Título ACTA
        $this->SetXY(110, 15);
        $this->SetFont('Arial', 'B', 28);
        $this->SetTextColor(10, 46, 79);
        $this->Cell(85, 10, 'ACTA', 0, 1, 'R');
        
        // Línea divisoria gris
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.4);
        $this->Line(15, 32, 195, 32);
        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-25);
        $this->SetDrawColor(220, 220, 220);
        $this->Line(15, 272, 195, 272);
        $this->SetY(-20);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, textoPdf('DataSense Analytics - Control Operativo - Página ') . $this->PageNo() . ' / {nb}', 0, 0, 'C');
    }
}

// 5. GENERAR EL DOCUMENTO
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// BLOQUE 1: DATOS DE CABECERA
$pdf->SetY(40);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(150, 150, 150);
$pdf->SetX(15); $pdf->Cell(65, 6, 'CONSULTOR RESPONSABLE:', 0, 0, 'L');
$pdf->SetX(85); $pdf->Cell(60, 6, textoPdf('FECHA DE SESIÓN:'), 0, 0, 'L');
$pdf->SetX(150); $pdf->Cell(45, 6, 'REFERENCIA ACTA:', 0, 1, 'L');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(10, 46, 79);
$y_datos = $pdf->GetY();

// Consultor
$pdf->SetXY(15, $y_datos);
$pdf->Cell(65, 5, textoPdf($datos['consultor']), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(15); $pdf->Cell(65, 5, 'DataSense Analytics', 0, 1, 'L');

// Fecha y Hora
$pdf->SetXY(85, $y_datos);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(60, 5, date('d/m/Y', strtotime($datos['fecha'])), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(85); $pdf->Cell(60, 5, 'Hora de Sesion: ' . $datos['hora_inicio'], 0, 1, 'L');

// Referencia ID
$pdf->SetXY(150, $y_datos);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 5, '#' . $id, 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(150); $pdf->Cell(45, 5, 'Estado: FINALIZADA', 0, 1, 'L');

$pdf->Ln(15);

// BLOQUE 2: ASUNTO
$pdf->SetX(15);
$pdf->SetFillColor(10, 46, 79); 
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(180, 10, textoPdf('   ASUNTO: ' . mb_strtoupper($datos['titulo'], 'UTF-8')), 0, 1, 'L', true);

$pdf->SetX(15);
$pdf->SetFillColor(248, 250, 252);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetFont('Arial', 'I', 9);
$pdf->MultiCell(180, 8, textoPdf('Propósito de la reunión: ' . ($datos['objetivo'] ? $datos['objetivo'] : 'No definido')), 'B', 'L', true);

$pdf->Ln(10);

// BLOQUE 3: ACUERDOS (CUERPO)
$pdf->SetX(15);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(180, 10, textoPdf('RESUMEN DE ACUERDOS:'), 0, 1, 'L');

$pdf->SetX(15);
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(40, 40, 40);
$pdf->MultiCell(180, 8, textoPdf($datos['evidencia']), 0, 'L');

$pdf->Ln(35);

// BLOQUE 4: FIRMA DIGITAL (Ajuste de posición y permanencia)
$current_y = $pdf->GetY();

// Evitamos que la firma quede huérfana al final de la hoja
if($current_y > 230) { 
    $pdf->AddPage(); 
    $current_y = 50; 
}

// LÓGICA DE FIRMA PERMANENTE DESDE DB
if (!empty($datos['firma_digital'])) {
    // 1. Convertimos el Base64 de la DB en un archivo temporal real para FPDF
    $img_data = str_replace(['data:image/png;base64,', ' '], ['', '+'], $datos['firma_digital']);
    $temp_file = sys_get_temp_dir() . '/temp_render_sig_' . $id . '_' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($temp_file, base64_decode($img_data));
    
    // 2. Colocamos la firma pegada a la línea
    // AJUSTE FINO: Usamos -12 para que pise la raya perfectamente
    $pdf->Image($temp_file, 20, $current_y - 19, 45, 0, 'PNG');
}

// Línea de firma
$pdf->SetDrawColor(10, 46, 79);
$pdf->Line(15, $current_y, 85, $current_y);

// Datos del pie de firma
$pdf->SetY($current_y + 2);
$pdf->SetX(15);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(10, 46, 79);
$pdf->Cell(70, 5, textoPdf($datos['consultor']), 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(15);
$pdf->Cell(70, 5, textoPdf($datos['cargo'] ? $datos['cargo'] : 'Consultor Especialista'), 0, 1, 'L');

// Sello de Certificación lateral
$pdf->SetY($current_y - 10);
$pdf->SetX(110);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(63, 174, 73);
$pdf->Cell(85, 5, textoPdf('CERTIFICACIÓN DIGITAL'), 0, 1, 'R');
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->SetX(110);
$pdf->MultiCell(85, 4, textoPdf('Documento firmado electrónicamente. La firma ha sido incrustada permanentemente en este archivo PDF.'), 0, 'R');

// --- 6. LIMPIEZA DE SEGURIDAD ---
// Borramos el archivo temporal. El PDF ya lo tiene "en sus venas", así que no hace falta más en el disco.
if (isset($temp_file) && file_exists($temp_file)) {
    unlink($temp_file);
}

// 7. SALIDA FINAL
ob_end_clean();
$pdf->Output('I', 'Acta_DataSense_' . $id . '.pdf');