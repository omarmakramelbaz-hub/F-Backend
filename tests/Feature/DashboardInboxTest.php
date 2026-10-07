<?php

namespace Tests\Feature;

use App\Http\Controllers\Dashboard\FcmNotificationsController;
use App\Models\User;
use App\Services\Dashboard\SupportFirestore;
use App\Services\Dashboard\SupportInbox;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardInboxTest extends TestCase
{
    private InboxFirestoreFixture $firestore;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('i', 32)), 'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'services.fcm.project_id' => 'fasakhaninjatest']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Africa/Cairo'));
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('account_type'); $t->string('mobile')->nullable();
            $t->unsignedBigInteger('added_by')->nullable(); $t->timestamps();
        });
        require_once base_path('database/migrations/2022_08_17_095029_create_notifications_table.php');
        (new \CreateNotificationsTable())->up();
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::create(['name' => 'support_contact-list', 'guard_name' => 'admin']);
        foreach ([1 => 'admin', 2 => 'admin', 3 => 'admin', 10 => 'vendor', 11 => 'vendor', 20 => 'user', 21 => 'user', 635 => 'admin'] as $id => $type) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Account '.$id, 'account_type' => $type, 'mobile' => '0100000'.$id]);
        }
        foreach ([3, 10, 11, 635] as $id) DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id, 'model_type' => User::class, 'model_id' => $id,
        ]);
        $this->firestore = new InboxFirestoreFixture();
        $this->app->instance(SupportFirestore::class, $this->firestore);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); parent::tearDown();
    }

    private function actor(int $id): User
    {
        return User::withoutGlobalScopes()->findOrFail($id);
    }

    private function note(string $id, int $owner, string $morph = User::class, ?string $read = null): void
    {
        DB::table('notifications')->insert(['id' => $id, 'type' => 'Fixture', 'notifiable_type' => $morph,
            'notifiable_id' => $owner, 'data' => json_encode(['title' => '<script>Untrusted notification</script>', 'text' => 'History text']),
            'read_at' => $read, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guest_cannot_read_notifications_or_support(): void
    {
        $this->note('mine', 1);
        $this->getJson('/admin/dashboard-inbox/notifications')->assertRedirect('/admin/login');
        $this->postJson('/admin/dashboard-inbox/notifications/read', ['ids' => ['mine']])->assertRedirect('/admin/login');
        $this->getJson('/admin/dashboard-inbox/support')->assertRedirect('/admin/login');
        $this->postJson('/admin/send_chat_notification', ['user2' => 20, 'message' => 'Forged'])->assertRedirect('/admin/login');
        $this->assertNull(DB::table('notifications')->where('id', 'mine')->value('read_at'));
    }

    public function test_notification_read_snapshot_preserves_new_arrivals_foreign_owner_morph_and_history(): void
    {
        $this->note('mine-a', 10); $this->note('mine-b', 10); $this->note('foreign', 11);
        $this->note('foreign-morph', 10, 'App\\Models\\OtherRecipient');
        $this->actingAs($this->actor(10), 'admin');
        $snapshot = $this->getJson('/admin/dashboard-inbox/notifications')->assertOk()->assertJsonPath('count', 2)->json('notifications');
        $this->note('new-arrival', 10);
        $this->postJson('/admin/dashboard-inbox/notifications/read', ['ids' => array_merge(array_column($snapshot, 'id'), ['foreign', 'foreign-morph']), 'user_id' => 11])
            ->assertOk()->assertJsonPath('marked', 2)->assertJsonPath('count', 1);
        $this->assertSame(5, DB::table('notifications')->count());
        $this->assertNotNull(DB::table('notifications')->where('id', 'mine-a')->value('read_at'));
        foreach (['new-arrival', 'foreign', 'foreign-morph'] as $id) $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertSame('History text', json_decode(DB::table('notifications')->where('id', 'mine-a')->value('data'), true)['text']);
        $this->postJson('/admin/dashboard-inbox/notifications/read', ['ids' => ['mine-a']])->assertOk()->assertJsonPath('marked', 0);
    }

    public function test_notification_read_validation_and_old_read_endpoints_keep_scope_and_csrf_method(): void
    {
        $this->note('foreign', 11); $this->note('mine', 10);
        $this->actingAs($this->actor(10), 'admin');
        $this->postJson('/admin/dashboard-inbox/notifications/read', ['ids' => 'all'])->assertStatus(422);
        $this->putJson('/admin/read/foreign')->assertNotFound();
        $this->getJson('/admin/read/all/notification')->assertStatus(405);
        $this->post('/admin/read/all/notification')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', 'mine')->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', 'foreign')->value('read_at'));
    }

    public function test_support_requires_permission_even_for_other_administrators(): void
    {
        $this->actingAs($this->actor(2), 'admin');
        $this->getJson('/admin/dashboard-inbox/support')->assertForbidden();
        $this->getJson('/admin/dashboard-inbox/support/20/messages')->assertForbidden();
        $this->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => ['one']])->assertForbidden();
        $this->postJson('/admin/dashboard-inbox/support/20/messages', ['message' => 'Forged'])->assertForbidden();
        $this->postJson('/admin/send_chat_notification', ['user2' => 20, 'message' => 'Forged'])->assertForbidden();
        $this->assertSame([], $this->firestore->writes);
    }

    public function test_support_count_excludes_outgoing_staff_replies_read_messages_other_users_and_other_rooms(): void
    {
        $this->firestore->add('room_1_20', 'incoming', 20, 1);
        $this->firestore->add('room_1_20', 'reply', 1, 20);
        $this->firestore->add('room_1_20', 'already-read', 20, 1, true);
        $this->firestore->add('room_1_20', 'wrong-sender', 21, 1);
        $this->firestore->add('room_1_2', 'admin-message', 2, 1);
        $this->firestore->add('room_11_21', 'other-room', 21, 11);
        $this->actingAs($this->actor(1), 'admin')->getJson('/admin/dashboard-inbox/support')
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('conversations.20', 1)->assertJsonPath('threads.1:20', 1);
        $this->assertSame(['room_1_20'], array_values(array_unique($this->firestore->messageCalls)));
    }

    public function test_staff_keeps_own_635_history_and_central_app_inbox_without_accessing_another_staff_inbox(): void
    {
        $this->firestore->add('room_20_635', 'own', 20, 635);
        $this->firestore->add('room_1_21', 'central', 21, 1);
        $this->firestore->add('room_2_20', 'private-other-admin', 20, 2);
        $this->actingAs($this->actor(635), 'admin');
        $this->getJson('/admin/dashboard-inbox/support')->assertOk()->assertJsonPath('count', 2)
            ->assertJsonPath('conversations.20', 1)->assertJsonPath('conversations.21', 1);
        $this->getJson('/admin/dashboard-inbox/support/20/messages')->assertOk()->assertJsonPath('room', 'room_20_635')->assertJsonPath('messages.0.id', 'own');
        $this->getJson('/admin/dashboard-inbox/support/21/messages?inbox_id=1')->assertOk()->assertJsonPath('room', 'room_1_21');
        $this->getJson('/admin/dashboard-inbox/support/20/messages?inbox_id=2')->assertForbidden();
        $this->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => ['private-other-admin'], 'inbox_id' => 2])->assertForbidden();
        $this->getJson('/admin/dashboard-inbox/support/2/messages')->assertForbidden();
    }

    public function test_branch_only_its_own_support_conversation_counts_incoming_support_reply(): void
    {
        $this->firestore->add('room_1_10', 'reply', 1, 10);
        $this->firestore->add('room_1_10', 'outgoing', 10, 1);
        $this->firestore->add('room_1_11', 'other-branch', 1, 11);
        $this->actingAs($this->actor(10), 'admin');
        $this->getJson('/admin/dashboard-inbox/support')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/admin/dashboard-inbox/support/1/messages')->assertOk()->assertJsonPath('room', 'room_1_10');
        $this->getJson('/admin/dashboard-inbox/support/21/messages')->assertForbidden();
        $this->getJson('/admin/dashboard-inbox/support/11/messages')->assertForbidden();
        $this->getJson('/admin/dashboard-inbox/support/20/messages?inbox_id=1')->assertForbidden();
        $this->postJson('/admin/dashboard-inbox/support/1/read', ['ids' => ['reply']])->assertOk()->assertJsonPath('marked', 1);
        $this->assertFalse((bool) SupportFirestore::value($this->firestore->docs['room_1_11']['other-branch'], 'dashboard_support_read_at'));
    }

    public function test_support_read_marks_only_rendered_incoming_ids_preserves_message_and_new_arrival(): void
    {
        $this->firestore->add('room_1_20', 'visible', 20, 1);
        $this->firestore->add('room_1_20', 'reply', 1, 20);
        $original = $this->firestore->docs['room_1_20']['visible'];
        $this->firestore->add('room_1_20', 'after-open', 20, 1);
        $this->actingAs($this->actor(1), 'admin');
        $this->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => ['visible', 'visible', 'reply']])->assertOk()->assertJsonPath('marked', 1);
        $read = $this->firestore->docs['room_1_20']['visible'];
        foreach (['message', 'sender_id', 'user_id', 'timestamp'] as $field) $this->assertSame($original['fields'][$field], $read['fields'][$field]);
        $this->assertNotNull(SupportFirestore::value($read, 'dashboard_support_read_at'));
        $this->assertSame('1', SupportFirestore::value($read, 'dashboard_support_read_by'));
        $this->assertNull(SupportFirestore::value($this->firestore->docs['room_1_20']['after-open'], 'dashboard_support_read_at'));
        $this->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => ['visible']])->assertOk()->assertJsonPath('marked', 0);
        $this->getJson('/admin/dashboard-inbox/support')->assertOk()->assertJsonPath('count', 1);
    }

    public function test_support_read_rejects_path_traversal_and_oversized_or_missing_ids_before_remote_requests(): void
    {
        $this->actingAs($this->actor(1), 'admin');
        foreach ([['../another'], ['one?updateMask=message'], ['a/b'], [], array_fill(0, 101, 'one')] as $ids) {
            $this->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => $ids])->assertStatus(422);
        }
        $this->assertSame([], $this->firestore->writes);
    }

    public function test_counter_paginates_all_actual_messages_and_reuses_only_unchanged_room_history(): void
    {
        $this->firestore->add('room_1_20', 'one', 20, 1);
        $this->firestore->extraPages['room_1_20'] = ['documents' => [InboxFirestoreFixture::document('room_1_20', 'two', 20, 1)]];
        $this->actingAs($this->actor(1), 'admin');
        $this->getJson('/admin/dashboard-inbox/support')->assertOk()->assertJsonPath('count', 2);
        $calls = count($this->firestore->messageCalls);
        $this->getJson('/admin/dashboard-inbox/support?fresh=1')->assertOk()->assertJsonPath('count', 2);
        $this->assertSame($calls, count($this->firestore->messageCalls));
        $this->firestore->add('room_1_20', 'new', 20, 1);
        $this->getJson('/admin/dashboard-inbox/support?fresh=1')->assertOk()->assertJsonPath('count', 3);
        $this->assertGreaterThan($calls, count($this->firestore->messageCalls));
    }

    public function test_message_page_excludes_forged_other_participants_and_returns_correct_older_cursor(): void
    {
        $this->firestore->add('room_1_20', 'one', 20, 1);
        $this->firestore->add('room_1_20', 'forged', 21, 1);
        $this->firestore->extraPages['room_1_20'] = ['documents' => [InboxFirestoreFixture::document('room_1_20', 'older', 20, 1)]];
        $this->actingAs($this->actor(1), 'admin');
        $this->getJson('/admin/dashboard-inbox/support/20/messages')->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('next_page_token', 'next');
        $this->getJson('/admin/dashboard-inbox/support/20/messages?page_token=next')->assertOk()->assertJsonPath('messages.0.id', 'older');
    }

    public function test_sending_preserves_app_room_identity_and_audits_actor_for_authorized_central_inbox(): void
    {
        $this->mock(FcmNotificationsController::class, function ($mock) { $mock->shouldReceive('send_chat_notification')->once()->andReturn(response()->json(['success' => true])); });
        $this->actingAs($this->actor(635), 'admin');
        $this->postJson('/admin/dashboard-inbox/support/20/messages', ['message' => '<b>Plain user text</b>', 'inbox_id' => 1])->assertOk()
            ->assertJsonPath('message.sender_id', 1)->assertJsonPath('message.recipient_id', 20)->assertJsonPath('message.incoming', false);
        $stored = $this->firestore->docs['room_1_20']['sent-message'];
        $this->assertSame('<b>Plain user text</b>', SupportFirestore::value($stored, 'message'));
        $this->assertSame('635', SupportFirestore::value($stored, 'dashboard_sender_admin_id'));
        $this->assertSame('0', SupportFirestore::value($stored, 'messageType'));
        $this->postJson('/admin/dashboard-inbox/support/20/messages', ['message' => 'Forbidden', 'inbox_id' => 2])->assertForbidden();
        $this->postJson('/admin/dashboard-inbox/support/20/messages', ['message' => ''])->assertStatus(422);
    }

    public function test_support_failure_returns_unavailable_and_does_not_invent_zero_count(): void
    {
        $this->firestore->unavailable = true;
        $this->actingAs($this->actor(1), 'admin')->getJson('/admin/dashboard-inbox/support')->assertStatus(503)
            ->assertJsonPath('success', false)->assertJsonMissing(['count' => 0]);
    }

    public function test_firestore_query_and_read_mask_use_configured_app_project_and_preserve_document_fields(): void
    {
        $document = InboxFirestoreFixture::document('room_1_20', 'message-id', 20, 1);
        Http::fake(function ($request) use ($document) {
            return Http::response(str_contains($request->url(), ':runQuery') ? [['document' => $document]] : $document, 200);
        });
        $gateway = new class extends SupportFirestore { protected function token(): string { return 'fixture-token'; } };
        $gateway->rooms(1);
        $gateway->markRead('room_1_20', 'message-id', $document, 635);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'projects/fasakhaninjatest/') && str_contains($request->url(), ':runQuery')
                && $request['structuredQuery']['where']['fieldFilter']['field']['fieldPath'] === 'users'
                && $request['structuredQuery']['where']['fieldFilter']['op'] === 'ARRAY_CONTAINS';
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH' && str_contains($request->url(), 'updateMask.fieldPaths=dashboard_support_read_at')
                && str_contains($request->url(), 'currentDocument.updateTime=')
                && array_keys($request['fields']) === ['dashboard_support_read_at', 'dashboard_support_read_by']
                && $request['fields']['dashboard_support_read_by']['integerValue'] === '635';
        });
        $this->assertSame(3, count(Http::recorded()));
    }

    public function test_firestore_reads_visible_snapshot_in_two_atomic_masked_calls_including_numeric_legacy_ids(): void
    {
        $one = InboxFirestoreFixture::document('room_1_20', 'one', 20, 1);
        $one['fields']['sender_id'] = ['integerValue' => '20'];
        $one['fields']['user_id'] = ['integerValue' => '1'];
        $two = InboxFirestoreFixture::document('room_1_20', 'two', 20, 1);
        Http::fake(function ($request) use ($one, $two) {
            return Http::response(str_contains($request->url(), ':batchGet') ? [['found' => $one], ['found' => $two]] : ['writeResults' => []], 200);
        });
        $gateway = new class extends SupportFirestore { protected function token(): string { return 'fixture-token'; } };
        $this->app->instance(SupportFirestore::class, $gateway);
        $this->actingAs($this->actor(1), 'admin');
        $this->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => ['one', 'two']])->assertOk()->assertJsonPath('marked', 2);
        $this->assertSame(2, count(Http::recorded()));
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), ':commit')) return false;
            $writes = $request['writes'];
            return count($writes) === 2 && $writes[0]['currentDocument']['updateTime'] === '2026-10-03T06:00:00Z'
                && $writes[0]['updateMask']['fieldPaths'] === ['dashboard_support_read_at', 'dashboard_support_read_by']
                && array_keys($writes[0]['update']['fields']) === ['dashboard_support_read_at', 'dashboard_support_read_by'];
        });
    }

    public function test_concurrent_firestore_message_edit_fails_atomic_read_without_reporting_success(): void
    {
        $one = InboxFirestoreFixture::document('room_1_20', 'one', 20, 1);
        Http::fake(function ($request) use ($one) {
            return str_contains($request->url(), ':batchGet') ? Http::response([['found' => $one]], 200)
                : Http::response(['error' => ['status' => 'FAILED_PRECONDITION']], 409);
        });
        $gateway = new class extends SupportFirestore { protected function token(): string { return 'fixture-token'; } };
        $this->app->instance(SupportFirestore::class, $gateway);
        $this->actingAs($this->actor(1), 'admin')->postJson('/admin/dashboard-inbox/support/20/read', ['ids' => ['one']])
            ->assertStatus(503)->assertJsonPath('success', false)->assertJsonMissing(['marked' => 1]);
        $this->assertSame(2, count(Http::recorded()));
    }

    public function test_firestore_send_retry_uses_same_uuid_and_atomic_message_room_commit(): void
    {
        $id = '70ba02b3-4254-4f66-a940-fefb416b7ad1';
        $stored = null;
        Http::fake(function ($request) use (&$stored) {
            if (str_contains($request->url(), ':commit')) {
                if ($stored) return Http::response(['error' => ['status' => 'ALREADY_EXISTS']], 409);
                $stored = $request['writes'][0]['update'];
                return Http::response(['writeResults' => []], 200);
            }
            return Http::response($stored, 200);
        });
        $gateway = new class extends SupportFirestore { protected function token(): string { return 'fixture-token'; } };
        $inbox = new SupportInbox($gateway);
        $first = $inbox->send($this->actor(635), 20, 'Genuine reply', 1, $id);
        $retry = $inbox->send($this->actor(635), 20, 'Genuine reply', 1, $id);
        $this->assertSame($id, $first['id']); $this->assertSame($first, $retry);
        $this->assertSame(3, count(Http::recorded()));
        Http::assertSent(function ($request) use ($id) {
            if (!str_contains($request->url(), ':commit')) return false;
            $writes = $request['writes'];
            return count($writes) === 2 && $writes[0]['currentDocument']['exists'] === false
                && str_ends_with($writes[0]['update']['name'], '/messages/'.$id)
                && $writes[1]['updateMask']['fieldPaths'] === ['roomId', 'users', 'lastMessageTimestamp']
                && $writes[1]['update']['fields']['users']['arrayValue']['values'] === [['stringValue' => '1'], ['stringValue' => '20']];
        });
    }
}

class InboxFirestoreFixture extends SupportFirestore
{
    public array $docs = [], $writes = [], $messageCalls = [], $extraPages = [], $versions = [];
    public bool $unavailable = false;
    public static function document(string $room, string $id, int $sender, int $recipient, bool $read = false): array
    {
        $fields = ['message' => ['stringValue' => 'Genuine message '.$id], 'sender_id' => ['stringValue' => (string) $sender],
            'user_id' => ['stringValue' => (string) $recipient], 'timestamp' => ['timestampValue' => '2026-10-03T06:00:00Z']];
        if ($read) $fields['dashboard_support_read_at'] = ['timestampValue' => '2026-10-03T06:01:00Z'];
        return ['name' => 'projects/fasakhaninjatest/databases/(default)/documents/DashboardChat/'.$room.'/messages/'.$id,
            'fields' => $fields, 'updateTime' => '2026-10-03T06:00:00Z'];
    }
    public function add(string $room, string $id, int $sender, int $recipient, bool $read = false): void
    {
        $this->docs[$room][$id] = self::document($room, $id, $sender, $recipient, $read);
        $this->versions[$room] = (string) (($this->versions[$room] ?? 0) + 1);
    }
    public function rooms(int $inbox): array
    {
        if ($this->unavailable) throw new \RuntimeException('Fixture failure');
        $rooms = [];
        foreach ($this->docs as $room => $docs) $rooms[] = ['name' => 'projects/fasakhaninjatest/databases/(default)/documents/DashboardChat/'.$room,
            'updateTime' => $this->versions[$room] ?? '1'];
        return $rooms;
    }
    public function messages(string $room, string $page = ''): array
    {
        $this->messageCalls[] = $room;
        if ($page) return $this->extraPages[$room] ?? ['documents' => []];
        return ['documents' => array_reverse(array_values($this->docs[$room] ?? []))] + (isset($this->extraPages[$room]) ? ['nextPageToken' => 'next'] : []);
    }
    public function message(string $room, string $id): array
    {
        return $this->docs[$room][$id] ?? throw new \RuntimeException('Unknown message');
    }
    public function markRead(string $room, string $id, array $document, int $actor): void
    {
        $this->writes[] = ['room' => $room, 'id' => $id, 'actor' => $actor];
        $this->docs[$room][$id]['fields']['dashboard_support_read_at'] = ['timestampValue' => now()->toIso8601ZuluString()];
        $this->docs[$room][$id]['fields']['dashboard_support_read_by'] = ['integerValue' => (string) $actor];
    }
    public function batchMessages(string $room, array $ids): array
    {
        return array_values(array_intersect_key($this->docs[$room] ?? [], array_flip($ids)));
    }
    public function markReads(string $room, array $documents, int $actor): void
    {
        foreach ($documents as $document) $this->markRead($room, basename($document['name']), $document, $actor);
    }
    public function send(string $room, array $fields, string $id): array
    {
        $document = self::document($room, 'sent-message', (int) $fields['sender_id']['stringValue'], (int) $fields['user_id']['stringValue']);
        $document['fields'] = $fields; $this->docs[$room]['sent-message'] = $document;
        $this->writes[] = ['room' => $room, 'fields' => $fields];
        return $document;
    }
}
