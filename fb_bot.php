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
.social-preview{background:#fff;border:1px solid #dbe3f0;border-radius:18px;box-shadow:0 10px 24px rgba(15,23,42,.08);overflow:hidden}
.social-preview-header{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #eef2f7}
.social-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:linear-gradient(135deg,#1877f2,#ef4444)}
.social-media-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px}
.social-media-grid img{width:100%;height:120px;object-fit:cover;border-radius:12px;border:1px solid #dbe3f0}
.social-preview-body{padding:14px 16px}
.social-preview-footer{display:flex;gap:16px;padding:12px 16px;border-top:1px solid #eef2f7;color:#64748b;font-size:.9rem}
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
              <div class="col-md-4">
                <label class="form-label">Canal</label>
                <select id="testPublishMode" class="form-select">
                  <option value="both">Facebook + Instagram</option>
                  <option value="facebook">Facebook solo</option>
                  <option value="instagram">Instagram solo</option>
                </select>
              </div>
              <div class="col-12"><button class="btn btn-success w-100" type="button" onclick="testPost()"><i class="fas fa-paper-plane"></i> Publicar prueba</button></div>
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
              <div class="alert alert-warning py-2 small">
                Los grupos automáticos de Facebook ya no están disponibles por la API oficial actual de Meta. Este módulo publica en tu página y, opcionalmente, en Instagram. Si necesitas publicar en grupos, habría que hacerlo manualmente o con otra estrategia fuera de API oficial.
              </div>
              <div class="card border mb-3">
                <div class="card-header bg-white fw-semibold py-2">Grupos manuales de referencia</div>
                <div class="card-body">
                  <div class="small text-muted mb-2">Puedes guardar aquí los grupos donde acostumbras compartir manualmente. Sirve como checklist y acceso rápido, no como publicación automática.</div>
                  <div id="manualGroupsWrap" class="border rounded p-2 mb-2" style="max-height:180px;overflow:auto">
                    <div class="text-muted small">Sin grupos manuales guardados.</div>
                  </div>
                  <div class="row g-2">
                    <div class="col-md-5"><input id="manualGroupName" class="form-control" placeholder="Nombre del grupo"></div>
                    <div class="col-md-5"><input id="manualGroupUrl" class="form-control" placeholder="URL del grupo"></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="button" onclick="addManualGroup()"><i class="fas fa-plus"></i></button></div>
                  </div>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-md-8">
                  <label class="form-label">Plantilla</label>
                  <select id="promoTemplateSelect" class="form-select"></select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                  <button class="btn btn-outline-primary w-100" type="button" onclick="applyPromoTemplate()"><i class="fas fa-file-import"></i> Cargar</button>
                  <button class="btn btn-outline-secondary" type="button" onclick="clonePromoTemplate()" title="Clonar plantilla"><i class="fas fa-clone"></i></button>
                  <button class="btn btn-outline-danger" type="button" onclick="deletePromoTemplate()"><i class="fas fa-trash"></i></button>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-md-8">
                  <label class="form-label">Nombre plantilla (texto + productos)</label>
                  <input id="promoTemplateName" class="form-control" placeholder="Ej: Oferta desayuno">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Canal</label>
                  <select id="promoTemplateMode" class="form-select">
                    <option value="both">Ambos</option>
                    <option value="facebook">Facebook</option>
                    <option value="instagram">Instagram</option>
                  </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
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
                <div class="col-md-6">
                  <label class="form-label">Destino de publicación</label>
                  <select id="promoPublishMode" class="form-select">
                    <option value="both">Facebook + Instagram</option>
                    <option value="facebook">Facebook solo</option>
                    <option value="instagram">Instagram solo</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Página / canal</label>
                  <div class="form-control bg-light" id="campaignTargetInfo">Se usará la página configurada.</div>
                </div>
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
              <div class="mt-3 border rounded p-3" id="campaignPreviewBox" style="background:#f8fafc">
                <div class="fw-semibold mb-2">Vista previa real del post</div>
                <div id="campaignPreview" class="small text-muted">Completa el contenido para ver la vista previa.</div>
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
                <div class="col-md-6">
                  <label class="form-label">Destino de publicación</label>
                  <select id="myPagePublishMode" class="form-select">
                    <option value="both">Facebook + Instagram</option>
                    <option value="facebook">Facebook solo</option>
                    <option value="instagram">Instagram solo</option>
                  </select>
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
              <div class="d-flex gap-2">
                <input id="importTemplatesInput" type="file" accept=".json,application/json" class="d-none">
                <button class="btn btn-sm btn-outline-primary" type="button" onclick="document.getElementById('importTemplatesInput').click()"><i class="fas fa-file-import"></i></button>
                <button class="btn btn-sm btn-outline-success" type="button" onclick="exportJson('templates')"><i class="fas fa-file-export"></i></button>
                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoTemplates()"><i class="fas fa-sync"></i></button>
              </div>
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
              <div class="d-flex gap-2">
                <input id="importCampaignsInput" type="file" accept=".json,application/json" class="d-none">
                <button class="btn btn-sm btn-outline-primary" type="button" onclick="document.getElementById('importCampaignsInput').click()"><i class="fas fa-file-import"></i></button>
                <button class="btn btn-sm btn-outline-success" type="button" onclick="exportJson('campaigns')"><i class="fas fa-file-export"></i></button>
                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="loadPromoList()"><i class="fas fa-sync"></i></button>
              </div>
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
        <button class="btn btn-outline-danger btn-sm" type="button" onclick="clearLocalLogConsole()"><i class="fas fa-eraser"></i> Limpiar vista</button>
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
        <div id="campaignPreviewAudit" class="mb-3"></div>
        <div id="campaignVersionsAudit" class="mb-3"></div>
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

<div class="modal fade" id="campaignEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0"><i class="fas fa-pen"></i> Editar campaña</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editCampaignId">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="row g-2">
              <div class="col-12"><label class="form-label">Nombre</label><input id="editCampaignName" class="form-control"></div>
              <div class="col-md-6"><label class="form-label">Grupo</label><input id="editCampaignGroup" class="form-control"></div>
              <div class="col-md-6"><label class="form-label">Modo</label><select id="editCampaignMode" class="form-select"><option value="both">Facebook + Instagram</option><option value="facebook">Facebook solo</option><option value="instagram">Instagram solo</option></select></div>
              <div class="col-md-6"><label class="form-label">Hora</label><input id="editCampaignTime" type="time" class="form-control"></div>
              <div class="col-md-6"><label class="form-label">Estado</label><select id="editCampaignStatus" class="form-select"><option value="scheduled">scheduled</option><option value="queued">queued</option><option value="error">error</option><option value="done">done</option></select></div>
              <div class="col-12"><label class="form-label d-block">Días</label><div class="d-flex flex-wrap gap-2">
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="1"> <span class="form-check-label">L</span></label>
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="2"> <span class="form-check-label">M</span></label>
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="3"> <span class="form-check-label">X</span></label>
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="4"> <span class="form-check-label">J</span></label>
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="5"> <span class="form-check-label">V</span></label>
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="6"> <span class="form-check-label">S</span></label>
                <label class="form-check form-check-inline m-0"><input class="form-check-input edit-campaign-day" type="checkbox" value="0"> <span class="form-check-label">D</span></label>
              </div></div>
              <div class="col-12">
                <label class="form-label">Texto de publicación</label>
                <textarea id="editCampaignText" class="form-control" rows="4"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">Texto final / cierre</label>
                <textarea id="editCampaignOutro" class="form-control" rows="3"></textarea>
              </div>
              <div class="col-12">
                <div class="fw-semibold mb-2">Vista previa actualizada</div>
                <div id="editCampaignPreview" class="border rounded p-2 bg-light small">Sin datos.</div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label">Banners o logo (máximo 3)</label>
                <input id="editCampaignBannerInput" type="file" class="form-control" accept="image/*" multiple>
                <div id="editCampaignBannerWrap" class="border rounded p-2 mt-2" style="min-height:84px;max-height:220px;overflow:auto">
                  <div class="text-muted small">Sin imágenes cargadas.</div>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label">Buscar producto</label>
                <input id="editCampaignSearch" class="form-control" placeholder="Nombre o código">
                <div id="editCampaignSearchRes" class="list-group mt-1" style="max-height:180px;overflow:auto"></div>
              </div>
              <div class="col-12">
                <label class="form-label">Productos seleccionados</label>
                <div id="editCampaignProductsWrap" class="border rounded p-2" style="min-height:120px;max-height:280px;overflow:auto">
                  <div class="text-muted small">Sin productos seleccionados.</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" type="button" onclick="saveCampaignEdit()"><i class="fas fa-save"></i> Guardar cambios</button>
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
let manualGroups=[];
let editCampaignProducts=[];
let editCampaignBannerImages=[];
let activeCampaignLogId='';
let promoSearchTimer=null;
let editPromoSearchTimer=null;
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
function describePublishMode(mode){if(mode==='facebook') return 'Facebook solo'; if(mode==='instagram') return 'Instagram solo'; return 'Facebook + Instagram';}
function buildPreviewHtml({text='',products=[],banners=[],mode='both',outroText='',emptyText='Completa el contenido para ver la vista previa.'}={}){
  const targetName = page_name.value.trim() || page_id.value.trim() || 'página configurada';
  const igName = ig_username.value.trim() || ig_user_id.value.trim() || 'Instagram';
  const channelLabel = mode==='instagram' ? igName : (mode==='facebook' ? targetName : `${targetName} + ${igName}`);
  let html = `<div class="social-preview"><div class="social-preview-header"><div class="social-avatar">${esc((business_name.value.trim()||'PW').slice(0,2).toUpperCase())}</div><div><div class="fw-semibold">${esc(business_name.value.trim()||targetName)}</div><div class="small text-muted">${esc(describePublishMode(mode))} · ${esc(channelLabel)}</div></div></div><div class="social-preview-body">`;
  if(text) html += `<div class="mb-3" style="white-space:pre-wrap">${esc(text)}</div>`;
  if(banners.length) html += `<div class="social-media-grid mb-3">${banners.map(img=>`<img src="${esc(img.url||'')}" alt="">`).join('')}</div>`;
  if(products.length) html += `<div class="fw-semibold small mb-1">Productos destacados</div><ul class="small mb-2">${products.slice(0,10).map(p=>`<li>${esc(p.name||p.id)} - $${Number(p.price||0).toFixed(2)}</li>`).join('')}</ul>`;
  if(outroText) html += `<div class="small text-muted" style="white-space:pre-wrap">${esc(outroText)}</div>`;
  if(!text && !products.length && !banners.length && !outroText) html += `<div class="text-muted">${esc(emptyText)}</div>`;
  html += `</div><div class="social-preview-footer"><span><i class="far fa-heart"></i> Me gusta</span><span><i class="far fa-comment"></i> Comentar</span><span><i class="far fa-paper-plane"></i> Compartir</span></div></div>`;
  return html;
}
function summarizeCampaignSnapshot(s){
  if(!s) return '<div class="small text-muted">Sin snapshot.</div>';
  const days=daysToText(s.schedule_days||[]);
  const text=s.text?esc(String(s.text).slice(0,160)):'<span class="text-muted">Sin texto</span>';
  const outro=s.outro_text?esc(String(s.outro_text).slice(0,120)):'<span class="text-muted">Sin cierre</span>';
  return `<div class="small">
    <div><b>Nombre:</b> ${esc(s.name||'-')}</div>
    <div><b>Grupo:</b> ${esc(s.campaign_group||'General')}</div>
    <div><b>Modo:</b> ${esc(describePublishMode(s.publish_mode||'both'))}</div>
    <div><b>Horario:</b> ${esc((s.schedule_time||'-') + ' | ' + days)}</div>
    <div><b>Productos:</b> ${Number((s.products||[]).length||0)} | <b>Banners:</b> ${Number((s.banner_images||[]).length||0)}</div>
    <div><b>Texto:</b> ${text}</div>
    <div><b>Cierre:</b> ${outro}</div>
  </div>`;
}
function updateTargetInfo(){const box=document.getElementById('campaignTargetInfo'); if(!box) return; const page=page_name.value.trim()||page_id.value.trim()||'página configurada'; const ig=ig_username.value.trim()||ig_user_id.value.trim()||'Instagram'; const mode=promoPublishMode.value||'both'; box.textContent=mode==='facebook'?page:(mode==='instagram'?ig:`${page} + ${ig}`);}
function renderCampaignPreview(source='campaign'){
  const box = source==='my_page' ? document.getElementById('myPagePreview') : document.getElementById('campaignPreview');
  if(!box) return;
  const text = source==='my_page' ? '' : (promoText.value||'').trim();
  const products = source==='my_page' ? [] : promoProducts;
  const banners = source==='my_page' ? [] : promoBannerImages;
  const mode = source==='my_page' ? (myPagePublishMode.value||'both') : (promoPublishMode.value||'both');
  box.innerHTML = buildPreviewHtml({text: text || (source==='my_page' ? 'Publicación automática diaria de catálogo y reservables.' : ''), products, banners, mode});
}
function renderManualGroups(){const wrap=document.getElementById('manualGroupsWrap'); if(!wrap) return; if(!manualGroups.length){wrap.innerHTML='<div class="text-muted small">Sin grupos manuales guardados.</div>';return;} wrap.innerHTML=manualGroups.map((g,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-2 small"><div class="flex-grow-1"><div class="fw-semibold">${esc(g.name||('Grupo '+(idx+1)))}</div>${g.url?`<a href="${esc(g.url)}" target="_blank" rel="noopener" class="text-decoration-none">${esc(g.url)}</a>`:'<div class="text-muted">Sin URL</div>'}</div><button class="btn btn-sm btn-outline-danger" type="button" onclick="removeManualGroup(${idx})"><i class="fas fa-trash"></i></button></div>`).join('');}
async function loadManualGroups(){const d=await g(API+'?action=manual_groups'); if(d.status!=='success') return; manualGroups=Array.isArray(d.rows)?d.rows:[]; renderManualGroups();}
async function saveManualGroups(){const d=await p(API+'?action=manual_groups',{rows:manualGroups}); if(d.status!=='success'){a('danger',d.msg||'No se pudo guardar grupos manuales'); return;} manualGroups=Array.isArray(d.rows)?d.rows:manualGroups; renderManualGroups();}
function addManualGroup(){const name=(manualGroupName.value||'').trim(); const url=(manualGroupUrl.value||'').trim(); if(!name && !url){a('danger','Escribe nombre o URL del grupo');return;} manualGroups.push({name,url}); manualGroupName.value=''; manualGroupUrl.value=''; renderManualGroups(); saveManualGroups();}
function removeManualGroup(idx){manualGroups.splice(idx,1); renderManualGroups(); saveManualGroups();}
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
  updateTargetInfo();
  renderCampaignPreview();
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
async function loadStats(){const d=await g(API+'?action=stats'); if(d.status!=='success') return; s1.textContent=d.stats.posts_today||0; s2.textContent=d.stats.facebook_today||0; s3.textContent=d.stats.instagram_today||0; if(!s4.textContent || s4.textContent==='-') s4.textContent=Number(d.stats.enabled||0)===1?'Activo':'Pausado';}
function renderRecentPosts(){const tb=document.getElementById('recentPostsRows'); const preview=document.getElementById('lastPostPreview'); if(!recentPosts.length){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin publicaciones</td></tr>'; preview.textContent='Sin datos.'; return;} tb.innerHTML=recentPosts.map((r,idx)=>`<tr onclick="showPostDetail(${idx})" style="cursor:pointer"><td class="small">${esc(r.created_at||'')}</td><td class="small"><span class="badge ${String(r.platform)==='instagram'?'bg-danger':'bg-primary'}">${esc(r.platform||'facebook')}</span></td><td class="small">${esc(r.page_name||'-')}</td><td class="small">${esc(r.campaign_id||'-')}</td><td>${stateBadge(r.status||'-')}</td><td class="small">${esc(r.fb_post_id||'-')}</td></tr>`).join(''); showPostDetail(0);}
function showPostDetail(idx){const row=recentPosts[idx]; if(!row) return; const lines=[`Fecha: ${row.created_at||'-'}`,`Plataforma: ${row.platform||'facebook'}`,`Destino: ${row.page_name||'-'} (${row.page_id||'-'})`,`Campaña: ${row.campaign_id||'-'}`,`Estado: ${row.status||'-'}`,`Post ID: ${row.fb_post_id||'-'}`,row.error_text?`Error: ${row.error_text}`:'','',String(row.message_text||'')].filter(Boolean); lastPostPreview.textContent=lines.join('\n');}
async function loadRecentPosts(){const d=await g(API+'?action=recent_posts'); if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar publicaciones');return;} recentPosts=Array.isArray(d.rows)?d.rows:[]; renderRecentPosts();}
function renderPromoTemplates(){const s=document.getElementById('promoTemplateSelect'); if(!s) return; s.innerHTML=['<option value="">(Sin plantilla)</option>'].concat(promoTemplates.map(t=>`<option value="${esc(t.id)}">${esc(t.name||t.id)}</option>`)).join('');}
async function loadPromoTemplates(){const d=await g(API+'?action=promo_templates'); if(d.status!=='success'){a('danger',d.msg||'No se pudieron cargar plantillas');return;} promoTemplates=Array.isArray(d.rows)?d.rows:[]; renderPromoTemplates(); renderProgrammingTab();}
async function savePromoTemplate(){const name=(promoTemplateName.value||'').trim(); const text=(promoText.value||'').trim(); const publishMode=(promoTemplateMode.value||promoPublishMode.value||'both').trim(); if(!name){a('danger','Pon nombre a la plantilla');return;} if(!text && !promoProducts.length && !promoBannerImages.length){a('danger','La plantilla no puede estar vacía');return;} const currentId=(promoTemplateSelect.value||'').trim(); const d=await p(API+'?action=promo_template_save',{id:currentId,name,text,publish_mode:publishMode,products:promoProducts,banner_images:promoBannerImages}); if(d.status==='success'){a('success','Plantilla guardada'); await loadPromoTemplates(); promoTemplateSelect.value=d.id||'';} else a('danger',d.msg||'No se pudo guardar plantilla');}
function applyPromoTemplate(){const id=(promoTemplateSelect.value||'').trim(); if(!id){a('danger','Selecciona una plantilla');return;} const t=promoTemplates.find(x=>String(x.id)===id); if(!t){a('danger','Plantilla no encontrada');return;} promoTemplateName.value=t.name||''; promoText.value=t.text||''; promoTemplateMode.value=t.publish_mode||'both'; promoPublishMode.value=t.publish_mode||'both'; promoProducts=Array.isArray(t.products)?t.products:[]; promoBannerImages=Array.isArray(t.banner_images)?t.banner_images:[]; renderPromoProducts(); renderPromoBanners(); updateTargetInfo(); renderCampaignPreview(); a('info','Plantilla cargada');}
async function clonePromoTemplate(){const id=(promoTemplateSelect.value||'').trim(); if(!id){a('danger','Selecciona una plantilla');return;} const t=promoTemplates.find(x=>String(x.id)===id); if(!t){a('danger','Plantilla no encontrada');return;} const d=await p(API+'?action=promo_template_save',{name:String(t.name||'Plantilla')+' (Copia)',text:t.text||'',publish_mode:t.publish_mode||'both',products:Array.isArray(t.products)?t.products:[],banner_images:Array.isArray(t.banner_images)?t.banner_images:[]}); if(d.status==='success'){a('success','Plantilla clonada'); await loadPromoTemplates(); promoTemplateSelect.value=d.id||'';} else a('danger',d.msg||'No se pudo clonar plantilla');}
async function deletePromoTemplate(){const id=(promoTemplateSelect.value||'').trim(); if(!id){a('danger','Selecciona una plantilla');return;} const d=await p(API+'?action=promo_template_delete',{id}); if(d.status==='success'){a('success','Plantilla eliminada'); await loadPromoTemplates(); promoTemplateName.value='';} else a('danger',d.msg||'No se pudo eliminar plantilla');}
function renderPromoProducts(){const w=document.getElementById('promoProductsWrap'); if(!w) return; if(!promoProducts.length){w.innerHTML='<div class="text-muted small">Sin productos seleccionados.</div>'; renderCampaignPreview(); return;} w.innerHTML=promoProducts.map((p,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-1"><img src="${esc(p.image||'')}" alt="" width="42" height="42" style="object-fit:cover;border-radius:6px;border:1px solid #ddd"><div class="small"><div class="fw-semibold">${esc(p.name)}</div><div class="text-muted">$${Number(p.price||0).toFixed(2)} · ${esc(p.id)}</div></div><div class="btn-group btn-group-sm ms-auto" role="group"><button class="btn btn-outline-secondary" type="button" onclick="movePromoProduct(${idx},-1)" ${idx===0?'disabled':''}><i class="fas fa-arrow-up"></i></button><button class="btn btn-outline-secondary" type="button" onclick="movePromoProduct(${idx},1)" ${idx===promoProducts.length-1?'disabled':''}><i class="fas fa-arrow-down"></i></button><button class="btn btn-outline-danger" type="button" onclick="removePromoProduct(${idx})"><i class="fas fa-times"></i></button></div></div>`).join(''); renderCampaignPreview();}
function renderPromoBanners(){const w=document.getElementById('promoBannerWrap'); if(!w) return; if(!promoBannerImages.length){w.innerHTML='<div class="text-muted small">Sin imágenes cargadas.</div>'; renderCampaignPreview(); return;} w.innerHTML=promoBannerImages.map((img,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-2"><img src="${esc(img.url||'')}" alt="" width="72" height="48" style="object-fit:cover;border-radius:6px;border:1px solid #ddd"><div class="small" style="min-width:0"><div class="fw-semibold text-truncate">${esc(img.name||('Banner '+(idx+1)))}</div><div class="text-muted text-truncate">${esc(img.url||'')}</div></div><div class="btn-group btn-group-sm ms-auto" role="group"><button class="btn btn-outline-secondary" type="button" onclick="movePromoBanner(${idx},-1)" ${idx===0?'disabled':''}><i class="fas fa-arrow-up"></i></button><button class="btn btn-outline-secondary" type="button" onclick="movePromoBanner(${idx},1)" ${idx===promoBannerImages.length-1?'disabled':''}><i class="fas fa-arrow-down"></i></button><button class="btn btn-outline-danger" type="button" onclick="removePromoBanner(${idx})"><i class="fas fa-times"></i></button></div></div>`).join(''); renderCampaignPreview();}
function renderEditCampaignPreview(){const box=document.getElementById('editCampaignPreview'); if(!box) return; box.innerHTML=buildPreviewHtml({text:(editCampaignText.value||'').trim(),outroText:(editCampaignOutro.value||'').trim(),products:editCampaignProducts,banners:editCampaignBannerImages,mode:(editCampaignMode.value||'both').trim(),emptyText:'Edita el contenido para ver la vista previa.'});}
function renderEditCampaignProducts(){const w=document.getElementById('editCampaignProductsWrap'); if(!w) return; if(!editCampaignProducts.length){w.innerHTML='<div class="text-muted small">Sin productos seleccionados.</div>'; renderEditCampaignPreview(); return;} w.innerHTML=editCampaignProducts.map((p,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-1"><img src="${esc(p.image||'')}" alt="" width="42" height="42" style="object-fit:cover;border-radius:6px;border:1px solid #ddd"><div class="small"><div class="fw-semibold">${esc(p.name)}</div><div class="text-muted">$${Number(p.price||0).toFixed(2)} · ${esc(p.id)}</div></div><div class="btn-group btn-group-sm ms-auto" role="group"><button class="btn btn-outline-secondary" type="button" onclick="moveEditCampaignProduct(${idx},-1)" ${idx===0?'disabled':''}><i class="fas fa-arrow-up"></i></button><button class="btn btn-outline-secondary" type="button" onclick="moveEditCampaignProduct(${idx},1)" ${idx===editCampaignProducts.length-1?'disabled':''}><i class="fas fa-arrow-down"></i></button><button class="btn btn-outline-danger" type="button" onclick="removeEditCampaignProduct(${idx})"><i class="fas fa-times"></i></button></div></div>`).join(''); renderEditCampaignPreview();}
function renderEditCampaignBanners(){const w=document.getElementById('editCampaignBannerWrap'); if(!w) return; if(!editCampaignBannerImages.length){w.innerHTML='<div class="text-muted small">Sin imágenes cargadas.</div>'; renderEditCampaignPreview(); return;} w.innerHTML=editCampaignBannerImages.map((img,idx)=>`<div class="d-flex align-items-center gap-2 border-bottom py-2"><img src="${esc(img.url||'')}" alt="" width="72" height="48" style="object-fit:cover;border-radius:6px;border:1px solid #ddd"><div class="small" style="min-width:0"><div class="fw-semibold text-truncate">${esc(img.name||('Banner '+(idx+1)))}</div><div class="text-muted text-truncate">${esc(img.url||'')}</div></div><div class="btn-group btn-group-sm ms-auto" role="group"><button class="btn btn-outline-secondary" type="button" onclick="moveEditCampaignBanner(${idx},-1)" ${idx===0?'disabled':''}><i class="fas fa-arrow-up"></i></button><button class="btn btn-outline-secondary" type="button" onclick="moveEditCampaignBanner(${idx},1)" ${idx===editCampaignBannerImages.length-1?'disabled':''}><i class="fas fa-arrow-down"></i></button><button class="btn btn-outline-danger" type="button" onclick="removeEditCampaignBanner(${idx})"><i class="fas fa-times"></i></button></div></div>`).join(''); renderEditCampaignPreview();}
function removePromoBanner(idx){promoBannerImages.splice(idx,1);renderPromoBanners();}
function removePromoProduct(idx){promoProducts.splice(idx,1);renderPromoProducts();}
function movePromoProduct(idx,delta){const to=idx+delta; if(to<0||to>=promoProducts.length) return; const tmp=promoProducts[idx]; promoProducts[idx]=promoProducts[to]; promoProducts[to]=tmp; renderPromoProducts();}
function movePromoBanner(idx,delta){const to=idx+delta; if(to<0||to>=promoBannerImages.length) return; const tmp=promoBannerImages[idx]; promoBannerImages[idx]=promoBannerImages[to]; promoBannerImages[to]=tmp; renderPromoBanners();}
function removeEditCampaignBanner(idx){editCampaignBannerImages.splice(idx,1);renderEditCampaignBanners();}
function removeEditCampaignProduct(idx){editCampaignProducts.splice(idx,1);renderEditCampaignProducts();}
function moveEditCampaignProduct(idx,delta){const to=idx+delta; if(to<0||to>=editCampaignProducts.length) return; const tmp=editCampaignProducts[idx]; editCampaignProducts[idx]=editCampaignProducts[to]; editCampaignProducts[to]=tmp; renderEditCampaignProducts();}
function moveEditCampaignBanner(idx,delta){const to=idx+delta; if(to<0||to>=editCampaignBannerImages.length) return; const tmp=editCampaignBannerImages[idx]; editCampaignBannerImages[idx]=editCampaignBannerImages[to]; editCampaignBannerImages[to]=tmp; renderEditCampaignBanners();}
function addPromoProduct(p){if(!p||!p.id) return; if(promoProducts.some(x=>String(x.id)===String(p.id))) return; promoProducts.push(p); renderPromoProducts();}
function addEditCampaignProduct(p){if(!p||!p.id) return; if(editCampaignProducts.some(x=>String(x.id)===String(p.id))) return; editCampaignProducts.push(p); renderEditCampaignProducts();}
async function onPromoBannerInput(ev){const files=[...(ev.target.files||[])]; if(!files.length) return; const remaining=Math.max(0,3-promoBannerImages.length); if(!remaining){a('danger','Solo se permiten hasta 3 imágenes');ev.target.value='';return;} const selected=files.slice(0,remaining); for(const file of selected){const d=await uploadPromoBanner(file); if(d.status==='success'){promoBannerImages.push({url:d.url,name:d.name||file.name}); renderPromoBanners();}else{a('danger',d.msg||('No se pudo subir '+file.name));}} if(files.length>remaining) a('info','Máximo 3 imágenes por campaña.'); ev.target.value='';}
async function onEditCampaignBannerInput(ev){const files=[...(ev.target.files||[])]; if(!files.length) return; const remaining=Math.max(0,3-editCampaignBannerImages.length); if(!remaining){a('danger','Solo se permiten hasta 3 imágenes');ev.target.value='';return;} const selected=files.slice(0,remaining); for(const file of selected){const d=await uploadPromoBanner(file); if(d.status==='success'){editCampaignBannerImages.push({url:d.url,name:d.name||file.name}); renderEditCampaignBanners();}else{a('danger',d.msg||('No se pudo subir '+file.name));}} if(files.length>remaining) a('info','Máximo 3 imágenes por campaña.'); ev.target.value='';}
async function searchPromoProducts(q){const box=document.getElementById('promoSearchRes'); if(!box) return; if(!q || q.trim().length<2){box.innerHTML='';return;} const d=await g(API+'?action=promo_products&q='+encodeURIComponent(q.trim())); if(d.status!=='success'){box.innerHTML='';return;} const rows=Array.isArray(d.rows)?d.rows:[]; box.innerHTML=rows.map((r,idx)=>`<button class="list-group-item list-group-item-action d-flex align-items-center gap-2" type="button" data-add-idx="${idx}"><img src="${esc(r.image||'')}" alt="" width="32" height="32" style="object-fit:cover;border-radius:5px;border:1px solid #ddd"><span class="small">${esc(r.name)} <span class="text-muted">(${esc(r.id)})</span> - $${Number(r.price||0).toFixed(2)}</span></button>`).join(''); box.querySelectorAll('[data-add-idx]').forEach(btn=>btn.addEventListener('click',()=>{addPromoProduct(rows[parseInt(btn.dataset.addIdx,10)]); box.innerHTML='';}));}
async function searchEditCampaignProducts(q){const box=document.getElementById('editCampaignSearchRes'); if(!box) return; if(!q || q.trim().length<2){box.innerHTML='';return;} const d=await g(API+'?action=promo_products&q='+encodeURIComponent(q.trim())); if(d.status!=='success'){box.innerHTML='';return;} const rows=Array.isArray(d.rows)?d.rows:[]; box.innerHTML=rows.map((r,idx)=>`<button class="list-group-item list-group-item-action d-flex align-items-center gap-2" type="button" data-edit-add-idx="${idx}"><img src="${esc(r.image||'')}" alt="" width="32" height="32" style="object-fit:cover;border-radius:5px;border:1px solid #ddd"><span class="small">${esc(r.name)} <span class="text-muted">(${esc(r.id)})</span> - $${Number(r.price||0).toFixed(2)}</span></button>`).join(''); box.querySelectorAll('[data-edit-add-idx]').forEach(btn=>btn.addEventListener('click',()=>{addEditCampaignProduct(rows[parseInt(btn.dataset.editAddIdx,10)]); box.innerHTML='';}));}
async function createPromoCampaign(){try{const text=(promoText.value||'').trim(); const campaignName=(promoCampaignName.value||'').trim(); const campaignGroup=(promoCampaignGroup.value||'').trim()||'General'; const publishMode=(promoPublishMode.value||'both').trim(); const scheduleTime=(promoScheduleTime.value||'').trim(); const scheduleDays=selectedPromoDays(); if(!text && !promoProducts.length && !promoBannerImages.length){a('danger','La campaña no puede estar vacía');return;} if(!scheduleTime){a('danger','Selecciona hora de lanzamiento');return;} if(!scheduleDays.length){a('danger','Selecciona al menos un día');return;} const previewHtml=(document.getElementById('campaignPreview')?.innerHTML||'').trim(); const d=await p(API+'?action=promo_create',{campaign_name:campaignName,campaign_group:campaignGroup,publish_mode:publishMode,preview_html:previewHtml,template_id:(promoTemplateSelect.value||'').trim(),text,banner_images:promoBannerImages,products:promoProducts,schedule_enabled:1,schedule_time:scheduleTime,schedule_days:scheduleDays}); if(d.status==='success'){a('success','Campaña programada: '+(d.id||'')); loadPromoList();} else a('danger',d.msg||'Error al crear campaña');}catch(e){a('danger','No se pudo programar la campaña: '+(e?.message||'error inesperado'));}}
function renderMyPagePreview(payload){const box=document.getElementById('myPagePreview'); if(!box) return; const products=Array.isArray(payload?.products)?payload.products:[]; const reservables=Array.isArray(payload?.reservables)?payload.reservables:[]; box.innerHTML=[`<div class="social-preview"><div class="social-preview-header"><div class="social-avatar">${esc((business_name.value.trim()||'PW').slice(0,2).toUpperCase())}</div><div><div class="fw-semibold">${esc(business_name.value.trim()||page_name.value||page_id.value||'tu página')}</div><div class="small text-muted">${esc(describePublishMode(myPagePublishMode.value||'both'))} · ${esc(page_name.value||page_id.value||'tu página')}</div></div></div><div class="social-preview-body"><div class="mb-2"><span class="badge bg-success">${products.length}</span> productos con existencias</div><div class="mb-2"><span class="badge bg-info text-dark">${reservables.length}</span> productos reservables al cierre</div><div class="fw-semibold small mb-1">Texto final</div><div style="white-space:pre-wrap">${esc(payload?.outro_text||'')}</div></div><div class="social-preview-footer"><span><i class="far fa-heart"></i> Me gusta</span><span><i class="far fa-comment"></i> Comentar</span><span><i class="far fa-paper-plane"></i> Compartir</span></div></div>`].join('');}
async function loadMyPagePreview(){const box=document.getElementById('myPagePreview'); if(!box) return; box.innerHTML='Cargando vista previa...'; const d=await g(API+'?action=promo_my_page_payload'); if(d.status!=='success'){box.innerHTML='No se pudo generar la vista previa.'; a('danger',d.msg||'No se pudo cargar Mi página'); return;} renderMyPagePreview(d);}
async function createMyPageCampaign(){try{const scheduleTime=(myPageScheduleTime.value||'').trim(); const campaignGroup=(myPageCampaignGroup.value||'').trim()||'Mi pagina'; const publishMode=(myPagePublishMode.value||'both').trim(); if(!scheduleTime){a('danger','Selecciona la hora diaria');return;} const payload=await g(API+'?action=promo_my_page_payload'); if(payload.status!=='success'){a('danger',payload.msg||'No se pudo preparar Mi página');return;} const products=Array.isArray(payload.products)?payload.products:[]; if(!products.length){a('danger','No hay productos con existencias disponibles');return;} const previewHtml=(document.getElementById('myPagePreview')?.innerHTML||'').trim(); const d=await p(API+'?action=promo_create',{campaign_name:'Mi pagina diaria',campaign_group:campaignGroup,publish_mode:publishMode,preview_html:previewHtml,text:'',outro_text:String(payload.outro_text||'').trim(),products,schedule_enabled:1,schedule_time:scheduleTime,schedule_days:[0,1,2,3,4,5,6]}); if(d.status==='success'){renderMyPagePreview(payload); a('success','Mi página programada: '+(d.id||'')); loadPromoList();} else a('danger',d.msg||'Error al crear Mi página');}catch(e){a('danger','No se pudo programar Mi página: '+(e?.message||'error inesperado'));}}
async function loadPromoList(){const d=await g(API+'?action=promo_list'); const tb=document.getElementById('promoRows'); if(!tb) return; if(d.status!=='success'){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr>'; promoCampaigns=[]; renderProgrammingTab(); return;} promoCampaigns=Array.isArray(d.rows)?d.rows:[]; if(!promoCampaigns.length){tb.innerHTML='<tr><td colspan="6" class="text-center text-muted p-3">Sin campañas</td></tr>'; renderProgrammingTab(); return;} tb.innerHTML=promoCampaigns.map(r=>`<tr><td class="small">${esc(r.created_at||'')}</td><td class="small">${esc(r.name||r.id||'')}<br><span class="badge bg-dark mt-1">${esc(describePublishMode(r.publish_mode||'both'))}</span></td><td class="small">${esc(r.campaign_group||'General')}</td><td class="small">${esc(r.schedule_time||'-')} (${esc(daysToText(r.schedule_days||[]))})<br><span class="text-muted">${esc(targetsToText(r.targets||[]))}</span></td><td>${stateBadge(r.status||'')}</td><td class="small"><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" type="button" title="Clonar" onclick="cloneScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-clone"></i></button><button class="btn btn-sm btn-outline-success" type="button" title="Enviar ahora" onclick="forceCampaignNow('${esc(r.id||'')}')"><i class="fas fa-bolt"></i></button><button class="btn btn-sm btn-outline-dark" type="button" title="Ver logs" onclick="openCampaignLogs('${esc(r.id||'')}')"><i class="fas fa-list-check"></i></button><button class="btn btn-sm btn-outline-primary" type="button" title="Editar" onclick="openCampaignEditModal('${esc(r.id||'')}')"><i class="fas fa-pen"></i></button><button class="btn btn-sm btn-outline-danger" type="button" title="Eliminar" onclick="deleteScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-trash"></i></button></div></td></tr>`).join(''); renderProgrammingTab();}
async function cloneScheduledCampaign(id){const d=await p(API+'?action=promo_clone',{id}); if(d.status==='success'){a('success','Campaña clonada: '+(d.name||d.id||'')); loadPromoList();} else a('danger',d.msg||'No se pudo clonar');}
async function deleteScheduledCampaign(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} if(!confirm(`¿Eliminar campaña "${row.name||row.id}"?`)) return; const d=await p(API+'?action=promo_delete',{id}); if(d.status==='success'){a('success','Campaña eliminada'); loadPromoList();} else a('danger',d.msg||'No se pudo eliminar');}
async function forceCampaignNow(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} if(!confirm(`¿Lanzar ahora la campaña "${row.name||row.id}"?`)) return; const d=await p(API+'?action=promo_force_now',{id}); if(d.status==='success'){a('success','Campaña enviada a cola para ejecutar ahora'); loadPromoList(); loadRecentPosts(); loadLogsSnapshot();} else a('danger',d.msg||'No se pudo forzar');}
async function editScheduledCampaign(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} const name=prompt('Nombre de campaña:',row.name||''); if(name===null) return; const group=prompt('Grupo de campaña:',row.campaign_group||'General'); if(group===null) return; const mode=prompt('Modo de publicación: facebook | instagram | both',row.publish_mode||'both'); if(mode===null) return; const time=prompt('Hora (HH:MM) zona Cuba (America/Havana):',row.schedule_time||'09:00'); if(time===null) return; const daysCurrent=Array.isArray(row.schedule_days)?row.schedule_days.join(','):'1,2,3,4,5'; const daysRaw=prompt('Días (0..6 separados por coma). 0=Dom,1=Lun,...,6=Sab',daysCurrent); if(daysRaw===null) return; const days=String(daysRaw).split(',').map(x=>parseInt(String(x).trim(),10)).filter(x=>!Number.isNaN(x) && x>=0 && x<=6); if(!days.length){a('danger','Debes indicar al menos un día válido (0..6)');return;} const d=await p(API+'?action=promo_update',{id,name:String(name).trim(),campaign_group:String(group).trim()||'General',publish_mode:String(mode).trim(),preview_html:String(row.preview_html||''),schedule_enabled:1,schedule_time:String(time).trim(),schedule_days:days,status:'scheduled'}); if(d.status==='success'){a('success','Campaña actualizada'); loadPromoList();} else a('danger',d.msg||'No se pudo actualizar');}
function selectedEditDays(){return [...document.querySelectorAll('.edit-campaign-day:checked')].map(x=>parseInt(x.value,10)).filter(x=>!Number.isNaN(x));}
function openCampaignEditModal(id){const row=promoCampaigns.find(x=>String(x.id)===String(id)); if(!row){a('danger','Campaña no encontrada');return;} editCampaignId.value=row.id||''; editCampaignName.value=row.name||''; editCampaignGroup.value=row.campaign_group||'General'; editCampaignMode.value=row.publish_mode||'both'; editCampaignTime.value=row.schedule_time||'09:00'; editCampaignStatus.value=row.status||'scheduled'; editCampaignText.value=row.text||''; editCampaignOutro.value=row.outro_text||''; editCampaignProducts=Array.isArray(row.products)?JSON.parse(JSON.stringify(row.products)):[]; editCampaignBannerImages=Array.isArray(row.banner_images)?JSON.parse(JSON.stringify(row.banner_images)):[]; document.querySelectorAll('.edit-campaign-day').forEach(cb=>cb.checked=Array.isArray(row.schedule_days)&&row.schedule_days.map(Number).includes(parseInt(cb.value,10))); document.getElementById('editCampaignSearchRes').innerHTML=''; renderEditCampaignProducts(); renderEditCampaignBanners(); renderEditCampaignPreview(); new bootstrap.Modal(document.getElementById('campaignEditModal')).show();}
async function saveCampaignEdit(){const id=(editCampaignId.value||'').trim(); const days=selectedEditDays(); if(!id){a('danger','Campaña no seleccionada');return;} if(!days.length){a('danger','Selecciona al menos un día');return;} const previewHtml=(document.getElementById('editCampaignPreview')?.innerHTML||'').trim(); const d=await p(API+'?action=promo_update',{id,name:(editCampaignName.value||'').trim(),campaign_group:(editCampaignGroup.value||'').trim()||'General',publish_mode:(editCampaignMode.value||'both').trim(),text:(editCampaignText.value||'').trim(),outro_text:(editCampaignOutro.value||'').trim(),products:editCampaignProducts,banner_images:editCampaignBannerImages,preview_html:previewHtml,schedule_enabled:1,schedule_time:(editCampaignTime.value||'09:00').trim(),schedule_days:days,status:(editCampaignStatus.value||'scheduled').trim()}); if(d.status==='success'){a('success','Campaña actualizada'); bootstrap.Modal.getInstance(document.getElementById('campaignEditModal'))?.hide(); loadPromoList();} else a('danger',d.msg||'No se pudo actualizar');}
function renderCampaignLogsModal(job){const summary=document.getElementById('campaignLogsSummary'); const rowsEl=document.getElementById('campaignLogsRows'); const previewEl=document.getElementById('campaignPreviewAudit'); const versionsEl=document.getElementById('campaignVersionsAudit'); const logs=Array.isArray(job.log)?job.log:[]; const versions=Array.isArray(job.versions)?job.versions:[]; const ok=logs.filter(x=>x&&x.ok===true).length; const fail=logs.filter(x=>x&&x.ok===false).length; const sent=logs.reduce((acc,x)=>acc+Number((x&&x.messages_sent)||0),0); const targets=(Array.isArray(job.targets)?job.targets:[]).map(t=>String(t.name||t.id||'')).join(' | '); summary.textContent=`Campaña: ${job.name||job.id||'-'} | Grupo: ${job.campaign_group||'General'} | Modo: ${describePublishMode(job.publish_mode||'both')} | Publicaciones: ${sent} | OK: ${ok} | Fallos: ${fail} | Página: ${targets||'-'}`; previewEl.innerHTML=job.preview_html?`<div class=\"fw-semibold mb-2\">Snapshot HTML del preview publicado</div><div class=\"border rounded p-2 bg-light\">${job.preview_html}</div>`:'<div class=\"small text-muted mb-2\">Sin snapshot HTML guardado para esta campaña.</div>'; versionsEl.innerHTML=versions.length?`<div class="fw-semibold mb-2">Historial de versiones</div>${versions.slice().reverse().map(v=>`<div class="border rounded p-2 mb-2"><div class="small fw-semibold mb-2">${esc(v.type||'update')} · ${esc(v.at||'-')} · ${esc(v.actor||'-')}</div>${v.note?`<div class="small text-muted mb-2">${esc(v.note)}</div>`:''}<div class="row g-2"><div class="col-md-6"><div class="border rounded p-2 bg-light"><div class="fw-semibold small mb-1">Antes</div>${summarizeCampaignSnapshot(v.before)}</div></div><div class="col-md-6"><div class="border rounded p-2 bg-white"><div class="fw-semibold small mb-1">Después</div>${summarizeCampaignSnapshot(v.after)}</div></div></div></div>`).join('')}`:'<div class="small text-muted mb-2">Sin historial de versiones todavía.</div>'; if(!logs.length){rowsEl.innerHTML='<tr><td colspan=\"5\" class=\"text-center text-muted p-3\">Sin logs aún</td></tr>'; return;} rowsEl.innerHTML=logs.slice().reverse().map(l=>`<tr><td class=\"small\">${esc(l.at||'-')}</td><td class=\"small\">${esc(l.target_name||l.target_id||'-')}<br><span class=\"text-muted\">${esc(describePublishMode(l.publish_mode||job.publish_mode||'both'))}</span></td><td class=\"small\">${Number(l.messages_sent||0)}<br><span class=\"text-muted\">${esc([l.facebook_post_id?('FB '+l.facebook_post_id):'',l.instagram_post_id?('IG '+l.instagram_post_id):''].filter(Boolean).join(' | '))}</span></td><td>${l.ok===true?'<span class=\"badge bg-success\">OK</span>':'<span class=\"badge bg-danger\">Fallo</span>'}</td><td class=\"small text-danger\">${esc(l.error||'')}</td></tr>`).join('');}
async function refreshCampaignLogs(){if(!activeCampaignLogId) return; const d=await g(API+'?action=promo_detail&id='+encodeURIComponent(activeCampaignLogId)); if(d.status!=='success' || !d.row){a('danger',d.msg||'No se pudieron cargar logs');return;} renderCampaignLogsModal(d.row);}
async function openCampaignLogs(id){activeCampaignLogId=String(id||''); await refreshCampaignLogs(); new bootstrap.Modal(document.getElementById('campaignLogsModal')).show();}
function renderProgrammingTab(){const tplRows=document.getElementById('promoTemplateRows'); if(tplRows){if(!promoTemplates.length){tplRows.innerHTML='<tr><td colspan="3" class="text-center text-muted p-3">Sin plantillas</td></tr>';} else {tplRows.innerHTML=promoTemplates.map(t=>`<tr><td class="small fw-semibold">${esc(t.name||t.id||'-')}<br><span class="badge bg-dark mt-1">${esc(describePublishMode(t.publish_mode||'both'))}</span></td><td class="small">${Array.isArray(t.products)?t.products.length:0}</td><td class="small">${esc(t.updated_at||'-')}</td></tr>`).join('');}} const wrap=document.getElementById('promoProgramGroups'); if(!wrap) return; const scheduled=promoCampaigns.filter(r=>Number(r.schedule_enabled||0)===1); if(!scheduled.length){wrap.innerHTML='<div class="text-muted small">Sin campañas programadas.</div>'; return;} const groups={}; for(const r of scheduled){const g=String(r.campaign_group||'General'); if(!groups[g]) groups[g]=[]; groups[g].push(r);} wrap.innerHTML=Object.keys(groups).sort().map(g=>`<div class="border rounded p-2 mb-2"><div class="fw-semibold mb-1">${esc(g)}</div><div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Campaña</th><th>Modo</th><th>Hora</th><th>Días</th><th>Estado</th><th>Página</th><th>Acciones</th></tr></thead><tbody>${groups[g].map(r=>`<tr><td class="small">${esc(r.name||r.id||'-')}</td><td class="small">${esc(describePublishMode(r.publish_mode||'both'))}</td><td class="small">${esc(r.schedule_time||'-')}</td><td class="small">${esc(daysToText(r.schedule_days||[]))}</td><td>${stateBadge(r.status||'-')}</td><td class="small text-muted">${esc(targetsToText(r.targets||[]))}</td><td class="small"><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-success" type="button" title="Enviar ahora" onclick="forceCampaignNow('${esc(r.id||'')}')"><i class="fas fa-bolt"></i></button><button class="btn btn-sm btn-outline-secondary" type="button" title="Clonar" onclick="cloneScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-clone"></i></button><button class="btn btn-sm btn-outline-dark" type="button" title="Ver logs" onclick="openCampaignLogs('${esc(r.id||'')}')"><i class="fas fa-list-check"></i></button><button class="btn btn-sm btn-outline-primary" type="button" onclick="openCampaignEditModal('${esc(r.id||'')}')"><i class="fas fa-pen"></i></button><button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteScheduledCampaign('${esc(r.id||'')}')"><i class="fas fa-trash"></i></button></div></td></tr>`).join('')}</tbody></table></div></div>`).join('');}
function exportJson(type){const payload=type==='templates'?promoTemplates:promoCampaigns; const blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json;charset=utf-8'}); const url=URL.createObjectURL(blob); const aEl=document.createElement('a'); aEl.href=url; aEl.download=`fb_${type}_${new Date().toISOString().replace(/[:.]/g,'-')}.json`; document.body.appendChild(aEl); aEl.click(); aEl.remove(); setTimeout(()=>URL.revokeObjectURL(url),1000);}
async function importJson(type,file){if(!file) return; try{const raw=await file.text(); const parsed=JSON.parse(raw); const rows=Array.isArray(parsed)?parsed:(Array.isArray(parsed.rows)?parsed.rows:[]); if(!rows.length){a('danger','El JSON no contiene filas válidas'); return;} const d=await p(API+'?action=promo_import',{type,rows}); if(d.status==='success'){a('success',`Importados ${d.imported||0} ${type==='templates'?'elementos de plantilla':'campañas'}`); if(type==='templates') await loadPromoTemplates(); else await loadPromoList();} else a('danger',d.msg||'No se pudo importar');}catch(e){a('danger','JSON inválido o no legible');}}
async function testPost(){const text=(testText.value||'').trim()||'Prueba de publicación desde PalWeb Facebook'; const d=await p(API+'?action=test_post',{text,publish_mode:testPublishMode.value||'both'}); if(d.status==='success'){a('success','Publicación de prueba enviada'); loadRecentPosts(); loadStats(); loadLogsSnapshot();} else a('danger',d.msg||'No se pudo publicar');}
async function runQueue(){const wk=(worker_key.value||currentConfig.worker_key||'').trim(); if(!wk){a('danger','Configura el worker key primero');return;} const d=await g(API+'?action=process_queue&worker_key='+encodeURIComponent(wk)); if(d.status==='success'){appendLog(`Queue ejecutada. Procesadas: ${d.processed||0}`); loadPromoList(); loadRecentPosts(); loadStats();} else appendLog(`Fallo process_queue: ${d.msg||'error'}`);}
function appendLog(text){const box=document.getElementById('logsText'); const stamp=new Date().toISOString(); const current=box.textContent||''; box.textContent=`[${stamp}] ${text}\n` + current.slice(0,12000); document.getElementById('logsStatus').textContent=`Última actualización: ${stamp}`;}
async function loadLogsSnapshot(){const d=await g(API+'?action=worker_logs'); if(d.status==='success'){logsText.textContent=(d.logs||'Sin logs persistentes.'); logsStatus.textContent='Logs persistentes del worker y del procesador de campañas';} await Promise.all([loadPromoList(),loadRecentPosts(),loadStats()]);}
function clearLocalLogConsole(){logsText.textContent=''; logsStatus.textContent='Vista limpiada';}
async function openLogsModal(){await loadLogsSnapshot(); new bootstrap.Modal(document.getElementById('logsModal')).show();}
async function loadAll(){try{await Promise.all([loadCfg(),loadStats(),loadRecentPosts(),loadPromoTemplates(),loadPromoList(),loadMyPagePreview(),loadManualGroups()]); await runQueueSilently();}catch(e){a('danger',e.message||'error')}}
async function runQueueSilently(){const wk=(worker_key.value||currentConfig.worker_key||'').trim(); if(!wk) return; const d=await g(API+'?action=process_queue&worker_key='+encodeURIComponent(wk)); if(d.status==='success' && Number(d.processed||0)>0){appendLog(`Queue automática: ${d.processed} campaña(s) procesada(s).`); await Promise.all([loadPromoList(),loadRecentPosts(),loadStats()]);}}
document.getElementById('f').addEventListener('submit',saveCfg);
document.getElementById('promoSearch').addEventListener('input',ev=>{if(promoSearchTimer) clearTimeout(promoSearchTimer); promoSearchTimer=setTimeout(()=>searchPromoProducts(ev.target.value||''),260);});
document.getElementById('promoBannerInput').addEventListener('change',onPromoBannerInput);
document.getElementById('editCampaignSearch').addEventListener('input',ev=>{if(editPromoSearchTimer) clearTimeout(editPromoSearchTimer); editPromoSearchTimer=setTimeout(()=>searchEditCampaignProducts(ev.target.value||''),260);});
document.getElementById('editCampaignBannerInput').addEventListener('change',onEditCampaignBannerInput);
document.getElementById('importTemplatesInput').addEventListener('change',async ev=>{await importJson('templates',ev.target.files?.[0]); ev.target.value='';});
document.getElementById('importCampaignsInput').addEventListener('change',async ev=>{await importJson('campaigns',ev.target.files?.[0]); ev.target.value='';});
['promoText','promoCampaignName','promoCampaignGroup','promoPublishMode','page_name','page_id','ig_username','ig_user_id'].forEach(id=>document.getElementById(id)?.addEventListener('input',()=>{updateTargetInfo();renderCampaignPreview();}));
document.getElementById('promoPublishMode')?.addEventListener('change',()=>{updateTargetInfo();renderCampaignPreview();});
['editCampaignName','editCampaignGroup','editCampaignMode','editCampaignText','editCampaignOutro','page_name','page_id','ig_username','ig_user_id','business_name'].forEach(id=>document.getElementById(id)?.addEventListener('input',renderEditCampaignPreview));
document.getElementById('editCampaignMode')?.addEventListener('change',renderEditCampaignPreview);
document.getElementById('myPagePublishMode')?.addEventListener('change',()=>loadMyPagePreview());
loadAll();
setInterval(()=>{loadStats();loadRecentPosts();loadPromoList();runQueueSilently();},15000);
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
