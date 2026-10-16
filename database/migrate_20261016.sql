-- Migration 2026-10-16: letzte aktive Rolle je Login merken, über Logout/Login hinweg.
--
-- Patrick, 16.10.2026: "Wenn ich mich jetzt als Obmann anmelde, dann möchte ich, wenn ich mich
-- wieder einlogge, wieder als Obmann sein und nicht als Admin [...] das ist immer mühsam, wenn
-- ich als Obmann eigentlich angemeldet [...] war, mich abmelde, wieder anmelde und dann wieder
-- als Plattformadmin drinnen bin." Bisher setzte Auth::establishSession() bei JEDEM Login über
-- pickDefaultRole() immer dieselbe Priorität (platform_admin > manager > member), unabhängig
-- davon, welche Rolle zuletzt aktiv war -- für einen Platform-Admin mit zusätzlicher
-- Obmann-Rolle bedeutete das: nach jedem Login zurück auf Platform-Admin, egal was davor
-- aktiv war.
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active_community_id UUID REFERENCES communities(id) ON DELETE SET NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active_role TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active_member_id UUID REFERENCES members(id) ON DELETE SET NULL;
