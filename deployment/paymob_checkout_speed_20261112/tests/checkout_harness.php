<?php
// All HTTP, authentication, models and responses are synthetic. No network calls.
namespace Illuminate\Http\Client { class ConnectionException extends \Exception {} }
namespace Illuminate\Support\Facades {
    class Auth { public static $user; public static function user() { return self::$user; } }
    class Http {
        public static $handler;
        public static $calls = [];
        public static function withHeaders($headers) { return new FakeRequest($headers); }
    }
    class FakeRequest {
        private $headers;
        private $options;
        public function __construct($headers) { $this->headers = $headers; }
        public function withOptions($options) { $this->options = $options; return $this; }
        public function post($url, $payload) {
            Http::$calls[] = ['url' => $url, 'payload' => $payload, 'headers' => $this->headers, 'options' => $this->options];
            return (Http::$handler)($payload);
        }
    }
}
namespace App\Models {
    class Order {
        public static $order;
        public static function findOrFail($id) { return self::$order; }
    }
    class Payment {
        public static $created = [];
        public static $fail = false;
        public static function create($data) {
            if (self::$fail) throw new \RuntimeException('synthetic storage failure');
            self::$created[] = $data;
            return (object) $data;
        }
    }
}
namespace {
    require dirname(__DIR__, 3) . '/app/Http/Traits/ApiResponses.php';
    class FakeGatewayResponse {
        private $data;
        private $code;
        public function __construct($data, $code = 201) { $this->data = $data; $this->code = $code; }
        public function successful() { return $this->code >= 200 && $this->code < 300; }
        public function status() { return $this->code; }
        public function json() { return $this->data; }
    }
    class FakeResponse {
        public $body;
        public $code;
        public function json($body, $code = 200) { $this->body = $body; $this->code = $code; return $this; }
    }
    class FakeRedirect {
        public $url;
        public $flash;
        public function __construct($url) { $this->url = $url; }
        public function back() { return $this; }
        public function with($flash) { $this->flash = $flash; return $this; }
    }
    class FakeAuth {
        public static $api = true;
        public function check() { return self::$api; }
    }
    class FakeApp { public function getLocale() { return 'ar'; } }
    function response() { return new FakeResponse(); }
    function auth($guard) { return new FakeAuth(); }
    function app() { return new FakeApp(); }
    function config($key) { return $key === 'app.url' ? 'https://synthetic.example' : null; }
    function redirect($url = null) { return new FakeRedirect($url); }
    function __($key) { return $key; }

    $template = file_get_contents(dirname(__DIR__) . '/checking_out.php.template');
    $template = str_replace(['__PAYMOB_AUTHORIZATION__', '__PAYMOB_PUBLIC_KEY__'],
        ['Token synthetic_secret_only', 'synthetic_public_only'], $template);
    eval('namespace App\\Http\\Controllers\\Payment; use Illuminate\\Support\\Facades\\Auth; '
        . 'use Illuminate\\Support\\Facades\\Http; use App\\Models\\Order; use App\\Models\\Payment; '
        . 'class CheckoutUnderTest { use \\App\\Http\\Traits\\ApiResponses; ' . $template . '}');

    function check($condition, $name) {
        if (!$condition) throw new \RuntimeException($name);
    }
    function validPayload($payload) {
        return ['client_secret' => 'synthetic_checkout_secret', 'intention_order_id' => 765,
            'intention_detail' => ['amount' => $payload['amount'], 'currency' => 'EGP']];
    }
    function resetScenario($type = 'wallet') {
        \Illuminate\Support\Facades\Auth::$user = (object) ['id' => 5, 'name' => 'Synthetic Customer', 'email' => 'synthetic@example.test', 'mobile' => '+201000000000'];
        \App\Models\Order::$order = (object) ['id' => 42, 'user_id' => 5, 'grand_total' => '50.00', 'type' => $type];
        \App\Models\Payment::$created = [];
        \App\Models\Payment::$fail = false;
        \Illuminate\Support\Facades\Http::$calls = [];
        \Illuminate\Support\Facades\Http::$handler = function ($payload) { return new FakeGatewayResponse(validPayload($payload)); };
        FakeAuth::$api = true;
    }
    function checkout($integration = 123) {
        return (new \App\Http\Controllers\Payment\CheckoutUnderTest())->checkingOut('synthetic', $integration, 42, null);
    }
    $tests = [];
    $tests['single intention preserves card and wallet callbacks'] = function () {
        foreach (['wallet' => 'wallet-topup', 'current' => 'order-payments', 'shipping' => null] as $type => $route) {
            resetScenario($type);
            $response = checkout();
            $calls = \Illuminate\Support\Facades\Http::$calls;
            check(count($calls) === 1, 'one POST only');
            check($calls[0]['url'] === 'https://accept.paymob.com/v1/intention/', 'intention endpoint');
            check($calls[0]['options'] === ['connect_timeout' => 3, 'timeout' => 15, 'allow_redirects' => false], 'finite timeouts and no redirects');
            check($calls[0]['headers']['Authorization'] === 'Token synthetic_secret_only', 'existing authorization preserved');
            check($calls[0]['payload']['amount'] === 5000 && $calls[0]['payload']['currency'] === 'EGP', 'exact server amount');
            check($calls[0]['payload']['payment_methods'] === [123], 'integration preserved');
            check($calls[0]['payload']['metadata']['merchant_order_id'] === 42 && !isset($calls[0]['payload']['special_reference']), 'correlation format unchanged');
            check($response->code === 200 && $response->body['data']['order_id'] === 42, 'typed checkout result');
            check(count(\App\Models\Payment::$created) === 1 && \App\Models\Payment::$created[0]['total_price'] === '50.00', 'one unpaid payment recorded');
            check(!isset(\App\Models\Payment::$created[0]['status']), 'creation never settles payment');
            if ($route !== null) {
                check($calls[0]['payload']['notification_url'] === 'https://synthetic.example/api/' . $route . '/paymob/notify', 'notification preserved');
                check($calls[0]['payload']['redirection_url'] === 'https://synthetic.example/api/' . $route . '/paymob/return', 'return preserved');
            } else check(!isset($calls[0]['payload']['notification_url']), 'shipping unchanged');
        }
    };
    $tests['network failure has uncertain outcome and no replay'] = function () {
        resetScenario();
        \Illuminate\Support\Facades\Http::$handler = function () { throw new \Illuminate\Http\Client\ConnectionException('synthetic'); };
        $response = checkout();
        check($response->code === 504 && $response->body['checkout_outcome_uncertain'] === true, 'timeout uncertain');
        check(count(\Illuminate\Support\Facades\Http::$calls) === 1 && count(\App\Models\Payment::$created) === 0, 'no retry or false record');
        check(strpos(json_encode($response->body), 'synthetic_secret_only') === false, 'no credential in response');
    };
    $tests['gateway statuses do not create payments'] = function () {
        foreach ([401, 404, 500] as $code) {
            resetScenario();
            \Illuminate\Support\Facades\Http::$handler = function () use ($code) { return new FakeGatewayResponse(['detail' => 'synthetic internal body'], $code); };
            $response = checkout();
            check($response->code === 502 && count(\App\Models\Payment::$created) === 0, 'gateway error guarded');
            check($response->body['checkout_outcome_uncertain'] === ($code >= 500), 'upstream outcome classification');
            check(strpos(json_encode($response->body), 'synthetic internal body') === false, 'upstream body not exposed');
        }
    };
    $tests['malformed and mismatching responses fail closed'] = function () {
        foreach (['scalar', 'missing_secret', 'missing_order', 'wrong_amount', 'fractional_amount',
            'boolean_amount', 'malformed_string_amount', 'infinite_amount', 'nan_amount',
            'wrong_currency', 'zero_order', 'array_secret'] as $case) {
            resetScenario();
            \Illuminate\Support\Facades\Http::$handler = function ($payload) use ($case) {
                $data = validPayload($payload);
                if ($case === 'scalar') $data = 42;
                if ($case === 'missing_secret') unset($data['client_secret']);
                if ($case === 'missing_order') unset($data['intention_order_id']);
                if ($case === 'wrong_amount') $data['intention_detail']['amount'] = 5001;
                if ($case === 'fractional_amount') $data['intention_detail']['amount'] = 5000.5;
                if ($case === 'boolean_amount') $data['intention_detail']['amount'] = true;
                if ($case === 'malformed_string_amount') $data['intention_detail']['amount'] = '5000.0';
                if ($case === 'infinite_amount') $data['intention_detail']['amount'] = INF;
                if ($case === 'nan_amount') $data['intention_detail']['amount'] = NAN;
                if ($case === 'wrong_currency') $data['intention_detail']['currency'] = 'USD';
                if ($case === 'zero_order') $data['intention_order_id'] = 0;
                if ($case === 'array_secret') $data['client_secret'] = [];
                return new FakeGatewayResponse($data);
            };
            $response = checkout();
            check($response->code === 502 && $response->body['checkout_outcome_uncertain'] === true, 'invalid response uncertain: ' . $case);
            check(count(\App\Models\Payment::$created) === 0, 'no payment from bad response: ' . $case);
        }
    };
    $tests['invalid request facts fail before network'] = function () {
        foreach (['bad_integration', 'zero_amount', 'negative_amount', 'invalid_amount', 'no_user'] as $case) {
            resetScenario();
            $integration = $case === 'bad_integration' ? 'wrong' : 123;
            if ($case === 'zero_amount') \App\Models\Order::$order->grand_total = '0.00';
            if ($case === 'negative_amount') \App\Models\Order::$order->grand_total = '-50.00';
            if ($case === 'invalid_amount') \App\Models\Order::$order->grand_total = 'broken';
            if ($case === 'no_user') \Illuminate\Support\Facades\Auth::$user = null;
            $response = checkout($integration);
            check(in_array($response->code, [401, 422], true), 'validation response');
            check(count(\Illuminate\Support\Facades\Http::$calls) === 0 && count(\App\Models\Payment::$created) === 0, 'no network for invalid facts');
        }
    };
    $tests['record failure remains uncertain'] = function () {
        resetScenario(); \App\Models\Payment::$fail = true;
        $response = checkout();
        check($response->code === 503 && $response->body['checkout_outcome_uncertain'] === true, 'storage failure uncertain');
        check(count(\Illuminate\Support\Facades\Http::$calls) === 1, 'storage failure no replay');
    };
    $tests['decimal amounts preserve exact cents and two-place records'] = function () {
        $cases = [
            ['0.01', 1, '0.01'], ['1.01', 101, '1.01'], ['50', 5000, '50.00'],
            ['50.1', 5010, '50.10'], ['0000000050.01', 5001, '50.01'],
            ["\t50.09 \n", 5009, '50.09'], [50, 5000, '50.00'], [50.25, 5025, '50.25'],
            ['9999999999.99', 999999999999, '9999999999.99'],
        ];
        foreach ($cases as [$amount, $cents, $decimal]) {
            resetScenario(); \App\Models\Order::$order->grand_total = $amount;
            $response = checkout();
            check($response->code === 200, 'valid decimal accepted');
            check(\Illuminate\Support\Facades\Http::$calls[0]['payload']['amount'] === $cents, 'exact integer cents');
            check(\App\Models\Payment::$created[0]['total_price'] === $decimal, 'exact two-place decimal');
        }
    };
    $tests['exact integral JSON float cents are accepted without changing stored money'] = function () {
        foreach (['50.00' => 5000.0, '9999999999.99' => 999999999999.0] as $amount => $gatewayAmount) {
            resetScenario(); \App\Models\Order::$order->grand_total = $amount;
            \Illuminate\Support\Facades\Http::$handler = function ($payload) use ($gatewayAmount) {
                $data = validPayload($payload); $data['intention_detail']['amount'] = $gatewayAmount;
                return new FakeGatewayResponse($data);
            };
            $response = checkout();
            check($response->code === 200, 'exact integral gateway float accepted');
            check(is_int(\Illuminate\Support\Facades\Http::$calls[0]['payload']['amount']), 'request cents remain integer');
            check(\App\Models\Payment::$created[0]['total_price'] === $amount, 'stored amount remains exact decimal');
        }
    };
    $tests['invalid decimals never reach the gateway'] = function () {
        foreach (['0', '-0.01', '+1.00', '50.001', '1e2', 'NaN', 'INF', '1,00', '.50', '50.',
            '10000000000.00', '00000000001.00', '', null, [], new \stdClass(), true, false] as $amount) {
            resetScenario(); \App\Models\Order::$order->grand_total = $amount;
            $response = checkout();
            check($response->code === 422, 'invalid decimal rejected');
            check(count(\Illuminate\Support\Facades\Http::$calls) === 0 && count(\App\Models\Payment::$created) === 0, 'invalid decimal has no gateway or record effect');
        }
    };
    $tests['shared money utility is not required or consulted'] = function () {
        check(!class_exists('App\\Services\\GoServices\\Money', false), 'utility initially absent');
        resetScenario(); check(checkout()->code === 200, 'works without utility');
        eval('namespace App\\Services\\GoServices; class Money { '
            . 'public static function minor($value) { throw new \\RuntimeException("foreign utility must not run"); } '
            . 'public static function decimal($value) { throw new \\RuntimeException("foreign utility must not run"); } }');
        resetScenario(); check(checkout()->code === 200, 'foreign utility never consulted');
    };
    $tests['web checkout preserves redirect behavior'] = function () {
        resetScenario(); FakeAuth::$api = false;
        $response = checkout();
        check($response instanceof FakeRedirect && strpos($response->url, 'https://accept.paymob.com/unifiedcheckout/') === 0, 'web redirect remains');
    };
    foreach ($tests as $name => $run) {
        $run();
        echo "PASS: " . $name . "\n";
    }
    echo 'PASS: ' . count($tests) . " PHP checkout scenarios\n";
}
