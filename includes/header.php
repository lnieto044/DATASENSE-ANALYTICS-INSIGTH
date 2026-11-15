<?php


// 1. Lógica del Avatar (Falla-Safe)
$nombre_usuario = $perfil['nombre'] ?? 'Usuario';
$rol_usuario = $perfil['rol'] ?? 'Admin';

if (!empty($perfil['foto'])) {
    // Si hay foto en la base de datos (binario), la convertimos a Base64
    $img_src = imagenDataUri($perfil['foto']);
} else {
    // 🌟 Si no hay foto, generamos el avatar con iniciales (UI-Avatars)
    // Usamos el azul corporativo de DataSense (#0A2E4F)
    $img_src = "https://ui-avatars.com/api/?name=" . urlencode($nombre_usuario) . "&background=0A2E4F&color=fff&bold=true&size=128";
}
?>

<div class="top-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom shadow-sm">
    <div class="d-flex align-items-center gap-3 encabezado-titulo">
        <button type="button" class="btn-menu-movil" id="btnMenuMovil" aria-label="Mostrar u ocultar el menú" title="Mostrar u ocultar el menú" aria-controls="sidebar" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>
        <div class="encabezado-textos">
            <h2 class="fw-bold mb-0 text-dark titulo-pagina" style="letter-spacing: -0.5px;"><?php echo $titulo ?? 'Panel Principal'; ?></h2>
            <small class="text-muted fw-medium subtitulo-pagina"><?php echo $subtitulo ?? 'Control operativo y análisis de datos'; ?></small>
        </div>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        
        <?php if(isset($tipo_campana)): ?>
        <a href="#" id="contenedor-campana" class="btn btn-light border-0 shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
            <i id="icono-campana" class="fas fa-bell fs-5 text-danger campana-sonando"></i>
        </a>
        <?php endif; ?>

        <div class="dropdown">
            <div class="profile-pill d-flex align-items-center gap-2 dropdown-toggle shadow-sm border border-light-subtle" 
                 id="userMenu" 
                 data-bs-toggle="dropdown" 
                 aria-expanded="false" 
                 style="cursor: pointer; padding: 5px 15px 5px 5px; border-radius: 50px; background: #fdfdfd; transition: all 0.3s ease;">
                
                <img src="<?php echo $img_src; ?>" 
                     class="profile-img shadow-sm" 
                     alt="Avatar de <?php echo htmlspecialchars($nombre_usuario); ?>"
                     style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%; border: 2px solid #fff;">
                
                <div class="d-none d-md-block text-start pe-2">
                    <p class="mb-0 fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.2;">
                        <?php echo htmlspecialchars($nombre_usuario); ?>
                    </p>
                    <small class="text-success d-flex align-items-center gap-1" style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase;">
                        <i class="fas fa-circle" style="font-size: 0.4rem;"></i> En Línea | <?php echo htmlspecialchars($rol_usuario); ?>
                    </small>
                </div>
            </div>

            <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg mt-2 rounded-3" aria-labelledby="userMenu" style="min-width: 200px;">
                <li class="px-3 py-2 d-md-none border-bottom mb-2">
                    <p class="mb-0 fw-bold small"><?php echo htmlspecialchars($nombre_usuario); ?></p>
                    <small class="text-muted"><?php echo htmlspecialchars($rol_usuario); ?></small>
                </li>
                <li>
                    <a class="dropdown-item py-2 fw-bold" href="perfil.php">
                        <i class="fas fa-user-circle text-primary me-2"></i> Mi Perfil
                    </a>
                </li>
                
                <li><hr class="dropdown-divider opacity-50"></li>
                <li>
                    <a class="dropdown-item py-2 fw-bold text-danger" href="logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> Cerrar Sesión
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<style>
    .profile-pill:hover {
        background-color: #f1f5f9 !important;
        transform: translateY(-1px);
    }
    .dropdown-toggle::after {
        display: none; /* Quitamos la flechita fea de Bootstrap */
    }
    .profile-img {
        flex-shrink: 0;
    }
</style>
<?php if(isset($tipo_campana)): ?>
<script>
function revisarNotificacionesLocales() {
    // Arma la ruta automáticamente: chequear_mensajes.php, chequear_clientes.php o chequear_facturas.php
    let endpoint = 'chequear_<?php echo $tipo_campana; ?>.php';
    let url = endpoint + '?t=' + new Date().getTime();
    
    fetch(url) 
    .then(response => response.text())
    .then(data => {
        let cantidad = parseInt(data);
        let contenedor = document.getElementById('contenedor-campana');
        
        if (contenedor) {
            if (cantidad > 0) {
                // Hay cosas nuevas: Aparece la campana roja y sonando
                contenedor.classList.remove('d-none');
                contenedor.classList.add('d-flex');
            } else {
                // No hay nada: Desaparece la campana por completo
                contenedor.classList.remove('d-flex');
                contenedor.classList.add('d-none');
            }
        }
    })
    .catch(error => console.error('Error al revisar notificaciones:', error));
}

document.addEventListener("DOMContentLoaded", function() {
    revisarNotificacionesLocales();
    setInterval(revisarNotificacionesLocales, 10000);
});
</script>
<?php endif; ?>
