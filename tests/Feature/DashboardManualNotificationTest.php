<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DashboardPushSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardManualNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = env('TAKEAWAY_TEST_CONNECTION', 'sqlite');
        if (!in_array($connection, ['sqlite','mysql'], true)) throw new \RuntimeException('Unsupported test database.');
        if ($connection === 'mysql' && config('database.connections.mysql.database') !== 'takeaway_test') throw new \RuntimeException('Dedicated takeaway_test database required.');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32)), 'database.default' => $connection,
            'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'services.fcm.project_id' => 'fasakhaninjatest']);
        DB::purge($connection); Schema::clearResolvedInstance('db.schema');
        if ($connection === 'mysql') {
            Schema::disableForeignKeyConstraints();
            foreach (['dashboard_push_devices','dashboard_push_campaigns','model_has_permissions','model_has_roles','role_has_permissions','permissions','roles','user_address','user_tokens','areas','users'] as $table) Schema::dropIfExists($table);
            Schema::enableForeignKeyConstraints();
        }
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('account_type'); $t->unsignedBigInteger('added_by')->nullable(); $t->timestamps();
        });
        Schema::create('user_tokens', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token'); $t->timestamps(); });
        Schema::create('areas', function (Blueprint $t) { $t->id(); });
        Schema::create('user_address', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('area_id'); });
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        require_once base_path('database/migrations/2026_10_06_160000_create_dashboard_push_campaigns.php');
        (new \CreateDashboardPushCampaigns())->up();
        (new \CreatePermissionTables())->up(); app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::create(['name' => 'fcm_notification-create', 'guard_name' => 'admin']);
        foreach ([1 => 'admin', 2 => 'admin', 10 => 'vendor', 11 => 'vendor', 20 => 'user', 21 => 'user', 22 => 'user', 30 => 'delegate'] as $id => $type) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Account '.$id, 'account_type' => $type, 'added_by' => $id === 20 ? 10 : ($id === 21 ? 11 : null)]);
            if ($id >= 20) DB::table('user_tokens')->insert(['user_id' => $id, 'token' => $this->token($id)]);
        }
        foreach ([1, 10] as $id) DB::table('model_has_permissions')->insert(['permission_id' => $permission->id, 'model_type' => User::class, 'model_id' => $id]);
        DB::table('areas')->insert([['id' => 100], ['id' => 200]]);
        DB::table('user_address')->insert([['user_id' => 20, 'area_id' => 100], ['user_id' => 21, 'area_id' => 200], ['user_id' => 30, 'area_id' => 100]]);
        $this->app->instance(DashboardPushSender::class, new class extends DashboardPushSender { protected function accessToken(float $timeout = 12): string { return 'fixture-oauth'; } });
        $this->fake(fn () => Http::response(['name' => 'projects/fasakhaninjatest/messages/provider-ack'], 200));
    }

    private function fake(callable $callback): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($callback);
    }

    private function token(int $id): string { return 'fixture-token-for-device-'.$id; }
    private function actor(int $id): User { return User::withoutGlobalScopes()->findOrFail($id); }
    private function input(array $changes = []): array
    {
        return array_replace(['account_type' => 'user', 'title' => 'إشعار إداري', 'body' => 'نص الإشعار', 'send_by' => '1', 'choose_user' => '1', 'user_id' => [20]], $changes);
    }

    public function test_guest_and_actor_without_manual_notice_permission_cannot_send(): void
    {
        $this->postJson('/admin/fcm_notifications', $this->input())->assertRedirect('/admin/login');
        $this->actingAs($this->actor(2), 'admin')->postJson('/admin/fcm_notifications', $this->input())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_selected_recipient_uses_actual_token_scalar_type_four_payload_and_provider_acknowledgement(): void
    {
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input())->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('accepted', 1)->assertJsonPath('failed', 0);
        Http::assertSent(function ($request) {
            $message = $request['message'];
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/fasakhaninjatest/messages:send'
                && $message['token'] === $this->token(20) && !isset($message['topic'])
                && $message['notification']['title'] === 'إشعار إداري' && $message['notification']['body'] === 'نص الإشعار'
                && $message['data']['notification_type'] === '4' && $message['data']['account_type'] === 'user'
                && count(array_filter($message['data'], 'is_string')) === count($message['data']);
        });
        $this->assertCount(1, Http::recorded());
    }

    public function test_zone_filters_account_type_and_ignores_hidden_specific_user_field(): void
    {
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input(['send_by' => '0', 'zone_id' => [100], 'user_id' => []]))
            ->assertOk()->assertJsonPath('accepted', 1);
        Http::assertSent(fn ($request) => $request['message']['token'] === $this->token(20));
        $this->assertCount(1, Http::recorded());
    }

    public function test_other_account_type_or_nonbranch_recipient_ids_are_rejected_before_provider_call(): void
    {
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input(['user_id' => [30]]))->assertStatus(422);
        $this->actingAs($this->actor(10), 'admin')->postJson('/admin/fcm_notifications', $this->input(['user_id' => [21]]))->assertStatus(422);
        Http::assertNothingSent();
        $this->postJson('/admin/fcm_notifications', $this->input(['choose_user' => '0', 'user_id' => []]))->assertOk()->assertJsonPath('accepted', 1);
        Http::assertSent(fn ($request) => $request['message']['token'] === $this->token(20));
        $this->assertCount(1, Http::recorded());
    }

    public function test_duplicate_device_tokens_are_sent_once_and_invalid_device_records_count_as_failures(): void
    {
        DB::table('user_tokens')->insert([['user_id' => 20, 'token' => $this->token(20)], ['user_id' => 20, 'token' => 'bad']]);
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input())->assertOk()
            ->assertJsonPath('accepted', 1)->assertJsonPath('failed', 0)->assertJsonPath('invalid', 1)->assertJsonPath('severity', 'warning');
        $this->assertCount(1, Http::recorded());
    }

    public function test_provider_refusal_returns_friendly_failure_without_false_success_or_provider_secret(): void
    {
        $this->fake(fn () => Http::response(['error' => ['message' => 'SECRET provider credentials', 'status' => 'PERMISSION_DENIED']], 403));
        $response = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input())
            ->assertStatus(502)->assertJsonPath('success', false)->assertJsonPath('accepted', 0)->assertJsonPath('failed', 1);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('fixture-oauth', $response->getContent());
    }

    public function test_connection_failure_does_not_escape_as_server_exception_or_claim_acceptance(): void
    {
        $this->fake(function () { throw new ConnectionException('SECRET provider token and account'); });
        $response = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input())
            ->assertStatus(502)->assertJsonPath('success', false)->assertJsonPath('accepted', 0)->assertJsonPath('failed', 1);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }

    public function test_mixed_provider_responses_report_actual_acceptance_and_failure_counts(): void
    {
        $this->fake(fn ($request) => $request['message']['token'] === $this->token(20)
            ? Http::response(['name' => 'projects/fasakhaninjatest/messages/ack'], 200)
            : Http::response(['error' => ['status' => 'UNREGISTERED']], 404));
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input(['user_id' => [20, 21]]))
            ->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('failed', 1);
        $this->assertCount(2, Http::recorded());
    }

    public function test_empty_tokens_and_success_status_without_message_name_never_claim_delivery(): void
    {
        DB::table('user_tokens')->where('user_id', 20)->update(['token' => 'bad']);
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input())->assertStatus(502)->assertJsonPath('accepted', 0);
        Http::assertNothingSent();
        DB::table('user_tokens')->where('user_id', 20)->update(['token' => $this->token(20)]);
        $this->fake(fn () => Http::response(['ok' => true], 200));
        $this->postJson('/admin/fcm_notifications', $this->input())->assertStatus(502)->assertJsonPath('accepted', 0)->assertJsonPath('failed', 1);
    }

    public function test_missing_credentials_and_oversized_multibyte_payload_fail_before_provider_request(): void
    {
        $this->app->instance(DashboardPushSender::class, new class extends DashboardPushSender {
            protected function accessToken(float $timeout = 12): string { throw new \RuntimeException('SECRET service account path'); }
        });
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->input())->assertStatus(502)->assertJsonPath('accepted', 0);
        $this->postJson('/admin/fcm_notifications', $this->input(['body' => str_repeat('😀', 1500)]))->assertStatus(502)
            ->assertJsonPath('accepted', 0)->assertJsonPath('message', trans('dashboard_push.too_large'));
        Http::assertNothingSent();
    }

    public function test_input_validation_rejects_nonscalar_text_invalid_account_type_and_missing_zone(): void
    {
        $this->actingAs($this->actor(1), 'admin');
        foreach ([['title' => ['unsafe']], ['account_type' => 'unscoped'], ['send_by' => '0', 'zone_id' => []]] as $changes) {
            $this->postJson('/admin/fcm_notifications', $this->input($changes))->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_total_budget_preserves_confirmed_sends_and_reports_unattempted_devices_separately(): void
    {
        $sender = new class extends DashboardPushSender {
            private array $times = [0, 0, 0, 25];
            protected function clock(): float { return array_shift($this->times) ?? 25; }
            protected function accessToken(float $timeout = 12): string { return 'fixture-oauth'; }
        };
        $result = $sender->send(array_map(fn ($id) => $this->token($id), range(100, 120)), 'Title', 'Body', 'user');
        $this->assertSame(10, $result['accepted']); $this->assertSame(10, $result['attempted']);
        $this->assertSame(0, $result['failed']); $this->assertSame(11, $result['not_sent']);
        $this->assertSame('time_budget', $result['reason']); $this->assertCount(10, Http::recorded());
    }

    public function test_budget_includes_authentication_and_never_starts_device_requests_after_deadline(): void
    {
        $sender = new class extends DashboardPushSender {
            private array $times = [0, 0, 25];
            protected function clock(): float { return array_shift($this->times) ?? 25; }
            protected function accessToken(float $timeout = 12): string { return 'fixture-oauth'; }
        };
        $result = $sender->send([$this->token(20)], 'Title', 'Body', 'user');
        $this->assertSame(0, $result['accepted']); $this->assertSame(0, $result['attempted']);
        $this->assertSame(0, $result['failed']); $this->assertSame(1, $result['not_sent']);
        Http::assertNothingSent();
    }

    public function test_permanent_provider_refusal_stops_remaining_chunks_without_claiming_they_were_attempted(): void
    {
        $this->fake(fn () => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403));
        $result = app(DashboardPushSender::class)->send(array_map(fn ($id) => $this->token($id), range(100, 120)), 'Title', 'Body', 'user');
        $this->assertSame(0, $result['accepted']); $this->assertSame(10, $result['attempted']);
        $this->assertSame(10, $result['failed']); $this->assertSame(11, $result['not_sent']);
        $this->assertCount(10, Http::recorded());
    }

    public function test_spa_html_accept_header_still_receives_actual_json_result(): void
    {
        $this->actingAs($this->actor(1), 'admin')->post('/admin/fcm_notifications', $this->input(),
            ['Accept' => 'text/html, application/json;q=0.9', 'X-Dashboard-SPA' => '1'])->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('severity', 'success')->assertJsonPath('accepted', 1);
    }

    public function test_device_specific_403_does_not_block_other_devices_or_later_chunks(): void
    {
        $this->fake(fn ($request) => $request['message']['token'] === $this->token(100)
            ? Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'SENDER_ID_MISMATCH']]]], 403)
            : Http::response(['name' => 'projects/fixture/messages/ack'], 200));
        $result = app(DashboardPushSender::class)->send(array_map(fn ($id) => $this->token($id), range(100, 120)), 'Title', 'Body', 'user');
        $this->assertSame(20, $result['accepted']); $this->assertSame(1, $result['failed']); $this->assertSame(0, $result['not_sent']);
    }

    public function test_one_connection_error_preserves_other_provider_acknowledgements(): void
    {
        $this->fake(function ($request) {
            if ($request['message']['token'] === $this->token(100)) throw new ConnectionException('private connection details');
            return Http::response(['name' => 'projects/fixture/messages/ack'], 200);
        });
        $result = app(DashboardPushSender::class)->send(array_map(fn ($id) => $this->token($id), range(100, 120)), 'Title', 'Body', 'user');
        $this->assertSame(20, $result['accepted']); $this->assertSame(1, $result['failed']);
        $this->assertSame('uncertain', $result['outcomes'][hash('sha256', $this->token(100))]['status']);
    }

    private function queued(array $changes = []): array
    {
        return $this->input(array_replace(['durable' => 1, 'request_key' => '09000000-0000-4000-8000-000000000001'], $changes));
    }

    public function test_durable_duplicate_request_and_status_do_not_send_and_changed_replay_is_rejected(): void
    {
        $this->actingAs($this->actor(1), 'admin');
        $first = $this->postJson('/admin/fcm_notifications', $this->queued())->assertStatus(202)->json('campaign');
        $this->postJson('/admin/fcm_notifications', $this->queued())->assertStatus(202)->assertJsonPath('campaign.id', $first['id']);
        $this->postJson('/admin/fcm_notifications', $this->queued(['body' => 'changed']))->assertStatus(409);
        $this->getJson('/admin/fcm_notifications/campaigns/'.$first['id'])->assertOk()->assertJsonPath('not_sent', 1);
        Http::assertNothingSent();
        $encrypted = DB::table('dashboard_push_devices')->value('token');
        $this->assertStringNotContainsString($this->token(20), $encrypted);
        $url = '/admin/fcm_notifications/campaigns/'.$first['id'].'/step';
        $this->postJson($url)->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('status', 'finished');
        $this->postJson($url)->assertOk()->assertJsonPath('accepted', 1);
        $this->assertCount(1, Http::recorded()); $this->assertNull(DB::table('dashboard_push_devices')->value('token'));
    }

    public function test_campaign_progress_and_processing_are_isolated_to_authorized_sender(): void
    {
        $id = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->queued())->json('campaign.id');
        $this->actingAs($this->actor(10), 'admin');
        $this->getJson('/admin/fcm_notifications/campaigns/'.$id)->assertNotFound();
        $this->postJson('/admin/fcm_notifications/campaigns/'.$id.'/step')->assertNotFound();
        $this->postJson('/admin/fcm_notifications/campaigns/'.$id.'/resume')->assertNotFound();
        $this->actingAs($this->actor(2), 'admin')->postJson('/admin/fcm_notifications/campaigns/'.$id.'/step')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_worker_completes_large_campaign_in_batches_without_browser_or_duplicates(): void
    {
        $rows = []; foreach (range(100, 4744) as $n) $rows[] = ['user_id' => 20, 'token' => $this->token($n)];
        foreach (array_chunk($rows, 100) as $chunk) DB::table('user_tokens')->insert($chunk);
        $id = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->queued())->assertStatus(202)->json('campaign.id');
        Http::assertNothingSent();
        for ($i = 0; $i < 4 && DB::table('dashboard_push_campaigns')->where('id', $id)->value('status') !== 'finished'; $i++) $this->artisan('dashboard-push:dispatch')->assertExitCode(0);
        $status = $this->getJson('/admin/fcm_notifications/campaigns/'.$id)->assertOk()->assertJsonPath('accepted', 4646)->assertJsonPath('not_sent', 0)->assertJsonPath('status', 'finished');
        $this->assertStringNotContainsString($this->token(100), $status->getContent());
        $this->artisan('dashboard-push:dispatch')->assertExitCode(0);
        $this->assertCount(4646, Http::recorded());
    }

    public function test_global_refusal_pauses_and_explicit_resume_only_sends_definitely_unaccepted_devices(): void
    {
        $id = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->queued(['user_id' => [20,21]]))->json('campaign.id');
        $this->fake(fn ($request) => $request['message']['token'] === $this->token(20)
            ? Http::response(['name' => 'projects/fixture/messages/ack'], 200)
            : Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'SECRET']], 403));
        $base = '/admin/fcm_notifications/campaigns/'.$id;
        $this->postJson($base.'/step')->assertOk()->assertJsonPath('status', 'paused')->assertJsonPath('accepted', 1)->assertJsonPath('not_sent', 1);
        $this->postJson($base.'/step')->assertOk(); $this->assertCount(2, Http::recorded());
        $this->fake(fn () => Http::response(['name' => 'projects/fixture/messages/new'], 200));
        $this->postJson($base.'/resume')->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson($base.'/step')->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('accepted', 2);
        $this->assertCount(1, Http::recorded()); Http::assertSent(fn ($r) => $r['message']['token'] === $this->token(21));
    }

    public function test_live_claim_blocks_duplicate_workers_and_expired_claim_is_never_resent(): void
    {
        $id = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->queued(['user_id' => [20,21]]))->json('campaign.id');
        $device = DB::table('dashboard_push_devices')->where('campaign_id', $id)->orderBy('id')->first();
        DB::table('dashboard_push_devices')->where('id', $device->id)->update(['status' => 'sending']);
        DB::table('dashboard_push_campaigns')->where('id', $id)->update(['status' => 'sending', 'claim' => '09000000-0000-4000-8000-000000000009', 'claimed_at' => now()]);
        $base = '/admin/fcm_notifications/campaigns/'.$id;
        $this->postJson($base.'/step')->assertOk()->assertJsonPath('sending', 1); Http::assertNothingSent();
        DB::table('dashboard_push_campaigns')->where('id', $id)->update(['claimed_at' => now()->subMinutes(3)]);
        $this->postJson($base.'/step')->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('uncertain', 1)->assertJsonPath('status', 'finished');
        $this->assertCount(1, Http::recorded());
    }

    public function test_revoked_sender_permission_pauses_background_send(): void
    {
        $id = $this->actingAs($this->actor(1), 'admin')->postJson('/admin/fcm_notifications', $this->queued())->json('campaign.id');
        DB::table('model_has_permissions')->where('model_id', 1)->delete();app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->artisan('dashboard-push:dispatch')->assertExitCode(0);
        $this->assertSame('authorization', DB::table('dashboard_push_campaigns')->where('id', $id)->value('reason')); Http::assertNothingSent();
    }

    public function test_manual_project_uses_service_account_project_and_explicit_override(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fcm-fixture');
        try {
            file_put_contents($file, json_encode(['project_id' => 'correct-fixture-project']));
            config(['firebase.credentials' => $file, 'services.fcm.project_id' => null]);
            $this->assertSame('correct-fixture-project', app(DashboardPushSender::class)->projectId());
            config(['services.fcm.project_id' => 'explicit-cross-project']);
            $this->assertSame('explicit-cross-project', app(DashboardPushSender::class)->projectId());
        } finally { unlink($file); }
    }
}
