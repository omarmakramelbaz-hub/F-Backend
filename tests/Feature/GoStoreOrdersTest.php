<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\GoStores\Orders;
use App\Services\GoStores\Payments;
use App\Services\GoServices\PaymobHmac;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoStoreOrdersTest extends TestCase
{
    private const OPTION = '5f205abc-4346-441d-9952-f495e1f89e9f';
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array',
            'go_payments'=>['enabled'=>true,'secret_key'=>'fixture-secret','public_key'=>'fixture-public','hmac_secret'=>'fixture-hmac','is_live'=>false,'methods'=>['card'=>9,'mobile_wallet'=>10]]]);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema'); Event::fake(); Notification::fake(); Http::swap(new \Illuminate\Http\Client\Factory());
        $this->withoutMiddleware([\App\Http\Middleware\CustomJwtAuth::class, \App\Http\Middleware\EnsureGoSchema::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Schema::create('users',function(Blueprint $t){$t->id();foreach(['name','email','mobile','app_scope','account_type','status','connected'] as $k)$t->string($k)->nullable();$t->decimal('balance',14,2);$t->decimal('delegate_fees',8,2)->default(10);$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->timestamps();});
        Schema::create('pending_vendors',function(Blueprint $t){$t->id();$t->string('profession_key');$t->decimal('lat',10,7);$t->decimal('lng',11,7);$t->integer('work_radius_km');});
        Schema::create('user_address',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('address');$t->decimal('lat',10,7);$t->decimal('lng',11,7);});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->unsignedBigInteger('from_user')->nullable();$t->unsignedBigInteger('to_user')->nullable();$t->unsignedBigInteger('order_id')->nullable();foreach(['status','payment','type'] as $k)$t->string($k);$t->string('transfer_reference',36)->nullable()->unique();$t->decimal('amount',14,2);$t->timestamps();});
        Schema::create('settings',function(Blueprint $t){$t->id();$t->string('group');$t->string('name');$t->text('payload');});
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php'); (new \CreateGoStoreCatalog())->up();
        require_once database_path('migrations/2026_09_30_000001_create_go_store_orders.php'); (new \CreateGoStoreOrders())->up();
        foreach ([1,2,3,4] as $id) DB::table('users')->insert(['id'=>$id,'name'=>'Fixture '.$id,'email'=>'fixture'.$id.'@example.test','mobile'=>'101000000'.$id,'app_scope'=>$id===1?'go':($id===4?'fasakhansta':'go_partner'),'account_type'=>$id===1?'user':'vendor','status'=>'accepted','balance'=>$id===1?500:100,'pending_vendor_id'=>$id===2?20:null]);
        DB::table('pending_vendors')->insert(['id'=>20,'profession_key'=>'store_owner','lat'=>30,'lng'=>31,'work_radius_km'=>5]);
        DB::table('go_stores')->insert(['user_id'=>2,'name'=>'Test market','kind'=>'supermarket','address'=>'Test street','revision'=>1]);
        DB::table('go_store_products')->insert(['id'=>5,'user_id'=>2,'request_key'=>(string)Str::uuid(),'name'=>'Rice','description'=>'Rice','unit'=>'kg','price_cents'=>8050,'image_path'=>'fixture/rice.png','available'=>true,'options'=>json_encode([['id'=>self::OPTION,'label'=>'Half kg','price_cents'=>4275]]),'revision'=>1]);
        DB::table('user_address')->insert(['id'=>7,'user_id'=>1,'address'=>'Customer street','lat'=>30.001,'lng'=>31.001]);
        foreach (['app_balance'=>'1000.00','default_0_1'=>'50.00','default_1_2'=>'50.00','default_2_3'=>'50.00','km_price'=>'10.00'] as $k=>$v) DB::table('settings')->insert(['group'=>'general','name'=>$k,'payload'=>json_encode($v)]);
    }
    private function login(int $id=1): self
    {
        $this->actingAs(User::withoutGlobalScopes()->findOrFail($id),'api');
        $this->withHeaders(['X-App-Scope'=>$id===1?'go':'go_partner','Accept'=>'application/json']); return $this;
    }
    private function cart(array $overrides=[]): array
    {
        return array_replace(['store_id'=>2,'items'=>[['product_id'=>5,'option_id'=>self::OPTION,'quantity'=>2]],'fulfillment'=>'pickup','notes'=>'No bag'], $overrides);
    }
    private function payload(string $method='cash', array $changes=[]): array
    {
        $cart=$this->cart($changes); $quote=(new Orders())->quote(1,$cart);
        return $cart+['quote_token'=>$quote['quote_token'],'payment_method'=>$method,'request_key'=>(string)Str::uuid()];
    }
    private function submit(string $method='cash', array $changes=[]): array { return (new Orders())->create(1,$this->payload($method,$changes)); }
    private function balance(int $id): string { return number_format((float)DB::table('users')->where('id',$id)->value('balance'),2,'.',''); }
    private function denied(callable $call,int $status): void
    {
        try {$call(); $this->fail('Expected rejection');} catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($status,$e->getStatusCode());}
    }
    public function test_cart_quote_uses_server_option_prices_delivery_radius_and_address_ownership(): void
    {
        $this->login()->postJson('/api/go-stores/quote',$this->cart(['fulfillment'=>'delivery','address_id'=>7,'total'=>0.01,'delivery_price'=>0]))
            ->assertOk()->assertJsonPath('data.subtotal','85.50')->assertJsonPath('data.delivery','50.00')->assertJsonPath('data.total','135.50');
        DB::table('user_address')->where('id',7)->update(['user_id'=>3]);
        $this->postJson('/api/go-stores/quote',$this->cart(['fulfillment'=>'delivery','address_id'=>7]))->assertStatus(422);
        DB::table('user_address')->where('id',7)->update(['user_id'=>1,'lat'=>31]);
        $this->postJson('/api/go-stores/quote',$this->cart(['fulfillment'=>'delivery','address_id'=>7]))->assertStatus(422);
        $this->postJson('/api/go-stores/quote',$this->cart(['items'=>[['product_id'=>5,'option_id'=>(string)Str::uuid(),'quantity'=>1]]]))->assertStatus(409);
    }
    public function test_cash_checkout_retries_once_and_merchant_lifecycle_is_visible_to_customer(): void
    {
        $payload=$this->payload();
        $order=$this->login()->postJson('/api/go-stores/orders',$payload)->assertOk()->json('data.order');
        $this->postJson('/api/go-stores/orders',$payload)->assertOk()->assertJsonPath('data.order.id',$order['id']);
        $this->assertSame(1,DB::table('go_store_orders')->count());
        $this->postJson('/api/go-stores/orders',array_replace($payload,['payment_method'=>'wallet']))->assertStatus(409);
        $this->login(2)->getJson('/api/go-stores/orders')->assertOk()->assertJsonPath('data.orders.0.id',$order['id']);
        foreach (['accept'=>'preparing','ready'=>'ready','complete'=>'completed'] as $action=>$status) {
            $order=$this->postJson('/api/go-stores/orders/'.$order['id'].'/action',['action'=>$action,'revision'=>$order['revision']])->assertOk()->assertJsonPath('data.order.status',$status)->json('data.order');
        }
        $this->postJson('/api/go-stores/orders/'.$order['id'].'/action',['action'=>'complete','revision'=>1])->assertOk();
        $this->assertSame('91.45',$this->balance(2));
        $this->assertSame('1008.55',json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true));
        $this->assertSame(1,DB::table('wallets')->count());
        $this->login()->getJson('/api/go-stores/orders?history=1')->assertOk()->assertJsonPath('data.orders.0.payment_status','cash_collected');
    }
    public function test_price_changes_unavailable_items_and_low_wallet_never_create_or_charge(): void
    {
        $payload=$this->payload('wallet');
        DB::table('go_store_products')->where('id',5)->increment('revision');
        $this->login()->postJson('/api/go-stores/orders',$payload)->assertStatus(409);
        $payload=$this->payload('wallet');DB::table('go_store_products')->where('id',5)->update(['available'=>false]);
        $this->postJson('/api/go-stores/orders',$payload)->assertStatus(409);
        DB::table('go_store_products')->where('id',5)->update(['available'=>true]);DB::table('users')->where('id',1)->update(['balance'=>80]);
        $this->postJson('/api/go-stores/orders',$this->payload('wallet'))->assertStatus(422);
        DB::table('users')->where('id',1)->update(['balance'=>49.99]);
        $this->postJson('/api/go-stores/orders',$this->payload())->assertStatus(409);
        $this->assertSame(0,DB::table('go_store_orders')->count());$this->assertSame(0,DB::table('wallets')->count());
    }
    public function test_wallet_hold_refunds_rejection_once_and_completion_settles_once(): void
    {
        $o=$this->submit('wallet');$orders=new Orders();
        $this->assertSame('414.50',$this->balance(1));$this->assertSame('100.00',$this->balance(2));
        $orders->transition($o['id'],2,true,'reject',1,'Out of stock');$orders->transition($o['id'],2,true,'reject',1,'Out of stock');
        $this->assertSame('500.00',$this->balance(1));
        $o=$this->submit('wallet');
        foreach(['accept','ready','complete'] as $a)$o=$orders->transition($o['id'],2,true,$a,$o['revision']);
        $orders->transition($o['id'],2,true,'complete',1);
        $this->assertSame('414.50',$this->balance(1));$this->assertSame('176.95',$this->balance(2));
        $this->assertSame('paid',$o['payment_status']);
    }
    public function test_orders_are_isolated_by_store_customer_and_app_and_cannot_skip_states(): void
    {
        $o=$this->submit();$id=$o['id'];
        $this->login(3)->getJson('/api/go-stores/orders/'.$id)->assertNotFound();
        $this->postJson('/api/go-stores/orders/'.$id.'/action',['action'=>'accept','revision'=>1])->assertNotFound();
        $this->login(4)->getJson('/api/go-stores/orders')->assertStatus(401);
        $this->login(2)->postJson('/api/go-stores/orders/'.$id.'/action',['action'=>'complete','revision'=>1])->assertStatus(409);
        $this->login()->postJson('/api/go-stores/orders/'.$id.'/action',['action'=>'accept','revision'=>1])->assertStatus(422);
        $o=(new Orders())->transition($id,2,true,'accept',1);
        $this->postJson('/api/go-stores/orders/'.$id.'/action',['action'=>'cancel','revision'=>$o['revision']])->assertStatus(409);
    }
    private function preparePayment(string $method='card'): array
    {
        Http::fake(['accept.paymob.com/v1/intention/'=>Http::response(['client_secret'=>'fixture-checkout','intention_order_id'=>'200'])]);
        $o=$this->submit($method);(new Payments())->checkout($o['id'],1);return $o;
    }
    private function object(array $changes=[]): array
    {
        return array_replace(['id'=>300,'order'=>['id'=>200],'amount_cents'=>8550,'currency'=>'EGP','integration_id'=>9,'is_live'=>false,'success'=>true,'pending'=>false,'is_auth'=>false,'is_capture'=>false,'is_standalone_payment'=>true,'error_occured'=>false,'is_refunded'=>false,'is_voided'=>false],$changes);
    }
    private function confirmPayment(array $object): void { (new Payments())->callback($object,PaymobHmac::digest($object,'fixture-hmac')); }
    public function test_online_checkout_is_hidden_until_verified_and_credits_full_total_once(): void
    {
        $o=$this->preparePayment();$p=new Payments();$p->checkout($o['id'],1);Http::assertSentCount(1);
        $this->login(2)->getJson('/api/go-stores/orders')->assertOk()->assertJsonPath('data.total',0);
        $this->denied(fn()=> $p->callback($this->object(),'forged'),403);
        foreach([['pending'=>true],['success'=>false],['is_auth'=>true]] as $flags)$this->confirmPayment($this->object($flags));
        $this->assertSame('100.00',$this->balance(2));
        $this->denied(fn()=> $this->confirmPayment($this->object(['amount_cents'=>1])),422);
        $this->confirmPayment($this->object());$this->confirmPayment($this->object());
        $this->assertSame('185.50',$this->balance(2));
        $this->getJson('/api/go-stores/orders')->assertOk()->assertJsonPath('data.orders.0.payment_status','paid');
        $orders=new Orders();$o=$orders->present($orders->visible($o['id'],2,true),true);
        foreach(['accept','ready','complete'] as $a)$o=$orders->transition($o['id'],2,true,$a,$o['revision']);
        $this->assertSame('176.95',$this->balance(2));$this->assertSame(2,DB::table('wallets')->count());
    }
    public function test_gateway_wallet_method_and_cancelled_late_second_capture_are_not_double_credited(): void
    {
        $o=$this->preparePayment('mobile_wallet');
        $this->denied(fn()=> $this->confirmPayment($this->object()),422);
        $orders=new Orders();$orders->transition($o['id'],1,false,'cancel',1);
        $this->confirmPayment($this->object(['integration_id'=>10]));
        $this->assertSame('100.00',$this->balance(2));
        $this->assertSame('refund_due',DB::table('go_store_payment_receipts')->value('status'));
        $this->assertSame('cancelled',DB::table('go_store_orders')->value('status'));
    }
    public function test_reject_paid_order_reverses_once_and_refund_is_not_falsely_reported_complete(): void
    {
        $o=$this->preparePayment();$this->confirmPayment($this->object());$orders=new Orders();
        $orders->transition($o['id'],2,true,'reject',2,'Unavailable');
        $this->assertSame('100.00',$this->balance(2));
        $this->assertSame('refund_pending',DB::table('go_store_orders')->value('payment_status'));
        $this->confirmPayment($this->object());$this->confirmPayment($this->object(['is_refunded'=>true]));
        $this->assertSame('100.00',$this->balance(2));$this->assertSame('refunded',DB::table('go_store_orders')->value('payment_status'));
    }
}
