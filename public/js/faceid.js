// Face ID / empreinte (WebAuthn) : ouverture de l'app et autorisation d'un appareil.
(function () {
  'use strict';

  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function toBuffer(value) {
    var base64 = String(value).replace(/-/g, '+').replace(/_/g, '/');
    base64 += '='.repeat((4 - base64.length % 4) % 4);
    var raw = atob(base64);
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }
    return bytes.buffer;
  }

  function toBase64Url(buffer) {
    var bytes = new Uint8Array(buffer);
    var raw = '';
    for (var i = 0; i < bytes.length; i++) { raw += String.fromCharCode(bytes[i]); }
    return btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify(data || {})
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (!response.ok) { throw new Error(body.message || (response.status === 419 ? 'Page expirée : rechargez la page.' : 'Erreur ' + response.status)); }
        return body;
      });
    });
  }

  function cancelled(error) {
    return error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
  }

  // Ouverture de l'app.
  var unlock = document.querySelector('[data-faceid-unlock]');
  if (unlock) {
    var start = unlock.querySelector('[data-faceid-start]');
    var status = unlock.querySelector('[data-faceid-status]');
    if (!window.PublicKeyCredential) {
      start.disabled = true;
      status.textContent = 'Ce navigateur ne gère pas Face ID : ouvrez avec le code ci-dessous.';
    }
    start.addEventListener('click', function () {
      start.disabled = true;
      status.textContent = 'Regardez votre téléphone…';
      post(unlock.getAttribute('data-options'))
        .then(function (options) {
          var key = options.publicKey;
          key.challenge = toBuffer(key.challenge);
          (key.allowCredentials || []).forEach(function (c) { c.id = toBuffer(c.id); });
          return navigator.credentials.get({ publicKey: key });
        })
        .then(function (credential) {
          return post(unlock.getAttribute('data-verify'), {
            id: toBase64Url(credential.rawId),
            clientDataJSON: toBase64Url(credential.response.clientDataJSON),
            authenticatorData: toBase64Url(credential.response.authenticatorData),
            signature: toBase64Url(credential.response.signature)
          });
        })
        .then(function (result) { status.textContent = 'Ouverture…'; window.location.replace(result.redirect); })
        .catch(function (error) {
          start.disabled = false;
          status.textContent = cancelled(error) ? 'Annulé. Touchez le bouton pour réessayer.' : (error.message || 'Face ID n\'a pas fonctionné.');
        });
    });
  }

  // Réglages : autoriser cet appareil.
  var register = document.querySelector('[data-faceid-register]');
  if (register) {
    var button = register.querySelector('[data-faceid-add]');
    var message = register.querySelector('[data-faceid-message]');
    var supported = window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable;
    (supported ? PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable() : Promise.resolve(false)).then(function (available) {
      if (!available) {
        button.disabled = true;
        message.textContent = 'Cet appareil ou ce navigateur ne propose pas Face ID / empreinte. Sur iPhone : ouvrez l\'app depuis son icône ou dans Safari.';
      }
    });
    button.addEventListener('click', function () {
      button.disabled = true;
      message.textContent = 'Regardez votre téléphone…';
      post(register.getAttribute('data-options'))
        .then(function (options) {
          var key = options.publicKey;
          key.challenge = toBuffer(key.challenge);
          key.user.id = toBuffer(key.user.id);
          (key.excludeCredentials || []).forEach(function (c) { c.id = toBuffer(c.id); });
          return navigator.credentials.create({ publicKey: key });
        })
        .then(function (credential) {
          return post(register.getAttribute('data-store'), {
            clientDataJSON: toBase64Url(credential.response.clientDataJSON),
            attestationObject: toBase64Url(credential.response.attestationObject)
          });
        })
        .then(function () { window.location.reload(); })
        .catch(function (error) {
          button.disabled = false;
          message.textContent = error && error.name === 'InvalidStateError'
            ? 'Face ID est déjà activé sur cet appareil.'
            : (cancelled(error) ? 'Annulé.' : (error.message || 'L\'activation n\'a pas fonctionné.'));
        });
    });
  }
})();
