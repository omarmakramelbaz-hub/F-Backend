const { contextBridge, ipcRenderer } = require('electron');
// The real dashboard gets only its native receipt printer, never the offline database or device token.
contextBridge.exposeInMainWorld('FasakhanstaDesktop', Object.freeze({
  printReceipt: async url => {
    const result = await ipcRenderer.invoke('dashboard:print-receipt', String(url));
    if (!result.ok) throw Error(result.error);
    return result.value;
  }
}));
