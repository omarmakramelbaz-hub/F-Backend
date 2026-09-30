<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class Ledger
{
    // The singleton is locked first by every financial/stock writer. This keeps
    // initialization snapshots, cash checks, and multi-line postings consistent.
    public static function lock(bool $requireInitialized = true)
    {
        if (DB::transactionLevel() < 1) { throw new \LogicException('Ledger locks require a transaction.'); }
        $state = DB::table('erp_ledger_state')->where('id',1)->lockForUpdate()->first();
        abort_unless($state,503,'يلزم تجهيز دفتر الحسابات.');
        abort_if($requireInitialized && !$state->initialized_at,409,'يلزم أن يهيئ المالك أرصدة دفتر الحسابات أولًا من شاشة الحسابات والكاش.');
        return $state;
    }

    public static function requestKey(string $key): void
    {
        abort_unless(preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$key),422,'مرجع العملية غير صالح.');
    }

    public static function fingerprint(array $data): string { return hash('sha256',json_encode($data)); }
    public static function inventoryAccount(string $category): string
    {
        return ['raw'=>'1200','finished'=>'1210','packaging'=>'1220'][$category];
    }

    // Signed amount: debit positive, credit negative. No public route accepts journal lines.
    public static function line(string $code, int $amount, ?int $branch = null, ?int $supplier = null): array
    {
        return ['account_code'=>$code,'branch_id'=>$branch,'supplier_id'=>$supplier,'debit_minor'=>max(0,$amount),'credit_minor'=>max(0,-$amount)];
    }

    public static function journal(Actor $actor, string $source, string $description, array $lines): int
    {
        self::lock(false);
        $description=mb_substr($description,0,500);
        $lines = array_values(array_filter($lines,fn($l)=>$l['debit_minor'] || $l['credit_minor']));
        if (!$lines) { return 0; }
        $debit=0; $credit=0;
        foreach ($lines as $line) {
            abort_unless(is_int($line['debit_minor']) && is_int($line['credit_minor']) && $line['debit_minor'] >= 0 && $line['credit_minor'] >= 0
                && !($line['debit_minor'] && $line['credit_minor']),422,'طرف قيد غير صالح.');
            $debit += $line['debit_minor']; $credit += $line['credit_minor'];
        }
        abort_unless($debit === $credit && $debit > 0,422,'القيد غير متوازن.');
        $hash=self::fingerprint([$description,$lines]);
        $prior=DB::table('erp_journals')->where('source_key',$source)->first();
        if ($prior) { abort_unless(hash_equals($prior->payload_hash,$hash),409,'مصدر القيد مسجل بقيم مختلفة.'); return (int)$prior->id; }
        $id=DB::table('erp_journals')->insertGetId(['source_key'=>$source,'payload_hash'=>$hash,'description'=>$description,'entry_date'=>now(config('erp.timezone'))->format('Y-m-d'),'actor_key'=>$actor->key,'created_at'=>now()]);
        foreach ($lines as $line) { DB::table('erp_journal_lines')->insert($line+['journal_id'=>$id]); }
        return (int)$id;
    }

    public function initialize(Actor $actor, string $cash, bool $confirmed): void
    {
        $actor->require('access.manage'); abort_unless($confirmed,422,'راجع وأكد الأرصدة الافتتاحية.');
        $cash=Decimal::money($cash);
        DB::transaction(function () use ($actor,$cash) {
            $state=self::lock(false); abort_if($state->initialized_at,409,'تمت التهيئة بالفعل؛ لا يمكن تكرارها.');
            $lines=[self::line('1100',$cash)]; $net=$cash;
            $balances=DB::table('erp_stock_balances as s')->join('erp_items as i','i.id','=','s.item_id')->join('erp_warehouses as w','w.id','=','s.warehouse_id')
                ->selectRaw('i.category, w.branch_id, SUM(s.value_minor) AS amount')->groupBy('i.category','w.branch_id')->get();
            foreach ($balances as $b) { $amount=(int)$b->amount; $net+=$amount; $lines[]=self::line(self::inventoryAccount($b->category),$amount,$b->branch_id ? (int)$b->branch_id : null); }
            foreach (DB::table('erp_payrolls')->selectRaw('branch_id, SUM(net_minor) AS amount')->groupBy('branch_id')->get() as $p) {
                $amount=(int)$p->amount; $net-=$amount; $lines[]=self::line('2200',-$amount,(int)$p->branch_id);
            }
            $lines[]=self::line('3100',-$net);
            self::journal($actor,'opening:operations','تهيئة الأرصدة من المخزون الحالي والمرتبات المعتمدة غير المصروفة',$lines);
            DB::table('erp_ledger_state')->where('id',1)->update(['initialized_at'=>now()]);
            $actor->audit('finance.initialize','ledger',1,['cash_minor'=>$cash,'opening_net_minor'=>$net]);
        },3);
    }

    public static function stock(Actor $actor, int $id, string $type, $item, $source, $destination, int $delta, int $value, ?int $supplier): void
    {
        $account=self::inventoryAccount($item->category); $branch=$source->branch_id ? (int)$source->branch_id : null;
        if ($type === 'transfer') {
            $lines=[self::line($account,-$value,$branch),self::line($account,$value,$destination->branch_id ? (int)$destination->branch_id : null)];
        } elseif ($delta > 0) {
            $counterpart=['opening'=>'3100','receipt'=>'2190','purchase'=>'2100','count'=>'4900'][$type];
            $lines=[self::line($account,$value,$branch),self::line($counterpart,-$value,$branch,$supplier)];
        } else {
            $lines=[self::line($account,-$value,$branch),self::line($type === 'waste' ? '5100':'5300',$value,$branch)];
        }
        self::journal($actor,'stock:'.$id,'حركة مخزون #'.$id,$lines);
    }

    public static function payroll(Actor $actor, int $id, array $statement): void
    {
        $branch=(int)$statement['branch_id']; $net=(int)$statement['net_minor']; $advance=(int)$statement['advance_minor'];
        self::journal($actor,'payroll:'.$id,'استحقاق مرتب #'.$id.' لشهر '.$statement['month'],[
            self::line('5200',$net+$advance,$branch),self::line('2200',-$net,$branch),self::line('2195',-$advance,$branch),
        ]);
    }

    public static function balance(string $code, ?int $supplier = null): int
    {
        $q=DB::table('erp_journal_lines')->where('account_code',$code);
        if ($supplier !== null) { $q->where('supplier_id',$supplier); }
        return (int)$q->selectRaw('COALESCE(SUM(debit_minor),0) - COALESCE(SUM(credit_minor),0) AS balance')->value('balance');
    }
}
