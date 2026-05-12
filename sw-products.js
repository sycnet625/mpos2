const CACHE_NAME = 'marinero-products-v3';
const ASSETS = [
  'products_table.php',
  'manifest-products.php',
  'icon-products-192.png',
  'icon-products-512.png',
  'assets/css/bootstrap.min.css',
  'assets/js/bootstrap.bundle.min.js',
  'db.php',
  'config_loader.php'
];

// Instalación: Cachear recursos críticos
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return Promise.allSettled(
        ASSETS.map((asset) => cache.add(new Request(asset, { cache: 'reload' })))
      );
    })
  );
  self.skipWaiting();
});

// Activación: Limpiar cachés antiguas de ESTE worker
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME && key.startsWith('marinero-products')) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Estrategia: Network First, falling back to cache
// Para Cuba, intentamos red primero (por si hubo cambios en precios),
// pero si falla (corte de internet), entregamos lo que tenemos.
self.addEventListener('fetch', (e) => {
  // Solo interceptar peticiones relacionadas con productos
  const url = e.request.url;
  if (url.includes('products_table.php') || url.includes('assets/')) {
    e.respondWith(
      fetch(e.request, { cache: 'no-store' }).then((response) => {
        if (response && response.ok) {
          caches.open(CACHE_NAME).then((cache) => cache.put(e.request, response.clone()));
        }
        return response;
      }).catch(() => {
        return caches.match(e.request) || caches.match(e.request, { ignoreSearch: true });
      })
    );
  }
});
