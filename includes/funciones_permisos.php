<?php
// Función para verificar si un rol tiene acceso a un módulo
function tieneAcceso($rol, $modulo, $db) {
    // El Admin siempre tiene acceso a todo por seguridad
    if ($rol === 'Admin') return true;

    // Caché por petición: el menú lateral consulta muchos módulos en cada página
    static $cache = [];
    $clave = $rol . '|' . $modulo;
    if (!isset($cache[$clave])) {
        $stmt = $db->prepare("SELECT acceso FROM permisos_roles WHERE rol = ? AND modulo = ? LIMIT 1");
        $stmt->execute([$rol, $modulo]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$clave] = ($resultado && $resultado['acceso'] == 1);
    }

    return $cache[$clave];
}
?>