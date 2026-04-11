<?php
// ARCHIVO: terminal.php
// Terminal web SSH/tmux — frontend

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
<title>Terminal — PalWeb</title>
<link rel="stylesheet" href="assets/css/xterm.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --sidebar-w: 220px;
    --topbar-h: 42px;
    --keysbar-h: 44px;
    --statusbar-h: 26px;
    --bg-app: #171717;
    --bg-sidebar: #111111;
    --bg-term: #1a1a1a;
    --bg-input: #212121;
    --accent: #10a37f;
    --accent-hover: #1abc97;
    --text-main: #ececec;
    --text-muted: #888;
    --border: #2a2a2a;
    --session-active: #10a37f22;
    --session-active-border: #10a37f;
    --danger: #e74c3c;
    --key-bg: #2a2a2a;
    --key-hover: #383838;
  }

  html, body {
    height: 100%; width: 100%;
    background: var(--bg-app);
    color: var(--text-main);
    font-family: 'Segoe UI', system-ui, sans-serif;
    overflow: hidden;
  }

  /* ── Layout shell ───────────────────────────────────────── */
  #app {
    display: flex;
    height: 100vh;
    width: 100vw;
  }

  /* ── Sidebar ────────────────────────────────────────────── */
  #sidebar {
    width: var(--sidebar-w);
    min-width: var(--sidebar-w);
    background: var(--bg-sidebar);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: width .2s;
  }
  #sidebar.collapsed { width: 0; min-width: 0; }

  .sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 12px 10px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .sidebar-header h2 {
    font-size: .8rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 600;
  }
  #btn-new-session {
    background: var(--accent);
    border: none;
    color: #fff;
    width: 26px; height: 26px;
    border-radius: 6px;
    font-size: 1.1rem;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
    flex-shrink: 0;
  }
  #btn-new-session:hover { background: var(--accent-hover); }

  #session-list {
    flex: 1;
    overflow-y: auto;
    padding: 6px 0;
  }
  #session-list::-webkit-scrollbar { width: 4px; }
  #session-list::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }

  .sess-item {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: background .1s, border-color .1s;
    user-select: none;
    gap: 6px;
  }
  .sess-item:hover { background: #1c1c1c; }
  .sess-item.active {
    background: var(--session-active);
    border-left-color: var(--session-active-border);
  }
  .sess-icon {
    width: 28px; height: 28px;
    background: #2a2a2a;
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem;
    flex-shrink: 0;
  }
  .sess-item.active .sess-icon { background: #10a37f44; }
  .sess-name {
    flex: 1;
    font-size: .82rem;
    color: var(--text-main);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .sess-kill {
    background: none;
    border: none;
    color: #555;
    cursor: pointer;
    font-size: .75rem;
    padding: 2px 4px;
    border-radius: 4px;
    transition: color .1s, background .1s;
    flex-shrink: 0;
  }
  .sess-kill:hover { color: var(--danger); background: #2a1a1a; }

  .sess-new-form {
    padding: 8px 10px;
    border-top: 1px solid var(--border);
    display: none;
    flex-shrink: 0;
  }
  .sess-new-form.visible { display: flex; gap: 6px; }
  .sess-new-form input {
    flex: 1;
    background: #222;
    border: 1px solid #333;
    color: var(--text-main);
    border-radius: 6px;
    padding: 5px 8px;
    font-size: .8rem;
    outline: none;
  }
  .sess-new-form input:focus { border-color: var(--accent); }
  .sess-new-form button {
    background: var(--accent);
    border: none;
    color: #fff;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: .8rem;
    cursor: pointer;
  }
  .sess-new-form button:hover { background: var(--accent-hover); }

  /* ── Main area ──────────────────────────────────────────── */
  #main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: var(--bg-term);
  }

  /* ── Top bar ────────────────────────────────────────────── */
  #topbar {
    height: var(--topbar-h);
    min-height: var(--topbar-h);
    background: #111;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    padding: 0 10px;
    gap: 8px;
    flex-shrink: 0;
  }
  #btn-toggle-sidebar {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 1rem;
    padding: 4px 6px;
    border-radius: 4px;
    transition: color .1s, background .1s;
  }
  #btn-toggle-sidebar:hover { color: var(--text-main); background: #222; }
  #topbar-title {
    font-size: .85rem;
    color: var(--text-main);
    font-weight: 500;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .topbar-badge {
    font-size: .7rem;
    background: #10a37f22;
    color: var(--accent);
    border: 1px solid #10a37f55;
    border-radius: 10px;
    padding: 2px 8px;
  }
  #btn-reconnect {
    background: none;
    border: 1px solid #444;
    color: var(--text-muted);
    cursor: pointer;
    font-size: .75rem;
    padding: 3px 8px;
    border-radius: 5px;
    transition: all .15s;
    display: none;
  }
  #btn-reconnect:hover { border-color: var(--accent); color: var(--accent); }

  /* ── Terminal area ──────────────────────────────────────── */
  #terminal-wrap {
    flex: 1;
    position: relative;
    overflow: hidden;
    background: #1a1a1a;
  }
  #terminal {
    position: absolute;
    inset: 0;
    padding: 4px 4px 0;
  }
  #term-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    gap: 10px;
    font-size: .9rem;
    pointer-events: none;
  }
  #term-placeholder .ph-icon { font-size: 2.5rem; opacity: .3; }
  #term-placeholder.hidden { display: none; }

  /* ── Special keys bar ───────────────────────────────────── */
  #keysbar {
    height: var(--keysbar-h);
    min-height: var(--keysbar-h);
    background: #111;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    padding: 0 8px;
    gap: 4px;
    overflow-x: auto;
    flex-shrink: 0;
  }
  #keysbar::-webkit-scrollbar { height: 0; }
  .key-btn {
    background: var(--key-bg);
    border: 1px solid #333;
    color: var(--text-main);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: .75rem;
    cursor: pointer;
    white-space: nowrap;
    transition: background .1s, border-color .1s, transform .08s;
    font-family: 'Segoe UI', monospace;
    user-select: none;
    flex-shrink: 0;
  }
  .key-btn:hover { background: var(--key-hover); border-color: #555; }
  .key-btn:active { transform: scale(.93); background: #444; }
  .key-sep {
    width: 1px;
    height: 20px;
    background: var(--border);
    margin: 0 2px;
    flex-shrink: 0;
  }

  /* ── Status bar ─────────────────────────────────────────── */
  #statusbar {
    height: var(--statusbar-h);
    min-height: var(--statusbar-h);
    background: #0a0a0a;
    border-top: 1px solid #1a1a1a;
    display: flex;
    align-items: center;
    padding: 0 10px;
    gap: 14px;
    font-size: .68rem;
    color: var(--text-muted);
    flex-shrink: 0;
  }
  .sb-item { display: flex; align-items: center; gap: 4px; }
  .sb-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #555;
    transition: background .3s;
  }
  .sb-dot.connected { background: var(--accent); box-shadow: 0 0 4px var(--accent); }
  .sb-dot.error { background: var(--danger); }
  #sb-session-name { color: var(--text-main); font-weight: 500; }

  /* ── New session dialog ─────────────────────────────────── */
  #dialog-overlay {
    position: fixed;
    inset: 0;
    background: #000a;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
  }
  #dialog-overlay.visible { display: flex; }
  #dialog-box {
    background: #1c1c1c;
    border: 1px solid #333;
    border-radius: 12px;
    padding: 24px;
    width: min(360px, 90vw);
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  #dialog-box h3 { font-size: .95rem; color: var(--text-main); }
  #dialog-box p { font-size: .78rem; color: var(--text-muted); margin-top: -8px; }
  #dialog-session-name {
    background: #111;
    border: 1px solid #333;
    color: var(--text-main);
    border-radius: 8px;
    padding: 9px 12px;
    font-size: .9rem;
    outline: none;
    width: 100%;
  }
  #dialog-session-name:focus { border-color: var(--accent); }
  .dialog-actions { display: flex; gap: 8px; justify-content: flex-end; }
  .btn-ghost {
    background: none;
    border: 1px solid #333;
    color: var(--text-muted);
    border-radius: 7px;
    padding: 7px 16px;
    font-size: .82rem;
    cursor: pointer;
    transition: all .15s;
  }
  .btn-ghost:hover { border-color: #555; color: var(--text-main); }
  .btn-primary {
    background: var(--accent);
    border: none;
    color: #fff;
    border-radius: 7px;
    padding: 7px 16px;
    font-size: .82rem;
    cursor: pointer;
    transition: background .15s;
  }
  .btn-primary:hover { background: var(--accent-hover); }

  /* ── Mobile ─────────────────────────────────────────────── */
  @media (max-width: 700px) {
    :root {
      --sidebar-w: 85vw;
      --keysbar-h: 40px;
      --topbar-h: 40px;
    }
    #sidebar {
      position: fixed;
      left: 0; top: 0; bottom: 0;
      z-index: 200;
      width: 0; min-width: 0;
      transition: width .22s cubic-bezier(.4,0,.2,1);
      box-shadow: none;
    }
    #sidebar.mobile-open {
      width: var(--sidebar-w);
      min-width: var(--sidebar-w);
      box-shadow: 4px 0 24px #0009;
    }
    #sidebar-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: #0007;
      z-index: 199;
    }
    #sidebar-overlay.visible { display: block; }
    #main { width: 100vw; }
    .key-btn { padding: 5px 8px; font-size: .72rem; }
    #terminal { padding: 2px 1px 0; }
  }
  @media (min-width: 701px) {
    #sidebar-overlay { display: none !important; }
  }
</style>
</head>
<body>
<div id="sidebar-overlay" onclick="closeSidebarMobile()"></div>

<div id="app">

  <!-- ── Sidebar ─────────────────────────────────────────── -->
  <nav id="sidebar">
    <div class="sidebar-header">
      <h2>Sesiones</h2>
      <button id="btn-new-session" title="Nueva sesión" onclick="openNewSessionDialog()">+</button>
    </div>
    <div id="session-list">
      <!-- populated by JS -->
    </div>
  </nav>

  <!-- ── Main content ────────────────────────────────────── -->
  <div id="main">

    <!-- Top bar -->
    <div id="topbar">
      <button id="btn-toggle-sidebar" onclick="toggleSidebar()" title="Mostrar/ocultar sesiones">☰</button>
      <span id="topbar-title">Terminal</span>
      <span class="topbar-badge">root@tmux</span>
      <button id="btn-reconnect" onclick="reconnect()">⟳ Reconectar</button>
    </div>

    <!-- Terminal -->
    <div id="terminal-wrap">
      <div id="terminal"></div>
      <div id="term-placeholder">
        <div class="ph-icon">⌨</div>
        <span>Selecciona o crea una sesión</span>
      </div>
    </div>

    <!-- Special keys bar -->
    <div id="keysbar">
      <button class="key-btn" onclick="sendKey('\x1b')"     title="Escape">Esc</button>
      <button class="key-btn" onclick="sendKey('\t')"       title="Tab">Tab</button>
      <button class="key-btn" onclick="sendKey('\r')"       title="Enter">Enter</button>
      <div class="key-sep"></div>
      <button class="key-btn" onclick="sendKey('\x03')"     title="Ctrl+C">^C</button>
      <button class="key-btn" onclick="sendKey('\x04')"     title="Ctrl+D">^D</button>
      <button class="key-btn" onclick="sendKey('\x1a')"     title="Ctrl+Z">^Z</button>
      <button class="key-btn" onclick="sendKey('\x0c')"     title="Ctrl+L">^L</button>
      <button class="key-btn" onclick="sendKey('\x01')"     title="Ctrl+A">^A</button>
      <button class="key-btn" onclick="sendKey('\x05')"     title="Ctrl+E">^E</button>
      <button class="key-btn" onclick="sendKey('\x15')"     title="Ctrl+U">^U</button>
      <div class="key-sep"></div>
      <button class="key-btn" onclick="sendKey('\x1b[A')"   title="Flecha arriba">↑</button>
      <button class="key-btn" onclick="sendKey('\x1b[B')"   title="Flecha abajo">↓</button>
      <button class="key-btn" onclick="sendKey('\x1b[C')"   title="Flecha derecha">→</button>
      <button class="key-btn" onclick="sendKey('\x1b[D')"   title="Flecha izquierda">←</button>
      <div class="key-sep"></div>
      <button class="key-btn" onclick="sendKey('\x1b[H')"   title="Inicio">Home</button>
      <button class="key-btn" onclick="sendKey('\x1b[F')"   title="Fin">End</button>
      <button class="key-btn" onclick="sendKey('\x1b[5~')"  title="Página arriba">PgUp</button>
      <button class="key-btn" onclick="sendKey('\x1b[6~')"  title="Página abajo">PgDn</button>
      <button class="key-btn" onclick="sendKey('\x1b[3~')"  title="Supr">Del</button>
      <div class="key-sep"></div>
      <button class="key-btn" onclick="sendKey('\x1bOP')"   title="F1">F1</button>
      <button class="key-btn" onclick="sendKey('\x1bOQ')"   title="F2">F2</button>
      <button class="key-btn" onclick="sendKey('\x1bOR')"   title="F3">F3</button>
      <button class="key-btn" onclick="sendKey('\x1bOS')"   title="F4">F4</button>
      <button class="key-btn" onclick="sendKey('\x1b[15~')" title="F5">F5</button>
    </div>

    <!-- Status bar -->
    <div id="statusbar">
      <div class="sb-item">
        <div class="sb-dot" id="sb-dot"></div>
        <span id="sb-status">Desconectado</span>
      </div>
      <div class="sb-item">
        <span>📟</span>
        <span id="sb-session-name">—</span>
      </div>
      <div class="sb-item">
        <span id="sb-size">—</span>
      </div>
      <div class="sb-item" style="margin-left:auto;">
        <span id="sb-time">—</span>
      </div>
    </div>
  </div>
</div>

<!-- ── New session dialog ───────────────────────────────── -->
<div id="dialog-overlay">
  <div id="dialog-box">
    <h3>Nueva sesión tmux</h3>
    <p>Elige un nombre (letras, números, guiones) o déjalo en blanco para auto-generar.</p>
    <input id="dialog-session-name" type="text" placeholder="ej. trabajo, deploy, logs…" maxlength="40"
           onkeydown="if(event.key==='Enter')createSession(); if(event.key==='Escape')closeDialog();">
    <div class="dialog-actions">
      <button class="btn-ghost" onclick="closeDialog()">Cancelar</button>
      <button class="btn-primary" onclick="createSession()">Abrir</button>
    </div>
  </div>
</div>

<script src="assets/js/xterm.js"></script>
<script src="assets/js/xterm-addon-fit.js"></script>
<script>
'use strict';

// ── State ────────────────────────────────────────────────────────────────────
const state = {
  sessions: [],          // [{name, windows, attached, activity}]
  active: null,          // session name currently open
  evtSource: null,       // current EventSource
  term: null,            // xterm.js Terminal
  fitAddon: null,        // FitAddon instance
  resizeObserver: null,  // ResizeObserver for auto-fit
  sidebarOpen: true,
};

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initTerminal();
  refreshSessions();
  setInterval(refreshSessions, 8000);
  setInterval(updateClock, 1000);
  updateClock();
});

// ── xterm.js ─────────────────────────────────────────────────────────────────
function initTerminal() {
  state.term = new Terminal({
    theme: {
      background: '#1a1a1a',
      foreground: '#ececec',
      cursor: '#10a37f',
      cursorAccent: '#1a1a1a',
      selectionBackground: '#10a37f44',
      black:         '#1a1a1a',
      red:           '#e74c3c',
      green:         '#2ecc71',
      yellow:        '#f39c12',
      blue:          '#3498db',
      magenta:       '#9b59b6',
      cyan:          '#1abc9c',
      white:         '#ecf0f1',
      brightBlack:   '#555',
      brightRed:     '#e74c3c',
      brightGreen:   '#27ae60',
      brightYellow:  '#f1c40f',
      brightBlue:    '#2980b9',
      brightMagenta: '#8e44ad',
      brightCyan:    '#16a085',
      brightWhite:   '#fff',
    },
    fontFamily: '"Cascadia Code", "Fira Code", "JetBrains Mono", "Consolas", monospace',
    fontSize: 13,
    lineHeight: 1.2,
    cursorBlink: true,
    cursorStyle: 'block',
    scrollback: 5000,
    allowProposedApi: true,
  });

  state.fitAddon = new FitAddon.FitAddon();
  state.term.loadAddon(state.fitAddon);
  state.term.open(document.getElementById('terminal'));

  // Keyboard input → send to tmux (fire-and-forget, no await)
  state.term.onData(data => {
    if (state.active) sendRaw(data);
  });

  // Click o touch en el área del terminal → foco
  const termWrap = document.getElementById('terminal-wrap');
  termWrap.addEventListener('click',      () => state.active && state.term.focus());
  termWrap.addEventListener('touchstart', () => state.active && state.term.focus(), {passive:true});

  // Resize observer
  state.resizeObserver = new ResizeObserver(() => fitAndSync());
  state.resizeObserver.observe(document.getElementById('terminal-wrap'));
}

function fitAndSync() {
  if (!state.term || !state.fitAddon) return;
  try {
    state.fitAddon.fit();
    const cols = state.term.cols;
    const rows = state.term.rows;
    document.getElementById('sb-size').textContent = cols + '×' + rows;
    if (state.active) {
      apiPost({ action: 'resize', session: state.active, cols, rows });
    }
  } catch(e) {}
}

// ── Session management ────────────────────────────────────────────────────────
async function refreshSessions() {
  try {
    const r = await fetch('terminal_api.php?action=list');
    const d = await r.json();
    state.sessions = d.sessions || [];
    renderSessionList();
  } catch(e) {}
}

function renderSessionList() {
  const list = document.getElementById('session-list');
  if (state.sessions.length === 0) {
    list.innerHTML = '<div style="padding:16px 12px;font-size:.75rem;color:var(--text-muted);text-align:center;">Sin sesiones activas</div>';
    return;
  }
  list.innerHTML = state.sessions.map(s => `
    <div class="sess-item ${s.name === state.active ? 'active' : ''}" onclick="attachSession('${escJs(s.name)}')">
      <div class="sess-icon">${s.attached > 0 ? '🟢' : '⬛'}</div>
      <span class="sess-name" title="${escHtml(s.name)}">${escHtml(s.name)}</span>
      <button class="sess-kill" onclick="killSession(event,'${escJs(s.name)}')" title="Cerrar sesión">✕</button>
    </div>
  `).join('');
}

async function attachSession(name) {
  if (state.active === name) { closeSidebarMobile(); return; }
  try {
    const r = await apiPost({ action: 'attach', session: name });
    if (r.error) { showTermError(r.error); return; }
    closeSidebarMobile();
    switchToSession(name, r.offset || 0);
  } catch(e) { showTermError('Error al conectar'); }
}

async function createSession() {
  const input = document.getElementById('dialog-session-name');
  const rawName = input.value.trim();
  closeDialog();
  try {
    const payload = { action: 'new' };
    if (rawName) payload.name = rawName;
    const r = await apiPost(payload);
    if (r.error) { showTermError(r.error); return; }
    await refreshSessions();
    switchToSession(r.session, r.offset || 0);
    // Dialog cierra y foco pasa al terminal
    setTimeout(() => state.term && state.term.focus(), 200);
  } catch(e) { showTermError('No se pudo crear la sesión'); }
}

async function killSession(evt, name) {
  evt.stopPropagation();
  if (!confirm('¿Cerrar sesión "' + name + '"?')) return;
  await apiPost({ action: 'kill', session: name });
  if (state.active === name) {
    stopStream();
    state.active = null;
    setStatus('disconnected');
    document.getElementById('sb-session-name').textContent = '—';
    document.getElementById('topbar-title').textContent = 'Terminal';
    document.getElementById('term-placeholder').classList.remove('hidden');
    if (state.term) state.term.clear();
  }
  await refreshSessions();
}

function switchToSession(name, offset) {
  stopStream();
  state.active = name;
  // Limpiar salida anterior solo si es sesión nueva (offset=0)
  if (offset === 0) state.term.reset();
  document.getElementById('term-placeholder').classList.add('hidden');
  document.getElementById('topbar-title').textContent = name;
  document.getElementById('sb-session-name').textContent = name;
  renderSessionList();
  fitAndSync();
  startStream(name, offset);
  // Foco inmediato y diferido para garantizar que el teclado responde
  state.term.focus();
  setTimeout(() => state.term && state.term.focus(), 150);
}

// ── SSE Stream ────────────────────────────────────────────────────────────────
function startStream(name, offset) {
  setStatus('connecting');
  const url = 'terminal_api.php?action=stream'
            + '&session=' + encodeURIComponent(name)
            + '&offset='  + encodeURIComponent(offset);
  const es = new EventSource(url);
  state.evtSource = es;

  es.onopen = () => {
    setStatus('connected');
    document.getElementById('btn-reconnect').style.display = 'none';
  };

  es.onmessage = (e) => {
    try {
      // data is base64-encoded binary terminal output
      const bin = atob(e.data);
      const bytes = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      state.term.write(bytes);
    } catch(err) {}
  };

  es.addEventListener('error', (e) => {
    if (e.data) showTermError(e.data);
  });

  es.onerror = () => {
    setStatus('error');
    document.getElementById('btn-reconnect').style.display = '';
    es.close();
    state.evtSource = null;
  };
}

function stopStream() {
  if (state.evtSource) {
    state.evtSource.close();
    state.evtSource = null;
  }
}

async function reconnect() {
  if (!state.active) return;
  try {
    const r = await apiPost({ action: 'attach', session: state.active });
    if (r.error) { showTermError(r.error); return; }
    startStream(state.active, r.offset || 0);
    document.getElementById('btn-reconnect').style.display = 'none';
  } catch(e) {}
}

// ── Input ─────────────────────────────────────────────────────────────────────
async function sendRaw(data) {
  if (!state.active) return;
  try {
    await apiPost({ action: 'send', session: state.active, data });
  } catch(e) {}
}

function sendKey(seq) {
  if (!state.active) {
    openNewSessionDialog();
    return;
  }
  sendRaw(seq);
  state.term.focus();
}

// ── API helper ────────────────────────────────────────────────────────────────
async function apiPost(body) {
  const r = await fetch('terminal_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return r.json();
}

// ── UI helpers ────────────────────────────────────────────────────────────────
function setStatus(s) {
  const dot = document.getElementById('sb-dot');
  const txt = document.getElementById('sb-status');
  dot.className = 'sb-dot';
  if (s === 'connected')   { dot.classList.add('connected'); txt.textContent = 'Conectado'; }
  else if (s === 'connecting') { txt.textContent = 'Conectando…'; }
  else if (s === 'error')  { dot.classList.add('error'); txt.textContent = 'Error SSE'; }
  else                     { txt.textContent = 'Desconectado'; }
}

function showTermError(msg) {
  if (state.term) state.term.writeln('\r\n\x1b[1;31m[ERROR] ' + msg + '\x1b[0m\r\n');
}

function isMobile() { return window.innerWidth <= 700; }

function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebar-overlay');
  if (isMobile()) {
    const open = sb.classList.toggle('mobile-open');
    ov.classList.toggle('visible', open);
  } else {
    state.sidebarOpen = !state.sidebarOpen;
    sb.classList.toggle('collapsed', !state.sidebarOpen);
    setTimeout(() => fitAndSync(), 220);
  }
}

function closeSidebarMobile() {
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sidebar-overlay').classList.remove('visible');
}

function openNewSessionDialog() {
  document.getElementById('dialog-session-name').value = '';
  document.getElementById('dialog-overlay').classList.add('visible');
  setTimeout(() => document.getElementById('dialog-session-name').focus(), 80);
}

function closeDialog() {
  document.getElementById('dialog-overlay').classList.remove('visible');
}

document.getElementById('dialog-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeDialog();
});

function updateClock() {
  const now = new Date();
  document.getElementById('sb-time').textContent = now.toLocaleTimeString('es-CU', { hour12: false });
}

// ── Utils ─────────────────────────────────────────────────────────────────────
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) {
  return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}
</script>
</body>
</html>
