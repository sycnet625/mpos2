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
                                <input type="number" class="form-control form-control-lg text-center fw-bold border-danger shadow-sm" id="final-cash" placeholder="0.00" required min="0" step="0.01" style="font-size: 2rem;">
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

