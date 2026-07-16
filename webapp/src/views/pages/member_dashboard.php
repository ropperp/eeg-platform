<?php
$pageTitle = 'Mein Verbrauch';
$communityId = Auth::activeCommunityId();
DB::setCommunity($communityId);

$userId = Auth::userId();
$member = DB::fetchOne(
    'SELECT m.*, mp.id AS mp_id, mp.type AS mp_type, mp.zaehlpunkt_nr
     FROM members m
     JOIN metering_points mp ON mp.member_id = m.id
     WHERE m.user_id = ? AND m.community_id = ? LIMIT 1',
    [$userId, $communityId]
);

ob_start();
?>

<h2 style="margin-bottom:1.5rem">Guten Tag, <?= htmlspecialchars($member['first_name'] ?? Auth::userName()) ?>!</h2>

<!--
  Live-Verbrauch/Autarkie (ESP32-Zeitreihe) und die Community-Autarkie-Kachel sind hier
  bewusst noch nicht für Mitglieder freigeschaltet -- das ESP32/EDA-Zählerdaten-Setup ist auf
  Kundenseite noch nicht produktionsreif. Rechnungen, Verträge und Dokumente (unten) sind davon
  unabhängig und bleiben normal sichtbar. Sobald die Zählerdaten für Mitglieder fertig sind: die
  ursprünglichen esp_measurements-Abfragen + Stat-Kacheln + Chart.js-Zeitreihe (siehe Git-
  Historie dieser Datei vor diesem Commit) hier wieder einsetzen.
-->
<div class="card" style="margin-bottom:1.5rem;text-align:center;padding:2.5rem 1.5rem">
  <div style="font-size:2rem;margin-bottom:.5rem">🚧</div>
  <h3 style="margin-bottom:.5rem">Verbrauchsanzeige in Bearbeitung</h3>
  <p style="color:#6b7280;font-size:.9rem;max-width:32rem;margin:0 auto">
    Die Anzeige Ihres tagesaktuellen Verbrauchs, Ihrer Einspeisung und der Gemeinschafts-Autarkie
    wird gerade aufgebaut und folgt hier in Kürze. Ihre Rechnungen und Verträge finden Sie
    schon jetzt weiter unten.
  </p>
</div>

<div class="card">
  <h3 style="margin-bottom:.75rem">Mein Zählpunkt</h3>
  <p style="font-size:.875rem;color:#6b7280"><?= htmlspecialchars($member['zaehlpunkt_nr'] ?? '—') ?></p>
  <p style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="/portal/invoices" class="btn btn-secondary">🧾 Meine Rechnungen</a>
    <a href="/portal/my/documents" class="btn btn-secondary">📄 Meine Dokumente</a>
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/portal.php';
