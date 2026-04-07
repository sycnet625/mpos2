const CACHE_NAME = 'clock-offline-v2';
const STATIC_ASSETS = [
  '/',
  '/clock.php',
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
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  if (url.pathname === '/api_sales.php') {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => {
        return cache.match('/api_sales.php').then(cached => {
          if (cached) return cached;
          return fetch(event.request).then(response => {
            if (response.ok) {
              cache.put('/api_sales.php', response.clone());
            }
            return response;
          }).catch(() => new Response('{"total":"0.00","count":0,"clients":0}', {
            headers: { 'Content-Type': 'application/json' }
          }));
        });
      })
    );
    return;
  }
  
  if (url.hostname === 'api.open-meteo.com' || url.hostname === 'wttr.in') {
    event.respondWith(
      fetch(event.request).catch(() => new Response('{}', { headers: { 'Content-Type': 'application/json' } }))
    );
    return;
  }

  event.respondWith(
    caches.match(event.request).then(response => {
      return response || fetch(event.request).then(fetchResponse => {
        if (fetchResponse.ok && url.pathname.endsWith('.php') || url.pathname === '/') {
          const clone = fetchResponse.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return fetchResponse;
      });
    }).catch(() => caches.match('/clock.php'))
  );
});