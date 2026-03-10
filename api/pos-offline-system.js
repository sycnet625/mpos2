// ==========================================
// 💾 SISTEMA OFFLINE V8 - FIXED EDITION
// ==========================================

if (typeof window.posOfflineLoaded === 'undefined') {
    window.posOfflineLoaded = true;

class POSCache {
    constructor() {
        this.dbName = 'POS_Offline_DB';
        this.dbVersion = 12; // Subida de versión
        this.db = null;
    }
    
    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                this.db = request.result;
                console.log('✅ IndexedDB Listo v' + this.dbVersion);
                resolve();
            };
            
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                
                // Stores básicos
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

                // Recrear store de ventas offline
                if (db.objectStoreNames.contains('offline_sales')) {
                    db.deleteObjectStore('offline_sales');
                }
                const salesStore = db.createObjectStore('offline_sales', { keyPath: 'id', autoIncrement: true });
                salesStore.createIndex('synced', 'synced', { unique: false });
            };
        });
    }

    // ==========================================
    // MÉTODOS PRODUCTOS
    // ==========================================
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

    // ==========================================
    // MÉTODOS CAJEROS (AGREGADO)
    // ==========================================
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

    // ==========================================
    // MÉTODOS SESIÓN DE CAJA
    // ==========================================
    async saveCajaSession(session) {
        if (!this.db) return;
        try {
            const tx = this.db.transaction(['caja_session'], 'readwrite');
            tx.objectStore('caja_session').put({ id: 'current', ...session, timestamp: Date.now() });
            return new Promise(resolve => tx.oncomplete = () => resolve());
        } catch(e) {
            console.error('Error guardando sesión:', e);
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

    // ==========================================
    // MÉTODOS CARRITO
    // ==========================================
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

    // ==========================================
    // MÉTODOS VENTAS OFFLINE
    // ==========================================
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
// 🚦 MONITOR DE CONEXIÓN
// ==========================================
class ConnectionMonitor {
    constructor() {
        this.isOnline = navigator.onLine;
        this.latency = 0;
    }
    
    async checkConnection() {
        try {
            const start = performance.now();
            const res = await fetch('pos.php?ping=1', { 
                method: 'GET', 
                cache: 'no-store', 
                signal: AbortSignal.timeout(3000) 
            });
            if (res.ok) {
                this.latency = Math.round(performance.now() - start);
                this.isOnline = true;
            } else { 
                throw new Error(); 
            }
        } catch (e) {
            this.isOnline = false;
            this.latency = 9999;
        }
        this.updateUI();
    }
    
    updateUI() {
        // Actualizar badge de conexión principal
        if (typeof updateOnlineStatus === 'function') {
            updateOnlineStatus();
        }
        this.updateSyncButton();
    }
    
    async updateSyncButton() {
        // Actualizar botón de sync en keypad
        const btn = document.getElementById('btnSyncKeypad');
        if (!btn) return;
        
        try {
            const pending = await window.posCache.getPendingSales();
            if (pending.length > 0) {
                btn.classList.remove('d-none');
                btn.disabled = false;
                btn.innerHTML = `<i class="fas fa-cloud-upload-alt"></i> ${pending.length}`;
                btn.title = `${pending.length} ventas pendientes por sincronizar`;
            } else {
                btn.classList.add('d-none');
                btn.disabled = true;
            }
        } catch(e) {
            console.error('Error actualizando botón sync:', e);
        }
    }
    
    start() {
        // Verificar conexión cada 10 segundos
        this.checkConnection();
        setInterval(() => { 
            this.checkConnection(); 
            if(this.isOnline) syncOfflineQueue(); 
        }, 10000);
        
        window.addEventListener('online', () => this.checkConnection());
        window.addEventListener('offline', () => this.checkConnection());
    }
}

window.connectionMonitor = new ConnectionMonitor();

// ==========================================
// 🔄 SINCRONIZACIÓN
// ==========================================
window.syncOfflineQueue = async function() {
    if (!navigator.onLine) {
        if (typeof showToast === 'function') showToast('Sin conexión', 'error');
        return;
    }
    
    const pending = await window.posCache.getPendingSales();
    if (pending.length === 0) return;

    console.log(`📤 Subiendo ${pending.length} ventas...`);
    
    let syncedCount = 0;
    let errorCount = 0;
    
    for (const sale of pending) {
        try {
            const res = await fetch('pos_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sale)
            });
            
            let data = {};
            try { 
                data = await res.json(); 
            } catch(e) { 
                console.error("Respuesta inválida del servidor"); 
                errorCount++;
                continue; 
            }
            
            if (data.status === 'success') {
                await window.posCache.markSaleSynced(sale.id);
                syncedCount++;
            } else {
                console.error("Error servidor:", data.msg);
                errorCount++;
            }
        } catch (e) {
            console.error("Error de red:", e);
            errorCount++;
        }
    }
    
    // Actualizar UI
    window.connectionMonitor.updateSyncButton();
    
    // Notificar resultado
    if (typeof showToast === 'function') {
        if (syncedCount > 0) {
            showToast(`✅ ${syncedCount} ventas sincronizadas`, 'success');
            if (typeof Synth !== 'undefined') Synth.tada();
        }
        if (errorCount > 0) {
            showToast(`⚠️ ${errorCount} ventas con error`, 'warning');
        }
    }
    
    return { synced: syncedCount, errors: errorCount };
};

// Función para sincronizar manualmente (llamada desde botón)
window.syncManual = async function() {
    const btn = document.getElementById('btnSyncKeypad');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;
    }
    
    try {
        await window.syncOfflineQueue();
    } finally {
        window.connectionMonitor.updateSyncButton();
    }
};

// ==========================================
// 🚀 INICIO
// ==========================================
window.initPOSOffline = async function() {
    try {
        await window.posCache.init();
        window.connectionMonitor.start();
        console.log('✅ Sistema offline inicializado');
    } catch(e) {
        console.error('❌ Error iniciando sistema offline:', e);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(window.initPOSOffline, 300);
});

// ==========================================
// HELPERS GLOBALES
// ==========================================
window.saveVentaOffline = async function(data) {
    // Si estamos online, intentar envío directo
    if (navigator.onLine) {
        try {
            const res = await fetch('pos_save.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            const json = await res.json();
            if (json.status === 'success') {
                return { success: true, online: true, id: json.id };
            }
        } catch(e) {
            console.log('Fallo envío online, guardando offline');
        }
    }
    
    // Si falla o estamos offline, guardar local
    await window.posCache.saveOfflineSale(data);
    window.connectionMonitor.updateSyncButton();
    return { success: true, online: false };
};

window.loadProductsWithCache = async function() {
    try {
        const res = await fetch('pos.php?load_products=1', { cache: 'no-store' });
        const data = await res.json();
        if (data.status === 'success' && data.products) {
            await window.posCache.saveProducts(data.products);
            if (typeof productsDB !== 'undefined') {
                window.productsDB = data.products;
            }
            if (typeof renderProducts === 'function') {
                renderProducts('all');
            }
        }
    } catch(e) {
        console.log('Cargando productos desde caché...');
        const cached = await window.posCache.getProducts();
        if (cached.length) {
            if (typeof productsDB !== 'undefined') {
                window.productsDB = cached;
            }
            if (typeof renderProducts === 'function') {
                renderProducts('all');
            }
        }
    }
};

} // End check

