(function () {
    function ensureToastRoot() {
        var existing = document.getElementById('inventorySuiteToastRoot');
        if (existing) return existing;
        var root = document.createElement('div');
        root.id = 'inventorySuiteToastRoot';
        root.className = 'toast-container position-fixed top-0 end-0 p-3';
        root.style.zIndex = '2000';
        document.body.appendChild(root);
        return root;
    }

    function buildToast(message, type) {
        var palette = {
            success: 'text-bg-success',
            error: 'text-bg-danger',
            warning: 'text-bg-warning',
            info: 'text-bg-dark'
        };
        var wrapper = document.createElement('div');
        wrapper.className = 'toast align-items-center border-0 ' + (palette[type] || palette.info);
        wrapper.setAttribute('role', 'status');
        wrapper.setAttribute('aria-live', 'polite');
        wrapper.setAttribute('aria-atomic', 'true');
        wrapper.innerHTML = '<div class="d-flex">'
            + '<div class="toast-body">' + String(message || '') + '</div>'
            + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>'
            + '</div>';
        return wrapper;
    }

    function createConfirmModal(message, title) {
        var modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = '<div class="modal-dialog modal-dialog-centered">'
            + '<div class="modal-content border-0 shadow-lg">'
            + '<div class="modal-header">'
            + '<h5 class="modal-title">' + (title || 'Confirmar acción') + '</h5>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
            + '</div>'
            + '<div class="modal-body"><p class="mb-0" style="white-space:pre-line">' + String(message || '') + '</p></div>'
            + '<div class="modal-footer">'
            + '<button type="button" class="btn btn-outline-secondary" data-action="cancel">Cancelar</button>'
            + '<button type="button" class="btn btn-success" data-action="confirm">Confirmar</button>'
            + '</div>'
            + '</div>'
            + '</div>';
        document.body.appendChild(modal);
        return modal;
    }

    window.InventorySuite = {
        toast: function (message, type) {
            if (window.bootstrap && bootstrap.Toast) {
                var root = ensureToastRoot();
                var toastEl = buildToast(message, type);
                root.appendChild(toastEl);
                var toast = new bootstrap.Toast(toastEl, { delay: 3200 });
                toastEl.addEventListener('hidden.bs.toast', function () {
                    toastEl.remove();
                });
                toast.show();
                return;
            }
            window.alert(String(message || ''));
        },
        success: function (message) {
            this.toast(message, 'success');
        },
        error: function (message) {
            this.toast(message, 'error');
        },
        warning: function (message) {
            this.toast(message, 'warning');
        },
        confirm: function (message, title) {
            if (!(window.bootstrap && bootstrap.Modal)) {
                return Promise.resolve(window.confirm(String(message || '')));
            }
            return new Promise(function (resolve) {
                var modalEl = createConfirmModal(message, title);
                var instance = new bootstrap.Modal(modalEl, { backdrop: 'static' });
                var settled = false;
                function finish(value) {
                    if (settled) return;
                    settled = true;
                    resolve(value);
                    instance.hide();
                }
                modalEl.querySelector('[data-action="confirm"]').addEventListener('click', function () { finish(true); });
                modalEl.querySelector('[data-action="cancel"]').addEventListener('click', function () { finish(false); });
                modalEl.addEventListener('hidden.bs.modal', function () {
                    if (!settled) resolve(false);
                    modalEl.remove();
                }, { once: true });
                instance.show();
            });
        },
        fetchJSON: async function (url, options) {
            var response = await fetch(url, options || {});
            var text = await response.text();
            var data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (error) {
                throw new Error('Respuesta inválida del servidor.');
            }
            return data;
        },
        postJSON: function (url, payload, extraOptions) {
            var options = Object.assign({
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {})
            }, extraOptions || {});
            return this.fetchJSON(url, options);
        },
        reloadSoon: function (ms) {
            window.setTimeout(function () { window.location.reload(); }, ms || 700);
        },
        openModal: function (id) {
            var el = document.getElementById(id);
            if (el && window.bootstrap && bootstrap.Modal) {
                new bootstrap.Modal(el).show();
            }
        }
    };
})();
