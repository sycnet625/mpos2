// ARCHIVO: /var/www/palweb/api/pos.js
// VERSION: 3.2 - Sistema Offline Completo

const POS_STORAGE_SCOPE = window.POS_STORAGE_SCOPE || 'e1_s1_a1_c0';
const CACHE_KEY = 'products_cache_v2_' + POS_STORAGE_SCOPE;
const QUEUE_KEY = 'pos_pending_sales_' + POS_STORAGE_SCOPE;
const CACHE_DURATION = 5 * 60 * 1000;
const PARKED_ORDERS_KEY = 'pos_parked_orders_' + POS_STORAGE_SCOPE;
const CART_STATE_KEY = 'pos_cart_state_' + POS_STORAGE_SCOPE;
const LAST_PAYMENT_KEY = 'pos_last_payment_' + POS_STORAGE_SCOPE;
const CATALOG_SYNC_KEY = 'pos_catalog_sync_' + POS_STORAGE_SCOPE;
let offlineSyncInProgress = false;

function readStorageArray(key) {
    try {
        const value = JSON.parse(localStorage.getItem(key) || '[]');
        return Array.isArray(value) ? value : [];
    } catch (e) {
        return [];
    }
}

function getPosJsonHeaders(extra = {}) {
    if (typeof window.posJsonHeaders === 'function') {
        return window.posJsonHeaders(extra);
    }
    return Object.assign({ 'Content-Type': 'application/json' }, extra || {});
}

async function ensurePosCsrfToken(forceRefresh = false) {
    if (!navigator.onLine) {
        return !!window.POS_CSRF_TOKEN_VALUE;
    }

    if (!forceRefresh && typeof window.POS_CSRF_TOKEN_VALUE === 'string' && window.POS_CSRF_TOKEN_VALUE.length >= 32) {
        return true;
    }

    try {
        const resp = await fetch('pos_cash.php?action=csrf', {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            signal: typeof window.posFetchTimeoutSignal === 'function'
                ? window.posFetchTimeoutSignal(10000)
                : undefined
        });
        const data = await resp.json();
        if (data && data.status === 'success' && typeof data.csrf_token === 'string' && data.csrf_token.length >= 32) {
            window.POS_CSRF_TOKEN_VALUE = data.csrf_token;
            return true;
        }
    } catch (e) {}

    return false;
}

function notifyOvernightCashSession(response) {
    const session = response?.aviso_sesion_dia_siguiente;
    if (!session || !session.fecha_hoy) return false;

    const empresaId = Number(session.id_empresa || 0);
    const sucursalId = Number(session.id_sucursal || 0);
    const key = `pos_overnight_cash_info_${empresaId}_${sucursalId}_${session.fecha_hoy}`;
    try {
        if (localStorage.getItem(key)) return false;
        localStorage.setItem(key, '1');
    } catch (e) {
        window.__posOvernightCashWarnings = window.__posOvernightCashWarnings || new Set();
        if (window.__posOvernightCashWarnings.has(key)) return false;
        window.__posOvernightCashWarnings.add(key);
    }

    const sessionId = Number(session.id || 0);
    const fechaApertura = String(session.fecha_apertura || '').slice(0, 10) || 'un día anterior';
    showToast(
        `La sesión #${sessionId || '—'} sigue abierta desde ${fechaApertura}. Venta #${Number(response.id || 0)} registrada.`,
        'warning'
    );
    return true;
}

// ==========================================
// MODO DE PRECIO
// ==========================================
// 'sucursal'      → precio venta normal de la sucursal, con fallback a global
// 'mayorista_suc' → precio mayorista de la sucursal, con fallback
// 'custom_pct'    → porcentaje manual aplicado al precio base normal
let priceMode = localStorage.getItem('pos_price_mode') || 'sucursal';
let priceAdjustPct = parseFloat(localStorage.getItem('pos_price_adjust_pct') || '0');
if (!Number.isFinite(priceAdjustPct)) priceAdjustPct = 0;

function getBaseSalePrice(p) {
    return parseFloat(p.precio_suc || p.precio_global || p.precio || 0);
}

function getWholesalePrice(p) {
    return parseFloat(p.precio_mayorista_suc || p.precio_suc || p.precio_global || p.precio || 0);
}

function getPrice(p) {
    if (priceMode === 'mayorista_suc') return getWholesalePrice(p);
    if (priceMode === 'custom_pct') {
        const base = getBaseSalePrice(p);
        return +(base * (1 + (priceAdjustPct / 100))).toFixed(2);
    }
    return getBaseSalePrice(p);
}

function getPriceModeLabel() {
    if (priceMode === 'mayorista_suc') return 'Mayor.';
    if (priceMode === 'custom_pct') {
        const pct = Number.isFinite(priceAdjustPct) ? priceAdjustPct : 0;
        const sign = pct > 0 ? '+' : '';
        return sign + pct + '%';
    }
    return 'Venta';
}

function getCartPriceOriginBadge() {
    if (priceMode === 'mayorista_suc') {
        return '<span class="price-origin-tag">Mayorista</span>';
    }
    if (priceMode === 'custom_pct') {
        const pct = Number.isFinite(priceAdjustPct) ? priceAdjustPct : 0;
        const sign = pct > 0 ? '+' : '';
        return '<span class="price-origin-tag">' + sign + pct + '%</span>';
    }
    return '<span class="price-origin-tag">Venta</span>';
}

function setPriceMode(mode, pctOverride) {
    priceMode = mode;
    if (mode === 'custom_pct' && Number.isFinite(pctOverride)) {
        priceAdjustPct = pctOverride;
    }
    localStorage.setItem('pos_price_mode', mode);
    localStorage.setItem('pos_price_adjust_pct', String(priceAdjustPct));
    recalculateCartPrices();
    _applyPriceModeUI();
    renderProducts();
    renderCart();
}

function recalculateCartPrices() {
    if (!Array.isArray(cart) || cart.length === 0) return;
    cart.forEach(item => {
        if (item.customPrice) return;
        const prod = productsDB.find(p => p.codigo == item.id);
        if (prod) {
            item.price = getPrice(prod);
        }
    });
    saveCartState();
}

function _applyPriceModeUI() {
    // Actualiza label del botón en el menú de inventario
    const lbl = document.getElementById('priceModeLabel');
    if (lbl) lbl.textContent = getPriceModeLabel();

    // Resalta la opción activa en el modal
    document.querySelectorAll('.price-mode-opt').forEach(btn => {
        const active = btn.dataset.mode === priceMode;
        btn.style.opacity     = active ? '1'   : '0.6';
        btn.style.boxShadow   = active ? '0 0 0 3px currentColor' : 'none';
        btn.style.fontWeight  = active ? '800' : '600';
    });
    const pctInput = document.getElementById('priceAdjustPct');
    if (pctInput) pctInput.value = String(priceAdjustPct);
    const pctHint = document.getElementById('priceAdjustHint');
    if (pctHint) {
        const sign = priceAdjustPct > 0 ? '+' : '';
        pctHint.textContent = 'Se aplicará ' + sign + priceAdjustPct + '% sobre el precio de venta normal.';
    }
}

function openPriceModeModal() {
    _applyPriceModeUI();
    const el = document.getElementById('priceModeModal');
    if (!el) return;
    const m = bootstrap.Modal.getOrCreateInstance(el);
    m.show();

    // Enlazar clicks de opciones (una sola vez con flag)
    if (!el._pmBound) {
        el._pmBound = true;
        el.querySelectorAll('.price-mode-opt').forEach(btn => {
            btn.addEventListener('click', () => {
                setPriceMode(btn.dataset.mode);
                m.hide();
            });
        });
        const applyBtn = document.getElementById('btnApplyCustomPriceMode');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => {
                const pctInput = document.getElementById('priceAdjustPct');
                const pct = parseFloat((pctInput && pctInput.value) || '0');
                if (!Number.isFinite(pct)) return showToast('Porcentaje inválido', 'warning');
                setPriceMode('custom_pct', pct);
                m.hide();
            });
        }
    }
}

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
function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showToast(msg, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    let color = 'text-bg-success', icon = '<i class="fas fa-check-circle"></i>';
    if (type === 'error' || type === 'danger') { color = 'text-bg-danger'; icon = '<i class="fas fa-exclamation-circle"></i>'; }
    else if (type === 'warning') { color = 'text-bg-warning'; icon = '<i class="fas fa-cloud-upload-alt"></i>'; }

    const div = document.createElement('div');
    div.innerHTML = '<div class="toast align-items-center ' + color + ' border-0 mb-2 shadow" role="alert"><div class="d-flex"><div class="toast-body fw-bold fs-6">' + icon + ' ' + escHtml(msg) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
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
window.stockFilterActive = localStorage.getItem('pos_stock_filter') === 'true';

// ── Roles y seguridad ─────────────────────────────────────────────────────────
let currentRole     = 'cajero';   // rol del usuario logueado
let pinAttempts     = 0;          // intentos fallidos acumulados
const PIN_MAX_ATTEMPTS = 3;       // máx antes de bloquear
const PIN_LOCKOUT_MS   = 30000;   // 30 segundos de bloqueo
let pinLockedUntil  = 0;          // timestamp de fin de bloqueo
let pinLockInterval = null;       // interval del countdown
let inactivityTimer = null;       // timer de auto-logout
const INACTIVITY_MS = 15 * 60 * 1000; // 15 minutos de inactividad
let pinEntryMode = 'overlay';
let pendingSessionRetry = null;
let sessionLoginModalInstance = null;
let terminalLockModalInstance = null;
let pinLoginInProgress = false;

function logAuthEvent(event, details = {}) {
    // Nunca registrar PIN, tokens, respuestas completas ni datos sensibles.
    console.info('[POS-AUTH]', Object.assign({ event, at: new Date().toISOString() }, details));
}

function setPinLoginBusy(busy, message = '') {
    pinLoginInProgress = busy;
    const buttons = document.querySelectorAll('#pinGrid button, #sessionLoginModal .mini-pin-grid button');
    buttons.forEach(button => { button.disabled = busy; });
    ['pinNetworkStatus', 'pinNetworkStatusModal'].forEach(id => {
        const status = document.getElementById(id);
        if (status) {
            status.textContent = message;
            status.classList.toggle('d-none', !message);
        }
    });
}

function updateAuthModeBadge(mode) {
    const badge = document.getElementById('authModeBadge');
    if (!badge) return;
    const offline = mode === 'offline';
    badge.className = 'badge px-2 ' + (offline ? 'bg-warning text-dark' : 'bg-info text-dark');
    badge.innerHTML = offline
        ? '<i class="fas fa-cloud-slash"></i> SESIÓN OFFLINE'
        : '<i class="fas fa-shield-alt"></i> SESIÓN ONLINE';
    badge.title = offline
        ? 'PIN validado localmente; las operaciones pendientes se sincronizarán al recuperar la conexión.'
        : 'PIN validado por el servidor.';
}

// ==========================================
// INICIALIZACION
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    console.log('Iniciando POS...');
    const sessionLoginModalEl = document.getElementById('sessionLoginModal');
    if (sessionLoginModalEl) {
        sessionLoginModalEl.addEventListener('hidden.bs.modal', () => {
            enteredPin = '';
            updatePinDisplay();
            pinEntryMode = 'overlay';
            pendingSessionRetry = null;
            const reasonEl = document.getElementById('sessionLoginReason');
            if (reasonEl) reasonEl.innerText = '';
        });
    }
    const terminalLockModalEl = document.getElementById('terminalLockModal');
    if (terminalLockModalEl) {
        terminalLockModalInstance = bootstrap.Modal.getOrCreateInstance(terminalLockModalEl);
        const unlockBtn = document.getElementById('btnUnlockTerminal');
        if (unlockBtn && !unlockBtn.dataset.bound) {
            unlockBtn.dataset.bound = '1';
            unlockBtn.addEventListener('click', () => {
                if (terminalLockModalInstance) terminalLockModalInstance.hide();
                openSessionLoginModal('Inicio de sesion requerido tras bloqueo por inactividad.', pendingSessionRetry);
            });
        }
    }
    _applyPriceModeUI();
    // El catálogo se dibuja una sola vez después de elegir servidor o caché.
    const initialSearch = document.getElementById('searchInput');
    if (initialSearch) initialSearch.value = '';
    
    setTimeout(() => {
        if (typeof window.initPOSOffline === 'function') {
            window.initPOSOffline().catch(() => {});
        }
    }, 300);
    
    if (typeof PRODUCTS_DATA !== 'undefined' && Array.isArray(PRODUCTS_DATA) && PRODUCTS_DATA.length > 0) {
        console.log('Cargando ' + PRODUCTS_DATA.length + ' productos desde servidor...');
        window.productsDB = PRODUCTS_DATA;
        productsDB = window.productsDB;
        saveToCache(productsDB);
        if (window.posCache && typeof window.posCache.saveProducts === 'function' && window.posCache.db) {
            window.posCache.saveProducts(productsDB).catch(() => {});
        }
        recalculateCartPrices();
        renderProducts('all');
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
    syncStockFilterUI();
    setInterval(updateSyncKeypadButton, 5000);
});

// ==========================================
// LOGICA OFFLINE Y SYNC
// ==========================================
function updateOnlineStatus() {
    const badge = document.getElementById('netStatus');
    const btnSync = document.getElementById('btnSync');
    const legacyQueue = readStorageArray(QUEUE_KEY);
    let pendingCount = legacyQueue.length;
    
    if (typeof window.connectionMonitor !== 'undefined') {
        const monitor = window.connectionMonitor;
        const latency = monitor.latency;
        
        if (!monitor.isOnline) {
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
    
    const canSync = typeof window.connectionMonitor !== 'undefined'
        ? window.connectionMonitor.isOnline
        : navigator.onLine;
    if (canSync && pendingCount > 0) {
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
    const legacyQueue = readStorageArray(QUEUE_KEY);
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

function pendingSyncReadLegacyQueue() {
    try {
        const value = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        return Array.isArray(value) ? value : [];
    } catch (e) { return []; }
}

function collectSaleStockCodes(target, sale, response) {
    if (!(target instanceof Set)) return;
    (Array.isArray(sale?.items) ? sale.items : []).forEach(item => {
        const code = String(item?.id || item?.codigo || '').trim();
        if (code) target.add(code);
    });
    (Array.isArray(response?.affected_codes) ? response.affected_codes : []).forEach(code => {
        code = String(code || '').trim();
        if (code) target.add(code);
    });
}

async function refreshPendingSyncPanel() {
    const status = document.getElementById('pendingSyncStatus');
    const total = document.getElementById('pendingSyncTotal');
    const errors = document.getElementById('pendingSyncErrors');
    const sources = document.getElementById('pendingSyncSources');
    const last = document.getElementById('pendingSyncLast');
    if (!status) return;
    status.textContent = 'Consultando pendientes...';
    const legacy = pendingSyncReadLegacyQueue();
    let idb = [];
    if (window.posCache && typeof window.posCache.getPendingSales === 'function') {
        try { idb = await window.posCache.getPendingSales(); } catch (e) { status.textContent = 'No se pudo leer el almacenamiento local.'; }
    }
    const rememberedErrors = Number(sessionStorage.getItem('pos_sync_errors') || 0);
    const count = legacy.length + idb.length;
    if (total) total.textContent = count;
    if (errors) errors.textContent = rememberedErrors;
    if (sources) sources.textContent = `LocalStorage: ${legacy.length} · Base local: ${idb.length}`;
    if (last) {
        const stamp = sessionStorage.getItem('pos_sync_last') || localStorage.getItem('pos_last_sync');
        last.textContent = stamp ? 'Última actividad: ' + new Date(Number(stamp)).toLocaleString() : 'Sin sincronizaciones en esta sesión.';
    }
    status.textContent = count ? 'Hay ventas esperando confirmación del servidor.' : 'No hay ventas pendientes.';
    const run = document.getElementById('pendingSyncRunBtn');
    if (run) run.disabled = count === 0;
    await updateSyncKeypadButton();
}

function openPendingSyncPanel() {
    const modalEl = document.getElementById('pendingSyncModal');
    if (!modalEl) return syncManual();
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    refreshPendingSyncPanel();
}

async function syncOfflineQueue() {
    if (offlineSyncInProgress) return;
    offlineSyncInProgress = true;
    if (!navigator.onLine) {
        offlineSyncInProgress = false;
        return showToast("Sin internet", "error");
    }
    if (!await ensurePosCsrfToken(true)) {
        offlineSyncInProgress = false;
        openSessionLoginModal('Inicio de sesion requerido para sincronizar ventas offline.', () => syncOfflineQueue());
        return showToast('Sin sesion valida para sincronizar', 'warning');
    }
    
    const queue = readStorageArray(QUEUE_KEY);
    if (queue.length === 0) {
        offlineSyncInProgress = false;
        return;
    }
    
    const btn = document.getElementById('btnSync'); 
    if(btn) btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>';
    
    const failedQueue = []; 
    let syncedCount = 0;
    const syncedStockCodes = new Set();
    
    for (const sale of queue) {
        try {
            const resp = await fetch('pos_save.php', { 
                method: 'POST', 
                headers: getPosJsonHeaders(), 
                body: JSON.stringify(sale) 
            });
            const res = await resp.json();
            if (res.status === 'success') {
                notifyOvernightCashSession(res);
                syncedCount++;
                collectSaleStockCodes(syncedStockCodes, sale, res);
                try { if (window.PalWebNative && sale.uuid) window.PalWebNative.saleSynced(sale.uuid); } catch (e) {}
            } else {
                if (await handleSessionAuthFailure(res, () => syncOfflineQueue(), 'Inicio de sesion requerido para sincronizar ventas offline.')) {
                    failedQueue.push(sale);
                    break;
                }
                failedQueue.push(sale);
            }
        } catch (e) { 
            failedQueue.push(sale); 
        }
    }
    
    localStorage.setItem(QUEUE_KEY, JSON.stringify(failedQueue));
    if (syncedStockCodes.size > 0) {
        await refreshProductStock([...syncedStockCodes], { allowFullRefresh: true });
    }
    updateOnlineStatus();
    
    if (syncedCount > 0) { 
        showToast(syncedCount + ' ventas sincronizadas', 'success'); 
        Synth.tada(); 
    }
    if (btn) btn.innerHTML = '<i class="fas fa-sync"></i>';
    offlineSyncInProgress = false;
}

async function syncAllPending() {
    if (offlineSyncInProgress) return { synced: 0, errors: 0, busy: true };
    offlineSyncInProgress = true;
    if (!navigator.onLine) {
        offlineSyncInProgress = false;
        return { synced: 0, errors: 1 };
    }
    if (!await ensurePosCsrfToken(true)) {
        offlineSyncInProgress = false;
        openSessionLoginModal('Inicio de sesion requerido para sincronizar ventas offline.', () => syncAllPending());
        showToast('Sin sesion valida para sincronizar', 'warning');
        return { synced: 0, errors: 1 };
    }
    
    let totalSynced = 0;
    let totalErrors = 0;
    const processedSaleUuids = new Set();
    const syncedStockCodes = new Set();
    
    // localStorage
    const legacyQueue = readStorageArray(QUEUE_KEY);
    if (legacyQueue.length > 0) {
        const failedQueue = [];
        for (const sale of legacyQueue) {
            if (sale && sale.uuid) processedSaleUuids.add(String(sale.uuid));
            try {
                const resp = await fetch('pos_save.php', { 
                    method: 'POST', 
                    headers: getPosJsonHeaders(), 
                    body: JSON.stringify(sale) 
                });
            const res = await resp.json();
                if (res.status === 'success') {
                    notifyOvernightCashSession(res);
                    totalSynced++;
                    collectSaleStockCodes(syncedStockCodes, sale, res);
                    try { if (window.PalWebNative && sale.uuid) window.PalWebNative.saleSynced(sale.uuid); } catch (e) {}
                } else {
                    if (await handleSessionAuthFailure(res, () => syncAllPending(), 'Inicio de sesion requerido para sincronizar ventas offline.')) {
                        failedQueue.push(sale);
                        break;
                    }
                    failedQueue.push(sale);
                    totalErrors++;
                }
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
                if (sale && sale.uuid && processedSaleUuids.has(String(sale.uuid))) {
                    await window.posCache.markSaleSynced(sale.id);
                    continue;
                }
                if (sale && sale.uuid) processedSaleUuids.add(String(sale.uuid));
                try {
                    const resp = await fetch('pos_save.php', { 
                        method: 'POST', 
                        headers: getPosJsonHeaders(), 
                        body: JSON.stringify(sale) 
                    });
                    const res = await resp.json();
                    if (res.status === 'success') {
                        notifyOvernightCashSession(res);
                        await window.posCache.markSaleSynced(sale.id);
                        collectSaleStockCodes(syncedStockCodes, sale, res);
                        try { if (window.PalWebNative && sale.uuid) window.PalWebNative.saleSynced(sale.uuid); } catch (e) {}
                        totalSynced++;
                    } else {
                        if (await handleSessionAuthFailure(res, () => syncAllPending(), 'Inicio de sesion requerido para sincronizar ventas offline.')) {
                            break;
                        }
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

    if (syncedStockCodes.size > 0) {
        await refreshProductStock([...syncedStockCodes], { allowFullRefresh: true });
    }
    
    if (totalSynced > 0) {
        showToast(totalSynced + ' ventas sincronizadas', 'success');
        Synth.tada();
    }
    
    updateOnlineStatus();
    sessionStorage.setItem('pos_sync_last', String(Date.now()));
    sessionStorage.setItem('pos_sync_errors', String(totalErrors));
    offlineSyncInProgress = false;
    return { synced: totalSynced, errors: totalErrors };
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
    
    const result = await syncAllPending();
    const status = document.getElementById('pendingSyncStatus');
    if (status && result) status.textContent = result.errors
        ? `Sincronizadas: ${result.synced}. Errores: ${result.errors}.`
        : `Sincronización completada: ${result.synced} ventas.`;
    await refreshPendingSyncPanel();
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
    if (window.posCache && typeof window.posCache.getProducts === 'function') {
        window.posCache.getProducts().then(data => {
            if (Array.isArray(data) && data.length > 0) {
                window.productsDB = data;
                productsDB = window.productsDB;
                saveToCache(data);
                renderProducts('all');
                console.log('Cargados ' + data.length + ' productos desde IndexedDB');
                return;
            }
            refreshProducts();
        }).catch(() => refreshProducts());
        return;
    }
    refreshProducts();
}

function saveToCache(data) { 
    try { 
        localStorage.setItem(CACHE_KEY, JSON.stringify({ data: data, timestamp: Date.now() })); 
    } catch (e) {} 
}

async function refreshProducts(almId = null, forceFull = false) {
    const btn = document.getElementById('btnRefresh');
    if (btn) { btn.innerHTML = '<i class="fas fa-spin fa-spinner"></i>'; btn.disabled = true; }

    const loadAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    const loadTimeoutMs = 60000;
    let loadTimeoutId = null;

    try {
        const almParam = almId ? '&alm=' + parseInt(almId, 10) : '';
        const existing = Array.isArray(window.productsDB) ? window.productsDB : [];
        const storedSync = Number(localStorage.getItem(CATALOG_SYNC_KEY) || window.POS_CATALOG_SYNC_AT || 0);
        const sinceParam = !forceFull && existing.length && storedSync ? '&since=' + storedSync : '';
        if (loadAbort) {
            loadTimeoutId = setTimeout(() => loadAbort.abort(), loadTimeoutMs);
        }
        const response = await fetch('pos.php?load_products=1&t=' + Date.now() + almParam + sinceParam, {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' },
            signal: loadAbort ? loadAbort.signal : undefined
        });
        
        if (!response.ok) throw new Error('Error del servidor: ' + response.status);
        
        const result = await response.json();
        
        if (result.status === 'success' && Array.isArray(result.products)) { 
            const merged = result.incremental
                ? new Map(existing.map(product => [String(product.codigo), product]))
                : new Map();
            result.products.forEach(product => {
                if (result.incremental && Number(product.activo) === 0) merged.delete(String(product.codigo));
                else merged.set(String(product.codigo), product);
            });
            window.productsDB = Array.from(merged.values());
            productsDB = window.productsDB;
            localStorage.setItem(CATALOG_SYNC_KEY, String(result.timestamp || Math.floor(Date.now() / 1000)));
            saveToCache(window.productsDB);
            
            if (typeof window.posCache !== 'undefined' && window.posCache.saveProducts) {
                await window.posCache.saveProducts(window.productsDB);
            }
            try {
                if (window.PalWebNative && typeof window.PalWebNative.cacheProducts === 'function') {
                    window.PalWebNative.cacheProducts(JSON.stringify(window.productsDB));
                }
            } catch (e) { console.warn('No se pudo copiar catálogo a Room:', e); }
            
            renderProducts('all', document.getElementById('searchInput')?.value || ''); 
            showToast(result.incremental
                ? 'Catálogo sincronizado (' + result.products.length + ' cambios)'
                : 'Catálogo actualizado (' + result.products.length + ' productos)');
        }
    } catch(e) {
        const msg = (e && e.name === 'AbortError')
            ? 'La carga de productos tardó demasiado. Se continuará con el POS.'
            : 'Error al actualizar: ' + e.message;
        if (e && e.name === 'AbortError') {
            console.warn('Timeout actualizando productos:', e);
        } else {
            console.error('Error actualizando productos:', e);
        }
        showToast(msg, 'warning');
    } finally { 
        if (loadTimeoutId) clearTimeout(loadTimeoutId);
        if (btn) { btn.innerHTML = '<i class="fas fa-sync-alt"></i>'; btn.disabled = false; } 
    }
}

// La venta actualiza stock_almacen, pero no necesariamente productos.updated_at.
// Reconciliar solo las líneas cobradas mantiene visible la existencia real sin
// descargar de nuevo todo el catálogo.
let productStockRefreshSequence = 0;

async function refreshProductStock(codes, options = {}) {
    if (!navigator.onLine || !Array.isArray(codes) || codes.length === 0) return false;
    const uniqueCodes = [...new Set(codes.map(code => String(code || '').trim()).filter(Boolean))];
    if (uniqueCodes.length === 0) return false;
    const requestSequence = ++productStockRefreshSequence;
    try {
        const response = await fetch('pos.php?load_product_stock=1&codes=' + encodeURIComponent(uniqueCodes.join(',')) + '&t=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate' }
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const result = await response.json();
        if (result.status !== 'success' || !Array.isArray(result.products)) {
            throw new Error('Respuesta de stock inválida');
        }
        // Una consulta anterior no puede sobrescribir la existencia obtenida
        // por una venta más reciente cuando varias operaciones son seguidas.
        if (requestSequence !== productStockRefreshSequence) return false;
        const stockByCode = new Map(result.products.map(product => [String(product.codigo), Number(product.stock || 0)]));
        (window.productsDB || []).forEach(product => {
            const code = String(product.codigo || '');
            if (stockByCode.has(code)) product.stock = stockByCode.get(code);
        });
        productsDB = window.productsDB || [];
        saveToCache(productsDB);
        if (window.posCache && typeof window.posCache.saveProducts === 'function') {
            await window.posCache.saveProducts(productsDB);
        }
        const activeCategory = document.querySelector('.category-btn.active')?.dataset?.category || 'all';
        renderProducts(activeCategory, document.getElementById('searchInput')?.value || '');
        const missingCodes = uniqueCodes.filter(code => !stockByCode.has(code));
        if (missingCodes.length > 0 && options.allowFullRefresh !== false && requestSequence === productStockRefreshSequence) {
            await refreshProducts(null, true);
        }
        return missingCodes.length === 0;
    } catch (error) {
        console.warn('No se pudo actualizar el stock visible:', error);
        if (options.allowFullRefresh !== false && requestSequence === productStockRefreshSequence) {
            try {
                await refreshProducts(null, true);
                return true;
            } catch (fallbackError) {
                console.warn('Tampoco se pudo actualizar el catálogo global:', fallbackError);
            }
        }
        return false;
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

    if (e.code === 'Space' && !inInput && !modalOpen) {
        e.preventDefault();
        openQtyModal();
        return;
    }

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
    // No capturar teclas mientras el PIN overlay está visible
    const pinOverlay = document.getElementById('pinOverlay');
    if (pinOverlay && pinOverlay.style.display !== 'none' && pinOverlay.style.display !== '') return;
    if(e.target.tagName === 'INPUT' && e.target.id !== 'searchInput') return;
    if(e.ctrlKey || e.altKey || e.metaKey) return; 
    if (e.code === 'Space' || e.key === ' ') return;
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
    const code = String(c || '').trim();
    let matchType = '';
    const p = productsDB.find(x => {
        const sku = String(x.codigo || '').trim();
        const cb1 = String(x.codigo_barra_1 || '').trim();
        const cb2 = String(x.codigo_barra_2 || '').trim();
        if (sku === code) { matchType = 'SKU'; return true; }
        if (cb1 && cb1 === code) { matchType = 'CB1'; return true; }
        if (cb2 && cb2 === code) { matchType = 'CB2'; return true; }
        return false;
    });
    if(p) { 
        showToast('Encontrado por ' + (matchType || 'SKU'));
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

function clearSearchAndRefreshProductList(focusInput = false) {
    const si = document.getElementById('searchInput');
    if (si) {
        si.value = '';
        si.setAttribute('autocomplete', 'off');
        if (focusInput) si.focus();
        else si.blur();
    }
    renderProducts('all', '');
}

function typePin(v) { 
    Synth.click(); 
    if(v === 'C') enteredPin = ""; 
    else if(enteredPin.length < 4) enteredPin += v; 
    updatePinDisplay(); 
}

function updatePinDisplay() { 
    const display = document.getElementById('pinDisplay');
    const masked = String.fromCharCode(8226).repeat(enteredPin.length);
    if (display) display.innerText = masked;
    const sessionDisplay = document.getElementById('sessionPinDisplay');
    if (sessionDisplay) sessionDisplay.innerText = masked || '....';
}

function sessionErrorNeedsLogin(message) {
    const msg = String(message || '').toLowerCase();
    return /sesion requerida|sesion invalida|no autenticado|no autorizado|csrf/.test(msg);
}

function closeSessionLoginModal() {
    const modalEl = document.getElementById('sessionLoginModal');
    if (!modalEl) return;
    sessionLoginModalInstance = sessionLoginModalInstance || bootstrap.Modal.getOrCreateInstance(modalEl);
    sessionLoginModalInstance.hide();
    pinEntryMode = 'overlay';
    const reasonEl = document.getElementById('sessionLoginReason');
    if (reasonEl) reasonEl.innerText = '';
}

function openSessionLoginModal(message, retryFn) {
    const modalEl = document.getElementById('sessionLoginModal');
    if (!modalEl) return;
    pendingSessionRetry = typeof retryFn === 'function' ? retryFn : null;
    enteredPin = '';
    pinEntryMode = 'modal';
    updatePinDisplay();
    const gridEl = modalEl.querySelector('.mini-pin-grid');
    if (gridEl) gridEl.style.display = 'grid';
    const bodyEl = modalEl.querySelector('.modal-body');
    if (bodyEl) {
        bodyEl.scrollTop = 0;
        bodyEl.style.overflowY = 'auto';
    }
    const reasonEl = document.getElementById('sessionLoginReason');
    if (reasonEl) reasonEl.innerText = String(message || 'Inicio de sesion requerido');
    sessionLoginModalInstance = sessionLoginModalInstance || bootstrap.Modal.getOrCreateInstance(modalEl);
    sessionLoginModalInstance.show();
}

async function handleSessionAuthFailure(result, retryFn, fallbackMessage) {
    const message = result?.msg || result?.message || fallbackMessage || 'Inicio de sesion requerido';
    if (!sessionErrorNeedsLogin(message)) return false;
    showToast(message, 'warning');
    openSessionLoginModal(message, retryFn);
    return true;
}

// ==========================================
// VERIFICACION DE PIN (ONLINE/OFFLINE)
// ==========================================
async function verifyPin() {
    // Bloqueo activo: ignorar intentos
    if (Date.now() < pinLockedUntil || pinLoginInProgress) return;
    if (enteredPin.length < 4) return;

    let loginSuccess = false;
    let allowOfflineLogin = !navigator.onLine;
    let loginMode = 'offline';
    let loginFailureKind = allowOfflineLogin ? 'network' : '';
    let realOnline = navigator.onLine;
    let cajeroRol    = 'cajero';
    let loginContext = null;
    // Contexto con el que se abrió esta página, antes de aplicar el PIN nuevo.
    // Debe conservarse para detectar cambios de sucursal/almacén.
    const pageContextBeforeLogin = Object.assign({}, window.POS_ACTIVE_CONTEXT || {});

    setPinLoginBusy(true, 'Verificando conexión…');
    const loginStartedAt = performance.now();
    try {
        if (navigator.onLine) {
            // netcheck actualiza el indicador de red, pero no debe bloquear el login:
            // un ping auxiliar lento no significa que el endpoint de autenticación
            // esté offline.
            if (window.connectionMonitor && typeof window.connectionMonitor.checkConnection === 'function') {
                window.connectionMonitor.checkConnection().catch(() => {});
            }

            if (realOnline) {
                let lastError = null;
                for (let attempt = 1; attempt <= 2 && !loginSuccess; attempt++) {
                    try {
                        setPinLoginBusy(true, attempt === 1 ? 'Conectando con el servidor…' : 'Reintentando conexión…');
                        const csrfReady = await ensurePosCsrfToken(true);
                        if (!csrfReady) throw Object.assign(new Error('No se pudo obtener el token CSRF'), { authKind: 'network' });

                        const resp = await fetch('pos_cash.php?action=login', {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: getPosJsonHeaders({ 'Cache-Control': 'no-store', 'Pragma': 'no-cache' }),
                            body: JSON.stringify({ pin: enteredPin }),
                            signal: typeof window.posFetchTimeoutSignal === 'function'
                                ? window.posFetchTimeoutSignal(10000)
                                : undefined
                        });
                        const data = await resp.json().catch(() => ({}));

                        // Un rechazo del servidor (PIN incorrecto, CSRF, etc.) no debe
                        // convertirse en un login offline autorizado.
                        if (!resp.ok || data.status !== 'success') {
                            const error = new Error(data.msg || data.message || 'No se pudo autenticar');
                            error.authKind = resp.status >= 500 || resp.status === 408 ? 'network' : 'credentials';
                            error.httpStatus = resp.status;
                            throw error;
                        }

                        if (typeof data.csrf_token === 'string' && data.csrf_token.length >= 32) {
                            window.POS_CSRF_TOKEN_VALUE = data.csrf_token;
                        }
                        loginSuccess = true;
                        loginMode = 'online';
                        currentCashier = data.cajero;
                        cajeroRol = data.rol ?? 'cajero';
                        loginContext = Object.assign({}, data, { serverAuthenticated: true });
                        logAuthEvent('login_online_ok', { attempt, durationMs: Math.round(performance.now() - loginStartedAt) });

                        // Guardar un verificador local del PIN recién validado, nunca el PIN.
                        if (typeof window.posCache !== 'undefined' && window.posCache.db &&
                            typeof window.posCache.rememberAuthenticatedCashier === 'function') {
                            await window.posCache.rememberAuthenticatedCashier(data, enteredPin).catch(() => {});
                        }
                    } catch (e) {
                        lastError = e;
                        const retryable = e.authKind === 'network' || e instanceof TypeError ||
                            e.name === 'AbortError' || e.name === 'TimeoutError';
                        logAuthEvent('login_online_error', {
                            attempt,
                            kind: retryable ? 'network_or_timeout' : 'credentials_or_server',
                            status: e.httpStatus || 0,
                            durationMs: Math.round(performance.now() - loginStartedAt)
                        });
                        if (!retryable || attempt >= 2) break;
                        await new Promise(resolve => setTimeout(resolve, 700));
                    }
                }

                if (!loginSuccess && lastError) {
                    const retryable = lastError.authKind === 'network' || lastError instanceof TypeError ||
                        lastError.name === 'AbortError' || lastError.name === 'TimeoutError';
                    // Con navigator.onLine activo, no autorizar desde IndexedDB por
                    // un fallo puntual del endpoint online. El fallback offline se
                    // reserva para una desconexión confirmada por el navegador.
                    allowOfflineLogin = !navigator.onLine && retryable;
                    loginFailureKind = retryable ? (navigator.onLine ? 'server' : 'network') : 'credentials';
                }
            } else {
                allowOfflineLogin = true;
                loginFailureKind = 'network';
                logAuthEvent('login_offline_fallback', { reason: 'real_connectivity_check_failed' });
            }
        }
    } finally {
        if (!loginSuccess && allowOfflineLogin) {
            setPinLoginBusy(true, 'Sin conexión. Verificando acceso offline…');
        }
    }

    // Un rechazo del servidor nunca se convierte en autorización offline.
    if (!loginSuccess && allowOfflineLogin && typeof window.posCache !== 'undefined' &&
        typeof window.posCache.verifyCajeroOffline === 'function') {
        try {
            const cajero = await window.posCache.verifyCajeroOffline(enteredPin);
            if (cajero) {
                loginSuccess = true;
                loginMode = 'offline';
                currentCashier = cajero.nombre;
                cajeroRol = cajero.rol ?? 'cajero';
                loginContext = Object.assign({}, cajero, { serverAuthenticated: false });
                logAuthEvent('login_offline_ok', { durationMs: Math.round(performance.now() - loginStartedAt) });
            }
        } catch(e) {}
    }

    setPinLoginBusy(false);
    if (loginSuccess && loginMode === 'offline') {
        showToast('Acceso offline: las operaciones se sincronizarán al recuperar la conexión.', 'warning');
    }

    // El catálogo y la sesión de servidor no se pueden cambiar de sucursal sin red.
    // No permitir que un PIN offline deje la interfaz apuntando a datos de otra sucursal.
    if (loginSuccess && !navigator.onLine && loginContext) {
        const offlineContextChanged = Number(pageContextBeforeLogin.id_empresa || 0) !== Number(loginContext.id_empresa || 0)
            || Number(pageContextBeforeLogin.id_sucursal || 0) !== Number(loginContext.id_sucursal || 0)
            || Number(pageContextBeforeLogin.id_almacen || 0) !== Number(loginContext.id_almacen || 0);
        if (offlineContextChanged) {
            enteredPin = '';
            updatePinDisplay();
            showToast('Conéctate a internet para cambiar de sucursal o almacén.', 'error');
            return;
        }
    }

    if (loginSuccess) {
        updateAuthModeBadge(loginMode);
        const isSessionReauth = pinEntryMode === 'modal';
        currentRole = cajeroRol;
        pinAttempts = 0;
        showPinAttemptDots(); // limpiar dots

        if (loginContext) {
            const sucBadge = document.getElementById('ctxSucursalBadge');
            const almBadge = document.getElementById('ctxAlmacenBadge');
            if (sucBadge && loginContext.id_sucursal) sucBadge.innerText = String(loginContext.id_sucursal);
            if (almBadge && loginContext.id_almacen) almBadge.innerText = String(loginContext.id_almacen);
            window.POS_ACTIVE_CONTEXT = Object.assign({}, window.POS_ACTIVE_CONTEXT || {}, {
                id_empresa: parseInt(loginContext.id_empresa || (window.POS_ACTIVE_CONTEXT || {}).id_empresa || 1, 10),
                id_sucursal: parseInt(loginContext.id_sucursal || (window.POS_ACTIVE_CONTEXT || {}).id_sucursal || 1, 10),
                id_almacen: parseInt(loginContext.id_almacen || (window.POS_ACTIVE_CONTEXT || {}).id_almacen || 1, 10)
            });
        }

        // Función que completa el login tras confirmar almacén
        const finalizarLogin = async function (almId, almNombre) {
            try {
                if (typeof window.setProgressOverlay === 'function') {
                    window.setProgressOverlay(45, 'Cargando productos del almacén...');
                }

                // Al cambiar de contexto, las claves de caché son inmutables
                // durante esta página. Recargar automáticamente evita mezclar
                // el catálogo del almacén anterior con el nuevo.
                const previousEmpresa = Number(pageContextBeforeLogin.id_empresa || 0);
                const previousSucursal = Number(pageContextBeforeLogin.id_sucursal || 0);
                const previousAlmacen = Number(pageContextBeforeLogin.id_almacen || 0);

                // Actualizar contexto con el almacén elegido
                if (almId && loginContext) loginContext.id_almacen = almId;
                window.POS_ACTIVE_CONTEXT = Object.assign({}, window.POS_ACTIVE_CONTEXT || {}, {
                    id_empresa: parseInt(loginContext?.id_empresa || (window.POS_ACTIVE_CONTEXT || {}).id_empresa || 1, 10),
                    id_sucursal: parseInt(loginContext?.id_sucursal || (window.POS_ACTIVE_CONTEXT || {}).id_sucursal || 1, 10),
                    id_almacen: parseInt(almId || loginContext?.id_almacen || (window.POS_ACTIVE_CONTEXT || {}).id_almacen || 1, 10)
                });

                const contextChanged = previousEmpresa !== Number(window.POS_ACTIVE_CONTEXT.id_empresa)
                    || previousSucursal !== Number(window.POS_ACTIVE_CONTEXT.id_sucursal)
                    || previousAlmacen !== Number(window.POS_ACTIVE_CONTEXT.id_almacen);
                if (contextChanged && navigator.onLine) {
                    // Mantener abierta la sesión del cajero durante la recarga que
                    // aplica el nuevo catálogo y no restaurar un carrito de otra sucursal.
                    try {
                        sessionStorage.removeItem('pos_cart_locked');
                        sessionStorage.setItem('pos_session_reload', JSON.stringify({
                            cajero: currentCashier,
                            rol: cajeroRol,
                            cashOpen: false,
                            cashId: null,
                            timestamp: Date.now()
                        }));
                    } catch (e) {}
                    showToast('Cambiando al catálogo del nuevo almacén...', 'info');
                    window.location.reload();
                    return;
                }

                // Actualizar fondo del carrito con el banner de la sucursal
                const sucId = loginContext ? loginContext.id_sucursal : null;
                if (sucId && typeof window.updateCartBackground === 'function') {
                    window.updateCartBackground(sucId);
                }

                // Releer productos pasando el almacén como parámetro URL para garantizar
                // que se usa el correcto incluso si el POST set_almacen no llegó al servidor.
                if (navigator.onLine) {
                    await refreshProducts(almId || null);
                    // El estado de caja puede pertenecer al PIN/contexto anterior
                    // (por ejemplo, tras cambiar de sucursal sin recargar la página).
                    // Consultar siempre al servidor después del login evita cerrar
                    // una sesión de otra sucursal por usar un cashId obsoleto.
                    await checkCashStatusSilent();
                }

                if (typeof window.setProgressOverlay === 'function') {
                    window.setProgressOverlay(86, 'Finalizando acceso...');
                }

                Synth.tada();
                applyRoleRestrictions();
                startInactivityTimer();
                if (isSessionReauth) {
                    closeSessionLoginModal();
                    const retryFn = pendingSessionRetry;
                    pendingSessionRetry = null;
                    if (typeof retryFn === 'function') {
                        setTimeout(() => retryFn(), 120);
                    }
                } else {
                    unlockPos();
                }
            } finally {
                if (typeof window.setProgressOverlay === 'function') {
                    window.setProgressOverlay(100, 'Listo');
                }
                setTimeout(() => {
                    if (typeof window.hideProgressOverlay === 'function') {
                        window.hideProgressOverlay();
                    }
                }, 180);
            }
        };

        // Si la sucursal tiene múltiples almacenes, mostrar selector antes de continuar
        const sucId    = loginContext ? loginContext.id_sucursal : null;
        const almacenes = (typeof ALMACENES_BY_SUCURSAL !== 'undefined' && sucId)
            ? (ALMACENES_BY_SUCURSAL[sucId] || [])
            : [];

        if (!isSessionReauth && almacenes.length > 1 && typeof window.showAlmacenPicker === 'function') {
            window.showAlmacenPicker(almacenes, loginContext, finalizarLogin);
        } else {
            await finalizarLogin(loginContext?.id_almacen || null);
        }
    } else {
        if (loginFailureKind === 'network' || loginFailureKind === 'server') {
            const message = loginFailureKind === 'server'
                ? 'La conexión está disponible, pero el servidor de login no respondió. Inténtalo de nuevo.'
                : 'No se pudo contactar al servidor. Verifica la conexión e inténtalo de nuevo.';
            showToast(message, 'warning');
            logAuthEvent('login_failed', { kind: loginFailureKind, durationMs: Math.round(performance.now() - loginStartedAt) });
            enteredPin = '';
            updatePinDisplay();
            return;
        }
        pinAttempts++;
        if (pinAttempts >= PIN_MAX_ATTEMPTS) {
            activatePinLockout();
        } else {
            showPinAttemptDots();
            showToast(`PIN incorrecto (${pinAttempts}/${PIN_MAX_ATTEMPTS})`, 'error');
        }
        enteredPin = "";
        updatePinDisplay();
        if (pinEntryMode === 'modal') {
            const reasonEl = document.getElementById('sessionLoginReason');
            if (reasonEl) reasonEl.innerText = 'PIN incorrecto. Intenta otra vez.';
        }
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
            if (lockMsg) { lockMsg.textContent = ''; lockMsg.style.visibility = 'hidden'; lockMsg.style.height = '0'; lockMsg.style.overflow = 'hidden'; lockMsg.style.padding = '0'; lockMsg.style.margin = '0'; lockMsg.style.border = 'none'; }
            if (grid) grid.querySelectorAll('button').forEach(b => b.disabled = false);
        } else {
            if (lockMsg) {
                lockMsg.style.visibility = 'visible'; lockMsg.style.height = ''; lockMsg.style.overflow = ''; lockMsg.style.padding = ''; lockMsg.style.margin = ''; lockMsg.style.border = '';
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
    // Limpiar filtro residual (ej. "admin") y recargar lista completa.
    clearSearchAndRefreshProductList(false);
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
    // Botón de editar precio: visible siempre, deshabilitado para cajeros
    const btnEditPrice = document.getElementById('btnEditPrice');
    if (btnEditPrice) {
        const allowed = (currentRole === 'admin' || currentRole === 'supervisor');
        btnEditPrice.disabled = !allowed;
        btnEditPrice.title = allowed ? 'Modificar precio del producto seleccionado' : 'Requiere supervisor o administrador';
    }
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
    if (overlay) overlay.style.display = 'none';
    const modalEl = document.getElementById('sessionLoginModal');
    if (modalEl) {
        const gridEl = modalEl.querySelector('.mini-pin-grid');
        if (gridEl) gridEl.style.display = 'grid';
        const sessionModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        sessionModal.hide();
    }
    if (terminalLockModalInstance) {
        terminalLockModalInstance.show();
    } else {
        const terminalLockModalEl = document.getElementById('terminalLockModal');
        if (terminalLockModalEl) bootstrap.Modal.getOrCreateInstance(terminalLockModalEl).show();
    }
    // Re-aplicar restricciones (ocultar botones de supervisor/admin)
    applyRoleRestrictions();
    showToast('Sesión cerrada por inactividad', 'warning');
}

// ==========================================
// GESTION DE CAJA
// ==========================================
let cashModal;
let qtyModalContext = { mode: 'cart', product: null };
let qtyModalLastValue = parseFloat(localStorage.getItem('pos_last_qty_modal') || '1');
if (!Number.isFinite(qtyModalLastValue) || qtyModalLastValue <= 0) qtyModalLastValue = 1;

document.addEventListener('contextmenu', (e) => {
    const productCard = e.target && typeof e.target.closest === 'function' ? e.target.closest('.product-card') : null;
    if (!productCard) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (typeof productCard._posOpenQtyModal === 'function') {
        productCard._posOpenQtyModal(e, false);
    }
}, true);

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('cashModal');
    if (modalEl) cashModal = new bootstrap.Modal(modalEl);
});

document.addEventListener('DOMContentLoaded', () => {
    const qtyInput = document.getElementById('qtyModalInput');
    if (!qtyInput || qtyInput._qtyBound) return;
    qtyInput._qtyBound = true;
    qtyInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmQtyModal();
        }
    });
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
            const cashLabel = 'ABIERTA #' + cashId + ' (' + accountingDate + ')';
            if(btn) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');
                btn.innerText = cashLabel;
            }
            if(badge) {
                badge.className = 'cash-status cash-open ms-2';
                badge.style.cssText = 'font-size: 0.7rem; background-color: #28a745 !important; color: white !important; padding: 2px 8px; border-radius: 4px;';
                badge.innerText = cashLabel;
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
    if (invModeActive) return true; // inventario muestra todo
    if (!window.stockFilterActive) return true;
    return parseFloat(p.stock) > 0 || p.es_servicio == 1;
};

window.toggleStockFilter = function() {
    window.stockFilterActive = !window.stockFilterActive;
    localStorage.setItem('pos_stock_filter', window.stockFilterActive);
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

window.syncStockFilterUI = function() {
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

let productSearchTimer = null;
function scheduleProductSearch() {
    clearTimeout(productSearchTimer);
    productSearchTimer = setTimeout(filterProducts, 150);
}

function renderProducts(category, searchTerm) {
    clearTimeout(productSearchTimer);
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
    window._lastCatalog = sourceData; // guardar para re-render al cambiar modo
    _applyPriceModeUI();
    
    if (!Array.isArray(sourceData) || sourceData.length === 0) {
        grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-box-open fa-3x mb-2"></i><p>No hay productos disponibles</p></div>';
        return;
    }

    let filtered = sourceData.filter(p => {
        const matchCat = category === 'all' || p.categoria === category;
        const matchSearch = !term ||
            (p.nombre && p.nombre.toLowerCase().includes(term)) ||
            (p.codigo && p.codigo.toLowerCase().includes(term)) ||
            (p.codigo_barra_1 && String(p.codigo_barra_1).toLowerCase().includes(term)) ||
            (p.codigo_barra_2 && String(p.codigo_barra_2).toLowerCase().includes(term));
        return matchCat && matchSearch;
    });
    
    if (typeof window.shouldShowProduct === 'function') {
        filtered = filtered.filter(p => window.shouldShowProduct(p));
    }

    if (filtered.length === 0) {
        grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-search fa-3x mb-2"></i><p>No se encontraron productos</p></div>';
        return;
    }

    // Construir 48 tarjetas por bloque; el catálogo completo sigue disponible al escáner.
    let renderedCount = 0;
    const moreButton = document.createElement('button');
    moreButton.type = 'button';
    moreButton.className = 'btn btn-outline-primary m-2';
    moreButton.style.gridColumn = '1 / -1';
    moreButton.onclick = () => appendProductBatch();
    function appendProductBatch() {
    moreButton.remove();
    const fragment = document.createDocumentFragment();
    filtered.slice(renderedCount, renderedCount + 48).forEach(p => {
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
            imgHTML = '<div class="product-img-container"><img src="image.php?w=200&code=' + encodeURIComponent(p.codigo) + imgV + '" class="product-img" width="200" height="200" loading="lazy" decoding="async" onerror="this.style.display=\'none\'"></div>';
        }

        card.innerHTML = 
            // ID ÚNICO PARA EL BADGE
            '<div id="stock-badge-' + p.codigo + '" class="stock-badge ' + badgeClass + '">' + stockDisplay + '</div>' +
            imgHTML +
            '<div class="product-info">' +
                '<div class="product-name text-dark">' + (p.nombre || 'Sin nombre') + '</div>' +
                '<div class="product-price">$' + getPrice(p).toFixed(2) + '</div>' +
            '</div>';
        
        if (hasStock || invModeActive) {
            card.onclick = () => {
                if (card._suppressNextClick) {
                    card._suppressNextClick = false;
                    return;
                }
                addToCart(p);
            };
            card.style.cursor = 'pointer';
            // En modo inventario los productos agotados tienen badge diferente
            if (invModeActive && !hasStock) {
                card.querySelector('.stock-badge')?.classList.replace('stock-zero','stock-inv');
            }
        } else {
            card.onclick = () => {
                if (card._suppressNextClick) {
                    card._suppressNextClick = false;
                    return;
                }
                Synth.error();
                showToast('Sin Stock', 'error');
            };
            card.style.cursor = 'not-allowed';
        }
        bindProductQuantityGesture(card, p);
        const productImage = card.querySelector('img');
        if (productImage) productImage.alt = p.nombre || 'Producto';

        // Botón estrella ★ para favoritos
        const starBtn = document.createElement('button');
        starBtn.className = 'star-btn ' + (p.favorito == 1 ? 'active' : 'inactive');
        starBtn.id = 'star-' + p.codigo;
        starBtn.textContent = p.favorito == 1 ? '★' : '☆';
        starBtn.title = p.favorito == 1 ? 'Quitar de favoritos' : 'Agregar a favoritos';
        starBtn.onclick = (e) => toggleFavorite(p.codigo, e);
        card.appendChild(starBtn);

        fragment.appendChild(card);
    });
    renderedCount = Math.min(renderedCount + 48, filtered.length);
    grid.appendChild(fragment);
    if (renderedCount < filtered.length) {
        moreButton.textContent = `Mostrar más (${renderedCount} de ${filtered.length})`;
        grid.appendChild(moreButton);
    }
    // Sincronizar badges inmediatamente
    updateStockBadges();
    }
    appendProductBatch();
    renderFavoritesBar();
}

function bindProductQuantityGesture(card, product) {
    if (!card || !product) return;

    const openProductQty = () => openQtyModal('product', product);
    let pressTimer = null;
    let quantityModalOpenedAt = 0;

    const clearPressTimer = () => {
        if (pressTimer) {
            clearTimeout(pressTimer);
            pressTimer = null;
        }
    };

    const triggerQuantityModal = (e = null, suppressNextClick = true) => {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        const now = Date.now();
        if (now - quantityModalOpenedAt < 300) return;
        quantityModalOpenedAt = now;
        clearPressTimer();
        if (suppressNextClick) {
            card._suppressNextClick = true;
            setTimeout(() => { card._suppressNextClick = false; }, 2500);
        }
        openProductQty();
    };
    card._posOpenQtyModal = triggerQuantityModal;

    card.addEventListener('touchstart', (e) => {
        clearPressTimer();
        pressTimer = setTimeout(() => {
            triggerQuantityModal(null, true);
        }, 650);
    }, { passive: false });

    card.addEventListener('touchend', () => {
        clearPressTimer();
    }, { passive: true });

    card.addEventListener('touchmove', clearPressTimer, { passive: true });
    card.addEventListener('touchcancel', clearPressTimer, { passive: true });
    card.addEventListener('mousedown', (e) => {
        if (e.button === 2) {
            triggerQuantityModal(e, false);
            return;
        }
        if (e.button !== 0) return;
        clearPressTimer();
        pressTimer = setTimeout(() => {
            triggerQuantityModal(null, true);
        }, 650);
    });
    card.addEventListener('mouseup', clearPressTimer);
    card.addEventListener('mouseleave', clearPressTimer);
    card.addEventListener('dragend', clearPressTimer);
    card.addEventListener('contextmenu', clearPressTimer, true);

    card.addEventListener('dragstart', (e) => e.preventDefault());
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
                       '<div class="fav-price">$' + getPrice(p).toFixed(2) + '</div>';
        fc.onclick = () => { if (hasStock) addToCart(p); else showToast('Sin Stock', 'error'); };
        row.appendChild(fc);
    });

    bar.style.display = '';
}

function toggleFavorite(codigo, event) {
    event.stopPropagation();
    fetch('pos.php?toggle_fav=1', {
        method: 'POST',
        headers: getPosJsonHeaders(),
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
    clearTimeout(productSearchTimer);
    renderProducts('all', document.getElementById('searchInput').value); 
}

// ==========================================
// CARRITO
// ==========================================
// forceQty: cantidad a agregar (por defecto 1). Se usa en autopedidos para cantidades decimales.
function addToCart(p, forceQty) {
    const qty = (forceQty !== undefined && forceQty > 0) ? parseFloat(forceQty) : 1;
    // Verificacion de stock considerando lo que ya esta en carrito
    const idx = cart.findIndex(i => i.id === p.codigo && (!i.note));
    const currentQtyInCart = (idx >= 0) ? cart[idx].qty : 0;
    const stockAvailable = parseFloat(p.stock) || 0;

    if(!invModeActive && p.es_servicio == 0 && (currentQtyInCart + qty) > stockAvailable) {
        return showToast("Stock insuficiente (" + stockAvailable + " disp)", "error");
    }

    if(idx >= 0) {
        cart[idx].qty = Math.round((cart[idx].qty + qty) * 1000) / 1000; // evitar imprecisión de punto flotante
        selectedIndex = idx;
    } else {
        cart.push({ id: p.codigo, name: p.nombre, price: getPrice(p), qty: qty, discountPct: 0, note: '' });
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
    let totalQty = 0;
    const productLines = cart.length;
    
    if(cart.length === 0) {
        c.innerHTML = '<div class="text-center text-muted mt-5 pt-5"><i class="fas fa-shopping-basket fa-2x mb-2 opacity-25"></i><p class="small">Carrito Vacio</p></div>';
    }
    
    cart.forEach((i, idx) => {
        const lineT = (i.price * (1 - i.discountPct/100)) * i.qty;
        sub += lineT; 
        totalQty += i.qty;
        const d = document.createElement('div'); 
        d.className = 'cart-item' + (idx === selectedIndex ? ' selected' : '');
        d.onclick = () => { selectedIndex = idx; renderCart(); };
        
        let bdg = i.discountPct > 0 ? '<span class="discount-tag">-' + i.discountPct + '%</span>' : '';
        let src = getCartPriceOriginBadge();
        let nt = i.note ? '<span class="cart-note">📝 ' + i.note + '</span>' : '';
        
        d.innerHTML = '<div class="d-flex justify-content-between fw-bold"><span><button type="button" class="cart-remove-btn" aria-label="Eliminar producto" onclick="event.stopPropagation(); removeCartItem(' + idx + ')">−</button>' + i.qty + ' x ' + i.name + bdg + src + '</span><span>$' + lineT.toFixed(2) + '</span></div><div class="small text-muted">$' + (i.price*(1-i.discountPct/100)).toFixed(2) + '</div>' + nt;
        c.appendChild(d);
    });
    
    // Mantener siempre visible y centrado el item seleccionado
    requestAnimationFrame(() => {
        const selectedEl = c.querySelector('.cart-item.selected');
        if (!selectedEl) return;
        const targetTop = selectedEl.offsetTop - (c.clientHeight / 2) + (selectedEl.offsetHeight / 2);
        const maxTop = Math.max(0, c.scrollHeight - c.clientHeight);
        c.scrollTop = Math.max(0, Math.min(targetTop, maxTop));
    });

    const total = sub * (1 - globalDiscountPct/100);
    const lbl = document.getElementById('totalAmount');
    if (lbl) {
        if (globalDiscountPct > 0) {
            lbl.innerHTML = '<small class="text-muted fs-6"><s>$' + sub.toFixed(2) + '</s> -' + globalDiscountPct + '%</small><br>$' + total.toFixed(2);
        } else if (globalDiscountPct < 0) {
            lbl.innerHTML = '<small class="text-muted fs-6"><s>$' + sub.toFixed(2) + '</s> +' + Math.abs(globalDiscountPct) + '%</small><br>$' + total.toFixed(2);
        } else {
            lbl.innerText = '$' + total.toFixed(2);
        }
    }
    
    const itemsEl = document.getElementById('totalItems');
    if (itemsEl) itemsEl.innerText = totalQty;
    const linesEl = document.getElementById('totalLines');
    if (linesEl) linesEl.innerText = productLines;
}

// Imprime el contenido actual del carrito como oferta comercial, sin crear venta ni ticket.
window.printCartOffer = function() {
    if (!Array.isArray(cart) || cart.length === 0) {
        showToast('El carrito está vacío', 'warning');
        return;
    }

    const printWindow = window.open('', 'CartOfferPrint', 'width=380,height=700');
    if (!printWindow) {
        showToast('El navegador bloqueó la ventana de impresión', 'warning');
        return;
    }

    const safe = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const money = value => Number(value || 0).toFixed(2);
    const subtotal = cart.reduce((sum, item) => sum + (Number(item.price || 0) * (1 - Number(item.discountPct || 0) / 100) * Number(item.qty || 0)), 0);
    const total = subtotal * (1 - Number(globalDiscountPct || 0) / 100);
    const now = new Date();
    const fecha = now.toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' });
    const rows = cart.map(item => {
        const qty = Number(item.qty || 0);
        const unitPrice = Number(item.price || 0) * (1 - Number(item.discountPct || 0) / 100);
        const lineTotal = unitPrice * qty;
        const discount = Number(item.discountPct || 0) > 0 ? ` <small>-${money(item.discountPct)}%</small>` : '';
        const note = item.note ? `<div class="note">${safe(item.note)}</div>` : '';
        return `<div class="line">
            <div class="name">${safe(item.name)}${discount}</div>
            <div class="line-values"><span>${qty} x $${money(unitPrice)}</span><strong>$${money(lineTotal)}</strong></div>
            ${note}
        </div>`;
    }).join('');
    const globalDiscount = Number(globalDiscountPct || 0) !== 0
        ? `<div class="summary"><span>${Number(globalDiscountPct) > 0 ? 'Descuento' : 'Recargo'} ${Math.abs(Number(globalDiscountPct))}%</span><strong>${Number(globalDiscountPct) > 0 ? '-' : '+'}$${money(Math.abs(subtotal - total))}</strong></div>`
        : '';
    const clientName = (document.getElementById('cliName')?.value || '').trim();

    printWindow.onload = () => {
        setTimeout(() => {
            printWindow.print();
            printWindow.onafterprint = () => printWindow.close();
        }, 150);
    };
    printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Oferta comercial - Carrito</title>
        <style>
            @page { size: 58mm auto; margin: 0; }
            * { box-sizing: border-box; }
            html, body { width: 58mm; margin: 0; padding: 0; background: #fff; }
            body { color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 11px; padding: 3mm 2mm; }
            .center { text-align: center; }
            .title { font-size: 17px; font-weight: 900; letter-spacing: .5px; }
            .subtitle { font-size: 14px; font-weight: 800; margin-top: 2px; }
            .muted { font-size: 10px; margin-top: 4px; }
            .rule { border-top: 1px dashed #000; margin: 8px 0; }
            .line { padding: 4px 0; border-bottom: 1px dotted #777; }
            .name { font-weight: 700; overflow-wrap: anywhere; }
            .line-values, .summary { display: flex; justify-content: space-between; gap: 5px; margin-top: 2px; }
            .line-values span { white-space: nowrap; }
            .note { font-size: 9px; font-style: italic; margin-top: 2px; overflow-wrap: anywhere; }
            .summary { font-size: 12px; margin-top: 5px; }
            .total { display: flex; justify-content: space-between; align-items: baseline; font-size: 22px; font-weight: 900; margin-top: 8px; }
            .footer { text-align: center; font-size: 10px; margin-top: 12px; }
            @media print { body { padding: 3mm 2mm; } }
        </style></head><body>
            <div class="center title">OFERTA COMERCIAL</div>
            <div class="center subtitle">CARRITO</div>
            <div class="center muted">${safe(fecha)}</div>
            ${clientName ? `<div class="center muted">Cliente: ${safe(clientName)}</div>` : ''}
            <div class="rule"></div>
            ${rows}
            <div class="rule"></div>
            <div class="summary"><span>Subtotal</span><strong>$${money(subtotal)}</strong></div>
            ${globalDiscount}
            <div class="total"><span>TOTAL</span><span>$${money(total)}</span></div>
            <div class="rule"></div>
            <div class="footer">Documento sin número de ticket<br>No constituye una venta</div>
        </body></html>`);
    printWindow.document.close();
    printWindow.focus();
};

// Imprime el inventario actual, agrupado por categoría y nombre, para conteo manual.
window.printInventoryList = async function() {
    const printWindow = window.open('', 'InventoryPrint', 'width=380,height=700');
    if (!printWindow) {
        showToast('El navegador bloqueó la ventana de impresión', 'warning');
        return;
    }
    printWindow.document.write('<p style="font-family:Arial;padding:12px">Cargando inventario...</p>');
    try {
        const response = await fetch('pos.php?inventory_print_data=1', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.msg || 'Error');
        const safe = value => String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const quantity = value => Number(value || 0).toLocaleString('es', { maximumFractionDigits: 3 });
        let currentCategory = '';
        let rows = '';
        (data.products || []).forEach(product => {
            const category = product.categoria || 'General';
            if (category !== currentCategory) {
                currentCategory = category;
                rows += `<tr class="category"><td colspan="3">${safe(category)}</td></tr>`;
            }
            rows += `<tr><td class="name">${safe(product.nombre)}</td><td class="stock">${quantity(product.existencia)}</td><td class="real"></td></tr>`;
        });
        if (!rows) rows = '<tr><td colspan="3" class="empty">No hay productos activos</td></tr>';
        printWindow.document.open();
        printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Inventario</title>
            <style>
                @page { size: 58mm auto; margin: 0; } * { box-sizing: border-box; }
                html, body { width: 58mm; margin: 0; padding: 0; }
                body { color:#000; background:#fff; font-family:Arial,Helvetica,sans-serif; font-size:10px; padding:3mm 3mm 3mm 4mm; }
                .center { text-align:center; } .title { font-size:15px; font-weight:900; }
                .meta { font-size:9px; margin-top:2px; } .rule { border-top:1px dashed #000; margin:6px 0; }
                table { width:100%; border-collapse:collapse; table-layout:fixed; }
                th { border-bottom:1px solid #000; padding:3px 1px; font-size:9px; }
                td { border-bottom:1px dotted #888; padding:4px 1px; vertical-align:top; }
                th:first-child, td.name { width:56%; text-align:left; overflow-wrap:anywhere; }
                th.stock, td.stock { width:19%; text-align:right; } th.real, td.real { width:25%; }
                td.stock { font-size:13px; font-weight:900; padding-left:3px; padding-right:3px; }
                td.real { height:23px; }
                tr.category td { border-top:1px solid #000; border-bottom:1px solid #000; padding:5px 1px 3px; font-weight:900; text-transform:uppercase; }
                .empty { text-align:center; padding:12px 0; } .sign { margin-top:18px; text-align:center; font-size:9px; }
                .signature { margin:14px 4px 3px; border-top:1px solid #000; padding-top:3px; }
                @media print { body { padding:3mm 3mm 3mm 4mm; } }
            </style></head><body>
            <div class="center title">INVENTARIO</div><div class="center meta">${safe(data.empresa)}</div>
            <div class="center meta">Impreso: ${safe(data.fecha)}</div><div class="rule"></div>
            <table><thead><tr><th>Nombre</th><th class="stock">Exist.</th><th class="real">Real</th></tr></thead><tbody>${rows}</tbody></table>
            <div class="rule"></div><div class="sign">Conteo realizado por: ${safe(data.usuario)}</div>
            <div class="signature">Firma de quien hizo el conteo</div>
            <div class="center meta">Fecha y hora de impresión: ${safe(data.fecha)}</div>
            </body></html>`);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => { printWindow.print(); printWindow.onafterprint = () => printWindow.close(); }, 150);
    } catch (error) {
        printWindow.close();
        showToast('No se pudo cargar el inventario para imprimir', 'warning');
        console.error(error);
    }
};

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
                    badge.className = 'stock-badge ' + (invModeActive ? 'stock-inv' : 'stock-zero');
                    if (card) {
                        card.classList.add('stock-zero-card');
                        if (invModeActive) {
                            card.style.opacity = '1';
                            card.onclick = () => addToCart(p);
                            card.style.cursor = 'pointer';
                        } else {
                            card.style.opacity = '0.6';
                            card.onclick = () => { Synth.error(); showToast('Sin Stock', 'error'); };
                            card.style.cursor = 'not-allowed';
                        }
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
    const step = Number(d) || 0;
    if (step === 0) return;

    const item = cart[selectedIndex];
    const prod = productsDB.find(x => x.codigo == item.id);
    
    // Verificacion de stock al incrementar
    if(!invModeActive && step > 0 && prod && prod.es_servicio == 0 && (item.qty + step) > parseFloat(prod.stock)) {
        Synth.error();
        return showToast("Sin mas stock", "error");
    }
    
    item.qty += step; 
    if(item.qty <= 0) { 
        cart.splice(selectedIndex, 1); 
        selectedIndex = -1; 
        Synth.removeCart();
    } else {
        step > 0 ? Synth.increment() : Synth.decrement();
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

function removeCartItem(index) {
    if (index < 0 || index >= cart.length) return;
    Synth.removeCart();
    cart.splice(index, 1);
    selectedIndex = cart.length ? Math.min(selectedIndex, cart.length - 1) : -1;
    renderCart();
    saveCartState();
    updateStockBadges();
}

function clearCart() { 
    if(cart.length > 0 && confirm('Vaciar carrito?')) { 
        Synth.clear(); 
        cart = []; 
        globalDiscountPct = 0; 
        selectedIndex = -1; 
        renderCart();
        localStorage.removeItem(CART_STATE_KEY);
        updateStockBadges(); // Restaurar todo el stock visual
    } 
}

function askQty() { 
    openQtyModal();
}

function openQtyModal(mode = 'cart', product = null) {
    if (mode !== 'product' && selectedIndex < 0) return showToast("Seleccione producto", "warning");
    const input = document.getElementById('qtyModalInput');
    const nameEl = document.getElementById('qtyModalItemName');
    const qtyEl = document.getElementById('qtyModalCurrentQty');
    const modalEl = document.getElementById('qtyModal');
    if (!input || !nameEl || !qtyEl || !modalEl) return;

    qtyModalContext = {
        mode: mode === 'product' ? 'product' : 'cart',
        product: product || null
    };

    const item = qtyModalContext.mode === 'product'
        ? qtyModalGetProductCartItem(qtyModalContext.product)
        : cart[selectedIndex];
    const productName = qtyModalContext.mode === 'product'
        ? (qtyModalContext.product?.nombre || 'Producto')
        : (item?.name || 'Producto');
    const currentQty = qtyModalContext.mode === 'product'
        ? (item ? parseFloat(item.qty || 0) : 0)
        : parseFloat(item?.qty || 0);
    const rememberedQty = Number.isFinite(qtyModalLastValue) && qtyModalLastValue > 0 ? qtyModalLastValue : 1;

    nameEl.textContent = productName;
    qtyEl.textContent = Number(currentQty || 0).toLocaleString('es-ES', { maximumFractionDigits: 3 });
    input.value = currentQty > 0 ? String(currentQty) : String(rememberedQty);
    input.dataset.initialQty = String(currentQty);

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    const focusQtyInput = () => {
        input.focus({ preventScroll: true });
        if (typeof input.select === 'function') {
            try { input.select(); } catch (e) {}
        }
        if (input.type !== 'number' && typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(0, String(input.value || '').length);
        }
    };
    focusQtyInput();
    modalEl.addEventListener('shown.bs.modal', focusQtyInput, { once: true });
}

function qtyModalGetProductCartItem(product) {
    if (!product || !Array.isArray(cart)) return null;
    const code = product.codigo;
    return cart.find(i => i.id === code && (!i.note));
}

function confirmQtyModal() {
    const input = document.getElementById('qtyModalInput');
    const modalEl = document.getElementById('qtyModal');
    if (!input || !modalEl) return;

    const newQty = parseFloat(input.value);
    if (!Number.isFinite(newQty) || newQty <= 0) {
        return showToast('Cantidad inválida', 'warning');
    }
    qtyModalLastValue = newQty;
    localStorage.setItem('pos_last_qty_modal', String(qtyModalLastValue));

    const isProductMode = qtyModalContext.mode === 'product' && qtyModalContext.product;
    const item = isProductMode
        ? qtyModalGetProductCartItem(qtyModalContext.product)
        : (selectedIndex >= 0 ? cart[selectedIndex] : null);
    const prod = isProductMode
        ? qtyModalContext.product
        : (window.productsDB ? window.productsDB.find(x => x.codigo == item?.id) : null);

    if (!item && !isProductMode) {
        return showToast("Seleccione producto", "warning");
    }

    if (!invModeActive && prod && prod.es_servicio == 0 && newQty > parseFloat(prod.stock)) {
        if (typeof Synth !== 'undefined') Synth.error();
        return showToast("Sin más stock", "error");
    }

    if (isProductMode) {
        if (item) {
            const changed = Math.abs((parseFloat(item.qty) || 0) - newQty) > 0.00001;
            item.qty = newQty;
            if (prod) item.price = getPrice(prod);
            if (changed && typeof Synth !== 'undefined') Synth.increment();
            selectedIndex = cart.findIndex(i => i.id === item.id && (!i.note));
        } else if (prod) {
            if (typeof addToCart === 'function') {
                addToCart(prod, newQty);
            }
        }
    } else {
        const changed = Math.abs((parseFloat(item.qty) || 0) - newQty) > 0.00001;
        item.qty = newQty;
        if (changed && typeof Synth !== 'undefined') Synth.increment();
    }
    renderCart();
    saveCartState();
    updateStockBadges();
    bootstrap.Modal.getInstance(modalEl)?.hide();
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

function editPrice() {
    if (currentRole !== 'admin' && currentRole !== 'supervisor') {
        return showToast('Solo supervisores o administradores pueden modificar el precio', 'error');
    }
    if (selectedIndex < 0) return showToast('Seleccione un producto del carrito', 'warning');
    const item = cart[selectedIndex];
    const p = prompt('Nuevo precio para "' + item.name + '":', item.price);
    if (p === null) return;
    const v = parseFloat(p);
    if (!Number.isFinite(v) || v < 0) return showToast('Precio inválido', 'error');
    item.price = v;
    item.customPrice = true;
    renderCart();
    if (typeof Synth !== 'undefined' && Synth.discount) Synth.discount();
    saveCartState();
}

function applyGlobalDiscount() { 
    if(cart.length === 0) return; 
    let p = prompt("% Total (negativo = recargo):", globalDiscountPct); 
    if(p !== null) { 
        let v = parseFloat(p);
        if(!Number.isFinite(v) || v > 100) return;
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
const CART_EXPIRY_MS = 8 * 60 * 60 * 1000; // 8 horas — cubre jornada laboral completa

function saveCartState() {
    if (cart.length > 0) {
        const state = {
            cart: cart,
            globalDiscountPct: globalDiscountPct,
            selectedIndex: selectedIndex,
            timestamp: Date.now()
        };
        // Guardar en localStorage (síncrono, rápido)
        try { localStorage.setItem(CART_STATE_KEY, JSON.stringify(state)); } catch(e) {}
        // Backup en IndexedDB (más resistente a fallo eléctrico / crash de navegador)
        if (typeof window.posCache !== 'undefined' && window.posCache.db &&
            typeof window.posCache.saveCart === 'function') {
            window.posCache.saveCart(state).catch(() => {});
        }
    } else {
        try { localStorage.removeItem(CART_STATE_KEY); } catch(e) {}
        if (typeof window.posCache !== 'undefined' && window.posCache.db &&
            typeof window.posCache.clearCart === 'function') {
            window.posCache.clearCart().catch(() => {});
        }
    }
}

async function restoreCartState() {
    let state = null;

    // 1) Intentar localStorage primero (más rápido)
    try {
        const saved = localStorage.getItem(CART_STATE_KEY);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (Date.now() - parsed.timestamp < CART_EXPIRY_MS) {
                state = parsed;
            } else {
                localStorage.removeItem(CART_STATE_KEY);
            }
        }
    } catch(e) {}

    // 2) Fallback: IndexedDB (resistente a crash / fallo eléctrico)
    if (!state && typeof window.posCache !== 'undefined' &&
        typeof window.posCache.getCart === 'function') {
        try {
            const idbState = await window.posCache.getCart();
            if (idbState && Date.now() - (idbState.timestamp || 0) < CART_EXPIRY_MS) {
                state = idbState;
                console.log('[POS] Carrito restaurado desde IndexedDB tras fallo/reinicio');
            }
        } catch(e) {}
    }

    if (state && Array.isArray(state.cart) && state.cart.length > 0) {
        cart = state.cart;
        globalDiscountPct = state.globalDiscountPct || 0;
        selectedIndex = state.selectedIndex ?? -1;
        recalculateCartPrices();
        renderCart();
        showToast('Carrito restaurado (' + cart.length + ' ítem' + (cart.length > 1 ? 's' : '') + ')', 'info');
    }
}

// Restaurar carrito al cargar — esperar a que IndexedDB esté listo
document.addEventListener('DOMContentLoaded', () => {
    // Delay para dar tiempo a initPOSOffline (300ms) + IndexedDB init
    setTimeout(restoreCartState, 800);
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

async function checkLiveStockForCart(items) {
    if (!navigator.onLine) {
        return { ok: true, online: false, out: [] };
    }

    try {
        const resp = await fetch('pagos_api.php', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify({
                action: 'check_stock',
                items: items
            })
        });
        const data = await resp.json();
        return {
            ok: !!(data && data.all_ok),
            online: true,
            out: Array.isArray(data?.out) ? data.out : []
        };
    } catch (e) {
        return { ok: true, online: false, out: [] };
    }
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

// Bandera para evitar clics duplicados
let paymentProcessing = false;

window.confirmPaymentSafe = async function() {
    // Si ya se está procesando un pago, ignorar el clic
    if (paymentProcessing) {
        showToast('Procesando pago...', 'info');
        return;
    }

    // Marcar como procesando
    paymentProcessing = true;

    // Deshabilitar botón
    const btn = document.getElementById('btn-confirm-payment');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ PROCESANDO...';
    }

    try {
        // Llamar a la función original de pago
        await confirmPayment();
    } catch (e) {
        console.error('Error en pago:', e);
    } finally {
        // Restaurar botón y estado global siempre al terminar (independientemente de si el modal se cerró o no)
        paymentProcessing = false;
        
        const btn = document.getElementById('btn-confirm-payment');
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'CONFIRMAR PAGO';
        }
    }
};

async function confirmPayment() {
    let sub = cart.reduce((acc, i) => acc + ((i.price * (1 - i.discountPct/100)) * i.qty), 0);
    let tot = sub * (1 - globalDiscountPct/100);

    // Aviso preventivo: permite continuar, pero deja claro cuando el precio
    // efectivo de una línea queda por debajo de su costo actual.
    const ventasEnPerdida = [];
    const factorDescuentoGlobal = 1 - (Number(globalDiscountPct) || 0) / 100;
    cart.forEach(i => {
        const producto = (window.productsDB || []).find(p => String(p.codigo) === String(i.id));
        const costo = Number(producto?.costo || 0);
        const precioBase = Number(i.price || 0);
        const cantidad = Number(i.qty || 0);
        const descuentoItem = 1 - (Number(i.discountPct) || 0) / 100;
        const precioEfectivo = precioBase * descuentoItem * factorDescuentoGlobal;
        if (costo > 0 && cantidad > 0 && precioEfectivo < costo - 0.005) {
            ventasEnPerdida.push({
                nombre: i.name || i.id,
                cantidad,
                precio: precioEfectivo,
                costo,
                perdida: (costo - precioEfectivo) * cantidad
            });
        }
    });
    if (ventasEnPerdida.length > 0) {
        const detallePerdida = ventasEnPerdida.map(item =>
            `• ${item.nombre}: vende $${item.precio.toFixed(2)}, costo $${item.costo.toFixed(2)} ` +
            `(${item.cantidad} ud., pérdida estimada $${item.perdida.toFixed(2)})`
        ).join('\n');
        const perdidaTotal = ventasEnPerdida.reduce((sum, item) => sum + item.perdida, 0);
        const continuar = window.confirm(
            '⚠️ ADVERTENCIA: esta venta contiene productos vendidos por debajo del costo.\n\n' +
            detallePerdida +
            `\n\nPérdida estimada total: $${perdidaTotal.toFixed(2)}\n\n¿Desea continuar con el cobro?`
        );
        if (!continuar) return;
    }
    
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
    const itms = cart.map(i => ({
        id: i.id,
        name: i.name,
        qty: i.qty,
        price: i.price,
        discountPct: Number(i.discountPct || 0),
        customPrice: !!i.customPrice,
        note: i.note
    }));

    if (navigator.onLine) {
        const liveCheck = await checkLiveStockForCart(itms);
        if (!liveCheck.ok && liveCheck.out.length > 0) {
            const lista = liveCheck.out
                .map(p => `• ${p.nombre} [${p.id}]: disponible ${p.stock}, requerido ${p.needed}`)
                .join('\n');
            if (typeof refreshProducts === 'function') {
                await refreshProducts().catch(() => {});
            }
            showToast('Stock desactualizado', 'warning');
            alert(
                'No se puede cobrar porque el stock cambió o ya no existe suficiente inventario:\n\n' +
                lista +
                '\n\nSe actualizó el catálogo. Revisa el carrito y vuelve a intentar.'
            );
            return;
        }
    }

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
        id_empresa: parseInt((window.POS_ACTIVE_CONTEXT || {}).id_empresa || 1, 10),
        id_sucursal: parseInt((window.POS_ACTIVE_CONTEXT || {}).id_sucursal || 1, 10),
        id_almacen: parseInt((window.POS_ACTIVE_CONTEXT || {}).id_almacen || 1, 10),
        estado_pago: 'confirmado',
        descuentos_items:  descuentosItems,
        descuento_global:  globalDiscountPct,
        price_mode: priceMode,
        price_adjust_pct: priceAdjustPct
    };

    payModal.hide();

    // Guardar información para la pantalla del cliente (Vuelto)
    if(met === 'Efectivo') {
        const cashReceived = parseFloat(document.getElementById('cashReceived')?.value || 0);
        if(cashReceived > tot) {
            localStorage.setItem(LAST_PAYMENT_KEY, JSON.stringify({
                method: 'Efectivo',
                total: tot,
                received: cashReceived,
                change: cashReceived - tot,
                timestamp: Date.now()
            }));
        }
    }

    if (navigator.onLine) {
        try {
            const r = await fetch('pos_save.php', { 
                method: 'POST', 
                headers: getPosJsonHeaders(), 
                body: JSON.stringify(payload) 
            });
            const res = await r.json();
            
            if (res.status === 'success') {
                notifyOvernightCashSession(res);
                if(tot < 0) Synth.refund(); else Synth.cash();
                if(pr) window.open('/ticket_view.php?id=' + res.id, 'Ticket', 'width=380,height=600');
                else showToast('Venta #' + res.id + ' registrada');
                paymentProcessing = false;
                finishSale();
                await refreshProductStock([
                    ...itms.map(item => item.id),
                    ...(Array.isArray(res.affected_codes) ? res.affected_codes : [])
                ], { allowFullRefresh: true });
                return;
            } else {
                const errMsg = String(res.msg || '');
                if (/stock insuficiente/i.test(errMsg)) {
                    if (typeof refreshProducts === 'function') {
                        await refreshProducts().catch(() => {});
                    }
                    showToast('Stock insuficiente en tiempo real', 'error');
                    alert(errMsg + '\n\nSe actualizó el catálogo. Ajusta la cantidad y vuelve a cobrar.');
                    paymentProcessing = false;
                    return;
                }
                if (await handleSessionAuthFailure(res, () => confirmPayment(), 'Inicio de sesion requerido para registrar la venta.')) {
                    paymentProcessing = false;
                    return;
                }
                saveOffline(payload);
                paymentProcessing = false;
                return;
            }
        } catch (e) {
            saveOffline(payload);
            paymentProcessing = false;
            return;
        }
    } else {
        saveOffline(payload);
        paymentProcessing = false;
    }
}

function saveOffline(p) {
    const q = readStorageArray(QUEUE_KEY);
    if (!p || !p.uuid || !q.some(item => item && item.uuid === p.uuid)) {
        q.push(p);
    }
    localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
    
    if (typeof window.posCache !== 'undefined' && typeof window.posCache.saveOfflineSale === 'function') {
        window.posCache.saveOfflineSale(p).catch(e => console.error('Error guardando en IDB:', e));
    }
    try {
            if (window.PalWebNative && typeof window.PalWebNative.queueSale === 'function') {
            window.PalWebNative.queueSale(JSON.stringify(p));
        }
    } catch (e) { console.warn('No se pudo copiar venta a Room:', e); }
    
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
    localStorage.removeItem(CART_STATE_KEY);
    
    const fields = ['cliName', 'cliPhone', 'cliAddr', 'deliveryDriver', 'reservationDate', 'reservationAbono'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    
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
    localStorage.removeItem(CART_STATE_KEY);
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
function bindHistorialTicketFilters(body) {
    const filters = body?.querySelector('#historialFilters');
    if (!filters || filters._bound) return;
    filters._bound = true;

    const apply = () => {
        const query = String(filters.querySelector('[data-filter="query"]')?.value || '').trim().toLocaleLowerCase();
        const values = name => filters.querySelector(`[data-filter="${name}"]`)?.value || '';
        const type = values('type');
        const client = values('client');
        const status = values('status');
        const payment = values('payment');
        const cashier = values('cashier');
        const channel = values('channel');
        const dateFrom = values('date-from');
        const dateTo = values('date-to');
        let visible = 0;

        body.querySelectorAll('.historial-ticket-table tbody tr.ticket-row').forEach(row => {
            const d = row.dataset;
            const matches = (!query || String(d.search || '').toLocaleLowerCase().includes(query))
                && (!type || d.type === type) && (!client || d.client === client)
                && (!status || d.status === status) && (!payment || d.payment === payment)
                && (!cashier || d.cashier === cashier)
                && (!channel || d.channel === channel)
                && (!dateFrom || (d.date || '') >= dateFrom) && (!dateTo || (d.date || '') <= dateTo);
            row.classList.toggle('d-none', !matches);
            const detail = body.querySelector('#det-row-' + row.dataset.ticketId);
            if (detail) {
                detail.classList.toggle('d-none', !matches);
                if (!matches) detail.classList.remove('show');
            }
            if (matches) visible++;
        });

        const counter = filters.querySelector('[data-filter-count]');
        if (counter) counter.textContent = `${visible} ticket${visible === 1 ? '' : 's'} visibles`;
        const empty = body.querySelector('[data-filter-empty]');
        if (empty) empty.classList.toggle('d-none', visible !== 0);
    };

    const applyColumns = () => {
        filters.querySelectorAll('[data-column-toggle]').forEach(control => {
            const column = control.dataset.columnToggle;
            body.querySelectorAll(`[data-column="${column}"]`).forEach(cell => {
                cell.classList.toggle('d-none', !control.checked);
            });
        });
    };

    filters.addEventListener('input', apply);
    filters.addEventListener('change', apply);
    filters.querySelectorAll('[data-column-toggle]').forEach(control => control.addEventListener('change', applyColumns));
    body.querySelectorAll('[data-toggle-history-top]').forEach(button => button.addEventListener('click', () => {
        const topPanel = body.querySelector('#historialTopPanel');
        const showWrap = body.querySelector('.historial-show-top-wrap');
        const hide = !topPanel.classList.contains('d-none');
        topPanel.classList.toggle('d-none', hide);
        showWrap.classList.toggle('d-none', !hide);
    }));
    body.querySelectorAll('[data-toggle-payment-summary]').forEach(button => button.addEventListener('click', () => {
        const panel = body.querySelector('[data-payment-summary-panel]');
        if (!panel) return;
        const collapsed = panel.classList.toggle('d-none');
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.innerHTML = collapsed
            ? '<i class="fas fa-chevron-down me-1"></i>Expandir'
            : '<i class="fas fa-chevron-up me-1"></i>Recoger';
    }));
    filters.querySelector('[data-filter-reset]')?.addEventListener('click', () => {
        filters.querySelectorAll('input, select').forEach(control => { control.value = ''; });
        apply();
    });
    apply();
    applyColumns();
}

function renderHistorialModalFromJson(data) {
    const tickets = Array.isArray(data?.tickets) ? data.tickets : [];
    const detalles = Array.isArray(data?.detalles) ? data.detalles : [];
    const totales = data?.totales || {};
    const sesion = data?.sesion || {};
    const alertas = Array.isArray(data?.alertas) ? data.alertas : [];
    const metricas = data?.metricas_tickets || {};
    const detallesMap = detalles.reduce((acc, d) => {
        const key = String(d.id_venta_cabecera || '');
        if (!key) return acc;
        if (!acc[key]) acc[key] = [];
        acc[key].push(d);
        return acc;
    }, {});

    const totalVenta = Number(totales.total || 0);
    const totalTickets = Number(totales.count || tickets.filter(t => Number(t.total || 0) > 0).length);
    const totalDev = Number(totales.valor_devoluciones || 0);
    const totalNeto = tickets.reduce((acc, t) => {
        const total = Number(t.total || 0);
        return acc + (total > 0 ? total : 0);
    }, 0);

    const pagos = Object.assign({ efectivo: 0, transferencia: 0, tarjeta: 0, otros: 0 }, totales.pagos || {});
    const money = value => '$' + Number(value || 0).toLocaleString('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const ventasPorCajero = tickets.reduce((acc, ticket) => {
        const cajero = String(ticket.cajero_ticket || sesion.cajero || 'Sin asignar');
        if (!acc[cajero]) acc[cajero] = { tickets: 0, total: 0 };
        acc[cajero].tickets++;
        if (Number(ticket.total || 0) > 0) acc[cajero].total += Number(ticket.total || 0);
        return acc;
    }, {});
    const resumenCajeros = Object.entries(ventasPorCajero).map(([cajero, resumen]) => `<span class="badge bg-light text-dark border me-1 mb-1"><i class="fas fa-user me-1"></i>${escapeHtml(cajero)}: ${resumen.tickets} · ${money(resumen.total)}</span>`).join('');
    const optionList = (values, placeholder) => `<option value="">${placeholder}</option>${values.map(value => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join('')}`;
    const typeOptions = [...new Set(tickets.map(t => String(t.tipo_servicio || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const clientOptions = [...new Set(tickets.map(t => String(t.cliente_nombre || 'General').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const cashierOptions = [...new Set(tickets.map(t => String(t.cajero_ticket || sesion.cajero || 'Sin asignar').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const paymentOptions = [...new Set(tickets.map(t => String(t.metodo_pago || 'Mixto').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const channelOptions = [...new Set(tickets.map(t => String(t.canal_origen || 'POS').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const getTicketStatus = ticket => {
        const estadoPago = String(ticket.estado_pago || '').toLowerCase();
        const estadoReserva = String(ticket.estado_reserva || '').toLowerCase();
        if (ticket.motivo_anulacion || estadoPago.includes('anulad') || estadoReserva.includes('anulad')) return ['Anulado', 'bg-danger'];
        if (Number(ticket.total || 0) < 0 || String(ticket.cliente_nombre || '').toUpperCase() === 'DEVOLUCIÓN') return ['Devuelto', 'bg-warning text-dark'];
        if (estadoReserva.includes('reserv') || estadoPago.includes('reserv')) return ['Reservado', 'bg-info text-dark'];
        if (estadoPago.includes('pend') || estadoPago === '') return ['Pendiente', 'bg-secondary'];
        return ['Pagado', 'bg-success'];
    };
    const getLocation = ticket => {
        const sucursalId = Number(ticket.sucursal_ticket || ticket.id_sucursal || 0);
        const almacenId = Number(ticket.almacen_ticket || ticket.id_almacen || 0);
        const sessionId = Number(ticket.sesion_ticket || ticket.id_sesion_caja || ticket.id_caja || 0);
        const sucursal = ticket.sucursal_nombre || ('Sucursal ' + (sucursalId || '-'));
        const almacen = ticket.almacen_nombre || ('Almacén ' + (almacenId || '-'));
        const cajero = ticket.cajero_ticket || sesion.cajero || 'Sin asignar';
        return { sucursalId, almacenId, sessionId, html: `${escapeHtml(sucursal)}<br><small class="text-muted">${escapeHtml(almacen)} · Caja #${sessionId || '-'} · ${escapeHtml(cajero)}</small>` };
    };
    const sessionSummary = `Sesión #${Number(sesion.id || 0)} · ${escapeHtml(sesion.cajero || 'Sin cajero')} · ${escapeHtml(sesion.estado || '')}`;
    const alertHtml = alertas.map(alerta => `<div class="alert alert-warning d-flex align-items-start gap-2 mb-2 py-2 px-3 small"><i class="fas fa-triangle-exclamation mt-1"></i><div><strong>Revisión de sesión:</strong> ${escapeHtml(alerta.mensaje || '')}<br><span class="text-muted">Los tickets de otras sucursales o cajas no se modificarán desde este historial.</span></div></div>`).join('');

    const renderTicketDetailRows = (ticket) => {
        const rows = detallesMap[String(ticket.id)] || [];
        if (!rows.length) {
            return '<tr><td colspan="5" class="text-center text-muted py-3">Sin detalle disponible</td></tr>';
        }
        return rows.map(item => {
            const qty = Number(item.cantidad || 0);
            const price = Number(item.precio || 0);
            const total = qty * price;
            return `
                <tr>
                    <td>${escapeHtml(item.nombre || item.nombre_producto || 'Producto')}</td>
                    <td class="text-end fw-bold ${qty < 0 ? 'text-danger' : ''}">${Math.abs(qty)}</td>
                    <td class="text-end">$${Math.abs(price).toFixed(2)}</td>
                    <td class="text-end ${qty < 0 ? 'text-danger' : ''}">$${Math.abs(total).toFixed(2)}</td>
                    <td class="text-end">
                        ${qty < 0 ? '' : `<button class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size: 0.65rem;" onclick="refundItemFromHistorial(${item.id}, '${String(item.nombre || item.nombre_producto || '').replace(/'/g, "\\'")}')">DEVOLVER</button>`}
                    </td>
                </tr>`;
        }).join('');
    };

    const renderPaymentBadge = (ticket) => {
        const total = Number(ticket.total || 0);
        const mp = String(ticket.metodo_pago || 'Mixto').toLowerCase();
        let badgeClass = 'bg-dark text-white';
        if (total < 0) badgeClass = 'bg-refund-badge';
        else if (mp.includes('efectivo')) badgeClass = 'bg-efectivo';
        else if (mp.includes('transferencia')) badgeClass = 'bg-transfer';
        else if (mp.includes('tarjeta') || mp.includes('gasto')) badgeClass = 'bg-gasto';
        return `<span class="badge ${badgeClass} badge-pago" style="font-size:0.7rem">${escapeHtml(ticket.metodo_pago || 'Mixto')}</span>`;
    };

    const renderTicketRowClass = (ticket) => {
        const total = Number(ticket.total || 0);
        const mp = String(ticket.metodo_pago || 'Mixto').toLowerCase();
        if (total < 0) return 'row-refund';
        if (mp.includes('efectivo')) return 'row-efectivo';
        if (mp.includes('transferencia')) return 'row-transfer';
        if (mp.includes('tarjeta') || mp.includes('gasto')) return 'row-gasto';
        return 'row-mixto';
    };

    let html = `
        <style>
            .historial-ticket-table .ticket-row.ticket-otra-ubicacion { background-color:#fff3cd !important; border-left:5px solid #ffc107; }
            .historial-ticket-table .ticket-row td { vertical-align:middle; }
            @media (max-width: 767.98px) {
                .historial-ticket-table { font-size:.82rem !important; }
                .historial-ticket-table thead { display:none; }
                .historial-ticket-table, .historial-ticket-table tbody, .historial-ticket-table tr.ticket-row { display:block; width:100%; }
                .historial-ticket-table tr.ticket-row { margin:.55rem .5rem; width:calc(100% - 1rem); border:1px solid #dee2e6; border-radius:.6rem; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
                .historial-ticket-table tr.ticket-row td { display:flex; justify-content:space-between; align-items:center; gap:.75rem; border:0; border-bottom:1px solid #f0f0f0; padding:.42rem .65rem; text-align:right !important; }
                .historial-ticket-table tr.ticket-row td:first-child { display:none; }
                .historial-ticket-table tr.ticket-row td::before { content:attr(data-label); color:#6c757d; font-size:.68rem; font-weight:700; text-transform:uppercase; text-align:left; }
                .historial-ticket-table tr.ticket-row td:last-child { justify-content:flex-end; flex-wrap:wrap; border-bottom:0; }
                .historial-ticket-table tr.ticket-row td:last-child::before { width:100%; }
                .historial-ticket-table tr[id^="det-row-"] { display:table-row; margin:0; width:100%; border:0; box-shadow:none; }
                .historial-ticket-table tr[id^="det-row-"] td { display:table-cell; }
                .historial-ticket-table .ticket-location { max-width:68%; }
            }
        </style>
        <div id="historialTopPanel" class="sticky-top bg-white shadow-sm border-bottom z-index-2 p-3 historial-top-panel d-none">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div><div class="fw-bold"><i class="fas fa-cash-register text-info me-1"></i>${sessionSummary}</div><small class="text-muted">Sucursal ${Number(sesion.id_sucursal || 0)} · Almacén ${Number(sesion.id_almacen || 0)} · Apertura ${escapeHtml(String(sesion.fecha_apertura || '').slice(0, 16))}</small><div class="mt-1"><span class="small text-muted me-1">Ventas por cajero:</span>${resumenCajeros || '<span class="small text-muted">Sin datos</span>'}</div></div>
                <span class="badge ${String(sesion.estado).toUpperCase() === 'ABIERTA' ? 'bg-success' : 'bg-secondary'}">${escapeHtml(sesion.estado || 'SIN ESTADO')}</span>
            </div>
            ${alertHtml}
            <div id="historialFilters" class="border rounded-3 bg-light p-2 mb-3">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <span class="small fw-bold text-uppercase text-muted"><i class="fas fa-filter me-1"></i>Filtros del historial</span>
                    <span class="small text-muted" data-filter-count></span>
                </div>
                <div class="row g-2">
                    <div class="col-12 col-md-4"><input type="search" class="form-control form-control-sm" data-filter="query" placeholder="Ticket, cliente, producto, cajero..."></div>
                    <div class="col-6 col-md-2"><select class="form-select form-select-sm" data-filter="type">${optionList(typeOptions, 'Todos los tipos')}</select></div>
                    <div class="col-6 col-md-2"><select class="form-select form-select-sm" data-filter="client">${optionList(clientOptions, 'Todos los clientes')}</select></div>
                    <div class="col-6 col-md-2"><select class="form-select form-select-sm" data-filter="status">${optionList(['Pagado', 'Pendiente', 'Anulado', 'Devuelto', 'Reservado'], 'Todos los estados')}</select></div>
                    <div class="col-6 col-md-2"><select class="form-select form-select-sm" data-filter="payment">${optionList(paymentOptions, 'Todos los pagos')}</select></div>
                    <div class="col-6 col-md-2"><select class="form-select form-select-sm" data-filter="cashier">${optionList(cashierOptions, 'Todos los cajeros')}</select></div>
                    <div class="col-6 col-md-2"><select class="form-select form-select-sm" data-filter="channel">${optionList(channelOptions, 'Todos los canales')}</select></div>
                    <div class="col-6 col-md-2"><input type="date" class="form-control form-control-sm" data-filter="date-from" title="Desde"></div>
                    <div class="col-6 col-md-2"><input type="date" class="form-control form-control-sm" data-filter="date-to" title="Hasta"></div>
                    <div class="col-12 col-md-2"><button type="button" class="btn btn-sm btn-outline-secondary w-100" data-filter-reset><i class="fas fa-rotate-left me-1"></i>Limpiar</button></div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top">
                    <span class="small fw-bold text-muted"><i class="fas fa-eye-slash me-1"></i>Columnas:</span>
                    <label class="small"><input type="checkbox" data-column-toggle="type" checked> Tipo</label>
                    <label class="small"><input type="checkbox" data-column-toggle="items" checked> Artículos</label>
                    <label class="small"><input type="checkbox" data-column-toggle="status" checked> Estado</label>
                    <label class="small"><input type="checkbox" data-column-toggle="wholesale" checked> Mayorista</label>
                    <label class="small"><input type="checkbox" data-column-toggle="payment" checked> Pago</label>
                    <label class="small"><input type="checkbox" data-column-toggle="location" checked> Ubicación</label>
                    <button type="button" class="btn btn-sm btn-outline-dark ms-md-auto" data-toggle-history-top><i class="fas fa-compress me-1"></i>Solo tickets</button>
                </div>
            </div>
            <div class="row g-2 align-items-stretch">
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0 bg-light shadow-sm text-center p-2">
                        <div class="text-muted small fw-bold text-uppercase">Ventas Netas</div>
                        <div class="fs-4 fw-bold text-success">$${totalNeto.toFixed(2)}</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="card h-100 border-0 bg-light shadow-sm text-center p-2">
                        <div class="text-muted small fw-bold text-uppercase">Tickets</div>
                        <div class="fs-4 fw-bold text-dark">${totalTickets}</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="card h-100 border-0 bg-light shadow-sm text-center p-2">
                        <div class="text-muted small fw-bold text-uppercase">Devoluciones</div>
                        <div class="fs-4 fw-bold text-danger">$${totalDev.toFixed(2)}</div>
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <div class="card h-100 border-0 shadow-sm rounded-3 overflow-hidden">
                        <div class="bg-dark text-white py-1 px-2 fw-bold text-uppercase d-flex justify-content-between align-items-center" style="font-size:0.6rem; letter-spacing:1px;">
                            <span><i class="fas fa-wallet me-1 text-warning"></i> Resumen por tipo de pago</span>
                            <button type="button" class="btn btn-sm btn-link text-white text-decoration-none p-0" data-toggle-payment-summary aria-expanded="false">
                                <i class="fas fa-chevron-down me-1"></i>Expandir
                            </button>
                        </div>
                        <div class="p-0 d-none" data-payment-summary-panel>
                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 border-0">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size:0.65rem;"><i class="fas fa-receipt me-1"></i> Total tickets</span>
                                    <span class="fw-bold text-dark" style="font-size:0.9rem;">${tickets.length}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 border-0">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size:0.65rem;"><i class="fas fa-money-bill me-1"></i> Efectivo</span>
                                    <span class="fw-bold text-success" style="font-size:0.9rem;">${money(pagos.efectivo)}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 border-0">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size:0.65rem;"><i class="fas fa-building-columns me-1"></i> Transferencia</span>
                                    <span class="fw-bold text-primary" style="font-size:0.9rem;">${money(pagos.transferencia)}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 border-0">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size:0.65rem;"><i class="fas fa-credit-card me-1"></i> Tarjeta</span>
                                    <span class="fw-bold text-warning" style="font-size:0.9rem;">${money(pagos.tarjeta)}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 border-0">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size:0.65rem;"><i class="fas fa-ellipsis me-1"></i> Otros</span>
                                    <span class="fw-bold text-dark" style="font-size:0.9rem;">${money(pagos.otros)}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-2">
                <button type="button" class="btn btn-success btn-sm fw-bold" onclick="openHistorialWhatsappModal()">
                    <i class="fab fa-whatsapp me-1"></i> Enviar ZIP por WhatsApp
                </button>
            </div>
        </div>
        <div class="d-flex justify-content-end p-2 border-bottom historial-show-top-wrap">
            <button type="button" class="btn btn-sm btn-outline-info historial-show-top" data-toggle-history-top><i class="fas fa-expand me-1"></i>Mostrar estadísticas y filtros</button>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle historial-ticket-table" style="font-size:0.92rem;">
                <thead class="bg-light text-secondary">
                    <tr><th width="40"></th><th>ID</th><th>Fecha / hora</th><th>Cliente</th><th data-column="type">Tipo</th><th data-column="items">Artículos / unidades</th><th data-column="status">Estado</th><th>Total</th><th data-column="wholesale" class="text-warning">Mayorista</th><th data-column="payment">Pago</th><th data-column="location">Sucursal / almacén / caja / cajero</th><th class="text-end">Acción</th></tr>
                </thead>
                <tbody>`;

    if (!tickets.length) {
        html += '<tr><td colspan="12" class="text-center p-5 text-muted"><i class="fas fa-receipt fa-2x mb-3 opacity-50"></i><br>Sin movimientos</td></tr>';
    } else {
        tickets.forEach(t => {
            const total = Number(t.total || 0);
            const isRef = total < 0;
            const tDetalles = detallesMap[String(t.id)] || [];
            const wholesaleTotal = tDetalles.reduce((sum, d) => sum + (Number(d.cantidad || 0) * Number(d.precio_mayorista || d.precio || 0)), 0);

            const ticketRows = renderTicketDetailRows(t);
            const ticketType = escapeHtml(t.tipo_servicio || '-');
            const clientLabel = String(t.cliente_nombre || ('Ticket #' + t.id));
            const clientLabelJs = JSON.stringify(clientLabel);
            const location = getLocation(t);
            const [statusLabel, statusClass] = getTicketStatus(t);
            const itemCount = Number(metricas.articulos?.[String(t.id)] || tDetalles.length || 0);
            const unitCount = Number(metricas.unidades?.[String(t.id)] ?? tDetalles.reduce((sum, d) => sum + Number(d.cantidad || 0), 0));
            const otherLocation = location.sucursalId !== Number(sesion.id_sucursal || location.sucursalId) || location.almacenId !== Number(sesion.id_almacen || location.almacenId);
            const fechaTicket = String(t.fecha || '');
            const productSearch = tDetalles.map(item => item.nombre || item.nombre_producto || '').join(' ');
            const searchText = [t.id, t.cliente_nombre || 'General', productSearch, t.tipo_servicio || '', t.metodo_pago || '', t.canal_origen || 'POS', t.cajero_ticket || sesion.cajero || ''].join(' ');
            const dateOnly = fechaTicket.slice(0, 10);
            const partesFecha = fechaTicket.slice(0, 10).split('-');
            const fechaReducida = partesFecha.length === 3
                ? `${Number(partesFecha[2])}/${Number(partesFecha[1])}/${partesFecha[0].slice(-2)}`
                : '';
            const fechaHora = [fechaReducida, fechaTicket.slice(11, 16)].filter(Boolean).join(' ');
            html += `
                <tr class="${renderTicketRowClass(t)} ticket-row${otherLocation ? ' ticket-otra-ubicacion' : ''}" data-ticket-id="${t.id}" data-search="${escapeHtml(searchText)}" data-type="${escapeHtml(t.tipo_servicio || '')}" data-client="${escapeHtml(t.cliente_nombre || 'General')}" data-status="${escapeHtml(statusLabel)}" data-payment="${escapeHtml(t.metodo_pago || 'Mixto')}" data-channel="${escapeHtml(t.canal_origen || 'POS')}" data-cashier="${escapeHtml(t.cajero_ticket || sesion.cajero || 'Sin asignar')}" data-date="${escapeHtml(dateOnly)}" onclick="toggleDetail(${t.id})">
                    <td class="text-center"><i class="fas fa-chevron-right text-muted icon-collapse-${t.id}"></i></td>
                    <td class="fw-bold" data-label="Ticket">#${t.id}</td>
                    <td data-label="Fecha / hora">${escapeHtml(fechaHora)}</td>
                    <td data-label="Cliente">${escapeHtml(t.cliente_nombre || 'General')}</td>
                    <td data-column="type" data-label="Tipo"><small class="text-uppercase text-muted fw-bold" style="font-size:0.65rem;">${ticketType}</small></td>
                    <td data-column="items" data-label="Artículos / unidades"><span class="badge bg-light text-dark border">${itemCount} art. · ${Math.abs(unitCount)} ud.</span></td>
                    <td data-column="status" data-label="Estado"><span class="badge ${statusClass}">${statusLabel}</span></td>
                    <td data-label="Total" class="fw-bold ${isRef ? 'text-danger' : 'text-dark'}">$${Math.abs(total).toFixed(2)}</td>
                    <td data-column="wholesale" data-label="Mayorista" class="text-warning fw-bold">$${Math.abs(wholesaleTotal).toFixed(2)}</td>
                    <td data-column="payment" data-label="Pago">${renderPaymentBadge(t)}</td>
                    <td data-column="location" data-label="Sucursal / almacén / caja / cajero" class="ticket-location small">${location.html}</td>
                    <td data-label="Acción" class="text-end" onclick="event.stopPropagation()">
                        ${isRef ? '' : `<button class="btn btn-sm btn-success py-0 px-2 shadow-sm me-1" onclick="loadSaleToCart(${t.id})" title="Cargar pedido al carrito"><i class="fas fa-cart-plus"></i></button>`}
                        ${isRef ? '' : `<button class="btn btn-sm btn-secondary py-0 px-2 shadow-sm me-1" onclick="saveSaleAsTemplate(${t.id}, ${clientLabelJs})" title="Guardar como plantilla"><i class="fas fa-bookmark"></i></button>`}
                        ${isRef ? '' : `<button class="btn btn-sm btn-warning py-0 px-2 shadow-sm me-1" onclick="voidTicket(${t.id})" title="Anular con motivo (sesión activa)"><i class="fas fa-ban"></i></button>`}
                        ${isRef ? '' : `<button class="btn btn-sm btn-info py-0 px-2 shadow-sm me-1" onclick="openEditSale(${t.id})" title="Editar venta"><i class="fas fa-edit"></i></button>`}
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2 me-1" onclick="window.open('/ticket_view.php?id=${t.id}', 'Ticket', 'width=380,height=600')" title="Ver ticket">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${isRef ? '' : `<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="refundTicketComplete(${t.id})" title="Devolver ticket">
                            <i class="fas fa-undo"></i>
                        </button>`}
                    </td>
                </tr>
                <tr id="det-row-${t.id}" class="collapse bg-light">
                    <td colspan="12" class="p-0">
                        <div class="p-3">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th class="text-end">Cant.</th>
                                        <th class="text-end">Precio</th>
                                        <th class="text-end">Importe</th>
                                        <th class="text-end">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>${ticketRows}</tbody>
                            </table>
                            ${t.mensajero_nombre ? `<div class="mt-2 small alert alert-info py-1 px-2 mb-0"><i class="fas fa-motorcycle me-1"></i> Mensajero: <strong>${escapeHtml(t.mensajero_nombre)}</strong></div>` : ''}
                        </div>
                    </td>
                </tr>`;
        });
        html += '<tr data-filter-empty class="d-none"><td colspan="12" class="text-center p-4 text-muted"><i class="fas fa-filter-circle-xmark me-1"></i>Ningún ticket coincide con los filtros.</td></tr>';
    }

    html += `
                </tbody>
            </table>
        </div>

        <div class="modal fade" id="historialWhatsappModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-success text-white">
                        <h6 class="modal-title fw-bold"><i class="fab fa-whatsapp me-2"></i>Enviar facturas en ZIP</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="small text-muted mb-2">Se enviará un solo archivo ZIP que contiene una factura PDF por cada ticket de esta sesión.</div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Contacto WhatsApp</label>
                            <select id="historialWhatsappContacto" class="form-select form-select-sm">
                                <option value="">Cargando contactos...</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Mensaje</label>
                            <textarea id="historialWhatsappMensaje" class="form-control form-control-sm" rows="3">Adjunto las facturas PDF de la sesión en un archivo ZIP.</textarea>
                        </div>
                        <div id="historialWhatsappEstado" class="small text-muted"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-success btn-sm fw-bold" onclick="enviarHistorialPorWhatsApp()">
                            <i class="fab fa-whatsapp me-1"></i> Enviar ZIP
                        </button>
                    </div>
                </div>
            </div>
        </div>`;

    return html;
}

async function loadHistorialModalContent() {
    const body = document.getElementById('historialModalBody');
    if (!body) return;

    body.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Cargando historial...</p></div>';

    try {
        const historialUrl = window.POS_HISTORIAL_URL || 'pos_historial.php';
        const response = await fetch(historialUrl + '?t=' + Date.now(), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const data = await response.json();
        if (data && data.status === 'success') {
            body.innerHTML = renderHistorialModalFromJson(data);
            bindHistorialTicketFilters(body);
            return;
        }
        throw new Error(data?.message || 'No se pudo cargar el historial');
    } catch (error) {
        console.warn('Fallback historial modal_history.php:', error);
        try {
            const fallbackUrl = window.POS_HISTORIAL_FALLBACK_URL || 'modal_history.php?render_mode=1';
            const fallback = await fetch(fallbackUrl + '&t=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!fallback.ok) {
                throw new Error(`HTTP ${fallback.status}`);
            }
            const html = await fallback.text();
            body.innerHTML = html;
        } catch (fallbackError) {
            console.error('Error cargando historial:', fallbackError);
            body.innerHTML = '<div class="p-5 text-center text-danger"><i class="fas fa-wifi fa-2x mb-2"></i><br>Error de conexión al cargar historial.</div>';
        }
    }
}

let historialRefreshTimer = null;
let historialHoverActivo = false;
let historialScrollActivo = false;
let historialScrollResetTimer = null;

function historialTieneDetalleAbierto() {
    return !!document.querySelector('#historialModal .collapse.show');
}

function historialPuedeRefrescar() {
    return !historialHoverActivo && !historialScrollActivo && !historialTieneDetalleAbierto();
}

function updateHistorialRefreshState() {
    const stateEl = document.getElementById('historialRefreshState');
    if (!stateEl) return;
    stateEl.classList.toggle('d-none', historialPuedeRefrescar());
}

function startHistorialAutoRefresh() {
    stopHistorialAutoRefresh();
    updateHistorialRefreshState();
    historialRefreshTimer = setInterval(() => {
        const modalEl = document.getElementById('historialModal');
        if (!modalEl || !modalEl.classList.contains('show')) {
            stopHistorialAutoRefresh();
            return;
        }
        if (!historialPuedeRefrescar()) {
            updateHistorialRefreshState();
            return;
        }
        updateHistorialRefreshState();
        loadHistorialModalContent();
    }, 30000);
}

function stopHistorialAutoRefresh() {
    if (historialRefreshTimer) {
        clearInterval(historialRefreshTimer);
        historialRefreshTimer = null;
    }
    if (historialScrollResetTimer) {
        clearTimeout(historialScrollResetTimer);
        historialScrollResetTimer = null;
    }
    historialHoverActivo = false;
    historialScrollActivo = false;
    updateHistorialRefreshState();
}

function bindHistorialInteracciones() {
    const modalEl = document.getElementById('historialModal');
    const bodyEl = document.getElementById('historialModalBody');
    if (!modalEl || !bodyEl || modalEl._historialInteractionBound) return;

    modalEl._historialInteractionBound = true;

    bodyEl.addEventListener('mouseenter', function() {
        historialHoverActivo = true;
        updateHistorialRefreshState();
    });

    bodyEl.addEventListener('mouseleave', function() {
        historialHoverActivo = false;
        updateHistorialRefreshState();
    });

    bodyEl.addEventListener('scroll', function() {
        historialScrollActivo = true;
        updateHistorialRefreshState();
        if (historialScrollResetTimer) clearTimeout(historialScrollResetTimer);
        historialScrollResetTimer = setTimeout(() => {
            historialScrollActivo = false;
            historialScrollResetTimer = null;
            updateHistorialRefreshState();
        }, 1200);
    }, { passive: true });
}

function setHistorialRefreshLoading(isLoading) {
    const btn = document.getElementById('btnRefreshHistorial');
    if (!btn) return;

    btn.disabled = !!isLoading;
    const icon = btn.querySelector('i');
    const label = btn.querySelector('.refresh-label');
    if (icon) {
        icon.className = isLoading ? 'fas fa-circle-notch fa-spin me-1' : 'fas fa-sync-alt me-1';
    }
    if (label) {
        label.textContent = isLoading ? 'Actualizando' : 'Refrescar';
    }
}

window.refreshHistorialModal = async function() {
    const modalEl = document.getElementById('historialModal');
    if (modalEl && !bootstrap.Modal.getInstance(modalEl)) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    setHistorialRefreshLoading(true);
    try {
        await loadHistorialModalContent();
    } finally {
        setHistorialRefreshLoading(false);
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('historialModal');
    if (!modalEl || modalEl._historialAutoRefreshBound) return;

    modalEl._historialAutoRefreshBound = true;
    bindHistorialInteracciones();
    modalEl.addEventListener('shown.bs.modal', function() {
        loadHistorialModalContent();
        startHistorialAutoRefresh();
    });
    modalEl.addEventListener('hidden.bs.modal', function() {
        stopHistorialAutoRefresh();
        updateHistorialRefreshState();
    });
});

window.showHistorialModal = async function() {
    let modalEl = document.getElementById('historialModal');
    
    // Si el modal no existe en el DOM, intentar cargarlo o alertar
    if (!modalEl) {
        // En pos.php el modal suele estar al final, si no, lo creamos
        console.error('Modal historialModal no encontrado en el DOM');
        return;
    }
    
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
    await loadHistorialModalContent();
};

window.openHistorialWhatsappModal = async function() {
    const modalEl = document.getElementById('historialWhatsappModal');
    const select = document.getElementById('historialWhatsappContacto');
    const estado = document.getElementById('historialWhatsappEstado');
    if (!modalEl || !select) return;
    if (estado) estado.textContent = 'Cargando contactos...';
    try {
        const resp = await fetch('whatsapp_contacts.php', { cache: 'no-store', credentials: 'same-origin' });
        const data = await resp.json();
        const contactos = Array.isArray(data.contactos) ? data.contactos : [];
        select.innerHTML = '';
        if (!contactos.length) {
            select.innerHTML = '<option value="">No hay contactos con WhatsApp</option>';
        } else {
            select.innerHTML = '<option value="">Selecciona un contacto...</option>' +
                contactos.map(c => `<option value="${String(c.whatsapp || '').replace(/"/g, '&quot;')}">${String(c.nombre || 'Cliente')} (${String(c.whatsapp || '')})</option>`).join('');
        }
        if (estado) estado.textContent = '';
    } catch (e) {
        console.error('Error cargando contactos WhatsApp:', e);
        if (estado) estado.textContent = 'No se pudieron cargar los contactos.';
    }

    if (modalEl.parentElement !== document.body) {
        document.body.appendChild(modalEl);
    }

    const historialModalEl = document.getElementById('historialModal');
    const showWhatsapp = () => bootstrap.Modal.getOrCreateInstance(modalEl).show();

    if (historialModalEl) {
        const historialInst = bootstrap.Modal.getInstance(historialModalEl);
        if (historialInst && historialModalEl.classList.contains('show')) {
            historialModalEl.addEventListener('hidden.bs.modal', function onHidden() {
                historialModalEl.removeEventListener('hidden.bs.modal', onHidden);
                showWhatsapp();
            });
            historialInst.hide();
            return;
        }
    }

    showWhatsapp();
};

window.enviarHistorialPorWhatsApp = async function() {
    const modalEl = document.getElementById('historialWhatsappModal');
    const select = document.getElementById('historialWhatsappContacto');
    const mensaje = document.getElementById('historialWhatsappMensaje');
    const estado = document.getElementById('historialWhatsappEstado');
    if (!select || !estado) return;
    const whatsapp = String(select.value || '').trim();
    if (!whatsapp) {
        estado.textContent = 'Selecciona un contacto válido.';
        return;
    }
    const ids = Array.from(document.querySelectorAll('#historialModal [data-ticket-id]'))
        .map(el => parseInt(el.getAttribute('data-ticket-id'), 10))
        .filter(id => Number.isFinite(id) && id > 0);
    if (!ids.length) {
        estado.textContent = 'No hay tickets cargados para enviar.';
        return;
    }

    if (!confirm(`Se enviará un ZIP con ${ids.length} factura(s) PDF a ${whatsapp}. ¿Continuar?`)) {
        return;
    }

    estado.textContent = `Generando ZIP con ${ids.length} factura(s) PDF...`;
    try {
        const formData = new FormData();
        formData.append('ticket_ids', JSON.stringify(ids));
        formData.append('whatsapp', whatsapp);
        formData.append('mensaje', mensaje ? mensaje.value : '');
        const resp = await fetch('ticket_whatsapp_send.php?action=send_session_zip', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        const data = await resp.json();
        if (data.status !== 'success') {
            throw new Error(data.msg || 'No se pudo enviar el ZIP.');
        }
        estado.textContent = data.msg;
        setTimeout(() => {
            if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
        }, 1800);
    } catch (e) {
        console.error('Error enviando historial por WhatsApp:', e);
        estado.textContent = e.message || 'No se pudo enviar.';
    }
};

// --- FUNCIONES GLOBALES PARA EL HISTORIAL (AJAX COMPATIBLE) ---

let suppressClientAutoLoad = false;
window.setSuppressClientAutoLoad = function(value) {
    suppressClientAutoLoad = !!value;
};

function setClientSelectionValue(clienteNombre) {
    const cliSelect = document.getElementById('cliName');
    if (!cliSelect) return;

    const safeName = String(clienteNombre || '').trim();
    suppressClientAutoLoad = true;
    try {
        if (!safeName) {
            cliSelect.value = '';
            if (typeof fillClientData === 'function') fillClientData(cliSelect);
            return;
        }

        let option = Array.from(cliSelect.options).find(opt => opt.value === safeName);
        if (!option) {
            option = document.createElement('option');
            option.value = safeName;
            option.textContent = safeName;
            cliSelect.appendChild(option);
        }
        cliSelect.value = safeName;
        if (typeof fillClientData === 'function') fillClientData(cliSelect);
    } finally {
        setTimeout(() => { suppressClientAutoLoad = false; }, 150);
    }
}

function setOrderAuxFields(order) {
    window.pendingRepeatedOrderConfig = {
        cliente_nombre: order.cliente_nombre || '',
        tipo_servicio: order.tipo_servicio || 'mostrador',
        mensajero_nombre: order.mensajero_nombre || '',
        delivery_cost: parseFloat(order.delivery_cost || 0) || 0
    };

    setClientSelectionValue(order.cliente_nombre || '');

    const serviceType = document.getElementById('serviceType');
    if (serviceType && order.tipo_servicio) {
        serviceType.value = order.tipo_servicio;
        if (typeof toggleServiceOptions === 'function') toggleServiceOptions();
    }

    const driver = document.getElementById('deliveryDriver');
    if (driver) driver.value = order.mensajero_nombre || '';

    const deliveryCostInput = document.getElementById('deliveryCostInput');
    if (deliveryCostInput) {
        deliveryCostInput.value = (parseFloat(order.delivery_cost || 0) || 0).toFixed(2);
        if (typeof recalcDeliveryCost === 'function') recalcDeliveryCost();
    }
}

function buildCartFromOrder(order) {
    const missing = [];
    const lowStock = [];
    const nextCart = [];

    (order.items || []).forEach(item => {
        const codigo = String(item.codigo || item.id || '').trim();
        if (!codigo) return;

        const product = (window.productsDB || []).find(p => String(p.codigo) === codigo);
        const qty = parseFloat(item.qty || 0);
        if (!(qty > 0)) return;

        if (!product) {
            missing.push(item.name || codigo);
        }

        if (product && parseInt(product.es_servicio || 0, 10) !== 1) {
            const stock = parseFloat(product.stock || 0) || 0;
            if (qty > stock) {
                lowStock.push(`${item.name || product.nombre || codigo} (${qty} / disp ${stock})`);
            }
        }

        nextCart.push({
            id: codigo,
            name: item.name || (product ? product.nombre : codigo),
            price: parseFloat(item.price || (product ? getPrice(product) : 0)) || 0,
            qty: qty,
            discountPct: 0,
            note: item.note || ''
        });
    });

    return { nextCart, missing, lowStock };
}

window.loadOrderIntoCart = function(order, opts = {}) {
    if (!order || !Array.isArray(order.items) || order.items.length === 0) {
        showToast('Ese pedido no tiene productos', 'error');
        return false;
    }

    const replace = opts.replace !== false;
    const silent = opts.silent === true;

    if (replace && cart.length > 0 && !silent) {
        if (!confirm('¿Reemplazar el carrito actual con este pedido?')) {
            return false;
        }
    }

    const { nextCart, missing, lowStock } = buildCartFromOrder(order);
    if (nextCart.length === 0) {
        showToast('No se pudieron cargar productos válidos', 'error');
        return false;
    }

    cart = replace ? nextCart : cart.concat(nextCart);
    selectedIndex = cart.length > 0 ? 0 : -1;
    globalDiscountPct = 0;
    renderCart();
    saveCartState();
    updateStockBadges();
    setOrderAuxFields(order);

    if (missing.length) {
        showToast('Faltan productos del catálogo: ' + missing.slice(0, 2).join(', '), 'warning');
    } else if (lowStock.length) {
        showToast('Pedido cargado con alertas de stock', 'warning');
    } else {
        showToast((order.source_label || 'Pedido') + ' cargado al carrito', 'success');
    }

    return true;
};

async function fetchOrderPayload(url) {
    const resp = await fetch(url, { cache: 'no-store' });
    const data = await resp.json();
    if (data.status !== 'success' || !data.order) {
        throw new Error(data.msg || 'No se pudo cargar el pedido');
    }
    return data.order;
}

window.loadSaleToCart = async function(saleId) {
    try {
        const order = await fetchOrderPayload(`pos_order_templates.php?action=sale_payload&id=${saleId}`);
        window.loadOrderIntoCart(order);
    } catch (error) {
        showToast(error.message || 'Error cargando venta', 'error');
    }
};

window.saveSaleAsTemplate = async function(saleId, defaultName) {
    const tplName = prompt('Nombre de la plantilla:', (defaultName || `Pedido #${saleId}`).trim());
    if (!tplName) return;

    try {
        const resp = await fetch('pos_order_templates.php?action=save_template_from_sale', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify({ sale_id: saleId, name: tplName.trim() })
        });
        const data = await resp.json();
        if (data.status !== 'success') throw new Error(data.msg || 'No se pudo guardar la plantilla');
        showToast('Plantilla guardada', 'success');
    } catch (error) {
        showToast(error.message || 'Error guardando plantilla', 'error');
    }
};

function renderOrderTemplatesList(rows) {
    const list = document.getElementById('orderTemplatesList');
    if (!list) return;

    if (!rows.length) {
        list.innerHTML = '<div class="list-group-item text-muted small">No hay plantillas guardadas.</div>';
        return;
    }

    list.innerHTML = rows.map(row => {
        const cliente = escHtml(row.cliente_nombre || 'Sin cliente');
        const nombre = escHtml(row.nombre || `Plantilla #${row.id}`);
        const meta = `${row.items_count || 0} productos`;
        const saleTag = row.source_sale_id ? ` · base #${row.source_sale_id}` : '';
        return `
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-bold">${nombre}</div>
                        <div class="small text-muted">${cliente} · ${meta}${saleTag}</div>
                    </div>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-success" onclick="loadTemplateToCart(${row.id})" title="Cargar al carrito">
                            <i class="fas fa-cart-plus"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteOrderTemplate(${row.id})" title="Eliminar plantilla">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>`;
    }).join('');
};

window.refreshOrderTemplatesList = async function() {
    const list = document.getElementById('orderTemplatesList');
    if (list) list.innerHTML = '<div class="list-group-item text-muted small">Cargando plantillas...</div>';

    try {
        const resp = await fetch('pos_order_templates.php?action=list_templates', { cache: 'no-store' });
        const data = await resp.json();
        if (data.status !== 'success') throw new Error(data.msg || 'No se pudieron cargar las plantillas');
        renderOrderTemplatesList(Array.isArray(data.templates) ? data.templates : []);
    } catch (error) {
        if (list) list.innerHTML = `<div class="list-group-item text-danger small">${escHtml(error.message || 'Error cargando plantillas')}</div>`;
    }
};

window.loadTemplateToCart = async function(templateId) {
    try {
        const order = await fetchOrderPayload(`pos_order_templates.php?action=template_payload&id=${templateId}`);
        window.loadOrderIntoCart(order);
    } catch (error) {
        showToast(error.message || 'Error cargando plantilla', 'error');
    }
};

window.deleteOrderTemplate = async function(templateId) {
    if (!confirm('¿Eliminar esta plantilla?')) return;

    try {
        const resp = await fetch('pos_order_templates.php?action=delete_template', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify({ id: templateId })
        });
        const data = await resp.json();
        if (data.status !== 'success') throw new Error(data.msg || 'No se pudo eliminar');
        showToast('Plantilla eliminada', 'success');
        refreshOrderTemplatesList();
    } catch (error) {
        showToast(error.message || 'Error eliminando plantilla', 'error');
    }
};

async function populateRegularClientSelect() {
    const select = document.getElementById('regularClientSelect');
    if (!select) return;

    const current = select.value;
    select.innerHTML = '<option value="">Cargando clientes...</option>';

    const seen = new Set();
    const mergedClients = [];
    const addClient = (client) => {
        const name = String(client?.nombre || '').trim();
        if (!name) return;
        const key = name.toLowerCase();
        if (seen.has(key)) return;
        seen.add(key);
        mergedClients.push({
            nombre: name,
            telefono: String(client?.telefono || '').trim(),
            direccion: String(client?.direccion || '').trim(),
            last_sale_id: Number(client?.last_sale_id || 0),
            last_sale_date: String(client?.last_sale_date || '').trim(),
        });
    };

    try {
        const resp = await fetch('pos_order_templates.php?action=recent_clients&limit=50', { cache: 'no-store' });
        const data = await resp.json();
        if (data.status === 'success' && Array.isArray(data.clients)) {
            data.clients.forEach(addClient);
        }
    } catch (error) {
        console.warn('No se pudieron cargar clientes recientes', error);
    }

    (window.CLIENTS_DATA || []).forEach(addClient);

    const options = ['<option value="">Selecciona un cliente...</option>'];
    mergedClients.forEach(client => {
        let dateLabel = '';
        if (client.last_sale_date) {
            const rawDate = client.last_sale_date.substring(0, 10);
            const parts = rawDate.split('-');
            dateLabel = parts.length === 3 ? ` · ${parts[2]}/${parts[1]}/${parts[0]}` : ` · ${rawDate}`;
        }
        const saleLabel = client.last_sale_id > 0 ? ` · Últ. #${client.last_sale_id}${dateLabel}` : '';
        options.push(
            `<option value="${escHtml(client.nombre)}" data-tel="${escHtml(client.telefono)}" data-dir="${escHtml(client.direccion)}">${escHtml(client.nombre)}${escHtml(saleLabel)}</option>`
        );
    });
    select.innerHTML = options.join('');
    select.value = current && seen.has(String(current).trim().toLowerCase()) ? current : '';
}

window.openOrderTemplatesModal = function() {
    populateRegularClientSelect();
    refreshOrderTemplatesList();
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('orderTemplatesModal'));
    modal.show();
};

window.loadLastOrderForClient = async function(clientName, opts = {}) {
    const safeName = String(clientName || '').trim();
    if (!safeName) return false;

    try {
        const order = await fetchOrderPayload(`pos_order_templates.php?action=last_client&client_name=${encodeURIComponent(safeName)}`);
        return window.loadOrderIntoCart(order, opts);
    } catch (error) {
        showToast(error.message || 'Ese cliente no tiene pedido previo', 'warning');
        return false;
    }
};

window.loadLastOrderForSelectedClient = function(select) {
    const clientName = String(select?.value || '').trim();
    if (!clientName) return;
    window.loadLastOrderForClient(clientName, { replace: true, silent: false });
};

window.afterClientSelected = function(select) {
    if (suppressClientAutoLoad) return;

    const clientName = String(select?.value || '').trim();
    if (!clientName) return;

    const isFinalizarVentaModal = !!select?.closest?.('#paymentModal');
    if (isFinalizarVentaModal) {
        return;
    }

    if (cart.length === 0) {
        window.loadLastOrderForClient(clientName, { replace: true, silent: true });
        return;
    }

    if (confirm(`¿Cargar al carrito el último pedido de ${clientName}?`)) {
        window.loadLastOrderForClient(clientName, { replace: true, silent: true });
    }
};

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

    if (document.getElementById('historialModal')?.classList.contains('show') && !historialTieneDetalleAbierto()) {
        startHistorialAutoRefresh();
    }
};

async function getRefundAuthorization(actionLabel) {
    if (typeof window.requestPosRefundPin !== 'function') {
        showToast('No se pudo abrir la autorización de devoluciones.', 'error');
        return null;
    }
    const pin = await window.requestPosRefundPin(actionLabel);
    return pin ? { pin_maestro_devolucion: pin } : null;
}

async function submitRefundRequest(payload) {
    const send = async () => {
        const response = await fetch('pos_refund.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getPosJsonHeaders(),
            body: JSON.stringify(payload)
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    };

    try {
        return { data: await send(), retried: false };
    } catch (firstError) {
        // Si el servidor terminó pero se perdió la respuesta, el segundo
        // intento recibe la respuesta idempotente y no duplica la devolución.
        await new Promise(resolve => setTimeout(resolve, 500));
        return { data: await send(), retried: true };
    }
}

window.refundTicketComplete = async function(ticketId) {
    if(!confirm(`¿DEVOLVER TICKET #${ticketId} COMPLETO?`)) return;
    const auth = await getRefundAuthorization(`devolver ticket #${ticketId}`);
    if (!auth) return;
    try {
        const refundResult = await submitRefundRequest({ ticket_id: ticketId, ...auth });
        const d = refundResult.data;
        if(d.status === 'success') { 
            alert(d.already_processed && !refundResult.retried
                ? 'La devolución ya estaba registrada.'
                : 'Éxito: Ticket devuelto.');
            if(typeof Synth !== 'undefined') Synth.refund();
            // La devolución ya fue confirmada. Si falla la actualización visual,
            // no se debe convertir una operación exitosa en “error de red”.
            try {
                if (typeof refreshProductStock === 'function' && Array.isArray(d.affected_codes)) {
                    await refreshProductStock(d.affected_codes, { allowFullRefresh: true });
                }
                await showHistorialModal(); // Recargar
            } catch (refreshError) {
                console.warn('Devolución confirmada; no se pudo refrescar la vista:', refreshError);
            }
        } else { 
            alert('Error: ' + d.msg); 
        }
    } catch(e) { 
        alert('No se pudo confirmar la respuesta de la devolución. Actualice el historial para verificar si fue registrada.');
    }
};

window.refundItemFromHistorial = async function(detailId, prodName) {
    if(!confirm(`¿Devolver lote de: ${prodName}?`)) return;
    const auth = await getRefundAuthorization(`devolver el producto ${prodName}`);
    if (!auth) return;
    try {
        const refundResult = await submitRefundRequest({ id: detailId, ...auth });
        const d = refundResult.data;
        if(d.status === 'success') { 
            alert(d.already_processed && !refundResult.retried
                ? 'La devolución ya estaba registrada.'
                : 'Producto devuelto');
            try {
                if (typeof refreshProductStock === 'function' && Array.isArray(d.affected_codes)) {
                    await refreshProductStock(d.affected_codes, { allowFullRefresh: true });
                }
                await showHistorialModal();
            } catch (refreshError) {
                console.warn('Devolución confirmada; no se pudo refrescar la vista:', refreshError);
            }
        } else { 
            alert(d.msg); 
        }
    } catch(e) { 
        alert('No se pudo confirmar la respuesta de la devolución. Actualice el historial para verificar si fue registrada.');
    }
};

// ─── ANULACIÓN DE VENTA (Void) ─────────────────────────────────────────────────
// Diferente a la devolución: PIN-based, solo sesión activa, requiere motivo.

window.voidTicket = function(ticketId) {
    const motEl = document.getElementById('voidMotivo');
    const userEl = document.getElementById('voidAuthUser');
    const passEl = document.getElementById('voidAuthPass');
    const idEl  = document.getElementById('voidTicketId');
    if (!motEl || !userEl || !passEl || !idEl) {
        return alert('Modal de anulación no disponible en esta página');
    }
    idEl.textContent = ticketId;
    motEl.value = '';
    userEl.value = '';
    passEl.value = '';
    window._voidTicketId = ticketId;
    const voidModalEl = document.getElementById('voidModal');
    if (voidModalEl) new bootstrap.Modal(voidModalEl).show();
};

window.confirmVoid = async function() {
    const motivo = (document.getElementById('voidMotivo')?.value || '').trim();
    const authUser = (document.getElementById('voidAuthUser')?.value || '').trim();
    const authPass = (document.getElementById('voidAuthPass')?.value || '').trim();

    if (motivo.length < 5) return alert('El motivo debe tener al menos 5 caracteres');
    if (!authUser || !authPass) return alert('Ingrese usuario y contraseña del sistema');

    const btn = document.querySelector('#voidModal .btn-void-confirm');
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Anulando...'; }

    try {
        const r = await fetch('pos_void.php', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify({ id_venta: window._voidTicketId, motivo, auth_user: authUser, auth_pass: authPass }),
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
        
        const resp = await fetch(window.POS_PING_URL || 'pos.php?netcheck=1', {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        
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
                    clearSearchAndRefreshProductList(false);
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
    entrada:      { title: 'Recepción de Mercancía',   icon: 'fa-truck-loading', color: 'success'  },
    merma:        { title: 'Registrar Merma',           icon: 'fa-trash-alt',     color: 'danger'   },
    ajuste:       { title: 'Ajuste de Existencias',    icon: 'fa-sliders-h',     color: 'warning'  },
    conteo:       { title: 'Conteo Físico',             icon: 'fa-barcode',       color: 'info'     },
    transferencia:{ title: 'Transferencia a Sucursal', icon: 'fa-exchange-alt',  color: 'primary'  },
    consultar:    { title: 'Consultar Existencias',    icon: 'fa-search',         color: 'secondary'},
};

let invModeActive = false;

window.toggleInventarioMode = function() {
    invModeActive = !invModeActive;

    const posPanel  = document.getElementById('posPanel');
    const invPanel  = document.getElementById('inventarioPanel');
    const btnInv    = document.getElementById('btnInventario');
    const banner    = document.getElementById('invModeBanner');

    if (posPanel) posPanel.style.display = invModeActive ? 'none'  : '';
    if (invPanel) invPanel.style.display = invModeActive ? 'block' : 'none';
    if (btnInv)   btnInv.classList.toggle('inv-active', invModeActive);
    if (banner)   banner.style.display   = invModeActive ? '' : 'none';
    document.body.classList.toggle('inventory-mode-active', invModeActive);

    if (invModeActive) {
        // Limpiar carrito al entrar en modo inventario
        cart = [];
        renderCart();
        showToast('Modo inventario activo — todos los productos están disponibles', 'warning');
    } else {
        cart = [];
        renderCart();
    }
    // Refrescar productos: en modo inv se muestran todos (incluye stock=0)
    renderProducts();
};

// En móvil permite aplicar explícitamente el texto escrito al catálogo de inventario.
window.forceInventorySearch = function() {
    const input = document.getElementById('searchInput');
    renderProducts('all', input ? input.value : '');
    if (input) input.focus();
};

window.openInvModal = function(tipo) {
    if (cart.length === 0) {
        showToast('Agrega productos al carrito primero', 'warning');
        return;
    }
    window.currentInvTipo = tipo;
    const cfg = INV_CONFIG[tipo];

    document.getElementById('invModalTitle').innerHTML =
        `<i class="fas ${cfg.icon} me-2 text-${cfg.color}"></i>${cfg.title}`;

    // Tabla resumen del carrito
    const rows = cart.map(i =>
        `<tr>
            <td class="small text-muted">${i.id}</td>
            <td class="small fw-bold">${i.name}</td>
            <td class="text-center fw-bold text-primary fs-6">${i.qty}</td>
         </tr>`
    ).join('');
    document.getElementById('invCartSummary').innerHTML =
        `<div class="small text-secondary fw-bold mb-1">${cart.length} producto(s) en el carrito:</div>
         <table class="table table-sm table-bordered mb-0">
            <thead class="table-dark"><tr>
                <th class="small">SKU</th><th class="small">Producto</th>
                <th class="text-center small">Cantidad</th>
            </tr></thead>
            <tbody>${rows}</tbody>
         </table>`;

    // Fecha por defecto hoy
    document.getElementById('invFechaInput').value =
        new Date().toISOString().split('T')[0];
    document.getElementById('invMotivoInput').value = '';

    // Mostrar/ocultar destino (solo transferencia)
    document.getElementById('invDestinoCol').style.display = tipo === 'transferencia' ? '' : 'none';
    if (tipo === 'transferencia')
        document.getElementById('invDestinoInput').value = '';

    // Para conteo: cargar comparación con BD
    const conteoInfo = document.getElementById('invConteoInfo');
    conteoInfo.innerHTML = '';
    conteoInfo.style.display = 'none';
    if (tipo === 'conteo') invCargarConteo();

    bootstrap.Modal.getOrCreateInstance(document.getElementById('invModal')).show();
    setTimeout(() => document.getElementById('invMotivoInput').focus(), 350);
};

// Carga comparación conteo vs BD
window.invCargarConteo = async function() {
    const infoEl = document.getElementById('invConteoInfo');
    infoEl.style.display = '';
    infoEl.innerHTML = '<div class="text-center py-2 small"><i class="fas fa-spinner fa-spin me-1"></i>Comparando con base de datos...</div>';
    try {
        const r = await fetch('pos.php?inventario_api=1', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify({ accion: 'consultar_bulk', skus: cart.map(i => i.id) }),
        });
        const d = await r.json();
        if (d.status !== 'success') throw new Error(d.msg);
        const rows = cart.map(item => {
            const enBD  = d.stocks[item.id] ?? 0;
            const diff  = item.qty - enBD;
            const cls   = diff === 0 ? 'text-success' : (diff > 0 ? 'text-warning' : 'text-danger');
            const label = diff === 0 ? '<i class="fas fa-check"></i>' : (diff > 0 ? `+${diff}` : `${diff}`);
            return `<tr>
                <td class="small">${item.id}</td>
                <td class="small">${item.name}</td>
                <td class="text-center">${enBD}</td>
                <td class="text-center fw-bold">${item.qty}</td>
                <td class="text-center fw-bold ${cls}">${label}</td>
            </tr>`;
        }).join('');
        infoEl.innerHTML =
            `<div class="small text-secondary fw-bold mb-1">Comparación conteo físico vs sistema:</div>
             <table class="table table-sm table-bordered mb-0 small">
                <thead class="table-warning"><tr>
                    <th>SKU</th><th>Producto</th>
                    <th class="text-center">En BD</th>
                    <th class="text-center">Contado</th>
                    <th class="text-center">Diferencia</th>
                </tr></thead>
                <tbody>${rows}</tbody>
             </table>`;
    } catch(e) {
        infoEl.innerHTML = '<div class="alert alert-warning py-2 small mb-0">No se pudo obtener datos de BD</div>';
    }
};

window.invConfirmar = async function() {
    const motivo  = document.getElementById('invMotivoInput').value.trim();
    const fecha   = document.getElementById('invFechaInput').value;
    const tipo    = window.currentInvTipo;

    if (!motivo) return showToast('El motivo es obligatorio', 'warning');
    if (tipo === 'transferencia') {
        const dest = (document.getElementById('invDestinoInput')?.value || '').trim();
        if (!dest) return showToast('Indica la sucursal destino', 'warning');
    }

    const payload = {
        accion:   tipo,
        items:    cart.map(i => ({ sku: i.id, cantidad: i.qty })),
        fecha,
        motivo,
        usuario:  currentCashier || 'POS-Admin',
        destino:  (document.getElementById('invDestinoInput')?.value || '').trim() || undefined,
    };

    const btn = document.getElementById('btnInvConfirmar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Procesando...';

    try {
        const r = await fetch('pos.php?inventario_api=1', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.status === 'success') {
            showToast(d.msg, 'success');
            bootstrap.Modal.getInstance(document.getElementById('invModal')).hide();
            // Actualizar stock local inmediato para feedback visual instantáneo.
            if (d.stocks_updated) {
                d.stocks_updated.forEach(s => {
                    const p = productsDB.find(x => x.codigo === s.sku);
                    if (p) p.stock = s.nuevo_stock;
                });
            }
            cart = [];
            renderCart();
            renderProducts();
            updateStockBadges();
            // Refrescar catálogo completo desde servidor para dejar lista/stock 100% sincronizados.
            await refreshProducts();
        } else {
            console.error('[POS INVENTARIO] Error del servidor:', d);
            if (await handleSessionAuthFailure(d, () => invConfirmar(), 'Inicio de sesion requerido para completar la operacion de inventario.')) {
                return;
            }
            showToast(d.msg || 'Error', 'error');
        }
    } catch(e) {
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Confirmar';
    }
};

console.log('POS.js v3.7 cargado');

window.openBarcodeModal = function() {
    if (cart.length === 0 || selectedIndex < 0) {
        showToast('Seleccione un producto en el carrito primero', 'warning');
        return;
    }
    const item = cart[selectedIndex];
    const prod = productsDB.find(p => p.codigo == item.id);
    if (!prod) {
        showToast('Producto no encontrado en base de datos local', 'error');
        return;
    }

    document.getElementById('barcodeProductName').innerText = prod.nombre;
    document.getElementById('barcodeProductSku').innerText = 'SKU: ' + prod.codigo;
    document.getElementById('barcodeInput1').value = prod.codigo_barra_1 || '';
    document.getElementById('barcodeInput2').value = prod.codigo_barra_2 || '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('barcodeModal')).show();
    setTimeout(() => document.getElementById('barcodeInput1').focus(), 350);
};

window.saveBarcodes = async function() {
    const item = cart[selectedIndex];
    const b1 = document.getElementById('barcodeInput1').value.trim();
    const b2 = document.getElementById('barcodeInput2').value.trim();

    const btn = document.getElementById('btnSaveBarcodes');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Guardando...';

    try {
        const payload = {
            accion: 'update_barcodes',
            sku: item.id,
            barcode: b1,
            barcode2: b2
        };

        const r = await fetch('pos.php?inventario_api=1', {
            method: 'POST',
            headers: getPosJsonHeaders(),
            body: JSON.stringify(payload)
        });
        const d = await r.json();

        if (d.status === 'success') {
            showToast('Códigos actualizados correctamente', 'success');
            // Actualizar en DB local
            const prod = productsDB.find(p => p.codigo == item.id);
            if (prod) {
                prod.codigo_barra_1 = b1;
                prod.codigo_barra_2 = b2;
            }
            bootstrap.Modal.getInstance(document.getElementById('barcodeModal')).hide();
            // Opcionalmente refrescar productos si los códigos se muestran en la UI
        } else {
            if (await handleSessionAuthFailure(d, () => saveBarcodes(), 'Inicio de sesion requerido para actualizar codigos de barras.')) {
                return;
            }
            showToast(d.msg || 'Error al guardar', 'error');
        }
    } catch(e) {
        console.error(e);
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Guardar';
    }
};
