const presets = {
  mikrotik_ether1_in: { label: 'ether1 IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'counter_bytes', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  mikrotik_ether1_out: { label: 'ether1 OUT', oid: '1.3.6.1.2.1.2.2.1.16.1', mode: 'counter_bytes', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  nano_m2_lan_in: { label: 'Nano M2 LAN IN', oid: '1.3.6.1.2.1.2.2.1.10.1', mode: 'counter_bytes', scaleMbps: 100, walkOid: '1.3.6.1.2.1.2.2.1' },
  nano_m5_wlan_out: { label: 'Nano M5 WLAN OUT', oid: '1.3.6.1.2.1.2.2.1.16.2', mode: 'counter_bytes', scaleMbps: 150, walkOid: '1.3.6.1.2.1.31.1.1.1' }
};

let configCache = null;
let pollTimer = null;
let updateInfo = null;

function angleFromPercent(percent) {
  return -135 + (Math.max(0, Math.min(100, percent)) * 270 / 100);
}

function gaugeSvg(index) {
  const ticks = Array.from({ length: 9 }, (_, i) => {
    const angle = (-135 + i * 33.75) * (Math.PI / 180);
    const x1 = 150 + Math.cos(angle) * 92;
    const y1 = 122 + Math.sin(angle) * 92;
    const x2 = 150 + Math.cos(angle) * 108;
    const y2 = 122 + Math.sin(angle) * 108;
    const hot = i > 6 ? 'hot' : '';
    const lx = 150 + Math.cos(angle) * 72;
    const ly = 126 + Math.sin(angle) * 72;
    return `
      <line class="dial-tick ${hot}" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"></line>
      <text class="dial-label" x="${lx}" y="${ly}" text-anchor="middle">${i * 12.5}</text>
    `;
  }).join('');
  return `
    <svg class="dial-svg" viewBox="0 0 300 160" aria-hidden="true">
      <defs>
        <linearGradient id="dialPlate" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#f5f8fc"></stop>
          <stop offset="100%" stop-color="#cad6e5"></stop>
        </linearGradient>
      </defs>
      <path class="dial-plate" d="M40,128 C54,48 246,48 260,128 L260,136 L40,136 Z"></path>
      ${ticks}
      <line class="dial-needle" id="needle-${index}" x1="150" y1="122" x2="74" y2="122"></line>
      <circle class="dial-cap" cx="150" cy="122" r="10"></circle>
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
          <div class="gauge-meta">Escala ${Number(item.scaleMbps).toFixed(0)} Mb/s</div>
        </div>
        <div class="gauge-value">
          <strong id="value-${index}">0.00</strong>
          <span>Mb/s</span>
        </div>
      </div>
      <div class="dial-wrap">${gaugeSvg(index)}</div>
      <div class="dial-status" id="status-${index}">Esperando datos...</div>
    </div>
  `).join('');
}

function updateMain(items) {
  items.forEach((item, index) => {
    const needle = document.getElementById(`needle-${index}`);
    const led = document.getElementById(`led-${index}`);
    const value = document.getElementById(`value-${index}`);
    const status = document.getElementById(`status-${index}`);
    if (needle) {
      needle.style.transform = `rotate(${angleFromPercent(item.percent)}deg)`;
    }
    if (led) {
      led.classList.toggle('green', !!item.pingOk);
      led.classList.toggle('red', !item.pingOk);
    }
    if (value) value.textContent = Number(item.mbps || 0).toFixed(2);
    if (status) status.textContent = item.enabled ? `${item.msg} | raw ${item.raw ?? '-'} | ping ${item.pingOk ? 'OK' : 'FAIL'}` : 'Desactivado';
  });
}

async function pollLoop() {
  const result = await window.snmpVuApi.poll();
  if (result && result.status === 'success') {
    updateMain(result.items);
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(pollLoop, result.refreshMs || 3000);
  } else {
    pollTimer = setTimeout(pollLoop, 3000);
  }
}

function setUpdateBanner(text, available = false) {
  const el = document.getElementById('updateBanner');
  if (!el) return;
  el.textContent = text;
  el.classList.toggle('available', available);
}

async function checkForUpdates(openIfAvailable = false) {
  const meta = await window.snmpVuApi.getMeta();
  const result = await window.snmpVuApi.checkUpdate();
  if (result.status !== 'success') {
    setUpdateBanner(`Version ${meta.version} | sin acceso a updates`, false);
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
        <option value="nano_m2_lan_in">NanoStation M2 LAN IN</option>
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
        <option value="direct_mbps" ${item.mode === 'direct_mbps' ? 'selected' : ''}>Valor directo Mb/s</option>
      </select>
      <label class="field-label">Escala Mb/s</label>
      <input class="text-input" type="number" min="1" step="0.1" id="scale_${index}" value="${item.scaleMbps}">
      <label class="field-label">IP Ping</label>
      <input class="text-input" id="ping_${index}" value="${item.pingIp}">
      <label class="field-label">OID walk</label>
      <input class="text-input" id="walk_${index}" value="${item.walkOid}">
      <button class="secondary-btn" data-walk="${index}">SNMP walk</button>
    </div>
  `;
}

function readConfigFromForm() {
  return {
    refreshMs: Number(document.getElementById('refreshMs').value || 3000),
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
      pingIp: document.getElementById(`ping_${index}`).value
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
    });
  });
  const saveBtn = document.getElementById('saveConfigBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      await window.snmpVuApi.saveConfig(readConfigFromForm());
      window.close();
    });
  }
}

async function bootMain() {
  configCache = await window.snmpVuApi.getConfig();
  renderMain(configCache.items);
  const meta = await window.snmpVuApi.getMeta();
  setUpdateBanner(`Version ${meta.version}`, false);
  const openBtn = document.getElementById('openConfigBtn');
  if (openBtn) {
    openBtn.addEventListener('click', () => window.snmpVuApi.openConfig());
  }
  const updateBtn = document.getElementById('checkUpdateBtn');
  if (updateBtn) {
    updateBtn.addEventListener('click', async () => {
      await checkForUpdates(true);
    });
  }
  checkForUpdates(false);
  pollLoop();
}

async function bootConfig() {
  configCache = await window.snmpVuApi.getConfig();
  document.getElementById('refreshMs').value = configCache.refreshMs;
  document.getElementById('configGrid').innerHTML = configCache.items.map(configBox).join('');
  bindConfigEvents();
}

if (window.snmpVuApi.isConfigWindow) {
  bootConfig();
} else {
  bootMain();
}
