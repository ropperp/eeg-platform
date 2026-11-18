# ADR 0004: Eigener Auth-Stack statt externem Identity Provider

**Status:** ersetzt durch [ADR 0007](0007-keycloak.md) (18.11.2026), vorher angenommen (16.11.2026)

## Kontext
Die Anmeldung umfasst Argon2id, Redis-Sessions, Rate Limiting, TOTP, Passkeys (WebAuthn), CSRF und
API-Tokens für die App. Zur Diskussion standen Keycloak, Authentik oder Authelia.

## Entscheidung
Kein externer Identity Provider, solange es nur diese eine Anwendung gibt. Ein IdP wäre ein weiterer
Container, ein weiterer Ausfallpunkt und eine weitere Update-Verantwortung auf dem Raspberry Pi.

## Folgen
- Auth-Code wird bei Berührung nach `Domain/Identity` bzw. `Infrastructure/Auth` geordnet und mit
  Integrationstests (Login, Recovery, Rate Limit) abgesichert.
- Kommt echter Single-Sign-on-Bedarf (mehrere Anwendungen, Vereins-/Unternehmens-Login), wird OIDC
  eingeführt -- vorher Produkt, Lizenz, Reifegrad, Ressourcenbedarf und OIDC/SAML-Unterstützung prüfen.
