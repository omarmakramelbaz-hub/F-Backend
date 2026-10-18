<?php
namespace Illuminate\Support\Facades {
    class Crypt {public static function decryptString($value){return$value;}}
    class DB {public static function table($name){return new \NativeBlockerQuery($name);}}
}
namespace Symfony\Component\HttpKernel\Exception {class HttpException extends \RuntimeException {}}
namespace App\Services\Dashboard {class WhatsAppInboxAccess {const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}}
namespace {
    $root=dirname(__DIR__);$checks=0;
    function config($name,$default=null){return$name==='app.key'?'native-blocker-fixture-key-32-bytes':$default;}
    function nativeExpect($condition,string$label):void{global$checks;$checks++;if(!$condition)throw new \RuntimeException('NATIVE_BLOCKER_TEST_FAILED:'.$label);}
    require$root.'/app/Services/Dashboard/WhatsAppInboxMessage.php';
    $source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_status.php');
    $intStart=strpos($source,'function waConfirmationInt(');$intEnd=strpos($source,'function waConfirmationCode(',$intStart);
    eval(substr($source,$intStart,$intEnd-$intStart));
    $start=strpos($source,'function waConfirmationNativeBlocker(');$end=strpos($source,'/** Optional worker observations.',$start);
    nativeExpect($start!==false&&$end!==false,'isolated actual helper source');
    eval(substr($source,$start,$end-$start));
    class NativeBlockerQuery {
        private array$conditions=[];private array$columns=[];private ?string$raw=null;
        public function __construct(private string$table){$GLOBALS['nativeQueries']++;}
        public function where($key,$operator,$value=null){$this->conditions[]=func_num_args()===2?[$key,'=',$operator]:[$key,$operator,$value];return$this;}
        public function select(...$columns){$this->columns=$columns;return$this;}
        public function selectRaw($sql){$this->raw=$sql;return$this;}
        public function first($columns=null){
            if($this->table==='whatsapp_inbox_conversations'){
                nativeExpect($this->conditions===[['id','=',11],['waba_id','=','TEST_WABA'],['phone_number_id','=','TEST_PHONE']],
                    'thread query fixed native account and exact conversation');
                nativeExpect($columns===['id','waba_id','phone_number_id','peer_hash'],'no customer field query');
                return$GLOBALS['nativeThread'];
            }
            nativeExpect($this->table==='whatsapp_inbox_messages'&&$this->conditions===[['conversation_id','=',11],
                ['message_key','=',$GLOBALS['nativeKey']],['id','>',1],['id','<=',4]],'message key and evidence bounds scoped');
            nativeExpect($this->columns===['id','conversation_id','message_key','direction','type','source','sent_at']
                &&$this->raw==='CASE WHEN LENGTH(content)<=1048576 THEN content ELSE NULL END AS content','bounded ciphertext query');
            return$GLOBALS['nativeRow'];
        }
    }
    function nativeFixture(string$type='sticker',array$extra=[]):array{
        $scope='TEST_WABA:TEST_PHONE:';$id='wamid.PRIVATE_NATIVE_ID';$user='PRIVATE_USER_ID';$sent='2026-10-18 18:00:00';
        $raw=['id'=>$id,'type'=>$type,'from_user_id'=>$user,'timestamp'=>(string)strtotime($sent.' UTC')]+$extra;
        $dto=['message_id'=>$id,'peer_identity'=>'user:'.$user,'peer_user_id'=>$user,'peer_phone'=>null,
            'direction'=>'inbound','type'=>$type,'source'=>'messages','sent_at'=>$sent,'text'=>null,'content'=>['message'=>$raw]];
        $key=hash('sha256','whatsapp-message-v1:'.$scope.$id);
        $GLOBALS['nativeKey']=$key;$GLOBALS['nativeQueries']=0;
        $GLOBALS['nativeThread']=(object)['id'=>11,'waba_id'=>'TEST_WABA','phone_number_id'=>'TEST_PHONE',
            'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:'.$scope.'user:'.$user,config('app.key'))];
        $GLOBALS['nativeRow']=(object)['id'=>3,'conversation_id'=>11,'message_key'=>$key,'direction'=>'inbound',
            'type'=>$type,'source'=>'messages','sent_at'=>$sent,'content'=>json_encode($dto,JSON_THROW_ON_ERROR)];
        return['transcript'=>[['id'=>'m3','speaker'=>'customer','sent_at'=>$sent,'text'=>null,'location'=>null]],'keys'=>['m3'=>$key]];
    }
    $observation=['blocker'=>['ordinal'=>1]];
    $snapshot=nativeFixture();$out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
    nativeExpect($out['available']&&$out['native_type']==='sticker'&&$out['native_identity_verified']&&$out['snapshot_row_matches'],
        'actual pinned projector authenticates selected sticker without exposing it');
    nativeExpect($out['scope']==='AUTHENTICATED_NATIVE_KIND_NOT_ORDER_AUTHORITY'&&$out['source']==='messages'
        &&$out['ordinal']===1&&!$out['raw_text_body_present']&&!$out['media_caption_present']
        &&!$out['location_payload_present']&&!$out['order_payload_present']&&!$out['interactive_payload_present'],
        'only allowlisted metadata booleans');
    foreach(['image','audio','video','document','contacts','reaction','system','unsupported']as$type){
        $snapshot=nativeFixture($type);$out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
        nativeExpect($out['available']&&$out['native_type']===$type,'known native type '.$type);
    }
    $snapshot=nativeFixture('PRIVATE_TYPE');$out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
    nativeExpect($out['available']&&$out['native_type']==='OTHER','unknown native type not exported');
    foreach([131060,'131060']as$code){
        $snapshot=nativeFixture('unsupported',['errors'=>[['code'=>$code,'title'=>'PRIVATE_ERROR','message'=>'PRIVATE_ERROR_BODY']]]);
        $out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
        nativeExpect($out['available']&&$out['only_error_131060']&&$out['error_count']===1,'known unavailable error metadata only');
    }
    foreach([[],[['code'=>131060],['code'=>131060]],[['code'=>999999]],'PRIVATE_ERRORS',array_fill(0,31,['code'=>131060])]as$errors){
        $snapshot=nativeFixture('unsupported',['errors'=>$errors]);$out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
        nativeExpect($out['available']&&!$out['only_error_131060'],'no unsupported unavailable inference from different error shape');
    }
    $snapshot=nativeFixture('image',['image'=>['caption'=>'PRIVATE_CAPTION','id'=>'PRIVATE_MEDIA_ID']]);
    $snapshot['transcript'][0]['text']='PRIVATE_CAPTION';$out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
    nativeExpect($out['available']&&$out['media_caption_present'],'caption existence observed without content');
    $snapshot=nativeFixture('sticker',['location'=>['latitude'=>30.125,'longitude'=>31.625],
        'order'=>['product_items'=>[['product_retailer_id'=>'PRIVATE_PRODUCT']]],'interactive'=>['body'=>'PRIVATE_INTERACTIVE']]);
    $out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
    nativeExpect($out['available']&&$out['location_payload_present']&&$out['order_payload_present']&&$out['interactive_payload_present'],
        'unexpected secondary payload presence remains visible as booleans');
    foreach(['peer','native-id','type','source','timestamp','thread-scope','peer-hash','snapshot-text','snapshot-sent','snapshot-speaker','missing-content']as$case){
        $snapshot=nativeFixture();$dto=json_decode($GLOBALS['nativeRow']->content,true);
        switch($case){
            case'peer':$dto['content']['message']['from_user_id']='PRIVATE_OTHER_PEER';break;
            case'native-id':$dto['content']['message']['id']='wamid.PRIVATE_OTHER_ID';break;
            case'type':$dto['content']['message']['type']='image';break;
            case'source':$dto['source']='PRIVATE_SOURCE';break;
            case'timestamp':$dto['content']['message']['timestamp']='1';break;
            case'thread-scope':$GLOBALS['nativeThread']->waba_id='PRIVATE_OTHER_WABA';break;
            case'peer-hash':$GLOBALS['nativeThread']->peer_hash=str_repeat('f',64);break;
            case'snapshot-text':$snapshot['transcript'][0]['text']='PRIVATE_DIFFERENT_TEXT';break;
            case'snapshot-sent':$snapshot['transcript'][0]['sent_at']='2026-10-18 18:00:01';break;
            case'snapshot-speaker':$snapshot['transcript'][0]['speaker']='business';break;
        }
        $GLOBALS['nativeRow']->content=$case==='missing-content'?null:json_encode($dto);
        $out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
        nativeExpect(!$out['available']&&$out['reason']==='NATIVE_PROOF_UNAVAILABLE','native proof fails closed '.$case);
        nativeExpect(!str_contains(json_encode($out),'PRIVATE_'),'failed proof exports no exception detail '.$case);
    }
    $snapshot=nativeFixture();$out=waConfirmationNativeBlocker($snapshot,['blocker'=>null],11,1,4);
    nativeExpect(!$out['available']&&$out['reason']==='NO_LOCATED_BLOCKER'&&$GLOBALS['nativeQueries']===0,'no unrelated native query when no blocker');
    unset($snapshot['keys']);$out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);
    nativeExpect(!$out['available']&&$GLOBALS['nativeQueries']===0,'missing native key does not guess identity');
    $snapshot=nativeFixture('unsupported',['errors'=>[['code'=>131060,'message'=>'PRIVATE_ERROR']]]);
    $out=waConfirmationNativeBlocker($snapshot,$observation,11,1,4);$output=json_encode($out);
    foreach(['PRIVATE_','wamid.','30.125','31.625',$GLOBALS['nativeKey'],config('app.key')]as$private)
        nativeExpect(!str_contains($output,$private),'native private content is absent');
    nativeExpect(!preg_match('/(?:->|::)(?:save|insert|update|delete|analyze|process|consume|dispatch|send|quote|lockForUpdate)\s*\(/',substr($source,$start,$end-$start)),
        'observer has no write, processing, locking or outbound action');
    echo"WHATSAPP_CUSTOMER_CONFIRMATION_NATIVE_BLOCKER_OK checks=$checks\n";
}
