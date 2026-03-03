// ==========================================
// SISTEMA OFFLINE V9 - CORREGIDO
// ==========================================

if (typeof window.posOfflineLoaded === 'undefined') {
    window.posOfflineLoaded = true;

    class POSCache {
        constructor() {
            this.dbName = 'POS_Offline_DB';
            this.dbVersion = 13;
            this.db = null;
        }
        
        async init() {
            return new Promise((resolve, reject) => {
                const request = indexedDB.open(this.dbName, this.dbVersion);
                request.onerror = () => reject(request.error);
                request.onsuccess = () => {
                    this.db = request.result;
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
                    if (!db.objectStoreNames.contains('cajeros')) {
                        db.createObjectStore('cajeros', { keyPath: 'pin' });
                    }
                    if (!db.objectStoreNames.contains('caja_session')) {
                        db.createObjectStore('caja_session', { keyPath: 'id' });
                    }
                    if (!db.objectStoreNames.contains('metadata')) {
                        db.createObjectStore('metadata', { keyPath: 'key' });
                    }

                    if (db.objectStoreNames.contains('offline_sales')) {
                        db.deleteObjectStore('offline_sales');
                    }
                    const salesStore = db.createObjectStore('offline_sales', { keyPath: 'id', autoIncrement: true });
                    salesStore.createIndex('synced', 'synced', { unique: false });
                };
            });
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
        async saveCajeros(cajeros) {
            if (!this.db || !cajeros || !Array.isArray(cajeros)) return;
            try {
                const tx = this.db.transaction(['cajeros'], 'readwrite');
                const store = tx.objectStore('cajeros');
                store.clear();
                cajeros.forEach(c => {
                    if (c.pin) store.put(c);
                });
                return new Promise(resolve => tx.oncomplete = () => resolve());
            } catch(e) {
                console.error('Error guardando cajeros:', e);
            }
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
                const tx = this.db.transaction(['cajeros'], 'readonly');
                return new Promise(resolve => {
                    const req = tx.objectStore('cajeros').get(pin);
                    req.onsuccess = () => resolve(req.result || null);
                    req.onerror = () => resolve(null);
                });
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
        }
        
        async checkConnection() {
            try {
                const start = performance.now();
                const res = await fetch('pos.php?ping=1', { 
                    method: 'GET', 
                    cache: 'no-store', 
                    signal: AbortSignal.timeout(5000) 
                });
                if (res.ok) {
                    this.latency = Math.round(performance.now() - start);
                    this.isOnline = true;
                } else { 
                    throw new Error('No OK'); 
                }
            } catch (e) {
                this.isOnline = false;
                this.latency = 9999;
            }
            this.updateUI();
        }
        
        updateUI() {
            if (typeof updateOnlineStatus === 'function') {
                updateOnlineStatus();
            }
        }
        
        start() {
            this.checkConnection();
            this.checkInterval = setInterval(() => { 
                this.checkConnection(); 
            }, 15000);
            
            window.addEventListener('online', () => {
                this.isOnline = true;
                this.checkConnection();
            });
            window.addEventListener('offline', () => {
                this.isOnline = false;
                this.latency = 9999;
                this.updateUI();
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
        try {
            await window.posCache.init();
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

