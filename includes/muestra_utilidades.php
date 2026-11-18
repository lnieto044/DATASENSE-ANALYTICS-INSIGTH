<?php
/**
 * UTILIDADES DE LOS DATOS DE MUESTRA
 * Las usan cron/datos_muestra.php, cron/datos_muestra_extra.php y cron/actividad_diaria.php: azar repetible (mt_srand lo
 * fija cada script), días hábiles con festivos de Colombia, personas y datos de contacto ficticios, y la numeración de
 * facturas, comprobantes, radicados y casos en orden de fecha.
 */
require_once __DIR__ . '/contabilidad.php';
require_once __DIR__ . '/itsm.php';

function azar($min, $max) { return mt_rand((int) $min, (int) $max); }
function elegir(array $lista) { return $lista[mt_rand(0, count($lista) - 1)]; }
function probable($p) { return mt_rand() / mt_getrandmax() < $p; }
function decimal($min, $max) { return $min + (mt_rand() / mt_getrandmax()) * ($max - $min); }
function elegirPeso(array $pesos) {
    $total = array_sum($pesos);
    $r = mt_rand(1, (int) $total);
    foreach ($pesos as $valor => $peso) {
        if (($r -= $peso) <= 0) return $valor;
    }
    return array_key_first($pesos);
}
function redondear($valor, $multiplo) { return round($valor / $multiplo) * $multiplo; }

function esHabil(DateTimeImmutable $dia) {
    return (int) $dia->format('N') <= 5 && !isset(festivosColombia((int) $dia->format('Y'))[$dia->format('Y-m-d')]);
}
function diasHabilesEntre(DateTimeImmutable $desde, DateTimeImmutable $hasta) {
    $dias = [];
    for ($d = $desde; $d <= $hasta; $d = $d->modify('+1 day')) {
        if (esHabil($d)) $dias[] = $d;
    }
    return $dias;
}
// Momento dentro de la jornada (8:00 a 17:00) de un día
function enJornada(DateTimeImmutable $dia, $desde = 8, $hasta = 17) {
    return $dia->setTime(azar($desde, $hasta - 1), azar(0, 59), azar(0, 59));
}
function f(DateTimeInterface $d) { return $d->format('Y-m-d H:i:s'); }
// Las funciones de la mesa devuelven DateTime: aquí todo se maneja como fechas que no cambian
function inmutable($valor) { return $valor instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($valor) : new DateTimeImmutable((string) $valor); }

const NOMBRES = ['Carolina', 'Andrés', 'Juliana', 'Felipe', 'Natalia', 'Camilo', 'Paola', 'Sebastián', 'Daniela', 'Mauricio', 'Valentina',
                 'Ricardo', 'Laura', 'Javier', 'Mónica', 'Alejandro', 'Diana', 'Óscar', 'Catalina', 'Hernán', 'Marcela', 'Esteban',
                 'Lucía', 'Gustavo', 'Sandra', 'Julián', 'Adriana', 'Fernando', 'Ángela', 'Mateo', 'Liliana', 'Tomás', 'Gloria', 'Iván'];
const APELLIDOS = ['Gómez', 'Rodríguez', 'Martínez', 'Restrepo', 'Castaño', 'Salazar', 'Quintero', 'Arango', 'Cárdenas', 'Moreno', 'Rojas',
                   'Velásquez', 'Ospina', 'Mejía', 'Zuluaga', 'Gutiérrez', 'Ramírez', 'Londoño', 'Vargas', 'Herrera', 'Patiño', 'Montoya',
                   'Echeverri', 'Giraldo', 'Bermúdez', 'Pineda', 'Cortés', 'Suárez', 'Agudelo', 'Duque', 'Palacio', 'Ríos'];
const CIUDADES = ['Bogotá' => 40, 'Medellín' => 18, 'Cali' => 12, 'Barranquilla' => 8, 'Bucaramanga' => 6, 'Pereira' => 4, 'Manizales' => 3,
                  'Cartagena' => 4, 'Tunja' => 3, 'Ibagué' => 2];

function persona() { return elegir(NOMBRES) . ' ' . elegir(APELLIDOS) . ' ' . elegir(APELLIDOS); }
function sinTildes($texto) {
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'ü' => 'u']);
}
function correoDe($nombre, $sufijo = '') {
    $partes = explode(' ', strtolower(sinTildes($nombre)));
    return preg_replace('/[^a-z.]/', '', $partes[0] . '.' . ($partes[1] ?? 'contacto')) . $sufijo . '@example.com';
}
function telefono() { return '307555' . str_pad((string) azar(0, 9999), 4, '0', STR_PAD_LEFT); }
function cedula() { return (string) azar(10000000, 1099999999); }

// Numera facturas, comprobantes, radicados de PQRSD y códigos de casos en orden de fecha, y ajusta los contadores para que
// el siguiente documento siga la numeración. Va dentro de la transacción del generador.
function renumerarDocumentos(PDO $db) {
    // Facturas
    $facturas = $db->query("SELECT id, numero_factura, YEAR(fecha_emision) AS anio FROM facturas ORDER BY fecha_emision, id")->fetchAll(PDO::FETCH_ASSOC);
    $db->exec("UPDATE facturas SET numero_factura = CONCAT('TMP-', id)");
    $nuevos = []; $cuenta = [];
    $poner = $db->prepare("UPDATE facturas SET numero_factura = ? WHERE id = ?");
    foreach ($facturas as $fa) {
        $cuenta['FAC'][$fa['anio']] = ($cuenta['FAC'][$fa['anio']] ?? 0) + 1;
        $nuevo = 'FAC-' . $fa['anio'] . '-' . str_pad((string) $cuenta['FAC'][$fa['anio']], 3, '0', STR_PAD_LEFT);
        $poner->execute([$nuevo, $fa['id']]);
        $nuevos[(int) $fa['id']] = [$fa['numero_factura'], $nuevo];
    }
    // Textos que mencionan el número de la factura
    $textos = $db->prepare("UPDATE comprobantes SET concepto = ? WHERE id = ?");
    foreach ($db->query("SELECT id, factura_id, concepto FROM comprobantes WHERE factura_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        [$viejo, $nuevo] = $nuevos[(int) $c['factura_id']] ?? [null, null];
        if ($viejo && strpos($c['concepto'], $viejo) !== false) $textos->execute([str_replace($viejo, $nuevo, $c['concepto']), $c['id']]);
    }
    $detalle = $db->prepare("UPDATE comprobantes_detalle SET detalle = ? WHERE id = ?");
    foreach ($db->query("SELECT d.id, d.detalle, c.factura_id FROM comprobantes_detalle d JOIN comprobantes c ON c.id = d.comprobante_id WHERE c.factura_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        [$viejo, $nuevo] = $nuevos[(int) $d['factura_id']] ?? [null, null];
        if ($viejo && strpos((string) $d['detalle'], $viejo) !== false) $detalle->execute([str_replace($viejo, $nuevo, $d['detalle']), $d['id']]);
    }
    // La referencia del pago no se puede repetir: primero un valor provisional y luego el definitivo (si no, un pago
    // tomaría por un momento el número que todavía tiene otro)
    $referencia = $db->prepare("UPDATE pagos_registro SET referencia_transaccion = ? WHERE id = ?");
    $cambios = [];
    foreach ($db->query("SELECT id, factura_id, referencia_transaccion FROM pagos_registro")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        [$viejo, $nuevo] = $nuevos[(int) $p['factura_id']] ?? [null, null];
        if ($viejo && strpos((string) $p['referencia_transaccion'], $viejo) !== false) $cambios[(int) $p['id']] = str_replace($viejo, $nuevo, $p['referencia_transaccion']);
    }
    foreach (array_keys($cambios) as $id) $referencia->execute(['TMP-' . $id, $id]);
    foreach ($cambios as $id => $valor) $referencia->execute([$valor, $id]);
    // Comprobantes (por tipo y año)
    $comprobantes = $db->query("SELECT id, tipo, YEAR(fecha) AS anio FROM comprobantes ORDER BY fecha, id")->fetchAll(PDO::FETCH_ASSOC);
    $db->exec("UPDATE comprobantes SET numero = CONCAT('TMP-', id)");
    $poner = $db->prepare("UPDATE comprobantes SET numero = ? WHERE id = ?");
    foreach ($comprobantes as $c) {
        $cuenta[$c['tipo']][$c['anio']] = ($cuenta[$c['tipo']][$c['anio']] ?? 0) + 1;
        $poner->execute([$c['tipo'] . '-' . $c['anio'] . '-' . str_pad((string) $cuenta[$c['tipo']][$c['anio']], 4, '0', STR_PAD_LEFT), $c['id']]);
    }
    // Radicados de PQRSD
    $radicados = $db->query("SELECT id, radicado, YEAR(fecha_registro) AS anio FROM pqrsd ORDER BY fecha_registro, id")->fetchAll(PDO::FETCH_ASSOC);
    $db->exec("UPDATE pqrsd SET radicado = CONCAT('TMP-', id)");
    $poner = $db->prepare("UPDATE pqrsd SET radicado = ? WHERE id = ?");
    $hist = $db->prepare("UPDATE pqrsd_historial SET detalle = REPLACE(detalle, ?, ?) WHERE pqrsd_id = ? AND detalle LIKE ?");
    foreach ($radicados as $r) {
        $cuenta['PQR'][$r['anio']] = ($cuenta['PQR'][$r['anio']] ?? 0) + 1;
        $nuevo = 'PQR-' . $r['anio'] . '-' . str_pad((string) $cuenta['PQR'][$r['anio']], 4, '0', STR_PAD_LEFT);
        $poner->execute([$nuevo, $r['id']]);
        if (strpos($r['radicado'], 'PQR-') === 0) $hist->execute([$r['radicado'], $nuevo, $r['id'], '%' . $r['radicado'] . '%']);
    }
    // Contadores: el siguiente documento sigue la numeración
    $contador = $db->prepare("UPDATE consecutivos SET ultimo = ? WHERE tipo = ? AND anio = ?");
    foreach ($cuenta as $tipo => $anios) {
        foreach ($anios as $anio => $total) {
            asegurarConsecutivo($db, $tipo, $anio);
            $contador->execute([$total, $tipo, $anio]);
        }
    }
    // Códigos de los casos: INC-00001, RF-00002… en orden de apertura
    $poner = $db->prepare("UPDATE soporte_tickets SET codigo_ticket = ? WHERE id = ?");
    $n = 0;
    foreach ($db->query("SELECT id, tipo FROM soporte_tickets ORDER BY created_at, id")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $poner->execute([($t['tipo'] === 'RF' ? 'RF' : 'INC') . '-' . str_pad((string) ++$n, 5, '0', STR_PAD_LEFT), $t['id']]);
    }
}
