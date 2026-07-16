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
# legt /var/lib/nginx SAMT /var/lib/nginx/tmp/* (u.a. client_body -- Zwischenspeicher für
# POST-Bodies, die zu groß für den kleinen In-Memory-Puffer sind) beim Install mit dem eigenen
# "nginx"-System-User und Modus 750 an, NICHT www-data. Nur /var/lib/nginx/tmp zu chownen
# reicht NICHT: Linux verlangt Ausführungsrecht auf JEDES Verzeichnis im Pfad, und
# /var/lib/nginx selbst (der Elternordner) blieb dabei weiterhin nginx:nginx mit Modus 750
# (keinerlei Rechte für "andere") -- www-data konnte dadurch gar nicht erst hinein, ganz gleich
# wie tmp/ selbst berechtigt war. Deshalb hier den kompletten Elternordner chownen, nicht nur
# das tmp-Unterverzeichnis. Ohne diesen Fix schlägt jeder Datei-Upload, dessen Body den kleinen
# In-Memory-Puffer übersteigt, mit "open() .../client_body/... failed (13: Permission denied)"
# fehl -- kleine Requests ohne Datei-Anhang (Login, Formularfelder) bleiben unter dem
# Puffer-Limit und fallen deshalb nie auf, wodurch sich das nginx-eigene Standard-500 nur bei
# manchen Uploads zeigt (abhängig davon, ob der jeweilige Body zufällig unter dem Puffer bleibt).
RUN chown -R www-data:www-data /var/lib/nginx

EXPOSE 80

COPY webapp/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
