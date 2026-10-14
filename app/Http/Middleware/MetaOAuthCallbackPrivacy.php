<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Keep OAuth callback inputs out of views, debug collectors, and redirect URLs. */
class MetaOAuthCallbackPrivacy
{
    private ?array $payload = null;

    public function handle(Request $request, Closure $next)
    {
        $callback = $request->routeIs('meta-connection.callback');
        if ($callback) {
            $query = $request->query->all();
            $this->payload = $this->callbackInputs($query, (string) $request->server->get('QUERY_STRING', ''));
            $path = $request->getPathInfo();
            $server = $request->server->all();
            $server['QUERY_STRING'] = '';
            $server['REQUEST_URI'] = $path;
            foreach (['HTTP_REFERER', 'REDIRECT_QUERY_STRING', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL',
                'HTTP_X_FORWARDED_URI', 'HTTP_X_ORIGINAL_URI'] as $key) unset($server[$key]);
            // initialize() also clears Symfony's cached URI and query-string properties.
            $request->initialize([], $request->request->all(), $request->attributes->all(),
                $request->cookies->all(), $request->files->all(), $server, $request->getContent());
            $request->headers->remove('referer');
            $_GET = [];
            $_REQUEST = [];
            $_SERVER['QUERY_STRING'] = '';
            $_SERVER['REQUEST_URI'] = $path;
            foreach (['HTTP_REFERER', 'REDIRECT_QUERY_STRING', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL',
                'HTTP_X_FORWARDED_URI', 'HTTP_X_ORIGINAL_URI'] as $key) unset($_SERVER[$key]);
            unset($query, $server);
            app()->instance(self::class, $this);
        }

        // Scrub first, then disable optional collectors before invoking application code.
        try {
            config(['debugbar.enabled' => false]);
            if (app()->bound('debugbar')) {
                $debugbar = app('debugbar');
                if (is_object($debugbar) && method_exists($debugbar, 'disable')) $debugbar->disable();
            }
        } catch (\Throwable $error) {
            // Debug tooling is optional; never report its exception alongside OAuth inputs.
        }

        try {
            try {
                $response = $next($request);
            } catch (\Throwable $error) {
                if (!$callback) throw $error;
                // Never render or log an unexpected callback exception at a credential-bearing browser URL.
                $response = new RedirectResponse('/admin/meta/connection', 303);
            }
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            return $response;
        } finally {
            $this->payload = null;
        }
    }

    /** Controller takes ownership once; this object never exposes raw callback data to views. */
    public function consume(): array
    {
        $payload = $this->payload ?? ['invalid' => true];
        $this->payload = null;
        return $payload;
    }

    private function callbackInputs(array $query, string $rawQuery): array
    {
        if (strlen($rawQuery) > 12000) return ['invalid' => true];
        $seen = [];
        foreach (explode('&', $rawQuery) as $part) {
            $key = urldecode(explode('=', $part, 2)[0]);
            if (!in_array($key, ['state', 'code', 'error'], true)) continue;
            if (isset($seen[$key])) return ['invalid' => true];
            $seen[$key] = true;
        }
        $state = $query['state'] ?? null;
        $code = $query['code'] ?? null;
        $error = $query['error'] ?? null;
        if (!is_string($state) || !preg_match('/\A[a-f0-9]{64}\z/', $state)
            || ($code !== null && (!is_string($code) || strlen($code) > 4096 || $code === ''))
            || ($error !== null && (!is_string($error) || strlen($error) > 128))) {
            return ['invalid' => true];
        }
        return ['invalid' => false, 'state' => $state, 'code' => $code,
            'cancelled' => $error !== null && $error !== ''];
    }
}
