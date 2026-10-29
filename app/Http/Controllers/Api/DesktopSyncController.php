<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OperatingDay;
use App\Services\DesktopSync\DesktopSyncDevices;
use App\Services\DesktopSync\DesktopSyncOperations;
use App\Services\DesktopSync\DesktopSyncState;
use Illuminate\Http\Request;

/** The GO ERP desktop app: set a device up once, then send its operations and fetch updates every 30 seconds. */
class DesktopSyncController extends Controller
{
    public function enroll(Request $request, DesktopSyncDevices $devices)
    {
        $values = $request->validate([
            'login' => 'required|string|max:120', 'password' => 'required|string|max:200',
            'device_name' => 'nullable|string|max:100', 'branches' => 'nullable|array|max:200',
            'branches.*' => ['string', 'regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],
        ]);
        return response()->json($devices->enroll($values['login'], $values['password'], $values['device_name'] ?? '',
            $values['branches'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function sync(Request $request, DesktopSyncDevices $devices, DesktopSyncOperations $operations, DesktopSyncState $state)
    {
        $device = $devices->authenticate($request->header('X-GO-ERP-Device'), $request->bearerToken());
        $values = $request->validate([
            'operations' => 'nullable|array|max:50', 'want_users' => 'nullable|boolean', 'users_hash' => 'nullable|string|max:64',
            'want_catalogs' => 'nullable|boolean', 'catalog_hashes' => 'nullable|array|max:200',
            'catalog_hashes.*' => 'nullable|string|max:64',
        ]);
        $response = ['server_time' => now('UTC')->toIso8601String(), 'operating_day' => OperatingDay::metadata(),
            'results' => $operations->apply($device, $values['operations'] ?? [])];
        if (($values['want_users'] ?? false) || ($values['want_catalogs'] ?? false)) {
            $users = $state->users($device);
            $hash = hash('sha256', json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($values['want_users'] ?? false) {
                $response['users_hash'] = $hash;
                if (($values['users_hash'] ?? null) !== $hash) $response['users'] = $users;
            }
            if ($values['want_catalogs'] ?? false) $response['branches'] = $state->catalogs($device, $values['catalog_hashes'] ?? [], $users);
        }
        return response()->json($response)->header('Cache-Control', 'no-store');
    }
}
