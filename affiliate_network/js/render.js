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
                + '</div>'
                + '<div class="grid two">'
                + '<div class="card"><div class="item-title">Wallet y visibilidad</div><p>Plan ' + ns.esc(state.owner.subscriptionPlan) + ' · Gestion asistida ' + (state.owner.managedService ? 'activa' : 'no contratada') + '. Si el saldo disponible llega a cero, el catalogo desaparece para los gestores.</p><div class="two-col" style="margin-top:12px">' + ns.stat('Disponible', ns.formatCUP(state.owner.wallet.available), 'Marketplace visible') + ns.stat('Bloqueado', ns.formatCUP(state.owner.wallet.blocked), 'Leads abiertos') + '</div></div>'
                + '<div class="card"><div class="item-title">Asistente de precios inteligente</div>'
                + (state.pricingSuggestions.length ? '<div class="list" style="margin-top:12px">' + state.pricingSuggestions.slice(0, 4).map(function (item) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(item.category) + '</div><div class="sub">' + ns.esc(item.items) + ' producto(s) comparados</div></div><div style="text-align:right"><div class="money">Moda ' + ns.formatCUP(item.modePrice) + '</div><div class="sub">Ponderado ' + ns.formatCUP(item.weightedPrice) + '</div></div></div></div>';
                }).join('') + '</div>' : '<p>No hay suficientes productos para sugerir precios.</p>')
                + '</div></div>'
                + '<div class="card warning"><div>⚠️</div><div><div class="item-title">Proteccion anti-salto</div><div class="sub">Cada contacto abre un lead con garantia bloqueada. La tasa de conversion alimenta la reputacion del dueño y dispara vigilancia cuando se degrada.</div></div></div>'
                + '<div class="section-title"><h3>Leads recientes</h3></div><div class="list">'
                + state.leads.slice(0, 4).map(function (lead) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · ' + ns.esc(lead.date) + ' · Gestor ' + ns.esc(lead.gestorId) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(lead.lockedCommission || lead.commission) + '</div>' + ns.badge(lead.status) + '</div></div></div>';
                }).join('')
                + '</div>';
        } else if (state.ownerTab === 'inventario') {
            body = '<div class="section-title"><h3>Inventario invisible del dueño</h3><button class="btn primary" data-open-product>+ Nuevo producto</button></div><div class="cards">'
                + state.products.map(function (p) {
                    var stats = state.ownerProductStats.find(function (item) { return item.id === p.id; }) || {};
                    return '<div class="card product-card">' + (p.trending ? '<div class="trend">🔥 TREND</div>' : '')
                        + ns.productVisual(p) + '<div class="item-title" style="margin:10px 0 4px">' + ns.esc(p.name) + '</div><div class="sub">' + ns.esc(p.category) + ' · ' + ns.esc(p.brand) + ' · Stock ' + ns.esc(p.stock) + '</div><div style="margin-top:8px;font-size:15px;font-weight:900;color:var(--amber)">' + ns.formatCUP(p.price) + '</div><div class="two-col" style="margin-top:14px"><div><div class="sub">Comision</div><div class="money">' + ns.formatCUP(p.commission) + '</div></div><div><div class="sub">% visible</div><div style="font-weight:800;color:var(--fire)">' + ns.esc(p.commissionPct) + '%</div></div></div><div class="sub" style="margin-top:12px">' + ns.esc((p.description || '').substring(0, 90)) + '</div>' + (p.couponLabel ? '<div class="sub" style="margin-top:8px"><strong>Beneficio:</strong> ' + ns.esc(p.couponLabel) + '</div>' : '') + (Number(p.isFeatured || 0) ? '<div class="sub" style="margin-top:8px"><strong>Patrocinado:</strong> prioridad ' + ns.esc(p.sponsorRank || 0) + '</div>' : '') + '<div class="two-col" style="margin-top:12px"><div><div class="sub">Clics / Leads / Ventas</div><div class="money">' + ns.esc(stats.clicks || 0) + ' / ' + ns.esc(stats.leads || 0) + ' / ' + ns.esc(stats.sales || 0) + '</div></div><div><div class="sub">Conversión</div><div class="money">' + ns.esc(stats.conversionRate || 0) + '%</div></div></div><div class="two-col" style="margin-top:12px"><div><div class="sub">Pago a gestores</div><div class="money">' + ns.formatCUP(stats.gestorPaid || 0) + '</div></div><div><div class="sub">Ingreso plataforma</div><div class="money">' + ns.formatCUP(stats.platformEarned || 0) + '</div></div></div><div class="actions"><button class="btn ghost" data-edit-product="' + ns.esc(p.id) + '">🛠️ Editar</button><button class="btn ghost" data-toggle-product="' + ns.esc(p.id) + '" data-active="' + (p.active ? '0' : '1') + '">' + (p.active ? '⏸️ Desactivar' : '♻️ Reactivar') + '</button></div>' + (!p.active ? '<div class="sub" style="margin-top:10px;color:#8f2f2f">Producto oculto del marketplace</div>' : '') + '</div>';
                }).join('')
                + '</div>';
        } else if (state.ownerTab === 'leads') {
            var leadPage = ns.paginate(state.leads, state.ownerLeadPage, 8);
            body = '<div class="section-title"><h3>Leads trazados</h3></div><div class="list">'
                + leadPage.items.map(function (lead) {
                    var actions = lead.status !== 'sold' && lead.status !== 'no_sale'
                        ? '<div class="actions"><button class="btn primary" data-lead-status="' + ns.esc(lead.id) + '" data-status="sold">✓ Vendido</button><button class="btn ghost" data-lead-status="' + ns.esc(lead.id) + '" data-status="contacted">💬 Contactado</button><button class="btn ghost" data-lead-status="' + ns.esc(lead.id) + '" data-status="negotiating">⏳ Negociando</button><button class="btn ghost" data-lead-status="' + ns.esc(lead.id) + '" data-status="no_sale">✗ No concretado</button></div>' : '';
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · Gestor ' + ns.esc(lead.gestorId) + ' · ' + ns.esc(lead.date) + ' · Cliente ' + ns.esc(lead.client) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(lead.lockedCommission || lead.commission) + '</div>' + ns.badge(lead.status) + '</div></div>' + actions + '</div>';
                }).join('')
                + '</div>' + ns.pager('ownerLead', leadPage.current, leadPage.total);
        } else {
            body = '<div class="grid kpis">'
                + ns.kpi('💳', 'Disponible', ns.formatCUP(state.owner.wallet.available), 'Visible al marketplace', '#ffd700')
                + ns.kpi('🔒', 'Bloqueado', ns.formatCUP(state.owner.wallet.blocked), 'Garantia retenida', '#ff8c00')
                + ns.kpi('🧮', 'Total', ns.formatCUP(state.owner.wallet.total), 'Wallet prepaga', '#ff4500')
                + ns.kpi('🛠️', 'Plan', String(state.owner.subscriptionPlan).toUpperCase(), state.owner.managedService ? 'Con gestion' : 'Autogestionado', '#ffd700')
                + '</div><div class="grid two"><div class="card"><div class="item-title">Conciliacion del ledger</div><div class="list" style="margin-top:12px">'
                + '<div class="item"><div class="item-head"><div><div class="item-title">Disponible calculado</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.calculatedAvailable) + '</div></div></div>'
                + '<div class="item"><div class="item-head"><div><div class="item-title">Bloqueado calculado</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.calculatedBlocked) + '</div></div></div>'
                + '<div class="item"><div class="item-head"><div><div class="item-title">Descuadre disponible</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.availableMismatch) + '</div></div></div>'
                + '<div class="item"><div class="item-head"><div><div class="item-title">Descuadre bloqueado</div></div><div class="money">' + ns.formatCUP(state.walletReconciliation.blockedMismatch) + '</div></div></div>'
                + '</div><div class="sub" style="margin-top:12px">' + (state.walletReconciliation.ok ? 'Ledger conciliado.' : 'Hay diferencias entre saldo calculado y saldo persistido.') + '</div></div>'
                + '<div class="card"><div class="item-title">Estado operativo</div><div class="list" style="margin-top:12px">'
                + '<div class="item"><div class="item-head"><div><div class="item-title">Garantias vencidas liberadas</div></div><div class="money">' + ns.esc(state.summary.expiredHolds || 0) + '</div></div></div>'
                + '<div class="item"><div class="item-head"><div><div class="item-title">Riesgo actual</div></div><div>' + ns.badge(state.owner.fraudRisk === 'BAJO' ? 'active' : 'pending') + '</div></div></div>'
                + '</div></div></div><div class="section-title"><h3>Movimientos de wallet</h3></div><div class="list">'
                + state.walletMovements.map(function (m) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(ns.movementLabel(m.movementType)) + '</div><div class="sub">' + ns.esc(m.note || '') + ' · ' + ns.esc(m.createdAt) + '</div></div><div class="money">' + ns.formatCUP(m.amount) + '</div></div></div>';
                }).join('')
                + '</div>';
        }

        root.innerHTML = ns.panelHeader('🏪 Panel del Dueño · ' + state.owner.name + ' · ' + state.owner.code, ns.formatCUP(state.owner.wallet.available), 'Saldo disponible') + ns.tabRow('owner', tabs, state.ownerTab) + body;
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
            body = '<div class="grid kpis">'
                + ns.kpi('💸', 'Volumen total', ns.formatCUP(state.summary.volumeTotal), 'RAC', '#ffd700')
                + ns.kpi('✨', 'Revenue LAG', ns.formatCUP(state.summary.revenue), 'Plataforma', '#ff8c00')
                + ns.kpi('🏪', 'Dueños activos', String(state.summary.ownersActive), 'Tiendas visibles', '#ff4500')
                + ns.kpi('🤝', 'Gestores activos', String(state.summary.gestoresActive), 'Red comercial', '#ffd700')
                + '</div><div class="grid two"><div class="card"><div class="item-title">Top productos por interes</div><div class="list" style="margin-top:12px">' + topProducts.map(function (p, i) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">#' + (i + 1) + ' ' + ns.esc(p.name) + '</div><div class="sub">' + ns.esc(p.category) + '</div></div><div style="text-align:right"><div class="money">' + ns.esc(p.clicks) + '</div><div class="sub">clics</div></div></div></div>';
                }).join('') + '</div></div><div class="card"><div class="item-title">Top gestores</div><div class="list" style="margin-top:12px">' + topGestores.map(function (g, i) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">#' + (i + 1) + ' ' + ns.esc(g.name) + '</div><div class="sub">⭐ ' + ns.esc(g.rating) + ' · Rep ' + ns.esc(g.reputationScore) + '</div></div><div class="money">' + ns.formatCUP(g.earnings) + '</div></div></div>';
                }).join('') + '</div></div></div><div class="grid two"><div class="card"><div class="item-title">Zonas con mas demanda</div><div class="list" style="margin-top:12px">' + state.marketInsights.zones.map(function (z) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(z.zone) + '</div><div class="sub">Reputacion promedio ' + ns.esc(z.reputation) + '</div></div><div class="money">' + ns.esc(z.owners) + '</div></div></div>';
                }).join('') + '</div></div><div class="card"><div class="item-title">Suscripciones de dueños</div><div class="list" style="margin-top:12px">' + state.marketInsights.plans.map(function (p) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(String(p.plan).toUpperCase()) + '</div></div><div class="money">' + ns.esc(p.total) + '</div></div></div>';
                }).join('') + '</div></div></div><div class="card" style="margin-top:18px"><div class="item-title">Ranking de enlaces RAC</div><div class="list" style="margin-top:12px">' + state.linkRankings.slice(0, 8).map(function (link) {
                    return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(link.product) + '</div><div class="sub">' + ns.esc(link.owner) + ' · Gestor ' + ns.esc(link.gestor) + ' · Ref ' + ns.esc(link.maskedRef) + '</div></div><div style="text-align:right"><div class="money">' + ns.formatCUP(link.gestorEarned || 0) + '</div><div class="sub">Gestor</div></div></div><div class="two-col" style="margin-top:12px"><div><div class="sub">Clics / Leads / Ventas</div><div class="money">' + ns.esc(link.clicks || 0) + ' / ' + ns.esc(link.leads || 0) + ' / ' + ns.esc(link.sold || 0) + '</div></div><div><div class="sub">CTR / Cierre</div><div class="money">' + ns.esc(link.ctr || 0) + '% / ' + ns.esc(link.closeRate || 0) + '%</div></div></div></div>';
                }).join('') + '</div></div>';
        } else if (state.adminTab === 'users') {
            body = '<div class="grid two"><div class="card"><div class="item-title">Dueños registrados</div><div class="list" style="margin-top:12px">' + state.owners.map(function (owner) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(owner.owner_name || owner.ownerName || owner.owner_code) + '</div><div class="sub">Plan ' + ns.esc(owner.subscriptionPlan || 'basic') + ' · Riesgo ' + ns.esc(owner.fraudRisk || 'BAJO') + '</div></div>' + ns.badge(owner.status) + '</div></div>';
            }).join('') + '</div></div><div class="card"><div class="item-title">Gestores</div><div class="list" style="margin-top:12px">' + state.gestores.map(function (g) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(g.name) + '</div><div class="sub">Links ' + ns.esc(g.links) + ' · Rep ' + ns.esc(g.reputationScore) + '</div></div><div class="money">' + ns.formatCUP(g.earnings) + '</div></div></div>';
            }).join('') + '</div></div></div>';
        } else if (state.adminTab === 'transactions') {
            body = '<div class="section-title"><h3>Transacciones RAC</h3></div><div class="list">' + state.leads.map(function (lead) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(lead.product) + '</div><div class="sub">' + ns.esc(lead.traceCode) + ' · Gestor ' + ns.esc(lead.gestorId) + ' · ' + ns.esc(lead.date) + '</div></div><div style="display:flex;gap:16px;flex-wrap:wrap"><div style="text-align:right"><div class="sub">Comision total</div><div class="money">' + ns.formatCUP(lead.commission) + '</div></div><div style="text-align:right"><div class="sub">Plataforma</div><div class="money">' + ns.formatCUP(lead.platformShare || 0) + '</div></div>' + ns.badge(lead.status) + '</div></div></div>';
            }).join('') + '</div>';
        } else {
            body = '<div class="section-title"><h3>Monitor de auditoria</h3></div><div class="list">' + state.alerts.map(function (a) {
                return '<div class="item" style="border-color:' + ns.esc(a.color) + '40;background:' + ns.esc(a.color) + '10"><div class="item-head"><div><div class="item-title">' + ns.esc(a.dueno) + '</div><div class="sub">' + ns.esc(a.metric) + ' · Riesgo ' + ns.esc(a.risk) + '</div></div><div>' + ns.badge(a.type === 'inactive' ? 'pending' : 'no_sale') + '</div></div><div class="sub" style="margin-top:10px">' + (a.type === 'fraud' ? 'El dueño esta bajo vigilancia por baja conversion frente al volumen de leads.' : 'El dueño no tiene saldo suficiente o fue suspendido.') + '</div></div>';
            }).join('') + '</div><div class="card" style="margin-top:18px"><div class="item-title">Eventos recientes</div><div class="list" style="margin-top:12px">' + state.auditEvents.map(function (evt) {
                return '<div class="item"><div class="item-head"><div><div class="item-title">' + ns.esc(evt.eventType) + '</div><div class="sub">' + ns.esc(evt.message) + '</div></div><div class="sub">' + ns.esc(evt.createdAt) + '</div></div></div>';
            }).join('') + '</div></div>';
        }
        root.innerHTML = ns.panelHeader('🛡️ Panel de Control · RAC', ns.formatCUP(state.summary.revenue), 'Revenue plataforma') + ns.tabRow('admin', tabs, state.adminTab) + body;
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
