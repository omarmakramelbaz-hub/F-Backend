<?php

namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    if (!is_string($autoload) || !is_file($autoload)) throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
    require $autoload;
    function app($abstract = null) { $container = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $container : $container->make($abstract); }
    function config($key = null, $default = null) { return app('config')->get($key, $default); }
    function auth($guard = null) { return new class { public function user() { return $GLOBALS['sessionActor']; } }; }
    function response() { return new class { public function json($body, $status = 200) { return new \Illuminate\Http\JsonResponse($body, $status); } }; }
    function abort_unless($condition, $status, $message = '') { if (!$condition) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function route($name) { return 'https://example.test/' . $name; }
    function url($path) { return 'https://example.test' . $path; }
    function verify($condition, $label) { if (!$condition) throw new \RuntimeException($label); }
}

namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class User { public $id = 1; public $account_type = 'admin'; public $owner_resturant_id = null; public $can_checkout = true; public $active = true; }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor) { $fresh = $GLOBALS['persistedActor']; \abort_unless($actor && $fresh->id === $actor->id && $fresh->active, 403); return $fresh; }
        public function permissions($actor) { return ['can_checkout' => $this->actor($actor)->can_checkout]; }
        public function branches($actor) { $this->actor($actor); return [['value' => 'f:363', 'kind' => 'f', 'name' => 'Mansoura']]; }
        public function branch($branch, $actor) { $this->actor($actor); \abort_unless($branch === 'f:363', 403); return ['value' => $branch, 'id' => 363, 'kind' => 'f']; }
    }
    class PosServicePhone {
        public function customers($values, $actor) {
            \app(TakeawayAccess::class)->branch($values['branch'], $actor);
            $GLOBALS['calls'][] = ['customers', $values, $actor];
            return ['success' => true, 'items' => [['name' => 'Saved customer', 'address' => 'Saved address', 'latitude' => 31.04, 'longitude' => 31.38]]];
        }
    }
    class PhoneDelivery {
        public function settings($branch, $actor) { \app(TakeawayAccess::class)->branch($branch, $actor); $GLOBALS['calls'][] = ['settings', $branch, $actor]; return ['ready' => true, 'latitude' => 31.04, 'longitude' => 31.38, 'km_price' => '50.00']; }
        public function quote($values, $actor) { \app(TakeawayAccess::class)->branch($values['branch'], $actor); $GLOBALS['calls'][] = ['quote', $values, $actor]; return ['success' => true, 'delivery' => ['method' => 'road_osrm', 'delivery_fee' => '75.00', 'route_path' => [[31.04,31.38],[31.05,31.39]]]]; }
    }
    class PhoneMapProvider {
        public function enabled() { return true; }
        public function suggestions($values, $actor) { \app(TakeawayAccess::class)->branch($values['branch'], $actor); $GLOBALS['calls'][] = ['suggestions', $values, $actor]; return ['success' => true, 'items' => []]; }
    }
    class WhatsAppOrderWorkflow { public function available() { return true; } }
}
namespace {
    use App\Http\Controllers\Dashboard\WhatsAppOrderController;
    use App\Services\Dashboard\WhatsAppInboxAccess;
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Facade;

    $container = new Container; Container::setInstance($container); Facade::setFacadeApplication($container);
    $container->instance('config', new \Illuminate\Config\Repository(['desktop_dashboard' => ['local' => false],
        'whatsapp_orders' => ['enabled' => true, 'api_key' => 'SECRET_MUST_NOT_LEAK', 'model' => 'gpt-6-luna', 'mode' => 'auto'],
        'services' => ['maps' => ['browser_key' => 'PUBLIC_BROWSER_KEY', 'tile_url' => 'https://tiles.test/{z}/{x}/{y}.png']]]));
    $container->instance('db.schema', new class { public function hasTable($name) { return true; } });
    $container->instance('db', new class { public function connection($name = null) { return $this; } public function getSchemaBuilder() { return app('db.schema'); } });
    $factory = new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en'), $container);
    Request::macro('validate', function ($rules) use ($factory) { return $factory->make($this->all(), $rules)->validate(); });
    require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxAccess.php';
    require __DIR__ . '/../app/Http/Controllers/Dashboard/WhatsAppOrderController.php';
    $GLOBALS['sessionActor'] = new \App\Models\User;
    $GLOBALS['persistedActor'] = new \App\Models\User;
    $GLOBALS['calls'] = [];
    $controller = new WhatsAppOrderController; $access = new WhatsAppInboxAccess;
    $request = function ($values) { return Request::create('/admin/whatsapp/orders/fixture', 'GET', $values); };
    $invoke = function ($method, $values, $status = 200) use ($controller, $access, $request) {
        $response = $controller->$method($request($values), $access);
        verify($response->getStatusCode() === $status, $method . ':status');
        verify(str_contains($response->headers->get('Cache-Control'), 'no-store'), $method . ':no-store');
        verify($response->headers->get('X-Content-Type-Options') === 'nosniff', $method . ':nosniff');
        return json_decode($response->getContent(), true);
    };
    $result = $invoke('customers', ['branch' => 'f:363', 'phone' => '+201000000001', 'prefix' => true, 'untrusted' => 'discard']);
    verify($result['items'][0]['latitude'] === 31.04, 'saved pin passes through shared service');
    verify($GLOBALS['calls'][0][1] === ['branch' => 'f:363', 'phone' => '+201000000001', 'prefix' => false], 'exact phone and whitelisted input');
    verify($GLOBALS['calls'][0][2] === $GLOBALS['persistedActor'] && $GLOBALS['calls'][0][2] !== $GLOBALS['sessionActor'], 'service receives freshly loaded identity');
    $invoke('deliverySettings', ['branch' => 'f:363']);
    $result = $invoke('deliveryQuote', ['branch' => 'f:363', 'latitude' => 31.05, 'longitude' => 31.39, 'location_confirmed' => true, 'delivery_fee' => '0.01']);
    verify($result['delivery']['delivery_fee'] === '75.00' && $result['delivery']['method'] === 'road_osrm', 'server service determines delivery rate/path');
    verify(!isset($GLOBALS['calls'][2][1]['delivery_fee']), 'browser supplied fee cannot override shared quote');
    $invoke('addressSuggestions', ['branch' => 'f:363', 'query' => 'Street, area, city']);
    $count = count($GLOBALS['calls']);
    foreach ([['customers', ['branch' => 'gs:1', 'phone' => '01000000001']], ['customers', ['branch' => 'f:363', 'phone' => str_repeat('1', 31)]],
        ['addressSuggestions', ['branch' => 'f:363', 'query' => 'x']], ['deliveryQuote', ['branch' => 'f:363', 'latitude' => 95, 'longitude' => 31, 'location_confirmed' => true]],
        ['deliveryQuote', ['branch' => 'f:363', 'latitude' => 31, 'longitude' => 31, 'location_confirmed' => false]]] as [$method, $values]) $invoke($method, $values, 422);
    verify(count($GLOBALS['calls']) === $count, 'invalid inputs do not reach customer or network services');
    $invoke('customers', ['branch' => 'f:94', 'phone' => '01000000001'], 403);
    foreach (['active', 'can_checkout', 'owner_resturant_id'] as $change) {
        $GLOBALS['persistedActor'] = new \App\Models\User;
        $GLOBALS['persistedActor']->$change = $change === 'owner_resturant_id' ? 363 : false;
        $invoke('customers', ['branch' => 'f:363', 'phone' => '01000000001'], 403);
        $invoke('deliverySettings', ['branch' => 'f:363'], 403);
        $invoke('addressSuggestions', ['branch' => 'f:363', 'query' => 'Street, area, city'], 403);
        $invoke('deliveryQuote', ['branch' => 'f:363', 'latitude' => 31, 'longitude' => 31, 'location_confirmed' => true], 403);
    }
    verify(count($GLOBALS['calls']) === $count, 'revoked or branch-only sessions cannot read customer addresses or call map services');
    $GLOBALS['persistedActor'] = new \App\Models\User;
    $response = $controller->meta($access); $meta = json_decode($response->getContent(), true);
    verify($meta['maps']['browser_key'] === 'PUBLIC_BROWSER_KEY', 'only public map configuration is exposed');
    verify(!str_contains($response->getContent(), 'SECRET_MUST_NOT_LEAK'), 'OpenAI credentials never leave the controller');
    echo "WHATSAPP_ORDERS_DELIVERY_CONTROLLER_PASS shared services, exact lookup, fresh scope, bound input, server delivery rate, no-store, credential privacy\n";
}
