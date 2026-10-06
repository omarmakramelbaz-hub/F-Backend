<?php
namespace App\Services\Dashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared expense vocabulary; only the persisted primary owner can add entries. */
class ExpenseCategories
{
    public function canCreate($actor): bool
    {
        $actor=app(TakeawayAccess::class)->actor($actor);
        return (int)$actor->id===1&&$actor->account_type==='admin'&&empty($actor->owner_resturant_id);
    }
    public function options(): array
    {
        $items=[];foreach(BranchExpenses::CATEGORIES as $key)$items[$key]=__('expenses.cat_'.$key);
        if(Schema::hasTable('branch_expense_categories'))foreach(DB::table('branch_expense_categories')->orderBy('id')->get() as $row)$items['custom_'.$row->id]=$row->name;
        return $items;
    }
    private function normalized(string $name): string {return mb_strtolower(trim(preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u',' ',$name)));}
    public function save(array $values,$actor): array
    {
        abort_unless($this->canCreate($actor),403);
        $v=Validator::make($values,['name'=>'required|string|max:80','idempotency_key'=>'required|uuid'])->validate();
        $name=trim(preg_replace('/\s+/u',' ',$v['name']));$normalized=$this->normalized($name);
        if($normalized===''||preg_match('/[<>\x00-\x1F\x7F]/u',$name))throw ValidationException::withMessages(['name'=>'اكتب اسم بند مصروفات صحيح.']);
        abort_unless(Schema::hasTable('branch_expense_categories'),503,'إضافة بنود المصروفات تحتاج تحديث قاعدة البيانات.');
        return DB::transaction(function()use($v,$actor,$name,$normalized){
            DB::table('users')->where('id',1)->lockForUpdate()->first();abort_unless($this->canCreate($actor),403);
            $hash=hash('sha256',$normalized);$old=DB::table('branch_expense_categories')->where('request_key',$v['idempotency_key'])->first();
            if($old)abort_unless(hash_equals($old->name_hash,$hash),409,'رقم العملية مستخدم لبند مختلف.');
            if(!$old)$old=DB::table('branch_expense_categories')->where('name_hash',$hash)->first();
            foreach(BranchExpenses::CATEGORIES as $key)foreach(['ar','en'] as $locale)if($this->normalized(trans('expenses.cat_'.$key,[],$locale))===$normalized)return ['success'=>true,'category'=>['key'=>$key,'name'=>__('expenses.cat_'.$key)],'categories'=>$this->options(),'replayed'=>true];
            $id=$old?$old->id:DB::table('branch_expense_categories')->insertGetId(['name'=>$name,'name_hash'=>$hash,'created_by'=>1,'request_key'=>$v['idempotency_key'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['success'=>true,'category'=>['key'=>'custom_'.$id,'name'=>$old?$old->name:$name],'categories'=>$this->options(),'replayed'=>(bool)$old];
        },3);
    }
}
