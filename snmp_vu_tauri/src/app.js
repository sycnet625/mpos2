const tauriCore = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__.core : null;
const tauriWindow = window.__TAURI__ && window.__TAURI__.window ? window.__TAURI__.window : null;

const presets = {
  mikrotik_ether1_in: { label: 'ether1 IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'auto', scaleMbps: 100 },
  mikrotik_ether1_out: { label: 'ether1 OUT', oid: '1.3.6.1.2.1.2.2.1.16.1', mode: 'auto', scaleMbps: 100 },
  mikrotik_wlan1_in: { label: 'wlan1 IN', oid: '1.3.6.1.2.1.2.2.1.10.2', mode: 'auto', scaleMbps: 100 },
  mikrotik_bridge_in: { label: 'bridge IN', oid: '1.3.6.1.2.1.2.2.1.10.3', mode: 'auto', scaleMbps: 300 },
  mikrotik_pppoe_out: { label: 'PPPoE/WAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.4', mode: 'auto', scaleMbps: 300 }
};

let pollTimer = null;
let configCache = null;
const historyState = new Map();
let profileList = [];

function invoke(cmd, args = {}) {
  if (!tauriCore || !tauriCore.invoke) throw new Error('Tauri API no disponible');
  return tauriCore.invoke(cmd, args);
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

function profileOptionsHtml(items) {
  return ['<option value="">Perfiles guardados</option>']
    .concat((items || []).map((name) => `<option value="${name}">${name}</option>`))
    .join('');
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

function configBox(item, index) {
  return `<div class="config-box" data-index="${index}"><div class="switch-line"><input type="checkbox" id="enabled_${index}" ${item.enabled ? 'checked' : ''}><h3>Instrumento ${index + 1}</h3></div><label class="field-label">Preset</label><select class="select-input" id="preset_${index}"><option value="">Sin preset</option><option value="mikrotik_ether1_in">MikroTik ether1 IN</option><option value="mikrotik_ether1_out">MikroTik ether1 OUT</option><option value="mikrotik_wlan1_in">MikroTik wlan1 IN</option><option value="mikrotik_bridge_in">MikroTik bridge IN</option><option value="mikrotik_pppoe_out">MikroTik PPPoE/WAN OUT</option></select><label class="field-label">Etiqueta</label><input class="text-input" id="label_${index}" value="${item.label}"><label class="field-label">Host</label><input class="text-input" id="host_${index}" value="${item.host}"><label class="field-label">Community</label><input class="text-input" id="community_${index}" value="${item.community}"><label class="field-label">Version</label><select class="select-input" id="version_${index}"><option value="2c" ${item.version === '2c' ? 'selected' : ''}>SNMP v2c</option><option value="1" ${item.version === '1' ? 'selected' : ''}>SNMP v1</option></select><label class="field-label">OID</label><input class="text-input" id="oid_${index}" value="${item.oid}"><label class="field-label">Modo</label><select class="select-input" id="mode_${index}"><option value="auto" ${item.mode === 'auto' ? 'selected' : ''}>Auto detectar</option><option value="counter_bytes" ${item.mode === 'counter_bytes' ? 'selected' : ''}>Contador bytes</option><option value="counter_bits" ${item.mode === 'counter_bits' ? 'selected' : ''}>Contador bits</option><option value="direct_bps" ${item.mode === 'direct_bps' ? 'selected' : ''}>Valor directo bps</option><option value="direct_mbps" ${item.mode === 'direct_mbps' ? 'selected' : ''}>Valor directo MB/s</option></select><label class="field-label">Escala MB/s</label><input class="text-input" type="number" min="1" step="0.1" id="scale_${index}" value="${item.scaleMbps}"><label class="field-label">IP Ping</label><input class="text-input" id="ping_${index}" value="${item.pingIp}"><div class="switch-line alarm-switch"><input type="checkbox" id="alarm_${index}" ${item.alarmEnabled ? 'checked' : ''}><label class="field-label inline-label" for="alarm_${index}">Alarma sonora si el ping queda en rojo</label></div><label class="field-label">OID walk</label><input class="text-input" id="walk_${index}" value="${item.walkOid || '1.3.6.1.2.1.2.2.1'}"><button class="secondary-btn" data-walk="${index}">SNMP walk</button></div>`;
}

function readConfigFromForm() {
  return {
    refreshMs: Number(document.getElementById('refreshMs').value || 3000),
    theme: document.getElementById('themeSelect').value || 'blue_ice',
    windowOpacity: Number(document.getElementById('windowOpacityRange').value || 100) / 100,
    mainWidth: Number(document.getElementById('mainWidthRange').value || 192),
    items: Array.from({ length: 5 }, (_, index) => ({
      enabled: document.getElementById(`enabled_${index}`).checked,
      label: document.getElementById(`label_${index}`).value,
      host: document.getElementById(`host_${index}`).value,
      community: document.getElementById(`community_${index}`).value,
      version: document.getElementById(`version_${index}`).value,
      oid: document.getElementById(`oid_${index}`).value,
      walkOid: document.getElementById(`walk_${index}`).value,
      mode: document.getElementById(`mode_${index}`).value,
      scaleMbps: Number(document.getElementById(`scale_${index}`).value || 100),
      pingIp: document.getElementById(`ping_${index}`).value,
      alarmEnabled: document.getElementById(`alarm_${index}`).checked
    }))
  };
}

function bindConfigEvents() {
  document.querySelectorAll('[id^="preset_"]').forEach((select) => {
    select.addEventListener('change', () => {
      const index = Number(select.id.split('_')[1]);
      const preset = presets[select.value];
      if (!preset) return;
      document.getElementById(`label_${index}`).value = preset.label;
      document.getElementById(`oid_${index}`).value = preset.oid;
      document.getElementById(`walk_${index}`).value = '1.3.6.1.2.1.2.2.1';
      document.getElementById(`mode_${index}`).value = preset.mode;
      document.getElementById(`scale_${index}`).value = preset.scaleMbps;
    });
  });
  document.querySelectorAll('[data-walk]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const index = Number(btn.getAttribute('data-walk'));
      const item = readConfigFromForm().items[index];
      const output = document.getElementById('walkOutput');
      output.textContent = 'Ejecutando...';
      try {
        const result = await invoke('snmp_walk', { item });
        output.textContent = result || 'Sin resultados';
      } catch (error) {
        output.textContent = `Error: ${error.message || error}`;
      }
    });
  });
  document.getElementById('themeSelect').onchange = () => applyTheme(document.getElementById('themeSelect').value);
  document.getElementById('windowOpacityRange').oninput = () => applyOpacity(Number(document.getElementById('windowOpacityRange').value || 100) / 100);
  document.getElementById('mainWidthRange').oninput = () => applyMainWidth(Number(document.getElementById('mainWidthRange').value || 192));
}

function toggleConfig(show) {
  const modal = document.getElementById('configModal');
  if (!modal) return;
  modal.classList.toggle('hidden', !show);
  if (show) {
    document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
    bindConfigEvents();
  }
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
  document.getElementById('refreshMs').value = configCache.refreshMs;
  document.getElementById('themeSelect').value = configCache.theme;
  document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
  document.getElementById('mainWidthRange').value = configCache.mainWidth || 192;
  try {
    profileList = await invoke('list_profiles');
  } catch (_) {
    profileList = [];
  }
  const select = document.getElementById('savedProfilesSelect');
  if (select) select.innerHTML = profileOptionsHtml(profileList);
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
  try {
    const meta = await invoke('get_meta');
    document.getElementById('buildBadge').textContent = `v${meta.version} #${meta.build}`;
  } catch (_) {}
  await loadConfig();
  document.getElementById('openConfigBtn').onclick = () => toggleConfig(true);
  document.getElementById('closeConfigBtn').onclick = () => toggleConfig(false);
  document.getElementById('configBackdrop').onclick = () => toggleConfig(false);
  document.getElementById('exportConfigBtn').onclick = async () => {
    const name = (document.getElementById('profileNameInput').value || '').trim();
    if (!name) {
      document.getElementById('updateBanner').textContent = 'Falta nombre de perfil';
      return;
    }
    await invoke('export_profile', { name, cfg: readConfigFromForm() });
    profileList = await invoke('list_profiles');
    document.getElementById('savedProfilesSelect').innerHTML = profileOptionsHtml(profileList);
    document.getElementById('updateBanner').textContent = `Perfil exportado: ${name}`;
  };
  document.getElementById('importConfigBtn').onclick = async () => {
    const name = document.getElementById('savedProfilesSelect').value;
    if (!name) {
      document.getElementById('updateBanner').textContent = 'Selecciona un perfil';
      return;
    }
    configCache = await invoke('import_profile', { name });
    applyTheme(configCache.theme);
    applyOpacity(configCache.windowOpacity || 1);
    applyMainWidth(configCache.mainWidth || 192);
    document.getElementById('refreshMs').value = configCache.refreshMs;
    document.getElementById('themeSelect').value = configCache.theme;
    document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
    document.getElementById('mainWidthRange').value = configCache.mainWidth || 192;
    document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
    bindConfigEvents();
    document.getElementById('updateBanner').textContent = `Perfil importado: ${name}`;
  };
  document.getElementById('saveConfigBtn').onclick = async () => {
    configCache = await invoke('save_config', { cfg: readConfigFromForm() });
    applyTheme(configCache.theme);
    applyOpacity(configCache.windowOpacity || 1);
    applyMainWidth(configCache.mainWidth || 192);
    toggleConfig(false);
  };
  document.getElementById('refreshBtn').onclick = () => pollLoop();
  document.getElementById('closeAppBtn').onclick = async () => {
    if (tauriWindow && tauriWindow.getCurrentWindow) {
      await tauriWindow.getCurrentWindow().close();
    }
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
  pollLoop();
}

boot();
