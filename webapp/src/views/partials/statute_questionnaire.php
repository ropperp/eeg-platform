<?php
/**
 * Fragebogen des Statuten-Generators (Abschnitte A–F). Genutzt vom Einladungslink
 * (pages/statute_form.php) und vom Admin (pages/admin_statute_detail.php).
 *
 * @var array<string, mixed> $answers  normalisierte Antworten
 * @var list<string> $errors
 * @var list<string> $hints
 * @var string $formAction  Ziel für Speichern (POST, Feld "aktion" bzw. eigene Admin-URL)
 * @var bool   $adminMode   true = Admin (Aktionen über eigene URLs)
 */
use App\Domain\Statutes\StatuteQuestionnaire as Q;

$h = fn($v) => htmlspecialchars((string) ($v ?? ''));
$a = $answers;
$sel = fn(string $k, $v) => (string) ($a[$k] ?? '') === (string) $v ? ' selected' : '';
$chk = fn(string $k) => !empty($a[$k]) ? ' checked' : '';
$opt = function (string $key, array $options) use ($sel, $h): string {
    $out = '';
    foreach ($options as $v => $label) {
        $out .= '<option value="' . $h($v) . '"' . $sel($key, $v) . '>' . $h(is_array($label) ? $label['label'] : $label) . '</option>';
    }
    return $out;
};
$num = fn(string $k, int $min, int $max, string $extra = '') => '<input type="number" name="' . $k . '" value="' . (int) ($a[$k] ?? 0) . '" min="' . $min . '" max="' . $max . '" ' . $extra . ' style="max-width:9rem">';
$founders = implode("\n", array_map(fn($f) => $f['name'] . ($f['role'] !== '' ? '; ' . $f['role'] : ''), $a['founders'] ?? []));
?>
<?php if ($errors): ?>
  <div class="alert alert-error" style="margin-bottom:1rem" role="alert">
    <strong>Bitte prüfen:</strong>
    <ul style="margin:.4rem 0 0 1.1rem;padding:0"><?php foreach ($errors as $err): ?><li><?= $h($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $h($formAction) ?><?= !empty($adminMode) ? '/speichern' : '' ?>" class="statute-form" id="fragebogen">
  <?= csrfField() ?>

  <fieldset class="card statute-section">
    <legend><h3>A. Grunddaten</h3></legend>
    <p class="statute-fixed"><?= icon('lock-key') ?> Modell: <strong>Erneuerbare-Energie-Gemeinschaft (EEG)</strong> · Rechtsform: <strong>Verein</strong></p>
    <label>Vereinsname *
      <input type="text" name="name" value="<?= $h($a['name']) ?>" maxlength="150" placeholder="z. B. Erneuerbare-Energie-Gemeinschaft Musterdorf">
      <small>Muss einen Schluss auf den Vereinszweck zulassen und darf nicht irreführend sein (§ 4 VerG).</small>
    </label>
    <div class="statute-grid">
      <label>Sitz (Gemeinde) *<input type="text" name="seat" value="<?= $h($a['seat']) ?>" maxlength="100"></label>
      <label>Bundesland *<select name="state"><option value="">– bitte wählen –</option><?= $opt('state', Q::STATES) ?></select></label>
      <label>Bezirk / Tätigkeitsgebiet<input type="text" name="district" value="<?= $h($a['district']) ?>" maxlength="150" placeholder="z. B. Bezirk Musterbezirk"></label>
    </div>
    <div class="statute-grid">
      <label>Nahebereich *<select name="area"><?= $opt('area', Q::AREAS) ?></select>
        <small>Eine EEG ist nur im Lokal- oder Regionalbereich möglich (§ 70 Abs 6 ElWG).</small></label>
      <label>Lokal-/Regional-ID (falls bekannt)<input type="text" name="area_id" value="<?= $h($a['area_id']) ?>" maxlength="30"></label>
      <label>Netzebene (falls bekannt)<input type="text" name="grid_level" value="<?= $h($a['grid_level']) ?>" maxlength="20" placeholder="z. B. 5"></label>
    </div>
    <label>Netzbetreiber *
      <input type="text" name="grid_operator" value="<?= $h($a['grid_operator']) ?>" list="statute-grid-operators" maxlength="120">
      <datalist id="statute-grid-operators"><?php foreach (Q::GRID_OPERATORS as $g): ?><option value="<?= $h($g) ?>"><?php endforeach; ?></datalist>
    </label>
    <div class="statute-grid">
      <label>Anzahl Gründungsmitglieder *<?= $num('founders_count', 0, 999) ?><small>Mindestens zwei.</small></label>
      <label style="grid-column:span 2">Gründungsmitglieder (freiwillig)
        <textarea name="founders" rows="3" placeholder="Eine Zeile je Person: Name; Funktion"><?= $h($founders) ?></textarea>
        <small>Nur für die Protokollvorlage und die Checkliste. Ohne Angabe bleiben dort Leerzeilen.</small>
      </label>
    </div>
  </fieldset>

  <fieldset class="card statute-section">
    <legend><h3>B. Zweck &amp; Geld</h3></legend>
    <p class="statute-fixed"><?= icon('lock-key') ?> Gemeinnützigkeit: <strong>nicht gemeinnützig</strong> (fest; eine EEG verfolgt wirtschaftliche Vorteile ihrer Mitglieder)</p>
    <div class="statute-grid">
      <div><span class="statute-label">Energieträger</span>
        <?php foreach (Q::ENERGY_SOURCES as $k => $label): ?>
          <label class="statute-check"><input type="checkbox" name="energy_sources[]" value="<?= $h($k) ?>"<?= in_array($k, $a['energy_sources'], true) ? ' checked' : '' ?>> <?= $h($label) ?></label>
        <?php endforeach; ?>
      </div>
      <label>Modell der Anlagen<select name="plant_model"><?= $opt('plant_model', Q::PLANT_MODELS) ?></select></label>
      <label>Wer legt die Energiepreise fest?<select name="prices_by"><?= $opt('prices_by', Q::BODY) ?></select></label>
    </div>
    <div class="statute-grid">
      <label>Eintrittsgeld (€, 0 = keines)<?= $num('entry_fee', 0, 100000) ?></label>
      <label>Mitgliedsbeitrag von (€/Jahr)<?= $num('fee_min', 0, 100000) ?></label>
      <label>bis (€/Jahr)<?= $num('fee_max', 0, 100000) ?></label>
    </div>
    <div class="statute-grid">
      <label>Verrechnung des Beitrags<select name="fee_billing"><?= $opt('fee_billing', Q::FEE_BILLING) ?></select></label>
      <label>Abrechnung spätestens … Tage nach Periodenende<?= $num('billing_days', 1, 365) ?></label>
      <label class="statute-check" style="align-self:end"><input type="checkbox" name="additional_contrib" value="1"<?= $chk('additional_contrib') ?>> Nachschusspflicht vorsehen</label>
    </div>
    <div class="statute-grid">
      <label>Erwarteter Gesamtumsatz pro Jahr (€)
        <?= $num('expected_turnover', 0, 1000000000) ?>
        <small>Entgelte für gelieferte Energie (ohne echte Mitgliedsbeiträge). Bestimmt, ob die Kleinunternehmerregelung möglich ist (Grenze <?= number_format(Q::KLEINUNTERNEHMER_GRENZE, 0, ',', '.') ?> €).</small>
      </label>
      <label style="grid-column:span 2">Umsatzsteuer<select name="vat_mode"><?= $opt('vat_mode', Q::VAT_MODES) ?></select>
        <small>Kleinunternehmer: keine Umsatzsteuer, kein Vorsteuerabzug. Regelbesteuerung: 20 % USt, Vorsteuerabzug, Bindung mindestens 5 Jahre.</small></label>
    </div>
  </fieldset>

  <fieldset class="card statute-section">
    <legend><h3>C. Mitgliedschaft</h3></legend>
    <p class="statute-fixed"><?= icon('lock-key') ?> Mitglieder: natürliche Personen, Gemeinden, öffentliche Stellen und KMU – keine Großunternehmen (§ 6 Abs 1 Z 45 ElWG). Schutzbedürftige Haushalte dürfen nicht ausgeschlossen werden (fest).</p>
    <div class="statute-grid">
      <div><span class="statute-label">Mitgliedsarten</span>
        <label class="statute-check"><input type="checkbox" checked disabled> ordentliche Mitglieder</label>
        <label class="statute-check"><input type="checkbox" name="member_extra" value="1"<?= $chk('member_extra') ?>> außerordentliche (Förder-)Mitglieder</label>
        <label class="statute-check"><input type="checkbox" name="member_honorary" value="1"<?= $chk('member_honorary') ?>> Ehrenmitglieder</label>
      </div>
      <label>Aufnahme durch<select name="admission_by"><?= $opt('admission_by', Q::BODY) ?></select></label>
      <label>Austrittsfrist<select name="exit_notice"><?= $opt('exit_notice', Q::EXIT_NOTICE) ?></select>
        <small>Für Verbraucher und Kleinunternehmen gelten zwingende gesetzliche Fristen; die Statuten verweisen darauf.</small></label>
    </div>
    <div class="statute-grid">
      <label>Ausschluss bei Zahlungsverzug ab … Monaten<?= $num('exclusion_months', 1, 24) ?></label>
      <label>nach … Mahnungen<?= $num('exclusion_reminders', 1, 5) ?></label>
      <label>Ausschluss durch<select name="exclusion_by"><?= $opt('exclusion_by', Q::BODY) ?></select></label>
    </div>
    <div class="statute-grid">
      <label class="statute-check"><input type="checkbox" name="succession" value="1"<?= $chk('succession') ?>> Rechtsnachfolge im Todesfall möglich</label>
      <label>Frist für Rechtsnachfolger (Monate)<?= $num('succession_months', 1, 12) ?></label>
      <label class="statute-check"><input type="checkbox" name="data_release" value="1"<?= $chk('data_release') ?>> Pflicht zur Datenfreigabe beim Netzbetreiber</label>
    </div>
  </fieldset>

  <fieldset class="card statute-section">
    <legend><h3>D. Organe</h3></legend>
    <div class="statute-grid">
      <label>Vorstand – Anzahl Personen<select name="board_size"><?php foreach ([2, 3, 4, 5, 6] as $n): ?><option value="<?= $n ?>"<?= $sel('board_size', $n) ?>><?= $n ?></option><?php endforeach; ?></select>
        <small>Mindestens zwei natürliche Personen (§ 5 Abs 3 VerG).</small></label>
      <div><span class="statute-label">Funktionen</span>
        <label class="statute-check"><input type="checkbox" checked disabled> Obmann/Obfrau</label>
        <label class="statute-check"><input type="checkbox" checked disabled> Kassier/in</label>
        <label class="statute-check"><input type="checkbox" name="has_secretary" value="1"<?= $chk('has_secretary') ?>> Schriftführer/in</label>
      </div>
      <div><span class="statute-label">Stellvertretungen</span>
        <label class="statute-check"><input type="checkbox" name="deputy_chair" value="1"<?= $chk('deputy_chair') ?>> Obmann-Stv.</label>
        <label class="statute-check"><input type="checkbox" name="deputy_treasurer" value="1"<?= $chk('deputy_treasurer') ?>> Kassier-Stv.</label>
        <label class="statute-check"><input type="checkbox" name="deputy_secretary" value="1"<?= $chk('deputy_secretary') ?>> Schriftführer-Stv.</label>
        <small>Ohne Obmann-Stv. vertritt der Kassier (wird automatisch geregelt).</small>
      </div>
    </div>
    <div class="statute-grid">
      <label>Funktionsperiode Vorstand (Jahre)<select name="board_term"><?php foreach ([1, 2, 3, 4, 5] as $n): ?><option value="<?= $n ?>"<?= $sel('board_term', $n) ?>><?= $n ?></option><?php endforeach; ?></select></label>
      <label>Wiederwahl höchstens (0 = unbegrenzt)<?= $num('reelection_max', 0, 10) ?></label>
      <label class="statute-check" style="align-self:end"><input type="checkbox" name="cooptation" value="1"<?= $chk('cooptation') ?>> Kooptierung erlaubt</label>
    </div>
    <div class="statute-grid">
      <label>Vertretung nach außen<select name="representation"><?= $opt('representation', Q::REPRESENTATION) ?></select></label>
      <label>Gemeinsame Unterschrift Obmann + Kassier ab (€)<?= $num('four_eyes_limit', 0, 10000000) ?></label>
      <div><span class="statute-label">Vorstandsbeschlüsse</span>
        <label class="statute-check"><input type="checkbox" name="casting_vote" value="1"<?= $chk('casting_vote') ?>> Vorsitz entscheidet bei Gleichstand</label>
        <label class="statute-check"><input type="checkbox" name="circular_resolution" value="1"<?= $chk('circular_resolution') ?>> Umlaufbeschlüsse (E-Mail)</label>
      </div>
    </div>
    <div class="statute-grid">
      <label>Rechnungsprüfer – Anzahl<select name="auditors_count"><?php foreach ([2, 3] as $n): ?><option value="<?= $n ?>"<?= $sel('auditors_count', $n) ?>><?= $n ?></option><?php endforeach; ?></select>
        <small>Mindestens zwei, unabhängig, nicht im Vorstand (§ 5 Abs 5 VerG).</small></label>
      <label>Funktionsperiode Rechnungsprüfer (Jahre)<select name="auditors_term"><?php foreach ([1, 2, 3, 4, 5] as $n): ?><option value="<?= $n ?>"<?= $sel('auditors_term', $n) ?>><?= $n ?></option><?php endforeach; ?></select></label>
      <p class="statute-fixed" style="margin:0"><?= icon('lock-key') ?> Schlichtungseinrichtung mit drei Personen (§ 8 VerG) – fest.</p>
    </div>
  </fieldset>

  <fieldset class="card statute-section">
    <legend><h3>E. Mitgliederversammlung</h3></legend>
    <div class="statute-grid">
      <label>Ordentliche Versammlung alle … Jahre<select name="mv_interval"><?php foreach ([1, 2, 3, 4, 5] as $n): ?><option value="<?= $n ?>"<?= $sel('mv_interval', $n) ?>><?= $n === 1 ? 'jährlich (empfohlen)' : $n . ' Jahre' ?></option><?php endforeach; ?></select>
        <small>Höchstens alle 5 Jahre (§ 5 Abs 2 VerG) und nicht seltener als die Funktionsperiode des Vorstands.</small></label>
      <label>Außerordentliche Versammlung binnen … Wochen<select name="extra_mv_weeks"><?php foreach ([2, 3, 4] as $n): ?><option value="<?= $n ?>"<?= $sel('extra_mv_weeks', $n) ?>><?= $n ?></option><?php endforeach; ?></select></label>
      <label>Einladungsfrist (Tage)<select name="invite_days"><?php foreach ([7, 14] as $n): ?><option value="<?= $n ?>"<?= $sel('invite_days', $n) ?>><?= $n ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="statute-grid">
      <label class="statute-check"><input type="checkbox" name="invite_email" value="1"<?= $chk('invite_email') ?>> Einladung per E-Mail</label>
      <label class="statute-check"><input type="checkbox" name="virtual" value="1"<?= $chk('virtual') ?>> Virtuelle/hybride Versammlung erlaubt</label>
      <label>Beschlussfähigkeit<select name="quorum"><?= $opt('quorum', Q::QUORUM) ?></select></label>
    </div>
    <div class="statute-grid">
      <label>Wartezeit bei fehlender Beschlussfähigkeit (Min.)<?= $num('quorum_wait_minutes', 0, 120) ?></label>
      <label class="statute-check"><input type="checkbox" name="proxy" value="1"<?= $chk('proxy') ?>> Stimmrechtsvollmacht erlaubt</label>
      <label>Höchstens … fremde Stimmen je Mitglied<?= $num('proxy_max', 1, 5) ?></label>
    </div>
    <label>Mehrheit für Statutenänderung und Auflösung<select name="majority_change"><?= $opt('majority_change', Q::MAJORITY) ?></select></label>
  </fieldset>

  <fieldset class="card statute-section">
    <legend><h3>F. Auflösung &amp; Datenschutz</h3></legend>
    <label>Vermögen bei Auflösung<select name="dissolution_assets"><?= $opt('dissolution_assets', Q::DISSOLUTION) ?></select></label>
    <p class="statute-fixed"><?= icon('lock-key') ?> Datenschutz-Paragraph (DSGVO/DSG, Weitergabe an Netzbetreiber und EDA, Auftragsverarbeitung, Aufbewahrung 7 Jahre nach § 132 BAO) – fest.</p>
  </fieldset>

  <?php if ($hints): ?>
    <div class="card statute-hints" role="note">
      <h3 style="margin-top:0"><?= icon('info') ?> Hinweise</h3>
      <ul><?php foreach ($hints as $hint): ?><li><?= $h($hint) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="statute-actions">
    <?php if (!empty($adminMode)): ?>
      <button type="submit" class="btn" formaction="<?= $h($formAction) ?>/speichern"><?= icon('floppy-disk') ?> Entwurf speichern</button>
      <button type="submit" class="btn btn-primary" formaction="<?= $h($formAction) ?>/erzeugen" data-confirm="Neue Fassung erzeugen? Der Einladungslink zeigt danach diese Fassung zum Download."><?= icon('file-text') ?> Neue Fassung erzeugen</button>
    <?php else: ?>
      <button type="submit" class="btn" name="aktion" value="speichern"><?= icon('floppy-disk') ?> Zwischenspeichern</button>
      <button type="submit" class="btn btn-primary" name="aktion" value="erzeugen" data-confirm="Statuten jetzt erzeugen? Danach kann der Fragebogen über diesen Link nicht mehr geändert werden."><?= icon('file-text') ?> Statuten erzeugen</button>
    <?php endif; ?>
  </div>
</form>
