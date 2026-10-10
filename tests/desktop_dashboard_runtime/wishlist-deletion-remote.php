<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

(function() use($http,$attempt,$decide,$proof,$serverCsrf,$remoteDevice,$ownerDevice,$origin,&$cookies){
    $firstCheck=$GLOBALS['count'];
    $seed=function(int $id){DB::table('wishlists')->insert(['id'=>$id,'resturant_id'=>100,'user_id'=>20,'created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-02 00:00:00']);};
    $seed(93001);$seed(93002);$seed(93003);
    DB::table('media')->insert(['id'=>93003,'model_type'=>\App\Models\Wishlist::class,'model_id'=>93003,'collection_name'=>'wishlist','name'=>'original-retained','file_name'=>'retained.txt','mime_type'=>'text/plain','disk'=>'public','conversions_disk'=>'public','size'=>18,'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}']);
    $originalMedia=(array)DB::table('media')->where('id',93003)->first();$originalFile=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail(93003)->getPath();mkdir(dirname($originalFile),0700,true);file_put_contents($originalFile,'original-query-delete');$originalHash=hash_file('sha256',$originalFile);
    $path='/admin/userWishlistsDelete/93001';$first=$attempt($path);[$status,$reserved]=$decide($first);
    verify($status===200&&$reserved['status']==='ready','the exact original Wishlist form reserves its native transactional server outcome');
    $form=['_token'=>$serverCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    $parents=['order'=>(array)DB::table('orders')->where('id',1)->first(),'user'=>(array)DB::table('users')->where('id',20)->first(),'restaurant'=>(array)DB::table('resturants')->where('id',100)->first()];
    [$status,$body,$headers]=$http($path,$form,[...$proof($reserved),'Referer: '.$origin.'/admin/resturants/100']);
    verify($status===302&&in_array('Location: '.$origin.'/admin/resturants/100',$headers,true)&&!DB::table('wishlists')->where('id',93001)->exists()&&$decide($first,'settle')[1]['status']==='committed','the original Wishlist redirect and deletion commit together with a native receipt');
    verify($http($path,$form,$proof($reserved))[1]===$body,'a lost native Wishlist response returns its exact original body after its row has disappeared');
    $actualSession=null;
    foreach(glob(config('session.files').'/*') as $sessionFile){$data=@unserialize(file_get_contents($sessionFile),['allowed_classes'=>false]);if(is_array($data)&&($data['_token']??null)===$serverCsrf[1]){$actualSession=$data;break;}}
    verify(($actualSession['success']??null)===trans('messages.DeleteSuccessfully')&&in_array('success',$actualSession['_flash']['old']??[],true),'the exact real browser session retains its original success flash for the next request after a native lost-redirect retry');
    foreach($parents as $kind=>$row){$table=['order'=>'orders','user'=>'users','restaurant'=>'resturants'][$kind];verify((array)DB::table($table)->where('id',$row['id'])->first()===$row,'the native Wishlist outcome preserves the original '.$kind.' row');}
    $retry=$attempt($path);[, $retryProof]=$decide($retry);verify($http($path,$form,$proof($retryProof))[1]===$body&&$decide($retry,'settle')[1]['status']==='committed','a new transmission UUID reuses the same immutable deleted-Wishlist operation and saved before-facts');
    $bad=$form;$bad['_desktop_command']=(string)Str::uuid();verify($http($path,$bad,$proof($reserved))[0]===409,'a committed Wishlist transmission cannot acknowledge a different logical UUID');
    verify($http('/admin/userWishlistsDelete/93002',$form,$proof($reserved))[0]===403&&DB::table('wishlists')->where('id',93002)->exists(),'a capability is bound to its immutable original path before any delete');
    $malformed=$attempt('/admin/userWishlistsDelete/93002');[, $malformedProof]=$decide($malformed);$arrayForm=$form;$arrayForm['_desktop_command']=[(string)Str::uuid()];
    verify($http($malformed['path'],$arrayForm,[...$proof($malformedProof),'Accept: application/json'])[0]===422&&DB::table('wishlists')->where('id',93002)->exists()&&DB::table('desktop_dashboard_remote_attempts')->where('id',$malformed['id'])->value('status')==='ready','an array logical UUID receives real native422 and leaves its original row and reservation untouched');
    verify($decide($malformed,'settle')[1]['status']==='cancelled','malformed native metadata can be terminally cancelled without changing a wishlist');
    DB::table('users')->where('id',1)->update(['owner_resturant_id'=>101]);
    try{verify($http($path,$form,$proof($reserved))[0]===403&&$http($path,$form,$proof($retryProof))[0]===403&&$decide($first,'settle')[0]===403,'both native replay and recovery require current restaurant authority for a saved deleted Wishlist');}
    finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>null]);}
    DB::table('users')->where('id',1)->update(['status'=>'disabled']);try{verify($http($path,$form,$proof($reserved))[0]===403&&$decide($first,'settle')[0]===403,'a disabled actor cannot obtain a committed Wishlist response or native recovery');}finally{DB::table('users')->where('id',1)->update(['status'=>'accepted']);}
    DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>false]);try{verify($http($path,$form,$proof($reserved))[0]===401&&$decide($first,'settle')[0]===401,'a revoked device cannot obtain a committed Wishlist response or native recovery');}finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['enabled'=>true]);}
    DB::table('users')->where('id',1)->update(['app_scope'=>'go_admin']);try{verify($http($path,$form,$proof($reserved))[0]===302&&$decide($first,'settle')[1]['status']==='committed','a central GO admin retains the original guard on an enrolled F Wishlist without a new app-scope restriction');}finally{DB::table('users')->where('id',1)->update(['app_scope'=>'fasakhansta']);}
    foreach(['GET','PUT','PATCH'] as $method)verify($decide(['id'=>(string)Str::uuid(),'method'=>$method,'path'=>$path])[0]===422,'Wishlist outcome reservation rejects the unwishlisted '.$method.' method');
    verify($decide(['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/userWishlistsDeleteDeleteAll'])[0]===422,'the Wishlist increment does not register nonexistent bulk wishlist deletion');
    $rollback=$attempt('/admin/userWishlistsDelete/93002');[, $rollbackProof]=$decide($rollback);$rollbackForm=$form;$rollbackForm['_desktop_command']=(string)Str::uuid();
    DB::unprepared("CREATE TRIGGER reject_wishlist_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture Wishlist outcome rollback'; END IF; END");
    try{verify($http($rollback['path'],$rollbackForm,$proof($rollbackProof))[0]===500&&DB::table('wishlists')->where('id',93002)->exists()&&DB::table('desktop_dashboard_remote_attempts')->where('id',$rollback['id'])->value('status')==='ready','failure to save the reserved native outcome rolls back the original Wishlist deletion');}finally{DB::unprepared('DROP TRIGGER reject_wishlist_outcome');}
    verify($decide($rollback,'settle')[1]['status']==='cancelled'&&$http($rollback['path'],$rollbackForm,$proof($rollbackProof))[0]===409&&DB::table('wishlists')->where('id',93002)->exists(),'cancelled native recovery rejects a late original Wishlist DELETE and retains its row');
    $normal=$form;unset($normal['_desktop_command']);
    $authenticatedCookies=$cookies;$cookies=[];[$anonymousStatus,$anonymousPage]=$http('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$anonymousPage,$anonymousCsrf);
    $anonymousForm=$normal;$anonymousForm['_token']=$anonymousCsrf[1];[$anonymousDelete,$anonymousBody,$anonymousHeaders]=$http('/admin/userWishlistsDelete/93003',$anonymousForm);
    verify($anonymousStatus===200&&$anonymousDelete===302&&in_array('Location: '.$origin.'/admin/login',$anonymousHeaders,true)&&DB::table('wishlists')->where('id',93003)->exists(),'the original custom wishlist query-delete retains its real anonymous IsAdmin login boundary');$cookies=$authenticatedCookies;
    verify($http('/admin/userWishlistsDelete/93003',array_diff_key($normal,['_token'=>true]))[0]===419&&DB::table('wishlists')->where('id',93003)->exists(),'ordinary original wishlist deletion retains real CSRF validation');
    verify($http('/admin/userWishlistsDelete/93003',$normal)[0]===302&&!DB::table('wishlists')->where('id',93003)->exists(),'ordinary online Wishlist deletion preserves the original admin controller without requiring a reservation');
    verify((array)DB::table('media')->where('id',93003)->first()===$originalMedia&&is_file($originalFile)&&hash_file('sha256',$originalFile)===$originalHash,'ordinary original query deletion preserves the real attached media row and file despite the Wishlist model media trait');
    verify($http('/admin/userWishlistsDelete/999999',$normal)[0]===302,'ordinary original query deletion keeps its missing-ID no-op redirect; newly scoped unknown IDs are intentionally retained409 conflicts');
    echo 'WISHLIST_NATIVE_HTTP_COMPLETE '.json_encode(['checks'=>$GLOBALS['count']-$firstCheck,'originalGuard'=>true,'csrf'=>true,'mediaRetained'=>true,'fullDashboard'=>false]).PHP_EOL;
})();
