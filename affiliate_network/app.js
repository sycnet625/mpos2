(function (ns) {
    var state = ns.state;

    function openRole(role) {
        state.role = role;
        ns.$('homeScreen').classList.add('hidden');
        ns.$('mainApp').classList.remove('hidden');
        ns.render();
    }

    function goHome() {
        ns.$('mainApp').classList.add('hidden');
        ns.$('homeScreen').classList.remove('hidden');
        ns.$('affBackBtn').classList.add('hidden');
    }

    document.addEventListener('click', function (event) {
        var roleBtn = event.target.closest('[data-open-role]');
        if (roleBtn) return openRole(roleBtn.getAttribute('data-open-role'));

        var homeBtn = event.target.closest('[data-go-home]');
        if (homeBtn) return goHome();

        if (event.target.closest('#pwaInstallBtn')) return ns.installPwa();

        var switchBtn = event.target.closest('#roleSwitcher button');
        if (switchBtn) {
            state.role = switchBtn.dataset.role;
            return ns.render();
        }

        var tabBtn = event.target.closest('[data-tab-role]');
        if (tabBtn) {
            var role = tabBtn.dataset.tabRole;
            var id = tabBtn.dataset.tabId;
            if (role === 'owner') state.ownerTab = id;
            if (role === 'gestor') state.gestorTab = id;
            if (role === 'admin') state.adminTab = id;
            return ns.render();
        }

        var catBtn = event.target.closest('[data-gestor-category]');
        if (catBtn) {
            state.gestorFilter.category = catBtn.getAttribute('data-gestor-category');
            state.gestorFilter.page = 1;
            return ns.renderGestor();
        }

        var sortBtn = event.target.closest('[data-gestor-sort]');
        if (sortBtn) {
            state.gestorFilter.sort = sortBtn.getAttribute('data-gestor-sort');
            state.gestorFilter.page = 1;
            return ns.renderGestor();
        }

        var pageBtn = event.target.closest('[data-page-kind]');
        if (pageBtn && !pageBtn.disabled) {
            var nextPage = Number(pageBtn.getAttribute('data-page') || 1);
            var kind = pageBtn.getAttribute('data-page-kind');
            if (kind === 'ownerLead') state.ownerLeadPage = nextPage;
            if (kind === 'gestorLinks') state.gestorLinksPage = nextPage;
            return ns.render();
        }

        var productBtn = event.target.closest('[data-open-product]');
        if (productBtn) {
            ns.resetProductDraft();
            return ns.openProductModal();
        }

        var editProductBtn = event.target.closest('[data-edit-product]');
        if (editProductBtn) return ns.editProduct(editProductBtn.getAttribute('data-edit-product'));

        var saveBtn = event.target.closest('[data-save-product]');
        if (saveBtn) return ns.saveNewProduct();

        var openIntegrationsBtn = event.target.closest('[data-open-integrations]');
        if (openIntegrationsBtn) return ns.openIntegrationModal();

        var saveIntegrationsBtn = event.target.closest('[data-save-integrations]');
        if (saveIntegrationsBtn) {
            return ns.saveIntegrationSettings({
                telegram_bot_token: state.integrationSettings.telegramBotToken || '',
                default_gestor_id: state.integrationSettings.defaultGestorId || 'G001',
                default_gestor_chat_id: state.integrationSettings.defaultGestorChatId || ''
            });
        }

        var removeImageBtn = event.target.closest('[data-remove-product-image]');
        if (removeImageBtn) {
            state.ownerNewProduct.removeImage = true;
            state.ownerNewProduct.imageData = '';
            state.ownerNewProduct.imagePreview = '';
            state.ownerNewProduct.hasImage = false;
            return ns.openProductModal();
        }

        var toggleProductBtn = event.target.closest('[data-toggle-product]');
        if (toggleProductBtn) return ns.toggleProductActive(toggleProductBtn.getAttribute('data-toggle-product'), Number(toggleProductBtn.getAttribute('data-active') || 0));

        var leadBtn = event.target.closest('[data-lead-status]');
        if (leadBtn) return ns.updateLeadStatus(leadBtn.dataset.leadStatus, leadBtn.dataset.status);

        var closeBtn = event.target.closest('[data-close-modal]');
        if (closeBtn) return ns.closeModal(closeBtn.getAttribute('data-close-modal'));

        var linkBtn = event.target.closest('[data-generate-link]');
        if (linkBtn) return ns.generateLink(linkBtn.getAttribute('data-generate-link'));

        var copyBtn = event.target.closest('[data-copy-link]');
        if (copyBtn) {
            var link = copyBtn.getAttribute('data-copy-link');
            if (navigator.clipboard) navigator.clipboard.writeText(link);
            return ns.toast('Link copiado', 'success');
        }
    });

    document.addEventListener('input', function (event) {
        var productField = event.target.closest('[data-product-field]');
        if (productField) {
            state.ownerNewProduct[productField.getAttribute('data-product-field')] = productField.value;
            return;
        }
        var integrationField = event.target.closest('[data-integration-field]');
        if (integrationField) {
            state.integrationSettings[integrationField.getAttribute('data-integration-field')] = integrationField.value;
            return;
        }
        if (event.target.id === 'gestorSearch') {
            state.gestorFilter.q = event.target.value;
            state.gestorFilter.page = 1;
            ns.renderGestor();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.id === 'racProductImage' && event.target.files && event.target.files[0]) {
            ns.fileToWebpDataUrl(event.target.files[0]).then(function (dataUrl) {
                state.ownerNewProduct.imageData = dataUrl;
                state.ownerNewProduct.imagePreview = dataUrl;
                state.ownerNewProduct.hasImage = true;
                state.ownerNewProduct.removeImage = false;
                ns.openProductModal();
            }).catch(function () {
                ns.toast('No fue posible procesar la imagen.', 'error');
            });
        }
    });

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        state.installPrompt = event;
        ns.updateInstallNotice();
    });

    window.addEventListener('appinstalled', function () {
        state.installPrompt = null;
        ns.updateInstallNotice();
    });

    window.addEventListener('online', function () {
        ns.updateNetBadge();
        ns.flushQueue();
    });

    window.addEventListener('offline', ns.updateNetBadge);

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/affiliate_network_sw.js').catch(function () {});
            ns.updateInstallNotice();
        });
    }

    ns.loadQueue();
    setTimeout(function () {
        ns.$('splashScreen').classList.add('hidden');
        ns.$('homeScreen').classList.remove('hidden');
    }, 1800);
    ns.loadBootstrap().then(ns.flushQueue);
})(window.RAC);
