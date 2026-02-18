<?php
// ARCHIVO: /var/www/palweb/api/tools_unit_converter.php
// CALCULADORA DE COSTOS UNITARIOS (CONVERTIDOR)
?>

<div class="modal fade" id="unitConverterModal" tabindex="-1" aria-hidden="true" style="z-index: 10000;">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-calculator me-2"></i> Costo por Gramo/ML</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                
                <form id="calcForm">
                    <label class="small fw-bold text-muted mb-1">¿Cuánto compraste?</label>
                    <div class="input-group mb-2">
                        <input type="number" class="form-control fw-bold" id="calcQty" placeholder="Ej: 5" step="0.01">
                        <select class="form-select bg-white" id="calcUnit" style="max-width: 90px;">
                            <option value="453.592">LB</option>
                            <option value="1000">KG</option>
                            <option value="1000">L</option>
                            <option value="28.3495">OZ</option>
                            <option value="3785.41">GAL</option>
                            <option value="1">UNID</option>
                        </select>
                    </div>

                    <label class="small fw-bold text-muted mb-1">¿Cuánto pagaste en total?</label>
                    <div class="input-group mb-3">
                        <span class="input-group-text bg-white">$</span>
                        <input type="number" class="form-control fw-bold text-success" id="calcPrice" placeholder="Ej: 580" step="0.01">
                    </div>

                    <hr class="my-2">

                    <div class="text-center">
                        <small class="text-muted text-uppercase">Costo por Gramo / ML</small>
                        <div class="display-6 fw-bold text-primary mb-0" id="resCostGram">0.00</div>
                        <small class="text-muted" id="resTotalGrams">Total: 0 gr</small>
                    </div>
                </form>

            </div>
            <div class="modal-footer bg-light p-1 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearCalc()">Borrar</button>
                <button type="button" class="btn btn-primary btn-sm fw-bold" onclick="copyResult()"><i class="fas fa-copy me-1"></i> Copiar</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Lógica de Conversión en Tiempo Real
    const inputs = ['calcQty', 'calcUnit', 'calcPrice'];
    inputs.forEach(id => {
        document.getElementById(id).addEventListener('input', calculateUnitCost);
        document.getElementById(id).addEventListener('change', calculateUnitCost);
    });

    function calculateUnitCost() {
        const qty = parseFloat(document.getElementById('calcQty').value) || 0;
        const price = parseFloat(document.getElementById('calcPrice').value) || 0;
        const factor = parseFloat(document.getElementById('calcUnit').value) || 1;

        if (qty > 0 && price > 0) {
            // 1. Calcular Peso Total en Base (Gramos o ML)
            const totalBaseUnits = qty * factor;
            
            // 2. Calcular Precio por Unidad Base
            const costPerBase = price / totalBaseUnits;

            // Mostrar
            // Usamos muchas decimales porque el costo por gramo suele ser pequeño (0.0034)
            document.getElementById('resCostGram').innerText = costPerBase.toFixed(5); 
            document.getElementById('resTotalGrams').innerText = `Total Base: ${totalBaseUnits.toFixed(2)}`;
        } else {
            document.getElementById('resCostGram').innerText = "0.00";
            document.getElementById('resTotalGrams').innerText = "Total: 0";
        }
    }

    function clearCalc() {
        document.getElementById('calcQty').value = '';
        document.getElementById('calcPrice').value = '';
        calculateUnitCost();
        document.getElementById('calcQty').focus();
    }

    function copyResult() {
        const val = document.getElementById('resCostGram').innerText;
        navigator.clipboard.writeText(val).then(() => {
            // Feedback visual rápido
            const btn = document.querySelector('#unitConverterModal .btn-primary');
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copiado';
            btn.classList.replace('btn-primary', 'btn-success');
            setTimeout(() => {
                btn.innerHTML = original;
                btn.classList.replace('btn-success', 'btn-primary');
            }, 1500);
        });
    }
</script>

