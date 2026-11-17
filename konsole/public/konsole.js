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
  function login(btn) {
    var msg = document.querySelector('[data-msg]');
    msg.textContent = '';
    if (!window.PublicKeyCredential) { msg.textContent = 'Dieser Browser unterstützt keine Passkeys.'; return; }
    btn.disabled = true;
    postJson('/login/options').then(function (o) {
      if (!o.challenge) throw new Error(o.error || 'Keine Challenge erhalten');
      return navigator.credentials.get({ publicKey: {
        challenge: b64uToBuf(o.challenge), rpId: o.rpId, timeout: o.timeout,
        userVerification: o.userVerification, allowCredentials: []
      } });
    }).then(function (c) {
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
      msg.textContent = (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) ? 'Abgebrochen.' : (e.message || String(e));
      btn.disabled = false;
    });
  }
  document.addEventListener('click', function (e) {
    var loginBtn = e.target.closest('[data-login]');
    if (loginBtn) { login(loginBtn); return; }
    var c = e.target.closest('[data-confirm]');
    if (c && !window.confirm(c.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
