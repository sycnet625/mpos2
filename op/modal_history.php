<div class="modal fade" id="historialModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2 shadow-sm">
                <h5 class="modal-title fw-bold"><i class="fas fa-history me-2"></i>Historial de Tickets</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="historialModalBody">
                <div class="text-center p-5">
                    <i class="fas fa-circle-notch fa-spin fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Cargando movimientos...</p>
                </div>
            </div>
            <div class="modal-footer py-1 bg-light">
                <small class="text-muted ms-auto">Sesión Actual</small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Colores suaves para los renglones */
    .row-efectivo { background-color: #d1e7dd !important; }      /* Verde */
    .row-transferencia { background-color: #cff4fc !important; } /* Azul */
    .row-tarjeta { background-color: #fff3cd !important; }       /* Amarillo */
    .row-mixto { background-color: #e2e3e5 !important; }         /* Gris */
    .row-devolucion { background-color: #f8d7da !important; }    /* Rojo */
    
    .ticket-row { transition: all 0.2s; border-bottom: 1px solid #dee2e6; }
    .ticket-row:hover { filter: brightness(0.95); cursor: pointer; }
    .badge-pago { min-width: 85px; }
</style>

<script>
// Variable global para detalles
window.historyDetailsMap = {};

window.showHistorialModal = async function() {
    const modal = new bootstrap.Modal(document.getElementById('historialModal'));
    const body = document.getElementById('historialModalBody');
    body.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Consultando servidor...</p></div>';
    modal.show();
    
    try {
        console.log("Iniciando petición historial..."); // DEBUG
        
        // Usamos timestamp para romper caché
        const url = `pos2.php?load_history=1&session_id=${CURRENT_SESSION_ID}&t=${Date.now()}`;
        const r = await fetch(url);
        
        // Verificamos si la respuesta es JSON válido
        const text = await r.text();
        let d;
        try {
            d = JSON.parse(text);
            body.innerHTML = `<div class="p-5 text-center text-danger">Error: El servidor devolvió datos inválidos.<br><small>${text.substring(0, 100)}...</small></div>`;

        } catch (err) {
            console.error("Respuesta no es JSON:", text);
            body.innerHTML = `<div class="p-5 text-center text-danger">Error: El servidor devolvió datos inválidos.<br><small>${text.substring(0, 100)}...</small></div>`;
            return;
        }
        
        console.log("Datos recibidos:", d); // DEBUG: Mira esto en la consola F12

        if(d.status === 'success') {
            renderHistorial(body, d);
        } else {
            body.innerHTML = `<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>Error del Sistema: ${d.msg}</div>`;
        }
    } catch(e) { 
        console.error(e);
        body.innerHTML = '<div class="p-5 text-center text-danger"><i class="fas fa-wifi fa-2x mb-2"></i><br>Error de conexión JS</div>'; 
    }
};

window.renderHistorial = function(body, data) {
    const tickets = data.tickets || [];
    const detalles = data.detalles || [];
    const totales = data.totales || { total: 0, count: 0, valor_devoluciones: 0 };
    
    if (tickets.length === 0) { 
        body.innerHTML = '<div class="p-5 text-center text-muted"><i class="fas fa-receipt fa-3x mb-3 opacity-50"></i><h5>Sin movimientos</h5><p>No se han encontrado ventas en la sesión actual.</p></div>'; 
        return; 
    }
    
    // Agrupar detalles (Mapeo robusto)
    window.historyDetailsMap = {};
    detalles.forEach(d => { 
        if(!window.historyDetailsMap[d.id_venta_cabecera]) window.historyDetailsMap[d.id_venta_cabecera] = [];
        window.historyDetailsMap[d.id_venta_cabecera].push(d); 
    });
    
    // 1. Renderizar KPIs (Cabecera)
    let html = `
    <div class="sticky-top bg-white shadow-sm border-bottom">
        <div class="d-flex justify-content-around p-3">
            <div class="text-center">
                <div class="text-muted small fw-bold text-uppercase">Ventas Netas</div>
                <div class="fs-4 fw-bold text-success">$${parseFloat(totales.total || 0).toFixed(2)}</div>
            </div>
            <div class="text-center border-start border-end px-4">
                <div class="text-muted small fw-bold text-uppercase">Tickets</div>
                <div class="fs-4 fw-bold text-dark">${totales.count}</div>
            </div>
            <div class="text-center">
                <div class="text-muted small fw-bold text-uppercase">Devoluciones</div>
                <div class="fs-4 fw-bold text-danger">$${parseFloat(totales.valor_devoluciones || 0).toFixed(2)}</div>
            </div>
        </div>
    </div>`;
    
    // 2. Renderizar Tabla
    html += '<div class="table-responsive"><table class="table mb-0 align-middle" style="font-size:0.95rem;">';
    html += '<thead class="bg-light text-secondary"><tr><th width="40"></th><th>ID</th><th>Hora</th><th>Cliente</th><th>Tipo</th><th>Total</th><th>Pago</th><th class="text-end">Acción</th></tr></thead><tbody>';
    
    tickets.forEach(t => {
        const total = parseFloat(t.total);
        const isRef = total < 0;
        const items = window.historyDetailsMap[t.id] || [];
        
        // Color de fila
        let rowClass = 'bg-white';
        let badgeClass = 'bg-secondary';
        const metodo = (t.metodo_pago || '').toLowerCase();
        
        if (isRef) { rowClass = 'row-devolucion'; badgeClass = 'bg-danger'; }
        else if (metodo.includes('efectivo')) { rowClass = 'row-efectivo'; badgeClass = 'bg-success'; }
        else if (metodo.includes('transferencia')) { rowClass = 'row-transferencia'; badgeClass = 'bg-primary'; }
        else if (metodo.includes('tarjeta') || metodo.includes('gasto')) { rowClass = 'row-tarjeta'; badgeClass = 'bg-warning text-dark'; }
        else if (metodo.includes('mixto')) { rowClass = 'row-mixto'; badgeClass = 'bg-dark text-white'; }
        
        // Fila Master
        html += `<tr class="${rowClass} ticket-row" onclick="toggleDetail(${t.id})">
            <td class="text-center"><i class="fas fa-chevron-right text-muted icon-collapse-${t.id}"></i></td>
            <td class="fw-bold">#${t.id}</td>
            <td>${(t.fecha || '').split(' ')[1].substring(0,5)}</td>
            <td>${t.cliente_nombre || 'General'}</td>
            <td><small class="text-uppercase text-muted fw-bold" style="font-size:0.7rem;">${t.tipo_servicio}</small></td>
            <td class="fw-bold ${isRef ? 'text-danger' : 'text-dark'}">$${Math.abs(total).toFixed(2)}</td>
            <td><span class="badge ${badgeClass} badge-pago">${t.metodo_pago}</span></td>
            <td class="text-end" onclick="event.stopPropagation()">
                ${!isRef ? `<button class="btn btn-sm btn-danger py-0 shadow-sm me-1" onclick="refundTicketComplete(${t.id})" title="Devolver Todo"><i class="fas fa-undo"></i></button>` : ''}
                <button class="btn btn-sm btn-dark py-0 shadow-sm" onclick="window.open('ticket_view.php?id=${t.id}','T','width=380,height=600')"><i class="fas fa-print"></i></button>
            </td>
        </tr>
        
        <tr class="collapse bg-white" id="det-row-${t.id}">
            <td colspan="8" class="p-0 border-0">
                <div class="p-3 border-bottom shadow-inner bg-light">
                    <h6 class="small fw-bold text-muted mb-2 ps-2 border-start border-4 border-info">Detalle de Productos (${items.length})</h6>
                    <table class="table table-sm table-borderless mb-0 small">
                        <thead class="text-muted border-bottom"><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th class="text-end">Opc</th></tr></thead>
                        <tbody>`;
                        
        // Loop productos
        if(items.length > 0) {
            items.forEach(i => {
                const sub = parseFloat(i.cantidad) * parseFloat(i.precio);
                const isItemRef = parseFloat(i.cantidad) < 0;
                
                html += `<tr>
                    <td>${i.nombre}</td>
                    <td class="text-end fw-bold">${Math.abs(parseFloat(i.cantidad))}</td>
                    <td class="text-end">$${parseFloat(i.precio).toFixed(2)}</td>
                    <td class="text-end">$${Math.abs(sub).toFixed(2)}</td>
                    <td class="text-end">
                        ${(!isRef && !isItemRef) ? 
                            `<button class="btn btn-xs btn-outline-danger py-0 px-2" onclick="refundItemFromHistorial(${i.id}, '${(i.nombre || '').replace(/'/g, "")}')">Dev</button>` 
                            : '<span class="text-muted">-</span>'}
                    </td>
                </tr>`;
            });
        } else {
            html += `<tr><td colspan="5" class="text-center text-muted fst-italic">Detalles no disponibles</td></tr>`;
        }

        html += `</tbody>
                    </table>
                    ${t.mensajero ? `<div class="mt-2 small alert alert-info py-1 px-2 mb-0"><i class="fas fa-motorcycle me-1"></i> Mensajero: <strong>${t.mensajero}</strong></div>` : ''}
                </div>
            </td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    body.innerHTML = html;
};

// UI Toggle
window.toggleDetail = function(id) {
    const row = document.getElementById(`det-row-${id}`);
    const icon = document.querySelector(`.icon-collapse-${id}`);
    
    if(!row) return;

    if (row.classList.contains('show')) {
        row.classList.remove('show');
        if(icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-right'); }
    } else {
        // Cerrar otros
        document.querySelectorAll('.collapse.show').forEach(el => el.classList.remove('show'));
        document.querySelectorAll('.fa-chevron-down').forEach(el => { el.classList.remove('fa-chevron-down'); el.classList.add('fa-chevron-right'); });
        
        row.classList.add('show');
        if(icon) { icon.classList.remove('fa-chevron-right'); icon.classList.add('fa-chevron-down'); }
    }
};

// Devolución Ítem
window.refundItemFromHistorial = async function(detailId, prodName) {
    if(!confirm(`¿Devolver 1 unidad/lote de: ${prodName}?\nSe regresará al inventario.`)) return;
    try {
        const resp = await fetch('pos_refund.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id: detailId }) });
        const res = await resp.json();
        if(res.status === 'success') { alert('Producto devuelto correctamente'); showHistorialModal(); } else { alert('Error: ' + res.msg); }
    } catch(e) { alert('Error de conexión'); }
};

// Devolución Ticket
window.refundTicketComplete = async function(ticketId) {
    if(!confirm(`¿ESTÁ SEGURO DE DEVOLVER EL TICKET #${ticketId} COMPLETO?`)) return;
    
    const items = window.historyDetailsMap[ticketId];
    if(!items || items.length === 0) {
        // Si no hay detalles en memoria, intentamos devolución forzada por ID de cabecera (soportada por pos_refund.php v4.5)
        try {
            const resp = await fetch('pos_refund.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ ticket_id: ticketId }) });
            const res = await resp.json();
            if(res.status === 'success') { alert('Devolución completa exitosa.'); if(typeof Synth !== 'undefined') Synth.refund(); showHistorialModal(); }
            else { alert(res.msg); }
        } catch(e) { alert('Error crítico al procesar devolución'); }
        return;
    }
    
    // Proceso iterativo si hay detalles cargados
    let processed = 0, errors = 0;
    for (const item of items) {
        if(parseFloat(item.cantidad) > 0) {
            try {
                const resp = await fetch('pos_refund.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id: item.id }) });
                const res = await resp.json();
                if(res.status === 'success') processed++; else errors++;
            } catch(e) { errors++; }
        }
    }
    
    if(errors === 0) { alert(`Ticket #${ticketId} devuelto completamente.`); if(typeof Synth !== 'undefined') Synth.refund(); } 
    else { alert(`Proceso finalizado con ${errors} errores.`); }
    showHistorialModal();
};
</script>

