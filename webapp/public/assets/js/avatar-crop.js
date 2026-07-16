/**
 * Zoom/Verschieben-Zuschnitt für Profilbild-Uploads. Zeigt nach Dateiauswahl eine runde
 * Live-Vorschau (Canvas), in der per Zoom-Regler und Ziehen mit der Maus/dem Finger der
 * Bildausschnitt zentriert werden kann. Beim Absenden wird der zugeschnittene Ausschnitt als
 * PNG anstelle der Originaldatei hochgeladen -- der Server bekommt dadurch ganz normal eine
 * Datei im "photo"-Feld, es sind keine Backend-Änderungen nötig (Anzeige erfolgt ohnehin via
 * object-fit:cover + border-radius:50%, weshalb ein quadratischer Ausschnitt genügt).
 *
 * Ohne JavaScript (oder falls initAvatarCropper aus irgendeinem Grund fehlschlägt) wird
 * einfach die Originaldatei wie bisher hochgeladen -- keine Pflicht-Abhängigkeit.
 */
function initAvatarCropper(opts) {
  const fileInput = document.getElementById(opts.fileInputId);
  const wrapper   = document.getElementById(opts.wrapperId);
  const canvas    = document.getElementById(opts.canvasId);
  const zoomInput = document.getElementById(opts.zoomId);
  const form      = fileInput.closest('form');
  if (!fileInput || !wrapper || !canvas || !zoomInput || !form) return;

  const ctx = canvas.getContext('2d');
  const size = canvas.width; // quadratisch, z.B. 220
  let img = null;
  let baseScale = 1, offsetX = 0, offsetY = 0;
  let dragging = false, dragStartX = 0, dragStartY = 0, dragOffsetX0 = 0, dragOffsetY0 = 0;
  let readyBlobUrl = null;

  function effectiveScale() {
    return baseScale * (parseInt(zoomInput.value, 10) / 100);
  }

  function clampOffsets() {
    const s = effectiveScale();
    const drawW = img.width * s, drawH = img.height * s;
    const maxX = Math.max(0, (drawW - size) / 2);
    const maxY = Math.max(0, (drawH - size) / 2);
    offsetX = Math.min(maxX, Math.max(-maxX, offsetX));
    offsetY = Math.min(maxY, Math.max(-maxY, offsetY));
  }

  function draw() {
    if (!img) return;
    clampOffsets();
    const s = effectiveScale();
    const drawW = img.width * s, drawH = img.height * s;
    ctx.clearRect(0, 0, size, size);
    ctx.drawImage(img, size / 2 - drawW / 2 + offsetX, size / 2 - drawH / 2 + offsetY, drawW, drawH);
  }

  fileInput.addEventListener('change', function () {
    const file = fileInput.files[0];
    if (!file) { wrapper.style.display = 'none'; return; }
    const reader = new FileReader();
    reader.onload = function (e) {
      const image = new Image();
      image.onload = function () {
        img = image;
        baseScale = size / Math.min(image.width, image.height);
        offsetX = 0; offsetY = 0;
        zoomInput.value = 100;
        wrapper.style.display = '';
        draw();
      };
      image.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });

  zoomInput.addEventListener('input', draw);

  function startDrag(x, y) {
    dragging = true;
    dragStartX = x; dragStartY = y;
    dragOffsetX0 = offsetX; dragOffsetY0 = offsetY;
  }
  function moveDrag(x, y) {
    if (!dragging) return;
    offsetX = dragOffsetX0 + (x - dragStartX);
    offsetY = dragOffsetY0 + (y - dragStartY);
    draw();
  }
  function endDrag() { dragging = false; }

  canvas.addEventListener('mousedown', (e) => startDrag(e.clientX, e.clientY));
  window.addEventListener('mousemove', (e) => moveDrag(e.clientX, e.clientY));
  window.addEventListener('mouseup', endDrag);
  canvas.addEventListener('touchstart', (e) => {
    const t = e.touches[0]; startDrag(t.clientX, t.clientY);
  }, { passive: true });
  canvas.addEventListener('touchmove', (e) => {
    const t = e.touches[0]; moveDrag(t.clientX, t.clientY);
  }, { passive: true });
  canvas.addEventListener('touchend', endDrag);

  let submitting = false;
  form.addEventListener('submit', function (e) {
    if (submitting || !img) return; // kein Bild gewählt/geladen -> normaler Upload ohne Zuschnitt
    e.preventDefault();
    canvas.toBlob(function (blob) {
      const croppedFile = new File([blob], 'avatar.png', { type: 'image/png' });
      const dt = new DataTransfer();
      dt.items.add(croppedFile);
      fileInput.files = dt.files;
      submitting = true;
      form.submit();
    }, 'image/png', 0.92);
  });
}
