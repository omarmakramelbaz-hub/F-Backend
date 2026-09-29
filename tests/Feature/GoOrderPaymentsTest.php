<?php
namespace Tests\Feature;

use App\Models\Order;
use App\Services\GoPayments\Gateway;
use App\Services\GoPayments\OrderPayments;
use App\Services\GoServices\PaymobHmac;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoOrderPaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array',
            'go_payments'=>['enabled'=>true,'secret_key'=>'fixture-secret','public_key'=>'fixture-public','hmac_secret'=>'fixture-hmac','api_key'=>'fixture-api','is_live'=>false,'methods'=>['card'=>9,'mobile_wallet'=>10]]]);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema'); Event::fake(); Notification::fake(); Http::swap(new \Illuminate\Http\Client\Factory());
        Schema::create('users',function(Blueprint $t){$t->id();foreach(['name','email','mobile','app_scope','account_type','status'] as $k)$t->string($k)->nullable();$t->decimal('balance',14,2);$t->decimal('delegate_fees',8,2)->default(10);$t->timestamps();});
        Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('delegate_id')->nullable();$t->unsignedBigInteger('resturant_id')->nullable();foreach(['type','status','payment_type','transfer_price_by','delegate_from_out','reason','order_no'] as $k)$t->string($k)->nullable();$t->decimal('delivery_price',14,2)->default(100);$t->decimal('vendor_tax',14,2)->default(10);$t->decimal('tax',14,2)->default(0);$t->decimal('user_tax',14,2)->default(0);$t->timestamps();});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->unsignedBigInteger('from_user')->nullable();$t->unsignedBigInteger('to_user')->nullable();$t->unsignedBigInteger('order_id');foreach(['status','payment','type'] as $k)$t->string($k);$t->string('transfer_reference')->nullable()->unique();$t->decimal('amount',14,2);$t->timestamps();});
        Schema::create('payments',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->unsignedBigInteger('user_id');foreach(['status','intention_order_id','transaction_id'] as $k)$t->string($k)->nullable();$t->decimal('total_price',14,2);$t->timestamps();});
        Schema::create('shippings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('actual_price',14,2);$t->timestamps();});
        Schema::create('delegate_notifications',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->unsignedBigInteger('delegate_id');$t->decimal('commission_amount',14,2)->default(10);});
        Schema::create('settings',function(Blueprint $t){$t->id();$t->string('group');$t->string('name');$t->text('payload');});
        Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('price',14,2);$t->integer('qty')->default(1);$t->decimal('updated_total',14,2)->nullable();});
        Schema::create('coupon_wheels',function(Blueprint $t){$t->id();$t->date('start_date');$t->date('end_date');});
        require_once database_path('migrations/2026_09_29_100000_create_go_order_payments.php'); (new \CreateGoOrderPayments())->up();
        DB::table('settings')->insert(['group'=>'general','name'=>'app_balance','payload'=>'"1000.00"']);
        foreach([1,2,3] as $id) DB::table('users')->insert(['id'=>$id,'name'=>'Fixture','email'=>'fixture@example.test','mobile'=>'1010000000','app_scope'=>$id===1?'go':'go_partner','account_type'=>$id===1?'user':'delegate','status'=>'accepted','balance'=>$id===1?500:90]);
    }
    private function order(string $method='online', string $status='pending', string $type='shipping'): Order
    {
        DB::table('orders')->insert(['id'=>20,'user_id'=>1,'delegate_id'=>$type==='shipping'?2:null,'type'=>$type,'status'=>$type==='shipping'?'accepted':null,'payment_type'=>$method,'delegate_from_out'=>$type==='shipping'?'out_resturant':'in_resturant','created_at'=>now(),'updated_at'=>now()]);
        DB::table('shippings')->insert(['order_id'=>20,'actual_price'=>100]);
        DB::table('go_order_payments')->insert(['order_id'=>20,'customer_id'=>1,'partner_id'=>2,'reference'=>'00000000-0000-0000-0000-000000000001','method'=>$method,'status'=>$status,'amount_cents'=>10000,'integration_id'=>$method==='v_cash'?10:9,'is_live'=>false,'gateway_order_id'=>'200','expires_at'=>now()->addMinutes(30),'created_at'=>now(),'updated_at'=>now()]);
        return Order::withoutGlobalScopes()->findOrFail(20);
    }
    private function object(array $changes=[]): array
    {
        return array_replace(['id'=>300,'order'=>['id'=>200],'amount_cents'=>10000,'currency'=>'EGP','integration_id'=>9,'is_live'=>false,'success'=>true,'pending'=>false,'is_auth'=>false,'is_capture'=>false,'is_standalone_payment'=>true,'error_occured'=>false,'is_refunded'=>false,'is_voided'=>false],$changes);
    }
    private function confirmPayment(array $object): void { (new OrderPayments())->callback($object,PaymobHmac::digest($object,'fixture-hmac')); }
    private function balance(int $id=2): float { return (float)DB::table('users')->where('id',$id)->value('balance'); }
    private function denied(callable $fn, int $status): void
    {
        try { $fn(); $this->fail('Expected rejection'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame($status,$e->getStatusCode()); }
    }
    public function test_existing_go_bootstrap_provisions_payment_tables_once_without_wallet_changes(): void
    {
        Schema::create('pending_vendors',function(Blueprint $t){$t->id();$t->string('application_kind')->nullable();$t->timestamp('partner_activated_at')->nullable();});
        Schema::create('partner_service_requests',function(Blueprint $t){$t->id();});
        Schema::table('users',function(Blueprint $t){$t->string('partner_auth_email')->nullable();});
        Schema::create('migrations',function(Blueprint $t){$t->id();$t->string('migration');$t->integer('batch');});
        Schema::drop('go_order_payment_receipts');Schema::drop('go_order_payments');
        $middleware=new \App\Http\Middleware\EnsureGoSchema();
        $request=\Illuminate\Http\Request::create('/api/go-orders/capabilities');$request->headers->set('X-App-Scope','go');
        $next=fn()=>response()->json(['ready'=>true]);
        $this->assertSame(200,$middleware->handle($request,$next)->getStatusCode());
        $this->assertTrue(Schema::hasTable('go_order_payments'));$this->assertTrue(Schema::hasTable('go_order_payment_receipts'));
        $this->assertSame(200,$middleware->handle($request,$next)->getStatusCode());
        $this->assertSame(1,DB::table('migrations')->where('migration','2026_09_29_100000_create_go_order_payments')->count());
        $this->assertSame(500.0,$this->balance(1));$this->assertSame(90.0,$this->balance());$this->assertSame(0,DB::table('wallets')->count());
    }
    public function test_verified_card_payment_credits_full_gross_once_and_completion_never_pays_twice(): void
    {
        $this->order();$p=new OrderPayments();$this->confirmPayment($this->object());$this->confirmPayment($this->object());
        $this->assertSame(190.0,$this->balance());$this->assertSame('paid',OrderPayments::record(20)->status);
        $p->finish(20,2);$p->finish(20,2);$this->confirmPayment($this->object());
        $this->assertSame(190.0,$this->balance());$this->assertSame(1,DB::table('wallets')->count());
        $this->assertSame('completed',DB::table('orders')->where('id',20)->value('status'));
    }
    public function test_wallet_gateway_method_and_exact_minor_units_are_bound_to_intention(): void
    {
        $this->order('v_cash');$this->denied(fn()=> $this->confirmPayment($this->object()),422);
        $this->denied(fn()=> $this->confirmPayment($this->object(['integration_id'=>10,'amount_cents'=>9999])),422);
        $this->denied(fn()=> $this->confirmPayment($this->object(['integration_id'=>10,'currency'=>'USD'])),422);
        $this->denied(fn()=> $this->confirmPayment($this->object(['integration_id'=>10,'is_live'=>true])),422);
        $this->assertSame(90.0,$this->balance());
        $this->confirmPayment($this->object(['integration_id'=>10]));$this->assertSame(190.0,$this->balance());
    }
    public function test_forged_failed_pending_and_authorization_only_transactions_never_credit(): void
    {
        $order=$this->order();$p=new OrderPayments();
        $this->denied(fn()=> $p->callback($this->object(),'forged'),403);
        foreach([['success'=>false],['pending'=>true],['is_auth'=>true],['error_occured'=>true],['is_standalone_payment'=>false]] as $flags)$this->confirmPayment($this->object($flags));
        $this->assertSame(90.0,$this->balance());$this->assertSame(0,DB::table('wallets')->count());
        $this->denied(fn()=> $p->assertPayableWork($order,2),409);
        $this->denied(fn()=> $p->checkout(20,3),403);
    }
    public function test_second_capture_is_refund_due_and_reversal_is_once_even_after_balance_spent(): void
    {
        $this->order();$this->confirmPayment($this->object());$this->confirmPayment($this->object(['id'=>301]));
        $this->assertSame(190.0,$this->balance());$this->assertSame('refund_due',DB::table('go_order_payment_receipts')->where('transaction_id','301')->value('status'));
        $this->confirmPayment($this->object(['id'=>301,'is_refunded'=>true]));$this->assertSame(190.0,$this->balance());
        DB::table('users')->where('id',2)->update(['balance'=>20]);
        $this->confirmPayment($this->object(['is_refunded'=>true]));$this->confirmPayment($this->object(['is_refunded'=>true]));
        $this->assertSame(-80.0,$this->balance());$this->assertSame('review',OrderPayments::record(20)->status);
    }
    public function test_cancellation_reverses_credit_once_and_never_fakes_an_external_refund(): void
    {
        $this->order();$this->confirmPayment($this->object());$p=new OrderPayments();
        $p->cancel(20,1);$p->cancel(20,1);$this->confirmPayment($this->object());
        $this->assertSame(90.0,$this->balance());$this->assertSame(500.0,$this->balance(1));
        $this->assertSame('refund_pending',OrderPayments::record(20)->status);
        $this->confirmPayment($this->object(['is_refunded'=>true]));$this->assertSame(90.0,$this->balance());
    }
    public function test_late_payment_cannot_reactivate_a_cancelled_order(): void
    {
        $this->order();(new OrderPayments())->cancel(20,1);$this->confirmPayment($this->object());
        $this->assertSame(90.0,$this->balance());$this->assertSame('cancelled',DB::table('orders')->where('id',20)->value('status'));
        $this->assertSame('refund_due',DB::table('go_order_payment_receipts')->value('status'));
    }
    public function test_app_wallet_uses_final_fare_and_retries_are_idempotent(): void
    {
        $order=$this->order('wallet','waiting_partner');$p=new OrderPayments();
        DB::transaction(fn()=> $p->acceptShipping($order));$this->assertSame(400.0,$this->balance(1));$this->assertSame(90.0,$this->balance());
        DB::transaction(fn()=> $p->acceptShipping($order));$this->assertSame(400.0,$this->balance(1));
        $p->finish(20,2);$p->finish(20,2);$this->assertSame(190.0,$this->balance());
        $this->assertSame(2,DB::table('wallets')->count());
    }
    public function test_insufficient_wallet_balance_rolls_back_final_fare_collection(): void
    {
        $order=$this->order('wallet','waiting_partner');DB::table('users')->where('id',1)->update(['balance'=>99.99]);
        $p=new OrderPayments();$this->denied(fn()=> DB::transaction(fn()=> $p->acceptShipping($order)),422);
        $this->assertSame(99.99,$this->balance(1));$this->assertSame('waiting_partner',OrderPayments::record(20)->status);
        $this->assertSame(0,DB::table('wallets')->count());
    }
    public function test_checkout_uses_final_fare_reuses_one_intention_and_never_credits_from_redirect(): void
    {
        $this->order('online','ready');
        Http::fake(['*/v1/intention/'=>Http::response(['client_secret'=>'fixture-client','intention_order_id'=>201]),'*/api/auth/tokens'=>Http::response([],500)]);
        $p=new OrderPayments();$first=$p->checkout(20,1);$second=$p->checkout(20,1);
        $this->assertSame($first['link'],$second['link']);Http::assertSentCount(1);
        Http::assertSent(fn($r)=>$r['amount']===10000 && $r['payment_methods']===[9] && str_contains($r['notification_url'],'go-orders/paymob/webhook'));
        $this->get('/api/go-orders/payment-return?id=300&success=true')->assertOk();
        $this->assertSame(90.0,$this->balance());$this->assertSame('pending',OrderPayments::record(20)->status);
    }
    public function test_cash_never_debits_customer_or_credits_partner_wallet(): void
    {
        $order=$this->order('cash','waiting_partner');$p=new OrderPayments();DB::transaction(fn()=> $p->acceptShipping($order));
        $p->finish(20,2);$p->finish(20,2);$this->assertSame(500.0,$this->balance(1));$this->assertSame(90.0,$this->balance());$this->assertSame(0,DB::table('wallets')->count());
    }
    public function test_without_hmac_merchant_inquiry_replaces_untrusted_callback_fields(): void
    {
        $this->order();config(['go_payments.hmac_secret'=>null]);
        $verified=$this->object(['success'=>false]);
        Http::fake(function ($request) use (&$verified) { return Http::response(str_contains($request->url(),'/auth/tokens')?['token'=>'fixture-token']:$verified); });
        (new OrderPayments())->callback($this->object(),'');$this->assertSame(90.0,$this->balance());
        $verified=$this->object();
        (new OrderPayments())->callback(['id'=>300,'success'=>false],'');$this->assertSame(190.0,$this->balance());
        Http::assertSent(fn($r)=>str_contains($r->url(),'/api/acceptance/transactions/300') && $r->hasHeader('Authorization','Bearer fixture-token'));
    }
    public function test_store_gross_credit_does_not_repeat_and_fees_settle_separately(): void
    {
        $this->order('online','pending','current');DB::table('carts')->insert(['order_id'=>20,'price'=>100,'qty'=>1]);
        $this->confirmPayment($this->object());$this->assertSame(190.0,$this->balance());
        DB::table('orders')->where('id',20)->update(['status'=>'accepted']);
        $p=new OrderPayments();$p->finish(20);$p->finish(20);
        $this->assertSame(180.0,$this->balance());$this->assertSame('"1010.00"',DB::table('settings')->where('name','app_balance')->value('payload'));
        $this->assertSame(1,DB::table('wallets')->where('transfer_reference','go:order:20:gross_credit')->count());
    }
}
