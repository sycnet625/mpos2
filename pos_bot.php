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

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-bold">Configuración</div>
        <div class="card-body">
          <form id="f" class="row g-2">
            <div class="col-12 form-check form-switch mb-1"><input class="form-check-input" id="enabled" type="checkbox"><label class="form-check-label" for="enabled">Habilitar BOT</label></div>
            <div class="col-md-6"><label class="form-label">Nombre negocio</label><input id="business_name" class="form-control" maxlength="120"></div>
            <div class="col-md-6"><label class="form-label">Verify token</label><input id="verify_token" class="form-control" maxlength="120"></div>
            <div class="col-md-6"><label class="form-label">Phone Number ID</label><input id="wa_phone_number_id" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Access Token</label><input id="wa_access_token" type="password" class="form-control" placeholder="vacío = conservar"></div>
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

<script>
const API='pos_bot_api.php';
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const a=(t,m)=>{const e=document.getElementById('alertBox');e.innerHTML=`<div class="alert alert-${t} py-2">${esc(m)}</div>`;setTimeout(()=>e.innerHTML='',3500)};
async function g(u){const r=await fetch(u,{credentials:'same-origin'});return r.json()}
async function p(u,d){const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});return r.json()}

async function loadCfg(){const d=await g(API+'?action=get_config');if(d.status!=='success')throw new Error(d.msg||'error');const c=d.config||{};enabled.checked=Number(c.enabled)===1;verify_token.value=c.verify_token||'';wa_phone_number_id.value=c.wa_phone_number_id||'';business_name.value=c.business_name||'';welcome_message.value=c.welcome_message||'';menu_intro.value=c.menu_intro||'';no_match_message.value=c.no_match_message||'';}
async function saveCfg(ev){ev.preventDefault();const d=await p(API+'?action=save_config',{enabled:enabled.checked?1:0,verify_token:verify_token.value.trim(),wa_phone_number_id:wa_phone_number_id.value.trim(),wa_access_token:wa_access_token.value.trim(),business_name:business_name.value.trim(),welcome_message:welcome_message.value.trim(),menu_intro:menu_intro.value.trim(),no_match_message:no_match_message.value.trim()});if(d.status==='success'){wa_access_token.value='';a('success','Guardado');loadAll()} else a('danger',d.msg||'error');}
async function loadStats(){const d=await g(API+'?action=stats');if(d.status!=='success')return;s1.textContent=d.stats.sessions||0;s2.textContent=d.stats.msgs_today||0;s3.textContent=d.stats.orders_today||0;s4.textContent='$'+Number(d.stats.sales_today||0).toFixed(2)}
async function loadMsgs(){const d=await g(API+'?action=recent_messages');if(d.status!=='success'||!(d.rows||[]).length){tm.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr>';return;}tm.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td class="small">${esc(r.wa_user_id)}</td><td>${esc(r.direction)}</td><td class="small">${esc((r.message_text||'').slice(0,120))}</td></tr>`).join('')}
async function loadOrders(){const d=await g(API+'?action=recent_orders');if(d.status!=='success'||!(d.rows||[]).length){to.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr>';return;}to.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td>#${esc(r.id_pedido)}</td><td class="small">${esc(r.cliente_nombre||r.wa_user_id)}</td><td>$${Number(r.total||0).toFixed(2)}</td></tr>`).join('')}
async function testBot(){const d=await p(API+'?action=test_incoming',{wa_user_id:twa.value.trim()||'5350000000',wa_name:tname.value.trim()||'Cliente Test',text:ttext.value.trim()||'MENU'});if(d.status==='success'){a('success','Procesado');loadAll()} else a('danger',d.msg||'error')}
async function loadAll(){try{await Promise.all([loadCfg(),loadStats(),loadMsgs(),loadOrders()])}catch(e){a('danger',e.message||'error')}}

document.getElementById('f').addEventListener('submit',saveCfg);
loadAll();setInterval(()=>{loadStats();loadMsgs();loadOrders()},12000);
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
