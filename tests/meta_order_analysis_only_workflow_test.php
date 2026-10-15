<?php
require __DIR__.'/meta_order_primary_ai_workflow_test.php';
use App\Services\Dashboard\WhatsAppOrderAiProvider as MetaAi;
use App\Support\WhatsAppOrderExtraction as MetaExtraction;
use Illuminate\Support\Facades\DB as MetaDB;
use Illuminate\Support\Facades\Crypt as MetaCrypt;

$metaAnalysisChecks=0;
function metaAnalysisExpect($ok,string $label): void {
    $GLOBALS['metaAnalysisChecks']++;
    if (!$ok) throw new RuntimeException('META_ANALYSIS_ONLY_FAILED:'.$label);
}
function metaAnalysisRecap(): string {
    return "ملخص الطلب النهائي:\nتأكيد الطلب:\nالاسم: عمر\nرقم الهاتف: 201000000001\nنوع الطلب: دليفري\nالفرع: المنصورة\nالعنوان: شارع البحر\nالمنطقة: المنصورة\nالأصناف:\n- فسيخ | الكمية: 1 كيلو\nملاحظات: لا يوجد\nالإجمالي التقريبي: 100.00 جنيه\nللموافقة على هذه البيانات اكتب: موافق على الطلب";
}
function metaAnalysisDraft(string $channel,?string $recap=null,?string $approval='موافق على الطلب',bool $ack=false):int {
    metaPrimarySetup();MetaPrimaryFixtureControl::$analysisOnly=true;MetaPrimaryFixtureControl::$replyClockClosed=true;
    $c=metaPrimaryMessage($channel,'mid.analysis.req','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
    metaPrimaryMessage($channel,'mid.analysis.pin',null,2,['lat'=>31.04,'long'=>31.37]);
    metaPrimaryMessage($channel,'mid.analysis.recap',$recap??metaAnalysisRecap(),3,null,true);
    if ($approval!==null) metaPrimaryMessage($channel,'mid.analysis.approval',$approval,4);
    if ($ack) metaPrimaryMessage($channel,'mid.analysis.ack','جاري مراجعة البيانات لإرسال الطلب للفرع.',5,null,true);
    MetaAi::$data=extractionData();MetaAi::$data['evidence']['confirmation_ids']=['m3'];
    MetaAi::$data['evidence']['customer_acceptance_ids']=$approval===null?[]:['m4'];
    return $c;
}
function metaAnalysisSilent(string $label):void {
    metaAnalysisExpect(count($GLOBALS['metaGraphs'])===0&&MetaPrimaryFixtureProvider::$contexts===[]
        &&MetaDB::table('meta_reply_requests')->count()===0&&MetaDB::table('whatsapp_reply_requests')->count()===0,$label);
}
foreach (['messenger','instagram'] as $channel) {
    foreach ([false,true] as $ack) {
        $c=metaAnalysisDraft($channel,null,'موافق على الطلب',$ack);$r=metaPrimaryRun();
        metaAnalysisExpect($r['errors']===0&&$r['dispatched']===1&&MetaAi::$calls===1
            &&MetaDB::table('test_tickets')->count()===1&&MetaDB::table('test_print')->count()===1,
            $channel.' authentic external recap plus genuine customer approval dispatches once');
        metaAnalysisSilent($channel.' analysis-only reserves and sends no automatic response');
        metaPrimaryRun();metaAnalysisExpect(MetaDB::table('test_tickets')->count()===1&&MetaDB::table('test_print')->count()===1,
            $channel.' duplicate processing cannot duplicate ERP order or print');
    }
    metaPrimarySetup();
    $c=metaPrimaryMessage($channel,'mid.analysis.old.request','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
    metaPrimaryMessage($channel,'mid.analysis.old.pin',null,2,['lat'=>31.04,'long'=>31.37]);
    MetaAi::$data=extractionData();MetaAi::$data['decision']='DRAFT';MetaAi::$data['issues']=['UNCONFIRMED'];
    MetaAi::$data['evidence']['confirmation_ids']=[];MetaAi::$data['approximate_total']=null;
    metaPrimaryRun();metaAnalysisExpect(count($metaGraphs)===1,$channel.' fixture has an older accepted dashboard proposal');
    $graphs=count($metaGraphs);$receipts=MetaDB::table('meta_reply_requests')->count();$contexts=count(MetaPrimaryFixtureProvider::$contexts);
    MetaPrimaryFixtureControl::$analysisOnly=true;MetaPrimaryFixtureControl::$replyClockClosed=true;MetaDB::table('meta_ai_controls')->update(['revision'=>2]);
    metaPrimaryMessage($channel,'mid.analysis.external.new',str_replace('100.00 جنيه','غير محدد',metaAnalysisRecap()),302,null,true);
    metaPrimaryMessage($channel,'mid.analysis.external.accept','موافق على الطلب',303);dialogueAdvance(304);
    MetaAi::$data['decision']='CONFIRMED';MetaAi::$data['issues']=[];MetaAi::$data['evidence']['confirmation_ids']=['m4'];MetaAi::$data['evidence']['customer_acceptance_ids']=['m5'];
    $r=metaPrimaryRun();metaAnalysisExpect($r['errors']===0&&$r['dispatched']===1,$channel.' current external recap replaces prior owned proposal authority');
    metaAnalysisExpect(count($metaGraphs)===$graphs&&MetaDB::table('meta_reply_requests')->count()===$receipts&&count(MetaPrimaryFixtureProvider::$contexts)===$contexts,
        $channel.' analysis-only never resends or recomposes after an accepted older reply');

    metaPrimarySetup();MetaPrimaryFixtureControl::$analysisOnly=true;MetaPrimaryFixtureControl::$replyClockClosed=true;
    $c=metaPrimaryMessage($channel,'mid.analysis.greeting','أهلاً');metaPrimaryMessage($channel,'mid.analysis.agent','أهلاً بحضرتك، تحب تطلب إيه؟',2,null,true);
    MetaAi::$data=MetaExtraction::none();$r=metaPrimaryRun();
    metaAnalysisExpect($r['errors']===0&&MetaAi::$calls===1,$channel.' analysis does not require a currently unanswered customer turn');
    metaAnalysisSilent($channel.' incomplete conversation stays silent');metaPrimaryNoErp('no final approved recap is not an order');
    foreach ([null,'موافق؟','"موافق"','موافق بس خليهم 2 كيلو','مش موافق'] as $approval) {
        metaAnalysisDraft($channel,null,$approval);metaPrimaryRun();metaPrimaryNoErp($channel.' unapproved or changed recap is not dispatchable');
        metaAnalysisSilent($channel.' missing approval does not compose any question');
    }
    foreach (['quantity','unit','notes','price','extra-item','marker'] as $change) {
        $recap=metaAnalysisRecap();
        if ($change==='quantity') $recap=str_replace('الكمية: 1','الكمية: 2',$recap);
        if ($change==='unit') $recap=str_replace('1 كيلو','1 قطعة',$recap);
        if ($change==='notes') $recap=str_replace('ملاحظات: لا يوجد','ملاحظات: بدون تنظيف',$recap);
        if ($change==='price') $recap=str_replace('100.00','200.00',$recap);
        if ($change==='extra-item') $recap.="\n- رنجة | الكمية: 1 كيلو";
        if ($change==='marker') $recap=str_replace('ملخص الطلب النهائي:','تأكيد الطلب:',$recap);
        metaAnalysisDraft($channel,$recap);metaPrimaryRun();metaPrimaryNoErp($channel.' inconsistent recap '.$change.' cannot authorize older extraction');
        metaAnalysisSilent($channel.' invalid recap stays silent');
    }
    foreach (['customer-change','business-change','wrong-ack','uncited'] as $change) {
        metaAnalysisDraft($channel);
        if ($change==='customer-change') metaPrimaryMessage($channel,'mid.analysis.changed','خلي الطلب 2 كيلو',5);
        if ($change==='business-change') metaPrimaryMessage($channel,'mid.analysis.changed','تم تعديل الطلب إلى 2 كيلو',5,null,true);
        if ($change==='wrong-ack') metaPrimaryMessage($channel,'mid.analysis.changed','تم إرسال الطلب للفرع',5,null,true);
        if ($change==='uncited') MetaAi::$data['evidence']['customer_acceptance_ids']=[];
        metaPrimaryRun();metaPrimaryNoErp($channel.' uncited tail '.$change.' prevents execution');metaAnalysisSilent($channel.' ambiguous tail stays silent');
    }
    $c=metaAnalysisDraft($channel);MetaPrimaryFixtureControl::$strictDispatchClock=true;$r=metaPrimaryRun();
    metaAnalysisExpect($r['errors']===0&&$r['deferred']===1&&MetaDB::table('meta_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
        $channel.' analysis-only respects independent dispatch hours');metaPrimaryNoErp('out of hours external recap waits');
    $calls=MetaAi::$calls;\Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');
    $r=metaPrimaryRun();metaAnalysisExpect($r['dispatched']===1&&MetaAi::$calls===$calls,$channel.' scheduled cached dispatch needs no second AI call');
    metaAnalysisSilent($channel.' resumed dispatch remains silent');
    $c=metaAnalysisDraft($channel);MetaAi::$callback=static function(){MetaDB::table('meta_ai_controls')->update(['revision'=>2]);};
    metaPrimaryRun();metaPrimaryNoErp($channel.' control revision drift prevents writes');metaAnalysisSilent($channel.' drift stays silent');
    $c=metaAnalysisDraft($channel);MetaAi::$callback=static function()use($channel){metaPrimaryMessage($channel,'mid.analysis.race','الغاء الطلب',6);};
    metaPrimaryRun();metaPrimaryNoErp($channel.' customer cancellation during extraction invalidates CAS');metaAnalysisSilent($channel.' stale snapshot stays silent');
    foreach (['native-peer','forged-archive'] as $mutation) {
        $c=metaAnalysisDraft($channel);
        $row=MetaDB::table('meta_inbox_messages')->where('conversation_id',$c)->where('direction','inbound')->orderBy('id')->first();
        $dto=json_decode(MetaCrypt::decryptString($row->content),true);
        if ($mutation==='native-peer') $dto['peer_user_id']='901';
        else $dto['text']='اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 2 كيلو فسيخ.';
        MetaDB::table('meta_inbox_messages')->where('id',$row->id)->update(['content'=>MetaCrypt::encryptString(json_encode($dto))]);
        metaPrimaryRun();metaPrimaryNoErp($channel.' altered native authority '.$mutation.' rejected');
    }
}
echo 'meta_order_analysis_only_workflow: '.$metaAnalysisChecks." checks passed\n";
