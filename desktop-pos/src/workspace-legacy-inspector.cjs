'use strict';
const fs = require('node:fs/promises'), path = require('node:path'), crypto = require('node:crypto');
const { constants } = require('node:fs');
const LocalRuntime = require('./local-runtime.cjs');
const RuntimeArchive = require('./runtime-archive.cjs');
const { prepared: validPrepared } = require('./dashboard-generation.cjs');
const source = require('./dashboard-source.cjs');
const { canonical, digest } = require('./workspace-profile.cjs');
const { COUNTS, HOLDS } = require('./workspace-migration.cjs');
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const count = value => Number.isSafeInteger(value) && value >= 0;
const exact = (value, keys) => value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).sort().join(',') === [...keys].sort().join(',');
const fail = code => Object.assign(Error('تعذر التحقق من جميع سجلات الحساب القديم؛ بياناته محفوظة وتحتاج إكمال المراجعة.'), { code: 'WORKSPACE_LEGACY_' + code });
const need = (value, code) => { if (!value) throw fail(code); };
const normalize = value => process.platform === 'win32' ? path.normalize(value).toLowerCase() : path.normalize(value);
const fileIdentity = value => [value.dev, value.ino, value.mode, value.nlink, value.size, value.mtimeMs, value.ctimeMs].join(':');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');

// Installed native literal; no Laravel bootstrap, caller-supplied SQL, DDL,
// schema creation, journal repair, print resolution or business callback.
const READ_ONLY_PHP = String.raw`
try {
 if(PHP_SAPI!=='cli'||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_USERNAME')!=='dashboard')throw new RuntimeException();
 $raw=stream_get_contents(STDIN,65537);if(strlen($raw)>65536)throw new RuntimeException();$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 $db=(string)getenv('DB_DATABASE');$port=filter_var(getenv('DB_PORT'),FILTER_VALIDATE_INT);$pass=(string)getenv('DB_PASSWORD');
 if(!$port||$port<1024||$port>65535||!preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$db)||!preg_match('/^[a-f0-9]{64}$/D',$pass)
  ||($v['deviceId']??null)!==getenv('DESKTOP_DASHBOARD_DEVICE_ID'))throw new RuntimeException();
 $p=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$db.';charset=utf8mb4','dashboard',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
 $p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
 $p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();
 $rows=function($sql,$a=[])use($p){$q=$p->prepare($sql);$q->execute($a);return $q->fetchAll(PDO::FETCH_ASSOC);};
 $n=function($sql,$a=[])use($rows){$r=$rows($sql,$a);$s=(string)($r[0]['n']??'');if(!preg_match('/^[0-9]{1,16}$/D',$s)||strlen($s)===16&&strcmp($s,'9007199254740991')>0)throw new RuntimeException();return (int)$s;};
 $tables=['desktop_dashboard_local_state','desktop_dashboard_commands','desktop_dashboard_source_manifest'];
 foreach($tables as $t){$r=$rows('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$db,$t]);if(count($r)!==1||strcasecmp($r[0]['engine'],'InnoDB')!==0)throw new RuntimeException();}
 $s=$rows('SELECT device_id,actor_id,snapshot_id,schema_hash,state,refresh_id,fenced_sequence,branches,coverage FROM desktop_dashboard_local_state WHERE device_id=?',[$v['deviceId']]);
 if(count($s)!==1)throw new RuntimeException();$s=$s[0];
 if((int)$s['actor_id']!==$v['actorId']||$s['snapshot_id']!==$v['snapshotId']||$s['schema_hash']!==$v['schemaHash']||!in_array($s['state'],['ready','held'],true))throw new RuntimeException();
 $coverage=json_decode($s['coverage'],true,64,JSON_THROW_ON_ERROR);if(($coverage['print_protocol']??null)!==1)throw new RuntimeException();
 $bound=$rows('SELECT source FROM desktop_dashboard_source_manifest WHERE device_id=? AND snapshot_id=?',[$v['deviceId'],$v['snapshotId']]);
 if(count($bound)!==1||json_decode($bound[0]['source'],true,32,JSON_THROW_ON_ERROR)!==$v['sourceFingerprint'])throw new RuntimeException();
 if($n('SELECT COUNT(*) AS n FROM desktop_dashboard_commands WHERE device_id<>? OR actor_id<>?',[$v['deviceId'],$v['actorId']]))throw new RuntimeException();
 $journal=['pending'=>0,'conflicts'=>0,'acknowledged'=>0];
 foreach($rows('SELECT status,COUNT(*) AS n FROM desktop_dashboard_commands WHERE device_id=? GROUP BY status',[$v['deviceId']]) as $r){
  $key=['pending'=>'pending','conflict'=>'conflicts','acknowledged'=>'acknowledged'][$r['status']]??null;if($key===null)throw new RuntimeException();$journal[$key]=(int)$r['n'];}
 $identity=hash_init('sha256');
 $walk=function($sql,$a=[])use($p,$identity){$q=$p->prepare($sql);$q->execute($a);while($r=$q->fetch(PDO::FETCH_ASSOC))hash_update($identity,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");$q->closeCursor();};
 $walk('SELECT * FROM desktop_dashboard_commands WHERE device_id=? ORDER BY sequence',[$v['deviceId']]);
 $fx='fasakhansta_dashboard_effects';$printTables=['print_ledger_scopes','print_ledger_commands','print_ledger_intents','print_ledger_attempts','print_ledger_reprints'];
 foreach($printTables as $t){$r=$rows('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$fx,$t]);if(count($r)!==1||strcasecmp($r[0]['engine'],'InnoDB')!==0)throw new RuntimeException();}
 $columns=[
  'print_ledger_scopes'=>['device_id','enrollment_id','actor_id','enrollment_cipher','schema_version','created_at'],
  'print_ledger_commands'=>['device_id','command_id','enrollment_id','actor_id','branch','route_name','request_hash','origin_database','snapshot_id','schema_hash','intents_hash','created_at'],
  'print_ledger_intents'=>['effect_id','device_id','enrollment_id','command_id','kind','slot','actor_id','branch','revision','descriptor_hash','descriptor_cipher','lifecycle_state','created_at'],
  'print_ledger_attempts'=>['effect_id','claim_token','native_session_id','actor_id','branch','render_sha256','invocation_id','permit_hash','state','outcome_cipher','server_ack_hash','created_at','updated_at'],
  'print_ledger_reprints'=>['effect_id','request_id','device_id','actor_id','branch','descriptor_cipher','descriptor_hash','created_at']];
 foreach($columns as $t=>$expected){
  $actual=$rows('SELECT COLUMN_NAME AS name,IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$fx,$t]);
  if(array_column($actual,'name')!==$expected||array_filter($actual,fn($r)=>$r['nullable']!=='NO'))throw new RuntimeException();
  $unique=[];foreach($rows('SELECT INDEX_NAME AS name,NON_UNIQUE AS non_unique,COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$fx,$t]) as $ix)if((int)$ix['non_unique']===0)$unique[$ix['name']][]=$ix['column_name'];
  $required=match($t){'print_ledger_scopes'=>['PRIMARY'=>['device_id']],'print_ledger_commands'=>['PRIMARY'=>['device_id','command_id']],'print_ledger_intents'=>['PRIMARY'=>['effect_id'],'origin_slot_unique'=>['device_id','enrollment_id','command_id','kind','slot']],default=>['PRIMARY'=>['effect_id']]};
  foreach($required as $name=>$fields)if(($unique[$name]??null)!==$fields)throw new RuntimeException();
 }

 $scope=$rows('SELECT device_id,actor_id,enrollment_id,schema_version FROM fasakhansta_dashboard_effects.print_ledger_scopes');
 if(count($scope)!==1||$scope[0]['device_id']!==$v['deviceId']||(int)$scope[0]['actor_id']!==$v['actorId']||(int)$scope[0]['schema_version']!==1||!preg_match('/^[a-f0-9]{64}$/D',$scope[0]['enrollment_id']))throw new RuntimeException();
 $orphans=0;foreach(['print_ledger_commands','print_ledger_intents','print_ledger_reprints'] as $t)$orphans+=$n('SELECT COUNT(*) AS n FROM '.$fx.'.'.$t.' WHERE device_id<>? OR actor_id<>?',[$v['deviceId'],$v['actorId']]);
 $orphans+=$n('SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_attempts a WHERE NOT EXISTS(SELECT 1 FROM fasakhansta_dashboard_effects.print_ledger_intents i WHERE i.effect_id=a.effect_id AND i.device_id=?) AND NOT EXISTS(SELECT 1 FROM fasakhansta_dashboard_effects.print_ledger_reprints r WHERE r.effect_id=a.effect_id AND r.device_id=?)',[$v['deviceId'],$v['deviceId']]);
 $orphans+=$n('SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_attempts WHERE actor_id<>?',[$v['actorId']]);
 $orphans+=$n('SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_intents i INNER JOIN fasakhansta_dashboard_effects.print_ledger_reprints r ON r.effect_id=i.effect_id');
 if($n("SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_attempts WHERE state NOT IN ('claimed','burnt','invoked','review','expired')"))throw new RuntimeException();
 $prints=['prepared'=>$n("SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_attempts WHERE state='claimed'"),
  'uncertain'=>$n("SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_attempts WHERE state IN ('burnt','review') AND server_ack_hash=''"),
  'unacknowledged'=>$n("SELECT COUNT(*) AS n FROM fasakhansta_dashboard_effects.print_ledger_attempts WHERE outcome_cipher<>'' AND server_ack_hash=''"),'orphans'=>$orphans];
 foreach($printTables as $t)$walk('SELECT * FROM '.$fx.'.'.$t.' ORDER BY '.($t==='print_ledger_scopes'?'device_id':($t==='print_ledger_commands'?'device_id,command_id':'effect_id')));
 $sequence=$n('SELECT COALESCE(MAX(sequence),0) AS n FROM desktop_dashboard_commands WHERE device_id=?',[$v['deviceId']]);
 $p->commit();echo json_encode(['format'=>1,'kind'=>'native-legacy-migration-state','device_id'=>$v['deviceId'],'actor_id'=>$v['actorId'],
  'snapshot_id'=>$v['snapshotId'],'schema_hash'=>$v['schemaHash'],'database'=>$db,'held'=>$s['state']==='held','journal'=>$journal,'prints'=>$prints,
  'sequence'=>$sequence,'identity_sha256'=>hash_final($identity)],JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,"NATIVE_LEGACY_INSPECTION_UNAVAILABLE\n");exit(1);}
`;

class WorkspaceLegacyInspector {
  constructor({ profiles, createRuntime, activeRuntime = () => null, ownRuntime, safeStorage = profiles?.metadata?.safeStorage, stopped = () => false,
    nativeRun = LocalRuntime.runNative, readDashboard }) {
    need(profiles && typeof profiles.account === 'function' && typeof profiles.directory === 'function' && typeof createRuntime === 'function'
      && typeof activeRuntime === 'function' && typeof ownRuntime === 'function' && typeof safeStorage?.decryptString === 'function'
      && typeof nativeRun === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { profiles, createRuntime, activeRuntime, ownRuntime, safeStorage, stopped, nativeRun, readDashboard });
    this.inspectProfile = this.inspectProfile.bind(this); this.running = new Map();
  }
  check(signal) { need(!this.stopped() && !signal?.aborted, 'CANCELLED'); }
  async directory(target) { await this.profiles.directory(target, false); }
  async file(target, maximum, { optional = true, signal } = {}) {
    this.check(signal); await this.directory(path.dirname(target));
    let before; try { before = await fs.lstat(target); } catch (cause) { if (optional && cause.code === 'ENOENT') return null; throw fail('FILE_UNAVAILABLE'); }
    need(before.isFile() && !before.isSymbolicLink() && before.nlink === 1 && before.size > 0 && before.size <= maximum, 'FILE_UNSAFE');
    const handle = await fs.open(target, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
    try {
      need(fileIdentity(await handle.stat()) === fileIdentity(before), 'FILE_CHANGED');
      const raw = await handle.readFile(); this.check(signal);
      need(fileIdentity(await handle.stat()) === fileIdentity(before) && fileIdentity(await fs.lstat(target)) === fileIdentity(before), 'FILE_CHANGED');
      return { raw, sha256: sha(raw), identity: fileIdentity(before) };
    } finally { await handle.close(); }
  }
  async encrypted(root, key, signal) {
    const target = path.join(root, key + '.enc');
    try {
      const file = await this.file(target, key.startsWith('runtimes/') || key.startsWith('compact-runtimes/') ? 64 * 1024 ** 2 : 16 * 1024 ** 2, { signal });
      if (!file) return { value: null, proof: null };
      return { value: JSON.parse(this.safeStorage.decryptString(file.raw)), proof: file.sha256 };
    } catch (cause) { if (cause.code === 'ENOENT') return { value: null, proof: null }; throw fail('METADATA_UNVERIFIED'); }
  }
  async sqlite(account, signal) {
    const file = path.join(account.profile, 'fasakhansta-pos.sqlite'), names = ['', '-wal', '-shm', '-journal'];
    const before = await this.sqliteFiles(file, names, signal);
    if (!before['']) { need(Object.values(before).every(value => value === null), 'SQLITE_ORPHAN'); return { counts: { legacyPending: 0, legacyConflicts: 0, openOrders: 0 }, proof: before }; }
    const { DatabaseSync } = require('node:sqlite'); let db;
    try {
      db = new DatabaseSync(file, { readOnly: true }); db.exec('PRAGMA query_only=ON; PRAGMA trusted_schema=OFF; PRAGMA busy_timeout=1000; BEGIN DEFERRED');
      const tables = db.prepare("SELECT name,type FROM sqlite_master WHERE name IN ('meta','snapshots','orders','outbox') ORDER BY name").all();
      need(tables.length === 4 && tables.every(row => row.type === 'table'), 'SQLITE_SCHEMA_UNVERIFIED');
      need(db.prepare('SELECT COUNT(*) AS n FROM outbox WHERE acked NOT IN (0,1) OR acked IS NULL').get().n === 0, 'SQLITE_SCHEMA_UNVERIFIED');
      const row = db.prepare('SELECT COUNT(*) AS pending,COALESCE(SUM(CASE WHEN last_error IS NOT NULL THEN 1 ELSE 0 END),0) AS errors FROM outbox WHERE acked=0').get();
      const open = db.prepare("SELECT COUNT(*) AS n FROM orders WHERE status NOT IN ('paid','cancelled') OR status IS NULL").get().n;
      need(count(row.pending) && count(row.errors) && count(open), 'SQLITE_COUNTS_UNVERIFIED');
      const counts = { legacyPending: row.pending, legacyConflicts: row.errors, openOrders: open };
      db.exec('COMMIT'); db.close(); db = null;
      const after = await this.sqliteFiles(file, names, signal); need(canonical(before) === canonical(after), 'SQLITE_CHANGED');
      return { counts, proof: before };
    } catch (cause) { if (cause.code?.startsWith('WORKSPACE_LEGACY_')) throw cause; throw fail('SQLITE_UNVERIFIED'); }
    finally { if (db) { try { db.close(); } catch {} } }
  }
  async sqliteFiles(file, names, signal) {
    const result = {};
    for (const suffix of names) {
      this.check(signal); const target = file + suffix; let before;
      try { before = await fs.lstat(target); } catch (cause) { if (cause.code === 'ENOENT') { result[suffix] = null; continue; } throw fail('SQLITE_UNAVAILABLE'); }
      need(before.isFile() && !before.isSymbolicLink() && before.nlink === 1 && before.size <= 2 * 1024 ** 3, 'SQLITE_UNSAFE');
      if (suffix === '-shm') { result[suffix] = { present: true, bytes: before.size }; continue; }
      const handle = await fs.open(target, constants.O_RDONLY | (constants.O_NOFOLLOW || 0)), digest = crypto.createHash('sha256');
      try {
        need(fileIdentity(await handle.stat()) === fileIdentity(before), 'SQLITE_CHANGED');
        const buffer = Buffer.allocUnsafe(65536); let offset = 0;
        while (true) { this.check(signal); const read = await handle.read(buffer, 0, buffer.length, offset); if (!read.bytesRead) break; digest.update(buffer.subarray(0, read.bytesRead)); offset += read.bytesRead; }
        need(fileIdentity(await handle.stat()) === fileIdentity(before) && fileIdentity(await fs.lstat(target)) === fileIdentity(before), 'SQLITE_CHANGED');
        // SQLite can refresh the WAL inode ctime while opening a read-only
        // connection. Content, inode, mode, links, size and mtime remain bound;
        // ctime is still checked within each opened-file hash against races.
        result[suffix] = { sha256: digest.digest('hex'), identity: [before.dev, before.ino, before.mode, before.nlink, before.size, before.mtimeMs].join(':') };
      } finally { await handle.close(); }
    } return result;
  }
  async nativePrints(root, prepared, signal) {
    const folder = path.join(root, 'print-effects'), entries = []; let directory;
    try { await this.directory(folder); directory = await fs.opendir(folder); } catch (cause) { if (cause.code === 'ENOENT') return { count: 0, proof: [] }; try { await fs.lstat(folder); } catch (error) { if (error.code === 'ENOENT') return { count: 0, proof: [] }; } throw fail('PRINT_FILES_UNVERIFIED'); }
    let uncertain = 0, bytes = 0;
    try {
      for await (const item of directory) {
        this.check(signal); need(entries.length < 10000 && item.isFile() && !item.isSymbolicLink() && /^[a-f0-9]{64}\.enc$/.test(item.name), 'PRINT_FILES_UNVERIFIED');
        const file = await this.file(path.join(folder, item.name), 512 * 1024, { optional: false, signal }); bytes += file.raw.length; need(bytes <= 16 * 1024 ** 2, 'PRINT_FILES_CAPACITY');
        let record; try { record = JSON.parse(this.safeStorage.decryptString(file.raw)); } catch { throw fail('PRINT_FILES_UNVERIFIED'); }
        need(record && typeof record === 'object' && !Array.isArray(record), 'PRINT_FILES_UNVERIFIED');
        const identity = record.identity, id = item.name.slice(0, 64);
        const required = ['format','identity','invocation_id','native_session_id','claim_token','phase','delivered'];
        need(required.every(key => Object.hasOwn(record, key)) && Object.keys(record).every(key => required.includes(key) || ['permit_hash','receipt'].includes(key))
          && exact(identity, ['effect_id','enrollment_id','origin_command_id','device_id','kind','actor_id','branch','revision','snapshot_sha256','render_sha256']), 'PRINT_FILES_UNVERIFIED');
        need(record.format === 1 && identity?.effect_id === id && identity.device_id === prepared.deviceId && identity.actor_id === prepared.actorId
          && hash(identity.enrollment_id) && hash(identity.snapshot_sha256) && hash(identity.render_sha256) && uuid(identity.origin_command_id)
          && ['sale_receipt','phone_kitchen','shift_report','dining_kitchen','bill_receipt','delivery_batch'].includes(identity.kind)
          && /^(?:f|gs):[1-9][0-9]{0,18}$/.test(identity.branch || '') && Number.isSafeInteger(identity.revision) && identity.revision > 0
          && uuid(record.invocation_id) && uuid(record.native_session_id) && uuid(record.claim_token)
          && ['planned','burnt','outcome'].includes(record.phase) && typeof record.delivered === 'boolean', 'PRINT_FILES_UNVERIFIED');
        if (record.phase === 'burnt') need(hash(record.permit_hash), 'PRINT_FILES_UNVERIFIED');
        if (record.phase !== 'outcome') need(record.delivered === false && record.receipt === undefined, 'PRINT_FILES_UNVERIFIED');
        if (record.phase !== 'outcome' || record.delivered !== true) uncertain++;
        else {
          const receipt = record.receipt;
          need(exact(receipt, ['effect_id','actor_id','branch','claim_token','native_session_id','invocation_id','permit_hash','enrollment_id','origin_command_id','revision','state','reason']) && receipt.effect_id === id && receipt.actor_id === identity.actor_id && receipt.branch === identity.branch
            && receipt.enrollment_id === identity.enrollment_id && receipt.origin_command_id === identity.origin_command_id && receipt.revision === identity.revision
            && receipt.claim_token === record.claim_token && receipt.native_session_id === record.native_session_id && receipt.invocation_id === record.invocation_id
            && hash(receipt.permit_hash) && ['invoked','review'].includes(receipt.state) && typeof receipt.reason === 'string' && receipt.reason.length <= 200, 'PRINT_FILES_UNVERIFIED');
        }
        entries.push([item.name, file.sha256]);
      }
    } finally { await directory.close().catch(() => {}); }
    return { count: uncertain, proof: entries.sort(([a], [b]) => a.localeCompare(b)) };
  }
  async sourceManifest(root, prepared, signal) {
    need(prepared.format === 2 && /^[a-f0-9]{40}$/.test(prepared.sourceRevision || ''), 'PREPARED_VERSION_UNVERIFIED');
    const key = 'runtimes/' + prepared.sourceRevision, receipt = await this.encrypted(root, key, signal);
    need(receipt.value?.format === 1 && receipt.value.revision === prepared.sourceRevision && hash(receipt.value.files?.['manifest.json'])
      && source.valid(receipt.value.sourceFingerprint), 'SOURCE_UNVERIFIED');
    const bundle = path.join(root, key), file = await this.file(path.join(bundle, 'manifest.json'), 2 * 1024 ** 2, { optional: false, signal });
    need(file.sha256 === receipt.value.files['manifest.json'], 'SOURCE_UNVERIFIED'); const manifest = JSON.parse(file.raw);
    need(manifest.sourceRevision === prepared.sourceRevision && source.same(manifest.sourceFingerprint, prepared.sourceFingerprint)
      && source.same(receipt.value.sourceFingerprint, prepared.sourceFingerprint), 'SOURCE_UNVERIFIED');
    return { bundle, manifest, proof: receipt.proof };
  }
  async runtime(account, root, prepared, sourceContext, signal, work) {
    let runtime = await this.activeRuntime(account.id), owned = false;
    if (!runtime) { runtime = await this.createRuntime(account); owned = true; this.ownRuntime(runtime); }
    need(runtime && normalize(runtime.profile) === normalize(root), 'RUNTIME_FOREIGN');
    if (owned) {
      // Retain the EXACT original bundle; optional installer promotion is never
      // part of migration inventory. Native cache/DB recovery is not business work.
      runtime.bundle = sourceContext.bundle; runtime.installedBundle = sourceContext.bundle; runtime.authorizeInstalled = async () => false;
    }
    const cancel = () => { if (owned) runtime.cancelStartup?.(); }; signal?.addEventListener('abort', cancel, { once: true });
    try {
      this.check(signal); await runtime.start(); this.check(signal);
      need(runtime.manifest?.sourceRevision === prepared.sourceRevision && source.same(runtime.sourceFingerprint, prepared.sourceFingerprint), 'SOURCE_UNVERIFIED');
      const bundle = runtime.bundle, key = path.basename(path.dirname(bundle)) === 'compact-runtimes' ? 'compact-runtimes/' : 'runtimes/';
      need(normalize(path.dirname(bundle)) === normalize(path.join(root, key.slice(0, -1))), 'SOURCE_UNVERIFIED');
      const receipt = await this.encrypted(root, key + prepared.sourceRevision, signal);
      const archive = new RuntimeArchive(root, { read: async name => (await this.encrypted(root, name, signal)).value,
        write: async () => { throw fail('SOURCE_UNVERIFIED'); } }, { stopped: () => this.stopped() || signal?.aborted });
      await archive.verify(bundle, receipt.value);
      need(source.same(await archive.sourceFingerprint(bundle), prepared.sourceFingerprint), 'SOURCE_UNVERIFIED');
      if (work) return await work({ runtime, prepared: structuredClone(prepared), account,
        metadata: { read: async name => (await this.encrypted(root, name, signal)).value },
        readState: async () => this.checkedState(await this.dashboardState(runtime, prepared, signal), prepared),
        nativePrints: () => this.nativePrints(root, prepared, signal),
        // Reuse this exact verified native owner for the final all-evidence
        // comparison; never start a second MariaDB on the same profile.
        inventorySnapshot: async () => {
          let proof;
          const snapshot = await this.inspect(account, { signal,
            readState: async expected => { need(canonical(expected) === canonical(prepared), 'PREPARED_BINDING_INVALID'); return this.dashboardState(runtime, prepared, signal); },
            onProof: value => { proof = value; } });
          need(proof, 'DASHBOARD_UNVERIFIED'); return { snapshot, ...proof };
        } });
      const result = await this.dashboardState(runtime, prepared, signal); this.check(signal); return result;
    } finally { signal?.removeEventListener('abort', cancel); if (owned) await runtime.stop(); }
  }
  async dashboardState(runtime, prepared, signal) {
    return this.readDashboard ? await this.readDashboard(runtime, prepared, { signal }) : JSON.parse(await this.nativeRun(runtime.php,
      [...LocalRuntime.phpArguments(runtime), '-r', READ_ONLY_PHP], { env: runtime.environment, cwd: runtime.application, ...(signal ? { signal } : {}) },
      JSON.stringify(prepared), 90000, 65536));
  }
  async preparedFacts(actual, root, preparedFile, signal) {
    const credentials = await this.encrypted(root, 'credentials', signal), settings = credentials.value, prepared = preparedFile.value;
    need(exact(settings, ['deviceId','rootPassword','appPassword','appKey']) && uuid(settings.deviceId) && hash(settings.rootPassword) && hash(settings.appPassword)
      && typeof settings.appKey === 'string' && /^base64:[A-Za-z0-9+/]{43}=$/.test(settings.appKey), 'CREDENTIALS_UNVERIFIED');
    const sourceContext = await this.sourceManifest(root, prepared, signal);
    need(validPrepared(prepared, settings.deviceId, sourceContext.manifest) && prepared.workspaceProtocol === undefined
      && (actual.actorId === null && actual.serverOrigin === null || actual.actorId === prepared.actorId && actual.serverOrigin === prepared.serverOrigin), 'PREPARED_BINDING_INVALID');
    await this.directory(path.join(root, 'database', prepared.database)); return { credentials, sourceContext };
  }
  checkedState(state, prepared) {
    need(state?.format === 1 && state.kind === 'native-legacy-migration-state' && state.device_id === prepared.deviceId && state.actor_id === prepared.actorId
      && state.snapshot_id === prepared.snapshotId && state.schema_hash === prepared.schemaHash && state.database === prepared.database
      && typeof state.held === 'boolean' && count(state.sequence) && exact(state.journal, ['pending','conflicts','acknowledged']) && Object.values(state.journal).every(count)
      && exact(state.prints, ['prepared','uncertain','unacknowledged','orphans']) && Object.values(state.prints).every(count) && hash(state.identity_sha256), 'DASHBOARD_UNVERIFIED');
    return state;
  }
  /** Private lease for the original migration drain/fence owner, never renderer IPC. */
  async withRuntime(account, { signal } = {}, work) {
    this.check(signal); need(typeof work === 'function', 'CONFIGURATION_INVALID'); const actual = await this.profiles.account(account.id);
    need(account.profile === actual.profile && account.actorId === actual.actorId && account.serverOrigin === actual.serverOrigin, 'PROFILE_FOREIGN');
    const root = path.join(actual.profile, 'dashboard'); await this.directory(root);
    const preparedFile = await this.encrypted(root, 'prepared', signal); need(preparedFile.value, 'NOT_PREPARED');
    const { sourceContext } = await this.preparedFacts(actual, root, preparedFile, signal);
    return this.runtime(actual, root, preparedFile.value, sourceContext, signal, work);
  }
  inspectProfile(account, options = {}) {
    if (this.running.has(account.id)) return this.running.get(account.id);
    const task = this.inspect(account, options).finally(() => this.running.delete(account.id)); this.running.set(account.id, task); return task;
  }
  async inspect(account, { signal, readState, onProof } = {}) {
    this.check(signal); const actual = await this.profiles.account(account.id);
    need(account.profile === actual.profile && account.actorId === actual.actorId && account.serverOrigin === actual.serverOrigin, 'PROFILE_FOREIGN');
    await this.directory(actual.profile); const legacy = await this.sqlite(actual, signal), root = path.join(actual.profile, 'dashboard');
    const counts = { ...Object.fromEntries(COUNTS.map(key => [key, 0])), ...legacy.counts }, holds = Object.fromEntries(HOLDS.map(key => [key, false]));
    let rootExists = true; try { await this.directory(root); } catch { try { await fs.lstat(root); } catch (cause) { if (cause.code === 'ENOENT') rootExists = false; } if (rootExists) throw fail('DIRECTORY_UNSAFE'); }
    if (!rootExists) return { format: 1, profileId: actual.id, prepared: false, identitySha256: digest({ account: actual, legacy: legacy.proof, dashboard: null }), counts, holds };
    const preparedFile = await this.encrypted(root, 'prepared', signal), metadataProof = {};
    for (const [name, field] of [['refresh', 'refresh'], ['return', 'return'], ['delta-background', 'delta'], ['central-mode', 'central']]) {
      const value = await this.encrypted(root, name, signal); holds[field] = value.value !== null; metadataProof[name] = digest(value.value);
    }
    const remote = await this.encrypted(root, 'remote-writes', signal); metadataProof.remote = digest(remote.value);
    if (remote.value !== null) {
      need(exact(remote.value, ['format','dirty','pending']) && remote.value.format === 1 && typeof remote.value.dirty === 'boolean'
        && Array.isArray(remote.value.pending) && remote.value.pending.length <= 10000 && remote.value.pending.every(uuid), 'REMOTE_UNVERIFIED');
      holds.remoteDirty = remote.value.dirty; counts.remotePending = remote.value.pending.length;
    }
    if (remote.value === null) {
      for (const name of ['remote-attempts', 'remote-central']) {
        let entries; try { await this.directory(path.join(root, name)); entries = await fs.readdir(path.join(root, name)); }
        catch (cause) { try { await fs.lstat(path.join(root, name)); } catch (missing) { if (missing.code === 'ENOENT') continue; } throw fail('REMOTE_UNVERIFIED'); }
        need(entries.length === 0, 'REMOTE_UNVERIFIED');
      }
    }
    const prepared = preparedFile.value;
    if (prepared === null) {
      for (const name of ['database', 'generations', 'print-effects']) {
        try { await fs.lstat(path.join(root, name)); throw fail('UNBOUND_DATA_PRESENT'); } catch (cause) { if (cause.code !== 'ENOENT') throw cause; }
      }
      return { format: 1, profileId: actual.id, prepared: false, identitySha256: digest({ account: actual, legacy: legacy.proof, metadataProof }), counts, holds };
    }
    const { credentials, sourceContext } = await this.preparedFacts(actual, root, preparedFile, signal);
    const prints = await this.nativePrints(root, prepared, signal), state = this.checkedState(await (readState ? readState(prepared) : this.runtime(actual, root, prepared, sourceContext, signal)), prepared);
    counts.pending = state.journal.pending; counts.conflicts = state.journal.conflicts;
    counts.printEffects = prints.count + Object.values(state.prints).reduce((sum, current) => sum + current, 0); need(count(counts.printEffects), 'DASHBOARD_UNVERIFIED');
    holds.refresh ||= state.held;
    for (const name of ['refresh', 'return', 'delta-background', 'central-mode']) {
      need(digest((await this.encrypted(root, name, signal)).value) === metadataProof[name], 'STATE_CHANGED');
    }
    need(digest((await this.encrypted(root, 'remote-writes', signal)).value) === metadataProof.remote, 'STATE_CHANGED');
    need(canonical((await this.nativePrints(root, prepared, signal)).proof) === canonical(prints.proof), 'STATE_CHANGED');
    const afterPrepared = await this.encrypted(root, 'prepared', signal), afterCredentials = await this.encrypted(root, 'credentials', signal);
    need(preparedFile.proof === afterPrepared.proof && credentials.proof === afterCredentials.proof, 'STATE_CHANGED'); this.check(signal);
    const evidence = { account: actual, prepared: preparedFile.proof, credentials: credentials.proof,
      source: sourceContext.proof, legacy: legacy.proof, metadataProof, prints: prints.proof, dashboard: state };
    // The physical fence changes only held. All accepted journal, print, SQLite,
    // source and authenticated metadata evidence must stay exactly the same.
    onProof?.({ state: structuredClone(state), businessSha256: digest({ ...evidence, dashboard: { ...state, held: false } }) });
    return { format: 1, profileId: actual.id, prepared: true, identitySha256: digest(evidence), counts, holds };
  }
}
module.exports = { WorkspaceLegacyInspector, READ_ONLY_PHP };
