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
