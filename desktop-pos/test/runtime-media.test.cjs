'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const media = require('../src/dashboard-media.cjs');
const RuntimeMedia = require('../src/runtime-media.cjs');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');

async function fixture(t, count = 3) {
  const root = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'runtime-media-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const storage = path.join(root, 'storage'), publicRoot = path.join(storage, 'app/public'), privateRoot = path.join(storage, 'app');
  await fs.mkdir(path.join(publicRoot, 'products'), { recursive: true });
  await fs.mkdir(path.join(privateRoot, 'branch-expenses'), { recursive: true });
  const files = [];
  for (let index = 0; index < count; index++) {
    const bytes = Buffer.from('synthetic image ' + index), relative = 'products/' + index + '.png';
    await fs.writeFile(path.join(publicRoot, relative), bytes);
    files.push({ path: relative, bytes: bytes.length, mime: 'image/png', sha256: sha(bytes) });
  }
  const pdf = Buffer.from('synthetic private pdf');
  await fs.writeFile(path.join(privateRoot, 'branch-expenses/فاتورة.pdf'), pdf);
  files.push({ area: 'private', path: 'branch-expenses/فاتورة.pdf', bytes: pdf.length, mime: 'application/pdf', sha256: sha(pdf) });
  const sourceFingerprint = { format: 1, files: 1358, framework: '8.83.29', sha256: 'a'.repeat(64) };
  const active = { format: 2, actorId: 7, snapshotId: 'synthetic-snapshot', sourceFingerprint };
  const binding = { format: 1, kind: 'native-runtime-media-binding', actorId: 7,
    snapshotId: active.snapshotId, sourceFingerprint, files };
  return { root, storage, publicRoot, privateRoot, files, active, sourceFingerprint, binding };
}

test('active media verifies every public and private byte without writes, downloads or receipt reordering', async t => {
  const f = await fixture(t), before = structuredClone(f.binding);
  const seen = [];
  const result = await RuntimeMedia.verify({ ...f, readBinding: async (code, active) => {
    assert.equal(active, f.active);
    assert.match(code, /SET TRANSACTION READ ONLY/);
    assert.match(code, /SELECT \* FROM/);
    assert.doesNotMatch(code, /require.*bootstrap|DesktopDashboardSource/);
    seen.push(code); return f.binding;
  } });
  assert.deepEqual(result, { verified: true, files: f.files.length });
  assert.equal(seen.length, 1); assert.deepEqual(f.binding, before);
  assert.equal(await fs.readFile(path.join(f.privateRoot, f.files.at(-1).path), 'utf8'), 'synthetic private pdf');
});

test('source, actor, snapshot and adapter schema mismatches stop before file verification', async t => {
  const f = await fixture(t);
  const cases = [ { actorId: 8 }, { snapshotId: 'other' }, { kind: 'other' },
    { sourceFingerprint: { ...f.sourceFingerprint, sha256: 'b'.repeat(64) } }, { unexpected: true } ];
  for (const changed of cases) await assert.rejects(RuntimeMedia.verify({ ...f,
    readBinding: async () => ({ ...f.binding, ...changed }) }), /سجل صور الحساب/);
  let reads = 0;
  await assert.rejects(RuntimeMedia.verify({ ...f, sourceFingerprint: { ...f.sourceFingerprint, sha256: 'b'.repeat(64) },
    readBinding: async () => { reads++; return f.binding; } }), /مصدر البرنامج/);
  assert.equal(reads, 0);
});

test('same-length same-mtime media corruption is freshly detected on each call', async t => {
  const f = await fixture(t), target = path.join(f.publicRoot, f.files[0].path);
  await media.verify(f.publicRoot, f.files, { privateRoot: f.privateRoot });
  const stat = await fs.stat(target), bytes = await fs.readFile(target); bytes[0] ^= 1;
  await fs.writeFile(target, bytes); await fs.utimes(target, stat.atime, stat.mtime);
  await assert.rejects(media.verify(f.publicRoot, f.files, { privateRoot: f.privateRoot }), /بصمة الصورة/);
  assert.deepEqual(await fs.readFile(target), bytes, 'Verification does not repair or delete changed user files.');
});

test('missing images, wrong bytes, unsafe paths and Windows case aliases are rejected', async t => {
  const f = await fixture(t), options = { privateRoot: f.privateRoot };
  for (const changed of [ { path: '../escape.png' }, { path: 'CON.png' }, { bytes: 0 },
    { bytes: f.files[0].bytes + 1 }, { mime: 'text/html' }, { ticket: 'not-a-receipt' } ]) {
    await assert.rejects(media.verify(f.publicRoot, [{ ...f.files[0], ...changed }], options));
  }
  await assert.rejects(media.verify(f.publicRoot, [f.files[0], { ...f.files[0], path: f.files[0].path.toUpperCase() }], options));
  await fs.unlink(path.join(f.publicRoot, f.files[0].path));
  await assert.rejects(media.verify(f.publicRoot, f.files, options));
});

test('active-file links never replace a manifest path and private roots cannot point elsewhere', async t => {
  const f = await fixture(t), target = path.join(f.publicRoot, f.files[0].path);
  await assert.rejects(media.verify(f.publicRoot, f.files, { privateRoot: f.root }));
  if (process.platform === 'win32') return; // Windows link permission is not required by production.
  await fs.unlink(target); await fs.symlink(path.join(f.publicRoot, f.files[1].path), target);
  await assert.rejects(media.verify(f.publicRoot, f.files, { privateRoot: f.privateRoot }), /مسار/);
});

test('verification bounds concurrent descriptors and drains them on cancellation', async t => {
  const f = await fixture(t, 20), original = fs.open;
  let live = 0, peak = 0, stopped = false;
  fs.open = async function (name, ...args) {
    const file = await original.call(this, name, ...args);
    if (!String(name).startsWith(f.root + path.sep)) return file;
    live++; peak = Math.max(peak, live);
    const read = file.read.bind(file), close = file.close.bind(file);
    file.read = async (...readArgs) => { await new Promise(resolve => setTimeout(resolve, 5)); stopped = true; return read(...readArgs); };
    file.close = async () => { try { return await close(); } finally { live--; } };
    return file;
  };
  try {
    await assert.rejects(media.verify(f.publicRoot, f.files, { privateRoot: f.privateRoot, stopped: () => stopped }), /يُغلق/);
    assert.ok(peak > 1 && peak <= 8); assert.equal(live, 0);
  } finally { fs.open = original; }
});

test('a cancelled binding cannot begin SQL or file work', async t => {
  const f = await fixture(t); let calls = 0;
  await assert.rejects(RuntimeMedia.verify({ ...f, stopped: () => true,
    readBinding: async () => { calls++; return f.binding; } }), /يُغلق/);
  assert.equal(calls, 0);
});
