// Mode hors ligne de l'app Argent.
//
// Sur le téléphone : un résumé (soldes, derniers mouvements, à venir) chiffré avec une clé
// propre à l'appareil (AES-GCM 256), clé elle-même chiffrée avec le code Argent (PBKDF2,
// 600 000 tours). Sans le code, rien n'est lisible. Les mouvements notés sans réseau sont
// chiffrés de la même façon et envoyés dès que l'app est rouverte avec du réseau.
//
// Trois usages selon la page : [data-offline-app] (app ouverte : met le résumé à jour,
// envoie les mouvements en attente), [data-offline-settings] (Réglages : activer,
// désactiver), [data-offline-shell] (page sans réseau).
(function () {
  'use strict';

  var PREFIX = 'argent.offline.';
  var ITERATIONS = 600000;
  var REFRESH_MS = 2 * 60 * 1000;
  var MAX_TRIES = 10;
  var BLOCK_EVERY = 5;
  var BLOCK_MS = 15 * 60 * 1000;
  var IDLE_MS = 5 * 60 * 1000;
  var enc = new TextEncoder();
  var dec = new TextDecoder();
  var supported = !!(window.crypto && window.crypto.subtle && window.localStorage);

  // --- Rangement local -------------------------------------------------------------------
  var get = function (name) {
    try { var value = localStorage.getItem(PREFIX + name); return value ? JSON.parse(value) : null; } catch (e) { return null; }
  };
  var set = function (name, value) {
    try { localStorage.setItem(PREFIX + name, JSON.stringify(value)); return true; } catch (e) { return false; }
  };
  var del = function (name) { try { localStorage.removeItem(PREFIX + name); } catch (e) { /* rien */ } };
  var wipe = function () { ['wrap', 'snapshot', 'queue', 'tries'].forEach(del); };

  // --- Chiffrement -----------------------------------------------------------------------
  var toB64 = function (buffer) {
    var bytes = new Uint8Array(buffer), text = '';
    for (var i = 0; i < bytes.length; i++) { text += String.fromCharCode(bytes[i]); }
    return btoa(text);
  };
  var fromB64 = function (text) {
    var raw = atob(text), bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }
    return bytes;
  };
  var random = function (n) { return crypto.getRandomValues(new Uint8Array(n)); };
  var aesKey = function (raw) { return crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['encrypt', 'decrypt']); };
  var codeKey = function (code, salt, iterations) {
    return crypto.subtle.importKey('raw', enc.encode(code), 'PBKDF2', false, ['deriveKey']).then(function (base) {
      return crypto.subtle.deriveKey({ name: 'PBKDF2', salt: salt, iterations: iterations, hash: 'SHA-256' }, base,
        { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    });
  };
  var seal = function (key, value) {
    var iv = random(12);
    var plain = value instanceof Uint8Array ? value : enc.encode(JSON.stringify(value));
    return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, plain).then(function (buffer) {
      return { iv: toB64(iv), data: toB64(buffer) };
    });
  };
  var openBox = function (key, box, raw) {
    return crypto.subtle.decrypt({ name: 'AES-GCM', iv: fromB64(box.iv) }, key, fromB64(box.data)).then(function (buffer) {
      return raw ? new Uint8Array(buffer) : JSON.parse(dec.decode(buffer));
    });
  };
  var fingerprint = function (raw) {
    return crypto.subtle.digest('SHA-256', raw).then(function (hash) { return toB64(hash).slice(0, 16); });
  };

  // Clé de l'appareil chiffrée avec le code (à l'activation, ou quand le code a changé).
  var wrapKey = function (raw, code, version) {
    var salt = random(16);
    return Promise.all([codeKey(code, salt, ITERATIONS), fingerprint(raw)]).then(function (keys) {
      return seal(keys[0], raw).then(function (box) {
        set('wrap', { v: 1, salt: toB64(salt), iterations: ITERATIONS, iv: box.iv, data: box.data, check: keys[1], version: version });
      });
    });
  };
  // Code → clé de l'appareil (échoue si le code est faux).
  var unwrapKey = function (code) {
    var wrap = get('wrap');
    return codeKey(code, fromB64(wrap.salt), wrap.iterations).then(function (key) {
      return openBox(key, wrap, true);
    }).then(aesKey);
  };

  // --- Format ----------------------------------------------------------------------------
  var money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  var euros = function (cents, signed) { return (signed && cents > 0 ? '+' : '') + money.format(cents / 100).replace('-', '−'); };
  var day = function (iso) { var p = iso.split('-'); return p[2] + '/' + p[1]; };
  var parseAmount = function (text) {
    var value = String(text || '').replace(/[\s  €]/g, '').replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(value)) { return null; }
    var parts = value.split('.');
    return parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
  };
  var uid = function () { return Array.prototype.map.call(random(16), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); };
  var el = function (tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (name) {
      if (name === 'text') { node.textContent = attrs[name]; } else if (attrs[name] !== null && attrs[name] !== undefined) { node.setAttribute(name, attrs[name]); }
    });
    (children || []).forEach(function (child) { if (child) { node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child); } });
    return node;
  };
  var csrf = function () { var meta = document.querySelector('meta[name="csrf-token"]'); return meta ? meta.content : ''; };

  // --- App ouverte : résumé à jour, mouvements en attente envoyés --------------------------
  var refreshSnapshot = function (key, url, force) {
    var snapshot = get('snapshot');
    if (!force && snapshot && Date.now() - snapshot.at < REFRESH_MS) { return Promise.resolve(); }
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (response) {
      if (!response.ok) { throw new Error('HTTP ' + response.status); }
      return response.json();
    }).then(function (data) {
      return seal(key, data).then(function (box) { set('snapshot', { at: Date.now(), box: box }); });
    });
  };

  var sendQueue = function (key, url) {
    var queue = get('queue') || [];
    if (!queue.length) { return Promise.resolve(0); }
    return Promise.all(queue.map(function (box) { return openBox(key, box).catch(function () { return null; }); })).then(function (entries) {
      entries = entries.filter(Boolean);
      if (!entries.length) { del('queue'); return 0; }
      return fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ entries: entries })
      }).then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        return response.json();
      }).then(function (result) {
        // Envoyés (ou déjà reçus) : retirés de la file ; une ligne refusée l'est aussi.
        del('queue');
        var refused = (result.refused || []).length;
        try {
          sessionStorage.setItem(PREFIX + 'flash', (result.created ? result.created + ' mouvement' + (result.created > 1 ? 's notés' : ' noté') + ' hors ligne ' + (result.created > 1 ? 'ont été ajoutés' : 'a été ajouté') + '.' : '')
            + (refused ? ' ' + refused + ' refusé' + (refused > 1 ? 's' : '') + ' (montant ou compte invalide).' : ''));
        } catch (e) { /* rien */ }
        return result.created || 0;
      });
    });
  };

  var showFlash = function () {
    var text = null;
    try { text = sessionStorage.getItem(PREFIX + 'flash'); sessionStorage.removeItem(PREFIX + 'flash'); } catch (e) { return; }
    var container = document.querySelector('.main .container');
    if (text && text.trim() && container) {
      container.insertBefore(el('div', { 'class': 'alert alert-success', role: 'status', text: text.trim() }), container.firstChild);
    }
  };

  var appSide = function (node) {
    showFlash();
    var wrap = get('wrap');
    var keyText = node.getAttribute('data-key') || '';
    if (!supported || !wrap || !keyText) { return; }
    var raw = fromB64(keyText);
    fingerprint(raw).then(function (check) {
      if (check !== wrap.check) { return; }
      return aesKey(raw).then(function (key) {
        return sendQueue(key, node.getAttribute('data-sync-url')).then(function (created) {
          if (created) { window.location.reload(); return; }
          return refreshSnapshot(key, node.getAttribute('data-data-url'), false);
        });
      });
    }).catch(function () { /* réseau capricieux : on réessaiera à la prochaine page */ });

    // La page hors ligne gardée par le téléphone est rafraîchie de temps en temps.
    var last = parseInt(get('shell') || '0', 10);
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller && Date.now() - last > 6 * 3600 * 1000) {
      navigator.serviceWorker.controller.postMessage({ type: 'refresh-offline' });
      set('shell', Date.now());
    }
    var warned = true;
    try { warned = !!sessionStorage.getItem(PREFIX + 'warned'); } catch (e) { /* rien */ }
    if (wrap.version !== node.getAttribute('data-version') && !warned && !document.querySelector('[data-offline-settings]')) {
      try { sessionStorage.setItem(PREFIX + 'warned', '1'); } catch (e) { /* rien */ }
      var container = document.querySelector('.main .container');
      if (container) {
        var link = el('a', { href: (node.getAttribute('data-settings-url') || '/reglages') + '#hors-ligne', text: 'Mettre à jour' });
        container.insertBefore(el('div', { 'class': 'alert alert-warning', role: 'status' }, ['Mode hors ligne : votre code Argent a changé, retapez-le pour continuer à ouvrir l\'app sans réseau. ', link]), container.firstChild);
      }
    }
  };

  // --- Réglages : activer, mettre à jour, désactiver --------------------------------------
  var settingsSide = function (card) {
    var app = document.querySelector('[data-offline-app]');
    var status = card.querySelector('[data-offline-status]');
    var form = card.querySelector('[data-offline-form]');
    var input = card.querySelector('[data-offline-code]');
    var button = form.querySelector('button[type="submit"]');
    var off = card.querySelector('[data-offline-off]');
    var say = function (text, tone) { status.textContent = text; status.className = 'small ' + (tone || ''); };

    var render = function () {
      var wrap = get('wrap');
      var snapshot = get('snapshot');
      if (!supported) { say('Ce navigateur ne permet pas le mode hors ligne.', 'm-neg'); form.hidden = true; off.hidden = true; return; }
      if (!wrap) {
        say('Pas activé sur cet appareil.', 'muted');
        button.textContent = 'Activer sur cet appareil';
        off.hidden = true;
      } else if (wrap.version !== app.getAttribute('data-version') || !app.getAttribute('data-key')) {
        say('Votre code a changé (ou la clé de l\'appareil a été effacée) : retapez votre code pour mettre à jour.', 'm-neg');
        button.textContent = 'Mettre à jour';
        off.hidden = false;
      } else {
        say('Activé sur cet appareil' + (snapshot ? ' · données du ' + new Date(snapshot.at).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '') + '.', 'm-pos');
        form.hidden = true;
        off.hidden = false;
        return;
      }
      form.hidden = false;
    };

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var code = input.value.trim();
      if (!/^\d{4,8}$/.test(code)) { say('Tapez votre code Argent (4 à 8 chiffres).', 'm-neg'); return; }
      button.disabled = true;
      say('Activation… (quelques secondes)', 'muted');
      // Clé déjà sur l'appareil (cookie) : gardée, pour ne rien perdre ; sinon une nouvelle.
      var current = app.getAttribute('data-key');
      var raw = current ? fromB64(current) : random(32);
      fetch(form.getAttribute('action'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ code: code, key: toB64(raw) })
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (body) {
          if (!response.ok) { throw new Error(body.message || 'Activation impossible. Réessayez.'); }
          return body;
        });
      }).then(function (body) {
        return fingerprint(raw).then(function (check) {
          // Autre clé que celle du résumé gardé : il n'est plus lisible, il est retiré.
          var wrap = get('wrap');
          if (!wrap || wrap.check !== check) { del('snapshot'); del('queue'); }
          return wrapKey(raw, code, body.version);
        }).then(function () {
          app.setAttribute('data-key', toB64(raw));
          app.setAttribute('data-version', body.version);
          del('tries');
          return aesKey(raw);
        }).then(function (key) { return refreshSnapshot(key, app.getAttribute('data-data-url'), true); });
      }).then(function () {
        input.value = '';
        render();
      }).catch(function (error) {
        say(error.message || 'Activation impossible. Réessayez.', 'm-neg');
      }).then(function () { button.disabled = false; });
    });

    off.addEventListener('click', function () {
      var pending = (get('queue') || []).length;
      if (!window.confirm((pending ? pending + ' mouvement(s) noté(s) hors ligne pas encore envoyé(s) seront perdus. ' : '') + 'Désactiver le mode hors ligne et effacer les données gardées sur cet appareil ?')) { return; }
      fetch(off.getAttribute('data-url'), {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }
      }).catch(function () { /* effacé ici quand même */ }).then(function () {
        wipe();
        app.setAttribute('data-key', '');
        render();
      });
    });

    render();
  };

  // --- Page sans réseau --------------------------------------------------------------------
  var shellSide = function (root) {
    var parts = {};
    ['setup', 'lock', 'main', 'online', 'unsupported'].forEach(function (name) { parts[name] = root.querySelector('[data-part="' + name + '"]'); });
    var lockForm = root.querySelector('[data-offline-unlock]');
    var codeInput = lockForm.querySelector('input');
    var lockMessage = root.querySelector('[data-offline-message]');
    var key = null;
    var data = null;
    var pending = [];
    var idle = null;
    var hiddenAt = null;

    var show = function (name) {
      ['setup', 'lock', 'main', 'unsupported'].forEach(function (part) { parts[part].hidden = part !== name; });
    };
    var lock = function () {
      key = null; data = null; pending = [];
      parts.main.querySelector('[data-render]').textContent = '';
      codeInput.value = '';
      show(get('wrap') ? 'lock' : 'setup');
    };
    var touch = function () {
      clearTimeout(idle);
      if (key) { idle = setTimeout(lock, IDLE_MS); }
    };
    ['click', 'keydown', 'touchstart'].forEach(function (name) { document.addEventListener(name, touch, { passive: true }); });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { hiddenAt = Date.now(); } else if (hiddenAt && Date.now() - hiddenAt > 30000) { lock(); }
    });

    // Le réseau est-il vraiment revenu (et le serveur joignable) ? Vérifié de temps en temps.
    var onlineBanner = function () {
      if (!navigator.onLine) { parts.online.hidden = true; return; }
      fetch('/manifest.webmanifest?ping=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (response) { parts.online.hidden = !response.ok; })
        .catch(function () { parts.online.hidden = true; });
    };
    window.addEventListener('online', onlineBanner);
    window.addEventListener('offline', onlineBanner);
    setInterval(onlineBanner, 20000);
    onlineBanner();

    var tries = function () { return get('tries') || { count: 0, until: 0 }; };
    var blocked = function () {
      var t = tries();
      if (t.until > Date.now()) {
        lockMessage.textContent = 'Trop de codes faux : réessayez dans ' + Math.ceil((t.until - Date.now()) / 60000) + ' min.';
        return true;
      }
      return false;
    };

    lockForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (blocked()) { return; }
      var button = lockForm.querySelector('button');
      button.disabled = true;
      lockMessage.textContent = 'Ouverture…';
      unwrapKey(codeInput.value.trim()).then(function (found) {
        key = found;
        del('tries');
        lockMessage.textContent = '';
        return load();
      }).catch(function () {
        var t = tries();
        t.count += 1;
        if (t.count >= MAX_TRIES) {
          wipe();
          lockMessage.textContent = MAX_TRIES + ' codes faux : les données hors ligne de ce téléphone ont été effacées.';
          show('setup');
          return;
        }
        if (t.count % BLOCK_EVERY === 0) { t.until = Date.now() + BLOCK_MS; }
        set('tries', t);
        lockMessage.textContent = 'Code faux.' + (t.until > Date.now() ? ' Réessayez dans 15 min.' : ' Encore ' + (MAX_TRIES - t.count) + ' essai(s) avant effacement.');
      }).then(function () { button.disabled = false; codeInput.value = ''; });
    });

    var load = function () {
      var snapshot = get('snapshot');
      return (snapshot ? openBox(key, snapshot.box) : Promise.resolve(null)).then(function (snap) {
        data = snap ? { at: snapshot.at, content: snap } : null;
        return Promise.all((get('queue') || []).map(function (box) { return openBox(key, box).catch(function () { return null; }); }));
      }).then(function (entries) {
        pending = entries.filter(Boolean);
        render();
        show('main');
        touch();
      });
    };

    var addEntry = function (entry) {
      return seal(key, entry).then(function (box) {
        var queue = get('queue') || [];
        queue.push(box);
        if (!set('queue', queue)) { throw new Error('Plus de place sur le téléphone.'); }
        pending.push(entry);
      });
    };
    var removeEntry = function (id) {
      // Ré-écrit la file sans cette ligne.
      pending = pending.filter(function (entry) { return entry.uid !== id; });
      return Promise.all(pending.map(function (entry) { return seal(key, entry); })).then(function (boxes) { set('queue', boxes); render(); });
    };

    var section = function (title, children) {
      return el('div', { 'class': 'card' }, [el('h2', { text: title })].concat(children));
    };
    var row = function (left, right, rightClass) {
      return el('li', {}, [left, el('strong', { 'class': 'm-amt ' + (rightClass || ''), text: right })]);
    };

    var render = function () {
      var target = parts.main.querySelector('[data-render]');
      target.textContent = '';
      var c = data ? data.content : null;
      var accountsById = {};
      if (c) { c.accounts.forEach(function (a) { accountsById[a.id] = a; }); }

      target.appendChild(el('p', { 'class': 'small muted offline-when' }, [c
        ? 'Données du ' + new Date(data.at).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) + ' (dernière ouverture avec réseau).'
        : 'Pas encore de données gardées : ouvrez l\'app une fois avec du réseau.']));

      if (c) {
        var total = c.accounts.reduce(function (sum, a) { return sum + a.balance; }, 0);
        var all = c.totals.all;
        target.appendChild(el('div', { 'class': 'grid money-mini' }, [
          el('div', { 'class': 'card kpi' }, [el('span', { 'class': 'label', text: 'Solde total' }), el('span', { 'class': 'value m-amt', text: euros(total) })]),
          el('div', { 'class': 'card kpi' }, [el('span', { 'class': 'label', text: 'Gagné en ' + c.month }), el('span', { 'class': 'value m-pos m-amt', text: euros(all.income) })]),
          el('div', { 'class': 'card kpi' }, [el('span', { 'class': 'label', text: 'Dépensé en ' + c.month }), el('span', { 'class': 'value m-neg m-amt', text: euros(all.expense) })])
        ]));
      }

      // Noter un mouvement sans réseau.
      var form = el('form', { 'class': 'card', novalidate: 'novalidate' });
      var accountSelect = el('select', { id: 'off-account', name: 'account_id' }, (c ? c.accounts : []).map(function (a) { return el('option', { value: a.id, text: a.name }); }));
      var categorySelect = el('select', { id: 'off-category', name: 'category_id' }, [el('option', { value: '', text: '— Sans catégorie —' })]);
      var typeValue = 'expense';
      var fillCategories = function () {
        categorySelect.textContent = '';
        categorySelect.appendChild(el('option', { value: '', text: '— Sans catégorie —' }));
        (c ? c.categories : []).filter(function (cat) { return cat.type === typeValue; }).forEach(function (cat) { categorySelect.appendChild(el('option', { value: cat.id, text: cat.name })); });
      };
      var typeSwitch = el('div', { 'class': 'type-switch two', role: 'radiogroup', 'aria-label': 'Type' }, ['expense', 'income'].map(function (type) {
        var radio = el('input', { type: 'radio', name: 'type', value: type });
        radio.checked = type === typeValue;
        radio.addEventListener('change', function () { typeValue = type; fillCategories(); });
        return el('label', {}, [radio, type === 'expense' ? ' Dépense' : ' Revenu']);
      }));
      fillCategories();
      var amount = el('input', { id: 'off-amount', 'class': 'amount-input', type: 'text', inputmode: 'decimal', placeholder: '0,00', autocomplete: 'off', required: 'required' });
      var label = el('input', { id: 'off-label', type: 'text', maxlength: '160', placeholder: 'ex. Plein, matériaux, repas…' });
      var date = el('input', { id: 'off-date', type: 'date', required: 'required' });
      date.value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
      var notes = el('input', { id: 'off-notes', type: 'text', maxlength: '500', placeholder: 'facultatif' });
      var message = el('p', { 'class': 'small', role: 'status' });
      var field = function (id, text, input) { return el('div', { 'class': 'field' }, [el('label', { 'for': id, text: text }), input]); };
      form.appendChild(el('h2', { text: 'Noter un mouvement' }));
      form.appendChild(el('p', { 'class': 'small muted', text: 'Gardé chiffré sur le téléphone, puis ajouté tout seul à la prochaine ouverture de l\'app avec du réseau.' }));
      form.appendChild(typeSwitch);
      form.appendChild(field('off-amount', 'Montant (€)', amount));
      form.appendChild(el('div', { 'class': 'form-grid cols-2', style: 'margin-top:1rem' }, [
        field('off-account', 'Compte', accountSelect), field('off-category', 'Catégorie', categorySelect),
        field('off-label', 'Libellé', label), field('off-date', 'Date', date), field('off-notes', 'Note', notes)
      ]));
      form.appendChild(message);
      form.appendChild(el('div', { 'class': 'form-actions' }, [el('button', { 'class': 'btn btn-block', type: 'submit', text: 'Noter' })]));
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var cents = parseAmount(amount.value);
        if (!cents) { message.textContent = 'Indiquez un montant (ex. 12,50).'; message.className = 'small m-neg'; return; }
        if (!accountSelect.value) { message.textContent = 'Aucun compte : ouvrez d\'abord l\'app avec du réseau.'; message.className = 'small m-neg'; return; }
        addEntry({
          uid: uid(), type: typeValue, amount: cents, account_id: parseInt(accountSelect.value, 10),
          category_id: categorySelect.value ? parseInt(categorySelect.value, 10) : null,
          label: label.value.trim(), occurred_on: date.value, notes: notes.value.trim(), noted_at: Date.now()
        }).then(function () {
          render();
          var fresh = parts.main.querySelector('[data-render] form p[role="status"]');
          if (fresh) { fresh.textContent = 'Noté. Il sera envoyé au retour du réseau.'; fresh.className = 'small m-pos'; }
        }).catch(function (error) { message.textContent = error.message; message.className = 'small m-neg'; });
      });
      target.appendChild(form);

      if (pending.length) {
        target.appendChild(section('En attente d\'envoi (' + pending.length + ')', [
          el('ul', { 'class': 'stat-list' }, pending.map(function (entry) {
            var remove = el('button', { 'class': 'link-btn small', type: 'button', text: 'Retirer' });
            remove.addEventListener('click', function () { if (window.confirm('Retirer ce mouvement noté hors ligne ?')) { removeEntry(entry.uid); } });
            var account = accountsById[entry.account_id];
            return el('li', {}, [
              el('span', {}, [day(entry.occurred_on) + ' · ' + (entry.label || (entry.type === 'expense' ? 'Dépense' : 'Revenu')), el('span', { 'class': 'small muted', text: account ? ' · ' + account.name : '' })]),
              el('span', { 'class': 'loan-entry-actions' }, [el('strong', { 'class': 'm-amt ' + (entry.type === 'expense' ? 'm-neg' : 'm-pos'), text: euros(entry.type === 'expense' ? -entry.amount : entry.amount, true) }), remove])
            ]);
          })),
          el('p', { 'class': 'small muted', text: 'Pas encore comptés dans les soldes ci-dessus.' })
        ]));
      }

      if (c) {
        target.appendChild(section('Comptes', [el('ul', { 'class': 'stat-list' }, c.accounts.map(function (a) {
          return row(el('span', {}, [el('span', { 'class': 'swatch-dot', style: 'background:' + (/^#[0-9A-Fa-f]{6}$/.test(a.color) ? a.color : '#8A99A6') }), a.name]), euros(a.balance));
        }))]));
        if (c.upcoming.length) {
          target.appendChild(section('À venir', [el('ul', { 'class': 'stat-list' }, c.upcoming.map(function (u) {
            return row(el('span', { text: day(u.date) + ' · ' + u.label }), euros(u.amount, !u.transfer), u.transfer ? '' : (u.amount < 0 ? 'm-neg' : 'm-pos'));
          })), el('p', { 'class': 'money-note' }, ['Solde estimé fin du mois : ', el('strong', { 'class': 'm-amt', text: euros(c.forecast) })])]));
        }
        if (c.budgets.length) {
          target.appendChild(section('Budgets du mois', c.budgets.map(function (b) {
            var percent = Math.round(b.spent * 100 / Math.max(1, b.budget));
            return el('div', { style: 'margin-bottom:.6rem' }, [
              el('div', { 'class': 'goal-head' }, [el('span', { text: b.name }), el('span', { 'class': 'small m-amt', text: euros(b.spent) + ' / ' + euros(b.budget) })]),
              el('div', { 'class': 'progress is-' + (percent > 100 ? 'danger' : (percent >= 85 ? 'warning' : 'success')) }, [el('span', { style: 'width:' + Math.min(100, percent) + '%' })])
            ]);
          })));
        }
        if (c.goals.length) {
          target.appendChild(section('Objectifs', [el('ul', { 'class': 'stat-list' }, c.goals.map(function (g) {
            return row(el('span', { text: g.name + ' · ' + g.percent + ' %' }), euros(g.current) + ' / ' + euros(g.target));
          }))]));
        }
        if (c.owed.to_me || c.owed.by_me) {
          target.appendChild(section('Qui me doit quoi', [el('ul', { 'class': 'stat-list' }, [
            row(el('span', { text: 'On vous doit' }), euros(c.owed.to_me)),
            row(el('span', { text: 'Vous devez' }), euros(c.owed.by_me))
          ])]));
        }
        target.appendChild(section('Derniers mouvements', [el('ul', { 'class': 'stat-list' }, c.latest.slice(0, 20).map(function (t) {
          return row(el('span', { 'class': 'cal-line' }, [
            el('span', { 'class': 'swatch-dot', style: 'background:' + (t.color && /^#[0-9A-Fa-f]{6}$/.test(t.color) ? t.color : '#B0BEC5') }),
            day(t.date) + ' · ' + t.label, el('span', { 'class': 'small muted', text: t.account ? ' · ' + t.account : '' })
          ]), euros(t.amount, !t.transfer), t.transfer ? '' : (t.amount < 0 ? 'm-neg' : 'm-pos'));
        }))]));
      }
    };

    root.querySelector('[data-offline-lock]').addEventListener('click', lock);
    root.querySelectorAll('[data-offline-retry]').forEach(function (button) {
      button.addEventListener('click', function () { window.location.href = '/'; });
    });

    if (!supported) { show('unsupported'); return; }
    lock();
    blocked();
  };

  var app = document.querySelector('[data-offline-app]');
  if (app) { appSide(app); }
  var settings = document.querySelector('[data-offline-settings]');
  if (settings && app) { settingsSide(settings); }
  var shell = document.querySelector('[data-offline-shell]');
  if (shell) { shellSide(shell); }
})();
