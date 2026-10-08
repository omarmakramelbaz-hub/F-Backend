const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const media = require('../src/dashboard-media.cjs');

const bytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=', 'base64');
function item(relative = 'products/10/رنجة.png') {
  return { path: relative, mime: 'image/png', bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), ticket: 'opaque-server-ticket' };
}
async function root(t) {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-'));
  t.after(() => fs.rm(directory, { recursive: true, force: true })); return directory;
}
function response(value = bytes, headers = {}) { return new Response(value, { headers: { 'content-type': 'image/png', ...headers } }); }

test('verified chunked images retain their original nested and Arabic names without storing download tickets', async t => {
  const directory = await root(t), image = item();
  let received;
  const result = await media.download(directory, [image], async ticket => {
    received = ticket; return response(new ReadableStream({ start(controller) { controller.enqueue(bytes.subarray(0, 10)); controller.enqueue(bytes.subarray(10)); controller.close(); } }));
  });
  assert.equal(received, image.ticket); assert.deepEqual(await fs.readFile(path.join(directory, image.path)), bytes);
  assert.equal(result.verified, true); assert.equal('ticket' in result.files[0], false);
});

test('the independent PHP receipt matches actual downloaded images regardless of field order and rejects changed metadata', async t => {
  const directory = await root(t), image = item();
  const downloaded = await media.download(directory, [image], async () => response());
  // DesktopDashboardMedia::receipt emits this order, independently of the native downloader.
  const checked = [{ path: image.path, sha256: image.sha256, bytes: image.bytes, mime: image.mime }];
  assert.doesNotThrow(() => media.verifyReceipt(checked, downloaded.files));
  for (const changed of [{ path: 'another.png' }, { sha256: '0'.repeat(64) }, { bytes: image.bytes + 1 },
    { bytes: String(image.bytes) }, { mime: 'image/jpeg' }, { ticket: image.ticket }])
    assert.throws(() => media.verifyReceipt([{ ...checked[0], ...changed }], downloaded.files));
  assert.throws(() => media.verifyReceipt([], downloaded.files));
  assert.throws(() => media.verifyReceipt(null, downloaded.files));
});

test('native manifests reject traversal, Windows device names, executable types, case collisions and oversize data before fetching', async t => {
  const directory = await root(t); let fetched = 0;
  for (const value of ['../private.png', '/absolute.png', 'x\\photo.png', 'folder/NUL.png', 'C:/x.png', 'x.php', 'x.png.', 'x%2fpng.png'])
    await assert.rejects(media.download(directory, [item(value)], async () => { fetched++; return response(); }));
  assert.throws(() => media.manifest([item('PHOTO.png'), item('photo.png')]));
  assert.throws(() => media.manifest([{ ...item(), bytes: media.MAX_FILE + 1 }]));
  assert.throws(() => media.manifest([{ ...item(), mime: 'text/html' }]));
  assert.equal(fetched, 0);
});

test('hash and length mismatches, truncated streams and interrupted downloads never leave an activatable image', async t => {
  const directory = await root(t), image = item('public/image.png');
  for (const make of [() => response(Buffer.alloc(bytes.length)), () => response(bytes.subarray(0, 10)),
    () => response(Buffer.concat([bytes, bytes])), () => response(bytes, { 'content-length': String(bytes.length + 1) }),
    () => response(new ReadableStream({ start(controller) { controller.enqueue(bytes.subarray(0, 10)); controller.error(Error('connection lost')); } }))]) {
    await assert.rejects(media.download(directory, [image], async () => make()));
    await assert.rejects(fs.access(path.join(directory, image.path)));
    assert.deepEqual(await fs.readdir(path.join(directory, 'public')), []);
  }
});

test('a staged existing image or a symlink cannot overwrite a previous file or escape into another directory', async t => {
  const directory = await root(t); await fs.mkdir(path.join(directory, 'public'));
  await fs.writeFile(path.join(directory, 'public/image.png'), 'old');
  await assert.rejects(media.download(directory, [item('public/image.png')], async () => response()));
  assert.equal(await fs.readFile(path.join(directory, 'public/image.png'), 'utf8'), 'old');
  const outside = await root(t);
  await fs.symlink(outside, path.join(directory, 'linked'), process.platform === 'win32' ? 'junction' : 'dir');
  await assert.rejects(media.download(directory, [item('linked/escape.png')], async () => response()));
  assert.deepEqual(await fs.readdir(outside), []);
});

test('closing during a streamed download cancels it and leaves the new generation incomplete', async t => {
  const directory = await root(t); let stopped = false, cancelled = false;
  const stream = new ReadableStream({ pull(controller) { controller.enqueue(bytes.subarray(0, 10)); stopped = true; }, cancel() { cancelled = true; } });
  await assert.rejects(media.download(directory, [item()], async () => response(stream), () => stopped));
  assert.equal(cancelled, true); assert.deepEqual(await fs.readdir(path.join(directory, 'products/10')), []);
});
