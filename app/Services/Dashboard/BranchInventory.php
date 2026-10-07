<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema,Validator};

/** Independent raw goods, explicit menu recipes, and transaction-bound ingredient movements. */
class BranchInventory
{
    private BranchOperations $ops;
    private const FEATURES=['half'=>'نصف','quarter'=>'ربع','combo'=>'كومبو','large'=>'كبير','medium'=>'وسط'];
    public function __construct(BranchOperations $ops){$this->ops=$ops;}
    public function installed(): bool{return Schema::hasTable('branch_recipe_sales');}
    private function ready(): void{abort_unless($this->installed(),503,'المخزون والوصفات يحتاجان تحديث قاعدة البيانات.');}
    private function branch(string $value,$actor): array{$b=$this->ops->access->branch($value,$actor);abort_unless($b['kind']==='f',404);return $b;}
    public function canManage($actor): bool{$a=$this->ops->access->actor($actor);return in_array($a->account_type,['admin','resturant_owner'],true);}
    public function quantity(int $units): string{$n=abs($units);$s=intdiv($n,1000000).'.'.str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);return ($units<0?'-':'').rtrim(rtrim($s,'0'),'.');}
    private function units(string $value): int
    {
        abort_unless(preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,3})?$/D',$value),422,'أدخل كمية حتى ثلاث منازل عشرية.');
        $p=explode('.',$value);$n=(int)$p[0]*1000000+(int)str_pad($p[1]??'',6,'0');abort_unless($n>0&&$n<=1000000000000,422,'الكمية يجب أن تكون موجبة وحتى مليون.');return $n;
    }
    private function ingredientList(): array
    {
        return DB::table('stock_ingredients')->orderBy('position')->get()->map(fn($r)=>['id'=>(int)$r->id,'name'=>$r->name,'unit'=>$r->unit,'unit_label'=>$r->unit==='kg'?'كجم':'قطعة'])->keyBy('id')->all();
    }
    private function stocks(string $branch): array{return DB::table('branch_inventory')->where('branch',$branch)->get()->keyBy('ingredient_id')->all();}
    private function stock(array $i,?object $r): array{return ['ingredient_id'=>$i['id'],'unit'=>$i['unit'],'unit_label'=>$i['unit_label'],'quantity'=>$this->quantity((int)($r->quantity_units??0)),'negative'=>(int)($r->quantity_units??0)<0,'revision'=>(int)($r->revision??0)];}
    public function listing(array $values,$actor): array
    {
        $this->ready();$v=Validator::make($values,['branch'=>'required|string|max:30','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1'])->validate();$b=$this->branch($v['branch'],$actor);
        $ingredients=$this->ingredientList();$stocks=$this->stocks($b['value']);$search=trim($v['search']??'');$items=[];
        foreach($ingredients as $i)if($search===''||mb_stripos($i['name'],$search)!==false)$items[]=$i+['stock'=>$this->stock($i,$stocks[$i['id']]??null)];
        $history=DB::table('branch_inventory_movements')->where('branch',$b['value'])->orderByDesc('id')->limit(30)->get();$actors=DB::table('users')->whereIn('id',$history->pluck('actor_id')->filter())->pluck('name','id');
        $legacy=Schema::hasTable('branch_stock')?DB::table('branch_stock')->leftJoin('resturant_products','resturant_products.id','=','branch_stock.product_id')->where('branch_stock.branch',$b['value'])->where('branch_stock.quantity_units','!=',0)->select('branch_stock.*','resturant_products.product_name')->orderBy('branch_stock.id')->get():collect();
        $ids=DB::table('resturant_products')->where('resturant_id',$b['id'])->where('status','show')->pluck('id')->all();
        $mapped=$this->saleRecipes($b['value'],$ids)+app(BranchStock::class)->directBalances($b['value'],$ids);$unconfigured=count(array_diff($ids,array_keys($mapped)));
        $unmapped=DB::table('branch_recipe_sales')->where('branch',$b['value'])->orderByDesc('id')->limit(30)->get()->map(function($r){$s=json_decode($r->snapshot,true);return !empty($s['unmapped'])?['source_type'=>$r->source_type,'source_id'=>$r->source_id,'items'=>$s['unmapped']]:null;})->filter()->values()->all();
        return ['success'=>true,'branch'=>$b,'items'=>$items,'ingredients'=>array_values($ingredients),'can_manage_recipes'=>$this->canManage($actor),'unconfigured_count'=>$unconfigured,'unmapped_sales'=>$unmapped,
            'pagination'=>['page'=>1,'last_page'=>1,'total'=>count($items)],'legacy'=>$legacy->map(fn($r)=>['id'=>(int)$r->id,'name'=>$r->product_name?:'صنف سابق #'.$r->product_id,'quantity'=>$this->quantity((int)$r->quantity_units),'unit_label'=>$r->unit==='kg'?'كجم':'قطعة'])->all(),
            'history'=>$history->map(fn($r)=>['id'=>(int)$r->id,'name'=>$r->name,'unit_label'=>$r->unit==='kg'?'كجم':'قطعة','quantity'=>$this->quantity((int)$r->quantity_units),'balance'=>$this->quantity((int)$r->balance_units),'source_type'=>$r->source_type,'source_id'=>$r->source_id,'actor'=>$actors[$r->actor_id]??'النظام','supplier'=>$r->supplier,'notes'=>$r->notes,'created_at'=>\Carbon\Carbon::parse($r->created_at,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i')])->all()];
    }
    public function receive(array $values,$actor): array
    {
        $this->ready();$v=Validator::make($values,$this->ops->rules()+['ingredient_id'=>'required|integer|min:1','unit'=>'required|in:kg,piece','quantity'=>'required|string|max:16','supplier'=>'nullable|string|max:150','notes'=>'nullable|string|max:500'])->validate();
        $units=$this->units($v['quantity']);abort_if($v['unit']==='piece'&&$units%1000000,422,'توريد القطع يجب أن يكون عددًا صحيحًا.');
        return $this->ops->write('inventory.receive',$v,$actor,function($b,$a)use($v,$units){
            abort_unless($b['kind']==='f',404);$i=$this->ingredientList()[$v['ingredient_id']]??null;abort_unless($i,404,'اختر صنفًا من قائمة البضاعة فقط.');abort_unless($i['unit']===$v['unit'],422,'وحدة البضاعة مختلفة.');
            $r=$this->lockedStock($b['value'],$i['id']);$id=$this->move($r,$i,$units,'receipt',$a->id.':'.$v['idempotency_key'],(int)$a->id,$v['supplier']??'',$v['notes']??'');
            return ['receipt'=>['id'=>$id,'branch'=>$b['value'],'ingredient_id'=>$i['id'],'unit'=>$i['unit'],'quantity'=>$v['quantity'],'idempotency_key'=>$v['idempotency_key']],
                'stock'=>$this->stock($i,DB::table('branch_inventory')->where('id',$r->id)->first())];
        });
    }
    private function lockedStock(string $branch,int $id): object
    {
        // All writers already hold the restaurant row lock, including first receipt and sales.
        $q=DB::table('branch_inventory')->where('branch',$branch)->where('ingredient_id',$id);$r=(clone $q)->lockForUpdate()->first();
        if(!$r){DB::table('branch_inventory')->insert(['branch'=>$branch,'ingredient_id'=>$id,'quantity_units'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);$r=$q->lockForUpdate()->first();}return $r;
    }
    private function move(object $r,array $i,int $delta,string $type,string $source,?int $actor,string $supplier='',string $notes=''): int
    {
        $old=DB::table('branch_inventory_movements')->where('stock_id',$r->id)->where('source_type',$type)->where('source_id',$source)->first();if($old)return (int)$old->id;
        $balance=(int)$r->quantity_units+$delta;abort_if(abs($balance)>1000000000000000,422,'رصيد البضاعة خارج الحد المسموح.');
        DB::table('branch_inventory')->where('id',$r->id)->update(['quantity_units'=>$balance,'revision'=>(int)$r->revision+1,'updated_at'=>now('UTC')]);
        return DB::table('branch_inventory_movements')->insertGetId(['stock_id'=>$r->id,'branch'=>$r->branch,'ingredient_id'=>$i['id'],'name'=>$i['name'],'unit'=>$i['unit'],'quantity_units'=>$delta,'balance_units'=>$balance,'source_type'=>$type,'source_id'=>$source,'actor_id'=>$actor,'supplier'=>$supplier?:null,'notes'=>$notes?:null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    private function features(object $product): array
    {
        $out=[['id'=>0,'label'=>'الصنف الأساسي']];
        if(!empty($product->product_id)&&Schema::hasTable('product_features'))foreach(DB::table('product_features')->where('product_id',$product->product_id)->orderBy('id')->get() as $f)if(isset(self::FEATURES[$f->name]))$out[]=['id'=>(int)$f->id,'label'=>self::FEATURES[$f->name]];
        return $out;
    }
    private function recipe(?object $r): ?array
    {
        if(!$r)return null;return ['id'=>(int)$r->id,'product_id'=>(int)$r->product_id,'unit'=>$r->unit,'revision'=>(int)$r->revision,'variants'=>json_decode($r->variants,true),'raw_stock'=>!empty($r->raw_stock),'updated_at'=>(string)($r->updated_at??'')];
    }
    public function recipes(array $values,$actor): array
    {
        $this->ready();$v=Validator::make($values,['branch'=>'required|string|max:30','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1'])->validate();$b=$this->branch($v['branch'],$actor);
        $q=DB::table('resturant_products')->where('resturant_id',$b['id']);$search=trim($v['search']??'');if($search!=='')$q->where('product_name','like','%'.$search.'%');
        $total=(clone $q)->count();$last=max(1,(int)ceil($total/50));$page=min($last,(int)($v['page']??1));$rows=$q->orderBy('product_name')->orderBy('id')->offset(($page-1)*50)->limit(50)->get();
        $recipes=$this->saleRecipes($b['value'],$rows->pluck('id')->all());$direct=app(BranchStock::class)->directBalances($b['value'],$rows->pluck('id')->all());$items=[];
        $ingredients=[];foreach($this->ingredientList() as $i)$ingredients[$this->stockName($i['name'])]=$i;
        foreach($rows as $p){$side=$ingredients[$this->sideStockName($p->product_name)]??null;
            $items[]=['id'=>(int)$p->id,'name'=>$p->product_name,'features'=>$this->features($p),'recipe'=>$this->recipe($recipes[$p->id]??null),'stock_source'=>isset($recipes[$p->id])?(!empty($recipes[$p->id]->raw_stock)?'ingredient':'recipe'):(isset($direct[$p->id])?'direct':($side?'ingredient_preview':'unconfigured')),
                'recipe_hint'=>$side?[['ingredient_id'=>$side['id'],'measure'=>'g','quantity'=>'']]:[]];
        }
        return ['success'=>true,'branch'=>$b,'items'=>$items,'ingredients'=>array_values($this->ingredientList()),'can_manage'=>$this->canManage($actor),'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$total]];
    }
    public function saveRecipe(array $values,$actor): array
    {
        $this->ready();abort_unless($this->canManage($actor),403,'تعديل الوصفات متاح للإدارة والمالك فقط.');
        $v=Validator::make($values,$this->ops->rules()+['product_id'=>'required|integer|min:1','feature_id'=>'required|integer|min:0','unit'=>'required|in:kg,piece','components'=>'required|array|min:1|max:'.count($this->ingredientList()),'components.*.ingredient_id'=>'required|integer|min:1|distinct','components.*.measure'=>'required|in:g,kg,piece','components.*.quantity'=>'required|string|max:16'])->validate();
        return $this->ops->write('inventory.recipe',$v,$actor,function($b,$a)use($v){
            abort_unless($b['kind']==='f'&&$this->canManage($a),403);$p=DB::table('resturant_products')->where('resturant_id',$b['id'])->where('id',$v['product_id'])->lockForUpdate()->first();abort_unless($p,404);
            abort_unless(in_array((int)$v['feature_id'],array_column($this->features($p),'id'),true),422,'الحجم لا ينتمي للصنف.');
            $ingredients=$this->ingredientList();$components=[];foreach($v['components'] as $c){$i=$ingredients[$c['ingredient_id']]??null;abort_unless($i,422,'مكوّن غير متاح.');abort_unless($i['unit']==='kg'?in_array($c['measure'],['kg','g'],true):$c['measure']==='piece',422,'وحدة المكوّن غير صحيحة.');
                $units=$this->units($c['quantity']);if($c['measure']==='g')$units=intdiv($units,1000);abort_unless($units>0&&$units<=1000000000,422,'مقدار المكوّن يجب ألا يزيد عن 1000 من وحدته الأساسية.');
                $components[]=['ingredient_id'=>$i['id'],'name'=>$i['name'],'unit'=>$i['unit'],'quantity_units'=>$units,'measure'=>$c['measure'],'quantity'=>$c['quantity']];}
            usort($components,fn($x,$y)=>$x['ingredient_id']<=>$y['ingredient_id']);
            $q=DB::table('branch_stock_recipes')->where('branch',$b['value'])->where('product_id',$p->id);$r=(clone $q)->lockForUpdate()->first();$variants=[];
            if($r){$this->ops->revision($r,$v);abort_unless($r->unit===$v['unit'],422,'وحدة بيع الصنف ثابتة لكل أحجامه.');$variants=json_decode($r->variants,true);}else abort_unless(empty($v['expected_revision']),409,'الوصفة تغيرت؛ أعد تحميلها.');
            $variants[(string)$v['feature_id']]=$components;ksort($variants,SORT_NUMERIC);$revision=$r?(int)$r->revision+1:1;
            $data=['unit'=>$v['unit'],'variants'=>json_encode((object)$variants,JSON_UNESCAPED_UNICODE),'revision'=>$revision,'updated_by'=>$a->id,'updated_at'=>now('UTC')];
            if($r)$q->update($data);else DB::table('branch_stock_recipes')->insert($data+['branch'=>$b['value'],'product_id'=>$p->id,'created_at'=>now('UTC')]);
            return ['recipe'=>$this->recipe($q->first()),'operation'=>['branch'=>$b['value'],'product_id'=>(int)$p->id,'feature_id'=>(int)$v['feature_id'],'idempotency_key'=>$v['idempotency_key']]];
        });
    }
    private function feature(array $line): int
    {
        if(preg_match('/^f:([0-9]+):/D',(string)($line['option_id']??''),$m))return (int)$m[1];return (int)($line['feature_id']??0);
    }
    private function stockName(string $name): string
    {
        $name=strtr($name,['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[ـ\x{064B}-\x{065F}]/u','',$name)));
    }
    private function rawStockName(string $name): string
    {
        $name=$this->stockName($name);
        if(preg_match('/^فسيخ(?:\s+(?:دسوق|نبروه))?\s+(?:(?<count>[1-4])\s*(?:قطعه|قطع|سمكه|سمكات)|(?<word>سمكتين|سمكه))$/u',$name,$m)){
            $count=!empty($m['count'])?(int)$m['count']:(($m['word']??'')==='سمكتين'?2:1);
            return $this->stockName('فسيخ كيلو '.([1=>'سمكة',2=>'سمكتين',3=>'3 سمكات',4=>'4 سمكات'][$count]));
        }
        // Only approved 200g/400g sealed cans are equivalent to one stock piece.
        if(preg_match('/^(?:علبه|عليه)\s+(?<fish>فسيخ|سردين|رنجه)(?:\s+كان)?(?:\s+مخلي(?:ه)?)?\s*وزن\s*(?<grams>200|400)\s*جرام$/u',$name,$m))return $this->stockName('علبة '.$m['fish'].' '.($m['grams']==='200'?'صغيرة':'كبيرة'));
        if(preg_match('/^علبه\s+(?:بطارخ|بطاخ)(?:\s+رنجه)?\s*(?:وزن|ورن)\s*400\s*جرام$/u',$name))return $this->stockName('علبة بطارخ');
        if(preg_match('/^علبه\s+(?<fish>انشوجه|ملوحه)(?:\s+مخلي(?:ه)?)?\s*وزن\s*200\s*جرام$/u',$name,$m))return $this->stockName('علبة '.$m['fish']);
        return [
            'رنجه هيرنج'=>$this->stockName('رنجة سمينة'),
            'رنجه هيرنج مبطرخ'=>$this->stockName('رنجة بطارخ'),
            'رنجه تدخين اشجار ليمون'=>$this->stockName('رنجة تدخين أشجار الليمون'),
            'سردين'=>$this->stockName('سردين بلدي'),
            'مياه معدنيه'=>$this->stockName('مياه'),
            'عدد 1 خبز بلدي'=>$this->stockName('خبز بلدي'),
            'مشروب كولا'=>$this->stockName('بيبسي'),
            'طبق بطاطس شيبسي'=>$this->stockName('شيبسي'),
        ][$name]??$name;
    }
    private function sideStockName(string $name): string
    {
        return ['بصل جوليان احمر'=>'بصل','طبق فلفل اخضر'=>'فلفل','طبق ليمون'=>'ليمون'][$this->stockName($name)]??'';
    }
    /** Only whole raw goods have a one-to-one stock binding; prepared dishes require a saved recipe. */
    private function saleRecipes(string $branch,array $ids): array
    {
        $recipes=DB::table('branch_stock_recipes')->where('branch',$branch)->whereIn('product_id',$ids)->get()->keyBy('product_id')->all();
        $direct=app(BranchStock::class)->directBalances($branch,$ids);$ingredients=[];
        foreach($this->ingredientList() as $i)$ingredients[$this->stockName($i['name'])]=$i;
        $products=DB::table('resturant_products')->where('resturant_id',(int)substr($branch,2))->whereIn('id',$ids)->get();
        $features=[];
        if(Schema::hasTable('product_features'))$features=DB::table('product_features')->whereIn('product_id',$products->pluck('product_id')->filter())->whereIn('name',['half','quarter'])->get()->groupBy('product_id')->all();
        foreach($products as $p){
            if(isset($recipes[$p->id])||isset($direct[$p->id]))continue;
            $name=$this->rawStockName($p->product_name);
            $i=$ingredients[$name]??null;if(!$i)continue;
            $component=['ingredient_id'=>$i['id'],'name'=>$i['name'],'unit'=>$i['unit'],'quantity_units'=>1000000,'measure'=>$i['unit'],'quantity'=>'1'];
            $variants=['0'=>[$component]];
            if($i['unit']==='kg')foreach($features[$p->product_id??0]??[] as $f){$part=$component;$part['quantity_units']=$f->name==='half'?500000:250000;$part['quantity']=$f->name==='half'?'0.5':'0.25';$variants[(string)$f->id]=[$part];}
            $recipes[$p->id]=(object)['id'=>0,'product_id'=>(int)$p->id,'unit'=>$i['unit'],'revision'=>0,'variants'=>json_encode((object)$variants,JSON_UNESCAPED_UNICODE),'raw_stock'=>true];
        }
        return $recipes;
    }
    public function snapshots(string $branch,array $lines): array
    {
        if(!str_starts_with($branch,'f:'))return $lines;
        $recipes=$this->saleRecipes($branch,array_column($lines,'product_id'));
        $direct=app(BranchStock::class)->directBalances($branch,array_column($lines,'product_id'));
        foreach($lines as &$line){$r=$recipes[$line['product_id']]??null;$f=$this->feature($line);$variants=$r?json_decode($r->variants,true):[];$components=$variants[(string)$f]??null;
            if($r&&$r->unit==='piece')abort_unless((int)$line['quantity_millis']%1000===0,422,'وصفة هذا الصنف للوحدة؛ أدخل عددًا صحيحًا.');
            if($r)abort_unless($components,422,'لم تُسجّل وصفة حجم هذا الصنف: '.$line['name']);
            $sku=!$r?($direct[$line['product_id']]??null):null;
            $line['inventory']=['version'=>1,'branch'=>$branch,'product_id'=>(int)$line['product_id'],'feature_id'=>$f,'recipe_id'=>$r?(int)$r->id:null,'revision'=>$r?(int)$r->revision:0,'unit'=>$r->unit??($sku['unit']??null),'components'=>$components?:[]];
            if($sku)$line['inventory']['stock_source']='direct';
        }unset($line);return $lines;
    }
    public function menuBalances(string $branch,array $ids): array
    {
        if(!str_starts_with($branch,'f:')||!$ids)return [];
        $recipes=$this->saleRecipes($branch,$ids);$stocks=$this->stocks($branch);$out=[];
        $direct=app(BranchStock::class)->directBalances($branch,$ids);
        $sideProducts=DB::table('resturant_products')->where('resturant_id',(int)substr($branch,2))->whereIn('id',$ids)->pluck('product_name','id');$ingredients=[];
        foreach($this->ingredientList() as $i)$ingredients[$this->stockName($i['name'])]=$i;
        foreach($ids as $id){$r=$recipes[$id]??null;$variants=$r?json_decode($r->variants,true):[];$base=$variants['0']??[];
            if(!$r&&isset($direct[$id])){
                $value=$direct[$id]+['configured'=>false,'tracked'=>true,'source'=>'direct'];
                $value['label']='رصيد الوحدة: '.$value['quantity'].' '.$value['unit_label'];$out[(int)$id]=$value;continue;
            }
            $value=['product_id'=>(int)$id,'unit'=>$r->unit??'','unit_label'=>$r&&$r->unit==='kg'?'كجم':'وحدة','quantity'=>'—','negative'=>false,'revision'=>(int)($r->revision??0),'configured'=>(bool)$r,'tracked'=>(bool)$base,'label'=>$r?'وصفة الحجم الأساسي غير مسجلة':'يحتاج ربط المكونات بالبضاعة'];
            $side=!$r?($ingredients[$this->sideStockName($sideProducts[$id]??'')]??null):null;
            if($side){$row=$stocks[$side['id']]??null;$value=array_merge($value,$this->stock($side,$row));$value['source']='ingredient_preview';$value['tracked']=(bool)$row;$value['needs_portion']=true;
                $value['label']=($row?'رصيد الخام: '.$value['quantity'].' '.$value['unit_label']:'رصيد الخام لم يسجّل بعد').' · سجّل وزن الطبق في الوصفة';$out[(int)$id]=$value;continue;}
            if(!empty($r->raw_stock)){
                $i=$base[0];$row=$stocks[$i['ingredient_id']]??null;$value=array_merge($value,$this->stock(['id'=>$i['ingredient_id'],'unit'=>$i['unit'],'unit_label'=>$i['unit']==='kg'?'كجم':'قطعة'],$row));
                $value['source']='ingredient';$value['tracked']=(bool)$row;$value['label']=$row?'رصيد البضاعة: '.$value['quantity'].' '.$value['unit_label']:'رصيد البضاعة لم يسجّل بعد';$out[(int)$id]=$value;continue;
            }
            if($base){$available=1000000000000;foreach($base as $c){$n=(int)($stocks[$c['ingredient_id']]->quantity_units??0);$value['negative']=$value['negative']||$n<0;$available=min($available,intdiv(max(0,$n)*1000,(int)$c['quantity_units']));}
                if($r->unit==='piece')$available=intdiv($available,1000)*1000;$value['quantity']=$this->quantity($available*1000);$value['label']='المتاح بالمكونات: '.$value['quantity'].' '.$value['unit_label'];}
            $out[(int)$id]=$value;
        }return $out;
    }
    public function decorate(string $branch,array $items): array
    {
        if(!str_starts_with($branch,'f:'))return $items;$stocks=$this->menuBalances($branch,array_column($items,'id'));
        foreach($items as &$item){$item['stock']=$stocks[$item['id']]??null;if(($item['stock']['configured']??false)||($item['stock']['source']??'')==='direct'){$item['unit']=$item['stock']['unit'];$item['quantity_mode']=$item['unit']==='kg'?'weight':'piece';}elseif(($item['stock']['source']??'')==='ingredient_preview'){$item['quantity_mode']='piece';}}unset($item);return $items;
    }
    public function validateQuantities(string $branch,array $items): void
    {
        if(!str_starts_with($branch,'f:'))return;$recipes=$this->saleRecipes($branch,array_column($items,'product_id'));
        $direct=app(BranchStock::class)->directBalances($branch,array_column($items,'product_id'));
        foreach($items as $i){$r=$recipes[$i['product_id']]??null;$unit=$r->unit??($direct[$i['product_id']]['unit']??null);if(!$unit)continue;
            abort_unless(($i['quantity_mode']??'')===($unit==='kg'?'weight':'piece'),422,'وحدة الصنف تغيرت؛ حدّث المينيو واختر الصنف مرة أخرى.');
            if($unit==='piece')abort_unless((int)$i['quantity_millis']%1000===0,422,'هذا الصنف للوحدة؛ أدخل عددًا صحيحًا.');
        }
    }
    public function posSale(array $branch,int $orderId,array $lines,int $actorId): void
    {
        if($branch['kind']!=='f')return;
        // Pre-upgrade unpaid bills have no recipe snapshot: resolve once on settlement.
        $direct=null;
        foreach($lines as &$line){
            if(!array_key_exists('inventory',$line))$line=$this->snapshots($branch['value'],[$line])[0];
            elseif(empty($line['inventory']['recipe_id'])&&empty($line['inventory']['components'])&&empty($line['inventory']['stock_source'])){
                // Unpaid bills created before direct stock support still consume their recorded SKU balance.
                $direct=$direct??app(BranchStock::class)->directBalances($branch['value'],array_column($lines,'product_id'));
                if(isset($direct[$line['product_id']])){$line['inventory']['stock_source']='direct';$line['inventory']['unit']=$direct[$line['product_id']]['unit'];}
                elseif(!empty($this->saleRecipes($branch['value'],[$line['product_id']])[$line['product_id']]->raw_stock))$line['inventory']=$this->snapshots($branch['value'],[$line])[0]['inventory'];
            }
        }unset($line);
        $this->consume($branch['value'],'pos',(string)$orderId,$lines,$actorId);
    }
    public function appSale(\App\Models\Order $order): void
    {
        if($order->type!=='current'||!$order->resturant_id)return;$branch='f:'.$order->resturant_id;
        DB::table('resturants')->where('id',$order->resturant_id)->lockForUpdate()->first();
        if(DB::table('branch_recipe_sales')->where('branch',$branch)->where('source_type','app')->where('source_id',(string)$order->id)->exists())return;
        $lines=[];foreach(DB::table('carts')->where('order_id',$order->id)->orderBy('id')->lockForUpdate()->get() as $c){
            if(preg_match('/^0+(?:\.0+)?$/D',(string)$c->qty))continue;$p=DB::table('resturant_products')->where('resturant_id',$order->resturant_id)->where('id',$c->resturant_product_id)->first();if(!$p)continue;
            $feature=!empty($c->product_feature)&&!empty($p->product_id)&&Schema::hasTable('product_features')?DB::table('product_features')->where('id',$c->product_feature)->where('product_id',$p->product_id)->value('name'):null;
            $lines[]=['product_id'=>(int)$p->id,'name'=>$p->product_name,'quantity_millis'=>intdiv($this->units((string)$c->qty),1000),'feature_id'=>(int)($c->product_feature??0),'option_label'=>self::FEATURES[$feature]??''];
        }
        $this->consume($branch,'app',(string)$order->id,$this->snapshots($branch,$lines),null);
    }
    private function consume(string $branch,string $type,string $source,array $lines,?int $actor): void
    {
        if(DB::table('branch_recipe_sales')->where('branch',$branch)->where('source_type',$type)->where('source_id',$source)->exists())return;
        $totals=[];$snapshots=[];$unmapped=[];$direct=[];
        foreach($lines as $line){$s=$line['inventory'];abort_unless(($s['version']??null)===1&&($s['branch']??null)===$branch&&($s['product_id']??null)===(int)$line['product_id'],409,'لقطة وصفة الفاتورة غير صالحة.');
            $qty=(int)$line['quantity_millis'];if($s['unit']==='piece')abort_unless($qty%1000===0,422);$snapshots[]=['product_id'=>$line['product_id'],'name'=>$line['name'],'quantity_millis'=>$qty,'recipe'=>$s];
            if(($s['stock_source']??'')==='direct')$direct[]=$line;
            elseif(!$s['components'])$unmapped[]=['product_id'=>$line['product_id'],'name'=>$line['name']];
            foreach($s['components'] as $c){$id=(int)$c['ingredient_id'];$delta=(int)$c['quantity_units']*$qty;abort_unless(is_int($delta)&&$delta<=1000000000000000000-($totals[$id]??0),422,'كمية مكونات الفاتورة أكبر من الحد المسموح.');$totals[$id]=($totals[$id]??0)+$delta;}
        }
        $ingredients=$this->ingredientList();ksort($totals,SORT_NUMERIC);$deductions=[];
        foreach($totals as $id=>$numerator){$units=intdiv($numerator+500,1000);$i=$ingredients[$id]??null;abort_unless($i,409,'مكوّن الوصفة غير صالح.');if($units===0)continue;$r=$this->lockedStock($branch,$id);$this->move($r,$i,-$units,$type,$source,$actor);$deductions[]=['ingredient_id'=>$id,'quantity_units'=>$units];}
        app(BranchStock::class)->consumeDirect($branch,$type,$source,$direct,$actor);
        DB::table('branch_recipe_sales')->insert(['branch'=>$branch,'source_type'=>$type,'source_id'=>$source,'snapshot'=>json_encode(['lines'=>$snapshots,'deductions'=>$deductions,'unmapped'=>$unmapped],JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
}
