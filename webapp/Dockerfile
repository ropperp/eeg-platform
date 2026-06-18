FROM php:8.2-fpm-alpine

# Extensions
RUN apk add --no-cache nginx redis libpq-dev && \
    docker-php-ext-install pdo pdo_pgsql && \
    pecl install redis && docker-php-ext-enable redis

# nginx Konfiguration
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# App-Code
COPY public/ /var/www/html/public/
COPY src/ /var/www/html/src/

WORKDIR /var/www/html

# Uploads-Verzeichnis
RUN mkdir -p /var/www/html/storage/uploads /var/www/html/storage/pdfs && \
    chown -R www-data:www-data /var/www/html/storage

EXPOSE 80

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
