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
            'cache.default' => 'array', 'partner_auth.mailer' => 'array', 'app.key' => '12345678901234567890123456789012']);
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
        Storage::fake('local');
        Storage::fake('pending_vendor');
        Storage::fake('public');
        require_once base_path('database/migrations/2026_09_27_180000_create_go_store_catalog.php');
        (new \CreateGoStoreCatalog())->up();
        require_once base_path('database/migrations/2026_10_07_180000_create_go_product_image_requests.php');
        (new \CreateGoProductImageRequests())->up();
        Schema::table('pending_vendors', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decline_reason')->nullable();
        });
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

    public function test_store_activation_and_recovery_keep_vendor_identity_in_go_scope(): void
    {
        $application = $this->application();
        $application->update(['profession_key' => 'store_owner']);
        $proof = $this->proof('activation');
        $this->postJson('/api/partner-applications/activate', ['mobile'=>'01012345678', 'email_verification_token'=>$proof,
            'password'=>'store-password', 'password_confirmation'=>'store-password'])->assertOk();
        $user = User::withoutGlobalScopes()->first();
        $this->assertSame('vendor', $user->account_type);
        $this->assertSame('go_partner', $user->app_scope);
        $this->assertTrue(\App\Services\GoStores\Catalog::isStore($user));
        // The same Partner login recognizes stores even on older clients sending delegate.
        $request = \Illuminate\Http\Request::create('/api/login','POST',[],[],[],['HTTP_X_APP_SCOPE'=>'go_partner']);
        app()->instance('request', $request);
        $signedIn = app(\App\Repositories\Api\AuthRepository::class)->login(['mobile'=>'1012345678', 'password'=>'store-password', 'account_type'=>'delegate']);
        $this->assertSame($user->id, $signedIn->id);
        $this->travel(61)->seconds();
        $proof = $this->proof('password_reset');
        $this->postJson('/api/partner-auth/password/reset', ['mobile'=>'01012345678', 'email_verification_token'=>$proof,
            'password'=>'updated-password', 'password_confirmation'=>'updated-password'])->assertOk();
        $this->assertTrue(Hash::check('updated-password', $user->fresh()->password));
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

    private function storeApplicationPayload(string $proof): array
    {
        return ['mobile'=>'01012345678','email'=>'partner@example.com','email_verification_token'=>$proof,
            'full_name'=>'صاحب متجر تجريبي','age'=>28,'profession_key'=>'store_owner','lat'=>30.04,'lng'=>31.23,
            'payment_method'=>'instapay','payment_identifier'=>'partner@instapay','work_radius_km'=>5,'terms_accepted'=>1,
            'photo'=>UploadedFile::fake()->image('portrait.jpg'), 'store_logo'=>UploadedFile::fake()->image('logo.png'),
            'product_images'=>[UploadedFile::fake()->image('rice.png')],
            'storefront'=>json_encode(['name'=>'متجر المدينة','kind'=>'supermarket','address'=>'شارع النيل القاهرة',
                'user_id'=>999,'commission_rate'=>0,'products'=>[['name'=>'أرز','unit'=>'كيلو','price'=>'80.50',
                    'price_cents'=>1,'user_id'=>999,'options'=>[['label'=>'نصف كيلو','price'=>'42.75']]]]], JSON_UNESCAPED_UNICODE)];
    }

    public function test_ai_signup_submits_sixty_products_with_only_logo_upload_and_is_idempotent(): void
    {
        $token = $this->uploadSession();
        $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token,
            'images'=>['logo'=>UploadedFile::fake()->image('logo.png')]])->assertOk();
        $payload = $this->stagedPayload($token, 60);
        $store = json_decode($payload['storefront'], true);
        $store['auto_images'] = true;
        $payload['storefront'] = json_encode($store);
        $id = $this->postJson('/api/partner-applications', $payload)->assertOk()->json('data.application_id');
        $this->assertSame(60, DB::table('go_product_image_requests')->count());
        $this->assertSame(0, DB::table('media')->where('collection_name', 'go_store_draft_product')->count());
        $review = app(\App\Services\GoStores\ApplicationCatalog::class)->review(PendingVendor::findOrFail($id));
        $this->assertCount(60, $review['products']);
        $this->assertSame('', $review['products'][0]['image_url']);
        $this->assertSame('pending', $review['products'][0]['image_status']);
        $this->postJson('/api/partner-applications', $payload)->assertOk()->assertJsonPath('data.application_id', $id);
        $this->assertSame(60, DB::table('go_product_image_requests')->count());
    }

    private function automaticApplication(): PendingVendor
    {
        $payload = $this->storeApplicationPayload($this->proof('application'));
        unset($payload['product_images']);
        $store = json_decode($payload['storefront'], true);
        $store['auto_images'] = true;
        $payload['storefront'] = json_encode($store);
        $this->postJson('/api/partner-applications', $payload)->assertOk();
        return PendingVendor::firstOrFail();
    }

    public function test_ai_result_attaches_after_approval_without_changing_name_price_or_options(): void
    {
        $application = $this->automaticApplication();
        $owner = User::create(['name'=>'Store', 'account_type'=>'vendor', 'app_scope'=>'go_partner', 'pending_vendor_id'=>$application->id]);
        DB::transaction(fn () => app(\App\Services\GoStores\ApplicationCatalog::class)->promote($application, $owner));
        $before = DB::table('go_store_products')->first();
        $this->assertSame('', app(\App\Services\GoStores\Catalog::class)->present($before)['image_url']);
        Storage::disk('public')->put('go-stores/ai/fixture.jpg', 'fixture');
        $search = \Mockery::mock(\App\Services\GoStores\ProductImageSearch::class);
        $search->shouldReceive('configured')->andReturn(true);
        $search->shouldReceive('find')->once()->with('أرز', 'supermarket')->andReturn([
            'path'=>'go-stores/ai/fixture.jpg', 'provenance'=>['unbranded'=>true],
        ]);
        app()->instance(\App\Services\GoStores\ProductImageSearch::class, $search);
        $this->assertSame(1, app(\App\Services\GoStores\AutomaticProductImages::class)->process(3));
        $after = DB::table('go_store_products')->first();
        $this->assertSame('go-stores/ai/fixture.jpg', $after->image_path);
        foreach (['name', 'price_cents', 'options', 'user_id'] as $field) $this->assertEquals($before->$field, $after->$field);
        $this->assertSame('ready', DB::table('go_product_image_requests')->value('status'));
        $this->assertSame(0, app(\App\Services\GoStores\AutomaticProductImages::class)->process(3));
    }

    public function test_ai_no_match_and_provider_failure_keep_products_and_do_not_attach_wrong_images(): void
    {
        $application = $this->automaticApplication();
        $search = \Mockery::mock(\App\Services\GoStores\ProductImageSearch::class);
        $search->shouldReceive('configured')->andReturn(true);
        $search->shouldReceive('find')->once()->andThrow(new \RuntimeException('provider outage'));
        app()->instance(\App\Services\GoStores\ProductImageSearch::class, $search);
        $service = app(\App\Services\GoStores\AutomaticProductImages::class);
        $this->assertSame(0, $service->process(1));
        $this->assertSame('pending', DB::table('go_product_image_requests')->value('status'));
        DB::table('go_product_image_requests')->update(['next_attempt_at'=>now()->subMinute()]);
        $search = \Mockery::mock(\App\Services\GoStores\ProductImageSearch::class);
        $search->shouldReceive('configured')->andReturn(true);
        $search->shouldReceive('find')->once()->andReturn(null);
        app()->instance(\App\Services\GoStores\ProductImageSearch::class, $search);
        $this->assertSame(1, $service->process(1));
        $this->assertSame('no_match', DB::table('go_product_image_requests')->value('status'));
        $this->assertSame('', DB::table('go_product_image_requests')->value('image_path'));
        $this->assertCount(1, app(\App\Services\GoStores\ApplicationCatalog::class)->review($application)['products']);
    }

    public function test_ai_does_not_overwrite_an_image_manually_changed_during_search(): void
    {
        $application = $this->automaticApplication();
        $owner = User::create(['name'=>'Store', 'account_type'=>'vendor', 'app_scope'=>'go_partner', 'pending_vendor_id'=>$application->id]);
        DB::transaction(fn () => app(\App\Services\GoStores\ApplicationCatalog::class)->promote($application, $owner));
        $search = \Mockery::mock(\App\Services\GoStores\ProductImageSearch::class);
        $search->shouldReceive('configured')->andReturn(true);
        $search->shouldReceive('find')->once()->andReturnUsing(function () {
            DB::table('go_store_products')->update(['image_path'=>'manual.jpg']);
            return ['path'=>'automatic.jpg', 'provenance'=>['unbranded'=>true]];
        });
        app()->instance(\App\Services\GoStores\ProductImageSearch::class, $search);
        app(\App\Services\GoStores\AutomaticProductImages::class)->process(1);
        $this->assertSame('manual.jpg', DB::table('go_store_products')->value('image_path'));
        $this->assertSame('superseded', DB::table('go_product_image_requests')->value('status'));
    }

    public function test_approval_during_ai_search_attaches_the_image_to_the_promoted_product(): void
    {
        $application = $this->automaticApplication();
        $owner = User::create(['name'=>'Store', 'account_type'=>'vendor', 'app_scope'=>'go_partner', 'pending_vendor_id'=>$application->id]);
        $search = \Mockery::mock(\App\Services\GoStores\ProductImageSearch::class);
        $search->shouldReceive('configured')->andReturn(true);
        $search->shouldReceive('find')->once()->andReturnUsing(function () use ($application, $owner) {
            DB::transaction(fn () => app(\App\Services\GoStores\ApplicationCatalog::class)->promote($application, $owner));
            return ['path'=>'go-stores/ai/approved-during-search.jpg', 'provenance'=>['unbranded'=>true]];
        });
        app()->instance(\App\Services\GoStores\ProductImageSearch::class, $search);
        app(\App\Services\GoStores\AutomaticProductImages::class)->process(1);
        $this->assertSame('go-stores/ai/approved-during-search.jpg', DB::table('go_store_products')->value('image_path'));
        $this->assertSame(1, DB::table('go_store_products')->count());
        $this->assertSame('ready', DB::table('go_product_image_requests')->value('status'));
    }

    public function test_store_application_review_approval_and_activation_keep_catalog_and_logo(): void
    {
        $this->postJson('/api/partner-applications', $this->storeApplicationPayload($this->proof('application')))->assertOk();
        $application = PendingVendor::firstOrFail();
        $catalog = app(\App\Services\GoStores\ApplicationCatalog::class);
        $review = $catalog->review($application);
        $this->assertSame('متجر المدينة', $review['name']);
        $this->assertSame(8050, $review['products'][0]['price_cents']);
        $this->assertSame(4275, $review['products'][0]['options'][0]['price_cents']);
        $this->assertNotEmpty($review['logo_url']);
        $this->assertSame(0, DB::table('go_stores')->count());
        $this->assertSame(0, User::withoutGlobalScopes()->count());
        $admin = User::create(['id'=>1,'name'=>'Admin','account_type'=>'admin','app_scope'=>'fasakhansta']);
        $this->actingAs($admin, 'admin');
        $this->post(route('pending_vendors.approvePartner', $application))->assertRedirect();
        $owner = User::withoutGlobalScopes()->where('app_scope','go_partner')->firstOrFail();
        $this->assertSame('pending', $owner->status);
        $this->assertSame('vendor', $owner->account_type);
        $this->assertSame(1, DB::table('go_store_products')->count());
        $this->assertSame($owner->id, (int) DB::table('go_store_products')->value('user_id'));
        $this->assertSame(8050, (int) DB::table('go_store_products')->value('price_cents'));
        $this->assertNotEmpty(app(\App\Services\GoStores\Catalog::class)->store($owner->id)['logo_url']);
        $this->assertSame('متجر المدينة', $catalog->review($application->fresh())['name']);
        $this->post(route('pending_vendors.approvePartner', $application))->assertRedirect();
        $this->assertSame(1, DB::table('go_store_products')->count());
        $this->postJson('/api/partner-applications/activate', ['mobile'=>'01012345678',
            'email_verification_token'=>$this->proof('activation'),'password'=>'store-password','password_confirmation'=>'store-password'])->assertOk();
        $this->assertSame('accepted', $owner->fresh()->status);
        $this->assertSame(1, DB::table('go_store_products')->count());
        $imagePath = DB::table('go_store_products')->value('image_path');
        $application->delete();
        Storage::disk('public')->assertExists($imagePath);
        $this->assertNotEmpty($owner->fresh()->getFirstMediaUrl('go_store_logo'));
    }

    public function test_invalid_store_draft_is_rejected_before_consuming_proof_or_creating_accounts(): void
    {
        $proof = $this->proof('application');
        $payload = $this->storeApplicationPayload($proof);
        $draft = json_decode($payload['storefront'], true);
        foreach ([['price'=>'-1'], ['price'=>'1.234'], ['options'=>[['label'=>'نصف','price'=>'10'],['label'=>'نصف','price'=>'20']]]] as $invalid) {
            $bad = $draft; $bad['products'][0] = array_replace($bad['products'][0], $invalid);
            $this->postJson('/api/partner-applications', array_replace($payload,['storefront'=>json_encode($bad)]))->assertStatus(422);
        }
        $this->postJson('/api/partner-applications', array_replace($payload,['product_images'=>[]]))->assertStatus(422);
        $this->postJson('/api/partner-applications', array_replace($payload,['profession_key'=>'plumber']))->assertStatus(422);
        $this->assertSame(0, PendingVendor::count());
        $this->assertSame(0, DB::table('media')->count());
        $this->postJson('/api/partner-applications', $payload)->assertOk();
        $this->assertSame(1, PendingVendor::count());
    }

    public function test_failed_store_upload_rolls_back_application_and_files(): void
    {
        app()->instance(\App\Services\GoStores\ApplicationCatalog::class, new class extends \App\Services\GoStores\ApplicationCatalog {
            public function capture(PendingVendor $application, \Illuminate\Http\Request $request, array $store): void {
                parent::capture($application, $request, $store);
                throw new \RuntimeException('upload fixture failure');
            }
        });
        $this->postJson('/api/partner-applications', $this->storeApplicationPayload($this->proof('application')))->assertStatus(500);
        $this->assertSame(0, PendingVendor::count());
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('pending_vendor')->allFiles());
    }

    private function uploadSession(): string
    {
        return $this->postJson('/api/partner-applications/catalog-upload', [
            'mobile'=>'01012345678', 'email'=>'partner@example.com',
            'email_verification_token'=>$this->proof('application'),
        ])->assertOk()->json('data.upload_token');
    }

    private function stagedPayload(string $token, int $count = 1): array
    {
        $payload = $this->storeApplicationPayload('unused');
        unset($payload['email_verification_token'], $payload['store_logo'], $payload['product_images']);
        $store = json_decode($payload['storefront'], true);
        $product = $store['products'][0];
        $store['products'] = [];
        for ($i = 0; $i < $count; $i++) $store['products'][] = array_replace($product, ['name'=>'منتج '.$i, 'price'=>($i + 1).'.50']);
        $payload['storefront'] = json_encode($store);
        return $payload + ['catalog_upload_token'=>$token];
    }

    public function test_sixty_products_upload_in_batches_and_transfer_after_approval_only(): void
    {
        $token = $this->uploadSession();
        $images = ['logo'=>UploadedFile::fake()->image('logo.png')];
        $hashes = [];
        for ($i = 0; $i < 60; $i++) {
            $file = UploadedFile::fake()->image('product.png');
            // More than the old 6 MB combined limit; each request remains small.
            $content = file_get_contents($file->getPathname()).str_repeat(chr(65 + $i % 26), 130000);
            $images['p'.$i] = UploadedFile::fake()->createWithContent('product.png', $content);
            $hashes[$i] = hash('sha256', $content);
        }
        foreach (array_chunk($images, 5, true) as $batch) {
            $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token, 'images'=>$batch])->assertOk();
        }
        $this->assertSame(0, PendingVendor::count());
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->postJson('/api/partner-applications', $this->stagedPayload($token, 61))->assertStatus(422);
        $id = $this->postJson('/api/partner-applications', $this->stagedPayload($token, 60))->assertOk()->json('data.application_id');
        $application = PendingVendor::findOrFail($id);
        $products = $application->getMedia('go_store_draft_product');
        $this->assertCount(60, $products);
        foreach ($products as $i => $media) {
            $this->assertSame('منتج '.$i, $media->getCustomProperty('name'));
            $this->assertSame(($i + 1) * 100 + 50, $media->getCustomProperty('price_cents'));
            $this->assertSame($hashes[$i], hash_file('sha256', $media->getPath()));
        }
        $this->assertCount(1, Storage::disk('local')->allFiles('go-store-signup')); // receipt only
        $this->postJson('/api/partner-applications', $this->stagedPayload($token, 60))->assertOk()->assertJsonPath('data.application_id', $id);
        $this->assertSame(1, PendingVendor::count());
        $this->assertSame(0, DB::table('go_store_products')->count());
        $admin = User::create(['id'=>1,'name'=>'Admin','account_type'=>'admin','app_scope'=>'fasakhansta']);
        $this->actingAs($admin, 'admin');
        $this->post(route('pending_vendors.approvePartner', $application))->assertRedirect();
        $this->assertSame(60, DB::table('go_store_products')->count());
        $this->post(route('pending_vendors.approvePartner', $application))->assertRedirect();
        $this->assertSame(60, DB::table('go_store_products')->count());
    }

    public function test_staged_uploads_enforce_identity_slots_batch_size_and_expiry(): void
    {
        $this->postJson('/api/partner-applications/catalog-upload', ['mobile'=>'01012345678','email'=>'partner@example.com',
            'email_verification_token'=>str_repeat('a',64)])->assertStatus(422);
        $token = $this->uploadSession();
        foreach (['p60', '../logo', 'anything'] as $slot) {
            $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token,
                'images'=>[$slot=>UploadedFile::fake()->image('bad.png')]])->assertStatus(422);
        }
        $batch = [];
        for ($i=0;$i<6;$i++) $batch['p'.$i] = UploadedFile::fake()->image('image.png');
        $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token,'images'=>$batch])->assertStatus(422);
        $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token,
            'images'=>['logo'=>UploadedFile::fake()->image('large.png')->size(1025)]])->assertStatus(422);
        foreach (['mobile'=>'01099999999','email'=>'other@example.com','profession_key'=>'plumber'] as $key=>$value) {
            $this->postJson('/api/partner-applications', array_replace($this->stagedPayload($token),[$key=>$value]))->assertStatus(422);
        }
        $this->postJson('/api/partner-applications', $this->stagedPayload($token))->assertStatus(422); // incomplete
        $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>str_repeat('z',64),
            'images'=>['logo'=>UploadedFile::fake()->image('logo.png')]])->assertStatus(422);
        $this->assertSame(0, PendingVendor::count());
        $this->travel(121)->minutes();
        $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token,
            'images'=>['logo'=>UploadedFile::fake()->image('logo.png')]])->assertStatus(422);
        $this->travel(61)->minutes();
        Storage::disk('public')->put('existing.png', 'keep');
        $this->assertSame(1, app(\App\Services\GoStores\ApplicationUploads::class)->prune());
        $this->assertSame([], Storage::disk('local')->allFiles('go-store-signup'));
        Storage::disk('public')->assertExists('existing.png');
    }

    public function test_staged_capture_failure_preserves_uploads_and_can_retry_without_new_proof(): void
    {
        $token = $this->uploadSession();
        $batch = ['catalog_upload_token'=>$token,'images'=>[
            'logo'=>UploadedFile::fake()->image('logo.png'), 'p0'=>UploadedFile::fake()->image('old.png')]];
        $this->postJson('/api/partner-applications/catalog-images', $batch)->assertOk();
        $this->postJson('/api/partner-applications/catalog-images', ['catalog_upload_token'=>$token,
            'images'=>['p0'=>UploadedFile::fake()->image('replacement.jpg')]])->assertOk();
        $this->assertCount(3, Storage::disk('local')->allFiles('go-store-signup')); // fixed slots + manifest
        app()->instance(\App\Services\GoStores\ApplicationCatalog::class, new class extends \App\Services\GoStores\ApplicationCatalog {
            public function capture(PendingVendor $application, \Illuminate\Http\Request $request, array $store): void {
                parent::capture($application, $request, $store);
                throw new \RuntimeException('upload fixture failure');
            }
        });
        $this->postJson('/api/partner-applications', $this->stagedPayload($token))->assertStatus(500);
        $this->assertSame(0, PendingVendor::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertCount(3, Storage::disk('local')->allFiles('go-store-signup'));
        app()->instance(\App\Services\GoStores\ApplicationCatalog::class, new \App\Services\GoStores\ApplicationCatalog());
        Cache::flush(); // release cache clear must not lose private upload manifests
        $this->postJson('/api/partner-applications', $this->stagedPayload($token))->assertOk();
        $this->assertSame(1, PendingVendor::count());
    }
}
