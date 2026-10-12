'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), { EventEmitter } = require('node:events');
const RemoteState = require('../src/dashboard-remote-state.cjs'), RemoteAttempts = require('../src/dashboard-remote-attempts.cjs');
const origin = 'https://dashboard.example';
const transports = [
  ['POST', '/admin/resturant_products'],
  ['POST', '/admin/resturant_products/update'], ['PUT', '/admin/resturant_products/update'],
  ['POST', '/admin/resturant_products/20'], ['DELETE', '/admin/resturant_products/20']
];
const nextTurn = () => new Promise(resolve => setImmediate(resolve));

function fixture() {
  const records = new Map(), calls = [], decisions = new Map();
  const credential = { deviceId: crypto.randomUUID(), actorId: 1, serverOrigin: origin, token: 'a'.repeat(64) };
  const metadata = {
    read: async key => structuredClone(records.get(key) || null),
    write: async (key, value) => records.set(key, structuredClone(value))
  };
  const state = new RemoteState(metadata);
  const request = async value => {
    calls.push(structuredClone(value));
    const status = value.action === 'reserve' ? 'ready' : decisions.get(value.id) || 'cancelled';
    return { format: 1, id: value.id, device_id: credential.deviceId, actor_id: credential.actorId,
      method: value.method, path: value.path, status, capability: 'b'.repeat(64) };
  };
  const options = { state, metadata, credential: async () => credential, request };
  return { records, calls, decisions, credential, metadata, state, options, attempts: new RemoteAttempts(options) };
}

test('product CRUD reserves only original physical POST and direct PUT/DELETE transports', () => {
  const f = fixture();
  for (const [method, pathname] of transports)
    assert.equal(f.attempts.supported({ url: origin + pathname, method }), true);
  for (const [pathname, allowed] of [
    ['/admin/resturant_products', ['POST']],
    ['/admin/resturant_products/update', ['POST', 'PUT']],
    ['/admin/resturant_products/20', ['POST', 'DELETE']]
  ]) {
    for (const method of ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
      assert.equal(f.attempts.supported({ url: origin + pathname, method }), allowed.includes(method));
  }
  for (const pathname of [
    '/admin/resturant_products/', '/admin/resturant_products/update/', '/admin/resturant_products/20/',
    '/admin/resturant_products/0', '/admin/resturant_products/01', '/admin/resturant_products/-1',
    '/admin/resturant_products/99999999999999999999', '/admin/resturant_products/20/edit',
    '/admin/resturant_products/20/update', '/admin/resturant_productsDeleteAll', '/admin/resturant/copy/items/',
    '/admin/resturants/20', '/admin/media/20', '/admin/product_image'
  ]) {
    for (const method of ['POST', 'PUT', 'DELETE'])
      assert.equal(f.attempts.supported({ url: origin + pathname, method }), false);
  }
});

test('native product reservation flushes its binding before release and never consumes multipart uploads', async () => {
  for (const [method, pathname] of transports) {
    const f = fixture(), id = crypto.randomUUID(), originalCredential = structuredClone(f.credential);
    let release, flushed = false;
    const originalWrite = f.metadata.write;
    f.metadata.write = async (key, value) => {
      if (key === 'remote-attempts/' + id) await new Promise(resolve => { release = resolve; });
      await originalWrite(key, value); flushed = true;
    };
    const details = { url: origin + pathname, method, requestHeaders: {
      'Content-Type': 'multipart/form-data; boundary=original-browser-boundary',
      'X-Fasakhansta-Command': crypto.randomUUID()
    } };
    Object.defineProperty(details, 'uploadData', { get() { throw Error('Native reservations must not open browser files.'); } });
    Object.defineProperty(details, 'capturedBody', { get() { throw Error('Native reservations must not copy browser bodies.'); } });
    const sending = f.attempts.begin(id, details);
    await nextTurn(); assert.equal(typeof release, 'function');
    assert.equal(flushed, false); assert.equal(f.calls.length, 0); assert.deepEqual((await f.state.read()).pending, []);
    release(); const proof = await sending;
    assert.equal(proof['X-Fasakhansta-Remote-Attempt'], id);
    assert.deepEqual(f.calls, [{ action: 'reserve', id, method, path: pathname }]);
    assert.deepEqual(Object.keys(f.records.get('remote-attempts/' + id)).sort(),
      ['actorId', 'deviceId', 'format', 'id', 'method', 'path', 'serverOrigin'].sort());
    assert.deepEqual(f.credential, originalCredential);
    assert.deepEqual((await f.state.read()).pending, [id]);
  }
});

async function originalHeaderHook(attempts) {
  let window;
  const session = { webRequest: {
    onBeforeSendHeaders(_filter, callback) { this.headers = callback; },
    onCompleted(_filter, callback) { this.completed = callback; },
    onErrorOccurred(_filter, callback) { this.error = callback; }
  }, setPermissionCheckHandler() {}, setPermissionRequestHandler() {} };
  class Window extends EventEmitter {
    constructor() {
      super(); window = this; this.webContents = new EventEmitter();
      Object.assign(this.webContents, { id: 1, session, setWindowOpenHandler() {}, getURL: () => this.url });
    }
    maximize() {} show() {} focus() {} isMinimized() { return false; } isDestroyed() { return false; }
    async loadURL(url) { this.url = url; }
  }
  const electron = { BrowserWindow: Window, ipcMain: { handle() {} },
    Menu: { buildFromTemplate: value => value, setApplicationMenu() {} }, shell: {}, dialog: { showMessageBox: async () => ({ response: 1 }) } };
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../src/dashboard.cjs'), 'utf8'), {
    module, __dirname: path.join(__dirname, '../src'), URL, AbortSignal,
    require: name => name === 'electron' ? electron : require(name === './dashboard-policy.cjs' ? '../src/dashboard-policy.cjs' : name)
  });
  const dashboard = module.exports({ origin, remoteAttempts: attempts, quitting: () => false,
    offline() {}, offlineWindow: () => window, quit() {}, printer: () => '' });
  await dashboard.open();
  return { session, send: details => new Promise(resolve => session.webRequest.headers(details, resolve)) };
}

test('actual native header interception preserves original multipart bytes and cannot forward proofs to foreign or unowned requests', async () => {
  const f = fixture(), native = await originalHeaderHook(f.attempts);
  const body = [{ bytes: Buffer.from('--original\r\nname="product_image"\r\nPNG original bytes') }, { file: '/private/original-product.png' }];
  const details = { id: 10, url: origin + '/admin/resturant_products/update', method: 'POST', webContentsId: 1,
    uploadData: body, requestHeaders: { 'Content-Type': 'multipart/form-data; boundary=original',
      'X-Fasakhansta-Remote-Attempt': 'forged', 'X-Fasakhansta-Remote-Capability': 'forged' } };
  const before = structuredClone(body), proof = await native.send(details);
  assert.equal(details.uploadData, body); assert.deepEqual(body, before.map(part => part.bytes ? { bytes: Buffer.from(part.bytes) } : part));
  assert.equal(proof.requestHeaders['Content-Type'], details.requestHeaders['Content-Type']);
  assert.match(proof.requestHeaders['X-Fasakhansta-Remote-Attempt'], /^[a-f0-9-]{36}$/i);
  assert.notEqual(proof.requestHeaders['X-Fasakhansta-Remote-Attempt'], 'forged');
  assert.equal(proof.requestHeaders['X-Fasakhansta-Remote-Capability'], 'b'.repeat(64));
  for (const target of [
    { url: 'https://foreign.example/admin/resturant_products/update', webContentsId: 1 },
    { url: 'https://user:pass@dashboard.example/admin/resturant_products/update', webContentsId: 1 },
    { url: origin + '/admin/resturant_products/update', webContentsId: 999 }
  ]) {
    const reply = await native.send({ ...details, ...target, id: 11, requestHeaders: proof.requestHeaders });
    assert.equal(Object.keys(reply.requestHeaders).some(name => /^x-fasakhansta-remote-/i.test(name)), false);
  }
  assert.equal(f.calls.length, 1);
  const persisted = JSON.stringify([...f.records.values()]) + JSON.stringify(f.calls);
  for (const secret of ['PNG original bytes', '/private/original-product.png', 'multipart/form-data', f.credential.token])
    assert.equal(persisted.includes(secret), false);
  native.session.webRequest.error({ ...details, error: 'net::ERR_CONNECTION_RESET' });
  assert.equal((await f.state.read()).pending.length, 1);
});

test('lost product reservation or original response recovers the same physical attempt without resending its upload', async () => {
  for (const [method, pathname] of transports) {
    for (const committed of [false, true]) {
      const f = fixture(), id = crypto.randomUUID(), details = { url: origin + pathname, method };
      if (!committed) {
        f.attempts.request = async value => { await f.options.request(value); throw Error('lost reserve reply'); };
        await assert.rejects(f.attempts.begin(id, details), /lost reserve reply/);
      } else {
        await f.attempts.begin(id, details); f.decisions.set(id, 'committed'); f.attempts.failed(id);
      }
      await new RemoteAttempts(f.options).recover();
      assert.deepEqual(f.calls, [
        { action: 'reserve', id, method, path: pathname }, { action: 'settle', id, method, path: pathname }
      ]);
      assert.deepEqual((await f.state.read()).pending, []); assert.equal((await f.state.read()).dirty, true);
      assert.equal(f.records.get('remote-attempts/' + id).receipt.status, committed ? 'committed' : 'cancelled');
      await assert.rejects(f.state.assertClean());
    }
  }
});

test('product settlement loss, revoked credentials and denied current authority retain uncertainty until a verified decision', async () => {
  const f = fixture(), id = crypto.randomUUID(), details = { url: origin + '/admin/resturant_products/20', method: 'DELETE' };
  await f.attempts.begin(id, details); f.decisions.set(id, 'committed'); f.attempts.failed(id);
  for (const options of [
    { credential: async () => null },
    { credential: async () => ({ ...f.credential, actorId: 2 }) },
    { credential: async () => ({ ...f.credential, deviceId: crypto.randomUUID() }) },
    { credential: async () => ({ ...f.credential, serverOrigin: 'https://foreign.example' }) },
    { request: async () => { throw Error('current authority revoked'); } },
    { request: async value => { await f.options.request(value); throw Error('lost settlement reply'); } }
  ]) {
    await assert.rejects(new RemoteAttempts({ ...f.options, ...options }).recover());
    assert.deepEqual((await f.state.read()).pending, [id]); assert.equal((await f.state.read()).dirty, true);
  }
  await new RemoteAttempts(f.options).recover();
  assert.deepEqual((await f.state.read()).pending, []); assert.equal((await f.state.read()).dirty, true);
  assert.equal(f.calls.filter(value => value.action === 'reserve').length, 1);
  assert.ok(f.calls.every(value => value.id === id && value.method === 'DELETE' && value.path === '/admin/resturant_products/20'));
});

test('product upload reservations reject a foreign origin or unreadable credential before returning any transmission proof', async () => {
  const f = fixture();
  for (const [method, pathname] of transports) {
    await assert.rejects(f.attempts.begin(crypto.randomUUID(), { url: 'https://foreign.example' + pathname, method }));
    await assert.rejects(new RemoteAttempts({ ...f.options, credential: async () => { throw Error('encrypted credential failed'); } })
      .begin(crypto.randomUUID(), { url: origin + pathname, method }), /encrypted credential failed/);
  }
  assert.equal(f.records.size, 0); assert.equal(f.calls.length, 0); assert.deepEqual((await f.state.read()).pending, []);
});
