const tauriCore = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__.core : null;
const tauriEvent = window.__TAURI__ && window.__TAURI__.event ? window.__TAURI__.event : null;
const tauriWindow = window.__TAURI__ && window.__TAURI__.window ? window.__TAURI__.window : null;

const presets = {
  mikrotik_ether1_in: { label: 'ether1 IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'auto', scaleMbps: 100 },
  mikrotik_ether1_out: { label: 'ether1 OUT', oid: '1.3.6.1.2.1.2.2.1.16.1', mode: 'auto', scaleMbps: 100 },
  mikrotik_wlan1_in: { label: 'wlan1 IN', oid: '1.3.6.1.2.1.2.2.1.10.2', mode: 'auto', scaleMbps: 100 },
  mikrotik_bridge_in: { label: 'bridge IN', oid: '1.3.6.1.2.1.2.2.1.10.3', mode: 'auto', scaleMbps: 300 },
  mikrotik_pppoe_out: { label: 'PPPoE/WAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.4', mode: 'auto', scaleMbps: 300 }
};

let configCache = null;
let profileList = [];

function invoke(cmd, args = {}) {
  if (!tauriCore || !tauriCore.invoke) throw new Error('Tauri API no disponible');
  return tauriCore.invoke(cmd, args);
}

function setStatus(text, kind = 'info') {
  const el = document.getElementById('configStatus');
  if (!el) return;
  el.textContent = text;
  el.dataset.kind = kind;
}

function profileOptionsHtml(items) {
  return ['<option value="">Perfiles guardados</option>']
    .concat((items || []).map((name) => `<option value="${name}">${name}</option>`))
    .join('');
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
  const valueEl = document.getElementById('mainWidthValue');
  if (valueEl) valueEl.textContent = `${safe}px`;
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

async function renderConfig() {
  document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
  document.getElementById('refreshMs').value = configCache.refreshMs;
  document.getElementById('themeSelect').value = configCache.theme;
  document.getElementById('windowOpacityRange').value = Math.round((configCache.windowOpacity || 1) * 100);
  document.getElementById('mainWidthRange').value = configCache.mainWidth || 192;
  applyTheme(configCache.theme);
  applyOpacity(configCache.windowOpacity || 1);
  applyMainWidth(configCache.mainWidth || 192);
  bindConfigEvents();
  try {
    profileList = await invoke('list_profiles');
  } catch (_) {
    profileList = [];
  }
  document.getElementById('savedProfilesSelect').innerHTML = profileOptionsHtml(profileList);
}

async function loadConfig() {
  configCache = await invoke('get_config');
  await renderConfig();
}

async function boot() {
  try {
    const meta = await invoke('get_meta');
    document.getElementById('buildBadge').textContent = `v${meta.version} #${meta.build}`;
  } catch (_) {}
  await loadConfig();
  setStatus('Configuracion lista', 'ok');

  document.getElementById('saveConfigBtn').onclick = async () => {
    try {
      configCache = await invoke('save_config', { cfg: readConfigFromForm() });
      await renderConfig();
      setStatus('Configuracion guardada', 'ok');
    } catch (error) {
      setStatus(`Error guardando: ${error.message || error}`, 'error');
    }
  };

  document.getElementById('closeConfigBtn').onclick = async () => {
    if (tauriWindow && tauriWindow.getCurrentWindow) {
      await tauriWindow.getCurrentWindow().close();
    }
  };

  document.getElementById('exportConfigBtn').onclick = async () => {
    const name = (document.getElementById('profileNameInput').value || '').trim();
    if (!name) {
      setStatus('Falta nombre de perfil', 'error');
      return;
    }
    try {
      await invoke('export_profile', { name, cfg: readConfigFromForm() });
      profileList = await invoke('list_profiles');
      document.getElementById('savedProfilesSelect').innerHTML = profileOptionsHtml(profileList);
      setStatus(`Perfil exportado: ${name}`, 'ok');
    } catch (error) {
      setStatus(`Error exportando: ${error.message || error}`, 'error');
    }
  };

  document.getElementById('importConfigBtn').onclick = async () => {
    const name = document.getElementById('savedProfilesSelect').value;
    if (!name) {
      setStatus('Selecciona un perfil', 'error');
      return;
    }
    try {
      configCache = await invoke('import_profile', { name });
      await renderConfig();
      setStatus(`Perfil importado: ${name}`, 'ok');
    } catch (error) {
      setStatus(`Error importando: ${error.message || error}`, 'error');
    }
  };

  if (tauriEvent && tauriEvent.listen) {
    tauriEvent.listen('config-updated', async () => {
      configCache = await invoke('get_config');
      await renderConfig();
      setStatus('Configuracion actualizada desde la aplicacion', 'info');
    });
  }
}

boot().catch((error) => setStatus(`Error inicial: ${error.message || error}`, 'error'));
