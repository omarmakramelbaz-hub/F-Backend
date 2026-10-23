'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const crypto = require('node:crypto'), fs = require('node:fs/promises');
const os = require('node:os'), path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const helper = path.join(root, 'desktop/snapshot-row-hashes.php');
const bootstrap = path.join(root, 'app/Services/Dashboard/DesktopDashboardBootstrap.php');
const importer = path.join(root, 'app/Services/Dashboard/DesktopDashboardImport.php');
const schema = path.join(root, 'app/Services/Dashboard/DesktopDashboardSchema.php');
const options = { encoding: 'utf8', timeout: 30000, windowsHide: true, maxBuffer: 24 * 1024 * 1024 };
const digest = value => crypto.createHash('sha256').update(value).digest('hex');

function php(t) {
  const command = process.env.DESKTOP_TEST_PHP || 'php';
  const available = spawnSync(command, ['-n', '-r', 'echo PHP_VERSION_ID;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && available.error?.code === 'ENOENT') {
    t.skip('PHP is unavailable; set DESKTOP_TEST_PHP to run the actual raw-row checksum regression.');
    return null;
  }
  assert.ifError(available.error); assert.equal(available.status, 0, available.stderr);
  assert.ok(Number(available.stdout) >= 80200, 'The reviewed desktop PHP is 8.2 or later.');
  return command;
}
function run(command, code, input = '', ini = [], args = []) {
  const result = spawnSync(command, ['-n', ...ini, '-r', code, helper, bootstrap, importer, schema, ...args], { ...options, input });
  assert.ifError(result.error); assert.equal(result.status, 0, result.stderr); assert.equal(result.stderr, '');
  return result.stdout;
}

const producer = String.raw`
  require $argv[2];
  $rows=[['id'=>1,'fraction'=>0.1,'whole'=>1.0,'negative'=>-0.0,
    'integer'=>9007199254740993,'decimal'=>'9007199254740993.12345678901234567890',
    'small'=>1.0e-7,'large'=>6.02214076e23,'text'=>'رنجة {"rows":[]} \\ ] }']];
  $tables=[];$schema=[];
  foreach(['users','wallets'] as $name){
    $ddl='CREATE TABLE '.chr(96).$name.chr(96).' ('.chr(96).'id'.chr(96).' bigint NOT NULL) ENGINE=InnoDB';
    $tables[$name]=['ddl'=>$ddl,'rows'=>$rows,'sha256'=>hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($rows))];
    $schema[$name]=hash('sha256',$ddl);
  }
  ksort($schema);
  echo \App\Services\Dashboard\DesktopDashboardBootstrap::json([
    'format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>'00000000-0000-4000-8000-000000000001',
    'device_id'=>'00000000-0000-4000-8000-000000000002','actor_id'=>1,'branches'=>['f:1'],
    'source'=>[],'coverage'=>['full_dashboard'=>false],
    'schema_hash'=>hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($schema)),'tables'=>$tables]);
`;
const summarize = String.raw`
  require $argv[1];require $argv[2];
  $input=stream_get_contents(STDIN);$snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
  $before=['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')];
  $wire=DesktopSnapshotRowHashes::fromJson($input);$matches=[];$old=[];
  foreach($snapshot['tables'] as $name=>$part){
    $matches[$name]=hash_equals($part['sha256'],$wire[$name]);
    $old[$name]=hash_equals($part['sha256'],hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($part['rows'])));
  }
  $row=$snapshot['tables']['users']['rows'][0];
  echo json_encode(['hashes'=>$wire,'wire_matches'=>$matches,'legacy_matches'=>$old,
    'whole_is_float'=>is_float($row['whole']),'negative_zero'=>json_encode($row['negative'],JSON_PRESERVE_ZERO_FRACTION)==='-0.0',
    'integer_exact'=>$row['integer']===9007199254740993,'decimal_exact'=>$row['decimal']==='9007199254740993.12345678901234567890',
    'ini_unchanged'=>$before===['serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision')]],JSON_THROW_ON_ERROR);
`;

test('exact row bytes verify across PHP encoder settings without changing values or INI settings', t => {
  const command = php(t); if (!command) return;
  let demonstratedLegacyFailure = false;
  for (const server of [-1, 17, 14, 100]) {
    const wire = run(command, producer, '', ['-d', `serialize_precision=${server}`]);
    for (const desktop of [-1, 17, 14]) {
      const actual = JSON.parse(run(command, summarize, wire, ['-d', `serialize_precision=${desktop}`, '-d', 'precision=6']));
      assert.deepEqual(actual.wire_matches, { users: true, wallets: true });
      for (const name of ['whole_is_float', 'negative_zero', 'integer_exact', 'decimal_exact', 'ini_unchanged']) assert.equal(actual[name], true, name);
      if (server === desktop) assert.deepEqual(actual.legacy_matches, { users: true, wallets: true });
      if (server === 17 && desktop === -1) {
        assert.deepEqual(actual.legacy_matches, { users: false, wallets: false }); demonstratedLegacyFailure = true;
      }
    }
  }
  assert.equal(demonstratedLegacyFailure, true);
});

const parseOnly = String.raw`
  require $argv[1];$input=stream_get_contents(STDIN);
  try{json_decode($input,true,512,JSON_THROW_ON_ERROR);$result=DesktopSnapshotRowHashes::fromJson($input);echo json_encode(['hashes'=>$result],JSON_THROW_ON_ERROR);}
  catch(Throwable $error){echo json_encode(['rejected'=>true,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
`;
test('decoded escaped keys select exact row spans and strings containing delimiters stay inside their values', t => {
  const command = php(t); if (!command) return;
  const rows = '[{"id":1,"text":"{\\\"rows\\\":[1]} ] } \\\\ \\u0631","nested":[true,null,{"x":"[\\\"}]"}]}]';
  const wire = '{"ignored":{"tables":["misleading"]},"tabl\\u0065s":{"u\\u0073ers":{"r\\u006fws":' + rows + ',"ignored":{"rows":[2]}}},"tail":[{"rows":[]}]}';
  const result = JSON.parse(run(command, parseOnly, wire));
  assert.deepEqual(result, { hashes: { users: digest(rows) } });
});

test('duplicate semantic keys and invalid table structures are rejected without echoing business input', t => {
  const command = php(t); if (!command) return;
  const invalid = [
    '{"tables":{},"tabl\\u0065s":{}}',
    '{"tables":{},"source":1,"sour\\u0063e":2}',
    '{"tables":{"users":{"rows":[]},"us\\u0065rs":{"rows":[]}}}',
    '{"tables":{"users":{"rows":[],"r\\u006fws":[]}}}',
    '{"tables":{"users":{"rows":[],"ddl":"x","d\\u0064l":"y"}}}',
    '{"tables":[]}', '{"tables":{"users":[]}}', '{"tables":{"users":{"rows":{}}}}',
    '{"tables":{"users":{"rows":null}}}', '{"tables":{"users":{"ddl":"PRIVATE_ACCOUNT_VALUE"}}}',
    '{"tables":{"users":{"rows":[1,]}}}', '{"tables":{"users":{"rows":[]}}}x',
  ];
  for (const wire of invalid) {
    const result = JSON.parse(run(command, parseOnly, wire));
    assert.equal(result.rejected, true, wire); assert.equal(JSON.stringify(result).includes('PRIVATE_ACCOUNT_VALUE'), false);
  }
});

test('finite extremes encoded by PHP at precision 4096 remain valid without losing subnormal values', t => {
  const command = php(t); if (!command) return;
  const wire = run(command, String.raw`
    require $argv[2];
    $rows=[['max'=>PHP_FLOAT_MAX,'min'=>5e-324,'negative_min'=>-5e-324]];
    $encoded=\App\Services\Dashboard\DesktopDashboardBootstrap::json($rows);
    echo \App\Services\Dashboard\DesktopDashboardBootstrap::json(['tables'=>['users'=>['rows'=>$rows,'sha256'=>hash('sha256',$encoded)]],
      'server_precision'=>ini_get('serialize_precision'),'subnormal_token_bytes'=>strlen(\App\Services\Dashboard\DesktopDashboardBootstrap::json(5e-324))]);
  `, '', ['-d', 'serialize_precision=4096']);
  const actual = JSON.parse(run(command, String.raw`
    require $argv[1];$input=stream_get_contents(STDIN);$snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    $hashes=DesktopSnapshotRowHashes::fromJson($input);$row=$snapshot['tables']['users']['rows'][0];
    echo json_encode(['matches'=>hash_equals($snapshot['tables']['users']['sha256'],$hashes['users']),
      'max_exact'=>$row['max']===PHP_FLOAT_MAX,'subnormal_exact'=>$row['min']===5e-324&&$row['min']>0,
      'negative_subnormal_exact'=>$row['negative_min']===-5e-324&&$row['negative_min']<0,
      'server_precision'=>$snapshot['server_precision'],'subnormal_token_bytes'=>$snapshot['subnormal_token_bytes']],JSON_THROW_ON_ERROR);
  `, wire, ['-d', 'serialize_precision=-1']));
  assert.equal(actual.matches, true); assert.equal(actual.max_exact, true);
  assert.equal(actual.subnormal_exact, true); assert.equal(actual.negative_subnormal_exact, true);
  assert.equal(actual.server_precision, '4096');
  assert.ok(actual.subnormal_token_bytes > 700 && actual.subnormal_token_bytes < 2048, JSON.stringify(actual));
});

test('a large row span is hashed with bounded additional allocation', t => {
  const command = php(t); if (!command) return;
  const result = JSON.parse(run(command, String.raw`
    require $argv[1];
    $text=str_repeat('x',8*1024*1024);$rows='[{"text":"'.$text.'"}]';unset($text);
    $expected=hash('sha256',$rows);$input='{"tables":{"users":{"rows":'.$rows.'}}}';unset($rows);
    $snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    gc_collect_cycles();$before=memory_get_usage(true);memory_reset_peak_usage();$started=hrtime(true);
    $hashes=DesktopSnapshotRowHashes::fromJson($input);
    echo json_encode(['matches'=>$hashes['users']===$expected,'additional_peak'=>memory_get_peak_usage(true)-$before,
      'elapsed_ms'=>round((hrtime(true)-$started)/1000000,2)],JSON_THROW_ON_ERROR);
  `));
  assert.equal(result.matches, true); assert.ok(result.additional_peak <= 4 * 1024 * 1024, JSON.stringify(result));
  t.diagnostic('8 MiB raw-row scan: ' + JSON.stringify(result));
});

const serviceFixture = String.raw`
namespace Illuminate\Support\Facades {
  class DB {
    public static int $writes=0;
    public static function connection(){return new class{public function getConfig($name){return '127.0.0.1';}public function getDatabaseName(){return 'fasakhansta_dashboard_stage_0123456789abcdef';}};}
    public static function select($sql){if($sql!=='SHOW TABLES')throw new \RuntimeException('UNEXPECTED_READ');return [];}
    public static function statement($sql){self::$writes++;throw new \RuntimeException('WRITE_BOUNDARY');}
    public static function unprepared($sql){self::$writes++;throw new \RuntimeException('UNEXPECTED_DDL');}
  }
  class Validator {public static function make(...$args){return new class{public function validate(){return [];}};}}
}
namespace App\Services\Dashboard {
  class DesktopDashboardSource {public function matches($value){return true;}}
  class DesktopDashboardMedia {public static function receipt($value){return [];}}
}
namespace {
  class RejectedSnapshot extends \RuntimeException {public function __construct(public int $status,string $message=''){parent::__construct($message);}}
  function abort_unless($value,$status,$message=''){if(!$value)throw new RejectedSnapshot($status,$message);}
  function abort_if($value,$status,$message=''){abort_unless(!$value,$status,$message);}
  function config($name){return $name==='desktop_dashboard.local'?true:'00000000-0000-4000-8000-000000000002';}
  function app($class){return new $class;}
  require $argv[1];require $argv[2];require $argv[3];require $argv[4];
  $input=stream_get_contents(STDIN);$snapshot=json_decode($input,true,512,JSON_THROW_ON_ERROR);
  $hashes=DesktopSnapshotRowHashes::fromJson($input);$mode=$argv[5]??'wire';
  if($mode==='missing')unset($hashes['users']);
  if($mode==='extra')$hashes['extra']=str_repeat('a',64);
  if($mode==='reordered')$hashes=array_reverse($hashes,true);
  if($mode==='invalid_hash')$hashes['users']='not-a-checksum';
  if($mode==='wrong_hash')$hashes['users']=str_repeat('a',64);
  if($mode==='spoof'){$snapshot['wire_hashes']=$hashes;$hashes=null;}
  try{
    (new \App\Services\Dashboard\DesktopDashboardImport)->import($snapshot,$mode==='legacy'?null:$hashes);
    echo json_encode(['unexpected_success'=>true],JSON_THROW_ON_ERROR);
  }catch(Throwable $error){
    echo json_encode(['boundary'=>$error->getMessage()==='WRITE_BOUNDARY','status'=>$error instanceof RejectedSnapshot?$error->status:null,
      'writes'=>\Illuminate\Support\Facades\DB::$writes,'private_leaked'=>str_contains($error->getMessage(),'PRIVATE_ACCOUNT_VALUE')],JSON_THROW_ON_ERROR);
  }
}
`;
test('the actual import guard accepts wire hashes across precision settings and retains the legacy guard', t => {
  const command = php(t); if (!command) return;
  const wire = run(command, producer, '', ['-d', 'serialize_precision=17']);
  const accepted = JSON.parse(run(command, serviceFixture, wire, ['-d', 'serialize_precision=-1'], ['wire']));
  assert.deepEqual(accepted, { boundary: true, status: null, writes: 1, private_leaked: false });
  const legacyRejected = JSON.parse(run(command, serviceFixture, wire, ['-d', 'serialize_precision=-1'], ['legacy']));
  assert.deepEqual(legacyRejected, { boundary: false, status: 422, writes: 0, private_leaked: false });
  const legacyAccepted = JSON.parse(run(command, serviceFixture, wire, ['-d', 'serialize_precision=17'], ['legacy']));
  assert.equal(legacyAccepted.boundary, true);
});

test('wrong, incomplete, reordered or public-property hash maps cannot bypass the actual import guard', t => {
  const command = php(t); if (!command) return;
  const wire = run(command, producer, '', ['-d', 'serialize_precision=17']);
  for (const mode of ['missing', 'extra', 'reordered', 'invalid_hash', 'wrong_hash', 'spoof']) {
    const result = JSON.parse(run(command, serviceFixture, wire, ['-d', 'serialize_precision=-1'], [mode]));
    assert.deepEqual(result, { boundary: false, status: 422, writes: 0, private_leaked: false }, mode);
  }
  const changed = wire.replace('9007199254740993.12345678901234567890', '9007199254740993.12345678901234567891');
  const result = JSON.parse(run(command, serviceFixture, changed, ['-d', 'serialize_precision=-1'], ['wire']));
  assert.deepEqual(result, { boundary: false, status: 422, writes: 0, private_leaked: false });
});

test('actual import.php derives the private hash map from input and preserves decoded numeric types', async t => {
  const command = php(t); if (!command) return;
  const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'desktop-raw-row-entry-'));
  t.after(() => fs.rm(temporary, { recursive: true, force: true }));
  await fs.mkdir(path.join(temporary, 'desktop'), { recursive: true });
  for (const name of ['import.php', 'snapshot-row-hashes.php', 'import-diagnostic.php'])
    await fs.copyFile(path.join(root, 'desktop', name), path.join(temporary, 'desktop', name));
  await fs.writeFile(path.join(temporary, 'desktop/bootstrap.php'), String.raw`<?php
namespace App\Services\Dashboard {
  class DesktopDashboardImport {
    public function import(array $snapshot,?array $wireHashes=null):array {
      if($wireHashes!==['users'=>$snapshot['tables']['users']['sha256']])throw new \RuntimeException('UNBOUND_WIRE_HASHES');
      return ['wire_bound'=>true,'whole_is_float'=>is_float($snapshot['tables']['users']['rows'][0]['whole']),
        'integer_exact'=>$snapshot['tables']['users']['rows'][0]['integer']===9007199254740993];
    }
  }
}
namespace {return new class{public function make($class){return new $class;}};}
`);
  const rows = '[{"id":1,"whole":1.0,"integer":9007199254740993}]';
  const wire = '{"tables":{"users":{"rows":' + rows + ',"sha256":"' + digest(rows) + '"}},"wire_hashes":{"users":"untrusted"}}';
  const result = spawnSync(command, ['-n', '-d', 'serialize_precision=-1', path.join(temporary, 'desktop/import.php')], { ...options, input: wire });
  assert.ifError(result.error); assert.equal(result.status, 0, result.stderr); assert.equal(result.stderr, '');
  const { php_profiles: profiles, ...receipt } = JSON.parse(result.stdout);
  assert.deepEqual(receipt, { wire_bound: true, whole_is_float: true, integer_exact: true });
  assert.equal(profiles.server_profile_status, 'missing');
  assert.equal(profiles.desktop_profile_status, 'valid');
  assert.equal(profiles.desktop.capture_scope, 'desktop_import');
  assert.equal(profiles.desktop.php_sapi, 'cli');
});

test('actual import.php rejects nonfinite, underflowed and oversized numbers before application boot or database writes', async t => {
  const command = php(t); if (!command) return;
  const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'desktop-raw-row-numeric-fence-'));
  t.after(() => fs.rm(temporary, { recursive: true, force: true }));
  await fs.mkdir(path.join(temporary, 'desktop'), { recursive: true });
  for (const name of ['import.php', 'snapshot-row-hashes.php', 'import-diagnostic.php'])
    await fs.copyFile(path.join(root, 'desktop', name), path.join(temporary, 'desktop', name));
  const marker = path.join(temporary, 'desktop/boot-reached');
  await fs.writeFile(path.join(temporary, 'desktop/bootstrap.php'), String.raw`<?php
file_put_contents(__DIR__.'/boot-reached','application booted');
return new class{public function make($class){return new class{public function import(array $snapshot,?array $hashes=null):array{return ['unexpected_success'=>true];}};}};
`);
  for (const token of ['1e9999', '-1e9999', '1e-9999', '-1e-9999', '1.234e-9999', '0.' + '0'.repeat(2048)]) {
    const rows = '[{"id":1,"value":' + token + ',"private":"PRIVATE_ACCOUNT_VALUE"}]';
    const wire = '{"tables":{"users":{"rows":' + rows + ',"sha256":"' + digest(rows) + '"}}}';
    const result = spawnSync(command, ['-n', path.join(temporary, 'desktop/import.php')], { ...options, input: wire });
    assert.ifError(result.error); assert.equal(result.status, 1, 'The numeric transport must be rejected.');
    assert.equal(result.stdout, ''); assert.equal(result.stderr.includes('PRIVATE_ACCOUNT_VALUE'), false);
    const reports = result.stderr.split(/\r?\n/).filter(line => line.startsWith('DESKTOP_IMPORT_DIAGNOSTIC '));
    assert.equal(reports.length, 1);
    assert.equal(JSON.parse(reports[0].slice('DESKTOP_IMPORT_DIAGNOSTIC '.length)).phase, 'json');
    await assert.rejects(fs.stat(marker), { code: 'ENOENT' });
  }
});
