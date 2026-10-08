// Comportements de l'interface. Aucun framework : chaque bloc est autonome.
(function () {
  'use strict';

  // Bascule clair / sombre.
  document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var root = document.documentElement;
      var current = root.getAttribute('data-theme')
        || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      var next = current === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) { /* ignoré */ }
    });
  });

  // Feuilles (menu « Plus », ajout rapide).
  document.querySelectorAll('[data-open-sheet]').forEach(function (button) {
    button.addEventListener('click', function () {
      var sheet = document.getElementById(button.getAttribute('data-open-sheet'));
      if (sheet && sheet.showModal) { sheet.showModal(); }
    });
  });
  document.querySelectorAll('dialog.sheet').forEach(function (sheet) {
    sheet.addEventListener('click', function (event) {
      if (event.target === sheet) { sheet.close(); }
    });
    sheet.querySelectorAll('[data-close-sheet]').forEach(function (button) {
      button.addEventListener('click', function () { sheet.close(); });
    });
  });

  // Demande de confirmation avant une action sensible.
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
    });
  });

  // Formulaires longs à traiter : bouton désactivé et message d'attente.
  document.querySelectorAll('form[data-busy]').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('[type="submit"]').forEach(function (button) {
        button.disabled = true;
        button.textContent = form.getAttribute('data-busy');
      });
    });
  });

  // Service worker : notifications seulement (aucune page gardée sur l'appareil).
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* navigateur non compatible */ });
  }
})();
