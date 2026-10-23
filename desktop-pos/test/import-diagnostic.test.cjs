'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path');
const { spawnSync } = require('node:child_process');

const desktop = path.resolve(__dirname, '../../desktop');
const helper = path.join(desktop, 'import-diagnostic.php');
const options = { encoding: 'utf8', timeout: 10000, windowsHide: true, maxBuffer: 1024 * 1024 };

function php(t) {
  const command = process.env.DESKTOP_TEST_PHP || 'php';
  const available = spawnSync(command, ['-n', '-r', 'echo PHP_VERSION_ID;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && available.error?.code === 'ENOENT') {
    t.skip('PHP is unavailable; set DESKTOP_TEST_PHP to run the import diagnostic regression.');
    return null;
  }
  assert.ifError(available.error);
  assert.equal(available.status, 0, 'The configured PHP executable must start.');
  assert.ok(Number(available.stdout) >= 80000);
  return command;
}

function isolated(command, code, input) {
  const result = spawnSync(command, ['-n', '-r', code, helper], { ...options, input: JSON.stringify(input) });
  assert.ifError(result.error);
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

const selectLocation = String.raw`
  require $argv[1];
  $v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
  echo json_encode(DesktopImportDiagnostic::location($v['file'],$v['line'],$v['trace'],$v['root']),JSON_THROW_ON_ERROR);
`;

test('PHP diagnostics select the first exact Dashboard trace frame and ignore unrelated, sibling and traversing paths', t => {
  const command = php(t); if (!command) return;
  const actual = isolated(command, selectLocation, {
    root: '/private/application', file: '/private/application/vendor/Illuminate/Foundation/Application.php', line: 1440,
    trace: [
      { file: '/other/application/app/Services/Dashboard/Other.php', line: 1 },
      { file: '/private/application/app/Services/DashboardSibling/Other.php', line: 2 },
      { file: '/private/application/app/Services/Dashboard/../private.php', line: 3 },
      { file: '/private/application/app/Services/Dashboard/private@example.php', line: 4 },
      { file: '/private/application/app/Services/Dashboard/Invalid.php', line: '5' },
      { file: '/private/application/app/Services/Dashboard/DesktopDashboardImport.php', line: 46, args: ['PRIVATE_SNAPSHOT_VALUE'] },
      { file: '/private/application/app/Services/Dashboard/Outer.php', line: 99 }
    ]
  });
  assert.deepEqual(actual, { file: 'DesktopDashboardImport.php', line: 46 });
});

test('PHP diagnostics normalize Windows paths, respect their directory boundary, and emit portable basenames', t => {
  const command = php(t); if (!command) return;
  const actual = isolated(command, selectLocation, {
    root: 'C:\\Private Account\\application', file: 'C:\\Private Account\\vendor\\Application.php', line: 1440,
    trace: [
      { file: 'C:/Private Account/application/app/Services/Dashboard-copy/Other.php', line: 7 },
      { file: 'c:/private account/application/app/services/dashboard/DesktopDashboardImport.php', line: 51 }
    ]
  });
  assert.deepEqual(actual, { file: 'DesktopDashboardImport.php', line: 51 });
});

test('PHP diagnostics fall back to a sanitized throw location when no Dashboard frame matches', t => {
  const command = php(t); if (!command) return;
  assert.deepEqual(isolated(command, selectLocation, {
    root: '/application', file: 'C:\\private\\vendor\\Application.php', line: 1440,
    trace: [{ file: '/application/app/Services/OtherService.php', line: 5 }]
  }), { file: 'Application.php', line: 1440 });
  assert.deepEqual(isolated(command, selectLocation, {
    root: '/application', file: '/private/account@example.php', line: -1, trace: []
  }), { file: 'unknown.php', line: 1 });
});

async function importFixture(t, serviceFile = 'DesktopDashboardImport.php') {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'desktop-import-diagnostic-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  await fs.mkdir(path.join(root, 'desktop'), { recursive: true });
  await fs.mkdir(path.join(root, 'vendor/Illuminate/Foundation'), { recursive: true });
  await fs.mkdir(path.join(root, 'app/Services/Dashboard'), { recursive: true });
  await fs.copyFile(path.join(desktop, 'import.php'), path.join(root, 'desktop/import.php'));
  await fs.copyFile(helper, path.join(root, 'desktop/import-diagnostic.php'));
  // Real PHP exceptions and real vendor-to-service frames, without Laravel or a database.
  await fs.writeFile(path.join(root, 'vendor/Illuminate/Foundation/Application.php'), String.raw`<?php
namespace Symfony\Component\HttpKernel\Exception {
    interface HttpExceptionInterface extends \Throwable { public function getStatusCode(): int; }
    class HttpException extends \RuntimeException implements HttpExceptionInterface {
        public function __construct(private int $status,string $message=''){parent::__construct($message);}
        public function getStatusCode(): int{return $this->status;}
    }
}
namespace {
    function abort($status,$message=''){throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function abort_unless($value,$status,$message=''){if(!$value)abort($status,$message);}
}
`);
  const service = String.raw`<?php
namespace App\Services\Dashboard;
final class DesktopDashboardImport {
    public function import(array $snapshot): array {
        if(($snapshot['kind']??'')==='runtime')throw new \RuntimeException($snapshot['message']??'');
        abort_unless(false,(int)($snapshot['status']??422),$snapshot['message']??'');
        return [];
    }
}
`;
  await fs.writeFile(path.join(root, 'app/Services/Dashboard', serviceFile), service);
  await fs.writeFile(path.join(root, 'desktop/bootstrap.php'), `<?php
ini_set('zend.exception_ignore_args','0');
require dirname(__DIR__).'/vendor/Illuminate/Foundation/Application.php';
require dirname(__DIR__).'/app/Services/Dashboard/${serviceFile}';
return new class {public function make($class){return new $class;}};
`);
  return { root, importFile: path.join(root, 'desktop/import.php'), line: service.split('\n').findIndex(line => line.includes('abort_unless(false')) + 1 };
}

function runImport(command, fixture, snapshot, runtimeArgs = []) {
  const result = spawnSync(command, ['-n', ...runtimeArgs, fixture.importFile], { ...options, input: JSON.stringify(snapshot) });
  assert.ifError(result.error);
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  const reports = result.stderr.split(/\r?\n/).filter(line => line.startsWith('DESKTOP_IMPORT_DIAGNOSTIC '));
  assert.equal(reports.length, 1);
  return { report: JSON.parse(reports[0].slice('DESKTOP_IMPORT_DIAGNOSTIC '.length)), stderr: result.stderr };
}

test('actual import.php reports its Dashboard abort caller and only reviewed fixed messages', async t => {
  const command = php(t); if (!command) return;
  const fixture = await importFixture(t);
  for (const [status, message] of [
    [422, 'مخطط قاعدة التجهيز غير مقبول.'],
    [422, 'بيانات التجهيز غير مكتملة.'],
    [422, 'بصمة مخطط التجهيز مختلفة.'],
    [409, 'نسخة برنامج الجهاز لا تطابق بيانات السيرفر؛ النسخة الحالية وسجلاتها محفوظة.'],
    [409, 'قاعدة التجهيز ليست فارغة؛ بيانات الجهاز محفوظة.'],
    [409, 'جدول مرتبط غير موجود في نسخة الحساب.'],
    [409, 'نسخة الحساب تحتوي على مرجع ناقص؛ لم تُفعّل قاعدة التجهيز.']
  ]) {
    const { report, stderr } = runImport(command, fixture, { status, message, private: 'PRIVATE_TRACE_ARGUMENT' });
    assert.deepEqual(report, { phase: 'import', type: 'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
      file: 'DesktopDashboardImport.php', line: fixture.line, status, message });
    assert.equal(stderr.includes('Application.php'), false);
    assert.equal(stderr.includes(fixture.root), false);
    assert.equal(stderr.includes('PRIVATE_TRACE_ARGUMENT'), false);
  }
});

test('actual import.php keeps locations for empty or private abort messages without forwarding their text', async t => {
  const command = php(t); if (!command) return;
  const fixture = await importFixture(t);
  for (const snapshot of [
    { message: '' },
    { message: 'SQLSTATE[HY000] account=private@example.invalid token=PRIVATE_TOKEN' },
    { message: 'بيانات التجهيز غير مكتملة.', status: 403 },
    { message: 'بيانات التجهيز غير مكتملة.', kind: 'runtime' }
  ]) {
    const { report, stderr } = runImport(command, fixture, { ...snapshot, private: 'PRIVATE_TRACE_ARGUMENT' });
    assert.equal(report.file, 'DesktopDashboardImport.php');
    assert.ok(report.line > 0);
    assert.equal(Object.hasOwn(report, 'message'), false);
    for (const privateValue of ['SQLSTATE', 'private@example.invalid', 'PRIVATE_TOKEN', 'PRIVATE_TRACE_ARGUMENT', fixture.root])
      assert.equal(stderr.includes(privateValue), false);
  }
  const other = await importFixture(t, 'OtherService.php');
  const { report } = runImport(command, other, { message: 'بيانات التجهيز غير مكتملة.' });
  assert.equal(report.file, 'OtherService.php');
  assert.equal(Object.hasOwn(report, 'message'), false);
});

test('anonymous exception types never put their embedded path into the diagnostic', t => {
  const command = php(t); if (!command) return;
  const report = isolated(command, String.raw`
    require $argv[1];
    $error=new class('PRIVATE_MESSAGE') extends RuntimeException {};
    echo json_encode(DesktopImportDiagnostic::report($error,'import','/application'),JSON_THROW_ON_ERROR);
  `, {});
  assert.equal(report.type, 'Throwable');
  assert.equal(report.file, 'unknown.php');
  assert.equal(Object.hasOwn(report, 'message'), false);
});

test('import diagnostics compare actual export metadata with the running import PHP without exposing snapshot fields', async t => {
  const command = php(t); if (!command) return;
  const fixture = await importFixture(t);
  const server = { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi', php_version: '8.2.30',
    serialize_precision: '17', precision: '14' };
  const { report, stderr } = runImport(command, fixture, { message: 'بيانات التجهيز غير مكتملة.',
    transport_php: { ...server, private: 'PRIVATE_ACCOUNT_VALUE' }, rows: [{ secret: 'PRIVATE_ACCOUNT_VALUE' }] },
  ['-d', 'serialize_precision=-1', '-d', 'precision=14']);
  assert.deepEqual(report.php_comparison.server, server);
  assert.equal(report.php_comparison.desktop.capture_scope, 'desktop_import');
  assert.equal(report.php_comparison.desktop.php_sapi, 'cli');
  assert.equal(report.php_comparison.desktop.serialize_precision, '-1');
  assert.equal(report.php_comparison.desktop.precision, '14');
  assert.equal(report.php_comparison.server_web_request, true);
  assert.equal(report.php_comparison.same_serialize_precision, false);
  assert.equal(stderr.includes('PRIVATE_ACCOUNT_VALUE'), false);
  assert.equal(stderr.includes(fixture.root), false);
});

test('malformed export metadata is omitted and a CLI export is never presented as website settings', async t => {
  const command = php(t); if (!command) return;
  const fixture = await importFixture(t);
  const server = { capture_scope: 'snapshot_export', php_sapi: 'cli', php_version: '8.2.30',
    serialize_precision: '-1', precision: '14' };
  const valid = runImport(command, fixture, { transport_php: server }, ['-d', 'serialize_precision=-1']);
  assert.equal(valid.report.php_comparison.server_web_request, false);
  assert.equal(valid.report.php_comparison.same_serialize_precision, true);
  for (const changed of [ { capture_scope: 'pool-private-script' }, { php_sapi: 'PRIVATE_ACCOUNT_VALUE' },
    { php_version: '8.2.30 PRIVATE_ACCOUNT_VALUE' }, { serialize_precision: 17 }, { precision: '14\nPRIVATE_ACCOUNT_VALUE' } ]) {
    const { report, stderr } = runImport(command, fixture, { transport_php: { ...server, ...changed } });
    assert.equal(Object.hasOwn(report, 'php_comparison'), false);
    assert.equal(stderr.includes('PRIVATE_ACCOUNT_VALUE'), false);
  }
});
