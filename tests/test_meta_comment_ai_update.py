"""Isolated rollout tests: no real database, provider, credentials or systemd writes."""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import types
import unittest
from unittest import mock

PACKAGE = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('comment_ai_updater', PACKAGE/'deployment/update_meta_comment_ai.py')
updater = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(updater)
WEB = "<?php\nrequire __DIR__.'/meta_inbox.php';\nrequire __DIR__.'/meta_comments.php';\n// live WhatsApp AI/custom routes\n"


class TransformTests(unittest.TestCase):
    def test_live_route_addition_is_narrow_and_idempotent(self):
        reviewed = updater.web_contents(WEB)
        self.assertTrue(reviewed.startswith(WEB))
        self.assertEqual(updater.web_contents(reviewed), reviewed)
        self.assertEqual(reviewed.count('meta_comment_ai'), 1)
        loop = "<?php\nforeach (['meta_inbox','meta_comments','meta_comment_ai','other_meta_ai'] as $metaRoutes) { require __DIR__.'/'.$metaRoutes.'.php'; }\n"
        self.assertEqual(updater.web_contents(loop), loop)

    def test_ambiguous_route_registration_is_refused(self):
        for value in (WEB+'// meta_comment_ai unknown operator block\n', updater.web_contents(WEB)*2, WEB+'?>'):
            with self.subTest(value=value), self.assertRaises(RuntimeError): updater.web_contents(value)

    def test_exact_old_baseline_wins_even_when_other_candidate_could_patch(self):
        older = b'old polish\n'+b'unchanged\n'*30+b'old AI\n'
        newer = older.replace(b'old polish',b'new polish')
        target = newer.replace(b'old AI',b'new AI')
        with mock.patch.object(updater,'BASES',('older','newer')), mock.patch.object(updater,'source',side_effect=[older,newer]):
            self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,'file',older,target),target)
        with mock.patch.object(updater,'BASES',('older','newer')), mock.patch.object(updater,'source',side_effect=[older,newer]):
            self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,'file',newer,target),target)

    def test_unknown_ambiguous_variant_refuses_to_choose_behavior(self):
        older=b'old polish\n'+b'unchanged\n'*15+b'operator slot\n'+b'unchanged\n'*15+b'old AI\n'+b'tail\n'*20
        newer=older.replace(b'old polish',b'new polish'); target=newer.replace(b'old AI',b'new AI')
        with mock.patch.object(updater,'BASES',('older','newer')), mock.patch.object(updater,'source',side_effect=[older,newer]):
            with self.assertRaisesRegex(RuntimeError,'SOURCE_PATCH_AMBIGUOUS'):
                updater.variant_patch(PACKAGE,'a'*40,'file',older.replace(b'operator slot',b'operator overlay'),target)

    def test_css_already_at_reviewed_newer_version_preserves_unrelated_overlay(self):
        old=b'.old{}\n'+b'body{}\n'*30
        target=old.replace(b'.old{}',b'.new{}')
        live=target+b'/* operator color overlay */\n'
        with mock.patch.object(updater,'BASES',('older','newer')), mock.patch.object(updater,'source',side_effect=[old,target]):
            self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,'style.css',live,target),live)

    def test_actual_comment_baselines_patch_and_rerun(self):
        for relative in updater.EXISTING_FILES:
            target=(PACKAGE/relative).read_bytes()
            for revision in updater.BASES:
                baseline=subprocess.check_output(['git','show',revision+':'+relative],cwd=PACKAGE)
                def source(root,selected,path): return subprocess.check_output(['git','show',selected+':'+path],cwd=PACKAGE)
                with self.subTest(relative=relative,revision=revision), mock.patch.object(updater,'source',side_effect=source):
                    self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,relative,baseline,target),target)
                    self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,relative,target,target),target)

    def test_deployed_comment_ui_upgrade_preserves_unrelated_operator_overlay(self):
        relative='public/js/dashboard-meta-comments.js'
        revision='86e5271d76030e53da33ee680d99d9a09a742b54'
        self.assertIn(revision,updater.BASES)
        baseline=subprocess.check_output(['git','show',revision+':'+relative],cwd=PACKAGE)
        before=b'},30000);'
        self.assertEqual(baseline.count(before),1)
        target=baseline.replace(before,b'},5000);')
        anchor=b'    var active = null, draftStores = new Map(), sendStores = new Map();\n'
        self.assertEqual(baseline.count(anchor),1)
        overlay=anchor+b'    // Operator observation hook outside the polling change.\n'
        current=baseline.replace(anchor,overlay)
        expected=target.replace(anchor,overlay)
        def source(root,selected,path):
            return subprocess.check_output(['git','show',selected+':'+path],cwd=PACKAGE)
        with mock.patch.object(updater,'source',side_effect=source):
            self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,relative,current,target),expected)
            self.assertEqual(updater.variant_patch(PACKAGE,'a'*40,relative,expected,target),expected)

    def test_deployed_comment_ui_conflicting_operator_poll_change_is_refused(self):
        relative='public/js/dashboard-meta-comments.js'
        revision='86e5271d76030e53da33ee680d99d9a09a742b54'
        baseline=subprocess.check_output(['git','show',revision+':'+relative],cwd=PACKAGE)
        before=b'},30000);'
        self.assertEqual(baseline.count(before),1)
        target=baseline.replace(before,b'},5000);')
        current=baseline.replace(before,b'},15000);')
        def source(root,selected,path):
            return subprocess.check_output(['git','show',selected+':'+path],cwd=PACKAGE)
        with mock.patch.object(updater,'source',side_effect=source):
            with self.assertRaisesRegex(RuntimeError,'SOURCE_PATCH_CONFLICT: '+relative):
                updater.variant_patch(PACKAGE,'a'*40,relative,current,target)


class SchemaGuardTests(unittest.TestCase):
    def test_upgrade_migration_preserves_enabled_policy_cutoff_and_send_guards(self):
        local=PACKAGE.parent/'php-runtime/php'
        php=os.environ.get('META_COMMENT_AI_TEST_PHP') or (str(local) if local.exists() else shutil.which('php'))
        vendor=os.environ.get('WA_INBOX_TEST_VENDOR') or str(PACKAGE.parent/'php-runtime/vendor')
        autoload=pathlib.Path(vendor)/'autoload.php' if pathlib.Path(vendor).is_dir() else pathlib.Path(vendor)
        if not php or not autoload.is_file(): self.skipTest('Isolated PHP database dependencies required')
        script=r'''
require $argv[1];
function now($zone=null){return \Carbon\Carbon::now($zone);}
$container=new \Illuminate\Container\Container();
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$capsule=new \Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$capsule->setAsGlobal();$capsule->bootEloquent();
$container->instance('db',$capsule->getDatabaseManager());
$container->bind('db.schema',fn()=>$capsule->schema());
require $argv[2];require $argv[3];
(new CreateMetaCommentReplyRequests())->up();$migration=new CreateMetaCommentAiTables();$migration->up();
$db=$capsule->getConnection();$stamp='2026-10-14 22:53:08';
$db->table('meta_comment_ai_settings')->where('id',1)->update([
 'enabled'=>true,'enabled_at'=>$stamp,'revision'=>1,'actor_id'=>17,
 'mode'=>'scheduled','days'=>'[1,3,5]','start'=>'09:00','end'=>'00:30',
 'worker_seen_at'=>'2026-10-14 23:08:13','last_reason'=>'OFF_WINDOW'
]);
$db->table('meta_comment_ai_targets')->insert([
 'account_key'=>str_repeat('a',64),'target_hash'=>str_repeat('b',64),'post_key'=>str_repeat('c',64),
 'state'=>'UNKNOWN','source'=>'ai','request_id'=>'f2ef0d23-2d4e-4a6c-8fb9-2c13e463a331',
 'revision'=>1,'response_id'=>'opaque encrypted receipt'
]);
$db->table('meta_comment_reply_requests')->insert([
 'request_hash'=>str_repeat('d',64),'pending_key'=>str_repeat('e',64),
 'actor_id'=>17,'account_key'=>str_repeat('a',64),'target_hash'=>str_repeat('b',64),
 'state'=>'UNKNOWN','payload_hash'=>str_repeat('f',64),'send_started_at'=>$stamp,
 'response_details'=>'opaque encrypted provider receipt'
]);
$db->table('meta_comment_ai_poll_states')->insert(['account_key'=>str_repeat('a',64),'state'=>'opaque encrypted cursor']);
$tables=['meta_comment_ai_settings','meta_comment_ai_targets','meta_comment_reply_requests','meta_comment_ai_poll_states'];
$before=[];foreach($tables as $table)$before[$table]=$db->table($table)->get()->toJson();
$migration->up();$migration->up();$migration->down();
foreach($tables as $table){if($before[$table]!==$db->table($table)->get()->toJson())throw new RuntimeException('Upgrade changed policy or receipt');}
echo "ACTIVE_POLICY_AND_RECEIPTS_PRESERVED\n";
'''
        result=subprocess.run([php,'-r',script,'--',str(autoload),
            str(PACKAGE/'database/migrations/2026_10_14_210000_create_meta_comment_reply_requests.php'),
            str(PACKAGE/updater.MIGRATION)],text=True,capture_output=True)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertIn('ACTIVE_POLICY_AND_RECEIPTS_PRESERVED',result.stdout)

    def test_read_only_sqlite_and_mysql_unique_guard_metadata(self):
        local=PACKAGE.parent/'php-runtime/php'
        php=os.environ.get('META_COMMENT_AI_TEST_PHP') or (str(local) if local.exists() else shutil.which('php'))
        if not php: self.skipTest('PHP with PDO SQLite is required for the isolated schema fixture')
        script=r'''<?php
$source=file_get_contents($argv[1]);
$begin='// BEGIN COMMENT AI UNIQUE INDEX CHECK';$end='// END COMMENT AI UNIQUE INDEX CHECK';
$code=substr($source,strpos($source,$begin)+strlen($begin));$code=substr($code,0,strpos($code,$end));eval($code);
$checks=0;function check($ok){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException('Schema guard fixture failed');}
class SqliteFixture {
 public $pdo;public $reads=[];function __construct(){$this->pdo=new PDO('sqlite::memory:');}
 function getDriverName(){return 'sqlite';}function getTablePrefix(){return '';}
 function select($sql){$this->reads[]=$sql;check(str_starts_with($sql,'PRAGMA '));return $this->pdo->query($sql)->fetchAll(PDO::FETCH_OBJ);}
}
$db=new SqliteFixture();
$db->pdo->exec('CREATE TABLE meta_comment_ai_settings (id INTEGER PRIMARY KEY, enabled INTEGER)');
$db->pdo->exec('CREATE TABLE meta_comment_ai_targets (target_hash TEXT UNIQUE, state TEXT)');
$db->pdo->exec('CREATE TABLE meta_comment_ai_poll_states (account_key TEXT UNIQUE)');
$db->pdo->exec('CREATE TABLE meta_comment_reply_requests (request_hash TEXT UNIQUE, pending_key TEXT UNIQUE)');
foreach(['meta_comment_ai_settings','meta_comment_ai_targets','meta_comment_ai_poll_states','meta_comment_reply_requests'] as $table)check(metaCommentAiUniqueIndexes($db,$table));
$reads=count($db->reads);check(!metaCommentAiUniqueIndexes($db,'unrelated_credentials'));check(count($db->reads)===$reads);
$db->pdo->exec('DROP TABLE meta_comment_ai_targets');$db->pdo->exec('CREATE TABLE meta_comment_ai_targets (target_hash TEXT, state TEXT, UNIQUE(target_hash,state))');
check(!metaCommentAiUniqueIndexes($db,'meta_comment_ai_targets'));
$db->pdo->exec('CREATE UNIQUE INDEX partial_guard ON meta_comment_ai_targets(target_hash) WHERE state="SENDING"');
check(!metaCommentAiUniqueIndexes($db,'meta_comment_ai_targets'));
$db->pdo->exec('DROP TABLE meta_comment_ai_settings');$db->pdo->exec('CREATE TABLE meta_comment_ai_settings (id INTEGER UNIQUE)');
check(!metaCommentAiUniqueIndexes($db,'meta_comment_ai_settings'));
class MysqlFixture {
 public $rows=[];public $reads=[];function getDriverName(){return 'mysql';}function getTablePrefix(){return 'prefix_';}
 function select($sql){$this->reads[]=$sql;check(str_starts_with($sql,'SHOW INDEX FROM `prefix_meta_comment_'));return $this->rows;}
}
function indexRow($name,$column,$position=1,$prefix=null,$nonUnique=0){return (object)['Key_name'=>$name,'Column_name'=>$column,'Seq_in_index'=>$position,'Sub_part'=>$prefix,'Non_unique'=>$nonUnique];}
$mysql=new MysqlFixture();$mysql->rows=[indexRow('target_unique','target_hash')];check(metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_targets'));
$mysql->rows=[indexRow('prefix_unique','target_hash',1,12)];check(!metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_targets'));
$mysql->rows=[indexRow('compound','target_hash'),indexRow('compound','state',2,1)];check(!metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_targets'));
$mysql->rows=[indexRow('compound','target_hash'),indexRow('compound',null,2)];check(!metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_targets'));
$mysql->rows=[indexRow('not_unique','target_hash',1,null,1)];check(!metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_targets'));
$mysql->rows=[indexRow('id_unique','id')];check(!metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_settings'));
$mysql->rows=[indexRow('PRIMARY','id')];check(metaCommentAiUniqueIndexes($mysql,'meta_comment_ai_settings'));
$mysql->rows=[indexRow('request_unique','request_hash')];check(!metaCommentAiUniqueIndexes($mysql,'meta_comment_reply_requests'));
$mysql->rows[] = indexRow('pending_unique','pending_key');check(metaCommentAiUniqueIndexes($mysql,'meta_comment_reply_requests'));
echo 'READ_ONLY_SCHEMA_GUARDS_OK '.$checks."\n";
'''
        result=subprocess.run([php,'-r',script.removeprefix('<?php\n'),'--',str(PACKAGE/updater.DIAGNOSTIC)],text=True,capture_output=True)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertIn('READ_ONLY_SCHEMA_GUARDS_OK',result.stdout)


class WrapperTests(unittest.TestCase):
    def parse(self,*flags):
        body=(PACKAGE/'deployment/apply_meta_comment_ai_root.sh').read_text()
        parser=body.split('\nshift\n',1)[1].split('\ncomment_ai_tmp=',1)[0]
        return subprocess.run(['bash','-s','--',*flags],input=parser+'\nprintf "%s\\n" "${comment_ai_flags[@]}"\n',text=True,capture_output=True)

    def test_all_reviewed_flags_forward_in_any_order(self):
        for flags in ((),('--check',),('--check','--install-timer','--repair-permissions'),('--repair-permissions','--check','--install-timer')):
            with self.subTest(flags=flags):
                result=self.parse(*flags); self.assertEqual(result.returncode,0)
                self.assertEqual(result.stdout.strip().splitlines(),list(flags))

    def test_unknown_and_duplicate_flags_are_refused(self):
        for flags in (('--unknown',),('--check','--check'),('--install-timer','--install-timer')):
            with self.subTest(flags=flags): self.assertNotEqual(self.parse(*flags).returncode,0)


class ProbeTests(unittest.TestCase):
    def test_safe_source_checkpoint_survives_probe_failure(self):
        detail={'phase':'verify','step':'view_compile','type':'ErrorException','file':'Filesystem.php','line':41}
        response=types.SimpleNamespace(returncode=1,stdout=json.dumps({'ok':False,'reason':'AI_READINESS_FAILED','diagnostic':detail}).encode())
        with mock.patch.object(updater.subprocess,'run',return_value=response):
            with self.assertRaisesRegex(RuntimeError,r'AI_READINESS_FAILED: verify/view_compile/ErrorException/Filesystem.php:41'):
                updater.probe(PACKAGE,'php','diagnostic','verify',PACKAGE,'views')

    def test_private_or_untrusted_diagnostic_fields_are_not_reflected(self):
        detail={'phase':'verify','step':'private token text','type':'ErrorException','file':'/private/key.php','line':41}
        message=updater.readiness_failure({'reason':'PRIVATE_TOKEN','diagnostic':detail},'verify')
        self.assertEqual(message,'AI_READINESS_FAILED: verify')

    def test_invalid_stdout_is_distinct_and_never_echoed(self):
        response=types.SimpleNamespace(returncode=1,stdout=b'private provider token')
        with mock.patch.object(updater.subprocess,'run',return_value=response):
            with self.assertRaisesRegex(RuntimeError,r'^AI_READINESS_OUTPUT_INVALID: post_migration/exit=1$'):
                updater.probe(PACKAGE,'php','diagnostic','post_migration',PACKAGE,'views')

    def test_private_result_file_ignores_framework_output_and_is_removed(self):
        seen=[]
        body={'ok':True,'tables':{'meta_comment_ai_settings':True},'tables_ready':True,'configured':True,
              'enabled':False,'mode':'always','verified_routes':2,'view_caches':[],'routes_cached':False}
        def run(arguments,**kwargs):
            path=pathlib.Path(arguments[-1]); seen.append(path)
            self.assertEqual(path.stat().st_mode&0o777,0o600)
            path.write_text(json.dumps(body))
            return types.SimpleNamespace(returncode=0,stdout=b'framework private output',stderr=b'private details')
        with mock.patch.object(updater.subprocess,'run',side_effect=run):
            self.assertEqual(updater.probe(PACKAGE,'php','diagnostic','verify',PACKAGE,'views'),body)
        self.assertFalse(seen[0].exists())

    def test_native_fatal_uses_private_checkpoint_without_reflecting_process_output(self):
        detail={'phase':'verify','step':'bootstrap','type':'NativeFatal','file':'Legacy.php','line':15,'fatal_kind':'CLASS_REDECLARATION'}
        def run(arguments,**kwargs):
            pathlib.Path(arguments[-1]).write_text(json.dumps({'ok':False,'reason':'AI_READINESS_FATAL','diagnostic':detail}))
            return types.SimpleNamespace(returncode=255,stdout=b'private fatal message',stderr=b'private path')
        with mock.patch.object(updater.subprocess,'run',side_effect=run):
            with self.assertRaisesRegex(RuntimeError,r'^AI_READINESS_FATAL: verify/bootstrap/NativeFatal/Legacy.php:15/CLASS_REDECLARATION$'):
                updater.probe(PACKAGE,'php','diagnostic','verify',PACKAGE,'views')

    def test_result_file_is_removed_after_process_timeout(self):
        seen=[]
        def run(arguments,**kwargs):
            seen.append(pathlib.Path(arguments[-1]))
            raise subprocess.TimeoutExpired('fixture',60)
        with mock.patch.object(updater.subprocess,'run',side_effect=run):
            with self.assertRaises(subprocess.TimeoutExpired):
                updater.probe(PACKAGE,'php','diagnostic','verify',PACKAGE,'views')
        self.assertFalse(seen[0].exists())

    def test_application_readability_reports_only_reviewed_relative_path(self):
        relative='resources/views/admin/meta/comments.blade.php'
        with mock.patch.object(updater,'owned_command',return_value=json.dumps([str(PACKAGE/relative)]).encode()):
            with self.assertRaisesRegex(RuntimeError,r'^APPLICATION_FILE_NOT_READABLE: resources/views/admin/meta/comments.blade.php$'):
                updater.readable_sources(PACKAGE,'php',[relative])
        with mock.patch.object(updater,'owned_command',return_value=b'["/private/secret"]'):
            with self.assertRaisesRegex(RuntimeError,r'^APPLICATION_READABILITY_CHECK_FAILED$'):
                updater.readable_sources(PACKAGE,'php',[relative])


class InstallTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(); self.root=pathlib.Path(self.temp.name)/'public_html'; self.root.mkdir()
        self.unit_root=pathlib.Path(self.temp.name)/'units'; self.unit_root.mkdir()
        self.release='a'*40; self.before={}; self.payloads={}; self.commands=[]; self.system=[]; self.migrated=False; self.fail=None
        self.policy=None
        for relative in updater.EXISTING_FILES:
            self.before[relative]=b'header\nold behavior\nfooter\n'
            self.payloads[relative]=b'header\nnew behavior\nfooter\n'
        self.before[updater.WEB_ROUTES]=WEB.encode(); self.payloads[updater.WEB_ROUTES]=updater.web_contents(WEB).encode()
        for relative in updater.NEW_FILES: self.payloads[relative]=('new reviewed source '+relative+'\n').encode()
        self.ai_baseline={relative:('previous reviewed source '+relative+'\n').encode() for relative in updater.NEW_FILES}
        for relative in updater.UNIT_INPUTS: self.payloads[relative]=(PACKAGE/relative).read_bytes()
        for relative,data in self.before.items(): self.write(relative,data)
        for relative in updater.NEW_FILES: (self.root/relative).parent.mkdir(parents=True,exist_ok=True)
        self.cache_relative='storage/framework/views/ab1234.php'; self.write(self.cache_relative,b'original comment view')
        self.write('storage/framework/views/cc2222.php',b'unrelated inbox view')
        self.write('.env',b'PRIVATE_CREDENTIALS=untouched')
        self.write('app/Console/Kernel.php',b'current WhatsApp and Meta AI scheduler overlays')
        self.manifest=json.dumps({'version':1,'files':{p:updater.blob(v) for p,v in self.payloads.items()}}).encode()
        self.prior={name:{'enabled':False,'active':False} for name in updater.UNIT_NAMES}
        self.unit_state=json.loads(json.dumps(self.prior))

    def tearDown(self): self.temp.cleanup()

    def write(self,relative,data):
        path=self.root/relative; path.parent.mkdir(parents=True,exist_ok=True); path.write_bytes(data); path.chmod(0o644)

    def source(self,root,release,relative):
        if relative==updater.MANIFEST: return self.manifest
        if release==updater.AI_FILE_BASE: return self.ai_baseline[relative]
        return self.payloads[relative] if release==self.release else self.before[relative]

    def owned(self,root,args):
        self.commands.append(args)
        if 'migrate' in args:
            self.assertEqual(args[1:],['artisan','migrate','--path='+updater.MIGRATION,'--force','--no-interaction'])
            self.migrated=True
            if self.fail=='migration': raise RuntimeError('TEST_MIGRATION_FAILURE')
        return b''

    def probe(self,root,php,script,phase,stage,views):
        if phase=='verify' and self.fail=='verify': raise RuntimeError('TEST_VERIFY_FAILURE')
        ready=self.migrated or self.policy is not None
        return {'ok':True,'tables_ready':ready,'tables':{'meta_comment_ai_settings':ready},
            'configured':True,'policy_present':ready,'enabled':self.policy['enabled'] if self.policy else (False if ready else None),
            'mode':self.policy['mode'] if self.policy else ('always' if ready else None),'worker_seen':False,'routes_cached':False,
            'config_cached':True,'verified_routes':2 if phase=='verify' else 0,'views_compiled':1,
            'view_caches':[self.cache_relative]}

    def system_command(self,args):
        self.system.append(args)
        if args[:2]==['systemctl','start'] and self.fail=='timer': raise RuntimeError('TEST_TIMER_FAILURE')
        if args[:2]==['systemctl','enable']: self.unit_state[args[2]]['enabled']=True
        if args[:2]==['systemctl','start']: self.unit_state[args[2]]['active']=True
        if args[:2]==['systemctl','disable']: self.unit_state[args[2]]['enabled']=False
        if args[:2]==['systemctl','stop']: self.unit_state[args[2]]['active']=False
        return ''

    def execute(self,check=False,timer=False,repair=False):
        output=io.StringIO()
        with mock.patch.object(updater,'source',side_effect=self.source), mock.patch.object(updater,'owned_command',side_effect=self.owned), \
             mock.patch.object(updater,'readable_sources'), \
             mock.patch.object(updater,'probe',side_effect=self.probe), mock.patch.object(updater,'UNIT_ROOT',self.unit_root), \
             mock.patch.object(updater,'timer_state',side_effect=lambda:json.loads(json.dumps(self.unit_state))), mock.patch.object(updater,'system_command',side_effect=self.system_command), \
             contextlib.redirect_stdout(output):
            try: return updater.install_locked(self.root,self.release,self.manifest,'php-fixture',check,os.getuid(),os.getgid(),repair,timer)
            finally: self.output=output.getvalue()

    def test_read_only_preflight_creates_no_sources_backup_migration_or_timer_changes(self):
        before={p:(self.root/p).stat() for p in self.before}; self.execute(check=True,timer=True)
        for relative,old in before.items():
            path=self.root/relative; now=path.stat(); self.assertEqual(path.read_bytes(),self.before[relative])
            self.assertEqual((old.st_ino,old.st_mode,old.st_mtime_ns),(now.st_ino,now.st_mode,now.st_mtime_ns))
        self.assertFalse(self.migrated); self.assertFalse((self.root.parent/'meta-comment-ai-backups').exists())
        self.assertEqual(list(self.unit_root.iterdir()),[])
        self.assertTrue(all(args[0]=='systemd-analyze' for args in self.system))

    def test_success_is_pinned_targeted_off_default_and_preserves_other_inbox_work(self):
        result=self.execute(timer=True)
        self.assertEqual(result['status'],'META_COMMENT_AI_READY'); self.assertFalse(result['readiness']['enabled'])
        for relative,expected in self.payloads.items():
            if relative not in updater.UNIT_INPUTS: self.assertEqual((self.root/relative).read_bytes(),expected)
        self.assertEqual((self.root/'.env').read_bytes(),b'PRIVATE_CREDENTIALS=untouched')
        self.assertEqual((self.root/'app/Console/Kernel.php').read_bytes(),b'current WhatsApp and Meta AI scheduler overlays')
        self.assertEqual((self.root/'storage/framework/views/cc2222.php').read_bytes(),b'unrelated inbox view')
        self.assertFalse((self.root/self.cache_relative).exists())
        self.assertTrue(self.migrated)
        self.assertIn(['systemctl','enable',updater.UNIT_NAMES[1]],self.system)
        self.assertIn(['systemctl','start',updater.UNIT_NAMES[1]],self.system)
        self.assertFalse(any('--once' in args or 'schedule:run' in args or 'config:clear' in args for args in self.commands))
        backup=pathlib.Path(result['backup']); saved=json.loads((backup/'manifest.json').read_text())
        self.assertIn('meta_comment_ai_targets',saved['tables_retained'])
        self.assertEqual((backup/self.cache_relative).read_bytes(),b'original comment view')

    def test_bad_manifest_pin_fails_before_publication(self):
        self.payloads[updater.NEW_FILES[0]]+=b'tamper'
        with self.assertRaisesRegex(RuntimeError,'SOURCE_MANIFEST_PIN_DIFFERS'): self.execute()
        self.assertFalse(self.migrated)

    def test_unknown_comment_overlay_refuses_before_backup_or_migration(self):
        path=updater.EXISTING_FILES[0]; self.write(path,b'header\noperator change\nfooter\n')
        with self.assertRaisesRegex(RuntimeError,'SOURCE_PATCH_CONFLICT'): self.execute()
        self.assertFalse(self.migrated); self.assertFalse((self.root.parent/'meta-comment-ai-backups').exists())

    def test_conflicting_new_file_or_systemd_unit_is_not_replaced(self):
        path=updater.NEW_FILES[0]; self.write(path,b'operator new source')
        with self.assertRaisesRegex(RuntimeError,'NEW_FILE_COLLISION'): self.execute()
        (self.root/path).unlink(); (self.unit_root/updater.UNIT_NAMES[0]).write_bytes(b'operator unit')
        with self.assertRaisesRegex(RuntimeError,'UNREVIEWED_TIMER_UNIT'): self.execute(timer=True)
        self.assertFalse(self.migrated)

    def test_exact_reviewed_ai_files_upgrade_and_back_up_previous_bytes(self):
        for relative,data in self.ai_baseline.items(): self.write(relative,data)
        result=self.execute()
        self.assertEqual(result['status'],'META_COMMENT_AI_READY')
        backup=pathlib.Path(result['backup'])
        for relative,previous in self.ai_baseline.items():
            self.assertEqual((self.root/relative).read_bytes(),self.payloads[relative])
            self.assertEqual((backup/relative).read_bytes(),previous)

    def test_reviewed_ai_upgrade_is_read_only_during_preflight(self):
        for relative,data in self.ai_baseline.items(): self.write(relative,data)
        self.policy={'enabled':True,'mode':'always','revision':1,'enabled_at':'2026-10-14T22:53:08+00:00'}
        files={path:(path.read_bytes(),path.stat().st_ino,path.stat().st_mode,path.stat().st_mtime_ns)
               for path in self.root.rglob('*') if path.is_file()}
        result=self.execute(check=True,timer=True)
        self.assertTrue(result['readiness']['enabled'])
        for path,before in files.items():
            self.assertEqual((path.read_bytes(),path.stat().st_ino,path.stat().st_mode,path.stat().st_mtime_ns),before)
        self.assertFalse(self.migrated)
        self.assertFalse((self.root.parent/'meta-comment-ai-backups').exists())
        self.assertEqual(list(self.unit_root.iterdir()),[])
        self.assertTrue(all(args[0]=='systemd-analyze' for args in self.system))

    def test_reviewed_ai_upgrade_is_idempotent_and_preserves_enabled_policy(self):
        for relative,data in self.ai_baseline.items(): self.write(relative,data)
        self.policy={'enabled':True,'mode':'always','revision':1,'enabled_at':'2026-10-14T22:53:08+00:00'}
        before=self.policy.copy()
        first=self.execute(timer=True)
        self.assertTrue(first['readiness']['enabled'])
        published={relative:(self.root/relative).stat().st_ino for relative in updater.SOURCE_FILES}
        second=self.execute(timer=True)
        self.assertEqual(second['updated_files'],0)
        self.assertTrue(second['readiness']['enabled'])
        self.assertEqual(self.policy,before)
        self.assertEqual(published,{relative:(self.root/relative).stat().st_ino for relative in updater.SOURCE_FILES})
        self.assertFalse(any('--once' in args or 'rollback' in args or 'schedule:run' in args for args in self.commands))

    def test_unknown_ai_overlay_refuses_upgrade_before_migration_or_backup(self):
        for relative,data in self.ai_baseline.items(): self.write(relative,data)
        relative='app/Services/Dashboard/MetaCommentAiWorker.php'
        altered=self.ai_baseline[relative]+b'operator polling overlay\n'; self.write(relative,altered)
        with self.assertRaisesRegex(RuntimeError,'NEW_FILE_COLLISION: '+relative): self.execute()
        self.assertEqual((self.root/relative).read_bytes(),altered)
        self.assertFalse(self.migrated)
        self.assertFalse((self.root.parent/'meta-comment-ai-backups').exists())

    def test_failed_ai_upgrade_restores_reviewed_old_files_without_policy_reset(self):
        for relative,data in self.ai_baseline.items(): self.write(relative,data)
        self.policy={'enabled':True,'mode':'always','revision':1,'enabled_at':'2026-10-14T22:53:08+00:00'}
        before=self.policy.copy(); self.fail='verify'
        with self.assertRaisesRegex(RuntimeError,'TEST_VERIFY_FAILURE'): self.execute()
        self.assertEqual(self.policy,before)
        for relative,data in self.ai_baseline.items(): self.assertEqual((self.root/relative).read_bytes(),data)
        self.assertIn('RESTORED',self.output)
        self.assertFalse(any('rollback' in args for args in self.commands))

    def test_migration_or_verification_failure_restores_sources_and_retains_data(self):
        for failure in ('migration','verify'):
            with self.subTest(failure=failure):
                self.fail=failure
                with self.assertRaises(RuntimeError): self.execute()
                self.assertTrue(self.migrated)
                for relative,old in self.before.items(): self.assertEqual((self.root/relative).read_bytes(),old)
                self.assertFalse((self.root/updater.NEW_FILES[0]).exists())
                self.assertEqual((self.root/self.cache_relative).read_bytes(),b'original comment view')
                self.assertFalse(any('rollback' in args or 'migrate:rollback' in args for args in self.commands))

    def test_timer_failure_rolls_back_only_dedicated_units_and_sources(self):
        self.fail='timer'
        with self.assertRaises(RuntimeError): self.execute(timer=True)
        self.assertEqual(list(self.unit_root.iterdir()),[])
        for relative,old in self.before.items(): self.assertEqual((self.root/relative).read_bytes(),old)
        self.assertTrue(self.migrated)
        self.assertIn(['systemctl','disable',updater.UNIT_NAMES[1]],self.system)

    def test_concurrent_edit_before_source_publication_is_preserved(self):
        relative=updater.EXISTING_FILES[0]; native=self.owned
        def mutate(root,args):
            result=native(root,args)
            if 'migrate' in args: self.write(relative,b'operator live edit during migration')
            return result
        with mock.patch.object(self,'owned',side_effect=mutate):
            with self.assertRaisesRegex(RuntimeError,'CONCURRENT_EDIT'): self.execute()
        self.assertEqual((self.root/relative).read_bytes(),b'operator live edit during migration')
        for path,before in self.before.items():
            if path!=relative: self.assertEqual((self.root/path).read_bytes(),before)
        self.assertFalse((self.root/updater.NEW_FILES[0]).exists())

    def test_concurrent_edit_during_failure_is_skipped_and_other_leaves_restore(self):
        relative=updater.EXISTING_FILES[0]; native=self.probe
        def mutate(root,php,script,phase,stage,views):
            if phase=='verify':
                self.write(relative,b'operator edit after publication')
                raise RuntimeError('TEST_CONCURRENT_VERIFY_FAILURE')
            return native(root,php,script,phase,stage,views)
        with mock.patch.object(self,'probe',side_effect=mutate):
            with self.assertRaisesRegex(RuntimeError,'TEST_CONCURRENT_VERIFY_FAILURE'): self.execute()
        self.assertEqual((self.root/relative).read_bytes(),b'operator edit after publication')
        for path,before in self.before.items():
            if path!=relative: self.assertEqual((self.root/path).read_bytes(),before)
        self.assertIn('NEEDS_REVIEW',self.output)
        self.assertEqual((self.root/self.cache_relative).read_bytes(),b'original comment view')
        self.assertTrue(self.migrated)

    def test_immediate_post_write_operator_bytes_are_not_recorded_as_our_publication(self):
        relative=updater.NEW_FILES[0]; native=updater.atomic
        def publish(path,data,mode,uid,gid):
            native(path,data,mode,uid,gid)
            if path==self.root/relative: path.write_bytes(b'operator replaced just-published source')
        with mock.patch.object(updater,'atomic',side_effect=publish):
            with self.assertRaisesRegex(RuntimeError,'CONCURRENT_EDIT'): self.execute()
        self.assertEqual((self.root/relative).read_bytes(),b'operator replaced just-published source')
        self.assertIn('NEEDS_REVIEW',self.output)
        self.assertFalse(self.migrated)

    def test_immediate_post_write_operator_deletion_is_not_recreated_by_rollback(self):
        relative=updater.EXISTING_FILES[0]; native=updater.atomic
        def publish(path,data,mode,uid,gid):
            native(path,data,mode,uid,gid)
            if path==self.root/relative: path.unlink()
        with mock.patch.object(updater,'atomic',side_effect=publish):
            with self.assertRaisesRegex(RuntimeError,'CONCURRENT_EDIT'): self.execute()
        self.assertFalse((self.root/relative).exists())
        self.assertIn('NEEDS_REVIEW',self.output)
        self.assertTrue(self.migrated)

    def test_permission_repair_is_explicit_and_check_never_changes_modes(self):
        for relative in self.before: (self.root/relative).chmod(0o775)
        with self.assertRaisesRegex(RuntimeError,'UNSAFE_FILE'): self.execute(check=True)
        self.execute(check=True,repair=True)
        for relative in self.before: self.assertEqual((self.root/relative).stat().st_mode&0o777,0o775)
        self.execute(repair=True)
        for relative in self.before: self.assertEqual((self.root/relative).stat().st_mode&0o777,0o755)

    def test_cached_routes_are_not_cleared_or_published(self):
        native=self.probe
        def cached(*args):
            result=native(*args); result['routes_cached']=True
            raise RuntimeError('CACHED_ROUTES_BLOCKED')
        with mock.patch.object(self,'probe',side_effect=cached):
            with self.assertRaisesRegex(RuntimeError,'CACHED_ROUTES_BLOCKED'): self.execute()
        self.assertFalse(self.migrated)

    def test_public_check_creates_no_lock(self):
        user=types.SimpleNamespace(pw_uid=self.root.stat().st_uid,pw_gid=self.root.stat().st_gid)
        with mock.patch.object(updater,'APP_ROOT',self.root), mock.patch.object(updater,'PHP','php-fixture'), \
             mock.patch.object(updater.pwd,'getpwnam',return_value=user), mock.patch.object(updater.os,'geteuid',return_value=0), \
             mock.patch.object(updater,'install_locked') as locked:
            updater.install(self.root,self.release,self.manifest,'php-fixture',check_only=True)
            locked.assert_called_once()
        self.assertFalse((self.root.parent/'.meta-comment-ai-update.lock').exists())


if __name__=='__main__': unittest.main()
