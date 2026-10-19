<?php
// CLI-only activation check. Does not read customer/order records or print credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['--preflight', '--enabled'], true)) throw new RuntimeException('Expected --preflight or --enabled.');
    foreach (['photon_url'=>'https://photon.komoot.io/api/', 'osrm_url'=>'https://routing.openstreetmap.de/routed-car'] as $key=>$url) {
        if (rtrim((string)config('services.maps.'.$key), '/') !== rtrim($url, '/')) throw new RuntimeException('Unexpected map endpoint override; review it before activating.');
    }
    if (!(Illuminate\Support\Facades\Cache::store()->getStore() instanceof Illuminate\Contracts\Cache\LockProvider)) throw new RuntimeException('The configured cache must support map request locks.');
    if ($mode === '--enabled') {
        if (!app(App\Services\Dashboard\PhoneMapProvider::class)->enabled()) throw new RuntimeException('PHONE_OPEN_MAPS_ENABLED is not effective.');
        echo "PHONE MAPS CONFIGURATION ACTIVE\n";
        exit(0);
    }
    config(['services.maps.phone_open_enabled'=>true]);
    $reply = Illuminate\Support\Facades\Http::acceptJson()
        ->withHeaders(['User-Agent'=>'FasakhanstaDashboard/1.0 (+https://fasakhaninja.com)'])
        ->withOptions(['allow_redirects'=>false, 'connect_timeout'=>3])->timeout(8)
        ->get(config('services.maps.photon_url'), ['q'=>'شبرا الخيمة', 'limit'=>1, 'countrycode'=>'EG', 'bbox'=>'24,22,37,32']);
    if (!$reply->successful() || !is_array($reply->json('features')) || !count($reply->json('features'))) throw new RuntimeException('Photon did not return an address result.');
    echo "PHOTON ADDRESS SEARCH READY\n";
    $route = app(App\Services\Dashboard\PhoneMapProvider::class)->road(['latitude'=>30.0444, 'longitude'=>31.2357], 30.0470, 31.2397);
    if ($route['meters'] <= 0) throw new RuntimeException('The public test route is empty.');
    echo "OSRM ROAD ROUTING READY\n";
} catch (Throwable $error) {
    // Connection exception strings may include proxy details; keep output bounded.
    fwrite(STDERR, "PHONE MAPS CHECK FAILED: ".($error instanceof RuntimeException && !($error instanceof Illuminate\Http\Client\ConnectionException) ? $error->getMessage() : 'Could not reach the map providers from this PHP runtime.')."\n");
    exit(1);
}
