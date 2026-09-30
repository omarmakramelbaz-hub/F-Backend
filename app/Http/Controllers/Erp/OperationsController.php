<?php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\Cash;
use App\Services\Erp\Ledger;
use App\Services\Erp\Manufacturing;
use App\Services\Erp\Purchasing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OperationsController extends Controller
{
    private function actor(Request $request) { return $request->attributes->get('erp_actor'); }
    private function mutate(Request $request, callable $action, string $message)
    {
        try { $action(); }
        catch (HttpExceptionInterface $e) {
            if (!in_array($e->getStatusCode(),[409,422],true)) { throw $e; }
            return back()->withErrors(['operation'=>$e->getMessage()])->withInput($request->except('_token'));
        }
        return back()->with('success',$message);
    }

    public function purchases(Request $r)
    {
        $actor=$this->actor($r); $actor->require('purchasing.manage');
        $suppliers=DB::table('erp_suppliers')->orderBy('name')->get();
        $owed=DB::table('erp_journal_lines')->where('account_code','2100')->selectRaw('supplier_id, SUM(credit_minor)-SUM(debit_minor) AS amount')->groupBy('supplier_id')->pluck('amount','supplier_id');
        $warehouses=$actor->scope(DB::table('erp_warehouses'))->orderBy('name')->get();
        $items=DB::table('erp_items')->where('active',true)->orderBy('name')->get();
        $purchases=DB::table('erp_purchases as p')->join('erp_suppliers as s','s.id','=','p.supplier_id')->join('erp_warehouses as w','w.id','=','p.warehouse_id')
            ->select('p.*','s.name as supplier_name','w.name as warehouse_name')->orderByDesc('p.id')->paginate(20);
        $lines=DB::table('erp_purchase_lines as l')->join('erp_items as i','i.id','=','l.item_id')->whereIn('purchase_id',$purchases->pluck('id'))->select('l.*','i.name as item_name','i.unit')->get()->groupBy('purchase_id');
        return view('erp.purchases',compact('suppliers','owed','warehouses','items','purchases','lines'));
    }

    public function supplier(Request $r)
    {
        $actor=$this->actor($r); $actor->require('purchasing.manage');
        $data=$r->validate(['id'=>'nullable|integer|exists:erp_suppliers,id','name'=>'required|string|max:120','phone'=>'nullable|string|max:32','address'=>'nullable|string|max:300','active'=>'required|boolean']);
        return $this->mutate($r,function () use ($actor,$data) {
            DB::transaction(function () use ($actor,$data) {
                $fields=['name'=>$data['name'],'phone'=>$data['phone']??null,'address'=>$data['address']??null,'active'=>$data['active'],'updated_at'=>now()];
                if (!empty($data['id'])) { $id=(int)$data['id']; DB::table('erp_suppliers')->where('id',$id)->update($fields); }
                else { $id=DB::table('erp_suppliers')->insertGetId($fields+['created_at'=>now()]); }
                $actor->audit('purchase.supplier','supplier',$id,['name'=>$data['name'],'active'=>$data['active']]);
            });
        },'تم حفظ المورد.');
    }

    public function receive(Request $r, Purchasing $service)
    {
        $data=$r->validate(['request_key'=>'required|string|max:64','supplier_id'=>'required|integer|min:1','warehouse_id'=>'required|integer|min:1','invoice_number'=>'required|string|max:80','invoice_date'=>'required|date_format:Y-m-d',
            'notes'=>'required|string|max:500','lines'=>'required|array|min:1|max:30','lines.*.item_id'=>'required|integer|min:1','lines.*.quantity'=>'required|string|max:20','lines.*.unit_cost'=>'required|string|max:20']);
        return $this->mutate($r,fn()=>$service->receive($this->actor($r),$data),'تم استلام الفاتورة وتحديث المخزون ورصيد المورد.');
    }

    public function production(Request $r)
    {
        $actor=$this->actor($r); $actor->require('production.manage'); $actor->require('inventory.manage');
        $warehouses=$actor->scope(DB::table('erp_warehouses'))->orderBy('name')->get();
        $items=DB::table('erp_items')->where('active',true)->orderBy('name')->get();
        $recipes=DB::table('erp_recipes as r')->join('erp_items as i','i.id','=','r.output_item_id')->select('r.*','i.name as item_name','i.unit')->orderByDesc('r.id')->get();
        $components=DB::table('erp_recipe_lines as l')->join('erp_items as i','i.id','=','l.item_id')->select('l.*','i.name as item_name','i.unit')->get()->groupBy('recipe_id');
        $batches=DB::table('erp_productions as p')->join('erp_recipes as r','r.id','=','p.recipe_id')->join('erp_warehouses as w','w.id','=','p.warehouse_id')->join('erp_items as i','i.id','=','r.output_item_id')
            ->whereIn('w.id',$warehouses->pluck('id'))->select('p.*','r.name as recipe_name','w.name as warehouse_name','i.unit')->orderByDesc('p.id')->paginate(20);
        return view('erp.production',compact('warehouses','items','recipes','components','batches'));
    }

    public function recipe(Request $r, Manufacturing $service)
    {
        $data=$r->validate(['name'=>'required|string|max:120','output_item_id'=>'required|integer|min:1','output_quantity'=>'required|string|max:20','notes'=>'nullable|string|max:500',
            'lines'=>'required|array|min:1|max:30','lines.*.item_id'=>'required|integer|min:1','lines.*.quantity'=>'required|string|max:20']);
        return $this->mutate($r,fn()=>$service->recipe($this->actor($r),$data),'تم حفظ الوصفة. للتعديل أنشئ إصدارًا جديدًا للحفاظ على تاريخ الدفعات.');
    }

    public function produce(Request $r, Manufacturing $service)
    {
        $data=$r->validate(['request_key'=>'required|string|max:64','recipe_id'=>'required|integer|min:1','warehouse_id'=>'required|integer|min:1','factor'=>'required|string|max:20','actual_output'=>'required|string|max:20','notes'=>'required|string|max:500']);
        return $this->mutate($r,fn()=>$service->produce($this->actor($r),$data),'تم ترحيل الدفعة: خصم المكونات وإضافة الناتج وتثبيت تكلفته.');
    }

    public function finance(Request $r)
    {
        $actor=$this->actor($r); $actor->require('finance.manage');
        $state=DB::table('erp_ledger_state')->where('id',1)->first();
        $openingStock=(int)DB::table('erp_stock_balances')->sum('value_minor');
        $openingPayroll=(int)DB::table('erp_payrolls')->sum('net_minor');
        $cash=Ledger::balance('1100'); $supplierOwed=-Ledger::balance('2100'); $payrollOwed=-Ledger::balance('2200');
        $balances=DB::table('erp_journal_lines')->selectRaw('account_code, SUM(debit_minor) AS debit, SUM(credit_minor) AS credit')->groupBy('account_code')->get()->keyBy('account_code');
        $accounts=DB::table('erp_accounts')->orderBy('code')->get();
        $journals=DB::table('erp_journals')->orderByDesc('id')->paginate(20);
        $journalLines=DB::table('erp_journal_lines as l')->join('erp_accounts as a','a.code','=','l.account_code')->whereIn('journal_id',$journals->pluck('id'))->select('l.*','a.name as account_name')->get()->groupBy('journal_id');
        $suppliers=DB::table('erp_suppliers')->orderBy('name')->get();
        $branches=DB::table('erp_branches')->where('active',true)->orderBy('name')->get();
        $unpaid=collect();
        if ($actor->can('payroll.manage')) {
            $unpaid=DB::table('erp_payrolls as p')->join('erp_employees as e','e.id','=','p.employee_id')->where('p.net_minor','>',0)->whereNotIn('p.id',DB::table('erp_cash_documents')->whereNotNull('payroll_id')->select('payroll_id'))->select('p.*','e.name as employee_name')->orderByDesc('p.id')->get();
        }
        return view('erp.finance',compact('state','openingStock','openingPayroll','cash','supplierOwed','payrollOwed','balances','accounts','journals','journalLines','suppliers','branches','unpaid'));
    }

    public function initialize(Request $r, Ledger $ledger)
    {
        $data=$r->validate(['cash'=>'required|string|max:20','confirmed'=>'required|accepted']);
        return $this->mutate($r,fn()=>$ledger->initialize($this->actor($r),$data['cash'],true),'تمت تهيئة دفتر الحسابات وتثبيت أرصدته الافتتاحية.');
    }

    public function cash(Request $r, Cash $service)
    {
        $data=$r->validate(['request_key'=>'required|string|max:64','type'=>'required|string|max:24','amount'=>'required|string|max:20','supplier_id'=>'nullable|integer|min:1','payroll_id'=>'nullable|integer|min:1','branch_id'=>'nullable|integer|min:1','reason'=>'required|string|max:500']);
        return $this->mutate($r,fn()=>$service->post($this->actor($r),$data),'تم تسجيل حركة الخزينة وقيدها المحاسبي.');
    }
}
