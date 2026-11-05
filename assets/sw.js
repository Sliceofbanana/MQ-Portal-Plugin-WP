// MQ Portal service worker (served from /mq-portal/sw.js with placeholders filled in by PHP).
// Keeps the app shell available offline. API data is cached by the app itself (IndexedDB).

const VERSION = '__VERSION__';
const ASSETS = '__ASSETS__';
const PORTAL = '__PORTAL__';
const CACHE = 'mq-portal-' + VERSION;

const FILES = [
  'portal.css', 'icon-64.png', 'icon-192.png', 'logo-white.webp', 'logo-color.webp', 'login-photo.jpg',
  'js/app.js', 'js/core.js', 'js/offline.js', 'js/notifications.js', 'js/print.js', 'js/jobs.js', 'js/icons.js',
  'js/views/home.js', 'js/views/orders.js', 'js/views/clock.js', 'js/views/account.js', 'js/views/sales.js', 'js/views/creatives.js', 'js/views/production.js', 'js/views/admin.js', 'js/views/pos.js', 'js/views/daily.js',
].map(f => ASSETS + f + '?v=' + encodeURIComponent(VERSION)); // The same addresses the portal page asks for.

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE)
      .then(cache => Promise.all(FILES.map(url => cache.add(new Request(url, { cache: 'reload' })).catch(() => {}))))
      .then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k.startsWith('mq-portal-') && k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Portal page: network first so the session/nonce is fresh; fall back to the last copy offline.
  if (req.mode === 'navigate' && url.href.startsWith(PORTAL)) {
    event.respondWith(
      fetch(req)
        .then(res => {
          if (res.ok) {
            const copy = res.clone(); // Copy now: the page starts reading the response right away.
            caches.open(CACHE).then(c => c.put(PORTAL, copy));
          }
          return res;
        })
        .catch(() => caches.match(PORTAL).then(r => r || new Response('Offline — open the portal once while online first.', { status: 503, headers: { 'Content-Type': 'text/plain' } }))));
    return;
  }

  // Plugin assets: cache first, by the full address including ?v=, so a new plugin version never
  // gets an old file (from this cache, the browser or the host's cache/CDN).
  if (url.href.startsWith(ASSETS)) {
    event.respondWith(
      caches.match(req.url).then(hit => hit || fetch(req).then(res => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(req.url, copy));
        }
        return res;
      })));
  }
});
