const { app, BrowserWindow, ipcMain, shell, dialog, screen } = require('electron');
const path = require('path');
const https = require('https');
const http = require('http');
const { execFile } = require('child_process');
const fs = require('fs');
const Store = require('electron-store');
const snmp = require('net-snmp');

const CONFIG_KEY = 'config';
const APP_VERSION = app.getVersion();
const APP_BUILD = '20260315.140210';
const APP_ICON = path.join(__dirname, 'assets', 'icon.png');
const APP_ICON_ICO = path.join(__dirname, 'assets', 'icon.ico');
const UPDATE_URLS = [
  'https://www.palweb.net/apk/snmp-vu-monitor-update.json',
  'https://shop.palweb.net/apk/snmp-vu-monitor-update.json'
];
const store = new Store({
  name: 'snmp-vu-monitor',
  defaults: {
    refreshMs: 3000,
    theme: 'blue_ice',
    dockMode: 'none',
    savedProfiles: [],
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
      pingIp: '192.168.88.1',
      alarmEnabled: false
    })),
    windowState: {
      main: { width: 240, height: 980 },
      config: { width: 980, height: 820 }
    }
  }
});

let mainWindow;
let configWindow;
const previousState = new Map();
const pingState = new Map();

function versionToSnmp(version) {
  if (version === '1') return snmp.Version1;
  return snmp.Version2c;
}

function getConfig() {
  const cfg = store.get(CONFIG_KEY);
  if (!cfg.items || !Array.isArray(cfg.items)) {
    return store.store;
  }
  cfg.theme = cfg.theme || 'blue_ice';
  cfg.dockMode = cfg.dockMode || 'none';
  cfg.savedProfiles = Array.isArray(cfg.savedProfiles) ? cfg.savedProfiles : [];
  if (!cfg.windowState || typeof cfg.windowState !== 'object') {
    cfg.windowState = {
      main: { width: 240, height: 980 },
      config: { width: 980, height: 820 }
    };
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
    pingIp: (cfg.items[idx] && cfg.items[idx].pingIp) || '',
    alarmEnabled: !!(cfg.items[idx] && cfg.items[idx].alarmEnabled)
  }));
  cfg.refreshMs = Math.max(1000, Number(cfg.refreshMs || 3000));
  return cfg;
}

function setConfig(nextConfig) {
  const current = getConfig();
  store.set(CONFIG_KEY, {
    ...current,
    ...nextConfig,
    windowState: current.windowState || nextConfig.windowState
  });
  return getConfig();
}

function boundsVisible(bounds) {
  const displays = screen.getAllDisplays();
  return displays.some((display) => {
    const area = display.workArea;
    return bounds.x >= area.x - bounds.width
      && bounds.y >= area.y - bounds.height
      && bounds.x <= area.x + area.width
      && bounds.y <= area.y + area.height;
  });
}

function getWindowState(kind, defaults) {
  const cfg = getConfig();
  const state = (cfg.windowState && cfg.windowState[kind]) || {};
  const next = {
    width: Number(state.width || defaults.width),
    height: Number(state.height || defaults.height)
  };
  if (Number.isFinite(state.x) && Number.isFinite(state.y)) {
    next.x = Number(state.x);
    next.y = Number(state.y);
  }
  if (typeof next.x === 'number' && typeof next.y === 'number' && !boundsVisible(next)) {
    delete next.x;
    delete next.y;
  }
  return next;
}

function saveWindowState(kind, win) {
  if (!win || win.isDestroyed()) return;
  const bounds = win.getBounds();
  const cfg = getConfig();
  cfg.windowState = cfg.windowState || {};
  cfg.windowState[kind] = {
    x: bounds.x,
    y: bounds.y,
    width: bounds.width,
    height: bounds.height
  };
  store.set(CONFIG_KEY, cfg);
}

function createMainWindow() {
  const state = getWindowState('main', { width: 240, height: 980 });
  mainWindow = new BrowserWindow({
    ...state,
    minWidth: 220,
    minHeight: 760,
    alwaysOnTop: true,
    autoHideMenuBar: true,
    title: `PalWeb SNMP VU v${APP_VERSION} #${APP_BUILD}`,
    icon: APP_ICON,
    backgroundColor: '#08111f',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  mainWindow.setAlwaysOnTop(true, 'screen-saver');
  applyDockMode(mainWindow, getConfig().dockMode);
  ['move', 'resize'].forEach((eventName) => {
    mainWindow.on(eventName, () => saveWindowState('main', mainWindow));
  });
  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

function createConfigWindow() {
  if (configWindow && !configWindow.isDestroyed()) {
    configWindow.focus();
    return;
  }
  const state = getWindowState('config', { width: 980, height: 820 });
  configWindow = new BrowserWindow({
    ...state,
    parent: mainWindow,
    modal: true,
    autoHideMenuBar: true,
    title: `Configuracion PalWeb SNMP VU v${APP_VERSION} #${APP_BUILD}`,
    icon: APP_ICON,
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
  ['move', 'resize'].forEach((eventName) => {
    configWindow.on(eventName, () => saveWindowState('config', configWindow));
  });
  configWindow.loadFile(path.join(__dirname, 'renderer', 'config.html'));
}

function applyDockMode(win, mode) {
  if (!win || win.isDestroyed()) return;
  const display = screen.getDisplayMatching(win.getBounds());
  const area = display.workArea;
  const bounds = win.getBounds();
  if (mode === 'left') {
    win.setPosition(area.x, area.y);
    win.setSize(bounds.width, area.height);
  } else if (mode === 'right') {
    win.setPosition(area.x + area.width - bounds.width, area.y);
    win.setSize(bounds.width, area.height);
  }
}

function compareVersions(a, b) {
  const pa = String(a || '').split('.').map((n) => parseInt(n || '0', 10));
  const pb = String(b || '').split('.').map((n) => parseInt(n || '0', 10));
  const len = Math.max(pa.length, pb.length);
  for (let i = 0; i < len; i += 1) {
    const av = pa[i] || 0;
    const bv = pb[i] || 0;
    if (av > bv) return 1;
    if (av < bv) return -1;
  }
  return 0;
}

function fetchJson(url) {
  return new Promise((resolve, reject) => {
    const client = url.startsWith('https://') ? https : http;
    client.get(url, (res) => {
      if (res.statusCode && res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        fetchJson(res.headers.location).then(resolve).catch(reject);
        return;
      }
      if (res.statusCode !== 200) {
        reject(new Error(`HTTP ${res.statusCode || 0}`));
        return;
      }
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        try {
          resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
        } catch (err) {
          reject(err);
        }
      });
    }).on('error', reject);
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
    return Math.max(0, (raw / 8) / 1000000);
  }
  const prev = previousState.get(item.label);
  previousState.set(item.label, { raw, ts: now });
  if (!prev || now <= prev.ts || raw < prev.raw) {
    return 0;
  }
  const delta = raw - prev.raw;
  const seconds = Math.max(1, (now - prev.ts) / 1000);
  if (item.mode === 'counter_bits') {
    return Math.max(0, ((delta / seconds) / 8) / 1000000);
  }
  return Math.max(0, (delta / seconds) / 1000000);
}

function fetchJsonWithFallback(urls, index = 0) {
  if (index >= urls.length) {
    return Promise.reject(new Error('No se pudo acceder a ningun endpoint de updates'));
  }
  return fetchJson(urls[index]).catch(() => fetchJsonWithFallback(urls, index + 1));
}

function pingHost(ip) {
  return new Promise((resolve) => {
    if (!ip) {
      resolve({ ok: false, ms: null });
      return;
    }
    const args = process.platform === 'win32' ? ['-n', '1', '-w', '1000', ip] : ['-c', '1', '-W', '1', ip];
    execFile('ping', args, { timeout: 2500 }, (error, stdout = '', stderr = '') => {
      const text = `${stdout}\n${stderr}`;
      const match = text.match(/time[=<]?\s*([0-9]+(?:[.,][0-9]+)?)\s*ms/i);
      const ms = match ? Number(String(match[1]).replace(',', '.')) : null;
      resolve({ ok: !error, ms });
    });
  });
}

function pingColorFor(itemKey, ms, ok) {
  const prev = pingState.get(itemKey) || { orangeStreak: 0 };
  let orangeStreak = prev.orangeStreak || 0;
  let color = 'red';
  if (ok && ms !== null && ms < 100) {
    color = 'green';
    orangeStreak = 0;
  } else if (ok && ms !== null && ms < 300) {
    color = 'yellow';
    orangeStreak = 0;
  } else if (ok && ms !== null && ms <= 900) {
    orangeStreak += 1;
    color = 'orange';
  } else {
    orangeStreak += 1;
    color = orangeStreak >= 4 ? 'red' : 'orange';
  }
  pingState.set(itemKey, { orangeStreak });
  return color;
}

async function pollItems() {
  const cfg = getConfig();
  const now = Date.now();
  const items = await Promise.all(cfg.items.map(async (item, index) => {
    const ping = await pingHost(item.pingIp);
    const pingColor = pingColorFor(`${index}:${item.label}`, ping.ms, ping.ok);
    const base = {
      index,
      label: item.label,
      enabled: !!item.enabled,
      mbps: 0,
      percent: 0,
      raw: null,
      pingOk: ping.ok,
      pingMs: ping.ms,
      pingColor,
      msg: item.enabled ? 'Sin datos' : 'Desactivado',
      scaleMbps: item.scaleMbps,
      alarmEnabled: !!item.alarmEnabled
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
ipcMain.handle('config:set', async (_event, nextConfig) => {
  const saved = setConfig(nextConfig);
  applyDockMode(mainWindow, saved.dockMode);
  return saved;
});
ipcMain.handle('config:open', async () => {
  createConfigWindow();
  return { status: 'success' };
});
ipcMain.handle('snmp:poll', async () => pollItems());
ipcMain.handle('snmp:walk', async (_event, item) => snmpWalk(item));
ipcMain.handle('app:get-meta', async () => ({ version: APP_VERSION, build: APP_BUILD, updateUrl: UPDATE_URLS[0] }));
ipcMain.handle('window:apply-dock', async (_event, mode) => {
  const cfg = getConfig();
  cfg.dockMode = mode || 'none';
  store.set(CONFIG_KEY, cfg);
  applyDockMode(mainWindow, cfg.dockMode);
  return { status: 'success', dockMode: cfg.dockMode };
});
ipcMain.handle('config:export', async (_event, payload) => {
  const selected = await dialog.showSaveDialog(configWindow || mainWindow, {
    title: 'Exportar perfil SNMP',
    defaultPath: `palweb-snmp-vu-${APP_VERSION}.json`,
    filters: [{ name: 'JSON', extensions: ['json'] }]
  });
  if (selected.canceled || !selected.filePath) {
    return { status: 'cancelled' };
  }
  fs.writeFileSync(selected.filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  return { status: 'success', filePath: selected.filePath };
});
ipcMain.handle('config:import', async () => {
  const selected = await dialog.showOpenDialog(configWindow || mainWindow, {
    title: 'Importar perfil SNMP',
    filters: [{ name: 'JSON', extensions: ['json'] }],
    properties: ['openFile']
  });
  if (selected.canceled || !selected.filePaths || !selected.filePaths[0]) {
    return { status: 'cancelled' };
  }
  const text = fs.readFileSync(selected.filePaths[0], 'utf8');
  const parsed = JSON.parse(text);
  return { status: 'success', filePath: selected.filePaths[0], config: parsed };
});
ipcMain.handle('update:check', async () => {
  try {
    const meta = await fetchJsonWithFallback(UPDATE_URLS);
    const latest = String(meta.version || '');
    return {
      status: 'success',
      currentVersion: APP_VERSION,
      latestVersion: latest,
      updateAvailable: latest !== '' && compareVersions(latest, APP_VERSION) > 0,
      exeUrl: meta.exe_url || '',
      zipUrl: meta.zip_url || '',
      notes: meta.notes || ''
    };
  } catch (error) {
    return {
      status: 'error',
      currentVersion: APP_VERSION,
      msg: error.message || 'No se pudo comprobar actualizacion'
    };
  }
});
ipcMain.handle('update:open-download', async (_event, url) => {
  if (!url) return { status: 'error', msg: 'URL vacia' };
  await shell.openExternal(url);
  return { status: 'success' };
});

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
