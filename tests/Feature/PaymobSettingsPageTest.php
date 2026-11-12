<?php

namespace Tests\Feature;

use App\Http\Controllers\Dashboard\SettingsController;
use Tests\TestCase;

/**
 * «Paymob Integration Setting»: the keys as the server has them (from its configuration, cached or not), the secret
 * key only as set or missing, and never written from the page (saving wrote empty values over the real keys once the
 * configuration was cached, and rewrote .env split on every space).
 */
class PaymobSettingsPageTest extends TestCase
{
    public function test_the_page_shows_the_ids_and_never_the_secret_key(): void
    {
        config(['services.paymob' => ['api_key' => 'sk_live_secret_value', 'card_integration_id' => '4400', 'card_iframe_id' => '901', 'wallet_integration_id' => null]]);
        $view = app(SettingsController::class)->enviroSetting(app(\App\Models\GeneralSettings::class));
        $rows = collect($view->getData()['paymob'])->mapWithKeys(fn ($row) => [$row[0] => $row[2]])->all();
        $this->assertSame(['PAYMOB_API_KEY' => 'مضبوط ✓', 'PAYMOB_CARD_INTEGRATION_ID' => '4400', 'PAYMOB_CARD_IFRAME_ID' => '901',
            'PAYMOB_MOBILE_WALLET_INTEGRATION_ID' => null], $rows);
        $this->assertStringNotContainsString('sk_live_secret_value', json_encode($view->getData()['paymob']));
        $source = file_get_contents(resource_path('views/admin/env-setting.blade.php'));
        $this->assertStringNotContainsString("env('PAYMOB", $source);
        $this->assertStringNotContainsString('name="PAYMOB_API_KEY"', $source);
    }

    public function test_saving_never_writes_the_servers_settings_file(): void
    {
        $file = base_path('.env');
        $before = is_file($file) ? hash_file('sha256', $file) : null;
        request()->merge(['PAYMOB_API_KEY' => '', "PAYMOB_CARD_INTEGRATION_ID" => "1\nAPP_DEBUG=true"]);
        $response = app(SettingsController::class)->updateEnv();
        $this->assertSame($before, is_file($file) ? hash_file('sha256', $file) : null);
        $this->assertSame('مفاتيح Paymob بتتظبط على السيرفر بس، مش من الداشبورد.', $response->getSession()->get('error'));
        $this->assertFalse(method_exists(SettingsController::class, 'changeEnv'));
    }
}
