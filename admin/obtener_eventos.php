<?php
ob_start();
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
requiereAcceso($db, 'reuniones', 'api');

try {
    $stmt = $db->query("SELECT id, titulo, fecha, hora_inicio, hora_fin FROM reuniones_virtuales");
    $eventos = [];
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eventos[] = [
            'id' => $r['id'],
            'title' => $r['titulo'],
            'start' => $r['fecha'] . 'T' . $r['hora_inicio'],
            'end' => $r['fecha'] . 'T' . $r['hora_fin'],
            'backgroundColor' => '#0A2E4F',
            'borderColor' => '#3FAE49',
            'textColor' => '#ffffff'
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($eventos);
} catch (Exception $e) { echo json_encode([]); }
ob_end_flush();
