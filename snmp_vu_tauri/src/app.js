const tauriCore = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__.core : null;
const tauriEvent = window.__TAURI__ && window.__TAURI__.event ? window.__TAURI__.event : null;

let pollTimer = null;
let configCache = null;
let remoteUpdate = null;
let configOpen = false;
const presets = {
  mikrotik_ether1_in: { label: 'ether1 IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'auto', scaleMbps: 100 },
  mikrotik_ether1_out: { label: 'ether1 OUT', oid: '1.3.6.1.2.1.2.2.1.16.1', mode: 'auto', scaleMbps: 100 },
  mikrotik_wlan1_in: { label: 'wlan1 IN', oid: '1.3.6.1.2.1.2.2.1.10.2', mode: 'auto', scaleMbps: 100 },
  mikrotik_bridge_in: { label: 'bridge IN', oid: '1.3.6.1.2.1.2.2.1.10.3', mode: 'auto', scaleMbps: 300 },
  mikrotik_pppoe_out: { label: 'PPPoE/WAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.4', mode: 'auto', scaleMbps: 300 }
};

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
  try {
    const data = await invoke('check_update_feed');
    remoteUpdate = data;
    if (compareVersions(data.version, meta.version) > 0) {
      setBanner(`Update disponible ${data.version}`, true, data.notes || data.zip_url || '');
    } else {
      setBanner(`Al dia ${meta.version}`, false, data.notes || '');
    }
  } catch (error) {
    setBanner(`Sin acceso a updates | ${meta.version}`, false, error.message || String(error));
  }
}

function defaultConfig() {
  return {
    refreshMs: 3000,
    theme: 'blue_ice',
    windowOpacity: 1,
    mainWidth: 192,
    dockMode: 'free',
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
  syncResponsiveMetrics();
}

async function previewLayout() {
  const widthEl = document.getElementById('mainWidthRange');
  const dockEl = document.getElementById('dockMode');
  try {
    await invoke('preview_layout', {
      width: Number(widthEl?.value || configCache?.mainWidth || 192),
      dock_mode: dockEl?.value || configCache?.dockMode || 'free',
      expanded: configOpen
    });
  } catch (_) {}
  syncResponsiveMetrics();
}

function syncResponsiveMetrics() {
  const host = document.getElementById('mainView');
  if (!host) return;
  const contentWidth = Math.max(140, Math.round(host.clientWidth || Number(configCache?.mainWidth || 192)));
  document.documentElement.style.setProperty('--content-width', `${contentWidth}px`);
}

function setConfigStatus(text, kind = 'info') {
  const el = document.getElementById('configStatus');
  if (!el) return;
  el.textContent = text;
  el.dataset.kind = kind;
}

function compactItemCard(item, index) {
  return `<details class="compact-card"${index === 0 ? ' open' : ''}>
    <summary>${item.label || `VU ${index + 1}`}<span>${item.enabled ? 'on' : 'off'}</span></summary>
    <div class="compact-body">
      <div class="compact-row">
        <label><input type="checkbox" id="enabled_${index}" ${item.enabled ? 'checked' : ''}> activo</label>
        <select class="select-input tiny-select" id="preset_${index}">
          <option value="">preset</option>
          <option value="mikrotik_ether1_in">e1 IN</option>
          <option value="mikrotik_ether1_out">e1 OUT</option>
          <option value="mikrotik_wlan1_in">wl IN</option>
          <option value="mikrotik_bridge_in">br IN</option>
          <option value="mikrotik_pppoe_out">wan OUT</option>
        </select>
      </div>
      <input class="text-input compact-input" id="label_${index}" value="${item.label}" placeholder="Etiqueta">
      <input class="text-input compact-input" id="host_${index}" value="${item.host}" placeholder="Host">
      <div class="compact-row">
        <input class="text-input compact-input" id="community_${index}" value="${item.community}" placeholder="Community">
        <select class="select-input tiny-select" id="version_${index}">
          <option value="2c" ${item.version === '2c' ? 'selected' : ''}>v2c</option>
          <option value="1" ${item.version === '1' ? 'selected' : ''}>v1</option>
        </select>
      </div>
      <input class="text-input compact-input" id="oid_${index}" value="${item.oid}" placeholder="OID">
      <div class="compact-row">
        <select class="select-input tiny-select" id="mode_${index}">
          <option value="auto" ${item.mode === 'auto' ? 'selected' : ''}>auto</option>
          <option value="counter_bytes" ${item.mode === 'counter_bytes' ? 'selected' : ''}>bytes</option>
          <option value="counter_bits" ${item.mode === 'counter_bits' ? 'selected' : ''}>bits</option>
          <option value="direct_bps" ${item.mode === 'direct_bps' ? 'selected' : ''}>bps</option>
          <option value="direct_mbps" ${item.mode === 'direct_mbps' ? 'selected' : ''}>MB/s</option>
        </select>
        <input class="text-input compact-input" type="number" min="1" step="0.1" id="scale_${index}" value="${item.scaleMbps}" placeholder="Escala">
      </div>
      <input class="text-input compact-input" id="ping_${index}" value="${item.pingIp}" placeholder="IP ping">
    </div>
  </details>`;
}

function readConfigFromForm() {
  return {
    refreshMs: Number(document.getElementById('refreshMs').value || 3000),
    theme: document.getElementById('themeSelect').value || 'blue_ice',
    windowOpacity: Number(document.getElementById('windowOpacityRange').value || 100) / 100,
    mainWidth: Number(document.getElementById('mainWidthRange').value || 192),
    dockMode: document.getElementById('dockMode').value || 'free',
    items: Array.from({ length: 5 }, (_, index) => ({
      enabled: document.getElementById(`enabled_${index}`).checked,
      label: document.getElementById(`label_${index}`).value,
      host: document.getElementById(`host_${index}`).value,
      community: document.getElementById(`community_${index}`).value,
      version: document.getElementById(`version_${index}`).value,
      oid: document.getElementById(`oid_${index}`).value,
      walkOid: configCache.items[index].walkOid || '1.3.6.1.2.1.2.2.1',
      mode: document.getElementById(`mode_${index}`).value,
      scaleMbps: Number(document.getElementById(`scale_${index}`).value || 100),
      pingIp: document.getElementById(`ping_${index}`).value,
      alarmEnabled: configCache.items[index].alarmEnabled || false
    }))
  };
}

function bindConfigEvents() {
  const themeEl = document.getElementById('themeSelect');
  if (themeEl) themeEl.onchange = () => applyTheme(themeEl.value);
  const opacityEl = document.getElementById('windowOpacityRange');
  if (opacityEl) opacityEl.oninput = () => applyOpacity(Number(opacityEl.value || 100) / 100);
  const widthEl = document.getElementById('mainWidthRange');
  if (widthEl) {
    widthEl.oninput = () => {
      applyMainWidth(Number(widthEl.value || 192));
      previewLayout();
    };
  }
  const dockEl = document.getElementById('dockMode');
  if (dockEl) dockEl.onchange = () => previewLayout();
  document.querySelectorAll('[id^="preset_"]').forEach((select) => {
    select.addEventListener('change', () => {
      const index = Number(select.id.split('_')[1]);
      const preset = presets[select.value];
      if (!preset) return;
      document.getElementById(`label_${index}`).value = preset.label;
      document.getElementById(`oid_${index}`).value = preset.oid;
      document.getElementById(`mode_${index}`).value = preset.mode;
      document.getElementById(`scale_${index}`).value = preset.scaleMbps;
    });
  });
}

function angleFromPercent(percent) {
  return -135 + (Math.max(0, Math.min(100, percent)) * 270 / 100);
}

function gaugeFace(index) {
  return `<div class="vu-stage">
    <img class="vu-face" src="./assets/vu-meter-scale.svg" alt="" aria-hidden="true">
    <div class="vu-glass"></div>
    <div class="vu-scale-labels">
      <span>0</span><span>12.5</span><span>25</span><span>37.5</span><span>50</span><span>62.5</span><span>75</span><span>87.5</span><span>100</span>
    </div>
    <div class="vu-value-float"><strong id="value-${index}">0.00</strong><span>MB/s</span></div>
    <div class="vu-needle-shadow" id="needle-shadow-${index}"></div>
    <div class="vu-needle" id="needle-${index}"></div>
    <div class="vu-cap"></div>
  </div>`;
}

function renderMain(items) {
  const host = document.getElementById('gauges');
  if (!host) return;
  host.innerHTML = items.map((item, index) => `<div class="gauge-card"><span class="gauge-led red" id="led-${index}"></span><div class="gauge-head"><div><div class="gauge-title">${item.label}</div><div class="gauge-meta">${Number(item.scaleMbps).toFixed(0)} MB/s escala</div></div><button class="mini-btn" data-reset="${index}" title="Reset calculo">R</button></div><div class="dial-wrap">${gaugeFace(index)}</div><div class="dial-status" id="status-${index}">Esperando datos...</div></div>`).join('');
}

function updateMain(items) {
  items.forEach((item, index) => {
    const needle = document.getElementById(`needle-${index}`);
    const needleShadow = document.getElementById(`needle-shadow-${index}`);
    const led = document.getElementById(`led-${index}`);
    const value = document.getElementById(`value-${index}`);
    const status = document.getElementById(`status-${index}`);
    if (needle) needle.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    if (needleShadow) needleShadow.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    if (led) {
      ['green', 'yellow', 'orange', 'red'].forEach((cls) => led.classList.remove(cls));
      led.classList.add(item.pingColor || 'red');
    }
    if (value) value.textContent = Number(item.mbps || 0).toFixed(2);
    if (status) status.textContent = item.enabled ? `${item.msg} | raw ${item.raw ?? '-'} | ping ${item.pingMs ?? '-'} ms` : 'Desactivado';
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
  document.getElementById('refreshMs').value = configCache.refreshMs;
  document.getElementById('themeSelect').value = configCache.theme;
  document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
  document.getElementById('mainWidthRange').value = configCache.mainWidth || 192;
  document.getElementById('dockMode').value = configCache.dockMode || 'free';
  document.getElementById('configGrid').innerHTML = configCache.items.map(compactItemCard).join('');
  bindConfigEvents();
  renderMain(configCache.items);
  syncResponsiveMetrics();
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
    configOpen = !configOpen;
    document.getElementById('inlineConfig').classList.toggle('hidden', !configOpen);
    try {
      await invoke('set_panel_expanded', { expanded: configOpen });
    } catch (_) {}
    syncResponsiveMetrics();
  };
  document.getElementById('saveConfigBtn').onclick = async () => {
    try {
      configCache = await invoke('save_config', { cfg: readConfigFromForm() });
      await loadConfig();
      configOpen = false;
      document.getElementById('inlineConfig').classList.add('hidden');
      try {
        await invoke('set_panel_expanded', { expanded: false });
      } catch (_) {}
      setConfigStatus('Guardado', 'ok');
    } catch (error) {
      setConfigStatus(`Error: ${error.message || error}`, 'error');
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
    await invoke('reset_calc', { label: item.label });
    const status = document.getElementById(`status-${index}`);
    if (status) status.textContent = 'Calculo reiniciado';
  });
  if (tauriEvent && tauriEvent.listen) {
    tauriEvent.listen('config-updated', async () => {
      await loadConfig();
      await pollLoop();
      setBanner(`Configuracion recargada`, false);
    });
  }
  window.addEventListener('resize', syncResponsiveMetrics);
  pollLoop();
}

boot();
