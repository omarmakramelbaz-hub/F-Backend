'use strict';
// Runs beside the dashboard pages. It only carries two things to the app: a receipt the dashboard asked to print
// (printed straight on the cashier's printer, like the branches' direct-print Chrome launcher), and the request to
// switch to the device's own screens from the page shown when nothing is saved for an account yet.
const { ipcRenderer } = require('electron');

window.addEventListener('message', (event) => {
  if (event.source !== window || !event.data) return;
  if (event.data.goerp === 'device-screens') { ipcRenderer.send('dashboard:device-screens'); return; }
  if (event.data.goerp !== 'print-receipt') return;
  ipcRenderer.send('dashboard:print-receipt', { html: String(event.data.html || '').slice(0, 2 * 1024 * 1024),
    base: String(event.data.base || '') });
});
