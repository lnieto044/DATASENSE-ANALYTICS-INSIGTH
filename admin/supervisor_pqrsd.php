<?php
/**
 * SUPERVISIÓN PQRSD - CANAL CIUDADANO
 * Torre de control del supervisor del canal: términos en riesgo, solicitudes sin responsable, reparto
 * automático (quién recibe, qué tipos atiende y con qué capacidad), desempeño de cada persona y metas.
 */
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/pqrsd.php';
require_once __DIR__ . '/../includes/utilidades.php';
$db = require __DIR__ . '/../config/database.php';
$usuario_actual = requiereAcceso($db, 'supervisor_pqrsd');

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([(int) $usuario_actual['id']]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

$vista = in_array($_GET['vista'] ?? '', ['responsables', 'configuracion'], true) ? $_GET['vista'] : 'tablero';
$titulo = 'Supervisión PQRSD';
$subtitulo = 'Términos en riesgo, reparto de solicitudes y desempeño del canal ciudadano';
$aviso = tomarAviso();

$disponible = pqrsdDisponible($db);
$normativa = $disponible && pqrsdNormativaDisponible($db);
$reparto = $disponible && repartoPqrsdDisponible($db);
$cfg = configPqrsd($db);

// Al abrir se pone al día lo que manda la ley y lo que manda la configuración
$desistidas_hoy = $normativa ? marcarDesistidasPqrsd($db) : 0;
$repartidas_hoy = $reparto ? repartirPqrsdPendientes($db) : 0;

$resumen = $disponible ? resumenPqrsd($db) : ['total' => 0, 'abiertas' => 0, 'vencidas' => 0, 'por_vencer' => 0, 'respondidas' => 0,
                                             'cumplimiento' => 0.0, 'prorrogadas' => 0, 'esperando' => 0, 'prioritarias' => 0];
$responsables = $disponible ? responsablesPqrsd($db) : [];
$cargas = $disponible ? cargasResponsablesPqrsd($db) : [];

// Desempeño del mes en curso
$desde = date('Y-m-01');
$hasta = date('Y-m-d');
$desempeno = $disponible ? desempenoPqrsd($db, $desde, $hasta) : [];

// Lo que necesita atención: sin responsable, vencidas y por vencer
$pendientes = [];
if ($disponible) {
    $filas = $db->query("SELECT p.*, u.nombre AS nombre_asignado FROM pqrsd p LEFT JOIN usuarios u ON u.id = p.asignado_a
                         WHERE p.estado NOT IN ('Respondida', 'Cerrada', 'Trasladada', 'Desistida')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $p) {
        $termino = textoTerminoPqrsd($p);
        $restantes = $p['estado'] === 'En espera del ciudadano' ? null : diasHabilesRestantesPqrsd(fechaLimitePqrsd($p));
        $pendientes[] = ['p' => $p, 'termino' => $termino, 'restantes' => $restantes,
                         'urgente' => $restantes !== null && $restantes <= 3, 'sin_dueno' => empty($p['asignado_a'])];
    }
    usort($pendientes, function ($a, $b) {
        return [$b['sin_dueno'], !empty($b['p']['prioritaria']), $a['restantes'] ?? 99] <=> [$a['sin_dueno'], !empty($a['p']['prioritaria']), $b['restantes'] ?? 99];
    });
}
$sin_asignar = array_values(array_filter($pendientes, function ($f) { return $f['sin_dueno']; }));
$csrf = tokenCsrf();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisión PQRSD | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .kpi-sup { background: #fff; border: 0; border-radius: 1rem; box-shadow: 0 2px 12px rgba(15, 23, 42, .06); padding: 1rem; height: 100%; }
        .kpi-sup .etiqueta { font-size: .7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .kpi-sup .valor { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
        .tabla-sup { min-width: 860px; }
        .tabla-sup th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #f8fafc; white-space: nowrap; }
        .barra-carga { height: 8px; border-radius: 4px; background: #e2e8f0; overflow: hidden; }
        .barra-carga span { display: block; height: 100%; background: linear-gradient(90deg, #3FAE49, #0B4F8A); }
        .fila-urgente { background: #fef2f2; }
        .fila-sin-dueno { background: #fffbeb; }
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
        <?php if (!$reparto): ?>
            <div class="alert alert-warning border-0 shadow-sm rounded-4">Para usar el reparto automático aplica <strong>database/datasense_analytics.sql</strong>: falta la tabla de responsables del canal.</div>
        <?php endif; ?>
        <?php if ($desistidas_hoy || $repartidas_hoy): ?>
            <div class="alert alert-info border-0 shadow-sm rounded-4" id="avisoAutomatico"><i class="fas fa-robot me-2"></i>
                <?php if ($repartidas_hoy): ?>Se repartieron <strong><?= (int) $repartidas_hoy ?></strong> solicitud(es) al abrir el tablero. <?php endif; ?>
                <?php if ($desistidas_hoy): ?>Se archivaron <strong><?= (int) $desistidas_hoy ?></strong> por desistimiento (art. 17).<?php endif; ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?= $vista === 'tablero' ? 'active' : '' ?>" href="supervisor_pqrsd.php" id="vista_tablero"><i class="fas fa-satellite-dish me-1"></i> Torre de control</a></li>
            <li class="nav-item"><a class="nav-link <?= $vista === 'responsables' ? 'active' : '' ?>" href="supervisor_pqrsd.php?vista=responsables" id="vista_responsables"><i class="fas fa-users-gear me-1"></i> Responsables y reparto</a></li>
            <li class="nav-item"><a class="nav-link <?= $vista === 'configuracion' ? 'active' : '' ?>" href="supervisor_pqrsd.php?vista=configuracion" id="vista_configuracion"><i class="fas fa-sliders me-1"></i> Metas y reparto</a></li>
        </ul>

        <?php if ($vista === 'tablero'): ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3"><div class="kpi-sup" id="kpiSinAsignar"><div class="etiqueta">Sin responsable</div><div class="valor text-warning"><?= count($sin_asignar) ?></div><div class="small text-muted">Esperan reparto</div></div></div>
                <div class="col-6 col-lg-3"><div class="kpi-sup" id="kpiSupVencidas"><div class="etiqueta">Fuera de término</div><div class="valor text-danger"><?= (int) $resumen['vencidas'] ?></div><div class="small text-muted">Incumplen la ley</div></div></div>
                <div class="col-6 col-lg-3"><div class="kpi-sup" id="kpiSupPorVencer"><div class="etiqueta">Por vencer</div><div class="valor text-warning"><?= (int) $resumen['por_vencer'] ?></div><div class="small text-muted">3 días hábiles o menos</div></div></div>
                <div class="col-6 col-lg-3"><div class="kpi-sup" id="kpiSupCumplimiento"><div class="etiqueta">Dentro del término</div><div class="valor"><?= esc(number_format($resumen['cumplimiento'], 1)) ?>%</div><div class="small text-muted">Meta: <?= (int) $cfg['meta_cumplimiento'] ?>%</div></div></div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h2 class="h6 fw-bold mb-0"><i class="fas fa-inbox me-2 text-primary"></i>Solicitudes en trámite (<?= count($pendientes) ?>)</h2>
                        <form method="POST" action="procesar_supervisor_pqrsd.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                            <input type="hidden" name="accion" value="repartir">
                            <button type="submit" class="btn btn-sm btn-dark rounded-pill fw-bold px-3" id="btnRepartir" style="background: var(--azul-sonda);"><i class="fas fa-shuffle me-1"></i>REPARTIR LAS QUE NO TIENEN RESPONSABLE</button>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-sup" id="tablaPendientes">
                            <thead><tr><th class="ps-3">Radicado</th><th>Tipo</th><th>Estado</th><th>Término</th><th>Responsable</th><th class="text-end pe-3">Reasignar</th></tr></thead>
                            <tbody>
                                <?php foreach ($pendientes as $f): $p = $f['p']; ?>
                                    <tr class="<?= $f['sin_dueno'] ? 'fila-sin-dueno' : ($f['urgente'] ? 'fila-urgente' : '') ?>">
                                        <td class="ps-3 fw-bold text-nowrap"><a href="pqrsd.php?abrir=<?= (int) $p['id'] ?>" class="text-decoration-none"><?= esc($p['radicado']) ?></a>
                                            <?php if (!empty($p['prioritaria'])): ?><span class="badge text-bg-danger ms-1" title="<?= esc($p['motivo_prioritaria']) ?>"><i class="fas fa-bolt"></i></span><?php endif; ?>
                                            <div class="small text-muted fw-normal"><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></div></td>
                                        <td class="small"><?= esc($p['tipo']) ?></td>
                                        <td><span class="badge text-bg-<?= esc(COLORES_PQRSD[$p['estado']] ?? 'secondary') ?>"><?= esc($p['estado']) ?></span></td>
                                        <td><span class="badge text-bg-<?= esc($f['termino']['clase']) ?>"><?= esc($f['termino']['etiqueta']) ?></span>
                                            <div class="small text-muted"><?= esc($f['termino']['texto']) ?></div></td>
                                        <td class="small"><?= $p['asignado_a'] ? esc($p['nombre_asignado']) : '<span class="badge text-bg-warning">Sin responsable</span>' ?></td>
                                        <td class="text-end pe-3">
                                            <form method="POST" action="procesar_supervisor_pqrsd.php" class="d-flex gap-1 justify-content-end form-reasignar-pqrsd">
                                                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                                <input type="hidden" name="accion" value="reasignar">
                                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                <select name="asignado_a" class="form-select form-select-sm" style="min-width: 160px;" aria-label="Responsable de <?= esc($p['radicado']) ?>">
                                                    <option value="0">Automático</option>
                                                    <?php foreach ($responsables as $r): ?>
                                                        <option value="<?= (int) $r['id'] ?>" <?= (int) $p['asignado_a'] === $r['id'] ? 'selected' : '' ?>><?= esc($r['nombre']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">Asignar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$pendientes): ?><tr><td colspan="6" class="text-center py-4 text-muted">No hay solicitudes en trámite: todo está respondido.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-1"><i class="fas fa-ranking-star me-2 text-primary"></i>Desempeño del mes (<?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?>)</h2>
                    <p class="small text-muted">Bonificación desde <?= (int) $cfg['bono_cumplimiento'] ?>% · plan de mejora por debajo de <?= (int) $cfg['alerta_cumplimiento'] ?>% · se califica con <?= (int) $cfg['minimo_para_medir'] ?> solicitudes atendidas o más.
                        <a href="reporte_desempeno.php" class="text-decoration-none">Ver el reporte completo</a>.</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-sup" id="tablaDesempenoSup">
                            <thead><tr><th class="ps-3">Persona</th><th>Carga</th><th>Atendidas</th><th>Dentro del término</th><th>Días hábiles</th><th>Fuera de término</th><th class="pe-3">Calificación</th></tr></thead>
                            <tbody>
                                <?php foreach ($desempeno as $d): $cal = CALIFICACIONES_DESEMPENO[$d['calificacion']]; ?>
                                    <tr>
                                        <td class="ps-3"><span class="fw-semibold"><?= esc($d['nombre']) ?></span><div class="small text-muted"><?= esc($d['rol']) ?></div></td>
                                        <td style="min-width: 120px;">
                                            <?php $cap = $responsables[$d['id']]['capacidad'] ?? CAPACIDAD_PQRSD_POR_DEFECTO; $usado = (float) ($cargas[$d['id']]['carga'] ?? 0); ?>
                                            <div class="barra-carga"><span style="width: <?= (int) min(100, $cap ? $usado * 100 / $cap : 0) ?>%"></span></div>
                                            <div class="small text-muted"><?= esc(number_format($usado, 1, ',', '')) ?> de <?= (int) $cap ?><?= $d['esperando'] ? ' · ' . (int) $d['esperando'] . ' esperando' : '' ?></div>
                                        </td>
                                        <td class="fw-bold"><?= (int) $d['respondidas'] ?></td>
                                        <td><?= $d['cumplimiento'] === null ? '<span class="text-muted">—</span>' : '<span class="fw-bold">' . esc(number_format($d['cumplimiento'], 1)) . '%</span>' ?>
                                            <div class="small text-muted"><?= (int) $d['a_tiempo'] ?> de <?= (int) $d['respondidas'] ?></div></td>
                                        <td><?= $d['dias_promedio'] === null ? '—' : esc(number_format($d['dias_promedio'], 1, ',', '')) ?></td>
                                        <td><?= $d['vencidas'] ? '<span class="badge text-bg-danger">' . (int) $d['vencidas'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                                        <td class="pe-3"><span class="badge text-bg-<?= esc($cal['clase']) ?>"><i class="fas <?= esc($cal['icono']) ?> me-1"></i><?= esc($cal['texto']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$desempeno): ?><tr><td colspan="7" class="text-center py-4 text-muted">Todavía no hay personas configuradas para atender PQRSD.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($vista === 'responsables'): ?>
            <form method="POST" action="procesar_supervisor_pqrsd.php" id="formResponsables">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                <input type="hidden" name="accion" value="guardar_responsables">
                <input type="hidden" name="vista" value="responsables">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold mb-1"><i class="fas fa-users-gear me-2 text-primary"></i>Quién atiende el canal ciudadano</h2>
                        <p class="small text-muted mb-4">El reparto busca primero a quien atiende <b>el tema</b> de la solicitud (facturación, técnico, datos personales...);
                            si nadie lo tiene marcado, a quien atiende <b>ese tipo</b>; y si tampoco, a quien atiende de todo. Entre los candidatos escoge al de menor
                            carga que no se pase de su capacidad. Las de atención prioritaria pesan doble. El tema se deduce del asunto y se corrige en la ficha.</p>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 tabla-sup" id="tablaResponsables">
                                <thead><tr><th class="ps-3">Persona</th><th>Recibe solicitudes</th><th>Tipos que atiende</th><th>Temas que atiende</th><th>Capacidad</th><th class="pe-3">Carga hoy</th></tr></thead>
                                <tbody>
                                    <?php foreach ($responsables as $r): ?>
                                        <tr>
                                            <td class="ps-3"><span class="fw-semibold"><?= esc($r['nombre']) ?></span><div class="small text-muted"><?= esc($r['rol']) ?> · <?= esc($r['cargo'] ?: 'sin cargo') ?></div></td>
                                            <td><div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="recibe[<?= (int) $r['id'] ?>]" value="1" id="recibe_<?= (int) $r['id'] ?>" <?= $r['recibe_casos'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="recibe_<?= (int) $r['id'] ?>">Sí</label>
                                            </div></td>
                                            <td>
                                                <select class="form-select form-select-sm" name="tipos[<?= (int) $r['id'] ?>][]" multiple size="4" style="min-width: 210px;" aria-label="Tipos que atiende <?= esc($r['nombre']) ?>">
                                                    <?php foreach (array_keys(TIPOS_PQRSD) as $t): ?>
                                                        <option value="<?= esc($t) ?>" <?= in_array($t, $r['tipos'], true) ? 'selected' : '' ?>><?= esc($t) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">Sin marcar nada atiende todos.</div>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm" name="temas[<?= (int) $r['id'] ?>][]" multiple size="4" style="min-width: 210px;" aria-label="Temas que atiende <?= esc($r['nombre']) ?>">
                                                    <?php foreach (array_merge(array_keys(TEMAS_PQRSD), [TEMA_PQRSD_OTROS]) as $t): ?>
                                                        <option value="<?= esc($t) ?>" <?= in_array($t, $r['temas'], true) ? 'selected' : '' ?>><?= esc($t) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">El tema manda sobre el tipo.</div>
                                            </td>
                                            <td><input type="number" class="form-control form-control-sm" name="capacidad[<?= (int) $r['id'] ?>]" value="<?= (int) $r['capacidad'] ?>" min="1" max="200" style="width: 90px;" aria-label="Capacidad de <?= esc($r['nombre']) ?>"></td>
                                            <td class="pe-3 small"><?= esc(number_format((float) ($cargas[$r['id']]['carga'] ?? 0), 1, ',', '')) ?> · <?= (int) ($cargas[$r['id']]['abiertas'] ?? 0) ?> abierta(s)</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$responsables): ?><tr><td colspan="6" class="text-center py-4 text-muted">No hay cuentas internas con acceso al módulo PQRSD.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn text-white fw-bold rounded-pill px-4 mt-4" style="background: var(--verde-datasense);" id="btnGuardarResponsables"><i class="fas fa-save me-2"></i>GUARDAR EL REPARTO</button>
                    </div>
                </div>
            </form>

        <?php else: ?>
            <form method="POST" action="procesar_supervisor_pqrsd.php" id="formConfigPqrsd">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                <input type="hidden" name="accion" value="guardar_configuracion">
                <input type="hidden" name="vista" value="configuracion">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body p-4">
                                <h2 class="h6 fw-bold mb-3"><i class="fas fa-shuffle me-2 text-primary"></i>Reparto automático</h2>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="asignacion_automatica" value="1" id="asignacion_automatica" <?= ($cfg['asignacion_automatica'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="asignacion_automatica">Asignar cada solicitud en cuanto se radica</label>
                                </div>
                                <p class="small text-muted mb-0">Con esto activo, la persona recibe el correo con el radicado y el término desde el primer momento,
                                    igual que en la mesa de servicios. Si se apaga, las solicitudes quedan sin responsable hasta que alguien las reparta a mano.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body p-4">
                                <h2 class="h6 fw-bold mb-3"><i class="fas fa-bullseye me-2 text-primary"></i>Metas y bonificación</h2>
                                <div class="row g-3">
                                    <div class="col-6"><label class="form-label small fw-bold" for="meta_cumplimiento">Meta de cumplimiento (%)</label>
                                        <input type="number" class="form-control" name="meta_cumplimiento" id="meta_cumplimiento" min="1" max="100" value="<?= (int) $cfg['meta_cumplimiento'] ?>"></div>
                                    <div class="col-6"><label class="form-label small fw-bold" for="bono_cumplimiento">Bonificación desde (%)</label>
                                        <input type="number" class="form-control" name="bono_cumplimiento" id="bono_cumplimiento" min="1" max="100" value="<?= (int) $cfg['bono_cumplimiento'] ?>"></div>
                                    <div class="col-6"><label class="form-label small fw-bold" for="alerta_cumplimiento">Plan de mejora por debajo de (%)</label>
                                        <input type="number" class="form-control" name="alerta_cumplimiento" id="alerta_cumplimiento" min="1" max="100" value="<?= (int) $cfg['alerta_cumplimiento'] ?>"></div>
                                    <div class="col-6"><label class="form-label small fw-bold" for="minimo_para_medir">Mínimo para calificar</label>
                                        <input type="number" class="form-control" name="minimo_para_medir" id="minimo_para_medir" min="1" max="100" value="<?= (int) $cfg['minimo_para_medir'] ?>">
                                        <div class="form-text">Solicitudes atendidas en el periodo.</div></div>
                                </div>
                                <p class="small text-muted mt-3 mb-0">La plataforma mide; la decisión de bonificar o abrir un plan de mejora es de la empresa.
                                    Conviene revisarlo con la persona antes de tomar cualquier medida laboral.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn text-white fw-bold rounded-pill px-4 mt-4" style="background: var(--verde-datasense);" id="btnGuardarConfigPqrsd"><i class="fas fa-save me-2"></i>GUARDAR</button>
            </form>
        <?php endif; ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
