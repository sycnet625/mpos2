<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS BOT Facebook</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<style>
body{background:#f6f8fc}
.card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.stat{font-size:1.6rem;font-weight:700}
.kpi-btn.active{outline:2px solid #0f172a;transform:translateY(-1px)}
pre.fb-console{white-space:pre-wrap;background:#0f172a;color:#dbeafe;padding:10px;border-radius:8px;max-height:60vh;overflow:auto}
</style>
</head>
<body class="p-3 p-md-4">
<div class="container-fluid" style="max-width:1400px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0"><i class="fab fa-facebook text-primary"></i> POS BOT Facebook</h4>
      <small class="text-muted">Publicaciones, campañas y programación automática en Facebook</small>
    </div>
    <button class="btn btn-outline-secondary" onclick="loadAll()"><i class="fas fa-sync"></i> Refrescar</button>
  </div>

  <div id="alertBox"></div>

  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Publicaciones hoy</div><div id="s1" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Facebook hoy</div><div id="s2" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Instagram hoy</div><div id="s3" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Estado</div><div id="s4" class="stat">-</div></div></div>
    <div class="col-12">
      <div class="card p-3">
        <div class="d-flex align-items-center gap-2">
          <span class="small text-muted">Estado Facebook Publisher</span>
          <span id="fbLedDot" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#64748b;box-shadow:0 0 0 4px rgba(100,116,139,.2)"></span>
          <span id="fbLedText" class="fw-semibold" style="color:#334155">Sin datos</span>
        </div>
      </div>
    </div>
  </div>

  <ul class="nav nav-tabs mb-3" id="botTabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-bot-btn" data-bs-toggle="tab" data-bs-target="#tab-bot" type="button" role="tab">Facebook</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-promo-btn" data-bs-toggle="tab" data-bs-target="#tab-promo" type="button" role="tab">Campañas</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-mi-pagina-btn" data-bs-toggle="tab" data-bs-target="#tab-mi-pagina" type="button" role="tab">Mi página</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-programacion-btn" data-bs-toggle="tab" data-bs-target="#tab-programacion" type="button" role="tab">Programación</button></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="tab-bot" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header bg-white fw-bold">Configuración</div>
            <div class="card-body">
              <div class="alert alert-primary py-2 mb-3">
                <div class="fw-semibold"><i class="fab fa-facebook"></i> Meta Graph API</div>
                <div class="small mt-1">Configura una página de Facebook con su <b>Page ID</b> y <b>Page Access Token</b>. Si enlazas Instagram profesional, también publica allí el mismo contenido.</div>
                <div class="d-flex gap-2 mt-2 flex-wrap">
                  <a class="btn btn-primary btn-sm" href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Graph Explorer</a>
                  <button class="btn btn-outline-dark btn-sm" type="button" onclick="openLogsModal()"><i class="fas fa-file-lines"></i> Ver consola</button>
                  <button class="btn btn-outline-success btn-sm" type="button" onclick="runQueue()"><i class="fas fa-play"></i> Ejecutar cola ahora</button>
                </div>
                <div id="fbStatus" class="small text-muted mt-2">Estado: pendiente de configuración.</div>
              </div>
              <form id="f" class="row g-2">
                <div class="col-12 form-check form-switch mb-1"><input class="form-check-input" id="enabled" type="checkbox"><label class="form-check-label" for="enabled">Habilitar publicador Facebook</label></div>
                <div class="col-md-6"><label class="form-label">Nombre negocio</label><input id="business_name" class="form-control" maxlength="120"></div>
                <div class="col-md-6"><label class="form-label">Nombre de la página</label><input id="page_name" class="form-control" maxlength="120" placeholder="Ej: PalWeb Oficial"></div>
                <div class="col-md-6"><label class="form-label">Page ID</label><input id="page_id" class="form-control" maxlength="80" placeholder="1234567890"></div>
                <div class="col-md-6"><label class="form-label">Graph version</label><input id="graph_version" class="form-control" maxlength="20" placeholder="v23.0"></div>
                <div class="col-md-6"><label class="form-label">Worker key</label><input id="worker_key" class="form-control" maxlength="120"></div>
                <div class="col-md-6"><label class="form-label">Page Access Token</label><input id="page_access_token" type="password" autocomplete="current-password" class="form-control" placeholder="vacío = conservar"></div>
                <div class="col-12"><hr class="my-2"></div>
                <div class="col-12 form-check form-switch mb-1"><input class="form-check-input" id="enable_instagram" type="checkbox"><label class="form-check-label" for="enable_instagram">Publicar también en Instagram</label></div>
                <div class="col-md-6"><label class="form-label">Instagram username</label><input id="ig_username" class="form-control" maxlength="120" placeholder="@palweb"></div>
                <div class="col-md-6"><label class="form-label">Instagram User ID</label><input id="ig_user_id" class="form-control" maxlength="80" placeholder="1784..."></div>
                <div class="col-md-6"><label class="form-label">Instagram Access Token</label><input id="ig_access_token" type="password" autocomplete="current-password" class="form-control" placeholder="vacío = usar token de página"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Guardar</button></div>
              </form>
            </div>
          </div>

          <div class="card mt-3">
            <div class="card-header bg-white fw-bold">Prueba de publicación</div>
            <div class="card-body row g-2">
              <div class="col-md-8"><input id="testText" class="form-control" placeholder="Texto de prueba para Facebook"></div>
              <div class="col-md-4"><button class="btn btn-success w-100" type="button" onclick="testPost()"><i class="fas fa-paper-plane"></i> Publicar prueba</button></div>
              <div class="col-12"><div class="small text-muted">La prueba publica en la página configurada y registra el resultado en el histórico.</div></div>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card mb-3">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
              <span>Publicaciones recientes</span>
              <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadRecentPosts()"><i class="fas fa-sync"></i></button>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive" style="max-height:460px">
                <table class="table table-sm mb-0">
                  <thead class="table-light"><tr><th>Fecha</th><th>Plataforma</th><th>Destino</th><th>Campaña</th><th>Resultado</th><th>Post ID</th></tr></thead>
                  <tbody id="recentPostsRows"><tr><td colspan="6" class="text-center text-muted p-3">Sin publicaciones</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-header bg-white fw-bold">Último detalle</div>
            <div class="card-body">
              <pre id="lastPostPreview" class="fb-console mb-0">Sin datos.</pre>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="tab-promo" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card mb-3">
            <div class="card-header bg-white fw-bold">Nueva campaña</div>
            <div class="card-body">
              <div class="row g-2 mb-2">
                <div class="col-md-8">
                  <label class="form-label">Plantilla</label>
                  <select id="promoTemplateSelect" class="form-select"></select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                  <button class="btn btn-outline-primary w-100" type="button" onclick="applyPromoTemplate()"><i class="fas fa-file-import"></i> Cargar</button>
                  <button class="btn btn-outline-danger" type="button" onclick="deletePromoTemplate()"><i class="fas fa-trash"></i></button>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-md-8">
                  <label class="form-label">Nombre plantilla (texto + productos)</label>
                  <input id="promoTemplateName" class="form-control" placeholder="Ej: Oferta desayuno">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                  <button class="btn btn-outline-success w-100" type="button" onclick="savePromoTemplate()"><i class="fas fa-save"></i> Guardar plantilla</button>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Texto de publicación</label>
                <textarea id="promoText" class="form-control" rows="3" placeholder="Ej: Oferta especial solo hoy..."></textarea>
              </div>
              <div class="mb-2">
                <label class="form-label">Banners o logo del texto (máximo 3)</label>
                <input id="promoBannerInput" type="file" class="form-control" accept="image/*" multiple>
                <div class="form-text">Estas imágenes acompañarán el post como banners publicitarios o logo de empresa.</div>
                <div id="promoBannerWrap" class="border rounded p-2 mt-2" style="min-height:84px;max-height:200px;overflow:auto">
                  <div class="text-muted small">Sin imágenes cargadas.</div>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-md-6"><label class="form-label">Nombre campaña</label><input id="promoCampaignName" class="form-control" placeholder="Ej: Viernes Oferta"></div>
                <div class="col-md-6"><label class="form-label">Grupo de campaña</label><input id="promoCampaignGroup" class="form-control" placeholder="Ej: Mayoristas"></div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-md-8">
                  <label class="form-label">Buscar producto</label>
                  <input id="promoSearch" class="form-control" placeholder="Nombre o código">
                  <div id="promoSearchRes" class="list-group mt-1" style="max-height:180px;overflow:auto"></div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Hora de lanzamiento</label>
                  <input id="promoScheduleTime" type="time" class="form-control" value="09:00">
                  <div class="form-text">Zona horaria fija: America/Havana (Cuba).</div>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-12">
                  <label class="form-label d-block">Días de la semana</label>
                  <div class="d-flex flex-wrap gap-2">
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="1" checked> <span class="form-check-label">L</span></label>
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="2" checked> <span class="form-check-label">M</span></label>
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="3" checked> <span class="form-check-label">X</span></label>
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="4" checked> <span class="form-check-label">J</span></label>
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="5" checked> <span class="form-check-label">V</span></label>
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="6"> <span class="form-check-label">S</span></label>
                    <label class="form-check form-check-inline m-0"><input class="form-check-input promo-day" type="checkbox" value="0"> <span class="form-check-label">D</span></label>
                  </div>
                </div>
              </div>
              <div id="promoProductsWrap" class="border rounded p-2" style="min-height:100px;max-height:240px;overflow:auto">
                <div class="text-muted small">Sin productos seleccionados.</div>
              </div>
              <div class="mt-2">
                <button class="btn btn-primary" type="button" onclick="createPromoCampaign()"><i class="fas fa-bullhorn"></i> Programar campaña</button>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
              <span>Campañas recientes <small class="text-muted">(horario Cuba)</small></span>
              <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive" style="max-height:620px">
                <table class="table table-sm mb-0">
                  <thead class="table-light"><tr><th>Fecha</th><th>Campaña</th><th>Grupo</th><th>Horario</th><th>Estado</th><th>Acciones</th></tr></thead>
                  <tbody id="promoRows"><tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="tab-mi-pagina" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header bg-white fw-bold">Página destino diaria</div>
            <div class="card-body">
              <div class="small text-muted mb-2">La campaña diaria publica en la página configurada todos los productos con existencias y al final un cierre con reservables y promoción web.</div>
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Hora diaria</label>
                  <input id="myPageScheduleTime" type="time" class="form-control" value="10:00">
                  <div class="form-text">Zona horaria fija: America/Havana (Cuba).</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Grupo de campaña</label>
                  <input id="myPageCampaignGroup" class="form-control" value="Mi pagina">
                </div>
              </div>
              <div class="mt-3 p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0">
                <div class="fw-semibold mb-1">Contenido generado automáticamente</div>
                <div class="small text-muted">
                  1. Todos los productos con existencias disponibles.<br>
                  2. Texto final con todos los productos reservables.<br>
                  3. Cierre con promoción a <b>www.palweb.net</b>.
                </div>
              </div>
              <div class="mt-3">
                <button class="btn btn-primary" type="button" onclick="createMyPageCampaign()"><i class="fas fa-calendar-check"></i> Programar publicación diaria</button>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header bg-white fw-bold">Vista previa de Mi página</div>
            <div class="card-body">
              <div id="myPagePreview" class="border rounded p-3 small text-muted" style="min-height:260px;max-height:420px;overflow:auto">Vista previa pendiente.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="tab-programacion" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
              <span>Plantillas guardadas</span>
              <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoTemplates()"><i class="fas fa-sync"></i></button>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive" style="max-height:380px">
                <table class="table table-sm mb-0">
                  <thead class="table-light"><tr><th>Plantilla</th><th>Productos</th><th>Actualizada</th></tr></thead>
                  <tbody id="promoTemplateRows"><tr><td colspan="3" class="text-center text-muted p-3">Sin plantillas</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="card">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
              <span>Campañas programadas</span>
              <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
            </div>
            <div class="card-body" id="promoProgramGroups">
              <div class="text-muted small">Sin campañas programadas.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="logsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0"><i class="fas fa-file-lines"></i> Consola Facebook Publisher</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="logsStatus" class="small text-muted mb-2"></div>
        <pre id="logsText" class="fb-console">Sin actividad.</pre>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="loadLogsSnapshot()"><i class="fas fa-sync"></i> Refrescar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="campaignLogsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0"><i class="fas fa-list-check"></i> Logs de campaña</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="campaignLogsSummary" class="small mb-2 text-muted">Cargando...</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Fecha</th><th>Página</th><th>Publicaciones</th><th>Resultado</th><th>Error</th></tr></thead>
            <tbody id="campaignLogsRows"><tr><td colspan="5" class="text-center text-muted p-3">Sin datos</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="refreshCampaignLogs()"><i class="fas fa-sync"></i> Refrescar</button>
      </div>
    </div>
  </div>
</div>

<script>
const API='fb_bot_api.php';
let currentConfig={};
let promoProducts=[];
let promoBannerImages=[];
let promoTemplates=[];
let promoCampaigns=[];
let recentPosts=[];
let activeCampaignLogId='';
let promoSearchTimer=null;
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const a=(t,m)=>{const e=document.getElementById('alertBox');e.innerHTML=`<div class="alert alert-${t} py-2">${esc(m)}</div>`;setTimeout(()=>e.innerHTML='',3500)};
async function parseApiResponse(r){const txt=await r.text();try{return JSON.parse(txt);}catch(_){return {status:'error',msg:'Respuesta no JSON del servidor',raw:txt};}}
async function g(u){const r=await fetch(u,{credentials:'same-origin'});return parseApiResponse(r)}
async function p(u,d){const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});return parseApiResponse(r)}
async function uploadPromoBanner(file){const fd=new FormData();fd.append('image',file);const r=await fetch(API+'?action=promo_upload_image',{method:'POST',credentials:'same-origin',body:fd});return parseApiResponse(r)}
function selectedPromoDays(){return [...document.querySelectorAll('.promo-day:checked')].map(x=>parseInt(x.value,10)).filter(x=>!Number.isNaN(x));}
function daysToText(days){const map={0:'D',1:'L',2:'M',3:'X',4:'J',5:'V',6:'S'};const arr=(Array.isArray(days)?days:[]).map(x=>parseInt(x,10)).filter(x=>map[x]!==undefined);return arr.length?arr.map(x=>map[x]).join(','):'-';}
function stateBadge(status){if(status==='success'||status==='done') return '<span class="badge bg-success">OK</span>'; if(status==='scheduled') return '<span class="badge bg-info text-dark">scheduled</span>'; if(status==='queued'||status==='running') return `<span class="badge bg-warning text-dark">${esc(status)}</span>`; if(status==='error') return '<span class="badge bg-danger">error</span>'; return `<span class="badge bg-secondary">${esc(status||'-')}</span>`;}
function targetsToText(targets){const arr=Array.isArray(targets)?targets:[]; if(!arr.length) return '-'; return arr.map(t=>String(t.name||t.id||'')).filter(Boolean).join(' | ')}
function updateStatusLed(){
  const dot=document.getElementById('fbLedDot');
  const txt=document.getElementById('fbLedText');
  const s4=document.getElementById('s4');
  const statusLine=document.getElementById('fbStatus');
  let label='Sin configurar', color='#64748b';
  const fbReady = String(currentConfig.page_id||'').trim()!=='' && String(currentConfig.page_access_token_masked||currentConfig.page_access_token||'').trim()!=='';
  const igReady = Number(currentConfig.enable_instagram||0)===1 && String(currentConfig.ig_user_id||'').trim()!=='' && String(currentConfig.ig_access_token_masked||currentConfig.ig_access_token||currentConfig.page_access_token_masked||'').trim()!=='';
  if(Number(currentConfig.enabled||0)===1 && fbReady) { label = igReady ? 'FB + IG listo' : 'Facebook listo'; color='#16a34a'; }
  else if(fbReady) { label = igReady ? 'FB + IG configurado' : 'Facebook configurado'; color='#2563eb'; }
  else if(String(currentConfig.page_id||'').trim()!=='' || String(currentConfig.page_name||'').trim()!=='') { label='Falta token'; color='#f59e0b'; }
  if(dot){dot.style.background=color;dot.style.boxShadow=`0 0 0 4px ${color}33`;}
  if(txt){txt.textContent=label;txt.style.color=color;}
  if(s4) s4.textContent=label;
  if(statusLine){statusLine.textContent = label.includes('listo') ? 'Estado: listo para publicar en Facebook' + (igReady ? ' e Instagram.' : '.') : (label.includes('configurado') ? 'Estado: canales configurados; activa el módulo para publicar.' : (label==='Falta token' ? 'Estado: falta completar o conservar el token requerido.' : 'Estado: pendiente de configuración.'));}
}
async function loadCfg(){
  const d=await g(API+'?action=get_config');
  if(d.status!=='success') throw new Error(d.msg||'No se pudo cargar configuración');
  const c=d.config||{};
  currentConfig={...c,page_access_token_masked:c.page_access_token||'',ig_access_token_masked:c.ig_access_token||''};
  enabled.checked=Number(c.enabled)===1;
  business_name.value=c.business_name||'';
  page_name.value=c.page_name||'';
  page_id.value=c.page_id||'';
  graph_version.value=c.graph_version||'v23.0';
  worker_key.value=c.worker_key||'palweb_fb_worker';
  enable_instagram.checked=Number(c.enable_instagram)===1;
  ig_username.value=c.ig_username||'';
  ig_user_id.value=c.ig_user_id||'';
  page_access_token.value='';
  ig_access_token.value='';
  updateStatusLed();
}
async function saveCfg(ev){
  ev.preventDefault();
  const d=await p(API+'?action=save_config',{
    enabled:enabled.checked?1:0,
    business_name:business_name.value.trim(),
    page_name:page_name.value.trim(),
    page_id:page_id.value.trim(),
    graph_version:graph_version.value.trim()||'v23.0',
    worker_key:worker_key.value.trim()||'palweb_fb_worker',
    page_access_token:page_access_token.value.trim(),
    enable_instagram:enable_instagram.checked?1:0,
    ig_username:ig_username.value.trim(),
    ig_user_id:ig_user_id.value.trim(),
    ig_access_token:ig_access_token.value.trim()
  });
  if(d.status==='success'){a('success','Guardado');await loadCfg();await loadStats();page_access_token.value='';ig_access_token.value='';} else a('danger',d.msg||'No se pudo guardar');
}
async function loadStats(){
  const d=await g(API+'?action=stats');
  if(d.status!=='success') return;
  s1.textContent=d.stats.posts_today||0;
  s2.textContent=d.stats.facebook_today||0;
  s3.textContent=d.stats.instagram_today||0;
  if(!s4.textContent || s4.textContent==='-') s4.textContent=Number(d.stats.enabled||0)===1?'Activo':'Pausado';
}
function renderRecentPosts(){
  const tb=document.getElementById('recentPostsRows');
  const preview=document.getElementById('lastPostPreview');
  if(!recentPosts.length){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin publicaciones</td></tr>'; preview.textContent='Sin datos.'; return;}
  tb.innerHTML=recentPosts.map((r,idx)=>`<tr onclick="showPostDetail(${idx})" style="cursor:pointer">
    <td class="small">${esc(r.created_at||'')}</td>
    <td class="small"><span class="badge ${String(r.platform)==='instagram'?'bg-danger':'bg-primary'}">${esc(r.platform||'facebook')}</span></td>
    <td class="small">${esc(r.page_name||'-')}</td>
    <td class="small">${esc(r.campaign_id||'-')}</td>
    <td>${stateBadge(r.status||'-')}</td>
    <td class="small">${esc(r.fb_post_id||'-')}</td>
  </tr>`).join('');
  showPostDetail(0);
}
function showPostDetail(idx){
  const row=recentPosts[idx];
  if(!row) return;
  const lines=[
    `Fecha: ${row.created_at||'-'}`,
    `Plataforma: ${row.platform||'facebook'}`,
    `Destino: ${row.page_name||'-'} (${row.page_id||'-'})`,
    `Campaña: ${row.campaign_id||'-'}`,
    `Estado: ${row.status||'-'}`,
    `Post ID: ${row.fb_post_id||'-'}`,
    row.error_text?`Error: ${row.error_text}`:'',
    '',
    String(row.message_text||'')
  ].filter(Boolean);
  lastPostPreview.textContent=lines.join('\n');
}
async function loadRecentPosts(){const d=await g(API+'?action=recent_posts'); if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar publicaciones');return;} recentPosts=Array.isArray(d.rows)?d.rows:[]; renderRecentPosts();}
function renderPromoTemplates(){const s=document.getElementById('promoTemplateSelect'); if(!s) return; s.innerHTML=['<option value="">(Sin plantilla)</option>'].concat(promoTemplates.map(t=>`<option value="${esc(t.id)}">${esc(t.name||t.id)}</option>`)).join('');}
async function loadPromoTemplates(){const d=await g(API+'?action=promo_templates'); if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar plantillas');return;} promoTemplates=Array.isArray(d.rows)?d.rows:[]; renderPromoTemplates(); renderProgrammingTab();}
async function savePromoTemplate(){const name=(promoTemplateName.value||'').trim(); const text=(promoText.value||'').trim(); if(!name){a('danger','Pon nombre a la plantilla');return;} if(!text && !promoProducts.length && !promoBannerImages.length){a('danger','La plantilla no puede estar vacía');return;} const currentId=(promoTemplateSelect.value||'').trim(); const d=await p(API+'?action=promo_template_save',{id:currentId,name,text,products:promoProducts,banner_images:promoBannerImages}); if(d.status==='success'){a('success','Plantilla guardada'); await loadPromoTemplates(); promoTemplateSelect.value=d.id||'';} else a('danger',d.msg||'No se pudo guardar plantilla');}
function applyPromoTemplate(){const id=(promoTemplateSelect.value||'').trim(); if(!id){a('danger','Selecciona una plantilla');return;} const t=promoTemplates.find(x=>String(x.id)===id); if(!t){a('danger','Plantilla no encontrada');return;} promoTemplateName.value=t.name||''; promoText.value=t.text||''; promoProducts=Array.isArray(t.products)?t.products:[]; promoBannerImages=Array.isArray(t.banner_images)?t.banner_images:[]; renderPromoProducts(); renderPromoBanners(); a('info','Plantilla cargada');}
async function deletePromoTemplate(){const id=(promoTemplateSelect.value||'').trim(); if(!id){a('danger','Selecciona una plantilla');return;} const d=await p(API+'?action=promo_template_delete',{id}); if(d.status==='success'){a('success','Plantilla eliminada'); await loadPromoTemplates(); promoTemplateName.value='';} else a('danger',d.msg||'No se pudo eliminar plantilla');}
function renderPromoProducts(){const w=document.getElementById('promoProductsWrap'); if(!w) return; if(!promoProducts.length){w.innerHTML='<div class="text-muted small">Sin productos seleccionados.</div>';return;} w.innerHTML=promoProducts.map((p,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-1"><img src="${esc(p.image||'')}" alt="" width="42" height="42" style="object-fit:cover;border-radius:6px;border:1px solid #ddd"><div class="small"><div class="fw-semibold">${esc(p.name)}</div><div class="text-muted">$${Number(p.price||0).toFixed(2)} · ${esc(p.id)}</div></div><button class="btn btn-sm btn-outline-danger ms-auto" type="button" onclick="removePromoProduct(${idx})"><i class="fas fa-times"></i></button></div>`).join('');}
function renderPromoBanners(){const w=document.getElementById('promoBannerWrap'); if(!w) return; if(!promoBannerImages.length){w.innerHTML='<div class="text-muted small">Sin imágenes cargadas.</div>';return;} w.innerHTML=promoBannerImages.map((img,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-2"><img src="${esc(img.url||'')}" alt="" width="72" height="48" style="object-fit:cover;border-radius:6px;border:1px solid #ddd"><div class="small" style="min-width:0"><div class="fw-semibold text-truncate">${esc(img.name||('Banner '+(idx+1)))}</div><div class="text-muted text-truncate">${esc(img.url||'')}</div></div><button class="btn btn-sm btn-outline-danger ms-auto" type="button" onclick="removePromoBanner(${idx})"><i class="fas fa-times"></i></button></div>`).join('');}
function removePromoBanner(idx){promoBannerImages.splice(idx,1);renderPromoBanners();}
function removePromoProduct(idx){promoProducts.splice(idx,1);renderPromoProducts();}
function addPromoProduct(p){if(!p||!p.id) return; if(promoProducts.some(x=>String(x.id)===String(p.id))) return; promoProducts.push(p); renderPromoProducts();}
async function onPromoBannerInput(ev){const files=[...(ev.target.files||[])]; if(!files.length) return; const remaining=Math.max(0,3-promoBannerImages.length); if(!remaining){a('danger','Solo se permiten hasta 3 imágenes');ev.target.value='';return;} const selected=files.slice(0,remaining); for(const file of selected){const d=await uploadPromoBanner(file); if(d.status==='success'){promoBannerImages.push({url:d.url,name:d.name||file.name}); renderPromoBanners();}else{a('danger',d.msg||('No se pudo subir '+file.name));}} if(files.length>remaining) a('info','Máximo 3 imágenes por campaña.'); ev.target.value='';}
async function searchPromoProducts(q){const box=document.getElementById('promoSearchRes'); if(!box) return; if(!q || q.trim().length<2){box.innerHTML='';return;} const d=await g(API+'?action=promo_products&q='+encodeURIComponent(q.trim())); if(d.status!=='success'){box.innerHTML='';return;} const rows=Array.isArray(d.rows)?d.rows:[]; box.innerHTML=rows.map((r,idx)=>`<button class="list-group-item list-group-item-action d-flex align-items-center gap-2" type="button" data-add-idx="${idx}"><img src="${esc(r.image||'')}" alt="" width="32" height="32" style="object-fit:cover;border-radius:5px;border:1px solid #ddd"><span class="small">${esc(r.name)} <span class="text-muted">(${esc(r.id)})</span> - $${Number(r.price||0).toFixed(2)}</span></button>`).join(''); box.querySelectorAll('[data-add-idx]').forEach(btn=>btn.addEventListener('click',()=>{addPromoProduct(rows[parseInt(btn.dataset.addIdx,10)]); box.innerHTML='';}));}
async function createPromoCampaign(){try{const text=(promoText.value||'').trim(); const campaignName=(promoCampaignName.value||'').trim(); const campaignGroup=(promoCampaignGroup.value||'').trim()||'General'; const scheduleTime=(promoScheduleTime.value||'').trim(); const scheduleDays=selectedPromoDays(); if(!text && !promoProducts.length && !promoBannerImages.length){a('danger','La campaña no puede estar vacía');return;} if(!scheduleTime){a('danger','Selecciona hora de lanzamiento');return;} if(!scheduleDays.length){a('danger','Selecciona al menos un día');return;} const d=await p(API+'?action=promo_create',{campaign_name:campaignName,campaign_group:campaignGroup,template_id:(promoTemplateSelect.value||'').trim(),text,banner_images:promoBannerImages,products:promoProducts,schedule_enabled:1,schedule_time:scheduleTime,schedule_days:scheduleDays}); if(d.status==='success'){a('success','Campaña programada: '+(d.id||'')); loadPromoList();} else a('danger',d.msg||'Error al crear campaña');}catch(e){a('danger','No se pudo programar la campaña: '+(e?.message||'error inesperado'));}}
function renderMyPagePreview(payload){const box=document.getElementById('myPagePreview'); if(!box) return; const products=Array.isArray(payload?.products)?payload.products:[]; const reservables=Array.isArray(payload?.reservables)?payload.reservables:[]; box.innerHTML=[`<div class="fw-semibold mb-2">Vista previa para ${esc(page_name.value||page_id.value||'tu página')}</div>`,`<div class="mb-2"><span class="badge bg-success">${products.length}</span> productos con existencias</div>`,`<div class="mb-2"><span class="badge bg-info text-dark">${reservables.length}</span> productos reservables al cierre</div>`,`<div class="text-muted">Texto final:</div>`,`<pre class="mb-0 mt-2" style="white-space:pre-wrap;font-family:inherit">${esc(payload?.outro_text||'')}</pre>`].join('');}
async function loadMyPagePreview(){const box=document.getElementById('myPagePreview'); if(!box) return; box.innerHTML='Cargando vista previa...'; const d=await g(API+'?action=promo_my_page_payload'); if(d.status!=='success'){box.innerHTML='No se pudo generar la vista previa.'; a('danger',d.msg||'No se pudo cargar Mi página'); return;} renderMyPagePreview(d);}
async function createMyPageCampaign(){try{const scheduleTime=(myPageScheduleTime.value||'').trim(); const campaignGroup=(myPageCampaignGroup.value||'').trim()||'Mi pagina'; if(!scheduleTime){a('danger','Selecciona la hora diaria');return;} const payload=await g(API+'?action=promo_my_page_payload'); if(payload.status!=='success'){a('danger',payload.msg||'No se pudo preparar Mi página');return;} const products=Array.isArray(payload.products)?payload.products:[]; if(!products.length){a('danger','No hay productos con existencias disponibles');return;} const d=await p(API+'?action=promo_create',{campaign_name:'Mi pagina diaria',campaign_group:campaignGroup,text:'',outro_text:String(payload.outro_text||'').trim(),products,schedule_enabled:1,schedule_time:scheduleTime,schedule_days:[0,1,2,3,4,5,6]}); if(d.status==='success'){renderMyPagePreview(payload); a('success','Mi página programada: '+(d.id||'')); loadPromoList();} else a('danger',d.msg||'Error al crear Mi página');}catch(e){a('danger','No se pudo programar Mi página: '+(e?.message||'error inesperado'));}}
async function loadPromoList(){const d=await g(API+'?action=promo_list'); const tb=document.getElementById('promoRows'); if(!tb) return; if(d.status!=='success'){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr>'; promoCampaigns=[]; renderProgrammingTab(); return;} promoCampaigns=Array.isArray(d.rows)?d.rows:[]; if(!promoCampaigns.length){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr>'; renderProgrammingTab(); return;} tb.innerHTML=promoCampaigns.map(r=>`<tr><td class="small">${esc(r.created_at||'')}</td><td class="small">${esc(r.name||r.id||'')}</td><td class="small">${esc(r.campaign_group||'General')}</td><td class="small">${esc(r.schedule_time||'-')} (${esc(daysToText(r.schedule_days||[]))})<br><span class="text-muted">${esc(targetsToText(r.targets||[]))}</span></td><td>${stateBadge(r.status||'')}</td><td class="small"><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" type="button" title="Clonar" onclick="cloneScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-clone"></i></button><button class="btn btn-sm btn-outline-success" type="button" title="Enviar ahora" onclick="forceCampaignNow('${esc(r.id||'')}')"><i class="fas fa-bolt"></i></button><button class="btn btn-sm btn-outline-dark" type="button" title="Ver logs" onclick="openCampaignLogs('${esc(r.id||'')}')"><i class="fas fa-list-check"></i></button><button class="btn btn-sm btn-outline-primary" type="button" title="Editar" onclick="editScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-pen"></i></button><button class="btn btn-sm btn-outline-danger" type="button" title="Eliminar" onclick="deleteScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-trash"></i></button></div></td></tr>`).join(''); renderProgrammingTab();}
async function cloneScheduledCampaign(id){const d=await p(API+'?action=promo_clone',{id}); if(d.status==='success'){a('success','Campaña clonada: '+(d.name||d.id||'')); loadPromoList();} else a('danger',d.msg||'No se pudo clonar');}
async function deleteScheduledCampaign(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} if(!confirm(`¿Eliminar campaña "${row.name||row.id}"?`)) return; const d=await p(API+'?action=promo_delete',{id}); if(d.status==='success'){a('success','Campaña eliminada'); loadPromoList();} else a('danger',d.msg||'No se pudo eliminar');}
async function forceCampaignNow(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} if(!confirm(`¿Lanzar ahora la campaña "${row.name||row.id}"?`)) return; const d=await p(API+'?action=promo_force_now',{id}); if(d.status==='success'){a('success','Campaña enviada a cola para ejecutar ahora'); loadPromoList(); loadRecentPosts();} else a('danger',d.msg||'No se pudo forzar');}
async function editScheduledCampaign(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} const name=prompt('Nombre de campaña:',row.name||''); if(name===null) return; const group=prompt('Grupo de campaña:',row.campaign_group||'General'); if(group===null) return; const time=prompt('Hora (HH:MM) zona Cuba (America/Havana):',row.schedule_time||'09:00'); if(time===null) return; const daysCurrent=Array.isArray(row.schedule_days)?row.schedule_days.join(','):'1,2,3,4,5'; const daysRaw=prompt('Días (0..6 separados por coma). 0=Dom,1=Lun,...,6=Sab',daysCurrent); if(daysRaw===null) return; const days=String(daysRaw).split(',').map(x=>parseInt(String(x).trim(),10)).filter(x=>!Number.isNaN(x) && x>=0 && x<=6); if(!days.length){a('danger','Debes indicar al menos un día válido (0..6)');return;} const d=await p(API+'?action=promo_update',{id,name:String(name).trim(),campaign_group:String(group).trim()||'General',schedule_enabled:1,schedule_time:String(time).trim(),schedule_days:days,status:'scheduled'}); if(d.status==='success'){a('success','Campaña actualizada'); loadPromoList();} else a('danger',d.msg||'No se pudo actualizar');}
function renderCampaignLogsModal(job){const summary=document.getElementById('campaignLogsSummary'); const rowsEl=document.getElementById('campaignLogsRows'); const logs=Array.isArray(job.log)?job.log:[]; const ok=logs.filter(x=>x&&x.ok===true).length; const fail=logs.filter(x=>x&&x.ok===false).length; const sent=logs.reduce((acc,x)=>acc+Number((x&&x.messages_sent)||0),0); const targets=(Array.isArray(job.targets)?job.targets:[]).map(t=>String(t.name||t.id||'')).join(' | '); summary.textContent=`Campaña: ${job.name||job.id||'-'} | Grupo: ${job.campaign_group||'General'} | Publicaciones: ${sent} | OK: ${ok} | Fallos: ${fail} | Página: ${targets||'-'}`; if(!logs.length){rowsEl.innerHTML='<tr><td colspan="5" class="text-center text-muted p-3">Sin logs aún</td></tr>'; return;} rowsEl.innerHTML=logs.slice().reverse().map(l=>`<tr><td class="small">${esc(l.at||'-')}</td><td class="small">${esc(l.target_name||l.target_id||'-')}</td><td class="small">${Number(l.messages_sent||0)}</td><td>${l.ok===true?'<span class="badge bg-success">OK</span>':'<span class="badge bg-danger">Fallo</span>'}</td><td class="small text-danger">${esc(l.error||'')}</td></tr>`).join('');}
async function refreshCampaignLogs(){if(!activeCampaignLogId) return; const d=await g(API+'?action=promo_detail&id='+encodeURIComponent(activeCampaignLogId)); if(d.status!=='success' || !d.row){a('danger',d.msg||'No se pudieron cargar logs');return;} renderCampaignLogsModal(d.row);}
async function openCampaignLogs(id){activeCampaignLogId=String(id||''); await refreshCampaignLogs(); new bootstrap.Modal(document.getElementById('campaignLogsModal')).show();}
function renderProgrammingTab(){const tplRows=document.getElementById('promoTemplateRows'); if(tplRows){if(!promoTemplates.length){tplRows.innerHTML='<tr><td colspan="3" class="text-center text-muted p-3">Sin plantillas</td></tr>';} else {tplRows.innerHTML=promoTemplates.map(t=>`<tr><td class="small fw-semibold">${esc(t.name||t.id||'-')}</td><td class="small">${Array.isArray(t.products)?t.products.length:0}</td><td class="small">${esc(t.updated_at||'-')}</td></tr>`).join('');}}
  const wrap=document.getElementById('promoProgramGroups'); if(!wrap) return; const scheduled=promoCampaigns.filter(r=>Number(r.schedule_enabled||0)===1); if(!scheduled.length){wrap.innerHTML='<div class="text-muted small">Sin campañas programadas.</div>'; return;} const groups={}; for(const r of scheduled){const g=String(r.campaign_group||'General'); if(!groups[g]) groups[g]=[]; groups[g].push(r);} wrap.innerHTML=Object.keys(groups).sort().map(g=>`<div class="border rounded p-2 mb-2"><div class="fw-semibold mb-1">${esc(g)}</div><div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Campaña</th><th>Hora</th><th>Días</th><th>Estado</th><th>Página</th><th>Acciones</th></tr></thead><tbody>${groups[g].map(r=>`<tr><td class="small">${esc(r.name||r.id||'-')}</td><td class="small">${esc(r.schedule_time||'-')}</td><td class="small">${esc(daysToText(r.schedule_days||[]))}</td><td>${stateBadge(r.status||'-')}</td><td class="small text-muted">${esc(targetsToText(r.targets||[]))}</td><td class="small"><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-success" type="button" title="Enviar ahora" onclick="forceCampaignNow('${esc(r.id||'')}')"><i class="fas fa-bolt"></i></button><button class="btn btn-sm btn-outline-secondary" type="button" title="Clonar" onclick="cloneScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-clone"></i></button><button class="btn btn-sm btn-outline-dark" type="button" title="Ver logs" onclick="openCampaignLogs('${esc(r.id||'')}')"><i class="fas fa-list-check"></i></button><button class="btn btn-sm btn-outline-primary" type="button" onclick="editScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-pen"></i></button><button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-trash"></i></button></div></td></tr>`).join('')}</tbody></table></div></div>`).join('');}
async function testPost(){const text=(testText.value||'').trim()||'Prueba de publicación desde PalWeb Facebook'; const d=await p(API+'?action=test_post',{text}); if(d.status==='success'){a('success','Publicación de prueba enviada'); loadRecentPosts(); loadStats(); runQueue();} else a('danger',d.msg||'No se pudo publicar');}
async function runQueue(){const wk=(worker_key.value||currentConfig.worker_key||'').trim(); if(!wk){a('danger','Configura el worker key primero');return;} const d=await g(API+'?action=process_queue&worker_key='+encodeURIComponent(wk)); if(d.status==='success'){appendLog(`Queue ejecutada. Procesadas: ${d.processed||0}`); loadPromoList(); loadRecentPosts(); loadStats();} else appendLog(`Fallo process_queue: ${d.msg||'error'}`);}
function appendLog(text){const box=document.getElementById('logsText'); const stamp=new Date().toISOString(); const current=box.textContent||''; box.textContent=`[${stamp}] ${text}\n` + current.slice(0,12000); document.getElementById('logsStatus').textContent=`Última actualización: ${stamp}`;}
async function loadLogsSnapshot(){appendLog('Resumen local de campañas y publicaciones actualizado.'); await Promise.all([loadPromoList(),loadRecentPosts(),loadStats()]);}
function openLogsModal(){new bootstrap.Modal(document.getElementById('logsModal')).show();}
async function loadAll(){try{await Promise.all([loadCfg(),loadStats(),loadRecentPosts(),loadPromoTemplates(),loadPromoList(),loadMyPagePreview()]); await runQueueSilently();}catch(e){a('danger',e.message||'error')}}
async function runQueueSilently(){const wk=(worker_key.value||currentConfig.worker_key||'').trim(); if(!wk) return; const d=await g(API+'?action=process_queue&worker_key='+encodeURIComponent(wk)); if(d.status==='success' && Number(d.processed||0)>0){appendLog(`Queue automática: ${d.processed} campaña(s) procesada(s).`); await Promise.all([loadPromoList(),loadRecentPosts(),loadStats()]);}}
document.getElementById('f').addEventListener('submit',saveCfg);
document.getElementById('promoSearch').addEventListener('input',ev=>{if(promoSearchTimer) clearTimeout(promoSearchTimer); promoSearchTimer=setTimeout(()=>searchPromoProducts(ev.target.value||''),260);});
document.getElementById('promoBannerInput').addEventListener('change',onPromoBannerInput);
loadAll();
setInterval(()=>{loadStats();loadRecentPosts();loadPromoList();runQueueSilently();},15000);
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
