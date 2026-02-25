// ==========================================
// SERVICE WORKER - TIENDA PÚBLICA
// Versión 2.0 - Online First + Offline Real
// Scope: /marinero/shop.php (solo tienda)
// ==========================================

const CACHE_NAME = 'palweb-shop-v2';
const IMG_CACHE  = 'palweb-shop-images-v1';
const PUSH_CACHE = 'push-config-v1';
const BASE       = self.registration.scope; // https://example.com/marinero/shop.php

// Recursos estáticos mínimos para offline
const OFFLINE_ASSETS = [
    '/marinero/shop.php',
    '/marinero/manifest-shop.php',
    '/marinero/icon-192.png',
    '/marinero/icon-512.png',
    '/marinero/assets/css/bootstrap.min.css',
    '/marinero/assets/css/all.min.css',
    '/marinero/assets/js/bootstrap.bundle.min.js',
    '/marinero/assets/webfonts/fa-solid-900.woff2',
    '/marinero/assets/webfonts/fa-regular-400.woff2',
    '/marinero/assets/webfonts/fa-brands-400.woff2',
    '/marinero/assets/webfonts/fa-v4compatibility.woff2',
];

// Parámetros GET que identifican peticiones AJAX (deben recibir JSON offline)
const AJAX_PARAMS = [
    'ajax_search', 'action_reviews', 'action_variants', 'action_track',
    'action_client', 'action_restock_aviso', 'action_wishlist', 'action', 'ping',
];

// ==========================================
// INSTALACIÓN
// ==========================================
self.addEventListener('install', (event) => {
    console.log('[SW-Shop] v2 Instalando...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(OFFLINE_ASSETS).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

// ==========================================
// ACTIVACIÓN — limpia cachés palweb-shop-* e imágenes viejas
// ==========================================
self.addEventListener('activate', (event) => {
    console.log('[SW-Shop] v2 Activando...');
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.map(key => {
                    // Solo eliminar cachés propias, nunca las del POS
                    if (
                        (key.startsWith('palweb-shop-') || key.startsWith('palweb-shop-images-')) &&
                        key !== CACHE_NAME &&
                        key !== IMG_CACHE
                    ) {
                        console.log('[SW-Shop] v2 Eliminando caché vieja:', key);
                        return caches.delete(key);
                    }
                })
            ))
            .then(() => self.clients.claim())
    );
});

// ==========================================
// FETCH — separar imágenes del resto
// ==========================================
self.addEventListener('fetch', (event) => {
    if (!event.request.url.startsWith('http')) return;
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    // Imágenes de productos → stale-while-revalidate en caché separada
    if (url.pathname.includes('image.php')) {
        event.respondWith(serveImage(event.request));
        return;
    }

    event.respondWith(onlineFirst(event.request));
});

// ==========================================
// serveImage — stale-while-revalidate en IMG_CACHE
// ==========================================
async function serveImage(request) {
    const imgCache = await caches.open(IMG_CACHE);
    const cached   = await imgCache.match(request);

    if (cached) {
        // Servir de caché inmediato; actualizar en background
        fetch(request)
            .then(r => { if (r && r.ok) imgCache.put(request, r.clone()); })
            .catch(() => {});
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) imgCache.put(request, response.clone());
        return response;
    } catch {
        // Sin caché y sin red → respuesta vacía (avatar de fallback se mostrará en CSS)
        return new Response('', { status: 404 });
    }
}

// ==========================================
// onlineFirst — online first con fallback offline inteligente
// ==========================================
async function onlineFirst(request) {
    const url = new URL(request.url);

    try {
        const networkResponse = await fetch(request, { credentials: 'same-origin' });

        if (networkResponse.ok) {
            const shouldCache =
                request.url.endsWith('.js') ||
                request.url.endsWith('.css') ||
                request.url.endsWith('.woff2') ||
                request.url.endsWith('.png') ||
                (url.pathname.includes('shop.php') && !url.search) ||
                url.pathname.includes('manifest-shop.php');

            if (shouldCache) {
                const cache = await caches.open(CACHE_NAME);
                cache.put(request, networkResponse.clone());
            }
        }

        return networkResponse;

    } catch {
        console.log('[SW-Shop] v2 Offline, buscando en caché:', request.url);

        // AJAX → responder con JSON offline en vez de HTML de error
        const isAjax = AJAX_PARAMS.some(p => url.searchParams.has(p));
        if (isAjax) {
            return new Response(
                JSON.stringify({ status: 'offline', offline: true }),
                { status: 200, headers: { 'Content-Type': 'application/json' } }
            );
        }

        // Navegación (categoría, sort, producto, sin params) → shop.php cacheada
        const cached = await caches.match(request)
                    || await caches.match(new URL('/marinero/shop.php', request.url).href);
        if (cached) return cached;

        return new Response('Sin conexión. Abre la tienda conectado primero.', { status: 503 });
    }
}

// ==========================================
// PUSH — igual que el SW del POS
// El tipo guardado en Cache API es 'cliente' o 'operador'
// ==========================================
async function getPushTipo() {
    try {
        const cache = await caches.open(PUSH_CACHE);
        const resp  = await cache.match('push-tipo');
        return resp ? (await resp.text()) : 'operador';
    } catch (e) {
        return 'operador';
    }
}

self.addEventListener('push', event => {
    // BASE del scope termina en shop.php, construir URL base del directorio
    const baseDir = BASE.replace(/shop\.php$/, '');
    event.waitUntil(
        getPushTipo().then(tipo =>
            fetch(baseDir + 'push_api.php?action=latest&tipo=' + encodeURIComponent(tipo), {
                credentials: 'same-origin',
                cache: 'no-store',
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data || !data.titulo) return;
                return self.registration.showNotification(data.titulo, {
                    body:    data.cuerpo || '',
                    icon:    baseDir + 'icon-192.png',
                    badge:   baseDir + 'icon-192.png',
                    data:    { url: data.url || baseDir + 'shop.php' },
                    tag:     'palweb-shop-' + (data.id || Date.now()),
                    renotify: true,
                    vibrate: [200, 100, 200],
                });
            })
            .catch(err => console.error('[SW-Shop Push] Error:', err))
        )
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data?.url || BASE;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const client of list) {
                if (client.url === target && 'focus' in client) return client.focus();
            }
            return clients.openWindow(target);
        })
    );
});

console.log('[SW-Shop] v2 Service Worker Tienda cargado');
