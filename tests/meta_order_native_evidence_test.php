<?php
/** Real native normalizer/consumer plus encrypted projection; no fabricated WA DTO. */
require __DIR__.'/meta_messaging_test.php';
foreach (['Support/WhatsAppOrderExtraction','Services/Dashboard/WhatsAppOrderFollowupService',
    'Services/Dashboard/MetaOrderNativeEvidence','Services/Dashboard/MetaOrderFollowupService'] as $file)
    if (!class_exists('App\\'.str_replace('/','\\',$file),false)) require __DIR__.'/../app/'.$file.'.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\Services\Dashboard\MetaOrderNativeEvidence;
use App\Services\Dashboard\MetaOrderFollowupService;
use App\Services\Dashboard\MetaInboxConsumer;

$nativeChecks=0;
function nativeExpect($ok,string $label): void { $GLOBALS['nativeChecks']++;if(!$ok)throw new RuntimeException($label); }
function nativeRejected(callable $callback,string $label): void {
    try { $callback();nativeExpect(false,$label); } catch (RuntimeException $error) {
        if ($error->getMessage()===$label) throw $error;nativeExpect(true,$label);
    }
}
$evidence=new MetaOrderNativeEvidence();
foreach ([['page','messenger','101'],['instagram','instagram','202']] as [$object,$channel,$account]) {
    $purchase=event($object,$account,'1900','native-order-'.$channel,'عايز كيلو فسيخ');capture($purchase);
    $location=event($object,$account,'1900','native-point-'.$channel,null);
    $location['entry'][0]['messaging'][0]['message']['attachments']=[['type'=>'location','payload'=>['coordinates'=>['lat'=>31,'long'=>31.4]]]];
    $location['entry'][0]['messaging'][0]['timestamp']+=1000;capture($location);
    $result=(new MetaInboxConsumer())->consume();nativeExpect($result['errors']===0,'actual native '.$channel.' messages consumed');
    $thread=DB::table('meta_inbox_conversations')->where('waba_id',$channel)->where('phone_number_id',$account)
        ->where('peer_hash',hash_hmac('sha256','whatsapp-peer-v1:'.$channel.':'.$account.':user:1900',Crypt::getKey()))->first();
    $ceiling=(int)DB::table('meta_inbox_messages')->where('conversation_id',$thread->id)->max('id');
    $snapshot=$evidence->snapshot((int)$thread->id,0,$ceiling);
    nativeExpect($snapshot['complete']&&count($snapshot['transcript'])===2,'complete native '.$channel.' snapshot');
    nativeExpect($snapshot['transcript'][1]['location']===['lat'=>31.0,'long'=>31.4],'actual integer-coordinate native location accepted');
    nativeExpect($evidence->latestInbound((int)$thread->id,$snapshot)['kind']==='location','latest native attachment is authoritative');
    nativeExpect($evidence->latestInbound((int)$thread->id,$snapshot)['text']===null,'native location does not invent text');
    $first=DB::table('meta_inbox_messages')->where('conversation_id',$thread->id)->orderBy('id')->first();
    $stored=$first->content;$dto=json_decode(Crypt::decryptString($stored),true,64,JSON_THROW_ON_ERROR);
    foreach (['raw-text','native-mid','channel','peer','kind','source'] as $mutation) {
        $altered=$dto;
        if ($mutation==='raw-text') $altered['content']['message']['meta']['text']='عايز 2 كيلو فسيخ';
        if ($mutation==='native-mid') $altered['content']['message']['meta']['mid']='wrong-mid';
        if ($mutation==='channel') $altered['channel']=$channel==='messenger'?'instagram':'messenger';
        if ($mutation==='peer') {$altered['peer_user_id']='1901';$altered['peer_identity']='user:1901';}
        if ($mutation==='kind') $altered['type']='image';
        if ($mutation==='source') $altered['source']='message_echoes';
        DB::table('meta_inbox_messages')->where('id',$first->id)->update(['content'=>Crypt::encryptString(json_encode($altered,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
        nativeRejected(fn()=>$evidence->snapshot((int)$thread->id,0,$ceiling),'native '.$channel.' '.$mutation.' mutation denied');
    }
    DB::table('meta_inbox_messages')->where('id',$first->id)->update(['content'=>$stored]);
    $pin=DB::table('meta_inbox_messages')->where('id',$ceiling)->first();$pinDto=json_decode(Crypt::decryptString($pin->content),true,64,JSON_THROW_ON_ERROR);
    $pinDto['content']['message']['meta']['attachments'][0]['payload']['coordinates']['lat']=30;
    DB::table('meta_inbox_messages')->where('id',$pin->id)->update(['content'=>Crypt::encryptString(json_encode($pinDto,JSON_THROW_ON_ERROR))]);
    nativeRejected(fn()=>$evidence->snapshot((int)$thread->id,0,$ceiling),'raw point must agree with normalized native point');
    DB::table('meta_inbox_messages')->where('id',$pin->id)->update(['content'=>$pin->content]);
    $image=event($object,$account,'1900','native-image-'.$channel,null);
    $image['entry'][0]['messaging'][0]['timestamp']+=1500;
    $image['entry'][0]['messaging'][0]['message']['attachments']=[['type'=>'image','payload'=>['url'=>'https://example.test/one.jpg']]];
    capture($image);(new MetaInboxConsumer())->consume();
    $newCeiling=(int)DB::table('meta_inbox_messages')->where('conversation_id',$thread->id)->max('id');
    $before=$evidence->snapshot((int)$thread->id,0,$newCeiling);
    $media=DB::table('meta_inbox_messages')->where('id',$newCeiling)->first();$mediaDto=json_decode(Crypt::decryptString($media->content),true,64,JSON_THROW_ON_ERROR);
    $mediaDto['content']['message']['meta']['attachments'][0]['payload']['url']='https://example.test/two.jpg';
    DB::table('meta_inbox_messages')->where('id',$media->id)->update(['content'=>Crypt::encryptString(json_encode($mediaDto,JSON_THROW_ON_ERROR))]);
    nativeExpect($evidence->snapshot((int)$thread->id,0,$newCeiling)['hash']!==$before['hash'],'opaque native attachment mutation invalidates saved guard');
    $plan=['conversation_id'=>(int)$thread->id,'episode_customer_key'=>$snapshot['keys']['m1'],'latest_customer_key'=>$snapshot['keys']['m2']];
    $uuid=MetaOrderFollowupService::requestUuid($plan,Crypt::getKey());
    $marker=['version'=>'meta-order-followup-v1','kind'=>'question','fields'=>['LOCATION'],'latest_customer_id'=>$ceiling,
        'latest_customer_key'=>$plan['latest_customer_key'],'episode_customer_key'=>$plan['episode_customer_key'],'proposal_hash'=>null];
    nativeExpect(MetaOrderFollowupService::validMarker($marker,(int)$thread->id,$uuid,$ceiling),'separate scoped Meta marker validates');
    $marker['version']='whatsapp-order-followup-v1';
    nativeExpect(!MetaOrderFollowupService::validMarker($marker,(int)$thread->id,$uuid,$ceiling),'WA marker cannot authorize Meta followup');
    $uuids[$channel]=$uuid;
}
nativeExpect($uuids['messenger']!==$uuids['instagram'],'same native message IDs remain isolated by channel and account');
echo 'META_ORDER_NATIVE_EVIDENCE_TESTS='.$nativeChecks." PASS\n";
