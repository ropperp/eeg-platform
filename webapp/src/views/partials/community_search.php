<?php
// Suchfeld für EEG-Auswahllisten (/beitreten, /datenschutz, /agb, ...): filtert die Karten mit
// der Klasse .community-card clientseitig nach deren data-search (Name + Adresse). Ohne
// JavaScript bleiben einfach alle Einträge sichtbar.
?>
<div class="form-group" style="margin-top:1.25rem;max-width:28rem">
  <label for="community-search" style="font-size:.85rem">Energiegemeinschaft suchen</label>
  <input type="search" id="community-search" placeholder="Name oder Ort eingeben …" autocomplete="off">
</div>
<p id="community-search-empty" style="display:none;color:var(--gray-600)">Keine passende Energiegemeinschaft gefunden.</p>
<script>
(function () {
  var input = document.getElementById('community-search');
  if (!input) return;
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    var visible = 0;
    document.querySelectorAll('.community-card').forEach(function (card) {
      var match = q === '' || (card.getAttribute('data-search') || '').indexOf(q) !== -1;
      card.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    document.getElementById('community-search-empty').style.display = visible === 0 ? '' : 'none';
  });
})();
</script>
