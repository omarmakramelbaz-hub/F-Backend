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
