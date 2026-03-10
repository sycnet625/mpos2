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
  </div>

  <ul class="nav nav-tabs mb-3" id="botTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-bot-btn" data-bs-toggle="tab" data-bs-target="#tab-bot" type="button" role="tab">BOT</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-promo-btn" data-bs-toggle="tab" data-bs-target="#tab-promo" type="button" role="tab">Promoción</button>
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
            <div id="row_phone_id" class="col-md-6"><label class="form-label">Phone Number ID</label><input id="wa_phone_number_id" class="form-control"></div>
            <div id="row_access_token" class="col-md-6"><label class="form-label">Access Token</label><input id="wa_access_token" type="password" class="form-control" placeholder="vacío = conservar"></div>
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
      <div class="card mb-3"><div class="card-header bg-white fw-bold">Mensajes</div><div class="card-body p-0"><div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Fecha</th><th>WA</th><th>Dir</th><th>Texto</th></tr></thead><tbody id="tm"><tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr></tbody></table></div></div></div>
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
            <div class="mb-2">
              <label class="form-label">Texto de promoción</label>
              <textarea id="promoText" class="form-control" rows="3" placeholder="Ej: Oferta especial solo hoy..."></textarea>
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
            <span>Campañas recientes</span>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive" style="max-height:260px">
              <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Fecha</th><th>ID</th><th>Estado</th><th>Progreso</th></tr></thead>
                <tbody id="promoRows"><tr><td colspan="4" class="text-center text-muted p-3">Sin campañas</td></tr></tbody>
              </table>
            </div>
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

<script>
const API='pos_bot_api.php';
let lastBridgeState=null;
let promoChats=[];
let promoProducts=[];
let promoSearchTimer=null;
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const a=(t,m)=>{const e=document.getElementById('alertBox');e.innerHTML=`<div class="alert alert-${t} py-2">${esc(m)}</div>`;setTimeout(()=>e.innerHTML='',3500)};
async function g(u){const r=await fetch(u,{credentials:'same-origin'});return r.json()}
async function p(u,d){const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});return r.json()}

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
async function loadMsgs(){const d=await g(API+'?action=recent_messages');if(d.status!=='success'||!(d.rows||[]).length){tm.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr>';return;}tm.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td class="small">${esc(r.wa_user_id)}</td><td>${esc(r.direction)}</td><td class="small">${esc((r.message_text||'').slice(0,120))}</td></tr>`).join('')}
async function loadOrders(){const d=await g(API+'?action=recent_orders');if(d.status!=='success'||!(d.rows||[]).length){to.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr>';return;}to.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td>#${esc(r.id_pedido)}</td><td class="small">${esc(r.cliente_nombre||r.wa_user_id)}</td><td>$${Number(r.total||0).toFixed(2)}</td></tr>`).join('')}
async function loadBridgeStatus(){
  const s = document.getElementById('waWebStatus');
  const d = await g(API+'?action=bridge_status');
  if(d.status!=='success' || !d.bridge){s.textContent='Estado: sin datos del bridge';return;}
  lastBridgeState=d.bridge;
  const st = String(d.bridge.state||'unknown');
  const map = {
    starting: 'iniciando servicio...',
    qr_required: 'esperando escaneo QR en servicio.',
    authenticated: 'sesion autenticada, cargando...',
    ready: 'conectado y listo para vender.',
    message_in: 'conectado, recibiendo mensajes.',
    disconnected: 'desconectado. revisa servicio.',
    auth_failure: 'fallo de autenticacion. vuelve a vincular.',
    stopped: 'servicio detenido.',
    unknown: 'sin estado disponible.'
  };
  s.textContent = 'Estado real: ' + (map[st] || st);
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
  a('danger',d.msg||'No se pudo reiniciar bridge');
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
  if(!promoChats.length){w.innerHTML='<div class="text-muted small">Sin chats detectados aún. Verifica estado listo y refresca.</div>';return;}
  w.innerHTML=promoChats.map((c,i)=>`<label class="d-flex align-items-center gap-2 py-1 border-bottom small">
      <input type="checkbox" class="form-check-input promo-chat" data-idx="${i}">
      <span class="badge ${c.is_group?'bg-primary':'bg-secondary'}">${c.is_group?'Grupo':'Chat'}</span>
      <span>${esc(c.name||c.id)}</span>
      <span class="text-muted ms-auto">${esc(c.id)}</span>
    </label>`).join('');
}
async function loadPromoChats(){
  const d=await g(API+'?action=promo_chats');
  if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar chats');return;}
  promoChats=Array.isArray(d.rows)?d.rows:[];
  renderPromoChats();
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
  const text=(promoText.value||'').trim();
  const minSec=Math.max(60,parseInt(promoMinSec.value||'60',10)||60);
  const maxSec=Math.max(minSec,parseInt(promoMaxSec.value||'120',10)||120);
  const targets=[...document.querySelectorAll('.promo-chat:checked')].map(ch=>promoChats[parseInt(ch.dataset.idx,10)]).filter(Boolean);
  if(!text){a('danger','Escribe el texto de promoción');return;}
  if(!targets.length){a('danger','Selecciona al menos un grupo/chat');return;}
  if(!promoProducts.length){a('danger','Selecciona al menos un producto');return;}
  const d=await p(API+'?action=promo_create',{text,targets,products:promoProducts,min_seconds:minSec,max_seconds:maxSec});
  if(d.status==='success'){a('success','Campaña programada: '+(d.id||''));loadPromoList();} else a('danger',d.msg||'Error al crear campaña');
}
async function loadPromoList(){
  const d=await g(API+'?action=promo_list');
  const tb=document.getElementById('promoRows');
  if(!tb) return;
  if(d.status!=='success' || !(d.rows||[]).length){tb.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin campañas</td></tr>';return;}
  tb.innerHTML=d.rows.map(r=>`<tr>
    <td class="small">${esc(r.created_at||'')}</td>
    <td class="small">${esc(r.id||'')}</td>
    <td><span class="badge ${r.status==='done'?'bg-success':(r.status==='error'?'bg-danger':'bg-warning text-dark')}">${esc(r.status||'')}</span></td>
    <td class="small">${Number(r.current_index||0)}/${(r.targets||[]).length}</td>
  </tr>`).join('');
}
async function loadAll(){try{await Promise.all([loadCfg(),loadStats(),loadMsgs(),loadOrders(),loadBridgeStatus(),loadPromoList()])}catch(e){a('danger',e.message||'error')}}
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
loadAll();
loadPromoChats();
setInterval(()=>{loadStats();loadMsgs();loadOrders();loadBridgeStatus();loadPromoList()},12000);
</script>
<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
