-- Anmeldedienst Keycloak (docs/adr/0007-keycloak.md): Verknüpfung Plattform-Benutzer <-> Keycloak-Konto.
-- keycloak_id ist die Benutzer-ID in Keycloak ("sub" im ID-Token). Die Anmeldung über Keycloak findet
-- den Plattform-Benutzer NUR über diese Spalte, nie über die E-Mail-Adresse.
-- keycloak_synced_at: Zeitpunkt der letzten Übernahme/Abgleichs (Import-Skript, Profiländerungen).
-- Idempotent (mehrfach ausführbar).

ALTER TABLE users ADD COLUMN IF NOT EXISTS keycloak_id UUID;
ALTER TABLE users ADD COLUMN IF NOT EXISTS keycloak_synced_at TIMESTAMPTZ;
CREATE UNIQUE INDEX IF NOT EXISTS users_keycloak_id_uidx ON users (keycloak_id) WHERE keycloak_id IS NOT NULL;
