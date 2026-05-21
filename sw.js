const CACHE_NAME = 'clock-offline-v4';
const STATIC_ASSETS = [
  '/clock/',
  '/clock.php',
  '/clock.html',
  '/clock-manifest.json',
  '/icon-192.png',
  '/icon-512.png',
  '/api_sales.php'
];

self.addEventListener('install', event => {
  console.log('Service Worker installing.');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS);
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(key => key.startsWith('clock-offline-') && key !== CACHE_NAME).map(key => caches.delete(key))
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  if (url.pathname === '/api_sales.php') {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => {
        return fetch(event.request).then(response => {
            if (response.ok) {
              cache.put('/api_sales.php', response.clone());
            }
            return response;
          }).catch(() => cache.match('/api_sales.php').then(cached => cached || new Response('{"total":"0.00","count":0,"clients":0}', {
            headers: { 'Content-Type': 'application/json' }
          })));
      })
    );
    return;
  }
  
  if (url.hostname === 'api.open-meteo.com' || url.hostname === 'wttr.in') {
    event.respondWith(
      fetch(event.request).then(r => r).catch(() => new Response('{}', { headers: { 'Content-Type': 'application/json' } }))
    );
    return;
  }

  if (url.pathname === '/simple_weather.php') {
    event.respondWith(
      fetch(event.request).then(r => r).catch(() => new Response('{}', { headers: { 'Content-Type': 'application/json' } }))
    );
    return;
  }

  event.respondWith(
    fetch(event.request).then(fetchResponse => {
        if (fetchResponse.ok && (url.pathname.endsWith('.php') || url.pathname === '/' || url.pathname === '/clock/' || url.pathname.endsWith('.css') || url.pathname.endsWith('.js'))) {
          const clone = fetchResponse.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return fetchResponse;
    }).catch(() => caches.match(event.request) || caches.match(event.request, { ignoreSearch: true }) || caches.match('/clock/') || caches.match('/clock.php'))
  );
});
