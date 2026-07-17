<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

foreach (['DB', 'Auth', 'Router', 'Billing', 'Mailer'] as $class) {
    require ROOT . '/src/' . $class . '.php';
}

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

/**
 * Prüft eine IBAN per Mod-97-Verfahren (ISO 7064). Erwartet die IBAN ohne
 * Leerzeichen/Kleinbuchstaben-Normalisierung durch den Aufrufer.
 */
function validateIban(string $iban): bool
{
    $iban = strtoupper(str_replace(' ', '', $iban));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
    }
    // Mod-97 blockweise ohne bcmath: Rest + max. 7 neue Ziffern bleibt immer < PHP_INT_MAX
    $remainder = 0;
    for ($offset = 0; $offset < strlen($numeric); $offset += 7) {
        $remainder = (int)((string)$remainder . substr($numeric, $offset, 7)) % 97;
    }
    return $remainder === 1;
}

/**
 * Prüft eine österreichische Zählpunktnummer: "AT" + 31 alphanumerische
 * Stellen = 33 Zeichen gesamt.
 */
function validateZaehlpunkt(string $zp): bool
{
    return (bool)preg_match('/^AT[A-Z0-9]{31}$/', strtoupper(trim($zp)));
}

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
 * Lädt eine System-Mail-Vorlage (Betreff + HTML-Body) aus platform_mail_templates und ersetzt
 * {{platzhalter}} durch $vars. Fällt auf den mitgegebenen Standardtext zurück, falls im
 * Platform-Admin noch keine eigene Vorlage gespeichert wurde (z.B. direkt nach der Migration).
 */
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
 * Lädt ein Mitglied anhand der ID community-übergreifend und prüft den Zugriff: Platform-Admins
 * dürfen jedes Mitglied verwalten, Manager nur die der eigenen aktiven Rolle (IDOR-Schutz).
 * Setzt bei Erfolg gleich die RLS-Community auf die des MITGLIEDS (nicht die der gerade aktiven
 * Rolle) -- wichtig, damit ein Platform-Admin ein Mitglied einer anderen EEG als der eigenen
 * aktiven bearbeiten kann, ohne vorher extra die Rolle wechseln zu müssen. Sendet bei fehlendem
 * Zugriff direkt die passende HTTP-Antwort und gibt null zurück.
 */
function requireMemberAccess(string $memberId): ?array
{
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$memberId]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return null; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return null;
    }
    DB::setCommunity($member['community_id']);
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

/**
 * Escaped einen String für sichere Verwendung in LaTeX-Zellwerten.
 * Nicht für RAW_-Variablen verwenden (die enthalten bereits LaTeX-Syntax).
 */
function texEscape(string $s): string
{
    return strtr($s, [
        '\\' => '\\textbackslash{}',
        '&'  => '\\&',
        '%'  => '\\%',
        '$'  => '\\$',
        '#'  => '\\#',
        '_'  => '\\_',
        '{'  => '\\{',
        '}'  => '\\}',
        '~'  => '\\textasciitilde{}',
        '^'  => '\\textasciicircum{}',
        '—'  => '--',
        '–'  => '--',
    ]);
}

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
                $mail = renderMailTemplate('invite', [
                    'vorname'     => htmlspecialchars(trim($f['first_name'])),
                    'link'        => $link,
                    'gueltigkeit' => '24 Stunden',
                ],
                    'Willkommen bei Strom für alle – Zugang einrichten',
                    '<p>Hallo {{vorname}},</p>'
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
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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

    $iban = trim($_POST['member_iban'] ?? '');
    if ($iban !== '' && !validateIban($iban)) {
        $error = 'Die eingegebene IBAN ist ungültig (Prüfsumme stimmt nicht).';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
    }
    if ($iban !== '' && trim($_POST['kontoinhaber'] ?? '') === '') {
        $error = 'Bitte bei Bankverbindung den vollen Namen des Kontoinhabers/der Kontoinhaberin angeben.';
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
    if ($iban !== '' && !str_starts_with($sepaSignature, 'data:image/png;base64,')) {
        $error = 'Bitte unterschreiben Sie zusätzlich das SEPA-Lastschriftmandat, da Sie eine IBAN angegeben haben.';
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
            trim($_POST['bezug_zaehlpunkt'] ?? '') ?: null,
            ($_POST['bezug_jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['bezug_jahresverbrauch_kwh']) : null,
            isset($_POST['einspeisung_gewuenscht']) ? 'true' : 'false',
            trim($_POST['einspeisung_zaehlpunkt'] ?? '') ?: null,
            ($_POST['einspeisung_kwp'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['einspeisung_kwp']) : null,
            ($_POST['einspeisung_geplante_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['einspeisung_geplante_kwh']) : null,
            ($_POST['speicher_status'] ?? '') ?: null,
            ($_POST['speicher_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['speicher_kwh']) : null,
            isset($_POST['andere_eeg']) ? 'true' : 'false',
            trim($_POST['andere_eeg_name'] ?? '') ?: null,
            $iban ?: null,
            trim($_POST['member_bic'] ?? '') ?: null,
            trim($_POST['kontoinhaber'] ?? '') ?: null,
            trim($_POST['konto_adresse'] ?? '') ?: null,
            'true', 'true', 'true', 'true', 'true', 'true',
            $signature,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $iban !== '' ? $sepaSignature : null,
            $iban !== '' ? date('Y-m-d H:i:s') : null,
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

    $agg = DB::fetchOne(
        "SELECT
            COALESCE(SUM(power_bezug_w), 0)        AS total_bezug_w,
            COALESCE(SUM(power_einspeisung_w), 0)  AS total_einspeisung_w,
            COUNT(DISTINCT metering_point_id)       AS active_meters
         FROM esp_measurements
         WHERE community_id = ? AND time >= now() - INTERVAL '2 minutes'",
        [$community['id']]
    );

    $today = DB::fetchOne(
        "SELECT COALESCE(SUM(energy_einspeisung_wh), 0) AS today_wh
         FROM esp_measurements
         WHERE community_id = ? AND time >= CURRENT_DATE
         ORDER BY time DESC LIMIT 1",
        [$community['id']]
    );

    $series = DB::fetchAll(
        "SELECT
            time_bucket('5 minutes', time) AS bucket,
            SUM(power_bezug_w)             AS bezug_w,
            SUM(power_einspeisung_w)       AS einspeisung_w
         FROM esp_measurements
         WHERE community_id = ? AND time >= now() - INTERVAL '2 hours'
         GROUP BY bucket ORDER BY bucket",
        [$community['id']]
    );

    $bezug = (int)($agg['total_bezug_w'] ?? 0);
    $einsp = (int)($agg['total_einspeisung_w'] ?? 0);
    $autarkie = $bezug > 0 ? min(100, round($einsp / $bezug * 100)) : 0;

    echo json_encode([
        'bezug_w'       => $bezug,
        'einspeisung_w' => $einsp,
        'autarkie_pct'  => $autarkie,
        'today_kwh'     => round(($today['today_wh'] ?? 0) / 1000, 2),
        'active_meters' => (int)($agg['active_meters'] ?? 0),
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
    if (Auth::login($email, $password)) {
        header('Location: /portal/dashboard');
    } else {
        $error = 'E-Mail oder Passwort falsch.';
        require ROOT . '/src/views/pages/login.php';
    }
    exit;
});

$router->get('/portal/logout', function () {
    Auth::logout();
    header('Location: /portal/login');
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
            $mail = renderMailTemplate('password_reset', [
                'vorname'     => htmlspecialchars($user['first_name'] ?? ''),
                'link'        => $link,
                'gueltigkeit' => 'Stunde',
            ],
                'Passwort zurücksetzen – Strom für alle',
                '<p>Hallo {{vorname}},</p>'
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
    Auth::resetPassword($token, $password);
    header('Location: /portal/login?success=password_reset');
    exit;
});

// ─── Portal: Dashboard ──────────────────────────────────
$router->get('/portal/dashboard', function () {
    Auth::requireLogin();
    if (Auth::isManager()) {
        require ROOT . '/src/views/pages/manager_dashboard.php';
    } else {
        require ROOT . '/src/views/pages/member_dashboard.php';
    }
});

$router->post('/portal/switch-role', function () {
    Auth::requireLogin();
    $communityId = $_POST['community_id'] ?? '';
    $role        = $_POST['role'] ?? '';
    Auth::switchRole($communityId, $role);
    header('Location: /portal/dashboard');
    exit;
});

// ─── Portal: Rechnungen (Mitglied) ──────────────────────
$router->get('/portal/invoices', function () {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $userId = Auth::userId();
    $member = DB::fetchOne('SELECT id FROM members WHERE user_id = ? AND community_id = ?', [$userId, $communityId]);
    if (!$member) { http_response_code(404); echo 'Mitglied nicht gefunden'; return; }
    $invoices = DB::fetchAll(
        'SELECT i.*, br.quartal FROM invoices i JOIN billing_runs br ON br.id = i.billing_run_id
         WHERE i.member_id = ? ORDER BY i.created_at DESC',
        [$member['id']]
    );
    require ROOT . '/src/views/pages/invoices.php';
});

$router->get('/portal/invoices/:id/pdf', function ($params) {
    Auth::requireLogin();
    $communityId = Auth::activeCommunityId();
    if ($communityId) DB::setCommunity($communityId);

    $invoice = DB::fetchOne(
        'SELECT i.*, m.first_name, m.last_name, m.address, m.zip, m.city, m.invoice_uid,
                m.community_id AS member_community_id, m.user_id AS member_user_id,
                br.quartal, br.period_from, br.period_to,
                c.name AS eeg_name, c.address AS eeg_address, c.iban AS eeg_iban, c.bic AS eeg_bic,
                tc.bezug_ct_kwh, tc.einspeisung_ct_kwh, tc.mitgliedsbeitrag_eur
         FROM invoices i
         JOIN members m ON m.id = i.member_id
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN communities c ON c.id = br.community_id
         LEFT JOIN tariff_config tc ON tc.community_id = c.id AND tc.valid_from <= br.period_from
         WHERE i.id = ?
         ORDER BY tc.valid_from DESC',
        [$params['id']]
    );
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

    $items = DB::fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY type', [$params['id']]);
    $bezugItem = null; $einspeisungItem = null; $beitragItem = null;
    foreach ($items as $it) {
        if ($it['type'] === 'bezug')           $bezugItem       = $it;
        if ($it['type'] === 'einspeisung')     $einspeisungItem = $it;
        if ($it['type'] === 'mitgliedsbeitrag') $beitragItem    = $it;
    }

    // RAW_: LaTeX-Befehle direkt übergeben — service.js darf diese NICHT escapen.
    // Zeile im Tabellenkontext (5 Spalten, braucht \\):
    $steuerZeile = '\\multicolumn{5}{l}{\\footnotesize\\color{midgray}Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung): keine Umsatzsteuer.} \\\\';
    // Paragraph am Seitenende:
    $steuerText  = 'Gem.\\,\\S{}\\,6 Abs.\\,1 Z\\,27 UStG 1994 (Kleinunternehmerregelung) wird keine Umsatzsteuer in Rechnung gestellt.';

    streamLatexPdf('rechnung', [
        'EEG_NAME'              => $invoice['eeg_name'],
        'EEG_ADRESSE'           => $invoice['eeg_address'] ?? '',
        'EEG_UID'               => '',
        'MITGLIED_NAME'         => $invoice['first_name'] . ' ' . $invoice['last_name'],
        'MITGLIED_ADRESSE'      => $invoice['address'] . ', ' . $invoice['zip'] . ' ' . $invoice['city'],
        'MITGLIED_UID'          => $invoice['invoice_uid'] ?? '',
        'RECHNUNGSNUMMER'       => $invoice['rechnungsnummer'],
        'RECHNUNGSDATUM'        => date('d.m.Y', strtotime($invoice['created_at'])),
        'ABRECHNUNGSZEITRAUM'   => date('d.m.Y', strtotime($invoice['period_from'])) . ' -- ' . date('d.m.Y', strtotime($invoice['period_to'])),
        'BEZUG_KWH'             => $bezugItem ? number_format((float)$bezugItem['kwh'], 2, ',', '.') : '0,00',
        'BEZUG_TARIF'           => $bezugItem ? number_format((float)$bezugItem['rate_ct_kwh'], 4, ',', '.') : '0,0000',
        'BEZUG_BETRAG'          => $bezugItem ? number_format((float)$bezugItem['amount_eur'], 2, ',', '.') : '0,00',
        'EINSPEISUNG_KWH'       => $einspeisungItem ? number_format((float)$einspeisungItem['kwh'], 2, ',', '.') : '0,00',
        'EINSPEISUNG_TARIF'     => $einspeisungItem ? number_format((float)$einspeisungItem['rate_ct_kwh'], 4, ',', '.') : '0,0000',
        'EINSPEISUNG_BETRAG'    => $einspeisungItem ? number_format(abs((float)$einspeisungItem['amount_eur']), 2, ',', '.') : '0,00',
        'MITGLIEDSBEITRAG'      => $beitragItem ? number_format((float)$beitragItem['amount_eur'], 2, ',', '.') : '0,00',
        'SUMME_NETTO'           => number_format((float)$invoice['saldo_eur'], 2, ',', '.'),
        'SUMME_BRUTTO'          => number_format((float)$invoice['saldo_eur'], 2, ',', '.'),
        'RAW_STEUER_ZEILE'      => $steuerZeile,
        'RAW_STEUER_TEXT'       => $steuerText,
        'IBAN'                  => $invoice['eeg_iban'] ?? '--',
        'BIC'                   => $invoice['eeg_bic'] ?? '--',
        'ZAHLUNGSZIEL'          => date('d.m.Y', strtotime($invoice['created_at'] . ' +14 days')),
    ], $invoice['rechnungsnummer'] . '.pdf');
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
    header('Content-Disposition: attachment; filename="' . addslashes($file['name']) . '"');
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

// ─── Portal: Mitgliederverwaltung ───────────────────────
$router->get('/portal/members', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $communityId = Auth::activeCommunityId();
    DB::setCommunity($communityId);
    $members = DB::fetchAll(
        "SELECT m.*,
                COUNT(DISTINCT mp.id) AS metering_point_count,
                bool_or(mp.type IN ('consumer', 'prosumer')) FILTER (WHERE mp.active) AS hat_bezug,
                bool_or(mp.type IN ('producer', 'prosumer')) FILTER (WHERE mp.active) AS hat_einspeisung,
                COALESCE(SUM(i.saldo_eur) FILTER (WHERE i.saldo_eur > 0 AND i.sent_at IS NULL), 0) AS open_amount,
                EXISTS(SELECT 1 FROM membership_applications ma WHERE ma.member_id = m.id) AS via_online
         FROM members m
         LEFT JOIN metering_points mp ON mp.member_id = m.id AND mp.active = true
         LEFT JOIN invoices i ON i.member_id = m.id AND i.saldo_eur > 0 AND i.sent_at IS NULL
         WHERE m.community_id = ?
         GROUP BY m.id ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name",
        [$communityId]
    );
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
         geburtsdatum=?, stromlieferant=?, speicher_status=?, speicher_kwh=?, andere_eeg=?, andere_eeg_name=?
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
            $params['id'],
        ]
    );
    logAudit($member['community_id'], 'member.update', 'member', $params['id'],
        'Mitglied ' . trim($_POST['first_name']) . ' ' . trim($_POST['last_name']) . ' bearbeitet');
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
        $mail = renderMailTemplate('member_deactivated', [
            'vorname' => htmlspecialchars($member['first_name']),
        ],
            'Ihre Mitgliedschaft bei Strom für alle wurde deaktiviert',
            '<p>Hallo {{vorname}},</p><p>Ihr Zugang wurde deaktiviert. Ihre Daten bleiben aus '
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
        $mail = renderMailTemplate('password_reset', [
            'vorname'     => htmlspecialchars($member['first_name']),
            'link'        => $link,
            'gueltigkeit' => '10 Minuten',
        ],
            'Passwort zurücksetzen – Strom für alle',
            '<p>Hallo {{vorname}},</p>'
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

$router->get('/portal/members/:id', function ($params) {
    Auth::requireLogin(); Auth::requireRole('manager');
    // Nicht über die aktive Rolle scopen: Platform-Admins müssen ein Mitglied auch dann ansehen
    // können, wenn ihre aktuell aktive Rolle gerade eine ANDERE EEG ist (z.B. von der
    // EEG-Übersicht im Admin-Bereich aus) -- IDOR-Schutz erfolgt danach explizit.
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $member['community_id'];
    DB::setCommunity($communityId);
    // "Gelöschte" Zählpunkte sind nur soft-deaktiviert (active=false), damit historische
    // Abrechnungsperioden weiter nachvollziehbar bleiben -- auf der Mitglied-Detailseite
    // sollen sie aber wie erwartet aus der Liste verschwinden.
    $metering_points = DB::fetchAll('SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at DESC', [$params['id']]);
    $member_files = DB::fetchAll('SELECT * FROM member_files WHERE member_id = ? ORDER BY created_at DESC', [$params['id']]);
    $application = DB::fetchOne('SELECT id FROM membership_applications WHERE member_id = ? AND community_id = ?', [$params['id'], $communityId]);
    require ROOT . '/src/views/pages/member_detail.php';
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
    header('Content-Disposition: attachment; filename="' . addslashes($file['name']) . '"');
    header('Content-Length: ' . filesize($file['pfad']));
    readfile($file['pfad']);
    exit;
});

// Profilbild eines Mitglieds ansehen -- entweder das Mitglied selbst oder ein Manager der
// gleichen Community (keine Community-Prüfung nötig, wenn es das eigene Konto ist).
$router->get('/portal/members/:id/avatar', function ($params) {
    Auth::requireLogin();
    $member = DB::fetchOne('SELECT id, community_id, user_id, photo_path FROM members WHERE id = ?', [$params['id']]);
    if (!$member || !$member['photo_path']) { http_response_code(404); return; }

    $allowed = $member['user_id'] !== null && $member['user_id'] === Auth::userId();
    if (!$allowed) {
        DB::setCommunity($member['community_id']);
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

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, type, meter_code, jahresverbrauch_kwh, engpassleistung_kw, geplante_einspeisung_kwh, registered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
         ON CONFLICT (community_id, zaehlpunkt_nr) DO NOTHING',
        [$communityId, $member['id'], $znr, $_POST['type'] ?? 'consumer', trim($_POST['meter_code'] ?? '') ?: null, $jahresverbrauch, $engpassleistung, $geplanteEinsp]
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
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // IDOR-Schutz: Manager darf nur die eigene EEG verwalten
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }

    $communityId = $member['community_id'];
    DB::setCommunity($communityId);

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
        $mail = renderMailTemplate('contract_bezug', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/contract/bezug/sign')),
            'hinweis'  => contractInvalidationNote((int)$member['contract_bezug_version']),
        ],
            'Ihre Bezugsvereinbarung – {{eeg_name}}',
            '<p>Hallo {{vorname}},</p><p>Ihre Bezugsvereinbarung mit {{eeg_name}} liegt für Sie bereit. '
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
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }

    // IDOR-Schutz
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }

    $communityId = $member['community_id'];
    DB::setCommunity($communityId);

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
        $mail = renderMailTemplate('contract_einspeisung', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/contract/einspeisung/sign')),
            'hinweis'  => contractInvalidationNote((int)$member['contract_einspeisung_version']),
        ],
            'Ihre Einspeisevereinbarung – {{eeg_name}}',
            '<p>Hallo {{vorname}},</p><p>Ihre Einspeisevereinbarung mit {{eeg_name}} liegt für Sie bereit. '
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
        $mail = renderMailTemplate('contract_both', [
            'vorname'  => htmlspecialchars($member['first_name']),
            'eeg_name' => htmlspecialchars($community['name']),
            'link'     => htmlspecialchars(portalUrl('/portal/my/documents')),
            'hinweis'  => $hinweis,
        ],
            'Ihre Vereinbarungen – {{eeg_name}}',
            '<p>Hallo {{vorname}},</p><p>Ihre Bezugsvereinbarung und Ihre Einspeisevereinbarung mit {{eeg_name}} liegen '
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
    $member = DB::fetchOne('SELECT * FROM members WHERE id = ?', [$params['id']]);
    if (!$member) { http_response_code(404); echo 'Nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $member['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $member['community_id'];
    DB::setCommunity($communityId);
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
    $mp = DB::fetchOne('SELECT community_id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $mp['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $mp['community_id'];
    DB::setCommunity($communityId);

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

    $jahresverbrauch = trim($_POST['jahresverbrauch_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['jahresverbrauch_kwh']) : null;
    $engpassleistung  = trim($_POST['engpassleistung_kw'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['engpassleistung_kw']) : null;
    $geplanteEinsp    = trim($_POST['geplante_einspeisung_kwh'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['geplante_einspeisung_kwh']) : null;

    DB::execute(
        'UPDATE metering_points SET zaehlpunkt_nr=?, meter_code=?, type=?, jahresverbrauch_kwh=?, engpassleistung_kw=?, geplante_einspeisung_kwh=? WHERE id=? AND community_id=?',
        [
            $znr,
            trim($_POST['meter_code'] ?? '') ?: null,
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
    $mp = DB::fetchOne('SELECT community_id FROM metering_points WHERE id = ? AND member_id = ?', [$params['mpid'], $params['id']]);
    if (!$mp) { http_response_code(404); echo 'Zählpunkt nicht gefunden'; return; }
    if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $mp['community_id']) {
        http_response_code(403); echo 'Kein Zugriff'; return;
    }
    $communityId = $mp['community_id'];
    DB::setCommunity($communityId);
    DB::execute('UPDATE metering_points SET active=false WHERE id=? AND community_id=?', [$params['mpid'], $communityId]);
    header('Location: /portal/members/' . $params['id'] . '?success=1');
    exit;
});

// ─── Portal: Passwort ändern ────────────────────────────
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
    return DB::fetchOne(
        'SELECT id, photo_path, salutation FROM members WHERE user_id = ? AND community_id = ?',
        [Auth::userId(), $communityId]
    );
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
    return DB::fetchOne(
        'SELECT * FROM members WHERE user_id = ? AND community_id = ?',
        [Auth::userId(), $communityId]
    );
}

$router->get('/portal/profile', function () {
    Auth::requireLogin();
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path FROM users WHERE id = ?', [Auth::userId()]);
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
    $profileUser = DB::fetchOne('SELECT id, email, first_name, last_name, photo_path FROM users WHERE id = ?', [Auth::userId()]);
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
    $runs = DB::fetchAll(
        'SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]
    );
    require ROOT . '/src/views/pages/billing.php';
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
        'SELECT i.*, br.quartal, m.kundennummer, m.first_name, m.last_name, m.company_name, m.email
         FROM invoices i
         JOIN billing_runs br ON br.id = i.billing_run_id
         JOIN members m ON m.id = i.member_id
         WHERE i.community_id = ?
         ORDER BY i.created_at DESC',
        [$communityId]
    );
    $quartalFilter = $_GET['quartal'] ?? '';
    require ROOT . '/src/views/pages/billing_invoices.php';
});

$router->post('/portal/billing/release', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $runId = $_POST['billing_run_id'] ?? '';
    $communityId = Auth::activeCommunityId();
    try {
        Billing::release($runId, Auth::userId());
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Abrechnungslauf freigegeben');
        header('Location: /portal/billing?success=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        logAudit($communityId, 'billing.release', 'billing_run', $runId, 'Freigabe fehlgeschlagen: ' . $e->getMessage(), true);
        DB::setCommunity($communityId);
        $runs = DB::fetchAll('SELECT * FROM billing_runs WHERE community_id = ? ORDER BY quartal DESC', [$communityId]);
        require ROOT . '/src/views/pages/billing.php';
    }
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

// ─── Portal: EDA-Import ─────────────────────────────────
$router->get('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    require ROOT . '/src/views/pages/eda_upload.php';
});

$router->post('/portal/eda/upload', function () {
    Auth::requireLogin(); Auth::requireRole('manager');

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

    $cmd = sprintf(
        'python3 /var/www/html/eda-parser/parser.py --file %s --community %s --user-id %s 2>&1',
        escapeshellarg($savePath),
        escapeshellarg($communitySlug),
        escapeshellarg($userId)
    );
    $output = shell_exec($cmd);
    $result = json_decode($output, true);
    $communityId = Auth::activeCommunityId();
    if ($result === null) {
        $error = 'Parser-Fehler: ' . htmlspecialchars(substr($output ?? 'Keine Ausgabe', 0, 500));
        logAudit($communityId, 'eda.import', null, null, 'EDA-Import fehlgeschlagen: ' . substr($output ?? 'Keine Ausgabe', 0, 500), true);
    } else {
        logAudit($communityId, 'eda.import', null, null,
            'EDA-Import: ' . ($result['records'] ?? '?') . ' Datensätze importiert' . (!empty($result['warnings']) ? ', ' . count($result['warnings']) . ' Warnung(en)' : ''));
    }

    require ROOT . '/src/views/pages/eda_upload.php';
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
    require ROOT . '/src/views/pages/settings.php';
});

$router->post('/portal/settings/signature', function () {
    Auth::requireLogin(); Auth::requireRole('manager');
    $signature = $_POST['signature_image'] ?? '';
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        header('Location: /portal/settings?error=upload');
        exit;
    }
    DB::execute('UPDATE users SET signature_image = ? WHERE id = ?', [$signature, Auth::userId()]);
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
    DB::execute(
        'UPDATE communities SET name=?, address=?, iban=?, bic=?, zvr_number=?, marktpartner_id=?, dashboard_url=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['address'] ?? ''),
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['dashboard_url'] ?? '') ?: null,
            $communityId,
        ]
    );
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
    header('Location: /portal/settings?success=1');
    exit;
});

// ─── Admin-Bereich ──────────────────────────────────────
$router->get('/admin', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $communities = DB::fetchAll('SELECT * FROM communities ORDER BY name');
    $userCount   = DB::fetchOne('SELECT COUNT(*) AS cnt FROM users')['cnt'];
    $rawUsers    = DB::fetchAll('SELECT id, email, first_name, last_name, active FROM users ORDER BY last_name, first_name');
    $allRoles    = DB::fetchAll('SELECT ur.user_id, ur.role, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id');
    $roleMap = [];
    foreach ($allRoles as $r) { $roleMap[$r['user_id']][] = $r; }
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
    // Bewusst ohne DB::setCommunity/RLS-Abhängigkeit: der Platform-Admin muss die Mitglieder
    // JEDER EEG hier sehen können, nicht nur die seiner aktuell aktiven Rolle.
    $members = DB::fetchAll(
        'SELECT m.id, m.kundennummer, m.first_name, m.last_name, m.company_name, m.email, m.status,
                m.user_id, u.email AS login_email
         FROM members m LEFT JOIN users u ON u.id = m.user_id
         WHERE m.community_id = ?
         ORDER BY m.kundennummer NULLS LAST, m.last_name, m.first_name',
        [$params['id']]
    );
    require ROOT . '/src/views/pages/admin_community.php';
});

$router->get('/admin/users/:id', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $user        = DB::fetchOne('SELECT id, email, first_name, last_name, active FROM users WHERE id = ?', [$params['id']]);
    if (!$user) { http_response_code(404); return; }
    $roles       = DB::fetchAll('SELECT ur.*, c.name AS community_name FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id WHERE ur.user_id = ?', [$params['id']]);
    $communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
    require ROOT . '/src/views/pages/admin_user.php';
});

$router->post('/admin/users/:id/roles', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $communityId = $_POST['community_id'] ?? null;
    $role = $_POST['role'] ?? '';
    if (!in_array($role, ['platform_admin', 'manager', 'member'])) { http_response_code(400); return; }
    DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$communityId, $params['id'], $role]
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
    DB::execute(
        'UPDATE communities SET name=?, marktpartner_id=?, zvr_number=?, address=?, iban=?, bic=?, active=? WHERE id=?',
        [
            trim($_POST['name'] ?? ''),
            trim($_POST['marktpartner_id'] ?? '') ?: null,
            trim($_POST['zvr_number'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
            trim($_POST['iban'] ?? '') ?: null,
            trim($_POST['bic'] ?? '') ?: null,
            isset($_POST['active']) ? 'true' : 'false',
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

// ─── Admin: E-Mail-Einstellungen (Microsoft Graph) ──────
$router->get('/admin/mail-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $mailConfig = DB::fetchOne('SELECT * FROM platform_mail_config WHERE id = 1');
    $mailTemplates = DB::fetchAll('SELECT * FROM platform_mail_templates ORDER BY key');
    try { $platformSettings = DB::fetchOne('SELECT * FROM platform_settings WHERE id = 1'); } catch (\Throwable $e) { $platformSettings = null; }
    require ROOT . '/src/views/pages/admin_mail_settings.php';
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

$router->post('/admin/mail-templates', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }
    $key = $_POST['key'] ?? '';
    if (!in_array($key, ['password_reset', 'invite', 'member_deactivated', 'contract_bezug', 'contract_einspeisung', 'contract_both'], true)) { http_response_code(400); return; }
    DB::execute(
        'UPDATE platform_mail_templates SET subject = ?, body_html = ?, updated_at = now() WHERE key = ?',
        [trim($_POST['subject'] ?? ''), $_POST['body_html'] ?? '', $key]
    );
    logAudit(null, 'mail_template.update', 'platform_mail_templates', $key, 'E-Mail-Vorlage "' . $key . '" aktualisiert');
    header('Location: /admin/mail-settings?success=1');
    exit;
});

$router->post('/admin/mail-settings', function () {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); return; }

    // Client-Secret nur überschreiben, wenn tatsächlich ein neuer Wert eingegeben wurde --
    // das Feld wird beim Laden nie im Klartext vorbefüllt, ein leeres Absenden darf das
    // gespeicherte Secret also nicht versehentlich löschen.
    $current = DB::fetchOne('SELECT client_secret FROM platform_mail_config WHERE id = 1');
    $newSecret = trim($_POST['client_secret'] ?? '');
    $clientSecret = $newSecret !== '' ? $newSecret : ($current['client_secret'] ?? null);

    DB::execute(
        'UPDATE platform_mail_config
         SET tenant_id = ?, client_id = ?, client_secret = ?, sender_address = ?, updated_at = now()
         WHERE id = 1',
        [
            trim($_POST['tenant_id'] ?? '') ?: null,
            trim($_POST['client_id'] ?? '') ?: null,
            $clientSecret,
            trim($_POST['sender_address'] ?? '') ?: null,
        ]
    );
    logAudit(null, 'mail_config.update', 'platform_mail_config', '1', 'Microsoft-Graph-Mailkonfiguration aktualisiert');
    header('Location: /admin/mail-settings?success=1');
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
        ];
    }
    require ROOT . '/src/views/pages/admin_templates.php';
});

$router->get('/admin/templates/:name/download', function ($params) {
    Auth::requireLogin();
    if (!Auth::isPlatformAdmin()) { http_response_code(403); echo 'Kein Zugriff'; return; }
    $registry = adminFileRegistry();
    if (!array_key_exists($params['name'], $registry)) { http_response_code(404); echo 'Unbekannte Datei'; return; }
    $path = adminFilePath($params['name']);
    if (!$path) { http_response_code(404); echo 'Datei nicht gefunden'; return; }

    header('Content-Type: ' . ($registry[$params['name']]['type'] === 'pdf' ? 'application/pdf' : 'text/plain; charset=UTF-8'));
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

$router->dispatch();
