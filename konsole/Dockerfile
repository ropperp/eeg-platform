# Steuerzentrale (admin.<DOMAIN>): eigener, kleiner Container ohne Abhängigkeit von Webapp, DB,
# Redis oder Traefik (docs/adr/0006-steuerzentrale.md). Gebaut vom Repo-Root aus
# (docker-compose.yml: context ".", dockerfile konsole/Dockerfile), weil die Passkey-Prüfung
# webapp/src/WebAuthn.php mitverwendet wird.
# Gleiches Basis-Image wie die Webapp (Version + Digest fest, Updates per Dependabot).
FROM php:8.4.27-fpm-alpine@sha256:511af6fa3ef55c4ea0c68bb47096ba4b81771ab4949bde7813807bf177efda11

# pdo_pgsql: Passkeys der Platform-Admins aus der Plattform-DB lesen (mit Kopie für DB-Ausfälle).
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps \
    && php -m | grep -qi '^curl$'

WORKDIR /app
COPY konsole/public/ /app/public/
COPY konsole/src/ /app/src/
COPY webapp/src/WebAuthn.php /app/src/WebAuthn.php

# Eingebauter PHP-Server: für 1-2 gleichzeitige Admins ausreichend, keine weiteren Prozesse.
ENV PHP_CLI_SERVER_WORKERS=3
EXPOSE 8090
CMD ["php", "-d", "display_errors=0", "-d", "log_errors=1", "-d", "expose_php=0", "-S", "0.0.0.0:8090", "-t", "/app/public", "/app/public/index.php"]
