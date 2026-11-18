#!/bin/bash
# Arranque del contenedor de demostración:
#   datasense-entrypoint --solo-base  -> (al construir la imagen) crea la base y carga los datos de demostración
#   datasense-entrypoint              -> (al iniciar el servicio) arranca MariaDB, crea el usuario de la app y abre Apache en $PORT
set -euo pipefail

DATOS=/var/lib/mysql
SOCKET=/run/mysqld/mysqld.sock

iniciar_mariadb() {
    mkdir -p /run/mysqld
    chown mysql:mysql /run/mysqld
    if [ ! -d "$DATOS/mysql" ]; then
        mariadb-install-db --user=mysql --datadir="$DATOS" --skip-test-db > /dev/null
    fi
    mariadbd-safe --user=mysql --datadir="$DATOS" > /dev/null 2>&1 &
    for _ in $(seq 1 60); do
        if mariadb-admin --socket="$SOCKET" ping --silent > /dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "MariaDB no arrancó en 60 segundos" >&2
    exit 1
}

iniciar_mariadb

if [ "${1:-}" = "--solo-base" ]; then
    mariadb --socket="$SOCKET" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    mariadb --socket="$SOCKET" --default-character-set=utf8mb4 "$DB_NAME" < /opt/datasense/datasense_demo.sql
    echo "Base de demostración lista: $(mariadb --socket="$SOCKET" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}'") tablas"
    mariadb-admin --socket="$SOCKET" shutdown
    exit 0
fi

# Usuario de la aplicación con la clave que genera Render (variable DB_PASS); MariaDB solo escucha dentro del contenedor
: "${DB_PASS:?Falta la variable de entorno DB_PASS}"
mariadb --socket="$SOCKET" <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# Apache escucha en el puerto que asigna Render
PUERTO="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PUERTO}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PUERTO}>/" /etc/apache2/sites-available/000-default.conf

# Vigilante de la base de datos: si MariaDB deja de responder y su supervisor (mariadbd-safe) ya no está, la vuelve a
# arrancar. Así un cierre inesperado de la base no deja el sitio caído hasta que alguien reinicie el contenedor.
(
    while true; do
        sleep 30
        if ! mariadb-admin --socket="$SOCKET" ping --silent > /dev/null 2>&1 && ! pgrep -f mariadbd-safe > /dev/null; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') MariaDB no respondía: se arranca de nuevo" >&2
            mariadbd-safe --user=mysql --datadir="$DATOS" > /dev/null 2>&1 &
            sleep 30
        fi
    done
) &

# Publicador de redes sociales: cada minuto publica lo que ya llegó a su fecha, aunque nadie tenga el panel abierto
(
    while true; do
        php /var/www/html/cron/publicar_redes.php || true
        sleep 60
    done
) &

echo "DataSense (demostración) escuchando en el puerto ${PUERTO}"
exec apache2-foreground
