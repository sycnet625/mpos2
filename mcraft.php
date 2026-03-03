<?php
// =============================================================
// MarcelCraft — Editor de Mapas Isométrico | PalWeb POS Marinero
// =============================================================
$seed     = isset($_GET['seed'])  ? abs(intval($_GET['seed'])) % 999999 : 42;
$mapW     = isset($_GET['w'])     ? min(60, max(20, intval($_GET['w']))) : 40;
$mapH     = isset($_GET['h'])     ? min(60, max(20, intval($_GET['h']))) : 40;
$mapTitle = isset($_GET['title']) ? substr(strip_tags($_GET['title']), 0, 64) : '';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>MarcelCraft — Editor de Mapas</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; user-select:none; }
body { background:#0a0f1a; overflow:hidden; font-family:'Segoe UI', 'Courier New', monospace; color:#e0e0e0; }
canvas { display:block; cursor:grab; touch-action:none; }
canvas.drag { cursor:grabbing; }
canvas.edit { cursor:crosshair; }

/* ── HUD SUPERIOR ── */
#hud {
  position:fixed; top:16px; left:16px;
  background:rgba(8, 15, 28, 0.85);
  border-left:4px solid #4fc3f7;
  border-radius:0 12px 12px 0;
  padding:12px 20px;
  min-width:200px;
  backdrop-filter:blur(12px);
  box-shadow:0 10px 25px rgba(0,0,0,0.5);
  border-top:1px solid rgba(79, 195, 247, 0.3);
  border-bottom:1px solid rgba(79, 195, 247, 0.3);
  border-right:1px solid rgba(79, 195, 247, 0.3);
  z-index:40;
}
#hud h2 { 
  font-size:14px; 
  color:#4fc3f7; 
  margin-bottom:12px; 
  letter-spacing:2px; 
  text-transform:uppercase;
  display:flex;
  align-items:center;
  gap:6px;
}
#hud h2:before {
  content:'▸';
  color:#7fe87f;
  font-size:18px;
}
#hud .row { 
  font-size:12px; 
  color:#8a9bb5; 
  margin:6px 0;
  display:flex;
  justify-content:space-between;
  border-bottom:1px dotted rgba(79,195,247,0.2);
  padding-bottom:3px;
}
#hud .row span { 
  color:#fff; 
  font-weight:600;
  text-shadow:0 0 5px rgba(79,195,247,0.5);
}

/* ── BARRA INFERIOR ESTILO JUEGO ── */
#game-bar {
  position:fixed; bottom:0; left:0; right:0; height:100px;
  background:linear-gradient(to top, rgba(5,10,20,0.95), rgba(10,18,30,0.9));
  border-top:2px solid #4fc3f7;
  box-shadow:0 -5px 20px rgba(0,0,0,0.7);
  backdrop-filter:blur(15px);
  display:flex;
  align-items:center;
  padding:0 20px;
  gap:20px;
  z-index:100;
  font-family:'Segoe UI', monospace;
}

/* Panel Izquierdo - Minimapa */
#panel-left {
  flex:0 0 180px;
  background:rgba(0,5,15,0.6);
  border-radius:12px;
  padding:8px;
  border:1px solid #4fc3f7;
  box-shadow:inset 0 0 15px rgba(79,195,247,0.2);
}
#panel-left h4 {
  font-size:11px;
  color:#8a9bb5;
  margin-bottom:5px;
  text-transform:uppercase;
  letter-spacing:1px;
  display:flex;
  align-items:center;
  gap:5px;
}
#panel-left h4:before {
  content:'🗺️';
  font-size:12px;
}
#mmCanvas { 
  display:block; 
  width:100%;
  height:auto;
  image-rendering:pixelated; 
  border-radius:6px;
  cursor:crosshair;
  border:1px solid #2a3a50;
}

/* Panel Central - Información detallada */
#panel-center {
  flex:1;
  display:flex;
  flex-direction:column;
  gap:8px;
  min-width:0;
}
#tile-preview {
  display:flex;
  align-items:center;
  gap:15px;
  background:rgba(0,0,0,0.3);
  border-radius:30px;
  padding:5px 15px;
  border:1px solid #2a3a50;
}
#tile-icon {
  width:48px;
  height:48px;
  background:rgba(79,195,247,0.1);
  border-radius:12px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:28px;
  border:2px solid #4fc3f7;
  box-shadow:0 0 15px #4fc3f7;
}
#tile-stats {
  flex:1;
}
#tile-name {
  font-size:18px;
  font-weight:bold;
  color:#fff;
  text-shadow:0 0 8px #4fc3f7;
  letter-spacing:1px;
}
#tile-coord {
  font-size:12px;
  color:#8a9bb5;
  display:flex;
  gap:15px;
  margin-top:3px;
}
#tile-detail {
  display:flex;
  gap:15px;
  font-size:12px;
  color:#b0c4de;
  margin-top:5px;
}
.tile-badge {
  background:rgba(79,195,247,0.2);
  padding:3px 10px;
  border-radius:20px;
  border:1px solid #4fc3f7;
  font-weight:bold;
}
#footer-version {
  font-size:10px;
  color:#4a5a70;
  text-align:right;
  padding-right:10px;
}

/* Panel Derecho - Métricas */
#panel-right {
  flex:0 0 240px;
  display:flex;
  flex-direction:column;
  gap:8px;
}
.metric-row {
  display:flex;
  align-items:center;
  justify-content:space-between;
  background:rgba(0,0,0,0.3);
  border-radius:8px;
  padding:8px 15px;
  border:1px solid #2a3a50;
}
.metric-label {
  display:flex;
  align-items:center;
  gap:8px;
  color:#8a9bb5;
  font-size:12px;
  text-transform:uppercase;
}
.metric-label span { font-size:16px; }
.metric-value {
  font-size:18px;
  font-weight:bold;
  color:#7fe87f;
  text-shadow:0 0 8px #7fe87f;
}
#zoom-value:after { content:'%'; font-size:12px; margin-left:2px; color:#8a9bb5; }
#fps-value:after { content:' FPS'; font-size:12px; color:#8a9bb5; }
#time-value { font-size:14px; }

/* Botón menú */
#menu-btn {
  background:linear-gradient(135deg, #1a2a40, #0a1525);
  border:2px solid #4fc3f7;
  border-radius:30px;
  padding:10px 20px;
  color:#fff;
  font-size:14px;
  font-weight:bold;
  cursor:pointer;
  letter-spacing:1px;
  transition:all 0.2s;
  box-shadow:0 0 15px rgba(79,195,247,0.3);
  display:flex;
  align-items:center;
  gap:8px;
  white-space:nowrap;
}
#menu-btn:hover { 
  transform:scale(1.02);
  box-shadow:0 0 25px #4fc3f7;
}
#menu-btn.open { 
  border-color:#7fe87f;
  box-shadow:0 0 25px #7fe87f;
}

/* Menú desplegable */
#bottom-menu {
  position:absolute; bottom:110px; right:20px;
  width:320px; max-height:70vh;
  background:rgba(8,15,28,0.98);
  border:2px solid #4fc3f7;
  border-radius:16px;
  padding:18px;
  backdrop-filter:blur(20px);
  display:none;
  flex-direction:column;
  gap:16px;
  z-index:200;
  box-shadow:0 10px 40px rgba(0,0,0,0.8);
}
#bottom-menu.open { display:flex; }

.bm-section {
  border-left:3px solid #4fc3f7;
  padding-left:12px;
}
.bm-section-title {
  font-size:12px;
  color:#7fe87f;
  letter-spacing:2px;
  text-transform:uppercase;
  margin-bottom:10px;
  font-weight:bold;
}
.bm-ctrl {
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:8px;
}
.bm-ctrl-item {
  background:rgba(255,255,255,0.05);
  border-radius:6px;
  padding:5px 8px;
  font-size:11px;
  color:#b0c4de;
}
.bm-ctrl-item .k {
  background:#2a3a50;
  color:#7fe87f;
  padding:2px 6px;
  border-radius:4px;
  margin-right:5px;
}

/* Editor toggle */
#editor-toggle {
  position:fixed; top:16px; right:16px;
  background:linear-gradient(135deg, #1a2a40, #0a1525);
  border:2px solid #ffaa00;
  border-radius:30px;
  padding:10px 22px;
  color:#ffaa00;
  font-size:14px;
  font-weight:bold;
  cursor:pointer;
  backdrop-filter:blur(10px);
  z-index:150;
  letter-spacing:1px;
  transition:all 0.2s;
  box-shadow:0 0 15px rgba(255,170,0,0.3);
}
#editor-toggle.active { 
  border-color:#7fe87f; 
  color:#7fe87f;
  box-shadow:0 0 25px #7fe87f;
}

/* Panel editor */
#editor-panel {
  position:fixed; top:80px; right:16px; left:auto;
  width:280px;
  background:rgba(8,15,28,0.98);
  border:2px solid #ffaa00;
  border-radius:16px;
  padding:18px;
  backdrop-filter:blur(20px);
  display:none;
  flex-direction:column;
  gap:15px;
  z-index:140;
  box-shadow:0 10px 40px rgba(0,0,0,0.8);
}
#editor-panel.visible { display:flex; }

.ed-tool-btn, .ed-action-btn {
  background:rgba(255,255,255,0.08);
  border:1px solid rgba(80,160,255,0.2);
  border-radius:6px;
  padding:8px 10px;
  color:#8ab;
  font-size:11px;
  cursor:pointer;
  font-family:'Courier New',monospace;
  transition:all 0.15s;
}
.ed-tool-btn:hover, .ed-action-btn:hover { background:rgba(255,255,255,0.14); }
.ed-tool-btn.active { background:rgba(80,160,255,0.25); border-color:rgba(80,160,255,0.6); color:#fff; }
.ed-action-btn:disabled { opacity:.35; cursor:default; }

.ed-map-title {
  font-size:11px; color:#cde; background:rgba(255,255,255,0.07);
  border:1px solid rgba(80,160,255,0.2); border-radius:5px;
  padding:8px; width:100%; font-family:'Courier New',monospace; outline:none;
}
.ed-map-title:focus { border-color:rgba(80,160,255,0.5); }

/* D-Pad táctil */
#dpad {
  position:fixed; bottom:120px; right:20px; z-index:50;
  display:none;
  flex-direction:column;
  align-items:center;
  gap:5px;
}
@media (hover: none) and (pointer: coarse) { #dpad { display:flex; } }
.dp-row { display:flex; gap:8px; }
.dp-btn {
  width:60px; height:60px;
  background:linear-gradient(135deg, #1e2f45, #0e1a2a);
  border:2px solid #4fc3f7;
  border-radius:16px;
  color:#ddeeff;
  font-size:24px;
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  box-shadow:0 5px 0 #0a1525;
  transition:all 0.05s;
}
.dp-btn:active {
  transform:translateY(4px);
  box-shadow:0 1px 0 #0a1525;
}
.dp-mid {
  width:60px; height:60px;
  background:rgba(79,195,247,0.2);
  border:2px solid #4fc3f7;
  border-radius:16px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:10px;
  color:#4fc3f7;
  font-weight:bold;
  text-transform:uppercase;
}

/* Toast */
#toast-container {
  position:fixed; bottom:120px; left:50%; transform:translateX(-50%);
  z-index:1000;
  pointer-events:none;
}
.toast {
  background:rgba(8,15,28,0.95);
  border-left:4px solid #7fe87f;
  border-radius:8px;
  padding:12px 25px;
  color:#fff;
  font-size:14px;
  font-weight:bold;
  box-shadow:0 10px 30px rgba(0,0,0,0.8);
  backdrop-filter:blur(10px);
  animation:toastIn 0.3s;
}
@keyframes toastIn {
  from { opacity:0; transform:translateY(20px); }
  to { opacity:1; transform:translateY(0); }
}

/* Modal */
#load-modal {
  position:fixed; inset:0; background:rgba(0,0,0,0.9); backdrop-filter:blur(8px);
  display:none; align-items:center; justify-content:center; z-index:300;
}
.load-modal-card {
  background:#0a1528; border:2px solid #4fc3f7; border-radius:20px;
  padding:25px; width:min(500px,90vw);
}
</style>
</head>
<body>
<canvas id="c"></canvas>

<!-- HUD Superior -->
<div id="hud">
  <h2>⚡ MARCELCRAFT</h2>
  <div class="row">Semilla <span id="h-seed"><?= $seed ?></span></div>
  <div class="row">Tamaño <span id="h-size"><?= $mapW ?>×<?= $mapH ?></span></div>
  <div class="row">Tile <span id="h-tile">—</span></div>
</div>

<!-- Botón Editor -->
<button id="editor-toggle" onclick="toggleEditor()">⚙ EDITOR</button>

<!-- Panel Editor -->
<div id="editor-panel">
  <div class="bm-section-title" style="color:#ffaa00; border-left-color:#ffaa00;">✏ EDITOR</div>
  
  <input class="ed-map-title" id="ed-title" type="text" placeholder="Título del mapa" maxlength="64">

  <div class="bm-section-title" style="font-size:11px;">HERRAMIENTA</div>
  <div class="ed-tool-row" style="display:grid; grid-template-columns:repeat(2,1fr); gap:5px;">
    <button class="ed-tool-btn active" id="et-paint"  onclick="setTool('paint')">🖌 Pintar</button>
    <button class="ed-tool-btn"        id="et-height" onclick="setTool('height')">↕ Altura</button>
    <button class="ed-tool-btn"        id="et-deco"   onclick="setTool('deco')">🌿 Deco</button>
    <button class="ed-tool-btn"        id="et-erase"  onclick="setTool('erase')">✗ Borrar</button>
  </div>

  <div id="ed-height-dir" style="display:none; background:#1a2a40; padding:8px; border-radius:8px;">
    <label style="color:#7fe87f; margin-right:15px;"><input type="radio" name="hdir" value="1" checked onchange="heightDirection=+1"> ▲ Subir</label>
    <label style="color:#ff6b6b;"><input type="radio" name="hdir" value="-1" onchange="heightDirection=-1"> ▼ Bajar</label>
  </div>

  <div class="bm-section-title" style="font-size:11px;">TERRENO</div>
  <div class="ed-swatch-row" id="ed-swatches" style="display:grid; grid-template-columns:repeat(4,1fr); gap:5px;"></div>

  <div class="bm-section-title" style="font-size:11px;">HISTORIAL</div>
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:5px;">
    <button class="ed-action-btn" id="ed-undo" onclick="undo()" disabled>↩ Deshacer</button>
    <button class="ed-action-btn" id="ed-redo" onclick="redo()" disabled>↪ Rehacer</button>
  </div>

  <div class="bm-section-title" style="font-size:11px;">MAPAS</div>
  <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:5px;">
    <button class="ed-action-btn" id="ed-save" onclick="saveMap()">💾 Guardar</button>
    <button class="ed-action-btn" onclick="openLoadModal()">📂 Cargar</button>
    <button class="ed-action-btn" onclick="exportMap()">⬇ Export</button>
    <button class="ed-action-btn" onclick="importMap()">⬆ Import</button>
    <button class="ed-action-btn" onclick="newMap()" style="grid-column:span 2;">+ Nuevo Mapa</button>
  </div>
</div>

<!-- Modal de carga -->
<div id="load-modal">
  <div class="load-modal-card">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
      <span style="color:#4fc3f7; font-size:18px; font-weight:bold;">📂 MIS MAPAS</span>
      <button onclick="closeLoadModal()" style="background:none; border:none; color:#8ab; font-size:20px; cursor:pointer;">✕</button>
    </div>
    <div id="load-modal-body" style="max-height:60vh; overflow-y:auto; display:flex; flex-direction:column; gap:10px;"></div>
  </div>
</div>

<!-- D-Pad Táctil -->
<div id="dpad">
  <div class="dp-row"><button class="dp-btn" id="dp-u">▲</button></div>
  <div class="dp-row">
    <button class="dp-btn" id="dp-l">◀</button>
    <div class="dp-mid">MOVER</div>
    <button class="dp-btn" id="dp-r">▶</button>
  </div>
  <div class="dp-row"><button class="dp-btn" id="dp-d">▼</button></div>
</div>

<!-- BARRA INFERIOR ESTILO JUEGO -->
<div id="game-bar">
  <!-- Panel Izquierdo: Minimapa -->
  <div id="panel-left">
    <h4>MINIMAPA</h4>
    <canvas id="mmCanvas"></canvas>
  </div>

  <!-- Panel Central: Info del Tile -->
  <div id="panel-center">
    <div id="tile-preview">
      <div id="tile-icon">🌍</div>
      <div id="tile-stats">
        <div id="tile-name">Selecciona un tile</div>
        <div id="tile-coord">
          <span>X: <span id="tile-x">-</span></span>
          <span>Y: <span id="tile-y">-</span></span>
        </div>
        <div id="tile-detail">
          <span class="tile-badge" id="tile-elev">-</span>
          <span class="tile-badge" id="tile-deco">-</span>
        </div>
      </div>
    </div>
    <div id="footer-version">PALWEB POS v3.0 • MARCEL ENGINE</div>
  </div>

  <!-- Panel Derecho: Métricas -->
  <div id="panel-right">
    <div class="metric-row">
      <div class="metric-label"><span>🎯</span> ZOOM</div>
      <div class="metric-value" id="zoom-value">100</div>
    </div>
    <div class="metric-row">
      <div class="metric-label"><span>⚡</span> FPS</div>
      <div class="metric-value" id="fps-value">60</div>
    </div>
    <div class="metric-row">
      <div class="metric-label"><span>🌓</span> HORA</div>
      <div class="metric-value" id="time-value">Día</div>
    </div>
    <button id="menu-btn" onclick="toggleBottomMenu()">
      <span>⚙</span> OPCIONES <span>▼</span>
    </button>
  </div>
</div>

<!-- Menú Inferior -->
<div id="bottom-menu">
  <div class="bm-section">
    <div class="bm-section-title">🎮 CONTROLES</div>
    <div class="bm-ctrl">
      <div class="bm-ctrl-item"><span class="k">WASD</span> Mover</div>
      <div class="bm-ctrl-item"><span class="k">R</span> Regenerar</div>
      <div class="bm-ctrl-item"><span class="k">Ctrl+Z</span> Deshacer</div>
      <div class="bm-ctrl-item"><span class="k">Ctrl+Y</span> Rehacer</div>
      <div class="bm-ctrl-item"><span class="k">Arrastrar</span> Pan</div>
      <div class="bm-ctrl-item"><span class="k">Rueda</span> Zoom</div>
    </div>
  </div>

  <div class="bm-section">
    <div class="bm-section-title">🌍 TERRENOS</div>
    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:5px;">
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#0e2244; border-radius:2px;"></div> Agua Prof.</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#1a4a8a; border-radius:2px;"></div> Agua</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#c8a96e; border-radius:2px;"></div> Arena</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#5a8a3c; border-radius:2px;"></div> Césped</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#1a5018; border-radius:2px;"></div> Jungla</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#6a5a4a; border-radius:2px;"></div> Roca</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#9a9080; border-radius:2px;"></div> Montaña</div>
      <div style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#dce8f8; border-radius:2px;"></div> Nieve</div>
    </div>
  </div>

  <div class="bm-section">
    <div class="bm-section-title">⚙ AJUSTES</div>
    <div style="display:flex; flex-direction:column; gap:8px;">
      <div style="display:flex; align-items:center; justify-content:space-between;">
        <span>💧 Agua animada</span>
        <button class="toggle-btn on" id="opt-water" onclick="toggleWaterAnim()">ON</button>
      </div>
      <div style="display:flex; align-items:center; justify-content:space-between;">
        <span>🌓 Ciclo día/noche</span>
        <button class="toggle-btn on" id="opt-day" onclick="toggleDayNight()">ON</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<!-- Variables PHP para JavaScript -->
<script>
// Variables globales desde PHP
const PHP_SEED = <?= $seed ?>;
const PHP_MW = <?= $mapW ?>;
const PHP_MH = <?= $mapH ?>;
const PHP_TITLE = '<?= addslashes($mapTitle) ?>';
</script>

<!-- Carga de módulos JavaScript en el orden correcto -->
<script src="js/constants.js"></script>
<script src="js/core.js"></script>
<script src="js/renderer.js"></script>
<script src="js/editor.js"></script>
</body>
</html>
