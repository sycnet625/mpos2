(function () {
    let modalElement = null;
    let pendingResolve = null;

    function getModal() {
        if (modalElement) return modalElement;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="posRefundPinModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content">
                        <form id="posRefundPinForm">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title"><i class="fas fa-user-shield me-2"></i>Autorizar devolución</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-muted mb-2" id="posRefundPinAction">Ingrese el PIN maestro de un administrador de esta sucursal.</p>
                                <label class="form-label" for="posRefundMasterPin">PIN maestro</label>
                                <input type="password" class="form-control form-control-lg text-center" id="posRefundMasterPin" name="pin" inputmode="numeric" pattern="[0-9]{4,10}" minlength="4" maxlength="10" autocomplete="off" required>
                                <div class="form-text">La devolución quedará registrada a nombre del administrador que autorice.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Autorizar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`;
        modalElement = wrapper.firstElementChild;
        document.body.appendChild(modalElement);

        const form = modalElement.querySelector('#posRefundPinForm');
        const pinInput = modalElement.querySelector('#posRefundMasterPin');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!form.reportValidity() || !pendingResolve) return;
            const pin = pinInput.value;
            const resolve = pendingResolve;
            pendingResolve = null;
            pinInput.value = '';
            bootstrap.Modal.getOrCreateInstance(modalElement).hide();
            resolve(pin);
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            pinInput.value = '';
            if (pendingResolve) {
                const resolve = pendingResolve;
                pendingResolve = null;
                resolve(null);
            }
        });
        modalElement.addEventListener('shown.bs.modal', function () {
            pinInput.focus();
        });
        return modalElement;
    }

    window.requestPosRefundPin = function (actionLabel) {
        if (!window.bootstrap?.Modal) return Promise.resolve(null);
        const modal = getModal();
        if (pendingResolve) {
            const previousResolve = pendingResolve;
            pendingResolve = null;
            previousResolve(null);
        }
        modal.querySelector('#posRefundPinAction').textContent = actionLabel
            ? `Ingrese el PIN maestro para ${actionLabel}.`
            : 'Ingrese el PIN maestro de un administrador de esta sucursal.';

        return new Promise(function (resolve) {
            pendingResolve = resolve;
            bootstrap.Modal.getOrCreateInstance(modal, { backdrop: 'static' }).show();
        });
    };
})();
