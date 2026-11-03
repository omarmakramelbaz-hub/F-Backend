<?php

namespace Tests\Feature;

use App\Http\Controllers\Payment\PaymobController;
use App\Models\User;
use App\Notifications\NotifyResturantStatusNotification;
use App\Services\Dashboard\BranchCustomers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The first refactor release: the slowest queries of the live server read their indexes instead of sorting whole
 * tables, and the errors that repeated in its logs are gone. What every page shows is unchanged.
 */
class RefactorFirstReleaseTest extends TestCase
{
    public function test_the_customers_page_lists_the_same_customers_in_the_same_order_without_sorting_the_directory(): void
    {
        Schema::create('crm_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->boolean('service_consent')->default(false);
        });
        $names = [null, 'سعيد', '', 'أحمد', 'Zeinab', null, 'ب', 'أحمد', '', 'adam', 'كريم', 'مها', null, 'ياسر', 'عمر', 'Adam', 'هاني', '',
            'نادر', 'سعيد', 'منى', 'حسن', 'آية', 'Omar', null, 'رنا', 'تامر', 'ثناء', 'جمال', 'خالد', 'دينا', 'ذكي', 'رامي', 'زياد', 'سامي'];
        foreach ($names as $index => $name) DB::table('crm_customers')->insert(['name' => $name, 'service_consent' => $index % 3 === 0]);
        $listing = (new \ReflectionClass(BranchCustomers::class))->newInstanceWithoutConstructor();
        $page = \Closure::bind(fn ($q, $offset, $limit) => $this->namedFirst($q, $offset, $limit), $listing, BranchCustomers::class);
        // The order the page used: customers without a name last, then by name and number.
        $before = fn ($q, $offset, $limit) => (clone $q)->orderByRaw("CASE WHEN name IS NULL OR name = '' THEN 1 ELSE 0 END")
            ->orderBy('name')->orderBy('id')->offset($offset)->limit($limit)->pluck('id')->all();
        foreach ([DB::table('crm_customers'), DB::table('crm_customers')->where('service_consent', true)] as $query) {
            $total = (clone $query)->count();
            foreach ([30, 7, 4] as $size) {
                for ($offset = 0; $offset <= $total + $size; $offset += $size) {
                    $this->assertSame($before($query, $offset, $size), $page($query, $offset, $size)->pluck('id')->all(), "offset $offset, $size a page");
                }
            }
        }
    }

    public function test_realtime_sign_in_reads_its_keys_from_the_configuration_and_signs_only_the_users_own_channel(): void
    {
        $user = new User();
        $user->id = 5;
        config(['broadcasting.connections.pusher.key' => 'live-key', 'broadcasting.connections.pusher.secret' => 'live-secret',
            'broadcasting.connections.pusher.app_id' => '1001', 'broadcasting.connections.pusher.options.cluster' => 'eu']);
        $signature = 'live-key:'.hash_hmac('sha256', '123.456:private-user.5', 'live-secret');
        $this->actingAs($user)->postJson('/pusher/auth', ['socket_id' => '123.456', 'channel_name' => 'private-user.5'])
            ->assertOk()->assertJson(['auth' => $signature]);
        // The apps' route sits behind their own token checks: its answer itself, for the same signed-in account.
        $api = fn () => app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/pusher/auth', 'POST'))->getAction('uses')(
            \Illuminate\Http\Request::create('/api/pusher/auth', 'POST', ['socket_id' => '123.456', 'channel_name' => 'private-user.5']));
        $this->actingAs($user);
        $this->assertSame(['auth' => $signature, 'channel_data' => ['user_id' => 5]], $api()->getData(true));
        $this->actingAs($user)->postJson('/pusher/auth', ['socket_id' => '123.456', 'channel_name' => 'private-user.6'])->assertStatus(403);
        // Without keys: a plain answer, never the error that filled the logs.
        config(['broadcasting.connections.pusher.key' => null]);
        $this->actingAs($user)->postJson('/pusher/auth', ['socket_id' => '123.456', 'channel_name' => 'private-user.5'])->assertStatus(503);
        $this->assertSame(503, $api()->getStatusCode());
    }

    public function test_card_payments_sign_in_to_paymob_with_the_configured_key(): void
    {
        config(['services.paymob.api_key' => 'paymob-key']);
        Http::fake(['accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'session-token']), '*' => Http::response([], 500)]);
        try {
            app(PaymobController::class)->checkingOut('card', 1, 999999, 2);
        } catch (\Throwable $error) {
            // The order itself is not part of this check.
        }
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/auth/tokens') && $request['api_key'] === 'paymob-key');
        foreach (['Api/V1/ShippingController.php', 'Api/V1/User/WalletController.php', 'Api/V1/User/CartController.php', 'Payment/PaymobController.php'] as $file) {
            $code = preg_replace('#^\s*//.*$#m', '', file_get_contents(app_path('Http/Controllers/'.$file)));
            $this->assertStringNotContainsString("env('PAYMOB", $code, $file.' reads Paymob through the configuration');
        }
    }

    public function test_a_restaurants_status_notice_is_built_without_a_signed_in_dashboard_account(): void
    {
        $restaurant = new User();
        $restaurant->setRelation('base_resturant', null);
        // The account notified, as the notice reads it (its type, and its devices' tokens: none here).
        $owner = (object) ['account_type' => 'resturant_owner', 'my_tokens' => []];
        $notice = (new NotifyResturantStatusNotification($restaurant))->toDatabase($owner);
        $this->assertStringStartsWith('المطعم حاليا غير متاح', $notice['text']);
        $this->assertSame('resturant_owner', $notice['data']['account_type']);
    }
}
