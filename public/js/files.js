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

// PDF et justificatifs « à partager » (data-share-file) : sur le téléphone, la feuille de
// partage s'ouvre (Enregistrer dans Fichiers, Mail au comptable…) sans quitter l'app.
// Ailleurs, ouverture normale dans un nouvel onglet.
(function () {
  'use strict';

  var canShareFiles = function () {
    try {
      return !!(navigator.canShare && navigator.canShare({ files: [new File(['x'], 'x.pdf', { type: 'application/pdf' })] }));
    } catch (e) { return false; }
  };
  if (!canShareFiles()) { return; }

  // Partage juste après le téléchargement ; si le téléphone refuse (geste trop ancien),
  // le bouton devient « Partager » et le fichier part au toucher suivant.
  var ready = new WeakMap();
  var label = function (el, text) {
    var target = el.tagName === 'FORM' ? el.querySelector('button[type="submit"]') : el;
    if (target) { target.textContent = text; }
  };
  var share = function (url, el) {
    var file = ready.get(el);
    if (file) {
      ready.delete(el);
      navigator.share({ files: [file], title: file.name }).catch(function () {});
      return;
    }
    el.setAttribute('aria-busy', 'true');
    fetch(url, { credentials: 'same-origin' }).then(function (response) {
      if (!response.ok) { throw new Error('HTTP ' + response.status); }
      var disposition = response.headers.get('Content-Disposition') || '';
      var match = disposition.match(/filename="?([^";]+)"?/);
      var name = match ? match[1] : 'document.pdf';
      return response.blob().then(function (blob) {
        var made = new File([blob], name, { type: blob.type || 'application/pdf' });
        return navigator.share({ files: [made], title: name }).catch(function (error) {
          if (error && error.name === 'NotAllowedError') {
            ready.set(el, made);
            label(el, 'Prêt : toucher pour partager');
            return;
          }
          if (!error || error.name !== 'AbortError') { throw error; }
        });
      });
    }).catch(function () {
      window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'telecharger=1';
    }).then(function () {
      el.removeAttribute('aria-busy');
    });
  };

  document.querySelectorAll('a[data-share-file]').forEach(function (link) {
    link.addEventListener('click', function (event) { event.preventDefault(); share(link.href, link); });
  });
  document.querySelectorAll('form[data-share-file]').forEach(function (form) {
    var button = form.querySelector('button[type="submit"]');
    var original = button ? button.innerHTML : '';
    // Autre mois, autres comptes : le PDF préparé n'est plus le bon.
    form.addEventListener('change', function () {
      if (ready.has(form)) { ready.delete(form); if (button) { button.innerHTML = original; } }
    });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var params = new URLSearchParams(new FormData(form)).toString();
      share(form.action + (form.action.indexOf('?') === -1 ? '?' : '&') + params, form);
    });
  });
})();
