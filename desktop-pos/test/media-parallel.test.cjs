'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const media = require('../src/dashboard-media.cjs');

const body = Buffer.from('parallel-image');
const sha256 = crypto.createHash('sha256').update(body).digest('hex');
const items = count => Array.from({ length: count }, (_, i) => ({ path: 'products/' + i + '.png', bytes: body.length, mime: 'image/png', sha256, ticket: 't' + i }));
const response = () => new Response(body, { headers: { 'content-type': 'image/png' } });
const directory = async () => fs.realpath(await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-parallel-')));

test('images download several at a time, keep manifest order and report every finished file', async () => {
  const files = items(20); let active = 0, peak = 0; const progress = [];
  const result = await media.download(await directory(), files, async () => {
    active++; peak = Math.max(peak, active);
    await new Promise(resolve => setTimeout(resolve, 5)); active--; return response();
  }, () => false, undefined, undefined, { concurrency: 4, onProgress: value => progress.push(value) });
  assert.equal(peak, 4);
  assert.deepEqual(result.files.map(file => file.path), files.map(file => file.path));
  assert.deepEqual(progress[0], { done: 0, total: 20 }); assert.deepEqual(progress.at(-1), { done: 20, total: 20 });
  assert.equal(result.transfer.downloadedFiles, 20);
});
test('one failed image stops new requests and waits for in-flight files before failing', async () => {
  let started = 0, settled = 0;
  await assert.rejects(media.download(await directory(), items(30), async ticket => {
    started++;
    await new Promise(resolve => setTimeout(resolve, 5)); settled++;
    return ticket === 't2' ? new Response('', { status: 500 }) : response();
  }, () => false, undefined, undefined, { concurrency: 4 }), /لم يكتمل تنزيل الصورة/);
  assert.ok(started < 30); assert.equal(started, settled);
});
