#!/usr/bin/env python3
"""Real Laravel/SQLite readiness guards and the publisher diagnostic's read-only scope."""
import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).absolute().parent.parent
spec = importlib.util.spec_from_file_location('cairo_read_only_diagnostic', ROOT / 'deployment/diagnose_whatsapp_cairo_install.py')
diag = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = diag
spec.loader.exec_module(diag)
APP = diag.load_app()


class RealLaravelReadinessTests(unittest.TestCase):
    def php_fixture(self, scenario='valid', phase='source'):
        php = os.getenv('WHATSAPP_TEST_PHP') or '/tmp/wa-workflow-runtime/php'
        vendor = pathlib.Path(os.getenv('WA_INBOX_TEST_VENDOR') or '/tmp/wa-workflow-deps/vendor')
        if not pathlib.Path(php).is_file() or not (vendor / 'autoload.php').is_file():
            self.skipTest('Real Laravel/PHP SQLite runtime required')
        with tempfile.TemporaryDirectory(prefix='wa-cairo-readiness-test-') as folder:
            root = pathlib.Path(folder)
            for name in ('vendor', 'bootstrap/cache', 'config', 'storage/logs', 'bootstrap-alternate/cache'):
                (root / name).mkdir(parents=True)
            (root / 'vendor/autoload.php').write_text("<?php require getenv('WA_CAIRO_VENDOR').'/autoload.php';\n"
                "spl_autoload_register(static function($class){if(str_starts_with($class,'App\\\\')){"
                "$path=getcwd().'/app/'.str_replace('\\\\','/',substr($class,4)).'.php';"
                "if(is_file($path))require $path;}});\n")
            (root / 'config/app.php').write_text("<?php return ['name'=>'Readiness fixture','env'=>'testing',"
                "'debug'=>false,'url'=>'http://localhost','timezone'=>'UTC','locale'=>'en','fallback_locale'=>'en',"
                "'providers'=>[Illuminate\\Database\\DatabaseServiceProvider::class]];\n")
            (root / 'config/database.php').write_text("<?php return ['default'=>'sqlite','connections'=>["
                "'sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]];\n")
            (root / 'config/whatsapp_orders.php').write_text("<?php return ['mode'=>getenv('WA_CAIRO_SCENARIO')==='auto'?'auto':'review'];\n")
            for short in ('PhoneDelivery', 'PosServiceTicket', 'WhatsAppOrderWorkflow'):
                relative = pathlib.Path('app/Services/Dashboard') / (short + '.php')
                (root / relative).parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / relative, root / relative)
            (root / 'bootstrap/app.php').write_text(r'''<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('SQLITE_REQUIRED');
$app=new Illuminate\Foundation\Application(getcwd());
$app->singleton(Illuminate\Contracts\Console\Kernel::class,Illuminate\Foundation\Console\Kernel::class);
$app->booted(static function(){
 $scenario=getenv('WA_CAIRO_SCENARIO');
 Schema::create('resturants',static function($t)use($scenario){
  $t->integer('id')->primary();$t->string('name');
  if($scenario!=='parent_schema')$t->integer('parent_id')->nullable();
  $t->string('lat');$t->string('lng');
  if($scenario!=='required_column')$t->string('km_price');
  $t->string('control');
 });
 $expected=json_decode(file_get_contents(getenv('WA_CAIRO_REGISTRY')),true,4,JSON_THROW_ON_ERROR);
 foreach($expected as$id=>$name){
  if($scenario==='row_count'&&(int)$id===309)continue;
  $row=['id'=>(int)$id,'name'=>$name,'lat'=>'30.1111','lng'=>'31.2222','control'=>'show'];
  if($scenario!=='parent_schema')$row['parent_id']=null;
  if($scenario!=='required_column')$row['km_price']='10.00';
  if((int)$id===309){
   if($scenario==='name')$row['name']='Wrong registered store';
   if($scenario==='parent')$row['parent_id']=94;
   if($scenario==='control')$row['control']='hide';
   if($scenario==='coordinates')$row['lat']='91';
   if($scenario==='rate')$row['km_price']='10.123';
  }
  DB::table('resturants')->insert($row);
 }
 $connection=DB::connection();$connection->enableQueryLog();
 register_shutdown_function(static function()use($connection){foreach($connection->getQueryLog()as$q){
  if(!preg_match('/\A\s*(select\b|pragma\b)/i',$q['query'])){
   fwrite(STDERR,"READINESS_WROTE_DATABASE\n");exit(78);
  }
 }});
});
return $app;
''')
            registry = root / 'registry.json'
            registry.write_text(json.dumps(APP.CAIRO_ORIGIN_NAMES, ensure_ascii=False))
            env = os.environ.copy()
            env.update(WA_CAIRO_VENDOR=str(vendor), WA_CAIRO_REGISTRY=str(registry), WA_CAIRO_SCENARIO=scenario)
            if scenario == 'cached_path':
                env['APP_CONFIG_CACHE'] = str(root / 'bootstrap-alternate/cache/config.php')
            return subprocess.run([php, '-r', diag.readiness_php(APP), phase], cwd=root, env=env, capture_output=True)

    def test_frozen_publisher_instrumentation_is_valid_php(self):
        self.assertEqual(APP.blob((ROOT / 'deployment/apply_whatsapp_cairo_origins.py').read_bytes()), diag.APP_BLOB)
        php = os.getenv('WHATSAPP_TEST_PHP') or '/tmp/wa-workflow-runtime/php'
        if not pathlib.Path(php).is_file():
            self.skipTest('PHP required for instrumentation syntax check')
        with tempfile.TemporaryDirectory() as folder:
            path = pathlib.Path(folder) / 'readiness.php'
            path.write_text('<?php\n' + diag.readiness_php(APP))
            result = subprocess.run([php, '-l', str(path)], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())

    def test_real_laravel_accepts_all_27_origins_in_review_and_auto_without_database_writes(self):
        for scenario in ('valid', 'auto'):
            with self.subTest(scenario=scenario):
                result = self.php_fixture(scenario)
                self.assertEqual(result.returncode, 0, result.stderr.decode())
                self.assertEqual(result.stderr, b'')
                value = json.loads(result.stdout)
                self.assertEqual(set(value), {'mode', 'config_fingerprint'})
                self.assertEqual(value['mode'], 'auto' if scenario == 'auto' else 'review')
                self.assertRegex(value['config_fingerprint'], r'\A[a-f0-9]{64}\Z')

    def test_real_schema_and_cached_path_failures_name_the_actual_guard(self):
        for scenario, stage, reason in (
                ('cached_path', 'BOOTSTRAP', 'CACHED_CONFIG_PATH'),
                ('parent_schema', 'SCHEMA', 'PARENT_SCHEMA'),
                ('required_column', 'SCHEMA', 'REQUIRED_COLUMNS'),
                ('row_count', 'REGISTRY', 'REGISTRY_ROW_COUNT')):
            with self.subTest(scenario=scenario):
                result = self.php_fixture(scenario)
                self.assertEqual(result.returncode, 1, result.stderr.decode())
                self.assertEqual(result.stderr, b'')
                self.assertEqual(json.loads(result.stdout),
                    {'status': 'FAILED', 'stage': stage, 'reason': reason, 'origin_id': None})

    def test_real_registry_failures_only_report_the_fixed_origin_id(self):
        for scenario, reason in (('name', 'REGISTERED_NAME'), ('parent', 'REGISTERED_PARENT'),
                ('control', 'CONTROL_SHOW'), ('coordinates', 'ORIGIN_PIN'), ('rate', 'RATE_FORMAT')):
            with self.subTest(scenario=scenario):
                result = self.php_fixture(scenario)
                self.assertEqual(result.returncode, 1, result.stderr.decode())
                self.assertEqual(result.stderr, b'')
                self.assertEqual(json.loads(result.stdout),
                    {'status': 'FAILED', 'stage': 'REGISTRY', 'reason': reason, 'origin_id': 309})


class DiagnosticScopeTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='wa-cairo-diagnostic-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = pathlib.Path(self.temporary.name)
        self.root = self.parent / 'public_html'
        self.root.mkdir(); (self.root / '.git').mkdir()
        self.payload = self.parent / 'payload'
        self.manifest = self.payload / 'deployment/whatsapp_cairo_origins_manifest.json'
        self.uid = os.geteuid(); self.calls = []
        pins = {}
        for index, relative in enumerate(APP.SOURCE_PINS):
            before = None if relative == APP.ORIGIN_PATH else ('<?php // before-' + str(index)).encode()
            after = ('<?php // target-' + str(index)).encode()
            if before is not None:
                self.write(self.root / relative, before)
            self.write(self.payload / relative, after)
            pins[relative] = (APP.blob(before) if before is not None else None, APP.blob(after))
        protected = {}
        for relative in dict.fromkeys((*APP.PROTECTED_PATHS, *APP.PROTECTED_BLOBS)):
            content = b'Preserved configuration and activation floor: ' + relative.encode()
            self.write(self.root / relative, content, 0o600 if relative == '.env' else 0o644)
            if relative in APP.PROTECTED_BLOBS:
                protected[relative] = APP.blob(content)
        for target, values in ((APP.SOURCE_PINS, pins), (APP.PROTECTED_BLOBS, protected)):
            override = patch.dict(target, values, clear=True)
            override.start(); self.addCleanup(override.stop)
        self.write(self.manifest, json.dumps({'version': 1, 'sources': [
            {'path': relative, 'before_blob': before, 'after_blob': after}
            for relative, (before, after) in pins.items()]}).encode())
        override = patch.object(diag, 'MANIFEST_BLOB', APP.blob(self.manifest.read_bytes()))
        override.start(); self.addCleanup(override.stop)

    def write(self, path, data, mode=0o644):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data); path.chmod(mode)

    def runner(self, root, args, capture=False):
        self.calls.append(args)
        if args[0] == '/usr/bin/systemctl':
            return 0, b'LoadState=loaded\nActiveState=active\n' + (b'MainPID=123\n' if args[-1].endswith('.service') else b'')
        if len(args) > 1 and args[1] == '-r':
            return 0, json.dumps({'mode': 'review', 'config_fingerprint': 'a' * 64}).encode()
        return 0, b''

    def footprint(self):
        return {str(path.relative_to(self.root)): (path.read_bytes(), APP.metadata(path))
                for path in self.root.rglob('*') if path.is_file()}

    def test_diagnostic_creates_no_runtime_backup_locks_and_performs_no_worker_actions(self):
        before = self.footprint()
        with patch.object(APP, 'apply', side_effect=AssertionError('publisher called')), \
                patch.object(APP.base, 'private_parent', side_effect=AssertionError('backup directory created')):
            checks = diag.diagnostic(self.root, self.uid, self.manifest, APP, self.runner)
        self.assertTrue(checks)
        self.assertFalse(any(row['status'] == 'FAILED' for row in checks), checks)
        self.assertEqual(self.footprint(), before)
        self.assertFalse((self.parent / 'whatsapp-release-backups').exists())
        self.assertFalse(any(path.name.startswith('.wa-cairo-diagnostic-') for path in self.parent.iterdir()))
        unit_calls = [args for args in self.calls if args[0] == '/usr/bin/systemctl']
        self.assertEqual(len(unit_calls), 4)
        self.assertTrue(all(args[1] == 'show' for args in unit_calls))
        self.assertFalse(any('artisan' in args or '--install' in args or '-c' in args for args in self.calls))
        self.assertEqual(sum('-l' in args for args in self.calls), 6)

    def test_profile_failure_is_fixed_and_skips_readiness_and_candidate_staging(self):
        path = self.root / APP.PHONE_PATH
        path.chmod(0o664)
        before = self.footprint()
        checks = diag.diagnostic(self.root, self.uid, self.manifest, APP, self.runner)
        failure = [row for row in checks if row.get('path') == APP.PHONE_PATH and row['status'] == 'FAILED']
        self.assertEqual(len(failure), 1)
        self.assertEqual(failure[0]['reason'], 'CAIRO_FILE_MODE_OR_PAYLOAD_LINK_UNSAFE')
        self.assertFalse(any(args[1] == '-r' for args in self.calls))
        self.assertEqual(self.footprint(), before)
        self.assertFalse((self.parent / 'whatsapp-release-backups').exists())

    def test_private_backup_failure_does_not_open_existing_locks(self):
        backup = self.parent / 'whatsapp-release-backups'
        backup.mkdir(); backup.chmod(0o755)
        real_open = os.open
        def deny_lock_opens(path, *args, **kwargs):
            if pathlib.Path(path).name in ('orders-install.lock', 'inbox-install.lock'):
                self.fail('lock inspected under unsafe backup parent')
            return real_open(path, *args, **kwargs)
        with patch.object(diag.os, 'open', side_effect=deny_lock_opens):
            checks = diag.diagnostic(self.root, self.uid, self.manifest, APP, self.runner)
        failure = [row for row in checks if row['stage'] == 'BACKUP_PARENT']
        self.assertEqual(failure, [{'stage': 'BACKUP_PARENT', 'status': 'FAILED',
                                  'reason': 'PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW'}])
        self.assertFalse((backup / 'orders-install.lock').exists())

    @staticmethod
    def different_device(info):
        class DeviceOnly:
            st_dev = info.st_dev + 1
            def __getattr__(self, name):
                return getattr(info, name)
        return DeviceOnly()

    def test_backup_device_mismatch_skips_existing_lock_opens_without_changing_files(self):
        backup = self.parent / 'whatsapp-release-backups'
        backup.mkdir(); backup.chmod(0o700)
        for name in ('orders-install.lock', 'inbox-install.lock'):
            self.write(backup / name, b'existing private lock', 0o600)
        before = self.footprint()
        lock_before = {path.name: (path.read_bytes(), APP.metadata(path)) for path in backup.iterdir()}
        real_lstat = pathlib.Path.lstat
        real_open = os.open
        def device_lstat(path, *args, **kwargs):
            info = real_lstat(path, *args, **kwargs)
            return self.different_device(info) if path == backup else info
        def deny_lock_opens(path, *args, **kwargs):
            if pathlib.Path(path).name in ('orders-install.lock', 'inbox-install.lock'):
                self.fail('lock inspected under a backup directory on another device')
            return real_open(path, *args, **kwargs)
        with patch.object(pathlib.Path, 'lstat', device_lstat), \
                patch.object(diag.os, 'open', side_effect=deny_lock_opens):
            checks = diag.diagnostic(self.root, self.uid, self.manifest, APP, self.runner)
        self.assertEqual([row for row in checks if row['stage'] == 'BACKUP_PARENT'],
            [{'stage': 'BACKUP_PARENT', 'status': 'FAILED',
              'reason': 'CAIRO_BACKUP_REQUIRES_SAME_FILESYSTEM', 'same_filesystem_as_app': False}])
        self.assertEqual([row for row in checks if row['stage'] == 'LOCK'],
            [{'stage': 'LOCK', 'status': 'SKIPPED', 'reason': 'BACKUP_PARENT_NOT_READY', 'path': name}
             for name in ('orders-install.lock', 'inbox-install.lock')])
        self.assertEqual(self.footprint(), before)
        self.assertEqual({path.name: (path.read_bytes(), APP.metadata(path)) for path in backup.iterdir()}, lock_before)

    def test_temporary_device_mismatch_skips_atomic_probe_but_still_lints_disposable_candidates(self):
        before = self.footprint()
        real_stat = pathlib.Path.stat
        def device_stat(path, *args, **kwargs):
            info = real_stat(path, *args, **kwargs)
            if path.parent == self.parent and path.name.startswith('.wa-cairo-diagnostic-'):
                return self.different_device(info)
            return info
        with patch.object(pathlib.Path, 'stat', device_stat), \
                patch.object(APP.base, 'probe_atomic_exchange', side_effect=AssertionError('wrong-device probe ran')):
            checks = diag.diagnostic(self.root, self.uid, self.manifest, APP, self.runner)
        self.assertEqual([row for row in checks if row['stage'] == 'TEMPORARY_SAME_FILESYSTEM'],
            [{'stage': 'TEMPORARY_SAME_FILESYSTEM', 'status': 'FAILED',
              'same_filesystem_as_app': False, 'reason': 'CAIRO_BACKUP_REQUIRES_SAME_FILESYSTEM'}])
        self.assertEqual([row for row in checks if row['stage'] == 'PRIVATE_ATOMIC_PROBE'],
            [{'stage': 'PRIVATE_ATOMIC_PROBE', 'status': 'SKIPPED', 'reason': 'TEMPORARY_FILESYSTEM_DIFFERS'}])
        self.assertFalse(any(row['stage'] == 'PRIVATE_ATOMIC_PROBE' and row['status'] != 'SKIPPED' for row in checks))
        self.assertEqual(sum('-l' in args for args in self.calls), 6)
        self.assertEqual(self.footprint(), before)
        self.assertFalse((self.parent / 'whatsapp-release-backups').exists())
        self.assertFalse(any(path.name.startswith('.wa-cairo-diagnostic-') for path in self.parent.iterdir()))


if __name__ == '__main__':
    unittest.main()
