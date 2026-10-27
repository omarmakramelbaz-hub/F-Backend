'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const php = process.env.DESKTOP_TEST_PHP || 'php';
const bootstrap = path.resolve(__dirname, '../../app/Services/Dashboard/DesktopDashboardBootstrap.php');
const options = { encoding: 'utf8', timeout: 15000, windowsHide: true, maxBuffer: 65536 };
function available(t) {
  const result = spawnSync(php, ['-n', '-r', 'echo PHP_VERSION_ID;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && result.error?.code === 'ENOENT') {
    t.skip('PHP is unavailable; set DESKTOP_TEST_PHP for the actual export metadata proof.');
    return false;
  }
  assert.ifError(result.error); assert.equal(result.status, 0);
  assert.ok(Number(result.stdout) >= 80000);
  return true;
}

// Synthetic adapters isolate export() itself. There is no Laravel boot, account,
// database, web-PHP simulation or claim about the deployed server's SAPI.
const fixture = String.raw`
namespace Illuminate\Support\Facades {
    class DB {
        public static function transactionLevel(){return 0;}
        public static function statement($sql){}
        public static function transaction($run){return $run();}
        public static function connection(){return new class {public function getDatabaseName(){return 'synthetic';}};}
        public static function selectOne($sql,$bindings=[]){
            return str_starts_with($sql,'SHOW CREATE')?(object)['Table'=>'users','Create Table'=>\syntheticDdl()]:(object)['engine'=>'InnoDB'];
        }
        public static function select($sql,$bindings=[]){return [(object)['name'=>'id','value'=>null,'nullable'=>'NO','type'=>'bigint'],(object)['name'=>'amount','value'=>null,'nullable'=>'YES','type'=>'double']];}
    }
}
namespace Illuminate\Support {class Str {public static function uuid(){return '00000000-0000-4000-8000-000000000001';}}}
namespace App\Models {class User {public int $id=1;}}
namespace App\Services\Dashboard {
    class DesktopDashboardDevices {
        public function ready(){}
        public function lockAuthenticated($device){return $device;}
        public function actor($device){return new \App\Models\User;}
        public function branch($device,$branch,$actor){}
    }
    class DesktopDashboardData {
        public function queries($device,$actor,$branches){return ['users'=>(object)['wheres'=>[]]];}
        public function rows($queries){return ['users'=>\syntheticRows()];}
        public function pendingEligibility($queries,$dataset){return null;}
    }
    class DesktopDashboardSource {
        public function fingerprint(){return ['format'=>1,'files'=>1,'framework'=>'synthetic','sha256'=>str_repeat('a',64)];}
        public function matches($source){return true;}
    }
    class DesktopDashboardCreatedScopes {public function available(){return false;}}
    class DesktopDashboardMedia {public function manifest($dataset,$device,$actor,$snapshot){return ['complete'=>true,'files'=>[],'issues'=>[]];}}
    class DesktopDashboardRoleFacts {public function manifest($roles){return [];}}
    class DesktopDashboardRoutes {public const WRITES=[];}
    class DesktopDashboardLegacy {public const ROUTES=[];}
}
namespace {
    function app($class){return new $class;}
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new \RuntimeException('Synthetic precondition failed.');}
    function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
    function now($timezone){return new class {public function toIso8601String(){return '2026-10-23T00:00:00+00:00';}};}
    function syntheticDdl(){$q=chr(96);return 'CREATE TABLE '.$q.'users'.$q.' ('.$q.'id'.$q.' bigint NOT NULL,'.$q.'amount'.$q.' double DEFAULT NULL) ENGINE=InnoDB';}
    function syntheticRows(){return [['id'=>1,'amount'=>0.1]];}
    require $argv[1];
    $before=['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')];
    $expectedRows=hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json(syntheticRows()));
    $expectedSchema=hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json(['users'=>hash('sha256',syntheticDdl())]));
    $service=new \App\Services\Dashboard\DesktopDashboardBootstrap(new \App\Services\Dashboard\DesktopDashboardDevices,new \App\Services\Dashboard\DesktopDashboardData);
    $snapshot=$service->export((object)['id'=>'00000000-0000-4000-8000-000000000002','branches'=>'["f:1"]','enrollment_hash'=>str_repeat('b',64),'enrolled_at'=>'2026-10-23 00:00:00']);
    echo json_encode(['snapshot'=>$snapshot,'before'=>$before,
        'after'=>['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')],
        'actual'=>['capture_scope'=>'snapshot_export','php_sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION,'serialize_precision'=>(string)ini_get('serialize_precision'),'precision'=>(string)ini_get('precision')],
        'row_hash_unchanged'=>hash_equals($expectedRows,$snapshot['tables']['users']['sha256']),
        'schema_hash_unchanged'=>hash_equals($expectedSchema,$snapshot['schema_hash'])],JSON_THROW_ON_ERROR);
}
`;

function exported(serialize = -1, precision = 14) {
  const result = spawnSync(php, ['-n', '-d', 'memory_limit=512M', '-d', `serialize_precision=${serialize}`,
    '-d', `precision=${precision}`, '-r', fixture, bootstrap], {
    ...options, env: { ...process.env, GO_ERP_SYNTHETIC_PRIVATE_VALUE: 'private-value-must-not-be-captured' }
  });
  assert.ifError(result.error); assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stderr, ''); return JSON.parse(result.stdout);
}

test('snapshot export records its actual process and current PHP JSON settings', t => {
  if (!available(t)) return;
  const value = exported(17, 6);
  assert.deepEqual(value.snapshot.transport_php, value.actual);
  assert.equal(value.snapshot.transport_php.capture_scope, 'snapshot_export');
  assert.equal(value.snapshot.transport_php.php_sapi, 'cli', 'The test must not mislabel its CLI process as website PHP.');
  assert.match(value.snapshot.transport_php.php_version, /^\d+\.\d+\.\d+/);
  assert.equal(value.snapshot.transport_php.serialize_precision, '17');
  assert.equal(value.snapshot.transport_php.precision, '6');
});

test('export diagnostics leave current precision and existing table/schema hash semantics intact', t => {
  if (!available(t)) return;
  for (const serialize of [-1, 17, 14]) {
    const value = exported(serialize, 8);
    assert.deepEqual(value.before, value.after);
    assert.equal(value.snapshot.transport_php.serialize_precision, String(serialize));
    assert.equal(value.row_hash_unchanged, true);
    assert.equal(value.schema_hash_unchanged, true);
  }
  assert.notEqual(exported(-1).snapshot.tables.users.sha256, exported(17).snapshot.tables.users.sha256,
    'Diagnostic metadata must not silently canonicalize or repair the suspected mismatch.');
});

test('snapshot PHP metadata contains only the five fixed diagnostic values', t => {
  if (!available(t)) return;
  const value = exported();
  assert.deepEqual(Object.keys(value.snapshot.transport_php).sort(),
    ['capture_scope', 'php_sapi', 'php_version', 'precision', 'serialize_precision']);
  for (const item of Object.values(value.snapshot.transport_php)) assert.equal(typeof item, 'string');
  assert.ok(!JSON.stringify(value.snapshot.transport_php).includes('private-value-must-not-be-captured'));
  assert.deepEqual(value.snapshot.coverage, { write_routes: [], role_memberships: [], full_dashboard: false,
    print_protocol: 1, catalog_scopes: 0, media: true });
});
