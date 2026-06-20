.PHONY: up down build logs ps backup restore shell-db shell-web

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

# ─── Update (Git Pull + Rebuild) ──────────────────────────────────────────────
update:
	git pull && docker compose up -d --build

# ─── Datenbank ────────────────────────────────────────────────────────────────
shell-db:
	docker compose exec timescaledb psql -U eeg -d eeg_platform

backup:
	@mkdir -p backups
	docker compose exec timescaledb pg_dump -U eeg eeg_platform | gzip > backups/backup_$$(date +%Y%m%d_%H%M%S).sql.gz
	@echo "Backup gespeichert in backups/"

restore:
	@echo "Verwendung: make restore FILE=backups/backup_YYYYMMDD_HHMMSS.sql.gz"
	gunzip -c $(FILE) | docker compose exec -T timescaledb psql -U eeg -d eeg_platform

migrate:
	@echo "Verwendung: make migrate FILE=database/migrate_YYYYMMDD.sql"
	docker compose exec -T timescaledb psql -U eeg -d eeg_platform < $(FILE)

# ─── Shells ───────────────────────────────────────────────────────────────────
shell-web:
	docker compose exec webapp sh
