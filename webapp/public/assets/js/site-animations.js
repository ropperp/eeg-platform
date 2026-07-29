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
  if (!hero) return;

  if (reduceMotion) {
    // CSS erzwingt hier ohnehin schon opacity:1 (siehe @media prefers-reduced-motion in
    // app.css) -- js-anim einfach unbenutzt lassen, keine Animation nötig.
    return;
  }

  // fromTo (nicht from!): app.css setzt den Hero-Text bereits per CSS auf opacity:0 (verhindert
  // ein kurzes Aufblitzen vor dem Laden dieses Scripts). Ein .from({opacity:0,...}) würde den
  // "aktuellen" (bereits 0) Zustand als Ziel interpretieren und gar nicht animieren -- deshalb
  // hier Start- UND Endwert explizit angeben.
  gsap.timeline({ defaults: { ease: 'power3.out', duration: .7 } })
    .fromTo(hero.querySelectorAll('h1, p, a.btn'), { opacity: 0, y: 20 }, { opacity: 1, y: 0, stagger: .12 });
})();
