'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const Runtime = require('../src/local-runtime.cjs');

test('the actual native child runner preserves the fixed workspace import diagnostic while excluding private stderr and fields', async () => {
  const detail = { phase: 'import', type: 'RuntimeException', file: 'DesktopDashboardWorkspaceImport.php', line: 123, status: 422,
    message: 'PRIVATE_PASSWORD_AND_ROOT_BEARER', token: 'PRIVATE_PASSWORD_AND_ROOT_BEARER', privateData: { password: 'PRIVATE_PASSWORD_AND_ROOT_BEARER' } };
  const program = 'process.stderr.write(' + JSON.stringify('DESKTOP_WORKSPACE_IMPORT_DIAGNOSTIC ' + JSON.stringify(detail) + '\nPRIVATE_PASSWORD_AND_ROOT_BEARER\n') + ');process.exit(1)';
  await assert.rejects(Runtime.runNative(process.execPath, ['-e', program], {}, '', 5000), error => {
    assert.deepEqual(error.cause.import, { phase: 'import', type: 'RuntimeException', file: detail.file, line: 123, status: 422 });
    assert.equal(error.cause.timedOut, false); assert.equal(error.cause.overflow, false);
    assert.equal(JSON.stringify(error.cause).includes('PRIVATE_'), false); assert.equal(error.message.includes('PRIVATE_'), false); return true;
  });
});


test('only typed SQLSTATE and numeric driver errno cross the actual child diagnostic boundary', async () => {
  const base = { phase: 'import', type: 'PDOException', file: 'DesktopDashboardWorkspaceLocalSchema.php', line: 33, status: null };
  for (const [sql_state, driver_errno, valid] of [['HY000',1005,true],['42000',1142,true],['PRIVATE_VALUE',1005,false],['HY000','1005',false],['HY000',65536,false]]) {
    const raw = { ...base, sql_state, driver_errno, sql: 'PRIVATE_SQL_AND_PASSWORD', bindings: ['PRIVATE_SQL_AND_PASSWORD'], driver_message: 'PRIVATE_SQL_AND_PASSWORD' };
    const program = 'process.stderr.write(' + JSON.stringify('DESKTOP_WORKSPACE_IMPORT_DIAGNOSTIC ' + JSON.stringify(raw) + '\n') + ');process.exit(1)';
    await assert.rejects(Runtime.runNative(process.execPath, ['-e', program], {}, '', 5000), error => {
      assert.deepEqual(error.cause.import, valid ? { ...base, sql_state, driver_errno } : base);
      assert.equal(JSON.stringify(error.cause).includes('PRIVATE_'), false); assert.equal(error.message.includes('PRIVATE_'), false); return true;
    });
  }
});


test('driver context accepts only exact fixed classifications and never table/database literals or extra SQL', async () => {
  const base = { phase: 'import', type: 'PDOException', file: 'DesktopDashboardWorkspaceLocalSchema.php', line: 40, status: null, sql_state: '42000', driver_errno: 1049 };
  const allowed = { operation: 'create-table', table: 'workspace-command-authorities', target: 'expected' };
  for (const [sql_context, valid] of [[allowed,true],[{ ...allowed, sql: 'PRIVATE_SQL' },false],[{ ...allowed, target: 'PRIVATE_DATABASE' },false],[{ ...allowed, table: 'PRIVATE_TABLE' },false]]) {
    const raw = { ...base, sql_context }, program = 'process.stderr.write(' + JSON.stringify('DESKTOP_WORKSPACE_IMPORT_DIAGNOSTIC ' + JSON.stringify(raw) + '\n') + ');process.exit(1)';
    await assert.rejects(Runtime.runNative(process.execPath, ['-e', program], {}, '', 5000), error => {
      assert.deepEqual(error.cause.import, valid ? { ...base, sql_context: allowed } : base);
      assert.equal(JSON.stringify(error.cause).includes('PRIVATE_'), false); return true;
    });
  }
});


test('the actual PHP fixture emits only one typed preparation report without exception text or private paths', t => {
  const { spawnSync } = require('node:child_process'), path = require('node:path');
  const php = process.env.DESKTOP_TEST_PHP || process.env.WORKSPACE_TEST_PHP || 'php';
  const options = { encoding: 'utf8', timeout: 10000, windowsHide: true };
  const available = spawnSync(php, ['-n','-r','exit(0);'], options);
  if (available.error?.code === 'ENOENT') { t.skip('PHP is unavailable for the real fixed diagnostic producer.'); return; }
  assert.equal(available.status, 0);
  const script = 'require $argv[1];WorkspaceNativeServerFixture::diagnostic(new RuntimeException("PRIVATE_PASSWORD_SQL_AND_TOKEN"),"prepare-bootstrap",$argv[2]);'
    + 'WorkspaceNativeServerFixture::diagnostic(new RuntimeException("SECOND_PRIVATE_VALUE"),"private-phase",$argv[2]);';
  const result = spawnSync(php, ['-n','-r',script,path.join(__dirname,'../../tests/desktop_dashboard_runtime/workspace-native-fixture.php'),path.join(__dirname,'../..')], options);
  assert.equal(result.status, 0); assert.equal(result.stdout, '');
  const lines = result.stderr.trim().split(/\r?\n/); assert.equal(lines.length, 1); assert.ok(lines[0].startsWith('NATIVE_SERVER_PHP_DIAGNOSTIC '));
  const report = JSON.parse(lines[0].slice('NATIVE_SERVER_PHP_DIAGNOSTIC '.length));
  assert.equal(report.type, 'RuntimeException'); assert.equal(report.phase, 'prepare-bootstrap');
  assert.match(report.file, /^[A-Za-z0-9_.-]{1,120}$/); assert.ok(report.line > 0); assert.equal(result.stderr.includes('PRIVATE_'), false);
  assert.equal(Object.hasOwn(report,'message'), false); assert.equal(Object.hasOwn(report,'trace'), false);
});
