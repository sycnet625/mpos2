// ==========================================
// 🔧 SERVICE WORKER - ONLINE FIRST
// Versión 8.1 - Assets locales + offline real
// ==========================================

const CACHE_NAME = 'palweb-pos-v81';

// Recursos estáticos mínimos para offline
const OFFLINE_ASSETS = [
    './pos.php',
    './clock.php',
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
    console.log('[SW-POS] Instalando Service Worker v8.1 (Online First)...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW-POS] Cacheando recursos para offline...');
                return cache.addAll(OFFLINE_ASSETS).catch(() => {});  // no falla si un asset falta
            })
            .then(() => {
                console.log('[SW-POS] Instalación completada');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW-POS] Error en instalación:', error);
            })
    );
});

// ==========================================
// ACTIVACIÓN - Limpiar cachés viejas
// ==========================================
self.addEventListener('activate', (event) => {
    console.log('[SW-POS] Activando Service Worker v8.1...');
    
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
    const LOCAL_NO_IMAGE = (self.registration && self.registration.scope ? self.registration.scope : './') + 'assets/img/no-image-50.png';

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
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            
            // Solo cachear recursos estáticos (no PHP dinámico con parámetros)
            const shouldCache = 
                request.url.endsWith('.js') ||
                request.url.endsWith('.css') ||
                request.url.endsWith('.jpg') ||
                request.url.endsWith('.png') ||
                request.url.endsWith('.woff2') ||
                request.url.includes('bootstrap') ||
                request.url.includes('fontawesome') ||
                (request.url.includes('.php') && !url.search); // PHP sin parámetros
            
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
                               await caches.match(request, { ignoreSearch: true });
        
        if (cachedResponse) {
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
        event.ports[0].postMessage({ version: 'v81-online-first' });
    }
});

console.log('[SW-POS] Service Worker v8.1 (ONLINE FIRST + offline real) cargado');

// ══════════════════════════════════════════════════════════════════════════
// PUSH NOTIFICATIONS
// El servidor envía un ping vacío (VAPID). El SW lee el tipo guardado en
// Cache API, obtiene la notificación pendiente de push_api.php y la muestra.
// ══════════════════════════════════════════════════════════════════════════

const PUSH_CACHE = 'push-config-v1';
const BASE        = self.registration.scope; // ej. https://example.com/marinero/

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
            fetch(BASE + 'push_api.php?action=latest&tipo=' + encodeURIComponent(tipo), {
                credentials: 'same-origin',
                cache: 'no-store',
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data || !data.titulo) return;
                return self.registration.showNotification(data.titulo, {
                    body:    data.cuerpo || '',
                    icon:    BASE + 'icon-192.png',
                    badge:   BASE + 'icon-192.png',
                    data:    { url: data.url || BASE },
                    tag:     'palweb-' + (data.id || Date.now()),
                    renotify: true,
                    vibrate: [200, 100, 200],
                });
            })
            .catch(err => console.error('[SW Push] fetch error:', err))
        )
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data?.url || BASE;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            // Si ya hay una pestaña abierta con esa URL, enfocarla
            for (const client of list) {
                if (client.url === target && 'focus' in client) return client.focus();
            }
            // Si no, abrir nueva pestaña
            return clients.openWindow(target);
        })
    );
});
