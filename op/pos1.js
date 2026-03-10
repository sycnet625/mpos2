// ARCHIVO: /var/www/palweb/api/pos.js
// VERSION: 3.2 - Sistema Offline Completo

const CACHE_KEY = 'products_cache_v1';
const QUEUE_KEY = 'pos_pending_sales';
const CACHE_DURATION = 5 * 60 * 1000;
const PARKED_ORDERS_KEY = 'pos_parked_orders';

// ==========================================
// MOTOR DE AUDIO (Synth)
// ==========================================
const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
const Synth = {
    playTone: (freq, type, duration, vol = 0.1) => {
        try {
            if(audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(vol, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch(e) {}
    },
    beep: () => Synth.playTone(1200, 'sine', 0.1, 0.12),
    error: () => { Synth.playTone(150, 'sawtooth', 0.3, 0.15); setTimeout(() => Synth.playTone(100, 'sawtooth', 0.3, 0.15), 100); },
    click: () => Synth.playTone(800, 'triangle', 0.05, 0.08),
    cash: () => { Synth.playTone(800, 'sine', 0.1, 0.12); setTimeout(() => Synth.playTone(1000, 'sine', 0.1, 0.12), 100); setTimeout(() => Synth.playTone(1200, 'sine', 0.15, 0.15), 200); },
    tada: () => { const now = audioCtx.currentTime; [523.25, 659.25, 783.99, 1046.50].forEach((freq, i) => { const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.frequency.value = freq; gain.gain.setValueAtTime(0.08, now + i*0.08); gain.gain.exponentialRampToValueAtTime(0.001, now + i*0.08 + 0.3); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(now + i*0.08); osc.stop(now + i*0.08 + 0.3); }); },
    refund: () => { const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.type = 'sawtooth'; osc.frequency.setValueAtTime(400, audioCtx.currentTime); osc.frequency.linearRampToValueAtTime(100, audioCtx.currentTime + 0.5); gain.gain.setValueAtTime(0.1, audioCtx.currentTime); gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.5); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(); osc.stop(audioCtx.currentTime + 0.5); },
    addCart: () => Synth.playTone(1000, 'sine', 0.06, 0.1),
    removeCart: () => Synth.playTone(500, 'sine', 0.08, 0.12),
    scan: () => Synth.playTone(1500, 'sine', 0.03, 0.08),
    category: () => Synth.playTone(700, 'triangle', 0.04, 0.06),
    discount: () => Synth.playTone(900, 'sine', 0.08, 0.1),
    openCash: () => Synth.playTone(1000, 'sine', 0.15, 0.12),
    closeCash: () => Synth.playTone(600, 'sine', 0.15, 0.12),
    warning: () => { Synth.playTone(500, 'square', 0.1, 0.15); setTimeout(() => Synth.playTone(500, 'square', 0.1, 0.15), 150); },
    increment: () => Synth.playTone(850, 'sine', 0.04, 0.07),
    decrement: () => Synth.playTone(650, 'sine', 0.04, 0.07),
    clear: () => Synth.playTone(400, 'sine', 0.08, 0.1)
};

// ==========================================
// NOTIFICACIONES (Toasts)
// ==========================================
function showToast(msg, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    let color = 'text-bg-success', icon = '<i class="fas fa-check-circle"></i>';
    if (type === 'error' || type === 'danger') { color = 'text-bg-danger'; icon = '<i class="fas fa-exclamation-circle"></i>'; }
    else if (type === 'warning') { color = 'text-bg-warning'; icon = '<i class="fas fa-cloud-upload-alt"></i>'; }
    
    const div = document.createElement('div');
    div.innerHTML = '<div class="toast align-items-center ' + color + ' border-0 mb-2 shadow" role="alert"><div class="d-flex"><div class="toast-body fw-bold fs-6">' + icon + ' ' + msg + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
    const toastEl = div.firstElementChild;
    container.appendChild(toastEl);
    new bootstrap.Toast(toastEl, { delay: 3000 }).show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    if (type === 'error') Synth.error();
}

// ==========================================
// VARIABLES GLOBALES
// ==========================================
window.productsDB = []; 
let productsDB = window.productsDB;
let cart = []; 
let selectedIndex = -1; 
let enteredPin = "";
let currentCashier = "Cajero"; 
let cashId = 0; 
let cashOpen = false;
let accountingDate = ""; 
let barcodeBuffer = ""; 
let barcodeTimeout; 
let globalDiscountPct = 0;
window.stockFilterActive = false;

// ==========================================
// INICIALIZACION
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    console.log('Iniciando POS...');
    
    setTimeout(() => {
        if (typeof window.initPOSOffline === 'function') {
            window.initPOSOffline();
        }
    }, 300);
    
    if (typeof PRODUCTS_DATA !== 'undefined' && Array.isArray(PRODUCTS_DATA) && PRODUCTS_DATA.length > 0) { 
        console.log('Cargando ' + PRODUCTS_DATA.length + ' productos desde servidor...');
        window.productsDB = PRODUCTS_DATA;
        productsDB = window.productsDB;
        saveToCache(productsDB); 
        renderProducts('all');
    } else { 
        console.log('PRODUCTS_DATA vacio, cargando desde cache...');
        loadFromCacheOrRefresh();
    }
    
    updatePinDisplay();
    document.addEventListener('keydown', handleBarcodeScanner);
    document.body.addEventListener('click', () => { if(audioCtx.state === 'suspended') audioCtx.resume(); }, {once:true});
    checkCashStatusSilent();
    
    window.addEventListener('online', () => { 
        console.log('Conexion restaurada');
        updateOnlineStatus(); 
        syncAllPending(); 
    });
    window.addEventListener('offline', () => {
        console.log('Conexion perdida');
        updateOnlineStatus();
    });
    
    updateOnlineStatus();
    setInterval(updateSyncKeypadButton, 5000);
});

// ==========================================
// LOGICA OFFLINE Y SYNC
// ==========================================
function updateOnlineStatus() {
    const badge = document.getElementById('netStatus');
    const btnSync = document.getElementById('btnSync');
    const legacyQueue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
    let pendingCount = legacyQueue.length;
    
    if (typeof window.connectionMonitor !== 'undefined') {
        const monitor = window.connectionMonitor;
        const latency = monitor.latency;
        
        if (!navigator.onLine || !monitor.isOnline) {
            if(badge) { badge.className = 'badge bg-danger'; badge.innerHTML = '🔌 OFF'; }
        } else if (latency < 200) {
            if(badge) { badge.className = 'badge bg-success'; badge.innerHTML = '🚀 ' + latency + 'ms'; }
        } else if (latency < 500) {
            if(badge) { badge.className = 'badge bg-success'; badge.innerHTML = '🐇 ' + latency + 'ms'; }
        } else if (latency < 1500) {
            if(badge) { badge.className = 'badge bg-warning text-dark'; badge.innerHTML = '🐢 ' + latency + 'ms'; }
        } else {
            if(badge) { badge.className = 'badge bg-danger'; badge.innerHTML = '🐌 ' + latency + 'ms'; }
        }
    } else {
        if (navigator.onLine) {
            if(badge) { badge.className = 'badge bg-success'; badge.innerHTML = '🚀 ON'; }
        } else {
            if(badge) { badge.className = 'badge bg-danger'; badge.innerHTML = '🔌 OFF'; }
        }
    }
    
    if (navigator.onLine && pendingCount > 0) {
        if(btnSync) { btnSync.classList.remove('d-none'); btnSync.innerHTML = '<i class="fas fa-sync"></i> ' + pendingCount; }
    } else {
        if(btnSync) btnSync.classList.add('d-none');
    }
    
    updateSyncKeypadButton();
}

async function updateSyncKeypadButton() {
    const btn = document.getElementById('btnSyncKeypad');
    if (!btn) return;
    
    let pendingCount = 0;
    const legacyQueue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
    pendingCount += legacyQueue.length;
    
    if (typeof window.posCache !== 'undefined' && typeof window.posCache.getPendingSales === 'function') {
        try {
            const idbPending = await window.posCache.getPendingSales();
            pendingCount += idbPending.length;
        } catch(e) {}
    }
    
    if (pendingCount > 0) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> ' + pendingCount;
        btn.title = pendingCount + ' ventas pendientes - Click para sincronizar';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.4';
        btn.innerHTML = '<i class="fas fa-check-circle"></i> 0';
        btn.title = 'No hay ventas pendientes';
    }
}

async function syncOfflineQueue() {
    if (!navigator.onLine) return showToast("Sin internet", "error");
    
    const queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
    if (queue.length === 0) return;
    
    const btn = document.getElementById('btnSync'); 
    if(btn) btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>';
    
    const failedQueue = []; 
    let syncedCount = 0;
    
    for (const sale of queue) {
        try {
            const resp = await fetch('pos_save.php', { 
                method: 'POST', 
                headers: {'Content-Type': 'application/json'}, 
                body: JSON.stringify(sale) 
            });
            const res = await resp.json();
            if (res.status === 'success') syncedCount++; 
            else failedQueue.push(sale);
        } catch (e) { 
            failedQueue.push(sale); 
        }
    }
    
    localStorage.setItem(QUEUE_KEY, JSON.stringify(failedQueue));
    updateOnlineStatus();
    
    if (syncedCount > 0) { 
        showToast(syncedCount + ' ventas sincronizadas', 'success'); 
        Synth.tada(); 
    }
    if (btn) btn.innerHTML = '<i class="fas fa-sync"></i>';
}

async function syncAllPending() {
    if (!navigator.onLine) return;
    
    let totalSynced = 0;
    let totalErrors = 0;
    
    // localStorage
    const legacyQueue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
    if (legacyQueue.length > 0) {
        const failedQueue = [];
        for (const sale of legacyQueue) {
            try {
                const resp = await fetch('pos_save.php', { 
                    method: 'POST', 
                    headers: {'Content-Type': 'application/json'}, 
                    body: JSON.stringify(sale) 
                });
                const res = await resp.json();
                if (res.status === 'success') totalSynced++; 
                else { failedQueue.push(sale); totalErrors++; }
            } catch (e) { 
                failedQueue.push(sale); 
                totalErrors++;
            }
        }
        localStorage.setItem(QUEUE_KEY, JSON.stringify(failedQueue));
    }
    
    // IndexedDB
    if (typeof window.posCache !== 'undefined' && typeof window.posCache.getPendingSales === 'function') {
        try {
            const idbPending = await window.posCache.getPendingSales();
            for (const sale of idbPending) {
                try {
                    const resp = await fetch('pos_save.php', { 
                        method: 'POST', 
                        headers: {'Content-Type': 'application/json'}, 
                        body: JSON.stringify(sale) 
                    });
                    const res = await resp.json();
                    if (res.status === 'success') {
                        await window.posCache.markSaleSynced(sale.id);
                        totalSynced++;
                    } else {
                        totalErrors++;
                    }
                } catch (e) { 
                    totalErrors++;
                }
            }
        } catch(e) {
            console.error('Error sincronizando IndexedDB:', e);
        }
    }
    
    if (totalSynced > 0) {
        showToast(totalSynced + ' ventas sincronizadas', 'success');
        Synth.tada();
    }
    
    updateOnlineStatus();
}

async function syncManual() {
    const btn = document.getElementById('btnSyncKeypad');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;
    }
    
    if (!navigator.onLine) {
        showToast('Sin conexion a internet', 'error');
        Synth.error();
        updateSyncKeypadButton();
        return;
    }
    
    await syncAllPending();
    updateSyncKeypadButton();
}

// ==========================================
// GESTION DE PRODUCTOS Y CACHE
// ==========================================
function loadFromCacheOrRefresh() {
    const cached = localStorage.getItem(CACHE_KEY);
    if (cached) {
        try {
            const {data} = JSON.parse(cached);
            if (Array.isArray(data) && data.length > 0) {
                window.productsDB = data;
                productsDB = window.productsDB;
                renderProducts('all');
                console.log('Cargados ' + data.length + ' productos desde cache');
                return;
            }
        } catch(e) {}
    }
    refreshProducts();
}

function saveToCache(data) { 
    try { 
        localStorage.setItem(CACHE_KEY, JSON.stringify({ data: data, timestamp: Date.now() })); 
    } catch (e) {} 
}

async function refreshProducts() {
    const btn = document.getElementById('btnRefresh'); 
    if (btn) { btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>'; btn.disabled = true; }
    
    try {
        localStorage.removeItem(CACHE_KEY);
        
        if (typeof window.posCache !== 'undefined' && window.posCache.db) {
            try {
                const tx = window.posCache.db.transaction(['products', 'metadata'], 'readwrite');
                tx.objectStore('products').clear();
                tx.objectStore('metadata').delete('products_timestamp');
                await new Promise(resolve => tx.oncomplete = resolve);
            } catch (e) {}
        }
        
        const response = await fetch('pos.php?load_products=1&t=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' }
        });
        
        if (!response.ok) throw new Error('Error del servidor: ' + response.status);
        
        const result = await response.json();
        
        if (result.status === 'success' && Array.isArray(result.products)) { 
            window.productsDB = result.products;
            productsDB = window.productsDB;
            saveToCache(result.products);
            
            if (typeof window.posCache !== 'undefined' && window.posCache.saveProducts) {
                await window.posCache.saveProducts(result.products);
            }
            
            renderProducts('all', document.getElementById('searchInput')?.value || ''); 
            showToast('Catalogo actualizado (' + result.products.length + ' productos)'); 
        }
    } catch(e) { 
        console.error('Error actualizando productos:', e);
        showToast("Error al actualizar: " + e.message, "error"); 
    } finally { 
        if (btn) { btn.innerHTML = '<i class="fas fa-sync-alt"></i>'; btn.disabled = false; } 
    }
}

// ==========================================
// ESCANER Y TECLADO
// ==========================================
function handleBarcodeScanner(e) { 
    if(e.target.tagName === 'INPUT' && e.target.id !== 'searchInput') return; 
    if(e.ctrlKey || e.altKey || e.metaKey) return; 
    clearTimeout(barcodeTimeout); 
    barcodeTimeout = setTimeout(() => { barcodeBuffer = ""; }, 100); 
    if(e.key === 'Enter') { 
        if(barcodeBuffer.length > 0) { 
            e.preventDefault(); 
            processBarcode(barcodeBuffer); 
            barcodeBuffer = ""; 
        } 
    } else if(e.key.length === 1) { 
        barcodeBuffer += e.key; 
    } 
}

function processBarcode(c) { 
    const p = productsDB.find(x => x.codigo == c); 
    if(p) { 
        if(parseFloat(p.stock) <= 0 && !p.es_servicio) { 
            showToast('AGOTADO', 'error'); 
            return; 
        } 
        addToCart(p); 
        Synth.beep(); 
        const s = document.getElementById('searchInput'); 
        if(s) { s.value = ""; s.focus(); } 
    } else { 
        Synth.error(); 
    } 
}

function typePin(v) { 
    Synth.click(); 
    if(v === 'C') enteredPin = ""; 
    else if(enteredPin.length < 4) enteredPin += v; 
    updatePinDisplay(); 
}

function updatePinDisplay() { 
    const display = document.getElementById('pinDisplay');
    if (display) display.innerText = String.fromCharCode(8226).repeat(enteredPin.length); 
}

// ==========================================
// VERIFICACION DE PIN (ONLINE/OFFLINE)
// ==========================================
async function verifyPin() {
    if(enteredPin.length < 4) return;
    
    let loginSuccess = false;
    let cajeroNombre = null;
    
    if (navigator.onLine) {
        try {
            const resp = await fetch('pos_cash.php?action=login', { 
                method: 'POST', 
                headers: {'Content-Type': 'application/json'}, 
                body: JSON.stringify({ pin: enteredPin }),
                signal: AbortSignal.timeout(5000)
            });
            const data = await resp.json();
            if(data.status === 'success') { 
                loginSuccess = true;
                cajeroNombre = data.cajero;
                currentCashier = data.cajero;
                
                if (typeof CAJEROS_CONFIG !== 'undefined' && 
                    typeof window.posCache !== 'undefined' && 
                    window.posCache.db &&
                    typeof window.posCache.saveCajeros === 'function') {
                    window.posCache.saveCajeros(CAJEROS_CONFIG);
                }
            }
        } catch(e) { 
            console.log('Login online fallo, intentando offline:', e.message);
        }
    }
    
    if (!loginSuccess) {
        if (typeof CAJEROS_CONFIG !== 'undefined' && Array.isArray(CAJEROS_CONFIG)) {
            const cajero = CAJEROS_CONFIG.find(c => c.pin === enteredPin);
            if (cajero) {
                loginSuccess = true;
                cajeroNombre = cajero.nombre;
                currentCashier = cajero.nombre;
            }
        }
        
        if (!loginSuccess && typeof window.posCache !== 'undefined' && typeof window.posCache.verifyCajeroOffline === 'function') {
            try {
                const cajero = await window.posCache.verifyCajeroOffline(enteredPin);
                if (cajero) {
                    loginSuccess = true;
                    cajeroNombre = cajero.nombre;
                    currentCashier = cajero.nombre;
                }
            } catch(e) {}
        }
    }
    
    if (loginSuccess) {
        Synth.tada();
        unlockPos();
    } else {
        Synth.error();
        showToast('PIN incorrecto', 'error');
        enteredPin = "";
        updatePinDisplay();
    }
}

function unlockPos() {
    const overlay = document.getElementById('pinOverlay');
    const cashierName = document.getElementById('cashierName');
    if (overlay) overlay.style.display = 'none';
    if (cashierName) cashierName.innerText = currentCashier;
    checkCashStatusSilent();
}

// ==========================================
// GESTION DE CAJA
// ==========================================
let cashModal;
document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('cashModal');
    if (modalEl) cashModal = new bootstrap.Modal(modalEl);
});

async function checkCashStatusSilent() {
    try {
        const resp = await fetch('pos_cash.php?action=status');
        const data = await resp.json();
        const btn = document.getElementById('cashBtn');
        const badge = document.getElementById('cashStatusBadge');
        
        if (data.status === 'open') {
            cashOpen = true;
            cashId = data.data.id;
            accountingDate = data.data.fecha_contable;
            if(btn) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');
                btn.innerText = 'ABIERTA (' + accountingDate + ')';
            }
            if(badge) {
                badge.className = 'cash-status cash-open ms-2';
                badge.style.cssText = 'font-size: 0.7rem; background-color: #28a745 !important; color: white !important; padding: 2px 8px; border-radius: 4px;';
                badge.innerText = 'ABIERTA (' + accountingDate + ')';
            }
        } else {
            cashOpen = false;
            cashId = 0;
            if(btn) {
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
                btn.innerText = 'ABRIR CAJA';
            }
            if(badge) {
                badge.className = 'cash-status cash-closed ms-2';
                badge.style.cssText = 'font-size: 0.7rem; background-color: #dc3545 !important; color: white !important; padding: 2px 8px; border-radius: 4px;';
                badge.innerText = 'CERRADA';
            }
        }
    } catch(e) {
        console.log('No se pudo verificar estado de caja (offline)');
    }
}

function showCashModal() { 
    if(!cashOpen) showOpenCashModal(); 
    else showCloseCashModal(); 
}

// Alias para compatibilidad con el HTML
function checkCashRegister() {
    showCashModal();
}

async function showOpenCashModal() {
    const body = document.getElementById('cashModalBody');
    if (!body) return;
    
    body.innerHTML = 
        '<div class="text-center mb-3"><h5 class="mb-0 fw-bold"><i class="fas fa-cash-register me-2"></i>Apertura de Caja</h5></div>' +
        '<div class="mb-3"><label class="form-label small fw-bold">Fecha Contable del Turno:</label>' +
        '<input type="date" id="cashDate" class="form-control" value="' + new Date().toISOString().split('T')[0] + '"></div>' +
        '<div class="mb-3"><label class="form-label small fw-bold">Monto Inicial en Caja:</label>' +
        '<input type="number" id="cashStart" class="form-control form-control-lg" placeholder="0.00" step="0.01"></div>' +
        '<button onclick="openCash()" class="btn btn-success w-100 fw-bold py-2"><i class="fas fa-door-open me-2"></i>ABRIR TURNO</button>';
    
    cashModal.show();
}

async function openCash() { 
    const a = parseFloat(document.getElementById('cashStart').value) || 0; 
    const f = document.getElementById('cashDate').value;
    
    if (!f) {
        showToast('Seleccione fecha contable', 'warning');
        return;
    }
    
    try {
        await fetch('pos_cash.php?action=open', { 
            method: 'POST', 
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ cajero: currentCashier, monto: a, fecha: f })
        }); 
        cashModal.hide(); 
        Synth.openCash(); 
        checkCashStatusSilent(); 
        showToast("Caja Abierta Correctamente");
    } catch(e) {
        showToast('Error al abrir caja', 'error');
    }
}

async function showCloseCashModal() {
    const body = document.getElementById('cashModalBody');
    if (!body) return;
    
    body.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Cargando datos...</p></div>';
    cashModal.show();
    
    try {
        const r = await fetch('pos_cash.php?action=status'); 
        const d = await r.json(); 
        
        if(d.status !== 'open') {
            showToast("No hay sesion abierta", "error");
            cashModal.hide();
            return;
        }
        
        let totalSistema = 0; 
        
        let htmlMetodosPago = '<div class="mb-3"><h6 class="fw-bold text-primary"><i class="fas fa-credit-card me-1"></i> Por Metodo de Pago</h6><ul class="list-group list-group-flush small">'; 
        (d.ventas || []).forEach(v => { 
            htmlMetodosPago += '<li class="list-group-item d-flex justify-content-between py-1 px-2"><span>' + v.metodo_pago + '</span><strong>$' + parseFloat(v.total).toFixed(2) + '</strong></li>'; 
            totalSistema += parseFloat(v.total); 
        }); 
        htmlMetodosPago += '</ul></div>';
        
        let htmlTipoServicio = '';
        if (d.ventas_tipo && d.ventas_tipo.length > 0) {
            htmlTipoServicio = '<div class="mb-3"><h6 class="fw-bold text-success"><i class="fas fa-utensils me-1"></i> Por Tipo de Servicio</h6><ul class="list-group list-group-flush small">';
            d.ventas_tipo.forEach(v => {
                let icono = '🏪';
                let nombre = v.tipo_servicio || 'Mostrador';
                if (nombre === 'llevar' || nombre === 'para_llevar') { icono = '🥡'; nombre = 'Para Llevar'; }
                else if (nombre === 'mensajeria' || nombre === 'delivery') { icono = '🛵'; nombre = 'Mensajeria'; }
                else if (nombre === 'reserva') { icono = '📅'; nombre = 'Reservas'; }
                else if (nombre === 'consumir_aqui' || nombre === 'comer') { icono = '🍽️'; nombre = 'Comer Aqui'; }
                
                htmlTipoServicio += '<li class="list-group-item d-flex justify-content-between py-1 px-2"><span>' + icono + ' ' + nombre + ' <small class="text-muted">(' + (v.cantidad || 0) + ')</small></span><strong>$' + parseFloat(v.total).toFixed(2) + '</strong></li>';
            });
            htmlTipoServicio += '</ul></div>';
        }

        body.innerHTML = 
            '<div class="text-center mb-3"><h5 class="mb-1 fw-bold"><i class="fas fa-cash-register me-2"></i>Cierre de Turno</h5>' +
            '<span class="badge bg-primary">Fecha: ' + (d.data ? d.data.fecha_contable : 'N/A') + '</span></div>' +
            '<div class="alert alert-success text-center py-2 mb-3"><small class="d-block text-uppercase">Total Ventas del Turno</small>' +
            '<span class="h3 mb-0 fw-bold">$' + totalSistema.toFixed(2) + '</span></div>' +
            '<div class="row"><div class="col-md-6">' + htmlMetodosPago + '</div><div class="col-md-6">' + htmlTipoServicio + '</div></div>' +
            '<hr><div class="mt-2"><label class="small fw-bold">Efectivo Real Contado:</label>' +
            '<input type="number" id="cashFinal" class="form-control form-control-lg border-warning mb-2" placeholder="0.00" step="0.01">' +
            '<label class="small fw-bold">Observaciones:</label>' +
            '<textarea id="cashNote" class="form-control mb-3" rows="2" placeholder="Notas del cierre..."></textarea></div>' +
            '<button onclick="validateAndCloseCash(' + d.data.id + ',' + totalSistema + ')" class="btn btn-danger w-100 py-2 fw-bold shadow-sm">' +
            '<i class="fas fa-lock me-2"></i> CERRAR TURNO</button>';
            
    } catch(e) {
        body.innerHTML = '<div class="text-center text-danger p-4"><i class="fas fa-exclamation-circle fa-2x"></i><p>Error al cargar datos</p></div>';
    }
}

async function validateAndCloseCash(id, sys) { 
    const r = parseFloat(document.getElementById('cashFinal').value); 
    const n = document.getElementById('cashNote').value;
    
    if(isNaN(r)) {
        showToast('Ingrese monto real en fisico', 'warning');
        return;
    }
    
    const diff = r - sys; 
    if(diff !== 0 && !confirm('Diferencia detectada: $' + diff.toFixed(2) + '. Cerrar de todas formas?')) return; 
    
    try {
        const resp = await fetch('pos_cash.php?action=close', { 
            method: 'POST', 
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: id, sistema: sys, real: r, nota: n }) 
        }); 
        const res = await resp.json();
        
        if(res.status === 'success') {
            cashModal.hide(); 
            Synth.closeCash();
            checkCashStatusSilent(); 
            showToast('Turno Cerrado y Guardado', 'success'); 
        } else {
            showToast('Error al cerrar: ' + res.msg, 'error');
        }
    } catch(e) {
        showToast('Error de conexion', 'error');
    }
}

// ==========================================
// RENDERIZADO DE PRODUCTOS
// ==========================================
window.shouldShowProduct = function(p) {
    if (!window.stockFilterActive) return true;
    const stock = parseFloat(p.stock) || 0;
    return stock > 0 || p.es_servicio == 1;
};

window.toggleStockFilter = function() {
    window.stockFilterActive = !window.stockFilterActive;
    const btn = document.getElementById('btnStockFilter');
    
    if (btn) {
        if (window.stockFilterActive) {
            btn.classList.add('btn-filter-active');
            btn.title = 'Mostrando solo productos con stock';
        } else {
            btn.classList.remove('btn-filter-active');
            btn.title = 'Mostrando todos los productos';
        }
    }
    
    renderProducts();
};

window.toggleImages = function() {
    document.body.classList.toggle('mode-no-images');
    const btn = document.getElementById('btnToggleImages');
    if (btn) {
        if (document.body.classList.contains('mode-no-images')) {
            btn.classList.add('btn-filter-active');
            btn.title = 'Imagenes ocultas';
        } else {
            btn.classList.remove('btn-filter-active');
            btn.title = 'Imagenes visibles';
        }
    }
};

function renderProducts(category, searchTerm) {
    if (typeof category === 'undefined') {
        const activeBtn = document.querySelector('.category-btn.active');
        category = activeBtn ? (activeBtn.innerText === 'TODOS' ? 'all' : activeBtn.innerText) : 'all';
    }

    const grid = document.getElementById('productContainer');
    if (!grid) return;
    
    grid.innerHTML = '';
    
    const searchInput = document.getElementById('searchInput');
    const term = (typeof searchTerm === 'string' ? searchTerm : (searchInput ? searchInput.value : '')).toLowerCase();

    const sourceData = window.productsDB || window.PRODUCTS_DATA || [];
    
    if (!Array.isArray(sourceData) || sourceData.length === 0) {
        grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-box-open fa-3x mb-2"></i><p>No hay productos disponibles</p></div>';
        return;
    }

    let filtered = sourceData.filter(p => {
        const matchCat = category === 'all' || p.categoria === category;
        const matchSearch = !term || 
            (p.nombre && p.nombre.toLowerCase().includes(term)) || 
            (p.codigo && p.codigo.toLowerCase().includes(term));
        return matchCat && matchSearch;
    });
    
    if (typeof window.shouldShowProduct === 'function') {
        filtered = filtered.filter(p => window.shouldShowProduct(p));
    }

    if (filtered.length === 0) {
        grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-search fa-3x mb-2"></i><p>No se encontraron productos</p></div>';
        return;
    }

    filtered.forEach(p => {
        const stock = parseFloat(p.stock) || 0;
        const hasStock = stock > 0 || p.es_servicio == 1;
        
        const card = document.createElement('div');
        card.className = 'product-card';
        if (!hasStock) card.classList.add('stock-zero-card');
        
        let badgeClass = hasStock ? 'stock-ok' : 'stock-zero';
        let stockDisplay = p.es_servicio == 1 ? '∞' : stock;
        
        let imgHTML = '<div class="product-img-container" style="background:' + (p.color || '#ccc') + '"><span class="placeholder-text">' + (p.nombre || '??').substring(0,2).toUpperCase() + '</span></div>';
        if(p.has_image) {
            imgHTML = '<div class="product-img-container"><img src="image.php?code=' + p.codigo + '" class="product-img" onerror="this.style.display=\'none\'"></div>';
        }

        card.innerHTML = 
            '<div class="stock-badge ' + badgeClass + '">' + stockDisplay + '</div>' +
            imgHTML +
            '<div class="product-info">' +
                '<div class="product-name text-dark">' + (p.nombre || 'Sin nombre') + '</div>' +
                '<div class="product-price">$' + parseFloat(p.precio || 0).toFixed(2) + '</div>' +
            '</div>';
        
        if (hasStock) {
            card.onclick = () => addToCart(p);
            card.style.cursor = 'pointer';
        } else {
            card.style.cursor = 'not-allowed';
        }
        
        grid.appendChild(card);
    });
}

function filterCategory(cat, btn) { 
    Synth.category(); 
    document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active')); 
    btn.classList.add('active'); 
    renderProducts(cat); 
}

function filterProducts() { 
    renderProducts('all', document.getElementById('searchInput').value); 
}

// ==========================================
// CARRITO
// ==========================================
function addToCart(p) {
    if(parseFloat(p.stock) <= 0 && p.es_servicio == 0) return showToast("Agotado", "error");
    const idx = cart.findIndex(i => i.id === p.codigo && (!i.note)); 
    if(idx >= 0) { 
        if(p.es_servicio == 0 && (cart[idx].qty + 1) > parseFloat(p.stock)) return showToast("Stock insuficiente", "error");
        cart[idx].qty++; 
        selectedIndex = idx; 
    } else { 
        cart.push({ id: p.codigo, name: p.nombre, price: parseFloat(p.precio), qty: 1, discountPct: 0, note: '' }); 
        selectedIndex = cart.length - 1; 
    }
    Synth.addCart(); 
    renderCart();
    saveCartState();
}

function renderCart() {
    const c = document.getElementById('cartContainer'); 
    if (!c) return;
    c.innerHTML = '';
    let sub = 0; 
    let items = 0;
    
    if(cart.length === 0) {
        c.innerHTML = '<div class="text-center text-muted mt-5 pt-5"><i class="fas fa-shopping-basket fa-2x mb-2 opacity-25"></i><p class="small">Carrito Vacio</p></div>';
    }
    
    cart.forEach((i, idx) => {
        const lineT = (i.price * (1 - i.discountPct/100)) * i.qty;
        sub += lineT; 
        items += i.qty;
        const d = document.createElement('div'); 
        d.className = 'cart-item' + (idx === selectedIndex ? ' selected' : '');
        d.onclick = () => { selectedIndex = idx; renderCart(); };
        
        let bdg = i.discountPct > 0 ? '<span class="discount-tag">-' + i.discountPct + '%</span>' : '';
        let nt = i.note ? '<span class="cart-note">📝 ' + i.note + '</span>' : '';
        
        d.innerHTML = '<div class="d-flex justify-content-between fw-bold"><span>' + i.qty + ' x ' + i.name + bdg + '</span><span>$' + lineT.toFixed(2) + '</span></div><div class="small text-muted">$' + (i.price*(1-i.discountPct/100)).toFixed(2) + '</div>' + nt;
        c.appendChild(d);
    });
    
    const total = sub * (1 - globalDiscountPct/100);
    const lbl = document.getElementById('totalAmount');
    if (lbl) {
        if (globalDiscountPct > 0) {
            lbl.innerHTML = '<small class="text-muted fs-6"><s>$' + sub.toFixed(2) + '</s> -' + globalDiscountPct + '%</small><br>$' + total.toFixed(2);
        } else {
            lbl.innerText = '$' + total.toFixed(2);
        }
    }
    
    const itemsEl = document.getElementById('totalItems');
    if (itemsEl) itemsEl.innerText = items;
}

function modifyQty(d) { 
    if(selectedIndex < 0) return; 
    const item = cart[selectedIndex];
    const prod = productsDB.find(x => x.codigo == item.id);
    if(d > 0 && prod && prod.es_servicio == 0 && (item.qty + d) > parseFloat(prod.stock)) {
        Synth.error();
        return showToast("Sin mas stock", "error");
    }
    item.qty += d; 
    if(item.qty <= 0) { 
        cart.splice(selectedIndex, 1); 
        selectedIndex = -1; 
        Synth.removeCart();
    } else {
        d > 0 ? Synth.increment() : Synth.decrement();
    }
    renderCart();
    saveCartState();
}

function removeItem() { 
    if(selectedIndex >= 0 && confirm('Eliminar producto?')) { 
        Synth.removeCart(); 
        cart.splice(selectedIndex, 1); 
        selectedIndex = -1; 
        renderCart();
        saveCartState();
    } 
}

function clearCart() { 
    if(cart.length > 0 && confirm('Vaciar carrito?')) { 
        Synth.clear(); 
        cart = []; 
        globalDiscountPct = 0; 
        selectedIndex = -1; 
        renderCart();
        localStorage.removeItem('pos_cart_state');
    } 
}

function askQty() { 
    if(selectedIndex < 0) return showToast("Seleccione producto", "warning"); 
    let q = prompt("Cantidad:", cart[selectedIndex].qty); 
    if(q && !isNaN(q) && q > 0) { 
        cart[selectedIndex].qty = Number(q); 
        Synth.increment(); 
        renderCart();
        saveCartState();
    } 
}

function applyDiscount() { 
    if(selectedIndex < 0) return showToast('Seleccione item', 'warning'); 
    let p = prompt("% Descuento Item:", cart[selectedIndex].discountPct); 
    if(p !== null) { 
        let v = parseFloat(p)||0; 
        if(v<0||v>100) return; 
        cart[selectedIndex].discountPct = v; 
        renderCart(); 
        Synth.discount();
        saveCartState();
    } 
}

function applyGlobalDiscount() { 
    if(cart.length === 0) return; 
    let p = prompt("% Descuento GLOBAL:", globalDiscountPct); 
    if(p !== null) { 
        let v = parseFloat(p)||0; 
        if(v<0||v>100) return; 
        globalDiscountPct = v; 
        renderCart(); 
        Synth.discount();
        saveCartState();
    } 
}

function addNote() { 
    if(selectedIndex < 0) return showToast('Seleccione producto', 'warning'); 
    let n = prompt("Nota de preparacion:", cart[selectedIndex].note); 
    if(n !== null) { 
        cart[selectedIndex].note = n; 
        renderCart();
        saveCartState();
    } 
}

// Auto-guardado del carrito
function saveCartState() {
    if (cart.length > 0) {
        const state = {
            cart: cart,
            globalDiscountPct: globalDiscountPct,
            selectedIndex: selectedIndex,
            timestamp: Date.now()
        };
        localStorage.setItem('pos_cart_state', JSON.stringify(state));
    } else {
        localStorage.removeItem('pos_cart_state');
    }
}

function restoreCartState() {
    const saved = localStorage.getItem('pos_cart_state');
    if (saved) {
        try {
            const state = JSON.parse(saved);
            if (Date.now() - state.timestamp < 3600000) {
                cart = state.cart || [];
                globalDiscountPct = state.globalDiscountPct || 0;
                selectedIndex = state.selectedIndex || -1;
                renderCart();
            } else {
                localStorage.removeItem('pos_cart_state');
            }
        } catch(e) {
            localStorage.removeItem('pos_cart_state');
        }
    }
}

// Restaurar carrito al cargar
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(restoreCartState, 500);
});

// ==========================================
// PROCESO DE PAGO
// ==========================================
let payModal;
document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('paymentModal');
    if (modalEl) payModal = new bootstrap.Modal(modalEl);
});

function openPaymentModal() { 
    if(cart.length === 0) return showToast('Carrito vacio', 'warning'); 
    if(!cashOpen) {
        Synth.error();
        return showToast('DEBE ABRIR CAJA', 'error');
    }
    const modalTotal = document.getElementById('modalTotal');
    const totalAmount = document.getElementById('totalAmount');
    if (modalTotal && totalAmount) modalTotal.innerHTML = totalAmount.innerHTML;
    payModal.show(); 
}

function toggleServiceOptions() { 
    const t = document.getElementById('serviceType').value; 
    const rd = document.getElementById('reservationDiv'); 
    const dd = document.getElementById('deliveryDiv'); 
    if (rd) rd.classList.add('d-none'); 
    if (dd) dd.classList.add('d-none'); 
    if(t === 'reserva' && rd) rd.classList.remove('d-none'); 
    if(t === 'mensajeria' && dd) dd.classList.remove('d-none'); 
}

async function confirmPayment() {
    let sub = cart.reduce((acc, i) => acc + ((i.price * (1 - i.discountPct/100)) * i.qty), 0);
    let tot = sub * (1 - globalDiscountPct/100);
    
    const metEl = document.querySelector('input[name="payMethod"]:checked');
    const met = metEl ? metEl.value : 'Efectivo';
    const servEl = document.getElementById('serviceType');
    const serv = servEl ? servEl.value : 'mostrador';
    const dateEl = document.getElementById('reservationDate');
    const date = dateEl ? dateEl.value : '';
    const prEl = document.getElementById('printTicket');
    const pr = prEl ? prEl.checked : false;
    
    const cliNameEl = document.getElementById('cliName');
    let cN = (cliNameEl ? cliNameEl.value : '').trim() || 'Consumidor Final';
    const itms = cart.map(i => ({ id: i.id, name: i.name, qty: i.qty, price: i.price, note: i.note }));

    const payload = { 
        uuid: crypto.randomUUID(), 
        items: itms, 
        total: tot, 
        metodo_pago: met, 
        tipo_servicio: serv, 
        fecha_reserva: date, 
        cliente_nombre: cN, 
        cliente_telefono: (document.getElementById('cliPhone')?.value || '').trim(), 
        cliente_direccion: (document.getElementById('cliAddr')?.value || '').trim(), 
        mensajero_nombre: (document.getElementById('deliveryDriver')?.value || '').trim(), 
        abono: document.getElementById('reservationAbono')?.value || 0, 
        id_caja: cashId,
        timestamp: Date.now()
    };

    payModal.hide();

    if (navigator.onLine) {
        try {
            const r = await fetch('pos_save.php', { 
                method: 'POST', 
                headers: {'Content-Type': 'application/json'}, 
                body: JSON.stringify(payload) 
            });
            const res = await r.json();
            
            if (res.status === 'success') {
                if(tot < 0) Synth.refund(); else Synth.cash();
                if(pr) window.open('ticket_view.php?id=' + res.id, 'Ticket', 'width=380,height=600'); 
                else showToast('Venta #' + res.id + ' registrada');
                finishSale();
                return;
            } else {
                saveOffline(payload);
                return;
            }
        } catch (e) {
            saveOffline(payload);
            return;
        }
    } else {
        saveOffline(payload);
    }
}

function saveOffline(p) {
    const q = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); 
    q.push(p); 
    localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
    
    if (typeof window.posCache !== 'undefined' && typeof window.posCache.saveOfflineSale === 'function') {
        window.posCache.saveOfflineSale(p).catch(e => console.error('Error guardando en IDB:', e));
    }
    
    Synth.warning();
    showToast('Venta guardada OFFLINE (pendiente)', 'warning');
    
    updateOnlineStatus();
    finishSale();
}

function finishSale() { 
    cart = []; 
    globalDiscountPct = 0; 
    selectedIndex = -1; 
    renderCart(); 
    localStorage.removeItem('pos_cart_state');
    
    const fields = ['cliName', 'cliPhone', 'cliAddr', 'deliveryDriver', 'reservationDate', 'reservationAbono'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    
    if (navigator.onLine) {
        refreshProducts();
    }
}

// ==========================================
// ORDENES PAUSADAS
// ==========================================
function parkOrder() {
    if (cart.length === 0) return showToast('Carrito vacio', 'warning');
    
    const orderName = prompt('Nombre para esta orden:', 'Mesa ' + Date.now().toString().slice(-4));
    if (!orderName) return;
    
    const parkedOrders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    
    const newOrder = {
        id: Date.now(),
        name: orderName,
        items: [...cart],
        globalDiscount: globalDiscountPct,
        timestamp: new Date().toISOString(),
        total: cart.reduce((acc, i) => {
            const itemTotal = (i.price * (1 - i.discountPct/100)) * i.qty;
            return acc + itemTotal;
        }, 0) * (1 - globalDiscountPct/100)
    };
    
    parkedOrders.push(newOrder);
    localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(parkedOrders));
    
    Synth.click();
    showToast('Orden "' + orderName + '" pausada', 'success');
    
    cart = [];
    globalDiscountPct = 0;
    selectedIndex = -1;
    renderCart();
    localStorage.removeItem('pos_cart_state');
}

function showParkedOrders() {
    const parkedOrders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    
    if (parkedOrders.length === 0) {
        return showToast('No hay ordenes pausadas', 'warning');
    }
    
    let html = '<div class="list-group">';
    parkedOrders.forEach((order, index) => {
        const date = new Date(order.timestamp);
        const timeStr = date.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
        
        html += '<div class="list-group-item d-flex justify-content-between align-items-center">' +
            '<div><strong>' + order.name + '</strong><br>' +
            '<small class="text-muted">' + order.items.length + ' items - $' + order.total.toFixed(2) + ' - ' + timeStr + '</small></div>' +
            '<div class="btn-group btn-group-sm">' +
            '<button class="btn btn-primary" onclick="loadParkedOrder(' + index + ')"><i class="fas fa-undo"></i></button>' +
            '<button class="btn btn-danger" onclick="deleteParkedOrder(' + index + ')"><i class="fas fa-trash"></i></button>' +
            '</div></div>';
    });
    html += '</div>';
    
    let modal = document.getElementById('parkedOrdersModal');
    if (!modal) {
        const modalHtml = '<div class="modal fade" id="parkedOrdersModal" tabindex="-1">' +
            '<div class="modal-dialog modal-dialog-scrollable">' +
            '<div class="modal-content">' +
            '<div class="modal-header bg-warning">' +
            '<h5 class="modal-title"><i class="fas fa-pause"></i> Ordenes Pausadas</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
            '</div>' +
            '<div class="modal-body" id="parkedOrdersBody"></div>' +
            '</div></div></div>';
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modal = document.getElementById('parkedOrdersModal');
    }
    
    document.getElementById('parkedOrdersBody').innerHTML = html;
    new bootstrap.Modal(modal).show();
}

function loadParkedOrder(index) {
    const parkedOrders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    const order = parkedOrders[index];
    
    if (!order) return;
    
    if (cart.length > 0 && !confirm('Reemplazar carrito actual?')) return;
    
    cart = [...order.items];
    globalDiscountPct = order.globalDiscount || 0;
    selectedIndex = cart.length > 0 ? 0 : -1;
    
    parkedOrders.splice(index, 1);
    localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(parkedOrders));
    
    renderCart();
    Synth.addCart();
    showToast('Orden recuperada', 'success');
    
    const modalEl = document.getElementById('parkedOrdersModal');
    if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
}

function deleteParkedOrder(index) {
    if (!confirm('Eliminar orden pausada?')) return;
    
    const parkedOrders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    parkedOrders.splice(index, 1);
    localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(parkedOrders));
    
    showParkedOrders();
    Synth.removeCart();
}

// ==========================================
// HISTORIAL DE TICKETS
// ==========================================
window.sessionTickets = [];

window.showHistorialModal = async function() {
    let modal = document.getElementById('historialModal');
    if (!modal) return showToast('Modal no encontrado', 'error');
    
    const bsModal = new bootstrap.Modal(modal);
    const body = document.getElementById('historialModalBody');
    
    body.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i><p class="mt-2 text-muted">Cargando tickets...</p></div>';
    bsModal.show();
    
    if (!navigator.onLine) {
        await renderHistorialOffline(body);
        return;
    }
    
    try {
        const response = await fetch('pos_historial.php');
        const data = await response.json();
        
        if (data.status === 'success') {
            window.sessionTickets = data.tickets;
            renderHistorial(body, data);
        } else {
            throw new Error(data.message || 'Error al cargar');
        }
    } catch (error) {
        console.error('Error cargando historial:', error);
        await renderHistorialOffline(body);
    }
};

function renderHistorial(body, data) {
    const { tickets, detalles, totales } = data;
    
    if (!tickets || tickets.length === 0) {
        body.innerHTML = '<div class="text-center p-4 text-muted"><i class="fas fa-receipt fa-3x mb-3 opacity-50"></i><h5>Sin tickets</h5><p>No hay ventas registradas en esta sesion.</p></div>';
        return;
    }
    
    const detallesPorTicket = {};
    if (detalles) {
        detalles.forEach(d => {
            if (!detallesPorTicket[d.id_venta_cabecera]) {
                detallesPorTicket[d.id_venta_cabecera] = [];
            }
            detallesPorTicket[d.id_venta_cabecera].push(d);
        });
    }
    
    // Contar tickets y sumar valores por método de pago
    const contadorPagos = {};
    const totalPorPago = {};
    tickets.forEach(t => {
        if (parseFloat(t.total) >= 0) { // Solo contar ventas, no devoluciones
            const metodo = t.metodo_pago || 'Otro';
            contadorPagos[metodo] = (contadorPagos[metodo] || 0) + 1;
            totalPorPago[metodo] = (totalPorPago[metodo] || 0) + parseFloat(t.total);
        }
    });
    
    // Estilos inline forzados para colores por tipo de pago (no se modifican con skin)
    const paymentColors = {
        'efectivo': 'background-color: #d4edda !important; border-left: 4px solid #28a745 !important;',
        'transferencia': 'background-color: #cce5ff !important; border-left: 4px solid #007bff !important;',
        'tarjeta': 'background-color: #e2d5f1 !important; border-left: 4px solid #6f42c1 !important;',
        'gasto casa': 'background-color: #fff3cd !important; border-left: 4px solid #ffc107 !important;',
        'gasto': 'background-color: #fff3cd !important; border-left: 4px solid #ffc107 !important;',
        'credito': 'background-color: #fce4d6 !important; border-left: 4px solid #fd7e14 !important;',
        'refund': 'background-color: #f8d7da !important; border-left: 4px solid #dc3545 !important;'
    };
    
    const badgeColors = {
        'efectivo': 'background-color: #28a745 !important; color: white !important;',
        'transferencia': 'background-color: #007bff !important; color: white !important;',
        'tarjeta': 'background-color: #6f42c1 !important; color: white !important;',
        'gasto casa': 'background-color: #ffc107 !important; color: #212529 !important;',
        'gasto': 'background-color: #ffc107 !important; color: #212529 !important;',
        'credito': 'background-color: #fd7e14 !important; color: white !important;'
    };
    
    // Generar resumen de pagos por tipo con cantidad y valor
    let resumenPagosHtml = '';
    Object.keys(contadorPagos).forEach(metodo => {
        const metodoCss = metodo.toLowerCase();
        const badgeStyle = badgeColors[metodoCss] || 'background-color: #6c757d !important; color: white !important;';
        const valorTotal = totalPorPago[metodo] || 0;
        resumenPagosHtml += '<span class="badge me-1 mb-1" style="' + badgeStyle + 'font-size:0.65rem;">' + metodo + ': ' + contadorPagos[metodo] + ' ($' + valorTotal.toFixed(2) + ')</span>';
    });
    
    // 4 Cards reorganizados: Tickets, Total, Devoluciones (unificado), Resumen pagos
    let html = '<div style="background-color:#f8f9fa !important;" class="p-3 border-bottom"><div class="row g-2">' +
        '<div class="col-6 col-md-3"><div class="text-center p-2 rounded" style="background-color:white !important;box-shadow:0 1px 3px rgba(0,0,0,0.1);"><div class="text-muted small">Tickets</div><div class="fw-bold fs-5" style="color:#212529 !important;">' + (totales?.count || tickets.length) + '</div></div></div>' +
        '<div class="col-6 col-md-3"><div class="text-center p-2 rounded" style="background-color:white !important;box-shadow:0 1px 3px rgba(0,0,0,0.1);"><div class="text-muted small">Total Ventas</div><div class="fw-bold fs-5" style="color:#28a745 !important;">$' + parseFloat(totales?.total || 0).toFixed(2) + '</div></div></div>' +
        '<div class="col-6 col-md-3"><div class="text-center p-2 rounded" style="background-color:white !important;box-shadow:0 1px 3px rgba(0,0,0,0.1);"><div class="text-muted small">Devoluciones</div><div class="fw-bold" style="color:#dc3545 !important;font-size:0.95rem;">' + (totales?.devoluciones || 0) + ' <small>(-$' + parseFloat(totales?.valor_devoluciones || 0).toFixed(2) + ')</small></div></div></div>' +
        '<div class="col-6 col-md-3"><div class="text-center p-2 rounded" style="background-color:white !important;box-shadow:0 1px 3px rgba(0,0,0,0.1);"><div class="text-muted small">Por Tipo Pago</div><div class="mt-1">' + (resumenPagosHtml || '<span class="text-muted small">-</span>') + '</div></div></div>' +
        '</div></div>';
    
    html += '<div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead style="background-color:#e9ecef !important;"><tr>' +
        '<th width="30"></th><th>Ticket</th><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Pago</th><th class="text-end">Total</th><th width="50" class="text-center">Dev</th></tr></thead><tbody>';
    
    tickets.forEach(t => {
        const tid = t.id;
        const items = detallesPorTicket[tid] || [];
        const isRefund = parseFloat(t.total) < 0;
        const ticketNum = String(tid).padStart(6, '0');
        const hora = t.fecha ? new Date(t.fecha).toLocaleTimeString('es', {hour: '2-digit', minute: '2-digit'}) : '--:--';
        
        // Determinar color de fondo según método de pago
        const metodoPago = (t.metodo_pago || '').toLowerCase();
        let rowStyle = '';
        let badgeStyle = 'background-color: #6c757d !important; color: white !important;';
        
        if (isRefund) {
            rowStyle = paymentColors['refund'];
        } else if (paymentColors[metodoPago]) {
            rowStyle = paymentColors[metodoPago];
        }
        
        if (badgeColors[metodoPago]) {
            badgeStyle = badgeColors[metodoPago];
        }
        
        html += '<tr style="cursor:pointer; ' + rowStyle + '" data-bs-toggle="collapse" data-bs-target="#hist-detail-' + tid + '">' +
            '<td class="text-center text-muted"><i class="fas fa-chevron-down fa-sm"></i></td>' +
            '<td class="fw-bold" style="color:#212529 !important;">#' + ticketNum + '</td>' +
            '<td>' + hora + '</td>' +
            '<td>' + escapeHtml(t.cliente_nombre || 'Cliente') + (isRefund ? ' <span class="badge" style="background-color:#dc3545 !important;color:white !important;font-size:0.65rem;">DEV</span>' : '') + '</td>' +
            '<td><span class="text-uppercase small">' + (t.tipo_servicio || 'N/A') + '</span></td>' +
            '<td><span class="badge" style="' + badgeStyle + 'font-size:0.7rem;">' + (t.metodo_pago || 'N/A') + '</span></td>' +
            '<td class="text-end fw-bold" style="' + (isRefund ? 'color:#dc3545 !important;' : 'color:#212529 !important;') + '">$' + parseFloat(t.total).toFixed(2) + '</td>' +
            '<td class="text-center">' + 
                (isRefund ? '<span class="text-muted">-</span>' : '<button class="btn btn-sm py-0 px-1" style="background-color:#dc3545 !important;color:white !important;border:none;" onclick="event.stopPropagation(); refundTicketComplete(' + tid + ')" title="Reembolsar ticket completo"><i class="fas fa-undo-alt fa-sm"></i></button>') +
            '</td></tr>';
        
        html += '<tr class="collapse" id="hist-detail-' + tid + '"><td colspan="8" class="p-0"><div class="p-2" style="background-color:#f1f3f4 !important;">' +
            '<table class="table table-sm mb-0" style="background-color:white !important;"><thead class="small" style="color:#6c757d !important;"><tr>' +
            '<th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th width="50" class="text-center">Dev</th></tr></thead><tbody>';
        
        if (items.length > 0) {
            items.forEach(item => {
                const subtotal = parseFloat(item.cantidad) * parseFloat(item.precio);
                const isNeg = parseFloat(item.cantidad) < 0;
                const itemId = item.id || item.id_detalle;
                
                html += '<tr>' +
                    '<td style="color:#212529 !important;">' + escapeHtml(item.nombre || item.id_producto) + '</td>' +
                    '<td class="text-end fw-bold" style="' + (isNeg ? 'color:#dc3545 !important;' : 'color:#212529 !important;') + '">' + parseFloat(item.cantidad) + '</td>' +
                    '<td class="text-end" style="color:#212529 !important;">$' + parseFloat(item.precio).toFixed(2) + '</td>' +
                    '<td class="text-end fw-bold" style="' + (isNeg ? 'color:#dc3545 !important;' : 'color:#212529 !important;') + '">$' + subtotal.toFixed(2) + '</td>' +
                    '<td class="text-center">';
                
                // Botón de reembolso individual solo si no es devolución y cantidad positiva
                if (!isRefund && !isNeg && itemId) {
                    html += '<button class="btn btn-sm py-0 px-1" style="background-color:#fd7e14 !important;color:white !important;border:none;" onclick="event.stopPropagation(); refundItemFromHistorial(' + itemId + ', \'' + escapeHtml(item.nombre || '').replace(/'/g, "\\'") + '\')" title="Devolver este producto"><i class="fas fa-undo fa-sm"></i></button>';
                } else {
                    html += '<span class="text-muted">-</span>';
                }
                
                html += '</td></tr>';
            });
        } else {
            html += '<tr><td colspan="5" class="text-center text-muted">Sin detalles</td></tr>';
        }
        
        html += '</tbody></table></div></td></tr>';
    });
    
    html += '</tbody></table></div>';
    body.innerHTML = html;
}

// Reembolsar ticket completo
window.refundTicketComplete = async function(ticketId) {
    if (!confirm('¿Generar DEVOLUCION COMPLETA del ticket #' + String(ticketId).padStart(6, '0') + '?\n\nEsto creara un ticket negativo con todos los productos.')) return;
    
    try {
        const resp = await fetch('pos_refund.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, tipo: 'completo' })
        });
        const res = await resp.json();
        
        if (res.status === 'success') {
            showToast('Devolucion completa generada - Ticket #' + res.id, 'success');
            Synth.refund();
            showHistorialModal(); // Recargar historial
        } else {
            showToast('Error: ' + (res.msg || res.message), 'error');
        }
    } catch (e) {
        showToast('Error de conexion', 'error');
    }
};

async function renderHistorialOffline(body) {
    let offlineSales = [];
    
    const legacyQueue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
    offlineSales = offlineSales.concat(legacyQueue.map((s, i) => ({...s, id: 'LS-' + i, source: 'localStorage'})));
    
    if (typeof window.posCache !== 'undefined' && typeof window.posCache.getPendingSales === 'function') {
        try {
            const idbSales = await window.posCache.getPendingSales();
            offlineSales = offlineSales.concat(idbSales.map(s => ({...s, source: 'IndexedDB'})));
        } catch(e) {}
    }
    
    if (offlineSales.length === 0) {
        body.innerHTML = '<div class="text-center p-4 text-warning"><i class="fas fa-wifi-slash fa-3x mb-3"></i><h5>Sin conexion</h5><p class="text-muted">No hay ventas pendientes offline.</p></div>';
        return;
    }
    
    let html = '<div class="bg-warning bg-opacity-25 p-2 text-center border-bottom"><i class="fas fa-wifi-slash me-2"></i><strong>Modo Offline</strong> - Ventas pendientes de sincronizar</div>';
    html += '<div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr>' +
        '<th>ID</th><th>Hora</th><th>Items</th><th>Cliente</th><th class="text-end">Total</th><th>Estado</th></tr></thead><tbody>';
    
    offlineSales.forEach(sale => {
        const hora = sale.timestamp ? new Date(sale.timestamp).toLocaleTimeString('es', {hour: '2-digit', minute: '2-digit'}) : '--:--';
        const itemCount = sale.items ? sale.items.length : 0;
        
        html += '<tr><td class="fw-bold">#' + (sale.id || 'N/A') + '</td>' +
            '<td>' + hora + '</td>' +
            '<td>' + itemCount + ' items</td>' +
            '<td>' + escapeHtml(sale.cliente_nombre || 'Cliente') + '</td>' +
            '<td class="text-end fw-bold">$' + parseFloat(sale.total || 0).toFixed(2) + '</td>' +
            '<td><span class="badge bg-warning">Pendiente</span></td></tr>';
    });
    
    html += '</tbody></table></div>';
    body.innerHTML = html;
}

window.refundItemFromHistorial = async function(itemId, itemName) {
    if (!confirm('Generar DEVOLUCION para: ' + itemName + '?')) return;
    
    try {
        const resp = await fetch('pos_refund.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: itemId })
        });
        const res = await resp.json();
        
        if (res.status === 'success') {
            showToast('Devolucion generada correctamente', 'success');
            showHistorialModal();
        } else {
            showToast('Error: ' + (res.msg || res.message), 'error');
        }
    } catch (e) {
        showToast('Error de conexion', 'error');
    }
};

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ==========================================
// FORZAR DESCARGA DEL SERVIDOR
// ==========================================
async function forceDownloadProducts() {
    const btn = document.getElementById('btnForceDownload');
    if (btn) { btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>'; btn.disabled = true; }
    
    try {
        if (currentCashier) {
            sessionStorage.setItem('pos_session_reload', JSON.stringify({
                cajero: currentCashier,
                cashOpen: cashOpen,
                cashId: cashId,
                timestamp: Date.now()
            }));
        }
        
        localStorage.removeItem(CACHE_KEY);
        
        if ('caches' in window) {
            const keys = await caches.keys();
            for (const key of keys) {
                await caches.delete(key);
            }
        }
        
        const resp = await fetch('pos.php?ping=1', { cache: 'no-store' });
        
        if (resp.ok) {
            location.reload(true);
        } else {
            throw new Error('Servidor no disponible');
        }
        
    } catch(e) {
        console.error('Error:', e);
        showToast('Error: ' + e.message, 'error');
        if (btn) { btn.innerHTML = '<i class="fas fa-cloud-download-alt"></i>'; btn.disabled = false; }
    }
}

// Restaurar sesion despues de reload
(function checkReloadSession() {
    const saved = sessionStorage.getItem('pos_session_reload');
    if (saved) {
        try {
            const session = JSON.parse(saved);
            if (Date.now() - session.timestamp < 30000) {
                currentCashier = session.cajero;
                cashOpen = session.cashOpen;
                cashId = session.cashId;
                document.addEventListener('DOMContentLoaded', () => {
                    const cashierName = document.getElementById('cashierName');
                    const pinOverlay = document.getElementById('pinOverlay');
                    if (cashierName) cashierName.innerText = currentCashier;
                    if (pinOverlay) pinOverlay.style.display = 'none';
                    checkCashStatusSilent();
                    showToast('Productos actualizados', 'success');
                });
            }
        } catch(e) {}
        sessionStorage.removeItem('pos_session_reload');
    }
})();

console.log('POS.js v3.2 cargado');




