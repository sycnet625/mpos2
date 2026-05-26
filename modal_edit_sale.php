<!-- ╔══════════════════════════════════════════════════════════╗ -->
<!-- ║  modal_edit_sale.php — Modal de edición de ventas        ║ -->
<!-- ╚══════════════════════════════════════════════════════════╝ -->

<div class="modal fade" id="editSaleModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-warning text-dark border-0 py-3">
                <h5 class="modal-title fw-bold fs-5">
                    <i class="fas fa-edit me-2"></i>Editar Venta
                    <span class="badge bg-dark text-white ms-2 fw-bold" id="editSaleIdBadge">#—</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">

                <!-- ── PRODUCTOS ── -->
                <div class="p-3 border-bottom bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-uppercase text-muted small"><i class="fas fa-box me-1"></i>Productos</span>
                        <button class="btn btn-sm btn-outline-success fw-bold" onclick="editSaleShowSearch()">
                            <i class="fas fa-plus me-1"></i>Agregar
                        </button>
                    </div>

                    <!-- Buscador (oculto por defecto) -->
                    <div id="editSaleSearchBox" class="d-none mb-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="editSaleSearchInput" class="form-control"
                                   placeholder="Buscar producto por nombre o código..."
                                   oninput="editSaleFilterProducts(this.value)">
                            <button class="btn btn-outline-secondary" onclick="editSaleHideSearch()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div id="editSaleSearchResults" class="list-group mt-1 shadow-sm"
                             style="max-height:180px;overflow-y:auto;"></div>
                    </div>

                    <!-- Tabla de items -->
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="editSaleItemsTable">
                            <thead class="table-secondary text-uppercase small">
                                <tr>
                                    <th>Producto</th>
                                    <th class="text-center" style="width:110px">Cant.</th>
                                    <th class="text-end" style="width:90px">Precio</th>
                                    <th class="text-end" style="width:90px">Subtotal</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="editSaleItemsTbody">
                                <!-- Renderizado por JS -->
                            </tbody>
                            <tfoot>
                                <tr id="editSaleTotalsRow" class="fw-bold border-top border-2">
                                    <td colspan="3" class="text-end text-muted small">TOTAL:</td>
                                    <td class="text-end text-success fs-5" id="editSaleTotalDisplay">$0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- ── DATOS DE LA VENTA ── -->
                <div class="p-3 border-bottom">
                    <span class="fw-bold text-uppercase text-muted small d-block mb-2"><i class="fas fa-file-invoice me-1"></i>Datos de la Venta</span>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="small text-muted fw-bold">Cliente</label>
                            <div class="input-group input-group-sm">
                                <select id="editSaleCliente" class="form-select">
                                    <option value="">Consumidor Final</option>
                                </select>
                                <button class="btn btn-outline-success" type="button" onclick="editSaleNewClient()" title="Crear Nuevo Cliente">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="small text-muted fw-bold">Tipo de Servicio</label>
                            <select id="editSaleTipoServicio" class="form-select form-select-sm"
                                    onchange="editSaleToggleDelivery()">
                                <option value="mostrador">🍽️ Aquí (Mostrador)</option>
                                <option value="llevar">🥡 Para Llevar</option>
                                <option value="mensajeria">🛵 Mensajería / Delivery</option>
                                <option value="reserva">📅 Reserva</option>
                            </select>
                        </div>
                        <div class="col-sm-6" id="editSaleDeliveryRow" style="display:none">
                            <label class="small text-muted fw-bold">Mensajero</label>
                            <select id="editSaleMensajero" class="form-select form-select-sm">
                                <option value="">Sin mensajero</option>
                            </select>
                        </div>
                        <div class="col-sm-6" id="editSaleDeliveryCostRow" style="display:none">
                            <label class="small text-muted fw-bold">Costo Mensajería ($)</label>
                            <input type="number" id="editSaleDeliveryCost" class="form-control form-control-sm text-end"
                                   placeholder="0.00" min="0" step="0.50" value="0"
                                   oninput="editSaleRecalcDelivery()">
                        </div>
                    </div>
                </div>

                <!-- ── MÉTODO DE PAGO ── -->
                <div class="p-3 border-bottom">
                    <span class="fw-bold text-uppercase text-muted small d-block mb-2"><i class="fas fa-wallet me-1"></i>Método de Pago</span>
                    <div id="editSalePaymentsContainer">
                        <!-- Renderizado por JS según METODOS_PAGO_POS -->
                    </div>
                </div>

                <!-- ── MOTIVO ── -->
                <div class="p-3">
                    <label class="small text-muted fw-bold text-uppercase">
                        <i class="fas fa-comment-alt me-1"></i>Motivo de la Edición <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="editSaleReason" class="form-control form-control-sm"
                           placeholder="Ej: Corrección de cantidad, cambio de cliente...">
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer border-0 bg-light p-3 gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning fw-bold px-4" id="editSaveSaleBtn"
                        onclick="editSaleSave()">
                    <i class="fas fa-save me-2"></i>Guardar Cambios
                </button>
            </div>

        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────────
//  Estado del editor de ventas
// ─────────────────────────────────────────────────────────────
let editSaleId         = 0;
let editSaleItems      = [];   // [{id, name, qty, price, codigo, es_servicio}]
let editSaleBaseTotal  = 0;    // total de ítems sin mensajería
let editSaleOrigVenta  = {};   // cabecera original (para comparar)

function editSaleNormalizeTipoServicio(tipo) {
    const raw = String(tipo || '').trim().toLowerCase();
    if (!raw) return 'mostrador';

    const map = {
        'mostrador': 'mostrador',
        'consumir_aqui': 'mostrador',
        'consumir aqui': 'mostrador',
        'aqui': 'mostrador',
        'aquí': 'mostrador',
        'mesa': 'mostrador',
        'llevar': 'llevar',
        'para llevar': 'llevar',
        'recoger': 'llevar',
        'pickup': 'llevar',
        'mensajeria': 'mensajeria',
        'delivery': 'mensajeria',
        'domicilio': 'mensajeria',
        'reserva': 'reserva'
    };

    return map[raw] || raw;
}

// ─────────────────────────────────────────────────────────────
//  Abrir modal y cargar venta
// ─────────────────────────────────────────────────────────────
window.openEditSale = async function(id) {
    editSaleId = id;
    document.getElementById('editSaleIdBadge').textContent = '#' + id;
    document.getElementById('editSaleMensajero').value = '';
    document.getElementById('editSaleDeliveryCost').value = '0';
    document.getElementById('editSaleReason').value = '';
    document.getElementById('editSaleSearchBox').classList.add('d-none');
    document.getElementById('editSaveSaleBtn').disabled = false;

    // Poblar datalists
    editSalePopulateLists();

    // Mostrar modal con spinner
    document.getElementById('editSaleItemsTbody').innerHTML =
        '<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-circle-notch fa-spin me-2"></i>Cargando...</td></tr>';
    new bootstrap.Modal(document.getElementById('editSaleModal')).show();

    try {
        const r = await fetch(`ventas_edit.php?action=load&id=${id}`);
        const data = await r.json();
        if (data.status !== 'success') {
            alert('Error: ' + data.msg);
            bootstrap.Modal.getInstance(document.getElementById('editSaleModal')).hide();
            return;
        }

        editSaleOrigVenta = data.venta;

        // Cargar ítems (id = codigo varchar, PK de productos)
        editSaleItems = (data.detalles || []).map(d => ({
            id:          String(d.id_producto),
            name:        d.nombre_producto,
            qty:         parseFloat(d.cantidad),
            price:       parseFloat(d.precio),
            codigo:      d.codigo_producto || d.id_producto || '',
            es_servicio: parseInt(d.es_servicio || 0),
            note:        d.nota_cocina || '',
        }));

        // Cargar campos de cabecera
        editSaleSetClientSelection(data.venta);
        document.getElementById('editSaleMensajero').value     = data.venta.mensajero_nombre || '';
        document.getElementById('editSaleTipoServicio').value  = editSaleNormalizeTipoServicio(data.venta.tipo_servicio);
        editSalePopulateLists();

        // Calcular costo mensajería como diferencia total - ítems
        const itemsSubtotal = editSaleItems.reduce((s, i) => s + i.qty * i.price, 0);
        const totalVenta    = parseFloat(data.venta.total);
        const costoEnvio    = Math.round((totalVenta - itemsSubtotal) * 100) / 100;
        if (costoEnvio > 0.01) {
            document.getElementById('editSaleDeliveryCost').value = costoEnvio.toFixed(2);
        } else {
            document.getElementById('editSaleDeliveryCost').value = '0';
        }

        editSaleToggleDelivery();
        editSaleRenderItems();                              // establece editSaleBaseTotal primero
        editSaleRenderPayments(data.pagos || [], totalVenta); // luego valida contra el total real

    } catch (e) {
        alert('Error al cargar la venta: ' + e.message);
    }
};

// ─────────────────────────────────────────────────────────────
//  Render tabla de ítems
// ─────────────────────────────────────────────────────────────
function editSaleRenderItems() {
    const tbody = document.getElementById('editSaleItemsTbody');
    if (!editSaleItems.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sin productos</td></tr>';
        editSaleRenderTotal();
        return;
    }
    tbody.innerHTML = editSaleItems.map((item, i) => `
        <tr>
            <td class="small fw-bold">${escHtml(item.name)}<br>
                <span class="text-muted" style="font-size:0.7rem">${escHtml(item.codigo)}</span>
                ${item.note ? `<div class="mt-1"><span class="badge bg-warning text-dark">📝 ${escHtml(item.note)}</span></div>` : ''}
                <input type="text" class="form-control form-control-sm mt-1 edit-sale-note-input"
                       placeholder="Nota de cocina"
                       value="${escHtml(item.note || '')}"
                       oninput="editSaleSetNote(${i}, this.value)">
            </td>
            <td class="text-center">
                <div class="input-group input-group-sm justify-content-center" style="width:100px;margin:auto">
                    <button class="btn btn-outline-secondary px-2 py-0" onclick="editSaleChangeQty(${i}, -1)">−</button>
                    <input type="number" class="form-control form-control-sm text-center fw-bold px-1"
                           style="max-width:46px" min="0.01" step="1" value="${item.qty}"
                           onchange="editSaleSetQty(${i}, this.value)">
                    <button class="btn btn-outline-secondary px-2 py-0" onclick="editSaleChangeQty(${i}, 1)">+</button>
                </div>
            </td>
            <td class="text-end small">$${item.price.toFixed(2)}</td>
            <td class="text-end fw-bold">$${(item.qty * item.price).toFixed(2)}</td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="editSaleRemoveItem(${i})"
                        title="Eliminar">
                    <i class="fas fa-trash-alt" style="font-size:0.7rem"></i>
                </button>
            </td>
        </tr>
    `).join('');
    editSaleRenderTotal();
}

function editSaleRenderTotal() {
    editSaleBaseTotal = editSaleItems.reduce((s, i) => s + i.qty * i.price, 0);
    const cost  = parseFloat(document.getElementById('editSaleDeliveryCost')?.value || 0);
    const total = editSaleBaseTotal + cost;
    document.getElementById('editSaleTotalDisplay').textContent = '$' + total.toFixed(2);
    // Ajustar pagos: si hay un solo método activo, actualizarlo al nuevo total
    const inputs = Array.from(document.querySelectorAll('.edit-sale-payment-input'));
    const filled = inputs.filter(i => parseFloat(i.value) > 0);
    if (inputs.length > 0 && filled.length <= 1) {
        inputs[0].value = total.toFixed(2);
        inputs.slice(1).forEach(i => i.value = '');
    }
    editSaleCheckPayments();
}

// ─────────────────────────────────────────────────────────────
//  Manipulación de ítems
// ─────────────────────────────────────────────────────────────
window.editSaleChangeQty = function(i, delta) {
    editSaleItems[i].qty = Math.max(0.01, editSaleItems[i].qty + delta);
    editSaleRenderItems();
};
window.editSaleSetQty = function(i, val) {
    const q = parseFloat(val);
    if (q > 0) { editSaleItems[i].qty = q; editSaleRenderItems(); }
};
window.editSaleRemoveItem = function(i) {
    editSaleItems.splice(i, 1);
    editSaleRenderItems();
};
window.editSaleSetNote = function(i, val) {
    if (!editSaleItems[i]) return;
    editSaleItems[i].note = String(val || '');
};

// ─────────────────────────────────────────────────────────────
//  Buscador de productos
// ─────────────────────────────────────────────────────────────
window.editSaleShowSearch = function() {
    document.getElementById('editSaleSearchBox').classList.remove('d-none');
    document.getElementById('editSaleSearchInput').value = '';
    document.getElementById('editSaleSearchResults').innerHTML = '';
    setTimeout(() => document.getElementById('editSaleSearchInput').focus(), 100);
};
window.editSaleHideSearch = function() {
    document.getElementById('editSaleSearchBox').classList.add('d-none');
};

window.editSaleFilterProducts = function(query) {
    const q = query.trim().toLowerCase();
    const container = document.getElementById('editSaleSearchResults');
    if (!q) { container.innerHTML = ''; return; }

    // Usar el catálogo del POS
    const source = window.productsDB || window.PRODUCTS_DATA || [];
    const results = source.filter(p =>
        (p.nombre || '').toLowerCase().includes(q) ||
        (p.codigo || '').toLowerCase().includes(q)
    ).slice(0, 12);

    if (!results.length) {
        container.innerHTML = '<div class="list-group-item text-muted small">Sin resultados</div>';
        return;
    }
    container.innerHTML = results.map(p => {
        const name   = p.nombre || '';
        const codigo = p.codigo || '';
        const price  = parseFloat(p.precio_venta || p.precio || p.price || 0);
        return `<button class="list-group-item list-group-item-action py-2 px-3 small edit-prod-result"
                        data-codigo="${escHtml(codigo)}"
                        data-name="${escHtml(name)}"
                        data-price="${price}">
                    <span class="fw-bold">${escHtml(name)}</span>
                    <span class="text-muted ms-2 small">${escHtml(codigo)}</span>
                    <span class="float-end fw-bold text-success">$${price.toFixed(2)}</span>
                </button>`;
    }).join('');

    // Listener delegado — evita problemas con comillas en onclick inline
    container.querySelectorAll('.edit-prod-result').forEach(btn => {
        btn.addEventListener('click', () => {
            editSaleAddProduct(btn.dataset.codigo, btn.dataset.name, parseFloat(btn.dataset.price), btn.dataset.codigo);
        });
    });
};

window.editSaleAddProduct = function(id, name, price, codigo) {
    const sid = String(id);
    const existing = editSaleItems.find(i => i.id === sid);
    if (existing) {
        existing.qty += 1;
    } else {
        editSaleItems.push({ id: sid, name, qty: 1, price, codigo: codigo || sid, es_servicio: 0, note: '' });
    }
    editSaleHideSearch();
    editSaleRenderItems();
};

// ─────────────────────────────────────────────────────────────
//  Mensajería
// ─────────────────────────────────────────────────────────────
window.editSaleToggleDelivery = function() {
    const t = document.getElementById('editSaleTipoServicio').value;
    const currentMessenger = String(document.getElementById('editSaleMensajero')?.value || '').trim();
    const isDelivery = (t === 'mensajeria' || t === 'delivery') || currentMessenger.length > 0;
    document.getElementById('editSaleDeliveryRow').style.display     = isDelivery ? '' : 'none';
    document.getElementById('editSaleDeliveryCostRow').style.display = isDelivery ? '' : 'none';
    if (!isDelivery) document.getElementById('editSaleDeliveryCost').value = '0';
    editSaleRenderTotal();
};
window.editSaleRecalcDelivery = function() {
    editSaleRenderTotal();
};

// ─────────────────────────────────────────────────────────────
//  Poblar listas
// ─────────────────────────────────────────────────────────────
function editSaleClientsData() {
    if (Array.isArray(window.CLIENTS_DATA)) return window.CLIENTS_DATA;
    if (typeof CLIENTS_DATA !== 'undefined' && Array.isArray(CLIENTS_DATA)) return CLIENTS_DATA;
    return [];
}

function editSalePopulateLists() {
    const cliSelect = document.getElementById('editSaleCliente');
    const msjSelect = document.getElementById('editSaleMensajero');
    
    if (cliSelect) {
        const currentValue = cliSelect.value;
        cliSelect.innerHTML = '<option value="">Consumidor Final</option>' + editSaleClientsData().map(c => {
            const tel = c.telefono ? ` · ${c.telefono}` : '';
            const nit = c.nit_ci ? ` · ${c.nit_ci}` : '';
            return `<option value="${escHtml(String(c.id || ''))}" data-name="${escHtml(c.nombre || '')}" data-tel="${escHtml(c.telefono || '')}" data-dir="${escHtml(c.direccion || '')}">${escHtml((c.nombre || 'Cliente') + tel + nit)}</option>`;
        }).join('');
        if (currentValue) cliSelect.value = currentValue;
    }
    
    if (msjSelect) {
        const currentValue = String(msjSelect.value || '').trim();
        const rawMessengers = Array.isArray(window.MESSENGERS_DATA)
            ? window.MESSENGERS_DATA
            : (typeof MESSENGERS_DATA !== 'undefined' && Array.isArray(MESSENGERS_DATA) ? MESSENGERS_DATA : []);
        const uniqueMessengers = [];
        rawMessengers.forEach(m => {
            const name = String(m || '').trim();
            if (name && !uniqueMessengers.includes(name)) {
                uniqueMessengers.push(name);
            }
        });
        if (currentValue && !uniqueMessengers.includes(currentValue)) {
            uniqueMessengers.unshift(currentValue);
        }
        msjSelect.innerHTML = '<option value="">Sin mensajero</option>' + uniqueMessengers.map(m =>
            `<option value="${escHtml(m)}">${escHtml(m)}</option>`
        ).join('');
        if (currentValue) {
            msjSelect.value = currentValue;
        }
    }
}

function editSaleSetClientSelection(venta) {
    const select = document.getElementById('editSaleCliente');
    if (!select) return;
    const idCliente = String(venta?.id_cliente || '');
    const clienteNombre = String(venta?.cliente_nombre || '').trim();
    editSalePopulateLists();
    if (idCliente && Array.from(select.options).some(opt => opt.value === idCliente)) {
        select.value = idCliente;
        return;
    }
    if (clienteNombre) {
        const found = Array.from(select.options).find(opt => (opt.getAttribute('data-name') || '').trim() === clienteNombre);
        if (found) {
            select.value = found.value;
            return;
        }
    }
    select.value = '';
}

// ─────────────────────────────────────────────────────────────
//  Nuevo Cliente (Botón +)
// ─────────────────────────────────────────────────────────────
function editSaleNewClient() {
    // Abrir el modal de nuevo cliente que ya existe en pos.php
    if (typeof bootstrap !== 'undefined' && document.getElementById('newClientModal')) {
        const ncModal = new bootstrap.Modal(document.getElementById('newClientModal'));
        ncModal.show();
        
        // Al cerrar el modal de nuevo cliente, refrescar los datalists aquí
        document.getElementById('newClientModal').addEventListener('hidden.bs.modal', function () {
            editSalePopulateLists();
        }, { once: true });
    } else {
        alert("El modal de creación de clientes no está disponible.");
    }
}

// ─────────────────────────────────────────────────────────────
//  Pagos
// ─────────────────────────────────────────────────────────────
function editSaleRenderPayments(pagos, total) {
    const container = document.getElementById('editSalePaymentsContainer');
    const metodos   = window.METODOS_PAGO_POS || [{ id: 'Efectivo', nombre: 'Efectivo', color_bootstrap: 'success' }];

    // Construir mapa de pagos existentes
    const pagoMap = {};
    (pagos || []).forEach(p => { pagoMap[p.metodo_pago] = parseFloat(p.monto); });

    container.innerHTML = metodos.map(m => {
        const val = pagoMap[m.id] !== undefined ? pagoMap[m.id] : 0;
        return `
        <div class="input-group input-group-sm mb-2">
            <span class="input-group-text bg-${m.color_bootstrap || 'secondary'} text-white fw-bold"
                  style="width:140px">${m.nombre}</span>
            <input type="number" class="form-control text-end edit-sale-payment-input"
                   data-method="${escHtml(m.id)}"
                   value="${val > 0 ? val.toFixed(2) : ''}"
                   placeholder="0.00" min="0" step="0.01"
                   oninput="editSaleCheckPayments()">
        </div>`;
    }).join('');

    editSaleDistributePayment(total);
}

function editSaleDistributePayment(total) {
    // Si solo hay un método activo (suma 0), rellenar el primero
    const inputs = Array.from(document.querySelectorAll('.edit-sale-payment-input'));
    const sum = inputs.reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
    if (sum < 0.01 && inputs.length > 0) {
        inputs[0].value = total.toFixed(2);
    }
    editSaleCheckPayments();
}

function editSaleCheckPayments() {
    const inputs  = Array.from(document.querySelectorAll('.edit-sale-payment-input'));
    const paid    = inputs.reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
    const cost    = parseFloat(document.getElementById('editSaleDeliveryCost')?.value || 0);
    const base    = editSaleItems.reduce((s, i) => s + i.qty * i.price, 0);
    const total   = base + cost;
    const diff    = Math.abs(paid - total);
    const ok      = diff < 0.05;
    const btn     = document.getElementById('editSaveSaleBtn');
    if (btn) btn.disabled = !ok;
    // Feedback visual
    inputs.forEach(i => {
        i.classList.toggle('border-danger', !ok);
        i.classList.toggle('border-success', ok);
    });
}

// ─────────────────────────────────────────────────────────────
//  Guardar
// ─────────────────────────────────────────────────────────────
window.editSaleSave = async function() {
    if (!editSaleItems.length) return alert('Debe haber al menos un producto');
    const reason = document.getElementById('editSaleReason').value.trim();
    if (reason.length < 3) {
        document.getElementById('editSaleReason').focus();
        return alert('Ingrese un motivo de edición (mínimo 3 caracteres)');
    }

    const cost     = parseFloat(document.getElementById('editSaleDeliveryCost').value) || 0;
    const total    = editSaleBaseTotal + cost;
    const clienteSelect = document.getElementById('editSaleCliente');
    const selectedClientOption = clienteSelect ? clienteSelect.options[clienteSelect.selectedIndex] : null;
    const clienteId = clienteSelect && clienteSelect.value ? parseInt(clienteSelect.value, 10) || 0 : 0;
    const cliente  = selectedClientOption && clienteId > 0
        ? (selectedClientOption.getAttribute('data-name') || selectedClientOption.textContent || '').trim()
        : 'Mostrador';
    const mensajero = document.getElementById('editSaleMensajero').value.trim();
    const tipoServ = document.getElementById('editSaleTipoServicio').value;

    // Construir pagos
    const payments = [];
    const methods  = [];
    document.querySelectorAll('.edit-sale-payment-input').forEach(inp => {
        const amt = parseFloat(inp.value) || 0;
        if (amt > 0) {
            payments.push({ method: inp.dataset.method, amount: amt });
            methods.push(inp.dataset.method);
        }
    });
    const metodoPago = methods.length > 1 ? 'Mixto' : (methods[0] || 'Efectivo');

    const btn = document.getElementById('editSaveSaleBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Guardando...';

    try {
        const r = await fetch('ventas_edit.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_venta:        editSaleId,
                items:           editSaleItems,
                payments:        payments,
                total:           total,
                id_cliente:      clienteId,
                cliente_nombre:  cliente,
                mensajero_nombre: mensajero,
                tipo_servicio:   tipoServ,
                metodo_pago:     metodoPago,
                edit_reason:     reason,
            }),
        });
        const res = await r.json();
        if (res.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('editSaleModal')).hide();
            if (typeof showToast === 'function') showToast(res.msg, 'success');
            else alert(res.msg);
            // Recargar historial si está abierto
            if (typeof loadHistorialContent === 'function') loadHistorialContent();
        } else {
            alert('Error: ' + res.msg);
        }
    } catch (e) {
        alert('Error de red: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Cambios';
    }
};

// ─────────────────────────────────────────────────────────────
//  Utils
// ─────────────────────────────────────────────────────────────
function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
