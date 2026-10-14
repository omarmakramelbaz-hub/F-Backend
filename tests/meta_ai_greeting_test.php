<?php
require __DIR__.'/../app/Support/WhatsAppInboxProtocol.php';
require __DIR__.'/../app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require __DIR__.'/../app/Support/WhatsAppOrderExtraction.php';
require __DIR__.'/../app/Support/WhatsAppOrderDialogue.php';
require __DIR__.'/../app/Services/Dashboard/WhatsAppOrderAiProvider.php';
use App\Support\WhatsAppOrderDialogue as Dialogue;
use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
$checks=0;
function greetingCheck($ok,$label) { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); }
$context=['field'=>'ITEMS','kind'=>'GREETING','item_name'=>null,'candidates'=>[],
    'transcript'=>[['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-15 09:00:00','text'=>'السلام عليكم','location'=>null]]];
greetingCheck(Dialogue::validContext($context),'native first greeting is a question context');
$choice=Dialogue::fallback($context); $text=Dialogue::render($context,$choice);
greetingCheck(str_contains($text,'أهلاً')&&str_contains($text,'نساعدك بإيه'),'first response welcomes and asks how to help');
foreach (['اتأكد','جنيه','الاسم','رقم التواصل','لوكيشن','أوردر رقم'] as $guess)
    greetingCheck(!str_contains($text,$guess),'greeting assumes no order facts');
greetingCheck(Dialogue::render($context,['action'=>'WAIT','candidate_ids'=>[],'variant'=>'SHORT'])==='','wait remains no-send');
foreach (['field'=>'PHONE','item_name'=>'السلام','candidates'=>[['id'=>'c1','label'=>'رنجة']]] as $key=>$value) {
    $bad=$context; $bad[$key]=$value;
    greetingCheck(!Dialogue::validContext($bad),'welcome cannot leak into factual questions');
}
$seen=[];
$provider=new Provider(function($body,$key) use (&$seen) {
    $seen=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[
        ['type'=>'output_text','text'=>'{"action":"ASK","candidate_ids":[],"variant":"POLITE"}']]]]],JSON_THROW_ON_ERROR)];
},['enabled'=>true,'api_key'=>'sk-fixture-value-only','model'=>'gpt-6-luna']);
$result=$provider->followupQuestion($context);
greetingCheck($result['ok']&&$result['choice']===$choice,'provider can select grounded first greeting');
greetingCheck(($seen['store']??null)===false&&!isset($seen['tools']),'no tools or stored conversation');
greetingCheck(json_decode($seen['input'][0]['content'],true)===$context,'whole actual context reaches selector');
$information=$context; $information['kind']='CATALOG_INFORMATION';
$information['candidates']=[['id'=>'c1','label'=>'رنجة بطارخ'],['id'=>'c2','label'=>'رنجة هيرنج']];
greetingCheck(Dialogue::validContext($information),'actual catalog information context');
$text=Dialogue::render($information,['action'=>'ASK','candidate_ids'=>['c2'],'variant'=>'SHORT']);
greetingCheck(str_contains($text,'رنجة هيرنج')&&!str_contains($text,'رنجة بطارخ'),'informative answer contains only selected actual candidates');
greetingCheck(str_contains($text,'تفاصيل')&&!str_contains($text,'موافقة'),'inquiry remains inquiry');
greetingCheck(!str_contains($text,'جنيه')&&!str_contains($text,'اتأكد'),'no invented price or order');
$bad=$information; $bad['item_name']='السلام';
greetingCheck(!Dialogue::validContext($bad),'catalog inquiry cannot invent basket item');
$bad=$information; $bad['candidates']=[];
greetingCheck(!Dialogue::validContext($bad),'catalog inquiry requires actual available choices');
echo 'PASS '.$checks."\n";
