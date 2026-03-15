const presets = {
  mikrotik_ether1_in: { label: 'ether1 IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'counter_bits', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  mikrotik_ether1_out: { label: 'ether1 OUT', oid: '1.3.6.1.2.1.2.2.1.16.1', mode: 'counter_bits', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  mikrotik_wlan1_in: { label: 'wlan1 IN', oid: '1.3.6.1.2.1.2.2.1.10.2', mode: 'counter_bits', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  mikrotik_bridge_in: { label: 'bridge IN', oid: '1.3.6.1.2.1.2.2.1.10.3', mode: 'counter_bits', scaleMbps: 300, walkOid: '1.3.6.1.2.1.2.2.1' },
  mikrotik_pppoe_out: { label: 'PPPoE/WAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.4', mode: 'counter_bits', scaleMbps: 300, walkOid: '1.3.6.1.2.1.2.2.1' },
  nano_m2_lan_in: { label: 'Nano M2 LAN IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'counter_bits', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  nano_m2_wlan_out: { label: 'Nano M2 WLAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.2', mode: 'counter_bits', scaleMbps: 100, walkOid: '1.3.6.1.2.1.31.1.1.1' },
  nano_m5_lan_in: { label: 'Nano M5 LAN IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'counter_bits', scaleMbps: 150, walkOid: '1.3.6.1.2.1.2.2.1' },
  nano_m5_wlan_out: { label: 'Nano M5 WLAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.2', mode: 'counter_bits', scaleMbps: 150, walkOid: '1.3.6.1.2.1.31.1.1.1' }
};

let configCache = null;
let pollTimer = null;
let updateInfo = null;
let debugLines = [];
const historyState = new Map();

function debugSet(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function setBuildBadge(text) {
  const el = document.getElementById('buildBadge');
  if (el) el.textContent = text;
}

function debugLog(message, data = null) {
  const stamp = new Date().toLocaleTimeString();
  const line = data === null ? `[${stamp}] ${message}` : `[${stamp}] ${message} | ${JSON.stringify(data)}`;
  debugLines.unshift(line);
  debugLines = debugLines.slice(0, 20);
  const el = document.getElementById('debugLog');
  if (el) el.textContent = debugLines.join('\n');
}

function apiReady() {
  return typeof window.snmpVuApi === 'object' && window.snmpVuApi !== null;
}

function defaultConfig() {
  return {
    refreshMs: 3000,
    theme: 'blue_ice',
    dockMode: 'none',
    trayEnabled: true,
    windowOpacity: 1,
    savedProfiles: [],
    items: Array.from({ length: 5 }, (_, index) => ({
      enabled: true,
      label: `VU ${index + 1}`,
      host: '',
      community: 'public',
      version: '2c',
      oid: '',
      walkOid: '1.3.6.1.2.1.2.2.1',
      mode: 'counter_bits',
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
    return `
      <line class="dial-tick ${hot}" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"></line>
      <text class="dial-label" x="${lx}" y="${ly}" text-anchor="middle">${i * 12.5}</text>
    `;
  }).join('');
  return `
    <svg class="dial-svg" viewBox="0 0 300 160" aria-hidden="true">
      <defs>
        <linearGradient id="dialPlate" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" style="stop-color:var(--plate-top)"></stop>
          <stop offset="55%" style="stop-color:var(--plate-mid)"></stop>
          <stop offset="100%" style="stop-color:var(--plate-bottom)"></stop>
        </linearGradient>
        <linearGradient id="dialGlass" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="rgba(255,255,255,.62)"></stop>
          <stop offset="32%" stop-color="rgba(255,255,255,.16)"></stop>
          <stop offset="100%" stop-color="rgba(255,255,255,0)"></stop>
        </linearGradient>
      </defs>
      <rect class="dial-frame" x="18" y="20" rx="16" ry="16" width="264" height="122"></rect>
      <path class="dial-plate" d="M40,128 C54,48 246,48 260,128 L260,136 L40,136 Z"></path>
      <path class="dial-glass" d="M40,128 C54,48 246,48 260,128 L260,136 L40,136 Z"></path>
      ${ticks}
      <line class="dial-needle-shadow" id="needle-shadow-${index}" x1="150" y1="120" x2="78" y2="120"></line>
      <line class="dial-needle" id="needle-${index}" x1="150" y1="118" x2="78" y2="118"></line>
      <circle class="dial-cap" cx="150" cy="120" r="11"></circle>
    </svg>
  `;
}

function renderMain(items) {
  const host = document.getElementById('gauges');
  if (!host) return;
  host.innerHTML = items.map((item, index) => `
    <div class="gauge-card">
      <span class="gauge-led red" id="led-${index}"></span>
      <div class="gauge-head">
        <div>
          <div class="gauge-title">${item.label}</div>
          <div class="gauge-meta">Escala ${Number(item.scaleMbps).toFixed(0)} MB/s</div>
        </div>
        <div class="gauge-value">
          <strong id="value-${index}">0.00</strong>
          <span>MB/s</span>
        </div>
      </div>
      <div class="dial-wrap">${gaugeSvg(index)}</div>
      <div class="dial-status" id="status-${index}">Esperando datos...</div>
      <div class="dial-history" id="history-${index}">min - | avg - | max -</div>
    </div>
  `).join('');
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
  return { min, max, avg, samples: next.length };
}

function updateMain(items) {
  items.forEach((item, index) => {
    const needle = document.getElementById(`needle-${index}`);
    const needleShadow = document.getElementById(`needle-shadow-${index}`);
    const led = document.getElementById(`led-${index}`);
    const value = document.getElementById(`value-${index}`);
    const status = document.getElementById(`status-${index}`);
    const history = document.getElementById(`history-${index}`);
    const stats = updateHistory(index, item.mbps || 0);
    if (needle) {
      needle.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    }
    if (needleShadow) {
      needleShadow.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    }
    if (led) {
      ['green', 'yellow', 'orange', 'red'].forEach((cls) => led.classList.remove(cls));
      led.classList.add(item.pingColor || (item.pingOk ? 'green' : 'red'));
    }
    if (value) value.textContent = Number(item.mbps || 0).toFixed(2);
    if (status) status.textContent = item.enabled ? `${item.msg} | raw ${item.raw ?? '-'} | ping ${item.pingMs ?? '-'} ms` : 'Desactivado';
    if (history) history.textContent = `min ${stats.min.toFixed(2)} | avg ${stats.avg.toFixed(2)} | max ${stats.max.toFixed(2)}`;
  });
  updateAlarm(items);
}

function profileOptionsHtml(savedProfiles) {
  const items = Array.isArray(savedProfiles) ? savedProfiles : [];
  return ['<option value="">Perfiles guardados</option>']
    .concat(items.map((profile, index) => `<option value="${index}">${profile.name}</option>`))
    .join('');
}

async function pollLoop() {
  try {
    const result = await window.snmpVuApi.poll();
    if (result && result.status === 'success') {
      updateMain(result.items);
      debugSet('dbgPoll', `ok ${new Date().toLocaleTimeString()}`);
      debugLog('poll ok', { items: (result.items || []).length, refreshMs: result.refreshMs || 3000 });
      if (pollTimer) clearTimeout(pollTimer);
      pollTimer = setTimeout(pollLoop, result.refreshMs || 3000);
      return;
    }
  } catch (error) {
    debugSet('dbgPoll', 'error');
    debugLog('poll error', { message: error && error.message ? error.message : String(error) });
    setUpdateBanner('Error local del monitor', true);
  }
  if (pollTimer) clearTimeout(pollTimer);
  pollTimer = setTimeout(pollLoop, 3000);
}

function setUpdateBanner(text, available = false) {
  const el = document.getElementById('updateBanner');
  if (!el) return;
  el.textContent = text;
  el.classList.toggle('available', available);
}

async function checkForUpdates(openIfAvailable = false) {
  try {
    const meta = await window.snmpVuApi.getMeta();
    const result = await window.snmpVuApi.checkUpdate();
    if (result.status !== 'success') {
      setUpdateBanner(`Version ${meta.version} | updates: ${result.msg || 'sin acceso'}`, false);
      debugLog('update unavailable', result);
      return;
    }
    updateInfo = result;
    if (result.updateAvailable) {
      setUpdateBanner(`Nueva version ${result.latestVersion} disponible`, true);
      if (openIfAvailable && result.exeUrl) {
        await window.snmpVuApi.openDownload(result.exeUrl);
      }
    } else {
      setUpdateBanner(`Version ${result.currentVersion} actualizada`, false);
    }
  } catch (error) {
    debugLog('update error', { message: error && error.message ? error.message : String(error) });
    setUpdateBanner('Updates no disponibles', false);
  }
}

async function runSelfTest() {
  if (!apiReady()) {
    debugLog('self test', { api: false });
    return;
  }
  try {
    const result = await window.snmpVuApi.selfTest();
    debugLog('self test', result);
  } catch (error) {
    debugLog('self test error', { message: error && error.message ? error.message : String(error) });
  }
}

async function bootMain() {
  const bootstrap = defaultConfig();
  renderMain(bootstrap.items);
  if (!apiReady()) {
    setUpdateBanner('Error UI: preload/API no disponible', true);
    return;
  }
  try {
    configCache = await window.snmpVuApi.getConfig();
    if (!configCache || !Array.isArray(configCache.items)) {
      configCache = bootstrap;
    }
  } catch (error) {
    configCache = bootstrap;
  }
  renderMain(configCache.items);
  applyTheme(configCache.theme);
  applyOpacity(configCache.windowOpacity || 1);
  try {
    const meta = await window.snmpVuApi.getMeta();
    setBuildBadge(`v${meta.version} #${meta.build || '-'}`);
    setUpdateBanner(`Version ${meta.version}`, false);
  } catch (error) {
    setBuildBadge('build ?');
    setUpdateBanner('Version local', false);
  }
  const openBtn = document.getElementById('openConfigBtn');
  if (openBtn) {
    openBtn.addEventListener('click', async () => {
      try {
        await window.snmpVuApi.openConfig();
      } catch (error) {
        setUpdateBanner('No se pudo abrir configuracion', true);
      }
    });
  }
  const updateBtn = document.getElementById('checkUpdateBtn');
  if (updateBtn) {
    updateBtn.addEventListener('click', async () => {
      await checkForUpdates(true);
    });
  }
  const closeBtn = document.getElementById('closeAppBtn');
  if (closeBtn) {
    closeBtn.addEventListener('click', async () => {
      await window.snmpVuApi.closeWindow();
    });
  }
  checkForUpdates(false);
  pollLoop();
}

async function bootConfig() {
  if (!apiReady()) {
    const grid = document.getElementById('configGrid');
    if (grid) {
      grid.innerHTML = '<div class="config-box"><h3>Error</h3><div>Preload/API no disponible en esta build.</div></div>';
    }
    return;
  }
  try {
    configCache = await window.snmpVuApi.getConfig();
  } catch (error) {
    configCache = defaultConfig();
  }
  applyTheme(configCache.theme);
  applyOpacity(configCache.windowOpacity || 1);
  document.getElementById('refreshMs').value = configCache.refreshMs;
  document.getElementById('themeSelect').value = configCache.theme || 'blue_ice';
  document.getElementById('dockModeSelect').value = configCache.dockMode || 'none';
  document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
  document.getElementById('trayEnabled').checked = configCache.trayEnabled !== false;
  document.getElementById('savedProfilesSelect').innerHTML = profileOptionsHtml(configCache.savedProfiles);
  document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
  debugSet('dbgApi', apiReady() ? 'ok' : 'missing');
  debugSet('dbgConfigBtn', 'modal');
  debugSet('dbgUpdateBtn', 'main');
  const selfTestBtn = document.getElementById('selfTestBtn');
  if (selfTestBtn) selfTestBtn.addEventListener('click', runSelfTest);
  bindConfigEvents();
}

window.addEventListener('error', (event) => {
  debugLog('window error', { message: event.message, source: event.filename, line: event.lineno });
  setUpdateBanner(`Error UI: ${event.message}`, true);
});

window.addEventListener('unhandledrejection', (event) => {
  const text = event.reason && event.reason.message ? event.reason.message : 'Promise rechazada';
  debugLog('promise rejection', { message: text });
  setUpdateBanner(`Error UI: ${text}`, true);
});

if (apiReady() && window.snmpVuApi.isConfigWindow) {
  bootConfig();
} else {
  bootMain();
}

function configBox(item, index) {
  return `
    <div class="config-box" data-index="${index}">
      <div class="switch-line">
        <input type="checkbox" id="enabled_${index}" ${item.enabled ? 'checked' : ''}>
        <h3>Instrumento ${index + 1}</h3>
      </div>
      <label class="field-label">Preset</label>
      <select class="select-input" id="preset_${index}">
        <option value="">Sin preset</option>
        <option value="mikrotik_ether1_in">MikroTik ether1 IN</option>
        <option value="mikrotik_ether1_out">MikroTik ether1 OUT</option>
        <option value="mikrotik_wlan1_in">MikroTik wlan1 IN</option>
        <option value="mikrotik_bridge_in">MikroTik bridge IN</option>
        <option value="mikrotik_pppoe_out">MikroTik PPPoE/WAN OUT</option>
        <option value="nano_m2_lan_in">NanoStation M2 LAN IN</option>
        <option value="nano_m2_wlan_out">NanoStation M2 WLAN OUT</option>
        <option value="nano_m5_lan_in">NanoStation M5 LAN IN</option>
        <option value="nano_m5_wlan_out">NanoStation M5 WLAN OUT</option>
      </select>
      <label class="field-label">Etiqueta</label>
      <input class="text-input" id="label_${index}" value="${item.label}">
      <label class="field-label">Host</label>
      <input class="text-input" id="host_${index}" value="${item.host}">
      <label class="field-label">Community</label>
      <input class="text-input" id="community_${index}" value="${item.community}">
      <label class="field-label">Version</label>
      <select class="select-input" id="version_${index}">
        <option value="2c" ${item.version === '2c' ? 'selected' : ''}>SNMP v2c</option>
        <option value="1" ${item.version === '1' ? 'selected' : ''}>SNMP v1</option>
      </select>
      <label class="field-label">OID</label>
      <input class="text-input" id="oid_${index}" value="${item.oid}">
      <label class="field-label">Modo</label>
      <select class="select-input" id="mode_${index}">
        <option value="counter_bytes" ${item.mode === 'counter_bytes' ? 'selected' : ''}>Contador bytes</option>
        <option value="counter_bits" ${item.mode === 'counter_bits' ? 'selected' : ''}>Contador bits</option>
        <option value="direct_bps" ${item.mode === 'direct_bps' ? 'selected' : ''}>Valor directo bps</option>
        <option value="direct_mbps" ${item.mode === 'direct_mbps' ? 'selected' : ''}>Valor directo MB/s</option>
      </select>
      <label class="field-label">Escala MB/s</label>
      <input class="text-input" type="number" min="1" step="0.1" id="scale_${index}" value="${item.scaleMbps}">
      <label class="field-label">IP Ping</label>
      <input class="text-input" id="ping_${index}" value="${item.pingIp}">
      <div class="switch-line alarm-switch">
        <input type="checkbox" id="alarm_${index}" ${item.alarmEnabled ? 'checked' : ''}>
        <label class="field-label inline-label" for="alarm_${index}">Alarma sonora si el ping queda en rojo</label>
      </div>
      <label class="field-label">OID walk</label>
      <input class="text-input" id="walk_${index}" value="${item.walkOid}">
      <button class="secondary-btn" data-walk="${index}">SNMP walk</button>
    </div>
  `;
}

function readConfigFromForm() {
  return {
    refreshMs: Number(document.getElementById('refreshMs').value || 3000),
    theme: document.getElementById('themeSelect').value || 'blue_ice',
    dockMode: document.getElementById('dockModeSelect').value || 'none',
    trayEnabled: document.getElementById('trayEnabled').checked,
    windowOpacity: Number(document.getElementById('windowOpacityRange').value || 100) / 100,
    savedProfiles: Array.isArray(configCache && configCache.savedProfiles) ? configCache.savedProfiles : [],
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
  const themeSelect = document.getElementById('themeSelect');
  if (themeSelect) {
    themeSelect.onchange = () => applyTheme(themeSelect.value);
  }
  const opacityRange = document.getElementById('windowOpacityRange');
  if (opacityRange) {
    opacityRange.oninput = () => applyOpacity(Number(opacityRange.value || 100) / 100);
  }
  const dockModeSelect = document.getElementById('dockModeSelect');
  if (dockModeSelect) {
    dockModeSelect.onchange = async () => {
      await window.snmpVuApi.applyDock(dockModeSelect.value);
    };
  }
  document.querySelectorAll('[id^="preset_"]').forEach((select) => {
    select.addEventListener('change', () => {
      const index = Number(select.id.split('_')[1]);
      const preset = presets[select.value];
      if (!preset) return;
      document.getElementById(`label_${index}`).value = preset.label;
      document.getElementById(`oid_${index}`).value = preset.oid;
      document.getElementById(`mode_${index}`).value = preset.mode;
      document.getElementById(`scale_${index}`).value = preset.scaleMbps;
      document.getElementById(`walk_${index}`).value = preset.walkOid;
    });
  });
  document.querySelectorAll('[data-walk]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const index = Number(btn.getAttribute('data-walk'));
      const item = readConfigFromForm().items[index];
      const output = document.getElementById('walkOutput');
      output.textContent = 'Ejecutando...';
      const result = await window.snmpVuApi.walk(item);
      output.textContent = result.ok ? result.output : (result.msg || 'Error');
      debugLog('walk', { index, ok: result.ok });
    });
  });
  const exportBtn = document.getElementById('exportConfigBtn');
  if (exportBtn) {
    exportBtn.onclick = async () => {
      const result = await window.snmpVuApi.exportConfig(readConfigFromForm());
      debugLog('export', result);
    };
  }
  const importBtn = document.getElementById('importConfigBtn');
  if (importBtn) {
    importBtn.onclick = async () => {
      const result = await window.snmpVuApi.importConfig();
      if (!result || result.status !== 'success' || !result.config) {
        debugLog('import', result || { status: 'cancelled' });
        return;
      }
      configCache = {
        ...defaultConfig(),
        ...result.config,
        theme: result.config.theme || 'blue_ice'
      };
      document.getElementById('refreshMs').value = configCache.refreshMs;
      document.getElementById('themeSelect').value = configCache.theme;
      document.getElementById('dockModeSelect').value = configCache.dockMode || 'none';
      document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
      document.getElementById('trayEnabled').checked = configCache.trayEnabled !== false;
      document.getElementById('savedProfilesSelect').innerHTML = profileOptionsHtml(configCache.savedProfiles);
      document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
      applyTheme(configCache.theme);
      applyOpacity(configCache.windowOpacity || 1);
      bindConfigEvents();
      debugLog('import ok', { filePath: result.filePath });
    };
  }
  const saveProfileBtn = document.getElementById('saveProfileBtn');
  if (saveProfileBtn) {
    saveProfileBtn.onclick = async () => {
      const name = (document.getElementById('profileNameInput').value || '').trim();
      if (!name) {
        debugLog('profile save', { status: 'error', msg: 'Falta nombre' });
        return;
      }
      const next = readConfigFromForm();
      const savedProfiles = Array.isArray(configCache.savedProfiles) ? [...configCache.savedProfiles] : [];
      const index = savedProfiles.findIndex((profile) => profile.name === name);
      const payload = { name, config: { ...next, savedProfiles } };
      if (index >= 0) {
        savedProfiles[index] = payload;
      } else {
        savedProfiles.push(payload);
      }
      configCache.savedProfiles = savedProfiles;
      document.getElementById('savedProfilesSelect').innerHTML = profileOptionsHtml(savedProfiles);
      await window.snmpVuApi.saveConfig({
        ...readConfigFromForm(),
        savedProfiles
      });
      debugLog('profile save', { status: 'success', name });
    };
  }
  const loadProfileBtn = document.getElementById('loadProfileBtn');
  if (loadProfileBtn) {
    loadProfileBtn.onclick = async () => {
      const index = Number(document.getElementById('savedProfilesSelect').value);
      const selected = Array.isArray(configCache.savedProfiles) ? configCache.savedProfiles[index] : null;
      if (!selected || !selected.config) {
        debugLog('profile load', { status: 'error', msg: 'Perfil no seleccionado' });
        return;
      }
      configCache = {
        ...defaultConfig(),
        ...selected.config,
        savedProfiles: configCache.savedProfiles || []
      };
      document.getElementById('refreshMs').value = configCache.refreshMs;
      document.getElementById('themeSelect').value = configCache.theme || 'blue_ice';
      document.getElementById('dockModeSelect').value = configCache.dockMode || 'none';
      document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
      document.getElementById('trayEnabled').checked = configCache.trayEnabled !== false;
      document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
      applyTheme(configCache.theme);
      applyOpacity(configCache.windowOpacity || 1);
      bindConfigEvents();
      await window.snmpVuApi.saveConfig({
        ...readConfigFromForm(),
        savedProfiles: configCache.savedProfiles || []
      });
      await window.snmpVuApi.applyDock(configCache.dockMode || 'none');
      debugLog('profile load', { status: 'success', name: selected.name });
    };
  }
  const deleteProfileBtn = document.getElementById('deleteProfileBtn');
  if (deleteProfileBtn) {
    deleteProfileBtn.onclick = async () => {
      const select = document.getElementById('savedProfilesSelect');
      const index = Number(select.value);
      const savedProfiles = Array.isArray(configCache.savedProfiles) ? [...configCache.savedProfiles] : [];
      if (!Number.isFinite(index) || !savedProfiles[index]) {
        debugLog('profile delete', { status: 'error', msg: 'Perfil no seleccionado' });
        return;
      }
      const removed = savedProfiles.splice(index, 1)[0];
      configCache.savedProfiles = savedProfiles;
      select.innerHTML = profileOptionsHtml(savedProfiles);
      select.value = '';
      await window.snmpVuApi.saveConfig({
        ...readConfigFromForm(),
        savedProfiles
      });
      debugLog('profile delete', { status: 'success', name: removed.name });
    };
  }
  const saveBtn = document.getElementById('saveConfigBtn');
  if (saveBtn) {
    saveBtn.onclick = async () => {
      await window.snmpVuApi.saveConfig(readConfigFromForm());
      window.close();
    };
  }
}

let audioCtx = null;
let alarmTimer = null;
let alarmPhase = 0;
function updateAlarm(items) {
  const shouldAlarm = (items || []).some((item) => item.enabled && item.alarmEnabled && item.pingColor === 'red');
  if (!shouldAlarm) {
    if (alarmTimer) {
      clearInterval(alarmTimer);
      alarmTimer = null;
    }
    alarmPhase = 0;
    return;
  }
  if (alarmTimer) return;
  alarmTimer = setInterval(() => {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = alarmPhase % 2 === 0 ? 'sawtooth' : 'square';
      osc.frequency.setValueAtTime(alarmPhase % 2 === 0 ? 780 : 1180, audioCtx.currentTime);
      gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.18, audioCtx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.42);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.42);
      alarmPhase += 1;
    } catch (e) {}
  }, 460);
}
