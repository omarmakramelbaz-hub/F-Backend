<?php
namespace ErpTests;

use App\Services\Erp\Actor;
use App\Services\Erp\Cash;
use App\Services\Erp\Ledger;
use App\Services\Erp\Manufacturing;
use App\Services\Erp\People;
use App\Services\Erp\Purchasing;
use App\Services\Erp\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('erp_suppliers')->insert(['id'=>1,'name'=>'مورد الأسماك','phone'=>'01000000000','active'=>1]);
        DB::table('erp_items')->insert(['id'=>3,'sku'=>'FIN-001','name'=>'وجبة فسيخ جاهزة','unit'=>'kg','category'=>'finished','minimum_milli'=>1000,'active'=>1]);
    }

    private function purchase(array $changes=[]): array
    {
        return array_replace(['supplier_id'=>1,'warehouse_id'=>1,'invoice_number'=>'INV-001','invoice_date'=>'2026-09-30','notes'=>'استلام كامل مطابق للفاتورة','request_key'=>(string)Str::uuid(),
            'lines'=>[['item_id'=>1,'quantity'=>'10','unit_cost'=>'200'],['item_id'=>2,'quantity'=>'10','unit_cost'=>'5']]],$changes);
    }

    private function recipe(array $changes=[]): int
    {
        return (new Manufacturing)->recipe($this->actor(),array_replace(['name'=>'وجبة فسيخ — إصدار 1','output_item_id'=>3,'output_quantity'=>'0.900','notes'=>'وصفة تجريبية','lines'=>[['item_id'=>1,'quantity'=>'1'],['item_id'=>2,'quantity'=>'1']]],$changes));
    }

    private function cash(array $changes=[]): array
    {
        return array_replace(['type'=>'funding','amount'=>'10000','reason'=>'سند تجريبي','request_key'=>(string)Str::uuid()],$changes);
    }

    private function assertBalanced(): void
    {
        foreach (DB::table('erp_journal_lines')->selectRaw('journal_id, SUM(debit_minor) AS debit, SUM(credit_minor) AS credit')->groupBy('journal_id')->get() as $j) {
            $this->assertSame((int)$j->debit,(int)$j->credit,'Journal #'.$j->journal_id);
        }
        $stockValue=(int)DB::table('erp_stock_balances')->sum('value_minor');
        $this->assertSame($stockValue,Ledger::balance('1200')+Ledger::balance('1210')+Ledger::balance('1220'));
    }

    public function test_received_purchase_posts_inventory_and_supplier_debt_exactly_once(): void
    {
        $service=new Purchasing; $data=$this->purchase();
        $id=$service->receive($this->actor(),$data);
        $this->assertSame($id,$service->receive($this->actor(),$data));
        $this->assertSame(1,DB::table('erp_purchases')->count());
        $this->assertSame(2,DB::table('erp_purchase_lines')->count());
        $this->assertSame(205000,-Ledger::balance('2100',1));
        $this->assertSame(200000,Ledger::balance('1200'));
        $this->assertSame(5000,Ledger::balance('1220'));
        $this->rejects(409,fn()=>$service->receive($this->actor(),$this->purchase()));
        $this->rejects(409,fn()=>$service->receive($this->actor(),array_replace($data,['notes'=>'مرجع مكرر بقيم مختلفة'])));
        $this->assertBalanced();
    }

    public function test_purchase_is_atomic_when_one_line_exceeds_stock_capacity(): void
    {
        $data=$this->purchase(['lines'=>[['item_id'=>1,'quantity'=>'10','unit_cost'=>'200'],['item_id'=>2,'quantity'=>'100000','unit_cost'=>'1000000']]]);
        $this->rejects(422,fn()=>(new Purchasing)->receive($this->actor(),$data));
        $this->assertSame(0,DB::table('erp_purchases')->count());
        $this->assertSame(0,DB::table('erp_stock_documents')->count());
        $this->assertSame(0,DB::table('erp_stock_balances')->count());
        $this->assertSame(0,DB::table('erp_journals')->count());
        $this->assertSame(0,Ledger::balance('2100',1));
    }

    public function test_production_consumes_recipe_and_conserves_cost_with_actual_yield(): void
    {
        (new Purchasing)->receive($this->actor(),$this->purchase()); $recipe=$this->recipe();
        $data=['recipe_id'=>$recipe,'warehouse_id'=>1,'factor'=>'2','actual_output'=>'1.700','notes'=>'ناتج بعد التجهيز','request_key'=>(string)Str::uuid()];
        $service=new Manufacturing; $id=$service->produce($this->actor(),$data);
        $this->assertSame($id,$service->produce($this->actor(),$data));
        $stock=DB::table('erp_stock_balances')->get()->keyBy('item_id');
        $this->assertSame(8000,(int)$stock[1]->quantity_milli);
        $this->assertSame(8000,(int)$stock[2]->quantity_milli);
        $this->assertSame(1700,(int)$stock[3]->quantity_milli);
        $this->assertSame(41000,(int)$stock[3]->value_minor);
        $this->assertSame(1800,(int)DB::table('erp_productions')->value('expected_output_milli'));
        $this->assertSame(5,DB::table('erp_stock_documents')->count());
        $this->assertSame(205000,(int)$stock->sum('value_minor'));
        $this->assertBalanced();
    }

    public function test_shortage_and_fractional_pieces_abort_the_entire_production(): void
    {
        (new Purchasing)->receive($this->actor(),$this->purchase()); $recipe=$this->recipe();
        $data=['recipe_id'=>$recipe,'warehouse_id'=>1,'factor'=>'11','actual_output'=>'9','notes'=>'اختبار','request_key'=>(string)Str::uuid()];
        $this->rejects(422,fn()=>(new Manufacturing)->produce($this->actor(),$data));
        $this->rejects(422,fn()=>(new Manufacturing)->produce($this->actor(),array_replace($data,['factor'=>'0.5'])));
        $this->assertSame(0,DB::table('erp_productions')->count());
        $this->assertSame(2,DB::table('erp_stock_documents')->count());
        $this->assertSame(2,DB::table('erp_stock_balances')->count());
        $this->assertBalanced();
    }

    public function test_cash_supplier_part_payment_and_overpayment_guards(): void
    {
        (new Purchasing)->receive($this->actor(),$this->purchase()); $cash=new Cash;
        $cash->post($this->actor(),$this->cash());
        $payment=$this->cash(['type'=>'supplier_payment','supplier_id'=>1,'amount'=>'500']);
        $id=$cash->post($this->actor(),$payment); $this->assertSame($id,$cash->post($this->actor(),$payment));
        $this->assertSame(155000,-Ledger::balance('2100',1));
        $this->assertSame(950000,Ledger::balance('1100'));
        $this->rejects(422,fn()=>$cash->post($this->actor(),$this->cash(['type'=>'supplier_payment','supplier_id'=>1,'amount'=>'2000'])));
        $this->rejects(422,fn()=>$cash->post($this->actor(),$this->cash(['type'=>'expense','amount'=>'9600'])));
        $this->assertSame(2,DB::table('erp_cash_documents')->count());
        $this->assertBalanced();
    }

    public function test_payroll_posts_liability_and_cash_payment_can_only_happen_once(): void
    {
        $actor=$this->actor(); $people=new People; $cash=new Cash;
        $people->adjustment($actor,1,['month'=>'2026-09','type'=>'advance_repayment','amount'=>'200','reason'=>'سلفة سابقة خارج الدفتر','request_key'=>(string)Str::uuid()]);
        $payroll=$people->close($actor,1,'2026-09');
        $this->assertSame(600000,Ledger::balance('5200'));
        $this->assertSame(-580000,Ledger::balance('2200'));
        $this->assertSame(-20000,Ledger::balance('2195'));
        $cash->post($actor,$this->cash());
        $payment=$this->cash(['type'=>'payroll_payment','payroll_id'=>$payroll,'amount'=>'5800']);
        $id=$cash->post($actor,$payment); $this->assertSame($id,$cash->post($actor,$payment));
        $this->rejects(409,fn()=>$cash->post($actor,array_replace($payment,['request_key'=>(string)Str::uuid()])));
        $this->assertSame(0,Ledger::balance('2200'));
        $this->assertSame(420000,Ledger::balance('1100'));
        $this->assertBalanced();
    }

    public function test_initialization_carries_old_balances_without_inventing_cash_or_profit(): void
    {
        DB::table('erp_ledger_state')->update(['initialized_at'=>null]);
        DB::table('erp_stock_balances')->insert(['warehouse_id'=>2,'item_id'=>1,'quantity_milli'=>10000,'value_minor'=>200000]);
        DB::table('erp_payrolls')->insert(['employee_id'=>1,'branch_id'=>1,'month'=>'2026-08','base_minor'=>600000,'bonus_minor'=>0,'deduction_minor'=>0,'advance_minor'=>0,'net_minor'=>600000,'actor_key'=>'legacy:1','created_at'=>now()]);
        $this->rejects(409,fn()=>(new Stock)->post($this->actor(),$this->stock()));
        $owner=new Actor('legacy:1','المالك','owner',null,Actor::CAPABILITIES);
        $this->rejects(403,fn()=>(new Ledger)->initialize($this->actor(),'1000',true));
        (new Ledger)->initialize($owner,'1000',true);
        $this->assertSame(100000,Ledger::balance('1100'));
        $this->assertSame(200000,Ledger::balance('1200'));
        $this->assertSame(-600000,Ledger::balance('2200'));
        $this->assertSame(300000,Ledger::balance('3100'));
        $this->assertSame(0,Ledger::balance('5200'));
        $this->rejects(409,fn()=>(new Ledger)->initialize($owner,'0',true));
        $this->assertBalanced();
    }

    public function test_direct_journals_reject_unbalanced_or_conflicting_source_entries(): void
    {
        $this->rejects(422,fn()=>DB::transaction(fn()=>Ledger::journal($this->actor(),'test:1','اختبار',[Ledger::line('1100',100),Ledger::line('3200',-90)])));
        DB::transaction(fn()=>Ledger::journal($this->actor(),'test:1','اختبار',[Ledger::line('1100',100),Ledger::line('3200',-100)]));
        $this->rejects(409,fn()=>DB::transaction(fn()=>Ledger::journal($this->actor(),'test:1','اختبار',[Ledger::line('1100',200),Ledger::line('3200',-200)])));
        $this->assertSame(100,Ledger::balance('1100'));
    }

    public function test_branch_manager_cannot_bypass_central_permissions_or_other_warehouses(): void
    {
        $staff=$this->staff('branch_manager',1); $staff->permissions=Actor::CAPABILITIES; $staff->save(); $this->actingAs($staff,'erp');
        $this->get('/erp/purchases')->assertForbidden(); $this->get('/erp/finance')->assertForbidden();
        $this->post('/erp/purchases',$this->purchase())->assertForbidden();
        $this->post('/erp/finance/cash',$this->cash())->assertForbidden();
        $recipe=$this->recipe();
        $this->post('/erp/production',['recipe_id'=>$recipe,'warehouse_id'=>3,'factor'=>'1','actual_output'=>'1','notes'=>'اختبار','request_key'=>(string)Str::uuid()])->assertForbidden();
        $this->get('/erp/production')->assertOk()->assertDontSee('مخزن المعادي');
        $this->assertSame(0,DB::table('erp_cash_documents')->count());
    }

    public function test_new_screens_render_with_real_posted_documents_and_forms_work(): void
    {
        $this->actingAs($this->staff(),'erp');
        $this->post('/erp/suppliers',['name'=>'مورد جديد','phone'=>'01111111111','active'=>'1'])->assertSessionHasNoErrors();
        $this->post('/erp/purchases',$this->purchase())->assertSessionHasNoErrors();
        $recipe=$this->recipe();
        $this->post('/erp/production',['recipe_id'=>$recipe,'warehouse_id'=>1,'factor'=>'2','actual_output'=>'1.7','notes'=>'دفعة الاختبار','request_key'=>(string)Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/erp/finance/cash',$this->cash())->assertSessionHasNoErrors();
        foreach (['purchases','production','finance'] as $page) {
            $response=$this->get('/erp/'.$page)->assertOk();
            if ($folder=getenv('ERP_RENDER_DIR')) { if (!is_dir($folder)) { mkdir($folder,0700,true); } file_put_contents($folder.'/'.$page.'.html',$response->getContent()); }
        }
        $this->get('/erp/inventory')->assertSee('استلام مشتريات')->assertSee('ناتج تصنيع');
        $this->assertBalanced();
    }

    public function test_branch_manager_can_produce_only_within_their_warehouse(): void
    {
        (new Purchasing)->receive($this->actor(),$this->purchase(['warehouse_id'=>2])); $recipe=$this->recipe();
        $this->actingAs($this->staff('branch_manager',1),'erp');
        $this->post('/erp/production',['recipe_id'=>$recipe,'warehouse_id'=>2,'factor'=>'1','actual_output'=>'0.9','notes'=>'دفعة داخل الفرع','request_key'=>(string)Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(1,DB::table('erp_productions')->count());
        $this->assertSame(900,(int)DB::table('erp_stock_balances')->where('warehouse_id',2)->where('item_id',3)->value('quantity_milli'));
        $this->assertBalanced();
    }

    public function test_mysql_prevents_concurrent_supplier_overpayment_and_cash_overdraft(): void
    {
        if (DB::connection()->getDriverName()!=='mysql' || !function_exists('pcntl_fork')) { $this->markTestSkipped('MySQL row-lock contention test'); }
        (new Purchasing)->receive($this->actor(),$this->purchase());
        (new Cash)->post($this->actor(),$this->cash(['amount'=>'2500']));
        $results=$this->race(fn($data)=>(new Cash)->post($this->actor(),$data),[
            $this->cash(['type'=>'supplier_payment','supplier_id'=>1,'amount'=>'1500']),
            $this->cash(['type'=>'supplier_payment','supplier_id'=>1,'amount'=>'1500']),
        ]);
        $statuses=array_column($results,'status'); sort($statuses); $this->assertSame(['422','ok'],$statuses,json_encode($results));
        $this->assertSame(100000,Ledger::balance('1100')); $this->assertSame(55000,-Ledger::balance('2100',1));
        $results=$this->race(fn($data)=>(new Cash)->post($this->actor(),$data),[
            $this->cash(['type'=>'expense','amount'=>'700']),$this->cash(['type'=>'expense','amount'=>'700']),
        ]);
        $statuses=array_column($results,'status'); sort($statuses); $this->assertSame(['422','ok'],$statuses,json_encode($results));
        $this->assertSame(30000,Ledger::balance('1100')); $this->assertBalanced();
    }

    public function test_cash_preserves_long_reason_without_overflowing_the_journal_column(): void
    {
        (new Cash)->post($this->actor(),$this->cash(['reason'=>str_repeat('س',500)]));
        $this->assertSame(500,mb_strlen(DB::table('erp_journals')->value('description')));
        $this->assertSame(500,mb_strlen(DB::table('erp_cash_documents')->value('reason')));
    }
}
