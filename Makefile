.PHONY: up down build build-clean prod update logs logs-web logs-latex logs-db \
        ps shell-db shell-web backup restore migrate schema demo-db verify

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
