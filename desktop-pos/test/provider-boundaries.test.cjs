'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const root = path.join(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const inboxSource = read('public/dashboard/js/dashboard-inbox.js');
const supportSource = read('public/dashboard/js/dashboard-support-chat.js');
const tick = () => new Promise(setImmediate);

// Render only the actual local-provider Blade condition, then execute its unchanged
// inline JavaScript. The other Blade strings are inert URLs/labels in these scripts.
function providerMarkup(source, local) {
  return source.replace(/@unless\(config\('desktop_dashboard\.local'\)\)([\s\S]*?)@endunless/g,
    (_, body) => { const parts = body.split(/@else\b/); return local ? (parts[1] || '') : parts[0]; }).replace(/<!--[\s\S]*?-->/g, '');
}
async function layoutProviders(local) {
  const calls = {initialize: 0, serviceWorker: 0, permission: 0, tokens: 0, saved: 0, pusher: 0, channels: 0, bound: [], audio: 0, removedWorkers: []};
  const header = providerMarkup(read('resources/views/admin/layouts/header.blade.php'), local);
  const footer = providerMarkup(read('resources/views/admin/layouts/footer.blade.php'), local);
  const document = {body: {dataset: {dashboardLocal: local ? '1' : '0'}},
    addEventListener() {}, getElementById: () => ({addEventListener() {}})};
  const jquery = () => ({ready: callback => callback(), on() {}});
  const firebase = {initializeApp() { calls.initialize++; }, messaging: () => ({
    useServiceWorker() {}, getToken: async () => { calls.tokens++; return 'provider-token'; }
  })};
  class Pusher {
    constructor() { calls.pusher++; }
    subscribe() { calls.channels++; return {bind: name => calls.bound.push(name)}; }
  }
  const worker = (name, url, state = 'active') => ({[state]: {scriptURL: url}, unregister: async () => { calls.removedWorkers.push(name); }});
  const registrations = [worker('local-active-fcm', 'http://127.0.0.1:9234/firebase-messaging-sw.js'),
    worker('local-waiting-fcm', 'http://127.0.0.1:9234/firebase-messaging-sw.js', 'waiting'),
    worker('unrelated', 'http://127.0.0.1:9234/dashboard-worker.js'),
    worker('foreign-fcm', 'https://dashboard.test/firebase-messaging-sw.js'),
    worker('similar-name', 'http://127.0.0.1:9234/other/firebase-messaging-sw.js'), {}];
  const context = vm.createContext({document, window: {location: {href: 'http://127.0.0.1:9234/admin/dashboard'}}, firebase, Pusher, $: jquery, URL,
    Notification: {permission: 'granted', requestPermission: async () => { calls.permission++; return 'granted'; }},
    navigator: {serviceWorker: {register: async () => { calls.serviceWorker++; return {}; }, getRegistrations: async () => registrations, addEventListener() {}}},
    axios: {post: async () => { calls.saved++; return {}; }},
    Audio: class {constructor() { calls.audio++; } pause() {} play() { return Promise.resolve(); }},
    console: {log() {}, warn() {}, error() {}}});
  for (const source of [header, footer]) {
    for (const [, script] of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) {
      if (script.includes('const firebaseConfig') || script.includes('const messaging = firebase.messaging()') || script.includes('const pusher =') || script.includes('serviceWorker.getRegistrations')) {
        vm.runInContext(script, context);
      }
    }
  }
  await tick();
  return {calls, header, footer};
}
test('local original layouts omit Firebase workers/tokens and Pusher subscriptions while retaining local audio controls', async () => {
  const result = await layoutProviders(true);
  assert.deepEqual(result.calls, {initialize: 0, serviceWorker: 0, permission: 0, tokens: 0, saved: 0, pusher: 0, channels: 0, bound: [], audio: 1,
    removedWorkers: ['local-active-fcm', 'local-waiting-fcm']});
  assert.equal(result.header.includes('firebase-app.js'), false);
  assert.equal(result.footer.includes('pusher.min.js'), false);
});
test('online original layouts still initialize Firebase, register messaging, save tokens and bind both Pusher events', async () => {
  const result = await layoutProviders(false), calls = result.calls;
  assert.equal(calls.initialize, 1); assert.equal(calls.serviceWorker, 1);
  assert.equal(calls.permission, 1); assert.equal(calls.tokens, 2); assert.equal(calls.saved, 2);
  assert.equal(calls.pusher, 1); assert.equal(calls.channels, 1);
  assert.deepEqual(calls.bound, ['order.updated', 'user.updated']);
  assert.deepEqual(calls.removedWorkers, []);
});

function inbox(local) {
  const requests = [], events = {}, intervals = [], snapshots = [];
  const shell = {dataset: {notificationsUrl: '/notes', notificationsReadUrl: '/notes/read', supportUrl: '/support', inboxIds: '1,10'},
    querySelector: () => null, querySelectorAll: () => []};
  const document = {body: {dataset: {dashboardLocal: local ? '1' : '0'}}, hidden: false, documentElement: {lang: 'ar'},
    querySelector: () => shell, querySelectorAll: () => [], dispatchEvent() {}, addEventListener: (name, fn) => { events[name] = fn; }};
  const firebase = {firestore: () => ({collection: () => ({where: () => ({onSnapshot: callback => { snapshots.push(callback); return () => {}; }})})})};
  const window = {firebase, addEventListener() {}};
  vm.runInNewContext(inboxSource, {window, document, firebase, CustomEvent: class {},
    fetch: async url => { requests.push(url); return {ok: true, headers: {get: () => 'application/json'}, json: async () => ({success: true, count: 0, notifications: []})}; },
    setInterval: callback => { intervals.push(callback); return 1; }, clearInterval() {}, setTimeout: () => 1, clearTimeout() {}});
  return {requests, events, intervals, snapshots, window};
}
test('local inbox preserves DB notification reads and never calls provider-backed support even with configured URLs and SDK', async () => {
  const env = inbox(true); await tick();
  env.intervals[0](); env.events['dashboard:page-mounted'](); env.events.visibilitychange();
  await env.window.DashboardInbox.refreshSupport(true); await tick();
  assert.ok(env.requests.length >= 2); assert.ok(env.requests.every(url => url === '/notes'));
  assert.equal(env.snapshots.length, 0);
});
test('online inbox retains provider-backed unread reads and both scoped Firestore subscriptions', async () => {
  const env = inbox(false); await tick();
  assert.deepEqual(env.requests, ['/notes', '/support']);
  assert.equal(env.snapshots.length, 2);
  await env.window.DashboardInbox.refreshSupport(true);
  assert.equal(env.requests.at(-1), '/support?fresh=1');
});

function support(local) {
  const calls = {requests: [], intervals: [], subscriptions: 0}, events = {}, formEvents = {}, classNames = new Set();
  const status = {textContent: '', classList: {toggle() {}}}, button = {disabled: false}, input = {value: '', disabled: false};
  const older = {hidden: true, disabled: false}, messages = {textContent: '', querySelectorAll: () => [], appendChild() {}};
  const scroll = {scrollHeight: 0, clientHeight: 100, scrollTop: 0, getBoundingClientRect: () => ({top: 0, bottom: 100}), addEventListener() {}, removeEventListener() {}};
  const form = {hidden: true, querySelector: () => button, addEventListener: (name, fn) => { formEvents[name] = fn; }, removeEventListener: name => { delete formEvents[name]; }};
  const partner = {dataset: {supportPartner: '20', supportName: 'Partner'}, setAttribute() {}};
  const selectors = {'#support-messages': messages, '[data-support-scroll]': scroll, '[data-support-status]': status,
    '[data-support-send]': form, '#support-message-input': input, '[data-support-older]': older, '[data-support-title]': {textContent: ''}};
  const panel = {dataset: {inboxId: '1', selected: '20', baseUrl: '/support', error: 'load failed', localUnavailable: 'Use the online dashboard', empty: 'empty'},
    querySelector: selector => selectors[selector] || partner, querySelectorAll: () => [partner],
    addEventListener() {}, removeEventListener() {}};
  const document = {body: {dataset: {dashboardLocal: local ? '1' : '0'}, classList: {add: name => classNames.add(name), remove: name => classNames.delete(name)}},
    hidden: false, documentElement: {lang: 'ar'}, getElementById: () => panel, querySelector: () => ({content: 'csrf'}),
    addEventListener: (name, fn) => { events[name] = fn; }, removeEventListener() {}};
  const firestore = {collection: () => ({doc: () => ({collection: () => ({orderBy: () => ({limit: () => ({onSnapshot() { calls.subscriptions++; return () => {}; }})})})})})};
  const firebase = {firestore: () => firestore};
  const window = {firebase, location: {href: 'https://dashboard.test/admin/chat'}};
  vm.runInNewContext(supportSource, {window, document, firebase, URL, AbortController,
    history: {state: null, replaceState() {}},
    fetch: async (url, options) => { calls.requests.push({url, options}); return {ok: true, headers: {get: () => 'application/json'}, json: async () => ({success: true, messages: [], room: 'room_1_20'})}; },
    setInterval: (callback, delay) => { calls.intervals.push({callback, delay}); return 1; }, clearInterval() {}, setTimeout: () => 1, clearTimeout() {}});
  return {calls, events, formEvents, window, status, button, input, older, form, classNames};
}
test('local support visibly disables sends and does no HTTP, Firestore listening or fallback polling across SPA mounts', async () => {
  const env = support(true); await tick();
  assert.equal(env.status.textContent, 'Use the online dashboard');
  assert.equal(env.form.hidden, false); assert.equal(env.button.disabled, true); assert.equal(env.input.disabled, true); assert.equal(env.older.disabled, true);
  let prevented = false; env.formEvents.submit({preventDefault() { prevented = true; }});
  env.window.DashboardSupportChat.mount(); env.events['dashboard:page-mounted']();
  assert.equal(prevented, true); assert.deepEqual(env.calls, {requests: [], intervals: [], subscriptions: 0});
  env.window.DashboardSupportChat.destroy(); assert.equal(env.classNames.has('dashboard-support-open'), false);
});
test('online support still loads the selected conversation, subscribes to Firestore and retains its fallback interval', async () => {
  const env = support(false); await tick();
  assert.equal(env.calls.requests[0].url, '/support/20/messages?inbox_id=1');
  assert.equal(env.calls.subscriptions, 1); assert.equal(env.calls.intervals[0].delay, 15000);
  assert.equal(env.form.hidden, false); assert.equal(env.button.disabled, false); assert.equal(env.input.disabled, false);
});
