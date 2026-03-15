const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('snmpVuApi', {
  getConfig: () => ipcRenderer.invoke('config:get'),
  saveConfig: (config) => ipcRenderer.invoke('config:set', config),
  openConfig: () => ipcRenderer.invoke('config:open'),
  poll: () => ipcRenderer.invoke('snmp:poll'),
  walk: (item) => ipcRenderer.invoke('snmp:walk', item),
  isConfigWindow: process.argv.includes('--config-window')
});
