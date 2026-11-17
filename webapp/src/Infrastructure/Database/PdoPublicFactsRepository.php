<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Öffentliche Kennzahlen einer EEG für die Startseite: Vereinsdaten (wie im Impressum), Anzahl
 * Mitglieder und Zählpunkte, letzter Live-Stand. Wird serverseitig ins HTML geschrieben, damit
 * Suchmaschinen und KI-Crawler (die kein JavaScript ausführen) echte Zahlen statt "0" lesen
 * (Sichtbarkeits-Check 17.11.2026). Nur Summen, keine Einzeldaten.
 */
final class PdoPublicFactsRepository
{
    /** @return array<string, mixed>|null */
    public function forSlug(string $slug): ?array
    {
        $c = \DB::fetchOne(
            'SELECT id, name, slug, address, zvr_number, contact_email, contact_phone FROM communities WHERE slug = ? AND active = true',
            [$slug]
        );
        if (!$c) {
            return null;
        }
        \DB::setCommunity($c['id']);
        $members = \DB::fetchOne(
            "SELECT count(*) AS n FROM members WHERE community_id = ? AND status = 'active' AND is_demo = false",
            [$c['id']]
        );
        $live = \DB::fetchOne(
            'SELECT bezug_w, einspeisung_w, autarkie_pct, updated_at FROM community_live_stats WHERE community_id = ?',
            [$c['id']]
        );
        // Alle aktiven Zählpunkte echter Mitglieder (Messe-Demo-Zählpunkte hängen an einem is_demo-Mitglied).
        $m = \DB::fetchOne(
            'SELECT count(*) AS n FROM metering_points mp LEFT JOIN members m ON m.id = mp.member_id
              WHERE mp.community_id = ? AND mp.active = true AND COALESCE(m.is_demo, false) = false',
            [$c['id']]
        );
        $meters = (int) ($m['n'] ?? 0);
        return [
            'name'          => (string) $c['name'],
            'slug'          => (string) $c['slug'],
            'address'       => (string) ($c['address'] ?? ''),
            'zvr'           => (string) ($c['zvr_number'] ?? ''),
            'email'         => (string) ($c['contact_email'] ?? ''),
            'phone'         => (string) ($c['contact_phone'] ?? ''),
            'members'       => (int) ($members['n'] ?? 0),
            'meters'        => $meters,
            'bezug_w'       => $live ? (int) round((float) $live['bezug_w']) : null,
            'einspeisung_w' => $live ? (int) round((float) $live['einspeisung_w']) : null,
            'autarkie_pct'  => $live ? (int) round((float) $live['autarkie_pct']) : null,
            'live_at'       => $live['updated_at'] ?? null,
        ];
    }
}
