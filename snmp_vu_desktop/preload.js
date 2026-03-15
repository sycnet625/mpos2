const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('snmpVuApi', {
  getConfig: () => ipcRenderer.invoke('config:get'),
  saveConfig: (config) => ipcRenderer.invoke('config:set', config),
  openConfig: () => ipcRenderer.invoke('config:open'),
  poll: () => ipcRenderer.invoke('snmp:poll'),
  walk: (item) => ipcRenderer.invoke('snmp:walk', item),
  getMeta: () => ipcRenderer.invoke('app:get-meta'),
  checkUpdate: () => ipcRenderer.invoke('update:check'),
  openDownload: (url) => ipcRenderer.invoke('update:open-download', url),
  isConfigWindow: process.argv.includes('--config-window'),
  selfTest: async () => {
    const result = {
      preload: true,
      isConfigWindow: process.argv.includes('--config-window'),
      ipc: false,
      config: false,
      poll: false,
      meta: false,
      details: {}
    };
    try {
      await ipcRenderer.invoke('app:get-meta');
      result.ipc = true;
      result.meta = true;
    } catch (error) {
      result.details.meta = error && error.message ? error.message : String(error);
    }
    try {
      const cfg = await ipcRenderer.invoke('config:get');
      result.config = !!cfg;
    } catch (error) {
      result.details.config = error && error.message ? error.message : String(error);
    }
    try {
      const poll = await ipcRenderer.invoke('snmp:poll');
      result.poll = !!poll;
    } catch (error) {
      result.details.poll = error && error.message ? error.message : String(error);
    }
    return result;
  }
});
