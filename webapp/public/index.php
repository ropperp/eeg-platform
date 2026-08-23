<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

foreach (['DB', 'Auth', 'RateLimiter', 'AppApiAuth', 'Router', 'Billing', 'Mailer', 'GraphMailReader', 'EdaParserRunner', 'EdaAutoImporter', 'Push'] as $class) {
    require ROOT . '/src/' . $class . '.php';
}
// Reine Hilfsfunktionen (validateIban, texEscape, rechnung*Latex ...) -- ausgelagert, damit
// sie ohne HTTP-/Router-Kontext testbar sind (tests/).
require ROOT . '/src/functions.php';

Auth::start();

/**
 * Sicherheitsnetz gegen rohe 500er ohne jede Auskunft: bisher fing z.B. der Datei-Upload
 * nur PDOException gezielt ab -- ein TypeError/Error o.ä. (etwa durch eine unerwartete
 * Alt-Spalte oder einen Tippfehler) lief unkontrolliert durch und endete als nichtssagender
 * nginx-Standard-500er. Jetzt wird jeder unbehandelte Fehler serverseitig geloggt (docker
 * compose logs webapp) und angemeldeten Nutzern wenigstens die Fehlermeldung angezeigt,
 * damit der Fehler überhaupt reproduzier-/meldbar wird.
 */
function renderFatalErrorPage(string $message): void
{
    if (headers_sent()) { return; }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>';
    echo '<div style="font-family:sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">';
    echo '<h2>Es ist ein unerwarteter Fehler aufgetreten.</h2>';
    if (Auth::check()) {
        echo '<p style="color:#6b7280;font-size:.9rem">Technische Details: <code>' . htmlspecialchars($message) . '</code></p>';
    }
    echo '<p><a href="/">Zur Startseite</a></p></div>';
}

set_exception_handler(function (\Throwable $e) {
    error_log('[unhandled] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    renderFatalErrorPage($e->getMessage());
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[fatal] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        renderFatalErrorPage($err['message']);
    }
});

// validateIban / validateZaehlpunkt nach src/functions.php ausgelagert (testbar).

/**
 * Baut einen Link zu einem /portal- oder /admin-Pfad, der immer auf der portal-Subdomain
 * landet -- absolut, außer man befindet sich (z.B. lokale Entwicklung oder solange DNS/SSL für
 * die Subdomain noch nicht steht) bereits dort, dann bleibt der Link relativ. Verhindert einen
 * unnötigen Redirect-Hop über die Domain-Trennung (index.php schickt Backoffice-Pfade auf der
 * Hauptdomain sonst ohnehin automatisch auf die portal-Subdomain um).
 */
function portalUrl(string $path): string
{
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    return $host === 'portal.stromfueralle.at' ? $path : 'https://portal.stromfueralle.at' . $path;
}

/**
 * Baut einen Link zu einer öffentlichen Marketing-Seite, der immer auf der Hauptdomain landet --
 * Gegenstück zu portalUrl() für Links aus dem Backoffice heraus (Logo, "Startseite"-Links).
 */
function marketingUrl(string $path): string
{
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    return $host === 'stromfueralle.at' || $host === 'www.stromfueralle.at' ? $path : 'https://stromfueralle.at' . $path;
}

/**
 * Baut den absoluten Link für Passwort-Reset-/Erstlogin-E-Mails. Fix auf die portal-Subdomain
 * verdrahtet (nicht die aufrufende Host-Kopfzeile), weil E-Mails immer denselben Link liefern
 * sollen, unabhängig davon von welcher Domain aus der Manager die Aktion auslöst.
 */
function passwordResetLink(string $token): string
{
    return 'https://portal.stromfueralle.at/portal/reset-password?token=' . urlencode($token);
}

/**
 * Sind Verträge (Bezugsvereinbarung/Einspeisevertrag) für diese EEG aktiviert? Ist der Schalter
 * aus (communities.contracts_enabled = false), werden alle Vertragsfunktionen in Portal und
 * Obmann-Ansicht ausgeblendet und die Vertragsrouten gesperrt (die Beitrittserklärung genügt als
 * Vertrag + SEPA-Mandat). Standard true.
 */
function contractsEnabled(?string $communityId): bool
{
    if (!$communityId) return true;
    $row = DB::fetchOne('SELECT contracts_enabled FROM communities WHERE id = ?', [$communityId]);
    return $row === null ? true : (bool)$row['contracts_enabled'];
}

/**
 * Lädt eine System-Mail-Vorlage (Betreff + HTML-Body) aus platform_mail_templates und ersetzt
 * {{platzhalter}} durch $vars. Fällt auf den mitgegebenen Standardtext zurück, falls im
 * Platform-Admin noch keine eigene Vorlage gespeichert wurde (z.B. direkt nach der Migration).
 */
/**
 * Liefert ['anrede','nachname'] für eine E-Mail-Adresse -- bevorzugt aus dem Mitgliedsdatensatz
 * (mit Geschlecht/Anrede-Modus), sonst aus dem Login-Konto (dann neutral „Guten Tag <Nachname>").
 * Nötig, damit auch Vorlagen ohne Mitgliedskontext (z.B. Passwort-Reset) {{anrede}}/{{nachname}}
 * verwenden können, ohne dass rohe Platzhalter in der Mail landen.
 */
function salutationVarsForEmail(string $email): array
{
    $email = strtolower(trim($email));
    try {
        $m = DB::fetchOne(
            'SELECT salutation, titel, first_name, last_name, company_name, email_anrede_mode
               FROM members WHERE lower(email) = ? ORDER BY created_at LIMIT 1',
            [$email]
        );
        if (!$m) {
            $u = DB::fetchOne('SELECT first_name, last_name FROM users WHERE lower(email) = ?', [$email]);
            $m = $u ? ['last_name' => $u['last_name'], 'email_anrede_mode' => 'auto'] : [];
        }
    } catch (Throwable $e) {
        $m = [];
    }
    $s = mailSalutation($m);
    return ['anrede' => htmlspecialchars($s['anrede']), 'nachname' => htmlspecialchars($s['nachname'])];
}

function renderMailTemplate(string $key, array $vars, string $fallbackSubject, string $fallbackBody): array
{
    $tpl = DB::fetchOne('SELECT subject, body_html FROM platform_mail_templates WHERE key = ?', [$key]);
    $subject = $tpl['subject'] ?? $fallbackSubject;
    $body    = $tpl['body_html'] ?? $fallbackBody;
    foreach ($vars as $name => $value) {
        $subject = str_replace('{{' . $name . '}}', $value, $subject);
        $body    = str_replace('{{' . $name . '}}', $value, $body);
    }
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Liefert, ob die Plattform aktuell im Testmodus läuft (siehe migrate_20260728.sql). Fängt
 * fehlende Tabelle/Zeile ab (z.B. Migration auf diesem Server noch nicht eingespielt) und
 * fällt dann sicher auf "Testmodus" zurück, statt die ganze Seite mit einem SQL-Fehler
 * abstürzen zu lassen -- eine vergessene Migration darf niemals die Mitglied-Anlage blockieren.
 */
function platformTestMode(): bool
{
    try {
        return (bool)(DB::fetchOne('SELECT test_mode FROM platform_settings WHERE id = 1')['test_mode'] ?? true);
    } catch (\Throwable $e) {
        return true;
    }
}

/**
 * Liefert die konfigurierte ESP-Offline-Schwelle in Minuten (siehe migrate_20260823.sql,
 * Platform-Admin -> Einstellungen -> Abschnitt "ESP32 / Ausleseeinheiten"). Ein ESP gilt
 * nur dann als online, wenn die letzte Statusmeldung "online" war UND das nicht länger als
 * dieser Wert her ist -- Sicherheitsnetz gegen ein hängengebliebenes Gerät, dessen MQTT-
 * Last-Will-Testament nie auslöst (TCP-Verbindung technisch noch offen, Firmware aber tot).
 */
function espOfflineAfterMinutes(): int
{
    try {
        return (int)(DB::fetchOne('SELECT esp_offline_after_minutes FROM platform_settings WHERE id = 1')['esp_offline_after_minutes'] ?? 5);
    } catch (\Throwable $e) {
        return 5;
    }
}

/**
 * Liefert die neueste verfügbare ESP-Firmwareversion (ohne "p1-smartmeter-v"-Präfix), damit die
 * Mitglied-Detailseite je Zählpunkt anzeigen kann, ob das Gerät schon aktualisiert hat oder ein
 * Vor-Ort-Termin nötig ist (Patrick, 12.08.2026). Gleiche Quelle wie checkForFirmwareUpdate() im
 * ESP32-Sketch selbst: GitHub-Releases dieses Repos, gefiltert auf Tags mit dem Präfix, neuester
 * nicht-Draft-Treffer gewinnt (Vorabversionen/Beta zählen bewusst NICHT als "neueste Version"
 * für den Vergleich -- Feldgeräte sollen nicht als "veraltet" markiert werden, nur weil eine
 * interne Testversion vorne liegt, siehe esp32-firmware/p1-smart-meter/README.md).
 *
 * @return string|null Versionsstring ohne Präfix (z.B. "1.2.0"), oder null wenn (noch) nichts
 *                      bekannt ist (Cache leer und GitHub nicht erreichbar).
 *
 * Ergebnis wird 1h in platform_settings gecacht (migrate_20260828.sql) statt bei jedem
 * Seitenaufruf einen eigenen GitHub-API-Request auszulösen (unauthentifiziertes Rate-Limit).
 * Schlägt der Request fehl (kein Internet, GitHub down, Rate-Limit), bleibt der zuletzt bekannte
 * Cache-Wert stehen -- eine Netzwerkstörung soll die Detailseite nie zum Absturz bringen.
 */
function latestFirmwareVersion(): ?string
{
    $repo = 'ropperp/eeg-platform';
    $tagPrefix = 'p1-smartmeter-v';
    try {
        $cached = DB::fetchOne('SELECT latest_firmware_version, latest_firmware_checked_at FROM platform_settings WHERE id = 1');
    } catch (\Throwable $e) {
        return null;
    }
    $checkedAt = $cached['latest_firmware_checked_at'] ?? null;
    $isFresh = $checkedAt && (time() - strtotime($checkedAt)) < 3600;
    if ($isFresh) {
        return $cached['latest_firmware_version'] ?: null;
    }

    $url = 'https://api.github.com/repos/' . $repo . '/releases?per_page=10';
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "User-Agent: eeg-platform-firmware-check\r\nAccept: application/vnd.github+json\r\n",
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $releases = $body ? json_decode($body, true) : null;

    $latest = null;
    if (is_array($releases)) {
        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? '';
            if (!empty($release['draft']) || !empty($release['prerelease'])) { continue; }
            if (strpos($tag, $tagPrefix) !== 0) { continue; }
            $latest = substr($tag, strlen($tagPrefix));
            break; // Releases-Liste ist bereits neueste zuerst sortiert
        }
    }

    try {
        if ($latest !== null) {
            DB::execute('UPDATE platform_settings SET latest_firmware_version = ?, latest_firmware_checked_at = now() WHERE id = 1', [$latest]);
        } else {
            // Kein gültiger Treffer -- trotzdem "geprüft" vermerken, sonst würde ein dauerhaft
            // falsch konfiguriertes Repo/Tag-Präfix bei JEDEM Seitenaufruf erneut angefragt.
            DB::execute('UPDATE platform_settings SET latest_firmware_checked_at = now() WHERE id = 1', []);
        }
    } catch (\Throwable $e) {
        // Cache-Update fehlgeschlagen (z.B. Migration noch nicht eingespielt) -- Anzeige bleibt
        // einfach ohne Vergleichswert, kein Fehler auf der Seite.
    }

    return $latest ?? ($cached['latest_firmware_version'] ?? null);
}

/**
 * Aktuelle Netto-Leistung (W) eines Mitglieds über dessen eigene aktive Zählpunkte, aus dem
 * jeweils NEUESTEN Messwert je Zählpunkt (nicht Summe über alle Zeilen -- siehe Live-Leistungs-
 * Bugfix vom 30.07.2026). Vorzeichenkonvention auf Wunsch von Patrick: positiv = es wird gerade
 * (netto) bezogen, negativ = es wird (netto) eingespeist. Gibt null zurück, wenn in den letzten
 * 2 Minuten kein Messwert vorliegt (kein ESP installiert oder gerade offline) -- explizit
 * unterschieden von "aktuell 0 W netto", damit die Anzeige nicht fälschlich "0 W Bezug" zeigt.
 */
function memberCurrentNetPowerW(string $communityId, array $meteringPointIds): ?float
{
    if (!$meteringPointIds) return null;
    $placeholders = implode(',', array_fill(0, count($meteringPointIds), '?'));
    $row = DB::fetchOne(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(power_bezug_w), 0) - COALESCE(SUM(power_einspeisung_w), 0) AS net_w
         FROM (
            SELECT DISTINCT ON (metering_point_id) power_bezug_w, power_einspeisung_w
            FROM esp_measurements
            WHERE community_id = ? AND metering_point_id IN ($placeholders)
              AND time >= now() - INTERVAL '2 minutes'
            ORDER BY metering_point_id, time DESC
         ) latest",
        array_merge([$communityId], $meteringPointIds)
    );
    return ($row && (int)$row['cnt'] > 0) ? (float)$row['net_w'] : null;
}

/**
 * Aktuelle Gesamt-Leistung (W) einer ganzen Community, aus dem jeweils neuesten Messwert je
 * Zählpunkt (nicht Summe über alle Zeilen). Gemeinsame Grundlage für die "Live-Leistung"-Kachel
 * im Obmann-Dashboard und den Polling-Endpunkt /portal/api/live-power (Patrick, 30.07.2026:
 * beide sollen dieselbe Zahl liefern, daher eine gemeinsame Funktion statt zweimal dieselbe SQL).
 */
function communityLivePower(string $communityId): array
{
    // mirror_source_metering_point_id IS NULL: Demo-Zählpunkte (siehe migrate_20260906.sql)
    // bewusst aus JEDER Community-weiten Summe ausschließen -- ihre Live-Werte sind ja nur eine
    // Live-Spiegelung eines ANDEREN, bereits selbst in dieser Summe enthaltenen Zählpunkts
    // (Patrick, 06.09.2026: "es dürfen die Daten nicht doppelt in dem Energiefluss angezeigt
    // werden"). Die eigene "Aktuelle Leistung"-Kachel des Demo-Mitglieds bleibt davon unberührt,
    // die fragt gezielt nur die eigenen Zählpunkte ab (memberCurrentNetPowerW()).
    $row = DB::fetchOne(
        "SELECT COALESCE(SUM(power_einspeisung_w),0) AS einsp_w, COALESCE(SUM(power_bezug_w),0) AS bezug_w,
                COUNT(*) AS active_meters
         FROM (
            SELECT DISTINCT ON (em.metering_point_id) em.power_einspeisung_w, em.power_bezug_w
            FROM esp_measurements em
            JOIN metering_points mp ON mp.id = em.metering_point_id AND mp.mirror_source_metering_point_id IS NULL
            WHERE em.community_id = ? AND em.time >= now() - INTERVAL '2 minutes'
            ORDER BY em.metering_point_id, em.time DESC
         ) latest",
        [$communityId]
    );
    // total_meters: alle aktiven Zählpunkte, die schon mindestens einmal gemeldet haben (nicht
    // "noch nie installiert") -- Grundlage fürs Disclaimer unten (aktive_meters < total_meters
    // bedeutet: mindestens ein bekannter ESP ist gerade offline, die Summenwerte sind dann
    // unvollständig, siehe Patrick 30.07.2026). Demo-Zählpunkte auch hier ausgeschlossen.
    $total = DB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM metering_points
         WHERE community_id = ? AND active = true AND meter_code IS NOT NULL AND esp_last_seen_at IS NOT NULL
           AND mirror_source_metering_point_id IS NULL",
        [$communityId]
    );
    return [
        'bezug_w'       => (int)($row['bezug_w'] ?? 0),
        'einsp_w'       => (int)($row['einsp_w'] ?? 0),
        'active_meters' => (int)($row['active_meters'] ?? 0),
        'total_meters'  => (int)($total['cnt'] ?? 0),
    ];
}

/**
 * Löst auf, in welcher/welchen Community(s) ein User-Account eine AKTIVE Mitgliedschaft hat --
 * ein Baustein von resolveAppRoleOptions() (App-Login). Fragt bewusst zuerst user_roles
 * (role='member', KEINE RLS -- siehe Auth::establishSession() für dasselbe Muster) statt direkt
 * members, weil members Row-Level Security hat und app.community_id an dieser Stelle noch
 * unbekannt ist -- für jeden gefundenen Kandidaten wird die Community deshalb einzeln aktiviert
 * (DB::setCommunity()) und DANACH der zugehörige members-Datensatz nachgeschlagen, damit RLS
 * korrekt greift statt eine Zeile über alle Communities hinweg zu joinen.
 *
 * ur.member_id wird mitgeladen, weil Demo-Logins (migrate_20260905.sql) mehrere 'member'-Zeilen
 * in DERSELBEN Community haben können (zwei unabhängig wählbare Mitglied-Identitäten) -- ohne
 * member_id würde hier nur EIN members-Datensatz je Community aufgelöst (und die zweite
 * Mitglied-Identität wäre in der App nie wählbar).
 *
 * @return array<int, array{member_id:string, community_id:string, community_name:string, name:string}>
 */
function resolveAppMemberships(string $userId): array
{
    $roles = DB::fetchAll(
        "SELECT ur.community_id, ur.member_id, c.name AS community_name
         FROM user_roles ur JOIN communities c ON c.id = ur.community_id
         WHERE ur.user_id = ? AND ur.role = 'member'
         ORDER BY c.name",
        [$userId]
    );
    $out = [];
    foreach ($roles as $r) {
        DB::setCommunity($r['community_id']);
        $member = $r['member_id']
            ? DB::fetchOne(
                "SELECT id, first_name, last_name FROM members WHERE id = ? AND community_id = ? AND status = 'active'",
                [$r['member_id'], $r['community_id']]
            )
            : DB::fetchOne(
                "SELECT id, first_name, last_name FROM members WHERE user_id = ? AND community_id = ? AND status = 'active'",
                [$userId, $r['community_id']]
            );
        if ($member) {
            $out[] = [
                'member_id'      => $member['id'],
                'community_id'   => $r['community_id'],
                'community_name' => $r['community_name'],
                'name'           => trim($member['first_name'] . ' ' . $member['last_name']),
            ];
        }
    }
    return $out;
}

/**
 * Löst auf, für welche Community(s) ein User-Account eine Obmann-/Manager-Berechtigung hat
 * (role IN 'manager','platform_admin' -- Platform-Admins dürfen wie im Web jede EEG verwalten,
 * siehe Auth::isManager()). user_roles/communities haben KEINE RLS, ein direkter Join ist hier
 * also (anders als bei resolveAppMemberships()) unproblematisch -- es wird ja kein
 * community-gebundener members-Datensatz nachgeladen.
 *
 * @return array<int, array{community_id:string, community_name:string}>
 */
function resolveAppManagerRoles(string $userId): array
{
    $rows = DB::fetchAll(
        "SELECT DISTINCT ur.community_id, c.name AS community_name
         FROM user_roles ur JOIN communities c ON c.id = ur.community_id
         WHERE ur.user_id = ? AND ur.role IN ('manager', 'platform_admin') AND c.active = true
         ORDER BY c.name",
        [$userId]
    );
    return array_map(fn($r) => [
        'community_id'   => $r['community_id'],
        'community_name' => $r['community_name'],
    ], $rows);
}

/** Ob der Account eine plattformweite Admin-Rolle hat (unabhängig von der Community-Spalte
 *  der jeweiligen user_roles-Zeile -- user_roles hat KEINE RLS, siehe migrate_20260731.sql für
 *  dasselbe Muster). Grundlage für die vierte Option in resolveAppRoleOptions() (role='admin'). */
function resolveAppIsAdmin(string $userId): bool
{
    return (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'platform_admin' LIMIT 1",
        [$userId]
    );
}

/**
 * Kombiniert Mitglieds-, Obmann- und Admin-Optionen für den App-Login zu einer einheitlichen
 * Liste, aus der der Client (bei mehr als einer Option) auswählen kann -- jeder Eintrag trägt
 * zusätzlich "role", damit appIssueSessionResponse() weiß, welche Art Token auszustellen ist.
 *
 * @return array<int, array{role:string, member_id:?string, community_id:?string, community_name:string, name:string}>
 */
function resolveAppRoleOptions(string $userId): array
{
    $out = [];
    foreach (resolveAppMemberships($userId) as $m) {
        $out[] = [
            'role'           => 'member',
            'member_id'      => $m['member_id'],
            'community_id'   => $m['community_id'],
            'community_name' => $m['community_name'],
            'name'           => $m['name'],
            'user_id'        => $userId,
        ];
    }
    foreach (resolveAppManagerRoles($userId) as $m) {
        $out[] = [
            'role'           => 'manager',
            'member_id'      => null,
            'community_id'   => $m['community_id'],
            'community_name' => $m['community_name'] . ' (Obmann)',
            'name'           => $m['community_name'],
            'user_id'        => $userId,
        ];
    }
    // Admin ist bewusst NICHT an eine EEG gebunden (community_id NULL) -- deshalb eine einzelne
    // Option statt einer je Community wie bei Obmann oben, siehe requireAdminAuth().
    if (resolveAppIsAdmin($userId)) {
        $out[] = [
            'role'           => 'admin',
            'member_id'      => null,
            'community_id'   => null,
            'community_name' => 'Plattform-Admin',
            'name'           => 'Admin',
            'user_id'        => $userId,
        ];
    }
    return $out;
}

/**
 * Live-Schätzung, wie viel der eigenen Einspeisung im gewählten Zeitraum tatsächlich von
 * Mitgliedern DERSELBEN Gemeinschaft verbraucht wurde ("Einspeisung in die Gemeinschaft"),
 * selbst aus den ESP-Messwerten berechnet -- NICHT der amtliche, vom Netzbetreiber angewandte
 * Aufteilungsschlüssel (siehe docs/AUFTEILUNGSSCHLUESSEL.md)! Diese Funktion ist bewusst nur
 * eine ergänzende Live-Kennzahl fürs Mitglieder-Dashboard, für die Abrechnung zählt weiterhin
 * ausschließlich der offizielle EDA-Import -- sonst gäbe es zwei unterschiedliche "Wahrheiten"
 * für dieselbe Sache (Patrick, 30.07.2026).
 *
 * WICHTIG (Patrick, 30.07.2026, nach Rückfrage): Es wird IMMER zuerst pro Viertelstunden-Fenster
 * ermittelt, wie viel gemeinschaftlich genutzt werden konnte, bevor über mehrere Fenster hinweg
 * (die gewählte 1/3/6/24h-Spanne, ein Tag, ein Zeitraum) aufsummiert wird -- NIE umgekehrt erst
 * Bezug/Einspeisung über den ganzen Zeitraum aufsummieren und danach ein Minimum bilden. Das wäre
 * grob falsch: an einem Tag mit viel Sonne wird tagsüber stark eingespeist, nachts dagegen vom
 * öffentlichen Netz bezogen (kaum Gemeinschafts-Erzeugung) -- ein day-weites Minimum würde einen
 * viel zu hohen "gemeinschaftlich genutzt"-Wert vortäuschen, weil sich Tag- und Nacht-Mengen im
 * Gesamtbetrag scheinbar decken, obwohl sie zeitlich nie zusammenfielen.
 *
 * Methode je Viertelstunden-Fenster: statt der Momentanleistung (die zwischen den ~5s-Messwerten
 * nur eine Näherung wäre) wird die tatsächliche Energie aus der Differenz der kumulativen
 * Zählerstände (energy_bezug_wh/energy_einspeisung_wh -- exakt dieselben Register, die auch der
 * Smart Meter/Netzbetreiber führt) am Ende von je zwei aufeinanderfolgenden Fenstern gebildet --
 * robust auch bei Lücken (ESP kurz offline), da nur die beiden Registerstände an den Rändern
 * zählen, nicht jede einzelne Zwischenmessung. Die community-weit gemeinsam nutzbare Menge
 * (= min(Gesamt-Bezug, Gesamt-Einspeisung) DIESES EINEN Fensters -- alles darüber hinaus ginge
 * ins öffentliche Netz) wird dann proportional zur eigenen Einspeisung in genau diesem Fenster
 * aufgeteilt; erst danach wird über die Fenster im gewählten Zeitraum aufsummiert.
 */
function ownEinspeisungInGemeinschaftKwh(string $communityId, array $meteringPointIds, string $fromSql, string $toSql): float
{
    if (!$meteringPointIds) return 0.0;
    $placeholders = implode(',', array_fill(0, count($meteringPointIds), '?'));
    $row = DB::fetchOne(
        "WITH bucket_readings AS (
            -- Letzter Registerstand je Zählpunkt und Viertelstunden-Fenster -- ein Fenster vor
            -- \$fromSql mit dazu, damit das erste Fenster im gewählten Zeitraum einen gültigen
            -- Vorgänger-Registerstand für die Differenzbildung hat (sonst wäre dessen Delta NULL).
            SELECT DISTINCT ON (metering_point_id, bucket)
                   bucket, metering_point_id, energy_bezug_wh, energy_einspeisung_wh
            FROM (
                SELECT time_bucket('15 minutes', time) AS bucket, metering_point_id, time,
                       energy_bezug_wh, energy_einspeisung_wh
                FROM esp_measurements
                WHERE community_id = ?
                  AND time >= (?::timestamptz - INTERVAL '15 minutes')
                  AND time < ?
            ) raw
            ORDER BY metering_point_id, bucket, time DESC
         ),
         bucket_deltas AS (
            SELECT bucket, metering_point_id,
                   GREATEST(energy_bezug_wh - LAG(energy_bezug_wh) OVER w, 0)       AS bezug_delta_wh,
                   GREATEST(energy_einspeisung_wh - LAG(energy_einspeisung_wh) OVER w, 0) AS einspeisung_delta_wh
            FROM bucket_readings
            WINDOW w AS (PARTITION BY metering_point_id ORDER BY bucket)
         ),
         in_range AS (
            -- Das zusätzliche Vorgänger-Fenster (nur für LAG gebraucht) hier wieder ausschließen.
            SELECT * FROM bucket_deltas WHERE bucket >= ?
         ),
         totals AS (
            SELECT bucket,
                   SUM(bezug_delta_wh)       / 1000.0 AS total_bezug_kwh,
                   SUM(einspeisung_delta_wh) / 1000.0 AS total_einspeisung_kwh
            FROM in_range GROUP BY bucket
         ),
         eigen AS (
            SELECT bucket, SUM(einspeisung_delta_wh) / 1000.0 AS eigen_einspeisung_kwh
            FROM in_range WHERE metering_point_id IN ($placeholders)
            GROUP BY bucket
         )
         SELECT COALESCE(SUM(
            e.eigen_einspeisung_kwh
            * LEAST(t.total_bezug_kwh, t.total_einspeisung_kwh)
            / NULLIF(t.total_einspeisung_kwh, 0)
         ), 0) AS kwh
         FROM eigen e JOIN totals t ON t.bucket = e.bucket",
        array_merge([$communityId, $fromSql, $toSql, $fromSql], $meteringPointIds)
    );
    return (float)($row['kwh'] ?? 0);
}

/**
 * Lädt ein Mitglied anhand der ID community-übergreifend und prüft den Zugriff: Platform-Admins
 * dürfen jedes Mitglied verwalten, Manager nur die der eigenen aktiven Rolle (IDOR-Schutz).
 * Setzt bei Erfolg gleich die RLS-Community auf die des MITGLIEDS (nicht die der gerade aktiven
 * Rolle) -- wichtig, damit ein Platform-Admin ein Mitglied einer anderen EEG als der eigenen
 * aktiven bearbeiten kann, ohne vorher extra die Rolle wechseln zu müssen. Sendet bei fehlendem
 * Zugriff direkt die passende HTTP-Antwort und gibt null zurück.
 */
function requireMemberAccess(string $memberId): ?array
{
    // members hat Row-Level Security (community-gebunden, siehe OWASP-Audit 13.08.2026) --
    // eine Abfrage OHNE vorher gesetztes app.community_id liefert seitdem für die eingeschränkte
    // Laufzeit-Rolle grundsätzlich GAR KEINE Zeile (auch bei korrekter ID), nicht mehr wie
    // früher (als die App noch als Tabellenbesitzer verband und RLS nie griff). Henne-Ei-Problem:
    // die Community ist erst nach dem Laden des Mitglieds bekannt.
    //
    // Manager: die eigene aktive Community ist bereits aus der Session bekannt (kein Henne-Ei-
    // Problem) -- direkt setzen, dann laden. Liefert für ein Mitglied EINER ANDEREN Community
    // korrekt keine Zeile (RLS blockt es), was denselben IDOR-Schutz ergibt wie die explizite
    // Prüfung unten, nur jetzt auch auf DB-Ebene abgesichert.
    //
    // Platform-Admin: darf JEDE EEG verwalten, die eigene aktive Rolle kennt aber nur EINE
    // Community -- reicht hier nicht. communities hat keine RLS (siehe migrate_20260731.sql für
    // dasselbe Muster bei user_roles), deshalb wird jede Community einzeln versucht, bis das
    // Mitglied gefunden ist (aktuell überschaubar viele EEGs, unproblematisch).
    if (Auth::isPlatformAdmin()) {
        $member = null;
        foreach (DB::fetchAll('SELECT id FROM communities') as $c) {
            DB::setCommunity($c['id']);
            $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$memberId]);
            if ($member) break;
        }
    } else {
        DB::setCommunity(Auth::activeCommunityId());
        $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$memberId]);
    }
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return null; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return null;
    }
    return $member;
}

/**
 * Liefert die anzuzeigende Avatar-URL für ein Mitglied: das eigene hochgeladene Foto,
 * falls vorhanden, sonst ein generischer Default-Avatar passend zur Anrede (statt eines
 * einzigen unpassenden "Männchens" für alle).
 */
function memberAvatarUrl(?string $memberId, ?string $photoPath, ?string $salutation): string
{
    if ($memberId && $photoPath) {
        return '/portal/members/' . $memberId . '/avatar';
    }
    return match ($salutation) {
        'Frau'  => '/assets/avatars/female.svg',
        'Herr'  => '/assets/avatars/male.svg',
        default => '/assets/avatars/neutral.svg',
    };
}

/**
 * Validiert und speichert eine hochgeladene Profilbild-Datei unter einem eindeutigen Key
 * (z.B. "member_<id>" oder "user_<id>"). Gibt ['path' => string, 'error' => null] bei Erfolg
 * zurück, sonst ['path' => null, 'error' => Fehler-Code]. Kümmert sich nur um die
 * Dateiablage, nicht um die DB-Zeile (die ist je Tabelle unterschiedlich).
 */
function storeAvatarFile(string $key, array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) { return ['path' => null, 'error' => 'upload']; }
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) { return ['path' => null, 'error' => 'phototype']; }

    $dir = '/var/www/html/storage/uploads/avatars';
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $destPath = $dir . '/' . $key . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) { return ['path' => null, 'error' => 'upload']; }

    // Altes Bild mit anderer Dateiendung entfernen, falls beim Ändern ein anderer Typ hochgeladen wurde.
    foreach ($allowedExt as $oldExt) {
        if ($oldExt !== $ext) { @unlink($dir . '/' . $key . '.' . $oldExt); }
    }
    return ['path' => $destPath, 'error' => null];
}

/**
 * Speichert ein hochgeladenes Profilbild für ein Mitglied (manager-seitig oder
 * Selbstbedienung im eigenen Profil). Gibt bei Erfolg null zurück, sonst einen kurzen
 * Fehler-Code (ggf. mit ":Detail" für DB-Fehler) für die Location-Weiterleitung.
 */
function saveMemberPhoto(string $memberId, array $file): ?string
{
    $r = storeAvatarFile('member_' . $memberId, $file);
    if ($r['error']) { return $r['error']; }
    try {
        DB::execute('UPDATE members SET photo_path = ? WHERE id = ?', [$r['path'], $memberId]);
    } catch (\Throwable $e) {
        unlink($r['path']);
        return 'upload_db:' . $e->getMessage();
    }
    return null;
}

/**
 * Speichert ein hochgeladenes Profilbild für einen Login-Account ohne eigenen
 * Mitgliedsdatensatz (Manager/Platform-Admin) -- Selbstbedienung im eigenen Profil.
 */
function saveUserPhoto(string $userId, array $file): ?string
{
    $r = storeAvatarFile('user_' . $userId, $file);
    if ($r['error']) { return $r['error']; }
    try {
        DB::execute('UPDATE users SET photo_path = ? WHERE id = ?', [$r['path'], $userId]);
    } catch (\Throwable $e) {
        unlink($r['path']);
        return 'upload_db:' . $e->getMessage();
    }
    return null;
}

/**
 * Avatar-URL für einen Login-Account ohne eigenen Mitgliedsdatensatz (Manager/Platform-Admin).
 */
function userAvatarUrl(string $userId, ?string $photoPath): string
{
    return $photoPath ? '/portal/users/' . $userId . '/avatar' : '/assets/avatars/neutral.svg';
}

/**
 * Ordnet eine frei getippte Datei-Bezeichnung einer festen Kategorie zu (Groß-/Kleinschreibung
 * und Kurzformen wie "Bezugsvertrag" statt "Bezugsvereinbarung" werden toleriert), damit manuell
 * hochgeladene Dateien trotzdem unter der passenden Zeile in der Dateien-Übersicht auftauchen.
 */
function matchFileCategory(string $name): ?string
{
    $n = str_replace(['ä', 'ö', 'ü'], ['ae', 'oe', 'ue'], mb_strtolower(trim($name)));
    $categories = [
        'beitritt'    => ['beitritt'],
        'bezug'       => ['bezug'],
        'einspeisung' => ['einspeisung'],
        'ausweis'     => ['personalausweis', 'reisepass', 'ausweis'],
    ];
    foreach ($categories as $key => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($n, $needle)) return $key;
        }
    }
    return null;
}

/**
 * Extrahiert den Ort aus einer frei eingegebenen Adresse "Straße Nr., PLZ Ort"
 * (Community-Adresse ist ein einzelnes Freitextfeld ohne getrennte Ort-Spalte).
 * Nimmt das letzte Komma-Segment und entfernt eine vorangestellte PLZ.
 */
function extractOrtFromAddress(?string $address): string
{
    $parts = explode(',', $address ?? '');
    $last = trim(end($parts));
    return trim(preg_replace('/^\d{3,6}\s*/', '', $last));
}

// texEscape nach src/functions.php ausgelagert (testbar).

/**
 * Baut ein 33-Kästchen-Raster (wie am Papier-Beitrittsformular) und trägt die
 * Zeichen einer Zählpunktnummer einzeln ein. Ohne Wert bleibt das Raster leer.
 */
function zpGridTikz(?string $zp): string
{
    $chars = array_slice(str_split(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($zp ?? '')))), 0, 33);
    $nodes = '';
    foreach ($chars as $i => $c) {
        $nodes .= '\\node at (' . sprintf('%.2f', 0.24 + $i * 0.5) . ',0.29){\\small ' . $c . '};' . "\n";
    }
    return '\\begin{tikzpicture}[baseline=-3pt]' . "\n"
        . '\\foreach \\i in {0,...,32}{\\draw[boxgray] (\\i*0.5,0) rectangle ++(0.48,0.58);}' . "\n"
        . $nodes
        . '\\end{tikzpicture}';
}

/**
 * Liefert die RAW_-Variable fürs Unterschriftsbild "Für die EEG" sowie das
 * zugehörige Bild-Asset für den angegebenen User (Default: der aktuell eingeloggte, i.d.R.
 * der Obmann/die Obfrau, der/die den Vertrag gerade erzeugt). Ohne hinterlegte Unterschrift
 * bleibt die Zeile leer (nur die Unterschriftslinie).
 */
function eegSignatureAsset(?string $userId = null): array
{
    $user = DB::fetchOne('SELECT signature_image FROM users WHERE id = ?', [$userId ?? Auth::userId()]);
    if (empty($user['signature_image'])) {
        return ['var' => '', 'assets' => []];
    }
    return [
        'var'    => '\\includegraphics[height=1.4cm]{unterschrift_eeg.png}',
        'assets' => ['unterschrift_eeg.png' => $user['signature_image']],
    ];
}

/**
 * Wie eegSignatureAsset(), aber für die Selbstbedienungs-Vertragsansicht eines Mitglieds:
 * dort ist Auth::userId() das Mitglied selbst (hat nie eine Unterschrift hinterlegt), die
 * Vertrags-PDF braucht aber die Unterschrift eines Managers/Obmanns der jeweiligen EEG.
 * Nimmt irgendeinen Manager mit hinterlegter Unterschrift -- bei mehreren Obleuten ist es für
 * das Vertragsdokument unerheblich, wessen Unterschrift dort abgebildet ist.
 */
function communityManagerSignature(string $communityId): array
{
    $row = DB::fetchOne(
        "SELECT u.id FROM user_roles ur
         JOIN users u ON u.id = ur.user_id
         WHERE ur.community_id = ? AND ur.role = 'manager' AND u.signature_image IS NOT NULL
         LIMIT 1",
        [$communityId]
    );
    if (!$row) { return ['var' => '', 'assets' => []]; }
    return eegSignatureAsset($row['id']);
}

/**
 * Wie eegSignatureAsset(), aber fürs Mitglied selbst: bekommt die Unterschrift direkt übergeben
 * (aus members.contract_{type}_customer_signature), statt sie per DB-Lookup über einen User zu
 * holen -- das Mitglied unterschreibt digital im Portal (siehe /portal/my/contract/:type/sign),
 * nicht über das Manager-Unterschriftsfeld in den Einstellungen.
 */
function memberSignatureAsset(?string $dataUri): array
{
    if (empty($dataUri)) {
        return ['var' => '', 'assets' => []];
    }
    return [
        'var'    => '\\includegraphics[height=1.4cm]{unterschrift_mitglied.png}',
        'assets' => ['unterschrift_mitglied.png' => $dataUri],
    ];
}

/**
 * "Ort, Datum"-Zeile in der Mitglieds-Unterschriftsspalte: solange nicht digital unterschrieben
 * bleibt es eine leere Linie zum handschriftlichen Ausfüllen (Fallback für Papierunterschrift),
 * nach digitaler Unterschrift steht dort Ort (Wohnort des Mitglieds) und tatsächliches Datum.
 */
function memberOrtDatumLine(array $member, string $type): string
{
    $signedAt = $member['contract_' . $type . '_signed_at'] ?? null;
    if (!$signedAt) {
        return '\\underline{\\hspace{4cm}}';
    }
    return texEscape($member['city'] . ', ' . date('d.m.Y', strtotime($signedAt)));
}

/**
 * Ruft den latex-service auf und liefert die fertigen PDF-Bytes zurück (oder null bei Fehler,
 * dann steht die Fehlermeldung in $errorOut). Reine Datenbeschaffung ohne jede Ausgabe --
 * genutzt sowohl zum direkten Anzeigen (streamLatexPdf) als auch zum Mailversand als Anhang.
 */
function generateLatexPdf(string $template, array $vars, array $assets, ?string &$errorOut = null): ?string
{
    $url     = (getenv('LATEX_SERVICE_URL') ?: 'http://latex-service:3210') . '/generate';
    $apiKey  = getenv('LATEX_API_KEY') ?: 'dev-key';
    $payload = json_encode(['template' => $template, 'vars' => $vars, 'assets' => $assets]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Api-Key: {$apiKey}\r\n",
        'content' => $payload,
        'timeout' => 60,
        'ignore_errors' => true,
    ]]);

    $body = file_get_contents($url, false, $ctx);
    $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 500')[1];

    if ($code !== 200 || !$body) {
        $detail = '';
        if ($body) {
            $json = json_decode($body, true);
            $detail = isset($json['error']) ? ': ' . $json['error'] : '';
        }
        $errorOut = "PDF-Generierung fehlgeschlagen (HTTP {$code}){$detail}. Bitte latex-service prüfen.";
        return null;
    }
    return $body;
}

/**
 * Ruft den latex-service auf und streamt das PDF direkt an den Browser.
 * Gibt true zurück wenn das PDF erfolgreich gesendet wurde, sonst false.
 */
function streamLatexPdf(string $template, array $vars, string $filename, array $assets = []): bool
{
    $error = null;
    $body = generateLatexPdf($template, $vars, $assets, $error);
    if ($body === null) {
        http_response_code(500);
        echo '<pre>' . htmlspecialchars($error) . '</pre>';
        return false;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    return true;
}

/**
 * Legt ein Mitglied inkl. Login-User und Rolle an. Wird sowohl von der manuellen
 * Mitglieder-Anlage (/portal/members) als auch von der Freigabe einer Online-
 * Beitrittserklärung (/portal/applications/:id/approve) verwendet, damit KdNr-
 * Vergabe (Lücken-Auffüllung) und Mandatsreferenz-Logik nicht doppelt gepflegt werden.
 * Erwartet in $f Schlüssel wie die Spalten der members-Tabelle (salutation, titel, …).
 * Gibt ['member_id', 'user_id', 'kundennummer', 'temp_password' (oder null),
 * 'invite_sent' (bool), 'invite_error' (string oder null)] zurück.
 */
function createMemberRecord(string $communityId, array $f): array
{
    $email = strtolower(trim($f['email']));
    $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    $tempPw = null;
    $inviteSent = false;
    $inviteError = null;
    if (!$user) {
        $tempPw = bin2hex(random_bytes(8));
        $hash = password_hash($tempPw, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::execute(
            'INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)',
            [$email, $hash, trim($f['first_name']), trim($f['last_name'])]
        );
        $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);

        // Erstlogin-Einladung statt (nur) Temp-Passwort am Bildschirm: 24h gültiger Reset-Link,
        // der den ersten Login direkt mit einer selbst gewählten Passwortvergabe verbindet.
        // Schlägt der Mailversand fehl (z.B. Graph noch nicht konfiguriert), bleibt das
        // Temp-Passwort als Fallback nutzbar -- deshalb wird es trotzdem immer erzeugt.
        $token = Auth::createResetToken($email, 86400);
        if ($token) {
            try {
                $link = htmlspecialchars(passwordResetLink($token));
                $anrede = mailSalutation($f);
                $mail = renderMailTemplate('invite', [
                    'vorname'     => htmlspecialchars(trim($f['first_name'])),
                    'anrede'      => htmlspecialchars($anrede['anrede']),
                    'nachname'    => htmlspecialchars($anrede['nachname']),
                    'link'        => $link,
                    'gueltigkeit' => '24 Stunden',
                ],
                    'Willkommen bei Strom für alle – Zugang einrichten',
                    '<p>{{anrede}} {{nachname}},</p>'
                    . '<p>Ihr Zugang zum Mitgliederportal wurde angelegt. Bitte vergeben Sie über folgenden Link '
                    . 'innerhalb der nächsten {{gueltigkeit}} Ihr persönliches Passwort:</p>'
                    . '<p><a href="{{link}}">{{link}}</a></p>'
                );
                Mailer::send($email, $mail['subject'], $mail['body']);
                $inviteSent = true;
            } catch (\Throwable $e) {
                $inviteError = $e->getMessage();
                error_log('[invite_mail] ' . $e->getMessage());
            }
        }
    }

    // KdNr muss über alle EEGs hinweg eindeutig sein, da stromfueralle als Plattform
    // gemeinsam abrechnet und die Kundennummer auf der Rechnung steht -- siehe
    // migrate_20260723.sql (UNIQUE-Index dafür plattformweit).
    // Im Testmodus wird PLATTFORMWEIT die kleinste freie Nummer ab 10001 vergeben (füllt
    // Lücken von gelöschten/deaktivierten Mitgliedern auf -- praktisch zum Testen). Im
    // Echtbetrieb wird eine einmal vergebene Nummer nie wieder verwendet, daher immer
    // MAX(kundennummer)+1, egal ob dazwischen Lücken bestehen (siehe migrate_20260728.sql).
    $testMode = platformTestMode();
    if ($testMode) {
        $kundennummer = (int)DB::fetchOne(
            "SELECT MIN(candidate) AS next FROM generate_series(
                10001, (SELECT COALESCE(MAX(kundennummer), 10000) + 1 FROM members)
             ) AS candidate
             WHERE candidate NOT IN (
                SELECT kundennummer FROM members WHERE kundennummer IS NOT NULL
             )"
        )['next'];
    } else {
        $kundennummer = (int)DB::fetchOne(
            'SELECT COALESCE(MAX(kundennummer), 10000) + 1 AS next FROM members'
        )['next'];
    }
    $iban = trim($f['member_iban'] ?? '');
    $mandatsreferenz = $iban !== '' ? 'S00000F' . date('Y') . 'A' . $kundennummer : null;

    DB::execute(
        'INSERT INTO members (
            community_id, user_id, salutation, titel, first_name, last_name, company_name,
            address, zip, city, email, phone, invoice_uid, member_iban, member_bic,
            kontoinhaber, konto_adresse,
            member_since, member_until, kundennummer, mandatsreferenz, beitrittsdatum,
            geburtsdatum, stromlieferant, speicher_status, speicher_kwh, andere_eeg, andere_eeg_name,
            zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb,
            email_anrede_mode
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            $user['id'],
            $f['salutation'] ?? null,
            trim($f['titel'] ?? '') ?: null,
            trim($f['first_name']),
            trim($f['last_name']),
            trim($f['company_name'] ?? '') ?: null,
            trim($f['address'] ?? ''),
            trim($f['zip'] ?? ''),
            trim($f['city'] ?? ''),
            $email,
            trim($f['phone'] ?? '') ?: null,
            trim($f['invoice_uid'] ?? '') ?: null,
            $iban ?: null,
            trim($f['member_bic'] ?? '') ?: null,
            trim($f['kontoinhaber'] ?? '') ?: null,
            trim($f['konto_adresse'] ?? '') ?: null,
            $f['member_since'] ?: date('Y-m-d'),
            ($f['member_until'] ?? '') ?: '2099-12-31',
            $kundennummer,
            $mandatsreferenz,
            $f['member_since'] ?: date('Y-m-d'),
            ($f['geburtsdatum'] ?? '') ?: null,
            trim($f['stromlieferant'] ?? '') ?: null,
            ($f['speicher_status'] ?? '') ?: null,
            ($f['speicher_kwh'] ?? '') !== '' && ($f['speicher_kwh'] ?? null) !== null ? (float)$f['speicher_kwh'] : null,
            !empty($f['andere_eeg']) ? 'true' : 'false',
            trim($f['andere_eeg_name'] ?? '') ?: null,
            'true', 'true', 'true', 'true', 'true', 'true',
            in_array($f['email_anrede_mode'] ?? 'auto', ['auto', 'herr', 'frau', 'familie'], true) ? ($f['email_anrede_mode'] ?? 'auto') : 'auto',
        ]
    );
    $member = DB::fetchOne('SELECT id FROM members WHERE community_id = ? AND kundennummer = ?', [$communityId, $kundennummer]);

    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $user['id'], 'member']
    );

    return [
        'member_id'     => $member['id'],
        'user_id'       => $user['id'],
        'kundennummer'  => $kundennummer,
        'temp_password' => $tempPw,
        'invite_sent'   => $inviteSent,
        'invite_error'  => $inviteError,
    ];
}

/**
 * Legt bei der Mitglied-Neuanlage optional gleich einen Zählpunkt an (statt erst hinterher auf
 * der Detailseite), z.B. wenn beim manuellen Anlegen aus einer Offline-Beitrittserklärung schon
 * bekannt ist, ob/wie das Mitglied bezieht oder einspeist. $extra: 'meter_code' (13-stellige
 * Zählernummer), plus je nach Typ 'jahresverbrauch_kwh' bzw. 'engpassleistung_kw'/
 * 'geplante_einspeisung_kwh' -- dieselben Felder wie beim späteren Hinzufügen über
 * /portal/members/:id/metering-points. Die Zählpunktnummer selbst wurde vom Aufrufer bereits auf
 * Eindeutigkeit geprüft (siehe POST /portal/members); eine geteilte Zählernummer ist dagegen kein
 * Fehler (Prosumer-Regelfall), nur eine informative Postfach-Meldung wie beim regulären Hinzufügen.
 */
function createMeteringPointForMember(string $communityId, string $memberId, string $znr, string $type, array $extra): void
{
    $meterCode = trim($extra['meter_code'] ?? '') ?: null;
    if ($meterCode) {
        $sharedWith = DB::fetchOne(
            "SELECT 1 FROM metering_points WHERE community_id = ? AND meter_code = ? AND active = true",
            [$communityId, $meterCode]
        );
        if ($sharedWith) {
            notifyMeterCodeShared($communityId, $meterCode);
        }
    }
    $jahresverbrauch = trim((string)($extra['jahresverbrauch_kwh'] ?? '')) !== '' ? (float)str_replace(',', '.', $extra['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim((string)($extra['engpassleistung_kw'] ?? '')) !== '' ? (float)str_replace(',', '.', $extra['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim((string)($extra['geplante_einspeisung_kwh'] ?? '')) !== '' ? (float)str_replace(',', '.', $extra['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, meter_code, jahresverbrauch_kwh, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
         ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
        [$communityId, $memberId, $znr, $type, $meterCode, $jahresverbrauch, $engpassleistung, $geplanteEinsp]
    );
}

/**
 * Schreibt einen Eintrag ins Admin-Aktivitätslog (Abrechnung, Mitglieder, EDA-Import,
 * Fehlermeldungen, Änderungen an Mitglied/EEG). Absichtlich fehlertolerant: ein Logging-
 * Fehler darf die eigentliche Aktion nie verhindern.
 */
function logAudit(?string $communityId, string $aktion, ?string $entityTyp, ?string $entityId, string $beschreibung, bool $istFehler = false): void
{
    try {
        DB::execute(
            'INSERT INTO audit_log (community_id, user_id, aktion, entity_typ, entity_id, beschreibung, ist_fehler)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$communityId, Auth::userId(), $aktion, $entityTyp, $entityId, $beschreibung, $istFehler ? 'true' : 'false']
        );
    } catch (Throwable $e) {
        error_log('[audit_log] ' . $e->getMessage());
    }
}

/**
 * Audit-Eintrag mit strukturierten Vorher/Nachher-Werten. $changes stammt aus auditDiff()
 * (Feld-Key => ['label','von','auf']). Ist nichts geändert, wird bewusst NICHTS protokolliert
 * (kein Rauschen). Die Änderungen landen sowohl lesbar in beschreibung als auch maschinenlesbar
 * in der JSONB-Spalte aenderungen (für Export/spätere Auswertung).
 */
function logAuditDiff(?string $communityId, string $aktion, ?string $entityTyp, ?string $entityId, array $changes, string $prefix = ''): void
{
    if (empty($changes)) return;
    $beschreibung = trim($prefix !== '' ? $prefix . ' ' . auditChangesText($changes) : auditChangesText($changes));
    try {
        DB::execute(
            'INSERT INTO audit_log (community_id, user_id, aktion, entity_typ, entity_id, beschreibung, aenderungen)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$communityId, Auth::userId(), $aktion, $entityTyp, $entityId, $beschreibung,
             json_encode(array_values($changes), JSON_UNESCAPED_UNICODE)]
        );
    } catch (Throwable $e) {
        error_log('[audit_log] ' . $e->getMessage());
        // Fallback ohne JSONB (z.B. wenn Migration noch nicht eingespielt), damit die Änderung
        // wenigstens als Freitext dokumentiert bleibt.
        logAudit($communityId, $aktion, $entityTyp, $entityId, $beschreibung);
    }
}

/**
 * Informative Postfach-Meldung, wenn dieselbe Zählernummer jetzt zu zwei aktiven Zählpunkten
 * gehört -- in Österreich der Normalfall bei einem Prosumer (eigene Zählpunktnummern für Bezug
 * und Einspeisung, aber ein gemeinsamer physischer Zähler/eine ESP-Ausleseeinheit). Kein Fehler,
 * daher kein Blocken -- der mqtt-subscriber ordnet ankommende ESP-Daten automatisch korrekt
 * beiden Zählpunkten zu (get_metering_points()), diese Meldung dient nur der Transparenz.
 * Dedup wie bei notify_unknown_meter()/notify_ssid_changed() im mqtt-subscriber: kein zweiter
 * offener Eintrag für dieselbe Zählernummer.
 */
function notifyMeterCodeShared(string $communityId, string $meterCode): void
{
    $key = "meter_shared:{$meterCode}";
    try {
        $existing = DB::fetchOne(
            "SELECT 1 FROM notifications WHERE community_id = ? AND text LIKE ? AND status = 'offen'",
            [$communityId, $key . ':%']
        );
        if ($existing) return;
        DB::execute(
            "INSERT INTO notifications (community_id, typ, titel, text, status) VALUES (?, ?, ?, ?, 'offen')",
            [
                $communityId,
                'zaehlernummer_geteilt',
                'Zählernummer doppelt vergeben (Bezug + Einspeisung)',
                "{$key}: Die Zählernummer {$meterCode} ist jetzt zwei aktiven Zählpunkten zugeordnet. "
                    . 'Das ist bei einem Prosumer normal (eigene Zählpunktnummern für Bezug und Einspeisung, '
                    . 'aber ein gemeinsamer physischer Zähler). Die ESP-Ausleseeinheit wird als ein Gerät '
                    . 'behandelt, ihre Daten werden automatisch korrekt auf beide Zählpunkte aufgeteilt und '
                    . 'nur einmal verarbeitet -- keine Aktion nötig.',
            ]
        );
    } catch (Throwable $e) {
        error_log('[notifyMeterCodeShared] ' . $e->getMessage());
    }
}

// Domain-Trennung: stromfueralle.at (+ www) zeigt NUR die öffentliche Marketing-Seite,
// portal.stromfueralle.at NUR Login/Backoffice (/portal/*, /admin/*). Traefik routet beide
// Hosts auf denselben Container/Code -- die eigentliche Trennung passiert hier per Redirect.
// Andere Hosts (live.stromfueralle.at, lokale Tests über IP/localhost, ...) bleiben unberührt.
$requestHost = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isBackofficePath = str_starts_with($requestPath, '/portal') || str_starts_with($requestPath, '/admin');

if ($requestHost === 'portal.stromfueralle.at' && !$isBackofficePath) {
    header('Location: https://stromfueralle.at' . $_SERVER['REQUEST_URI']);
    exit;
}
if (in_array($requestHost, ['stromfueralle.at', 'www.stromfueralle.at'], true) && $isBackofficePath) {
    header('Location: https://portal.stromfueralle.at' . $_SERVER['REQUEST_URI']);
    exit;
}

$router = new Router();

// ─── Gesundheitscheck ───────────────────────────────────
$router->get('/health', function () {
    echo 'OK';
});

// ─── Landingpage ────────────────────────────────────────
$router->get('/', function () {
    require ROOT . '/src/views/pages/home.php';
});

// ─── Informieren und Beitreten: Auswahl der Energiegemeinschaft ─────────
$router->get('/beitreten', function () {
    $communities = DB::fetchAll('SELECT * FROM communities WHERE active = true ORDER BY name');
    // Communities mit bereits veröffentlichten Beitritts-/Rechtsunterlagen (Statuten, AGB, …).
    // Für neu angelegte EEGs ohne eigene Unterlagen wird "Informationen folgen in Kürze" angezeigt,
    // statt fälschlich die Texte einer anderen Energiegemeinschaft darzustellen.
    $communitiesWithLegalPages = ['rc108175'];
    require ROOT . '/src/views/pages/beitreten_picker.php';
});

// ─── Rechtliches (rc108175 = Marktpartner-ID Strompool Feldkirchen Süd-West) ──
$router->get('/rc108175/beitreten', function () {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ?', ['rc108175']);
    require ROOT . '/src/views/pages/legal_beitreten.php';
});

$router->get('/rc108175/kontakt', function () {
    require ROOT . '/src/views/pages/legal_kontakt.php';
});

$router->get('/rc108175/impressum', function () {
    require ROOT . '/src/views/pages/legal_impressum.php';
});

$router->get('/rc108175/statuten', function () {
    require ROOT . '/src/views/pages/legal_statuten.php';
});

$router->get('/rc108175/datenschutz', function () {
    require ROOT . '/src/views/pages/legal_datenschutz.php';
});

$router->get('/rc108175/agb', function () {
    require ROOT . '/src/views/pages/legal_agb.php';
});

$router->get('/rc108175/preisliste', function () {
    // Datengetrieben: aktuelle Preise + vollständige Änderungshistorie aus tariff_config
    // (jede Tarifänderung legt dort einen neuen Datensatz mit valid_from an). So können
    // Mitglieder jederzeit nachvollziehen, welcher Preis seit wann galt.
    $community = DB::fetchOne("SELECT * FROM communities WHERE lower(marktpartner_id) = 'rc108175' LIMIT 1");
    $tariffHistory = [];
    if ($community) {
        DB::setCommunity($community['id']);
        $tariffHistory = DB::fetchAll(
            'SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC, created_at DESC',
            [$community['id']]
        );
    }
    require ROOT . '/src/views/pages/legal_preisliste.php';
});

// ─── Online-Beitrittserklärung ──────────────────────────
$router->get('/:communityid/beitreten/formular', function ($params) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($params['communityid'])]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    require ROOT . '/src/views/pages/beitreten_formular.php';
});

$router->post('/:communityid/beitreten/formular', function ($params) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($params['communityid'])]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    $communityId = $community['id'];
    DB::setCommunity($communityId);

    $required = ['first_name', 'last_name', 'email', 'address', 'zip', 'city'];
    foreach ($required as $rf) {
        if (empty(trim($_POST[$rf] ?? ''))) {
            $error = 'Bitte alle Pflichtfelder ausfüllen.';
            require ROOT . '/src/views/pages/beitreten_formular.php';
            return;
        }
    }

    $consentFields = [
        'zustimmung_mitgliedschaft', 'zustimmung_vollmacht', 'zustimmung_widerrufsfrist',
        'zustimmung_email_kommunikation', 'zustimmung_datenschutz', 'zustimmung_agb',
    ];
    foreach ($consentFields as $cf) {
        if (empty($_POST[$cf])) {
            $error = 'Bitte alle sechs rechtlichen Zustimmungen bestätigen.';
            require ROOT . '/src/views/pages/beitreten_formular.php';
            return;
        }
    }

    // Zählpunktnummer: normalizeZaehlpunkt() entfernt Leerzeichen VOR der Längenprüfung, damit
    // eine z.B. aus dem Kelag-Portal mit Leerzeichen kopierte Nummer nicht durch das frühere
    // Zählen der Leerzeichen als "Zeichen" fälschlich zu kurz erscheint bzw. (bei einem
    // längenbegrenzten Feld) am Ende abgeschnitten wird (Patrick, 03.09.2026: Mitglied fehlten
    // dadurch die letzten drei echten Ziffern). Gespeichert wird der normalisierte
    // (leerzeichenfreie) Wert, nicht die Roheingabe.
    $bezugZaehlpunkt = normalizeZaehlpunkt($_POST['bezug_zaehlpunkt'] ?? '');
    if (!empty($_POST['bezug_gewuenscht']) && $bezugZaehlpunkt !== '' && !validateZaehlpunkt($bezugZaehlpunkt)) {
        $error = 'Die Zählpunktnummer für den Bezug ist ungültig -- es werden genau 33 Zeichen (AT + 31 Buchstaben/Ziffern, ohne Leerzeichen) benötigt.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }
    $einspeisungZaehlpunkt = normalizeZaehlpunkt($_POST['einspeisung_zaehlpunkt'] ?? '');
    if (!empty($_POST['einspeisung_gewuenscht']) && $einspeisungZaehlpunkt !== '' && !validateZaehlpunkt($einspeisungZaehlpunkt)) {
        $error = 'Die Zählpunktnummer für die Einspeisung ist ungültig -- es werden genau 33 Zeichen (AT + 31 Buchstaben/Ziffern, ohne Leerzeichen) benötigt.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }

    // IBAN ist Pflicht (Patrick: ein Mitglied kam drauf, dass die Bankverbindung optional war --
    // ohne sie kann weder eine Einspeisevergütung ausbezahlt noch per SEPA-Lastschrift
    // eingezogen werden). Deshalb HIER, nicht nur im generischen $required-Block oben, damit
    // eine fehlende IBAN eine eigene, klare Fehlermeldung bekommt statt der generischen "Bitte
    // alle Pflichtfelder ausfüllen.". Kontoinhaber:in dagegen bewusst NICHT Pflicht (Patrick,
    // 03.09.2026): das Feld wird nur ausgefüllt, wenn der/die Kontoinhaber:in vom Namen der
    // Mitgliedsdaten abweicht -- beim Regelfall (Konto läuft auf den Namen des Mitglieds) bleibt
    // es leer. Der SEPA-Mandatstext fällt bei leerem Feld auf first_name/last_name zurück (siehe
    // 'Kontoinhaber:in: ' . texEscape($a['kontoinhaber'] ?: ...) in renderContractPdf()).
    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban === '') {
        $error = 'Bitte eine IBAN angeben -- ohne Bankverbindung können weder Einspeisevergütungen ausbezahlt noch Rechnungsbeträge per SEPA-Lastschrift eingezogen werden.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }
    if (!validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }

    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        $error = 'Bitte unterschreiben Sie im Unterschriftsfeld, bevor Sie absenden.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }
    $sepaSignature = $_POST['sepa_signature_image'] ?? '';
    if (!str_starts_with($sepaSignature, 'data:image/png;base64,')) {
        $error = 'Bitte unterschreiben Sie zusätzlich das SEPA-Lastschriftmandat.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }

    DB::execute(
        'INSERT INTO membership_applications (
            community_id, salutation, titel, first_name, last_name, geburtsdatum,
            address, zip, city, phone, email, stromlieferant,
            bezug_gewuenscht, bezug_zaehlpunkt, bezug_jahresverbrauch_kwh,
            einspeisung_gewuenscht, einspeisung_zaehlpunkt, einspeisung_kwp, einspeisung_geplante_kwh,
            speicher_status, speicher_kwh, andere_eeg, andere_eeg_name,
            iban, bic, kontoinhaber, konto_adresse,
            zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb,
            signature_image, signed_at, signer_ip, sepa_signature_image, sepa_signed_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), ?, ?, ?)',
        [
            $communityId,
            $_POST['salutation'] ?? null,
            trim($_POST['titel'] ?? '') ?: null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            ($_POST['geburtsdatum'] ?? '') ?: null,
            trim($_POST['address']),
            trim($_POST['zip']),
            trim($_POST['city']),
            trim($_POST['phone'] ?? '') ?: null,
            strtolower(trim($_POST['email'])),
            trim($_POST['stromlieferant'] ?? '') ?: null,
            isset($_POST['bezug_gewuenscht']) ? 'true' : 'false',
            $bezugZaehlpunkt ?: null,
            ($_POST['bezug_jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['bezug_jahresverbrauch_kwh']) : null,
            isset($_POST['einspeisung_gewuenscht']) ? 'true' : 'false',
            $einspeisungZaehlpunkt ?: null,
            ($_POST['einspeisung_kwp'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['einspeisung_kwp']) : null,
            ($_POST['einspeisung_geplante_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['einspeisung_geplante_kwh']) : null,
            ($_POST['speicher_status'] ?? '') ?: null,
            ($_POST['speicher_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['speicher_kwh']) : null,
            isset($_POST['andere_eeg']) ? 'true' : 'false',
            trim($_POST['andere_eeg_name'] ?? '') ?: null,
            $iban,
            trim($_POST['member_bic'] ?? '') ?: null,
            trim($_POST['kontoinhaber'] ?? '') ?: null,
            trim($_POST['konto_adresse'] ?? '') ?: null,
            'true', 'true', 'true', 'true', 'true', 'true',
            $signature,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $sepaSignature,
            date('Y-m-d H:i:s'),
        ]
    );
    $application = DB::fetchOne(
        'SELECT id FROM membership_applications WHERE community_id = ? AND email = ? ORDER BY created_at DESC LIMIT 1',
        [$communityId, strtolower(trim($_POST['email']))]
    );

    DB::execute(
        'INSERT INTO notifications (community_id, typ, titel, text, referenz_typ, referenz_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            'beitrittserklaerung',
            'Neue Beitrittserklärung: ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']),
            'Online-Beitrittserklärung wurde übermittelt und wartet auf Freigabe.',
            'membership_application',
            $application['id'],
        ]
    );

    header('Location: /' . strtolower($community['marktpartner_id']) . '/beitreten/danke');
    exit;
});

$router->get('/:communityid/beitreten/danke', function ($params) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($params['communityid'])]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    require ROOT . '/src/views/pages/beitreten_danke.php';
});

// ─── Live-Dashboard ─────────────────────────────────────
$router->get('/live', function () {
    require ROOT . '/src/views/pages/live.php';
});

$router->get('/api/live/:slug', function ($params) {
    header('Content-Type: application/json');
    $slug = $params['slug'];
    $community = DB::fetchOne('SELECT id FROM communities WHERE slug = ? AND active = true', [$slug]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'Nicht gefunden']); return; }

    DB::setCommunity($community['id']);

    // mirror_source_metering_point_id IS NULL: Demo-Zählpunkte (siehe migrate_20260906.sql) aus
    // JEDER Community-weiten Summe ausschließen -- ihre Live-Werte sind nur eine Live-Spiegelung
    // eines ANDEREN, bereits selbst enthaltenen Zählpunkts, würden also doppelt zählen (Patrick,
    // 06.09.2026: "es dürfen die Daten nicht doppelt in dem Energiefluss angezeigt werden").
    // Diese Seite ist zudem öffentlich (live.stromfueralle.at), eine verdoppelte Zahl wäre also
    // nicht nur intern falsch, sondern für jeden Besucher sichtbar falsch.
    $demoExclusion = 'metering_point_id NOT IN (SELECT id FROM metering_points WHERE mirror_source_metering_point_id IS NOT NULL)';

    // Aktuelle Leistung: pro Zählpunkt nur den jeweils NEUESTEN Messwert im Fenster nehmen,
    // nicht alle Zeilen aufsummieren (bei 5s-Sende-Intervall wären das bis zu ~24 Zeilen/Zähler
    // in 2 Minuten -> Werte um ein Vielfaches zu hoch, siehe CLAUDE.md/Sitzungslog).
    $agg = DB::fetchOne(
        "SELECT
            COALESCE(SUM(power_bezug_w), 0)        AS total_bezug_w,
            COALESCE(SUM(power_einspeisung_w), 0)  AS total_einspeisung_w,
            COUNT(*)                                AS active_meters
         FROM (
            SELECT DISTINCT ON (metering_point_id) power_bezug_w, power_einspeisung_w
            FROM esp_measurements
            WHERE community_id = ? AND time >= now() - INTERVAL '2 minutes' AND $demoExclusion
            ORDER BY metering_point_id, time DESC
         ) latest",
        [$community['id']]
    );

    // Energie heute: kumulative Zählerstände, daher Differenz statt zeilenweise SUMmen. Als
    // Tages-Basiswert bewusst die letzte Messung VOR heute nehmen (nicht die erste Messung DES
    // Tages) -- sonst würde z.B. ein Test/Neustart mit nur 1-2 Messwerten "heute" (z.B. beim
    // manuellen Testen per mosquitto_pub, siehe esp32-firmware-README) MAX=MIN liefern und 0
    // kWh anzeigen, obwohl der Zähler längst deutlich mehr eingespeist hat. Gibt es für einen
    // Zählpunkt gar keine Messung vor heute (allererste Messung überhaupt ist von heute), lässt
    // sich "heute" nicht sinnvoll von "insgesamt" trennen -- dann 0 statt einer falsch hohen
    // Zahl (kompletter historischer Zählerstand als "heute" ausgegeben).
    $today = DB::fetchOne(
        "WITH jetzt AS (
            SELECT DISTINCT ON (metering_point_id) metering_point_id, energy_einspeisung_wh AS jetzt_wh
            FROM esp_measurements
            WHERE community_id = ? AND $demoExclusion
            ORDER BY metering_point_id, time DESC
         ),
         basis AS (
            SELECT DISTINCT ON (metering_point_id) metering_point_id, energy_einspeisung_wh AS basis_wh
            FROM esp_measurements
            WHERE community_id = ? AND time < CURRENT_DATE AND $demoExclusion
            ORDER BY metering_point_id, time DESC
         )
         SELECT COALESCE(SUM(GREATEST(j.jetzt_wh - COALESCE(b.basis_wh, j.jetzt_wh), 0)), 0) AS today_wh
         FROM jetzt j LEFT JOIN basis b ON b.metering_point_id = j.metering_point_id",
        [$community['id'], $community['id']]
    );

    // Zeitreihe: pro Bucket/Zähler zuerst den Durchschnitt bilden, dann über die Zähler summieren
    // (sonst würde ein Zähler mit mehr Messwerten im Bucket das Ergebnis verzerren).
    $series = DB::fetchAll(
        "SELECT bucket,
            SUM(bezug_w)       AS bezug_w,
            SUM(einspeisung_w) AS einspeisung_w
         FROM (
            SELECT
                time_bucket('5 minutes', time) AS bucket,
                metering_point_id,
                AVG(power_bezug_w)             AS bezug_w,
                AVG(power_einspeisung_w)       AS einspeisung_w
            FROM esp_measurements
            WHERE community_id = ? AND time >= now() - INTERVAL '2 hours' AND $demoExclusion
            GROUP BY bucket, metering_point_id
         ) per_meter_bucket
         GROUP BY bucket ORDER BY bucket",
        [$community['id']]
    );

    $bezug = (int)($agg['total_bezug_w'] ?? 0);
    $einsp = (int)($agg['total_einspeisung_w'] ?? 0);
    $autarkie = $bezug > 0 ? min(100, round($einsp / $bezug * 100)) : 0;

    // Für den "nicht alle Zählpunkte online"-Hinweis: alle bekannten (schon mal gemeldeten)
    // Zählpunkte vs. die gerade aktiven -- siehe communityLivePower() (Patrick, 30.07.2026).
    $totalMeters = DB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM metering_points
         WHERE community_id = ? AND active = true AND meter_code IS NOT NULL AND esp_last_seen_at IS NOT NULL
           AND mirror_source_metering_point_id IS NULL",
        [$community['id']]
    );

    echo json_encode([
        'bezug_w'       => $bezug,
        'einspeisung_w' => $einsp,
        'autarkie_pct'  => $autarkie,
        'today_kwh'     => round(($today['today_wh'] ?? 0) / 1000, 2),
        'active_meters' => (int)($agg['active_meters'] ?? 0),
        'total_meters'  => (int)($totalMeters['cnt'] ?? 0),
        'series'        => $series,
    ]);
});

$router->get('/api/communities/search', function () {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $results = DB::fetchAll(
        'SELECT name, slug FROM communities WHERE active = true AND name ILIKE ? LIMIT 10', [$q]
    );
    echo json_encode($results);
});

// ─── Portal: Login ──────────────────────────────────────
$router->get('/portal/login', function () {
    if (Auth::check()) { header('Location: /portal/dashboard'); exit; }
    require ROOT . '/src/views/pages/login.php';
});

$router->post('/portal/login', function () {
    $email    = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    // Vor dem eigentlichen Passwort-Check gegen Brute-Force sperren (OWASP-Audit 13.08.2026) --
    // dieselbe generische Fehlermeldung wie bei falschem Passwort, damit die Sperre selbst
    // keine Rückschlüsse zulässt (z.B. ob die E-Mail überhaupt existiert).
    if (RateLimiter::isLoginBlocked($email)) {
        $error = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        require ROOT . '/src/views/pages/login.php';
        exit;
    }
    $user = Auth::checkPassword($email, $password);
    if (!$user) {
        RateLimiter::registerLoginFailure($email);
        $error = 'E-Mail oder Passwort falsch.';
        require ROOT . '/src/views/pages/login.php';
        exit;
    }
    RateLimiter::resetLoginAttempts($email);
    // Zweiter Faktor aktiv? Dann Session NOCH NICHT aufbauen, sondern Code abfragen.
    if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        $_SESSION['2fa_pending_user'] = $user['id'];
        header('Location: /portal/login/2fa');
        exit;
    }
    Auth::establishSession($user['id']);
    header('Location: /portal/dashboard');
    exit;
});

$router->get('/portal/login/2fa', function () {
    if (empty($_SESSION['2fa_pending_user'])) { header('Location: /portal/login'); exit; }
    require ROOT . '/src/views/pages/login_2fa.php';
});

$router->post('/portal/login/2fa', function () {
    $uid = $_SESSION['2fa_pending_user'] ?? null;
    if (!$uid) { header('Location: /portal/login'); exit; }
    // Brute-Force-Schutz für den 6-stelligen Code (OWASP-Audit 13.08.2026) -- ohne Sperre wären
    // die 1 Mio. Kombinationen bei automatisierten Anfragen realistisch durchprobierbar, sobald
    // ein Passwort einmal geleakt ist.
    if (RateLimiter::isTotpBlocked($uid)) {
        $error = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        require ROOT . '/src/views/pages/login_2fa.php';
        exit;
    }
    $u = DB::fetchOne('SELECT totp_secret FROM users WHERE id = ? AND active = true', [$uid]);
    if ($u && !empty($u['totp_secret']) && totpVerify(totpSecretFromStorage($u['totp_secret']), $_POST['code'] ?? '')) {
        RateLimiter::resetTotpAttempts($uid);
        unset($_SESSION['2fa_pending_user']);
        Auth::establishSession($uid);
        header('Location: /portal/dashboard');
        exit;
    }
    RateLimiter::registerTotpFailure($uid);
    $error = 'Code ungültig oder abgelaufen. Bitte den aktuellen 6-stelligen Code eingeben.';
    require ROOT . '/src/views/pages/login_2fa.php';
    exit;
});

$router->get('/portal/logout', function () {
    Auth::logout();
    header('Location: /portal/login');
    exit;
});

/**
 * Bestätigt das Pre-Launch-Hinweis-Popup (nur Mitglieder-Ansicht, siehe layouts/portal.php) --
 * setzt die Session-Flag und springt zur Seite zurück, auf der das Popup aufgerufen wurde
 * (Patrick, 30.07.2026: soll nicht jedes Mal auf das Dashboard umleiten). return_to wird gegen
 * einen /portal/-Präfix geprüft, damit daraus kein Open-Redirect auf fremde Ziele werden kann.
 */
$router->post('/portal/ack-prelaunch', function () {
    Auth::requireLogin();
    $_SESSION['prelaunch_ack'] = true;
    $returnTo = $_POST['return_to'] ?? '/portal/dashboard';
    if (!str_starts_with($returnTo, '/portal/')) { $returnTo = '/portal/dashboard'; }
    header('Location: ' . $returnTo);
    exit;
});

$router->get('/portal/forgot-password', function () {
    require ROOT . '/src/views/pages/forgot_password.php';
});

$router->post('/portal/forgot-password', function () {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $token = Auth::createResetToken($email);
    if ($token) {
        try {
            $user = DB::fetchOne('SELECT first_name FROM users WHERE email = ?', [$email]);
            $link = htmlspecialchars(passwordResetLink($token));
            $mail = renderMailTemplate('password_reset', array_merge([
                'vorname'     => htmlspecialchars($user['first_name'] ?? ''),
                'link'        => $link,
                'gueltigkeit' => 'Stunde',
            ], salutationVarsForEmail($email)),
                'Passwort zurücksetzen – Strom für alle',
                '<p>Liebes Mitglied,</p>'
                . '<p>über folgenden Link können Sie innerhalb der nächsten {{gueltigkeit}} ein neues Passwort vergeben:</p>'
                . '<p><a href="{{link}}">{{link}}</a></p>'
                . '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>'
            );
            Mailer::send($email, $mail['subject'], $mail['body']);
        } catch (\Throwable $e) {
            error_log('[forgot_password_mail] ' . $e->getMessage());
        }
    }
    // Bewusst immer dieselbe Meldung, unabhängig davon ob die E-Mail existiert oder der
    // Mailversand geklappt hat -- sonst ließe sich über die Fehlermeldung erraten, welche
    // Adressen als Login registriert sind.
    $success = 'Falls die E-Mail existiert, wurde ein Reset-Link versendet.';
    require ROOT . '/src/views/pages/forgot_password.php';
});

$router->get('/portal/reset-password', function () {
    $token = $_GET['token'] ?? '';
    $valid = $token !== '' && (bool)DB::fetchOne(
        'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > now()',
        [$token]
    );
    require ROOT . '/src/views/pages/reset_password.php';
});

$router->post('/portal/reset-password', function () {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $valid = $token !== '' && (bool)DB::fetchOne(
        'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > now()',
        [$token]
    );
    if (!$valid) {
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    if (strlen($password) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen haben.';
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    if ($password !== $password2) {
        $error = 'Die beiden Passwörter stimmen nicht überein.';
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    if (isPasswordBreached($password)) {
        $error = 'Dieses Passwort ist in bekannten Datenlecks aufgetaucht und ist deshalb unsicher. Bitte ein anderes Passwort wählen.';
        require ROOT . '/src/views/pages/reset_password.php';
        return;
    }
    Auth::resetPassword($token, $password);
    header('Location: /portal/login?success=password_reset');
    exit;
});

// ─── Portal: Dashboard ──────────────────────────────────
$router->get('/portal/dashboard', function () {
    Auth::requireLogin();
    if (Auth::isManager()) {
        // manager_dashboard.php braucht eine konkrete aktive EEG (DB::setCommunity() dort ganz
        // oben) -- ein platform_admin ohne community-gebundene Rolle (community_id NULL, siehe
        // Auth::isPlatformAdmin()) hat aber keine, das würde sonst mit einem Fatal Error
        // abstürzen (Patrick, 06.09.2026, per Screenshot). In dem Fall auf die
        // EEG-unabhängige Plattform-Übersicht ausweichen.
        if (!Auth::activeCommunityId()) {
            header('Location: /admin');
            exit;
        }
        require ROOT . '/src/views/pages/manager_dashboard.php';
    } else {
        require ROOT . '/src/views/pages/member_dashboard.php';
    }
});

/**
 * Leichtgewichtiger JSON-Endpunkt fürs Mitglieder-Dashboard (Patrick, 30.07.2026): wird per
 * Fetch alle 5s abgefragt, damit nur die "Aktuelle Leistung"-Kachel aktualisiert wird -- kein
 * voller Seiten-Reload für Werte, von denen man weiß, dass sie sich laufend ändern.
 */
$router->get('/portal/api/current-power', function () {
    Auth::requireLogin();
    header('Content-Type: application/json');
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { echo json_encode(['net_w' => null]); return; }
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { echo json_encode(['net_w' => null]); return; }
    $mpIds = array_column(
        DB::fetchAll('SELECT id FROM metering_points WHERE member_id = ? AND active = true', [$memberId]),
        'id'
    );
    echo json_encode(['net_w' => memberCurrentNetPowerW($communityId, $mpIds)]);
});

/**
 * Liefert dieselbe Zahl wie die "Energiefluss (Live)"-Grafik (communityLivePower(), siehe
 * partials/energy_flow.php), per Fetch alle 5s abgefragt statt bei jedem Update die ganze
 * Seite neu zu laden. Bewusst für JEDEN eingeloggten Portal-Nutzer offen (keine
 * Auth::requireRole()-Einschränkung mehr) -- seit 13.08.2026 auch im Kundenportal eingebunden,
 * und die zurückgegebenen Werte sind ohnehin nur ein Community-weiter Summenwert ohne
 * Rückschluss auf einzelne Mitglieder.
 */
$router->get('/portal/api/live-power', function () {
    Auth::requireLogin();
    header('Content-Type: application/json');
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { echo json_encode(['bezug_w' => 0, 'einsp_w' => 0, 'active_meters' => 0, 'total_meters' => 0]); return; }
    DB::setCommunity($communityId);
    echo json_encode(communityLivePower($communityId));
});

$router->post('/portal/switch-role', function () {
    Auth::requireLogin();
    $communityId = $_POST['community_id'] ?? '';
    $role        = $_POST['role'] ?? '';
    $memberId    = !empty($_POST['member_id']) ? (string)$_POST['member_id'] : null;
    Auth::switchRole($communityId, $role, $memberId);
    header('Location: /portal/dashboard');
    exit;
});

// ─── Portal: Rechnungen (Mitglied) ──────────────────────
$router->get('/portal/invoices', function () {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { http_response_code(404); echo 'Mitglied nicht gefunden'; return; }
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = ? ORDER BY i.created_at DESC',
        [$memberId]
    );
    require ROOT . '/src/views/pages/invoices.php';
});

/**
 * Lädt eine Rechnung mit allen Feldern, die sowohl für den Autorisierungs-Check als auch für
 * den PDF-Aufbau gebraucht werden. Gibt null zurück, wenn die Rechnung nicht existiert.
 * Geteilt zwischen /portal/invoices/:id/pdf (Browser-Session) und /api/v1/invoices/:id/pdf
 * (App-Bearer-Token, seit 30.08.2026) -- beide Routen prüfen die Berechtigung SELBST (je nach
 * Identitätsquelle unterschiedlich), diese Funktion liefert nur die Rohdaten.
 */
function loadInvoiceForPdf(string $invoiceId): ?array
{
    return DB::fetchOne(
        'SELECT i.*, m.first_name, m.last_name, m.address, m.zip, m.city, m.invoice_uid,
                m.salutation, m.titel, m.company_name, m.invoice_name,
                m.kundennummer, m.mandatsreferenz, m.member_iban,
                m.community_id AS member_community_id, m.user_id AS member_user_id,
                br.quartal, br.period_from, br.period_to,
                c.name AS eeg_name, c.address AS eeg_address, c.iban AS eeg_iban, c.bic AS eeg_bic,
                c.zvr_number AS eeg_zvr, c.contact_phone AS eeg_contact_phone,
                c.contact_email AS eeg_contact_email, c.bank_name AS eeg_bank_name,
                c.account_holder AS eeg_account_holder, c.creditor_id AS eeg_creditor_id,
                tc.bezug_ct_kwh, tc.einspeisung_ct_kwh, tc.mitgliedsbeitrag_eur,
                tx.uid_number AS eeg_uid_number, tx.tax_model AS eeg_tax_model,
                tx.tax_rate_percent AS eeg_tax_rate
         FROM invoices i
         JOIN members m ON m.id = i.member_id
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN communities c ON c.id = br.community_id
         LEFT JOIN tariff_config tc ON tc.community_id = c.id AND tc.valid_from <= br.period_from
         LEFT JOIN LATERAL (
             SELECT uid_number, tax_model, tax_rate_percent FROM tax_config
             WHERE community_id = c.id AND valid_from <= br.period_from
             ORDER BY valid_from DESC LIMIT 1
         ) tx ON true
         WHERE i.id = ?
         ORDER BY tc.valid_from DESC',
        [$invoiceId]
    );
}

/** Rendert eine per loadInvoiceForPdf() geladene Rechnung als PDF-Response (LaTeX). */
function renderInvoicePdf(array $invoice): void
{
    $items = DB::fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY type', [$invoice['id']]);
    // Bezug/Einspeisung können mehrere Positionen haben (eine pro Zählpunkt) -- als Listen
    // sammeln, damit die Rechnung pro Zählpunkt eine eigene Zeile zeigen kann.
    $bezugItems = []; $einspeisungItems = []; $beitragItem = null; $extraItems = [];
    foreach ($items as $it) {
        if ($it['type'] === 'bezug')            $bezugItems[]       = $it;
        if ($it['type'] === 'einspeisung')      $einspeisungItems[] = $it;
        if ($it['type'] === 'mitgliedsbeitrag') $beitragItem        = $it;
        if ($it['type'] === 'manuell')          $extraItems[]       = $it;
    }
    // Aggregat-Summen für die Fallback-Einzeiler (falls die RAW-Liste leer ist).
    $bezugKwh    = array_sum(array_map(fn($i) => (float)$i['kwh'], $bezugItems));
    $bezugBetrag = array_sum(array_map(fn($i) => (float)$i['amount_eur'], $bezugItems));
    $einspKwh    = array_sum(array_map(fn($i) => (float)$i['kwh'], $einspeisungItems));
    $einspBetrag = array_sum(array_map(fn($i) => (float)$i['amount_eur'], $einspeisungItems));
    $bezugTarif  = $bezugItems[0]['rate_ct_kwh'] ?? null;
    $einspTarif  = $einspeisungItems[0]['rate_ct_kwh'] ?? null;
    // Zählpunkt-Fallback (nur ein Zählpunkt): direkt als zweite Zeile im Einzeiler anzeigen.
    $bezugZp     = count($bezugItems) === 1 ? trim((string)($bezugItems[0]['zaehlpunkt_nr'] ?? '')) : '';
    $einspZp     = count($einspeisungItems) === 1 ? trim((string)($einspeisungItems[0]['zaehlpunkt_nr'] ?? '')) : '';

    // Steuer: Tarife sind netto. Kleinunternehmer -> keine USt (netto = brutto). Standard ->
    // USt-Satz auf den Netto-Saldo aufschlagen; brutto ist der real zu zahlende/einzuziehende
    // Betrag. taxBreakdown() zentralisiert die Rechnung (getestet, auch von SEPA/Vorabinfo genutzt).
    $tax = taxBreakdown((float)$invoice['saldo_eur'], $invoice['eeg_tax_model'] ?? null, $invoice['eeg_tax_rate'] ?? null);
    // RAW_: LaTeX-Befehle direkt übergeben — service.js darf diese NICHT escapen.
    // 4-Spalten-Tabelle (Position / Menge / Tarif / Betrag), Steuerzeile spannt alle 4 Spalten:
    if ($tax['model'] === 'standard') {
        $ustFmt = number_format($tax['ust'], 2, ',', '.');
        $nettoFmt = number_format($tax['netto'], 2, ',', '.');
        $satzFmt = rtrim(rtrim(number_format($tax['rate'], 2, ',', '.'), '0'), ',');
        $steuerZeile = '\\multicolumn{3}{r}{\\footnotesize Netto} & \\footnotesize EUR ' . $nettoFmt . ' \\\\'
            . '\\multicolumn{3}{r}{\\footnotesize zzgl.\\,' . $satzFmt . '\\,\\% USt} & \\footnotesize EUR ' . $ustFmt . ' \\\\';
        $steuerText  = 'Im Rechnungsbetrag sind ' . $satzFmt . '\\,\\% Umsatzsteuer (EUR ' . $ustFmt . ') enthalten.';
    } else {
        $steuerZeile = '\\multicolumn{4}{l}{\\footnotesize\\color{midgray}Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung): keine Umsatzsteuer.} \\\\';
        // Paragraph am Seitenende:
        $steuerText  = 'Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';
    }

    // Anzeigename: abweichender Rechnungsname > Firma > Titel + Vor-/Nachname
    $anzeigeName = ($invoice['invoice_name'] ?? '')
        ?: (($invoice['company_name'] ?? '')
        ?: trim((!empty($invoice['titel']) ? $invoice['titel'] . ' ' : '')
            . $invoice['first_name'] . ' ' . $invoice['last_name']));

    // EEG-Adresse für den Footer aufteilen ("Straße 1, 9560 Ort" -> zwei Zeilen)
    $eegAdrTeile = array_map('trim', explode(',', $invoice['eeg_address'] ?? '', 2));

    // Zahlungstext (RAW = wird nicht escaped, texEscape() für dynamische Teile!)
    // Der real zu zahlende/einzuziehende Betrag ist der Brutto-Betrag (bei Kleinunternehmer
    // identisch mit netto, bei Standard inkl. USt).
    $saldoVal  = (float)$tax['brutto'];
    $betragFmt = number_format(abs($saldoVal), 2, ',', '.');
    $faellig   = date('d.m.Y', strtotime($invoice['created_at'] . ' +14 days'));
    $ibanEnd   = !empty($invoice['member_iban'])
        ? substr(preg_replace('/\s+/', '', $invoice['member_iban']), -4) : null;

    $summeLabel = '';
    if ($saldoVal < 0) {
        $zahlungText = 'Ihr Guthaben von \\textbf{EUR ' . $betragFmt . '} wird auf Ihr bei uns hinterlegtes Konto'
            . ($ibanEnd ? ' (IBAN mit der Endung \\textbf{' . $ibanEnd . '})' : '')
            . ' überwiesen. Sie müssen nichts weiter veranlassen.';
        $summeLabel = 'Ihr Guthaben';
    } elseif (!empty($invoice['mandatsreferenz'])) {
        $zahlungText = 'Der Rechnungsbetrag von \\textbf{EUR ' . $betragFmt . '} wird gemäß SEPA-Lastschriftmandat'
            . ' (Mandatsreferenz \\textbf{' . texEscape($invoice['mandatsreferenz']) . '}'
            . (!empty($invoice['eeg_creditor_id']) ? ', Gläubiger-ID \\textbf{' . texEscape($invoice['eeg_creditor_id']) . '}' : '')
            . ') am \\textbf{' . $faellig . '}'
            . ($ibanEnd ? ' von Ihrem Konto mit der Endung \\textbf{' . $ibanEnd . '}' : ' von Ihrem Konto')
            . ' eingezogen. Sie müssen nichts weiter veranlassen.'
            . ' Diese Rechnung gilt zugleich als Vorabankündigung (Pre-Notification)'
            . ' im Sinne des SEPA-Lastschriftverfahrens.';
    } else {
        $zahlungText = ''; // Vorlage zeigt dann automatisch die Überweisungsbitte
    }

    streamLatexPdf('rechnung', [
        'EEG_NAME'              => $invoice['eeg_name'],
        'EEG_ADRESSE'           => $invoice['eeg_address'] ?? '',
        'EEG_STRASSE'           => $eegAdrTeile[0] ?? '',
        'EEG_PLZ_ORT'           => $eegAdrTeile[1] ?? '',
        'EEG_UID'               => $invoice['eeg_uid_number'] ?? '',
        'EEG_ZVR'               => $invoice['eeg_zvr'] ?? '',
        'EEG_OBMANN_TELEFON'    => $invoice['eeg_contact_phone'] ?? '',
        'EEG_KONTAKT_EMAIL'     => $invoice['eeg_contact_email'] ?? '',
        'EEG_BANKNAME'          => $invoice['eeg_bank_name'] ?? '',
        'EEG_KONTOINHABER'      => $invoice['eeg_account_holder'] ?? '',
        'MITGLIED_ANREDE'       => $invoice['salutation'] ?? '',
        'MITGLIED_NAME'         => $anzeigeName,
        'MITGLIED_ADRESSE'      => $invoice['address'] . ', ' . $invoice['zip'] . ' ' . $invoice['city'],
        'MITGLIED_STRASSE'      => $invoice['address'],
        'MITGLIED_PLZ_ORT'      => trim($invoice['zip'] . ' ' . $invoice['city']),
        'MITGLIED_UID'          => $invoice['invoice_uid'] ?? '',
        'KUNDENNUMMER'          => $invoice['kundennummer'] !== null ? (string)$invoice['kundennummer'] : '',
        'MITGLIED_SEPA_MANDATSREFERENZ' => $invoice['mandatsreferenz'] ?? '',
        'RECHNUNGSNUMMER'       => $invoice['rechnungsnummer'],
        'RECHNUNGSDATUM'        => date('d.m.Y', strtotime($invoice['created_at'])),
        'ABRECHNUNGSZEITRAUM'   => date('d.m.Y', strtotime($invoice['period_from'])) . ' -- ' . date('d.m.Y', strtotime($invoice['period_to'])),
        'BEZUG_KWH'             => number_format($bezugKwh, 2, ',', '.'),
        'BEZUG_TARIF'           => $bezugTarif !== null ? number_format((float)$bezugTarif, 4, ',', '.') : '0,0000',
        'BEZUG_BETRAG'          => number_format($bezugBetrag, 2, ',', '.'),
        'BEZUG_ZAEHLPUNKT'      => $bezugZp,
        'EINSPEISUNG_KWH'       => number_format($einspKwh, 2, ',', '.'),
        'EINSPEISUNG_TARIF'     => $einspTarif !== null ? number_format((float)$einspTarif, 4, ',', '.') : '0,0000',
        'EINSPEISUNG_BETRAG'    => number_format(abs($einspBetrag), 2, ',', '.'),
        'EINSPEISUNG_ZAEHLPUNKT' => $einspZp,
        'RAW_BEZUG_POSITIONEN_LISTE'       => rechnungPositionenLatex($bezugItems, 'Energiebezug aus der Gemeinschaft', false),
        'RAW_EINSPEISUNG_POSITIONEN_LISTE' => rechnungPositionenLatex($einspeisungItems, 'Einspeisevergütung (Gutschrift)', true),
        'MITGLIEDSBEITRAG'      => $beitragItem ? number_format((float)$beitragItem['amount_eur'], 2, ',', '.') : '0,00',
        'SUMME_NETTO'           => number_format($tax['netto'], 2, ',', '.'),
        'SUMME_BRUTTO'          => number_format($tax['brutto'], 2, ',', '.'),
        'RAW_STEUER_ZEILE'      => $steuerZeile,
        'RAW_STEUER_TEXT'       => $steuerText,
        'RAW_ZUSATZPOSITIONEN_LISTE' => rechnungExtraItemsLatex($extraItems),
        'RAW_ZAHLUNG_TEXT'      => $zahlungText,
        'RAW_SUMME_LABEL'       => $summeLabel,
        'IBAN'                  => $invoice['eeg_iban'] ?? '--',
        'BIC'                   => $invoice['eeg_bic'] ?? '--',
        'ZAHLUNGSZIEL'          => $faellig,
    ], $invoice['rechnungsnummer'] . '.pdf', communityLogoAsset($invoice['member_community_id']));
}

$router->get('/portal/invoices/:id/pdf', function ($params) {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    if ($communityId) DB::setCommunity($communityId);

    $invoice = loadInvoiceForPdf($params['id']);
    if (!$invoice) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }

    // IDOR-Schutz: nur das Mitglied selbst (Rechnung gehört zu seinem User-Login) oder ein
    // Manager/Platform-Admin der jeweiligen Community darf die PDF abrufen -- ohne diese
    // Prüfung konnte jeder eingeloggte Nutzer mit bekannter/erratener Invoice-UUID fremde
    // Rechnungen abrufen.
    $isOwnInvoice = $invoice['member_user_id'] !== null && $invoice['member_user_id'] === Auth::userId();
    $isManagerOfCommunity = Auth::isManager() && Auth::activeCommunityId() === $invoice['member_community_id'];
    if (!Auth::isPlatformAdmin() && !$isOwnInvoice && !$isManagerOfCommunity) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }

    renderInvoicePdf($invoice);
});

/**
 * App-Pendant zu /portal/invoices/:id/pdf, nur mit Bearer-Token statt Browser-Session als
 * Identitätsquelle -- der IDOR-Schutz ist hier sogar einfacher: das Token trägt bereits genau
 * EINE member_id (siehe AppApiAuth), es muss also nur noch mit der Rechnung abgeglichen werden,
 * kein zusätzlicher Manager/Platform-Admin-Sonderfall wie im Web (die App ist reine
 * Mitglieder-Selbstbedienung).
 */
$router->get('/api/v1/invoices/:id/pdf', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    DB::setCommunity($ctx['community_id']);

    $invoice = loadInvoiceForPdf($params['id']);
    if (!$invoice) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Rechnung nicht gefunden.']);
        return;
    }
    if ($invoice['member_id'] !== $ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Zugriff.']);
        return;
    }

    renderInvoicePdf($invoice);
});

// ─── Portal: Mitglied-Selbstbedienung (eigene Verträge/Dateien) ─────────
// Analog zu den Manager-Routen unter /portal/members/:id/contract/*, aber ohne Manager-Rolle
// und ohne :id -- löst den Mitgliedsdatensatz direkt aus der eingeloggten Session auf
// (currentMemberFull()). Anders als die Manager-Ansicht wird der Status/Zeitstempel beim
// bloßen Ansehen NICHT verändert -- das bleibt allein den Manager-Aktionen vorbehalten.
$router->get('/portal/my/contract/bezug', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    if (!contractsEnabled($member['community_id'])) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$member['id'], 'consumer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Bezugs-Zählpunkt registriert.'; return; }

    $tariff = contractTariff($member['community_id'], $member['contract_bezug_generated_at'] ?? null);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = communityManagerSignature($member['community_id']);
    $memberSig = memberSignatureAsset($member['contract_bezug_customer_signature'] ?? null);
    $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature, $memberSig);
    streamLatexPdf('bezugsvereinbarung', $vars, 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
});

$router->get('/portal/my/contract/einspeisung', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    if (!contractsEnabled($member['community_id'])) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$member['id'], 'producer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Einspeise-Zählpunkt registriert.'; return; }

    $tariff = contractTariff($member['community_id'], $member['contract_einspeisung_generated_at'] ?? null);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = communityManagerSignature($member['community_id']);
    $memberSig = memberSignatureAsset($member['contract_einspeisung_customer_signature'] ?? null);
    $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature, $memberSig);
    streamLatexPdf('einspeisevereinbarung', $vars, 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
});

/** Menschenlesbare Bezeichnung des Vertragstyps für Benachrichtigungen/Meldungen. */
function contractTypeLabel(string $type): string
{
    return $type === 'einspeisung' ? 'Einspeisevereinbarung' : 'Bezugsvereinbarung';
}

/**
 * Digitale Unterschrift durch das Mitglied: Statt (wie bisher) den Vertrag nur per Post
 * unterschrieben zurückzuschicken, unterschreibt das Mitglied hier im Portal per Maus/Finger.
 * Nur möglich solange der Vertrag "created" (= versendet, aber noch nicht unterschrieben) ist.
 */
$router->get('/portal/my/contract/:type/sign', function ($params) {
    Auth::requireLogin();
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) { http_response_code(404); return; }
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $status = $member['contract_' . $type . '_status'] ?? 'none';
    if ($status === 'signed') {
        header('Location: /portal/my/documents?info=' . urlencode(contractTypeLabel($type) . ' wurde bereits unterschrieben.'));
        exit;
    }
    if ($status !== 'created') {
        header('Location: /portal/my/documents?error=' . urlencode(contractTypeLabel($type) . ' wurde noch nicht zur Unterschrift versendet.'));
        exit;
    }

    require ROOT . '/src/views/pages/contract_sign.php';
});

$router->post('/portal/my/contract/:type/sign', function ($params) {
    Auth::requireLogin();
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) { http_response_code(404); return; }
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $status = $member['contract_' . $type . '_status'] ?? 'none';
    if ($status !== 'created') {
        header('Location: /portal/my/documents?error=' . urlencode('Vertrag kann in diesem Status nicht unterschrieben werden.'));
        exit;
    }
    if (empty($_POST['zustimmung'])) {
        header('Location: /portal/my/contract/' . $type . '/sign?error=' . urlencode('Bitte bestätigen Sie die Zustimmung, bevor Sie unterschreiben.'));
        exit;
    }
    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        header('Location: /portal/my/contract/' . $type . '/sign?error=' . urlencode('Bitte unterschreiben Sie im Unterschriftsfeld, bevor Sie absenden.'));
        exit;
    }

    $communityId = $member['community_id'];
    DB::setCommunity($communityId);
    DB::execute(
        "UPDATE members SET
            contract_{$type}_status = 'signed',
            contract_{$type}_customer_signature = ?,
            contract_{$type}_signed_at = now(),
            contract_{$type}_signer_ip = ?
         WHERE id = ?",
        [$signature, $_SERVER['REMOTE_ADDR'] ?? null, $member['id']]
    );
    // Interne Benachrichtigung an die Manager der EEG (analog zur Beitrittserklärung-
    // Benachrichtigung oben) -- damit die Rückmeldung "Vertrag wurde unterschrieben" ankommt,
    // ohne dass der Manager aktiv nachfragen muss.
    DB::execute(
        'INSERT INTO notifications (community_id, typ, titel, text, referenz_typ, referenz_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            'vertrag_unterschrieben',
            'Vertrag digital unterschrieben: ' . $member['first_name'] . ' ' . $member['last_name'] . ' (' . contractTypeLabel($type) . ')',
            'Die/der Netzbenutzer:in hat die ' . contractTypeLabel($type) . ' im Portal digital unterschrieben. '
            . 'Der Vertrag ist ab sofort gültig und wird automatisch sicher archiviert.',
            'member',
            $member['id'],
        ]
    );
    header('Location: /portal/my/documents?success=' . urlencode(contractTypeLabel($type) . ' wurde erfolgreich unterschrieben und ist jetzt gültig.'));
    exit;
});

/**
 * Übersichtsseite: eigene Verträge (Links zu den beiden Routen oben, je nach vorhandenen
 * Zählpunkten), eigene hochgeladene Dateien (deckt auch vom Manager hochgeladene
 * Beitrittserklärungen/Ausweis-Scans ab) und -- falls online beigetreten -- das
 * Beitrittsformular.
 */
/**
 * Viertelstündliche Werte für EINEN Tag, summiert über eine Menge von Zählpunkten eines
 * Mitglieds -- gemeinsame Grundlage für zwei spiegelbildliche Diagramme:
 *  - $energyDirection='CONSUMPTION' (Default): Verbrauch vs. gemeinschaftliche Eigendeckung,
 *    für Bezugs-/Prosumer-Zählpunkte -- /portal/my/verbrauch, GET /api/v1/consumption/interval.
 *    kwh_gemeinschaft ist hier der Anteil von kwh_messung, der aus der EEG gedeckt wurde (siehe
 *    Spaltenkommentar in database/migrate_20260904.sql) -- die Differenz kam aus dem
 *    öffentlichen Netz. Patrick, 03.09.2026: "wie viel sie viertelstündlich verbrauchen und wie
 *    viel davon energiegemeinschaftlich genutzt wird [...] damit wissen die Mitglieder auch, wie
 *    sie ihren Verbrauch optimieren können".
 *  - $energyDirection='GENERATION': eigene Einspeisung, für Einspeise-/Prosumer-Zählpunkte --
 *    /portal/my/einspeisung, GET /api/v1/production/interval. Patrick, 06.09.2026: "warum haben
 *    die Einspeiser nicht die Möglichkeit, ihre eingespeiste Leistung in einem Diagramm
 *    einzusehen?". WICHTIG: bei GENERATION ist kwh_messung die GESAMTE gemeinschaftliche
 *    Erzeugung (community-weit, NICHT mitgliedsspezifisch) -- die fürs eigene Diagramm relevante
 *    Größe ist deshalb kwh_gemeinschaft ("Erzeugung lt. Messung entsprechend dem
 *    Teilnahmefaktor und EC-ID", siehe migrate_20260904.sql) = die eigene, individuell
 *    zugerechnete Einspeisung. Aufrufer für GENERATION verwenden deshalb bewusst nur
 *    'gemeinschaft_w' aus der Rückgabe, nicht 'verbrauch_w'.
 *
 * Rückgabe in DURCHSCHNITTS-WATT je Viertelstunde (kWh * 4000), nicht kWh, damit die Werte
 * direkt mit der Live-Leistungsanzeige (current-power, ebenfalls in W) vergleichbar sind.
 */
function memberIntervalDayData(string $communityId, array $meteringPointIds, string $date, string $energyDirection = 'CONSUMPTION'): array
{
    if (!$meteringPointIds) {
        return ['intervals' => [], 'total_messung_kwh' => 0.0, 'total_gemeinschaft_kwh' => 0.0, 'has_data' => false];
    }
    DB::setCommunity($communityId);
    $placeholders = implode(',', array_fill(0, count($meteringPointIds), '?'));
    $rows = DB::fetchAll(
        "SELECT time, SUM(kwh_messung) AS kwh_messung, SUM(kwh_gemeinschaft) AS kwh_gemeinschaft
         FROM eda_interval_data
         WHERE community_id = ? AND metering_point_id IN ($placeholders)
           AND energy_direction = ? AND time >= ?::date AND time < ?::date + INTERVAL '1 day'
         GROUP BY time ORDER BY time",
        array_merge([$communityId], $meteringPointIds, [$energyDirection, $date, $date])
    );
    $byTime = [];
    foreach ($rows as $r) {
        $byTime[date('H:i', strtotime($r['time']))] = [
            'messung_w'     => round((float)$r['kwh_messung'] * 4000),
            'gemeinschaft_w' => round((float)$r['kwh_gemeinschaft'] * 4000),
        ];
    }
    $intervals = [];
    $totalMessung = 0.0;
    $totalGemeinschaft = 0.0;
    for ($m = 0; $m < 24 * 60; $m += 15) {
        $label = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $v = $byTime[$label] ?? null;
        $intervals[] = ['zeit' => $label, 'verbrauch_w' => $v['messung_w'] ?? null, 'gemeinschaft_w' => $v['gemeinschaft_w'] ?? null];
    }
    foreach ($rows as $r) {
        $totalMessung += (float)$r['kwh_messung'];
        $totalGemeinschaft += (float)$r['kwh_gemeinschaft'];
    }
    return [
        'intervals' => $intervals,
        'total_messung_kwh' => round($totalMessung, 3),
        'total_gemeinschaft_kwh' => round($totalGemeinschaft, 3),
        'has_data' => count($rows) > 0,
    ];
}

$router->get('/portal/my/verbrauch', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('consumer', 'prosumer')", [$member['id']]),
        'id'
    );

    // Ohne Datumsangabe: den letzten Tag mit tatsächlich vorhandenen Daten vorschlagen (heute hat
    // fast nie schon Werte, siehe Lücken-Anzeige unter /portal/eda/upload) statt stur "heute" zu
    // zeigen, wo dann ohnehin nichts zu sehen wäre.
    $date = $_GET['date'] ?? null;
    if (!$date) {
        $latest = $mpIds ? DB::fetchOne(
            "SELECT MAX(time) AS letzter FROM eda_interval_data WHERE community_id = ? AND metering_point_id IN ("
            . implode(',', array_fill(0, count($mpIds), '?')) . ")",
            array_merge([$member['community_id']], $mpIds)
        ) : null;
        $date = !empty($latest['letzter']) ? date('Y-m-d', strtotime($latest['letzter'])) : date('Y-m-d');
    }

    $data = memberIntervalDayData($member['community_id'], $mpIds, $date);
    require ROOT . '/src/views/pages/my_verbrauch.php';
});

/**
 * Spiegelbild von /portal/my/verbrauch, für Einspeise-/Prosumer-Zählpunkte -- Patrick,
 * 06.09.2026: "warum haben die Einspeiser nicht die Möglichkeit, ihre eingespeiste Leistung in
 * einem Diagramm einzusehen?". Nutzt dieselbe memberIntervalDayData(), aber mit
 * energy_direction='GENERATION' -- siehe dortiger Kommentar zur kwh_gemeinschaft-Semantik bei
 * Erzeugung.
 */
$router->get('/portal/my/einspeisung', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('producer', 'prosumer')", [$member['id']]),
        'id'
    );

    $date = $_GET['date'] ?? null;
    if (!$date) {
        $latest = $mpIds ? DB::fetchOne(
            "SELECT MAX(time) AS letzter FROM eda_interval_data WHERE community_id = ? AND metering_point_id IN ("
            . implode(',', array_fill(0, count($mpIds), '?')) . ") AND energy_direction = 'GENERATION'",
            array_merge([$member['community_id']], $mpIds)
        ) : null;
        $date = !empty($latest['letzter']) ? date('Y-m-d', strtotime($latest['letzter'])) : date('Y-m-d');
    }

    $data = memberIntervalDayData($member['community_id'], $mpIds, $date, 'GENERATION');
    require ROOT . '/src/views/pages/my_einspeisung.php';
});

$router->get('/portal/my/documents', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true', [$member['id']]);
    $hasConsumer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'consumer'));
    $hasProducer = !empty(array_filter($metering_points, fn($mp) => $mp['type'] === 'producer'));
    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$member['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$member['id'], $member['community_id']]);
    if (!empty($_GET['success'])) { $success = $_GET['success']; }
    if (!empty($_GET['error']))   { $error = $_GET['error']; }
    if (!empty($_GET['info']))    { $info = $_GET['info']; }
    require ROOT . '/src/views/pages/my_documents.php';
});

/**
 * Sammelt die Jahresübersicht eines Mitglieds: alle Rechnungen eines Kalenderjahres (aus dem
 * Quartals-Präfix des Abrechnungslaufs), je mit Netto/USt/Brutto (zentrale taxBreakdown()) und
 * Zahlungsstatus, plus Jahressummen und die Liste der Jahre mit Rechnungen. Gemeinsame Datenbasis
 * für die Manager- und die Mitglied-Ansicht.
 */
function memberJahresUebersicht(string $memberId, string $communityId, ?int $jahr): array
{
    $member    = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$memberId, $communityId]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $jahre = array_map(fn($r) => (int)$r['jahr'], DB::fetchAll(
        "SELECT DISTINCT substring(br.quartal from 1 for 4) AS jahr
           FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
          WHERE i.member_id = ? AND i.community_id = ?
          ORDER BY jahr DESC",
        [$memberId, $communityId]
    ));
    if ($jahr === null) { $jahr = $jahre[0] ?? (int)date('Y'); }
    $rows = DB::fetchAll(
        "SELECT i.*, br.quartal, br.period_from, br.status AS run_status,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           LEFT JOIN LATERAL (
               SELECT tax_model, tax_rate_percent FROM tax_config
               WHERE community_id = i.community_id AND valid_from <= br.period_from
               ORDER BY valid_from DESC LIMIT 1
           ) tx ON true
          WHERE i.member_id = ? AND i.community_id = ? AND substring(br.quartal from 1 for 4) = ?
          ORDER BY br.quartal",
        [$memberId, $communityId, (string)$jahr]
    );
    $sum = ['netto' => 0.0, 'ust' => 0.0, 'brutto' => 0.0, 'gebuehr' => 0.0];
    foreach ($rows as &$r) {
        $tb = taxBreakdown((float)$r['saldo_eur'], $r['eeg_tax_model'] ?? null, $r['eeg_tax_rate'] ?? null);
        $r['netto'] = $tb['netto']; $r['ust'] = $tb['ust']; $r['brutto'] = $tb['brutto'];
        $sum['netto'] += $tb['netto']; $sum['ust'] += $tb['ust']; $sum['brutto'] += $tb['brutto'];
        $sum['gebuehr'] += (float)($r['mahn_gebuehr_summe_eur'] ?? 0);
    }
    unset($r);
    return compact('member', 'community', 'jahre', 'jahr', 'rows', 'sum');
}

$router->get('/portal/members/:id/jahresuebersicht/:jahr', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $data = memberJahresUebersicht($member['id'], $member['community_id'], (int)$params['jahr']);
    $backUrl = '/portal/members/' . $member['id'];
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});
$router->get('/portal/members/:id/jahresuebersicht', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $data = memberJahresUebersicht($member['id'], $member['community_id'], null);
    $backUrl = '/portal/members/' . $member['id'];
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});

$router->get('/portal/my/jahresuebersicht/:jahr', function ($params) {
    Auth::requireLogin();
    $me = currentMemberFull();
    if (!$me) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $data = memberJahresUebersicht($me['id'], $me['community_id'], (int)$params['jahr']);
    $backUrl = '/portal/my/documents';
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});
$router->get('/portal/my/jahresuebersicht', function () {
    Auth::requireLogin();
    $me = currentMemberFull();
    if (!$me) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $data = memberJahresUebersicht($me['id'], $me['community_id'], null);
    $backUrl = '/portal/my/documents';
    extract($data);
    require ROOT . '/src/views/pages/jahresuebersicht.php';
});

$router->get('/portal/my/documents/:fileid/download', function ($params) {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ?',
        [$params['fileid'], $member['id']]
    );
    if (!$file || !is_file($file['pfad'])) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
    exit;
});

/** Eigenes Beitrittsformular (nur bei Online-Beitritt vorhanden) selbst ansehen. */
$router->get('/portal/my/documents/formular', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$member['id'], $member['community_id']]);
    if (!$application) { http_response_code(404); echo 'Kein Online-Beitrittsformular vorhanden.'; return; }
    header('Location: /portal/applications/' . $application['id'] . '/formular');
    exit;
});

/**
 * Verwaltung persönlicher API-Keys (Grundlage für die künftige Smart-Home-API mit
 * Echtzeit-Bezugs-/Einspeiseleistung und Community-Autarkie). Die eigentlichen
 * Live-Daten-Endpoints, die diese Keys einmal prüfen werden, kommen erst, sobald das
 * Zählerdaten-Setup fürs Mitglied-Dashboard produktionsreif ist -- Mitglieder können ihre
 * Zugänge aber schon jetzt anlegen/benennen/mit Ablaufdatum versehen/widerrufen.
 */
$router->get('/portal/my/api-keys', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $apiKeys = DB::fetchAll(
        'SELECT * FROM member_api_keys WHERE member_id = ? ORDER BY created_at DESC',
        [$member['id']]
    );
    // Frisch erzeugter Token wird nur EINMAL direkt nach der Erstellung angezeigt (Flash über
    // die Session) -- danach ist nur noch der Hash in der DB, der Klartext ist unwiederbringlich weg.
    $newApiKey = $_SESSION['flash_new_api_key'] ?? null;
    unset($_SESSION['flash_new_api_key']);
    require ROOT . '/src/views/pages/my_api_keys.php';
});

$router->post('/portal/my/api-keys', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        header('Location: /portal/my/api-keys?error=' . urlencode('Bitte einen Namen für den API-Key vergeben.'));
        exit;
    }
    $validityDays = ['30' => 30, '90' => 90, '365' => 365][$_POST['validity'] ?? ''] ?? null;
    $expiresAt = $validityDays ? date('Y-m-d H:i:s', strtotime("+{$validityDays} days")) : null;

    // Eigener Zufallstoken statt bcrypt-Passwort: 32 Byte Zufall sind selbst schon die
    // Sicherheit, ein sha256-Hash reicht zur Speicherung (siehe migrate_20260730.sql).
    $token = bin2hex(random_bytes(32));
    DB::execute(
        'INSERT INTO member_api_keys (community_id, member_id, name, key_prefix, key_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$member['community_id'], $member['id'], $name, substr($token, 0, 8), hash('sha256', $token), $expiresAt]
    );
    $_SESSION['flash_new_api_key'] = $token;
    header('Location: /portal/my/api-keys?created=1');
    exit;
});

$router->post('/portal/my/api-keys/:id/revoke', function ($params) {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    DB::execute(
        'UPDATE member_api_keys SET revoked_at = now() WHERE id = ? AND member_id = ? AND revoked_at IS NULL',
        [$params['id'], $member['id']]
    );
    header('Location: /portal/my/api-keys?success=' . urlencode('API-Key wurde widerrufen.'));
    exit;
});

/**
 * Benachrichtigt die konfigurierte Support-Adresse über ein neues Ticket -- rein informativ
 * ("es gibt was Neues, bitte im Portal ansehen"), kein Ticket-Inhalt-Editing per Mail-Antwort.
 * Scheitert der Mailversand (z.B. Microsoft Graph nicht konfiguriert), wird das nur geloggt --
 * ein Mitglied darf nie an einer fehlgeschlagenen internen Benachrichtigung scheitern.
 */
function notifySupportTicketCreated(string $ticketId, array $member, string $subject, string $category, string $message): void
{
    try {
        $to = DB::fetchOne('SELECT support_notification_email FROM platform_mail_config WHERE id = 1')['support_notification_email']
            ?? 'office@stromfueralle.at';
        $community = DB::fetchOne('SELECT name FROM communities WHERE id = ?', [$member['community_id']]);
        $categoryLabel = $category === 'feature' ? 'Feature-Vorschlag' : 'Problem/Frage';
        $body = '<p>Neues Support-Ticket in „' . htmlspecialchars($community['name'] ?? '') . '":</p>'
            . '<p><strong>' . htmlspecialchars($subject) . '</strong> (' . $categoryLabel . ')<br>'
            . 'von ' . htmlspecialchars(trim($member['first_name'] . ' ' . $member['last_name']))
            . ' (' . htmlspecialchars($member['email'] ?? '') . ')</p>'
            . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
            . '<p><a href="' . htmlspecialchars(portalUrl('/portal/support/' . $ticketId)) . '">Ticket im Portal ansehen &amp; antworten</a></p>';
        Mailer::send($to, 'Neues Support-Ticket: ' . $subject, $body);
    } catch (\Throwable $e) {
        error_log('[support_ticket_mail] ' . $e->getMessage());
    }
}

/**
 * Support-Ticket-System (siehe migrate_20260821.sql): Mitglieder können Probleme melden oder
 * Feature-Vorschläge machen, statt dass alles per E-Mail hin- und hergeschickt wird. Manager/
 * Platform-Admin sehen und beantworten alle Tickets ihrer Community unter /portal/support.
 */
$router->get('/portal/my/support', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $tickets = DB::fetchAll('SELECT * FROM support_tickets WHERE member_id = ? ORDER BY updated_at DESC', [$member['id']]);
    require ROOT . '/src/views/pages/my_support.php';
});

$router->post('/portal/my/support', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $subject  = trim($_POST['subject'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    $category = ($_POST['category'] ?? '') === 'feature' ? 'feature' : 'problem';
    if ($subject === '' || $message === '') {
        header('Location: /portal/my/support?error=' . urlencode('Bitte Betreff und Nachricht ausfüllen.'));
        exit;
    }
    $ticket = DB::fetchOne(
        'INSERT INTO support_tickets (community_id, member_id, subject, category) VALUES (?, ?, ?, ?) RETURNING id',
        [$member['community_id'], $member['id'], $subject, $category]
    );
    DB::execute(
        'INSERT INTO support_ticket_messages (ticket_id, author_label, is_staff, message) VALUES (?, ?, false, ?)',
        [$ticket['id'], trim($member['first_name'] . ' ' . $member['last_name']), $message]
    );
    notifySupportTicketCreated($ticket['id'], $member, $subject, $category, $message);
    header('Location: /portal/my/support/' . $ticket['id']);
    exit;
});

$router->get('/portal/my/support/:id', function ($params) {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $ticket = DB::fetchOne('SELECT * FROM support_tickets WHERE id = ? AND member_id = ?', [$params['id'], $member['id']]);
    if (!$ticket) { http_response_code(404); echo 'Ticket nicht gefunden.'; return; }
    $messages = DB::fetchAll('SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC', [$ticket['id']]);
    require ROOT . '/src/views/pages/my_support_detail.php';
});

$router->post('/portal/my/support/:id/reply', function ($params) {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    $ticket = DB::fetchOne('SELECT * FROM support_tickets WHERE id = ? AND member_id = ?', [$params['id'], $member['id']]);
    if (!$ticket) { http_response_code(404); echo 'Ticket nicht gefunden.'; return; }
    $message = trim($_POST['message'] ?? '');
    if ($message !== '') {
        DB::execute(
            'INSERT INTO support_ticket_messages (ticket_id, author_label, is_staff, message) VALUES (?, ?, false, ?)',
            [$ticket['id'], trim($member['first_name'] . ' ' . $member['last_name']), $message]
        );
        // Eine Mitglied-Antwort auf ein bereits beantwortetes/geschlossenes Ticket setzt den
        // Status zurück auf "offen" -- sonst würde die Antwort im Blick des Obmanns leicht untergehen.
        DB::execute("UPDATE support_tickets SET status = 'offen', updated_at = now() WHERE id = ?", [$ticket['id']]);
    }
    header('Location: /portal/my/support/' . $ticket['id']);
    exit;
});

/**
 * Wandelt einen von PostgreSQL/PDO gelieferten Datums-/Zeitstempel-String in striktes
 * ISO-8601 ("2026-08-18T17:03:00+00:00") um -- PDO gibt TIMESTAMPTZ-Spalten im
 * Postgres-eigenen Format zurück (Leerzeichen statt "T", Offset ohne Doppelpunkt, z.B.
 * "2026-08-18 17:03:00+00"), reine DATE-Spalten ganz ohne Uhrzeit (z.B. "2026-08-18"). Beides
 * ist kein gültiges ISO-8601 nach strengem Verständnis und lässt Swifts
 * `JSONDecoder`/`ISO8601DateFormatter` (Standardeinstellung, von der Xcode-App verwendet)
 * fehlschlagen -- genau das führte zu "Unerwartete Antwort vom Server" beim Mitglied-Detail
 * (viele Datumsfelder gleichzeitig: member_since, member_until, geburtsdatum, mehrere
 * registered_at/created_at) und potenziell an jeder anderen Stelle mit Datumsfeldern (Patrick,
 * 19.08.2026). ALLE Datums-/Zeitstempelfelder in /api/v1/*-JSON-Antworten laufen deshalb durch
 * diese Funktion, NICHT nur roh durchgereicht wie im Web-Portal (dort macht PHPs eigenes
 * date()/strtotime() auf denselben Rohstring ohnehin unabhängig vom Format weiter Sinn).
 *
 * Immer auf UTC normalisiert (Offset im Ergebnis ist IMMER "+00:00"), NICHT die
 * PHP-Standard-Zeitzone des Containers (Europe/Vienna) -- sonst hätte je nach Kalenderdatum ein
 * unterschiedlicher Offset im Ergebnis gestanden (+02:00 im Sommer/DST, +01:00 im Winter, siehe
 * Patrick 19.08.2026: "member_until":"...+01:00" vs. "member_since":"...+02:00" in derselben
 * Antwort) -- syntaktisch für Swift zwar beides gültiges ISO-8601, aber unnötig verwirrend und
 * schwerer zu vergleichen/cachen als ein durchgehend fester Offset.
 *
 * Reine DATE-Spalten (z.B. "2026-08-19", ohne Uhrzeit/Offset im Rohwert) werden explizit ALS
 * UTC-Mitternacht konstruiert statt über die PHP-Standard-Zeitzone interpretiert -- sonst hätte
 * "new DateTimeImmutable('2026-08-19')" das als Mitternacht in Europe/Vienna gelesen und beim
 * Umrechnen auf UTC (Sommerzeit: -2h) daraus fälschlich den 18.08. gemacht (Kalenderdatum
 * verschoben, z.B. bei geburtsdatum sichtbar falsch). Bei TIMESTAMPTZ-Werten ist das nicht
 * nötig -- die bringen ihren Offset schon selbst mit (Postgres liefert immer z.B. "...+00"),
 * PHP respektiert den beim Parsen unabhängig von der Standard-Zeitzone.
 */
function appDate(?string $value): ?string
{
    if ($value === null || $value === '') return null;
    try {
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? new DateTimeImmutable($value, new DateTimeZone('UTC'))
            : new DateTimeImmutable($value);
    } catch (\Throwable $e) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}

// ─── Mitglieder-App: Login-Flow (seit 30.08.2026, siehe docs/APP_API.md) ─────
// Getrennt von der Smart-Home-API unten (member_api_keys, langlebig/selbst erzeugt): hier
// meldet sich ein MENSCH mit E-Mail/Passwort in der App an, bekommt ein kurzlebiges
// Zugriffstoken + ein erneuerbares Refresh-Token (siehe AppApiAuth.php). Alle Antworten JSON.

/**
 * Baut nach erfolgreichem Login (Passwort, ggf. 2FA) die endgültige Antwort: bei genau EINER
 * Rollen-Option (Mitgliedschaft ODER Obmann-Zugang einer EEG) direkt die Zugriffstoken, bei
 * mehreren (z.B. Mitglied in >1 EEG, oder gleichzeitig Mitglied UND Obmann) eine Auswahlliste +
 * Ticket für /api/v1/login/select-community, bei keiner ein Fehler.
 */
function appLoginSuccessResponse(string $userId, ?string $deviceLabel): array
{
    $options = resolveAppRoleOptions($userId);
    if (!$options) {
        http_response_code(403);
        return ['error' => 'Dieser Account hat weder eine aktive Mitgliedschaft noch eine Obmann-Berechtigung in einer EEG.'];
    }
    if (count($options) === 1) {
        return appIssueSessionResponse($options[0], $deviceLabel);
    }
    return [
        'community_selection_required' => true,
        'selection_ticket' => AppApiAuth::issueTicket('community_pending', ['uid' => $userId]),
        'memberships' => array_map(fn($m) => [
            'role'           => $m['role'],
            'community_id'   => $m['community_id'],
            'community_name' => $m['community_name'],
        ], $options),
    ];
}

/**
 * Stellt Zugriffs- + Refresh-Token für eine gewählte Rollen-Option (Mitglied ODER Obmann) aus.
 * "account" statt "member" im Antwortfeld -- bei role=manager gibt es keinen member_id/eigenen
 * Mitgliedsdatensatz, "member" wäre dort irreführend.
 */
function appIssueSessionResponse(array $membership, ?string $deviceLabel): array
{
    return [
        'access_token'  => AppApiAuth::issueAccessToken($membership['community_id'], $membership['role'], $membership['member_id'], $membership['user_id']),
        'refresh_token' => AppApiAuth::issueRefreshToken($membership['community_id'], $membership['role'], $membership['member_id'], $membership['user_id'], $deviceLabel),
        'expires_in'    => AppApiAuth::accessTokenTtl(),
        'role'          => $membership['role'],
        'account' => [
            'member_id'      => $membership['member_id'],
            'name'           => $membership['name'],
            'community_id'   => $membership['community_id'],
            'community_name' => $membership['community_name'],
        ],
    ];
}

/**
 * Schritt 1: E-Mail + Passwort. Nutzt dieselbe Auth::checkPassword()/RateLimiter-Logik wie der
 * Web-Login (gemeinsamer Fehlversuch-Zähler pro E-Mail/IP -- ein Angreifer kann die Sperre nicht
 * einfach umgehen, indem er zwischen Web- und App-Endpunkt wechselt). Bei aktiver 2FA folgt
 * Schritt 2 (/api/v1/login/2fa) statt sofort Token auszugeben.
 */
$router->post('/api/v1/login', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $email = (string)($body['email'] ?? '');
    $password = (string)($body['password'] ?? '');
    $deviceLabel = isset($body['device_label']) ? mb_substr(trim((string)$body['device_label']), 0, 100) : null;

    if (RateLimiter::isLoginBlocked($email)) {
        http_response_code(429);
        echo json_encode(['error' => 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.']);
        return;
    }
    $user = Auth::checkPassword($email, $password);
    if (!$user) {
        RateLimiter::registerLoginFailure($email);
        http_response_code(401);
        echo json_encode(['error' => 'E-Mail oder Passwort falsch.']);
        return;
    }
    RateLimiter::resetLoginAttempts($email);

    if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        echo json_encode([
            'totp_required' => true,
            'login_ticket'  => AppApiAuth::issueTicket('totp_pending', ['uid' => $user['id'], 'dl' => $deviceLabel]),
        ]);
        return;
    }

    echo json_encode(appLoginSuccessResponse($user['id'], $deviceLabel));
});

/**
 * Schritt 2 (nur falls 2FA aktiv): Ticket aus Schritt 1 + 6-stelliger Code. Eigener
 * Rate-Limiter-Zähler pro User-ID (RateLimiter::isTotpBlocked), unabhängig vom E-Mail/IP-Zähler
 * aus Schritt 1 -- exakt dieselbe Logik wie /portal/login/2fa im Web.
 */
$router->post('/api/v1/login/2fa', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $ticket = AppApiAuth::verifyTicket('totp_pending', (string)($body['login_ticket'] ?? ''));
    if (!$ticket || empty($ticket['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Login-Ticket ungültig oder abgelaufen. Bitte erneut mit E-Mail/Passwort anmelden.']);
        return;
    }
    $uid = (string)$ticket['uid'];
    $deviceLabel = $ticket['dl'] ?? null;

    if (RateLimiter::isTotpBlocked($uid)) {
        http_response_code(429);
        echo json_encode(['error' => 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.']);
        return;
    }
    $u = DB::fetchOne('SELECT totp_secret FROM users WHERE id = ? AND active = true', [$uid]);
    if (!$u || empty($u['totp_secret']) || !totpVerify(totpSecretFromStorage($u['totp_secret']), (string)($body['code'] ?? ''))) {
        RateLimiter::registerTotpFailure($uid);
        http_response_code(401);
        echo json_encode(['error' => 'Code ungültig oder abgelaufen.']);
        return;
    }
    RateLimiter::resetTotpAttempts($uid);

    echo json_encode(appLoginSuccessResponse($uid, $deviceLabel));
});

/**
 * Nur nötig, wenn Schritt 1/2 eine Auswahlliste zurückgegeben haben (Account ist in mehreren
 * EEGs Mitglied und/oder gleichzeitig Mitglied UND Obmann). Löst die Optionen bewusst FRISCH
 * neu auf (statt der Liste aus dem Ticket zu vertrauen) -- verhindert, dass eine manipulierte
 * community_id/role akzeptiert wird, die zwischenzeitlich (z.B. Mitgliedschaft beendet) nicht
 * mehr gültig ist. "role" muss mitgeschickt werden, falls für dieselbe community_id sowohl eine
 * Mitglieds- als auch eine Obmann-Option existiert.
 */
$router->post('/api/v1/login/select-community', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $ticket = AppApiAuth::verifyTicket('community_pending', (string)($body['selection_ticket'] ?? ''));
    if (!$ticket || empty($ticket['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Auswahl-Ticket ungültig oder abgelaufen. Bitte erneut anmelden.']);
        return;
    }
    $deviceLabel = isset($body['device_label']) ? mb_substr(trim((string)$body['device_label']), 0, 100) : null;
    // Leerstring wie NULL behandeln (Admin-Option hat community_id=NULL, ein Client schickt für
    // "keine Auswahl nötig" typischerweise "" statt das Feld ganz wegzulassen).
    $communityId = !empty($body['community_id']) ? (string)$body['community_id'] : null;
    $role = (string)($body['role'] ?? 'member');

    $chosen = null;
    foreach (resolveAppRoleOptions((string)$ticket['uid']) as $m) {
        if ($m['community_id'] === $communityId && $m['role'] === $role) { $chosen = $m; break; }
    }
    if (!$chosen) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Community-Auswahl.']);
        return;
    }
    echo json_encode(appIssueSessionResponse($chosen, $deviceLabel));
});

/**
 * Listet alle Rollen-Optionen des eingeloggten Accounts (Mitgliedschaft(en) + Obmann-Zugänge)
 * -- Grundlage für einen Rollen-/Community-Umschalter INNERHALB der App, ohne Neuanmeldung
 * (Patrick, 19.08.2026: "als Obmann hab ich nur Mitglieder und Konto, wo wechselt man die
 * Rolle?"). Markiert zusätzlich, welche Option gerade aktiv ist (aus dem Access-Token im
 * Request), damit der Client sie in der Auswahl hervorheben kann.
 */
$router->get('/api/v1/roles', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $options = resolveAppRoleOptions($ctx['user_id']);
    echo json_encode(['roles' => array_map(fn($m) => [
        'role'           => $m['role'],
        'community_id'   => $m['community_id'],
        'community_name' => $m['community_name'],
        'name'           => $m['name'],
        'active'         => $m['role'] === $ctx['role'] && $m['community_id'] === $ctx['community_id'],
    ], $options)]);
});

/**
 * Wechselt die aktive Rolle/Community, OHNE dass sich der Nutzer neu anmelden muss (Web-Pendant:
 * POST /portal/switch-role, dort session-basiert) -- braucht dafür ein aktuell gültiges
 * Zugriffstoken statt eines separaten Auswahl-Tickets wie beim Login (dort ist die Identität ja
 * noch nicht bestätigt, hier schon). Liefert wie beim Login ein frisches Zugriffs-/
 * Refresh-Token-Paar für die NEUE Rolle zurück -- das alte Refresh-Token bleibt bis zu seinem
 * Ablauf gültig (kein Widerruf nötig, ein Rollenwechsel ist kein Sicherheitsvorfall, nur ein
 * zweites paralleles Gerätesitzungs-Paar, siehe app_sessions).
 */
$router->post('/api/v1/switch-role', function () {
    // allowDemoWrite=true: sonst könnte ein Demo-Login (siehe migrate_20260905.sql) über die App
    // nicht mal zwischen seinen vier vorgesehenen Rollen wechseln -- der Rollenwechsel selbst
    // verändert keine Plattform-Daten, ist also unbedenklich read-only-kompatibel.
    $ctx = AppApiAuth::requireAppAuth(true);
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    // Leerstring wie NULL behandeln (Admin-Option hat community_id=NULL).
    $communityId = !empty($body['community_id']) ? (string)$body['community_id'] : null;
    $role = (string)($body['role'] ?? '');
    // member_id disambiguiert bei role='member', falls ein Login mehrere Mitglied-Identitäten in
    // derselben Community hat (Demo-Logins, siehe migrate_20260905.sql) -- bei allen anderen
    // Accounts leer, dann matcht der Vergleich unten wie bisher rein über community_id+role.
    $memberId = !empty($body['member_id']) ? (string)$body['member_id'] : null;
    $deviceLabel = isset($body['device_label']) ? mb_substr(trim((string)$body['device_label']), 0, 100) : null;

    $chosen = null;
    foreach (resolveAppRoleOptions($ctx['user_id']) as $m) {
        if ($m['community_id'] === $communityId && $m['role'] === $role
            && ($m['member_id'] ?? null) === $memberId) { $chosen = $m; break; }
    }
    if (!$chosen) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Rollen-/Community-Auswahl.']);
        return;
    }
    echo json_encode(appIssueSessionResponse($chosen, $deviceLabel));
});

/**
 * Tauscht ein Refresh-Token gegen ein frisches Zugriffstoken -- soll die App still im
 * Hintergrund aufrufen, sobald das Zugriffstoken (15 Min gültig) abläuft, statt den Nutzer
 * erneut Passwort/2FA eingeben zu lassen. Das Refresh-Token wird dabei ROTIERT (siehe
 * AppApiAuth::verifyAndRotateRefreshToken()) -- die App MUSS das neu zurückgegebene
 * refresh_token speichern, das alte ist ab sofort ungültig.
 */
$router->post('/api/v1/token/refresh', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $result = AppApiAuth::verifyAndRotateRefreshToken((string)($body['refresh_token'] ?? ''));
    if (!$result) {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh-Token ungültig oder abgelaufen. Bitte erneut anmelden.']);
        return;
    }
    echo json_encode([
        'access_token'  => AppApiAuth::issueAccessToken($result['community_id'], $result['role'], $result['member_id'], $result['user_id']),
        'refresh_token' => $result['refresh_token'],
        'expires_in'    => AppApiAuth::accessTokenTtl(),
    ]);
});

/** Meldet das aktuelle Gerät ab (widerruft dessen Refresh-Token). Immer "ok", auch wenn das
 *  Token schon ungültig war -- Logout soll nie mit einem Fehler abbrechen können. */
$router->post('/api/v1/logout', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    if (!empty($body['refresh_token'])) {
        AppApiAuth::revokeRefreshToken((string)$body['refresh_token']);
    }
    echo json_encode(['status' => 'ok']);
});

/**
 * Test-Endpoint der Smart-Home-API: prüft nur, ob ein API-Key gültig ist, und gibt Basisinfos
 * zurück -- keine Energiedaten (die liefert /api/v1/live, siehe unten). Dient zum Testen der
 * Authentifizierung z.B. mit Node-RED, bevor man die Live-Daten abruft.
 *
 * Kein DB::setCommunity() vorab -- der Key wird bewusst GLOBAL per Hash gesucht (die Community
 * ist ja erst das Ergebnis der Suche), member_api_keys hat deshalb auch keine Community-RLS
 * (siehe migrate_20260731.sql).
 */
$router->get('/api/v1/me', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Fehlender oder ungültiger Authorization-Header. Erwartet: "Bearer <API-Key>".']);
        return;
    }
    $hash = hash('sha256', trim($m[1]));
    // LEFT JOIN members statt JOIN: seit migrate_20260901.sql gibt es neben persönlichen
    // Mitglied-Keys (member_id gesetzt) auch Community-weite Obmann-/Platform-Admin-Keys
    // (member_id NULL, angelegt unter /portal/settings) -- ein JOIN hätte deren Zeile komplett
    // ausgefiltert.
    $key = DB::fetchOne(
        'SELECT k.*, m.first_name, m.last_name, c.name AS community_name
         FROM member_api_keys k
         LEFT JOIN members m ON m.id = k.member_id
         JOIN communities c ON c.id = k.community_id
         WHERE k.key_hash = ?',
        [$hash]
    );
    if (!$key || $key['revoked_at'] || ($key['expires_at'] && strtotime($key['expires_at']) < time())) {
        http_response_code(401);
        echo json_encode(['error' => 'API-Key ungültig, widerrufen oder abgelaufen.']);
        return;
    }
    DB::execute('UPDATE member_api_keys SET last_used_at = now() WHERE id = ?', [$key['id']]);
    echo json_encode([
        'status'    => 'ok',
        'scope'     => $key['member_id'] ? 'member' : 'community',
        'member'    => $key['member_id'] ? ($key['first_name'] . ' ' . $key['last_name']) : null,
        'community' => $key['community_name'],
        'note'      => 'Authentifizierung erfolgreich. Live-Energiedaten: GET /api/v1/live.',
    ]);
});

/**
 * Live-Energiedaten fürs Smart-Home (Node-RED etc., siehe docs/ESP_IDEEN.md Punkt 2). Zwei
 * Key-Arten seit migrate_20260901.sql:
 * - Mitglied-Key (member_id gesetzt, über /portal/my/api-keys angelegt): bezug_w/einspeisung_w
 *   sind wie bisher die EIGENE Leistung des Mitglieds.
 * - Community-Key (member_id NULL, über /portal/settings angelegt -- Obmann/Platform-Admin,
 *   Patrick 18.08.2026: "auch für den ganzen Verein, nicht nur ein einzelnes Mitglied"):
 *   bezug_w/einspeisung_w sind stattdessen die GESAMTE Community-Leistung (identisch zu
 *   community.bezug_w unten) -- ein Skript, das nur die Top-Level-Felder liest (bisheriges
 *   Verhalten), bekommt dann sinnvoll "die Zahl, die zu diesem Key gehört" statt 0.
 * community_autarkie_pct und das neue "community"-Objekt (inkl. aktiver/gesamter Zählpunkte)
 * sind bei BEIDEN Key-Arten identisch befüllt. Gibt 0/null-Werte zurück statt eines Fehlers,
 * wenn noch keine Ausleseeinheit(en) Daten liefern -- ein Smart-Home-Skript soll bei "noch
 * keine Daten" nicht mit einem HTTP-Fehler abbrechen müssen.
 */
$router->get('/api/v1/live', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Fehlender oder ungültiger Authorization-Header. Erwartet: "Bearer <API-Key>".']);
        return;
    }
    $hash = hash('sha256', trim($m[1]));
    $key = DB::fetchOne('SELECT * FROM member_api_keys WHERE key_hash = ?', [$hash]);
    if (!$key || $key['revoked_at'] || ($key['expires_at'] && strtotime($key['expires_at']) < time())) {
        http_response_code(401);
        echo json_encode(['error' => 'API-Key ungültig, widerrufen oder abgelaufen.']);
        return;
    }
    DB::execute('UPDATE member_api_keys SET last_used_at = now() WHERE id = ?', [$key['id']]);

    $own = null;
    if ($key['member_id']) {
        // Pro Zählpunkt nur den jeweils neuesten Messwert im Fenster nehmen, nicht alle Zeilen
        // aufsummieren (bei 5s-Sende-Intervall sonst Werte um ein Vielfaches zu hoch).
        $own = DB::fetchOne(
            "SELECT COALESCE(SUM(power_bezug_w), 0) AS bezug_w, COALESCE(SUM(power_einspeisung_w), 0) AS einspeisung_w
             FROM (
                SELECT DISTINCT ON (metering_point_id) power_bezug_w, power_einspeisung_w
                FROM esp_measurements
                WHERE community_id = ? AND time >= now() - INTERVAL '2 minutes'
                  AND metering_point_id IN (SELECT id FROM metering_points WHERE member_id = ?)
                ORDER BY metering_point_id, time DESC
             ) latest",
            [$key['community_id'], $key['member_id']]
        );
    }

    $community = communityLivePower($key['community_id']);
    $autarkie = $community['bezug_w'] > 0 ? min(100, round($community['einsp_w'] / $community['bezug_w'] * 100)) : 0;

    echo json_encode([
        'status'                 => 'ok',
        'scope'                  => $key['member_id'] ? 'member' : 'community',
        'bezug_w'                => $own !== null ? (int)$own['bezug_w'] : $community['bezug_w'],
        'einspeisung_w'          => $own !== null ? (int)$own['einspeisung_w'] : $community['einsp_w'],
        'community_autarkie_pct' => $autarkie,
        'community' => [
            'bezug_w'       => $community['bezug_w'],
            'einspeisung_w' => $community['einsp_w'],
            'active_meters' => $community['active_meters'],
            'total_meters'  => $community['total_meters'],
        ],
    ]);
});

// ─── Mitglieder-App: Daten-Endpunkte (Bearer-Zugriffstoken, siehe AppApiAuth) ────
// Zeigen jeweils nur Daten des Mitglieds/der Community aus dem Token -- DB::setCommunity()
// aktiviert dieselben RLS-Policies wie im Web-Portal, sodass ein Token niemals fremde
// Community-Daten sehen kann, selbst bei einem Bug im Handler.

/**
 * Übersicht für den App-Startbildschirm: aktuelle Leistung des Mitglieds, Live-Zahlen der
 * ganzen Community, aktueller Verbrauchsmonat, letzte versendete Rechnung -- inhaltlich das
 * JSON-Äquivalent der Kopfzeile von member_dashboard.php, reicht für einen ersten
 * Übersichtsbildschirm der App ohne einen zweiten Request.
 */
$router->get('/api/v1/dashboard', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $member = DB::fetchOne('SELECT first_name, last_name FROM members WHERE id = ?', [$ctx['member_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true", [$ctx['member_id']]),
        'id'
    );
    $currentPowerW = $mpIds ? memberCurrentNetPowerW($ctx['community_id'], $mpIds) : null;
    $community = communityLivePower($ctx['community_id']);

    $currentMonth = null;
    if ($mpIds) {
        $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
        $row = DB::fetchOne(
            "SELECT date_trunc('month', time) AS monat,
                    COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme_kwh,
                    COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung_kwh
             FROM eda_measurements
             WHERE community_id = ? AND metering_point_id IN ($placeholders) AND quality IN ('L1','L2')
             GROUP BY monat ORDER BY monat DESC LIMIT 1",
            array_merge([$ctx['community_id']], $mpIds)
        );
        if ($row) {
            $currentMonth = [
                'label'          => monatsLabel((string)$row['monat']),
                'teilnahme_kwh'  => (float)$row['teilnahme_kwh'],
                'erzeugung_kwh'  => (float)$row['erzeugung_kwh'],
            ];
        }
    }

    $lastInvoice = DB::fetchOne(
        'SELECT id, rechnungsnummer, saldo_eur, created_at, sent_at FROM invoices
         WHERE member_id = ? AND sent_at IS NOT NULL ORDER BY created_at DESC LIMIT 1',
        [$ctx['member_id']]
    );

    echo json_encode([
        'member' => [
            'id'   => $ctx['member_id'],
            'name' => trim($member['first_name'] . ' ' . $member['last_name']),
        ],
        'current_power_w' => $currentPowerW,
        'community' => [
            'bezug_w'       => $community['bezug_w'],
            'einspeisung_w' => $community['einsp_w'],
            'active_meters' => $community['active_meters'],
            'total_meters'  => $community['total_meters'],
        ],
        'current_month' => $currentMonth,
        'last_invoice'  => $lastInvoice ? [
            'id'              => $lastInvoice['id'],
            'rechnungsnummer' => $lastInvoice['rechnungsnummer'],
            'saldo_eur'       => (float)$lastInvoice['saldo_eur'],
            'created_at'      => appDate($lastInvoice['created_at']),
        ] : null,
    ]);
});

/**
 * Leichtgewichtiger Polling-Endpunkt für die aktuelle Leistung -- zum Pollen alle paar Sekunden
 * (Patrick, 19.08.2026: "aktuelle Leistung automatisch aktualisieren, ohne die ganze Seite neu
 * zu laden"), OHNE bei jedem Aufruf die komplette /api/v1/dashboard-Antwort inkl. der
 * schwereren Monatsaggregation gegen eda_measurements neu zu berechnen. Web-Pendant:
 * /portal/api/current-power + /portal/api/live-power (dort zwei getrennte Endpunkte, hier zu
 * einem zusammengefasst -- eine App braucht für einen "Live"-Bildschirm ohnehin meist beides
 * gleichzeitig). Funktioniert für role=member (eigene Nettoleistung + Community) UND
 * role=manager (nur Community-Werte, current_power_w dann null -- ein reiner Obmann-Account
 * hat keine eigenen Zählpunkte).
 */
$router->get('/api/v1/current-power', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $currentPowerW = null;
    if ($ctx['member_id']) {
        $mpIds = array_column(
            DB::fetchAll('SELECT id FROM metering_points WHERE member_id = ? AND active = true', [$ctx['member_id']]),
            'id'
        );
        $currentPowerW = $mpIds ? memberCurrentNetPowerW($ctx['community_id'], $mpIds) : null;
    }
    $community = communityLivePower($ctx['community_id']);

    echo json_encode([
        'current_power_w' => $currentPowerW,
        'community' => [
            'bezug_w'       => $community['bezug_w'],
            'einspeisung_w' => $community['einsp_w'],
            'active_meters' => $community['active_meters'],
            'total_meters'  => $community['total_meters'],
        ],
    ]);
});

/**
 * Monatlicher Verbrauchs-/Erzeugungsverlauf (für ein Balkendiagramm in der App) -- dieselbe
 * Datengrundlage (eda_measurements, nur L1/L2-Qualität) wie der Verlauf im Kundenportal.
 * ?months=N (Default 6, maximal 24) steuert, wie viele Monate zurück geliefert werden.
 */
$router->get('/api/v1/consumption', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ?", [$ctx['member_id']]),
        'id'
    );
    $rows = [];
    if ($mpIds) {
        $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
        $rows = DB::fetchAll(
            "SELECT date_trunc('month', time) AS monat,
                    COALESCE(SUM(kwh_teilnahme), 0) AS teilnahme_kwh,
                    COALESCE(SUM(kwh_erzeugung), 0) AS erzeugung_kwh
             FROM eda_measurements
             WHERE community_id = ? AND metering_point_id IN ($placeholders) AND quality IN ('L1','L2')
             GROUP BY monat ORDER BY monat DESC LIMIT ?",
            array_merge([$ctx['community_id']], $mpIds, [$months])
        );
    }

    echo json_encode(['months' => array_map(fn($r) => [
        'month'         => date('Y-m', strtotime((string)$r['monat'])),
        'label'         => monatsLabel((string)$r['monat']),
        'teilnahme_kwh' => (float)$r['teilnahme_kwh'],
        'erzeugung_kwh' => (float)$r['erzeugung_kwh'],
    ], $rows)]);
});

/**
 * Viertelstündlicher Verbrauch vs. gemeinschaftliche Eigendeckung für EINEN Tag (App-Äquivalent
 * von /portal/my/verbrauch, siehe memberIntervalDayData() weiter oben für die geteilte Logik).
 * ?date=YYYY-MM-DD, Default: heute (anders als im Web-Portal kein automatisches Zurückfallen auf
 * den letzten Tag mit Daten -- die App zeigt bei fehlenden Werten stattdessen einen eigenen
 * Hinweis an, siehe app.md).
 */
$router->get('/api/v1/consumption/interval', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Datum, erwartet YYYY-MM-DD.']);
        return;
    }
    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('consumer', 'prosumer')", [$ctx['member_id']]),
        'id'
    );
    $data = memberIntervalDayData($ctx['community_id'], $mpIds, $date);

    echo json_encode([
        'date'                   => $date,
        'has_data'               => $data['has_data'],
        'total_messung_kwh'      => $data['total_messung_kwh'],
        'total_gemeinschaft_kwh' => $data['total_gemeinschaft_kwh'],
        'intervals'              => array_map(fn($iv) => [
            'zeit'           => $iv['zeit'],
            'verbrauch_w'    => $iv['verbrauch_w'],
            'gemeinschaft_w' => $iv['gemeinschaft_w'],
        ], $data['intervals']),
    ]);
});

/**
 * Spiegelbild von GET /api/v1/consumption/interval, für die eigene Einspeisung (App-Äquivalent
 * von /portal/my/einspeisung) -- Patrick, 06.09.2026: "warum haben die Einspeiser nicht die
 * Möglichkeit, ihre eingespeiste Leistung in einem Diagramm einzusehen?". 'total_messung_kwh'
 * ist bei GENERATION die gemeinschaftsweite Gesamterzeugung (NICHT mitgliedsspezifisch, siehe
 * memberIntervalDayData()) -- bewusst trotzdem mitgeliefert (falls die App sie mal als
 * Kontext-Info zeigen will), die für das eigene Diagramm relevante Zahl ist
 * 'total_gemeinschaft_kwh'/'gemeinschaft_w'.
 */
$router->get('/api/v1/production/interval', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Datum, erwartet YYYY-MM-DD.']);
        return;
    }
    $mpIds = array_column(
        DB::fetchAll("SELECT id FROM metering_points WHERE member_id = ? AND active = true AND type IN ('producer', 'prosumer')", [$ctx['member_id']]),
        'id'
    );
    $data = memberIntervalDayData($ctx['community_id'], $mpIds, $date, 'GENERATION');

    echo json_encode([
        'date'                   => $date,
        'has_data'               => $data['has_data'],
        'total_messung_kwh'      => $data['total_messung_kwh'],
        'total_gemeinschaft_kwh' => $data['total_gemeinschaft_kwh'],
        'intervals'              => array_map(fn($iv) => [
            'zeit'           => $iv['zeit'],
            'einspeisung_w'  => $iv['gemeinschaft_w'],
        ], $data['intervals']),
    ]);
});

/** Rechnungsliste des Mitglieds (Metadaten -- die PDF selbst kommt über
 *  /api/v1/invoices/:id/pdf). */
$router->get('/api/v1/invoices', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $invoices = DB::fetchAll(
        'SELECT i.id, i.rechnungsnummer, i.saldo_eur, i.sent_at, i.created_at, br.quartal, br.period_from, br.period_to
         FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = ? ORDER BY i.created_at DESC',
        [$ctx['member_id']]
    );

    echo json_encode(['invoices' => array_map(fn($i) => [
        'id'              => $i['id'],
        'rechnungsnummer' => $i['rechnungsnummer'],
        'saldo_eur'       => (float)$i['saldo_eur'],
        'quartal'         => $i['quartal'],
        'period_from'     => appDate($i['period_from']),
        'period_to'       => appDate($i['period_to']),
        'sent_at'         => appDate($i['sent_at']),
        'created_at'      => appDate($i['created_at']),
    ], $invoices)]);
});

/** Eigene Zählpunkte (Zählpunktnummer, Typ, aktiv/inaktiv) -- kein WLAN-Passwort/keine
 *  Diagnosedaten hier (die bleiben absichtlich Web-Portal-only, siehe member_detail.php). */
$router->get('/api/v1/metering-points', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $points = DB::fetchAll(
        'SELECT id, zaehlpunkt_nr, type, active, registered_at FROM metering_points WHERE member_id = ? ORDER BY registered_at',
        [$ctx['member_id']]
    );

    echo json_encode(['metering_points' => array_map(fn($p) => [
        'id'             => $p['id'],
        'zaehlpunkt_nr'  => $p['zaehlpunkt_nr'],
        'type'           => $p['type'],
        'active'         => (bool)$p['active'],
        'registered_at'  => appDate($p['registered_at']),
    ], $points)]);
});

// ─── Mitglieder-App: Verträge, Dokumente, DSGVO, Support, Profil/2FA ─────────
// JSON-Äquivalente der /portal/my/*- bzw. /portal/profile|password-Routen, jeweils per
// Bearer-Zugriffstoken statt Session (siehe AppApiAuth). Die reine Datenlogik (SQL, PDF-
// Vorlagen, Mail-Versand) wird 1:1 über dieselben Helferfunktionen wie im Web-Portal
// wiederverwendet -- nur Auth-Quelle (Token statt Session) und Antwortformat (JSON statt
// Redirect/HTML) unterscheiden sich.

/** Kein Mitgliedskonto in dieser EEG -> einheitliche 403-JSON-Antwort (reiner Obmann-Account
 *  ohne eigene Mitgliedschaft hat keinen Zugriff auf die eigenen Vertrags-/Dokument-Routen). */
function requireAppMemberId(array $ctx): ?string
{
    if (!$ctx['member_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return null;
    }
    return $ctx['member_id'];
}

/** Vertragsstatus (Bezug/Einspeisung) für die App-Startseite "Verträge". */
$router->get('/api/v1/contracts/status', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $mps = DB::fetchAll('SELECT type FROM metering_points WHERE member_id = ? AND active = true', [$member['id']]);
    $hasConsumer = !empty(array_filter($mps, fn($mp) => $mp['type'] === 'consumer'));
    $hasProducer = !empty(array_filter($mps, fn($mp) => $mp['type'] === 'producer'));

    echo json_encode([
        'contracts_enabled' => contractsEnabled($member['community_id']),
        'bezug' => $hasConsumer ? [
            'status'    => $member['contract_bezug_status'] ?? 'none',
            'signed_at' => appDate($member['contract_bezug_signed_at'] ?? null),
        ] : null,
        'einspeisung' => $hasProducer ? [
            'status'    => $member['contract_einspeisung_status'] ?? 'none',
            'signed_at' => appDate($member['contract_einspeisung_signed_at'] ?? null),
        ] : null,
    ]);
});

/** Vertrags-PDF (aktueller Stand, ob nur versendet oder bereits signiert). Gleiche Vorlagen wie
 *  /portal/my/contract/bezug|einspeisung. */
$router->get('/api/v1/contracts/:type/pdf', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Unbekannter Vertragstyp.']);
        return;
    }
    if (!$ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    if (!contractsEnabled($member['community_id'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Verträge sind in dieser EEG deaktiviert.']);
        return;
    }

    $mpType = $type === 'einspeisung' ? 'producer' : 'consumer';
    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$member['id'], $mpType]);
    if (empty($mps)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(400);
        echo json_encode(['error' => 'Kein ' . ($type === 'einspeisung' ? 'Einspeise' : 'Bezugs') . '-Zählpunkt registriert.']);
        return;
    }

    $tariff = contractTariff($member['community_id'], $member['contract_' . $type . '_generated_at'] ?? null);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = communityManagerSignature($member['community_id']);
    $memberSig = memberSignatureAsset($member['contract_' . $type . '_customer_signature'] ?? null);

    if ($type === 'einspeisung') {
        $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature, $memberSig);
        streamLatexPdf('einspeisevereinbarung', $vars, 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
    } else {
        $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature, $memberSig);
        streamLatexPdf('bezugsvereinbarung', $vars, 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);
    }
});

/**
 * Digitale Unterschrift durch das Mitglied in der App -- JSON-Äquivalent von
 * POST /portal/my/contract/:type/sign. Erwartet im Body {"zustimmung": true,
 * "signature_image": "data:image/png;base64,..."} (Unterschriftsfeld als PNG, gleiche
 * Validierung wie im Web-Portal).
 */
$router->post('/api/v1/contracts/:type/sign', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) {
        http_response_code(404);
        echo json_encode(['error' => 'Unbekannter Vertragstyp.']);
        return;
    }
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $status = $member['contract_' . $type . '_status'] ?? 'none';
    if ($status !== 'created') {
        http_response_code(400);
        echo json_encode(['error' => 'Vertrag kann in diesem Status nicht unterschrieben werden.']);
        return;
    }
    $body = jsonBody();
    if (empty($body['zustimmung'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte die Zustimmung bestätigen.']);
        return;
    }
    $signature = (string)($body['signature_image'] ?? '');
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Unterschrift.']);
        return;
    }

    DB::execute(
        "UPDATE members SET
            contract_{$type}_status = 'signed',
            contract_{$type}_customer_signature = ?,
            contract_{$type}_signed_at = now(),
            contract_{$type}_signer_ip = ?
         WHERE id = ?",
        [$signature, $_SERVER['REMOTE_ADDR'] ?? null, $member['id']]
    );
    DB::execute(
        'INSERT INTO notifications (community_id, typ, titel, text, referenz_typ, referenz_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $ctx['community_id'],
            'vertrag_unterschrieben',
            'Vertrag digital unterschrieben: ' . $member['first_name'] . ' ' . $member['last_name'] . ' (' . contractTypeLabel($type) . ')',
            'Die/der Netzbenutzer:in hat die ' . contractTypeLabel($type) . ' in der App digital unterschrieben. '
            . 'Der Vertrag ist ab sofort gültig und wird automatisch sicher archiviert.',
            'member',
            $member['id'],
        ]
    );
    echo json_encode(['status' => 'ok']);
});

/** Eigene hochgeladene/vom Obmann hochgeladene Dateien (Ausweis-Scan, Beitrittserklärung, ...). */
$router->get('/api/v1/documents', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $files = DB::fetchAll('SELECT id, name, pfad, mime, created_at FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$ctx['member_id']]);
    echo json_encode(['documents' => array_map(fn($f) => [
        'id'         => $f['id'],
        'name'       => filenameWithExtension($f['name'], $f['pfad']),
        'mime'       => $f['mime'],
        'created_at' => appDate($f['created_at']),
    ], $files)]);
});

$router->get('/api/v1/documents/:fileid/download', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    if (!$ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $file = DB::fetchOne('SELECT * FROM member_files WHERE id = ? AND member_id = ?', [$params['fileid'], $ctx['member_id']]);
    if (!$file || !is_file($file['pfad'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden.']);
        return;
    }
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
});

/** DSGVO-Selbstauskunft (Art. 15/20 DSGVO) als JSON-Download -- identisch zu
 *  /portal/my/dsgvo-export, nur per Bearer-Token statt Session. */
$router->get('/api/v1/dsgvo-export', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    if (!$ctx['member_id']) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => 'Kein Mitgliedskonto in dieser EEG.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    logAudit($member['community_id'], 'dsgvo.export.self', 'member', $member['id'], 'Mitglied hat DSGVO-Selbstauskunft über die App exportiert');
    sendDsgvoExport(buildMemberDsgvoExport($member), 'dsgvo-export-' . ($member['kundennummer'] ?? 'mitglied') . '.json');
});

// ─── Mitglieder-App: Support-Tickets ─────────────────────────────────────────
$router->get('/api/v1/support', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $tickets = DB::fetchAll('SELECT * FROM support_tickets WHERE member_id = ? ORDER BY updated_at DESC', [$ctx['member_id']]);
    echo json_encode(['tickets' => array_map(fn($t) => [
        'id'         => $t['id'],
        'subject'    => $t['subject'],
        'category'   => $t['category'],
        'status'     => $t['status'],
        'created_at' => appDate($t['created_at']),
        'updated_at' => appDate($t['updated_at']),
    ], $tickets)]);
});

$router->post('/api/v1/support', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }

    $body = jsonBody();
    $subject  = trim((string)($body['subject'] ?? ''));
    $message  = trim((string)($body['message'] ?? ''));
    $category = ($body['category'] ?? '') === 'feature' ? 'feature' : 'problem';
    if ($subject === '' || $message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte Betreff und Nachricht ausfüllen.']);
        return;
    }
    $ticket = DB::fetchOne(
        'INSERT INTO support_tickets (community_id, member_id, subject, category) VALUES (?, ?, ?, ?) RETURNING id',
        [$member['community_id'], $member['id'], $subject, $category]
    );
    DB::execute(
        'INSERT INTO support_ticket_messages (ticket_id, author_label, is_staff, message) VALUES (?, ?, false, ?)',
        [$ticket['id'], trim($member['first_name'] . ' ' . $member['last_name']), $message]
    );
    notifySupportTicketCreated($ticket['id'], $member, $subject, $category, $message);
    echo json_encode(['id' => $ticket['id'], 'status' => 'ok']);
});

$router->get('/api/v1/support/:id', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);

    $ticket = DB::fetchOne('SELECT * FROM support_tickets WHERE id = ? AND member_id = ?', [$params['id'], $ctx['member_id']]);
    if (!$ticket) { http_response_code(404); echo json_encode(['error' => 'Ticket nicht gefunden.']); return; }
    $messages = DB::fetchAll('SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC', [$ticket['id']]);
    echo json_encode([
        'ticket' => [
            'id'         => $ticket['id'],
            'subject'    => $ticket['subject'],
            'category'   => $ticket['category'],
            'status'     => $ticket['status'],
            'created_at' => appDate($ticket['created_at']),
            'updated_at' => appDate($ticket['updated_at']),
        ],
        'messages' => array_map(fn($m) => [
            'id'           => $m['id'],
            'author_label' => $m['author_label'],
            'is_staff'     => (bool)$m['is_staff'],
            'message'      => $m['message'],
            'created_at'   => appDate($m['created_at']),
        ], $messages),
    ]);
});

$router->post('/api/v1/support/:id/reply', function ($params) {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!requireAppMemberId($ctx)) return;
    DB::setCommunity($ctx['community_id']);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    if (!$member) { http_response_code(404); echo json_encode(['error' => 'Mitglied nicht gefunden.']); return; }
    $ticket = DB::fetchOne('SELECT * FROM support_tickets WHERE id = ? AND member_id = ?', [$params['id'], $ctx['member_id']]);
    if (!$ticket) { http_response_code(404); echo json_encode(['error' => 'Ticket nicht gefunden.']); return; }

    $body = jsonBody();
    $message = trim((string)($body['message'] ?? ''));
    if ($message === '') { http_response_code(400); echo json_encode(['error' => 'Nachricht darf nicht leer sein.']); return; }
    DB::execute(
        'INSERT INTO support_ticket_messages (ticket_id, author_label, is_staff, message) VALUES (?, ?, false, ?)',
        [$ticket['id'], trim($member['first_name'] . ' ' . $member['last_name']), $message]
    );
    DB::execute("UPDATE support_tickets SET status = 'offen', updated_at = now() WHERE id = ?", [$ticket['id']]);
    echo json_encode(['status' => 'ok']);
});

// ─── Mitglieder-App: Profil, Passwort, 2FA (Konto-Endpunkte, users-Tabelle) ──────────────────
// Anders als die obigen Routen (member_id nötig) funktionieren diese für JEDES Token -- Mitglied
// UND reiner Obmann-Account, siehe Kommentar zu app_sessions.user_id (migrate_20260831.sql).
$router->get('/api/v1/profile', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path, totp_enabled FROM users WHERE id = ?', [$ctx['user_id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Konto nicht gefunden.']); return; }

    $hasPhoto = !empty($user['photo_path']);
    if ($ctx['member_id']) {
        DB::setCommunity($ctx['community_id']);
        $member = DB::fetchOne('SELECT photo_path FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
        $hasPhoto = !empty($member['photo_path'] ?? null);
    }

    echo json_encode([
        'user' => [
            'id'           => $user['id'],
            'email'        => $user['email'],
            'first_name'   => $user['first_name'],
            'last_name'    => $user['last_name'],
            'totp_enabled' => (bool)$user['totp_enabled'],
        ],
        'role'      => $ctx['role'],
        'has_photo' => $hasPhoto,
    ]);
});

$router->post('/api/v1/profile', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $email     = trim((string)($body['email'] ?? ''));
    $firstName = trim((string)($body['first_name'] ?? ''));
    $lastName  = trim((string)($body['last_name'] ?? ''));
    if (!$email || !$firstName || !$lastName) {
        http_response_code(400);
        echo json_encode(['error' => 'Alle Felder sind Pflichtfelder.']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige E-Mail-Adresse.']);
        return;
    }
    DB::execute('UPDATE users SET email=?, first_name=?, last_name=? WHERE id=?', [$email, $firstName, $lastName, $ctx['user_id']]);
    echo json_encode(['status' => 'ok']);
});

/** Profilbild-Upload (multipart/form-data, Feld "photo") -- hängt am Mitgliedsdatensatz, wenn
 *  vorhanden, sonst direkt am Login-Account (reiner Obmann-/Platform-Admin-Zugang). */
$router->post('/api/v1/profile/photo', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!isset($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Bild übermittelt.']);
        return;
    }
    if ($ctx['member_id']) {
        DB::setCommunity($ctx['community_id']);
        $err = saveMemberPhoto($ctx['member_id'], $_FILES['photo']);
    } else {
        $err = saveUserPhoto($ctx['user_id'], $_FILES['photo']);
    }
    if ($err !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'Profilbild konnte nicht gespeichert werden.']);
        return;
    }
    echo json_encode(['status' => 'ok']);
});

$router->post('/api/v1/password', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$ctx['user_id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Konto nicht gefunden.']); return; }

    $body    = jsonBody();
    $current = (string)($body['current_password'] ?? '');
    $new     = (string)($body['new_password'] ?? '');
    $confirm = (string)($body['confirm_password'] ?? $new);

    if (!password_verify($current, $user['password_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Aktuelles Passwort ist falsch.']);
        return;
    }
    if (strlen($new) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.']);
        return;
    }
    if ($new !== $confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'Die Passwörter stimmen nicht überein.']);
        return;
    }
    if (isPasswordBreached($new)) {
        http_response_code(400);
        echo json_encode(['error' => 'Dieses Passwort ist in bekannten Datenlecks aufgetaucht und ist deshalb unsicher. Bitte ein anderes Passwort wählen.']);
        return;
    }
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    DB::execute('UPDATE users SET password_hash=? WHERE id=?', [$hash, $ctx['user_id']]);
    echo json_encode(['status' => 'ok']);
});

/**
 * 2FA-Einrichtung starten: erzeugt ein neues TOTP-Secret. Da die App KEINE Server-Session hält
 * (bewusst stateless, siehe AppApiAuth), wird das Secret -- anders als im Web-Portal
 * ($_SESSION['2fa_setup_secret']) -- in einem kurzlebigen, signierten Ticket an den Client
 * zurückgegeben (gleiches Prinzip wie beim Community-Auswahl-Ticket im Login-Flow) und muss bei
 * POST /api/v1/2fa/enable wieder mitgeschickt werden.
 */
$router->get('/api/v1/2fa/setup', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT email FROM users WHERE id = ?', [$ctx['user_id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Konto nicht gefunden.']); return; }

    $secret = totpGenerateSecret();
    $setupTicket = AppApiAuth::issueTicket('app_2fa_setup', ['uid' => $ctx['user_id'], 'secret' => $secret]);
    echo json_encode([
        'secret'       => $secret,
        'otpauth_uri'  => totpProvisioningUri($secret, $user['email'], 'Strom für alle'),
        'setup_ticket' => $setupTicket,
    ]);
});

$router->post('/api/v1/2fa/enable', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $ticket = AppApiAuth::verifyTicket('app_2fa_setup', (string)($body['setup_ticket'] ?? ''));
    if (!$ticket || ($ticket['uid'] ?? null) !== $ctx['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Setup-Ticket ungültig oder abgelaufen. Bitte 2FA-Einrichtung erneut starten.']);
        return;
    }
    $secret = (string)($ticket['secret'] ?? '');
    if ($secret === '' || !totpVerify($secret, (string)($body['code'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'Der Code stimmt nicht. Bitte den aktuellen 6-stelligen Code eingeben.']);
        return;
    }
    DB::execute('UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?', [encryptSecret($secret), 'true', $ctx['user_id']]);
    logAudit(null, 'user.2fa.enable', 'user', $ctx['user_id'], 'Zwei-Faktor-Authentifizierung (TOTP) über die App aktiviert');
    echo json_encode(['status' => 'ok']);
});

$router->post('/api/v1/2fa/disable', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::execute("UPDATE users SET totp_enabled = 'false', totp_secret = NULL WHERE id = ?", [$ctx['user_id']]);
    logAudit(null, 'user.2fa.disable', 'user', $ctx['user_id'], 'Zwei-Faktor-Authentifizierung (TOTP) über die App deaktiviert');
    echo json_encode(['status' => 'ok']);
});

// ─── Push-Benachrichtigungen (APNs, siehe Push.php + database/migrate_20260903.sql) ──────────
// Token-Registrierung ist rollenunabhängig (jeder angemeldete App-Zugang -- Mitglied, Obmann,
// Admin -- kann ein Gerät für Push registrieren), die Einstellungen (Rechnungs-Push an/aus,
// Einspeisung-Schwelle) gelten nur für Mitglieder (member_id nur bei role='member' gesetzt).

/** Registriert/aktualisiert ein Gerät für Push. Ein Gerät (device_token) kann jeweils nur EINEM
 *  Account zugeordnet sein -- meldet sich ein anderer Account auf demselben Gerät an (Geräte-
 *  wechsel/-weitergabe), übernimmt dieser Account den Token (UPSERT über die UNIQUE-Spalte). */
$router->post('/api/v1/push/register', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $deviceToken = trim((string)($body['device_token'] ?? ''));
    if ($deviceToken === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte device_token angeben.']);
        return;
    }
    $deviceLabel = trim((string)($body['device_label'] ?? '')) ?: null;

    DB::execute(
        'INSERT INTO app_push_tokens (user_id, role, device_token, device_label)
         VALUES (?, ?, ?, ?)
         ON CONFLICT (device_token) DO UPDATE
         SET user_id = EXCLUDED.user_id, role = EXCLUDED.role, device_label = EXCLUDED.device_label,
             last_seen_at = now(), revoked_at = NULL',
        [$ctx['user_id'], $ctx['role'], $deviceToken, $deviceLabel]
    );
    echo json_encode(['status' => 'ok']);
});

/** Meldet ein Gerät wieder ab (z.B. Logout/Deinstallation) -- nur der eigene Account darf sein
 *  eigenes Gerät abmelden, kein globaler Zugriff über die device_token allein. */
$router->post('/api/v1/push/unregister', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $deviceToken = trim((string)($body['device_token'] ?? ''));
    if ($deviceToken === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte device_token angeben.']);
        return;
    }
    DB::execute(
        'UPDATE app_push_tokens SET revoked_at = now() WHERE device_token = ? AND user_id = ?',
        [$deviceToken, $ctx['user_id']]
    );
    echo json_encode(['status' => 'ok']);
});

/** Eigene Benachrichtigungs-Einstellungen (nur Mitglied-Rolle -- Obmann/Admin haben aktuell
 *  keine eigenen Einstellungen, ihr Postfach-Push ist immer an). */
$router->get('/api/v1/notifications/settings', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!$ctx['member_id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);
    $s = DB::fetchOne('SELECT * FROM member_notification_settings WHERE member_id = ?', [$ctx['member_id']]);
    echo json_encode([
        'notify_new_invoice'      => $s ? (bool)$s['notify_new_invoice'] : true,
        'einspeisung_threshold_w' => $s['einspeisung_threshold_w'] ?? null,
    ]);
});

/** Setzt die eigenen Benachrichtigungs-Einstellungen. einspeisung_threshold_w = null/0 im
 *  Request deaktiviert die Einspeisung-Push (siehe Trigger-Bedingung "IF v_threshold IS NULL"
 *  in migrate_20260903.sql) -- ein neu gesetzter Schwellenwert startet bewusst mit
 *  einspeisung_above_threshold = false (Hysterese-Ausgangszustand), damit ein Wert, der schon
 *  jetzt über der neuen Schwelle liegt, beim nächsten Messwert normal auslöst statt als
 *  "schon oberhalb, keine erneute Benachrichtigung" übersprungen zu werden. */
$router->post('/api/v1/notifications/settings', function () {
    $ctx = AppApiAuth::requireAppAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    if (!$ctx['member_id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return;
    }
    DB::setCommunity($ctx['community_id']);

    $body = jsonBody();
    $notifyInvoice = array_key_exists('notify_new_invoice', $body) ? (bool)$body['notify_new_invoice'] : true;
    $thresholdRaw = $body['einspeisung_threshold_w'] ?? null;
    $threshold = ($thresholdRaw !== null && (int)$thresholdRaw > 0) ? (int)$thresholdRaw : null;

    DB::execute(
        'INSERT INTO member_notification_settings (member_id, notify_new_invoice, einspeisung_threshold_w, einspeisung_above_threshold)
         VALUES (?, ?, ?, false)
         ON CONFLICT (member_id) DO UPDATE
         SET notify_new_invoice = EXCLUDED.notify_new_invoice,
             einspeisung_threshold_w = EXCLUDED.einspeisung_threshold_w,
             einspeisung_above_threshold = false,
             updated_at = now()',
        [$ctx['member_id'], $notifyInvoice, $threshold]
    );
    echo json_encode(['status' => 'ok']);
});

// ─── Mitglieder-App: Obmann-Endpunkte (Mitgliederverwaltung von unterwegs) ───────────────────
// Nur mit role='manager'-Token (AppApiAuth::requireManagerAuth()). Anders als
// requireMemberAccess() im Web-Portal ist hier KEIN Platform-Admin-"jede Community
// durchprobieren"-Fall nötig: der Manager-Token trägt die Community bereits fest (bei mehreren
// EEGs wählt der Login-Flow eine davon aus, siehe /api/v1/login/select-community) -- entspricht
// exakt dem "sonst"-Zweig von requireMemberAccess().
function requireAppManagedMember(string $communityId, string $memberId): ?array
{
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$memberId, $communityId]);
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Mitglied nicht gefunden.']);
        return null;
    }
    return $member;
}

/** Mitgliederliste der eigenen EEG -- leichtere Variante der /portal/members-Tabelle (ohne
 *  ESP-Fehlerstatus/Sidebar-Badges, die reichen fürs Web-Portal, nicht für eine App-Liste). */
$router->get('/api/v1/manager/members', function () {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);

    $members = DB::fetchAll(
        "SELECT m.id, m.kundennummer, m.salutation, m.titel, m.first_name, m.last_name, m.company_name,
                m.email, m.phone, m.city, m.member_since, m.member_until,
                COUNT(DISTINCT mp.id) AS metering_point_count,
                COALESCE(
                    (SELECT SUM(i.saldo_eur) FROM invoices i
                     WHERE i.member_id = m.id AND i.saldo_eur > 0 AND i.sent_at IS NULL),
                    0
                ) AS open_amount
         FROM members m
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
        [$ctx['community_id']]
    );

    echo json_encode(['members' => array_map(fn($m) => [
        'id'                    => $m['id'],
        'kundennummer'          => $m['kundennummer'],
        'name'                  => trim($m['first_name'] . ' ' . $m['last_name']),
        'company_name'          => $m['company_name'],
        'email'                 => $m['email'],
        'phone'                 => $m['phone'],
        'city'                  => $m['city'],
        'member_since'          => appDate($m['member_since']),
        'member_until'          => appDate($m['member_until']),
        'metering_point_count'  => (int)$m['metering_point_count'],
        'open_amount_eur'       => (float)$m['open_amount'],
    ], $members)]);
});

/** Mitglied-Detail inkl. Zählpunkten und Dateien (Obmann-Ansicht in der App). */
$router->get('/api/v1/manager/members/:id', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    $meteringPoints = DB::fetchAll('SELECT id, zaehlpunkt_nr, type, active, registered_at FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$member['id']]);
    $files = DB::fetchAll('SELECT id, name, pfad, mime, created_at FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$member['id']]);

    echo json_encode([
        'member' => [
            'id'               => $member['id'],
            'kundennummer'     => $member['kundennummer'],
            'salutation'       => $member['salutation'],
            'titel'            => $member['titel'],
            'first_name'       => $member['first_name'],
            'last_name'        => $member['last_name'],
            'company_name'     => $member['company_name'],
            'address'          => $member['address'],
            'zip'              => $member['zip'],
            'city'             => $member['city'],
            'email'            => $member['email'],
            'phone'            => $member['phone'],
            'invoice_uid'      => $member['invoice_uid'],
            'member_iban'      => $member['member_iban'],
            'member_bic'       => $member['member_bic'],
            'member_since'     => appDate($member['member_since']),
            'member_until'     => appDate($member['member_until']),
            'geburtsdatum'     => appDate($member['geburtsdatum']),
            'stromlieferant'   => $member['stromlieferant'],
        ],
        'metering_points' => array_map(fn($p) => [
            'id'            => $p['id'],
            'zaehlpunkt_nr' => $p['zaehlpunkt_nr'],
            'type'          => $p['type'],
            'active'        => (bool)$p['active'],
            'registered_at' => appDate($p['registered_at']),
        ], $meteringPoints),
        'files' => array_map(fn($f) => [
            'id'         => $f['id'],
            'name'       => filenameWithExtension($f['name'], $f['pfad']),
            'mime'       => $f['mime'],
            'created_at' => appDate($f['created_at']),
        ], $files),
    ]);
});

/**
 * Legt ein neues Mitglied an -- JSON-Äquivalent von POST /portal/members. Erwartet dieselben
 * Pflichtfelder (first_name, last_name, email, address, zip, city) sowie die sechs rechtlichen
 * Zustimmungen (zustimmung_*, siehe $consentFields unten) im JSON-Body; optionale
 * Zählpunkt-Felder wie im Web-Formular (add_bezug_zp/add_einspeisung_zp + *_zaehlpunkt_nr).
 * Nutzt dieselbe createMemberRecord()-Logik wie das Web-Portal (KdNr-Vergabe, Erstlogin-Mail).
 */
$router->post('/api/v1/manager/members', function () {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    DB::setCommunity($ctx['community_id']);
    $communityId = $ctx['community_id'];

    $body = jsonBody();

    $required = ['first_name', 'last_name', 'email', 'address', 'zip', 'city'];
    foreach ($required as $f) {
        if (empty(trim((string)($body[$f] ?? '')))) {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte alle Pflichtfelder ausfüllen.']);
            return;
        }
    }

    $iban = trim((string)($body['member_iban'] ?? ''));
    if ($iban !== '' && !validateIban($iban)) {
        http_response_code(400);
        echo json_encode(['error' => 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).']);
        return;
    }

    $znrBezugNew = null;
    $znrEinspNew = null;
    if (!empty($body['add_bezug_zp'])) {
        $znrBezugNew = strtoupper(trim((string)($body['bezug_zaehlpunkt_nr'] ?? '')));
        if ($znrBezugNew === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte die Zählpunktnummer für den Bezugs-Zählpunkt angeben (oder add_bezug_zp weglassen).']);
            return;
        }
    }
    if (!empty($body['add_einspeisung_zp'])) {
        $znrEinspNew = strtoupper(trim((string)($body['einspeisung_zaehlpunkt_nr'] ?? '')));
        if ($znrEinspNew === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte die Zählpunktnummer für den Einspeise-Zählpunkt angeben (oder add_einspeisung_zp weglassen).']);
            return;
        }
    }
    if ($znrBezugNew && $znrEinspNew && $znrBezugNew === $znrEinspNew) {
        http_response_code(400);
        echo json_encode(['error' => 'Bezugs- und Einspeise-Zählpunkt dürfen nicht dieselbe Zählpunktnummer haben.']);
        return;
    }
    foreach (array_filter([$znrBezugNew, $znrEinspNew]) as $znrToCheck) {
        $znrOwner = DB::fetchOne(
            "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
             JOIN members m ON m.id = mp.member_id
             WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ?",
            [$communityId, $znrToCheck]
        );
        if ($znrOwner) {
            http_response_code(400);
            echo json_encode(['error' => 'Die Zählpunktnummer ' . $znrToCheck . ' ist bereits vergeben — an '
                . $znrOwner['first_name'] . ' ' . $znrOwner['last_name'] . ' (KdNr ' . ($znrOwner['kundennummer'] ?? '—') . ').']);
            return;
        }
    }

    $consentFields = [
        'zustimmung_mitgliedschaft', 'zustimmung_vollmacht', 'zustimmung_widerrufsfrist',
        'zustimmung_email_kommunikation', 'zustimmung_datenschutz', 'zustimmung_agb',
    ];
    foreach ($consentFields as $cf) {
        if (empty($body[$cf])) {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte alle sechs rechtlichen Zustimmungen bestätigen, bevor das Mitglied angelegt wird.']);
            return;
        }
    }

    $result = createMemberRecord($communityId, array_merge($body, ['andere_eeg' => !empty($body['andere_eeg'])]));
    logAudit($communityId, 'member.create', 'member', $result['member_id'],
        'Mitglied ' . trim((string)$body['first_name']) . ' ' . trim((string)$body['last_name']) . ' über die App angelegt (KdNr ' . $result['kundennummer'] . ')');

    if ($znrBezugNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrBezugNew, 'consumer', [
            'meter_code'          => trim((string)($body['bezug_meter_code'] ?? '')),
            'jahresverbrauch_kwh' => $body['bezug_jahresverbrauch_kwh'] ?? '',
        ]);
    }
    if ($znrEinspNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrEinspNew, 'producer', [
            'meter_code'               => trim((string)($body['einspeisung_meter_code'] ?? '')),
            'engpassleistung_kw'       => $body['einspeisung_engpassleistung_kw'] ?? '',
            'geplante_einspeisung_kwh' => $body['einspeisung_geplante_einspeisung_kwh'] ?? '',
        ]);
    }

    echo json_encode([
        'status'        => 'ok',
        'member_id'     => $result['member_id'],
        'kundennummer'  => $result['kundennummer'],
        'invite_sent'   => $result['invite_sent'],
        'temp_password' => $result['temp_password'],
    ]);
});

/** Bearbeitet Stammdaten eines Mitglieds -- JSON-Äquivalent von POST /portal/members/:id/edit. */
$router->post('/api/v1/manager/members/:id', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    $body = jsonBody();
    $required = ['first_name', 'last_name', 'address', 'zip', 'city'];
    foreach ($required as $f) {
        if (empty(trim((string)($body[$f] ?? '')))) {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte alle Pflichtfelder ausfüllen.']);
            return;
        }
    }

    $iban = trim((string)($body['member_iban'] ?? ''));
    if ($iban !== '' && !validateIban($iban)) {
        http_response_code(400);
        echo json_encode(['error' => 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).']);
        return;
    }

    $mandatsreferenz = $member['mandatsreferenz'];
    if ($iban !== '' && empty($mandatsreferenz)) {
        $mandatsreferenz = 'S00000F' . date('Y') . 'A' . $member['kundennummer'];
    }

    DB::execute(
        'UPDATE members SET salutation=?, titel=?, first_name=?, last_name=?, company_name=?, address=?, zip=?, city=?,
         phone=?, invoice_uid=?, member_iban=?, member_bic=?, kontoinhaber=?, konto_adresse=?, mandatsreferenz=?,
         member_since=?, member_until=?,
         geburtsdatum=?, stromlieferant=?, speicher_status=?, speicher_kwh=?, andere_eeg=?, andere_eeg_name=?,
         email_anrede_mode=?
         WHERE id=?',
        [
            $body['salutation'] ?? null,
            trim((string)($body['titel'] ?? '')) ?: null,
            trim((string)$body['first_name']),
            trim((string)$body['last_name']),
            trim((string)($body['company_name'] ?? '')) ?: null,
            trim((string)$body['address']),
            trim((string)$body['zip']),
            trim((string)$body['city']),
            trim((string)($body['phone'] ?? '')) ?: null,
            trim((string)($body['invoice_uid'] ?? '')) ?: null,
            $iban ?: null,
            trim((string)($body['member_bic'] ?? '')) ?: null,
            trim((string)($body['kontoinhaber'] ?? '')) ?: null,
            trim((string)($body['konto_adresse'] ?? '')) ?: null,
            $mandatsreferenz,
            $body['member_since'] ?: date('Y-m-d'),
            ($body['member_until'] ?? '') ?: '2099-12-31',
            ($body['geburtsdatum'] ?? '') ?: null,
            trim((string)($body['stromlieferant'] ?? '')) ?: null,
            ($body['speicher_status'] ?? '') ?: null,
            ($body['speicher_kwh'] ?? '') !== '' ? (float)$body['speicher_kwh'] : null,
            !empty($body['andere_eeg']) ? 'true' : 'false',
            trim((string)($body['andere_eeg_name'] ?? '')) ?: null,
            in_array($body['email_anrede_mode'] ?? 'auto', ['auto', 'herr', 'frau', 'familie'], true) ? ($body['email_anrede_mode'] ?? 'auto') : 'auto',
            $params['id'],
        ]
    );
    $memberAfter = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    $memberChanges = auditDiff($member, $memberAfter ?? [], [
        'salutation' => 'Anrede', 'titel' => 'Titel', 'first_name' => 'Vorname', 'last_name' => 'Nachname',
        'company_name' => 'Firma', 'address' => 'Adresse', 'zip' => 'PLZ', 'city' => 'Ort', 'phone' => 'Telefon',
        'invoice_uid' => 'UID', 'member_iban' => 'IBAN', 'member_bic' => 'BIC', 'kontoinhaber' => 'Kontoinhaber',
        'konto_adresse' => 'Konto-Adresse', 'mandatsreferenz' => 'Mandatsreferenz', 'member_since' => 'Mitglied seit',
        'member_until' => 'Mitglied bis', 'geburtsdatum' => 'Geburtsdatum', 'stromlieferant' => 'Stromlieferant',
        'speicher_status' => 'Speicher', 'speicher_kwh' => 'Speicher kWh', 'andere_eeg' => 'Andere EEG',
        'andere_eeg_name' => 'Andere-EEG-Name', 'email_anrede_mode' => 'E-Mail-Anrede-Modus',
    ]);
    $memberName = trim(($memberAfter['first_name'] ?? '') . ' ' . ($memberAfter['last_name'] ?? ''));
    if (!empty($memberChanges)) {
        logAuditDiff($member['community_id'], 'member.update', 'member', $params['id'], $memberChanges,
            'Mitglied ' . $memberName . ' (App):');
    } else {
        logAudit($member['community_id'], 'member.update', 'member', $params['id'],
            'Mitglied ' . $memberName . ' über die App gespeichert (keine Änderung)');
    }
    echo json_encode(['status' => 'ok']);
});

/**
 * Datei-Upload für ein Mitglied (Ausweis-Scan, Beitrittserklärung, ...) -- multipart/form-data
 * mit Feld "file" (+ optionalem Feld "name" für die Anzeige-Bezeichnung), JSON-Äquivalent von
 * POST /portal/members/:id/files. Standard-multipart statt Base64-in-JSON, damit sich iOS'
 * URLSession-Multipart-Upload direkt nutzen lässt (kein 33% Base64-Overhead bei z.B. einem
 * mehrere MB großen Ausweis-Scan-Foto).
 */
$router->post('/api/v1/manager/members/:id/files', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Datei-Upload gefunden.']);
        return;
    }

    $displayName = trim((string)($_POST['name'] ?? '')) ?: basename($_FILES['file']['name']);
    $origExt = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $dir = '/var/www/html/storage/uploads/members/' . $params['id'];
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $storedName = bin2hex(random_bytes(16)) . ($origExt ? '.' . strtolower($origExt) : '');
    $destPath = $dir . '/' . $storedName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht gespeichert werden.']);
        return;
    }

    try {
        $sha256 = hash_file('sha256', $destPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Datei konnte nach dem Upload nicht gelesen werden (sha256 fehlgeschlagen).');
        }
        $file = DB::fetchOne(
            'INSERT INTO member_files (community_id, member_id, name, pfad, mime, sha256, hochgeladen_von)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $member['community_id'],
                $params['id'],
                $displayName,
                $destPath,
                $_FILES['file']['type'] ?: null,
                $sha256,
                $ctx['user_id'],
            ]
        );
    } catch (\Throwable $e) {
        unlink($destPath);
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht in der Datenbank gespeichert werden.']);
        return;
    }

    echo json_encode(['status' => 'ok', 'id' => $file['id']]);
});

$router->get('/api/v1/manager/members/:id/files/:fileid/download', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ? AND community_id = ?',
        [$params['fileid'], $params['id'], $member['community_id']]
    );
    if (!$file || !is_file($file['pfad'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden.']);
        return;
    }
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
});

/** Profilbild eines Mitglieds setzen (Obmann-Aktion, multipart-Feld "photo"). */
$router->post('/api/v1/manager/members/:id/photo', function ($params) {
    $ctx = AppApiAuth::requireManagerAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $member = requireAppManagedMember($ctx['community_id'], $params['id']);
    if (!$member) return;

    if (!isset($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Bild übermittelt.']);
        return;
    }
    $err = saveMemberPhoto($params['id'], $_FILES['photo']);
    if ($err !== null) {
        http_response_code(400);
        echo json_encode(['error' => 'Profilbild konnte nicht gespeichert werden.']);
        return;
    }
    echo json_encode(['status' => 'ok']);
});

// ─── Mitglieder-App: Plattform-Admin-Endpunkte (role: admin, seit migrate_20260902.sql) ──────
// JSON-Äquivalente der /admin/*-Web-Routen -- wirken plattformweit über ALLE EEGs hinweg, nicht
// auf eine einzelne Community beschränkt (der Token trägt deshalb kein community_id). Sensible
// Werte (Client-Secret, MQTT-Passwort, EDA-Portal-Passwort) werden wie im Web NIE im Klartext
// zurückgegeben (nur ob gesetzt), Update-Felder folgen demselben "leer lassen = unverändert"-
// Prinzip wie /admin/mail-settings im Web (siehe $keep() dort).

/** Übersicht: alle EEGs + Nutzerzahl -- JSON-Äquivalent von GET /admin. */
$router->get('/api/v1/admin/overview', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $communities = DB::fetchAll('SELECT id, name, active, marktpartner_id FROM communities ORDER BY name');
    $userCount = (int)DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];

    echo json_encode([
        'communities' => array_map(fn($c) => [
            'id'              => $c['id'],
            'name'            => $c['name'],
            'active'          => (bool)$c['active'],
            'marktpartner_id' => $c['marktpartner_id'],
        ], $communities),
        'user_count' => $userCount,
    ]);
});

/** Alle Nutzer + ihre Rollen -- JSON-Äquivalent des Nutzer-Teils von GET /admin. */
$router->get('/api/v1/admin/users', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $users = DB::fetchAll('SELECT id, email, first_name, last_name, active FROM users ORDER BY last_name, first_name');
    $allRoles = DB::fetchAll('SELECT ur.user_id, ur.role, ur.community_id, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id');
    $roleMap = [];
    foreach ($allRoles as $r) { $roleMap[$r['user_id']][] = $r; }

    echo json_encode(['users' => array_map(fn($u) => [
        'id'         => $u['id'],
        'email'      => $u['email'],
        'name'       => trim($u['first_name'] . ' ' . $u['last_name']),
        'active'     => (bool)$u['active'],
        'roles'      => array_map(fn($r) => [
            'role'           => $r['role'],
            'community_id'   => $r['community_id'],
            'community_name' => $r['community_name'],
        ], $roleMap[$u['id']] ?? []),
    ], $users)]);
});

/** Detail eines Nutzers inkl. Rollen -- JSON-Äquivalent von GET /admin/users/:id. */
$router->get('/api/v1/admin/users/:id', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $user = DB::fetchOne('SELECT id, email, first_name, last_name, active FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Nutzer nicht gefunden.']); return; }
    $roles = DB::fetchAll('SELECT ur.id, ur.role, ur.community_id, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id WHERE ur.user_id = ?', [$params['id']]);

    echo json_encode([
        'user' => [
            'id'         => $user['id'],
            'email'      => $user['email'],
            'name'       => trim($user['first_name'] . ' ' . $user['last_name']),
            'active'     => (bool)$user['active'],
        ],
        'roles' => array_map(fn($r) => [
            'role_id'        => $r['id'],
            'role'           => $r['role'],
            'community_id'   => $r['community_id'],
            'community_name' => $r['community_name'],
        ], $roles),
    ]);
});

/** Fügt einem Nutzer eine Rolle hinzu -- JSON-Äquivalent von POST /admin/users/:id/roles. */
$router->post('/api/v1/admin/users/:id/roles', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $role = (string)($body['role'] ?? '');
    if (!in_array($role, ['platform_admin', 'manager', 'member'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Rolle.']);
        return;
    }
    $communityId = !empty($body['community_id']) ? (string)$body['community_id'] : null;
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $params['id'], $role]
    );
    logAudit($communityId, 'user_role.add', 'user', $params['id'], "Rolle \"$role\" hinzugefügt.");
    echo json_encode(['status' => 'ok']);
});

/** Entfernt eine Rollenzuweisung -- JSON-Äquivalent von POST /admin/users/:id/roles/delete. */
$router->post('/api/v1/admin/users/:id/roles/delete', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $roleId = (string)($body['role_id'] ?? '');
    // Es muss immer mindestens eine platform_admin-Rolle übrig bleiben, sonst kann sich niemand
    // mehr als Admin einloggen (weder web noch app) -- exakt dieselbe Schutzregel wie im Web.
    $isLastPlatformAdminRole = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$roleId]
    );
    if ($isLastPlatformAdminRole) {
        http_response_code(400);
        echo json_encode(['error' => 'Dies ist die letzte verbleibende Plattform-Admin-Rolle und kann nicht entfernt werden.']);
        return;
    }
    DB::execute('DELETE FROM user_roles WHERE id = ?', [$roleId]);
    logAudit(null, 'user_role.remove', 'user', $params['id'], 'Rollenzuweisung entfernt.');
    echo json_encode(['status' => 'ok']);
});

/** Löscht einen Login-Account -- JSON-Äquivalent von POST /admin/users/:id/delete. */
$router->post('/api/v1/admin/users/:id/delete', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    if ($params['id'] === $ctx['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Der eigene Account kann nicht gelöscht werden.']);
        return;
    }
    $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); echo json_encode(['error' => 'Nutzer nicht gefunden.']); return; }

    $isLastPlatformAdmin = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$params['id']]
    );
    if ($isLastPlatformAdmin) {
        http_response_code(400);
        echo json_encode(['error' => 'Dieser Account ist der letzte verbleibende Plattform-Admin und kann nicht gelöscht werden.']);
        return;
    }
    DB::execute('DELETE FROM users WHERE id = ?', [$params['id']]);
    logAudit(null, 'user.delete', 'user', $params['id'], 'Login-Account gelöscht.');
    echo json_encode(['status' => 'ok']);
});

/** Detail einer EEG inkl. Mitgliederliste -- JSON-Äquivalent von GET /admin/communities/:id. */
$router->get('/api/v1/admin/communities/:id', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'EEG nicht gefunden.']); return; }
    // members hat Row-Level Security -- Ziel-Community ist hier schon aus der URL bekannt, kein
    // Henne-Ei-Problem wie z.B. bei requireMemberAccess(), einfach direkt setzen.
    DB::setCommunity($params['id']);
    $members = DB::fetchAll(
        'SELECT m.id, m.kundennummer, m.first_name, m.last_name, m.company_name, m.email, m.status
         FROM members m WHERE m.community_id = ?
         ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name',
        [$params['id']]
    );

    echo json_encode([
        'community' => [
            'id'                 => $community['id'],
            'name'               => $community['name'],
            'slug'               => $community['slug'],
            'marktpartner_id'    => $community['marktpartner_id'],
            'zvr_number'         => $community['zvr_number'],
            'address'            => $community['address'],
            'iban'               => $community['iban'],
            'bic'                => $community['bic'],
            'active'             => (bool)$community['active'],
            'eda_login_email'    => $community['eda_login_email'],
            'eda_login_password_set' => !empty($community['eda_login_password_enc']),
        ],
        'members' => array_map(fn($m) => [
            'id'            => $m['id'],
            'kundennummer'  => $m['kundennummer'],
            'name'          => trim($m['first_name'] . ' ' . $m['last_name']),
            'company_name'  => $m['company_name'],
            'email'         => $m['email'],
            'status'        => $m['status'],
        ], $members),
    ]);
});

/** Legt eine neue EEG an -- JSON-Äquivalent von POST /admin/communities. */
$router->post('/api/v1/admin/communities', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte einen Namen angeben.']);
        return;
    }
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    $community = DB::fetchOne(
        'INSERT INTO communities (name, slug, marktpartner_id, address) VALUES (?, ?, ?, ?) RETURNING id',
        [$name, $slug, $body['marktpartner_id'] ?? null, $body['address'] ?? null]
    );
    logAudit(null, 'community.create', 'community', $community['id'], 'EEG "' . $name . '" angelegt.');
    echo json_encode(['status' => 'ok', 'id' => $community['id']]);
});

/** Bearbeitet eine EEG -- JSON-Äquivalent von POST /admin/communities/:id. */
$router->post('/api/v1/admin/communities/:id', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $current = DB::fetchOne('SELECT eda_login_password_enc FROM communities WHERE id = ?', [$params['id']]);
    if (!$current) { http_response_code(404); echo json_encode(['error' => 'EEG nicht gefunden.']); return; }
    // EDA-Portal-Passwort nur überschreiben, wenn tatsächlich ein neues mitgeschickt wurde --
    // gleiches "leer = unverändert"-Prinzip wie im Web (siehe /admin/communities/:id).
    $newEdaPassword = trim((string)($body['eda_login_password'] ?? ''));
    $edaPasswordEnc = $newEdaPassword !== '' ? encryptSecret($newEdaPassword) : ($current['eda_login_password_enc'] ?? null);

    DB::execute(
        'UPDATE communities SET name=?, marktpartner_id=?, zvr_number=?, address=?, iban=?, bic=?, active=?,
             eda_login_email=?, eda_login_password_enc=? WHERE id=?',
        [
            trim((string)($body['name'] ?? '')),
            trim((string)($body['marktpartner_id'] ?? '')) ?: null,
            trim((string)($body['zvr_number'] ?? '')) ?: null,
            trim((string)($body['address'] ?? '')) ?: null,
            trim((string)($body['iban'] ?? '')) ?: null,
            trim((string)($body['bic'] ?? '')) ?: null,
            !empty($body['active']) ? 'true' : 'false',
            trim((string)($body['eda_login_email'] ?? '')) ?: null,
            $edaPasswordEnc,
            $params['id'],
        ]
    );
    logAudit($params['id'], 'community.update', 'community', $params['id'], 'EEG "' . trim((string)($body['name'] ?? '')) . '" bearbeitet.');
    echo json_encode(['status' => 'ok']);
});

/**
 * Löscht eine EEG UNWIDERRUFLICH inkl. aller Mitglieder/Verträge/Zählpunkte/Rechnungen
 * (ON DELETE CASCADE) -- JSON-Äquivalent von POST /admin/communities/:id/delete. Die App MUSS
 * vor diesem Aufruf eine eigene, deutliche Bestätigung einholen (z. B. Name der EEG erneut
 * eintippen lassen), genau wie destruktive Aktionen im Web-Portal ein JS-confirm() haben.
 */
$router->post('/api/v1/admin/communities/:id/delete', function ($params) {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $community = DB::fetchOne('SELECT id, name FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); echo json_encode(['error' => 'EEG nicht gefunden.']); return; }
    DB::execute('DELETE FROM communities WHERE id = ?', [$params['id']]);
    logAudit(null, 'community.delete', 'community', $params['id'],
        'EEG "' . $community['name'] . '" (ID ' . $community['id'] . ') endgültig gelöscht inkl. aller Mitglieder, Verträge, Zählpunkte und Rechnungen.');
    echo json_encode(['status' => 'ok']);
});

/** Aktivitätslog, optional gefiltert nach einer EEG -- JSON-Äquivalent von GET /admin/log
 *  (ohne das Markdown-Export-Pendant -- siehe APP_PARITY_BACKLOG.md). */
$router->get('/api/v1/admin/log', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $filterCommunity = $_GET['community_id'] ?? '';
    $params = []; $where = '1=1';
    if ($filterCommunity !== '') { $where .= ' AND al.community_id = ?'; $params[] = $filterCommunity; }
    $entries = DB::fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, c.name AS community_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN communities c ON c.id = al.community_id
         WHERE $where ORDER BY al.created_at DESC LIMIT 500",
        $params
    );
    echo json_encode(['entries' => array_map(fn($e) => [
        'id'             => $e['id'],
        'created_at'     => appDate($e['created_at']),
        'user_name'      => trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: ($e['email'] ?? 'System'),
        'community_name' => $e['community_name'],
        'aktion'         => $e['aktion'],
        'entity_typ'     => $e['entity_typ'],
        'entity_id'      => $e['entity_id'],
        'beschreibung'   => $e['beschreibung'],
        'ist_fehler'     => (bool)$e['ist_fehler'],
    ], $entries)]);
});

/**
 * Gesammelte Plattform-Einstellungen (Mail/Microsoft Graph, Mail-Vorlagen, MQTT, Plattform-
 * Technik) in EINER Antwort -- JSON-Äquivalent der kombinierten Seite GET /admin/mail-settings.
 * Secrets (client_secret, mqtt_password, eda-Passwort) werden NIE im Klartext zurückgegeben,
 * nur als "..._set": true/false, exakt wie das Web-Formular sie nie vorbefüllt.
 */
$router->get('/api/v1/admin/settings', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $mail = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $templates = DB::fetchAll('SELECT key, subject, body_html, updated_at FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    try { $mqtt = DB::fetchOne('SELECT * FROM platform_mqtt_config WHERE id = 1'); } catch (\Throwable $e) { $mqtt = null; }
    try { $apns = DB::fetchOne('SELECT * FROM platform_apns_config WHERE id = 1'); } catch (\Throwable $e) { $apns = null; }

    echo json_encode([
        'mail' => $mail ? [
            'tenant_id'                   => $mail['tenant_id'],
            'client_id'                   => $mail['client_id'],
            'client_secret_set'           => !empty($mail['client_secret']),
            'sender_address'              => $mail['sender_address'],
            'reply_to'                    => $mail['reply_to'],
            'signature_html'              => $mail['signature_html'],
            'signature_logo_set'          => !empty($mail['signature_logo_base64']),
            'backup_alert_email_1'        => $mail['backup_alert_email_1'],
            'backup_alert_email_2'        => $mail['backup_alert_email_2'],
            'support_notification_email'  => $mail['support_notification_email'],
            'eda_import_mailbox_address'  => $mail['eda_import_mailbox_address'],
        ] : null,
        'mail_templates' => array_map(fn($t) => [
            'key'        => $t['key'],
            'subject'    => $t['subject'],
            'body_html'  => $t['body_html'],
            'updated_at' => appDate($t['updated_at']),
        ], $templates),
        'platform' => $platformSettings ? [
            'test_mode'                  => !empty($platformSettings['test_mode']),
            'esp_offline_after_minutes'  => (int)($platformSettings['esp_offline_after_minutes'] ?? 5),
        ] : null,
        'mqtt' => $mqtt ? [
            'mqtt_user'          => $mqtt['mqtt_user'],
            'mqtt_password_set'  => !empty($mqtt['mqtt_password']),
            'pending_apply'      => !empty($mqtt['pending_apply']),
            'applied_at'         => appDate($mqtt['applied_at'] ?? null),
        ] : null,
        'apns' => $apns ? [
            'team_id'         => $apns['team_id'],
            'key_id'          => $apns['key_id'],
            'bundle_id'       => $apns['bundle_id'],
            'private_key_set' => !empty($apns['private_key_enc']),
            'sandbox'         => (bool)$apns['sandbox'],
            'configured'      => Push::isConfigured(),
        ] : null,
    ]);
});

/** Aktualisiert die Mail-/Microsoft-Graph-Konfiguration -- JSON-Äquivalent von
 *  POST /admin/mail-settings (Logo-Upload bewusst NICHT enthalten, siehe APP_PARITY_BACKLOG.md). */
$router->post('/api/v1/admin/settings/mail', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $current = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $newSecret = trim((string)($body['client_secret'] ?? ''));
    $clientSecret = $newSecret !== '' ? $newSecret : ($current['client_secret'] ?? null);
    // "Feld war im Request gar nicht dabei" (unverändert) von "Feld war dabei, aber leer"
    // (wirklich löschen) unterscheiden -- exakt dasselbe Prinzip wie $keep() im Web (siehe
    // Kommentar dort zum Vorfall 13.08.2026, als das Fehlen dieser Unterscheidung
    // Zugangsdaten/Signatur bei jedem Speichern gelöscht hat).
    $keep = function (string $key) use ($body, $current) {
        return array_key_exists($key, $body) ? (trim((string)$body[$key]) ?: null) : ($current[$key] ?? null);
    };
    $supportEmail = array_key_exists('support_notification_email', $body)
        ? (trim((string)$body['support_notification_email']) ?: 'office@stromfueralle.at')
        : ($current['support_notification_email'] ?? 'office@stromfueralle.at');

    DB::execute(
        'UPDATE platform_mail_config
         SET tenant_id = ?, client_id = ?, client_secret = ?, sender_address = ?, reply_to = ?, signature_html = ?,
             backup_alert_email_1 = ?, backup_alert_email_2 = ?, support_notification_email = ?,
             eda_import_mailbox_address = ?, updated_at = now()
         WHERE id = 1',
        [
            $keep('tenant_id'), $keep('client_id'), $clientSecret, $keep('sender_address'), $keep('reply_to'),
            $keep('signature_html'), $keep('backup_alert_email_1'), $keep('backup_alert_email_2'),
            $supportEmail, $keep('eda_import_mailbox_address'),
        ]
    );
    logAudit(null, 'mail_config.update', 'platform_mail_config', '1', 'Mail-Konfiguration über die App geändert.');
    echo json_encode(['status' => 'ok']);
});

/** Sendet eine Test-Mail mit der aktuellen Konfiguration -- JSON-Äquivalent von
 *  POST /admin/mail-settings/test. */
$router->post('/api/v1/admin/settings/mail/test', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $to = trim((string)($body['to'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
        return;
    }
    try {
        Mailer::send($to, 'Test-Mail von Strom für alle', '<p>Diese Test-Mail bestätigt, dass die Mail-Konfiguration funktioniert.</p>');
        logAudit(null, 'mail_config.test', null, null, 'Test-Mail an ' . $to . ' über die App versendet.');
        echo json_encode(['status' => 'ok']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Versand fehlgeschlagen: ' . $e->getMessage()]);
    }
});

/** Bearbeitet eine einzelne E-Mail-Vorlage -- JSON-Äquivalent von POST /admin/mail-templates. */
$router->post('/api/v1/admin/settings/mail-templates', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $key = (string)($body['key'] ?? '');
    if (!in_array($key, ['password_reset', 'invite', 'member_deactivated', 'contract_bezug', 'contract_einspeisung', 'contract_both', 'sepa_prenotification', 'mahnung'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unbekannte Vorlage.']);
        return;
    }
    DB::execute(
        'UPDATE platform_mail_templates SET subject = ?, body_html = ?, updated_at = now() WHERE key = ?',
        [trim((string)($body['subject'] ?? '')), (string)($body['body_html'] ?? ''), $key]
    );
    logAudit(null, 'mail_template.update', 'platform_mail_templates', $key, 'E-Mail-Vorlage „' . $key . '" über die App bearbeitet.');
    echo json_encode(['status' => 'ok']);
});

/** Setzt die MQTT-Zugangsdaten (Anwendung auf den Broker läuft asynchron über den Host-Cron,
 *  siehe scripts/mqtt_apply_pending.sh) -- JSON-Äquivalent von POST /admin/mqtt-settings. */
$router->post('/api/v1/admin/settings/mqtt', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $mqttUser = trim((string)($body['mqtt_user'] ?? '')) ?: 'eeg-device';
    $mqttPassword = trim((string)($body['mqtt_password'] ?? ''));
    if ($mqttPassword === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte ein Passwort angeben.']);
        return;
    }
    DB::execute(
        'UPDATE platform_mqtt_config SET mqtt_user = ?, mqtt_password = ?, pending_apply = true, updated_at = now() WHERE id = 1',
        [$mqttUser, $mqttPassword]
    );
    logAudit(null, 'mqtt_config.update', 'platform_mqtt_config', '1', 'MQTT-Zugangsdaten über die App geändert (Benutzer: ' . $mqttUser . ').');
    echo json_encode(['status' => 'ok']);
});

/** Broadcastet neue MQTT-Zugangsdaten an ALLE Geräte im Feld -- JSON-Äquivalent von
 *  POST /admin/mqtt-device-reconfig. */
$router->post('/api/v1/admin/settings/mqtt-device-reconfig', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $host = trim((string)($body['device_mqtt_host'] ?? ''));
    $port = (int)($body['device_mqtt_port'] ?? 0);
    $user = trim((string)($body['device_mqtt_user'] ?? ''));
    $pass = (string)($body['device_mqtt_pass'] ?? '');
    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte Host, Port, Benutzername und Passwort angeben.']);
        return;
    }
    $payload = json_encode(['mqtt_host' => $host, 'mqtt_port' => $port, 'mqtt_user' => $user, 'mqtt_pass' => $pass]);
    DB::execute(
        "UPDATE platform_mqtt_config SET device_reconfig_payload = ?, device_reconfig_requested_at = now() WHERE id = 1",
        [$payload]
    );
    logAudit(null, 'mqtt_config.device_reconfig', 'platform_mqtt_config', '1',
        "MQTT-Fernkonfiguration über die App an alle Geräte angestoßen (Host: $host:$port, Benutzer: $user).");
    echo json_encode(['status' => 'ok']);
});

/**
 * Setzt die APNs-Zugangsdaten für Push-Benachrichtigungen (Team-ID/Key-ID/Bundle-ID/.p8-Auth-Key
 * aus Patricks Apple-Developer-Account -- ohne diese echten Zugangsdaten bleibt
 * push_notifications_queue liegen, siehe Push::sendPending()). Kein Web-Portal-Äquivalent
 * (bewusst nur über die App/API eingerichtet, kein Formular in admin_mail_settings.php).
 * private_key erwartet den kompletten .p8-Dateiinhalt (PEM, inkl. BEGIN/END-Zeilen) und wird wie
 * andere Secrets in diesem Projekt (WLAN-Passwörter, EDA-Zugangsdaten) mit encryptSecret()
 * verschlüsselt abgelegt, nie im Klartext.
 */
$router->post('/api/v1/admin/settings/apns', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $body = jsonBody();
    $current = DB::fetchOne('SELECT * FROM platform_apns_config WHERE id = 1');
    $newKey = trim((string)($body['private_key'] ?? ''));
    $privateKeyEnc = $newKey !== '' ? encryptSecret($newKey) : ($current['private_key_enc'] ?? null);
    $keep = function (string $key) use ($body, $current) {
        return array_key_exists($key, $body) ? (trim((string)$body[$key]) ?: null) : ($current[$key] ?? null);
    };

    DB::execute(
        'UPDATE platform_apns_config SET team_id = ?, key_id = ?, bundle_id = ?, private_key_enc = ?, sandbox = ?, updated_at = now() WHERE id = 1',
        [$keep('team_id'), $keep('key_id'), $keep('bundle_id'), $privateKeyEnc, !empty($body['sandbox'])]
    );
    logAudit(null, 'apns_config.update', 'platform_apns_config', '1', 'APNs-Konfiguration (Push-Benachrichtigungen) über die App geändert.');
    echo json_encode(['status' => 'ok']);
});

/** Schickt eine Test-Push an alle eigenen (des aufrufenden Admin-Accounts) registrierten Geräte
 *  -- JSON-Äquivalent von POST /admin/mail-settings/test, nur für Push statt Mail. Erfordert,
 *  dass der Admin zuvor in der App selbst (Einstellungen -> Push aktivieren) sein Gerät über
 *  POST /api/v1/push/register registriert hat, sonst "Kein registriertes Gerät." */
$router->post('/api/v1/admin/settings/apns/test', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    if (!Push::isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'APNs ist noch nicht konfiguriert (Team-ID/Key-ID/Bundle-ID/Auth-Key fehlen).']);
        return;
    }
    DB::execute(
        'INSERT INTO push_notifications_queue (user_id, role, title, body, data)
         VALUES (?, ?, ?, ?, ?)',
        [$ctx['user_id'], 'admin', 'Test-Benachrichtigung', 'Push-Benachrichtigungen funktionieren.', json_encode(['type' => 'test'])]
    );
    try {
        $result = Push::sendPending();
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Versand fehlgeschlagen: ' . $e->getMessage()]);
        return;
    }
    if (($result['sent'] ?? 0) > 0) {
        logAudit(null, 'apns_config.test', null, null, 'Test-Push über die App versendet.');
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Versand fehlgeschlagen oder kein registriertes Gerät. Details im Aktivitätslog/Server-Log.']);
    }
});

/** Testmodus/Echtbetrieb umschalten -- JSON-Äquivalent von POST /admin/settings/test-mode. */
$router->post('/api/v1/admin/settings/test-mode', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $testMode = !empty($body['test_mode']);
    DB::execute('UPDATE platform_settings SET test_mode = ?, updated_at = now() WHERE id = 1', [$testMode ? 'true' : 'false']);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'Plattform über die App auf ' . ($testMode ? 'Testmodus' : 'Echtbetrieb') . ' umgestellt.');
    echo json_encode(['status' => 'ok']);
});

/** ESP-Offline-Schwelle setzen -- JSON-Äquivalent von POST /admin/settings/esp. */
$router->post('/api/v1/admin/settings/esp', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');
    $body = jsonBody();
    $minutes = (int)($body['esp_offline_after_minutes'] ?? 5);
    if ($minutes < 1) $minutes = 5;
    DB::execute('UPDATE platform_settings SET esp_offline_after_minutes = ?, updated_at = now() WHERE id = 1', [$minutes]);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'ESP-Offline-Schwelle über die App auf ' . $minutes . ' Minuten gesetzt.');
    echo json_encode(['status' => 'ok']);
});

/**
 * Backup-Status (Alter/Größe der letzten Sicherungen) -- JSON-Äquivalent von GET /admin/backups.
 * Rein lesend, wie im Web (Backup-Verzeichnis ist :ro gemountet).
 */
$router->get('/api/v1/admin/backups', function () {
    $ctx = AppApiAuth::requireAdminAuth();
    if (!$ctx) return;
    header('Content-Type: application/json; charset=UTF-8');

    $dir = '/var/www/html/backups';
    $status = null;
    if (is_readable($dir . '/last_backup.json')) {
        $status = json_decode((string)file_get_contents($dir . '/last_backup.json'), true) ?: null;
    }
    $arten = [
        'stamm' => ['label' => 'Stammdaten (Mitglieder, Rechnungen, Verträge)', 'glob' => 'eeg_stamm_*.dump'],
        'voll'  => ['label' => 'Datenbank vollständig (inkl. Messwerte)',        'glob' => 'eeg_2*.dump'],
        'full'  => ['label' => 'Komplettsicherung (Datenbank + Dateien)',        'glob' => 'eeg_full_*.tar.gz'],
    ];
    $result = [];
    $dirLesbar = is_dir($dir) && is_readable($dir);
    foreach ($arten as $key => $art) {
        $dateien = [];
        if ($dirLesbar) {
            foreach (glob($dir . '/' . $art['glob']) ?: [] as $pfad) {
                $dateien[] = ['name' => basename($pfad), 'bytes' => filesize($pfad) ?: 0, 'zeit' => appDate(date('c', filemtime($pfad) ?: 0))];
            }
            usort($dateien, fn($a, $b) => strcmp($b['zeit'] ?? '', $a['zeit'] ?? ''));
        }
        $result[$key] = ['label' => $art['label'], 'dateien' => $dateien];
    }
    echo json_encode(['status' => $status, 'arten' => $result, 'verzeichnis_lesbar' => $dirLesbar]);
});

// ─── Portal: Mitgliederverwaltung ───────────────────────
$router->get('/portal/members', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    // ESP-Fehlerstatus je Mitglied (für die neue "Zähler"-Spalte + Sidebar-Badge, Patrick
    // 30.07.2026): "Fehler" = mindestens ein aktiver Zählpunkt, der schon einmal gemeldet hat
    // (esp_last_seen_at gesetzt), aber entweder länger als die Offline-Schwelle nicht mehr
    // online war ODER online ist, dessen Zähler (P1-Signal) aber nicht erreichbar ist -- exakt
    // dieselbe Logik wie die Status-Kachelzeile im Obmann-Dashboard (espOfflineAfterMinutes()).
    $espOfflineMinutes = espOfflineAfterMinutes();
    // open_amount bewusst als eigene Subquery, NICHT als weiterer JOIN + SUM(...) FILTER: ein
    // zusätzlicher LEFT JOIN invoices NEBEN dem LEFT JOIN metering_points hätte (beides 1:n zu
    // members) ein klassisches Fan-out-Problem erzeugt -- bei z.B. 2 Zählpunkten UND 1 offener
    // Rechnung entstehen 2 kombinierte Zeilen (Kreuzprodukt), wodurch SUM(i.saldo_eur) dieselbe
    // Rechnung doppelt zählt. Genau das ließ "Offener Betrag" bei jedem Mitglied mit 2
    // Zählpunkten (eigener Bezugs- UND Einspeisungs-Zählpunkt) exakt doppelt so hoch erscheinen
    // wie bei einem Mitglied mit nur einem Zählpunkt (Patrick, 06.08.2026: "warum steht bei ein
    // paar schon 4,00€" bei nur einer einzigen Juli-Abrechnung). Eine Subquery ist vom
    // metering_points-JOIN völlig unabhängig und kann daher nicht mitmultipliziert werden.
    $members = DB::fetchAll(
        "SELECT m.*,
                COUNT(DISTINCT mp.id) AS metering_point_count,
                bool_or(mp.type IN ('consumer', 'prosumer')) FILTER (WHERE mp.active) AS hat_bezug,
                bool_or(mp.type IN ('producer', 'prosumer')) FILTER (WHERE mp.active) AS hat_einspeisung,
                COALESCE(
                    (SELECT SUM(i.saldo_eur) FROM invoices i
                     WHERE i.member_id = m.id AND i.saldo_eur > 0 AND i.sent_at IS NULL),
                    0
                ) AS open_amount,
                EXISTS(SELECT 1 FROM membership_applications ma WHERE ma.member_id = m.id) AS via_online,
                -- mp.meter_code IS NOT NULL zusätzlich zu mp.active: esp_last_seen_at/
                -- meter_reachable bleiben auf dem Zählpunkt stehen, auch wenn die Zählernummer
                -- später entfernt wurde (Zähler außer Betrieb, Zählpunkt selbst aber weiterhin
                -- aktiv) -- ohne diese Sperre zeigte die Zähler-Spalte für so ein Mitglied
                -- weiterhin OK/Fehler statt kein Zähler (Patrick, 09.08.2026).
                bool_or(mp.active AND mp.meter_code IS NOT NULL AND mp.esp_last_seen_at IS NOT NULL) AS hat_esp_bekannt,
                -- NICHT mehr zusätzlich auf mp.esp_online geprüft (Patrick, 19.08.2026: Status
                -- blinkte zwischen Mama/Papa trotz durchgehender Live-Daten alle 5s) -- die
                -- Recency von esp_last_seen_at allein ist das zuverlässigere Signal, seit
                -- insert_measurement() (mqtt-subscriber) diesen Zeitstempel bei JEDER Live-
                -- Messung mitzieht. esp_online selbst bleibt dagegen weiterhin nur eine
                -- Momentaufnahme des zuletzt empfangenen Status-Heartbeats und kann durch dessen
                -- MQTT-Last-Will-Testament bei einem kurzen Verbindungsaussetzer auf false
                -- hängen bleiben, bis der NÄCHSTE Heartbeat (nicht: die nächste Live-Nachricht)
                -- eintrifft -- ein zusätzliches AND mp.esp_online hätte also weiterhin genau die
                -- Fehlanzeige verursacht, die eigentlich behoben werden sollte. esp_online bleibt
                -- als Spalte/Diagnosewert erhalten (WLAN-Info-Anzeige), fließt hier aber
                -- bewusst nicht mehr ein.
                bool_or(
                    mp.active AND mp.meter_code IS NOT NULL AND mp.esp_last_seen_at IS NOT NULL AND
                    NOT (mp.esp_last_seen_at > now() - (? || ' minutes')::interval AND mp.meter_reachable)
                ) AS hat_esp_fehler,
                MAX(u.last_login_at) AS last_login_at,
                (SELECT mp2.zaehlpunkt_nr FROM metering_points mp2
                 WHERE mp2.member_id = m.id AND mp2.type IN ('consumer', 'prosumer') AND mp2.active = true
                 ORDER BY mp2.created_at LIMIT 1) AS znr_bezug,
                (SELECT mp2.zaehlpunkt_nr FROM metering_points mp2
                 WHERE mp2.member_id = m.id AND mp2.type IN ('producer', 'prosumer') AND mp2.active = true
                 ORDER BY mp2.created_at LIMIT 1) AS znr_einspeisung
         FROM members m
         LEFT JOIN users u ON u.id = m.user_id
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
        [$espOfflineMinutes, $communityId]
    );
    // Demo-Login (siehe migrate_20260905.sql): echte Mitgliederdaten hier NIE unmaskiert zeigen
    // (Patrick, 05.09.2026: "Bei Plattform, Admin und Obmann auch keine personenbezogenen
    // Daten") -- die beiden fiktiven Demo-Mitglieder selbst bleiben unverändert.
    $members = demoMaskMembers($members, Auth::isDemo());
    $contractsEnabled = contractsEnabled($communityId);
    require ROOT . '/src/views/pages/member_list.php';
});

$router->get('/portal/files', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $members = DB::fetchAll(
        "SELECT id, first_name, last_name, company_name, email, kundennummer
         FROM members WHERE community_id = ? ORDER BY kundennummer NULLS LAST, last_name, first_name",
        [$communityId]
    );
    require ROOT . '/src/views/pages/files_search.php';
});

$router->get('/portal/files/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];

    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$params['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$params['id'], $communityId]);
    $hasConsumer = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM metering_points WHERE member_id = ? AND type = 'consumer' AND active = true LIMIT 1",
        [$params['id']]
    );
    $hasProducer = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM metering_points WHERE member_id = ? AND type = 'producer' AND active = true LIMIT 1",
        [$params['id']]
    );

    // Neueste hochgeladene Datei je Kategorie (member_files ist bereits nach created_at DESC
    // sortiert, das erste Match pro Kategorie ist also automatisch das aktuellste).
    $filesByCategory = ['beitritt' => null, 'bezug' => null, 'einspeisung' => null, 'ausweis' => null];
    foreach ($member_files as $f) {
        $cat = matchFileCategory($f['name']);
        if ($cat && !$filesByCategory[$cat]) { $filesByCategory[$cat] = $f; }
    }

    require ROOT . '/src/views/pages/files_member.php';
});

$router->get('/portal/members/new', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    require ROOT . '/src/views/pages/member_form.php';
});

$router->post('/portal/members', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $required = ['first_name', 'last_name', 'email', 'address', 'zip', 'city'];
    foreach ($required as $f) {
        if (empty(trim($_POST[$f] ?? ''))) {
            $error = 'Bitte alle Pflichtfelder ausfüllen.';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }

    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban !== '' && !validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        require ROOT . '/src/views/pages/member_form.php';
        return;
    }

    // Optionale Zählpunkte gleich bei der Anlage (statt erst hinterher auf der Detailseite) --
    // Bezug und Einspeisung haben eigene Zählpunktnummern (AT...), auch wenn es derselbe
    // physische Zähler/dieselbe Zählernummer ist (Prosumer). Beide Checkboxen sind optional.
    $znrBezugNew = null;
    $znrEinspNew = null;
    if (!empty($_POST['add_bezug_zp'])) {
        $znrBezugNew = strtoupper(trim($_POST['bezug_zaehlpunkt_nr'] ?? ''));
        if ($znrBezugNew === '') {
            $error = 'Bitte die Zählpunktnummer für den Bezugs-Zählpunkt angeben (oder das Häkchen entfernen).';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }
    if (!empty($_POST['add_einspeisung_zp'])) {
        $znrEinspNew = strtoupper(trim($_POST['einspeisung_zaehlpunkt_nr'] ?? ''));
        if ($znrEinspNew === '') {
            $error = 'Bitte die Zählpunktnummer für den Einspeise-Zählpunkt angeben (oder das Häkchen entfernen).';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }
    if ($znrBezugNew && $znrEinspNew && $znrBezugNew === $znrEinspNew) {
        $error = 'Bezugs- und Einspeise-Zählpunkt dürfen nicht dieselbe Zählpunktnummer haben (dieselbe Zählernummer ist bei einem Prosumer dagegen normal).';
        require ROOT . '/src/views/pages/member_form.php';
        return;
    }
    foreach (array_filter([$znrBezugNew, $znrEinspNew]) as $znrToCheck) {
        $znrOwner = DB::fetchOne(
            "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
             JOIN members m ON m.id = mp.member_id
             WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ?",
            [$communityId, $znrToCheck]
        );
        if ($znrOwner) {
            $error = 'Die Zählpunktnummer ' . $znrToCheck . ' ist bereits vergeben — an '
                . $znrOwner['first_name'] . ' ' . $znrOwner['last_name'] . ' (KdNr ' . ($znrOwner['kundennummer'] ?? '—') . ').';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }

    $consentFields = [
        'zustimmung_mitgliedschaft', 'zustimmung_vollmacht', 'zustimmung_widerrufsfrist',
        'zustimmung_email_kommunikation', 'zustimmung_datenschutz', 'zustimmung_agb',
    ];
    foreach ($consentFields as $cf) {
        if (empty($_POST[$cf])) {
            $error = 'Bitte alle sechs rechtlichen Zustimmungen bestätigen, bevor das Mitglied angelegt wird.';
            require ROOT . '/src/views/pages/member_form.php';
            return;
        }
    }

    $email = strtolower(trim($_POST['email']));
    $result = createMemberRecord($communityId, array_merge($_POST, ['andere_eeg' => isset($_POST['andere_eeg'])]));
    logAudit($communityId, 'member.create', 'member', $result['member_id'],
        'Mitglied ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']) . ' angelegt (KdNr ' . $result['kundennummer'] . ')');

    if ($znrBezugNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrBezugNew, 'consumer', [
            'meter_code'          => trim($_POST['bezug_meter_code'] ?? ''),
            'jahresverbrauch_kwh' => $_POST['bezug_jahresverbrauch_kwh'] ?? '',
        ]);
    }
    if ($znrEinspNew) {
        createMeteringPointForMember($communityId, $result['member_id'], $znrEinspNew, 'producer', [
            'meter_code'               => trim($_POST['einspeisung_meter_code'] ?? ''),
            'engpassleistung_kw'       => $_POST['einspeisung_engpassleistung_kw'] ?? '',
            'geplante_einspeisung_kwh' => $_POST['einspeisung_geplante_einspeisung_kwh'] ?? '',
        ]);
    }

    // Erstlogin-Einladung wurde per E-Mail verschickt -> kein Temp-Passwort am Bildschirm nötig.
    if ($result['invite_sent']) {
        header('Location: /portal/members?success=invite_sent');
        exit;
    }

    // Fallback: Mailversand nicht konfiguriert/fehlgeschlagen (oder E-Mail existierte schon,
    // dann gibt's ohnehin kein Temp-Passwort) -- Temp-Passwort anzeigen, falls ein neuer User
    // angelegt wurde, damit der Manager die Zugangsdaten notfalls selbst weitergeben kann.
    if ($result['temp_password']) {
        $successTempPw = $result['temp_password'];
        $successEmail  = $email;
        $successInviteError = $result['invite_error'];
        $members = DB::fetchAll(
            "SELECT m.*,
                    COUNT(DISTINCT mp.id) AS metering_point_count,
                    bool_or(mp.type IN ('consumer', 'prosumer')) FILTER (WHERE mp.active) AS hat_bezug,
                    bool_or(mp.type IN ('producer', 'prosumer')) FILTER (WHERE mp.active) AS hat_einspeisung
             FROM members m
             LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
             WHERE m.community_id = ? GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
            [$communityId]
        );
        $contractsEnabled = contractsEnabled($communityId);
        require ROOT . '/src/views/pages/member_list.php';
        exit;
    }

    header('Location: /portal/members?success=1');
    exit;
});

$router->get('/portal/members/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    require ROOT . '/src/views/pages/member_form.php';
});

$router->post('/portal/members/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban !== '' && !validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        $member = array_merge($member, $_POST);
        require ROOT . '/src/views/pages/member_form.php';
        return;
    }

    // Mandatsreferenz erstmalig vergeben, sobald erstmals eine IBAN hinterlegt wird — danach unveränderlich
    $mandatsreferenz = $member['mandatsreferenz'];
    if ($iban !== '' && empty($mandatsreferenz)) {
        $mandatsreferenz = 'S00000F' . date('Y') . 'A' . $member['kundennummer'];
    }

    DB::execute(
        'UPDATE members SET salutation=?, titel=?, first_name=?, last_name=?, company_name=?, address=?, zip=?, city=?,
         phone=?, invoice_uid=?, member_iban=?, member_bic=?, kontoinhaber=?, konto_adresse=?, mandatsreferenz=?,
         member_since=?, member_until=?,
         geburtsdatum=?, stromlieferant=?, speicher_status=?, speicher_kwh=?, andere_eeg=?, andere_eeg_name=?,
         email_anrede_mode=?
         WHERE id=?',
        [
            $_POST['salutation'] ?? null,
            trim($_POST['titel'] ?? '') ?: null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['company_name'] ?? '') ?: null,
            trim($_POST['address']),
            trim($_POST['zip']),
            trim($_POST['city']),
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['invoice_uid'] ?? '') ?: null,
            $iban ?: null,
            trim($_POST['member_bic'] ?? '') ?: null,
            trim($_POST['kontoinhaber'] ?? '') ?: null,
            trim($_POST['konto_adresse'] ?? '') ?: null,
            $mandatsreferenz,
            $_POST['member_since'] ?: date('Y-m-d'),
            ($_POST['member_until'] ?? '') ?: '2099-12-31',
            ($_POST['geburtsdatum'] ?? '') ?: null,
            trim($_POST['stromlieferant'] ?? '') ?: null,
            ($_POST['speicher_status'] ?? '') ?: null,
            ($_POST['speicher_kwh'] ?? '') !== '' ? (float)$_POST['speicher_kwh'] : null,
            isset($_POST['andere_eeg']) ? 'true' : 'false',
            trim($_POST['andere_eeg_name'] ?? '') ?: null,
            in_array($_POST['email_anrede_mode'] ?? 'auto', ['auto', 'herr', 'frau', 'familie'], true) ? ($_POST['email_anrede_mode'] ?? 'auto') : 'auto',
            $params['id'],
        ]
    );
    $memberAfter = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    $memberChanges = auditDiff($member, $memberAfter ?? [], [
        'salutation' => 'Anrede', 'titel' => 'Titel', 'first_name' => 'Vorname', 'last_name' => 'Nachname',
        'company_name' => 'Firma', 'address' => 'Adresse', 'zip' => 'PLZ', 'city' => 'Ort', 'phone' => 'Telefon',
        'invoice_uid' => 'UID', 'member_iban' => 'IBAN', 'member_bic' => 'BIC', 'kontoinhaber' => 'Kontoinhaber',
        'konto_adresse' => 'Konto-Adresse', 'mandatsreferenz' => 'Mandatsreferenz', 'member_since' => 'Mitglied seit',
        'member_until' => 'Mitglied bis', 'geburtsdatum' => 'Geburtsdatum', 'stromlieferant' => 'Stromlieferant',
        'speicher_status' => 'Speicher', 'speicher_kwh' => 'Speicher kWh', 'andere_eeg' => 'Andere EEG',
        'andere_eeg_name' => 'Andere-EEG-Name', 'email_anrede_mode' => 'E-Mail-Anrede-Modus',
    ]);
    $memberName = trim(($memberAfter['first_name'] ?? '') . ' ' . ($memberAfter['last_name'] ?? ''));
    if (!empty($memberChanges)) {
        logAuditDiff($member['community_id'], 'member.update', 'member', $params['id'], $memberChanges,
            'Mitglied ' . $memberName . ':');
    } else {
        logAudit($member['community_id'], 'member.update', 'member', $params['id'],
            'Mitglied ' . $memberName . ' gespeichert (keine Änderung)');
    }
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

// Kein echter Hard-Delete für einzelne Mitglieder mehr (Aufbewahrungspflicht für Verträge/
// Dateien) -- siehe /deactivate weiter unten. Ein Hard-Delete gibt es nur noch komplett auf
// EEG-Ebene (/admin/communities/:id/delete), wenn die ganze EEG aufgelöst wird.

// Nur Plattform-Admins dürfen ein Mitglied-Login löschen (danach kann der Account kein
// Passwort mehr anfragen). Da users.email plattformweit eindeutig ist, wird die users-Zeile
// nur gelöscht, wenn der Account sonst KEINE Rolle mehr hat — sonst würde man einer Person
// versehentlich den Zugriff auf eine andere EEG entziehen, in der sie z.B. ebenfalls
// Mitglied ist.
$router->post('/portal/members/:id/delete-login', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!$member['user_id']) { http_response_code(404); echo 'Nicht gefunden'; return; }
    $communityId = $member['community_id'];
    $userId = $member['user_id'];

    DB::execute('UPDATE members SET user_id = NULL WHERE id = ?', [$params['id']]);
    DB::execute('DELETE FROM user_roles WHERE user_id = ? AND community_id = ?', [$userId, $communityId]);

    $remainingRoles = DB::fetchOne('SELECT COUNT(*) AS cnt FROM user_roles WHERE user_id = ?', [$userId])['cnt'];
    if ((int)$remainingRoles === 0) {
        DB::execute('DELETE FROM users WHERE id = ?', [$userId]);
    }

    logAudit($communityId, 'member.delete_login', 'member', $params['id'],
        'Login-Konto von ' . $member['first_name'] . ' ' . $member['last_name'] . ' entfernt (Mitglied bleibt bestehen)');

    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

/**
 * "Wirklich löschen": Soft-Deactivation statt Hard-Delete. Wegen der Aufbewahrungspflicht
 * bleiben Mitgliedsdaten, Verträge und Dateien vollständig erhalten -- nur der Login wird
 * gesperrt (users.active=false, falls ein Login existiert) und members.status auf
 * 'inactive' gesetzt, was auf der Detailseite den "Freigeben"-Button statt der
 * Löschen-Aktionen anzeigt. Das Mitglied wird per E-Mail informiert.
 */
$router->post('/portal/members/:id/deactivate', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    DB::execute("UPDATE members SET status = 'inactive' WHERE id = ?", [$params['id']]);
    if ($member['user_id']) {
        DB::execute('UPDATE users SET active = false WHERE id = ?', [$member['user_id']]);
    }
    logAudit($member['community_id'], 'member.deactivate', 'member', $params['id'],
        'Mitglied ' . $member['first_name'] . ' ' . $member['last_name'] . ' deaktiviert (Daten aus Aufbewahrungspflicht erhalten)');

    $mailError = null;
    try {
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('member_deactivated', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
        ],
            'Ihre Mitgliedschaft bei Strom für alle wurde deaktiviert',
            '<p>{{anrede}} {{nachname}},</p><p>Ihr Zugang wurde deaktiviert. Ihre Daten bleiben aus '
            . 'Aufbewahrungsgründen erhalten. Bitte wenden Sie sich zur Reaktivierung an Ihre EEG-Verwaltung.</p>'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
    } catch (\Throwable $e) {
        $mailError = $e->getMessage();
    }

    header('Location: /portal/members/' . $params['id'] . '?' . ($mailError
        ? 'error=mail&detail=' . urlencode($mailError)
        : 'success=' . urlencode('Mitglied deaktiviert — Benachrichtigung wurde per E-Mail verschickt.')));
    exit;
});

/** "Freigeben": Hebt eine über /deactivate gesetzte Deaktivierung wieder auf. */
$router->post('/portal/members/:id/reactivate', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Nur für Plattform-Admins.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    DB::execute("UPDATE members SET status = 'active' WHERE id = ?", [$params['id']]);
    if ($member['user_id']) {
        DB::execute('UPDATE users SET active = true WHERE id = ?', [$member['user_id']]);
    }
    logAudit($member['community_id'], 'member.reactivate', 'member', $params['id'],
        'Mitglied ' . $member['first_name'] . ' ' . $member['last_name'] . ' wieder freigegeben');

    header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Mitglied wieder freigegeben.'));
    exit;
});

$router->post('/portal/members/:id/reset-password', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!$member['user_id']) { http_response_code(404); echo 'Kein Login-Konto vorhanden.'; return; }
    $loginEmail = DB::fetchOne('SELECT email FROM users WHERE id = ?', [$member['user_id']])['email'];

    // 10 Minuten statt der 1-Stunden-Standardgültigkeit der Selbstbedienungs-"Passwort
    // vergessen"-Funktion, da dieser Link vom Manager direkt im Beisein/Auftrag des
    // Mitglieds ausgelöst wird und entsprechend kurzlebig sein soll.
    $token = Auth::createResetToken($loginEmail, 600);
    try {
        $link = htmlspecialchars(passwordResetLink($token));
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('password_reset', [
            'vorname'     => htmlspecialchars($member['first_name']),
            'anrede'      => htmlspecialchars($anrede['anrede']),
            'nachname'    => htmlspecialchars($anrede['nachname']),
            'link'        => $link,
            'gueltigkeit' => '10 Minuten',
        ],
            'Passwort zurücksetzen – Strom für alle',
            '<p>Liebes Mitglied,</p>'
            . '<p>über folgenden Link können Sie innerhalb der nächsten {{gueltigkeit}} ein neues Passwort vergeben:</p>'
            . '<p><a href="{{link}}">{{link}}</a></p>'
            . '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>'
        );
        Mailer::send($loginEmail, $mail['subject'], $mail['body']);
        header('Location: /portal/members/' . $params['id'] . '?success=reset_sent');
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Willkommens-E-Mail (Erstlogin-Link, 24h gültig) erneut senden -- für Mitglieder, die sich seit
 * dem Anlegen noch nie eingeloggt haben (z.B. weil die ursprüngliche Mail im Spam landete oder der
 * erste Link inzwischen abgelaufen ist). Bewusst eine eigene Route statt reset-password oben
 * wiederzuverwenden: andere Vorlage ("Willkommen" statt "Passwort zurücksetzen"), andere
 * Standard-Gültigkeit (24h statt 10min) und ein serverseitiger Schutz gegen Mehrfachnutzung durch
 * bereits aktive Mitglieder (siehe last_login_at-Prüfung unten).
 */
$router->post('/portal/members/:id/resend-invite', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!$member['user_id']) { http_response_code(404); echo 'Kein Login-Konto vorhanden.'; return; }
    $user = DB::fetchOne('SELECT email, last_login_at FROM users WHERE id = ?', [$member['user_id']]);
    if (!empty($user['last_login_at'])) {
        header('Location: /portal/members?error=' . urlencode('Dieses Mitglied hat sich bereits angemeldet -- bitte stattdessen "Passwort zurücksetzen" auf der Mitglieds-Detailseite verwenden.'));
        exit;
    }
    $token = Auth::createResetToken($user['email'], 86400);
    if (!$token) {
        header('Location: /portal/members?error=' . urlencode('Kein aktiver Login-Zugang für diese E-Mail-Adresse gefunden.'));
        exit;
    }
    try {
        $link = htmlspecialchars(passwordResetLink($token));
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('invite', [
            'vorname'     => htmlspecialchars($member['first_name']),
            'anrede'      => htmlspecialchars($anrede['anrede']),
            'nachname'    => htmlspecialchars($anrede['nachname']),
            'link'        => $link,
            'gueltigkeit' => '24 Stunden',
        ],
            'Willkommen bei Strom für alle – Zugang einrichten',
            '<p>{{anrede}} {{nachname}},</p>'
            . '<p>Ihr Zugang zum Mitgliederportal wurde angelegt. Bitte vergeben Sie über folgenden Link '
            . 'innerhalb der nächsten {{gueltigkeit}} Ihr persönliches Passwort:</p>'
            . '<p><a href="{{link}}">{{link}}</a></p>'
        );
        Mailer::send($user['email'], $mail['subject'], $mail['body']);
        header('Location: /portal/members?success=invite_resent');
    } catch (\Throwable $e) {
        header('Location: /portal/members?error=' . urlencode('E-Mail-Versand fehlgeschlagen: ' . $e->getMessage()));
    }
    exit;
});

$router->get('/portal/members/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // requireMemberAccess() scoped absichtlich NICHT über die aktive Rolle: Platform-Admins
    // müssen ein Mitglied auch dann ansehen können, wenn ihre aktuell aktive Rolle gerade eine
    // ANDERE EEG ist (z.B. von der EEG-Übersicht im Admin-Bereich aus) -- IDOR-Schutz erfolgt
    // dort explizit, setzt bei Erfolg auch gleich die RLS-Community.
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];
    // "Gelöschte" Zählpunkte sind nur soft-deaktiviert (active=false), damit historische
    // Abrechnungsperioden weiter nachvollziehbar bleiben -- auf der Mitglied-Detailseite
    // sollen sie aber wie erwartet aus der Liste verschwinden.
    $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$params['id']]);
    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$params['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$params['id'], $communityId]);
    $member['last_login_at'] = $member['user_id']
        ? (DB::fetchOne('SELECT last_login_at FROM users WHERE id = ?', [$member['user_id']])['last_login_at'] ?? null)
        : null;
    $latestFirmwareVersion = latestFirmwareVersion();
    // Demo-Login: echte Mitgliederdaten (inkl. Zählpunkte) maskieren, siehe /portal/members oben.
    $isDemo = Auth::isDemo();
    $member = demoMaskMember($member, $isDemo);
    $metering_points = demoMaskMeteringPoints($metering_points, $isDemo);
    require ROOT . '/src/views/pages/member_detail.php';
});

/**
 * WLAN-Diagnoseinfos eines Zählpunkts (SSID/IP/Passwort) auf Abruf statt beim Seitenaufbau
 * mitzuschicken -- das entschlüsselte Passwort landet so nicht unnötig im initialen HTML
 * (z.B. Browser-Cache, Screenshot der Seite), sondern nur wenn Obmann/Admin aktiv auf
 * "WLAN-Info anzeigen" klicken. Siehe docs/ESP_IDEEN.md Punkt 1.
 */
$router->get('/portal/members/:id/metering-points/:mpid/wifi-info', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    header('Content-Type: application/json; charset=UTF-8');
    // metering_points hat Row-Level Security (migrate_20260822.sql) -- ohne vorheriges
    // DB::setCommunity() liefert die eingeschränkte Laufzeit-Rolle grundsätzlich GAR KEINE
    // Zeile, auch bei korrekter ID (siehe requireMemberAccess()-Kommentar oben). Dieser
    // Endpunkt hat das ursprünglich übersehen ("Zählpunkt nicht gefunden" trotz existierendem
    // Zählpunkt) -- Fix analog zu requireMemberAccess()/dem Avatar-Endpunkt: Platform-Admin
    // probiert jede Community durch, alle anderen nutzen direkt ihre aktive Rolle.
    if (Auth::isPlatformAdmin()) {
        $mp = null;
        foreach (DB::fetchAll('SELECT id FROM communities') as $c) {
            DB::setCommunity($c['id']);
            $mp = DB::fetchOne(
                'SELECT wifi_ssid, wifi_ip, wifi_password_enc, esp_firmware_version, community_id
                 FROM metering_points WHERE id = ? AND member_id = ?',
                [$params['mpid'], $params['id']]
            );
            if ($mp) break;
        }
    } else {
        DB::setCommunity(Auth::activeCommunityId());
        $mp = DB::fetchOne(
            'SELECT wifi_ssid, wifi_ip, wifi_password_enc, esp_firmware_version, community_id
             FROM metering_points WHERE id = ? AND member_id = ?',
            [$params['mpid'], $params['id']]
        );
    }
    if (!$mp) { http_response_code(404); echo json_encode(['error' => 'Zählpunkt nicht gefunden']); return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $mp['community_id']) {
        http_response_code(403); echo json_encode(['error' => 'Kein Zugriff']); return;
    }
    // Demo-Login: das entschlüsselte WLAN-Passwort ist das ECHTE Heim-WLAN-Passwort des
    // Mitglieds -- niemals im Klartext zeigen, außer beim fiktiven Demo-Mitglied selbst
    // (Patrick, 06.09.2026). GET-Endpunkt, also von der POST-only Read-only-Sperre NICHT erfasst.
    if (Auth::isDemo()) {
        $isDemoMember = (bool)DB::fetchOne('SELECT is_demo FROM members WHERE id = ?', [$params['id']])['is_demo'];
        if (!$isDemoMember) {
            echo json_encode([
                'ssid'             => !empty($mp['wifi_ssid']) ? demoMaskFull((string)$mp['wifi_ssid']) : '',
                'ip'               => !empty($mp['wifi_ip']) ? demoMaskFull((string)$mp['wifi_ip']) : '',
                'password'         => !empty($mp['wifi_password_enc']) ? '••••••••' : '',
                'firmware_version' => $mp['esp_firmware_version'] ?? '',
                'latest_version'   => latestFirmwareVersion() ?? '',
            ]);
            return;
        }
    }
    echo json_encode([
        'ssid'             => $mp['wifi_ssid'] ?? '',
        'ip'               => $mp['wifi_ip'] ?? '',
        'password'         => decryptSecret($mp['wifi_password_enc']),
        'firmware_version' => $mp['esp_firmware_version'] ?? '',
        'latest_version'   => latestFirmwareVersion() ?? '',
    ]);
});

$router->post('/portal/members/:id/files', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /portal/members/' . $params['id'] . '?error=upload');
        exit;
    }

    $displayName = trim($_POST['name'] ?? '') ?: basename($_FILES['file']['name']);
    $origExt = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $dir = '/var/www/html/storage/uploads/members/' . $params['id'];
    if (!is_dir($dir)) { mkdir($dir, 0750, true); }
    $storedName = bin2hex(random_bytes(16)) . ($origExt ? '.' . strtolower($origExt) : '');
    $destPath = $dir . '/' . $storedName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
        header('Location: /portal/members/' . $params['id'] . '?error=upload');
        exit;
    }

    // Absichtlich try/catch statt einfach durchbrechen zu lassen: bei einem Schema-Problem
    // (unbekannte Alt-Spalte, siehe migrate_20260719.sql) landet man sonst in einem rohen
    // 500 ohne jeden Hinweis, was los ist. \Throwable statt nur \PDOException, weil auch ein
    // TypeError/Error (z.B. hash_file() liefert false bei nicht lesbarer Datei) sonst am
    // globalen Handler vorbei unkontrolliert durchläuft. So bekommt der Manager wenigstens
    // die konkrete Fehlermeldung angezeigt und kann sie weitergeben, statt dass wir blind
    // raten müssen.
    try {
        $sha256 = hash_file('sha256', $destPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Datei konnte nach dem Upload nicht gelesen werden (sha256 fehlgeschlagen).');
        }
        DB::execute(
            'INSERT INTO member_files (community_id, member_id, name, pfad, mime, sha256, hochgeladen_von)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $communityId,
                $params['id'],
                $displayName,
                $destPath,
                $_FILES['file']['type'] ?: null,
                $sha256,
                Auth::userId(),
            ]
        );
    } catch (\Throwable $e) {
        unlink($destPath);
        header('Location: /portal/members/' . $params['id'] . '?error=upload_db&detail=' . urlencode($e->getMessage()));
        exit;
    }

    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->get('/portal/members/:id/files/:fileid/download', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $file = DB::fetchOne(
        'SELECT * FROM member_files WHERE id = ? AND member_id = ? AND community_id = ?',
        [$params['fileid'], $params['id'], $member['community_id']]
    );
    if (!$file || !is_file($file['pfad'])) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(filenameWithExtension($file['name'], $file['pfad'])) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
    exit;
});

// Profilbild eines Mitglieds ansehen -- entweder das Mitglied selbst oder ein Manager der
// gleichen Community (keine Community-Prüfung nötig, wenn es das eigene Konto ist).
$router->get('/portal/members/:id/avatar', function ($params) {
    Auth::requireLogin();
    // members hat Row-Level Security -- die Community ist hier wie in requireMemberAccess()
    // erst NACH dem Laden bekannt (Henne-Ei-Problem), deshalb dasselbe Muster: Platform-Admin
    // versucht jede Community einzeln, alle anderen nutzen direkt ihre aktive Rolle (reicht
    // auch für "eigenes Avatar ansehen", da die eigene Mitgliedschaft immer zur eigenen
    // aktiven Rolle gehört).
    if (Auth::isPlatformAdmin()) {
        $member = null;
        foreach (DB::fetchAll('SELECT id FROM communities') as $c) {
            DB::setCommunity($c['id']);
            $member = DB::fetchOne('SELECT id, community_id, user_id, photo_path FROM members WHERE id = ?', [$params['id']]);
            if ($member) break;
        }
    } else {
        DB::setCommunity(Auth::activeCommunityId());
        $member = DB::fetchOne('SELECT id, community_id, user_id, photo_path FROM members WHERE id = ?', [$params['id']]);
    }
    if (!$member || !$member['photo_path']) { http_response_code(404); return; }

    $allowed = $member['user_id'] !== null && $member['user_id'] === Auth::userId();
    if (!$allowed) {
        $allowed = Auth::isManager() && (Auth::isPlatformAdmin() || Auth::activeCommunityId() === $member['community_id']);
    }
    if (!$allowed) { http_response_code(403); return; }
    if (!is_file($member['photo_path'])) { http_response_code(404); return; }

    header('Content-Type: ' . (mime_content_type($member['photo_path']) ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=3600');
    readfile($member['photo_path']);
    exit;
});

// Profilbild eines Login-Accounts ohne eigenen Mitgliedsdatensatz (Manager/Platform-Admin) --
// nur der Account selbst oder ein Platform-Admin darf es sehen.
$router->get('/portal/users/:id/avatar', function ($params) {
    Auth::requireLogin();
    if ($params['id'] !== Auth::userId() && !Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $user = DB::fetchOne('SELECT photo_path FROM users WHERE id = ?', [$params['id']]);
    if (!$user || !$user['photo_path'] || !is_file($user['photo_path'])) { http_response_code(404); return; }

    header('Content-Type: ' . (mime_content_type($user['photo_path']) ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=3600');
    readfile($user['photo_path']);
    exit;
});

$router->post('/portal/members/:id/photo', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    if (!isset($_FILES['photo'])) { header('Location: /portal/members/' . $params['id'] . '?error=upload'); exit; }

    $err = saveMemberPhoto($params['id'], $_FILES['photo']);
    if ($err === null) {
        header('Location: /portal/members/' . $params['id'] . '?success=1');
    } elseif (str_starts_with($err, 'upload_db:')) {
        header('Location: /portal/members/' . $params['id'] . '?error=upload_db&detail=' . urlencode(substr($err, 10)));
    } elseif ($err === 'phototype') {
        header('Location: /portal/members/' . $params['id'] . '?error=phototype');
    } else {
        header('Location: /portal/members/' . $params['id'] . '?error=upload');
    }
    exit;
});

$router->post('/portal/members/:id/metering-points', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];

    $znr = strtoupper(trim($_POST['zaehlpunkt_nr'] ?? ''));
    if (!$znr) { header('Location: /portal/members/' . $params['id'] . '?error=znr'); exit; }

    $existing = DB::fetchOne(
        "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
         JOIN members m ON m.id = mp.member_id
         WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ?",
        [$communityId, $znr]
    );
    if ($existing) {
        header('Location: /portal/members/' . $params['id'] . '?error=znr_duplicate&znr_owner='
            . urlencode($existing['first_name'] . ' ' . $existing['last_name'] . ' (KdNr ' . ($existing['kundennummer'] ?? '—') . ')'));
        exit;
    }

    $meterCode = trim($_POST['meter_code'] ?? '') ?: null;
    // Eine Zählernummer darf sehr wohl zu ZWEI aktiven Zählpunkten gehören: in Österreich haben
    // Bezug und Einspeisung eines Prosumers unterschiedliche Zählpunktnummern (AT...), teilen
    // sich aber denselben physischen Zähler/dieselbe Zählernummer (Patrick, 30.07.2026, nach
    // anfänglich falscher Annahme "nur ein Zählpunkt pro Zähler möglich"). Kein Blocken -- der
    // mqtt-subscriber teilt eingehende ESP-Daten automatisch korrekt auf beide Zählpunkte auf
    // (get_metering_points()), nur eine informative Postfach-Meldung zur Transparenz.
    if ($meterCode) {
        $sharedWith = DB::fetchOne(
            "SELECT 1 FROM metering_points WHERE community_id = ? AND meter_code = ? AND active = true",
            [$communityId, $meterCode]
        );
        if ($sharedWith) {
            notifyMeterCodeShared($communityId, $meterCode);
        }
    }

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, meter_code, jahresverbrauch_kwh, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
         ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
        [$communityId, $member['id'], $znr, $_POST['type'] ?? 'consumer', $meterCode, $jahresverbrauch, $engpassleistung, $geplanteEinsp]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

/** Baut die \item-Liste der Bezugs-Zählpunkte für den Bezugsvertrag. */
function bezugZpLines(array $mps): string
{
    return implode("\n", array_map(fn($mp) => '\\item ' . texEscape($mp['zaehlpunkt_nr']), $mps));
}

/** Voller Name inkl. Anrede und Titel für die Namensanzeige in den Vertragsvorlagen. */
function memberFullName(array $member): string
{
    $prefix = trim(($member['salutation'] ?? '') . ' ' . ($member['titel'] ?? ''));
    return ($prefix ? $prefix . ' ' : '') . $member['first_name'] . ' ' . $member['last_name'];
}

/**
 * Tarif für die Vertrags-Ansicht/Erneut-Versenden: vor der ersten Erstellung der aktuell
 * gültige, danach für immer der zum Erstellungszeitpunkt gültige Tarif -- sonst würde ein
 * bereits versendeter oder gar digital unterschriebener Vertrag bei jeder erneuten Ansicht
 * plötzlich andere Zahlen zeigen, sobald sich der Tarif später ändert.
 */
function contractTariff(string $communityId, ?string $generatedAt): ?array
{
    if ($generatedAt) {
        return DB::fetchOne(
            'SELECT * FROM tariff_config WHERE community_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1',
            [$communityId, $generatedAt]
        );
    }
    return DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
}

/**
 * Baut die Template-Variablen für die Bezugsvereinbarung. Gemeinsam genutzt von der
 * Ansichts-Route (Browser-Vorschau) und der "Jetzt senden"-Route (E-Mail-Anhang), damit
 * beide exakt denselben Vertragsinhalt erzeugen.
 */
function bezugsvereinbarungVars(array $member, array $community, ?array $tariff, string $zpLines, array $signature, array $memberSignature = ['var' => '', 'assets' => []]): array
{
    return [
        'EEG_NAME'                  => $community['name'],
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'       => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'                  => $community['iban'] ?? '--',
        'EEG_ORT'                   => extractOrtFromAddress($community['address']),
        'MITGLIED_NAME'             => memberFullName($member),
        'MITGLIED_ADRESSE'          => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'      => $member['city'],
        'MITGLIED_UID_ZEILE'        => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'MITGLIED_SEPA_MANDATSREFERENZ' => $member['mandatsreferenz'] ?? '--',
        'MITGLIED_IBAN'             => $member['member_iban'] ?? '--',
        'BEZUG_TARIF'               => $tariff ? number_format((float)$tariff['bezug_ct_kwh'], 4, ',', '.') : '--',
        'MITGLIEDSBEITRAG'          => $tariff ? number_format((float)$tariff['mitgliedsbeitrag_eur'], 2, ',', '.') : '--',
        'TARIF_GUELTIG_AB'          => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'RAW_ZAEHLPUNKTE_LISTE'     => $zpLines,
        // Frei in den EEG-Einstellungen konfigurierbar (communities.dashboard_url), da sich die
        // Verlinkung jederzeit ändern kann -- Standard-Link nur als Fallback, falls nichts gepflegt ist.
        // ?? statt ?: -- ein direkter Array-Zugriff auf einen fehlenden Key (z.B. Spalte noch
        // nicht migriert) erzeugt bei ?: trotzdem eine "Undefined array key"-Warning, die den
        // PDF-Response zerstört (Output vor den header()-Aufrufen). ?? liest den Key sicher aus.
        'EEG_DASHBOARD_URL'         => ($community['dashboard_url'] ?? null) ?: 'https://portal.stromfueralle.at/portal/login',
        'RAW_EEG_UNTERSCHRIFT_BILD' => $signature['var'],
        'RAW_MITGLIED_UNTERSCHRIFT_BILD' => $memberSignature['var'],
        'RAW_MITGLIED_ORT_DATUM'    => memberOrtDatumLine($member, 'bezug'),
        'ERSTELLT_AM'               => date('d.m.Y'),
    ];
}

$router->get('/portal/members/:id/contract/bezug', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $communityId = $member['community_id'];

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Bezugs-Zählpunkt registriert. Bitte zuerst einen Bezugs-Zählpunkt (Typ: Bezug) anlegen.'; return; }

    $genAt  = $member['contract_bezug_generated_at'] ?? null;
    $status = $member['contract_bezug_status'] ?? 'none';
    $tariff = contractTariff($communityId, $genAt);

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $signature = eegSignatureAsset();
    $memberSig = memberSignatureAsset($member['contract_bezug_customer_signature'] ?? null);
    $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature, $memberSig);
    $ok = streamLatexPdf('bezugsvereinbarung', $vars, 'Bezugsvereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);

    // DB-Update NUR nach erfolgreichem PDF, und nicht mehr nach digitaler Unterschrift --
    // ab dann bleibt generated_at eingefroren, damit der signierte Vertrag bei jeder erneuten
    // Ansicht exakt dieselben (zum Unterschriftszeitpunkt gültigen) Tarifzahlen zeigt.
    if ($ok && $status !== 'signed') {
        DB::execute(
            "UPDATE members SET contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END, contract_bezug_generated_at = now() WHERE id = ?",
            [$params['id']]
        );
    }
});

/**
 * Baut den {{hinweis}}-Textbaustein für Vertrags-E-Mails: leer bei der erstmaligen Fassung,
 * sonst ein expliziter Hinweis, dass eine frühere, bereits versendete Fassung ab sofort
 * ungültig ist (Version wird beim Zurücksetzen eines gesendeten Vertrags hochgezählt).
 */
function contractInvalidationNote(int $version): string
{
    return $version > 1
        ? '<p><strong>Hinweis:</strong> Dies ist eine korrigierte Fassung. Eine Ihnen zuvor '
          . 'zugesendete frühere Version ist ab sofort ungültig.</p>'
        : '';
}

$router->post('/portal/members/:id/contract/bezug/send', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Verträge sind in dieser EEG deaktiviert.')); exit; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    if (empty($mps)) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Kein Bezugs-Zählpunkt registriert.'));
        exit;
    }
    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$member['community_id']]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = eegSignatureAsset();
    $vars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($mps), $signature);

    // PDF wird hier nur zur Validierung erzeugt (Template-/Latex-Fehler sollen dem Manager
    // sofort auffallen, nicht erst wenn das Mitglied den Portal-Link später öffnet) -- versendet
    // wird kein Anhang mehr, nur eine Benachrichtigung mit Link zur digitalen Unterschrift.
    $error = null;
    $pdf = generateLatexPdf('bezugsvereinbarung', $vars, $signature['assets'], $error);
    if ($pdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }
    try {
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('contract_bezug', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/contract/bezug/sign')),
            'hinweis'  => contractInvalidationNote((int)$member['contract_bezug_version']),
        ],
            'Ihre Bezugsvereinbarung – {{eeg_name}}',
            '<p>{{anrede}} {{nachname}},</p><p>Ihre Bezugsvereinbarung mit {{eeg_name}} liegt für Sie bereit. '
            . 'Bitte prüfen Sie die Vereinbarung im Mitgliederportal und unterschreiben Sie dort digital, '
            . 'damit sie gültig wird:</p><p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
        DB::execute(
            "UPDATE members SET contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END, contract_bezug_generated_at = now(), contract_bezug_sent_at = now() WHERE id = ?",
            [$params['id']]
        );
        header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Bezugsvereinbarung wurde per E-Mail zur digitalen Unterschrift verschickt.'));
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Setzt eine bereits versendete Vertragsfassung zurück, damit nach Korrekturen eine neue
 * Fassung erstellt und gesendet werden kann. Nur möglich, wenn der Vertrag tatsächlich schon
 * einmal per "Jetzt senden" verschickt wurde -- sonst gibt es ja noch nichts zurückzunehmen,
 * die reine Generierung/Vorschau kann jederzeit beliebig oft wiederholt werden.
 */
$router->post('/portal/members/:id/contract/:type/reset', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $type = $params['type'];
    if (!in_array($type, ['bezug', 'einspeisung'], true)) { http_response_code(404); return; }

    if (empty($member['contract_' . $type . '_sent_at'])) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Dieser Vertrag wurde noch nicht per E-Mail gesendet, ein Zurücksetzen ist daher nicht nötig.'));
        exit;
    }

    DB::execute(
        "UPDATE members SET contract_{$type}_status = 'none', contract_{$type}_sent_at = NULL, contract_{$type}_version = contract_{$type}_version + 1 WHERE id = ?",
        [$params['id']]
    );
    logAudit($member['community_id'], 'contract.reset', 'member', $params['id'],
        ucfirst($type) . 'svereinbarung von ' . $member['first_name'] . ' ' . $member['last_name'] . ' zurückgesetzt (Korrektur erforderlich)');

    header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Vertrag wurde zurückgesetzt und kann neu erstellt werden.'));
    exit;
});

/** Baut die \item-Liste der Einspeise-Zählpunkte für den Einspeisevertrag. */
function einspeisungZpLines(array $mps): string
{
    return implode("\n", array_map(
        function ($mp) {
            $engpass = $mp['engpassleistung_kw'] ? number_format((float)$mp['engpassleistung_kw'], 2, ',', '.') . ' kWp' : '--';
            return '\\item Zählpunktnummer ' . texEscape($mp['zaehlpunkt_nr'])
                . ' --- Erzeugungsart: ' . texEscape($mp['erzeugungsart'] ?? 'Photovoltaik')
                . ', Engpassleistung: ' . $engpass;
        },
        $mps
    ));
}

/** Baut die Anlagenbeschreibung (Adresse/Gst.-Nr./KG) aus den Einspeise-Zählpunkten. */
function einspeisungAnlagenBeschreibung(array $mps): string
{
    return implode('; ', array_filter(array_map(
        function ($mp) {
            $teile = array_filter([
                $mp['anlagenadresse'] ?? null,
                $mp['gst_nr'] ? 'Gst.-Nr. ' . $mp['gst_nr'] : null,
                $mp['katastralgemeinde'] ? 'KG ' . $mp['katastralgemeinde'] : null,
            ]);
            return $teile ? implode(', ', $teile) : null;
        },
        $mps
    )));
}

/**
 * Baut die Template-Variablen für die Einspeisevereinbarung. Gemeinsam genutzt von der
 * Ansichts-Route und der "Jetzt senden"-Route.
 */
function einspeisevereinbarungVars(array $member, array $community, ?array $tariff, string $zpLines, string $anlagenBeschreibung, array $signature, array $memberSignature = ['var' => '', 'assets' => []]): array
{
    return [
        'EEG_NAME'                  => $community['name'],
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_MARKTPARTNER_ID'       => $community['marktpartner_id'] ?? '--',
        'EEG_IBAN'                  => $community['iban'] ?? '--',
        'EEG_ORT'                   => extractOrtFromAddress($community['address']),
        'MITGLIED_NAME'             => memberFullName($member),
        'MITGLIED_ADRESSE'          => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
        'MITGLIED_ADRESSE_ORT'      => $member['city'],
        'MITGLIED_UID_ZEILE'        => $member['invoice_uid'] ? 'UID-Nr.: ' . $member['invoice_uid'] : '',
        'MITGLIED_SEIT'             => $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '--',
        'MITGLIED_IBAN'             => $member['member_iban'] ?? '--',
        'MITGLIED_BIC'              => $member['member_bic'] ?? '--',
        'EINSPEISUNG_TARIF'         => $tariff ? number_format((float)$tariff['einspeisung_ct_kwh'], 4, ',', '.') : '--',
        'TARIF_GUELTIG_AB'          => $tariff ? date('d.m.Y', strtotime($tariff['valid_from'])) : '--',
        'RAW_ZAEHLPUNKTE_LISTE'     => $zpLines,
        'ANLAGENBESCHREIBUNG'       => $anlagenBeschreibung ?: '--',
        'RAW_EEG_UNTERSCHRIFT_BILD' => $signature['var'],
        'RAW_MITGLIED_UNTERSCHRIFT_BILD' => $memberSignature['var'],
        'RAW_MITGLIED_ORT_DATUM'    => memberOrtDatumLine($member, 'einspeisung'),
        'ERSTELLT_AM'               => date('d.m.Y'),
    ];
}

$router->get('/portal/members/:id/contract/einspeisung', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { http_response_code(404); echo 'Verträge sind in dieser EEG deaktiviert.'; return; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $communityId = $member['community_id'];

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($mps)) { http_response_code(400); echo 'Kein Einspeise-Zählpunkt registriert. Bitte zuerst einen Zählpunkt (Typ: Einspeisung) anlegen.'; return; }

    $genAt  = $member['contract_einspeisung_generated_at'] ?? null;
    $status = $member['contract_einspeisung_status'] ?? 'none';
    $tariff = contractTariff($communityId, $genAt);

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $signature = eegSignatureAsset();
    $memberSig = memberSignatureAsset($member['contract_einspeisung_customer_signature'] ?? null);
    $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature, $memberSig);
    $ok = streamLatexPdf('einspeisevereinbarung', $vars, 'Einspeisevereinbarung_' . $member['last_name'] . '.pdf', $signature['assets'] + $memberSig['assets']);

    if ($ok && $status !== 'signed') {
        DB::execute(
            "UPDATE members SET contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END, contract_einspeisung_generated_at = now() WHERE id = ?",
            [$params['id']]
        );
    }
});

$router->post('/portal/members/:id/contract/einspeisung/send', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Verträge sind in dieser EEG deaktiviert.')); exit; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $mps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($mps)) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Kein Einspeise-Zählpunkt registriert.'));
        exit;
    }
    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$member['community_id']]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = eegSignatureAsset();
    $vars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($mps), einspeisungAnlagenBeschreibung($mps), $signature);

    // PDF wird hier nur zur Validierung erzeugt -- versendet wird kein Anhang mehr, nur eine
    // Benachrichtigung mit Link zur digitalen Unterschrift (siehe Kommentar beim Bezugsvertrag).
    $error = null;
    $pdf = generateLatexPdf('einspeisevereinbarung', $vars, $signature['assets'], $error);
    if ($pdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }
    try {
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('contract_einspeisung', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/contract/einspeisung/sign')),
            'hinweis'  => contractInvalidationNote((int)$member['contract_einspeisung_version']),
        ],
            'Ihre Einspeisevereinbarung – {{eeg_name}}',
            '<p>{{anrede}} {{nachname}},</p><p>Ihre Einspeisevereinbarung mit {{eeg_name}} liegt für Sie bereit. '
            . 'Bitte prüfen Sie die Vereinbarung im Mitgliederportal und unterschreiben Sie dort digital, '
            . 'damit sie gültig wird:</p><p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
        DB::execute(
            "UPDATE members SET contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END, contract_einspeisung_generated_at = now(), contract_einspeisung_sent_at = now() WHERE id = ?",
            [$params['id']]
        );
        header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Einspeisevereinbarung wurde per E-Mail zur digitalen Unterschrift verschickt.'));
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Sendet Bezugs- und Einspeisevereinbarung gemeinsam in einer E-Mail mit beiden PDFs im
 * Anhang -- praktisch für Mitglieder, die sowohl Bezugs- als auch Einspeise-Zählpunkte haben,
 * damit nicht zweimal einzeln gesendet werden muss. Nutzt eine eigene, im Platform-Admin
 * editierbare Vorlage (contract_both), da der Text sich von den Einzel-Vorlagen unterscheiden soll.
 */
$router->post('/portal/members/:id/contract/send-both', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!contractsEnabled(Auth::activeCommunityId())) { header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Verträge sind in dieser EEG deaktiviert.')); exit; }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }

    $consumerMps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'consumer']);
    $producerMps = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true AND type = ? ORDER BY registered_at', [$params['id'], 'producer']);
    if (empty($consumerMps) || empty($producerMps)) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Für den gemeinsamen Versand werden sowohl ein Bezugs- als auch ein Einspeise-Zählpunkt benötigt.'));
        exit;
    }

    $tariff = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$member['community_id']]);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$member['community_id']]);
    $signature = eegSignatureAsset();

    $error = null;
    $bezugVars = bezugsvereinbarungVars($member, $community, $tariff, bezugZpLines($consumerMps), $signature);
    $bezugPdf = generateLatexPdf('bezugsvereinbarung', $bezugVars, $signature['assets'], $error);
    if ($bezugPdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }
    $einspeisungVars = einspeisevereinbarungVars($member, $community, $tariff, einspeisungZpLines($producerMps), einspeisungAnlagenBeschreibung($producerMps), $signature);
    $einspeisungPdf = generateLatexPdf('einspeisevereinbarung', $einspeisungVars, $signature['assets'], $error);
    if ($einspeisungPdf === null) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode($error));
        exit;
    }

    try {
        $hinweis = contractInvalidationNote((int)$member['contract_bezug_version'])
            ?: contractInvalidationNote((int)$member['contract_einspeisung_version']);
        $anrede = mailSalutation($member);
        $mail = renderMailTemplate('contract_both', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'anrede'   => htmlspecialchars($anrede['anrede']),
            'nachname' => htmlspecialchars($anrede['nachname']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/documents')),
            'hinweis'  => $hinweis,
        ],
            'Ihre Vereinbarungen – {{eeg_name}}',
            '<p>{{anrede}} {{nachname}},</p><p>Ihre Bezugsvereinbarung und Ihre Einspeisevereinbarung mit {{eeg_name}} liegen '
            . 'für Sie bereit. Bitte prüfen Sie beide Vereinbarungen im Mitgliederportal und unterschreiben Sie dort '
            . 'digital, damit sie gültig werden:</p><p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
        );
        Mailer::send($member['email'], $mail['subject'], $mail['body']);
        DB::execute(
            "UPDATE members SET
                contract_bezug_status = CASE WHEN contract_bezug_status = 'none' THEN 'created' ELSE contract_bezug_status END,
                contract_bezug_generated_at = now(), contract_bezug_sent_at = now(),
                contract_einspeisung_status = CASE WHEN contract_einspeisung_status = 'none' THEN 'created' ELSE contract_einspeisung_status END,
                contract_einspeisung_generated_at = now(), contract_einspeisung_sent_at = now()
             WHERE id = ?",
            [$params['id']]
        );
        header('Location: /portal/members/' . $params['id'] . '?success=' . urlencode('Beide Vereinbarungen wurden gemeinsam per E-Mail verschickt.'));
    } catch (\Throwable $e) {
        header('Location: /portal/members/' . $params['id'] . '?error=mail&detail=' . urlencode($e->getMessage()));
    }
    exit;
});

$router->post('/portal/members/:id/contract-status', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $communityId = $member['community_id'];
    $type   = $_POST['type'] ?? '';
    $status = $_POST['status'] ?? '';
    if (!in_array($type, ['bezug', 'einspeisung']) || !in_array($status, ['none', 'created', 'signed'])) {
        http_response_code(400); return;
    }
    // Nach dem Versand ist der Status nur noch über "Zurücksetzen" (setzt sent_at zurück
    // auf NULL) veränderbar -- schützt davor, dass ein bereits versendeter Vertrag über
    // dieses Dropdown unbemerkt "manuell" umgestellt wird.
    if (!empty($member['contract_' . $type . '_sent_at'])) {
        header('Location: /portal/members/' . $params['id'] . '?error=' . urlencode('Bereits versendete Verträge sind nicht mehr über dieses Dropdown änderbar. Bitte zuerst zurücksetzen.'));
        exit;
    }
    $col = 'contract_' . $type . '_status';
    DB::execute("UPDATE members SET {$col} = ? WHERE id = ? AND community_id = ?", [$status, $params['id'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/metering-points/:mpid/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // requireMemberAccess() setzt (anders als der frühere direkte metering_points-Query hier)
    // korrekt DB::setCommunity() -- metering_points hat seit migrate_20260822.sql Row-Level
    // Security, ein Query ohne vorher gesetzte Community liefert für die eingeschränkte
    // Laufzeit-Rolle grundsätzlich GAR KEINE Zeile ("Zählpunkt nicht gefunden" trotz
    // existierendem Zählpunkt).
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mp = DB::fetchOne('SELECT id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    $communityId = $member['community_id'];

    $znr = strtoupper(trim($_POST['zaehlpunkt_nr'] ?? ''));
    $existing = DB::fetchOne(
        "SELECT m.first_name, m.last_name, m.kundennummer FROM metering_points mp
         JOIN members m ON m.id = mp.member_id
         WHERE mp.community_id = ? AND mp.zaehlpunkt_nr = ? AND mp.id != ?",
        [$communityId, $znr, $params['mpid']]
    );
    if ($existing) {
        header('Location: /portal/members/' . $params['id'] . '?error=znr_duplicate&znr_owner='
            . urlencode($existing['first_name'] . ' ' . $existing['last_name'] . ' (KdNr ' . ($existing['kundennummer'] ?? '—') . ')'));
        exit;
    }

    $meterCode = trim($_POST['meter_code'] ?? '') ?: null;
    // Siehe Kommentar bei der Anlage-Route: eine Zählernummer darf sehr wohl zu ZWEI aktiven
    // Zählpunkten gehören (Bezug + Einspeisung eines Prosumers, ein physischer Zähler) --
    // kein Blocken, nur eine informative Postfach-Meldung zur Transparenz.
    if ($meterCode) {
        $sharedWith = DB::fetchOne(
            "SELECT 1 FROM metering_points WHERE community_id = ? AND meter_code = ? AND active = true AND id != ?",
            [$communityId, $meterCode, $params['mpid']]
        );
        if ($sharedWith) {
            notifyMeterCodeShared($communityId, $meterCode);
        }
    }

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'UPDATE metering_points SET zaehlpunkt_nr=?, meter_code=?, type=?, jahresverbrauch_kwh=?, engpassleistung_kw=?, geplante_einspeisung_kwh=? WHERE id=? AND community_id=?',
        [
            $znr,
            $meterCode,
            $_POST['type'] ?? 'consumer',
            $jahresverbrauch,
            $engpassleistung,
            $geplanteEinsp,
            $params['mpid'],
            $communityId,
        ]
    );
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/portal/members/:id/metering-points/:mpid/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // Siehe Kommentar bei .../edit oben -- gleicher RLS-Fix (requireMemberAccess() statt
    // direktem metering_points-Query ohne gesetzte Community).
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mp = DB::fetchOne('SELECT id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    $communityId = $member['community_id'];
    DB::execute('UPDATE metering_points SET active=false WHERE id=? AND community_id=?', [$params['mpid'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

/**
 * Testphase-Reset (Patrick, 30.07.2026): löscht ALLE ESP-Live-Messdaten (esp_measurements) und
 * setzt den Online-/WLAN-Status auf allen Zählpunkten EINES Mitglieds zurück -- bewusst pro
 * Mitglied statt für die ganze EEG auf einmal, damit ein Reset beim Testen mit einem einzelnen
 * Test-Zählpunkt nicht versehentlich die Daten anderer Mitglieder mitlöscht. Nur im Testmodus
 * verfügbar (Platform-Admin -> Plattform-Technik) -- sobald eine EEG in den echten Betrieb
 * wechselt, verschwindet dieser destruktive Button automatisch, damit niemand aus Versehen
 * echte Live-Daten löscht.
 */
$router->post('/portal/members/:id/reset-live-data', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    if (!platformTestMode()) {
        http_response_code(403); echo 'Nur im Testmodus verfügbar (Platform-Admin -> Plattform-Technik).'; return;
    }
    $member = requireMemberAccess($params['id']);
    if (!$member) { return; }
    $mpIds = array_column(
        DB::fetchAll('SELECT id FROM metering_points WHERE member_id = ?', [$member['id']]),
        'id'
    );
    if ($mpIds) {
        $placeholders = implode(',', array_fill(0, count($mpIds), '?'));
        $deleted = DB::execute("DELETE FROM esp_measurements WHERE metering_point_id IN ($placeholders)", $mpIds);
        DB::execute(
            // meter_reachable ist NOT NULL DEFAULT false (migrate_20260820.sql) -- auf false statt
            // NULL zuruecksetzen, sonst verletzt das UPDATE den NOT-NULL-Constraint (siehe
            // SQLSTATE[23502] beim ersten Versuch, Patrick 30.07.2026).
            "UPDATE metering_points
             SET esp_online = false, esp_last_seen_at = NULL, meter_reachable = false,
                 meter_last_seen_at = NULL, wifi_ssid = NULL, wifi_ip = NULL, wifi_password_enc = NULL
             WHERE id IN ($placeholders)",
            $mpIds
        );
        logAudit($member['community_id'], 'live_daten_reset', 'member', $member['id'],
            'Live-ESP-Messdaten zurückgesetzt (Testphase): ' . $deleted . ' Messzeilen gelöscht, '
            . count($mpIds) . ' Zählpunkt(e) auf "keine Daten" zurückgesetzt.');
    }
    header('Location: /portal/members/' . $params['id'] . '?success=live_reset');
    exit;
});

// ─── Portal: Passwort ändern ────────────────────────────
/**
 * Löst die members.id des eingeloggten Users in der angegebenen Community auf -- bevorzugt die
 * member_id der aktuell aktiven Rolle (Demo-Logins mit mehreren Mitglied-Identitäten in
 * derselben Community, siehe migrate_20260905.sql), fällt sonst auf die bisherige
 * user_id-Suche zurück (unverändertes Verhalten für alle echten Accounts, die weiterhin
 * höchstens einen Mitgliedsdatensatz je Community haben).
 */
function activeMemberId(string $communityId): ?string
{
    $active = Auth::activeRole();
    if ($active && ($active['community_id'] ?? null) === $communityId && !empty($active['member_id'])) {
        return $active['member_id'];
    }
    $row = DB::fetchOne('SELECT id FROM members WHERE user_id = ? AND community_id = ?', [Auth::userId(), $communityId]);
    return $row['id'] ?? null;
}

/**
 * Mitgliedsdatensatz des eingeloggten Users in der aktuell aktiven Community (falls
 * vorhanden) -- für das Profilbild in /portal/profile. Gibt null zurück für Accounts ohne
 * eigenen Mitgliedsdatensatz (reine Manager/Platform-Admins); die haben ihr Profilbild dann
 * stattdessen direkt am Login-Account (users.photo_path, siehe saveUserPhoto()).
 */
function currentProfileMember(): ?array
{
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { return null; }
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { return null; }
    return DB::fetchOne('SELECT id, photo_path, salutation FROM members WHERE id = ?', [$memberId]);
}

/**
 * Vollständiger Mitgliedsdatensatz des eingeloggten Users in der aktuell aktiven Community --
 * für die Selbstbedienungs-Ansichten (eigene Verträge/Dateien/Beitrittserklärung). Anders als
 * currentProfileMember() (nur id/photo_path/salutation fürs Profilbild) wird hier die
 * komplette Zeile gebraucht (Adresse, IBAN, Zustimmungen etc. für die Vertragsvorlagen).
 */
function currentMemberFull(): ?array
{
    $communityId = Auth::activeCommunityId();
    if (!$communityId) { return null; }
    DB::setCommunity($communityId);
    $memberId = activeMemberId($communityId);
    if (!$memberId) { return null; }
    return DB::fetchOne('SELECT * FROM members WHERE id = ?', [$memberId]);
}

/**
 * Stellt alle zu einem Mitglied gespeicherten personenbezogenen Daten strukturiert zusammen --
 * DSGVO Art. 15 (Auskunftsrecht) bzw. Art. 20 (Datenübertragbarkeit), maschinenlesbar als JSON.
 * Bewusst OHNE sicherheitskritische interne Felder (Passwort-Hash, API-Key-Hash, Signier-Token)
 * und ohne die eingebetteten Unterschriftsbilder (große Base64-Blobs ohne Mehrwert im Export).
 */
function buildMemberDsgvoExport(array $member): array
{
    $cid = $member['community_id'];
    $mid = $member['id'];
    DB::setCommunity($cid);

    $strip = function (array $rows, array $keys): array {
        foreach ($rows as &$r) { foreach ($keys as $k) { unset($r[$k]); } }
        return $rows;
    };

    $konto = null;
    if (!empty($member['user_id'])) {
        // Explizit nur unbedenkliche Spalten -- NICHT password_hash/reset_token.
        $konto = DB::fetchOne(
            'SELECT email, first_name, last_name, active, created_at, last_login_at FROM users WHERE id = ?',
            [$member['user_id']]
        );
    }

    $rechnungen = DB::fetchAll('SELECT * FROM invoices WHERE member_id = ? AND community_id = ? ORDER BY created_at', [$mid, $cid]);
    foreach ($rechnungen as &$r) {
        $r['positionen'] = DB::fetchAll('SELECT type, label, kwh, rate_ct_kwh, quantity, unit, amount_eur, zaehlpunkt_nr FROM invoice_items WHERE invoice_id = ?', [$r['id']]);
    }
    unset($r);

    return [
        'export_erzeugt_am' => date('c'),
        'hinweis' => 'DSGVO-Datenauskunft (Art. 15) bzw. Datenübertragbarkeit (Art. 20). Enthält alle '
            . 'zu dieser Person gespeicherten personenbezogenen Daten. Sicherheitskritische interne Felder '
            . '(Passwort-Hash, API-Key-Hash, Signier-Token) und eingebettete Unterschriftsbilder sind bewusst '
            . 'nicht enthalten.',
        'stammdaten'          => $member,
        'login_konto'         => $konto,
        'zaehlpunkte'         => DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND community_id = ?', [$mid, $cid]),
        'beitrittserklaerung' => $strip(DB::fetchAll('SELECT * FROM membership_applications WHERE member_id = ? AND community_id = ?', [$mid, $cid]), ['signature_image']),
        'vertraege'           => $strip(DB::fetchAll('SELECT * FROM contracts WHERE member_id = ? AND community_id = ?', [$mid, $cid]), ['signature_image', 'sign_token', 'sign_token_expires_at']),
        'rechnungen'          => $rechnungen,
        'hochgeladene_dateien'=> DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? AND community_id = ?', [$mid, $cid]),
        'api_zugaenge'        => $strip(DB::fetchAll('SELECT * FROM member_api_keys WHERE member_id = ?', [$mid]), ['key_hash']),
    ];
}

/** Sendet ein DSGVO-Export-Array als JSON-Download. */
function sendDsgvoExport(array $data, string $filename): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Mitglied exportiert seine eigenen Daten (Selbstauskunft).
$router->get('/portal/my/dsgvo-export', function () {
    Auth::requireLogin();
    $member = currentMemberFull();
    if (!$member) { http_response_code(404); echo 'Kein Mitgliedskonto in dieser EEG.'; return; }
    logAudit($member['community_id'], 'dsgvo.export.self', 'member', $member['id'], 'Mitglied hat DSGVO-Selbstauskunft exportiert');
    sendDsgvoExport(buildMemberDsgvoExport($member), 'dsgvo-export-' . ($member['kundennummer'] ?? 'mitglied') . '.json');
});

// Manager/Platform-Admin exportiert die Daten eines bestimmten Mitglieds (Auskunftsersuchen).
$router->get('/portal/members/:id/dsgvo-export', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$member) { http_response_code(404); echo 'Mitglied nicht gefunden.'; return; }
    logAudit($communityId, 'dsgvo.export.manager', 'member', $member['id'],
        'DSGVO-Auskunft für ' . $member['first_name'] . ' ' . $member['last_name'] . ' exportiert');
    sendDsgvoExport(buildMemberDsgvoExport($member), 'dsgvo-export-' . ($member['kundennummer'] ?? $member['id']) . '.json');
});

$router->get('/portal/profile', function () {
    Auth::requireLogin();
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path, totp_enabled FROM users WHERE id = ?', [Auth::userId()]);
    $profileMember = currentProfileMember();
    if (!empty($_GET['success'])) { $success = $_GET['success']; }
    if (!empty($_GET['error'])) { $error = $_GET['error']; }
    require ROOT . '/src/views/pages/profile.php';
});

$router->post('/portal/profile', function () {
    Auth::requireLogin();
    $email     = trim($_POST['email'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    if (!$email || !$firstName || !$lastName) {
        $error = 'Alle Felder sind Pflichtfelder.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ungültige E-Mail-Adresse.';
    } else {
        DB::execute('UPDATE users SET email=?, first_name=?, last_name=? WHERE id=?',
            [$email, $firstName, $lastName, Auth::userId()]);
        $_SESSION['user_email'] = $email;
        $success = 'Daten wurden gespeichert.';
    }
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path, totp_enabled FROM users WHERE id = ?', [Auth::userId()]);
    $profileMember = currentProfileMember();
    require ROOT . '/src/views/pages/profile.php';
});

$router->post('/portal/profile/photo', function () {
    Auth::requireLogin();
    if (!isset($_FILES['photo'])) { header('Location: /portal/profile?error=upload'); exit; }

    // Mit Community-Mitgliedsdatensatz (Mitglied, ggf. auch Manager mit eigener Mitgliedschaft):
    // Bild hängt am Mitglied, damit es auch in der Mitgliederliste/-detailseite erscheint.
    // Ohne Mitgliedsdatensatz (reiner Manager-/Platform-Admin-Account): Bild hängt am Login.
    $profileMember = currentProfileMember();
    $err = $profileMember
        ? saveMemberPhoto($profileMember['id'], $_FILES['photo'])
        : saveUserPhoto(Auth::userId(), $_FILES['photo']);

    if ($err === null) {
        header('Location: /portal/profile?success=' . urlencode('Profilbild gespeichert.'));
    } else {
        header('Location: /portal/profile?error=' . urlencode('Profilbild konnte nicht gespeichert werden.'));
    }
    exit;
});

/**
 * 2FA-Einrichtung starten: erzeugt ein neues TOTP-Secret und legt es NUR in der Session ab
 * (noch nicht in der DB / noch nicht aktiv). Erst nach erfolgreicher Code-Bestätigung wird es
 * gespeichert und aktiviert -- so ist sichergestellt, dass die App den Code auch wirklich liefert.
 */
$router->get('/portal/profile/2fa/setup', function () {
    Auth::requireLogin();
    $secret = totpGenerateSecret();
    $_SESSION['2fa_setup_secret'] = $secret;
    $account = $_SESSION['user_email'] ?? 'konto';
    $otpauthUri = totpProvisioningUri($secret, $account, 'Strom für alle');
    require ROOT . '/src/views/pages/profile_2fa.php';
});

$router->post('/portal/profile/2fa/enable', function () {
    Auth::requireLogin();
    $secret = $_SESSION['2fa_setup_secret'] ?? '';
    if ($secret === '') { header('Location: /portal/profile/2fa/setup'); exit; }
    if (!totpVerify($secret, $_POST['code'] ?? '')) {
        $account = $_SESSION['user_email'] ?? 'konto';
        $otpauthUri = totpProvisioningUri($secret, $account, 'Strom für alle');
        $error = 'Der Code stimmt nicht. Bitte den aktuellen 6-stelligen Code eingeben.';
        require ROOT . '/src/views/pages/profile_2fa.php';
        exit;
    }
    // Verschlüsselt speichern (AES-256-CBC, gleiche encryptSecret()-Funktion wie für das
    // ESP-WLAN-Passwort) statt im Klartext -- OWASP-Audit 13.08.2026: ein DB-Dump/-Leak hätte
    // sonst direkt alle 2FA-Codes kompromittiert, ganz ohne das jeweilige Passwort zu brauchen.
    DB::execute('UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?',
        [encryptSecret($secret), 'true', Auth::userId()]);
    unset($_SESSION['2fa_setup_secret']);
    logAudit(null, 'user.2fa.enable', 'user', Auth::userId(), 'Zwei-Faktor-Authentifizierung (TOTP) aktiviert');
    header('Location: /portal/profile?success=' . urlencode('Zwei-Faktor-Authentifizierung ist jetzt aktiv.'));
    exit;
});

$router->post('/portal/profile/2fa/disable', function () {
    Auth::requireLogin();
    // Der Nutzer ist bereits authentifiziert -> Ausschalten ohne erneute Code-Abfrage (bewusst
    // einfach gehalten, da häufiger Account-Wechsel gewünscht ist). Secret wird gelöscht, ein
    // erneutes Aktivieren erzeugt ein frisches.
    DB::execute("UPDATE users SET totp_enabled = 'false', totp_secret = NULL WHERE id = ?", [Auth::userId()]);
    logAudit(null, 'user.2fa.disable', 'user', Auth::userId(), 'Zwei-Faktor-Authentifizierung (TOTP) deaktiviert');
    header('Location: /portal/profile?success=' . urlencode('Zwei-Faktor-Authentifizierung wurde deaktiviert.'));
    exit;
});

$router->get('/portal/password', function () {
    Auth::requireLogin();
    require ROOT . '/src/views/pages/password_change.php';
});

$router->post('/portal/password', function () {
    Auth::requireLogin();
    $userId = Auth::userId();
    $user = DB::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Aktuelles Passwort ist falsch.';
    } elseif (strlen($new) < 8) {
        $error = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($new !== $confirm) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } elseif (isPasswordBreached($new)) {
        $error = 'Dieses Passwort ist in bekannten Datenlecks aufgetaucht und ist deshalb unsicher. Bitte ein anderes Passwort wählen.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::execute('UPDATE users SET password_hash=? WHERE id=?', [$hash, $userId]);
        $success = 'Passwort wurde erfolgreich geändert.';
    }
    require ROOT . '/src/views/pages/password_change.php';
    exit;
});

// ─── Portal: Abrechnung ─────────────────────────────────
$router->get('/portal/billing', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    // POST /portal/billing/generate (u.a.) leiten bei einem Fehler auf ?error=... um -- diese
    // Route hat das bisher nie in die $error-Variable übernommen, die die View unten prüft
    // (!empty($error)), wodurch eine fehlgeschlagene Berechnung (z.B. wegen L3-Werten) komplett
    // ohne jede Rückmeldung blieb, obwohl sie korrekt geloggt wurde (Patrick, 07.08.2026:
    // "Wollte Rechnung berechnen, geht aber nicht, und es kommt auch nichts").
    $error = $_GET['error'] ?? null;
    $runs = DB::fetchAll(
        'SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]
    );
    $extraItemsByRun = [];
    foreach (DB::fetchAll('SELECT * FROM billing_run_extra_items WHERE community_id = ? ORDER BY created_at', [$communityId]) as $ei) {
        $extraItemsByRun[$ei['billing_run_id']][] = $ei;
    }
    // Fehlende Monatsimporte je Lauf schon in der Übersicht sichtbar machen, nicht erst als
    // Fehlermeldung beim Freigabe-Versuch (siehe Billing::missingMonths(), Patrick 05.08.2026).
    $missingMonthsByRun = [];
    foreach ($runs as $run) {
        $missingMonthsByRun[$run['id']] = Billing::missingMonths($communityId, $run['period_from'], $run['period_to']);
    }
    require ROOT . '/src/views/pages/billing.php';
});

/**
 * Test-Vorschau einer Rechnung mit Platzhalter-Werten statt echten Mitgliedsdaten -- erzeugt
 * keinen Datenbankeintrag, dient nur dazu, das Layout (bzw. eine angepasste rechnung.tex) zu
 * begutachten. Nutzt die echten Stammdaten der Community (Name/Adresse/IBAN/Logo), damit die
 * Vorschau realistisch aussieht.
 */
$router->get('/portal/billing/preview', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tax = DB::fetchOne('SELECT * FROM tax_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);

    // Beispiel-Netto der Vorschau (Summe der Beispielpositionen) durch die konfigurierte
    // Steuerlogik schicken, damit die Vorschau Kleinunternehmer vs. Standard (mit USt) zeigt.
    $tbPreview = taxBreakdown(49.04, $tax['tax_model'] ?? null, $tax['tax_rate_percent'] ?? null);
    if ($tbPreview['model'] === 'standard') {
        $ustFmt = number_format($tbPreview['ust'], 2, ',', '.');
        $satzFmt = rtrim(rtrim(number_format($tbPreview['rate'], 2, ',', '.'), '0'), ',');
        $steuerZeile = '\\multicolumn{3}{r}{\\footnotesize Netto} & \\footnotesize EUR ' . number_format($tbPreview['netto'], 2, ',', '.') . ' \\\\'
            . '\\multicolumn{3}{r}{\\footnotesize zzgl.\\,' . $satzFmt . '\\,\\% USt} & \\footnotesize EUR ' . $ustFmt . ' \\\\';
        $steuerText  = 'Im Rechnungsbetrag sind ' . $satzFmt . '\\,\\% Umsatzsteuer (EUR ' . $ustFmt . ') enthalten.';
    } else {
        $steuerZeile = '\\multicolumn{4}{l}{\\footnotesize\\color{midgray}Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung): keine Umsatzsteuer.} \\\\';
        $steuerText  = 'Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';
    }

    // Beispiel-Zusatzposition, damit die Vorschau auch zeigt, wie ein Rabatt/eine Gutschrift
    // aussieht (siehe /portal/billing -> Zusatzpositionen).
    $extraItemsLatex = rechnungExtraItemsLatex([
        ['label' => 'Beispiel: Rabatt Mitgliedsbeitrag Q1', 'quantity' => 1, 'unit' => 'Stk', 'amount_eur' => -6.00],
    ]);
    // Beispielhafte Positionslisten -- zeigen zwei Bezugs-Zählpunkte (mehrere Zeilen) und einen
    // Einspeise-Zählpunkt mit Zählpunktnummer als grauer Zweitzeile.
    $bezugListe = rechnungPositionenLatex([
        ['zaehlpunkt_nr' => 'AT0030000000000000000000000000001', 'kwh' => 250.00, 'rate_ct_kwh' => 9.80, 'amount_eur' => 24.50],
        ['zaehlpunkt_nr' => 'AT0030000000000000000000000000002', 'kwh' => 162.50, 'rate_ct_kwh' => 9.80, 'amount_eur' => 15.93],
    ], 'Energiebezug aus der Gemeinschaft', false);
    $einspListe = rechnungPositionenLatex([
        ['zaehlpunkt_nr' => 'AT0030000000000000000000000000003', 'kwh' => 85.00, 'rate_ct_kwh' => 7.50, 'amount_eur' => -6.38],
    ], 'Einspeisevergütung (Gutschrift)', true);

    $eegAdrTeile = array_map('trim', explode(',', $community['address'] ?? 'Musterstraße 1, 9020 Klagenfurt', 2));

    streamLatexPdf('rechnung', [
        'EEG_NAME'              => $community['name'] ?? 'Muster-EEG',
        'EEG_ADRESSE'           => $community['address'] ?? 'Musterstraße 1, 9020 Klagenfurt',
        'EEG_STRASSE'           => $eegAdrTeile[0] ?? '',
        'EEG_PLZ_ORT'           => $eegAdrTeile[1] ?? '',
        'EEG_UID'               => $tax['uid_number'] ?? '',
        'EEG_ZVR'               => $community['zvr_number'] ?? '1778816746',
        'EEG_OBMANN_TELEFON'    => $community['contact_phone'] ?? '',
        'EEG_KONTAKT_EMAIL'     => $community['contact_email'] ?? '',
        'EEG_BANKNAME'          => $community['bank_name'] ?? '',
        'EEG_KONTOINHABER'      => $community['account_holder'] ?? '',
        'MITGLIED_ANREDE'       => 'Herr',
        'MITGLIED_NAME'         => 'Max Mustermann',
        'MITGLIED_ADRESSE'      => 'Musterweg 12, 9020 Klagenfurt',
        'MITGLIED_STRASSE'      => 'Musterweg 12',
        'MITGLIED_PLZ_ORT'      => '9020 Klagenfurt',
        'MITGLIED_UID'          => '',
        'KUNDENNUMMER'          => '123',
        'MITGLIED_SEPA_MANDATSREFERENZ' => 'MUSTER-MANDAT-001',
        'RECHNUNGSNUMMER'       => 'MUSTER-2026-Q1-000',
        'RECHNUNGSDATUM'        => date('d.m.Y'),
        'ABRECHNUNGSZEITRAUM'   => '01.01.2026 -- 31.03.2026',
        'BEZUG_KWH'             => '412,50',
        'BEZUG_TARIF'           => '9,8000',
        'BEZUG_BETRAG'          => '40,42',
        'EINSPEISUNG_KWH'       => '85,00',
        'EINSPEISUNG_TARIF'     => '7,5000',
        'EINSPEISUNG_BETRAG'    => '6,38',
        'RAW_BEZUG_POSITIONEN_LISTE'       => $bezugListe,
        'RAW_EINSPEISUNG_POSITIONEN_LISTE' => $einspListe,
        'MITGLIEDSBEITRAG'      => '15,00',
        'SUMME_NETTO'           => number_format($tbPreview['netto'], 2, ',', '.'),
        'SUMME_BRUTTO'          => number_format($tbPreview['brutto'], 2, ',', '.'),
        'RAW_STEUER_ZEILE'      => $steuerZeile,
        'RAW_STEUER_TEXT'       => $steuerText,
        'RAW_ZUSATZPOSITIONEN_LISTE' => $extraItemsLatex,
        'RAW_ZAHLUNG_TEXT'      => 'Der Rechnungsbetrag von \\textbf{EUR ' . number_format($tbPreview['brutto'], 2, ',', '.') . '} wird gemäß SEPA-Lastschriftmandat'
            . ' (Mandatsreferenz \\textbf{MUSTER-MANDAT-001}) am \\textbf{' . date('d.m.Y', strtotime('+14 days')) . '}'
            . ' von Ihrem Konto eingezogen. Sie müssen nichts weiter veranlassen.'
            . ' Diese Rechnung gilt zugleich als Vorabankündigung (Pre-Notification) im Sinne des SEPA-Lastschriftverfahrens.',
        'RAW_SUMME_LABEL'       => '',
        'IBAN'                  => $community['iban'] ?? 'AT00 0000 0000 0000 0000',
        'BIC'                   => $community['bic'] ?? '',
        'ZAHLUNGSZIEL'          => date('d.m.Y', strtotime('+14 days')),
    ], 'Test-Rechnung.pdf', communityLogoAsset($communityId));
});

/**
 * Legt einen neuen Abrechnungslauf für ein Quartal an (z.B. "2026-Q1"). Ohne diese Route gab
 * es bisher gar keine Möglichkeit, überhaupt einen billing_runs-Eintrag anzulegen -- die
 * gesamte Rechnungs-Funktion war dadurch faktisch nie erreichbar.
 */
$router->post('/portal/billing/create', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $quartal = trim($_POST['quartal'] ?? '');
    if (!preg_match('/^\d{4}-(Q[1-4]|(0[1-9]|1[0-2]))$/', $quartal)) {
        header('Location: /portal/billing?error=' . urlencode('Ungültiges Zeitraum-Format (z.B. 2026-Q1 für ein Quartal oder 2026-07 für einen Monat).'));
        exit;
    }
    try {
        Billing::getOrCreateRun($communityId, $quartal);
        logAudit($communityId, 'billing.create', 'billing_run', null, 'Abrechnungslauf ' . $quartal . ' angelegt');
        header('Location: /portal/billing?success=1');
    } catch (Throwable $e) {
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Manuelle Zusatzposition (z.B. einmaliger Rabatt/Gutschrift) zu einem noch nicht berechneten
 * Abrechnungslauf hinzufügen -- gilt für alle Mitglieder dieses Laufs, wird bei der Berechnung
 * (Billing::generateDrafts()) in jede einzelne Rechnung übernommen.
 */
$router->post('/portal/billing/:id/extra-items', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$run) { http_response_code(404); echo 'Abrechnungslauf nicht gefunden'; return; }
    if ($run['status'] !== 'pending') {
        header('Location: /portal/billing?error=' . urlencode('Zusatzpositionen können nur vor der Freigabe hinzugefügt werden.'));
        exit;
    }
    $label = trim($_POST['label'] ?? '');
    $amount = str_replace(',', '.', trim($_POST['amount_eur'] ?? ''));
    if ($label === '' || !is_numeric($amount)) {
        header('Location: /portal/billing?error=' . urlencode('Bitte Text und Betrag angeben.'));
        exit;
    }
    DB::execute(
        'INSERT INTO billing_run_extra_items (billing_run_id, community_id, label, quantity, unit, amount_eur)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$params['id'], $communityId, $label, str_replace(',', '.', trim($_POST['quantity'] ?? '') ?: '1'),
         trim($_POST['unit'] ?? '') ?: 'Stk', (float)$amount]
    );
    logAudit($communityId, 'billing.extra_item.add', 'billing_run', $params['id'], 'Zusatzposition "' . $label . '" (' . $amount . ' EUR) hinzugefügt');
    header('Location: /portal/billing?success=1');
    exit;
});

$router->post('/portal/billing/:id/extra-items/:itemId/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        'DELETE FROM billing_run_extra_items WHERE id = ? AND billing_run_id = ? AND community_id = ?',
        [$params['itemId'], $params['id'], $communityId]
    );
    logAudit($communityId, 'billing.extra_item.delete', 'billing_run', $params['id'], 'Zusatzposition entfernt');
    header('Location: /portal/billing?success=1');
    exit;
});

/**
 * Eigener Reiter "Rechnungen" (getrennt von /portal/billing, das nur die Abrechnungsläufe pro
 * Quartal zeigt): listet einzelne Rechnungen aller Mitglieder der aktiven Community, filterbar
 * client-seitig nach Kundennummer/Name (Text), Quartal und Betrag (min/max). $_GET['quartal']
 * setzt die Quartals-Auswahl serverseitig vor, damit der "Rechnungen ansehen"-Link aus
 * /portal/billing direkt gefiltert aufmachen kann.
 */
$router->get('/portal/billing/invoices', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal, br.status AS run_status, m.kundennummer, m.first_name, m.last_name,
                m.company_name, m.email, m.member_iban, m.mandatsreferenz,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate
         FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN members m ON m.id = i.member_id
         LEFT JOIN LATERAL (
             SELECT tax_model, tax_rate_percent FROM tax_config
             WHERE community_id = i.community_id AND valid_from <= br.period_from
             ORDER BY valid_from DESC LIMIT 1
         ) tx ON true
         WHERE i.community_id = ?
         ORDER BY i.created_at DESC',
        [$communityId]
    );
    // Anzeige immer in Brutto: bei Kleinunternehmer identisch mit netto, bei Standard inkl. USt.
    foreach ($invoices as &$inv) {
        $inv['brutto_eur'] = taxBreakdown((float)$inv['saldo_eur'], $inv['eeg_tax_model'] ?? null, $inv['eeg_tax_rate'] ?? null)['brutto'];
    }
    unset($inv);
    $quartalFilter = $_GET['quartal'] ?? '';
    // Fortschritt der Zahlungsabwicklung nur über bereits freigegebene Rechnungen (run_status
    // 'done') -- Entwürfe zählen nicht als "offen".
    $released = array_filter($invoices, fn($i) => ($i['run_status'] ?? '') === 'done');
    $paymentDone = count(array_filter($released, fn($i) => in_array($i['payment_status'] ?? 'offen', ['eingezogen', 'ueberwiesen'], true)));
    $paymentTotal = count($released);
    require ROOT . '/src/views/pages/billing_invoices.php';
});

// Schritt 1: Rechnungen aus den EDA-Daten berechnen (Entwurf) -- Lauf geht auf 'ready',
// danach pro Rechnung manuell nachbearbeitbar. Auch erneut aufrufbar ("neu berechnen").
$router->post('/portal/billing/generate', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $runId = $_POST['billing_run_id'] ?? '';
    $communityId = Auth::activeCommunityId();
    try {
        Billing::generateDrafts($runId);
        logAudit($communityId, 'billing.generate', 'billing_run', $runId, 'Rechnungs-Entwürfe berechnet');
        header('Location: /portal/billing?success=' . urlencode('Rechnungs-Entwürfe berechnet. Sie können jede Rechnung vor der Freigabe noch anpassen.'));
    } catch (Throwable $e) {
        logAudit($communityId, 'billing.generate', 'billing_run', $runId, 'Berechnung fehlgeschlagen: ' . $e->getMessage(), true);
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

// Schritt 2: berechneten Lauf endgültig freigeben (60-Tage-Gate). Route-Name "release" bleibt
// aus Kompatibilität, ruft aber jetzt finalize() (Berechnung ist ein eigener Schritt davor).
$router->post('/portal/billing/release', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $runId = $_POST['billing_run_id'] ?? '';
    $communityId = Auth::activeCommunityId();
    try {
        Billing::finalize($runId, Auth::userId());
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Abrechnungslauf freigegeben');
        // Mit der Freigabe gilt die Rechnung als erstellt -- für alle einzuziehenden Salden
        // (saldo > 0) geht am selben Tag die SEPA-Vorabinformation (Pre-Notification) raus, in
        // der das voraussichtliche Abbuchungsdatum (= Rechnungsdatum + Vorlauftage der EEG)
        // angekündigt wird. Der Versand läuft in einem eigenen try, damit ein Mail-/DB-Problem
        // die bereits erfolgreiche Freigabe nicht als "fehlgeschlagen" erscheinen lässt.
        $msg = 'Freigegeben.';
        try {
            $preInfo = sendSepaPrenotifications($communityId, $runId);
            if ($preInfo > 0) $msg = 'Freigegeben. ' . $preInfo . ' SEPA-Vorabinformation(en) versendet.';
        } catch (Throwable $e) {
            error_log('SEPA-Vorabinfo (Lauf ' . $runId . '): ' . $e->getMessage());
            $msg = 'Freigegeben. Hinweis: SEPA-Vorabinformationen konnten nicht versendet werden.';
        }
        header('Location: /portal/billing?success=' . urlencode($msg));
    } catch (Throwable $e) {
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Freigabe fehlgeschlagen: ' . $e->getMessage(), true);
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * EDA-Datenqualitäts-Status eines Abrechnungslaufs setzen (aus dem Monatsbericht/Eder-XLSX
 * übernommen). Ersetzt zusammen mit dem automatischen L3-Check die alte 60-Tage-Frist als
 * Freigabe-Kriterium (siehe Billing::datenqualitaetProblem()).
 */
$router->post('/portal/billing/:id/eda-status', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$run) { header('Location: /portal/billing?error=' . urlencode('Abrechnungslauf nicht gefunden.')); exit; }
    try {
        Billing::setEdaStatus($params['id'], $_POST['eda_status'] ?? 'unbekannt');
        logAudit($communityId, 'billing.eda_status', 'billing_run', $params['id'], 'EDA-Datenstatus auf "' . ($_POST['eda_status'] ?? '') . '" gesetzt');
        header('Location: /portal/billing?success=1');
    } catch (Throwable $e) {
        header('Location: /portal/billing?error=' . urlencode($e->getMessage()));
    }
    exit;
});

/**
 * Sammelt die für einen freigegebenen Abrechnungslauf einzuziehenden Rechnungen (saldo > 0)
 * inkl. der SEPA-Mandatsdaten des jeweiligen Mitglieds. Rechnungen ohne verwertbares Mandat
 * (fehlende IBAN oder Mandatsreferenz) werden separat unter 'ohne_mandat' zurückgegeben, damit
 * der Obmann sie nicht stillschweigend übergeht.
 * Rückgabe: ['creditor' => [...], 'txns' => [...], 'ohne_mandat' => [...]].
 */
function sepaCollectionData(string $communityId, string $runId): array
{
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $run = DB::fetchOne('SELECT period_from FROM billing_runs WHERE id = ?', [$runId]);
    // Für den Einzug zählt der Brutto-Betrag (bei Standard inkl. USt) -- gleiche Steuerlogik
    // wie auf der Rechnung, damit Rechnung, SEPA und Vorabinfo denselben Betrag ausweisen.
    $tx = DB::fetchOne(
        'SELECT tax_model, tax_rate_percent FROM tax_config WHERE community_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1',
        [$communityId, $run['period_from'] ?? date('Y-m-d')]
    );
    $rows = DB::fetchAll(
        "SELECT i.rechnungsnummer, i.saldo_eur, br.quartal,
                m.first_name, m.last_name, m.company_name, m.member_iban, m.member_bic,
                m.mandatsreferenz,
                COALESCE(m.sepa_mandate_date, m.beitrittsdatum, m.member_since, CURRENT_DATE) AS mandate_date
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN members m ON m.id = i.member_id
          WHERE i.billing_run_id = ? AND i.community_id = ? AND i.saldo_eur > 0
          ORDER BY m.kundennummer",
        [$runId, $communityId]
    );
    $txns = [];
    $ohneMandat = [];
    foreach ($rows as $r) {
        $name = trim(($r['company_name'] ?: '') ?: ($r['first_name'] . ' ' . $r['last_name']));
        $iban = trim((string)$r['member_iban']);
        $ref  = trim((string)$r['mandatsreferenz']);
        $brutto = taxBreakdown((float)$r['saldo_eur'], $tx['tax_model'] ?? null, $tx['tax_rate_percent'] ?? null)['brutto'];
        // Ohne verwertbares Mandat (fehlende/ungültige IBAN oder fehlende Mandatsreferenz) NICHT
        // in die Sammellastschrift aufnehmen -- eine einzige ungültige IBAN lässt sonst die Bank
        // die komplette Datei zurückweisen.
        if ($iban === '' || $ref === '' || !validateIban($iban)) {
            $ohneMandat[] = ['name' => $name, 'rechnungsnummer' => $r['rechnungsnummer'], 'saldo' => $brutto];
            continue;
        }
        $txns[] = [
            'end_to_end_id' => $r['rechnungsnummer'],
            'amount'        => $brutto,
            'mandate_ref'   => $ref,
            'mandate_date'  => substr((string)$r['mandate_date'], 0, 10),
            'debtor_name'   => $name,
            'debtor_iban'   => $iban,
            'debtor_bic'    => trim((string)$r['member_bic']),
            'remittance'    => 'Rechnung ' . $r['rechnungsnummer'] . ' (' . $r['quartal'] . ') ' . ($community['name'] ?? ''),
        ];
    }
    $creditor = [
        'name'        => $community['name'] ?? '',
        'iban'        => trim((string)($community['iban'] ?? '')),
        'bic'         => trim((string)($community['bic'] ?? '')),
        'creditor_id' => trim((string)($community['creditor_id'] ?? '')),
    ];
    return ['creditor' => $creditor, 'txns' => $txns, 'ohne_mandat' => $ohneMandat, 'community' => $community];
}

/**
 * Versendet die SEPA-Vorabinformation für alle noch nicht vorab-informierten, einzuziehenden
 * Rechnungen (saldo > 0, Mandat vorhanden) eines freigegebenen Laufs. Gibt die Anzahl
 * tatsächlich versendeter Mails zurück. Setzt invoices.prenotified_at, damit bei einer
 * erneuten Freigabe/Verarbeitung keine Doppel-Mail entsteht.
 */
function sendSepaPrenotifications(string $communityId, string $runId): int
{
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $days = (int)($community['sepa_prenotification_days'] ?? 14);
    $abbuchung = date('d.m.Y', strtotime('+' . $days . ' days'));
    $rows = DB::fetchAll(
        "SELECT i.id, i.rechnungsnummer, i.saldo_eur, br.quartal, br.period_from,
                m.first_name, m.last_name, m.company_name, m.salutation, m.titel,
                m.email_anrede_mode, m.email, m.mandatsreferenz, m.member_iban
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN members m ON m.id = i.member_id
          WHERE i.billing_run_id = ? AND i.community_id = ? AND i.saldo_eur > 0
            AND i.prenotified_at IS NULL",
        [$runId, $communityId]
    );
    // USt-Modell einmal für den Lauf bestimmen (Betrag in der Vorabinfo = Brutto).
    $tx = DB::fetchOne(
        'SELECT tax_model, tax_rate_percent FROM tax_config WHERE community_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1',
        [$communityId, $rows[0]['period_from'] ?? date('Y-m-d')]
    );
    $sent = 0;
    foreach ($rows as $r) {
        $ref = trim((string)$r['mandatsreferenz']);
        $iban = trim((string)$r['member_iban']);
        if (empty($r['email']) || $ref === '' || $iban === '') continue;
        $brutto = taxBreakdown((float)$r['saldo_eur'], $tx['tax_model'] ?? null, $tx['tax_rate_percent'] ?? null)['brutto'];
        $anrede = mailSalutation($r);
        try {
            $mail = renderMailTemplate('sepa_prenotification', [
                'vorname'        => htmlspecialchars((string)$r['first_name']),
                'anrede'         => htmlspecialchars($anrede['anrede']),
                'nachname'       => htmlspecialchars($anrede['nachname']),
                'eeg_name'       => htmlspecialchars((string)($community['name'] ?? '')),
                'rechnungsnummer'=> htmlspecialchars((string)$r['rechnungsnummer']),
                'betrag'         => number_format($brutto, 2, ',', '.'),
                'abbuchung'      => $abbuchung,
                'mandatsreferenz'=> htmlspecialchars($ref),
                'creditor_id'    => htmlspecialchars((string)($community['creditor_id'] ?? '')),
            ],
                'SEPA-Vorabinformation zu Rechnung {{rechnungsnummer}} – {{eeg_name}}',
                '<p>{{anrede}} {{nachname}},</p>'
                . '<p>Ihre Rechnung <strong>{{rechnungsnummer}}</strong> über <strong>{{betrag}} €</strong> wird '
                . 'im Wege des SEPA-Lastschriftverfahrens am <strong>{{abbuchung}}</strong> von Ihrem Konto eingezogen. '
                . 'Sie müssen nichts weiter veranlassen.</p>'
                . '<p>Mandatsreferenz: {{mandatsreferenz}}<br>Gläubiger-ID: {{creditor_id}}</p>'
                . '<p>Diese E-Mail gilt als Vorabankündigung (Pre-Notification) im Sinne des SEPA-Lastschriftverfahrens.</p>'
            );
            Mailer::send($r['email'], $mail['subject'], $mail['body']);
            DB::execute('UPDATE invoices SET prenotified_at = now() WHERE id = ?', [$r['id']]);
            $sent++;
        } catch (Throwable $e) {
            error_log('SEPA-Vorabinfo fehlgeschlagen für Rechnung ' . $r['rechnungsnummer'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * SEPA-Sammellastschrift (pain.008) für einen freigegebenen Abrechnungslauf herunterladen.
 * Enthält nur einzuziehende Rechnungen (saldo > 0) mit gültigem Mandat. Format (.08/.02) und
 * Gläubiger-ID stammen aus den EEG-Einstellungen.
 */
$router->get('/portal/billing/:id/sepa-xml', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$run) { http_response_code(404); echo 'Abrechnungslauf nicht gefunden'; return; }
    if ($run['status'] !== 'done') {
        header('Location: /portal/billing?error=' . urlencode('SEPA-Datei erst nach der Freigabe verfügbar.')); exit;
    }
    $data = sepaCollectionData($communityId, $params['id']);
    $community = $data['community'];
    if ($data['creditor']['creditor_id'] === '' || $data['creditor']['iban'] === '') {
        header('Location: /portal/billing/invoices?quartal=' . urlencode($run['quartal'])
            . '&error=' . urlencode('Bitte zuerst Gläubiger-ID und EEG-IBAN in den Einstellungen hinterlegen.')); exit;
    }
    if (empty($data['txns'])) {
        header('Location: /portal/billing/invoices?quartal=' . urlencode($run['quartal'])
            . '&error=' . urlencode('Keine einzuziehenden Rechnungen mit gültigem SEPA-Mandat in diesem Lauf.')); exit;
    }
    $version = ($community['sepa_pain_version'] ?? '08') === '02' ? '02' : '08';
    $days    = (int)($community['sepa_prenotification_days'] ?? 14);
    // Abbuchungsdatum = Rechnungsdatum (Freigabe) + Vorlauftage, passend zur Vorabinformation.
    // Liegt dieser Tag bereits in der Vergangenheit, frühestens übermorgen einziehen.
    $collect = date('Y-m-d', strtotime(($run['released_at'] ?: 'now') . ' +' . $days . ' days'));
    if ($collect < date('Y-m-d', strtotime('+2 days'))) {
        $collect = date('Y-m-d', strtotime('+2 days'));
    }
    $msgId = 'SFA-' . preg_replace('/[^A-Za-z0-9]/', '', $run['quartal']) . '-' . date('YmdHis');
    $xml = sepaPain008Xml($data['creditor'], $data['txns'], $collect, $version, 'RCUR', $msgId);
    logAudit($communityId, 'billing.sepa_export', 'billing_run', $params['id'],
        'SEPA-XML (pain.008.001.' . $version . ') mit ' . count($data['txns']) . ' Lastschrift(en) erzeugt');
    $fname = 'SEPA-' . preg_replace('/[^A-Za-z0-9]/', '', $run['quartal']) . '-pain008-' . $version . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
});

/**
 * SEPA-Test-XML mit Beispieldaten herunterladen -- damit die EEG die Datei schon beim
 * Bank-/Prüftool testen kann, BEVOR echte Abrechnungsdaten (EDA) vorliegen. Nutzt die echte
 * Gläubiger-Konfiguration der EEG (Name/IBAN/BIC/Gläubiger-ID), falls hinterlegt, sonst
 * Platzhalter; die Schuldner sind reine Beispiel-Datensätze. Es entsteht kein Datenbankeintrag
 * und kein echter Einzug.
 */
$router->get('/portal/billing/sepa-test-xml', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $c = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $version = ($c['sepa_pain_version'] ?? '08') === '02' ? '02' : '08';
    $days    = (int)($c['sepa_prenotification_days'] ?? 14);
    $creditor = [
        'name'        => trim((string)($c['name'] ?? '')) ?: 'Beispiel-EEG',
        'iban'        => trim((string)($c['iban'] ?? '')) ?: 'AT611904300234573201',
        'bic'         => trim((string)($c['bic'] ?? '')) ?: 'BKAUATWW',
        'creditor_id' => trim((string)($c['creditor_id'] ?? '')) ?: 'AT12ZZZ00000000001',
    ];
    $txns = [
        ['end_to_end_id' => 'TEST-2026-Q1-001', 'amount' => 42.50, 'mandate_ref' => 'S00001F2026A100', 'mandate_date' => '2026-01-15',
         'debtor_name' => 'Max Mustermann', 'debtor_iban' => 'AT162011100000001234', 'debtor_bic' => 'GIBAATWWXXX', 'remittance' => 'TESTRECHNUNG 2026-Q1 (keine echte Abbuchung)'],
        ['end_to_end_id' => 'TEST-2026-Q1-002', 'amount' => 18.90, 'mandate_ref' => 'S00002F2026A101', 'mandate_date' => '2026-02-01',
         'debtor_name' => 'Anna Beispiel', 'debtor_iban' => 'AT613200000000005678', 'debtor_bic' => '', 'remittance' => 'TESTRECHNUNG 2026-Q1 (keine echte Abbuchung)'],
        ['end_to_end_id' => 'TEST-2026-Q1-003', 'amount' => 7.15, 'mandate_ref' => 'S00003F2026A102', 'mandate_date' => '2026-03-10',
         'debtor_name' => 'Familie Test', 'debtor_iban' => 'AT381200000002731300', 'debtor_bic' => 'BKAUATWW', 'remittance' => 'TESTRECHNUNG 2026-Q1 (keine echte Abbuchung)'],
    ];
    $collect = date('Y-m-d', strtotime('+' . max($days, 2) . ' days'));
    $xml = sepaPain008Xml($creditor, $txns, $collect, $version, 'RCUR', 'SFA-TEST-' . date('YmdHis'));
    logAudit($communityId, 'billing.sepa_test_export', 'community', $communityId, 'SEPA-Test-XML (pain.008.001.' . $version . ') erzeugt');
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="SEPA-Testdatei-pain008-' . $version . '.xml"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
});

/**
 * Zahlungsstatus einer einzelnen Rechnung setzen: positive Salden nach erfolgtem SEPA-Einzug
 * auf 'eingezogen', negative Salden nach erfolgter Überweisung durch den Obmann auf
 * 'ueberwiesen' -- erst dann gilt die Rechnung als erledigt. 'offen' setzt zurück.
 */
$router->post('/portal/billing/invoices/:id/mark-paid', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $inv = DB::fetchOne('SELECT * FROM invoices WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$inv) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    $status = $_POST['payment_status'] ?? '';
    $saldo  = (float)$inv['saldo_eur'];
    // Plausibilität: einziehen nur bei Forderung (>0), überweisen nur bei Guthaben (<0).
    $allowed = ($status === 'eingezogen' && $saldo > 0)
            || ($status === 'ueberwiesen' && $saldo < 0)
            || ($status === 'fehlgeschlagen')
            || ($status === 'offen');
    if (!$allowed) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Ungültiger Zahlungsstatus für diese Rechnung.')); exit;
    }
    $paidAt = in_array($status, ['eingezogen', 'ueberwiesen'], true) ? 'now()' : 'NULL';
    DB::execute("UPDATE invoices SET payment_status = ?, paid_at = $paidAt WHERE id = ? AND community_id = ?",
        [$status, $params['id'], $communityId]);
    logAudit($communityId, 'billing.payment_status', 'invoice', $params['id'],
        'Zahlungsstatus von Rechnung ' . $inv['rechnungsnummer'] . ' auf "' . $status . '" gesetzt');
    header('Location: /portal/billing/invoices?success=1');
    exit;
});

/**
 * Rücklastschrift melden: eine per SEPA eingezogene Rechnung wurde von der Bank zurückgebucht
 * (R-Transaktion). Setzt payment_status='fehlgeschlagen' und merkt sich den Zeitpunkt -- danach
 * kann gemahnt werden.
 */
$router->post('/portal/billing/invoices/:id/ruecklastschrift', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $inv = DB::fetchOne('SELECT * FROM invoices WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$inv) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    DB::execute(
        "UPDATE invoices SET payment_status = 'fehlgeschlagen', paid_at = NULL, ruecklastschrift_at = now()
         WHERE id = ? AND community_id = ?",
        [$params['id'], $communityId]
    );
    logAudit($communityId, 'billing.ruecklastschrift', 'invoice', $params['id'],
        'Rücklastschrift zu Rechnung ' . $inv['rechnungsnummer'] . ' erfasst (Zahlung offen)');
    header('Location: /portal/billing/invoices?success=1');
    exit;
});

/**
 * Nächste Mahnstufe auslösen: Stufe hochzählen (max. 3), konfigurierte Mahngebühr aufschlagen und
 * die Zahlungserinnerung/Mahnung per E-Mail verschicken. Nur für freigegebene, noch offene bzw.
 * fehlgeschlagene Rechnungen mit Forderung (Saldo > 0).
 */
$router->post('/portal/billing/invoices/:id/mahnung', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $inv = DB::fetchOne(
        'SELECT i.*, br.status AS run_status, c.name AS eeg_name, c.iban AS eeg_iban, c.mahngebuehr_eur,
                c.sepa_prenotification_days,
                tx.tax_model AS eeg_tax_model, tx.tax_rate_percent AS eeg_tax_rate,
                m.first_name, m.last_name, m.company_name, m.salutation, m.titel, m.email_anrede_mode, m.email
           FROM invoices i
           JOIN billing_runs br ON br.id = i.billing_run_id
           JOIN communities c ON c.id = i.community_id
           JOIN members m ON m.id = i.member_id
           LEFT JOIN LATERAL (
               SELECT tax_model, tax_rate_percent FROM tax_config
               WHERE community_id = i.community_id AND valid_from <= br.period_from
               ORDER BY valid_from DESC LIMIT 1
           ) tx ON true
          WHERE i.id = ? AND i.community_id = ?',
        [$params['id'], $communityId]
    );
    if (!$inv) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    if (($inv['run_status'] ?? '') !== 'done') {
        header('Location: /portal/billing/invoices?error=' . urlencode('Nur freigegebene Rechnungen können gemahnt werden.')); exit;
    }
    if ((float)$inv['saldo_eur'] <= 0) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Nur Rechnungen mit offener Forderung können gemahnt werden.')); exit;
    }
    if (in_array($inv['payment_status'], ['eingezogen', 'ueberwiesen'], true)) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Diese Rechnung ist bereits bezahlt.')); exit;
    }
    if ((int)$inv['mahnstufe'] >= 3) {
        header('Location: /portal/billing/invoices?error=' . urlencode('Höchste Mahnstufe bereits erreicht – bitte weiteres Vorgehen (Inkasso o.ä.) außerhalb der Plattform klären.')); exit;
    }

    $neueStufe = (int)$inv['mahnstufe'] + 1;
    $gebuehr   = round((float)($inv['mahngebuehr_eur'] ?? 0), 2);
    $brutto    = taxBreakdown((float)$inv['saldo_eur'], $inv['eeg_tax_model'] ?? null, $inv['eeg_tax_rate'] ?? null)['brutto'];
    $gebuehrSummeNeu = round((float)$inv['mahn_gebuehr_summe_eur'] + $gebuehr, 2);
    $gesamt    = round($brutto + $gebuehrSummeNeu, 2);
    $fristTage = (int)($inv['sepa_prenotification_days'] ?? 14);
    $frist     = date('d.m.Y', strtotime('+' . max(7, $fristTage) . ' days'));

    $mailError = null;
    if (!empty($inv['email'])) {
        try {
            $anrede = mailSalutation($inv);
            $mail = renderMailTemplate('mahnung', [
                'anrede'          => htmlspecialchars($anrede['anrede']),
                'nachname'        => htmlspecialchars($anrede['nachname']),
                'eeg_name'        => htmlspecialchars((string)$inv['eeg_name']),
                'rechnungsnummer' => htmlspecialchars((string)$inv['rechnungsnummer']),
                'mahnstufe_text'  => htmlspecialchars(mahnstufeText($neueStufe)),
                'betrag'          => number_format($brutto, 2, ',', '.'),
                'gesamt'          => number_format($gesamt, 2, ',', '.'),
                'gebuehr_zeile'   => $gebuehrSummeNeu > 0 ? '<br>Mahngebühren: ' . number_format($gebuehrSummeNeu, 2, ',', '.') . ' €' : '',
                'ruecklast_hinweis' => !empty($inv['ruecklastschrift_at']) ? ' (die SEPA-Lastschrift wurde von Ihrer Bank zurückgebucht)' : '',
                'frist'           => $frist,
                'iban'            => htmlspecialchars((string)($inv['eeg_iban'] ?? '')),
            ],
                '{{mahnstufe_text}}: Rechnung {{rechnungsnummer}} – {{eeg_name}}',
                '<p>{{anrede}} {{nachname}},</p>'
                . '<p>zu Ihrer Rechnung <strong>{{rechnungsnummer}}</strong> ist bei uns bislang kein vollständiger Zahlungseingang verbucht{{ruecklast_hinweis}}.</p>'
                . '<p>Offener Betrag: <strong>{{betrag}} €</strong>{{gebuehr_zeile}}<br>Bitte zu überweisen: <strong>{{gesamt}} €</strong></p>'
                . '<p>Wir bitten Sie, den Betrag bis spätestens <strong>{{frist}}</strong> auf folgendes Konto zu überweisen:<br>'
                . 'IBAN: {{iban}}<br>Verwendungszweck: {{rechnungsnummer}}</p>'
                . '<p>Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben, betrachten Sie es bitte als gegenstandslos.</p>'
            );
            Mailer::send($inv['email'], $mail['subject'], $mail['body']);
        } catch (Throwable $e) {
            $mailError = $e->getMessage();
            error_log('Mahnung-Mail fehlgeschlagen für ' . $inv['rechnungsnummer'] . ': ' . $e->getMessage());
        }
    }

    DB::execute(
        'UPDATE invoices SET mahnstufe = ?, mahn_gebuehr_summe_eur = ?, letzte_mahnung_at = now() WHERE id = ? AND community_id = ?',
        [$neueStufe, $gebuehrSummeNeu, $params['id'], $communityId]
    );
    logAudit($communityId, 'billing.mahnung', 'invoice', $params['id'],
        mahnstufeText($neueStufe) . ' (Stufe ' . $neueStufe . ') zu Rechnung ' . $inv['rechnungsnummer']
        . ' ausgelöst' . ($gebuehr > 0 ? ', Mahngebühr ' . number_format($gebuehr, 2, ',', '.') . ' €' : '')
        . ($mailError ? ' – Mailversand fehlgeschlagen' : ''));

    $msg = $mailError
        ? 'Mahnstufe gesetzt, aber E-Mail-Versand fehlgeschlagen: ' . $mailError
        : mahnstufeText($neueStufe) . ' verschickt.';
    header('Location: /portal/billing/invoices?success=' . urlencode($msg));
    exit;
});

/**
 * Einzelbearbeitung einer Rechnung vor der Freigabe: nur solange der Abrechnungslauf im
 * Status 'ready' ist (nach der Berechnung, vor der endgültigen Freigabe). Zeigt alle
 * Positionen einer Rechnung und erlaubt Bearbeiten/Löschen/Hinzufügen; der Saldo wird nach
 * jeder Änderung neu berechnet.
 */
$router->get('/portal/billing/invoices/:id/edit', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $invoice = DB::fetchOne(
        'SELECT i.*, br.status AS run_status, br.quartal, m.first_name, m.last_name, m.kundennummer
         FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN members m ON m.id = i.member_id
         WHERE i.id = ? AND i.community_id = ?',
        [$params['id'], $communityId]
    );
    if (!$invoice) { http_response_code(404); echo 'Rechnung nicht gefunden'; return; }
    $items = DB::fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY type', [$params['id']]);
    require ROOT . '/src/views/pages/invoice_edit.php';
});

/** Hilfsfunktion: prüft, dass die Rechnung existiert, zur Community gehört und ihr Lauf noch
 *  im Status 'ready' ist (nur dann darf bearbeitet werden). Gibt die Rechnung zurück oder null. */
function editableInvoiceOrNull(string $invoiceId, string $communityId): ?array
{
    $inv = DB::fetchOne(
        'SELECT i.id, br.status AS run_status FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.id = ? AND i.community_id = ?',
        [$invoiceId, $communityId]
    );
    return ($inv && $inv['run_status'] === 'ready') ? $inv : null;
}

$router->post('/portal/billing/invoices/:id/items/add', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    if (!editableInvoiceOrNull($params['id'], $communityId)) {
        header('Location: /portal/billing?error=' . urlencode('Diese Rechnung kann nicht (mehr) bearbeitet werden.')); exit;
    }
    $label  = trim($_POST['label'] ?? '');
    $amount = str_replace(',', '.', trim($_POST['amount_eur'] ?? ''));
    if ($label === '' || !is_numeric($amount)) {
        header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?error=' . urlencode('Bitte Text und Betrag angeben.')); exit;
    }
    DB::execute(
        'INSERT INTO invoice_items (invoice_id, type, label, quantity, unit, amount_eur) VALUES (?, ?, ?, ?, ?, ?)',
        [$params['id'], 'manuell', $label, str_replace(',', '.', trim($_POST['quantity'] ?? '') ?: '1'),
         trim($_POST['unit'] ?? '') ?: 'Stk', (float)$amount]
    );
    Billing::recalcInvoiceSaldo($params['id']);
    logAudit($communityId, 'invoice.item.add', 'invoice', $params['id'], 'Position "' . $label . '" (' . $amount . ' EUR) hinzugefügt');
    header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?success=1');
    exit;
});

$router->post('/portal/billing/invoices/:id/items/:itemId/update', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    if (!editableInvoiceOrNull($params['id'], $communityId)) {
        header('Location: /portal/billing?error=' . urlencode('Diese Rechnung kann nicht (mehr) bearbeitet werden.')); exit;
    }
    $amount = str_replace(',', '.', trim($_POST['amount_eur'] ?? ''));
    if (!is_numeric($amount)) {
        header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?error=' . urlencode('Ungültiger Betrag.')); exit;
    }
    // label nur bei manuellen Positionen editierbar; Beträge bei allen.
    DB::execute(
        'UPDATE invoice_items SET amount_eur = ?, label = COALESCE(?, label) WHERE id = ? AND invoice_id = ?',
        [(float)$amount, (array_key_exists('label', $_POST) ? trim($_POST['label']) : null), $params['itemId'], $params['id']]
    );
    Billing::recalcInvoiceSaldo($params['id']);
    logAudit($communityId, 'invoice.item.update', 'invoice', $params['id'], 'Position angepasst (' . $amount . ' EUR)');
    header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?success=1');
    exit;
});

$router->post('/portal/billing/invoices/:id/items/:itemId/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    if (!editableInvoiceOrNull($params['id'], $communityId)) {
        header('Location: /portal/billing?error=' . urlencode('Diese Rechnung kann nicht (mehr) bearbeitet werden.')); exit;
    }
    DB::execute('DELETE FROM invoice_items WHERE id = ? AND invoice_id = ?', [$params['itemId'], $params['id']]);
    Billing::recalcInvoiceSaldo($params['id']);
    logAudit($communityId, 'invoice.item.delete', 'invoice', $params['id'], 'Position gelöscht');
    header('Location: /portal/billing/invoices/' . $params['id'] . '/edit?success=1');
    exit;
});

$router->post('/portal/billing/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isManager()) { http_response_code(403); echo 'Kein Zugriff.'; return; }
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $run = DB::fetchOne('SELECT quartal FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    // Löscht kaskadierend die zugehörigen Rechnungen/Rechnungspositionen (siehe migrate_20260715.sql).
    DB::execute('DELETE FROM billing_runs WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    logAudit($communityId, 'billing.delete', 'billing_run', $params['id'], 'Abrechnungslauf ' . ($run['quartal'] ?? '?') . ' gelöscht');
    header('Location: /portal/billing?success=1');
    exit;
});

// ─── Portal: Internes Postfach (Benachrichtigungen) ─────
$router->get('/portal/postfach', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $notifications = DB::fetchAll(
        "SELECT * FROM notifications WHERE community_id = ? ORDER BY (status = 'offen') DESC, created_at DESC",
        [$communityId]
    );
    require ROOT . '/src/views/pages/postfach.php';
});

$router->post('/portal/postfach/:id/erledigt', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ? WHERE id = ? AND community_id = ?",
        [Auth::userId(), $params['id'], $communityId]
    );
    header('Location: /portal/postfach?success=1');
    exit;
});

// ─── Portal: Support-Tickets (Manager/Platform-Admin) ───
$router->get('/portal/support', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $statusFilter = $_GET['status'] ?? '';
    $where = 't.community_id = ?';
    $params = [$communityId];
    if (in_array($statusFilter, ['offen', 'in_bearbeitung', 'geschlossen'], true)) {
        $where .= ' AND t.status = ?';
        $params[] = $statusFilter;
    }
    $tickets = DB::fetchAll(
        "SELECT t.*, m.first_name, m.last_name,
                EXISTS(
                    SELECT 1 FROM support_ticket_messages sm
                    WHERE sm.ticket_id = t.id AND sm.is_staff = false
                      AND sm.created_at > COALESCE(t.manager_read_at, '-infinity'::timestamptz)
                ) AS hat_ungelesen
         FROM support_tickets t JOIN members m ON m.id = t.member_id
         WHERE $where ORDER BY (t.status = 'offen') DESC, t.updated_at DESC",
        $params
    );
    require ROOT . '/src/views/pages/support_tickets.php';
});

$router->get('/portal/support/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $ticket = DB::fetchOne(
        'SELECT t.*, m.first_name, m.last_name, m.email
         FROM support_tickets t JOIN members m ON m.id = t.member_id
         WHERE t.id = ? AND t.community_id = ?',
        [$params['id'], $communityId]
    );
    if (!$ticket) { http_response_code(404); echo 'Ticket nicht gefunden.'; return; }
    $messages = DB::fetchAll('SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC', [$ticket['id']]);
    // Öffnen der Detailseite gilt als "gelesen" -- alle bisherigen Mitglieder-Nachrichten dieses
    // Tickets zählen ab jetzt nicht mehr zum Ungelesen-Badge in der Sidebar.
    DB::execute('UPDATE support_tickets SET manager_read_at = now() WHERE id = ?', [$ticket['id']]);
    require ROOT . '/src/views/pages/support_ticket_detail.php';
});

$router->post('/portal/support/:id/reply', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $ticket = DB::fetchOne('SELECT * FROM support_tickets WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$ticket) { http_response_code(404); echo 'Ticket nicht gefunden.'; return; }
    $message = trim($_POST['message'] ?? '');
    if ($message !== '') {
        DB::execute(
            'INSERT INTO support_ticket_messages (ticket_id, author_label, is_staff, message) VALUES (?, ?, true, ?)',
            [$ticket['id'], Auth::userName() ?: 'Verwaltung', $message]
        );
    }
    $newStatus = $_POST['status'] ?? '';
    if (in_array($newStatus, ['offen', 'in_bearbeitung', 'geschlossen'], true)) {
        DB::execute('UPDATE support_tickets SET status = ?, updated_at = now() WHERE id = ?', [$newStatus, $ticket['id']]);
    } elseif ($message !== '') {
        DB::execute('UPDATE support_tickets SET updated_at = now() WHERE id = ?', [$ticket['id']]);
    }
    header('Location: /portal/support/' . $ticket['id']);
    exit;
});

// ─── Portal: Online-Beitrittserklärungen (Freigabe) ─────
$router->get('/portal/applications', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    // Nur wirklich neue (unbearbeitete) Anfragen -- abgeschlossene (freigegeben/abgelehnt) sind
    // schon am jeweiligen Mitglied über den Online/Offline-Badge und den Formular-Ausdruck
    // nachvollziehbar und müssen die Neuanmeldungen-Übersicht nicht mehr zumüllen.
    $applications = DB::fetchAll(
        "SELECT * FROM membership_applications WHERE community_id = ? AND status = 'pending' ORDER BY created_at DESC",
        [$communityId]
    );
    require ROOT . '/src/views/pages/applications_list.php';
});

$router->get('/portal/applications/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne('SELECT * FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$application) { http_response_code(404); echo 'Nicht gefunden'; return; }
    require ROOT . '/src/views/pages/application_detail.php';
});

$router->get('/portal/applications/:id/formular', function ($params) {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $a = DB::fetchOne('SELECT * FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$a) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // Zugriff: Manager/Platform-Admin der Community (bisheriges Verhalten) ODER das Mitglied
    // selbst, dessen eigene Beitrittserklärung das ist (Selbstbedienung über /portal/my/documents).
    if (!Auth::isManager()) {
        $ownMember = currentMemberFull();
        if (!$ownMember || $a['member_id'] !== $ownMember['id']) {
            http_response_code(403); echo 'Kein Zugriff'; return;
        }
    }

    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);

    $isTrue = fn($v) => in_array($v, [true, 't', '1', 1], true);
    $cb = fn(bool $checked) => $checked ? '\\cbon' : '\\cb';

    $eegNameEsc = texEscape($community['name']);
    $eegZvrEsc  = texEscape($community['zvr_number'] ?? '--');
    $eegOrtEsc  = texEscape(extractOrtFromAddress($community['address']));

    // Anrede-Checkboxen (Divers hat am Papierformular kein eigenes Kästchen,
    // wird stattdessen im Titel-Feld vermerkt)
    $salutation = $a['salutation'] ?? '';
    $titelDisplay = trim(($a['titel'] ?? '') . ($salutation === 'Divers' ? ' · Divers' : ''));

    $speicherStatus = $a['speicher_status'] ?? '';

    // SEPA-Block: exakt im Kasten-Layout des Papierformulars, mit echten Werten.
    // Unterschrift per 0x0-Box (wie in den Verträgen): schwebt über der Linie statt sie
    // nach unten zu schieben -- Box bleibt dadurch kompakt, ob mit oder ohne Bild.
    $sepaAssets = [];
    if (trim($a['iban'] ?? '') !== '') {
        $sepaSigBox = '';
        if (!empty($a['sepa_signature_image'])) {
            $sepaAssets['sepa_unterschrift.png'] = $a['sepa_signature_image'];
            $sepaSigBox = '\\makebox[0pt][l]{\\raisebox{0.15\\baselineskip}[0pt][0pt]{\\includegraphics[height=0.85cm]{sepa_unterschrift.png}}}';
        }
        $sepaSignedAt = $a['sepa_signed_at'] ? date('d.m.Y H:i', strtotime($a['sepa_signed_at'])) : '--';
        $sepaBlock =
            '\\begin{tcolorbox}[colback=egreenlight, colframe=egreen, boxrule=0.6pt, arc=2pt, left=7pt, right=7pt, top=3pt, bottom=3pt]' . "\n"
            . '\\small\\noindent' . "\n"
            . '\\begin{minipage}[t]{7.6cm}' . "\n"
            . '\\textbf{SEPA-Lastschrift-Mandat:}\\par\\vspace{4pt}' . "\n"
            . 'IBAN: ' . texEscape($a['iban']) . '\\\\[4pt]' . "\n"
            . 'BIC: ' . ($a['bic'] ? texEscape($a['bic']) : '--') . '\\\\[4pt]' . "\n"
            . 'Kontoinhaber:in: ' . texEscape($a['kontoinhaber'] ?: ($a['first_name'] . ' ' . $a['last_name'])) . '\\\\[4pt]' . "\n"
            . 'Adresse (falls abw.): ' . ($a['konto_adresse'] ? texEscape($a['konto_adresse']) : '--') . "\n"
            . '\\end{minipage}\\hfill' . "\n"
            . '\\begin{minipage}[t]{8.8cm}' . "\n"
            . '\\scriptsize Hiermit ermächtige ich die Erneuerbare-Energie-Gemeinschaft ' . $eegNameEsc . ', ZVR ' . $eegZvrEsc
            . ', Sitz ' . $eegOrtEsc . ', Creditor-ID: \\textbf{AT14EEG00000086499}, widerruflich, die von mir zu entrichtenden'
            . ' Zahlungen bei Fälligkeit zu Lasten meines Kontos mittels wiederkehrender SEPA-Lastschriften einzuziehen.'
            . ' Zugleich weise ich mein Kreditinstitut an, die von der ' . $eegNameEsc . ' auf mein Konto gezogenen'
            . ' SEPA-Lastschriften einzulösen. Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die'
            . ' Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten'
            . ' Bedingungen.' . "\n"
            . '\\end{minipage}\\par' . "\n"
            . '\\vspace{1cm}\\noindent' . "\n"
            . $sepaSigBox . '\\rule{6.5cm}{0.4pt}\\\\[1pt]' . "\n"
            . '{\\scriptsize Unterschrift (Kontoinhaber:in)}\\hfill{\\scriptsize Unterschrieben am ' . $sepaSignedAt . '}' . "\n"
            . '\\end{tcolorbox}';
    } else {
        $sepaBlock =
            '\\begin{tcolorbox}[colback=egreenlight, colframe=egreen, boxrule=0.6pt, arc=2pt, left=7pt, right=7pt, top=4pt, bottom=4pt]' . "\n"
            . '\\small Es wurde keine SEPA-Lastschrift vereinbart (keine IBAN angegeben).' . "\n"
            . '\\end{tcolorbox}';
    }

    // Rechtliche Zustimmungen: voller Wortlaut (nicht nur Kurzlabel) -- dieser Ausdruck ist
    // der nachvollziehbare Beleg dessen, was tatsächlich online unterschrieben wurde, und soll
    // deshalb für sich stehen können, unabhängig davon ob/wie die Website später geändert wird.
    $consentTexts = [
        'zustimmung_mitgliedschaft'      => 'Vereins- und EEG-Mitgliedschaft: Ich beantrage die Mitgliedschaft im Verein und nehme die Vereinsstatuten zur Kenntnis.',
        'zustimmung_vollmacht'           => 'Vollmacht: Ich bevollmächtige den Vorstand zur Zustimmungserklärung und Übermittlung der Viertelstundenwerte gegenüber dem Netzbetreiber.',
        'zustimmung_widerrufsfrist'      => 'Beginn vor Ablauf der Rücktrittsfrist: Ich stimme zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt.',
        'zustimmung_email_kommunikation' => 'E-Mail-Rechnung/-Korrespondenz: Ich stimme der Zustellung von Rechnungen und vereinsrelevanten Dokumenten per E-Mail zu.',
        'zustimmung_datenschutz'         => 'Datenschutz: Ich willige in die Verarbeitung meiner Stamm-, Erzeugungs- und Verbrauchsdaten gemäß Datenschutzerklärung ein.',
        'zustimmung_agb'                 => 'AGB \\& Tarif-/Preisblatt: Ich bestätige, die geltenden Konditionen laut Preisliste und AGB gelesen und akzeptiert zu haben.',
    ];
    $zustimmungenLines = implode("\n", array_map(
        fn($field, $text) => '\\item[' . $cb($isTrue($a[$field])) . ']  ' . $text,
        array_keys($consentTexts), $consentTexts
    ));

    $assets = ['unterschrift_beitritt.png' => $a['signature_image']] + $sepaAssets;

    streamLatexPdf('beitrittserklaerung_formular', [
        'EEG_NAME'                  => $community['name'],
        'EEG_ZVR'                   => $community['zvr_number'] ?? '--',
        'EEG_ADRESSE'               => $community['address'] ?? '',
        'EINGEREICHT_AM'            => date('d.m.Y H:i', strtotime($a['created_at'])),
        'TITEL'                     => $titelDisplay ?: '--',
        'VORNAME'                   => $a['first_name'],
        'NACHNAME'                  => $a['last_name'],
        'ADRESSE'                   => $a['address'] . ', ' . $a['zip'] . ' ' . $a['city'],
        'TELEFON'                   => $a['phone'] ?: '--',
        'GEBURTSDATUM'              => $a['geburtsdatum'] ? date('d.m.Y', strtotime($a['geburtsdatum'])) : '--',
        'STROMLIEFERANT'            => $a['stromlieferant'] ?: '--',
        'EMAIL'                     => $a['email'],
        'BEZUG_JAHRESVERBRAUCH'     => $a['bezug_jahresverbrauch_kwh'] ? number_format((float)$a['bezug_jahresverbrauch_kwh'], 0, ',', '.') : '--',
        'EINSPEISUNG_KWP'           => $a['einspeisung_kwp'] ? number_format((float)$a['einspeisung_kwp'], 2, ',', '.') : '--',
        'EINSPEISUNG_GEPLANT'       => $a['einspeisung_geplante_kwh'] ? number_format((float)$a['einspeisung_geplante_kwh'], 0, ',', '.') : '--',
        'SPEICHER_KWH'              => $a['speicher_kwh'] ? number_format((float)$a['speicher_kwh'], 1, ',', '.') : '--',
        'ANDERE_EEG_NAME'           => $isTrue($a['andere_eeg']) ? ($a['andere_eeg_name'] ?: '--') : '--',
        'RAW_ANREDE_FRAU'           => $cb($salutation === 'Frau'),
        'RAW_ANREDE_HERR'           => $cb($salutation === 'Herr'),
        'RAW_BEZUG_CB'              => $cb($isTrue($a['bezug_gewuenscht'])),
        'RAW_EINSPEISUNG_CB'        => $cb($isTrue($a['einspeisung_gewuenscht'])),
        'RAW_SPEICHER_JA'           => $cb($speicherStatus === 'ja'),
        'RAW_SPEICHER_NEIN'         => $cb($speicherStatus === 'nein'),
        'RAW_SPEICHER_GEPLANT'      => $cb($speicherStatus === 'geplant'),
        'RAW_ANDERE_EEG_JA'         => $cb($isTrue($a['andere_eeg'])),
        'RAW_ANDERE_EEG_NEIN'       => $cb(!$isTrue($a['andere_eeg'])),
        'RAW_ZP_BEZUG_GRID'         => zpGridTikz($isTrue($a['bezug_gewuenscht']) ? $a['bezug_zaehlpunkt'] : null),
        'RAW_ZP_EINSPEISUNG_GRID'   => zpGridTikz($isTrue($a['einspeisung_gewuenscht']) ? $a['einspeisung_zaehlpunkt'] : null),
        'RAW_SEPA_BLOCK'            => $sepaBlock,
        'RAW_ZUSTIMMUNGEN_LISTE'    => $zustimmungenLines,
        'RAW_UNTERSCHRIFT_BILD'     => '\\includegraphics[height=1.3cm]{unterschrift_beitritt.png}',
        'UNTERSCHRIEBEN_DATUM'      => $a['signed_at'] ? date('d.m.Y', strtotime($a['signed_at'])) : '--',
        'UNTERSCHRIEBEN_AM'         => $a['signed_at'] ? date('d.m.Y H:i', strtotime($a['signed_at'])) : '--',
        'SIGNER_IP'                 => $a['signer_ip'] ?: '--',
    ], 'Beitrittserklaerung_' . $a['last_name'] . '.pdf', $assets);
});

$router->post('/portal/applications/:id/approve', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne(
        "SELECT * FROM membership_applications WHERE id = ? AND community_id = ? AND status = 'pending'",
        [$params['id'], $communityId]
    );
    if (!$application) { http_response_code(404); echo 'Nicht gefunden oder bereits bearbeitet'; return; }

    $result = createMemberRecord($communityId, [
        'salutation' => $application['salutation'], 'titel' => $application['titel'],
        'first_name' => $application['first_name'], 'last_name' => $application['last_name'],
        'email' => $application['email'], 'phone' => $application['phone'],
        'address' => $application['address'], 'zip' => $application['zip'], 'city' => $application['city'],
        'geburtsdatum' => $application['geburtsdatum'], 'stromlieferant' => $application['stromlieferant'],
        'speicher_status' => $application['speicher_status'], 'speicher_kwh' => $application['speicher_kwh'],
        'andere_eeg' => in_array($application['andere_eeg'], [true, 't', '1', 1], true), 'andere_eeg_name' => $application['andere_eeg_name'],
        'member_iban' => $application['iban'], 'member_bic' => $application['bic'],
        'kontoinhaber' => $application['kontoinhaber'], 'konto_adresse' => $application['konto_adresse'],
        'member_since' => date('Y-m-d'),
        'email_anrede_mode' => $_POST['email_anrede_mode'] ?? 'auto',
    ]);

    // Vom Antragsteller angegebene Zählpunkte übernehmen, damit sie nicht händisch
    // nachgetragen werden müssen. Zählernummer (Ausleseeinheit) bleibt bewusst leer --
    // die kennt man erst nach der Vor-Ort-Installation.
    $isTrue = fn($v) => in_array($v, [true, 't', '1', 1], true);
    if ($isTrue($application['bezug_gewuenscht']) && trim($application['bezug_zaehlpunkt'] ?? '') !== '') {
        DB::execute(
            'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, jahresverbrauch_kwh, registered_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_DATE)
             ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
            [$communityId, $result['member_id'], strtoupper(trim($application['bezug_zaehlpunkt'])), 'consumer', $application['bezug_jahresverbrauch_kwh'] ?: null]
        );
    }
    if ($isTrue($application['einspeisung_gewuenscht']) && trim($application['einspeisung_zaehlpunkt'] ?? '') !== '') {
        DB::execute(
            'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE)
             ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
            [$communityId, $result['member_id'], strtoupper(trim($application['einspeisung_zaehlpunkt'])), 'producer', $application['einspeisung_kwp'] ?: null, $application['einspeisung_geplante_kwh'] ?: null]
        );
    }

    DB::execute(
        "UPDATE membership_applications SET status = 'approved', member_id = ?, bearbeitet_von = ?, bearbeitet_am = now() WHERE id = ?",
        [$result['member_id'], Auth::userId(), $application['id']]
    );
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ?
         WHERE community_id = ? AND referenz_typ = 'membership_application' AND referenz_id = ?",
        [Auth::userId(), $communityId, $application['id']]
    );
    logAudit($communityId, 'application.approve', 'member', $result['member_id'],
        'Online-Beitrittserklärung von ' . $application['first_name'] . ' ' . $application['last_name'] . ' freigegeben (KdNr ' . $result['kundennummer'] . ')');

    if ($result['invite_sent']) {
        header('Location: /portal/members/' . $result['member_id'] . '?success=invite_sent');
        exit;
    }

    // Fallback: Einladungs-Mail nicht verschickt (Mailversand nicht konfiguriert/fehlgeschlagen,
    // oder es gab schon einen Login für diese E-Mail) -- Temp-Passwort direkt auf der
    // Mitgliedsseite anzeigen, damit der Manager es notfalls selbst weitergeben kann.
    $memberIdForRedirect = $result['member_id'];
    if ($result['temp_password']) {
        $successTempPw = $result['temp_password'];
        $successEmail = $application['email'];
        $successInviteError = $result['invite_error'];
        $member = DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$memberIdForRedirect, $communityId]);
        $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$memberIdForRedirect]);
        $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$memberIdForRedirect]);
        $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$memberIdForRedirect, $communityId]);
        $latestFirmwareVersion = latestFirmwareVersion();
        require ROOT . '/src/views/pages/member_detail.php';
        exit;
    }

    header('Location: /portal/members/' . $memberIdForRedirect . '?success=1');
    exit;
});

$router->post('/portal/applications/:id/reject', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne(
        "SELECT id, first_name, last_name FROM membership_applications WHERE id = ? AND community_id = ? AND status = 'pending'",
        [$params['id'], $communityId]
    );
    if (!$application) { http_response_code(404); echo 'Nicht gefunden oder bereits bearbeitet'; return; }

    DB::execute(
        "UPDATE membership_applications SET status = 'rejected', ablehnungsgrund = ?, bearbeitet_von = ?, bearbeitet_am = now() WHERE id = ?",
        [trim($_POST['ablehnungsgrund'] ?? '') ?: null, Auth::userId(), $application['id']]
    );
    DB::execute(
        "UPDATE notifications SET status = 'erledigt', erledigt_am = now(), erledigt_von = ?
         WHERE community_id = ? AND referenz_typ = 'membership_application' AND referenz_id = ?",
        [Auth::userId(), $communityId, $application['id']]
    );
    logAudit($communityId, 'application.reject', 'membership_application', $application['id'],
        'Online-Beitrittserklärung von ' . $application['first_name'] . ' ' . $application['last_name'] . ' abgelehnt');

    header('Location: /portal/applications?success=1');
    exit;
});

/**
 * Korrigiert die Zählpunktnummer(n) einer bereits eingereichten Beitrittserklärung -- nötig,
 * weil z.B. eine aus einem Netzbetreiber-Portal mit Leerzeichen kopierte Nummer bei einem
 * älteren, bereits eingereichten Antrag (vor dem Leerzeichen-Fix, siehe validateZaehlpunkt())
 * schon abgeschnitten in membership_applications gespeichert wurde (Patrick, 03.09.2026: "die
 * letzten drei Stellen sind frei"). Das Beitrittserklärung-PDF (/portal/applications/:id/formular)
 * wird bei JEDEM Abruf frisch aus dieser Tabelle gerendert (kein Caching) -- diese Korrektur
 * allein reicht deshalb bereits aus, damit der nächste PDF-Ausdruck die richtigen Daten zeigt,
 * ohne einen gesonderten "neu generieren"-Schritt. Erlaubt für JEDEN Antragsstatus (auch schon
 * freigegeben/abgelehnt), nicht nur 'pending' -- die Korrektur betrifft nur das Dokument selbst.
 */
$router->post('/portal/applications/:id/zaehlpunkt', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$application) { http_response_code(404); echo 'Nicht gefunden'; return; }

    $bezug = normalizeZaehlpunkt($_POST['bezug_zaehlpunkt'] ?? '');
    if ($bezug !== '' && !validateZaehlpunkt($bezug)) {
        header('Location: /portal/applications/' . $params['id'] . '?error=' . urlencode('Zählpunktnummer (Bezug) ungültig -- es werden genau 33 Zeichen (AT + 31 Buchstaben/Ziffern) benötigt.'));
        exit;
    }
    $einspeisung = normalizeZaehlpunkt($_POST['einspeisung_zaehlpunkt'] ?? '');
    if ($einspeisung !== '' && !validateZaehlpunkt($einspeisung)) {
        header('Location: /portal/applications/' . $params['id'] . '?error=' . urlencode('Zählpunktnummer (Einspeisung) ungültig -- es werden genau 33 Zeichen (AT + 31 Buchstaben/Ziffern) benötigt.'));
        exit;
    }

    DB::execute(
        'UPDATE membership_applications SET bezug_zaehlpunkt = ?, einspeisung_zaehlpunkt = ? WHERE id = ? AND community_id = ?',
        [$bezug ?: null, $einspeisung ?: null, $params['id'], $communityId]
    );
    logAudit($communityId, 'application.zaehlpunkt_correct', 'membership_application', $params['id'],
        'Zählpunktnummer(n) einer Beitrittserklärung nachträglich korrigiert.');

    header('Location: /portal/applications/' . $params['id'] . '?success=' . urlencode('Zählpunktnummer(n) gespeichert -- das Beitrittserklärung-PDF zeigt beim nächsten Ausdruck die korrigierten Daten.'));
    exit;
});

// ─── Portal: EDA-Import ─────────────────────────────────
// Import-Historie dieser Community: für die Übersicht auf /portal/eda/upload (welche Dateien
// wurden für welchen Zeitraum importiert) UND für den Lösch-Button dort (siehe
// /portal/eda/imports/:id/delete weiter unten).
function edaImportsForCommunity(string $communityId): array
{
    DB::setCommunity($communityId);
    // quality_l1/l2/l3: Datenqualität direkt aus den importierten Messwerten ausgelesen (Patrick,
    // 07.08.2026: "Bitte die EDA-Datenqualität aus der Datei auslesen und auch anzeigen") --
    // period_from/period_to sind hier beides TIMESTAMPTZ-Spalten (eda_imports), ein direkter
    // >=/<=-Vergleich gegen em.time (ebenfalls TIMESTAMPTZ) ist deshalb zeitzonenunabhängig
    // korrekt, ganz ohne ::date-Umwandlung (siehe die Zeitzonen-Fallgrube bei
    // Billing::missingMonths(), wo genau das nötig war -- dort waren die Vergleichswerte
    // hingegen reine DATE-Strings aus billing_runs).
    return DB::fetchAll(
        "SELECT ei.*, u.first_name, u.last_name,
                (SELECT COUNT(*) FROM eda_measurements em WHERE em.community_id = ei.community_id
                   AND em.time >= ei.period_from AND em.time <= ei.period_to AND em.quality = 'L1') AS quality_l1,
                (SELECT COUNT(*) FROM eda_measurements em WHERE em.community_id = ei.community_id
                   AND em.time >= ei.period_from AND em.time <= ei.period_to AND em.quality = 'L2') AS quality_l2,
                (SELECT COUNT(*) FROM eda_measurements em WHERE em.community_id = ei.community_id
                   AND em.time >= ei.period_from AND em.time <= ei.period_to AND em.quality = 'L3') AS quality_l3
         FROM eda_imports ei
         LEFT JOIN users u ON u.id = ei.imported_by
         WHERE ei.community_id = ?
         ORDER BY ei.imported_at DESC",
        [$communityId]
    );
}

/** Import-Historie der Viertelstundenwerte (zweiter Export-Typ, siehe
 *  eda-parser/parser_interval.py) -- eigene, schlankere Tabelle als edaImportsForCommunity()
 *  oben, da hier bewusst überlappende Zeiträume normal sind (siehe Kommentar in
 *  database/migrate_20260904.sql), keine Datenqualitäts-Aggregation nötig (nicht
 *  abrechnungsrelevant). */
function edaIntervalImportsForCommunity(string $communityId): array
{
    DB::setCommunity($communityId);
    return DB::fetchAll(
        "SELECT ei.*, u.first_name, u.last_name
         FROM eda_interval_imports ei
         LEFT JOIN users u ON u.id = ei.imported_by
         WHERE ei.community_id = ?
         ORDER BY ei.imported_at DESC",
        [$communityId]
    );
}

/**
 * Lücken-Anzeige für die Viertelstundenwerte: letzter vorhandener Zeitstempel community-weit
 * (über alle Zählpunkte) plus wie viele Tage bis heute fehlen -- Patrick, 03.09.2026: "ab
 * welchem Datum es noch keine Werte gibt, ab welchem Datum gehe ich die Daten exportieren
 * muss", "wenn ich jetzt den juli importier und dann von 01.08. bis 21.08. ich sehe, dass ab
 * 22.08. die Daten fehlen". Bewusst eine einfache MAX(time)-Abfrage statt eine eigene
 * Fortschritts-Tabelle zu pflegen -- immer exakt aktuell, unabhängig vom Import-Protokoll.
 */
function edaIntervalGap(string $communityId): array
{
    DB::setCommunity($communityId);
    $row = DB::fetchOne('SELECT MAX(time) AS letzter FROM eda_interval_data WHERE community_id = ?', [$communityId]);
    $letzter = $row['letzter'] ?? null;
    $fehlendeTage = $letzter ? (int)floor((time() - strtotime($letzter)) / 86400) : null;
    return ['letzter' => $letzter, 'fehlende_tage' => $fehlendeTage];
}

$router->get('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    $imports = edaImportsForCommunity($communityId);
    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);
    require ROOT . '/src/views/pages/eda_upload.php';
});

$router->post('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    $imports = edaImportsForCommunity($communityId);
    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);

    if (!isset($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload fehlgeschlagen (Fehlercode: ' . ($_FILES['xlsx']['error'] ?? '?') . ')';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $origName = basename($_FILES['xlsx']['name']);
    if (!str_ends_with(strtolower($origName), '.xlsx')) {
        $error = 'Nur XLSX-Dateien erlaubt.';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $savePath = '/var/www/html/storage/uploads/' . uniqid() . '_' . $origName;
    move_uploaded_file($_FILES['xlsx']['tmp_name'], $savePath);

    $communitySlug = Auth::activeCommunitySlug();
    $userId = Auth::userId();

    $parserResult = EdaParserRunner::run($savePath, $communitySlug, $userId);
    $result = json_decode($parserResult['stdout'], true);
    if ($result === null) {
        // stdout/stderr sauber getrennt (siehe EdaParserRunner.php) -- vorher landeten Logzeilen
        // (stderr) und das JSON-Ergebnis (stdout) über ein simples "2>&1" in einem String, wodurch
        // json_decode() auf dem kombinierten String IMMER fehlschlug, sobald der Parser
        // mindestens eine Logzeile ausgegeben hatte (bei jedem Lauf der Fall) -- ein erfolgreicher
        // Import wurde dadurch fälschlich als "Parser-Fehler" angezeigt.
        $diag = EdaParserRunner::diagnostics($parserResult);
        $error = 'Parser-Fehler: ' . htmlspecialchars(substr($diag, 0, 4000));
        logAudit($communityId, 'eda.import', null, null, 'EDA-Import fehlgeschlagen: ' . substr($diag, 0, 4000), true);
    } else {
        logAudit($communityId, 'eda.import', null, null,
            'EDA-Import: ' . ($result['records'] ?? '?') . ' Datensätze importiert' . (!empty($result['warnings']) ? ', ' . count($result['warnings']) . ' Warnung(en)' : ''));
        // Je automatisch angelegtem Zählpunkt einen eigenen, gezielt auffindbaren Audit-Log-
        // Eintrag -- wer/wann nachvollziehbar pro Zählpunkt statt nur in der Sammel-Zeile oben.
        foreach ($result['neu_angelegt'] ?? [] as $neu) {
            logAudit($communityId, 'eda.metering_point.autocreate', 'metering_point', $neu['metering_point_id'] ?? null,
                'Zählpunkt ' . $neu['zaehlpunkt_nr'] . ' automatisch aus EDA-Import angelegt (Typ-Vermutung: '
                . $neu['type_guess'] . '), noch keinem Mitglied zugeordnet.');
        }
    }

    // Neu laden, damit der gerade eben erzeugte (oder bei einem Duplikat-Fehler eben NICHT
    // erzeugte) Import sofort in der Liste unten auftaucht, statt erst nach einem Reload.
    $imports = edaImportsForCommunity($communityId);

    require ROOT . '/src/views/pages/eda_upload.php';
});

/** Wie POST /portal/eda/upload, aber für den zweiten Export-Typ (Viertelstundenwerte,
 *  "Energiedaten"-Sheet, siehe eda-parser/parser_interval.py) -- eigene Route, eigenes
 *  Datei-Feld (xlsx_interval), damit beide Upload-Formulare unabhängig auf derselben Seite
 *  stehen können. */
$router->post('/portal/eda/upload-interval', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    $imports = edaImportsForCommunity($communityId);
    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);

    if (!isset($_FILES['xlsx_interval']) || $_FILES['xlsx_interval']['error'] !== UPLOAD_ERR_OK) {
        $intervalError = 'Upload fehlgeschlagen (Fehlercode: ' . ($_FILES['xlsx_interval']['error'] ?? '?') . ')';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }
    $origName = basename($_FILES['xlsx_interval']['name']);
    if (!str_ends_with(strtolower($origName), '.xlsx')) {
        $intervalError = 'Nur XLSX-Dateien erlaubt.';
        require ROOT . '/src/views/pages/eda_upload.php';
        return;
    }

    $savePath = '/var/www/html/storage/uploads/' . uniqid() . '_' . $origName;
    move_uploaded_file($_FILES['xlsx_interval']['tmp_name'], $savePath);

    $parserResult = EdaParserRunner::runInterval($savePath, Auth::activeCommunitySlug(), Auth::userId());
    $intervalResult = json_decode($parserResult['stdout'], true);
    if ($intervalResult === null) {
        $diag = EdaParserRunner::diagnostics($parserResult);
        $intervalError = 'Parser-Fehler: ' . htmlspecialchars(substr($diag, 0, 4000));
        logAudit($communityId, 'eda.interval_import', null, null, 'Viertelstundenwerte-Import fehlgeschlagen: ' . substr($diag, 0, 4000), true);
    } else {
        logAudit($communityId, 'eda.interval_import', null, null,
            'Viertelstundenwerte-Import: ' . ($intervalResult['records'] ?? '?') . ' Datensätze importiert'
            . (!empty($intervalResult['warnings']) ? ', ' . count($intervalResult['warnings']) . ' Warnung(en)' : ''));
        foreach ($intervalResult['neu_angelegt'] ?? [] as $neu) {
            logAudit($communityId, 'eda.metering_point.autocreate', 'metering_point', $neu['metering_point_id'] ?? null,
                'Zählpunkt ' . $neu['zaehlpunkt_nr'] . ' automatisch aus Viertelstundenwerte-Import angelegt (Typ-Vermutung: '
                . $neu['type_guess'] . '), noch keinem Mitglied zugeordnet.');
        }
    }

    $intervalImports = edaIntervalImportsForCommunity($communityId);
    $intervalGap = edaIntervalGap($communityId);

    require ROOT . '/src/views/pages/eda_upload.php';
});

/**
 * Löscht einen EDA-Import wieder -- den Log-Eintrag UND die dabei importierten Messwerte
 * (eda_measurements), damit dieselbe Datei anschließend erneut hochgeladen werden kann (der
 * Parser verweigert sonst mit "Duplikat", siehe import_to_db() in eda-parser/parser.py).
 * eda_measurements hat KEINEN Verweis auf den einzelnen Import zurück (nur time/community_id/
 * metering_point_id) -- gelöscht wird deshalb alles im exakten Zeitraum dieses Imports für diese
 * Community, genau wie die Duplikat-Prüfung selbst prüft. Träfe für exakt denselben Zeitraum
 * zufällig noch ein ZWEITER Import vor (in der Praxis nicht vorgesehen -- EDA liefert eine Datei
 * pro Monat), würden dessen Messwerte mitgelöscht; die Metering-Points selbst (inkl. automatisch
 * angelegter, siehe neu_angelegt) bleiben bewusst unangetastet, nur die Energiedaten verschwinden.
 * Rührt NICHT an bereits berechneten Abrechnungslauf-Entwürfen -- deren invoice_items sind
 * statische Kopien, die durch das Löschen der Rohdaten nicht rückwirkend aktualisiert werden.
 */
$router->post('/portal/eda/imports/:id/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $imp = DB::fetchOne('SELECT * FROM eda_imports WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$imp) { http_response_code(404); return; }

    $deletedMeasurements = DB::execute(
        "DELETE FROM eda_measurements WHERE community_id = ? AND time >= ? AND time < ?::date + INTERVAL '1 day'",
        [$communityId, $imp['period_from'], $imp['period_to']]
    );
    DB::execute('DELETE FROM eda_imports WHERE id = ?', [$imp['id']]);

    logAudit($communityId, 'eda.import.delete', 'eda_import', $imp['id'],
        'EDA-Import "' . $imp['filename'] . '" (' . date('d.m.Y', strtotime($imp['period_from'])) . ' – '
        . date('d.m.Y', strtotime($imp['period_to'])) . ') gelöscht, ' . $deletedMeasurements . ' Messwert-Datensätze entfernt.');

    header('Location: /portal/eda/upload?deleted=1');
    exit;
});

/**
 * Löscht nur den Protokoll-Eintrag eines Viertelstundenwerte-Imports, NICHT die dabei
 * importierten Messwerte selbst -- anders als beim Monatsimport oben ist das hier sicher genug:
 * Zeiträume überlappen sich bei diesem Import-Typ bewusst normal (Patrick lädt alle paar Tage
 * einen neuen, teils überlappenden Ausschnitt hoch), ein exaktes Zurückrechnen "welche Zeilen
 * gehören zu genau diesem Log-Eintrag" wäre bei Überlappungen nicht mehr eindeutig. Falsche/
 * veraltete Werte werden stattdessen einfach durch einen erneuten Upload desselben Zeitraums
 * überschrieben (siehe import_to_db() in eda-parser/parser_interval.py) -- diese Route räumt
 * nur die Historien-Anzeige auf.
 */
$router->post('/portal/eda/interval-imports/:id/delete', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $imp = DB::fetchOne('SELECT * FROM eda_interval_imports WHERE id = ? AND community_id = ?', [$params['id'], $communityId]);
    if (!$imp) { http_response_code(404); return; }

    DB::execute('DELETE FROM eda_interval_imports WHERE id = ?', [$imp['id']]);
    logAudit($communityId, 'eda.interval_import.delete', 'eda_interval_import', $imp['id'],
        'Viertelstundenwerte-Import-Protokolleintrag "' . $imp['filename'] . '" gelöscht (Messwerte selbst bleiben erhalten).');

    header('Location: /portal/eda/upload?deleted=1');
    exit;
});

/**
 * Zählpunkte, die per automatischem EDA-Import-Abgleich angelegt wurden (siehe
 * eda-parser/parser.py, docs/ESP_IDEEN.md Punkt 3), aber noch keinem Mitglied zugeordnet sind
 * -- der Obmann weist sie hier einem bestehenden Mitglied zu, korrigiert bei Bedarf den Typ und
 * aktiviert den Zählpunkt (erst dann nimmt er an einer Abrechnung teil).
 */
$router->get('/portal/metering-points/unassigned', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $unassigned = DB::fetchAll(
        'SELECT * FROM metering_points WHERE community_id = ? AND member_id IS NULL ORDER BY registered_at DESC',
        [$communityId]
    );
    $members = DB::fetchAll(
        "SELECT id, first_name, last_name, kundennummer FROM members WHERE community_id = ? AND status = 'active' ORDER BY last_name, first_name",
        [$communityId]
    );
    require ROOT . '/src/views/pages/metering_points_unassigned.php';
});

$router->post('/portal/metering-points/:id/assign', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $mp = DB::fetchOne('SELECT * FROM metering_points WHERE id = ? AND community_id = ? AND member_id IS NULL', [$params['id'], $communityId]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden oder bereits zugeordnet.'; return; }

    $memberId = $_POST['member_id'] ?? '';
    $member = DB::fetchOne('SELECT id FROM members WHERE id = ? AND community_id = ?', [$memberId, $communityId]);
    if (!$member) {
        header('Location: /portal/metering-points/unassigned?error=' . urlencode('Bitte ein gültiges Mitglied auswählen.'));
        exit;
    }
    $type = in_array($_POST['type'] ?? '', ['consumer', 'producer', 'prosumer'], true) ? $_POST['type'] : $mp['type'];

    DB::execute(
        'UPDATE metering_points SET member_id = ?, type = ?, active = true WHERE id = ? AND community_id = ?',
        [$memberId, $type, $params['id'], $communityId]
    );
    logAudit($communityId, 'metering_point.assign', 'metering_point', $params['id'],
        'Zählpunkt ' . $mp['zaehlpunkt_nr'] . ' (aus EDA-Import) einem Mitglied zugeordnet und aktiviert (Typ: ' . $type . ').');
    header('Location: /portal/metering-points/unassigned?success=1');
    exit;
});

// ─── Portal: Einstellungen ──────────────────────────────
$router->get('/portal/settings', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    $tariff    = DB::fetchOne('SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $tax       = DB::fetchOne('SELECT * FROM tax_config WHERE community_id = ? ORDER BY valid_from DESC LIMIT 1', [$communityId]);
    $myUser    = DB::fetchOne('SELECT first_name, last_name, signature_image FROM users WHERE id = ?', [Auth::userId()]);
    $hasCustomLogo = communityLogoPath($communityId) !== null;
    // Community-weite Smart-Home-API-Keys (member_id NULL, seit migrate_20260901.sql) -- nicht
    // personengebunden wie ein Mitglied-Key unter /portal/my/api-keys, sondern eine
    // EEG-Ressource, die jeder Obmann/Platform-Admin dieser Community sehen/anlegen/widerrufen
    // kann (gleiches Prinzip wie die MQTT-Zugangsdaten weiter unten).
    $liveApiKeys = DB::fetchAll(
        'SELECT * FROM member_api_keys WHERE community_id = ? AND member_id IS NULL ORDER BY created_at DESC',
        [$communityId]
    );
    $newLiveApiKey = $_SESSION['flash_new_live_api_key'] ?? null;
    unset($_SESSION['flash_new_live_api_key']);
    require ROOT . '/src/views/pages/settings.php';
});

/** Neuen Community-weiten Live-Daten-API-Key anlegen (Obmann/Platform-Admin) -- Gegenstück zu
 *  POST /portal/my/api-keys, nur ohne member_id. */
$router->post('/portal/settings/live-api-keys', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        header('Location: /portal/settings?error=' . urlencode('Bitte einen Namen für den API-Key vergeben.'));
        exit;
    }
    $validityDays = ['30' => 30, '90' => 90, '365' => 365][$_POST['validity'] ?? ''] ?? null;
    $expiresAt = $validityDays ? date('Y-m-d H:i:s', strtotime("+{$validityDays} days")) : null;

    $token = bin2hex(random_bytes(32));
    $newKey = DB::fetchOne(
        'INSERT INTO member_api_keys (community_id, member_id, name, key_prefix, key_hash, expires_at)
         VALUES (?, NULL, ?, ?, ?, ?) RETURNING id',
        [$communityId, $name, substr($token, 0, 8), hash('sha256', $token), $expiresAt]
    );
    logAudit($communityId, 'live_api_key.create', 'member_api_key', $newKey['id'], 'Community-weiter Live-Daten-API-Key „' . $name . '" angelegt.');
    $_SESSION['flash_new_live_api_key'] = $token;
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/live-api-keys/:id/revoke', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        'UPDATE member_api_keys SET revoked_at = now() WHERE id = ? AND community_id = ? AND member_id IS NULL AND revoked_at IS NULL',
        [$params['id'], $communityId]
    );
    logAudit($communityId, 'live_api_key.revoke', 'member_api_key', $params['id'], 'Community-weiter Live-Daten-API-Key widerrufen.');
    header('Location: /portal/settings?success=' . urlencode('API-Key wurde widerrufen.'));
    exit;
});

/**
 * Eigenes Logo der EEG für Rechnungen/Verträge (anders als das plattformweite Website-Logo
 * unter /admin/templates) -- landet im geteilten Volume unter community-logos/{id}.png, siehe
 * communityLogoPath()/communityLogoAsset().
 */
$router->post('/portal/settings/logo', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /portal/settings?error=' . urlencode('Logo-Upload fehlgeschlagen.'));
        exit;
    }
    if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
        header('Location: /portal/settings?error=' . urlencode('Datei zu groß (max. 5 MB).'));
        exit;
    }
    if (@getimagesize($_FILES['logo']['tmp_name']) === false) {
        header('Location: /portal/settings?error=' . urlencode('Datei ist kein gültiges Bild.'));
        exit;
    }
    @mkdir('/var/www/html/latex-templates/community-logos', 0775, true);
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], '/var/www/html/latex-templates/community-logos/' . $communityId . '.png')) {
        header('Location: /portal/settings?error=' . urlencode('Datei konnte nicht gespeichert werden.'));
        exit;
    }
    logAudit($communityId, 'community.logo.upload', 'community', $communityId, 'EEG-Logo für Rechnungen/Verträge aktualisiert');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->get('/portal/settings/logo/preview', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $path = communityLogoPath(Auth::activeCommunityId());
    if (!$path) { http_response_code(404); return; }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    readfile($path);
});

$router->post('/portal/settings/logo/delete', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    @unlink('/var/www/html/latex-templates/community-logos/' . $communityId . '.png');
    logAudit($communityId, 'community.logo.delete', 'community', $communityId, 'EEG-Logo entfernt (Standard-Logo wirkt wieder)');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/signature', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        header('Location: /portal/settings?error=upload');
        exit;
    }
    DB::execute('UPDATE users SET signature_image = ? WHERE id = ?', [$signature, Auth::userId()]);
    logAudit(Auth::activeCommunityId(), 'settings.signature', 'user', Auth::userId(), 'Manager-Unterschrift aktualisiert');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/signature/delete', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    DB::execute('UPDATE users SET signature_image = NULL WHERE id = ?', [Auth::userId()]);
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/community', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $auditBefore = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    DB::execute(
        'UPDATE communities SET name=?, address=?, iban=?, bic=?, zvr_number=?, marktpartner_id=?, dashboard_url=?,
                                 aufteilungsschluessel_info=?,
                                 bank_name=?, account_holder=?, contact_phone=?, contact_email=?, creditor_id=?,
                                 sepa_pain_version=?, sepa_prenotification_days=?, mahngebuehr_eur=?, contracts_enabled=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['dashboard_url'] ?? '') ?: null,
            trim($_POST['aufteilungsschluessel_info'] ?? '') ?: null,
            trim($_POST['bank_name'] ?? '') ?: null,
            trim($_POST['account_holder'] ?? '') ?: null,
            trim($_POST['contact_phone'] ?? '') ?: null,
            trim($_POST['contact_email'] ?? '') ?: null,
            trim($_POST['creditor_id'] ?? '') ?: null,
            in_array($_POST['sepa_pain_version'] ?? '08', ['02', '08'], true) ? $_POST['sepa_pain_version'] : '08',
            max(1, min(60, (int)($_POST['sepa_prenotification_days'] ?? 14))),
            max(0, (float)str_replace(',', '.', $_POST['mahngebuehr_eur'] ?? '0')),
            // Als 'true'/'false' binden, nicht als PHP-bool: PDO (pgsql, emulate_prepares=off)
            // schickt PHP-false als leeren String '', den eine boolean-Spalte ablehnt (22P02).
            !empty($_POST['contracts_enabled']) ? 'true' : 'false',
            $communityId,
        ]
    );
    $auditAfter = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$communityId]);
    logAuditDiff($communityId, 'community.update', 'community', $communityId,
        auditDiff($auditBefore ?? [], $auditAfter ?? [], [
            'name' => 'Name', 'address' => 'Adresse', 'iban' => 'IBAN', 'bic' => 'BIC',
            'zvr_number' => 'ZVR', 'marktpartner_id' => 'Marktpartner-ID', 'dashboard_url' => 'Dashboard-URL',
            'aufteilungsschluessel_info' => 'Aufteilungsschlüssel (Info)',
            'bank_name' => 'Bankname', 'account_holder' => 'Kontoinhaber', 'contact_phone' => 'Telefon',
            'contact_email' => 'Kontakt-E-Mail', 'creditor_id' => 'Gläubiger-ID',
            'sepa_pain_version' => 'SEPA-Format', 'sepa_prenotification_days' => 'SEPA-Vorlauftage',
            'mahngebuehr_eur' => 'Mahngebühr', 'contracts_enabled' => 'Verträge aktiv',
        ]),
        'EEG-Stammdaten:');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/tariff', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    DB::execute(
        'INSERT INTO tariff_config (community_id, valid_from, bezug_ct_kwh, einspeisung_ct_kwh, mitgliedsbeitrag_eur)
         VALUES (?, ?, ?, ?, ?)',
        [
            $communityId,
            $_POST['valid_from'] ?? date('Y-m-d'),
            (float)str_replace(',', '.', $_POST['bezug_ct_kwh'] ?? '0'),
            (float)str_replace(',', '.', $_POST['einspeisung_ct_kwh'] ?? '0'),
            (float)str_replace(',', '.', $_POST['mitgliedsbeitrag_eur'] ?? '0'),
        ]
    );
    logAudit($communityId, 'tariff.update', 'community', $communityId,
        'Neuer Tarif gültig ab ' . ($_POST['valid_from'] ?? date('Y-m-d')) . ': Bezug '
        . str_replace(',', '.', $_POST['bezug_ct_kwh'] ?? '0') . ' ct/kWh, Einspeisung '
        . str_replace(',', '.', $_POST['einspeisung_ct_kwh'] ?? '0') . ' ct/kWh, Mitgliedsbeitrag '
        . str_replace(',', '.', $_POST['mitgliedsbeitrag_eur'] ?? '0') . ' €/Jahr');
    header('Location: /portal/settings?success=1');
    exit;
});

$router->post('/portal/settings/tax', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);

    $taxModel = $_POST['tax_model'] ?? '';
    if (!in_array($taxModel, ['kleinunternehmer', 'standard'], true)) {
        http_response_code(400);
        echo 'Ungültiges Steuermodell.';
        return;
    }
    $taxRate = $taxModel === 'standard'
        ? (float)str_replace(',', '.', $_POST['tax_rate_percent'] ?? '20')
        : null;

    DB::execute(
        'INSERT INTO tax_config (community_id, valid_from, tax_model, tax_rate_percent, uid_number)
         VALUES (?, ?, ?, ?, ?)',
        [
            $communityId,
            $_POST['valid_from'] ?? date('Y-m-d'),
            $taxModel,
            $taxRate,
            trim($_POST['uid_number'] ?? '') ?: null,
        ]
    );
    logAudit($communityId, 'tax.update', 'community', $communityId,
        'Steuermodell "' . $taxModel . '"' . ($taxRate !== null ? ' (' . $taxRate . ' %)' : '')
        . ' gültig ab ' . ($_POST['valid_from'] ?? date('Y-m-d')));
    header('Location: /portal/settings?success=1');
    exit;
});

// ─── Admin-Bereich ──────────────────────────────────────
$router->get('/admin', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $communities = DB::fetchAll('SELECT * FROM communities ORDER BY name');
    $userCount   = DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];
    $rawUsers    = DB::fetchAll('SELECT id, email, first_name, last_name, active, is_demo FROM users ORDER BY last_name, first_name');
    $allRoles    = DB::fetchAll('SELECT ur.user_id, ur.role, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id');
    $roleMap = [];
    foreach ($allRoles as $r) { $roleMap[$r['user_id']][] = $r; }
    // Demo-Login: echte Login-Accounts hier NIE unmaskiert zeigen (siehe demoMaskUser()).
    $rawUsers = demoMaskUsers($rawUsers, Auth::isDemo());
    $users = array_map(fn($u) => array_merge($u, ['roles' => $roleMap[$u['id']] ?? []]), $rawUsers);
    require ROOT . '/src/views/pages/admin.php';
});

$router->post('/admin/communities', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $name = trim($_POST['name'] ?? '');
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    DB::execute(
        'INSERT INTO communities (name, slug, marktpartner_id, address) VALUES (?, ?, ?, ?)',
        [$name, $slug, $_POST['marktpartner_id'] ?? null, $_POST['address'] ?? null]
    );
    header('Location: /admin');
    exit;
});

$router->get('/admin/communities/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); return; }
    // Entschlüsselt fürs Formular: der Platform-Admin muss das EDA-Portal-Passwort tatsächlich
    // ablesen können (z.B. um sich selbst einzuloggen), anders als z.B. das Graph-Client-Secret,
    // das nur die App selbst braucht -- siehe encryptSecret()/decryptSecret() in functions.php.
    $community['eda_login_password'] = decryptSecret($community['eda_login_password_enc'] ?? null);
    // Demo-Login: das entschlüsselte EDA-Portal-Passwort ist ein ECHTES Zugangsdatum zum
    // Netzbetreiber-Portal -- niemals im Klartext zeigen (Patrick, 06.09.2026).
    if (Auth::isDemo()) {
        if (!empty($community['eda_login_password'])) { $community['eda_login_password'] = demoMaskFull((string)$community['eda_login_password']); }
        if (!empty($community['eda_login_email'])) { $community['eda_login_email'] = demoMaskFull((string)$community['eda_login_email']); }
    }
    // members hat Row-Level Security (migrate_20260822.sql) -- OHNE DB::setCommunity() liefert
    // die eingeschränkte Laufzeit-Rolle grundsätzlich GAR KEINE Zeile, auch bei korrektem WHERE
    // (Patrick, 19.08.2026 beim App-API-Nachbau entdeckt: diese Seite zeigte seit dem RLS-Fix
    // leer, obwohl Mitglieder vorhanden sind). Anders als z.B. bei requireMemberAccess() gibt es
    // hier KEIN Henne-Ei-Problem -- die Ziel-Community ist ja schon aus der URL bekannt
    // (:id), also einfach direkt setzen, kein "jede Community durchprobieren" nötig. Der
    // Platform-Admin sieht dadurch weiterhin die Mitglieder JEDER EEG (nicht nur der eigenen
    // aktiven Rolle) -- der ursprüngliche Kommentar hier meinte richtig "keine Bindung an die
    // eigene aktive Rolle", nicht "gar keine Community setzen".
    DB::setCommunity($params['id']);
    $members = DB::fetchAll(
        'SELECT m.id, m.kundennummer, m.first_name, m.last_name, m.company_name, m.email, m.status,
                m.user_id, m.is_demo, u.email AS login_email
         FROM members m LEFT JOIN users u ON u.id = m.user_id
         WHERE m.community_id = ?
         ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name',
        [$params['id']]
    );
    // Demo-Login: echte Mitgliederdaten hier NIE unmaskiert zeigen (siehe /portal/members).
    // login_email (echter Login-Account, falls vorhanden) separat maskieren -- demoMaskMember()
    // kennt nur die members-eigenen Spalten.
    $isDemo = Auth::isDemo();
    $members = demoMaskMembers($members, $isDemo);
    if ($isDemo) {
        foreach ($members as &$m) {
            if (empty($m['is_demo']) && !empty($m['login_email'])) { $m['login_email'] = demoMaskFull((string)$m['login_email']); }
        }
        unset($m);
    }
    require ROOT . '/src/views/pages/admin_community.php';
});

$router->get('/admin/users/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $user        = DB::fetchOne('SELECT id, email, first_name, last_name, active, is_demo FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }
    $roles       = DB::fetchAll('SELECT ur.*, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id WHERE ur.user_id = ?', [$params['id']]);
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    // members hat RLS -- je Rolle einzeln mit korrekt gesetzter Community nachladen (gleiches
    // Muster wie Auth::attachMemberNames()), damit bei role='member' mit gesetzter member_id
    // (Demo-Logins mit mehreren Mitglied-Identitäten, siehe migrate_20260905.sql) der Name der
    // jeweiligen Identität in der Rollen-Tabelle angezeigt werden kann. Ebenso werden ALLE
    // Mitglieder jeder EEG geladen fürs Auswahlfeld beim Hinzufügen einer 'member'-Rolle.
    $isDemo = Auth::isDemo();
    $membersByCommunity = [];
    foreach ($communities as $c) {
        DB::setCommunity($c['id']);
        $membersByCommunity[$c['id']] = demoMaskMembers(DB::fetchAll(
            "SELECT id, first_name, last_name, is_demo FROM members WHERE community_id = ? ORDER BY last_name, first_name",
            [$c['id']]
        ), $isDemo);
    }
    $user = demoMaskUser($user, $isDemo);
    foreach ($roles as &$r) {
        if (empty($r['member_id']) || empty($r['community_id'])) continue;
        foreach ($membersByCommunity[$r['community_id']] ?? [] as $m) {
            if ($m['id'] === $r['member_id']) {
                $r['member_name'] = trim($m['first_name'] . ' ' . $m['last_name']);
                break;
            }
        }
    }
    unset($r);
    require ROOT . '/src/views/pages/admin_user.php';
});

$router->post('/admin/users/:id/roles', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $communityId = $_POST['community_id'] ?? null;
    $role = $_POST['role'] ?? '';
    if (!in_array($role, ['platform_admin', 'manager', 'member'])) { http_response_code(400); return; }
    // member_id nur bei role='member' relevant -- disambiguiert mehrere Mitglied-Identitäten
    // desselben Logins in derselben Community (Demo-Logins, siehe migrate_20260905.sql). Für den
    // normalen Fall (ein Mitglied hat genau einen members-Datensatz je Community) leer lassen --
    // currentMemberFull() fällt dann weiterhin auf die alte user_id-Suche zurück.
    $memberId = ($role === 'member' && !empty($_POST['member_id'])) ? (string)$_POST['member_id'] : null;
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role, member_id) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $params['id'], $role, $memberId]
    );
    if ($params['id'] === Auth::userId()) { Auth::refreshRoles(); }
    header('Location: /admin/users/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/admin/users/:id/roles/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    // Es muss immer mindestens eine platform_admin-Rolle übrig bleiben, sonst kann sich
    // niemand mehr ins Admin-Backoffice einloggen.
    $isLastPlatformAdminRole = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$_POST['role_id']]
    );
    if ($isLastPlatformAdminRole) {
        http_response_code(400);
        echo 'Dies ist die letzte verbleibende Plattform-Admin-Rolle und kann nicht entfernt werden.';
        return;
    }

    DB::execute('DELETE FROM user_roles WHERE id = ?', [$_POST['role_id']]);
    if ($params['id'] === Auth::userId()) { Auth::refreshRoles(); }
    header('Location: /admin/users/' . $params['id'] . '?success=1');
    exit;
});

$router->post('/admin/users/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    if ($params['id'] === Auth::userId()) { http_response_code(400); echo 'Der eigene Account kann nicht gelöscht werden.'; return; }
    $user = DB::fetchOne('SELECT id FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }

    // Es muss immer mindestens ein platform_admin übrig bleiben, sonst kann sich niemand mehr
    // ins Admin-Backoffice einloggen -- keine hartkodierte E-Mail, sondern generisch "letzter
    // verbleibender platform_admin darf nicht gelöscht werden".
    $isLastPlatformAdmin = (bool)DB::fetchOne(
        "SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'platform_admin'
         AND (SELECT COUNT(*) FROM user_roles WHERE role = 'platform_admin') = 1",
        [$params['id']]
    );
    if ($isLastPlatformAdmin) {
        http_response_code(400);
        echo 'Dieser Account ist der letzte verbleibende Plattform-Admin und kann nicht gelöscht werden.';
        return;
    }

    // Löscht kaskadierend Rollenzuweisungen (user_roles); verknüpfte Mitglieder bleiben erhalten,
    // verlieren nur die Login-Verknüpfung (siehe migrate_20260715.sql).
    DB::execute('DELETE FROM users WHERE id = ?', [$params['id']]);
    header('Location: /admin?success=1');
    exit;
});

$router->post('/admin/communities/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    // EDA-Portal-Passwort nur überschreiben, wenn tatsächlich ein neues eingegeben wurde --
    // das Feld wird beim Laden nie im Klartext vorbefüllt (siehe admin_community.php), ein
    // leeres Absenden darf das gespeicherte Passwort also nicht versehentlich löschen.
    $current = DB::fetchOne('SELECT eda_login_password_enc FROM communities WHERE id = ?', [$params['id']]);
    $newEdaPassword = trim($_POST['eda_login_password'] ?? '');
    $edaPasswordEnc = $newEdaPassword !== '' ? encryptSecret($newEdaPassword) : ($current['eda_login_password_enc'] ?? null);

    DB::execute(
        'UPDATE communities SET name=?, marktpartner_id=?, zvr_number=?, address=?, iban=?, bic=?, active=?,
             eda_login_email=?, eda_login_password_enc=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            isset($_POST['active']) ? 'true' : 'false',
            trim($_POST['eda_login_email'] ?? '') ?: null,
            $edaPasswordEnc,
            $params['id'],
        ]
    );
    logAudit($params['id'], 'community.update', 'community', $params['id'], 'EEG "' . trim($_POST['name'] ?? '') . '" bearbeitet');
    header('Location: /admin?success=1');
    exit;
});

$router->post('/admin/communities/:id/delete', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $community = DB::fetchOne('SELECT id, name FROM communities WHERE id = ?', [$params['id']]);
    if (!$community) { http_response_code(404); return; }

    // Kaskadiert über ON DELETE CASCADE auf ALLE community-gebundenen Daten (Mitglieder,
    // Zählpunkte, Verträge, Rechnungen, Rollenzuweisungen, ...) -- siehe init.sql/migrate_*.sql,
    // dort hat jede Referenz auf communities(id) ON DELETE CASCADE. Login-Accounts (users)
    // bleiben bestehen, verlieren nur ihre Rolle(n) in dieser EEG.
    // Audit-Log bewusst mit community_id=NULL, sonst würde der Eintrag durch dieselbe Kaskade
    // sofort wieder mitgelöscht.
    DB::execute('DELETE FROM communities WHERE id = ?', [$params['id']]);
    logAudit(null, 'community.delete', 'community', $params['id'],
        'EEG "' . $community['name'] . '" (ID ' . $community['id'] . ') endgültig gelöscht inkl. aller Mitglieder, Verträge, Zählpunkte und Rechnungen');
    header('Location: /admin?success=1');
    exit;
});

// ─── Admin: Aktivitätslog ────────────────────────────────
$router->get('/admin/log', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $filterCommunity = $_GET['community_id'] ?? '';
    $params = [];
    $where = '1=1';
    if ($filterCommunity !== '') {
        $where .= ' AND al.community_id = ?';
        $params[] = $filterCommunity;
    }
    $entries = DB::fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, c.name AS community_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN communities c ON c.id = al.community_id
         WHERE $where
         ORDER BY al.created_at DESC LIMIT 500",
        $params
    );
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    require ROOT . '/src/views/pages/admin_log.php';
});

/**
 * Audit-Log als Markdown exportieren (Download) -- zum Archivieren oder späteren Auswerten
 * (auch per KI): wer hat wann was geändert. Respektiert den EEG-Filter, exportiert aber ohne
 * das 500-Zeilen-Limit der Ansicht.
 */
$router->get('/admin/log/export', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $filterCommunity = $_GET['community_id'] ?? '';
    $params = []; $where = '1=1';
    if ($filterCommunity !== '') { $where .= ' AND al.community_id = ?'; $params[] = $filterCommunity; }
    $entries = DB::fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, c.name AS community_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN communities c ON c.id = al.community_id
         WHERE $where ORDER BY al.created_at DESC",
        $params
    );
    $esc = fn($s) => str_replace(['|', "\r", "\n"], ['\\|', '', ' '], (string)$s);
    $md  = "# Audit-Log – stromfueralle.at\n\n";
    $md .= 'Exportiert am ' . date('d.m.Y H:i') . ' · ' . count($entries) . " Einträge"
         . ($filterCommunity !== '' ? ' · gefiltert nach einer EEG' : '') . "\n\n";
    $md .= "| Datum/Zeit | Benutzer | EEG | Aktion | Objekt | Beschreibung | Fehler |\n";
    $md .= "|---|---|---|---|---|---|---|\n";
    foreach ($entries as $e) {
        $user = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: ($e['email'] ?? 'System');
        $obj  = trim(($e['entity_typ'] ?? '') . ' ' . ($e['entity_id'] ?? ''));
        $md .= '| ' . date('d.m.Y H:i:s', strtotime($e['created_at']))
             . ' | ' . $esc($user)
             . ' | ' . $esc($e['community_name'] ?? '—')
             . ' | ' . $esc($e['aktion'])
             . ' | ' . $esc($obj)
             . ' | ' . $esc($e['beschreibung'])
             . ' | ' . (!empty($e['ist_fehler']) ? '⚠️ Fehler' : '')
             . " |\n";
    }
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd_His') . '.md"');
    echo $md;
    exit;
});

// ─── Admin: Einstellungen (Plattform-Technik + E-Mail/Microsoft Graph) ──────
$router->get('/admin/mail-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $mailConfig = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $mailTemplates = DB::fetchAll('SELECT * FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    try { $mqttConfig = DB::fetchOne('SELECT * FROM platform_mqtt_config WHERE id = 1'); } catch (\Throwable $e) { $mqttConfig = null; }
    // JSONB-Spalte kommt über PDO als Rohtext zurück, nicht automatisch dekodiert (anders als
    // z.B. psycopg2 in mqtt-subscriber) -- für die Formular-Vorbelegung in der View als Array
    // gebraucht, siehe admin_mail_settings.php ("MQTT-Fernkonfiguration (Geräte)").
    if ($mqttConfig && !empty($mqttConfig['device_reconfig_payload'])) {
        $mqttConfig['device_reconfig_payload'] = json_decode($mqttConfig['device_reconfig_payload'], true) ?: [];
    }
    // Demo-Login: diese Seite zeigt normalerweise mehrere ECHTE Zugangsdaten im Klartext (MQTT-
    // Passwort, Microsoft-Graph Tenant-/Client-ID) -- die Read-only-Sperre verhindert zwar jede
    // Änderung, aber NICHT das bloße Ansehen. Patrick, 06.09.2026: "die ganzen E-Mail-
    // Einstellungen, Sachen wie die Graph API von Microsoft [...] verpixelt oder mit Sternchen".
    // Das Client-Secret selbst wird ohnehin nie im Klartext angezeigt (siehe admin_mail_settings.php).
    if (Auth::isDemo()) {
        if ($mqttConfig) {
            if (!empty($mqttConfig['mqtt_password'])) { $mqttConfig['mqtt_password'] = demoMaskFull((string)$mqttConfig['mqtt_password']); }
            if (!empty($mqttConfig['device_reconfig_payload']['mqtt_pass'])) {
                $mqttConfig['device_reconfig_payload']['mqtt_pass'] = demoMaskFull((string)$mqttConfig['device_reconfig_payload']['mqtt_pass']);
            }
        }
        if ($mailConfig) {
            if (!empty($mailConfig['tenant_id'])) { $mailConfig['tenant_id'] = demoMaskFull((string)$mailConfig['tenant_id']); }
            if (!empty($mailConfig['client_id'])) { $mailConfig['client_id'] = demoMaskFull((string)$mailConfig['client_id']); }
        }
    }
    require ROOT . '/src/views/pages/admin_mail_settings.php';
});

/**
 * Speichert den GEWÜNSCHTEN MQTT-Benutzernamen/Passwort und setzt pending_apply -- die Webapp
 * kann weder Docker noch Dateien auf dem Host direkt anfassen, das eigentliche Anwenden auf den
 * echten Broker übernimmt scripts/mqtt_apply_pending.sh als Host-Cron (siehe migrate_20260827.sql
 * und CLAUDE.md), sobald pending_apply=true dort ankommt -- typischerweise binnen einer Minute.
 * Manueller Fallback (falls der Cron noch nicht eingerichtet ist): `mqtt_secure_setup.sh --apply`.
 */
$router->post('/admin/mqtt-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $mqttUser = trim($_POST['mqtt_user'] ?? '') ?: 'eeg-device';
    $mqttPassword = trim($_POST['mqtt_password'] ?? '');
    DB::execute(
        'UPDATE platform_mqtt_config SET mqtt_user = ?, mqtt_password = ?, pending_apply = true, updated_at = now() WHERE id = 1',
        [$mqttUser, $mqttPassword]
    );
    logAudit(null, 'mqtt_config.update', 'platform_mqtt_config', '1', 'MQTT-Zugangsdaten geändert (Benutzer: ' . $mqttUser . '), Anwendung auf den Broker angestoßen.');
    header('Location: /admin/mail-settings?mqtt_success=1');
    exit;
});

/**
 * MQTT-Fernkonfiguration der ESP-Geräte: schickt Host/Port/Benutzer/Passwort an ALLE bereits
 * im Feld laufenden Geräte, statt jedes einzeln über sein eigenes /config-Formular umstellen zu
 * müssen (Patrick, 12.08.2026, nachdem geklärt war, dass dafür keine offenen Ports beim
 * Mitglied nötig sind -- jedes Gerät baut die MQTT-Verbindung selbst ausgehend auf, der Befehl
 * kommt über genau diese Verbindung zurück). Die Webapp selbst hat keinen MQTT-Client -- speichert
 * hier nur die Anfrage (migrate_20260829.sql), mqtt-subscriber holt sie über die bestehende
 * DB-Verbindung ab und published sie (siehe reconfig_broadcast_loop() in main.py). Nur Geräte
 * mit einer Firmware ab dieser Funktion (onMqttMessage() im Sketch) reagieren darauf -- ältere
 * Geräte ignorieren die für sie unbekannte Nachricht einfach.
 */
$router->post('/admin/mqtt-device-reconfig', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $host = trim($_POST['device_mqtt_host'] ?? '');
    $port = (int)($_POST['device_mqtt_port'] ?? 0);
    $user = trim($_POST['device_mqtt_user'] ?? '');
    $pass = (string)($_POST['device_mqtt_pass'] ?? '');
    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        header('Location: /admin/mail-settings?error=' . urlencode('Für die Geräte-Fernkonfiguration bitte Host, Port, Benutzername und Passwort ausfüllen.'));
        exit;
    }
    $payload = json_encode(['mqtt_host' => $host, 'mqtt_port' => $port, 'mqtt_user' => $user, 'mqtt_pass' => $pass]);
    DB::execute(
        "UPDATE platform_mqtt_config SET device_reconfig_payload = ?, device_reconfig_requested_at = now() WHERE id = 1",
        [$payload]
    );
    logAudit(null, 'mqtt_config.device_reconfig', 'platform_mqtt_config', '1',
        "MQTT-Fernkonfiguration an alle Geräte angestoßen (Host: $host:$port, Benutzer: $user).");
    header('Location: /admin/mail-settings?mqtt_device_success=1');
    exit;
});

/**
 * Backup-Übersicht: zeigt, ob die nächtliche Sicherung wirklich läuft (Alter/Größe der letzten
 * Dumps) und welche Sicherungen zum Wiederherstellen bereitliegen. Liest das Backup-Verzeichnis
 * NUR LESEND (Mount `:ro` in docker-compose.yml) -- die Webapp kann Backups also anzeigen, aber
 * nie verändern oder löschen.
 */
$router->get('/admin/backups', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }

    $dir = '/var/www/html/backups';
    $status = null;
    if (is_readable($dir . '/last_backup.json')) {
        $status = json_decode((string)file_get_contents($dir . '/last_backup.json'), true) ?: null;
    }
    // Sicherungen einsammeln und nach Art gruppieren.
    $arten = [
        'stamm' => ['label' => 'Stammdaten (Mitglieder, Rechnungen, Verträge)', 'glob' => 'eeg_stamm_*.dump', 'dateien' => []],
        'voll'  => ['label' => 'Datenbank vollständig (inkl. Messwerte)',        'glob' => 'eeg_2*.dump',      'dateien' => []],
        'full'  => ['label' => 'Komplettsicherung (Datenbank + Dateien)',        'glob' => 'eeg_full_*.tar.gz','dateien' => []],
    ];
    $dirLesbar = is_dir($dir) && is_readable($dir);
    if ($dirLesbar) {
        foreach ($arten as $key => $art) {
            foreach (glob($dir . '/' . $art['glob']) ?: [] as $pfad) {
                $arten[$key]['dateien'][] = [
                    'name'  => basename($pfad),
                    'bytes' => filesize($pfad) ?: 0,
                    'zeit'  => filemtime($pfad) ?: 0,
                ];
            }
            usort($arten[$key]['dateien'], fn($a, $b) => $b['zeit'] <=> $a['zeit']);
        }
    }
    require ROOT . '/src/views/pages/admin_backups.php';
});

/**
 * Testmodus/Echtbetrieb: steuert nur, ob die Kundennummern-Vergabe Lücken von gelöschten/
 * deaktivierten Mitgliedern auffüllen darf (siehe createMemberRecord()) -- im Echtbetrieb
 * wird eine einmal vergebene Nummer nie wieder verwendet.
 */
$router->post('/admin/settings/test-mode', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $testMode = !empty($_POST['test_mode']);
    // PDO gibt einen rohen PHP-Bool ohne expliziten Typ standardmäßig als String weiter --
    // "true" wird von Postgres noch akzeptiert, ein rohes "false" aber als leerer String (''),
    // was am boolean-Spaltentyp scheitert (SQLSTATE 22P02). Deshalb wie im Rest der Codebase
    // durchgehend 'true'/'false' als Literal übergeben statt des PHP-Bools direkt.
    DB::execute('UPDATE platform_settings SET test_mode = ?, updated_at = now() WHERE id = 1', [$testMode ? 'true' : 'false']);
    // entity_id ist in audit_log als UUID typisiert -- platform_settings.id ist aber ein
    // simpler Integer (immer 1), passt dort nicht rein.
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'Plattform auf ' . ($testMode ? 'Testmodus' : 'Echtbetrieb') . ' umgestellt');
    header('Location: /admin/mail-settings?success=1');
    exit;
});

/** Siehe migrate_20260823.sql / espOfflineAfterMinutes(). */
$router->post('/admin/settings/esp', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $minutes = (int)($_POST['esp_offline_after_minutes'] ?? 5);
    if ($minutes < 1) $minutes = 5;
    DB::execute('UPDATE platform_settings SET esp_offline_after_minutes = ?, updated_at = now() WHERE id = 1', [$minutes]);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'ESP-Offline-Schwelle auf ' . $minutes . ' Minuten gesetzt');
    header('Location: /admin/mail-settings?success=1');
    exit;
});

$router->post('/admin/mail-templates', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $key = $_POST['key'] ?? '';
    if (!in_array($key, ['password_reset', 'invite', 'member_deactivated', 'contract_bezug', 'contract_einspeisung', 'contract_both', 'sepa_prenotification', 'mahnung'], true)) { http_response_code(400); return; }
    $tplBefore = DB::fetchOne('SELECT subject, body_html FROM platform_mail_templates WHERE key = ?', [$key]);
    DB::execute(
        'UPDATE platform_mail_templates SET subject = ?, body_html = ?, updated_at = now() WHERE key = ?',
        [trim($_POST['subject'] ?? ''), $_POST['body_html'] ?? '', $key]
    );
    $tplAfter = DB::fetchOne('SELECT subject, body_html FROM platform_mail_templates WHERE key = ?', [$key]);
    $tplChanges = auditDiff($tplBefore ?? [], $tplAfter ?? [], ['subject' => 'Betreff', 'body_html' => 'Text (HTML)']);
    if (!empty($tplChanges)) {
        logAuditDiff(null, 'mail_template.update', 'platform_mail_templates', $key, $tplChanges, 'E-Mail-Vorlage „' . $key . '":');
    } else {
        logAudit(null, 'mail_template.update', 'platform_mail_templates', $key, 'E-Mail-Vorlage "' . $key . '" gespeichert (keine Änderung)');
    }
    header('Location: /admin/mail-settings?success=1');
    exit;
});

$router->post('/admin/mail-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    // Client-Secret nur überschreiben, wenn tatsächlich ein neuer Wert eingegeben wurde --
    // das Feld wird beim Laden nie im Klartext vorbefüllt, ein leeres Absenden darf das
    // gespeicherte Secret also nicht versehentlich löschen.
    $current = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $newSecret = trim($_POST['client_secret'] ?? '');
    $clientSecret = $newSecret !== '' ? $newSecret : ($current['client_secret'] ?? null);

    // Signatur-Logo: bestehendes behalten, per Checkbox entfernen oder durch Upload ersetzen.
    $logoBase64 = $current['signature_logo_base64'] ?? null;
    $logoType   = $current['signature_logo_type'] ?? null;
    if (!empty($_POST['signature_logo_remove'])) {
        $logoBase64 = null;
        $logoType   = null;
    }
    if (!empty($_FILES['signature_logo']) && $_FILES['signature_logo']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['signature_logo']['size'] > 2 * 1024 * 1024) {
            header('Location: /admin/mail-settings?error=' . urlencode('Logo zu groß (max. 2 MB).')); exit;
        }
        $info = @getimagesize($_FILES['signature_logo']['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) {
            header('Location: /admin/mail-settings?error=' . urlencode('Datei ist kein gültiges Bild (PNG/JPG/GIF).')); exit;
        }
        $logoBase64 = base64_encode((string)file_get_contents($_FILES['signature_logo']['tmp_name']));
        $logoType   = $info['mime'];
    }
    // Logo-Größe (px). Leer/0/ungültig -> NULL (Standardgröße). Max. 1000 px als Sanity-Grenze.
    // isset()-Prüfung aus demselben Grund wie bei $keep() unten: nur die große Form hat diese
    // Felder überhaupt, ein Request vom kleinen EDA-Postfach-Formular darf sie nicht auf NULL
    // zurücksetzen.
    $clampPx = function ($v): ?int {
        $n = (int)$v;
        return ($n > 0 && $n <= 1000) ? $n : null;
    };
    $logoWidth  = isset($_POST['signature_logo_width'])  ? $clampPx($_POST['signature_logo_width'])  : ($current['signature_logo_width']  ?? null);
    $logoHeight = isset($_POST['signature_logo_height']) ? $clampPx($_POST['signature_logo_height']) : ($current['signature_logo_height'] ?? null);

    // Mehrere <form>-Elemente auf dieser Seite posten hierher (das große Formular mit ALLEN
    // Feldern, aber z.B. auch das kleine "EDA-Automatik"-Postfach-Formular weiter unten, das
    // NUR eda_import_mailbox_address enthält). Ohne diese isset()-Prüfung würde jedes Feld, das
    // im gerade abgeschickten Formular schlicht nicht vorkommt, mit trim(null ?? '') ?: null zu
    // NULL -- die kleine Postfach-Form hätte damit bei JEDEM Speichern Tenant-ID/Client-ID/
    // Signatur/Alarm-Adressen gelöscht (Patrick, 13.08.2026: "Er haut mir immer die azure App
    // daten raus. und die signatur ist auch wieder weg."). $keep() unterscheidet "Feld war in
    // DIESEM Request gar nicht dabei -> alten Wert behalten" von "Feld war dabei, aber leer
    // abgeschickt -> wirklich löschen" (isset() ist true auch bei einem leer übermittelten Feld).
    $keep = function (string $key) use ($current) {
        return isset($_POST[$key]) ? (trim($_POST[$key]) ?: null) : ($current[$key] ?? null);
    };
    $supportEmail = isset($_POST['support_notification_email'])
        ? (trim($_POST['support_notification_email']) ?: 'office@stromfueralle.at')
        : ($current['support_notification_email'] ?? 'office@stromfueralle.at');

    DB::execute(
        'UPDATE platform_mail_config
         SET tenant_id = ?, client_id = ?, client_secret = ?, sender_address = ?, reply_to = ?, signature_html = ?,
             signature_logo_base64 = ?, signature_logo_type = ?,
             signature_logo_width = ?, signature_logo_height = ?,
             backup_alert_email_1 = ?, backup_alert_email_2 = ?, support_notification_email = ?,
             eda_import_mailbox_address = ?, updated_at = now()
         WHERE id = 1',
        [
            $keep('tenant_id'),
            $keep('client_id'),
            $clientSecret,
            $keep('sender_address'),
            $keep('reply_to'),
            $keep('signature_html'),
            $logoBase64,
            $logoType,
            $logoWidth,
            $logoHeight,
            $keep('backup_alert_email_1'),
            $keep('backup_alert_email_2'),
            $supportEmail,
            $keep('eda_import_mailbox_address'),
        ]
    );
    $mailAfter = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    // Sensible Werte (Secret, Logo-Rohdaten) werden NICHT im Klartext protokolliert -- nur, DASS
    // sie geändert wurden. Deshalb secret/logo aus dem eigentlichen Diff heraushalten und separat
    // als Ja/Nein-Änderung behandeln.
    $mailChanges = auditDiff($current ?? [], $mailAfter ?? [], [
        'tenant_id' => 'Tenant-ID', 'client_id' => 'Client-ID', 'sender_address' => 'Absenderadresse',
        'reply_to' => 'Antwort-an', 'signature_html' => 'Signatur',
        'signature_logo_width' => 'Logo-Breite', 'signature_logo_height' => 'Logo-Höhe',
        'backup_alert_email_1' => 'Alarm-E-Mail 1', 'backup_alert_email_2' => 'Alarm-E-Mail 2',
        'support_notification_email' => 'Support-Ticket-Benachrichtigung an',
        'eda_import_mailbox_address' => 'EDA-Import-Postfach',
    ]);
    if (($current['client_secret'] ?? null) !== ($mailAfter['client_secret'] ?? null)) {
        $mailChanges['client_secret'] = ['label' => 'Client-Secret', 'von' => '(verborgen)', 'auf' => '(geändert)'];
    }
    if (($current['signature_logo_base64'] ?? null) !== ($mailAfter['signature_logo_base64'] ?? null)) {
        $mailChanges['signature_logo'] = ['label' => 'Signatur-Logo', 'von' => (empty($current['signature_logo_base64']) ? 'kein' : 'vorhanden'), 'auf' => (empty($mailAfter['signature_logo_base64']) ? 'entfernt' : 'gesetzt')];
    }
    if (!empty($mailChanges)) {
        logAuditDiff(null, 'mail_config.update', 'platform_mail_config', '1', $mailChanges, 'Mail-Konfiguration:');
    } else {
        logAudit(null, 'mail_config.update', 'platform_mail_config', '1', 'Mail-Konfiguration gespeichert (keine Änderung)');
    }
    header('Location: /admin/mail-settings?success=1');
    exit;
});

/**
 * Manueller Testlauf des EDA-Postfach-Imports (siehe EdaAutoImporter.php) -- normalerweise
 * läuft das per Cron (scripts/eda_auto_import.php), dieser Button ist zum Testen/Nachstoßen,
 * ohne auf den nächsten Cron-Durchlauf warten zu müssen.
 */
$router->post('/admin/mail-settings/eda-import-run', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    try {
        $lines = EdaAutoImporter::run();
        logAudit(null, 'eda.auto_import.manual_run', null, null, 'EDA-Postfach-Import manuell angestoßen: ' . implode(' | ', $lines));
        header('Location: /admin/mail-settings?eda_run=' . urlencode(implode("\n", $lines)));
    } catch (\Throwable $e) {
        header('Location: /admin/mail-settings?eda_run_error=' . urlencode($e->getMessage()));
    }
    exit;
});

$router->post('/admin/mail-settings/test', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $to = trim($_POST['test_to'] ?? '');
    $mailConfig = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $mailTemplates = DB::fetchAll('SELECT * FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $testError = 'Bitte eine gültige E-Mail-Adresse angeben.';
        require ROOT . '/src/views/pages/admin_mail_settings.php';
        return;
    }
    try {
        Mailer::send($to, 'Test-E-Mail von Strom für alle', '<p>Das ist eine Test-E-Mail aus dem Platform-Admin-Bereich von stromfueralle.at.</p><p>Wenn Sie das lesen, funktioniert der Microsoft-Graph-Mailversand.</p>');
        $testSuccess = 'Test-E-Mail an ' . htmlspecialchars($to) . ' wurde verschickt.';
    } catch (\Throwable $e) {
        $testError = $e->getMessage();
    }
    require ROOT . '/src/views/pages/admin_mail_settings.php';
});

/**
 * Whitelist der über /admin/templates verwaltbaren Dateien (Dateiname => Anzeigename + Typ).
 * Bewusst eine feste Liste statt freier Dateinamen -- verhindert Path-Traversal. Umfasst die
 * LaTeX-Vorlagen (von latex-service live pro Anfrage gerendert) UND das Infoblatt: das ist
 * KEINE Vorlage, sondern eine fertige, statische PDF für die Marketing-Seite (kein
 * personalisierter Inhalt, daher kein Live-Rendering) -- hier lädt der Platform-Admin direkt
 * eine fertige PDF hoch, kein .tex.
 */
function adminFileRegistry(): array
{
    return [
        'bezugsvereinbarung.tex'           => ['label' => 'Bezugsvereinbarung', 'type' => 'tex'],
        'einspeisevereinbarung.tex'        => ['label' => 'Einspeisevereinbarung', 'type' => 'tex'],
        'rechnung.tex'                     => ['label' => 'Rechnung', 'type' => 'tex'],
        'beitrittserklaerung_formular.tex' => ['label' => 'Beitrittserklärung', 'type' => 'tex'],
        'infoblatt.pdf'                    => ['label' => 'Infoblatt (Website)', 'type' => 'pdf'],
        'logo-light.png'                   => ['label' => 'Logo (Light-Mode)', 'type' => 'image'],
        'logo-dark.png'                    => ['label' => 'Logo (Dark-Mode)', 'type' => 'image'],
        'hero-banner.png'                  => ['label' => 'Hero-Banner (Startseite)', 'type' => 'image'],
    ];
}

/**
 * Verfügbare <<<VARIABLE>>>-Platzhalter je LaTeX-Vorlage, rein zu Anzeigezwecken in
 * /admin/templates -- Quelle der Wahrheit sind die .tex-Dateien selbst (latex-service
 * ersetzt <<<NAME>>> durch den jeweiligen Wert, siehe latex-service/service.js). Ein
 * mit RAW_ beginnender Variablenname wird NICHT für LaTeX escaped (dort steht bereits
 * fertiges LaTeX drin, z.B. eine eingebettete Unterschrift-Grafik oder eine Liste) --
 * beim Bearbeiten der Vorlage also nicht versehentlich Text hineinschreiben, der wie
 * ein RAW_-Platzhalter aussieht.
 */
function adminFileVariables(): array
{
    return [
        'rechnung.tex' => [
            'RECHNUNGSNUMMER' => 'Fortlaufende Rechnungsnummer',
            'RECHNUNGSDATUM' => 'Ausstellungsdatum',
            'ABRECHNUNGSZEITRAUM' => 'Zeitraum, für den abgerechnet wird',
            'ZAHLUNGSZIEL' => 'Fälligkeitsdatum',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft (einzeilig)',
            'EEG_STRASSE' => 'Straße/Hausnummer der Energiegemeinschaft (erster Teil von EEG_ADRESSE, für den Footer)',
            'EEG_PLZ_ORT' => 'PLZ und Ort der Energiegemeinschaft (zweiter Teil von EEG_ADRESSE, für den Footer)',
            'EEG_UID' => 'UID-Nummer der Energiegemeinschaft',
            'EEG_ZVR' => 'Zentralvereinsregisternummer (ZVR) der Energiegemeinschaft',
            'EEG_OBMANN_TELEFON' => 'Kontakt-Telefonnummer (Obmann/Obfrau) der Energiegemeinschaft, für den Rechnungs-Footer',
            'EEG_KONTAKT_EMAIL' => 'Kontakt-E-Mail der Energiegemeinschaft, für den Rechnungs-Footer',
            'EEG_BANKNAME' => 'Name der Bank der Energiegemeinschaft, für den Rechnungs-Footer',
            'EEG_KONTOINHABER' => 'Kontoinhaber (falls abweichend vom EEG-Namen), für den Rechnungs-Footer -- leer = Vorlage fällt auf EEG_NAME zurück',
            'MITGLIED_ANREDE' => 'Anrede des Mitglieds (Herr/Frau)',
            'MITGLIED_NAME' => 'Anzeigename: abweichender Rechnungsname > Firma > Titel + Vor-/Nachname',
            'MITGLIED_ADRESSE' => 'Adresse des Mitglieds (einzeilig, Fallback falls Vorlage keine getrennten Zeilen nutzt)',
            'MITGLIED_STRASSE' => 'Straße/Hausnummer des Mitglieds (für getrennte Adresszeilen)',
            'MITGLIED_PLZ_ORT' => 'PLZ und Ort des Mitglieds (für getrennte Adresszeilen)',
            'MITGLIED_UID' => 'UID-Nummer des Mitglieds (falls Firma)',
            'KUNDENNUMMER' => 'Plattformweit eindeutige Kundennummer des Mitglieds',
            'MITGLIED_SEPA_MANDATSREFERENZ' => 'SEPA-Mandatsreferenz des Mitglieds, falls vorhanden',
            'BEZUG_KWH' => 'Bezogene Energiemenge in kWh (Summe; Fallback-Einzeiler, wenn keine RAW-Bezugsliste)',
            'BEZUG_TARIF' => 'Bezugstarif (ct/kWh) für den Fallback-Einzeiler',
            'BEZUG_BETRAG' => 'Betrag für den Bezug (Summe) für den Fallback-Einzeiler',
            'BEZUG_ZAEHLPUNKT' => 'Einzelne Bezugs-Zählpunktnummer für den Fallback-Einzeiler (nur bei genau einem Zählpunkt)',
            'EINSPEISUNG_KWH' => 'Eingespeiste Energiemenge in kWh (Summe; Fallback-Einzeiler)',
            'EINSPEISUNG_TARIF' => 'Einspeisetarif (ct/kWh) für den Fallback-Einzeiler',
            'EINSPEISUNG_BETRAG' => 'Betrag für die Einspeisung (Summe) für den Fallback-Einzeiler',
            'EINSPEISUNG_ZAEHLPUNKT' => 'Einzelne Einspeise-Zählpunktnummer für den Fallback-Einzeiler (nur bei genau einem Zählpunkt)',
            'RAW_BEZUG_POSITIONEN_LISTE' => 'Vorformatierte Tabellenzeilen für den Bezug -- eine Zeile pro Zählpunkt, jeweils mit Zählpunktnummer (RAW, nicht escapen). Leer = Fallback auf BEZUG_KWH-Einzeiler',
            'RAW_EINSPEISUNG_POSITIONEN_LISTE' => 'Vorformatierte Tabellenzeilen für die Einspeisung -- eine Zeile pro Zählpunkt (RAW, nicht escapen). Leer = Fallback auf EINSPEISUNG_KWH-Einzeiler',
            'MITGLIEDSBEITRAG' => 'Mitgliedsbeitrag laut Preisliste (anteilig bei unterjährigem Beitritt)',
            'SUMME_NETTO' => 'Gesamtsumme netto',
            'SUMME_BRUTTO' => 'Gesamtsumme brutto',
            'IBAN' => 'IBAN der Energiegemeinschaft (für die Zahlung)',
            'BIC' => 'BIC der Energiegemeinschaft',
            'RAW_STEUER_ZEILE' => 'Vorformatierte USt-Zeile (RAW, nicht escapen)',
            'RAW_STEUER_TEXT' => 'Vorformatierter Steuerhinweis-Text (RAW, nicht escapen)',
            'RAW_ZUSATZPOSITIONEN_LISTE' => 'Vorformatierte Tabellenzeilen für manuelle Zusatzpositionen, z.B. ein Rabatt (RAW, nicht escapen) -- siehe /portal/billing',
            'RAW_ZAHLUNG_TEXT' => 'Vorformatierter Zahlungstext: SEPA-Lastschrift-Vorabankündigung, Gutschrift-Hinweis oder leer (Vorlage zeigt dann die Standard-Überweisungsbitte) -- RAW, nicht escapen',
            'RAW_SUMME_LABEL' => 'Beschriftung der Summe, z.B. "Ihr Guthaben" bei einer Gutschrift statt "Gesamtbetrag" (RAW, nicht escapen)',
        ],
        'bezugsvereinbarung.tex' => [
            'ERSTELLT_AM' => 'Erstellungsdatum des Dokuments',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft',
            'EEG_ORT' => 'Ort der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'EEG_IBAN' => 'IBAN der Energiegemeinschaft',
            'EEG_MARKTPARTNER_ID' => 'Marktpartner-ID der Energiegemeinschaft',
            'EEG_DASHBOARD_URL' => 'Link zum öffentlichen Live-Dashboard',
            'MITGLIED_NAME' => 'Name des Mitglieds (inkl. Titel)',
            'MITGLIED_ADRESSE' => 'Adresse des Mitglieds',
            'MITGLIED_IBAN' => 'IBAN des Mitglieds',
            'MITGLIED_UID_ZEILE' => 'UID-Zeile des Mitglieds, falls vorhanden',
            'MITGLIED_SEPA_MANDATSREFERENZ' => 'SEPA-Mandatsreferenz des Mitglieds',
            'BEZUG_TARIF' => 'Vereinbarter Bezugstarif (€/kWh)',
            'TARIF_GUELTIG_AB' => 'Gültig-ab-Datum des Tarifs',
            'MITGLIEDSBEITRAG' => 'Mitgliedsbeitrag laut Preisliste',
            'RAW_ZAEHLPUNKTE_LISTE' => 'Vorformatierte Liste der Bezugs-Zählpunkte (RAW, nicht escapen)',
            'RAW_EEG_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik der EEG (RAW, nicht escapen)',
            'RAW_MITGLIED_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik des Mitglieds (RAW, nicht escapen)',
            'RAW_MITGLIED_ORT_DATUM' => 'Vorformatierter Ort/Datum-Text bei der Unterschrift (RAW, nicht escapen)',
        ],
        'einspeisevereinbarung.tex' => [
            'ERSTELLT_AM' => 'Erstellungsdatum des Dokuments',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft',
            'EEG_ORT' => 'Ort der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'EEG_MARKTPARTNER_ID' => 'Marktpartner-ID der Energiegemeinschaft',
            'MITGLIED_NAME' => 'Name des Mitglieds (inkl. Titel)',
            'MITGLIED_ADRESSE' => 'Adresse des Mitglieds',
            'MITGLIED_IBAN' => 'IBAN des Mitglieds',
            'MITGLIED_UID_ZEILE' => 'UID-Zeile des Mitglieds, falls vorhanden',
            'MITGLIED_SEIT' => 'Mitglied-seit-Datum',
            'ANLAGENBESCHREIBUNG' => 'Beschreibung der Einspeiseanlage (z.B. PV-Leistung)',
            'EINSPEISUNG_TARIF' => 'Vereinbarter Einspeisetarif (€/kWh)',
            'TARIF_GUELTIG_AB' => 'Gültig-ab-Datum des Tarifs',
            'RAW_ZAEHLPUNKTE_LISTE' => 'Vorformatierte Liste der Einspeise-Zählpunkte (RAW, nicht escapen)',
            'RAW_EEG_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik der EEG (RAW, nicht escapen)',
            'RAW_MITGLIED_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik des Mitglieds (RAW, nicht escapen)',
            'RAW_MITGLIED_ORT_DATUM' => 'Vorformatierter Ort/Datum-Text bei der Unterschrift (RAW, nicht escapen)',
        ],
        'beitrittserklaerung_formular.tex' => [
            'EINGEREICHT_AM' => 'Einreichdatum des Formulars',
            'TITEL' => 'Titel (z.B. akademischer Grad)',
            'VORNAME' => 'Vorname',
            'NACHNAME' => 'Nachname',
            'GEBURTSDATUM' => 'Geburtsdatum',
            'ADRESSE' => 'Adresse',
            'EMAIL' => 'E-Mail-Adresse',
            'TELEFON' => 'Telefonnummer',
            'STROMLIEFERANT' => 'Bisheriger Stromlieferant',
            'BEZUG_JAHRESVERBRAUCH' => 'Jährlicher Stromverbrauch (kWh)',
            'EINSPEISUNG_GEPLANT' => 'Ob eine Einspeiseanlage geplant ist',
            'EINSPEISUNG_KWP' => 'Geplante PV-Leistung (kWp)',
            'SPEICHER_KWH' => 'Geplante Speicherkapazität (kWh)',
            'ANDERE_EEG_NAME' => 'Name einer evtl. anderen EEG-Mitgliedschaft',
            'EEG_NAME' => 'Name der Energiegemeinschaft',
            'EEG_ADRESSE' => 'Adresse der Energiegemeinschaft',
            'EEG_ZVR' => 'ZVR-Zahl der Energiegemeinschaft',
            'SIGNER_IP' => 'IP-Adresse bei der Online-Unterschrift',
            'UNTERSCHRIEBEN_AM' => 'Zeitpunkt der Unterschrift',
            'UNTERSCHRIEBEN_DATUM' => 'Datum der Unterschrift',
            'RAW_ANREDE_HERR' => 'Ankreuzfeld „Herr" (RAW, nicht escapen)',
            'RAW_ANREDE_FRAU' => 'Ankreuzfeld „Frau" (RAW, nicht escapen)',
            'RAW_BEZUG_CB' => 'Ankreuzfeld Bezug (RAW, nicht escapen)',
            'RAW_EINSPEISUNG_CB' => 'Ankreuzfeld Einspeisung (RAW, nicht escapen)',
            'RAW_SPEICHER_JA' => 'Ankreuzfeld Speicher „Ja" (RAW, nicht escapen)',
            'RAW_SPEICHER_NEIN' => 'Ankreuzfeld Speicher „Nein" (RAW, nicht escapen)',
            'RAW_SPEICHER_GEPLANT' => 'Vorformatierter Speicher-Block (RAW, nicht escapen)',
            'RAW_ANDERE_EEG_JA' => 'Ankreuzfeld andere EEG „Ja" (RAW, nicht escapen)',
            'RAW_ANDERE_EEG_NEIN' => 'Ankreuzfeld andere EEG „Nein" (RAW, nicht escapen)',
            'RAW_ZP_BEZUG_GRID' => 'Vorformatiertes Raster der Bezugs-Zählpunkte (RAW, nicht escapen)',
            'RAW_ZP_EINSPEISUNG_GRID' => 'Vorformatiertes Raster der Einspeise-Zählpunkte (RAW, nicht escapen)',
            'RAW_ZUSTIMMUNGEN_LISTE' => 'Vorformatierte Liste der Zustimmungen (RAW, nicht escapen)',
            'RAW_SEPA_BLOCK' => 'Vorformatierter SEPA-Block (RAW, nicht escapen)',
            'RAW_UNTERSCHRIFT_BILD' => 'Eingebettete Unterschrift-Grafik (RAW, nicht escapen)',
        ],
    ];
}

/**
 * Bild-Assets (keine Text-Platzhalter, sondern eingebettete Dateien) je Vorlage --
 * eingebunden in LaTeX über \includegraphics{dateiname} (benötigt \usepackage{graphicx}).
 */
function adminFileAssets(): array
{
    return [
        'rechnung.tex' => [
            'logo.png' => 'Logo der EEG. Eigenes hochgeladenes Logo (Manager-Einstellungen -> Logo für Rechnungen/Verträge) oder ersatzweise das Website-Logo -- immer vorhanden, \includegraphics{logo.png} funktioniert also immer.',
        ],
    ];
}

/**
 * Vollständige Liste aller Felder aus members/communities/tariff_config/tax_config, die
 * grundsätzlich für neue Platzhalter zur Verfügung stünden -- unabhängig davon, ob sie
 * aktuell schon in einer Vorlage verwendet werden (siehe adminFileVariables()). Gedacht als
 * Nachschlagewerk beim Erweitern einer Vorlage bzw. zum Export für eine externe KI, die beim
 * Schreiben einer neuen .tex-Datei hilft. Ist ein Feld hier noch nicht als <<<VAR>>> in einer
 * bestehenden Vorlage verdrahtet, muss es zuerst im jeweiligen PHP-Code (z.B. der
 * /portal/invoices/:id/pdf-Route) ergänzt werden, bevor es dort tatsächlich ersetzt wird --
 * diese Liste beschreibt die verfügbaren Rohdaten, nicht automatisch fertige Platzhalter.
 */
function availableDataFields(): array
{
    return [
        'Mitglied (members)' => [
            'salutation' => 'Anrede (Herr/Frau)',
            'titel' => 'Akademischer Titel',
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'company_name' => 'Firmenname, falls Firmenmitglied',
            'address' => 'Straße und Hausnummer',
            'zip' => 'Postleitzahl',
            'city' => 'Ort',
            'email' => 'E-Mail-Adresse',
            'phone' => 'Telefonnummer',
            'invoice_name' => 'Abweichender Name für Rechnungen (falls gesetzt)',
            'invoice_uid' => 'UID-Nummer für Rechnungen (falls Firma)',
            'member_iban' => 'IBAN des Mitglieds',
            'member_bic' => 'BIC des Mitglieds',
            'kundennummer' => 'Plattformweit eindeutige Kundennummer',
            'mandatsreferenz' => 'SEPA-Mandatsreferenz',
            'member_since' => 'Mitglied seit (Datum)',
            'member_until' => 'Mitglied bis (Datum, falls beendet)',
            'status' => 'Mitgliedsstatus (pending/active/inactive)',
        ],
        'Zählpunkt (metering_points)' => [
            'zaehlpunkt_nr' => 'Zählpunktnummer (AT...)',
            'type' => 'Zählpunkt-Typ (consumer/producer/prosumer)',
            'active' => 'Ob der Zählpunkt aktiv ist',
        ],
        'Energiegemeinschaft (communities)' => [
            'name' => 'Name der EEG',
            'marktpartner_id' => 'Marktpartner-ID (z.B. RC108175)',
            'zvr_number' => 'ZVR-Zahl',
            'address' => 'Adresse der EEG',
            'dashboard_url' => 'Link zum Mitgliederportal',
            'iban' => 'IBAN der EEG',
            'bic' => 'BIC der EEG',
            'payment_days' => 'Zahlungsziel in Tagen',
        ],
        'Tarif (tariff_config, historisiert)' => [
            'valid_from' => 'Gültig ab (Datum)',
            'bezug_ct_kwh' => 'Bezugstarif in ct/kWh',
            'einspeisung_ct_kwh' => 'Einspeisevergütung in ct/kWh',
            'mitgliedsbeitrag_eur' => 'Jahresbeitrag in EUR',
        ],
        'Steuer (tax_config, historisiert)' => [
            'tax_model' => 'Steuermodell (kleinunternehmer/standard)',
            'tax_rate_percent' => 'USt-Satz in %, falls "standard"',
            'uid_number' => 'UID-Nummer der EEG (falls USt-pflichtig)',
        ],
        'Abrechnung (invoices/invoice_items/billing_runs)' => [
            'rechnungsnummer' => 'Fortlaufende Rechnungsnummer',
            'saldo_eur' => 'Gesamtsumme der Rechnung',
            'quartal' => 'Abgerechnetes Quartal (z.B. 2026-Q1)',
            'period_from / period_to' => 'Abrechnungszeitraum',
            'invoice_items.label/quantity/unit/amount_eur' => 'Manuelle Zusatzposition (z.B. Rabatt) -- siehe /portal/billing',
        ],
    ];
}

/**
 * Pfad zur aktuell wirksamen Fassung einer verwalteten Datei: das persistente Volume
 * (/var/www/html/latex-templates, geteilt mit latex-service) hat Vorrang, sonst die im
 * Image mitgelieferte Standard-Fassung als Rückfallebene (z.B. direkt nach einem frischen
 * Deploy, bevor latex-service beim ersten Start das Volume befüllt hat, oder bevor überhaupt
 * einmal etwas hochgeladen wurde).
 */
function adminFilePath(string $filename): ?string
{
    $live = '/var/www/html/latex-templates/' . $filename;
    if (is_file($live)) { return $live; }
    $default = '/var/www/html/latex-templates-default/' . $filename;
    return is_file($default) ? $default : null;
}

/**
 * Pfad zum individuellen Logo einer einzelnen EEG für Rechnungen/Verträge (anders als
 * logo-light.png/logo-dark.png in adminFileRegistry(), die für die ganze Website gelten --
 * jede Community kann hier ein eigenes Logo hinterlegen). Kein DB-Feld nötig, reine
 * Dateikonvention nach community_id, gleiches geteiltes Volume wie die LaTeX-Vorlagen.
 */
function communityLogoPath(string $communityId): ?string
{
    $path = '/var/www/html/latex-templates/community-logos/' . $communityId . '.png';
    return is_file($path) ? $path : null;
}

/**
 * Logo-Bild als LaTeX-Asset für die Rechnungserstellung: eigenes Logo der EEG falls
 * hochgeladen, sonst das Website-Logo (Light-Variante) als Rückfallebene -- so findet
 * \includegraphics{logo.png} in rechnung.tex immer eine Datei, auch wenn diese EEG noch
 * keins hochgeladen hat.
 */
function communityLogoAsset(string $communityId): array
{
    $path = communityLogoPath($communityId)
        ?? adminFilePath('logo-light.png')
        ?? ROOT . '/public/assets/images/logo.png';
    return is_file($path) ? ['logo.png' => base64_encode(file_get_contents($path))] : [];
}

// rechnungExtraItemsLatex / rechnungPositionenLatex nach src/functions.php ausgelagert (testbar).

$router->get('/admin/templates', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $templates = [];
    foreach (adminFileRegistry() as $filename => $info) {
        $path = adminFilePath($filename);
        $templates[] = [
            'filename'  => $filename,
            'label'     => $info['label'],
            'type'      => $info['type'],
            'exists'    => $path !== null,
            'is_custom' => $path !== null && str_starts_with($path, '/var/www/html/latex-templates/'),
            'size'      => $path ? filesize($path) : null,
            'mtime'     => $path ? filemtime($path) : null,
            'variables' => adminFileVariables()[$filename] ?? null,
            'assets'    => adminFileAssets()[$filename] ?? null,
        ];
    }
    require ROOT . '/src/views/pages/admin_templates.php';
});

/**
 * Export der Variablen-Referenz (alle Vorlagen) als Markdown bzw. CSV -- gedacht zum
 * Weitergeben an eine KI, die beim Schreiben/Anpassen einer .tex-Datei hilft, ohne dass man
 * die Platzhalternamen händisch abtippen muss.
 */
$router->get('/admin/templates/variablen.md', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $md = "# Variablen-Referenz -- Strom für alle\n\n";
    $md .= "Platzhalter der Form `<<<NAME>>>` in den LaTeX-Vorlagen. Namen mit `RAW_`-Präfix ";
    $md .= "enthalten bereits fertiges LaTeX und werden NICHT escaped.\n\n";
    foreach (adminFileRegistry() as $filename => $info) {
        if ($info['type'] !== 'tex') continue;
        $md .= "## " . $info['label'] . " (`{$filename}`)\n\n";
        $vars = adminFileVariables()[$filename] ?? [];
        if ($vars) {
            $md .= "| Variable | Beschreibung |\n|---|---|\n";
            foreach ($vars as $name => $desc) { $md .= "| `<<<{$name}>>>` | {$desc} |\n"; }
            $md .= "\n";
        }
        $assets = adminFileAssets()[$filename] ?? [];
        if ($assets) {
            $md .= "**Bild-Assets** (über `\\includegraphics{dateiname}`, benötigt `\\usepackage{graphicx}`):\n\n";
            $md .= "| Datei | Beschreibung |\n|---|---|\n";
            foreach ($assets as $name => $desc) { $md .= "| `{$name}` | {$desc} |\n"; }
            $md .= "\n";
        }
    }
    $md .= "# Verfügbare Rohdaten (noch nicht zwingend als Platzhalter verdrahtet)\n\n";
    $md .= "Diese Felder existieren in der Datenbank und könnten bei Bedarf als zusätzliche ";
    $md .= "`<<<VARIABLE>>>` in eine Vorlage aufgenommen werden -- dafür muss der jeweilige ";
    $md .= "PHP-Code (z.B. die Rechnungs-/Vertrags-Route) noch angepasst werden, damit der Wert ";
    $md .= "tatsächlich mitgeschickt wird.\n\n";
    foreach (availableDataFields() as $group => $fields) {
        $md .= "## {$group}\n\n| Feld | Beschreibung |\n|---|---|\n";
        foreach ($fields as $name => $desc) { $md .= "| `{$name}` | {$desc} |\n"; }
        $md .= "\n";
    }
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="variablen-referenz.md"');
    echo $md;
});

$router->get('/admin/templates/variablen.csv', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="variablen-referenz.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kategorie', 'Vorlage/Gruppe', 'Name', 'Beschreibung']);
    foreach (adminFileRegistry() as $filename => $info) {
        if ($info['type'] !== 'tex') continue;
        foreach (adminFileVariables()[$filename] ?? [] as $name => $desc) {
            fputcsv($out, ['Platzhalter', $info['label'], "<<<{$name}>>>", $desc]);
        }
        foreach (adminFileAssets()[$filename] ?? [] as $name => $desc) {
            fputcsv($out, ['Bild-Asset', $info['label'], $name, $desc]);
        }
    }
    foreach (availableDataFields() as $group => $fields) {
        foreach ($fields as $name => $desc) {
            fputcsv($out, ['Verfügbares Rohdatenfeld', $group, $name, $desc]);
        }
    }
    fclose($out);
});

$router->get('/admin/templates/:name/download', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $registry = adminFileRegistry();
    if (!array_key_exists($params['name'], $registry)) { http_response_code(404); echo 'Unbekannte Datei'; return; }
    $path = adminFilePath($params['name']);
    if (!$path) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    $contentTypes = ['pdf' => 'application/pdf', 'image' => 'image/png', 'tex' => 'text/plain; charset=UTF-8'];
    header('Content-Type: ' . $contentTypes[$registry[$params['name']]['type']]);
    header('Content-Disposition: attachment; filename="' . $params['name'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

$router->post('/admin/templates/:name/upload', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $registry = adminFileRegistry();
    if (!array_key_exists($params['name'], $registry)) { http_response_code(404); echo 'Unbekannte Datei'; return; }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /admin/templates?error=' . urlencode('Datei-Upload fehlgeschlagen.'));
        exit;
    }
    // Grobe Plausibilitätsprüfung -- keine strikte LaTeX-/PDF-Validierung, ein Fehler zeigt
    // sich ohnehin sofort beim nächsten Aufruf (streamLatexPdf() liefert dann die
    // pdflatex-Fehlermeldung statt eines PDFs; ein kaputtes Infoblatt-PDF zeigt der Browser an).
    if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
        header('Location: /admin/templates?error=' . urlencode('Datei zu groß (max. 10 MB).'));
        exit;
    }
    // Logos werden direkt (ohne pdflatex/PDF-Viewer als "Fehler zeigt sich später von selbst")
    // in jede Seiten-Kopfzeile eingebettet -- hier lohnt sich eine echte Bildvalidierung, damit
    // keine beliebige Datei mit .png-Namen ausgeliefert wird.
    if ($registry[$params['name']]['type'] === 'image' && @getimagesize($_FILES['file']['tmp_name']) === false) {
        header('Location: /admin/templates?error=' . urlencode('Datei ist kein gültiges Bild.'));
        exit;
    }

    @mkdir('/var/www/html/latex-templates', 0775, true);
    $target = '/var/www/html/latex-templates/' . $params['name'];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        header('Location: /admin/templates?error=' . urlencode('Datei konnte nicht gespeichert werden.'));
        exit;
    }
    // entity_id ist in audit_log als UUID typisiert -- der Dateiname passt dort nicht rein,
    // steht stattdessen in der Beschreibung.
    logAudit(null, 'template.upload', 'admin_file', null,
        'Datei "' . $registry[$params['name']]['label'] . '" (' . $params['name'] . ') hochgeladen/ersetzt');
    header('Location: /admin/templates?success=' . urlencode($registry[$params['name']]['label'] . ' wurde aktualisiert.'));
    exit;
});

/**
 * Öffentlicher Infoblatt-Download für die Marketing-Seite: bevorzugt eine über
 * /admin/templates hochgeladene Fassung, sonst die mitgelieferte Standard-PDF (siehe
 * adminFilePath()) -- dieselbe Fallback-Logik wie bei den LaTeX-Vorlagen.
 */
$router->get('/infoblatt.pdf', function () {
    $path = adminFilePath('infoblatt.pdf');
    if (!$path) { http_response_code(404); echo 'Infoblatt nicht gefunden'; return; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="infoblatt-eeg-strompool-feldkirchen-suedwest.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

/**
 * Öffentliches Logo (Header, Light-/Dark-Mode getrennt): bevorzugt eine über
 * /admin/templates hochgeladene Fassung, sonst die mitgelieferte Standard-Grafik --
 * dieselbe Fallback-Logik wie beim Infoblatt. Wird von base.php/portal.php per <img>
 * eingebunden und per CSS ([data-theme="dark"]) je nach Theme ein-/ausgeblendet.
 */
$router->get('/logo-:variant.png', function ($params) {
    if (!in_array($params['variant'], ['light', 'dark'], true)) { http_response_code(404); return; }
    $path = adminFilePath('logo-' . $params['variant'] . '.png');
    if (!$path) { http_response_code(404); return; }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

/**
 * Eigenes Hero-Banner-Foto (statt der mitgelieferten SVG-Landschafts-Illustration) --
 * hochgeladen unter /admin/templates, dort mit Zoom/Verschieben auf die Ziel-Bildgröße des
 * Hero-Banners zugeschnitten (siehe rect-crop.js). 404, solange kein eigenes Bild hochgeladen
 * wurde -- home.php prüft das vorab und bindet die SVG-Illustration dann ganz normal weiter ein.
 */
$router->get('/hero-banner-image', function () {
    $path = adminFilePath('hero-banner.png');
    if (!$path) { http_response_code(404); return; }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

$router->dispatch();
