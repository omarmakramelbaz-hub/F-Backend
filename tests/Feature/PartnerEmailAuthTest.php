<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureGoSchema;
use App\Mail\PartnerVerificationCode;
use App\Models\PendingVendor;
use App\Models\User;
use App\Services\PartnerEmailVerification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PartnerEmailAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'partner_auth.mailer' => 'array', 'app.key' => 'partner-email-tests-only-key']);
        DB::purge('sqlite');
        Cache::flush();
        Mail::fake();
        $this->withoutMiddleware([EnsureGoSchema::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'email', 'mobile', 'password', 'account_type', 'app_scope', 'status', 'partner_auth_email', 'remember_token'] as $field) $table->string($field)->nullable();
            $table->unsignedBigInteger('pending_vendor_id')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pending_vendors', function (Blueprint $table) {
            $table->id();
            foreach (['full_name', 'email', 'mobile', 'status', 'profession_key', 'application_kind'] as $field) $table->string($field)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('partner_activated_at')->nullable();
            $table->timestamps();
        });
        Schema::table('pending_vendors', function (Blueprint $table) {
            foreach (['age', 'added_by', 'work_radius_km'] as $field) $table->unsignedInteger($field)->nullable();
            foreach (['lat', 'lng', 'location', 'vodafone_cash_mobile', 'payment_method', 'payment_identifier', 'type'] as $field) $table->string($field)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
        });
        require_once base_path('vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub');
        (new \CreateMediaTable())->up();
        Storage::fake('pending_vendor');
    }

    private function issue(string $purpose = 'application', string $mobile = '01012345678', ?string $email = 'partner@example.com'): array
    {
        return $this->postJson('/api/partner-auth/email/request', compact('purpose', 'mobile', 'email'))->assertOk()->json('data');
    }

    private function lastCode(): string
    {
        return Mail::sent(PartnerVerificationCode::class)->last()->code;
    }

    private function proof(string $purpose, string $mobile = '01012345678'): string
    {
        $challenge = $this->issue($purpose, $mobile);
        return $this->postJson('/api/partner-auth/email/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $this->lastCode()])
            ->assertOk()->json('data.email_verification_token');
    }

    private function application(string $status = 'accepted'): PendingVendor
    {
        return PendingVendor::create(['full_name' => 'شريك تجريبي', 'mobile' => '1012345678', 'email' => 'owner@example.com',
            'email_verified_at' => now(), 'status' => $status, 'application_kind' => 'partner', 'profession_key' => 'plumber']);
    }

    public function test_codes_are_six_digits_hidden_from_api_and_single_use(): void
    {
        $challenge = $this->issue();
        $code = $this->lastCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertArrayNotHasKey('code', $challenge);
        $payload = ['challenge_id' => $challenge['challenge_id'], 'code' => $code];
        $this->postJson('/api/partner-auth/email/verify', $payload)->assertOk();
        $this->postJson('/api/partner-auth/email/verify', $payload)->assertStatus(422);
    }

    public function test_resend_cooldown_expiry_and_attempt_limit(): void
    {
        $challenge = $this->issue();
        $correct = $this->lastCode();
        $this->postJson('/api/partner-auth/email/request', ['purpose' => 'application', 'mobile' => '01012345678', 'email' => 'partner@example.com'])->assertStatus(429);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/partner-auth/email/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/partner-auth/email/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $correct])->assertStatus(422);
        $this->travel(61)->seconds();
        $new = $this->issue();
        $this->postJson('/api/partner-auth/email/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $correct])->assertStatus(422);
        $this->travel(11)->minutes();
        $this->postJson('/api/partner-auth/email/verify', ['challenge_id' => $new['challenge_id'], 'code' => $this->lastCode()])->assertStatus(422);
    }

    public function test_proof_is_bound_to_purpose_mobile_and_used_once(): void
    {
        $proof = $this->proof('application');
        $service = app(PartnerEmailVerification::class);
        foreach ([['activation', '01012345678'], ['application', '01099999999']] as $binding) {
            try { $service->consume($proof, $binding[0], $binding[1], function () { $this->fail('Wrong binding accepted'); }); $this->fail('Expected rejection'); }
            catch (ValidationException $expected) { $this->assertNotEmpty($expected->errors()); }
        }
        $this->assertSame('partner@example.com', $service->consume($proof, 'application', '+201012345678', function ($record) { return $record['email']; }));
        $this->expectException(ValidationException::class);
        $service->consume($proof, 'application', '01012345678', function () {});
    }

    public function test_application_and_activation_cannot_bypass_email_verification(): void
    {
        $this->application();
        $this->postJson('/api/partner-applications', ['mobile' => '01012345678'])->assertStatus(422);
        $this->postJson('/api/partner-applications/activate', ['mobile' => '01012345678', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertStatus(422);
        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    public function test_verified_application_persists_email_and_photo_but_still_requires_approval(): void
    {
        $proof = $this->proof('application');
        $this->postJson('/api/partner-applications', [
            'mobile' => '01012345678', 'email' => 'partner@example.com', 'email_verification_token' => $proof,
            'full_name' => 'شريك تجريبي', 'age' => 28, 'profession_key' => 'plumber', 'lat' => 30.04, 'lng' => 31.23,
            'payment_method' => 'instapay', 'payment_identifier' => 'partner@instapay', 'work_radius_km' => 5,
            'terms_accepted' => 1, 'photo' => UploadedFile::fake()->image('portrait.jpg'),
        ])->assertSuccessful();
        $application = PendingVendor::first();
        $this->assertSame('partner@example.com', $application->email);
        $this->assertNotNull($application->email_verified_at);
        $this->assertSame('pending', $application->status);
        $this->assertSame(1, $application->getMedia('partner_photo')->count());
        $this->assertSame(0, User::withoutGlobalScopes()->count());
        $this->issue('activation');
        $this->assertSame(1, Mail::sent(PartnerVerificationCode::class)->count());
    }

    public function test_expired_proof_and_invalid_phone_are_rejected(): void
    {
        $this->postJson('/api/partner-auth/email/request', ['purpose' => 'application', 'mobile' => '00000000000', 'email' => 'partner@example.com'])->assertStatus(422);
        $proof = $this->proof('application');
        $this->travel(11)->minutes();
        $this->expectException(ValidationException::class);
        app(PartnerEmailVerification::class)->consume($proof, 'application', '01012345678', function () { $this->fail('Expired proof used'); });
    }

    public function test_activation_uses_registered_email_and_cannot_be_replayed(): void
    {
        $application = $this->application();
        $proof = $this->proof('activation'); // request contains a different email: server must ignore it
        Mail::assertSent(PartnerVerificationCode::class, function ($mail) { return $mail->hasTo('owner@example.com'); });
        $body = ['mobile' => '01012345678', 'email_verification_token' => $proof, 'password' => 'test-password', 'password_confirmation' => 'test-password'];
        $this->postJson('/api/partner-applications/activate', $body)->assertOk();
        $user = User::withoutGlobalScopes()->first();
        $this->assertSame('owner@example.com', $user->partner_auth_email);
        $this->assertTrue(Hash::check('test-password', $user->password));
        $this->assertNotNull($application->fresh()->partner_activated_at);
        $this->postJson('/api/partner-applications/activate', $body)->assertStatus(422);
    }

    public function test_pending_or_unverified_applications_receive_no_activation_code(): void
    {
        $this->application('pending');
        $this->issue('activation');
        Mail::assertNothingSent();
    }

    public function test_recovery_uses_verified_mailbox_and_only_changes_go_partner_account(): void
    {
        $user = new User();
        $user->forceFill(['name' => 'Partner', 'mobile' => '1012345678', 'email' => 'editable@example.com',
            'partner_auth_email' => 'verified@example.com', 'password' => 'old-password', 'app_scope' => 'go_partner',
            'account_type' => 'delegate', 'status' => 'accepted'])->save();
        $other = User::create(['mobile' => '1012345678', 'password' => 'other-password', 'app_scope' => 'fasakhansta', 'account_type' => 'delegate', 'status' => 'accepted']);
        $proof = $this->proof('password_reset');
        Mail::assertSent(PartnerVerificationCode::class, function ($mail) { return $mail->hasTo('verified@example.com'); });
        $body = ['mobile' => '01012345678', 'email_verification_token' => $proof, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/partner-auth/password/reset', $body)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertTrue(Hash::check('other-password', $other->fresh()->password));
        $this->postJson('/api/partner-auth/password/reset', $body)->assertStatus(422);
    }

    public function test_unknown_account_does_not_disclose_a_destination_or_send_mail(): void
    {
        $challenge = $this->issue('password_reset');
        $this->assertArrayNotHasKey('email', $challenge);
        Mail::assertNothingSent();
        $this->postJson('/api/partner-auth/email/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'])->assertStatus(422);
    }

    public function test_mail_failure_does_not_report_delivery(): void
    {
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/partner-auth/email/request', ['purpose' => 'application', 'mobile' => '01012345678', 'email' => 'partner@example.com'])->assertStatus(503);
    }
}
