-- Migration 2026-07-25: Profilbild auch für Manager/Platform-Admins ohne eigenen
-- Mitgliedsdatensatz (members.photo_path deckt nur Community-Mitglieder ab).
ALTER TABLE users ADD COLUMN IF NOT EXISTS photo_path TEXT;
