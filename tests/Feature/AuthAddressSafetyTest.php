<?php

namespace App\Http\Controllers\Api\V1\Auth {
    // Keep address ownership checks independent of the external geocoding service.
    function getCityName($latitude, $longitude)
    {
        return 'Test city';
    }
}

namespace Tests\Feature {
    use App\Models\User;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Schema;
    use Tests\TestCase;

    class AuthAddressSafetyTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'jwt.secret' => str_repeat('test-only-jwt-secret-', 4),
                'app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
            DB::purge('sqlite');
            Schema::clearResolvedInstance('db.schema');
            $this->withoutMiddleware();

            Schema::create('users', function (Blueprint $table) {
                $table->id();
                foreach (['name', 'email', 'mobile', 'country_code', 'password', 'account_type', 'app_scope', 'status', 'email_code'] as $field) {
                    $table->string($field)->nullable();
                }
                $table->boolean('forget_password')->default(false);
                $table->timestamps();
            });
            Schema::create('areas', function (Blueprint $table) {
                $table->id();
                $table->string('title_ar')->nullable();
                $table->string('title_en')->nullable();
            });
            Schema::create('user_address', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('area_id')->nullable();
                foreach (['area_name', 'apartment_no', 'floor_no', 'street_name', 'badge', 'address_name', 'mobile', 'type'] as $field) {
                    $table->string($field)->nullable();
                }
                $table->decimal('lat', 10, 6);
                $table->decimal('lng', 10, 6);
                $table->timestamps();
            });
            DB::table('users')->insert([
                ['id' => 1, 'name' => 'Customer one', 'email' => 'one@example.test', 'mobile' => '01012345678',
                    'account_type' => 'user', 'app_scope' => 'fasakhansta', 'status' => 'accepted', 'password' => Hash::make('old-password')],
                ['id' => 2, 'name' => 'Customer two', 'email' => 'two@example.test', 'mobile' => '01087654321',
                    'account_type' => 'user', 'app_scope' => 'fasakhansta', 'status' => 'accepted', 'password' => Hash::make('other-password')],
            ]);
            $this->actingAs(User::withoutGlobalScopes()->findOrFail(1), 'api');
        }

        private function addressPayload(): array
        {
            return ['area_name' => 'Test area', 'apartment_no' => '1', 'street_name' => 'Test street',
                'mobile' => '01012345678', 'type' => 'home', 'lat' => 30.1, 'lng' => 31.2];
        }

        public function test_create_ignores_supplied_owner_and_primary_key(): void
        {
            $this->postJson('/api/user/store/address', $this->addressPayload() + ['user_id' => 2, 'id' => 999])
                ->assertOk()->assertJsonPath('status', 'Success');
            $address = DB::table('user_address')->first();
            $this->assertSame(1, (int) $address->user_id);
            $this->assertNotSame(999, (int) $address->id);
            $this->assertSame(0, DB::table('user_address')->where('user_id', 2)->count());
        }

        public function test_update_cannot_transfer_ownership_or_change_primary_key(): void
        {
            $id = DB::table('user_address')->insertGetId($this->addressPayload() + ['user_id' => 1]);
            $this->postJson('/api/user/update/address/'.$id,
                array_replace($this->addressPayload(), ['street_name' => 'Updated street', 'user_id' => 2, 'id' => 999]))
                ->assertOk()->assertJsonPath('status', 'Success');
            $address = DB::table('user_address')->where('id', $id)->first();
            $this->assertNotNull($address);
            $this->assertSame(1, (int) $address->user_id);
            $this->assertSame('Updated street', $address->street_name);
            $this->assertFalse(DB::table('user_address')->where('id', 999)->exists());
        }

        public function test_another_customers_address_cannot_be_updated(): void
        {
            $id = DB::table('user_address')->insertGetId($this->addressPayload() + ['user_id' => 2]);
            $this->postJson('/api/user/update/address/'.$id,
                array_replace($this->addressPayload(), ['street_name' => 'Unauthorized change']))
                ->assertJsonPath('status', 'Error');
            $this->assertSame('Test street', DB::table('user_address')->where('id', $id)->value('street_name'));
        }

        public function test_new_reset_code_is_not_exposed_and_revokes_previous_reset_permission(): void
        {
            DB::table('users')->where('id', 1)->update(['forget_password' => 1]);
            Mail::shouldReceive('send')->once()->andReturn(null);
            $this->postJson('/api/user/forget_pass', ['email' => 'one@example.test', 'mobile' => '01012345678'])
                ->assertOk()->assertJsonPath('status', 'Success')->assertJsonPath('data', null);
            $user = DB::table('users')->where('id', 1)->first();
            $this->assertNotEmpty($user->email_code);
            $this->assertSame(0, (int) $user->forget_password);
        }

        public function test_successful_reset_consumes_permission_and_hashes_password(): void
        {
            DB::table('users')->where('id', 1)->update(['email_code' => '4321']);
            $verification = ['email' => 'one@example.test', 'code' => '4321'];
            $this->postJson('/api/user/check_code', $verification)
                ->assertOk()->assertJsonPath('status', 'Success');
            $this->assertNull(DB::table('users')->where('id', 1)->value('email_code'));
            $payload = ['email' => 'one@example.test', 'mobile' => '01012345678',
                'new_password' => 'new-password', 'confirm_password' => 'new-password'];
            $this->postJson('/api/user/reset_password', $payload)->assertOk()->assertJsonPath('status', 'Success');
            $user = DB::table('users')->where('id', 1)->first();
            $this->assertTrue(Hash::check('new-password', $user->password));
            $this->assertSame(0, (int) $user->forget_password);
            $this->assertNull($user->email_code);
            $this->postJson('/api/user/check_code', $verification)->assertJsonPath('status', 'Error');
            $this->assertSame(0, (int) DB::table('users')->where('id', 1)->value('forget_password'));
            $this->postJson('/api/user/reset_password', array_replace($payload,
                ['new_password' => 'replayed-password', 'confirm_password' => 'replayed-password']))
                ->assertJsonPath('status', 'Error');
            $this->assertSame($user->password, DB::table('users')->where('id', 1)->value('password'));
        }

        public function test_missing_or_invalid_verification_code_never_grants_reset_permission(): void
        {
            // A missing code previously selected users whose stored code was NULL.
            foreach ([[], ['code' => null], ['code' => ''], ['code' => '123'],
                ['code' => '12345'], ['code' => 'abcd']] as $code) {
                $this->postJson('/api/user/check_code', $code + ['email' => 'one@example.test'])
                    ->assertJsonPath('status', 'Error');
                $this->assertSame(0, (int) DB::table('users')->where('id', 1)->value('forget_password'));
            }
        }

        public function test_verification_requires_a_valid_email_and_preserves_unverified_code(): void
        {
            DB::table('users')->where('id', 1)->update(['email_code' => '4321']);
            foreach ([[], ['email' => null], ['email' => ''], ['email' => 'not-an-email']] as $email) {
                $this->postJson('/api/user/check_code', $email + ['code' => '4321'])
                    ->assertJsonPath('status', 'Error');
            }
            $this->assertSame('4321', DB::table('users')->where('id', 1)->value('email_code'));
            $this->assertSame(0, (int) DB::table('users')->where('id', 1)->value('forget_password'));
        }

        public function test_requesting_a_go_code_does_not_change_the_fasakhansta_account(): void
        {
            DB::table('users')->where('id', 1)->update(['email_code' => '4321', 'forget_password' => 1]);
            DB::table('users')->insert(['id' => 3, 'name' => 'GO customer', 'email' => 'one@example.test',
                'mobile' => '01012345678', 'account_type' => 'user', 'app_scope' => 'go',
                'status' => 'accepted', 'password' => Hash::make('go-password'), 'forget_password' => 1]);
            Mail::shouldReceive('send')->once()->andReturn(null);
            $this->withHeader('X-App-Scope', 'go')
                ->postJson('/api/user/forget_pass', ['email' => 'one@example.test', 'mobile' => '01012345678'])
                ->assertOk()->assertJsonPath('data', null);
            $fasakhansta = DB::table('users')->where('id', 1)->first();
            $go = DB::table('users')->where('id', 3)->first();
            $this->assertSame('4321', $fasakhansta->email_code);
            $this->assertSame(1, (int) $fasakhansta->forget_password);
            $this->assertNotEmpty($go->email_code);
            $this->assertSame(0, (int) $go->forget_password);
        }

        public function test_verification_and_reset_remain_isolated_between_go_and_fasakhansta(): void
        {
            DB::table('users')->where('id', 1)->update(['email_code' => '4321']);
            DB::table('users')->insert(['id' => 3, 'name' => 'GO customer', 'email' => 'one@example.test',
                'mobile' => '01012345678', 'account_type' => 'user', 'app_scope' => 'go',
                'status' => 'accepted', 'password' => Hash::make('go-password'), 'email_code' => '8765']);
            $this->withHeader('X-App-Scope', 'go')
                ->postJson('/api/user/check_code', ['email' => 'one@example.test', 'code' => '4321'])
                ->assertJsonPath('status', 'Error');
            $this->postJson('/api/user/check_code', ['email' => 'one@example.test', 'code' => '8765'])
                ->assertOk()->assertJsonPath('status', 'Success');
            $payload = ['email' => 'one@example.test', 'mobile' => '01012345678',
                'new_password' => 'changed-go-password', 'confirm_password' => 'changed-go-password'];
            $this->withHeader('X-App-Scope', 'fasakhansta')->postJson('/api/user/reset_password', $payload)
                ->assertJsonPath('status', 'Error');
            $this->assertSame(1, (int) DB::table('users')->where('id', 3)->value('forget_password'));
            $this->withHeader('X-App-Scope', 'go')->postJson('/api/user/reset_password', $payload)
                ->assertOk()->assertJsonPath('status', 'Success');
            $this->assertTrue(Hash::check('changed-go-password', DB::table('users')->where('id', 3)->value('password')));
            $this->assertTrue(Hash::check('old-password', DB::table('users')->where('id', 1)->value('password')));
            $this->assertSame('4321', DB::table('users')->where('id', 1)->value('email_code'));
            $this->assertSame(0, (int) DB::table('users')->where('id', 1)->value('forget_password'));
        }
    }
}
