window.RAC = window.RAC || {};
(function (ns) {
    ns.state = {
        role: 'dueno',
        ownerTab: 'dashboard',
        gestorTab: 'marketplace',
        adminTab: 'dashboard',
        gestorFilter: { category: 'Todos', sort: 'trending', q: '', page: 1 },
        ownerLeadPage: 1,
        gestorLinksPage: 1,
        ownerNewProduct: { id: '', name: '', category: 'Tecnologia', price: '', stock: '', commission: '', brand: 'Nuevo', couponLabel: '', description: '', imageData: '', imagePreview: '', hasImage: false, removeImage: false },
        owner: {
            code: 'D-0042',
            name: 'ElectroHavana',
            wallet: { available: 0, blocked: 0, total: 0 },
            reputationScore: 80,
            fraudRisk: 'BAJO',
            conversionRate: 0,
            subscriptionPlan: 'basic',
            managedService: false
        },
        products: [],
        leads: [],
        gestores: [],
        alerts: [],
        owners: [],
        traceLinks: [],
        pricingSuggestions: [],
        marketInsights: { zones: [], categories: [], plans: [] },
        walletMovements: [],
        walletReconciliation: { calculatedAvailable: 0, calculatedBlocked: 0, actualAvailable: 0, actualBlocked: 0, availableMismatch: 0, blockedMismatch: 0, ok: true },
        auditEvents: [],
        summary: { volumeTotal: 0, revenue: 0, ownersActive: 0, gestoresActive: 0, leadsToday: 0, salesToday: 0 },
        queue: [],
        cacheKey: 'rac_affiliate_cache_v4',
        queueKey: 'rac_affiliate_queue_v4',
        installPrompt: null,
        csrf: (document.querySelector('meta[name="rac-csrf"]') || {}).content || ''
    };

    ns.$ = function (id) {
        return document.getElementById(id);
    };

    ns.esc = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    };

    ns.formatCUP = function (n) {
        return Number(n || 0).toLocaleString('es-CU') + ' CUP';
    };

    ns.fmtPct = function (n) {
        return Number(n || 0).toLocaleString('es-CU', { maximumFractionDigits: 1 }) + '%';
    };

    ns.badge = function (status) {
        var map = {
            sold: 'Vendido',
            pending: 'Pendiente',
            no_sale: 'No concretado',
            active: 'Activo',
            suspended: 'Suspendido',
            new: 'Nuevo',
            contacted: 'Contactado',
            negotiating: 'Negociando',
            fraud_suspected: 'Fraude'
        };
        var css = { new: 'pending', contacted: 'pending', negotiating: 'pending', fraud_suspected: 'no_sale' };
        return '<span class="badge ' + ns.esc(css[status] || status) + '">' + ns.esc(map[status] || status) + '</span>';
    };

    ns.movementLabel = function (type) {
        var map = {
            seed_available: 'Saldo inicial',
            seed_blocked: 'Garantia inicial',
            lead_hold: 'Bloqueo por lead',
            release_hold: 'Liberacion de garantia',
            payout_gestor: 'Pago al gestor',
            platform_revenue: 'Ingreso plataforma'
        };
        return map[type] || type;
    };

    ns.toast = function (msg, type) {
        var box = ns.$('toast');
        if (!box) return;
        box.textContent = msg;
        box.className = 'toast ' + (type || 'info');
        setTimeout(function () {
            box.className = 'toast hidden';
        }, 3200);
    };

    ns.stat = function (label, val, sub) {
        return '<div class="stat"><div class="k">' + ns.esc(label) + '</div><div class="v">' + ns.esc(val) + '</div><div class="s">' + ns.esc(sub || '') + '</div></div>';
    };

    ns.panelHeader = function (title, balance, balanceLabel) {
        return '<div class="section-title"><div><h3>' + ns.esc(title) + '</h3><div class="sub">Riesgo ' + ns.esc(ns.state.owner.fraudRisk) + ' · Reputacion ' + ns.esc(ns.state.owner.reputationScore) + '</div></div><div style="text-align:right"><div class="sub">' + ns.esc(balanceLabel) + '</div><div class="money">' + ns.esc(balance) + '</div></div></div>';
    };

    ns.kpi = function (icon, label, value, sub, color) {
        return '<div class="card mini"><div class="icon" style="background:' + color + '20">' + icon + '</div><div class="meta"><div class="l">' + ns.esc(label) + '</div><div class="n" style="color:' + color + '">' + ns.esc(value) + '</div><div class="s">' + ns.esc(sub || '') + '</div></div></div>';
    };

    ns.tabRow = function (role, tabs, active) {
        return '<div class="tabs">' + tabs.map(function (tab) {
            return '<button class="' + (active === tab[0] ? 'active' : '') + '" data-tab-role="' + role + '" data-tab-id="' + tab[0] + '">' + tab[1] + '</button>';
        }).join('') + '</div>';
    };

    ns.paginate = function (list, page, size) {
        var total = Math.max(1, Math.ceil(list.length / size));
        var current = Math.min(Math.max(page, 1), total);
        return { items: list.slice((current - 1) * size, current * size), current: current, total: total };
    };

    ns.pager = function (kind, current, total) {
        if (total <= 1) return '';
        return '<div class="actions pager">'
            + '<button class="btn ghost" data-page-kind="' + kind + '" data-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>← Anterior</button>'
            + '<div class="sub">Pagina ' + current + ' / ' + total + '</div>'
            + '<button class="btn ghost" data-page-kind="' + kind + '" data-page="' + (current + 1) + '"' + (current >= total ? ' disabled' : '') + '>Siguiente →</button>'
            + '</div>';
    };
})(window.RAC);
