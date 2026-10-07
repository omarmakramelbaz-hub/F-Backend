<?php

namespace Tests\Feature;

use App\Events\BalanceUpdated;
use App\Events\NotificationUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotifyAcceptOrderNotification;
use App\Services\Dashboard\BestEffortOrderMail;
use App\Services\Dashboard\OrderProviderDelivery;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderProviderDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32)), 'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('account_type'); $t->decimal('balance', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('user_tokens', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token'); $t->timestamps(); });
        Schema::create('provider_orders', function (Blueprint $t) { $t->id(); $t->string('status'); $t->decimal('settled', 12, 2); });
        require_once base_path('database/migrations/2022_08_17_095029_create_notifications_table.php');
        (new \CreateNotificationsTable())->up();
        DB::table('users')->insert([['id' => 10, 'name' => 'Customer', 'account_type' => 'user', 'balance' => 80],
            ['id' => 20, 'name' => 'Store', 'account_type' => 'vendor', 'balance' => 50]]);
        DB::table('user_tokens')->insert([['user_id' => 10, 'token' => 'customer-device'], ['user_id' => 20, 'token' => 'store-device']]);
        ProviderProbeNotice::$sent = []; ProviderProbeNotice::$fail = false; ProviderProbeNotice::$refused = false;
        Event::fake([NotificationUpdated::class, BalanceUpdated::class]);
    }

    private function order(): Order
    {
        $order = new Order(['order_no' => 'ORD-501', 'status' => 'accepted', 'resturant_id' => 99]);
        $order->id = 501; $order->setRelation('resturant', null);
        return $order;
    }

    private function actor(int $id): User { return User::withoutGlobalScopes()->findOrFail($id); }

    public function test_http_response_precedes_push_broadcast_and_mail_while_database_evidence_is_committed(): void
    {
        $sentResponse = false; $mailRan = false;
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use (&$sentResponse, &$mailRan) {
            $this->assertTrue($sentResponse); $this->assertSame(0, DB::transactionLevel()); $mailRan = true;
        });
        Route::post('/provider-delivery-fixture', function () {
            DB::transaction(function () {
                DB::table('provider_orders')->insert(['id' => 501, 'status' => 'accepted', 'settled' => 12.50]);
                Notification::send($this->actor(10), new ProviderProbeNotice($this->order()));
                app(BestEffortOrderMail::class)->send(501, 'fixture', [], fn () => null);
            });
            return response()->json(['status' => 'accepted']);
        });
        $kernel = app(Kernel::class); $request = Request::create('/provider-delivery-fixture', 'POST');
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('accepted', DB::table('provider_orders')->value('status'));
        $this->assertSame(12.5, (float) DB::table('provider_orders')->value('settled'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', 10)->count());
        $this->assertSame([], ProviderProbeNotice::$sent); $this->assertFalse($mailRan);
        Event::assertNotDispatched(NotificationUpdated::class);
        $sentResponse = true; $kernel->terminate($request, $response);
        $this->assertTrue($mailRan); $this->assertCount(1, ProviderProbeNotice::$sent);
        Event::assertDispatched(NotificationUpdated::class, fn ($event) => $event->senderId === 10 && $event->broadcastOn()->name === 'private-user.10');
    }

    public function test_nested_rollback_discards_its_deliveries_and_outer_commit_waits_for_termination(): void
    {
        $delivered = []; $delivery = app(OrderProviderDelivery::class);
        DB::beginTransaction();
        $delivery->defer(function () use (&$delivered) { $delivered[] = 'outer'; }, 501);
        DB::beginTransaction();
        $delivery->defer(function () use (&$delivered) { $delivered[] = 'rolled-back-inner'; }, 501);
        DB::rollBack();
        DB::commit();
        $this->assertSame([], $delivered);
        app()->terminate(); $this->assertSame(['outer'], $delivered);
        DB::beginTransaction();
        $delivery->defer(function () use (&$delivered) { $delivered[] = 'rolled-back-outer'; }, 501);
        DB::rollBack(); app()->terminate();
        $this->assertSame(['outer'], $delivered);
    }

    public function test_failed_provider_cannot_undo_settlement_or_notification_and_does_not_stop_other_deliveries(): void
    {
        ProviderProbeNotice::$fail = true; $nextRan = false;
        Log::shouldReceive('warning')->once()->with('Order provider delivery unavailable',
            ['order_id' => 501, 'provider' => 'push', 'exception' => \RuntimeException::class]);
        DB::transaction(function () use (&$nextRan) {
            DB::table('provider_orders')->insert(['id' => 501, 'status' => 'completed', 'settled' => 12.50]);
            Notification::send($this->actor(10), new ProviderProbeNotice($this->order()));
            app(OrderProviderDelivery::class)->defer(function () use (&$nextRan) { $nextRan = true; }, 501, 'second-provider');
        });
        app()->terminate();
        $this->assertTrue($nextRan); $this->assertSame('completed', DB::table('provider_orders')->value('status'));
        $this->assertSame(12.5, (float) DB::table('provider_orders')->value('settled'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', 10)->count());
    }

    public function test_reused_notification_keeps_each_private_recipient_tokens_and_status_snapshot(): void
    {
        $order = $this->order(); $notice = new ProviderProbeNotice($order);
        Notification::send($this->actor(10), $notice);
        $order->status = 'preparing';
        Notification::send($this->actor(20), $notice);
        $this->assertSame([], ProviderProbeNotice::$sent);
        app()->terminate();
        $this->assertSame([['token' => 'customer-device']], ProviderProbeNotice::$sent[0]['tokens']);
        $this->assertSame('user', ProviderProbeNotice::$sent[0]['body']['data']['account_type']);
        $this->assertSame('accepted', ProviderProbeNotice::$sent[0]['body']['data']['order_status']);
        $this->assertSame([['token' => 'store-device']], ProviderProbeNotice::$sent[1]['tokens']);
        $this->assertSame('vendor', ProviderProbeNotice::$sent[1]['body']['data']['account_type']);
        $this->assertSame('preparing', ProviderProbeNotice::$sent[1]['body']['data']['order_status']);
        app()->terminate(); $this->assertCount(2, ProviderProbeNotice::$sent);
    }

    public function test_push_provider_json_refusal_is_logged_safely_after_committed_notification(): void
    {
        ProviderProbeNotice::$refused = true;
        Log::shouldReceive('warning')->once()->with('Order provider delivery unavailable',
            ['order_id' => 501, 'provider' => 'push', 'exception' => \RuntimeException::class]);
        DB::transaction(fn () => Notification::send($this->actor(10), new ProviderProbeNotice($this->order())));
        $this->assertSame(1, DB::table('notifications')->count());
        app()->terminate(); $this->assertCount(1, ProviderProbeNotice::$sent);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_balance_event_is_after_commit_and_snapshots_owner_balance_without_changing_model_work(): void
    {
        $user = $this->actor(20);
        DB::transaction(function () use ($user) { $user->balance = 65; $user->save(); });
        $this->assertSame(65.0, (float) DB::table('users')->where('id', 20)->value('balance'));
        Event::assertNotDispatched(BalanceUpdated::class);
        $user->balance = 900;
        app()->terminate();
        Event::assertDispatched(BalanceUpdated::class, fn ($event) => $event->broadcastOn()->name === 'private-user.20'
            && (float) $event->broadcastWith()['user_balance'] === 65.0);
    }

    public function test_console_termination_delivers_committed_work_once_without_queue_worker(): void
    {
        $ran = 0;
        DB::transaction(function () use (&$ran) { app(OrderProviderDelivery::class)->defer(function () use (&$ran) { $ran++; }, 501); });
        $this->assertSame(0, $ran);
        app(\Illuminate\Contracts\Console\Kernel::class)->terminate(new \Symfony\Component\Console\Input\ArrayInput([]), 0);
        $this->assertSame(1, $ran);
        app()->terminate(); $this->assertSame(1, $ran);
    }

    public function test_email_keeps_committed_order_snapshot_when_model_is_reused_later(): void
    {
        $order = $this->order();
        Mail::shouldReceive('send')->once()->withArgs(fn ($view, $data) => $view === 'fixture'
            && $data['cart']->status === 'accepted' && $data['email'] === 'recipient@example.test');
        DB::transaction(fn () => app(BestEffortOrderMail::class)->send(501, 'fixture',
            ['cart' => $order, 'email' => 'recipient@example.test'], fn () => null));
        $order->status = 'completed'; app()->terminate();
    }
}

class ProviderProbeNotice extends NotifyAcceptOrderNotification
{
    public static array $sent = [];
    public static bool $fail = false;
    public static bool $refused = false;
    public function sendFcmNotification($tokens = null, $body = [])
    {
        self::$sent[] = ['tokens' => $tokens, 'body' => $body];
        if (self::$fail) throw new \RuntimeException('SECRET device token and provider credentials');
        if (self::$refused) return response()->json(['responses' => ['SECRET-token' => ['error' => ['message' => 'SECRET provider detail']]]]);
    }
}
