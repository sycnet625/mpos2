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
<style>
body{background:#f6f8fc}
.card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.stat{font-size:1.6rem;font-weight:700}
.metric-card{border:1px solid transparent !important;border-radius:14px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
#toastArea{
  position:fixed;
  left:0;
  right:0;
  bottom:14px;
  z-index:2000;
  pointer-events:none;
  padding:0 16px 16px;
}
#alertBox{
  max-width:1280px;
  margin:0 auto;
  pointer-events:auto;
}
#alertBox .toast-msg.slide-in{
  animation: pb-toast-in 220ms ease-out forwards;
}
#alertBox .toast-msg.hide{
  animation: pb-toast-out 220ms ease-in forwards;
}
#alertBox .toast-msg{
  min-height:74px;
  width:100%;
  font-size:1.2rem;
  font-weight:600;
  border:0;
  border-radius:14px;
  padding:1.15rem 1.25rem;
  box-shadow:0 16px 34px rgba(0,0,0,.3);
  backdrop-filter:blur(2px);
  position:relative;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
#alertBox .toast-msg .toast-msg-text{flex:1;}
#alertBox .toast-msg .toast-close{opacity:.9;margin-left:auto;border-radius:999px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;border:1px solid rgba(255,255,255,.45);background:rgba(0,0,0,.12);line-height:1;pointer-events:auto;}
#alertBox .toast-msg.alert-danger{background:linear-gradient(120deg,#991b1b,#dc2626);color:#fff;border-left:10px solid #fca5a5;}
#alertBox .toast-msg.alert-success{background:linear-gradient(120deg,#166534,#16a34a);color:#fff;}
#alertBox .toast-msg.alert-info{background:#0ea5e9;color:#fff;}
#alertBox .toast-msg.alert-warning{
  background:linear-gradient(120deg,#92400e,#b45309);
  color:#fff;
}
@keyframes pb-toast-in{
  from{transform:translateY(24px);opacity:0;}
  to{transform:translateY(0);opacity:1;}
}
@keyframes pb-toast-out{
  from{transform:translateY(0);opacity:1;}
  to{transform:translateY(24px);opacity:0;}
}
#alertBox .is-invalid{
  border-color:#dc2626!important;
  box-shadow:0 0 0 .2rem rgba(220,38,38,.15)!important;
}
.error-block{
  border:2px solid #ef4444!important;
  box-shadow:0 0 0 .2rem rgba(239,68,68,.18)!important;
}
</style>
</head>
<body class="p-3 p-md-4">
<div class="container-fluid" style="max-width:1400px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div><h4 class="mb-0"><i class="fab fa-whatsapp text-success"></i> POS BOT WhatsApp</h4><small class="text-muted">Auto-reply, reservas y ventas por WhatsApp</small></div>
    <button class="btn btn-outline-secondary" onclick="loadAll()"><i class="fas fa-sync"></i> Refrescar</button>
  </div>

  <div id="toastArea" class="px-2">
    <div id="alertBox"></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card metric-card p-3" style="background:#eef6ff !important;border-color:#cfe3ff !important"><div class="text-muted small">Sesiones</div><div id="s1" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card metric-card p-3" style="background:#f3f0ff !important;border-color:#ddd1ff !important"><div class="text-muted small">Mensajes hoy</div><div id="s2" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card metric-card p-3" style="background:#ecfff5 !important;border-color:#c8f1da !important"><div class="text-muted small">Reservas hoy</div><div id="s3" class="stat">0</div></div></div>
    <div class="col-md-3"><div class="card metric-card p-3" style="background:#fff7e8 !important;border-color:#ffe0a8 !important"><div class="text-muted small">Ventas hoy</div><div id="s4" class="stat">$0.00</div></div></div>
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
              <button class="btn btn-outline-danger btn-sm" type="button" onclick="resetBridgeSession()"><i class="fas fa-right-from-bracket"></i> Cerrar sesión y QR nuevo</button>
              <button class="btn btn-outline-dark btn-sm" type="button" onclick="showBridgeLogs()"><i class="fas fa-file-lines"></i> Ver logs bridge</button>
            </div>
            <div id="waWebStatus" class="small text-muted mt-2">Estado: pendiente de apertura</div>
          </div>
          <form id="f" class="row g-2">
            <div class="col-12">
              <div class="form-check form-switch mb-1">
                <input class="form-check-input" id="enabled" type="checkbox">
                <label class="form-check-label" for="enabled">Habilitar autorespuesta BOT</label>
              </div>
              <div class="small text-muted">Si lo apagas manualmente, el bot no respondera. Las campañas siguen funcionando.</div>
            </div>
            <div class="col-md-6">
              <div class="form-check form-switch mb-1">
                <input class="form-check-input" id="auto_schedule_enabled" type="checkbox">
                <label class="form-check-label" for="auto_schedule_enabled">Apagado automatico por horario</label>
              </div>
              <div class="small text-muted">Hora Habana. Dentro de la franja se apaga la autorespuesta; fuera de ella se activa sola si el switch principal esta encendido.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Apagar desde</label>
              <input id="auto_off_start" type="time" class="form-control" value="07:00">
            </div>
            <div class="col-md-3">
              <label class="form-label">Apagar hasta</label>
              <input id="auto_off_end" type="time" class="form-control" value="20:00">
            </div>
            <div class="col-12">
              <div id="botAutoStatus" class="small rounded border px-3 py-2" style="background:#f8fafc;color:#334155">Estado de autorespuesta: cargando...</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Modo de conexión</label>
              <select id="wa_mode" class="form-select">
                <option value="web">WhatsApp Web (QR)</option>
                <option value="meta_api">Meta Cloud API</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Nombre negocio</label><input id="business_name" class="form-control" maxlength="120"></div>
            <div class="col-md-6">
              <label class="form-label">Tono del bot</label>
              <select id="bot_tone" class="form-select">
                <option value="premium">Premium</option>
                <option value="popular_cubano">Popular cubano</option>
                <option value="formal_comercial">Formal comercial</option>
                <option value="muy_cercano">Muy cercano</option>
              </select>
            </div>
            <div class="col-12">
              <div class="border rounded p-3" style="background:#f8fafc">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="fw-semibold">Vista previa del tono</div>
                  <span class="small text-muted">Ejemplo de respuesta al cliente</span>
                </div>
                <div id="botTonePreview" class="small" style="white-space:pre-wrap;line-height:1.55;color:#0f172a">Cargando vista previa...</div>
              </div>
            </div>
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
      <div class="card"><div class="card-header bg-white fw-bold">Reservas BOT</div><div class="card-body p-0"><div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Reserva</th><th>Cliente</th><th>Total</th></tr></thead><tbody id="to"><tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr></tbody></table></div></div></div>
      <div class="card mt-3">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
          <span>Conversaciones activas</span>
          <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadConversations()"><i class="fas fa-sync"></i></button>
        </div>
        <div class="card-body">
          <div class="row g-2 mb-2">
            <div class="col-md-5">
              <input id="conversationSearch" class="form-control form-control-sm" placeholder="Buscar por nombre o WA">
            </div>
            <div class="col-md-7">
              <div class="small text-muted h-100 d-flex align-items-center justify-content-md-end">Toca un KPI para filtrar el panel.</div>
            </div>
          </div>
          <div class="row g-2 mb-2" id="conversationKpis">
            <div class="col-6 col-md-4 col-xl">
              <button class="btn btn-sm w-100 text-start border active" type="button" data-conv-filter="all" style="background:#111827;color:#fff">
                <div class="small opacity-75">Todas</div>
                <div id="kpiConvAll" class="fs-5 fw-bold">0</div>
              </button>
            </div>
            <div class="col-6 col-md-4 col-xl">
              <button class="btn btn-sm w-100 text-start border" type="button" data-conv-filter="human" style="background:#fee2e2;color:#991b1b;border-color:#fecaca !important">
                <div class="small">Humanas</div>
                <div id="kpiConvHuman" class="fs-5 fw-bold">0</div>
              </button>
            </div>
            <div class="col-6 col-md-4 col-xl">
              <button class="btn btn-sm w-100 text-start border" type="button" data-conv-filter="alarm" style="background:#7f1d1d;color:#fff;border-color:#991b1b !important">
                <div class="small">Alarmas</div>
                <div id="kpiConvAlarm" class="fs-5 fw-bold">0</div>
              </button>
            </div>
            <div class="col-6 col-md-4 col-xl">
              <button class="btn btn-sm w-100 text-start border" type="button" data-conv-filter="cart" style="background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe !important">
                <div class="small">Con carrito</div>
                <div id="kpiConvCart" class="fs-5 fw-bold">0</div>
              </button>
            </div>
            <div class="col-6 col-md-4 col-xl">
              <button class="btn btn-sm w-100 text-start border" type="button" data-conv-filter="awaiting" style="background:#fef3c7;color:#92400e;border-color:#fde68a !important">
                <div class="small">Pendientes</div>
                <div id="kpiConvAwaiting" class="fs-5 fw-bold">0</div>
              </button>
            </div>
            <div class="col-6 col-md-4 col-xl">
              <button class="btn btn-sm w-100 text-start border" type="button" data-conv-filter="abandoned" style="background:#e5e7eb;color:#374151;border-color:#d1d5db !important">
                <div class="small">Abandonadas</div>
                <div id="kpiConvAbandoned" class="fs-5 fw-bold">0</div>
              </button>
            </div>
          </div>
          <div id="conversationSummary" class="small text-muted mb-2">Sin conversaciones activas.</div>
          <div id="conversationWrap">
            <div class="text-muted small">Sin conversaciones activas.</div>
          </div>
        </div>
      </div>
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
              <div class="mb-2">
                <input id="promoChatsFilter" class="form-control form-control-sm" placeholder="Filtrar destino (nombre o id)..." autocomplete="off">
              </div>
              <div class="d-flex gap-2 mb-2">
                <button class="btn btn-sm btn-outline-primary" type="button" title="Marcar todos los grupos" onclick="selectAllPromoChats(true)">
                  <i class="fas fa-check-double"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" type="button" title="Desmarcar todos los grupos" onclick="selectAllPromoChats(false)">
                  <i class="fas fa-eraser"></i>
                </button>
                <button id="promoGroupsOnlyBtn" class="btn btn-sm btn-outline-info" type="button" title="Seleccionar solo grupos" onclick="togglePromoGroupsOnly()">
                  <i class="fas fa-users"></i>
                </button>
              </div>
              <div id="promoSelectionSummary" class="small text-muted mb-2">0 chats · 0 grupos seleccionados</div>
              <div id="promoChatsWrap" tabindex="-1" style="max-height:320px;overflow:auto;border:1px solid #e9ecef;border-radius:8px;padding:8px">
                <div class="text-muted small">Sin datos aún.</div>
              </div>

              <hr class="my-3">
              <div class="small fw-semibold mb-2">Listas de grupos de WhatsApp</div>
              <div class="row g-2 align-items-end mb-2">
                <div class="col-12">
                  <label class="form-label">Nombre de lista</label>
                  <input id="promoGroupListName" class="form-control form-control-sm" maxlength="120" placeholder="Ej: Promoción Fin de semana">
                </div>
                <div class="col-sm-6">
                  <button class="btn btn-sm btn-outline-success w-100" type="button" onclick="savePromoGroupList()"><i class="fas fa-save"></i> Guardar lista</button>
                </div>
                <div class="col-sm-6">
                  <button class="btn btn-sm btn-outline-primary w-100" type="button" onclick="savePromoGroupList(true)"><i class="fas fa-sync-alt"></i> Actualizar lista</button>
                </div>
              </div>
              <div id="promoGroupListWrap" style="max-height:220px;overflow:auto;border:1px solid #e9ecef;border-radius:8px;padding:8px">
                <div class="text-muted small">Sin listas aún.</div>
              </div>
              <div class="small text-muted mt-2 mb-0">La lista se guarda con los destinos actualmente seleccionados.</div>
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
            <div class="mb-2">
              <label class="form-label">Banners o logo del texto (máximo 3)</label>
              <input id="promoBannerInput" type="file" class="form-control" accept="image/*" multiple>
              <div class="form-text">Estas imágenes acompañarán el texto promocional como banners publicitarios o logo de empresa.</div>
            <div id="promoBannerWrap" tabindex="-1" class="border rounded p-2 mt-2" style="min-height:84px;max-height:200px;overflow:auto">
                <div class="text-muted small">Sin imágenes cargadas.</div>
              </div>
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
            <div id="promoProductsWrap" tabindex="-1" class="border rounded p-2" style="min-height:100px;max-height:240px;overflow:auto">
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
                <thead class="table-light"><tr><th>Fecha</th><th>Campaña</th><th>Grupo</th><th>Horario</th><th>Estado</th><th>Progreso</th><th>Acciones</th></tr></thead>
                <tbody id="promoRows"><tr><td colspan="7" class="text-center text-muted p-3">Sin campañas</td></tr></tbody>
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
            <div id="myGroupWrap" tabindex="-1" style="max-height:360px;overflow:auto;border:1px solid #e9ecef;border-radius:8px;padding:8px">
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
          <div class="card-body" id="promoTemplateRows">
            <div class="text-center text-muted p-3">Sin plantillas</div>
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

<div class="modal fade" id="conversationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0"><i class="fas fa-comments"></i> Responder conversación</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="conversationModalMeta" class="small text-muted mb-2">-</div>
        <textarea id="conversationReplyText" class="form-control" rows="4" placeholder="Escribe la respuesta manual..."></textarea>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="conversationSendQuick">
          <label class="form-check-label" for="conversationSendQuick">Enviar también accesos rápidos</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" type="button" onclick="sendConversationManual()"><i class="fas fa-paper-plane"></i> Enviar</button>
      </div>
    </div>
  </div>
</div>

<script>
const API='pos_bot_api.php';
let lastBridgeState=null;
let promoChats=[];
let promoProducts=[];
let promoBannerImages=[];
let promoTemplates=[];
let promoCampaigns=[];
let activePromoTemplateId='';
let promoGroupLists=[];
let promoGroupListEditingId='';
let conversationRows=[];
let activeConversationId='';
let activeCampaignLogId='';
let promoSearchTimer=null;
let conversationFilter='all';
let promoChatsSearchTimer=null;
let promoChatsSearchTerm='';
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
let toastTimer=null;
const hideToast=()=>{const e=document.getElementById('alertBox'); if(e) e.innerHTML='';};
function closeToastWithAnim(){
  if(toastTimer){
    clearTimeout(toastTimer);
    toastTimer=null;
  }
  const toast=document.querySelector('#alertBox .toast-msg');
  if(!toast){
    return;
  }
  toast.classList.remove('slide-in');
  toast.classList.add('hide');
  toast.addEventListener('animationend', ()=>{hideToast();}, {once:true});
}
const clearValidationMarks=()=>{
  document.querySelectorAll('.is-invalid,.error-block').forEach(el=>{
    el.classList.remove('is-invalid');
    el.classList.remove('error-block');
    el.removeAttribute('aria-invalid');
  });
  document.querySelectorAll('.invalid-feedback[data-pb-mark]').forEach(el=>el.remove());
};
const markFieldInvalid=(el,msg='')=>{
  if(!el) return;
  const node=el instanceof Element ? el : null;
  if(!node) return;
  if(node.matches('input,select,textarea,.form-control,.form-select')) node.classList.add('is-invalid');
  else node.classList.add('error-block');
  node.setAttribute('aria-invalid','true');
  node.scrollIntoView({behavior:'smooth',block:'center'});
  if(typeof node.focus==='function') node.focus();
  if(msg && !node.nextElementSibling?.classList?.contains('invalid-feedback')) {
    const fb=document.createElement('div');
    fb.className='invalid-feedback d-block';
    fb.setAttribute('data-pb-mark','1');
    fb.textContent=msg;
    node.parentElement?.appendChild(fb);
  }
};
const a=(type,msg,{focusEl=null}={})=>{
  const e=document.getElementById('alertBox');
  if(!e) return;
  const safeMsg=esc(msg||'');
  const cls=type==='danger' ? 'alert-danger' : `alert-${type}`;
  e.innerHTML=`<div class="alert ${cls} toast-msg slide-in" role="status"><span class="toast-msg-text">${safeMsg}</span><span class="toast-close" role="button" aria-label="Cerrar notificación" onclick="closeToastWithAnim()">×</span></div>`;
  const toast=e.querySelector('.toast-msg');
  if(toast){
    toast.addEventListener('click', ()=>closeToastWithAnim());
  }
  if(type==='danger' && focusEl){
    clearValidationMarks();
    markFieldInvalid(focusEl);
  }
  if(toastTimer) clearTimeout(toastTimer);
  toastTimer=setTimeout(closeToastWithAnim,4000);
};
async function parseApiResponse(r){
  const txt=await r.text();
  try{return JSON.parse(txt);}catch(_){
    if((txt||'').includes('<!DOCTYPE html') || (txt||'').includes('<html')) return {status:'error',msg:'Sesión expirada o respuesta inválida del servidor'};
    return {status:'error',msg:'Respuesta no JSON del servidor'};
  }
}
async function g(u){const r=await fetch(u,{credentials:'same-origin'});return parseApiResponse(r)}
async function p(u,d){const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});return parseApiResponse(r)}
async function uploadPromoBanner(file){const fd=new FormData();fd.append('image',file);const r=await fetch(API+'?action=promo_upload_image',{method:'POST',credentials:'same-origin',body:fd});return parseApiResponse(r)}
function renderBotTonePreview(){
  const box=document.getElementById('botTonePreview');
  if(!box) return;
  const name=(tname?.value||'Daniel').trim()||'Daniel';
  const site='www.palweb.net';
  const previews={
    premium:`Hola ${name}, es un placer atenderte.\nPuedo ayudarte a realizar tu pedido con rapidez o mostrarte el catálogo.\nTambién puedes consultar el catálogo y comprar automáticamente en ${site}.`,
    popular_cubano:`Hola ${name}, qué bolá, aquí te atiendo rapidito.\nTú dime lo que quieres y yo te lo voy armando sin lío.\nOye, en ${site} también puedes mirar el catálogo y comprar automático.`,
    formal_comercial:`Buenas ${name}, gracias por contactarnos.\nCon gusto gestiono su pedido o le muestro el menú disponible.\nPuede consultar el catálogo y comprar automáticamente en ${site}.`,
    muy_cercano:`Buenas ${name}, dime qué te apetece y te ayudo enseguida.\nEscríbeme como hablas normalmente y yo te voy guiando.\nTambién puedes ver el catálogo y comprar automático en ${site}.`
  };
  box.textContent=previews[bot_tone?.value||'muy_cercano']||previews.muy_cercano;
}

function applyModeUI(){
  const isMeta = wa_mode.value === 'meta_api';
  row_verify_token.classList.toggle('d-none', !isMeta);
  row_phone_id.classList.toggle('d-none', !isMeta);
  row_access_token.classList.toggle('d-none', !isMeta);
  verify_token.disabled = !isMeta;
  wa_phone_number_id.disabled = !isMeta;
  wa_access_token.disabled = !isMeta;
}
function renderAutoReplyState(state){
  const box=document.getElementById('botAutoStatus');
  if(!box) return;
  const s=state||{};
  const active=Number(s.effective_enabled||0)===1;
  box.style.background=active?'#ecfdf5':'#fff7ed';
  box.style.borderColor=active?'#86efac':'#fdba74';
  box.style.color=active?'#166534':'#9a3412';
  box.textContent='Estado de autorespuesta: ' + (s.reason||'Sin datos');
}
function botAutoPayload(){
  return {
    enabled:enabled.checked?1:0,
    auto_schedule_enabled:auto_schedule_enabled.checked?1:0,
    auto_off_start:(auto_off_start.value||'07:00'),
    auto_off_end:(auto_off_end.value||'20:00')
  };
}
async function loadCfg(){const d=await g(API+'?action=get_config');if(d.status!=='success')throw new Error(d.msg||'error');const c=d.config||{};enabled.checked=Number(c.enabled)===1;auto_schedule_enabled.checked=Number(c.auto_schedule_enabled)===1;auto_off_start.value=c.auto_off_start||'07:00';auto_off_end.value=c.auto_off_end||'20:00';wa_mode.value=(c.wa_mode==='meta_api'?'meta_api':'web');bot_tone.value=(c.bot_tone||'muy_cercano');verify_token.value=c.verify_token||'';wa_phone_number_id.value=c.wa_phone_number_id||'';business_name.value=c.business_name||'';welcome_message.value=c.welcome_message||'';menu_intro.value=c.menu_intro||'';no_match_message.value=c.no_match_message||'';applyModeUI();renderBotTonePreview();renderAutoReplyState(c.auto_reply_state||{});}
async function saveCfg(ev){
  ev.preventDefault();
  clearValidationMarks();
  const mode=(wa_mode.value||'web').trim();
  if(mode==='meta_api'){
    if(!verify_token.value.trim()){a('danger','Ingresa el Verify Token',{focusEl:verify_token});return;}
    if(!wa_phone_number_id.value.trim()){a('danger','Ingresa el Phone Number ID',{focusEl:wa_phone_number_id});return;}
    if(!wa_access_token.value.trim()){a('danger','Ingresa el Access Token',{focusEl:wa_access_token});return;}
  }
  const payload=botAutoPayload();
  payload.wa_mode=mode;
  payload.bot_tone=bot_tone.value;
  payload.verify_token=verify_token.value.trim();
  payload.wa_phone_number_id=wa_phone_number_id.value.trim();
  payload.wa_access_token=wa_access_token.value.trim();
  payload.business_name=business_name.value.trim();
  payload.welcome_message=welcome_message.value.trim();
  payload.menu_intro=menu_intro.value.trim();
  payload.no_match_message=no_match_message.value.trim();
  const d=await p(API+'?action=save_config',payload);
  if(d.status==='success'){
    wa_access_token.value='';
    clearValidationMarks();
    a('success','Guardado');
    loadAll();
  } else a('danger',d.msg||'error');
}
async function saveAutoReplyConfig(msg){
  const d=await p(API+'?action=save_config',botAutoPayload());
  if(d.status==='success'){
    if(msg) a('success',msg);
    await loadCfg();
  } else {
    a('danger',d.msg||'error');
  }
}
async function loadStats(){const d=await g(API+'?action=stats');if(d.status!=='success')return;s1.textContent=d.stats.sessions||0;s2.textContent=d.stats.msgs_today||0;s3.textContent=d.stats.orders_today||0;s4.textContent='$'+Number(d.stats.sales_today||0).toFixed(2)}
async function loadMsgs(){const d=await g(API+'?action=recent_messages');if(d.status!=='success'||!(d.rows||[]).length){tm.innerHTML='<tr><td colspan="5" class="text-center text-muted p-3">Sin datos</td></tr>';return;}tm.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td class="small">${esc(r.wa_user_id)}</td><td class="small">${esc(r.wa_name||'-')}</td><td>${esc(r.direction)}</td><td class="small">${esc((r.message_text||'').slice(0,120))}</td></tr>`).join('')}
async function loadOrders(){const d=await g(API+'?action=recent_orders');if(d.status!=='success'||!(d.rows||[]).length){to.innerHTML='<tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr>';return;}to.innerHTML=d.rows.map(r=>`<tr><td class="small">${esc(r.created_at)}</td><td>#${esc(r.id_pedido)}</td><td class="small">${esc(r.cliente_nombre||r.wa_user_id)}</td><td>$${Number(r.total||0).toFixed(2)}</td></tr>`).join('')}
function conversationBadge(row){
  if(Number(row.escalation_active||0)===1) return `<span class="badge bg-danger text-white">ALARMA ${esc(row.escalation_label||'Humano')}</span>`;
  if(Number(row.bot_paused||0)===1) return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Humano</span>';
  if((row.awaiting_field||'').trim()) return `<span class="badge bg-warning-subtle text-dark border border-warning-subtle">Esperando ${esc(row.awaiting_field)}</span>`;
  return '<span class="badge bg-success-subtle text-success border border-success-subtle">Bot activo</span>';
}
function isConversationAbandoned(row){
  if(Number(row.items_count||0)<=0) return false;
  const ref=row.last_cart_activity_at||row.last_seen||'';
  const ts=Date.parse(ref);
  if(!Number.isFinite(ts)) return false;
  return (Date.now()-ts) > (20*60*1000);
}
function filteredConversations(){
  const q=(document.getElementById('conversationSearch')?.value||'').trim().toLowerCase();
  return conversationRows.filter(r=>{
    if(q){
      const hay=`${String(r.wa_name||'').toLowerCase()} ${String(r.wa_user_id||'').toLowerCase()}`;
      if(!hay.includes(q)) return false;
    }
    if(conversationFilter==='alarm') return Number(r.escalation_active||0)===1;
    if(conversationFilter==='human') return Number(r.bot_paused||0)===1;
    if(conversationFilter==='cart') return Number(r.items_count||0)>0;
    if(conversationFilter==='awaiting') return String(r.awaiting_field||'').trim()!=='';
    if(conversationFilter==='abandoned') return isConversationAbandoned(r);
    return true;
  });
}
function updateConversationKpis(){
  const all=conversationRows.length;
  const alarm=conversationRows.filter(r=>Number(r.escalation_active||0)===1).length;
  const human=conversationRows.filter(r=>Number(r.bot_paused||0)===1).length;
  const cart=conversationRows.filter(r=>Number(r.items_count||0)>0).length;
  const awaiting=conversationRows.filter(r=>String(r.awaiting_field||'').trim()!=='').length;
  const abandoned=conversationRows.filter(r=>isConversationAbandoned(r)).length;
  const ids={
    kpiConvAll:all,
    kpiConvAlarm:alarm,
    kpiConvHuman:human,
    kpiConvCart:cart,
    kpiConvAwaiting:awaiting,
    kpiConvAbandoned:abandoned
  };
  Object.entries(ids).forEach(([id,val])=>{
    const el=document.getElementById(id);
    if(el) el.textContent=String(val);
  });
  document.querySelectorAll('[data-conv-filter]').forEach(btn=>{
    btn.classList.toggle('active',(btn.dataset.convFilter||'all')===conversationFilter);
    btn.style.outline=(btn.dataset.convFilter||'all')===conversationFilter?'2px solid #0f172a':'none';
    btn.style.transform=(btn.dataset.convFilter||'all')===conversationFilter?'translateY(-1px)':'none';
  });
}
function renderConversations(){
  const wrap=document.getElementById('conversationWrap');
  const summary=document.getElementById('conversationSummary');
  if(!wrap) return;
  updateConversationKpis();
  const rows=filteredConversations();
  const alarmCount=conversationRows.filter(r=>Number(r.escalation_active||0)===1).length;
  if(summary) summary.textContent=(alarmCount>0?`ALARMA: ${alarmCount} conversación(es) escaladas a humano. `:'') + (rows.length?`${rows.length} conversación(es) visibles de ${conversationRows.length} total`:'Sin conversaciones activas para ese filtro.');
  if(!rows.length){wrap.innerHTML='<div class="text-muted small">Sin conversaciones activas para ese filtro.</div>';return;}
  wrap.innerHTML=rows.map(r=>`<div class="border rounded p-2 mb-2 ${Number(r.escalation_active||0)===1?'border-danger border-2 bg-danger-subtle':''}">
    <div class="d-flex gap-2 align-items-start">
      <div class="flex-grow-1">
        <div class="fw-semibold">${esc(r.wa_name||r.wa_user_id)}</div>
        <div class="small text-muted">${esc(r.wa_user_id)} · ${esc(r.last_seen||'-')}</div>
        <div class="small mt-1">${conversationBadge(r)} <span class="ms-2">Items: ${Number(r.items_count||0)}</span></div>
        ${Number(r.escalation_active||0)===1?`<div class="small mt-1 text-danger fw-semibold"><i class="fas fa-triangle-exclamation"></i> ${esc(r.escalation_label||'Atención humana')} ${r.escalation_at?`· ${esc(r.escalation_at)}`:''}</div>`:''}
        ${isConversationAbandoned(r)?`<div class="small mt-1"><span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Carrito abandonado</span></div>`:''}
        ${r.customer_address?`<div class="small text-muted mt-1"><i class="fas fa-location-dot"></i> ${esc(r.customer_address)}</div>`:''}
        ${r.last_message_text?`<div class="small mt-1"><span class="text-muted">${esc(r.last_message_dir||'')}:</span> ${esc(String(r.last_message_text||'').slice(0,120))}</div>`:''}
      </div>
      <div class="d-flex flex-column gap-1">
        ${Number(r.bot_paused||0)===1
          ? `<button class="btn btn-sm btn-outline-success" type="button" onclick="resumeConversation('${esc(r.wa_user_id)}')"><i class="fas fa-robot"></i></button>`
          : `<button class="btn btn-sm btn-outline-danger" type="button" onclick="pauseConversation('${esc(r.wa_user_id)}')"><i class="fas fa-user"></i></button>`
        }
        <button class="btn btn-sm btn-outline-primary" type="button" onclick="openConversationModal('${esc(r.wa_user_id)}')"><i class="fas fa-reply"></i></button>
      </div>
    </div>
  </div>`).join('');
}
async function loadConversations(){
  const d=await g(API+'?action=conversation_list');
  if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar conversaciones');return;}
  conversationRows=Array.isArray(d.rows)?d.rows:[];
  renderConversations();
}
async function pauseConversation(wa){
  const d=await p(API+'?action=conversation_pause',{wa_user_id:wa});
  if(d.status==='success'){a('success','Conversación tomada por humano');loadConversations();} else a('danger',d.msg||'No se pudo pausar');
}
async function resumeConversation(wa){
  const d=await p(API+'?action=conversation_resume',{wa_user_id:wa});
  if(d.status==='success'){a('success','Bot reactivado en la conversación');loadConversations();} else a('danger',d.msg||'No se pudo reanudar');
}
function openConversationModal(wa){
  activeConversationId=String(wa||'');
  const row=conversationRows.find(x=>String(x.wa_user_id)===activeConversationId);
  conversationReplyText.value='';
  conversationSendQuick.checked=false;
  conversationModalMeta.textContent=row?`${row.wa_name||row.wa_user_id} · ${row.wa_user_id}`:activeConversationId;
  new bootstrap.Modal(document.getElementById('conversationModal')).show();
}
async function sendConversationManual(){
  if(!activeConversationId){a('danger','Conversación no seleccionada');return;}
  const text=(conversationReplyText.value||'').trim();
  const sendQuick=conversationSendQuick.checked?1:0;
  if(!text && !sendQuick){a('danger','Escribe un mensaje o marca accesos rápidos');return;}
  const d=await p(API+'?action=conversation_send_manual',{wa_user_id:activeConversationId,text,send_quick_actions:sendQuick});
  if(d.status==='success'){
    a('success','Mensaje manual en cola');
    bootstrap.Modal.getInstance(document.getElementById('conversationModal'))?.hide();
    loadConversations();
  } else a('danger',d.msg||'No se pudo enviar');
}
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
async function resetBridgeSession(){
  if(!confirm('Esto cerrará la sesión actual de WhatsApp Web, borrará la sesión guardada y forzará un QR nuevo. ¿Continuar?')) return;
  const d=await p(API+'?action=bridge_reset_session',{});
  if(d.status==='success'){
    a('warning',(d.msg||'Sesión cerrada') + (d.detail ? ' ' + String(d.detail) : ''));
    setTimeout(async ()=>{
      await loadBridgeStatus();
      await showBridgeQr();
    }, 2500);
    return;
  }
  const detail = d.detail ? ' Detalle: '+String(d.detail) : '';
  a('danger',(d.msg||'No se pudo cerrar la sesión del bridge') + detail);
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
  const selectedIds=new Set(
    Array.from(document.querySelectorAll('.promo-chat:checked'))
      .map(i=>i.getAttribute('data-id')).filter(Boolean)
  );
  if(!promoChats.length){
    const hasFilter=promoChatsSearchTerm.trim().length>0;
    w.innerHTML=`<div class="text-muted small">${hasFilter?'No hay destinos que coincidan con el filtro.': 'Sin chats detectados aún. Verifica estado listo y refresca.'}</div>`;
    renderMyGroupOptions();
    return;
  }
  w.innerHTML=promoChats.map((c,i)=>`<label class="d-flex align-items-center gap-2 py-1 border-bottom small">
      <input type="checkbox" class="form-check-input promo-chat" data-id="${esc(c.id)}" data-idx="${i}" data-group="${Number(c.is_group||0)}" ${selectedIds.has(String(c.id||''))?'checked':''}>
      <span class="badge ${c.is_group?'bg-primary':'bg-secondary'}">${c.is_group?'Grupo':'Chat'}</span>
      <span>${esc(c.name||c.id)}</span>
      <span class="text-muted ms-auto">${esc(c.id)}</span>
    </label>`).join('');
  renderMyGroupOptions();
  updatePromoSelectionSummary();
}
async function loadPromoChats(){
  const term=(promoChatsSearchTerm||'').trim();
  const query=term?`&q=${encodeURIComponent(term)}`:'';
  const d=await g(API+'?action=promo_chats'+query);
  if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar chats');return;}
  promoChats=Array.isArray(d.rows)?d.rows:[];
  renderPromoChats();
}

async function loadPromoGroupLists(){
  const d=await g(API+'?action=promo_group_lists');
  if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar listas');return;}
  promoGroupLists=Array.isArray(d.rows)?d.rows:[];
  renderPromoGroupLists();
}

function renderPromoGroupLists(){
  const w=document.getElementById('promoGroupListWrap');
  if(!w) return;
  if(!promoGroupLists.length){
    w.innerHTML='<div class="text-muted small">Sin listas aún.</div>';
    return;
  }
  w.innerHTML=promoGroupLists.map(l=>{
    const targets=Array.isArray(l.targets)?l.targets:[];
    const names=targets.map(t=>esc(String(t.name||t.id||''))).join(' | ');
    return `<div class="d-flex align-items-center gap-2 py-2 border-bottom">
      <div class="flex-grow-1">
        <div class="fw-semibold small">${esc(String(l.name||'Sin nombre'))}</div>
        <div class="small text-muted">${targets.length} destinos</div>
        <div class="small text-muted text-truncate" style="max-width:360px">${names || '-'}</div>
      </div>
      <button class="btn btn-sm btn-outline-success" type="button" title="Aplicar lista" onclick="applyPromoGroupList('${esc(String(l.id||''))}')"><i class="fas fa-check-circle"></i></button>
      <button class="btn btn-sm btn-outline-primary" type="button" title="Editar lista" onclick="editPromoGroupList('${esc(String(l.id||''))}')"><i class="fas fa-pen"></i></button>
      <button class="btn btn-sm btn-outline-danger" type="button" title="Eliminar lista" onclick="deletePromoGroupList('${esc(String(l.id||''))}')"><i class="fas fa-trash"></i></button>
    </div>`;
  }).join('');
}

function selectedPromoChatTargets(){
  const checks=Array.from(document.querySelectorAll('.promo-chat:checked'));
  return checks.map(ch=>{
    const idx=parseInt(ch.dataset.idx,10);
    const item=promoChats[idx];
    if(!item) return null;
    return {id:String(item.id||''), name:String(item.name||item.id||'')};
  }).filter(Boolean);
}

async function savePromoGroupList(isUpdate=false){
  const input=document.getElementById('promoGroupListName');
  const name=(input?.value||'').trim();
  const targets=selectedPromoChatTargets();
  const id=(isUpdate && promoGroupListEditingId)?promoGroupListEditingId:'';
  if(!name){a('danger','Ingresa un nombre de lista');return;}
  if(!targets.length){a('danger','Selecciona al menos un destino para guardar la lista');return;}
  const d=await p(API+'?action=promo_group_list_save',{id,name,targets});
  if(d.status==='success'){
    a('success',isUpdate?'Lista actualizada':'Lista guardada');
    clearPromoGroupListEditing();
    await loadPromoGroupLists();
  }else{
    a('danger',d.msg||'No se pudo guardar la lista');
  }
}

async function applyPromoGroupList(id){
  const listId=String(id||'');
  const list=promoGroupLists.find(x=>String(x.id)===listId);
  if(!list){a('danger','Lista no encontrada');return;}
  const targetSet=new Set((Array.isArray(list.targets)?list.targets:[]).map(t=>String(t.id||t)));
  let checks=document.querySelectorAll('.promo-chat');
  if(!checks.length){
    await loadPromoChats();
    checks=document.querySelectorAll('.promo-chat');
  }
  let selectedCount=0;
  checks.forEach(ch=>{
    const itemId=String(ch.dataset.id||'');
    const on=targetSet.has(itemId);
    ch.checked=on;
    if(on) selectedCount++;
  });
  if(selectedCount===0){
    a('warning','La lista no tiene destinos cargados en la vista actual');
  }else{
    a('success',`Lista aplicada: ${selectedCount} destinos`);
  }
  if(__promoGroupsOnlyMode){
    __promoGroupsOnlyMode=false;
    const btn=document.getElementById('promoGroupsOnlyBtn');
    if(btn){btn.classList.add('btn-outline-info');btn.classList.remove('btn-info','text-white');btn.title='Seleccionar solo grupos';}
  }
  updatePromoSelectionSummary();
}

function editPromoGroupList(id){
  const listId=String(id||'');
  const list=promoGroupLists.find(x=>String(x.id)===listId);
  if(!list){a('danger','Lista no encontrada');return;}
  promoGroupListEditingId=listId;
  const input=document.getElementById('promoGroupListName');
  if(input) input.value=String(list.name||'');
  const targetSet=new Set((Array.isArray(list.targets)?list.targets:[]).map(t=>String(t.id||t)));
  const checks=document.querySelectorAll('.promo-chat');
  if(!checks.length){a('info','Carga nuevamente los destinos y vuelve a editar si no aparecen todos.');}
  checks.forEach(ch=>{ch.checked=targetSet.has(String(ch.dataset.id||''));});
  updatePromoSelectionSummary();
  if(__promoGroupsOnlyMode){
    __promoGroupsOnlyMode=false;
    const btn=document.getElementById('promoGroupsOnlyBtn');
    if(btn){btn.classList.add('btn-outline-info');btn.classList.remove('btn-info','text-white');btn.title='Seleccionar solo grupos';}
  }
  a('info','Ajusta los destinos y luego pulsa Actualizar lista');
}

async function deletePromoGroupList(id){
  const listId=String(id||'');
  if(!listId){a('danger','Lista inválida');return;}
  if(!window.confirm('¿Eliminar esta lista de destinos?')) return;
  const d=await p(API+'?action=promo_group_list_delete',{id:listId});
  if(d.status==='success'){
    a('success','Lista eliminada');
    if(promoGroupListEditingId===listId) clearPromoGroupListEditing();
    await loadPromoGroupLists();
  }else{
    a('danger',d.msg||'No se pudo eliminar la lista');
  }
}

function clearPromoGroupListEditing(){
  promoGroupListEditingId='';
  const input=document.getElementById('promoGroupListName');
  if(input) input.value='';
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
  if(!activePromoTemplateId || !promoTemplates.some(t=>String(t.id)===String(activePromoTemplateId))){
    activePromoTemplateId=promoTemplates.length?String(promoTemplates[0].id||''):'';
  }
  renderPromoTemplates();
  renderProgrammingTab();
}
async function savePromoTemplate(){
  clearValidationMarks();
  const name=(promoTemplateName.value||'').trim();
  const text=(promoText.value||'').trim();
  if(!name){a('danger','Pon nombre a la plantilla',{focusEl:promoTemplateName});return;}
  if(!text && !promoProducts.length && !promoBannerImages.length){
    const focusTarget=(text===''?promoText:
      (promoProducts.length===0?document.getElementById('promoProductsWrap'):document.getElementById('promoBannerWrap')));
    a('danger','La plantilla no puede estar vacía',{focusEl:focusTarget||promoText});
    return;
  }
  const currentId=(promoTemplateSelect.value||'').trim();
  const d=await p(API+'?action=promo_template_save',{id:currentId,name,text,products:promoProducts,banner_images:promoBannerImages});
  if(d.status==='success'){
    const banners = Array.isArray(promoBannerImages)?promoBannerImages.length:0;
    a('success',`Plantilla guardada` + (banners>0 ? ` (banners: ${Math.min(banners,3)})` : ''));
    clearValidationMarks();
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
  promoBannerImages=Array.isArray(t.banner_images)?t.banner_images:[];
  renderPromoProducts();
  renderPromoBanners();
  a('info','Plantilla cargada');
}
async function deletePromoTemplate(){
  const id=(promoTemplateSelect.value||'').trim();
  if(!id){a('danger','Selecciona una plantilla');return;}
  const d=await p(API+'?action=promo_template_delete',{id});
  if(d.status==='success'){
    a('success','Plantilla eliminada' + (Number(d.deleted_campaigns||0)>0?` y ${Number(d.deleted_campaigns||0)} campaña(s) enlazada(s)`:'')); 
    await loadPromoTemplates();
    promoTemplateName.value='';
  } else a('danger',d.msg||'No se pudo eliminar plantilla');
}
async function deletePromoTemplateById(id){
  id=String(id||'').trim();
  if(!id){a('danger','Selecciona una plantilla');return;}
  const t=promoTemplates.find(x=>String(x.id)===id);
  if(!confirm(`¿Eliminar plantilla "${t?.name||id}"? Si hay campañas enlazadas se eliminarán también.`)) return;
  const d=await p(API+'?action=promo_template_delete',{id});
  if(d.status==='success'){
    a('success','Plantilla eliminada' + (Number(d.deleted_campaigns||0)>0?` y ${Number(d.deleted_campaigns||0)} campaña(s) enlazada(s)`:'')); 
    if(activePromoTemplateId===id) activePromoTemplateId='';
    if((promoTemplateSelect.value||'').trim()===id) promoTemplateSelect.value='';
    await loadPromoTemplates();
    await loadPromoList();
  } else a('danger',d.msg||'No se pudo eliminar plantilla');
}
function selectPromoTemplateDetail(id){
  activePromoTemplateId=String(id||'');
  renderProgrammingTab();
}
function renderPromoTemplateDetailCard(t){
  const products=Array.isArray(t.products)?t.products:[];
  const banners=Array.isArray(t.banner_images)?t.banner_images:[];
  const linked=promoCampaigns.filter(c=>String(c.template_id||'')===String(t.id||''));
  return `<div class="border rounded p-3 h-100 bg-white">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
      <div>
        <div class="fw-bold">${esc(t.name||t.id||'-')}</div>
        <div class="small text-muted">${esc(t.id||'-')} · ${esc(t.updated_at||'-')}</div>
      </div>
      <div class="d-flex gap-1">
        <button class="btn btn-sm btn-outline-primary" type="button" onclick="promoTemplateSelect.value='${esc(t.id||'')}';applyPromoTemplate()" title="Cargar plantilla"><i class="fas fa-file-import"></i></button>
        <button class="btn btn-sm btn-outline-danger" type="button" onclick="deletePromoTemplateById('${esc(t.id||'')}')" title="Eliminar plantilla"><i class="fas fa-trash"></i></button>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <span class="badge bg-light text-dark border">${products.length} productos</span>
      <span class="badge bg-light text-dark border">${banners.length} banners</span>
      <span class="badge bg-light text-dark border">${linked.length} campañas enlazadas</span>
    </div>
    <div class="small text-muted mb-1">Texto</div>
    <div class="border rounded p-2 small mb-3" style="min-height:74px;white-space:pre-wrap;background:#f8fafc">${esc(t.text||'(Sin texto)')}</div>
    <div class="small text-muted mb-1">Productos</div>
    <div class="small mb-3" style="max-height:120px;overflow:auto">${products.length?products.map(p=>`<div class="py-1 border-bottom">${esc(p.name||p.id||'-')} <span class="text-muted">(${esc(p.id||'-')})</span></div>`).join(''):'<div class="text-muted">Sin productos</div>'}</div>
    <div class="small text-muted mb-1">Campañas enlazadas</div>
    <div class="small" style="max-height:120px;overflow:auto">${linked.length?linked.map(c=>`<div class="py-1 border-bottom">${esc(c.name||c.id||'-')} <span class="text-muted">· ${esc(c.status||'-')}</span></div>`).join(''):'<div class="text-muted">Sin campañas enlazadas</div>'}</div>
  </div>`;
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
  if (__promoGroupsOnlyMode) {
    __promoGroupsOnlyMode = false;
    const btn = document.getElementById('promoGroupsOnlyBtn');
    if (btn) {
      btn.classList.add('btn-outline-info');
      btn.classList.remove('btn-info','text-white');
      btn.title = 'Seleccionar solo grupos';
    }
  }
  document.querySelectorAll('.promo-chat[data-group="1"]').forEach(x=>x.checked=!!v);
  updatePromoSelectionSummary();
}
let __promoGroupsOnlyMode = false;
function togglePromoGroupsOnly(){
  __promoGroupsOnlyMode = !__promoGroupsOnlyMode;
  const btn = document.getElementById('promoGroupsOnlyBtn');
  const target = document.querySelectorAll('.promo-chat');
  if (__promoGroupsOnlyMode) {
    target.forEach(x=>{x.checked = String(x.dataset.group || '0') === '1';});
    if (btn) {
      btn.classList.remove('btn-outline-info');
      btn.classList.add('btn-info','text-white');
      btn.title = 'Desactivar solo grupos';
    }
  } else {
    updatePromoSelectionSummary();
    if (btn) {
      btn.classList.add('btn-outline-info');
      btn.classList.remove('btn-info','text-white');
      btn.title = 'Seleccionar solo grupos';
    }
    return;
  }
  updatePromoSelectionSummary();
}
function updatePromoSelectionSummary(){
  const checks = Array.from(document.querySelectorAll('.promo-chat'));
  const total = checks.length;
  const groupsSel = checks.filter(x => String(x.dataset.group || '0') === '1' && x.checked).length;
  const chatsSel = checks.filter(x => x.checked).length;
  const el = document.getElementById('promoSelectionSummary');
  if (el) el.textContent = `${chatsSel} chats · ${groupsSel} grupos seleccionados`;
}

document.addEventListener('change', function(e){
  if (e.target && e.target.classList && e.target.classList.contains('promo-chat')) {
    if (__promoGroupsOnlyMode) {
      __promoGroupsOnlyMode = false;
      const btn = document.getElementById('promoGroupsOnlyBtn');
      if (btn) {
        btn.classList.add('btn-outline-info');
        btn.classList.remove('btn-info','text-white');
        btn.title = 'Seleccionar solo grupos';
      }
    }
    updatePromoSelectionSummary();
  }
});

function renderPromoProducts(){
  const w=document.getElementById('promoProductsWrap');
  if(!w) return;
  if(!promoProducts.length){w.innerHTML='<div class="text-muted small">Sin productos seleccionados.</div>';return;}
  w.innerHTML=promoProducts.map((p,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-1">
    <img src="${esc(p.image||'')}" alt="" width="42" height="42" style="object-fit:cover;border-radius:6px;border:1px solid #ddd">
    <div class="small">
      <div class="fw-semibold">${esc(p.name)}</div>
      <div class="text-muted">$${Number(p.price||0).toFixed(2)} · ${esc(p.id)} · ${String(p.price_mode||'retail')==='wholesale'?'Mayorista':'Minorista'}</div>
    </div>
    <button class="btn btn-sm btn-outline-danger ms-auto" type="button" onclick="removePromoProduct(${idx})"><i class="fas fa-times"></i></button>
  </div>`).join('');
}
function renderPromoBanners(){
  const w=document.getElementById('promoBannerWrap');
  if(!w) return;
  if(!promoBannerImages.length){w.innerHTML='<div class="text-muted small">Sin imágenes cargadas.</div>';return;}
  w.innerHTML=promoBannerImages.map((img,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-2">
    <img src="${esc(img.url||'')}" alt="" width="72" height="48" style="object-fit:cover;border-radius:6px;border:1px solid #ddd">
    <div class="small" style="min-width:0">
      <div class="fw-semibold text-truncate">${esc(img.name||('Banner '+(idx+1)))}</div>
      <div class="text-muted text-truncate">${esc(img.url||'')}</div>
    </div>
    <button class="btn btn-sm btn-outline-danger ms-auto" type="button" onclick="removePromoBanner(${idx})"><i class="fas fa-times"></i></button>
  </div>`).join('');
}
function removePromoBanner(idx){promoBannerImages.splice(idx,1);renderPromoBanners();}
function normalizePromoProductChoice(p, mode){
  const retail=Number(p?.retail_price ?? p?.price ?? 0);
  const wholesaleRaw=Number(p?.wholesale_price ?? 0);
  const useWholesale=mode==='wholesale';
  const wholesale=wholesaleRaw>0?wholesaleRaw:retail;
  return {
    ...p,
    retail_price:retail,
    wholesale_price:wholesaleRaw,
    price_mode:useWholesale?'wholesale':'retail',
    price:useWholesale?wholesale:retail
  };
}
function askPromoPriceMode(p, existingMode=''){
  const retail=Number(p?.retail_price ?? p?.price ?? 0);
  const wholesale=Number(p?.wholesale_price ?? 0);
  const defaultCode=existingMode==='wholesale'?'M':'N';
  const hasWholesale=wholesale>0 && wholesale!==retail;
  const msg=[
    `Precio para ${p?.name||p?.id||'producto'} (${p?.id||'-'}):`,
    `N = Minorista $${retail.toFixed(2)}`,
    `M = Mayorista $${(hasWholesale?wholesale:retail).toFixed(2)}${hasWholesale?'':' (igual al minorista)'}`,
    'Escribe N o M'
  ].join('\n');
  const raw=(prompt(msg, defaultCode)||'').trim().toUpperCase();
  if(!raw) return null;
  if(raw==='M') return hasWholesale?'wholesale':'retail';
  if(raw==='N') return 'retail';
  a('danger','Respuesta inválida. Usa N para minorista o M para mayorista.');
  return null;
}
async function onPromoBannerInput(ev){
  const files=[...(ev.target.files||[])];
  if(!files.length) return;
  const remaining=Math.max(0,3-promoBannerImages.length);
  if(!remaining){a('danger','Solo se permiten hasta 3 imágenes');ev.target.value='';return;}
  const selected=files.slice(0,remaining);
  for(const file of selected){
    const d=await uploadPromoBanner(file);
    if(d.status==='success'){
      promoBannerImages.push({url:d.url,name:d.name||file.name});
      renderPromoBanners();
    }else{
      a('danger',d.msg||('No se pudo subir '+file.name));
    }
  }
  if(files.length>remaining) a('info','Máximo 3 imágenes por campaña.');
  ev.target.value='';
}
function removePromoProduct(idx){promoProducts.splice(idx,1);renderPromoProducts();}
function addPromoProduct(p){
  if(!p||!p.id) return;
  const existingIdx=promoProducts.findIndex(x=>String(x.id)===String(p.id));
  const existing=existingIdx>=0?promoProducts[existingIdx]:null;
  const mode=askPromoPriceMode(p, existing?.price_mode||'');
  if(!mode) return;
  const chosen=normalizePromoProductChoice(p, mode);
  if(existingIdx>=0){
    promoProducts[existingIdx]=chosen;
    a('info','Precio actualizado para '+(p.name||p.id));
  }else{
    promoProducts.push(chosen);
  }
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
    <span class="small">${esc(r.name)} <span class="text-muted">(${esc(r.id)})</span> - Min $${Number(r.retail_price||r.price||0).toFixed(2)} · May $${Number((r.wholesale_price||0)>0?r.wholesale_price:r.retail_price||r.price||0).toFixed(2)}</span>
  </button>`).join('');
}
async function createPromoCampaign(){
  try{
    clearValidationMarks();
    const text=(promoText.value||'').trim();
    const campaignName=(promoCampaignName.value||'').trim();
    const campaignGroup=(promoCampaignGroup.value||'').trim()||'General';
    const scheduleTime=(promoScheduleTime.value||'').trim();
    const scheduleDays=selectedPromoDays();
    const minSec=Math.max(60,parseInt(promoMinSec.value||'60',10)||60);
    const maxSec=Math.max(minSec,parseInt(promoMaxSec.value||'120',10)||120);
    const targets=[...document.querySelectorAll('.promo-chat:checked')].map(ch=>promoChats[parseInt(ch.dataset.idx,10)]).filter(Boolean);
    if(!promoProducts.length){
      const ok=confirm('No has seleccionado productos. ¿Quieres continuar la campaña solo con texto y/o banners?');
      if(!ok) return;
    }
    const hasBanner = Array.isArray(promoBannerImages) && promoBannerImages.length > 0;
    if(!text && !hasBanner){
      a('danger','Agrega texto o banner para la campaña',{focusEl:promoText});
      return;
    }
    if(!targets.length){a('danger','Selecciona al menos un grupo/chat',{focusEl:document.getElementById('promoChatsWrap')});return;}
    if(!scheduleTime){a('danger','Selecciona hora de lanzamiento',{focusEl:promoScheduleTime});return;}
    if(!scheduleDays.length){a('danger','Selecciona al menos un día',{focusEl:document.querySelector('.promo-day')});return;}
    const d=await p(API+'?action=promo_create',{
      campaign_name:campaignName,
      campaign_group:campaignGroup,
      template_id:(promoTemplateSelect.value||'').trim(),
      text,
      banner_images:promoBannerImages,
      targets,
      products:promoProducts,
      min_seconds:minSec,
      max_seconds:maxSec,
      schedule_enabled:1,
      schedule_time:scheduleTime,
      schedule_days:scheduleDays
    });
    if(d.status==='success'){clearValidationMarks();a('success','Campaña programada: '+(d.id||''));loadPromoList();} else a('danger',d.msg||'Error al crear campaña');
  }catch(e){
    a('danger','No se pudo programar la campaña: '+(e?.message||'error inesperado'));
  }
}
async function createMyGroupCampaign(){
  try{
    clearValidationMarks();
    const target=selectedMyGroup();
    const scheduleTime=(myGroupScheduleTime.value||'').trim();
    const campaignGroup=(myGroupCampaignGroup.value||'').trim()||'Mi grupo';
    if(!target){a('danger','Selecciona un grupo',{focusEl:document.getElementById('myGroupWrap')});return;}
    if(!scheduleTime){a('danger','Selecciona la hora diaria',{focusEl:myGroupScheduleTime});return;}
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
      clearValidationMarks();
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
  if(d.status!=='success'){tb.innerHTML='<tr><td colspan="7" class="text-center text-muted p-3">Sin campañas</td></tr>';promoCampaigns=[];renderProgrammingTab();return;}
  promoCampaigns=Array.isArray(d.rows)?d.rows:[];
  if(!promoCampaigns.length){tb.innerHTML='<tr><td colspan="7" class="text-center text-muted p-3">Sin campañas</td></tr>';renderProgrammingTab();return;}
  tb.innerHTML=promoCampaigns.map(r=>`<tr>
    <td class="small">${esc(r.created_at||'')}</td>
    <td class="small">${esc(r.name||r.id||'')}</td>
    <td class="small">${esc(r.campaign_group||'General')}</td>
    <td class="small">${esc(r.schedule_time||'-')} (${esc(daysToText(r.schedule_days||[]))})<br><span class="text-muted">${esc(targetsToText(r.targets||[]))}</span></td>
    <td><span class="badge ${r.status==='done'?'bg-success':(r.status==='error'?'bg-danger':'bg-warning text-dark')}">${esc(r.status||'')}</span></td>
    <td class="small">${Number(r.current_index||0)}/${(r.targets||[]).length}</td>
    <td class="small">
      <div class="d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" type="button" title="Clonar" onclick="cloneScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-clone"></i></button>
        <button class="btn btn-sm btn-outline-success" type="button" title="Enviar ahora" onclick="forceCampaignNow('${esc(r.id||'')}')"><i class="fas fa-bolt"></i></button>
        <button class="btn btn-sm btn-outline-primary" type="button" title="Editar" onclick="editScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-pen"></i></button>
        <button class="btn btn-sm btn-outline-danger" type="button" title="Eliminar" onclick="deleteScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-trash"></i></button>
      </div>
    </td>
  </tr>`).join('');
  renderProgrammingTab();
}
async function cloneScheduledCampaign(id){
  const row=promoCampaigns.find(x=>String(x.id)===String(id));
  if(!row){a('danger','Campaña no encontrada');return;}
  const d=await p(API+'?action=promo_clone',{id});
  if(d.status==='success'){a('success','Campaña clonada: '+(d.name||d.id||''));loadPromoList();} else a('danger',d.msg||'No se pudo clonar');
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
      tplRows.innerHTML='<div class="text-center text-muted p-3">Sin plantillas</div>';
    }else{
      const active=promoTemplates.find(t=>String(t.id)===String(activePromoTemplateId)) || promoTemplates[0];
      if(active) activePromoTemplateId=String(active.id||'');
      tplRows.innerHTML=`<div class="row g-3">
        <div class="col-12 col-xl-5">
          <div class="border rounded overflow-auto" style="max-height:420px;background:#fff">
            ${promoTemplates.map(t=>{
              const selected=String(t.id)===String(activePromoTemplateId);
              return `<div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom ${selected?'bg-primary-subtle':''}" style="cursor:pointer" onclick="selectPromoTemplateDetail('${esc(t.id||'')}')">
                <div class="flex-grow-1">
                  <div class="small fw-semibold">${esc(t.name||t.id||'-')}</div>
                  <div class="small text-muted">${Array.isArray(t.products)?t.products.length:0} prod · ${Array.isArray(t.banner_images)?t.banner_images.length:0} banners</div>
                </div>
                <button class="btn btn-sm btn-outline-danger" type="button" title="Eliminar plantilla" onclick="event.stopPropagation();deletePromoTemplateById('${esc(t.id||'')}')"><i class="fas fa-trash"></i></button>
              </div>`;
            }).join('')}
          </div>
        </div>
        <div class="col-12 col-xl-7">
          ${active?renderPromoTemplateDetailCard(active):'<div class="text-muted">Selecciona una plantilla</div>'}
        </div>
      </div>`;
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
	                <button class="btn btn-sm btn-outline-secondary" type="button" title="Clonar" onclick="cloneScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-clone"></i></button>
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
async function loadAll(){try{await Promise.all([loadCfg(),loadStats(),loadMsgs(),loadOrders(),loadConversations(),loadBridgeStatus(),loadPromoList(),loadPromoChats(),loadPromoTemplates(),loadPromoGroupLists()])}catch(e){a('danger',e.message||'error')}}
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
bot_tone.addEventListener('change',renderBotTonePreview);
enabled.addEventListener('change',()=>saveAutoReplyConfig(enabled.checked?'Autorespuesta activada':'Autorespuesta desactivada'));
auto_schedule_enabled.addEventListener('change',()=>saveAutoReplyConfig(auto_schedule_enabled.checked?'Horario automatico activado':'Horario automatico desactivado'));
auto_off_start.addEventListener('change',()=>saveAutoReplyConfig('Horario de apagado actualizado'));
auto_off_end.addEventListener('change',()=>saveAutoReplyConfig('Horario de apagado actualizado'));
tname.addEventListener('input',renderBotTonePreview);
document.getElementById('conversationSearch').addEventListener('input',renderConversations);
document.querySelectorAll('[data-conv-filter]').forEach(btn=>btn.addEventListener('click',()=>{
  conversationFilter=btn.dataset.convFilter||'all';
  document.querySelectorAll('[data-conv-filter]').forEach(x=>x.classList.remove('active'));
  btn.classList.add('active');
  renderConversations();
}));
document.getElementById('promoSearch').addEventListener('input',ev=>{
  if(promoSearchTimer) clearTimeout(promoSearchTimer);
  promoSearchTimer=setTimeout(()=>searchPromoProducts(ev.target.value||''),260);
});
document.getElementById('promoBannerInput').addEventListener('change',onPromoBannerInput);
document.getElementById('promoChatsFilter')?.addEventListener('input',ev=>{
  promoChatsSearchTerm=(ev.target?.value||'').trim();
  if(promoChatsSearchTimer) clearTimeout(promoChatsSearchTimer);
  promoChatsSearchTimer=setTimeout(()=>loadPromoChats(promoChatsSearchTerm),220);
});
document.addEventListener('change',ev=>{
  if(ev.target && ev.target.matches('input[name="my_group_chat"]')) loadMyGroupPreview();
});
loadAll();
setInterval(()=>{loadStats();loadMsgs();loadOrders();loadConversations();loadBridgeStatus();loadPromoList();loadPromoChats();loadPromoTemplates();loadPromoGroupLists()},12000);
</script>
<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
