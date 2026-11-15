<?php
require_once __DIR__ . '/../includes/seguridad.php';
$db = require __DIR__ . '/../config/database.php';
// 1. SEGURIDAD
requiereAcceso($db, 'catalogo');

// TRAER EL PERFIL Y VARIABLES DE ENCABEZADO
$stmt_user = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$perfil = $stmt_user->fetch(PDO::FETCH_ASSOC);

$titulo = "Catálogo de Servicios";
$subtitulo = "Gestión de portafolio, precios y códigos";

// 2. LÓGICA DE PROCESAMIENTO (CREAR / EDITAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    // Unimos el prefijo con el número (Ej: BI + 021 = BI-021)
    $prefijo = strtoupper(trim($_POST['prefijo_codigo'] ?? ''));
    $numero = trim($_POST['numero_codigo'] ?? '');
    $codigo_final = $prefijo . '-' . str_pad($numero, 3, "0", STR_PAD_LEFT);
    $precio = filter_var($_POST['precio_base'] ?? '', FILTER_VALIDATE_FLOAT);
    $iva = filter_var($_POST['impuesto_porcentaje'] ?? '', FILTER_VALIDATE_FLOAT);
    $estado = in_array($_POST['estado'] ?? '', ['Activo', 'Inactivo'], true) ? $_POST['estado'] : 'Activo';

    // Código PREFIJO-NNN, nombre obligatorio, precio no negativo e IVA entre 0 y 100
    $datos_validos = preg_match('/^[A-Z]{2,5}$/', $prefijo) && ctype_digit($numero)
        && trim($_POST['nombre'] ?? '') !== ''
        && $precio !== false && $precio >= 0
        && $iva !== false && $iva >= 0 && $iva <= 100;
    if (!$datos_validos) {
        header("Location: catalogo.php?err=datos_invalidos"); exit;
    }

    // El código no puede repetirse en otro servicio
    $repetido = $db->prepare("SELECT COUNT(*) FROM servicios_catalogo WHERE codigo_servicio = ? AND id <> ?");
    $repetido->execute([$codigo_final, (int) ($_POST['id_servicio'] ?? 0)]);
    if ($repetido->fetchColumn()) {
        header("Location: catalogo.php?err=codigo_duplicado"); exit;
    }

    if ($_POST['accion'] === 'crear') {
        $stmt = $db->prepare("INSERT INTO servicios_catalogo (codigo_servicio, nombre, descripcion, precio_base, impuesto_porcentaje, estado) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$codigo_final, trim($_POST['nombre']), trim($_POST['descripcion'] ?? ''), $precio, $iva, $estado]);
        header("Location: catalogo.php?msg=creado"); exit;
    }
    elseif ($_POST['accion'] === 'editar') {
        $stmt = $db->prepare("UPDATE servicios_catalogo SET codigo_servicio = ?, nombre = ?, descripcion = ?, precio_base = ?, impuesto_porcentaje = ?, estado = ? WHERE id = ?");
        $stmt->execute([$codigo_final, trim($_POST['nombre']), trim($_POST['descripcion'] ?? ''), $precio, $iva, $estado, (int) $_POST['id_servicio']]);
        header("Location: catalogo.php?msg=editado"); exit;
    }
}

// 3. OBTENER SERVICIOS PARA LA TABLA Y EL JAVASCRIPT
$stmt_servicios = $db->query("SELECT * FROM servicios_catalogo ORDER BY estado ASC, codigo_servicio ASC");
$servicios = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);
$json_catalogo = jsJson($servicios);

// 4. EXTRAER CATEGORÍAS (PREFIJOS) DINÁMICAS DE LA BASE DE DATOS
$stmt_prefijos = $db->query("SELECT DISTINCT SUBSTRING_INDEX(codigo_servicio, '-', 1) as prefijo FROM servicios_catalogo WHERE codigo_servicio LIKE '%-%' ORDER BY prefijo ASC");
$prefijos_db = $stmt_prefijos->fetchAll(PDO::FETCH_COLUMN);

if(empty($prefijos_db)) { $prefijos_db = ['DA', 'BI', 'AI', 'IT', 'SRV']; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Servicios | DataSense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../includes/header.php'; ?>
        
        <div class="container-fluid py-4">
            <div class="toolbar-pro px-0 mb-4 mt-2">
                <div class="search-container-pro shadow-sm flex-grow-1">
                    <i class="fas fa-search"></i>
                    <input type="text" id="buscadorServicios" placeholder="Buscar por código, nombre del servicio o descripción...">
                </div>
                <button class="btn btn-dark rounded-pill fw-bold px-4 shadow-sm" style="background:#0A2E4F;" data-bs-toggle="modal" data-bs-target="#modalServicio" onclick="prepararModalNuevo()">
                    <i class="fas fa-plus-circle me-2"></i> NUEVO SERVICIO
                </button>
            </div>

            <?php if(isset($_GET['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                    <i class="fas fa-check-circle me-2"></i> Catálogo actualizado correctamente.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php
            $errores_catalogo = [
                'datos_invalidos'  => 'Revisa los datos: el código debe ser PREFIJO-NÚMERO, el precio no puede ser negativo y el IVA debe estar entre 0 y 100.',
                'codigo_duplicado' => 'Ese código ya está asignado a otro servicio del catálogo.',
            ];
            ?>
            <?php if (isset($errores_catalogo[$_GET['err'] ?? ''])): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?= $errores_catalogo[$_GET['err']] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">CÓDIGO</th>
                            <th>SERVICIO Y DESCRIPCIÓN</th>
                            <th>DESGLOSE FINANCIERO</th>
                            <th>ESTADO</th>
                            <th class="text-center">GESTIÓN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($servicios)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">Aún no hay servicios en el catálogo.</td></tr>
                        <?php endif; ?>

                        <?php foreach($servicios as $s): 
                            // Cálculo matemático para la tabla
                            $base = $s['precio_base'];
                            $iva_porc = $s['impuesto_porcentaje'];
                            $monto_iva = $base * ($iva_porc / 100);
                            $total_final = $base + $monto_iva;
                        ?>
                        <tr class="<?= $s['estado'] == 'Inactivo' ? 'opacity-50 bg-light' : '' ?>">
                            <td class="ps-4 fw-bold text-muted">
                                <span class="badge bg-secondary bg-opacity-10 text-dark border border-secondary border-opacity-25 px-2 py-1">
                                    <i class="fas fa-hashtag text-secondary me-1" style="font-size:0.7rem;"></i><?= htmlspecialchars($s['codigo_servicio']) ?>
                                </span>
                            </td>
                            <td style="max-width: 300px;">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($s['nombre']) ?></div>
                                <div class="small text-muted text-truncate" title="<?= htmlspecialchars($s['descripcion']) ?>"><?= htmlspecialchars($s['descripcion']) ?></div>
                            </td>
                            <td>
                                <div class="small text-muted mb-1">Base: $ <?= number_format($base, 0, ',', '.') ?></div>
                                <div class="small text-muted mb-1">IVA (<?= floatval($iva_porc) ?>%): $ <?= number_format($monto_iva, 0, ',', '.') ?></div>
                                <div class="fw-bold text-success fs-6 border-top pt-1 mt-1" style="display:inline-block;">
                                    Total: $ <?= number_format($total_final, 0, ',', '.') ?> COP
                                </div>
                            </td>
                            <td>
                                <?php if($s['estado'] == 'Activo'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i> Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill"><i class="fas fa-ban me-1" style="font-size:0.5rem;"></i> Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-light text-primary rounded-circle shadow-sm" title="Editar Servicio" onclick="prepararModalEditar(<?= (int) $s['id'] ?>, <?= jsAttr($s['codigo_servicio']) ?>, <?= jsAttr($s['nombre']) ?>, <?= jsAttr(str_replace("\r", "", $s['descripcion'] ?? '')) ?>, <?= jsAttr((float) $s['precio_base']) ?>, <?= jsAttr((float) $s['impuesto_porcentaje']) ?>, <?= jsAttr($s['estado']) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="modalServicio" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0 py-3">
                <h5 class="fw-bold mb-0 text-dark" id="modalTitle"><i class="fas fa-tag text-primary me-2"></i> Registrar Servicio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST" style="background-color: #f8fafc;">
                <input type="hidden" name="accion" id="formAccion" value="crear">
                <input type="hidden" name="id_servicio" id="formId" value="">
                
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">Categoría y Código</label>
                            <div class="input-group shadow-sm">
                                <select name="prefijo_codigo" id="formPrefijo" class="form-select bg-white fw-bold text-primary border-end-0" style="max-width: 100px;" onchange="actualizarCategoria()" required>
                                    <option value="">Prefijo</option>
                                    <?php foreach($prefijos_db as $pref): ?>
                                        <option value="<?= htmlspecialchars($pref) ?>"><?= htmlspecialchars($pref) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="input-group-text bg-white border-start-0 border-end-0 px-1 text-muted">-</span>
                                <input type="number" name="numero_codigo" id="formNumero" class="form-control border-start-0 rounded-end-pill bg-light fw-bold text-success" placeholder="Auto" required readonly>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <label class="small fw-bold text-info mb-1"><i class="fas fa-magic me-1"></i> Servicio existente</label>
                            <select id="selectPlantillaBase" class="form-select border-info shadow-sm rounded-pill bg-white text-dark" onchange="autollenarDesdePlantilla()">
                                <option value="">-- Selecciona una categoría primero --</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Nombre del Servicio (Editable)</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-briefcase text-muted"></i></span>
                            <input type="text" name="nombre" id="formNombre" class="form-control border-start-0 rounded-end-pill bg-white fw-bold" placeholder="Ej: Consultoría en Datos..." required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Descripción Detallada (Aparecerá en Facturas/Propuestas)</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-4 align-items-start pt-2"><i class="fas fa-align-left text-muted"></i></span>
                            <textarea name="descripcion" id="formDesc" class="form-control border-start-0 rounded-end-4 bg-white" rows="2" style="transition: background 0.3s ease;" required></textarea>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">Precio Base ($ COP)</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-dollar-sign text-success"></i></span>
                                <input type="number" name="precio_base" id="formPrecio" class="form-control border-start-0 rounded-end-pill bg-white" placeholder="0" style="transition: background 0.3s ease;" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">% IVA (Impuesto)</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-percentage text-muted"></i></span>
                                <input type="number" step="0.01" name="impuesto_porcentaje" id="formImpuesto" class="form-control border-start-0 rounded-end-pill bg-white" value="19.00" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="small fw-bold text-muted mb-1">Estado en el Catálogo</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0 rounded-start-pill"><i class="fas fa-toggle-on text-primary"></i></span>
                            <select name="estado" id="formEstado" class="form-select border-start-0 rounded-end-pill bg-white">
                                <option value="Activo">🟢 Activo (Disponible para uso)</option>
                                <option value="Inactivo">🔴 Inactivo (Oculto del sistema)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-0 p-3 bg-light">
                    <button type="submit" class="btn w-100 rounded-pill fw-bold text-white shadow" style="background:#0A2E4F; letter-spacing: 1px;" id="btnSubmit">
                        <i class="fas fa-save me-2"></i> GUARDAR SERVICIO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Base de datos de servicios inyectada en Javascript
    const catalogoDB = <?= $json_catalogo ?>;

    // 1. Se dispara al elegir una categoría (Ej: BI)
    function actualizarCategoria() {
        const prefijo = document.getElementById('formPrefijo').value;
        const numInput = document.getElementById('formNumero');
        const selectPlantilla = document.getElementById('selectPlantillaBase');
        const accion = document.getElementById('formAccion').value;

        if(prefijo) {
            // Filtrar servicios que empiecen por "BI-"
            const filtrados = catalogoDB.filter(s => s.codigo_servicio.startsWith(prefijo + '-'));

            // Calcular código consecutivo solo si estamos creando
            if(accion === 'crear') {
                let maxNum = 0;
                filtrados.forEach(s => {
                    let partes = s.codigo_servicio.split('-');
                    if(partes.length === 2) {
                        let num = parseInt(partes[1], 10);
                        if(!isNaN(num) && num > maxNum) maxNum = num;
                    }
                });
                numInput.value = (maxNum + 1).toString().padStart(3, '0');
            }

            // Llenar el SELECT de Plantillas Base
            selectPlantilla.innerHTML = '<option value="">-- Seleccionar servicio para copiar datos --</option>';
            filtrados.forEach(s => {
                let option = document.createElement('option');
                option.value = s.id;
                option.text = s.nombre;
                selectPlantilla.appendChild(option);
            });
        } else {
            numInput.value = '';
            selectPlantilla.innerHTML = '<option value="">-- Selecciona una categoría primero --</option>';
        }
    }

    // 2. Se dispara al elegir un servicio de la lista de plantillas
    function autollenarDesdePlantilla() {
        const idSeleccionado = document.getElementById('selectPlantillaBase').value;
        const accion = document.getElementById('formAccion').value;

        // Solo copiamos si estamos en modo "Crear"
        if(accion === 'crear' && idSeleccionado) {
            // Buscamos los datos de ese servicio en el arreglo
            const srv = catalogoDB.find(s => s.id == idSeleccionado);
            
            if(srv) {
                // LLENADO MÁGICO
                document.getElementById('formNombre').value = srv.nombre;
                document.getElementById('formDesc').value = srv.descripcion;
                document.getElementById('formPrecio').value = srv.precio_base;
                document.getElementById('formImpuesto').value = srv.impuesto_porcentaje;

                // Efecto visual (Parpadeo verde) para confirmar llenado
                const inputs = ['formNombre', 'formDesc', 'formPrecio'];
                inputs.forEach(id => {
                    document.getElementById(id).style.backgroundColor = '#f0fdf4';
                    setTimeout(() => { document.getElementById(id).style.backgroundColor = '#ffffff'; }, 1000);
                });
            }
        } else if (idSeleccionado === "") {
            // Si vuelve a "Seleccionar", limpiamos
            document.getElementById('formNombre').value = '';
            document.getElementById('formDesc').value = '';
            document.getElementById('formPrecio').value = '';
        }
    }

    function prepararModalNuevo() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-tag text-primary me-2"></i> Registrar Nuevo Servicio';
        document.getElementById('formAccion').value = 'crear';
        document.getElementById('formId').value = '';
        
        document.getElementById('formPrefijo').selectedIndex = 0;
        document.getElementById('formNumero').value = '';
        document.getElementById('formNombre').value = '';
        document.getElementById('formDesc').value = '';
        document.getElementById('formPrecio').value = '';
        document.getElementById('formImpuesto').value = '19.00';
        document.getElementById('formEstado').value = 'Activo';
        
        // Resetear lista de plantillas
        document.getElementById('selectPlantillaBase').innerHTML = '<option value="">-- Selecciona una categoría primero --</option>';
        
        let btn = document.getElementById('btnSubmit');
        btn.innerHTML = '<i class="fas fa-save me-2"></i> GUARDAR SERVICIO';
        btn.style.background = '#3FAE49'; 
    }

    function prepararModalEditar(id, codigo, nombre, desc, precio, imp, estado) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-primary me-2"></i> Editar Servicio';
        document.getElementById('formAccion').value = 'editar';
        document.getElementById('formId').value = id;
        
        let partes_codigo = codigo.split('-');
        if(partes_codigo.length === 2) {
            document.getElementById('formPrefijo').value = partes_codigo[0];
            document.getElementById('formNumero').value = partes_codigo[1];
        } else {
            document.getElementById('formNumero').value = codigo;
        }

        document.getElementById('formNombre').value = nombre;
        document.getElementById('formDesc').value = desc;
        document.getElementById('formPrecio').value = precio;
        document.getElementById('formImpuesto').value = imp;
        document.getElementById('formEstado').value = estado;
        
        let btn = document.getElementById('btnSubmit');
        btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i> ACTUALIZAR CAMBIOS';
        btn.style.background = '#0A2E4F'; 

        // En modo edición, deshabilitamos la copia de plantillas para evitar confusiones
        document.getElementById('selectPlantillaBase').innerHTML = '<option value="">(Deshabilitado en modo edición)</option>';

        var modal = new bootstrap.Modal(document.getElementById('modalServicio'));
        modal.show();
    }
    // --- LÓGICA DE BÚSQUEDA EN CATÁLOGO ---
    document.getElementById('buscadorServicios').addEventListener('input', function() {
        let filtro = this.value.toLowerCase().trim();
        let filas = document.querySelectorAll('table tbody tr');
        
        filas.forEach(fila => {
            let texto = fila.innerText.toLowerCase();
            fila.style.display = texto.includes(filtro) ? '' : 'none';
        });
    });
</script>

</body>
</html>