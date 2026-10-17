<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

// Original FAQ FormRequest, repository and deletion controllers; no controller substitutes.
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$faqCommands=[];$faqIds=[];
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/question_answers/create');preg_match('/name="_token" value="([^"]+)"/',$page,$faqCsrf);
    verify($status===200&&isset($faqCsrf[1])&&str_contains($page,'name="answer_ar"'),'the original FAQ page renders its Arabic and English form fields');
    $faq=['_token'=>$faqCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'question_ar'=>'سؤال محلي أصلي','question_en'=>'Original local question','answer_ar'=>'<p>إجابة عربية أصلية</p>','answer_en'=>'<p>Original answer</p>'];
    verify($http('/admin/question_answers',$faq)[0]===302,'the original FAQ FormRequest autoloads and its repository commits one local command');$faqCommands[]=$faq['_desktop_command'];
    $faqFirst=(int)DB::table('question_answers')->where('question_ar',$faq['question_ar'])->value('id');$faqIds[]=$faqFirst;
    verify($faqFirst>0&&$http('/admin/question_answers',$faq)[0]===302&&DB::table('question_answers')->where('question_ar',$faq['question_ar'])->count()===1,'a lost original FAQ creation reply does not duplicate its HTML answers');
    $faq['_desktop_command']=(string)Str::uuid();$faq['_method']='PUT';$faq['answer_ar']='<p>إجابة عربية معدلة</p>';
    verify($http('/admin/question_answers/'.$faqFirst,$faq)[0]===302&&DB::table('question_answers')->where('id',$faqFirst)->value('answer_ar')===$faq['answer_ar'],'the original FAQ update retains its before-state and HTML answer');$faqCommands[]=$faq['_desktop_command'];
    verify($http('/admin/question_answers/'.$faqFirst,$faq)[0]===302,'the original FAQ update returns the same local response after a lost reply');
    $invalid=$faq;unset($invalid['_method']);$invalid['_desktop_command']=(string)Str::uuid();$invalid['question_en']='';$before=DB::table('desktop_dashboard_commands')->count();
    verify($http('/admin/question_answers',$invalid)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'the original FAQ English validation redirects without a committed journal entry');
    unset($faq['_method']);$faq['_desktop_command']=(string)Str::uuid();$faq['question_ar']='سؤال محلي للحذف';
    verify($http('/admin/question_answers',$faq)[0]===302,'the original FAQ form creates its single-delete fixture');$faqCommands[]=$faq['_desktop_command'];
    $faqSecond=(int)DB::table('question_answers')->where('question_ar',$faq['question_ar'])->value('id');$faqIds[]=$faqSecond;
    $remove=['_token'=>$faqCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http('/admin/question_answers/'.$faqSecond,$remove)[0]===302&&!DB::table('question_answers')->where('id',$faqSecond)->exists()
        &&$http('/admin/question_answers/'.$faqSecond,$remove)[0]===302,'the original FAQ deletion retries before binding its removed model');$faqCommands[]=$remove['_desktop_command'];
    $faq['_desktop_command']=(string)Str::uuid();$faq['question_ar']='سؤال محلي للحذف الجماعي';
    verify($http('/admin/question_answers',$faq)[0]===302,'the original FAQ form creates a second bulk selection');$faqCommands[]=$faq['_desktop_command'];
    $faqThird=(int)DB::table('question_answers')->where('question_ar',$faq['question_ar'])->value('id');$faqIds[]=$faqThird;
    $bulk=['_token'=>$faqCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid(),'ids'=>$faqThird.','.$faqFirst];
    verify($http('/admin/question_answersDeleteAll',$bulk,['Accept: application/json'])[0]===200&&DB::table('question_answers')->whereIn('id',[$faqFirst,$faqThird])->count()===0,'the existing FAQ bulk URL invokes its actual permission-protected controller method');
    $bulk['ids']=$faqFirst.','.$faqThird;
    verify($http('/admin/question_answersDeleteAll',$bulk,['Accept: application/json'])[0]===200,'a reordered FAQ bulk retry returns its receipt after both models disappear');$faqCommands[]=$bulk['_desktop_command'];
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
foreach($faqIds as $id)DB::table('question_answers')->insert(['id'=>$id,'added_by'=>1,'question_ar'=>'سؤال سيرفر مستقل '.$id,'question_en'=>'Server question','answer_ar'=>'إجابة السيرفر','answer_en'=>'Server answer']);
foreach($faqCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($row->route_name==='question_answers.update')verify($resolved['parameters']['question_answer']!==$faqFirst,'FAQ update parameters map to the created server record despite a colliding local ID');
    if($row->route_name==='question_answers.destroy-all'){
        $selected=$resolved['values']['ids'];$first=$selected[0];$original=DB::table('question_answers')->where('id',$first)->value('answer_ar');
        DB::table('question_answers')->where('id',$first)->update(['answer_ar'=>'تعديل إجابة مستقل على السيرفر']);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed FAQ batch was deleted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('question_answers')->whereIn('id',$selected)->count()===count($selected),'one changed answer preserves every FAQ row selected for pending bulk deletion');}
        DB::table('question_answers')->where('id',$first)->update(['answer_ar'=>$original]);
    }
    if(in_array($row->route_name,['question_answers.destroy','question_answers.destroy-all'],true)){
        $selected=$row->route_name==='question_answers.destroy-all'?$resolved['values']['ids']:[$resolved['parameters']['question_answer']];
        $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('question_answer-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked FAQ deletion was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('question_answers')->whereIn('id',$selected)->count()===count($selected),'the original FAQ deletion permission protects both single and bulk selections during replay');}
        $currentRole->givePermissionTo('question_answer-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'the original FAQ controller reconciles exactly once: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('question_answers')->where('question_ar','like','سؤال محلي%')->count()===0&&DB::table('question_answers')->whereIn('id',$faqIds)->count()===count($faqIds),'mapped original FAQ deletions leave every unrelated colliding server record intact');
