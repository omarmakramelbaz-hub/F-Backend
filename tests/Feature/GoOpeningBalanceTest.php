<?php

namespace Tests\Feature;

use App\Http\Resources\Api\User\WalletResource;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Api\UserAuthRepository;
use App\Services\GoAccountCreator;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoOpeningBalanceTest extends TestCase
{
    use \Tests\Support\CreatesOpeningWalletLedger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'email', 'mobile', 'password', 'account_type', 'app_scope', 'status', 'fcm_id', 'mobile_code'] as $field) $table->string($field)->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['mobile', 'account_type', 'app_scope']);
        });
        $this->createOpeningWalletLedger();
    }

    private function attributes(array $overrides = []): array
    {
        return array_replace(['name' => 'New GO account', 'mobile' => '1012345678',
            'account_type' => 'user', 'app_scope' => 'go', 'status' => 'accepted', 'password' => 'test-password'], $overrides);
    }

    public function test_each_go_account_type_starts_at_exactly_fifty_with_one_completed_ledger_entry(): void
    {
        foreach ([['go', 'user'], ['go_partner', 'delegate'], ['go_partner', 'vendor']] as [$scope, $type]) {
            $user = app(GoAccountCreator::class)->create($this->attributes(['app_scope' => $scope, 'account_type' => $type, 'balance' => 9999]));
            $this->assertSame(50.0, $user->balance);
            $this->assertSame(50.0, $user->fresh()->balance);
            $wallet = Wallet::where('to_user', $user->id)->sole();
            $this->assertSame(50.0, (float) $wallet->amount);
            $this->assertSame('completed', $wallet->status);
            $this->assertSame('charging', $wallet->type);
            $this->assertSame(GoAccountCreator::openingReference($user->id), $wallet->transfer_reference);
            $this->assertSame('opening_balance', (new WalletResource($wallet))->resolve()['payment']);
        }
        $this->assertSame(3, Wallet::count());
    }

    public function test_fasakhansta_and_non_partner_accounts_keep_their_existing_balance_policy(): void
    {
        foreach ([['fasakhansta', 'user'], ['fasakhansta', 'delegate'], [null, 'vendor'], ['go', 'admin'], ['go_partner', 'user']] as [$scope, $type]) {
            $user = app(GoAccountCreator::class)->create($this->attributes(['app_scope' => $scope, 'account_type' => $type, 'balance' => 7]));
            $this->assertSame(7.0, $user->fresh()->balance);
        }
        $this->assertSame(0, Wallet::count());
    }

    public function test_customer_first_login_creates_credit_but_later_logins_do_not_reset_spending(): void
    {
        app()->instance('request', Request::create('/api/user/login', 'POST', [], [], [], ['HTTP_X_APP_SCOPE' => 'go']));
        $credentials = ['mobile' => '1012345678', 'password' => 'test-password', 'fcm_id' => null];
        $first = app(UserAuthRepository::class)->loginUser($credentials);
        $this->assertSame(1, $first['register']);
        $this->assertSame(50.0, $first['user']->balance);
        DB::table('users')->where('id', $first['user']->id)->decrement('balance', 20);
        $second = app(UserAuthRepository::class)->loginUser($credentials);
        $this->assertSame(0, $second['register']);
        $this->assertSame(30.0, $second['user']->balance);
        $this->assertSame(1, Wallet::count());
        $this->assertSame(2, app(UserAuthRepository::class)->loginUser(array_replace($credentials, ['password' => 'wrong-password'])));
        $this->assertSame(30.0, $first['user']->fresh()->balance);
    }

    public function test_existing_accounts_are_not_backfilled_on_login_or_profile_changes(): void
    {
        $user = User::create($this->attributes(['balance' => 12.5]));
        app()->instance('request', Request::create('/api/user/login', 'POST', [], [], [], ['HTTP_X_APP_SCOPE' => 'go']));
        app(UserAuthRepository::class)->loginUser(['mobile' => $user->mobile, 'password' => 'test-password', 'fcm_id' => null]);
        $user->update(['name' => 'Updated name']);
        $this->assertSame(12.5, $user->fresh()->balance);
        $this->assertSame(0, Wallet::count());
    }

    public function test_a_duplicate_registration_does_not_add_a_second_credit(): void
    {
        $user = app(GoAccountCreator::class)->create($this->attributes());
        try {
            app(GoAccountCreator::class)->create($this->attributes());
            $this->fail('Duplicate registration must be rejected.');
        } catch (QueryException $error) {
            $this->assertSame(1, User::withoutGlobalScopes()->count());
            $this->assertSame(1, Wallet::count());
            $this->assertSame(50.0, $user->fresh()->balance);
        }
    }

    public function test_ledger_failure_rolls_back_the_account_and_balance_together(): void
    {
        DB::statement("CREATE TRIGGER fail_opening_credit BEFORE INSERT ON wallets BEGIN SELECT RAISE(ABORT, 'opening credit fixture failure'); END");
        try {
            app(GoAccountCreator::class)->create($this->attributes());
            $this->fail('Ledger failure must reject registration.');
        } catch (QueryException $error) {
            $this->assertSame(0, User::withoutGlobalScopes()->count());
            $this->assertSame(0, Wallet::count());
        }
    }

    public function test_opening_credit_also_works_before_the_optional_transfer_reference_migration(): void
    {
        Schema::drop('wallets');
        $this->createOpeningWalletLedger(false);
        $user = app(GoAccountCreator::class)->create($this->attributes());
        $this->assertSame(50.0, $user->fresh()->balance);
        $this->assertSame(1, Wallet::count());
        $this->assertSame(50.0, (float) Wallet::first()->amount);
        $this->assertSame('wallet', (new WalletResource(Wallet::first()))->resolve()['payment']);
    }
}
