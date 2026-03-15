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
  isConfigWindow: process.argv.includes('--config-window')
});
