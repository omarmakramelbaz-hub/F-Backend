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

// Keep the original login form authoritative. This isolated button asks native
// code to choose another profile; it never reads or submits account credentials.
function originalLoginForm() {
  try {
    if (window.top !== window) return null;
    const current = new URL(window.location.href);
    if (!['http:', 'https:'].includes(current.protocol) || current.username || current.password || current.pathname !== '/admin/login') return null;
    const form = document.querySelector('form[data-dashboard-login]');
    if (!form || !form.isConnected || String(form.getAttribute('method')).toLowerCase() !== 'post') return null;
    const action = form.getAttribute('action');
    if (!action || new URL(action, current).href !== new URL('/admin/signin', current).href) return null;
    const target = form.getAttribute('target');
    return !target || target === '_self' ? form : null;
  } catch { return null; }
}
async function addAccountSwitch() {
  const form = originalLoginForm();
  if (!form || document.querySelector('[data-go-erp-account-switch]')) return;
  try {
    if (await invoke('account-switch-available') !== true || originalLoginForm() !== form
      || document.querySelector('[data-go-erp-account-switch]')) return;
    const container = document.createElement('div'), button = document.createElement('button'), status = document.createElement('p');
    container.setAttribute('data-go-erp-account-switch', '');
    button.type = 'button'; button.className = 'dashboard-login-submit'; button.textContent = 'تبديل الحساب';
    status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.hidden = true;
    let pending = false;
    button.addEventListener('click', async event => {
      event.preventDefault();
      if (pending || originalLoginForm() !== form) return;
      pending = true; button.disabled = true; status.hidden = true; status.textContent = '';
      try { await invoke('switch-account'); }
      catch {
        status.textContent = 'تعذر تبديل الحساب. حاول مرة أخرى من قائمة البرنامج.';
        status.hidden = false;
      } finally { pending = false; button.disabled = false; }
    });
    container.append(button, status); form.after(container);
  } catch { /* Unsupported pages and native shutdown must leave login usable. */ }
}
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addAccountSwitch, { once: true });
  else void addAccountSwitch();
}
