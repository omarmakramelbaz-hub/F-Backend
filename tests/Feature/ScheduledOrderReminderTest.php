<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * notify:schedule (daily): each branch is reminded of its orders scheduled for tomorrow, by email and on the
 * dashboard. An order is never mailed to another order's branch, and one email that fails does not stop the others.
 */
class ScheduledOrderReminderTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Notification::fake();
        DB::table('users')->insert(['id' => 30, 'name' => 'تاجر شبرا', 'email' => 'shubra@example.test', 'mobile' => '1000000030', 'account_type' => 'vendor', 'status' => 'accepted']);
        DB::table('resturants')->where('id', 301)->update(['user_id' => 30]);
        $tomorrow = now()->addDay()->toDateString();
        DB::table('orders')->insert([
            ['id' => 500, 'user_id' => 7, 'resturant_id' => null, 'status' => 'pending', 'type' => 'current', 'order_type' => 'schedule', 'schedule_date' => $tomorrow],
            ['id' => 501, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'pending', 'type' => 'current', 'order_type' => 'schedule', 'schedule_date' => $tomorrow],
            ['id' => 502, 'user_id' => 8, 'resturant_id' => null, 'status' => 'pending', 'type' => 'current', 'order_type' => 'another_zone', 'schedule_date' => $tomorrow],
        ]);
    }

    /** Records each reminder email (who to, for which order) instead of sending it. */
    private function mails(?callable $fail = null): void
    {
        Mail::shouldReceive('send')->andReturnUsing(function ($view, $data, $callback) use ($fail) {
            $message = new class { public array $to = []; public function to($address) { $this->to[] = $address; return $this; } public function subject($subject) { return $this; } };
            $callback($message);
            if ($fail && $fail($data['cart'])) throw new \RuntimeException('SMTP down');
            $this->sent[] = [$data['cart']->id, $message->to];
        });
    }

    public function test_each_order_is_mailed_to_its_own_branch_only(): void
    {
        $this->mails();
        $this->artisan('notify:schedule')->assertExitCode(0);
        // Orders 500 and 502 have no branch: nobody is mailed for them (never the previous order's branch).
        $this->assertSame([[501, ['shubra@example.test']]], $this->sent);
        Notification::assertSentTo(\App\Models\User::withoutGlobalScopes()->find(30), \App\Notifications\NotifySheduleOrderNotification::class);
    }

    public function test_a_failing_email_does_not_stop_the_dashboard_reminder(): void
    {
        $this->mails(fn ($order) => true);
        $this->artisan('notify:schedule')->assertExitCode(0);
        Notification::assertSentTo(\App\Models\User::withoutGlobalScopes()->find(30), \App\Notifications\NotifySheduleOrderNotification::class);
    }

    public function test_a_branch_opens_and_another_closes_on_time_even_when_the_reminder_email_fails(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        DB::table('resturants')->where('id', 301)->update(['open_at' => '10:00', 'status' => 'closed']);
        DB::table('resturants')->insert(['id' => 302, 'name' => 'فرع المنصورة', 'user_id' => 30, 'status' => 'opened', 'close_at' => '10:00']);
        DB::table('orders')->where('id', 501)->update(['schedule_date' => now()->toDateString()]);
        $this->mails(fn ($order) => true);
        $this->artisan('notify:open_close_resturant')->assertExitCode(0);
        $this->assertSame(['opened', 'closed'], DB::table('resturants')->whereIn('id', [301, 302])->orderBy('id')->pluck('status')->all());
    }
}
