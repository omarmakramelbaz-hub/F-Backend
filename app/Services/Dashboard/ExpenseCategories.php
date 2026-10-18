<?php
namespace App\Services\Dashboard;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

/** A frozen shared baseline with independently editable branch vocabularies. */
class ExpenseCategories
{
    public function canCreate($actor): bool
    {
        $actor=app(TakeawayAccess::class)->actor($actor);
        return (int)$actor->id===1&&$actor->account_type==='admin'&&empty($actor->owner_resturant_id);
    }
    public function catalog(?string $branch=null): array
    {
        $items=[];$local=[];$stock=app(ExpenseStockPurchases::class);
        foreach(BranchExpenses::CATEGORIES as $key)$items[$key]=['key'=>$key,'name'=>__('expenses.cat_'.$key),'active'=>true,'revision'=>0];
        if(Schema::hasTable('branch_expense_categories'))foreach(DB::table('branch_expense_categories')->orderBy('id')->get() as $row){
            $key='custom_'.$row->id;$item=['key'=>$key,'name'=>$row->name,'active'=>true,'revision'=>0,'stock_category'=>$stock->categoryProduct($row->name)];
            if(!empty($row->origin_branch)){$item['origin_branch']=$row->origin_branch;$local[$key]=$item;}else $items[$key]=$item;
        }
        foreach(ExpenseStockPurchases::CATEGORIES as $key=>$name)$items[$key]=['key'=>$key,'name'=>$name,'active'=>true,'revision'=>0,'protected'=>true,'stock_category'=>$key];
        if(Schema::hasTable('branch_expense_category_settings'))foreach(DB::table('branch_expense_category_settings')->get() as $row){
            if(!isset($items[$row->category_key]))continue;
            $items[$row->category_key]['name']=$row->name??$items[$row->category_key]['name'];
            $items[$row->category_key]['active']=(bool)$row->active;$items[$row->category_key]['revision']=(int)$row->revision;
        }
        // Only legacy global definitions are aliases. A local definition keeps
        // its own key even after its original name is reused by another choice.
        $representatives=[];
        foreach($items as $key=>&$item){
            $item['name']=$this->sharedName($item['name']);$item['stock_category']=$item['stock_category']??$stock->categoryProduct($item['name']);
            $identity=$item['stock_category']?'stock:'.$item['stock_category']:'name:'.$this->normalized($item['name']);$item['shared_identity']=$identity;
            if($item['stock_category'])$representatives[$identity]=$item['stock_category'];
            elseif(!isset($representatives[$identity])||(!$items[$representatives[$identity]]['active']&&$item['active']))$representatives[$identity]=$key;
        }
        unset($item);
        foreach($items as $key=>&$item){$item['canonical_key']=$representatives[$item['shared_identity']];unset($item['shared_identity']);if($item['canonical_key']!==$key)$item['active']=false;}
        unset($item);
        if($branch===null||$branch==='all')return $items;
        $overrides=[];
        if(Schema::hasTable('branch_expense_category_overrides'))foreach(DB::table('branch_expense_category_overrides')->where('branch',$branch)->get() as $row)$overrides[$row->category_key]=$row;
        foreach($local as $key=>$item)if($item['origin_branch']===$branch||isset($overrides[$key])){$item['name']=$this->sharedName($item['name']);$item['canonical_key']=$key;$items[$key]=$item;}
        foreach($items as $key=>&$item){$override=$overrides[$key]??null;if($override){$item['name']=$override->name??$item['name'];$item['active']=(bool)$override->active;$item['revision']=(int)$override->revision;}}
        unset($item);
        foreach($items as $key=>&$item)if($item['canonical_key']!==$key){$canonical=$items[$item['canonical_key']];$item['name']=$canonical['name'];$item['active']=false;}
        unset($item);return $items;
    }
    public function options(bool $includeDeleted=true,?string $branch=null): array
    {
        return array_map(fn($item)=>$item['name'],array_filter($this->catalog($branch),fn($item)=>$includeDeleted||$item['active']));
    }
    public function choices(bool $manage=false,?string $branch=null): array
    {
        $items=$this->catalog($branch);$active=array_filter($items,fn($item)=>$item['active']);
        return ['categories'=>array_map(fn($i)=>$i['name'],$items),'active_categories'=>array_map(fn($i)=>$i['name'],$active),
            'stock_categories'=>$this->stockCategories($items),'category_items'=>$manage?array_values($active):[],'category_scope'=>$branch];
    }
    public function stockCategories(?array $items=null): array
    {
        $map=[];foreach($items??$this->catalog() as $key=>$item)if($item['stock_category'])$map[$key]=$item['stock_category'];return $map;
    }
    public function filterKeys(string $key,?string $branch=null): array
    {
        $items=$this->catalog($branch);if(!isset($items[$key]))return [$key];$canonical=$items[$key]['canonical_key'];
        return array_keys(array_filter($items,fn($item)=>$item['canonical_key']===$canonical));
    }
    private function sharedName(string $name): string
    {
        $name=trim(preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u',' ',$name));
        $branches='(?:المحل[ةه](?: الكبرى)?|محل[ةه](?: الكبرى)?|نبرو[ةه]|المنصور[ةه]|منصور[ةه]|شبرا(?: الخيم[ةه])?|رأس البر|راس البر)';
        return trim(preg_replace('/(?:\s*[-–—:،\/|]\s*|\s+|\s*[\(\[\{]\s*)(?:فرع\s+)?'.$branches.'\s*[\)\]\}]?$/u','',$name)??$name);
    }
    private function normalized(string $name): string {return mb_strtolower(trim(preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u',' ',$name)));}
    private function response(array $category,bool $replayed,?string $branch): array {return ['success'=>true,'category'=>$category,'replayed'=>$replayed]+$this->choices(true,$branch);}
    private function baseReady(): void
    {
        abort_unless(Schema::hasTable('branch_expense_categories')&&Schema::hasTable('branch_expense_category_commands')&&Schema::hasTable('branch_expense_category_settings'),503,__('expenses.category_upgrade'));
    }
    private function scopeReady(): void
    {
        $this->baseReady();abort_unless(Schema::hasColumn('branch_expense_categories','origin_branch')&&Schema::hasTable('branch_expense_category_overrides'),503,__('expenses.category_upgrade'));
    }
    private function requireBranch(?string $branch,$actor): string
    {
        if(!$branch)throw ValidationException::withMessages(['branch'=>__('expenses.basic_categories_choose_branch')]);
        app(TakeawayAccess::class)->branch($branch,$actor,true);return $branch;
    }
    public function save(array $values,$actor): array
    {
        abort_unless($this->canCreate($actor),403);
        if(($values['action']??null)==='apply_basic')return $this->applyBasic($values,$actor);
        $values+=['action'=>'create'];
        $v=Validator::make($values,['action'=>'required|in:create,update,delete','key'=>'required_unless:action,create|nullable|string|max:60',
            'name'=>'required_unless:action,delete|nullable|string|max:80','expected_revision'=>'required_unless:action,create|nullable|integer|min:0',
            'branch'=>['nullable','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid'])->validate();
        $name=$v['action']==='delete'?'':$this->sharedName($v['name']);$normalized=$this->normalized($name);
        if($v['action']!=='delete'&&($normalized===''||preg_match('/[<>\x00-\x1F\x7F]/u',$name)))throw ValidationException::withMessages(['name'=>__('expenses.category_invalid')]);
        $this->baseReady();$branch=$v['branch']??null;
        $parts=[$v['action'],$v['action']==='create'?null:$v['key'],$normalized,$v['action']==='create'?null:(int)$v['expected_revision']];
        $hash=hash('sha256',json_encode(array_merge(['branch_category_v1',$branch],$parts)));
        $legacyHash=hash('sha256',json_encode($parts));$legacyNormalized=$v['action']==='delete'?'':$this->normalized($v['name']);$parts[2]=$legacyNormalized;$legacyRawHash=hash('sha256',json_encode($parts));
        return DB::transaction(function()use($v,$actor,$name,$normalized,$hash,$legacyHash,$legacyRawHash,$legacyNormalized,$branch){
            DB::table('users')->where('id',1)->lockForUpdate()->first();abort_unless($this->canCreate($actor),403);
            $command=DB::table('branch_expense_category_commands')->where('request_key',$v['idempotency_key'])->first();
            if($command){
                $scoped=hash_equals($command->request_hash,$hash);$legacy=hash_equals($command->request_hash,$legacyHash)||hash_equals($command->request_hash,$legacyRawHash);
                abort_unless($command->action===$v['action']&&($scoped||$legacy),409,__('expenses.category_conflict'));
                if($scoped)$this->requireBranch($branch,$actor);elseif($branch)app(TakeawayAccess::class)->branch($branch,$actor,true);
                return $this->response(json_decode($command->snapshot,true),true,$branch);
            }
            // Older creates used only the category row as their durable receipt.
            // Replaying one is read-only; a fresh request may never write globally.
            if($v['action']==='create'){
                $legacy=DB::table('branch_expense_categories')->where('request_key',$v['idempotency_key'])->first();
                if($legacy&&empty($legacy->origin_branch)){
                    abort_unless(hash_equals($legacy->name_hash,hash('sha256',$normalized))||hash_equals($legacy->name_hash,hash('sha256',$legacyNormalized)),409,__('expenses.category_conflict'));
                    if($branch)app(TakeawayAccess::class)->branch($branch,$actor,true);
                    return $this->response($this->catalog($branch)['custom_'.$legacy->id],true,$branch);
                }
            }
            $branch=$this->requireBranch($branch,$actor);$this->scopeReady();$items=$this->catalog($branch);$category=null;$replayed=false;
            if($v['action']==='create'){
                $stockCategory=app(ExpenseStockPurchases::class)->categoryProduct($name);if($stockCategory)$category=$items[$stockCategory]??null;
                if(!$category)foreach($items as $item){
                    if($item['canonical_key']!==$item['key'])continue;
                    $matches=$this->normalized($item['name'])===$normalized;
                    if(in_array($item['key'],BranchExpenses::CATEGORIES,true)&&$item['revision']===0)foreach(['ar','en'] as $locale)$matches=$matches||$this->normalized(trans('expenses.cat_'.$item['key'],[],$locale))===$normalized;
                    if($matches){$category=$item;break;}
                }
                if($category){$replayed=$category['active'];if(!$category['active']){$category['active']=true;$this->storeGroup($branch,$items,$category);}}
                else{
                    $nameHash=hash('sha256',$branch.'|'.$normalized);if(DB::table('branch_expense_categories')->where('name_hash',$nameHash)->exists())$nameHash=hash('sha256',$branch.'|'.$normalized.'|'.$v['idempotency_key']);
                    $id=DB::table('branch_expense_categories')->insertGetId(['name'=>$name,'name_hash'=>$nameHash,'origin_branch'=>$branch,'created_by'=>1,'request_key'=>$v['idempotency_key'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                    $category=['key'=>'custom_'.$id,'name'=>$name,'active'=>true,'revision'=>1,'canonical_key'=>'custom_'.$id,'stock_category'=>null];$this->store($branch,$category);
                }
            }else{
                $category=$items[$v['key']]??null;abort_unless($category,404);
                if($v['action']==='delete'&&($category['protected']??false))throw ValidationException::withMessages(['category'=>'بنود مشتريات المخزون أساسية ولا يمكن حذفها.']);
                abort_unless($category['active']&&$category['canonical_key']===$category['key']&&$category['revision']===(int)$v['expected_revision'],409,__('expenses.category_stale'));
                $group=array_filter($items,fn($item)=>$item['canonical_key']===$category['canonical_key']);
                if($v['action']==='update'){
                    $stockCategory=app(ExpenseStockPurchases::class)->categoryProduct($name);
                    if($category['stock_category']&&$stockCategory&&$stockCategory!==$category['stock_category'])throw ValidationException::withMessages(['name'=>__('expenses.category_duplicate')]);
                    foreach($items as $item)if(!isset($group[$item['key']])&&($this->normalized($item['name'])===$normalized||($stockCategory&&$item['stock_category']===$stockCategory)))throw ValidationException::withMessages(['name'=>__('expenses.category_duplicate')]);
                    $category['name']=$name;
                }else $category['active']=false;
                $this->storeGroup($branch,$items,$category);
            }
            $category=$this->catalog($branch)[$category['key']];
            DB::table('branch_expense_category_commands')->insert(['request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'actor_id'=>1,'action'=>$v['action'],'snapshot'=>json_encode($category,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC')]);
            return $this->response($category,$replayed,$branch);
        },3);
    }
    private function applyBasic(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>['required','regex:/^f:[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid'])->validate();
        $this->scopeReady();$hash=hash('sha256',json_encode(['apply_basic',$v['branch']]));
        return DB::transaction(function()use($v,$actor,$hash){
            DB::table('users')->where('id',1)->lockForUpdate()->first();abort_unless($this->canCreate($actor),403);
            $access=app(TakeawayAccess::class);$target=$access->branch($v['branch'],$actor,true);
            $command=DB::table('branch_expense_category_commands')->where('request_key',$v['idempotency_key'])->first();
            if($command){
                abort_unless($command->action==='apply_basic'&&hash_equals($command->request_hash,$hash),409,__('expenses.category_conflict'));$snapshot=json_decode($command->snapshot,true);
                if(empty($snapshot['schema_version'])){
                    // Materialize the previous release's exact receipt once.
                    $sourceItems=[];$definitions=$this->catalog($snapshot['source_branch']??null);
                    foreach($snapshot['categories']??[] as $key=>$name)if(isset($definitions[$key])){$item=$definitions[$key];$item['name']=$name;$item['active']=true;$sourceItems[$key]=$item;}
                    $snapshot['applied_keys']=$this->import($sourceItems,$target['value']);$snapshot['added_count']=count($snapshot['applied_keys']);$snapshot['schema_version']=1;
                    DB::table('branch_expense_category_commands')->where('id',$command->id)->update(['snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
                }
                return ['success'=>true,'basic_categories'=>$snapshot,'replayed'=>true]+$this->choices(true,$target['value']);
            }
            DB::table('resturants')->orderBy('id')->lockForUpdate()->get(['id']);
            $sources=array_values(array_filter($access->branches($actor),function($branch){
                if($branch['kind']!=='f')return false;
                $name=preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{200B}-\x{200F}\x{FEFF}]/u','',$branch['name']);
                $name=trim(preg_replace('/\s+/u',' ',strtr($name,['ة'=>'ه','ى'=>'ي','أ'=>'ا','إ'=>'ا','آ'=>'ا'])));$name=preg_replace('/^فرع\s+/u','',$name);
                return in_array($name,['المحله','محله','المحله الكبري','محله الكبري'],true);
            }));
            abort_unless(count($sources)===1,422,__(count($sources)?'expenses.basic_categories_source_ambiguous':'expenses.basic_categories_source_missing'));
            $source=$access->branch($sources[0]['value'],$actor,true);$sourceItems=$this->catalog($source['value']);$categories=[];
            foreach($sourceItems as $key=>$item)if($item['active']&&$item['canonical_key']===$key)$categories[$key]=$item['name'];
            $applied=$this->import($sourceItems,$target['value']);
            $snapshot=['source_branch'=>$source['value'],'source_name'=>$source['name'],'target_branch'=>$target['value'],'target_name'=>$target['name'],
                'count'=>count($categories),'keys'=>array_keys($categories),'categories'=>$categories,'applied_at'=>now('UTC')->toIso8601String(),
                'schema_version'=>1,'added_count'=>count($applied),'applied_keys'=>$applied];
            DB::table('branch_expense_category_commands')->insert(['request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'actor_id'=>1,'action'=>'apply_basic','snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC')]);
            return ['success'=>true,'basic_categories'=>$snapshot,'replayed'=>false]+$this->choices(true,$target['value']);
        },3);
    }
    private function import(array $sourceItems,string $branch): array
    {
        $items=$this->catalog($branch);$overrides=DB::table('branch_expense_category_overrides')->where('branch',$branch)->pluck('category_key')->all();$existing=array_fill_keys($overrides,true);$applied=[];
        foreach($sourceItems as $key=>$source){
            if(!$source['active']||$source['canonical_key']!==$key)continue;
            $group=array_filter($sourceItems,fn($item)=>$item['canonical_key']===$key);if(array_intersect_key($existing,$group))continue;
            $normalized=$this->normalized($source['name']);$duplicate=false;
            foreach($items as $item)if($item['active']&&!isset($group[$item['key']])&&$this->normalized($item['name'])===$normalized){$duplicate=true;break;}
            if($duplicate)continue;
            foreach($group as $memberKey=>$member){$member['name']=$source['name'];$member['active']=$memberKey===$key;$member['revision']=($items[$memberKey]['revision']??0)+1;$this->store($branch,$member);$existing[$memberKey]=true;$items[$memberKey]=$member;}
            $applied[]=$key;
        }
        return $applied;
    }
    private function storeGroup(string $branch,array $items,array $category): void
    {
        foreach($items as $key=>$member)if($member['canonical_key']===$category['canonical_key']){$member['name']=$category['name'];$member['active']=$category['active']&&$key===$category['key'];$member['revision']++;$this->store($branch,$member);}
    }
    private function store(string $branch,array $item): void
    {
        $values=['name'=>$item['name'],'active'=>$item['active'],'revision'=>$item['revision'],'updated_at'=>now('UTC')];$query=DB::table('branch_expense_category_overrides')->where('branch',$branch)->where('category_key',$item['key']);
        if($query->exists())$query->update($values);else DB::table('branch_expense_category_overrides')->insert(['branch'=>$branch,'category_key'=>$item['key'],'created_at'=>now('UTC')]+$values);
    }
}
