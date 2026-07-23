<?php
$pageTitle = 'Abrechnung';
ob_start();
?>

<h2 style="margin-bottom:1.5rem">💶 Abrechnung</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success">Gespeichert.</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
    <div>
      <h3 style="margin-bottom:.25rem">Neuen Abrechnungslauf anlegen</h3>
      <p style="color:var(--gray-600);font-size:.85rem">Format: Jahr-Q Quartalsnummer, z.B. <code>2026-Q1</code>.</p>
    </div>
    <form method="post" action="/portal/billing/create" style="display:flex;gap:.5rem">
      <input type="text" name="quartal" placeholder="2026-Q1" pattern="\d{4}-Q[1-4]" required
             style="padding:.4rem .75rem;border:1px solid var(--gray-200);border-radius:6px;width:120px">
      <button type="submit" class="btn btn-primary">Anlegen</button>
    </form>
  </div>
</div>

<div class="card" style="margin-bottom:1rem">
  <h3 style="margin-bottom:.25rem">🧪 Test-Vorschau</h3>
  <p style="color:var(--gray-600);font-size:.85rem;margin-bottom:.75rem">
    Zeigt, wie eine Rechnung aussieht -- mit Platzhalter-Werten statt echten Mitgliedsdaten. Erzeugt keinen
    Datenbankeintrag, ideal um das Layout bzw. eine neue LaTeX-Vorlage zu prüfen.
  </p>
  <a href="/portal/billing/preview" target="_blank" class="btn" style="background:var(--gray-100);color:var(--gray-700)">
    Beispiel-Rechnung ansehen (PDF)
  </a>
</div>

<!-- Suche -->
<div class="card" style="margin-bottom:1rem;padding:.75rem 1rem">
  <div style="display:flex;gap:.75rem;align-items:center">
    <input type="text" id="billing-search" placeholder="Quartal suchen (z.B. 2026-Q1)…"
           style="flex:1;padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px"
           oninput="filterBilling()">
    <select id="billing-status" onchange="filterBilling()" style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:6px">
      <option value="">Alle Status</option>
      <option value="pending">Ausstehend</option>
      <option value="done">Abgeschlossen</option>
    </select>
  </div>
</div>

<div class="card">
  <table id="billing-table">
    <thead>
      <tr>
        <th>Quartal</th>
        <th>Zeitraum</th>
        <th>Status</th>
        <th>EDA-Datenqualität</th>
        <th>Freigegeben am</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $run): ?>
      <?php
        $edaStatus = $run['eda_status'] ?? 'unbekannt';
        $edaLabel  = ['unbekannt' => 'noch nicht geprüft', 'vollstaendig' => 'vollständig (belastbar)', 'unvollstaendig' => 'unvollständig'][$edaStatus] ?? $edaStatus;
        $edaBadge  = ['unbekannt' => 'gray', 'vollstaendig' => 'green', 'unvollstaendig' => 'yellow'][$edaStatus] ?? 'gray';
        // Freigabe ist erlaubt, sobald die Daten belastbar sind: Status nicht 'unvollständig'.
        // Der zusätzliche automatische L3-Check läuft serverseitig in Billing::finalize().
        $freigabeErlaubt = $edaStatus !== 'unvollstaendig';
      ?>
      <tr data-quartal="<?= htmlspecialchars(strtolower($run['quartal'])) ?>" data-status="<?= htmlspecialchars($run['status']) ?>">
        <td><?= htmlspecialchars($run['quartal']) ?></td>
        <td><?= date('d.m.Y', strtotime($run['period_from'])) ?> – <?= date('d.m.Y', strtotime($run['period_to'])) ?></td>
        <td>
          <?php $badges = ['pending' => 'gray', 'ready' => 'yellow', 'done' => 'green']; ?>
          <span class="badge badge-<?= $badges[$run['status']] ?? 'gray' ?>">
            <?= ['pending' => 'offen', 'ready' => 'Entwurf – prüfen', 'done' => 'abgeschlossen'][$run['status']] ?? htmlspecialchars($run['status']) ?>
          </span>
        </td>
        <td>
          <span class="badge badge-<?= $edaBadge ?>"><?= htmlspecialchars($edaLabel) ?></span>
          <?php if ($run['status'] !== 'done'): ?>
            <form method="post" action="/portal/billing/<?= $run['id'] ?>/eda-status" style="display:inline-flex;gap:.3rem;margin-top:.35rem">
              <select name="eda_status" style="padding:.2rem .4rem;border:1px solid var(--gray-200);border-radius:5px;font-size:.75rem">
                <?php foreach (['unbekannt' => 'noch nicht geprüft', 'vollstaendig' => 'vollständig (belastbar)', 'unvollstaendig' => 'unvollständig'] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= $edaStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn" style="background:var(--gray-100);color:var(--gray-700);padding:.2rem .5rem;font-size:.72rem">Setzen</button>
            </form>
          <?php endif; ?>
        </td>
        <td><?= $run['released_at'] ? date('d.m.Y H:i', strtotime($run['released_at'])) : '—' ?></td>
        <td>
          <?php if ($run['status'] === 'pending'): ?>
            <form method="post" action="/portal/billing/generate" style="display:inline"
                  onsubmit="return confirm('Rechnungen für dieses Quartal aus den EDA-Daten berechnen? Sie können danach jede Rechnung noch einzeln anpassen.')">
              <input type="hidden" name="billing_run_id" value="<?= $run['id'] ?>">
              <button class="btn btn-primary" style="padding:.35rem .75rem;font-size:.8rem">🧮 Rechnungen berechnen</button>
            </form>
          <?php elseif ($run['status'] === 'ready'): ?>
            <a href="/portal/billing/invoices?quartal=<?= urlencode($run['quartal']) ?>" class="btn btn-secondary" style="padding:.35rem .6rem;font-size:.8rem">📝 Prüfen/Bearbeiten</a>
            <form method="post" action="/portal/billing/generate" style="display:inline"
                  onsubmit="return confirm('Neu berechnen? Manuelle Änderungen an den Rechnungen dieses Laufs gehen dabei verloren.')">
              <input type="hidden" name="billing_run_id" value="<?= $run['id'] ?>">
              <button class="btn" style="background:var(--gray-100);color:var(--gray-700);padding:.35rem .6rem;font-size:.8rem">🔄 Neu</button>
            </form>
            <?php if ($freigabeErlaubt): ?>
              <form method="post" action="/portal/billing/release" style="display:inline"
                    onsubmit="return confirm('Abrechnung endgültig freigeben? Dieser Schritt kann nicht rückgängig gemacht werden. Voraussetzung: die EDA-Werte sind belastbar (keine L3-Werte mehr).')">
                <input type="hidden" name="billing_run_id" value="<?= $run['id'] ?>">
                <button class="btn btn-primary" style="padding:.35rem .75rem;font-size:.8rem">✅ Freigeben</button>
              </form>
            <?php else: ?>
              <span style="font-size:.8rem;color:var(--gray-600)">Freigabe gesperrt: EDA-Daten unvollständig</span>
            <?php endif; ?>
          <?php else: ?>
            <a href="/portal/billing/invoices?quartal=<?= urlencode($run['quartal']) ?>" style="font-size:.8rem">Rechnungen ansehen</a>
          <?php endif; ?>
          <?php if (Auth::isManager()): ?>
            <form method="post" action="/portal/billing/<?= $run['id'] ?>/delete" style="display:inline"
                  onsubmit="return confirmDangerDelete('Abrechnungslauf <?= htmlspecialchars(addslashes($run['quartal'])) ?> inkl. aller zugehörigen Rechnungen')">
              <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c;padding:.35rem .6rem;font-size:.8rem;margin-left:.4rem">🗑️</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($run['status'] === 'pending'): ?>
        <tr>
          <td colspan="6" style="background:var(--gray-50)">
            <details>
              <summary style="cursor:pointer;font-size:.85rem;color:var(--gray-700)">
                ➕ Zusatzpositionen (<?= count($extraItemsByRun[$run['id']] ?? []) ?>) -- z.B. einmaliger Rabatt für dieses Quartal
              </summary>
              <div style="padding:.75rem 0 .5rem">
                <?php if (!empty($extraItemsByRun[$run['id']])): ?>
                  <table style="margin-bottom:.75rem">
                    <thead><tr><th>Text</th><th>Menge</th><th>Einheit</th><th>Betrag</th><th></th></tr></thead>
                    <tbody>
                      <?php foreach ($extraItemsByRun[$run['id']] as $ei): ?>
                        <tr>
                          <td><?= htmlspecialchars($ei['label']) ?></td>
                          <td><?= htmlspecialchars((string)$ei['quantity']) ?></td>
                          <td><?= htmlspecialchars($ei['unit']) ?></td>
                          <td style="<?= (float)$ei['amount_eur'] < 0 ? 'color:#16a34a' : '' ?>">
                            <?= number_format((float)$ei['amount_eur'], 2, ',', '.') ?> €
                          </td>
                          <td>
                            <form method="post" action="/portal/billing/<?= $run['id'] ?>/extra-items/<?= $ei['id'] ?>/delete"
                                  onsubmit="return confirm('Zusatzposition wirklich entfernen?')">
                              <button type="submit" class="btn" style="background:none;color:#b91c1c;padding:0;font-size:.85rem">✕</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <p style="color:var(--gray-600);font-size:.8rem;margin-bottom:.75rem">
                    Gilt für <strong>alle</strong> Rechnungen dieses Abrechnungslaufs -- wird bei der Freigabe automatisch
                    in jede einzelne Rechnung übernommen. Negativer Betrag = Gutschrift/Rabatt.
                  </p>
                <?php endif; ?>
                <form method="post" action="/portal/billing/<?= $run['id'] ?>/extra-items" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
                  <div class="form-group" style="margin:0;flex:2;min-width:180px">
                    <label style="font-size:.78rem">Text</label>
                    <input type="text" name="label" placeholder="z.B. Rabatt Mitgliedsbeitrag Q1" required style="width:100%">
                  </div>
                  <div class="form-group" style="margin:0;width:80px">
                    <label style="font-size:.78rem">Menge</label>
                    <input type="text" name="quantity" value="1" style="width:100%">
                  </div>
                  <div class="form-group" style="margin:0;width:90px">
                    <label style="font-size:.78rem">Einheit</label>
                    <input type="text" name="unit" value="Stk" style="width:100%">
                  </div>
                  <div class="form-group" style="margin:0;width:110px">
                    <label style="font-size:.78rem">Betrag (€)</label>
                    <input type="text" name="amount_eur" placeholder="-6,00" required style="width:100%">
                  </div>
                  <button type="submit" class="btn btn-secondary" style="padding:.5rem .9rem">Hinzufügen</button>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (empty($runs)): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--gray-600);padding:2rem">Noch keine Abrechnungsläufe vorhanden.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterBilling() {
  const q = document.getElementById('billing-search').value.toLowerCase();
  const s = document.getElementById('billing-status').value;
  document.querySelectorAll('#billing-table tbody tr[data-quartal]').forEach(row => {
    const qm = !q || row.dataset.quartal.includes(q);
    const sm = !s || row.dataset.status === s;
    row.style.display = qm && sm ? '' : 'none';
  });
}
</script>

<div class="card" style="margin-top:1.5rem">
  <h3 style="margin-bottom:.75rem">ℹ️ Freigabe nach EDA-Datenqualität</h3>
  <p style="font-size:.875rem;color:var(--gray-600);margin-bottom:.5rem">
    Eine Abrechnung darf freigegeben werden, sobald die Messwerte belastbar sind – nicht mehr erst nach einer
    starren 60-Tage-Frist. Die EDA kennzeichnet jeden Viertelstundenwert mit einer Wertekategorie:
  </p>
  <ul style="font-size:.85rem;color:var(--gray-600);margin:0 0 .5rem 1.1rem">
    <li><strong>L1</strong> – Echtwert, gemessen → belastbar (bester Wert)</li>
    <li><strong>L2</strong> – Ersatzwert, belastbar → belastbar (ändert sich mit hoher Wahrscheinlichkeit nicht mehr)</li>
    <li><strong>L3</strong> – Ersatzwert, <em>nicht</em> belastbar → ändert sich wahrscheinlich noch; laut EDA <strong>nicht</strong> abzurechnen</li>
  </ul>
  <p style="font-size:.875rem;color:var(--gray-600)">
    Freigegeben werden kann, sobald der EDA-Monatsbericht (Eder-XLSX) den Zeitraum als <strong>vollständig</strong>
    meldet und keine L3-Werte mehr vorliegen. Den Gesamtstatus aus dem Bericht trägst du oben in der Spalte
    „EDA-Datenqualität" ein; die Prüfung auf verbliebene L3-Werte läuft zusätzlich automatisch bei der Freigabe.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
