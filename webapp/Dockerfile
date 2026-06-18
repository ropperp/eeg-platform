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
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# App-Code + EDA-Parser
COPY public/ /var/www/html/public/
COPY src/ /var/www/html/src/
COPY eda-parser/ /var/www/html/eda-parser/

WORKDIR /var/www/html

# Uploads-Verzeichnis
RUN mkdir -p /var/www/html/storage/uploads /var/www/html/storage/pdfs && \
    chown -R www-data:www-data /var/www/html/storage

EXPOSE 80

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
