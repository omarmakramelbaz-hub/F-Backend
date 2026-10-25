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
const rosterDigest = entries => sha('desktop-runtime-roster:1\n' + entries.map(([kind, name]) => kind + '\0' + name + '\n').join(''));

async function fixture(t) {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'runtime-worker-'));
  t.after(() => fs.rm(directory, { recursive: true, force: true }));
  return directory;
}

function executeWorker(directory, { cancel = new SharedArrayBuffer(4), quota = new SharedArrayBuffer(8), omitQuota = false, online, shard = 0, shards = 4 } = {}) {
  const workerData = { format: 1, directory, cancel, quota, shard, shards };
  if (omitQuota) delete workerData.quota;
  const worker = new Worker(workerFile, { workerData });
  const messages = [];
  if (online) worker.once('online', () => online(worker, cancel));
  const completed = new Promise((resolve, reject) => {
    worker.on('message', value => messages.push(value));
    worker.once('error', reject);
    worker.once('exit', code => resolve({ messages, code }));
  });
  return { worker, completed, cancel, quota };
}

function failure(result, code) {
  assert.equal(result.code, 0);
  assert.equal(result.messages.length, 1);
  assert.deepEqual(result.messages[0], { format: 1, kind: 'runtime-inventory-error', code });
}

function parentWithWorkers({ throwAt = -1, beforeConstruct = () => {} } = {}) {
  const created = []; let attempts = 0;
  class FakeWorker extends EventEmitter {
    constructor(file, options) {
      super();
      const index = attempts++;
      beforeConstruct(index, created);
      if (index === throwAt) throw Error('private worker construction failure');
      this.file = file; this.options = options; this.terminated = 0; this.exited = false; created.push(this);
      this.once('exit', () => { this.exited = true; });
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

async function pendingWorkers(t, { stopped, ...options } = {}) {
  const f = parentWithWorkers(options);
  if (stopped) f.archive.stopped = stopped;
  let settled = false;
  const result = f.archive.inventory('/inventory-fixture');
  result.then(() => { settled = true; }, () => { settled = true; });
  // Construction may be preceded by an asynchronous path check.
  for (let turn = 0; f.created.length < (options.throwAt >= 0 ? options.throwAt : 4) && turn < 10; turn++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.created.length, options.throwAt >= 0 ? options.throwAt : 4);
  t.after(() => { for (const worker of f.created) if (!worker.exited) worker.emit('exit', 1); });
  return { ...f, result, settled: () => settled };
}

function success(shard, overrides = {}) {
  // These fixed names belong to shards0,1,2,3 under the protocol's FNV1a assignment.
  const name = ['source-1.php', 'source-2.php', 'source-3.php', 'source-0.php'][shard];
  return { format: 1, kind: 'runtime-inventory', files: [[name, 'a'.repeat(64)]], bytes: 1,
    rosterCount: 4, rosterSha256: 'f'.repeat(64), ...overrides };
}
function emitResult(worker, value) {
  // A real successful shard reserves its bytes before opening files. Protocol
  // fakes must account for that shared reservation as well as their message.
  if (value?.kind === 'runtime-inventory' && Number.isSafeInteger(value.bytes) && value.bytes >= 0) {
    Atomics.add(new BigInt64Array(worker.options.workerData.quota), 0, BigInt(value.bytes));
  }
  worker.emit('message', value);
}
function sendSuccesses(workers, except = -1) {
  workers.forEach((worker, index) => { if (index !== except) emitResult(worker, success(index)); });
}
function exitWorkers(workers, except = -1) {
  workers.forEach((worker, index) => { if (index !== except && !worker.exited) worker.emit('exit', 0); });
}

// Each worker enumerates the complete typed roster but hashes only its fixed
// shard. The union must still contain every byte exactly once.
test('four real workers hash every byte once and agree on the complete directory and file roster', async t => {
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
  const quota = new SharedArrayBuffer(8);
  const results = await Promise.all(Array.from({ length: 4 }, (_, shard) => executeWorker(directory, { shard, quota }).completed));
  const parts = results.map(result => {
    assert.equal(result.code, 0); assert.equal(result.messages.length, 1);
    const value = result.messages[0];
    assert.deepEqual(Object.keys(value).sort(), ['bytes', 'files', 'format', 'kind', 'rosterCount', 'rosterSha256']);
    assert.equal(value.format, 1); assert.equal(value.kind, 'runtime-inventory');
    assert.equal(value.rosterCount, contents.size);
    return value;
  });
  const entries = [['f', '__proto__'], ['d', 'application'], ['d', 'application/nested'], ['f', 'application/nested/binary.dat'], ['f', 'application/source.php'], ['f', 'empty']];
  for (const part of parts) assert.equal(part.rosterSha256, rosterDigest(entries));
  assert.deepEqual(parts.flatMap(value => value.files).sort(order), [...contents].map(([name, bytes]) => [name, sha(bytes)]).sort(order));
  assert.equal(parts.reduce((sum, value) => sum + value.bytes, 0), [...contents.values()].reduce((total, bytes) => total + bytes.length, 0));
  assert.equal(Atomics.load(new BigInt64Array(quota), 0), BigInt([...contents.values()].reduce((total, bytes) => total + bytes.length, 0)));
});

test('the real inventory workers reject relative roots, invalid shard configurations and forbidden entry names', async t => {
  failure(await executeWorker('relative-runtime').completed, 'PATH_INVALID');
  const directory = await fixture(t);
  for (const options of [{ shard: -1 }, { shard: 4 }, { shard: 0.5 }, { shard: '0' }, { shards: 1 }]) {
    failure(await executeWorker(directory, options).completed, 'READ_FAILED');
  }
  const negative = new SharedArrayBuffer(8); Atomics.store(new BigInt64Array(negative), 0, -1n);
  for (const quota of [null, new ArrayBuffer(8), new SharedArrayBuffer(4), new SharedArrayBuffer(16), negative]) {
    failure(await executeWorker(directory, { quota }).completed, 'READ_FAILED');
  }
  failure(await executeWorker(directory, { omitQuota: true }).completed, 'READ_FAILED');
  if (process.platform === 'win32') return;
  await fs.writeFile(path.join(directory, 'invalid\tname.php'), 'untrusted');
  for (const shard of [0, 1, 2, 3]) failure(await executeWorker(directory, { shard }).completed, 'PATH_INVALID');
});

test('the real inventory workers reject case-colliding paths even outside their selected shard', async t => {
  const directory = await fixture(t);
  await fs.writeFile(path.join(directory, 'Order.php'), 'first');
  await fs.writeFile(path.join(directory, 'order.php'), 'second');
  if ((await fs.readdir(directory)).length !== 2) return t.skip('filesystem is case insensitive');
  for (const shard of [0, 1, 2, 3]) failure(await executeWorker(directory, { shard }).completed, 'PATH_INVALID');
});

test('the real inventory workers reject file links, directory links, and a linked root', async t => {
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

test('a real inventory shard rejects the byte limit before reading a sparse oversized selected file', async t => {
  const directory = await fixture(t);
  const handle = await fs.open(path.join(directory, 'oversized.bin'), 'w');
  try { await handle.truncate(4 * 1024 ** 3 + 1); } finally { await handle.close(); }
  const results = await Promise.all(Array.from({ length: 4 }, (_, shard) => executeWorker(directory, { shard }).completed));
  const errors = results.filter(result => result.messages[0]?.kind === 'runtime-inventory-error');
  assert.ok(errors.length >= 1, 'the owner of an oversized file must reject before reading it');
  for (const result of errors) failure(result, 'LIMIT');
});

test('different shards reserve the global byte quota before opening oversized sparse file content', async t => {
  const directory = await fixture(t), selectedBytes = 2 * 1024 ** 3;
  // The fixed filenames belong to different shards. Pre-reservation represents
  // bytes already accepted by another shard without reading gigabytes here.
  for (const name of ['source-1.php', 'source-2.php']) {
    const file = await fs.open(path.join(directory, name), 'w');
    try { await file.truncate(selectedBytes); } finally { await file.close(); }
  }
  const quota = new SharedArrayBuffer(8), counters = new BigInt64Array(quota);
  Atomics.store(counters, 0, 4n * 1024n ** 3n - 1n);
  let opened = 0;
  const guardedFs = { ...syncFs, openSync() { opened++; throw Error('content must not open after exhausted quota'); } };
  for (const shard of [0, 1]) {
    const messages = []; let closed = false;
    vm.runInNewContext(syncFs.readFileSync(workerFile, 'utf8'), {
      require: name => name === 'node:fs' ? guardedFs : name === 'node:worker_threads' ? {
        isMainThread: false, parentPort: { postMessage: value => messages.push(value), close: () => { closed = true; } },
        workerData: { format: 1, directory, shard, shards: 4, quota, cancel: new SharedArrayBuffer(4) },
      } : localRequire(name),
      Buffer, SharedArrayBuffer, Int32Array, BigInt64Array, Atomics,
    }, { filename: workerFile });
    assert.equal(closed, true);
    assert.equal(messages.length, 1); assert.equal(messages[0].kind, 'runtime-inventory-error');
    assert.equal(messages[0].code, 'LIMIT');
  }
  assert.equal(opened, 0, 'quota overflow must stop before any content handle is opened');
  assert.equal(Atomics.load(counters, 0), 8n * 1024n ** 3n - 1n, 'failed reservations are never released');
});

test('the real inventory workers settle shared cancellation before and during a large file hash', async t => {
  const directory = await fixture(t);
  const handle = await fs.open(path.join(directory, 'large.bin'), 'w');
  try { await handle.writeFile(crypto.randomBytes(1024 * 1024)); await handle.truncate(512 * 1024 * 1024); }
  finally { await handle.close(); }
  const cancelled = new SharedArrayBuffer(4); Atomics.store(new Int32Array(cancelled), 0, 1);
  for (const shard of [0, 1, 2, 3]) failure(await executeWorker(directory, { cancel: cancelled, shard }).completed, 'CANCELLED');
  let timer;
  // Run all four shards so the large file's owner always participates.
  const cancel = new SharedArrayBuffer(4);
  const running = Array.from({ length: 4 }, (_, shard) => executeWorker(directory, { cancel, shard,
    online: () => { if (!timer) timer = setTimeout(() => Atomics.store(new Int32Array(cancel), 0, 1), 40); } }));
  try {
    const results = await Promise.all(running.map(value => value.completed));
    assert.ok(results.some(result => result.messages[0]?.code === 'CANCELLED'));
    for (const result of results) {
      assert.equal(result.code, 0); assert.equal(result.messages.length, 1);
      assert.ok(result.messages[0].kind === 'runtime-inventory' || result.messages[0].code === 'CANCELLED');
    }
  } finally { clearTimeout(timer); }
});

test('the parent creates exactly four trusted shards and waits for every worker exit before trusting their merged inventory', async t => {
  const f = await pendingWorkers(t);
  f.created.forEach((worker, shard) => {
    assert.equal(path.resolve(worker.file), workerFile);
    assert.equal(worker.options.workerData.directory, path.resolve('/inventory-fixture'));
    assert.equal(worker.options.workerData.format, 1);
    assert.equal(worker.options.workerData.cancel.byteLength, 4);
    assert.equal(worker.options.workerData.quota.byteLength, 8);
    assert.equal(worker.options.workerData.shard, shard); assert.equal(worker.options.workerData.shards, 4);
    assert.equal(worker.options.workerData.cancel, f.created[0].options.workerData.cancel);
    assert.equal(worker.options.workerData.quota, f.created[0].options.workerData.quota);
    emitResult(worker, success(shard, shard === 1 ? { files: [['__proto__', sha(Buffer.from('safe'))]] } : {}));
  });
  exitWorkers(f.created, 3);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.settled(), false, 'three exited workers cannot establish archive authority');
  f.created[3].emit('exit', 0);
  const value = await f.result;
  assert.equal(Object.keys(value).length, 4);
  assert.equal(Object.getOwnPropertyDescriptor(value, '__proto__').value, sha(Buffer.from('safe')));
});

test('the parent rejects malformed, duplicate, missing and unsuccessful shard results after draining all exits', async t => {
  const valid = success(0);
  const cases = [
    { messages: [{ ...valid, bytes: 4 * 1024 ** 3 + 1 }] },
    { messages: [{ ...valid, files: [['../escape', 'a'.repeat(64)]] }] },
    { messages: [{ ...valid, files: [['same', 'a'.repeat(64)], ['same', 'b'.repeat(64)]] }] },
    { messages: [{ ...valid, files: [['Source.php', 'a'.repeat(64)], ['source.php', 'b'.repeat(64)]] }] },
    { messages: [{ ...valid, files: [['source.php', 'not-a-hash']] }] },
    { messages: [{ ...valid, files: [['source-0.php', 'a'.repeat(64)]] }] },
    { messages: [{ ...valid, unexpected: 'unreviewed field' }] },
    { messages: [{ ...valid, format: true }] },
    { messages: [{ ...valid, format: '1' }] },
    { messages: [{ ...valid, rosterCount: 4.5 }] },
    { messages: [{ ...valid, rosterCount: 100001 }] },
    { messages: [{ ...valid, rosterSha256: 'not-a-digest' }] },
    { messages: [Object.assign(Object.create({ format: 1 }), { kind: valid.kind, files: valid.files, bytes: valid.bytes, rosterCount: valid.rosterCount, rosterSha256: valid.rosterSha256 })] },
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
    const f = await pendingWorkers(t);
    sendSuccesses(f.created, 0);
    if (sample.error) f.created[0].emit('error', sample.error);
    for (const value of sample.messages || []) emitResult(f.created[0], value);
    if (sample.exit !== undefined) f.created[0].emit('exit', sample.exit);
    exitWorkers(f.created, 3);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(f.settled(), false, 'a failed shard must not skip another worker exit');
    f.created[3].emit('exit', 0);
    await assert.rejects(f.result);
  }
});

test('the parent rejects inconsistent rosters, incomplete unions, duplicate paths and a combined byte overflow', async t => {
  const cases = [
    parts => { parts[2].rosterCount = 5; },
    parts => { parts[2].rosterSha256 = 'b'.repeat(64); },
    parts => { parts[2].files = []; },
    parts => { for (const part of parts) part.rosterCount = 5; },
    parts => { parts[2].files = parts[1].files; },
    parts => { parts[2].files = [['SOURCE-1.PHP', 'a'.repeat(64)]]; },
    parts => { for (const part of parts) part.bytes = 2 * 1024 ** 3; },
  ];
  for (const mutate of cases) {
    const f = await pendingWorkers(t), parts = f.created.map((_, index) => success(index));
    mutate(parts);
    parts.forEach((part, index) => emitResult(f.created[index], part));
    exitWorkers(f.created);
    await assert.rejects(f.result);
  }
});

test('the parent rejects a quota that disagrees with successful shard bytes or exceeds the global bound', async t => {
  for (const quota of [0n, 5n, -1n, 4n * 1024n ** 3n + 1n]) {
    const f = await pendingWorkers(t);
    sendSuccesses(f.created);
    Atomics.store(new BigInt64Array(f.created[0].options.workerData.quota), 0, quota);
    exitWorkers(f.created);
    await assert.rejects(f.result);
  }
});

test('a parent cancellation wins over queued successes, flags every shard and drains all workers first', async t => {
  let stopped = false;
  const f = await pendingWorkers(t, { stopped: () => stopped });
  sendSuccesses(f.created);
  stopped = true;
  await new Promise(resolve => setTimeout(resolve, 60));
  for (const worker of f.created) assert.equal(Atomics.load(new Int32Array(worker.options.workerData.cancel), 0), 1);
  exitWorkers(f.created, 3);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.settled(), false);
  f.created[3].emit('exit', 0);
  await assert.rejects(f.result, /البرنامج يُغلق/);
});

test('a later worker construction failure drains prior workers including one that already exited', async t => {
  const f = await pendingWorkers(t, { throwAt: 2, beforeConstruct(index, workers) {
    if (index === 1) { emitResult(workers[0], success(0)); workers[0].emit('exit', 0); }
  } });
  assert.equal(f.created[0].exited, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.settled(), false, 'construction failure must drain the still-running earlier worker');
  assert.ok(f.created[1].terminated > 0);
  assert.equal(Atomics.load(new Int32Array(f.created[1].options.workerData.cancel), 0), 1);
  f.created[1].emit('exit', 1);
  await assert.rejects(f.result);
});
