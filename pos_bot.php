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
<title>POS BOT WhatsApp</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<style>body{background:#f6f8fc}.card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06)}.stat{font-size:1.6rem;font-weight:700}</style>
</head>
<body class="p-3 p-md-4">
<div class="container-fluid" style="max-width:1400px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div><h4 class="mb-0"><i class="fab fa-whatsapp text-success"></i> POS BOT WhatsApp</h4><small class="text-muted">Auto-reply, menu ordering y pedidos por WhatsApp</small></div>
    <button class="btn btn-outline-secondary" onclick="loadAll()"><i class="fas fa-sync"></i> Refrescar</button>
  </div>

  <div id="alertBox"></div>

  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Sesiones</div><div id="s1" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Mensajes hoy</div><div id="s2" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Pedidos hoy</div><div id="s3" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Ventas hoy</div><div id="s4" class="stat">$0.00</div></div></div>
    <div class="col-12">
      <div class="card p-3">
        <div class="d-flex align-items-center gap-2">
          <span class="small text-muted">Estado WhatsApp Web</span>
          <span id="waLedDot" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#64748b;box-shadow:0 0 0 4px rgba(100,116,139,.2)"></span>
          <span id="waLedText" class="fw-semibold" style="color:#334155">Sin datos</span>
        </div>
      </div>
    </div>
  </div>

  <ul class="nav nav-tabs mb-3" id="botTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-bot-btn" data-bs-toggle="tab" data-bs-target="#tab-bot" type="button" role="tab">BOT</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-promo-btn" data-bs-toggle="tab" data-bs-target="#tab-promo" type="button" role="tab">Campañas</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-mi-grupo-btn" data-bs-toggle="tab" data-bs-target="#tab-mi-grupo" type="button" role="tab">Mi grupo</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-programacion-btn" data-bs-toggle="tab" data-bs-target="#tab-programacion" type="button" role="tab">Programación</button>
    </li>
  </ul>

  <div class="tab-content">
  <div class="tab-pane fade show active" id="tab-bot" role="tabpanel">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-bold">Configuración</div>
        <div class="card-body">
          <div class="alert alert-success py-2 mb-3">
            <div class="fw-semibold"><i class="fab fa-whatsapp"></i> Modo WhatsApp Web</div>
            <div class="small mt-1">Para abrir la cuenta: pulsa <b>Abrir web.whatsapp.com</b> y escanea el QR desde tu teléfono en WhatsApp.</div>
            <div class="d-flex gap-2 mt-2 flex-wrap">
              <button class="btn btn-success btn-sm" type="button" onclick="openWhatsAppWeb()"><i class="fas fa-external-link-alt"></i> Abrir web.whatsapp.com</button>
              <button class="btn btn-outline-success btn-sm" type="button" onclick="showBridgeQr()"><i class="fas fa-qrcode"></i> Ver QR del servicio</button>
              <button class="btn btn-outline-warning btn-sm" type="button" onclick="restartBridge()"><i class="fas fa-rotate-right"></i> Reiniciar bridge</button>
              <button class="btn btn-outline-dark btn-sm" type="button" onclick="showBridgeLogs()"><i class="fas fa-file-lines"></i> Ver logs bridge</button>
            </div>
            <div id="waWebStatus" class="small text-muted mt-2">Estado: pendiente de apertura</div>
          </div>
          <form id="f" class="row g-2">
            <div class="col-12 form-check form-switch mb-1"><input class="form-check-input" id="enabled" type="checkbox"><label class="form-check-label" for="enabled">Habilitar BOT</label></div>
            <div class="col-md-6">
              <label class="form-label">Modo de conexión</label>
              <select id="wa_mode" class="form-select">
                <option value="web">WhatsApp Web (QR)</option>
                <option value="meta_api">Meta Cloud API</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Nombre negocio</label><input id="business_name" class="form-control" maxlength="120"></div>
            <div id="row_verify_token" class="col-md-6"><label class="form-label">Verify token</label><input id="verify_token" class="form-control" maxlength="120"></div>
            <div id="row_phone_id" class="col-md-6"><label class="form-label">Phone Number ID</label><input id="wa_phone_number_id" autocomplete="username" class="form-control"></div>
            <div id="row_access_token" class="col-md-6"><label class="form-label">Access Token</label><input id="wa_access_token" type="password" autocomplete="current-password" class="form-control" placeholder="vacío = conservar"></div>
            <div class="col-12"><label class="form-label">Mensaje bienvenida</label><textarea id="welcome_message" rows="2" class="form-control"></textarea></div>
            <div class="col-12"><label class="form-label">Intro menú</label><textarea id="menu_intro" rows="3" class="form-control"></textarea></div>
            <div class="col-12"><label class="form-label">No match</label><textarea id="no_match_message" rows="2" class="form-control"></textarea></div>
            <div class="col-12"><button class="btn btn-success" type="submit"><i class="fas fa-save"></i> Guardar</button></div>
          </form>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header bg-white fw-bold">Prueba BOT</div>
        <div class="card-body row g-2">
          <div class="col-md-4"><input id="twa" class="form-control" placeholder="5351234567"></div>
          <div class="col-md-3"><input id="tname" class="form-control" placeholder="Cliente Test"></div>
          <div class="col-md-5"><input id="ttext" class="form-control" placeholder="MENU"></div>
          <div class="col-12"><button class="btn btn-primary" onclick="testBot()"><i class="fas fa-paper-plane"></i> Simular entrada</button></div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-header bg-white fw-bold">Mensajes</div><div class="card-body p-0"><div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Teléfono/WA</th><th>Nombre</th><th>Dir</th><th>Texto</th></tr></thead><tbody id="tm"><tr><td colspan="5" class="text-center text-muted p-3">Sin datos</td></tr></tbody></table></div></div></div>
      <div class="card"><div class="card-header bg-white fw-bold">Pedidos BOT</div><div class="card-body p-0"><div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Pedido</th><th>Cliente</th><th>Total</th></tr></thead><tbody id="to"><tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr></tbody></table></div></div></div>
    </div>
  </div>
  </div>

  <div class="tab-pane fade" id="tab-promo" role="tabpanel">
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Destinos de promoción</span>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoChats()"><i class="fas fa-sync"></i></button>
          </div>
          <div class="card-body">
            <div class="small text-muted mb-2">Se leen chats y grupos desde WhatsApp Web del bridge conectado.</div>
            <div id="promoChatsWrap" style="max-height:320px;overflow:auto;border:1px solid #e9ecef;border-radius:8px;padding:8px">
              <div class="text-muted small">Sin datos aún.</div>
            </div>
            <div class="mt-2">
              <button class="btn btn-sm btn-outline-primary" type="button" onclick="selectAllPromoChats(true)">Marcar todo</button>
              <button class="btn btn-sm btn-outline-secondary" type="button" onclick="selectAllPromoChats(false)">Limpiar</button>
            </div>
          </div>
        </div>
      </div>

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
              <label class="form-label">Texto de promoción</label>
              <textarea id="promoText" class="form-control" rows="3" placeholder="Ej: Oferta especial solo hoy..."></textarea>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-6">
                <label class="form-label">Nombre campaña</label>
                <input id="promoCampaignName" class="form-control" placeholder="Ej: Viernes Oferta">
              </div>
              <div class="col-md-6">
                <label class="form-label">Grupo de campaña</label>
                <input id="promoCampaignGroup" class="form-control" placeholder="Ej: Mayoristas">
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-6">
                <label class="form-label">Buscar producto</label>
                <input id="promoSearch" class="form-control" placeholder="Nombre o código">
                <div id="promoSearchRes" class="list-group mt-1" style="max-height:180px;overflow:auto"></div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Mín (seg)</label>
                <input id="promoMinSec" type="number" class="form-control" min="60" max="180" value="60">
              </div>
              <div class="col-md-3">
                <label class="form-label">Máx (seg)</label>
                <input id="promoMaxSec" type="number" class="form-control" min="60" max="300" value="120">
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="form-label">Hora de lanzamiento</label>
                <input id="promoScheduleTime" type="time" class="form-control" value="09:00">
                <div class="form-text">Zona horaria fija: America/Havana (Cuba).</div>
              </div>
              <div class="col-md-8">
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
            <div class="small text-muted mb-2">El bridge publica en cada destino con un intervalo aleatorio entre min y max. Ej: 1:20, 1:57, 1:08.</div>
            <div id="promoProductsWrap" class="border rounded p-2" style="min-height:100px;max-height:240px;overflow:auto">
              <div class="text-muted small">Sin productos seleccionados.</div>
            </div>
            <div class="mt-2">
              <button class="btn btn-success" type="button" onclick="createPromoCampaign()"><i class="fas fa-bullhorn"></i> Programar promoción</button>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Campañas recientes <small class="text-muted">(horario Cuba)</small></span>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive" style="max-height:260px">
              <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Fecha</th><th>Campaña</th><th>Grupo</th><th>Horario</th><th>Estado</th><th>Progreso</th></tr></thead>
                <tbody id="promoRows"><tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tab-mi-grupo" role="tabpanel">
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>Grupo destino diario</span>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoChats()"><i class="fas fa-sync"></i></button>
          </div>
          <div class="card-body">
            <div class="small text-muted mb-2">Selecciona un solo grupo. Se enviarán todos los productos con existencias y, al final, un texto con los reservables y la promoción web.</div>
            <div id="myGroupWrap" style="max-height:360px;overflow:auto;border:1px solid #e9ecef;border-radius:8px;padding:8px">
              <div class="text-muted small">Sin grupos detectados aún.</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header bg-white fw-bold">Publicación diaria automática</div>
          <div class="card-body">
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Hora diaria</label>
                <input id="myGroupScheduleTime" type="time" class="form-control" value="10:00">
                <div class="form-text">Zona horaria fija: America/Havana (Cuba).</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Grupo de campaña</label>
                <input id="myGroupCampaignGroup" class="form-control" value="Mi grupo">
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
              <button class="btn btn-success" type="button" onclick="createMyGroupCampaign()"><i class="fas fa-calendar-check"></i> Programar publicación diaria</button>
            </div>
            <div id="myGroupPreview" class="mt-3 border rounded p-3 small text-muted" style="min-height:180px;max-height:320px;overflow:auto">
              Vista previa pendiente.
            </div>
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
            <span>Campañas programadas por grupo</span>
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
        <h6 class="modal-title mb-0"><i class="fas fa-file-lines"></i> Logs bridge</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="logsBridgeState" class="small text-muted mb-2"></div>
        <pre id="logsText" style="white-space:pre-wrap;background:#0f172a;color:#dbeafe;padding:10px;border-radius:8px;max-height:60vh;overflow:auto">Cargando...</pre>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="loadBridgeLogs()"><i class="fas fa-sync"></i> Refrescar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0"><i class="fas fa-qrcode"></i> QR de vinculación</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body text-center">
        <div id="qrHelp" class="small text-muted mb-2">Escanea este QR desde tu teléfono en WhatsApp.</div>
        <div id="qrCanvas" class="d-inline-block"></div>
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
            <thead class="table-light">
              <tr><th>Fecha</th><th>Destino</th><th>Mensajes</th><th>Resultado</th><th>Error</th></tr>
            </thead>
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
const API='pos_bot_api.php';
let lastBridgeState=null;
let promoChats=[];
let promoProducts=[];
let promoTemplates=[];
let promoCampaigns=[];
let activeCampaignLogId='';
let promoSearchTimer=null;
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const a=(t,m)=>{const e=document.getElementById('alertBox');e.innerHTML=`<div class="alert alert-${t} py-2">${esc(m)}</div>`;setTimeout(()=>e.innerHTML='',3500)};
async function parseApiResponse(r){
  const txt=await r.text();
  try{return JSON.parse(txt);}catch(_){
    if((txt||'').includes('<!DOCTYPE html') || (txt||'').includes('<html')) return {status:'error',msg:'Sesión expirada o respuesta inválida del servidor'};
    return {status:'error',msg:'Respuesta no JSON del servidor'};
  }
}
async function g(u){const r=await fetch(u,{credentials:'same-origin'});return parseApiResponse(r)}
async function p(u,d){const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});return parseApiResponse(r)}

function applyModeUI(){
  const isMeta = wa_mode.value === 'meta_api';
  row_verify_token.classList.toggle('d-none', !isMeta);
  row_phone_id.classList.toggle('d-none', !isMeta);
  row_access_token.classList.toggle('d-none', !isMeta);
  verify_token.disabled = !isMeta;
  wa_phone_number_id.disabled = !isMeta;
  wa_access_token.disabled = !isMeta;
}
async function loadCfg(){const d=await g(API+'?action=get_config');if(d.status!=='success')throw new Error(d.msg||'error');const c=d.config||{};enabled.checked=Number(c.enabled)===1;wa_mode.value=(c.wa_mode==='meta_api'?'meta_api':'web');verify_token.value=c.verify_token||'';wa_phone_number_id.value=c.wa_phone_number_id||'';business_name.value=c.business_name||'';welcome_message.value=c.welcome_message||'';menu_intro.value=c.menu_intro||'';no_match_message.value=c.no_match_message||'';applyModeUI();}
async function saveCfg(ev){ev.preventDefault();const d=await p(API+'?action=save_config',{enabled:enabled.checked?1:0,wa_mode:wa_mode.value,verify_token:verify_token.value.trim(),wa_phone_number_id:wa_phone_number_id.value.trim(),wa_access_token:wa_access_token.value.trim(),business_name:business_name.value.trim(),welcome_message:welcome_message.value.trim(),menu_intro:menu_intro.value.trim(),no_match_message:no_match_message.value.trim()});if(d.status==='success'){wa_access_token.value='';a('success','Guardado');loadAll()} else a('danger',d.msg||'error');}
async function loadStats(){const d=await g(API+'?action=stats');if(d.status!=='success')return;s1.textContent=d.stats.sessions||0;s2.textContent=d.stats.msgs_today||0;s3.textContent=d.stats.orders_today||0;s4.textContent='$'+Number(d.stats.sales_today||0).toFixed(2)}
async function loadMsgs(){const d=await g(API+'?action=recent_messages');if(d.status!=='success'||!(d.rows||[]).length){tm.innerHTML='<tr><td colspan="5" class="text-center text-muted p-3">Sin datos</td></tr>';return;}tm.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td class="small">${esc(r.wa_user_id)}</td><td class="small">${esc(r.wa_name||'-')}</td><td>${esc(r.direction)}</td><td class="small">${esc((r.message_text||'').slice(0,120))}</td></tr>`).join('')}
async function loadOrders(){const d=await g(API+'?action=recent_orders');if(d.status!=='success'||!(d.rows||[]).length){to.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr>';return;}to.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td>#${esc(r.id_pedido)}</td><td class="small">${esc(r.cliente_nombre||r.wa_user_id)}</td><td>$${Number(r.total||0).toFixed(2)}</td></tr>`).join('')}
async function loadBridgeStatus(){
  const s = document.getElementById('waWebStatus');
  const ledDot = document.getElementById('waLedDot');
  const ledText = document.getElementById('waLedText');
  const d = await g(API+'?action=bridge_status');
  if(d.status!=='success' || !d.bridge){s.textContent='Estado: sin datos del bridge';if(ledDot){ledDot.style.background='#64748b';ledDot.style.boxShadow='0 0 0 4px rgba(100,116,139,.2)';}if(ledText){ledText.textContent='Sin datos';ledText.style.color='#334155';}return;}
  lastBridgeState=d.bridge;
  const st = String(d.bridge.state||'unknown');
  const map = {
    starting: {txt:'iniciando servicio...', color:'#64748b'},
    qr_required: {txt:'esperando escaneo QR en servicio.', color:'#dc2626'},
    authenticated: {txt:'sesión autenticada, cargando...', color:'#f59e0b'},
    ready: {txt:'conectado y listo para vender.', color:'#16a34a'},
    message_in: {txt:'conectado, recibiendo mensajes.', color:'#16a34a'},
    disconnected: {txt:'desconectado. revisa servicio.', color:'#7c3aed'},
    auth_failure: {txt:'fallo de autenticación. vuelve a vincular.', color:'#7c3aed'},
    stopped: {txt:'servicio detenido.', color:'#334155'},
    unknown: {txt:'sin estado disponible.', color:'#8b5cf6'}
  };
  const cfg = map[st] || {txt:st,color:'#8b5cf6'};
  s.textContent = 'Estado real: ' + cfg.txt;
  if(ledDot){
    ledDot.style.background = cfg.color;
    ledDot.style.boxShadow = `0 0 0 4px ${cfg.color}33`;
  }
  if(ledText){
    ledText.textContent = cfg.txt;
    ledText.style.color = cfg.color;
  }
}
async function showBridgeQr(){
  if(!lastBridgeState){
    await loadBridgeStatus();
  }
  const b=lastBridgeState||{};
  const qr=String(b.qr||'').trim();
  const c=document.getElementById('qrCanvas');
  const h=document.getElementById('qrHelp');
  c.innerHTML='';
  if(qr){
    new QRCode(c,{text:qr,width:260,height:260,correctLevel:QRCode.CorrectLevel.M});
    h.textContent='Escanea este QR desde WhatsApp > Dispositivos vinculados.';
  }else{
    h.textContent='No hay QR disponible ahora. Si ya está conectado, no hace falta escanear. Si no, reinicia el bridge.';
  }
  const m=new bootstrap.Modal(document.getElementById('qrModal'));
  m.show();
}
async function restartBridge(){
  const d=await p(API+'?action=bridge_restart',{});
  if(d.status==='success'){
    a('success',d.msg||'Bridge reiniciado');
    setTimeout(()=>loadBridgeStatus(),1200);
    return;
  }
  const detail = d.detail ? ' Detalle: '+String(d.detail) : '';
  a('danger',(d.msg||'No se pudo reiniciar bridge') + detail);
}
async function loadBridgeLogs(){
  const d=await g(API+'?action=bridge_logs');
  const t=document.getElementById('logsText');
  const st=document.getElementById('logsBridgeState');
  if(d.status!=='success'){t.textContent='No se pudo cargar logs.';st.textContent='';return;}
  const b=d.bridge||{};
  st.textContent='Estado bridge: '+(b.state||'desconocido')+' | actualiza: '+(b.updated_at||'-');
  t.textContent=(d.logs&&String(d.logs).trim()!=='')?String(d.logs):'Sin logs disponibles (o sin permisos de journal para el usuario web).';
}
async function showBridgeLogs(){
  await loadBridgeLogs();
  const m=new bootstrap.Modal(document.getElementById('logsModal'));
  m.show();
}
async function testBot(){const d=await p(API+'?action=test_incoming',{wa_user_id:twa.value.trim()||'5350000000',wa_name:tname.value.trim()||'Cliente Test',text:ttext.value.trim()||'MENU'});if(d.status==='success'){a('success','Procesado');loadAll()} else a('danger',d.msg||'error')}
function renderPromoChats(){
  const w=document.getElementById('promoChatsWrap');
  if(!w) return;
  if(!promoChats.length){w.innerHTML='<div class="text-muted small">Sin chats detectados aún. Verifica estado listo y refresca.</div>';renderMyGroupOptions();return;}
  w.innerHTML=promoChats.map((c,i)=>`<label class="d-flex align-items-center gap-2 py-1 border-bottom small">
      <input type="checkbox" class="form-check-input promo-chat" data-idx="${i}">
      <span class="badge ${c.is_group?'bg-primary':'bg-secondary'}">${c.is_group?'Grupo':'Chat'}</span>
      <span>${esc(c.name||c.id)}</span>
      <span class="text-muted ms-auto">${esc(c.id)}</span>
    </label>`).join('');
  renderMyGroupOptions();
}
async function loadPromoChats(){
  const d=await g(API+'?action=promo_chats');
  if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar chats');return;}
  promoChats=Array.isArray(d.rows)?d.rows:[];
  renderPromoChats();
}
function renderMyGroupOptions(){
  const w=document.getElementById('myGroupWrap');
  if(!w) return;
  const groups=promoChats.filter(c=>Number(c.is_group||0)===1);
  if(!groups.length){
    w.innerHTML='<div class="text-muted small">Sin grupos detectados aún. Conecta WhatsApp Web y refresca.</div>';
    return;
  }
  const selected=document.querySelector('input[name="my_group_chat"]:checked')?.value||'';
  w.innerHTML=groups.map(c=>`<label class="d-flex align-items-center gap-2 py-1 border-bottom small">
      <input type="radio" name="my_group_chat" class="form-check-input" value="${esc(c.id)}" ${selected===String(c.id)?'checked':''}>
      <span class="badge bg-primary">Grupo</span>
      <span>${esc(c.name||c.id)}</span>
      <span class="text-muted ms-auto">${esc(c.id)}</span>
    </label>`).join('');
}
function selectedMyGroup(){
  const id=document.querySelector('input[name="my_group_chat"]:checked')?.value||'';
  if(!id) return null;
  return promoChats.find(c=>String(c.id)===String(id))||null;
}
function renderMyGroupPreview(payload, groupName){
  const box=document.getElementById('myGroupPreview');
  if(!box) return;
  const products=Array.isArray(payload?.products)?payload.products:[];
  const reservables=Array.isArray(payload?.reservables)?payload.reservables:[];
  box.innerHTML=[
    `<div class="fw-semibold mb-2">Vista previa para ${esc(groupName||'grupo seleccionado')}</div>`,
    `<div class="mb-2"><span class="badge bg-success">${products.length}</span> productos con existencias</div>`,
    `<div class="mb-2"><span class="badge bg-info text-dark">${reservables.length}</span> productos reservables al cierre</div>`,
    `<div class="text-muted">Texto final:</div>`,
    `<pre class="mb-0 mt-2" style="white-space:pre-wrap;font-family:inherit">${esc(payload?.outro_text||'')}</pre>`
  ].join('');
}
async function loadMyGroupPreview(){
  const box=document.getElementById('myGroupPreview');
  if(!box) return;
  const target=selectedMyGroup();
  if(!target){
    box.innerHTML='Selecciona un grupo para ver la vista previa.';
    return;
  }
  box.innerHTML='Cargando vista previa...';
  const d=await g(API+'?action=promo_my_group_payload');
  if(d.status!=='success'){
    box.innerHTML='No se pudo generar la vista previa.';
    a('danger',d.msg||'No se pudo cargar Mi grupo');
    return;
  }
  renderMyGroupPreview(d, target.name||target.id);
}
function renderPromoTemplates(){
  const s=document.getElementById('promoTemplateSelect');
  if(!s) return;
  const opts=['<option value="">(Sin plantilla)</option>'].concat(
    promoTemplates.map(t=>`<option value="${esc(t.id)}">${esc(t.name||t.id)}</option>`)
  );
  s.innerHTML=opts.join('');
}
async function loadPromoTemplates(){
  const d=await g(API+'?action=promo_templates');
  if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar plantillas');return;}
  promoTemplates=Array.isArray(d.rows)?d.rows:[];
  renderPromoTemplates();
  renderProgrammingTab();
}
async function savePromoTemplate(){
  const name=(promoTemplateName.value||'').trim();
  const text=(promoText.value||'').trim();
  if(!name){a('danger','Pon nombre a la plantilla');return;}
  if(!text && !promoProducts.length){a('danger','La plantilla no puede estar vacía');return;}
  const currentId=(promoTemplateSelect.value||'').trim();
  const d=await p(API+'?action=promo_template_save',{id:currentId,name,text,products:promoProducts});
  if(d.status==='success'){
    a('success','Plantilla guardada');
    await loadPromoTemplates();
    promoTemplateSelect.value=d.id||'';
  } else a('danger',d.msg||'No se pudo guardar plantilla');
}
function applyPromoTemplate(){
  const id=(promoTemplateSelect.value||'').trim();
  if(!id){a('danger','Selecciona una plantilla');return;}
  const t=promoTemplates.find(x=>String(x.id)===id);
  if(!t){a('danger','Plantilla no encontrada');return;}
  promoTemplateName.value=t.name||'';
  promoText.value=t.text||'';
  promoProducts=Array.isArray(t.products)?t.products:[];
  renderPromoProducts();
  a('info','Plantilla cargada');
}
async function deletePromoTemplate(){
  const id=(promoTemplateSelect.value||'').trim();
  if(!id){a('danger','Selecciona una plantilla');return;}
  const d=await p(API+'?action=promo_template_delete',{id});
  if(d.status==='success'){
    a('success','Plantilla eliminada');
    await loadPromoTemplates();
    promoTemplateName.value='';
  } else a('danger',d.msg||'No se pudo eliminar plantilla');
}
function selectedPromoDays(){
  return [...document.querySelectorAll('.promo-day:checked')].map(x=>parseInt(x.value,10)).filter(x=>!Number.isNaN(x));
}
function daysToText(days){
  const map={0:'D',1:'L',2:'M',3:'X',4:'J',5:'V',6:'S'};
  const arr=(Array.isArray(days)?days:[]).map(x=>parseInt(x,10)).filter(x=>map[x]!==undefined);
  return arr.length?arr.map(x=>map[x]).join(','):'-';
}
function targetsToText(targets){
  const arr=Array.isArray(targets)?targets:[];
  if(!arr.length) return '-';
  return arr.map(t=>String(t.name||t.id||'')).filter(Boolean).join(' | ');
}
function selectAllPromoChats(v){
  document.querySelectorAll('.promo-chat').forEach(x=>x.checked=!!v);
}
function renderPromoProducts(){
  const w=document.getElementById('promoProductsWrap');
  if(!w) return;
  if(!promoProducts.length){w.innerHTML='<div class="text-muted small">Sin productos seleccionados.</div>';return;}
  w.innerHTML=promoProducts.map((p,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-1">
    <img src="${esc(p.image||'')}" alt="" width="42" height="42" style="object-fit:cover;border-radius:6px;border:1px solid #ddd">
    <div class="small"><div class="fw-semibold">${esc(p.name)}</div><div class="text-muted">$${Number(p.price||0).toFixed(2)} · ${esc(p.id)}</div></div>
    <button class="btn btn-sm btn-outline-danger ms-auto" type="button" onclick="removePromoProduct(${idx})"><i class="fas fa-times"></i></button>
  </div>`).join('');
}
function removePromoProduct(idx){promoProducts.splice(idx,1);renderPromoProducts();}
function addPromoProduct(p){
  if(!p||!p.id) return;
  if(promoProducts.some(x=>String(x.id)===String(p.id))) return;
  promoProducts.push(p);
  renderPromoProducts();
}
async function searchPromoProducts(q){
  const box=document.getElementById('promoSearchRes');
  if(!box) return;
  if(!q || q.trim().length<2){box.innerHTML='';return;}
  const d=await g(API+'?action=promo_products&q='+encodeURIComponent(q.trim()));
  if(d.status!=='success'){box.innerHTML='';return;}
  const rows=Array.isArray(d.rows)?d.rows:[];
  box.innerHTML=rows.map(r=>`<button class="list-group-item list-group-item-action d-flex align-items-center gap-2" type="button" onclick='addPromoProduct(${JSON.stringify(r).replace(/'/g,"&#39;")});document.getElementById("promoSearchRes").innerHTML="";'>
    <img src="${esc(r.image||'')}" alt="" width="32" height="32" style="object-fit:cover;border-radius:5px;border:1px solid #ddd">
    <span class="small">${esc(r.name)} <span class="text-muted">(${esc(r.id)})</span> - $${Number(r.price||0).toFixed(2)}</span>
  </button>`).join('');
}
async function createPromoCampaign(){
  try{
    const text=(promoText.value||'').trim();
    const campaignName=(promoCampaignName.value||'').trim();
    const campaignGroup=(promoCampaignGroup.value||'').trim()||'General';
    const scheduleTime=(promoScheduleTime.value||'').trim();
    const scheduleDays=selectedPromoDays();
    const minSec=Math.max(60,parseInt(promoMinSec.value||'60',10)||60);
    const maxSec=Math.max(minSec,parseInt(promoMaxSec.value||'120',10)||120);
    const targets=[...document.querySelectorAll('.promo-chat:checked')].map(ch=>promoChats[parseInt(ch.dataset.idx,10)]).filter(Boolean);
    if(!text){a('danger','Escribe el texto de promoción');return;}
    if(!targets.length){a('danger','Selecciona al menos un grupo/chat');return;}
    if(!promoProducts.length){a('danger','Selecciona al menos un producto');return;}
    if(!scheduleTime){a('danger','Selecciona hora de lanzamiento');return;}
    if(!scheduleDays.length){a('danger','Selecciona al menos un día');return;}
    const d=await p(API+'?action=promo_create',{
      campaign_name:campaignName,
      campaign_group:campaignGroup,
      template_id:(promoTemplateSelect.value||'').trim(),
      text,
      targets,
      products:promoProducts,
      min_seconds:minSec,
      max_seconds:maxSec,
      schedule_enabled:1,
      schedule_time:scheduleTime,
      schedule_days:scheduleDays
    });
    if(d.status==='success'){a('success','Campaña programada: '+(d.id||''));loadPromoList();} else a('danger',d.msg||'Error al crear campaña');
  }catch(e){
    a('danger','No se pudo programar la campaña: '+(e?.message||'error inesperado'));
  }
}
async function createMyGroupCampaign(){
  try{
    const target=selectedMyGroup();
    const scheduleTime=(myGroupScheduleTime.value||'').trim();
    const campaignGroup=(myGroupCampaignGroup.value||'').trim()||'Mi grupo';
    if(!target){a('danger','Selecciona un grupo');return;}
    if(!scheduleTime){a('danger','Selecciona la hora diaria');return;}
    const payload=await g(API+'?action=promo_my_group_payload');
    if(payload.status!=='success'){a('danger',payload.msg||'No se pudo preparar Mi grupo');return;}
    const products=Array.isArray(payload.products)?payload.products:[];
    if(!products.length){a('danger','No hay productos con existencias disponibles');return;}
    const d=await p(API+'?action=promo_create',{
      campaign_name:'Mi grupo diario',
      campaign_group:campaignGroup,
      text:'',
      outro_text:String(payload.outro_text||'').trim(),
      targets:[target],
      products,
      min_seconds:60,
      max_seconds:120,
      schedule_enabled:1,
      schedule_time:scheduleTime,
      schedule_days:[0,1,2,3,4,5,6]
    });
    if(d.status==='success'){
      renderMyGroupPreview(payload, target.name||target.id);
      a('success','Mi grupo programado: '+(d.id||''));
      loadPromoList();
    } else a('danger',d.msg||'Error al crear Mi grupo');
  }catch(e){
    a('danger','No se pudo programar Mi grupo: '+(e?.message||'error inesperado'));
  }
}
async function loadPromoList(){
  const d=await g(API+'?action=promo_list');
  const tb=document.getElementById('promoRows');
  if(!tb) return;
  if(d.status!=='success'){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr>';promoCampaigns=[];renderProgrammingTab();return;}
  promoCampaigns=Array.isArray(d.rows)?d.rows:[];
  if(!promoCampaigns.length){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr>';renderProgrammingTab();return;}
  tb.innerHTML=promoCampaigns.map(r=>`<tr>
    <td class="small">${esc(r.created_at||'')}</td>
    <td class="small">${esc(r.name||r.id||'')}</td>
    <td class="small">${esc(r.campaign_group||'General')}</td>
    <td class="small">${esc(r.schedule_time||'-')} (${esc(daysToText(r.schedule_days||[]))})<br><span class="text-muted">${esc(targetsToText(r.targets||[]))}</span></td>
    <td><span class="badge ${r.status==='done'?'bg-success':(r.status==='error'?'bg-danger':'bg-warning text-dark')}">${esc(r.status||'')}</span></td>
    <td class="small">${Number(r.current_index||0)}/${(r.targets||[]).length}</td>
  </tr>`).join('');
  renderProgrammingTab();
}
async function deleteScheduledCampaign(id){
  const row=promoCampaigns.find(x=>String(x.id)===String(id));
  if(!row){a('danger','Campaña no encontrada');return;}
  if(!confirm(`¿Eliminar campaña "${row.name||row.id}"?`)) return;
  const d=await p(API+'?action=promo_delete',{id});
  if(d.status==='success'){a('success','Campaña eliminada');loadPromoList();} else a('danger',d.msg||'No se pudo eliminar');
}
async function forceCampaignNow(id){
  const row=promoCampaigns.find(x=>String(x.id)===String(id));
  if(!row){a('danger','Campaña no encontrada');return;}
  if(!confirm(`¿Lanzar ahora la campaña "${row.name||row.id}"?`)) return;
  const d=await p(API+'?action=promo_force_now',{id});
  if(d.status==='success'){a('success','Campaña enviada a cola para ejecutar ahora');loadPromoList();} else a('danger',d.msg||'No se pudo forzar');
}
async function editScheduledCampaign(id){
  const row=promoCampaigns.find(x=>String(x.id)===String(id));
  if(!row){a('danger','Campaña no encontrada');return;}
  const name=prompt('Nombre de campaña:',row.name||'');
  if(name===null) return;
  const group=prompt('Grupo de campaña:',row.campaign_group||'General');
  if(group===null) return;
  const time=prompt('Hora (HH:MM) zona Cuba (America/Havana):',row.schedule_time||'09:00');
  if(time===null) return;
  const daysCurrent=Array.isArray(row.schedule_days)?row.schedule_days.join(','):'1,2,3,4,5';
  const daysRaw=prompt('Días (0..6 separados por coma). 0=Dom,1=Lun,...,6=Sab',daysCurrent);
  if(daysRaw===null) return;
  const days=String(daysRaw).split(',').map(x=>parseInt(String(x).trim(),10)).filter(x=>!Number.isNaN(x) && x>=0 && x<=6);
  if(!days.length){a('danger','Debes indicar al menos un día válido (0..6)');return;}

  const d=await p(API+'?action=promo_update',{
    id,
    name:String(name).trim(),
    campaign_group:String(group).trim()||'General',
    schedule_enabled:1,
    schedule_time:String(time).trim(),
    schedule_days:days,
    status:'scheduled'
  });
  if(d.status==='success'){a('success','Campaña actualizada');loadPromoList();} else a('danger',d.msg||'No se pudo actualizar');
}
function renderCampaignLogsModal(job){
  const summary=document.getElementById('campaignLogsSummary');
  const rowsEl=document.getElementById('campaignLogsRows');
  const logs=Array.isArray(job.log)?job.log:[];
  const ok=logs.filter(x=>x && x.ok===true).length;
  const fail=logs.filter(x=>x && x.ok===false).length;
  const sent=logs.reduce((acc,x)=>acc + Number((x&&x.messages_sent)||0),0);
  const targets=(Array.isArray(job.targets)?job.targets:[]).map(t=>String(t.name||t.id||'')).join(' | ');
  summary.textContent=`Campaña: ${job.name||job.id||'-'} | Grupo: ${job.campaign_group||'General'} | Estado: ${job.status||'-'} | Mensajes enviados: ${sent} | OK: ${ok} | Fallos: ${fail} | Destinos: ${targets||'-'}`;
  if(!logs.length){
    rowsEl.innerHTML='<tr><td colspan="5" class="text-center text-muted p-3">Sin logs aún</td></tr>';
    return;
  }
  rowsEl.innerHTML=logs.slice().reverse().map(l=>`<tr>
    <td class="small">${esc(l.at||'-')}</td>
    <td class="small">${esc(l.target_name||l.target_id||'-')}</td>
    <td class="small">${Number(l.messages_sent||0)}</td>
    <td>${l.ok===true?'<span class="badge bg-success">OK</span>':'<span class="badge bg-danger">Fallo</span>'}</td>
    <td class="small text-danger">${esc(l.error||'')}</td>
  </tr>`).join('');
}
async function refreshCampaignLogs(){
  if(!activeCampaignLogId) return;
  const d=await g(API+'?action=promo_detail&id='+encodeURIComponent(activeCampaignLogId));
  if(d.status!=='success' || !d.row){a('danger',d.msg||'No se pudieron cargar logs');return;}
  renderCampaignLogsModal(d.row);
}
async function openCampaignLogs(id){
  activeCampaignLogId=String(id||'');
  await refreshCampaignLogs();
  const m=new bootstrap.Modal(document.getElementById('campaignLogsModal'));
  m.show();
}
function renderProgrammingTab(){
  const tplRows=document.getElementById('promoTemplateRows');
  if(tplRows){
    if(!promoTemplates.length){
      tplRows.innerHTML='<tr><td colspan="3" class="text-center text-muted p-3">Sin plantillas</td></tr>';
    }else{
      tplRows.innerHTML=promoTemplates.map(t=>`<tr>
        <td class="small fw-semibold">${esc(t.name||t.id||'-')}</td>
        <td class="small">${Array.isArray(t.products)?t.products.length:0}</td>
        <td class="small">${esc(t.updated_at||'-')}</td>
      </tr>`).join('');
    }
  }

  const wrap=document.getElementById('promoProgramGroups');
  if(!wrap) return;
  const scheduled=promoCampaigns.filter(r=>Number(r.schedule_enabled||0)===1);
  if(!scheduled.length){
    wrap.innerHTML='<div class="text-muted small">Sin campañas programadas.</div>';
    return;
  }
  const groups={};
  for(const r of scheduled){
    const g=String(r.campaign_group||'General');
    if(!groups[g]) groups[g]=[];
    groups[g].push(r);
  }
  wrap.innerHTML=Object.keys(groups).sort().map(g=>`<div class="border rounded p-2 mb-2">
    <div class="fw-semibold mb-1">${esc(g)}</div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Campaña</th><th>Hora</th><th>Días</th><th>Estado</th><th>Destinos</th><th>Chats/Grupos</th><th>Acciones</th></tr></thead>
        <tbody>
          ${groups[g].map(r=>`<tr>
            <td class="small">${esc(r.name||r.id||'-')}</td>
            <td class="small">${esc(r.schedule_time||'-')}</td>
            <td class="small">${esc(daysToText(r.schedule_days||[]))}</td>
            <td><span class="badge ${r.status==='scheduled'?'bg-info text-dark':(r.status==='running'?'bg-warning text-dark':(r.status==='done'?'bg-success':(r.status==='error'?'bg-danger':'bg-secondary')))}">${esc(r.status||'-')}</span></td>
            <td class="small">${Array.isArray(r.targets)?r.targets.length:0}</td>
            <td class="small text-muted">${esc(targetsToText(r.targets||[]))}</td>
            <td class="small">
              <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-success" type="button" title="Enviar ahora" onclick="forceCampaignNow('${esc(r.id||'')}')"><i class="fas fa-bolt"></i></button>
                <button class="btn btn-sm btn-outline-dark" type="button" title="Ver logs" onclick="openCampaignLogs('${esc(r.id||'')}')"><i class="fas fa-list-check"></i></button>
                <button class="btn btn-sm btn-outline-primary" type="button" onclick="editScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-pen"></i></button>
                <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
  </div>`).join('');
}
async function loadAll(){try{await Promise.all([loadCfg(),loadStats(),loadMsgs(),loadOrders(),loadBridgeStatus(),loadPromoList(),loadPromoChats(),loadPromoTemplates()])}catch(e){a('danger',e.message||'error')}}
function openWhatsAppWeb(){
  const w = window.open('https://web.whatsapp.com/','_blank','noopener,noreferrer');
  const s = document.getElementById('waWebStatus');
  if (w) {
    s.textContent = 'Estado: web.whatsapp.com abierto. Escanea el QR desde tu teléfono.';
    return;
  }
  s.textContent = 'Estado: solicitud enviada. Si ya se abrió WhatsApp Web, escanea el QR desde tu teléfono.';
  a('info','Si la pestaña ya se abrió, la conexión está lista para escanear QR.');
}

document.getElementById('f').addEventListener('submit',saveCfg);
wa_mode.addEventListener('change',applyModeUI);
document.getElementById('promoSearch').addEventListener('input',ev=>{
  if(promoSearchTimer) clearTimeout(promoSearchTimer);
  promoSearchTimer=setTimeout(()=>searchPromoProducts(ev.target.value||''),260);
});
document.addEventListener('change',ev=>{
  if(ev.target && ev.target.matches('input[name="my_group_chat"]')) loadMyGroupPreview();
});
loadAll();
setInterval(()=>{loadStats();loadMsgs();loadOrders();loadBridgeStatus();loadPromoList();loadPromoChats();loadPromoTemplates()},12000);
</script>
<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
