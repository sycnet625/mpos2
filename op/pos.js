// ARCHIVO: /var/www/palweb/api/pos.js
// VERSION: 4.3 - Logic Fusion (Payment/History/Park)

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

// Variables de Pago
let currentSaleTotal = 0;
let currentPaymentMode = 'cash'; 
let theoreticalTotal = 0;

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
    discount: ()=>Synth.playTone(900,'sine',0.08, 0.1)
};

// INIT
document.addEventListener('DOMContentLoaded', () => {
    console.log('Iniciando POS v4.3...');
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

    // Listeners Pagos
    const pC = document.getElementById('payCash');
    if(pC) {
        ['payCash','payTransfer','payCard'].forEach(id => { const el=document.getElementById(id); if(el) el.addEventListener('input', calculateMixedTotals); });
        pC.addEventListener('input', () => autoCompletePayment('payCash', ['payTransfer','payCard']));
        document.getElementById('payTransfer').addEventListener('input', () => autoCompletePayment('payTransfer', ['payCash','payCard']));
    }
    
    const fc = document.getElementById('final-cash');
    if(fc) fc.addEventListener('input', updateCloseDifference);

    if(navigator.onLine) syncAllPending();
    window.addEventListener('online', syncAllPending);
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
            badge.innerText = `ABIERTA (${d.data.fecha_contable || 'HOY'})`;
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

// CARRITO
window.addToCart = function(p, qty = 1) {
    qty = Math.max(1, parseFloat(qty) || 1);
    const idx = cart.findIndex(i => i.id === p.codigo && (!i.note));
    if(idx >= 0) { if(p.es_servicio==0 && (cart[idx].qty+qty)>parseFloat(p.stock)) { Synth.error(); return showToast("Stock insuficiente", "error"); } cart[idx].qty += qty; selectedIndex = idx; }
    else { cart.push({ id: p.codigo, name: p.nombre, price: parseFloat(p.precio), qty: qty, discountPct: 0, note: '' }); selectedIndex = cart.length - 1; }
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
    const tp = document.getElementById('totalProds'); if(tp) tp.innerText = cart.length;
};
window.modifyQty = function(d) { if(selectedIndex < 0) return; const item = cart[selectedIndex]; const prod = productsDB.find(p=>p.codigo == item.id); if(d>0 && prod && prod.es_servicio==0 && (item.qty+d)>parseFloat(prod.stock)) { Synth.error(); return showToast("Sin stock", "error"); } item.qty += d; if(item.qty <= 0) { cart.splice(selectedIndex, 1); selectedIndex = -1; Synth.removeCart(); } else { d>0 ? Synth.increment() : Synth.decrement(); } renderCart(); saveCartState(); };
window.removeItem = function() { if(selectedIndex>=0 && confirm('Eliminar?')) { cart.splice(selectedIndex,1); selectedIndex=-1; Synth.removeCart(); renderCart(); saveCartState(); } };
window.clearCart = function() { if(cart.length>0 && confirm('Vaciar?')) { cart=[]; globalDiscountPct=0; selectedIndex=-1; Synth.clear(); renderCart(); saveCartState(); } };
window.askQty = function() { if(selectedIndex<0) return showToast("Seleccione item","warning"); let q=prompt("Cantidad:",cart[selectedIndex].qty); if(q&&!isNaN(q)&&q>0){ cart[selectedIndex].qty=Number(q); Synth.increment(); renderCart(); saveCartState(); } };
window.applyDiscount = function() { if(selectedIndex<0) return showToast("Seleccione item","warning"); let p=prompt("Desc %:",cart[selectedIndex].discountPct); if(p!==null){ let v=parseFloat(p)||0; if(v>=0&&v<=100){ cart[selectedIndex].discountPct=v; renderCart(); Synth.discount(); saveCartState(); } } };
window.applyGlobalDiscount = function() { if(cart.length===0) return; let p=prompt("Desc Global %:",globalDiscountPct); if(p!==null){ let v=parseFloat(p)||0; if(v>=0&&v<=100){ globalDiscountPct=v; renderCart(); Synth.discount(); saveCartState(); } } };
window.addNote = function() { if(selectedIndex<0) return showToast("Seleccione item","warning"); let n=prompt("Nota:",cart[selectedIndex].note); if(n!==null){ cart[selectedIndex].note=n; renderCart(); saveCartState(); } };

// PAGOS
window.openPaymentModal = function() {
    if(cart.length === 0) return showToast('Carrito vacío', 'warning');
    if(!cashOpen) return showToast('CAJA CERRADA', 'error');
    let sub = cart.reduce((acc, i) => acc + ((i.price * (1 - i.discountPct/100)) * i.qty), 0);
    currentSaleTotal = sub * (1 - globalDiscountPct/100);
    const disp = document.getElementById('payment-total-due'); if(disp) disp.innerText = '$' + currentSaleTotal.toFixed(2);
    ['payCash','payTransfer','payCard'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    const si = document.getElementById('singleAmountInput'); if(si) si.value='';
    document.getElementById('modeCash').checked = true; setPaymentMode('cash'); toggleServiceOptions();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
    setTimeout(() => { if(si) si.focus(); }, 500);
};
window.setPaymentMode = function(mode) {
    currentPaymentMode = mode;
    ['cashPaymentSection','simplePaymentSection','mixedPaymentSection'].forEach(id => document.getElementById(id).classList.add('d-none'));
    if(mode === 'cash') { document.getElementById('cashPaymentSection').classList.remove('d-none'); setTimeout(() => document.getElementById('singleAmountInput').focus(), 100); } 
    else if(mode === 'mixed') { document.getElementById('mixedPaymentSection').classList.remove('d-none'); document.getElementById('payCash').value = currentSaleTotal.toFixed(2); calculateMixedTotals(); setTimeout(() => document.getElementById('payCash').select(), 100); } 
    else { document.getElementById('simplePaymentSection').classList.remove('d-none'); }
};
window.calcChange = function() {
    const r = parseFloat(document.getElementById('singleAmountInput').value) || 0;
    const c = r - currentSaleTotal;
    const d = document.getElementById('singleChangeDisplay');
    if(c >= 0) { d.innerText = '$' + c.toFixed(2); d.className = 'h2 fw-bold text-primary ms-2'; } else { d.innerText = 'Falta: $' + Math.abs(c).toFixed(2); d.className = 'h4 fw-bold text-danger ms-2'; }
};
window.calculateMixedTotals = function() {
    const c = parseFloat(document.getElementById('payCash').value)||0;
    const t = parseFloat(document.getElementById('payTransfer').value)||0;
    const cd = parseFloat(document.getElementById('payCard').value)||0;
    const paid = c + t + cd;
    const rem = currentSaleTotal - paid;
    const pct = Math.min(100, (paid/currentSaleTotal)*100);
    const bar = document.getElementById('mixProgressBar'); if(bar) { bar.style.width = pct + '%'; bar.className = 'progress-bar ' + (rem <= 0.01 ? 'bg-success' : 'bg-warning'); }
    const rd = document.getElementById('mixRemaining'); if(rd) { if(rem > 0.01) { rd.innerText = '$' + rem.toFixed(2); rd.className = 'fw-bold text-danger fs-5'; document.getElementById('btn-confirm-payment').disabled = true; } else { rd.innerText = 'COMPLETO'; rd.className = 'fw-bold text-success fs-5'; document.getElementById('btn-confirm-payment').disabled = false; } }
};
function autoCompletePayment(sourceId, targetIds) {
    const val = parseFloat(document.getElementById(sourceId).value)||0;
    const otherVal = parseFloat(document.getElementById(targetIds[1]).value)||0; 
    const rem = currentSaleTotal - val - otherVal;
    if(rem >= 0) document.getElementById(targetIds[0]).value = rem.toFixed(2);
    calculateMixedTotals();
}
window.toggleServiceOptions = function() {
    const t = document.getElementById('serviceType').value;
    document.getElementById('deliveryDiv').classList.add('d-none');
    document.getElementById('reservationDiv').classList.add('d-none');
    if(t === 'mensajeria' || t === 'delivery') document.getElementById('deliveryDiv').classList.remove('d-none');
    if(t === 'reserva') document.getElementById('reservationDiv').classList.remove('d-none');
};

// Bandera para evitar clics duplicados
let opPaymentProcessing = false;

window.confirmPaymentSafe = async function() {
    // Si ya se está procesando un pago, ignorar el clic
    if (opPaymentProcessing) {
        alert('Por favor espera a que se procese el pago anterior...');
        return;
    }

    // Marcar como procesando
    opPaymentProcessing = true;

    // Deshabilitar botón y cambiar texto
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
        // Restaurar botón y estado global siempre al terminar
        opPaymentProcessing = false;
        
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'CONFIRMAR PAGO';
        }
    }
};

window.confirmPayment = async function() {
    let payments = []; let mainMethod = 'Efectivo';
    if (currentPaymentMode === 'mixed') {
        mainMethod = 'Mixto';
        const c = parseFloat(document.getElementById('payCash').value)||0;
        const t = parseFloat(document.getElementById('payTransfer').value)||0;
        const cd = parseFloat(document.getElementById('payCard').value)||0;
        if(c>0) payments.push({method:'Efectivo', amount:c});
        if(t>0) payments.push({method:'Transferencia', amount:t});
        if(cd>0) payments.push({method:'Tarjeta', amount:cd});
        if((c+t+cd) < (currentSaleTotal - 0.05)) return alert("Pago incompleto");
    } else if (currentPaymentMode === 'cash') {
        mainMethod = 'Efectivo';
        payments.push({method:'Efectivo', amount: currentSaleTotal});
        const del = parseFloat(document.getElementById('singleAmountInput').value)||0;
        if(del < currentSaleTotal) return alert("Monto insuficiente");
    } else {
        mainMethod = currentPaymentMode === 'transfer' ? 'Transferencia' : 'Tarjeta';
        payments.push({method: mainMethod, amount: currentSaleTotal});
    }
    const cliName = document.getElementById('cliName').value || 'Mostrador';
    const serv = document.getElementById('serviceType').value;
    let msj = ''; if(serv === 'mensajeria' || serv === 'delivery') { msj = document.getElementById('deliveryDriver').value; if(!msj) return alert("Seleccione mensajero"); }
    let rDate='', rAbono=0; if(serv === 'reserva') { rDate = document.getElementById('reservationDate').value; rAbono = document.getElementById('reservationAbono').value; if(!rDate||!rAbono) return alert("Datos reserva incompletos"); }
    const payload = { uuid: crypto.randomUUID(), items: cart.map(i=>({id:i.id, name:i.name, qty:i.qty, price:i.price, note:i.note})), total: currentSaleTotal, payments: payments, metodo_pago: mainMethod, tipo_servicio: serv, cliente_nombre: cliName, mensajero_nombre: msj, fecha_reserva: rDate, abono: rAbono, id_caja: cashId, timestamp: Date.now() };
    bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
    try {
        const r = await fetch('pos_save.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const res = await r.json();
        if(res.status === 'success') {
            Synth.cash();
            showPostSaleOptions(res.id);
            finishSale();
        } else {
            alert('Error: ' + res.msg);
        }
    } catch(e) { saveOffline(payload); }
    finally {
        opPaymentProcessing = false;
    }
};

// Mostrar opciones después de registrar venta
window.showPostSaleOptions = function(idVenta) {
    const html = `
        <div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999;">
            <div style="background:white;border-radius:8px;padding:25px;max-width:400px;box-shadow:0 5px 30px rgba(0,0,0,0.3);">
                <h4 style="margin-top:0;color:#333;"><i class="fas fa-check-circle" style="color:#28a745;"></i> Venta #${idVenta} Registrada</h4>
                <p style="color:#666;margin-bottom:25px;">¿Qué deseas hacer ahora?</p>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <button onclick="window.open('comprobante_ventas.php?id=${idVenta}', '_blank', 'width=1000,height=800');closeSaleModal();" style="padding:12px;background:#0dcaf0;color:white;border:none;border-radius:5px;font-weight:600;cursor:pointer;font-size:14px;">
                        <i class="fas fa-receipt"></i> Ver Comprobante
                    </button>
                    <button onclick="window.open('ticket_view.php?id=${idVenta}', '_blank', 'width=380,height=600');closeSaleModal();" style="padding:12px;background:#6c757d;color:white;border:none;border-radius:5px;font-weight:600;cursor:pointer;font-size:14px;">
                        <i class="fas fa-print"></i> Imprimir Ticket
                    </button>
                    <button onclick="window.open('comprobante_ventas.php?id=${idVenta}&format=pdf', '_blank');closeSaleModal();" style="padding:12px;background:#fd7e14;color:white;border:none;border-radius:5px;font-weight:600;cursor:pointer;font-size:14px;">
                        <i class="fas fa-file-pdf"></i> Descargar PDF
                    </button>
                    <button onclick="closeSaleModal();" style="padding:12px;background:#e9ecef;color:#333;border:none;border-radius:5px;font-weight:600;cursor:pointer;font-size:14px;">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    `;

    const container = document.createElement('div');
    container.id = 'post-sale-modal';
    container.innerHTML = html;
    document.body.appendChild(container);
};

window.closeSaleModal = function() {
    const modal = document.getElementById('post-sale-modal');
    if(modal) modal.remove();
    showToast('Venta completada', 'success');
};

// CIERRE DE CAJA
window.checkCashRegister = async function() {
    if(!cashOpen) { if(typeof showOpenCashModal === 'function') showOpenCashModal(); return; }
    const modal = new bootstrap.Modal(document.getElementById('closeRegisterModal'));
    try {
        const r=await fetch('pos_cash.php?action=status'); const d=await r.json();
        if(d.status==='open') {
            document.getElementById('closeDateDisplay').innerText = d.data.fecha_contable;
            let tot=0, c=0, tr=0, tj=0;
            if(d.ventas) d.ventas.forEach(v => { const m=parseFloat(v.total); tot+=m; if(v.metodo_pago==='Efectivo')c+=m; else if(v.metodo_pago==='Tarjeta')tj+=m; else if(v.metodo_pago==='Transferencia')tr+=m; else c+=m; });
            const fondo = parseFloat(d.data.monto_inicial||0); theoreticalTotal = fondo + c;
            document.getElementById('sysCash').innerText = '$'+c.toFixed(2); document.getElementById('sysTransfer').innerText = '$'+tr.toFixed(2); document.getElementById('sysCard').innerText = '$'+tj.toFixed(2); document.getElementById('summaryTotalSales').innerText = '$'+tot.toFixed(2); document.getElementById('summaryInitialFund').innerText = '$'+fondo.toFixed(2); document.getElementById('summaryTheoreticalTotal').innerText = '$'+theoreticalTotal.toFixed(2);
            document.getElementById('final-cash').value = ''; document.getElementById('diffDisplay').innerText = 'Diferencia: $0.00';
            modal.show();
        }
    } catch(e){}
};
window.updateCloseDifference = function() { const r=parseFloat(document.getElementById('final-cash').value)||0; const d=r-theoreticalTotal; const el=document.getElementById('diffDisplay'); el.innerText='Diferencia: $'+d.toFixed(2); el.className='form-text text-end fw-bold '+(d<0?'text-danger':(d>0?'text-success':'text-muted')); };
window.validateAndCloseCash = async function() { const r=parseFloat(document.getElementById('final-cash').value); const n=document.getElementById('close-note').value; if(isNaN(r)) return alert("Ingrese efectivo"); if(!confirm('Cerrar?')) return; try{ const resp=await fetch('pos_cash.php?action=close',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:cashId,real:r,nota:n})}); const res=await resp.json(); if(res.status==='success'){ bootstrap.Modal.getInstance(document.getElementById('closeRegisterModal')).hide(); Synth.closeCash(); checkCashStatusSilent(); showToast('Cerrado','success'); setTimeout(()=>location.reload(),1500); } else alert(res.msg); } catch(e){ alert("Error"); } };

// HISTORIAL & PARK
window.showHistorialModal = async function() {
    const modal = new bootstrap.Modal(document.getElementById('historialModal'));
    const body = document.getElementById('historialModalBody');
    body.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    modal.show();
    try {
        const r = await fetch(`pos2.php?load_history=1&session_id=${cashId}`);
        const d = await r.json();
        if(d.status === 'success') renderHistorialTable(body, d.tickets); else body.innerHTML = '<div class="p-3 text-center text-danger">Error al cargar</div>';
    } catch(e) { body.innerHTML = '<div class="p-3 text-center text-danger">Error de conexión</div>'; }
};
window.renderHistorialTable = function(container, tickets) {
    if(tickets.length===0) { container.innerHTML='<div class="p-4 text-center text-muted">Sin movimientos</div>'; return; }
    let html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>ID</th><th>Hora</th><th>Total</th><th>Pago</th><th></th></tr></thead><tbody>';
    tickets.forEach(t => {
        let badgeClass = 'bg-secondary';
        if(t.metodo_pago=='Efectivo') badgeClass='bg-success'; else if(t.metodo_pago=='Transferencia') badgeClass='bg-primary'; else if(t.metodo_pago=='Tarjeta') badgeClass='bg-warning text-dark';
        html += `<tr class="ticket-row">
            <td class="fw-bold">#${t.id}</td>
            <td>${t.fecha.split(' ')[1].substring(0,5)}</td>
            <td class="fw-bold text-end">$${parseFloat(t.total).toFixed(2)}</td>
            <td><span class="badge ${badgeClass}">${t.metodo_pago}</span></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-dark" onclick="window.open('ticket_view.php?id=${t.id}','Ticket','width=380,height=600')"><i class="fas fa-eye"></i></button></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
};

window.parkOrder = function() {
    if (cart.length === 0) return showToast('Carrito vacío', 'warning');
    const name = prompt('Nombre para esta orden:', 'Mesa ' + new Date().toTimeString().substring(0,5));
    if (!name) return;
    const orders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    orders.push({ id: Date.now(), name: name, items: [...cart], globalDiscount: globalDiscountPct });
    localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(orders));
    Synth.click(); showToast('Orden pausada', 'success');
    cart = []; globalDiscountPct = 0; selectedIndex = -1; renderCart(); saveCartState();
};
window.showParkedOrders = function() {
    const orders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    if (orders.length === 0) return showToast('No hay ordenes pausadas', 'warning');
    let html = '';
    orders.forEach((o, i) => {
        html += `<button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="loadParkedOrder(${i})">
            <div><strong>${o.name}</strong><br><small>${o.items.length} items - $${o.items.reduce((a,b)=>a+(b.price*b.qty),0).toFixed(2)}</small></div>
            <span class="badge bg-primary rounded-pill"><i class="fas fa-undo"></i></span>
        </button>`;
    });
    document.getElementById('parkList').innerHTML = html;
    new bootstrap.Modal(document.getElementById('parkModal')).show();
};
window.loadParkedOrder = function(index) {
    const orders = JSON.parse(localStorage.getItem(PARKED_ORDERS_KEY) || '[]');
    const o = orders[index];
    if (cart.length > 0 && !confirm('¿Reemplazar carrito actual?')) return;
    cart = [...o.items]; globalDiscountPct = o.globalDiscount || 0; selectedIndex = -1;
    orders.splice(index, 1);
    localStorage.setItem(PARKED_ORDERS_KEY, JSON.stringify(orders));
    renderCart(); Synth.addCart();
    bootstrap.Modal.getInstance(document.getElementById('parkModal')).hide();
};

// EXTRAS
window.finishSale = function() { cart=[]; globalDiscountPct=0; selectedIndex=-1; renderCart(); localStorage.removeItem('pos_cart_state'); const ids=['cliPhone','cliAddr','deliveryDriver','reservationDate','reservationAbono']; ids.forEach(id=>{const el=document.getElementById(id);if(el)el.value='';}); if(navigator.onLine) refreshProducts(); };
window.saveOffline = function(p) { const q=JSON.parse(localStorage.getItem(QUEUE_KEY)||'[]'); q.push(p); localStorage.setItem(QUEUE_KEY, JSON.stringify(q)); Synth.warning(); showToast('Guardado OFFLINE','warning'); finishSale(); };
function saveToCache(data) { try { localStorage.setItem(CACHE_KEY, JSON.stringify({ data: data, timestamp: Date.now() })); } catch (e) {} }
function loadFromCacheOrRefresh() { const c = localStorage.getItem(CACHE_KEY); if(c) { try { const d = JSON.parse(c); if(d.data) { window.productsDB = d.data; productsDB = d.data; initCatalog(); return; } } catch(e){} } refreshProducts(); }
window.refreshProducts = async function() { try{ const r=await fetch('pos2.php?load_products=1'); const d=await r.json(); if(d.status==='success'){ window.productsDB=d.products; productsDB=d.products; saveToCache(productsDB); initCatalog(); showToast('Catálogo actualizado'); } }catch(e){} };
window.saveCartState = function() { localStorage.setItem('pos_cart_state', JSON.stringify({cart, globalDiscountPct})); };
window.restoreCartState = function() { try{const s=JSON.parse(localStorage.getItem('pos_cart_state')); if(s){cart=s.cart;globalDiscountPct=s.globalDiscountPct;renderCart();}}catch(e){} };
window.updatePinDisplay = function() { const d=document.getElementById('pinDisplay'); if(d) d.innerText = '•'.repeat(enteredPin.length); };
window.typePin = function(v) { Synth.click(); if(v==='C') enteredPin=""; else if(enteredPin.length<4) enteredPin+=v; updatePinDisplay(); };
window.verifyPin = function() { if(enteredPin==="0000"){ document.getElementById('pinOverlay').style.display='none'; Synth.tada(); checkCashStatusSilent(); } else { Synth.error(); enteredPin=""; updatePinDisplay(); showToast("Error PIN","error"); } };
window.showToast = function(msg, type='success') { if(typeof Swal!=='undefined') Swal.fire({toast:true,position:'bottom-end',icon:type,title:msg,showConfirmButton:false,timer:3000}); else alert(msg); };
let barcodeBuffer = ""; let barcodeTimeout;
function handleBarcodeScanner(e) { if(e.target.tagName==='INPUT' && e.target.id!=='searchInput') return; if(e.key==='Enter'){ if(barcodeBuffer){ processBarcode(barcodeBuffer); barcodeBuffer=""; } } else if(e.key.length===1){ barcodeBuffer+=e.key; clearTimeout(barcodeTimeout); barcodeTimeout=setTimeout(()=>barcodeBuffer="",100); } }
function processBarcode(c) { const p = productsDB.find(x => x.codigo == c); if(p){ addToCart(p); Synth.beep(); } else Synth.error(); }
window.forceDownloadProducts = async function() { if(!confirm("¿Recargar?")) return; localStorage.removeItem(CACHE_KEY); location.reload(true); };
window.syncOfflineQueue = async function() { if(!navigator.onLine) return; const q=JSON.parse(localStorage.getItem(QUEUE_KEY)||'[]'); if(q.length===0) return; let ok=0; for(const s of q){ try{ const r=await fetch('pos_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(s)}); const d=await r.json(); if(d.status==='success') ok++; }catch(e){} } if(ok>0){ localStorage.setItem(QUEUE_KEY,'[]'); showToast(ok+' ventas sincronizadas'); } };
window.syncAllPending = function() { syncOfflineQueue(); };

