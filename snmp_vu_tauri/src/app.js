const tauriCore = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__.core : null;
const tauriEvent = window.__TAURI__ && window.__TAURI__.event ? window.__TAURI__.event : null;

let pollTimer = null;
let configCache = null;
const historyState = new Map();
let remoteUpdate = null;

function invoke(cmd, args = {}) {
  if (!tauriCore || !tauriCore.invoke) throw new Error('Tauri API no disponible');
  return tauriCore.invoke(cmd, args);
}

function compareVersions(a, b) {
  const pa = String(a || '0').split('.').map((v) => Number(v) || 0);
  const pb = String(b || '0').split('.').map((v) => Number(v) || 0);
  const len = Math.max(pa.length, pb.length);
  for (let i = 0; i < len; i += 1) {
    const av = pa[i] || 0;
    const bv = pb[i] || 0;
    if (av > bv) return 1;
    if (av < bv) return -1;
  }
  return 0;
}

function setBanner(text, available = false, title = '') {
  const banner = document.getElementById('updateBanner');
  if (!banner) return;
  banner.textContent = text;
  banner.classList.toggle('available', !!available);
  banner.title = title || text;
}

async function checkRemoteUpdate(meta) {
  const urls = [
    'https://www.palweb.net/apk/snmp-vu-tauri-update.json',
    'https://shop.palweb.net/apk/snmp-vu-tauri-update.json'
  ];
  for (const url of urls) {
    try {
      const response = await fetch(`${url}?t=${Date.now()}`, { cache: 'no-store' });
      if (!response.ok) continue;
      const data = await response.json();
      remoteUpdate = data;
      if (compareVersions(data.version, meta.version) > 0) {
        setBanner(`Update disponible ${data.version}`, true, data.notes || data.zip_url || url);
      } else {
        setBanner(`Al dia ${meta.version}`, false, data.notes || '');
      }
      return;
    } catch (_) {}
  }
  setBanner(`Sin acceso a updates | ${meta.version}`, false);
}

function defaultConfig() {
  return {
    refreshMs: 3000,
    theme: 'blue_ice',
    windowOpacity: 1,
    mainWidth: 192,
    items: Array.from({ length: 5 }, (_, index) => ({
      enabled: index === 0,
      label: `VU ${index + 1}`,
      host: '',
      community: 'public',
      version: '2c',
      oid: '',
      walkOid: '1.3.6.1.2.1.2.2.1',
      mode: 'auto',
      scaleMbps: index < 4 ? 100 : 300,
      pingIp: '',
      alarmEnabled: false
    }))
  };
}

function applyTheme(theme) {
  document.body.setAttribute('data-theme', theme || 'blue_ice');
}

function applyOpacity(opacity) {
  const safe = Math.max(0.45, Math.min(1, Number(opacity || 1)));
  document.documentElement.style.setProperty('--window-alpha', safe.toFixed(2));
  const valueEl = document.getElementById('windowOpacityValue');
  if (valueEl) valueEl.textContent = `${Math.round(safe * 100)}%`;
}

function applyMainWidth(width) {
  const safe = Math.max(140, Math.min(420, Number(width || 192)));
  document.documentElement.style.setProperty('--main-width', `${safe}px`);
  const valueEl = document.getElementById('mainWidthValue');
  if (valueEl) valueEl.textContent = `${safe}px`;
}

function angleFromPercent(percent) {
  return -135 + (Math.max(0, Math.min(100, percent)) * 270 / 100);
}

function gaugeSvg(index) {
  const ticks = Array.from({ length: 9 }, (_, i) => {
    const angle = (-135 + i * 33.75) * (Math.PI / 180);
    const x1 = 150 + Math.cos(angle) * 90;
    const y1 = 120 + Math.sin(angle) * 90;
    const x2 = 150 + Math.cos(angle) * 108;
    const y2 = 120 + Math.sin(angle) * 108;
    const hot = i > 6 ? 'hot' : '';
    const lx = 150 + Math.cos(angle) * 68;
    const ly = 124 + Math.sin(angle) * 68;
    return `<line class="dial-tick ${hot}" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"></line><text class="dial-label" x="${lx}" y="${ly}" text-anchor="middle">${i * 12.5}</text>`;
  }).join('');
  return `<svg class="dial-svg" viewBox="0 0 300 160" aria-hidden="true"><defs><linearGradient id="dialPlate-${index}" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" style="stop-color:var(--plate-top)"></stop><stop offset="55%" style="stop-color:var(--plate-mid)"></stop><stop offset="100%" style="stop-color:var(--plate-bottom)"></stop></linearGradient><linearGradient id="dialGlass-${index}" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="rgba(255,255,255,.62)"></stop><stop offset="32%" stop-color="rgba(255,255,255,.16)"></stop><stop offset="100%" stop-color="rgba(255,255,255,0)"></stop></linearGradient></defs><rect class="dial-frame" x="18" y="20" rx="16" ry="16" width="264" height="122"></rect><path class="dial-plate" fill="url(#dialPlate-${index})" d="M40,128 C54,48 246,48 260,128 L260,136 L40,136 Z"></path><path class="dial-glass" fill="url(#dialGlass-${index})" d="M40,128 C54,48 246,48 260,128 L260,136 L40,136 Z"></path>${ticks}<line class="dial-needle-shadow" id="needle-shadow-${index}" x1="150" y1="120" x2="78" y2="120"></line><line class="dial-needle" id="needle-${index}" x1="150" y1="118" x2="78" y2="118"></line><circle class="dial-cap" cx="150" cy="120" r="11"></circle></svg>`;
}

function renderMain(items) {
  const host = document.getElementById('gauges');
  if (!host) return;
  host.innerHTML = items.map((item, index) => `<div class="gauge-card"><span class="gauge-led red" id="led-${index}"></span><div class="gauge-head"><div><div class="gauge-title">${item.label}</div><div class="gauge-meta">Escala ${Number(item.scaleMbps).toFixed(0)} MB/s</div></div><div class="gauge-value"><strong id="value-${index}">0.00</strong><span>MB/s</span></div></div><div class="dial-wrap">${gaugeSvg(index)}</div><div class="dial-status" id="status-${index}">Esperando datos...</div><div class="dial-calc" id="calc-${index}">calc auto</div><div class="dial-history" id="history-${index}">min - | avg - | max -</div><button class="mini-btn" data-reset="${index}" title="Reset calculo">R</button></div>`).join('');
}

function updateHistory(index, mbps) {
  const key = String(index);
  const next = historyState.get(key) || [];
  next.push(Number(mbps || 0));
  while (next.length > 20) next.shift();
  historyState.set(key, next);
  const min = Math.min(...next);
  const max = Math.max(...next);
  const avg = next.reduce((sum, value) => sum + value, 0) / Math.max(1, next.length);
  return { min, max, avg };
}

function updateMain(items) {
  items.forEach((item, index) => {
    const needle = document.getElementById(`needle-${index}`);
    const needleShadow = document.getElementById(`needle-shadow-${index}`);
    const led = document.getElementById(`led-${index}`);
    const value = document.getElementById(`value-${index}`);
    const status = document.getElementById(`status-${index}`);
    const calc = document.getElementById(`calc-${index}`);
    const history = document.getElementById(`history-${index}`);
    const stats = updateHistory(index, item.mbps || 0);
    if (needle) needle.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    if (needleShadow) needleShadow.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    if (led) {
      ['green', 'yellow', 'orange', 'red'].forEach((cls) => led.classList.remove(cls));
      led.classList.add(item.pingColor || 'red');
    }
    if (value) value.textContent = Number(item.mbps || 0).toFixed(2);
    if (status) status.textContent = item.enabled ? `${item.msg} | raw ${item.raw ?? '-'} | ping ${item.pingMs ?? '-'} ms` : 'Desactivado';
    if (calc) calc.textContent = `calc ${item.calcMode || '-'}`;
    if (history) history.textContent = `min ${stats.min.toFixed(2)} | avg ${stats.avg.toFixed(2)} | max ${stats.max.toFixed(2)}`;
  });
}

async function loadConfig() {
  try {
    configCache = await invoke('get_config');
  } catch (error) {
    configCache = defaultConfig();
  }
  applyTheme(configCache.theme);
  applyOpacity(configCache.windowOpacity || 1);
  applyMainWidth(configCache.mainWidth || 192);
  renderMain(configCache.items);
}

async function pollLoop() {
  try {
    const result = await invoke('poll_items');
    if (result && result.items) {
      updateMain(result.items);
      if (pollTimer) clearTimeout(pollTimer);
      pollTimer = setTimeout(pollLoop, result.refreshMs || 3000);
      return;
    }
  } catch (error) {
    document.getElementById('updateBanner').textContent = `Error local: ${error.message || error}`;
  }
  if (pollTimer) clearTimeout(pollTimer);
  pollTimer = setTimeout(pollLoop, 3000);
}

async function boot() {
  let meta = { version: '1.0.0', build: '-' };
  try {
    meta = await invoke('get_meta');
    document.getElementById('buildBadge').textContent = `v${meta.version} #${meta.build}`;
  } catch (_) {}
  await loadConfig();
  checkRemoteUpdate(meta);
  document.getElementById('openConfigBtn').onclick = async () => {
    try {
      await invoke('open_config_window');
    } catch (error) {
      setBanner(`Error abriendo configuracion: ${error.message || error}`, false);
    }
  };
  document.getElementById('refreshBtn').onclick = () => pollLoop();
  document.getElementById('updateBanner').onclick = () => {
    const url = remoteUpdate && (remoteUpdate.zip_url || remoteUpdate.exe_url);
    if (!url) return;
    try {
      window.open(url, '_blank');
    } catch (_) {}
  };
  document.getElementById('closeAppBtn').onclick = async () => {
    await invoke('close_app');
  };
  document.addEventListener('click', async (event) => {
    const btn = event.target.closest('[data-reset]');
    if (!btn) return;
    const index = Number(btn.getAttribute('data-reset'));
    const item = configCache.items[index];
    historyState.delete(String(index));
    await invoke('reset_calc', { label: item.label });
    const calc = document.getElementById(`calc-${index}`);
    const hist = document.getElementById(`history-${index}`);
    if (calc) calc.textContent = 'calc reset';
    if (hist) hist.textContent = 'min - | avg - | max -';
  });
  if (tauriEvent && tauriEvent.listen) {
    tauriEvent.listen('config-updated', async () => {
      await loadConfig();
      await pollLoop();
      setBanner(`Configuracion recargada`, false);
    });
  }
  pollLoop();
}

boot();
