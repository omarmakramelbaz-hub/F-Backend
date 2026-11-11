<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The daily balance jobs (notify:delegate_balance, notify:min_wallet_balance, notify:vendor_balance) stop an account whose
 * wallet stayed below its minimum past the deadline, then tell the account and the admins who follow it. They run with
 * nobody signed in; the wording depends on who receives the notice (an admin reads the account's name).
 */
class AccountBalanceStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('account_type')->default('user'); $t->string('status')->nullable(); $t->string('connected')->nullable();
            $t->decimal('balance', 12, 2)->nullable(); $t->decimal('min_wallet', 12, 2)->nullable(); $t->timestamp('expiration_date')->nullable();
            $t->string('decline_reason')->nullable(); $t->unsignedBigInteger('added_by')->nullable(); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('status')->nullable(); $t->timestamps(); });
        Schema::create('user_tokens', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token'); $t->timestamps(); });
        Schema::create('notifications', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('type'); $t->morphs('notifiable'); $t->text('data'); $t->timestamp('read_at')->nullable(); $t->timestamps(); });
        Schema::create('media', function (Blueprint $t) {
            $t->id(); $t->morphs('model'); $t->uuid('uuid')->nullable(); $t->string('collection_name'); $t->string('name'); $t->string('file_name');
            $t->string('mime_type')->nullable(); $t->string('disk'); $t->string('conversions_disk')->nullable(); $t->unsignedBigInteger('size');
            $t->json('manipulations'); $t->json('custom_properties'); $t->json('generated_conversions'); $t->json('responsive_images');
            $t->unsignedInteger('order_column')->nullable(); $t->timestamps();
        });
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up(); app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'المدير', 'account_type' => 'admin', 'status' => 'accepted', 'balance' => null, 'min_wallet' => null, 'expiration_date' => null],
            ['id' => 20, 'name' => 'مندوب أحمد', 'account_type' => 'delegate', 'status' => 'accepted', 'balance' => 10, 'min_wallet' => 100, 'expiration_date' => now()->subHours(3)],
            ['id' => 21, 'name' => 'مندوب محمود', 'account_type' => 'delegate', 'status' => 'accepted', 'balance' => 10, 'min_wallet' => 100, 'expiration_date' => now()->subHours(3)],
            ['id' => 30, 'name' => 'تاجر', 'account_type' => 'vendor', 'status' => 'accepted', 'balance' => 10, 'min_wallet' => 100, 'expiration_date' => null],
        ]);
        DB::table('resturants')->insert(['id' => 301, 'name' => 'فرع شبرا', 'user_id' => 30, 'status' => 'opened']);
        foreach (['delegate-list', 'vendor-list'] as $name) {
            $permission = Permission::create(['name' => $name, 'guard_name' => 'admin']);
            DB::table('model_has_permissions')->insert(['permission_id' => $permission->id, 'model_type' => User::class, 'model_id' => 1]);
        }
    }

    private function texts(int $user): array
    {
        return DB::table('notifications')->where('notifiable_id', $user)->orderBy('created_at')->pluck('data')
            ->map(fn ($data) => json_decode($data, true)['text'] ?? null)->all();
    }

    public function test_every_courier_past_the_deadline_is_stopped_and_told_with_nobody_signed_in(): void
    {
        $this->artisan('notify:delegate_balance')->assertExitCode(0);
        $this->assertSame(['disabled', 'disabled'], DB::table('users')->whereIn('id', [20, 21])->orderBy('id')->pluck('status')->all());
        $this->assertSame(['الحساب حاليا غير متاح لاستقبال طلبات جديدة عليك شحن المحفظة بالحد الأدني المطلوب'], $this->texts(20));
        $this->assertCount(1, $this->texts(21));
        // The admin reads whose account it is.
        $admin = $this->texts(1);
        $this->assertCount(2, $admin);
        $this->assertStringContainsString('مندوب أحمد', $admin[0]);
        $this->assertStringContainsString('مندوب محمود', $admin[1]);
    }

    public function test_a_negative_balance_past_the_deadline_stops_the_account_and_tells_the_admins(): void
    {
        DB::table('users')->where('id', 20)->update(['balance' => -50]);
        DB::table('users')->where('id', 21)->update(['balance' => -50]);
        $this->artisan('notify:min_wallet_balance')->assertExitCode(0);
        $this->assertSame(['disabled', 'disabled'], DB::table('users')->whereIn('id', [20, 21])->orderBy('id')->pluck('status')->all());
        $this->assertCount(1, $this->texts(20));
        $this->assertCount(2, $this->texts(1));
    }

    public function test_an_admin_reads_the_stopped_branchs_name_and_the_vendor_reads_their_own_notice(): void
    {
        $vendor = User::withoutGlobalScopes()->find(30);
        $notice = new \App\Notifications\NotifyResturantStatusNotification($vendor);
        $this->assertStringContainsString('فرع شبرا', $notice->toDatabase(User::withoutGlobalScopes()->find(1))['text']);
        $this->assertStringStartsWith('المطعم حاليا', $notice->toDatabase($vendor)['text']);
    }

    public function test_a_courier_reactivated_by_a_top_up_is_told_with_nobody_signed_in(): void
    {
        DB::table('users')->where('id', 20)->update(['status' => 'disabled', 'balance' => 150]);
        (new \App\Services\Payments\WalletTopupEffects())->afterCredit(20);
        $this->assertSame('accepted', DB::table('users')->where('id', 20)->value('status'));
        $this->assertSame(['تم تفعيل الحساب الأن يمكنك إستقبال طلبات'], $this->texts(20));
    }
}
