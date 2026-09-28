<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\NotifyTransferWallet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WalletTransferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:']);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        $this->withoutMiddleware();
        Notification::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name','mobile','account_type','app_scope','status'] as $field) $t->string($field)->nullable();
            $t->decimal('balance',14,2)->default(0);
            $t->decimal('min_wallet',14,2)->default(50);
            $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('from_user'); $t->unsignedBigInteger('to_user');
            $t->decimal('amount',14,2); $t->string('type'); $t->string('payment'); $t->string('status');
            $t->timestamps();
        });
        require_once base_path('database/migrations/2026_09_28_190000_add_transfer_reference_to_wallets.php');
        (new \AddTransferReferenceToWallets())->up();
        $this->user(1, 'user', 'go', '1099999999', 500);
        // The same telephone represents three independent wallets.
        $this->user(10, 'user', 'go');
        $this->user(11, 'delegate', 'go_partner');
        $this->user(12, 'user', 'fasakhansta');
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(1), 'api');
    }

    private function user(int $id, string $role, ?string $scope, string $phone = '1012345678', int $balance = 0): void
    {
        DB::table('users')->insert(['id'=>$id,'name'=>'Wallet '.$id,'mobile'=>$phone,
            'account_type'=>$role,'app_scope'=>$scope,'status'=>'accepted','balance'=>$balance]);
    }

    private function payload(string $target = 'go_customer', string $mobile = '01012345678'): array
    {
        return ['target_wallet'=>$target, 'mobile'=>$mobile, 'amount'=>'10.50'];
    }

    private function confirm(array $payload): array
    {
        $preview = $this->postJson('/api/check/user/transfer', $payload)->assertOk()->json('data');
        return $payload + ['recipient_id'=>$preview['user_id'], 'transfer_token'=>$preview['transfer_token']];
    }

    public function test_every_sender_can_choose_each_of_the_three_wallets_without_phone_collision(): void
    {
        $roles = [['user','go'], ['user','fasakhansta'], ['delegate','go_partner'],
            ['vendor','go_partner'], ['delegate','fasakhansta'], ['vendor','fasakhansta']];
        foreach ($roles as $index => [$role,$scope]) {
            $sender = 20 + $index;
            $this->user($sender, $role, $scope, '109999990'.$index, 500);
            $this->actingAs(User::withoutGlobalScopes()->findOrFail($sender), 'api');
            foreach (['go_customer'=>10, 'go_partner'=>11, 'fasakhansta_customer'=>12] as $target=>$recipient) {
                $data = $this->confirm($this->payload($target));
                $this->assertSame($recipient, $data['recipient_id']);
                $this->postJson('/api/transfer/wallet', $data)->assertOk()->assertJsonPath('data.to_user', $recipient);
            }
            $this->assertEquals(468.50, DB::table('users')->where('id',$sender)->value('balance'));
        }
        foreach ([10,11,12] as $id) $this->assertEquals(63, DB::table('users')->where('id',$id)->value('balance'));
        $this->assertSame(18, DB::table('wallets')->count());
    }

    public function test_partner_wallet_includes_stores_and_legacy_couriers_but_not_fasakhansta_stores(): void
    {
        $this->user(30,'vendor','go_partner','1055555555');
        $this->user(31,'delegate',null,'1066666666');
        $this->user(32,'vendor','fasakhansta','1077777777');
        foreach (['01055555555'=>30, '+201066666666'=>31] as $phone=>$id) {
            $this->postJson('/api/check/user/transfer',$this->payload('go_partner',(string)$phone))
                ->assertOk()->assertJsonPath('data.user_id',$id);
        }
        $this->postJson('/api/check/user/transfer',$this->payload('go_partner','01077777777'))->assertStatus(422);
    }

    public function test_missing_invalid_or_wrong_destination_never_falls_back_to_other_app(): void
    {
        foreach (['', 'user', 'vendor', 'go', 'invalid'] as $target) {
            $this->postJson('/api/check/user/transfer',$this->payload($target))->assertStatus(422);
        }
        $this->user(35,'user','fasakhansta','1088888888');
        $this->postJson('/api/check/user/transfer',$this->payload('go_customer','01088888888'))->assertStatus(422);
        $this->postJson('/api/transfer/wallet',['mobile'=>'01012345678','account_type'=>'user','amount'=>10])->assertStatus(422);
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_multiple_partners_with_same_phone_are_not_selected_arbitrarily(): void
    {
        $this->user(36,'vendor','go_partner');
        $this->postJson('/api/check/user/transfer',$this->payload('go_partner'))->assertStatus(422);
        $this->postJson('/api/check/user/transfer',$this->payload('go_customer'))->assertOk()->assertJsonPath('data.user_id',10);
    }

    public function test_confirmation_binds_sender_recipient_app_phone_and_amount(): void
    {
        $confirmed = $this->confirm($this->payload());
        foreach (['target_wallet'=>'fasakhansta_customer','recipient_id'=>12,'amount'=>20,'mobile'=>'01000000000','transfer_token'=>'forged'] as $field=>$value) {
            $this->postJson('/api/transfer/wallet',array_replace($confirmed,[$field=>$value]))
                ->assertStatus(422)->assertJsonPath('transfer_rejected',true);
        }
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(11),'api');
        $this->postJson('/api/transfer/wallet',$confirmed)->assertStatus(422);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertEquals(500, DB::table('users')->where('id',1)->value('balance'));
    }

    public function test_self_pending_declined_and_invalid_amounts_are_rejected(): void
    {
        $this->postJson('/api/check/user/transfer',$this->payload('go_customer','01099999999'))->assertStatus(422);
        foreach (['pending','declined'] as $status) {
            DB::table('users')->where('id',10)->update(['status'=>$status]);
            $this->postJson('/api/check/user/transfer',$this->payload())->assertStatus(422);
        }
        foreach (['0','-10','5001','1.001','NaN'] as $amount) {
            $this->postJson('/api/check/user/transfer',array_replace($this->payload(),['amount'=>$amount]))->assertStatus(422);
        }
    }

    public function test_retries_do_not_duplicate_money_or_notifications_even_after_expiry(): void
    {
        $confirmed = $this->confirm($this->payload());
        $first = $this->postJson('/api/transfer/wallet',$confirmed)->assertOk()->json('data.id');
        $this->travel(11)->minutes();
        $this->postJson('/api/transfer/wallet',$confirmed)->assertOk()->assertJsonPath('data.id',$first);
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertEquals(489.50, DB::table('users')->where('id',1)->value('balance'));
        $this->assertEquals(10.50, DB::table('users')->where('id',10)->value('balance'));
        Notification::assertSentToTimes(User::withoutGlobalScopes()->find(10), NotifyTransferWallet::class, 1);
    }

    public function test_balance_and_recipient_scope_are_rechecked_at_commit(): void
    {
        $confirmed = $this->confirm($this->payload());
        DB::table('users')->where('id',1)->update(['balance'=>5]);
        $this->postJson('/api/transfer/wallet',$confirmed)->assertStatus(422);
        DB::table('users')->where('id',1)->update(['balance'=>500]);
        DB::table('users')->where('id',10)->update(['app_scope'=>'fasakhansta']);
        $this->postJson('/api/transfer/wallet',$confirmed)->assertStatus(422);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertEquals(500, DB::table('users')->where('id',1)->value('balance'));
    }

    public function test_expired_unexecuted_confirmation_requires_another_review(): void
    {
        $confirmed = $this->confirm($this->payload());
        $this->travel(11)->minutes();
        $this->postJson('/api/transfer/wallet',$confirmed)->assertStatus(422);
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_ledger_failure_rolls_back_both_balance_changes(): void
    {
        $confirmed = $this->confirm($this->payload());
        DB::listen(function ($query) {
            if (strpos($query->sql,'insert into "wallets"') !== false) throw new \RuntimeException('Ledger unavailable');
        });
        $this->postJson('/api/transfer/wallet',$confirmed)->assertStatus(500);
        $this->assertEquals(500, DB::table('users')->where('id',1)->value('balance'));
        $this->assertEquals(0, DB::table('users')->where('id',10)->value('balance'));
        $this->assertSame(0, DB::table('wallets')->count());
        Notification::assertNothingSent();
    }
}
