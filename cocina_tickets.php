<?php
// ============================================================
// cocina_tickets.php — Pantalla de Cocina (tickets de sesión)
// Muestra tickets de la sesión actual (o última cerrada) de la
// sucursal actual, con totales de productos en la cabecera
// y marcado "hecho" persistente por ticket (localStorage).
// ============================================================
require_once 'db.php';
require_once 'config_loader.php';

$idSucursal = intval($config['id_sucursal'] ?? 1);

// 1. Determinar sesión: abierta preferente, si no la última cerrada
$stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_sucursal = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$idSucursal]);
$sesion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sesion) {
    $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id_sucursal = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$idSucursal]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);
}

$idSesion     = $sesion ? intval($sesion['id']) : 0;
$estadoSesion = $sesion['estado'] ?? '—';
$aperturaSes  = $sesion['fecha_apertura'] ?? '';

// 2. Obtener tickets de la sesión + detalles
$tickets = [];
if ($idSesion > 0) {
    $stmtT = $pdo->prepare("SELECT id, uuid_venta, fecha, cliente_nombre, cliente_telefono, total, tipo_servicio, canal_origen, mensajero_nombre
                            FROM ventas_cabecera
                            WHERE id_caja = ? AND id_sucursal = ?
                            ORDER BY id ASC");
    $stmtT->execute([$idSesion, $idSucursal]);
    $cabs = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    if ($cabs) {
        $ids = array_column($cabs, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtD = $pdo->prepare("SELECT id_venta_cabecera, codigo_producto, nombre_producto, cantidad
                                FROM ventas_detalle
                                WHERE id_venta_cabecera IN ($placeholders)");
        $stmtD->execute($ids);
        $detsPorTk = [];
        foreach ($stmtD->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $detsPorTk[$d['id_venta_cabecera']][] = $d;
        }
        foreach ($cabs as $c) {
            $c['items'] = $detsPorTk[$c['id']] ?? [];
            $tickets[] = $c;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pantalla de Cocina — Sucursal <?= $idSucursal ?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<style>
    :root {
        --bg:#0b1020;
        --panel:#111827;
        --panel-2:#1f2937;
        --border:#374151;
        --accent:#3b82f6;
        --done:#10b981;
        --done-bg:#064e3b;
        --warning:#fbbf24;
        --text:#e5e7eb;
        --muted:#9ca3af;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        user-select: none;
        -webkit-tap-highlight-color: transparent;
    }
    ::-webkit-scrollbar { width: 10px; height: 10px; }
    ::-webkit-scrollbar-track { background: #1e1e1e; }
    ::-webkit-scrollbar-thumb { background: #555; border-radius: 4px; }

    /* ======= CABECERA FIJA CON RESUMEN ======= */
    .kds-header {
        position: sticky;
        top: 0;
        z-index: 100;
        background: linear-gradient(180deg, #0f172a 0%, #0b1020 100%);
        border-bottom: 2px solid var(--border);
        box-shadow: 0 4px 20px rgba(0,0,0,0.6);
        padding: 10px 14px;
    }
    .kds-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 8px;
    }
    .kds-title {
        font-size: 1.15rem;
        font-weight: 800;
        letter-spacing: 0.3px;
    }
    .kds-title i { color: #f97316; }
    .kds-meta {
        font-size: 0.85rem;
        color: var(--muted);
        display: flex;
        gap: 14px;
        align-items: center;
        flex-wrap: wrap;
    }
    .kds-meta .dot { color: var(--accent); font-weight: 700; }
    .kds-meta .closed { color: #ef4444; }
    .kds-meta .open { color: var(--done); }
    .kds-btn {
        background: var(--panel-2);
        color: var(--text);
        border: 1px solid var(--border);
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all .15s;
    }
    .kds-btn:hover { background: #374151; }
    .kds-btn.primary { background: var(--accent); border-color: var(--accent); }

    .kds-prod-bar {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 8px;
    }
    .kds-prod {
        background: var(--panel-2);
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 10px 12px;
        text-align: center;
        transition: all .25s;
    }
    .kds-prod.partial { border-color: var(--warning); }
    .kds-prod.complete {
        background: var(--done-bg);
        border-color: var(--done);
        box-shadow: 0 0 12px rgba(16,185,129,0.35);
    }
    .kds-prod .name {
        font-size: 0.85rem;
        color: var(--muted);
        text-transform: uppercase;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .kds-prod .qty {
        font-size: 2rem;
        font-weight: 900;
        margin-top: 2px;
        line-height: 1;
    }
    .kds-prod .qty .done { color: #34d399; }
    .kds-prod.complete .qty .done { color: #fff; }
    .kds-prod .qty .sep { opacity: 0.5; margin: 0 2px; }
    .kds-prod .qty .total { color: var(--muted); font-size: 1.3rem; font-weight: 800; }

    /* ======= GRID DE TICKETS ======= */
    .kds-tickets {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px;
        padding: 14px;
    }
    .kds-ticket {
        background: var(--panel);
        border: 3px solid var(--accent);
        border-radius: 12px;
        padding: 12px;
        cursor: pointer;
        transition: all .2s ease;
        box-shadow: 0 3px 10px rgba(0,0,0,0.45);
        position: relative;
    }
    .kds-ticket:hover { transform: translateY(-2px); }
    .kds-ticket:active { transform: scale(0.98); }
    .kds-ticket.done {
        border-color: var(--done);
        background: var(--done-bg);
        box-shadow: 0 0 16px rgba(16,185,129,0.4);
        opacity: 0.85;
    }
    .kds-ticket.done::after {
        content: "✓";
        position: absolute;
        top: 6px;
        right: 10px;
        font-size: 1.8rem;
        color: var(--done);
        font-weight: 800;
    }
    .tk-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
        padding-bottom: 6px;
        margin-bottom: 8px;
    }
    .tk-num {
        font-weight: 800;
        font-size: 1.05rem;
        color: #60a5fa;
    }
    .kds-ticket.done .tk-num { color: #34d399; }
    .tk-time {
        font-size: 0.85rem;
        color: var(--muted);
        font-variant-numeric: tabular-nums;
    }
    .tk-client {
        font-size: 0.85rem;
        color: #d1d5db;
        margin-bottom: 6px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .tk-badges {
        display: flex;
        gap: 4px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }
    .tk-badge {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 10px;
        text-transform: uppercase;
    }
    .tk-badge.srv { background: #f59e0b; color: #1f2937; }
    .tk-badge.chn { background: #6366f1; color: #fff; }
    .tk-items {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .tk-items li {
        font-size: 0.98rem;
        padding: 3px 0;
        border-bottom: 1px dashed rgba(255,255,255,0.07);
    }
    .tk-items li:last-child { border-bottom: none; }
    .tk-items .qty {
        display: inline-block;
        min-width: 36px;
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--warning);
        margin-right: 6px;
    }
    .kds-ticket.done .tk-items .qty { color: #34d399; }

    .kds-empty {
        padding: 70px 20px;
        text-align: center;
        color: var(--muted);
    }
    .kds-empty i { font-size: 3.5rem; opacity: 0.3; margin-bottom: 10px; }

    @media (max-width: 600px) {
        .kds-prod { min-width: 130px; padding: 8px 10px; }
        .kds-prod .qty { font-size: 1.4rem; }
        .kds-tickets { grid-template-columns: 1fr 1fr; gap: 8px; padding: 8px; }
    }
</style>
</head>
<body>

<?php if ($idSesion === 0): ?>
    <div class="kds-empty">
        <i class="fas fa-cash-register"></i>
        <h3>No hay sesiones de caja en la sucursal <?= $idSucursal ?>.</h3>
        <p>Abre caja en POS para generar tickets.</p>
    </div>
<?php else: ?>

<div class="kds-header">
    <div class="kds-topbar">
        <div class="kds-title">
            <i class="fas fa-fire"></i> PANTALLA DE COCINA
        </div>
        <div class="kds-meta">
            <span>Sesión <span class="dot">#<?= $idSesion ?></span></span>
            <span>
                Estado:
                <?php if ($estadoSesion === 'ABIERTA'): ?>
                    <span class="open"><i class="fas fa-circle"></i> ABIERTA</span>
                <?php else: ?>
                    <span class="closed"><i class="fas fa-lock"></i> <?= htmlspecialchars($estadoSesion) ?></span>
                <?php endif; ?>
            </span>
            <span>Sucursal <span class="dot"><?= $idSucursal ?></span></span>
            <span>Tickets: <span class="dot"><?= count($tickets) ?></span></span>
            <span>Auto-refresh <span id="countdown">30</span>s</span>
            <button class="kds-btn" onclick="resetDone()" title="Limpia todos los marcados como hechos">
                <i class="fas fa-rotate-left"></i> Reiniciar
            </button>
            <button class="kds-btn" onclick="printSummary()" title="Imprime un resumen en papel con cada producto y sus cantidades">
                <i class="fas fa-print"></i> Imprimir resumen
            </button>
            <button class="kds-btn primary" onclick="location.reload()" title="Refrescar ahora">
                <i class="fas fa-sync"></i> Refrescar
            </button>
        </div>
    </div>
    <div class="kds-prod-bar" id="prodBar"></div>
</div>

<div class="kds-tickets" id="ticketsBox"></div>

<script>
const SESSION_ID = <?= $idSesion ?>;
const TICKETS = <?= json_encode($tickets, JSON_UNESCAPED_UNICODE) ?>;
const STORAGE_KEY = 'kds_done_' + SESSION_ID;

let doneSet = loadDone();

function loadDone() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return new Set(raw ? JSON.parse(raw) : []);
    } catch(e) { return new Set(); }
}
function saveDone(s) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...s]));
}

function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function fmtQty(n) {
    n = parseFloat(n) || 0;
    if (Number.isInteger(n)) return n;
    return n.toFixed(2).replace(/\.?0+$/, '');
}

function computeAggregates() {
    const agg = {};
    TICKETS.forEach(tk => {
        const isDone = doneSet.has(tk.uuid_venta);
        (tk.items || []).forEach(it => {
            const key = it.codigo_producto || it.nombre_producto;
            if (!agg[key]) agg[key] = { name: it.nombre_producto, total: 0, done: 0 };
            const q = parseFloat(it.cantidad) || 0;
            agg[key].total += q;
            if (isDone) agg[key].done += q;
        });
    });
    return agg;
}

function renderProdBar() {
    const agg = computeAggregates();
    const arr = Object.values(agg).sort((a, b) => {
        const pa = (a.total - a.done), pb = (b.total - b.done);
        if (pb !== pa) return pb - pa;
        return b.total - a.total;
    });
    const bar = document.getElementById('prodBar');
    if (!arr.length) {
        bar.innerHTML = '<div style="opacity:.6;padding:14px;">Sin productos en la sesión.</div>';
        return;
    }
    bar.innerHTML = arr.map(p => {
        const complete = p.total > 0 && p.done >= p.total;
        const partial = p.done > 0 && !complete;
        const cls = complete ? 'complete' : (partial ? 'partial' : '');
        return `<div class="kds-prod ${cls}">
            <div class="name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</div>
            <div class="qty">
                <span class="done">${fmtQty(p.done)}</span><span class="sep">/</span><span class="total">${fmtQty(p.total)}</span>
            </div>
        </div>`;
    }).join('');
}

function renderTickets() {
    const box = document.getElementById('ticketsBox');
    if (!TICKETS.length) {
        box.innerHTML = `<div class="kds-empty"><i class="fas fa-inbox"></i><h4>Aún no hay tickets en esta sesión.</h4></div>`;
        return;
    }
    box.innerHTML = TICKETS.map(tk => {
        const isDone = doneSet.has(tk.uuid_venta);
        const timePart = (tk.fecha || '').split(' ')[1] || tk.fecha || '';
        const badges = [];
        if (tk.tipo_servicio && tk.tipo_servicio !== 'mostrador') {
            badges.push(`<span class="tk-badge srv">${escapeHtml(tk.tipo_servicio)}</span>`);
        }
        if (tk.canal_origen && tk.canal_origen !== 'POS') {
            badges.push(`<span class="tk-badge chn">${escapeHtml(tk.canal_origen)}</span>`);
        }
        const items = (tk.items || []).map(it =>
            `<li><span class="qty">${fmtQty(it.cantidad)}×</span>${escapeHtml(it.nombre_producto)}</li>`
        ).join('');
        const cliente = tk.cliente_nombre || 'Mostrador';
        return `<div class="kds-ticket ${isDone ? 'done' : ''}" data-uuid="${escapeHtml(tk.uuid_venta)}">
            <div class="tk-head">
                <span class="tk-num">#${tk.id}</span>
                <span class="tk-time">${escapeHtml(timePart)}</span>
            </div>
            <div class="tk-client"><i class="fas fa-user" style="opacity:0.5;"></i> ${escapeHtml(cliente)}</div>
            ${badges.length ? `<div class="tk-badges">${badges.join('')}</div>` : ''}
            <ul class="tk-items">${items}</ul>
        </div>`;
    }).join('');

    box.querySelectorAll('.kds-ticket').forEach(el => {
        el.addEventListener('click', () => {
            const uuid = el.dataset.uuid;
            if (doneSet.has(uuid)) doneSet.delete(uuid);
            else doneSet.add(uuid);
            saveDone(doneSet);
            el.classList.toggle('done');
            renderProdBar();
        });
    });
}

function resetDone() {
    if (!confirm('¿Reiniciar todos los tickets marcados como hechos?')) return;
    doneSet.clear();
    saveDone(doneSet);
    renderProdBar();
    renderTickets();
}

function printSummary() {
    const agg = computeAggregates();
    const arr = Object.values(agg).sort((a, b) =>
        a.name.localeCompare(b.name, 'es', { sensitivity: 'base' })
    );
    if (!arr.length) {
        alert('No hay productos en la sesión para imprimir.');
        return;
    }

    const totalUnidades = arr.reduce((s, p) => s + (parseFloat(p.total) || 0), 0);
    const fecha = new Date().toLocaleString('es-CU', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit'
    });

    const filas = arr.map(p => `
        <tr>
            <td>${escapeHtml(p.name)}</td>
            <td class="num">${fmtQty(p.total)}</td>
        </tr>
    `).join('');

    const html = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen de cocina — Sesión #${SESSION_ID}</title>
<style>
    @page { size: auto; margin: 8mm 10mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #000; margin: 0; padding: 0; }
    h1 { font-size: 18px; margin: 0 0 4px 0; }
    .meta { font-size: 11px; color: #444; margin-bottom: 10px; border-bottom: 1px solid #999; padding-bottom: 6px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { padding: 5px 6px; border-bottom: 1px dashed #aaa; text-align: left; }
    th { background: #eee; font-size: 12px; text-transform: uppercase; border-bottom: 2px solid #000; }
    .num { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
    tfoot td { border-top: 2px solid #000; border-bottom: none; font-weight: 800; padding-top: 8px; }
    .footer { margin-top: 18px; font-size: 10px; color: #666; text-align: center; }
</style>
</head>
<body>
    <h1>Resumen de producción</h1>
    <div class="meta">
        Sesión #${SESSION_ID} · Sucursal <?= $idSucursal ?> · Tickets: ${TICKETS.length} · ${fecha}
    </div>
    <table>
        <thead>
            <tr><th>Producto</th><th class="num">Cantidad</th></tr>
        </thead>
        <tbody>${filas}</tbody>
        <tfoot>
            <tr>
                <td>TOTAL UNIDADES</td>
                <td class="num">${fmtQty(totalUnidades)}</td>
            </tr>
        </tfoot>
    </table>
    <div class="footer">Generado desde Pantalla de Cocina</div>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => { window.print(); }, 150);
        });
    <\/script>
</body>
</html>`;

    const w = window.open('', '_blank', 'width=720,height=900');
    if (!w) { alert('El navegador bloqueó la ventana emergente. Permítela e intenta de nuevo.'); return; }
    w.document.open();
    w.document.write(html);
    w.document.close();
}

renderProdBar();
renderTickets();

// Auto-refresh
let cd = 30;
setInterval(() => {
    cd--;
    const el = document.getElementById('countdown');
    if (el) el.textContent = cd;
    if (cd <= 0) location.reload();
}, 1000);

// Mantener pantalla encendida si el navegador lo soporta
if ('wakeLock' in navigator) {
    navigator.wakeLock.request('screen').catch(() => {});
}
</script>

<?php endif; ?>

</body>
</html>
