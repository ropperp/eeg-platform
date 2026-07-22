<?php $pageTitle = 'Rechnung bearbeiten'; ob_start();

$typLabels = [
    'bezug'            => 'Energiebezug',
    'einspeisung'      => 'Einspeisevergütung (Gutschrift)',
    'mitgliedsbeitrag' => 'Mitgliedsbeitrag',
    'manuell'          => 'Zusatzposition',
];
$editable = $invoice['run_status'] === 'ready';
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
  <a href="/portal/billing/invoices?quartal=<?= urlencode($invoice['quartal']) ?>" style="color:var(--gray-600);text-decoration:none">← Rechnungen</a>
  <h2 style="margin:0">📝 Rechnung <?= htmlspecialchars($invoice['rechnungsnummer']) ?></h2>
</div>

<p style="color:var(--gray-600);font-size:.9rem;margin-bottom:1rem">
  <?= htmlspecialchars(trim($invoice['first_name'] . ' ' . $invoice['last_name'])) ?>
  (KdNr <?= htmlspecialchars((string)($invoice['kundennummer'] ?? '—')) ?>) ·
  Quartal <?= htmlspecialchars($invoice['quartal']) ?>
</p>

<?php if (!empty($_GET['success'])): ?>
  <div class="alert alert-success" style="margin-bottom:1rem">Gespeichert.</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php if (!$editable): ?>
  <div class="alert alert-warning">Diese Rechnung gehört zu einem bereits freigegebenen Abrechnungslauf und kann nicht mehr bearbeitet werden.</div>
  <a href="/portal/invoices/<?= $invoice['id'] ?>/pdf" target="_blank" class="btn btn-secondary" style="margin-top:1rem">📄 Rechnung als PDF ansehen</a>
<?php else: ?>

<?php // Formulare außerhalb der Tabelle deklarieren; die Felder in den Zellen referenzieren sie
      // per HTML5-form-Attribut (verschachtelte <form> in <tr>/<td> wären ungültiges HTML). ?>
<?php foreach ($items as $it): ?>
  <form id="upd-<?= $it['id'] ?>" method="post" action="/portal/billing/invoices/<?= $invoice['id'] ?>/items/<?= $it['id'] ?>/update"></form>
  <form id="del-<?= $it['id'] ?>" method="post" action="/portal/billing/invoices/<?= $invoice['id'] ?>/items/<?= $it['id'] ?>/delete"
        onsubmit="return confirm('Position wirklich streichen?')"></form>
<?php endforeach; ?>

<div class="card" style="margin-bottom:1rem">
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:1rem">
    Solange der Abrechnungslauf noch nicht freigegeben ist, können Sie einzelne Positionen dieser
    Rechnung anpassen, streichen oder ergänzen. Der Gesamtbetrag wird nach jeder Änderung neu berechnet.
  </p>
  <table>
    <thead>
      <tr><th>Position</th><th>Details</th><th style="text-align:right">Betrag (EUR)</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td>
          <?php if ($it['type'] === 'manuell'): ?>
            <input type="text" name="label" form="upd-<?= $it['id'] ?>" value="<?= htmlspecialchars((string)$it['label']) ?>" style="width:100%;min-width:180px">
          <?php else: ?>
            <?= htmlspecialchars($typLabels[$it['type']] ?? $it['type']) ?>
          <?php endif; ?>
        </td>
        <td style="font-size:.82rem;color:var(--gray-600)">
          <?php if ($it['kwh'] !== null): ?>
            <?= number_format((float)$it['kwh'], 2, ',', '.') ?> kWh × <?= number_format((float)$it['rate_ct_kwh'], 4, ',', '.') ?> ct/kWh
          <?php endif; ?>
          <?php if (!empty($it['zaehlpunkt_nr'])): ?><br><code style="font-size:.72rem"><?= htmlspecialchars($it['zaehlpunkt_nr']) ?></code><?php endif; ?>
          <?php if ($it['type'] === 'mitgliedsbeitrag' && $it['months'] !== null): ?><?= (int)$it['months'] ?> Monat(e)<?php endif; ?>
        </td>
        <td style="text-align:right">
          <input type="text" name="amount_eur" form="upd-<?= $it['id'] ?>" value="<?= number_format((float)$it['amount_eur'], 2, ',', '.') ?>" style="width:90px;text-align:right">
        </td>
        <td style="white-space:nowrap">
          <button type="submit" form="upd-<?= $it['id'] ?>" class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.8rem">Speichern</button>
          <button type="submit" form="del-<?= $it['id'] ?>" class="btn" style="background:#fee2e2;color:#b91c1c;padding:.3rem .5rem;font-size:.8rem">✕</button>
        </td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td colspan="2" style="text-align:right;font-weight:700">Gesamtbetrag</td>
        <td style="text-align:right;font-weight:700"><?= number_format((float)$invoice['saldo_eur'], 2, ',', '.') ?> €</td>
        <td></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card" style="margin-bottom:1rem">
  <h3 style="margin-bottom:.5rem">Position hinzufügen</h3>
  <form method="post" action="/portal/billing/invoices/<?= $invoice['id'] ?>/items/add"
        style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="margin:0;flex:2;min-width:200px">
      <label style="font-size:.78rem">Text</label>
      <input type="text" name="label" placeholder="z.B. Gutschrift Kulanz" required style="width:100%">
    </div>
    <div class="form-group" style="margin:0;width:120px">
      <label style="font-size:.78rem">Betrag (€)</label>
      <input type="text" name="amount_eur" placeholder="-5,00" required style="width:100%">
    </div>
    <button type="submit" class="btn btn-primary" style="padding:.5rem .9rem">Hinzufügen</button>
  </form>
  <p style="color:var(--gray-600);font-size:.8rem;margin-top:.5rem">Negativer Betrag = Gutschrift/Rabatt zugunsten des Mitglieds.</p>
</div>

<a href="/portal/invoices/<?= $invoice['id'] ?>/pdf" target="_blank" class="btn btn-secondary">📄 Rechnung als PDF ansehen</a>

<?php endif; ?>

<?php $content = ob_get_clean(); require ROOT . '/src/views/layouts/portal.php'; ?>
