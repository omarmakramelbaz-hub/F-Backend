'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs/promises'), path = require('node:path'), os = require('node:os'), crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');
const AccountProfiles = require('../src/account-profiles.cjs');
const { GenerationStore } = require('../src/dashboard-generation.cjs');
const { WorkspaceLegacyInspector, READ_ONLY_PHP } = require('../src/workspace-legacy-inspector.cjs');
const { WorkspaceMigrationInventory } = require('../src/workspace-migration.cjs');
const { encryption, record, hash } = require('./helpers/workspace-fixture.cjs');
async function fixture(t) {
  const root = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'workspace-legacy-inspector-')), safeStorage = encryption();
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const profiles = new AccountProfiles({ root, safeStorage }); await profiles.bind('default', { actorId: 101, serverOrigin: 'https://dashboard.example.test' });
  const account = await profiles.account('default'), calls = [], owners = []; let active = null, dashboard;
  const options = { profiles, safeStorage, activeRuntime: () => active, ownRuntime: runtime => { owners.push(runtime); }, stopped: () => false,
    createRuntime: async row => { calls.push(['create', row.id]); return runtime(); }, nativeRun: async (_php, args, options, input) => {
      calls.push(['native-read']); assert.equal(args.at(-1), READ_ONLY_PHP); assert.equal(options.env.DB_DATABASE, dashboard.prepared.database);
      assert.equal(JSON.parse(input).actorId, 101); return JSON.stringify(dashboard.state);
    } };
  function runtime() {
    const source = dashboard;
    const value = { profile: source.folder, bundle: source.bundle, installedBundle: source.bundle, php: path.join(source.bundle, 'php/php.exe'),
      phpIni: path.join(source.bundle, 'php/runtime.ini'), application: path.join(source.bundle, 'application'), manifest: source.manifest,
      sourceFingerprint: source.prepared.sourceFingerprint, environment: { DB_DATABASE: source.prepared.database }, settings() { throw Error('Inspector must not call settings.'); },
      async start() { calls.push(['start']); }, async stop() { calls.push(['stop']); }, cancelStartup() { calls.push(['cancel']); } };
    return value;
  }
  async function prepared() {
    const group = record(), value = structuredClone(group.candidate.prepared); delete value.workspaceProtocol; delete value.workspace; delete value.importReceiptSha256;
    const folder = path.join(account.profile, 'dashboard'), metadata = new GenerationStore(folder, safeStorage), bundle = path.join(folder, 'runtimes', value.sourceRevision);
    const files = { 'php/php.exe': 'synthetic verified native boundary', 'php/runtime.ini': '; no global config', 'application/desktop/probe.php': '<?php // synthetic fixture' };
    const manifest = { format: 1, platform: 'win32-x64', channel: 'preview', sourceRevision: value.sourceRevision, sourceFingerprint: value.sourceFingerprint,
      sourceHashes: { 'desktop/probe.php': hash(files['application/desktop/probe.php']) } };
    files['manifest.json'] = JSON.stringify(manifest);
    for (const [relative, bytes] of Object.entries(files)) { const file = path.join(bundle, relative); await fs.mkdir(path.dirname(file), { recursive: true }); await fs.writeFile(file, bytes); }
    await fs.mkdir(path.join(folder, 'database', value.database), { recursive: true });
    await metadata.write('prepared', value); await metadata.write('credentials', { deviceId: value.deviceId, rootPassword: 'a'.repeat(64), appPassword: 'b'.repeat(64), appKey: 'base64:' + Buffer.alloc(32, 1).toString('base64') });
    await metadata.write('runtimes/' + value.sourceRevision, { format: 1, revision: value.sourceRevision,
      files: Object.fromEntries(Object.entries(files).map(([relative, bytes]) => [relative, hash(bytes)])), sourceFingerprint: value.sourceFingerprint });
    const state = { format: 1, kind: 'native-legacy-migration-state', device_id: value.deviceId, actor_id: 101, snapshot_id: value.snapshotId,
      schema_hash: value.schemaHash, database: value.database, held: false, sequence: 3, journal: { pending: 0, conflicts: 0, acknowledged: 3 },
      prints: { prepared: 0, uncertain: 0, unacknowledged: 0, orphans: 0 }, identity_sha256: hash('all original rows') };
    dashboard = { folder, metadata, bundle, manifest, prepared: value, state }; return dashboard;
  }
  return { root, profiles, account, safeStorage, calls, owners, options, prepared, runtime,
    setActive(value) { active = value; }, create: overrides => new WorkspaceLegacyInspector({ ...options, ...overrides }) };
}
function ledger(file, { wal = false } = {}) {
  const db = new DatabaseSync(file); if (wal) db.exec('PRAGMA journal_mode=WAL');
  db.exec('CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);CREATE TABLE snapshots(id TEXT PRIMARY KEY,payload TEXT);CREATE TABLE orders(id TEXT PRIMARY KEY,status TEXT);CREATE TABLE outbox(seq INTEGER PRIMARY KEY,id TEXT,acked INTEGER,last_error TEXT);');
  return db;
}
function printRecord(prepared, effect, phase = 'planned') {
  const value = { format: 1, identity: { effect_id: effect, device_id: prepared.deviceId, enrollment_id: hash('enrollment'), origin_command_id: crypto.randomUUID(),
    actor_id: prepared.actorId, branch: 'f:100', kind: 'sale_receipt', revision: 1, snapshot_sha256: hash('snapshot'), render_sha256: hash('render') },
    invocation_id: crypto.randomUUID(), native_session_id: crypto.randomUUID(), claim_token: crypto.randomUUID(), phase, delivered: false };
  if (phase === 'outcome') { value.delivered = true; value.receipt = { effect_id: effect, actor_id: prepared.actorId, branch: 'f:100', claim_token: value.claim_token,
    native_session_id: value.native_session_id, invocation_id: value.invocation_id, permit_hash: hash('permit'), enrollment_id: value.identity.enrollment_id,
    origin_command_id: value.identity.origin_command_id, revision: 1, state: 'invoked', reason: 'spooler_accepted' }; }
  return value;
}

test('an absent legacy ledger is known clean without creating a SQLite file, dashboard settings or native children', async t => {
  const f = await fixture(t), before = await fs.readdir(f.root), result = await f.create().inspectProfile(f.account);
  assert.equal(result.prepared, false); assert.equal(Object.values(result.counts).every(value => value === 0), true);
  assert.deepEqual(await fs.readdir(f.root), before); assert.deepEqual(f.calls, []); assert.equal(f.owners.length, 0);
});

test('actual SQLite readOnly inspection counts unacknowledged errors and open orders without Store DDL or changing accepted DB/WAL bytes', async t => {
  const f = await fixture(t), file = path.join(f.root, 'fasakhansta-pos.sqlite'), db = ledger(file, { wal: true });
  try {
    db.exec("INSERT INTO outbox VALUES(1,'a',0,NULL),(2,'b',0,'conflict'),(3,'c',1,NULL);INSERT INTO orders VALUES('a','draft'),('b','paid'),('c','cancelled')");
    const before = { db: await fs.readFile(file), wal: await fs.readFile(file + '-wal') }, result = await f.create().inspectProfile(f.account);
    assert.equal(result.counts.legacyPending, 2); assert.equal(result.counts.legacyConflicts, 1); assert.equal(result.counts.openOrders, 1);
    assert.deepEqual(await fs.readFile(file), before.db); assert.deepEqual(await fs.readFile(file + '-wal'), before.wal);
    assert.deepEqual(f.calls, []);
  } finally { db.close(); }
});

test('an orphan WAL or malformed existing database is unknown and never replaced by a clean empty ledger', async t => {
  const f = await fixture(t), file = path.join(f.root, 'fasakhansta-pos.sqlite'); await fs.writeFile(file + '-wal', 'accepted orphan bytes');
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_SQLITE_ORPHAN' });
  assert.equal(await fs.readFile(file + '-wal', 'utf8'), 'accepted orphan bytes'); await fs.unlink(file + '-wal');
  await fs.writeFile(file, 'damaged original SQLite bytes'); const before = await fs.readFile(file);
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_SQLITE_UNVERIFIED' }); assert.deepEqual(await fs.readFile(file), before);
});

test('inactive prepared A uses one owned exact-source runtime and fixed read-only native query, then stops without rewriting accepted metadata', async t => {
  const f = await fixture(t), d = await f.prepared(), files = ['prepared.enc', 'credentials.enc', 'runtimes/' + d.prepared.sourceRevision + '.enc'];
  const before = await Promise.all(files.map(name => fs.readFile(path.join(d.folder, name))));
  const result = await f.create().inspectProfile(f.account); assert.equal(result.prepared, true); assert.equal(Object.values(result.counts).every(value => value === 0), true);
  assert.equal(f.owners.length, 1); assert.deepEqual(f.calls, [['create', 'default'], ['start'], ['native-read'], ['stop']]);
  for (let i = 0; i < files.length; i++) assert.deepEqual(await fs.readFile(path.join(d.folder, files[i])), before[i]);
});

test('the active original runtime is reused and never stopped, rebound or registered as a new inspection owner', async t => {
  const f = await fixture(t); await f.prepared(); const active = f.runtime(); f.setActive(active);
  await f.create().inspectProfile(f.account); assert.equal(f.owners.length, 0); assert.deepEqual(f.calls, [['start'], ['native-read']]);
});

test('a private migration lease binds the full original inventory without a second runtime and ignores only its own held flag', async t => {
  const f = await fixture(t), d = await f.prepared(), inspector = f.create(), original = await inspector.inspectProfile(f.account); f.calls.length = 0;
  await inspector.withRuntime(f.account, {}, async lease => {
    const before = await lease.inventorySnapshot(); assert.deepEqual(before.snapshot, original);
    d.state.held = true; const fenced = await lease.inventorySnapshot();
    assert.equal(fenced.state.held, true); assert.equal(fenced.snapshot.holds.refresh, true);
    assert.equal(fenced.businessSha256, before.businessSha256);
    d.state.journal.acknowledged++; d.state.identity_sha256 = hash('external accepted work');
    assert.notEqual((await lease.inventorySnapshot()).businessSha256, before.businessSha256);
  });
  assert.equal(f.calls.filter(value => value[0] === 'create').length, 1);
  assert.equal(f.calls.filter(value => value[0] === 'start').length, 1);
  assert.equal(f.calls.filter(value => value[0] === 'stop').length, 1);
});

test('all native print records are inspected: an older unresolved record beyond 100 terminal outcomes blocks migration', async t => {
  const f = await fixture(t), d = await f.prepared();
  for (let index = 0; index < 101; index++) { const id = hash('print-' + index); await d.metadata.write('print-effects/' + id, printRecord(d.prepared, id, index === 0 ? 'planned' : 'outcome')); }
  const result = await f.create().inspectProfile(f.account); assert.equal(result.counts.printEffects, 1);
  const inventory = new WorkspaceMigrationInventory({ profiles: f.profiles, inspectProfile: f.create().inspectProfile });
  assert.equal((await inventory.inspectAll()).ready, false);
});

test('global SQL print uncertainty, orphan/reprint evidence and database fences cannot be hidden by an empty recent audit', async t => {
  const f = await fixture(t), d = await f.prepared(); d.state.prints.uncertain = 1; d.state.prints.orphans = 1; d.state.held = true;
  const result = await f.create().inspectProfile(f.account); assert.equal(result.counts.printEffects, 2); assert.equal(result.holds.refresh, true);
  assert.equal(f.calls.some(call => call.includes('print-audit')), false);
});

test('missing credentials, a foreign actor binding or unknown native print files defer before a runtime can create settings', async t => {
  const f = await fixture(t), d = await f.prepared(); await fs.unlink(path.join(d.folder, 'credentials.enc'));
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_CREDENTIALS_UNVERIFIED' }); assert.deepEqual(f.calls, []);
  await d.metadata.write('credentials', { deviceId: d.prepared.deviceId, rootPassword: 'a'.repeat(64), appPassword: 'b'.repeat(64), appKey: 'base64:' + Buffer.alloc(32, 1).toString('base64') });
  await d.metadata.write('prepared', { ...d.prepared, actorId: 202 });
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_PREPARED_BINDING_INVALID' }); assert.deepEqual(f.calls, []);
  await d.metadata.write('prepared', d.prepared); await fs.mkdir(path.join(d.folder, 'print-effects')); await fs.writeFile(path.join(d.folder, 'print-effects', 'unknown.enc'), 'accepted unknown bytes');
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_PRINT_FILES_UNVERIFIED' }); assert.deepEqual(f.calls, []);
});

test('every encrypted legacy hold participates in the returned inventory and a missing remote index cannot hide retained requests', async t => {
  const f = await fixture(t), d = await f.prepared();
  for (const name of ['refresh', 'return', 'delta-background', 'central-mode']) await d.metadata.write(name, { retained: true });
  await d.metadata.write('remote-writes', { format: 1, dirty: true, pending: [crypto.randomUUID()] });
  const result = await f.create().inspectProfile(f.account); assert.equal(Object.values(result.holds).every(Boolean), true); assert.equal(result.counts.remotePending, 1);
  await fs.unlink(path.join(d.folder, 'remote-writes.enc')); await fs.mkdir(path.join(d.folder, 'remote-attempts')); await fs.writeFile(path.join(d.folder, 'remote-attempts', 'retained.enc'), 'immutable original request');
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_REMOTE_UNVERIFIED' });
});

test('new acknowledged work changes inventory identity even if both pending counts are zero', async t => {
  const f = await fixture(t), d = await f.prepared(), inspector = f.create(), first = await inspector.inspectProfile(f.account);
  d.state.journal.acknowledged++; d.state.identity_sha256 = hash('new accepted and acknowledged command');
  const second = await inspector.inspectProfile(f.account); assert.notEqual(first.identitySha256, second.identitySha256);
  assert.equal(first.counts.pending, 0); assert.equal(second.counts.pending, 0);
});

const { spawnSync } = require('node:child_process');
const php = process.env.WORKSPACE_TEST_PHP || process.env.DESKTOP_TEST_PHP || 'php';
const phpAvailable = spawnSync(php, ['-n', '-r', 'exit(0);'], { encoding: 'utf8', timeout: 10000 }).status === 0;
test('the actual fixed PHP producer uses a read-only transaction and counts an older unknown beyond 100 acknowledged attempts', { skip: !phpAvailable }, () => {
  const value = structuredClone(record().candidate.prepared); delete value.workspaceProtocol; delete value.workspace; delete value.importReceiptSha256;
  const dataset = Buffer.from(JSON.stringify(value)).toString('base64');
  // Only the PDO boundary is substituted. The installed literal's SQL,
  // iteration, binding checks, aggregate counts and output execute unchanged.
  const boundary = String.raw`
$GLOBALS['fixture']=json_decode(base64_decode('DATASET'),true);$GLOBALS['readAttemptRows']=0;
class InspectorPDO {
 const ATTR_ERRMODE=1,ERRMODE_EXCEPTION=2,ATTR_TIMEOUT=3,FETCH_ASSOC=4,MYSQL_ATTR_USE_BUFFERED_QUERY=5;
 public $readOnly=false,$isolation=false,$begun=false;
 public function __construct(...$arguments){}
 public function setAttribute($name,$value){if($name!==self::MYSQL_ATTR_USE_BUFFERED_QUERY||$value!==false)throw new RuntimeException();}
 public function exec($sql){if($sql==='SET TRANSACTION READ ONLY')$this->readOnly=true;elseif($sql==='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')$this->isolation=true;else throw new RuntimeException('No write statement is allowed.');}
 public function beginTransaction(){if(!$this->readOnly||!$this->isolation)throw new RuntimeException();$this->begun=true;}
 public function commit(){if(!$this->begun)throw new RuntimeException();$this->begun=false;}
 public function prepare($sql){if(!$this->begun)throw new RuntimeException();return new InspectorStatement($sql);}
}
class InspectorStatement {
 private $rows=[],$offset=0;public function __construct(private $sql){}
 public function execute($arguments=[]){
  $v=$GLOBALS['fixture'];$s=$this->sql;$attempts=[['effect_id'=>'older-unknown','actor_id'=>101,'state'=>'burnt','server_ack_hash'=>'','outcome_cipher'=>'']];
  for($i=0;$i<100;$i++)$attempts[]=['effect_id'=>'recent-'.$i,'actor_id'=>101,'state'=>'invoked','server_ack_hash'=>str_repeat('f',64),'outcome_cipher'=>'encrypted-terminal'];
  if(str_contains($s,'information_schema.TABLES'))$this->rows=[['engine'=>'InnoDB']];
  elseif(str_contains($s,'information_schema.COLUMNS'))$this->rows=array_map(fn($name)=>['name'=>$name,'nullable'=>'NO'],$GLOBALS['columns'][$arguments[1]]);
  elseif(str_contains($s,'information_schema.STATISTICS')){
   $t=$arguments[1];$fields=$t==='print_ledger_scopes'?['device_id']:($t==='print_ledger_commands'?['device_id','command_id']:['effect_id']);
   $this->rows=array_map(fn($name)=>['name'=>'PRIMARY','non_unique'=>0,'column_name'=>$name],$fields);
   if($t==='print_ledger_intents')foreach(['device_id','enrollment_id','command_id','kind','slot'] as $name)$this->rows[]=['name'=>'origin_slot_unique','non_unique'=>0,'column_name'=>$name];
  }
  elseif(str_starts_with($s,'SELECT device_id,actor_id,snapshot_id'))$this->rows=[['device_id'=>$v['deviceId'],'actor_id'=>101,'snapshot_id'=>$v['snapshotId'],'schema_hash'=>$v['schemaHash'],'state'=>'ready','refresh_id'=>null,'fenced_sequence'=>17,'branches'=>'["f:100"]','coverage'=>'{"print_protocol":1}']];
  elseif(str_starts_with($s,'SELECT source FROM'))$this->rows=[['source'=>json_encode($v['sourceFingerprint'])]];
  elseif(str_starts_with($s,'SELECT status,COUNT(*)'))$this->rows=[['status'=>'acknowledged','n'=>3]];
  elseif(str_starts_with($s,'SELECT device_id,actor_id,enrollment_id'))$this->rows=[['device_id'=>$v['deviceId'],'actor_id'=>101,'enrollment_id'=>str_repeat('e',64),'schema_version'=>1]];
  elseif(str_starts_with($s,'SELECT COALESCE(MAX(sequence),0) AS n'))$this->rows=[['n'=>3]];
  elseif(str_starts_with($s,'SELECT COUNT(*) AS n')){
   $n=0;
   if(str_contains($s,"WHERE state='claimed'"))$n=count(array_filter($attempts,fn($r)=>$r['state']==='claimed'));
   elseif(str_contains($s,"WHERE state IN ('burnt','review')"))$n=count(array_filter($attempts,fn($r)=>in_array($r['state'],['burnt','review'],true)&&$r['server_ack_hash']===''));
   elseif(str_contains($s,"WHERE outcome_cipher<>''"))$n=count(array_filter($attempts,fn($r)=>$r['outcome_cipher']!==''&&$r['server_ack_hash']===''));
   $this->rows=[['n'=>$n]];
  }
  elseif(str_starts_with($s,'SELECT * FROM desktop_dashboard_commands'))$this->rows=[['sequence'=>1,'command_id'=>'original accepted command','status'=>'acknowledged']];
  elseif(str_starts_with($s,'SELECT * FROM fasakhansta_dashboard_effects.print_ledger_attempts'))$this->rows=$attempts;
  elseif(str_starts_with($s,'SELECT * FROM fasakhansta_dashboard_effects.'))$this->rows=[];
  else throw new RuntimeException('Unreviewed query.');
 }
 public function fetchAll($kind){return $this->rows;}
 public function fetch($kind){if($this->offset>=count($this->rows))return false;if(str_starts_with($this->sql,'SELECT * FROM fasakhansta_dashboard_effects.print_ledger_attempts'))$GLOBALS['readAttemptRows']++;return $this->rows[$this->offset++];}
 public function closeCursor(){}
}
`;
  const code = boundary.replace('DATASET', dataset) + READ_ONLY_PHP.replaceAll('new PDO(', 'new InspectorPDO(').replaceAll('PDO::', 'InspectorPDO::')
    + 'fwrite(STDERR,json_encode(["read_only"=>true,"attempt_rows_read"=>$GLOBALS["readAttemptRows"]]));';
  const result = spawnSync(php, ['-n', '-r', code], { input: JSON.stringify(value), encoding: 'utf8', timeout: 10000,
    env: { ...process.env, DESKTOP_DASHBOARD_LOCAL: 'true', DESKTOP_DASHBOARD_DEVICE_ID: value.deviceId, DB_HOST: '127.0.0.1',
      DB_PORT: '33067', DB_DATABASE: value.database, DB_USERNAME: 'dashboard', DB_PASSWORD: 'b'.repeat(64) } });
  assert.equal(result.status, 0, result.stderr + result.stdout); const inspected = JSON.parse(result.stdout);
  assert.equal(inspected.prints.uncertain, 1); assert.equal(inspected.prints.prepared, 0); assert.equal(inspected.prints.unacknowledged, 0);
  assert.deepEqual(JSON.parse(result.stderr), { read_only: true, attempt_rows_read: 101 }); assert.match(inspected.identity_sha256, /^[a-f0-9]{64}$/);
});

test('all retained profiles are inspected: selected clean A cannot hide B SQLite pending work', async t => {
  const f = await fixture(t); await f.prepared(); const created = await f.profiles.create(); await f.profiles.bind(created.id, { actorId: 202, serverOrigin: 'https://dashboard.example.test' });
  const b = await f.profiles.account(created.id), file = path.join(b.profile, 'fasakhansta-pos.sqlite'), db = ledger(file);
  db.exec("INSERT INTO outbox VALUES(1,'retained-B',0,'awaiting confirmation')"); db.close(); const before = await fs.readFile(file);
  const inspector = f.create(), inventory = new WorkspaceMigrationInventory({ profiles: f.profiles, inspectProfile: inspector.inspectProfile }), result = await inventory.inspectAll();
  assert.equal(result.ready, false); assert.equal(result.rows.length, 2); assert.equal(result.rows.find(row => row.profileId === b.id).snapshot.counts.legacyPending, 1);
  assert.deepEqual(await fs.readFile(file), before); assert.equal((await f.profiles.selected()).id, 'default');
});

test('cancellation drains and stops only an owned inspection runtime; the active account owner remains untouched', async t => {
  for (const active of [false, true]) {
    const f = await fixture(t), d = await f.prepared(), controller = new AbortController(); if (active) f.setActive(f.runtime());
    const inspector = f.create({ nativeRun: async () => { controller.abort(); return JSON.stringify(d.state); } });
    await assert.rejects(inspector.inspectProfile(f.account, { signal: controller.signal }), { code: 'WORKSPACE_LEGACY_CANCELLED' });
    assert.equal(f.calls.filter(call => call[0] === 'stop').length, active ? 0 : 1);
    assert.equal(f.calls.filter(call => call[0] === 'cancel').length, active ? 0 : 1);
  }
});

test('an unbound partial dashboard database or a late remote hold stays unknown without settings repair or data deletion', async t => {
  const f = await fixture(t), folder = path.join(f.root, 'dashboard'); await fs.mkdir(path.join(folder, 'database'), { recursive: true });
  const marker = path.join(folder, 'database', 'accepted-or-partial.ibd'); await fs.writeFile(marker, 'retained bytes');
  await assert.rejects(f.create().inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_UNBOUND_DATA_PRESENT' }); assert.equal(await fs.readFile(marker, 'utf8'), 'retained bytes');
  assert.deepEqual(f.calls, []);
  const d = await f.prepared();
  const inspector = f.create({ nativeRun: async () => { await d.metadata.write('remote-writes', { format: 1, dirty: true, pending: [crypto.randomUUID()] }); return JSON.stringify(d.state); } });
  await assert.rejects(inspector.inspectProfile(f.account), { code: 'WORKSPACE_LEGACY_STATE_CHANGED' });
});

test('equivalent null hold rewrites do not invalidate inventory while their authenticated semantic state stays clean', async t => {
  const f = await fixture(t), d = await f.prepared(); await d.metadata.write('delta-background', null);
  const inspector = f.create(), first = await inspector.inspectProfile(f.account); await d.metadata.write('delta-background', null);
  const second = await inspector.inspectProfile(f.account); assert.equal(first.identitySha256, second.identitySha256); assert.equal(second.holds.delta, false);
});
