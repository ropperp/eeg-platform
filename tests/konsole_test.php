<?php

declare(strict_types=1);

// Steuerzentrale (konsole/): Anmeldung per Passkey (nachgebauter ES256-Authenticator), Sitzung,
// CSRF, Wartungsmodus inkl. Traefik-Datei, Host-Trennung, Dienst-Liste, Docker-Log-Format.

use Konsole\App;
use Konsole\AuditLog;
use Konsole\Config;
use Konsole\Docker;
use Konsole\Maintenance;
use Konsole\PasskeyStore;
use Konsole\Response;
use Konsole\Tokens;

function kz_env(): array
{
    $dir = sys_get_temp_dir() . '/konsole-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/traefik', 0777, true);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $credId = WebAuthn::b64uEncode(random_bytes(16));
    $db = ['up' => true, 'rows' => [[
        'credential_id' => $credId, 'public_key_pem' => openssl_pkey_get_details($key)['key'], 'sign_count' => 0,
        'user_id' => 'u-1', 'name' => 'Test Admin',
    ]]];
    $config = new Config('stromfueralle.at', 'admin.stromfueralle.at', str_repeat('s', 64), $dir, $dir . '/traefik', ['webapp', 'redis'], false,
        '10.0.0.144', $dir . '/kein-socket', 'pgsql:host=none', '', '');
    $store = new PasskeyStore($dir . '/passkeys.json', function () use (&$db) { return $db['up'] ? $db['rows'] : null; });
    $app = new App($config, $store, new Docker($config->dockerSocket, $config->services),
        new Maintenance($dir . '/maintenance.json', $dir . '/traefik/maintenance.yml', $config), new AuditLog($dir . '/audit.log'));
    $env = ['dir' => $dir, 'key' => $key, 'cred' => $credId, 'app' => $app, 'cookies' => []];
    $env['db'] = &$db;
    return $env;
}

/** Anfrage an die App; Cookies aus Antworten werden wie im Browser gemerkt. */
function kz_req(array &$env, string $method, string $path, array $post = [], string $body = '', string $host = 'admin.stromfueralle.at', array $extra = []): Response
{
    $server = $extra + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'HTTP_HOST' => $host, 'REMOTE_ADDR' => '10.0.0.144',
        'QUERY_STRING' => (string) parse_url($path, PHP_URL_QUERY)];
    $r = $env['app']->handle($server, $post, $env['cookies'], $body);
    foreach ($r->cookies() as [$n, $v, $ttl]) {
        if ($ttl < 0) unset($env['cookies'][$n]); else $env['cookies'][$n] = $v;
    }
    return $r;
}

function kz_login(array &$env, int $count = 1, ?string $credId = null, string $origin = 'https://admin.stromfueralle.at'): Response
{
    $o = json_decode(kz_req($env, 'POST', '/login/options')->body, true);
    $cd = json_encode(['type' => 'webauthn.get', 'challenge' => $o['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
    $authData = hash('sha256', 'stromfueralle.at', true) . chr(0x05) . pack('N', $count);
    openssl_sign($authData . hash('sha256', $cd, true), $sig, $env['key'], OPENSSL_ALGO_SHA256);
    $b = fn($x) => WebAuthn::b64uEncode($x);
    return kz_req($env, 'POST', '/login/verify', [], json_encode(['id' => $credId ?? $env['cred'], 'response' => [
        'clientDataJSON' => $b($cd), 'authenticatorData' => $b($authData), 'signature' => $b($sig), 'userHandle' => $b('u-1'),
    ]]));
}

function kz_csrf(Response $page): string
{
    preg_match('/name="csrf" value="([0-9a-f]{64})"/', $page->body, $m);
    return $m[1] ?? '';
}

test('Konsole: signierte Tokens lehnen Manipulation und Ablauf ab', function () {
    $t = new Tokens(str_repeat('k', 32));
    $tok = $t->issue(['uid' => 'a'], 60, 1000);
    assertSame('a', $t->read($tok, 1001)['uid']);
    assertSame(null, $t->read($tok, 2000), 'abgelaufen');
    assertSame(null, $t->read(str_replace('.', 'x.', $tok), 1001), 'verändert');
    assertSame(null, (new Tokens(str_repeat('z', 32)))->read($tok, 1001), 'anderer Schlüssel');
    assertSame(null, $t->read('kaputt', 1001));
});

test('Konsole: Health, Wartungstext mit CORS nur für eigene Hosts, fremde Hosts sehen Wartungsseite', function () {
    $env = kz_env();
    assertSame('OK', kz_req($env, 'GET', '/health', [], '', 'irgendwas')->body);
    $j = kz_req($env, 'GET', '/wartung.json', [], '', 'admin.stromfueralle.at', ['HTTP_ORIGIN' => 'https://stromfueralle.at']);
    assertSame(false, json_decode($j->body, true)['active']);
    assertSame('https://stromfueralle.at', $j->headerValue('Access-Control-Allow-Origin'));
    $evil = kz_req($env, 'GET', '/wartung.json', [], '', 'admin.stromfueralle.at', ['HTTP_ORIGIN' => 'https://boese.example']);
    assertSame(null, $evil->headerValue('Access-Control-Allow-Origin'));
    $page = kz_req($env, 'GET', '/', [], '', 'stromfueralle.at');
    assertSame(503, $page->status);
    assertContains('Wartungsarbeiten', $page->body);
    assertSame('1800', $page->headerValue('Retry-After'));
    assertFalse(str_contains($page->body, 'Passkey'), 'keine Anmeldung auf fremdem Host');
});

test('Konsole: ohne Anmeldung nur die Login-Seite, Aktionen 401', function () {
    $env = kz_env();
    $r = kz_req($env, 'GET', '/');
    assertSame(200, $r->status);
    assertContains('Mit Passkey anmelden', $r->body);
    assertSame(401, kz_req($env, 'POST', '/neustart', ['dienst' => 'webapp'])->status);
    assertSame(303, kz_req($env, 'GET', '/logs?dienst=webapp')->status);
});

test('Konsole: Anmeldung per Passkey; fremder Schlüssel, falsche Herkunft und Wiederholung abgelehnt', function () {
    $env = kz_env();
    assertSame(401, kz_login($env, 1, WebAuthn::b64uEncode('unbekannt'))->status, 'unbekannter Passkey');
    assertSame(401, kz_login($env, 1, null, 'https://boese.example')->status, 'falsche Herkunft');
    $ok = kz_login($env, 5);
    assertSame(200, $ok->status);
    assertTrue(isset($env['cookies']['__Host-konsole_s']), 'Sitzungs-Cookie mit __Host-Präfix');
    $dash = kz_req($env, 'GET', '/');
    assertContains('Angemeldet als <strong>Test Admin</strong>', $dash->body);
    assertContains('Docker nicht erreichbar', $dash->body, 'ohne Socket: Hinweis statt Absturz');
    unset($env['cookies']['__Host-konsole_s']);
    assertSame(401, kz_login($env, 5)->status, 'gleicher Zähler = kopierter Schlüssel/Wiederholung');
    $log = file_get_contents($env['dir'] . '/audit.log');
    assertContains('"aktion":"anmeldung"', $log);
    assertContains('anmeldung_fehlgeschlagen', $log);
});

test('Konsole: Anmeldung funktioniert auch bei Datenbank-Ausfall (Passkey-Kopie)', function () {
    $env = kz_env();
    kz_req($env, 'GET', '/');                     // nichts gespiegelt bisher
    assertSame(200, kz_login($env, 1)->status);   // DB erreichbar -> Kopie geschrieben
    unset($env['cookies']['__Host-konsole_s']);
    $env['db']['up'] = false;
    assertSame(200, kz_login($env, 2)->status, 'Anmeldung aus der Kopie');
    unset($env['cookies']['__Host-konsole_s']);
    assertSame(401, kz_login($env, 2)->status, 'Zähler in der Kopie fortgeschrieben');
});

test('Konsole: Wartungsmodus nur mit CSRF; schreibt und entfernt die Traefik-Datei', function () {
    $env = kz_env();
    kz_login($env);
    $csrf = kz_csrf(kz_req($env, 'GET', '/'));
    assertSame(64, strlen($csrf));
    assertSame(403, kz_req($env, 'POST', '/wartung', ['aktion' => 'ein', 'text' => 'x'])->status);
    $r = kz_req($env, 'POST', '/wartung', ['csrf' => $csrf, 'aktion' => 'ein', 'text' => "Update <b>läuft</b>", 'bis' => '2099-01-01T20:00']);
    assertSame(303, $r->status);
    $yml = file_get_contents($env['dir'] . '/traefik/maintenance.yml');
    assertContains('Host(`stromfueralle.at`) || Host(`www.stromfueralle.at`) || Host(`portal.stromfueralle.at`)', $yml);
    assertContains('http://konsole:8090', $yml);
    assertFalse(str_contains($yml, 'admin.'), 'Konsole selbst bleibt erreichbar');
    $page = kz_req($env, 'GET', '/', [], '', 'portal.stromfueralle.at');
    assertContains('Update &lt;b&gt;läuft&lt;/b&gt;', $page->body, 'Text escaped');
    assertContains('01.01.2099, 20:00', $page->body);
    assertSame('86400', $page->headerValue('Retry-After'));
    assertSame(true, json_decode(kz_req($env, 'GET', '/wartung.json')->body, true)['active']);
    assertSame(303, kz_req($env, 'POST', '/wartung', ['csrf' => $csrf, 'aktion' => 'ein', 'bis' => 'morgen'])->status);
    assertContains('fehler=', kz_req($env, 'POST', '/wartung', ['csrf' => $csrf, 'aktion' => 'ein', 'bis' => 'morgen'])->headerValue('Location'));
    kz_req($env, 'POST', '/wartung', ['csrf' => $csrf, 'aktion' => 'aus']);
    assertFalse(is_file($env['dir'] . '/traefik/maintenance.yml'));
    assertSame(false, json_decode(kz_req($env, 'GET', '/wartung.json')->body, true)['active']);
});

test('Konsole: Neustart und Logs nur für Dienste der festen Liste', function () {
    $env = kz_env();
    kz_login($env);
    $csrf = kz_csrf(kz_req($env, 'GET', '/'));
    assertContains('Unbekannter', urldecode((string) kz_req($env, 'POST', '/neustart', ['csrf' => $csrf, 'dienst' => 'portainer'])->headerValue('Location')));
    assertContains('Neustart von webapp fehlgeschlagen', urldecode((string) kz_req($env, 'POST', '/neustart', ['csrf' => $csrf, 'dienst' => 'webapp'])->headerValue('Location')));
    assertSame(404, kz_req($env, 'GET', '/logs?dienst=' . rawurlencode('../containers/x'))->status);
    $d = new Docker('/x', ['webapp']);
    assertTrue($d->isAllowed('webapp'));
    assertFalse($d->isAllowed('webapp/../x'));
    try { $d->restart('redis'); throw new Exception('nicht erlaubter Dienst wurde angenommen'); } catch (InvalidArgumentException) {}
});

test('Konsole: Docker-Logformat (Multiplex) wird korrekt zerlegt', function () {
    $frame = fn(int $stream, string $s) => chr($stream) . "\0\0\0" . pack('N', strlen($s)) . $s;
    assertSame("Zeile 1\nFehler\n", Docker::demux($frame(1, "Zeile 1\n") . $frame(2, "Fehler\n")));
    assertSame("Klartext von TTY\n", Docker::demux("Klartext von TTY\n"));
    assertSame('', Docker::demux(''));
});
