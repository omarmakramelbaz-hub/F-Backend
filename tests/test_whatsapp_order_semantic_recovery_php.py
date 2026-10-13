#!/usr/bin/env python3
"""Execute the publisher's current recovery PHP with real Laravel, SQLite and Crypt.

The genuine Workflow noteProjectionRevision callback and protected ticket
fingerprint are exercised. No order, AI, provider, HTTP, worker or production
application is invoked by these fixtures.
"""
import ast
import hashlib
import json
import os
import pathlib
import shutil
import stat
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'deployment/apply_whatsapp_order_semantic_repair.py'
PHP = os.environ.get('WHATSAPP_TEST_PHP', '/tmp/meta-php-runtime-20261013/php')
VENDOR = os.environ.get('WA_INBOX_TEST_VENDOR', '/tmp/wa-order-framework-vendor/autoload.php')
PREFIX = 'app/Services/Dashboard/'
SOURCES = (
    PREFIX+'WhatsAppNativePlaceholderHydration.php',
    'app/Support/WhatsAppOrderExtraction.php',
    PREFIX+'WhatsAppAcceptedReplyEvidence.php',
    PREFIX+'WhatsAppInboxMessage.php',
    PREFIX+'WhatsAppOrderAiProvider.php',
    PREFIX+'WhatsAppInboxConsumer.php',
    PREFIX+'WhatsAppReplyService.php',
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php',
    PREFIX+'WhatsAppOrderWorkflow.php',
)
IDS = (5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338)


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


def recovery_php():
    # Read on every invocation: the publisher may still be edited during review.
    nodes = [n for n in ast.parse(PUBLISHER.read_text()).body
             if isinstance(n, ast.Assign) and any(isinstance(t, ast.Name)
             and t.id == 'RECOVERY_PHP' for t in n.targets)]
    if len(nodes) != 1:
        raise AssertionError('One exact embedded recovery PHP is required')
    return ast.literal_eval(nodes[0].value)


BOOTSTRAP = r'''<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
$app=new Illuminate\Foundation\Application(getcwd());
Illuminate\Container\Container::setInstance($app);
$capsule=new Capsule($app);
$connection=['driver'=>'sqlite','database'=>getcwd().'/fixture.sqlite','prefix'=>'','foreign_key_constraints'=>true];
$capsule->addConnection($connection);$capsule->setAsGlobal();$capsule->bootEloquent();
$key=base64_decode('c2VtYW50aWMtcmVjb3ZlcnktZml4dHVyZS1rZXkhISE=',true);
$app->instance('config',new Repository([
 'app'=>['key'=>'base64:'.base64_encode($key),'cipher'=>'AES-256-CBC'],
 'database'=>['default'=>'default','connections'=>['default'=>$connection]],
 'whatsapp_orders'=>['enabled'=>true,'mode'=>'auto','activation_message_id'=>900,
 'activation_event_id'=>5000,'activated_at'=>'2026-10-12 17:00:00'],
]));
$app->instance('db',$capsule->getDatabaseManager());
$app->instance('db.schema',$capsule->getConnection()->getSchemaBuilder());
$app->instance('encrypter',new Encrypter($key,'AES-256-CBC'));
Facade::setFacadeApplication($app);
$app->instance(Illuminate\Contracts\Console\Kernel::class,new class {public function bootstrap(){}});
spl_autoload_register(static function($class){
 if(str_starts_with($class,'App\\')){$file=getcwd().'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require $file;}
});
Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys=ON');
if(getenv('WA_FIXTURE_ADVANCE_CLOCK')==='1'){
 $clockPath=getcwd().'/fixture-clock.txt';
 $clock=is_file($clockPath)?(int)file_get_contents($clockPath):1791892800;
 \Carbon\Carbon::setTestNow(\Carbon\Carbon::createFromTimestampUTC($clock));
}
Illuminate\Support\Facades\DB::listen(static function($query){
 file_put_contents(getcwd().'/query-log.jsonl',json_encode(['sql'=>$query->sql])."\n",FILE_APPEND);
 if(getenv('WA_FIXTURE_ADVANCE_CLOCK')==='1'){
  $clockPath=getcwd().'/fixture-clock.txt';
  $clock=is_file($clockPath)?(int)file_get_contents($clockPath):1791892800;
  $clock++;file_put_contents($clockPath,(string)$clock);
  \Carbon\Carbon::setTestNow(\Carbon\Carbon::createFromTimestampUTC($clock));
 }
});
return $app;
'''

CONTROL = r'''
require 'vendor/autoload.php';require 'bootstrap/app.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Schema\Blueprint;
use App\Support\WhatsAppInboxProtocol;
use App\Services\Dashboard\WhatsAppInboxConsumer;
use App\Services\Dashboard\WhatsAppNativePlaceholderHydration;
$ids=[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338];
$scope='468336579702269:515388018324075:';
function fixturePayload(array $message):array{return ['object'=>'whatsapp_business_account','entry'=>[[
 'id'=>'468336579702269','changes'=>[['field'=>'messages','value'=>[
 'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075','display_phone_number'=>'201285545554'],
 'messages'=>[$message]]]]]]];}
function fixtureMessage(int $id,bool $full):array {
 $m=['id'=>'wamid.semantic'.$id,'from'=>'201111111111','from_user_id'=>'US.semantic'.$id,
  'timestamp'=>$full?'1791831660':'1791831600','type'=>$full?'text':'unsupported'];
 if($full)$m['text']=['body'=>'Confirmed fictional fish order '.$id];
 else $m['errors']=[['code'=>131060,'title'=>'Message unavailable','message'=>'Message unavailable']];
 return $m;
}
function fixtureDump():array{
 $tables=['whatsapp_webhook_events','whatsapp_inbox_ingestion_failures','whatsapp_inbox_messages',
 'whatsapp_inbox_conversations','whatsapp_order_scans','whatsapp_order_drafts','fixture_orders','fixture_ai_calls'];
 $out=[];foreach($tables as $table)$out[$table]=DB::table($table)->orderBy($table==='whatsapp_inbox_ingestion_failures'?'event_id':($table==='whatsapp_order_scans'?'conversation_id':'id'))->get()->map(static fn($r)=>(array)$r)->all();
 return $out;
}
$action=$argv[1]??'';$id=(int)($argv[2]??7338);
if($action==='setup'){
 if(Illuminate\Foundation\Application::VERSION!=='8.83.29'||!extension_loaded('pdo_sqlite'))throw new RuntimeException('Real Laravel8 SQLite required');
 Schema::create('whatsapp_webhook_events',static function(Blueprint $t){$t->bigIncrements('id');$t->string('payload_hash',64)->unique();$t->longText('payload');$t->timestamp('received_at');$t->timestamp('processed_at')->nullable();});
 require 'migrations/inbox.php';(new CreateWhatsAppInboxTables())->up();
 require 'migrations/orders.php';(new CreateWhatsAppOrderDrafts())->up();
 foreach(['fixture_orders','fixture_ai_calls']as$table)Schema::create($table,static function(Blueprint$t){$t->bigIncrements('id');$t->string('unchanged');});
 foreach(['fixture_orders','fixture_ai_calls']as$table)DB::table($table)->insert(['id'=>1,'unchanged'=>'Never execute order or AI']);
 foreach($ids as$i=>$eventId){
  $placeholder=WhatsAppInboxProtocol::report(fixturePayload(fixtureMessage($eventId,false)),'468336579702269','515388018324075')['messages'][0];
  $cid=501+$i;$mid=1001+$i;$stamp='2026-10-12 20:20:00';
  DB::table('whatsapp_inbox_conversations')->insert(['id'=>$cid,'waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
   'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$placeholder['peer_identity'],Crypt::getKey()),
   'customer'=>Crypt::encryptString(json_encode(['name'=>'Fictional fixture','phone'=>$placeholder['peer_phone'],'user_id'=>$placeholder['peer_user_id']],JSON_THROW_ON_ERROR)),
   'first_message_at'=>$placeholder['sent_at'],'last_message_at'=>$placeholder['sent_at'],'created_at'=>$stamp,'updated_at'=>$stamp]);
  DB::table('whatsapp_inbox_messages')->insert(['id'=>$mid,'conversation_id'=>$cid,'message_key'=>hash('sha256','whatsapp-message-v1:'.$scope.$placeholder['message_id']),
   'type'=>'unsupported','direction'=>'inbound','source'=>'messages','content'=>Crypt::encryptString(json_encode($placeholder,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
   'sent_at'=>$placeholder['sent_at'],'created_at'=>$stamp,'updated_at'=>$stamp]);
  $plain=json_encode(fixturePayload(fixtureMessage($eventId,true)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  DB::table('whatsapp_webhook_events')->insert(['id'=>$eventId,'payload_hash'=>hash('sha256',$plain),'payload'=>Crypt::encryptString($plain),'received_at'=>$stamp,'processed_at'=>null]);
  DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$eventId,'reason'=>'CONFLICTING_MESSAGE','attempts'=>2,'last_attempted_at'=>$stamp]);
  DB::table('whatsapp_order_scans')->insert(['conversation_id'=>$cid,'analyzed_ceiling'=>$mid,'claimed_ceiling'=>0,'auto_floor'=>900,'attempts'=>0,'created_at'=>$stamp,'updated_at'=>$stamp]);
 }
 $plain=json_encode(fixturePayload(fixtureMessage(7215,true)),JSON_THROW_ON_ERROR);
 DB::table('whatsapp_webhook_events')->insert(['id'=>7215,'payload_hash'=>hash('sha256',$plain),'payload'=>Crypt::encryptString($plain),'received_at'=>'2026-10-12 20:20:00','processed_at'=>null]);
 DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>7215,'reason'=>'INVALID_PAYLOAD','attempts'=>9,'last_attempted_at'=>'2026-10-12 20:20:00']);
 echo json_encode(['version'=>Illuminate\Foundation\Application::VERSION,'foreign_keys'=>(int)DB::selectOne('PRAGMA foreign_keys')->foreign_keys]);
}elseif($action==='dump'){echo json_encode(fixtureDump(),JSON_THROW_ON_ERROR);
}elseif($action==='prove'){
 $checks=[];foreach($ids as$eventId){$raw=DB::table('whatsapp_webhook_events')->where('id',$eventId)->first();
  $dto=WhatsAppInboxProtocol::report(json_decode(Crypt::decryptString($raw->payload),true,512,JSON_THROW_ON_ERROR),'468336579702269','515388018324075')['messages'][0];
  $row=DB::table('whatsapp_inbox_messages')->where('message_key',hash('sha256','whatsapp-message-v1:'.$scope.$dto['message_id']))->first();
  $thread=DB::table('whatsapp_inbox_conversations')->where('id',$row->conversation_id)->first();
  $saved=json_decode(Crypt::decryptString($row->content),true,512,JSON_THROW_ON_ERROR);
  $checks[]=['event_id'=>$eventId,'proof'=>WhatsAppNativePlaceholderHydration::prove($thread,$row,$saved,$raw,DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$eventId)->exists()),
   'message'=>$saved,'row_type'=>$row->type];}
 echo json_encode($checks,JSON_THROW_ON_ERROR);
}elseif($action==='decrypt'){echo Crypt::decryptString(file_get_contents($argv[2]));
}elseif($action==='recover_first'){echo json_encode((new WhatsAppInboxConsumer())->recoverUnavailableMessages([$ids[0]]),JSON_THROW_ON_ERROR);
}elseif($action==='probe_real_callback'){
 // Invoke the authentic consumer path while exposing a bounded exception code.
 // Always roll this isolated probe's metadata back; never change runtime code.
 DB::beginTransaction();
 try{
  $event=DB::table('whatsapp_webhook_events')->where('id',$ids[0])->first();
  $dto=WhatsAppInboxProtocol::report(json_decode(Crypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR),'468336579702269','515388018324075')['messages'][0];
  $method=new ReflectionMethod(WhatsAppInboxConsumer::class,'persist');$method->setAccessible(true);
  $result=$method->invoke(new WhatsAppInboxConsumer(),$dto,Crypt::getKey(),$ids[0],$event->payload_hash,true);
  $out=['status'=>'CALLBACK_READY','result'=>$result,'ai_calls'=>0,'orders_created'=>0];
 }catch(Throwable$e){$code=$e->getMessage();$out=['status'=>'CALLBACK_FAILED','exception'=>get_class($e),
  'code'=>preg_match('/\A[A-Z_]{1,64}\z/',$code)?$code:'OTHER','ai_calls'=>0,'orders_created'=>0];
 }finally{DB::rollBack();}
 echo json_encode($out,JSON_THROW_ON_ERROR);
}elseif($action==='trigger_late'){
 DB::unprepared("CREATE TRIGGER fixture_late_failure BEFORE UPDATE OF type ON whatsapp_inbox_messages WHEN NEW.id=1013 BEGIN SELECT RAISE(ABORT,'fixture late write'); END");echo '{}';
}elseif($action==='busy_late'){
 DB::table('whatsapp_order_scans')->where('conversation_id',513)->update(['lease_until'=>now('UTC')->addHour(),'claim_nonce'=>str_repeat('a',64)]);echo '{}';
}elseif($action==='mutate_scan'){
 DB::table('whatsapp_order_scans')->where('conversation_id',513)->update(['attempts'=>5]);echo '{}';
}elseif($action==='mutate_marker'){
 $r=DB::table('whatsapp_inbox_messages')->where('id',1001)->first();$dto=json_decode(Crypt::decryptString($r->content),true,512,JSON_THROW_ON_ERROR);
 $dto['content']['hydration']['before_ciphertext_sha256']=str_repeat('0',64);
 DB::table('whatsapp_inbox_messages')->where('id',1001)->update(['content'=>Crypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR))]);echo '{}';
}elseif($action==='mutate_placeholder'){
 $r=DB::table('whatsapp_inbox_messages')->where('id',1013)->first();$dto=json_decode(Crypt::decryptString($r->content),true,512,JSON_THROW_ON_ERROR);
 $field=$argv[2]??'code';
 if($field==='code')$dto['content']['message']['errors'][0]['code']=131061;
 elseif($field==='peer')$dto['peer_phone']='201222222222';
 elseif($field==='source'){$dto['source']='standby';DB::table('whatsapp_inbox_messages')->where('id',1013)->update(['source'=>'standby']);}
 elseif($field==='time')$dto['content']['message']['timestamp']='1791820000';
 elseif($field==='context')$dto['content']['message']['context']=['id'=>'wamid.different'];
 elseif($field==='old_text')$dto['text']='Existing real content';
 DB::table('whatsapp_inbox_messages')->where('id',1013)->update(['content'=>Crypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR))]);echo '{}';
}elseif($action==='mutate_raw'){
 $r=DB::table('whatsapp_webhook_events')->where('id',$id)->first();$raw=json_decode(Crypt::decryptString($r->payload),true,512,JSON_THROW_ON_ERROR);
 $field=$argv[3]??'hash';$m=&$raw['entry'][0]['changes'][0]['value']['messages'][0];
 if($field==='time')$m['timestamp']='1791835261';
 elseif($field==='outbound'){$raw['entry'][0]['changes'][0]['field']='smb_message_echoes';$m['to']=$m['from'];$m['to_user_id']=$m['from_user_id'];unset($m['from'],$m['from_user_id']);$raw['entry'][0]['changes'][0]['value']['message_echoes']=[$m];unset($raw['entry'][0]['changes'][0]['value']['messages']);}
 elseif($field==='account')$raw['entry'][0]['id']='1000000000000000';
 elseif($field==='duplicate'){$extra=$m;$extra['id']='wamid.extraCandidate';$raw['entry'][0]['changes'][0]['value']['messages'][]=$extra;}
 elseif($field==='errors')$m['errors']=[['code'=>131060]];
 $plain=json_encode($raw,JSON_THROW_ON_ERROR);DB::table('whatsapp_webhook_events')->where('id',$id)->update([
 'payload'=>Crypt::encryptString($plain),'payload_hash'=>$field==='hash'?str_repeat('0',64):hash('sha256',$plain)]);echo '{}';
}elseif($action==='sealed'){
 $state=$argv[2]??'sealed';DB::table('whatsapp_order_drafts')->insert(['id'=>1,'conversation_id'=>513,'evidence_floor'=>900,'evidence_ceiling'=>1013,
 'evidence_hash'=>str_repeat('b',64),'status'=>$state==='dispatched'?'DISPATCHED':'READY','sealed_payload'=>$state==='sealed'?Crypt::encryptString('{}'):null,
 'ticket_id'=>$state==='ticket'?88:null,'customer_command_key'=>'00000000-0000-4000-8000-000000000001','ticket_command_key'=>'00000000-0000-4000-8000-000000000002',
 'created_at'=>'2026-10-12 20:20:00','updated_at'=>'2026-10-12 20:20:00']);echo '{}';
}else throw new RuntimeException('Unknown fixture action');
'''


class RecoveryFixture:
    def __init__(self, directory, advance_clock=False):
        self.root = pathlib.Path(directory)/'app'
        self.root.mkdir(mode=0o700)
        self.advance_clock = advance_clock
        self.pins = {}
        for name in SOURCES:
            if name.endswith(('WhatsAppNativePlaceholderHydration.php','WhatsAppInboxMessage.php','WhatsAppInboxConsumer.php','WhatsAppOrderWorkflow.php')):
                data = (ROOT/name).read_bytes()
            else:
                namespace = 'App\\'+str(pathlib.PurePosixPath(name).parent)[4:].replace('/','\\')
                cls = pathlib.PurePosixPath(name).stem
                data = ('<?php namespace '+namespace+'; final class '+cls+' { public function __call($m,$a){throw new \\RuntimeException("UNEXPECTED_ACTION");} }\n').encode()
            self.write(name,data)
            self.pins[name] = blob(data)
        for name in ('app/Support/WhatsAppInboxProtocol.php', PREFIX+'WhatsAppInboxAccess.php', PREFIX+'WhatsAppInboxQuarantinedEvent.php', PREFIX+'PosServiceTicket.php'):
            self.write(name,(ROOT/name).read_bytes())
        self.write('migrations/inbox.php',(ROOT/'database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php').read_bytes())
        self.write('migrations/orders.php',(ROOT/'database/migrations/2026_10_10_000002_create_whatsapp_order_drafts.php').read_bytes())
        vendor = pathlib.Path(VENDOR)
        if vendor.is_dir(): vendor /= 'autoload.php'
        self.write('vendor/autoload.php', ('<?php require '+json.dumps(str(vendor))+';\n').encode())
        self.write('bootstrap/app.php',BOOTSTRAP.encode())
        self.write('.env',b'UNCHANGED=1\nWHATSAPP_ORDERS_MODE=auto\n',0o600)
        self.write('config/whatsapp_orders.php',b'<?php return ["mode"=>"auto","activation_message_id"=>900];\n')
        self.write('bootstrap/cache/config.php',b'<?php return ["sentinel"=>true];\n',0o600)
        self.write('fixture.sqlite',b'',0o600)
        self.backup_parent = self.root.parent/'whatsapp-release-backups'
        self.backup_parent.mkdir(mode=0o700)
        self.backup = self.backup_parent/'order-semantic-fixture'
        self.backup.mkdir(mode=0o700)
        self.backup_arg = str(self.root)+'/../whatsapp-release-backups/'+self.backup.name
        self.config = self.configuration_snapshot()
        result = self.control('setup')
        if result != {'version':'8.83.29','foreign_keys':1}:
            raise AssertionError(result)

    def write(self,name,data,mode=0o644):
        path=self.root/name
        path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(data);path.chmod(mode)

    def run_php(self,program,args,expect_success=True):
        env=os.environ.copy()
        env['WA_FIXTURE_ADVANCE_CLOCK']='1' if self.advance_clock else '0'
        result=subprocess.run([PHP,'-r',program,*map(str,args)],cwd=self.root,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30,env=env)
        if expect_success and result.returncode:
            raise AssertionError('Fixture PHP failed: '+result.stdout.decode(errors='replace')+' '+result.stderr.decode(errors='replace'))
        return result

    def control(self,*args):
        return json.loads(self.run_php(CONTROL,args).stdout)

    def configuration_snapshot(self):
        result={}
        for name in ('.env','config/whatsapp_orders.php','bootstrap/cache/config.php'):
            p=self.root/name;s=p.stat()
            result[name]=(p.read_bytes(),s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode),s.st_dev,s.st_ino,s.st_nlink,s.st_mtime_ns,s.st_ctime_ns)
        return result

    def phase(self,phase,expected='',expect_success=True,ids=None,pins=None,backup=None):
        result=self.run_php(recovery_php(),[phase,json.dumps(self.pins if pins is None else pins),
            json.dumps(IDS if ids is None else ids),self.backup_arg if backup is None else backup,expected],expect_success)
        return result.returncode,json.loads(result.stdout)

    def preflight(self):
        return self.phase('preflight')[1]

    def recover(self,scope_hash):
        return self.phase('recover',scope_hash)[1]


class SemanticRecoveryPhpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which(PHP) and not pathlib.Path(PHP).is_file():
            raise RuntimeError('WHATSAPP_TEST_PHP must identify a real PHP8 runtime')
        v=pathlib.Path(VENDOR)
        if v.is_dir():v/='autoload.php'
        if not v.is_file():raise RuntimeError('WA_INBOX_TEST_VENDOR must identify real Laravel8.83.29 dependencies')

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='wa-semantic-php-')
        self.f=RecoveryFixture(self.temp.name)

    def tearDown(self):self.temp.cleanup()

    def reject_unchanged(self,phase='preflight',expected=''):
        before=self.f.control('dump')
        code,value=self.f.phase(phase,expected,expect_success=False)
        self.assertNotEqual(code,0)
        self.assertEqual(value,{'status':'SEMANTIC_RECOVERY_NEEDS_REVIEW','committed':False,'ai_calls':0,'orders_created':0})
        self.assertEqual(self.f.control('dump'),before)
        self.assertEqual(self.f.configuration_snapshot(),self.f.config)
        return value

    def test_preflight_is_real_scoped_read_only_without_backup_writes(self):
        before=self.f.control('dump');value=self.f.preflight()
        self.assertEqual(value['status'],'SEMANTIC_RECOVERY_PREFLIGHT_READY')
        self.assertEqual((value['total'],value['pending'],value['ai_calls'],value['orders_created']),(13,13,0,0))
        self.assertRegex(value['scope_hash'],r'\A[a-f0-9]{64}\Z')
        self.assertEqual(self.f.control('dump'),before)
        self.assertEqual(list(self.f.backup.iterdir()),[])

    def test_real_workflow_callback_has_no_hidden_exception_and_probe_rolls_back(self):
        before=self.f.control('dump')
        self.assertEqual(self.f.control('probe_real_callback'),{
            'status':'CALLBACK_READY','result':'hydrated','ai_calls':0,'orders_created':0})
        self.assertEqual(self.f.control('dump'),before)
        self.assertEqual(self.f.configuration_snapshot(),self.f.config)

    def test_real_workflow_recovery_preserves_proof_while_clock_advances_per_sql_query(self):
        with tempfile.TemporaryDirectory(prefix='wa-semantic-clock-') as t:
            f=RecoveryFixture(t,advance_clock=True)
            before=f.control('dump');pre=f.preflight()
            clock_before=int(f.root.joinpath('fixture-clock.txt').read_text())
            value=f.recover(pre['scope_hash'])
            clock_after=int(f.root.joinpath('fixture-clock.txt').read_text())
            self.assertGreater(clock_after-clock_before,100)
            self.assertEqual(value['metrics']['messages_hydrated'],13)
            self.assertEqual(value['metrics']['errors'],0)
            proofs=f.control('prove');self.assertTrue(all(p['proof'] for p in proofs))
            after=f.control('dump');raw={r['id']:r for r in after['whatsapp_webhook_events']}
            for proof in proofs:
                self.assertEqual(raw[proof['event_id']]['processed_at'],proof['message']['content']['hydration']['at_utc'])
            self.assertEqual(after['fixture_orders'],before['fixture_orders'])
            self.assertEqual(after['fixture_ai_calls'],before['fixture_ai_calls'])
            self.assertEqual(f.configuration_snapshot(),f.config)

    def test_recovers_exact_13_authentic_placeholders_with_encrypted_before_images(self):
        before=self.f.control('dump');value=self.f.recover(self.f.preflight()['scope_hash']);after=self.f.control('dump')
        self.assertEqual(value['status'],'SEMANTIC_RECOVERY_READY');self.assertTrue(value['committed'])
        self.assertEqual((value['ai_calls'],value['orders_created'],value['pending']),(0,0,0))
        self.assertEqual(value['metrics'],{'events_seen':13,'events_processed':13,'events_skipped':0,
            'messages_inserted':0,'messages_replayed':0,'messages_hydrated':13,'quarantined_events':0,
            'ignored_records':0,'status_records':0,'errors':0})
        self.assertEqual(len(after['whatsapp_inbox_messages']),13)
        proofs=self.f.control('prove');self.assertTrue(all(p['proof'] and p['row_type']=='text' for p in proofs))
        for proof in proofs:
            dto=proof['message'];self.assertEqual(dto['content']['hydration']['code'],131060)
            self.assertEqual(dto['text'],'Confirmed fictional fish order '+str(proof['event_id']))
        for old,new in zip(before['whatsapp_webhook_events'],after['whatsapp_webhook_events']):
            self.assertEqual({k:v for k,v in old.items() if k!='processed_at'},{k:v for k,v in new.items() if k!='processed_at'})
            if old['id'] in IDS:self.assertIsNotNone(new['processed_at'])
            else:self.assertEqual(old,new)
        self.assertEqual(after['whatsapp_inbox_ingestion_failures'],[r for r in before['whatsapp_inbox_ingestion_failures'] if r['event_id']==7215])
        self.assertEqual(after['fixture_orders'],before['fixture_orders']);self.assertEqual(after['fixture_ai_calls'],before['fixture_ai_calls'])
        self.assertEqual(self.f.configuration_snapshot(),self.f.config)
        encrypted=self.f.backup/'recovery-before-images.enc'
        self.assertNotIn(b'Confirmed fictional fish order',encrypted.read_bytes());self.assertNotIn(b'whatsapp_webhook_events',encrypted.read_bytes())
        saved=json.loads(self.f.run_php(CONTROL,['decrypt',encrypted]).stdout)
        self.assertEqual(saved['events'],[r for r in before['whatsapp_webhook_events'] if r['id'] in IDS])
        self.assertEqual(list(saved['messages'].values()),before['whatsapp_inbox_messages'])
        self.assertEqual(set(p.name for p in self.f.backup.iterdir()),{'recovery-before-images.enc','recovery-intent.json','recovery-complete.json'})
        for p in self.f.backup.iterdir():self.assertEqual(stat.S_IMODE(p.stat().st_mode),0o600)

    def test_processed_resume_proves_every_marker_and_is_idempotent(self):
        self.f.recover(self.f.preflight()['scope_hash']);before=self.f.control('dump')
        self.f.backup=self.f.backup_parent/'order-semantic-resume';self.f.backup.mkdir(mode=0o700)
        self.f.backup_arg=str(self.f.root)+'/../whatsapp-release-backups/'+self.f.backup.name
        pre=self.f.preflight();self.assertEqual(pre['pending'],0)
        value=self.f.recover(pre['scope_hash'])
        self.assertEqual((value['metrics']['events_skipped'],value['metrics']['messages_hydrated']),(13,0))
        self.assertEqual(self.f.control('dump'),before)

    def test_valid_partial_resume_hydrates_remaining_12_only(self):
        first=self.f.control('recover_first');self.assertEqual(first['messages_hydrated'],1)
        first_row=self.f.control('dump')['whatsapp_inbox_messages'][0]
        pre=self.f.preflight();self.assertEqual(pre['pending'],12)
        value=self.f.recover(pre['scope_hash']);self.assertEqual((value['metrics']['events_skipped'],value['metrics']['messages_hydrated']),(1,12))
        self.assertEqual(self.f.control('dump')['whatsapp_inbox_messages'][0],first_row)
        self.assertTrue(all(p['proof'] for p in self.f.control('prove')))

    def test_corrupt_processed_marker_refuses_resume_without_mutation(self):
        self.f.control('recover_first');self.f.control('mutate_marker');self.reject_unchanged()

    def test_scope_hash_cas_rejects_scan_change_after_preflight(self):
        pre=self.f.preflight();self.f.control('mutate_scan')
        self.reject_unchanged('recover',pre['scope_hash'])
        self.assertEqual(list(self.f.backup.iterdir()),[])

    def test_late_sql_failure_rolls_back_all_13_and_keeps_encrypted_intent(self):
        self.f.control('trigger_late');pre=self.f.preflight()
        self.reject_unchanged('recover',pre['scope_hash'])
        self.assertEqual(set(p.name for p in self.f.backup.iterdir()),{'recovery-before-images.enc','recovery-intent.json'})

    def test_late_busy_projection_rolls_back_earlier_12(self):
        self.f.control('busy_late');pre=self.f.preflight()
        self.reject_unchanged('recover',pre['scope_hash'])

    def test_pending_invalid_placeholder_code_fails_closed(self):
        self.f.control('mutate_placeholder','code');self.reject_unchanged()

    def test_pending_identity_context_source_and_timestamp_conflicts_never_mutate(self):
        for field in ('peer','source','time','context','old_text'):
            with self.subTest(field=field),tempfile.TemporaryDirectory(prefix='wa-semantic-negative-') as t:
                f=RecoveryFixture(t);f.control('mutate_placeholder',field);before=f.control('dump')
                code,pre=f.phase('preflight',expect_success=False)
                if code==0:
                    code,value=f.phase('recover',pre['scope_hash'],expect_success=False)
                    self.assertNotEqual(code,0);self.assertFalse(value['committed'])
                self.assertEqual(f.control('dump'),before)

    def test_raw_hash_mismatch_refuses_without_mutation(self):
        self.f.control('mutate_raw',7338,'hash');self.reject_unchanged()

    def test_raw_out_of_scope_direction_duplicates_and_errors_refuse(self):
        for field in ('outbound','account','duplicate','errors'):
            with self.subTest(field=field),tempfile.TemporaryDirectory(prefix='wa-semantic-raw-') as t:
                f=RecoveryFixture(t);f.control('mutate_raw',7338,field);before=f.control('dump')
                code,value=f.phase('preflight',expect_success=False)
                self.assertNotEqual(code,0);self.assertFalse(value['committed']);self.assertEqual(f.control('dump'),before)

    def test_corrected_native_timestamp_outside_hour_fails_closed(self):
        self.f.control('mutate_raw',7338,'time');before=self.f.control('dump')
        code,pre=self.f.phase('preflight',expect_success=False)
        if code==0:self.reject_unchanged('recover',pre['scope_hash'])
        self.assertEqual(self.f.control('dump'),before)

    def test_sealed_dispatched_and_ticket_evidence_cannot_be_rehydrated(self):
        for state in ('sealed','dispatched','ticket'):
            with self.subTest(state=state),tempfile.TemporaryDirectory(prefix='wa-semantic-sealed-') as t:
                f=RecoveryFixture(t);f.control('sealed',state);before=f.control('dump')
                code,value=f.phase('preflight',expect_success=False)
                self.assertNotEqual(code,0);self.assertFalse(value['committed']);self.assertEqual(f.control('dump'),before)

    def test_fixed_event_allowlist_cannot_be_extended_to_other_quarantine(self):
        before=self.f.control('dump');code,value=self.f.phase('preflight',ids=[*IDS,7215],expect_success=False)
        self.assertNotEqual(code,0);self.assertFalse(value['committed']);self.assertEqual(self.f.control('dump'),before)

    def test_entire_candidate_pin_set_verified_before_bootstrap(self):
        pins=dict(self.f.pins);pins[SOURCES[-1]]='0'*40
        self.f.root.joinpath('query-log.jsonl').unlink()
        code,value=self.f.phase('preflight',pins=pins,expect_success=False)
        self.assertNotEqual(code,0);self.assertFalse(value['committed'])
        self.assertFalse(self.f.root.joinpath('query-log.jsonl').exists())

    def test_backup_permissions_and_scope_are_enforced_before_any_recovery(self):
        pre=self.f.preflight();self.f.backup.chmod(0o755)
        self.reject_unchanged('recover',pre['scope_hash'])
        self.f.backup.chmod(0o700)
        code,value=self.f.phase('recover',pre['scope_hash'],backup=str(self.f.root.parent),expect_success=False)
        self.assertNotEqual(code,0);self.assertFalse(value['committed'])

    def test_recovery_cannot_overwrite_existing_private_journal(self):
        pre=self.f.preflight();path=self.f.backup/'recovery-before-images.enc'
        path.write_bytes(b'existing private journal');path.chmod(0o600)
        self.reject_unchanged('recover',pre['scope_hash'])
        self.assertEqual(path.read_bytes(),b'existing private journal')


if __name__=='__main__':unittest.main()
