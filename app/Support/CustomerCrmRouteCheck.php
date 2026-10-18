<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/** Inspect only customer CRM entry points; never dispatch a request or an action. */
final class CustomerCrmRouteCheck
{
    public const CONTRACTS = [
        'customers.index'=>['GET', 'admin/customers', 'BranchOperationsController', 'index'],
        'customers.data'=>['GET', 'admin/customers/data', 'BranchOperationsController', 'data'],
        'customers.save'=>['POST', 'admin/customers/save', 'BranchOperationsController', 'save'],
        'customers.directory-save'=>['POST', 'admin/customers/directory-save', 'BranchOperationsController', 'directorySave'],
        'customers.consent'=>['POST', 'admin/customers/consent', 'BranchOperationsController', 'customerConsent'],
        'customers.messaging-status'=>['GET', 'admin/customers/messaging-status', 'BranchOperationsController', 'messagingStatus'],
        'customers.messaging-configure'=>['POST', 'admin/customers/messaging-configure', 'BranchOperationsController', 'configureMessaging'],
        'customers.feedback-template-prepare'=>['POST', 'admin/customers/feedback-template-prepare', 'BranchOperationsController', 'prepareFeedbackTemplate'],
        'customers.marketing-template-prepare'=>['POST', 'admin/customers/marketing-template-prepare', 'BranchOperationsController', 'prepareMarketingTemplate'],
        'customers.campaign-queue'=>['POST', 'admin/customers/campaign-queue', 'BranchOperationsController', 'queueCampaign'],
        'branch-operations.recover'=>['GET', 'admin/branch-operations/recover', 'BranchOperationsController', 'recover'],
        'takeaway.checkout'=>['POST', 'admin/takeaway/checkout', 'TakeawayController', 'checkout'],
        'dining.settle'=>['POST', 'admin/dining/tickets/{id}/settle', 'DineInController', 'settle'],
        'phone-orders.settle'=>['POST', 'admin/phone-orders/tickets/{id}/settle', 'PhoneOrdersController', 'settle'],
        'customer-crm.app-consent'=>['POST', 'api/user/whatsapp-consent', 'App\\Http\\Controllers\\Api\\V1\\User\\CustomerConsentController', 'update'],
    ];

    public static function verify(Router $router): array
    {
        $routes = $router->getRoutes();
        $uris = array_column(self::CONTRACTS, 1);
        $seen = [];
        // Filtering precedes gatherMiddleware: unrelated legacy controllers are not resolved.
        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();
            $uri = trim($route->uri(), '/');
            $own = $uri === 'admin/customers' || str_starts_with($uri, 'admin/customers/')
                || str_starts_with((string)$name, 'customers.') || isset(self::CONTRACTS[$name ?? '']) || in_array($uri, $uris, true);
            if (!$own) continue;
            self::require(is_string($name) && isset(self::CONTRACTS[$name]), 'CRM_ROUTE_UNEXPECTED');
            self::require(!isset($seen[$name]), 'CRM_ROUTE_DUPLICATE', $name);
            $seen[$name] = true;
        }
        foreach (self::CONTRACTS as $name=>[$method, $uri, $shortController, $action]) {
            $route = $routes->getByName($name);
            self::require($route !== null && isset($seen[$name]), 'CRM_ROUTE_MISSING', $name);
            self::require(trim($route->uri(), '/') === $uri && $route->domain() === null, 'CRM_ROUTE_URI', $name);
            $expectedMethods = $method === 'GET' ? ['GET', 'HEAD'] : [$method];
            $actualMethods = $route->methods();
            sort($expectedMethods); sort($actualMethods);
            self::require($actualMethods === $expectedMethods, 'CRM_ROUTE_METHODS', $name);
            $controller = str_contains($shortController, '\\') ? $shortController : 'App\\Http\\Controllers\\Dashboard\\'.$shortController;
            self::require($route->getActionName() === $controller.'@'.$action && $route->getAction('uses') === $controller.'@'.$action,
                'CRM_ROUTE_ACTION', $name);
            self::require(class_exists($controller) && (new ReflectionClass($controller))->isInstantiable()
                && method_exists($controller, $action) && (new ReflectionMethod($controller, $action))->isPublic(),
                'CRM_ROUTE_CONTROLLER_UNAVAILABLE', $name);
            self::require($route->excludedMiddleware() === [], 'CRM_ROUTE_MIDDLEWARE_EXCLUDED', $name);
            $middleware = $route->gatherMiddleware();
            $api = str_starts_with($uri, 'api/');
            $requiredMiddleware = $api ? ['api', 'CheckLang', 'auth:api', 'app.scope', 'custom.jwt'] : ['web', 'lang', 'IsAdmin'];
            foreach ($requiredMiddleware as $required) {
                self::require(in_array($required, $middleware, true), 'CRM_ROUTE_AUTH_MIDDLEWARE', $name);
            }
            self::require(is_callable([$route->getController(), $action]), 'CRM_ROUTE_CONTROLLER_UNAVAILABLE', $name);
            $effective = $router->gatherRouteMiddleware($route);
            $requiredEffective = $api ? ['App\\Http\\Middleware\\Authenticate:api', 'App\\Http\\Middleware\\CheckLang',
                'App\\Http\\Middleware\\AppScopeGuard', 'App\\Http\\Middleware\\CustomJwtAuth'] : ['App\\Http\\Middleware\\IsAdmin', 'App\\Http\\Middleware\\lang',
                'Illuminate\\Session\\Middleware\\StartSession', 'App\\Http\\Middleware\\VerifyCsrfToken'];
            foreach ($requiredEffective as $required) {
                self::require(in_array($required, $effective, true), 'CRM_ROUTE_EFFECTIVE_MIDDLEWARE', $name);
            }
            if (str_contains($uri, '{id}')) self::require(($route->wheres['id'] ?? null) === '[0-9]+', 'CRM_ROUTE_PARAMETER', $name);
            $path = str_replace('{id}', '991771', $uri);
            $matched = $routes->match(Request::create('/'.$path, $method));
            self::require($matched->getName() === $name && $matched === $route, 'CRM_ROUTE_SHADOWED', $name);
        }
        return ['ok'=>true, 'verified_routes'=>count(self::CONTRACTS), 'requests_dispatched'=>0];
    }

    public static function failure(Throwable $error): array
    {
        $reason = $error instanceof RuntimeException && preg_match('/^CRM_ROUTE_[A-Z_]+(?::[a-z0-9.-]+)?$/D', $error->getMessage())
            ? $error->getMessage() : 'CRM_ROUTE_VERIFICATION_FAILED';
        // Exception messages/traces may contain private application data; print only a contract reason and class.
        return ['ok'=>false, 'reason'=>$reason, 'error_type'=>get_class($error), 'requests_dispatched'=>0];
    }

    private static function require(bool $condition, string $reason, ?string $route = null): void
    {
        if (!$condition) throw new RuntimeException($reason.($route !== null ? ':'.$route : ''));
    }
}
