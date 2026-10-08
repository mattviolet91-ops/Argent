// Activation des notifications sur cet appareil (Réglages).
(function () {
  'use strict';

  var box = document.querySelector('[data-push]');
  if (!box) { return; }

  var status = box.querySelector('[data-push-status]');
  var enable = box.querySelector('[data-push-enable]');
  var disable = box.querySelector('[data-push-disable]');
  var test = box.querySelector('[data-push-test]');
  var token = box.querySelector('[name="_token"]').value;
  var key = box.getAttribute('data-push-key');

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      if (!r.ok) {
        return r.text().then(function (t) { throw new Error('Erreur ' + r.status + (r.status === 419 ? ' (page expirée : rechargez la page)' : '') + ' ' + t.slice(0, 120)); });
      }
      return r.json();
    });
  }

  function report(r) {
    if (r.sent) { status.textContent = 'Notification de test envoyée à ' + r.sent + ' appareil(s). Elle doit apparaître dans quelques secondes.'; }
    else if (!r.devices) { status.textContent = 'Aucun appareil abonné : appuyez sur « Désactiver » puis « Activer » de nouveau.'; }
    else { status.textContent = 'La notification n\'est pas partie : ' + (r.errors || []).join(' / '); }
  }

  function urlBase64ToUint8Array(base64) {
    var padding = '='.repeat((4 - base64.length % 4) % 4);
    var raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
    return out;
  }

  function show(state, message) {
    status.textContent = message;
    enable.hidden = state !== 'off';
    disable.hidden = state !== 'on';
    test.hidden = state !== 'on';
  }

  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    show('none', /iPhone|iPad/.test(navigator.userAgent) && !standalone
      ? 'Sur iPhone : ouvrez l\'application depuis l\'icône de votre écran d\'accueil (iOS 16.4 ou plus récent), puis revenez ici.'
      : 'Ce navigateur ne gère pas les notifications.');
    return;
  }

  navigator.serviceWorker.register('/sw.js').then(function (registration) {
    return registration.pushManager.getSubscription().then(function (subscription) {
      if (Notification.permission === 'denied') {
        show('none', 'Les notifications sont bloquées pour cette application : autorisez-les dans les réglages du téléphone.');
      } else if (subscription) {
        post(box.getAttribute('data-subscribe'), subscription.toJSON()).catch(function () {});
        show('on', 'Notifications activées sur cet appareil.');
      } else {
        show('off', 'Notifications désactivées sur cet appareil.');
      }

      enable.addEventListener('click', function () {
        Notification.requestPermission().then(function (permission) {
          if (permission !== 'granted') { show('off', 'Autorisation refusée. Vous pourrez l\'activer plus tard.'); return; }
          return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(key) })
            .then(function (sub) { return post(box.getAttribute('data-subscribe'), sub.toJSON()); })
            .then(function () { show('on', 'Notifications activées sur cet appareil.'); return post(box.getAttribute('data-test')).then(report); });
        }).catch(function (e) { show('off', 'L\'activation a échoué : ' + (e && e.message ? e.message : 'erreur inconnue') + '. Réessayez.'); });
      });

      disable.addEventListener('click', function () {
        registration.pushManager.getSubscription().then(function (sub) {
          if (!sub) { return; }
          return post(box.getAttribute('data-unsubscribe'), { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
        }).then(function () { show('off', 'Notifications désactivées sur cet appareil.'); });
      });

      test.addEventListener('click', function () {
        post(box.getAttribute('data-test')).then(report, function (e) { status.textContent = e.message; });
      });
    });
  }).catch(function (e) { show('none', 'Impossible d\'activer les notifications : ' + (e && e.message ? e.message : 'navigateur non compatible') + '.'); });
})();
