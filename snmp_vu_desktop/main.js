const { app, BrowserWindow, ipcMain, shell, dialog, screen, nativeImage, Menu, Tray } = require('electron');
const path = require('path');
const https = require('https');
const http = require('http');
const { execFile } = require('child_process');
const fs = require('fs');
const Store = require('electron-store');
const snmp = require('net-snmp');

const CONFIG_KEY = 'config';
const APP_VERSION = app.getVersion();
const APP_BUILD = '20260315.170015';
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
    trayEnabled: true,
    windowOpacity: 1,
    savedProfiles: [],
    items: Array.from({ length: 5 }, (_, idx) => ({
      enabled: idx === 0,
      label: `VU ${idx + 1}`,
      host: '192.168.88.1',
      community: 'public',
      version: '2c',
      oid: '',
      walkOid: '1.3.6.1.2.1.2.2.1',
      mode: 'counter_bits',
      scaleMbps: idx < 4 ? 100 : 300,
      pingIp: '192.168.88.1',
      alarmEnabled: false
    })),
    windowState: {
      main: { width: 192, height: 980 },
      config: { width: 980, height: 820 }
    }
  }
});

let mainWindow;
let configWindow;
let tray;
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
  cfg.trayEnabled = cfg.trayEnabled !== false;
  cfg.windowOpacity = Math.max(0.45, Math.min(1, Number(cfg.windowOpacity || 1)));
  cfg.savedProfiles = Array.isArray(cfg.savedProfiles) ? cfg.savedProfiles : [];
  if (!cfg.windowState || typeof cfg.windowState !== 'object') {
    cfg.windowState = {
      main: { width: 192, height: 980 },
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
    mode: (cfg.items[idx] && cfg.items[idx].mode) || 'counter_bits',
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
  if (kind === 'main') {
    next.width = 192;
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
  const state = getWindowState('main', { width: 192, height: 980 });
  mainWindow = new BrowserWindow({
    ...state,
    minWidth: 192,
    maxWidth: 192,
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
  mainWindow.setOpacity(getConfig().windowOpacity);
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
  configWindow.setOpacity(getConfig().windowOpacity);
  configWindow.on('closed', () => {
    configWindow = null;
  });
  ['move', 'resize'].forEach((eventName) => {
    configWindow.on(eventName, () => saveWindowState('config', configWindow));
  });
  configWindow.loadFile(path.join(__dirname, 'renderer', 'config.html'));
}

function resolvePingCommand() {
  if (process.platform !== 'win32') {
    return { cmd: 'ping', args: ['-c', '1', '-W', '1'] };
  }
  const systemRoot = process.env.SystemRoot || 'C:\\Windows';
  return {
    cmd: path.join(systemRoot, 'System32', 'PING.EXE'),
    args: ['-n', '1', '-w', '1200']
  };
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

function createTrayIcon(color, text) {
  const safeText = String(text || '0').slice(0, 3);
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64">
      <rect x="4" y="4" width="56" height="56" rx="14" fill="${color}" stroke="#0b1320" stroke-width="4"/>
      <text x="32" y="42" text-anchor="middle" font-family="Segoe UI, Arial" font-size="24" font-weight="700" fill="#ffffff">${safeText}</text>
    </svg>`;
  return nativeImage.createFromDataURL(`data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}`);
}

function trayColorFromPercent(percent) {
  if (percent >= 100) return '#fb4f63';
  if (percent >= 85) return '#fb923c';
  if (percent >= 60) return '#facc15';
  return '#32d669';
}

function applyWindowVisuals() {
  const cfg = getConfig();
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.setOpacity(cfg.windowOpacity);
    applyDockMode(mainWindow, cfg.dockMode);
  }
  if (configWindow && !configWindow.isDestroyed()) {
    configWindow.setOpacity(cfg.windowOpacity);
  }
  if (!cfg.trayEnabled) {
    if (tray) {
      tray.destroy();
      tray = null;
    }
    return;
  }
  if (!tray) {
    tray = new Tray(createTrayIcon('#32d669', '0'));
    tray.setContextMenu(Menu.buildFromTemplate([
      { label: 'Mostrar monitor', click: () => { if (mainWindow && !mainWindow.isDestroyed()) { mainWindow.show(); mainWindow.focus(); } } },
      { label: 'Configuracion', click: () => createConfigWindow() },
      { label: 'Salir', click: () => app.quit() }
    ]));
    tray.on('click', () => {
      if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.show();
        mainWindow.focus();
      }
    });
  }
}

function updateTray(items) {
  const cfg = getConfig();
  if (!cfg.trayEnabled) {
    applyWindowVisuals();
    return;
  }
  if (!tray) {
    applyWindowVisuals();
  }
  if (!tray) return;
  const active = Array.isArray(items) ? items : [];
  const peak = active.reduce((max, item) => Math.max(max, Number(item.percent || 0)), 0);
  tray.setImage(createTrayIcon(trayColorFromPercent(peak), String(Math.round(peak))));
  tray.setToolTip(['PalWeb SNMP VU']
    .concat(active.map((item, index) => `VU${index + 1}: ${Number(item.mbps || 0).toFixed(1)} MB/s | ${Math.round(item.percent || 0)}%`))
    .join('\n'));
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
      timeout: 2000,
      retries: 1
    });
    const lines = [];
    session.subtree(item.walkOid, 20, (varbinds) => {
      for (const varbind of (Array.isArray(varbinds) ? varbinds : [])) {
        if (snmp.isVarbindError(varbind)) {
          continue;
        }
        lines.push(`${varbind.oid} = ${varbind.value}`);
      }
      return false;
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
    const pingInfo = resolvePingCommand();
    const args = [...pingInfo.args, ip];
    execFile(pingInfo.cmd, args, { timeout: 3000, windowsHide: true }, (error, stdout = '', stderr = '') => {
      const text = `${stdout}\n${stderr}`;
      const timeMatch = text.match(/(?:time|tiempo)[=<]?\s*([0-9]+(?:[.,][0-9]+)?)\s*ms/i);
      const avgMatch = text.match(/(?:Average|Media)\s*=\s*([0-9]+(?:[.,][0-9]+)?)ms/i);
      const fastMatch = /(?:time|tiempo)\s*[=<]\s*1\s*ms/i.test(text);
      const ttlOk = /ttl[=\s:]/i.test(text);
      let ms = null;
      if (timeMatch) {
        ms = Number(String(timeMatch[1]).replace(',', '.'));
      } else if (avgMatch) {
        ms = Number(String(avgMatch[1]).replace(',', '.'));
      } else if (fastMatch) {
        ms = 1;
      }
      resolve({ ok: !error || ttlOk, ms });
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
  updateTray(items);
  return { status: 'success', items, refreshMs: cfg.refreshMs };
}

ipcMain.handle('config:get', async () => getConfig());
ipcMain.handle('config:set', async (_event, nextConfig) => {
  const saved = setConfig(nextConfig);
  applyWindowVisuals();
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
  applyWindowVisuals();
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
  applyWindowVisuals();
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
