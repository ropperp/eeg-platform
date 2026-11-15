// Zentrierungs-Prüfung (Patrick, 15.11.2026: "Schau bitte, dass du dir immer dort, wo es sein
// soll, einen Punkt machst und da dann textuell zentriert arbeitest").
//
// Misst auf den Startseiten jeden Textblock mit text-align:center: liegt die Mitte jeder Textzeile
// (inkl. Symbol davor) auf der Mitte ihres Kastens, und ragt der Text nicht über den Kasten
// hinaus? Am Laptop, Tablet und Handy. Braucht eine laufende Plattform und Playwright:
//
//   BASE=http://localhost ADMIN_EMAIL=... MEMBER_EMAIL=... PASSWORD=... node tests/browser/zentrierung.js
//
// Ausgabe je Seite "ok" oder die betroffenen Elemente mit Versatz in Pixeln. Exit-Code 1 bei Befund.
const { chromium, devices } = require('playwright');
const B = process.env.BASE || 'http://localhost';
const PASSWORD = process.env.PASSWORD || '';
const USERS = [
  [process.env.ADMIN_EMAIL, ['/admin', '/admin/members']],
  [process.env.MEMBER_EMAIL, ['/portal/dashboard']],
].filter(([email]) => email);

const check = () => {
  const out = [];
  const isBlockish = el => { const d = getComputedStyle(el).display; return !/^inline/.test(d) && d !== 'contents'; };
  document.querySelectorAll('body *').forEach(el => {
    const cs = getComputedStyle(el);
    if (cs.textAlign !== 'center' || cs.display === 'none' || cs.visibility === 'hidden' || /^inline/.test(cs.display)) return;
    if (el.closest('svg, select, option, script, style')) return;
    // nur "Textblöcke": alle Textknoten, deren nächster Block-Vorfahr dieses Element ist
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const rects = [];
    let n;
    while ((n = walker.nextNode())) {
      if (!n.textContent.trim()) continue;
      let p = n.parentElement;
      while (p !== el && !isBlockish(p)) p = p.parentElement;
      if (p !== el) continue;
      if (getComputedStyle(n.parentElement).position === 'absolute') continue;
      const r = document.createRange(); r.selectNodeContents(n);
      rects.push(...[...r.getClientRects()].filter(x => x.width > 1));
    }
    if (!rects.length) return;
    const box = el.getBoundingClientRect(); if (!box.width) return;
    const L0 = box.left + parseFloat(cs.paddingLeft), R0 = box.right - parseFloat(cs.paddingRight), C0 = (L0 + R0) / 2;
    // Zeilen über vertikale Überlappung zusammenfassen
    const rows = [];
    rects.sort((a, b) => a.top - b.top).forEach(rc => {
      const row = rows.find(r => rc.top < r.bottom - 2 && rc.bottom > r.top + 2);
      if (row) { row.left = Math.min(row.left, rc.left); row.right = Math.max(row.right, rc.right); row.top = Math.min(row.top, rc.top); row.bottom = Math.max(row.bottom, rc.bottom); }
      else rows.push({ left: rc.left, right: rc.right, top: rc.top, bottom: rc.bottom });
    });
    // Symbole (svg) in derselben Zeile mit einbeziehen
    el.querySelectorAll('svg').forEach(sv => {
      const sr = sv.getBoundingClientRect(); if (!sr.width) return;
      let p = sv.parentElement; while (p !== el && !isBlockish(p)) p = p.parentElement; if (p !== el) return;
      const row = rows.find(r => sr.top < r.bottom && sr.bottom > r.top);
      if (row) { row.left = Math.min(row.left, sr.left); row.right = Math.max(row.right, sr.right); }
    });
    rows.forEach(row => {
      const off = (row.left + row.right) / 2 - C0;
      const overflow = (row.right - row.left) - (R0 - L0);
      if (Math.abs(off) > 3 || overflow > 1) {
        out.push(`${el.tagName.toLowerCase()}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''} "${el.textContent.trim().replace(/\s+/g, ' ').slice(0, 40)}" Versatz ${off.toFixed(1)}px${overflow > 1 ? ', ragt ' + overflow.toFixed(0) + 'px über den Kasten' : ''}`);
      }
    });
  });
  return [...new Set(out)];
};
(async () => {
  const b = await chromium.launch();
  let befunde = 0;
  for (const [dev, opts] of [['Laptop', { viewport: { width: 1400, height: 1000 } }], ['Tablet', { viewport: { width: 820, height: 1000 } }], ['Handy', devices['iPhone 13']]]) {
    for (const [who, pages] of USERS) {
      const ctx = await b.newContext(opts); const p = await ctx.newPage();
      await p.goto(B + '/portal/login'); await p.fill('#email', who); await p.fill('#password', PASSWORD);
      await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
      const ack = await p.$('form[action="/portal/ack-prelaunch"] button'); if (ack) await Promise.all([p.waitForNavigation(), ack.click()]);
      for (const path of pages) {
        await p.goto(B + path); await p.waitForTimeout(300);
        const res = await p.evaluate(check);
        befunde += res.length;
        console.log(`[${dev}] ${path}: ${res.length ? '\n   - ' + res.join('\n   - ') : 'ok'}`);
      }
      await ctx.close();
    }
  }
  await b.close();
  process.exit(befunde ? 1 : 0);
})();
