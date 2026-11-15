/* Passkey-Anmeldung und -Registrierung (WebAuthn), 17.11.2026.
 * Eigene Datei statt Inline-Skript, damit die Content-Security-Policy keine Inline-Skripte erlauben muss.
 * Elemente: [data-passkey-login] (Login-Seite) und [data-passkey-register] (Profil); beide tragen das
 * CSRF-Token in data-csrf. Anfragen gehen als Formular-POST (csrf_token + data=<JSON>), damit der
 * zentrale CSRF-Check des Routers unverändert greift. */
(function () {
  'use strict';

  function supported() {
    return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get);
  }
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
  function post(url, csrf, data) {
    var body = new URLSearchParams();
    body.set('csrf_token', csrf);
    if (data !== undefined) body.set('data', JSON.stringify(data));
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { j._status = r.status; return j; });
    });
  }
  function say(el, text, isError) {
    if (!el) return;
    el.textContent = text || '';
    el.style.color = isError ? 'var(--red-600, #b91c1c)' : '';
  }
  function friendly(err) {
    if (err && (err.name === 'NotAllowedError' || err.name === 'AbortError')) return 'Abgebrochen.';
    if (err && err.name === 'InvalidStateError') return 'Dieses Gerät hat schon einen Passkey für dieses Konto.';
    return 'Passkey nicht möglich: ' + (err && err.message ? err.message : 'unbekannter Fehler');
  }

  function login(btn) {
    var msg = document.querySelector('[data-passkey-msg]');
    var csrf = btn.getAttribute('data-csrf');
    btn.disabled = true; say(msg, '');
    post('/portal/login/passkey/options', csrf).then(function (o) {
      if (!o.challenge) throw new Error(o.error || 'Keine Challenge erhalten');
      return navigator.credentials.get({ publicKey: {
        challenge: b64uToBuf(o.challenge), rpId: o.rpId, timeout: o.timeout,
        userVerification: o.userVerification, allowCredentials: []
      } });
    }).then(function (c) {
      return post('/portal/login/passkey/verify', csrf, {
        id: c.id,
        response: {
          clientDataJSON: bufToB64u(c.response.clientDataJSON),
          authenticatorData: bufToB64u(c.response.authenticatorData),
          signature: bufToB64u(c.response.signature),
          userHandle: c.response.userHandle ? bufToB64u(c.response.userHandle) : ''
        }
      });
    }).then(function (r) {
      if (r.ok) { window.location.href = r.redirect || '/portal/dashboard'; return; }
      throw new Error(r.error || 'Anmeldung fehlgeschlagen');
    }).catch(function (e) {
      say(msg, e.name ? friendly(e) : String(e), true);
      btn.disabled = false;
    });
  }

  function register(btn) {
    var msg = document.getElementById('passkey-msg');
    var csrf = btn.getAttribute('data-csrf');
    var label = (document.getElementById('passkey-label') || {}).value || '';
    btn.disabled = true; say(msg, '');
    post('/portal/profile/passkeys/options', csrf).then(function (o) {
      if (!o.challenge) throw new Error(o.error || 'Keine Challenge erhalten');
      return navigator.credentials.create({ publicKey: {
        challenge: b64uToBuf(o.challenge), rp: o.rp, timeout: o.timeout,
        user: { id: b64uToBuf(o.user.id), name: o.user.name, displayName: o.user.displayName },
        pubKeyCredParams: o.pubKeyCredParams, authenticatorSelection: o.authenticatorSelection,
        attestation: o.attestation,
        excludeCredentials: (o.excludeCredentials || []).map(function (c) { return { type: c.type, id: b64uToBuf(c.id) }; })
      } });
    }).then(function (c) {
      return post('/portal/profile/passkeys/verify', csrf, {
        id: c.id, label: label,
        response: {
          clientDataJSON: bufToB64u(c.response.clientDataJSON),
          attestationObject: bufToB64u(c.response.attestationObject)
        }
      });
    }).then(function (r) {
      if (r.ok) { window.location.href = '/portal/profile?success=' + encodeURIComponent('Passkey wurde hinzugefügt.') + '#passkeys'; return; }
      throw new Error(r.error || 'Speichern fehlgeschlagen');
    }).catch(function (e) {
      say(msg, e.name ? friendly(e) : String(e), true);
      btn.disabled = false;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var ok = supported();
    var l = document.querySelector('[data-passkey-login]');
    if (l) { if (ok) { l.hidden = false; l.addEventListener('click', function () { login(l); }); } }
    var wrap = document.querySelector('[data-passkey-login-wrap]');
    if (wrap && ok) wrap.hidden = false;
    var r = document.querySelector('[data-passkey-register]');
    if (r) {
      var box = document.getElementById('passkey-register-box');
      var un = document.getElementById('passkey-unsupported');
      if (ok) { if (box) box.hidden = false; if (un) un.hidden = true; r.addEventListener('click', function () { register(r); }); }
    }
  });
})();
