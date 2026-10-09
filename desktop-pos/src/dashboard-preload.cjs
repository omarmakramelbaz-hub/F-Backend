const { contextBridge, ipcRenderer } = require('electron');
// These bounded native actions never expose the offline database or enrollment credential.
async function invoke(name, value) {
  const result = await ipcRenderer.invoke('dashboard:' + name, value);
  if (!result.ok) throw Error(result.error);
  return result.value;
}
contextBridge.exposeInMainWorld('FasakhanstaDesktop', Object.freeze({
  printReceipt: url => invoke('print-receipt', String(url)),
  status: () => invoke('status'),
  prepare: csrf => invoke('prepare', { csrf: String(csrf) }),
  synchronize: () => invoke('synchronize'),
  onState: callback => {
    if (typeof callback !== 'function') throw Error('Invalid state callback.');
    const listener = (_event, state) => callback(state);
    ipcRenderer.on('dashboard:state', listener);
    return () => ipcRenderer.removeListener('dashboard:state', listener);
  }
}));
