window.RAC = window.RAC || {};
(function (ns) {
    var state = ns.state;

    ns.fileToWebpDataUrl = function (file) {
        return new Promise(function (resolve, reject) {
            if (!file) {
                resolve('');
                return;
            }
            var reader = new FileReader();
            reader.onerror = function () { reject(new Error('file_read_failed')); };
            reader.onload = function () {
                var img = new Image();
                img.onerror = function () { reject(new Error('image_decode_failed')); };
                img.onload = function () {
                    var maxSide = 1400;
                    var w = img.width;
                    var h = img.height;
                    if (w > h && w > maxSide) {
                        h = Math.round(h * (maxSide / w));
                        w = maxSide;
                    } else if (h >= w && h > maxSide) {
                        w = Math.round(w * (maxSide / h));
                        h = maxSide;
                    }
                    var canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, w, h);
                    resolve(canvas.toDataURL('image/webp', 0.84));
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    };

    ns.apiUrl = function (action) {
        return '/affiliate_network_api.php?action=' + encodeURIComponent(action);
    };

    ns.api = async function (action, method, payload) {
        var headers = {};
        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = state.csrf;
        }
        var res = await fetch(ns.apiUrl(action), {
            method: method || 'GET',
            headers: headers,
            body: method === 'POST' ? JSON.stringify(payload || {}) : undefined,
            credentials: 'same-origin'
        });
        var json = await res.json();
        if (!res.ok || json.status !== 'success') {
            throw new Error(json.msg || 'request_failed');
        }
        return json;
    };

    ns.cacheData = function () {
        localStorage.setItem(state.cacheKey, JSON.stringify({
            owner: state.owner,
            products: state.products,
            leads: state.leads,
            gestores: state.gestores,
            alerts: state.alerts,
            owners: state.owners,
            traceLinks: state.traceLinks,
            ownerProductStats: state.ownerProductStats,
            linkRankings: state.linkRankings,
            pricingSuggestions: state.pricingSuggestions,
            marketInsights: state.marketInsights,
            analytics: state.analytics,
            walletTopups: state.walletTopups,
            billingCharges: state.billingCharges,
            paymentReconciliations: state.paymentReconciliations,
            externalPayments: state.externalPayments,
            ownerAdminList: state.ownerAdminList,
            gestorAdminList: state.gestorAdminList,
            affiliateUsers: state.affiliateUsers,
            userRoleSummary: state.userRoleSummary,
            accessAudit: state.accessAudit,
            subscriptionMetrics: state.subscriptionMetrics,
            sponsoredProducts: state.sponsoredProducts,
            advancedAudit: state.advancedAudit,
            walletMovements: state.walletMovements,
            walletReconciliation: state.walletReconciliation,
            auditEvents: state.auditEvents,
            integrations: state.integrations,
            integrationSettings: state.integrationSettings,
            health: state.health,
            currentLeadFlow: state.currentLeadFlow,
            summary: state.summary
        }));
    };

    ns.loadCache = function () {
        try {
            var raw = localStorage.getItem(state.cacheKey);
            if (!raw) return false;
            Object.assign(state, JSON.parse(raw) || {});
            return true;
        } catch (e) {
            return false;
        }
    };

    ns.loadQueue = function () {
        try {
            state.queue = JSON.parse(localStorage.getItem(state.queueKey) || '[]') || [];
        } catch (e) {
            state.queue = [];
        }
    };

    ns.saveQueue = function () {
        localStorage.setItem(state.queueKey, JSON.stringify(state.queue));
    };

    ns.enqueueMutation = function (item) {
        var now = Date.now();
        var entityId = ((item.payload || {}).id || (item.payload || {}).product_id || (item.payload || {}).reference_code || '');
        var entityKey = item.type + '::' + entityId;
        var wrapped = {
            id: 'Q' + now + Math.random().toString(36).slice(2, 8),
            type: item.type,
            payload: item.payload || {},
            entityKey: entityKey,
            createdAt: new Date().toISOString(),
            retries: 0,
            nextAttemptAt: 0,
            lastError: '',
            status: 'pending'
        };
        var replaced = false;
        state.queue = state.queue.map(function (queued) {
            if (queued.entityKey && queued.entityKey === entityKey) {
                replaced = true;
                wrapped.retries = Number(queued.retries || 0);
                return wrapped;
            }
            return queued;
        });
        if (!replaced) {
            state.queue.push(wrapped);
        }
        ns.saveQueue();
        ns.updateSyncBadge();
    };

    ns.isStandalone = function () {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    };

    ns.updateInstallNotice = function () {
        var box = ns.$('pwaInstallNotice');
        if (box) {
            box.classList.toggle('hidden', ns.isStandalone() || !state.installPrompt);
        }
    };

    ns.installPwa = async function () {
        if (!state.installPrompt) return;
        state.installPrompt.prompt();
        try {
            await state.installPrompt.userChoice;
        } catch (e) {}
        state.installPrompt = null;
        ns.updateInstallNotice();
    };

    ns.updateNetBadge = function () {
        var el = ns.$('netStatus');
        if (!el) return;
        if (navigator.onLine) {
            el.textContent = '● En linea';
            el.className = 'status-online';
        } else {
            el.textContent = '● Offline';
            el.className = 'status-offline';
        }
    };

    ns.updateSyncBadge = function () {
        var el = ns.$('syncStatus');
        if (el) {
            if (state.syncInFlight) {
                el.textContent = 'Sincronizando cambios pendientes...';
            } else if (state.queue.length) {
                var failed = state.queue.filter(function (item) { return item.status === 'failed'; }).length;
                var pending = state.queue.filter(function (item) { return item.status !== 'synced'; }).length;
                el.textContent = pending + ' cambio(s) pendiente(s)' + (failed ? ' · ' + failed + ' con reintento' : '') + (state.syncRetryAt ? ' · próximo ' + state.syncRetryAt : '');
            } else if (state.lastSyncAt) {
                el.textContent = 'Última sync: ' + state.lastSyncAt + (state.swUpdateAvailable ? ' · actualización disponible' : '');
            } else {
                el.textContent = state.swUpdateAvailable ? 'Actualización disponible' : 'Sin cambios pendientes';
            }
        }
    };

    ns.flushQueue = async function () {
        if (state.syncInFlight || !navigator.onLine || !state.queue.length) {
            ns.updateSyncBadge();
            return;
        }
        state.syncInFlight = true;
        state.syncError = '';
        state.syncRetryAt = '';
        ns.updateSyncBadge();
        var now = Date.now();
        var pending = [];
        var deferred = [];
        var nextRetryTs = 0;
        state.queue.forEach(function (item) {
            if ((item.nextAttemptAt || 0) > now) {
                deferred.push(item);
                if (!nextRetryTs || item.nextAttemptAt < nextRetryTs) nextRetryTs = item.nextAttemptAt;
                return;
            }
            pending.push(item);
        });
        state.queue = deferred;
        ns.saveQueue();
        for (var i = 0; i < pending.length; i += 1) {
            var item = pending[i];
            try {
                await ns.api(item.type, 'POST', item.payload);
            } catch (e) {
                item.retries = Number(item.retries || 0) + 1;
                item.status = 'failed';
                item.lastError = e && e.message ? e.message : 'sync_failed';
                item.nextAttemptAt = now + Math.min(600000, Math.pow(2, Math.min(item.retries, 6)) * 15000);
                state.queue.push(item);
                state.syncError = item.lastError;
                if (!nextRetryTs || item.nextAttemptAt < nextRetryTs) nextRetryTs = item.nextAttemptAt;
            }
        }
        ns.saveQueue();
        state.syncInFlight = false;
        state.lastSyncAt = new Date().toLocaleString('es-CU');
        state.syncRetryAt = nextRetryTs ? new Date(nextRetryTs).toLocaleString('es-CU') : '';
        ns.updateSyncBadge();
        await ns.loadBootstrap();
        if (state.syncError) {
            ns.toast('Quedaron cambios pendientes de sincronizar.', 'error');
        }
    };

    ns.loadBootstrap = async function () {
        try {
            var json = await ns.api('bootstrap', 'GET');
            Object.assign(state, json.data || {});
            ns.cacheData();
            ns.render();
        } catch (e) {
            if (ns.loadCache()) {
                ns.render();
                ns.toast('Mostrando datos locales en modo offline.', 'info');
            } else {
                ns.toast('No fue posible cargar datos del modulo.', 'error');
            }
        }
    };

    ns.saveNewProduct = async function () {
        var p = state.ownerNewProduct;
        if (!String(p.name).trim() || !String(p.price).trim() || !String(p.stock).trim() || !String(p.commission).trim()) {
            ns.toast('Completa nombre, precio, stock y comision.', 'error');
            return;
        }
        var payload = {
            id: p.id || '',
            name: p.name.trim(),
            category: p.category || 'Tecnologia',
            price: Number(p.price),
            stock: Number(p.stock),
            commission: Number(p.commission),
            brand: (p.brand || 'Nuevo').trim(),
            coupon_label: (p.couponLabel || '').trim(),
            is_featured: Number(p.isFeatured || 0) > 0 ? 1 : 0,
            sponsor_rank: Number(p.sponsorRank || 0),
            description: p.description || '',
            image: '📦',
            image_data: p.imageData || '',
            remove_image: !!p.removeImage
        };
        var isEdit = !!p.id;
        if (!navigator.onLine) {
            var optimistic = {
                id: payload.id || ('TMP-' + Date.now()),
                name: payload.name,
                category: payload.category,
                price: payload.price,
                stock: payload.stock,
                commission: payload.commission,
                commissionPct: Number(((payload.commission / Math.max(payload.price, 1)) * 100).toFixed(1)),
                image: '📦',
                imageUrl: payload.remove_image ? '' : (p.imagePreview || ''),
                imageWebpUrl: payload.remove_image ? '' : (p.imagePreview || ''),
                imageThumbUrl: payload.remove_image ? '' : (p.imagePreview || ''),
                hasImage: payload.remove_image ? false : !!p.imagePreview,
                brand: payload.brand || 'Nuevo',
                couponLabel: payload.coupon_label || '',
                isFeatured: Number(payload.is_featured || 0) > 0 ? 1 : 0,
                sponsorRank: payload.sponsor_rank || 0,
                description: payload.description,
                clicks: 0,
                leads: 0,
                sales: 0,
                trending: 0,
                active: isEdit ? Number((state.products.find(function (item) { return item.id === payload.id; }) || {}).active || 0) : 1
            };
            if (isEdit) {
                state.products = state.products.map(function (item) {
                    return item.id === payload.id ? Object.assign({}, item, optimistic) : item;
                });
            } else {
                state.products.unshift(optimistic);
            }
            ns.enqueueMutation({ type: isEdit ? 'product_update' : 'product_create', payload: payload });
            ns.cacheData();
            ns.closeModal('productModalWrap');
            state.ownerNewProduct = { id: '', name: '', category: 'Tecnologia', price: '', stock: '', commission: '', brand: 'Nuevo', couponLabel: '', isFeatured: false, sponsorRank: 0, description: '', imageData: '', imagePreview: '', hasImage: false, removeImage: false };
            ns.renderOwner();
            ns.toast(isEdit ? 'Cambios del producto guardados offline.' : 'Producto guardado offline. Se sincronizara al volver internet.', 'info');
            return;
        }
        try {
            await ns.api(isEdit ? 'product_update' : 'product_create', 'POST', payload);
            state.ownerNewProduct = { id: '', name: '', category: 'Tecnologia', price: '', stock: '', commission: '', brand: 'Nuevo', couponLabel: '', isFeatured: false, sponsorRank: 0, description: '', imageData: '', imagePreview: '', hasImage: false, removeImage: false };
            ns.closeModal('productModalWrap');
            await ns.loadBootstrap();
            ns.toast(isEdit ? 'Producto actualizado.' : 'Producto publicado en el catalogo.', 'success');
        } catch (e) {
            ns.toast(isEdit ? 'No fue posible actualizar el producto.' : 'No fue posible publicar el producto.', 'error');
        }
    };

    ns.toggleProductActive = async function (id, active) {
        var payload = { id: id, active: active ? 1 : 0 };
        if (!navigator.onLine) {
            state.products = state.products.map(function (item) {
                return item.id === id ? Object.assign({}, item, { active: payload.active }) : item;
            });
            ns.enqueueMutation({ type: 'product_toggle_active', payload: payload });
            ns.cacheData();
            ns.renderOwner();
            ns.toast(payload.active ? 'Producto reactivado offline.' : 'Producto desactivado offline.', 'info');
            return;
        }
        try {
            await ns.api('product_toggle_active', 'POST', payload);
            await ns.loadBootstrap();
            ns.toast(payload.active ? 'Producto reactivado.' : 'Producto desactivado.', 'success');
        } catch (e) {
            ns.toast('No fue posible cambiar el estado del producto.', 'error');
        }
    };

    ns.saveIntegrationSettings = async function (payload) {
        try {
            await ns.api('integration_settings_update', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('integrationModalWrap');
            ns.toast('Integraciones actualizadas.', 'success');
        } catch (e) {
            ns.toast('No fue posible guardar las integraciones.', 'error');
        }
    };
    ns.saveOwner = async function (payload) {
        try {
            await ns.api('owner_upsert', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('entityModalWrap');
            ns.toast('Dueño guardado.', 'success');
        } catch (e) {
            ns.toast('No fue posible guardar el dueño.', 'error');
        }
    };
    ns.saveUser = async function (payload) {
        try {
            await ns.api('user_upsert', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('entityModalWrap');
            ns.toast('Usuario RAC guardado.', 'success');
        } catch (e) {
            ns.toast('No fue posible guardar el usuario.', 'error');
        }
    };
    ns.saveGestor = async function (payload) {
        try {
            await ns.api('gestor_upsert', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('entityModalWrap');
            ns.toast('Gestor guardado.', 'success');
        } catch (e) {
            ns.toast('No fue posible guardar el gestor.', 'error');
        }
    };
    ns.requestWalletTopup = async function (payload) {
        try {
            await ns.api('wallet_topup_request', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('walletModalWrap');
            ns.toast('Solicitud de recarga enviada.', 'success');
        } catch (e) {
            ns.toast('No fue posible registrar la recarga.', 'error');
        }
    };
    ns.reviewWalletTopup = async function (id, decision) {
        try {
            await ns.api('wallet_topup_review', 'POST', { id: id, decision: decision });
            await ns.loadBootstrap();
            ns.toast(decision === 'approved' ? 'Recarga aprobada.' : 'Recarga rechazada.', 'success');
        } catch (e) {
            ns.toast('No fue posible revisar la recarga.', 'error');
        }
    };
    ns.createBillingCharge = async function (payload) {
        try {
            await ns.api('billing_charge_create', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('walletModalWrap');
            ns.toast('Cargo financiero creado.', 'success');
        } catch (e) {
            ns.toast('No fue posible crear el cargo.', 'error');
        }
    };
    ns.reconcilePayment = async function (payload) {
        try {
            await ns.api('payment_reconcile', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('walletModalWrap');
            ns.toast('Pago conciliado por referencia.', 'success');
        } catch (e) {
            ns.toast('No se encontró match para la referencia.', 'error');
        }
    };
    ns.generateBilling = async function () {
        try {
            await ns.api('billing_generate', 'POST', {});
            await ns.loadBootstrap();
            ns.toast('Cargos operativos generados.', 'success');
        } catch (e) {
            ns.toast('No fue posible generar cargos.', 'error');
        }
    };
    ns.importPaymentExtract = async function (payload) {
        try {
            await ns.api('payment_extract_import', 'POST', payload);
            await ns.loadBootstrap();
            ns.closeModal('walletModalWrap');
            ns.toast('Extracto importado.', 'success');
        } catch (e) {
            ns.toast('No fue posible importar el extracto.', 'error');
        }
    };
    ns.autoReconcilePayments = async function () {
        try {
            await ns.api('payment_auto_reconcile', 'POST', {});
            await ns.loadBootstrap();
            ns.toast('Conciliación por lote ejecutada.', 'success');
        } catch (e) {
            ns.toast('No fue posible ejecutar la conciliación por lote.', 'error');
        }
    };
    ns.resetUserPassword = async function (id, password) {
        try {
            await ns.api('user_password_reset', 'POST', { id: id, password: password });
            ns.closeModal('authModalWrap');
            ns.toast('Contraseña reseteada.', 'success');
        } catch (e) {
            ns.toast('No fue posible resetear la contraseña.', 'error');
        }
    };
    ns.deleteUser = async function (id) {
        try {
            await ns.api('user_delete', 'POST', { id: id });
            await ns.loadBootstrap();
            ns.toast('Usuario eliminado.', 'success');
        } catch (e) {
            ns.toast('No fue posible eliminar el usuario.', 'error');
        }
    };
    ns.changeOwnPassword = async function (payload) {
        try {
            await ns.api('user_change_password', 'POST', payload);
            ns.closeModal('authModalWrap');
            ns.toast('Contraseña actualizada.', 'success');
        } catch (e) {
            ns.toast('No fue posible cambiar la contraseña.', 'error');
        }
    };

    ns.loadLeadFinancialFlow = async function (leadId) {
        try {
            var json = await ns.api('lead_financial_flow&id=' + encodeURIComponent(leadId), 'GET');
            state.currentLeadFlow = json.data || null;
            ns.openLeadFlowModal();
        } catch (e) {
            ns.toast('No fue posible cargar el flujo financiero del lead.', 'error');
        }
    };

    ns.updateLeadStatus = async function (id, status) {
        var payload = { id: id, status: status };
        if (!navigator.onLine) {
            state.leads = state.leads.map(function (lead) {
                return lead.id === id ? Object.assign({}, lead, { status: status }) : lead;
            });
            ns.enqueueMutation({ type: 'lead_update_status', payload: payload });
            ns.cacheData();
            ns.render();
            ns.toast('Estado del lead guardado offline.', 'info');
            return;
        }
        try {
            await ns.api('lead_update_status', 'POST', payload);
            await ns.loadBootstrap();
            ns.toast('Lead actualizado.', 'success');
        } catch (e) {
            ns.toast('No fue posible actualizar el lead.', 'error');
        }
    };

    ns.generateLink = async function (productId) {
        var product = state.products.find(function (item) { return item.id === productId; });
        if (!product) return;
        if (!navigator.onLine) {
            ns.toast('La creacion segura del enlace requiere conexion.', 'error');
            return;
        }
        try {
            var json = await ns.api('trace_link_create', 'POST', { product_id: productId, gestor_id: 'G001' });
            var row = json.row || {};
            var waShare = 'https://wa.me/?text=' + encodeURIComponent((product.name || 'Producto RAC') + ' ' + (row.link || ''));
            ns.$('linkModalWrap').innerHTML = '<div class="modal active"><header><h3>🔗 Enlace del Gestor generado</h3><button class="close" data-close-modal="linkModalWrap">×</button></header><div style="text-align:center;margin-bottom:18px"><div style="font-size:50px">' + ns.esc(product.image) + '</div><div class="item-title" style="margin-top:8px">' + ns.esc(product.name) + '</div></div><div class="card" style="background:rgba(255,140,0,.08);border-color:rgba(255,140,0,.25);margin-bottom:14px"><div class="sub">Tu enlace unico de traza</div><div class="code" style="margin-top:8px">' + ns.esc(row.link || '') + '</div><div class="sub" style="margin-top:8px">Ref ' + ns.esc(row.masked_ref || '') + '</div></div><div class="two-col" style="margin-bottom:14px"><div class="card" style="text-align:center"><div class="sub">Tu comision (80%)</div><div class="money" style="font-size:20px">' + ns.formatCUP(Number(product.commission || 0) * 0.8) + '</div></div><div class="card" style="text-align:center"><div class="sub">Precio al cliente</div><div style="font-size:20px;font-weight:900">' + ns.formatCUP(product.price) + '</div></div></div><div class="footer-actions"><button class="btn primary" style="flex:1" data-copy-link="' + ns.esc(row.link || '') + '">📋 Copiar enlace</button><a class="btn ghost" style="flex:1;color:#25d366;border-color:rgba(37,211,102,.25);text-align:center" href="' + ns.esc(waShare) + '" target="_blank" rel="noopener">💬 Compartir</a></div></div>';
            ns.$('linkModalWrap').classList.add('active');
            await ns.loadBootstrap();
        } catch (e) {
            ns.toast(e.message || 'No fue posible generar el enlace.', 'error');
        }
    };

    ns.showSwUpdateNotice = function () {
        var box = document.getElementById('racSwUpdateNotice');
        if (!state.swUpdateAvailable) {
            if (box) box.remove();
            return;
        }
        if (!box) {
            box = document.createElement('div');
            box.id = 'racSwUpdateNotice';
            box.style.position = 'fixed';
            box.style.left = '16px';
            box.style.right = '16px';
            box.style.bottom = '16px';
            box.style.zIndex = '9999';
            box.style.background = '#111';
            box.style.color = '#fff';
            box.style.border = '1px solid rgba(255,215,0,.35)';
            box.style.borderRadius = '14px';
            box.style.padding = '14px 16px';
            box.style.boxShadow = '0 10px 28px rgba(0,0,0,.35)';
            box.innerHTML = '<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap"><div><strong>Actualización RAC disponible</strong><div style="font-size:12px;opacity:.8;margin-top:4px">Recarga para usar la última versión offline.</div></div><button id="racReloadForUpdate" style="background:#ffd700;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer">Actualizar</button></div>';
            document.body.appendChild(box);
            box.querySelector('#racReloadForUpdate').addEventListener('click', function () {
                window.location.reload();
            });
        }
    };
})(window.RAC);
