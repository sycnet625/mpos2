<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS BOT WhatsApp</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/inventory-suite.css">
<?php require __DIR__ . '/styles.php'; ?>
<style>
.table thead th { white-space: nowrap; }
#toastArea{
  position:fixed;left:0;right:0;bottom:14px;z-index:2000;pointer-events:none;padding:0 16px 16px;
}
#alertBox{max-width:1280px;margin:0 auto;pointer-events:auto;}
#alertBox .toast-msg.slide-in{animation:pb-toast-in 220ms ease-out forwards;}
#alertBox .toast-msg.hide{animation:pb-toast-out 220ms ease-in forwards;}
#alertBox .toast-msg{min-height:74px;width:100%;font-size:1.2rem;font-weight:600;border:0;border-radius:14px;padding:1.15rem 1.25rem;box-shadow:0 16px 34px rgba(0,0,0,.3);backdrop-filter:blur(2px);position:relative;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;}
#alertBox .toast-msg .toast-msg-text{flex:1;}
#alertBox .toast-msg .toast-close{opacity:.9;margin-left:auto;border-radius:999px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;border:1px solid rgba(255,255,255,.45);background:rgba(0,0,0,.12);line-height:1;pointer-events:auto;}
#alertBox .toast-msg.alert-danger{background:linear-gradient(120deg,#991b1b,#dc2626);color:#fff;border-left:10px solid #fca5a5;}
#alertBox .toast-msg.alert-success{background:linear-gradient(120deg,#166534,#16a34a);color:#fff;}
#alertBox .toast-msg.alert-info{background:#0ea5e9;color:#fff;}
#alertBox .toast-msg.alert-warning{background:linear-gradient(120deg,#92400e,#b45309);color:#fff;}
@keyframes pb-toast-in{from{transform:translateY(24px);opacity:0;}to{transform:translateY(0);opacity:1;}}
@keyframes pb-toast-out{from{transform:translateY(0);opacity:1;}to{transform:translateY(24px);opacity:0;}}
#alertBox .is-invalid{border-color:#dc2626!important;box-shadow:0 0 0 .2rem rgba(220,38,38,.15)!important;}
.error-block{border:2px solid #ef4444!important;box-shadow:0 0 0 .2rem rgba(239,68,68,.18)!important;}
</style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">WhatsApp / Automatización</div>
                <h1 class="h2 fw-bold mb-2"><i class="fab fa-whatsapp me-2"></i>POS BOT WhatsApp</h1>
                <p class="mb-3 text-white-50">Auto-reply, reservas y ventas por WhatsApp. Campañas, grupos y conversaciones activas.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-code me-1"></i><?php echo htmlspecialchars($posBotVersion, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="kpi-chip"><i class="fas fa-users me-1"></i>Sesiones: <strong id="s1">0</strong></span>
                    <span class="kpi-chip"><i class="fas fa-envelope me-1"></i>Mensajes hoy: <strong id="s2">0</strong></span>
                    <span class="kpi-chip"><i class="fas fa-calendar-check me-1"></i>Reservas hoy: <strong id="s3">0</strong></span>
                    <span class="kpi-chip"><i class="fas fa-dollar-sign me-1"></i>Ventas hoy: <strong id="s4">$0.00</strong></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-light" onclick="loadAll()"><i class="fas fa-sync me-1"></i>Refrescar</button>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <div class="glass-card p-3 mb-4 inventory-fade-in">
        <div class="row g-3 align-items-center">
            <div class="col-lg-3">
                <div class="text-muted small">Cuenta WhatsApp conectada</div>
                <div id="waOwnerName" class="fw-semibold" style="font-size:1.05rem">Sin datos</div>
            </div>
            <div class="col-lg-3">
                <div class="text-muted small">Número</div>
                <div id="waOwnerPhone" class="fw-semibold">-</div>
            </div>
            <div class="col-lg-3">
                <div class="text-muted small">Usuario / JID</div>
                <div id="waOwnerJid" class="fw-semibold small text-break">-</div>
            </div>
            <div class="col-lg-3">
                <div class="text-muted small">Plataforma</div>
                <div id="waOwnerPlatform" class="fw-semibold">-</div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center gap-2">
                    <span class="small text-muted">Estado WhatsApp Web</span>
                    <span id="waLedDot" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#64748b;box-shadow:0 0 0 4px rgba(100,116,139,.2)"></span>
                    <span id="waLedText" class="fw-semibold" style="color:#334155">Sin datos</span>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3 inventory-tablist d-inline-flex" id="botTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active px-4 py-2 fw-bold" id="tab-bot-btn" data-bs-toggle="tab" data-bs-target="#tab-bot" type="button" role="tab">BOT</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2 fw-bold" id="tab-promo-btn" data-bs-toggle="tab" data-bs-target="#tab-promo" type="button" role="tab">Campañas</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2 fw-bold" id="tab-mi-grupo-btn" data-bs-toggle="tab" data-bs-target="#tab-mi-grupo" type="button" role="tab">Mi grupo</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2 fw-bold" id="tab-programacion-btn" data-bs-toggle="tab" data-bs-target="#tab-programacion" type="button" role="tab">Programación</button>
        </li>
    </ul>

    <div class="tab-content">
    <div class="tab-pane fade show active" id="tab-bot" role="tabpanel">
    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-6">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Configuración</div>
                        <h2 class="h5 fw-bold mb-0">Parámetros del bot</h2>
                    </div>
                </div>
                <div class="alert alert-success py-2 mb-3">
                    <div class="fw-semibold"><i class="fab fa-whatsapp"></i> Modo WhatsApp Web</div>
                    <div class="small mt-1">Para abrir la cuenta: pulsa <b>Abrir web.whatsapp.com</b> y escanea el QR desde tu teléfono.</div>
                    <div class="d-flex gap-2 mt-2 flex-wrap">
                        <button class="btn btn-success btn-sm" type="button" onclick="openWhatsAppWeb()"><i class="fas fa-external-link-alt"></i> Abrir WA Web</button>
                        <button class="btn btn-outline-success btn-sm" type="button" onclick="showBridgeQr()"><i class="fas fa-qrcode"></i> Ver QR</button>
                        <button class="btn btn-outline-warning btn-sm" type="button" onclick="restartBridge()"><i class="fas fa-rotate-right"></i> Reiniciar</button>
                        <button class="btn btn-outline-danger btn-sm" type="button" onclick="resetBridgeSession()"><i class="fas fa-right-from-bracket"></i> Cerrar sesión</button>
                        <button class="btn btn-outline-dark btn-sm" type="button" onclick="showBridgeLogs()"><i class="fas fa-file-lines"></i> Logs</button>
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
                        <div class="small text-muted">Hora Habana. Dentro de la franja se apaga la autorespuesta.</div>
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
                        <div class="stat-box mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
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

        <div class="col-12 col-lg-6">
            <div class="glass-card p-4 mb-4 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Prueba</div>
                        <h2 class="h5 fw-bold mb-0">Simular entrada</h2>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label small fw-bold">Teléfono</label><input id="twa" class="form-control" placeholder="5351234567"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Nombre</label><input id="tname" class="form-control" placeholder="Cliente Test"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Texto</label><input id="ttext" class="form-control" placeholder="MENU"></div>
                    <div class="col-12"><button class="btn btn-primary" onclick="testBot()"><i class="fas fa-paper-plane"></i> Simular entrada</button></div>
                </div>
            </div>

            <div class="glass-card p-4 mb-4 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Mensajes</div>
                        <h2 class="h5 fw-bold mb-0">Registro de mensajes</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="clearMessageLogs()"><i class="fas fa-trash"></i> Borrar</button>
                </div>
                <div class="table-responsive" style="max-height:320px">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Fecha</th><th>Teléfono/WA</th><th>Nombre</th><th>Dir</th><th>Texto</th></tr></thead>
                        <tbody id="tm"><tr><td colspan="5" class="text-center text-muted p-3">Sin datos</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="glass-card p-4 mb-4 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Reservas</div>
                        <h2 class="h5 fw-bold mb-0">Reservas BOT</h2>
                    </div>
                </div>
                <div class="table-responsive" style="max-height:320px">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Fecha</th><th>Reserva</th><th>Cliente</th><th>Total</th></tr></thead>
                        <tbody id="to"><tr><td colspan="4" class="text-center text-muted p-3">Sin datos</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="glass-card p-4 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Conversaciones</div>
                        <h2 class="h5 fw-bold mb-0">Conversaciones activas</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadConversations()"><i class="fas fa-sync"></i></button>
                </div>
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

    <div class="tab-pane fade" id="tab-promo" role="tabpanel">
    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-5">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Destinos</div>
                        <h2 class="h5 fw-bold mb-0">Destinos de promoción</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoChats()"><i class="fas fa-sync"></i></button>
                </div>
                <div class="small text-muted mb-2">Se leen chats y grupos desde WhatsApp Web del bridge conectado.</div>
                <div class="mb-2">
                    <input id="promoChatsFilter" class="form-control form-control-sm" placeholder="Filtrar destino (nombre o id)..." autocomplete="off">
                </div>
                <div class="d-flex gap-2 mb-2">
                    <button class="btn btn-sm btn-outline-primary" type="button" title="Marcar todos los grupos" onclick="selectAllPromoChats(true)"><i class="fas fa-check-double"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" title="Desmarcar todos los grupos" onclick="selectAllPromoChats(false)"><i class="fas fa-eraser"></i></button>
                    <button id="promoGroupsOnlyBtn" class="btn btn-sm btn-outline-info" type="button" title="Seleccionar solo grupos" onclick="togglePromoGroupsOnly()"><i class="fas fa-users"></i></button>
                </div>
                <div id="promoSelectionSummary" class="small text-muted mb-2">0 chats · 0 grupos seleccionados</div>
                <div id="promoChatsWrap" tabindex="-1" style="max-height:320px;overflow:auto;border:1px solid var(--pw-line);border-radius:12px;padding:8px">
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
                <div id="promoGroupListWrap" style="max-height:220px;overflow:auto;border:1px solid var(--pw-line);border-radius:12px;padding:8px">
                    <div class="text-muted small">Sin listas aún.</div>
                </div>
                <div class="small text-muted mt-2 mb-0">La lista se guarda con los destinos actualmente seleccionados.</div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="glass-card p-4 mb-4 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Campaña</div>
                        <h2 class="h5 fw-bold mb-0">Nueva campaña</h2>
                    </div>
                </div>
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
                    <div class="col-md-6">
                        <label class="form-label">Hora de lanzamiento</label>
                        <input id="promoScheduleTime" type="time" class="form-control" value="09:00">
                        <div class="form-text">Zona horaria fija: America/Havana (Cuba).</div>
                    </div>
                    <div class="col-md-6">
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
                <div class="small text-muted mb-2">El bridge publica en cada destino con un intervalo aleatorio entre min y max.</div>
                <div id="promoProductsWrap" tabindex="-1" class="border rounded p-2" style="min-height:100px;max-height:240px;overflow:auto">
                    <div class="text-muted small">Sin productos seleccionados.</div>
                </div>
                <div class="mt-2">
                    <button class="btn btn-success" type="button" onclick="createPromoCampaign()"><i class="fas fa-bullhorn"></i> Programar promoción</button>
                </div>
            </div>

            <div class="glass-card p-4 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Historial</div>
                        <h2 class="h5 fw-bold mb-0">Campañas recientes</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
                </div>
                <div class="table-responsive" style="max-height:260px">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Fecha</th><th>Campaña</th><th>Grupo</th><th>Horario</th><th>Estado</th><th>Progreso</th><th>Último OK</th><th>Último error</th><th>Acciones</th></tr></thead>
                        <tbody id="promoRows"><tr><td colspan="9" class="text-center text-muted p-3">Sin campañas</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    </div>

    <div class="tab-pane fade" id="tab-mi-grupo" role="tabpanel">
    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-6">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Grupo</div>
                        <h2 class="h5 fw-bold mb-0">Grupo destino diario</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoChats()"><i class="fas fa-sync"></i></button>
                </div>
                <div class="small text-muted mb-2">Selecciona un solo grupo. Se enviarán todos los productos con existencias y, al final, un texto con los reservables y la promoción web.</div>
                <div id="myGroupWrap" tabindex="-1" style="max-height:360px;overflow:auto;border:1px solid var(--pw-line);border-radius:12px;padding:8px">
                    <div class="text-muted small">Sin grupos detectados aún.</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Publicación</div>
                        <h2 class="h5 fw-bold mb-0">Publicación diaria automática</h2>
                    </div>
                </div>
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
                <div class="mt-3 stat-box">
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

    <div class="tab-pane fade" id="tab-programacion" role="tabpanel">
    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-5">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Plantillas</div>
                        <h2 class="h5 fw-bold mb-0">Plantillas guardadas</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoTemplates()"><i class="fas fa-sync"></i></button>
                </div>
                <div id="promoTemplateRows">
                    <div class="text-center text-muted p-3">Sin plantillas</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-7">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Programación</div>
                        <h2 class="h5 fw-bold mb-0">Campañas programadas por grupo</h2>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
                </div>
                <div id="promoProgramGroups">
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

<?php require __DIR__ . '/app.js.php'; ?>
<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once __DIR__ . '/../menu_master.php'; ?>
</body>
</html>
