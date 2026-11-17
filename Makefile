.PHONY: setup up down build build-clean prod update logs logs-web logs-latex logs-db \
        ps shell-db shell-web backup backup-storage backup-all sync-nas restore migrate schema demo-db verify test test-integration test-e2e

# ─── Ersteinrichtung (einmalig, siehe SETUP.md) ───────────────────────────────
setup:
	bash scripts/setup.sh

# ─── Automatisierte Tests (abhängigkeitsfrei, läuft auch in der CI) ───────────
test:
	php tests/run.php

# End-to-End: ganzer Stack in Docker (Webapp, eda-worker, DB, Redis), siehe tests/e2e/run.sh.
test-e2e:
	bash tests/e2e/run.sh
	bash tests/e2e/konsole_run.sh

# Integrationstests gegen eine frische Wegwerf-TimescaleDB (Docker, Port 55432). Prüft u. a. die
# RLS-Mandantentrennung mit der echten Laufzeit-Rolle. Läuft auch in der CI (Job "integration").
TEST_PG_IMAGE ?= timescale/timescaledb:2.17.2-pg16
test-integration:
	-docker rm -f eeg-pgtest >/dev/null 2>&1
	docker run -d --name eeg-pgtest -e POSTGRES_USER=eeg -e POSTGRES_PASSWORD=test \
	  -e POSTGRES_DB=eeg_platform -p 55432:5432 $(TEST_PG_IMAGE) >/dev/null
	until docker exec eeg-pgtest pg_isready -U eeg -d eeg_platform -h 127.0.0.1 >/dev/null 2>&1; do sleep 1; done
	PSQL="docker exec -i eeg-pgtest psql -U eeg -d eeg_platform" bash tests/integration/prepare_db.sh
	TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=55432 TEST_DB_NAME=eeg_platform \
	  TEST_DB_OWNER=eeg TEST_DB_OWNER_PASSWORD=test TEST_APP_USER=eeg_app TEST_APP_USER_PASSWORD=app \
	  TEST_WORKER_USER=eeg_worker TEST_WORKER_USER_PASSWORD=worker php tests/integration/run.php; \
	  rc=$$?; docker rm -f eeg-pgtest >/dev/null; exit $$rc

# ─── Starten ──────────────────────────────────────────────────────────────────
up:
	docker compose up -d

prod:
	docker compose --profile production up -d

# ─── Bauen (nach Code-Änderungen) ─────────────────────────────────────────────
build:
	docker compose up -d --build

build-clean:
	docker compose build --no-cache webapp latex-service && docker compose up -d webapp latex-service

# ─── Stoppen ──────────────────────────────────────────────────────────────────
down:
	docker compose down

# ─── Update (Git Pull + Rebuild) ──────────────────────────────────────────────
update:
	git pull && docker compose up -d --build

# ─── Status / Logs ────────────────────────────────────────────────────────────
ps:
	docker compose ps

logs:
	docker compose logs -f --tail=100

logs-web:
	docker compose logs -f --tail=100 webapp

logs-latex:
	docker compose logs -f --tail=100 latex-service

logs-db:
	docker compose logs -f --tail=100 timescaledb

# ─── Datenbank ────────────────────────────────────────────────────────────────
shell-db:
	docker compose exec timescaledb psql -U eeg -d eeg_platform

shell-web:
	docker compose exec webapp sh

# ─── Backup (enthält echte Daten — NICHT in Git) ──────────────────────────────
backup:
	bash scripts/backup.sh

# Uploads sichern (Avatare, Ausweis-Scans, Beitrittserklärungen -- NICHT aus der DB
# wiederherstellbar). Muss auf dem Host laufen, nicht im Container.
backup-storage:
	bash scripts/backup-storage.sh

# DB-Dump + Uploads in einem Schritt
backup-all: backup backup-storage

# Verwendung: make sync-nas NAS_HOST=192.168.1.50 NAS_USER=eeg-backup [NAS_PATH=/volume1/eeg-backup]
sync-nas:
	@test -n "$(NAS_HOST)" || (echo "Verwendung: make sync-nas NAS_HOST=... NAS_USER=... [NAS_PATH=...]" && exit 1)
	NAS_HOST=$(NAS_HOST) NAS_USER=$(NAS_USER) NAS_PATH=$(NAS_PATH) bash scripts/sync-to-nas.sh

# Verwendung: make restore FILE=backups/eeg_20260620_0230.dump
restore:
	@test -n "$(FILE)" || (echo "Verwendung: make restore FILE=backups/eeg_DATUM.dump" && exit 1)
	bash scripts/restore.sh $(FILE)

# ─── Migration ────────────────────────────────────────────────────────────────
# Verwendung: make migrate FILE=database/migrate_YYYYMMDD.sql
migrate:
	@test -n "$(FILE)" || (echo "Verwendung: make migrate FILE=database/migrate_YYYYMMDD.sql" && exit 1)
	docker compose exec -T timescaledb psql -U eeg -d eeg_platform < $(FILE)

# ─── Schema (nur Struktur, kein Daten — darf in Git) ─────────────────────────
schema:
	docker compose exec -T timescaledb \
	  pg_dump -U eeg -d eeg_platform --schema-only --no-owner --no-privileges \
	  > docs/schema.sql
	@echo "docs/schema.sql aktualisiert"

# ─── Vollständige Verifikation (Stack, Backup, Restore, Schema, PDF-Test) ─────
verify:
	bash scripts/verify.sh

# ─── Demo-Daten (für Screenshots, NICHT auf Produktions-DB) ──────────────────
demo-db:
	@echo "WARNUNG: Lädt Demo-Daten in die DB. Nur auf frischen/Test-Systemen!"
	@read -p "Fortfahren? (ja/NEIN): " c && [ "$$c" = "ja" ]
	docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/seed_demo.sql
