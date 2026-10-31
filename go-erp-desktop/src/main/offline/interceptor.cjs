'use strict';
// Keeps the dashboard window working without the internet. It uses Chromium's own request interception (the DevTools
// protocol), so online everything (sign-in, cookies, uploads, redirects) behaves exactly as in a normal browser:
//   online:  every page and file the dashboard loads is saved on the device (pages under the signed-in account);
//            a request the network cannot deliver is answered from the device instead of failing;
//   offline: the same pages open from that copy, the cashier page's requests are answered from the device's database,
//            and anything that needs the server gets the dashboard's usual error message asking for the internet.
const fs = require('node:fs');
const path = require('node:path');
const { missingSpaPage, nothingSavedPage, signedOutPage } = require('./pages.cjs');

// Shipped with the app: the receipt logo (the dashboard's print waits for every receipt picture to load).
const BUNDLED = { '/dashboard/branding/fasakhansta-logo-transparent.png': ['image/png', path.join(__dirname, 'files', 'fasakhansta-logo-transparent.png')] };

const STATIC_TYPES = new Set(['Stylesheet', 'Script', 'Font', 'Image', 'Media', 'Manifest']);
const STATIC_CONTENT = /^(text\/css|(application|text)\/(x-)?javascript|application\/ecmascript|font\/|application\/(font|x-font)|image\/|audio\/)/;
// Never kept: sign-in pages, one invoice's print or details, exports and private files.
const SKIP_PATH = /\/(adminLogout|logout|login|signin|signout|print|details|export|download|attachment|identity-image|media)(\/|$)|\/print-jobs|\/pusher/i;
// The network could not deliver the request (no response at all). Aborted and blocked requests are left alone.
const NETWORK_FAILURES = new Set(['Failed', 'TimedOut', 'ConnectionClosed', 'ConnectionReset', 'ConnectionRefused', 'ConnectionAborted',
  'ConnectionFailed', 'NameNotResolved', 'InternetDisconnected', 'AddressUnreachable']);
// Only a page or data request to the dashboard that clearly could not connect means the internet is down.
const SIGNAL_TYPES = new Set(['Document', 'XHR', 'Fetch']);
// Responses read online. Fonts are not among them: Chromium fails a font whose response is intercepted, so fonts are
// only watched at the request and downloaded a second time for the copy.
const READ_TYPES = ['Document', 'Stylesheet', 'Image', 'Media', 'Script', 'XHR', 'Fetch', 'Other'];
const NEEDS_INTERNET = 'هذه العملية تحتاج اتصالًا بالإنترنت. الكاشير والصفحات المحفوظة تعمل الآن من الجهاز، وكل شيء يتزامن مع الداشبورد عند رجوع النت.';
// Pages saved for offline use as soon as an account opens the dashboard online (those in its own menu).
const WARM_PAGES = ['/admin/dashboard', '/admin/takeaway', '/admin/dining', '/admin/phone-orders', '/admin/branch-orders', '/admin/branch-expenses',
  '/admin/branch-shifts', '/admin/employees', '/admin/customers', '/admin/delivery-companies', '/admin/branch-stock', '/admin/print-settings'];

function header(headers, name) {
  if (!headers) return '';
  if (Array.isArray(headers)) {
    const found = headers.find((item) => item.name.toLowerCase() === name);
    return found ? found.value : '';
  }
  const found = Object.keys(headers).find((item) => item.toLowerCase() === name);
  return found ? headers[found] : '';
}

/** The JSON a page sent (CDP gives it as text, or as base64 parts in newer versions). */
function requestBody(request) {
  let text = request.postData;
  if (text === undefined && Array.isArray(request.postDataEntries)) {
    text = Buffer.concat(request.postDataEntries.map((entry) => Buffer.from(entry.bytes || '', 'base64'))).toString('utf8');
  }
  if (!text) return null;
  try { return JSON.parse(text); } catch { return null; }
}

const wantsJson = (request) => /json/i.test(header(request.headers, 'accept')) || header(request.headers, 'x-requested-with') === 'XMLHttpRequest';

class DashboardOffline {
  /**
   * origin: the dashboard's address. cache: PageCache. takeaway: the cashier page's offline answers.
   * user(): the signed-in account (from the device database) or null. Callbacks: onNetworkFailure(reason),
   * onMissing(url) when a page was never saved, onFromCopy/onLive(contents) for the window's current page.
   */
  constructor({ origin, cache, takeaway, user, onNetworkFailure = () => {}, onMissing = () => {}, onFromCopy = () => {}, onLive = () => {} }) {
    this.origin = origin;
    this.cache = cache;
    this.takeaway = takeaway;
    this.user = user;
    this.onNetworkFailure = onNetworkFailure;
    this.onMissing = onMissing;
    this.onFromCopy = onFromCopy;
    this.onLive = onLive;
    this.offline = false;
    this.attached = new Set();
    this.fonts = new Set();
  }

  scope(user) {
    return 'u:' + user.id;
  }

  /** Whether this account opened the dashboard online on this device before (its pages are saved). */
  hasCopy(user) {
    return !!user && this.cache.count(this.scope(user), 'page') > 0;
  }

  /** Where the window opens offline: the cashier page, the home page, or the last saved page. */
  landing(user) {
    const scope = this.scope(user);
    const order = user.can_checkout ? ['/admin/takeaway', '/admin/dashboard'] : ['/admin/dashboard', '/admin/takeaway'];
    return order.find((path) => this.cache.has(scope, 'page', path)) || this.cache.latest(scope, 'page');
  }

  patterns() {
    if (this.offline) return [{ urlPattern: '*', requestStage: 'Request' }];
    // Online: responses (to save them, or to answer a failed one from the device), fonts, and invoices saved on this device.
    return [...READ_TYPES.map((resourceType) => ({ urlPattern: '*', resourceType, requestStage: 'Response' })),
      { urlPattern: '*', resourceType: 'Font', requestStage: 'Request' },
      { urlPattern: this.origin + '/admin/takeaway/receipts/9*', requestStage: 'Request' }];
  }

  async attach(contents) {
    if (this.attached.has(contents) || contents.isDestroyed()) return;
    const debug = contents.debugger;
    try {
      if (!debug.isAttached()) debug.attach('1.3');
    } catch {
      return; // another tool holds the window; it still works online
    }
    this.attached.add(contents);
    debug.on('message', (event, method, params) => {
      if (method === 'Fetch.requestPaused') this.paused(contents, params).catch(() => {});
    });
    debug.on('detach', () => {
      this.attached.delete(contents);
      if (!contents.isDestroyed()) setTimeout(() => this.attach(contents).catch(() => {}), 500);
    });
    contents.once('destroyed', () => this.attached.delete(contents));
    // The window works online even if Chromium refuses the interception; only the offline copy would be missing.
    await debug.sendCommand('Fetch.enable', { patterns: this.patterns() }).catch((error) => console.error('[goerp] interception:', error.message));
  }

  async setOffline(value) {
    if (this.offline === value) return;
    this.offline = value;
    for (const contents of [...this.attached]) {
      if (contents.isDestroyed()) { this.attached.delete(contents); continue; }
      await this.send(contents, 'Fetch.enable', { patterns: this.patterns() });
    }
  }

  send(contents, method, params) {
    if (contents.isDestroyed() || !contents.debugger.isAttached()) return Promise.resolve(null);
    return contents.debugger.sendCommand(method, params).catch(() => null);
  }

  async paused(contents, params) {
    try {
      if (params.responseErrorReason) return await this.failed(contents, params);
      if (params.responseStatusCode !== undefined) return await this.responded(contents, params);
      const reply = await this.answer(contents, params, this.offline);
      if (reply) return await this.fulfill(contents, params.requestId, reply);
      if (this.offline) return await this.send(contents, 'Fetch.failRequest', { requestId: params.requestId, errorReason: 'InternetDisconnected' });
      if (params.resourceType === 'Font') this.keepFont(contents, params.request);
      return await this.send(contents, 'Fetch.continueRequest', { requestId: params.requestId });
    } catch (error) {
      // Never leave a request waiting: it goes on as if the app were not there.
      console.error('[goerp] interception:', error && error.message);
      const { requestId } = params;
      if (params.responseErrorReason) return this.send(contents, 'Fetch.failRequest', { requestId, errorReason: params.responseErrorReason });
      if (params.responseStatusCode !== undefined) return this.send(contents, 'Fetch.continueResponse', { requestId });
      return this.send(contents, this.offline ? 'Fetch.failRequest' : 'Fetch.continueRequest',
        this.offline ? { requestId, errorReason: 'InternetDisconnected' } : { requestId });
    }
  }

  /** A font the dashboard uses, downloaded once more by the app itself for the device's copy. */
  keepFont(contents, request) {
    const url = request.url;
    if (request.method !== 'GET' || this.fonts.has(url) || !/^https?:/.test(url) || this.cache.hasUrl('shared', 'file', url)) return;
    this.fonts.add(url);
    contents.session.fetch(url).then(async (response) => {
      if (!response.ok) return;
      const body = Buffer.from(await response.arrayBuffer());
      if (body.length && body.length <= 10 * 1024 * 1024) this.cache.put('shared', 'file', url, response.headers.get('content-type') || 'font/woff2', body);
    }).catch(() => {}).finally(() => this.fonts.delete(url));
  }

  /** The network gave no answer: answer from the device when it can (the dashboard dedupes a resent sale). */
  async failed(contents, params) {
    const reason = params.responseErrorReason;
    let reply = null;
    if (NETWORK_FAILURES.has(reason)) {
      const signal = reason !== 'Failed' && SIGNAL_TYPES.has(params.resourceType) && new URL(params.request.url).origin === this.origin;
      if (signal) this.onNetworkFailure(reason, params.request.url);
      reply = await this.answer(contents, params, true);
    }
    if (reply) return this.fulfill(contents, params.requestId, reply);
    return this.send(contents, 'Fetch.failRequest', { requestId: params.requestId, errorReason: reason });
  }

  /** What the device answers for this request, or null to leave it to the network. */
  async answer(contents, { request, resourceType }, offline) {
    let url;
    try { url = new URL(request.url); } catch { return null; }
    const same = url.origin === this.origin;
    const user = this.user();
    if (same && user) {
      const route = this.takeaway.match(request.method, url.pathname);
      if (route && (offline || route.always)) {
        const reply = await this.takeaway.answer(route, { user, url, body: requestBody(request) });
        if (reply) return reply;
      }
    }
    if (!offline) return null;
    if (same && /^\/admin\/(adminLogout|logout)\/?$/i.test(url.pathname)) return { status: 302, location: this.origin + '/admin/login' };
    if (same && /^\/admin\/login\/?$/i.test(url.pathname)) return { status: 200, html: signedOutPage() };
    if (!['GET', 'HEAD'].includes(request.method)) return same ? { status: 503, json: { success: false, message: NEEDS_INTERNET } } : null;
    const document = resourceType === 'Document';
    const spa = header(request.headers, 'x-dashboard-spa') === '1';
    if (same && user) {
      const scope = this.scope(user);
      for (const kind of document ? ['page'] : spa ? ['spa', 'page'] : ['data', 'page']) {
        if (kind === 'page' && !document && !spa && !/text\/html/i.test(header(request.headers, 'accept'))) continue;
        const copy = this.cache.get(scope, kind, url.href, { pathOnly: kind !== 'data' });
        if (copy) {
          if (document) this.onFromCopy(contents);
          return { status: 200, body: copy.body, contentType: copy.contentType };
        }
      }
    }
    const file = this.copyOf(url.href);
    if (file) return { status: 200, body: file.body, contentType: file.contentType };
    if (!same || !user) return null;
    if (document) {
      const landing = this.landing(user);
      if (landing && landing !== url.pathname.replace(/\/+$/, '')) {
        this.onMissing(url.href);
        return { status: 302, location: this.origin + landing };
      }
      return { status: 200, html: nothingSavedPage() };
    }
    if (spa) {
      this.onMissing(url.href);
      const scope = this.scope(user);
      const links = [['/admin/takeaway', 'الكاشير'], ['/admin/dashboard', 'الرئيسية']].filter(([path]) => this.cache.has(scope, 'page', path))
        .map(([href, label]) => ({ href, label }));
      return { status: 200, html: missingSpaPage(user, links) };
    }
    if (wantsJson(request)) return { status: 503, json: { success: false, message: NEEDS_INTERNET } };
    return null;
  }

  /** A public file of the dashboard from the device's copy, or shipped with the app. */
  copyOf(address) {
    const file = this.cache.get('shared', 'file', address);
    if (file) return file;
    let url;
    try { url = new URL(address); } catch { return null; }
    const bundled = url.origin === this.origin && BUNDLED[url.pathname];
    return bundled ? { contentType: bundled[0], body: fs.readFileSync(bundled[1]) } : null;
  }

  /** A response arrived online: keep a copy for offline use, then let it through unchanged. */
  async responded(contents, { requestId, request, resourceType, responseStatusCode, responseHeaders }) {
    try {
      if (resourceType === 'Document' && new URL(request.url).origin === this.origin) this.onLive(contents);
      const plan = responseStatusCode === 200 && request.method === 'GET' ? this.plan(request, resourceType, responseHeaders || []) : null;
      if (plan) {
        const result = await contents.debugger.sendCommand('Fetch.getResponseBody', { requestId });
        const body = Buffer.from(result.body, result.base64Encoded ? 'base64' : 'utf8');
        if (body.length && body.length <= plan.max && plan.accept(body)) this.cache.put(plan.scope, plan.kind, request.url, plan.contentType, body);
      }
    } catch { /* not saved this time */ }
    const done = await this.send(contents, 'Fetch.continueResponse', { requestId });
    if (done === null && !contents.isDestroyed()) await this.send(contents, 'Fetch.continueRequest', { requestId });
  }

  /** Whether and where to save a response. */
  plan(request, resourceType, headers) {
    const url = new URL(request.url);
    const contentType = (header(headers, 'content-type') || '').toLowerCase();
    if (/attachment/i.test(header(headers, 'content-disposition'))) return null;
    const same = url.origin === this.origin;
    if (STATIC_TYPES.has(resourceType) || STATIC_CONTENT.test(contentType)) {
      // The dashboard's private files (under /admin) are never shared between accounts.
      if (same && url.pathname.startsWith('/admin')) return null;
      // Versioned files do not change; others are refreshed once a day.
      if (this.cache.fresh('shared', 'file', request.url, 24 * 3600 * 1000)) return null;
      return { scope: 'shared', kind: 'file', contentType: contentType || 'application/octet-stream', max: 10 * 1024 * 1024, accept: () => true };
    }
    if (!same || !url.pathname.startsWith('/admin') || SKIP_PATH.test(url.pathname)) return null;
    const user = this.user();
    if (!user || this.takeaway.match('GET', url.pathname)) return null;
    const scope = this.scope(user);
    if (contentType.startsWith('text/html')) {
      // Only a page of this very account (the dashboard marks each page with its account).
      const actor = new RegExp(`data-dashboard-actor="${user.id}:`);
      return { scope, kind: header(request.headers, 'x-dashboard-spa') === '1' ? 'spa' : 'page', contentType, max: 6 * 1024 * 1024,
        accept: (body) => actor.test(body.toString('utf8')) };
    }
    if (contentType.includes('json')) return { scope, kind: 'data', contentType, max: 3 * 1024 * 1024, accept: () => true };
    return null;
  }

  fulfill(contents, requestId, reply) {
    const headers = [{ name: 'Cache-Control', value: 'no-store' }, { name: 'X-GO-ERP-Copy', value: 'device' }];
    let body = Buffer.alloc(0);
    if (reply.location) {
      headers.push({ name: 'Location', value: reply.location });
    } else if (reply.json !== undefined) {
      headers.push({ name: 'Content-Type', value: 'application/json' });
      body = Buffer.from(JSON.stringify(reply.json));
    } else if (reply.html !== undefined) {
      headers.push({ name: 'Content-Type', value: 'text/html; charset=UTF-8' });
      body = Buffer.from(reply.html);
    } else {
      headers.push({ name: 'Content-Type', value: reply.contentType || 'application/octet-stream' });
      body = reply.body;
    }
    return this.send(contents, 'Fetch.fulfillRequest', { requestId, responseCode: reply.status || 200, responseHeaders: headers, body: body.toString('base64') });
  }
}

/**
 * Runs inside the signed-in dashboard: saves the pages of its own menu among WARM_PAGES, with the styles and scripts
 * they use, so they open offline even if they were never visited. Plain page reads, one after another.
 */
function warmUpScript() {
  return `(async () => {
    const wanted = ${JSON.stringify(WARM_PAGES)};
    const menu = new Set(Array.from(document.querySelectorAll('#dashboard-sidebar a[href]')).map((link) => {
      try { const url = new URL(link.href, location.href); return url.origin === location.origin ? url.pathname.replace(/\\/+$/, '') : null; } catch (_) { return null; }
    }).filter(Boolean));
    const pages = wanted.filter((page) => page === '/admin/dashboard' || menu.has(page));
    const files = new Set([location.origin + '/dashboard/branding/fasakhansta-logo-transparent.png']);
    for (const page of pages) {
      try {
        const response = await fetch(page, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
        if (!response.ok || response.redirected) continue;
        const copy = new DOMParser().parseFromString(await response.text(), 'text/html');
        copy.querySelectorAll('link[rel="stylesheet"][href], script[src]').forEach((node) => {
          try { files.add(new URL(node.getAttribute('href') || node.getAttribute('src'), location.origin + page).href); } catch (_) {}
        });
      } catch (_) {}
      await new Promise((resolve) => setTimeout(resolve, 700));
    }
    for (const file of files) {
      try {
        const same = new URL(file).origin === location.origin;
        await (await fetch(file, same ? { credentials: 'same-origin' } : { mode: 'no-cors', credentials: 'omit' })).arrayBuffer();
      } catch (_) {}
    }
    return pages.length;
  })()`;
}

module.exports = { DashboardOffline, warmUpScript, WARM_PAGES, NETWORK_FAILURES, NEEDS_INTERNET, requestBody };
