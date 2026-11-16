<?php

declare(strict_types=1);

// AppConfig: einzige Quelle für Domain, Portal-Host, URLs und Mail-Fallback.

test('AppConfig Produktion: Standardwerte aus DOMAIN abgeleitet', function () {
    $c = AppConfig::fromEnv(['DOMAIN' => 'stromfueralle.at']);
    assertSame('portal.stromfueralle.at', $c->portalHost);
    assertSame('office@stromfueralle.at', $c->contactEmail);
    assertSame('https://stromfueralle.at', $c->marketingBaseUrl());
    assertSame('https://portal.stromfueralle.at', $c->portalBaseUrl());
    assertTrue($c->production);
});

test('AppConfig: leere DOMAIN fällt auf stromfueralle.at zurück, Schema/Pfad werden entfernt', function () {
    assertSame('stromfueralle.at', AppConfig::fromEnv([])->domain);
    assertSame('beispiel.at', AppConfig::fromEnv(['DOMAIN' => 'https://Beispiel.at/'])->domain);
});

test('AppConfig: PORTAL_HOST und CONTACT_EMAIL überschreibbar', function () {
    $c = AppConfig::fromEnv(['DOMAIN' => 'beispiel.at', 'PORTAL_HOST' => 'login.beispiel.at', 'CONTACT_EMAIL' => 'info@beispiel.at']);
    assertSame('https://login.beispiel.at', $c->portalBaseUrl());
    assertSame('info@beispiel.at', $c->contactEmail);
});

test('AppConfig: portalUrl/marketingUrl wie bisher (relativ auf dem eigenen Host, sonst absolut)', function () {
    $c = AppConfig::fromEnv(['DOMAIN' => 'stromfueralle.at']);
    assertSame('/portal/x', $c->portalUrl('/portal/x', 'portal.stromfueralle.at'));
    assertSame('https://portal.stromfueralle.at/portal/x', $c->portalUrl('/portal/x', 'stromfueralle.at'));
    assertSame('/agb', $c->marketingUrl('/agb', 'www.stromfueralle.at'));
    assertSame('/agb', $c->marketingUrl('/agb', 'stromfueralle.at:443'));
    assertSame('https://stromfueralle.at/agb', $c->marketingUrl('/agb', 'portal.stromfueralle.at'));
});

test('AppConfig: Host-Erkennung für die Domain-Trennung', function () {
    $c = AppConfig::fromEnv(['DOMAIN' => 'stromfueralle.at']);
    assertTrue($c->isMarketingHost('stromfueralle.at'));
    assertTrue($c->isMarketingHost('WWW.stromfueralle.at'));
    assertFalse($c->isMarketingHost('live.stromfueralle.at'));
    assertTrue($c->isPortalHost('portal.stromfueralle.at:80'));
    assertFalse($c->isPortalHost('admin.stromfueralle.at'));
});

test('AppConfig: Cookie-Domain nur für die eigene Domain, nicht für ähnlich klingende Hosts', function () {
    $c = AppConfig::fromEnv(['DOMAIN' => 'stromfueralle.at']);
    assertSame('.stromfueralle.at', $c->cookieDomainFor('portal.stromfueralle.at'));
    assertSame('.stromfueralle.at', $c->cookieDomainFor('stromfueralle.at'));
    assertSame('', $c->cookieDomainFor('evilstromfueralle.at'));
    assertSame('', $c->cookieDomainFor('localhost'));
});

test('AppConfig lokal (localhost/IP): keine Domain-Trennung, Links relativ', function () {
    foreach (['localhost', '127.0.0.1', 'eeg.localhost'] as $d) {
        $c = AppConfig::fromEnv(['DOMAIN' => $d, 'APP_ENV' => 'development']);
        assertFalse($c->domainSplit(), $d);
        assertSame('/portal/x', $c->portalUrl('/portal/x', $d));
        assertFalse($c->isMarketingHost($d), $d);
        assertSame('', $c->cookieDomainFor($d));
        assertFalse($c->production);
    }
    assertSame('http://localhost', AppConfig::fromEnv(['DOMAIN' => 'localhost'])->portalBaseUrl());
});
