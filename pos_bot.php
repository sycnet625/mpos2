<?php
// ARCHIVO: pos_bot.php
// Dashboard unificado del módulo POS Bot / WhatsApp Bot

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'config_loader.php';

$botApi = 'posbot_api/router.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Bot Dashboard | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root{
            --bg:#08111f;
            --panel:#0f1b2d;
            --panel-2:#12213a;
            --line:#20314c;
            --text:#e5eefb;
            --muted:#8ca1c0;
            --green:#2dd4bf;
            --lime:#84cc16;
            --yellow:#f59e0b;
            --red:#ef4444;
            --blue:#38bdf8;
            --violet:#a78bfa;
        }
        body{background:radial-gradient(circle at top left,#10203b 0%, #08111f 45%, #050a14 100%); color:var(--text);}
        .shell{max-width:1600px;}
        .hero{
            background:linear-gradient(135deg, rgba(56,189,248,.18), rgba(167,139,250,.12));
            border:1px solid rgba(255,255,255,.08);
            border-radius:24px;
            box-shadow:0 18px 60px rgba(0,0,0,.28);
        }
        .panel{
            background:linear-gradient(180deg, rgba(15,27,45,.98), rgba(10,18,31,.98));
            border:1px solid rgba(255,255,255,.08);
            border-radius:20px;
            box-shadow:0 18px 50px rgba(0,0,0,.24);
        }
        .kpi{
            background:linear-gradient(180deg, rgba(18,33,58,.96), rgba(13,24,42,.96));
            border:1px solid rgba(255,255,255,.08);
            border-radius:18px;
            min-height:120px;
        }
        .kpi .value{font-size:2rem; font-weight:800; line-height:1;}
        .kpi .label{color:var(--muted); font-size:.8rem; text-transform:uppercase; letter-spacing:.08em;}
        .kpi .delta{font-size:.78rem; color:#cbd5e1;}
        .badge-soft{
            display:inline-flex; align-items:center; gap:.35rem;
            padding:.35rem .65rem; border-radius:999px; font-weight:700; font-size:.78rem;
            background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08);
        }
        .table-darkish{--bs-table-bg:transparent; --bs-table-color:var(--text); --bs-table-striped-bg:rgba(255,255,255,.02); color:var(--text);}
        .table-darkish thead th{color:#b7c8e1; border-bottom:1px solid rgba(255,255,255,.12)!important; font-size:.78rem; text-transform:uppercase; letter-spacing:.06em;}
        .table-darkish td, .table-darkish th{border-top:1px solid rgba(255,255,255,.08)!important;}
        .muted{color:var(--muted);}
        canvas{width:100%; height:240px; display:block; background:rgba(255,255,255,.02); border:1px solid rgba(255,255,255,.06); border-radius:16px;}
        .chart-wrap{padding:14px; background:rgba(255,255,255,.02); border:1px solid rgba(255,255,255,.06); border-radius:18px;}
        .scroll-x{overflow-x:auto;}
        .tag{display:inline-block; padding:.25rem .5rem; border-radius:999px; background:rgba(255,255,255,.08); color:#fff; font-size:.75rem; margin:0 .25rem .25rem 0;}
        .mini{font-size:.78rem;}
        .status-dot{width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:.4rem;}
        .status-ready{background:var(--green);}
        .status-warn{background:var(--yellow);}
        .status-bad{background:var(--red);}
        .status-off{background:#64748b;}
        .section-title{font-size:.82rem; text-transform:uppercase; letter-spacing:.1em; color:#94a3b8; font-weight:700;}
        .stuck{position:sticky; top:0; backdrop-filter:blur(10px); z-index:10;}
        .btn-glass{background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.12);}
        .btn-glass:hover{background:rgba(255,255,255,.12); color:#fff;}
        .footnote{color:#94a3b8; font-size:.78rem;}
        .skeleton{height:18px; border-radius:999px; background:linear-gradient(90deg,rgba(255,255,255,.05),rgba(255,255,255,.09),rgba(255,255,255,.05)); background-size:200% 100%; animation:shimmer 1.2s infinite linear;}
        @keyframes shimmer{0%{background-position:0 0}100%{background-position:-200% 0}}
        @media (max-width: 991px){ canvas{height:220px;} .value{font-size:1.7rem;} }
    </style>
</head>
<body>
<div class="shell container-fluid py-3 py-lg-4">
    <div class="hero panel p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-start align-items-lg-center">
            <div>
                <div class="section-title mb-2">Bots y notificaciones</div>
                <h1 class="h2 fw-bold mb-2">POS Bot Dashboard</h1>
                <div class="muted">Vista rápida de campañas, estado del bridge, errores y actividad por hora.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-glass" onclick="reloadAll()"><i class="fas fa-rotate me-2"></i>Actualizar</button>
                <button class="btn btn-outline-light" onclick="window.open('pos_bot_api.php','_blank')"><i class="fas fa-code me-2"></i>API</button>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="kpiRow">
        <div class="col-6 col-xl-2"><div class="kpi p-3"><div class="label">Campañas</div><div class="value" id="kpiCampaigns">—</div><div class="delta" id="kpiCampaignsSub">Cargando...</div></div></div>
        <div class="col-6 col-xl-2"><div class="kpi p-3"><div class="label">Programadas</div><div class="value" id="kpiScheduled">—</div><div class="delta">Con `schedule_enabled = 1`</div></div></div>
        <div class="col-6 col-xl-2"><div class="kpi p-3"><div class="label">En cola</div><div class="value" id="kpiQueued">—</div><div class="delta">Esperando ejecución</div></div></div>
        <div class="col-6 col-xl-2"><div class="kpi p-3"><div class="label">Errores</div><div class="value" id="kpiErrors">—</div><div class="delta">Últimas ejecuciones</div></div></div>
        <div class="col-6 col-xl-2"><div class="kpi p-3"><div class="label">Mensajes hoy</div><div class="value" id="kpiMsgsToday">—</div><div class="delta">Entrantes + salientes</div></div></div>
        <div class="col-6 col-xl-2"><div class="kpi p-3"><div class="label">Bridge</div><div class="value" id="kpiBridgeState">—</div><div class="delta" id="kpiBridgeMsg">—</div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="kpi p-3"><div class="label">Última ejecución</div><div class="value" style="font-size:1.2rem" id="kpiLastRun">—</div><div class="delta" id="kpiLastRunSub">Sin datos</div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi p-3"><div class="label">Errores 7 días</div><div class="value" id="kpiErrors7d">—</div><div class="delta">Suma de fallos en campañas</div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi p-3"><div class="label">Grupos distintos</div><div class="value" id="kpiGroups">—</div><div class="delta">Agrupación por campaign_group</div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi p-3"><div class="label">Destinos totales</div><div class="value" id="kpiTargets">—</div><div class="delta">Suma de chats/grupos configurados</div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="panel p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <div class="section-title">Histórico</div>
                        <h2 class="h5 fw-bold mb-0">Mensajes por hora y actividad de campañas</h2>
                    </div>
                    <span class="badge-soft" id="histogramMeta">últimas 24h</span>
                </div>
                <div class="chart-wrap mb-3">
                    <canvas id="hourChart" width="1200" height="280"></canvas>
                </div>
                <div class="chart-wrap">
                    <canvas id="campaignBarChart" width="1200" height="280"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="panel p-3 h-100">
                <div class="section-title">Bridge y errores</div>
                <h2 class="h5 fw-bold mb-3">Estado operativo</h2>
                <div class="mb-3" id="bridgeBox">
                    <div class="skeleton mb-2" style="width:60%"></div>
                    <div class="skeleton mb-2" style="width:85%"></div>
                    <div class="skeleton mb-2" style="width:70%"></div>
                </div>
                <div class="mb-2">
                    <div class="section-title mb-2">Tipos de error frecuentes</div>
                    <div id="errorTags"></div>
                </div>
                <div class="footnote mt-3">Se muestran los patrones más repetidos extraídos de los logs de campañas y mensajes.</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="panel p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <div class="section-title">Campañas</div>
                        <h2 class="h5 fw-bold mb-0">Resumen por campaña</h2>
                    </div>
                    <span class="badge-soft" id="campaignRowsMeta">0 campañas</span>
                </div>
                <div class="scroll-x">
                    <table class="table table-sm table-darkish align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Campaña</th>
                                <th>Estado</th>
                                <th>Última ejecución</th>
                                <th>Errores</th>
                                <th>Éxito</th>
                            </tr>
                        </thead>
                        <tbody id="campaignRows">
                            <tr><td colspan="5" class="text-center muted py-4">Cargando campañas...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="panel p-3 h-100">
                <div class="section-title">Grupos</div>
                <h2 class="h5 fw-bold mb-3">Histograma por grupo</h2>
                <div class="chart-wrap mb-3">
                    <canvas id="groupChart" width="1200" height="280"></canvas>
                </div>
                <div id="groupTags" class="mini"></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="panel p-3 h-100">
                <div class="section-title">Mensajería</div>
                <h2 class="h5 fw-bold mb-3">Conversaciones activas y resumen</h2>
                <div class="scroll-x">
                    <table class="table table-sm table-darkish align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Contacto</th>
                                <th>Mensajes</th>
                                <th>Pedidos</th>
                                <th>Último evento</th>
                            </tr>
                        </thead>
                        <tbody id="conversationRows"><tr><td colspan="4" class="text-center muted py-4">Cargando...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="panel p-3 h-100">
                <div class="section-title">Top</div>
                <h2 class="h5 fw-bold mb-3">Campañas más activas</h2>
                <div id="topCampaignCards" class="d-grid gap-2"></div>
            </div>
        </div>
    </div>

    <div class="panel p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <div class="section-title">Actividad reciente</div>
                <h2 class="h5 fw-bold mb-0">Eventos y mensajes recientes</h2>
            </div>
            <span class="badge-soft">últimos 120</span>
        </div>
        <div class="scroll-x">
            <table class="table table-sm table-darkish align-middle mb-0">
                <thead>
                    <tr><th>Fecha</th><th>Tipo</th><th>Campaña / Canal</th><th>Detalle</th></tr>
                </thead>
                <tbody id="recentEventRows"><tr><td colspan="4" class="text-center muted py-4">Cargando...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const API = <?= json_encode($botApi) ?>;
const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const state = { campaigns: [], messages: [], conversations: [], bridge: null, stats: null, recentOrders: [] };

async function api(url) {
    const r = await fetch(url, { credentials: 'same-origin' });
    const txt = await r.text();
    try { return JSON.parse(txt); } catch (_) { return { status: 'error', raw: txt, msg: 'Respuesta inválida' }; }
}

function fmt(n) {
    return new Intl.NumberFormat('es-ES').format(Number(n || 0));
}

function badgeForStatus(status) {
    const s = String(status || '').toLowerCase();
    if (['running','queued','waiting','scheduled'].includes(s)) return `<span class="badge text-bg-warning">${esc(status || '-')}</span>`;
    if (['done','success'].includes(s)) return `<span class="badge text-bg-success">${esc(status || '-')}</span>`;
    if (['error','failed'].includes(s)) return `<span class="badge text-bg-danger">${esc(status || '-')}</span>`;
    if (s === 'paused') return `<span class="badge text-bg-secondary">${esc(status || '-')}</span>`;
    return `<span class="badge text-bg-dark">${esc(status || '-')}</span>`;
}

function parseCampaignLogs(job) {
    const logs = Array.isArray(job?.log) ? job.log : [];
    const items = Array.isArray(job?.targets) ? job.targets.length : 0;
    const sent = logs.reduce((a, l) => a + Number(l?.messages_sent || 0), 0);
    const errors = logs.filter(l => l && l.ok === false).length;
    const last = logs.length ? logs[logs.length - 1] : null;
    const lastRun = String(job?.last_run_finished_at || job?.last_run_started_at || last?.at || '');
    const errorCounts = {};
    logs.filter(l => l && l.ok === false).forEach(l => {
        const msg = String(l.error || 'Error desconocido').split('\n')[0].slice(0, 120);
        errorCounts[msg] = (errorCounts[msg] || 0) + 1;
    });
    return { logs, items, sent, errors, lastRun, errorCounts };
}

function aggregateErrors(campaigns) {
    const map = {};
    campaigns.forEach(job => {
        const data = parseCampaignLogs(job);
        Object.entries(data.errorCounts).forEach(([k, v]) => map[k] = (map[k] || 0) + v);
        (Array.isArray(job.log) ? job.log : []).forEach(l => {
            if (!l || l.ok !== false) return;
            const msg = String(l.error || '').trim();
            const key = msg ? msg.slice(0, 80) : 'Error sin detalle';
            map[key] = (map[key] || 0) + 1;
        });
    });
    return Object.entries(map).sort((a, b) => b[1] - a[1]).slice(0, 8);
}

function drawBarChart(canvas, labels, values, options = {}) {
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.clientWidth || 1200;
    const cssH = canvas.clientHeight || 280;
    canvas.width = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);
    ctx.fillStyle = 'rgba(255,255,255,.03)';
    ctx.fillRect(0, 0, cssW, cssH);
    ctx.strokeStyle = 'rgba(255,255,255,.08)';
    for (let i = 0; i < 5; i++) {
        const y = 18 + ((cssH - 38) / 4) * i;
        ctx.beginPath();
        ctx.moveTo(12, y);
        ctx.lineTo(cssW - 12, y);
        ctx.stroke();
    }
    const max = Math.max(1, ...values);
    const padX = 16;
    const chartW = cssW - padX * 2;
    const chartH = cssH - 54;
    const barW = Math.max(8, chartW / Math.max(1, values.length) * 0.72);
    const gap = chartW / Math.max(1, values.length);
    values.forEach((v, i) => {
        const x = padX + i * gap + (gap - barW) / 2;
        const h = (v / max) * chartH;
        const y = cssH - 24 - h;
        const grad = ctx.createLinearGradient(0, y, 0, cssH - 24);
        grad.addColorStop(0, options.colorTop || '#38bdf8');
        grad.addColorStop(1, options.colorBottom || '#2563eb');
        ctx.fillStyle = grad;
        ctx.fillRect(x, y, barW, h);
        ctx.fillStyle = '#cbd5e1';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(String(v), x + barW / 2, y - 4);
        ctx.save();
        ctx.translate(x + barW / 2, cssH - 8);
        ctx.rotate(-0.35);
        ctx.fillStyle = '#94a3b8';
        ctx.font = '11px sans-serif';
        ctx.fillText(String(labels[i] || ''), 0, 0);
        ctx.restore();
    });
}

function drawLineChart(canvas, labels, values, options = {}) {
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.clientWidth || 1200;
    const cssH = canvas.clientHeight || 280;
    canvas.width = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);
    ctx.fillStyle = 'rgba(255,255,255,.03)';
    ctx.fillRect(0, 0, cssW, cssH);
    const max = Math.max(1, ...values);
    const min = 0;
    const padX = 22;
    const padY = 20;
    const w = cssW - padX * 2;
    const h = cssH - padY * 2 - 20;
    ctx.strokeStyle = 'rgba(255,255,255,.08)';
    for (let i = 0; i < 5; i++) {
        const y = padY + (h / 4) * i;
        ctx.beginPath();
        ctx.moveTo(padX, y);
        ctx.lineTo(cssW - padX, y);
        ctx.stroke();
    }
    const pts = values.map((v, i) => ({
        x: padX + (values.length <= 1 ? w / 2 : (w * i / (values.length - 1))),
        y: padY + h - ((v - min) / (max - min)) * h
    }));
    ctx.beginPath();
    pts.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
    ctx.lineWidth = 3;
    ctx.strokeStyle = options.stroke || '#2dd4bf';
    ctx.stroke();
    const grad = ctx.createLinearGradient(0, padY, 0, padY + h);
    grad.addColorStop(0, (options.fillTop || 'rgba(45,212,191,.32)'));
    grad.addColorStop(1, (options.fillBottom || 'rgba(45,212,191,0)'));
    ctx.lineTo(pts[pts.length - 1]?.x || padX, padY + h);
    ctx.lineTo(pts[0]?.x || padX, padY + h);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();
    pts.forEach((p, i) => {
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.beginPath();
        ctx.arc(p.x, p.y, 2.5, 0, Math.PI * 2);
        ctx.fillStyle = options.stroke || '#2dd4bf';
        ctx.fill();
    });
    ctx.fillStyle = '#94a3b8';
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'center';
    labels.forEach((label, i) => {
        if (i % 2 && labels.length > 12) return;
        const x = pts[i]?.x ?? padX;
        ctx.fillText(label, x, cssH - 6);
    });
}

function renderBridgeBox(bridge) {
    const box = document.getElementById('bridgeBox');
    if (!box) return;
    const state = String(bridge?.state || 'unknown');
    const running = !!bridge?.running;
    const ok = !!bridge?.ok;
    const stateClass = ok ? 'status-ready' : (running ? 'status-warn' : 'status-off');
    const stateLabel = ok ? 'Conectado' : (running ? 'Con proceso activo' : 'Detenido');
    const updated = bridge?.updated_at ? new Date(bridge.updated_at).toLocaleString() : 'N/D';
    const msg = String(bridge?.msg || 'Sin detalle');
    box.innerHTML = `
        <div class="mb-2"><span class="status-dot ${stateClass}"></span><strong>${esc(stateLabel)}</strong> <span class="muted">(${esc(state)})</span></div>
        <div class="mb-2 mini"><span class="badge-soft">Proceso: ${running ? 'Sí' : 'No'}</span> <span class="badge-soft ms-1">Sesión: ${bridge?.session_ok ? 'OK' : 'No'}</span></div>
        <div class="mini mb-2"><strong>Mensaje:</strong> ${esc(msg)}</div>
        <div class="mini"><strong>Actualizado:</strong> ${esc(updated)}</div>
    `;
}

function renderCampaignTable(campaigns) {
    const tbody = document.getElementById('campaignRows');
    if (!tbody) return;
    document.getElementById('campaignRowsMeta').textContent = `${campaigns.length} campañas`;
    if (!campaigns.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center muted py-4">No hay campañas programadas.</td></tr>';
        return;
    }
    tbody.innerHTML = campaigns.map(job => {
        const data = parseCampaignLogs(job);
        const last = job.last_run_finished_at || job.last_run_started_at || (job.log && job.log.length ? job.log[job.log.length - 1].at : '');
        const err = data.errors;
        const sent = data.sent;
        return `
            <tr>
                <td>
                    <div class="fw-semibold">${esc(job.name || job.id || '-')}</div>
                    <div class="muted mini">${esc(job.campaign_group || 'General')} · ${esc(Array.isArray(job.targets) ? job.targets.length : 0)} destinos</div>
                </td>
                <td>${badgeForStatus(job.status || '-')}</td>
                <td class="mini">${esc(last || 'N/D')}</td>
                <td>
                    <div class="fw-semibold ${err ? 'text-danger' : 'text-success'}">${fmt(err)}</div>
                    <div class="muted mini">${esc(job.last_run_summary || job.queue_note || '')}</div>
                </td>
                <td>
                    <div class="fw-semibold">${fmt(sent)}</div>
                    <div class="muted mini">${job.schedule_enabled ? 'Programada' : 'Manual'}</div>
                </td>
            </tr>
        `;
    }).join('');
}

function renderCampaignCards(campaigns) {
    const wrap = document.getElementById('topCampaignCards');
    if (!wrap) return;
    const sorted = campaigns.slice().sort((a, b) => (parseCampaignLogs(b).sent - parseCampaignLogs(a).sent));
    const top = sorted.slice(0, 5);
    wrap.innerHTML = top.length ? top.map(job => {
        const data = parseCampaignLogs(job);
        return `
            <div class="p-3 rounded-4" style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);">
                <div class="d-flex justify-content-between gap-2 align-items-start">
                    <div>
                        <div class="fw-semibold">${esc(job.name || job.id || '-')}</div>
                        <div class="muted mini">${esc(job.campaign_group || 'General')}</div>
                    </div>
                    ${badgeForStatus(job.status || '-')}
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2 mini">
                    <span class="badge-soft">Mensajes: ${fmt(data.sent)}</span>
                    <span class="badge-soft">Errores: ${fmt(data.errors)}</span>
                    <span class="badge-soft">Destinos: ${fmt(data.items)}</span>
                </div>
            </div>
        `;
    }).join('') : '<div class="muted">Sin campañas activas.</div>';
}

function renderCampaignRunKPIs(campaigns) {
    const lastTimes = [];
    let errors7d = 0;
    const groups = new Set();
    let targets = 0;
    campaigns.forEach(job => {
        groups.add(String(job.campaign_group || 'General'));
        targets += Array.isArray(job.targets) ? job.targets.length : 0;
        const logs = Array.isArray(job.log) ? job.log : [];
        logs.forEach(l => {
            if (!l || l.ok !== false) return;
            const at = new Date(String(l.at || '').replace(' ', 'T'));
            if (!isNaN(at.getTime())) {
                const diffDays = (Date.now() - at.getTime()) / 86400000;
                if (diffDays <= 7) errors7d += 1;
            } else {
                errors7d += 1;
            }
        });
        const last = job.last_run_finished_at || job.last_run_started_at || (logs.length ? logs[logs.length - 1].at : '');
        if (last) lastTimes.push({ at: last, job });
    });
    lastTimes.sort((a, b) => String(b.at).localeCompare(String(a.at)));
    const last = lastTimes[0];
    const lastLabel = last ? new Date(String(last.at).replace(' ', 'T')).toLocaleString() : 'Sin ejecuciones';
    document.getElementById('kpiLastRun').textContent = last ? last.job.last_run_status || last.job.status || 'run' : '—';
    document.getElementById('kpiLastRunSub').textContent = lastLabel;
    document.getElementById('kpiErrors7d').textContent = fmt(errors7d);
    document.getElementById('kpiGroups').textContent = fmt(groups.size);
    document.getElementById('kpiTargets').textContent = fmt(targets);
}

function renderGroupStats(campaigns) {
    const map = {};
    campaigns.forEach(job => {
        const g = String(job.campaign_group || 'General');
        if (!map[g]) map[g] = { sent: 0, errors: 0, count: 0 };
        const d = parseCampaignLogs(job);
        map[g].sent += d.sent;
        map[g].errors += d.errors;
        map[g].count += 1;
    });
    const entries = Object.entries(map).sort((a, b) => b[1].sent - a[1].sent);
    const labels = entries.map(e => e[0]).slice(0, 12);
    const values = entries.map(e => e[1].sent).slice(0, 12);
    drawBarChart(document.getElementById('groupChart'), labels, values, { colorTop: '#a78bfa', colorBottom: '#7c3aed' });
    const tags = document.getElementById('groupTags');
    tags.innerHTML = entries.slice(0, 8).map(([name, v]) => `<span class="tag">${esc(name)} · ${fmt(v.sent)} msgs · ${fmt(v.errors)} err</span>`).join(' ') || '<span class="muted">Sin grupos.</span>';
}

function renderHistograms(messages, campaigns) {
    const hourMap = new Array(24).fill(0);
    const msgByType = { in: 0, out: 0 };
    const msgByHour = new Array(24).fill(0);
    (messages || []).forEach(m => {
        const d = new Date(String(m.created_at || '').replace(' ', 'T'));
        if (!isNaN(d.getTime())) {
            hourMap[d.getHours()] += 1;
            msgByHour[d.getHours()] += 1;
        }
        const dir = String(m.direction || '').toLowerCase();
        if (dir === 'in' || dir === 'out') msgByType[dir] += 1;
    });
    const labels = Array.from({length:24}, (_, i) => String(i).padStart(2, '0') + ':00');
    drawLineChart(document.getElementById('hourChart'), labels, hourMap, { stroke: '#2dd4bf' });
    const campValues = campaigns.slice().sort((a,b) => parseCampaignLogs(b).sent - parseCampaignLogs(a).sent).slice(0, 12).map(job => parseCampaignLogs(job).sent);
    const campLabels = campaigns.slice().sort((a,b) => parseCampaignLogs(b).sent - parseCampaignLogs(a).sent).slice(0, 12).map(job => (job.name || job.id || '-').slice(0, 12));
    drawBarChart(document.getElementById('campaignBarChart'), campLabels, campValues, { colorTop: '#38bdf8', colorBottom: '#0ea5e9' });
    document.getElementById('histogramMeta').textContent = `24h · In ${fmt(msgByType.in)} / Out ${fmt(msgByType.out)}`;
}

function renderConversations(rows) {
    const tb = document.getElementById('conversationRows');
    if (!tb) return;
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="4" class="text-center muted py-4">Sin conversaciones activas.</td></tr>';
        return;
    }
    tb.innerHTML = rows.slice(0, 20).map(r => `
        <tr>
            <td>
                <div class="fw-semibold">${esc(r.wa_name || r.customer_name || r.wa_user_id || '-')}</div>
                <div class="muted mini">${esc(r.phone || '')}</div>
            </td>
            <td>
                <div class="fw-semibold">${fmt(r.msg_count || 0)}</div>
                <div class="muted mini">In ${fmt(r.in_count || 0)} · Out ${fmt(r.out_count || 0)} · Dudosas ${fmt(r.misunderstood_count || 0)}</div>
            </td>
            <td>
                <div class="fw-semibold">${fmt(r.orders_count || 0)}</div>
                <div class="muted mini">$${fmt(r.total_spent || 0)}</div>
            </td>
            <td class="mini">
                <div><strong>Seen:</strong> ${esc(r.last_seen || 'N/D')}</div>
                <div><strong>Msg:</strong> ${esc(r.last_message_at || 'N/D')}</div>
                <div><strong>Order:</strong> ${esc(r.last_order_at || 'N/D')}</div>
            </td>
        </tr>
    `).join('');
}

function renderRecentEvents(messages, campaigns, orders) {
    const rows = [];
    (messages || []).slice(0, 50).forEach(m => rows.push({
        at: m.created_at,
        type: `msg ${m.direction || ''}`,
        source: m.wa_user_id || '',
        detail: (m.msg_type || 'text') + ' · ' + String(m.message_text || '').slice(0, 120)
    }));
    (orders || []).slice(0, 20).forEach(o => rows.push({
        at: o.created_at,
        type: 'order',
        source: o.wa_user_id || '',
        detail: `Pedido #${o.id_pedido || o.id || '-'} · $${fmt(o.total || 0)}`
    }));
    campaigns.slice(0, 20).forEach(job => {
        const d = parseCampaignLogs(job);
        if (job.forced_at) rows.push({
            at: job.forced_at,
            type: 'campaign force',
            source: job.campaign_group || 'General',
            detail: `${job.name || job.id || '-'} · ${fmt(d.sent)} msgs · ${fmt(d.errors)} err`
        });
        if (job.last_run_finished_at) rows.push({
            at: job.last_run_finished_at,
            type: 'campaign finish',
            source: job.campaign_group || 'General',
            detail: `${job.name || job.id || '-'} · ${job.last_run_status || job.status || '-'}`
        });
    });
    rows.sort((a, b) => String(b.at || '').localeCompare(String(a.at || '')));
    const tb = document.getElementById('recentEventRows');
    if (!tb) return;
    tb.innerHTML = rows.slice(0, 120).map(r => `
        <tr>
            <td class="mini">${esc(r.at || 'N/D')}</td>
            <td><span class="badge text-bg-dark">${esc(r.type)}</span></td>
            <td class="mini">${esc(r.source || '')}</td>
            <td class="mini">${esc(r.detail || '')}</td>
        </tr>
    `).join('') || '<tr><td colspan="4" class="text-center muted py-4">Sin eventos.</td></tr>';
}

function renderErrorTags(campaigns) {
    const err = aggregateErrors(campaigns);
    const wrap = document.getElementById('errorTags');
    if (!wrap) return;
    wrap.innerHTML = err.length ? err.map(([k, v]) => `<div class="tag mb-2 me-2" style="background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.2)">${esc(k)} · ${fmt(v)}</div>`).join('') : '<div class="muted">Sin errores recientes.</div>';
}

async function loadAll() {
    document.getElementById('kpiCampaigns').textContent = '…';
    const [statsRes, bridgeRes, promoListRes, convRes, msgRes, orderRes] = await Promise.all([
        api(API + '?action=stats'),
        api(API + '?action=promo_validate_bridge'),
        api(API + '?action=promo_list'),
        api(API + '?action=client_activity_list'),
        api(API + '?action=recent_messages'),
        api(API + '?action=recent_orders')
    ]);

    state.stats = statsRes;
    state.bridge = bridgeRes?.bridge || null;
    state.campaigns = Array.isArray(promoListRes?.rows) ? promoListRes.rows : [];
    state.conversations = Array.isArray(convRes?.rows) ? convRes.rows : [];
    state.messages = Array.isArray(msgRes?.rows) ? msgRes.rows : [];
    state.recentOrders = Array.isArray(orderRes?.rows) ? orderRes.rows : [];

    const campaignCount = state.campaigns.length;
    const scheduled = state.campaigns.filter(j => Number(j.schedule_enabled || 0) === 1).length;
    const queued = state.campaigns.filter(j => ['queued','running','waiting','scheduled'].includes(String(j.status || '').toLowerCase())).length;
    const errors = state.campaigns.reduce((acc, j) => acc + parseCampaignLogs(j).errors, 0);
    const msgsToday = Number(statsRes?.stats?.msgs_today || 0);

    document.getElementById('kpiCampaigns').textContent = fmt(campaignCount);
    document.getElementById('kpiCampaignsSub').textContent = `${fmt(scheduled)} programadas · ${fmt(queued)} activas`;
    document.getElementById('kpiScheduled').textContent = fmt(scheduled);
    document.getElementById('kpiQueued').textContent = fmt(queued);
    document.getElementById('kpiErrors').textContent = fmt(errors);
    document.getElementById('kpiMsgsToday').textContent = fmt(msgsToday);

    const bridge = state.bridge || {};
    const bridgeOk = !!bridge.ok;
    document.getElementById('kpiBridgeState').textContent = bridgeOk ? 'OK' : String(bridge.state || '—').toUpperCase().slice(0, 7);
    document.getElementById('kpiBridgeMsg').textContent = bridge.msg || 'Sin detalle';
    document.getElementById('kpiBridgeState').parentElement.parentElement.style.borderColor = bridgeOk ? 'rgba(45,212,191,.5)' : 'rgba(239,68,68,.45)';

    renderBridgeBox(bridge);
    renderCampaignTable(state.campaigns);
    renderCampaignCards(state.campaigns);
    renderCampaignRunKPIs(state.campaigns);
    renderGroupStats(state.campaigns);
    renderHistograms(state.messages, state.campaigns);
    renderConversations(state.conversations);
    renderErrorTags(state.campaigns);
    renderRecentEvents(state.messages, state.campaigns, state.recentOrders);
}

function reloadAll(){ loadAll().catch(err => console.error(err)); }
window.addEventListener('resize', () => {
    if (state.campaigns.length || state.messages.length) {
        renderGroupStats(state.campaigns);
        renderHistograms(state.messages, state.campaigns);
    }
});
reloadAll();
</script>
</body>
</html>
