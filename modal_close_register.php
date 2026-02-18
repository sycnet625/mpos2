<div class="modal fade" id="closeRegisterModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Cierre de Turno Administrativo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="text-center mb-4">
                    <span class="badge bg-primary px-3 py-2 fs-6 mb-2"><i class="fas fa-calendar-alt me-1"></i> Fecha Contable: <span id="closeDateDisplay">...</span></span>
                </div>
                <div class="row g-4">
                    <div class="col-md-6 border-end">
                        <h6 class="fw-bold text-muted mb-3 border-bottom pb-2">Ventas por Método</h6>
                        <div class="d-flex justify-content-between mb-2"><span>Efectivo</span><span class="fw-bold text-success" id="sysCash">$0.00</span></div>
                        <div class="d-flex justify-content-between mb-2"><span>Transferencia</span><span class="fw-bold text-primary" id="sysTransfer">$0.00</span></div>
                        <div class="d-flex justify-content-between mb-3"><span>Tarjeta/Gasto</span><span class="fw-bold text-warning" id="sysCard">$0.00</span></div>
                        <div class="d-flex justify-content-between mb-2 pt-2 border-top bg-white p-2 rounded"><span class="fw-bold">TOTAL VENTAS</span><span class="fw-bold text-dark fs-5" id="summaryTotalSales">$0.00</span></div>
                        <h6 class="fw-bold text-muted mb-2 border-bottom pb-2 mt-4">Arqueo Físico Esperado</h6>
                        <div class="d-flex justify-content-between mb-2"><span>Fondo Inicial</span><span class="fw-bold" id="summaryInitialFund">$0.00</span></div>
                        <div class="d-flex justify-content-between mb-2 p-2 bg-primary bg-opacity-10 rounded border border-primary"><span class="fw-bold text-primary">Total Efectivo en Caja</span><span class="fw-bold text-primary fs-5" id="summaryTheoreticalTotal">$0.00</span></div>
                    </div>
                    <div class="col-md-6">
                        <form id="close-register-form">
                            <div class="mb-4">
                                <label for="final-cash" class="form-label fw-bold text-uppercase">Dinero Real Contado:</label>
                                <input type="number" class="form-control form-control-lg text-center fw-bold border-danger shadow-sm" id="final-cash" placeholder="0.00" required min="0" step="0.01" style="font-size: 2rem;" oninput="updateCloseDifference()">
                                <div class="form-text text-end fw-bold mt-2 fs-6" id="diffDisplay">Diferencia: $0.00</div>
                            </div>
                            <div class="mb-4">
                                <label for="close-note" class="form-label fw-bold">Observaciones:</label>
                                <textarea class="form-control" id="close-note" rows="3" placeholder="Comentarios sobre el cuadre..."></textarea>
                            </div>
                            <div class="d-grid mt-auto"><button type="button" onclick="validateAndCloseCash()" class="btn btn-danger btn-lg fw-bold shadow-sm py-3"><i class="fas fa-lock me-2"></i> CERRAR TURNO</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.showOpenCashModal = function() {
    const body = document.getElementById('cashModalBody');
    const today = new Date().toISOString().split('T')[0];
    
    body.innerHTML = `
        <div class="text-center mb-3">
            <i class="fas fa-user-circle fa-3x text-success mb-2"></i>
            <h5 class="fw-bold">¡Hola, ${currentCashier}!</h5>
            <p class="text-muted small">Inicia tu turno de trabajo</p>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold">Fecha Contable</label>
            <input type="date" id="startOpenDate" class="form-control" value="${today}">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold">Monto Inicial en Caja</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" id="startAmount" class="form-control fw-bold" placeholder="0.00" value="0">
            </div>
        </div>
        <button onclick="doOpen()" class="btn btn-success w-100 py-2 fw-bold">
            <i class="fas fa-lock-open me-2"></i> ABRIR TURNO
        </button>
    `;
    new bootstrap.Modal(document.getElementById('cashModal')).show();
};

window.doOpen = async function() {
    const m = document.getElementById('startAmount').value;
    const f = document.getElementById('startOpenDate').value;
    
    if(!f) return alert("Seleccione la fecha contable");

    try {
        const resp = await fetch('pos_cash.php?action=open', {
            method: 'POST', 
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                monto: m, 
                fecha: f,
                cajero: currentCashier
            })
        });
        const res = await resp.json();
        
        if(res.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('cashModal')).hide();
            checkCashStatusSilent(); 
            if(typeof Synth !== 'undefined') Synth.openCash();
            showToast("Turno abierto correctamente");
        } else {
            alert("Error: " + res.msg);
        }
    } catch(e){ 
        console.error(e);
        alert('Error de conexión al abrir caja'); 
    }
};
window.checkCashRegister = async function() {
    if(!cashOpen) { if(typeof showOpenCashModal === 'function') showOpenCashModal(); return; }
    const modal = new bootstrap.Modal(document.getElementById('closeRegisterModal'));
    try {
        const r=await fetch('pos_cash.php?action=status'); const d=await r.json();
        if(d.status==='open') {
            document.getElementById('closeDateDisplay').innerText = d.data.fecha_contable;
            let tot=0, c=0, tr=0, tj=0;
            
            // RECTIFICACIÓN: Usar el desglose real enviado por el servidor
            if(d.ventas) {
                d.ventas.forEach(v => {
                    const m = parseFloat(v.total) || 0;
                    const mp = (v.metodo_pago || '').toLowerCase();
                    
                    if (mp.includes('efectivo')) c += m;
                    else if (mp.includes('transferencia')) tr += m;
                    else if (mp.includes('tarjeta') || mp.includes('gasto')) tj += m;
                    else c += m; // Fallback
                    
                    tot += m;
                });
            }
            
            const fondo = parseFloat(d.data.monto_inicial||0); 
            theoreticalTotal = fondo + c;
            
            document.getElementById('sysCash').innerText = '$'+c.toFixed(2); 
            document.getElementById('sysTransfer').innerText = '$'+tr.toFixed(2); 
            document.getElementById('sysCard').innerText = '$'+tj.toFixed(2); 
            document.getElementById('summaryTotalSales').innerText = '$'+tot.toFixed(2); 
            document.getElementById('summaryInitialFund').innerText = '$'+fondo.toFixed(2); 
            document.getElementById('summaryTheoreticalTotal').innerText = '$'+theoreticalTotal.toFixed(2);
            
            document.getElementById('final-cash').value = ''; 
            document.getElementById('diffDisplay').innerText = 'Diferencia: $0.00';
            modal.show();
        }
    } catch(e){ console.error("Error al verificar caja:", e); }
};
window.updateCloseDifference = function() { const r=parseFloat(document.getElementById('final-cash').value)||0; const d=r-theoreticalTotal; const el=document.getElementById('diffDisplay'); el.innerText='Diferencia: $'+d.toFixed(2); el.className='form-text text-end fw-bold '+(d<0?'text-danger':(d>0?'text-success':'text-muted')); };
window.validateAndCloseCash = async function() { const r=parseFloat(document.getElementById('final-cash').value); const n=document.getElementById('close-note').value; if(isNaN(r)) return alert("Ingrese efectivo"); if(!confirm('Cerrar?')) return; try{ const resp=await fetch('pos_cash.php?action=close',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:cashId,real:r,nota:n})}); const res=await resp.json(); if(res.status==='success'){ bootstrap.Modal.getInstance(document.getElementById('closeRegisterModal')).hide(); Synth.closeCash(); checkCashStatusSilent(); showToast('Cerrado','success'); setTimeout(()=>location.reload(),1500); } else alert(res.msg); } catch(e){ alert("Error"); } };
</script>

