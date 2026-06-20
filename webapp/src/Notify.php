<?php

declare(strict_types=1);

class Notify
{
    /**
     * Legt eine Benachrichtigung im Postfach an.
     *
     * @param string      $audience     'platform_admin' | 'manager' | 'member'
     * @param string      $type         Maschinenlesbarer Ereignistyp, z.B. 'billing.released'
     * @param string      $title        Kurze Anzeige-Überschrift
     * @param string      $body         Längerer Erklärungstext (optional)
     * @param mixed       $payload      Beliebige JSON-Daten (z.B. IDs für Deeplinks)
     * @param string|null $communityId  Community-Scope; null für platform_admin-Nachrichten
     * @param string|null $memberId     Für memberschaft-spezifische Nachrichten
     */
    public static function create(
        string $audience,
        string $type,
        string $title,
        string $body = '',
        mixed $payload = null,
        ?string $communityId = null,
        ?string $memberId = null
    ): void {
        DB::execute(
            'INSERT INTO notifications (community_id, audience, member_id, type, title, body, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $communityId,
                $audience,
                $memberId,
                $type,
                $title,
                $body,
                $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    /**
     * Deduplizierungs-Helfer: prüft ob eine gleichartige Nachricht kürzlich existiert.
     * Verhindert Spam bei häufig ausgelösten Ereignissen.
     *
     * @param string      $type        Ereignistyp
     * @param string|null $communityId Community-Scope
     * @param string      $dedupKey    Eindeutiger Schlüssel im payload->dedup_key Feld
     * @param int         $withinHours Zeitfenster in Stunden
     */
    public static function existsRecent(
        string $type,
        ?string $communityId,
        string $dedupKey,
        int $withinHours = 24
    ): bool {
        if ($communityId !== null) {
            $row = DB::fetchOne(
                "SELECT id FROM notifications
                 WHERE type = ?
                   AND community_id = ?
                   AND payload->>'dedup_key' = ?
                   AND created_at >= now() - (? * INTERVAL '1 hour')
                 LIMIT 1",
                [$type, $communityId, $dedupKey, $withinHours]
            );
        } else {
            $row = DB::fetchOne(
                "SELECT id FROM notifications
                 WHERE type = ?
                   AND community_id IS NULL
                   AND payload->>'dedup_key' = ?
                   AND created_at >= now() - (? * INTERVAL '1 hour')
                 LIMIT 1",
                [$type, $dedupKey, $withinHours]
            );
        }
        return $row !== null;
    }

    /**
     * Zählt ungelesene Nachrichten für die aktuelle Session-Rolle.
     * Gibt 0 zurück wenn keine DB-Verbindung oder Tabelle fehlt.
     */
    public static function unreadCount(): int
    {
        try {
            if (Auth::isPlatformAdmin()) {
                $row = DB::fetchOne(
                    "SELECT COUNT(*) AS cnt FROM notifications WHERE audience = 'platform_admin' AND is_read = false"
                );
            } elseif (Auth::isManager()) {
                $cid = Auth::activeCommunityId();
                if (!$cid) return 0;
                DB::setCommunity($cid);
                $row = DB::fetchOne(
                    "SELECT COUNT(*) AS cnt FROM notifications WHERE audience = 'manager' AND community_id = ? AND is_read = false",
                    [$cid]
                );
            } else {
                $cid = Auth::activeCommunityId();
                $uid = Auth::userId();
                if (!$cid || !$uid) return 0;
                DB::setCommunity($cid);
                $member = DB::fetchOne(
                    'SELECT id FROM members WHERE user_id = ? AND community_id = ?',
                    [$uid, $cid]
                );
                if (!$member) return 0;
                $row = DB::fetchOne(
                    "SELECT COUNT(*) AS cnt FROM notifications WHERE audience = 'member' AND community_id = ? AND member_id = ? AND is_read = false",
                    [$cid, $member['id']]
                );
            }
            return (int)($row['cnt'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
