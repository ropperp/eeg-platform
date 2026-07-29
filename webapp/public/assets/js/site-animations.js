/**
 * Dezente, professionelle Bewegung für die öffentliche Marketing-Seite (Hero-Einstieg),
 * mit GSAP (self-hosted, siehe assets/js/vendor/) statt eigenem Timing-Code.
 *
 * Bewusst NICHT auf dem Backoffice (Portal/Admin) eingebunden: dort geht es um schnelles,
 * wiederholtes Arbeiten (Mitgliederlisten, Rechnungen) -- Animation würde dort nur bremsen,
 * nicht wirken. Der bestehende Scroll-Reveal-Mechanismus (.reveal/.reveal-grid, siehe
 * layouts/base.php) bleibt unverändert; dieses Script ergänzt nur den Hero-Einstieg.
 *
 * Ausfallsicher: Die Klasse "js-anim" (synchron in <head> gesetzt) blendet den Hero-Text
 * per CSS aus (siehe app.css). Lädt GSAP aus irgendeinem Grund nicht, wird "js-anim" hier
 * sofort wieder entfernt -- der Hero bleibt dann einfach dauerhaft sichtbar, nie unsichtbar.
 */
(function () {
  var html = document.documentElement;

  if (!window.gsap) {
    html.classList.remove('js-anim');
    return;
  }
  if (window.ScrollTrigger) {
    gsap.registerPlugin(ScrollTrigger);
  }

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hero = document.querySelector('.hero');

  // ─── Hero-Einstieg ──────────────────────────────────────────────────────
  if (hero && !reduceMotion) {
    // fromTo (nicht from!): app.css setzt den Hero-Text bereits per CSS auf opacity:0
    // (verhindert ein kurzes Aufblitzen vor dem Laden dieses Scripts). Ein
    // .from({opacity:0,...}) würde den "aktuellen" (bereits 0) Zustand als Ziel interpretieren
    // und gar nicht animieren -- deshalb hier Start- UND Endwert explizit angeben.
    gsap.timeline({ defaults: { ease: 'power3.out', duration: .7 } })
      .fromTo(hero.querySelectorAll('h1, p, a.btn'), { opacity: 0, y: 20 }, { opacity: 1, y: 0, stagger: .12 });
  }
  // Ohne JS/GSAP bzw. bei reduzierter Bewegung erzwingt @media prefers-reduced-motion in
  // app.css ohnehin schon opacity:1 -- js-anim bleibt dann einfach ungenutzt.

  // ─── Magnetische Buttons (nur Hero-CTAs) ────────────────────────────────
  // Der Button folgt der Maus minimal beim Hover und federt beim Verlassen sanft zurück --
  // dezenter "Wow"-Effekt, wie man ihn von Stripe/Linear kennt. Bewusst nur im Hero (2 Buttons),
  // nicht plattformweit -- soll auffallen, nicht überall gleich wirken.
  if (hero && !reduceMotion) {
    hero.querySelectorAll('a.btn').forEach(function (btn) {
      var strength = 0.35;
      btn.addEventListener('mousemove', function (e) {
        var r = btn.getBoundingClientRect();
        var x = (e.clientX - r.left - r.width / 2) * strength;
        var y = (e.clientY - r.top - r.height / 2) * strength;
        gsap.to(btn, { x: x, y: y, duration: .3, ease: 'power2.out' });
      });
      btn.addEventListener('mouseleave', function () {
        gsap.to(btn, { x: 0, y: 0, duration: .5, ease: 'elastic.out(1, .4)' });
      });
    });
  }

  // ─── Live-Zähler (Pilotprojekt-Kennzahlen) ──────────────────────────────
  // Holt dieselben Live-Werte wie die /live-Seite (siehe /api/live/:slug) und zählt sie beim
  // Erscheinen sanft von 0 hoch statt sie einfach hinzuklatschen. Rein clientseitig, damit die
  // Startseite selbst weiterhin ohne DB-Zugriff auskommt -- schlägt der Abruf fehl (z.B. DB
  // gerade nicht erreichbar, Pilot-EEG umbenannt), bleibt die Zeile einfach unsichtbar.
  var liveStats = document.getElementById('pilot-live-stats');
  if (liveStats) {
    fetch('/api/live/strompool-feldkirchen')
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (d) {
        if (!d) return;
        liveStats.style.display = '';
        liveStats.querySelectorAll('[data-live-value]').forEach(function (el) {
          var target = Number(d[el.getAttribute('data-live-value')]) || 0;
          if (reduceMotion) {
            el.textContent = target.toLocaleString('de-AT');
            return;
          }
          var obj = { v: 0 };
          gsap.to(obj, {
            v: target, duration: 1.4, ease: 'power2.out',
            scrollTrigger: { trigger: liveStats, start: 'top 90%', once: true },
            onUpdate: function () { el.textContent = Math.round(obj.v).toLocaleString('de-AT'); },
          });
        });
      })
      .catch(function () { /* Startseite bleibt unabhängig davon voll funktionsfähig */ });
  }
})();
