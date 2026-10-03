/**
 * Passwort-Felder bekommen automatisch ein Augen-Icon zum Ein-/Ausblenden des eingetippten
 * Textes -- Patrick, 03.10.2026: "ein Wunsch wäre, dass man beim Passwort-Vergeben bitte ein
 * Auge bekommt, wo man die Punkte in Klartext anzeigen lassen kann, um noch mal zu kontrollieren,
 * was man eingetippt hat" (Anlass: ein Mitglied, das über den 24h-Link ein neues Passwort setzt).
 *
 * Reine progressive Verbesserung: läuft automatisch über JEDES input[type="password"] auf der
 * Seite (Login, Passwort-vergeben-Link, Passwort ändern im Portal, ...) -- keine einzelne Seite
 * muss dafür angepasst werden, einfach dieses eine Script in den beiden Layouts (base.php,
 * portal.php) einbinden. Ohne JS bleibt das Feld ein ganz normales input[type="password"].
 */
(function () {
  function addToggle(input) {
    if (input.dataset.pwToggled) return;
    input.dataset.pwToggled = '1';

    var wrap = document.createElement('div');
    wrap.className = 'pw-field-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle-btn';
    btn.setAttribute('aria-label', 'Passwort anzeigen');
    btn.innerHTML = '<svg class="icon"><use href="/assets/icons/phosphor-sprite.svg#ph-eye"></use></svg>';
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.querySelector('use').setAttribute('href', '/assets/icons/phosphor-sprite.svg#' + (show ? 'ph-eye-slash' : 'ph-eye'));
      btn.setAttribute('aria-label', show ? 'Passwort verbergen' : 'Passwort anzeigen');
    });
  }

  document.querySelectorAll('input[type="password"]').forEach(addToggle);
})();
