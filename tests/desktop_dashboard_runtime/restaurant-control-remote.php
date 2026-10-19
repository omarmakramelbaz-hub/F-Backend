<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

(function()use($http,$decide,$proof,$remoteDevice,$origin){
    $rows=fn()=>DB::table('resturants')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();$before=$rows();
    $restore=function()use($before){foreach($before as $row){$id=$row['id'];unset($row['id']);DB::table('resturants')->where('id',$id)->update($row);}};
    $deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();
    $attempt=fn()=>['id'=>(string)Str::uuid(),'method'=>'GET','path'=>'/admin/resturantControl'];
    $get=fn(array $query,array $headers=[])=>$http('/admin/resturantControl?'.http_build_query($query),null,['Referer: '.$origin.'/admin/dashboard',...$headers]);
    try{
        $reservation=$attempt();[$status,$ready]=$decide($reservation);verify($status===200&&$ready['status']==='ready','the original global-control GET reserves one native durable outcome');
        $command=(string)Str::uuid();$query=['_desktop_command'=>$command];[$status,$body]=$get($query,$proof($ready));$after=$rows();
        verify($status===302&&$decide($reservation,'settle')[1]['status']==='committed','native global-control commits its fleet mutation and outcome atomically');
        $raw=DB::table('desktop_dashboard_remote_attempts')->where('id',$reservation['id'])->first();$saved=json_decode(Crypt::decryptString($raw->response_cipher),true,512,JSON_THROW_ON_ERROR);
        verify(count($saved['restaurant_control_facts']['restaurant_control_before'])===count($before)&&$saved['restaurant_control_after']===$after
            &&$saved['restaurant_control_success']===trans('main.update success'),'native encrypted global-control outcomes retain original full-row facts, result and success');
        verify($get($query,$proof($ready))[1]===$body&&$rows()===$after,'a lost native global-control reply cannot toggle the fleet twice');
        $second=$attempt();[, $again]=$decide($second);verify($get($query,$proof($again))[1]===$body&&$rows()===$after
            &&$decide($second,'settle')[1]['status']==='committed','a second native transmission retains the same immutable global logical UUID');
        DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:100'])]);
        try{verify($get($query,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($reservation,'settle')[0]===403&&$rows()===$after,
            'cached and settled native global results require every selected branch enrollment');}finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
        $old=DB::table('users')->where('id',1)->value('status');DB::table('users')->where('id',1)->update(['status'=>'disabled']);
        try{verify($get($query,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($reservation,'settle')[0]===403,'native saved global replies check the current enabled account');}finally{DB::table('users')->where('id',1)->update(['status'=>$old]);}
        $rollback=$attempt();[, $rollbackProof]=$decide($rollback);$rollbackId=(string)Str::uuid();
        DB::unprepared("CREATE TRIGGER reject_native_global_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackId."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture native global rollback'; END IF; END");
        try{verify($get(['_desktop_command'=>$rollbackId],[...$proof($rollbackProof),'Accept: application/json'])[0]===500&&$rows()===$after
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$rollback['id'])->value('status')==='ready','failed native global outcome persistence rolls back every original toggle and leaves uncertainty');}finally{DB::unprepared('DROP TRIGGER reject_native_global_outcome');}
        verify($decide($rollback,'settle')[1]['status']==='cancelled'&&$get(['_desktop_command'=>$rollbackId],[...$proof($rollbackProof),'Accept: application/json'])[0]===409&&$rows()===$after,
            'a cancelled global native attempt rejects its delayed original GET');
        foreach(['HEAD','POST','PUT','PATCH','DELETE'] as $method)verify($decide(['id'=>(string)Str::uuid(),'method'=>$method,'path'=>'/admin/resturantControl'])[0]===422,
            'native global-control reservations reject unreviewed '.$method.' requests');
        foreach(['/admin/resturantControl/100','/admin/resturantControl/','/admin/dashboard'] as $path)verify($decide(['id'=>(string)Str::uuid(),'method'=>'GET','path'=>$path])[0]===422,
            'native global-control coverage cannot widen to '.$path);
        echo 'Original global restaurant native outcomes completed'.PHP_EOL;
    }finally{$restore();DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
})();
