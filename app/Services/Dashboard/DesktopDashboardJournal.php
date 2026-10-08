<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

/** A successful original business operation and its outbox entry commit together. */
class DesktopDashboardJournal
{
    public function __construct(private DesktopDashboardReferences $references) {}
    public function execute(string $device, string $command, int $actor, string $route, array $payload, array $dependencies, callable $work): array
    {
        Validator::make(['device'=>$device,'command'=>$command,'actor'=>$actor,'route'=>$route,'dependencies'=>$dependencies], [
            'device'=>'required|uuid','command'=>'required|uuid','actor'=>'required|integer|min:1',
            'route'=>'required|string|max:150','dependencies'=>'array|max:1000','dependencies.*'=>'required|uuid|distinct',
        ])->validate();
        abort_if(in_array($command, $dependencies, true), 422, 'العملية لا يمكن أن تعتمد على نفسها.');
        $prepared=$this->references->prepare($device,$route,$payload,$command);
        $payload=$prepared['payload'];$dependencies=array_values(array_unique(array_merge($dependencies,$prepared['dependencies'])));
        // References to an entity produced by an earlier replay of this same operation are not dependencies.
        $dependencies=array_values(array_filter($dependencies,fn($id)=>$id!==$command));
        $hash = $this->fingerprint([$actor, $route, $payload, $dependencies]);
        return DB::transaction(function () use ($device,$command,$actor,$route,$payload,$dependencies,$work,$hash) {
            $old = DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$command)->lockForUpdate()->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash,$hash),409,'رقم العملية محفوظ لبيانات مختلفة.');
                return $this->saved($old->local_result_cipher)['result'];
            }
            foreach ($dependencies as $dependency) {
                abort_unless(DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$dependency)->exists(),409,'العملية السابقة لم تُحفظ؛ لا يمكن حفظ عملية تابعة لها.');
            }
            $result = $work();
            if (!is_array($result)) throw new \LogicException('A journaled business operation must return its persisted result.');
            // Collections and date objects must have the same JSON representation on first reply and replay.
            $result=json_decode($this->json($result),true,512,JSON_THROW_ON_ERROR);
            $references=$this->references->capture($device,$command,$route,$result,$payload,$actor);
            $now = now('UTC');
            DB::table('desktop_dashboard_commands')->insert([
                'device_id'=>$device,'command_id'=>$command,'actor_id'=>$actor,'route_name'=>$route,'request_hash'=>$hash,
                'command_cipher'=>Crypt::encryptString($this->json($payload)),
                'local_result_cipher'=>Crypt::encryptString($this->json(['format'=>1,'result'=>$result,'references'=>$references])),
                'dependencies'=>$this->json($dependencies),'status'=>'pending','attempts'=>0,'created_at'=>$now,'updated_at'=>$now,
            ]);
            return $result;
        });
    }

    public function pending(string $device, int $limit=100): array
    {
        $rows=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('status','!=','acknowledged')->orderBy('sequence')->limit(min(100,max(1,$limit)))->get();
        $ready=[];
        foreach ($rows as $row) {
            // Stop at the first conflict or dependency: never move a later closing ahead of its receipts.
            if ($row->status==='conflict') break;
            $dependencies=json_decode($row->dependencies,true,512,JSON_THROW_ON_ERROR);
            if (count($dependencies) && DB::table('desktop_dashboard_commands')->where('device_id',$device)->whereIn('command_id',$dependencies)->where('status','!=','acknowledged')->exists()) break;
            $saved=$this->saved($row->local_result_cipher);
            $ready[]=['sequence'=>(int)$row->sequence,'command_id'=>$row->command_id,'actor_id'=>(int)$row->actor_id,'route_name'=>$row->route_name,
                'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR),
                'local_result'=>$saved['result'],'local_references'=>$saved['references'],
                'dependencies'=>$dependencies,'occurred_at'=>$row->created_at];
            // Apply and confirm one command at a time. The next query sees acknowledged dependencies.
            break;
        }
        return $ready;
    }

    public function acknowledge(string $device,string $command,array $receipt): void
    {
        abort_unless(($receipt['command_id']??'')===$command && ($receipt['device_id']??'')===$device
            && ($receipt['committed']??false)===true,422,'تأكيد السيرفر لا يخص هذه العملية.');
        DB::transaction(function()use($device,$command,$receipt){
            $row=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$command)->lockForUpdate()->first();abort_unless($row,404);
            if($row->status==='acknowledged') {
                $old=json_decode(Crypt::decryptString($row->server_result_cipher),true,512,JSON_THROW_ON_ERROR);
                abort_unless(hash_equals($this->fingerprint($old),$this->fingerprint($receipt)),409,'السيرفر أرسل تأكيدًا مختلفًا للعملية نفسها.');return;
            }
            $now=now('UTC');
            DB::table('desktop_dashboard_commands')->where('sequence',$row->sequence)->update(['status'=>'acknowledged','server_result_cipher'=>Crypt::encryptString($this->json($receipt)),'last_error'=>null,'acknowledged_at'=>$now,'updated_at'=>$now]);
        });
    }

    public function failed(string $device,string $command,string $message,bool $conflict=false): void
    {
        DB::transaction(function()use($device,$command,$message,$conflict){
            $row=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$command)->lockForUpdate()->first();abort_unless($row,404);
            if($row->status==='acknowledged')return;
            DB::table('desktop_dashboard_commands')->where('sequence',$row->sequence)->update(['status'=>$conflict?'conflict':'pending','attempts'=>$row->attempts+1,'last_error'=>Str::limit($message,1000,''),'updated_at'=>now('UTC')]);
        });
    }

    public function counts(string $device): array
    {
        $counts=DB::table('desktop_dashboard_commands')->where('device_id',$device)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total','status')->all();
        return ['pending'=>(int)($counts['pending']??0),'conflicts'=>(int)($counts['conflict']??0),'acknowledged'=>(int)($counts['acknowledged']??0)];
    }
    private function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); }
    private function saved(string $cipher): array
    {
        $value=json_decode(Crypt::decryptString($cipher),true,512,JSON_THROW_ON_ERROR);
        return isset($value['format'],$value['result']) && $value['format']===1?$value:['format'=>1,'result'=>$value,'references'=>[]];
    }
    public function fingerprint(array $value): string
    {
        $sort=function($value)use(&$sort){if(!is_array($value))return $value;if(array_keys($value)!==range(0,count($value)-1))ksort($value);return array_map($sort,$value);};
        return hash('sha256',$this->json($sort($value)));
    }
}
