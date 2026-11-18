# Keycloak (Anmeldedienst auth.<DOMAIN>) mit einer eigenen, kleinen Erweiterung:
# LegacyBcryptHashProvider prüft die alten bcrypt-Hashes der Plattform, damit beim Umzug niemand ein
# neues Passwort setzen muss. Keine Fremdbibliothek: bcrypt kommt aus dem mitgelieferten BouncyCastle.
# Entscheidung: docs/adr/0007-keycloak.md

FROM quay.io/keycloak/keycloak:26.8.2@sha256:c68e286cacd87b942ac1939a0a1481461109d6acd3a57dc496969a319a76bbf0 AS kc

# 1) Erweiterung gegen genau die Keycloak-Bibliotheken dieser Version übersetzen (kein Maven, kein Download)
FROM eclipse-temurin:21-jdk@sha256:db5cc6341a1d7bc40cdb51e1f0d9824f3bb2cc8107d29da421dc4df7d11f05f1 AS providers
COPY --from=kc /opt/keycloak/lib/lib/main /kc-lib
COPY providers /src
RUN set -eu; mkdir /out; \
    javac --release 21 -d /out -cp "/kc-lib/*" $(find /src/src -name '*.java'); \
    cp -r /src/META-INF /out/; \
    jar cf /stromfueralle-providers.jar -C /out .

# 2) Optimiertes Keycloak-Image bauen (Datenbank, Features und Erweiterung fest eingebaut)
FROM kc AS builder
ENV KC_DB=postgres \
    KC_HEALTH_ENABLED=true \
    KC_METRICS_ENABLED=false
COPY --from=providers /stromfueralle-providers.jar /opt/keycloak/providers/
RUN /opt/keycloak/bin/kc.sh build

FROM kc
COPY --from=builder /opt/keycloak/ /opt/keycloak/
COPY realm /opt/keycloak/data/import
COPY themes /opt/keycloak/themes
ENTRYPOINT ["/opt/keycloak/bin/kc.sh"]
CMD ["start", "--optimized", "--import-realm"]
