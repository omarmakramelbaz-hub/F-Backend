<?php
namespace App\Services\Dashboard;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

/** Shared vocabulary. Removing a choice never deletes its historical expenses. */
class ExpenseCategories
{
    public function canCreate($actor): bool
    {
        $actor=app(TakeawayAccess::class)->actor($actor);
        return (int)$actor->id===1&&$actor->account_type==='admin'&&empty($actor->owner_resturant_id);
    }
    public function catalog(): array
    {
        $items=[];
        foreach(BranchExpenses::CATEGORIES as $key)$items[$key]=['key'=>$key,'name'=>__('expenses.cat_'.$key),'active'=>true,'revision'=>0];
        if(Schema::hasTable('branch_expense_categories'))foreach(DB::table('branch_expense_categories')->orderBy('id')->get() as $row){
            $key='custom_'.$row->id;$items[$key]=['key'=>$key,'name'=>$row->name,'active'=>true,'revision'=>0];
        }
        if(Schema::hasTable('branch_expense_category_settings'))foreach(DB::table('branch_expense_category_settings')->get() as $row){
            if(!isset($items[$row->category_key]))continue;
            $items[$row->category_key]['name']=$row->name??$items[$row->category_key]['name'];
            $items[$row->category_key]['active']=(bool)$row->active;$items[$row->category_key]['revision']=(int)$row->revision;
        }
        // The six stock purchases are shared by every branch, including branches
        // whose old vocabulary never contained them. Their identity is stable so
        // renaming an expense choice cannot silently remove its stock requirement.
        foreach(ExpenseStockPurchases::CATEGORIES as $key=>$name){
            $items[$key]=['key'=>$key,'name'=>$name,'active'=>true,'revision'=>0,'protected'=>true];
        }
        $stock=app(ExpenseStockPurchases::class);$representatives=[];
        foreach($items as $key=>&$item){
            $item['name']=$this->sharedName($item['name']);
            $item['stock_category']=isset(ExpenseStockPurchases::CATEGORIES[$key])?$key:$stock->categoryProduct($item['name']);
            $identity=$item['stock_category']?'stock:'.$item['stock_category']:'name:'.$this->normalized($item['name']);
            $item['shared_identity']=$identity;
            if($item['stock_category'])$representatives[$identity]=$item['stock_category'];
            elseif(!isset($representatives[$identity])||(!$items[$representatives[$identity]]['active']&&$item['active']))$representatives[$identity]=$key;
        }
        unset($item);
        foreach($items as $key=>&$item){
            $item['canonical_key']=$representatives[$item['shared_identity']];unset($item['shared_identity']);
            // Keep alias keys addressable for old records and filters. Only one
            // normalized choice is offered when recording another expense.
            if($item['canonical_key']!==$key)$item['active']=false;
        }
        unset($item);
        return $items;
    }
    public function options(bool $includeDeleted=true): array
    {
        return array_map(fn($item)=>$item['name'],array_filter($this->catalog(),fn($item)=>$includeDeleted||$item['active']));
    }
    public function choices(bool $manage=false): array
    {
        $items=$this->catalog();$active=array_filter($items,fn($item)=>$item['active']);
        return ['categories'=>array_map(fn($i)=>$i['name'],$items),'active_categories'=>array_map(fn($i)=>$i['name'],$active),
            'stock_categories'=>$this->stockCategories($items),'category_items'=>$manage?array_values($active):[]];
    }
    public function stockCategories(?array $items=null): array
    {
        $map=[];foreach($items??$this->catalog() as $key=>$item)if($item['stock_category'])$map[$key]=$item['stock_category'];return $map;
    }
    public function filterKeys(string $key): array
    {
        $items=$this->catalog();if(!isset($items[$key]))return [$key];$canonical=$items[$key]['canonical_key'];
        return array_keys(array_filter($items,fn($item)=>$item['canonical_key']===$canonical));
    }
    private function sharedName(string $name): string
    {
        $name=trim(preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u',' ',$name));
        $branches='(?:المحل[ةه](?: الكبرى)?|محل[ةه](?: الكبرى)?|نبرو[ةه]|المنصور[ةه]|منصور[ةه]|شبرا(?: الخيم[ةه])?|رأس البر|راس البر)';
        $without=preg_replace('/(?:\s*[-–—:،\/|]\s*|\s+|\s*[\(\[\{]\s*)(?:فرع\s+)?'.$branches.'\s*[\)\]\}]?$/u','',$name);
        return trim($without??$name);
    }
    private function normalized(string $name): string {return mb_strtolower(trim(preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u',' ',$name)));}
    private function response(array $category,bool $replayed): array {return ['success'=>true,'category'=>$category,'replayed'=>$replayed]+$this->choices(true);}
    public function save(array $values,$actor): array
    {
        abort_unless($this->canCreate($actor),403);
        $values+=['action'=>'create'];
        $v=Validator::make($values,['action'=>'required|in:create,update,delete','key'=>'required_unless:action,create|nullable|string|max:60',
            'name'=>'required_unless:action,delete|nullable|string|max:80','expected_revision'=>'required_unless:action,create|nullable|integer|min:0','idempotency_key'=>'required|uuid'])->validate();
        $name=$v['action']==='delete'?'':$this->sharedName($v['name']);$normalized=$this->normalized($name);
        if($v['action']!=='delete'&&($normalized===''||preg_match('/[<>\x00-\x1F\x7F]/u',$name)))throw ValidationException::withMessages(['name'=>__('expenses.category_invalid')]);
        abort_unless(Schema::hasTable('branch_expense_categories')&&Schema::hasTable('branch_expense_category_commands')&&Schema::hasTable('branch_expense_category_settings'),503,__('expenses.category_upgrade'));
        $hash=hash('sha256',json_encode([$v['action'],$v['action']==='create'?null:$v['key'],$normalized,$v['action']==='create'?null:(int)$v['expected_revision']]));
        $legacyNormalized=$v['action']==='delete'?'':$this->normalized($v['name']);
        $legacyHash=hash('sha256',json_encode([$v['action'],$v['action']==='create'?null:$v['key'],$legacyNormalized,$v['action']==='create'?null:(int)$v['expected_revision']]));
        return DB::transaction(function()use($v,$actor,$name,$normalized,$hash,$legacyNormalized,$legacyHash){
            DB::table('users')->where('id',1)->lockForUpdate()->first();abort_unless($this->canCreate($actor),403);
            $command=DB::table('branch_expense_category_commands')->where('request_key',$v['idempotency_key'])->first();
            if($command){abort_unless(hash_equals($command->request_hash,$hash)||hash_equals($command->request_hash,$legacyHash),409,__('expenses.category_conflict'));return $this->response(json_decode($command->snapshot,true),true);}
            $items=$this->catalog();$category=null;$replayed=false;
            if($v['action']==='create'){
                // Support request keys issued before category editing was introduced.
                $legacy=DB::table('branch_expense_categories')->where('request_key',$v['idempotency_key'])->first();
                if($legacy){abort_unless(hash_equals($legacy->name_hash,hash('sha256',$normalized))||hash_equals($legacy->name_hash,hash('sha256',$legacyNormalized)),409,__('expenses.category_conflict'));$category=$items['custom_'.$legacy->id];$replayed=true;}
                $stockCategory=app(ExpenseStockPurchases::class)->categoryProduct($name);
                if(!$category&&$stockCategory){$category=$items[$stockCategory];$replayed=true;}
                if(!$category)foreach($items as $item){
                    $matches=$this->normalized($item['name'])===$normalized;
                    if(in_array($item['key'],BranchExpenses::CATEGORIES,true)&&$item['revision']===0)foreach(['ar','en'] as $locale)$matches=$matches||$this->normalized(trans('expenses.cat_'.$item['key'],[],$locale))===$normalized;
                    if($matches){$category=$item;$replayed=$item['active'];break;}
                }
                if(!$category){
                    // A renamed custom category keeps its original hash for old retries.
                    $nameHash=hash('sha256',$normalized);
                    if(DB::table('branch_expense_categories')->where('name_hash',$nameHash)->exists())$nameHash=hash('sha256',$normalized.'|'.$v['idempotency_key']);
                    $id=DB::table('branch_expense_categories')->insertGetId(['name'=>$name,'name_hash'=>$nameHash,'created_by'=>1,'request_key'=>$v['idempotency_key'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                    $category=['key'=>'custom_'.$id,'name'=>$name,'active'=>true,'revision'=>0];
                }elseif(!$category['active']&&!$legacy){$category['active']=true;$category['revision']++;$this->store($category);}
            }else{
                $category=$items[$v['key']]??null;abort_unless($category,404);
                if($category['protected']??false)throw ValidationException::withMessages(['category'=>'بنود مشتريات المخزون أساسية وموحدة لجميع الفروع ولا يمكن تعديلها أو حذفها.']);
                abort_unless($category['active']&&$category['revision']===(int)$v['expected_revision'],409,__('expenses.category_stale'));
                $group=array_filter($items,fn($item)=>$item['canonical_key']===$category['canonical_key']);
                if($v['action']==='update'){
                    $stockCategory=app(ExpenseStockPurchases::class)->categoryProduct($name);
                    foreach($items as $item)if(!isset($group[$item['key']])&&($this->normalized($item['name'])===$normalized||($stockCategory&&$item['stock_category']===$stockCategory)))throw ValidationException::withMessages(['name'=>__('expenses.category_duplicate')]);
                    $category['name']=$name;
                }else $category['active']=false;
                // Persist the whole shared choice: a suppressed branch alias must
                // not revive an old name after its representative is changed.
                foreach($group as $key=>$member){
                    $member['name']=$category['name'];
                    $member['active']=$category['active']&&$key===$category['key'];
                    $member['revision']++;$this->store($member);
                }
            }
            $category=$this->catalog()[$category['key']];
            DB::table('branch_expense_category_commands')->insert(['request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'actor_id'=>1,'action'=>$v['action'],'snapshot'=>json_encode($category,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC')]);
            return $this->response($category,$replayed);
        },3);
    }
    private function store(array $item): void
    {
        DB::table('branch_expense_category_settings')->updateOrInsert(['category_key'=>$item['key']],['name'=>$item['name'],'active'=>$item['active'],'revision'=>$item['revision'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
}
