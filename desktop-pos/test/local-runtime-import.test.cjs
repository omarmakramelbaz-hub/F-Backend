'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), syncFs = require('node:fs'), os = require('node:os'), path = require('node:path');
const vm = require('node:vm'), { createRequire } = require('node:module');
const { EventEmitter } = require('node:events'), { PassThrough } = require('node:stream');

const runtimeFile = path.join(__dirname, '../src/local-runtime.cjs');
const runtimeSource = syncFs.readFileSync(runtimeFile, 'utf8');
const localRequire = createRequire(runtimeFile);
const scriptName = args => args.find(value => typeof value === 'string' && value.endsWith('.php'))?.split(/[\\/]/).pop();

// Exercise the exported supervisor and its real run()/error path. Only the
// child boundary is spied here; generation-native.cjs also runs bundled PHP.
function loadRuntime(respond, { timeout = false } = {}) {
  const calls = [];
  const spawn = (file, args, options) => {
    calls.push({ file, args: Array.from(args), options });
    const child = new EventEmitter();
    Object.assign(child, { stdin: new PassThrough(), stdout: new PassThrough(), stderr: new PassThrough(), exitCode: null, signalCode: null });
    let finished = false;
    const finish = (code, signal = null) => {
      if (finished) return;
      finished = true; child.exitCode = code; child.signalCode = signal;
      child.emit('exit', code, signal); child.emit('close', code, signal);
    };
    child.kill = (signal = 'SIGTERM') => { queueMicrotask(() => finish(null, signal)); return true; };
    const result = respond({ file, args, options });
    if (!result.hold) queueMicrotask(() => {
      if (result.stderr) child.stderr.write(result.stderr);
      if (result.stdout) child.stdout.write(result.stdout);
      queueMicrotask(() => finish(result.code ?? 0));
    });
    return child;
  };
  const context = vm.createContext({
    module: { exports: {} }, __dirname: path.dirname(runtimeFile),
    require: name => name === 'node:child_process' ? { spawn } : localRequire(name),
    Buffer, process, Error, JSON, URL, AbortSignal, console,
    fetch: async () => ({ ok: true }),
    setTimeout: (fn, milliseconds) => setTimeout(fn, timeout && milliseconds >= 90000 ? 1 : milliseconds),
    clearTimeout
  });
  vm.runInContext(runtimeSource, context, { filename: runtimeFile });
  return { Runtime: context.module.exports, reason: context.failureReason, calls };
}

async function fixture(t, respond, options) {
  const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'desktop-import-command-'));
  t.after(() => fs.rm(temporary, { recursive: true, force: true }));
  const loaded = loadRuntime(respond, options);
  const bundle = path.join(temporary, 'bundle'), application = path.join(bundle, 'application');
  const context = { bundle, application, php: path.join(bundle, 'php/php.exe'), phpIni: path.join(bundle, 'php/profile.ini'),
    manifest: { sourceRevision: 'a'.repeat(40) } };
  const source = { format: 1, files: 1, framework: '8.83.29', sha256: 'b'.repeat(64) };
  const snapshot = { snapshot_id: 'fixture-snapshot', actor_id: 7, schema_hash: 'c'.repeat(64), branches: [3], coverage: {}, source, media: [] };
  const checked = { ...snapshot, verified: true, device_id: 'fixture-device' };
  const runtime = new loaded.Runtime({ bundle, profile: temporary, safeStorage: {} });
  runtime.environment = {}; runtime.candidate = async () => context;
  runtime.settings = async () => ({ deviceId: checked.device_id, rootPassword: 'SYNTHETIC_ROOT_SECRET', appPassword: 'SYNTHETIC_APP_SECRET' });
  Object.assign(runtime, context, { origin: 'http://127.0.0.1:12345', httpPort: 12345 });
  // These represent the active encrypted state. Failed staging must leave it intact.
  await fs.mkdir(runtime.profile, { recursive: true });
  const retained = path.join(runtime.profile, 'prepared.enc');
  await fs.writeFile(retained, 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  return { ...loaded, runtime, snapshot, checked, retained };
}

test('actual staging keeps one-shot opcode caching off, persistent web caching on, and only import memory uncapped', async t => {
  let f;
  f = await fixture(t, ({ args }) => ({ stdout: ['import.php', 'verify.php'].includes(scriptName(args)) ? JSON.stringify(f.checked) : '', hold: args.includes('-S') }));
  const staged = await f.runtime.stage(f.snapshot, '1'.repeat(16), { previous: { database: 'active', snapshotId: 'old', schemaHash: 'old-schema' }, id: 'refresh', token: 'SYNTHETIC_ARCHIVE_TOKEN' });
  assert.equal(staged.receipt.verified, true);
  await assert.rejects(fs.access(path.join(f.runtime.profile, 'generations', '1'.repeat(16), 'import-php-profiles.json')), { code: 'ENOENT' });
  await f.runtime.startWeb();
  assert.deepEqual(f.calls.map(call => scriptName(call.args)), ['database.php', 'import.php', 'archive.php', 'verify.php', 'router.php']);
  for (const call of f.calls) {
    assert.equal(call.args[0], '-c');
    assert.equal(call.options.shell, false);
    assert.equal(call.args.filter(value => value === 'memory_limit=-1').length, scriptName(call.args) === 'import.php' ? 1 : 0);
    const iniOverrides = Object.fromEntries(call.args.flatMap((argument, index) => argument === '-d'
      ? [call.args[index + 1].split(/=(.*)/s).slice(0, 2)] : []));
    assert.equal(iniOverrides['opcache.enable_cli'], scriptName(call.args) === 'router.php' ? '1' : '0');
  }
  const imported = f.calls.find(call => scriptName(call.args) === 'import.php');
  assert.equal(imported.args[imported.args.indexOf('memory_limit=-1') - 1], '-d');
  assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  f.runtime.stopping = true; f.runtime.children[1].kill();
});

test('raw PHP fatal details on either output stream never enter the displayed staging error', async t => {
  const raw = 'PHP Fatal error: Uncaught PDOException: SQLSTATE[HY000] account=private@example.invalid token=CI_PRIVATE_TOKEN in C:\\private\\orders.php:123';
  for (const channel of ['stderr', 'stdout']) {
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 255, [channel]: raw } : {});
    await assert.rejects(f.runtime.stage(f.snapshot, '2'.repeat(16)), error => {
      assert.match(error.message, /\[import\.php\]/);
      assert.match(error.message, /خطأ داخلي/);
      for (const secret of ['SQLSTATE', 'private@example.invalid', 'CI_PRIVATE_TOKEN', 'C:\\private', 'SYNTHETIC_ROOT_SECRET', 'SYNTHETIC_APP_SECRET'])
        assert.equal(error.message.includes(secret), false);
      assert.deepEqual({ ...error.cause }, { exitCode: 255, overflow: false, timedOut: false });
      return true;
    });
    assert.equal(f.runtime.stagedContexts.size, 0);
    assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  }
});

test('stdout memory failures get a fixed useful reason and preserve the sanitized import cause', async t => {
  const importCause = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 71, status: 422 };
  const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
    stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(importCause),
    stdout: 'Fatal error: Allowed memory size of 268435456 bytes exhausted (tried to allocate 123 bytes) PRIVATE_PAYLOAD' } : {});
  await assert.rejects(f.runtime.stage(f.snapshot, '3'.repeat(16)), error => {
    assert.match(error.message, /الذاكرة المتاحة للخطوة غير كافية/);
    assert.equal(error.message.includes('PRIVATE_PAYLOAD'), false);
    assert.deepEqual({ ...error.cause.import }, importCause);
    return true;
  });
});

test('the actual staging error displays the Dashboard check and reviewed fixed abort message', async t => {
  const importCause = { phase: 'import', type: 'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
    file: 'DesktopDashboardImport.php', line: 46, status: 422, message: 'بيانات التجهيز غير مكتملة.' };
  const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
    stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(importCause) } : {});
  await assert.rejects(f.runtime.stage(f.snapshot, '5'.repeat(16)), error => {
    assert.match(error.message, /DesktopDashboardImport\.php:46 \(422\)/);
    assert.ok(error.message.includes(importCause.message));
    assert.deepEqual({ ...error.cause.import }, importCause);
    return true;
  });
  assert.equal(f.runtime.stagedContexts.size, 0);
  assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
});

test('the native boundary drops unreviewed diagnostic messages while retaining safe locations', async t => {
  const base = { phase: 'import', type: 'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
    file: 'DesktopDashboardImport.php', line: 51, status: 422 };
  for (const changed of [
    { message: 'SQLSTATE[HY000] private@example.invalid PRIVATE_TOKEN' },
    { message: 'بيانات التجهيز غير مكتملة.\nPRIVATE_TOKEN' },
    { message: 'بيانات التجهيز غير مكتملة.', status: 403 },
    { message: 'بيانات التجهيز غير مكتملة.', file: 'OtherService.php' },
    { message: { secret: 'PRIVATE_TOKEN' } }
  ]) {
    const report = { ...base, ...changed };
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
      stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) } : {});
    await assert.rejects(f.runtime.stage(f.snapshot, '6'.repeat(16)), error => {
      assert.ok(error.message.includes(report.file + ':' + report.line));
      assert.equal(Object.hasOwn(error.cause.import, 'message'), false);
      for (const forbidden of ['SQLSTATE', 'private@example.invalid', 'PRIVATE_TOKEN', 'بيانات التجهيز غير مكتملة.'])
        assert.equal(error.message.includes(forbidden), false);
      return true;
    });
    assert.equal(f.runtime.stagedContexts.size, 0);
    assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  }
});

test('the native import error displays only validated web and desktop PHP settings', async t => {
  const server = { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi', php_version: '8.2.30', serialize_precision: '17', precision: '14' };
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.4.15', serialize_precision: '-1', precision: '14' };
  const base = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 46, status: 422 };
  for (const isWeb of [true, false]) {
    const source = { ...server, php_sapi: isWeb ? 'fpm-fcgi' : 'cli' };
    const report = { ...base, php_comparison: { server: { ...source, private: 'PRIVATE_ACCOUNT_VALUE' }, desktop,
      server_web_request: !isWeb, same_serialize_precision: true, private: 'PRIVATE_ACCOUNT_VALUE' } };
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
      stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) } : {});
    await assert.rejects(f.runtime.stage(f.snapshot, '7'.repeat(16)), error => {
      assert.ok(error.message.includes((isWeb ? 'الموقع=' : 'مصدر البيانات=') + source.php_sapi + '/8.2.30 serialize_precision=17 precision=14'));
      assert.ok(error.message.includes('الجهاز=cli/8.4.15 serialize_precision=-1 precision=14'));
      assert.equal(error.message.includes('PRIVATE_ACCOUNT_VALUE'), false);
      assert.deepEqual(JSON.parse(JSON.stringify(error.cause.import.php_comparison)), { server: source, desktop,
        server_web_request: isWeb, same_serialize_precision: false });
      return true;
    });
  }
});

test('the native import boundary rejects malformed PHP settings without forwarding private text', async t => {
  const server = { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi', php_version: '8.2.30', serialize_precision: '17', precision: '14' };
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.4.15', serialize_precision: '-1', precision: '14' };
  for (const changed of [{ php_version: '8.2.30 PRIVATE_ACCOUNT_VALUE' }, { serialize_precision: '17\nPRIVATE_ACCOUNT_VALUE' },
    { php_version: '8.2.30\n' }, { php_version: '1234.2.30' }, { serialize_precision: '17\n' },
    { precision: 14 }, { capture_scope: 'pool-private-script' }, { php_sapi: ['fpm-fcgi'] }]) {
    const report = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 46, status: 422,
      php_comparison: { server: { ...server, ...changed }, desktop } };
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
      stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) } : {});
    await assert.rejects(f.runtime.stage(f.snapshot, '8'.repeat(16)), error => {
      assert.equal(Object.hasOwn(error.cause.import, 'php_comparison'), false);
      assert.equal(error.message.includes('PHP:'), false);
      assert.equal(error.message.includes('PRIVATE_ACCOUNT_VALUE'), false);
      return true;
    });
  }
});

test('the native import error keeps a validated desktop profile when server settings are missing or invalid', async t => {
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.2.34', serialize_precision: '-1', precision: '14' };
  for (const [serverStatus, changed] of [['missing', {}], ['invalid', {}], ['invalid', {
    server: { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi', php_version: 'PRIVATE_ACCOUNT_VALUE' },
    server_profile_status: 'valid'
  }]]) {
    const report = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 46, status: 422,
      php_profiles: { server_profile_status: serverStatus, desktop_profile_status: 'invalid',
        desktop: { ...desktop, private: 'PRIVATE_ACCOUNT_VALUE' }, private: 'PRIVATE_ACCOUNT_VALUE', ...changed } };
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
      stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) } : {});
    await assert.rejects(f.runtime.stage(f.snapshot, '9'.repeat(16)), error => {
      assert.ok(error.message.includes('الجهاز=cli/8.2.34 serialize_precision=-1 precision=14'));
      assert.ok(error.message.includes(serverStatus === 'missing' ? 'لم تصل إعدادات PHP مع بيانات التجهيز.' : 'إعدادات PHP غير صالحة للتشخيص.'));
      assert.deepEqual(JSON.parse(JSON.stringify(error.cause.import.php_profiles)), {
        server_profile_status: serverStatus, desktop_profile_status: 'valid', desktop });
      assert.equal(Object.hasOwn(error.cause.import, 'php_comparison'), false);
      assert.equal(error.message.includes('PRIVATE_ACCOUNT_VALUE'), false);
      return true;
    });
  }
});

test('the native import parser accepts precision 100 and recomputes validity and comparison flags', async t => {
  const server = { capture_scope: 'snapshot_export', php_sapi: 'litespeed', php_version: '8.2.30', serialize_precision: '100', precision: '100' };
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.2.34', serialize_precision: '100', precision: '100' };
  const report = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 46, status: 422,
    php_profiles: { server_profile_status: 'missing', desktop_profile_status: 'invalid',
      server: { ...server, private: 'PRIVATE_ACCOUNT_VALUE' }, desktop, private: 'PRIVATE_ACCOUNT_VALUE' },
    php_comparison: { server, desktop, server_web_request: false, same_serialize_precision: false } };
  const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
    stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) } : {});
  await assert.rejects(f.runtime.stage(f.snapshot, 'a'.repeat(16)), error => {
    assert.ok(error.message.includes('الموقع=litespeed/8.2.30 serialize_precision=100 precision=100'));
    assert.ok(error.message.includes('الجهاز=cli/8.2.34 serialize_precision=100 precision=100'));
    assert.deepEqual(JSON.parse(JSON.stringify(error.cause.import.php_profiles)), {
      server_profile_status: 'valid', desktop_profile_status: 'valid', server, desktop });
    assert.deepEqual(JSON.parse(JSON.stringify(error.cause.import.php_comparison)), {
      server, desktop, server_web_request: true, same_serialize_precision: true });
    assert.equal(error.message.includes('PRIVATE_ACCOUNT_VALUE'), false);
    return true;
  });
});

test('invalid new profile fields never borrow a valid legacy comparison or expose rejected values', async t => {
  const server = { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi', php_version: '8.2.30', serialize_precision: '17', precision: '14' };
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.2.34', serialize_precision: '-1', precision: '14' };
  for (const invalid of ['10000', '-2', '01', '17\nPRIVATE_ACCOUNT_VALUE']) {
    const report = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 46, status: 422,
      php_profiles: { server_profile_status: 'valid', server: { ...server, serialize_precision: invalid }, desktop },
      php_comparison: { server, desktop } };
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? { code: 1,
      stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) } : {});
    await assert.rejects(f.runtime.stage(f.snapshot, 'b'.repeat(16)), error => {
      assert.equal(error.cause.import.php_profiles.server_profile_status, 'invalid');
      assert.equal(error.cause.import.php_profiles.desktop_profile_status, 'valid');
      assert.equal(Object.hasOwn(error.cause.import.php_profiles, 'server'), false);
      assert.equal(Object.hasOwn(error.cause.import, 'php_comparison'), false);
      assert.equal(error.message.includes('PRIVATE_ACCOUNT_VALUE'), false);
      assert.equal(error.message.includes('الموقع='), false);
      return true;
    });
  }
});

test('successful imports save only sanitized PHP settings inside their own private generation', async t => {
  const server = { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi', php_version: '8.2.30', serialize_precision: '17', precision: '14' };
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.2.34', serialize_precision: '-1', precision: '14' };
  for (const [status, source] of [['valid', { server: { ...server, private: 'PRIVATE_ACCOUNT_VALUE' } }],
    ['missing', {}], ['invalid', { server: { ...server, serialize_precision: 'PRIVATE_ACCOUNT_VALUE' } }]]) {
    const id = 'c'.repeat(16); let f;
    const profiles = { server_profile_status: status, desktop_profile_status: 'invalid',
      desktop: { ...desktop, private: 'PRIVATE_ACCOUNT_VALUE' }, private: 'PRIVATE_ACCOUNT_VALUE', ...source };
    f = await fixture(t, ({ args }) => ({ stdout: scriptName(args) === 'import.php'
      ? JSON.stringify({ ...f.checked, private: 'PRIVATE_ACCOUNT_VALUE', php_profiles: profiles })
      : scriptName(args) === 'verify.php' ? JSON.stringify(f.checked) : '' }));
    const staged = await f.runtime.stage(f.snapshot, id);
    assert.equal(staged.receipt.verified, true);
    const saved = path.join(f.runtime.profile, 'generations', id, 'import-php-profiles.json');
    const info = await fs.lstat(saved), text = await fs.readFile(saved, 'utf8');
    assert.equal(info.isFile(), true); assert.equal(info.isSymbolicLink(), false);
    if (process.platform !== 'win32') assert.equal(info.mode & 0o777, 0o600);
    assert.ok(Buffer.byteLength(text) < 2048);
    assert.deepEqual(JSON.parse(text), { server_profile_status: status, desktop_profile_status: 'valid',
      ...(status === 'valid' ? { server } : {}), desktop });
    for (const privateValue of ['PRIVATE_ACCOUNT_VALUE', 'fixture-snapshot', 'fixture-device', 'schema_hash', 'actor_id'])
      assert.equal(text.includes(privateValue), false);
    await assert.rejects(fs.access(path.join(f.runtime.profile, 'import-php-profiles.json')), { code: 'ENOENT' });
    assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  }
});

test('failed imports do not fabricate a successful-import PHP settings record', async t => {
  const desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.2.34', serialize_precision: '-1', precision: '14' };
  const report = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardImport.php', line: 46, status: 422,
    php_profiles: { server_profile_status: 'missing', desktop } };
  for (const result of [{ code: 1, stderr: 'DESKTOP_IMPORT_DIAGNOSTIC ' + JSON.stringify(report) }, { stdout: '{' }]) {
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php' ? result : {});
    await assert.rejects(f.runtime.stage(f.snapshot, 'd'.repeat(16)));
    await assert.rejects(fs.access(path.join(f.runtime.profile, 'generations', 'd'.repeat(16), 'import-php-profiles.json')), { code: 'ENOENT' });
    assert.equal(f.runtime.stagedContexts.size, 0);
    assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  }
});

test('the PHP settings record is created exclusively and write failures expose no private path', async t => {
  const id = 'e'.repeat(16), desktop = { capture_scope: 'desktop_import', php_sapi: 'cli', php_version: '8.2.34', serialize_precision: '-1', precision: '14' };
  let f, saved;
  f = await fixture(t, ({ args }) => {
    if (scriptName(args) !== 'import.php') return {};
    saved = path.join(f.runtime.profile, 'generations', id, 'import-php-profiles.json');
    syncFs.writeFileSync(saved, 'EXISTING_SETTINGS_RECORD');
    return { stdout: JSON.stringify({ ...f.checked, php_profiles: { server_profile_status: 'missing', desktop } }) };
  });
  await assert.rejects(f.runtime.stage(f.snapshot, id), error => {
    assert.equal(error.message, 'تعذر حفظ مقارنة إعدادات PHP؛ بيانات الجهاز محفوظة.');
    assert.equal(error.message.includes(f.runtime.profile), false);
    return true;
  });
  assert.equal(await fs.readFile(saved, 'utf8'), 'EXISTING_SETTINGS_RECORD');
  assert.equal(f.runtime.stagedContexts.size, 0);
  assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
});

test('only known portable script basenames can label a failure', () => {
  const { reason } = loadRuntime(() => ({}));
  const cause = { exitCode: 1, overflow: false, timedOut: false };
  for (const script of ['C:\\secret-account\\desktop\\import.php', '/secret-account/desktop/import.php']) {
    assert.match(reason(['-c', 'private.ini', script], cause, ''), /^ \[import\.php\]/);
    assert.equal(reason([script], cause, '').includes('secret-account'), false);
  }
  for (const script of ['C:\\secret-account\\private-user.php', '/secret-account/private-user.php']) {
    const value = reason([script], cause, 'Fatal error: CI_PRIVATE_TOKEN');
    assert.equal(value.includes('private-user'), false);
    assert.equal(value.includes('secret-account'), false);
    assert.equal(value.includes('CI_PRIVATE_TOKEN'), false);
  }
});

test('timeout and overflow failures keep their guards and leave the active state intact', async t => {
  for (const mode of ['timeout', 'overflow']) {
    const f = await fixture(t, ({ args }) => scriptName(args) === 'import.php'
      ? mode === 'timeout' ? { hold: true } : { stdout: 'X'.repeat(20 * 1024 * 1024 + 1) }
      : {}, { timeout: mode === 'timeout' });
    await assert.rejects(f.runtime.stage(f.snapshot, '4'.repeat(16)), error => {
      assert.equal(error.cause.timedOut, mode === 'timeout');
      assert.equal(error.cause.overflow, mode === 'overflow');
      assert.match(error.message, mode === 'timeout' ? /أخذت وقتًا أطول/ : /ناتج الخطوة أكبر/);
      return true;
    });
    assert.equal(f.runtime.stagedContexts.size, 0);
    assert.equal(await fs.readFile(f.retained, 'utf8'), 'SYNTHETIC_ENCRYPTED_ACTIVE_STATE');
  }
});
