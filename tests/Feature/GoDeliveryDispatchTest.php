<?php
namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\GoDelivery\Dispatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoDeliveryDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema'); Event::fake(); Notification::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); foreach (['name','account_type','app_scope','status','connected','lat','lng'] as $key) $t->string($key)->nullable();
            $t->decimal('balance', 14, 2)->default(100); $t->unsignedBigInteger('pending_vendor_id')->nullable(); $t->timestamps();
        });
        Schema::create('pending_vendors', function (Blueprint $t) {
            $t->id(); foreach (['application_kind','status','profession_key','lat','lng'] as $key) $t->string($key)->nullable();
            $t->integer('work_radius_km')->nullable();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('delegate_id')->nullable();
            foreach (['type','status','delegate_from_out','order_no'] as $key) $t->string($key)->nullable(); $t->timestamps();
        });
        Schema::create('shippings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->decimal('from_lat',10,7); $t->decimal('from_lng',10,7); });
        Schema::create('delegate_notifications', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('delegate_id'); $t->string('status')->nullable(); $t->timestamps(); });
        DB::table('users')->insert(['id'=>1, 'name'=>'Customer fixture', 'account_type'=>'user','app_scope'=>'go','status'=>'accepted','balance'=>100]);
    }
    private function partner(int $id, array $overrides = [], array $work = []): User
    {
        DB::table('pending_vendors')->insert(array_replace(['id'=>$id,'application_kind'=>'partner','status'=>'accepted','profession_key'=>'delivery_courier','lat'=>30,'lng'=>31,'work_radius_km'=>20], $work));
        // GPS is absent: dispatch must use the registered work-area centre.
        DB::table('users')->insert(array_replace(['id'=>$id,'name'=>'Courier fixture','account_type'=>'delegate','app_scope'=>'go_partner','status'=>'accepted','connected'=>'active','balance'=>100,'pending_vendor_id'=>$id], $overrides));
        return User::withoutGlobalScopes()->findOrFail($id);
    }
    private function order(int $id, array $extra = []): Order
    {
        DB::table('orders')->insert(array_replace(['id'=>$id,'user_id'=>1,'type'=>'shipping','status'=>'pending','created_at'=>now(),'updated_at'=>now()], $extra));
        DB::table('shippings')->insert(['order_id'=>$id,'from_lat'=>30.12,'from_lng'=>31]); // 13.3 km; beyond the old hard-coded 10 km.
        return Order::withoutGlobalScopes()->findOrFail($id);
    }
    public function test_registered_radius_and_center_include_courier_beyond_old_ten_km(): void
    {
        $this->partner(2); $this->partner(3, [], ['work_radius_km'=>10]);
        $ids=(new Dispatch())->candidates(30.12,31)->pluck('id')->all();
        $this->assertEquals([2],$ids);
        $this->assertCount(0,(new Dispatch())->candidates(null,31));
    }
    public function test_profession_status_online_wallet_and_scope_filters(): void
    {
        $this->partner(2); $this->partner(3, ['connected'=>'inactive']); $this->partner(4,['balance'=>49.99]);
        $this->partner(5,[],['profession_key'=>'plumber']); $this->partner(6,['status'=>'pending']);
        $this->partner(7,[],['status'=>'pending']); $this->partner(8,['account_type'=>'vendor']);
        $this->partner(9,['app_scope'=>'go']);
        $this->assertEquals([2],(new Dispatch())->candidates(30,31)->pluck('id')->all());
    }
    public function test_legacy_courier_supported_without_professional_application(): void
    {
        DB::table('users')->insert(['id'=>2,'account_type'=>'delegate','app_scope'=>'fasakhansta','status'=>'accepted','connected'=>'active','balance'=>50,'lat'=>30,'lng'=>31]);
        $this->assertEquals([2],(new Dispatch())->candidates(30,31)->pluck('id')->all());
    }
    public function test_dispatch_persists_every_invitation_even_when_push_fails_and_does_not_reset_declines(): void
    {
        $this->partner(2); $this->partner(3); $order=$this->order(10);
        Notification::shouldReceive('send')->twice()->andThrow(new \RuntimeException('Push unavailable'));
        $dispatch=new Dispatch(); $this->assertSame(2,$dispatch->dispatch($order));
        $this->assertSame(2,DB::table('delegate_notifications')->count());
        DB::table('delegate_notifications')->where('delegate_id',2)->update(['status'=>'declined']);
        $this->assertSame(0,$dispatch->dispatch($order));
        $this->assertSame('declined',DB::table('delegate_notifications')->where('delegate_id',2)->value('status'));
    }
    public function test_inbox_recovers_existing_missed_orders_but_never_unpaid_assigned_cancelled_or_declined(): void
    {
        $partner=$this->partner(2); $this->order(10); $this->order(11,['status'=>null]);
        $this->order(12,['delegate_id'=>3]); $this->order(13,['status'=>'cancelled']); $this->order(14);
        DB::table('delegate_notifications')->insert(['order_id'=>14,'delegate_id'=>2,'status'=>'declined']);
        $dispatch=new Dispatch(); $dispatch->syncInbox($partner); $dispatch->syncInbox($partner);
        $this->assertSame(1,DB::table('delegate_notifications')->where('order_id',10)->count());
        $this->assertSame(2,DB::table('delegate_notifications')->count());
        $this->assertSame('out_resturant',DB::table('orders')->where('id',10)->value('delegate_from_out'));
        $this->assertSame('declined',DB::table('delegate_notifications')->where('order_id',14)->value('status'));
    }
}
