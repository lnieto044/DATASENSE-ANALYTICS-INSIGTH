# Sitio de DEMOSTRACIÓN de DataSense para Render: Apache + PHP 8.2 + MariaDB en un solo contenedor.
# La base se carga al construir la imagen con datos ficticios (deploy/demo/datasense_demo.sql) y vuelve
# a ese estado cada vez que el servicio se reinicia. El correo está desactivado y los pagos van al simulador.

# 1) Dependencias de Composer (PHPMailer y phpdotenv), con las versiones fijadas en composer.lock
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --ignore-platform-reqs

# 2) Servidor web
FROM php:8.2-apache-bookworm
ENV TZ=America/Bogota \
    DB_HOST=127.0.0.1 \
    DB_PORT=3306 \
    DB_NAME=datasense_demo \
    DB_USER=datasense
# Valores de la demostración por defecto: así arranca sin configurar nada (por ejemplo en Vercel). Render y la copia
# local pueden cambiarlos con sus propias variables.
ENV APP_ENV=demo \
    PAGOS_ENTORNO=LOCAL \
    MAIL_HOST=127.0.0.1 \
    MAIL_PORT=9 \
    MAIL_ENCRYPTION=none \
    MAIL_USER=demo@example.com \
    MAIL_PASS=demo \
    SOCIAL_FACEBOOK=https://www.facebook.com/profile.php?id=61596608473887 \
    SOCIAL_INSTAGRAM=https://www.instagram.com/datasenseanalyticsinsigth/ \
    SOCIAL_LINKEDIN=https://www.linkedin.com/company/datasense-analytics-insights \
    SOCIAL_WHATSAPP=573133898638

RUN apt-get update \
 && apt-get install -y --no-install-recommends mariadb-server tzdata \
 && docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers setenvif \
 && rm -rf /var/lib/apt/lists/*

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-datasense.ini
COPY deploy/apache-datasense.conf /etc/apache2/conf-available/datasense.conf
COPY deploy/mariadb.cnf /etc/mysql/mariadb.conf.d/99-datasense.cnf
# Permisos normales: MariaDB ignora un archivo de configuración que cualquiera puede modificar (pasa al copiar desde
# algunos equipos o Codespaces), y sin él la base corre con otro sql_mode y otra memoria
RUN chmod 644 /usr/local/etc/php/conf.d/zz-datasense.ini /etc/apache2/conf-available/datasense.conf /etc/mysql/mariadb.conf.d/99-datasense.cnf \
 && a2enconf datasense \
 && sed -ri 's/^Listen 80$/Listen ${PUERTO_WEB}/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PUERTO_WEB}>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
COPY --from=vendor /app/vendor /var/www/html/vendor

# La base de demostración y el script de arranque quedan fuera de la carpeta pública
RUN mkdir -p /opt/datasense \
 && mv /var/www/html/deploy/demo/datasense_demo.sql /opt/datasense/ \
 && cp /var/www/html/deploy/entrypoint.sh /usr/local/bin/datasense-entrypoint \
 && sed -i 's/\r$//' /usr/local/bin/datasense-entrypoint /etc/apache2/conf-available/datasense.conf /etc/mysql/mariadb.conf.d/99-datasense.cnf \
 && chmod +x /usr/local/bin/datasense-entrypoint \
 && rm -rf /var/www/html/deploy /var/www/html/Dockerfile /var/www/html/render.yaml /var/www/html/.dockerignore \
           /var/www/html/docker-compose.yml /var/www/html/vercel.json \
 && mkdir -p /var/www/html/uploads/tickets /var/www/html/uploads/pagos /var/www/html/uploads/pqrsd /var/www/html/uploads/redes \
 && chown -R www-data:www-data /var/www/html/uploads /var/www/html/img/productos

# Crea la base de demostración dentro de la imagen (si el volcado tiene un error, la construcción falla aquí)
RUN datasense-entrypoint --solo-base

# Puerto por defecto 80 (Vercel); Render y docker-compose indican el suyo con la variable PORT
EXPOSE 80
CMD ["datasense-entrypoint"]
