const CACHE_NAME = 'rac-affiliate-v7';
const APP_SHELL = [
  '/affiliate_network.php',
  '/affiliate_network_help.php',
  '/affiliate_network_manifest.json',
  '/affiliate_network_icon.svg',
  '/affiliate_network/styles.css',
  '/affiliate_network/js/core.js',
  '/affiliate_network/js/api.js',
  '/affiliate_network/js/render.js',
  '/affiliate_network/app.js',
  '/assets/css/all.min.css',
  '/assets/webfonts/fa-solid-900.woff2',
  '/assets/webfonts/fa-regular-400.woff2',
  '/assets/webfonts/fa-brands-400.woff2'
];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(APP_SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.pathname === '/affiliate_network_api.php' && url.searchParams.get('action') === 'bootstrap') {
    event.respondWith(fetch(req).then(res => { const copy = res.clone(); caches.open(CACHE_NAME).then(cache => cache.put(req, copy)); return res; }).catch(() => caches.match(req)));
    return;
  }
  if (url.origin === location.origin && (url.pathname === '/affiliate_network.php' || url.pathname === '/affiliate_network_help.php' || url.pathname === '/affiliate_network_manifest.json' || url.pathname === '/affiliate_network_icon.svg')) {
    event.respondWith(fetch(req).then(res => { const copy = res.clone(); caches.open(CACHE_NAME).then(cache => cache.put(req, copy)); return res; }).catch(() => caches.match(req)));
    return;
  }
  if (url.origin === location.origin) {
    event.respondWith(caches.match(req).then(hit => hit || fetch(req).then(res => { const copy = res.clone(); caches.open(CACHE_NAME).then(cache => cache.put(req, copy)).catch(() => {}); return res; })));
    return;
  }
});
self.addEventListener('message', event => {
  const data = event.data || {};
  if (data.type === 'rac-show-notification' && self.registration && self.registration.showNotification) {
    event.waitUntil(self.registration.showNotification(data.title || 'RAC', {
      body: data.body || '',
      icon: '/affiliate_network_icon.svg',
      badge: '/affiliate_network_icon.svg'
    }));
  }
});
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) {}
  event.waitUntil(self.registration.showNotification(data.title || 'RAC', {
    body: data.body || 'Nueva notificación RAC',
    icon: '/affiliate_network_icon.svg',
    badge: '/affiliate_network_icon.svg',
    data: { url: data.url || '/affiliate_network.php' }
  }));
});
self.addEventListener('notificationclick', event => {
  const url = (event.notification && event.notification.data && event.notification.data.url) || '/affiliate_network.php';
  event.notification.close();
  event.waitUntil(clients.openWindow(url));
});
