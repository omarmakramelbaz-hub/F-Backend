#!/usr/bin/env python3
"""Read only the fixed failed recovery's receipts and scoped proof predicates."""
import argparse
import ast
import dataclasses
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import types

ROOT = pathlib.Path('/home/fasakha/public_html')
BACKUP_NAME = 'order-semantic-ngb77kbf'
PIPELINE_NAME = 'semantic-pipeline-state-a_wh7l9m'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
PUBLISHER_BLOB = 'ad881bc4529ce112890f1e73f1c323ab6664ea60'
ROOT_BLOB = 'be981e4cf3bda3e4d45aad591a8d0e5dace16f62'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
ACCESS_BLOB = '186789d42113e81818de8d82a2327e3ee9d8d0b5'
IDS = [5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338]
UNITS = tuple('fasakhansta-whatsapp-'+v for v in ('capture.timer','capture.service','orders.timer','orders.service'))
METRICS = ('events_seen','events_processed','events_skipped','messages_inserted','messages_replayed',
           'messages_hydrated','quarantined_events','ignored_records','status_records','errors')
REASONS = ('ARGUMENT_SHAPE','UNEXPECTED_SOURCE_PRESENCE','SOURCE_TYPE_OR_PATH','SOURCE_PIN',
           'FIXED_EVENT_COUNT','RAW_PAYLOAD_SIZE','RAW_HASH_OR_PLAIN_SIZE','RAW_NORMALIZATION',
           'INCOMING_TEXT_SHAPE','NATIVE_ROW_MISSING','SCOPED_THREAD_MISSING','PENDING_FAILURE',
           'PROCESSED_STATE','ENCRYPTED_COLUMN_SIZE','SAVED_IDENTITY','PLACEHOLDER_SHAPE',
           'PROCESSED_HYDRATION_PROOF','NATIVE_TYPE','DRAFT_COUNT','DRAFT_SIZE','PROTECTED_DRAFT','BUNDLE_SIZE')


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


def signature(s):
    return (s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)


def private_file(path, uid, limit, read=True, absent_ok=False, ancestry_uid=None):
    """Stable, bounded descriptor reads; never follow private receipt aliases."""
    ancestry_uid=uid if ancestry_uid is None else ancestry_uid
    for ancestor in reversed(path.parents):
        s=ancestor.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid not in (0,uid,ancestry_uid):
            raise ValueError('PRIVATE_PATH')
    parent=path.parent.lstat()
    if parent.st_uid!=uid or stat.S_IMODE(parent.st_mode)!=0o700:
        raise ValueError('PRIVATE_DIRECTORY')
    try: before=path.lstat()
    except FileNotFoundError:
        if absent_ok:return None,None
        raise
    if (not stat.S_ISREG(before.st_mode) or before.st_uid!=uid or before.st_nlink!=1
            or stat.S_IMODE(before.st_mode)!=0o600 or before.st_size>limit):
        raise ValueError('PRIVATE_FILE')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        if signature(os.fstat(fd))!=signature(before):raise ValueError('PRIVATE_RACE')
        data=None
        if read:
            with os.fdopen(fd,'rb',closefd=False) as stream:data=stream.read(limit+1)
            if len(data)>limit:raise ValueError('PRIVATE_SIZE')
        if signature(os.fstat(fd))!=signature(before) or signature(path.lstat())!=signature(before):
            raise ValueError('PRIVATE_RACE')
        return data,{'exists':True,'bytes':before.st_size}
    finally:os.close(fd)


def selected_namespace(source, wanted, namespace):
    tree=ast.parse(source)
    chosen=[]
    for node in tree.body:
        name=node.name if isinstance(node,(ast.FunctionDef,ast.ClassDef)) else None
        if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
            name=node.targets[0].id
        if name in wanted:chosen.append(node)
    if len(chosen)!=len(wanted):raise ValueError('READ_ONLY_DEFINITIONS')
    exec(compile(ast.Module(body=chosen,type_ignores=[]),'<verified-read-only>','exec'),namespace)
    return namespace


def readonly_guards(publisher, base_source):
    # Load only dataclasses/read functions. No installer, lock, write or main body.
    base_ns={'__name__':__name__,'dataclass':dataclasses.dataclass,'pathlib':pathlib,'os':os,'stat':stat}
    selected_namespace(base_source,{'SafeError','Snapshot','safe_ancestors','snapshot'},base_ns)
    base=types.SimpleNamespace(**base_ns)
    ns={'os':os,'stat':stat,'pathlib':pathlib,'hashlib':hashlib,'json':json,'base':base,'SafeError':base.SafeError}
    selected_namespace(publisher,{'PREFIX','SOURCE_PINS','PROTECTED_PINS','ROOT_READ_ONLY_CONFIGURATION','metadata',
        'root_configuration_snapshot','guarded_snapshot','configuration_snapshot','configuration_fingerprint','RECOVERY_PHP'},ns)
    return ns


ROW_DIAGNOSTICS = r'''
   $projected=false;$projectedStatus=null;
   if($message->type==='unsupported')try{
    $p=(new \App\Services\Dashboard\WhatsAppInboxMessage())->projected($thread,$message);
    $projected=($p['native_id']??null)===($saved['message_id']??null)&&($p['kind']??null)==='unsupported';
   }catch(\Throwable$e){$s=method_exists($e,'getStatusCode')?$e->getStatusCode():null;
    $projectedStatus=is_int($s)&&$s>=400&&$s<=599?$s:null;}
   $old=$saved['content']['message']??[];$full=$dto['content']['message']??[];
   $route=true;foreach(['from','from_user_id','to','to_user_id','context','referral']as$f)
    if(\App\Services\Dashboard\PosServiceTicket::fingerprint([$old[$f]??null])!==\App\Services\Dashboard\PosServiceTicket::fingerprint([$full[$f]??null]))$route=false;
   $oldAllowed=['id','from','from_user_id','to','to_user_id','timestamp','type','errors','unsupported','context','referral'];
   $fullAllowed=['id','from','from_user_id','to','to_user_id','timestamp','type','text','context','referral'];
   $a=strtotime(($saved['sent_at']??'').' UTC');$b=strtotime(($dto['sent_at']??'').' UTC');
   $native=$saved['message_id']??null;$h=$saved['content']['hydration']??null;
   $GLOBALS['wa_diag_rows'][]=['event_id'=>(int)$event->id,'message_id'=>(int)$message->id,'conversation_id'=>(int)$thread->id,
    'state'=>$event->processed_at===null?'PENDING_UNSUPPORTED':'PROCESSED_TEXT','raw_hash_valid'=>true,
    'native_id_is_wamid'=>is_string($native)&&preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/',$native)===1,
    'old_native_keys_allowed'=>is_array($old)&&!array_diff(array_keys($old),$oldAllowed),
    'old_native_key_count'=>is_array($old)?count($old):null,
    'incoming_native_keys_allowed'=>is_array($full)&&!array_diff(array_keys($full),$fullAllowed),
    'incoming_native_key_count'=>is_array($full)?count($full):null,'routing_context_matches'=>$route,
    'native_delta_seconds'=>$a!==false&&$b!==false&&abs($b-$a)<=86400?$b-$a:null,
    'old_dto_time_matches_column'=>($saved['sent_at']??null)===$message->sent_at,
    'old_native_time_matches_dto'=>isset($old['timestamp'])&&ctype_digit((string)$old['timestamp'])&&gmdate('Y-m-d H:i:s',(int)$old['timestamp'])===($saved['sent_at']??null),
    'incoming_native_time_matches_dto'=>isset($full['timestamp'])&&ctype_digit((string)$full['timestamp'])&&gmdate('Y-m-d H:i:s',(int)$full['timestamp'])===($dto['sent_at']??null),
    'old_projected_ready'=>$message->type==='unsupported'?$projected:null,'old_projected_http'=>$projectedStatus,
    'transition_ready'=>$message->type==='unsupported'?\App\Services\Dashboard\WhatsAppNativePlaceholderHydration::transition($thread,$message,$saved,$dto):null,
    'hydration_marker_present'=>is_array($h),'marker_time_matches_raw'=>is_array($h)?($h['at_utc']??null)===$event->processed_at:null,
    'before_ciphertext_within_workflow_budget'=>is_array($h)?is_string($h['before_ciphertext']??null)&&strlen($h['before_ciphertext'])<=1048576:null,
    'completed_proof_ready'=>$message->type==='text'?\App\Services\Dashboard\WhatsAppNativePlaceholderHydration::prove($thread,$message,$saved,$event,$failure!==null):null];
'''


def diagnostic_php(original):
    # Exact frozen preflight only: no recover branch, Consumer call or DB write.
    body=original.split(" if($phase==='preflight'){",1)[0]
    needle=r'throw new \RuntimeException();'
    if body.count(needle)!=len(REASONS):raise ValueError('DIAGNOSTIC_TEMPLATE')
    for reason in REASONS:body=body.replace(needle,"throw new \\RuntimeException('"+reason+"');",1)
    body=body.replace('foreach($events as$event){','foreach($events as$event){$GLOBALS[\'wa_diag_event\']=(int)$event->id;',1)
    needle="   $result['events'][]=(array)$event;"
    if body.count(needle)!=1:raise ValueError('DIAGNOSTIC_ROW_MARKER')
    body=body.replace(needle,ROW_DIAGNOSTICS+needle,1)
    # Eliminate locking code even though this closure is always called false.
    body=body.replace('($lock?$q->lockForUpdate():$q)','$q')
    hook=" $db=\\Illuminate\\Support\\Facades\\DB::class;$crypt=\\Illuminate\\Support\\Facades\\Crypt::class;"
    if body.count(hook)!=1:raise ValueError('DIAGNOSTIC_BOOTSTRAP_MARKER')
    body=body.replace(hook,hook+r'''
 $GLOBALS['wa_diag_rows']=[];$GLOBALS['wa_diag_event']=null;$GLOBALS['wa_diag_classes']=[];
 $db::listen(static function($q){if(!preg_match('/\A\s*(SELECT|SHOW|PRAGMA)\b/i',$q->sql))throw new \RuntimeException('READ_ONLY_QUERY_GUARD');});
 $proofPins=json_decode($argv[7]??'{}',true,16,JSON_THROW_ON_ERROR);
 foreach(['App\\Services\\Dashboard\\WhatsAppNativePlaceholderHydration','App\\Services\\Dashboard\\WhatsAppOrderWorkflow',
   'App\\Services\\Dashboard\\WhatsAppInboxMessage','App\\Support\\WhatsAppInboxProtocol',
   'App\\Services\\Dashboard\\PosServiceTicket','App\\Services\\Dashboard\\WhatsAppInboxConsumer',
   'App\\Services\\Dashboard\\WhatsAppInboxAccess']as$c){
  $ok=class_exists($c);$match=false;
  if($ok){$f=(new \ReflectionClass($c))->getFileName();$rel='app/'.str_replace('\\','/',substr($c,4)).'.php';
   if(is_string($f)&&$f===getcwd().'/'.$rel){$s=file_get_contents($f);$match=sha1('blob '.strlen($s)."\0".$s)===($proofPins[$rel]??null);}}
  $GLOBALS['wa_diag_classes'][]=['class'=>$c,'autoload_ready'=>$ok,'loaded_source_matches'=>$match];
  if(!$ok||!$match)throw new \RuntimeException('LOADED_CLASS_MISMATCH');
 }
''',1)
    return body+r'''
 $v=$gather(false);$scans=[];$activation=config('whatsapp_orders.activation_message_id');
 $digits=is_int($activation)||is_string($activation)?(string)$activation:'';$max=(string)PHP_INT_MAX;
 $activationValid=preg_match('/\A(?:0|[1-9][0-9]*)\z/',$digits)===1&&strlen($digits)<=strlen($max)
  &&(strlen($digits)<strlen($max)||strcmp($digits,$max)<=0);
 foreach($v['bundle']['scans']as$s){$lease=is_string($s['claim_nonce']??null)&&$s['claim_nonce']!==''
   &&is_string($s['lease_until']??null)&&$s['lease_until']>=now('UTC')->format('Y-m-d H:i:s');
  $scans[]=['conversation_id'=>(int)$s['conversation_id'],'claim_present'=>!empty($s['claim_nonce']),
   'lease_active'=>$lease,'auto_floor_present'=>isset($s['auto_floor'])];}
 $out=['diagnostic'=>'READ_ONLY_FAILED_SEMANTIC_RECOVERY','status'=>'SCOPED_PREFLIGHT_READY','pending'=>$v['pending'],
  'total'=>13,'scope_matches_original_quiescent'=>preg_match('/\A[a-f0-9]{64}\z/',$expected)===1&&hash_equals($expected,$v['scope_hash']),
  'automatic_mode'=>config('whatsapp_orders.mode')==='auto','activation_message_id_valid'=>$activationValid,
  'rows'=>$GLOBALS['wa_diag_rows'],'scans'=>$scans,'classes'=>$GLOBALS['wa_diag_classes'],'writes'=>0,'ai_calls'=>0,'orders_created'=>0];
 while(ob_get_level()>0)ob_end_clean();echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(\Throwable$e){while(ob_get_level()>0)ob_end_clean();
 $allowed=json_decode($argv[6]??'[]',true);$r=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'DECRYPT_SCHEMA_BOOTSTRAP_OR_READ_ONLY_CHECK';
 echo json_encode(['diagnostic'=>'READ_ONLY_FAILED_SEMANTIC_RECOVERY','status'=>'SCOPED_PREFLIGHT_NOT_READY',
  'first_failed_check'=>$r,'event_id'=>$GLOBALS['wa_diag_event']??null,'rows'=>$GLOBALS['wa_diag_rows']??[],
  'classes'=>$GLOBALS['wa_diag_classes']??[],'writes'=>0,'ai_calls'=>0,'orders_created'=>0],JSON_THROW_ON_ERROR);}
'''


def verified_source(release, path, expected):
    result=subprocess.run(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'show',release+':'+path],
        stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=20)
    if result.returncode or not result.stdout or len(result.stdout)>1048576 or blob(result.stdout)!=expected:
        raise ValueError('PINNED_DIAGNOSTIC_SOURCE')
    return result.stdout


def safe_metrics(value):
    if (not isinstance(value,dict) or set(value)!=set(METRICS)
            or any(type(x)is not int or not 0<=x<=13 for x in value.values())):raise ValueError('METRIC_SHAPE')
    return value


def pipeline_summary(entries):
    phases=('PRIOR_PIPELINES','QUIESCENT','CODE_PUBLISHED','RECOVERY_COMMITTED','PRIOR_PIPELINES_RESTORED','ORDERS_PAUSED_CAPTURE_ACTIVE')
    if not isinstance(entries,list) or not 1<=len(entries)<=20:raise ValueError('JOURNAL_SHAPE')
    if any(not isinstance(e,dict) or e.get('phase')not in phases for e in entries):raise ValueError('JOURNAL_PHASE')
    prior=[e for e in entries if e['phase']=='PRIOR_PIPELINES'];quiet=[e for e in entries if e['phase']=='QUIESCENT']
    if len(prior)!=1 or len(quiet)!=1:raise ValueError('JOURNAL_BOUNDARY')
    p=prior[0];q=quiet[0]
    if set(p.get('before',{}))!=set(UNITS) or set(p.get('profiles',{}))!=set(UNITS):raise ValueError('JOURNAL_UNITS')
    for name,s in p['before'].items():
        if not isinstance(s,dict) or s.get('ActiveState')not in ('active','activating','inactive','failed'):raise ValueError('JOURNAL_UNIT_STATE')
    if not re.fullmatch('[a-f0-9]{64}',p.get('configuration_fingerprint','')) or not re.fullmatch('[a-f0-9]{64}',q.get('recovery_scope','')):
        raise ValueError('JOURNAL_DIGEST')
    if type(q.get('recovery_pending'))is not int or not 0<=q['recovery_pending']<=13:raise ValueError('JOURNAL_PENDING')
    return {'phases':[e['phase']for e in entries],
            'original_capture_timer_active':p['before'][UNITS[0]]['ActiveState']=='active',
            'original_orders_timer_active':p['before'][UNITS[2]]['ActiveState']=='active',
            'quiescent_pending':q.get('recovery_pending')},p,q


def main():
    parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--release',required=True);args=parser.parse_args()
    try:
        if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{40}',args.release):raise ValueError('ROOT_CONTEXT')
        uid=pwd.getpwnam('fasakha').pw_uid;parent=ROOT.parent/'whatsapp-release-backups'
        if parent.lstat().st_uid!=uid or stat.S_IMODE(parent.lstat().st_mode)!=0o700:raise ValueError('BACKUP_PARENT')
        publisher=verified_source(args.release,'deployment/apply_whatsapp_order_semantic_repair.py',PUBLISHER_BLOB)
        base=verified_source(args.release,'deployment/install_whatsapp_inbox.py',BASE_BLOB)
        wrapper=verified_source(args.release,'deployment/apply_whatsapp_order_semantic_repair_root.sh',ROOT_BLOB)
        guards=readonly_guards(publisher,base)
        root_program=wrapper.split(b"exec /usr/bin/python3 - \"$@\" <<'PY'\n",1)[1][:-len(b'\nPY\n')]
        root_ns={'pathlib':pathlib,'os':os,'stat':stat,'hashlib':hashlib,'re':re,'subprocess':subprocess,'SYSTEMCTL':'/usr/bin/systemctl'}
        selected_namespace(root_program,{'SafeError','command','unit_state','unit_profile'},root_ns)
        pipeline=parent/PIPELINE_NAME;backup=parent/BACKUP_NAME
        pipeline_raw,_=private_file(pipeline/'journal.jsonl',0,1048576,ancestry_uid=uid)
        entries=[json.loads(line)for line in pipeline_raw.splitlines()if line]
        summary,prior,quiet=pipeline_summary(entries)
        receipt_raw,receipt_meta=private_file(backup/'receipt.json',uid,1048576)
        receipt=json.loads(receipt_raw)
        pins={p:v[1]for p,v in guards['SOURCE_PINS'].items()}
        if receipt.get('targets')!=pins:raise ValueError('RECEIPT_TARGETS')
        metadata={'receipt.json':receipt_meta};intent=None;completion=None
        for name,limit in [('recovery-before-images.enc',33554432),('recovery-intent.json',65536),('recovery-complete.json',65536)]:
            raw,m=private_file(backup/name,uid,limit,read=name!='recovery-before-images.enc',absent_ok=True)
            metadata[name]=m or {'exists':False,'bytes':None}
            if raw is not None:
                value=json.loads(raw)
                if value.get('event_ids')!=IDS:raise ValueError('RECOVERY_RECEIPT_IDS')
                if name=='recovery-intent.json':
                    if type(value.get('pending'))is not int or not 0<=value['pending']<=13:raise ValueError('INTENT_PENDING')
                    intent={'pending':value['pending'],'scope_matches_quiescent':value.get('scope_hash')==quiet['recovery_scope']}
                elif name=='recovery-complete.json':
                    if value.get('status')!='SEMANTIC_RECOVERY_READY':raise ValueError('COMPLETION_STATUS')
                    completion={'status':'SEMANTIC_RECOVERY_READY','metrics':safe_metrics(value.get('metrics'))}
        summary['unit_profiles_match']=all(list(root_ns['unit_profile'](name))==prior['profiles'][name]for name in UNITS)
        summary['current_units']={name:{'active_state':root_ns['unit_state'](name)['ActiveState'],
            'main_pid_zero':root_ns['unit_state'](name).get('MainPID','0')=='0'}for name in UNITS}
        config=guards['configuration_snapshot'](ROOT,uid)
        summary['configuration_matches_original']=guards['configuration_fingerprint'](config)==prior['configuration_fingerprint']
        proof_pins={**pins,**guards['PROTECTED_PINS'],'app/Services/Dashboard/WhatsAppInboxAccess.php':ACCESS_BLOB}
        for p,target in proof_pins.items():
            if blob(guards['guarded_snapshot'](ROOT/p,uid,ROOT)[0].content)!=target:raise ValueError('LIVE_CODE_PIN')
        program=diagnostic_php(guards['RECOVERY_PHP'])
        result=subprocess.run(['/sbin/runuser','-u','fasakha','--',PHP,'-r',program,'preflight',json.dumps(pins),json.dumps(IDS),'',
            quiet['recovery_scope'],json.dumps(list(REASONS)+['READ_ONLY_QUERY_GUARD','LOADED_CLASS_MISMATCH']),json.dumps(proof_pins)],cwd=ROOT,
            stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=60)
        if result.returncode or len(result.stdout)>131072:raise ValueError('READ_ONLY_PHP')
        value=json.loads(result.stdout)
        if value.get('writes')!=0 or value.get('ai_calls')!=0 or value.get('orders_created')!=0:raise ValueError('READ_ONLY_RESULT')
        if private_file(pipeline/'journal.jsonl',0,1048576,ancestry_uid=uid)[0]!=pipeline_raw:
            raise ValueError('PIPELINE_JOURNAL_CHANGED')
        # Fingerprint compare once more: never report a stable snapshot if config changed.
        summary['configuration_stable_during_check']=guards['configuration_snapshot'](ROOT,uid)==config
        print(json.dumps({'backup_files':metadata,'intent':intent,'completion':completion,'pipeline':summary,
                         'scoped_recovery':value,'writes':0,'ai_calls':0,'orders_created':0},sort_keys=True))
    except BaseException:
        print('READ_ONLY_SEMANTIC_RECOVERY_DIAGNOSTIC_STOPPED_WITHOUT_SECRET_OUTPUT');raise SystemExit(1)


if __name__=='__main__':main()
