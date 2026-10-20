<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use App\Services\GoServices\Money;

/** Common branch locking and immutable recovery receipts for operational records. */
class BranchOperations
{
    public TakeawayAccess $access;
    public function __construct(TakeawayAccess $access) {$this->access=$access;}
    public function ready(): void {abort_unless(Schema::hasTable('branch_operation_commands')&&Schema::hasTable('branch_payrolls'),503,'شاشات إدارة الفرع تحتاج تحديث قاعدة البيانات.');}
    public function branches(string $branch,$actor): array
    {
        $this->ready();$actor=$this->access->actor($actor);
        if($branch!=='all')return [$this->access->branch($branch,$actor)];
        abort_unless($actor->account_type==='admin'&&empty($actor->owner_resturant_id),403);
        return $this->access->branches($actor);
    }
    public function rules(): array {return ['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid','expected_revision'=>'nullable|integer|min:1'];}
    public function write(string $kind,array $v,$actor,callable $work): array
    {
        $this->ready();$actor=$this->access->actor($actor);abort_unless($this->access->permissions($actor)['can_checkout'],403);
        $hash=PosServiceTicket::fingerprint([$kind,$v]);
        return DB::transaction(function()use($kind,$v,$actor,$hash,$work){
            $branch=$this->access->branch($v['branch'],$actor,true);
            $old=DB::table('branch_operation_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();
            if($old){abort_unless(hash_equals($old->request_hash,$hash),409,'رقم العملية مستخدم لبيانات مختلفة.');return json_decode($old->result,true)+['replayed'=>true];}
            $result=$work($branch,$actor)+['success'=>true];
            DB::table('branch_operation_commands')->insert(['branch'=>$v['branch'],'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'kind'=>$kind,'result'=>json_encode($result,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return $result+['replayed'=>false];
        },3);
    }
    public function recover(array $values,$actor): array
    {
        $v=Validator::make($values,$this->rules())->validate();$this->ready();$actor=$this->access->actor($actor);$this->access->branch($v['branch'],$actor);
        $old=DB::table('branch_operation_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();
        return $old?json_decode($old->result,true)+['found'=>true,'replayed'=>true]:['success'=>true,'found'=>false];
    }
    public function money($value,bool $zero=true): int
    {
        try{$amount=Money::minor($value);}catch(\InvalidArgumentException $e){abort(422,'أدخل مبلغًا صحيحًا بمنزلتين عشريتين.');}
        abort_unless($amount>=($zero?0:1)&&$amount<=100000000,422,'المبلغ خارج الحدود المسموحة.');return $amount;
    }
    public function revision(object $row,array $v): void {abort_unless((int)($v['expected_revision']??0)===(int)$row->revision,409,'البيانات تغيرت. حدّث الصفحة قبل الحفظ.');}
}
