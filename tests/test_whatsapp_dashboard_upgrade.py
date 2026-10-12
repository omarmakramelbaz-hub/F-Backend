#!/usr/bin/env python3
"""Real-filesystem transactions and private Laravel/SQLite derived metadata regressions."""
import copy
import importlib.util
import json
import os
import pathlib
import shutil
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('dashboard_upgrade_under_test', ROOT / 'deployment/apply_whatsapp_dashboard_upgrade.py')
app = importlib.util.module_from_spec(spec); spec.loader.exec_module(app)


def schema_tables():
    result = {}
    for name, columns in app.TABLE_COLUMNS.items():
        result[name] = {'exists': True, 'columns': [dict(zip(('name','type','nullable','length','auto','unsigned'), (key,*value))) for key,value in columns.items()],
            'indexes': {str(n): {'unique': unique, 'columns': columns} for n,(unique,columns) in enumerate(app.TABLE_INDEXES[name])},
            'foreign_keys': {key: {'column': column,'target': target,'target_column':'id','delete':'RESTRICT'} for key,(column,target) in app.TABLE_FOREIGN[name].items()}}
    for metadata in result.values():
        for column in metadata['columns']:column['charset']='utf8mb4' if column['type'] in ('varchar','char') else None
    return result


class InstallFixture:
    def __init__(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-dashboard-test-')
        self.home = pathlib.Path(self.temp.name); self.root = self.home / 'public_html'; self.payload = self.home / 'payload'
        self.root.mkdir(); (self.root / '.git').mkdir(); self.payload.mkdir(); (self.payload / 'deployment').mkdir()
        self.uid = os.geteuid(); self.calls = []; self.fail = None; self.after = None; self.route = False
        self.tables = {key: {'exists':False,'columns':[],'indexes':{},'foreign_keys':{}} for key in app.TABLE_COLUMNS}
        self.migrations = [{'id':1,'migration':'original_application_migration','batch':1}]
        self.pins = {}; self.protected = {}; self.before = {}; self.aliases = {}
        for n,(relative,(old,_)) in enumerate(app.SOURCE_PINS.items()):
            if relative.endswith('.blade.php'): baseline=b'<p>before</p>'; target=b'<p>{{ "after" }}</p>'
            elif relative.endswith('.php'): baseline=b'<?php /* before */\n'; target=('<?php /* after '+str(n)+' */\n').encode()
            elif relative.endswith('.js'): baseline=b'var before = true;\n'; target=b'var after = true;\n'
            else: baseline=b'.before{color:#000}\n'; target=b'.after{color:#111}\n'
            if old is not None:
                self.write(self.root/relative,baseline); self.before[relative]=baseline
            else:self.before[relative]=None
            self.write(self.payload/relative,target)
            self.pins[relative]=(app.blob(baseline) if old is not None else None,app.blob(target))
        for relative in app.PROTECTED_PATHS:
            if relative in app.PROTECTED_PINS or relative in ('.env','bootstrap/cache/config.php'):
                data=('private fixture '+relative).encode(); self.write(self.root/relative,data)
                if relative in app.PROTECTED_PINS:self.protected[relative]=app.blob(data)
        self.manifest=self.payload/'deployment/whatsapp_dashboard_upgrade_manifest.json'
        self.write(self.manifest,(json.dumps({'version':1,'sources':[{'path':p,'before_blob':a,'after_blob':b} for p,(a,b) in self.pins.items()]})+'\n').encode())
        self.manifest_pin=app.blob(self.manifest.read_bytes())

    def write(self,path,data):path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data);path.chmod(0o644)
    def cleanup(self):self.temp.cleanup()
    def install(self):
        with patch.object(app,'SOURCE_PINS',self.pins),patch.object(app,'PROTECTED_PINS',self.protected):
            return app.apply(self.root,self.uid,self.manifest,self.manifest_pin,runner=self.runner)
    def ready(self):
        return {'config_fingerprint':'a'*64,'route_path':str(self.root/'bootstrap/cache/routes-v7.php'),
            'route_cached':(self.root/'bootstrap/cache/routes-v7.php').exists(),'autoload':{'app_psr4':True,'authoritative':False,'new_classes_mapped':False},'tables':copy.deepcopy(self.tables),'migrations':copy.deepcopy(self.migrations)}
    def runner(self,root,args,capture=False,timeout=90):
        self.calls.append(args)
        if args[1:3]==['-r',app.READINESS_PHP]:return 0,json.dumps(self.ready()).encode()
        if args[1:3]==['-r',app.COMPILE_PHP]:
            pathlib.Path(args[4]).write_bytes(b'<?php echo "compiled";\n')
            return 0,b'{"compiled_mtime":0}'
        if args[1:3]==['-r',app.BACKFILL_PHP]:
            if self.after:self.after(self)
            if self.fail=='backfill':return 1,b''
            return 0,b'{"processed":2,"valid":1,"invalid":1,"remaining":0,"complete":true}'
        if args[1:3]==['-r',app.POSTFLIGHT_PHP]:
            if self.fail=='postflight':return 1,b''
            return 0,b'DASHBOARD_ROUTE_CONTRACT_READY'
        if args[1:3]==['artisan','migrate']:
            if self.fail=='migration':return 1,b''
            self.tables=schema_tables();self.migrations.append({'id':2,'migration':pathlib.Path(app.MIGRATION).stem,'batch':2})
            if self.fail=='foreignmigration':self.migrations[0]['batch']=99
            return 0,b''
        if self.fail=='lint':return 1,b''
        return 0,b''


class PublisherTests(unittest.TestCase):
    def setUp(self):self.f=InstallFixture();self.addCleanup(self.f.cleanup)
    def unchanged(self):
        for path,data in self.f.before.items():
            self.assertEqual((self.f.root/path).read_bytes() if (self.f.root/path).exists() else None,data,path)
    def test_fixed_roster17_and_dependency_order(self):
        self.assertEqual(len(app.SOURCE_PINS),17)
        paths=list(app.SOURCE_PINS);self.assertLess(paths.index(app.MESSAGE),paths.index('routes/whatsapp_inbox.php'))
        self.assertLess(paths.index('public/js/dashboard-whatsapp-orders.js'),paths.index('resources/views/admin/whatsapp/index.blade.php'))
    def test_publish_preserves_config_footer_and_mode_metadata(self):
        protected={p:(app.source(self.f.root,p,self.f.uid,absent=True)) for p in app.PROTECTED_PATHS}
        status,backup,counts=self.f.install();self.assertEqual(status,'WHATSAPP_DASHBOARD_UPGRADE_READY')
        self.assertEqual(counts,{'processed':2,'valid':1,'invalid':1})
        for p,item in protected.items():self.assertEqual(app.source(self.f.root,p,self.f.uid,absent=True),item,p)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode),0o700)
        for path in backup.glob('before-*'):self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o400);self.assertEqual(path.stat().st_nlink,1)
    def test_known_hardlink_becomes_independent_without_modifying_alias(self):
        relative='app/Services/Dashboard/WhatsAppReplyService.php';alias=self.f.home/'legacy-alias.php';os.link(self.f.root/relative,alias)
        old=alias.read_bytes();self.f.install();self.assertEqual(alias.read_bytes(),old);self.assertNotEqual(alias.stat().st_ino,(self.f.root/relative).stat().st_ino)
    def test_unknown_live_hash_named_before_php_or_database(self):
        p='public/js/dashboard-whatsapp-inbox.js';(self.f.root/p).write_bytes(b'unknown')
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_LIVE_HASH_NEEDS_REVIEW: '+p):self.f.install()
        self.assertEqual(self.f.calls,[])
    def reviewed_meta_channels_css(self):
        relative='public/css/dashboard-whatsapp-inbox.css'
        baseline=(ROOT/'tests/fixtures/whatsapp-inbox-before-meta-channels.css').read_bytes()
        target=(ROOT/relative).read_bytes()
        self.assertEqual(app.blob(baseline),'d4e1f32b816da1219b32de856ea484f07cecd1fa')
        self.assertEqual(app.SOURCE_PINS[relative],(app.blob(baseline),app.blob(target)))
        self.f.before[relative]=baseline;self.f.pins[relative]=app.SOURCE_PINS[relative]
        self.f.write(self.f.root/relative,baseline);self.f.write(self.f.payload/relative,target)
        self.f.write(self.f.manifest,(json.dumps({'version':1,'sources':[{'path':p,'before_blob':a,'after_blob':b} for p,(a,b) in self.f.pins.items()]})+'\n').encode())
        self.f.manifest_pin=app.blob(self.f.manifest.read_bytes())
        return relative,baseline,target
    def test_reviewed_meta_channels_css_installs_and_backup_preserves_preimage(self):
        relative,baseline,target=self.reviewed_meta_channels_css()
        status,backup,_=self.f.install()
        self.assertEqual(status,'WHATSAPP_DASHBOARD_UPGRADE_READY')
        self.assertEqual((self.f.root/relative).read_bytes(),target)
        self.assertEqual((backup/('before-'+str(list(app.SOURCE_PINS).index(relative)))).read_bytes(),baseline)
        addition=baseline[8036:]
        self.assertEqual(len(addition),261);self.assertTrue(target.endswith(addition))
    def test_unreviewed_meta_channels_css_edit_stops_before_php(self):
        relative,baseline,_=self.reviewed_meta_channels_css()
        self.f.write(self.f.root/relative,baseline+b'.unexpected{color:red}\n')
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_LIVE_HASH_NEEDS_REVIEW: '+relative):self.f.install()
        self.assertEqual(self.f.calls,[]);self.assertEqual(len(self.f.migrations),1)
    def test_payload_hash_rejects_before_bootstrap(self):
        (self.f.payload/app.MESSAGE).write_bytes(b'unknown')
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_PAYLOAD_HASH_MISMATCH'):self.f.install()
        self.assertEqual(self.f.calls,[])
    def test_symlink_rejects_before_bootstrap(self):
        p=self.f.root/'public/js/dashboard-whatsapp-inbox.js';p.unlink();p.symlink_to(self.f.root/'.env')
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_SOURCE_NEEDS_REVIEW'):self.f.install()
        self.assertEqual(self.f.calls,[])
    def test_group_writable_source_rejects(self):
        (self.f.root/'public/js/dashboard-whatsapp-inbox.js').chmod(0o664)
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_LIVE_MODE_UNSAFE'):self.f.install()
    def test_wrong_manifest_scope_rejects(self):
        value=json.loads(self.f.manifest.read_bytes());value['sources'].append(value['sources'][0]);self.f.write(self.f.manifest,json.dumps(value).encode())
        self.f.manifest_pin=app.blob(self.f.manifest.read_bytes())
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_MANIFEST_SCOPE_INVALID'):self.f.install()
    def test_lint_failure_has_no_runtime_changes_or_migration(self):
        self.f.fail='lint'
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_CANDIDATE_LINT_FAILED'):self.f.install()
        self.unchanged();self.assertEqual(len(self.f.migrations),1)
    def test_migration_failure_has_no_runtime_changes(self):
        self.f.fail='migration'
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_METADATA_MIGRATION_FAILED.*FILES_RESTORED'):self.f.install()
        self.unchanged()
    def test_unrelated_migration_drift_refused(self):
        self.f.fail='foreignmigration'
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_UNRELATED_MIGRATIONS_CHANGED'):self.f.install()
        self.unchanged()
    def test_backfill_failure_retains_new_tables_restores_files(self):
        self.f.fail='backfill'
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_PROJECTION_INDEX_BACKFILL_NEEDS_REVIEW.*METADATA_TABLES_RETAINED'):self.f.install()
        self.unchanged();self.assertTrue(all(t['exists'] for t in self.f.tables.values()))
    def test_postflight_failure_restores_all_code_not_metadata_tables(self):
        self.f.fail='postflight'
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_ROUTE_CONTRACT_POSTFLIGHT_FAILED.*FILES_RESTORED'):self.f.install()
        self.unchanged();self.assertTrue(all(t['exists'] for t in self.f.tables.values()))
    def test_cached_routes_only_removal_and_guarded_restore(self):
        cache=self.f.root/'bootstrap/cache/routes-v7.php';self.f.write(cache,b'<?php old_routes();');initial=app.source(self.f.root,'bootstrap/cache/routes-v7.php',self.f.uid)
        self.f.fail='postflight'
        with self.assertRaisesRegex(app.SafeError,'FILES_RESTORED'):self.f.install()
        restored=app.source(self.f.root,'bootstrap/cache/routes-v7.php',self.f.uid)
        self.assertEqual(restored[0],initial[0]);self.assertEqual(restored[1][:-1],initial[1][:-1])
    def test_cached_routes_success_does_not_touch_config_cache(self):
        cache=self.f.root/'bootstrap/cache/routes-v7.php';self.f.write(cache,b'<?php old_routes();')
        config=app.source(self.f.root,'bootstrap/cache/config.php',self.f.uid);self.f.install()
        self.assertFalse(cache.exists());self.assertEqual(app.source(self.f.root,'bootstrap/cache/config.php',self.f.uid),config)
    def test_concurrent_protected_edit_stops_without_overwrite(self):
        def mutate(f): (f.root/'.env').write_bytes(b'concurrent private value')
        self.f.after=mutate
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_PROTECTED_FILE_CHANGED'):self.f.install()
        self.unchanged();self.assertEqual((self.f.root/'.env').read_bytes(),b'concurrent private value')
    def test_authoritative_classmap_without_new_classes_stops_before_migration(self):
        original=self.f.runner
        def run(root,args,**kwargs):
            if args[1:3]==['-r',app.READINESS_PHP]:
                value=self.f.ready();value['autoload']['authoritative']=True;return 0,json.dumps(value).encode()
            return original(root,args,**kwargs)
        self.f.runner=run
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_AUTHORITATIVE_CLASSMAP_NEEDS_REVIEW'):self.f.install()
        self.unchanged();self.assertEqual(len(self.f.migrations),1)

    def test_partial_existing_metadata_table_refused(self):
        self.f.tables['whatsapp_inbox_hidden_messages']=schema_tables()['whatsapp_inbox_hidden_messages']
        with self.assertRaisesRegex(app.SafeError,'DASHBOARD_METADATA_SCHEMA_NEEDS_REVIEW'):self.f.install()
        self.unchanged()
    def test_schema_column_index_fk_drift_refused(self):
        for field in ('columns','indexes','foreign_keys'):
            with self.subTest(field=field):
                value=self.f.ready();value['tables']=schema_tables()
                value['tables']['whatsapp_inbox_hidden_messages'][field]=[] if field=='columns' else {}
                with self.assertRaises(app.SafeError):app.validate_schema(value,True)
    def test_unexpected_unique_index_refused(self):
        value=self.f.ready();value['tables']=schema_tables()
        value['tables']['whatsapp_inbox_hidden_messages']['indexes']['extra_actor_unique']={'unique':True,'columns':['actor_id']}
        with self.assertRaises(app.SafeError):app.validate_schema(value,True)
    def test_backfill_incomplete_no_progress_or_unbounded_output_refused(self):
        for response in ({'processed':0,'valid':0,'invalid':0,'remaining':1,'complete':False},
                         {'processed':201,'valid':201,'invalid':0,'remaining':0,'complete':True}):
            with self.subTest(response=response):
                with self.assertRaises(app.SafeError):app.backfill(self.f.root,self.f.payload,lambda *_a,**_k:(0,json.dumps(response).encode()))
    def test_repeat_target_exact_schema_is_idempotent(self):
        self.f.install();first={p:app.source(self.f.root,p,self.f.uid) for p in app.SOURCE_PINS};n=len(self.f.migrations)
        self.f.install();self.assertEqual(len(self.f.migrations),n)
        for p,old in first.items():self.assertEqual(app.source(self.f.root,p,self.f.uid),old)
    def test_preexchange_concurrent_edit_retained(self):
        relative='app/Services/Dashboard/WhatsAppReplyService.php';original=app.base.atomic_exchange
        def race(a,b):
            if b==self.f.root/relative:b.write_bytes(b'<?php /* concurrent peer */');original(a,b)
            else:original(a,b)
        with patch.object(app.base,'atomic_exchange',side_effect=race):
            with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.f.install()
        self.assertEqual((self.f.root/relative).read_bytes(),b'<?php /* concurrent peer */')
    def test_immutable_backup_restores_baseline_even_if_legacy_alias_changes(self):
        relative='app/Services/Dashboard/WhatsAppReplyService.php';alias=self.f.home/'legacy-alias.php';os.link(self.f.root/relative,alias)
        original=self.f.runner
        def run(root,args,**kwargs):
            if args[1:3]==['-r',app.POSTFLIGHT_PHP]:alias.write_bytes(b'<?php /* changed legacy alias */');return 1,b''
            return original(root,args,**kwargs)
        self.f.runner=run
        with self.assertRaisesRegex(app.SafeError,'FILES_RESTORED'):self.f.install()
        self.unchanged();self.assertEqual(alias.read_bytes(),b'<?php /* changed legacy alias */')
    def test_peer_cached_entrypoint_retains_published_dependencies_on_failure(self):
        for initially_cached in (False,True):
            with self.subTest(initially_cached=initially_cached):
                f=InstallFixture()
                try:
                    cache=f.root/'bootstrap/cache/routes-v7.php'
                    if initially_cached:f.write(cache,b'<?php original_routes();')
                    original=f.runner
                    def run(root,args,**kwargs):
                        if args[1:3]==['-r',app.POSTFLIGHT_PHP]:f.write(cache,b'<?php peer_new_controller();');return 1,b''
                        return original(root,args,**kwargs)
                    f.runner=run
                    with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):f.install()
                    self.assertEqual(cache.read_bytes(),b'<?php peer_new_controller();')
                    for relative in (app.MESSAGE,app.MEDIA,'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php'):
                        self.assertEqual((f.root/relative).read_bytes(),(f.payload/relative).read_bytes())
                finally:f.cleanup()
    def test_mid_rollback_peer_cache_repairs_own_restored_dependencies(self):
        for trigger in ('resources/views/admin/whatsapp/index.blade.php','app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php'):
            for initially_cached in (False,True):
                with self.subTest(trigger=trigger,initially_cached=initially_cached):
                    f=InstallFixture();f.fail='postflight';exchange=app.base.atomic_exchange;move=app.base.atomic_move_noreplace;fired=[False]
                    try:
                        if initially_cached:f.write(f.root/'bootstrap/cache/routes-v7.php',b'<?php original_routes();')
                        def observe(path):
                            if app.STAGE=='POSTFLIGHT' and path==f.root/trigger and not fired[0]:
                                fired[0]=True;f.write(f.root/'bootstrap/cache/routes-v7.php',b'<?php peer_new_controller();')
                        def race_exchange(a,b):exchange(a,b);observe(b)
                        def race_move(a,b):move(a,b);observe(a)
                        with patch.object(app.base,'atomic_exchange',side_effect=race_exchange),patch.object(app.base,'atomic_move_noreplace',side_effect=race_move):
                            with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):f.install()
                        self.assertTrue(fired[0])
                        self.assertEqual((f.root/'bootstrap/cache/routes-v7.php').read_bytes(),b'<?php peer_new_controller();')
                        for relative in app.SOURCE_PINS:
                            self.assertEqual((f.root/relative).read_bytes(),(f.payload/relative).read_bytes(),relative)
                    finally:f.cleanup()

    def test_compensation_never_attributes_peer_file_to_our_restore(self):
        relative='public/css/dashboard-whatsapp-inbox.css';self.f.fail='postflight';original=app.base.replace_file
        def race(path,data,*args,**kwargs):
            result=original(path,data,*args,**kwargs)
            if path==self.f.root/relative and data==self.f.before[relative]:
                self.f.write(path,b'.peer{color:red}')
                self.f.write(self.f.root/'bootstrap/cache/routes-v7.php',b'<?php peer_new_controller();')
            return result
        with patch.object(app.base,'replace_file',side_effect=race):
            with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.f.install()
        self.assertEqual((self.f.root/relative).read_bytes(),b'.peer{color:red}')
        self.assertTrue((self.f.root/'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php').exists())

    def test_final_route_cache_restore_collision_compensates_dependencies(self):
        self.f.fail='postflight';cache=self.f.root/'bootstrap/cache/routes-v7.php';self.f.write(cache,b'<?php original_routes();')
        original=app.base.atomic_move_noreplace
        def race(a,b):
            if a.name=='routes-displaced.php' and b==cache:self.f.write(cache,b'<?php peer_new_controller();')
            original(a,b)
        with patch.object(app.base,'atomic_move_noreplace',side_effect=race):
            with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.f.install()
        self.assertEqual(cache.read_bytes(),b'<?php peer_new_controller();')
        for relative in app.SOURCE_PINS:self.assertEqual((self.f.root/relative).read_bytes(),(self.f.payload/relative).read_bytes(),relative)
        self.assertEqual(stat.S_IMODE((self.f.root/'database/migrations').stat().st_mode),0o755)

    def test_rollback_exception_compensates_prior_owned_restores(self):
        relative='app/Services/Dashboard/WhatsAppReplyService.php';self.f.fail='postflight';original=app.base.atomic_exchange
        def race(a,b):
            is_restore=b==self.f.root/relative and a.read_bytes()==self.f.before[relative]
            original(a,b)
            if is_restore:
                self.f.write(b,b'<?php /* peer service */')
                self.f.write(self.f.root/'bootstrap/cache/routes-v7.php',b'<?php peer_new_controller();')
        with patch.object(app.base,'atomic_exchange',side_effect=race):
            with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.f.install()
        self.assertEqual((self.f.root/relative).read_bytes(),b'<?php /* peer service */')
        self.assertTrue((self.f.root/'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php').exists())
    def test_untrusted_backup_stop_compensates_prior_owned_restores(self):
        relative='app/Services/Dashboard/WhatsAppReplyService.php';self.f.fail='postflight';original=app.payload_bytes
        def race(path,uid):
            if path.name=='before-'+str(list(app.SOURCE_PINS).index(relative)):
                self.f.write(self.f.root/'bootstrap/cache/routes-v7.php',b'<?php peer_new_controller();')
                return b'tampered original backup'
            return original(path,uid)
        with patch.object(app,'payload_bytes',side_effect=race):
            with self.assertRaisesRegex(app.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.f.install()
        for path in app.SOURCE_PINS:self.assertEqual((self.f.root/path).read_bytes(),(self.f.payload/path).read_bytes(),path)

    def test_created_directories_readable_despite_private_umask(self):
        old=os.umask(0o077)
        try:self.f.install()
        finally:os.umask(old)
        self.assertEqual(stat.S_IMODE((self.f.root/'database/migrations').stat().st_mode),0o755)

    def test_all_embedded_php_programs_parse(self):
        php=os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php')
        if not php:self.skipTest('Actual PHP interpreter unavailable')
        for name in ('COMPILE_PHP','READINESS_PHP','BACKFILL_PHP','POSTFLIGHT_PHP'):
            path=self.f.home/(name+'.php');path.write_text('<?php\n'+getattr(app,name))
            result=subprocess.run([php,'-l',str(path)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(result.returncode,0,name+' has invalid PHP syntax')
    def test_actual_blade_compiles_both_final_views_without_global_cache_write(self):
        php=os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php');vendor=os.environ.get('WA_INBOX_TEST_VENDOR')
        if not php or not vendor:self.skipTest('Actual PHP/Blade fixture vendor unavailable')
        if pathlib.Path(vendor).is_file():vendor=str(pathlib.Path(vendor).parent)
        program=r'''require $argv[1].'/autoload.php';
        $compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,$argv[2]);
        $compiled=$compiler->compileString(file_get_contents($argv[3]));file_put_contents($argv[4],$compiled);'''
        cache=self.f.home/'compiled';cache.mkdir()
        for n,relative in enumerate(app.VIEWS):
            output=self.f.home/('blade-'+str(n)+'.php')
            result=subprocess.run([php,'-r',program,vendor,str(cache),str(ROOT/relative),str(output)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(result.returncode,0,'Actual Blade compilation failed')
            result=subprocess.run([php,'-l',str(output)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(result.returncode,0,'Actual compiled Blade syntax failed')
        self.assertEqual(list(cache.iterdir()),[])

    def test_actual_sqlite_migration_matches_declared_columns_indices_and_fk(self):
        php=os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php');vendor=os.environ.get('WA_INBOX_TEST_VENDOR')
        if not php or not vendor:self.skipTest('Actual PHP/SQLite fixture vendor unavailable')
        if pathlib.Path(vendor).is_file():vendor=str(pathlib.Path(vendor).parent)
        program=r"""require $argv[1].'/autoload.php';
        $caps=new \Illuminate\Database\Capsule\Manager;$caps->addConnection(['driver'=>'sqlite','database'=>':memory:']);$caps->setAsGlobal();
        $container=$caps->getContainer();$container->instance('db',$caps->getDatabaseManager());$container->bind('db.schema',fn()=>$caps->getConnection()->getSchemaBuilder());
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);$schema=$caps->getConnection()->getSchemaBuilder();
        foreach(['whatsapp_inbox_messages','whatsapp_inbox_conversations','whatsapp_reply_requests']as$table)$schema->create($table,fn($t)=>$t->bigIncrements('id'));
        require $argv[2];(new CreateWhatsAppInboxHiddenMessages)->up();$result=[];
        foreach(['whatsapp_inbox_hidden_messages','whatsapp_reply_projection_keys','whatsapp_reply_reaction_targets']as$table){
         $columns=[];$indexes=[];$primary=[];
         foreach($caps->getConnection()->select('PRAGMA table_info("'.$table.'")')as$c){$columns[$c->name]=!$c->notnull&&!$c->pk;if($c->pk)$primary[(int)$c->pk]=$c->name;}
         if($primary){ksort($primary);$indexes[]=['unique'=>true,'columns'=>array_values($primary)];}
         foreach($caps->getConnection()->select('PRAGMA index_list("'.$table.'")')as$i){$names=[];foreach($caps->getConnection()->select('PRAGMA index_info("'.$i->name.'")')as$c)$names[(int)$c->seqno]=$c->name;ksort($names);$indexes[]=['unique'=>(bool)$i->unique,'columns'=>array_values($names)];}
         $foreign=[];foreach($caps->getConnection()->select('PRAGMA foreign_key_list("'.$table.'")')as$f)$foreign[]=['column'=>$f->from,'target'=>$f->table,'target_column'=>$f->to,'delete'=>$f->on_delete];
         $result[$table]=['columns'=>$columns,'indexes'=>$indexes,'foreign'=>$foreign];
        }echo json_encode($result,JSON_THROW_ON_ERROR);"""
        result=subprocess.run([php,'-r',program,vendor,str(ROOT/app.MIGRATION)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        self.assertEqual(result.returncode,0,'Actual migration execution failed')
        value=json.loads(result.stdout)
        self.assertEqual(set(value),set(app.TABLE_COLUMNS))
        for table,metadata in value.items():
            self.assertEqual(metadata['columns'],{name:definition[1] for name,definition in app.TABLE_COLUMNS[table].items()},table)
            for unique,columns in app.TABLE_INDEXES[table]:self.assertIn({'unique':unique,'columns':columns},metadata['indexes'],table)
            expected={tuple(names) for unique,names in app.TABLE_INDEXES[table] if unique}
            actual={tuple(i['columns']) for i in metadata['indexes'] if i['unique']}
            self.assertEqual(actual,expected,table)
            self.assertEqual(sorted((f['column'],f['target'],f['target_column'],f['delete']) for f in metadata['foreign']),
                sorted((column,target,'id','RESTRICT') for column,target in app.TABLE_FOREIGN[table].values()),table)

    def test_no_worker_or_config_cache_commands(self):
        self.f.install();flat=' '.join(str(v) for call in self.f.calls for v in call)
        for forbidden in ('systemctl','config:clear','cache:clear','migrate:rollback','queue:restart','whatsapp:process-orders','route:cache'):
            self.assertNotIn(forbidden,flat)


class ActualBackfillFixture:
    """A real Laravel console bootstrap with old App PSR-4 mappings and private SQLite."""
    def __init__(self, php, vendor, populated=False, autoload=None, mutate=None, identical_old=False):
        self.php=php;self.temp=tempfile.TemporaryDirectory(prefix='wa-backfill-loader-')
        self.home=pathlib.Path(self.temp.name);self.root=self.home/'public_html';self.payload=self.home/'payload'
        self.db=self.home/'fixture.sqlite';self.db.touch();self.candidates=[];self.pins=[]
        for relative in ('vendor','bootstrap/cache','config','storage/framework','app/Services/Dashboard'):
            (self.root/relative).mkdir(parents=True,exist_ok=True)
        self.payload.mkdir();self.payload.chmod(0o755)
        for label,relative in (('MESSAGE',app.MESSAGE),('MEDIA',app.MEDIA)):
            data=(ROOT/relative).read_bytes();candidate=self.payload/pathlib.Path(relative).name
            candidate.write_bytes(data);candidate.chmod(0o644);self.candidates.append(candidate);self.pins.append(app.blob(data))
            old_path=os.environ.get('WA_BACKFILL_OLD_'+label)
            old=pathlib.Path(old_path).read_bytes() if old_path else data+b'\n/* Previously deployed PSR-4 copy, distinct from the staged candidate. */\n'
            if old_path:
                expected={'MESSAGE':'f88843b6abc227c1697476cd9a6217331070f250',
                    'MEDIA':'998efb85b37cb5e26497d82a2f7816513b6435a7'}[label]
                if app.blob(old)!=expected:raise AssertionError('Unverified previously deployed '+label+' fixture')
            (self.root/relative).write_bytes(data if identical_old else old)
        (self.root/'app/Services/Dashboard/WhatsAppInboxAccess.php').write_bytes((ROOT/'app/Services/Dashboard/WhatsAppInboxAccess.php').read_bytes())
        external=str(pathlib.Path(vendor).parent) if pathlib.Path(vendor).is_file() else vendor
        vendor_php=r'''<?php
file_put_contents(dirname(__DIR__).'/vendor-started', 'yes');
$loader=require __EXTERNAL__.'/autoload.php';
$loader->addPsr4('App\\', dirname(__DIR__).'/app', true);
__PRELOAD__
return $loader;
'''.replace('__EXTERNAL__',self.literal(external)).replace('__PRELOAD__',
            "class_exists('App\\\\Services\\\\Dashboard\\\\WhatsAppInbox"+autoload+"');" if autoload else '')
        (self.root/'vendor/autoload.php').write_text(vendor_php)
        app_config={'name':'Backfill fixture','env':'testing','debug':False,'url':'http://localhost',
            'timezone':'UTC','cipher':'AES-256-CBC'}
        # Use a stable valid 32-byte key; no production configuration is read.
        app_config['key']='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
        providers=r'''[\Illuminate\Database\DatabaseServiceProvider::class,
\Illuminate\Encryption\EncryptionServiceProvider::class, BackfillFixtureProvider::class]'''
        (self.root/'config/app.php').write_text('<?php return '+self.array(app_config)[:-1]+",'providers'=>"+providers+'];')
        (self.root/'config/database.php').write_text("<?php return ['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>"+self.literal(str(self.db))+",'prefix'=>'','foreign_key_constraints'=>true]]];")
        mutation=''
        if mutate is not None:mutation="file_put_contents("+self.literal(str(self.candidates[mutate]))+", '\\n/* concurrent candidate replacement */', FILE_APPEND);"
        bootstrap=r'''<?php
class BackfillFixtureProvider extends \Illuminate\Support\ServiceProvider {
 public function boot() {
  class_exists(\App\Services\Dashboard\WhatsAppInboxMessage::class);
  class_exists(\App\Services\Dashboard\WhatsAppInboxMedia::class);
  $paths=[];
  foreach([\App\Services\Dashboard\WhatsAppInboxMessage::class,\App\Services\Dashboard\WhatsAppInboxMedia::class] as $class)
   $paths[]=(new \ReflectionClass($class))->getFileName();
  file_put_contents(__ROOT__.'/bootstrap-started',json_encode($paths,JSON_THROW_ON_ERROR));
  \Illuminate\Support\Facades\DB::listen(function($event){
   if(preg_match('/\A\s*(?:insert|update|delete|replace|create|drop|alter)\b/i',$event->sql))
    file_put_contents(__ROOT__.'/metadata-writes',$event->sql."\n",FILE_APPEND);
  });
  __MUTATE__
 }
}
$app=new \Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton(\Illuminate\Contracts\Console\Kernel::class,\Illuminate\Foundation\Console\Kernel::class);
return $app;
'''.replace('__ROOT__',self.literal(str(self.root))).replace('__MUTATE__',mutation)
        (self.root/'bootstrap/app.php').write_text(bootstrap)
        setup=r'''require $argv[1].'/autoload.php';
$caps=new \Illuminate\Database\Capsule\Manager;
$caps->addConnection(['driver'=>'sqlite','database'=>$argv[2],'foreign_key_constraints'=>true]);$caps->setAsGlobal();
$container=$caps->getContainer();$container->instance('db',$caps->getDatabaseManager());
$container->bind('db.schema',fn()=>$caps->getConnection()->getSchemaBuilder());
$container->instance('encrypter',new \Illuminate\Encryption\Encrypter('0123456789abcdef0123456789abcdef','AES-256-CBC'));
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);$schema=$caps->getConnection()->getSchemaBuilder();
$schema->create('whatsapp_inbox_conversations',function($t){$t->bigIncrements('id');$t->string('waba_id',32);$t->string('phone_number_id',32);});
$schema->create('whatsapp_inbox_messages',function($t){$t->bigIncrements('id');});
$schema->create('whatsapp_reply_requests',function($t){
 $t->bigIncrements('id');$t->unsignedBigInteger('conversation_id');$t->string('waba_id',32);$t->string('phone_number_id',32);
 $t->string('kind',16);$t->string('state',16);$t->char('remote_message_key',64)->nullable();$t->text('response_details')->nullable();
 $t->char('payload_hash',64);$t->text('audit')->nullable();$t->unsignedBigInteger('actor_id');$t->timestamp('completed_at')->nullable();
});
require $argv[3];(new CreateWhatsAppInboxHiddenMessages)->up();
if($argv[4]==='populated'){
 $waba='468336579702269';$phone='515388018324075';$native='wamid.fixture_reply';$payload=str_repeat('a',64);
 $encrypt=fn($v)=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($v,JSON_THROW_ON_ERROR));
 $remote=fn($id)=>hash('sha256','whatsapp-reply-message-v1:'.$waba.':'.$phone.':'.$id);
 \Illuminate\Support\Facades\DB::table('whatsapp_inbox_conversations')->insert(['id'=>1,'waba_id'=>$waba,'phone_number_id'=>$phone]);
 $base=['conversation_id'=>1,'waba_id'=>$waba,'phone_number_id'=>$phone,'state'=>'ACCEPTED','actor_id'=>1,
  'payload_hash'=>$payload,'completed_at'=>'2026-10-12 17:00:00'];
 \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->insert($base+['id'=>1,'kind'=>'text',
  'remote_message_key'=>$remote($native),'response_details'=>$encrypt(['message_id'=>$native]),'audit'=>null]);
 $key=hash('sha256','whatsapp-message-v1:'.$waba.':'.$phone.':'.$native);
 $audit=['source'=>['conversation'=>1,'kind'=>'reply','id'=>1,'fingerprint'=>hash('sha256',$payload.':'.$native)],
  'source_native_key'=>$key,'source_native_kind'=>'text','emoji'=>'👍'];
 \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->insert($base+['id'=>2,'kind'=>'reaction',
  'remote_message_key'=>$remote('wamid.fixture_reaction'),'response_details'=>$encrypt(['message_id'=>'wamid.fixture_reaction']),
  'audit'=>$encrypt($audit)]);
}'''
        result=subprocess.run([php,'-r',setup,external,str(self.db),str(ROOT/app.MIGRATION),
            'populated' if populated else 'empty'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
        if result.returncode:raise AssertionError('Real fixture schema/receipt setup failed: '+result.stderr.decode(errors='replace')[:2000])

    @staticmethod
    def literal(value):return "'"+value.replace('\\','\\\\').replace("'","\\'")+"'"
    @classmethod
    def array(cls,value):
        return '['+','.join(cls.literal(k)+'=>'+('true' if v is True else 'false' if v is False else cls.literal(v)) for k,v in value.items())+']'
    def run(self,pins=None,paths=None):
        candidates=paths or self.candidates;digests=pins or self.pins
        return subprocess.run([self.php,'-r',app.BACKFILL_PHP,str(candidates[0]),digests[0],str(candidates[1]),digests[1]],
            cwd=self.root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
    def rows(self):
        with sqlite3.connect(self.db) as db:
            db.row_factory=sqlite3.Row
            return {name:[dict(row) for row in db.execute('SELECT * FROM '+name+' ORDER BY 1').fetchall()] for name in
                ('whatsapp_reply_requests','whatsapp_reply_projection_keys','whatsapp_reply_reaction_targets')}
    def cleanup(self):self.temp.cleanup()


class ActualBackfillLoaderTests(unittest.TestCase):
    def fixture(self,**kwargs):
        php=os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php');vendor=os.environ.get('WA_INBOX_TEST_VENDOR')
        if not php or not vendor:
            if os.environ.get('WHATSAPP_REQUIRE_PHP_TESTS')=='1':self.fail('Actual PHP/Laravel backfill fixture dependencies required')
            self.skipTest('Actual PHP/Laravel backfill fixture dependencies unavailable')
        f=ActualBackfillFixture(php,vendor,**kwargs);self.addCleanup(f.cleanup);return f
    def no_writes(self,f,before):
        self.assertEqual(f.rows(),before)
        self.assertFalse((f.root/'metadata-writes').exists())
    def test_previous_bootstrap_first_loader_reproduces_production_failure_without_writes(self):
        f=self.fixture();before=f.rows()
        previous=r'''ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $class=\App\Services\Dashboard\WhatsAppInboxMessage::class;
 if(!class_exists($class,false))require_once $argv[1];
 $reflection=new \ReflectionClass($class);$bytes=file_get_contents($reflection->getFileName());
 if(!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$argv[2])exit(1);
 $result=(new $class)->backfillProjectionIndex(200);
 while(ob_get_level()>0)ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}'''
        result=subprocess.run([f.php,'-r',previous,str(f.candidates[0]),f.pins[0]],
            cwd=f.root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
        self.assertNotEqual(result.returncode,0);self.assertEqual(result.stdout,b'')
        self.assertEqual(json.loads((f.root/'bootstrap-started').read_text()),
            [str(f.root/relative) for relative in (app.MESSAGE,app.MEDIA)])
        self.no_writes(f,before)
    def test_bootstrap_psr4_old_classes_cannot_displace_verified_candidates_zero_pending(self):
        f=self.fixture();before=f.rows();result=f.run()
        self.assertEqual(result.returncode,0,result.stderr.decode(errors='replace'))
        self.assertEqual(json.loads(result.stdout),{'processed':0,'valid':0,'invalid':0,'remaining':0,'complete':True})
        self.assertEqual(json.loads((f.root/'bootstrap-started').read_text()),[str(p) for p in f.candidates])
        self.no_writes(f,before)
    def test_real_encrypted_reply_and_reaction_backfill_then_idempotent_second_pass(self):
        f=self.fixture(populated=True);before=f.rows();result=f.run()
        self.assertEqual(result.returncode,0,result.stderr.decode(errors='replace'))
        self.assertEqual(json.loads(result.stdout),{'processed':2,'valid':2,'invalid':0,'remaining':0,'complete':True})
        self.assertEqual(json.loads((f.root/'bootstrap-started').read_text()),[str(p) for p in f.candidates])
        after=f.rows();self.assertEqual(after['whatsapp_reply_requests'],before['whatsapp_reply_requests'])
        self.assertEqual(len(after['whatsapp_reply_projection_keys']),1)
        self.assertEqual(len(after['whatsapp_reply_reaction_targets']),1)
        self.assertEqual(after['whatsapp_reply_projection_keys'][0]['valid'],1)
        self.assertEqual(after['whatsapp_reply_reaction_targets'][0]['valid'],1)
        (f.root/'metadata-writes').unlink();result=f.run()
        self.assertEqual(result.returncode,0,result.stderr.decode(errors='replace'))
        self.assertEqual(json.loads(result.stdout),{'processed':0,'valid':0,'invalid':0,'remaining':0,'complete':True})
        self.no_writes(f,after)
    def test_both_candidate_hashes_verified_before_any_candidate_vendor_or_bootstrap_execution(self):
        for bad in (0,1):
            with self.subTest(candidate=bad):
                f=self.fixture(populated=True);before=f.rows()
                for candidate in f.candidates:
                    candidate.write_bytes(candidate.read_bytes()+b'\nfile_put_contents(__DIR__."/candidate-executed", "yes");\n')
                pins=[app.blob(p.read_bytes()) for p in f.candidates];pins[bad]='0'*40
                result=f.run(pins=pins)
                self.assertNotEqual(result.returncode,0);self.assertEqual(result.stdout,b'')
                self.assertFalse((f.payload/'candidate-executed').exists())
                self.assertFalse((f.root/'vendor-started').exists());self.assertFalse((f.root/'bootstrap-started').exists())
                self.no_writes(f,before)
    def test_composer_autoload_preloaded_old_class_is_rejected_without_bootstrap_or_writes(self):
        for label in ('Message','Media'):
            with self.subTest(class_name=label):
                f=self.fixture(populated=True,autoload=label);before=f.rows();result=f.run()
                self.assertNotEqual(result.returncode,0);self.assertEqual(result.stdout,b'')
                self.assertTrue((f.root/'vendor-started').exists());self.assertFalse((f.root/'bootstrap-started').exists())
                self.no_writes(f,before)
    def test_identical_bytes_preloaded_from_other_path_are_rejected_without_writes(self):
        for label in ('Message','Media'):
            with self.subTest(class_name=label):
                f=self.fixture(populated=True,autoload=label,identical_old=True);before=f.rows();result=f.run()
                self.assertNotEqual(result.returncode,0);self.assertFalse((f.root/'bootstrap-started').exists())
                self.no_writes(f,before)
    def test_candidate_replacement_during_bootstrap_is_rejected_before_metadata_writes(self):
        for changed in (0,1):
            with self.subTest(candidate=changed):
                f=self.fixture(populated=True,mutate=changed);before=f.rows();result=f.run()
                self.assertNotEqual(result.returncode,0);self.assertEqual(result.stdout,b'')
                self.assertTrue((f.root/'bootstrap-started').exists());self.no_writes(f,before)
    def test_symlink_candidate_is_rejected_before_execution_even_when_bytes_match(self):
        for linked in (0,1):
            with self.subTest(candidate=linked):
                f=self.fixture(populated=True);before=f.rows();paths=list(f.candidates)
                alias=f.payload/('linked-'+str(linked)+'.php');alias.symlink_to(paths[linked]);paths[linked]=alias
                result=f.run(paths=paths)
                self.assertNotEqual(result.returncode,0);self.assertFalse((f.root/'vendor-started').exists())
                self.assertFalse((f.root/'bootstrap-started').exists());self.no_writes(f,before)


if __name__=='__main__':unittest.main()
