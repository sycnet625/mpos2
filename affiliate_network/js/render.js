window.RAC = window.RAC || {};
(function (ns) {
    var state = ns.state;

    ns.productVisual = function (product) {
        if (product && product.hasImage && (product.imageThumbUrl || product.imageWebpUrl || product.imageUrl)) {
            var primary = product.imageThumbUrl || product.imageUrl || product.imageWebpUrl;
            if (product.imageWebpUrl || product.imageThumbUrl) {
                return '<div class="product-media"><picture><source srcset="' + ns.esc(product.imageThumbUrl || product.imageWebpUrl) + '" type="image/webp"><img loading="lazy" src="' + ns.esc(primary) + '" alt="' + ns.esc(product.name || 'Producto RAC') + '"></picture></div>';
            }
            return '<div class="product-media"><img loading="lazy" src="' + ns.esc(primary) + '" alt="' + ns.esc(product.name || 'Producto RAC') + '"></div>';
        }
        return '<div class="emoji">' + ns.esc(product.image) + '</div>';
    };

    ns.renderRoleSwitcher = function () {
        document.querySelectorAll('#roleSwitcher button').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.role === state.role);
        });
        document.querySelectorAll('.panel').forEach(function (panel) {
            panel.classList.remove('active');
        });
        var active = ns.$('panel-' + state.role);
        if (active) active.classList.add('active');
    };

    ns.field = function (key, label, value, type) {
        return '<div class="field"><label>' + label + '</label><input class="input" type="' + (type || 'text') + '" value="' + ns.esc(value) + '" data-product-field="' + key + '"></div>';
    };

    ns.resetProductDraft = function () {
        state.ownerNewProduct = { id: '', name: '', category: 'Tecnologia', price: '', stock: '', commission: '', brand: 'Nuevo', couponLabel: '', isFeatured: false, sponsorRank: 0, description: '', imageData: '', imagePreview: '', hasImage: false, removeImage: false };
    };

    ns.editProduct = function (productId) {
        var product = state.products.find(function (item) { return item.id === productId; });
        if (!product) return;
        state.ownerNewProduct = {
            id: product.id,
            name: product.name || '',
            category: product.category || 'Tecnologia',
            price: product.price || '',
            stock: product.stock || '',
            commission: product.commission || '',
            brand: product.brand || 'Nuevo',
            couponLabel: product.couponLabel || '',
            isFeatured: Number(product.isFeatured || 0),
            sponsorRank: product.sponsorRank || 0,
            description: product.description || '',
            imageData: '',
            imagePreview: product.imageUrl || product.imageWebpUrl || product.imageThumbUrl || '',
            hasImage: !!product.hasImage,
            removeImage: false
        };
        ns.openProductModal();
    };

    ns.closeModal = function (id) {
        var wrap = ns.$(id);
        if (!wrap) return;
        wrap.classList.remove('active');
        wrap.innerHTML = '';
    };

    ns.integrationField = function (key, label, value, type) {
        return '<div class="field"><label>' + label + '</label><input class="input" type="' + (type || 'text') + '" value="' + ns.esc(value) + '" data-integration-field="' + key + '"></div>';
    };

    ns.openIntegrationModal = function () {
        var s = state.integrationSettings || {};
        ns.$('integrationModalWrap').innerHTML = '<div class="modal active"><header><h3>🔔 Integraciones RAC</h3><button class="close" data-close-modal="integrationModalWrap">×</button></header>'
            + ns.integrationField('telegramBotToken', 'Telegram Bot Token', s.telegramBotToken || '')
            + '<div class="field"><label>Gestor por defecto</label><input class="input" type="text" value="' + ns.esc((s.defaultGestorId || '') + ' · ' + (s.defaultGestorName || '')) + '" disabled></div>'
            + ns.integrationField('defaultGestorChatId', 'Telegram Chat ID del gestor', s.defaultGestorChatId || '')
            + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-integrations>💾 Guardar integraciones</button></div></div>';
        ns.$('integrationModalWrap').classList.add('active');
    };

    ns.openOwnerModal = function () {
        var d = state.ownerDraft;
        ns.$('entityModalWrap').innerHTML = '<div class="modal active"><header><h3>🏪 Dueño RAC</h3><button class="close" data-close-modal="entityModalWrap">×</button></header>'
            + '<div class="field"><label>Código</label><input class="input" data-owner-field="owner_code" value="' + ns.esc(d.owner_code) + '"></div>'
            + '<div class="field"><label>Nombre</label><input class="input" data-owner-field="owner_name" value="' + ns.esc(d.owner_name) + '"></div>'
            + '<div class="field"><label>Teléfono</label><input class="input" data-owner-field="phone" value="' + ns.esc(d.phone) + '"></div>'
            + '<div class="field"><label>WhatsApp</label><input class="input" data-owner-field="whatsapp_number" value="' + ns.esc(d.whatsapp_number) + '"></div>'
            + '<div class="field"><label>Zona</label><input class="input" data-owner-field="geo_zone" value="' + ns.esc(d.geo_zone) + '"></div>'
            + '<div class="field"><label>Plan</label><select class="select" data-owner-field="subscription_plan"><option value="basic"' + (d.subscription_plan === 'basic' ? ' selected' : '') + '>basic</option><option value="managed"' + (d.subscription_plan === 'managed' ? ' selected' : '') + '>managed</option><option value="pro"' + (d.subscription_plan === 'pro' ? ' selected' : '') + '>pro</option></select></div>'
            + '<div class="field"><label>Gestión asistida</label><select class="select" data-owner-field="managed_service"><option value="0"' + (Number(d.managed_service) === 0 ? ' selected' : '') + '>No</option><option value="1"' + (Number(d.managed_service) === 1 ? ' selected' : '') + '>Sí</option></select></div>'
            + '<div class="field"><label>Cuota mensual</label><input class="input" type="number" data-owner-field="monthly_fee" value="' + ns.esc(d.monthly_fee) + '"></div>'
            + '<div class="field"><label>Vence</label><input class="input" data-owner-field="subscription_due_at" value="' + ns.esc(d.subscription_due_at) + '"></div>'
            + '<div class="field"><label>Budget publicidad</label><input class="input" type="number" data-owner-field="advertising_budget" value="' + ns.esc(d.advertising_budget) + '"></div>'
            + '<div class="field"><label>Publicidad activa</label><select class="select" data-owner-field="ads_active"><option value="0"' + (Number(d.ads_active) === 0 ? ' selected' : '') + '>No</option><option value="1"' + (Number(d.ads_active) === 1 ? ' selected' : '') + '>Sí</option></select></div>'
            + '<div class="field"><label>Estado</label><select class="select" data-owner-field="status"><option value="active"' + (d.status === 'active' ? ' selected' : '') + '>active</option><option value="suspended"' + (d.status === 'suspended' ? ' selected' : '') + '>suspended</option></select></div>'
            + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-owner>💾 Guardar dueño</button></div></div>';
        ns.$('entityModalWrap').classList.add('active');
    };

    ns.openGestorModal = function () {
        var d = state.gestorDraft;
        ns.$('entityModalWrap').innerHTML = '<div class="modal active"><header><h3>🪔 Gestor RAC</h3><button class="close" data-close-modal="entityModalWrap">×</button></header>'
            + '<div class="field"><label>ID</label><input class="input" data-gestor-field="id" value="' + ns.esc(d.id) + '"></div>'
            + '<div class="field"><label>Nombre</label><input class="input" data-gestor-field="name" value="' + ns.esc(d.name) + '"></div>'
            + '<div class="field"><label>Teléfono</label><input class="input" data-gestor-field="phone" value="' + ns.esc(d.phone) + '"></div>'
            + '<div class="field"><label>Telegram chat id</label><input class="input" data-gestor-field="telegram_chat_id" value="' + ns.esc(d.telegram_chat_id) + '"></div>'
            + '<div class="field"><label>Código enmascarado</label><input class="input" data-gestor-field="masked_code" value="' + ns.esc(d.masked_code) + '"></div>'
            + '<div class="field"><label>Estado</label><select class="select" data-gestor-field="status"><option value="active"' + (d.status === 'active' ? ' selected' : '') + '>active</option><option value="suspended"' + (d.status === 'suspended' ? ' selected' : '') + '>suspended</option></select></div>'
            + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-gestor>💾 Guardar gestor</button></div></div>';
        ns.$('entityModalWrap').classList.add('active');
    };

    ns.openUserModal = function () {
        var d = state.userDraft;
        ns.$('entityModalWrap').innerHTML = '<div class="modal active"><header><h3>👤 Usuario RAC</h3><button class="close" data-close-modal="entityModalWrap">×</button></header>'
            + '<div class="field"><label>Usuario</label><input class="input" data-user-field="username" value="' + ns.esc(d.username) + '"></div>'
            + '<div class="field"><label>Nombre visible</label><input class="input" data-user-field="display_name" value="' + ns.esc(d.display_name) + '"></div>'
            + '<div class="field"><label>Rol</label><select class="select" data-user-field="role"><option value="admin"' + (d.role === 'admin' ? ' selected' : '') + '>admin</option><option value="owner"' + (d.role === 'owner' ? ' selected' : '') + '>owner</option><option value="gestor"' + (d.role === 'gestor' ? ' selected' : '') + '>gestor</option></select></div>'
            + '<div class="field"><label>Dueño asociado</label><select class="select" data-user-field="owner_id"><option value="">-- ninguno --</option>' + (state.ownerAdminList || []).map(function (owner) {
                return '<option value="' + ns.esc(owner.id) + '"' + (String(d.owner_id || '') === String(owner.id) ? ' selected' : '') + '>' + ns.esc((owner.ownerCode || '') + ' · ' + (owner.ownerName || '')) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="field"><label>Gestor asociado</label><select class="select" data-user-field="gestor_id"><option value="">-- ninguno --</option>' + (state.gestorAdminList || []).map(function (gestor) {
                return '<option value="' + ns.esc(gestor.id) + '"' + (String(d.gestor_id || '') === String(gestor.id) ? ' selected' : '') + '>' + ns.esc((gestor.id || '') + ' · ' + (gestor.name || '')) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="field"><label>Estado</label><select class="select" data-user-field="status"><option value="active"' + (d.status === 'active' ? ' selected' : '') + '>active</option><option value="suspended"' + (d.status === 'suspended' ? ' selected' : '') + '>suspended</option></select></div>'
            + '<div class="field"><label>' + (d.id ? 'Nueva contraseña opcional' : 'Contraseña inicial') + '</label><input class="input" type="password" data-user-field="password" value=""></div>'
            + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-user>💾 Guardar usuario</button></div></div>';
        ns.$('entityModalWrap').classList.add('active');
    };

    ns.openPasswordModal = function (mode, targetUserId) {
        if (mode === 'reset') {
            state.passwordDraft = { current_password: '', new_password: '', confirm_password: '', target_user_id: targetUserId || 0, reset_password: '' };
            ns.$('authModalWrap').innerHTML = '<div class="modal active"><header><h3>🔐 Resetear contraseña</h3><button class="close" data-close-modal="authModalWrap">×</button></header>'
                + '<div class="field"><label>Nueva contraseña</label><input class="input" type="password" data-password-field="reset_password" value=""></div>'
                + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-user-password-reset>🔁 Resetear contraseña</button></div></div>';
        } else {
            state.passwordDraft = { current_password: '', new_password: '', confirm_password: '', target_user_id: 0, reset_password: '' };
            ns.$('authModalWrap').innerHTML = '<div class="modal active"><header><h3>🔑 Cambiar contraseña</h3><button class="close" data-close-modal="authModalWrap">×</button></header>'
                + '<div class="field"><label>Contraseña actual</label><input class="input" type="password" data-password-field="current_password" value=""></div>'
                + '<div class="field"><label>Nueva contraseña</label><input class="input" type="password" data-password-field="new_password" value=""></div>'
                + '<div class="field"><label>Confirmar nueva contraseña</label><input class="input" type="password" data-password-field="confirm_password" value=""></div>'
                + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-change-password>💾 Cambiar contraseña</button></div></div>';
        }
        ns.$('authModalWrap').classList.add('active');
    };

    ns.openTopupModal = function () {
        var d = state.topupDraft;
        ns.$('walletModalWrap').innerHTML = '<div class="modal active"><header><h3>💳 Solicitar recarga</h3><button class="close" data-close-modal="walletModalWrap">×</button></header>'
            + '<div class="field"><label>Monto (CUP)</label><input class="input" type="number" data-topup-field="amount" value="' + ns.esc(d.amount) + '"></div>'
            + '<div class="field"><label>Método</label><select class="select" data-topup-field="payment_method"><option value="Transfermóvil"' + (d.payment_method === 'Transfermóvil' ? ' selected' : '') + '>Transfermóvil</option><option value="EnZona"' + (d.payment_method === 'EnZona' ? ' selected' : '') + '>EnZona</option></select></div>'
            + '<div class="field"><label>Referencia</label><input class="input" data-topup-field="reference_code" value="' + ns.esc(d.reference_code) + '"></div>'
            + '<div class="field"><label>Nota</label><input class="input" data-topup-field="note" value="' + ns.esc(d.note) + '"></div>'
            + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-topup>📨 Enviar solicitud</button></div></div>';
        ns.$('walletModalWrap').classList.add('active');
    };

    ns.openFinanceModal = function () {
        var d = state.financeDraft;
        if (d.mode === 'charge') {
            ns.$('walletModalWrap').innerHTML = '<div class="modal active"><header><h3>🧾 Crear cargo RAC</h3><button class="close" data-close-modal="walletModalWrap">×</button></header>'
                + '<div class="field"><label>Dueño</label><select class="select" data-finance-field="owner_id"><option value="">-- seleccionar --</option>' + (state.ownerAdminList || []).map(function (owner) {
                    return '<option value="' + ns.esc(owner.id) + '"' + (String(d.owner_id || '') === String(owner.id) ? ' selected' : '') + '>' + ns.esc((owner.ownerCode || '') + ' · ' + (owner.ownerName || '')) + '</option>';
                }).join('') + '</select></div>'
                + '<div class="field"><label>Tipo</label><select class="select" data-finance-field="charge_type"><option value="subscription"' + (d.charge_type === 'subscription' ? ' selected' : '') + '>subscription</option><option value="advertising"' + (d.charge_type === 'advertising' ? ' selected' : '') + '>advertising</option></select></div>'
                + '<div class="field"><label>Monto</label><input class="input" type="number" data-finance-field="amount" value="' + ns.esc(d.amount) + '"></div>'
                + '<div class="field"><label>Referencia</label><input class="input" data-finance-field="reference_code" value="' + ns.esc(d.reference_code) + '"></div>'
                + '<div class="field"><label>Vence</label><input class="input" data-finance-field="due_at" value="' + ns.esc(d.due_at) + '"></div>'
                + '<div class="field"><label>Nota</label><input class="input" data-finance-field="note" value="' + ns.esc(d.note) + '"></div>'
                + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-billing-charge>💾 Crear cargo</button></div></div>';
        } else if (d.mode === 'import') {
            ns.$('walletModalWrap').innerHTML = '<div class="modal active"><header><h3>📥 Importar extracto</h3><button class="close" data-close-modal="walletModalWrap">×</button></header>'
                + '<div class="field"><label>Canal</label><select class="select" data-finance-field="payment_channel"><option value="Transfermóvil"' + (d.payment_channel === 'Transfermóvil' ? ' selected' : '') + '>Transfermóvil</option><option value="EnZona"' + (d.payment_channel === 'EnZona' ? ' selected' : '') + '>EnZona</option></select></div>'
                + '<div class="field"><label>CSV pegado</label><textarea class="textarea" data-finance-field="csv_text" placeholder="reference_code,amount,payer_name,paid_at,note">' + ns.esc(d.csv_text || '') + '</textarea></div>'
                + '<div class="field"><label>Archivo CSV</label><input class="input" type="file" id="racPaymentExtractFile" accept=".csv,text/csv,.txt"></div>'
                + '<div class="sub" style="margin-bottom:12px">Formato esperado: referencia,monto,pagador,fecha,note</div>'
                + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-payment-import>📥 Importar extracto</button></div></div>';
        } else {
            ns.$('walletModalWrap').innerHTML = '<div class="modal active"><header><h3>🔗 Conciliar pago por referencia</h3><button class="close" data-close-modal="walletModalWrap">×</button></header>'
                + '<div class="field"><label>Canal</label><select class="select" data-finance-field="payment_channel"><option value="Transfermóvil"' + (d.payment_channel === 'Transfermóvil' ? ' selected' : '') + '>Transfermóvil</option><option value="EnZona"' + (d.payment_channel === 'EnZona' ? ' selected' : '') + '>EnZona</option></select></div>'
                + '<div class="field"><label>Referencia</label><input class="input" data-finance-field="reference_code" value="' + ns.esc(d.reference_code) + '"></div>'
                + '<div class="field"><label>Monto</label><input class="input" type="number" data-finance-field="amount" value="' + ns.esc(d.amount) + '"></div>'
                + '<div class="field"><label>Nota</label><input class="input" data-finance-field="note" value="' + ns.esc(d.note) + '"></div>'
                + '<div class="footer-actions"><button class="btn primary" style="width:100%" data-save-payment-reconcile>✅ Conciliar</button></div></div>';
        }
        ns.$('walletModalWrap').classList.add('active');
    };

    ns.openLeadFlowModal = function () {
        var flow = state.currentLeadFlow;
        if (!flow) {
            return;
        }
        ns.$('flowModalWrap').innerHTML = '<div class="modal active"><header><h3>💸 Flujo financiero del lead</h3><button class="close" data-close-modal="flowModalWrap">×</button></header>'
            + '<div class="card mini"><div class="item-title">' + ns.esc(flow.product) + '</div><div class="sub">' + ns.esc(flow.traceCode) + ' · ' + ns.esc(flow.status) + '</div>'
            + '<div class="two-col" style="margin-top:12px"><div><div class="sub">Dueño</div><div class="money">' + ns.esc(flow.owner) + '</div></div><div><div class="sub">Gestor</div><div class="money">' + ns.esc(flow.gestor) + '</div></div></div>'
            + '<div class="two-col" style="margin-top:12px"><div><div class="sub">Comisión total</div><div class="money">' + ns.formatCUP(flow.commission) + '</div></div><div><div class="sub">Garantía bloqueada</div><div class="money">' + ns.formatCUP(flow.lockedCommission) + '</div></div></div>'
            + '<div class="two-col" style="margin-top:12px"><div><div class="sub">Pago gestor</div><div class="money">' + ns.formatCUP(flow.gestorShare) + '</div></div><div><div class="sub">Revenue plataforma</div><div class="money">' + ns.formatCUP(flow.platformShare) + '</div></div></div></div>'
            + '<div class="section-title" style="margin-top:18px"><h3>Línea de tiempo</h3></div><div class="list">'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Trigger de contacto</div><div class="sub">' + ns.esc(flow.triggeredAt || '—') + '</div></div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Apertura de contacto</div><div class="sub">' + ns.esc(flow.contactOpenedAt || '—') + '</div></div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Cierre vendido</div><div class="sub">' + ns.esc(flow.soldAt || '—') + '</div></div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">No concretado</div><div class="sub">' + ns.esc(flow.noSaleAt || '—') + '</div></div></div></div>'
            + '</div><div class="section-title" style="margin-top:18px"><h3>Movimientos de wallet</h3></div><div class="list">'
            + (flow.movements || []).map(function (m) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(ns.movementLabel(m.movementType)) + '</div><div class="sub">' + ns.esc(m.note || '') + ' · ' + ns.esc(m.createdAt) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(m.amount) + '</div><div class="sub">Disp ' + ns.formatCUP(m.deltaAvailable) + ' · Bloq ' + ns.formatCUP(m.deltaBlocked) + '</div></div></div></div>';
            }).join('')
            + '</div></div>';
        ns.$('flowModalWrap').classList.add('active');
    };

    ns.healthBadge = function (ok) {
        if (ok === true) return ns.badge('active');
        if (ok === false) return ns.badge('no_sale');
        return ns.badge('pending');
    };

    ns.renderOwnerOfflineQueueCard = function (root) {
        if (!root || state.ownerTab !== 'wallet') return;
        var queueItems = (state.queue || []).slice(0, 8);
        root.insertAdjacentHTML('beforeend', '<div class="card" style="margin-top:18px"><div class="item-title">Cola offline</div><div class="list" style="margin-top:12px">' + (queueItems.length ? queueItems.map(function (item) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(item.type) + '</div><div class="sub">' + ns.esc(item.status || 'pending') + (item.lastError ? ' · ' + ns.esc(item.lastError) : '') + '</div></div><div class="sub">' + ns.esc(item.createdAt || '') + '</div></div></div>'; }).join('') : '<div class="item"><div class="sub">Sin cambios pendientes en este dispositivo.</div></div>') + '</div></div>');
    };

    ns.renderAdminAnalyticsBlocks = function (root) {
        if (!root || state.adminTab !== 'dashboard') return;
        var funnel = (state.analytics || {}).funnel || {};
        var trend = ((state.analytics || {}).dailyTrend || []).slice(-7);
        var ownerCohorts = ((state.analytics || {}).ownerCohorts || []).slice(0, 6);
        var gestorCohorts = ((state.analytics || {}).gestorCohorts || []).slice(0, 6);
        var sponsoredRoi = ((state.analytics || {}).sponsoredRoi || []).slice(0, 6);
        root.insertAdjacentHTML('beforeend',
            '<div class="grid two" style="margin-top:18px">'
            + '<div class="card"><div class="item-title">Embudo RAC</div><div class="list" style="margin-top:12px">'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Clics</div><div class="sub">Trazas abiertas</div></div><div class="money">' + ns.esc(funnel.clicks || 0) + '</div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Leads</div><div class="sub">' + ns.esc(funnel.leadRate || 0) + '% base</div></div><div class="money">' + ns.esc(funnel.leads || 0) + '</div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Contactados</div><div class="sub">' + ns.esc(funnel.contactRate || 0) + '%</div></div><div class="money">' + ns.esc(funnel.contacts || 0) + '</div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Negociando</div><div class="sub">' + ns.esc(funnel.negotiatingRate || 0) + '%</div></div><div class="money">' + ns.esc(funnel.negotiating || 0) + '</div></div></div>'
            + '<div class="item"><div class="item-head"><div><div class="item-title">Vendidos</div><div class="sub">' + ns.esc(funnel.soldRate || 0) + '%</div></div><div class="money">' + ns.esc(funnel.sold || 0) + '</div></div></div>'
            + '</div></div>'
            + '<div class="card"><div class="item-title">Tendencia diaria (7 días)</div><div class="list" style="margin-top:12px">'
            + (trend.length ? trend.map(function (row) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(row.day) + '</div><div class="sub">Leads ' + ns.esc(row.leads) + ' · Contactos ' + ns.esc(row.contacts) + ' · Vendidos ' + ns.esc(row.sold) + '</div></div><div class="money">' + ns.formatCUP(row.revenue || 0) + '</div></div></div>'; }).join('') : '<div class="item"><div class="sub">Sin datos diarios todavía.</div></div>')
            + '</div></div></div>'
            + '<div class="grid two" style="margin-top:18px">'
            + '<div class="card"><div class="item-title">Cohortes · Dueños</div><div class="list" style="margin-top:12px">'
            + (ownerCohorts.length ? ownerCohorts.map(function (row) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(row.ownerCode + ' · ' + row.ownerName) + '</div><div class="sub">Leads ' + ns.esc(row.leads) + ' · Contacto ' + ns.esc(row.contactRate) + '% · Cierre ' + ns.esc(row.conversionRate) + '%</div></div><div class="money">' + ns.formatCUP(row.revenue || 0) + '</div></div></div>'; }).join('') : '<div class="item"><div class="sub">Sin cohortes de dueños.</div></div>')
            + '</div></div>'
            + '<div class="card"><div class="item-title">Cohortes · Gestores</div><div class="list" style="margin-top:12px">'
            + (gestorCohorts.length ? gestorCohorts.map(function (row) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(row.id + ' · ' + row.name) + '</div><div class="sub">Leads ' + ns.esc(row.leads) + ' · Contacto ' + ns.esc(row.contactRate) + '% · Cierre ' + ns.esc(row.conversionRate) + '%</div></div><div class="money">' + ns.formatCUP(row.earned || 0) + '</div></div></div>'; }).join('') : '<div class="item"><div class="sub">Sin cohortes de gestores.</div></div>')
            + '</div></div></div>'
            + '<div class="card" style="margin-top:18px"><div class="item-title">ROI patrocinado</div><div class="list" style="margin-top:12px">'
            + (sponsoredRoi.length ? sponsoredRoi.map(function (row) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(row.name) + '</div><div class="sub">' + ns.esc(row.ownerCode) + ' · presupuesto ' + ns.formatCUP(row.advertisingBudget || 0) + '</div></div><div style="text-align:right"><div class="money">' + ns.esc(row.roiPct || 0) + '%</div><div class="sub">rev ' + ns.formatCUP(row.platformRevenue || 0) + '</div></div></div></div>'; }).join('') : '<div class="item"><div class="sub">Sin productos patrocinados suficientes para ROI.</div></div>')
            + '</div></div>'
        );
    };

    ns.renderAdminSecurityBlocks = function (root) {
        if (!root || state.adminTab !== 'users') return;
        var sessions = (state.activeSessions || []).slice(0, 20);
        var lockouts = (state.recentLockouts || []).slice(0, 20);
        root.insertAdjacentHTML('beforeend',
            '<div class="grid two" style="margin-top:18px">'
            + '<div class="card"><div class="item-title">Sesiones activas RAC</div><div class="list" style="margin-top:12px">'
            + (sessions.length ? sessions.map(function (s) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc((s.displayName || s.username) + ' · ' + (s.role || '')) + '</div><div class="sub">' + ns.esc(s.ipAddress || 'sin ip') + ' · expira ' + ns.esc(s.expiresAt || '') + '</div></div><div class="sub">' + ns.esc(s.lastSeenAt || '') + '</div></div><div class="actions"><button class="btn ghost" data-revoke-session="' + ns.esc(s.sessionId || '') + '">⛔ Revocar</button></div></div>'; }).join('') : '<div class="item"><div class="sub">Sin sesiones activas.</div></div>')
            + '</div></div>'
            + '<div class="card"><div class="item-title">Bloqueos por fallos</div><div class="list" style="margin-top:12px">'
            + (lockouts.length ? lockouts.map(function (l) { return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(l.username) + '</div><div class="sub">Fallos ' + ns.esc(l.failedCount) + ' · último ' + ns.esc(l.lastAttempt || '') + '</div></div><div>' + ns.badge(l.active ? 'pending' : 'active') + '</div></div><div class="sub" style="margin-top:8px">Bloqueado hasta ' + ns.esc(l.lockedUntil || 'N/D') + '</div></div>'; }).join('') : '<div class="item"><div class="sub">Sin bloqueos recientes.</div></div>')
            + '</div></div></div>'
        );
    };

    ns.openProductModal = function () {
        var p = state.ownerNewProduct;
        var isEdit = !!p.id;
        ns.$('productModalWrap').innerHTML = '<div class="modal active"><header><h3>' + (isEdit ? '🛠️ Editar producto RAC' : '🪔 Publicar nuevo producto') + '</h3><button class="close" data-close-modal="productModalWrap">×</button></header>'
            + ns.field('name', 'Nombre del producto', p.name)
            + '<div class="field"><label>Categoria</label><select class="select" data-product-field="category">'
            + ['Tecnologia', 'Electrodomesticos', 'Muebles', 'Transporte', 'Ropa', 'Alimentacion', 'Otros'].map(function (c) {
                return '<option value="' + ns.esc(c) + '"' + (p.category === c ? ' selected' : '') + '>' + ns.esc(c) + '</option>';
            }).join('')
            + '</select></div>'
            + ns.field('price', 'Precio (CUP)', p.price, 'number')
            + ns.field('stock', 'Stock disponible', p.stock, 'number')
            + ns.field('commission', 'Comision por venta (CUP)', p.commission, 'number')
            + ns.field('brand', 'Marca', p.brand)
            + ns.field('couponLabel', 'Cupón / beneficio RAC', p.couponLabel)
            + '<div class="field"><label>Producto destacado</label><select class="select" data-product-field="isFeatured"><option value="0"' + (Number(p.isFeatured || 0) === 0 ? ' selected' : '') + '>No</option><option value="1"' + (Number(p.isFeatured || 0) === 1 ? ' selected' : '') + '>Si</option></select></div>'
            + ns.field('sponsorRank', 'Prioridad patrocinada', p.sponsorRank, 'number')
            + '<div class="field"><label>Descripcion</label><textarea class="textarea" data-product-field="description">' + ns.esc(p.description) + '</textarea></div>'
            + '<div class="field"><label>Imagen del producto</label><input class="input" id="racProductImage" type="file" accept="image/png,image/jpeg,image/webp"></div>'
            + (p.imagePreview ? '<div class="product-media" style="margin-bottom:14px"><img loading="lazy" src="' + ns.esc(p.imagePreview) + '" alt="Preview"></div>' : '')
            + '<div class="footer-actions">'
            + (isEdit && (p.hasImage || p.imagePreview) ? '<button class="btn ghost" type="button" data-remove-product-image>🗑️ Quitar imagen</button>' : '')
            + '<button class="btn primary" style="width:100%" data-save-product>' + (isEdit ? '💾 Guardar cambios' : '✨ Publicar producto') + '</button></div></div>';
        ns.$('productModalWrap').classList.add('active');
    };

    ns.renderOwner = function () {
        var root = ns.$('panel-dueno');
        var tabs = [['dashboard', '📊 Dashboard'], ['inventario', '📦 Inventario'], ['leads', '📋 Leads'], ['wallet', '💳 Wallet']];
        var body = '';

        if (state.ownerTab === 'dashboard') {
            body = '<div class="grid kpis">'
                + ns.kpi('💳', 'Saldo disponible', ns.formatCUP(state.owner.wallet.available), 'Productos visibles', '#ffd700')
                + ns.kpi('🔒', 'Saldo bloqueado', ns.formatCUP(state.owner.wallet.blocked), 'Garantia RAC', '#ff8c00')
                + ns.kpi('📈', 'Conversion', ns.fmtPct(state.owner.conversionRate), 'Ventas/Leads', '#ff4500')
                + ns.kpi('⭐', 'Reputacion', String(state.owner.reputationScore), 'Riesgo ' + state.owner.fraudRisk, '#ffd700')
                + '</div><div class="grid two"><div class="card"><div class="item-title">Wallet y visibilidad</div><p>Plan ' + ns.esc(state.owner.subscriptionPlan) + ' · Gestion asistida ' + (state.owner.managedService ? 'activa' : 'no contratada') + '. Si el saldo disponible llega a cero, el catalogo desaparece para los gestores.</p><div class="two-col" style="margin-top:12px">' + ns.stat('Disponible', ns.formatCUP(state.owner.wallet.available), 'Marketplace visible') + ns.stat('Bloqueado', ns.formatCUP(state.owner.wallet.blocked), 'Leads abiertos') + '</div></div><div class="card"><div class="item-title">Asistente de precios inteligente</div>'
                + (state.pricingSuggestions.length ? '<div class="list" style="margin-top:12px">' + state.pricingSuggestions.slice(0, 4).map(function (item) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(item.category) + '</div><div class="sub">' + ns.esc(item.items) + ' producto(s) comparados</div></div><div style="text-align:right"><div class="money">Moda ' + ns.formatCUP(item.modePrice) + '</div><div class="sub">Ponderado ' + ns.formatCUP(item.weightedPrice) + '</div></div></div></div>';
                }).join('') + '</div>' : '<p>No hay suficientes productos para sugerir precios.</p>') + '</div></div><div class="card warning"><div>⚠️</div><div><div class="item-title">Proteccion anti-salto</div><div class="sub">Cada contacto abre un lead con garantia bloqueada. La tasa de conversion alimenta la reputacion del dueño y dispara vigilancia cuando se degrada.</div></div></div><div class="section-title"><h3>Leads recientes</h3></div><div class="list">'
                + state.leads.slice(0, 4).map(function (lead) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · ' + ns.esc(lead.date) + ' · Gestor ' + ns.esc(lead.gestorId) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(lead.lockedCommission || lead.commission) + '</div>' + ns.badge(lead.status) + '</div></div></div>';
                }).join('') + '</div>';
        } else if (state.ownerTab === 'inventario') {
            body = '<div class="section-title"><h3>Inventario invisible del dueño</h3><button class="btn primary" data-open-product>+ Nuevo producto</button></div><div class="cards">'
                + state.products.map(function (p) {
                    var stats = state.ownerProductStats.find(function (item) { return item.id === p.id; }) || {};
                    return '<div class="card product-card">' + (p.trending ? '<div class="trend">🔥 TREND</div>' : '')
                        + ns.productVisual(p) + '<div class="item-title" style="margin:10px 0 4px">' + ns.esc(p.name) + '</div><div class="sub">' + ns.esc(p.category) + ' · ' + ns.esc(p.brand) + ' · Stock ' + ns.esc(p.stock) + '</div><div style="margin-top:8px;font-size:15px;font-weight:900;color:var(--amber)">' + ns.formatCUP(p.price) + '</div><div class="two-col" style="margin-top:14px"><div><div class="sub">Comision</div><div class="money">' + ns.formatCUP(p.commission) + '</div></div><div><div class="sub">% visible</div><div style="font-weight:800;color:var(--fire)">' + ns.esc(p.commissionPct) + '%</div></div></div><div class="sub" style="margin-top:12px">' + ns.esc((p.description || '').substring(0, 90)) + '</div>' + (p.couponLabel ? '<div class="sub" style="margin-top:8px"><strong>Beneficio:</strong> ' + ns.esc(p.couponLabel) + '</div>' : '') + (Number(p.isFeatured || 0) ? '<div class="sub" style="margin-top:8px"><strong>Patrocinado:</strong> prioridad ' + ns.esc(p.sponsorRank || 0) + '</div>' : '') + '<div class="two-col" style="margin-top:12px"><div><div class="sub">Clics / Leads / Ventas</div><div class="money">' + ns.esc(stats.clicks || 0) + ' / ' + ns.esc(stats.leads || 0) + ' / ' + ns.esc(stats.sales || 0) + '</div></div><div><div class="sub">Conversión</div><div class="money">' + ns.esc(stats.conversionRate || 0) + '%</div></div></div><div class="two-col" style="margin-top:12px"><div><div class="sub">Pago a gestores</div><div class="money">' + ns.formatCUP(stats.gestorPaid || 0) + '</div></div><div><div class="sub">Ingreso plataforma</div><div class="money">' + ns.formatCUP(stats.platformEarned || 0) + '</div></div></div><div class="actions"><button class="btn ghost" data-edit-product="' + ns.esc(p.id) + '">🛠️ Editar</button><button class="btn ghost" data-toggle-product="' + ns.esc(p.id) + '" data-active="' + (p.active ? '0' : '1') + '">' + (p.active ? '⏸️ Desactivar' : '♻️ Reactivar') + '</button></div>' + (!p.active ? '<div class="sub" style="margin-top:10px;color:#8f2f2f">Producto oculto del marketplace</div>' : '') + '</div>';
                }).join('') + '</div>';
        } else if (state.ownerTab === 'leads') {
            var leadPage = ns.paginate(state.leads, state.ownerLeadPage, 8);
            body = '<div class="section-title"><h3>Leads trazados</h3></div><div class="list">'
                + leadPage.items.map(function (lead) {
                    var actions = lead.status !== 'sold' && lead.status !== 'no_sale'
                        ? '<div class="actions"><button class="btn primary" data-lead-status="' + ns.esc(lead.id) + '" data-status="sold">✓ Vendido</button><button class="btn ghost" data-lead-status="' + ns.esc(lead.id) + '" data-status="contacted">💬 Contactado</button><button class="btn ghost" data-lead-status="' + ns.esc(lead.id) + '" data-status="negotiating">⏳ Negociando</button><button class="btn ghost" data-lead-status="' + ns.esc(lead.id) + '" data-status="no_sale">✗ No concretado</button></div>' : '';
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · Gestor ' + ns.esc(lead.gestorId) + ' · ' + ns.esc(lead.date) + ' · Cliente ' + ns.esc(lead.client) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(lead.lockedCommission || lead.commission) + '</div>' + ns.badge(lead.status) + '</div></div>' + actions + '</div>';
                }).join('') + '</div>' + ns.pager('ownerLead', leadPage.current, leadPage.total);
        } else {
            var ownerTopups = (state.walletTopups || []).filter(function (t) { return t.ownerCode === state.owner.code; });
            var ownerCharges = (state.billingCharges || []).filter(function (c) { return c.ownerCode === state.owner.code; });
            body = '<div class="grid kpis">'
                + ns.kpi('💳', 'Disponible', ns.formatCUP(state.owner.wallet.available), 'Visible al marketplace', '#ffd700')
                + ns.kpi('🔒', 'Bloqueado', ns.formatCUP(state.owner.wallet.blocked), 'Garantia retenida', '#ff8c00')
                + ns.kpi('🧮', 'Total', ns.formatCUP(state.owner.wallet.total), 'Wallet prepaga', '#ff4500')
                + ns.kpi('🛠️', 'Plan', String(state.owner.subscriptionPlan).toUpperCase(), state.owner.managedService ? 'Con gestion' : 'Autogestionado', '#ffd700')
                + '</div><div class="section-title"><h3>Recargas wallet</h3><button class="btn primary" data-open-topup>+ Solicitar recarga</button></div><div class="list">'
                + (ownerTopups.length ? ownerTopups.map(function (t) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.formatCUP(t.amount) + ' · ' + ns.esc(t.paymentMethod) + '</div><div class="sub">' + ns.esc(t.referenceCode) + ' · ' + ns.esc(t.createdAt) + '</div></div><div>' + ns.badge(t.status === 'approved' ? 'active' : (t.status === 'rejected' ? 'no_sale' : 'pending')) + '</div></div></div>';
                }).join('') : '<div class="item"><div class="sub">No hay recargas registradas.</div></div>')
                + '</div><div class="section-title"><h3>Cobros RAC</h3></div><div class="list">'
                + (ownerCharges.length ? ownerCharges.map(function (c) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(c.chargeType) + ' · ' + ns.esc(c.referenceCode) + '</div><div class="sub">' + ns.esc(c.note || '') + ' · Vence ' + ns.esc(c.dueAt || 'N/D') + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(c.amount) + '</div>' + ns.badge(c.status === 'paid' ? 'active' : 'pending') + '</div></div></div>';
                }).join('') : '<div class="item"><div class="sub">Sin cargos pendientes o pagados para este dueño.</div></div>')
                + '</div><div class="grid two"><div class="card"><div class="item-title">Conciliacion del ledger</div><div class="list" style="margin-top:12px"><div class="item"><div class="item-head"><div><div class="item-title">Disponible calculado</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.calculatedAvailable) + '</div></div></div><div class="item"><div class="item-head"><div><div class="item-title">Bloqueado calculado</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.calculatedBlocked) + '</div></div></div><div class="item"><div class="item-head"><div><div class="item-title">Descuadre disponible</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.availableMismatch) + '</div></div></div><div class="item"><div class="item-head"><div><div class="item-title">Descuadre bloqueado</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.blockedMismatch) + '</div></div></div></div><div class="sub" style="margin-top:12px">' + (state.walletReconciliation.ok ? 'Ledger conciliado.' : 'Hay diferencias entre saldo calculado y saldo persistido.') + '</div></div><div class="card"><div class="item-title">Estado operativo</div><div class="list" style="margin-top:12px"><div class="item"><div class="item-head"><div><div class="item-title">Garantias vencidas liberadas</div></div><div class="money">' + ns.esc(state.summary.expiredHolds || 0) + '</div></div></div><div class="item"><div class="item-head"><div><div class="item-title">Riesgo actual</div></div><div>' + ns.badge(state.owner.fraudRisk === 'BAJO' ? 'active' : 'pending') + '</div></div></div></div></div></div><div class="section-title"><h3>Movimientos de wallet</h3></div><div class="list">'
                + state.walletMovements.map(function (m) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(ns.movementLabel(m.movementType)) + '</div><div class="sub">' + ns.esc(m.note || '') + ' · ' + ns.esc(m.createdAt) + '</div></div><div class="money">' + ns.formatCUP(m.amount) + '</div></div></div>';
                }).join('') + '</div>';
        }
        root.innerHTML = ns.panelHeader('🏪 Panel del Dueño · ' + state.owner.name + ' · ' + state.owner.code, ns.formatCUP(state.owner.wallet.available), 'Saldo disponible') + ns.tabRow('owner', tabs, state.ownerTab) + body;
        ns.renderOwnerOfflineQueueCard(root);
    };

    ns.renderGestor = function () {
        var root = ns.$('panel-gestor');
        var tabs = [['marketplace', '🛍️ Marketplace'], ['mis_links', '🔗 Mis Links'], ['ganancias', '✨ Ganancias']];
        var cats = ['Todos'].concat(Array.from(new Set(state.products.map(function (p) { return p.category; }))).sort());
        var q = String(state.gestorFilter.q || '').toLowerCase();
        var products = state.products.filter(function (p) {
            var categoryOk = state.gestorFilter.category === 'Todos' || p.category === state.gestorFilter.category;
            var text = [p.name, p.brand, p.category, p.description].join(' ').toLowerCase();
            return Number(p.active || 0) === 1 && categoryOk && (!q || text.indexOf(q) !== -1);
        }).sort(function (a, b) {
            if (state.gestorFilter.sort === 'commission') return Number(b.commission) - Number(a.commission);
            if (state.gestorFilter.sort === 'clicks') return Number(b.clicks) - Number(a.clicks);
            return Number(b.trending) - Number(a.trending);
        });
        var body = '';

        if (state.gestorTab === 'marketplace') {
            body = '<div class="field"><label>Buscar producto</label><input class="input" id="gestorSearch" type="text" value="' + ns.esc(state.gestorFilter.q) + '" placeholder="Nombre, marca o categoria"></div>'
                + '<div class="pill-row" style="margin-bottom:10px">' + cats.map(function (c) {
                    return '<button class="' + (state.gestorFilter.category === c ? 'active' : '') + '" data-gestor-category="' + ns.esc(c) + '">' + ns.esc(c) + '</button>';
                }).join('') + '</div>'
                + '<div class="pill-row" style="margin-bottom:18px"><button class="' + (state.gestorFilter.sort === 'trending' ? 'active' : '') + '" data-gestor-sort="trending">🔥 Tendencia</button><button class="' + (state.gestorFilter.sort === 'commission' ? 'active' : '') + '" data-gestor-sort="commission">✨ Mayor comision</button><button class="' + (state.gestorFilter.sort === 'clicks' ? 'active' : '') + '" data-gestor-sort="clicks">👁️ Mas visto</button></div>'
                + '<div class="cards">' + products.map(function (p) {
                    return '<div class="card product-card">' + (p.trending ? '<div class="trend">🔥 TREND</div>' : '')
                        + ns.productVisual(p) + '<div class="item-title" style="margin:10px 0 4px">' + ns.esc(p.name) + '</div><div class="sub">' + ns.esc(p.category) + ' · ' + ns.esc(p.brand) + '</div><div style="margin-top:8px;font-size:15px;font-weight:900;color:var(--amber)">' + ns.formatCUP(p.price) + '</div><div class="two-col" style="margin-top:14px"><div><div class="sub">Tu comision 80%</div><div class="money">' + ns.formatCUP(Number(p.commission || 0) * 0.8) + '</div></div><div><div class="sub">Conversion</div><div style="font-weight:800;color:var(--fire)">' + ns.fmtPct((Number(p.sales || 0) / Math.max(Number(p.leads || 0), 1)) * 100) + '</div></div></div><div class="sub" style="margin-top:12px">' + ns.esc((p.description || '').substring(0, 88)) + '</div><div class="actions"><button class="btn primary" data-generate-link="' + ns.esc(p.id) + '">🔗 Generar enlace</button></div></div>';
                }).join('') + '</div>';
        } else if (state.gestorTab === 'mis_links') {
            var linkPage = ns.paginate(state.traceLinks, state.gestorLinksPage, 6);
            body = '<div class="section-title"><h3>Mis enlaces activos</h3></div><div class="list">'
                + (linkPage.items.length ? linkPage.items.map(function (link) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(link.product) + '</div><div class="code" style="margin-top:8px">' + ns.esc(link.link) + '</div><div class="sub" style="margin-top:8px">Ref ' + ns.esc(link.maskedRef) + (link.lastOpenedAt ? ' · Última apertura ' + ns.esc(link.lastOpenedAt) : '') + '</div></div><div style="display:flex;gap:16px;flex-wrap:wrap"><div style="text-align:center"><div class="sub">Clics</div><div class="money">' + ns.esc(link.clicks) + '</div></div><div style="text-align:center"><div class="sub">Leads</div><div class="money">' + ns.esc(link.leads) + '</div></div><div style="text-align:center"><div class="sub">Ventas</div><div class="money">' + ns.esc(link.sold || 0) + '</div></div><div style="text-align:center"><div class="sub">CTR</div><div class="money">' + ns.esc(link.ctr || 0) + '%</div></div><div style="text-align:center"><div class="sub">Cierre</div><div class="money">' + ns.esc(link.closeRate || 0) + '%</div></div><div style="text-align:center"><div class="sub">Ganado</div><div class="money">' + ns.formatCUP(link.earned) + '</div></div></div></div><div class="actions"><button class="btn ghost" data-copy-link="' + ns.esc(link.link) + '">📋 Copiar link</button><a class="btn ghost" href="https://wa.me/?text=' + ns.esc(encodeURIComponent(link.product + ' ' + link.link)) + '" target="_blank" rel="noopener">💬 Compartir</a></div></div>';
                }).join('') : '<div class="card"><p>Aun no has generado enlaces trazados.</p></div>')
                + '</div>' + ns.pager('gestorLinks', linkPage.current, linkPage.total);
        } else {
            var gestor = state.gestores[0] || { earnings: 0, links: 0, conversions: 0, rating: 0, reputationScore: 0 };
            body = '<div class="grid kpis">'
                + ns.kpi('✨', 'Este mes', ns.formatCUP(gestor.earnings), 'Comisiones cerradas', '#ffd700')
                + ns.kpi('🔗', 'Links activos', String(gestor.links || 0), 'Trazas generadas', '#ff8c00')
                + ns.kpi('✅', 'Ventas cerradas', String(gestor.conversions || 0), 'Cierres confirmados', '#ff4500')
                + ns.kpi('⭐', 'Reputacion', String(gestor.reputationScore || gestor.rating || 0), 'Calidad del gestor', '#ffd700')
                + '</div><div class="section-title"><h3>Historial de comisiones</h3></div><div class="list">'
                + state.leads.filter(function (lead) { return lead.gestorId === 'G001'; }).map(function (lead) {
                    var value = lead.status === 'sold' ? lead.gestorShare : (lead.status === 'no_sale' ? 0 : lead.gestorShare);
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · ' + ns.esc(lead.date) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(value) + '</div>' + ns.badge(lead.status) + '</div></div></div>';
                }).join('') + '</div>';
        }

        root.innerHTML = ns.panelHeader('🪔 Panel del Gestor · Carlos Mendez · G001', ns.formatCUP((state.gestores[0] || {}).earnings || 0), 'Ganado este mes') + ns.tabRow('gestor', tabs, state.gestorTab) + body;
    };

    ns.renderAdmin = function () {
        var root = ns.$('panel-admin');
        var tabs = [['dashboard', '📊 BI Dashboard'], ['users', '👥 Usuarios'], ['transactions', '💸 Transacciones'], ['audit', '🔍 Auditoria']];
        var body = '';
        if (state.adminTab === 'dashboard') {
            var topProducts = state.products.slice().sort(function (a, b) { return Number(b.clicks) - Number(a.clicks); }).slice(0, 5);
            var topGestores = state.gestores.slice().sort(function (a, b) { return Number(b.earnings) - Number(a.earnings); }).slice(0, 5);
            var health = state.health || {};
            var externalPayments = state.externalPayments || [];
            var matchedPayments = externalPayments.filter(function (payment) { return payment.status === 'matched'; }).length;
            var unmatchedPayments = externalPayments.filter(function (payment) { return payment.status === 'unmatched'; }).length;
            var pendingPayments = externalPayments.filter(function (payment) { return payment.status === 'pending'; }).length;
            var matchRate = externalPayments.length ? (((matchedPayments / externalPayments.length) * 100).toFixed(1) + '%') : '0.0%';
            var byChannel = {};
            externalPayments.forEach(function (payment) {
                var channel = payment.paymentChannel || 'Sin canal';
                byChannel[channel] = (byChannel[channel] || 0) + 1;
            });
            var topChannel = Object.keys(byChannel).sort(function (a, b) { return byChannel[b] - byChannel[a]; })[0] || 'Sin datos';
            var healthOutput = (health.output || []).slice(0, 8);
            var healthJournal = ((health.service && health.service.journal) || []).slice(0, 8);
            body = '<div class="grid kpis">'
                + ns.kpi('💸', 'Volumen total', ns.formatCUP(state.summary.volumeTotal), 'RAC', '#ffd700')
                + ns.kpi('✨', 'Revenue LAG', ns.formatCUP(state.summary.revenue), 'Plataforma', '#ff8c00')
                + ns.kpi('🏪', 'Dueños activos', String(state.summary.ownersActive), 'Tiendas visibles', '#ff4500')
                + ns.kpi('🤝', 'Gestores activos', String(state.summary.gestoresActive), 'Red comercial', '#ffd700')
                + ns.kpi('🏦', 'Pagos externos', String(externalPayments.length), 'Extracto + webhook', '#ffd700')
                + ns.kpi('🎯', 'Match rate', matchRate, matchedPayments + ' conciliados', '#28a745')
                + ns.kpi('🕓', 'Pendientes match', String(pendingPayments + unmatchedPayments), 'Pendientes o sin casar', '#ef5350')
                + ns.kpi('🏷️', 'Canal líder', topChannel, String(byChannel[topChannel] || 0) + ' pagos', '#ff8c00')
                + '</div><div class="actions" style="margin:12px 0 18px"><a class="btn ghost" href="/affiliate_network_api.php?action=export_leads">⬇️ Exportar leads CSV</a><a class="btn ghost" href="/affiliate_network_api.php?action=export_wallet">⬇️ Exportar wallet CSV</a><a class="btn ghost" href="/affiliate_network_api.php?action=export_rankings">⬇️ Exportar rankings CSV</a><a class="btn ghost" href="/affiliate_network_health.php" target="_blank" rel="noopener">🩺 Ver health JSON</a></div><div class="grid kpis">'
                + ns.kpi('🩺', 'Health general', health.ok === true ? 'OK' : (health.ok === false ? 'FAIL' : 'N/D'), health.timestamp || 'Sin ejecución', health.ok === true ? '#28a745' : '#ef5350')
                + ns.kpi('⏱️', 'Timer activo', health.timer && health.timer.active ? 'Sí' : 'No', health.timer && health.timer.next ? ('Próximo ' + health.timer.next) : 'Sin próxima ejecución', health.timer && health.timer.active ? '#ffd700' : '#ef5350')
                + ns.kpi('🧰', 'Service activo', health.service && health.service.active ? 'Sí' : 'No', health.timer && health.timer.last ? ('Último ' + health.timer.last) : 'Sin historial', health.service && health.service.active ? '#28a745' : '#ff8c00')
                + ns.kpi('💼', 'MRR esperado', ns.formatCUP((state.subscriptionMetrics && state.subscriptionMetrics.expectedMrr) || 0), 'Suscripciones', '#ffd700')
                + ns.kpi('📣', 'Publicidad activa', String((state.subscriptionMetrics && state.subscriptionMetrics.adsActiveOwners) || 0), 'Dueños con ads', '#ff8c00')
                + ns.kpi('🧾', 'Recargas pendientes', String((state.subscriptionMetrics && state.subscriptionMetrics.pendingTopups) || 0), 'Por revisar', '#ff4500')
                + ns.kpi('📋', 'Checks health', String((health.summary && health.summary.okChecks) || 0), ((health.summary && health.summary.checks) || 0) + ' líneas útiles', '#ff8c00')
                + ns.kpi('⚠️', 'Checks fallidos', String((health.summary && health.summary.failedChecks) || 0), 'Exit code ' + (health.exit_code == null ? 'N/D' : health.exit_code), (health.summary && health.summary.failedChecks) ? '#ef5350' : '#28a745')
                + '</div><div class="grid two"><div class="card"><div class="item-title">Estado health RAC</div><div class="list" style="margin-top:12px"><div class="item"><div class="item-head"><div><div class="item-title">Resumen</div><div class="sub">Modo ' + ns.esc(health.mode || 'N/D') + ' · Última ejecución ' + ns.esc(health.timestamp || 'N/D') + '</div></div><div>' + ns.healthBadge(health.ok) + '</div></div><div class="sub" style="margin-top:10px">Timer habilitado: ' + ((health.timer && health.timer.enabled) ? 'sí' : 'no') + ' · Última corrida timer: ' + ns.esc((health.timer && health.timer.last) || 'N/D') + '</div></div><div class="item"><div class="item-head"><div><div class="item-title">Próxima corrida</div><div class="sub">' + ns.esc((health.timer && health.timer.next) || 'No detectada') + '</div></div><div>' + ns.healthBadge(health.timer && health.timer.active) + '</div></div></div></div></div><div class="card"><div class="item-title">Salida reciente del health</div><div class="list" style="margin-top:12px">' + (healthOutput.length ? healthOutput.map(function (line) { return '<div class="item"><div class="sub" style="white-space:pre-wrap">' + ns.esc(line) + '</div></div>'; }).join('') : '<div class="item"><div class="sub">Sin salida registrada.</div></div>') + '</div></div></div><div class="card" style="margin-top:18px"><div class="item-title">Journal reciente del servicio</div><div class="list" style="margin-top:12px">' + (healthJournal.length ? healthJournal.map(function (line) { return '<div class="item"><div class="sub" style="white-space:pre-wrap">' + ns.esc(line) + '</div></div>'; }).join('') : '<div class="item"><div class="sub">Sin journal disponible.</div></div>') + '</div></div><div class="grid two"><div class="card"><div class="item-title">Top productos por interes</div><div class="list" style="margin-top:12px">' + topProducts.map(function (p, i) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">#' + (i + 1) + ' ' + ns.esc(p.name) + '</div><div class="sub">' + ns.esc(p.category) + '</div></div><div style="text-align:right"><div class="money">' + ns.esc(p.clicks) + '</div><div class="sub">clics</div></div></div></div>';
                }).join('') + '</div></div><div class="card"><div class="item-title">Top gestores</div><div class="list" style="margin-top:12px">' + topGestores.map(function (g, i) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">#' + (i + 1) + ' ' + ns.esc(g.name) + '</div><div class="sub">⭐ ' + ns.esc(g.rating) + ' · Rep ' + ns.esc(g.reputationScore) + '</div></div><div class="money">' + ns.formatCUP(g.earnings) + '</div></div></div>';
                }).join('') + '</div></div></div><div class="grid two"><div class="card"><div class="item-title">Zonas con mas demanda</div><div class="list" style="margin-top:12px">' + state.marketInsights.zones.map(function (z) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(z.zone) + '</div><div class="sub">Reputacion promedio ' + ns.esc(z.reputation) + '</div></div><div class="money">' + ns.esc(z.owners) + '</div></div></div>';
                }).join('') + '</div></div><div class="card"><div class="item-title">Suscripciones de dueños</div><div class="list" style="margin-top:12px"><div class="item"><div class="item-head"><div><div class="item-title">Vencidos</div></div><div class="money">' + ns.esc((state.subscriptionMetrics && state.subscriptionMetrics.overdueOwners) || 0) + '</div></div></div><div class="item"><div class="item-head"><div><div class="item-title">Managed</div></div><div class="money">' + ns.esc((state.subscriptionMetrics && state.subscriptionMetrics.managedOwners) || 0) + '</div></div></div>' + state.marketInsights.plans.map(function (p) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(String(p.plan).toUpperCase()) + '</div></div><div class="money">' + ns.esc(p.total) + '</div></div></div>';
                }).join('') + '</div></div></div><div class="grid two"><div class="card"><div class="item-title">Productos patrocinados</div><div class="list" style="margin-top:12px">' + ((state.sponsoredProducts || []).slice(0, 8)).map(function (p) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(p.name) + '</div><div class="sub">' + ns.esc((p.ownerCode || '') + ' · ' + (p.ownerName || '')) + '</div></div><div class="money">P' + ns.esc(p.sponsorRank || 0) + '</div></div></div>';
                }).join('') + '</div></div><div class="card"><div class="item-title">Ranking de enlaces RAC</div><div class="list" style="margin-top:12px">' + state.linkRankings.slice(0, 8).map(function (link) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(link.product) + '</div><div class="sub">' + ns.esc(link.owner) + ' · Gestor ' + ns.esc(link.gestor) + ' · Ref ' + ns.esc(link.maskedRef) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(link.gestorEarned || 0) + '</div><div class="sub">Gestor</div></div></div><div class="two-col" style="margin-top:12px"><div><div class="sub">Clics / Leads / Ventas</div><div class="money">' + ns.esc(link.clicks || 0) + ' / ' + ns.esc(link.leads || 0) + ' / ' + ns.esc(link.sold || 0) + '</div></div><div><div class="sub">CTR / Cierre</div><div class="money">' + ns.esc(link.ctr || 0) + '% / ' + ns.esc(link.closeRate || 0) + '%</div></div></div></div>';
                }).join('') + '</div></div></div><div class="card" style="margin-top:18px"><div class="item-title">Estado de integraciones</div><div class="list" style="margin-top:12px"><div class="item"><div class="item-head"><div><div class="item-title">Telegram</div><div class="sub">Bot configurado: ' + (state.integrations.telegramConfigured ? 'sí' : 'no') + ' · Gestor: ' + ns.esc((state.integrationSettings.defaultGestorId || '') + ' · ' + (state.integrationSettings.defaultGestorName || '')) + '</div></div><div>' + ns.badge(state.integrations.telegramConfigured ? 'active' : 'pending') + '</div></div><div class="sub" style="margin-top:10px">Chat ID configurado: ' + (state.integrationSettings.defaultGestorChatId ? 'sí' : 'no') + '</div><div class="actions"><button class="btn ghost" data-open-integrations>⚙️ Configurar Telegram</button></div></div></div></div>';
        } else if (state.adminTab === 'users') {
            var userQuery = String((state.userFilter || {}).q || '').toLowerCase();
            var userRoleFilter = String((state.userFilter || {}).role || 'all');
            var userStatusFilter = String((state.userFilter || {}).status || 'all');
            var filteredUsers = (state.affiliateUsers || []).filter(function (u) {
                var text = [u.username, u.displayName, u.role, u.ownerCode, u.gestorId, u.gestorName].join(' ').toLowerCase();
                var roleOk = userRoleFilter === 'all' || String(u.role) === userRoleFilter;
                var statusOk = userStatusFilter === 'all' || String(u.status) === userStatusFilter;
                return roleOk && statusOk && (!userQuery || text.indexOf(userQuery) !== -1);
            });
            body = '<div class="grid kpis">'
                + ns.kpi('🛡️', 'Admins RAC', String((((state.userRoleSummary || {}).admin || {}).total || 0)), 'Activos ' + (((state.userRoleSummary || {}).admin || {}).active || 0), '#ffd700')
                + ns.kpi('🏪', 'Owners RAC', String((((state.userRoleSummary || {}).owner || {}).total || 0)), 'Activos ' + (((state.userRoleSummary || {}).owner || {}).active || 0), '#ff8c00')
                + ns.kpi('🪔', 'Gestores RAC', String((((state.userRoleSummary || {}).gestor || {}).total || 0)), 'Activos ' + (((state.userRoleSummary || {}).gestor || {}).active || 0), '#ff4500')
                + '</div><div class="actions" style="margin:12px 0 18px"><a class="btn ghost" href="/affiliate_network_api.php?action=export_users">⬇️ Usuarios CSV</a><a class="btn ghost" href="/affiliate_network_api.php?action=export_users_xlsx">⬇️ Usuarios XLSX</a><a class="btn ghost" href="/affiliate_network_api.php?action=export_access_audit">⬇️ Accesos CSV</a><a class="btn ghost" href="/affiliate_network_api.php?action=export_access_audit_xlsx">⬇️ Accesos XLSX</a></div><div class="grid two"><div class="card"><div class="section-title"><h3>Dueños registrados</h3><button class="btn primary" data-open-owner-new>+ Dueño</button></div><div class="list" style="margin-top:12px">' + (state.ownerAdminList || []).map(function (owner) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(owner.ownerName || owner.ownerCode) + '</div><div class="sub">' + ns.esc(owner.ownerCode) + ' · Plan ' + ns.esc(owner.subscriptionPlan || 'basic') + ' · Fee ' + ns.formatCUP(owner.monthlyFee || 0) + '</div></div><div style="text-align:right"><div>' + ns.badge(owner.status) + '</div><div class="sub">Ads ' + (Number(owner.adsActive || 0) === 1 ? 'sí' : 'no') + '</div></div></div><div class="actions"><button class="btn ghost" data-edit-owner="' + ns.esc(owner.id) + '">🛠️ Editar</button></div></div>';
            }).join('') + '</div></div><div class="card"><div class="section-title"><h3>Gestores</h3><button class="btn primary" data-open-gestor-new>+ Gestor</button></div><div class="list" style="margin-top:12px">' + (state.gestorAdminList || []).map(function (g) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(g.name) + '</div><div class="sub">' + ns.esc(g.id) + ' · Links ' + ns.esc(g.links) + ' · Rep ' + ns.esc(g.reputationScore) + '</div></div><div class="money">' + ns.formatCUP(g.earnings) + '</div></div><div class="actions"><button class="btn ghost" data-edit-gestor="' + ns.esc(g.id) + '">🛠️ Editar</button></div></div>';
            }).join('') + '</div></div></div><div class="card" style="margin-top:18px"><div class="section-title"><h3>Usuarios RAC</h3><button class="btn primary" data-open-user-new>+ Usuario</button></div><div class="grid two" style="margin-top:12px"><div class="field"><label>Buscar</label><input class="input" id="userSearch" type="text" value="' + ns.esc((state.userFilter || {}).q || '') + '" placeholder="usuario, nombre, dueño o gestor"></div><div class="field"><label>Rol</label><select class="select" data-user-filter="role"><option value="all"' + (userRoleFilter === 'all' ? ' selected' : '') + '>Todos</option><option value="admin"' + (userRoleFilter === 'admin' ? ' selected' : '') + '>admin</option><option value="owner"' + (userRoleFilter === 'owner' ? ' selected' : '') + '>owner</option><option value="gestor"' + (userRoleFilter === 'gestor' ? ' selected' : '') + '>gestor</option></select></div></div><div class="field"><label>Estado</label><select class="select" data-user-filter="status"><option value="all"' + (userStatusFilter === 'all' ? ' selected' : '') + '>Todos</option><option value="active"' + (userStatusFilter === 'active' ? ' selected' : '') + '>active</option><option value="suspended"' + (userStatusFilter === 'suspended' ? ' selected' : '') + '>suspended</option></select></div><div class="list" style="margin-top:12px">' + filteredUsers.map(function (u) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(u.displayName || u.username) + '</div><div class="sub">' + ns.esc(u.username) + ' · Rol ' + ns.esc(u.role) + (u.ownerCode ? ' · ' + ns.esc(u.ownerCode) : '') + (u.gestorId ? ' · ' + ns.esc(u.gestorId) : '') + '</div></div><div>' + ns.badge(u.status) + '</div></div><div class="actions"><button class="btn ghost" data-edit-user="' + ns.esc(u.id) + '">🛠️ Editar</button><button class="btn ghost" data-reset-user-password="' + ns.esc(u.id) + '">🔐 Reset pass</button><button class="btn ghost" data-delete-user="' + ns.esc(u.id) + '">🗑️ Eliminar</button></div></div>';
            }).join('') + '</div><div class="card" style="margin-top:18px"><div class="item-title">Auditoría de accesos y usuarios</div><div class="list" style="margin-top:12px">' + (state.accessAudit || []).map(function (evt) {
                var ctx = evt.context || {};
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(evt.eventType) + '</div><div class="sub">' + ns.esc(evt.message) + '</div></div><div class="sub">' + ns.esc(evt.createdAt) + '</div></div><div class="sub" style="margin-top:8px">' + ns.esc([ctx.username, ctx.role, ctx.user_id, ctx.masked_code].filter(Boolean).join(' · ')) + '</div></div>';
            }).join('') + '</div></div></div>';
        } else if (state.adminTab === 'transactions') {
            body = '<div class="section-title"><h3>Transacciones RAC</h3><div class="actions"><button class="btn ghost" data-open-payment-import>📥 Importar extracto</button><button class="btn ghost" data-open-finance-reconcile>🔗 Conciliar pago</button><button class="btn ghost" data-run-auto-reconcile>⚡ Conciliar lote</button><button class="btn ghost" data-open-billing-charge>🧾 Nuevo cargo</button><button class="btn ghost" data-generate-billing>⚙️ Generar cargos</button></div></div><div class="grid kpis">' + ns.kpi('🏦', 'Pagos importados', String((state.externalPayments || []).length), 'Webhook + CSV', '#ffd700') + ns.kpi('✅', 'Conciliados', String((state.externalPayments || []).filter(function (item) { return item.status === 'matched'; }).length), 'Con referencia válida', '#28a745') + ns.kpi('🕓', 'Pendientes', String((state.externalPayments || []).filter(function (item) { return item.status === 'pending'; }).length), 'Esperando match', '#ff8c00') + ns.kpi('⚠️', 'Sin casar', String((state.externalPayments || []).filter(function (item) { return item.status === 'unmatched'; }).length), 'Revisión manual', '#ef5350') + '</div><div class="list">' + state.leads.map(function (lead) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · Gestor ' + ns.esc(lead.gestorId) + ' · ' + ns.esc(lead.date) + '</div></div><div style="display:flex;gap:16px;flex-wrap:wrap"><div style="text-align:right"><div class="sub">Comision total</div><div class="money">' + ns.formatCUP(lead.commission) + '</div></div><div style="text-align:right"><div class="sub">Plataforma</div><div class="money">' + ns.formatCUP(lead.platformShare || 0) + '</div></div>' + ns.badge(lead.status) + '</div></div><div class="actions"><button class="btn ghost" data-open-flow="' + ns.esc(lead.id) + '">🧾 Ver flujo</button></div></div>';
            }).join('') + '</div><div class="grid two"><div class="card" style="margin-top:18px"><div class="item-title">Recargas pendientes</div><div class="list" style="margin-top:12px">' + ((state.walletTopups || []).filter(function (t) { return t.status === 'pending'; })).map(function (t) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(t.ownerCode + ' · ' + t.ownerName) + '</div><div class="sub">' + ns.esc(t.paymentMethod) + ' · Ref ' + ns.esc(t.referenceCode) + ' · ' + ns.esc(t.createdAt) + '</div></div><div class="money">' + ns.formatCUP(t.amount) + '</div></div><div class="actions"><button class="btn primary" data-topup-review="' + ns.esc(t.id) + '" data-decision="approved">✓ Aprobar</button><button class="btn ghost" data-topup-review="' + ns.esc(t.id) + '" data-decision="rejected">✗ Rechazar</button></div></div>';
            }).join('') + '</div></div><div class="card" style="margin-top:18px"><div class="item-title">Cobros RAC</div><div class="list" style="margin-top:12px">' + ((state.billingCharges || []).map(function (c) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(c.ownerCode + ' · ' + c.chargeType) + '</div><div class="sub">Ref ' + ns.esc(c.referenceCode) + ' · Vence ' + ns.esc(c.dueAt || 'N/D') + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(c.amount) + '</div>' + ns.badge(c.status === 'paid' ? 'active' : 'pending') + '</div></div></div>';
            }).join('')) + '</div></div></div><div class="card" style="margin-top:18px"><div class="item-title">Pagos externos recibidos</div><div class="list" style="margin-top:12px">' + ((state.externalPayments || []).map(function (p) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(p.referenceCode) + '</div><div class="sub">' + ns.esc(p.paymentChannel) + ' · ' + ns.esc(p.sourceType) + ' · ' + ns.esc(p.paidAt || p.createdAt) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(p.amount) + '</div>' + ns.badge(p.status === 'matched' ? 'active' : (p.status === 'unmatched' ? 'no_sale' : 'pending')) + '</div></div></div>';
            }).join('')) + '</div></div><div class="card" style="margin-top:18px"><div class="item-title">Conciliaciones recientes</div><div class="list" style="margin-top:12px">' + ((state.paymentReconciliations || []).map(function (r) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(r.referenceCode) + '</div><div class="sub">' + ns.esc(r.paymentChannel) + ' · ' + ns.esc(r.targetType) + ' · ' + ns.esc(r.createdAt) + '</div></div><div class="money">' + ns.formatCUP(r.amount) + '</div></div></div>';
            }).join('')) + '</div></div>';
        } else {
            body = '<div class="section-title"><h3>Monitor de auditoria</h3></div><div class="list">' + state.alerts.map(function (a) {
                return '<div class="item" style="border-color:' + ns.esc(a.color) + '40;background:' + ns.esc(a.color) + '10"><div class="item-head"><div><div class="item-title">' + ns.esc(a.dueno) + '</div><div class="sub">' + ns.esc(a.metric) + ' · Riesgo ' + ns.esc(a.risk) + '</div></div><div>' + ns.badge(a.type === 'inactive' ? 'pending' : 'no_sale') + '</div></div><div class="sub" style="margin-top:10px">' + (a.type === 'fraud' ? 'El dueño esta bajo vigilancia por baja conversion frente al volumen de leads.' : 'El dueño no tiene saldo suficiente o fue suspendido.') + '</div></div>';
            }).join('') + '</div><div class="grid two"><div class="card"><div class="item-title">Señales avanzadas · Dueños</div><div class="list" style="margin-top:12px">' + (((state.advancedAudit || {}).owners) || []).map(function (o) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(o.ownerCode + ' · ' + o.ownerName) + '</div><div class="sub">Leads ' + ns.esc(o.leads) + ' · No sale ' + ns.esc(o.noSaleCount) + ' · Conv ' + ns.esc(o.conversionRate) + '%</div></div><div>' + ns.badge(o.fraudRisk === 'BAJO' ? 'active' : 'pending') + '</div></div></div>';
            }).join('') + '</div></div><div class="card"><div class="item-title">Señales avanzadas · Gestores</div><div class="list" style="margin-top:12px">' + (((state.advancedAudit || {}).gestores) || []).map(function (g) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(g.id + ' · ' + g.name) + '</div><div class="sub">Leads ' + ns.esc(g.leads) + ' · Sold ' + ns.esc(g.soldCount) + ' · No sale ' + ns.esc(g.noSaleCount) + '</div></div><div class="money">' + ns.esc(g.reputationScore) + '</div></div></div>';
            }).join('') + '</div></div></div><div class="card" style="margin-top:18px"><div class="item-title">Eventos recientes</div><div class="list" style="margin-top:12px">' + state.auditEvents.map(function (evt) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(evt.eventType) + '</div><div class="sub">' + ns.esc(evt.message) + '</div></div><div class="sub">' + ns.esc(evt.createdAt) + '</div></div></div>';
            }).join('') + '</div></div>';
        }
        root.innerHTML = ns.panelHeader('🛡️ Panel de Control · RAC', ns.formatCUP(state.summary.revenue), 'Revenue plataforma') + ns.tabRow('admin', tabs, state.adminTab) + body;
        ns.renderAdminAnalyticsBlocks(root);
        ns.renderAdminSecurityBlocks(root);
    };

    ns.render = function () {
        ns.renderRoleSwitcher();
        ns.renderOwner();
        ns.renderGestor();
        ns.renderAdmin();
        ns.$('heroTotalVolume').textContent = ns.formatCUP(state.summary.volumeTotal);
        ns.$('heroRevenue').textContent = ns.formatCUP(state.summary.revenue);
        ns.$('heroOwners').textContent = state.summary.ownersActive || 0;
        ns.$('heroGestores').textContent = state.summary.gestoresActive || 0;
        ns.$('affBackBtn').classList.toggle('hidden', !state.role);
        ns.updateSyncBadge();
        ns.updateNetBadge();
        var search = ns.$('gestorSearch');
        if (search) search.value = state.gestorFilter.q;
    };
})(window.RAC);
