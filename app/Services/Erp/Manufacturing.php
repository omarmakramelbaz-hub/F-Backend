<?php
namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class Manufacturing
{
    public function recipe(Actor $actor, array $data): int
    {
        $actor->require('production.manage'); $actor->require('inventory.manage'); abort_unless($actor->allBranches(),403);
        $output=DB::table('erp_items')->where('id',$data['output_item_id'])->where('active',true)->where('category','finished')->first();
        abort_unless($output,422,'الناتج يجب أن يكون صنف منتج جاهز.');
        $qty=Decimal::quantity($data['output_quantity'],$output->unit); abort_unless($qty>0,422,'أدخل كمية الناتج.');
        abort_unless(count($data['lines'])>=1 && count($data['lines'])<=30,422,'الوصفة تحتاج من مكون إلى 30 مكونًا.');
        $lines=[];
        foreach ($data['lines'] as $line) {
            $item=DB::table('erp_items')->where('id',$line['item_id'])->where('active',true)->first();
            abort_unless($item && $item->id!==$output->id && !isset($lines[$item->id]),422,'مكون مكرر أو غير صالح أو مطابق للناتج.');
            $amount=Decimal::quantity($line['quantity'],$item->unit); abort_unless($amount>0,422,'كمية المكون مطلوبة.');
            $lines[$item->id]=['item_id'=>$item->id,'quantity_milli'=>$amount];
        }
        return DB::transaction(function () use ($actor,$data,$qty,$lines,$output) {
            $id=DB::table('erp_recipes')->insertGetId(['name'=>$data['name'],'output_item_id'=>$output->id,'output_milli'=>$qty,'notes'=>$data['notes'] ?? null,'actor_key'=>$actor->key,'created_at'=>now()]);
            foreach ($lines as $line) { DB::table('erp_recipe_lines')->insert($line+['recipe_id'=>$id]); }
            $actor->audit('production.recipe','recipe',$id,['name'=>$data['name'],'output_item_id'=>$output->id,'quantity_milli'=>$qty]);
            return (int)$id;
        });
    }

    public function produce(Actor $actor, array $data): int
    {
        $actor->require('production.manage'); $actor->require('inventory.manage'); Ledger::requestKey($data['request_key']);
        $stock=new Stock; $warehouse=$stock->warehouse($actor,(int)$data['warehouse_id']);
        $recipe=DB::table('erp_recipes')->where('id',$data['recipe_id'])->first(); abort_unless($recipe,422,'حدد الوصفة.');
        $output=DB::table('erp_items')->where('id',$recipe->output_item_id)->where('active',true)->first(); abort_unless($output,422,'صنف الناتج غير متاح.');
        $factor=Decimal::scaled($data['factor'],3,100000); abort_unless($factor>0,422,'عدد الوصفات يجب أن يكون أكبر من صفر.');
        $actual=Decimal::quantity($data['actual_output'],$output->unit); abort_unless($actual>0,422,'سجل كمية الناتج الفعلي.');
        $expected=$this->scale((int)$recipe->output_milli,$factor,$output->unit);
        $notes=trim($data['notes']); abort_unless($notes!=='' && mb_strlen($notes)<=500,422,'ملاحظات الدفعة مطلوبة.');
        $hash=Ledger::fingerprint([(int)$recipe->id,(int)$warehouse->id,$factor,$actual,$notes]);
        return DB::transaction(function () use ($actor,$data,$stock,$warehouse,$recipe,$output,$factor,$actual,$expected,$notes,$hash) {
            Ledger::lock();
            $prior=DB::table('erp_productions')->where('request_key',$data['request_key'])->first();
            if ($prior) { abort_unless($prior->actor_key===$actor->key && hash_equals($prior->payload_hash,$hash),409,'مرجع الدفعة مستخدم بقيم أخرى.'); return (int)$prior->id; }
            $components=DB::table('erp_recipe_lines as l')->join('erp_items as i','i.id','=','l.item_id')->where('l.recipe_id',$recipe->id)->get(['l.item_id','l.quantity_milli','i.unit','i.category','i.active']);
            abort_if($components->isEmpty(),422,'الوصفة لا تحتوي على مكونات.');
            $ids=$components->pluck('item_id')->push($output->id)->all(); sort($ids,SORT_NUMERIC); $balances=[];
            foreach ($ids as $id) {
                DB::table('erp_stock_balances')->insertOrIgnore(['warehouse_id'=>$warehouse->id,'item_id'=>$id,'quantity_milli'=>0,'value_minor'=>0]);
                $balances[$id]=DB::table('erp_stock_balances')->where('warehouse_id',$warehouse->id)->where('item_id',$id)->lockForUpdate()->first();
            }
            $consumed=[]; $total=0;
            foreach ($components as $component) {
                abort_unless($component->active,422,'أحد المكونات غير نشط.');
                $qty=$this->scale((int)$component->quantity_milli,$factor,$component->unit); $balance=$balances[$component->item_id];
                abort_if($qty>(int)$balance->quantity_milli,422,'رصيد أحد مكونات الوصفة غير كافٍ؛ لم تُرحل الدفعة.');
                $cost=$qty===(int)$balance->quantity_milli ? (int)$balance->value_minor : intdiv((int)$balance->value_minor*$qty+intdiv((int)$balance->quantity_milli,2),(int)$balance->quantity_milli);
                $consumed[]=['item_id'=>(int)$component->item_id,'quantity'=>$qty,'value'=>$cost,'account'=>Ledger::inventoryAccount($component->category)]; $total+=$cost;
            }
            $id=DB::table('erp_productions')->insertGetId(['recipe_id'=>$recipe->id,'warehouse_id'=>$warehouse->id,'factor_milli'=>$factor,'expected_output_milli'=>$expected,'actual_output_milli'=>$actual,'total_minor'=>$total,'notes'=>$notes,'request_key'=>$data['request_key'],'payload_hash'=>$hash,'actor_key'=>$actor->key,'created_at'=>now()]);
            $branch=$warehouse->branch_id ? (int)$warehouse->branch_id : null; $journal=[];
            foreach ($consumed as $line) {
                $document=$this->document($actor,$id,'production_out',$line['item_id'],(int)$warehouse->id,$line['quantity'],$line['value'],$notes);
                $stock->entry($document,$balances[$line['item_id']],-$line['quantity'],-$line['value']);
                $journal[]=Ledger::line($line['account'],-$line['value'],$branch);
            }
            $document=$this->document($actor,$id,'production_in',(int)$output->id,(int)$warehouse->id,$actual,$total,$notes);
            $stock->entry($document,$balances[$output->id],$actual,$total);
            $journal[]=Ledger::line(Ledger::inventoryAccount($output->category),$total,$branch);
            Ledger::journal($actor,'production:'.$id,'تصنيع دفعة #'.$id.' — '.$recipe->name,$journal);
            $actor->audit('production.post','production',$id,['recipe_id'=>$recipe->id,'expected_output_milli'=>$expected,'actual_output_milli'=>$actual,'total_minor'=>$total],$branch);
            return (int)$id;
        },3);
    }

    private function scale(int $qty,int $factor,string $unit): int
    {
        $product=$qty*$factor; abort_if($product%1000!==0,422,'حجم الدفعة ينتج دقة أقل من المسموح؛ غيّر عدد الوصفات.');
        $result=intdiv($product,1000); abort_unless($result>0 && $result<=100000000 && ($unit!=='piece' || $result%1000===0),422,'حجم الدفعة يتجاوز الحد أو ينتج جزءًا من قطعة.');
        return $result;
    }

    private function document(Actor $actor,int $batch,string $type,int $item,int $warehouse,int $qty,int $value,string $notes): int
    {
        return (int)DB::table('erp_stock_documents')->insertGetId(['request_key'=>'production-'.$batch.'-'.$type.'-'.$item,'payload_hash'=>Ledger::fingerprint([$batch,$type,$item,$warehouse,$qty,$value]),'actor_key'=>$actor->key,'type'=>$type,'item_id'=>$item,'warehouse_id'=>$warehouse,'quantity_milli'=>$qty,'value_minor'=>$value,'reference'=>'دفعة تصنيع #'.$batch,'reason'=>$notes,'created_at'=>now()]);
    }
}
