<?php
// Native transport recognition never widens the current enrolled device branch.
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$stockSettingsBranches=DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->value('branches');
DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:100'])]);
try{
    foreach(['/admin/branch-stock/minimum','/admin/branch-stock/basic-recipes'] as $stockSettingsPath){
        $stockSettingsAttempt=$attempt($stockSettingsPath);
        [$stockSettingsStatus,$stockSettingsProof]=$decide($stockSettingsAttempt);
        verify($stockSettingsStatus===200&&$stockSettingsProof['status']==='ready'&&$stockSettingsProof['path']===$stockSettingsPath,
            'native stock setting reserves its exact current POST path '.$stockSettingsPath);
        $stockSettingsValues=['_token'=>$serverCsrf[1],'branch'=>'f:101','idempotency_key'=>(string)Str::uuid()];
        verify($http($stockSettingsPath,$stockSettingsValues,[...$proof($stockSettingsProof),'Accept: application/json'])[0]===403
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$stockSettingsAttempt['id'])->value('status')==='ready',
            'an owner stock setting cannot use a native reservation outside the current enrolled branch '.$stockSettingsPath);
        verify($decide($stockSettingsAttempt,'settle')[1]['status']==='cancelled',
            'a rejected out-of-scope stock setting settles without an original business write '.$stockSettingsPath);
    }
}finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$stockSettingsBranches]);}
