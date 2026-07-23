<?php
$pageTitle = 'Rechnungen';
ob_start();
?>

<h2 style="margin-bottom:1rem">🧾 Rechnungen</h2>

<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success">Gespeichert.</div>
<?php endif; ?>

<?php
// Zahlungsstatus-Metadaten: Label + Badge-Farbe.
$paymentMeta = [
    'offen'          => ['Offen', 'gray'],
    'eingezogen'     => ['✓ Eingezogen', 'green'],
    'ueberwiesen'    => ['✓ Überwiesen', 'green'],
    'fehlgeschlagen' => ['⚠ Fehlgeschlagen', 'red'],
];
?>

<!-- Fortschritt der Zahlungsabwicklung (nur freigegebene Rechnungen) -->
<?php if ($paymentTotal > 0): ?>
  <div class="card" style="margin-bottom:1rem;padding:.85rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
    <div style="font-size:1.4rem"><?= $paymentDone === $paymentTotal ? '✅' : '⏳' ?></div>
    <div style="flex:1;min-width:220px">
      <strong><?= $paymentDone ?> von <?= $paymentTotal ?></strong> freigegebenen Rechnungen erledigt
      (eingezogen&nbsp;/&nbsp;überwiesen).
      <?php if ($paymentDone === $paymentTotal): ?>
        <span style="color:#15803d">Der Abrechnungsprozess ist vollständig abgeschlossen.</span>
      <?php else: ?>
        <span style="color:var(--gray-600)">Positive Salden werden per SEPA eingezogen, negative vom Obmann überwiesen.</span>
      <?php endif; ?>
      <div style="height:7px;background:var(--gray-100);border-radius:4px;margin-top:.5rem;overflow:hidden">
        <div style="height:100%;width:<?= $paymentTotal ? round($paymentDone / $paymentTotal * 100) : 0 ?>%;background:#16a34a"></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Suche & Filter -->
<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <input type="text" id="invoice-search" placeholder="Kundennummer oder Name suchen…"
           style="flex:1;min-width:200px;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
           oninput="filterInvoices()">
    <select id="invoice-quartal" onchange="filterInvoices()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Quartale</option>
      <?php foreach (array_unique(array_column($invoices, 'quartal')) as $q): ?>
        <option value="<?= htmlspecialchars($q) ?>" <?= $quartalFilter === $q ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" id="invoice-betrag-min" placeholder="Betrag von (€)" step="0.01"
           style="width:130px;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px" oninput="filterInvoices()">
    <input type="number" id="invoice-betrag-max" placeholder="Betrag bis (€)" step="0.01"
           style="width:130px;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px" oninput="filterInvoices()">
    <span id="invoice-result-count" style="font-size:.8rem;color:var(--gray-600)"></span>
  </div>
</div>

<div class="card" style="overflow-x:auto">
  <table id="invoice-table">
    <thead>
      <tr>
        <th class="sortable" data-sort-key="kdnr" data-sort-type="num" onclick="sortInvoices(this)">KdNr <span class="sort-arrow"></span></th>
        <th class="sortable" data-sort-key="name" data-sort-type="str" onclick="sortInvoices(this)">Name <span class="sort-arrow"></span></th>
        <th>E-Mail</th>
        <th class="sortable" data-sort-key="quartal" data-sort-type="str" onclick="sortInvoices(this)">Quartal <span class="sort-arrow"></span></th>
        <th>Rechnungsnummer</th>
        <th class="sortable" style="text-align:right" data-sort-key="betrag" data-sort-type="num" onclick="sortInvoices(this)">Betrag (brutto) <span class="sort-arrow"></span></th>
        <th class="sortable" data-sort-key="erstellt" data-sort-type="str" onclick="sortInvoices(this)">Erstellt <span class="sort-arrow"></span></th>
        <th>Versendet</th>
        <th>Zahlung</th>
        <th>PDF</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
      <?php $name = trim(($inv['company_name'] ?: '') ?: ($inv['first_name'] . ' ' . $inv['last_name'])); ?>
      <tr data-kdnr="<?= (int)($inv['kundennummer'] ?? 0) ?>"
          data-name="<?= htmlspecialchars(strtolower($name)) ?>"
          data-quartal="<?= htmlspecialchars($inv['quartal']) ?>"
          data-betrag="<?= (float)$inv['brutto_eur'] ?>"
          data-sort-kdnr="<?= (int)($inv['kundennummer'] ?? 0) ?>"
          data-sort-name="<?= htmlspecialchars(strtolower($name)) ?>"
          data-sort-quartal="<?= htmlspecialchars($inv['quartal']) ?>"
          data-sort-betrag="<?= (float)$inv['brutto_eur'] ?>"
          data-sort-erstellt="<?= htmlspecialchars($inv['created_at']) ?>">
        <td style="font-weight:600;color:#15803d"><?= htmlspecialchars((string)($inv['kundennummer'] ?? '—')) ?></td>
        <td><?= htmlspecialchars($name) ?></td>
        <td style="font-size:.85rem"><?= htmlspecialchars($inv['email']) ?></td>
        <td><?= htmlspecialchars($inv['quartal']) ?></td>
        <td style="font-size:.8rem"><code><?= htmlspecialchars($inv['rechnungsnummer']) ?></code></td>
        <td style="text-align:right;white-space:nowrap">
          <?php $betrag = (float)$inv['brutto_eur']; ?>
          <span style="color:<?= $betrag < 0 ? '#16a34a' : '#111827' ?>"><?= number_format($betrag, 2, ',', '.') ?> €</span>
        </td>
        <td style="font-size:.85rem;white-space:nowrap"><?= date('d.m.Y', strtotime($inv['created_at'])) ?></td>
        <td>
          <?php if ($inv['sent_at']): ?>
            <span class="badge badge-green">✓ <?= date('d.m.Y', strtotime($inv['sent_at'])) ?></span>
          <?php else: ?>
            <span class="badge badge-gray">Nicht versendet</span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php
            $ps = $inv['payment_status'] ?? 'offen';
            [$psLabel, $psBadge] = $paymentMeta[$ps] ?? [$ps, 'gray'];
            $isReleased = ($inv['run_status'] ?? '') === 'done';
            $hasMandat  = trim((string)($inv['member_iban'] ?? '')) !== '' && trim((string)($inv['mandatsreferenz'] ?? '')) !== '';
          ?>
          <span class="badge badge-<?= $psBadge ?>" style="font-size:.72rem"><?= htmlspecialchars($psLabel) ?></span>
          <?php if ($isReleased && $ps === 'offen'): ?>
            <?php if ($betrag > 0): ?>
              <form method="post" action="/portal/billing/invoices/<?= $inv['id'] ?>/mark-paid" style="display:inline"
                    onsubmit="return confirm('Rechnung als per SEPA eingezogen markieren? Bitte erst bestätigen, wenn die Lastschrift bei der Bank tatsächlich durchgelaufen ist.')">
                <input type="hidden" name="payment_status" value="eingezogen">
                <button class="btn" style="background:#dcfce7;color:#15803d;padding:.2rem .5rem;font-size:.7rem;margin-top:.25rem"
                        <?= $hasMandat ? '' : 'disabled title="Kein SEPA-Mandat (IBAN/Mandatsreferenz fehlt)"' ?>>✓ eingezogen</button>
              </form>
            <?php elseif ($betrag < 0): ?>
              <form method="post" action="/portal/billing/invoices/<?= $inv['id'] ?>/mark-paid" style="display:inline"
                    onsubmit="return confirm('Rechnung als an das Mitglied überwiesen markieren?')">
                <input type="hidden" name="payment_status" value="ueberwiesen">
                <button class="btn" style="background:#dbeafe;color:#1d4ed8;padding:.2rem .5rem;font-size:.7rem;margin-top:.25rem">✓ überwiesen</button>
              </form>
            <?php else: ?>
              <form method="post" action="/portal/billing/invoices/<?= $inv['id'] ?>/mark-paid" style="display:inline">
                <input type="hidden" name="payment_status" value="eingezogen">
                <button class="btn" style="background:var(--gray-100);color:var(--gray-700);padding:.2rem .5rem;font-size:.7rem;margin-top:.25rem">erledigt</button>
              </form>
            <?php endif; ?>
          <?php elseif ($isReleased && in_array($ps, ['eingezogen', 'ueberwiesen', 'fehlgeschlagen'], true)): ?>
            <form method="post" action="/portal/billing/invoices/<?= $inv['id'] ?>/mark-paid" style="display:inline"
                  onsubmit="return confirm('Zahlungsstatus zurück auf „offen“ setzen?')">
              <input type="hidden" name="payment_status" value="offen">
              <button class="btn" style="background:none;color:var(--gray-600);padding:.2rem .35rem;font-size:.68rem" title="Zurücksetzen">↺</button>
            </form>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($inv['pdf_path']): ?>
            <a href="/portal/invoices/<?= $inv['id'] ?>/pdf" target="_blank" style="font-size:.8rem">📄 Ansehen</a>
          <?php else: ?>
            <span style="font-size:.78rem;color:var(--gray-600)">wird erstellt…</span>
          <?php endif; ?>
          <?php if (($inv['run_status'] ?? '') === 'ready'): ?>
            · <a href="/portal/billing/invoices/<?= $inv['id'] ?>/edit" style="font-size:.8rem">📝 Bearbeiten</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
      <tr><td colspan="10" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Rechnungen vorhanden.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterInvoices() {
  const q = document.getElementById('invoice-search').value.toLowerCase();
  const quartal = document.getElementById('invoice-quartal').value;
  const min = parseFloat(document.getElementById('invoice-betrag-min').value);
  const max = parseFloat(document.getElementById('invoice-betrag-max').value);
  const rows = document.querySelectorAll('#invoice-table tbody tr[data-kdnr]');
  let visible = 0;
  rows.forEach(row => {
    const betrag = parseFloat(row.dataset.betrag);
    const nm = !q || row.dataset.kdnr.includes(q) || row.dataset.name.includes(q);
    const qm = !quartal || row.dataset.quartal === quartal;
    const minOk = isNaN(min) || betrag >= min;
    const maxOk = isNaN(max) || betrag <= max;
    const show = nm && qm && minOk && maxOk;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('invoice-result-count').textContent = visible + ' Rechnung' + (visible !== 1 ? 'en' : '') + ' gefunden';
}
filterInvoices();

let currentInvoiceSort = { key: null, dir: 1 };
function sortInvoices(th) {
  const key = th.dataset.sortKey;
  const type = th.dataset.sortType;
  currentInvoiceSort.dir = (currentInvoiceSort.key === key) ? -currentInvoiceSort.dir : 1;
  currentInvoiceSort.key = key;

  document.querySelectorAll('#invoice-table thead th.sortable .sort-arrow').forEach(el => el.textContent = '');
  th.querySelector('.sort-arrow').textContent = currentInvoiceSort.dir === 1 ? '▲' : '▼';

  const tbody = document.querySelector('#invoice-table tbody');
  const rows = Array.from(tbody.querySelectorAll('tr[data-kdnr]'));
  rows.sort((a, b) => {
    const va = a.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)] ?? '';
    const vb = b.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)] ?? '';
    let cmp;
    if (type === 'num') {
      cmp = parseFloat(va || 0) - parseFloat(vb || 0);
    } else {
      cmp = va.localeCompare(vb, 'de');
    }
    return cmp * currentInvoiceSort.dir;
  });
  rows.forEach(row => tbody.appendChild(row));
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
