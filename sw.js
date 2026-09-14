// Spotcomm HRIS Service Worker - enables app-like experience
const CACHE_NAME = 'spotcomm-hris-v1';
const APP_URL = '/index.php';

self.addEventListener('install', function(e) {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function(e) {
  // Network-first strategy - always try online, fallback to cache
  e.respondWith(
    fetch(e.request).catch(function() {
      return caches.match(e.request);
    })
  );
});
