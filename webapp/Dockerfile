# Version + Digest fest (reproduzierbare Builds); Updates schlägt Dependabot vor (.github/dependabot.yml).
FROM php:8.5.12-fpm-alpine@sha256:951fb5b1780219b81d5daffa4cebef8e0077c537f3893d9790a5dfcd66ca334d

# PHP-Extensions. Python/pandas für den EDA-Import liegen seit 16.11.2026 im eigenen Container
# eda-worker (eda-parser/Dockerfile), nicht mehr hier.
# tzdata: Alpine liefert von sich aus KEINE IANA-Zeitzonendatenbank (/usr/share/zoneinfo) mit --
# Pythons zoneinfo-Modul (ab 3.9, von pandas beim Excel-Datumsparsing/lokaler Zeitzonenauflösung
# transitiv genutzt) schlägt ohne dieses Paket mit "No time zone found with key ..." fehl, siehe
# Parser-Fehler beim EDA-Import ("zoneinfo/_common.py, load_tzdata"). apk-Paket statt separatem
# pip-Paket "tzdata", weil es zusätzlich PHP-Datumsfunktionen (date_default_timezone_set u.ä.)
# zugutekommt, die dieselbe Systemdatenbank verwenden.
# libjpeg-turbo/libpng/freetype/libwebp (ohne -dev) bleiben dauerhaft im Image -- Laufzeit-Bibliotheken,
# die die gd-Extension (s.u.) zum Lesen/Schreiben von JPEG/PNG braucht. Die -dev-Varianten
# (Header/.so-Symlinks zum Kompilieren) landen bewusst in .build-deps und werden danach wieder
# gelöscht (Vorfall 29.10.2026: Server lieferte /hero-banner-image mit HTTP 500 aus, weil Code
# imagecreatefromstring() & Co. aufrief, ohne dass die gd-Extension hier überhaupt installiert
# war -- ein Fatal Error durch einen Aufruf einer undefinierten Funktion, den @ NICHT
# unterdrückt, anders als eine Warning/einen Notice).
RUN apk add --no-cache nginx libpq-dev curl-dev libzip-dev tzdata \
        libjpeg-turbo libpng freetype libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libjpeg-turbo-dev libpng-dev freetype-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql curl zip gd opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# nginx Konfiguration
COPY webapp/docker/nginx.conf /etc/nginx/nginx.conf
COPY webapp/docker/security-headers.conf /etc/nginx/security-headers.conf
COPY webapp/docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# App-Code
COPY webapp/public/ /var/www/html/public/
COPY webapp/src/ /var/www/html/src/
COPY webapp/bin/ /var/www/html/bin/
COPY webapp/routes/ /var/www/html/routes/

# Read-only Rückfallebene für /admin/templates (Platform-Admin: LaTeX-Vorlagen + Infoblatt
# herunter-/hochladen): das persistente Volume /opt/eeg/latex-templates (siehe
# docker-compose.yml) wird erst beim ersten Start von latex-service befüllt -- bis dahin (oder
# falls das Volume aus irgendeinem Grund leer ist) zeigt die Admin-Seite ersatzweise diese
# mitgelieferten Standard-Dateien an, statt eine leere/kaputte Seite auszuliefern.
COPY latex-service/templates/ /var/www/html/latex-templates-default/
COPY webapp/public/assets/docs/infoblatt-eeg-strompool-feldkirchen-suedwest.pdf /var/www/html/latex-templates-default/infoblatt.pdf
# Gleiches Logo als Standard für beide Varianten -- bis der Platform-Admin über
# /admin/templates eine eigene, für Dark-Mode optimierte Grafik hochlädt (siehe
# logo-img-light/-dark in app.css), zeigt der Dark-Mode ersatzweise das Light-Logo.
COPY webapp/public/assets/images/logo.png /var/www/html/latex-templates-default/logo-light.png
COPY webapp/public/assets/images/logo.png /var/www/html/latex-templates-default/logo-dark.png
# Favicon/Tab-Icon (Vorfall 29.10.2026, Patrick: weißer Kasten im Browser-Tab bei Dark Mode) --
# favicon.ico/favicon-16.png/favicon-32.png/apple-touch-icon.png/icon-192.png/icon-512.png sind
# bewusst NICHT mehr unter webapp/public/ (dort würde nginx sie als statische Dateien direkt
# ausliefern, siehe nginx.conf -- die PHP-Routen unten kämen nie zum Zug, ein Admin-Upload über
# /admin/templates hätte also nie sichtbaren Effekt). favicon-source.png ist die Ausgangsgrafik
# (transparenter Hintergrund, aus dem Blitz-Icon-Teil des Logos ausgeschnitten), die übrigen
# fünf Dateien sind die daraus einmalig generierten Ableitungen (siehe generateFaviconAssets()
# in index.php) -- werden bei einem eigenen Upload neu erzeugt, bis dahin gilt dieser Satz hier
# als Standard.
COPY webapp/assets-default/favicon-source.png /var/www/html/latex-templates-default/favicon-source.png
COPY webapp/assets-default/favicon.ico /var/www/html/latex-templates-default/favicon.ico
COPY webapp/assets-default/favicon-16.png /var/www/html/latex-templates-default/favicon-16.png
COPY webapp/assets-default/favicon-32.png /var/www/html/latex-templates-default/favicon-32.png
COPY webapp/assets-default/apple-touch-icon.png /var/www/html/latex-templates-default/apple-touch-icon.png
COPY webapp/assets-default/icon-192.png /var/www/html/latex-templates-default/icon-192.png
COPY webapp/assets-default/icon-512.png /var/www/html/latex-templates-default/icon-512.png

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
