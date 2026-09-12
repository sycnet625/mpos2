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

<?php if (!empty($posLazySaleEditor)): ?>
<script>
(() => {
    let editorLoading;
    const loadEditor = () => editorLoading || (editorLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'assets/js/pos-sale-editor.js?v=<?= filemtime(__DIR__ . '/assets/js/pos-sale-editor.js') ?>';
        script.onload = resolve;
        script.onerror = () => { script.remove(); editorLoading = null; reject(new Error('No se pudo cargar el editor')); };
        document.head.appendChild(script);
    }));
    const openLazyEditor = async id => {
        try {
            await loadEditor();
            return window.openEditSale(id);
        } catch (error) {
            console.error(error);
            alert('No se pudo abrir el editor. Comprueba la conexión e inténtalo de nuevo.');
        }
    };
    window.openEditSale = openLazyEditor;
})();
</script>
<?php else: ?>
<script src="assets/js/pos-sale-editor.js?v=<?= filemtime(__DIR__ . '/assets/js/pos-sale-editor.js') ?>"></script>
<?php endif; ?>
