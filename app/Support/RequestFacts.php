<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Facts read once per page read: while the site answers a GET (or HEAD) request, a fact read from the database (an
 * account, its permissions, a branch) is read once and reused by every part of that answer, instead of being read
 * again by each of them. Everywhere else every read goes to the database as before: saving requests, console
 * commands and queue jobs, and anything inside a database transaction (reads that lock rows among them).
 */
final class RequestFacts
{
    /** The request attribute holding the facts of the request being answered (set by RememberRequestFacts). */
    public const ATTRIBUTE = 'go.request_facts';

    /** The fact under that key: read once in this request, or read now when nothing is kept here. */
    public static function remember(string $key, \Closure $read)
    {
        $facts = self::current();
        if ($facts === null) return $read();
        if (isset($facts[$key])) return $facts[$key][0];
        $value = $read();
        $facts[$key] = [$value];
        return $value;
    }

    private static function current(): ?\ArrayObject
    {
        if (! app()->bound('request')) return null;
        $facts = app('request')->attributes->get(self::ATTRIBUTE);
        if (! $facts instanceof \ArrayObject) return null;
        return DB::transactionLevel() > 0 ? null : $facts;
    }
}
