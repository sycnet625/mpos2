<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pantalla de Cocina (KDS)</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { 
            background-color: #121212; 
            color: #e0e0e0; 
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; 
            overflow-x: hidden;
        }
        
        /* SCROLLBAR PERSONALIZADO */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #1e1e1e; }
        ::-webkit-scrollbar-thumb { background: #555; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #777; }

        .grid-container { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); 
            gap: 20px; 
            padding: 20px; 
            padding-top: 10px;
        }
        
        .comanda-card { 
            background: #1e1e1e; 
            border: 2px solid #444; 
            border-radius: 12px; 
            cursor: pointer; 
            transition: all 0.3s ease; 
            overflow: hidden; 
            position: relative;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
        }
        
        .comanda-card:hover { transform: translateY(-3px); z-index: 10; }
        .comanda-card:active { transform: scale(0.98); }
        
        /* ESTADOS CON GLOW EFFECTS */
        .estado-pendiente { border-color: #ff4d4d; box-shadow: 0 0 15px rgba(255, 77, 77, 0.2); }
        .estado-elaboracion { border-color: #ffc107; box-shadow: 0 0 15px rgba(255, 193, 7, 0.2); }
        .estado-terminado { border-color: #28a745; box-shadow: 0 0 15px rgba(40, 167, 69, 0.2); }

        /* ANIMACIÓN DE ATRASADO */
        @keyframes pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(255, 82, 82, 0.7); border-color: #ff5252; }
            70% { box-shadow: 0 0 20px 10px rgba(255, 82, 82, 0); border-color: #ff0000; }
            100% { box-shadow: 0 0 0 0 rgba(255, 82, 82, 0); border-color: #ff5252; }
        }

        .estado-atrasado {
            animation: pulse-red 2s infinite;
            background-color: #2a0a0a;
        }

        .card-header { 
            font-weight: 700; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            padding: 10px 15px; 
            background: rgba(255,255,255,0.05); 
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .timer { 
            font-size: 1.3rem; 
            font-weight: 700; 
            font-family: 'Courier New', monospace; 
            letter-spacing: 1px;
        }
        
        .card-body { padding: 0; flex-grow: 1; }

        .item-row { 
            padding: 10px 15px; 
            border-bottom: 1px solid rgba(255,255,255,0.08); 
            font-size: 1.1rem; 
            line-height: 1.3;
        }
        .item-row:last-child { border-bottom: none; }

        .qty-badge {
            display: inline-block;
            background: rgba(255,255,255,0.15);
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: bold;
            margin-right: 8px;
            min-width: 30px;
            text-align: center;
        }

        .item-note { 
            font-size: 0.9rem; 
            color: #ffca2c; 
            font-style: italic; 
            display: block; 
            margin-top: 4px;
            padding-left: 40px;
        }

        .card-footer-status {
            padding: 6px;
            text-align: center;
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 1px;
            background: rgba(0,0,0,0.2);
        }
        
        .text-pendiente { color: #ff4d4d; }
        .text-elaboracion { color: #ffc107; }
        .text-terminado { color: #28a745; }
        .service-icon { font-size: 1.1rem; margin-left: 5px; }

        /* NUEVOS ESTILOS: FILTROS Y BADGES */
        .filter-bar {
            overflow-x: auto;
            white-space: nowrap;
            padding: 10px 15px;
            background: #1a1a1a;
            border-bottom: 1px solid #333;
        }
        
        .filter-btn {
            border: 1px solid #444;
            background: #2a2a2a;
            color: #ccc;
            margin-right: 8px;
            padding: 6px 15px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: #ffc107;
            color: #000;
            border-color: #ffc107;
            font-weight: bold;
        }

        .stat-badge {
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 4px;
            margin-left: 10px;
            background: rgba(255,255,255,0.1);
        }

        /* MODAL HISTORY */
        .modal-content { background: #222; color: #fff; border: 1px solid #444; }
        .modal-header { border-bottom: 1px solid #444; }
        .modal-footer { border-top: 1px solid #444; }
        .table-dark { background-color: #222; }
    </style>
</head>
<body>

<div class="d-flex flex-column vh-100">
    <!-- HEADER -->
    <div class="bg-dark p-2 border-bottom border-secondary d-flex justify-content-between align-items-center sticky-top shadow-sm" style="z-index: 100;">
        <div class="d-flex align-items-center">
            <h4 class="m-0 text-white mr-3"><i class="fas fa-utensils text-warning mr-2"></i> KDS</h4>
            
            <div class="d-none d-md-flex ms-3">
                <span class="stat-badge border border-light" title="Total Activas">
                    <i class="fas fa-clipboard-list"></i> <span id="count-total">0</span>
                </span>
                <span class="stat-badge border border-danger text-danger" title="Pendientes">
                    <i class="fas fa-fire"></i> <span id="count-pending">0</span>
                </span>
                <span class="stat-badge border border-warning text-warning" title="En Elaboración">
                    <i class="fas fa-hat-chef"></i> <span id="count-cooking">0</span>
                </span>
                <span class="stat-badge border border-success text-success" title="Terminados">
                    <i class="fas fa-check"></i> <span id="count-ready">0</span>
                </span>
            </div>
        </div>

        <div>
            <span id="clock" class="text-white mr-3 font-monospace h5 d-none d-sm-inline"></span>
            <button class="btn btn-outline-info btn-sm me-2" onclick="openHistory()"><i class="fas fa-history"></i> Historial</button>
            <button class="btn btn-outline-warning btn-sm" onclick="toggleFullScreen()"><i class="fas fa-expand"></i></button>
        </div>
    </div>

    <!-- FILTROS DE PARTIDA -->
    <div class="filter-bar" id="categoryFilters">
        <button class="filter-btn active" onclick="setFilter('all')">Todos</button>
        <!-- Botones dinámicos aquí -->
    </div>
    
    <!-- GRID -->
    <div id="gridComandas" class="grid-container flex-grow-1">
        <!-- Las comandas se cargarán aquí -->
    </div>
</div>

<!-- MODAL HISTORIAL -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-history text-info"></i> Historial de Pedidos (Últimos 50)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover table-sm m-0 small">
                        <thead>
                            <tr>
                                <th>#Venta</th>
                                <th>Hora Fin</th>
                                <th>Duración</th>
                                <th>Items</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<audio id="alertSound" src="assets/audio/ding.mp3" preload="auto"></audio>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
    let comandas = [];
    let productMap = {}; // Mapa NombreProducto -> Categoria
    let currentFilter = 'all';
    let lastComandasIds = new Set();
    let isFirstLoad = true;

    // Configuración
    const REFRESH_RATE = 3000;
    const TIME_WARNING = 600; // 10 minutos
    const TIME_PENDING_WARNING = 300; 

    document.addEventListener('DOMContentLoaded', () => {
        loadComandas();
        setInterval(loadComandas, REFRESH_RATE);
        setInterval(updateTimers, 1000);
        updateClock();
        setInterval(updateClock, 1000);
    });

    async function loadComandas() {
        try {
            const resp = await fetch('pos_kitchen_api.php?action=list');
            if (!resp.ok) throw new Error('Network error');
            const data = await resp.json();
            
            // La API ahora devuelve { comandas: [], product_map: {} }
            // Si es versión vieja, podría devolver array directo
            const comandaList = Array.isArray(data) ? data : (data.comandas || []);
            productMap = data.product_map || {};

            processData(comandaList);
        } catch(e) { 
            console.error("Error conexión cocina:", e); 
        }
    }

    function processData(data) {
        // Alerta sonora
        const currentIds = new Set(data.map(c => c.id));
        let hasNew = false;
        currentIds.forEach(id => { if (!lastComandasIds.has(id)) hasNew = true; });

        if (hasNew && !isFirstLoad) playNotification();
        lastComandasIds = currentIds;
        isFirstLoad = false;

        // DEBUG
        console.log("Comandas recibidas:", data.length);
        console.log("Mapa de productos:", Object.keys(productMap).length);

        // Renderizado
        // Siempre renderizamos para actualizar contadores y filtros dinámicos
        comandas = data;
        renderComandas();
        updateStats();
        updateFilters(); // Generar botones si hay nuevas categorías
    }

    function updateStats() {
        document.getElementById('count-total').innerText = comandas.length;
        document.getElementById('count-pending').innerText = comandas.filter(c => c.estado === 'pendiente').length;
        document.getElementById('count-cooking').innerText = comandas.filter(c => c.estado === 'elaboracion').length;
        document.getElementById('count-ready').innerText = comandas.filter(c => c.estado === 'terminado').length;
    }

    // Generar botones de filtro dinámicamente
    function updateFilters() {
        const categories = new Set();
        // Buscar categorías usadas en las comandas actuales
        comandas.forEach(c => {
            const items = JSON.parse(c.items_json || '[]');
            items.forEach(i => {
                const cat = productMap[i.name] || 'Otros';
                categories.add(cat);
            });
        });

        const filterBar = document.getElementById('categoryFilters');
        // Mantener el botón 'Todos'
        let html = `<button class="filter-btn ${currentFilter === 'all' ? 'active' : ''}" onclick="setFilter('all')">Todos</button>`;
        
        Array.from(categories).sort().forEach(cat => {
            const isActive = currentFilter === cat ? 'active' : '';
            html += `<button class="filter-btn ${isActive}" onclick="setFilter('${cat}')">${cat}</button>`;
        });
        
        // Solo actualizar si cambia el contenido para no romper clicks
        if (filterBar.innerHTML !== html) {
             // Pequeña optimización: podríamos verificar si las cats cambiaron realmente
             // pero reemplazar el HTML es barato aquí.
             filterBar.innerHTML = html;
        }
    }

    function setFilter(cat) {
        currentFilter = cat;
        // Actualizar visual de botones
        document.querySelectorAll('.filter-btn').forEach(btn => {
            if (btn.innerText === cat || (cat === 'all' && btn.innerText === 'Todos')) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        renderComandas();
    }

    function renderComandas() {
        const grid = document.getElementById('gridComandas');
        const data = comandas;

        if (data.length === 0) {
            grid.innerHTML = '<div class="text-center w-100 mt-5 py-5" style="color: #888;">' +
                             '<i class="fas fa-check-circle fa-4x mb-3" style="color: #28a745; opacity: 0.5;"></i>' +
                             '<h3 class="text-white">¡Todo al día!</h3>' +
                             '<p>No hay pedidos pendientes en este momento.</p></div>';
            return;
        }

        let html = '';
        let visibleCount = 0;

        data.forEach(c => {
            let items = [];
            try {
                items = JSON.parse(c.items_json || '[]');
            } catch(e) {
                return;
            }

            let itemsHtml = '';
            let hasVisibleItems = false;
            
            items.forEach(i => {
                const itemCat = productMap[i.name] || 'Otros';

                if (currentFilter !== 'all' && itemCat !== currentFilter) {
                    return; 
                }

                hasVisibleItems = true;
                let nota = i.note ? `<span class="item-note"><i class="fas fa-comment-alt"></i> ${i.note}</span>` : '';
                itemsHtml += `
                    <div class="item-row">
                        <span class="qty-badge">${i.qty}</span> ${i.name} 
                        ${nota}
                    </div>`;
            });

            if (!hasVisibleItems) return;
            visibleCount++;

            const nextStatus = getNextStatus(c.estado);
            
            let badgeIcon = '';
            if (c.tipo_servicio === 'mensajeria') badgeIcon = '<i class="fas fa-motorcycle service-icon"></i>';
            else if (c.tipo_servicio === 'llevar') badgeIcon = '<i class="fas fa-shopping-bag service-icon"></i>';
            else badgeIcon = '<i class="fas fa-concierge-bell service-icon"></i>';

            const timeRef = (c.estado === 'elaboracion' && c.fecha_inicio) ? c.fecha_inicio : c.fecha_creacion;

            html += `
                <div class="comanda-card estado-${c.estado}" 
                     id="card-${c.id}" 
                     data-status="${c.estado}"
                     data-time="${timeRef}"
                     onclick="changeStatus(${c.id}, '${nextStatus}')">
                    
                    <div class="card-header">
                        <div>
                            <span class="badge bg-light text-dark">#${c.id_venta}</span>
                            ${badgeIcon} <small>${c.mensajero_nombre || ''}</small>
                        </div>
                        <span class="timer">00:00</span>
                    </div>
                    
                    <div class="card-body">
                        ${itemsHtml}
                    </div>
                    
                    <div class="card-footer-status text-${c.estado}">
                        ${c.estado.toUpperCase()}
                    </div>
                </div>
            `;
        });

        if (visibleCount === 0 && data.length > 0) {
             grid.innerHTML = '<div class="text-center w-100 mt-5 py-5" style="color: #888;">' +
                              '<i class="fas fa-filter fa-3x mb-3" style="opacity:0.3"></i>' +
                              '<h4 class="text-white">Sin pedidos en "' + currentFilter + '"</h4>' +
                              '<p>Hay pedidos en otras partidas.</p></div>';
        } else {
            grid.innerHTML = html;
            updateTimers();
        }
    }

    async function changeStatus(id, status) {
        const card = document.getElementById(`card-${id}`);
        if(card) {
            card.style.transform = 'scale(0.95)';
            card.style.opacity = '0.7';
        }
        try {
            await fetch(`pos_kitchen_api.php?action=update&id=${id}&status=${status}`);
            loadComandas(); 
        } catch (e) {
            console.error(e);
        }
    }

    function getNextStatus(current) {
        if(current === 'pendiente') return 'elaboracion';
        if(current === 'elaboracion') return 'terminado';
        return 'entregado'; 
    }

    function updateTimers() {
        document.querySelectorAll('.comanda-card').forEach(card => {
            const timeStr = card.dataset.time; 
            if (!timeStr) return;
            const safeTimeStr = timeStr.replace(' ', 'T');
            const start = new Date(safeTimeStr).getTime();
            const now = new Date().getTime();
            if (isNaN(start)) return;

            const diff = Math.floor((now - start) / 1000); 
            const m = Math.floor(diff / 60).toString().padStart(2, '0');
            const s = (diff % 60).toString().padStart(2, '0');
            
            const timerEl = card.querySelector('.timer');
            if (timerEl) timerEl.innerText = `${m}:${s}`;
            
            const status = card.dataset.status;
            if (status === 'elaboracion' && diff > TIME_WARNING) {
                card.classList.remove('estado-elaboracion');
                card.classList.add('estado-atrasado');
            } else if (status === 'pendiente' && diff > TIME_PENDING_WARNING) {
                if (timerEl) timerEl.style.color = '#ff6b6b';
            }
        });
    }

    function updateClock() {
        const now = new Date();
        document.getElementById('clock').innerText = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }

    function playNotification() {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(500, audioCtx.currentTime);
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.start();
            gainNode.gain.exponentialRampToValueAtTime(0.00001, audioCtx.currentTime + 0.5);
            oscillator.stop(audioCtx.currentTime + 0.5);
        } catch(e) {}
    }

    function toggleFullScreen() {
        if (!document.fullscreenElement) document.documentElement.requestFullscreen();
        else if (document.exitFullscreen) document.exitFullscreen();
    }

    // --- HISTORIAL ---
    async function openHistory() {
        const modal = new bootstrap.Modal(document.getElementById('historyModal'));
        modal.show();
        
        const tbody = document.getElementById('historyBody');
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">Cargando...</td></tr>';

        try {
            const resp = await fetch('pos_kitchen_api.php?action=history');
            const data = await resp.json();
            
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center">No hay historial reciente</td></tr>';
                return;
            }

            data.forEach(c => {
                // Calcular duración
                let duration = '-';
                if (c.fecha_inicio && c.fecha_fin) {
                    const start = new Date(c.fecha_inicio.replace(' ', 'T')).getTime();
                    const end = new Date(c.fecha_fin.replace(' ', 'T')).getTime();
                    const diffMin = Math.round((end - start) / 60000);
                    duration = `${diffMin} min`;
                }

                // Resumen Items
                const items = JSON.parse(c.items_json || '[]');
                const itemsSummary = items.map(i => `${i.qty}x ${i.name}`).join(', ');

                tbody.innerHTML += `
                    <tr>
                        <td>#${c.id_venta}</td>
                        <td>${c.fecha_fin ? c.fecha_fin.split(' ')[1] : '-'}</td>
                        <td>${duration}</td>
                        <td class="text-truncate" style="max-width: 200px;" title="${itemsSummary}">${itemsSummary}</td>
                        <td><span class="badge bg-success">Entregado</span></td>
                    </tr>
                `;
            });

        } catch(e) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error al cargar historial</td></tr>';
        }
    }
</script>

<!-- Push Notifications para Cocina -->
<div id="pushBellWrap" style="position:fixed;bottom:16px;right:16px;z-index:9999;">
    <button id="pushBellBtn" onclick="handleCocinaNotif()"
        title="Activar notificaciones de cocina"
        style="width:42px;height:42px;border-radius:50%;border:none;
               background:#f59e0b;color:#fff;font-size:18px;
               box-shadow:0 2px 10px rgba(0,0,0,0.4);cursor:pointer;">
        <i id="pushBellIcon" class="fas fa-bell-slash"></i>
    </button>
</div>
<script>
(function () {
    const PUSH_TIPO = 'cocina';
    const PUSH_CACHE_NAME = 'push-config-v1';

    function urlB64ToUint8Array(b64) {
        const pad = '='.repeat((4 - b64.length % 4) % 4);
        const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        const arr = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    async function saveTipoToCache(tipo) {
        try {
            const cache = await caches.open(PUSH_CACHE_NAME);
            await cache.put('push-tipo', new Response(tipo));
        } catch (e) {}
    }

    function setBell(state) {
        const btn  = document.getElementById('pushBellBtn');
        const icon = document.getElementById('pushBellIcon');
        if (!btn || !icon) return;
        if (state === 'active') {
            btn.style.background = '#22c55e';
            btn.title = 'Notificaciones cocina activas — Click para desactivar';
            icon.className = 'fas fa-bell';
        } else if (state === 'denied') {
            btn.style.background = '#ef4444';
            icon.className = 'fas fa-bell-slash';
        } else {
            btn.style.background = '#f59e0b';
            btn.title = 'Activar notificaciones de cocina';
            icon.className = 'fas fa-bell-slash';
        }
    }

    async function subscribe() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
        try { await navigator.serviceWorker.register('service-worker.js', { scope: './' }); } catch (e) {}
        const reg = await Promise.race([
            navigator.serviceWorker.ready,
            new Promise((_, rej) => setTimeout(() => rej(new Error('SW timeout')), 6000)),
        ]);
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            const res = await fetch('push_api.php?action=vapid_key');
            const { publicKey } = await res.json();
            if (!publicKey) return;
            sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8Array(publicKey) });
        }
        await fetch('push_api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON(), tipo: PUSH_TIPO, device: navigator.userAgent.slice(0, 150) })
        });
        await saveTipoToCache(PUSH_TIPO);
        setBell('active');
    }

    async function unsubscribe() {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            await fetch('push_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'unsubscribe', endpoint: sub.endpoint }) });
            await sub.unsubscribe();
        }
        setBell('off');
    }

    window.handleCocinaNotif = async function () {
        if (!('PushManager' in window)) { alert('Navegador no soporta push.'); return; }
        if (Notification.permission === 'denied') { alert('Notificaciones bloqueadas. Actívalas en ajustes del navegador.'); return; }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            if (confirm('¿Desactivar notificaciones de cocina?')) await unsubscribe();
        } else {
            const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
            if (perm === 'granted') await subscribe(); else setBell('denied');
        }
    };

    window.addEventListener('load', async () => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
        try { await navigator.serviceWorker.register('service-worker.js', { scope: './' }); } catch (e) {}
        let reg;
        try {
            reg = await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((_, rej) => setTimeout(() => rej(new Error('SW timeout')), 6000)),
            ]);
        } catch (e) { return; }
        const sub = await reg.pushManager.getSubscription();
        if (sub) { await saveTipoToCache(PUSH_TIPO); setBell('active'); } else setBell('off');
    });
})();
</script>
</body>
</html>

