<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
// Alle Anzeigen (Portal, Admin, PDF, E-Mails) in Wiener Ortszeit -- die DB speichert UTC
// (TIMESTAMPTZ). Steht zusätzlich in docker/php.ini; hier explizit, damit es nicht davon abhängt,
// dass die ini-Datei geladen wurde (Zeitzonen-Vorfall 07.11.2026, siehe fmtVienna()).
date_default_timezone_set('Europe/Vienna');

// Neue Klassen im Namensraum App\ (src/Http, src/Domain, ...) per PSR-4-Autoloader.
require ROOT . '/src/autoload.php';
foreach (['AppConfig', 'DB', 'Auth', 'RateLimiter', 'LiveStatsCache', 'AppApiAuth', 'Router', 'Billing', 'Mailer', 'GraphMailReader', 'EdaParserRunner', 'EdaAutoImporter', 'Push', 'WebAuthn'] as $class) {
    require ROOT . '/src/' . $class . '.php';
}
// Reine Hilfsfunktionen (validateIban, texEscape, rechnung*Latex ...) -- ausgelagert, damit
// sie ohne HTTP-/Router-Kontext testbar sind (tests/).
require ROOT . '/src/functions.php';
// Einzige Quelle der Zustimmungstexte der Beitrittserklärung (siehe Dateikopf).
require ROOT . '/src/consent_texts.php';
require ROOT . '/src/content/marketing_pages.php';

sendCspHeader(); // vor jeder Ausgabe; Routen mit eigener Policy (Dateiauslieferung) überschreiben sie
Auth::start();

/**
 * Sicherheitsnetz gegen rohe 500er ohne jede Auskunft: jeder unbehandelte Fehler wird
 * serverseitig mit einer kurzen Fehlernummer geloggt (docker compose logs webapp | grep <nummer>),
 * die Seite zeigt nur diese Nummer. Die Fehlermeldung selbst kann SQL, Pfade oder Daten enthalten
 * und erscheint deshalb nur bei APP_ENV=development oder ausdrücklich gesetztem APP_DEBUG=1.
 */
function renderFatalErrorPage(string $message, string $ref): void
{
    if (headers_sent()) { return; }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>';
    echo '<div style="font-family:sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">';
    echo '<h2>Es ist ein unerwarteter Fehler aufgetreten.</h2>';
    echo '<p style="color:#6b7280;font-size:.9rem">Fehlernummer: <code>' . htmlspecialchars($ref) . '</code>'
        . ' &ndash; bitte bei einer Rückfrage angeben.</p>';
    if (!AppConfig::get()->production || getenv('APP_DEBUG') === '1') {
        echo '<p style="color:#6b7280;font-size:.9rem">Technische Details: <code>' . htmlspecialchars($message) . '</code></p>';
    }
    echo '<p><a href="/">Zur Startseite</a></p></div>';
}

set_exception_handler(function (\Throwable $e) {
    $ref = bin2hex(random_bytes(4));
    error_log('[unhandled ' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    renderFatalErrorPage($e->getMessage(), $ref);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $ref = bin2hex(random_bytes(4));
        error_log('[fatal ' . $ref . '] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        renderFatalErrorPage($err['message'], $ref);
    }
});

// Abgleich Portal -> Keycloak (docs/adr/0007-keycloak.md): nach der Antwort an den Browser die
// Warteschlange abarbeiten (Trigger in migrate_20261121.sql), damit z. B. ein geändertes Passwort sofort
// auch bei Keycloak gilt. Ohne eingerichtetes Keycloak passiert nichts (kein DB-Zugriff).
register_shutdown_function(function () {
    if (!getenv('KC_PORTAL_CLIENT_SECRET')) { return; }
    // Erst die Sitzung speichern, dann die Antwort abschließen: sonst könnte der Browser der nächsten
    // Weiterleitung (z. B. nach der Anmeldung) folgen, bevor die Sitzung geschrieben ist.
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    \App\Infrastructure\Auth\KeycloakSync::afterRequest();
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
    return AppConfig::get()->portalUrl($path, (string)($_SERVER['HTTP_HOST'] ?? ''));
}

/**
 * Baut einen Link zu einer öffentlichen Marketing-Seite, der immer auf der Hauptdomain landet --
 * Gegenstück zu portalUrl() für Links aus dem Backoffice heraus (Logo, "Startseite"-Links).
 */
function marketingUrl(string $path): string
{
    return AppConfig::get()->marketingUrl($path, (string)($_SERVER['HTTP_HOST'] ?? ''));
}

/**
 * Baut den absoluten Link für Passwort-Reset-/Erstlogin-E-Mails. Fix auf die portal-Subdomain
 * verdrahtet (nicht die aufrufende Host-Kopfzeile), weil E-Mails immer denselben Link liefern
 * sollen, unabhängig davon von welcher Domain aus der Manager die Aktion auslöst.
 */
function passwordResetLink(string $token): string
{
    return AppConfig::get()->portalBaseUrl() . '/portal/reset-password?token=' . urlencode($token);
}

/**
 * Redirect NACH einem Datei-Upload (multipart/form-data-POST), bewusst OHNE HTTP-3xx-Status --
 * Safari/WebKit bricht einen größeren Datei-Upload sonst manchmal mit
 * "NSURLErrorDomain -1021: request body stream exhausted" ab (Patrick, 24.08.2026: reproduzierbar
 * beim Logo-Upload unter /admin/templates). Ein bekannter, seit Jahren dokumentierter WebKit-Bug:
 * bekommt Safari als Antwort auf einen großen multipart-POST einen klassischen 3xx-Redirect
 * (`header('Location: ...')`), versucht es intern den ursprünglichen Request erneut zu stellen --
 * der dafür nötige Datei-Stream aus dem <input type="file"> wurde beim ersten Senden aber bereits
 * vollständig verbraucht und lässt sich nicht zurückspulen, der Upload schlägt fehl BEVOR die
 * Datei überhaupt beim Server ankommt (in `move_uploaded_file()` bereits geschriebene Dateien
 * sind davon nicht betroffen -- das Problem tritt schon auf Netzwerkebene auf, nicht in PHP).
 * Fix: 200 OK statt 3xx zurückgeben (kein "Redirect", den der Browser nachvollziehen müsste),
 * die eigentliche Weiterleitung übernimmt eine bereits vollständig ausgelieferte, winzige
 * HTML-Seite selbst (Meta-Refresh + JS als Fallback) -- funktioniert in jedem Browser identisch,
 * behebt aber gezielt das Safari-spezifische Nachsende-Problem. Nur für POST-Handler mit
 * Datei-Upload gedacht, nicht als genereller Ersatz für normale 302-Redirects nach POST.
 */
function uploadRedirect(string $location): void
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($location) . '">'
        . '<script nonce="' . cspNonce() . '">location.replace(' . json_encode($location) . ');</script></head>'
        . '<body>Wird weitergeleitet …</body></html>';
    exit;
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
            'SELECT salutation, titel_vor, first_name, last_name, company_name, email_anrede_mode
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
 * Portal-Design der ganzen Plattform: 'klassisch' (bisher) oder 'glas' (neuer Look nach Video-
 * Vorbild, Patrick 13.11.2026) -- Platform-Admin -> Einstellungen -> Design. Fehlt die Spalte
 * (Migration noch nicht eingespielt), bleibt alles beim klassischen Design.
 */
function platformDesign(): string
{
    static $design = null;
    if ($design === null) {
        try {
            $design = (string)(DB::fetchOne('SELECT portal_design FROM platform_settings WHERE id = 1')['portal_design'] ?? 'klassisch');
        } catch (\Throwable $e) {
            $design = 'klassisch';
        }
        if (!in_array($design, ['klassisch', 'glas'], true)) $design = 'klassisch';
    }
    return $design;
}

/** Design umstellen (Web-Formular und App-API). */
function savePlatformDesign(string $design, string $via): bool
{
    if (!in_array($design, ['klassisch', 'glas'], true)) return false;
    DB::execute('UPDATE platform_settings SET portal_design = ?, updated_at = now() WHERE id = 1', [$design]);
    logAudit(null, 'platform_settings.update', 'platform_settings', null,
        'Portal-Design' . $via . ' auf „' . ($design === 'glas' ? 'Neuer Look (Glas)' : 'Klassisch') . '“ umgestellt');
    return true;
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
    //
    // mp.type-Filter (Patrick, 01.11.2026, am Beispiel seines Vaters -- reiner Einspeiser,
    // kein Bezugsvertrag mit der EEG, bezieht seinen eigenen Strom ganz normal und direkt von
    // der Kelag): ein bidirektionaler Smart-Meter misst an einem reinen Einspeise-Zählpunkt
    // (type = 'producer') trotzdem echte power_bezug_w-Werte, sobald das Haus mehr verbraucht
    // als die PV gerade liefert -- das ist aber privater Bezug von dessen Energieversorger, NIE
    // über die Energiegemeinschaft. Ohne diesen Filter floss dieser Bezug in JEDE
    // Community-weite Live-Summe ein (Dashboard, öffentliche Live-Anzeige, API) und suggerierte
    // fälschlich, er würde über die EEG laufen. Exakt dieselbe Unterscheidung wie in
    // Billing::generateDrafts() (type IN ('consumer','prosumer') für Bezug, IN ('producer',
    // 'prosumer') für Einspeisung) -- dort schon immer korrekt, hier bei der reinen
    // Live-Anzeige bisher gefehlt. Die Abrechnung selbst war von diesem Bug nie betroffen.
    $row = DB::fetchOne(
        "SELECT COALESCE(SUM(CASE WHEN mp_type IN ('producer','prosumer') THEN power_einspeisung_w ELSE 0 END),0) AS einsp_w,
                COALESCE(SUM(CASE WHEN mp_type IN ('consumer','prosumer') THEN power_bezug_w ELSE 0 END),0) AS bezug_w,
                COUNT(*) AS active_meters
         FROM (
            SELECT DISTINCT ON (em.metering_point_id) em.power_einspeisung_w, em.power_bezug_w, mp.type AS mp_type
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
 * Ob für diese EEG die Messe-/Präsentations-Demo (siehe mqtt-subscriber/main.py,
 * demo_simulation_loop()) aktiv ist -- Platform-Admin/Obmann-Einstellungen -> "Simulierte Werte
 * anzeigen". Wird von jeder Stelle abgefragt, die die Community-weite Live-Summe zeigt (siehe
 * energy_flow.php, /api/live/:slug), um dort einen deutlichen Hinweis einzublenden, dass ein
 * Teil der angezeigten Werte fiktiv ist -- Patrick, 02.10.2026: "mach mir aber bei dieser
 * Anzeige dann einen Hinweis [...] dass diese Werte fiktive Werte sind für die Veranschaulichung
 * der späteren Webseite [...] und diese jetzt noch nicht stimmen."
 */
function communityMesseDemoEnabled(string $communityId): bool
{
    return (bool)(DB::fetchOne('SELECT messe_demo_enabled FROM communities WHERE id = ?', [$communityId])['messe_demo_enabled'] ?? false);
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
/**
 * Sendet eine hochgeladene Bilddatei sicher aus: nur echte Bildtypen (JPEG/PNG/WebP, am INHALT
 * erkannt, nicht an der Endung) werden als Bild ausgeliefert, alles andere als Download mit
 * gesperrter Ausführung. Vorher kam der Dateityp direkt aus mime_content_type(): eine als
 * "foto.jpg" hochgeladene HTML-Datei wäre als text/html im Portal ausgeliefert worden und hätte
 * -- wegen 'unsafe-inline' in der CSP -- Skripte im Portal ausführen können, sobald jemand die
 * Adresse direkt öffnet.
 */
function sendImageFileSafely(string $path): void
{
    $mime = @mime_content_type($path) ?: '';
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        header('Content-Type: ' . $mime);
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="datei"');
    }
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('X-Content-Type-Options: nosniff');
}

function storeAvatarFile(string $key, array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) { return ['path' => null, 'error' => 'upload']; }
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) { return ['path' => null, 'error' => 'phototype']; }
    // Die Endung allein sagt nichts: der Inhalt muss tatsächlich ein JPEG/PNG/WebP-Bild sein.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ['path' => null, 'error' => 'phototype'];
    }

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

// extractOrtFromAddress und texEscape nach src/functions.php ausgelagert (testbar).

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
 * Anhebung für \floatsig (siehe LaTeX-Vorlagen), damit die im Browser sichtbare Führungslinie
 * der Unterschrift-Canvas (assets/js/signature-pad-trim.js: SIGNATURE_GUIDE_Y=130,
 * SIGNATURE_ABOVE_PX=95, SIGNATURE_BELOW_PX=35 -- fester Zuschnitt von 130px Höhe relativ zur
 * Führungslinie) exakt auf der gedruckten Linie landet, statt das ganze Bild nur grob mittig
 * zu zentrieren. Patrick, 04.10.2026: "wenn man unter die Linie kommt, ist es noch unter der
 * Linie" -- der Anteil UNTERHALB der Führungslinie (35 von 130px = ~26,9 %) wird deshalb genau
 * auf diesen Anteil der tatsächlichen Bildhöhe in der PDF umgerechnet, nicht pauschal angehoben.
 * Ändert sich eine der drei JS-Konstanten, muss dieser Faktor (35/130) hier mitgezogen werden.
 *
 * Vorzeichen-Korrektur (05.10.2026): \includegraphics setzt die UNTERKANTE des Bildes auf die
 * aktuelle Grundlinie (dieselbe Grundlinie, auf der auch \rule steht) -- die Führungslinie liegt
 * innerhalb des Bildes aber ein Stück OBERHALB dieser Unterkante. Ohne jeden Versatz schwebt die
 * Führungslinie deshalb bereits von sich aus über der gedruckten Linie; \raisebox muss das Bild
 * folglich nach UNTEN ziehen (negativer Wert), nicht zusätzlich anheben. Die vorige Fassung
 * dieser Funktion gab einen POSITIVEN Wert zurück und hat den Versatz dadurch verdoppelt, statt
 * ihn aufzuheben -- das war die eigentliche Ursache dafür, dass die Unterschrift in jeder bis-
 * herigen Testrunde zu hoch über der Linie saß (per lokalem \floatsig-Test mit einer farbig
 * markierten Führungslinie empirisch verifiziert, siehe docs/VORFAELLE.md).
 *
 * $offsetCm: zusätzliche manuelle Fein-Korrektur je EEG (communities.signature_offset_cm, siehe
 * Obmann-Einstellungen) -- positiv hebt die Unterschrift zusätzlich an, negativ senkt sie ab.
 * Patrick, 05.10.2026: "Gib jedem Obmann die Möglichkeit, das Unterschriftsfeld selbst auf der
 * Beitrittserklärung zu platzieren." Wird zum automatisch berechneten Versatz addiert, ist also
 * eine reine Fein-Nachkorrektur oben drauf, kein Ersatz für die automatische Berechnung.
 */
function signatureRaise(float $heightCm, float $offsetCm = 0.0): string
{
    return round(-1 * $heightCm * 35 / 130 + $offsetCm, 2) . 'cm';
}

/**
 * Manuelle Fein-Korrektur der Unterschrift-Position für eine EEG (siehe signatureRaise()).
 * Statisch zwischengespeichert, weil pro erzeugter PDF mehrmals abgefragt (SEPA + Haupt-
 * unterschrift, ggf. zusätzlich Vertrags-Unterschriften).
 */
function communitySignatureOffsetCm(string $communityId): float
{
    static $cache = [];
    if (!array_key_exists($communityId, $cache)) {
        $cache[$communityId] = (float)(
            DB::fetchOne('SELECT signature_offset_cm FROM communities WHERE id = ?', [$communityId])['signature_offset_cm'] ?? 0
        );
    }
    return $cache[$communityId];
}

/**
 * Wie communitySignatureOffsetCm(), aber für die horizontale Fein-Korrektur (links/rechts).
 * Patrick, 05.10.2026: "Was ich aber dort noch gern hätte, ist auch ein Links- und
 * Rechtsverschieben, bitte." -- positiv verschiebt nach rechts, negativ nach links.
 */
function communitySignatureOffsetXCm(string $communityId): float
{
    static $cache = [];
    if (!array_key_exists($communityId, $cache)) {
        $cache[$communityId] = (float)(
            DB::fetchOne('SELECT signature_offset_x_cm FROM communities WHERE id = ?', [$communityId])['signature_offset_x_cm'] ?? 0
        );
    }
    return $cache[$communityId];
}

/**
 * Erste \floatsig-Zahl (die "halbe Linienbreite", siehe Makro-Kommentar in den .tex-Vorlagen):
 * \floatsig verschiebt den Ankerpunkt für die zentrierte Unterschrift um genau diesen Wert nach
 * rechts, zentriert dort das Bild, und verschiebt danach um denselben Wert zurück nach links --
 * dadurch bleibt die nachfolgende \rule unbeeinflusst, ganz gleich, welcher Wert hier steht.
 * Ein horizontaler Versatz lässt sich deshalb OHNE jede Änderung am \floatsig-Makro selbst
 * einbauen: einfach zum Basiswert (halbe Linienbreite) addieren -- ein positiver Versatz
 * verschiebt den Anker (und damit die Unterschrift) weiter nach rechts, ein negativer weiter
 * nach links, exakt symmetrisch in beide \floatsig-Verschiebungen.
 */
function floatsigHalfWidth(float $baseHalfWidthCm, float $offsetXCm): string
{
    return round($baseHalfWidthCm + $offsetXCm, 2) . 'cm';
}

/**
 * Liefert die RAW_-Variable fürs Unterschriftsbild "Für die EEG" sowie das
 * zugehörige Bild-Asset für den angegebenen User (Default: der aktuell eingeloggte, i.d.R.
 * der Obmann/die Obfrau, der/die den Vertrag gerade erzeugt). Ohne hinterlegte Unterschrift
 * bleibt die Zeile leer (nur die Unterschriftslinie). $communityId nur nötig, falls er nicht aus
 * Auth::activeCommunityId() übernommen werden soll (siehe communityManagerSignature()).
 */
function eegSignatureAsset(?string $userId = null, ?string $communityId = null): array
{
    $user = DB::fetchOne('SELECT signature_image FROM users WHERE id = ?', [$userId ?? Auth::userId()]);
    if (empty($user['signature_image'])) {
        return ['var' => '', 'assets' => []];
    }
    $cid = $communityId ?? Auth::activeCommunityId();
    $offset = communitySignatureOffsetCm($cid);
    $offsetX = communitySignatureOffsetXCm($cid);
    return [
        'var'    => '\\floatsig{' . floatsigHalfWidth(2.5, $offsetX) . '}{' . signatureRaise(1.4, $offset) . '}{\\includegraphics[height=1.4cm]{unterschrift_eeg.png}}',
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
    return eegSignatureAsset($row['id'], $communityId);
}

/**
 * Wie eegSignatureAsset(), aber fürs Mitglied selbst: bekommt die Unterschrift direkt übergeben
 * (aus members.contract_{type}_customer_signature), statt sie per DB-Lookup über einen User zu
 * holen -- das Mitglied unterschreibt digital im Portal (siehe /portal/my/contract/:type/sign),
 * nicht über das Manager-Unterschriftsfeld in den Einstellungen.
 */
function memberSignatureAsset(?string $dataUri, string $communityId = ''): array
{
    if (empty($dataUri)) {
        return ['var' => '', 'assets' => []];
    }
    $offset = $communityId !== '' ? communitySignatureOffsetCm($communityId) : 0.0;
    $offsetX = $communityId !== '' ? communitySignatureOffsetXCm($communityId) : 0.0;
    return [
        'var'    => '\\floatsig{' . floatsigHalfWidth(2.5, $offsetX) . '}{' . signatureRaise(1.4, $offset) . '}{\\includegraphics[height=1.4cm]{unterschrift_mitglied.png}}',
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
 * Demo-Login: Datei-Downloads sind ausnahmslos verboten (Patrick, 23.08.2026: "Die Dateien
 * dürfen nie, in gar keinem Fall, irgendwie installiert oder heruntergeladen werden können. Ich
 * würde da voll gegen das Datenschutzrecht verstoßen."). Gilt für JEDE Datei -- Mitglieder-
 * Uploads, Beitrittserklärungen, Bezugs-/Einspeisevereinbarungen, Rechnungen, LaTeX-Vorlagen/
 * Logos --, unabhängig davon, ob die konkrete Datei technisch zu einem echten oder einem
 * fiktiven Demo-Mitglied gehört (letzteres wäre zwar unbedenklich, aber eine Ausnahme dafür
 * würde die Regel unnötig verkomplizieren). Browsen/Hineinklicken in Datei-LISTEN bleibt erlaubt
 * ("Hineinklicken wäre nämlich schon cool zu können") -- nur der eigentliche Dateitransfer wird
 * hier zentral geblockt, direkt am Anfang jeder Download-Route, bevor eine Datei gelesen oder
 * ein PDF erzeugt wird (manche Routen aktualisieren dabei sonst auch noch einen Status, siehe
 * /portal/members/:id/contract/bezug -- der frühe return verhindert auch das).
 */
function denyDemoFileDownload(): void
{
    denyDemoPage(
        'Dieser Demo-Zugang dient nur zur Ansicht. Aus Datenschutzgründen können '
        . 'über diesen Zugang keine Dateien heruntergeladen werden (Verträge, '
        . 'Beitrittserklärungen, Rechnungen, Vorlagen, Fotos, ...).'
    );
}

/**
 * Generischer Seiten-Block für den Demo-Zugang -- zeigt die "Nur Lesezugriff"-Seite mit
 * $message statt der Zielseite. Für Seiten, bei denen Maskieren nicht reicht/nicht sinnvoll
 * ist (z.B. ein Bearbeiten-Formular, das echte Werte in vorbefüllte Eingabefelder schreibt --
 * Patrick, 24.08.2026: "/members/<id>/edit darf nicht verfügbar sein"), statt einer
 * spaltenweisen demoMask*()-Funktion.
 */
function denyDemoPage(string $message): void
{
    if (!Auth::isDemo()) return;
    http_response_code(403);
    $demoErrorMessage = $message;
    require ROOT . '/src/views/pages/demo_readonly_error.php';
    exit;
}

/** JSON-Äquivalent von denyDemoFileDownload() für die App-API (Bearer-Token statt Session) --
 *  $ctx kommt von AppApiAuth::requireAppAuth(), das seit demselben Update immer 'is_demo' liefert. */
function denyDemoApiFileDownload(array $ctx): bool
{
    if (empty($ctx['is_demo'])) return false;
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(403);
    echo json_encode(['error' => 'Demo-Zugang: Datei-Downloads sind aus Datenschutzgründen nicht möglich.']);
    return true;
}

/**
 * Legt ein Mitglied inkl. Login-User und Rolle an. Wird sowohl von der manuellen
 * Mitglieder-Anlage (/portal/members) als auch von der Freigabe einer Online-
 * Beitrittserklärung (/portal/applications/:id/approve) verwendet, damit KdNr-
 * Vergabe (Lücken-Auffüllung) und Mandatsreferenz-Logik nicht doppelt gepflegt werden.
 * Erwartet in $f Schlüssel wie die Spalten der members-Tabelle (salutation, titel_vor, …).
 * Gibt ['member_id', 'user_id', 'kundennummer', 'temp_password' (oder null),
 * 'invite_sent' (bool), 'invite_error' (string oder null)] zurück.
 */
function createMemberRecord(string $communityId, array $f): array
{
    loadCommunityConsentVersions($communityId);
    $email = strtolower(trim($f['email']));
    $user = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    $tempPw = null;
    $inviteSent = false;
    $inviteError = null;
    if (!$user) {
        $tempPw = bin2hex(random_bytes(8));
        $hash = hashPassword($tempPw);
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
            community_id, user_id, salutation, titel_vor, titel_nach, first_name, last_name, company_name,
            address, zip, city, email, phone, invoice_uid, member_iban, member_bic,
            kontoinhaber, konto_adresse,
            member_since, member_until, kundennummer, mandatsreferenz, beitrittsdatum,
            mandat_unterschrift_datum,
            geburtsdatum, stromlieferant, speicher_status, speicher_kwh, andere_eeg, andere_eeg_name,
            zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb, consent_version,
            email_anrede_mode
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $communityId,
            $user['id'],
            $f['salutation'] ?? null,
            trim($f['titel_vor'] ?? '') ?: null,
            trim($f['titel_nach'] ?? '') ?: null,
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
            ($f['mandat_unterschrift_datum'] ?? '') ?: null,
            ($f['geburtsdatum'] ?? '') ?: null,
            trim($f['stromlieferant'] ?? '') ?: null,
            ($f['speicher_status'] ?? '') ?: null,
            ($f['speicher_kwh'] ?? '') !== '' && ($f['speicher_kwh'] ?? null) !== null ? (float)$f['speicher_kwh'] : null,
            !empty($f['andere_eeg']) ? 'true' : 'false',
            trim($f['andere_eeg_name'] ?? '') ?: null,
            'true', 'true', 'true', 'true', 'true', 'true',
            // Welche Fassung der Zustimmungen gilt (consent_texts.php): bei der Freigabe eines
            // Online-Antrags die dort akzeptierte, sonst (Papierformular, App) die aktuelle.
            consentVersion($f['consent_version'] ?? null) !== null ? $f['consent_version'] : consentCurrentVersionFor(communityById($communityId)),
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
// /logo-light.png und /logo-dark.png sind domain-unabhängige geteilte Assets -- base.php UND
// portal.php binden beide Varianten ein (siehe logoAssetUrl()). Ohne diese Ausnahme leitet die
// Domain-Trennung einen Bildaufruf von portal.stromfueralle.at auf die Hauptdomain um (3xx) --
// die eigene CSP (img-src 'self') verbietet dem Browser aber, einer domainübergreifenden
// Bild-Weiterleitung zu folgen, das Logo bleibt dadurch auf dem Portal unsichtbar (Vorfall
// 25.08.2026: erst durch Entfernen zweier fest eingecheckter Platzhalter-Dateien in public/
// sichtbar geworden, siehe "Bekannte Probleme" -- vorher fing nginx den Request als echte Datei
// ab, bevor diese Weiterleitungslogik überhaupt lief).
$isSharedAsset = $requestPath === '/logo-light.png' || $requestPath === '/logo-dark.png';

if (AppConfig::get()->isPortalHost($requestHost) && !$isBackofficePath && !$isSharedAsset) {
    header('Location: ' . AppConfig::get()->marketingBaseUrl() . $_SERVER['REQUEST_URI']);
    exit;
}
if (AppConfig::get()->isMarketingHost($requestHost) && $isBackofficePath) {
    header('Location: ' . AppConfig::get()->portalBaseUrl() . $_SERVER['REQUEST_URI']);
    exit;
}

$router = new Router();

// Routen nach Bereich (webapp/routes/). Die Reihenfolge ist Teil des Verhaltens: die erste
// passende Route gewinnt, die öffentlichen /:communityid/...-Muster müssen zuerst kommen.
foreach (['public', 'portal', 'admin', 'api'] as $routeFile) {
    require ROOT . '/routes/' . $routeFile . '.php';
}

$router->dispatch();
