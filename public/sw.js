// Service worker de l'app Argent : notifications (Web Push) et page hors ligne.
// Le téléphone ne garde que la page vide « hors ligne » et les fichiers de l'app (css, js,
// icônes, polices) : jamais une page avec des chiffres. Le résumé lisible hors ligne est
// rangé à part, chiffré avec le code Argent (js/offline.js).
var CACHE = 'argent-offline';
var SHELL = '/hors-ligne';
var ASSET = /^\/(css|js|fonts|icons)\//;
var TIMEOUT_MS = 15000;

// Page hors ligne + tout ce qu'elle charge (et les polices du css), rangés dans le cache.
var cacheShell = function () {
  return fetch(SHELL, { cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
    if (!response.ok) { throw new Error('HTTP ' + response.status); }
    return response.clone().text().then(function (html) {
      var urls = [];
      html.replace(/(?:href|src)="([^"]+)"/g, function (all, url) { urls.push(url); return all; });
      var assets = urls.map(function (url) { return new URL(url.replace(/&amp;/g, '&'), self.location.origin); })
        .filter(function (url) { return url.origin === self.location.origin && ASSET.test(url.pathname); })
        .map(function (url) { return url.pathname + url.search; });
      var css = assets.filter(function (url) { return /\.css(\?|$)/.test(url); });
      return Promise.all(css.map(function (url) {
        return fetch(url).then(function (r) { return r.text(); }).then(function (text) {
          var fonts = [];
          text.replace(/url\(["']?([^"')]+)["']?\)/g, function (all, url) { fonts.push(new URL(url, self.location.origin + '/css/').pathname); return all; });
          return fonts.filter(function (url) { return ASSET.test(url); });
        }).catch(function () { return []; });
      })).then(function (lists) {
        var all = assets.concat.apply(assets, lists).concat(['/icons/icon-192.png', '/manifest.webmanifest']);
        return caches.open(CACHE).then(function (cache) {
          return Promise.all(all.map(function (url) {
            return fetch(url).then(function (r) { if (r.ok) { return cache.put(url, r); } }).catch(function () {});
          })).then(function () { return cache.put(SHELL, response); }).then(function () {
            // Les anciennes versions des fichiers sont retirées.
            var keep = all.concat([SHELL]).map(function (url) { return new URL(url, self.location.origin).href; });
            return cache.keys().then(function (keys) {
              return Promise.all(keys.filter(function (req) { return keep.indexOf(req.url) === -1; }).map(function (req) { return cache.delete(req); }));
            });
          });
        });
      });
    });
  });
};

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(cacheShell().catch(function () { /* sans réseau : au prochain passage */ }));
});
self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});
self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'refresh-offline') {
    event.waitUntil(cacheShell().catch(function () {}));
  }
});

var withTimeout = function (promise, ms) {
  return new Promise(function (resolve, reject) {
    var timer = setTimeout(function () { reject(new Error('timeout')); }, ms);
    promise.then(function (value) { clearTimeout(timer); resolve(value); }, function (error) { clearTimeout(timer); reject(error); });
  });
};

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') { return; }
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) { return; }

  // Pages : toujours le réseau ; sans réseau, la page hors ligne (jamais une page en cache).
  if (request.mode === 'navigate') {
    event.respondWith(withTimeout(fetch(request), TIMEOUT_MS).catch(function () {
      return caches.match(SHELL).then(function (shell) { return shell || Response.error(); });
    }));
    return;
  }
  // Fichiers de l'app : le réseau d'abord, le cache sans réseau.
  if (ASSET.test(url.pathname)) {
    event.respondWith(fetch(request).catch(function () {
      return caches.match(request).then(function (hit) { return hit || caches.match(request, { ignoreSearch: true }); })
        .then(function (hit) { return hit || Response.error(); });
    }));
  }
});

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || 'Argent', {
    body: data.body || '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    data: { url: data.url || '/' },
    tag: data.url || undefined,
    renotify: true
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if ('focus' in list[i]) { list[i].navigate(url); return list[i].focus(); }
    }
    return self.clients.openWindow(url);
  }));
});
