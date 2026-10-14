/* Nexora Logistics service worker — offline shell for the public site.
   Static assets are cache-first; pages and APIs always try the network first. */
'use strict';

var CACHE = 'nexora-shell-v1';
var SHELL = [
  '/',
  '/track.php',
  '/assets/css/app.css',
  '/assets/js/app.js',
  '/assets/js/nx-labels.js',
  '/assets/js/qrcode.min.js',
  '/assets/vendor/maplibre/maplibre-gl.css',
  '/assets/vendor/maplibre/maplibre-gl.js',
  '/assets/images/icon.svg',
  '/manifest.webmanifest'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return Promise.all(SHELL.map(function (url) {
        return cache.add(url).catch(function () { /* ignore individual misses */ });
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) { if (k !== CACHE) return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Never cache dynamic data or admin traffic.
  if (url.pathname.indexOf('/api/') === 0 ||
      url.pathname.indexOf('/admin/') === 0 ||
      url.pathname.indexOf('track-api.php') !== -1 ||
      url.pathname.indexOf('chat.php') !== -1) {
    return;
  }

  var isStatic = /\.(css|js|png|jpe?g|gif|webp|svg|woff2?)$/i.test(url.pathname) ||
                 url.pathname.indexOf('/assets/') === 0;

  if (isStatic) {
    event.respondWith(
      caches.match(req).then(function (cached) {
        return cached || fetch(req).then(function (res) {
          var copy = res.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copy); });
          return res;
        });
      })
    );
    return;
  }

  event.respondWith(
    fetch(req).catch(function () { return caches.match(req); })
  );
});
