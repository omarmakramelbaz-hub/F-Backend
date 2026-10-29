'use strict';
// The screens can only call these named actions; they never touch files, the database or the network directly.
const { contextBridge, ipcRenderer } = require('electron');

const CHANNELS = ['app:info', 'setup:enroll', 'setup:reset', 'auth:login', 'auth:logout', 'auth:branch', 'catalog:get', 'tickets:open', 'tickets:save',
  'tickets:delete', 'tickets:quote', 'tickets:pay', 'tickets:kitchen', 'receipts:list', 'receipts:print', 'cash:summary', 'cash:expense', 'cash:till', 'print:printers',
  'print:settings', 'print:test', 'sync:status', 'sync:now', 'dashboard:open'];

contextBridge.exposeInMainWorld('goerp', {
  async call(channel, values) {
    if (!CHANNELS.includes(channel)) throw new Error('طلب غير معروف.');
    const response = await ipcRenderer.invoke(channel, values);
    if (!response.ok) throw new Error(response.error);
    return response.value;
  },
  onSyncStatus(callback) {
    const listener = (event, status) => callback(status);
    ipcRenderer.on('sync:status', listener);
    return () => ipcRenderer.removeListener('sync:status', listener);
  },
});
