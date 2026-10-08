<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema,Validator};

/** Account-scoped dashboard enrollment is independent of the old branch-only POS token. */
class DesktopDashboardDevices
{
    public function __construct(private TakeawayAccess $access) {}
    public function ready(): void {abort_unless(config('desktop_dashboard.enabled') && Schema::hasTable('desktop_dashboard_devices'),503,'تشغيل الداشبورد المحلية لم يُفعّل على السيرفر بعد.');}
    public function enroll(array $values,$actor): array
    {
        $this->ready();$actor=$this->access->actor($actor);
        $v=Validator::make($values,['device_id'=>'required|uuid','name'=>'required|string|max:100','nonce'=>'required|string|size:64|regex:/^[a-f0-9]+$/D'])->validate();
        $branches=array_column($this->access->branches($actor),'value');abort_unless(count($branches),403);
        $token=hash_hmac('sha256',$v['device_id'].':'.$actor->id.':'.$v['nonce'],(string)config('app.key'));
        return DB::transaction(function()use($v,$actor,$branches,$token){
            $old=DB::table('desktop_dashboard_devices')->where('id',$v['device_id'])->lockForUpdate()->first();
            if($old){abort_unless((int)$old->actor_id===(int)$actor->id && $old->enabled && hash_equals($old->enrollment_hash,hash('sha256',$v['nonce'])),409,'الجهاز مسجل بالفعل أو تم إيقافه.');}
            else DB::table('desktop_dashboard_devices')->insert(['id'=>$v['device_id'],'actor_id'=>$actor->id,'name'=>$v['name'],'enrollment_hash'=>hash('sha256',$v['nonce']),
                'token_hash'=>hash('sha256',$token),'branches'=>json_encode($branches),'enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['device_id'=>$v['device_id'],'actor_id'=>(int)$actor->id,'token'=>$token,'branches'=>$branches,'protocol'=>1];
        });
    }
    public function device(string $token): object
    {
        $this->ready();abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),401);
        $device=DB::table('desktop_dashboard_devices')->where('token_hash',hash('sha256',$token))->where('enabled',true)->first();abort_unless($device,401,'ربط الداشبورد المحلية متوقف.');
        $this->access->actor((object)['id'=>$device->actor_id]);return $device;
    }
    public function actor(object $device) {return $this->access->actor((object)['id'=>$device->actor_id]);}
    public function branch(object $device,string $branch,$actor): void
    {
        abort_unless(in_array($branch,json_decode($device->branches,true,512,JSON_THROW_ON_ERROR),true),403,'الفرع خارج نطاق ربط الجهاز.');
        $this->access->branch($branch,$actor);
    }
}
