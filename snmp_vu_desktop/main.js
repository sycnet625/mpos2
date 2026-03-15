const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const { execFile } = require('child_process');
const Store = require('electron-store');
const snmp = require('net-snmp');

const CONFIG_KEY = 'config';
const store = new Store({
  name: 'snmp-vu-monitor',
  defaults: {
    refreshMs: 3000,
    items: Array.from({ length: 5 }, (_, idx) => ({
      enabled: idx === 0,
      label: `VU ${idx + 1}`,
      host: '192.168.88.1',
      community: 'public',
      version: '2c',
      oid: '',
      walkOid: '1.3.6.1.2.1.2.2.1',
      mode: 'counter_bytes',
      scaleMbps: idx < 4 ? 100 : 300,
      pingIp: '192.168.88.1'
    }))
  }
});

let mainWindow;
let configWindow;
const previousState = new Map();

function versionToSnmp(version) {
  if (version === '1') return snmp.Version1;
  return snmp.Version2c;
}

function getConfig() {
  const cfg = store.get(CONFIG_KEY);
  if (!cfg.items || !Array.isArray(cfg.items)) {
    return store.store;
  }
  cfg.items = Array.from({ length: 5 }, (_, idx) => ({
    enabled: !!(cfg.items[idx] && cfg.items[idx].enabled),
    label: (cfg.items[idx] && cfg.items[idx].label) || `VU ${idx + 1}`,
    host: (cfg.items[idx] && cfg.items[idx].host) || '',
    community: (cfg.items[idx] && cfg.items[idx].community) || 'public',
    version: (cfg.items[idx] && cfg.items[idx].version) || '2c',
    oid: (cfg.items[idx] && cfg.items[idx].oid) || '',
    walkOid: (cfg.items[idx] && cfg.items[idx].walkOid) || '1.3.6.1.2.1.2.2.1',
    mode: (cfg.items[idx] && cfg.items[idx].mode) || 'counter_bytes',
    scaleMbps: Number((cfg.items[idx] && cfg.items[idx].scaleMbps) || 100),
    pingIp: (cfg.items[idx] && cfg.items[idx].pingIp) || ''
  }));
  cfg.refreshMs = Math.max(1000, Number(cfg.refreshMs || 3000));
  return cfg;
}

function setConfig(nextConfig) {
  store.set(CONFIG_KEY, nextConfig);
  return getConfig();
}

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 460,
    height: 980,
    minWidth: 400,
    minHeight: 760,
    autoHideMenuBar: true,
    title: 'SNMP VU Monitor',
    backgroundColor: '#08111f',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

function createConfigWindow() {
  if (configWindow && !configWindow.isDestroyed()) {
    configWindow.focus();
    return;
  }
  configWindow = new BrowserWindow({
    width: 980,
    height: 820,
    parent: mainWindow,
    modal: true,
    autoHideMenuBar: true,
    title: 'Configuracion SNMP VU',
    backgroundColor: '#e7edf4',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      additionalArguments: ['--config-window']
    }
  });
  configWindow.on('closed', () => {
    configWindow = null;
  });
  configWindow.loadFile(path.join(__dirname, 'renderer', 'config.html'));
}

function pingHost(ip) {
  return new Promise((resolve) => {
    if (!ip) {
      resolve(false);
      return;
    }
    const args = process.platform === 'win32' ? ['-n', '1', '-w', '1000', ip] : ['-c', '1', '-W', '1', ip];
    execFile('ping', args, { timeout: 2000 }, (error) => {
      resolve(!error);
    });
  });
}

function snmpGetValue(item) {
  return new Promise((resolve) => {
    if (!item.host || !item.community || !item.oid) {
      resolve({ ok: false, msg: 'Faltan host/community/OID' });
      return;
    }

    const session = snmp.createSession(item.host, item.community, {
      version: versionToSnmp(item.version),
      timeout: 1200,
      retries: 0
    });

    session.get([item.oid], (error, varbinds) => {
      session.close();
      if (error) {
        resolve({ ok: false, msg: error.message || 'Error SNMP' });
        return;
      }
      if (!Array.isArray(varbinds) || !varbinds[0]) {
        resolve({ ok: false, msg: 'Sin respuesta SNMP' });
        return;
      }
      const vb = varbinds[0];
      if (snmp.isVarbindError(vb)) {
        resolve({ ok: false, msg: snmp.varbindError(vb) });
        return;
      }
      const value = Number(vb.value);
      if (Number.isNaN(value)) {
        resolve({ ok: false, msg: 'Valor no numerico' });
        return;
      }
      resolve({ ok: true, raw: value });
    });
  });
}

function snmpWalk(item) {
  return new Promise((resolve) => {
    if (!item.host || !item.community || !item.walkOid) {
      resolve({ ok: false, msg: 'Faltan host/community/OID base' });
      return;
    }
    const session = snmp.createSession(item.host, item.community, {
      version: versionToSnmp(item.version),
      timeout: 1200,
      retries: 0
    });
    const lines = [];
    session.subtree(item.walkOid, (varbind) => {
      if (!snmp.isVarbindError(varbind)) {
        lines.push(`${varbind.oid} = ${varbind.value}`);
      }
    }, (error) => {
      session.close();
      if (error) {
        resolve({ ok: false, msg: error.message || 'Error SNMP walk' });
        return;
      }
      resolve({ ok: true, output: lines.join('\n') || 'Sin resultados' });
    });
  });
}

function calculateMbps(item, raw, now) {
  if (item.mode === 'direct_mbps') {
    return Math.max(0, raw);
  }
  if (item.mode === 'direct_bps') {
    return Math.max(0, raw / 1000000);
  }
  const prev = previousState.get(item.label);
  previousState.set(item.label, { raw, ts: now });
  if (!prev || now <= prev.ts || raw < prev.raw) {
    return 0;
  }
  const delta = raw - prev.raw;
  const seconds = Math.max(1, (now - prev.ts) / 1000);
  if (item.mode === 'counter_bits') {
    return Math.max(0, (delta / seconds) / 1000000);
  }
  return Math.max(0, ((delta / seconds) * 8) / 1000000);
}

async function pollItems() {
  const cfg = getConfig();
  const now = Date.now();
  const items = await Promise.all(cfg.items.map(async (item, index) => {
    const pingOk = await pingHost(item.pingIp);
    const base = {
      index,
      label: item.label,
      enabled: !!item.enabled,
      mbps: 0,
      percent: 0,
      raw: null,
      pingOk,
      msg: item.enabled ? 'Sin datos' : 'Desactivado',
      scaleMbps: item.scaleMbps
    };
    if (!item.enabled) {
      return base;
    }
    const snmpRes = await snmpGetValue(item);
    if (!snmpRes.ok) {
      return { ...base, msg: snmpRes.msg };
    }
    const mbps = calculateMbps(item, snmpRes.raw, now);
    return {
      ...base,
      raw: snmpRes.raw,
      mbps,
      percent: Math.max(0, Math.min(100, (mbps / Math.max(1, item.scaleMbps)) * 100)),
      msg: 'OK'
    };
  }));
  return { status: 'success', items, refreshMs: cfg.refreshMs };
}

ipcMain.handle('config:get', async () => getConfig());
ipcMain.handle('config:set', async (_event, nextConfig) => setConfig(nextConfig));
ipcMain.handle('config:open', async () => {
  createConfigWindow();
  return { status: 'success' };
});
ipcMain.handle('snmp:poll', async () => pollItems());
ipcMain.handle('snmp:walk', async (_event, item) => snmpWalk(item));

app.whenReady().then(() => {
  createMainWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createMainWindow();
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});
