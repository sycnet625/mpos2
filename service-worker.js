// ==========================================
// 🔧 SERVICE WORKER - ONLINE FIRST
// Versión 8.5 - Cache individual + ping offline fix
// ==========================================

const CACHE_NAME = 'palweb-pos-v85';
const APP_BASE_URL = new URL('./', self.location.href);
const appUrl = (rel) => new URL(rel, APP_BASE_URL).toString();

// Recursos estáticos mínimos para offline
const OFFLINE_ASSETS = [
    './pos/',
    './pos.php',
    './clock.php',
    './clock.html',
    './clock.css',
    './clock.js',
    './simple_weather.php',
    './pos1.js',                               // CORREGIDO: era pos.js (no existe)
    './pos-offline-system.js',
    './manifest-pos.php',
    './icon-192.png',
    './icon-512.png',
    './assets/css/bootstrap.min.css',          // LOCAL: era CDN
    './assets/css/all.min.css',                // LOCAL: era CDN
    './assets/js/bootstrap.bundle.min.js',     // LOCAL: era CDN
    // WebFonts de FontAwesome (referenciados por all.min.css)
    './assets/webfonts/fa-solid-900.woff2',
    './assets/webfonts/fa-regular-400.woff2',
    './assets/webfonts/fa-brands-400.woff2',
    './assets/webfonts/fa-v4compatibility.woff2',
];

// ==========================================
// INSTALACIÓN
// ==========================================
self.addEventListener('install', (event) => {
    console.log('[SW-POS] Instalando Service Worker v8.5...');

    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            // Cachear cada asset individualmente: un 404 aislado no arruina toda la instalación
            const results = await Promise.allSettled(
                OFFLINE_ASSETS.map(url =>
                    cache.add(url).catch(err => {
                        console.warn('[SW-POS] No se pudo cachear:', url, err.message);
                    })
                )
            );
            const ok  = results.filter(r => r.status === 'fulfilled').length;
            const bad = results.filter(r => r.status === 'rejected').length;
            console.log(`[SW-POS] Cache: ${ok} OK, ${bad} fallidos de ${OFFLINE_ASSETS.length}`);
            return self.skipWaiting();
        }).catch(err => {
            console.error('[SW-POS] Error abriendo caché:', err);
            return self.skipWaiting(); // continuar igual para no bloquear
        })
    );
});

// ==========================================
// ACTIVACIÓN - Limpiar cachés viejas
// ==========================================
self.addEventListener('activate', (event) => {
    console.log('[SW-POS] Activando Service Worker v8.5...');
    
    event.waitUntil(
        caches.keys()
            .then((keyList) => {
                return Promise.all(
                    keyList.map((key) => {
                        // Solo eliminar cachés propias (palweb-pos-*), nunca la caché de la tienda
                        if (key.startsWith('palweb-pos-') && key !== CACHE_NAME) {
                            console.log('[SW] Eliminando caché vieja:', key);
                            return caches.delete(key);
                        }
                    })
                );
            })
            .then(() => {
                console.log('[SW-POS] Activación completada - ONLINE FIRST activo');
                return self.clients.claim();
            })
    );
});

// ==========================================
// INTERCEPCIÓN DE FETCH - ONLINE FIRST
// ==========================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // Ignorar requests que no sean HTTP/HTTPS
    if (!event.request.url.startsWith('http')) {
        return;
    }
    
    // POST requests - siempre al servidor directo
    if (event.request.method !== 'GET') {
        return;
    }
    
    const swUrl = new URL(event.request.url);
    
    // simple_weather.php: siempre a la red, nunca cachear
    if (swUrl.pathname.includes('simple_weather.php')) {
        event.respondWith(
            fetch(event.request, { credentials: 'same-origin' })
                .then(r => r)
                .catch(() => new Response('{}', { headers: { 'Content-Type': 'application/json' } }))
        );
        return;
    }

    // ?ping=1 — NUNCA servir desde caché: debe indicar estado real de red
    // Si está offline → JSON con offline:true para que el monitor detecte la caída
    if (swUrl.search.includes('ping')) {
        event.respondWith(
            fetch(event.request, { credentials: 'same-origin', cache: 'no-store' })
                .catch(() => new Response(
                    JSON.stringify({ pong: false, offline: true }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } }
                ))
        );
        return;
    }

    // load_cashiers — nunca cachear: contiene PINs sensibles
    if (swUrl.search.includes('load_cashiers')) {
        event.respondWith(
            fetch(event.request, { credentials: 'same-origin', cache: 'no-store' })
                .catch(() => new Response(
                    JSON.stringify({ status: 'offline', offline: true }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } }
                ))
        );
        return;
    }
    
    // ESTRATEGIA: ONLINE FIRST para TODO
    // 1. Intentar servidor primero
    // 2. Si falla (offline), usar caché
    event.respondWith(onlineFirst(event.request));
});

// ==========================================
// ESTRATEGIA ONLINE FIRST
// ==========================================
async function onlineFirst(request) {
    const url = new URL(request.url);
    const LOCAL_NO_IMAGE = appUrl('assets/img/no-image-50.png');
    const isPosStart = /\/pos\/?$/.test(url.pathname);

    // Sustituye placeholders externos por imagen local para evitar ruido offline.
    if (url.hostname === 'via.placeholder.com') {
        const localReq = new Request(LOCAL_NO_IMAGE, { credentials: 'same-origin' });
        const localCached = await caches.match(localReq) || await caches.match(LOCAL_NO_IMAGE);
        if (localCached) return localCached;
        try {
            const localResp = await fetch(localReq);
            if (localResp && localResp.ok) {
                const cache = await caches.open(CACHE_NAME);
                cache.put(localReq, localResp.clone());
                return localResp;
            }
        } catch (e) {}
        return new Response('', { status: 204 });
    }
    
    try {
        // Siempre intentar el servidor primero
        const networkResponse = await fetch(request, {
            credentials: 'same-origin'
        });
        
        // Si es exitoso, actualizar caché para uso offline
        if (networkResponse.ok && networkResponse.type !== 'opaqueredirect' && !networkResponse.redirected) {
            const cache = await caches.open(CACHE_NAME);
            
            // Solo cachear recursos estáticos (no PHP dinámico con parámetros)
            const shouldCache = 
                isPosStart ||
                request.url.endsWith('.js') ||
                request.url.endsWith('.css') ||
                request.url.endsWith('.jpg') ||
                request.url.endsWith('.png') ||
                request.url.endsWith('.woff2') ||
                request.url.includes('bootstrap') ||
                request.url.includes('fontawesome') ||
                (request.url.includes('.php') && !url.search && !request.url.includes('simple_weather')); // PHP sin parámetros
            
            if (shouldCache) {
                cache.put(request, networkResponse.clone());
            }
        }
        
        return networkResponse;
        
    } catch (error) {
        // OFFLINE: Servidor no disponible, usar caché
        console.log('[SW-POS] Offline - buscando en caché:', request.url);

        // ignoreSearch permite que pos.php?ping=1 resuelva desde la caché de pos.php
        const cachedResponse = await caches.match(request) ||
                               await caches.match(request, { ignoreSearch: true }) ||
                               (isPosStart ? await caches.match(appUrl('pos/')) : null) ||
                               (isPosStart ? await caches.match(appUrl('pos.php')) : null);
        
        if (cachedResponse && cachedResponse.type !== 'opaqueredirect') {
            console.log('[SW-POS] Servido desde caché:', request.url);
            return cachedResponse;
        }
        
        // Si es un PHP con parámetros (API), devolver respuesta offline
        if (request.url.includes('.php')) {
            return new Response(
                JSON.stringify({ 
                    status: 'offline', 
                    message: 'Sin conexión al servidor',
                    offline: true 
                }),
                { 
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                }
            );
        }
        
        // Para otros recursos no encontrados
        return new Response('Offline - Recurso no disponible', { 
            status: 503,
            statusText: 'Offline' 
        });
    }
}

// ==========================================
// MENSAJES DESDE LA APP
// ==========================================
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        console.log('[SW] Limpiando todas las cachés...');
        caches.keys().then((keyList) => {
            return Promise.all(keyList.map((key) => caches.delete(key)));
        }).then(() => {
            console.log('[SW] ✅ Cachés limpiadas');
        });
    }
    
    if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({ version: 'v83-online-first' });
    }
});

console.log('[SW-POS] Service Worker v8.5 (ONLINE FIRST + offline real) cargado');

// ══════════════════════════════════════════════════════════════════════════
// PUSH NOTIFICATIONS
// El servidor envía un ping vacío (VAPID). El SW lee el tipo guardado en
// Cache API, obtiene la notificación pendiente de push_api.php y la muestra.
// ══════════════════════════════════════════════════════════════════════════

const PUSH_CACHE = 'push-config-v1';
const BASE        = APP_BASE_URL.toString();
const POS_START_URL = appUrl('pos/');

// Leer tipo desde Cache API (guardado por el frontend al suscribirse)
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
    event.waitUntil(
        getPushTipo().then(tipo =>
            fetch(appUrl('push_api.php') + '?action=latest&tipo=' + encodeURIComponent(tipo), {
                credentials: 'same-origin',
                cache: 'no-store',
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data || !data.titulo) return;
                
                const options = {
                    body:    data.cuerpo || '',
                    icon:    appUrl('icon-192.png'),
                    badge:   appUrl('icon-192.png'),
                    data:    { 
                        url: data.url || POS_START_URL,
                        chat_id: data.chat_id || null, // Guardar chat_id para acciones
                        agente: data.agente || 'claude'
                    },
                    tag:     'palweb-' + (data.id || Date.now()),
                    renotify: true,
                    vibrate: [200, 100, 200],
                    actions: data.acciones || [] // Soporte para botones
                };

                return self.registration.showNotification(data.titulo, options);
            })
            .catch(err => console.error('[SW Push] fetch error:', err))
        )
    );
});

self.addEventListener('notificationclick', event => {
    const notification = event.notification;
    const action = event.action; // 'approve', 'deny' o null (clic normal)
    const data = notification.data || {};

    notification.close();

    // Comportamiento normal: abrir/enfocar app
    const target = data.url || POS_START_URL;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const client of list) {
                if (client.url === target && 'focus' in client) return client.focus();
            }
            return clients.openWindow(target);
        })
    );
});
