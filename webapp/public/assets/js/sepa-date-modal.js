// Ausführungsdatum-Auswahl-Modal für SEPA-XML-Downloads (Sammellastschrift pain.008,
// Sammelüberweisung pain.001) -- Patrick, 18.10.2026: "nach dem Klick auf den Download-Button
// soll ein Pop-up erscheinen, in dem ich das Ausführungsdatum auswähle". Gemeinsam genutzt von
// billing.php (pro Abrechnungslauf, window.sepaTilesByRun) und billing_gutschriften.php
// (window.sepaTransferTiles) -- beide übergeben direkt ein von sepaExecutionDateTiles()
// (functions.php) berechnetes Tiles-Objekt, kein weiterer Server-Roundtrip nötig.
//
// tiles: {tiles: [{date, label, blocked, reason}], selected, invoice_date, min_days}
// downloadUrl: Ziel-Route OHNE "date"-Parameter (wird beim Bestätigen angehängt).
function openSepaDateModal(tiles, downloadUrl) {
  if (!tiles) {
    alert('Für diesen Abrechnungslauf stehen keine Ausführungsdaten zur Auswahl zur Verfügung.');
    return;
  }
  const dialog = document.getElementById('sepa-date-dialog');
  const tilesEl = document.getElementById('sepa-date-tiles');
  const hintEl = document.getElementById('sepa-date-hint');
  let selected = tiles.selected;

  hintEl.textContent = 'Rechnungsdatum ' + formatDateDE(tiles.invoice_date)
    + ' — frühester Einzug/Überweisung nach ' + tiles.min_days + ' Tagen Vorabankündigung.';

  tilesEl.innerHTML = '';
  tiles.tiles.forEach(function (t) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = t.label;
    btn.className = 'sepa-date-tile' + (t.blocked ? ' sepa-date-tile-blocked' : '')
      + (!t.blocked && t.date === selected ? ' sepa-date-tile-selected' : '');
    btn.disabled = t.blocked;
    if (t.blocked) { btn.title = t.reason; }
    if (!t.blocked) {
      btn.addEventListener('click', function () {
        tilesEl.querySelectorAll('.sepa-date-tile-selected').forEach(function (el) {
          el.classList.remove('sepa-date-tile-selected');
        });
        btn.classList.add('sepa-date-tile-selected');
        selected = t.date;
      });
    }
    tilesEl.appendChild(btn);
  });

  document.getElementById('sepa-date-confirm').onclick = function () {
    const sep = downloadUrl.indexOf('?') === -1 ? '?' : '&';
    window.location.href = downloadUrl + sep + 'date=' + encodeURIComponent(selected);
    dialog.close();
  };

  dialog.showModal();
}

function formatDateDE(iso) {
  const parts = iso.split('-');
  return parts[2] + '.' + parts[1] + '.' + parts[0];
}
