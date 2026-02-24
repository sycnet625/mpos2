<?php
// Variables de config inyectadas desde pos.php (que ya cargó config_loader.php)
$metodosPosActivos = array_values(array_filter(
    $config['metodos_pago'] ?? [],
    fn($m) => ($m['activo'] ?? false) && ($m['aplica_pos'] ?? true)
));
?>
<script>
window.METODOS_PAGO_POS  = <?= json_encode($metodosPosActivos, JSON_UNESCAPED_UNICODE) ?>;
window.POS_TC_USD        = <?= floatval($config['tipo_cambio_usd'] ?? 385) ?>;
window.POS_TC_MLC        = <?= floatval($config['tipo_cambio_mlc'] ?? 310) ?>;
window.POS_MONEDA_DEFAULT = <?= json_encode($config['moneda_default_pos'] ?? 'CUP') ?>;
</script>

<div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary-custom text-white border-0">
                <h5 class="modal-title fw-bold">Finalizar Venta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4 px-4">
                <div class="text-center mb-4"><div class="total-display-large" id="payment-total-due">$0.00</div></div>

                <!-- Botones de método de pago (dinámicos desde config) -->
                <div id="paymentMethodsGroup" class="btn-group w-100 mb-2 shadow-sm" role="group">
                    <!-- Renderizado por renderPaymentMethodsPOS() -->
                </div>

                <!-- Selector de moneda -->
                <div class="d-flex align-items-center gap-2 mb-3">
                    <label class="small fw-bold text-muted">Moneda:</label>
                    <div class="btn-group btn-group-sm" id="posCurrencyGroup">
                        <button class="btn btn-outline-secondary" onclick="setCurrencyPOS('CUP')">CUP</button>
                        <button class="btn btn-outline-secondary" onclick="setCurrencyPOS('USD')">USD</button>
                        <button class="btn btn-outline-secondary" onclick="setCurrencyPOS('MLC')">MLC</button>
                    </div>
                    <span class="small text-muted" id="posExchangeDisplay"></span>
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

                <div id="especialPaymentSection" class="d-none"></div>

                <div id="simplePaymentSection" class="d-none"><div class="alert alert-info text-center"><i class="fas fa-check-circle me-1"></i> Pago por el monto exacto.</div></div>

                <div id="mixedPaymentSection" class="d-none">
                    <div class="progress mb-3" style="height: 10px;"><div class="progress-bar bg-success" id="mixProgressBar" role="progressbar" style="width: 0%"></div></div>
                    <div id="mixedInputsContainer"></div>
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
// ───── Estado del modal de pago ─────
let posCurrentCurrency = 'CUP';
let posCurrentTC       = 1.0;

window.setQuickAmount = function(amount) {
    const si = document.getElementById('singleAmountInput');
    if(si) { si.value = amount; calcChange(); }
};

// ───── Render dinámico de botones de método ─────
function renderPaymentMethodsPOS() {
    const container = document.getElementById('paymentMethodsGroup');
    const metodos   = window.METODOS_PAGO_POS || [];
    container.innerHTML = '';
    metodos.forEach((m, i) => {
        const safeId = 'mode_' + m.id.replace(/\W/g, '_');
        const isWarning = m.color_bootstrap === 'warning';
        container.innerHTML += `
            <input type="radio" class="btn-check" name="paymentMode" id="${safeId}" autocomplete="off"
                   ${i === 0 ? 'checked' : ''} onclick="setPaymentMode('${m.id.replace(/'/g,"\\'")}')">
            <label class="btn btn-outline-${m.color_bootstrap} fw-bold${isWarning ? ' text-dark' : ''}" for="${safeId}">
                <i class="fas ${m.icono} me-1"></i>${m.nombre}
            </label>`;
    });
    if (metodos.length > 1) {
        container.innerHTML += `
            <input type="radio" class="btn-check" name="paymentMode" id="modeMixed" autocomplete="off"
                   onclick="setPaymentMode('mixed')">
            <label class="btn btn-outline-secondary fw-bold" for="modeMixed">
                <i class="fas fa-calculator me-1"></i>Mixto
            </label>`;
    }
}

// ───── Render dinámico de inputs mixtos ─────
function renderMixedInputsPOS() {
    const container = document.getElementById('mixedInputsContainer');
    const metodos   = window.METODOS_PAGO_POS || [];
    container.innerHTML = '';
    metodos.forEach(m => {
        const color  = m.color_bootstrap || 'secondary';
        const dark   = color === 'warning' ? 'text-dark' : 'text-white';
        container.innerHTML += `
            <div class="input-group mb-2">
                <span class="input-group-text bg-${color} ${dark}" style="width:130px; cursor:pointer;"
                      onclick="fillRemainingMixed('${m.id.replace(/'/g,"\\'")}')"
                      title="Click para completar restante">${m.nombre}</span>
                <input type="number" class="form-control split-payment-input text-end"
                       id="payMixed_${m.id.replace(/\W/g,'_')}" data-method-id="${m.id}"
                       placeholder="0.00" min="0" step="0.01"
                       oninput="autoCompleteMixedDynamic('${m.id.replace(/'/g,"\\'")}')">
            </div>`;
    });
}

// ───── Selector de moneda ─────
window.setCurrencyPOS = function(moneda) {
    posCurrentCurrency = moneda;
    const rates = { CUP: 1.0, USD: window.POS_TC_USD, MLC: window.POS_TC_MLC };
    posCurrentTC = rates[moneda] || 1.0;
    document.getElementById('posExchangeDisplay').textContent =
        moneda === 'CUP' ? '' : `1 ${moneda} = ${posCurrentTC.toFixed(2)} CUP`;
    document.querySelectorAll('#posCurrencyGroup button').forEach(b =>
        b.classList.toggle('active', b.textContent.trim() === moneda));

    // Actualizar display del total
    const montoDisplay = currentSaleTotal / posCurrentTC;
    const sym = moneda === 'CUP' ? '$' : moneda + ' ';
    document.getElementById('payment-total-due').innerText = sym + montoDisplay.toFixed(2);

    // Actualizar botones de cantidad rápida según moneda
    const quickAmounts = moneda === 'CUP' ? [200, 500, 1000, 2000] : [1, 5, 10, 20];
    document.querySelectorAll('[onclick^="setQuickAmount"]').forEach((btn, i) => {
        const amt = quickAmounts[i] ?? quickAmounts[0];
        btn.textContent = amt;
        btn.setAttribute('onclick', `setQuickAmount(${amt})`);
    });
    if (currentPaymentMode !== 'mixed') calcChange();
};

// ───── Modo de pago ─────
window.setPaymentMode = function(mode) {
    currentPaymentMode = mode;
    ['cashPaymentSection', 'simplePaymentSection', 'mixedPaymentSection'].forEach(id =>
        document.getElementById(id).classList.add('d-none'));
    document.getElementById('especialPaymentSection').classList.add('d-none');
    document.getElementById('especialPaymentSection').innerHTML = '';

    const metodoEfectivo = (window.METODOS_PAGO_POS || [])[0]?.id || 'Efectivo';
    if (mode === metodoEfectivo) {
        document.getElementById('cashPaymentSection').classList.remove('d-none');
        const si = document.getElementById('singleAmountInput');
        if (si && (!si.value || parseFloat(si.value) === 0)) si.value = (currentSaleTotal / posCurrentTC).toFixed(2);
        calcChange();
        setTimeout(() => si?.focus(), 100);
    } else if (mode === 'mixed') {
        document.getElementById('mixedPaymentSection').classList.remove('d-none');
        const first = document.querySelector('.split-payment-input');
        if (first) { first.value = currentSaleTotal.toFixed(2); }
        calculateMixedTotals();
        setTimeout(() => first?.select(), 100);
    } else {
        document.getElementById('simplePaymentSection').classList.remove('d-none');
    }

    // Mostrar texto especial si el método lo tiene
    const metodoObj = (window.METODOS_PAGO_POS || []).find(m => m.id === mode);
    if (metodoObj?.es_especial && metodoObj?.texto_especial) {
        const espSection = document.getElementById('especialPaymentSection');
        espSection.innerHTML = `<div class="alert alert-warning border-0 text-center mb-2"><i class="fas fa-info-circle me-1"></i> ${metodoObj.texto_especial.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</div>`;
        espSection.classList.remove('d-none');
    }
};

// ───── Cálculo de cambio ─────
window.calcChange = function() {
    const r   = parseFloat(document.getElementById('singleAmountInput').value) || 0;
    const totalEnMoneda = currentSaleTotal / posCurrentTC;
    const c   = r - totalEnMoneda;
    const d   = document.getElementById('singleChangeDisplay');
    const sym = posCurrentCurrency === 'CUP' ? '$' : posCurrentCurrency + ' ';
    if (c >= -0.01) {
        d.innerText = sym + Math.max(0, c).toFixed(2);
        d.className = 'h2 fw-bold text-primary mb-0';
    } else {
        d.innerText = 'Falta: ' + sym + Math.abs(c).toFixed(2);
        d.className = 'h4 fw-bold text-danger mb-0';
    }
};

// ───── Totales modo mixto ─────
window.calculateMixedTotals = function() {
    const inputs = document.querySelectorAll('.split-payment-input');
    let paid = 0;
    inputs.forEach(inp => paid += parseFloat(inp.value) || 0);
    const rem = currentSaleTotal - paid;
    const pct = Math.min(100, (paid / currentSaleTotal) * 100);
    const bar = document.getElementById('mixProgressBar');
    if (bar) { bar.style.width = pct + '%'; bar.className = 'progress-bar ' + (rem <= 0.01 ? 'bg-success' : 'bg-warning'); }
    const rd  = document.getElementById('mixRemaining');
    if (rd) {
        if (rem > 0.01) {
            rd.innerText = '$' + rem.toFixed(2); rd.className = 'fw-bold text-danger fs-5';
            document.getElementById('btn-confirm-payment').disabled = true;
        } else if (rem < -0.01) {
            rd.innerText = 'Exceso: $' + Math.abs(rem).toFixed(2); rd.className = 'fw-bold text-info fs-5';
            document.getElementById('btn-confirm-payment').disabled = false;
        } else {
            rd.innerText = 'PAGO COMPLETO'; rd.className = 'fw-bold text-success fs-5';
            document.getElementById('btn-confirm-payment').disabled = false;
        }
    }
};

window.fillRemainingMixed = function(targetMethodId) {
    const inputs = Array.from(document.querySelectorAll('.split-payment-input'));
    const safeId = targetMethodId.replace(/\W/g, '_');
    const target = document.getElementById('payMixed_' + safeId);
    if (!target) return;
    let otherTotal = 0;
    inputs.forEach(inp => {
        if (inp.dataset.methodId !== targetMethodId)
            otherTotal += parseFloat(inp.value) || 0;
    });
    const remaining = currentSaleTotal - otherTotal;
    target.value = remaining > 0 ? remaining.toFixed(2) : '0.00';
    calculateMixedTotals();
};

function autoCompleteMixedDynamic(sourceId) {
    const inputs = Array.from(document.querySelectorAll('.split-payment-input'));
    if (inputs.length === 2) {
        const safeSourceId = sourceId.replace(/\W/g, '_');
        const src   = document.getElementById('payMixed_' + safeSourceId);
        const other = inputs.find(i => i.dataset.methodId !== sourceId);
        if (src && other) {
            const rem = currentSaleTotal - (parseFloat(src.value) || 0);
            other.value = rem > 0 ? rem.toFixed(2) : '0.00';
        }
    }
    calculateMixedTotals();
}

// ───── Abrir modal ─────
window.openPaymentModal = function() {
    if (cart.length === 0) return showToast('Carrito vacío', 'warning');
    if (!cashOpen) return showToast('CAJA CERRADA', 'error');

    let sub = cart.reduce((acc, i) => acc + ((i.price * (1 - i.discountPct / 100)) * i.qty), 0);
    currentSaleTotal = sub * (1 - globalDiscountPct / 100);

    // Limpiar inputs mixtos
    document.querySelectorAll('.split-payment-input').forEach(el => el.value = '');

    const si = document.getElementById('singleAmountInput');
    if (si) si.value = '';

    // Renderizar métodos dinámicos
    renderPaymentMethodsPOS();
    renderMixedInputsPOS();

    // Seleccionar moneda default
    setCurrencyPOS(window.POS_MONEDA_DEFAULT || 'CUP');

    // Seleccionar primer método
    const firstRadio = document.querySelector('input[name="paymentMode"]');
    if (firstRadio) {
        firstRadio.checked = true;
        setPaymentMode(firstRadio.id.replace('mode_', '').replace(/_/g, ' '));
        // Usar el id real del método
        const firstMethod = (window.METODOS_PAGO_POS || [])[0];
        if (firstMethod) setPaymentMode(firstMethod.id);
    }

    toggleServiceOptions();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
    setTimeout(() => { if (si) si.select(); }, 500);
};

window.toggleServiceOptions = function() {
    const t = document.getElementById('serviceType').value;
    document.getElementById('deliveryDiv').classList.add('d-none');
    document.getElementById('reservationDiv').classList.add('d-none');
    if (t === 'mensajeria' || t === 'delivery') document.getElementById('deliveryDiv').classList.remove('d-none');
    if (t === 'reserva') document.getElementById('reservationDiv').classList.remove('d-none');
};

// ───── Confirmar pago ─────
window.confirmPayment = async function() {
    let payments   = [];
    let mainMethod = 'Efectivo';
    const metodoEfectivo = (window.METODOS_PAGO_POS || [])[0]?.id || 'Efectivo';

    if (currentPaymentMode === 'mixed') {
        mainMethod = 'Mixto';
        let total = 0;
        document.querySelectorAll('.split-payment-input').forEach(inp => {
            const amt = parseFloat(inp.value) || 0;
            if (amt > 0) { payments.push({ method: inp.dataset.methodId, amount: amt }); total += amt; }
        });
        if (total < (currentSaleTotal - 0.05)) return alert("Pago incompleto");
    } else {
        mainMethod = currentPaymentMode;
        if (currentPaymentMode === metodoEfectivo) {
            const del = parseFloat(document.getElementById('singleAmountInput').value) || 0;
            const totalEnMoneda = currentSaleTotal / posCurrentTC;
            if (del < (totalEnMoneda - 0.01)) return alert("Monto insuficiente");
        }
        payments.push({ method: mainMethod, amount: currentSaleTotal });
    }

    const cliName = document.getElementById('cliName').value || 'Mostrador';
    const serv    = document.getElementById('serviceType').value;
    let msj = '';
    if (serv === 'mensajeria' || serv === 'delivery') {
        msj = document.getElementById('deliveryDriver').value;
        if (!msj) return alert("Seleccione mensajero");
    }
    let rDate = '', rAbono = 0;
    if (serv === 'reserva') {
        rDate  = document.getElementById('reservationDate').value;
        rAbono = document.getElementById('reservationAbono').value;
        if (!rDate || !rAbono) return alert("Datos reserva incompletos");
    }

    const payload = {
        uuid:                 crypto.randomUUID(),
        items:                cart.map(i => ({ id: i.id, name: i.name, qty: i.qty, price: i.price, note: i.note })),
        total:                currentSaleTotal,
        payments:             payments,
        metodo_pago:          mainMethod,
        tipo_servicio:        serv,
        cliente_nombre:       cliName,
        mensajero_nombre:     msj,
        fecha_reserva:        rDate,
        abono:                rAbono,
        id_caja:              cashId,
        timestamp:            Date.now(),
        moneda:               posCurrentCurrency,
        tipo_cambio:          posCurrentTC,
        monto_moneda_original: parseFloat((currentSaleTotal / posCurrentTC).toFixed(2)),
    };

    bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
    try {
        const r   = await fetch('pos_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const res = await r.json();
        if (res.status === 'success') {
            Synth.cash();
            if (document.getElementById('printTicket').checked) window.open('ticket_view.php?id=' + res.id, 'Ticket', 'width=380,height=600');
            else showToast('Venta #' + res.id + ' Registrada');
            finishSale();
        } else {
            alert('Error: ' + res.msg);
        }
    } catch (e) { saveOffline(payload); }
};
</script>
