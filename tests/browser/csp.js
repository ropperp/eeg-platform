// CSP-Prüfung im echten Browser (17.11.2026): die Plattform sendet jetzt `script-src 'self' 'nonce-…'` ohne
// 'unsafe-inline' und `script-src-attr 'none'`. Dieser Test besucht Seiten aller Rollen und schlägt fehl bei
// JEDER CSP-Verletzung (blockiertes Inline-Skript, on*-Attribut, javascript:-Link) und bei JS-Fehlern; danach
// prüft er, dass die ersetzten Bedienelemente (ui-actions.js) wirklich funktionieren.
//
//   BASE=http://localhost:8790 EMAIL=admin@example.test MEMBER_EMAIL=anna@example.test PASSWORD=Test1234! node tests/browser/csp.js
const { chromium } = require('playwright');
const B = process.env.BASE || 'http://localhost:8790';
const PASSWORD = process.env.PASSWORD;
let fails = 0;
const ok = (c, msg) => { console.log((c ? '  ✓ ' : '  ✗ ') + msg); if (!c) fails++; };

async function login(ctx, email) {
  const page = await ctx.newPage();
  const problems = [];
  await page.addInitScript(() => {
    window.__csp = [];
    document.addEventListener('securitypolicyviolation', e => window.__csp.push(e.violatedDirective + ' ' + (e.sample || e.blockedURI)));
  });
  page.on('pageerror', e => problems.push('JS-Fehler: ' + e.message));
  page.on('console', m => { if (/Content Security Policy|Refused to/i.test(m.text())) problems.push('CSP: ' + m.text().slice(0, 160)); });
  await page.goto(B + '/portal/login');
  await page.fill('#email', email); await page.fill('#password', PASSWORD);
  await Promise.all([page.waitForURL(u => !/\/portal\/login/.test(u.pathname), { timeout: 15000 }), page.click('form[action="/portal/login"] button[type=submit]')]);
  // Hinweis-Popup der Mitgliederansicht (Pre-Launch) quittieren, falls vorhanden
  const ack = page.locator('form[action="/portal/ack-prelaunch"] button');
  if (await ack.count()) await Promise.all([page.waitForLoadState(), ack.first().click()]);
  return { page, problems };
}
async function visit(page, problems, urls) {
  for (const u of urls) {
    const before = problems.length;
    const r = await page.goto(B + u, { waitUntil: 'load' });
    await page.waitForTimeout(250);
    const v = await page.evaluate(() => window.__csp || []);
    v.forEach(x => problems.push('Verletzung: ' + x));
    const st = r ? r.status() : 0;
    // TOLERATE_5XX=1: Testumgebung mit einfachem Postgres ohne TimescaleDB (time_bucket fehlt) -> Seiten mit
    // Serverfehler zählen nicht als CSP-Fehler, werden aber ausgewiesen
    if (st >= 500 && process.env.TOLERATE_5XX) { console.log(`  - ${u} (HTTP ${st}) übersprungen: Serverfehler der Testumgebung`); continue; }
    ok(problems.length === before && st < 500, `${u} (HTTP ${st}) ohne CSP-Verletzung/JS-Fehler` + (problems.length > before ? ' -> ' + problems.slice(before).join(' | ') : ''));
  }
}
const spy = (page, name) => page.evaluate(n => { window.__calls = window.__calls || {}; const o = window[n]; window[n] = function () { window.__calls[n] = (window.__calls[n] || 0) + 1; return o && o.apply(this, arguments); }; }, name);
const calls = (page, n) => page.evaluate(k => (window.__calls || {})[k] || 0, n);

(async () => {
  const browser = await chromium.launch();

  console.log('Öffentliche Seiten und Login (beide Designs)');
  const anon = await browser.newContext(); const ap = await anon.newPage(); const aprob = [];
  await ap.addInitScript(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', e => window.__csp.push(e.violatedDirective + ' ' + (e.sample || e.blockedURI))); });
  ap.on('pageerror', e => aprob.push('JS-Fehler: ' + e.message));
  const hdr = await ap.goto(B + '/portal/login');
  const csp = hdr.headers()['content-security-policy'] || '';
  ok(/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{24}'/.test(csp) && !/script-src[^;]*unsafe-inline/.test(csp) && /script-src-attr 'none'/.test(csp), 'CSP-Header: Nonce, kein unsafe-inline, script-src-attr none');
  const nonce1 = (csp.match(/nonce-([^']+)/) || [])[1];
  const nonce2 = ((await ap.goto(B + '/portal/login')).headers()['content-security-policy'].match(/nonce-([^']+)/) || [])[1];
  ok(nonce1 && nonce2 && nonce1 !== nonce2, 'Nonce wechselt bei jeder Antwort');
  ok((csp.match(/Content-Security/gi) || []).length === 0 && !hdr.headers()['content-security-policy'].includes(', '), 'genau eine CSP');
  await visit(ap, aprob, ['/', '/beitreten', '/barrierefreiheit', '/portal/login', '/portal/forgot-password', '/live/strompool']);
  // Test eingeschleustes Inline-Skript: darf NICHT laufen
  await ap.goto(B + '/portal/login');
  const ran = await ap.evaluate(() => new Promise(res => {
    window.__x = false;
    const s = document.createElement('script'); s.textContent = 'window.__x = true';
    document.body.appendChild(s); setTimeout(() => res(window.__x), 200);
  }));
  ok(ran === false, 'eingeschleustes <script> ohne Nonce wird blockiert');
  const ran2 = await ap.evaluate(() => new Promise(res => {
    window.__y = false; const i = document.createElement('img'); i.src = 'x:'; i.setAttribute('onerror', 'window.__y=true'); document.body.appendChild(i); setTimeout(() => res(window.__y), 300);
  }));
  ok(ran2 === false, 'eingeschleustes onerror-Attribut wird blockiert');
  await anon.close();

  console.log('Plattform-Admin');
  const actx = await browser.newContext();
  const { page: p, problems } = await login(actx, process.env.EMAIL);
  // zuletzt aktive Rolle wird gemerkt -> zuerst sicher auf Plattform-Admin schalten (testet zugleich data-onchange)
  const roleValue = re => p.locator('select[data-onchange="switchRole"] option').evaluateAll((os, src) => os.map(o => o.value).find(v => new RegExp(src).test(v)), re.source);
  await p.goto(B + '/portal/dashboard');
  if (!/\/admin/.test(p.url())) {
    const adm = await roleValue(/\|platform_admin\|/);
    await Promise.all([p.waitForNavigation(), p.selectOption('select[data-onchange="switchRole"]', adm)]);
  }
  await visit(p, problems, ['/admin', '/admin/members', '/admin/log', '/admin/mail-settings', '/admin/backups', '/admin/templates', '/portal/profile', '/portal/password']);

  console.log('Rollenwechsel (Auswahlfeld) und Obmann-Seiten');
  await p.goto(B + '/admin');
  const sel = p.locator('select[data-onchange="switchRole"]');
  ok(await sel.count() === 1, 'Rollenwahl vorhanden');
  const mgr = await roleValue(/\|manager\|/);
  await Promise.all([p.waitForNavigation(), sel.selectOption(mgr)]);
  ok(!/\/admin/.test(p.url()), 'Rollenwechsel zum Obmann per data-onchange (' + p.url().replace(B, '') + ')');
  const mid = 'bbbbbbbb-0000-0000-0000-000000000001';
  await visit(p, problems, ['/portal/dashboard', '/portal/members', '/portal/members/new', '/portal/members/' + mid, '/portal/files', '/portal/billing', '/portal/billing/invoices',
    '/portal/billing/gutschriften', '/portal/settings', '/portal/dokumente', '/portal/eda/upload', '/portal/support', '/portal/postfach', '/portal/applications', '/portal/profile', '/portal/password', '/portal/metering-points/unassigned']);

  console.log('Bedienelemente (ersetzen frühere Inline-Handler)');
  await p.goto(B + '/portal/members');
  await spy(p, 'sortMembers'); await spy(p, 'filterMembers');
  await p.click('th[data-sort-key="name"]');
  ok(await calls(p, 'sortMembers') === 1, 'Spaltenkopf sortieren (data-onclick + @this)');
  await p.fill('#member-search, input[data-oninput="filterMembers"]', 'Huber');
  ok(await calls(p, 'filterMembers') >= 1, 'Suchfeld filtert (data-oninput)');
  await p.selectOption('#filter-status', { index: 1 }).catch(() => {});
  ok(await calls(p, 'filterMembers') >= 2, 'Filter-Auswahl (data-onchange)');

  await p.goto(B + '/portal/dashboard');
  await p.click('#theme-toggle');
  const t1 = await p.evaluate(() => document.documentElement.getAttribute('data-theme'));
  await p.click('#theme-toggle');
  const t2 = await p.evaluate(() => document.documentElement.getAttribute('data-theme'));
  ok(t1 !== t2, 'Hell/Dunkel umschalten (' + t1 + ' -> ' + t2 + ')');
  await p.click('.profile-btn'); await p.waitForTimeout(300);
  ok(await p.evaluate(() => document.getElementById('profile-dropdown').classList.contains('open')), 'Profilmenü bleibt offen (stopPropagation in Capture-Phase)');
  await p.click('body', { position: { x: 5, y: 400 } }); await p.waitForTimeout(200);
  ok(!(await p.evaluate(() => document.getElementById('profile-dropdown').classList.contains('open'))), 'Klick daneben schließt das Profilmenü');

  await p.goto(B + '/portal/members/' + mid);
  await p.click('[data-dialog-open="photo-dialog"]');
  ok(await p.evaluate(() => document.getElementById('photo-dialog').open), 'Dialog öffnen (data-dialog-open)');
  await p.click('[data-dialog-close="photo-dialog"]');
  ok(!(await p.evaluate(() => document.getElementById('photo-dialog').open)), 'Dialog schließen (data-dialog-close)');
  // Bestätigungen: Abbrechen darf nichts absenden
  const conf = p.locator('form[data-confirm], button[data-confirm]').first();
  if (await conf.count()) {
    let msg = ''; p.once('dialog', d => { msg = d.message(); d.dismiss(); });
    const url0 = p.url();
    // Absenden auslösen (Formular direkt oder Knopf anklicken) und prüfen, ob "submit" überhaupt zugestellt wird
    await p.evaluate(() => { const el = document.querySelector('form[data-confirm], button[data-confirm]'); const f = el.tagName === 'FORM' ? el : el.form; window.__submitted = false; f.addEventListener('submit', e => { window.__submitted = !e.defaultPrevented; }); });
    if (await conf.evaluate(el => el.tagName) === 'FORM') await conf.evaluate(el => el.requestSubmit());
    else await conf.evaluate(el => el.click());
    await p.waitForTimeout(400);
    ok(msg.length > 5 && p.url() === url0, 'Bestätigung abgebrochen -> Seite bleibt, nichts abgesendet ("' + msg.slice(0, 40) + '…")');
  } else console.log('  - keine data-confirm-Elemente auf dieser Seite');
  const danger = p.locator('[data-danger-confirm]').first();
  if (await danger.count()) {
    let m = ''; p.once('dialog', d => { m = d.message(); d.dismiss(); });
    await danger.evaluate(el => { const f = el.tagName === 'FORM' ? el : el.form; f.addEventListener('submit', () => { window.__submitted2 = true; }); if (el.tagName === 'FORM') el.requestSubmit(); else el.click(); });
    await p.waitForTimeout(300);
    ok(/LOESCHEN/.test(m) && !(await p.evaluate(() => window.__submitted2)), 'Lösch-Abfrage mit "LOESCHEN"-Eingabe abgebrochen -> nichts gelöscht');
  }

  await p.goto(B + '/portal/eda/upload');
  const [fc] = await Promise.all([p.waitForEvent('filechooser', { timeout: 3000 }).catch(() => null), p.click('#upload-zone')]);
  ok(!!fc, 'Upload-Fläche öffnet die Dateiauswahl (data-click-target)');
  await p.goto(B + '/portal/password');
  await spy(p, 'checkStrength'); await p.fill('input[data-oninput="checkStrength"]', 'abcDEF123!');
  ok(await calls(p, 'checkStrength') >= 1, 'Passwortstärke (data-oninput + @value)');
  await p.goto(B + '/portal/settings');
  if (await p.locator('#tax-model-select').count()) {
    const setTax = v => p.evaluate(val => { const e = document.getElementById('tax-model-select'); e.value = val; e.dispatchEvent(new Event('change', { bubbles: true })); return document.getElementById('tax-rate-field').style.display; }, v);
    const d1 = await setTax('standard');
    const other = await p.evaluate(() => [...document.getElementById('tax-model-select').options].map(o => o.value).find(v => v !== 'standard'));
    const d2 = await setTax(other);
    ok(d1 === 'block' && d2 === 'none', 'Steuer-Satz-Feld ein-/ausblenden (data-toggle-target)');
  }
  const urls2 = ['/portal/billing/invoices'];
  await p.goto(B + urls2[0]); await spy(p, 'filterInvoices'); await p.fill('#invoice-search', 'x');
  ok(await calls(p, 'filterInvoices') >= 1, 'Rechnungsfilter (data-oninput)');
  const ret = await p.evaluate(() => { const a = document.querySelector('[data-onclick="downloadInvoiceZip"]'); const ev = new MouseEvent('click', { bubbles: true, cancelable: true }); window.alert = () => {}; a.dispatchEvent(ev); return ev.defaultPrevented; });
  ok(ret === true, 'data-return: Funktion liefert false -> Standardaktion (Sprung auf #) abgebrochen');

  await actx.close();

  console.log('Mitglied');
  const mctx = await browser.newContext();
  const { page: mp, problems: mprob } = await login(mctx, process.env.MEMBER_EMAIL);
  await visit(mp, mprob, ['/portal/dashboard', '/portal/invoices', '/portal/my/verbrauch', '/portal/my/einspeisung', '/portal/my/documents', '/portal/my/jahresuebersicht',
    '/portal/my/contract/bezug', '/portal/my/api-keys', '/portal/my/support', '/portal/profile', '/portal/password']);
  await mctx.close();

  await browser.close();
  console.log(fails ? `\n${fails} Prüfung(en) fehlgeschlagen` : '\nAlles ok');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
