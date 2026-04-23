<?php
// ARCHIVO: fb_bot_premium.php
// POS BOT Facebook - Versión con Rediseño Premium Inventory-Suite

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'config_loader.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facebook Bot Premium | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .inventory-hero {
            background: linear-gradient(135deg, #1877f2ee, #054a91c6) !important;
        }
        .social-preview {
            background: #fff;
            border-radius: 1.2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.05);
        }
        .social-preview-header {
            display: flex; align-items: center; gap: 12px; padding: 1rem; border-bottom: 1px solid #f1f5f9;
        }
        .social-avatar {
            width: 45px; height: 45px; border-radius: 50%;
            background: linear-gradient(45deg, #1877f2, #00c6ff);
            display: grid; place-items: center; color: white; font-weight: 800;
        }
        .social-media-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 4px; }
        .social-media-grid img { width: 100%; height: 180px; object-fit: cover; }
        
        .fb-console-premium {
            background: #0f172a; color: #38bdf8; border-radius: 1rem; padding: 1.5rem;
            font-family: 'Fira Code', monospace; font-size: 0.85rem; max-height: 500px; overflow: auto;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1);
        }
        .nav-pills-premium .nav-link {
            border-radius: 0.8rem; padding: 0.6rem 1.2rem; color: var(--pw-text-main); font-weight: 600;
            transition: all 0.2s; border: 1px solid transparent; margin-right: 0.5rem;
        }
        .nav-pills-premium .nav-link.active {
            background: var(--pw-accent); color: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .status-led {
            width: 10px; height: 10px; border-radius: 50%; display: inline-block;
            box-shadow: 0 0 8px currentColor;
        }
        .app-toast-stack {
            position: fixed; left: 50%; bottom: 2rem; transform: translateX(-50%);
            z-index: 9999; width: 90%; max-width: 500px; display: flex; flex-direction: column; gap: 0.5rem;
        }
        .app-toast {
            background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); color: white;
            padding: 1rem 1.5rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            font-weight: 600; animation: toastIn 0.3s ease-out forwards;
        }
        @keyframes toastIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div id="toastStack" class="app-toast-stack"></div>

<div class="container-fluid shell inventory-shell py-4 py-lg-5">
    
    <!-- Hero Section -->
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-center">
            <div class="text-center text-lg-start">
                <div class="section-title text-white-50 mb-2">Marketing / Automatización</div>
                <h1 class="h2 fw-bold mb-2 text-white"><i class="fab fa-facebook-messenger me-2"></i>POS BOT Facebook Premium</h1>
                <p class="mb-3 text-white-50">Gestión avanzada de publicaciones, campañas programadas y analítica de engagement.</p>
                <div class="d-flex flex-wrap justify-content-center justify-content-lg-start gap-2">
                    <span class="kpi-chip"><i class="fas fa-paper-plane me-1"></i>Hoy: <span id="s1">0</span></span>
                    <span class="kpi-chip"><i class="fab fa-facebook me-1"></i>FB: <span id="s2">0</span></span>
                    <span class="kpi-chip"><i class="fab fa-instagram me-1"></i>IG: <span id="s3">0</span></span>
                    <span class="kpi-chip"><i class="fas fa-signal me-1"></i><span id="s4">Online</span></span>
                </div>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
                <button class="btn btn-light shadow-sm" onclick="loadAll()"><i class="fas fa-sync me-2"></i>Sincronizar Todo</button>
                <div class="glass-card px-3 py-2 d-flex align-items-center gap-2 bg-white bg-opacity-10 text-white small border-0">
                    <span id="fbLedDot" class="status-led text-success"></span>
                    <span id="fbLedText" class="fw-bold">Servicio Activo</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Navigation -->
    <ul class="nav nav-pills nav-pills-premium mb-4 no-print" id="botTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-bot" type="button">Canales API</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-promo" type="button">Campañas</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-mi-pagina" type="button">Publicación Diaria</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-programacion" type="button">Programación Pro</button></li>
    </ul>

    <div class="tab-content">
        <!-- TAB: BOT / CONFIG -->
        <div class="tab-pane fade show active" id="tab-bot">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="glass-card p-4 h-100">
                        <div class="section-title">Integración</div>
                        <h2 class="h5 fw-bold mb-4">Meta Graph API Setup</h2>
                        
                        <div class="alert alert-primary bg-opacity-10 border-0 mb-4 p-3 rounded-4">
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-primary btn-sm" onclick="connectFacebookBrowser()"><i class="fab fa-facebook me-1"></i>Link Browser</button>
                                <button class="btn btn-outline-primary btn-sm" onclick="openFacebookViewer(true)"><i class="fas fa-desktop me-1"></i>Visual Login</button>
                                <button class="btn btn-success btn-sm" onclick="runQueue()"><i class="fas fa-play me-1"></i>Run Queue</button>
                                <button class="btn btn-dark btn-sm" onclick="openLogsModal()"><i class="fas fa-terminal me-1"></i>Console</button>
                            </div>
                            <div id="fbBrowserStatus" class="tiny mt-2 text-primary fw-bold">Login por navegador inactivo.</div>
                        </div>

                        <form id="f" class="row g-3">
                            <div class="col-12 form-check form-switch mb-2">
                                <input class="form-check-input" id="enabled" type="checkbox">
                                <label class="form-check-label fw-bold" for="enabled">Habilitar Publicador Global</label>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Nombre Negocio</label>
                                <input id="business_name" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Página Destino</label>
                                <input id="page_name" class="form-control" placeholder="Nombre visible">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Page ID</label>
                                <input id="page_id" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Graph Version</label>
                                <input id="graph_version" class="form-control" value="v23.0">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Page Access Token</label>
                                <input id="page_access_token" type="password" class="form-control" placeholder="••••••••••••••••">
                            </div>
                            
                            <div class="col-12 border-top pt-3 mt-3">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" id="enable_instagram" type="checkbox">
                                    <label class="form-check-label fw-bold" for="enable_instagram">Mirror a Instagram Profesional</label>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label small fw-bold">IG User ID</label><input id="ig_user_id" class="form-control"></div>
                                    <div class="col-md-6"><label class="form-label small fw-bold">IG Handle</label><input id="ig_username" class="form-control" placeholder="@usuario"></div>
                                </div>
                            </div>

                            <div class="col-12 pt-3">
                                <button class="btn btn-primary w-100 fw-bold py-2" type="submit"><i class="fas fa-save me-2"></i>GUARDAR CONFIGURACIÓN</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="glass-card p-0 overflow-hidden h-100">
                        <div class="p-4 border-bottom">
                            <div class="section-title">Actividad</div>
                            <h2 class="h5 fw-bold mb-0">Publicaciones Recientes</h2>
                        </div>
                        <div class="table-responsive" style="max-height: 400px;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr><th>Fecha</th><th>Canal</th><th>Estado</th></tr>
                                </thead>
                                <tbody id="recentPostsRows"></tbody>
                            </table>
                        </div>
                        <div class="p-4 bg-light bg-opacity-50">
                            <div class="section-title">Detalle del Payload</div>
                            <pre id="lastPostPreview" class="fb-console-premium mb-0 small">Sin actividad seleccionada.</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: CAMPAÑAS (Placeholder for simplicity, same as originals) -->
        <div class="tab-pane fade" id="tab-promo">
            <div class="glass-card p-4">
                <div class="section-title">Builder</div>
                <h2 class="h5 fw-bold mb-4">Gestión de Campañas de Marketing</h2>
                <div class="alert alert-info border-0 bg-primary bg-opacity-10 text-primary rounded-4">
                    <i class="fas fa-info-circle me-2"></i> Usa esta sección para programar envíos masivos a grupos y páginas.
                </div>
                <div class="text-center py-5">
                    <i class="fas fa-bullhorn fa-3x text-muted opacity-25 mb-3"></i>
                    <p class="text-muted">Cargando módulos de campaña...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const API='fb_bot_api.php';
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

function showToast(type,msg){
    const holder=document.getElementById('toastStack');
    if(!holder) return;
    const toast=document.createElement('div');
    toast.className=`app-toast shadow-lg`;
    toast.style.borderLeft=`5px solid ${type==='danger'?'#ef4444':'#10b981'}`;
    toast.innerHTML=esc(msg);
    holder.appendChild(toast);
    setTimeout(()=> { toast.style.opacity='0'; setTimeout(()=>toast.remove(), 500); }, 4000);
}
const a=(t,m)=>showToast(t,m);

async function parseApiResponse(r){const txt=await r.text();try{return JSON.parse(txt);}catch(_){return {status:'error',msg:'Error servidor',raw:txt};}}
async function g(u){const r=await fetch(u,{credentials:'same-origin'});return parseApiResponse(r)}
async function p(u,d){const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});return parseApiResponse(r)}

async function loadCfg(){
  const d=await g(API+'?action=get_config');
  if(d.status!=='success') return;
  const c=d.config||{};
  document.getElementById('enabled').checked=Number(c.enabled)===1;
  document.getElementById('business_name').value=c.business_name||'';
  document.getElementById('page_name').value=c.page_name||'';
  document.getElementById('page_id').value=c.page_id||'';
  document.getElementById('graph_version').value=c.graph_version||'v23.0';
  document.getElementById('enable_instagram').checked=Number(c.enable_instagram)===1;
  document.getElementById('ig_username').value=c.ig_username||'';
  document.getElementById('ig_user_id').value=c.ig_user_id||'';
}

async function loadStats(){
  const d=await g(API+'?action=stats');
  if(d.status!=='success') return;
  document.getElementById('s1').textContent=d.stats.posts_today||0;
  document.getElementById('s2').textContent=d.stats.facebook_today||0;
  document.getElementById('s3').textContent=d.stats.instagram_today||0;
  document.getElementById('s4').textContent=Number(d.stats.enabled||0)===1?'Online':'Offline';
}

async function loadRecentPosts(){
  const d=await g(API+'?action=recent_posts');
  const tb=document.getElementById('recentPostsRows');
  if(!d.rows || !d.rows.length){ tb.innerHTML='<tr><td colspan="3" class="text-center text-muted py-4">Sin posts</td></tr>'; return; }
  tb.innerHTML=d.rows.map((r,idx)=>`
    <tr onclick="showPostDetail(${idx})" style="cursor:pointer">
        <td class="tiny">${esc(r.created_at)}</td>
        <td><span class="badge ${r.platform==='instagram'?'bg-danger':'bg-primary'} small">${esc(r.platform)}</span></td>
        <td><span class="soft-pill ${r.status==='success'?'bg-success':'bg-danger'} text-white">${esc(r.status)}</span></td>
    </tr>
  `).join('');
  window.recentPosts = d.rows;
}

function showPostDetail(idx){
  const r = window.recentPosts[idx]; if(!r) return;
  document.getElementById('lastPostPreview').textContent=`[${r.created_at}] ${r.platform.toUpperCase()}\nStatus: ${r.status}\nPost ID: ${r.fb_post_id||'N/A'}\n\nMessage:\n${r.message_text||'-'}`;
}

async function loadAll(){ await Promise.all([loadCfg(), loadStats(), loadRecentPosts()]); }

document.getElementById('f').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const d=await p(API+'?action=save_config', {
        enabled: document.getElementById('enabled').checked?1:0,
        business_name: document.getElementById('business_name').value,
        page_name: document.getElementById('page_name').value,
        page_id: document.getElementById('page_id').value,
        graph_version: document.getElementById('graph_version').value,
        page_access_token: document.getElementById('page_access_token').value,
        enable_instagram: document.getElementById('enable_instagram').checked?1:0,
        ig_user_id: document.getElementById('ig_user_id').value,
        ig_username: document.getElementById('ig_username').value
    });
    if(d.status==='success'){ a('success','Configuración guardada'); loadCfg(); } else a('danger', d.msg);
});

loadAll();
setInterval(loadStats, 30000);
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
