/* OpenRanch — service worker
 *
 * Strategy, deliberately conservative because this dashboard drives real
 * ranch and irrigation hardware:
 *
 *   static shell (icons, manifest, offline page)  -> cache-first
 *   navigations (/, index.php, login.php)         -> network-first, offline.html on failure
 *   ?data=1 / ?history=1 device readings          -> network ONLY, never cached
 *   ingest / poll / cmd / register endpoints      -> never touched by the SW
 *
 * Device readings are deliberately NOT given a cache fallback. Showing a
 * cached "pump: ON" while the phone is offline would be worse than showing
 * nothing, so a failed data fetch surfaces as an offline state instead.
 */

const VERSION    = 'openranch-v2';
const SHELL      = `${VERSION}-shell`;
const OFFLINE_URL = '/offline.html';

const SHELL_ASSETS = [
  OFFLINE_URL,
  '/manifest.json',
  '/icons/icon-96.png',
  '/icons/icon-144.png',
  '/icons/icon-192.png',
  '/icons/icon-256.png',
  '/icons/icon-384.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable-192.png',
  '/icons/icon-maskable-512.png',
  '/icons/apple-touch-icon.png',
  '/icons/favicon-32.png',
];

// Endpoints the boards and the command buttons use. The SW must stay out of
// their way entirely — no caching, no rewriting, no interception.
const PASSTHROUGH = ['/ingest.php', '/poll.php', '/cmd.php', '/register.php',
                     '/irrigation_run.php'];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(SHELL)
      .then(c => c.addAll(SHELL_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== SHELL).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Only ever handle GET. POSTs (cmd.php, login.php, subscribe) go straight out.
  if (req.method !== 'GET') return;

  // Board-facing endpoints: hands off.
  if (url.origin === self.location.origin &&
      PASSTHROUGH.some(p => url.pathname === p)) return;

  // Live device data: network only, never served stale.
  if (url.origin === self.location.origin &&
      (url.searchParams.has('data') || url.searchParams.has('history'))) {
    event.respondWith(fetch(req));
    return;
  }

  // Navigations: try the network, fall back to the offline page.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL, { cacheName: SHELL }))
    );
    return;
  }

  // Everything else (icons, manifest, fonts, Chart.js): cache-first,
  // filling the cache opportunistically on first use.
  event.respondWith(
    caches.match(req).then(hit => {
      if (hit) return hit;
      return fetch(req).then(res => {
        // Only store successful same-origin or CORS-readable responses.
        if (res && res.status === 200 && res.type !== 'opaque') {
          const copy = res.clone();
          caches.open(SHELL).then(c => c.put(req, copy));
        }
        return res;
      }).catch(() => caches.match(OFFLINE_URL, { cacheName: SHELL }));
    })
  );
});

/* ---------------- web push ---------------- */

self.addEventListener('push', event => {
  let payload = {};
  try { payload = event.data ? event.data.json() : {}; } catch (e) { payload = {}; }

  const title = payload.title || 'OpenRanch';
  const body  = payload.body  || 'A device needs your attention.';

  event.waitUntil(self.registration.showNotification(title, {
    body,
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    tag: payload.tag || 'ww-alert',
    renotify: true,
    data: { url: payload.url || '/' },
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) {
        if (c.url.includes(self.location.origin) && 'focus' in c) return c.focus();
      }
      return self.clients.openWindow(target);
    })
  );
});
