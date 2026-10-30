'use strict';
// Runs beside the live dashboard pages. It only carries a receipt the dashboard asked to print to the app,
// which prints it directly on the cashier's printer (like the branches' direct-print Chrome launcher).
const { ipcRenderer } = require('electron');

window.addEventListener('message', (event) => {
  if (event.source !== window || !event.data || event.data.goerp !== 'print-receipt') return;
  ipcRenderer.send('dashboard:print-receipt', { html: String(event.data.html || '').slice(0, 2 * 1024 * 1024),
    base: String(event.data.base || '') });
});
