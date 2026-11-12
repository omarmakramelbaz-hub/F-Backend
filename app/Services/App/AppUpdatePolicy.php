<?php

namespace App\Services\App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The customer app's forced update, set on the dashboard: the lowest version still allowed on Android and on iPhone,
 * and where to update it. An older app shows a message it cannot close on its home screen, with a button to the store.
 * Kept in the settings table under its own group («app_update»), beside the app's general settings.
 */
class AppUpdatePolicy
{
    public const GROUP = 'app_update';
    public const FIELDS = ['android_min_version', 'ios_min_version', 'android_store_url', 'ios_store_url', 'message'];
    public const DEFAULT_MESSAGE = 'في نسخة جديدة من فسخانستا. حدّث التطبيق علشان تكمّل وتستفيد من أحدث المميزات.';

    public function get(): array
    {
        $values = array_fill_keys(self::FIELDS, '');
        if (Schema::hasTable('settings')) {
            foreach (DB::table('settings')->where('group', self::GROUP)->whereIn('name', self::FIELDS)->get(['name', 'payload']) as $row) {
                $value = json_decode((string) $row->payload, true);
                $values[$row->name] = is_string($value) ? $value : '';
            }
        }
        return $values;
    }

    public function save(array $input): array
    {
        $store = fn (string $host) => ['nullable', 'string', 'max:500', 'url', 'starts_with:https://'.$host.'/'];
        $v = Validator::make($input, [
            'android_min_version' => ['nullable', 'string', 'max:20', 'regex:/\A\d{1,4}(\.\d{1,4}){0,3}\z/'],
            'ios_min_version' => ['nullable', 'string', 'max:20', 'regex:/\A\d{1,4}(\.\d{1,4}){0,3}\z/'],
            'android_store_url' => $store('play.google.com'),
            'ios_store_url' => $store('apps.apple.com'),
            'message' => ['nullable', 'string', 'max:300'],
        ], [
            'android_min_version.regex' => 'رقم نسخة أندرويد بالشكل 1.0.55.',
            'ios_min_version.regex' => 'رقم نسخة الآيفون بالشكل 1.0.55.',
            'android_store_url.starts_with' => 'رابط جوجل بلاي يبدأ بـ https://play.google.com/',
            'ios_store_url.starts_with' => 'رابط آب ستور يبدأ بـ https://apps.apple.com/',
        ])->validate();
        DB::transaction(function () use ($v) {
            foreach (self::FIELDS as $name) {
                $value = trim((string) ($v[$name] ?? ''));
                $where = ['group' => self::GROUP, 'name' => $name];
                if (DB::table('settings')->where($where)->exists()) DB::table('settings')->where($where)->update(['payload' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
                else DB::table('settings')->insert($where + ['locked' => false, 'payload' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return $this->get();
    }

    /** What the app reads (GET /api/app-version): per platform, the lowest version allowed and the store to update from. */
    public function forApp(): array
    {
        $values = $this->get();
        return [
            'android' => ['min_version' => $values['android_min_version'] ?: null, 'store_url' => $values['android_store_url'] ?: null],
            'ios' => ['min_version' => $values['ios_min_version'] ?: null, 'store_url' => $values['ios_store_url'] ?: null],
            'message' => $values['message'] ?: self::DEFAULT_MESSAGE,
        ];
    }
}
