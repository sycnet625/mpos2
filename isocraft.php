<?php
// =============================================================
// IsoCraft — Editor de Mapas Isométrico | PalWeb POS Marinero
// =============================================================
require_once 'config_loader.php';
$seed     = isset($_GET['seed'])  ? abs(intval($_GET['seed'])) % 999999 : 42;
$mapW     = isset($_GET['w'])     ? min(60, max(20, intval($_GET['w']))) : 40;
$mapH     = isset($_GET['h'])     ? min(60, max(20, intval($_GET['h']))) : 40;
$mapTitle = isset($_GET['title']) ? substr(strip_tags($_GET['title']), 0, 64) : '';

// ── Tilemap PNG: lee isocraft_tilemap.json y escanea assets ──
$tilemapFile = __DIR__ . '/isocraft_tilemap.json';
$tilemapData = file_exists($tilemapFile)
    ? json_decode(file_get_contents($tilemapFile), true)
    : ['tiles' => [], 'sprites' => []];
if (!isset($tilemapData['tiles']))   $tilemapData['tiles']   = [];
if (!isset($tilemapData['sprites'])) $tilemapData['sprites'] = [];

$tileFiles = [];
foreach (glob(__DIR__ . '/assets/tiles/*.png') as $f) {
    $name = pathinfo($f, PATHINFO_FILENAME);
    if (!isset($tilemapData['tiles'][$name]))
        $tilemapData['tiles'][$name] = ['terrain' => null, 'label' => ''];
    $tileFiles[] = $name;
}
sort($tileFiles);

$spriteFiles = [];
foreach (['enemies','fauna','misc','resources','structures','units'] as $cat) {
    foreach (glob(__DIR__ . "/assets/sprites/{$cat}/*.png") as $f) {
        $name = $cat . '/' . pathinfo($f, PATHINFO_FILENAME);
        if (!isset($tilemapData['sprites'][$name]))
            $tilemapData['sprites'][$name] = ['label' => '', 'as_deco' => true];
        $spriteFiles[] = $name;
    }
}
sort($spriteFiles);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IsoCraft — Editor de Mapas</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { background:#0a0f1a; overflow:hidden; font-family:'Courier New',monospace; color:#e0e0e0; }
canvas { display:block; cursor:grab; }
canvas.drag { cursor:grabbing; }
canvas.edit { cursor:crosshair; }

#hud {
  position:fixed; top:14px; left:14px;
  background:rgba(5,10,20,.82); border:1px solid rgba(80,160,255,.25);
  border-radius:10px; padding:14px 18px; min-width:210px;
  backdrop-filter:blur(10px);
}
#hud h2 { font-size:13px; color:#4fc3f7; margin-bottom:10px; letter-spacing:2px; text-transform:uppercase; }
#hud .row { font-size:11px; color:#8ab; margin:4px 0; }
#hud .row span { color:#fff; }

:root { --bar-h: 90px; }

.k {
  display:inline-block; background:rgba(255,255,255,.12);
  border-radius:4px; padding:1px 7px; color:#fff; font-size:10px;
}

/* ── BARRA INFERIOR ── */
#bottom-bar {
  position:fixed; bottom:0; left:0; right:0; height:var(--bar-h);
  background:rgba(5,10,20,.92); border-top:1px solid rgba(80,160,255,.25);
  backdrop-filter:blur(10px); display:flex; align-items:center;
  padding:6px 14px; gap:14px; z-index:50;
}

#bar-left {
  display:flex; flex-direction:column; align-items:center; gap:3px;
  flex-shrink:0;
}
#bar-left h4 { font-size:10px; color:#4fc3f7; letter-spacing:2px; margin-bottom:2px;
  text-transform:uppercase; text-align:center; }
#mmCanvas { display:block; border-radius:3px; image-rendering:pixelated; cursor:crosshair; }

#bar-mid {
  flex:1; display:flex; flex-direction:column; align-items:center;
  justify-content:center; gap:3px; min-width:0;
}
#tinfo {
  font-size:12px; color:#e0e0e0; white-space:nowrap; overflow:hidden;
  text-overflow:ellipsis; max-width:100%; text-align:center;
  pointer-events:none;
}
#footer-inline {
  font-size:9px; color:rgba(255,255,255,.2); letter-spacing:1px;
  pointer-events:none;
}

#bar-right { position:relative; flex-shrink:0; }

#menu-btn {
  background:rgba(5,10,20,.82); border:1px solid rgba(80,160,255,.25);
  border-radius:10px; padding:8px 14px; color:#8ab; font-size:12px;
  cursor:pointer; font-family:'Courier New',monospace; letter-spacing:1px;
  white-space:nowrap; transition:border-color .2s, color .2s;
  backdrop-filter:blur(10px);
}
#menu-btn:hover  { border-color:rgba(80,160,255,.5); color:#cde; }
#menu-btn.open   { border-color:rgba(80,255,120,.4); color:#7fe87f; }

#bottom-menu {
  position:absolute; bottom:calc(100% + 8px); right:0;
  width:300px; max-height:70vh; overflow-y:auto;
  background:rgba(5,10,20,.97); border:1px solid rgba(80,160,255,.3);
  border-radius:10px; padding:14px 16px;
  backdrop-filter:blur(12px); display:none;
  flex-direction:column; gap:14px; z-index:200;
}
#bottom-menu.open { display:flex; }

.bm-section-title {
  font-size:10px; color:#4fc3f7; letter-spacing:2px;
  text-transform:uppercase; margin-bottom:6px;
  border-bottom:1px solid rgba(80,160,255,.15); padding-bottom:4px;
}
.bm-ctrl { font-size:11px; color:#8ab; line-height:2; }
.bm-li { display:flex; align-items:center; gap:8px; margin:4px 0; color:#ccc; font-size:11px; }
.bm-lc { width:18px; height:9px; border-radius:2px; flex-shrink:0; }
.bm-option {
  display:flex; align-items:center; justify-content:space-between;
  font-size:11px; color:#8ab; padding:4px 0;
}
.toggle-btn {
  background:rgba(255,255,255,.08); border:1px solid rgba(80,160,255,.2);
  border-radius:20px; padding:3px 12px; color:#8ab; font-size:10px;
  cursor:pointer; font-family:'Courier New',monospace;
  transition:background .15s, border-color .15s, color .15s; min-width:52px; text-align:center;
}
.toggle-btn.on { background:rgba(80,255,120,.18); border-color:rgba(80,255,120,.45); color:#7fe87f; }
.toggle-btn:hover { background:rgba(255,255,255,.14); }

/* ── MÓVIL / TÁCTIL ── */
@media (hover: none) and (pointer: coarse) {
  #hud   { min-width: 150px; padding: 10px 14px; font-size: 10px; }
}
#dpad {
  position:fixed; bottom:100px; right:14px; z-index:20;
  display:none; flex-direction:column; align-items:center; gap:5px;
}
@media (hover: none) and (pointer: coarse) { #dpad { display:flex; } }
.dp-row { display:flex; gap:5px; }
.dp-btn {
  width:50px; height:50px;
  background:rgba(5,15,40,.72); border:1.5px solid rgba(79,195,247,.38);
  border-radius:10px; color:#ddeeff; font-size:22px;
  display:flex; align-items:center; justify-content:center;
  cursor:pointer; user-select:none; touch-action:none;
  backdrop-filter:blur(8px); -webkit-tap-highlight-color:transparent;
}
.dp-btn:active { background:rgba(79,195,247,.30); border-color:rgba(79,195,247,.7); }
.dp-mid {
  width:50px; height:50px;
  background:rgba(5,15,40,.55); border:1px solid rgba(79,195,247,.18);
  border-radius:10px; display:flex; align-items:center; justify-content:center;
  font-size:9px; color:#4fc3f7; letter-spacing:1px; text-transform:uppercase;
}

/* ── EDITOR ── */
#editor-toggle {
  position:fixed; top:14px; right:14px;
  background:rgba(5,10,20,.82); border:1px solid rgba(80,160,255,.25);
  border-radius:20px; padding:7px 18px; color:#8ab; font-size:12px;
  cursor:pointer; backdrop-filter:blur(10px); z-index:100;
  font-family:'Courier New',monospace; letter-spacing:1px;
  transition:border-color .2s, color .2s; white-space:nowrap;
}
#editor-toggle.active { border-color:rgba(80,255,120,.5); color:#7fe87f; }
#editor-toggle:hover  { border-color:rgba(80,160,255,.5); color:#cde; }

/* PC: panel lateral derecho */
#editor-panel {
  position:fixed; top:0; right:0; bottom:var(--bar-h); z-index:90;
  width:300px;
  background:rgba(5,10,20,.97); border-left:1px solid rgba(80,160,255,.25);
  backdrop-filter:blur(14px); display:none; flex-direction:column;
  overflow:hidden;
}
#editor-panel.visible { display:flex; }

/* Header fijo del panel */
#ed-panel-header {
  display:flex; align-items:center; gap:8px; flex-shrink:0;
  padding:12px 14px 10px; border-bottom:1px solid rgba(80,160,255,.15);
}
#ed-panel-header h4 {
  font-size:11px; color:#4fc3f7; letter-spacing:2px;
  text-transform:uppercase; flex:1; margin:0;
}
#ed-close-panel {
  background:none; border:none; color:#8ab; cursor:pointer;
  font-size:16px; padding:0 2px; line-height:1;
  font-family:'Courier New',monospace; transition:color .15s;
}
#ed-close-panel:hover { color:#fff; }

/* Cuerpo scrollable */
#ed-panel-body {
  flex:1; overflow-y:auto; padding:12px 14px;
  display:flex; flex-direction:column; gap:10px;
}
.ep-label { font-size:10px; color:#4fc3f7; letter-spacing:1px; text-transform:uppercase; margin-bottom:3px; }
.ed-tool-row { display:flex; gap:5px; flex-wrap:wrap; }
.ed-tool-btn {
  background:rgba(255,255,255,.08); border:1px solid rgba(80,160,255,.2);
  border-radius:6px; padding:6px 9px; color:#8ab; font-size:11px;
  cursor:pointer; font-family:'Courier New',monospace;
  transition:background .15s, border-color .15s;
}
.ed-tool-btn:hover  { background:rgba(255,255,255,.14); }
.ed-tool-btn.active { background:rgba(80,160,255,.25); border-color:rgba(80,160,255,.6); color:#fff; }
.ed-sep { border:none; border-top:1px solid rgba(80,160,255,.12); margin:0; }

/* ── TERRAIN SCULPT TOOL ── */
#ed-terrain-sculpt { margin-top:6px; }
.tsculpt-row { display:flex; align-items:center; gap:6px; margin-bottom:5px; }
.tsculpt-label { font-size:10px; color:#8ab; min-width:54px; }
.tsculpt-val   { font-size:11px; color:#cde; min-width:18px; text-align:right; }
.tsculpt-slider {
  -webkit-appearance:none; appearance:none; flex:1;
  height:4px; border-radius:3px; background:rgba(80,160,255,.25);
  outline:none; cursor:pointer;
}
.tsculpt-slider::-webkit-slider-thumb {
  -webkit-appearance:none; width:14px; height:14px; border-radius:50%;
  background:#4fc3f7; cursor:pointer; border:2px solid rgba(0,0,0,.4);
}
.tsculpt-mode-row { display:flex; gap:5px; flex-wrap:wrap; margin-bottom:5px; }
.tsculpt-mode-btn {
  background:rgba(255,255,255,.07); border:1px solid rgba(80,160,255,.2);
  border-radius:5px; padding:4px 8px; font-size:10px; color:#8ab;
  cursor:pointer; font-family:'Courier New',monospace; transition:background .15s;
}
.tsculpt-mode-btn.active { background:rgba(80,160,255,.28); border-color:rgba(80,160,255,.6); color:#fff; }
.tsculpt-toggle {
  display:flex; align-items:center; gap:6px; font-size:10px; color:#8ab; cursor:pointer;
}
.ed-action-row { display:flex; gap:5px; flex-wrap:wrap; }
.ed-action-btn {
  background:rgba(255,255,255,.07); border:1px solid rgba(80,160,255,.18);
  border-radius:6px; padding:5px 8px; color:#8ab; font-size:11px;
  cursor:pointer; font-family:'Courier New',monospace; transition:background .15s;
}
.ed-action-btn:hover { background:rgba(255,255,255,.14); }
.ed-action-btn:disabled { opacity:.35; cursor:default; pointer-events:none; }
.ed-map-title {
  font-size:11px; color:#cde; background:rgba(255,255,255,.07);
  border:1px solid rgba(80,160,255,.2); border-radius:5px;
  padding:4px 7px; width:100%; font-family:'Courier New',monospace; outline:none;
}
.ed-map-title:focus { border-color:rgba(80,160,255,.5); }

/* ── SELECTOR DE TERRENO (nuevo) ── */
.ed-terrain-grid {
  display:grid; grid-template-columns:repeat(4,1fr); gap:5px;
}
.ed-terrain-btn {
  background:rgba(255,255,255,.05); border:2px solid rgba(80,160,255,.12);
  border-radius:8px; padding:7px 4px 5px; cursor:pointer;
  display:flex; flex-direction:column; align-items:center; gap:3px;
  transition:border-color .15s, background .15s;
}
.ed-terrain-btn:hover { background:rgba(255,255,255,.10); border-color:rgba(80,160,255,.35); }
.ed-terrain-btn.active { border-color:#ffd700; background:rgba(255,215,0,.10); }
.ed-terrain-btn .tb-dot { width:26px; height:13px; border-radius:3px; flex-shrink:0; }
.ed-terrain-btn .tb-emoji { font-size:15px; line-height:1; }
.ed-terrain-btn .tb-name {
  font-size:8px; color:#8ab; letter-spacing:.4px;
  text-transform:uppercase; text-align:center; line-height:1.2;
}
.ed-terrain-btn.active .tb-name { color:#ffd700; }

/* ── GRID VARIANTES DE TILE PNG ── */
#ed-tile-variants {
  display:grid; grid-template-columns:repeat(4,1fr); gap:4px;
  max-height:195px; overflow-y:auto; padding:2px;
}
.ed-tile-cell {
  position:relative; cursor:pointer;
  border:2px solid rgba(80,160,255,.12);
  border-radius:4px; overflow:hidden; transition:border-color .12s;
  background:rgba(255,255,255,.04); aspect-ratio:2/1;
}
.ed-tile-cell img {
  width:100%; height:100%; object-fit:cover;
  image-rendering:pixelated; display:block;
}
.ed-tile-cell:hover  { border-color:rgba(255,255,255,.45); }
.ed-tile-cell.active { border-color:#ffd700; background:rgba(255,215,0,.06); }
.ed-tile-cell .tc-lock {
  position:absolute; top:1px; right:2px;
  font-size:9px; opacity:.0; transition:opacity .1s;
}
.ed-tile-cell.active .tc-lock { opacity:1; }
.ed-tile-rnd {
  grid-column:span 4;
  background:rgba(255,255,255,.05); border:2px dashed rgba(80,160,255,.2);
  border-radius:4px; padding:5px; color:#6a8a9a; font-size:10px;
  text-align:center; cursor:pointer; transition:border-color .15s, color .15s;
  font-family:'Courier New',monospace; letter-spacing:.5px;
}
.ed-tile-rnd:hover  { border-color:rgba(80,160,255,.45); color:#9ab; }
.ed-tile-rnd.active { border-color:rgba(80,255,120,.45); color:#7fe87f; background:rgba(80,255,120,.07); }

#load-modal {
  position:fixed; inset:0; background:rgba(0,0,0,.75);
  display:none; align-items:center; justify-content:center; z-index:200;
  backdrop-filter:blur(4px);
}
#load-modal.visible { display:flex; }
.load-modal-card {
  background:rgba(5,10,25,.96); border:1px solid rgba(80,160,255,.3);
  border-radius:14px; padding:20px; width:min(480px,92vw);
  max-height:80vh; display:flex; flex-direction:column; gap:12px;
}
.load-modal-card header {
  display:flex; align-items:center; justify-content:space-between;
  font-size:13px; color:#4fc3f7; letter-spacing:2px; text-transform:uppercase;
}
.load-modal-card header button {
  background:none; border:none; color:#8ab; cursor:pointer; font-size:16px; padding:0 4px;
}
.load-modal-card header button:hover { color:#fff; }
#load-modal-body { overflow-y:auto; display:flex; flex-direction:column; gap:8px; }
.map-card {
  background:rgba(255,255,255,.05); border:1px solid rgba(80,160,255,.15);
  border-radius:8px; padding:10px 12px; display:flex; align-items:center; gap:10px;
}
.map-card-info { flex:1; min-width:0; }
.map-card-title { font-size:12px; color:#cde; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.map-card-meta  { font-size:10px; color:#678; margin-top:2px; }
.map-card-btns  { display:flex; gap:5px; flex-shrink:0; }
.map-card-btns button {
  background:rgba(255,255,255,.08); border:1px solid rgba(80,160,255,.2);
  border-radius:5px; padding:4px 8px; color:#8ab; font-size:10px;
  cursor:pointer; font-family:'Courier New',monospace;
}
.map-card-btns button:hover { background:rgba(255,255,255,.15); color:#fff; }
.map-card-btns button.del:hover { background:rgba(255,80,80,.2); border-color:rgba(255,80,80,.4); color:#faa; }
.map-empty { font-size:12px; color:#567; text-align:center; padding:20px; }

/* Móvil: barra compacta abajo */
@media (hover:none) and (pointer:coarse), (max-width:767px) {
  #editor-toggle {
    top:auto; bottom:calc(var(--bar-h) + 10px); right:14px;
    font-size:11px; padding:6px 14px;
  }
  #editor-panel {
    top:auto; right:0; left:0; bottom:var(--bar-h);
    width:100%; height:auto; max-height:58vh;
    border-left:none; border-top:1px solid rgba(80,160,255,.25);
    border-radius:0;
  }
  #ed-panel-body { padding:8px 12px; gap:8px; }
  .ed-terrain-grid { grid-template-columns:repeat(8,1fr); gap:4px; }
  .ed-terrain-btn { padding:5px 2px 4px; }
  .ed-terrain-btn .tb-name { display:none; }
  .ed-terrain-btn .tb-dot { width:20px; height:10px; }
  #ed-tile-variants { grid-template-columns:repeat(6,1fr); max-height:120px; }
  .ed-tile-rnd { grid-column:span 6; }
  .ed-tool-btn, .ed-action-btn { padding:8px 10px; font-size:12px; }
}

/* ── RESPONSIVE MEJORADO ── */
@media (max-width:480px) {
  #hud { min-width:120px; padding:8px 10px; }
  #hud h2 { font-size:11px; margin-bottom:6px; }
  #hud .row { font-size:10px; margin:2px 0; }
}
@media (max-width:400px) {
  :root { --bar-h:60px; }
  #bar-left h4 { display:none; }
  #mmCanvas { max-height:42px; }
  #bottom-bar { gap:8px; padding:4px 8px; }
  #tinfo { font-size:11px; }
}
@media (max-height:480px) and (orientation:landscape) {
  :root { --bar-h:54px; }
  #hud { top:6px; left:6px; max-height:calc(100vh - 68px); overflow-y:auto; }
  #hud h2 { font-size:10px; margin-bottom:4px; }
  #hud .row { font-size:9px; margin:1px 0; }
  #dpad { bottom:60px; right:10px; }
  .dp-btn { width:42px; height:42px; font-size:18px; }
  .dp-mid { width:42px; height:42px; font-size:8px; }
  #editor-toggle { padding:5px 12px; font-size:10px; }
  #editor-panel  { bottom:calc(var(--bar-h) + 8px); max-height:calc(100vh - 120px); }
}
@media (max-width:360px) and (hover:none) {
  .dp-btn { width:44px; height:44px; }
  .dp-mid { width:44px; height:44px; }
  #dpad { right:6px; }
}

/* ── SISTEMA PNG TILES ── */
/* Paleta de tiles en el editor */
.ed-png-section { display:flex; flex-direction:column; gap:6px; }
.ed-tile-filter {
  display:flex; gap:4px; align-items:center; flex-wrap:wrap;
}
.ed-tile-filter select {
  flex:1; background:rgba(255,255,255,.06); border:1px solid rgba(80,160,255,.2);
  border-radius:5px; padding:4px 6px; color:#cde; font-size:10px;
  font-family:'Courier New',monospace; outline:none; cursor:pointer;
}
.ed-tile-filter select:focus { border-color:rgba(80,160,255,.5); }
#ed-tile-grid {
  display:flex; flex-wrap:wrap; gap:4px;
  max-height:220px; overflow-y:auto; padding:2px;
}
.ed-tile-cell {
  position:relative; cursor:pointer; border:2px solid transparent;
  border-radius:4px; overflow:hidden; transition:border-color .12s;
  background:rgba(255,255,255,.05); flex-shrink:0;
}
.ed-tile-cell canvas { display:block; image-rendering:pixelated; }
.ed-tile-cell:hover  { border-color:rgba(255,255,255,.45); }
.ed-tile-cell.active { border-color:#ffd700; }
.ed-tile-badge {
  position:absolute; bottom:0; left:0; right:0;
  font-size:7px; color:#fff; text-align:center; line-height:1.6;
  background:rgba(0,0,0,.55); letter-spacing:.5px;
}
/* Paleta de sprites deco */
#ed-sprite-grid {
  display:flex; flex-wrap:wrap; gap:5px;
  max-height:160px; overflow-y:auto; padding:2px;
}
.ed-sprite-cell {
  width:46px; cursor:pointer; border:2px solid transparent; border-radius:5px;
  display:flex; flex-direction:column; align-items:center; gap:2px;
  background:rgba(255,255,255,.05); padding:3px; transition:border-color .12s;
  overflow:hidden;
}
.ed-sprite-cell canvas { display:block; image-rendering:pixelated; width:38px; height:38px; object-fit:contain; }
.ed-sprite-cell:hover  { border-color:rgba(255,255,255,.4); }
.ed-sprite-cell.active { border-color:#7fe87f; }
.ed-sprite-lbl { font-size:8px; color:#8ab; text-align:center; line-height:1.2; max-width:42px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
/* Grupo de terreno en paleta */
.ed-terrain-group { display:flex; flex-direction:column; gap:4px; }
.ed-terrain-grp-hdr {
  display:flex; align-items:center; gap:5px; font-size:9px; color:#8ab;
  letter-spacing:1px; text-transform:uppercase;
}
.ed-terrain-grp-dot { width:10px; height:10px; border-radius:2px; flex-shrink:0; }

/* ── PALETA GRANDE (MODAL FULLSCREEN) ── */
#tp-modal {
  position:fixed; inset:0; background:rgba(0,0,0,.88);
  display:none; flex-direction:column; z-index:500;
  backdrop-filter:blur(6px);
}
#tp-modal.open { display:flex; }
#tp-header {
  display:flex; align-items:center; gap:12px; flex-shrink:0;
  background:rgba(5,10,25,.96); border-bottom:1px solid rgba(80,160,255,.22);
  padding:10px 16px;
}
#tp-header h3 { font-size:14px; color:#4fc3f7; letter-spacing:2px; text-transform:uppercase; flex:1; margin:0; }
#tp-filters { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.tp-filter-btn {
  background:rgba(255,255,255,.07); border:1px solid rgba(80,160,255,.2);
  border-radius:16px; padding:3px 10px; color:#8ab; font-size:10px;
  cursor:pointer; font-family:'Courier New',monospace; letter-spacing:.5px;
  transition:background .15s, border-color .15s, color .15s;
}
.tp-filter-btn:hover { background:rgba(255,255,255,.14); }
.tp-filter-btn.active { background:rgba(80,160,255,.22); border-color:rgba(80,160,255,.55); color:#cde; }
#tp-close {
  background:rgba(255,80,80,.12); border:1px solid rgba(255,80,80,.3);
  border-radius:6px; padding:5px 10px; color:#faa; font-size:12px;
  cursor:pointer; font-family:'Courier New',monospace;
}
#tp-close:hover { background:rgba(255,80,80,.25); }
#tp-body {
  flex:1; overflow-y:auto; padding:18px;
  display:flex; flex-direction:column; gap:24px;
}
.tp-section { display:flex; flex-direction:column; gap:10px; }
.tp-section-hdr {
  display:flex; align-items:center; gap:10px;
  font-size:11px; color:#cde; letter-spacing:2px; text-transform:uppercase;
  padding-bottom:8px; border-bottom:1px solid rgba(80,160,255,.18);
}
.tp-section-dot { width:14px; height:14px; border-radius:3px; flex-shrink:0; }
.tp-section-count { color:#567; font-size:10px; margin-left:auto; }
.tp-grid {
  display:grid;
  grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
  gap:8px;
}
.tp-tile {
  background:rgba(255,255,255,.04); border:2px solid rgba(80,160,255,.12);
  border-radius:8px; overflow:hidden; cursor:pointer;
  display:flex; flex-direction:column; align-items:center;
  transition:border-color .15s, background .15s;
  padding-bottom:4px;
}
.tp-tile:hover { border-color:rgba(255,255,255,.4); background:rgba(255,255,255,.08); }
.tp-tile.active { border-color:#ffd700; background:rgba(255,215,0,.08); }
.tp-tile.unassigned { border-color:rgba(255,255,255,.06); opacity:.7; }
.tp-tile.unassigned:hover { opacity:1; }
.tp-img-wrap {
  width:100%; aspect-ratio:1/1; display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,.25); overflow:hidden;
}
.tp-img-wrap img {
  width:100%; height:100%; object-fit:cover; image-rendering:pixelated;
}
.tp-tile-name {
  font-size:8px; color:#8ab; letter-spacing:.4px; text-align:center;
  padding:3px 4px 0; line-height:1.3; word-break:break-all;
}
.tp-tile-terrain {
  font-size:8px; font-weight:700; text-align:center;
  padding:1px 4px; border-radius:3px; margin-top:2px;
  letter-spacing:.5px;
}
#tp-footer {
  flex-shrink:0; background:rgba(5,10,25,.96); border-top:1px solid rgba(80,160,255,.18);
  padding:8px 16px; font-size:10px; color:#567; text-align:center;
  letter-spacing:1px;
}
/* Botón que abre la paleta grande (en editor panel) */
.tp-open-btn {
  background:rgba(80,160,255,.12); border:1px solid rgba(80,160,255,.3);
  border-radius:6px; padding:6px 10px; color:#4fc3f7; font-size:11px;
  cursor:pointer; font-family:'Courier New',monospace; width:100%;
  transition:background .15s; letter-spacing:.5px; text-align:center;
}
.tp-open-btn:hover { background:rgba(80,160,255,.22); }
</style>
</head>
<body>
<canvas id="c"></canvas>

<div id="hud">
  <h2>&#127758; IsoCraft</h2>
  <div class="row">Semilla: <span id="h-seed"><?= $seed ?></span></div>
  <div class="row">Bioma: <span id="h-biome">—</span></div>
  <div class="row">Mapa: <span id="h-size"><?= $mapW ?>×<?= $mapH ?></span></div>
  <div class="row">Tile: <span id="h-tile">—</span></div>
  <div class="row">Zoom: <span id="h-zoom">100%</span></div>
  <div class="row">FPS: <span id="h-fps">—</span></div>
  <div class="row">Hora: <span id="h-time">—</span></div>
  <div class="row">Tiles: <span id="h-tiles">—</span></div>
</div>

<!-- Botón toggle editor (siempre visible) -->
<button id="editor-toggle" onclick="toggleEditor()">✏ Editor</button>

<!-- Panel lateral derecho del editor -->
<div id="editor-panel">

  <!-- Cabecera fija -->
  <div id="ed-panel-header">
    <h4>✏ Editor de Mapa</h4>
    <button id="ed-close-panel" onclick="toggleEditor()" title="Cerrar editor">✕</button>
  </div>

  <!-- Cuerpo scrollable -->
  <div id="ed-panel-body">

    <div>
      <div class="ep-label">Título del Mapa</div>
      <input class="ed-map-title" id="ed-title" type="text" placeholder="Sin título" maxlength="64">
    </div>

    <hr class="ed-sep">

    <div>
      <div class="ep-label">Herramienta</div>
      <div class="ed-tool-row">
        <button class="ed-tool-btn active" id="et-paint"  onclick="setTool('paint')"  title="Pintar terreno">🖌 Pintar</button>
        <button class="ed-tool-btn"        id="et-height" onclick="setTool('height')" title="LMB sube, RMB baja">↕ Altura</button>
        <button class="ed-tool-btn"        id="et-deco"   onclick="setTool('deco')"   title="Colocar decoración">🌿 Deco</button>
        <button class="ed-tool-btn"        id="et-erase"  onclick="setTool('erase')"  title="Borrar decoración">✗ Borrar</button>
        <button class="ed-tool-btn"        id="et-terrain" onclick="setTool('terrain')" title="Esculpir montañas y valles">⛰ Terreno</button>
      </div>
      <div id="ed-height-dir" style="display:none;font-size:10px;color:#8ab;line-height:2;margin-top:4px;">
        <label><input type="radio" name="hdir" value="1"  checked onchange="heightDirection=+1"> ▲ Subir</label>
        &nbsp;
        <label><input type="radio" name="hdir" value="-1" onchange="heightDirection=-1"> ▼ Bajar</label>
      </div>
      <!-- TERRAIN SCULPT CONTROLS -->
      <div id="ed-terrain-sculpt" style="display:none;">
        <div class="tsculpt-mode-row">
          <button class="tsculpt-mode-btn active" id="tsm-mountain" onclick="setTerrainMode('mountain')">⛰ Montaña</button>
          <button class="tsculpt-mode-btn"        id="tsm-valley"   onclick="setTerrainMode('valley')">🏞 Valle</button>
        </div>
        <div class="tsculpt-row">
          <span class="tsculpt-label">Radio</span>
          <input type="range" class="tsculpt-slider" id="ts-radius" min="1" max="12" value="4"
                 oninput="terrainRadius=+this.value; document.getElementById('ts-radius-val').textContent=this.value">
          <span class="tsculpt-val" id="ts-radius-val">4</span>
        </div>
        <div class="tsculpt-row">
          <span class="tsculpt-label">Altura pico</span>
          <input type="range" class="tsculpt-slider" id="ts-peak" min="1" max="4" value="2" step="1"
                 oninput="terrainPeakH=+this.value; document.getElementById('ts-peak-val').textContent=this.value">
          <span class="tsculpt-val" id="ts-peak-val">2</span>
        </div>
        <div class="tsculpt-mode-row">
          <button class="tsculpt-mode-btn active" id="tsh-smooth" onclick="setTerrainShape('smooth')">〜 Suave</button>
          <button class="tsculpt-mode-btn"        id="tsh-cone"   onclick="setTerrainShape('cone')">△ Cónico</button>
          <button class="tsculpt-mode-btn"        id="tsh-plateau" onclick="setTerrainShape('plateau')">▬ Meseta</button>
        </div>
        <label class="tsculpt-toggle">
          <input type="checkbox" id="ts-autotype" onchange="toggleTerrainAutoType(this.checked)" checked>
          <span>Auto-asignar tipo de terreno</span>
        </label>
      </div>
    </div>

    <hr class="ed-sep">

    <!-- Selector de terreno + variantes de tile PNG -->
    <div>
      <div class="ep-label">Terreno a Pintar</div>
      <div class="ed-terrain-grid" id="ed-terrain-grid"><!-- JS --></div>
    </div>

    <div id="ed-variants-section" style="display:none">
      <div class="ep-label" id="ed-variants-label">Variante de Tile</div>
      <div id="ed-tile-variants"><!-- JS --></div>
    </div>

    <hr class="ed-sep">

    <div>
      <div class="ep-label">Historial</div>
      <div class="ed-action-row">
        <button class="ed-action-btn" id="ed-undo" onclick="undo()" disabled>↩ Deshacer</button>
        <button class="ed-action-btn" id="ed-redo" onclick="redo()" disabled>↪ Rehacer</button>
      </div>
    </div>

    <hr class="ed-sep">

    <div>
      <div class="ep-label">Mapa</div>
      <div class="ed-action-row">
        <button class="ed-action-btn" id="ed-save" onclick="saveMap()"      title="Guardar en servidor">💾 Guardar</button>
        <button class="ed-action-btn"               onclick="openLoadModal()" title="Cargar mapa guardado">📂 Cargar</button>
      </div>
      <div class="ed-action-row" style="margin-top:5px;">
        <button class="ed-action-btn" onclick="exportMap()" title="Exportar JSON">⬇ Export</button>
        <button class="ed-action-btn" onclick="importMap()" title="Importar JSON">⬆ Import</button>
        <button class="ed-action-btn" onclick="newMap()"    title="Nuevo mapa">+ Nuevo</button>
      </div>
    </div>

    <hr class="ed-sep">

    <!-- Sprites deco -->
    <div>
      <div class="ep-label">🐾 Sprites Decoración</div>
      <div id="ed-sprite-grid"><!-- JS --></div>
    </div>

    <hr class="ed-sep">

    <!-- Paleta grande (modal) -->
    <button class="tp-open-btn" onclick="openTilePalette()">🖼 Ver todos los Tiles PNG</button>

  </div><!-- /ed-panel-body -->
</div><!-- /editor-panel -->

<!-- Modal de carga -->
<div id="load-modal">
  <div class="load-modal-card">
    <header>
      📂 MIS MAPAS
      <button onclick="closeLoadModal()">✕</button>
    </header>
    <div id="load-modal-body">
      <div class="map-empty">Cargando...</div>
    </div>
  </div>
</div>

<!-- ══ PALETA GRANDE DE TILES ══ -->
<div id="tp-modal">
  <div id="tp-header">
    <h3>🖼 Paleta de Tiles PNG</h3>
    <div id="tp-filters">
      <button class="tp-filter-btn active" data-f="all"      onclick="tpFilter('all')">Todos</button>
      <button class="tp-filter-btn"        data-f="__none__" onclick="tpFilter('__none__')">Sin asignar</button>
      <button class="tp-filter-btn"        data-f="DEEP"     onclick="tpFilter('DEEP')"    >🌊 Agua Prof.</button>
      <button class="tp-filter-btn"        data-f="WATER"    onclick="tpFilter('WATER')"   >💧 Agua</button>
      <button class="tp-filter-btn"        data-f="SAND"     onclick="tpFilter('SAND')"    >🏖 Arena</button>
      <button class="tp-filter-btn"        data-f="GRASS"    onclick="tpFilter('GRASS')"   >🌿 Césped</button>
      <button class="tp-filter-btn"        data-f="JUNGLE"   onclick="tpFilter('JUNGLE')"  >🌴 Jungla</button>
      <button class="tp-filter-btn"        data-f="ROCK"     onclick="tpFilter('ROCK')"    >⛰ Roca</button>
      <button class="tp-filter-btn"        data-f="MOUNTAIN" onclick="tpFilter('MOUNTAIN')">🏔 Montaña</button>
      <button class="tp-filter-btn"        data-f="SNOW"     onclick="tpFilter('SNOW')"    >❄ Nieve</button>
    </div>
    <button id="tp-close" onclick="closeTilePalette()">✕ Cerrar</button>
  </div>
  <div id="tp-body"><!-- JS --></div>
  <div id="tp-footer">
    isocraft_tilemap.json — edita el campo "terrain" de cada tile para asignarlo al terreno correspondiente &nbsp;|&nbsp; Sistema <?= htmlspecialchars(config_loader_system_name()) ?> v3.0
  </div>
</div>

<div id="dpad">
  <div class="dp-row"><button class="dp-btn" id="dp-u">▲</button></div>
  <div class="dp-row">
    <button class="dp-btn" id="dp-l">◀</button>
    <div class="dp-mid">PAN</div>
    <button class="dp-btn" id="dp-r">▶</button>
  </div>
  <div class="dp-row"><button class="dp-btn" id="dp-d">▼</button></div>
</div>

<div id="bottom-bar">
  <!-- Izquierda: minimapa -->
  <div id="bar-left">
    <h4>&#128506; Minimapa</h4>
    <canvas id="mmCanvas"></canvas>
  </div>

  <!-- Centro: info tile + footer -->
  <div id="bar-mid">
    <div id="tinfo">Haz clic en un tile para ver información</div>
    <div id="footer-inline">Sistema <?= htmlspecialchars(config_loader_system_name()) ?> v3.0</div>
  </div>

  <!-- Derecha: botón menú -->
  <div id="bar-right">
    <button id="menu-btn" onclick="toggleBottomMenu()">&#9881; Opciones &#9650;</button>
    <div id="bottom-menu">

      <!-- Sección: Controles -->
      <div>
        <div class="bm-section-title">&#9000; Controles</div>
        <div class="bm-ctrl">
          <div><span class="k">WASD</span> Mover cámara</div>
          <div><span class="k">Arrastrar</span> Desplazar</div>
          <div><span class="k">Rueda</span> Zoom</div>
          <div><span class="k">Click</span> Info / Editar tile</div>
          <div><span class="k">R</span> Regenerar mapa</div>
          <div><span class="k">Ctrl+Z</span> Deshacer</div>
          <div><span class="k">Ctrl+Y</span> Rehacer</div>
          <div><span class="k">&#128070; Arrastrar</span> Pan táctil</div>
          <div><span class="k">&#129295; Pinch</span> Zoom táctil</div>
        </div>
      </div>

      <!-- Sección: Terreno -->
      <div>
        <div class="bm-section-title">&#128506; Tipo de Terreno</div>
        <div class="bm-li"><div class="bm-lc" style="background:#142d5e"></div>Agua profunda</div>
        <div class="bm-li"><div class="bm-lc" style="background:#1e5296"></div>Agua poco prof.</div>
        <div class="bm-li"><div class="bm-lc" style="background:#c8a96e"></div>Arena</div>
        <div class="bm-li"><div class="bm-lc" style="background:#5a8a3c"></div>Césped</div>
        <div class="bm-li"><div class="bm-lc" style="background:#1f5c1f"></div>Jungla</div>
        <div class="bm-li"><div class="bm-lc" style="background:#7a6a5a"></div>Roca</div>
        <div class="bm-li"><div class="bm-lc" style="background:#b0a898"></div>Montaña</div>
        <div class="bm-li"><div class="bm-lc" style="background:#e8eef8"></div>Nieve</div>
      </div>

      <!-- Sección: Opciones -->
      <div>
        <div class="bm-section-title">&#9881; Opciones</div>
        <div class="bm-option">
          Animaciones de agua
          <button class="toggle-btn on" id="opt-water" onclick="toggleWaterAnim()">ON</button>
        </div>
        <div class="bm-option">
          Ciclo día/noche
          <button class="toggle-btn on" id="opt-day" onclick="toggleDayNight()">ON</button>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
// ============================================================
// CONSTANTES
// ============================================================
const SEED = <?= $seed ?>;
let MW   = <?= $mapW ?>;
let MH   = <?= $mapH ?>;
const TW   = 128;  // ancho del tile (coincide con seasons_tiles.png)
const TH   = 64;   // alto  del tile (coincide con seasons_tiles.png)
const WH   = 26;   // altura de pared isométrica (escala con TH)

// ── Datos PNG inyectados desde PHP ───────────────────────────
const TILE_MAP    = <?= json_encode($tilemapData, JSON_UNESCAPED_UNICODE) ?>;
const TILE_FILES  = <?= json_encode($tileFiles) ?>;
const SPRITE_FILES= <?= json_encode($spriteFiles) ?>;

const T = { DEEP:0, WATER:1, SAND:2, GRASS:3, JUNGLE:4, ROCK:5, MOUNTAIN:6, SNOW:7 };

const TINFO = {
  [T.DEEP]:     { name:'Agua Profunda',   emoji:'🌊' },
  [T.WATER]:    { name:'Agua',            emoji:'💧' },
  [T.SAND]:     { name:'Arena',           emoji:'🏖️' },
  [T.GRASS]:    { name:'Césped',          emoji:'🌿' },
  [T.JUNGLE]:   { name:'Jungla',          emoji:'🌴' },
  [T.ROCK]:     { name:'Roca',            emoji:'⛰️' },
  [T.MOUNTAIN]: { name:'Montaña',         emoji:'🏔️' },
  [T.SNOW]:     { name:'Nieve',           emoji:'❄️' },
};

// ── ESTADO DEL EDITOR ────────────────────────────────────────
const DEFAULT_HEIGHT = { 0:0, 1:0, 2:1, 3:1, 4:1, 5:2, 6:3, 7:4 };
const VALID_DECO = {
  0:[null], 1:['lily',null], 2:[null], 3:['flower',null],
  4:['tree','palm',null], 5:['boulder',null], 6:['rock',null], 7:['snowrock',null]
};

let editorMode = false, activeTool = 'paint', paintType = T.GRASS;
let isPainting = false, paintStartX = 0, paintStartY = 0;
let heightDirection = +1, _pendingSnapshot = false, _edMouseDown = false;
let mapTitle = '<?= addslashes($mapTitle) ?>', mapDirty = false;

// Terrain sculpt tool state
let terrainSculptMode = 'mountain'; // 'mountain' | 'valley'
let terrainRadius = 4;
let terrainPeakH  = 2;
let terrainShape  = 'smooth'; // 'smooth' | 'cone' | 'plateau'
let terrainAutoType = true;
let _lastTerrainR = -99, _lastTerrainC = -99;
const undoStack = [], redoStack = [], MAX_UNDO = 20;
let waterAnimEnabled = true;
let dayNightEnabled  = true;

// ============================================================
// RUIDO DE VALOR (Value Noise con interpolación suave)
// ============================================================
class VNoise {
  constructor(seed) { this.s = seed >>> 0; }

  _h(ix, iy) {
    let n = (ix * 374761393 + iy * 668265263 + this.s * 2246822519) >>> 0;
    n = Math.imul(n ^ (n >>> 13), 1274126177) >>> 0;
    return (n ^ (n >>> 16)) / 0xffffffff;
  }

  at(x, y) {
    const ix = Math.floor(x), iy = Math.floor(y);
    const fx = x - ix, fy = y - iy;
    const ux = fx*fx*(3-2*fx), uy = fy*fy*(3-2*fy);
    const v00 = this._h(ix,iy), v10 = this._h(ix+1,iy);
    const v01 = this._h(ix,iy+1), v11 = this._h(ix+1,iy+1);
    return v00 + (v10-v00)*ux + (v01-v00)*uy + (v00-v10-v01+v11)*ux*uy;
  }

  oct(x, y, n, p) {
    let v=0, a=1, f=1, m=0;
    for(let i=0;i<n;i++){ v+=this.at(x*f,y*f)*a; m+=a; a*=p; f*=2; }
    return v/m;
  }
}

// ============================================================
// MAPA INVERSO TERRENO→PNG  (necesario antes de buildMap)
// ============================================================
const _typeIdToKey = {0:'DEEP',1:'WATER',2:'SAND',3:'GRASS',4:'JUNGLE',5:'ROCK',6:'MOUNTAIN',7:'SNOW'};

const terrainToPngs = {};
for (const [name, info] of Object.entries(TILE_MAP.tiles || {})) {
  if (!info.terrain) continue;
  if (!terrainToPngs[info.terrain]) terrainToPngs[info.terrain] = [];
  terrainToPngs[info.terrain].push(name);
}

// ── Sistema de biomas (8 tipos según SEED) ───────────────────
const MAP_TYPES = ['islas','continente','montanas','playa','jungla','nieve','lago','rio'];
const MAP_TYPE  = MAP_TYPES[SEED % MAP_TYPES.length];
const MAP_ICONS = {
  islas:'🏝', continente:'🌍', montanas:'⛰', playa:'🏖',
  jungla:'🌴', nieve:'❄', lago:'🏔', rio:'🌊'
};

function _biomeParms(type) {
  // { deep, water, sand, veg, rock, mount, edgePow, edgeStart, eBoost, mois }
  switch (type) {
    case 'islas':      return {deep:0.10,water:0.20,sand:0.28,veg:0.58,rock:0.76,mount:0.90,edgePow:0.0,edgeStart:1.0,eBoost:0.0,mois:0.45};
    case 'continente': return {deep:0.07,water:0.14,sand:0.21,veg:0.66,rock:0.80,mount:0.91,edgePow:1.5,edgeStart:0.94,eBoost:0.22,mois:0.52};
    case 'montanas':   return {deep:0.06,water:0.12,sand:0.17,veg:0.38,rock:0.55,mount:0.72,edgePow:1.2,edgeStart:0.97,eBoost:0.35,mois:0.60};
    case 'playa':      return {deep:0.10,water:0.22,sand:0.42,veg:0.72,rock:0.85,mount:0.94,edgePow:2.5,edgeStart:0.78,eBoost:0.06,mois:0.45};
    case 'jungla':     return {deep:0.08,water:0.16,sand:0.22,veg:0.70,rock:0.83,mount:0.93,edgePow:1.6,edgeStart:0.92,eBoost:0.18,mois:0.28};
    case 'nieve':      return {deep:0.05,water:0.10,sand:0.15,veg:0.30,rock:0.48,mount:0.64,edgePow:1.4,edgeStart:0.95,eBoost:0.38,mois:0.99};
    case 'lago':       return {deep:0.10,water:0.20,sand:0.27,veg:0.68,rock:0.82,mount:0.92,edgePow:2.0,edgeStart:0.88,eBoost:0.18,mois:0.50};
    case 'rio':        return {deep:0.08,water:0.15,sand:0.22,veg:0.66,rock:0.80,mount:0.91,edgePow:1.8,edgeStart:0.92,eBoost:0.20,mois:0.50};
    default:           return {deep:0.13,water:0.23,sand:0.29,veg:0.64,rock:0.78,mount:0.90,edgePow:2.2,edgeStart:0.88,eBoost:0.10,mois:0.50};
  }
}

// Devuelve un nombre de PNG aleatorio (hash deterministico) para un terreno en (r,c)
function _randomTexForType(typeId, r, c) {
  const key  = _typeIdToKey[typeId];
  const list = terrainToPngs[key];
  if (!list || list.length === 0) return null;
  const idx = Math.floor(tileHash(r, c, 13) * list.length);
  return list[Math.min(idx, list.length - 1)];
}

// ============================================================
// GENERACIÓN DEL MAPA — 8 biomas según semilla
// ============================================================
function buildMap() {
  const P     = _biomeParms(MAP_TYPE);
  const elev  = new VNoise(SEED);
  const elev2 = new VNoise(SEED ^ 0x9f8e7d6c);
  const mois  = new VNoise(SEED ^ 0xdeadbeef);
  const deco  = new VNoise(SEED ^ 0x12345678);
  const rvN     = (MAP_TYPE === 'rio')   ? new VNoise(SEED ^ 0x77665544) : null;
  const islandN = (MAP_TYPE === 'islas') ? new VNoise(SEED ^ 0x3a5b7c8d) : null;
  const map   = [];

  for (let r = 0; r < MH; r++) {
    map[r] = [];
    for (let c = 0; c < MW; c++) {
      const nx = c / MW, ny = r / MH;

      const e1 = elev.oct(nx * 4.5, ny * 4.5, 7, 0.52);
      const e2 = elev2.oct(nx * 2.8, ny * 2.8, 5, 0.58);
      let e = Math.max(e1 * 0.90, e2 * 0.80);

      const dx = nx * 2 - 1, dy = ny * 2 - 1;
      const d  = Math.sqrt(dx * dx + dy * dy);
      e -= Math.pow(Math.max(0, d - P.edgeStart), 2) * P.edgePow;
      e = Math.max(0, Math.min(1, e + P.eBoost));

      // Islas: patrón multiplicativo de amplitud — genera múltiples islas grandes
      if (MAP_TYPE === 'islas' && islandN) {
        // Frecuencia 2.0 → picos de ~25-35% del mapa de ancho (islas grandes)
        const iAmp = islandN.oct(nx * 2.0, ny * 2.0, 4, 0.50);
        // Multiplicar: picos de noise → tierra, valles → agua
        e = e * (iAmp * 1.8);
        // Contención suave en bordes para que no haya tierra en el filo del mapa
        if (d > 0.86) e *= Math.max(0, 1 - (d - 0.86) * 7);
        e = Math.max(0, Math.min(1, e));
      }

      // Lago: depresión central crea un lago
      if (MAP_TYPE === 'lago') {
        const lakeR = 0.32;
        if (d < lakeR) e = Math.max(0, e - (1 - d / lakeR) * 0.58);
      }

      // Río: carve winding river strip de izq a der
      if (MAP_TYPE === 'rio' && rvN) {
        const cy   = rvN.at(nx * 3.5, 0) * 0.5 + 0.25;
        const band = Math.abs(ny - cy) * MH;
        if (band < 1.4 + rvN.at(nx * 6, ny * 3) * 1.4) e = Math.min(e, P.water * 0.65);
      }

      const m  = mois.oct(nx * 5 + 7, ny * 5 + 7, 4, 0.5);
      const dv = deco.at(c * 0.8, r * 0.8);

      let type, height;
      if      (e < P.deep)  { type = T.DEEP;     height = 0; }
      else if (e < P.water) { type = T.WATER;     height = 0; }
      else if (e < P.sand)  { type = T.SAND;      height = 1; }
      else if (e < P.veg)   {
        const jungleOk = MAP_TYPE === 'jungla' ? m > P.mois
                       : MAP_TYPE === 'nieve'  ? false
                       : m > P.mois;
        type = jungleOk ? T.JUNGLE : T.GRASS; height = 1;
      }
      else if (e < P.rock)  { type = T.ROCK;      height = 2; }
      else if (e < P.mount) { type = T.MOUNTAIN;  height = 3; }
      else                  { type = T.SNOW;      height = 4; }

      let dec = null;
      if      (type === T.JUNGLE   && dv > 0.20) dec = dv > 0.55 ? 'palm' : 'tree';
      else if (type === T.GRASS    && dv > 0.55) dec = 'flower';
      else if (type === T.ROCK     && dv > 0.30) dec = 'boulder';
      else if (type === T.MOUNTAIN && dv > 0.25) dec = 'rock';
      else if (type === T.WATER    && dv > 0.65) dec = 'lily';
      else if (type === T.SNOW     && dv > 0.40) dec = 'snowrock';

      const tex = _randomTexForType(type, r, c);
      map[r][c] = { type, height, e, dec, tex };
    }
  }
  return map;
}

// ============================================================
// CANVAS Y ESTADO DE CÁMARA
// ============================================================
const canvas = document.getElementById('c');
const ctx    = canvas.getContext('2d');

let camX=0, camY=0, zoom=1;
let dragging=false, dragO={x:0,y:0,cx:0,cy:0};
let hovR=null, hovC=null;
let selR=null, selC=null;
let frame=0, lastTs=0, fps=0, fpsN=0, fpsT=0;
const keys = {};

// ============================================================
// CICLO DÍA / NOCHE
// ============================================================
let dayAngle  = 0.92;
const DAY_SPEED = 0.00042;
let _sunH = 0;

function lerpC(c1, c2, t) {
  t = Math.max(0, Math.min(1, t));
  const p = s => [parseInt(s.slice(1,3),16), parseInt(s.slice(3,5),16), parseInt(s.slice(5,7),16)];
  const [r1,g1,b1]=p(c1), [r2,g2,b2]=p(c2);
  const rr=Math.round(r1+(r2-r1)*t), gg=Math.round(g1+(g2-g1)*t), bb=Math.round(b1+(b2-b1)*t);
  return `#${rr.toString(16).padStart(2,'0')}${gg.toString(16).padStart(2,'0')}${bb.toString(16).padStart(2,'0')}`;
}

function tileHash(r, c, i=0) {
  let n = ((r * 374761 + c * 668265 + i * 1234567) ^ (SEED * 89101)) >>> 0;
  n = Math.imul(n ^ (n >>> 13), 1274126177) >>> 0;
  return (n ^ (n >>> 16)) / 0xffffffff;
}

function getSunState() {
  const sh  = Math.sin(dayAngle);
  const ch  = Math.cos(dayAngle);
  const horizY = canvas.height * 0.28;
  const arcR   = canvas.width  * 0.46;
  return {
    sunX : canvas.width/2 - ch * arcR,
    sunY : horizY - sh * canvas.height * 0.30,
    moonX: canvas.width/2 + ch * arcR,
    moonY: horizY + sh * canvas.height * 0.30,
    sh, ch
  };
}

function timeOfDayLabel(sh) {
  if (sh > 0.82)  return '☀️ Mediodía';
  if (sh > 0.45)  return '🌤️ Día pleno';
  if (sh > 0.10)  return Math.cos(dayAngle) < 0 ? '🌅 Tarde'      : '🌄 Mañana';
  if (sh > -0.06) return Math.cos(dayAngle) < 0 ? '🌇 Atardecer'  : '🌅 Amanecer';
  if (sh > -0.35) return '🌆 Crepúsculo';
  return '🌙 Noche';
}

let map = buildMap();

// HUD: mostrar tipo de bioma
(function() {
  const el = document.getElementById('h-biome');
  if (el) el.textContent = `${MAP_ICONS[MAP_TYPE] || ''} ${MAP_TYPE}`;
})();

// ============================================================
// MINIMAPA
// ============================================================
let MM_PX   = Math.max(2, Math.floor(160 / Math.max(MW, MH)));
const mmCanvas = document.getElementById('mmCanvas');
const mmCtx    = mmCanvas.getContext('2d');
mmCanvas.width  = MW * MM_PX;
mmCanvas.height = MH * MM_PX;

const MINI_C = {
  [T.DEEP]:     '#0e2244',
  [T.WATER]:    '#1a4a8a',
  [T.SAND]:     '#c8a96e',
  [T.GRASS]:    '#5a8a3c',
  [T.JUNGLE]:   '#1a5018',
  [T.ROCK]:     '#6a5a4a',
  [T.MOUNTAIN]: '#9a9080',
  [T.SNOW]:     '#dce8f8',
};

let mmOff    = document.createElement('canvas');
mmOff.width  = MW * MM_PX;
mmOff.height = MH * MM_PX;
let mmOffCtx = mmOff.getContext('2d');

function rebuildMinimap() {
  for(let r=0; r<MH; r++) {
    for(let c=0; c<MW; c++) {
      mmOffCtx.fillStyle = MINI_C[map[r][c].type];
      mmOffCtx.fillRect(c*MM_PX, r*MM_PX, MM_PX, MM_PX);
    }
  }
}
rebuildMinimap();

function updateMinimapTile(r, c) {
  mmOffCtx.fillStyle = MINI_C[map[r][c].type];
  mmOffCtx.fillRect(c * MM_PX, r * MM_PX, MM_PX, MM_PX);
}

function drawMinimap() {
  mmCtx.drawImage(mmOff, 0, 0);

  if(selR!==null && selR>=0 && selR<MH && selC>=0 && selC<MW) {
    mmCtx.fillStyle = '#ffd700';
    mmCtx.fillRect(selC*MM_PX, selR*MM_PX, MM_PX, MM_PX);
  }
  if(hovR!==null && hovR>=0 && hovR<MH && hovC>=0 && hovC<MW) {
    mmCtx.fillStyle = 'rgba(255,255,255,.65)';
    mmCtx.fillRect(hovC*MM_PX, hovR*MM_PX, MM_PX, MM_PX);
  }

  const corners = [
    s2t(0, 0),
    s2t(canvas.width, 0),
    s2t(canvas.width, canvas.height),
    s2t(0, canvas.height),
  ];
  mmCtx.beginPath();
  corners.forEach((t, i) => {
    const mx = t.c * MM_PX + MM_PX * 0.5;
    const my = t.r * MM_PX + MM_PX * 0.5;
    i === 0 ? mmCtx.moveTo(mx, my) : mmCtx.lineTo(mx, my);
  });
  mmCtx.closePath();
  mmCtx.fillStyle   = 'rgba(255,255,255,.07)';
  mmCtx.strokeStyle = 'rgba(255,255,255,.85)';
  mmCtx.lineWidth   = 1.2;
  mmCtx.fill();
  mmCtx.stroke();
}

mmCanvas.addEventListener('click', e => {
  const rect = mmCanvas.getBoundingClientRect();
  const tc = (e.clientX - rect.left) / MM_PX;
  const tr = (e.clientY - rect.top)  / MM_PX;
  const sx = (tc - tr) * (TW/2) * zoom;
  const sy = (tc + tr) * (TH/2) * zoom;
  camX = canvas.width  / 2 - sx;
  camY = canvas.height / 2 - sy;
});

// ============================================================
// COORDENADAS
// ============================================================
function t2s(r, c, h=0) {
  return {
    x: (c-r)*(TW/2)*zoom + camX,
    y: (c+r)*(TH/2)*zoom + camY - h*WH*zoom
  };
}

function s2t(mx, my) {
  const rx = (mx-camX)/zoom, ry = (my-camY)/zoom;
  const X = rx/(TW/2), Y = ry/(TH/2);
  return { r: Math.round((Y-X)/2), c: Math.round((Y+X)/2) };
}

function resetCam() {
  camX = canvas.width/2  - (MW/2-MH/2)*(TW/2)*zoom;
  camY = canvas.height/3 - (MW/2+MH/2)*(TH/2)*zoom;
}

let _firstResize = true;
function resize() {
  canvas.width  = window.innerWidth;
  canvas.height = window.innerHeight;
  if (_firstResize) {
    _firstResize = false;
    // Auto-fit: que el mapa quepa en pantalla sin importar el tamaño del tile
    const availW = canvas.width;
    const availH = canvas.height - 90; // descontar barra inferior
    const fw = availW / ((MW + MH) * (TW / 2));
    const fh = availH / ((MW + MH) * (TH / 2));
    const fitZ = Math.min(fw, fh) * 1.1;
    const isMobile = window.innerWidth < 768 || ('ontouchstart' in window);
    zoom = isMobile
      ? Math.max(0.12, Math.min(0.40, fitZ))
      : Math.max(0.15, Math.min(1.0,  fitZ));
  }
  resetCam();
}

// ============================================================
// COLORES DE TILES
// ============================================================
function tileColors(type, f, sh) {
  const wa = Math.sin(f*0.04)*0.1 + 0.9;
  const wHue  = Math.round(210 + sh * 18);
  const wLit  = Math.round(36 * wa);
  switch(type) {
    case T.DEEP:
      return { top:`hsl(220,78%,${Math.round(20*wa)}%)`, L:'#0e2a5a', R:'#0a1e42' };
    case T.WATER:
      return { top:`hsl(${wHue},72%,${wLit}%)`,          L:'#1a4070', R:'#132e54' };
    case T.SAND:
      return { top:'#d4b47c', L:'#aa8c58', R:'#8a6e3e' };
    case T.GRASS:
      return { top:'#5e9040', L:'#426828', R:'#2e4e1a' };
    case T.JUNGLE:
      return { top:'#2a6e28', L:'#1c4e1c', R:'#143a14' };
    case T.ROCK:
      return { top:'#8a7a6a', L:'#665a4a', R:'#4e4234' };
    case T.MOUNTAIN:
      return { top:'#b8b0a0', L:'#8a8278', R:'#6e6860' };
    case T.SNOW:
      return { top:'#eaf0ff', L:'#c0cce0', R:'#a0aec8' };
    default:
      return { top:'#888', L:'#666', R:'#444' };
  }
}

// ============================================================
// RENDERIZAR TILE ISOMÉTRICO
// ============================================================
function drawTile(r, c) {
  const tile = map[r][c];
  const p    = t2s(r, c, tile.height);
  const w = TW*zoom, h = TH*zoom;
  const wh = tile.height * WH * zoom;

  const top = { x: p.x+w/2, y: p.y       };
  const rig = { x: p.x+w,   y: p.y+h/2   };
  const bot = { x: p.x+w/2, y: p.y+h     };
  const lft = { x: p.x,     y: p.y+h/2   };

  const col = tileColors(tile.type, frame, _sunH);
  const isH = hovR===r && hovC===c;
  const isS = selR===r && selC===c;

  if (wh > 0 && tile.type!==T.DEEP && tile.type!==T.WATER) {
    ctx.beginPath();
    ctx.moveTo(lft.x,lft.y); ctx.lineTo(bot.x,bot.y);
    ctx.lineTo(bot.x,bot.y+wh); ctx.lineTo(lft.x,lft.y+wh);
    ctx.closePath(); ctx.fillStyle=col.L; ctx.fill();

    ctx.beginPath();
    ctx.moveTo(rig.x,rig.y); ctx.lineTo(bot.x,bot.y);
    ctx.lineTo(bot.x,bot.y+wh); ctx.lineTo(rig.x,rig.y+wh);
    ctx.closePath(); ctx.fillStyle=col.R; ctx.fill();
  }

  ctx.beginPath();
  ctx.moveTo(top.x,top.y); ctx.lineTo(rig.x,rig.y);
  ctx.lineTo(bot.x,bot.y); ctx.lineTo(lft.x,lft.y);
  ctx.closePath();

  if (tile.type===T.GRASS || tile.type===T.JUNGLE || tile.type===T.SAND) {
    const g = ctx.createLinearGradient(p.x,p.y,p.x+w,p.y+h);
    g.addColorStop(0, adjustBrightness(col.top, +15));
    g.addColorStop(1, adjustBrightness(col.top, -10));
    ctx.fillStyle = g;
  } else {
    ctx.fillStyle = col.top;
  }
  ctx.fill();

  if (zoom > 0.28 && tile.type !== T.WATER && tile.type !== T.DEEP) {
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(top.x,top.y); ctx.lineTo(rig.x,rig.y);
    ctx.lineTo(bot.x,bot.y); ctx.lineTo(lft.x,lft.y);
    ctx.closePath();
    ctx.clip();
    // SNOW es estático si tiene PNG, animado si es solo procedural
    const hasPng = !!_getPngTex(tile.type, r, c);
    if (STATIC_TEX_TYPES.has(tile.type) && (tile.type !== T.SNOW || hasPng)) {
      const tex = getOrBuildTexture(tile.type, r, c);
      ctx.drawImage(tex, lft.x, top.y, w, h);
    } else {
      // Textura animada o SNOW sin PNG
      drawTileTexture(ctx, tile.type, top, rig, bot, lft, w, h, r, c);
    }
    ctx.restore();
    ctx.beginPath();
    ctx.moveTo(top.x,top.y); ctx.lineTo(rig.x,rig.y);
    ctx.lineTo(bot.x,bot.y); ctx.lineTo(lft.x,lft.y);
    ctx.closePath();
  }

  if (isS) {
    ctx.fillStyle='rgba(255,220,0,.28)'; ctx.fill();
    ctx.strokeStyle='#ffd700'; ctx.lineWidth=1.8; ctx.stroke();
  } else if (isH) {
    // En modo editor, usar tinte de herramienta activa
    if (editorMode) {
      const hintColor = activeTool==='paint'   ? 'rgba(100,200,255,.22)'
                      : activeTool==='height'  ? 'rgba(255,180,80,.22)'
                      : activeTool==='deco'    ? 'rgba(100,255,130,.22)'
                      : activeTool==='terrain' ? 'rgba(180,120,255,.22)'
                      :                          'rgba(255,80,80,.22)';
      ctx.fillStyle=hintColor; ctx.fill();
    } else {
      ctx.fillStyle='rgba(255,255,255,.16)'; ctx.fill();
    }
    ctx.strokeStyle='rgba(255,255,255,.55)'; ctx.lineWidth=0.9; ctx.stroke();
  }
  // Terrain brush radius preview: tint tiles within radius of hovered tile
  if (editorMode && activeTool==='terrain' && hovR>=0) {
    const dr = r - hovR, dc = c - hovC;
    const dist = Math.sqrt(dr*dr + dc*dc);
    if (dist > 0 && dist <= terrainRadius) {
      const t   = dist / terrainRadius;
      const fac = terrainFalloff(t);
      const alpha = fac * 0.35;
      ctx.fillStyle = terrainSculptMode === 'mountain'
        ? `rgba(220,180,80,${alpha})`
        : `rgba(80,180,255,${alpha})`;
      ctx.fill();
      ctx.strokeStyle = `rgba(180,120,255,${0.15 + fac*0.25})`; ctx.lineWidth=0.8; ctx.stroke();
    }
  }
  // Sin stroke en tiles normales → sin rayas entre tiles

  if (waterAnimEnabled && (tile.type===T.WATER || tile.type===T.DEEP)) {
    const sv = Math.sin(frame*0.055 + r*0.42 + c*0.31)*0.5+0.5;
    if (sv > 0.65) {
      ctx.beginPath();
      ctx.moveTo(top.x,top.y); ctx.lineTo(rig.x,rig.y);
      ctx.lineTo(bot.x,bot.y); ctx.lineTo(lft.x,lft.y);
      ctx.closePath();
      const sr = _sunH > 0 ? Math.round(180+_sunH*52) : 165;
      const sg2 = _sunH > 0 ? Math.round(228+_sunH*14) : 205;
      const sb  = 255;
      const sg = ctx.createLinearGradient(p.x,p.y,p.x+w,p.y+h);
      sg.addColorStop(0, `rgba(${sr},${sg2},${sb},${((sv-.65)*.36).toFixed(3)})`);
      sg.addColorStop(1, `rgba(${sr},${sg2},${sb},0)`);
      ctx.fillStyle=sg; ctx.fill();
    }
  }

  if (tile.dec && zoom > 0.45) {
    drawDeco(tile.dec, top.x, p.y+h*.35, zoom, frame, r, c);
  }
}

function adjustBrightness(color, amt) {
  if (color.startsWith('hsl')) {
    return color.replace(/(\d+)%\)/, (m, n) => `${Math.min(100,Math.max(0,+n+amt))}%)`);
  }
  return color;
}

// ============================================================
// DECORACIONES
// ============================================================
function drawDeco(deco, cx, cy, z, f, r, c) {
  ctx.save();
  // ── Sprites PNG (prefijo 'sprite:') ─────────────────────────
  if (deco && deco.startsWith('sprite:')) {
    const key = deco.slice(7);
    const img = spriteImages.get(key);
    if (img && img.complete && img.naturalWidth > 0) {
      const sw = TW * z * 1.4;
      const sh = sw;
      ctx.drawImage(img, cx - sw/2, cy - sh, sw, sh);
    } else {
      // placeholder mientras carga
      ctx.fillStyle = 'rgba(255,100,200,0.6)';
      ctx.fillRect(cx - 10*z, cy - 20*z, 20*z, 20*z);
    }
    ctx.restore();
    return;
  }
  switch(deco) {

    case 'tree': {
      ctx.fillStyle='rgba(0,0,0,.2)';
      ctx.beginPath(); ctx.ellipse(cx,cy+2*z,8*z,3*z,0,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#6b3a1f';
      ctx.fillRect(cx-2*z, cy-14*z, 4*z, 14*z);
      const shades=['#1a5c1a','#236b23','#2d7a2d'];
      const sizes=[[10,16],[13,12],[16,8]];
      shades.forEach((col,i)=>{
        ctx.fillStyle=col;
        ctx.beginPath();
        const [half,base]=sizes[i];
        ctx.moveTo(cx, cy-(32-i*6)*z);
        ctx.lineTo(cx-half*z, cy-base*z);
        ctx.lineTo(cx+half*z, cy-base*z);
        ctx.closePath(); ctx.fill();
      });
      ctx.fillStyle='rgba(120,220,120,.12)';
      ctx.beginPath(); ctx.moveTo(cx,cy-32*z); ctx.lineTo(cx-4*z,cy-22*z); ctx.lineTo(cx+7*z,cy-24*z); ctx.closePath(); ctx.fill();
      break;
    }

    case 'palm': {
      ctx.fillStyle='rgba(0,0,0,.2)';
      ctx.beginPath(); ctx.ellipse(cx,cy+2*z,10*z,3.5*z,0,0,Math.PI*2); ctx.fill();
      ctx.strokeStyle='#8b5e3c'; ctx.lineWidth=4*z;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.quadraticCurveTo(cx+6*z, cy-16*z, cx-2*z, cy-30*z);
      ctx.stroke();
      const frondAngs=[-0.5,0,0.6,-1.1,1.2,-0.2];
      const frondCols=['#2e8b2e','#3a9e3a','#228b22','#32a532','#1e7c1e','#2a9428'];
      frondAngs.forEach((a,i)=>{
        ctx.strokeStyle=frondCols[i%frondCols.length]; ctx.lineWidth=2.5*z;
        ctx.beginPath();
        const tx=cx-2*z, ty=cy-30*z;
        ctx.moveTo(tx, ty);
        ctx.quadraticCurveTo(tx+Math.sin(a+.5)*13*z, ty-4*z, tx+Math.sin(a)*18*z, ty+8*z);
        ctx.stroke();
      });
      ctx.fillStyle='#8b6914';
      [[cx-5*z,cy-28*z,2.8*z],[cx+1*z,cy-29*z,2.2*z],[cx-2*z,cy-31*z,2*z]].forEach(([x,y,r])=>{
        ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill();
      });
      break;
    }

    case 'boulder': {
      ctx.fillStyle='rgba(0,0,0,.25)';
      ctx.beginPath(); ctx.ellipse(cx,cy+2*z,13*z,4*z,0,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#7a6a5a';
      ctx.beginPath();
      ctx.moveTo(cx-12*z,cy); ctx.quadraticCurveTo(cx-14*z,cy-10*z,cx-4*z,cy-18*z);
      ctx.quadraticCurveTo(cx+4*z,cy-22*z,cx+11*z,cy-14*z);
      ctx.quadraticCurveTo(cx+14*z,cy-6*z,cx+10*z,cy); ctx.closePath(); ctx.fill();
      ctx.fillStyle='#8c7c6c';
      ctx.beginPath();
      ctx.moveTo(cx-1*z,cy-2*z); ctx.quadraticCurveTo(cx-3*z,cy-12*z,cx+5*z,cy-16*z);
      ctx.quadraticCurveTo(cx+10*z,cy-12*z,cx+8*z,cy-1*z); ctx.closePath(); ctx.fill();
      ctx.fillStyle='rgba(255,255,255,.14)';
      ctx.beginPath(); ctx.ellipse(cx+3*z,cy-12*z,5*z,2.5*z,-0.4,0,Math.PI*2); ctx.fill();
      break;
    }

    case 'rock': {
      ctx.fillStyle='rgba(0,0,0,.2)';
      ctx.beginPath(); ctx.ellipse(cx,cy+1*z,7*z,2.5*z,0,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#9a8a7a';
      ctx.beginPath(); ctx.moveTo(cx-6*z,cy-1*z); ctx.lineTo(cx,cy-15*z); ctx.lineTo(cx+7*z,cy-1*z); ctx.closePath(); ctx.fill();
      ctx.fillStyle='#b09a88';
      ctx.beginPath(); ctx.moveTo(cx-6*z,cy-1*z); ctx.lineTo(cx,cy-15*z); ctx.lineTo(cx+1*z,cy-15*z); ctx.lineTo(cx-3*z,cy-1*z); ctx.closePath(); ctx.fill();
      break;
    }

    case 'flower': {
      const cols=['#ff6b6b','#ffd93d','#c084fc','#60d3f0','#ff9fbd'];
      for(let i=0;i<3;i++){
        const fx=cx+(i-1)*7*z, fy=cy-2*z-i*1.5*z;
        ctx.fillStyle='#4a7a2a'; ctx.fillRect(fx-z*.8,fy,z*1.6,5*z);
        ctx.fillStyle=cols[(r+c+i)%cols.length];
        ctx.beginPath(); ctx.arc(fx,fy,2.5*z,0,Math.PI*2); ctx.fill();
      }
      break;
    }

    case 'lily': {
      const rip=Math.sin(f*0.05+cx*0.08)*0.6;
      ctx.strokeStyle='rgba(140,210,255,.45)'; ctx.lineWidth=z;
      ctx.beginPath(); ctx.ellipse(cx,cy,(8+rip)*z,4*z,0,0,Math.PI*2); ctx.stroke();
      ctx.fillStyle='#2a7a2a';
      ctx.beginPath(); ctx.ellipse(cx,cy-z,5*z,3*z,0,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#ff8090';
      ctx.beginPath(); ctx.arc(cx,cy-2*z,2*z,0,Math.PI*2); ctx.fill();
      break;
    }

    case 'snowrock': {
      ctx.fillStyle='rgba(0,0,0,.2)';
      ctx.beginPath(); ctx.ellipse(cx,cy+z,8*z,3*z,0,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#888080';
      ctx.beginPath(); ctx.moveTo(cx-7*z,cy); ctx.quadraticCurveTo(cx-8*z,cy-9*z,cx-1*z,cy-14*z); ctx.quadraticCurveTo(cx+6*z,cy-12*z,cx+8*z,cy); ctx.closePath(); ctx.fill();
      ctx.fillStyle='#e8eef8';
      ctx.beginPath(); ctx.moveTo(cx-3*z,cy-8*z); ctx.quadraticCurveTo(cx,cy-16*z,cx+4*z,cy-12*z); ctx.quadraticCurveTo(cx+6*z,cy-6*z,cx+2*z,cy-4*z); ctx.closePath(); ctx.fill();
      break;
    }
  }
  ctx.restore();
}

// ============================================================
// FONDO: CIELO DINÁMICO
// ============================================================
function drawBg() {
  const ss = getSunState();
  const sh = ss.sh;
  const horizY = canvas.height * 0.42;

  let skyT, skyM, skyB;
  if (sh > 0.30) {
    const t = Math.min(1,(sh-0.30)/0.70);
    skyT = lerpC('#0e1a50','#081440',t);
    skyM = lerpC('#1a4ab4','#1040a8',t);
    skyB = lerpC('#2a64dc','#1852c0',t);
  } else if (sh > 0) {
    const t = sh / 0.30;
    skyT = lerpC('#120922','#0e1a50',t);
    skyM = lerpC('#922412','#1a4ab4',t);
    skyB = lerpC('#dc6220','#2a64dc',t);
  } else if (sh > -0.14) {
    const t = (sh+0.14)/0.14;
    skyT = lerpC('#040210','#120922',t);
    skyM = lerpC('#1c0a06','#922412',t);
    skyB = lerpC('#280e04','#dc6220',t);
  } else {
    skyT='#010208'; skyM='#020410'; skyB='#030512';
  }
  const g = ctx.createLinearGradient(0,0,0,canvas.height);
  g.addColorStop(0, skyT); g.addColorStop(0.44, skyM); g.addColorStop(1, skyB);
  ctx.fillStyle=g; ctx.fillRect(0,0,canvas.width,canvas.height);

  const starA = Math.max(0, Math.min(1, -sh / 0.16));
  if (starA > 0.01) {
    for(let i=0;i<150;i++){
      const sx=((SEED*17+i*137)%canvas.width+canvas.width)%canvas.width;
      const sy=((SEED*31+i*89)%(canvas.height*.46|0)+canvas.height)%(canvas.height*.46|0);
      const br=Math.sin(frame*0.025+i*0.7)*0.5+0.5;
      ctx.globalAlpha=br*0.75*starA;
      ctx.fillStyle='#fff';
      ctx.fillRect(sx,sy,i%7===0?2:1,i%7===0?2:1);
    }
    ctx.globalAlpha=1;
  }

  if (sh > -0.18 && sh < 0.34) {
    const gi = Math.max(0, 1 - Math.abs(sh-0.06)/0.26);
    const ga = gi * 0.52;
    const hg = ctx.createRadialGradient(ss.sunX, horizY, 0, ss.sunX, horizY, canvas.width*0.56);
    if (sh >= 0) {
      hg.addColorStop(0,   `rgba(255,188,55,${ga.toFixed(3)})`);
      hg.addColorStop(0.38,`rgba(255,100,20,${(ga*0.38).toFixed(3)})`);
    } else {
      hg.addColorStop(0,   `rgba(255,120,40,${(ga*0.52).toFixed(3)})`);
      hg.addColorStop(0.38,`rgba(180,50,15,${(ga*0.18).toFixed(3)})`);
    }
    hg.addColorStop(1,'rgba(0,0,0,0)');
    ctx.fillStyle=hg; ctx.fillRect(0,0,canvas.width,canvas.height);
  }

  if (ss.sunY < horizY + 22 && sh > -0.07) {
    const vis    = Math.min(1,(sh+0.07)/0.12);
    const sunSz  = 14 + sh * 8;
    const [cr,cg,cb] = sh > 0.26 ? [255,242,150] : [255,185,72];
    ctx.globalAlpha = vis;

    const sg = ctx.createRadialGradient(ss.sunX,ss.sunY,0,ss.sunX,ss.sunY,sunSz*4.6);
    sg.addColorStop(0,  `rgba(${cr},${cg},${cb},${sh>0.26?0.22:0.44})`);
    sg.addColorStop(0.4,`rgba(${cr},${cg},${cb},${sh>0.26?0.07:0.16})`);
    sg.addColorStop(1,  `rgba(${cr},${cg},${cb},0)`);
    ctx.fillStyle=sg;
    ctx.beginPath(); ctx.arc(ss.sunX,ss.sunY,sunSz*4.6,0,Math.PI*2); ctx.fill();

    if (sh < 0.24) {
      const rayA = (0.24-sh)/0.30;
      for(let i=0;i<10;i++){
        const ang = (i/10)*Math.PI*2 + frame*0.0032;
        const len = sunSz*(1.7+Math.sin(frame*0.042+i)*0.4);
        ctx.strokeStyle = `rgba(255,205,105,${(rayA*0.32).toFixed(3)})`;
        ctx.lineWidth   = 1.2;
        ctx.beginPath();
        ctx.moveTo(ss.sunX+Math.cos(ang)*(sunSz+1), ss.sunY+Math.sin(ang)*(sunSz+1));
        ctx.lineTo(ss.sunX+Math.cos(ang)*(sunSz+len), ss.sunY+Math.sin(ang)*(sunSz+len));
        ctx.stroke();
      }
    }

    const diskCol = sh>0.26 ? '#fffff0' : (sh>0 ? '#ffe0a0' : '#ff9040');
    ctx.fillStyle = diskCol;
    ctx.beginPath(); ctx.arc(ss.sunX,ss.sunY,sunSz,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='rgba(255,255,255,0.38)';
    ctx.beginPath(); ctx.arc(ss.sunX-sunSz*0.22,ss.sunY-sunSz*0.22,sunSz*0.38,0,Math.PI*2); ctx.fill();
    ctx.globalAlpha=1;
  }

  if (ss.moonY < horizY + 22 && sh < 0.10) {
    const moonA = Math.max(0, Math.min(1, (-sh+0.10)/0.24));
    if (moonA > 0.01) {
      ctx.globalAlpha = moonA;
      const msz = 11;
      const mg = ctx.createRadialGradient(ss.moonX,ss.moonY,0,ss.moonX,ss.moonY,msz*3.8);
      mg.addColorStop(0,'rgba(200,220,255,0.14)'); mg.addColorStop(1,'rgba(200,220,255,0)');
      ctx.fillStyle=mg; ctx.beginPath(); ctx.arc(ss.moonX,ss.moonY,msz*3.8,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#d8e8ff';
      ctx.beginPath(); ctx.arc(ss.moonX,ss.moonY,msz,0,Math.PI*2); ctx.fill();
      ctx.fillStyle='#020410';
      ctx.beginPath(); ctx.arc(ss.moonX+msz*0.36,ss.moonY-msz*0.16,msz*0.87,0,Math.PI*2); ctx.fill();
      ctx.globalAlpha=1;
    }
  }
}

// ============================================================
// TEXTURAS DE TILES  (tCtx = contexto destino)
// ============================================================
function drawTileTexture(tCtx, type, top, rig, bot, lft, w, h, r, c) {
  const th = (i) => tileHash(r, c, i);
  const cx = (top.x + bot.x) * 0.5;
  const cy = (top.y + bot.y) * 0.5;

  switch(type) {

    case T.GRASS: {
      for(let i=0;i<6;i++){
        const tx = lft.x + th(i*2)*w, ty = top.y + th(i*2+1)*h;
        const ra = w*0.08 + th(i+40)*w*0.06, rb = h*0.06 + th(i+50)*h*0.04;
        tCtx.fillStyle = `rgba(0,45,0,${(0.25+th(i+20)*0.20).toFixed(3)})`;
        tCtx.beginPath();
        tCtx.ellipse(tx, ty, ra, rb, th(i+60)*Math.PI, 0, Math.PI*2);
        tCtx.fill();
      }
      for(let i=0;i<3;i++){
        const tx = lft.x+th(i+70)*w, ty = top.y+th(i+75)*h;
        tCtx.fillStyle = `rgba(165,225,80,${(0.18+th(i+80)*0.12).toFixed(3)})`;
        tCtx.beginPath();
        tCtx.ellipse(tx,ty,w*0.07,h*0.055,th(i+85)*Math.PI,0,Math.PI*2);
        tCtx.fill();
      }
      break;
    }

    case T.JUNGLE: {
      for(let i=0;i<8;i++){
        const tx = lft.x+th(i*2)*w, ty = top.y+th(i*2+1)*h;
        const ra = w*0.09+th(i+40)*w*0.07, rb = h*0.07+th(i+50)*h*0.05;
        tCtx.fillStyle = `rgba(0,55,0,${(0.28+th(i+20)*0.22).toFixed(3)})`;
        tCtx.beginPath();
        tCtx.ellipse(tx,ty,ra,rb,th(i+60)*Math.PI,0,Math.PI*2);
        tCtx.fill();
      }
      for(let i=0;i<2;i++){
        tCtx.strokeStyle = `rgba(0,65,0,${(0.25+th(i+90)*0.18).toFixed(3)})`;
        tCtx.lineWidth = 1.8;
        tCtx.beginPath();
        tCtx.moveTo(lft.x+th(i*3+100)*w, top.y+th(i*3+101)*h*0.45);
        tCtx.quadraticCurveTo(cx, cy, lft.x+th(i*3+102)*w, top.y+th(i*3+103)*h*0.75+h*0.22);
        tCtx.stroke();
      }
      break;
    }

    case T.SAND: {
      for(let i=0;i<4;i++){
        const y0 = top.y + (i+0.65)*h/4.1;
        const x0 = lft.x + th(i*4)*w*0.22;
        const x1 = lft.x + w*0.42 + th(i*4+1)*w*0.38;
        const mx=(x0+x1)/2, my=y0-h*0.068;
        tCtx.strokeStyle = `rgba(158,120,62,${(0.32+th(i+30)*0.24).toFixed(3)})`;
        tCtx.lineWidth = 1.4+th(i+40)*1.1;
        tCtx.beginPath();
        tCtx.moveTo(x0, y0+th(i+50)*h*0.05);
        tCtx.quadraticCurveTo(mx, my, x1, y0+th(i+55)*h*0.05);
        tCtx.stroke();
        tCtx.strokeStyle = `rgba(225,198,148,${(0.22+th(i+60)*0.16).toFixed(3)})`;
        tCtx.lineWidth = 0.8;
        tCtx.beginPath();
        tCtx.moveTo(x0, y0+th(i+50)*h*0.05-1.8);
        tCtx.quadraticCurveTo(mx, my-1.8, x1, y0+th(i+55)*h*0.05-1.8);
        tCtx.stroke();
      }
      for(let i=0;i<10;i++){
        const gx=lft.x+th(i+80)*w, gy=top.y+th(i+90)*h;
        tCtx.fillStyle = `rgba(148,110,58,${(0.22+th(i+100)*0.20).toFixed(3)})`;
        tCtx.beginPath();
        tCtx.arc(gx, gy, 0.9+th(i+110)*1.2, 0, Math.PI*2);
        tCtx.fill();
      }
      break;
    }

    case T.ROCK: {
      tCtx.fillStyle = 'rgba(72,60,45,0.32)';
      tCtx.beginPath();
      tCtx.moveTo(lft.x+w*0.14, cy-h*0.04);
      tCtx.lineTo(cx-w*0.08,    top.y+h*0.20);
      tCtx.lineTo(cx+w*0.11,    top.y+h*0.18);
      tCtx.lineTo(lft.x+w*0.60, cy+h*0.06);
      tCtx.closePath(); tCtx.fill();
      for(let i=0;i<3;i++){
        const ax=lft.x+th(i*5)*w,   ay=top.y+th(i*5+1)*h;
        const bx=ax+(th(i*5+2)-0.5)*w*0.52, by=ay+th(i*5+3)*h*0.44;
        tCtx.strokeStyle = `rgba(30,22,12,${(0.38+th(i+50)*0.24).toFixed(3)})`;
        tCtx.lineWidth = 1.4+th(i+60)*0.9;
        tCtx.beginPath(); tCtx.moveTo(ax,ay); tCtx.lineTo(bx,by); tCtx.stroke();
        tCtx.strokeStyle = `rgba(172,158,136,${(0.22+th(i+70)*0.14).toFixed(3)})`;
        tCtx.lineWidth = 0.8;
        tCtx.beginPath(); tCtx.moveTo(ax+1,ay-1); tCtx.lineTo(bx+1,by-1); tCtx.stroke();
      }
      const px=lft.x+th(80)*w*0.52+w*0.24, py=top.y+th(81)*h*0.48+h*0.14;
      tCtx.fillStyle = 'rgba(178,165,140,0.30)';
      tCtx.beginPath(); tCtx.ellipse(px,py,w*0.12,h*0.09,th(82)*Math.PI,0,Math.PI*2); tCtx.fill();
      break;
    }

    case T.MOUNTAIN: {
      tCtx.fillStyle = 'rgba(235,245,255,0.48)';
      tCtx.beginPath();
      tCtx.moveTo(cx, top.y+h*0.02);
      tCtx.lineTo(cx-w*0.20, top.y+h*0.30);
      tCtx.lineTo(cx+w*0.15, top.y+h*0.27);
      tCtx.closePath(); tCtx.fill();
      tCtx.fillStyle = 'rgba(170,195,240,0.28)';
      tCtx.beginPath();
      tCtx.moveTo(cx, top.y+h*0.02);
      tCtx.lineTo(cx-w*0.20, top.y+h*0.30);
      tCtx.lineTo(cx-w*0.04, top.y+h*0.20);
      tCtx.closePath(); tCtx.fill();
      for(let i=0;i<3;i++){
        tCtx.strokeStyle = `rgba(50,42,32,${(0.32+th(i*3+90)*0.22).toFixed(3)})`;
        tCtx.lineWidth = 1.2+th(i+95)*0.9;
        tCtx.beginPath();
        tCtx.moveTo(cx+(th(i*3+90)-0.5)*w*0.46, top.y+h*0.33);
        tCtx.lineTo(cx+(th(i*3+91)-0.5)*w*0.58, bot.y-h*0.06);
        tCtx.stroke();
      }
      tCtx.strokeStyle = 'rgba(200,188,168,0.26)';
      tCtx.lineWidth = 1.8;
      tCtx.beginPath();
      tCtx.moveTo(lft.x+w*0.10, cy+h*0.10);
      tCtx.lineTo(lft.x+w*0.52, cy-h*0.02);
      tCtx.stroke();
      break;
    }

    case T.SNOW: {
      // SNOW usa frame global (animado) → no se cachea
      for(let i=0;i<9;i++){
        const sx=lft.x+th(i+100)*w, sy=top.y+th(i+110)*h;
        const br=Math.sin(frame*0.09+r*0.48+c*0.32+i*1.4)*0.5+0.5;
        if(br>0.42){
          const a = Math.min(0.95, (br-0.42)*1.3);
          tCtx.fillStyle = `rgba(255,255,255,${a.toFixed(3)})`;
          tCtx.beginPath(); tCtx.arc(sx,sy,1.8+th(i+120)*1.8,0,Math.PI*2); tCtx.fill();
        }
      }
      tCtx.fillStyle = 'rgba(115,158,218,0.26)';
      tCtx.beginPath();
      tCtx.moveTo(lft.x+w*0.06, cy+h*0.08);
      tCtx.lineTo(lft.x+w*0.44, bot.y-h*0.05);
      tCtx.lineTo(lft.x+w*0.62, bot.y-h*0.09);
      tCtx.lineTo(lft.x+w*0.18, cy-h*0.02);
      tCtx.closePath(); tCtx.fill();
      for(let i=0;i<2;i++){
        tCtx.strokeStyle = `rgba(205,225,255,${(0.24+th(i+130)*0.18).toFixed(3)})`;
        tCtx.lineWidth = 1.2;
        tCtx.beginPath();
        tCtx.moveTo(lft.x+th(i+140)*w*0.3, top.y+(i+1)*h*0.30);
        tCtx.lineTo(lft.x+w*0.42+th(i+141)*w*0.38, top.y+(i+1)*h*0.30-h*0.06);
        tCtx.stroke();
      }
      break;
    }
  }
}

// ============================================================
// CACHÉ DE TEXTURAS PROCEDURALES
// ============================================================
// Si hay PNG para SNOW, también es estático (el PNG no anima)
const STATIC_TEX_TYPES = new Set([T.GRASS, T.JUNGLE, T.SAND, T.ROCK, T.MOUNTAIN, T.SNOW]);
const textureCache = new Map();

function getOrBuildTexture(type, r, c) {
  // Preferir textura específica del tile (per-cell) sobre la hash-aleatoria
  const cell    = (map && map[r]) ? map[r][c] : null;
  const texName = cell ? cell.tex : null;
  // texName → clave compartida (todos los cells con mismo PNG comparten canvas)
  // sin texName → clave posicional clásica
  const key = texName ? `tex_${texName}` : `${type}_${r}_${c}`;
  if (textureCache.has(key)) return textureCache.get(key);

  const off    = document.createElement('canvas');
  off.width    = TW; off.height = TH;
  const offCtx = off.getContext('2d');

  const tTop = {x: TW/2, y: 0};
  const tRig = {x: TW,   y: TH/2};
  const tBot = {x: TW/2, y: TH};
  const tLft = {x: 0,    y: TH/2};

  // Resolver imagen: tex explícita → fallback hash
  let pngImg = null;
  if (texName) {
    const img = tileImages.get(texName);
    if (img && img.complete && img.naturalWidth > 0) pngImg = img;
  }
  if (!pngImg) pngImg = _getPngTex(type, r, c);

  if (pngImg) {
    offCtx.save();
    offCtx.beginPath();
    offCtx.moveTo(tTop.x, tTop.y);
    offCtx.lineTo(tRig.x, tRig.y);
    offCtx.lineTo(tBot.x, tBot.y);
    offCtx.lineTo(tLft.x, tLft.y);
    offCtx.closePath();
    offCtx.clip();
    offCtx.drawImage(pngImg, 0, 0, pngImg.naturalWidth, pngImg.naturalHeight, 0, 0, TW, TH);
    offCtx.restore();
  } else {
    drawTileTexture(offCtx, type, tTop, tRig, tBot, tLft, TW, TH, r, c);
  }

  textureCache.set(key, off);
  return off;
}

// Invalida el caché de un tile específico (al editar)
function clearTileTextureCache(r, c) {
  for (let t = 0; t <= 7; t++) textureCache.delete(`${t}_${r}_${c}`);
}

// Limpia todo el caché (al cargar nuevo mapa)
function clearAllTextureCache() { textureCache.clear(); }

// ============================================================
// TINTE AMBIENTAL DÍA/NOCHE
// ============================================================
function drawAmbient(sh) {
  let r, g, b, a;
  if (sh > 0.38)       { r=255; g=250; b=220; a=0.03; }
  else if (sh > 0.08)  { const t=(sh-0.08)/0.30; r=255; g=Math.round(165+t*85); b=Math.round(58+t*162); a=0.14-t*0.11; }
  else if (sh > -0.12) { const t=(sh+0.12)/0.20; r=255; g=Math.round(118+t*47); b=Math.round(28+t*30); a=0.30-t*0.16; }
  else if (sh > -0.36) { const t=(sh+0.36)/0.24; r=28; g=14; b=68; a=0.44-t*0.14; }
  else                 { r=0; g=8; b=34; a=0.72; }
  ctx.fillStyle=`rgba(${r},${g},${b},${a.toFixed(3)})`;
  ctx.fillRect(0,0,canvas.width,canvas.height);
}

// ============================================================
// VIEWPORT CULLING AVANZADO
// ============================================================
/**
 * Calcula EXACTAMENTE qué rango de tiles es visible en pantalla.
 * Convierte las 4 esquinas del viewport (con margen) a coordenadas
 * de tile y devuelve el bounding box (minR, maxR, minC, maxC).
 */
function getVisibleTileRange() {
  const W = canvas.width, H = canvas.height;
  // Margen horizontal para tiles parcialmente visibles
  const hMarg = TW * zoom * 2;
  // Margen vertical: altura máxima de pared + tile completo
  const vMargTop = (4 * WH + TH) * zoom;
  const vMargBot = TH * zoom;

  // Cuatro esquinas del viewport extendido → coordenadas de tile
  const corners = [
    s2t(-hMarg,           -vMargTop),
    s2t(W + hMarg,        -vMargTop),
    s2t(-hMarg,           H + vMargBot),
    s2t(W + hMarg,        H + vMargBot),
  ];

  let minR = MH, maxR = -1, minC = MW, maxC = -1;
  for (const {r, c} of corners) {
    if (r < minR) minR = r;
    if (r > maxR) maxR = r;
    if (c < minC) minC = c;
    if (c > maxC) maxC = c;
  }

  // Margen +1 tile para evitar artefactos en bordes, clampear al mapa
  return {
    minR: Math.max(0,    minR - 1),
    maxR: Math.min(MH-1, maxR + 1),
    minC: Math.max(0,    minC - 1),
    maxC: Math.min(MW-1, maxC + 1),
  };
}

// ============================================================
// RENDER PRINCIPAL
// ============================================================
function render(ts) {
  frame++;
  fpsT+=ts-lastTs; lastTs=ts; fpsN++;
  if(fpsT>=500){ fps=Math.round(fpsN*1000/fpsT); document.getElementById('h-fps').textContent=fps; fpsN=0; fpsT=0; }

  const ss = getSunState();
  _sunH = ss.sh;

  ctx.clearRect(0,0,canvas.width,canvas.height);
  drawBg();

  // ── Viewport Culling: sólo tiles visibles en orden painter's ──
  const { minR, maxR, minC, maxC } = getVisibleTileRange();
  let visibleCount = 0;

  // Recorrer diagonales (r+c = cte.) garantiza orden correcto para isométrico
  const minDiag = minR + minC;
  const maxDiag = maxR + maxC;
  for (let diag = minDiag; diag <= maxDiag; diag++) {
    const rStart = Math.max(minR, diag - maxC);
    const rEnd   = Math.min(maxR, diag - minC);
    if (rStart > rEnd) continue;
    for (let r = rStart; r <= rEnd; r++) {
      const c = diag - r;
      drawTile(r, c);
      visibleCount++;
    }
  }

  drawAmbient(_sunH);

  if(hovR!==null && hovR>=0 && hovR<MH && hovC>=0 && hovC<MW){
    const ti=TINFO[map[hovR][hovC].type];
    document.getElementById('h-tile').textContent=`(${hovR},${hovC}) ${ti.emoji} ${ti.name}`;
  }
  document.getElementById('h-zoom').textContent=Math.round(zoom*100)+'%';
  document.getElementById('h-time').textContent=timeOfDayLabel(_sunH);
  document.getElementById('h-tiles').textContent=visibleCount+'/'+(MW*MH);

  drawMinimap();
}

// ============================================================
// CÁMARA
// ============================================================
function moveCam() {
  const spd=7/zoom;
  if(keys['w']||keys['arrowup'])    camY+=spd;
  if(keys['s']||keys['arrowdown'])  camY-=spd;
  if(keys['a']||keys['arrowleft'])  camX+=spd;
  if(keys['d']||keys['arrowright']) camX-=spd;
}

function loop(ts) {
  if (dayNightEnabled) dayAngle = (dayAngle + DAY_SPEED) % (Math.PI * 2);
  moveCam();
  render(ts);
  requestAnimationFrame(loop);
}

// ============================================================
// EVENTOS MOUSE
// ============================================================
canvas.addEventListener('mousedown', e=>{
  if (editorMode) {
    _edMouseDown = true;
    paintStartX = e.clientX;
    paintStartY = e.clientY;
    isPainting = false;
    _pendingSnapshot = true;
    return;
  }
  dragging=true; canvas.classList.add('drag');
  dragO={x:e.clientX, y:e.clientY, cx:camX, cy:camY};
});

canvas.addEventListener('mousemove', e=>{
  if (editorMode && _edMouseDown) {
    const dx = e.clientX - paintStartX, dy = e.clientY - paintStartY;
    if (!isPainting && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) {
      isPainting = true;
      if (_pendingSnapshot) { pushUndo(); _pendingSnapshot = false; }
    }
    if (isPainting) {
      const {r,c} = s2t(e.clientX, e.clientY);
      applyEditAt(r, c, e.buttons === 2);
    }
  } else if (!editorMode && dragging) {
    camX=dragO.cx+(e.clientX-dragO.x);
    camY=dragO.cy+(e.clientY-dragO.y);
  }
  const {r,c}=s2t(e.clientX,e.clientY);
  hovR=r; hovC=c;
});

canvas.addEventListener('mouseup', e=>{
  if (editorMode) {
    const dx = e.clientX - paintStartX, dy = e.clientY - paintStartY;
    const moved = Math.abs(dx) > 3 || Math.abs(dy) > 3;
    if (!moved && _pendingSnapshot) {
      const {r,c} = s2t(e.clientX, e.clientY);
      pushUndo();
      applyEditAt(r, c, e.button === 2);
    }
    if (isPainting || (!moved && _pendingSnapshot)) {
      rebuildMinimap();
      mapDirty = true;
      updateSaveButton();
    }
    isPainting = false;
    _edMouseDown = false;
    _pendingSnapshot = false;
    return;
  }
  const moved=Math.abs(e.clientX-dragO.x)>5||Math.abs(e.clientY-dragO.y)>5;
  if(!moved){
    const {r,c}=s2t(e.clientX,e.clientY);
    if(r>=0&&r<MH&&c>=0&&c<MW){
      selR=r; selC=c;
      const tile=map[r][c];
      const ti=TINFO[tile.type];
      const decStr=tile.dec?' | Deco: '+tile.dec:'';
      document.getElementById('tinfo').textContent=
        `${ti.emoji} ${ti.name} | Elevación: ${tile.e.toFixed(3)} | Altura: ${tile.height}${decStr}`;
    }
  }
  dragging=false; canvas.classList.remove('drag');
});

canvas.addEventListener('mouseleave', ()=>{
  dragging=false; canvas.classList.remove('drag'); hovR=null; hovC=null;
  if (editorMode) { isPainting=false; _edMouseDown=false; _pendingSnapshot=false; }
});

canvas.addEventListener('contextmenu', e=>{
  if (editorMode) e.preventDefault();
});

canvas.addEventListener('wheel', e=>{
  e.preventDefault();
  const f=e.deltaY>0?.88:1.14;
  const nz=Math.max(.10,Math.min(2.0,zoom*f));
  camX=e.clientX+(camX-e.clientX)*(nz/zoom);
  camY=e.clientY+(camY-e.clientY)*(nz/zoom);
  zoom=nz;
},{passive:false});

document.addEventListener('keydown', e=>{
  // Undo/Redo
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='z') {
    e.preventDefault(); undo(); return;
  }
  if ((e.ctrlKey||e.metaKey) && (e.key.toLowerCase()==='y' || (e.shiftKey && e.key.toLowerCase()==='z'))) {
    e.preventDefault(); redo(); return;
  }
  keys[e.key.toLowerCase()]=true;
  if (e.key.toLowerCase()==='r' && !editorMode) {
    const ns=Math.floor(Math.random()*999999);
    location.href=`?seed=${ns}&w=${MW}&h=${MH}`;
  }
});
document.addEventListener('keyup', e=>{ keys[e.key.toLowerCase()]=false; });

// ============================================================
// EVENTOS TÁCTILES (móvil)
// ============================================================
let touchBase = { dist:0, zoom:1, cx:0, cy:0, ox:0, oy:0 };

function touchDist(t1, t2) {
  const dx=t1.clientX-t2.clientX, dy=t1.clientY-t2.clientY;
  return Math.sqrt(dx*dx+dy*dy);
}
function touchMid(t1, t2) {
  return { x:(t1.clientX+t2.clientX)/2, y:(t1.clientY+t2.clientY)/2 };
}

canvas.addEventListener('touchstart', e=>{
  e.preventDefault();
  const t = e.touches;
  if(t.length===1){
    if (editorMode) {
      _edMouseDown = true;
      paintStartX = t[0].clientX; paintStartY = t[0].clientY;
      isPainting = false; _pendingSnapshot = true;
    } else {
      dragging=true; canvas.classList.add('drag');
      dragO={x:t[0].clientX, y:t[0].clientY, cx:camX, cy:camY};
    }
  } else if(t.length===2){
    // 2 dedos: siempre pinch-zoom, cancela pintura
    isPainting=false; _edMouseDown=false; _pendingSnapshot=false;
    dragging=false; canvas.classList.remove('drag');
    touchBase.dist = touchDist(t[0],t[1]);
    touchBase.zoom = zoom;
    const mid = touchMid(t[0],t[1]);
    touchBase.cx=camX; touchBase.cy=camY;
    touchBase.ox=mid.x; touchBase.oy=mid.y;
  }
},{passive:false});

canvas.addEventListener('touchmove', e=>{
  e.preventDefault();
  const t = e.touches;
  if(t.length===1){
    if (editorMode && _edMouseDown) {
      const dx=t[0].clientX-paintStartX, dy=t[0].clientY-paintStartY;
      if (!isPainting && (Math.abs(dx)>3 || Math.abs(dy)>3)) {
        isPainting = true;
        if (_pendingSnapshot) { pushUndo(); _pendingSnapshot=false; }
      }
      if (isPainting) {
        const {r,c}=s2t(t[0].clientX,t[0].clientY);
        applyEditAt(r,c);
      }
    } else if (!editorMode && dragging) {
      camX=dragO.cx+(t[0].clientX-dragO.x);
      camY=dragO.cy+(t[0].clientY-dragO.y);
    }
    const {r,c}=s2t(t[0].clientX,t[0].clientY);
    hovR=r; hovC=c;
  } else if(t.length===2){
    const d   = touchDist(t[0],t[1]);
    const mid = touchMid(t[0],t[1]);
    const nz  = Math.max(0.25, Math.min(3.5, touchBase.zoom * d / touchBase.dist));
    const scale = nz / touchBase.zoom;
    camX = mid.x + (touchBase.cx - touchBase.ox) * scale;
    camY = mid.y + (touchBase.cy - touchBase.oy) * scale;
    touchBase.ox=mid.x; touchBase.oy=mid.y;
    touchBase.cx=camX;  touchBase.cy=camY;
    touchBase.dist=d;   touchBase.zoom=nz;
    zoom=nz;
  }
},{passive:false});

canvas.addEventListener('touchend', e=>{
  e.preventDefault();
  if(e.touches.length===0){
    if (editorMode && _edMouseDown) {
      const t=e.changedTouches[0];
      const dx=t.clientX-paintStartX, dy=t.clientY-paintStartY;
      const moved=Math.abs(dx)>3||Math.abs(dy)>3;
      if (!moved && _pendingSnapshot) {
        const {r,c}=s2t(t.clientX,t.clientY);
        pushUndo();
        applyEditAt(r,c);
      }
      if (isPainting || (!moved && _pendingSnapshot)) {
        rebuildMinimap();
        mapDirty=true;
        updateSaveButton();
      }
      isPainting=false; _edMouseDown=false; _pendingSnapshot=false;
    } else if (dragging) {
      const t=e.changedTouches[0];
      const moved=Math.abs(t.clientX-dragO.x)>12||Math.abs(t.clientY-dragO.y)>12;
      if(!moved){
        const {r,c}=s2t(t.clientX,t.clientY);
        if(r>=0&&r<MH&&c>=0&&c<MW){
          selR=r; selC=c;
          const tile=map[r][c];
          const ti=TINFO[tile.type];
          const decStr=tile.dec?' | Deco: '+tile.dec:'';
          document.getElementById('tinfo').textContent=
            `${ti.emoji} ${ti.name} | Elevación: ${tile.e.toFixed(3)} | Altura: ${tile.height}${decStr}`;
        }
      }
    }
    dragging=false; canvas.classList.remove('drag'); hovR=null; hovC=null;
  }
},{passive:false});

canvas.addEventListener('touchcancel', ()=>{
  dragging=false; canvas.classList.remove('drag'); hovR=null; hovC=null;
  isPainting=false; _edMouseDown=false; _pendingSnapshot=false;
},{passive:true});

// ============================================================
// D-PAD TÁCTIL (móvil)
// ============================================================
const dpMap = { 'dp-u':'w', 'dp-d':'s', 'dp-l':'a', 'dp-r':'d' };
Object.entries(dpMap).forEach(([id, key]) => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('touchstart',  e=>{ e.preventDefault(); keys[key]=true;  }, {passive:false});
  el.addEventListener('touchend',    e=>{ e.preventDefault(); keys[key]=false; }, {passive:false});
  el.addEventListener('touchcancel', ()=>{ keys[key]=false; });
});

// ============================================================
// EDITOR — FUNCIONES PRINCIPALES
// ============================================================

function toggleEditor() {
  editorMode = !editorMode;
  const btn   = document.getElementById('editor-toggle');
  const panel = document.getElementById('editor-panel');
  btn.textContent = editorMode ? '✏ Editor ON' : '✏ Editor';
  btn.classList.toggle('active', editorMode);
  panel.classList.toggle('visible', editorMode);
  canvas.classList.toggle('edit', editorMode);
}

function setTool(tool) {
  activeTool = tool;
  ['paint','height','deco','erase','terrain'].forEach(t => {
    document.getElementById('et-'+t).classList.toggle('active', t === tool);
  });
  document.getElementById('ed-height-dir').style.display    = tool === 'height'  ? 'block' : 'none';
  document.getElementById('ed-terrain-sculpt').style.display = tool === 'terrain' ? 'block' : 'none';
}

function applyEditAt(r, c, rightClick = false) {
  if (r < 0 || r >= MH || c < 0 || c >= MW) return;
  const tile = map[r][c];
  switch (activeTool) {
    case 'paint': {
      tile.type   = paintType;
      tile.height = DEFAULT_HEIGHT[paintType];
      tile.dec    = VALID_DECO[paintType].includes(tile.dec) ? tile.dec : null;
      tile.e      = tile.height / 4;
      // Asignar textura específica: variante bloqueada en paleta o aleatoria por posición
      const _tKey = _typeIdToKey[paintType];
      tile.tex = lockedTilePng[_tKey] || _randomTexForType(paintType, r, c);
      break;
    }
    case 'height':
      tile.height = Math.max(0, Math.min(4, tile.height + (rightClick ? -1 : heightDirection)));
      break;
    case 'deco': {
      if (activeSpriteDeco) {
        tile.dec = 'sprite:' + activeSpriteDeco;
      } else {
        const opts = VALID_DECO[tile.type];
        const cur  = opts.indexOf(tile.dec);
        tile.dec   = opts[(cur + 1) % opts.length];
      }
      break;
    }
    case 'erase':
      tile.dec = null;
      break;
    case 'terrain': {
      // Throttle: only trigger when cursor has moved at least radius/2 tiles
      const dist = Math.abs(r - _lastTerrainR) + Math.abs(c - _lastTerrainC);
      if (dist < Math.max(1, Math.floor(terrainRadius / 2))) return;
      _lastTerrainR = r; _lastTerrainC = c;
      applyTerrainSculpt(r, c);
      return; // applyTerrainSculpt handles cache+minimap internally
    }
  }
  clearTileTextureCache(r, c);
  updateMinimapTile(r, c);
}

// ============================================================
// TERRAIN SCULPT TOOL
// ============================================================
function terrainFalloff(t) {
  // t = 0 (center) → 1 (edge)
  t = Math.max(0, Math.min(1, t));
  if (terrainShape === 'smooth')  return (1 - t * t) * (1 - t * t); // Gaussian-like (C²)
  if (terrainShape === 'cone')    return 1 - t;
  if (terrainShape === 'plateau') return t < 0.45 ? 1 : t > 0.75 ? 0 : (0.75 - t) / 0.30;
  return 1 - t;
}

function autoTerrainType(h) {
  if (h <= 0) return T.WATER;
  if (h === 1) return T.GRASS;
  if (h === 2) return T.ROCK;
  if (h === 3) return T.MOUNTAIN;
  return T.SNOW;
}

function applyTerrainSculpt(cr, cc) {
  const R = terrainRadius;
  const affected = [];
  for (let dr = -R; dr <= R; dr++) {
    for (let dc = -R; dc <= R; dc++) {
      const r = cr + dr, c = cc + dc;
      if (r < 0 || r >= MH || c < 0 || c >= MW) continue;
      const dist = Math.sqrt(dr * dr + dc * dc);
      if (dist > R) continue;
      const t   = dist / R;
      const fac = terrainFalloff(t);
      const tile = map[r][c];

      let newH;
      if (terrainSculptMode === 'mountain') {
        newH = Math.round(fac * terrainPeakH);
      } else {
        // valley: invert — depress terrain relative to current average (or absolute to 0)
        newH = Math.round((1 - fac) * terrainPeakH);
      }
      newH = Math.max(0, Math.min(4, newH));

      // Only modify height (and optionally type); don't erase decoration
      const changed = tile.height !== newH || (terrainAutoType && tile.type !== autoTerrainType(newH));
      if (!changed) continue;

      tile.height = newH;
      tile.e      = newH / 4;
      if (terrainAutoType) {
        tile.type = autoTerrainType(newH);
        tile.dec  = VALID_DECO[tile.type].includes(tile.dec) ? tile.dec : null;
        const _tKey = _typeIdToKey[tile.type];
        tile.tex = lockedTilePng[_tKey] || _randomTexForType(tile.type, r, c);
      }
      affected.push([r, c]);
    }
  }
  affected.forEach(([r, c]) => { clearTileTextureCache(r, c); updateMinimapTile(r, c); });
}

function setTerrainMode(mode) {
  terrainSculptMode = mode;
  ['mountain','valley'].forEach(m => {
    document.getElementById('tsm-' + m).classList.toggle('active', m === mode);
  });
}

function setTerrainShape(shape) {
  terrainShape = shape;
  ['smooth','cone','plateau'].forEach(s => {
    document.getElementById('tsh-' + s).classList.toggle('active', s === shape);
  });
}

function toggleTerrainAutoType(enabled) {
  terrainAutoType = enabled;
}

// ============================================================
// UNDO / REDO
// ============================================================
function snapshotMap() {
  return map.map(row => row.map(cell => ({t:cell.type, h:cell.height, e:cell.e, d:cell.dec, x:cell.tex||null})));
}

function restoreSnapshot(snap) {
  for (let r = 0; r < MH; r++)
    for (let c = 0; c < MW; c++) {
      map[r][c].type   = snap[r][c].t;
      map[r][c].height = snap[r][c].h;
      map[r][c].e      = snap[r][c].e;
      map[r][c].dec    = snap[r][c].d;
      map[r][c].tex    = snap[r][c].x || null;
    }
}

function pushUndo() {
  undoStack.push(snapshotMap());
  if (undoStack.length > MAX_UNDO) undoStack.shift();
  redoStack.length = 0;
  updateUndoButtons();
}

function undo() {
  if (!undoStack.length) return;
  redoStack.push(snapshotMap());
  restoreSnapshot(undoStack.pop());
  rebuildMinimap();
  updateUndoButtons();
  mapDirty = true;
  updateSaveButton();
}

function redo() {
  if (!redoStack.length) return;
  undoStack.push(snapshotMap());
  restoreSnapshot(redoStack.pop());
  rebuildMinimap();
  updateUndoButtons();
  mapDirty = true;
  updateSaveButton();
}

function updateUndoButtons() {
  document.getElementById('ed-undo').disabled = !undoStack.length;
  document.getElementById('ed-redo').disabled = !redoStack.length;
}

function updateSaveButton() {
  const btn = document.getElementById('ed-save');
  if (btn) btn.textContent = mapDirty ? '💾 Guardar*' : '💾 Guardar';
}

// ============================================================
// SERIALIZACIÓN / CARGA
// ============================================================
function serializeMap() {
  return {
    title : document.getElementById('ed-title').value || 'Sin título',
    seed  : SEED,
    w     : MW,
    h     : MH,
    map   : map.flat().map(cell => [cell.type, cell.height, +cell.e.toFixed(4), cell.dec, cell.tex||null])
  };
}

function applyLoadedMap(data) {
  const newW = data.w || data.width  || MW;
  const newH = data.h || data.height || MH;
  MW = newW; MH = newH;

  document.getElementById('ed-title').value  = data.title || '';
  document.getElementById('h-size').textContent = MW + '×' + MH;

  // Reconstruir map[][]
  map = [];
  for (let r = 0; r < MH; r++) {
    map[r] = [];
    for (let c = 0; c < MW; c++) {
      const idx  = r * MW + c;
      const cell = data.map[idx];
      if (Array.isArray(cell)) {
        map[r][c] = { type:cell[0], height:cell[1], e:cell[2], dec:cell[3]||null, tex:cell[4]||null };
      } else if (cell && typeof cell === 'object') {
        map[r][c] = { type:cell.t??cell.type, height:cell.h??cell.height, e:cell.e, dec:cell.d??cell.dec??null, tex:cell.x??cell.tex??null };
      } else {
        map[r][c] = { type:T.GRASS, height:1, e:0.25, dec:null, tex:null };
      }
    }
  }

  // Redimensionar minimapa
  MM_PX = Math.max(2, Math.floor(160 / Math.max(MW, MH)));
  mmOff.width      = MW * MM_PX;
  mmOff.height     = MH * MM_PX;
  mmCanvas.width   = MW * MM_PX;
  mmCanvas.height  = MH * MM_PX;

  // Limpiar caché de texturas (nuevo mapa = nuevos tiles)
  clearAllTextureCache();

  rebuildMinimap();
  resetCam();

  undoStack.length = 0;
  redoStack.length = 0;
  updateUndoButtons();
  mapDirty = false;
  updateSaveButton();
}

// ============================================================
// GUARDAR / CARGAR (servidor)
// ============================================================
async function saveMap() {
  const title = document.getElementById('ed-title').value.trim() || 'Sin título';
  const data  = serializeMap();
  data.title  = title;
  try {
    const res  = await fetch('isocraft_api.php?action=save', {
      method : 'POST',
      headers: {'Content-Type':'application/json'},
      body   : JSON.stringify(data)
    });
    const json = await res.json();
    if (json.ok) {
      mapDirty = false;
      updateSaveButton();
      showToast('✓ Mapa guardado');
    } else {
      showToast('✗ Error: ' + (json.error || 'desconocido'));
    }
  } catch(err) {
    showToast('✗ Error de red');
  }
}

async function openLoadModal() {
  document.getElementById('load-modal').classList.add('visible');
  const body = document.getElementById('load-modal-body');
  body.innerHTML = '<div class="map-empty">Cargando...</div>';
  try {
    const res  = await fetch('isocraft_api.php?action=list');
    const maps = await res.json();
    if (!maps.length) {
      body.innerHTML = '<div class="map-empty">No hay mapas guardados</div>';
      return;
    }
    body.innerHTML = maps.map(m => `
      <div class="map-card">
        <div class="map-card-info">
          <div class="map-card-title">${escHtml(m.title)}</div>
          <div class="map-card-meta">${m.w}×${m.h} &nbsp;·&nbsp; ${m.saved}</div>
        </div>
        <div class="map-card-btns">
          <button onclick="loadMapFromServer('${escHtml(m.file)}')">📂 Cargar</button>
          <button class="del" onclick="deleteMapFromServer('${escHtml(m.file)}',this)">🗑</button>
        </div>
      </div>`).join('');
  } catch(err) {
    body.innerHTML = '<div class="map-empty">Error cargando lista</div>';
  }
}

function closeLoadModal() {
  document.getElementById('load-modal').classList.remove('visible');
}

async function loadMapFromServer(file) {
  try {
    const res  = await fetch('isocraft_api.php?action=load&file=' + encodeURIComponent(file));
    const data = await res.json();
    if (data.error) { showToast('✗ ' + data.error); return; }
    applyLoadedMap(data);
    closeLoadModal();
    showToast('✓ Mapa cargado');
  } catch(err) {
    showToast('✗ Error cargando mapa');
  }
}

async function deleteMapFromServer(file, btn) {
  if (!confirm('¿Eliminar este mapa?')) return;
  try {
    const res  = await fetch('isocraft_api.php?action=delete', {
      method : 'POST',
      headers: {'Content-Type':'application/json'},
      body   : JSON.stringify({file})
    });
    const json = await res.json();
    if (json.ok) {
      btn.closest('.map-card').remove();
      showToast('✓ Mapa eliminado');
    }
  } catch(err) {
    showToast('✗ Error');
  }
}

// ============================================================
// EXPORT / IMPORT (cliente, sin servidor)
// ============================================================
function exportMap() {
  const data = serializeMap();
  const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = (data.title || 'mapa').replace(/\s+/g,'_') + '.json';
  a.click();
  URL.revokeObjectURL(url);
}

function importMap() {
  const input    = document.createElement('input');
  input.type     = 'file';
  input.accept   = '.json';
  input.onchange = e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = ev => {
      try {
        applyLoadedMap(JSON.parse(ev.target.result));
        showToast('✓ Mapa importado');
      } catch(err) {
        showToast('✗ JSON inválido');
      }
    };
    reader.readAsText(file);
  };
  input.click();
}

function newMap() {
  const title = prompt('Título del nuevo mapa:', 'Nuevo Mapa');
  if (title === null) return;
  const wStr  = prompt('Ancho (20-60):', '40');
  if (wStr  === null) return;
  const hStr  = prompt('Alto (20-60):', '40');
  if (hStr  === null) return;
  const w     = Math.min(60, Math.max(20, parseInt(wStr) || 40));
  const h     = Math.min(60, Math.max(20, parseInt(hStr) || 40));
  const seed  = Math.floor(Math.random() * 999999);
  location.href = `?seed=${seed}&w=${w}&h=${h}&title=${encodeURIComponent(title.substring(0,64))}`;
}

// ── Utilidades ────────────────────────────────────────────────
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let _toastTimer = null;
function showToast(msg) {
  const el = document.getElementById('tinfo');
  el.textContent = msg;
  if (_toastTimer) clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => { el.textContent = 'Haz clic en un tile para ver información'; }, 3000);
}

// ============================================================
// INICIALIZACIÓN DEL EDITOR UI
// ============================================================
(function initEditorUI() {
  // Título inicial desde URL
  document.getElementById('ed-title').value = mapTitle;
  // El terrain grid se construye en initPngSystem → _buildEditorTilePalette
})();

// ============================================================
// BARRA INFERIOR — MENÚ Y TOGGLES
// ============================================================
function toggleBottomMenu() {
  const menu = document.getElementById('bottom-menu');
  const btn  = document.getElementById('menu-btn');
  const open = menu.classList.toggle('open');
  btn.classList.toggle('open', open);
  btn.textContent = open ? '\u2699 Opciones \u25BC' : '\u2699 Opciones \u25B2';
}

document.addEventListener('click', e => {
  const bar = document.getElementById('bar-right');
  if (bar && !bar.contains(e.target)) {
    document.getElementById('bottom-menu').classList.remove('open');
    const btn = document.getElementById('menu-btn');
    if (btn) { btn.classList.remove('open'); btn.textContent = '\u2699 Opciones \u25B2'; }
  }
});

function toggleWaterAnim() {
  waterAnimEnabled = !waterAnimEnabled;
  const btn = document.getElementById('opt-water');
  btn.textContent = waterAnimEnabled ? 'ON' : 'OFF';
  btn.classList.toggle('on', waterAnimEnabled);
}

function toggleDayNight() {
  dayNightEnabled = !dayNightEnabled;
  const btn = document.getElementById('opt-day');
  btn.textContent = dayNightEnabled ? 'ON' : 'OFF';
  btn.classList.toggle('on', dayNightEnabled);
}

// ============================================================
// SISTEMA PNG — TILES Y SPRITES
// ============================================================

// ── Estado global PNG ─────────────────────────────────────────
const tileImages   = new Map(); // filename → HTMLImageElement
const spriteImages = new Map(); // 'cat/name' → HTMLImageElement
let activeSpriteDeco = null;    // 'cat/name' activo para colocar, o null

// Tile "bloqueado" por terreno: si el usuario elige una variante específica
// { 'GRASS': 'cesped_r1_c3', 'JUNGLE': null, ... }
const lockedTilePng = {};

// Devuelve HTMLImageElement ya cargada para el terreno del tile (r,c) — fallback hash-based
// (los tiles con cell.tex explícita se resuelven directamente en getOrBuildTexture)
function _getPngTex(typeId, r, c) {
  const key  = _typeIdToKey[typeId];
  const list = terrainToPngs[key];
  if (!list || list.length === 0) return null;
  const imgName = list[Math.abs(tileHash(r, c, 77) | 0) % list.length];
  const img = tileImages.get(imgName);
  return (img && img.complete && img.naturalWidth > 0) ? img : null;
}

// Precarga todas las imágenes PNG
function initPngSystem() {
  // Tiles
  for (const name of TILE_FILES) {
    const img = new Image();
    img.src = `assets/tiles/${name}.png`;
    tileImages.set(name, img);
  }
  // Sprites
  for (const key of SPRITE_FILES) {
    const img = new Image();
    img.src = `assets/sprites/${key}.png`;
    spriteImages.set(key, img);
  }
  _buildEditorTilePalette();
  _buildEditorSpritePalette();
}

// ── Selector de terreno (botones grandes con variantes de PNG) ──

// Datos de terreno para los botones del panel
const ED_TERRAIN_DATA = [
  { key:'DEEP',     typeId:T.DEEP,     color:'#142d5e', emoji:'🌊', name:'Profundo' },
  { key:'WATER',    typeId:T.WATER,    color:'#1e5296', emoji:'💧', name:'Agua'     },
  { key:'SAND',     typeId:T.SAND,     color:'#c8a96e', emoji:'🏖', name:'Arena'    },
  { key:'GRASS',    typeId:T.GRASS,    color:'#5a8a3c', emoji:'🌿', name:'Césped'   },
  { key:'JUNGLE',   typeId:T.JUNGLE,   color:'#1f5c1f', emoji:'🌴', name:'Jungla'   },
  { key:'ROCK',     typeId:T.ROCK,     color:'#7a6a5a', emoji:'⛰',  name:'Roca'     },
  { key:'MOUNTAIN', typeId:T.MOUNTAIN, color:'#b0a898', emoji:'🏔', name:'Montaña'  },
  { key:'SNOW',     typeId:T.SNOW,     color:'#e8eef8', emoji:'❄',  name:'Nieve'    },
];

let _activeTerrainKey = 'GRASS';

function _buildEditorTilePalette() {
  const grid = document.getElementById('ed-terrain-grid');
  if (!grid) return;
  grid.innerHTML = '';

  for (const td of ED_TERRAIN_DATA) {
    const btn = document.createElement('button');
    btn.className = 'ed-terrain-btn' + (td.key === _activeTerrainKey ? ' active' : '');
    btn.dataset.key = td.key;
    btn.title = td.name;
    btn.innerHTML = `
      <div class="tb-dot" style="background:${td.color}"></div>
      <span class="tb-emoji">${td.emoji}</span>
      <span class="tb-name">${td.name}</span>`;
    btn.addEventListener('click', () => selectTerrainKey(td.key));
    grid.appendChild(btn);
  }

  // Construir variantes para el terreno activo inicial
  _buildTileVariants(_activeTerrainKey);
}

function selectTerrainKey(key) {
  _activeTerrainKey = key;
  const td = ED_TERRAIN_DATA.find(d => d.key === key);
  if (!td) return;

  // Actualizar paintType + herramienta
  paintType = td.typeId;
  setTool('paint');

  // Actualizar botones de terreno activos
  document.querySelectorAll('.ed-terrain-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.key === key);
  });

  // Mostrar variantes de tile para este terreno
  _buildTileVariants(key);

  showToast(`🖌 Terreno: ${td.emoji} ${td.name}`);
}

function _buildTileVariants(terrainKey) {
  const container = document.getElementById('ed-tile-variants');
  const section   = document.getElementById('ed-variants-section');
  const label     = document.getElementById('ed-variants-label');
  if (!container) return;

  const list = terrainToPngs[terrainKey] || [];
  if (list.length === 0) {
    section.style.display = 'none';
    return;
  }

  const td = ED_TERRAIN_DATA.find(d => d.key === terrainKey);
  if (label && td) label.textContent = `Variante — ${td.emoji} ${td.name}`;
  section.style.display = '';
  container.innerHTML   = '';

  const locked = lockedTilePng[terrainKey] || null;

  // Opción "Aleatoria"
  const rnd = document.createElement('div');
  rnd.className = 'ed-tile-rnd' + (!locked ? ' active' : '');
  rnd.textContent = '🎲 Aleatoria (variedad por tile)';
  rnd.addEventListener('click', () => {
    lockedTilePng[terrainKey] = null;
    _buildTileVariants(terrainKey);
    showToast('🎲 Pincel: variante aleatoria por posición');
  });
  container.appendChild(rnd);

  // Grid de variantes específicas
  for (const name of list) {
    const cell = document.createElement('div');
    const isActive = name === locked;
    cell.className = 'ed-tile-cell' + (isActive ? ' active' : '');
    cell.title = name;
    cell.dataset.file = name;

    const img = document.createElement('img');
    img.src = `assets/tiles/${name}.png`;
    img.loading = 'lazy';

    const lock = document.createElement('span');
    lock.className = 'tc-lock';
    lock.textContent = '📌';

    cell.appendChild(img);
    cell.appendChild(lock);

    cell.addEventListener('click', () => {
      if (lockedTilePng[terrainKey] === name) {
        lockedTilePng[terrainKey] = null;
        showToast('🎲 Pincel: variante aleatoria');
      } else {
        lockedTilePng[terrainKey] = name;
        showToast(`📌 Pincel: ${name}`);
      }
      _buildTileVariants(terrainKey);
    });

    container.appendChild(cell);
  }
}

// Mantener compatibilidad con el botón de apertura de paleta grande
function filterEditorTiles() { /* no-op — reemplazado por _buildTileVariants */ }

// ── Paleta de sprites deco en el panel del editor ─────────────
function _buildEditorSpritePalette() {
  const grid = document.getElementById('ed-sprite-grid');
  if (!grid) return;
  grid.innerHTML = '';
  for (const key of SPRITE_FILES) {
    const info  = TILE_MAP.sprites[key] || {};
    const cell  = document.createElement('div');
    cell.className   = 'ed-sprite-cell';
    cell.title       = key;
    cell.dataset.key = key;

    const img = document.createElement('img');
    img.src   = `assets/sprites/${key}.png`;

    const lbl = document.createElement('div');
    lbl.className   = 'ed-sprite-label';
    lbl.textContent = key.split('/')[1] || key;

    cell.appendChild(img);
    cell.appendChild(lbl);

    cell.addEventListener('click', () => {
      if (activeSpriteDeco === key) {
        activeSpriteDeco = null;
        cell.classList.remove('active');
      } else {
        document.querySelectorAll('.ed-sprite-cell').forEach(el => el.classList.remove('active'));
        activeSpriteDeco = key;
        cell.classList.add('active');
        setTool('deco');
      }
    });
    grid.appendChild(cell);
  }
}

// ============================================================
// PALETA GRANDE DE TILES (MODAL FULLSCREEN)
// ============================================================

const TP_TERRAIN_META = {
  DEEP:     { label:'🌊 Agua Profunda', color:'#142d5e' },
  WATER:    { label:'💧 Agua',          color:'#1e5296' },
  SAND:     { label:'🏖 Arena',         color:'#c8a96e' },
  GRASS:    { label:'🌿 Césped',        color:'#5a8a3c' },
  JUNGLE:   { label:'🌴 Jungla',        color:'#1f5c1f' },
  ROCK:     { label:'⛰ Roca',          color:'#7a6a5a' },
  MOUNTAIN: { label:'🏔 Montaña',       color:'#b0a898' },
  SNOW:     { label:'❄ Nieve',          color:'#e8eef8' },
};
const TP_ORDER = ['DEEP','WATER','SAND','GRASS','JUNGLE','ROCK','MOUNTAIN','SNOW'];

let _tpBuilt  = false;
let _tpFilter = 'all';

function openTilePalette() {
  const modal = document.getElementById('tp-modal');
  if (modal) {
    if (!_tpBuilt) { _buildLargePalette(); _tpBuilt = true; }
    modal.classList.add('open');
  }
}

function closeTilePalette() {
  const modal = document.getElementById('tp-modal');
  if (modal) modal.classList.remove('open');
}

// Cierra con Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeTilePalette();
});

function tpFilter(f) {
  _tpFilter = f;
  document.querySelectorAll('.tp-filter-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.f === f);
  });
  document.querySelectorAll('.tp-section').forEach(sec => {
    const terrain = sec.dataset.terrain;
    if (f === 'all') {
      sec.style.display = '';
    } else if (f === '__none__') {
      sec.style.display = terrain === '__none__' ? '' : 'none';
    } else {
      sec.style.display = terrain === f ? '' : 'none';
    }
  });
}

function _buildLargePalette() {
  const body = document.getElementById('tp-body');
  if (!body) return;
  body.innerHTML = '';

  // Agrupar tiles por terreno
  const groups = {}; // terrain → [name, name, ...]
  groups['__none__'] = [];
  for (const key of TP_ORDER) groups[key] = [];

  for (const name of TILE_FILES) {
    const info    = TILE_MAP.tiles[name] || {};
    const terrain = info.terrain || '__none__';
    if (!groups[terrain]) groups[terrain] = [];
    groups[terrain].push(name);
  }

  // Renderizar sección por terreno
  const allSections = [
    ...TP_ORDER.map(k => ({ key: k, meta: TP_TERRAIN_META[k] })),
    { key: '__none__', meta: { label: '❔ Sin asignar', color: '#445' } },
  ];

  for (const { key, meta } of allSections) {
    const names = groups[key] || [];
    if (names.length === 0) continue;

    const sec = document.createElement('div');
    sec.className      = 'tp-section';
    sec.dataset.terrain = key;

    // Header del grupo
    const hdr = document.createElement('div');
    hdr.className = 'tp-section-hdr';
    hdr.innerHTML = `
      <div class="tp-section-dot" style="background:${meta.color}"></div>
      <span>${meta.label}</span>
      <span class="tp-section-count">${names.length} tile${names.length !== 1 ? 's' : ''}</span>`;
    sec.appendChild(hdr);

    // Grid de tiles
    const grid = document.createElement('div');
    grid.className = 'tp-grid';

    for (const name of names) {
      const info    = TILE_MAP.tiles[name] || {};
      const terrain = info.terrain || null;
      const label   = info.label   || '';

      const cell = document.createElement('div');
      cell.className       = 'tp-tile' + (terrain ? '' : ' unassigned');
      cell.title           = name + (label ? '\n' + label : '') + (terrain ? '\nTerreno: ' + terrain : '\nSin terreno asignado');
      cell.dataset.file    = name;
      cell.dataset.terrain = terrain || '__none__';

      // Imagen
      const wrap = document.createElement('div');
      wrap.className = 'tp-img-wrap';
      const img = document.createElement('img');
      img.src     = `assets/tiles/${name}.png`;
      img.loading = 'lazy';
      img.alt     = name;
      wrap.appendChild(img);

      // Nombre del archivo
      const nameEl = document.createElement('div');
      nameEl.className   = 'tp-tile-name';
      nameEl.textContent = name.replace('tile_', '').replace('_', '·');

      cell.appendChild(wrap);
      cell.appendChild(nameEl);

      // Badge de terreno si asignado
      if (terrain) {
        const badge = document.createElement('div');
        badge.className   = 'tp-tile-terrain';
        badge.style.background = meta.color + '40';
        badge.style.color      = meta.color === '#e8eef8' ? '#334' : meta.color;
        badge.style.border     = '1px solid ' + meta.color + '66';
        badge.textContent = terrain;
        cell.appendChild(badge);
      }

      // Click → seleccionar terreno + bloquear esta variante específica
      cell.addEventListener('click', () => {
        if (!terrain) return;
        // Bloquear esta variante para el terreno
        lockedTilePng[terrain] = name;
        clearAllTextureCache();
        // Actualizar selector de terreno en el panel lateral
        selectTerrainKey(terrain);
        // Destacar en paleta grande
        document.querySelectorAll('.tp-tile').forEach(el => el.classList.remove('active'));
        cell.classList.add('active');
        closeTilePalette();
        const td = ED_TERRAIN_DATA.find(d => d.key === terrain);
        showToast(`📌 ${td ? td.emoji + ' ' + td.name : terrain} — ${name}`);
      });

      grid.appendChild(cell);
    }

    sec.appendChild(grid);
    body.appendChild(sec);
  }
}

// ============================================================
// INICIO
// ============================================================
window.addEventListener('resize', resize);
resize();
initPngSystem();
requestAnimationFrame(loop);

// Paleta fallback (escala de grises) — se reemplaza al cargar interfac.drs
const AOE_FALLBACK_PAL = (() => {
  const p = new Uint8Array(256 * 4);
  for (let i = 0; i < 256; i++) { p[i*4]=i; p[i*4+1]=i; p[i*4+2]=i; p[i*4+3]=255; }
  return p;
})();

// ── DRS Parser ───────────────────────────────────────────────
class DrsBinary {
  constructor(buffer) {
    this.buf = buffer;
    this.dv  = new DataView(buffer);
    this.entries = new Map(); // id → {offset, size, ext}
    this._parse();
  }
  _parse() {
    // Header: 36 (copyright) + 4 (version str) + 12 (type) + 4 (num_tables) + 4 (files_offset) = 60
    const numTables = this.dv.getUint32(52, true);
    let hOff = 60; // tabla de headers empieza aquí
    for (let t = 0; t < numTables; t++) {
      // Extensión almacenada en orden invertido + relleno de espacios
      const ext = [
        this.dv.getUint8(hOff+3), this.dv.getUint8(hOff+2),
        this.dv.getUint8(hOff+1), this.dv.getUint8(hOff+0)
      ].map(b => b > 32 ? String.fromCharCode(b) : '').join('');
      const entriesOff = this.dv.getUint32(hOff+4, true);
      const numFiles   = this.dv.getUint32(hOff+8, true);
      hOff += 12;
      for (let f = 0; f < numFiles; f++) {
        const e      = entriesOff + f * 12;
        const id     = this.dv.getUint32(e,   true);
        const offset = this.dv.getUint32(e+4, true);
        const size   = this.dv.getUint32(e+8, true);
        this.entries.set(id, { offset, size, ext });
      }
    }
  }
  getBytes(id) {
    const e = this.entries.get(id);
    if (!e) return null;
    return new Uint8Array(this.buf, e.offset, e.size);
  }
  listByExt(ext) {
    const r = [];
    for (const [id, e] of this.entries)
      if (e.ext === ext) r.push({ id, size: e.size });
    return r.sort((a, b) => a.id - b.id);
  }
}

// ── SLP Decoder v2.0N ────────────────────────────────────────
class SlpDecoder {
  constructor(bytes, palette) {
    this.b   = bytes;
    this.dv  = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    this.pal = palette || AOE_FALLBACK_PAL;
    this.numFrames = 0;
    this.frames    = [];
    this._parseHeader();
  }
  _parseHeader() {
    if (this.b.length < 32) return;
    this.numFrames = Math.min(this.dv.getInt32(4, true), 512);
    for (let i = 0; i < this.numFrames; i++) {
      const base = 32 + i * 32;
      if (base + 32 > this.b.length) break;
      this.frames.push({
        cmdOff: this.dv.getUint32(base,    true),
        outOff: this.dv.getUint32(base+4,  true),
        w:      this.dv.getInt32(base+16,  true),
        h:      this.dv.getInt32(base+20,  true),
        hotX:   this.dv.getInt32(base+24,  true),
        hotY:   this.dv.getInt32(base+28,  true),
      });
    }
  }
  renderFrame(fi = 0) {
    if (fi >= this.frames.length) return null;
    const f = this.frames[fi];
    const { w, h, hotX, hotY } = f;
    if (w <= 0 || h <= 0 || w > 2048 || h > 2048) return null;

    const off    = document.createElement('canvas');
    off.width    = w; off.height = h;
    const offCtx = off.getContext('2d');
    const img    = offCtx.createImageData(w, h);
    const px     = img.data;
    const b      = this.b;
    const pal    = this.pal;
    const dv     = this.dv;

    for (let y = 0; y < h; y++) {
      const ot = f.outOff + y * 4;
      if (ot + 3 >= b.length) break;
      const leftSkip  = dv.getUint16(ot,   true);
      const rightSkip = dv.getUint16(ot+2, true);
      if (leftSkip === 0x8000) continue; // fila totalmente transparente

      let x    = leftSkip;
      const ex = w - rightSkip;
      let pos  = dv.getUint32(f.cmdOff + y * 4, true);
      if (pos >= b.length) continue;

      rowLoop: while (x < ex && pos < b.length) {
        const cmd = b[pos++];
        const lo  = cmd & 0x0F;
        let cnt   = cmd >> 4;
        if (cnt === 0 && lo !== 0x0F) { cnt = b[pos++] || 0; }

        if      (lo === 0x00 || lo === 0x04) { x += cnt; }
        else if (lo === 0x01 || lo === 0x05) {
          // Dibujar cnt pixels de paleta
          for (let i = 0; i < cnt && x < ex; i++, x++) {
            const pi = (b[pos++] || 0) * 4;
            const px4 = (y*w+x)*4;
            px[px4]=pal[pi]; px[px4+1]=pal[pi+1]; px[px4+2]=pal[pi+2]; px[px4+3]=255;
          }
        } else if (lo === 0x02 || lo === 0x06) {
          // Color de jugador (usamos el índice tal cual de la paleta)
          for (let i = 0; i < cnt && x < ex; i++, x++) {
            const pi = (b[pos++] || 0) * 4;
            const px4 = (y*w+x)*4;
            px[px4]=pal[pi]; px[px4+1]=pal[pi+1]; px[px4+2]=pal[pi+2]; px[px4+3]=255;
          }
        } else if (lo === 0x03 || lo === 0x07) {
          // Rellenar cnt pixels con un solo color
          const pi = (b[pos++] || 0) * 4;
          for (let i = 0; i < cnt && x < ex; i++, x++) {
            const px4 = (y*w+x)*4;
            px[px4]=pal[pi]; px[px4+1]=pal[pi+1]; px[px4+2]=pal[pi+2]; px[px4+3]=255;
          }
        } else if (lo === 0x08 || lo === 0x09 || lo === 0x0A) {
          x += cnt; // sombra / shadow — skip
        } else if (lo === 0x0B)           { x++;       } // outline px
        else if (lo === 0x0C || lo === 0x0D) { x += cnt; } // outline span
        else if (lo === 0x0E)             { pos++; x += cnt; } // extendido
        else if (lo === 0x0F)             { break rowLoop; } // fin de fila
        else                              { break rowLoop; } // desconocido
      }
    }
    offCtx.putImageData(img, 0, 0);
    return { canvas: off, hotX, hotY, width: w, height: h };
  }
  renderAllFrames() {
    const results = [];
    for (let i = 0; i < this.frames.length; i++) {
      const r = this.renderFrame(i);
      if (r) results.push(r);
    }
    return results;
  }
}

// ── Cargador de paleta ───────────────────────────────────────
function parsePaletteBytes(bytes) {
  // Detectar JASC-PAL (texto)
  try {
    const head = new TextDecoder('ascii', { fatal: true }).decode(bytes.slice(0, 9));
    if (head.startsWith('JASC-PAL')) {
      const lines = new TextDecoder().decode(bytes).split('\n');
      const pal = new Uint8Array(256 * 4);
      let j = 0;
      for (const line of lines) {
        const p = line.trim().split(/\s+/);
        if (p.length >= 3 && /^\d+$/.test(p[0]) && j < 256) {
          pal[j*4]=+p[0]; pal[j*4+1]=+p[1]; pal[j*4+2]=+p[2]; pal[j*4+3]=255; j++;
        }
      }
      return j >= 16 ? pal : null;
    }
  } catch(e) {}
  // Binario: 256 × 3 (RGB) o 256 × 4 (RGBA)
  if (bytes.length < 48) return null;
  const pal = new Uint8Array(256 * 4);
  const stride = bytes.length >= 1024 ? 4 : 3;
  for (let i = 0; i < 256 && (i+1)*stride <= bytes.length; i++) {
    pal[i*4]=bytes[i*stride]; pal[i*4+1]=bytes[i*stride+1];
    pal[i*4+2]=bytes[i*stride+2]; pal[i*4+3]=255;
  }
  return pal;
}

// ── Cargar archivos DRS ──────────────────────────────────────
async function loadAoeFiles() {
  const drsInput = document.getElementById('aoe-drs-input');
  const palInput = document.getElementById('aoe-pal-input');
  if (!drsInput.files.length) { showToast('✗ Selecciona un archivo .drs'); return; }

  const btn = document.getElementById('aoe-load-btn');
  btn.textContent = '⏳ Procesando…'; btn.disabled = true;

  try {
    // 1. Parsear DRS principal
    const drsBuf = await drsInput.files[0].arrayBuffer();
    aoeDrsFile = new DrsBinary(drsBuf);

    // 2. Intentar extraer paleta del DRS principal (interfac.drs tiene ID 50500)
    const palIds = [50500, 50501, 50530, 50532];
    for (const pid of palIds) {
      const pb = aoeDrsFile.getBytes(pid);
      if (pb) { const p = parsePaletteBytes(pb); if (p) { aoePal = p; break; } }
    }

    // 3. Paleta explícita (interfac.drs separado o .pal/.bin)
    if (palInput.files.length) {
      const palBuf = await palInput.files[0].arrayBuffer();
      const raw    = new Uint8Array(palBuf);
      // ¿Es un DRS?
      try {
        const palDrs = new DrsBinary(palBuf);
        for (const pid of palIds) {
          const pb = palDrs.getBytes(pid);
          if (pb) { const p = parsePaletteBytes(pb); if (p) { aoePal = p; break; } }
        }
      } catch(e) {}
      // ¿Es directamente una paleta binaria/texto?
      if (!aoePal) { const p = parsePaletteBytes(raw); if (p) aoePal = p; }
    }

    if (!aoePal) aoePal = AOE_FALLBACK_PAL;

    // 4. Listar todos los SLPs
    aoeSlpList    = aoeDrsFile.listByExt('slp');
    aoeCanvasCache.clear();
    aoeSelectedId = null;
    aoePreviewFrame = 0;

    _showAoeBrowser();

    // Actualizar estado en menú
    document.getElementById('aoe-status-row').style.display = '';
    document.getElementById('aoe-status-lbl').textContent =
      `✓ ${aoeSlpList.length} sprites cargados`;

  } catch(err) {
    showToast('✗ Error: ' + err.message);
    console.error(err);
  }
  btn.textContent = '⚡ Procesar'; btn.disabled = false;
}

// ── Browser de sprites ───────────────────────────────────────
function _showAoeBrowser() {
  document.getElementById('aoe-step-load').style.display = 'none';
  const browse = document.getElementById('aoe-step-browse');
  browse.style.display = 'flex';
  document.getElementById('aoe-count-lbl').textContent = aoeSlpList.length + ' sprites SLP';
  document.getElementById('aoe-preview-panel').style.display = 'none';

  _renderAoeGrid(aoeSlpList);

  const search = document.getElementById('aoe-id-search');
  search.value = '';
  search.oninput = () => {
    const q = search.value.trim();
    const filtered = q ? aoeSlpList.filter(s => String(s.id).includes(q)) : aoeSlpList;
    _renderAoeGrid(filtered);
  };
}

function _renderAoeGrid(list) {
  const grid = document.getElementById('aoe-sprite-grid');
  grid.innerHTML = '';
  const PAGE = 120;
  let shown = 0;

  function renderPage() {
    const batch = list.slice(shown, shown + PAGE);
    for (const { id } of batch) {
      const cell = document.createElement('div');
      cell.className = 'aoe-cell';
      cell.dataset.id = id;
      if (id === aoeSelectedId) cell.classList.add('selected');
      // Thumbnail: si ya está en caché, mostrar frame 0
      if (aoeCanvasCache.has(id)) {
        _fillCellThumb(cell, id);
      } else {
        cell.innerHTML = `<div class="aoe-cell-id">${id}</div>`;
      }
      cell.onclick = () => _selectAoeSprite(id, cell);
      grid.appendChild(cell);
    }
    shown += batch.length;
    if (shown < list.length) {
      const more = document.createElement('button');
      more.className = 'ed-action-btn';
      more.style.cssText = 'margin:6px auto;display:block;width:90%';
      more.textContent = `⬇ Más (${list.length - shown} restantes)`;
      more.onclick = () => { more.remove(); renderPage(); };
      grid.appendChild(more);
    }
  }
  renderPage();
}

function _fillCellThumb(cell, id) {
  const frames = aoeCanvasCache.get(id);
  if (!frames || !frames.length) return;
  const r = frames[0];
  const maxSz = 52;
  const scale = Math.min(maxSz / r.width, maxSz / r.height, 1);
  const thumb = document.createElement('canvas');
  thumb.width  = Math.max(1, Math.round(r.width  * scale));
  thumb.height = Math.max(1, Math.round(r.height * scale));
  const tc = thumb.getContext('2d');
  tc.imageSmoothingEnabled = false;
  tc.drawImage(r.canvas, 0, 0, thumb.width, thumb.height);
  cell.innerHTML = '';
  const idLbl = document.createElement('div');
  idLbl.className = 'aoe-cell-id'; idLbl.textContent = id;
  cell.appendChild(thumb); cell.appendChild(idLbl);
}

function _selectAoeSprite(id, cellEl) {
  document.querySelectorAll('#aoe-sprite-grid .aoe-cell.selected')
    .forEach(el => el.classList.remove('selected'));
  cellEl.classList.add('selected');
  aoeSelectedId   = id;
  aoePreviewFrame = 0;

  // Renderizar si no está en caché
  if (!aoeCanvasCache.has(id)) {
    const bytes = aoeDrsFile.getBytes(id);
    if (!bytes) { showToast('✗ SLP no encontrado'); return; }
    try {
      const dec    = new SlpDecoder(bytes, aoePal);
      const frames = dec.renderAllFrames();
      if (!frames.length) { showToast('✗ SLP vacío'); return; }
      aoeCanvasCache.set(id, frames);
      _fillCellThumb(cellEl, id);
    } catch(err) {
      console.warn('SLP decode error', id, err);
      showToast('✗ Error decodificando SLP #' + id);
      return;
    }
  }
  _updateAoePreview();
}

function _updateAoePreview() {
  const panel = document.getElementById('aoe-preview-panel');
  if (!aoeSelectedId) { panel.style.display = 'none'; return; }
  const frames = aoeCanvasCache.get(aoeSelectedId);
  if (!frames || !frames.length) { panel.style.display = 'none'; return; }
  panel.style.display = 'flex';

  const r = frames[Math.min(aoePreviewFrame, frames.length-1)];
  const maxW = 172, maxH = 172;
  const scale = Math.min(maxW / r.width, maxH / r.height, 1);
  const pvc = document.getElementById('aoe-preview-canvas');
  pvc.width  = Math.max(1, Math.round(r.width  * scale));
  pvc.height = Math.max(1, Math.round(r.height * scale));
  const pc = pvc.getContext('2d');
  pc.imageSmoothingEnabled = false;
  pc.clearRect(0, 0, pvc.width, pvc.height);
  pc.drawImage(r.canvas, 0, 0, pvc.width, pvc.height);

  document.getElementById('aoe-preview-info').textContent =
    `SLP #${aoeSelectedId} · ${r.width}×${r.height}px\n` +
    `Hotspot (${r.hotX}, ${r.hotY}) · Frame ${aoePreviewFrame+1}/${frames.length}`;

  document.getElementById('aoe-prev-frame-btn').disabled = aoePreviewFrame === 0;
  document.getElementById('aoe-next-frame-btn').disabled = aoePreviewFrame >= frames.length-1;
}

function aoePreviewPrevFrame() { if (aoePreviewFrame > 0) { aoePreviewFrame--; _updateAoePreview(); } }
function aoePreviewNextFrame() {
  const frames = aoeCanvasCache.get(aoeSelectedId);
  if (frames && aoePreviewFrame < frames.length-1) { aoePreviewFrame++; _updateAoePreview(); }
}

function aoeAddToEditor() {
  if (!aoeSelectedId || !aoeCanvasCache.has(aoeSelectedId)) {
    showToast('✗ Selecciona un sprite primero'); return;
  }
  const id  = aoeSelectedId;
  const key = 'aoe_' + id;
  if (!aoeEditorSet.has(id)) {
    const frames = aoeCanvasCache.get(id);
    const r      = frames[0];
    // Chip en el panel del editor
    const chip  = document.createElement('div');
    chip.className = 'aoe-chip' + (aoeActiveDeco === key ? ' active' : '');
    chip.title   = 'SLP #' + id;
    chip.dataset.key = key;
    const maxSz = 26;
    const sc    = Math.min(maxSz/r.width, maxSz/r.height, 1);
    const th    = document.createElement('canvas');
    th.width    = Math.max(1, Math.round(r.width*sc));
    th.height   = Math.max(1, Math.round(r.height*sc));
    const tc    = th.getContext('2d');
    tc.imageSmoothingEnabled = false;
    tc.drawImage(r.canvas, 0, 0, th.width, th.height);
    chip.appendChild(th);
    chip.onclick = () => {
      aoeActiveDeco = (aoeActiveDeco === key) ? null : key;
      document.querySelectorAll('.aoe-chip').forEach(c => c.classList.remove('active'));
      if (aoeActiveDeco) { chip.classList.add('active'); setTool('paint'); }
    };
    document.getElementById('ed-aoe-chips').appendChild(chip);
    document.getElementById('ed-aoe-section').style.display = '';
    aoeEditorSet.set(id, chip);
  }
  showToast(`✓ SLP #${id} añadido al editor`);
  closeAoeModal();
}

function aoeResetLoad() {
  document.getElementById('aoe-step-browse').style.display = 'none';
  document.getElementById('aoe-step-load').style.display   = '';
  document.getElementById('aoe-preview-panel').style.display = 'none';
}

function openAoeModal()  { document.getElementById('aoe-modal').classList.add('visible'); }
function closeAoeModal() { document.getElementById('aoe-modal').classList.remove('visible'); }

// ── Renderizar sprite AoE en el canvas principal ──────────────
function _drawAoeSprite(key, cx, cy, zoomLvl) {
  const id     = parseInt(key.slice(4));
  const frames = aoeCanvasCache.get(id);
  if (!frames || !frames.length) return;
  // Ciclar frames del sprite con la animación del juego
  const fi = Math.floor(frame * 0.1) % frames.length;
  const r  = frames[fi];
  if (!r) return;

  // Escalar: la base del sprite (hotspotY) debe quedar en cy
  // Altura objetivo: aprox 2.5 alturas de tile
  const targetH = TH * zoomLvl * 2.5;
  const scale   = Math.min(targetH / Math.max(r.hotY, r.height * 0.5, 1), zoomLvl * 3);
  const sw = r.width  * scale;
  const sh = r.height * scale;
  const dx = cx - r.hotX * scale;
  const dy = cy - r.hotY * scale;

  ctx.drawImage(r.canvas, dx, dy, sw, sh);
}

// ── Aplicar deco AoE al tile seleccionado en modo editor ──────
// (llamado desde mouseup cuando aoeActiveDeco está activo)
function applyAoeDecoAt(r, c) {
  if (r < 0 || r >= MH || c < 0 || c >= MW) return;
  pushUndo();
  map[r][c].dec = aoeActiveDeco;
  updateMinimapTile(r, c);
  mapDirty = true;
  updateSaveButton();
}

// ============================================================
// INICIO
// ============================================================
window.addEventListener('resize', resize);
resize();
requestAnimationFrame(loop);
</script>
</body>
</html>
