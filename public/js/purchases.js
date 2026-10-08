// Facture prise en photo : réduite dans le téléphone avant l'envoi (2000 px, JPEG),
// pour un envoi rapide et sous la limite du serveur. Les PDF partent tels quels.
(function () {
  'use strict';

  var MAX = 2000;

  var shrink = function (input) {
    var file = input.files && input.files[0];
    if (!file || !/^image\//.test(file.type) || file.size < 400 * 1024 || typeof DataTransfer === 'undefined') { return; }
    var url = URL.createObjectURL(file);
    var img = new Image();
    var done = function () {
      delete input.dataset.pending;
      if (input.form && input.form.dataset.waiting) { delete input.form.dataset.waiting; input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit(); }
    };
    input.dataset.pending = '1';
    img.onload = function () {
      var ratio = Math.min(1, MAX / Math.max(img.naturalWidth, img.naturalHeight));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(img.naturalWidth * ratio);
      canvas.height = Math.round(img.naturalHeight * ratio);
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(url);
      canvas.toBlob(function (blob) {
        if (!blob || blob.size >= file.size) { done(); return; }
        var name = (file.name || 'facture').replace(/\.[^.]+$/, '') + '.jpg';
        var transfer = new DataTransfer();
        transfer.items.add(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
        input.files = transfer.files;
        done();
      }, 'image/jpeg', 0.85);
    };
    img.onerror = function () { URL.revokeObjectURL(url); done(); };
    img.src = url;
  };

  document.querySelectorAll('input[type="file"][data-shrink-image]').forEach(function (input) {
    input.addEventListener('change', function () { shrink(input); });
    // Envoi demandé pendant la réduction de la photo : il part juste après.
    if (input.form) {
      input.form.addEventListener('submit', function (event) {
        if (input.dataset.pending) { event.preventDefault(); event.stopImmediatePropagation(); input.form.dataset.waiting = '1'; }
      }, true);
    }
  });
})();
