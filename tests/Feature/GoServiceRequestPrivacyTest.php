<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\GoServiceMarketplaceController;
use App\Models\User;
use App\Services\GoServices\Marketplace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoServiceRequestPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'go_services.enabled' => true]);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        Notification::fake();
        Http::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'status', 'account_type', 'app_scope', 'connected', 'mobile', 'email'] as $field) $t->string($field);
            $t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->decimal('delegate_fees', 6, 2)->nullable();
            $t->decimal('balance', 14, 2);
        });
        Schema::create('pending_vendors', function (Blueprint $t) {
            $t->id();
            foreach (['application_kind', 'status', 'profession_key'] as $field) $t->string($field);
            $t->decimal('lat', 10, 7); $t->decimal('lng', 11, 7); $t->integer('work_radius_km');
        });
        require_once base_path('database/migrations/2026_09_26_090000_create_go_service_marketplace.php');
        (new \CreateGoServiceMarketplace())->up();
        $user = ['id' => 1, 'name' => 'Privacy fixture', 'status' => 'accepted', 'account_type' => 'user',
            'app_scope' => 'go', 'connected' => 'active', 'mobile' => '01012345678',
            'email' => 'privacy@example.test', 'balance' => '100.00'];
        DB::table('users')->insert($user);
        auth('api')->setUser((new User())->forceFill($user));
    }

    private function submit(array $extra = []): array
    {
        $payload = array_merge(['request_key' => 'privacy-request-0001', 'profession_key' => 'plumber',
            'description' => 'Repair the kitchen sink pipe', 'address' => 'Building 4, apartment 3', 'lat' => 30, 'lng' => 31], $extra);
        $request = Request::create('/api/go-services/jobs', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
        return (new GoServiceMarketplaceController(new Marketplace()))->store($request)->getData(true)['data'];
    }

    public function test_request_succeeds_with_address_and_map_without_phone_or_district(): void
    {
        $job = $this->submit();
        $row = DB::table('go_service_jobs')->find($job['id']);
        $this->assertSame('Building 4, apartment 3', $row->address);
        $this->assertSame('', $row->phone);
        $this->assertSame('', $row->area);
        $this->assertNull($job['phone']);
        $this->assertSame($job['id'], $this->submit()['id']);
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_legacy_fields_are_ignored_and_legacy_phone_never_returned(): void
    {
        $legacy = ['phone' => '01099999999', 'area' => 'Legacy district'];
        $job = $this->submit($legacy);
        $row = DB::table('go_service_jobs')->find($job['id']);
        $this->assertSame('', $row->phone);
        $this->assertSame('', $row->area);
        DB::table('go_service_jobs')->where('id', $job['id'])->update(['phone' => $legacy['phone']]);
        $retried = $this->submit($legacy);
        $this->assertSame($job['id'], $retried['id']);
        $this->assertNull($retried['phone']);
        $this->assertSame(1, DB::table('go_service_jobs')->count());
    }

    public function test_exact_address_remains_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->submit(['address' => '']);
    }
}
