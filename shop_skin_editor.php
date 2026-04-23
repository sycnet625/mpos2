<?php
// ============================================================================
// ARCHIVO: shop_skin_editor.php
// EDITOR VISUAL DE APARIENCIAS (SKINS) PARA SHOP.PHP
// ============================================================================
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
require_once 'config_loader.php';
require_once 'shop_skins.php';

$feedback = '';
$feedbackType = 'success';

// ── API JSON ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    try {
        $custom = shop_skin_custom();

        if ($action === 'save') {
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($input['id'] ?? '')));
            if ($id === '') throw new Exception('ID de skin inválido.');

            $builtins = array_keys(shop_skin_builtin());
            if (in_array($id, $builtins, true)) throw new Exception('No puedes sobreescribir un skin predefinido.');

            $vars = [];
            foreach (['--primary','--secondary','--success','--danger','--body-bg','--body-fg','--nav-grad-1','--nav-grad-2','--card-radius','--card-shadow'] as $k) {
                if (isset($input['vars'][$k])) $vars[$k] = strip_tags(trim($input['vars'][$k]));
            }

            $custom[$id] = [
                'nombre'       => strip_tags(trim($input['nombre'] ?? $id)),
                'descripcion'  => strip_tags(trim($input['descripcion'] ?? '')),
                'preview'      => array_map('strip_tags', array_slice((array)($input['preview'] ?? []), 0, 3)),
                'font_google'  => preg_replace('/[^A-Za-z0-9_+:;&@%,. ]/', '', $input['font_google'] ?? ''),
                'body_font'    => strip_tags(trim($input['body_font'] ?? "'Inter', sans-serif")),
                'heading_font' => strip_tags(trim($input['heading_font'] ?? "'Inter', sans-serif")),
                'vars'         => $vars,
                'body_class'   => 'skin-custom-' . $id,
                'extra_css'    => strip_tags(trim($input['extra_css'] ?? '')),
                'is_custom'    => true,
            ];

            file_put_contents(SHOP_SKINS_CUSTOM_FILE, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode(['status' => 'success', 'id' => $id]);

        } elseif ($action === 'delete') {
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($input['id'] ?? '')));
            if (!isset($custom[$id])) throw new Exception('Skin no encontrado.');
            unset($custom[$id]);
            file_put_contents(SHOP_SKINS_CUSTOM_FILE, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode(['status' => 'success']);

        } else {
            throw new Exception('Acción desconocida.');
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

$allSkins     = shop_skin_all();
$builtinIds   = array_keys(shop_skin_builtin());
$customSkins  = shop_skin_custom();
$googleFonts  = [
    'Inter'          => 'Inter:wght@400;500;600;700',
    'Poppins'        => 'Poppins:wght@400;500;600;700',
    'Roboto'         => 'Roboto:wght@400;500;700',
    'Lato'           => 'Lato:wght@400;700',
    'Montserrat'     => 'Montserrat:wght@400;500;600;700',
    'Open Sans'      => 'Open+Sans:wght@400;500;600;700',
    'Nunito'         => 'Nunito:wght@400;500;600;700',
    'Raleway'        => 'Raleway:wght@400;500;600;700',
    'Playfair Display' => 'Playfair+Display:wght@400;600;700',
    'Merriweather'   => 'Merriweather:wght@400;700',
    'Space Grotesk'  => 'Space+Grotesk:wght@400;500;600;700',
    'Rajdhani'       => 'Rajdhani:wght@400;500;600;700',
    'Caveat'         => 'Caveat:wght@400;600;700',
    'Oswald'         => 'Oswald:wght@400;500;600;700',
    'DM Sans'        => 'DM+Sans:wght@400;500;600;700',
    'Sora'           => 'Sora:wght@400;500;600;700',
    'Pacifico'       => 'Pacifico',
    'Sistema'        => '',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de Apariencias · PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <style>
        body { background: var(--pw-body); color: var(--pw-text); }

        /* ── Layout dos columnas ── */
        #editorLayout { display: grid; grid-template-columns: 400px 1fr; gap: 0; height: calc(100vh - 80px); overflow: hidden; }
        #controlPanel { overflow-y: auto; border-right: 1px solid var(--pw-line); }
        #previewPanel { overflow: hidden; display: flex; flex-direction: column; }

        /* ── Panel controles ── */
        .ctrl-section { padding: 14px 16px; border-bottom: 1px solid var(--pw-line); }
        .ctrl-section-title { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--pw-muted); margin-bottom: 10px; }
        .ctrl-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .ctrl-row label { font-size: .78rem; white-space: nowrap; min-width: 120px; color: var(--pw-text); }
        .ctrl-row input[type=color] { width: 44px; height: 30px; border: 1px solid var(--pw-line); border-radius: 6px; cursor: pointer; padding: 2px; }
        .ctrl-row input[type=text], .ctrl-row input[type=range], .ctrl-row select, .ctrl-row textarea { flex: 1; font-size: .78rem; }
        .val-label { font-size: .72rem; font-family: monospace; color: var(--pw-muted); min-width: 52px; }

        /* ── Skin chips ── */
        .skin-chip { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 8px; cursor: pointer; border: 2px solid transparent; transition: all .18s; margin-bottom: 4px; }
        .skin-chip:hover { background: var(--pw-hover); }
        .skin-chip.active { border-color: #0d6efd; background: rgba(13,110,253,.08); }
        .skin-chip .swatches { display: flex; gap: 3px; }
        .skin-chip .swatch { width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,.12); }
        .skin-chip .chip-name { font-size: .82rem; font-weight: 600; flex: 1; }
        .skin-chip .chip-badge { font-size: .65rem; padding: 1px 6px; border-radius: 99px; }
        .skin-chip .chip-actions { opacity: 0; transition: opacity .15s; }
        .skin-chip:hover .chip-actions { opacity: 1; }

        /* ── Preview ── */
        #previewFrame { flex: 1; border: none; width: 100%; height: 100%; }
        #previewToolbar { padding: 6px 12px; background: var(--pw-card); border-bottom: 1px solid var(--pw-line); display: flex; align-items: center; gap: 8px; font-size: .8rem; }
        .device-btn { border: 1px solid var(--pw-line); background: transparent; border-radius: 6px; padding: 3px 8px; cursor: pointer; color: var(--pw-text); }
        .device-btn.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }

        /* ── Navbar header ── */
        #editorNav { display: flex; align-items: center; padding: 0 16px; height: 56px; border-bottom: 1px solid var(--pw-line); background: var(--pw-card); gap: 12px; }
    </style>
</head>
<body>

<!-- Top bar -->
<div id="editorNav">
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i></a>
    <a href="pos_config2.php#skins" class="btn btn-sm btn-outline-secondary"><i class="fas fa-cog me-1"></i>Config</a>
    <span class="fw-bold"><i class="fas fa-palette me-2 text-primary"></i>Editor de Apariencias</span>
    <span class="badge bg-secondary ms-1"><?= count($allSkins) ?> skins</span>
    <div class="ms-auto d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" onclick="newSkin()"><i class="fas fa-plus me-1"></i>Nuevo</button>
        <button class="btn btn-sm btn-primary" id="btnSave" onclick="saveSkin()"><i class="fas fa-save me-1"></i>Guardar</button>
    </div>
</div>

<div id="editorLayout">
    <!-- ══ PANEL IZQUIERDO: controles ══════════════════════════════════════ -->
    <div id="controlPanel">

        <!-- Lista de skins -->
        <div class="ctrl-section">
            <div class="ctrl-section-title">Skins disponibles</div>
            <div id="skinList">
                <?php foreach ($allSkins as $sid => $sk):
                    $isCustom = !in_array($sid, $builtinIds, true);
                    $preview  = $sk['preview'] ?? ['#667eea','#764ba2','#0d6efd'];
                ?>
                <div class="skin-chip <?= $sid === 'clasico_marino' ? 'active' : '' ?>"
                     id="chip-<?= htmlspecialchars($sid) ?>"
                     onclick="loadSkin('<?= htmlspecialchars($sid) ?>')">
                    <div class="swatches">
                        <?php foreach ($preview as $c): ?>
                        <div class="swatch" style="background:<?= htmlspecialchars($c) ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <span class="chip-name"><?= htmlspecialchars($sk['nombre']) ?></span>
                    <?php if ($isCustom): ?>
                    <span class="badge bg-primary chip-badge">custom</span>
                    <div class="chip-actions">
                        <button class="btn btn-xs btn-outline-danger border-0 p-0 px-1" onclick="event.stopPropagation();deleteSkin('<?= htmlspecialchars($sid) ?>','<?= htmlspecialchars($sk['nombre']) ?>')">
                            <i class="fas fa-trash" style="font-size:.7rem"></i>
                        </button>
                    </div>
                    <?php else: ?>
                    <span class="badge bg-secondary chip-badge">base</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ID y nombre -->
        <div class="ctrl-section">
            <div class="ctrl-section-title">Identificación</div>
            <div class="ctrl-row">
                <label>ID (slug)</label>
                <input type="text" id="skinId" class="form-control form-control-sm" placeholder="mi_skin_custom" pattern="[a-z0-9_]+">
            </div>
            <div class="ctrl-row">
                <label>Nombre</label>
                <input type="text" id="skinNombre" class="form-control form-control-sm" placeholder="Mi Skin">
            </div>
            <div class="ctrl-row">
                <label>Descripción</label>
                <input type="text" id="skinDesc" class="form-control form-control-sm" placeholder="Breve descripción...">
            </div>
            <small class="text-muted" style="font-size:.7rem">Los skins base no se pueden sobreescribir. Crea uno nuevo a partir de ellos.</small>
        </div>

        <!-- Tipografía -->
        <div class="ctrl-section">
            <div class="ctrl-section-title">Tipografía</div>
            <div class="ctrl-row">
                <label>Fuente cuerpo</label>
                <select id="bodyFontSel" class="form-select form-select-sm" onchange="onFontChange()">
                    <?php foreach ($googleFonts as $name => $gfamily): ?>
                    <option value="<?= htmlspecialchars($name) ?>" data-family="<?= htmlspecialchars($gfamily) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ctrl-row">
                <label>Fuente títulos</label>
                <select id="headFontSel" class="form-select form-select-sm" onchange="onFontChange()">
                    <?php foreach ($googleFonts as $name => $gfamily): ?>
                    <option value="<?= htmlspecialchars($name) ?>" data-family="<?= htmlspecialchars($gfamily) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Colores -->
        <div class="ctrl-section">
            <div class="ctrl-section-title">Colores</div>
            <?php
            $colorDefs = [
                '--primary'    => ['Primario',     '#0d6efd'],
                '--secondary'  => ['Secundario',   '#6c757d'],
                '--success'    => ['Éxito',         '#10b981'],
                '--danger'     => ['Peligro',       '#ef4444'],
                '--body-bg'    => ['Fondo página',  '#f9fafb'],
                '--body-fg'    => ['Texto página',  '#1f2937'],
                '--nav-grad-1' => ['Navbar color 1','#667eea'],
                '--nav-grad-2' => ['Navbar color 2','#764ba2'],
            ];
            foreach ($colorDefs as $varName => [$label, $def]): ?>
            <div class="ctrl-row">
                <label><?= $label ?></label>
                <input type="color" id="v<?= str_replace(['-','--'],['_',''],$varName) ?>"
                       data-var="<?= $varName ?>" value="<?= $def ?>"
                       oninput="onColorChange(this)" onchange="onColorChange(this)">
                <span class="val-label" id="lbl<?= str_replace(['-','--'],['_',''],$varName) ?>"><?= $def ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Forma y sombra -->
        <div class="ctrl-section">
            <div class="ctrl-section-title">Bordes y sombras</div>
            <div class="ctrl-row">
                <label>Radio tarjetas</label>
                <input type="range" id="cardRadius" min="0" max="32" value="16"
                       oninput="onRadiusChange(this)" class="form-range">
                <span class="val-label" id="lblRadius">16px</span>
            </div>
            <div class="ctrl-row">
                <label>Sombra tarjetas</label>
                <select id="cardShadow" class="form-select form-select-sm" onchange="onShadowChange()">
                    <option value="none">Sin sombra</option>
                    <option value="0 2px 6px rgba(0,0,0,0.06)">Suave</option>
                    <option value="0 4px 12px rgba(0,0,0,0.08)" selected>Normal</option>
                    <option value="0 8px 24px rgba(0,0,0,0.12)">Pronunciada</option>
                    <option value="0 16px 40px rgba(0,0,0,0.18)">Dramática</option>
                </select>
            </div>
        </div>

        <!-- CSS extra -->
        <div class="ctrl-section">
            <div class="ctrl-section-title">CSS adicional <span class="text-muted fw-normal">(avanzado)</span></div>
            <textarea id="extraCss" class="form-control form-control-sm font-monospace"
                      rows="6" placeholder="/* Reglas CSS extra */"
                      oninput="debouncePreview()" style="font-size:.72rem"></textarea>
        </div>

        <div class="ctrl-section">
            <button class="btn btn-primary w-100" onclick="saveSkin()">
                <i class="fas fa-save me-2"></i>Guardar skin personalizado
            </button>
        </div>
    </div>

    <!-- ══ PANEL DERECHO: preview ═══════════════════════════════════════════ -->
    <div id="previewPanel">
        <div id="previewToolbar">
            <span class="fw-bold text-muted">Vista previa</span>
            <div class="ms-auto d-flex gap-1">
                <button class="device-btn active" onclick="setDevice('100%','desktop',this)" title="Escritorio"><i class="fas fa-desktop"></i></button>
                <button class="device-btn" onclick="setDevice('768px','tablet',this)" title="Tablet"><i class="fas fa-tablet-alt"></i></button>
                <button class="device-btn" onclick="setDevice('390px','mobile',this)" title="Móvil"><i class="fas fa-mobile-alt"></i></button>
            </div>
            <span id="activeSkinLabel" class="badge bg-primary">Clásico Marino</span>
        </div>
        <div style="flex:1;overflow:auto;display:flex;align-items:flex-start;justify-content:center;background:#666;padding:12px">
            <div id="previewWrap" style="width:100%;max-height:100%;overflow:hidden;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.4);transition:width .3s">
                <div id="previewDoc" style="width:100%;background:#f9fafb;overflow:auto;max-height:calc(100vh - 145px)">
                    <!-- Contenido de preview generado por JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed;bottom:20px;right:20px;z-index:9999;display:none">
    <div id="toastInner" class="alert mb-0 shadow-lg fw-bold" style="min-width:260px"></div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// ── Estado del editor ────────────────────────────────────────────────────────
const SKINS_DATA = <?= json_encode($allSkins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const BUILTIN_IDS = <?= json_encode($builtinIds) ?>;
const GOOGLE_FONTS = <?= json_encode($googleFonts, JSON_UNESCAPED_UNICODE) ?>;

let currentSkinId  = 'clasico_marino';
let previewTimeout = null;
let loadedFonts    = new Set();

const colorVarMap = {
    '--primary':    'vprimary',
    '--secondary':  'vsecondary',
    '--success':    'vsuccess',
    '--danger':     'vdanger',
    '--body-bg':    'vbody_bg',
    '--body-fg':    'vbody_fg',
    '--nav-grad-1': 'vnav_grad_1',
    '--nav-grad-2': 'vnav_grad_2',
};

// ── Carga un skin en los controles ───────────────────────────────────────────
function loadSkin(id) {
    currentSkinId = id;
    const skin = SKINS_DATA[id];
    if (!skin) return;

    // Chips
    document.querySelectorAll('.skin-chip').forEach(c => c.classList.remove('active'));
    const chip = document.getElementById('chip-' + id);
    if (chip) { chip.classList.add('active'); chip.scrollIntoView({block:'nearest'}); }

    // IDs y nombre
    document.getElementById('skinId').value    = BUILTIN_IDS.includes(id) ? '' : id;
    document.getElementById('skinNombre').value = skin.nombre || '';
    document.getElementById('skinDesc').value   = skin.descripcion || '';

    // Colores
    const vars = skin.vars || {};
    for (const [varName, elId] of Object.entries(colorVarMap)) {
        const el  = document.getElementById('v' + elId.replace('v',''));
        const lbl = document.getElementById('lbl' + elId.replace('v',''));
        const val = vars[varName] || '';
        if (el && val.match(/^#[0-9a-fA-F]{6}$/)) {
            el.value = val;
            if (lbl) lbl.textContent = val;
        }
    }

    // Radio
    const r = parseInt((vars['--card-radius'] || '16px').replace('px','')) || 16;
    document.getElementById('cardRadius').value = r;
    document.getElementById('lblRadius').textContent = r + 'px';

    // Sombra
    const shadowSel = document.getElementById('cardShadow');
    const sv = vars['--card-shadow'] || '';
    let matched = false;
    for (const opt of shadowSel.options) { if (opt.value === sv) { shadowSel.value = sv; matched = true; break; } }
    if (!matched) shadowSel.selectedIndex = 2;

    // Fuentes
    matchFontSelect('bodyFontSel', skin.body_font || '');
    matchFontSelect('headFontSel', skin.heading_font || '');

    // Extra CSS
    document.getElementById('extraCss').value = (skin.extra_css || '').trim();

    // Label
    document.getElementById('activeSkinLabel').textContent = skin.nombre || id;

    renderPreview();
}

function matchFontSelect(selId, fontValue) {
    const sel = document.getElementById(selId);
    for (const opt of sel.options) {
        if (fontValue.includes(opt.value)) { sel.value = opt.value; return; }
    }
    sel.value = 'Inter';
}

// ── Eventos de controles ─────────────────────────────────────────────────────
function onColorChange(input) {
    const lbl = document.getElementById('lbl' + input.id.replace('v',''));
    if (lbl) lbl.textContent = input.value;
    debouncePreview();
}

function onRadiusChange(input) {
    document.getElementById('lblRadius').textContent = input.value + 'px';
    debouncePreview();
}

function onShadowChange() { debouncePreview(); }
function onFontChange()   { debouncePreview(); }

function debouncePreview() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(renderPreview, 80);
}

// ── Construye los vars actuales ───────────────────────────────────────────────
function buildCurrentVars() {
    const vars = {};
    for (const [varName, elId] of Object.entries(colorVarMap)) {
        vars[varName] = document.getElementById('v' + elId.replace('v','')).value;
    }
    vars['--card-radius'] = document.getElementById('cardRadius').value + 'px';
    vars['--card-shadow'] = document.getElementById('cardShadow').value;
    return vars;
}

function getFontCSS() {
    const bodyFont  = document.getElementById('bodyFontSel').value;
    const headFont  = document.getElementById('headFontSel').value;
    const bodyFam   = bodyFont === 'Sistema' ? "system-ui, sans-serif" : `'${bodyFont}', system-ui, sans-serif`;
    const headFam   = headFont === 'Sistema' ? "system-ui, sans-serif" : `'${headFont}', system-ui, sans-serif`;
    return { bodyFam, headFam, bodyFont, headFont };
}

// ── Renderiza la preview ──────────────────────────────────────────────────────
function renderPreview() {
    const vars    = buildCurrentVars();
    const { bodyFam, headFam, bodyFont, headFont } = getFontCSS();
    const extra   = document.getElementById('extraCss').value;

    // Cargar Google Fonts necesarias
    loadGoogleFontForPreview(bodyFont);
    loadGoogleFontForPreview(headFont);

    const varsCss = Object.entries(vars).map(([k,v]) => `${k}:${v}`).join(';');

    const html = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
${buildFontLink(bodyFont)}
${bodyFont !== headFont ? buildFontLink(headFont) : ''}
<style>
:root { ${varsCss}; --body-font: ${bodyFam}; --head-font: ${headFam}; }
*{box-sizing:border-box;margin:0;padding:0}
body { font-family: var(--body-font); background: var(--body-bg,#f9fafb); color: var(--body-fg,#1f2937); min-height:100vh; }
h1,h2,h3,h4,h5 { font-family: var(--head-font); }
.navbar { background: linear-gradient(135deg, var(--nav-grad-1,#667eea) 0%, var(--nav-grad-2,#764ba2) 100%); padding: 16px 24px; display:flex; align-items:center; justify-content:space-between; }
.navbar-brand { color:#fff; font-weight:700; font-size:1.3rem; font-family:var(--head-font); }
.navbar-icons { display:flex; gap:12px; color:#fff; opacity:.85; font-size:1.1rem; cursor:pointer; }
.search-bar { flex:1; margin:0 20px; }
.search-bar input { width:100%; padding:8px 14px; border:none; border-radius:999px; font-size:.85rem; outline:none; }
.hero { padding: 24px; background: var(--body-bg); border-bottom: 1px solid rgba(0,0,0,.06); }
.hero h2 { font-family: var(--head-font); font-size:1.5rem; font-weight:700; color: var(--body-fg); margin-bottom:4px; }
.hero p  { color: var(--secondary,#6c757d); font-size:.85rem; }
.section { padding: 20px 24px; }
.section-title { font-family:var(--head-font); font-size:1.1rem; font-weight:700; margin-bottom:14px; color:var(--body-fg); }
.grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:14px; }
.card { background:#fff; border-radius:var(--card-radius,16px); box-shadow:var(--card-shadow,0 4px 12px rgba(0,0,0,.06)); overflow:hidden; }
.card-img { height:130px; display:flex; align-items:center; justify-content:center; font-size:2.5rem; background: linear-gradient(135deg, var(--nav-grad-1,#667eea)22, var(--nav-grad-2,#764ba2)22); }
.card-body { padding:12px; }
.card-name { font-weight:600; font-size:.88rem; margin-bottom:2px; color:var(--body-fg); font-family:var(--head-font); }
.card-cat  { font-size:.72rem; color:var(--secondary,#6c757d); margin-bottom:8px; }
.card-price { font-weight:700; font-size:1.05rem; color:var(--primary,#0d6efd); }
.btn { display:inline-flex; align-items:center; justify-content:center; padding:7px 14px; border:none; border-radius:var(--card-radius,16px); cursor:pointer; font-weight:600; font-size:.82rem; transition:opacity .15s; }
.btn-primary { background:var(--primary,#0d6efd); color:#fff; width:100%; margin-top:8px; }
.btn-primary:hover { opacity:.85; }
.badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.68rem; font-weight:700; }
.badge-success { background:var(--success,#10b981); color:#fff; }
.badge-danger  { background:var(--danger,#ef4444);  color:#fff; }
.nav-cats { display:flex; gap:8px; padding:12px 24px; overflow-x:auto; border-bottom:1px solid rgba(0,0,0,.06); background:var(--body-bg); }
.cat-pill { padding:5px 14px; border-radius:999px; border:1px solid rgba(0,0,0,.12); font-size:.78rem; cursor:pointer; white-space:nowrap; color:var(--body-fg); }
.cat-pill.active { background:var(--primary,#0d6efd); color:#fff; border-color:transparent; }
${extra}
</style>
</head>
<body>
<div class="navbar">
  <div class="navbar-brand">🛍 Mi Tienda</div>
  <div class="search-bar"><input type="text" placeholder="Buscar productos..."></div>
  <div class="navbar-icons">
    <span>🛒</span><span>👤</span>
  </div>
</div>
<div class="nav-cats">
  <div class="cat-pill active">Todos</div>
  <div class="cat-pill">Bebidas</div>
  <div class="cat-pill">Panadería</div>
  <div class="cat-pill">Dulces</div>
  <div class="cat-pill">Snacks</div>
</div>
<div class="hero">
  <h2>Bienvenido a la tienda</h2>
  <p>Encuentra los mejores productos frescos y artesanales</p>
</div>
<div class="section">
  <div class="section-title">Productos destacados</div>
  <div class="grid">
    ${productCard('🍰','Cake de Chocolate','Pastelería','$350.00','badge-success','Disponible')}
    ${productCard('🥤','Refresco de Guayaba','Bebidas','$120.00','badge-success','Disponible')}
    ${productCard('🍞','Pan de Bono','Panadería','$85.00','badge-danger','Agotado')}
    ${productCard('🍫','Tableta de Cacao','Dulces','$210.00','badge-success','Disponible')}
  </div>
</div>
</body></html>`;

    document.getElementById('previewDoc').innerHTML = '';
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'width:100%;border:none;display:block;';
    iframe.style.height  = 'calc(100vh - 145px)';
    document.getElementById('previewDoc').appendChild(iframe);
    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open(); doc.write(html); doc.close();
}

function productCard(emoji, name, cat, price, badgeClass, badgeLabel) {
    return `<div class="card">
        <div class="card-img">${emoji}</div>
        <div class="card-body">
            <div class="card-name">${name}</div>
            <div class="card-cat">${cat} · <span class="badge ${badgeClass}">${badgeLabel}</span></div>
            <div class="card-price">${price}</div>
            <button class="btn btn-primary">Agregar al carrito</button>
        </div>
    </div>`;
}

function buildFontLink(fontName) {
    const fam = GOOGLE_FONTS[fontName];
    if (!fam) return '';
    return `<link href="https://fonts.googleapis.com/css2?family=${fam}&display=swap" rel="stylesheet">`;
}

function loadGoogleFontForPreview(fontName) {
    if (loadedFonts.has(fontName)) return;
    const fam = GOOGLE_FONTS[fontName];
    if (!fam) return;
    const link = document.createElement('link');
    link.rel  = 'stylesheet';
    link.href = `https://fonts.googleapis.com/css2?family=${fam}&display=swap`;
    document.head.appendChild(link);
    loadedFonts.add(fontName);
}

// ── Device switching ──────────────────────────────────────────────────────────
function setDevice(width, name, btn) {
    document.getElementById('previewWrap').style.width = width;
    document.querySelectorAll('.device-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── Guardar skin ─────────────────────────────────────────────────────────────
async function saveSkin() {
    const id = document.getElementById('skinId').value.trim().toLowerCase().replace(/[^a-z0-9_]/g,'');
    if (!id) { toast('Escribe un ID para el skin (slug, solo letras, números y _)', 'danger'); return; }
    if (BUILTIN_IDS.includes(id)) { toast('No puedes sobreescribir un skin predefinido. Usa otro ID.', 'danger'); return; }

    const vars = buildCurrentVars();
    const { bodyFam, headFam } = getFontCSS();
    const bodyFontName = document.getElementById('bodyFontSel').value;
    const headFontName = document.getElementById('headFontSel').value;

    const payload = {
        action:      'save',
        id,
        nombre:      document.getElementById('skinNombre').value,
        descripcion: document.getElementById('skinDesc').value,
        font_google: [GOOGLE_FONTS[bodyFontName], GOOGLE_FONTS[headFontName]].filter(Boolean).join('&family='),
        body_font:   bodyFam,
        heading_font: headFam,
        vars,
        preview: [vars['--primary'], vars['--nav-grad-1'], vars['--body-bg']],
        extra_css: document.getElementById('extraCss').value,
    };

    try {
        const res  = await fetch('shop_skin_editor.php', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify(payload) });
        const json = await res.json();
        if (json.status === 'success') {
            toast('Skin "' + id + '" guardado. Recarga la página para verlo en la lista.', 'success');
            setTimeout(() => location.reload(), 1800);
        } else {
            toast(json.msg || 'Error al guardar', 'danger');
        }
    } catch (e) { toast('Error de conexión', 'danger'); }
}

// ── Nuevo skin ────────────────────────────────────────────────────────────────
function newSkin() {
    document.getElementById('skinId').value = '';
    document.getElementById('skinNombre').value = 'Mi Skin';
    document.getElementById('skinDesc').value  = '';
    document.getElementById('extraCss').value  = '';
    document.querySelectorAll('.skin-chip').forEach(c => c.classList.remove('active'));
    renderPreview();
}

// ── Eliminar skin ─────────────────────────────────────────────────────────────
async function deleteSkin(id, nombre) {
    if (!confirm(`¿Eliminar el skin "${nombre}"? Esta acción no se puede deshacer.`)) return;
    try {
        const res  = await fetch('shop_skin_editor.php', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({action:'delete', id}) });
        const json = await res.json();
        if (json.status === 'success') { toast('Skin eliminado.', 'success'); setTimeout(() => location.reload(), 1200); }
        else toast(json.msg || 'Error', 'danger');
    } catch(e) { toast('Error de conexión','danger'); }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type='success') {
    const el = document.getElementById('toastInner');
    el.className = `alert alert-${type} mb-0 shadow-lg fw-bold`;
    el.textContent = msg;
    document.getElementById('toast').style.display = 'block';
    setTimeout(() => document.getElementById('toast').style.display='none', 3500);
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadSkin('clasico_marino');
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
