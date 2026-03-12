// ==========================================
// SERVICE WORKER - TIENDA PUBLICA
// Version 2.1 - Online First + Offline Real
// Scope: directorio actual de la tienda
// ==========================================

const CACHE_NAME = 'palweb-shop-v21';
const IMG_CACHE = 'palweb-shop-images-v1';
const PUSH_CACHE = 'push-config-v1';
const BASE_URL = new URL('./', self.registration.scope);
const assetUrl = (rel) => new URL(rel, BASE_URL).toString();

// Recursos estaticos minimos para offline
const OFFLINE_ASSETS = [
    assetUrl('shop.php'),
    assetUrl('manifest-shop.php'),
    assetUrl('icon-shop-192.png'),
    assetUrl('icon-shop-512.png'),
    assetUrl('assets/css/bootstrap.min.css'),
    assetUrl('assets/css/all.min.css'),
    assetUrl('assets/js/bootstrap.bundle.min.js'),
    assetUrl('assets/webfonts/fa-solid-900.woff2'),
    assetUrl('assets/webfonts/fa-regular-400.woff2'),
    assetUrl('assets/webfonts/fa-brands-400.woff2'),
    assetUrl('assets/webfonts/fa-v4compatibility.woff2'),
];

// Parametros GET que identifican peticiones AJAX
const AJAX_PARAMS = [
    'ajax_search', 'action_reviews', 'action_variants', 'action_track',
    'action_client', 'action_restock_aviso', 'action_wishlist', 'action', 'ping',
];

self.addEventListener('install', (event) => {
    console.log('[SW-Shop] v2.1 Instalando...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(OFFLINE_ASSETS).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    console.log('[SW-Shop] v2.1 Activando...');
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.map((key) => {
                    if (
                        (key.startsWith('palweb-shop-') || key.startsWith('palweb-shop-images-')) &&
                        key !== CACHE_NAME &&
                        key !== IMG_CACHE
                    ) {
                        console.log('[SW-Shop] v2.1 Eliminando cache vieja:', key);
                        return caches.delete(key);
                    }
                    return Promise.resolve();
                })
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (!event.request.url.startsWith('http')) return;
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);
    if (url.pathname.includes('image.php')) {
        event.respondWith(serveImage(event.request));
        return;
    }

    event.respondWith(onlineFirst(event.request));
});

async function serveImage(request) {
    const imgCache = await caches.open(IMG_CACHE);
    const cached = await imgCache.match(request);

    if (cached) {
        fetch(request)
            .then((response) => {
                if (response && response.ok) imgCache.put(request, response.clone());
            })
            .catch(() => {});
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) imgCache.put(request, response.clone());
        return response;
    } catch {
        return new Response('', { status: 404 });
    }
}

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
        console.log('[SW-Shop] v2.1 Offline, buscando en cache:', request.url);

        const isAjax = AJAX_PARAMS.some((param) => url.searchParams.has(param));
        if (isAjax) {
            return new Response(
                JSON.stringify({ status: 'offline', offline: true }),
                { status: 200, headers: { 'Content-Type': 'application/json' } }
            );
        }

        const cached = await caches.match(request) || await caches.match(assetUrl('shop.php'));
        if (cached) return cached;

        return new Response('Sin conexion. Abre la tienda conectado primero.', { status: 503 });
    }
}

async function getPushTipo() {
    try {
        const cache = await caches.open(PUSH_CACHE);
        const resp = await cache.match('push-tipo');
        return resp ? await resp.text() : 'operador';
    } catch (e) {
        return 'operador';
    }
}

self.addEventListener('push', (event) => {
    event.waitUntil(
        getPushTipo().then((tipo) =>
            fetch(assetUrl('push_api.php') + '?action=latest&tipo=' + encodeURIComponent(tipo), {
                credentials: 'same-origin',
                cache: 'no-store',
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (!data || !data.titulo) return;
                    return self.registration.showNotification(data.titulo, {
                        body: data.cuerpo || '',
                        icon: assetUrl('icon-shop-192.png'),
                        badge: assetUrl('icon-shop-192.png'),
                        data: { url: data.url || assetUrl('shop.php') },
                        tag: 'palweb-shop-' + (data.id || Date.now()),
                        renotify: true,
                        vibrate: [200, 100, 200],
                    });
                })
                .catch((err) => console.error('[SW-Shop Push] Error:', err))
        )
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data?.url || assetUrl('shop.php');
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if (client.url === target && 'focus' in client) return client.focus();
            }
            return clients.openWindow(target);
        })
    );
});

console.log('[SW-Shop] v2.1 Service Worker Tienda cargado');
