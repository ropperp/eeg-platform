// Einfacher Text-Editor für Rechtsdokumente (/portal/dokumente). Kein externes Paket:
// contenteditable + execCommand, eingefügter Text (z.B. aus Word) wird auf dieselben
// erlaubten Elemente reduziert wie serverseitig in sanitizeLegalHtml() (functions.php).
// Der Server bereinigt beim Speichern ohnehin noch einmal -- das hier ist nur Komfort.
(function () {
  'use strict';

  var ALLOWED = ['H2', 'H3', 'H4', 'P', 'BR', 'HR', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'U', 'A',
    'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD'];
  var DROP = ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'HEAD', 'META', 'LINK', 'TITLE', 'XML',
    'NOSCRIPT', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'TEMPLATE', 'IMG'];
  var RENAME = { H1: 'H2', B: 'STRONG', I: 'EM', H5: 'H4', H6: 'H4' };
  var SAFE_HREF = /^(https?:\/\/|mailto:|\/(?!\/)|#)/i;

  function clean(node, doc) {
    var children = Array.prototype.slice.call(node.childNodes);
    children.forEach(function (child) {
      if (child.nodeType === 8) { node.removeChild(child); return; }       // Kommentar
      if (child.nodeType !== 1) { return; }                                  // Text bleibt
      var tag = child.tagName.toUpperCase();
      if (tag.indexOf(':') !== -1 || DROP.indexOf(tag) !== -1) { node.removeChild(child); return; }
      clean(child, doc);
      var target = RENAME[tag] || tag;
      if (ALLOWED.indexOf(target) === -1) {
        // Unbekanntes Element (span, div, font, o:p ...): Inhalt behalten, Hülle weg.
        // Ein div wird zum Absatz, damit Zeilen aus Word nicht zusammenlaufen.
        if (tag === 'DIV' && child.textContent.trim() !== '' && !child.querySelector('p,ul,ol,table,h2,h3,h4')) {
          target = 'P';
        } else {
          while (child.firstChild) { node.insertBefore(child.firstChild, child); }
          node.removeChild(child);
          return;
        }
      }
      var el = child;
      if (target !== tag) {
        el = doc.createElement(target.toLowerCase());
        while (child.firstChild) { el.appendChild(child.firstChild); }
        node.replaceChild(el, child);
      }
      var href = target === 'A' ? (child.getAttribute('href') || '').trim() : '';
      Array.prototype.slice.call(el.attributes).forEach(function (a) { el.removeAttribute(a.name); });
      if (target === 'A') {
        if (SAFE_HREF.test(href)) {
          el.setAttribute('href', href);
        } else {
          while (el.firstChild) { node.insertBefore(el.firstChild, el); }
          node.removeChild(el);
          return;
        }
      }
      if (['P', 'H2', 'H3', 'H4', 'LI'].indexOf(target) !== -1 && el.textContent.replace(/ /g, ' ').trim() === '') {
        node.removeChild(el);
      }
    });
  }

  function cleanHtml(html) {
    var doc = document.implementation.createHTMLDocument('');
    var box = doc.createElement('div');
    box.innerHTML = html;
    clean(box, doc);
    return box.innerHTML;
  }

  function textToHtml(text) {
    return text.split(/\r?\n\s*\r?\n/).map(function (block) {
      var esc = block.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      return esc.trim() === '' ? '' : '<p>' + esc.replace(/\r?\n/g, '<br>') + '</p>';
    }).join('');
  }

  function init(root) {
    var area = root.querySelector('.rich-editor-area');
    var source = root.querySelector('.rich-editor-source');
    var form = root.closest('form');
    if (!area || !source) { return; }

    var sync = function () { source.value = area.innerHTML; };
    var dirty = false;
    area.addEventListener('input', function () { dirty = true; sync(); });

    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* ältere Browser */ }

    root.querySelectorAll('[data-cmd]').forEach(function (btn) {
      // mousedown verhindern, sonst verliert das Textfeld die Markierung vor dem Klick
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function () {
        var cmd = btn.getAttribute('data-cmd');
        var value = btn.getAttribute('data-value') || null;
        area.focus();
        if (cmd === 'createLink') {
          value = window.prompt('Adresse des Links (https://…, mailto:… oder /pfad):', 'https://');
          if (!value || !SAFE_HREF.test(value.trim())) { return; }
          value = value.trim();
        }
        if (cmd === 'formatBlock') { value = '<' + value + '>'; }
        document.execCommand(cmd, false, value);
        dirty = true;
        sync();
      });
    });

    area.addEventListener('paste', function (e) {
      var cd = e.clipboardData || window.clipboardData;
      if (!cd) { return; }
      e.preventDefault();
      var html = cd.getData('text/html');
      var out = html ? cleanHtml(html) : textToHtml(cd.getData('text/plain') || '');
      document.execCommand('insertHTML', false, out);
      dirty = true;
      sync();
    });

    if (form) {
      form.addEventListener('submit', function () { sync(); dirty = false; });
    }
    window.addEventListener('beforeunload', function (e) {
      if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  document.querySelectorAll('[data-rich-editor]').forEach(init);
  window.richEditorCleanHtml = cleanHtml; // für Tests
})();
