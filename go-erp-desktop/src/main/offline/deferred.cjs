'use strict';
// The dashboard pages' other operations while the internet is down (an employee's details, a table, a setting, an app
// order's items...): no offline page answers them, so the page's own request is kept on the device under the page's
// operation number and sent to the dashboard as the same account once it answers again, in the order they were done.
// The dashboard applies an operation number once, so a request sent twice is done once. A request without an operation
// number (a price check, a search), with a file, or that must see the dashboard's figures of the moment (closing and
// paying a month's pay) needs the internet.

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NEEDS_DASHBOARD = /^\/admin\/(employees\/(close|pay|identity)(\/|$)|desktop-dashboard(\/|$)|signin|login|logout|adminLogout)/i;
const SAVED = 'اتسجلت على الجهاز، وهتتبعت للداشبورد لوحدها أول ما النت يرجع.';
const FILES = 'المرفقات والصور محتاجة إنترنت. احفظ من غير مرفق دلوقتي، وضيفه من الداشبورد لما النت يرجع.';
const LABELS = [
  [/^\/admin\/employees\/save$/, 'بيانات موظف'], [/^\/admin\/employees\/wallet$/, 'محفظة موظف'],
  [/^\/admin\/employees\/daily-notes$/, 'ملاحظات يوم موظف'], [/^\/admin\/employees\/void-entry$/, 'إلغاء خصم أو مكافأة أو سلفة'],
  [/^\/admin\/employees\/attendance-rules$/, 'مواعيد الحضور وساعات العمل'], [/^\/admin\/customers\/(save|directory-save)$/, 'بيانات عميل'],
  [/^\/admin\/customers\/consent$/, 'موافقة عميل'], [/^\/admin\/customers\//, 'رسائل العملاء'], [/^\/admin\/delivery-companies\/save$/, 'شركة دليفري'],
  [/^\/admin\/dining\/tables$/, 'طاولة صالة'], [/^\/admin\/dining\/settings$/, 'إعدادات الصالة'],
  [/^\/admin\/branch-expenses\/categories$/, 'بنود المصروفات'], [/^\/admin\/branch-stock\//, 'إعدادات المخزون'],
  [/^\/admin\/order-board\/menu\//, 'إتاحة صنف في التطبيق'], [/^\/admin\/order-board\/[a-z_]+\/[0-9]+\/items$/, 'تعديل أصناف طلب تطبيق'],
  [/^\/admin\/takeaway\/till\//, 'حركة أو إعداد الخزنة'], [/^\/admin\/branch-shifts\/reprint$/, 'طباعة قفلة وردية'],
];

function header(headers, name) {
  const wanted = name.toLowerCase();
  for (const [key, value] of Object.entries(headers || {})) if (key.toLowerCase() === wanted) return String(value);
  return '';
}

/** The request's body exactly as the page sent it. */
function rawBody(request) {
  if (typeof request.postData === 'string') return request.postData;
  if (Array.isArray(request.postDataEntries)) {
    return Buffer.concat(request.postDataEntries.map((entry) => Buffer.from(entry.bytes || '', 'base64'))).toString('utf8');
  }
  return '';
}

function label(pathname) {
  const found = LABELS.find(([pattern]) => pattern.test(pathname));
  return found ? found[1] : 'عملية من الداشبورد';
}

/**
 * What the device does with a page operation no offline page answers: { keep } (the request to keep for the
 * dashboard), { refuse } (why it needs the internet) or null (not one it can keep: the usual "needs the internet").
 * body: the request's fields as read (requestBody).
 */
function deferral(request, url, body) {
  if (request.method !== 'POST' || !url.pathname.startsWith('/admin/') || NEEDS_DASHBOARD.test(url.pathname)) return null;
  if (!body || typeof body !== 'object' || Array.isArray(body) || !UUID.test(String(body.idempotency_key || ''))) return null;
  if (Array.isArray(body.__files) && body.__files.length) return { refuse: FILES };
  const raw = rawBody(request);
  if (!raw || raw.length > 512 * 1024) return null;
  return { keep: { id: String(body.idempotency_key).toLowerCase(), method: 'POST', url: url.pathname + url.search,
    content_type: header(request.headers, 'content-type'), body: raw, label: label(url.pathname) } };
}

/** The answer the page gets: done, on the device, until the dashboard has it. */
function keptAnswer(saved) {
  return { status: 200, json: { success: true, replayed: !!saved.replayed, device_queued: true, message: SAVED } };
}

/**
 * Runs in the dashboard page, signed in as the account that did the operations: each request goes to the dashboard as
 * the page sent it, with the page's current security token, in order. A refusal is the dashboard's decision; no answer,
 * a signed-out session or a server fault stops the run, to try again with the next sync.
 */
function replayScript(requests) {
  return `(async () => {
    const requests = ${JSON.stringify(requests.map((item) => ({ id: item.id, url: item.url, content_type: item.content_type, body: item.body })))};
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta || !meta.content) return [{ retry: true, error: 'session' }];
    const token = meta.content;
    const withToken = (item) => {
      const type = String(item.content_type || '');
      if (/json/i.test(type)) {
        try { const data = JSON.parse(item.body); if (data && typeof data === 'object' && '_token' in data) data._token = token; return JSON.stringify(data); } catch (_) { return item.body; }
      }
      if (/x-www-form-urlencoded/i.test(type)) {
        const data = new URLSearchParams(item.body); if (data.has('_token')) data.set('_token', token); return data.toString();
      }
      if (/multipart\\/form-data/i.test(type)) return item.body.replace(/(name="_token"\\r\\n\\r\\n)[^\\r\\n]*/, '$1' + token);
      return item.body;
    };
    const results = [];
    for (const item of requests) {
      let response;
      try {
        response = await fetch(item.url, { method: 'POST', credentials: 'same-origin', body: withToken(item),
          headers: { ...(item.content_type ? { 'Content-Type': item.content_type } : {}), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token } });
      } catch (_) { results.push({ id: item.id, retry: true, error: 'network' }); break; }
      let payload = null;
      try { payload = await response.json(); } catch (_) {}
      if (response.ok && !(payload && payload.success === false) && !response.redirected) { results.push({ id: item.id, done: true }); continue; }
      if ([401, 408, 419, 429].includes(response.status) || response.status >= 500 || response.redirected) {
        results.push({ id: item.id, retry: true, error: 'status ' + response.status }); break;
      }
      const errors = payload && payload.errors ? Object.values(payload.errors).flat() : [];
      results.push({ id: item.id, rejected: true, status: response.status, message: errors[0] || (payload && payload.message) || 'الداشبورد رفض العملية.' });
    }
    return results;
  })()`;
}

module.exports = { deferral, keptAnswer, replayScript, rawBody, label, SAVED };
