<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;

    /** Preserve the original configured proxy membership, without implicit host-based trust. */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $trustedIps = $this->proxies ?: config('trustedproxy.proxies');
        if ($trustedIps === '*' || $trustedIps === '**') {
            $request->setTrustedProxies([$request->server->get('REMOTE_ADDR')], $this->getTrustedHeaderNames());
            return;
        }
        if (is_string($trustedIps)) $trustedIps = array_map('trim', explode(',', $trustedIps));
        if (is_array($trustedIps)) $request->setTrustedProxies($trustedIps, $this->getTrustedHeaderNames());
    }
}
