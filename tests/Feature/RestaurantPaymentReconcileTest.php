<?php

namespace Tests\Feature;

use App\Services\Payments\RestaurantOrderPayment;
use App\Services\Payments\RestaurantPaymentEffects;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestaurantPaymentReconcileTest extends TestCase
{
    private ReconciliationPaymentService $service;
    private ReconciliationPaymentEffects $effects;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:',
            'cache.default'=>'array', 'desktop_dashboard.local'=>false, 'wallet_topups.api_key'=>'not-a-live-key',
            'wallet_topups.live'=>true, 'dashboard_payments.integrations.online'=>'765', 'dashboard_payments.integrations.v_cash'=>'766']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema'); Cache::flush();
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('resturant_id')->default(100);
            $t->string('type')->default('current'); $t->string('status')->nullable(); $t->string('payment_type')->default('online');
            $t->string('accepted_notify')->nullable(); $t->string('transfer_price_by')->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('user_id');
            $t->string('intention_order_id')->nullable(); $t->decimal('total_price',14,2)->default(125);
            $t->string('status')->nullable(); $t->string('transaction_id')->nullable();
        });
        Schema::create('wallets', function (Blueprint $t) { $t->id(); $t->decimal('amount',14,2); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->decimal('balance',14,2); });
        DB::table('users')->insert(['id'=>20,'balance'=>'500.00']);
        DB::table('wallets')->insert(['id'=>1,'amount'=>'200.00']);
        $this->service = new ReconciliationPaymentService();
        $this->effects = new ReconciliationPaymentEffects();
        $this->app->instance(RestaurantOrderPayment::class, $this->service);
        $this->app->instance(RestaurantPaymentEffects::class, $this->effects);
    }

    private function attempt(int $id, array $order = [], array $payment = []): void
    {
        DB::table('orders')->insert(array_replace(['id'=>$id, 'user_id'=>20, 'resturant_id'=>100, 'status'=>null,
            'type'=>'current', 'payment_type'=>'online'], $order));
        DB::table('payments')->insert(array_replace(['id'=>$id, 'order_id'=>$id, 'user_id'=>20,
            'intention_order_id'=>(string) (7000+$id), 'total_price'=>'125.00'], $payment));
    }

    private function runCommand(array $values = []): array
    {
        $status = Artisan::call('order-payments:reconcile', $values);
        $output = Artisan::output();
        if ($status !== 0) return [$status, $output, null];
        $line = trim($output);
        $this->assertStringStartsWith('RESTAURANT_PAYMENT_RECONCILIATION ', $line);
        return [$status, $output, json_decode(substr($line, strlen('RESTAURANT_PAYMENT_RECONCILIATION ')), true)];
    }

    public function test_default_is_read_only_and_does_not_inquire_or_advance_rotation(): void
    {
        $this->attempt(1); $this->attempt(2);
        $before = DB::table('orders')->get()->toArray();
        [$status, , $counts] = $this->runCommand(['--limit'=>'1']);
        $this->assertSame(0, $status); $this->assertSame('check', $counts['mode']); $this->assertSame(1, $counts['candidates']);
        $this->assertSame([], $this->service->calls); $this->assertSame([], $this->effects->calls);
        $this->assertEquals($before, DB::table('orders')->get()->toArray());
        $this->runCommand(['--apply'=>true, '--limit'=>'1']);
        $this->assertSame([2], $this->service->calls);
    }

    public function test_apply_calls_shared_verification_then_notifies_only_new_activations(): void
    {
        foreach ([1,2,3,4] as $id) $this->attempt($id, ['payment_type'=>$id%2 ? 'online' : 'v_cash']);
        $this->service->results=[1=>'activated',2=>'unchanged',3=>'closed',4=>'throw'];
        [$status, $output, $counts] = $this->runCommand(['--apply'=>true]);
        $this->assertSame(0, $status);
        $this->assertSame(['activated'=>1,'unchanged'=>1,'closed'=>1,'deferred'=>1], array_intersect_key($counts,array_flip(['activated','unchanged','closed','deferred'])));
        $this->assertSame([4,3,2,1], $this->service->calls); $this->assertSame([1], $this->effects->calls);
        $this->assertSame([0,0,0,0], $this->service->transactionLevels);
        $this->assertStringNotContainsString('secret-provider-token', $output);
        $this->assertSame('pending', DB::table('orders')->where('id',1)->value('status'));
        $this->assertSame(500.0, (float) DB::table('users')->where('id',20)->value('balance'));
        $this->assertSame(200.0, (float) DB::table('wallets')->where('id',1)->value('amount'));
    }

    public function test_effect_failure_cannot_turn_committed_activation_into_failed_payment(): void
    {
        $this->attempt(1); $this->service->results=[1=>'activated']; $this->effects->fail=true;
        [, , $counts]=$this->runCommand(['--apply'=>true]);
        $this->assertSame(1,$counts['activated']); $this->assertSame(0,$counts['deferred']); $this->assertSame(1,$counts['effects_deferred']);
        $this->assertSame('pending',DB::table('orders')->where('id',1)->value('status'));
        $this->runCommand(['--apply'=>true]); $this->assertSame([1],$this->service->calls);
    }

    public function test_rotation_reaches_older_attempts_without_starvation_and_wraps(): void
    {
        foreach ([1,2,3] as $id) $this->attempt($id);
        foreach ([3,2,1,3] as $expected) {
            $this->runCommand(['--apply'=>true,'--limit'=>'1']);
            $this->assertSame($expected, end($this->service->calls));
        }
    }

    public function test_batch_only_uses_one_attempt_per_order_and_scope_excludes_inactive_orders(): void
    {
        $this->attempt(1); $this->attempt(2,['status'=>'accepted']); $this->attempt(3,['status'=>'completed']);
        $this->attempt(4,['status'=>'cancelled']); $this->attempt(5,['type'=>'wallet']); $this->attempt(6,['payment_type'=>'cash']);
        $this->attempt(7,[],['user_id'=>999]); $this->attempt(8,[],['total_price'=>'0.00']);
        $this->attempt(9,[],['intention_order_id'=>'invalid']); $this->attempt(10,['accepted_notify'=>'yes']);
        $this->attempt(11,['transfer_price_by'=>'admin']);
        DB::table('payments')->insert(['id'=>12,'order_id'=>1,'user_id'=>20,'intention_order_id'=>'7012','total_price'=>'125.00']);
        $this->runCommand(['--apply'=>true]);
        $this->assertSame([12],$this->service->calls);
    }

    public function test_explicit_real_order_ids_do_not_reconcile_other_rows_or_change_rotation(): void
    {
        $this->attempt(1); $this->attempt(2);
        $this->runCommand(['--apply'=>true,'--order-id'=>['1']]); $this->assertSame([1],$this->service->calls);
        $this->runCommand(['--apply'=>true,'--limit'=>'1']); $this->assertSame([1,2],$this->service->calls);
    }

    public function test_invalid_options_missing_config_and_local_device_refuse_without_inquiry(): void
    {
        $this->attempt(1);
        foreach (['0','21','-1','1.5','abc'] as $limit) $this->assertSame(1,$this->runCommand(['--apply'=>true,'--limit'=>$limit])[0]);
        foreach (['0','PH-1','1 OR 1=1'] as $id) $this->assertSame(1,$this->runCommand(['--apply'=>true,'--order-id'=>[$id]])[0]);
        config(['wallet_topups.api_key'=>'']); $this->assertSame(1,$this->runCommand(['--apply'=>true])[0]);
        config(['wallet_topups.api_key'=>'not-a-live-key','desktop_dashboard.local'=>true]);
        $this->assertSame(1,$this->runCommand(['--apply'=>true])[0]); $this->assertSame([],$this->service->calls);
    }

    public function test_missing_schema_and_method_configuration_do_not_inquire(): void
    {
        $this->attempt(1); config(['dashboard_payments.integrations.online'=>'']);
        [, , $counts]=$this->runCommand(['--apply'=>true]); $this->assertSame(1,$counts['deferred']); $this->assertSame([],$this->service->calls);
        Schema::drop('payments'); $this->assertSame(1,$this->runCommand(['--apply'=>true])[0]);
    }
}

class ReconciliationPaymentService extends RestaurantOrderPayment
{
    public array $calls=[];
    public array $results=[];
    public array $transactionLevels=[];
    public function __construct() {}
    public function settle(int $paymentId, ?string $transactionId=null): array
    {
        $this->calls[]=$paymentId; $this->transactionLevels[]=DB::transactionLevel();
        $state=$this->results[$paymentId]??'unchanged';
        if ($state==='throw') throw new \RuntimeException('secret-provider-token');
        $payment=DB::table('payments')->where('id',$paymentId)->first();
        if ($state==='activated') {
            DB::table('orders')->where('id',$payment->order_id)->update(['status'=>'pending']);
            DB::table('payments')->where('id',$paymentId)->update(['status'=>'1','transaction_id'=>'9001']);
        }
        return ['state'=>$state,'order_id'=>(int)$payment->order_id,'payment_id'=>$paymentId,'transaction_id'=>'9001'];
    }
}

class ReconciliationPaymentEffects extends RestaurantPaymentEffects
{
    public array $calls=[];
    public bool $fail=false;
    public function afterActivation(array $result): void
    {
        $this->calls[]=$result['payment_id'];
        if ($this->fail) throw new \RuntimeException('secret-provider-token');
    }
}
