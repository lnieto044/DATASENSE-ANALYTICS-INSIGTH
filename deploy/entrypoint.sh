#!/bin/bash
# Arranque del contenedor de demostración:
#   datasense-entrypoint --solo-base  -> (al construir la imagen) crea la base y carga los datos de demostración
#   datasense-entrypoint              -> (al iniciar el servicio) arranca MariaDB, crea el usuario de la app y abre Apache en $PORT
#
# Funciona igual en Render, en la copia local (docker compose) y en Vercel (tiempo de ejecución de contenedores):
#   - Puerto: el de la variable PORT; sin ella, el 80 (el que espera Vercel).
#   - Sin DB_PASS se genera una clave al azar para esa ejecución (la base solo escucha dentro del contenedor).
#   - Si el disco es de solo lectura (fuera de /tmp), la base y los archivos de trabajo de Apache pasan a /tmp.
#   - Base permanente: con DATABASE_URL (o DB_HOST de otro servidor, como TiDB Cloud) no se arranca MariaDB: se usa esa
#     base, que carga los datos de demostración una sola vez y conserva todo lo que se guarde (cron/preparar_base.php).
set -euo pipefail

DATOS=/var/lib/mysql
SOCKET=/run/mysqld/mysqld.sock

# ¿Se puede escribir en esta carpeta? (sin crearla ni dejar rastro)
escribible() {
    local prueba="$1/.prueba-escritura-$$"
    mkdir -p "$1" 2> /dev/null && touch "$prueba" 2> /dev/null && rm -f "$prueba"
}

preparar_disco_base() {
    if ! escribible "$DATOS"; then
        echo "Disco de solo lectura: la base de datos trabaja desde /tmp"
        rm -rf /tmp/mariadb
        cp -a "$DATOS" /tmp/mariadb
        DATOS=/tmp/mariadb
    fi
    if ! escribible /run/mysqld; then
        SOCKET=/tmp/mysqld/mysqld.sock
    fi
}

preparar_disco_apache() {
    if ! escribible /var/run/apache2 || ! escribible /var/lock/apache2; then
        export APACHE_RUN_DIR=/tmp/apache2/run APACHE_LOCK_DIR=/tmp/apache2/lock APACHE_PID_FILE=/tmp/apache2/run/apache2.pid
        mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR"
    fi
}

# MariaDB con su supervisor (mariadbd-safe), que la vuelve a levantar si se cierra.
# USER=root: con el disco en solo lectura el supervisor no detecta que corre como administrador y no cambia al usuario
# mysql, y MariaDB se niega a arrancar.
arrancar_mariadbd() {
    USER=root mariadbd-safe --user=mysql --datadir="$DATOS" --socket="$SOCKET" --pid-file="$(dirname "$SOCKET")/mysqld.pid" > /dev/null 2>&1 &
}

iniciar_mariadb() {
    local carpeta_socket
    carpeta_socket="$(dirname "$SOCKET")"
    mkdir -p "$carpeta_socket"
    chown mysql:mysql "$carpeta_socket"
    if [ ! -d "$DATOS/mysql" ]; then
        mariadb-install-db --user=mysql --datadir="$DATOS" --skip-test-db > /dev/null
    fi
    arrancar_mariadbd
    for _ in $(seq 1 60); do
        if mariadb-admin --socket="$SOCKET" ping --silent > /dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "MariaDB no arrancó en 60 segundos" >&2
    exit 1
}

if [ "${1:-}" = "--solo-base" ]; then
    iniciar_mariadb
    mariadb --socket="$SOCKET" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    mariadb --socket="$SOCKET" --default-character-set=utf8mb4 "$DB_NAME" < /opt/datasense/datasense_demo.sql
    echo "Base de demostración lista: $(mariadb --socket="$SOCKET" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}'") tablas"
    # Un año de actividad para los tableros (cron/datos_muestra.php), con un usuario temporal: al arrancar el servicio
    # el usuario de la aplicación recibe su clave definitiva
    CLAVE_TEMPORAL="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    mariadb --socket="$SOCKET" -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${CLAVE_TEMPORAL}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
    DB_PASS="$CLAVE_TEMPORAL" php /var/www/html/cron/datos_muestra.php
    mariadb-admin --socket="$SOCKET" shutdown
    exit 0
fi

preparar_disco_apache

# ¿La base es permanente (fuera de este servidor)? Lo decide config/conexion.php con las mismas variables que usa el sitio
BASE_EXTERNA="$(php -r 'require "/var/www/html/config/conexion.php"; echo configuracionBaseDatos()["externa"] ? "si" : "no";')"

if [ -n "${DATABASE_URL:-}" ] && [ "$BASE_EXTERNA" != "si" ]; then
    echo "AVISO: DATABASE_URL no tiene la forma mysql://usuario:clave@servidor:puerto/base; se usa la base de demostración" >&2
fi

if [ "$BASE_EXTERNA" = "si" ]; then
    # Base permanente: se prepara (y la primera vez se cargan los datos de demostración); MariaDB local no se usa
    # Si la base permanente no responde (clave cambiada, servicio caído), el sitio arranca igual y salud.php dice el
    # motivo; las páginas muestran el aviso de que no hay conexión en lugar de quedar el servidor caído
    php /var/www/html/cron/preparar_base.php || echo "AVISO: la base permanente no respondió; revisa DATABASE_URL" >&2
    # Un año de actividad para los tableros, una sola vez y en segundo plano (el sitio abre mientras tanto)
    (php /var/www/html/cron/datos_muestra.php || echo "No se generaron los datos de muestra" >&2) &
else
    preparar_disco_base
    iniciar_mariadb

    # Usuario de la aplicación con la clave que genera Render (variable DB_PASS); MariaDB solo escucha dentro del contenedor.
    # Sin DB_PASS (por ejemplo en Vercel sin variables configuradas) se genera una para esta ejecución.
    if [ -z "${DB_PASS:-}" ]; then
        DB_PASS="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
        export DB_PASS
        echo "Sin DB_PASS: se generó una clave para esta ejecución"
    fi
    mariadb --socket="$SOCKET" <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

    # Un año de actividad para los tableros (ya viene en la imagen; esto completa bases locales anteriores)
    php /var/www/html/cron/datos_muestra.php || echo "No se generaron los datos de muestra" >&2

    # Demostración: las fechas de los datos de prueba se mueven para que el mes en curso muestre información
    php /var/www/html/cron/fechas_demo.php || echo "No se pudieron poner al día las fechas de la demostración" >&2

    # Vigilante de la base de datos: si MariaDB deja de responder y su supervisor (mariadbd-safe) ya no está, la vuelve a
    # arrancar. Así un cierre inesperado de la base no deja el sitio caído hasta que alguien reinicie el contenedor.
    (
        while true; do
            sleep 30
            if ! mariadb-admin --socket="$SOCKET" ping --silent > /dev/null 2>&1 && ! pgrep -f mariadbd-safe > /dev/null; then
                echo "$(date '+%Y-%m-%d %H:%M:%S') MariaDB no respondía: se arranca de nuevo" >&2
                arrancar_mariadbd
                sleep 30
            fi
        done
    ) &
fi

# Apache escucha en el puerto de la variable PORT (Render pone 10000); sin ella, en el 80, que es el que usa Vercel.
# La configuración de Apache lee el puerto de PUERTO_WEB: así no hay que modificar archivos al arrancar.
PUERTO="${PORT:-80}"
export PUERTO_WEB="$PUERTO"

# Publicador de redes sociales: cada minuto publica lo que ya llegó a su fecha, aunque nadie tenga el panel abierto
(
    while true; do
        php /var/www/html/cron/publicar_redes.php || true
        sleep 60
    done
) &

echo "DataSense escuchando en el puerto ${PUERTO} (base $([ "$BASE_EXTERNA" = "si" ] && echo permanente || echo de demostración))"
exec apache2-foreground
