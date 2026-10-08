// Espace Argent : verrouillage automatique, mode discret, formulaires qui s'adaptent au type choisi.
(function () {
  var root = document.querySelector('[data-money-root]');

  // Verrouillage : après le délai sans nouvelle page, l'écran est remplacé par le verrou
  // (le serveur est déjà verrouillé ; rien ne reste affiché sur un téléphone posé).
  if (root) {
    var seconds = parseInt(root.getAttribute('data-lock-seconds'), 10) || 900;
    var lockUrl = root.getAttribute('data-lock-url');
    var lockAt = Date.now() + seconds * 1000;
    var check = function () { if (Date.now() >= lockAt) { window.location.replace(lockUrl); } };
    setInterval(check, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { check(); } });
    // Retour arrière vers une page gardée par le navigateur : on revérifie auprès du serveur.
    window.addEventListener('pageshow', function (event) { if (event.persisted) { window.location.reload(); } });
  }

  // Mode discret : montants floutés (retenu sur ce téléphone).
  var discreetKey = 'argent-discret';
  var applyDiscreet = function (on) {
    document.documentElement.classList.toggle('money-discreet', on);
    document.querySelectorAll('[data-money-discreet]').forEach(function (button) { button.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  };
  var discreet = false;
  try { discreet = localStorage.getItem(discreetKey) === '1'; } catch (e) { /* ignoré */ }
  applyDiscreet(discreet);
  document.querySelectorAll('[data-money-discreet]').forEach(function (button) {
    button.addEventListener('click', function () {
      discreet = !discreet;
      applyDiscreet(discreet);
      try { localStorage.setItem(discreetKey, discreet ? '1' : '0'); } catch (e) { /* ignoré */ }
    });
  });

  // Formulaires « selon le type » : data-money-switch="nom du champ" ; les éléments data-when="a b"
  // ne s'affichent que pour ces valeurs ; les listes data-filter-options ne gardent que leurs options.
  document.querySelectorAll('[data-money-switch]').forEach(function (scope) {
    var name = scope.getAttribute('data-money-switch');
    var inputs = scope.querySelectorAll('[name="' + name + '"]');
    var selects = Array.prototype.map.call(scope.querySelectorAll('select[data-filter-options]'), function (select) {
      return { el: select, options: Array.prototype.slice.call(select.options) };
    });

    var current = function () {
      for (var i = 0; i < inputs.length; i++) {
        var input = inputs[i];
        if (input.type === 'radio' ? input.checked : true) { return input.value; }
      }
      return '';
    };
    var matches = function (el, value) { return el.getAttribute('data-when').split(' ').indexOf(value) !== -1; };

    var update = function () {
      var value = current();
      scope.querySelectorAll('[data-when]').forEach(function (el) {
        if (el.tagName === 'OPTION') { return; }
        var show = matches(el, value);
        el.hidden = !show;
        el.querySelectorAll('input, select, textarea').forEach(function (field) { field.disabled = !show; });
      });
      selects.forEach(function (entry) {
        var selected = entry.el.value;
        entry.el.innerHTML = '';
        entry.options.forEach(function (option) {
          if (!option.hasAttribute('data-when') || matches(option, value)) { entry.el.appendChild(option); }
        });
        entry.el.value = Array.prototype.some.call(entry.el.options, function (o) { return o.value === selected; }) ? selected : '';
      });
    };
    inputs.forEach(function (input) { input.addEventListener('change', update); });
    update();
  });

  // Ajout rapide resté ouvert après une erreur de saisie.
  var reopen = document.querySelector('dialog[data-open-on-load]');
  if (reopen && reopen.showModal) { reopen.showModal(); }

  // Aperçu d'un relevé : tout cocher / décocher.
  var all = document.querySelector('[data-check-all]');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('[data-check-item]').forEach(function (box) { box.checked = all.checked; });
    });
  }
})();
