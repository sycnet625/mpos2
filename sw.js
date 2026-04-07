self.addEventListener('install', event => {
  console.log('Service Worker installing.');
  event.waitUntil(
    caches.open('clock-cache-v1').then(cache => {
      return cache.addAll([
        '/',
        '/clock.php',
        '/assets/fonts/dseg7-classic-400.woff2',
        '/assets/fonts/dseg14-classic-400.woff2',
        '/assets/fonts/dseg7-modern-400.woff2',
        '/assets/fonts/dseg14-classic-mini-400.woff2',
        '/assets/fonts/dseg7-modern-mini-400.woff2'
      ]);
    })
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request).then(response => {
      return response || fetch(event.request);
    })
  );
});