<?php
// ARCHIVO: menu_master.php
// Menú Flotante v3.0 - FEATURE: Categorías Colapsables & Expand All

// 1. Incluir herramientas (Calculadora)
if (file_exists(__DIR__ . '/tools_unit_converter.php')) {
    include_once __DIR__ . '/tools_unit_converter.php';
}

// --- DEFINICIÓN DE ENLACES ---
$menuCategories = [
    // --- NUEVO: CHAT DE SOPORTE ---
    "💬 Atención al Cliente" => [
        ["icon" => "fa-comments", "name" => "Chat en Vivo", "url" => "#", "onclick" => "openAdminChat()", "id" => "pwChatLink"],
        ["icon" => "fa-sync", "name" => "SYNC GUI", "url" => "sync_panel.php"],

    ],
    "🛒 POS & Venta" => [
        ["icon" => "fa-desktop", "name" => "Terminal POS", "url" => "pos.php"],
        ["icon" => "fa-store", "name" => "Tienda Online", "url" => "shop.php"],
        ["icon" => "fa-calendar-check", "name" => "Reservas", "url" => "reservas.php"],
        ["icon" => "fa-utensils", "name" => "Pantalla Cocina", "url" => "cocina.php"],
        ["icon" => "fa-user-plus", "name" => "Clientes CRM", "url" => "crm_clients.php"],
        
        
    ],
    "✨ Compras" => [
        ["icon" => "fa-truck-loading", "name" => "Entrada / Compra", "url" => "pos_purchases.php"],
        ["icon" => "fa-exchange-alt", "name" => "Transferencias", "url" => "branch_transfers.php"],
        ["icon" => "fa-trash-alt", "name" => "Mermas", "url" => "pos_shrinkage.php"],

    ],
    "📦 Inventario" => [
        ["icon" => "fa-boxes", "name" => "Catálogo & Stock", "url" => "products_table.php"],
        ["icon" => "fa-plus-square", "name" => "Nuevo Producto", "url" => "pos_newprod.php"],
        ["icon" => "fa-file-import", "name" => "Importar Excel", "url" => "pos_import.php"],
        ["icon" => "fa-file-export", "name" => "Exportar Datos", "url" => "pos_export.php"],
        ["icon" => "fa-pencil-square", "name" => "Editor WEB", "url" => "web_manager.php"],
        ["icon" => "fa-cog", "name" => "Produccion", "url" => "pos_production.php"],        
        
    ],
    "💰 Finanzas" => [
        ["icon" => "fa-chart-pie", "name" => "BI & Finanzas", "url" => "profit_analysis.php"],
        ["icon" => "fa-money-bill-wave", "name" => "Flujo de Caja", "url" => "cash_flow.php"],
        ["icon" => "fa-file-invoice-dollar", "name" => "Gastos", "url" => "pos_expenses.php"],
        ["icon" => "fa-file-invoice", "name" => "Facturas", "url" => "invoices.php"],
        ["icon" => "fa-money-bill-wave", "name" => "Contabilidad", "url" => "pos_accounting.php"],
        ["icon" => "fa-coins", "name" => "Libro Diario", "url" => "accounting_journal.php"],

    ],
    "📊 Reportes" => [
        ["icon" => "fa-file-invoice-dollar", "name" => "Cierre de Negocio", "url" => "business_closure_report.php"],
        ["icon" => "fa-chart-line", "name" => "Dashboard", "url" => "dashboard.php"],
        ["icon" => "fa-history", "name" => "Historial Ventas", "url" => "sales_history.php"],
        ["icon" => "fa-balance-scale", "name" => "Reporte IPV (Kardex)", "url" => "reporte_ipv.php"],
        ["icon" => "fa-cash-register", "name" => "Cortes de Caja", "url" => "reportes_caja.php"],
        ["icon" => "fa-chart-pie", "name" => "Análisis Ganancia", "url" => "profit_analysis.php"],
        ["icon" => "fa-chart-bar", "name" => "Análisis Diario", "url" => "profit.php"],
    ],
    "⚙️ Administración" => [
        ["icon" => "fa-cogs", "name" => "Configuración", "url" => "pos_config.php"],
        ["icon" => "fa-tools", "name" => "Mantenimiento BD", "url" => "pos_admin.php"],
        ["icon" => "fa-heartbeat", "name" => "Estado Salud", "url" => "pos_health.php"],
        ["icon" => "fa-sign-out-alt", "name" => "Cerrar Sesión", "url" => "logout.php"],
    ],
    "🧮 Herramientas" => [
        ["icon" => "fa-calculator", "name" => "Convertir Unidades", "url" => "#", "onclick" => "openUnitConverter()"],
        ["icon" => "fa-tint", "name" => "Imagen filler", "url" => "image_filler.php"],
        ["icon" => "fa-file-image", "name" => "Imagen Google", "url" => "image_hunter.php"],
    ],
    "🎨 Temas / Skins" => [
        ["icon" => "fa-sun", "name" => "Claro (Original)", "url" => "#", "onclick" => "setTheme('light')"],
        ["icon" => "fa-moon", "name" => "Oscuro", "url" => "#", "onclick" => "setTheme('dark')"],
        ["icon" => "fa-briefcase", "name" => "BizLand Pro", "url" => "#", "onclick" => "setTheme('bizland')"],
        ["icon" => "fa-tint", "name" => "Azul Ocean", "url" => "#", "onclick" => "setTheme('blue')"],
        ["icon" => "fa-gem", "name" => "Modern Violet", "url" => "#", "onclick" => "setTheme('modern')"],
        ["icon" => "fa-industry", "name" => "Scada (Industrial)", "url" => "#", "onclick" => "setTheme('scada')"],
        ["icon" => "fa-leaf", "name" => "Flatly (Turquesa)", "url" => "#", "onclick" => "setTheme('flatly')"],
        ["icon" => "fa-mask", "name" => "Darkly (Elegante)", "url" => "#", "onclick" => "setTheme('darkly')"],
        ["icon" => "fa-robot", "name" => "Cyborg (Retro)", "url" => "#", "onclick" => "setTheme('cyborg')"],
    ]
];
?>

<style>
    /* Estilos del Menú Flotante */
    @media print { #palweb-float-nav { display: none !important; } }

    #palweb-float-nav {
        position: fixed; bottom: 20px; right: 20px; z-index: 9999;
        font-family: 'Segoe UI', system-ui, sans-serif;
    }

    .pw-float-btn {
        width: 40px; height: 40px;
        background: var(--primary-color, #0d6efd);
        color: white; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        cursor: grab; transition: transform 0.2s; border: 2px solid white;
        position: relative;
    }
    .pw-float-btn:active { cursor: grabbing; transform: scale(0.95); }

    .pw-menu-content {
        position: absolute; bottom: 75px; right: 0;
        width: 260px;
        background: var(--bg-card, #ffffff);
        border-radius: 12px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.25);
        padding: 10px;
        display: none; flex-direction: column;
        max-height: 80vh; overflow-y: auto;
        border: 1px solid var(--border-color, #e0e0e0);
    }
    .pw-menu-content.active { display: flex; animation: pw-pop-in 0.2s ease-out; }

    @keyframes pw-pop-in {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Modificación Categoría Colapsable */
    .pw-cat-container { border-bottom: 1px solid var(--border-color, #f0f0f0); }

    .pw-cat-title {
        font-size: 11px; text-transform: uppercase; color: var(--text-muted, #6c757d);
        font-weight: 800; padding: 10px 10px;
        cursor: pointer; display: flex; justify-content: space-between; align-items: center;
        transition: background 0.2s;
        user-select: none;
    }
    .pw-cat-title:hover { background: var(--border-color, #f8f9fa); }
    .pw-cat-title i.chevron { transition: transform 0.3s; font-size: 9px; }
    
    .pw-cat-content { display: none; padding-bottom: 5px; }
    .pw-cat-container.open .pw-cat-content { display: block; }
    .pw-cat-container.open i.chevron { transform: rotate(180deg); }

    .pw-link {
        display: flex; align-items: center; padding: 8px 12px;
        color: var(--text-main, #333); text-decoration: none !important;
        border-radius: 6px; font-size: 14px; cursor: pointer;
        position: relative;
    }
    .pw-link:hover {
        background-color: var(--border-color, #f1f5f9);
        color: var(--primary-color, #0d6efd);
    }
    .pw-link i { width: 25px; text-align: center; margin-right: 8px; opacity: 0.8; }

    /* Botón Expandir Todo */
    .pw-expand-all-btn {
        font-size: 10px; border: none; background: none; 
        color: var(--primary-color, #0d6efd); cursor: pointer;
        padding: 2px 8px; text-decoration: underline; opacity: 0.8;
    }
    .pw-expand-all-btn:hover { opacity: 1; }

    /* ESTILOS DE NOTIFICACIÓN Y CHAT */
    @keyframes blink-urgent {
        0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); transform: scale(1); }
        50% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); transform: scale(1.1); }
        100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); transform: scale(1); }
    }

    .pw-btn-alert {
        animation: blink-urgent 1.5s infinite !important;
        background-color: #dc3545 !important;
        border-color: #dc3545 !important;
    }

    /* Badges Rojos */
    .pw-badge-float {
        position: absolute; top: -5px; right: -5px;
        background: #dc3545; color: white;
        width: 18px; height: 18px; border-radius: 50%;
        font-size: 10px; font-weight: bold;
        display: none; align-items: center; justify-content: center;
        border: 2px solid white;
    }
    .pw-badge-inline {
        background: #dc3545; color: white;
        padding: 2px 6px; border-radius: 10px;
        font-size: 10px; font-weight: bold;
        margin-left: auto; display: none;
    }

    /* MODAL DE CHAT ADMIN (Vanilla CSS) */
    .admin-chat-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 10000;
        display: none; justify-content: center; align-items: center;
    }
    .admin-chat-box {
        width: 90%; max-width: 900px; height: 80vh;
        background: white; border-radius: 8px;
        display: flex; overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .chat-list-p { width: 30%; background: #f8f9fa; border-right: 1px solid #ddd; display: flex; flex-direction: column; }
    .chat-view-p { width: 70%; display: flex; flex-direction: column; background: #fff; }
    
    .cl-head { padding: 15px; background: #212529; color: white; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
    .cl-body { flex: 1; overflow-y: auto; }
    .cl-item { padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; position: relative; }
    .cl-item:hover, .cl-item.active { background: #e9ecef; }
    .cl-badge { position: absolute; top: 10px; right: 10px; background: #dc3545; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; }
    
    .cv-head { padding: 10px 15px; border-bottom: 1px solid #eee; background: #f8f9fa; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
    .cv-main { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background: #fff; }
    .cv-foot { padding: 15px; border-top: 1px solid #eee; display: flex; gap: 10px; background: #f8f9fa; }
    
    .chat-bubble { max-width: 75%; padding: 8px 12px; border-radius: 12px; font-size: 14px; word-wrap: break-word; }
    .cb-client { align-self: flex-start; background: #f1f0f0; color: #333; }
    .cb-admin { align-self: flex-end; background: #0d6efd; color: white; }
    
    .btn-close-custom { background: none; border: none; font-size: 1.2rem; cursor: pointer; line-height: 1; color: inherit; opacity: 0.7; }
    .btn-close-custom:hover { opacity: 1; }
</style>

<div id="palweb-float-nav">
    <div class="pw-menu-content" id="pwMenuContent">
        <div style="text-align:center; padding-bottom:5px; border-bottom:1px solid var(--border-color); margin-bottom:5px;">
            <strong style="color:var(--primary-color);">PalWeb POS</strong> <small class="text-muted">v3.0</small>
            <br>
            <button class="pw-expand-all-btn" onclick="toggleAllCategories(true)">Abrir todo</button>
            <button class="pw-expand-all-btn" onclick="toggleAllCategories(false)">Cerrar todo</button>
        </div>
        
        <?php foreach($menuCategories as $catName => $links): ?>
            <div class="pw-cat-container">
                <div class="pw-cat-title" onclick="toggleCategory(this)">
                    <span><?php echo htmlspecialchars($catName); ?></span>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="pw-cat-content">
                    <?php foreach($links as $link): 
                        $onclick = isset($link['onclick']) ? 'onclick="'.$link['onclick'].'"' : '';
                        $idAttr = isset($link['id']) ? 'id="'.$link['id'].'"' : '';
                    ?>
                        <a href="<?php echo $link['url']; ?>" class="pw-link" <?php echo $onclick; ?> <?php echo $idAttr; ?>>
                            <i class="fas <?php echo $link['icon']; ?>"></i> 
                            <?php echo htmlspecialchars($link['name']); ?>
                            <?php if(isset($link['id']) && $link['id'] === 'pwChatLink'): ?>
                                <span class="pw-badge-inline" id="pwChatBadgeInline">0</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="pw-float-btn" id="pwFloatBtn">
        <i class="fas fa-bars" id="pwMainIcon"></i>
        <span class="pw-badge-float" id="pwChatBadgeFloat">0</span>
    </div>
</div>

<div class="admin-chat-overlay" id="adminChatModal">
    <div class="admin-chat-box">
        <div class="chat-list-p">
            <div class="cl-head">
                <span><i class="fas fa-comments me-2"></i> Bandeja</span>
                <button type="button" class="btn-close-custom" style="color:white" onclick="loadChatList()"><i class="fas fa-sync"></i></button>
            </div>
            <div class="cl-body" id="chatListItems">
                </div>
        </div>
        <div class="chat-view-p">
            <div class="cv-head">
                <span id="chatUserTitle">Selecciona un chat</span>
                <button type="button" class="btn-close-custom" onclick="closeAdminChat()">&times;</button>
            </div>
            <div class="cv-main" id="chatMessages">
                <div class="text-center text-muted mt-5"><i class="fas fa-inbox fa-3x"></i></div>
            </div>
            <div class="cv-foot">
                <input type="text" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;" id="chatInput" placeholder="Escribe..." disabled onkeypress="if(event.key==='Enter') sendAdminMsg()">
                <button style="padding:8px 15px; background:#0d6efd; color:white; border:none; border-radius:4px; cursor:pointer;" id="chatSendBtn" disabled onclick="sendAdminMsg()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
</div>

<script>
// --- CONFIGURACIÓN DE CHAT (RUTA PLANA) ---
const CHAT_API = 'chat_api.php'; 
let currentChatUUID = null;
let chatPollInterval = null;

// --- LÓGICA DE CATEGORÍAS COLAPSABLES ---
function toggleCategory(element) {
    const container = element.parentElement;
    container.classList.toggle('open');
}

function toggleAllCategories(expand) {
    const containers = document.querySelectorAll('.pw-cat-container');
    containers.forEach(c => {
        if(expand) c.classList.add('open');
        else c.classList.remove('open');
    });
}

// 1. FUNCIÓN CALCULADORA
function openUnitConverter() {
    // Cerrar el menú flotante
    document.getElementById('pwMenuContent').classList.remove('active');
    document.getElementById('pwMainIcon').className = 'fas fa-bars';
    
    const modalEl = document.getElementById('unitConverterModal');
    if(modalEl) {
        if (typeof bootstrap !== 'undefined') {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        } else {
            alert("Herramienta requiere Bootstrap cargado.");
        }
    } else {
        alert("Herramienta no disponible.");
    }
}

// 2. LÓGICA DE CHAT (Polling & UI)
function checkUnread() {
    fetch(CHAT_API + '?action=check_unread_global')
        .then(r => r.json())
        .then(data => {
            const count = parseInt(data.total_unread || 0);
            const btn = document.getElementById('pwFloatBtn');
            const badgeFloat = document.getElementById('pwChatBadgeFloat');
            const badgeInline = document.getElementById('pwChatBadgeInline');
            
            if (count > 0) {
                // Activar alertas
                btn.classList.add('pw-btn-alert');
                if(badgeFloat) { badgeFloat.innerText = count; badgeFloat.style.display = 'flex'; }
                if(badgeInline) { badgeInline.innerText = count; badgeInline.style.display = 'inline-block'; }
            } else {
                // Desactivar alertas
                btn.classList.remove('pw-btn-alert');
                if(badgeFloat) badgeFloat.style.display = 'none';
                if(badgeInline) badgeInline.style.display = 'none';
            }
        })
        .catch(e => {}); // Silencio
}

// Polling cada 5s
setInterval(checkUnread, 5000);
checkUnread(); // Check inicial

window.openAdminChat = function() {
    document.getElementById('pwMenuContent').classList.remove('active');
    document.getElementById('pwMainIcon').className = 'fas fa-bars';
    
    document.getElementById('adminChatModal').style.display = 'flex';
    loadChatList();
    
    chatPollInterval = setInterval(() => {
        loadChatList();
        if(currentChatUUID) loadConversation(currentChatUUID, false);
    }, 3000);
}

window.closeAdminChat = function() {
    document.getElementById('adminChatModal').style.display = 'none';
    clearInterval(chatPollInterval);
    checkUnread();
}

async function loadChatList() {
    try {
        const res = await fetch(CHAT_API + '?action=admin_list');
        const list = await res.json();
        const container = document.getElementById('chatListItems');
        let html = '';
        
        if(list.length === 0) html = '<div style="padding:20px; text-align:center; color:#999;">No hay chats.</div>';
        
        list.forEach(c => {
            const bg = (c.client_uuid === currentChatUUID) ? '#e9ecef' : 'transparent';
            const badge = c.unread > 0 ? `<span class="cl-badge">${c.unread}</span>` : '';
            const time = new Date(c.last_msg_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
            
            html += `
            <div class="cl-item" onclick="selectChat('${c.client_uuid}')" style="background:${bg}">
                ${badge}
                <div style="font-weight:bold;">Cliente #${c.client_uuid.substr(0,6)}</div>
                <div style="font-size:12px; color:#666; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${c.last_msg || '...'}</div>
                <div style="font-size:10px; color:#999; margin-top:2px;">${time}</div>
            </div>`;
        });
        container.innerHTML = html;
    } catch(e) {}
}

window.selectChat = function(uuid) {
    currentChatUUID = uuid;
    document.getElementById('chatUserTitle').innerText = 'Cliente #' + uuid.substr(0,6);
    document.getElementById('chatInput').disabled = false;
    document.getElementById('chatSendBtn').disabled = false;
    loadConversation(uuid, true);
}

async function loadConversation(uuid, scroll) {
    if(!uuid) return;
    try {
        const res = await fetch(`${CHAT_API}?action=admin_get_chat&uuid=${uuid}`);
        const msgs = await res.json();
        const body = document.getElementById('chatMessages');
        let html = '';
        
        msgs.forEach(m => {
            const type = m.sender === 'admin' ? 'cb-admin' : 'cb-client';
            const align = m.sender === 'admin' ? 'flex-end' : 'flex-start';
            html += `<div style="display:flex; justify-content:${align}; margin-bottom:5px;">
                        <div class="chat-bubble ${type}">${m.message}</div>
                     </div>`;
        });
        body.innerHTML = html;
        if(scroll) body.scrollTop = body.scrollHeight;
    } catch(e) {}
}

window.sendAdminMsg = async function() {
    const input = document.getElementById('chatInput');
    const text = input.value.trim();
    if(!text || !currentChatUUID) return;
    
    input.value = '';
    const body = document.getElementById('chatMessages');
    body.innerHTML += `<div style="display:flex; justify-content:flex-end; margin-bottom:5px;"><div class="chat-bubble cb-admin" style="opacity:0.7">${text}</div></div>`;
    body.scrollTop = body.scrollHeight;
    
    await fetch(CHAT_API + '?action=send', {
        method: 'POST',
        body: JSON.stringify({ uuid: currentChatUUID, message: text, sender: 'admin' })
    });
}; 

// 3. INYECTOR DE CSS (Themes) - Original
(function() {
    if (!document.getElementById('theme-css-link')) {
        const link = document.createElement('link');
        link.id = 'theme-css-link'; link.rel = 'stylesheet';
        link.href = 'themes.css?v=' + new Date().getTime(); 
        document.head.appendChild(link);
    }

    window.setTheme = function(themeName) {
        document.documentElement.setAttribute('data-theme', themeName);
        document.documentElement.setAttribute('data-bs-theme', themeName === 'light' || themeName === 'flatly' ? 'light' : 'dark');
        localStorage.setItem('palweb_theme', themeName);
    }

    const savedTheme = localStorage.getItem('palweb_theme') || 'light';
    setTheme(savedTheme);
})();

// 4. LÓGICA DE ARRASTRE Y MENÚ - Original
(function() {
    const floatNav = document.getElementById('palweb-float-nav');
    const btn = document.getElementById('pwFloatBtn');
    const menu = document.getElementById('pwMenuContent');
    const icon = document.getElementById('pwMainIcon');

    let isDragging = false, startX, startY, initialRight, initialBottom, hasMoved = false;

    const startDrag = (e) => {
        isDragging = true; hasMoved = false;
        const clientX = e.type.includes('mouse') ? e.clientX : e.touches[0].clientX;
        const clientY = e.type.includes('mouse') ? e.clientY : e.touches[0].clientY;
        startX = clientX; startY = clientY;
        const style = window.getComputedStyle(floatNav);
        initialRight = parseInt(style.right); initialBottom = parseInt(style.bottom);
        document.addEventListener('mousemove', onDrag); document.addEventListener('touchmove', onDrag, {passive:false});
        document.addEventListener('mouseup', stopDrag); document.addEventListener('touchend', stopDrag);
    };

    const onDrag = (e) => {
        if (!isDragging) return;
        const clientX = e.type.includes('mouse') ? e.clientX : e.touches[0].clientX;
        const clientY = e.type.includes('mouse') ? e.clientY : e.touches[0].clientY;
        const dx = startX - clientX; const dy = startY - clientY;
        if (Math.abs(dx)>3 || Math.abs(dy)>3) {
            hasMoved = true; e.preventDefault();
            floatNav.style.right = `${initialRight + dx}px`;
            floatNav.style.bottom = `${initialBottom + dy}px`;
        }
    };

    const stopDrag = () => {
        isDragging = false;
        document.removeEventListener('mousemove', onDrag); document.removeEventListener('touchmove', onDrag);
        document.removeEventListener('mouseup', stopDrag); document.removeEventListener('touchend', stopDrag);
    };

    btn.addEventListener('mousedown', startDrag);
    btn.addEventListener('touchstart', startDrag, {passive:false});

    btn.addEventListener('click', (e) => {
        if (hasMoved) return;
        menu.classList.toggle('active');
        // Mantener ícono acorde
        if(menu.classList.contains('active')) {
            icon.className = 'fas fa-times';
        } else {
            icon.className = 'fas fa-bars';
        }
    });

    document.addEventListener('click', (e) => {
        if (!floatNav.contains(e.target) && menu.classList.contains('active')) {
            menu.classList.remove('active');
            icon.className = 'fas fa-bars';
        }
    });
})();
</script>

