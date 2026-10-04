<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema,Validator};

/** Restaurant SKU receipts and completed sales. One base unit per branch SKU. */
class BranchStock
{
    private BranchOperations $ops;
    public function __construct(BranchOperations $ops) {$this->ops=$ops;}
    public function installed(): bool {return Schema::hasTable('branch_stock')&&Schema::hasTable('branch_stock_movements');}
    public function branches($actor): array {return array_values(array_filter($this->ops->access->branches($actor),fn($b)=>$b['kind']==='f'));}
    private function branch(string $value,$actor,bool $lock=false): array {$b=$this->ops->access->branch($value,$actor,$lock);abort_unless($b['kind']==='f',404);return $b;}
    public function quantity(int $units): string {$n=abs($units);$s=intdiv($n,1000000).'.'.str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);return ($units<0?'-':'').rtrim(rtrim($s,'0'),'.');}
    private function units(string $value): int {abort_unless(preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,3})?$/D',$value),422,'أدخل كمية صحيحة حتى ثلاث منازل عشرية.');$p=explode('.',$value);$n=(int)$p[0]*1000000+(int)str_pad($p[1]??'',6,'0');abort_unless($n>0&&$n<=1000000000000,422,'الكمية يجب أن تكون أكبر من صفر وحتى مليون.');return $n;}
    private function present(object $r): array {return ['product_id'=>(int)$r->product_id,'unit'=>$r->unit,'unit_label'=>$r->unit==='kg'?'كجم':'قطعة','quantity'=>$this->quantity((int)$r->quantity_units),'negative'=>(int)$r->quantity_units<0,'revision'=>(int)$r->revision];}
    public function balances(string $branch,array $ids): array
    {
        if(app(BranchInventory::class)->installed())return app(BranchInventory::class)->menuBalances($branch,$ids);
        if(!$ids||!$this->installed())return [];$result=[];
        foreach(DB::table('branch_stock')->where('branch',$branch)->whereIn('product_id',$ids)->get() as $r)$result[(int)$r->product_id]=$this->present($r);
        return $result;
    }
    public function quoteLines(string $branch,array $lines): array
    {
        return app(BranchInventory::class)->installed()?app(BranchInventory::class)->snapshots($branch,$lines):$lines;
    }
    public function saleBalances(string $branch,array $ids): array
    {
        if(app(BranchInventory::class)->installed()&&str_starts_with($branch,'f:'))$ids=\Illuminate\Support\Facades\DB::table('branch_stock_recipes')->where('branch',$branch)->pluck('product_id')->all();
        return $this->balances($branch,$ids);
    }
    public function decorate(string $branch,array $items): array
    {
        if(app(BranchInventory::class)->installed())return app(BranchInventory::class)->decorate($branch,$items);
        $stocks=$this->balances($branch,array_column($items,'id'));
        foreach($items as &$item){$item['stock']=$stocks[$item['id']]??null;if($item['stock']){$item['unit']=$item['stock']['unit'];$item['quantity_mode']=$item['stock']['unit']==='kg'?'weight':'piece';}}unset($item);
        return $items;
    }
    public function validateQuantities(string $branch,array $items): void
    {
        if(app(BranchInventory::class)->installed()){app(BranchInventory::class)->validateQuantities($branch,$items);return;}
        $stocks=$this->balances($branch,array_column($items,'product_id'));
        foreach($items as $item)if(($stocks[$item['product_id']]['unit']??null)==='piece')abort_unless((int)$item['quantity_millis']%1000===0,422,'هذا الصنف مسجل بالقطعة؛ أدخل عددًا صحيحًا.');
    }
    public function listing(array $values,$actor): array
    {
        if(app(BranchInventory::class)->installed())return app(BranchInventory::class)->listing($values,$actor);
        abort_unless($this->installed(),503,'صفحة إضافة البضاعة تحتاج تحديث قاعدة البيانات.');
        $v=Validator::make($values,['branch'=>'required|string|max:30','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1'])->validate();$b=$this->branch($v['branch'],$actor);
        $q=DB::table('resturant_products')->where('resturant_id',$b['id']);$search=trim($v['search']??'');if($search!=='')$q->where('product_name','like','%'.$search.'%');
        $total=(clone $q)->count();$last=max(1,(int)ceil($total/100));$page=min($last,(int)($v['page']??1));$rows=$q->orderBy('product_name')->orderBy('id')->offset(($page-1)*100)->limit(100)->get();
        $stocks=$this->balances($b['value'],$rows->pluck('id')->all());$items=[];foreach($rows as $r)$items[]=['id'=>(int)$r->id,'name'=>$r->product_name,'available'=>$r->status==='show','stock'=>$stocks[$r->id]??null];
        $history=DB::table('branch_stock_movements')->where('branch',$b['value'])->orderByDesc('id')->limit(30)->get();$actors=DB::table('users')->whereIn('id',$history->pluck('actor_id')->filter())->pluck('name','id');
        return ['success'=>true,'branch'=>$b,'items'=>$items,'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$total],'history'=>$history->map(fn($r)=>['id'=>(int)$r->id,'name'=>$r->product_name,'unit_label'=>$r->unit==='kg'?'كجم':'قطعة','quantity'=>$this->quantity((int)$r->quantity_units),'balance'=>$this->quantity((int)$r->balance_units),'source_type'=>$r->source_type,'source_id'=>$r->source_id,'actor'=>$actors[$r->actor_id]??'النظام','supplier'=>$r->supplier,'notes'=>$r->notes,'created_at'=>\Carbon\Carbon::parse($r->created_at,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i')])->all()];
    }
    public function receive(array $values,$actor): array
    {
        if(app(BranchInventory::class)->installed())return app(BranchInventory::class)->receive($values,$actor);
        abort_unless($this->installed(),503);$v=Validator::make($values,$this->ops->rules()+['product_id'=>'required|integer|min:1','unit'=>'required|in:kg,piece','quantity'=>'required|string|max:16','supplier'=>'nullable|string|max:150','notes'=>'nullable|string|max:500'])->validate();
        $units=$this->units($v['quantity']);abort_if($v['unit']==='piece'&&$units%1000000!==0,422,'كمية القطع يجب أن تكون عددًا صحيحًا.');
        return $this->ops->write('stock.receive',$v,$actor,function($branch,$actor)use($v,$units){
            abort_unless($branch['kind']==='f',404);$p=DB::table('resturant_products')->where('resturant_id',$branch['id'])->where('id',$v['product_id'])->lockForUpdate()->first();abort_unless($p,404);
            $r=DB::table('branch_stock')->where('branch',$v['branch'])->where('product_id',$p->id)->lockForUpdate()->first();
            if(!$r){$id=DB::table('branch_stock')->insertGetId(['branch'=>$v['branch'],'product_id'=>$p->id,'unit'=>$v['unit'],'quantity_units'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);$r=DB::table('branch_stock')->where('id',$id)->first();}
            abort_unless($r->unit===$v['unit'],422,'الوحدة المسجلة لهذا الصنف مختلفة؛ استخدم نفس وحدة رصيد الفرع.');
            $movement=$this->move($r,$units,'receipt',$actor->id.':'.$v['idempotency_key'],(string)$p->product_name,(int)$actor->id,$v['supplier']??'',$v['notes']??'');
            return ['receipt'=>['id'=>$movement,'branch'=>$v['branch'],'product_id'=>(int)$p->id,'unit'=>$v['unit'],'quantity'=>$v['quantity'],'idempotency_key'=>$v['idempotency_key']],'stock'=>$this->present(DB::table('branch_stock')->where('id',$r->id)->first())];
        });
    }
    private function move(object $r,int $delta,string $type,string $source,string $name,?int $actor=null,string $supplier='',string $notes=''): int
    {
        $old=DB::table('branch_stock_movements')->where('stock_id',$r->id)->where('source_type',$type)->where('source_id',$source)->first();if($old)return (int)$old->id;
        $balance=(int)$r->quantity_units+$delta;abort_if(abs($balance)>1000000000000000,422,'رصيد البضاعة خارج الحد المسموح.');
        DB::table('branch_stock')->where('id',$r->id)->update(['quantity_units'=>$balance,'revision'=>(int)$r->revision+1,'updated_at'=>now('UTC')]);
        return DB::table('branch_stock_movements')->insertGetId(['stock_id'=>$r->id,'branch'=>$r->branch,'product_id'=>$r->product_id,'product_name'=>mb_substr($name,0,255),'unit'=>$r->unit,'quantity_units'=>$delta,'balance_units'=>$balance,'source_type'=>$type,'source_id'=>$source,'actor_id'=>$actor,'supplier'=>$supplier?:null,'notes'=>$notes?:null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    public function posSale(array $branch,int $orderId,array $lines,int $actorId): void
    {
        if(app(BranchInventory::class)->installed()){app(BranchInventory::class)->posSale($branch,$orderId,$lines,$actorId);return;}
        if($branch['kind']!=='f'||!$this->installed())return;
        $this->consume($branch['value'],'pos',(string)$orderId,$lines,$actorId);
    }
    public function appSale(\App\Models\Order $order): void
    {
        if(app(BranchInventory::class)->installed()){app(BranchInventory::class)->appSale($order);return;}
        if($order->type!=='current'||!$order->resturant_id||!$this->installed())return;
        // Completion already owns the order lock; serialize with receipts/POS on its branch.
        DB::table('resturants')->where('id',$order->resturant_id)->lockForUpdate()->first();
        $tracked=DB::table('branch_stock')->where('branch','f:'.$order->resturant_id)->pluck('product_id');if($tracked->isEmpty())return;
        $rows=DB::table('carts')->where('order_id',$order->id)->whereIn('resturant_product_id',$tracked)->orderBy('id')->lockForUpdate()->get();$lines=[];
        foreach($rows as $r){
            $p=DB::table('resturant_products')->where('resturant_id',$order->resturant_id)->where('id',$r->resturant_product_id)->first();if(!$p)continue;
            $feature=!empty($r->product_feature)&&Schema::hasTable('product_features')?DB::table('product_features')->where('id',$r->product_feature)->where('product_id',$p->product_id??0)->value('name'):null;
            if(preg_match('/^0+(?:\.0+)?$/D',(string)$r->qty))continue;
            $units=$this->units((string)$r->qty);$lines[]=['product_id'=>(int)$p->id,'name'=>$p->product_name,'quantity_millis'=>intdiv($units,1000),'option_label'=>$feature==='half'?'نصف':($feature==='quarter'?'ربع':'')];
        }
        $this->consume('f:'.$order->resturant_id,'app',(string)$order->id,$lines,null);
    }
    private function consume(string $branch,string $type,string $source,array $lines,?int $actor): void
    {
        $this->validateQuantities($branch,$lines);
        $ids=array_values(array_unique(array_column($lines,'product_id')));sort($ids,SORT_NUMERIC);
        foreach(DB::table('branch_stock')->where('branch',$branch)->whereIn('product_id',$ids)->orderBy('product_id')->lockForUpdate()->get() as $r){
            $units=0;$name='';foreach($lines as $line){if((int)$line['product_id']!==(int)$r->product_id)continue;$denominator=1;
                if($r->unit==='kg'&&preg_match('/(?:^| \/ )(نصف|ربع)(?: \/ |$)/u',$line['option_label']??'',$m))$denominator=$m[1]==='نصف'?2:4;
                $units+=intdiv((int)$line['quantity_millis']*1000,$denominator);$name=$line['name'];
            }
            if($units>0)$this->move($r,-$units,$type,$source,$name,$actor);
        }
    }
}
