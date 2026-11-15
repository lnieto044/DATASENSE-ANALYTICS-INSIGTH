<?php
/**
 * TIENDA - FINALIZAR COMPRA
 * Exige una cuenta de cliente activa (quien no tiene cuenta la crea en segundos y vuelve aquí). Pide documento y datos de
 * entrega (si hay productos físicos), crea el pedido con su factura y lleva al cliente a pagarla en su panel.
 */
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/tienda.php';
require_once __DIR__ . '/includes/tienda_layout.php';

iniciarSesionSegura();
$db = require __DIR__ . '/config/database.php';
if (!tiendaDisponible($db)) {
    http_response_code(503);
    exit('La tienda estará disponible muy pronto.');
}

// Usuario vigente (se relee de la base: una cuenta suspendida no puede comprar)
$usuario = null;
if (!empty($_SESSION['admin_id'])) {
    $stmt = $db->prepare("SELECT id, nombre, correo, telefono, organizacion, rol, estado_cuenta FROM usuarios WHERE id = ?");
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($usuario && in_array($usuario['estado_cuenta'], ESTADOS_BLOQUEADOS, true)) $usuario = null;
}
$es_cliente = $usuario && $usuario['rol'] === 'Cliente';

$lineas = lineasCarrito($db);
if (!$lineas) {
    header('Location: carrito.php');
    exit;
}

$error = null;
$datos = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datos = $_POST;
    if (!csrfValido()) $error = 'La página expiró. Revisa tus datos y confirma de nuevo.';
    elseif (!$es_cliente) $error = 'Para comprar debes ingresar con una cuenta de cliente.';
    elseif (empty($_POST['acepto'])) $error = 'Debes aceptar los términos de compra y el tratamiento de tus datos.';
    else {
        list($pedido_id, $error) = crearPedidoTienda($db, $usuario, $_POST);
        if ($pedido_id) {
            header('Location: admin/mis_pedidos.php?pedido=' . (int) $pedido_id . '&nuevo=1');
            exit;
        }
        $lineas = lineasCarrito($db);
        if (!$lineas) {
            header('Location: carrito.php');
            exit;
        }
    }
}

$t = totalesTienda($db, $lineas, cuponSesion());
$tercero = $es_cliente ? terceroDeUsuario($db, $usuario['id']) : null;
$valor = function ($campo, $defecto = '') use ($datos) { return esc(is_string($datos[$campo] ?? null) ? $datos[$campo] : $defecto); };

cabeceraTienda($db, 'Finalizar compra', ['migas' => [['Tienda', 'tienda.php'], ['Carrito', 'carrito.php'], ['Finalizar compra', null]]]);
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 fw-bold mb-0"><i class="fas fa-lock me-2 text-success"></i>Finalizar compra</h1>
        <div class="pasos-compra">
            <span class="hecho"><b><i class="fas fa-check"></i></b>Carrito</span><i class="fas fa-chevron-right"></i>
            <span class="actual"><b>2</b>Datos y confirmación</span><i class="fas fa-chevron-right"></i>
            <span><b>3</b>Pago</span>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert" id="errorCheckout"><i class="fas fa-circle-exclamation mt-1"></i><div><?= esc($error) ?></div></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <?php if (!$usuario): ?>
            <div class="panel-tienda text-center py-5" id="checkoutIngreso">
                <i class="fas fa-user-lock fa-3x text-secondary opacity-50 mb-3"></i>
                <h2 class="h4 fw-bold">Ingresa para terminar tu compra</h2>
                <p class="text-muted mx-auto" style="max-width:460px">Tu carrito se conserva. Con tu cuenta recibes la factura, pagas en línea y sigues el estado de tu pedido.</p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="<?= esc(enlaceIngresoTienda()) ?>" class="btn btn-carrito px-4"><i class="fas fa-right-to-bracket me-2"></i>Ya tengo cuenta</a>
                    <a href="<?= esc(enlaceIngresoTienda() . '&tab=registro') ?>" class="btn btn-comprar px-4"><i class="fas fa-user-plus me-2"></i>Crear cuenta gratis</a>
                </div>
            </div>
            <?php elseif (!$es_cliente): ?>
            <div class="panel-tienda" id="checkoutNoCliente">
                <h2 class="h5 fw-bold"><i class="fas fa-user-shield me-2 text-warning"></i>Estás usando una cuenta del equipo</h2>
                <p class="text-muted mb-3">Ingresaste como <b><?= esc($usuario['nombre']) ?></b> (<?= esc($usuario['rol']) ?>). Las compras se facturan a cuentas de cliente: cierra sesión e ingresa o regístrate como cliente.</p>
                <a href="admin/logout.php" class="btn btn-outline-danger"><i class="fas fa-power-off me-2"></i>Cerrar sesión</a>
            </div>
            <?php else: ?>
            <form method="post" action="checkout.php" id="formCheckout" novalidate>
                <input type="hidden" name="csrf_token" value="<?= esc(tokenCsrf()) ?>">
                <div class="panel-tienda mb-3">
                    <h2 class="h5 fw-bold mb-3"><span class="badge rounded-pill text-bg-dark me-2">1</span>Datos de facturación</h2>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small fw-semibold">Nombre</label><input type="text" class="form-control" value="<?= esc($usuario['nombre']) ?>" readonly></div>
                        <div class="col-md-6"><label class="form-label small fw-semibold">Correo (aquí llega la factura)</label><input type="email" class="form-control" value="<?= esc($usuario['correo']) ?>" readonly></div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="documento">NIT o cédula <?= $tercero ? '' : '<span class="text-muted fw-normal">(opcional)</span>' ?></label>
                            <?php if ($tercero): ?>
                                <input type="text" class="form-control" id="documento" value="<?= esc($tercero['documento']) ?>" readonly>
                            <?php else: ?>
                                <input type="text" class="form-control" id="documento" name="documento" maxlength="20" placeholder="900123456-7" value="<?= $valor('documento') ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6"><label class="form-label small fw-semibold">Empresa</label><input type="text" class="form-control" value="<?= esc($usuario['organizacion'] ?: '—') ?>" readonly></div>
                    </div>
                </div>

                <?php if ($t['requiere_envio']): ?>
                <div class="panel-tienda mb-3" id="bloqueEntrega">
                    <h2 class="h5 fw-bold mb-3"><span class="badge rounded-pill text-bg-dark me-2">2</span>Datos de entrega <small class="text-muted fw-normal fs-6">(tu pedido incluye productos físicos)</small></h2>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small fw-semibold" for="entrega_nombre">Quién recibe *</label><input type="text" class="form-control" id="entrega_nombre" name="entrega_nombre" maxlength="150" required value="<?= $valor('entrega_nombre', $usuario['nombre']) ?>"></div>
                        <div class="col-md-6"><label class="form-label small fw-semibold" for="entrega_telefono">Teléfono *</label><input type="tel" class="form-control" id="entrega_telefono" name="entrega_telefono" maxlength="20" required value="<?= $valor('entrega_telefono', (string) $usuario['telefono']) ?>"></div>
                        <div class="col-md-8"><label class="form-label small fw-semibold" for="entrega_direccion">Dirección *</label><input type="text" class="form-control" id="entrega_direccion" name="entrega_direccion" maxlength="200" required placeholder="Calle 00 # 00-00, oficina" value="<?= $valor('entrega_direccion') ?>"></div>
                        <div class="col-md-4"><label class="form-label small fw-semibold" for="entrega_ciudad">Ciudad *</label><input type="text" class="form-control" id="entrega_ciudad" name="entrega_ciudad" maxlength="80" required list="ciudades" value="<?= $valor('entrega_ciudad') ?>">
                            <datalist id="ciudades"><option>Bogotá</option><option>Medellín</option><option>Cali</option><option>Barranquilla</option><option>Cartagena</option><option>Bucaramanga</option><option>Pereira</option><option>Manizales</option></datalist></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="panel-tienda mb-3">
                    <h2 class="h5 fw-bold mb-3"><span class="badge rounded-pill text-bg-dark me-2"><?= $t['requiere_envio'] ? 3 : 2 ?></span>Pago</h2>
                    <p class="text-muted small mb-2">Al confirmar generamos tu factura y te llevamos a pagarla con el medio que prefieras:</p>
                    <div class="d-flex flex-wrap gap-2 small">
                        <span class="filtro-activo"><i class="fas fa-building-columns"></i>PSE</span><span class="filtro-activo"><i class="far fa-credit-card"></i>Tarjeta</span>
                        <span class="filtro-activo"><i class="fas fa-mobile-screen"></i>Nequi</span><span class="filtro-activo"><i class="fas fa-money-bill"></i>Efectivo</span>
                        <span class="filtro-activo"><i class="fab fa-paypal"></i>PayPal</span><span class="filtro-activo"><i class="fas fa-globe"></i>Zelle, Western Union, USDT</span>
                    </div>
                    <label class="form-label small fw-semibold mt-3" for="notas">Notas para el pedido (opcional)</label>
                    <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="500" placeholder="Horario de entrega, datos del curso, etc."><?= $valor('notas') ?></textarea>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="acepto" value="1" id="acepto" required <?= !empty($datos['acepto']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="acepto">Acepto los términos de compra y autorizo el tratamiento de mis datos personales (Ley 1581 de 2012).</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-comprar w-100 py-3 fs-5" id="btnConfirmarPedido"><i class="fas fa-lock me-2"></i>Confirmar pedido por <?= dinero($t['total']) ?></button>
            </form>
            <?php endif; ?>
        </div>

        <div class="col-lg-5">
            <div class="panel-tienda resumen-compra">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 fw-bold mb-0">Tu pedido</h2>
                    <a href="carrito.php" class="small">Editar carrito</a>
                </div>
                <?php foreach ($lineas as $l): $p = $l['producto']; ?>
                <div class="mini-linea">
                    <?= imagenProductoTienda($p) ?>
                    <div><b><?= esc($p['nombre']) ?></b><span class="small text-muted"><?= (int) $l['cantidad'] ?> × <?= dinero($p['precio']) ?></span></div>
                    <div class="fw-bold small text-nowrap"><?= dinero($l['total']) ?></div>
                </div>
                <?php endforeach; ?>
                <div class="mt-3">
                    <div class="fila"><span>Subtotal</span><span><?= dinero($t['subtotal']) ?></span></div>
                    <?php if ($t['descuento'] > 0): ?><div class="fila text-success"><span>Descuento (<?= esc($t['cupon']['codigo']) ?>)</span><span>−<?= dinero($t['descuento']) ?></span></div><?php endif; ?>
                    <?php if ($t['error_cupon']): ?><div class="alert alert-warning small py-2 mt-2"><?= esc($t['error_cupon']) ?> <a href="carrito.php">Quítalo en el carrito</a>.</div><?php endif; ?>
                    <?php if ($t['requiere_envio']): ?><div class="fila"><span>Envío</span><span><?= $t['envio'] > 0 ? dinero($t['envio']) : 'Gratis' ?></span></div><?php endif; ?>
                    <div class="fila"><span class="text-muted">Base sin IVA</span><span class="text-muted"><?= dinero($t['base']) ?></span></div>
                    <div class="fila"><span class="text-muted">IVA (<?= TIENDA_IVA ?> %)</span><span class="text-muted"><?= dinero($t['iva']) ?></span></div>
                    <div class="fila total"><span>Total a pagar</span><span id="totalCheckout"><?= dinero($t['total']) ?></span></div>
                </div>
                <div class="small text-muted mt-3"><i class="fas fa-file-invoice me-1"></i>Tienes <?= TIENDA_DIAS_PAGO ?> días para pagar la factura; mientras tanto reservamos las unidades.</div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formCheckout');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            form.classList.add('was-validated');
            window.TiendaUI.aviso('Revisa los campos marcados y acepta los términos.', 'warning');
            return;
        }
        const boton = document.getElementById('btnConfirmarPedido');
        boton.disabled = true;
        boton.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Generando tu pedido y factura...';
    });
});
</script>
<?php pieTienda($db); ?>
