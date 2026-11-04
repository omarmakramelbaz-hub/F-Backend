<?php

namespace App\Http\Middleware;

use App\Support\RequestFacts;
use Closure;

/** A GET (or HEAD) request keeps the facts it reads for the rest of its own answer only (App\Support\RequestFacts). */
class RememberRequestFacts
{
    public function handle($request, Closure $next)
    {
        if (! $request->isMethodSafe()) return $next($request);
        $request->attributes->set(RequestFacts::ATTRIBUTE, new \ArrayObject());
        try {
            return $next($request);
        } finally {
            $request->attributes->remove(RequestFacts::ATTRIBUTE);
        }
    }
}
