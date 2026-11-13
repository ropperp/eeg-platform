// Zeitraum-Umschalter der Säulendiagramme im Glas-Design (renderBarChart() in src/charts.php):
// zeigt die letzten N Monate und skaliert Achse + Säulen auf den sichtbaren Bereich neu.
(function () {
  'use strict';

  function niceScale(max) {
    if (max <= 0) return [3, 1];
    var raw = max / 3;
    var exp = Math.pow(10, Math.floor(Math.log10(raw)));
    var steps = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < steps.length; i++) {
      if (raw <= steps[i] * exp + 1e-9) return [steps[i] * exp * 3, steps[i] * exp];
    }
    return [raw * 3, raw];
  }

  function fmt(v) {
    var opts = (Math.abs(v) < 10 && Math.floor(v) !== v) ? { minimumFractionDigits: 1, maximumFractionDigits: 1 } : { maximumFractionDigits: 0 };
    return v.toLocaleString('de-DE', opts); // Punkt als Tausendertrennzeichen wie number_format() in charts.php
  }

  function apply(chart, range) {
    var cols = Array.prototype.slice.call(chart.querySelectorAll('[data-col]'));
    var visible = cols.slice(-range);
    var max = 0;
    cols.forEach(function (col) { col.hidden = visible.indexOf(col) === -1; });
    visible.forEach(function (col) {
      col.querySelectorAll('.gchart-bar').forEach(function (b) { max = Math.max(max, parseFloat(b.dataset.v) || 0); });
    });
    var scale = niceScale(max);
    visible.forEach(function (col) {
      col.querySelectorAll('.gchart-bar').forEach(function (b) {
        var v = parseFloat(b.dataset.v) || 0;
        b.style.setProperty('--h', Math.min(100, v / scale[0] * 100) + '%');
      });
    });
    chart.querySelectorAll('[data-tick]').forEach(function (t) { t.textContent = fmt(scale[1] * parseInt(t.dataset.tick, 10)); });
    chart.querySelectorAll('[data-range]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', parseInt(btn.dataset.range, 10) === range ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-gchart]').forEach(function (chart) {
    chart.querySelectorAll('[data-range]').forEach(function (btn) {
      btn.addEventListener('click', function () { apply(chart, parseInt(btn.dataset.range, 10)); });
    });
  });
})();
