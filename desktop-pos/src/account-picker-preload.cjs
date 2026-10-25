'use strict';
const { contextBridge, ipcRenderer } = require('electron');
const argument = process.argv.find(value => /^--go-account-picker=[a-f0-9]{32}$/.test(value));
if (argument) {
  const channel = 'go-account-picker:' + argument.slice('--go-account-picker='.length);
  contextBridge.exposeInMainWorld('goAccounts', Object.freeze({
    list: () => ipcRenderer.invoke(channel, { action: 'list' }),
    choose: input => {
      if (input?.action === 'select' && typeof input.id === 'string') return ipcRenderer.invoke(channel, { action: 'select', id: input.id });
      if (input?.action === 'add' || input?.action === 'cancel') return ipcRenderer.invoke(channel, { action: input.action });
      return Promise.resolve(false);
    }
  }));
}
