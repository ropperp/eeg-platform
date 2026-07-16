FROM php:8.2-fpm-alpine

# PHP Extensions + Python für EDA-Parser
RUN apk add --no-cache nginx libpq-dev python3 py3-pip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && pip3 install --break-system-packages pandas openpyxl psycopg2-binary

# nginx Konfiguration
COPY webapp/docker/nginx.conf /etc/nginx/nginx.conf
COPY webapp/docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# App-Code + EDA-Parser
COPY webapp/public/ /var/www/html/public/
COPY webapp/src/ /var/www/html/src/
COPY eda-parser/ /var/www/html/eda-parser/

WORKDIR /var/www/html

# Uploads-Verzeichnis
RUN mkdir -p /var/www/html/storage/uploads /var/www/html/storage/pdfs && \
    chown -R www-data:www-data /var/www/html/storage

# nginx.conf setzt "user www-data;" (passend zum PHP-FPM-User), aber das Alpine-nginx-Paket
# legt /var/lib/nginx/tmp/* (u.a. client_body -- Zwischenspeicher für POST-Bodies, die zu groß
# für den kleinen In-Memory-Puffer sind) beim Install mit dem eigenen "nginx"-System-User an,
# NICHT www-data. Ohne diesen Fix schlägt JEDER Datei-Upload mit "open() .../client_body/...
# failed (13: Permission denied)" fehl, sobald der Request-Body den Default-Puffer übersteigt --
# kleine Requests ohne Datei-Anhang (Login, Formularfelder) bleiben unter dem Puffer-Limit und
# fallen deshalb nie auf, wodurch sich das nginx-eigene Standard-500 nur bei Uploads zeigt.
RUN chown -R www-data:www-data /var/lib/nginx/tmp

EXPOSE 80

COPY webapp/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
