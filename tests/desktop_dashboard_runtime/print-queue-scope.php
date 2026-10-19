<?php
// Physical jobs created on another device must not become new work in a local generation.
use App\Services\Dashboard\{DesktopDashboardData,PosBranchPrinting};

\Illuminate\Support\Facades\DB::beginTransaction();
try{
    $printScopeDb=\Illuminate\Support\Facades\DB::table('pos_branch_print_jobs');
    $printScopeToken=(string)\Illuminate\Support\Str::uuid();
    foreach([
        ['branch'=>'f:100','ticket_id'=>81001,'kitchen_id'=>82001,'status'=>'pending'],
        ['branch'=>'f:100','ticket_id'=>81002,'kitchen_id'=>82002,'status'=>'claimed','claim_token'=>$printScopeToken,'claimed_by'=>10,'claimed_at'=>now('UTC')],
        ['branch'=>'f:101','ticket_id'=>81003,'kitchen_id'=>82003,'status'=>'pending'],
    ] as $printScopeRow){
        \Illuminate\Support\Facades\DB::table('pos_service_tickets')->insert([
            'id'=>$printScopeRow['ticket_id'],'branch'=>$printScopeRow['branch'],'channel'=>'phone',
            'actor_id'=>$printScopeRow['branch']==='f:100'?10:11,'status'=>'preparing',
            'cart_snapshot'=>'{}','quote_snapshot'=>'{}','business_date'=>now('UTC')->toDateString(),
            'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        $printScopeDb->insert($printScopeRow+['created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    foreach(['central owner'=>$actor,'branch cashier'=>$cashier] as $printScopeLabel=>$printScopeActor){
        $printScopeQueries=app(DesktopDashboardData::class)->queries((object)['id'=>$device],$printScopeActor,['f:100']);
        check(isset($printScopeQueries['pos_branch_print_jobs'])&&$printScopeQueries['pos_branch_print_jobs']->get()->all()===[]
            &&$printScopeQueries['pos_service_tickets']->count()===2,
            $printScopeLabel.' snapshot excludes existing pending and claimed kitchen jobs');
        $printScopeRows=app(DesktopDashboardData::class)->rows($printScopeQueries);
        check($printScopeRows['pos_branch_print_jobs']===[]&&!str_contains(json_encode($printScopeRows,JSON_THROW_ON_ERROR),$printScopeToken),
            $printScopeLabel.' snapshot carries neither server print claims nor their capability token');
    }
    check($printScopeDb->count()===3&&(clone $printScopeDb)->where('status','claimed')->value('claim_token')===$printScopeToken,
        'empty local print scope leaves original server jobs and claim tokens unchanged');
    app(PosBranchPrinting::class)->enqueue('f:100',81004,82004);
    check((clone $printScopeDb)->where('kitchen_id',82004)->value('status')==='pending',
        'the retained print table still accepts new jobs from the original local enqueue service');
}finally{\Illuminate\Support\Facades\DB::rollBack();}
