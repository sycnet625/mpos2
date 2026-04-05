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
                default_gestor_chat_id: state.integrationSettings.defaultGestorChatId || '',
                telegram_admin_chat_id: state.integrationSettings.telegramAdminChatId || '',
                webpush_enabled: String(state.integrationSettings.webpushEnabled || '0') === '1' ? 1 : 0,
                alert_low_balance_threshold: state.integrationSettings.alertLowBalanceThreshold || 1000,
                alert_fraud_min_leads: state.integrationSettings.alertFraudMinLeads || 6,
                alert_fraud_low_conversion_pct: state.integrationSettings.alertFraudLowConversionPct || 12
            });
        }
        var enableWebpushBtn = event.target.closest('[data-enable-webpush]');
        if (enableWebpushBtn) {
            return ns.enableWebPush().then(function () {
                ns.toast('Notificaciones web activadas en este navegador.', 'success');
            }).catch(function (err) {
                ns.toast(err.message || 'No se pudo activar la notificación web.', 'error');
            });
        }

        var openFlowBtn = event.target.closest('[data-open-flow]');
        if (openFlowBtn) return ns.loadLeadFinancialFlow(openFlowBtn.getAttribute('data-open-flow'));

        var newOwnerBtn = event.target.closest('[data-open-owner-new]');
        if (newOwnerBtn) {
            state.ownerDraft = { id: 0, owner_code: '', owner_name: '', phone: '', whatsapp_number: '', geo_zone: '', subscription_plan: 'basic', managed_service: 0, monthly_fee: '', subscription_due_at: '', advertising_budget: '', ads_active: 0, status: 'active' };
            return ns.openOwnerModal();
        }

        var editOwnerBtn = event.target.closest('[data-edit-owner]');
        if (editOwnerBtn) {
            var owner = (state.ownerAdminList || []).find(function (row) {
                return String(row.id) === String(editOwnerBtn.getAttribute('data-edit-owner'));
            });
            if (!owner) return ns.toast('No se pudo cargar el dueño.', 'error');
            state.ownerDraft = {
                id: owner.id || 0,
                owner_code: owner.owner_code || '',
                owner_name: owner.owner_name || '',
                phone: owner.phone || '',
                whatsapp_number: owner.whatsapp_number || '',
                geo_zone: owner.geo_zone || '',
                subscription_plan: owner.subscription_plan || 'basic',
                managed_service: Number(owner.managed_service || 0),
                monthly_fee: owner.monthly_fee || '',
                subscription_due_at: owner.subscription_due_at || '',
                advertising_budget: owner.advertising_budget || '',
                ads_active: Number(owner.ads_active || 0),
                status: owner.status || 'active'
            };
            return ns.openOwnerModal();
        }

        var saveOwnerBtn = event.target.closest('[data-save-owner]');
        if (saveOwnerBtn) return ns.saveOwner(state.ownerDraft);

        var newUserBtn = event.target.closest('[data-open-user-new]');
        if (newUserBtn) {
            state.userDraft = { id: 0, username: '', display_name: '', role: 'owner', owner_id: '', gestor_id: '', status: 'active', password: '' };
            return ns.openUserModal();
        }

        var editUserBtn = event.target.closest('[data-edit-user]');
        if (editUserBtn) {
            var user = (state.affiliateUsers || []).find(function (row) {
                return String(row.id) === String(editUserBtn.getAttribute('data-edit-user'));
            });
            if (!user) return ns.toast('No se pudo cargar el usuario.', 'error');
            state.userDraft = {
                id: user.id || 0,
                username: user.username || '',
                display_name: user.displayName || '',
                role: user.role || 'owner',
                owner_id: user.ownerId || '',
                gestor_id: user.gestorId || '',
                status: user.status || 'active',
                password: ''
            };
            return ns.openUserModal();
        }

        var saveUserBtn = event.target.closest('[data-save-user]');
        if (saveUserBtn) return ns.saveUser(state.userDraft);

        var newGestorBtn = event.target.closest('[data-open-gestor-new]');
        if (newGestorBtn) {
            state.gestorDraft = { id: '', name: '', phone: '', telegram_chat_id: '', masked_code: '', status: 'active' };
            return ns.openGestorModal();
        }

        var editGestorBtn = event.target.closest('[data-edit-gestor]');
        if (editGestorBtn) {
            var gestor = (state.gestorAdminList || []).find(function (row) {
                return String(row.id) === String(editGestorBtn.getAttribute('data-edit-gestor'));
            });
            if (!gestor) return ns.toast('No se pudo cargar el gestor.', 'error');
            state.gestorDraft = {
                id: gestor.id || '',
                name: gestor.name || '',
                phone: gestor.phone || '',
                telegram_chat_id: gestor.telegram_chat_id || '',
                masked_code: gestor.masked_code || '',
                status: gestor.status || 'active'
            };
            return ns.openGestorModal();
        }

        var saveGestorBtn = event.target.closest('[data-save-gestor]');
        if (saveGestorBtn) return ns.saveGestor(state.gestorDraft);

        var openTopupBtn = event.target.closest('[data-open-topup]');
        if (openTopupBtn) {
            state.topupDraft = { amount: '', payment_method: 'Transfermóvil', reference_code: '', note: '' };
            return ns.openTopupModal();
        }

        var saveTopupBtn = event.target.closest('[data-save-topup]');
        if (saveTopupBtn) return ns.requestWalletTopup(state.topupDraft);

        var openFinanceReconcileBtn = event.target.closest('[data-open-finance-reconcile]');
        if (openFinanceReconcileBtn) {
            state.financeDraft = { mode: 'reconcile', payment_channel: 'Transfermóvil', reference_code: '', amount: '', note: '', owner_id: '', charge_type: 'subscription', due_at: '', csv_text: '' };
            return ns.openFinanceModal();
        }

        var openBillingChargeBtn = event.target.closest('[data-open-billing-charge]');
        if (openBillingChargeBtn) {
            state.financeDraft = { mode: 'charge', payment_channel: 'Transfermóvil', reference_code: '', amount: '', note: '', owner_id: '', charge_type: 'subscription', due_at: '', csv_text: '' };
            return ns.openFinanceModal();
        }

        var openPaymentImportBtn = event.target.closest('[data-open-payment-import]');
        if (openPaymentImportBtn) {
            state.financeDraft = { mode: 'import', payment_channel: 'Transfermóvil', reference_code: '', amount: '', note: '', owner_id: '', charge_type: 'subscription', due_at: '', csv_text: '' };
            return ns.openFinanceModal();
        }

        var saveBillingChargeBtn = event.target.closest('[data-save-billing-charge]');
        if (saveBillingChargeBtn) return ns.createBillingCharge(state.financeDraft);

        var savePaymentReconcileBtn = event.target.closest('[data-save-payment-reconcile]');
        if (savePaymentReconcileBtn) return ns.reconcilePayment(state.financeDraft);

        var savePaymentImportBtn = event.target.closest('[data-save-payment-import]');
        if (savePaymentImportBtn) return ns.importPaymentExtract(state.financeDraft);

        var runAutoReconcileBtn = event.target.closest('[data-run-auto-reconcile]');
        if (runAutoReconcileBtn) return ns.autoReconcilePayments();

        var generateBillingBtn = event.target.closest('[data-generate-billing]');
        if (generateBillingBtn) return ns.generateBilling();

        var passwordOpenBtn = event.target.closest('[data-open-password-change]');
        if (passwordOpenBtn) return ns.openPasswordModal('change');

        var resetUserPasswordBtn = event.target.closest('[data-reset-user-password]');
        if (resetUserPasswordBtn) return ns.openPasswordModal('reset', resetUserPasswordBtn.getAttribute('data-reset-user-password'));

        var deleteUserBtn = event.target.closest('[data-delete-user]');
        if (deleteUserBtn) return ns.deleteUser(deleteUserBtn.getAttribute('data-delete-user'));

        var revokeSessionBtn = event.target.closest('[data-revoke-session]');
        if (revokeSessionBtn) return ns.revokeSession(revokeSessionBtn.getAttribute('data-revoke-session'));

        var changePasswordBtn = event.target.closest('[data-change-password]');
        if (changePasswordBtn) {
            if ((state.passwordDraft.new_password || '') !== (state.passwordDraft.confirm_password || '')) {
                return ns.toast('La confirmación de contraseña no coincide.', 'error');
            }
            return ns.changeOwnPassword({
                current_password: state.passwordDraft.current_password || '',
                new_password: state.passwordDraft.new_password || ''
            });
        }

        var resetPasswordConfirmBtn = event.target.closest('[data-user-password-reset]');
        if (resetPasswordConfirmBtn) {
            return ns.resetUserPassword(state.passwordDraft.target_user_id || 0, state.passwordDraft.reset_password || '');
        }

        var topupReviewBtn = event.target.closest('[data-topup-review]');
        if (topupReviewBtn) {
            return ns.reviewWalletTopup(topupReviewBtn.getAttribute('data-topup-review'), topupReviewBtn.getAttribute('data-decision'));
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
        var ownerField = event.target.closest('[data-owner-field]');
        if (ownerField) {
            state.ownerDraft[ownerField.getAttribute('data-owner-field')] = ownerField.type === 'checkbox'
                ? (ownerField.checked ? 1 : 0)
                : ownerField.value;
            return;
        }
        var gestorField = event.target.closest('[data-gestor-field]');
        if (gestorField) {
            state.gestorDraft[gestorField.getAttribute('data-gestor-field')] = gestorField.type === 'checkbox'
                ? (gestorField.checked ? 1 : 0)
                : gestorField.value;
            return;
        }
        var topupField = event.target.closest('[data-topup-field]');
        if (topupField) {
            state.topupDraft[topupField.getAttribute('data-topup-field')] = topupField.type === 'checkbox'
                ? (topupField.checked ? 1 : 0)
                : topupField.value;
            return;
        }
        var userField = event.target.closest('[data-user-field]');
        if (userField) {
            state.userDraft[userField.getAttribute('data-user-field')] = userField.type === 'checkbox'
                ? (userField.checked ? 1 : 0)
                : userField.value;
            return;
        }
        var passwordField = event.target.closest('[data-password-field]');
        if (passwordField) {
            state.passwordDraft[passwordField.getAttribute('data-password-field')] = passwordField.value;
            return;
        }
        var financeField = event.target.closest('[data-finance-field]');
        if (financeField) {
            state.financeDraft[financeField.getAttribute('data-finance-field')] = financeField.value;
            return;
        }
        var userFilterField = event.target.closest('[data-user-filter]');
        if (userFilterField) {
            state.userFilter[userFilterField.getAttribute('data-user-filter')] = userFilterField.value;
            return ns.renderAdmin();
        }
        if (event.target.id === 'gestorSearch') {
            state.gestorFilter.q = event.target.value;
            state.gestorFilter.page = 1;
            ns.renderGestor();
            return;
        }
        if (event.target.id === 'userSearch') {
            state.userFilter.q = event.target.value;
            ns.renderAdmin();
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
        ns.pollNotifications();
    });

    window.addEventListener('offline', ns.updateNetBadge);

    document.addEventListener('change', function (event) {
        if (event.target.id !== 'racPaymentExtractFile' || !event.target.files || !event.target.files[0]) {
            return;
        }
        var reader = new FileReader();
        reader.onload = function (loadEvent) {
            state.financeDraft.csv_text = String((loadEvent.target && loadEvent.target.result) || '');
            ns.openFinanceModal();
        };
        reader.readAsText(event.target.files[0], 'UTF-8');
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/affiliate_network_sw.js').then(function (registration) {
                if (registration.waiting) {
                    state.swUpdateAvailable = true;
                    ns.updateSyncBadge();
                    if (ns.showSwUpdateNotice) ns.showSwUpdateNotice();
                }
                registration.addEventListener('updatefound', function () {
                    var worker = registration.installing;
                    if (!worker) return;
                    worker.addEventListener('statechange', function () {
                        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                            state.swUpdateAvailable = true;
                            ns.updateSyncBadge();
                            if (ns.showSwUpdateNotice) ns.showSwUpdateNotice();
                        }
                    });
                });
            }).catch(function () {});
            ns.updateInstallNotice();
        });
    }

    ns.loadQueue();
    setTimeout(function () {
        ns.$('splashScreen').classList.add('hidden');
        if (state.role) {
            openRole(state.role);
        } else {
            ns.$('homeScreen').classList.remove('hidden');
        }
    }, 1800);
    ns.loadBootstrap().then(ns.flushQueue);
    window.setInterval(function () {
        if (navigator.onLine) ns.pollNotifications();
    }, 60000);
})(window.RAC);
