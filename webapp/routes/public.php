<?php

declare(strict_types=1);

/**
 * Öffentliche Routen: Marketing-Seiten, Rechtstexte, Beitritt, Kontakt, Live-Seite und
 * dynamische Assets (Logos, Favicons). Muss als erste Routendatei geladen werden: die Muster
 * /:communityid/... sollen weiter vor allen anderen greifen.
 *
 * Wird von public/index.php eingebunden (gleicher globaler Gültigkeitsbereich: $router, ROOT,
 * Hilfsfunktionen). Reihenfolge der Dateien dort beachten: die erste passende Route gewinnt.
 *
 * @var Router $router
 */

// ─── Gesundheitscheck ───────────────────────────────────
$router->get('/health', function () {
    echo 'OK';
});

// ─── Landingpage ────────────────────────────────────────
$router->get('/', function () {
    require ROOT . '/src/views/pages/home.php';
});

// ─── Info-Seiten (Inhalte: src/content/marketing_pages.php) ─────────────
// Sichtbarkeits-Check 17.11.2026: eigene Seite pro Funktion, FAQ, Seite der Pilot-EEG.
$router->get('/funktionen', function () {
    $kind = 'overview';
    require ROOT . '/src/views/pages/marketing_page.php';
});
$router->get('/funktionen/:slug', function ($params) {
    $slug = $params['slug'];
    if ($slug === 'energiegemeinschaft-feldkirchen' || !isset(marketingPages()[$slug])) {
        http_response_code(404);
        require ROOT . '/src/views/pages/404.php';
        return;
    }
    $kind = 'page';
    require ROOT . '/src/views/pages/marketing_page.php';
});
$router->get('/energiegemeinschaft-feldkirchen', function () {
    $kind = 'page';
    $slug = 'energiegemeinschaft-feldkirchen';
    require ROOT . '/src/views/pages/marketing_page.php';
});
$router->get('/faq', function () {
    $kind = 'faq';
    require ROOT . '/src/views/pages/marketing_page.php';
});

// ─── Informieren und Beitreten: Auswahl der Energiegemeinschaft ─────────
$router->get('/beitreten', function () {
    $communities = DB::fetchAll('SELECT * FROM communities WHERE active = true ORDER BY name');
    // Für neu angelegte EEGs ohne eigene Unterlagen wird "Informationen folgen in Kürze" angezeigt,
    // statt fälschlich die Texte einer anderen Energiegemeinschaft darzustellen.
    $communitiesWithLegalPages = communitiesWithLegalPages();
    require ROOT . '/src/views/pages/beitreten_picker.php';
});

// ─── Rechtsdokumente je Energiegemeinschaft ───────────────
// Patrick, 08.11.2026: Dokumente, die pro EEG verschieden sind (AGB, Statuten, Widerruf,
// Impressum, EEG-Teil der Datenschutzerklärung), pflegt der Obmann selbst unter
// /portal/dokumente; der allgemeine Teil der Datenschutzerklärung und die
// Barrierefreiheitserklärung gelten für die ganze Plattform (Plattform-Admin). Gespeichert wird
// jede Änderung als neue Zeile in legal_documents (Verlauf); ohne Zeile gilt die im Code
// hinterlegte Standardfassung (views/legal_defaults/<rc>/ bzw. /plattform/).

/** Verwaltete Rechtsdokumente: Schlüssel => Titel + wer sie pflegt (eeg/plattform/beides). */
function legalDocumentDefs(): array
{
    return [
        'agb'              => ['title' => 'Allgemeine Geschäftsbedingungen', 'scope' => 'eeg'],
        'statuten'         => ['title' => 'Statuten', 'scope' => 'eeg'],
        'widerruf'         => ['title' => 'Widerrufsbelehrung', 'scope' => 'eeg'],
        'impressum'        => ['title' => 'Impressum', 'scope' => 'beides'],
        'datenschutz'      => ['title' => 'Datenschutzerklärung', 'scope' => 'beides'],
        'barrierefreiheit' => ['title' => 'Erklärung zur Barrierefreiheit', 'scope' => 'plattform'],
        'kontakt'          => ['title' => 'Kontakt', 'scope' => 'plattform'],
    ];
}

/** Aktive EEG zu einer RC-Nummer aus der URL (z.B. "rc108175"), sonst null. */
function communityByRc(string $rc): ?array
{
    if (!preg_match('/^[a-z0-9]{2,20}$/i', $rc)) return null;
    return DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [strtolower($rc)]) ?: null;
}

/** EEG-Zeile nach ID (pro Request zwischengespeichert). */
function communityById(?string $id): ?array
{
    static $cache = [];
    if (!$id) return null;
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$id]) ?: null;
    }
    return $cache[$id];
}

/** Registriert die EEG-eigenen Zustimmungs-Fassungen (consent_versions) für consent_texts.php. */
function loadCommunityConsentVersions(?string $communityId): void
{
    static $loaded = [];
    if (!$communityId || isset($loaded[$communityId])) return;
    $loaded[$communityId] = true;
    $versions = [];
    foreach (DB::fetchAll('SELECT * FROM consent_versions WHERE community_id = ? ORDER BY created_at DESC', [$communityId]) as $row) {
        $versions[$row['version_key']] = consentVersionFromRow($row);
    }
    consentRegisterVersions($versions);
}

/** Aktuell gültige Zustimmungs-Fassung einer EEG (eigene Fassung > v2 bzw. v2-ohne-ae). */
function consentCurrentVersionFor(?array $community): string
{
    if ($community) loadCommunityConsentVersions($community['id']);
    return consentCurrentVersion($community);
}

/** Jüngste gespeicherte Fassung (community_id null = Plattform) inkl. Name der bearbeitenden Person. */
function legalDocumentLatestRow(?string $communityId, string $docKey): ?array
{
    $sql = 'SELECT d.*, u.first_name AS editor_first_name, u.last_name AS editor_last_name
            FROM legal_documents d LEFT JOIN users u ON u.id = d.created_by
            WHERE d.doc_key = ? AND ' . ($communityId === null ? 'd.community_id IS NULL' : 'd.community_id = ?') . '
            ORDER BY d.created_at DESC LIMIT 1';
    return DB::fetchOne($sql, $communityId === null ? [$docKey] : [$docKey, $communityId]) ?: null;
}

/** Datei der im Code hinterlegten Standardfassung, falls es eine gibt. */
function legalDefaultFile(?array $community, string $docKey): ?string
{
    $dir = $community === null ? 'plattform' : strtolower((string)$community['marktpartner_id']);
    if (!preg_match('/^[a-z0-9]+$/', $dir) || !preg_match('/^[a-z]+$/', $docKey)) return null;
    $file = ROOT . '/src/views/legal_defaults/' . $dir . '/' . $docKey . '.php';
    return is_file($file) ? $file : null;
}

/**
 * Aktuell gültiger Text eines Rechtsdokuments: ['html', 'stand', 'meta', 'source' (eigen|standard), 'row'],
 * oder null, wenn die EEG das Dokument noch nicht veröffentlicht hat. $community null = Plattform-Teil.
 */
function legalDocument(?array $community, string $docKey): ?array
{
    $row = legalDocumentLatestRow($community['id'] ?? null, $docKey);
    if ($row) {
        return ['html' => sanitizeLegalHtml($row['content_html']), 'stand' => datumLang($row['created_at']),
                'meta' => null, 'source' => 'eigen', 'row' => $row];
    }
    $file = legalDefaultFile($community, $docKey);
    if (!$file) return null;
    $legalStand = '';
    $legalMeta = null;
    ob_start();
    include $file;
    $html = ob_get_clean();
    return ['html' => $html, 'stand' => $legalStand, 'meta' => $legalMeta, 'source' => 'standard', 'row' => null];
}

/**
 * EEGs mit veröffentlichten Rechtsunterlagen: eine im Code hinterlegte Standardfassung oder
 * mindestens ein selbst gespeichertes Dokument. EEGs ohne eigene Unterlagen bekommen "folgt in
 * Kürze" statt der Texte einer anderen EEG.
 */
function communitiesWithLegalPages(): array
{
    static $rcs = null;
    if ($rcs === null) {
        $rcs = [];
        foreach (glob(ROOT . '/src/views/legal_defaults/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== 'plattform') $rcs[] = basename($dir);
        }
        foreach (DB::fetchAll('SELECT DISTINCT LOWER(c.marktpartner_id) AS rc FROM legal_documents d
                               JOIN communities c ON c.id = d.community_id WHERE c.active = true') as $r) {
            $rcs[] = $r['rc'];
        }
        $rcs = array_values(array_unique($rcs));
    }
    return $rcs;
}

// Generische Adressen ohne RC-Nummer (Fußzeile): Auswahlseite aller aktiven EEGs mit Suchfeld
// (Patrick, 07./08.11.2026). Kein Redirect, auch bei nur einer EEG. Unbekannte Dokumente haben
// keine Route und landen regulär auf der 404-Seite. Die Barrierefreiheitserklärung gilt für die
// ganze Plattform und hat deshalb keine Auswahl.
function legalDocuments(): array
{
    return [
        'datenschutz' => 'Datenschutzerklärung',
        'agb'         => 'Allgemeine Geschäftsbedingungen',
        'statuten'    => 'Statuten',
        'preisliste'  => 'Preisliste',
        'widerruf'    => 'Widerrufsbelehrung',
        'impressum'   => 'Impressum',
        'kontakt'     => 'Kontakt',
    ];
}
foreach (legalDocuments() as $docSlugRoute => $docTitleRoute) {
    $router->get('/' . $docSlugRoute, function () use ($docSlugRoute, $docTitleRoute) {
        $docSlug = $docSlugRoute;
        $docTitle = $docTitleRoute;
        $communities = DB::fetchAll('SELECT name, address, marktpartner_id FROM communities WHERE active = true ORDER BY name');
        // Allgemeiner Plattform-Teil VOR der Auswahl (Patrick, 08.11.2026): Datenschutz, Impressum
        // und Kontakt haben einen eigenen Plattform-Text; die übrigen Seiten einen kurzen Hinweis.
        $platformDoc = in_array($docSlug, ['datenschutz', 'impressum', 'kontakt'], true) ? legalDocument(null, $docSlug) : null;
        require ROOT . '/src/views/pages/legal_document_picker.php';
    });
}

$router->get('/barrierefreiheit', function () {
    $community = null;
    $docKey = 'barrierefreiheit';
    $docTitle = legalDocumentDefs()[$docKey]['title'];
    $doc = legalDocument(null, $docKey);
    require ROOT . '/src/views/pages/legal_document.php';
});
$router->get('/:communityid/barrierefreiheit', function () {
    header('Location: /barrierefreiheit', true, 301);
    exit;
});

foreach (['agb', 'statuten', 'widerruf', 'impressum'] as $docKeyRoute) {
    $router->get('/:communityid/' . $docKeyRoute, function ($params) use ($docKeyRoute) {
        $community = communityByRc($params['communityid']);
        if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
        $docKey = $docKeyRoute;
        $docTitle = legalDocumentDefs()[$docKey]['title'];
        $doc = legalDocument($community, $docKey);
        require ROOT . '/src/views/pages/legal_document.php';
    });
}

$router->get('/:communityid/datenschutz', function ($params) {
    $community = communityByRc($params['communityid']);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    $platformDoc = legalDocument(null, 'datenschutz');
    $doc = legalDocument($community, 'datenschutz');
    require ROOT . '/src/views/pages/legal_datenschutz.php';
});

$router->get('/:communityid/beitreten', function ($params) {
    $community = communityByRc($params['communityid']);
    if (!$community || !in_array(strtolower($community['marktpartner_id']), communitiesWithLegalPages(), true)) {
        http_response_code(404); require ROOT . '/src/views/pages/404.php'; return;
    }
    require ROOT . '/src/views/pages/legal_beitreten.php';
});

$router->get('/:communityid/kontakt', function ($params) {
    $community = communityByRc($params['communityid']);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    require ROOT . '/src/views/pages/legal_kontakt.php';
});

/**
 * Kontaktformular der öffentlichen Kontaktseite -- auch für Datenschutz-Anfragen gedacht
 * (Auskunft/Berichtigung/Löschung), damit nicht zwingend ein eigenes E-Mail-Programm nötig ist.
 * Versendet per Mailer (Microsoft-Graph-Anbindung wie überall sonst) an die Kontaktadresse der
 * jeweiligen EEG (Einstellungen -> Kontakt-E-Mail, sonst office@stromfueralle.at); schlägt der
 * Versand fehl (z.B. Graph nicht konfiguriert), wird NICHT der Nutzer mit einem Fehler
 * konfrontiert, sondern geloggt und als "gesendet" quittiert, sobald die Nachricht wenigstens
 * inhaltlich gültig war -- gleiches Prinzip wie App\Infrastructure\Mail\MailSupportNotifier.
 * Spam-Schutz: unsichtbares Honeypot-Feld "website" -- füllt ein Bot es aus, wird stillschweigend
 * ohne Mailversand auf die Erfolgsseite umgeleitet (kein Hinweis an den Bot, dass er entdeckt wurde).
 */
/** Anliegen-Auswahl der Kontaktformulare: Plattform (/kontakt) bzw. Energiegemeinschaft (/<rc>/kontakt). */
function contactFormTopics(bool $platform): array
{
    return $platform ? [
        'eeg'         => 'Energiegemeinschaft gründen / auf die Plattform bringen',
        'plattform'   => 'Frage zur Plattform oder zum Mitgliederportal',
        'datenschutz' => 'Datenschutz',
        'sonstiges'   => 'Sonstiges (Presse, Schule, Kooperation …)',
    ] : [
        'allgemein'      => 'Allgemeine Frage',
        'mitgliedschaft' => 'Mitgliedschaft',
        'rechnung'       => 'Rechnung & Zahlung',
        'datenschutz'    => 'Datenschutz (Auskunft/Berichtigung/Löschung)',
        'sonstiges'      => 'Sonstiges',
    ];
}

/**
 * Verarbeitet ein Kontaktformular -- auch für Datenschutz-Anfragen gedacht (Auskunft/Berichtigung/
 * Löschung), damit nicht zwingend ein eigenes E-Mail-Programm nötig ist. Versand per Mailer
 * (Microsoft Graph); schlägt er fehl (z.B. Graph nicht konfiguriert), wird geloggt und trotzdem
 * quittiert, sobald die Nachricht inhaltlich gültig war -- gleiches Prinzip wie
 * App\Infrastructure\Mail\MailSupportNotifier. Spam-Schutz: unsichtbares Honeypot-Feld "website" -- füllt ein Bot
 * es aus, wird stillschweigend ohne Mailversand auf die Erfolgsseite umgeleitet.
 */
function handleContactForm(string $back, string $to, string $recipientLabel, array $topics): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $anliegen = trim((string)($_POST['anliegen'] ?? ''));
    $nachricht = trim((string)($_POST['nachricht'] ?? ''));
    $honeypot = trim((string)($_POST['website'] ?? ''));
    $ok = $back . '?success=' . urlencode('Danke für Ihre Nachricht! Wir melden uns so bald wie möglich.') . '#kontaktformular';

    if ($honeypot !== '') {
        header('Location: ' . $ok);
        exit;
    }
    if ($name === '' || $nachricht === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ' . $back . '?error=' . urlencode('Bitte Name, eine gültige E-Mail-Adresse und eine Nachricht angeben.') . '#kontaktformular');
        exit;
    }
    $anliegenLabel = $topics[$anliegen] ?? 'Allgemeine Frage';
    try {
        $body = '<p>Neue Nachricht über das Kontaktformular ' . htmlspecialchars($recipientLabel)
            . ' (' . htmlspecialchars($anliegenLabel) . '):</p>'
            . '<p><strong>Von:</strong> ' . htmlspecialchars($name) . ' (' . htmlspecialchars($email) . ')</p>'
            . '<p>' . nl2br(htmlspecialchars($nachricht)) . '</p>'
            . '<p style="color:#6b7280;font-size:.85rem">Antwort bitte direkt an die oben genannte E-Mail-Adresse.</p>';
        Mailer::send($to, 'Kontaktformular: ' . $anliegenLabel, $body);
    } catch (\Throwable $e) {
        error_log('[kontaktformular_mail] ' . $e->getMessage());
    }
    header('Location: ' . $ok);
    exit;
}

/** Plattform-Kontaktformular (/kontakt) -- geht an den Plattformbetreiber, nicht an eine EEG. */
$router->post('/kontakt', function () {
    handleContactForm('/kontakt', AppConfig::get()->contactEmail, 'der Plattform „Strom für alle“', contactFormTopics(true));
});

/** Kontaktformular einer EEG -- an deren Kontakt-E-Mail (Einstellungen), sonst office@stromfueralle.at. */
$router->post('/:communityid/kontakt', function ($params) {
    $community = communityByRc($params['communityid']);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    handleContactForm(
        '/' . strtolower($community['marktpartner_id']) . '/kontakt',
        trim((string)($community['contact_email'] ?? '')) ?: AppConfig::get()->contactEmail,
        'der ' . $community['name'],
        contactFormTopics(false)
    );
});

$router->get('/:communityid/preisliste', function ($params) {
    // Datengetrieben: aktuelle Preise + vollständige Änderungshistorie aus tariff_config
    // (jede Tarifänderung legt dort einen neuen Datensatz mit valid_from an). So können
    // Mitglieder jederzeit nachvollziehen, welcher Preis seit wann galt.
    $community = communityByRc($params['communityid']);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    DB::setCommunity($community['id']);
    $tariffHistory = DB::fetchAll(
        'SELECT * FROM tariff_config WHERE community_id = ? ORDER BY valid_from DESC, created_at DESC',
        [$community['id']]
    );
    require ROOT . '/src/views/pages/legal_preisliste.php';
});

/** RC-Nummer (klein, z.B. "rc108175") der im Portal aktiven EEG -- für Links auf deren Rechtsseiten. */
function activeCommunityRc(): string
{
    static $rc = null;
    if ($rc === null) {
        $id = Auth::activeCommunityId();
        $row = $id ? DB::fetchOne('SELECT marktpartner_id FROM communities WHERE id = ?', [$id]) : null;
        $rc = strtolower((string)($row['marktpartner_id'] ?? 'rc108175'));
    }
    return $rc;
}


/**
 * Leeres Beitrittsformular zum Ausdrucken (Patrick, 07.11.2026). Ersetzt die statische
 * public/assets/docs/beitrittserklaerung-...pdf: erzeugt aus latex-service
 * beitrittserklaerung_leer.tex, Zustimmungen (Langfassung) und SEPA-Text aus derselben Quelle
 * wie das Online-Formular (consent_texts.php). Ergebnis wird je Textstand zwischengespeichert --
 * der Hash über alle Template-Variablen ändert sich automatisch, sobald sich Texte oder
 * EEG-Stammdaten ändern; die 1-Tages-Frist fängt Layoutänderungen an der Vorlage selbst ab.
 */
$router->get('/:communityid/beitrittserklaerung.pdf', function ($params) {
    $rc = strtolower($params['communityid']);
    if (!in_array($rc, communitiesWithLegalPages(), true)) {
        http_response_code(404); require ROOT . '/src/views/pages/404.php'; return;
    }
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [$rc]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }

    loadCommunityConsentVersions($community['id']);
    $vars = offlineBeitrittserklaerungVars($community);
    $cacheDir = '/var/www/html/storage/cache/beitrittserklaerung';
    $cacheFile = $cacheDir . '/' . $rc . '_' . sha1(json_encode($vars)) . '.pdf';
    if (!is_file($cacheFile) || filemtime($cacheFile) < time() - 86400) {
        $error = null;
        $pdf = generateLatexPdf('beitrittserklaerung_leer', $vars, [], $error);
        if ($pdf === null) {
            error_log('[beitrittserklaerung.pdf] ' . $error);
            http_response_code(503);
            echo 'Das Formular kann gerade nicht erzeugt werden. Bitte versuchen Sie es später erneut oder treten Sie online bei: /beitreten';
            return;
        }
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
        foreach (glob($cacheDir . '/' . $rc . '_*.pdf') ?: [] as $old) { @unlink($old); }
        @file_put_contents($cacheFile, $pdf);
    } else {
        $pdf = file_get_contents($cacheFile);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="beitrittserklaerung-' . $rc . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
});

/** Ablage EEG-eigener Dateien (z.B. Infoblatt), vom Obmann unter /portal/dokumente hochgeladen. */
function communityDocDir(string $communityId): string
{
    return '/var/www/html/storage/eeg-dokumente/' . preg_replace('/[^a-f0-9-]/', '', strtolower($communityId));
}

/**
 * Infoblatt einer EEG: vom Obmann hochgeladene Fassung, sonst für die Pilot-EEG die bisherige
 * plattformweite Datei (/admin/templates -> infoblatt.pdf).
 */
$router->get('/:communityid/infoblatt.pdf', function ($params) {
    $community = communityByRc($params['communityid']);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    $path = communityDocDir($community['id']) . '/infoblatt.pdf';
    if (!is_file($path)) {
        // Bisheriges plattformweites Infoblatt nur für EEGs mit Standardfassungen im Code (Pilot-EEG).
        $path = is_dir(ROOT . '/src/views/legal_defaults/' . strtolower($community['marktpartner_id'])) ? adminFilePath('infoblatt.pdf') : null;
    }
    if (!$path || !is_file($path)) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="infoblatt-' . strtolower($community['marktpartner_id']) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

// Alte, statische Adresse des Papierformulars (Infoblatt, E-Mails) -> neue, erzeugte Fassung.
$router->get('/assets/docs/beitrittserklaerung-eeg-strompool-feldkirchen-suedwest.pdf', function () {
    header('Location: /rc108175/beitrittserklaerung.pdf', true, 301);
    exit;
});

/**
 * Vollständiger Wortlaut aller Fassungen der sechs Zustimmungen. Darauf verweist der
 * Versionshinweis im Online-PDF ("Es gilt der vollständige Wortlaut ... abrufbar unter
 * stromfueralle.at/rc.../zustimmungen") -- deshalb auch ältere Fassungen, die Bestandsmitglieder
 * unterschrieben haben.
 */
$router->get('/:communityid/zustimmungen', function ($params) {
    $rc = strtolower($params['communityid']);
    if (!in_array($rc, communitiesWithLegalPages(), true)) {
        http_response_code(404); require ROOT . '/src/views/pages/404.php'; return;
    }
    $community = DB::fetchOne('SELECT * FROM communities WHERE LOWER(marktpartner_id) = ? AND active = true', [$rc]);
    if (!$community) { http_response_code(404); require ROOT . '/src/views/pages/404.php'; return; }
    require ROOT . '/src/views/pages/legal_zustimmungen.php';
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
    // Gespeichert wird consentCurrentVersionFor() -- das stimmt nur, wenn das Formular auch mit
    // dieser Fassung angezeigt wurde. War die Seite schon vor einem Update der Texte offen,
    // Haken zurücksetzen und die neue Fassung zum erneuten Lesen anzeigen.
    if (($_POST['consent_version'] ?? '') !== consentCurrentVersionFor($community)) {
        foreach ($consentFields as $cf) { unset($_POST[$cf]); }
        $error = 'Die Zustimmungstexte wurden in der Zwischenzeit aktualisiert. Bitte lesen Sie sie erneut und bestätigen Sie sie.';
        require ROOT . '/src/views/pages/beitreten_formular.php';
        return;
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
            community_id, salutation, titel_vor, titel_nach, first_name, last_name, geburtsdatum,
            address, zip, city, phone, email, stromlieferant,
            bezug_gewuenscht, bezug_zaehlpunkt, bezug_jahresverbrauch_kwh,
            einspeisung_gewuenscht, einspeisung_zaehlpunkt, einspeisung_kwp, einspeisung_geplante_kwh,
            speicher_status, speicher_kwh, andere_eeg, andere_eeg_name,
            iban, bic, kontoinhaber, konto_adresse,
            zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
            zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb, consent_version,
            signature_image, signed_at, signer_ip, sepa_signature_image, sepa_signed_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), ?, ?, now())',
        [
            $communityId,
            $_POST['salutation'] ?? null,
            resolveTitelField($_POST, 'titel_vor'),
            resolveTitelField($_POST, 'titel_nach'),
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
            consentCurrentVersionFor($community),
            $signature,
            clientIp(),
            $sepaSignature,
            // sepa_signed_at: now() wie signed_at -- bis 06.11.2026 stand hier
            // date('Y-m-d H:i:s') (Ortszeit OHNE Zeitzone), Postgres las das als UTC und das
            // SEPA-Mandat erschien im PDF 1-2 h später (siehe migrate_20261107.sql).
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
    // Auth::start() (ganz oben in dieser Datei, läuft vor JEDER Route) ruft session_start()
    // auf -- PHPs Standard-Session-Cache-Limiter "nocache" setzt dabei automatisch
    // Expires: <Datum in der Vergangenheit> + Pragma: no-cache, BEVOR diese Route überhaupt
    // läuft. header('Cache-Control: ...') unten ERSETZT zwar den vorherigen Cache-Control-Wert
    // (PHP ersetzt gleichnamige Header standardmäßig), Expires/Pragma sind aber ANDERE
    // Headernamen und blieben bisher bestehen -- viele Browser/Caches werten diese
    // widersprüchlichen, bereits abgelaufenen Werte trotz des eigentlich cachefreundlichen
    // Cache-Control aus und laden das Logo dadurch bei JEDEM Seitenaufruf neu (Patrick,
    // 29.10.2026: "Logo lädt die ganze Zeit lang langsamer nach bei jedem Aufruf"). Deshalb
    // hier explizit entfernen, bevor der eigene Cache-Control-Wert gesetzt wird.
    header_remove('Expires');
    header_remove('Pragma');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

/**
 * Serverseitige Garantie dafür, dass /hero-banner-image tatsächlich ein kompaktes JPEG
 * ausliefert -- unabhängig davon, WELCHES Format die hochgeladene Datei wirklich hat.
 *
 * Hintergrund (PageSpeed-Insights-Audit, 29.10.2026, Patrick): "Bildübermittlung verbessern --
 * geschätzte Einsparung von 1.830 KiB", LCP auf Mobile >12s. Ursache: Die unter
 * /admin/templates hochgeladene hero-banner.jpg war trotz Dateiendung ".jpg" und trotz des
 * `Content-Type: image/jpeg`-Headers in Wahrheit ein verlustfrei kodiertes PNG (1600x640px,
 * ~1,9 MB) -- der Browser erkennt das tatsächliche Format anhand der Bytes und zeigt es
 * trotzdem korrekt an, nur eben mit einem Vielfachen der nötigen Datenmenge. Der am
 * 09.10.2026 dokumentierte rect-crop.js-Fix (Export als JPEG statt PNG) wirkt nur auf NEUE
 * Uploads über den Browser-Zuschneide-Dialog -- diese konkrete, schon vorher hochgeladene
 * Datei blieb davon unberührt, und jeder andere Upload-Weg (z.B. ein Bild, das nicht über
 * rect-crop.js läuft) wäre genauso betroffen gewesen.
 *
 * Deshalb hier zusätzlich eine serverseitige Absicherung statt nur eine Korrektur im Client:
 * wird eine neue/geänderte hero-banner.jpg entdeckt (Quelldatei-mtime geprüft), einmalig per
 * GD auf max. 1920px Breite skalieren (reicht für jede Bildschirmgröße, der Hero-Container ist
 * nie breiter) und als JPEG (Qualität 82) in eine Cache-Datei neben dem Original schreiben --
 * ALLE folgenden Aufrufe liefern direkt diese Cache-Datei aus, keine Neuberechnung pro Request.
 * Schlägt die GD-Verarbeitung aus irgendeinem Grund fehl (z.B. korruptes Bild), liefert die
 * Route als Rückfallebene die Originaldatei unverändert aus, statt mit einem Fehler zu enden.
 */
function optimizedHeroBannerPath(string $sourcePath, int $maxWidth = 1920, string $format = 'jpg'): string
{
    // Varianten für responsive Auslieferung (Handy 960 px, Desktop 1920 px; WebP wo möglich).
    // Die bisherige Datei hero-banner.optimized.jpg bleibt der Name der Standardvariante.
    $suffix = ($maxWidth === 1920 ? '' : '-' . $maxWidth) . '.' . $format;
    $cachePath = dirname($sourcePath) . '/hero-banner.optimized' . $suffix;
    if (is_file($cachePath) && filemtime($cachePath) >= filemtime($sourcePath)) {
        return $cachePath;
    }
    // Harte Absicherung gegen genau den Vorfall vom 29.10.2026 (siehe Dockerfile-Kommentar):
    // fehlt die gd-Extension aus irgendeinem Grund doch (z.B. ein Deploy, der diese Datei noch
    // nicht enthält), ruft KEINE der folgenden GD-Funktionen auf -- ein Aufruf einer
    // undefinierten Funktion wäre ein Fatal Error, den @ nicht abfangen kann und der die ganze
    // Seite mit HTTP 500 lahmlegen würde. Lieber das Originalbild unverändert ausliefern.
    if (!function_exists('imagecreatefromstring') || ($format === 'webp' && !function_exists('imagewebp'))) {
        return $sourcePath;
    }
    $raw = @file_get_contents($sourcePath);
    $img = $raw !== false ? @imagecreatefromstring($raw) : false;
    if ($img === false) {
        return $sourcePath;
    }
    $width = imagesx($img);
    $height = imagesy($img);
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int) round($height * ($maxWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        $img = $resized;
    }
    $ok = $format === 'webp' ? @imagewebp($img, $cachePath, 80) : @imagejpeg($img, $cachePath, 82);
    imagedestroy($img);
    return $ok ? $cachePath : $sourcePath;
}

/**
 * Eigenes Hero-Banner-Foto (statt der mitgelieferten SVG-Landschafts-Illustration) --
 * hochgeladen unter /admin/templates, dort mit Zoom/Verschieben auf die Ziel-Bildgröße des
 * Hero-Banners zugeschnitten (siehe rect-crop.js). 404, solange kein eigenes Bild hochgeladen
 * wurde -- home.php prüft das vorab und bindet die SVG-Illustration dann ganz normal weiter ein.
 *
 * Cache-Control lang + immutable (statt bisher nur 1h, Vorfall 02.10.2026 "Bild braucht lange,
 * bis es geladen wird"): home.php hängt an die URL bereits ?v=<filemtime> an, die Adresse ändert
 * sich also automatisch, sobald ein neues Foto hochgeladen wird -- unter DIESER exakten URL
 * bleiben die Bytes für immer gleich, ein langes Cache-Limit ist daher gefahrlos.
 */
$router->get('/hero-banner-image', function () {
    $path = adminFilePath('hero-banner.jpg');
    if (!$path) { http_response_code(404); return; }
    // Nur feste Varianten (keine beliebigen Größen -> kein Speicher-/CPU-Missbrauch per URL).
    $width = in_array($_GET['w'] ?? '', ['960', '1920'], true) ? (int) $_GET['w'] : 1920;
    $format = ($_GET['f'] ?? '') === 'webp' ? 'webp' : 'jpg';
    $servePath = optimizedHeroBannerPath($path, $width, $format);
    header('Content-Type: ' . (str_ends_with($servePath, '.webp') ? 'image/webp' : 'image/jpeg'));
    // Siehe Kommentar bei /logo-:variant.png weiter oben: Auth::start() ruft session_start()
    // vor JEDER Route auf, PHPs Standard-Session-Cache-Limiter setzt dabei automatisch ein
    // bereits abgelaufenes Expires + Pragma: no-cache -- das lief dem hier eigentlich
    // beabsichtigten langen Cache-Control seit dem allerersten Vorfall (02.10.2026, "Bild
    // braucht lange, bis es geladen wird") unbemerkt entgegen. Vermutlich der eigentliche
    // Grund, warum Patrick auch nach diesem Fix noch gelegentlich langsames Nachladen bemerkt
    // hat (29.10.2026).
    header_remove('Expires');
    header_remove('Pragma');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($servePath));
    readfile($servePath);
    exit;
});

/**
 * Favicon/Tab-Icon-Dateien -- wie /logo-:variant.png und /hero-banner-image PHP-Routen statt
 * echter statischer Dateien unter webapp/public/, damit ein eigener Upload über
 * /admin/templates (favicon-source.png) tatsächlich wirkt. Die Ableitungen selbst werden NICHT
 * bei jedem Request neu berechnet, sondern einmalig beim Upload erzeugt (siehe
 * generateFaviconAssets()) -- diese Routen liefern nur die fertigen Dateien aus.
 */
function serveFaviconFile(string $filename, string $contentType): void
{
    $path = adminFilePath($filename);
    if (!$path) { http_response_code(404); return; }
    header('Content-Type: ' . $contentType);
    // Siehe Kommentar bei /logo-:variant.png/-hero-banner-image weiter oben (Vorfall 29.10.2026):
    // Auth::start() setzt via PHPs Session-Cache-Limiter automatisch ein abgelaufenes Expires +
    // Pragma: no-cache, das dem hier beabsichtigten Caching sonst unbemerkt entgegenläuft.
    header_remove('Expires');
    header_remove('Pragma');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
$router->get('/favicon.ico', fn () => serveFaviconFile('favicon.ico', 'image/vnd.microsoft.icon'));
$router->get('/assets/images/favicon-16.png', fn () => serveFaviconFile('favicon-16.png', 'image/png'));
$router->get('/assets/images/favicon-32.png', fn () => serveFaviconFile('favicon-32.png', 'image/png'));
$router->get('/assets/images/apple-touch-icon.png', fn () => serveFaviconFile('apple-touch-icon.png', 'image/png'));
$router->get('/assets/images/icon-192.png', fn () => serveFaviconFile('icon-192.png', 'image/png'));
$router->get('/assets/images/icon-512.png', fn () => serveFaviconFile('icon-512.png', 'image/png'));
