<?php
/**
 * DATOS DE MUESTRA: UN AÑO DE ACTIVIDAD PARA LOS TABLEROS
 * Completa la base de demostración con la actividad de los últimos doce meses, para que los resúmenes, reportes y
 * gráficos se vean como en una operación real: casos de la mesa de servicios con sus tiempos y encuestas, facturas
 * con sus pagos y su contabilidad, gastos del mes, PQRSD, mensajes del sitio, conversaciones del chat y reuniones.
 *
 * - Usa las mismas reglas de la plataforma: consecutivos, causación contable (partida doble), ANS de la mesa y
 *   términos legales de las PQRSD. Al terminar numera facturas, comprobantes, radicados y casos en orden de fecha.
 * - Todo es ficticio: personas y empresas inventadas, correos @example.com (nunca llegan a nadie) y teléfonos 307 555.
 * - No crea pedidos ni reseñas de la tienda: la tienda es pública y mostraría ventas y opiniones que no existieron.
 * - Corre una sola vez por base (marca en ds_instalacion, id 2) y en una sola transacción: si algo falla, no deja nada
 *   a medias, y dos servidores que arranquen a la vez no lo duplican.
 * - Solo en la demostración (APP_ENV=demo) o en una base permanente que se creó con los datos de demostración
 *   (cron/preparar_base.php). Nunca en una base real. DATOS_MUESTRA=0 lo apaga.
 *
 * Uso: php cron/datos_muestra.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$db = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/contabilidad.php';
require_once __DIR__ . '/../includes/itsm.php';
require_once __DIR__ . '/../includes/pqrsd.php';
require_once __DIR__ . '/../includes/muestra_utilidades.php';   // azar repetible, fechas hábiles, personas ficticias y numeración

function avisoMuestra($texto) {
    echo date('Y-m-d H:i:s') . " Datos de muestra: $texto\n";
}

if (($_ENV['DATOS_MUESTRA'] ?? '1') === '0') {
    avisoMuestra('apagados (DATOS_MUESTRA=0)');
    exit;
}
$db->exec("CREATE TABLE IF NOT EXISTS ds_instalacion (id TINYINT PRIMARY KEY, estado VARCHAR(20) NOT NULL, actualizada DATETIME NOT NULL)");
$base_de_demo = (bool) $db->query("SELECT COUNT(*) FROM ds_instalacion WHERE id = 1")->fetchColumn();
if (($_ENV['APP_ENV'] ?? '') !== 'demo' && !$base_de_demo) {
    avisoMuestra('no aplica (solo en la demostración o en una base creada con sus datos)');
    exit;
}
if ($db->query("SELECT COUNT(*) FROM ds_instalacion WHERE id = 2")->fetchColumn()) {
    avisoMuestra('ya estaban');
    exit;
}

// ================================================================
// AZAR REPETIBLE Y FECHAS
// ================================================================

mt_srand(20261118);

$hoy = new DateTimeImmutable('today');
$fin = $hoy->modify('-1 day');                                    // nada de lo que ya pasó queda después de ayer
$limite = $fin->setTime(23, 59, 59);
$inicio = $hoy->modify('first day of this month')->modify('-11 months');
$meses = [];
for ($m = $inicio; $m <= $fin; $m = $m->modify('first day of next month')) $meses[] = $m;
$nombres_mes = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// ================================================================
// PERSONAS Y EMPRESAS (ficticias)
// ================================================================


// Clientes nuevos: [empresa, sector público o privado, peso en la actividad]
$empresas_nuevas = [
    ['Constructora Sierra Alta S.A.S.', 'Privado', 3], ['Transportes Río Claro Ltda.', 'Privado', 2], ['Laboratorios Monteverde S.A.', 'Privado', 3],
    ['Cooperativa Financiera Los Andes', 'mixto', 3], ['Hotel Mirador del Lago', 'Privado', 1], ['Agroindustrial El Llano S.A.S.', 'Privado', 2],
    ['Instituto Técnico Altamira', 'Público', 2], ['Secretaría de Movilidad de Villa Esperanza', 'Público', 3], ['Comercializadora Punto Fresco S.A.S.', 'Privado', 2],
    ['EPS Bienestar Integral', 'mixto', 3], ['Logística Puerto Azul S.A.', 'Privado', 2], ['Fundación Semillas de Futuro', 'mixto', 1],
];

$db->beginTransaction();
try {
    // La marca va primero: otro servidor que arranque a la vez espera aquí y luego ve que ya existe
    $db->exec("INSERT INTO ds_instalacion (id, estado, actualizada) VALUES (2, 'generando', NOW())");
    for ($anio = (int) $inicio->format('Y'); $anio <= (int) $fin->format('Y'); $anio++) {
        foreach (['CI', 'CE'] as $tipo) asegurarConsecutivo($db, $tipo, $anio);
    }

    // ---------------------------------------------------------------- Personas del equipo
    $staff = $db->query("SELECT id, nombre, rol FROM usuarios WHERE rol IN ('Admin', 'Soporte', 'Finanzas', 'Supervisor', 'Analista', 'Comercial') AND estado_cuenta = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $por_rol = [];
    foreach ($staff as $s) $por_rol[$s['rol']][] = (int) $s['id'];
    $admin = $por_rol['Admin'][0] ?? 1;
    $finanzas = $por_rol['Finanzas'] ?? [$admin];
    $soporte = $por_rol['Soporte'] ?? [$admin];
    $aprobadores = $por_rol['Admin'] ?? [$admin];

    // Nombres de las cuentas de ejemplo escritos en minúsculas o mayúsculas sostenidas: con mayúscula inicial
    $arreglar = $db->prepare("UPDATE usuarios SET nombre = ? WHERE id = ?");
    foreach ($db->query("SELECT id, nombre FROM usuarios WHERE correo LIKE '%@example.com'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if ($u['nombre'] === mb_strtolower($u['nombre']) || $u['nombre'] === mb_strtoupper($u['nombre'])) {
            $arreglar->execute([mb_convert_case(mb_strtolower($u['nombre']), MB_CASE_TITLE, 'UTF-8'), $u['id']]);
        }
    }

    // ---------------------------------------------------------------- Clientes
    $clave_inusable = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);   // nadie puede entrar con estas cuentas
    $nuevo_cliente = $db->prepare("INSERT INTO usuarios (nombre, documento, correo, password, telefono, organizacion, rol, fecha_registro, estado_cuenta, visto, cargo)
                                   VALUES (?, ?, ?, ?, ?, ?, 'Cliente', ?, 'Activo', 1, ?)");
    $existe_correo = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?");
    foreach ($empresas_nuevas as $i => [$empresa, , ]) {
        $contacto = persona();
        $correo = correoDe($contacto);
        $existe_correo->execute([$correo]);
        if ($existe_correo->fetchColumn()) $correo = correoDe($contacto, (string) ($i + 2));
        $registro = $inicio->modify('-' . azar(10, 120) . ' days')->setTime(azar(8, 17), azar(0, 59));
        $nuevo_cliente->execute([$contacto, cedula(), $correo, $clave_inusable, telefono(), $empresa, f($registro),
                                 elegir(['Gerente de Tecnología', 'Directora de Planeación', 'Coordinador de Sistemas', 'Jefe de Analítica', 'Gerente Financiero', 'Líder de Transformación Digital'])]);
    }

    $clientes = $db->query("SELECT id, nombre, organizacion FROM usuarios WHERE rol = 'Cliente' AND estado_cuenta = 'Activo' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $peso_cliente = [];
    $tipo_cliente = [];
    foreach ($empresas_nuevas as [$empresa, $tipo, $peso]) { $tipo_cliente[$empresa] = $tipo; $peso_empresa[$empresa] = $peso; }
    foreach ($clientes as $c) $peso_cliente[(int) $c['id']] = $peso_empresa[$c['organizacion']] ?? 2;
    $cliente_por_id = array_column($clientes, null, 'id');

    // Terceros (NIT) de cada empresa cliente, para los comprobantes y los libros
    $nit = 900000100;
    foreach ($clientes as $c) {
        if (!terceroDeUsuario($db, $c['id'])) guardarTercero($db, (string) ($nit++), $c['organizacion'] ?: $c['nombre'], 'Cliente', $c['id']);
    }

    // ================================================================
    // MESA DE SERVICIOS
    // ================================================================
    $cfg = configItsm($db);
    $catalogo = $db->query("SELECT tipo_solicitud, servicio, subcategoria, grupo_resolutor FROM catalogo_servicios WHERE estado = 1")->fetchAll(PDO::FETCH_ASSOC);
    $grupos_tecnico = [];
    foreach ($db->query("SELECT e.usuario_id, e.grupos FROM itsm_especialistas e JOIN usuarios u ON u.id = e.usuario_id WHERE u.rol = 'Soporte'")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        foreach (array_filter(explode('|', (string) $e['grupos'])) as $g) $grupos_tecnico[$g][] = (int) $e['usuario_id'];
    }
    $respuestas_tecnicas = [
        'Recibimos el caso y ya estamos revisando la información del tablero y de la fuente de datos.',
        'Hola, ya tomamos tu caso. Estamos validando la conexión y los registros que mencionas.',
        'Caso recibido. Revisamos los registros del proceso y te contamos el avance en cuanto tengamos el diagnóstico.',
        'Gracias por el reporte. Ya identificamos el componente afectado y empezamos la corrección.',
    ];
    $soluciones = [
        'Visualización (Dashboards)' => 'Se corrigió la medida que alimentaba el gráfico y se actualizó el modelo semántico del tablero.',
        'Actualización (ETL)' => 'Se reprogramó la carga nocturna y se ajustó el paso de transformación que fallaba por un cambio en la fuente.',
        'Integración (APIs)' => 'Se renovó el token de la integración y se agregó un reintento automático ante caídas del servicio externo.',
        'Integración de Datos' => 'Se configuró la nueva fuente y se validaron los primeros cargues con el área solicitante.',
        'Calidad de Datos' => 'Se depuraron los registros duplicados y se agregó una regla de validación en el cargue.',
        'Rendimiento' => 'Se optimizaron las consultas del reporte y se agregaron agregaciones; el tiempo de carga bajó a pocos segundos.',
        'Acceso y Seguridad' => 'Se ajustaron los permisos del usuario según la matriz de roles aprobada.',
        'Extracción de Datos' => 'Se entregó la extracción solicitada en el formato acordado, con su diccionario de campos.',
        'Modelos Predictivos' => 'Se reentrenó el modelo con los datos del último trimestre y se publicó la nueva versión.',
        'Exportación e Informes' => 'Se corrigió la plantilla de exportación y se verificó la descarga en Excel y PDF.',
        'Disponibilidad Web' => 'Se reinició el servicio y se amplió la capacidad del servidor para evitar nuevas caídas.',
        'Alertas y Notificaciones' => 'Se corrigió la regla de la alerta y se probaron los envíos con el equipo del cliente.',
    ];
    $comentarios_buenos = ['Muy buena atención, rápido y claro.', 'Excelente servicio, resolvieron el problema el mismo día.', 'Gracias, quedó funcionando perfecto.',
                           'El técnico explicó muy bien la solución.', 'Atención oportuna y profesional.', 'Todo bien, muchas gracias por la ayuda.',
                           'Resolvieron sin afectar la operación.', ''];
    $comentarios_regulares = ['Se resolvió, pero tardó más de lo esperado.', 'La solución funciona, aunque nos gustaría más seguimiento.', 'Bien, pero tuvimos que insistir.'];

    $crear_ticket = $db->prepare("INSERT INTO soporte_tickets (codigo_ticket, usuario_id, tecnico_id, asunto, servicio, categoria, impacto, urgencia, grupo_resolutor,
            prioridad, estado, descripcion, respuesta_tecnica, solucion, fecha_respuesta, created_at, fecha_vencimiento, fecha_pausa, ultima_gestion_fecha,
            calificacion_estrellas, comentario_usuario, notificar_tecnico, tipo, fecha_primera_respuesta, fecha_resolucion, fecha_cierre, minutos_pausa,
            vence_respuesta, sla_respuesta_min, sla_resolucion_min, calendario, reaperturas, codigo_cierre)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
    $historial = [];
    $encuestas = [];
    $primer_ticket = null;
    $total_tickets = 0;

    foreach (diasHabilesEntre($inicio, $fin) as $dia) {
        $cantidad = elegirPeso([0 => 2, 1 => 4, 2 => 3, 3 => 1]);
        for ($n = 0; $n < $cantidad; $n++) {
            $cat = elegir($catalogo);
            $tipo = $cat['tipo_solicitud'] === 'RF' ? 'RF' : 'INC';
            $impacto = elegirPeso(['Alto' => 2, 'Medio' => 5, 'Bajo' => 3]);
            $urgencia = elegirPeso(['Alta' => 2, 'Media' => 5, 'Baja' => 3]);
            $prioridad = prioridadDe($impacto, $urgencia);
            [$resp_min, $resol_min, $calendario] = objetivoSla($db, $tipo, $prioridad);
            $usuario = (int) elegirPeso($peso_cliente);
            $tecnico = elegir($grupos_tecnico[$cat['grupo_resolutor']] ?? $soporte);
            $creado = enJornada($dia, probable(0.08) ? 7 : 8, probable(0.08) ? 19 : 17);
            $edad = (int) $creado->diff($limite)->format('%a');

            // Tiempos: la gran mayoría dentro del ANS, una parte por encima
            $primera = inmutable(sumarMinutosHabiles(f($creado), max(3, $resp_min * (probable(0.88) ? decimal(0.15, 0.9) : decimal(1.1, 2.4))), $calendario, $cfg));
            $resuelto = inmutable(sumarMinutosHabiles(f($creado), max(30, $resol_min * (probable(0.84) ? decimal(0.2, 0.95) : decimal(1.05, 2.0))), $calendario, $cfg));
            if ($resuelto <= $primera) $resuelto = $primera->modify('+' . azar(20, 90) . ' minutes');
            $cierre_tipo = elegirPeso(['Cerrado - Exitoso' => 86, 'Cerrado - Inactividad' => 8, 'Cerrado - Falta de información' => 6]);
            $cerrado = inmutable(sumarMinutosHabiles(f($resuelto), $cierre_tipo === 'Cerrado - Exitoso' ? azar(45, 1400) : 3 * 540, 'habil', $cfg));

            // Estado según la antigüedad: lo reciente sigue en curso
            $sigue_abierto = $edad <= 3 ? 0.85 : ($edad <= 8 ? 0.6 : ($edad <= 16 ? 0.3 : 0));
            if ($cerrado <= $limite && !probable($sigue_abierto)) {
                $estado = $cierre_tipo;
            } else {
                $estado = $edad <= 1 ? elegirPeso(['Registrado' => 3, 'Asignado' => 3, 'En Progreso' => 2]) : elegirPeso(['Asignado' => 1, 'En Progreso' => 4, 'En Espera' => 2, 'Resuelto' => 3]);
            }
            $con_primera = !in_array($estado, ['Registrado', 'Asignado'], true) && $primera <= $limite;
            if (!$con_primera && !in_array($estado, ['Registrado', 'Asignado'], true)) $estado = 'Asignado';
            $con_solucion = in_array($estado, ['Resuelto', 'Cerrado - Exitoso', 'Cerrado - Inactividad', 'Cerrado - Falta de información'], true) && $resuelto <= $limite;
            if (!$con_solucion && in_array($estado, ['Resuelto'], true)) $estado = 'En Progreso';
            $con_cierre = strpos($estado, 'Cerrado') === 0;
            $pausa = $estado === 'En Espera' ? $primera->modify('+' . azar(30, 240) . ' minutes') : null;
            if ($pausa && $pausa > $limite) $pausa = $primera;
            $ultima = $con_cierre ? $cerrado : ($con_solucion ? $resuelto : ($pausa ?: ($con_primera ? $primera : $creado)));

            $estrellas = null; $comentario = null;
            if ($estado === 'Cerrado - Exitoso' && probable(0.62)) {
                $estrellas = elegirPeso([5 => 58, 4 => 30, 3 => 9, 2 => 3]);
                $comentario = $estrellas >= 4 ? elegir($comentarios_buenos) : elegir($comentarios_regulares);
            }

            $t = ['created_at' => f($creado), 'tipo' => $tipo, 'prioridad' => $prioridad, 'sla_respuesta_min' => $resp_min, 'sla_resolucion_min' => $resol_min,
                  'calendario' => $calendario, 'estado' => $estado, 'comportamiento' => COMP_ABIERTO, 'minutos_pausa' => 0,
                  'fecha_primera_respuesta' => null, 'fecha_resolucion' => null, 'fecha_cierre' => null, 'fecha_pausa' => null];
            $vence = camposVencimiento($t, $cfg);

            $crear_ticket->execute([
                'TMP', $usuario, $estado === 'Registrado' ? null : $tecnico, $cat['subcategoria'], $cat['servicio'], $cat['subcategoria'], $impacto, $urgencia,
                $cat['grupo_resolutor'], $prioridad, $estado,
                'Desde ' . elegir(['ayer', 'esta mañana', 'el último cargue', 'la actualización del fin de semana', 'hace unos días']) . ': ' . mb_strtolower(rtrim($cat['subcategoria'], '.'))
                    . '. ' . elegir(['Afecta al equipo de la gerencia.', 'Lo necesitamos para el comité de esta semana.', 'Adjuntamos captura del error.', 'Varias personas del área reportan lo mismo.', 'Agradecemos su apoyo.']),
                $con_primera ? elegir($respuestas_tecnicas) : null,
                $con_solucion ? ($soluciones[$cat['servicio']] ?? 'Se atendió la solicitud y se validó el resultado con el usuario.') : null,
                $con_primera ? f($primera) : null, f($creado), $vence['fecha_vencimiento'] ?? null, $pausa ? f($pausa) : null, f($ultima),
                $estrellas ?? 0, $comentario, $tipo, $con_primera ? f($primera) : null, $con_solucion ? f($resuelto) : null, $con_cierre ? f($cerrado) : null, 0,
                $vence['vence_respuesta'] ?? null, $resp_min, $resol_min, $calendario,
                $con_cierre ? (probable(0.9) ? 'Solución definitiva' : 'Solución temporal (mientras se corrige la causa)') : null,
            ]);
            $id = (int) $db->lastInsertId();
            $primer_ticket = $primer_ticket ?? $id;
            $total_tickets++;

            if ($estado !== 'Registrado') $historial[] = [$id, f($creado->modify('+' . azar(1, 8) . ' minutes')), null, 'Cambio de estado', 'Asignación automática al especialista', 'Registrado', 'Asignado', 0];
            if ($con_primera) {
                $historial[] = [$id, f($primera), $tecnico, 'Primera respuesta', 'Primera respuesta enviada al cliente', 'Asignado', 'En Progreso', 1];
                if (probable(0.3)) $historial[] = [$id, f($primera->modify('+' . azar(20, 120) . ' minutes')), $tecnico, elegir(['Correo al cliente', 'Llamada al cliente']), 'Seguimiento con el cliente', null, null, 1];
            }
            if ($pausa) $historial[] = [$id, f($pausa), $tecnico, 'Cambio de estado', 'En espera de información del cliente', 'En Progreso', 'En Espera', 1];
            if ($con_solucion) $historial[] = [$id, f($resuelto), $tecnico, 'Solución', 'Solución registrada', 'En Progreso', 'Resuelto', 1];
            if ($con_cierre) {
                $historial[] = $estado === 'Cerrado - Exitoso'
                    ? [$id, f($cerrado), $usuario, 'Confirmación del cliente', 'El cliente confirmó la solución', 'Resuelto', $estado, 1]
                    : [$id, f($cerrado), null, 'Cierre automático', 'Cierre por plazo sin respuesta del cliente', 'Resuelto', $estado, 1];
            }
            if ($estrellas !== null) {
                $notas = [min(5, $estrellas + azar(-1, 1)), $estrellas, min(5, $estrellas + azar(0, 1)), min(5, max(2, $estrellas + azar(-1, 0))), $estrellas];
                $notas = array_map(function ($v) { return max(1, min(5, $v)); }, $notas);
                $encuestas[] = [1, $tecnico, $id, $cliente_por_id[$usuario]['nombre'] ?? 'Cliente', $notas, $estrellas, (string) $comentario,
                                f(min($cerrado->modify('+' . azar(1, 30) . ' hours'), $limite))];
            }
        }
    }

    // Historial y encuestas en bloques (menos viajes a la base)
    foreach (array_chunk($historial, 200) as $bloque) {
        $marcas = implode(', ', array_fill(0, count($bloque), '(?, ?, ?, ?, ?, ?, ?, ?)'));
        $db->prepare("INSERT INTO ticket_historial (ticket_id, fecha, usuario_id, evento, detalle, estado_anterior, estado_nuevo, publico) VALUES $marcas")
           ->execute(array_merge(...$bloque));
    }

    // ANS cumplido o no, calculado con la misma regla de la mesa
    $guardar_sla = $db->prepare("UPDATE soporte_tickets SET sla_respuesta_cumplido = ?, sla_resolucion_cumplido = ? WHERE id = ?");
    foreach (ticketsItsm($db, 't.id >= ?', [$primer_ticket]) as $caso) {
        if (empty($caso['sla'])) continue;
        $r = $caso['sla']['respuesta']['cumplido'];
        $s = $caso['sla']['resolucion']['cumplido'];
        $guardar_sla->execute([$r === null ? null : (int) $r, $s === null ? null : (int) $s, $caso['id']]);
    }

    // ================================================================
    // FACTURACIÓN, RECAUDOS Y GASTOS
    // ================================================================
    $servicios = $db->query("SELECT id, codigo_servicio, nombre, precio_base, impuesto_porcentaje FROM servicios_catalogo WHERE estado = 'Activo' AND precio_base >= 900000")->fetchAll(PDO::FETCH_ASSOC);
    $mantenimiento = $db->query("SELECT id, nombre, precio_base, impuesto_porcentaje FROM servicios_catalogo WHERE codigo_servicio = 'CS-008'")->fetch(PDO::FETCH_ASSOC)
        ?: ['id' => null, 'nombre' => 'Mantenimiento Mensual BI', 'precio_base' => 1200000, 'impuesto_porcentaje' => 19];
    $con_contrato = array_slice(array_keys($peso_cliente), 0, 6);   // clientes con soporte mensual
    $crear_factura = $db->prepare("INSERT INTO facturas (id_usuario, id_servicio, numero_factura, servicio_contratado, fecha_emision, fecha_vencimiento, subtotal, porcentaje_iva, monto_total, estado, creado_en, visto)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?, 1)");
    $crear_pago = $db->prepare("INSERT INTO pagos_registro (factura_id, metodo_pago, referencia_transaccion, fecha_pago, monto_pagado, observaciones, estado_transaccion, moneda)
                                VALUES (?, ?, ?, ?, ?, ?, 'Aprobado', 'COP')");
    $flujo = $db->prepare("UPDATE comprobantes SET created_at = ?, estado = ?, revisado_por = ?, fecha_revision = ?, aprobado_por = ?, fecha_aprobacion = ? WHERE id = ?");
    $total_facturas = 0; $total_recaudos = 0; $total_gastos = 0;
    $cierres_proyecto = [];

    // Aprobación del comprobante: prepara Finanzas, revisa otra persona de Finanzas, aprueba un administrador
    $aprobar = function ($comp_id, DateTimeImmutable $fecha, $preparo) use ($flujo, $finanzas, $aprobadores, $limite) {
        $creado = $fecha->setTime(azar(8, 11), azar(0, 59));
        $revision = $creado->modify('+' . azar(2, 26) . ' hours');
        $aprobacion = $revision->modify('+' . azar(2, 30) . ' hours');
        $revisor = elegir(array_values(array_diff($finanzas, [$preparo])) ?: $aprobadores);
        $aprobador = elegir(array_values(array_diff($aprobadores, [$revisor])) ?: $aprobadores);
        if ($aprobacion <= $limite) {
            $flujo->execute([f($creado), 'Aprobado', $revisor, f($revision), $aprobador, f($aprobacion), $comp_id]);
        } elseif ($revision <= $limite) {
            $flujo->execute([f($creado), 'Revisado', $revisor, f($revision), null, null, $comp_id]);
        } else {
            $flujo->execute([f($creado), 'Pendiente', null, null, null, null, $comp_id]);
        }
    };

    foreach ($meses as $mes) {
        $ultimo_dia = min($mes->modify('last day of this month'), $fin);
        $habiles = diasHabilesEntre($mes, $ultimo_dia);
        if (!$habiles) continue;
        $etiqueta_mes = $nombres_mes[(int) $mes->format('n')] . ' ' . $mes->format('Y');

        // Facturas del mes: soporte mensual de los clientes con contrato y proyectos
        $emitir = [];
        foreach ($con_contrato as $cliente_id) {
            $emitir[] = [$cliente_id, $mantenimiento['id'], $mantenimiento['nombre'] . ' - ' . $etiqueta_mes,
                         redondear($mantenimiento['precio_base'] * decimal(0.95, 1.6), 50000), (float) $mantenimiento['impuesto_porcentaje'], $habiles[0], false];
        }
        for ($k = 0, $proyectos = azar(4, 8); $k < $proyectos; $k++) {
            $s = elegir($servicios);
            $emitir[] = [(int) elegirPeso($peso_cliente), $s['id'], $s['nombre'], redondear($s['precio_base'] * decimal(0.85, 1.5), 50000),
                         (float) $s['impuesto_porcentaje'], elegir($habiles), true];
        }

        foreach ($emitir as [$cliente_id, $servicio_id, $concepto, $subtotal, $iva_pct, $emision, $es_proyecto]) {
            $iva = round($subtotal * $iva_pct / 100, 2);
            $total = $subtotal + $iva;
            $vence = $emision->modify('+' . ($es_proyecto ? 30 : 15) . ' days');
            $crear_factura->execute([$cliente_id, $servicio_id, 'TMP-' . bin2hex(random_bytes(5)), $concepto, $emision->format('Y-m-d'), $vence->format('Y-m-d'),
                                     $subtotal, $iva_pct, $total, f($emision->setTime(azar(8, 12), azar(0, 59)))]);
            $factura_id = (int) $db->lastInsertId();
            $total_facturas++;

            // ¿Ya pagó? Casi todo lo antiguo sí; lo reciente, una parte
            $dias_emitida = (int) $emision->diff($fin)->format('%a');
            $paga = probable($dias_emitida > 45 ? 0.93 : ($dias_emitida > 20 ? 0.7 : 0.3));
            if (!$paga) continue;
            $pago = $emision->modify('+' . azar(3, $es_proyecto ? 38 : 20) . ' days');
            if ($pago > $fin) $pago = $fin;
            while (!esHabil($pago) && $pago > $emision) $pago = $pago->modify('-1 day');
            $metodo = elegirPeso(['Transferencia' => 58, 'PSE' => 24, 'Tarjeta' => 10, 'Consignación' => 8]);
            $retencion = probable(0.45) ? round($subtotal * 0.04, 2) : 0;          // retención por servicios que practica el cliente
            $valor = round($total - $retencion, 2);
            $momento = $pago->setTime(azar(8, 17), azar(0, 59));
            $crear_pago->execute([$factura_id, $metodo, strtoupper(substr($metodo, 0, 3)) . '-' . $pago->format('ymd') . '-' . azar(1000, 9999), f($momento), $valor,
                                  $retencion > 0 ? 'Pago con retención en la fuente del 4%' : null]);
            $pago_id = (int) $db->lastInsertId();

            $f = $db->query("SELECT f.*, u.nombre, u.organizacion FROM facturas f JOIN usuarios u ON u.id = f.id_usuario WHERE f.id = $factura_id")->fetch(PDO::FETCH_ASSOC);
            $preparo = elegir($finanzas);
            $ci = registrarIngresoFactura($db, $f, ['fecha' => $pago->format('Y-m-d'), 'metodo' => $metodo, 'referencia' => null, 'valor' => $valor,
                                                    'retencion' => $retencion, 'estado' => 'Pendiente', 'origen' => $metodo === 'PSE' ? 'Pasarela' : 'Manual',
                                                    'usuario' => $preparo, 'pago_id' => $pago_id]);
            $aprobar($ci['id'], $pago, $preparo);
            recalcularFactura($db, $factura_id);
            $total_recaudos++;
            if ($es_proyecto && probable(0.45) && $pago->modify('+5 days') <= $fin) $cierres_proyecto[] = [$cliente_id, $pago->modify('+' . azar(2, 5) . ' days')];
        }

        // Gastos del mes (comprobantes de egreso): [día hábil, proveedor, concepto, cuenta, base, iva %, retención %, cuenta retención]
        $gastos = [
            [2, 'Nube Andina Servicios S.A.S.', 'Hosting y dominio de la plataforma', '514525', redondear(decimal(118000, 142000), 100), 19, 0, null],
            [4, 'Inmobiliaria Torres del Parque S.A.S.', 'Arriendo de la oficina - ' . $etiqueta_mes, '512010', 3200000, 0, 3.5, '236530'],
            [6, 'Conectividad Total S.A.', 'Internet y telefonía de la oficina', '513535', 159580, 19, 0, null],
            [9, 'Servicios Públicos de Energía', 'Energía eléctrica de la oficina', '513530', redondear(decimal(240000, 360000), 10), 0, 0, null],
            [9, 'Acueducto y Alcantarillado', 'Acueducto y alcantarillado de la oficina', '513525', redondear(decimal(60000, 95000), 10), 0, 0, null],
            [11, 'Licencias Analíticas Ltda.', 'Licencias de software de análisis y visualización', '513520', redondear(decimal(820000, 1180000), 1000), 19, 0, null],
            [13, 'Patricia Gómez Rincón', 'Honorarios contables - ' . $etiqueta_mes, '511025', 1300000, 0, 11, '236515'],
            [15, 'Distribuidora Café y Aseo', 'Elementos de aseo y cafetería', '519525', redondear(decimal(110000, 210000), 100), 19, 0, null],
        ];
        if (probable(0.6)) $gastos[] = [17, 'Papelería El Escritorio', 'Útiles y papelería', '519530', redondear(decimal(70000, 160000), 100), 19, 0, null];
        if (probable(0.7)) $gastos[] = [18, 'Caja menor', 'Transporte a reuniones con clientes', '519545', redondear(decimal(60000, 190000), 100), 0, 0, null];
        $sueldos = $mes >= new DateTimeImmutable('2026-06-01') ? 18600000 : 16200000;
        $gastos[] = [99, 'Nómina DataSense', 'Nómina de ' . mb_strtolower($etiqueta_mes), '510506', $sueldos, 0, 0, null, ['510527', 'Auxilio de transporte', 400000]];
        $gastos[] = [99, 'Banco', 'Gastos bancarios del mes', '530505', redondear(decimal(28000, 46000), 100), 0, 0, null];

        $salidas_mes = 0;
        foreach ($gastos as $g) {
            [$dia_n, $proveedor, $concepto, $cuenta, $base, $iva_pct, $ret_pct, $cuenta_ret] = $g;
            $fecha = $dia_n >= 99 ? end($habiles) : ($habiles[min(count($habiles), $dia_n) - 1] ?? end($habiles));
            $iva = round($base * $iva_pct / 100, 0);
            $retencion = round($base * $ret_pct / 100, 0);
            $lineas = [[$cuenta, $concepto, $base, 0]];
            if (isset($g[8])) { $lineas[] = [$g[8][0], $g[8][1], $g[8][2], 0]; $base += $g[8][2]; }
            if ($iva > 0) $lineas[] = [CTA_IVA_DESCONTABLE, 'IVA descontable', $iva, 0];
            if ($retencion > 0) $lineas[] = [$cuenta_ret, 'Retención en la fuente ' . $ret_pct . '%', 0, $retencion];
            $neto = $base + $iva - $retencion;
            $lineas[] = [CTA_BANCOS, 'Pago ' . mb_strtolower($concepto), 0, $neto];
            $tercero = guardarTercero($db, (string) (800000000 + abs(crc32($proveedor)) % 99999999), $proveedor, 'Proveedor');
            $preparo = elegir($finanzas);
            $ce = guardarComprobante($db, ['tipo' => 'CE', 'fecha' => $fecha->format('Y-m-d'), 'tercero_id' => $tercero['id'], 'tercero_nombre' => $proveedor,
                                           'tercero_documento' => $tercero['documento'], 'concepto' => $concepto, 'metodo_pago' => 'Transferencia',
                                           'valor_total' => $neto, 'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $preparo], $lineas);
            $aprobar($ce['id'], $fecha, $preparo);
            $salidas_mes += $neto;
            $total_gastos++;
        }
        // Gravamen a los movimientos financieros (4 por mil) de lo que salió del banco en el mes
        $gmf = round($salidas_mes * 0.004, 0);
        $tercero = guardarTercero($db, '800000004', 'Banco', 'Proveedor');
        $preparo = elegir($finanzas);
        $ce = guardarComprobante($db, ['tipo' => 'CE', 'fecha' => end($habiles)->format('Y-m-d'), 'tercero_id' => $tercero['id'], 'tercero_nombre' => 'Banco',
                                       'tercero_documento' => $tercero['documento'], 'concepto' => 'Gravamen a los movimientos financieros (4x1000)',
                                       'metodo_pago' => 'Transferencia', 'valor_total' => $gmf, 'estado' => 'Pendiente', 'origen' => 'Manual', 'preparado_por' => $preparo],
                                 [['511595', 'GMF 4x1000 del mes', $gmf, 0], [CTA_BANCOS, 'Débito automático GMF', 0, $gmf]]);
        $aprobar($ce['id'], end($habiles), $preparo);
        $total_gastos++;
    }
    actualizarVencimientos($db);

    // Encuestas: satisfacción de los casos cerrados y cierre de proyectos facturados
    foreach ($cierres_proyecto as [$cliente_id, $fecha]) {
        $nota = elegirPeso([5 => 6, 4 => 3, 3 => 1]);
        $encuestas[] = [2, null, null, $cliente_por_id[$cliente_id]['nombre'] ?? 'Cliente',
                        array_map(function () use ($nota) { return max(3, min(5, $nota + azar(-1, 0))); }, range(1, 5)), $nota,
                        elegir(['Proyecto entregado a tiempo y con buena documentación.', 'Muy satisfechos con el resultado.', 'Buen acompañamiento durante todo el proyecto.', '']),
                        f(min($fecha->setTime(azar(9, 17), azar(0, 59)), $limite))];
    }
    $preguntas = [];
    foreach ($db->query("SELECT id, id_encuesta FROM encuestas_preguntas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $p) $preguntas[(int) $p['id_encuesta']][] = (int) $p['id'];
    $crear_encuesta = $db->prepare("INSERT INTO encuestas_respuestas (id_encuesta, tecnico_id, ticket_id, cliente_nombre, calificacion_1, calificacion_2, calificacion_3,
                                    calificacion_4, calificacion_5, calificacion, comentarios, fecha_respuesta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $detalles = [];
    foreach ($encuestas as [$encuesta, $tecnico, $ticket, $cliente, $notas, $general, $comentario, $fecha]) {
        $crear_encuesta->execute([$encuesta, $tecnico, $ticket, $cliente, $notas[0], $notas[1], $notas[2], $notas[3], $notas[4], $general, $comentario, $fecha]);
        $respuesta = (int) $db->lastInsertId();
        foreach (array_slice($preguntas[$encuesta] ?? [], 0, 5) as $i => $pregunta) $detalles[] = [$respuesta, $pregunta, $notas[$i]];
    }
    foreach (array_chunk($detalles, 300) as $bloque) {
        $db->prepare("INSERT INTO encuestas_resultados_detalle (id_respuesta_maestra, id_pregunta, valor_calificacion) VALUES "
                     . implode(', ', array_fill(0, count($bloque), '(?, ?, ?)')))->execute(array_merge(...$bloque));
    }
    // Respuestas de prueba sin cliente que traía el volcado: quedan a nombre de un cliente
    $db->prepare("UPDATE encuestas_respuestas SET cliente_nombre = ? WHERE cliente_nombre LIKE 'Evaluación sin datos%'")->execute([$clientes[0]['nombre'] ?? 'Cliente']);
    $db->exec("UPDATE encuestas_respuestas SET comentarios = '' WHERE comentarios REGEXP '^[0-9 ]+$'");

    // ================================================================
    // PQRSD
    // ================================================================
    $responsables = array_map('intval', $db->query("SELECT usuario_id FROM pqrsd_responsables WHERE recibe_casos = 1")->fetchAll(PDO::FETCH_COLUMN)) ?: $soporte;
    $plantillas_pqrsd = [
        'Petición' => [['Solicitud de copia del contrato de servicio', 'Solicito copia del contrato vigente y de sus anexos para la auditoría interna de la entidad.'],
                       ['Certificación de servicios prestados', 'Requerimos una certificación de los servicios prestados durante el último año para un proceso de contratación.'],
                       ['Capacitación adicional para el equipo', 'Solicitamos una sesión de capacitación sobre el uso de los tableros para el personal nuevo.']],
        'Reclamo'  => [['Cobro duplicado en la factura del mes', 'En la factura de este mes aparece un cobro duplicado del soporte mensual. Solicitamos la revisión y la nota crédito.'],
                       ['El tablero de ventas no actualiza', 'Desde hace dos días el tablero de ventas no muestra los datos actualizados y el sistema presenta error.'],
                       ['Demora en la entrega del informe', 'El informe acordado para el viernes no ha sido entregado y no hemos recibido respuesta del asesor.']],
        'Queja'    => [['Demora en la atención telefónica', 'Llamé en varias ocasiones y no me respondieron. La atención del asesor fue demorada.'],
                       ['Trato recibido en la reunión de seguimiento', 'Queremos dejar constancia de una situación con el trato recibido durante la última reunión de seguimiento.']],
        'Sugerencia' => [['Agregar exportación a Excel en el tablero', 'Sería muy útil poder exportar a Excel los datos del tablero de indicadores.'],
                         ['Recordatorios antes del vencimiento de facturas', 'Sugerimos enviar un recordatorio por correo unos días antes del vencimiento de cada factura.']],
        'Solicitud de información' => [['Información sobre tratamiento de datos personales', 'Solicito información sobre la política de tratamiento de datos personales y los datos que conservan de nuestra empresa.'],
                                       ['Tarifas de los servicios de analítica', 'Solicitamos información sobre las tarifas y la propuesta comercial del servicio de analítica para el próximo año.']],
        'Denuncia' => [['Posible uso indebido de información', 'Reporto un posible uso indebido de información confidencial por parte de un tercero que se presentó como proveedor.']],
        'Consulta' => [['Concepto sobre conservación de datos', 'Consultamos el concepto de la empresa sobre el tiempo de conservación de los datos de nuestros clientes.']],
    ];
    $crear_pqrsd = $db->prepare("INSERT INTO pqrsd (radicado, token_consulta, tipo, tema, anonima, prioritaria, nombre, tipo_documento, documento, correo, telefono, ciudad,
            medio_respuesta, asunto, descripcion, estado, asignado_a, dias_termino, fecha_registro, fecha_limite, fecha_respuesta, respuesta, respondido_por,
            fecha_cierre, cerrado_por, motivo_cierre, calificacion)
        VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 'Correo', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $hist_pqrsd = [];
    $total_pqrsd = 0;
    foreach ($meses as $mes) {
        $habiles = diasHabilesEntre($mes, min($mes->modify('last day of this month'), $fin));
        for ($k = 0, $n = azar(5, 9); $k < $n && $habiles; $k++) {
            $tipo = elegirPeso(['Petición' => 30, 'Reclamo' => 20, 'Queja' => 9, 'Sugerencia' => 14, 'Solicitud de información' => 21, 'Denuncia' => 3, 'Consulta' => 3]);
            [$asunto, $descripcion] = elegir($plantillas_pqrsd[$tipo]);
            $registro = enJornada(elegir($habiles), 7, 19);
            $dias = diasTerminoPqrsd($tipo);
            $vence = sumarDiasHabilesPqrsd(f($registro), $dias);
            $anonima = $tipo === 'Denuncia' && probable(0.5);
            $nombre = $anonima ? null : persona();
            $responsable = elegir($responsables);
            $respuesta_el = new DateTimeImmutable(sumarDiasHabilesPqrsd(f($registro), probable(0.93) ? azar(2, max(3, $dias - 2)) : $dias + azar(1, 4)));
            $respuesta_el = $respuesta_el->setTime(azar(9, 16), azar(0, 59));
            if ($respuesta_el <= $limite) {
                $estado = elegirPeso(['Respondida' => 55, 'Cerrada' => 40, 'Trasladada' => 3, 'Desistida' => 2]);
            } else {
                $estado = elegirPeso(['Radicada' => 2, 'En trámite' => 6, 'En espera del ciudadano' => 1]);
            }
            $respondida = in_array($estado, ['Respondida', 'Cerrada'], true);
            $cierre = $estado === 'Cerrada' ? $respuesta_el->modify('+' . azar(1, 4) . ' days') : null;
            if ($cierre && $cierre > $limite) { $estado = 'Respondida'; $cierre = null; }
            $crear_pqrsd->execute([
                'TMP-' . bin2hex(random_bytes(5)), bin2hex(random_bytes(8)), $tipo, temaPqrsd($asunto, $descripcion), $anonima ? 1 : 0,
                $nombre, $anonima ? null : 'CC', $anonima ? null : cedula(), $anonima ? null : correoDe($nombre), $anonima ? null : telefono(), elegirPeso(CIUDADES),
                $asunto, $descripcion, $estado, $estado === 'Radicada' ? null : $responsable, $dias, f($registro), $vence,
                $respondida ? f($respuesta_el) : null,
                $respondida ? 'Cordial saludo. En atención a su ' . mb_strtolower($tipo) . ', le informamos que revisamos el caso con el área responsable y '
                              . elegir(['se realizaron los ajustes solicitados.', 'adjuntamos la información requerida.', 'se tomaron las medidas correspondientes.', 'agradecemos su aporte, que quedará en el plan de mejora.']) : null,
                $respondida ? $responsable : null, $cierre ? f($cierre) : null, $cierre ? $responsable : null,
                $cierre ? 'Respuesta de fondo entregada al ciudadano' : null, $cierre && probable(0.5) ? elegirPeso([5 => 5, 4 => 4, 3 => 1]) : null,
            ]);
            $id = (int) $db->lastInsertId();
            $total_pqrsd++;
            $hist_pqrsd[] = [$id, f($registro), null, 'Radicación', 'Solicitud radicada por el canal web', null, 'Radicada', 1];
            if ($estado !== 'Radicada') $hist_pqrsd[] = [$id, f($registro->modify('+' . azar(1, 15) . ' minutes')), null, 'Asignación automática', 'Asignada al responsable del tema', 'Radicada', 'En trámite', 0];
            if ($respondida) $hist_pqrsd[] = [$id, f($respuesta_el), $responsable, 'Respuesta enviada', 'Respuesta enviada al correo del ciudadano', 'En trámite', 'Respondida', 1];
            if ($cierre) $hist_pqrsd[] = [$id, f($cierre), $responsable, 'Cambio de estado', 'Solicitud cerrada', 'Respondida', 'Cerrada', 1];
        }
    }
    foreach (array_chunk($hist_pqrsd, 200) as $bloque) {
        $db->prepare("INSERT INTO pqrsd_historial (pqrsd_id, fecha, usuario_id, evento, detalle, estado_anterior, estado_nuevo, publico) VALUES "
                     . implode(', ', array_fill(0, count($bloque), '(?, ?, ?, ?, ?, ?, ?, ?)')))->execute(array_merge(...$bloque));
    }

    // ================================================================
    // MENSAJES DEL SITIO, CHAT Y REUNIONES
    // ================================================================
    $mensajes_contacto = [
        'BI' => ['Necesitamos un tablero de indicadores para la gerencia comercial con datos de nuestro ERP.', 'Queremos automatizar los reportes mensuales que hoy hacemos en Excel.',
                 'Buscamos apoyo para migrar nuestros informes a Power BI.'],
        'DS' => ['Nos interesa un modelo para predecir la deserción de clientes.', 'Queremos pronosticar la demanda de nuestros productos para planear compras.'],
        'ASP' => ['La entidad necesita un tablero de seguimiento al plan de desarrollo.', 'Requerimos analítica para el seguimiento de indicadores de gestión pública.'],
        'CGD' => ['Necesitamos ordenar la calidad de los datos de nuestra base de clientes.', 'Queremos definir una política de gobierno de datos para la organización.'],
        'DSW' => ['Buscamos desarrollar una plataforma web para la gestión de solicitudes internas.', 'Necesitamos integrar nuestro sistema con una API de facturación.'],
        'PSI' => ['Queremos agendar una conferencia sobre bienestar y trabajo en equipo para nuestros colaboradores.', 'Nos interesa una charla de orientación vocacional para estudiantes de grado once.'],
    ];
    $crear_contacto = $db->prepare("INSERT INTO contactos (nombre, correo, mensaje, estado, tipo_cliente, servicio, fecha_registro, leido) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $total_contactos = 0;
    foreach ($meses as $mes) {
        $dias_mes = diasHabilesEntre($mes, min($mes->modify('last day of this month'), $fin));
        for ($k = 0, $n = azar(11, 17); $k < $n && $dias_mes; $k++) {
            $servicio = elegirPeso(['BI' => 34, 'DS' => 16, 'ASP' => 12, 'CGD' => 12, 'DSW' => 14, 'PSI' => 12]);
            $fecha = enJornada(elegir($dias_mes), 7, 21);
            $edad = (int) $fecha->diff($limite)->format('%a');
            $estado = $edad < 4 ? elegirPeso(['Nuevo' => 7, 'En Calificación' => 3])
                    : ($edad < 30 ? elegirPeso(['En Calificación' => 2, 'Contactado' => 4, 'En Propuesta' => 3, 'Negociación' => 2, 'Descartado' => 2])
                    : elegirPeso(['Contactado' => 2, 'En Propuesta' => 1, 'Negociación' => 1, 'Convertido' => 3, 'Descartado' => 4]));
            $nombre = persona();
            $crear_contacto->execute([$nombre, correoDe($nombre), elegir($mensajes_contacto[$servicio]), $estado,
                                      elegirPeso(['Privado' => 6, 'Público' => 2, 'mixto' => 2]), $servicio, f($fecha), $estado === 'Nuevo' ? 0 : 1]);
            $total_contactos++;
        }
    }

    $conversaciones = [
        [['Hola, ¿qué servicios ofrecen?', 'Hola. En DataSense ofrecemos inteligencia de negocios, ciencia de datos, gobierno de datos, analítica para el sector público y desarrollo de software. ¿Sobre cuál quieres saber más?'],
         ['Tableros de Power BI', 'Diseñamos tableros a la medida, conectados a tus fuentes de datos y con actualización automática. ¿Quieres que un consultor te contacte?'],
         ['Sí, por favor', 'Perfecto. Déjanos tu correo en el formulario de contacto y te escribimos en menos de un día hábil.']],
        [['Buenas tardes, necesito una cotización', 'Con gusto. ¿Para qué servicio necesitas la cotización?'],
         ['Un modelo predictivo de ventas', 'Los modelos predictivos parten de un diagnóstico de tus datos. Un consultor puede agendar una reunión virtual para revisar el alcance.'],
         ['¿Cuánto se demora?', 'Depende del alcance; un primer modelo suele estar listo entre cuatro y ocho semanas.']],
        [['¿Cómo radico una PQRSD?', 'Puedes radicarla en la sección PQRSD del sitio. Recibirás un número de radicado para consultar el estado.'],
         ['Gracias', 'Con gusto. ¿Hay algo más en lo que te pueda ayudar?']],
        [['Quiero información de las conferencias de psicología', 'Ofrecemos conferencias sobre psicología de pareja, trabajo en equipo, orientación vocacional y bienestar. ¿Es para una empresa o un colegio?'],
         ['Para una empresa', 'Excelente. Puedes ver el detalle en la sección de conferencias y solicitar la fecha desde el formulario.']],
        [['Tengo un problema con mi tablero', 'Lamento el inconveniente. Si eres cliente, puedes registrar un caso en el Centro de Soporte y un especialista lo atiende según su prioridad.'],
         ['¿Dónde está el centro de soporte?', 'Ingresa a tu cuenta y elige Centro de Soporte en el menú. Ahí verás el estado de tus casos.']],
    ];
    $crear_sesion = $db->prepare("INSERT INTO ds_chat_sessions (nombre_usuario, empresa_entidad, fecha_inicio, estado, visto) VALUES (?, ?, ?, 'finalizado', 1)");
    $mensajes_chat = [];
    $total_chats = 0;
    foreach ($meses as $mes) {
        $dias_mes = diasHabilesEntre($mes, min($mes->modify('last day of this month'), $fin));
        for ($k = 0, $n = azar(9, 15); $k < $n && $dias_mes; $k++) {
            $inicio_chat = enJornada(elegir($dias_mes), 7, 22);
            $empresa = probable(0.6) ? elegir(array_column($empresas_nuevas, 0)) : elegir(['Independiente', 'Colegio San Martín de Porres', 'Tienda en línea', 'Empresa de logística', '']);
            $crear_sesion->execute([elegir(NOMBRES) . ' ' . elegir(APELLIDOS), $empresa, f($inicio_chat)]);
            $sesion = (int) $db->lastInsertId();
            $momento = $inicio_chat;
            foreach (elegir($conversaciones) as [$pregunta, $respuesta]) {
                $momento = $momento->modify('+' . azar(15, 70) . ' seconds');
                $mensajes_chat[] = [$sesion, 'user', $pregunta, f($momento)];
                $momento = $momento->modify('+' . azar(2, 6) . ' seconds');
                $mensajes_chat[] = [$sesion, 'bot', $respuesta, f($momento)];
            }
            $total_chats++;
        }
    }
    foreach (array_chunk($mensajes_chat, 300) as $bloque) {
        $db->prepare("INSERT INTO ds_chat_messages (session_id, remitente, mensaje, timestamp) VALUES " . implode(', ', array_fill(0, count($bloque), '(?, ?, ?, ?)')))
           ->execute(array_merge(...$bloque));
    }

    $temas_reunion = ['Revisión de avance del tablero comercial', 'Inicio del proyecto de gobierno de datos', 'Seguimiento mensual del soporte BI', 'Presentación de resultados del modelo predictivo',
                      'Levantamiento de requerimientos de integración', 'Comité de seguimiento del contrato', 'Validación del diccionario de datos', 'Capacitación en el uso de los tableros',
                      'Cierre de fase y entrega de documentación', 'Revisión de indicadores de calidad de datos'];
    $crear_reunion = $db->prepare("INSERT INTO reuniones_virtuales (titulo, codigo_sala, fecha, hora_inicio, hora_fin, objetivo, cliente_id, estado, created_at, link_reunion, acta_reunion)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $total_reuniones = 0;
    $agendar = function (DateTimeImmutable $dia, $estado) use ($crear_reunion, $temas_reunion, $peso_cliente, $cliente_por_id, &$total_reuniones) {
        $codigo = 'DS_' . strtoupper(bin2hex(random_bytes(4)));
        $inicio_r = $dia->setTime(azar(8, 16), elegir([0, 30]));
        $cliente = (int) elegirPeso($peso_cliente);
        $tema = elegir($temas_reunion);
        $crear_reunion->execute([$tema . ' - ' . ($cliente_por_id[$cliente]['organizacion'] ?? ''), $codigo, $dia->format('Y-m-d'), $inicio_r->format('H:i:s'),
                                 $inicio_r->modify('+' . elegir([30, 45, 60, 60, 90]) . ' minutes')->format('H:i:s'),
                                 'Revisar con el cliente: ' . mb_strtolower($tema) . '.', $cliente, $estado, f($dia->modify('-' . azar(2, 10) . ' days')->setTime(azar(8, 17), azar(0, 59))),
                                 'https://meet.jit.si/' . $codigo,
                                 $estado === 'Finalizada' ? 'Se revisaron los avances y se acordaron los siguientes pasos. Compromisos: ' . elegir(['enviar el informe ajustado', 'validar los datos con el área', 'programar la capacitación', 'aprobar el alcance de la siguiente fase']) . '.' : null]);
        $total_reuniones++;
    };
    foreach ($meses as $mes) {
        $dias_mes = diasHabilesEntre($mes, min($mes->modify('last day of this month'), $fin));
        for ($k = 0, $n = azar(4, 7); $k < $n && $dias_mes; $k++) $agendar(elegir($dias_mes), 'Finalizada');
    }
    foreach (array_slice(diasHabilesEntre($hoy->modify('+1 day'), $hoy->modify('+15 days')), 0, 10) as $i => $dia) {
        if ($i % 3 === 0) $agendar($dia, 'Programada');
    }
    // Reuniones de prueba del volcado: con un nombre de verdad
    foreach ($db->query("SELECT id FROM reuniones_virtuales WHERE titulo IN ('prueba', 'ADAD', 'test', 'Prueba') OR LENGTH(titulo) < 5")->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $db->prepare("UPDATE reuniones_virtuales SET titulo = ? WHERE id = ?")->execute([elegir($temas_reunion), $r]);
    }

    // ================================================================
    // NUMERACIÓN EN ORDEN DE FECHA
    // ================================================================
    renumerarDocumentos($db);

    $db->exec("UPDATE ds_instalacion SET estado = 'muestra lista', actualizada = NOW() WHERE id = 2");
    $db->commit();
    avisoMuestra("$total_tickets casos, $total_facturas facturas, $total_recaudos recaudos, $total_gastos egresos, $total_pqrsd PQRSD, "
                 . "$total_contactos mensajes, $total_chats chats, $total_reuniones reuniones y " . count($encuestas) . ' encuestas');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    // ¿Otro servidor los generó mientras este esperaba? Entonces la marca ya existe y no es un error
    if ($db->query("SELECT COUNT(*) FROM ds_instalacion WHERE id = 2")->fetchColumn()) {
        avisoMuestra('otro servidor ya los generó');
        exit(0);
    }
    fwrite(STDERR, 'Datos de muestra: no se generaron (' . $e->getMessage() . ' en la línea ' . $e->getLine() . ")\n");
    exit(1);
}
