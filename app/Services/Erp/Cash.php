<?php
namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class Cash
{
    public function post(Actor $actor, array $data): int
    {
        $actor->require('finance.manage'); abort_unless($actor->allBranches(),403);
        Ledger::requestKey($data['request_key']);
        $type=$data['type']; abort_unless(in_array($type,['funding','expense','supplier_payment','payroll_payment'],true),422,'نوع حركة كاش غير صالح.');
        $amount=Decimal::money($data['amount']); abort_unless($amount > 0,422,'المبلغ يجب أن يكون أكبر من صفر.');
        $reason=trim($data['reason'] ?? ''); abort_unless($reason !== '' && mb_strlen($reason)<=500,422,'سبب الحركة مطلوب.');
        $supplier=$type==='supplier_payment' ? (int)($data['supplier_id'] ?? 0) : null;
        $payroll=$type==='payroll_payment' ? (int)($data['payroll_id'] ?? 0) : null;
        $branch=$type==='expense' && !empty($data['branch_id']) ? (int)$data['branch_id'] : null;
        $actor->branch($branch,true);
        $hash=Ledger::fingerprint([$type,$amount,$reason,$supplier,$payroll,$branch]);
        return DB::transaction(function () use ($actor,$data,$type,$amount,$reason,$supplier,$payroll,$branch,$hash) {
            Ledger::lock();
            $prior=DB::table('erp_cash_documents')->where('request_key',$data['request_key'])->first();
            if ($prior) { abort_unless($prior->actor_key===$actor->key && hash_equals($prior->payload_hash,$hash),409,'مرجع العملية مستخدم لبيانات أخرى.'); return (int)$prior->id; }
            $code=['funding'=>'3200','expense'=>'5400','supplier_payment'=>'2100','payroll_payment'=>'2200'][$type];
            if ($supplier !== null) {
                abort_unless(DB::table('erp_suppliers')->where('id',$supplier)->exists(),422,'حدد المورد.');
                abort_if($amount > -Ledger::balance('2100',$supplier),422,'المبلغ أكبر من المستحق للمورد.');
            }
            if ($payroll !== null) {
                $actor->require('payroll.manage');
                $statement=DB::table('erp_payrolls')->where('id',$payroll)->first();
                abort_unless($statement,422,'حدد كشفًا معتمدًا.');
                abort_if(DB::table('erp_cash_documents')->where('payroll_id',$payroll)->exists(),409,'سبق صرف هذا الكشف.');
                abort_unless($amount===(int)$statement->net_minor,422,'صرف المرتب يكون بصافي الكشف كاملًا.');
                $branch=(int)$statement->branch_id;
            }
            abort_if($type!=='funding' && $amount>Ledger::balance('1100'),422,'رصيد الخزينة غير كافٍ.');
            $id=DB::table('erp_cash_documents')->insertGetId(['type'=>$type,'amount_minor'=>$amount,'supplier_id'=>$supplier,'payroll_id'=>$payroll,'branch_id'=>$branch,'reason'=>$reason,'request_key'=>$data['request_key'],'payload_hash'=>$hash,'actor_key'=>$actor->key,'created_at'=>now()]);
            $cashDelta=$type==='funding' ? $amount : -$amount;
            Ledger::journal($actor,'cash:'.$id,'حركة كاش #'.$id.' — '.$reason,[Ledger::line('1100',$cashDelta),Ledger::line($code,-$cashDelta,$branch,$supplier)]);
            $actor->audit('finance.'.$type,'cash_document',$id,['amount_minor'=>$amount,'supplier_id'=>$supplier,'payroll_id'=>$payroll],$branch);
            return (int)$id;
        },3);
    }
}
