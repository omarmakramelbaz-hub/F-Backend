<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

(function() use($http,$attempt,$decide,$proof,$serverCsrf,$remoteDevice,$ownerDevice,$origin){
    $row=fn()=>(array)DB::table('resturants')->where('id',100)->first();$original=$row();
    $deviceOriginal=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();
    $restore=function(array $state){unset($state['id']);DB::table('resturants')->where('id',100)->update($state);};
    $baseline=$original;$baseline['is_featured']='no';$baseline['under_contract']='no';$baseline['updated_at']='2020-01-01 00:00:00';$restore($baseline);
    $related=function(){ $result=[];foreach(['users','orders','carts','resturant_products','resturant_areas','reviews','wishlists','media','wallets','resturants'] as $table){
        $query=DB::table($table);if($table==='resturants')$query->where('id','!=',100);$result[$table]=$query->orderBy('id')->get()->map(fn($item)=>(array)$item)->all();}return $result;};
    $relatedBefore=$related();
    try{
        foreach([['Status','is_featured','4'],['UnderContract','under_contract','9'],['Sorting','sortby_is_featured','5']] as [$action,$field,$page]){
            $sorting=$field==='sortby_is_featured';$path=$sorting?'/admin/update_sorting_is_featured/100':'/admin/resturantchange'.$action.'/100';$reservation=$attempt($path);[$status,$reserved]=$decide($reservation);
            verify($status===200&&$reserved['status']==='ready','the original restaurant '.$field.' POST reserves a native transactional outcome');
            $reservationCipher=DB::table('desktop_dashboard_remote_attempts')->where('id',$reservation['id'])->value('response_cipher');
            $form=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),$field=>$sorting?'7':'on','page'=>$page];$before=$row();$journalBefore=DB::table('desktop_dashboard_commands')->count();
            verify($http($path,$form,[...$proof($reserved),'Accept: application/json','X-Fasakhansta-Command: '.Str::uuid()])[0]===409&&$row()===$before,'conflicting native restaurant '.$field.' UUIDs preserve the entire original row');
            foreach([false,true] as $header){$bad=$form;$bad['_desktop_command']=[(string)Str::uuid()];$headers=[...$proof($reserved),'Accept: application/json'];if($header)$headers[]='X-Fasakhansta-Command: '.Str::uuid();
                $reply=$http($path,$bad,$headers);$stored=DB::table('desktop_dashboard_remote_attempts')->where('id',$reservation['id'])->first();
                verify($reply[0]===422&&$row()===$before&&$stored->status==='ready'&&$stored->operation_id===null&&$stored->response_cipher===$reservationCipher&&DB::table('desktop_dashboard_commands')->count()===$journalBefore,
                    'raw native restaurant '.$field.' UUID arrays return422 before conversion with no mutation or outcome'.($header?' with a valid header':' without a header'));}
            [$status,$body,$headers]=$http($path,$form,$proof($reserved));$after=$row();$expected=$origin.'/admin/resturants'.($field!=='under_contract'?'?page='.$page:'');
            $sameBefore=$before;$sameAfter=$after;unset($sameBefore[$field],$sameAfter[$field],$sameBefore['updated_at'],$sameAfter['updated_at']);
            verify($status===302&&in_array('Location: '.$expected,$headers,true)&&$after[$field]===($sorting?7:'yes')&&$sameAfter===$sameBefore&&$decide($reservation,'settle')[1]['status']==='committed',
                'the original restaurant '.$field.' controller commits only its flag/timestamp with its distinct redirect and native receipt');
            $stored=DB::table('desktop_dashboard_remote_attempts')->where('id',$reservation['id'])->first();$outcome=json_decode(Crypt::decryptString($stored->response_cipher),true,512,JSON_THROW_ON_ERROR);
            verify(count($outcome['restaurant_flag_facts']['restaurant_flag_before']['row'])===29&&$outcome['restaurant_flag_after']['row']===$after&&$outcome['restaurant_flag_success']===trans('messages.UpdateSuccessfully'),
                'the encrypted native restaurant '.$field.' outcome retains complete before/after state and original success');
            verify($http($path,$form,$proof($reserved))[1]===$body&&$row()===$after,'a lost native restaurant '.$field.' redirect replays exactly without another update or timestamp');
            $sessionData=null;foreach(glob(config('session.files').'/*') as $sessionFile){$value=@unserialize(file_get_contents($sessionFile),['allowed_classes'=>false]);if(is_array($value)&&($value['_token']??null)===$serverCsrf[1]){$sessionData=$value;break;}}
            verify(($sessionData['success']??null)===trans('messages.UpdateSuccessfully')&&in_array('success',$sessionData['_flash']['old']??[],true),'the actual server session retains restaurant '.$field.' success after a native lost redirect');
            $retry=$attempt($path);[, $retryProof]=$decide($retry);verify($http($path,$form,$proof($retryProof))[1]===$body&&$row()===$after&&$decide($retry,'settle')[1]['status']==='committed',
                'another restaurant '.$field.' transmission reuses the same immutable logical UUID and saved facts');
            $changed=$form;unset($changed[$field]);$changedAttempt=$attempt($path);[, $changedProof]=$decide($changedAttempt);
            verify($http($path,$changed,[...$proof($changedProof),'Accept: application/json'])[0]===409&&$row()===$after&&$decide($changedAttempt,'settle')[1]['status']==='cancelled','changed restaurant '.$field.' content cannot reuse a committed operation and remains cancellable');
            foreach(['status'=>'disabled','owner_resturant_id'=>101] as $column=>$value){$old=DB::table('users')->where('id',1)->value($column);DB::table('users')->where('id',1)->update([$column=>$value]);
                try{verify($http($path,$form,[...$proof($reserved),'Accept: application/json'])[0]===403&&$http($path,$form,[...$proof($retryProof),'Accept: application/json'])[0]===403&&$decide($reservation,'settle')[0]===403,
                    'native restaurant '.$field.' cached responses and committed recovery check current actor '.$column);}
                finally{DB::table('users')->where('id',1)->update([$column=>$old]);}}
            DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>false]);
            try{verify($http($path,$form,[...$proof($reserved),'Accept: application/json'])[0]===401&&$decide($reservation,'settle')[0]===401,'a revoked device cannot obtain cached or committed restaurant '.$field.' outcomes');}
            finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>$deviceOriginal['enabled']]);}
            DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:101'])]);
            try{verify($http($path,$form,[...$proof($reserved),'Accept: application/json'])[0]===403&&$decide($reservation,'settle')[0]===403,'a removed enrolled F branch rejects cached and committed restaurant '.$field.' outcomes');}
            finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceOriginal['branches']]);}
            $rollback=$attempt($path);[, $rollbackProof]=$decide($rollback);$rollbackForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid()];$rollbackBefore=$row();
            DB::unprepared("CREATE TRIGGER reject_restaurant_flag_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture restaurant flag native rollback'; END IF; END");
            try{verify($http($path,$rollbackForm,[...$proof($rollbackProof),'Accept: application/json'])[0]===500&&$row()===$rollbackBefore&&DB::table('desktop_dashboard_remote_attempts')->where('id',$rollback['id'])->value('status')==='ready',
                'failure to persist the native restaurant '.$field.' outcome rolls back its original flag and timestamp');}
            finally{DB::unprepared('DROP TRIGGER reject_restaurant_flag_outcome');}
            verify($decide($rollback,'settle')[1]['status']==='cancelled'&&$http($path,$rollbackForm,[...$proof($rollbackProof),'Accept: application/json'])[0]===409&&$row()===$rollbackBefore,
                'terminal native restaurant '.$field.' cancellation rejects a delayed original POST');
            foreach(['GET','PUT','PATCH','DELETE'] as $method)verify($decide(['id'=>(string)Str::uuid(),'method'=>$method,'path'=>$path])[0]===422,'restaurant '.$field.' outcome reservations reject unreviewed '.$method.' methods');
            $normal=['_token'=>$serverCsrf[1]];verify($http($path,$normal)[0]===302&&$row()[$field]===($sorting?null:'no'),
                'ordinary online restaurant '.$field.' retains the original absent value semantics without a native UUID');
            $normal[$field]=$sorting?'-3':'off';verify($http($path,$normal)[0]===302&&$row()[$field]===($sorting?-3:'no'),
                'ordinary online restaurant '.$field.' preserves its exact original input and database semantics');
        }
        // The reviewed single-restaurant status POST is exercised positively in
        // restaurant-management.php. A zero identity still cannot reserve it.
        $invalidStatus=$attempt('/admin/updateStatus/0');$statusBefore=$row();
        verify($decide($invalidStatus)[0]===422&&!DB::table('desktop_dashboard_remote_attempts')->where('id',$invalidStatus['id'])->exists()&&$row()===$statusBefore,
            'an invalid operating-status identity cannot reserve a native broadcast outcome or change the original restaurant');
        verify($related()===$relatedBefore,'native restaurant checkbox flags preserve all related rows and every other restaurant');
        echo 'Restaurant flag original native outcome acceptance completed'.PHP_EOL;
    }finally{$restore($original);DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['actor_id'=>$deviceOriginal['actor_id'],'enabled'=>$deviceOriginal['enabled'],'branches'=>$deviceOriginal['branches']]);}
})();
