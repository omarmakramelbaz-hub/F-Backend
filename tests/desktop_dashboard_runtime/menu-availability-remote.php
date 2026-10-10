<?php
// The actual original server endpoint with durable native transmission outcomes.
foreach(['f'=>100,'gs'=>60] as $menuKind=>$menuBranch){
    $menuPath='/admin/order-board/menu/'.$menuKind.'/'.$menuBranch.'/products/1/availability';$menuTable=$menuKind==='f'?'resturant_products':'go_store_products';
    $menuAttempt=$attempt($menuPath);[$menuReserveStatus,$menuProof]=$decide($menuAttempt);
    verify($menuReserveStatus===200,'the native server API reserves the exact original availability endpoint: '.$menuKind);
    $menuForm=['_token'=>$serverCsrf[1],'idempotency_key'=>(string)Str::uuid(),'available'=>true,'expected_available'=>false,'expected_revision'=>$menuKind==='gs'?2:null];
    [$menuStatus,$menuBody]=$http($menuPath,$menuForm,[...$proof($menuProof),'Accept: application/json']);
    verify($menuStatus===200&&json_decode($menuBody,true)['item']['available']===true&&$decide($menuAttempt,'settle')[1]['status']==='committed','actual availability and native outcome commit together: '.$menuKind);
    verify($http($menuPath,$menuForm,[...$proof($menuProof),'Accept: application/json'])[1]===$menuBody,'a lost original server availability reply returns its exact JSON: '.$menuKind);
    $menuRetry=$attempt($menuPath);[, $menuRetryProof]=$decide($menuRetry);
    verify($http($menuPath,$menuForm,[...$proof($menuRetryProof),'Accept: application/json'])[1]===$menuBody,'another transmission reuses the same availability operation UUID: '.$menuKind);
    DB::table('users')->where('id',1)->update(['account_type'=>'user']);
    try{verify($http($menuPath,$menuForm,[...$proof($menuProof),'Accept: application/json'])[0]===403,'a stored server outcome requires current original availability authority: '.$menuKind);}
    finally{DB::table('users')->where('id',1)->update(['account_type'=>'admin']);}
    $menuOldBranches=DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->value('branches');
    DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->update(['branches'=>json_encode(array_values(array_diff(json_decode($menuOldBranches,true),[$menuKind.':'.$menuBranch])))]);
    try{verify($http($menuPath,$menuForm,[...$proof($menuProof),'Accept: application/json'])[0]===403,'a stored native outcome requires its current enrolled menu branch: '.$menuKind);}
    finally{DB::table('desktop_dashboard_devices')->where('id',$ownerDevice)->update(['branches'=>$menuOldBranches]);}
    $menuRollback=$menuForm;$menuRollback['idempotency_key']=(string)Str::uuid();$menuRollback['available']=false;$menuRollback['expected_available']=true;$menuRollback['expected_revision']=$menuKind==='gs'?3:null;
    $menuFailure=$attempt($menuPath);[, $menuFailureProof]=$decide($menuFailure);
    DB::unprepared("CREATE TRIGGER fixture_menu_outcome_rollback BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$menuRollback['idempotency_key']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture menu outcome rollback'; END IF; END");
    try{verify($http($menuPath,$menuRollback,[...$proof($menuFailureProof),'Accept: application/json'])[0]===500&&($menuKind==='f'?DB::table($menuTable)->where('id',1)->value('status')==='show':(int)DB::table($menuTable)->where('id',1)->value('revision')===3),'failed reserved outcome rolls back actual product availability and revision: '.$menuKind);}
    finally{DB::unprepared('DROP TRIGGER fixture_menu_outcome_rollback');}
    verify($decide($menuFailure,'settle')[1]['status']==='cancelled'&&$http($menuPath,$menuRollback,[...$proof($menuFailureProof),'Accept: application/json'])[0]===409,'a late original menu request cannot write after terminal cancellation: '.$menuKind);
}
verify($decide(['id'=>(string)Str::uuid(),'method'=>'PUT','path'=>'/admin/order-board/menu/f/100/products/1/availability'])[0]===422,'native menu reservations cannot invent an unsupported HTTP method');
