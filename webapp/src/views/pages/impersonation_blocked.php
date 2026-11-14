<?php
// Gesperrte Aktion in der Kundenansicht ("Ins Portal des Kunden", siehe Auth::startImpersonation()
// und Router::dispatch()). Bewusst im Portal-Layout: der Balken "Zurück zur Verwaltung" bleibt
// sichtbar.
$pageTitle = 'In der Kundenansicht gesperrt';
ob_start(); ?>
<div class="card" style="max-width:40rem;margin:3rem auto;text-align:center;padding:2.5rem 1.5rem">
  <div style="font-size:3rem;margin-bottom:.75rem"><?= icon('lock-key') ?></div>
  <h1 style="font-size:1.5rem;margin-bottom:.5rem">In der Kundenansicht gesperrt</h1>
  <p style="color:var(--gray-600);margin-bottom:1.5rem">
    Unterschriften, Passwort, Zwei-Faktor-Anmeldung, API-Schlüssel und Nachrichten im Namen des
    Mitglieds kann nur das Mitglied selbst erledigen. Alles andere funktioniert in der
    Kundenansicht genauso wie beim Mitglied.
  </p>
  <a href="javascript:history.back()" class="btn btn-secondary">Zurück</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/portal.php';
