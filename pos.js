// ARCHIVO: /var/www/palweb/api/pos.js
// VERSION: 4.5 - CORE LOGIC ONLY (Limpiado de modales)

const CACHE_KEY = 'products_cache_v1';
const QUEUE_KEY = 'pos_pending_sales';
const PARKED_ORDERS_KEY = 'pos_parked_orders';

// GLOBALES
window.productsDB = []; 
let productsDB = window.productsDB;
let cart = []; 
let selectedIndex = -1; 
let enteredPin = "";
let currentCashier = "Cajero"; 
let cashId = 0; 
let cashOpen = false;
let globalDiscountPct = 0;
window.stockFilterActive = false;

// AUDIO
const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
const Synth = {
    playTone: (f,t,d,v=0.1) => { try{if(audioCtx.state==='suspended')audioCtx.resume();const o=audioCtx.createOscillator();const g=audioCtx.createGain();o.type=t;o.frequency.value=f;g.gain.value=v;g.gain.exponentialRampToValueAtTime(0.001,audioCtx.currentTime+d);o.connect(g);g.connect(audioCtx.destination);o.start();o.stop(audioCtx.currentTime+d);}catch(e){} },
    beep: ()=>Synth.playTone(1200,'sine',0.1, 0.12), error: ()=>Synth.playTone(150,'sawtooth',0.3, 0.15), click: ()=>Synth.playTone(800,'triangle',0.05, 0.08),
    cash: ()=>Synth.playTone(1500,'sine',0.2), tada: ()=>Synth.playTone(1000,'sine',0.2), 
    category: ()=>Synth.playTone(600,'sine',0.05, 0.05),
    addCart: ()=>Synth.playTone(1000,'sine',0.06, 0.1), removeCart: ()=>Synth.playTone(500,'sine',0.08, 0.12),
    increment: ()=>Synth.playTone(850,'sine',0.04, 0.07), decrement: ()=>Synth.playTone(650,'sine',0.04, 0.07),
    clear: ()=>Synth.playTone(400,'sine',0.08, 0.1),
    openCash: ()=>Synth.playTone(1000,'sine',0.15, 0.12), closeCash: ()=>Synth.playTone(600,'sine',0.15, 0.12),
    discount: ()=>Synth.playTone(900,'sine',0.08, 0.1),
    refund: ()=>Synth.playTone(300,'sawtooth',0.4, 0.15)
};

// INIT
document.addEventListener('DOMContentLoaded', () => {
    console.log('Iniciando POS v4.5 Core...');
    if (typeof PRODUCTS_DATA !== 'undefined' && Array.isArray(PRODUCTS_DATA) && PRODUCTS_DATA.length > 0) { 
        window.productsDB = PRODUCTS_DATA;
        productsDB = window.productsDB;
        saveToCache(productsDB); 
        initCatalog();
    } else { 
        loadFromCacheOrRefresh();
    }
    
    updatePinDisplay();
    document.addEventListener('keydown', handleBarcodeScanner);
    checkCashStatusSilent();
    restoreCartState();
    
    // NetStatus
    checkNetworkSpeed();
    setInterval(checkNetworkSpeed, 10000);

    if(navigator.onLine) syncAllPending();
    window.addEventListener('online', syncAllPending);

    // Restaurar estado de barras
    if(localStorage.getItem('posBarsHidden') === '1') {
        document.body.classList.add('pos-bars-hidden');
        const btn = document.getElementById('btnToggleBars');
        if(btn) btn.classList.add('btn-filter-active');
    }
});

function initCatalog() { renderCategories(); renderProducts('all'); }

// NETSTATUS
window.checkNetworkSpeed = async function() {
    const start = Date.now();
    try { await fetch('pos2.php?ping=1', {cache: "no-store"}); const duration = Date.now() - start; updateNetBadge(duration); } 
    catch (e) { updateNetBadge(9999); }
};
window.updateNetBadge = function(ms) {
    const el = document.getElementById('netStatus'); if(!el) return;
    let icon = '🚀', color = 'bg-success';
    if(ms < 150) { icon = '🐆'; color = 'bg-success'; } 
    else if(ms < 500) { icon = '🐇'; color = 'bg-success'; } 
    else if(ms < 1000) { icon = '🐢'; color = 'bg-warning text-dark'; } 
    else { icon = '🐌'; color = 'bg-danger'; }
    if(ms === 9999) { icon = '💀'; color = 'bg-dark'; ms = 'OFF'; }
    el.className = `badge ${color}`; el.innerHTML = `${icon} ${ms}ms`;
};

// CASH STATUS
window.checkCashStatusSilent = async function() {
    try {
        const r = await fetch('pos_cash.php?action=status'); const d = await r.json();
        const badge = document.getElementById('cashStatusBadge');
        if(d.status === 'open') { 
            cashOpen = true; cashId = d.data.id; 
            badge.className = 'cash-status cash-open ms-2';
            const fecha = d.data.fecha_contable || 'HOY';
            badge.innerText = `ABIERTA (${fecha})`;
            document.getElementById('cashierName').innerText = d.data.nombre_cajero || currentCashier;
        } else { 
            cashOpen = false; badge.className = 'cash-status cash-closed ms-2'; badge.innerText = 'CERRADA'; 
        }
    } catch(e){}
};

// CATALOGO
window.renderCategories = function() {
    const container = document.getElementById('categoryBar');
    if (!container || container.children.length > 1) return;
    const cats = [...new Set(productsDB.map(p => p.categoria).filter(c => c))].sort();
    let html = '<button class="category-btn active" onclick="filterCategory(\'all\', this)">TODOS</button>';
    cats.forEach(c => { html += `<button class="category-btn" onclick="filterCategory('${c.replace(/'/g, "\\'")}', this)">${c}</button>`; });
    container.innerHTML = html;
};

window.renderProducts = function(cat, term) {
    const grid = document.getElementById('productContainer'); if (!grid) return;
    grid.innerHTML = '';
    if (typeof term === 'undefined') { const input = document.getElementById('searchInput'); term = input ? input.value.toLowerCase().trim() : ''; } else { term = term.toLowerCase().trim(); }
    const list = productsDB.filter(p => {
        const mCat = cat === 'all' || !cat || p.categoria === cat;
        const mSearch = !term || (p.nombre && p.nombre.toLowerCase().includes(term)) || (p.codigo && p.codigo.toLowerCase().includes(term));
        const stock = parseFloat(p.stock) || 0;
        const mStock = !window.stockFilterActive || stock > 0 || p.es_servicio == 1;
        return mCat && mSearch && mStock;
    });
    if (list.length === 0) { grid.innerHTML = '<div class="text-center p-5 text-muted"><h5>No se encontraron productos</h5></div>'; return; }
    list.forEach(p => {
        const stock = parseFloat(p.stock) || 0;
        const hasStock = stock > 0 || p.es_servicio == 1;
        const cardClass = hasStock ? 'product-card' : 'product-card disabled-card';
        const badgeClass = hasStock ? 'stock-ok' : 'stock-zero';
        const stockDisplay = p.es_servicio == 1 ? '∞' : stock;
        let imgHTML = `<div class="product-img-container" style="background:${p.color||'#6c757d'}"><span class="placeholder-text">${(p.nombre||'??').substring(0,2).toUpperCase()}</span></div>`;
        if(p.has_image && !document.body.classList.contains('mode-no-images')) { imgHTML = `<div class="product-img-container"><img src="image.php?code=${p.codigo}" class="product-img" onerror="this.style.display='none'"></div>`; }
        const card = document.createElement('div'); card.className = cardClass;
        card.innerHTML = `<div class="stock-badge ${badgeClass}">${stockDisplay}</div>${imgHTML}<div class="product-info"><div class="product-name">${p.nombre}</div><div class="product-price">$${parseFloat(p.precio).toFixed(2)}</div></div>`;
        if (hasStock) { card.onclick = () => addToCart(p); } else { card.onclick = () => { Synth.error(); showToast('Sin Stock', 'error'); }; }
        grid.appendChild(card);
    });
};

window.filterCategory = function(cat, btn) { Synth.category(); document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active')); if(btn) btn.classList.add('active'); renderProducts(cat); };
window.filterProducts = function() { renderProducts(undefined); };
window.toggleStockFilter = function() { window.stockFilterActive = !window.stockFilterActive; const btn = document.getElementById('btnStockFilter'); if(btn) btn.classList.toggle('btn-filter-active'); renderProducts(); };
window.toggleImages = function() { document.body.classList.toggle('mode-no-images'); const btn = document.getElementById('btnToggleImages'); if(btn) btn.classList.toggle('btn-filter-active'); renderProducts(); };
window.toggleBars = function() { const hidden = document.body.classList.toggle('pos-bars-hidden'); const btn = document.getElementById('btnToggleBars'); if(btn) btn.classList.toggle('btn-filter-active', hidden); localStorage.setItem('posBarsHidden', hidden ? '1' : '0'); };

// CARRITO
window.addToCart = function(p) {
    const idx = cart.findIndex(i => i.id === p.codigo && (!i.note)); 
    if(idx >= 0) { if(p.es_servicio==0 && (cart[idx].qty+1)>parseFloat(p.stock)) { Synth.error(); return showToast("Stock insuficiente", "error"); } cart[idx].qty++; selectedIndex = idx; } 
    else { cart.push({ id: p.codigo, name: p.nombre, price: parseFloat(p.precio), qty: 1, discountPct: 0, note: '' }); selectedIndex = cart.length - 1; }
    Synth.addCart(); renderCart(); saveCartState();
};
window.renderCart = function() {
    const c = document.getElementById('cartContainer'); if (!c) return;
    c.innerHTML = ''; let sub = 0; let items = 0;
    if(cart.length===0) c.innerHTML = '<div class="text-center text-muted mt-5 pt-5"><i class="fas fa-shopping-basket fa-2x mb-2 opacity-25"></i><p class="small">Carrito Vacío</p></div>';
    cart.forEach((i, idx) => {
        const lineT = (i.price * (1 - i.discountPct/100)) * i.qty; sub += lineT; items += i.qty;
        const selClass = idx === selectedIndex ? ' selected' : '';
        const discHtml = i.discountPct > 0 ? `<span class="discount-tag">-${i.discountPct}%</span>` : '';
        const noteHtml = i.note ? `<div class="cart-note">📝 ${i.note}</div>` : '';
        const d = document.createElement('div'); d.className = 'cart-item' + selClass; d.onclick = () => { selectedIndex = idx; renderCart(); };
        d.innerHTML = `<div class="d-flex justify-content-between fw-bold"><span>${i.qty} x ${i.name} ${discHtml}</span><span>$${lineT.toFixed(2)}</span></div><div class="small text-muted">$${(i.price*(1-i.discountPct/100)).toFixed(2)}</div>${noteHtml}`;
        c.appendChild(d);
    });
    const tot = sub * (1 - globalDiscountPct/100);
    const te = document.getElementById('totalAmount'); if(te) te.innerText = '$' + tot.toFixed(2);
    const ti = document.getElementById('totalItems'); if(ti) ti.innerText = items;
};
window.modifyQty = function(d) { if(selectedIndex < 0) return; const item = cart[selectedIndex]; const prod = productsDB.find(p=>p.codigo == item.id); if(d>0 && prod && prod.es_servicio==0 && (item.qty+d)>parseFloat(prod.stock)) { Synth.error(); return showToast("Sin stock", "error"); } item.qty += d; if(item.qty <= 0) { cart.splice(selectedIndex, 1); selectedIndex = -1; Synth.removeCart(); } else { d>0 ? Synth.increment() : Synth.decrement(); } renderCart(); saveCartState(); };
window.removeItem = function() { if(selectedIndex>=0 && confirm('Eliminar?')) { cart.splice(selectedIndex,1); selectedIndex=-1; Synth.removeCart(); renderCart(); saveCartState(); } };
window.clearCart = function() { if(cart.length>0 && confirm('Vaciar?')) { cart=[]; globalDiscountPct=0; selectedIndex=-1; Synth.clear(); renderCart(); saveCartState(); } };
window.askQty = function() { if(selectedIndex<0) return showToast("Seleccione item","warning"); let q=prompt("Cantidad:",cart[selectedIndex].qty); if(q&&!isNaN(q)&&q>0){ cart[selectedIndex].qty=Number(q); Synth.increment(); renderCart(); saveCartState(); } };
window.applyDiscount = function() { if(selectedIndex<0) return showToast("Seleccione item","warning"); let p=prompt("Desc %:",cart[selectedIndex].discountPct); if(p!==null){ let v=parseFloat(p)||0; if(v>=0&&v<=100){ cart[selectedIndex].discountPct=v; renderCart(); Synth.discount(); saveCartState(); } } };
window.applyGlobalDiscount = function() { if(cart.length===0) return; let p=prompt("Desc Global %:",globalDiscountPct); if(p!==null){ let v=parseFloat(p)||0; if(v>=0&&v<=100){ globalDiscountPct=v; renderCart(); Synth.discount(); saveCartState(); } } };
window.addNote = function() { if(selectedIndex<0) return showToast("Seleccione item","warning"); let n=prompt("Nota:",cart[selectedIndex].note); if(n!==null){ cart[selectedIndex].note=n; renderCart(); saveCartState(); } };

// Utilidades Extra
function saveToCache(data) { try { localStorage.setItem(CACHE_KEY, JSON.stringify({ data: data, timestamp: Date.now() })); } catch (e) {} }
function loadFromCacheOrRefresh() { const c = localStorage.getItem(CACHE_KEY); if(c) { try { const d = JSON.parse(c); if(d.data) { window.productsDB = d.data; productsDB = d.data; initCatalog(); return; } } catch(e){} } refreshProducts(); }
window.refreshProducts = async function() { try{ const r=await fetch('pos2.php?load_products=1'); const d=await r.json(); if(d.status==='success'){ window.productsDB=d.products; productsDB=d.products; saveToCache(productsDB); initCatalog(); showToast('Catálogo actualizado'); } }catch(e){} };
window.saveCartState = function() { localStorage.setItem('pos_cart_state', JSON.stringify({cart, globalDiscountPct})); };
window.restoreCartState = function() { try{const s=JSON.parse(localStorage.getItem('pos_cart_state')); if(s){cart=s.cart;globalDiscountPct=s.globalDiscountPct;renderCart();}}catch(e){} };
window.updatePinDisplay = function() { const d=document.getElementById('pinDisplay'); if(d) d.innerText = '•'.repeat(enteredPin.length); };
window.typePin = function(v) { Synth.click(); if(v==='C') enteredPin=""; else if(enteredPin.length<4) enteredPin+=v; updatePinDisplay(); };
window.verifyPin = function() {
    const cfg = (typeof CAJEROS_CONFIG !== 'undefined') ? CAJEROS_CONFIG : [];
    const matched = cfg.find(c => String(c.pin) === String(enteredPin));
    if (matched) {
        window.currentCajero    = matched.nombre;
        window.currentCajeroRol = matched.rol || 'cajero';
        document.getElementById('pinOverlay').style.display = 'none';
        const nameEl = document.getElementById('cashierName');
        if (nameEl) nameEl.textContent = matched.nombre;
        Synth.tada();
        checkCashStatusSilent();
        const btnInv = document.getElementById('btnInventario');
        if (btnInv) btnInv.style.display = window.currentCajeroRol === 'admin' ? '' : 'none';
    } else {
        Synth.error(); enteredPin = ""; updatePinDisplay(); showToast("Error PIN", "error");
    }
};

// ── MÓDULO INVENTARIO POS ────────────────────────────────────────────────────
const INV_CONFIG = {
    entrada: { title: 'Recepción de Mercancía', icon: 'fa-truck-loading',  color: 'success', qtyLabel: 'Cantidad recibida' },
    ajuste:  { title: 'Ajuste de Conteo',       icon: 'fa-sliders-h',      color: 'warning', qtyLabel: 'Cantidad a ajustar' },
    conteo:  { title: 'Conteo Físico',           icon: 'fa-barcode',        color: 'info',    qtyLabel: 'Cantidad contada (total real)' },
    merma:   { title: 'Registrar Merma',         icon: 'fa-trash-alt',      color: 'danger',  qtyLabel: 'Cantidad a dar de baja' },
};

window.toggleInventarioMode = function() {
    const kp    = document.getElementById('keypadContainer');
    const ip    = document.getElementById('inventarioPanel');
    const cobrar = document.querySelector('.btn-pay');
    const btnInv = document.getElementById('btnInventario');
    const isInv  = ip.style.display !== 'none';
    kp.style.display    = isInv ? '' : 'none';
    ip.style.display    = isInv ? 'none' : '';
    cobrar.style.display = isInv ? '' : 'none';
    if (btnInv) btnInv.style.display = '';
};

window.openInvModal = function(tipo) {
    window.currentInvTipo = tipo;
    window.currentInvProd = null;
    const cfg = INV_CONFIG[tipo];
    document.getElementById('invModalTitle').innerHTML = `<i class="fas ${cfg.icon} me-2 text-${cfg.color}"></i>${cfg.title}`;
    document.getElementById('invSkuInput').value     = '';
    document.getElementById('invProductInfo').innerHTML = '';
    document.getElementById('invSuggestions').style.display = 'none';
    document.getElementById('invQtyInput').value    = '';
    document.getElementById('invQtyLabel').textContent = cfg.qtyLabel;
    document.getElementById('invMotivoInput').value = '';
    document.getElementById('invCostoInput').value  = '';
    document.getElementById('invCostoRow').style.display  = tipo === 'entrada' ? '' : 'none';
    document.getElementById('invAjusteRow').style.display = tipo === 'ajuste'  ? '' : 'none';
    document.getElementById('signoPos').checked = true;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('invModal'));
    modal.show();
    setTimeout(() => document.getElementById('invSkuInput').focus(), 350);
};

window.invBuscarProducto = function(e) {
    // Permite buscar con Enter o con el botón
    if (e && e.type === 'keyup' && e.key !== 'Enter') {
        // Mostrar sugerencias dinámicas
        const term = document.getElementById('invSkuInput').value.trim().toLowerCase();
        const sug  = document.getElementById('invSuggestions');
        if (term.length < 2) { sug.style.display = 'none'; return; }
        const matches = (window.productsDB || []).filter(p =>
            p.codigo.toLowerCase().includes(term) || p.nombre.toLowerCase().includes(term)
        ).slice(0, 8);
        if (!matches.length) { sug.style.display = 'none'; return; }
        sug.innerHTML = matches.map(p =>
            `<button type="button" class="list-group-item list-group-item-action py-1 small" onclick="invSeleccionarProd('${p.codigo}')">
                <strong class="me-2">${p.codigo}</strong>${p.nombre}
                <span class="float-end text-muted">Stock: ${p.stock}</span>
            </button>`
        ).join('');
        sug.style.display = '';
        return;
    }
    // Búsqueda exacta (Enter o botón)
    const term = document.getElementById('invSkuInput').value.trim();
    document.getElementById('invSuggestions').style.display = 'none';
    if (!term) return;
    const prod = (window.productsDB || []).find(p =>
        p.codigo.toLowerCase() === term.toLowerCase() || p.nombre.toLowerCase() === term.toLowerCase()
    ) || (window.productsDB || []).find(p =>
        p.codigo.toLowerCase().includes(term.toLowerCase()) || p.nombre.toLowerCase().includes(term.toLowerCase())
    );
    if (prod) invSeleccionarProd(prod.codigo);
    else document.getElementById('invProductInfo').innerHTML = '<div class="alert alert-warning py-2 small mb-0">Producto no encontrado</div>';
};

window.invSeleccionarProd = function(codigo) {
    const prod = (window.productsDB || []).find(p => p.codigo == codigo);
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
    document.getElementById('invQtyInput').focus();
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
        accion:     window.currentInvTipo,
        sku:        window.currentInvProd.codigo,
        cantidad:   finalQty,
        motivo:     motivo,
        usuario:    window.currentCajero || 'POS-Admin',
        costo_nuevo: parseFloat(document.getElementById('invCostoInput').value) || undefined,
    };

    const btn = document.getElementById('btnInvConfirmar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Procesando...';

    try {
        const r = await fetch('pos.php?inventario_api=1', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.status === 'success') {
            showToast(d.msg, 'success');
            bootstrap.Modal.getInstance(document.getElementById('invModal')).hide();
            // Actualizar stock local en productsDB para reflejar el cambio
            const p = window.productsDB.find(x => x.codigo == window.currentInvProd.codigo);
            if (p) {
                const tipo = window.currentInvTipo;
                if      (tipo === 'entrada') p.stock = parseFloat(p.stock) + Math.abs(finalQty);
                else if (tipo === 'ajuste')  p.stock = parseFloat(p.stock) + finalQty;
                else if (tipo === 'conteo')  p.stock = qty;
                else if (tipo === 'merma')   p.stock = Math.max(0, parseFloat(p.stock) - Math.abs(finalQty));
                window.renderProducts();
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
window.showToast = function(msg, type='success') { if(typeof Swal!=='undefined') Swal.fire({toast:true,position:'bottom-end',icon:type,title:msg,showConfirmButton:false,timer:3000}); else alert(msg); };
let barcodeBuffer = ""; let barcodeTimeout;
function handleBarcodeScanner(e) { if(e.target.tagName==='INPUT' && e.target.id!=='searchInput') return; if(e.key==='Enter'){ if(barcodeBuffer){ processBarcode(barcodeBuffer); barcodeBuffer=""; } } else if(e.key.length===1){ barcodeBuffer+=e.key; clearTimeout(barcodeTimeout); barcodeTimeout=setTimeout(()=>barcodeBuffer="",100); } }
function processBarcode(c) { const p = productsDB.find(x => x.codigo == c); if(p){ addToCart(p); Synth.beep(); } else Synth.error(); }
window.forceDownloadProducts = async function() { if(!confirm("¿Recargar?")) return; localStorage.removeItem(CACHE_KEY); location.reload(true); };
window.syncOfflineQueue = async function() { if(!navigator.onLine) return; const q=JSON.parse(localStorage.getItem(QUEUE_KEY)||'[]'); if(q.length===0) return; let ok=0; for(const s of q){ try{ const r=await fetch('pos_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(s)}); const d=await r.json(); if(d.status==='success') ok++; }catch(e){} } if(ok>0){ localStorage.setItem(QUEUE_KEY,'[]'); showToast(ok+' ventas sincronizadas'); } };
window.syncAllPending = function() { syncOfflineQueue(); };

// PARK ORDERS
window.parkOrder = function() { if(cart.length===0) return showToast('Carrito vacío','warning'); const n=prompt('Nombre orden:','Mesa '+new Date().toTimeString().substring(0,5)); if(!n) return; const o=JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY)||'[]'); o.push({id:Date.now(),name:n,items:[...cart],globalDiscount:globalDiscountPct}); localStorage.setItem(PARKED_ORDERS_KEY,JSON.stringify(o)); Synth.click(); showToast('Orden pausada','success'); cart=[]; globalDiscountPct=0; selectedIndex=-1; renderCart(); saveCartState(); };
window.showParkedOrders = function() { const o=JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY)||'[]'); if(o.length===0) return showToast('No hay ordenes','warning'); let h=''; o.forEach((x,i)=>{ h+=`<button class="list-group-item list-group-item-action d-flex justify-content-between" onclick="loadParkedOrder(${i})"><div><strong>${x.name}</strong><br><small>${x.items.length} items</small></div><span class="badge bg-primary">Recuperar</span></button>`; }); document.getElementById('parkList').innerHTML=h; new bootstrap.Modal(document.getElementById('parkModal')).show(); };
window.loadParkedOrder = function(i) { const o=JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY)||'[]'); const ord=o[i]; if(cart.length>0 && !confirm('Reemplazar actual?')) return; cart=[...ord.items]; globalDiscountPct=ord.globalDiscount||0; selectedIndex=-1; o.splice(i,1); localStorage.setItem(PARKED_ORDERS_KEY,JSON.stringify(o)); renderCart(); Synth.addCart(); bootstrap.Modal.getInstance(document.getElementById('parkModal')).hide(); };

