// ============================================================
// SERVICE WORKER - TIENDA PUBLICA
// Version 3.0 - Stale-While-Revalidate + Offline Real
// ============================================================

const CACHE_NAME      = 'palweb-shop-v30';
const IMG_CACHE       = 'palweb-shop-images-v2';
const STATIC_CACHE    = 'palweb-shop-static-v30';
const PUSH_CACHE      = 'push-config-v1';
const BASE_URL        = new URL('./', self.registration.scope);
const assetUrl        = (rel) => new URL(rel, BASE_URL).toString();

// Recursos estáticos: cacheados permanentemente, revalidados en background
const STATIC_ASSETS = [
    assetUrl('assets/css/bootstrap.min.css'),
    assetUrl('assets/css/all.min.css'),
    assetUrl('assets/js/bootstrap.bundle.min.js'),
    assetUrl('assets/webfonts/fa-solid-900.woff2'),
    assetUrl('assets/webfonts/fa-regular-400.woff2'),
    assetUrl('assets/webfonts/fa-brands-400.woff2'),
    assetUrl('assets/webfonts/fa-v4compatibility.woff2'),
    assetUrl('icon-shop-192.png'),
    assetUrl('icon-shop-512.png'),
    assetUrl('manifest-shop.php'),
];

// Ruta principal de la tienda (SWR)
const SHOP_MAIN_URL = assetUrl('shop.php');

// Params GET que son siempre AJAX — nunca cachear, nunca servir offline con HTML
const AJAX_PARAMS = [
    'ajax_search', 'action_reviews', 'action_variants', 'action_track',
    'action_client', 'action_restock_aviso', 'action_wishlist',
    'action_new_captcha', 'action_geo', 'action_view_product',
    'action', 'ping',
];

// Params cuyo JSON vacío es respuesta offline válida
const AJAX_OFFLINE_JSON = { status: 'offline', offline: true };

// ── Instalación ──────────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    console.log('[SW-Shop] v3.0 Instalando...');
    event.waitUntil(
        caches.open(STATIC_CACHE).then(async (cache) => {
            // Instalación parcial tolerante: un fallo no bloquea el SW
            await Promise.allSettled(
                STATIC_ASSETS.map(url =>
                    cache.add(url).catch(e => console.warn('[SW-Shop] No cacheado:', url, e.message))
                )
            );
        }).then(() => self.skipWaiting())
    );
});

// ── Activación — limpia cachés viejas ───────────────────────────────────────
self.addEventListener('activate', (event) => {
    console.log('[SW-Shop] v3.0 Activando...');
    const keep = new Set([CACHE_NAME, IMG_CACHE, STATIC_CACHE, PUSH_CACHE]);
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k.startsWith('palweb-shop') && !keep.has(k))
                    .map(k => { console.log('[SW-Shop] Eliminando caché vieja:', k); return caches.delete(k); })
            ))
            .then(() => self.clients.claim())
    );
});

// ── Interceptor de peticiones ────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    if (!event.request.url.startsWith('http')) return;
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    // 1. Imágenes de producto → Cache-First con revalidación silenciosa
    if (url.pathname.endsWith('image.php') || url.pathname.includes('/product_images/')) {
        event.respondWith(cacheFirstRevalidate(event.request, IMG_CACHE));
        return;
    }

    // 2. Assets estáticos (.css .js .woff2 .png .ico .webp .jpg) → Cache-First
    if (isStaticAsset(url)) {
        event.respondWith(cacheFirstRevalidate(event.request, STATIC_CACHE));
        return;
    }

    // 3. Peticiones AJAX / API → Network-Only con fallback JSON offline
    if (isAjaxRequest(url)) {
        event.respondWith(networkOnlyWithOfflineFallback(event.request, url));
        return;
    }

    // 4. shop.php sin parámetros (navegación principal) → Stale-While-Revalidate
    if (url.pathname.endsWith('shop.php') && !url.search) {
        event.respondWith(staleWhileRevalidate(event.request, CACHE_NAME));
        return;
    }

    // 5. Resto de páginas → Network-First con caché de emergencia
    event.respondWith(networkFirstWithCache(event.request));
});

// ── Estrategias de caché ─────────────────────────────────────────────────────

// Stale-While-Revalidate: responde INMEDIATAMENTE con caché (si existe),
// luego actualiza la caché en background para la próxima visita.
async function staleWhileRevalidate(request, cacheName) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(request);

    // Lanzar revalidación en background (sin await)
    const networkPromise = fetch(request, { credentials: 'same-origin' })
        .then(response => {
            if (response.ok) cache.put(request, response.clone());
            return response;
        })
        .catch(() => null);

    // Si hay caché → responder YA, actualizar en fondo
    if (cached) {
        networkPromise.catch(() => {}); // asegurar que no lanza si nadie lo escucha
        return cached;
    }

    // Sin caché → esperar red
    const networkResponse = await networkPromise;
    if (networkResponse) return networkResponse;

    // Offline y sin caché → página de emergencia
    return offlineFallbackPage();
}

// Cache-First con revalidación silenciosa en background (imágenes y estáticos)
async function cacheFirstRevalidate(request, cacheName) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        // Revalidar en background sin bloquear
        fetch(request).then(r => { if (r && r.ok) cache.put(request, r.clone()); }).catch(() => {});
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) cache.put(request, response.clone());
        return response;
    } catch {
        // Para imágenes rotas, respuesta vacía sin error visible
        return new Response('', { status: 204, headers: { 'Content-Type': 'image/svg+xml' } });
    }
}

// Network-Only con fallback JSON para AJAX offline
async function networkOnlyWithOfflineFallback(request, url) {
    try {
        // Timeout de 12s para requests AJAX en conexión lenta
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 12000);
        const response   = await fetch(request, { signal: controller.signal, credentials: 'same-origin' });
        clearTimeout(timeoutId);
        return response;
    } catch {
        return new Response(
            JSON.stringify(AJAX_OFFLINE_JSON),
            { status: 200, headers: { 'Content-Type': 'application/json; charset=utf-8' } }
        );
    }
}

// Network-First con caché de emergencia
async function networkFirstWithCache(request) {
    const cache = await caches.open(CACHE_NAME);
    try {
        const response = await fetch(request, { credentials: 'same-origin' });
        if (response.ok) cache.put(request, response.clone());
        return response;
    } catch {
        const cached = await cache.match(request);
        return cached || offlineFallbackPage();
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function isStaticAsset(url) {
    return /\.(css|js|woff2?|ttf|eot|png|ico|svg|webp|jpg|jpeg|gif)(\?.*)?$/.test(url.pathname);
}

function isAjaxRequest(url) {
    return AJAX_PARAMS.some(p => url.searchParams.has(p));
}

function offlineFallbackPage() {
    return new Response(`<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sin conexión</title>
<style>
  body{font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f9fafb;color:#374151;text-align:center;padding:24px}
  .icon{font-size:4rem;margin-bottom:16px}
  h1{font-size:1.4rem;margin:0 0 8px}
  p{color:#6b7280;margin:0 0 24px;max-width:320px}
  button{background:#0d6efd;color:#fff;border:none;padding:12px 28px;border-radius:999px;font-size:1rem;font-weight:600;cursor:pointer}
</style></head>
<body>
  <div class="icon">📶</div>
  <h1>Sin conexión a internet</h1>
  <p>La tienda no está disponible en este momento. Verifica tu conexión y vuelve a intentarlo.</p>
  <button onclick="location.reload()">Reintentar</button>
</body></html>`,
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
}

// ── Push Notifications ───────────────────────────────────────────────────────

async function getPushTipo() {
    try {
        const cache = await caches.open(PUSH_CACHE);
        const resp  = await cache.match('push-tipo');
        return resp ? await resp.text() : 'operador';
    } catch { return 'operador'; }
}

self.addEventListener('push', (event) => {
    event.waitUntil(
        getPushTipo().then(tipo =>
            fetch(assetUrl('push_api.php') + '?action=latest&tipo=' + encodeURIComponent(tipo), {
                credentials: 'same-origin', cache: 'no-store',
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data?.titulo) return;
                return self.registration.showNotification(data.titulo, {
                    body:      data.cuerpo || '',
                    icon:      assetUrl('icon-shop-192.png'),
                    badge:     assetUrl('icon-shop-192.png'),
                    data:      { url: data.url || assetUrl('shop.php') },
                    tag:       'palweb-shop-' + (data.id || Date.now()),
                    renotify:  true,
                    vibrate:   [200, 100, 200],
                });
            })
            .catch(e => console.error('[SW-Shop Push]', e))
        )
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data?.url || assetUrl('shop.php');
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const c of list) {
                if (c.url === target && 'focus' in c) return c.focus();
            }
            return clients.openWindow(target);
        })
    );
});

// ── Background Sync — checkout fallido ──────────────────────────────────────
self.addEventListener('sync', (event) => {
    if (event.tag === 'checkout-retry') {
        event.waitUntil(retrySyncedCheckouts());
    }
});

async function retrySyncedCheckouts() {
    let db;
    try {
        db = await openCheckoutDB();
        const store  = db.transaction('pending_checkouts', 'readwrite').objectStore('pending_checkouts');
        const items  = await idbGetAll(store);
        for (const item of items) {
            try {
                const res = await fetch(assetUrl('shop.php'), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(item.payload),
                    credentials: 'same-origin',
                });
                if (res.ok) {
                    const del = db.transaction('pending_checkouts', 'readwrite').objectStore('pending_checkouts');
                    del.delete(item.id);
                }
            } catch { /* dejar para el próximo sync */ }
        }
    } catch (e) { console.warn('[SW-Shop Sync]', e); }
    finally     { if (db) db.close(); }
}

function openCheckoutDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open('palweb-shop-checkout', 1);
        req.onupgradeneeded = e => e.target.result.createObjectStore('pending_checkouts', { keyPath: 'id', autoIncrement: true });
        req.onsuccess = e => resolve(e.target.result);
        req.onerror   = e => reject(e.target.error);
    });
}

function idbGetAll(store) {
    return new Promise((resolve, reject) => {
        const req = store.getAll();
        req.onsuccess = e => resolve(e.target.result);
        req.onerror   = e => reject(e.target.error);
    });
}

console.log('[SW-Shop] v3.0 Stale-While-Revalidate activo');
