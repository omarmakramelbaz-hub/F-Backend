<?php

/** Inspect the actual feature routes without resolving unrelated application controllers. */
final class MetaMessagingRouteCheck
{
    public const CONTRACTS = [
        'meta-messaging.webhook.verify' => ['GET', 'api/meta/messaging/webhook', 'Api\\V1\\MetaMessagingWebhookController', 'verify'],
        'meta-messaging.webhook.receive' => ['POST', 'api/meta/messaging/webhook', 'Api\\V1\\MetaMessagingWebhookController', 'receive'],
        'meta-replies.temporary-media' => ['GET', 'api/meta/messaging/media/{ticket}', 'Dashboard\\MetaReplyController', 'temporaryMedia'],
        'meta-inbox.index' => ['GET', 'admin/meta', 'Dashboard\\MetaInboxController', 'index'],
        'meta-inbox.conversations' => ['GET', 'admin/meta/conversations', 'Dashboard\\MetaInboxController', 'conversations'],
        'meta-inbox.messages' => ['GET', 'admin/meta/conversations/{conversation}/messages', 'Dashboard\\MetaInboxController', 'messages'],
        'meta-inbox.media' => ['GET', 'admin/meta/conversations/{conversation}/messages/{message}/media/{index}', 'Dashboard\\MetaMessageActionsController', 'media'],
        'meta-inbox.hide' => ['POST', 'admin/meta/conversations/{conversation}/messages/{message}/hide', 'Dashboard\\MetaMessageActionsController', 'hide'],
        'meta-orders.meta' => ['GET', 'admin/meta/orders/meta', 'Dashboard\\MetaOrderController', 'meta'],
        'meta-orders.catalog' => ['GET', 'admin/meta/orders/catalog', 'Dashboard\\MetaOrderController', 'catalog'],
        'meta-orders.customers' => ['GET', 'admin/meta/orders/customers', 'Dashboard\\MetaOrderController', 'customers'],
        'meta-orders.delivery-settings' => ['GET', 'admin/meta/orders/delivery-settings', 'Dashboard\\MetaOrderController', 'deliverySettings'],
        'meta-orders.address-suggestions' => ['GET', 'admin/meta/orders/address-suggestions', 'Dashboard\\MetaOrderController', 'addressSuggestions'],
        'meta-orders.delivery-quote' => ['POST', 'admin/meta/orders/delivery-quote', 'Dashboard\\MetaOrderController', 'deliveryQuote'],
        'meta-orders.state' => ['GET', 'admin/meta/conversations/{conversation}/orders', 'Dashboard\\MetaOrderController', 'state'],
        'meta-orders.analyze' => ['POST', 'admin/meta/conversations/{conversation}/orders/analyze', 'Dashboard\\MetaOrderController', 'analyze'],
        'meta-orders.quote' => ['POST', 'admin/meta/orders/{draft}/quote', 'Dashboard\\MetaOrderController', 'quote'],
        'meta-orders.dispatch' => ['POST', 'admin/meta/orders/{draft}/dispatch', 'Dashboard\\MetaOrderController', 'submitOrder'],
        'meta-inbox.unread' => ['GET', 'admin/meta/unread', 'Dashboard\\MetaInboxReadController', 'unread'],
        'meta-inbox.read' => ['POST', 'admin/meta/conversations/{conversation}/read', 'Dashboard\\MetaInboxReadController', 'read'],
        'meta-replies.state' => ['GET', 'admin/meta/conversations/{conversation}/reply-state', 'Dashboard\\MetaReplyController', 'state'],
        'meta-replies.send' => ['POST', 'admin/meta/conversations/{conversation}/replies', 'Dashboard\\MetaReplyController', 'send'],
        'meta-replies.voice' => ['POST', 'admin/meta/conversations/{conversation}/voice', 'Dashboard\\MetaReplyController', 'voice'],
        'meta-replies.attachment' => ['POST', 'admin/meta/conversations/{conversation}/attachments', 'Dashboard\\MetaReplyController', 'attachment'],
        'meta-replies.forward' => ['POST', 'admin/meta/conversations/{conversation}/forward', 'Dashboard\\MetaReplyController', 'forward'],
    ];

    public static function verify($router): array
    {
        $routes = $router->getRoutes();
        // A newly added feature endpoint must declare its complete authentication and HTTP contract here.
        $seen = [];
        foreach ($routes->getRoutes() as $route) {
            $uri = trim($route->uri(), '/');
            if ($uri === 'admin/meta' || strpos($uri, 'admin/meta/') === 0 || strpos($uri, 'api/meta/messaging/') === 0) {
                self::assertContract(is_string($route->getName()) && isset(self::CONTRACTS[$route->getName()]),
                    'UNEXPECTED_META_ROUTE', 'UNREGISTERED_META_ROUTE');
                self::assertContract(!isset($seen[$route->getName()]), 'DUPLICATE_META_ROUTE', $route->getName());
                $seen[$route->getName()] = true;
            }
        }
        foreach (self::CONTRACTS as $name => [$method, $uri, $controller, $action]) {
            $route = $routes->getByName($name);
            self::assertContract($route !== null, 'MISSING_ROUTE', $name);
            self::assertContract(trim($route->uri(), '/') === $uri && in_array($method, $route->methods(), true), 'ROUTE_CONTRACT', $name);
            $expectedMethods = $method === 'GET' ? ['GET', 'HEAD'] : [$method];
            $actualMethods = $route->methods(); sort($expectedMethods); sort($actualMethods);
            self::assertContract($actualMethods === $expectedMethods, 'ROUTE_METHODS', $name);
            foreach (['conversation', 'draft', 'message', 'index'] as $parameter) {
                if (strpos($uri, '{'.$parameter.'}') !== false)
                    self::assertContract(($route->wheres[$parameter] ?? null) === '[0-9]+', 'ROUTE_PARAMETER_CONSTRAINT', $name);
            }
            if ($name === 'meta-replies.temporary-media') self::assertContract(($route->wheres['ticket'] ?? null)
                === '[0-9a-f]{48}\\.[1-9][0-9]{9}\\.[0-9a-f]{64}', 'ROUTE_PARAMETER_CONSTRAINT', $name);
            $controller = 'App\\Http\\Controllers\\'.$controller;
            self::assertContract($route->getActionName() === $controller.'@'.$action
                && $route->getAction('uses') === $controller.'@'.$action, 'CONTROLLER_CONTRACT', $name);
            self::assertContract(class_exists($controller) && method_exists($controller, $action)
                && (new ReflectionMethod($controller, $action))->isPublic(), 'CONTROLLER_UNAVAILABLE', $name);
            // Only Meta controllers are inspected; legacy broken routes are never resolved.
            $middleware = $route->gatherMiddleware();
            if (strpos($uri, 'api/meta/messaging/') === 0) {
                self::assertContract(in_array('api', $middleware, true) && !in_array('IsAdmin', $middleware, true)
                    && !in_array('web', $middleware, true), 'CALLBACK_MIDDLEWARE', $name);
                self::assertContract(in_array('App\\Http\\Middleware\\EnsureGoSchema', $route->excludedMiddleware(), true), 'CALLBACK_SCHEMA_EXCLUSION', $name);
            } else {
                self::assertContract(in_array('web', $middleware, true) && in_array('lang', $middleware, true)
                    && in_array('IsAdmin', $middleware, true), 'ADMIN_MIDDLEWARE', $name);
                self::assertContract(!array_intersect($route->excludedMiddleware(), ['web', 'lang', 'IsAdmin',
                    'App\\Http\\Middleware\\IsAdmin', 'App\\Http\\Middleware\\lang',
                    'App\\Http\\Middleware\\VerifyCsrfToken', 'Illuminate\\Session\\Middleware\\StartSession']), 'ADMIN_MIDDLEWARE_EXCLUDED', $name);
            }
            $path = preg_replace('/\{(?:conversation|draft|message|index)\}/', '1', $uri);
            $path = str_replace('{ticket}', str_repeat('a', 48).'.'.(time()+600).'.'.str_repeat('b', 64), $path);
            $matched = $routes->match(Illuminate\Http\Request::create('/'.$path, $method));
            self::assertContract($matched->getName() === $name, 'ROUTE_SHADOWED', $name);
        }
        return ['ok' => true, 'verified_routes' => count(self::CONTRACTS)];
    }

    private static function assertContract(bool $condition, string $reason, string $route): void
    {
        if (!$condition) throw new MetaMessagingRouteCheckFailure($reason, $route);
    }

    public static function failure(Throwable $error, string $phase): array
    {
        $result = ['ok' => false, 'phase' => $phase, 'error_type' => get_class($error)];
        if ($error instanceof MetaMessagingRouteCheckFailure) {
            $result['reason'] = $error->reason;
            $result['route'] = $error->route;
        } else {
            $result['reason'] = 'APPLICATION_BOOTSTRAP_OR_ROUTE_ERROR';
            // Print only a missing PHP class identifier, never exception text, keys or traces.
            if (preg_match('/(?:Class|Target class) ["\[]([A-Za-z_][A-Za-z0-9_\\\\]{0,255})["\]] (?:not found|does not exist)/', $error->getMessage(), $match)) {
                $result['missing_class'] = $match[1];
            }
        }
        return $result;
    }

    public static function run(): int
    {
        ini_set('display_errors', '0');
        $phase = 'bootstrap';
        try {
            require getcwd().'/vendor/autoload.php';
            $app = require getcwd().'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $phase = 'routes';
            $result = self::verify($app->make('router'));
            echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
            return 0;
        } catch (Throwable $error) {
            echo json_encode(self::failure($error, $phase), JSON_THROW_ON_ERROR).PHP_EOL;
            return 1;
        }
    }
}

final class MetaMessagingRouteCheckFailure extends RuntimeException
{
    public string $reason;
    public string $route;
    public function __construct(string $reason, string $route)
    {
        parent::__construct($reason);
        $this->reason = $reason;
        $this->route = $route;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(MetaMessagingRouteCheck::run());
