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

// ── Roles y seguridad ─────────────────────────────────────────────────────────
let currentRole     = 'cajero';   // rol del usuario logueado
let pinAttempts     = 0;          // intentos fallidos acumulados
const PIN_MAX_ATTEMPTS = 3;       // máx antes de bloquear
const PIN_LOCKOUT_MS   = 30000;   // 30 segundos de bloqueo
let pinLockedUntil  = 0;          // timestamp de fin de bloqueo
let pinLockInterval = null;       // interval del countdown
let inactivityTimer = null;       // timer de auto-logout
const INACTIVITY_MS = 15 * 60 * 1000; // 15 minutos de inactividad

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
        renderFavoritesBar();
    } else {
        console.log('PRODUCTS_DATA vacio, cargando desde cache...');
        loadFromCacheOrRefresh();
    }
    
    updatePinDisplay();
    startPosClock();
    document.addEventListener('keydown', handleHotkeys);
    document.addEventListener('keydown', handleBarcodeScanner);
    document.body.addEventListener('click', () => { if(audioCtx.state === 'suspended') audioCtx.resume(); }, {once:true});

    // Eventos de actividad para reset del inactivity timer
    ['click', 'keydown', 'touchstart'].forEach(ev => {
        document.addEventListener(ev, resetInactivityTimer, { passive: true });
    });
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
// RELOJ DIGITAL
// ==========================================
function startPosClock() {
    let lastBeepMinute = -1;

    function tick() {
        const now = new Date();
        const hours24 = now.getHours();
        const h12 = hours24 % 12 || 12;
        const m = String(now.getMinutes()).padStart(2, '0');
        const ampm = hours24 < 12 ? 'AM' : 'PM';

        const elH    = document.querySelector('#posClock .clock-h');
        const elM    = document.querySelector('#posClock .clock-m');
        const elAmpm = document.querySelector('#posClock .clock-ampm');
        const clock  = document.getElementById('posClock');

        if (elH)    elH.textContent = String(h12).padStart(2, '0');
        if (elM)    elM.textContent = m;
        if (elAmpm) elAmpm.textContent = ampm;

        // Parpadeo del separador : sincronizado con los segundos
        if (clock) clock.classList.toggle('blink-colon', now.getSeconds() % 2 === 1);

        // Beeps en segundo 0 de cada minuto
        const totalMin = hours24 * 60 + now.getMinutes();
        if (now.getSeconds() === 0 && totalMin !== lastBeepMinute) {
            lastBeepMinute = totalMin;
            if (now.getMinutes() === 0) {
                // Hora en punto: ding-dong (Do6 → Sol5)
                Synth.playTone(1047, 'sine', 0.55, 0.18);
                setTimeout(() => Synth.playTone(784, 'sine', 0.55, 0.24), 480);
            } else if (now.getMinutes() === 30) {
                // Media hora: un beep suave (La5)
                Synth.playTone(880, 'sine', 0.45, 0.16);
            }
        }
    }

    tick();
    setInterval(tick, 1000);
}

// ==========================================
// HOTKEYS F1-F10 / Del / Enter
// ==========================================
function handleHotkeys(e) {
    const pinOverlay = document.getElementById('pinOverlay');
    const pinVisible = pinOverlay && pinOverlay.style.display !== 'none' && pinOverlay.style.display !== '';
    if (pinVisible) return;

    const tag = document.activeElement?.tagName;
    const inInput = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT');
    const modalOpen = !!document.querySelector('.modal.show');

    const map = {
        'F1':     () => checkCashRegister(),
        'F2':     () => applyGlobalDiscount(),
        'F3':     () => openSelfOrdersModal(),
        'F4':     () => parkOrder(),
        'F5':     () => { const s = document.getElementById('searchInput'); if (s) { s.focus(); s.select(); } },
        'F6':     () => showHistorialModal(),
        'F7':     () => openNewClientModal(),
        'F8':     () => forceDownloadProducts(),
        'F9':     () => applyDiscount(),
        'F10':    () => askQty(),
        'Delete': () => { if (!inInput) removeItem(); },
        'Enter':  () => { if (!inInput && !modalOpen && barcodeBuffer.length === 0) openPaymentModal(); }
    };

    if (!map[e.key]) return;
    if (e.key === 'Delete' && inInput) return;
    if (e.key === 'Enter' && (inInput || modalOpen || barcodeBuffer.length > 0)) return;

    e.preventDefault();
    map[e.key]();
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
    } else if(e.key && e.key.length === 1) {
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
    // Bloqueo activo: ignorar intentos
    if (Date.now() < pinLockedUntil) return;
    if (enteredPin.length < 4) return;

    let loginSuccess = false;
    let cajeroRol    = 'cajero';

    if (navigator.onLine) {
        try {
            const resp = await fetch('pos_cash.php?action=login', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ pin: enteredPin }),
                signal: AbortSignal.timeout(5000)
            });
            const data = await resp.json();
            if (data.status === 'success') {
                loginSuccess = true;
                currentCashier = data.cajero;
                cajeroRol = data.rol ?? 'cajero';

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
                currentCashier = cajero.nombre;
                cajeroRol = cajero.rol ?? 'cajero';
            }
        }

        if (!loginSuccess && typeof window.posCache !== 'undefined' && typeof window.posCache.verifyCajeroOffline === 'function') {
            try {
                const cajero = await window.posCache.verifyCajeroOffline(enteredPin);
                if (cajero) {
                    loginSuccess = true;
                    currentCashier = cajero.nombre;
                    cajeroRol = cajero.rol ?? 'cajero';
                }
            } catch(e) {}
        }
    }

    if (loginSuccess) {
        currentRole = cajeroRol;
        pinAttempts = 0;
        showPinAttemptDots(); // limpiar dots
        Synth.tada();
        applyRoleRestrictions();
        startInactivityTimer();
        unlockPos();
    } else {
        pinAttempts++;
        if (pinAttempts >= PIN_MAX_ATTEMPTS) {
            activatePinLockout();
        } else {
            showPinAttemptDots();
            showToast(`PIN incorrecto (${pinAttempts}/${PIN_MAX_ATTEMPTS})`, 'error');
        }
        enteredPin = "";
        updatePinDisplay();
    }
}

// ── PIN Lockout ───────────────────────────────────────────────────────────────
function activatePinLockout() {
    pinLockedUntil = Date.now() + PIN_LOCKOUT_MS;
    pinAttempts = 0;
    enteredPin = "";
    updatePinDisplay();

    // Deshabilitar todos los botones del teclado PIN
    const grid = document.getElementById('pinGrid');
    if (grid) grid.querySelectorAll('button').forEach(b => b.disabled = true);

    const lockMsg = document.getElementById('pinLockMsg');
    const dotsEl  = document.getElementById('pinAttemptsDots');
    if (dotsEl) dotsEl.textContent = '';

    function updateCountdown() {
        const remaining = Math.ceil((pinLockedUntil - Date.now()) / 1000);
        if (remaining <= 0) {
            clearInterval(pinLockInterval);
            pinLockInterval = null;
            pinLockedUntil = 0;
            if (lockMsg) { lockMsg.textContent = ''; lockMsg.classList.add('d-none'); }
            if (grid) grid.querySelectorAll('button').forEach(b => b.disabled = false);
        } else {
            if (lockMsg) {
                lockMsg.classList.remove('d-none');
                lockMsg.textContent = `🔒 Bloqueado por ${remaining} segundo${remaining !== 1 ? 's' : ''}`;
            }
        }
    }
    updateCountdown();
    pinLockInterval = setInterval(updateCountdown, 1000);
}

function showPinAttemptDots() {
    const dotsEl = document.getElementById('pinAttemptsDots');
    if (!dotsEl) return;
    if (pinAttempts === 0) { dotsEl.textContent = ''; return; }
    const dots = [];
    for (let i = 0; i < PIN_MAX_ATTEMPTS; i++) {
        dots.push(i < pinAttempts ? '🔴' : '⚪');
    }
    dotsEl.textContent = dots.join(' ');
}

function unlockPos() {
    const overlay = document.getElementById('pinOverlay');
    const cashierName = document.getElementById('cashierName');
    if (overlay) overlay.style.display = 'none';
    if (cashierName) cashierName.innerText = currentCashier;
    checkCashStatusSilent();

    // Restaurar carrito guardado antes del auto-logout
    const lockedCart = sessionStorage.getItem('pos_cart_locked');
    if (lockedCart) {
        try {
            const saved = JSON.parse(lockedCart);
            if (saved.cart && saved.cart.length > 0) {
                cart = saved.cart;
                globalDiscountPct = saved.globalDiscountPct ?? 0;
                renderCart();
                showToast('Carrito restaurado (' + cart.length + ' items)', 'warning');
            }
        } catch(e) {}
        sessionStorage.removeItem('pos_cart_locked');
    }
}

// ── Restricciones de rol ─────────────────────────────────────────────────────
function applyRoleRestrictions() {
    const isCajero = currentRole === 'cajero';
    const btnDiscount = document.getElementById('btnGlobalDiscount');
    const btnCaja     = document.getElementById('btnCaja');
    if (btnDiscount) btnDiscount.classList.toggle('d-none', isCajero);
    if (btnCaja)     btnCaja.classList.toggle('d-none', isCajero);
    // Botón de inventario: solo admin
    const btnInv = document.getElementById('btnInventario');
    if (btnInv) btnInv.style.display = (currentRole === 'admin') ? '' : 'none';
    // Si no es admin y el panel inventario está abierto, volver al panel normal
    if (isCajero && invModeActive) {
        invModeActive = false;
        const posPanel = document.getElementById('posPanel');
        const invPanel = document.getElementById('inventarioPanel');
        if (posPanel) posPanel.style.display = '';
        if (invPanel) invPanel.style.display = 'none';
        const btnInv = document.getElementById('btnInventario');
        if (btnInv) btnInv.classList.remove('inv-active');
    }
}

// ── Inactividad / Auto-logout ─────────────────────────────────────────────────
function startInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(lockPos, INACTIVITY_MS);
}

function resetInactivityTimer() {
    const overlay = document.getElementById('pinOverlay');
    // Solo resetear si el overlay NO está visible (usuario activo en el POS)
    if (overlay && overlay.style.display !== 'none' && overlay.style.display !== '') return;
    startInactivityTimer();
}

function lockPos() {
    clearTimeout(inactivityTimer);
    // Preservar carrito en sessionStorage antes de bloquear
    if (cart && cart.length > 0) {
        sessionStorage.setItem('pos_cart_locked', JSON.stringify({ cart, globalDiscountPct }));
    }
    currentCashier = 'Cajero';
    currentRole    = 'cajero';
    enteredPin     = '';
    updatePinDisplay();
    showPinAttemptDots();
    const overlay = document.getElementById('pinOverlay');
    if (overlay) overlay.style.display = 'flex';
    // Re-aplicar restricciones (ocultar botones de supervisor/admin)
    applyRoleRestrictions();
    showToast('Sesión cerrada por inactividad', 'warning');
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

window.toggleBars = function() {
    const hidden = document.body.classList.toggle('pos-bars-hidden');
    const btn = document.getElementById('btnToggleBars');
    if (btn) btn.classList.toggle('btn-filter-active', hidden);
    localStorage.setItem('posBarsHidden', hidden ? '1' : '0');
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
        // Agregamos ID único al card para facilitar actualizaciones futuras si se necesita
        card.id = `card-product-${p.codigo}`;
        
        if (!hasStock) card.classList.add('stock-zero-card');
        
        let badgeClass = hasStock ? 'stock-ok' : 'stock-zero';
        let stockDisplay = p.es_servicio == 1 ? '∞' : stock;
        
        let imgHTML = '<div class="product-img-container" style="background:' + (p.color || '#ccc') + '"><span class="placeholder-text">' + (p.nombre || '??').substring(0,2).toUpperCase() + '</span></div>';
        if(p.has_image) {
            const imgV = p.img_version ? '&v=' + p.img_version : '';
            imgHTML = '<div class="product-img-container"><img src="image.php?code=' + p.codigo + imgV + '" class="product-img" onerror="this.style.display=\'none\'"></div>';
        }

        card.innerHTML = 
            // ID ÚNICO PARA EL BADGE
            '<div id="stock-badge-' + p.codigo + '" class="stock-badge ' + badgeClass + '">' + stockDisplay + '</div>' +
            imgHTML +
            '<div class="product-info">' +
                '<div class="product-name text-dark">' + (p.nombre || 'Sin nombre') + '</div>' +
                '<div class="product-price">$' + parseFloat(p.precio || 0).toFixed(2) + '</div>' +
            '</div>';
        
        if (hasStock) {
            card.onclick = () => addToCart(p);
            card.style.cursor = 'pointer';
        } else {
            card.onclick = () => { Synth.error(); showToast('Sin Stock', 'error'); }; // Feedback visual click en agotado
            card.style.cursor = 'not-allowed';
        }

        // Botón estrella ★ para favoritos
        const starBtn = document.createElement('button');
        starBtn.className = 'star-btn ' + (p.favorito == 1 ? 'active' : 'inactive');
        starBtn.id = 'star-' + p.codigo;
        starBtn.textContent = p.favorito == 1 ? '★' : '☆';
        starBtn.title = p.favorito == 1 ? 'Quitar de favoritos' : 'Agregar a favoritos';
        starBtn.onclick = (e) => toggleFavorite(p.codigo, e);
        card.appendChild(starBtn);

        grid.appendChild(card);
    });
    // Sincronizar badges inmediatamente
    updateStockBadges();
    renderFavoritesBar();
}

// ==========================================
// BARRA DE FAVORITOS
// ==========================================
function renderFavoritesBar() {
    const bar = document.getElementById('favoritesBar');
    const row = document.getElementById('favCardsRow');
    const label = document.getElementById('favBarLabel');
    if (!bar || !row || !label) return;

    const db = window.productsDB || [];
    let items = db.filter(p => p.favorito == 1);
    let isMostSold = false;

    if (items.length === 0) {
        // Fallback: mostrar más vendidos
        const codes = (typeof MOST_SOLD_CODES !== 'undefined') ? MOST_SOLD_CODES : [];
        if (codes.length === 0) { bar.style.display = 'none'; return; }
        items = codes.map(c => db.find(p => p.codigo == c)).filter(Boolean);
        if (items.length === 0) { bar.style.display = 'none'; return; }
        isMostSold = true;
    }

    label.innerHTML = isMostSold
        ? '<i class="fas fa-fire-alt"></i> MÁS VENDIDOS'
        : '<i class="fas fa-star"></i> FAVORITOS';
    label.className = isMostSold ? 'text-danger fw-bold' : 'text-warning fw-bold';
    label.style.cssText = 'font-size:0.72rem; white-space:nowrap; flex-shrink:0;';

    row.innerHTML = '';
    items.forEach(p => {
        const stock = parseFloat(p.stock) || 0;
        const hasStock = stock > 0 || p.es_servicio == 1;
        const fc = document.createElement('div');
        fc.className = 'fav-card' + (hasStock ? '' : ' out-of-stock');
        fc.innerHTML = '<div class="fav-name">' + (p.nombre || '') + '</div>' +
                       '<div class="fav-price">$' + parseFloat(p.precio || 0).toFixed(2) + '</div>';
        fc.onclick = () => { if (hasStock) addToCart(p); else showToast('Sin Stock', 'error'); };
        row.appendChild(fc);
    });

    bar.style.display = '';
}

function toggleFavorite(codigo, event) {
    event.stopPropagation();
    fetch('pos.php?toggle_fav=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo: codigo })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            // Actualizar en memoria
            const p = (window.productsDB || []).find(x => x.codigo == codigo);
            if (p) p.favorito = res.favorito;
            // Actualizar botón visual
            const btn = document.getElementById('star-' + codigo);
            if (btn) {
                btn.className = 'star-btn ' + (res.favorito == 1 ? 'active' : 'inactive');
                btn.textContent = res.favorito == 1 ? '★' : '☆';
                btn.title = res.favorito == 1 ? 'Quitar de favoritos' : 'Agregar a favoritos';
            }
            renderFavoritesBar();
        }
    })
    .catch(e => console.error('Error toggling favorito:', e));
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
    // Verificacion de stock considerando lo que ya esta en carrito
    const idx = cart.findIndex(i => i.id === p.codigo && (!i.note)); 
    const currentQtyInCart = (idx >= 0) ? cart[idx].qty : 0;
    const stockAvailable = parseFloat(p.stock) || 0;

    if(p.es_servicio == 0 && (currentQtyInCart + 1) > stockAvailable) {
        return showToast("Stock insuficiente (" + stockAvailable + " disp)", "error");
    }

    if(idx >= 0) { 
        cart[idx].qty++; 
        selectedIndex = idx; 
    } else { 
        cart.push({ id: p.codigo, name: p.nombre, price: parseFloat(p.precio), qty: 1, discountPct: 0, note: '' }); 
        selectedIndex = cart.length - 1; 
    }
    Synth.addCart(); 
    renderCart();
    saveCartState();
    updateStockBadges(); // Actualizar visualmente
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
    
    // AUTO-SCROLL
    setTimeout(() => { c.scrollTop = c.scrollHeight; }, 50);

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

// NUEVA FUNCIÓN: Actualizar badges de stock en tiempo real
window.updateStockBadges = function() {
    // Mapa de cantidades en carrito
    const cartQtys = {};
    cart.forEach(item => {
        cartQtys[item.id] = (cartQtys[item.id] || 0) + item.qty;
    });

    // Iterar productos visibles (o todos si fuera necesario, pero mejor iterar DOM visible o DB)
    // Iteramos productsDB para asegurar consistencia
    if (window.productsDB) {
        window.productsDB.forEach(p => {
            const inCart = cartQtys[p.codigo] || 0;
            const stockReal = parseFloat(p.stock) || 0;
            let stockVisual = p.es_servicio == 1 ? '∞' : (stockReal - inCart);
            
            // Buscar el badge
            const badge = document.getElementById('stock-badge-' + p.codigo);
            if (badge) {
                // Actualizar texto
                badge.innerText = stockVisual;
                
                // Actualizar clases y estado del card
                const card = badge.closest('.product-card');
                
                if (stockVisual <= 0 && p.es_servicio == 0) {
                    badge.className = 'stock-badge stock-zero';
                    if (card) {
                         card.classList.add('stock-zero-card');
                         card.style.opacity = '0.6';
                         card.onclick = () => { Synth.error(); showToast('Sin Stock', 'error'); };
                         card.style.cursor = 'not-allowed';
                    }
                } else {
                    badge.className = 'stock-badge stock-ok';
                    if (card) {
                        card.classList.remove('stock-zero-card');
                        card.style.opacity = '1';
                        card.onclick = () => addToCart(p);
                        card.style.cursor = 'pointer';
                    }
                }
            }
        });
    }
};

function modifyQty(d) { 
    if(selectedIndex < 0) return; 
    const item = cart[selectedIndex];
    const prod = productsDB.find(x => x.codigo == item.id);
    
    // Verificacion de stock al incrementar
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
    updateStockBadges(); // Actualizar visualmente
}

function removeItem() { 
    if(selectedIndex >= 0 && confirm('Eliminar producto?')) { 
        Synth.removeCart(); 
        cart.splice(selectedIndex, 1); 
        selectedIndex = -1; 
        renderCart();
        saveCartState();
        updateStockBadges(); // Restaurar stock visual
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
        updateStockBadges(); // Restaurar todo el stock visual
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

    // Descuentos para audit trail inmutable
    const descuentosItems = cart
        .filter(i => i.discountPct > 0)
        .map(i => ({
            codigo:          i.id,
            nombre:          i.name,
            precio_original: i.price,
            descuento_pct:   i.discountPct,
            precio_final:    +(i.price * (1 - i.discountPct / 100)).toFixed(2),
        }));

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
        timestamp: Date.now(),
        canal_origen: 'POS',
        estado_pago: 'confirmado',
        descuentos_items:  descuentosItems,
        descuento_global:  globalDiscountPct
    };

    // Guardar información para la pantalla del cliente (Vuelto)
    if(met === 'Efectivo') {
        const cashReceived = parseFloat(document.getElementById('cashReceived')?.value || 0);
        if(cashReceived > tot) {
            localStorage.setItem('pos_last_payment', JSON.stringify({
                method: 'Efectivo',
                total: tot,
                received: cashReceived,
                change: cashReceived - tot,
                timestamp: Date.now()
            }));
        }
    }

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
// HISTORIAL DE TICKETS (RECTIFICADO: LLAMA A MODAL_HISTORY.PHP)
// ==========================================
window.showHistorialModal = async function() {
    let modalEl = document.getElementById('historialModal');
    
    // Si el modal no existe en el DOM, intentar cargarlo o alertar
    if (!modalEl) {
        // En pos.php el modal suele estar al final, si no, lo creamos
        console.error('Modal historialModal no encontrado en el DOM');
        return;
    }
    
    const bsModal = new bootstrap.Modal(modalEl);
    const body = document.getElementById('historialModalBody');
    
    body.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Cargando historial...</p></div>';
    bsModal.show();
    
    try {
        // LLAMADA AL ARCHIVO EXTERNO PARA UNIFICAR CRITERIOS
        const response = await fetch('modal_history.php?render_mode=1&t=' + Date.now());
        const html = await response.text();
        body.innerHTML = html;
    } catch (error) {
        console.error('Error cargando historial:', error);
        body.innerHTML = '<div class="p-5 text-center text-danger"><i class="fas fa-wifi fa-2x mb-2"></i><br>Error de conexión al cargar historial.</div>';
    }
};

// --- FUNCIONES GLOBALES PARA EL HISTORIAL (AJAX COMPATIBLE) ---

window.toggleDetail = function(id) {
    const row = document.getElementById(`det-row-${id}`);
    const icon = document.querySelector(`.icon-collapse-${id}`);
    if(!row) return;
    
    if (row.classList.contains('show')) {
        row.classList.remove('show');
        if(icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-right'); }
    } else {
        // Cerrar otros abiertos para mantener orden
        document.querySelectorAll('.collapse.show').forEach(el => el.classList.remove('show'));
        document.querySelectorAll('.fa-chevron-down').forEach(el => { el.classList.remove('fa-chevron-down'); el.classList.add('fa-chevron-right'); });
        
        row.classList.add('show');
        if(icon) { icon.classList.remove('fa-chevron-right'); icon.classList.add('fa-chevron-down'); }
    }
};

window.refundTicketComplete = async function(ticketId) {
    if(!confirm(`¿DEVOLVER TICKET #${ticketId} COMPLETO?`)) return;
    try {
        const r = await fetch('pos_refund.php', { 
            method: 'POST', 
            headers: {'Content-Type': 'application/json'}, 
            body: JSON.stringify({ ticket_id: ticketId }) 
        });
        const d = await r.json();
        if(d.status === 'success') { 
            alert('Éxito: Ticket devuelto.'); 
            if(typeof Synth !== 'undefined') Synth.refund();
            showHistorialModal(); // Recargar
        } else { 
            alert('Error: ' + d.msg); 
        }
    } catch(e) { 
        alert('Error de red al intentar devolver'); 
    }
};

window.refundItemFromHistorial = async function(detailId, prodName) {
    if(!confirm(`¿Devolver lote de: ${prodName}?`)) return;
    try {
        const r = await fetch('pos_refund.php', { 
            method: 'POST', 
            headers: {'Content-Type': 'application/json'}, 
            body: JSON.stringify({ id: detailId }) 
        });
        const d = await r.json();
        if(d.status === 'success') { 
            alert('Producto devuelto'); 
            showHistorialModal(); 
        } else { 
            alert(d.msg); 
        }
    } catch(e) { 
        alert('Error red'); 
    }
};

// ─── ANULACIÓN DE VENTA (Void) ─────────────────────────────────────────────────
// Diferente a la devolución: PIN-based, solo sesión activa, requiere motivo.

window.voidTicket = function(ticketId) {
    const motEl = document.getElementById('voidMotivo');
    const pinEl = document.getElementById('voidPin');
    const idEl  = document.getElementById('voidTicketId');
    if (!motEl || !pinEl || !idEl) {
        return alert('Modal de anulación no disponible en esta página');
    }
    idEl.textContent = ticketId;
    motEl.value = '';
    pinEl.value = '';
    window._voidTicketId = ticketId;
    const voidModalEl = document.getElementById('voidModal');
    if (voidModalEl) new bootstrap.Modal(voidModalEl).show();
};

window.confirmVoid = async function() {
    const motivo = (document.getElementById('voidMotivo')?.value || '').trim();
    const pin    = (document.getElementById('voidPin')?.value    || '').trim();

    if (motivo.length < 5) return alert('El motivo debe tener al menos 5 caracteres');
    if (!pin)              return alert('Ingrese su PIN de cajero para autorizar');

    const btn = document.querySelector('#voidModal .btn-void-confirm');
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Anulando...'; }

    try {
        const r = await fetch('pos_void.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_venta: window._voidTicketId, motivo, pin }),
        });
        const d = await r.json();
        if (d.status === 'success') {
            const voidModalInst = bootstrap.Modal.getInstance(document.getElementById('voidModal'));
            if (voidModalInst) voidModalInst.hide();
            showToast('Venta anulada — ' + d.msg, 'success');
            if (typeof Synth !== 'undefined') Synth.refund();
            showHistorialModal();
        } else {
            alert('Error: ' + d.msg);
            if (btn) { btn.disabled = false; btn.innerHTML = orig; }
        }
    } catch (e) {
        alert('Error de red al intentar anular');
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
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
                rol: currentRole,
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
                currentRole    = session.rol ?? 'cajero';
                cashOpen = session.cashOpen;
                cashId = session.cashId;
                document.addEventListener('DOMContentLoaded', () => {
                    const cashierName = document.getElementById('cashierName');
                    const pinOverlay = document.getElementById('pinOverlay');
                    if (cashierName) cashierName.innerText = currentCashier;
                    if (pinOverlay) pinOverlay.style.display = 'none';
                    applyRoleRestrictions();
                    startInactivityTimer();
                    checkCashStatusSilent();
                    showToast('Productos actualizados', 'success');
                });
            }
        } catch(e) {}
        sessionStorage.removeItem('pos_session_reload');
    }
})();

// ==========================================
// SCROLL HORIZONTAL CON DRAG GENÉRICO
// ==========================================
function initHorizontalScroll(selector) {
    const element = document.querySelector(selector);
    if (!element) return;

    let isDragging = false;
    let startPos = 0;
    let scrollLeft = 0;

    element.addEventListener('mousedown', (e) => {
        isDragging = true;
        element.classList.add('dragging');
        startPos = e.pageX - element.offsetLeft;
        scrollLeft = element.scrollLeft;
    });

    element.addEventListener('mouseleave', () => {
        isDragging = false;
        element.classList.remove('dragging');
    });

    element.addEventListener('mouseup', () => {
        isDragging = false;
        element.classList.remove('dragging');
    });

    element.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        e.preventDefault();
        const x = e.pageX - element.offsetLeft;
        const walk = (x - startPos) * 1.5; // Ajusta la velocidad del scroll
        element.scrollLeft = scrollLeft - walk;
    });

    // Evitar que el drag de la barra interfiera con el arrastre de texto
    element.ondragstart = () => false;
}

// Inicializar el scroll por drag en las categorías y favoritos al cargar el DOM
document.addEventListener('DOMContentLoaded', () => {
    initHorizontalScroll('.category-bar');
    initHorizontalScroll('#favCardsRow');
});

// Restaurar estado de barras al cargar
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('posBarsHidden') === '1') {
        document.body.classList.add('pos-bars-hidden');
        const btn = document.getElementById('btnToggleBars');
        if (btn) btn.classList.add('btn-filter-active');
    }
});

// ── MÓDULO INVENTARIO POS ────────────────────────────────────────────────────
const INV_CONFIG = {
    entrada:      { title: 'Recepción de Mercancía',   icon: 'fa-truck-loading', color: 'success',   qtyLabel: 'Cantidad recibida' },
    ajuste:       { title: 'Ajuste de Conteo',         icon: 'fa-sliders-h',     color: 'warning',   qtyLabel: 'Cantidad a ajustar' },
    conteo:       { title: 'Conteo Físico',             icon: 'fa-barcode',       color: 'info',      qtyLabel: 'Cantidad contada (total real)' },
    merma:        { title: 'Registrar Merma',           icon: 'fa-trash-alt',     color: 'danger',    qtyLabel: 'Cantidad a dar de baja' },
    transferencia:{ title: 'Transferencia a Sucursal', icon: 'fa-exchange-alt',  color: 'primary',   qtyLabel: 'Cantidad a transferir' },
    consultar:    { title: 'Consultar Existencias',    icon: 'fa-search',         color: 'secondary', qtyLabel: '' },
};

let invModeActive = false;

window.toggleInventarioMode = function() {
    invModeActive = !invModeActive;
    const posPanel = document.getElementById('posPanel');
    const invPanel = document.getElementById('inventarioPanel');
    const btnInv   = document.getElementById('btnInventario');
    if (posPanel) posPanel.style.display = invModeActive ? 'none' : '';
    if (invPanel) invPanel.style.display = invModeActive ? ''     : 'none';
    if (btnInv)   btnInv.classList.toggle('inv-active', invModeActive);
};

window.openInvModal = function(tipo) {
    window.currentInvTipo = tipo;
    window.currentInvProd = null;
    const cfg = INV_CONFIG[tipo];
    document.getElementById('invModalTitle').innerHTML =
        `<i class="fas ${cfg.icon} me-2 text-${cfg.color}"></i>${cfg.title}`;
    document.getElementById('invSkuInput').value        = '';
    document.getElementById('invProductInfo').innerHTML = '';
    document.getElementById('invSuggestions').style.display = 'none';
    document.getElementById('invQtyInput').value        = '';
    document.getElementById('invQtyLabel').textContent  = cfg.qtyLabel;
    document.getElementById('invMotivoInput').value     = '';
    document.getElementById('invCostoInput').value      = '';
    const isConsultar = tipo === 'consultar';
    document.getElementById('invCostoRow').style.display          = tipo === 'entrada'       ? '' : 'none';
    document.getElementById('invAjusteRow').style.display         = tipo === 'ajuste'        ? '' : 'none';
    document.getElementById('invTransferenciaRow').style.display  = tipo === 'transferencia' ? '' : 'none';
    document.getElementById('invQtyRow').style.display            = isConsultar ? 'none' : '';
    document.getElementById('invMotivoRow').style.display         = isConsultar ? 'none' : '';
    document.getElementById('invConsultarInfo').innerHTML         = '';
    document.getElementById('invConsultarInfo').style.display     = 'none';
    document.getElementById('btnInvConfirmar').style.display      = isConsultar ? 'none' : '';
    const signoPos = document.getElementById('signoPos');
    if (signoPos) signoPos.checked = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('invModal')).show();
    setTimeout(() => document.getElementById('invSkuInput').focus(), 350);
};

window.invBuscarProducto = function(e) {
    if (e && e.type === 'keyup' && e.key !== 'Enter') {
        const term = document.getElementById('invSkuInput').value.trim().toLowerCase();
        const sug  = document.getElementById('invSuggestions');
        if (term.length < 2) { sug.style.display = 'none'; return; }
        const matches = (productsDB || []).filter(p =>
            p.codigo.toLowerCase().includes(term) || p.nombre.toLowerCase().includes(term)
        ).slice(0, 8);
        if (!matches.length) { sug.style.display = 'none'; return; }
        sug.innerHTML = matches.map(p =>
            `<button type="button" class="list-group-item list-group-item-action py-1 small"
                     onclick="invSeleccionarProd('${p.codigo}')">
                <strong class="me-2">${p.codigo}</strong>${p.nombre}
                <span class="float-end text-muted">Stock: ${p.stock}</span>
             </button>`
        ).join('');
        sug.style.display = '';
        return;
    }
    const term = document.getElementById('invSkuInput').value.trim();
    document.getElementById('invSuggestions').style.display = 'none';
    if (!term) return;
    const prod = (productsDB || []).find(p => p.codigo.toLowerCase() === term.toLowerCase())
              || (productsDB || []).find(p => p.nombre.toLowerCase() === term.toLowerCase())
              || (productsDB || []).find(p => p.codigo.toLowerCase().includes(term.toLowerCase())
                                          || p.nombre.toLowerCase().includes(term.toLowerCase()));
    if (prod) invSeleccionarProd(prod.codigo);
    else document.getElementById('invProductInfo').innerHTML =
        '<div class="alert alert-warning py-2 small mb-0">Producto no encontrado</div>';
};

window.invSeleccionarProd = function(codigo) {
    const prod = (productsDB || []).find(p => p.codigo == codigo);
    if (!prod) return;
    window.currentInvProd = prod;
    document.getElementById('invSkuInput').value = prod.codigo;
    document.getElementById('invSuggestions').style.display = 'none';
    document.getElementById('invProductInfo').innerHTML =
        `<div class="alert alert-success py-2 small mb-0">
            <strong>${prod.nombre}</strong><br>
            <span class="me-3">Stock actual: <strong>${prod.stock}</strong></span>
            <span>Costo: <strong>$${prod.costo || 0}</strong></span>
         </div>`;
    document.getElementById('invCostoInput').value = prod.costo || '';
    if (window.currentInvTipo === 'consultar') {
        invConsultarStock(prod.codigo);
    } else {
        document.getElementById('invQtyInput').focus();
    }
};

window.invConsultarStock = async function(sku) {
    const infoEl = document.getElementById('invConsultarInfo');
    infoEl.style.display = '';
    infoEl.innerHTML = '<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Consultando...</div>';
    try {
        const r = await fetch('pos.php?inventario_api=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'consultar', sku }),
        });
        const d = await r.json();
        if (d.status === 'success') {
            const rows = (d.kardex || []).map(m =>
                `<tr><td class="small">${m.fecha}</td><td class="small">${m.tipo_movimiento}</td>
                 <td class="small text-end">${m.cantidad > 0 ? '+' : ''}${m.cantidad}</td>
                 <td class="small text-muted">${m.referencia || ''}</td></tr>`
            ).join('');
            infoEl.innerHTML = `
                <div class="alert alert-info py-2 mb-2 small">
                    <i class="fas fa-warehouse me-1"></i>
                    Stock en almacén: <strong class="fs-5">${d.stock}</strong>
                </div>
                ${rows ? `<div class="small fw-bold text-secondary mb-1">Últimos movimientos:</div>
                <table class="table table-sm table-striped mb-0 small"><tbody>${rows}</tbody></table>` : ''}`;
        } else {
            infoEl.innerHTML = `<div class="alert alert-danger py-2 small">${d.msg}</div>`;
        }
    } catch (e) {
        infoEl.innerHTML = '<div class="alert alert-danger py-2 small">Error de conexión</div>';
    }
};

window.invConfirmar = async function() {
    if (!window.currentInvProd) return showToast('Seleccione un producto', 'warning');
    const qty    = parseFloat(document.getElementById('invQtyInput').value);
    const motivo = document.getElementById('invMotivoInput').value.trim();
    if (!qty || qty <= 0) return showToast('Ingrese una cantidad válida', 'warning');
    if (!motivo)          return showToast('El motivo es obligatorio', 'warning');

    let finalQty = qty;
    if (window.currentInvTipo === 'ajuste') {
        const signo = document.querySelector('input[name="ajusteSigno"]:checked')?.value;
        if (signo === 'neg') finalQty = -Math.abs(qty);
    }

    const payload = {
        accion:      window.currentInvTipo,
        sku:         window.currentInvProd.codigo,
        cantidad:    finalQty,
        motivo:      motivo,
        usuario:     currentCashier || 'POS-Admin',
        costo_nuevo: parseFloat(document.getElementById('invCostoInput').value) || undefined,
        destino:     (document.getElementById('invDestinoInput')?.value || '').trim() || undefined,
    };

    const btn = document.getElementById('btnInvConfirmar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Procesando...';

    try {
        const r = await fetch('pos.php?inventario_api=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.status === 'success') {
            showToast(d.msg, 'success');
            bootstrap.Modal.getInstance(document.getElementById('invModal')).hide();
            const p = productsDB.find(x => x.codigo == window.currentInvProd.codigo);
            if (p) {
                const tipo = window.currentInvTipo;
                if      (tipo === 'entrada')      p.stock = parseFloat(p.stock) + Math.abs(finalQty);
                else if (tipo === 'ajuste')       p.stock = parseFloat(p.stock) + finalQty;
                else if (tipo === 'conteo')       p.stock = qty;
                else if (tipo === 'merma')        p.stock = Math.max(0, parseFloat(p.stock) - Math.abs(finalQty));
                else if (tipo === 'transferencia')p.stock = Math.max(0, parseFloat(p.stock) - Math.abs(finalQty));
                renderProducts();
            }
        } else {
            showToast(d.msg || 'Error desconocido', 'error');
        }
    } catch (e) {
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Confirmar';
    }
};

console.log('POS.js v3.4 cargado');
