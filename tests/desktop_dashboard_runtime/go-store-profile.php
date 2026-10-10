<?php
// Targeted original GO profile forms on the same imported 106-table fixture.
// Keep every process, cookie jar and identity private to this fixture.
(function () use ($application, $ownerStage, $database, $ownerDevice, $ownerLink, $remoteDevice,
    $origin, $httpPort, $browserToken, $profile, $env, $port, $pdo) {
    $goSwitch = function (bool $local) use ($ownerStage, $database, $ownerDevice) {
        config(['database.connections.mysql.database' => $local ? $ownerStage : $database,
            'desktop_dashboard.local' => $local, 'desktop_dashboard.device_id' => $ownerDevice]);
        \Illuminate\Support\Facades\DB::purge();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    };
    $goDb = fn () => app('db');
    $goProfile = fn (int $owner = 60) => (array) $goDb()->table('go_stores')->where('user_id', $owner)->first();
    $goFee = fn (int $owner = 60) => $goDb()->table('users')->where('id', $owner)->value('delegate_fees');
    $goState = fn (int $owner = 60) => [$goProfile($owner), $goFee($owner)];
    $goUuid = fn () => (string) \Illuminate\Support\Str::uuid();
    $goActor = fn (int $id = 1) => \App\Models\User::withoutGlobalScopes()->findOrFail($id);
    $goHelper = fn () => app(\App\Services\Dashboard\DesktopDashboardGoStoreProfile::class);
    $goReject = function (int $status, callable $work, string $message) {
        try { $work(); throw new \RuntimeException($message.' was accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            verify($error->getStatusCode() === $status, $message.' ('.$error->getStatusCode().')');
        }
    };
    $goEnvelope = function (string $id) use ($goDb) {
        $row = $goDb()->table('desktop_dashboard_commands')->where('command_id', $id)->first();
        $saved = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->local_result_cipher), true, 512, JSON_THROW_ON_ERROR);
        return ['command_id' => $id, 'actor_id' => (int) $row->actor_id, 'route_name' => $row->route_name,
            'payload' => json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->command_cipher), true, 512, JSON_THROW_ON_ERROR),
            'local_result' => $saved['result'], 'local_references' => $saved['references'],
            'dependencies' => json_decode($row->dependencies, true),
            'occurred_at' => \Carbon\Carbon::parse($row->created_at, 'UTC')->toIso8601String()];
    };
    $goCookies = []; $goWeb = null; $goPipes = [];
    $goHttp = function (string $path, ?array $form = null, array $extraHeaders = [], ?string $method = null)
        use ($origin, $browserToken, &$goCookies) {
        $headers = ['X-Fasakhansta-Desktop: '.$browserToken, ...$extraHeaders];
        if ($goCookies) $headers[] = 'Cookie: '.implode('; ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($goCookies), $goCookies));
        if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $context = stream_context_create(['http' => ['method' => $method ?? ($form === null ? 'GET' : 'POST'),
            'header' => implode("\r\n", $headers), 'content' => $form === null ? '' : http_build_query($form),
            'ignore_errors' => true, 'timeout' => 15, 'follow_location' => 0]]);
        $body = @file_get_contents($origin.$path, false, $context); $headers = $http_response_header ?? [];
        preg_match('/^HTTP\/\S+ (\d+)/', $headers[0] ?? '', $status);
        foreach ($headers as $header) if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $match)) $goCookies[$match[1]] = $match[2];
        return [(int) ($status[1] ?? 0), $body, $headers];
    };
    $goStop = function () use (&$goWeb, &$goPipes) {
        if (is_resource($goWeb)) { fclose($goPipes[0]); proc_terminate($goWeb); proc_close($goWeb); }
        $goWeb = null;
    };
    $goStart = function (bool $local, string $email = 'owner@test.invalid', ?array $override = null)
        use ($application, $httpPort, $profile, $env, $ownerStage, $database, $origin, $goHttp, $goStop, &$goWeb, &$goPipes, &$goCookies) {
        $goStop(); $goCookies = []; $processEnv = $override ?? $env;
        $processEnv['DB_DATABASE'] = $override['DB_DATABASE'] ?? ($local ? $ownerStage : $database);
        $processEnv['APP_URL'] = $origin; $processEnv['DESKTOP_TEST_APPLICATION'] = $application;
        $processEnv['DESKTOP_DASHBOARD_ENABLED'] = 'true';
        $command = $local ? [PHP_BINARY, '-S', '127.0.0.1:'.$httpPort, '-t', $application.'/public', $application.'/desktop/router.php']
            : [PHP_BINARY, '-S', '127.0.0.1:'.$httpPort, __DIR__.'/server-router.php'];
        $goWeb = proc_open($command, [['pipe', 'r'], ['file', $profile.'/go-store-profile.log', 'a'], ['file', $profile.'/go-store-profile.log', 'a']], $goPipes, $application, $processEnv);
        for ($n = 0; $n < 100; $n++) { [$status, $page] = $goHttp('/admin/login'); if ($status === 200) break; usleep(50000); }
        preg_match('/name="_token" value="([^"]+)"/', $page ?? '', $csrf);
        verify($status === 200 && isset($csrf[1]) && $goHttp('/admin/signin', ['_token' => $csrf[1], 'email' => $email, 'password' => 'Fixture123'])[0] === 302,
            'the original GO profile fixture signs into its '.($local ? 'imported local' : 'server').' dashboard');
        [$status, $page] = $goHttp('/admin/go-stores/60');
        preg_match('/name="_token" value="([^"]+)"/', $page, $csrf);
        verify($status === 200 && isset($csrf[1]), 'the actual original GO profile Blade form retains its CSRF and existing store');
        return $csrf[1];
    };
    $goForm = fn (string $csrf, array $row, string $id) => ['_token' => $csrf, '_desktop_command' => $id,
        'name' => 'متجر GO بعد التعديل', 'kind' => 'supermarket', 'address' => 'شارع الاختبار، القاهرة ١٢',
        'revision' => $row['revision'], 'commission_rate' => '12.50'];
    $goLocation = function (array $reply) {
        foreach ($reply[2] as $header) if (str_starts_with(strtolower($header), 'location:')) return parse_url(trim(substr($header, 9)), PHP_URL_PATH);
        return null;
    };
    try {
        verify(realpath((new \ReflectionClass(\App\Services\Dashboard\DesktopDashboardGoStoreProfile::class))->getFileName())
            === realpath($application.'/app/Services/Dashboard/DesktopDashboardGoStoreProfile.php'),
            'the GO profile helper is bound to the generated original application source');
        $goSwitch(true); $goLocalBefore = $goState(); $goLocalOther = $goState(61);
        $goLocalJournal = $goDb()->table('desktop_dashboard_commands')->count();
        $goCsrf = $goStart(true); $goId = $goUuid(); $goValues = $goForm($goCsrf, $goProfile(), $goId);
        foreach (['commission_rate' => '100.01', 'name' => 'x', 'kind' => 'grocery', 'address' => 'x', 'revision' => '-1'] as $field => $value) {
            $invalid = array_replace($goValues, ['_desktop_command' => $goUuid(), $field => $value]);
            verify($goHttp('/admin/go-stores/60', $invalid)[0] === 302 && $goState() === $goLocalBefore
                && $goDb()->table('desktop_dashboard_commands')->count() === $goLocalJournal,
                'original GO profile validation retains its form redirect and queues no success: '.$field);
        }
        $stale = array_replace($goValues, ['_desktop_command' => $goUuid(), 'revision' => (int) $goProfile()['revision'] + 10]);
        verify($goHttp('/admin/go-stores/60', $stale)[0] === 409 && $goState() === $goLocalBefore,
            'the original stale GO profile revision rejects both profile and commission changes');
        $goDb()->unprepared("CREATE TRIGGER fixture_go_profile_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='go-stores.update' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture GO profile journal rollback'; END IF; END");
        try { verify($goHttp('/admin/go-stores/60', $goValues)[0] === 500 && $goState() === $goLocalBefore
            && $goDb()->table('desktop_dashboard_commands')->count() === $goLocalJournal,
            'failed GO profile journal persistence rolls back the complete profile, revision and commission together'); }
        finally { $goDb()->unprepared('DROP TRIGGER fixture_go_profile_journal'); }
        $goReply = $goHttp('/admin/go-stores/60', $goValues);
        $goAfter = $goState(); $goRetry = $goHttp('/admin/go-stores/60', $goValues, ['X-Fasakhansta-Command: '.$goId]);
        verify($goReply[0] === 302 && $goLocation($goReply) === '/admin/go-stores/60' && $goRetry[0] === 302
            && $goRetry[1] === $goReply[1] && $goLocation($goRetry) === $goLocation($goReply) && $goState() === $goAfter
            && (int) $goAfter[0]['revision'] === (int) $goLocalBefore[0]['revision'] + 1
            && (float) $goAfter[1] === 12.5 && $goDb()->table('desktop_dashboard_commands')->count() === $goLocalJournal + 1,
            'a lost original GO profile reply and the same header/form UUID replay one redirect, profile revision and commission');
        verify($goHttp('/admin/go-stores/60', $goValues, ['X-Fasakhansta-Command: '.$goUuid()])[0] === 409
            && $goHttp('/admin/go-stores/60', array_replace($goValues, ['commission_rate' => '13.00']))[0] === 409 && $goState() === $goAfter,
            'conflicting GO command aliases or changed commission cannot reuse the immutable local operation');
        $goCommand = $goEnvelope($goId); $goFacts = $goCommand['payload']['facts']['catalog_before'];
        $goExpectedRow = $goLocalBefore[0]; unset($goExpectedRow['created_at'], $goExpectedRow['updated_at']);
        // A prior actual availability receipt owns this typed identity; resolve to
        // the local ID solely to compare the persisted original before-row.
        $goFactRow = $goFacts['row'];
        if (is_array($goFactRow['user_id'])) $goFactRow['user_id'] = $goFactRow['user_id']['$desktop_ref']['local_id'];
        verify($goFactRow === $goExpectedRow && array_keys($goFacts['owner']) === ['account_type', 'app_scope', 'pending_vendor_id', 'delegate_fees']
            && ($goFacts['owner']['delegate_fees'] === null || preg_match('/^-?\d+\.\d{2}$/D', $goFacts['owner']['delegate_fees']))
            && $goCommand['local_references'] === ['menu_store_owner' => 60],
            'GO profile facts retain every original profile column, canonical commission and the named existing owner identity without timestamps');
        verify(($goCommand['payload']['parameters']['owner']['$desktop_ref']['entity'] ?? null) === 'menu_store_owner'
            && count($goCommand['dependencies']) === 1,
            'the original profile command reuses the acknowledged availability menu_store_owner identity and dependency');
        $goFactKeys = array_keys($goFacts); sort($goFactKeys); $goOwnerKeys = array_keys($goFacts['owner']); sort($goOwnerKeys);
        verify($goFactKeys === ['owner', 'pending', 'row'] && $goOwnerKeys === ['account_type', 'app_scope', 'delegate_fees', 'pending_vendor_id']
            && !str_contains(json_encode($goFacts), 'password') && !str_contains(json_encode($goFacts), 'store60@test.invalid'),
            'the GO owner before-facts exclude account secrets, email, telephone and unrelated identity fields');
        $goDb()->table('users')->where('id', 1)->update(['account_type' => 'user']);
        try { verify($goHttp('/admin/go-stores/60', $goValues)[0] === 403, 'a saved local GO profile reply rechecks the current administrator account'); }
        finally { $goDb()->table('users')->where('id', 1)->update(['account_type' => 'admin']); }
        $goBranches = $goDb()->table('desktop_dashboard_local_state')->where('device_id', $ownerDevice)->value('branches');
        $goDb()->table('desktop_dashboard_local_state')->where('device_id', $ownerDevice)->update(['branches' => json_encode(array_values(array_diff(json_decode($goBranches, true), ['gs:60'])))]);
        try { verify($goHttp('/admin/go-stores/60', $goValues)[0] === 403, 'a saved local GO profile reply requires its current imported gs owner branch'); }
        finally { $goDb()->table('desktop_dashboard_local_state')->where('device_id', $ownerDevice)->update(['branches' => $goBranches]); }
        $goExistingProfile = $goProfile(); $goExistingFee = $goFee();
        $goDb()->table('go_stores')->where('user_id', 60)->delete();
        try { verify($goHttp('/admin/go-stores/60', array_replace($goValues, ['_desktop_command' => $goUuid(), 'revision' => 0]))[0] === 404
            && !$goDb()->table('go_stores')->where('user_id', 60)->exists() && $goFee() === $goExistingFee
            && $goDb()->table('desktop_dashboard_commands')->count() === $goLocalJournal + 1,
            'the original local GO profile adapter cannot create a missing profile through the update route'); }
        finally { $goDb()->table('go_stores')->insert($goExistingProfile); }
        $goDb()->table('users')->where('id', 1)->update(['owner_resturant_id' => 100]);
        try { verify((int) $goHelper()->authorize(['owner' => 60], $goActor())->id === 1,
            'GO profile authorization preserves the original admin owner_resturant_id behavior'); }
        finally { $goDb()->table('users')->where('id', 1)->update(['owner_resturant_id' => null]); }
        verify($goState(61) === $goLocalOther, 'the unrelated enrolled GO store retains its original profile and commission');
        $goStop();

        $goSwitch(false); $goServerBefore = $goState();
        $goReconcile = fn (array $command, ?object $device = null) => app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($device ?? $remoteDevice, $command);
        $wrong = $goCommand; $wrong['payload']['parameters']['owner']['$desktop_ref']['entity'] = 'menu_restaurant';
        $goReject(422, fn () => $goReconcile($wrong), 'a GO profile owner cannot use a colliding restaurant identity type');
        foreach (['name' => 'اسم مختلف على السيرفر', 'address' => 'عنوان مختلف على السيرفر'] as $field => $value) {
            $goDb()->table('go_stores')->where('user_id', 60)->update([$field => $value]);
            try { $goReject(409, fn () => $goReconcile($goCommand), 'the complete original GO before-row detects a server '.$field.' change'); }
            finally { $goDb()->table('go_stores')->where('user_id', 60)->update([$field => $goServerBefore[0][$field]]); }
        }
        $goDb()->table('users')->where('id', 60)->update(['delegate_fees' => '17.25']);
        try { $goReject(409, fn () => $goReconcile($goCommand), 'a server commission change conflicts independently of the unchanged GO profile revision'); }
        finally { $goDb()->table('users')->where('id', 60)->update(['delegate_fees' => $goServerBefore[1]]); }
        $goDb()->unprepared("CREATE TRIGGER fixture_go_profile_receipt BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='go-stores.update' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture GO profile server receipt rollback'; END IF; END");
        try {
            try { $goReconcile($goCommand); throw new \RuntimeException('GO receipt failure was accepted.'); }
            catch (\Illuminate\Database\QueryException $error) { verify($goState() === $goServerBefore
                && !$goDb()->table('desktop_dashboard_commands')->where('command_id', $goId)->exists(),
                'failed server GO receipt rolls back the original profile revision and commission atomically'); }
        } finally { $goDb()->unprepared('DROP TRIGGER fixture_go_profile_receipt'); }
        $goReceipt = $goReconcile($goCommand);
        verify($goReceipt === $goReconcile($goCommand) && $goReceipt['result']['http']['status'] === 302
            && $goReceipt['references'] === [['entity' => 'menu_store_owner', 'local_id' => 60, 'server_id' => 60]]
            && (int) $goProfile()['revision'] === (int) $goServerBefore[0]['revision'] + 1 && (float) $goFee() === 12.5,
            'the original server GO controller preserves its redirect and commits one typed profile receipt and commission');
        $goDb()->table('users')->where('id', 1)->update(['account_type' => 'user']);
        try { $goReject(403, fn () => $goReconcile($goCommand), 'an acknowledged GO profile receipt rechecks current actor authority before deduplication'); }
        finally { $goDb()->table('users')->where('id', 1)->update(['account_type' => 'admin']); }
        $goDeviceBranches = $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->value('branches');
        $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->update(['branches' => json_encode(array_values(array_diff(json_decode($goDeviceBranches, true), ['gs:60'])))]);
        try { $goReject(403, fn () => $goReconcile($goCommand), 'the acknowledged GO receipt checks the current enrolled branch instead of a stale caller device'); }
        finally { $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->update(['branches' => $goDeviceBranches]); }
        $goSwitch(true); app(\App\Services\Dashboard\DesktopDashboardJournal::class)->acknowledge($ownerDevice, $goId, $goReceipt); $goSwitch(false);

        // Warm real Spatie role/direct grants, then commit revocations through an
        // independent MariaDB connection after establishing an older RR snapshot.
        $goDb()->table('users')->insert(['id' => 67, 'added_by' => 1, 'name' => 'مدير ملف GO', 'email' => 'go-profile-admin@test.invalid', 'mobile' => '1200000067',
            'password' => password_hash('Fixture123', PASSWORD_BCRYPT), 'account_type' => 'admin', 'status' => 'accepted', 'app_scope' => 'fasakhansta']);
        $goRole = \Spatie\Permission\Models\Role::create(['name' => 'Fixture GO Profile Editor', 'guard_name' => 'admin']);
        foreach (['order-list', 'resturant-list', 'resturant-edit'] as $name) $goRole->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($name, 'admin'));
        $goAdmin = $goActor(67); $goAdmin->assignRole($goRole); app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $goRace = new \PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4', 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (['resturant-list', 'resturant-edit'] as $name) {
            verify($goAdmin->can($name), 'the GO fixture warms the actual original role grant: '.$name);
            $permission = \Spatie\Permission\Models\Permission::findByName($name, 'admin'); $goDb()->beginTransaction();
            try {
                $goDb()->table('role_has_permissions')->count();
                $goRace->prepare('DELETE FROM role_has_permissions WHERE role_id=? AND permission_id=?')->execute([$goRole->id, $permission->id]);
                $goReject(403, fn () => $goHelper()->authorize(['owner' => 60], $goAdmin), 'a warmed GO role grant cannot survive current revocation after an older RR snapshot: '.$name);
            } finally { $goDb()->rollBack(); $goRace->prepare('INSERT INTO role_has_permissions (role_id,permission_id) VALUES (?,?)')->execute([$goRole->id, $permission->id]); }
        }
        $goEdit = \Spatie\Permission\Models\Permission::findByName('resturant-edit', 'admin'); $goRole->revokePermissionTo($goEdit);
        $goAdmin->givePermissionTo($goEdit); app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(); $goAdmin = $goActor(67);
        verify($goAdmin->can('resturant-edit'), 'the GO fixture warms an actual direct original editing grant'); $goDb()->beginTransaction();
        try {
            $goDb()->table('model_has_permissions')->count();
            $goRace->prepare('DELETE FROM model_has_permissions WHERE model_type=? AND model_id=? AND permission_id=?')->execute([\App\Models\User::class, 67, $goEdit->id]);
            $goReject(403, fn () => $goHelper()->authorize(['owner' => 60], $goAdmin), 'current GO direct grant revocation wins over a warmed old transaction');
        } finally {
            $goDb()->rollBack();
            $goRace->prepare('INSERT INTO model_has_permissions (model_type,model_id,permission_id) VALUES (?,?,?)')->execute([\App\Models\User::class, 67, $goEdit->id]);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
        $goAdmin->removeRole($goRole); $goAdmin->revokePermissionTo($goEdit);
        $goSuper = \Spatie\Permission\Models\Role::where('name', 'Super Admin')->where('guard_name', 'admin')->firstOrFail(); $goAdmin->assignRole($goSuper);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $goReject(403, fn () => $goHelper()->authorize(['owner' => 60], $goAdmin), 'the GO controller gains no resturant grants from a Super Admin role name alone');
        $goAdmin->removeRole($goSuper); $goAdmin->assignRole($goRole); $goAdmin->givePermissionTo($goEdit); app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Catalog must observe a real independently committed revision even
        // when an earlier plain read established a repeatable-read snapshot.
        $goCatalogBefore = $goProfile(); $goDb()->beginTransaction();
        try {
            $goProfile(); $goRace->prepare('UPDATE go_stores SET revision=revision+1 WHERE user_id=60')->execute();
            $goRequest = \Illuminate\Http\Request::create('/admin/go-stores/60', 'POST', $goForm('', $goCatalogBefore, $goUuid()));
            $goReject(409, fn () => app(\App\Services\GoStores\Catalog::class)->saveStore(60, $goRequest), 'original Catalog reads a current locked profile revision after an independent commit');
        } finally { $goDb()->rollBack(); $goRace->prepare('UPDATE go_stores SET revision=? WHERE user_id=60')->execute([$goCatalogBefore['revision']]); }

        // The target is the original Catalog owner, not POS status policy.
        $goOwnerStatus = $goDb()->table('users')->where('id', 60)->value('status');
        $goDb()->table('users')->where('id', 60)->update(['status' => 'disabled']);
        try { verify((int) $goHelper()->authorize(['owner' => 60], $goActor())->id === 1 && $goReconcile($goCommand) === $goReceipt,
            'the original disabled vendor target remains editable and its saved GO receipt remains valid'); }
        finally { $goDb()->table('users')->where('id', 60)->update(['status' => $goOwnerStatus]); }
        $goPending = $goDb()->table('pending_vendors')->insertGetId(['full_name' => 'مندوب مالك متجر GO', 'mobile' => '1200000060', 'type' => 'delegate', 'status' => 'accepted', 'source_app' => 'go_partner', 'profession_key' => 'store_owner']);
        $goDb()->table('users')->where('id', 60)->update(['account_type' => 'delegate', 'pending_vendor_id' => $goPending]);
        try {
            verify((int) $goHelper()->authorize(['owner' => 60], $goActor())->id === 1 && $goHelper()->state(60)['pending'] === ['id' => $goPending, 'profession_key' => 'store_owner'],
                'the original GO delegate store_owner eligibility contributes its pending profession proof');
            $goDb()->beginTransaction();
            try {
                $goDb()->table('pending_vendors')->where('id', $goPending)->first();
                $goRace->prepare("UPDATE pending_vendors SET profession_key='courier' WHERE id=?")->execute([$goPending]);
                $goReject(403, fn () => $goHelper()->authorize(['owner' => 60], $goActor()), 'a current independent profession change revokes original GO delegate eligibility after an older snapshot');
            } finally { $goDb()->rollBack(); $goRace->prepare("UPDATE pending_vendors SET profession_key='store_owner' WHERE id=?")->execute([$goPending]); }
        } finally { $goDb()->table('users')->where('id', 60)->update(['account_type' => 'vendor', 'pending_vendor_id' => null]); }

        // Actual native API reservation plus original HTTP form and durable reply.
        $goServerCsrf = $goStart(false);
        $goAttempt = fn (string $path = '/admin/go-stores/60', string $method = 'POST') => ['id' => $goUuid(), 'method' => $method, 'path' => $path];
        $goDecide = function (array $attempt, string $action = 'reserve', ?array $link = null) use ($goHttp, $ownerLink) {
            [$status, $body] = $goHttp('/api/desktop-dashboard/remote-attempts', ['action' => $action] + $attempt,
                ['Authorization: Bearer '.($link ?? $ownerLink)['token'], 'Accept: application/json']);
            return [$status, json_decode($body, true)];
        };
        $goProof = fn (array $reply) => ['X-Fasakhansta-Remote-Attempt: '.$reply['id'], 'X-Fasakhansta-Remote-Capability: '.$reply['capability']];
        $goArrayAttempt = $goAttempt(); [, $goArrayProof] = $goDecide($goArrayAttempt);
        $goArrayCommand = $goUuid(); $goArrayValues = $goForm($goServerCsrf, $goProfile(), $goArrayCommand);
        $goArrayValues['_desktop_command'] = [$goArrayCommand]; $goArrayBefore = $goState();
        $goArrayJournal = $goDb()->table('desktop_dashboard_commands')->count();
        foreach ([[], ['X-Fasakhansta-Command: '.$goArrayCommand]] as $goArrayHeaders) {
            $goArrayReply = $goHttp('/admin/go-stores/60', $goArrayValues, [...$goProof($goArrayProof), ...$goArrayHeaders, 'Accept: application/json']);
            $goArrayRow = $goDb()->table('desktop_dashboard_remote_attempts')->where('id', $goArrayAttempt['id'])->first();
            verify($goArrayReply[0] === 422 && $goState() === $goArrayBefore && $goArrayRow->status === 'ready'
                && $goDb()->table('desktop_dashboard_commands')->count() === $goArrayJournal
                && $goArrayRow->operation_id === null && $goArrayRow->response_cipher === null,
                'native GO raw array UUID is rejected before conversion with unchanged profile and ready outcome'.($goArrayHeaders ? ' despite a valid UUID header' : ' without an alias header'));
        }
        verify($goDecide($goArrayAttempt, 'settle')[1]['status'] === 'cancelled' && $goState() === $goArrayBefore,
            'an invalid raw GO operation can be cancelled without an unrecorded profile or fee write');
        $goNative = $goAttempt(); [$goReserveStatus, $goReserved] = $goDecide($goNative);
        verify($goReserveStatus === 200 && $goReserved['status'] === 'ready', 'the native server API reserves exactly the original POST GO profile owner path');
        foreach ([['/admin/go-stores', 'POST'], ['/admin/go-stores/60/products', 'POST'], ['/admin/go-stores/60', 'PUT'], ['/admin/go-stores/60', 'DELETE']] as [$path, $method])
            verify($goDecide($goAttempt($path, $method))[0] === 422, 'native GO profile reservation cannot broaden to an unsupported original path or method: '.$method.' '.$path);
        $goNativeValues = $goForm($goServerCsrf, $goProfile(), $goUuid()); $goNativeBefore = $goState();
        $goNativeReply = $goHttp('/admin/go-stores/60', $goNativeValues, $goProof($goReserved));
        $goNativeAfter = $goState(); $goNativeRetry = $goHttp('/admin/go-stores/60', $goNativeValues, [...$goProof($goReserved), 'X-Fasakhansta-Command: '.$goNativeValues['_desktop_command']]);
        verify($goNativeReply[0] === 302 && $goLocation($goNativeReply) === '/admin/go-stores/60' && $goNativeRetry[0] === 302
            && $goNativeRetry[1] === $goNativeReply[1] && $goState() === $goNativeAfter && (int) $goNativeAfter[0]['revision'] === (int) $goNativeBefore[0]['revision'] + 1
            && $goDecide($goNative, 'settle')[1]['status'] === 'committed', 'the actual native GO form outcome, profile revision and fee commit together and survive a lost reply');
        verify($goHttp('/admin/go-stores/60', $goNativeValues, [...$goProof($goReserved), 'X-Fasakhansta-Command: '.$goUuid()])[0] === 409,
            'a native saved GO outcome rejects contradictory header and form operation aliases');
        $goNextTransmission = $goAttempt(); [, $goNextProof] = $goDecide($goNextTransmission);
        verify($goHttp('/admin/go-stores/60', $goNativeValues, $goProof($goNextProof))[0] === 302 && $goState() === $goNativeAfter,
            'a new native transmission reuses the same original GO logical UUID without another revision');
        $goDb()->table('users')->where('id', 1)->update(['account_type' => 'user']);
        try { verify($goHttp('/admin/go-stores/60', $goNativeValues, $goProof($goReserved))[0] === 403,
            'a committed native GO outcome requires the current original administrator'); }
        finally { $goDb()->table('users')->where('id', 1)->update(['account_type' => 'admin']); }
        $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->update(['branches' => json_encode(array_values(array_diff(json_decode($goDeviceBranches, true), ['gs:60'])))]);
        try { verify($goHttp('/admin/go-stores/60', $goNativeValues, $goProof($goReserved))[0] === 403,
            'a committed native GO outcome still requires its current gs owner enrollment'); }
        finally { $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->update(['branches' => $goDeviceBranches]); }
        $goRollback = $goForm($goServerCsrf, $goProfile(), $goUuid()); $goRollback['commission_rate'] = '23.75'; $goRollback['name'] = 'متجر يجب التراجع عنه';
        $goRollbackAttempt = $goAttempt(); [, $goRollbackProof] = $goDecide($goRollbackAttempt);
        $goDb()->unprepared("CREATE TRIGGER fixture_go_profile_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$goRollback['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture GO profile native outcome rollback'; END IF; END");
        try { verify($goHttp('/admin/go-stores/60', $goRollback, $goProof($goRollbackProof))[0] === 500 && $goState() === $goNativeAfter,
            'failed native GO outcome persistence rolls back both original profile and commission'); }
        finally { $goDb()->unprepared('DROP TRIGGER fixture_go_profile_outcome'); }
        verify($goDecide($goRollbackAttempt, 'settle')[1]['status'] === 'cancelled'
            && $goHttp('/admin/go-stores/60', $goRollback, $goProof($goRollbackProof))[0] === 409 && $goState() === $goNativeAfter,
            'a cancelled GO native outcome rejects a delayed original form without unrecorded profile or fee writes');
        $goStop();

        // A genuine second enrolled administrator gets its own original
        // schema snapshot and delegate eligibility reference, rather than
        // rewriting the first device's actor or injecting a pending snapshot.
        $goAdminTargetIdentity = (array) $goDb()->table('users')->where('id', 60)->first(['account_type', 'pending_vendor_id']);
        $goLinkedPrivate = 'GO_PENDING_LINKED_PRIVATE_'.str_replace('-', '', $goUuid());
        $goForeignPrivate = 'GO_PENDING_FOREIGN_PRIVATE_'.str_replace('-', '', $goUuid());
        $goAdminPending = $goDb()->table('pending_vendors')->insertGetId(['added_by' => 1, 'full_name' => $goLinkedPrivate,
            'mobile' => $goLinkedPrivate, 'owner_name' => $goLinkedPrivate, 'national_id' => $goLinkedPrivate,
            'another_mobile' => $goLinkedPrivate, 'vodafone_cash_mobile' => $goLinkedPrivate, 'email' => $goLinkedPrivate.'@test.invalid',
            'location' => $goLinkedPrivate, 'payment_method' => 'private_fixture', 'payment_identifier' => $goLinkedPrivate,
            'type' => 'delegate', 'status' => 'pending', 'source_app' => 'go_partner', 'profession_key' => 'store_owner']);
        $goForeignPending = $goDb()->table('pending_vendors')->insertGetId(['added_by' => 1, 'full_name' => $goForeignPrivate,
            'mobile' => $goForeignPrivate, 'owner_name' => $goForeignPrivate, 'national_id' => $goForeignPrivate,
            'another_mobile' => $goForeignPrivate, 'vodafone_cash_mobile' => $goForeignPrivate, 'email' => $goForeignPrivate.'@test.invalid',
            'location' => $goForeignPrivate, 'payment_method' => 'private_fixture', 'payment_identifier' => $goForeignPrivate,
            'type' => 'vendor', 'status' => 'declined', 'source_app' => 'go_partner', 'profession_key' => 'store_owner']);
        $goDb()->table('users')->where('id', 60)->update(['account_type' => 'delegate', 'pending_vendor_id' => $goAdminPending]);
        foreach ([$goAdminPending, $goForeignPending] as $index => $pendingId) {
            $goDb()->table('media')->insert(['id' => 880010 + $index, 'model_type' => 'App\\Models\\PendingVendor', 'model_id' => $pendingId,
                'collection_name' => 'national_id', 'name' => 'go-pending-private-'.$index, 'file_name' => 'go-pending-private-'.$index.'.png',
                'mime_type' => 'image/png', 'disk' => 'public', 'conversions_disk' => 'public', 'size' => 1,
                'manipulations' => '[]', 'custom_properties' => '{}', 'generated_conversions' => '{}', 'responsive_images' => '{}']);
        }
        $goAdminLink = app(\App\Services\Dashboard\DesktopDashboardDevices::class)->enroll(['device_id' => $goUuid(),
            'name' => 'GO profile current grants fixture', 'nonce' => bin2hex(random_bytes(32))], $goActor(67));
        $goAdminDevice = app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($goAdminLink['token']);
        $goAdminSnapshot = app(\App\Services\Dashboard\DesktopDashboardBootstrap::class)->export($goAdminDevice);
        // The earlier owner snapshot predates the original attendance migration
        // installed by remote-attempts.php. Its added reviewed table must also
        // be exported here; compare exact names instead of losing that table.
        $goDeployedSchemas = json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'), true, 512, JSON_THROW_ON_ERROR)['tables'];
        $goExpectedTables = [...array_keys($goDeployedSchemas), 'branch_attendance_rules']; sort($goExpectedTables);
        $goCatalogTables = \App\Services\Dashboard\DesktopDashboardSchema::TABLES; sort($goCatalogTables);
        $goExportedTables = array_keys($goAdminSnapshot['tables']); sort($goExportedTables);
        $goSchemaDetail = ['deployed_count' => count($goDeployedSchemas), 'expected_count' => count($goExpectedTables), 'exported_count' => count($goExportedTables),
            'missing' => array_values(array_diff($goExpectedTables, $goExportedTables)), 'unexpected' => array_values(array_diff($goExportedTables, $goExpectedTables)),
            'catalog_missing' => array_values(array_diff($goExpectedTables, $goCatalogTables)), 'catalog_unexpected' => array_values(array_diff($goCatalogTables, $goExpectedTables))];
        verify(count($goDeployedSchemas) === 106 && count($goExpectedTables) === 107 && $goCatalogTables === $goExpectedTables
            && $goExportedTables === $goExpectedTables
            && realpath((new \ReflectionClass(\AddEmployeeAttendanceRules::class))->getFileName()) === realpath($application.'/database/migrations/2026_10_08_190000_add_employee_attendance_rules.php'),
            'the second original GO administrator exports exactly the inspected106 tables plus the original installed attendance table: '.json_encode($goSchemaDetail, JSON_UNESCAPED_SLASHES));
        $goPendingProjection = [['id' => $goAdminPending, 'profession_key' => 'store_owner', 'type' => 'delegate',
            'status' => 'pending', 'source_app' => 'go_partner', 'full_name' => '', 'mobile' => '']];
        $goPendingPart = $goAdminSnapshot['tables']['pending_vendors'];
        $goPendingDdl = (array) $goDb()->selectOne('SHOW CREATE TABLE `pending_vendors`');
        verify($goPendingPart['rows'] === $goPendingProjection && $goPendingPart['ddl'] === array_values($goPendingDdl)[1]
            && $goPendingPart['sha256'] === hash('sha256', \App\Services\Dashboard\DesktopDashboardBootstrap::json($goPendingProjection)),
            'the actual non-primary GO snapshot exports only its linked delegate proof, exact projected row hash and unchanged original DDL');
        verify($goAdminSnapshot['coverage']['pending_vendor_eligibility'] === ['kind' => 'go-store-owner-only', 'projected_ids' => [$goAdminPending],
            'source_fields' => ['id', 'profession_key', 'type', 'status', 'source_app'], 'redacted_required_fields' => ['full_name', 'mobile'], 'application_review' => false]
            && $goAdminSnapshot['coverage']['full_dashboard'] === false,
            'the projected GO pending reference reports its exact eligibility coverage without application review or full dashboard coverage');
        $goSnapshotText = \App\Services\Dashboard\DesktopDashboardBootstrap::json($goAdminSnapshot);
        verify(!str_contains($goSnapshotText, $goLinkedPrivate) && !str_contains($goSnapshotText, $goForeignPrivate)
            && !str_contains($goSnapshotText, 'go-pending-private-')
            && array_filter($goAdminSnapshot['tables']['media']['rows'], fn ($row) => $row['model_type'] === 'App\\Models\\PendingVendor') === []
            && $goAdminSnapshot['coverage']['media'] === true,
            'linked and foreign pending PII, payment identifiers and PendingVendor media remain outside the projected GO snapshot');
        $goAdminStage = 'fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
        $pdo->exec('CREATE DATABASE `'.$goAdminStage.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        register_shutdown_function(fn () => $pdo->exec('DROP DATABASE IF EXISTS `'.$goAdminStage.'`'));
        $goAdminSwitch = function (bool $local) use ($goAdminStage, $database, $goAdminDevice) {
            config(['database.connections.mysql.database' => $local ? $goAdminStage : $database,
                'desktop_dashboard.local' => $local, 'desktop_dashboard.device_id' => $goAdminDevice->id]);
            \Illuminate\Support\Facades\DB::purge(); app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        };
        $goAdminSwitch(true); $goAdminImported = app(\App\Services\Dashboard\DesktopDashboardImport::class)->import($goAdminSnapshot);
        verify(app(\App\Services\Dashboard\DesktopDashboardImport::class)->verify($goAdminImported)['verified'],
            'the independently imported original GO administrator dataset verifies before its actual form writes');
        $goImportedPending = (array) $goDb()->table('pending_vendors')->where('id', $goAdminPending)->first();
        verify($goDb()->table('pending_vendors')->count() === 1 && $goImportedPending['full_name'] === '' && $goImportedPending['mobile'] === ''
            && $goImportedPending['payment_identifier'] === null && $goImportedPending['national_id'] === null
            && $goHelper()->state(60)['pending'] === ['id' => $goAdminPending, 'profession_key' => 'store_owner'],
            'the actual original import restores the minimal delegate eligibility proof while required private fields stay blank and nullable fields stay null');
        $goAdminEnv = $env; $goAdminEnv['DB_DATABASE'] = $goAdminStage; $goAdminEnv['DESKTOP_DASHBOARD_DEVICE_ID'] = $goAdminDevice->id;
        $goAdminCsrf = $goStart(true, 'go-profile-admin@test.invalid', $goAdminEnv);
        $goAdminId = $goUuid(); $goAdminForm = $goForm($goAdminCsrf, $goProfile(), $goAdminId);
        $goAdminForm['name'] = 'متجر عدّله المدير الحالي'; $goAdminForm['commission_rate'] = '14.25';
        verify($goHttp('/admin/go-stores/60', $goAdminForm)[0] === 302 && $goHttp('/admin/go-stores/60', $goAdminForm)[0] === 302,
            'a non-primary original GO administrator saves and repeats its real imported delegate profile with current role and direct grants');
        $goAdminCommand = $goEnvelope($goAdminId); $goAdminLocalAfter = $goState();
        verify($goAdminCommand['payload']['facts']['catalog_before']['pending'] === ['id' => $goAdminPending, 'profession_key' => 'store_owner']
            && $goAdminCommand['payload']['facts']['catalog_before']['owner']['account_type'] === 'delegate'
            && $goAdminCommand['payload']['facts']['catalog_before']['owner']['pending_vendor_id'] === $goAdminPending,
            'the actual imported delegate form binds its journal to the linked projected pending profession proof');
        $goLocalRole = \Spatie\Permission\Models\Role::findByName('Fixture GO Profile Editor', 'admin');
        $goLocalList = \Spatie\Permission\Models\Permission::findByName('resturant-list', 'admin');
        verify($goActor(67)->can('resturant-list'), 'the actual saved local GO form warms its original cached listing role grant');
        $goDb()->table('role_has_permissions')->where('role_id', $goLocalRole->id)->where('permission_id', $goLocalList->id)->delete();
        try { verify($goHttp('/admin/go-stores/60', $goAdminForm)[0] === 403 && $goState() === $goAdminLocalAfter,
            'a saved original local GO reply rejects a revoked warmed role grant before returning its stored redirect'); }
        finally { $goDb()->table('role_has_permissions')->insert(['role_id' => $goLocalRole->id, 'permission_id' => $goLocalList->id]); }
        $goLocalEdit = \Spatie\Permission\Models\Permission::findByName('resturant-edit', 'admin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        verify($goActor(67)->can('resturant-edit'), 'the actual saved local GO form warms its original direct editing grant');
        $goDb()->table('model_has_permissions')->where('model_type', \App\Models\User::class)->where('model_id', 67)->where('permission_id', $goLocalEdit->id)->delete();
        try { verify($goHttp('/admin/go-stores/60', $goAdminForm)[0] === 403 && $goState() === $goAdminLocalAfter,
            'a saved original local GO reply rejects current direct editing revocation without another profile or fee write'); }
        finally { $goDb()->table('model_has_permissions')->insert(['model_type' => \App\Models\User::class, 'model_id' => 67, 'permission_id' => $goLocalEdit->id]); }
        $goStop(); $goAdminSwitch(false); $goAdminReceipt = $goReconcile($goAdminCommand, $goAdminDevice);
        verify($goAdminReceipt['committed'] && $goAdminReceipt === $goReconcile($goAdminCommand, $goAdminDevice),
            'the second real imported GO delegate form reconciles with matching pending proof through its original controller and deduplicates its exact server receipt');
        foreach (['resturant-list' => 'role', 'resturant-edit' => 'direct'] as $name => $grant) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            verify($goActor(67)->can($name), 'the acknowledged original GO receipt warms its current '.$grant.' grant: '.$name);
            $permission = \Spatie\Permission\Models\Permission::findByName($name, 'admin'); $goDb()->beginTransaction();
            try {
                $goDb()->table($grant === 'role' ? 'role_has_permissions' : 'model_has_permissions')->count();
                if ($grant === 'role') $goRace->prepare('DELETE FROM role_has_permissions WHERE role_id=? AND permission_id=?')->execute([$goRole->id, $permission->id]);
                else $goRace->prepare('DELETE FROM model_has_permissions WHERE model_type=? AND model_id=? AND permission_id=?')->execute([\App\Models\User::class, 67, $permission->id]);
                $goReject(403, fn () => $goReconcile($goAdminCommand, $goAdminDevice),
                    'the saved server GO receipt rejects an independently revoked warm '.$grant.' grant inside older RR');
            } finally {
                $goDb()->rollBack();
                if ($grant === 'role') $goRace->prepare('INSERT INTO role_has_permissions (role_id,permission_id) VALUES (?,?)')->execute([$goRole->id, $permission->id]);
                else $goRace->prepare('INSERT INTO model_has_permissions (model_type,model_id,permission_id) VALUES (?,?,?)')->execute([\App\Models\User::class, 67, $permission->id]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $goAdminNativeCsrf = $goStart(false, 'go-profile-admin@test.invalid');
        $goAdminAttempt = $goAttempt(); [$goAdminReserveStatus, $goAdminReserved] = $goDecide($goAdminAttempt, 'reserve', $goAdminLink);
        $goAdminNativeForm = $goForm($goAdminNativeCsrf, $goProfile(), $goUuid()); $goAdminNativeForm['commission_rate'] = '15.75';
        verify($goAdminReserveStatus === 200 && $goHttp('/admin/go-stores/60', $goAdminNativeForm, $goProof($goAdminReserved))[0] === 302,
            'the original non-primary GO administrator commits an actual account-bound native form outcome');
        $goAdminNativeAfter = $goState();
        $goAdminTerminal = $goDecide($goAdminAttempt, 'settle', $goAdminLink);
        $goTerminalKeys = array_keys($goAdminTerminal[1]); sort($goTerminalKeys);
        $goExpectedTerminalKeys = ['format', 'id', 'device_id', 'actor_id', 'method', 'path', 'status', 'capability']; sort($goExpectedTerminalKeys);
        verify($goAdminTerminal[0] === 200 && $goAdminTerminal[1]['status'] === 'committed' && $goAdminTerminal[1]['capability'] === null
            && $goTerminalKeys === $goExpectedTerminalKeys,
            'the actual GO terminal API confirms only committed transmission metadata without returning the original business redirect');
        $goAdminTerminalRow = (array) $goDb()->table('desktop_dashboard_remote_attempts')->where('id', $goAdminAttempt['id'])->first();
        foreach (['resturant-list' => 'role', 'resturant-edit' => 'direct'] as $name => $grant) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(); verify($goActor(67)->can($name), 'the saved native GO outcome warms its original '.$grant.' grant');
            $permission = \Spatie\Permission\Models\Permission::findByName($name, 'admin');
            $table = $grant === 'role' ? 'role_has_permissions' : 'model_has_permissions';
            $query = $goDb()->table($table)->where('permission_id', $permission->id);
            if ($grant === 'role') $query->where('role_id', $goRole->id); else $query->where('model_type', \App\Models\User::class)->where('model_id', 67);
            $query->delete();
            try {
                verify($goHttp('/admin/go-stores/60', $goAdminNativeForm, $goProof($goAdminReserved))[0] === 403 && $goState() === $goAdminNativeAfter,
                    'the stored native GO redirect rejects the current revoked '.$grant.' permission before reply deduplication');
                verify($goDecide($goAdminAttempt, 'settle', $goAdminLink)[0] === 403
                    && (array) $goDb()->table('desktop_dashboard_remote_attempts')->where('id', $goAdminAttempt['id'])->first() === $goAdminTerminalRow
                    && $goState() === $goAdminNativeAfter,
                    'the actual committed GO terminal status rechecks the revoked warmed '.$grant.' grant and retains its immutable outcome without another business write');
            }
            finally { $goDb()->table($table)->insert($grant === 'role' ? ['role_id' => $goRole->id, 'permission_id' => $permission->id]
                : ['model_type' => \App\Models\User::class, 'model_id' => 67, 'permission_id' => $permission->id]); }
            verify($goDecide($goAdminAttempt, 'settle', $goAdminLink) === $goAdminTerminal,
                'restoring the original GO '.$grant.' grant returns the same committed metadata without its saved business response');
        }
        $goAdminTerminalBranches = $goDb()->table('desktop_dashboard_devices')->where('id', $goAdminDevice->id)->value('branches');
        $goDb()->table('desktop_dashboard_devices')->where('id', $goAdminDevice->id)->update(['branches' => json_encode(array_values(array_diff(json_decode($goAdminTerminalBranches, true), ['gs:60'])))]);
        try {
            verify($goDecide($goAdminAttempt, 'settle', $goAdminLink)[0] === 403
                && (array) $goDb()->table('desktop_dashboard_remote_attempts')->where('id', $goAdminAttempt['id'])->first() === $goAdminTerminalRow
                && $goState() === $goAdminNativeAfter,
                'the actual committed GO terminal status requires current gs enrollment from its saved owner path and leaves the committed row intact');
        } finally { $goDb()->table('desktop_dashboard_devices')->where('id', $goAdminDevice->id)->update(['branches' => $goAdminTerminalBranches]); }
        verify($goDecide($goAdminAttempt, 'settle', $goAdminLink) === $goAdminTerminal,
            'restoring GO owner enrollment returns the same terminal committed metadata without replaying the form');
        $goDeletedTerminalProfile = $goProfile(); $goDeletedTerminalFee = $goFee();
        $goDeletedTerminalJournal = $goDb()->table('desktop_dashboard_commands')->count();
        $goDb()->table('go_stores')->where('user_id', 60)->delete();
        try {
            verify($goDecide($goAdminAttempt, 'settle', $goAdminLink) === $goAdminTerminal
                && !$goDb()->table('go_stores')->where('user_id', 60)->exists() && $goDb()->table('users')->where('id', 60)->exists()
                && $goFee() === $goDeletedTerminalFee,
                'the actual GO terminal API preserves its committed historical status after profile deletion without requiring current profile existence');
            verify($goHttp('/admin/go-stores/60', $goAdminNativeForm, $goProof($goAdminReserved))[0] === 404
                && !$goDb()->table('go_stores')->where('user_id', 60)->exists() && $goFee() === $goDeletedTerminalFee
                && $goDb()->table('desktop_dashboard_commands')->count() === $goDeletedTerminalJournal
                && (array) $goDb()->table('desktop_dashboard_remote_attempts')->where('id', $goAdminAttempt['id'])->first() === $goAdminTerminalRow,
                'the original cached GO redirect still rejects a deleted profile while terminal metadata cannot recreate it or change the commission or journal');
        } finally { $goDb()->table('go_stores')->insert($goDeletedTerminalProfile); }
        verify($goDecide($goAdminAttempt, 'settle', $goAdminLink) === $goAdminTerminal && $goState() === $goAdminNativeAfter,
            'restoring the existing GO profile preserves the same terminal metadata and original business state without replay');
        $goStop();
        $goDb()->table('users')->where('id', 60)->update($goAdminTargetIdentity);

        // Simulate pre-existing snapshot ID drift explicitly. The mapping seed
        // executes the original server availability controller and produces a
        // real acknowledged receipt; it is not a GO owner creation capability.
        $goSwitch(true); $goDriftOwner = (array) $goDb()->table('users')->where('id', 60)->first();
        $goDriftStore = $goProfile(); $goDriftProduct = (array) $goDb()->table('go_store_products')->where('id', 1)->first();
        $goOldIdentity = (array) $goDb()->table('desktop_dashboard_entities')->where('device_id', $ownerDevice)->where('entity', 'menu_store_owner')->where('local_id', 60)->first();
        $goSwitch(false); $goDriftOriginal = $goState(); $goDriftOther = $goState(61);
        $goServerOwner = $goDriftOwner; $goServerOwner['id'] = 160; $goServerOwner['email'] = 'go-profile-drift160@test.invalid'; $goServerOwner['mobile'] = '1200000160';
        $goDb()->table('users')->insert($goServerOwner);
        $goServerStore = $goDriftStore; $goServerStore['user_id'] = 160; $goDb()->table('go_stores')->insert($goServerStore);
        $goServerProduct = $goDriftProduct; $goServerProduct['id'] = 16001; $goServerProduct['user_id'] = 160;
        $goServerProduct['request_key'] = $goUuid(); $goDb()->table('go_store_products')->insert($goServerProduct);
        $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->update(['branches' => json_encode([...json_decode($goDeviceBranches, true), 'gs:160'])]);
        $goSeedId = $goUuid(); $goMenuRow = app(\App\Services\Dashboard\OrderBoardMenu::class)->authorizeAvailability('gs', 160, 16001, $goActor())['row'];
        unset($goMenuRow['id'], $goMenuRow['created_at'], $goMenuRow['updated_at']);
        $goMenuBranch = (array) $goDb()->table('users')->where('id', 160)->first(['account_type', 'app_scope', 'pending_vendor_id']);
        $goSeed = ['command_id' => $goSeedId, 'actor_id' => 1, 'route_name' => 'order-board.menu.availability',
            'payload' => ['parameters' => ['kind' => 'gs', 'branchId' => 160, 'product' => 16001],
                'values' => ['idempotency_key' => $goSeedId, 'available' => !(bool) $goDriftProduct['available'],
                    'expected_available' => (bool) $goDriftProduct['available'], 'expected_revision' => (int) $goDriftProduct['revision']],
                'files' => [], 'facts' => ['menu_before' => ['kind' => 'gs', 'row' => $goMenuRow, 'branch' => $goMenuBranch]]],
            'local_result' => ['success' => true, 'item' => ['id' => 1, 'available' => !(bool) $goDriftProduct['available']]],
            'local_references' => ['menu_store_owner' => 60, 'menu_store_product' => 1], 'dependencies' => [], 'occurred_at' => now('UTC')->toIso8601String()];
        $goSeedReceipt = $goReconcile($goSeed);
        verify($goSeedReceipt['committed'] && $goSeedReceipt['result']['item']['id'] === 16001
            && $goSeedReceipt['references'][0] === ['entity' => 'menu_store_owner', 'local_id' => 60, 'server_id' => 160],
            'the explicit snapshot ID drift fixture obtains an actual original availability receipt mapping owner60 to server160');
        $goSwitch(true);
        $goDb()->table('desktop_dashboard_commands')->insert(['device_id' => $ownerDevice, 'command_id' => $goSeedId, 'actor_id' => 1,
            'route_name' => $goSeed['route_name'], 'request_hash' => app(\App\Services\Dashboard\DesktopDashboardJournal::class)->fingerprint($goSeed),
            'command_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($goSeed['payload'])),
            'local_result_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['format' => 1, 'result' => $goSeed['local_result'], 'references' => $goSeed['local_references']])),
            'dependencies' => '[]', 'status' => 'acknowledged', 'attempts' => 0,
            'server_result_cipher' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($goSeedReceipt)),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'), 'acknowledged_at' => now('UTC')]);
        $goDb()->table('desktop_dashboard_entities')->where('device_id', $ownerDevice)->where('entity', 'menu_store_owner')->where('local_id', 60)->update(['command_id' => $goSeedId]);
        $goDriftCsrf = $goStart(true); $goDriftId = $goUuid(); $goDriftForm = $goForm($goDriftCsrf, $goProfile(), $goDriftId);
        $goDriftForm['name'] = 'ملف صاحب المتجر المرتبط'; $goDriftForm['commission_rate'] = '21.50';
        verify($goHttp('/admin/go-stores/60', $goDriftForm)[0] === 302,
            'the actual original local GO profile form records an operation after the acknowledged typed snapshot mapping');
        $goDriftCommand = $goEnvelope($goDriftId); $goStop(); $goSwitch(false);
        $goResolved = app(\App\Services\Dashboard\DesktopDashboardReferences::class)->resolve($ownerDevice, $goDriftCommand['payload']);
        verify($goResolved['parameters']['owner'] === 160 && $goResolved['facts']['catalog_before']['row']['user_id'] === 160
            && $goDriftCommand['dependencies'] === [$goSeedId],
            'both the original route owner and complete profile before-row resolve through the same named owner identity');
        $goWrongDrift = $goDriftCommand; $goWrongDrift['payload']['facts']['catalog_before']['row']['user_id']['$desktop_ref']['entity'] = 'menu_store_product';
        $goReject(422, fn () => $goReconcile($goWrongDrift), 'a profile before-row cannot replace its owner type with a colliding store product reference');
        $goDriftReceipt = $goReconcile($goDriftCommand);
        verify($goDriftReceipt === $goReconcile($goDriftCommand) && $goDriftReceipt['result']['http']['location'] === '/admin/go-stores/160'
            && $goDriftReceipt['references'] === [['entity' => 'menu_store_owner', 'local_id' => 60, 'server_id' => 160]]
            && (int) $goProfile(160)['revision'] === (int) $goDriftStore['revision'] + 1 && (float) $goFee(160) === 21.5
            && $goState() === $goDriftOriginal && $goState(61) === $goDriftOther,
            'the actual original GO controller follows typed owner ID drift once, preserves its mapped redirect, and leaves both other profiles and fees unchanged');
        $goSwitch(true); app(\App\Services\Dashboard\DesktopDashboardJournal::class)->acknowledge($ownerDevice, $goDriftId, $goDriftReceipt);
        $goDb()->table('desktop_dashboard_entities')->where('device_id', $ownerDevice)->where('entity', 'menu_store_owner')->where('local_id', 60)->update(['command_id' => $goOldIdentity['command_id']]);
        $goSwitch(false); $goDb()->table('desktop_dashboard_devices')->where('id', $ownerDevice)->update(['branches' => $goDeviceBranches]);
    } finally {
        $goStop(); $goSwitch(false);
    }
})();
