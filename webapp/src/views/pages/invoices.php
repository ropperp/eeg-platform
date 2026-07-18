<?php $pageTitle = 'Meine Rechnungen'; ob_start(); ?>

<h2 style="margin-bottom:1.5rem">🧾 Meine Rechnungen</h2>

<div class="card">
  <table>
    <thead>
      <tr><th>Quartal</th><th>Rechnungsnummer</th><th>Betrag</th><th>Erstellt</th><th>PDF</th></tr>
    </thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
      <tr>
        <td><?= htmlspecialchars($inv['quartal']) ?></td>
        <td><?= htmlspecialchars($inv['rechnungsnummer']) ?></td>
        <td style="font-weight:600;<?= $inv['saldo_eur'] < 0 ? 'color:#16a34a' : '' ?>">
          <?= number_format((float)$inv['saldo_eur'], 2, ',', '.') ?> EUR
          <?= $inv['saldo_eur'] < 0 ? '(Gutschrift)' : '' ?>
        </td>
        <td><?= date('d.m.Y', strtotime($inv['created_at'])) ?></td>
        <td>
          <?php if ($inv['pdf_path']): ?>
            <a href="/portal/invoices/<?= $inv['id'] ?>/pdf" class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.8rem">
              ⬇ PDF
            </a>
          <?php else: ?>
            <span style="font-size:.8rem;color:var(--gray-600)">wird erstellt…</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--gray-600);padding:2rem">
        Noch keine Rechnungen vorhanden. Rechnungen erscheinen nach der Quartalsabrechnung.
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:1.5rem">
  <p style="font-size:.875rem;color:var(--gray-600)">
    <strong>Wichtiger Hinweis:</strong> Rechnungen werden auf Basis der offiziellen EDA-Messdaten
    vom Netzbetreiber erstellt. Die täglich sichtbaren Verbrauchsdaten im Dashboard basieren auf
    den ESP32-Smart-Meter-Modulen und können leicht abweichen.
  </p>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
