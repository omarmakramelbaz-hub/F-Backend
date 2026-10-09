<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Validator};

/** Carry UUID replay protection, while keeping original encrypted receipts in their original database. */
class DesktopDashboardArchive
{
    public function seal(array $receipt,string $source,string $id,string $token,?array $sourceIdentity=null): array
    {
        app(DesktopDashboardImport::class)->verify($receipt);
        Validator::make(['id'=>$id,'token'=>$token],['id'=>'required|uuid','token'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        if($sourceIdentity!==null)Validator::make($sourceIdentity,['snapshot_id'=>'required|uuid','schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        abort_unless(preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$source)
            &&$source!==DB::connection()->getDatabaseName()&&DB::transactionLevel()===0,403);
        return DB::transaction(function()use($receipt,$source,$id,$token,$sourceIdentity){
            $state=DB::selectOne('SELECT * FROM `'.$source.'`.`desktop_dashboard_local_state` WHERE device_id=? FOR UPDATE',[$receipt['device_id']]);
            abort_unless($state&&$state->state==='held'&&$state->refresh_id===$id
                &&hash_equals((string)$state->refresh_token_hash,hash('sha256',$token))
                &&(int)$state->actor_id===(int)$receipt['actor_id']
                &&$state->schema_hash===($sourceIdentity['schema_hash']??$receipt['schema_hash'])
                &&($sourceIdentity===null||$state->snapshot_id===$sourceIdentity['snapshot_id']),409);
            $invalid=DB::selectOne('SELECT COUNT(*) AS total FROM `'.$source.'`.`desktop_dashboard_commands` WHERE status<>? OR device_id<>? OR actor_id<>?',
                ['acknowledged',$receipt['device_id'],$receipt['actor_id']]);abort_unless((int)$invalid->total===0,409);
            $older=DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$source,'desktop_dashboard_archived_commands']);
            if((int)$older->total){
                DB::statement('INSERT INTO desktop_dashboard_archived_commands (device_id,command_id,actor_id,route_name,request_hash,source_database) SELECT device_id,command_id,actor_id,route_name,request_hash,source_database FROM `'.$source.'`.`desktop_dashboard_archived_commands`');
            }
            DB::statement('INSERT INTO desktop_dashboard_archived_commands (device_id,command_id,actor_id,route_name,request_hash,source_database) SELECT device_id,command_id,actor_id,route_name,request_hash,? FROM `'.$source.'`.`desktop_dashboard_commands`',[$source]);
            return ['archived_commands'=>DB::table('desktop_dashboard_archived_commands')->count()];
        });
    }
}
