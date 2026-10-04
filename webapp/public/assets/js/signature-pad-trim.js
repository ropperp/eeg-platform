/**
 * Schneidet eine Unterschrift-Canvas zu, bevor sie als PNG gespeichert wird.
 *
 * Runde 1 (Patrick, 03.10.2026, zur Beitrittserklärung): "die Unterschrift schwebt eigentlich
 * [...] wäre cool, wenn es mittig vom Strich und auf dem Strich aufliegen würde." -- Ursache: die
 * Unterschrift-Canvas ist immer 600x180px groß, Nutzer unterschreiben aber nur in einem kleinen
 * Teil davon (Rest bleibt transparent, clearRect() statt weißer Füllung). Ohne Zuschnitt landet
 * ein riesiges, größtenteils leeres PNG in der PDF -- wie nah die Tinte am PNG-Rand liegt, war
 * dadurch reiner Zufall statt vorhersehbar.
 *
 * Runde 2 (Patrick, 04.10.2026): "wenn ich mit Ropper unterschreibe, [sollen] die Ps unter die
 * Linie gehen [...] wir können [...] eine Linie im Unterschriftsfeld machen, auf der man fast
 * ganz unten unterschreibt. Wenn man unter die Linie kommt, ist es noch unter der Linie." -- ein
 * reines Zuschneiden auf die Tinten-Bounding-Box (Runde 1) zentriert die Unterschrift zwar
 * zuverlässig auf der gedruckten Linie, "verschluckt" dabei aber die natürliche Position von
 * Unterlängen (z.B. das "p" in "Ropper") relativ zur Linie -- jede Unterschrift sitzt gleich
 * mittig, unabhängig davon, ob sie eigentlich eher ÜBER oder UNTER der Grundlinie verläuft.
 *
 * Fix: eine sichtbare Führungslinie im Canvas (siehe beitreten_formular.php etc., dieselbe
 * SIGNATURE_GUIDE_Y-Pixelposition wie hier) gibt vor, wo die "Grundlinie" beim Unterschreiben
 * liegt. Der Zuschnitt verwendet jetzt ein FESTES Fenster relativ zu dieser Führungslinie (nicht
 * die dynamische Tinten-Bounding-Box) -- jedes exportierte PNG hat die Führungslinie dadurch
 * IMMER an derselben relativen Position, unabhängig von der tatsächlichen Unterschrift. Die
 * LaTeX-Vorlagen (\floatsig-Aufrufe) heben das Bild dadurch um einen fest berechenbaren Betrag
 * an, sodass die Führungslinie exakt auf der gedruckten Linie landet -- Unterlängen erscheinen
 * dadurch automatisch UNTER der gedruckten Linie, genau wie beim echten Unterschreiben auf
 * Papier. Nur falls die Tinte über dieses großzügig bemessene Fenster hinausgeht (ungewöhnlich
 * große Unterschrift), wird das Fenster erweitert, damit nie etwas abgeschnitten wird -- die
 * Positionierung ist dann minimal ungenauer, aber nichts geht verloren.
 *
 * Betrifft jede Stelle, an der eine Unterschrift-Canvas als PNG übernommen wird: Beitritts-
 * formular (inkl. SEPA-Mandat), Vertragsunterschrift (Mitglied), eigene Unterschrift im
 * Obmann-Profil.
 */
var SIGNATURE_GUIDE_Y = 130;  // Pixel-Position der sichtbaren Führungslinie im 600x180-Canvas
var SIGNATURE_ABOVE_PX = 95;  // Platz oberhalb der Führungslinie im Zuschnitt (Signatur-Körper)
var SIGNATURE_BELOW_PX = 35;  // Platz unterhalb der Führungslinie im Zuschnitt (Unterlängen)

function trimSignatureCanvas(canvas, horizontalPadding) {
  horizontalPadding = horizontalPadding || 6;
  var ctx = canvas.getContext('2d');
  var w = canvas.width, h = canvas.height;
  var data = ctx.getImageData(0, 0, w, h).data;
  var minX = w, minY = h, maxX = -1, maxY = -1;

  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      var alpha = data[(y * w + x) * 4 + 3];
      if (alpha > 10) {
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        if (y < minY) minY = y;
        if (y > maxY) maxY = y;
      }
    }
  }

  // Nichts gezeichnet (sollte durch die vorgelagerte "hasSignature"-Prüfung nie vorkommen) --
  // dann einfach die unveränderte Canvas zurückgeben statt eines kaputten 0x0-Bildes.
  if (maxX < 0) return canvas.toDataURL('image/png');

  // Horizontal: dynamisch auf die Tinte zuschneiden (für eine kompakte, mittig zentrierbare
  // Breite -- unabhängig von der Führungslinie, die nur die Höhe betrifft).
  minX = Math.max(0, minX - horizontalPadding);
  maxX = Math.min(w - 1, maxX + horizontalPadding);

  // Vertikal: festes Fenster um die Führungslinie, bei Bedarf erweitert, damit nie etwas
  // abgeschnitten wird (siehe Erklärung oben).
  var guideTop = Math.max(0, SIGNATURE_GUIDE_Y - SIGNATURE_ABOVE_PX);
  var guideBottom = Math.min(h - 1, SIGNATURE_GUIDE_Y + SIGNATURE_BELOW_PX);
  minY = Math.max(0, Math.min(guideTop, minY - horizontalPadding));
  maxY = Math.min(h - 1, Math.max(guideBottom, maxY + horizontalPadding));

  var cropW = maxX - minX + 1;
  var cropH = maxY - minY + 1;
  var out = document.createElement('canvas');
  out.width = cropW;
  out.height = cropH;
  out.getContext('2d').drawImage(canvas, minX, minY, cropW, cropH, 0, 0, cropW, cropH);
  return out.toDataURL('image/png');
}
