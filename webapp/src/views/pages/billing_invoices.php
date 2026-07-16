<?php
$pageTitle = 'Rechnungen';
ob_start();
?>

<h2 style="margin-bottom:1.5rem">🧾 Rechnungen</h2>

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
    <span id="invoice-result-count" style="font-size:.8rem;color:#6b7280"></span>
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
        <th class="sortable" style="text-align:right" data-sort-key="betrag" data-sort-type="num" onclick="sortInvoices(this)">Betrag <span class="sort-arrow"></span></th>
        <th class="sortable" data-sort-key="erstellt" data-sort-type="str" onclick="sortInvoices(this)">Erstellt <span class="sort-arrow"></span></th>
        <th>Versendet</th>
        <th>PDF</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
      <?php $name = trim(($inv['company_name'] ?: '') ?: ($inv['first_name'] . ' ' . $inv['last_name'])); ?>
      <tr data-kdnr="<?= (int)($inv['kundennummer'] ?? 0) ?>"
          data-name="<?= htmlspecialchars(strtolower($name)) ?>"
          data-quartal="<?= htmlspecialchars($inv['quartal']) ?>"
          data-betrag="<?= (float)$inv['saldo_eur'] ?>"
          data-sort-kdnr="<?= (int)($inv['kundennummer'] ?? 0) ?>"
          data-sort-name="<?= htmlspecialchars(strtolower($name)) ?>"
          data-sort-quartal="<?= htmlspecialchars($inv['quartal']) ?>"
          data-sort-betrag="<?= (float)$inv['saldo_eur'] ?>"
          data-sort-erstellt="<?= htmlspecialchars($inv['created_at']) ?>">
        <td style="font-weight:600;color:#15803d"><?= htmlspecialchars((string)($inv['kundennummer'] ?? '—')) ?></td>
        <td><?= htmlspecialchars($name) ?></td>
        <td style="font-size:.85rem"><?= htmlspecialchars($inv['email']) ?></td>
        <td><?= htmlspecialchars($inv['quartal']) ?></td>
        <td style="font-size:.8rem"><code><?= htmlspecialchars($inv['rechnungsnummer']) ?></code></td>
        <td style="text-align:right;white-space:nowrap">
          <?php $betrag = (float)$inv['saldo_eur']; ?>
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
        <td>
          <?php if ($inv['pdf_path']): ?>
            <a href="/portal/invoices/<?= $inv['id'] ?>/pdf" target="_blank" style="font-size:.8rem">📄 Ansehen</a>
          <?php else: ?>
            <span style="font-size:.78rem;color:#9ca3af">wird erstellt…</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
      <tr><td colspan="9" style="text-align:center;color:#6b7280;padding:2rem">Noch keine Rechnungen vorhanden.</td></tr>
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
