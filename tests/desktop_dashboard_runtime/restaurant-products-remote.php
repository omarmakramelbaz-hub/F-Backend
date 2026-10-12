<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

// Original online GET links under the native remote-attempt protocol, including loss of the redirect.
(function() use($http,$decide,$proof,$remoteDevice,$origin){
    $row=fn()=>(array)DB::table('resturant_products')->where('id',1)->first();$original=$row();
    $restore=function(array $state){unset($state['id']);DB::table('resturant_products')->where('id',1)->update($state);};
    $baseline=$original;$baseline['status']='show';$baseline['highest_rated']='no';$baseline['created_at']='2019-01-01 00:00:00';$baseline['updated_at']='2020-01-01 00:00:00';$restore($baseline);
    $related=function(){ $data=[];foreach(['users','resturants','products','categories','product_features','media','orders','carts','wallets','reviews','wishlists'] as $table)
        $data[$table]=DB::table($table)->orderBy('id')->get()->map(fn($part)=>(array)$part)->all();return $data;};$beforeRelated=$related();
    $deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();
    $reservation=fn(string $path)=>['id'=>(string)Str::uuid(),'method'=>'GET','path'=>$path];
    $returnQuery='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';
    $get=function(string $path,array $query,array $headers=[],string $method='GET')use($http,$origin,$returnQuery){return $http($path.'?'.http_build_query($query),null,['Referer: '.$origin.'/admin/resturants/100?'.$returnQuery,...$headers],$method);};
    try{
        foreach([['status','hide'],['highest_rated','yes']] as [$field,$value]){
            $path='/admin/resturant_products/1/update/'.$field;$attempt=$reservation($path);[$status,$reserved]=$decide($attempt);
            verify($status===200&&$reserved['status']==='ready','the original product '.$field.' GET reserves an account-bound native outcome');
            $command=(string)Str::uuid();$query=[$field=>$value,'_desktop_command'=>$command];$before=$row();
            $bad=$query;$bad['_desktop_command']=[$command];verify($get($path,$bad,[...$proof($reserved),'Accept: application/json'])[0]===422&&$row()===$before,'raw native product '.$field.' UUID arrays create no mutation');
            verify($get($path,$query,[...$proof($reserved),'Accept: application/json','X-Fasakhansta-Command: '.Str::uuid()])[0]===409&&$row()===$before,'conflicting native product '.$field.' UUIDs preserve the original row');
            [$status,$body,$headers]=$get($path,$query,$proof($reserved));$after=$row();$sameBefore=$before;$sameAfter=$after;unset($sameBefore[$field],$sameAfter[$field],$sameBefore['updated_at'],$sameAfter['updated_at']);
            verify($status===302&&in_array('Location: '.$origin.'/admin/resturants/100?'.$returnQuery,$headers,true)&&$after[$field]===$value&&$sameBefore===$sameAfter
                &&$decide($attempt,'settle')[1]['status']==='committed','the original product '.$field.' controller commits one enum and its retained native redirect');
            $stored=DB::table('desktop_dashboard_remote_attempts')->where('id',$attempt['id'])->first();$outcome=json_decode(Crypt::decryptString($stored->response_cipher),true,512,JSON_THROW_ON_ERROR);
            verify(count($outcome['restaurant_product_facts']['restaurant_product_before']['row'])===13&&$outcome['restaurant_product_after']['row']===$after
                &&$outcome['restaurant_product_success']===trans('messages.UpdateSuccessfully')&&$outcome['restaurant_product_facts']['restaurant_product_return']['query']===$returnQuery,
                'the encrypted native product '.$field.' outcome retains complete original before/after, original search context and success');
            verify($get($path,$query,$proof($reserved))[1]===$body&&$row()===$after,'a lost native product '.$field.' redirect replays without another enum/timestamp write');
            $retry=$reservation($path);[, $retryProof]=$decide($retry);
            verify($get($path,$query,$proof($retryProof))[1]===$body&&$row()===$after&&$decide($retry,'settle')[1]['status']==='committed','another product '.$field.' transmission reuses the same immutable logical UUID');
            $changed=$query;$changed[$field]=$field==='status'?'show':'no';$changedAttempt=$reservation($path);[, $changedProof]=$decide($changedAttempt);
            verify($get($path,$changed,[...$proof($changedProof),'Accept: application/json'])[0]===409&&$row()===$after&&$decide($changedAttempt,'settle')[1]['status']==='cancelled','changed product '.$field.' content cannot obtain its original saved receipt');
            foreach(['status'=>'disabled','owner_resturant_id'=>101] as $column=>$changedValue){$old=DB::table('users')->where('id',1)->value($column);DB::table('users')->where('id',1)->update([$column=>$changedValue]);
                try{verify($get($path,$query,[...$proof($reserved),'Accept: application/json'])[0]===403&&$decide($attempt,'settle')[0]===403,'native cached/recovered product '.$field.' receipts check current actor '.$column);}
                finally{DB::table('users')->where('id',1)->update([$column=>$old]);}}
            DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:101'])]);
            try{verify($get($path,$query,[...$proof($reserved),'Accept: application/json'])[0]===403&&$decide($attempt,'settle')[0]===403,'removing enrollment rejects cached/recovered product '.$field.' outcomes');}
            finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
            $rollback=$reservation($path);[, $rollbackProof]=$decide($rollback);$rollbackQuery=[$field=>$field==='status'?'show':'no','_desktop_command'=>(string)Str::uuid()];$rollbackBefore=$row();
            DB::unprepared("CREATE TRIGGER reject_product_toggle_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackQuery['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture native product toggle rollback'; END IF; END");
            try{verify($get($path,$rollbackQuery,[...$proof($rollbackProof),'Accept: application/json'])[0]===500&&$row()===$rollbackBefore
                &&DB::table('desktop_dashboard_remote_attempts')->where('id',$rollback['id'])->value('status')==='ready','a failed native product '.$field.' receipt save rolls back its original enum and timestamp');}
            finally{DB::unprepared('DROP TRIGGER reject_product_toggle_outcome');}
            verify($decide($rollback,'settle')[1]['status']==='cancelled'&&$get($path,$rollbackQuery,[...$proof($rollbackProof),'Accept: application/json'])[0]===409&&$row()===$rollbackBefore,'a cancelled native product '.$field.' attempt rejects a delayed original GET');
            foreach(['HEAD','POST','PUT','PATCH','DELETE'] as $method)verify($decide(['id'=>(string)Str::uuid(),'method'=>$method,'path'=>$path])[0]===422,'native product '.$field.' reservations reject unreviewed '.$method.' methods');
            verify($get($path,[$field=>$value])[0]===302&&$row()[$field]===$value,'ordinary online product '.$field.' GET links keep their original behavior without a native UUID');
        }
        verify($related()===$beforeRelated,'native product links preserve every original catalog/media/branch and financial row');
        verify($decide(['id'=>(string)Str::uuid(),'method'=>'GET','path'=>'/admin/resturant/copy/items'])[0]===422,'native menu-copy coverage rejects the unsupported GET method on its exact original POST endpoint');
        echo 'Restaurant product original native GET outcomes completed'.PHP_EOL;
    }finally{$restore($original);}
})();
