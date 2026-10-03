/**
 * Schneidet eine Unterschrift-Canvas auf die tatsächlich gezeichnete Fläche zu, bevor sie als
 * PNG gespeichert wird -- Patrick, 03.10.2026, zur Beitrittserklärung: "ich bin noch nicht so
 * ganz zufrieden mit der automatischen Ausfüllung [...] die Unterschrift schwebt eigentlich,
 * [...] wäre cool, wenn es mittig vom Strich und auf dem Strich aufliegen würde."
 *
 * Ursache: die Unterschrift-Canvas ist immer 600x180px groß, aber die Nutzer unterschreiben
 * natürlich nur in einem kleinen Teil davon -- der Rest bleibt transparent (clearRect(), kein
 * weißer Hintergrund im Bitmap selbst). Ohne Zuschnitt landet also ein riesiges, größtenteils
 * leeres PNG in der PDF, dessen Platzierung (mittig/auf der Linie) dadurch von der zufälligen
 * Position der Unterschrift INNERHALB der 600x180-Fläche abhängt, statt vom PNG selbst. Mit
 * Zuschnitt entspricht die PNG-Bounding-Box genau der Tinte -- die LaTeX-Positionierung
 * (\floatsig in den Vorlagen) kann das Bild dadurch zuverlässig mittig auf die Linie legen.
 *
 * Betrifft jede Stelle, an der eine Unterschrift-Canvas als PNG übernommen wird: Beitritts-
 * formular, Vertragsunterschrift (Mitglied), eigene Unterschrift im Obmann-Profil.
 */
function trimSignatureCanvas(canvas, padding) {
  padding = padding || 6;
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

  minX = Math.max(0, minX - padding);
  minY = Math.max(0, minY - padding);
  maxX = Math.min(w - 1, maxX + padding);
  maxY = Math.min(h - 1, maxY + padding);

  var cropW = maxX - minX + 1;
  var cropH = maxY - minY + 1;
  var out = document.createElement('canvas');
  out.width = cropW;
  out.height = cropH;
  out.getContext('2d').drawImage(canvas, minX, minY, cropW, cropH, 0, 0, cropW, cropH);
  return out.toDataURL('image/png');
}
