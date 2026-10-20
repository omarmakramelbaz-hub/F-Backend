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
        abort_if(in_array($actor->status??null,['disabled','declined'],true),403,'حساب الجهاز غير مفعّل.');
        $v=Validator::make($values,['device_id'=>'required|uuid','name'=>'required|string|max:100','nonce'=>'required|string|size:64|regex:/^[a-f0-9]+$/D'])->validate();
        $branches=array_column($this->access->branches($actor),'value');abort_unless(count($branches),403);
        $token=hash_hmac('sha256',$v['device_id'].':'.$actor->id.':'.$v['nonce'],(string)config('app.key'));
        return DB::transaction(function()use($v,$actor,$branches,$token){
            $old=DB::table('desktop_dashboard_devices')->where('id',$v['device_id'])->lockForUpdate()->first();
            if($old){abort_unless((int)$old->actor_id===(int)$actor->id && $old->enabled && hash_equals($old->enrollment_hash,hash('sha256',$v['nonce'])),409,'الجهاز مسجل بالفعل أو تم إيقافه.');}
            else DB::table('desktop_dashboard_devices')->insert(['id'=>$v['device_id'],'actor_id'=>$actor->id,'name'=>$v['name'],'enrollment_hash'=>hash('sha256',$v['nonce']),
                'token_hash'=>hash('sha256',$token),'branches'=>json_encode($branches),'enabled'=>true,'enrolled_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['device_id'=>$v['device_id'],'actor_id'=>(int)$actor->id,'token'=>$token,'branches'=>$old?json_decode($old->branches,true,512,JSON_THROW_ON_ERROR):$branches,'protocol'=>1];
        });
    }
    public function device(string $token): object
    {
        $this->ready();abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),401);
        $device=DB::table('desktop_dashboard_devices')->where('token_hash',hash('sha256',$token))->where('enabled',true)->first();abort_unless($device,401,'ربط الداشبورد المحلية متوقف.');
        $this->actor($device);return $device;
    }
    /** These fields identify one authenticated enrollment, independently of mutable grants. */
    public function enrollmentFingerprint(object $device): string
    {
        abort_unless(is_string($device->id??null)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$device->id)
            &&(is_int($device->actor_id??null)||(is_string($device->actor_id??null)&&preg_match('/^[1-9][0-9]{0,18}$/D',$device->actor_id)))
            &&(int)$device->actor_id>0,401,'ربط الجهاز تغيّر؛ أعد ربطه بعد مراجعة العمليات المحفوظة.');
        foreach(['enrollment_hash','token_hash'] as $field)abort_unless(is_string($device->$field??null)&&preg_match('/^[a-f0-9]{64}$/D',$device->$field),401);
        $time=$device->enrolled_at??null;
        try{
            if($time instanceof \DateTimeInterface)$canonical=\Carbon\Carbon::instance($time)->setTimezone('UTC')->format('Y-m-d H:i:s');
            else{
                abort_unless(is_string($time)&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$time),401);
                $canonical=\Carbon\Carbon::createFromFormat('!Y-m-d H:i:s',$time,'UTC')->format('Y-m-d H:i:s');abort_unless($canonical===$time,401);
            }
        }catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){throw $error;}catch(\Throwable $error){abort(401);}
        return hash('sha256',json_encode([$device->id,(int)$device->actor_id,$device->enrollment_hash,$device->token_hash,$canonical],JSON_THROW_ON_ERROR));
    }
    /** Re-read after authentication under the same write lock used by every receipt. */
    public function lockAuthenticated(object $authenticated): object
    {
        abort_unless(DB::transactionLevel()>0,503);
        $binding=$this->enrollmentFingerprint($authenticated);
        $fresh=DB::table('desktop_dashboard_devices')->where('id',$authenticated->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->enabled&&hash_equals($binding,$this->enrollmentFingerprint($fresh)),401,
            'ربط الجهاز تغيّر؛ العمليات المحفوظة تحتاج مراجعة قبل استكمال المزامنة.');
        return $fresh;
    }
    public function actor(object $device) {
        $actor=$this->access->actor((object)['id'=>$device->actor_id]);
        abort_if(in_array($actor->status??null,['disabled','declined'],true),403,'حساب الجهاز غير مفعّل.');return $actor;
    }
    /** Financial BASE is the persisted enrollment, even when callers pass effective local state. */
    public function baseBranches(object $device,bool $lock=false): array
    {
        $id=$device->id??$device->device_id??null;$query=DB::table('desktop_dashboard_devices')->where('id',$id);
        if($lock)$query->lockForUpdate();$fresh=$query->first();
        if($fresh){abort_unless($fresh->enabled,401);return json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR);}
        // Legacy prepared generations did not transport enrollment. They cannot carry created grants.
        if(config('desktop_dashboard.local')===true&&isset($device->device_id)&&!app(DesktopDashboardCreatedScopes::class)->active()){
            $coverage=json_decode($device->coverage??'{}',true);abort_unless(($coverage['print_protocol']??null)!==1,401);
            return json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);
        }
        abort(401,'دليل ربط الجهاز غير متاح.');
    }
    public function branch(object $device,string $branch,$actor): void
    {
        if(isset($device->device_id))abort_unless(in_array($branch,json_decode($device->branches,true,512,JSON_THROW_ON_ERROR),true),403);
        abort_unless(in_array($branch,$this->baseBranches($device,DB::transactionLevel()>0),true),403,'الفرع خارج نطاق ربط الجهاز.');
        $this->access->branch($branch,$actor);
    }
}
