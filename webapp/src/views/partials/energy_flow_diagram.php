<?php
/**
 * Reines Energiefluss-Diagramm (PV -> EEG -> Verbrauch, EEG <-> Netz) -- Markup + Werte, OHNE
 * Karten-Rahmen oder Disclaimer-Texte. Die binden die beiden Aufrufer jeweils selbst ein, weil
 * sie dort unterschiedlich aussehen/liegen:
 * - partials/energy_flow.php (Obmann-/Mitglied-Portal, Karte inkl. "Zählpunkte aktiv"-Hinweis)
 * - pages/live.php (öffentliche Live-Seite, seit 02.10.2026 -- Patrick: "Hier wird aber noch
 *   kein Energieflussdiagramm angezeigt. Da hätte ich auch gern eins")
 *
 * Erwartet $live im Scope, mind. 'bezug_w' und entweder 'einsp_w' (Portal-API-Feldname) oder
 * 'einspeisung_w' (öffentliche API, siehe /api/live/:slug) -- beide werden akzeptiert, damit
 * dieselbe Partial unverändert von beiden Seiten eingebunden werden kann.
 *
 * Die Animations-/Geometrie-Logik liegt in /assets/js/energy-flow.js (requestAnimationFrame-Loop
 * mit eigener, dauerhafter Zeitachse statt der vorherigen SMIL-Fassung, siehe dort für Details
 * zum "Punkt manchmal nicht sichtbar"/"mitten im Fließen unterbrochen"-Vorfall vom 02.10.2026).
 *
 * Netz-Icon "ph-pylon" (assets/icons/phosphor-sprite.svg) ist selbst gezeichnet, kein echtes
 * Phosphor-Icon (die Bibliothek hat keinen Hochspannungsmast) -- Patrick, 02.10.2026: "können
 * wir auch das Netzsymbol so wie bei mir in der Loxone-App nehmen, also so einen Strommasten",
 * ersetzt das bisherige generische Stecker-Icon (ph-plug).
 */
$efEinspW = (float)($live['einsp_w'] ?? $live['einspeisung_w'] ?? 0);
$efBezugW = (float)($live['bezug_w'] ?? 0);
$efNetzW  = $efEinspW - $efBezugW;
$efNetzDirClass = $efNetzW > 0 ? 'eflow-out' : ($efNetzW < 0 ? 'eflow-in' : '');
$efNetzLabel    = $efNetzW > 0 ? 'Netz (Einspeisung)' : ($efNetzW < 0 ? 'Netz (Bezug)' : 'Netz');
?>
<div class="eflow" id="<?= htmlspecialchars($eflowId ?? 'eflow') ?>">
  <svg class="eflow-svg"></svg>
  <div class="eflow-node" data-eflow-node="pv">
    <div class="eflow-circle eflow-circle-pv"><?= icon('sun') ?></div>
    <div class="eflow-text">
      <div class="eflow-value" id="ef-pv"><?= number_format($efEinspW, 0, ',', '.') ?> W</div>
      <div class="eflow-label">PV-Erzeugung</div>
    </div>
  </div>
  <div class="eflow-middle">
    <div class="eflow-node" data-eflow-node="netz">
      <div class="eflow-circle eflow-circle-netz <?= $efNetzDirClass ?>" id="ef-netz-circle"><?= icon('pylon') ?></div>
      <div class="eflow-text">
        <div class="eflow-value" id="ef-netz"><?= number_format(abs($efNetzW), 0, ',', '.') ?> W</div>
        <div class="eflow-label" id="ef-netz-label"><?= $efNetzLabel ?></div>
      </div>
    </div>
    <div class="eflow-hub" data-eflow-node="hub"><span>EEG</span></div>
    <div class="eflow-node" data-eflow-node="verbrauch">
      <div class="eflow-circle eflow-circle-verbrauch"><?= icon('buildings') ?></div>
      <div class="eflow-text">
        <div class="eflow-value" id="ef-verbrauch"><?= number_format($efBezugW, 0, ',', '.') ?> W</div>
        <div class="eflow-label">Verbrauch</div>
      </div>
    </div>
  </div>
</div>
<script src="/assets/js/energy-flow.js?v=<?= @filemtime(ROOT . '/public/assets/js/energy-flow.js') ?: time() ?>"></script>
