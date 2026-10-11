#!/usr/bin/env python3
"""Exercise the preflight's secret-safe output against an isolated PHP runtime."""
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest

SOURCE = pathlib.Path(__file__).absolute().parent.parent / 'deployment/whatsapp_automatic_preflight.php'
PHP = os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php')

FIXTURE = r'''<?php
namespace Illuminate\Support\Facades {
 class Schema { public static function hasTable($name) {return false;} }
 class DB { public static function __callStatic($name,$args) {throw new \RuntimeException('UNEXPECTED_DATABASE_CALL');} }
}
namespace App\Models {
 class User { public static function withoutGlobalScopes(){return new self;} public function find($id){return (object)['id'=>1];} }
}
namespace App\Services\Dashboard {
 class WhatsAppInboxAccess { public function actor($actor){return $actor;} }
 class WhatsAppInboxConsumer {public const QUARANTINE_BATCH_RECHECK=true;}
 class WhatsAppOrderWorkflow {public const AUTOMATIC_ACTIVATION_TIME_GUARD='whatsapp-auto-activation-time-v1'; public function available(){return true;}}
 class TakeawayAccess {public function permissions($a){return ['can_checkout'=>true];} public function branch($b,$a){return ['name'=>'Branch '.substr($b,2)];}}
 class PhoneDelivery {public function settings($b,$a){return ['ready'=>true];}}
 class TakeawayCatalog {public function listing($v,$a){return ['items'=>[['id'=>15,'available'=>true,'name'=>'PRIVATE_PRODUCT_SENTINEL']], 'pagination'=>['last_page'=>1]];}}
 class WhatsAppOrderAiProvider {public function extract($v){throw new \RuntimeException('UNEXPECTED_AI_CALL');}}
}
namespace {
 $GLOBALS['config']=[
  'whatsapp_orders'=>['enabled'=>true,'mode'=>'review','model'=>'gpt-6-luna','api_key'=>'PRIVATE_API_KEY_SENTINEL','automation_actor_id'=>'1','allowed_branch_ids'=>['94','301','307','363'],'activation_message_id'=>'123','activation_event_id'=>'456'],
  'whatsapp_replies'=>['enabled'=>true,'access_token'=>'PRIVATE_META_TOKEN_SENTINEL'],
  'whatsapp_cart'=>['debounce_seconds'=>10,'product_mappings'=>['627343999792522'=>['PRIVATE_RETAILER_SENTINEL'=>['f:363'=>['product_id'=>'15']]]],'product_names'=>[]],
  'unrelated'=>(object)['secret'=>'PRIVATE_OBJECT_SENTINEL']
 ];
 class FixtureConfig {public function all(){return $GLOBALS['config'];}}
 function config($key=null,$default=null){return $key===null?new FixtureConfig:($GLOBALS['config'][$key]??$default);}
 class FixtureKernel {public function bootstrap(){}public function all(){return ['whatsapp:process-orders'=>true,'whatsapp:consume-inbox'=>true];}}
 class FixtureApp {public function make($class){return new FixtureKernel;}public function getCachedConfigPath(){return getcwd().'/bootstrap/cache/config.php';}}
 function app($class){return new $class;}
}
'''


@unittest.skipUnless(PHP, 'Set WHATSAPP_TEST_PHP for the isolated PHP redaction regression')
class PreflightTest(unittest.TestCase):
    def test_read_only_report_redacts_credentials_objects_and_product_payloads(self):
        with tempfile.TemporaryDirectory() as folder:
            root=pathlib.Path(folder)
            for name in ('vendor','bootstrap/cache'):
                (root/name).mkdir(parents=True,exist_ok=True)
            (root/'vendor/autoload.php').write_text(FIXTURE)
            (root/'bootstrap/app.php').write_text('<?php return new FixtureApp;')
            (root/'bootstrap/cache/config.php').write_text('<?php return ["unrelated"=>\\Fixture\\CacheValue::class];')
            (root/'.env').write_text('PRIVATE_ENV_SECRET_SENTINEL')
            source=SOURCE.read_text(); marker="chdir('/home/fasakha/public_html')"
            self.assertEqual(source.count(marker),1)
            fixture_script=root/'preflight.php'
            fixture_script.write_text(source.replace(marker,'chdir('+json.dumps(str(root))+')'))
            result=subprocess.run([PHP,str(fixture_script)],capture_output=True,text=True,timeout=15)
            self.assertEqual(result.returncode,0,result.stderr)
            for secret in ('PRIVATE_API_KEY_SENTINEL','PRIVATE_META_TOKEN_SENTINEL','PRIVATE_OBJECT_SENTINEL',
                           'PRIVATE_RETAILER_SENTINEL','PRIVATE_PRODUCT_SENTINEL','PRIVATE_ENV_SECRET_SENTINEL'):
                self.assertNotIn(secret,result.stdout)
            report=json.loads(result.stdout)
            self.assertEqual(report['diagnostic'],'WHATSAPP_AUTOMATIC_READ_ONLY_PREFLIGHT')
            for field in ('network_requests','ai_calls','messages_sent','orders_created','database_writes','configuration_writes'):
                self.assertEqual(report[field],0)
            self.assertEqual(report['configuration']['mode'],'review')
            self.assertTrue(report['configuration']['api_key_configured'])
            self.assertTrue(report['configuration']['whatsapp_token_configured'])
            self.assertEqual(report['cache_shape']['objects'],1)
            self.assertEqual(report['cache_shape']['fully_qualified_names'],1)
            self.assertNotIn('blob',report['private_file_metadata']['env'])
            self.assertNotIn('blob',report['private_file_metadata']['config_cache'])
            self.assertEqual(report['catalog_setup']['mapping_entries'],1)
            self.assertEqual(report['actor_and_branches']['status'],'READY')
            self.assertEqual(report['actor_and_branches']['branches'][-1]['mapping_entries_checked'],1)
            self.assertEqual(report['actor_and_branches']['branches'][-1]['mapping_products_unavailable_or_missing'],0)
            self.assertEqual((root/'.env').read_text(),'PRIVATE_ENV_SECRET_SENTINEL')


if __name__=='__main__':
    unittest.main()
