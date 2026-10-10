#!/usr/bin/env python3
"""Activation publication/cache fixtures. No real credentials, API calls, or orders."""
import copy
import hashlib
import fcntl
import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('wa_auto_activation_fixture', ROOT / 'deployment/activate_whatsapp_automatic.py')
helper = importlib.util.module_from_spec(SPEC); sys.modules[SPEC.name] = helper; SPEC.loader.exec_module(helper)
PHP = os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php')
KEY = 'sk-proj-' + 'SYNTHETIC_ONLY_' * 3
TOKEN = 'EAA' + 'SYNTHETIC_ONLY_' * 3
DEFAULT_CART = {'product_mappings': {}, 'product_names': {}, 'debounce_seconds': 10, 'activated_at': None,
                'branch_aliases': {'94': ['فرع المحله', 'المحله', 'المحلة', 'المحلة الكبرى'],
                                  '301': ['فرع شبرا', 'شبرا الخيمة'], '307': ['فرع نبروه', 'نبروه'],
                                  '363': ['فرع المنصورة', 'المنصورة']}}


def valid_mapping():
    return {'987654321': {'quarter-feseekh': {'f:363': {'product_id': 11, 'quantity_mode': 'weight',
             'quantity_per_unit': '0.25', 'option_id': ''}}}}


class Fixture:
    def __init__(self, cached=True):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-auto-activation-')
        self.root = pathlib.Path(self.temp.name) / 'public_html'; self.root.mkdir(mode=0o700)
        (self.root / '.git').mkdir(mode=0o700)
        self.uid = os.geteuid(); self.calls = []; self.hook = None; self.fail = None
        self.current = {'orders': {'enabled': True, 'mode': 'review', 'model': 'gpt-6-luna', 'api_key': KEY,
                                  'automation_actor_id': None, 'allowed_branch_ids': [],
                                  'activation_message_id': 116, 'activation_event_id': 300,
                                  'future_extension': {'retained': True}},
                        'replies': {'enabled': True, 'access_token': TOKEN},
                        'cart': copy.deepcopy(DEFAULT_CART), 'fingerprint': hashlib.sha256(b'synthetic').hexdigest()}
        self.cutover = {'event_ceiling': 402, 'message_ceiling': 150, 'pending_runnable': 0,
                        'quarantines_retained': 2, 'activated_at': '2026-10-10 02:00:00',
                        'events_seen': 20, 'events_processed': 20, 'quarantined_events': 0}
        self.env = (b'APP_NAME="Unrelated app"\r\n# preserved exactly\r\nOTHER_SECRET="multiline\r\nWHATSAPP_ORDERS_MODE=not-a-setting\r\nend"\r\n'
                    + ('WHATSAPP_ORDERS_ENABLED=true\r\nWHATSAPP_ORDERS_MODE=review # mode comment\r\n'
                       'WHATSAPP_ORDERS_MODEL="gpt-6-luna"\r\nWHATSAPP_ORDERS_API_KEY="' + KEY + '"\r\n'
                       'WHATSAPP_REPLIES_ENABLED=true\r\nWHATSAPP_REPLIES_ACCESS_TOKEN="' + TOKEN + '"\r\n').encode())
        self.write('.env', self.env, 0o640)
        self.write(helper.CART_PATH, (ROOT / helper.CART_PATH).read_bytes(), 0o644)
        for path in helper.PROTECTED: self.write(path, b'<?php // protected fixture\n', 0o644)
        self.cache_original = None
        if cached:
            data = {'unrelated': {'nil': None, 'flag': False, 'int': 3, 'numeric_string': '003', 'nested': {'x': ['a', 2]}},
                    'app': {'key': 'synthetic-app-key'}, 'whatsapp': {'allowed_account_ids': ['468336579702269']},
                    'whatsapp_orders': self.current['orders'], 'whatsapp_replies': self.current['replies']}
            self.write_php(helper.CACHE_PATH, data, 0o640)
            self.cache_original = self.path(helper.CACHE_PATH).read_bytes()
        else: self.path(helper.CACHE_PATH).parent.mkdir(parents=True)
        self.before = {path: self.path(path).read_bytes() for path in ('.env', helper.CART_PATH, *helper.PROTECTED)}
        self.backups = None

    def close(self): self.temp.cleanup()
    def path(self, relative): return self.root / relative
    def write(self, relative, data, mode=0o600):
        path = self.path(relative); path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data); path.chmod(mode)
    def write_php(self, relative, value, mode=0o600):
        if not PHP: raise unittest.SkipTest('PHP CLI required')
        path = self.path(relative); path.parent.mkdir(parents=True, exist_ok=True)
        source = "<?php return "
        run = subprocess.run([PHP, '-r', '$a=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR);echo "<?php return ".var_export($a,true).";\\n";', json.dumps(value, ensure_ascii=True)], capture_output=True, check=True)
        path.write_bytes(run.stdout); path.chmod(mode)
    def php(self, arguments):
        if not PHP: raise unittest.SkipTest('PHP CLI required')
        run = subprocess.run([PHP] + arguments[1:], cwd=self.root, capture_output=True, timeout=20)
        return run.returncode, run.stdout
    def php_value(self, path):
        code, output = self.php(['php', '-r', 'echo json_encode(require $argv[1],JSON_THROW_ON_ERROR);', str(path)])
        if code != 0: raise AssertionError('PHP fixture value failed')
        return json.loads(output)
    def enable_real_php_boot(self):
        self.real_boot=True
        self.actor_allowed=True;self.branch_allowed=True;self.runtime_allowed=True;self.activation_guard_allowed=True;self.catalog_options=[]
        self.write('vendor/autoload.php',b'<?php // isolated fixture autoload\n')
        source=r'''<?php
namespace { class FixtureState {public static function read(){return json_decode(file_get_contents(getcwd().'/fixture-state.json'),true,64,JSON_THROW_ON_ERROR);}} }
namespace Illuminate\Support\Facades {class Schema {public static function hasTable($n){return true;}}}
namespace App\Models {class User {public static function withoutGlobalScopes(){return new class{public function find($id){$s=\FixtureState::read();return $s['actor_allowed']&&(string)$id==='1'?(object)['id'=>1,'account_type'=>'admin']:null;}};}}}
namespace App\Services\Dashboard {
class WhatsAppInboxConsumer{public const QUARANTINE_BATCH_RECHECK=true;}
class WhatsAppOrderWorkflow{private const AUTOMATIC_ACTIVATION_TIME_GUARD='whatsapp-auto-activation-time-v1';public function available(){return \FixtureState::read()['runtime_allowed'];}private function activationInstant(){return \FixtureState::read()['activation_guard_allowed']?\config('whatsapp_cart')['activated_at']:null;}}
class WhatsAppInboxAccess{public function actor($actor){if(!$actor||!\FixtureState::read()['actor_allowed'])throw new \RuntimeException();return $actor;}}
class TakeawayAccess{public function permissions($actor){return ['can_checkout'=>\FixtureState::read()['actor_allowed']];}public function branch($branch,$actor){if(!\FixtureState::read()['branch_allowed']||!in_array($branch,['f:94','f:301','f:307','f:363'],true))throw new \RuntimeException();return ['value'=>$branch,'kind'=>'f','id'=>(int)substr($branch,2)];}}
class TakeawayCatalog{public function listing($v,$actor){return ['items'=>[['id'=>11,'name'=>'فسيخ','quantity_mode'=>'select','available'=>true,'options'=>\FixtureState::read()['catalog_options']]],'pagination'=>['last_page'=>1]];}}
}
namespace { class FixtureKernel{public function bootstrap(){}public function all(){return ['whatsapp:process-orders'=>true];}}
class FixtureApp{public function make($class){if($class==='Illuminate\\Contracts\\Console\\Kernel')return new FixtureKernel;return new $class;}public function getCachedConfigPath(){return getcwd().'/bootstrap/cache/config.php';}}
function app($class){return (new FixtureApp)->make($class);}
function config($name){$cache=getcwd().'/bootstrap/cache/config.php';$values=is_file($cache)?require $cache:\FixtureState::read()['current'];if(isset($values['orders']))$values=['whatsapp_orders'=>$values['orders'],'whatsapp_replies'=>$values['replies'],'whatsapp_cart'=>$values['cart']];return $values[$name]??null;}
return new FixtureApp; }
'''
        self.write('bootstrap/app.php',source.encode())
    def runner(self, root, args, capture=False, timeout=60):
        self.calls.append(tuple(args))
        if self.hook: self.hook(self, args)
        if args[1:2] == ['-l']: return self.php(args)
        operation = args[2]
        if operation == helper.PREFLIGHT_PHP:
            if self.fail == 'preflight': return 1, b'SENSITIVE_OUTPUT_MUST_NOT_ESCAPE'
            if getattr(self,'real_boot',False):
                self.write('fixture-state.json',json.dumps({'current':self.current,'actor_allowed':self.actor_allowed,
                    'branch_allowed':self.branch_allowed,'runtime_allowed':self.runtime_allowed,'activation_guard_allowed':self.activation_guard_allowed,'catalog_options':self.catalog_options}).encode())
                return self.php(args)
            self.write_private(pathlib.Path(args[4]), json.dumps(self.current).encode())
        elif operation == helper.CUTOVER_PHP:
            if self.fail == 'history': return 1, b'SENSITIVE_OUTPUT_MUST_NOT_ESCAPE'
            self.write_private(pathlib.Path(args[3]), json.dumps(self.cutover).encode())
        elif operation == helper.STAGE_PHP:
            if self.fail == 'stage': return 1, b''
            return self.php(args)
        elif operation == helper.POSTFLIGHT_PHP:
            if self.fail == 'postflight': return 1, b''
            if getattr(self,'real_boot',False):return self.php(args)
            expected = json.loads(pathlib.Path(args[3]).read_bytes())
            actual = self.php_value(self.path(helper.CART_PATH))
            # PHP empty maps encode as arrays; normalize only these specified dictionary fields.
            for key in ('product_mappings', 'product_names'):
                if actual[key] == []: actual[key] = {}
            if actual != expected['cart']: return 1, b''
            env = helper.writer.parse_env(self.path('.env').read_bytes())[2]
            if env['WHATSAPP_ORDERS_MODE'][2] != 'auto' or env['WHATSAPP_ORDERS_API_KEY'][2] != KEY: return 1, b''
            if self.path(helper.CACHE_PATH).exists():
                cached = self.php_value(self.path(helper.CACHE_PATH))
                for key, value in expected['orders'].items():
                    if cached['whatsapp_orders'][key] != value: return 1, b''
                if cached['whatsapp_replies'] != self.current['replies']: return 1, b''
            return 0, b'{"automatic_ready":true,"network_calls":0,"orders_created":0}'
        else: raise AssertionError('unexpected operation')
        return 0, b''
    @staticmethod
    def write_private(path, content): path.write_bytes(content); path.chmod(0o600)
    def activate(self, mapping=None): return helper.activate(self.root, self.uid, '1', ['94','301','307','363'], mapping, self.runner)


class ActivationTests(unittest.TestCase):
    def fixture(self, **kwargs):
        item = Fixture(**kwargs); self.addCleanup(item.close); return item
    def mapping_file(self, item, data):
        path = pathlib.Path(item.temp.name) / 'public-mapping.json'; path.write_text(json.dumps(data)); path.chmod(0o600); return path
    def assert_unchanged(self, item):
        for path, content in item.before.items(): self.assertEqual(item.path(path).read_bytes(), content)
        if item.cache_original is not None: self.assertEqual(item.path(helper.CACHE_PATH).read_bytes(), item.cache_original)
        else: self.assertFalse(item.path(helper.CACHE_PATH).exists())

    def test_actual_cache_merge_preserves_all_unrelated_values_and_existing_credentials(self):
        item = self.fixture(); original = item.php_value(item.path(helper.CACHE_PATH))
        status, backup, receipt = item.activate()
        self.assertEqual(status, 'WHATSAPP_AUTOMATIC_CONFIGURED_TIMER_START_REQUIRED')
        cached = item.php_value(item.path(helper.CACHE_PATH))
        for key in original:
            if key != 'whatsapp_orders': self.assertEqual(cached[key], original[key])
        self.assertEqual(cached['whatsapp_orders']['future_extension'], original['whatsapp_orders']['future_extension'])
        self.assertEqual(cached['whatsapp_orders']['api_key'], KEY)
        self.assertEqual(cached['whatsapp_orders']['model'], 'gpt-6-luna')
        self.assertEqual(cached['whatsapp_cart']['activated_at'], '2026-10-10 02:00:00')
        self.assertEqual(cached['whatsapp_cart']['debounce_seconds'], 10)
        self.assertEqual(receipt['message_ceiling'], 150); self.assertEqual(receipt['event_ceiling'], 402)
        self.assertEqual(receipt['quarantines_retained'], 2)
        self.assertEqual(receipt['network_calls'], 0); self.assertEqual(receipt['orders_created'], 0)
        self.assertEqual((backup/'env.before').read_bytes(), item.env)
        self.assertEqual((backup/'config.before.php').read_bytes(), item.cache_original)
        self.assertEqual(item.path('.env').stat().st_mode & 0o777, 0o600)
        self.assertEqual(item.path(helper.CACHE_PATH).stat().st_mode & 0o777, 0o600)
        for path in helper.PROTECTED: self.assertEqual(item.path(path).read_bytes(), item.before[path])

    def test_uncached_configuration_works_without_creating_config_cache(self):
        item = self.fixture(cached=False); _, _, receipt = item.activate()
        self.assertTrue(receipt['automatic_ready']); self.assertFalse(item.path(helper.CACHE_PATH).exists())

    def test_every_unrelated_env_byte_and_identical_key_model_token_literal_preserved(self):
        item=self.fixture(); item.activate(); result=item.path('.env').read_bytes()
        preserved = item.env.split(b'WHATSAPP_ORDERS_ENABLED=true')[0]
        self.assertTrue(result.startswith(preserved)); self.assertIn(b'WHATSAPP_ORDERS_MODE="auto" # mode comment\r\n',result)
        for name in ('WHATSAPP_ORDERS_MODEL','WHATSAPP_ORDERS_API_KEY','WHATSAPP_REPLIES_ACCESS_TOKEN','WHATSAPP_REPLIES_ENABLED'):
            original=next(line for line in item.env.splitlines(keepends=True) if line.startswith(name.encode()+b'=')); self.assertIn(original,result)

    def test_explicit_mappings_and_public_names_source_and_cache(self):
        item=self.fixture(); document={'product_mappings':valid_mapping(),'product_names':{'987654321':{'quarter-feseekh':'ربع كيلو فسيخ'}}}
        path=self.mapping_file(item,document); _,_,receipt=item.activate(path)
        source=item.php_value(item.path(helper.CART_PATH));cached=item.php_value(item.path(helper.CACHE_PATH))['whatsapp_cart']
        self.assertEqual(source,cached);self.assertEqual(source['product_names'],document['product_names'])
        self.assertEqual(source['product_mappings'],document['product_mappings']);self.assertEqual(receipt['mapping_catalogs'],1)

    def test_existing_mappings_and_names_preserved_if_file_absent(self):
        item=self.fixture();item.current['cart']['product_mappings']=valid_mapping()
        item.current['cart']['product_names']={'987654321':{'quarter-feseekh':'ربع كيلو فسيخ'}}
        item.activate();actual=item.php_value(item.path(helper.CART_PATH))
        self.assertEqual(actual['product_mappings'],valid_mapping());self.assertEqual(actual['product_names'],item.current['cart']['product_names'])

    def test_empty_php_array_maps_are_accepted_and_normalized(self):
        item=self.fixture();item.current['cart']['product_mappings']=[];item.current['cart']['product_names']=[]
        self.assertTrue(item.activate()[2]['automatic_ready'])

    def test_actual_default_aliases_retained(self):
        item=self.fixture();item.activate();self.assertEqual(item.php_value(item.path(helper.CART_PATH))['branch_aliases'],DEFAULT_CART['branch_aliases'])

    def test_not_review_enabled_or_wrong_model_or_missing_credentials(self):
        for namespace,key,value in [('orders','mode','auto'),('orders','enabled',False),('orders','model','gpt-5.6-terra'),
                                    ('orders','api_key',''),('replies','access_token','')]:
            with self.subTest(key=key):
                item=self.fixture();item.current[namespace][key]=value
                with self.assertRaises(helper.SafeError):item.activate()
                self.assert_unchanged(item)

    def test_fresh_actor_branch_or_runtime_preflight_denial_no_projection(self):
        item=self.fixture();item.fail='preflight'
        with self.assertRaisesRegex(helper.SafeError,'ACTIVATION_REVIEW_ACTOR_BRANCH_OR_RUNTIME_NOT_READY'):item.activate()
        self.assertFalse(any(c[2]==helper.CUTOVER_PHP for c in item.calls));self.assert_unchanged(item)

    def test_uncleared_capture_history_leaves_all_configuration_unchanged(self):
        item=self.fixture();item.fail='history'
        with self.assertRaisesRegex(helper.SafeError,'HISTORY_NOT_DRAINED'):item.activate()
        self.assert_unchanged(item)

    def test_late_runnable_event_after_bounded_drain_refuses_activation(self):
        item=self.fixture();item.cutover['pending_runnable']=1
        with self.assertRaisesRegex(helper.SafeError,'HISTORY_NOT_DRAINED'):item.activate()
        self.assert_unchanged(item)

    def test_cutover_limits_and_timestamp_rejected(self):
        for key,value in [('events_seen',2001),('quarantines_retained',2001),('event_ceiling',-1),('message_ceiling','150'),
                          ('activated_at','2026-10-10T02:00:00Z'),('activated_at','2026-02-30 02:00:00'),
                          ('activated_at','2026-10-10 25:00:00'),('pending_runnable',False)]:
            with self.subTest(key=key):
                item=self.fixture();item.cutover[key]=value
                with self.assertRaises(helper.SafeError):item.activate()
                self.assert_unchanged(item)

    def test_failure_after_publication_restores_own_three_files(self):
        item=self.fixture();item.fail='postflight'
        with self.assertRaisesRegex(helper.SafeError,'ACTIVATION_CONFIGURATION_RESTORED'):item.activate()
        self.assert_unchanged(item)

    def test_external_postpublication_edit_retained_during_rollback(self):
        item=self.fixture();item.fail='postflight'
        def hook(f,args):
            if len(args)>2 and args[2]==helper.POSTFLIGHT_PHP:f.path('.env').write_bytes(b'EXTERNAL_EDIT=retained\n')
        item.hook=hook
        with self.assertRaisesRegex(helper.SafeError,'CONCURRENT_FILES_RETAINED'):item.activate()
        self.assertEqual(item.path('.env').read_bytes(),b'EXTERNAL_EDIT=retained\n')

    def test_configuration_edit_during_drain_refuses_publication(self):
        item=self.fixture()
        def hook(f,args):
            if len(args)>2 and args[2]==helper.CUTOVER_PHP:f.path('.env').write_bytes(f.env+b'CONCURRENT=1\n')
        item.hook=hook
        with self.assertRaisesRegex(helper.SafeError,'CHANGED_CONCURRENTLY'):item.activate()
        self.assertEqual(item.path(helper.CART_PATH).read_bytes(),item.before[helper.CART_PATH])

    def test_protected_source_alias_edit_and_restore_detected(self):
        item=self.fixture();path=item.path('config/whatsapp_orders.php');alias=path.parent/'private-retained.php';os.link(path,alias)
        def hook(f,args):
            if len(args)>2 and args[2]==helper.CUTOVER_PHP:
                original=alias.read_bytes();alias.write_bytes(original+b' ');alias.write_bytes(original)
        item.hook=hook
        with self.assertRaisesRegex(helper.SafeError,'CHANGED_CONCURRENTLY'):item.activate()

    def test_secret_symlink_hardlink_and_group_write_guards(self):
        for kind in ('symlink','hardlink','write'):
            with self.subTest(kind=kind):
                item=self.fixture();path=item.path('.env')
                if kind=='symlink':
                    other=path.parent/'env-source';path.rename(other);path.symlink_to(other)
                elif kind=='hardlink':os.link(path,path.parent/'env-alias')
                else:path.chmod(0o660)
                with self.assertRaises(helper.SafeError):item.activate()

    def test_unknown_modified_cart_source_refused(self):
        item=self.fixture();item.path(helper.CART_PATH).write_bytes(b'<?php return [];\n')
        with self.assertRaisesRegex(helper.SafeError,'CART_SOURCE_NEEDS_REVIEW'):item.activate()

    def test_old_initial_installer_source_hardlinks_are_supported(self):
        item=self.fixture();path=item.path(helper.CART_PATH);os.link(path,path.parent/'retained-source.php')
        self.assertTrue(item.activate()[2]['automatic_ready'])

    def test_env_and_cached_credential_drift_refused(self):
        item=self.fixture();item.current['orders']['api_key']='sk-proj-other-synthetic-only'
        with self.assertRaisesRegex(helper.SafeError,'CREDENTIALS_DIFFER'):item.activate()
        self.assert_unchanged(item)

    def test_duplicate_target_env_assignment_refused(self):
        item=self.fixture();item.path('.env').write_bytes(item.env+b'WHATSAPP_ORDERS_MODE=review\n')
        with self.assertRaisesRegex(helper.SafeError,'DUPLICATE_ORDERS_ENV_SETTING'):item.activate()

    def test_mapping_input_duplicate_json_or_symlink_refused(self):
        item=self.fixture();path=self.mapping_file(item,{});path.write_text('{"987":{},"987":{}}')
        with self.assertRaisesRegex(helper.SafeError,'JSON_INVALID'):item.activate(path)
        other=path.parent/'other.json';path.rename(other);path.symlink_to(other)
        with self.assertRaises(helper.SafeError):item.activate(path)

    def test_mapping_and_alias_validation_rejects_unsafe_values(self):
        invalid=[]
        for key,value in [('product_id',True),('quantity_mode','select'),('quantity_per_unit','0'),('quantity_per_unit','0.0001'),
                          ('quantity_per_unit','0.25'),('option_id','arbitrary')]:
            data=valid_mapping();line=data['987654321']['quarter-feseekh']['f:363'];line[key]=value
            if key=='quantity_per_unit' and value=='0.25':line['quantity_mode']='piece'
            invalid.append(data)
        invalid.append({'987654321':{'quarter-feseekh':{'f:999':valid_mapping()['987654321']['quarter-feseekh']['f:363']}}})
        for data in invalid:
            with self.subTest(data=data),self.assertRaises(helper.SafeError):helper.validate_mappings(data,['363'])
        item=self.fixture();item.current['cart']['branch_aliases']['363']=['المنصورة\n']
        with self.assertRaisesRegex(helper.SafeError,'INVALID_BRANCH_ALIASES'):item.activate()

    def test_product_names_bind_to_mapped_ids_and_reject_long_control_or_credentials(self):
        for title in ['x'*201,'  فسيخ','فسيخ\n',TOKEN,KEY]:
            with self.subTest(title=title),self.assertRaises(helper.SafeError):helper.validate_names({'987654321':{'quarter-feseekh':title}},valid_mapping())
        with self.assertRaises(helper.SafeError):helper.validate_names({'987654321':{'wrong':'فسيخ'}},valid_mapping())

    def test_ids_are_canonical_and_branch_scope_explicit(self):
        for value in ['01','0','1,1','1, 363','9223372036854775808','']:
            with self.subTest(value=value),self.assertRaises(helper.SafeError):helper.branches_argument(value)

    def test_no_processing_analysis_send_or_network_operation_invoked(self):
        item=self.fixture();item.activate();self.assertTrue(any(c[2]==helper.CUTOVER_PHP for c in item.calls))
        self.assertFalse(any('artisan' in c or any(v in ('curl','wget') for v in c) for c in item.calls))
        self.assertNotIn('->process(',helper.CUTOVER_PHP);self.assertNotIn('->analyze(',helper.CUTOVER_PHP)
        self.assertNotIn('->send(',helper.CUTOVER_PHP);self.assertIn("getDriverName()!=='mysql'",helper.CUTOVER_PHP)
        self.assertIn('QUARANTINE_BATCH_RECHECK',helper.PREFLIGHT_PHP)

    def test_embedded_php_programs_are_syntax_valid(self):
        item=self.fixture()
        for index,code in enumerate((helper.PREFLIGHT_PHP,helper.CUTOVER_PHP,helper.STAGE_PHP,helper.POSTFLIGHT_PHP)):
            path=item.root/('snippet'+str(index)+'.php');path.write_text('<?php\n'+code)
            self.assertEqual(item.php(['php','-l',str(path)])[0],0)

    def test_actual_php_pre_and_postflight_boot_effective_cached_settings(self):
        item=self.fixture();item.enable_real_php_boot()
        self.assertTrue(item.activate()[2]['automatic_ready'])
        current_file=next(pathlib.Path(c[4]) for c in item.calls if len(c)>2 and c[2]==helper.PREFLIGHT_PHP)
        state=json.loads(current_file.read_bytes())
        self.assertEqual(state['orders']['mode'],'review')
        self.assertEqual(item.php_value(item.path(helper.CACHE_PATH))['whatsapp_orders']['mode'],'auto')

    def test_actual_php_fresh_actor_branch_and_runtime_denials(self):
        for field in ('actor_allowed','branch_allowed','runtime_allowed'):
            with self.subTest(field=field):
                item=self.fixture();item.enable_real_php_boot();setattr(item,field,False)
                with self.assertRaisesRegex(helper.SafeError,'REVIEW_ACTOR_BRANCH_OR_RUNTIME_NOT_READY'):item.activate()
                self.assert_unchanged(item)

    def test_actual_php_rejects_unavailable_product_or_unconfigured_option(self):
        item=self.fixture();item.enable_real_php_boot();mapping=valid_mapping();mapping['987654321']['quarter-feseekh']['f:363']['product_id']=22
        with self.assertRaisesRegex(helper.SafeError,'REVIEW_ACTOR_BRANCH_OR_RUNTIME_NOT_READY'):item.activate(self.mapping_file(item,mapping))
        self.assert_unchanged(item)
        item=self.fixture();item.enable_real_php_boot();mapping=valid_mapping();mapping['987654321']['quarter-feseekh']['f:363']['option_id']='f:3:base'
        with self.assertRaisesRegex(helper.SafeError,'REVIEW_ACTOR_BRANCH_OR_RUNTIME_NOT_READY'):item.activate(self.mapping_file(item,mapping))
        self.assert_unchanged(item)

    def test_actual_php_activation_timestamp_guard_failure_rolls_back_publication(self):
        item=self.fixture();item.enable_real_php_boot();item.activation_guard_allowed=False
        with self.assertRaisesRegex(helper.SafeError,'ACTIVATION_CONFIGURATION_RESTORED'):item.activate()
        self.assert_unchanged(item)

    def test_shared_configuration_lock_refuses_competing_activation(self):
        item=self.fixture();parent=item.root.parent/'whatsapp-release-backups';parent.mkdir(mode=0o700)
        descriptor=os.open(parent/'orders-install.lock',os.O_RDWR|os.O_CREAT,0o600)
        try:
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(helper.SafeError,'ANOTHER_ORDERS'):item.activate()
        finally:os.close(descriptor)
        self.assert_unchanged(item)

    def test_secret_private_backup_modes_and_no_secret_receipt(self):
        item=self.fixture();_,backup,receipt=item.activate()
        self.assertEqual(backup.stat().st_mode&0o777,0o700)
        for name in ('env.before','config.before.php','current.json','inputs.json','cutover.json','activation-settings.json','receipt.json'):
            self.assertEqual((backup/name).stat().st_mode&0o777,0o600)
        self.assertNotIn(KEY,json.dumps(receipt));self.assertNotIn(TOKEN,json.dumps(receipt))


if __name__=='__main__': unittest.main()
