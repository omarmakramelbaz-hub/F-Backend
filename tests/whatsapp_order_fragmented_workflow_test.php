<?php

namespace {
    // The existing real Laravel/SQLite suite sets up authenticated native projections,
    // encrypted drafts, transactions, and isolated ERP services (671 baseline checks).
    require __DIR__.'/whatsapp_order_workflow_test.php';
}

namespace App\Services\Dashboard {
    final class FragmentedWorkflowCatalog extends TakeawayCatalog
    {
        public static $scenario='VALID';
        public function listing($values,$actor): array
        {
            \app(TakeawayAccess::class)->branch($values['branch'],$actor);
            $options=[['id'=>'whole','label'=>'كامل']];
            if (self::$scenario==='OPTION_MISSING') $options=[['id'=>'clean','label'=>'تنظيف']];
            if (self::$scenario==='OPTION_DUPLICATE') $options[]=['id'=>'whole-other','label'=>'كامل'];
            $feseekh=['id'=>11,'name'=>self::$scenario==='NAME_MISMATCH'?'فسيخ خاص':'فسيخ',
                'available'=>true,'quantity_mode'=>'weight','options'=>$options];
            $renga=['id'=>12,'name'=>'رنجه','available'=>self::$scenario!=='RENGA_UNAVAILABLE',
                'quantity_mode'=>'weight','options'=>[]];
            $items=[$feseekh,$renga];
            if (self::$scenario==='PRODUCT_DUPLICATE') $items[]=$feseekh;
            return ['items'=>$items,'pagination'=>['last_page'=>1]];
        }
    }

    final class FragmentedWorkflowPos extends PosServiceTicket
    {
        public function quote($channel,array $values,$actor): array
        {
            \abort_unless($channel==='phone',422,'CHANNEL_CONTRACT');
            \app(TakeawayAccess::class)->branch($values['branch'],$actor);
            \abort_unless(isset($values['delivery_quote_hash']),422,'DELIVERY_HASH_REQUIRED');
            foreach ($values['items'] as $item) {
                \abort_unless(in_array($item['product_id'],[11,12],true) && $item['quantity_mode']==='weight'
                    && is_string($item['quantity']) && (float)$item['quantity']>0,422,'CATALOG_CONTRACT');
                \abort_unless($item['product_id']===11 ? $item['option_id']==='whole' : $item['option_id']==='',422,'OPTION_CONTRACT');
            }
            $total=10000+PosServiceTicket::$drift;
            return ['quote_hash'=>PosServiceTicket::fingerprint([$values['branch'],$values['items'],$total]),
                'total'=>number_format($total/100,2,'.',''),'total_cents'=>$total];
        }
    }
}

namespace {
    use App\Services\Dashboard\WhatsAppOrderWorkflow;
    use App\Services\Dashboard\WhatsAppOrderAiProvider as Ai;
    use App\Services\Dashboard\WhatsAppOrderConversationEvidence as Evidence;
    use App\Services\Dashboard\PosServiceTicket as Pos;
    use App\Services\Dashboard\BranchCustomers;
    use App\Services\Dashboard\PosServicePhone;
    use App\Services\Dashboard\TakeawayCatalog;
    use App\Services\Dashboard\FragmentedWorkflowCatalog as Catalog;
    use App\Services\Dashboard\FragmentedWorkflowPos;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;

    app()->bind(TakeawayCatalog::class,static fn()=>new Catalog());
    app()->bind(Pos::class,static fn()=>new FragmentedWorkflowPos());
    $fragmentWorkflowChecks=0;
    function fragmentWorkflowExpect($actual,$expected,string $label): void
    {
        $GLOBALS['fragmentWorkflowChecks']++;
        if ($actual!==$expected) throw new \RuntimeException('FAILED: '.$label);
    }
    function fragmentWorkflowReference(string $anchor,string $offer,string $quantity,int $clause,?string $option=null): array
    {
        return ['name_anchor_id'=>$anchor,'business_offer_id'=>$offer,'customer_quantity_id'=>$quantity,
            'business_summary_id'=>'m10','summary_clause_index'=>$clause,'customer_option_id'=>$option];
    }
    /** Synthetic facts only; no production contacts, addresses, prices or IDs. */
    function fragmentWorkflowFixture(string $scenario='VALID',bool $variant=false,bool $cairo=false): array
    {
        if ($cairo) originSetup(); else resetFlow();
        \Carbon\Carbon::setTestNow('2026-10-09 23:00:00 UTC');
        Catalog::$scenario=$scenario;
        config()->set('whatsapp_orders.mode','auto');
        if (!$cairo) config()->set('whatsapp_cart.branch_aliases',['1'=>['المنصورة']]);
        $area=$cairo?'المقطم':'المنصورة'; $address='مدينة التجربة '.$area.' شارع المثال';
        $offer=$variant?'من الكيلو 3 حبه بسعر 300 جنيه، والكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟':'الفسيخ بسعر 300 جنيه للكيلو. تحب قد إيه؟';
        $quantity=$variant?'عايز كيلو ونص من الكيلو ٣ حبه':'عايز كيلو ونص';
        // The first recap clause omits the noun. Its unique selected amount/variant and
        // affirmative basket acceptance prove association, without fabricating any text.
        $recap=$variant?'طلبك: كيلو ونص من الكيلو 3 حبه كامل؛ كيلو رنجه':'طلبك: كيلو ونص كامل؛ كيلو رنجه';
        $chat=[['inbound','الفسيخ بكام؟'],['outbound',$offer],['inbound',$quantity],['outbound','تمام'],
            ['inbound','في رنجه'],['outbound','رنجه بسعر 220 جنيه للكيلو. تحب قد إيه؟'],['inbound','تمام اعملي كيلو'],
            ['outbound','تحبه كامل ولا تنظيف؟'],['inbound','كيلو ونص كامل'],['outbound',$recap],
            ['inbound','تمام موافق'],['outbound','ابعت الاسم ورقم الهاتف والعنوان'],['inbound','عميل تجريبي'],
            ['inbound','201000000001'],['inbound',$address],['outbound','تم استلام الاسم ورقم الهاتف والعنوان وتأكيد الطلب']];
        if ($scenario==='ACK_UNVERIFIED') $chat[15][1]='تمام';
        if ($scenario==='ACCEPTANCE_MISSING') $chat[10][1]='استفسار عن السعر فقط';
        $peer='US.fragmented.workflow';$cid=addConversation($peer);
        foreach ($chat as $index=>[$direction,$text]) insertMessage($cid,'wamid.fragmented.'.$index,$direction,$text,$index+1,null,$peer);
        Ai::$data=['decision'=>'CONFIRMED','customer'=>['name'=>'عميل تجريبي','phone'=>'201000000001',
            'address'=>$address,'area'=>$area,'notes'=>null],'branch_hint'=>null,'fulfillment'=>'DELIVERY','items'=>[
            ['name'=>'فسيخ','quantity'=>'1.5','quantity_mode'=>'weight','option_hint'=>'كامل','cart_reference'=>null,
                'conversation_reference'=>fragmentWorkflowReference('m1','m2','m3',0,'m9')],
            ['name'=>'رنجه','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null,'cart_reference'=>null,
                'conversation_reference'=>fragmentWorkflowReference('m5','m6','m7',1)],
        ],'approximate_total'=>null,'evidence'=>['request_ids'=>['m1','m3','m5','m7','m9','m13','m14','m15'],
            'confirmation_ids'=>['m10','m16'],'customer_acceptance_ids'=>['m11'],'location_id'=>null],'issues'=>[]];
        $branch=$cairo?'f:301':'f:1';$actor=(object)['id'=>7];
        if ($scenario!=='COORDINATES_MISSING') app(BranchCustomers::class)->save([
            'branch'=>$branch,'idempotency_key'=>'00000000-0000-4000-8000-000000000017','name'=>'عميل تجريبي',
            'phone'=>'201000000001','address'=>$address,'area'=>$area,'delivery_notes'=>'',
            'latitude'=>$cairo?30.03:31.04,'longitude'=>$cairo?31.32:31.37],$actor);
        $saved=DB::table('branch_customers')->first();
        if ($scenario==='COORDINATES_ZERO') DB::table('branch_customers')->update(['latitude'=>0,'longitude'=>0]);
        if ($scenario==='COORDINATES_CONFLICT') PosServicePhone::$items=[['phone'=>'201000000001','address'=>$address,
            'latitude'=>32.0,'longitude'=>32.0,'customer_id'=>(int)$saved->id]];
        if ($scenario==='BRANCH_CLOSED') DB::table('test_branches')->where('id',$cairo?301:1)->update(['status'=>'closed']);
        if ($scenario==='EXPLICIT_TEST') insertMessage($cid,'wamid.fragmented.test','inbound','ده طلب تجريبي فقط',17,null,$peer);
        if ($scenario==='LATE_CHANGE') insertMessage($cid,'wamid.fragmented.change','inbound','غير الطلب',17,null,$peer);
        if ($scenario==='CONNECTION_CHECK') {
            \Carbon\Carbon::setTestNow('2026-10-10 23:00:00 UTC');
            insertMessage($cid,'wamid.fragmented.connection','inbound','ااختبار ربط واتساب',86417,null,$peer);
        }
        BranchCustomers::$calls=0;
        return ['cid'=>$cid,'actor'=>$actor,'address'=>$address,'branch'=>$branch,'saved'=>$saved];
    }
    function fragmentWorkflowRun(string $scenario='VALID',bool $variant=false,bool $cairo=false): array
    {
        $fixture=fragmentWorkflowFixture($scenario,$variant,$cairo);
        $workflow=new WhatsAppOrderWorkflow();$metrics=$workflow->process();
        return $fixture+['metrics'=>$metrics,'workflow'=>$workflow,'draft'=>DB::table('whatsapp_order_drafts')->first()];
    }
    function fragmentWorkflowNoOrder(array $case,string $reason,string $label): void
    {
        fragmentWorkflowExpect($case['metrics']['dispatched'],0,$label.' dispatches no order');
        fragmentWorkflowExpect($case['draft']->reason,$reason,$label.' fixed review reason');
        fragmentWorkflowExpect([DB::table('test_tickets')->count(),DB::table('test_kitchen')->count(),DB::table('test_print')->count()],[0,0,0],$label.' no ticket/kitchen/print writes');
        fragmentWorkflowExpect(Pos::$saveCalls,0,$label.' ERP submission is never called');
        fragmentWorkflowExpect(BranchCustomers::$calls,0,$label.' creates or updates no customer');
        fragmentWorkflowExpect(DB::table('test_billing')->count(),0,$label.' creates no bill');
    }

    $positive=fragmentWorkflowRun();
    fragmentWorkflowExpect([$positive['metrics']['dispatched'],$positive['metrics']['errors']],[1,0],'fragmented acknowledged basket dispatches through the actual SQL workflow');
    fragmentWorkflowExpect(Ai::$calls,1,'contextual workflow uses one isolated extraction');
    fragmentWorkflowExpect(count(Ai::$lastTranscript),16,'actual native projection preserves all original fragments');
    fragmentWorkflowExpect(Ai::$lastTranscript[9]['text'],'طلبك: كيلو ونص كامل؛ كيلو رنجه','unnamed recap is retained exactly without synthesized product name');
    fragmentWorkflowExpect($positive['draft']->status,'DISPATCHED','fragmented order has a completed immutable receipt');
    $sealed=json_decode(Crypt::decryptString($positive['draft']->sealed_payload),true,512,JSON_THROW_ON_ERROR);
    fragmentWorkflowExpect($sealed['review']['items'],[
        ['product_id'=>11,'quantity'=>'1.5','quantity_mode'=>'weight','option_id'=>'whole','feature_id'=>0,'product_clean'=>''],
        ['product_id'=>12,'quantity'=>'1','quantity_mode'=>'weight','option_id'=>'','feature_id'=>0,'product_clean'=>''],
    ],'approved exact live products and whole option are sealed with the selected weights');
    fragmentWorkflowExpect([$sealed['review']['latitude'],$sealed['review']['longitude']],[31.04,31.37],'verified saved native coordinates are retained rather than invented from text');
    fragmentWorkflowExpect($sealed['review']['address'],$positive['address'],'explicit same-episode data acknowledgment preserves the literal address');
    fragmentWorkflowExpect($sealed['quote']['total'],'100.00','current isolated ERP quote is authoritative rather than chat offer prices');
    fragmentWorkflowExpect([DB::table('test_tickets')->count(),DB::table('test_kitchen')->count(),DB::table('test_print')->count()],[1,1,1],'one unpaid ticket, kitchen send and print');
    fragmentWorkflowExpect(DB::table('test_tickets')->first()->payment_status,'unpaid','contextual proof never bills the order');
    fragmentWorkflowExpect([DB::table('branch_customers')->count(),BranchCustomers::$calls],[1,0],'unchanged verified customer is reused without duplicate/update');
    fragmentWorkflowExpect(strpos($positive['draft']->extraction,$positive['address']),false,'contextual extraction remains encrypted');
    $review=$sealed['review'];$review['expected_revision']=(int)$positive['draft']->revision;
    fragmentWorkflowExpect($positive['workflow']->dispatch((int)$positive['draft']->id,$review,$positive['actor'])['replayed'],true,'same contextual confirmation replays its receipt');
    fragmentWorkflowExpect(DB::table('test_tickets')->count(),1,'receipt replay cannot duplicate the contextual order');
    $again=$positive['workflow']->process();
    fragmentWorkflowExpect([$again['dispatched'],Ai::$calls],[0,1],'automatic replay does not repeat ERP submission or extraction');

    foreach (['COORDINATES_MISSING','COORDINATES_ZERO','COORDINATES_CONFLICT'] as $scenario)
        fragmentWorkflowNoOrder(fragmentWorkflowRun($scenario),'LOCATION_UNCONFIRMED',$scenario);
    foreach (['ACK_UNVERIFIED','ACCEPTANCE_MISSING','LATE_CHANGE'] as $scenario)
        fragmentWorkflowNoOrder(fragmentWorkflowRun($scenario),'INVALID_EXTRACTION',$scenario);
    foreach (['PRODUCT_DUPLICATE','NAME_MISMATCH','RENGA_UNAVAILABLE','OPTION_MISSING','OPTION_DUPLICATE'] as $scenario)
        fragmentWorkflowNoOrder(fragmentWorkflowRun($scenario),'CATALOG_UNRESOLVED',$scenario);
    fragmentWorkflowNoOrder(fragmentWorkflowRun('BRANCH_CLOSED'),'BRANCH_CLOSED','closed fulfillment branch');
    fragmentWorkflowNoOrder(fragmentWorkflowRun('VALID',true),'CATALOG_UNRESOLVED','fish-per-kilo size is not silently dropped from generic catalog mapping');
    $test=fragmentWorkflowRun('EXPLICIT_TEST');fragmentWorkflowNoOrder($test,'NO_ORDER','explicit test order');
    fragmentWorkflowExpect($test['draft']->status,'NONE','explicit order-test intent cannot become a production order');
    $connection=fragmentWorkflowRun('CONNECTION_CHECK');
    fragmentWorkflowExpect($connection['metrics']['dispatched'],1,'connection check alone does not retroactively declare an accepted order a test');

    $cairo=fragmentWorkflowRun('VALID',false,true);
    fragmentWorkflowExpect([$cairo['metrics']['dispatched'],$cairo['metrics']['errors']],[1,0],'contextual Cairo basket still dispatches through open Shubra');
    $cairoSeal=json_decode(Crypt::decryptString($cairo['draft']->sealed_payload),true,512,JSON_THROW_ON_ERROR);
    fragmentWorkflowExpect([$cairoSeal['review']['branch'],$cairoSeal['review']['delivery_origin_branch']],['f:301','f:309'],'Cairo map origin and Shubra fulfillment remain separately sealed');
    fragmentWorkflowExpect([DB::table('test_tickets')->first()->branch,DB::table('test_print')->first()->branch],['f:301','f:301'],'Shubra receives both contextual order and print');
    fragmentWorkflowExpect([DB::table('resturants')->where('id',309)->value('control'),DB::table('test_branches')->where('id',309)->value('status')],['hide','closed'],'contextual routing never exposes or opens the virtual store');
    \Carbon\Carbon::setTestNow();
    echo 'WHATSAPP_ORDER_FRAGMENTED_WORKFLOW_CHECKS='.$fragmentWorkflowChecks."\n";
}
