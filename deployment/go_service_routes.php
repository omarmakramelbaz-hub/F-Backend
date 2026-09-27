<?php
/** Check actual GO route matching without resolving unrelated controllers. */
function goServiceRouteIssues(\Illuminate\Routing\Router $router): array
{
    $expected = [
        ['GET', 'capabilities', 'capabilities'],
        ['GET', 'photos/1/0', 'photo'],
        ['POST', 'paymob/webhook', 'webhook'],
        ['GET', 'payment-return', 'paymentReturn'],
        ['GET', 'jobs', 'index'],
        ['POST', 'jobs', 'store'],
        ['GET', 'jobs/1', 'show'],
        ['POST', 'jobs/1/offers', 'quote'],
        ['POST', 'jobs/1/offers/1/accept', 'accept'],
        ['POST', 'jobs/1/offers/1/reject', 'reject'],
        ['POST', 'jobs/1/skip', 'skip'],
        ['POST', 'jobs/1/status', 'status'],
        ['POST', 'jobs/1/checkout', 'checkout'],
    ];
    $issues = [];
    foreach ($expected as [$method, $path, $action]) {
        $uri = '/api/go-services/'.$path;
        try {
            $route = $router->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $issues[] = $method.' '.$uri.' is not registered.';
            continue;
        }
        $controller = \App\Http\Controllers\Api\V1\GoServiceMarketplaceController::class;
        if ($route->getActionName() !== $controller.'@'.$action) {
            $issues[] = $method.' '.$uri.' targets the wrong action.';
        }
        if (str_starts_with($path, 'jobs')) {
            foreach (['auth:api', 'app.scope', 'custom.jwt'] as $middleware) {
                if (!in_array($middleware, $route->middleware(), true)) {
                    $issues[] = $method.' '.$uri.' is missing '.$middleware.'.';
                }
            }
        }
    }
    return $issues;
}
