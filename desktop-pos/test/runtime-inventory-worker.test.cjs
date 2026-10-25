'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const syncFs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const { EventEmitter } = require('node:events');
const { Worker } = require('node:worker_threads');

const runtimeFile = path.join(__dirname, '../src/runtime-archive.cjs');
const workerFile = path.join(__dirname, '../src/runtime-inventory-worker.cjs');
const localRequire = createRequire(runtimeFile);
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const order = (left, right) => Buffer.compare(Buffer.from(left[0]), Buffer.from(right[0]));

async function fixture(t) {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'runtime-worker-'));
  t.after(() => fs.rm(directory, { recursive: true, force: true }));
  return directory;
}

function executeWorker(directory, { cancel = new SharedArrayBuffer(4), online } = {}) {
  const worker = new Worker(workerFile, { workerData: { format: 1, directory, cancel } });
  const messages = [];
  if (online) worker.once('online', () => online(worker, cancel));
  const completed = new Promise((resolve, reject) => {
    worker.on('message', value => messages.push(value));
    worker.once('error', reject);
    worker.once('exit', code => resolve({ messages, code }));
  });
  return { worker, completed, cancel };
}

function failure(result, code) {
  assert.equal(result.code, 0);
  assert.equal(result.messages.length, 1);
  assert.deepEqual(result.messages[0], { format: 1, kind: 'runtime-inventory-error', code });
}

function parentWithWorker() {
  const created = [];
  class FakeWorker extends EventEmitter {
    constructor(file, options) {
      super(); this.file = file; this.options = options; this.terminated = 0; created.push(this);
      this.closed = new Promise(resolve => this.once('exit', resolve));
    }
    terminate() { this.terminated++; return this.closed; }
  }
  const module = { exports: {} };
  vm.runInNewContext(syncFs.readFileSync(runtimeFile, 'utf8'), {
    module, exports: module.exports, __filename: runtimeFile, __dirname: path.dirname(runtimeFile),
    require: name => name === 'node:worker_threads' ? { Worker: FakeWorker } : localRequire(name),
    Buffer, process, SharedArrayBuffer, Atomics, structuredClone,
    setTimeout, clearTimeout, setInterval, clearInterval,
  }, { filename: runtimeFile });
  const archive = new module.exports('/unused-profile', { read: async () => null, write: async () => {} });
  return { archive, created };
}

async function pendingWorker(t, stopped) {
  const f = parentWithWorker();
  if (stopped) f.archive.stopped = stopped;
  let settled = false;
  const result = f.archive.inventory('/inventory-fixture');
  result.then(() => { settled = true; }, () => { settled = true; });
  // Construction may be preceded by an asynchronous path check.
  for (let turn = 0; !f.created.length && turn < 10; turn++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.created.length, 1);
  t.after(() => {
    if (!settled) f.created[0].emit('exit', 1);
  });
  return { ...f, child: f.created[0], result, settled: () => settled };
}

test('the real inventory worker hashes every byte and keeps prototype-shaped file names', async t => {
  const directory = await fixture(t);
  const contents = new Map([
    ['__proto__', Buffer.from('root file must appear in the complete inventory')],
    ['empty', Buffer.alloc(0)],
    ['application/nested/binary.dat', crypto.randomBytes(3 * 1024 * 1024 + 137)],
    ['application/source.php', Buffer.from('<?php /* \u0645\u0635\u062f\u0631 */\r\nreturn true;\n')],
  ]);
  for (const [name, bytes] of contents) {
    await fs.mkdir(path.dirname(path.join(directory, name)), { recursive: true });
    await fs.writeFile(path.join(directory, name), bytes);
  }
  const result = await executeWorker(directory).completed;
  assert.equal(result.code, 0); assert.equal(result.messages.length, 1);
  const value = result.messages[0];
  assert.deepEqual(Object.keys(value).sort(), ['bytes', 'files', 'format', 'kind']);
  assert.equal(value.format, 1); assert.equal(value.kind, 'runtime-inventory');
  assert.deepEqual(value.files.slice().sort(order), [...contents].map(([name, bytes]) => [name, sha(bytes)]).sort(order));
  assert.equal(value.bytes, [...contents.values()].reduce((total, bytes) => total + bytes.length, 0));
});

test('the real inventory worker rejects a relative root and forbidden entry names', async t => {
  failure(await executeWorker('relative-runtime').completed, 'PATH_INVALID');
  if (process.platform === 'win32') return;
  const directory = await fixture(t);
  await fs.writeFile(path.join(directory, 'invalid\tname.php'), 'untrusted');
  failure(await executeWorker(directory).completed, 'PATH_INVALID');
});

test('the real inventory worker rejects case-colliding paths on a case-sensitive filesystem', async t => {
  const directory = await fixture(t);
  await fs.writeFile(path.join(directory, 'Order.php'), 'first');
  await fs.writeFile(path.join(directory, 'order.php'), 'second');
  if ((await fs.readdir(directory)).length !== 2) return t.skip('filesystem is case insensitive');
  failure(await executeWorker(directory).completed, 'PATH_INVALID');
});

test('the real inventory worker rejects file links, directory links, and a linked root', async t => {
  const directory = await fixture(t);
  const target = path.join(directory, 'outside');
  await fs.mkdir(target); await fs.writeFile(path.join(target, 'source.php'), 'outside');
  const files = path.join(directory, 'files'); await fs.mkdir(files);
  try { await fs.symlink(path.join(target, 'source.php'), path.join(files, 'source.php'), 'file'); }
  catch (error) {
    if (process.platform === 'win32' && error.code === 'EPERM') t.diagnostic('file symlink needs Windows privilege; directory junction checks still execute');
    else throw error;
  }
  if ((await fs.readdir(files)).length) failure(await executeWorker(files).completed, 'PATH_INVALID');
  const directories = path.join(directory, 'directories'); await fs.mkdir(directories);
  await fs.symlink(target, path.join(directories, 'application'), process.platform === 'win32' ? 'junction' : 'dir');
  failure(await executeWorker(directories).completed, 'PATH_INVALID');
  const rootLink = path.join(directory, 'linked-root');
  await fs.symlink(target, rootLink, process.platform === 'win32' ? 'junction' : 'dir');
  const linked = await executeWorker(rootLink).completed;
  assert.equal(linked.code, 0); assert.equal(linked.messages.length, 1);
  assert.equal(linked.messages[0].kind, 'runtime-inventory-error');
  assert.ok(['PATH_INVALID', 'FILE_INVALID'].includes(linked.messages[0].code));
});

test('the real inventory worker rejects the byte limit before reading a sparse oversized file', async t => {
  const directory = await fixture(t);
  const handle = await fs.open(path.join(directory, 'oversized.bin'), 'w');
  try { await handle.truncate(4 * 1024 ** 3 + 1); } finally { await handle.close(); }
  failure(await executeWorker(directory).completed, 'LIMIT');
});

test('the real inventory worker settles cancellation before and during a large file hash', async t => {
  const directory = await fixture(t);
  const handle = await fs.open(path.join(directory, 'large.bin'), 'w');
  try {
    await handle.writeFile(crypto.randomBytes(1024 * 1024));
    await handle.truncate(512 * 1024 * 1024);
  } finally { await handle.close(); }
  const cancelled = new SharedArrayBuffer(4); Atomics.store(new Int32Array(cancelled), 0, 1);
  failure(await executeWorker(directory, { cancel: cancelled }).completed, 'CANCELLED');
  let timer;
  const running = executeWorker(directory, { online: (_worker, cancel) => {
    timer = setTimeout(() => Atomics.store(new Int32Array(cancel), 0, 1), 40);
  } });
  try { failure(await running.completed, 'CANCELLED'); } finally { clearTimeout(timer); }
});

test('the parent waits for worker exit before trusting an otherwise valid inventory', async t => {
  const f = await pendingWorker(t);
  assert.equal(path.resolve(f.child.file), workerFile);
  assert.equal(f.child.options.workerData.directory, path.resolve('/inventory-fixture'));
  assert.equal(f.child.options.workerData.format, 1);
  assert.equal(f.child.options.workerData.cancel.byteLength, 4);
  const digest = sha(Buffer.from('safe'));
  f.child.emit('message', { format: 1, kind: 'runtime-inventory', files: [['__proto__', digest]], bytes: 4 });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.settled(), false, 'a message alone must not establish archive authority');
  f.child.emit('exit', 0);
  const value = await f.result;
  assert.equal(Object.keys(value).length, 1);
  assert.equal(Object.getOwnPropertyDescriptor(value, '__proto__').value, digest);
});

test('the parent rejects malformed, duplicate, and unsuccessful worker results after draining exit', async t => {
  const valid = { format: 1, kind: 'runtime-inventory', files: [['source.php', 'a'.repeat(64)]], bytes: 1 };
  const cases = [
    { messages: [{ ...valid, bytes: 4 * 1024 ** 3 + 1 }] },
    { messages: [{ ...valid, files: [['../escape', 'a'.repeat(64)]] }] },
    { messages: [{ ...valid, files: [['same', 'a'.repeat(64)], ['same', 'b'.repeat(64)]] }] },
    { messages: [{ ...valid, files: [['Source.php', 'a'.repeat(64)], ['source.php', 'b'.repeat(64)]] }] },
    { messages: [{ ...valid, files: [['source.php', 'not-a-hash']] }] },
    { messages: [{ ...valid, unexpected: 'unreviewed field' }] },
    { messages: [{ ...valid, format: true }] },
    { messages: [{ ...valid, format: '1' }] },
    { messages: [Object.assign(Object.create({ format: 1 }), { kind: valid.kind, files: valid.files, bytes: valid.bytes })] },
    { messages: [{ format: 1, kind: 'runtime-inventory-error', code: 'UNREVIEWED_FAILURE' }] },
    { messages: [{ format: 1, kind: 'runtime-inventory-error', code: 'constructor' }] },
    { messages: [{ format: 1, kind: 'runtime-inventory-error', code: '__proto__' }] },
    { messages: [Object.assign(Object.create({ code: 'CHANGED' }), { format: 1, kind: 'runtime-inventory-error' })] },
    { messages: [valid, valid] },
    { messages: [valid], exit: 1 },
    { messages: [{ format: 1, kind: 'runtime-inventory-error', code: 'CHANGED' }] },
    { error: Error('worker failed privately') },
    { messages: [] },
  ];
  for (const sample of cases) {
    const f = await pendingWorker(t);
    if (sample.error) f.child.emit('error', sample.error);
    for (const value of sample.messages || []) f.child.emit('message', value);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(f.settled(), false, 'failed work must drain before startup can finish');
    f.child.emit('exit', sample.exit ?? 0);
    await assert.rejects(f.result);
  }
});

test('a parent cancellation wins over a queued success and drains the worker first', async t => {
  let stopped = false;
  const f = await pendingWorker(t, () => stopped);
  f.child.emit('message', { format: 1, kind: 'runtime-inventory', files: [['source.php', 'a'.repeat(64)]], bytes: 1 });
  stopped = true;
  await new Promise(resolve => setTimeout(resolve, 60));
  assert.equal(Atomics.load(new Int32Array(f.child.options.workerData.cancel), 0), 1);
  assert.equal(f.settled(), false);
  f.child.emit('exit', 0);
  await assert.rejects(f.result, /\u0627\u0644\u0628\u0631\u0646\u0627\u0645\u062c \u064a\u064f\u063a\u0644\u0642/);
});
