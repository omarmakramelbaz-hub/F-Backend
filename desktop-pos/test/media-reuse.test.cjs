'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const media = require('../src/dashboard-media.cjs');
const bytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=', 'base64');
const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n');
function item(relative = 'products/10/رنجة.png', value = bytes, area = 'public') {
  return { path: relative, mime: relative.endsWith('.pdf') ? 'application/pdf' : 'image/png', bytes: value.length,
    sha256: crypto.createHash('sha256').update(value).digest('hex'), ticket: 'opaque-current-authorized-ticket', ...(area === 'private' ? { area } : {}) };
}
async function fixture(t) {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-reuse-'));
  t.after(() => fs.rm(directory, { recursive: true, force: true }));
  const old = path.join(directory, 'old', 'app'), target = path.join(directory, 'candidate', 'app'), cache = path.join(directory, 'media-cache');
  for (const root of [old, target]) await fs.mkdir(path.join(root, 'public'), { recursive: true });
  return { directory, old, target, cache, reuse: { publicRoot: path.join(old, 'public'), privateRoot: old, cacheRoot: cache } };
}
async function put(root, entry, value = bytes) {
  const full = path.join(root, entry.area === 'private' ? '' : 'public', entry.path);
  await fs.mkdir(path.dirname(full), { recursive: true }); await fs.writeFile(full, value); return full;
}
const cacheFile = (f, entry) => path.join(f.cache, entry.area === 'private' ? 'private' : 'public', entry.sha256 + path.extname(entry.path));
const download = (f, entries, request, reuse = f.reuse, stopped) => media.download(path.join(f.target, 'public'), entries, request, stopped, f.target, reuse);
const response = (entry, value = bytes) => new Response(value, { headers: { 'content-type': entry.mime, 'content-length': String(value.length) } });

test('unchanged public images and private PDFs reuse verified physical bytes with no server request', async t => {
  const f = await fixture(t), image = item(), attachment = item('branch-expenses/فاتورة.pdf', pdf, 'private');
  const oldImage = await put(f.old, image), oldPrivate = await put(f.old, attachment, pdf); let requested = 0;
  const result = await download(f, [image, attachment], async () => { requested++; throw Error('unchanged media must not fetch'); });
  assert.equal(requested, 0); assert.equal(result.verified, true);
  assert.deepEqual(result.transfer, { reusedFiles: 2, downloadedFiles: 0, downloadedBytes: 0 });
  const newImage = path.join(f.target, 'public', image.path), newPrivate = path.join(f.target, attachment.path);
  for (const [old, current, expected] of [[oldImage, newImage, bytes], [oldPrivate, newPrivate, pdf]]) {
    assert.deepEqual(await fs.readFile(current), expected);
    const a = await fs.stat(old, { bigint: true }), b = await fs.stat(current, { bigint: true });
    assert.equal(a.dev, b.dev); assert.equal(a.ino, b.ino); assert.ok(a.nlink >= 2n, 'unchanged generations share physical bytes');
  }
  await assert.rejects(fs.access(path.join(f.target, 'public', attachment.path)));
  assert.equal(result.files[1].area, 'private'); assert.equal(Object.hasOwn(result.files[0], 'ticket'), false);
  media.verifyReceipt([image, attachment].map(({ ticket, ...value }) => value), result.files);
});

test('changed image downloads once, preserves the active bytes and can be reused from cache after a discarded candidate', async t => {
  const f = await fixture(t), original = item(), changed = item(original.path, Buffer.concat([bytes, Buffer.from('changed')]));
  const oldPath = await put(f.old, original), changedBytes = Buffer.concat([bytes, Buffer.from('changed')]); let requested = 0;
  const result = await download(f, [changed], async ticket => { requested++; assert.equal(ticket, changed.ticket); return response(changed, changedBytes); });
  assert.deepEqual(result.transfer, { reusedFiles: 0, downloadedFiles: 1, downloadedBytes: changedBytes.length });
  assert.equal(requested, 1); assert.deepEqual(await fs.readFile(oldPath), bytes);
  assert.deepEqual(await fs.readFile(cacheFile(f, changed)), changedBytes);
  const next = path.join(f.directory, 'retry', 'app'); await fs.mkdir(path.join(next, 'public'), { recursive: true });
  await media.download(path.join(next, 'public'), [changed], async () => { requested++; throw Error('cached delta must not fetch'); }, () => false, next, { cacheRoot: f.cache });
  assert.equal(requested, 1); assert.deepEqual(await fs.readFile(path.join(next, 'public', changed.path)), changedBytes);
  const cached = await fs.stat(cacheFile(f, changed), { bigint: true }), retried = await fs.stat(path.join(next, 'public', changed.path), { bigint: true });
  assert.equal(cached.ino, retried.ino);
});

test('missing or corrupt active bytes never pass on metadata alone and fall back to the authorized download', async t => {
  for (const corrupt of [false, true]) {
    const f = await fixture(t), image = item(); if (corrupt) await put(f.old, image, Buffer.alloc(bytes.length)); let requested = 0;
    const result = await download(f, [image], async () => { requested++; return response(image); });
    assert.equal(requested, 1); assert.equal(result.verified, true); assert.deepEqual(await fs.readFile(path.join(f.target, 'public', image.path)), bytes);
    if (corrupt) assert.deepEqual(await fs.readFile(path.join(f.old, 'public', image.path)), Buffer.alloc(bytes.length));
  }
});

test('a corrupt content-addressed cache is rehashed rather than trusted by its filename', async t => {
  const f = await fixture(t), image = item(); await fs.mkdir(path.dirname(cacheFile(f, image)), { recursive: true });
  await fs.writeFile(cacheFile(f, image), Buffer.alloc(bytes.length)); let requested = 0;
  await download(f, [image], async () => { requested++; return response(image); });
  assert.equal(requested, 1); assert.deepEqual(await fs.readFile(path.join(f.target, 'public', image.path)), bytes);
});

test('cache namespaces do not turn a private expense attachment into a public reusable image', async t => {
  const f = await fixture(t), attachment = item('branch-expenses/same.png', bytes, 'private'); let requested = 0;
  await download(f, [attachment], async () => { requested++; return response(attachment); });
  assert.equal(requested, 1); await fs.access(cacheFile(f, attachment));
  await assert.rejects(fs.access(path.join(f.cache, 'public', attachment.sha256 + '.png')));
  await assert.rejects(fs.access(path.join(f.target, 'public', attachment.path)));
});

test('hardlink-unavailable volumes use verified independent copies without server reads', async t => {
  const f = await fixture(t), image = item(), old = await put(f.old, image); const link = fs.link; let attempted = 0;
  fs.link = async () => { attempted++; const error = Error('unsupported fixture'); error.code = 'EXDEV'; throw error; };
  try { await download(f, [image], async () => { throw Error('must not fetch'); }); }
  finally { fs.link = link; }
  assert.ok(attempted > 0); const target = path.join(f.target, 'public', image.path);
  assert.deepEqual(await fs.readFile(target), bytes); assert.notEqual((await fs.stat(old)).ino, (await fs.stat(target)).ino);
  await fs.writeFile(target, 'independent changed copy'); assert.deepEqual(await fs.readFile(old), bytes);
});

test('a source changed after the first hash cannot corrupt the fallback copy', async t => {
  const f = await fixture(t), image = item(), old = await put(f.old, image); const link = fs.link;
  fs.link = async () => { await fs.writeFile(old, Buffer.alloc(bytes.length)); const error = Error('unsupported fixture'); error.code = 'EXDEV'; throw error; };
  try { await assert.rejects(download(f, [image], async () => { throw Error('must not fetch'); })); }
  finally { fs.link = link; }
  await assert.rejects(fs.access(path.join(f.target, 'public', image.path)));
});

test('source files swapped between descriptor hashing and linking never produce a verified receipt', async t => {
  const f = await fixture(t), image = item(), old = await put(f.old, image); const link = fs.link; let swapped = false;
  fs.link = async (source, target) => {
    if (source === old && !swapped) { swapped = true; await fs.rename(old, old + '.held'); await fs.writeFile(old, bytes); }
    return link(source, target);
  };
  try { await assert.rejects(download(f, [image], async () => { throw Error('must not fetch'); }), /يحتاج التحقق/); }
  finally { fs.link = link; }
  assert.equal(swapped, true); await assert.rejects(fs.access(path.join(f.target, 'public', image.path)));
  assert.deepEqual(await fs.readFile(old + '.held'), bytes);
});

test('a destination created during linking is preserved, never overwritten or accepted', async t => {
  const f = await fixture(t), image = item(); await put(f.old, image); const link = fs.link; let raced = false;
  fs.link = async (source, target) => { if (!raced) { raced = true; await fs.writeFile(target, 'foreign file', { flag: 'wx' }); } return link(source, target); };
  try { await assert.rejects(download(f, [image], async () => { throw Error('must not fetch'); })); }
  finally { fs.link = link; }
  assert.equal(await fs.readFile(path.join(f.target, 'public', image.path), 'utf8'), 'foreign file');
});

test('source symlinks and linked ancestors are refused without reading or modifying outside media', async t => {
  for (const ancestor of [false, true]) {
    const f = await fixture(t), image = item(); const outside = path.join(f.directory, 'outside'); await fs.mkdir(outside);
    const outsideFile = path.join(outside, 'outside.png'); await fs.writeFile(outsideFile, bytes);
    const source = path.join(f.old, 'public', image.path); await fs.mkdir(path.dirname(source), { recursive: true });
    if (ancestor) { await fs.rm(path.dirname(source), { recursive: true }); await fs.symlink(outside, path.dirname(source), process.platform === 'win32' ? 'junction' : 'dir'); }
    else { try { await fs.symlink(outsideFile, source); } catch (error) { if (process.platform === 'win32' && error.code === 'EPERM') { t.diagnostic('Windows file symlink unavailable; directory junction is exercised separately'); continue; } throw error; } }
    await assert.rejects(download(f, [image], async () => { throw Error('unsafe source must not fetch'); }), /يحتاج التحقق/);
    assert.deepEqual(await fs.readFile(outsideFile), bytes);
  }
});

test('private roots must be coupled to their public root and the cache must stay outside all application storage', async t => {
  const f = await fixture(t), attachment = item('branch-expenses/one.pdf', pdf, 'private'); let requested = 0;
  for (const reuse of [{ ...f.reuse, privateRoot: f.target }, { ...f.reuse, cacheRoot: path.join(f.target, 'public', 'cache') },
    { ...f.reuse, cacheRoot: f.old }, { ...f.reuse, publicRoot: path.join(f.target, 'public') }, { publicRoot: 'relative' }])
    await assert.rejects(download(f, [attachment], async () => { requested++; return response(attachment, pdf); }, reuse));
  assert.equal(requested, 0);
});

test('closing during reuse prevents a completed candidate and performs no network request', async t => {
  const f = await fixture(t), image = item(); await put(f.old, image); let checks = 0;
  await assert.rejects(download(f, [image], async () => { throw Error('must not fetch'); }, f.reuse, () => ++checks > 1));
  await assert.rejects(fs.access(path.join(f.target, 'public', image.path)));
});
