<?php

declare(strict_types=1);

class Audit
{
    /**
     * Schreibt einen unveränderlichen Protokolleintrag.
     *
     * @param string      $action       Maschinenlesbarer Aktionsname, z.B. 'member.create'
     * @param string      $entityType   Entitätstyp, z.B. 'member', 'metering_point'
     * @param string|null $entityId     UUID oder sonstiger Bezeichner des betroffenen Datensatzes
     * @param mixed       $details      Beliebige JSON-Daten (Vorher-/Nachher-Werte o.ä.)
     * @param string|null $communityId  Community-Scope; null für plattformweite Aktionen
     * @param string|null $actorLabel   Bei Systemvorgängen statt user_id, z.B. 'system:mqtt-subscriber'
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        mixed $details = null,
        ?string $communityId = null,
        ?string $actorLabel = null
    ): void {
        try {
            $userId = $actorLabel ? null : Auth::userId();

            $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            $ip    = $rawIp ? trim(explode(',', $rawIp)[0]) : null;

            DB::execute(
                'INSERT INTO audit_log (community_id, user_id, actor_label, action, entity_type, entity_id, details, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $communityId,
                    $userId,
                    $actorLabel,
                    $action,
                    $entityType,
                    $entityId,
                    $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                    $ip,
                ]
            );
        } catch (Throwable) {
            // Audit-Fehler dürfen die eigentliche Aktion nie blockieren
        }
    }
}
