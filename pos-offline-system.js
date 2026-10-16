// ==========================================
// SISTEMA OFFLINE V10 - CORREGIDO
// ==========================================

if (typeof window.posOfflineLoaded === 'undefined') {
    window.posOfflineLoaded = true;

    class POSCache {
        constructor() {
            this.dbName = 'POS_Offline_DB_' + (window.POS_STORAGE_SCOPE || 'e1_s1_a1_c0');
            this.dbVersion = 15;
            this.db = null;
        }
        
        async init() {
            return new Promise((resolve, reject) => {
                const request = indexedDB.open(this.dbName, this.dbVersion);
                request.onerror = () => reject(request.error);
                request.onsuccess = async () => {
                    this.db = request.result;
                    this.db.onversionchange = () => this.db.close();
                    await this.purgeLegacyPins();
                    await this.restoreOfflineVerifier();
                    console.log('IndexedDB Listo v' + this.dbVersion);
                    resolve();
                };
                
                request.onupgradeneeded = (event) => {
                    const db = event.target.result;
                    
                    if (!db.objectStoreNames.contains('products')) {
                        db.createObjectStore('products', { keyPath: 'codigo' });
                    }
                    if (!db.objectStoreNames.contains('cart_state')) {
                        db.createObjectStore('cart_state', { keyPath: 'id' });
                    }
                    // Eliminar PIN antiguos al migrar, conservando ventas pendientes.
                    if (db.objectStoreNames.contains('cajeros')) db.deleteObjectStore('cajeros');
                    db.createObjectStore('cajeros', { keyPath: 'nombre' });
                    if (!db.objectStoreNames.contains('caja_session')) {
                        db.createObjectStore('caja_session', { keyPath: 'id' });
                    }
                    if (!db.objectStoreNames.contains('metadata')) {
                        db.createObjectStore('metadata', { keyPath: 'key' });
                    }

                    if (!db.objectStoreNames.contains('offline_sales')) {
                        const salesStore = db.createObjectStore('offline_sales', { keyPath: 'id', autoIncrement: true });
                        salesStore.createIndex('synced', 'synced', { unique: false });
                    }
                };
            });
        }

        async purgeLegacyPins() {
            // Borrar solo credenciales legacy; nunca tocar ventas de otras cajas.
            if (!indexedDB.databases) return;
            try {
                for (const info of await indexedDB.databases()) {
                    if (!info.name?.startsWith('POS_Offline_DB') || info.name === this.dbName) continue;
                    await new Promise(resolve => {
                        const req = indexedDB.open(info.name);
                        req.onerror = resolve;
                        req.onblocked = resolve;
                        req.onsuccess = () => {
                            const db = req.result;
                            if (!db.objectStoreNames.contains('cajeros')) { db.close(); resolve(); return; }
                            const tx = db.transaction('cajeros', 'readwrite');
                            const store = tx.objectStore('cajeros');
                            if (store.keyPath === 'pin') store.clear();
                            tx.oncomplete = tx.onerror = () => { db.close(); resolve(); };
                        };
                    });
                }
            } catch (e) { console.warn('No se pudieron limpiar credenciales antiguas.', e); }
        }

        async restoreOfflineVerifier() {
            try {
                const record = JSON.parse(localStorage.getItem('palweb_offline_operator_v1') || 'null');
                const context = window.POS_ACTIVE_CONTEXT || {};
                if (!record?.verifier || record.expiresAt < Date.now()
                    || Number(record.id_empresa) !== Number(context.id_empresa)
                    || Number(record.id_sucursal) !== Number(context.id_sucursal)) return;
                const tx = this.db.transaction('cajeros', 'readwrite');
                tx.objectStore('cajeros').put(record);
                await new Promise(resolve => { tx.oncomplete = tx.onerror = resolve; });
            } catch (e) { /* El almacenamiento privado puede estar deshabilitado. */ }
        }

        async hydrateProductsFromLocalStorage() {
            try {
                const raw = localStorage.getItem('products_cache_v2_' + (window.POS_STORAGE_SCOPE || 'e1_s1_a1_c0'));
                if (!raw) return 0;
                const parsed = JSON.parse(raw);
                const products = Array.isArray(parsed.data) ? parsed.data : [];
                if (!products.length) return 0;
                await this.saveProducts(products);
                return products.length;
            } catch (e) {
                console.warn('No se pudo copiar catalogo local a IndexedDB:', e);
                return 0;
            }
        }

        // PRODUCTOS
        async saveProducts(products) {
            if (!this.db) return;
            const tx = this.db.transaction(['products'], 'readwrite');
            const store = tx.objectStore('products');
            store.clear();
            products.forEach(p => store.put(p));
            return new Promise(resolve => tx.oncomplete = () => resolve());
        }
        
        async getProducts() {
            if (!this.db) return [];
            const tx = this.db.transaction(['products'], 'readonly');
            return new Promise(resolve => {
                const req = tx.objectStore('products').getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => resolve([]);
            });
        }

        // CAJEROS
        async pinVerifier(pin, salt) {
            const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits']);
            const bits = await crypto.subtle.deriveBits({name: 'PBKDF2', salt: new Uint8Array(salt), iterations: 310000, hash: 'SHA-256'}, key, 256);
            return Array.from(new Uint8Array(bits), b => b.toString(16).padStart(2, '0')).join('');
        }

        async rememberAuthenticatedCashier(data, pin) {
            if (!this.db || !crypto.subtle) return;
            const salt = Array.from(crypto.getRandomValues(new Uint8Array(16)));
            const verifier = await this.pinVerifier(pin, salt);
            const tx = this.db.transaction(['cajeros'], 'readwrite');
            // Solo recordar al operador que autenticó este terminal.
            const store = tx.objectStore('cajeros');
            store.clear();
            const record = {nombre: data.cajero, rol: data.rol, id_empresa: data.id_empresa,
                id_sucursal: data.id_sucursal, id_almacen: data.id_almacen,
                salt, verifier, expiresAt: Date.now() + 24 * 60 * 60 * 1000};
            store.put(record);
            try { localStorage.setItem('palweb_offline_operator_v1', JSON.stringify(record)); } catch (e) {}
            return new Promise((resolve, reject) => {
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
        }
        
        async getCajeros() {
            if (!this.db) return [];
            try {
                const tx = this.db.transaction(['cajeros'], 'readonly');
                return new Promise(resolve => {
                    const req = tx.objectStore('cajeros').getAll();
                    req.onsuccess = () => resolve(req.result || []);
                    req.onerror = () => resolve([]);
                });
            } catch(e) {
                return [];
            }
        }
        
        async verifyCajeroOffline(pin) {
            if (!this.db) return null;
            try {
                for (const cashier of await this.getCajeros()) {
                    if (!cashier.verifier || !cashier.salt || cashier.expiresAt < Date.now()) continue;
                    if (await this.pinVerifier(pin, cashier.salt) === cashier.verifier) return cashier;
                }
                return null;
            } catch(e) {
                return null;
            }
        }

        // SESION DE CAJA
        async saveCajaSession(session) {
            if (!this.db) return;
            try {
                const tx = this.db.transaction(['caja_session'], 'readwrite');
                tx.objectStore('caja_session').put({ id: 'current', ...session, timestamp: Date.now() });
                return new Promise(resolve => tx.oncomplete = () => resolve());
            } catch(e) {
                console.error('Error guardando sesion:', e);
            }
        }
        
        async getCajaSession() {
            if (!this.db) return null;
            try {
                const tx = this.db.transaction(['caja_session'], 'readonly');
                return new Promise(resolve => {
                    const req = tx.objectStore('caja_session').get('current');
                    req.onsuccess = () => resolve(req.result || null);
                    req.onerror = () => resolve(null);
                });
            } catch(e) {
                return null;
            }
        }

        // CARRITO
        async saveCart(cartData) {
            if (!this.db) return;
            const tx = this.db.transaction(['cart_state'], 'readwrite');
            tx.objectStore('cart_state').put({ id: 'current', ...cartData, timestamp: Date.now() });
        }
        
        async getCart() {
            if (!this.db) return null;
            const tx = this.db.transaction(['cart_state'], 'readonly');
            return new Promise(resolve => {
                const req = tx.objectStore('cart_state').get('current');
                req.onsuccess = () => resolve(req.result || null);
                req.onerror = () => resolve(null);
            });
        }
        
        async clearCart() {
            if (!this.db) return;
            const tx = this.db.transaction(['cart_state'], 'readwrite');
            tx.objectStore('cart_state').delete('current');
        }

        // VENTAS OFFLINE
        async saveOfflineSale(sale) {
            if (!this.db) return null;
            const tx = this.db.transaction(['offline_sales'], 'readwrite');
            const saleData = { ...sale, timestamp: Date.now(), synced: false };
            const req = tx.objectStore('offline_sales').add(saleData);
            return new Promise((resolve, reject) => {
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        }
        
        async getPendingSales() {
            if (!this.db) return [];
            const tx = this.db.transaction(['offline_sales'], 'readonly');
            const store = tx.objectStore('offline_sales');
            
            return new Promise(resolve => {
                const req = store.getAll();
                req.onsuccess = () => {
                    const all = req.result || [];
                    const pending = all.filter(s => s.synced !== true);
                    resolve(pending);
                };
                req.onerror = () => resolve([]);
            });
        }
        
        async markSaleSynced(id) {
            if (!this.db) return;
            const tx = this.db.transaction(['offline_sales'], 'readwrite');
            const store = tx.objectStore('offline_sales');
            store.delete(id);
            return new Promise(resolve => tx.oncomplete = () => resolve());
        }
        
        async getPendingCount() {
            const pending = await this.getPendingSales();
            return pending.length;
        }
    }

    window.posCache = new POSCache();

    // ==========================================
    // MONITOR DE CONEXION
    // ==========================================
    class ConnectionMonitor {
        constructor() {
            this.isOnline = navigator.onLine;
            this.latency = 0;
            this.checkInterval = null;
            this.consecutiveFailures = 0;
            this.failureThreshold = 2;
            this.lastOkAt = 0;
            this.lastError = '';
        }

        getPingUrl() {
            const baseUrl = window.POS_PING_URL || 'pos.php?netcheck=1';
            const url = new URL(baseUrl, window.location.href);
            url.searchParams.set('_', String(Date.now()));
            return url.toString();
        }

        fetchWithTimeout(url, timeoutMs) {
            const headers = { 'Accept': 'application/json' };
            if (typeof AbortController === 'undefined') {
                return fetch(url, { method: 'GET', cache: 'no-store', credentials: 'same-origin', headers });
            }

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
            return fetch(url, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers,
                signal: controller.signal
            }).finally(() => clearTimeout(timeoutId));
        }
        
        async checkConnection() {
            try {
                const start = performance.now();
                const res = await this.fetchWithTimeout(this.getPingUrl(), 8000);
                if (!res.ok) throw new Error('No OK');
                // El SW devuelve {offline:true} cuando no hay red real
                // (evita falso positivo por la caché de pos.php)
                const ct = res.headers.get('Content-Type') || '';
                if (!ct.includes('application/json')) throw new Error('Respuesta no JSON');
                const data = await res.clone().json().catch(() => ({}));
                if (data.offline === true) throw new Error('Offline SW');
                if (data.pong !== true) {
                    throw new Error('Netcheck inválido');
                }
                this.latency = Math.round(performance.now() - start);
                this.isOnline = true;
                this.consecutiveFailures = 0;
                this.lastOkAt = Date.now();
                this.lastError = '';
            } catch (e) {
                this.consecutiveFailures += 1;
                this.lastError = e && e.message ? e.message : 'Error de red';
                if (this.consecutiveFailures >= this.failureThreshold || Date.now() - this.lastOkAt > 60000) {
                    this.isOnline = false;
                    this.latency = 9999;
                }
            }
            this.updateUI();
            return this.isOnline;
        }
        
        updateUI() {
            if (typeof updateOnlineStatus === 'function') {
                updateOnlineStatus();
            }
        }
        
        start() {
            if (this.checkInterval) return;
            this.checkConnection();
            this.checkInterval = setInterval(() => { 
                this.checkConnection(); 
            }, 15000);
            
            window.addEventListener('online', () => {
                this.checkConnection();
            });
            window.addEventListener('offline', () => {
                this.consecutiveFailures = Math.max(this.consecutiveFailures, 1);
                this.checkConnection();
            });
        }
        
        stop() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
            }
        }
    }

    window.connectionMonitor = new ConnectionMonitor();

    // ==========================================
    // INICIO
    // ==========================================
    window.initPOSOffline = async function() {
        if (window.posOfflineInitialized) return;
        window.posOfflineInitialized = true;
        try {
            await window.posCache.init();
            await window.posCache.hydrateProductsFromLocalStorage();
            window.connectionMonitor.start();
            console.log('Sistema offline inicializado');
        } catch(e) {
            console.error('Error iniciando sistema offline:', e);
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(window.initPOSOffline, 300);
    });

} // End if check
