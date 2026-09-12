const CACHE_NAME = 'marinero-products-v8';
const BASE_URL = new URL('./', self.location.href);
const assetUrl = (rel) => new URL(rel, BASE_URL).toString();

async function responseForCache(response) {
  const headers = new Headers(response.headers);
  headers.delete('content-encoding');
  headers.delete('content-length');
  headers.delete('transfer-encoding');

  return new Response(await response.clone().arrayBuffer(), {
    status: response.status,
    statusText: response.statusText,
    headers
  });
}
const ASSETS = [
  assetUrl('products/'),
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
        ASSETS.map(async (asset) => {
          const request = new Request(asset, { cache: 'reload', credentials: 'same-origin' });
          const response = await fetch(request, { cache: 'no-store', credentials: 'same-origin' });
          if (response && response.ok && response.type !== 'opaqueredirect') {
            await cache.put(request, await responseForCache(response));
          }
        })
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
  if (e.request.method !== 'GET') {
    return;
  }

  // Solo interceptar peticiones relacionadas con productos
  const url = e.request.url;
  const reqUrl = new URL(url);
  const isProductsShell = reqUrl.search === '' &&
    reqUrl.pathname === assetUrl('products/').replace(reqUrl.origin, '');
  const isAsset = url.includes('assets/') ||
    url.endsWith('.js') ||
    url.endsWith('.css') ||
    url.endsWith('.jpg') ||
    url.endsWith('.jpeg') ||
    url.endsWith('.png') ||
    url.endsWith('.webp') ||
    url.endsWith('.svg') ||
    url.endsWith('.woff2');

  if (isProductsShell || isAsset) {
    e.respondWith(
      // Los recursos estáticos respetan su caché HTTP; el inventario sigue consultando la red.
      fetch(e.request, { cache: isProductsShell ? 'no-store' : 'default' }).then((response) => {
        if (response && response.ok) {
          try {
            caches.open(CACHE_NAME).then((cache) => {
              responseForCache(response).then((responseCopy) => {
                cache.put(e.request, responseCopy).catch(() => {});
              }).catch(() => {});
            }).catch(() => {});
          } catch (err) {
            console.warn('[SW-PRODUCTS] No se pudo clonar respuesta para caché:', err);
          }
        }
        return response;
      }).catch(() => {
        return caches.match(e.request)
          .then((cached) => cached || caches.match(e.request, { ignoreSearch: true }))
          .then((cached) => cached ? responseForCache(cached) : new Response('Offline - Recurso no disponible', {
            status: 503,
            statusText: 'Offline'
          }));
      })
    );
  }
});
