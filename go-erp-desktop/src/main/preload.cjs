'use strict';
// The screens can only call these named actions; they never touch files, the database or the network directly.
const { contextBridge, ipcRenderer } = require('electron');

const CHANNELS = ['app:info', 'app:autostart', 'app:network', 'customers:lookup', 'setup:enroll', 'setup:reset', 'auth:login', 'auth:logout', 'auth:branch', 'catalog:get', 'tickets:open', 'tickets:save',
  'tickets:delete', 'tickets:quote', 'tickets:pay', 'tickets:bill', 'tickets:kitchen', 'receipts:list', 'receipts:print', 'cash:summary', 'cash:expense', 'cash:till', 'attendance:board', 'attendance:punch', 'reports:sales', 'reports:print', 'print:printers',
  'print:settings', 'print:test', 'sync:status', 'sync:now', 'dashboard:open', 'dashboard:hide'];

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
  // shown / hidden / loaded / connection / missing-page / unavailable / signed-out / print-error, from the dashboard window
  onDashboardEvent(callback) {
    const listener = (event, value) => callback(value);
    ipcRenderer.on('dashboard:event', listener);
    return () => ipcRenderer.removeListener('dashboard:event', listener);
  },
});
