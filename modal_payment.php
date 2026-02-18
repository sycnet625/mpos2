<div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary-custom text-white border-0">
                <h5 class="modal-title fw-bold">Finalizar Venta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4 px-4">
                <div class="text-center mb-4"><div class="total-display-large" id="payment-total-due">$0.00</div></div>
                <div class="btn-group w-100 mb-4 shadow-sm" role="group">
                    <input type="radio" class="btn-check" name="paymentMode" id="modeCash" autocomplete="off" checked onclick="setPaymentMode('cash')">
                    <label class="btn btn-outline-success fw-bold" for="modeCash"><i class="fas fa-money-bill-wave me-1"></i> Efectivo</label>
                    <input type="radio" class="btn-check" name="paymentMode" id="modeTransfer" autocomplete="off" onclick="setPaymentMode('transfer')">
                    <label class="btn btn-outline-primary fw-bold" for="modeTransfer"><i class="fas fa-university me-1"></i> Transf.</label>
                    <input type="radio" class="btn-check" name="paymentMode" id="modeCard" autocomplete="off" onclick="setPaymentMode('card')">
                    <label class="btn btn-outline-warning fw-bold text-dark" for="modeCard"><i class="fas fa-credit-card me-1"></i> Gasto</label>
                    <input type="radio" class="btn-check" name="paymentMode" id="modeMixed" autocomplete="off" onclick="setPaymentMode('mixed')">
                    <label class="btn btn-outline-secondary fw-bold" for="modeMixed"><i class="fas fa-calculator me-1"></i> Mixto</label>
                </div>
                
                <div id="cashPaymentSection">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Monto Entregado</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-success text-white border-success">$</span>
                            <input type="number" id="singleAmountInput" class="form-control fw-bold text-end border-success text-success" placeholder="0.00" oninput="calcChange()" style="font-size: 1.5rem;">
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 fw-bold" onclick="setQuickAmount(200)">200</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 fw-bold" onclick="setQuickAmount(500)">500</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 fw-bold" onclick="setQuickAmount(1000)">1000</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 fw-bold" onclick="setQuickAmount(2000)">2000</button>
                        </div>
                    </div>
                    <div class="text-end bg-light p-2 rounded border">
                        <span class="text-muted fs-5 me-2">Su Cambio:</span>
                        <span class="h2 fw-bold text-primary mb-0" id="singleChangeDisplay">$0.00</span>
                    </div>
                </div>

                <div id="simplePaymentSection" class="d-none"><div class="alert alert-info text-center"><i class="fas fa-check-circle me-1"></i> Pago por el monto exacto.</div></div>
                
                <div id="mixedPaymentSection" class="d-none">
                    <div class="progress mb-3" style="height: 10px;"><div class="progress-bar bg-success" id="mixProgressBar" role="progressbar" style="width: 0%"></div></div>
                    <div class="input-group mb-2"><span class="input-group-text bg-success text-white" style="width: 120px;">Efectivo</span><input type="number" class="form-control split-payment-input text-end" id="payCash" placeholder="0.00" min="0" step="0.01" oninput="autoCompleteMixed('cash')"></div>
                    <div class="input-group mb-2"><span class="input-group-text bg-primary text-white" style="width: 120px;">Transferencia</span><input type="number" class="form-control split-payment-input text-end" id="payTransfer" placeholder="0.00" min="0" step="0.01" oninput="autoCompleteMixed('transfer')"></div>
                    <div class="input-group mb-2"><span class="input-group-text bg-warning text-dark" style="width: 120px;">Gasto/Tarj.</span><input type="number" class="form-control split-payment-input text-end" id="payCard" placeholder="0.00" min="0" step="0.01" oninput="calculateMixedTotals()"></div>
                    <div class="text-end mt-2"><small class="text-muted fw-bold me-2">RESTANTE:</small><span class="fw-bold text-danger fs-5" id="mixRemaining">$0.00</span></div>
                </div>
                
                <hr class="my-4">
                
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="small text-muted fw-bold">Cliente</label>
                        <div class="input-group input-group-sm">
                            <select id="cliName" class="form-select" onchange="fillClientData(this)">
                                <option value="">Consumidor Final</option>
                                <?php foreach($clientsData as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['nombre']); ?>" data-tel="<?php echo htmlspecialchars($c['telefono']); ?>" data-dir="<?php echo htmlspecialchars($c['direccion']); ?>"><?php echo htmlspecialchars($c['nombre']); ?> </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-secondary" onclick="openNewClientModal()"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                    <div class="col-5">
                        <label class="small text-muted fw-bold">Servicio</label>
                        <select id="serviceType" class="form-select form-select-sm" onchange="toggleServiceOptions()">
                            <option value="mostrador">🍽️ Aquí</option>
                            <option value="llevar">🥡 Llevar</option>
                            <option value="mensajeria">🛵 Delivery</option>
                            <option value="reserva">📅 Reserva</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3 d-none bg-warning bg-opacity-10 p-2 rounded border border-warning" id="reservationDiv">
                    <h6 class="small fw-bold text-dark mb-2"><i class="fas fa-calendar-alt me-1"></i> Datos de Reserva</h6>
                    <div class="row g-2">
                        <div class="col-7"><label class="small text-muted">Fecha y Hora</label><input type="datetime-local" class="form-control form-control-sm border-secondary" id="reservationDate"></div>
                        <div class="col-5"><label class="small text-muted">Abono Inicial</label><input type="number" class="form-control form-control-sm fw-bold text-end border-secondary" id="reservationAbono" placeholder="0.00"></div>
                    </div>
                </div>

                <div class="mb-3 d-none bg-info bg-opacity-10 p-2 rounded border border-info" id="deliveryDiv">
                    <label class="small fw-bold text-primary mb-1"><i class="fas fa-motorcycle me-1"></i> Asignar Mensajero:</label>
                    <select class="form-select form-select-sm border-primary" id="deliveryDriver">
                        <option value="">- Seleccionar -</option>
                        <?php foreach($mensajeros as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" id="cliPhone"><input type="hidden" id="cliAddr"><input type="hidden" id="invTicketId">
                <div class="form-check form-switch text-end"><input class="form-check-input" type="checkbox" id="printTicket" checked><label class="form-check-label small" for="printTicket">Imprimir Ticket</label></div>
            </div>
            <div class="modal-footer p-0 border-0"><button class="btn btn-primary w-100 py-3 fw-bold fs-4 rounded-0" id="btn-confirm-payment" onclick="confirmPayment()">CONFIRMAR PAGO</button></div>
        </div>
    </div>
</div>

<script>
window.setQuickAmount = function(amount) {
    const si = document.getElementById('singleAmountInput');
    if(si) {
        si.value = amount;
        calcChange();
    }
};

window.openPaymentModal = function() {
    if(cart.length === 0) return showToast('Carrito vacío', 'warning');
    if(!cashOpen) return showToast('CAJA CERRADA', 'error');
    
    let sub = cart.reduce((acc, i) => acc + ((i.price * (1 - i.discountPct/100)) * i.qty), 0);
    currentSaleTotal = sub * (1 - globalDiscountPct/100);
    document.getElementById('payment-total-due').innerText = '$' + currentSaleTotal.toFixed(2);
    
    ['payCash','payTransfer','payCard'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    
    const si = document.getElementById('singleAmountInput'); 
    if(si) {
        si.value = currentSaleTotal.toFixed(2);
    }
    
    calcChange();
    
    document.getElementById('modeCash').checked = true;
    setPaymentMode('cash');
    toggleServiceOptions();
    
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
    setTimeout(() => { if(si) si.select(); }, 500);
};

window.setPaymentMode = function(mode) {
    currentPaymentMode = mode;
    ['cashPaymentSection','simplePaymentSection','mixedPaymentSection'].forEach(id => document.getElementById(id).classList.add('d-none'));
    
    if(mode === 'cash') { 
        document.getElementById('cashPaymentSection').classList.remove('d-none'); 
        const si = document.getElementById('singleAmountInput');
        if(si && (!si.value || parseFloat(si.value) === 0)) {
            si.value = currentSaleTotal.toFixed(2);
        }
        calcChange();
        setTimeout(() => si.focus(), 100); 
    } 
    else if(mode === 'mixed') { 
        document.getElementById('mixedPaymentSection').classList.remove('d-none'); 
        document.getElementById('payCash').value = currentSaleTotal.toFixed(2); 
        calculateMixedTotals(); 
        setTimeout(() => document.getElementById('payCash').select(), 100); 
    } 
    else { 
        document.getElementById('simplePaymentSection').classList.remove('d-none'); 
    }
};

window.calcChange = function() {
    const r = parseFloat(document.getElementById('singleAmountInput').value) || 0;
    const c = r - currentSaleTotal;
    const d = document.getElementById('singleChangeDisplay');
    if(c >= -0.01) { 
        d.innerText = '$' + Math.max(0, c).toFixed(2); 
        d.className = 'h2 fw-bold text-primary mb-0'; 
    } else { 
        d.innerText = 'Falta: $' + Math.abs(c).toFixed(2); 
        d.className = 'h4 fw-bold text-danger mb-0'; 
    }
};

window.calculateMixedTotals = function() {
    const c = parseFloat(document.getElementById('payCash').value)||0;
    const t = parseFloat(document.getElementById('payTransfer').value)||0;
    const cd = parseFloat(document.getElementById('payCard').value)||0;
    const paid = c + t + cd;
    const rem = currentSaleTotal - paid;
    const pct = Math.min(100, (paid/currentSaleTotal)*100);
    const bar = document.getElementById('mixProgressBar'); 
    if(bar) { 
        bar.style.width = pct + '%'; 
        bar.className = 'progress-bar ' + (rem <= 0.01 ? 'bg-success' : 'bg-warning'); 
    }
    const rd = document.getElementById('mixRemaining'); 
    if(rd) { 
        if(rem > 0.01) { 
            rd.innerText = '$' + rem.toFixed(2); 
            rd.className = 'fw-bold text-danger fs-5'; 
            document.getElementById('btn-confirm-payment').disabled = true; 
        } else if (rem < -0.01) {
            rd.innerText = 'Exceso: $' + Math.abs(rem).toFixed(2); 
            rd.className = 'fw-bold text-info fs-5'; 
            document.getElementById('btn-confirm-payment').disabled = false;
        } else { 
            rd.innerText = 'PAGO COMPLETO'; 
            rd.className = 'fw-bold text-success fs-5'; 
            document.getElementById('btn-confirm-payment').disabled = false; 
        } 
    }
};

window.autoCompleteMixed = function(source) {
    const cashEl = document.getElementById('payCash');
    const transferEl = document.getElementById('payTransfer');
    const cardEl = document.getElementById('payCard');
    
    const cashVal = parseFloat(cashEl.value) || 0;
    const transferVal = parseFloat(transferEl.value) || 0;
    const cardVal = parseFloat(cardEl.value) || 0;

    if (source === 'cash') {
        const remaining = currentSaleTotal - cashVal - cardVal;
        transferEl.value = remaining > 0 ? remaining.toFixed(2) : "0.00";
    } else if (source === 'transfer') {
        const remaining = currentSaleTotal - transferVal - cardVal;
        cashEl.value = remaining > 0 ? remaining.toFixed(2) : "0.00";
    }
    
    calculateMixedTotals();
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
        if(del < (currentSaleTotal - 0.01)) return alert("Monto insuficiente");
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
        if(res.status === 'success') { Synth.cash(); if(document.getElementById('printTicket').checked) window.open('ticket_view.php?id='+res.id, 'Ticket', 'width=380,height=600'); else showToast('Venta #' + res.id + ' Registrada'); finishSale(); } else { alert('Error: ' + res.msg); }
    } catch(e) { saveOffline(payload); }
};
</script>

