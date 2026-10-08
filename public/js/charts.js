// Courbes : info-bulle au survol ou au toucher (valeurs du point), avec un repère vertical.
(function () {
  'use strict';

  document.querySelectorAll('[data-line-chart]').forEach(function (chart) {
    var svg = chart.querySelector('svg');
    var tip = chart.querySelector('.chart-tip');
    var cross = chart.querySelector('.crosshair');
    if (!svg || !tip || !cross) { return; }

    var show = function (hit) {
      var x = parseFloat(hit.getAttribute('data-x'));
      cross.setAttribute('x1', x);
      cross.setAttribute('x2', x);
      cross.removeAttribute('hidden');
      tip.textContent = hit.getAttribute('data-tip');
      tip.hidden = false;
      var box = svg.getBoundingClientRect();
      var scale = box.width / svg.viewBox.baseVal.width;
      var left = Math.max(0, Math.min(box.width - tip.offsetWidth, x * scale - tip.offsetWidth / 2));
      tip.style.left = left + 'px';
    };
    var hide = function () { tip.hidden = true; cross.setAttribute('hidden', ''); };

    svg.querySelectorAll('.hit').forEach(function (hit) {
      hit.addEventListener('pointerenter', function () { show(hit); });
      hit.addEventListener('click', function () { show(hit); });
    });
    svg.addEventListener('pointerleave', function (event) { if (event.pointerType === 'mouse') { hide(); } });
  });
})();
