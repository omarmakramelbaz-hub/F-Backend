'use strict';
// Runs beside the dashboard pages. It carries a receipt the dashboard asked to print (printed straight on the
// cashier's printer, like the branches' direct-print Chrome launcher), the request to switch to the app's stand-in
// cashier from the page shown when nothing is saved for an account yet, and it draws this device's part of the
// dashboard's «برنامج الكمبيوتر» page (its setup for working without the internet, its open orders and printers).
const { ipcRenderer } = require('electron');

window.addEventListener('message', (event) => {
  if (event.source !== window || !event.data) return;
  if (event.data.goerp === 'device-screens') { ipcRenderer.send('dashboard:device-screens'); return; }
  if (event.data.goerp !== 'print-receipt') return;
  ipcRenderer.send('dashboard:print-receipt', { html: String(event.data.html || '').slice(0, 2 * 1024 * 1024),
    base: String(event.data.base || '') });
});

// ---- without the internet: the branches this device was not set up for ----

// The pages' branch lists (takeaway, dine-in, delivery, expenses, stock, shift, employees) use the dashboard's branch
// keys. While the pages run from the device, a branch it was not set up for has nothing here (menu, orders, shift), so
// it is shown as such and cannot be picked; online every branch is back as the dashboard offers it.
const BRANCH_VALUE = /^[a-z]+:[0-9]+$/;
const NOT_HERE = ' — مش متجهز على الجهاز';
let deviceBranches = null; // the device's branches while the pages run from it; null online
let marking = false;

function markBranches() {
  if (marking) return;
  marking = true;
  try {
    for (const select of document.querySelectorAll('select')) {
      for (const option of select.options) {
        if (!BRANCH_VALUE.test(option.value)) continue;
        const away = !!deviceBranches && !deviceBranches.includes(option.value);
        const marked = option.hasAttribute('data-goerp-not-here');
        if (away && !marked) {
          option.setAttribute('data-goerp-not-here', option.textContent);
          option.textContent += NOT_HERE;
          option.disabled = true;
        } else if (!away && marked) {
          option.textContent = option.getAttribute('data-goerp-not-here');
          option.removeAttribute('data-goerp-not-here');
          option.disabled = false;
        }
      }
    }
  } finally {
    marking = false;
  }
}

function useBranches(state) {
  deviceBranches = state && state.offline && Array.isArray(state.branches) && state.branches.length ? state.branches : null;
  markBranches();
}

ipcRenderer.on('goerp:branches', (event, state) => useBranches(state));
window.addEventListener('DOMContentLoaded', () => {
  ipcRenderer.invoke('dashboard:branches').then(useBranches).catch(() => {});
  // Lists the pages fill or refill later are marked as they appear.
  new MutationObserver(() => { if (deviceBranches) markBranches(); })
    .observe(document.documentElement, { childList: true, subtree: true });
});

// ---- this device, on the «برنامج الكمبيوتر» page ----

const PROGRAM_PAGE = '/admin/desktop-dashboard';
const CHANNELS = { takeaway: 'تيك أواي', dine: 'صالة', phone: 'دليفري' };

function device(action, values) {
  return ipcRenderer.invoke('dashboard:device', { action, values }).then((reply) => {
    if (!reply || !reply.ok) throw new Error((reply && reply.error) || 'حدث خطأ غير متوقع.');
    return reply.value;
  });
}

/** A page element: text is always set as text, never as markup. */
function el(tag, props = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(props)) {
    if (value === undefined || value === null || value === false) continue;
    if (key === 'text') node.textContent = String(value);
    else if (key === 'className') node.className = value;
    else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value === true ? '' : String(value));
  }
  for (const child of [].concat(children)) if (child !== null && child !== undefined && child !== false) node.append(child);
  return node;
}

function when(value) {
  const date = value ? new Date(value) : null;
  if (!date || Number.isNaN(date.getTime())) return '—';
  // Western digits, as the dashboard writes its dates and times.
  return date.toLocaleString('ar-EG-u-nu-latn', { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function card(title, attributes, children) {
  return el('div', { className: 'card', ...attributes }, el('div', { className: 'card-body' }, [el('h2', { className: 'h5', text: title }), ...children]));
}

function fact(label, value) {
  return el('div', { className: 'col-sm-6 col-lg-3 mb-2' }, [el('div', { className: 'text-muted small', text: label }), el('b', { text: value })]);
}

function drawDevicePart() {
  if (location.pathname.replace(/\/+$/, '') !== PROGRAM_PAGE || document.querySelector('[data-go-erp-device-part]')) return;
  const content = document.querySelector('section.content') || document.querySelector('.content-wrapper') || document.body;
  // The old hint («شاشات الجهاز») is replaced by the controls themselves.
  for (const paragraph of content.querySelectorAll('p')) {
    if (paragraph.textContent.includes('شاشات الجهاز')) paragraph.hidden = true;
  }

  const error = el('p', { className: 'text-danger mb-0', role: 'alert', hidden: true });
  const facts = el('div', { className: 'row' });
  const syncButton = el('button', { type: 'button', className: 'btn btn-outline-primary', 'data-go-erp-device-sync': true, text: 'مزامنة الآن' });
  const autostart = el('input', { type: 'checkbox', className: 'ml-2', 'data-go-erp-device-autostart': true });
  const rejected = el('div', { hidden: true });
  const orders = el('tbody');
  const ordersCard = card('طلبات مفتوحة على الجهاز', { 'data-go-erp-device-orders': true, hidden: true }, [
    el('p', { className: 'text-muted', text: 'كل طلب لازم يتحصّل من مكانه أو يتلغي من هنا قبل إعادة تجهيز الجهاز، عشان مفيش حاجة تضيع.' }),
    el('div', { className: 'table-responsive' }, el('table', { className: 'table table-sm mb-0' }, [
      el('thead', {}, el('tr', {}, ['الطلب', 'الفرع', 'الأصناف', 'الإجمالي', 'مكانه', ''].map((name) => el('th', { text: name })))), orders])),
  ]);

  const password = el('input', { type: 'password', className: 'form-control', autocomplete: 'current-password', 'data-go-erp-device-password': true });
  const passwordName = el('span', { className: 'd-block mb-1', text: 'كلمة المرور للتأكيد' });
  const passwordLabel = el('label', { className: 'd-block mb-2' }, [passwordName, password]);
  const releaseButton = el('button', { type: 'submit', className: 'btn btn-danger', 'data-go-erp-device-release': true, text: 'إلغاء التجهيز الحالي' });
  const releaseBack = el('button', { type: 'button', className: 'btn btn-outline-secondary', text: 'رجوع' });
  const releaseError = el('p', { className: 'text-danger mt-2 mb-0', role: 'alert', hidden: true, 'data-go-erp-device-release-error': true });
  const releaseForm = el('form', { hidden: true, style: 'max-width: 520px' }, [passwordLabel,
    el('div', { className: 'd-flex flex-wrap', style: 'gap:8px' }, [releaseButton, releaseBack])]);
  const releaseStart = el('button', { type: 'button', className: 'btn btn-warning', 'data-go-erp-device-reset': true, text: 'إعادة تجهيز الجهاز بحساب آخر' });

  const printer = el('select', { className: 'form-control' });
  const kitchen = el('select', { className: 'form-control' });
  const width = el('select', { className: 'form-control' }, [el('option', { value: '72', text: '80 مم (عرض الطباعة 72)' }), el('option', { value: '58', text: '58 مم' })]);
  const autoPrint = el('input', { type: 'checkbox', className: 'ml-2' });
  const kitchenAuto = el('input', { type: 'checkbox', className: 'ml-2' });
  const printNotice = el('p', { className: 'mb-0', role: 'status', hidden: true });
  const field = (label, control) => el('div', { className: 'col-md-4 mb-2' }, el('label', { className: 'd-block mb-0' }, [el('span', { className: 'd-block mb-1', text: label }), control]));
  const check = (control, label) => el('label', { className: 'd-block mb-1' }, [control, label]);

  const part = el('div', { 'data-go-erp-device-part': true }, [
    card('هذا الجهاز', {}, [facts, error,
      el('div', { className: 'd-flex flex-wrap align-items-center mt-1', style: 'gap:8px' }, [syncButton]),
      el('label', { className: 'd-block mt-3 mb-0' }, [autostart, 'تشغيل البرنامج تلقائيًا مع فتح ويندوز']),
      el('p', { className: 'text-muted small mt-1 mb-0', text: 'نسخة احتياطية من بيانات الجهاز بتتعمل كل يوم، وبيتحفظ آخر ٧ أيام.' }),
      rejected]),
    ordersCard,
    card('إعادة تجهيز الجهاز للعمل بدون إنترنت', { 'data-go-erp-device-setup': true }, [
      el('p', { text: 'لتجهيز الجهاز ده بحساب تاني أو لفروع تانية (مثلًا حساب الأونر عشان كل الفروع تشتغل عليه من غير نت): البرنامج بيبعت كل عمليات الجهاز '
        + 'للداشبورد الأول، وبعدها يلغي التجهيز الحالي ويفتح شاشة تجهيز الجهاز، وتسجّل فيها بالحساب اللي عايزه وتختار الفروع.' }),
      el('p', { className: 'text-muted', text: 'محتاج إنترنت، ومن غير طلبات مفتوحة على الجهاز.' }),
      releaseStart, releaseForm, releaseError]),
    card('طابعات الجهاز', { 'data-go-erp-device-printers': true }, [
      el('div', { className: 'row' }, [field('طابعة الفواتير', printer), field('طابعة المطبخ', kitchen), field('عرض الورق', width)]),
      check(autoPrint, 'طباعة الفاتورة تلقائيًا بعد إنهاء البيع'), check(kitchenAuto, 'طباعة طلب المطبخ تلقائيًا مع الفاتورة'),
      el('div', { className: 'd-flex flex-wrap mt-2', style: 'gap:8px' }, [
        el('button', { type: 'button', className: 'btn btn-primary', text: 'حفظ إعدادات الطباعة', onclick: () => savePrint() }),
        el('button', { type: 'button', className: 'btn btn-outline-secondary', text: 'طباعة تجريبية', onclick: () => testPrint() }),
        el('button', { type: 'button', className: 'btn btn-outline-secondary', text: 'تحديث قائمة الطابعات', onclick: () => loadPrinters() })]),
      printNotice]),
  ]);
  const slot = content.querySelector('[data-go-erp-device]');
  if (slot) slot.append(part);
  else {
    const offlineCard = Array.from(content.querySelectorAll('.card')).find((node) => {
      const title = node.querySelector('h2');
      return title && title.textContent.trim() === 'العمل بدون إنترنت';
    });
    if (offlineCard) offlineCard.after(part); else content.append(part);
  }

  let state = null;
  let printShown = false;
  let confirming = null;

  const showError = (node, message) => { node.textContent = message || ''; node.hidden = !message; };

  function drawFacts() {
    const status = state.sync;
    facts.replaceChildren(
      fact('اسم الجهاز', state.device ? state.device.name : '—'),
      fact('كود الجهاز في أرقام الفواتير', state.device ? state.device.code : '—'),
      fact('الفروع المتجهزة للعمل بدون إنترنت', state.branches.map((branch) => branch.name).join('، ') || '—'),
      fact('إصدار البرنامج', state.version),
      fact('الاتصال', status.online === false ? 'غير متصل — شغال من الجهاز' : 'متصل'),
      fact('آخر مزامنة ناجحة', when(status.last_sync_at)),
      fact('عمليات في الانتظار', String(status.pending)),
      fact('عمليات رفضها الداشبورد', String(status.rejected)));
    passwordName.textContent = `كلمة مرور ${state.user.name} للتأكيد`;
    autostart.checked = !!state.autostart;
    if (state.rejected.length) {
      rejected.hidden = false;
      rejected.replaceChildren(el('h3', { className: 'h6 mt-3', text: 'عمليات رفضها الداشبورد' }),
        el('p', { className: 'text-muted small', text: 'العمليات دي محفوظة على الجهاز ولم تُسجّل في الداشبورد. راجعها مع الإدارة.' }),
        el('div', { className: 'table-responsive' }, el('table', { className: 'table table-sm mb-0' }, [
          el('thead', {}, el('tr', {}, ['النوع', 'الفاتورة', 'المبلغ', 'السبب', 'الوقت'].map((name) => el('th', { text: name })))),
          el('tbody', {}, state.rejected.map((item) => el('tr', {}, [el('td', { text: item.kind }), el('td', { text: item.local_number || '-' }),
            el('td', { text: item.total || '-' }), el('td', { text: item.error || '' }), el('td', { text: when(item.at) })])))])));
    } else {
      rejected.hidden = true;
      rejected.replaceChildren();
    }
  }

  function drawOrders() {
    ordersCard.hidden = !state.orders.length;
    orders.replaceChildren(...state.orders.map((order) => {
      const actions = el('td', { className: 'text-nowrap' });
      if (confirming === order.id) {
        actions.append(el('button', { type: 'button', className: 'btn btn-sm btn-danger ml-1', 'data-go-erp-device-cancel-confirm': order.id, text: 'تأكيد الإلغاء',
          onclick: () => cancelOrder(order.id) }),
        el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', text: 'رجوع', onclick: () => { confirming = null; drawOrders(); } }));
      } else {
        actions.append(el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', 'data-go-erp-device-cancel': order.id, text: 'إلغاء الطلب',
          onclick: () => { confirming = order.id; drawOrders(); } }));
      }
      return el('tr', {}, [
        el('td', {}, [el('b', { text: `${CHANNELS[order.channel] || order.channel}${order.label ? ` · ${order.label}` : ''}` }),
          el('div', { className: 'text-muted small', text: `${when(order.created_at)}${order.actor_name ? ` · ${order.actor_name}` : ''}` })]),
        el('td', { text: order.branch_name }),
        el('td', { text: order.items.join('، ') || '—' }),
        el('td', { text: order.total || '—' }),
        el('td', {}, [el('div', { text: order.where }), order.problem ? el('div', { className: 'text-danger small', text: order.problem }) : null]),
        actions]);
    }));
  }

  function drawPrint() {
    if (printShown) return; // what the person is choosing stays as it is
    printShown = true;
    width.value = String(state.print.width === 58 ? 58 : 72);
    autoPrint.checked = state.print.auto_print !== false;
    kitchenAuto.checked = !!state.print.kitchen_auto;
    fillPrinters([]);
  }

  function fillPrinters(list) {
    const option = (value, text) => el('option', { value, text });
    const keep = (select, wanted, first) => {
      const names = list.map((item) => item.name);
      select.replaceChildren(option('', first), ...list.map((item) => option(item.name, `${item.label}${item.default && select === printer ? ' (الافتراضية)' : ''}`)),
        ...(wanted && !names.includes(wanted) ? [option(wanted, wanted)] : []));
      select.value = wanted || '';
    };
    keep(printer, printer.value || (state && state.print.printer) || '', 'اختيار الطابعة عند كل طباعة');
    keep(kitchen, kitchen.value || (state && state.print.kitchen_printer) || '', 'نفس طابعة الفواتير');
  }

  async function load() {
    try {
      state = await device('state');
      showError(error, '');
      drawFacts();
      drawOrders();
      drawPrint();
    } catch (problem) {
      showError(error, problem.message);
    }
  }

  async function loadPrinters() {
    try { fillPrinters(await device('printers')); } catch (problem) { notify(false, problem.message); }
  }

  function notify(ok, message) {
    printNotice.className = ok ? 'text-success mt-2 mb-0' : 'text-danger mt-2 mb-0';
    printNotice.textContent = message;
    printNotice.hidden = !message;
  }

  async function savePrint() {
    try {
      await device('print-settings', { printer: printer.value, kitchen_printer: kitchen.value, width: Number(width.value),
        auto_print: autoPrint.checked, kitchen_auto: kitchenAuto.checked });
      notify(true, 'تم حفظ إعدادات الطباعة.');
    } catch (problem) {
      notify(false, problem.message);
    }
  }

  async function testPrint() {
    await savePrint();
    try {
      const result = await device('print-test');
      notify(!!(result && result.ok), result && result.ok ? 'تم إرسال الطباعة التجريبية.' : ((result && result.error) || 'تم إلغاء الطباعة.'));
    } catch (problem) {
      notify(false, problem.message);
    }
  }

  async function cancelOrder(id) {
    try {
      await device('cancel-order', { id });
      confirming = null;
      showError(releaseError, '');
    } catch (problem) {
      showError(releaseError, problem.message);
    }
    await load();
  }

  syncButton.addEventListener('click', async () => {
    syncButton.disabled = true;
    syncButton.textContent = 'جاري المزامنة…';
    try { await device('sync'); } catch (problem) { showError(error, problem.message); }
    syncButton.disabled = false;
    syncButton.textContent = 'مزامنة الآن';
    await load();
  });
  autostart.addEventListener('change', async () => {
    try { autostart.checked = await device('autostart', { enabled: autostart.checked }); } catch (problem) { showError(error, problem.message); }
  });
  releaseStart.addEventListener('click', () => {
    releaseStart.hidden = true;
    releaseForm.hidden = false;
    password.focus();
  });
  releaseBack.addEventListener('click', () => {
    releaseForm.hidden = true;
    releaseStart.hidden = false;
    password.value = '';
    showError(releaseError, '');
  });
  releaseForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!password.value) return;
    showError(releaseError, '');
    releaseButton.disabled = true;
    releaseBack.disabled = true;
    releaseButton.textContent = 'جاري إرسال العمليات للداشبورد…';
    try {
      await device('release', { password: password.value });
      // The app now shows its setup screen; this page closes with the old setup.
    } catch (problem) {
      showError(releaseError, problem.message);
      await load();
    } finally {
      releaseButton.disabled = false;
      releaseBack.disabled = false;
      releaseButton.textContent = 'إلغاء التجهيز الحالي';
    }
  });

  load().then(() => loadPrinters());
  // The connection and what is waiting change with every sync (every 30 seconds); stops once the page is left.
  const timer = setInterval(() => {
    if (!part.isConnected) clearInterval(timer);
    else if (document.visibilityState === 'visible') load();
  }, 10000);
}

window.addEventListener('DOMContentLoaded', drawDevicePart);
// The dashboard's in-place navigation (its own pages without a full load).
document.addEventListener('dashboard:page-mounted', drawDevicePart);
