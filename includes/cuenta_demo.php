<?php
/**
 * PREPARAR LA CUENTA DEL ADMINISTRADOR EN LA BASE DE DEMOSTRACIÓN
 * Lo usa admin/preparar_cuenta.php (botón en Mi perfil, solo para administradores y solo en una base creada con los
 * datos de demostración). Hace dos cosas, en una sola transacción:
 *
 *   1. Deja a la persona como único administrador: borra las cuentas de administrador de ejemplo (@example.com). Todo
 *      lo que esas cuentas registraron (aprobaciones, revisiones, casos, historial…) pasa a su nombre para que nada
 *      quede sin responsable. Los administradores con correo real no se tocan.
 *   2. Le pasa el historial de un cliente de ejemplo (el de más facturas): facturas con sus pagos y comprobantes,
 *      casos con su historial y encuestas, y reuniones. Así Mi Resumen, Mis Facturas y el Centro de Soporte de su
 *      cuenta se ven con datos. Los valores no cambian, así que la contabilidad sigue cuadrando.
 *
 * Nunca corre en una base real: esDemostracion() exige APP_ENV=demo o la marca de cron/preparar_base.php.
 */
require_once __DIR__ . '/contabilidad.php';

// Columnas que guardan quién hizo algo: [tabla, columna]
const COLUMNAS_RESPONSABLE = [
    ['comprobantes', 'preparado_por'], ['comprobantes', 'revisado_por'], ['comprobantes', 'aprobado_por'], ['comprobantes', 'anulado_por'],
    ['ingresos', 'preparado_por'], ['ingresos', 'revisado_por'], ['ingresos', 'anulado_por'],
    ['egresos', 'preparado_por'], ['egresos', 'revisado_por'], ['egresos', 'anulado_por'],
    ['facturas', 'anulada_por'], ['asientos_contables', 'id_usuario'],
    ['conciliaciones', 'creado_por'], ['conciliaciones', 'cerrado_por'], ['extracto_movimientos', 'conciliado_por'],
    ['periodos_contables', 'cerrado_por'], ['periodos_contables', 'reabierto_por'],
    ['soporte_tickets', 'tecnico_id'], ['encuestas_respuestas', 'tecnico_id'], ['ticket_historial', 'usuario_id'], ['ticket_correos', 'usuario_id'],
    ['pqrsd', 'asignado_a'], ['pqrsd', 'respondido_por'], ['pqrsd', 'cerrado_por'], ['pqrsd_historial', 'usuario_id'],
    ['reuniones_virtuales', 'firmado_por'], ['tienda_pedido_historial', 'usuario_id'],
    ['redes_conexiones', 'conectado_por'], ['redes_programadas', 'creado_por'],
];
// Columnas donde la cuenta aparece como cliente
const COLUMNAS_CLIENTE = [
    ['facturas', 'id_usuario'], ['soporte_tickets', 'usuario_id'], ['reuniones_virtuales', 'cliente_id'], ['pqrsd', 'usuario_id'], ['tienda_pedidos', 'usuario_id'],
];

function esDemostracion(PDO $db) {
    if (($_ENV['APP_ENV'] ?? '') === 'demo') return true;
    try {
        return (bool) $db->query("SELECT COUNT(*) FROM ds_instalacion WHERE id = 1")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function columnaExiste(PDO $db, $tabla, $columna) {
    static $cache = [];
    $clave = "$tabla.$columna";
    if (!isset($cache[$clave])) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tabla, $columna]);
        $cache[$clave] = (bool) $stmt->fetchColumn();
    }
    return $cache[$clave];
}

// Pasa a $yo todo lo que tienen las cuentas $ids en las columnas indicadas
function pasarReferencias(PDO $db, array $columnas, array $ids, $yo) {
    if (!$ids) return;
    $marcas = implode(', ', array_fill(0, count($ids), '?'));
    foreach ($columnas as [$tabla, $columna]) {
        if (!columnaExiste($db, $tabla, $columna)) continue;
        $db->prepare("UPDATE `$tabla` SET `$columna` = ? WHERE `$columna` IN ($marcas)")->execute(array_merge([(int) $yo], $ids));
    }
}

// ¿Ya se preparó esta cuenta? (tiene facturas propias o ya no quedan administradores de ejemplo y tiene historial)
function cuentaPreparada(PDO $db, $yo) {
    $facturas = $db->prepare("SELECT COUNT(*) FROM facturas WHERE id_usuario = ?");
    $facturas->execute([(int) $yo]);
    $otros_admin = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'Admin' AND correo LIKE '%@example.com'")->fetchColumn();
    return $facturas->fetchColumn() >= 3 && !$otros_admin;
}

/**
 * Hace las dos tareas. Devuelve ['admins' => cuántos se borraron, 'cliente' => organización de la que vino el historial,
 * 'facturas' => , 'casos' => , 'reuniones' => ].
 */
function prepararCuentaDemo(PDO $db, array $yo) {
    $id = (int) $yo['id'];
    $resultado = ['admins' => 0, 'cliente' => null, 'facturas' => 0, 'casos' => 0, 'reuniones' => 0];
    $db->beginTransaction();
    try {
        // 1. Único administrador
        $admins = array_map('intval', $db->query("SELECT id FROM usuarios WHERE rol = 'Admin' AND correo LIKE '%@example.com' AND id <> $id")->fetchAll(PDO::FETCH_COLUMN));
        if ($admins) {
            $marcas = implode(', ', array_fill(0, count($admins), '?'));
            // Especialistas y responsables de PQRSD tienen una fila por persona: se quitan en lugar de pasarse
            foreach (['itsm_especialistas', 'pqrsd_responsables'] as $tabla) {
                if (columnaExiste($db, $tabla, 'usuario_id')) $db->prepare("DELETE FROM `$tabla` WHERE usuario_id IN ($marcas)")->execute($admins);
            }
            pasarReferencias($db, array_merge(COLUMNAS_RESPONSABLE, COLUMNAS_CLIENTE), $admins, $id);
            if (columnaExiste($db, 'terceros', 'id_usuario')) $db->prepare("UPDATE terceros SET id_usuario = NULL WHERE id_usuario IN ($marcas)")->execute($admins);
            if (columnaExiste($db, 'contactos', 'id_usuario_final')) $db->prepare("UPDATE contactos SET id_usuario_final = NULL WHERE id_usuario_final IN ($marcas)")->execute($admins);
            $db->prepare("DELETE FROM usuarios WHERE id IN ($marcas)")->execute($admins);
            $resultado['admins'] = count($admins);
        }
        $db->prepare("UPDATE usuarios SET rol = 'Admin', estado_cuenta = 'Activo' WHERE id = ?")->execute([$id]);

        // 2. Historial del cliente de ejemplo con más facturas
        $propias = $db->prepare("SELECT COUNT(*) FROM facturas WHERE id_usuario = ?");
        $propias->execute([$id]);
        if ($propias->fetchColumn() < 3) {
            $donante = $db->query("SELECT u.id, u.nombre, u.organizacion FROM usuarios u JOIN facturas f ON f.id_usuario = u.id
                                   WHERE u.rol = 'Cliente' AND u.correo LIKE '%@example.com' GROUP BY u.id, u.nombre, u.organizacion
                                   ORDER BY COUNT(*) DESC, u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($donante) {
                $de = (int) $donante['id'];
                $mi_empresa = trim((string) ($yo['organizacion'] ?? '')) !== '' ? $yo['organizacion'] : $yo['nombre'];
                $empresa_antes = trim((string) $donante['organizacion']) !== '' ? $donante['organizacion'] : $donante['nombre'];

                $cuenta = function ($sql) use ($db, $de) { $s = $db->prepare($sql); $s->execute([$de]); return (int) $s->fetchColumn(); };
                $resultado['facturas'] = $cuenta("SELECT COUNT(*) FROM facturas WHERE id_usuario = ?");
                $resultado['casos'] = $cuenta("SELECT COUNT(*) FROM soporte_tickets WHERE usuario_id = ?");
                $resultado['reuniones'] = $cuenta("SELECT COUNT(*) FROM reuniones_virtuales WHERE cliente_id = ?");

                // Comprobantes de sus facturas y encuestas de sus casos: a nombre de la empresa y la persona nuevas
                $db->prepare("UPDATE comprobantes SET tercero_nombre = ? WHERE factura_id IN (SELECT id FROM facturas WHERE id_usuario = ?)")->execute([$mi_empresa, $de]);
                $db->prepare("UPDATE encuestas_respuestas SET cliente_nombre = ? WHERE ticket_id IN (SELECT id FROM soporte_tickets WHERE usuario_id = ?) OR cliente_nombre = ?")
                   ->execute([$yo['nombre'], $de, $donante['nombre']]);
                $db->prepare("UPDATE reuniones_virtuales SET titulo = REPLACE(titulo, ?, ?) WHERE cliente_id = ?")->execute([$empresa_antes, $mi_empresa, $de]);
                // El cliente confirmó soluciones en el historial de sus casos
                $db->prepare("UPDATE ticket_historial SET usuario_id = ? WHERE usuario_id = ?")->execute([$id, $de]);
                pasarReferencias($db, COLUMNAS_CLIENTE, [$de], $id);

                // Su tercero (NIT) pasa a la cuenta si esta no tiene uno
                if (!terceroDeUsuario($db, $id)) {
                    $db->prepare("UPDATE terceros SET id_usuario = ?, nombre = ? WHERE id_usuario = ?")->execute([$id, $mi_empresa, $de]);
                    $db->prepare("UPDATE comprobantes c JOIN terceros t ON t.id = c.tercero_id SET c.tercero_nombre = ? WHERE t.id_usuario = ?")->execute([$mi_empresa, $id]);
                } else {
                    $db->prepare("UPDATE terceros SET id_usuario = NULL WHERE id_usuario = ?")->execute([$de]);
                }
                // El cliente de ejemplo queda sin historial: se borra para no dejar una ficha vacía
                if (columnaExiste($db, 'contactos', 'id_usuario_final')) $db->prepare("UPDATE contactos SET id_usuario_final = NULL WHERE id_usuario_final = ?")->execute([$de]);
                $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$de]);
                $resultado['cliente'] = $empresa_antes;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $resultado;
}
