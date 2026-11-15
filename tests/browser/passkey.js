// Passkey-Anmeldung Ende-zu-Ende (17.11.2026) mit einem virtuellen Authenticator (Chrome DevTools
// Protocol WebAuthn): registrieren im Profil, abmelden, mit Passkey anmelden (klassisches UND Glas-
// Design), entfernter Passkey wird abgelehnt, Passwort-Login bleibt unberührt.
//
//   BASE=http://localhost:8790 EMAIL=anna@example.test PASSWORD=Test1234! DB="psql ..." node tests/browser/passkey.js
//
// BASE muss mit "localhost" angesprochen werden (IP-Adressen sind keine gültige WebAuthn-rpId).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = process.env.BASE || 'http://localhost:8790';
const EMAIL = process.env.EMAIL, PASSWORD = process.env.PASSWORD;
const DB = process.env.DB; // psql-Aufruf, um das Design umzuschalten und Zeilen zu zählen
let fails = 0;
const ok = (c, msg) => { console.log((c ? '  ✓ ' : '  ✗ ') + msg); if (!c) fails++; };
const sql = q => execSync(`${DB} -At -c "${q}"`).toString().trim();

async function passwordLogin(page) {
  await page.goto(B + '/portal/login');
  await page.fill('#email', EMAIL); await page.fill('#password', PASSWORD);
  await Promise.all([page.waitForURL(u => !/\/portal\/login/.test(u.pathname), { timeout: 15000 }), page.click('form[action="/portal/login"] button[type=submit]')]);
}

(async () => {
  sql('delete from user_passkeys'); sql("update platform_settings set portal_design = 'klassisch'");
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true,
    isUserVerified: true, automaticPresenceSimulation: true } });

  console.log('Registrieren im Profil');
  await passwordLogin(page);
  await page.goto(B + '/portal/profile');
  ok(await page.locator('#passkey-register-box').isVisible(), 'Passkey-Bereich sichtbar');
  await page.fill('#passkey-label', 'Test-iPhone');
  await Promise.all([page.waitForURL(/profile\?success/), page.click('[data-passkey-register]')]);
  ok(await page.locator('text=Test-iPhone').count() > 0, 'Passkey erscheint in der Liste');
  ok((await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials.length === 1, 'Authenticator hält 1 Zugangsdatum');
  ok(sql("select count(*) from user_passkeys") === '1', 'Zeile in der Datenbank');
  ok(!sql("select public_key_pem from user_passkeys").includes('PRIVATE'), 'nur öffentlicher Schlüssel gespeichert');

  for (const design of ['klassisch', 'glas']) {
    console.log('Anmelden mit Passkey -- Design ' + design);
    sql(`update platform_settings set portal_design = '${design}'`);
    await page.goto(B + '/portal/logout');
    await page.goto(B + '/portal/login');
    const btn = page.locator('[data-passkey-login]');
    ok(await btn.isVisible(), 'Knopf "Mit Passkey anmelden" sichtbar');
    await Promise.all([page.waitForURL(/\/(portal\/dashboard|admin)/), btn.click()]);
    ok(/\/(portal\/dashboard|admin)/.test(page.url()), 'ohne Passwort im Portal angemeldet');
    const logged = await page.goto(B + '/portal/profile');
    ok(logged.status() === 200 && !/login/.test(page.url()), 'Sitzung gültig (Profil erreichbar)');
  }
  const cnt = sql("select sign_count || '/' || (last_used_at is not null) from user_passkeys");
  ok(/true$/.test(cnt), 'last_used_at gesetzt (' + cnt + ')');

  console.log('Entfernter Passkey wird abgelehnt');
  await page.goto(B + '/portal/logout'); await page.goto(B + '/portal/login');
  await cdp.send('WebAuthn.clearCredentials', { authenticatorId });
  await page.click('[data-passkey-login]');
  await page.waitForTimeout(1500);
  ok(/\/portal\/login/.test(page.url()), 'bleibt auf der Anmeldeseite');

  console.log('Passkey ohne Eintrag in der Datenbank wird abgelehnt');
  // neuen Passkey anlegen, Zeile serverseitig löschen -> Server kennt ihn nicht mehr
  await passwordLogin(page); await page.goto(B + '/portal/profile');
  await Promise.all([page.waitForURL(/profile\?success/), page.click('[data-passkey-register]')]);
  sql('delete from user_passkeys');
  await page.goto(B + '/portal/logout'); await page.goto(B + '/portal/login');
  await page.click('[data-passkey-login]');
  await page.waitForSelector('[data-passkey-msg]:not(:empty), .lg-alert-inline:not(:empty)', { timeout: 5000 }).catch(() => {});
  ok(/\/portal\/login/.test(page.url()), 'unbekannter Passkey: keine Anmeldung');

  console.log('Passwort-Anmeldung funktioniert weiter');
  await passwordLogin(page);
  ok(!/login/.test(page.url()), 'Passwort-Login ok');

  console.log('Passkey entfernen im Profil');
  await page.goto(B + '/portal/profile');
  await Promise.all([page.waitForURL(/profile\?success/), page.click('[data-passkey-register]')]);
  page.once('dialog', d => d.accept());
  await Promise.all([page.waitForURL(/profile\?success/), page.locator('#passkeys form[action$="/delete"] button').first().click()]);
  ok(sql('select count(*) from user_passkeys') === '0', 'Passkey entfernt');

  sql("update platform_settings set portal_design = 'klassisch'");
  await browser.close();
  console.log(fails ? `\n${fails} Prüfung(en) fehlgeschlagen` : '\nAlles ok');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
