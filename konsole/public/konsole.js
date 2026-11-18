/* Steuerzentrale: Passkey-Anmeldung und Rückfragen vor Aktionen. Keine Inline-Skripte (CSP). */
(function () {
  'use strict';
  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64u(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function postJson(url, data) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(data || {})
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }
  /* Safari startet die Passkey-Abfrage nur direkt aus dem Klick heraus. Wird vorher noch auf den Server
     gewartet, verwirft Safari den Aufruf manchmal still ("es passiert nichts"). Deshalb die Challenge schon
     beim Laden holen und alle 2 Minuten erneuern (sie gilt 3 Minuten); der Klick ruft dann sofort auf. */
  var ready = null;
  function prefetch() {
    if (!document.querySelector('[data-login]') || !window.PublicKeyCredential) return;
    postJson('/login/options').then(function (o) {
      ready = o.challenge ? { o: o, t: Date.now() } : null;
    }).catch(function () { ready = null; });
  }
  function ask(o) {
    return navigator.credentials.get({ publicKey: {
      challenge: b64uToBuf(o.challenge), rpId: o.rpId, timeout: o.timeout,
      userVerification: o.userVerification, allowCredentials: []
    } });
  }
  function login(btn) {
    var msg = document.querySelector('[data-msg]');
    msg.textContent = '';
    if (!window.PublicKeyCredential) { msg.textContent = 'Dieser Browser unterstützt keine Passkeys.'; return; }
    btn.disabled = true;
    var pre = ready && Date.now() - ready.t < 150000 ? ready.o : null;
    ready = null;
    var slowPath = !pre;
    // Mit vorab geholter Challenge: Abfrage noch im Klick starten (Safari). Sonst wie bisher erst holen.
    (pre ? ask(pre) : postJson('/login/options').then(function (o) {
      if (!o.challenge) throw new Error(o.error || 'Keine Challenge erhalten');
      return ask(o);
    })).then(function (c) {
      return postJson('/login/verify', {
        id: c.id,
        response: {
          clientDataJSON: bufToB64u(c.response.clientDataJSON),
          authenticatorData: bufToB64u(c.response.authenticatorData),
          signature: bufToB64u(c.response.signature),
          userHandle: c.response.userHandle ? bufToB64u(c.response.userHandle) : ''
        }
      });
    }).then(function (r) {
      if (r.ok) { window.location.href = '/'; return; }
      throw new Error(r.error || 'Anmeldung fehlgeschlagen');
    }).catch(function (e) {
      var cancelled = e && (e.name === 'NotAllowedError' || e.name === 'AbortError');
      msg.textContent = cancelled
        ? (slowPath ? 'Bitte noch einmal auf „Mit Passkey anmelden“ tippen.' : 'Abgebrochen.')
        : (e.message || String(e));
      btn.disabled = false;
      prefetch();
    });
  }
  prefetch();
  setInterval(prefetch, 120000);
  document.addEventListener('click', function (e) {
    var loginBtn = e.target.closest('[data-login]');
    if (loginBtn) { login(loginBtn); return; }
    var c = e.target.closest('[data-confirm]');
    if (c && !window.confirm(c.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
