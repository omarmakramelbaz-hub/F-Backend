<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One request for the dashboard's repeated checks (new orders, print jobs, late deliveries, messages, pending
 * expenses, the home numbers): each check runs here exactly as its own request would, through the same middleware,
 * permissions and limits, and its answer comes back unchanged. The page (dashboard-pulse.js) falls back to the checks'
 * own requests whenever this one fails.
 */
class DashboardPulseController extends Controller
{
    /** The checks one pulse may carry, by route name: read-only GET addresses polled by every dashboard window. */
    public const ROUTES = ['getOrders', 'phone-orders.print-jobs', 'branch-shift-print.jobs', 'app-order-print.jobs', 'phone-orders.late',
        'dashboard-inbox.notifications', 'branch-expenses.pending-count', 'dashboard.overview'];
    public const MAX = 8;

    public function index(Request $request)
    {
        $wanted = $request->query('r');
        abort_unless(is_array($wanted) && $wanted !== [] && count($wanted) <= self::MAX, 422, 'Invalid pulse.');
        $kernel = app(HttpKernel::class);
        $answers = [];
        try {
            foreach (array_values($wanted) as $check) $answers[] = $this->answer($kernel, $request, is_array($check) ? $check : []);
        } finally {
            // Every check ran as its own request: the rest of this one is this request again.
            app()->instance('request', $request);
            Facade::clearResolvedInstance('request');
        }
        // JSON sent as plain text: the desktop program keeps a copy of every JSON answer for offline use, and a copy of a
        // pulse would answer later pulses offline with old checks. Plain text is never kept, so offline the pulse fails
        // and each check goes as its own request, which the program answers from the device.
        return response(json_encode(['answers' => $answers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** One check: ['u' => '/admin/...?query', 'a' => Accept header, 'x' => '1' when sent as XMLHttpRequest]. */
    private function answer(HttpKernel $kernel, Request $outer, array $check): array
    {
        $target = is_string($check['u'] ?? null) ? $check['u'] : '';
        if (strlen($target) > 1000 || ! preg_match('#^/admin/[A-Za-z0-9/_.\-]+(\?[^\#]*)?$#D', $target)) return ['status' => 400];
        $path = (string) parse_url($target, PHP_URL_PATH);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $server = array_diff_key($outer->server->all(), array_flip(['REQUEST_URI', 'QUERY_STRING', 'CONTENT_TYPE', 'CONTENT_LENGTH',
            'HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH', 'HTTP_ACCEPT', 'HTTP_X_REQUESTED_WITH']));
        $sub = Request::create($path, 'GET', $query, $this->rawCookies($outer), [], $server);
        $sub->headers->set('Accept', is_string($check['a'] ?? null) && $check['a'] !== '' ? substr($check['a'], 0, 200) : '*/*');
        if (($check['x'] ?? null) === '1') $sub->headers->set('X-Requested-With', 'XMLHttpRequest');
        try {
            $route = app('router')->getRoutes()->match($sub);
        } catch (HttpExceptionInterface $error) {
            return ['status' => $error->getStatusCode()];
        }
        if (! in_array($route->getName(), self::ROUTES, true)) return ['status' => 400];
        $response = $kernel->handle($sub);
        $answer = ['status' => $response->getStatusCode(), 'type' => (string) $response->headers->get('Content-Type', '')];
        if ($response->isRedirection()) return $answer + ['location' => (string) $response->headers->get('Location', '')];
        $body = $response->getContent();
        return $answer + ['body' => $body === false ? '' : $body];
    }

    /** The cookies as the browser sent them (still encrypted), so each check reads the session like its own request. */
    private function rawCookies(Request $outer): array
    {
        $cookies = [];
        foreach (explode(';', (string) $outer->headers->get('Cookie', '')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($name !== '' && $value !== null) $cookies[urldecode($name)] = urldecode($value);
        }
        return $cookies;
    }
}
