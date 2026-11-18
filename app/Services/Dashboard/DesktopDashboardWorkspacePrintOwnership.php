<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{Crypt,DB,Schema,Validator};

/** Root-authenticated outcome auditing never authorizes another native invocation. */
final class DesktopDashboardWorkspacePrintOwnership
{
    public function outcome(object $authenticatedRoot,array $receipt): array
    {
        $keys=array_keys($receipt);sort($keys,SORT_STRING);
        $fields=['effect_id','enrollment_id','origin_command_id','actor_id','branch','revision','claim_token','native_session_id','invocation_id','permit_hash','state','reason'];sort($fields,SORT_STRING);
        abort_unless($keys===$fields,422);
        Validator::make($receipt,['revision'=>'required|integer|min:1','claim_token'=>'required|uuid','native_session_id'=>'required|uuid',
            'permit_hash'=>'required|regex:/^[a-f0-9]{64}$/D','reason'=>'present|string|max:200','effect_id'=>'required|regex:/^[a-f0-9]{64}$/D',
            'enrollment_id'=>'required|regex:/^[a-f0-9]{64}$/D','origin_command_id'=>'required|uuid','actor_id'=>'required|integer|min:1',
            'branch'=>'required|string|max:40','invocation_id'=>'required|uuid','state'=>'required|in:invoked,review'])->validate();
        return DB::transaction(function()use($authenticatedRoot,$receipt){
            $authority=app(DesktopDashboardWorkspaceAuthority::class);$root=$authority->lockAuthenticated($authenticatedRoot);
            $fingerprint=$authority->rootFingerprint($root);
            abort_unless(Schema::hasTable('desktop_dashboard_print_ownership')&&Schema::hasTable(DesktopDashboardWorkspaceCommandAuthorities::TABLE),503);
            $ownership=DB::table('desktop_dashboard_print_ownership')->where('effect_id',$receipt['effect_id'])->lockForUpdate()->first();
            abort_unless($ownership&&$ownership->device_id===$root->id&&$ownership->command_id===$receipt['origin_command_id']
                &&$ownership->enrollment_id===$receipt['enrollment_id']&&hash_equals($fingerprint,$ownership->enrollment_id)
                &&(int)$ownership->actor_id===(int)$receipt['actor_id']&&$ownership->branch===$receipt['branch']
                &&(int)$ownership->revision===(int)$receipt['revision'],403);
            $command=DB::table('desktop_dashboard_commands')->where('device_id',$root->id)->where('command_id',$ownership->command_id)->lockForUpdate()->first();
            $sidecar=DB::table(DesktopDashboardWorkspaceCommandAuthorities::TABLE)->where('workspace_id',$root->id)->where('command_id',$ownership->command_id)->lockForUpdate()->first();
            abort_unless($command&&$sidecar&&(int)$command->actor_id===(int)$ownership->actor_id
                &&(int)$sidecar->actor_id===(int)$ownership->actor_id&&hash_equals($command->request_hash,$sidecar->request_hash),503);
            $proof=app(DesktopDashboardWorkspaceCommandAuthorities::class)->assertHistoryRow((array)$sidecar,$root->id,$fingerprint);
            // Historical grant is audit evidence, even after this actor is retired; it grants no execution scope.
            $authority->validateHistoricalMember($root,$proof['actor_id'],$proof['grant'],$proof['manifest_revision']);
            try{$stored=json_decode(Crypt::decryptString($command->command_cipher),true,512,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){abort(503);}
            $binding=$stored['enrollment_binding']??null;$origin=$stored['envelope']??null;
            abort_unless(is_array($binding)&&($binding['format']??null)===1&&($binding['fingerprint']??null)===$fingerprint
                &&is_array($origin)&&($origin['command_id']??null)===$ownership->command_id
                &&(int)($origin['actor_id']??0)===(int)$ownership->actor_id,503);
            $hash=$this->hash($receipt);
            if($ownership->outcome_hash!==null)abort_unless(hash_equals($ownership->outcome_hash,$hash),409);
            else DB::table('desktop_dashboard_print_ownership')->where('effect_id',$ownership->effect_id)->update([
                'outcome'=>json_encode($receipt,JSON_THROW_ON_ERROR),'outcome_hash'=>$hash,'updated_at'=>now('UTC')]);
            return ['effect_id'=>$ownership->effect_id,'invocation_id'=>$receipt['invocation_id'],'state'=>$receipt['state'],'receipt_sha256'=>$hash];
        });
    }
    private function hash(array $value): string
    {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($sort,$v);};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
}
