'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { EventEmitter } = require('node:events');
const createUpdateInstall = require('../src/update-install.cjs');

async function fixture(t) {
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'go-update-install-'));
  t.after(() => fs.rm(profile, { recursive: true, force: true }));
  await fs.mkdir(path.join(profile, 'updates'));
  const bytes = Buffer.from('verified bytes handled by updater');
  const sha256 = crypto.createHash('sha256').update(bytes).digest('hex');
  const file = path.join(profile, 'updates', sha256 + '.exe');
  await fs.writeFile(file, bytes);
  const release = { installer: { sha256, bytes: bytes.length } }, candidate = { file, release };
  const calls = [];
  let child;
  const spawn = (...args) => {
    calls.push(args); child = new EventEmitter(); child.pid = 678; child.unref = () => { child.unreferenced = true; };
    queueMicrotask(() => child.emit('spawn'));
    return child;
  };
  const options = { profile, execPath: 'C:\\Users\\Ag\\برنامج جو\\GO ERP.exe', pid: 1234, platform: 'win32', spawn };
  return { profile, file, options, calls, release, candidate, child: () => child };
}

test('verified staged upgrade launches without a shell and leaves exit to the caller', async t => {
  const f = await fixture(t);
  const journal = path.join(f.profile, 'orders.enc');
  await fs.writeFile(journal, 'unsynced original journal');
  const result = await createUpdateInstall(f.options)(f.candidate);
  assert.deepEqual(result, { pid: 678 });
  assert.equal(f.child().unreferenced, true);
  assert.deepEqual(f.calls, [[f.file, ['/S', '/GOERPAUTOUPDATE', '/GOERPWAITPID=1234', '/D=C:\\Users\\Ag\\برنامج جو'], {
    detached: true, stdio: 'ignore', windowsHide: true, windowsVerbatimArguments: true, shell: false
  }]]);
  assert.equal(await fs.readFile(journal, 'utf8'), 'unsynced original journal');
  assert.equal(await fs.readFile(f.file, 'utf8'), 'verified bytes handled by updater');
});

test('handoff stays pending until the native installer actually spawns', async t => {
  const f = await fixture(t), child = new EventEmitter();
  let settled = false;
  child.unref = () => { child.unreferenced = true; };
  const task = createUpdateInstall({ ...f.options, spawn: () => child })(f.candidate).then(() => { settled = true; });
  await new Promise(resolve => setTimeout(resolve, 20));
  assert.equal(settled, false);
  child.emit('spawn');
  await task;
  assert.equal(child.unreferenced, true);
});

test('spawn errors do not authorize quitting the running application', async t => {
  const f = await fixture(t);
  await assert.rejects(createUpdateInstall({ ...f.options, spawn() { throw Error('native spawn failure'); } })(f.candidate), /native spawn failure/);
  await assert.rejects(createUpdateInstall({ ...f.options, spawn() {
    const child = new EventEmitter(); queueMicrotask(() => child.emit('error', Error('native spawn denied'))); return child;
  } })(f.candidate), /native spawn denied/);
});

test('foreign platform, invalid PID, unsafe command-line path and other product are refused', async t => {
  const f = await fixture(t);
  for (const patch of [{ platform: 'linux' }, { pid: 0 }, { pid: 2147483648 }, { pid: '123' },
    { execPath: '\\\\server\\share\\GO ERP.exe' }, { execPath: 'C:\\GO ERP.exe' },
    { execPath: 'C:\\app"\\GO ERP.exe' }, { execPath: 'C:\\app\n\\GO ERP.exe' }, { execPath: 'C:\\app\\other.exe' }]) {
    await assert.rejects(createUpdateInstall({ ...f.options, ...patch })(f.candidate));
  }
  assert.equal(f.calls.length, 0);
});

test('only the exact SHA named flat update file can be installed', async t => {
  const f = await fixture(t), install = createUpdateInstall(f.options);
  const foreign = path.join(f.profile, 'b'.repeat(64) + '.exe');
  await fs.writeFile(foreign, 'foreign');
  for (const file of [foreign, path.join(f.profile, 'updates', 'setup.exe'), 'relative.exe',
    path.join(f.profile, 'updates', '..', 'updates', path.basename(f.file)) + '/..']) {
    await assert.rejects(install({ file, release: f.release }));
  }
  assert.equal(f.calls.length, 0);
});

test('staging symlink, parent symlink, hard link, directory and empty download are refused', async t => {
  const f = await fixture(t), install = createUpdateInstall(f.options);
  const source = path.join(f.profile, 'original');
  await fs.rename(f.file, source);
  await fs.symlink(source, f.file);
  await assert.rejects(install(f.candidate));
  await fs.unlink(f.file);
  await fs.link(source, f.file);
  await assert.rejects(install(f.candidate));
  await fs.unlink(f.file);
  await fs.mkdir(f.file);
  await assert.rejects(install(f.candidate));
  await fs.rmdir(f.file);
  await fs.writeFile(f.file, '');
  await assert.rejects(install(f.candidate));
  await fs.unlink(f.file);
  await fs.rename(path.join(f.profile, 'updates'), path.join(f.profile, 'real-updates'));
  await fs.symlink(path.join(f.profile, 'real-updates'), path.join(f.profile, 'updates'));
  await fs.writeFile(f.file, 'staged');
  await assert.rejects(install(f.candidate));
  assert.equal(f.calls.length, 0);
});

test('corrupting or replacing staged bytes while an external guard waits cannot authorize spawn', async t => {
  const f = await fixture(t), install = createUpdateInstall(f.options);
  const original = await fs.readFile(f.file), corrupted = Buffer.from(original);
  corrupted[0] ^= 1;
  assert.equal(corrupted.length, f.release.installer.bytes);
  await fs.writeFile(f.file, corrupted);
  await assert.rejects(install(f.candidate), /تغير/);
  const replacement = path.join(f.profile, 'replacement');
  await fs.writeFile(replacement, corrupted);
  await fs.unlink(f.file); await fs.rename(replacement, f.file);
  await assert.rejects(install(f.candidate), /تغير/);
  await fs.writeFile(f.file, original.subarray(1));
  await assert.rejects(install(f.candidate), /غير مكتمل/);
  assert.equal(f.calls.length, 0);
});

test('a missing or divergent release installer identity cannot authorize even valid staged bytes', async t => {
  const f = await fixture(t), install = createUpdateInstall(f.options);
  for (const release of [undefined, {}, { installer: { ...f.release.installer, sha256: 'b'.repeat(64) } },
    { installer: { ...f.release.installer, bytes: 0 } }, { installer: { ...f.release.installer, bytes: 1024 ** 3 + 1 } }]) {
    await assert.rejects(install({ file: f.file, release }));
  }
  assert.equal(f.calls.length, 0);
});
