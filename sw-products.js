const CACHE_NAME = 'marinero-products-v5';
const BASE_URL = new URL('./', self.location.href);
const assetUrl = (rel) => new URL(rel, BASE_URL).toString();
const ASSETS = [
  assetUrl('products/'),
  assetUrl('products_table.php'),
  assetUrl('manifest-products.php'),
  assetUrl('icon-products-192.png'),
  assetUrl('icon-products-512.png'),
  assetUrl('assets/css/bootstrap.min.css'),
  assetUrl('assets/css/all.min.css'),
  assetUrl('assets/css/inventory-suite.css'),
  assetUrl('assets/js/bootstrap.bundle.min.js')
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
