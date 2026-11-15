<?php
// Bandeja de PQRSD: peticiones, quejas, reclamos, sugerencias y denuncias del canal ciudadano,
// con el término legal en días hábiles, asignación, respuesta y cierre.
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pqrsd.php';
$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'pqrsd');

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$titulo = 'PQRSD · Canal Ciudadano';
$subtitulo = 'Peticiones, quejas, reclamos, sugerencias y denuncias con sus términos de ley';
$aviso = tomarAviso();

$disponible = pqrsdDisponible($db);
$normativa = $disponible && pqrsdNormativaDisponible($db);
$tema_disponible = $disponible && temaPqrsdDisponible($db);
// Art. 17: las que se pasaron del mes sin que el ciudadano completara la información quedan archivadas
$desistidas_hoy = $normativa ? marcarDesistidasPqrsd($db) : 0;
$resumen = $disponible ? resumenPqrsd($db) : ['total' => 0, 'abiertas' => 0, 'vencidas' => 0, 'por_vencer' => 0, 'respondidas' => 0,
                                              'cumplimiento' => 0.0, 'prorrogadas' => 0, 'esperando' => 0, 'prioritarias' => 0];

// ---------------------------------------------------------------- filtros
$buscar = trim((string) ($_GET['buscar'] ?? ''));
$tipo = (string) ($_GET['tipo'] ?? '');
$estado = (string) ($_GET['estado'] ?? '');
$solo_vencidas = ($_GET['vencidas'] ?? '') === '1';

$solicitudes = [];
if ($disponible) {
    $sql = "SELECT p.*, a.nombre AS nombre_asignado FROM pqrsd p LEFT JOIN usuarios a ON a.id = p.asignado_a WHERE 1 = 1";
    $params = [];
    if ($buscar !== '') {
        $sql .= " AND (p.radicado LIKE ? OR p.asunto LIKE ? OR p.nombre LIKE ? OR p.correo LIKE ? OR p.documento LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = "%$buscar%";
    }
    if (isset(TIPOS_PQRSD[$tipo])) { $sql .= " AND p.tipo = ?"; $params[] = $tipo; }
    if (in_array($estado, ESTADOS_PQRSD, true)) { $sql .= " AND p.estado = ?"; $params[] = $estado; }
    // Las de atención prioritaria (art. 20) se atienden primero, así que encabezan la bandeja
    $stmt_p = $db->prepare($sql . ($normativa ? " ORDER BY p.prioritaria DESC," : " ORDER BY") . " p.fecha_registro DESC, p.id DESC");
    $stmt_p->execute($params);
    $solicitudes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    if ($solo_vencidas) {
        $solicitudes = array_values(array_filter($solicitudes, function ($p) {
            // Las que esperan al ciudadano tienen el término suspendido: no están vencidas
            return pqrsdEnTramite($p) && $p['estado'] !== 'En espera del ciudadano' && diasHabilesRestantesPqrsd(fechaLimitePqrsd($p)) < 0;
        }));
    }
}

// Quién puede quedar como responsable: las cuentas internas con acceso al módulo
$responsables = array_values(array_filter(
    $db->query("SELECT id, nombre, rol FROM usuarios WHERE rol <> 'Cliente' AND estado_cuenta = 'Activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC),
    function ($u) use ($db) { return tieneAcceso($u['rol'], 'pqrsd', $db); }
));

// Datos para la ficha (se abren en la ventana sin volver a consultar)
$fichas = [];
foreach ($solicitudes as $p) {
    $termino = textoTerminoPqrsd($p);
    $fichas[$p['id']] = [
        'id' => (int) $p['id'], 'radicado' => $p['radicado'], 'tipo' => $p['tipo'], 'estado' => $p['estado'],
        'anonima' => (int) $p['anonima'], 'nombre' => $p['nombre'], 'documento' => trim(($p['tipo_documento'] ?? '') . ' ' . ($p['documento'] ?? '')),
        'correo' => $p['correo'], 'telefono' => $p['telefono'], 'ciudad' => $p['ciudad'], 'direccion' => $p['direccion'],
        'medio' => MEDIOS_RESPUESTA_PQRSD[$p['medio_respuesta']] ?? $p['medio_respuesta'],
        'asunto' => $p['asunto'], 'descripcion' => $p['descripcion'], 'adjunto' => $p['adjunto'],
        'registro' => date('d/m/Y H:i', strtotime($p['fecha_registro'])), 'termino' => $termino,
        'dias' => (int) $p['dias_termino'], 'asignado' => (int) $p['asignado_a'], 'respuesta' => $p['respuesta'],
        'tema' => (string) ($p['tema'] ?? ''),
        // Actuaciones de ley (Ley 1755 de 2015)
        'prioritaria' => (int) ($p['prioritaria'] ?? 0), 'motivo_prioritaria' => (string) ($p['motivo_prioritaria'] ?? ''),
        'prorroga_hasta' => !empty($p['prorroga_hasta']) ? date('d/m/Y', strtotime($p['prorroga_hasta'])) : '',
        'prorroga_motivo' => (string) ($p['prorroga_motivo'] ?? ''),
        'tope_prorroga' => $normativa ? date('d/m/Y', strtotime(topeProrrogaPqrsd($p))) : '',
        'requerimiento' => (string) ($p['requerimiento_detalle'] ?? ''),
        'desiste_el' => !empty($p['desiste_el']) ? date('d/m/Y', strtotime($p['desiste_el'])) : '',
        'trasladada_a' => (string) ($p['trasladada_a'] ?? ''),
        'fecha_traslado' => !empty($p['fecha_traslado']) ? date('d/m/Y', strtotime($p['fecha_traslado'])) : '',
        'tope_traslado' => $normativa ? date('d/m/Y', strtotime(fechaTrasladoPqrsd($p))) : '',
        'historial' => array_map(function ($h) {
            return ['fecha' => date('d/m/Y H:i', strtotime($h['fecha'])), 'evento' => $h['evento'], 'detalle' => $h['detalle'],
                    'usuario' => $h['usuario'] ?: 'Automático', 'publico' => (int) $h['publico']];
        }, historialPqrsd($db, $p['id'])),
    ];
}
$abrir = (int) ($_GET['abrir'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PQRSD | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .kpi-pqrsd { background: #fff; border: 0; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); padding: 1rem; height: 100%; }
        .kpi-pqrsd .etiqueta { font-size: .7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .kpi-pqrsd .valor { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
        .tabla-pqrsd { min-width: 900px; }
        .tabla-pqrsd th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .linea-pqrsd { list-style: none; padding: 0; margin: 0; }
        .linea-pqrsd li { border-left: 2px solid #e2e8f0; padding: 0 0 .8rem 1rem; position: relative; }
        .linea-pqrsd li::before { content: ''; position: absolute; left: -5px; top: .35rem; width: 8px; height: 8px; border-radius: 50%; background: #3FAE49; }
        .bloque-descripcion { white-space: pre-line; background: #f8fafc; border-radius: .75rem; padding: 1rem; font-size: .9rem; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>

        <?php if ($aviso): ?>
            <div class="alert alert-<?= esc($aviso['tipo']) ?> alert-dismissible fade show rounded-4 shadow-sm border-0"><i class="fas fa-circle-info me-2"></i><?= esc($aviso['texto']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if (!$disponible): ?>
            <div class="alert alert-warning border-0 shadow-sm rounded-4">Para usar el canal ciudadano aplica <strong>database/modulo_pqrsd.sql</strong> (ya viene incluido en database/datasense_analytics.sql).</div>
        <?php endif; ?>

        <?php if ($desistidas_hoy): ?>
            <div class="alert alert-secondary border-0 shadow-sm rounded-4"><i class="fas fa-box-archive me-2"></i>
                Se archivaron <strong><?= (int) $desistidas_hoy ?></strong> solicitud(es) por desistimiento: se cumplió el mes del artículo 17 sin que el ciudadano completara la información.</div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiTotal"><div class="etiqueta">Radicadas</div><div class="valor"><?= (int) $resumen['total'] ?></div><div class="small text-muted">En total</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiAbiertas"><div class="etiqueta">En trámite</div><div class="valor text-primary"><?= (int) $resumen['abiertas'] ?></div><div class="small text-muted"><?= (int) ($resumen['prorrogadas'] ?? 0) ?> con prórroga (art. 14)</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiVencidas"><div class="etiqueta">Fuera de término</div><div class="valor text-danger"><?= (int) $resumen['vencidas'] ?></div><div class="small text-muted">Requieren respuesta ya</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiPorVencer"><div class="etiqueta">Por vencer</div><div class="valor text-warning"><?= (int) $resumen['por_vencer'] ?></div><div class="small text-muted">3 días hábiles o menos</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiEspera"><div class="etiqueta">Esperando al ciudadano</div><div class="valor text-info"><?= (int) ($resumen['esperando'] ?? 0) ?></div><div class="small text-muted">Término suspendido (art. 17)</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiPrioritarias"><div class="etiqueta">Prioritarias</div><div class="valor text-danger"><?= (int) ($resumen['prioritarias'] ?? 0) ?></div><div class="small text-muted">Atención preferente (art. 20)</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiRespondidas"><div class="etiqueta">Respondidas</div><div class="valor text-success"><?= (int) $resumen['respondidas'] ?></div><div class="small text-muted">Con respuesta enviada</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi-pqrsd" id="kpiCumplimiento"><div class="etiqueta">Dentro del término</div><div class="valor"><?= esc(number_format($resumen['cumplimiento'], 1)) ?>%</div><div class="small text-muted">De las respondidas</div></div></div>
        </div>

        <div class="bg-white p-4 rounded-4 shadow-sm mb-4 border-0">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-4"><label class="small fw-bold text-muted mb-1" for="buscar">Buscar</label>
                    <input type="text" id="buscar" name="buscar" class="form-control rounded-pill px-4" placeholder="Radicado, asunto, ciudadano o documento" value="<?= esc($buscar) ?>"></div>
                <div class="col-md-3"><label class="small fw-bold text-muted mb-1" for="tipo">Tipo</label>
                    <select id="tipo" name="tipo" class="form-select rounded-pill px-4">
                        <option value="">Todos</option>
                        <?php foreach (array_keys(TIPOS_PQRSD) as $t): ?><option value="<?= esc($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= esc($t) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="small fw-bold text-muted mb-1" for="estado">Estado</label>
                    <select id="estado" name="estado" class="form-select rounded-pill px-4">
                        <option value="">Todos</option>
                        <?php foreach (ESTADOS_PQRSD as $e): ?><option value="<?= esc($e) ?>" <?= $estado === $e ? 'selected' : '' ?>><?= esc($e) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2 d-grid"><button type="submit" class="btn btn-dark fw-bold rounded-pill" style="background: var(--azul-sonda);">FILTRAR</button></div>
                <div class="col-12">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="vencidas" value="1" id="vencidas" <?= $solo_vencidas ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label small" for="vencidas">Ver solo las que están fuera de término</label></div>
                </div>
            </form>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tabla-pqrsd" id="tablaPqrsd">
                    <thead><tr><th class="ps-4">Radicado</th><th>Tipo</th><th>Ciudadano</th><th>Asunto</th><th>Estado</th><th>Término</th><th>Responsable</th><th class="text-end pe-4">Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($solicitudes as $p): $t = textoTerminoPqrsd($p); ?>
                            <tr>
                                <td class="ps-4 fw-bold text-nowrap"><?= esc($p['radicado']) ?>
                                    <?php if (!empty($p['prioritaria'])): ?><span class="badge text-bg-danger ms-1" title="<?= esc($p['motivo_prioritaria']) ?>"><i class="fas fa-bolt"></i></span><?php endif; ?>
                                    <div class="small text-muted fw-normal"><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></div></td>
                                <td class="small"><?= esc($p['tipo']) ?><div class="text-muted"><?= (int) $p['dias_termino'] ?> días hábiles</div>
                                    <?php if (!empty($p['tema'])): ?><div class="text-muted"><i class="fas fa-tag me-1"></i><?= esc($p['tema']) ?></div><?php endif; ?></td>
                                <td class="small"><?= $p['anonima'] ? '<span class="badge text-bg-secondary">Anónima</span>' : esc($p['nombre']) ?><?php if (!$p['anonima'] && $p['correo']): ?><div class="text-muted"><?= esc($p['correo']) ?></div><?php endif; ?></td>
                                <td class="small"><?= esc(mb_strimwidth($p['asunto'], 0, 60, '…', 'UTF-8')) ?></td>
                                <td><span class="badge text-bg-<?= esc(COLORES_PQRSD[$p['estado']] ?? 'secondary') ?>"><?= esc($p['estado']) ?></span></td>
                                <td><span class="badge text-bg-<?= esc($t['clase']) ?>"><?= esc($t['etiqueta']) ?></span><div class="small text-muted"><?= esc($t['texto']) ?></div></td>
                                <td class="small"><?= esc($p['nombre_asignado'] ?: 'Sin asignar') ?></td>
                                <td class="text-end pe-4"><button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold btn-ver-pqrsd" data-id="<?= (int) $p['id'] ?>"><i class="fas fa-folder-open me-1"></i>Gestionar</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$solicitudes): ?><tr><td colspan="8" class="text-center py-5 text-muted">No hay solicitudes con esos filtros.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalPqrsd" tabindex="-1" aria-labelledby="pqrsd_radicado">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <form class="modal-content border-0 shadow-lg" method="POST" action="procesar_pqrsd.php" id="formPqrsdGestion">
            <input type="hidden" name="accion" value="gestionar">
            <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
            <input type="hidden" name="id" id="pqrsd_id">
            <div class="modal-header text-white p-4" style="background: var(--azul-sonda);">
                <div>
                    <h5 class="fw-bold mb-0 text-white" id="pqrsd_radicado">—</h5>
                    <div class="small opacity-75" id="pqrsd_encabezado"></div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <h6 class="fw-bold text-dark" id="pqrsd_asunto">—</h6>
                        <div class="bloque-descripcion mb-3" id="pqrsd_descripcion"></div>
                        <div id="pqrsd_bloque_adjunto" class="mb-3" hidden>
                            <a class="btn btn-sm btn-outline-danger rounded-pill" id="pqrsd_adjunto" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i>Ver el soporte adjunto</a>
                        </div>
                        <label class="form-label small fw-bold text-muted" for="pqrsd_respuesta">Respuesta al ciudadano</label>
                        <textarea class="form-control" name="respuesta" id="pqrsd_respuesta" rows="7" placeholder="Escribe la respuesta de fondo, clara y completa."></textarea>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="enviar_correo" value="1" id="pqrsd_enviar" checked>
                            <label class="form-check-label small" for="pqrsd_enviar">Enviar la respuesta al correo del ciudadano y dejarla en el seguimiento</label>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="bg-white rounded-4 p-3 mb-3 shadow-sm">
                            <div class="small fw-bold text-muted text-uppercase mb-2">Ciudadano</div>
                            <div id="pqrsd_ciudadano" class="small"></div>
                        </div>
                        <div class="bg-white rounded-4 p-3 mb-3 shadow-sm">
                            <div class="small fw-bold text-muted text-uppercase mb-2">Gestión</div>
                            <label class="form-label small fw-bold" for="pqrsd_estado">Estado</label>
                            <select class="form-select form-select-sm" name="estado" id="pqrsd_estado">
                                <?php foreach (ESTADOS_MANUALES_PQRSD as $e): ?><option value="<?= esc($e) ?>"><?= esc($e) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-text mb-2">La espera del ciudadano, el traslado y el desistimiento se registran con las actuaciones de ley.</div>
                            <?php if ($tema_disponible): ?>
                                <label class="form-label small fw-bold" for="pqrsd_tema">Tema (orienta el reparto)</label>
                                <select class="form-select form-select-sm mb-2" name="tema" id="pqrsd_tema">
                                    <?php foreach (array_merge(array_keys(TEMAS_PQRSD), [TEMA_PQRSD_OTROS]) as $t): ?>
                                        <option value="<?= esc($t) ?>"><?= esc($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <label class="form-label small fw-bold" for="pqrsd_asignado">Responsable</label>
                            <select class="form-select form-select-sm mb-2" name="asignado_a" id="pqrsd_asignado">
                                <option value="">Sin asignar</option>
                                <?php foreach ($responsables as $r): ?><option value="<?= (int) $r['id'] ?>"><?= esc($r['nombre']) ?> · <?= esc($r['rol']) ?></option><?php endforeach; ?>
                            </select>
                            <label class="form-label small fw-bold" for="pqrsd_nota">Nota interna (no la ve el ciudadano)</label>
                            <textarea class="form-control form-control-sm" name="nota" id="pqrsd_nota" rows="2"></textarea>
                        </div>
                        <?php if ($normativa): ?>
                            <div class="bg-white rounded-4 p-3 mb-3 shadow-sm">
                                <div class="small fw-bold text-muted text-uppercase mb-2">Actuaciones de ley · Ley 1755 de 2015</div>
                                <div id="pqrsd_estado_legal" class="small mb-2"></div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill btn-actuacion" data-accion="prorrogar"><i class="fas fa-hourglass-half me-1"></i>Prorrogar</button>
                                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill btn-actuacion" data-accion="requerir"><i class="fas fa-circle-question me-1"></i>Pedir información</button>
                                    <button type="button" class="btn btn-sm btn-outline-success rounded-pill btn-actuacion" data-accion="completar" id="btnCompletarPqrsd" hidden><i class="fas fa-check me-1"></i>Ya la completó</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill btn-actuacion" data-accion="trasladar"><i class="fas fa-share-from-square me-1"></i>Trasladar</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill btn-actuacion" data-accion="prioritaria"><i class="fas fa-bolt me-1"></i>Prioritaria</button>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="bg-white rounded-4 p-3 shadow-sm">
                            <div class="small fw-bold text-muted text-uppercase mb-2">Seguimiento</div>
                            <ul class="linea-pqrsd small" id="pqrsd_historial"></ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-0 p-4 d-flex justify-content-between">
                <span class="small text-muted" id="pqrsd_termino"></span>
                <button type="submit" class="btn text-white fw-bold rounded-pill px-4" style="background: var(--verde-datasense);" id="btnGuardarPqrsd"><i class="fas fa-save me-2"></i>GUARDAR GESTIÓN</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalActuacion" tabindex="-1" aria-labelledby="actuacion_titulo">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 shadow-lg" method="POST" action="procesar_pqrsd.php" id="formActuacion">
            <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
            <input type="hidden" name="id" id="actuacion_id">
            <input type="hidden" name="accion" id="actuacion_accion">
            <div class="modal-header border-0 pb-0">
                <h6 class="fw-bold mb-0" id="actuacion_titulo">—</h6>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted" id="actuacion_ayuda"></p>
                <div class="mb-3" id="campo_dias" hidden>
                    <label class="form-label small fw-bold" for="actuacion_dias">Días hábiles adicionales</label>
                    <input type="number" class="form-control" name="dias" id="actuacion_dias" min="1" max="60" value="5">
                    <div class="form-text" id="actuacion_tope"></div>
                </div>
                <div class="mb-3" id="campo_entidad" hidden>
                    <label class="form-label small fw-bold" for="actuacion_entidad">Entidad o dependencia competente</label>
                    <input type="text" class="form-control" name="entidad" id="actuacion_entidad" maxlength="150" placeholder="Ej: Superintendencia de Industria y Comercio">
                    <div class="form-text" id="actuacion_tope_traslado"></div>
                </div>
                <div class="mb-3" id="campo_prioritaria" hidden>
                    <label class="form-label small fw-bold" for="actuacion_motivo_prioritaria">Motivo del artículo 20</label>
                    <select class="form-select" name="motivo_prioritaria" id="actuacion_motivo_prioritaria">
                        <?php foreach (MOTIVOS_PRIORITARIOS_PQRSD as $m): ?><option value="<?= esc($m) ?>"><?= esc($m) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="quitar" value="1" id="actuacion_quitar">
                        <label class="form-check-label small" for="actuacion_quitar">Quitar la atención prioritaria</label>
                    </div>
                </div>
                <div id="campo_detalle">
                    <label class="form-label small fw-bold" for="actuacion_detalle" id="actuacion_detalle_label">Detalle</label>
                    <textarea class="form-control" name="detalle" id="actuacion_detalle" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="submit" class="btn text-white fw-bold rounded-pill px-4" style="background: var(--azul-sonda);" id="btnActuacion">REGISTRAR</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const FICHAS = <?= jsJson($fichas) ?>;
const ABRIR = <?= (int) $abrir ?>;

function escHtml(t) { return String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function abrirPqrsd(id) {
    const f = FICHAS[id];
    if (!f) return;
    document.getElementById('pqrsd_id').value = f.id;
    document.getElementById('pqrsd_radicado').innerText = f.radicado;
    document.getElementById('pqrsd_encabezado').innerText = `${f.tipo} · radicada el ${f.registro} · término de ${f.dias} días hábiles`;
    document.getElementById('pqrsd_asunto').innerText = f.asunto;
    document.getElementById('pqrsd_descripcion').innerText = f.descripcion;
    document.getElementById('pqrsd_respuesta').value = f.respuesta || '';
    // El estado actual puede ser uno de las actuaciones de ley (no está en el combo): se muestra sin poder ponerlo a mano
    const selEstado = document.getElementById('pqrsd_estado');
    selEstado.querySelectorAll('option[data-actual]').forEach((o) => o.remove());
    if (!Array.from(selEstado.options).some((o) => o.value === f.estado)) {
        selEstado.insertAdjacentHTML('afterbegin', `<option value="${escHtml(f.estado)}" data-actual="1">${escHtml(f.estado)} (actual)</option>`);
    }
    selEstado.value = f.estado;
    document.getElementById('pqrsd_asignado').value = f.asignado || '';
    document.getElementById('pqrsd_nota').value = '';
    const selTema = document.getElementById('pqrsd_tema');
    if (selTema) selTema.value = Array.from(selTema.options).some((o) => o.value === f.tema) ? f.tema : 'Otros';
    document.getElementById('pqrsd_termino').innerText = f.termino.texto;
    pintarEstadoLegal(f);
    const adjunto = document.getElementById('pqrsd_bloque_adjunto');
    adjunto.hidden = !f.adjunto;
    if (f.adjunto) document.getElementById('pqrsd_adjunto').href = 'descargar_pqrsd.php?id=' + f.id;
    document.getElementById('pqrsd_ciudadano').innerHTML = f.anonima
        ? '<span class="badge text-bg-secondary">Denuncia anónima</span><div class="text-muted mt-2">No dejó datos de contacto: la respuesta no se envía por correo.</div>'
        : `<div class="fw-bold text-dark">${escHtml(f.nombre)}</div>
           <div class="text-muted">${escHtml(f.documento || 'Sin documento')}</div>
           <div><i class="fas fa-envelope me-1 text-muted"></i>${escHtml(f.correo || 'Sin correo')}</div>
           <div><i class="fas fa-phone me-1 text-muted"></i>${escHtml(f.telefono || 'Sin teléfono')}</div>
           <div><i class="fas fa-location-dot me-1 text-muted"></i>${escHtml([f.direccion, f.ciudad].filter(Boolean).join(', ') || 'Sin dirección')}</div>
           <div class="mt-1 small text-muted">Respuesta por: ${escHtml(f.medio)}</div>`;
    document.getElementById('pqrsd_historial').innerHTML = f.historial.length
        ? f.historial.map((h) => `<li><div class="fw-semibold">${escHtml(h.evento)} <span class="text-muted fw-normal">· ${escHtml(h.fecha)}</span>${h.publico ? '' : ' <span class="badge text-bg-light border">interna</span>'}</div>
            ${h.detalle ? `<div class="text-muted">${escHtml(h.detalle)}</div>` : ''}<div class="text-muted small">${escHtml(h.usuario)}</div></li>`).join('')
        : '<li class="text-muted">Sin movimientos todavía.</li>';
    new bootstrap.Modal(document.getElementById('modalPqrsd')).show();
}

// ------------------------------------------------------- actuaciones de ley (Ley 1755 de 2015)
let fichaActual = null;

function pintarEstadoLegal(f) {
    fichaActual = f;
    const caja = document.getElementById('pqrsd_estado_legal');
    if (!caja) return;
    // Una solicitud respondida, cerrada, trasladada o desistida ya salió de trámite: no admite actuaciones
    const cerrada = ['Respondida', 'Cerrada', 'Trasladada', 'Desistida'].includes(f.estado);
    const lineas = [];
    if (f.prioritaria) lineas.push(`<div><span class="badge text-bg-danger">Atención prioritaria</span> <span class="text-muted">${escHtml(f.motivo_prioritaria)}</span></div>`);
    if (f.prorroga_hasta) lineas.push(`<div><strong>Prórroga vigente</strong> hasta el ${escHtml(f.prorroga_hasta)}. <span class="text-muted">${escHtml(f.prorroga_motivo)}</span></div>`);
    else if (f.tope_prorroga && !cerrada) lineas.push(`<div class="text-muted">Se puede prorrogar, cuando más, hasta el ${escHtml(f.tope_prorroga)} (art. 14).</div>`);
    if (f.estado === 'En espera del ciudadano') lineas.push(`<div><strong>Esperando al ciudadano</strong> hasta el ${escHtml(f.desiste_el)}: ${escHtml(f.requerimiento)}</div>`);
    if (f.trasladada_a) lineas.push(`<div><strong>Trasladada</strong> a ${escHtml(f.trasladada_a)} el ${escHtml(f.fecha_traslado)} (art. 21).</div>`);
    else if (f.tope_traslado && !cerrada) lineas.push(`<div class="text-muted">Si no es de nuestra competencia, el traslado va hasta el ${escHtml(f.tope_traslado)} (art. 21).</div>`);
    if (cerrada) lineas.push(`<div class="text-muted">La solicitud salió de trámite (${escHtml(f.estado)}): no admite más actuaciones de ley.</div>`);
    caja.innerHTML = lineas.join('');
    // Los botones solo se ofrecen cuando sirven
    document.querySelectorAll('.btn-actuacion').forEach((boton) => {
        boton.hidden = cerrada || (boton.dataset.accion === 'completar' && f.estado !== 'En espera del ciudadano');
    });
}

const ACTUACIONES = {
    prorrogar: { titulo: 'Prorrogar el término (art. 14)', boton: 'REGISTRAR LA PRÓRROGA', campos: ['dias'],
        ayuda: 'Se le avisa al ciudadano antes de que venza el término, con los motivos y la nueva fecha. La prórroga no puede pasar del doble del plazo inicial.',
        etiqueta: 'Motivos de la prórroga (los lee el ciudadano)' },
    requerir: { titulo: 'Pedir información al ciudadano (art. 17)', boton: 'ENVIAR EL REQUERIMIENTO', campos: [],
        ayuda: 'El término queda suspendido y el ciudadano tiene un mes para completar lo que falta. Si no responde, se entiende que desistió y se archiva.',
        etiqueta: 'Qué información o documento le falta' },
    completar: { titulo: 'El ciudadano completó la información', boton: 'REANUDAR EL TRÁMITE', campos: [],
        ayuda: 'El término vuelve a correr completo desde hoy.', etiqueta: 'Nota para el seguimiento (opcional)' },
    trasladar: { titulo: 'Trasladar por competencia (art. 21)', boton: 'REGISTRAR EL TRASLADO', campos: ['entidad'],
        ayuda: 'Si el asunto no nos corresponde, se traslada dentro de los cinco días siguientes a la radicación y se le informa al ciudadano.',
        etiqueta: 'Detalle del traslado (opcional)' },
    prioritaria: { titulo: 'Atención prioritaria (art. 20)', boton: 'GUARDAR', campos: ['prioritaria'],
        ayuda: 'Niños, niñas y adolescentes, personas adultas mayores, personas en situación de discapacidad, periodistas en ejercicio o riesgo de un perjuicio irremediable a un derecho fundamental.',
        etiqueta: 'Nota para el seguimiento (opcional)' },
};

function abrirActuacion(accion) {
    const f = fichaActual;
    const cfg = ACTUACIONES[accion];
    if (!f || !cfg) return;
    document.getElementById('actuacion_id').value = f.id;
    document.getElementById('actuacion_accion').value = accion;
    document.getElementById('actuacion_titulo').innerText = `${f.radicado} · ${cfg.titulo}`;
    document.getElementById('actuacion_ayuda').innerText = cfg.ayuda;
    document.getElementById('actuacion_detalle_label').innerText = cfg.etiqueta;
    document.getElementById('btnActuacion').innerText = cfg.boton;
    const detalle = document.getElementById('actuacion_detalle');
    detalle.value = '';
    detalle.required = ['prorrogar', 'requerir'].includes(accion);
    document.getElementById('campo_dias').hidden = !cfg.campos.includes('dias');
    document.getElementById('campo_entidad').hidden = !cfg.campos.includes('entidad');
    document.getElementById('campo_prioritaria').hidden = !cfg.campos.includes('prioritaria');
    document.getElementById('actuacion_tope').innerText = f.tope_prorroga ? `Cuando más hasta el ${f.tope_prorroga}: el doble del término inicial.` : '';
    document.getElementById('actuacion_tope_traslado').innerText = f.tope_traslado ? `Dentro de los 5 días siguientes a la radicación: hasta el ${f.tope_traslado}.` : '';
    document.getElementById('actuacion_quitar').checked = false;
    if (f.motivo_prioritaria) document.getElementById('actuacion_motivo_prioritaria').value = f.motivo_prioritaria;

    // La ficha se cierra para que quede una sola ventana abierta; al guardar se vuelve a abrir.
    // Se muestra con un respaldo por tiempo: si el navegador no avisa que la ficha se cerró, igual aparece.
    const ficha = document.getElementById('modalPqrsd');
    const ventana = new bootstrap.Modal(document.getElementById('modalActuacion'));
    const mostrar = function () { if (!document.getElementById('modalActuacion').classList.contains('show')) ventana.show(); };
    const abierta = bootstrap.Modal.getInstance(ficha);
    if (abierta && ficha.classList.contains('show')) {
        ficha.addEventListener('hidden.bs.modal', mostrar, { once: true });
        abierta.hide();
        setTimeout(mostrar, 500);
    } else {
        mostrar();
    }
}

document.querySelectorAll('.btn-actuacion').forEach((b) => b.addEventListener('click', () => abrirActuacion(b.dataset.accion)));
document.querySelectorAll('.btn-ver-pqrsd').forEach((b) => b.addEventListener('click', () => abrirPqrsd(b.dataset.id)));
if (ABRIR && FICHAS[ABRIR]) abrirPqrsd(ABRIR);
</script>
</body>
</html>
