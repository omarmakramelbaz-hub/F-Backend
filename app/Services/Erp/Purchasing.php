<?php
namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class Purchasing
{
    public function receive(Actor $actor, array $data): int
    {
        $actor->require('purchasing.manage'); $actor->require('inventory.manage'); abort_unless($actor->allBranches(),403);
        Ledger::requestKey($data['request_key']);
        $warehouse=(new Stock)->warehouse($actor,(int)$data['warehouse_id']);
        $invoice=trim($data['invoice_number']); $notes=trim($data['notes']);
        abort_unless($invoice!=='' && mb_strlen($invoice)<=80 && $notes!=='' && mb_strlen($notes)<=500,422,'رقم الفاتورة وملاحظات الاستلام مطلوبان.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$data['invoice_date']);
        abort_unless($date && $date->format('Y-m-d')===$data['invoice_date'] && $data['invoice_date']<=now(config('erp.timezone'))->format('Y-m-d'),422,'تاريخ الفاتورة غير صالح.');
        abort_unless(count($data['lines'])>=1 && count($data['lines'])<=30,422,'الفاتورة تحتاج من صنف إلى 30 صنفًا.');
        $lines=[]; $total=0;
        foreach ($data['lines'] as $line) {
            $item=DB::table('erp_items')->where('id',$line['item_id'])->where('active',true)->first();
            abort_unless($item,422,'صنف غير متاح.'); abort_if(isset($lines[$item->id]),422,'كرر الكمية داخل سطر واحد بدل تكرار الصنف.');
            $qty=Decimal::quantity($line['quantity'],$item->unit); $cost=Decimal::money($line['unit_cost']);
            $value=intdiv($qty*$cost+500,1000); abort_unless($qty>0 && $cost>0 && $value>0,422,'راجع كمية وتكلفة كل صنف.');
            $lines[$item->id]=['item_id'=>(int)$item->id,'quantity_milli'=>$qty,'unit_cost_minor'=>$cost,'total_minor'=>$value]; $total+=$value;
        }
        ksort($lines,SORT_NUMERIC);
        $hash=Ledger::fingerprint([(int)$data['supplier_id'],(int)$warehouse->id,$invoice,$data['invoice_date'],$notes,$lines]);
        return DB::transaction(function () use ($actor,$data,$warehouse,$invoice,$notes,$lines,$total,$hash) {
            Ledger::lock();
            $prior=DB::table('erp_purchases')->where('request_key',$data['request_key'])->first();
            if ($prior) { abort_unless($prior->actor_key===$actor->key && hash_equals($prior->payload_hash,$hash),409,'مرجع العملية مستخدم لفاتورة أخرى.'); return (int)$prior->id; }
            abort_unless(DB::table('erp_suppliers')->where('id',$data['supplier_id'])->where('active',true)->exists(),422,'المورد غير متاح.');
            abort_if(DB::table('erp_purchases')->where('supplier_id',$data['supplier_id'])->where('invoice_number',$invoice)->exists(),409,'هذه الفاتورة مسجلة لهذا المورد بالفعل.');
            $id=DB::table('erp_purchases')->insertGetId(['supplier_id'=>$data['supplier_id'],'warehouse_id'=>$warehouse->id,'invoice_number'=>$invoice,'invoice_date'=>$data['invoice_date'],'notes'=>$notes,'total_minor'=>$total,'request_key'=>$data['request_key'],'payload_hash'=>$hash,'actor_key'=>$actor->key,'created_at'=>now()]);
            $stock=new Stock;
            foreach ($lines as $line) {
                $document=$stock->purchaseReceipt($actor,['request_key'=>'purchase-'.$id.'-item-'.$line['item_id'],'warehouse_id'=>$warehouse->id,'item_id'=>$line['item_id'],'quantity'=>Decimal::format($line['quantity_milli'],3),'unit_cost'=>Decimal::format($line['unit_cost_minor']),'reason'=>$notes,'reference'=>'فاتورة شراء #'.$id.' / '.$invoice],(int)$data['supplier_id']);
                DB::table('erp_purchase_lines')->insert($line+['purchase_id'=>$id,'stock_document_id'=>$document]);
            }
            $actor->audit('purchase.receive','purchase',$id,['supplier_id'=>(int)$data['supplier_id'],'invoice_number'=>$invoice,'total_minor'=>$total],$warehouse->branch_id ? (int)$warehouse->branch_id : null);
            return (int)$id;
        },3);
    }
}
