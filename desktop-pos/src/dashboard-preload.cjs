const { contextBridge, ipcRenderer } = require('electron');
// These bounded native actions never expose the offline database or enrollment credential.
async function invoke(name, value) {
  const result = await ipcRenderer.invoke('dashboard:' + name, value);
  if (!result.ok) throw Error(result.error);
  return result.value;
}
contextBridge.exposeInMainWorld('FasakhanstaDesktop', Object.freeze({
  printReceipt: (url,requestId) => invoke('print-receipt', requestId===undefined?String(url):{url:String(url),request_id:String(requestId)}),
  printEffect: id => invoke('print-effect', String(id)),
  printPending: () => invoke('print-pending'),
  status: () => invoke('status'),
  prepare: csrf => invoke('prepare', { csrf: String(csrf) }),
  synchronize: () => invoke('synchronize'),
  reviewSync: () => invoke('review-sync'),
  openLive: kind => invoke('live-social', String(kind)),
  switchAccount: () => invoke('switch-account'),
  onState: callback => {
    if (typeof callback !== 'function') throw Error('Invalid state callback.');
    const listener = (_event, state) => callback(state);
    ipcRenderer.on('dashboard:state', listener);
    return () => ipcRenderer.removeListener('dashboard:state', listener);
  }
}));

// Account selection is handled internally by the native sign-in surface.
// The original Laravel login page receives no extra account-switch button.
