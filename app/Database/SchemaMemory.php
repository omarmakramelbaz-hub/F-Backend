<?php

namespace App\Database;

/**
 * What the site knows about which tables and columns its database has, so "is this table (or column) there?" is not
 * asked of the database again and again: the live site asked it about 330 times a second, and on MariaDB each of these
 * questions builds a temporary table on disk.
 *
 * - Known for this process for a minute (a request, or a minute of a long-running worker), and kept in the file cache
 *   for the next requests for ten minutes.
 * - A table or column not found is looked up again once a minute: something added since is seen within that minute.
 * - Any change of the layout through Laravel (a migration, Schema::create or ::table, a raw CREATE, ALTER, DROP or
 *   RENAME) forgets everything, here and for every later request: a new generation of kept facts starts.
 * - Only the account that owns the cache folder (the web server's) keeps facts there, so a command run by another
 *   account never leaves files the site cannot replace. Any account that can write the folder (root too) can start a
 *   new generation: the generation is one small file, replaced whole.
 */
final class SchemaMemory
{
    /** How long one request's facts serve the next requests. */
    public const KEEP_FOR = 600;
    /** How long a table or column not found is believed missing. */
    public const MISSING_FOR = 60;
    /** How long a long-running process trusts what it holds before reading the kept facts again. */
    public const PROCESS_FOR = 60;

    /** @var array<string, array{tables: array<string, bool>, folded: array<string, bool>, complete_at: ?int}> per connection scope */
    private static array $tables = [];
    /** @var array<string, array<string, array{at: int, columns: array}>> per connection scope, per table */
    private static array $columns = [];
    private static ?int $since = null;
    private static ?string $generation = null;
    private static ?bool $shared = null;
    private const GENERATION_FILE = 'go-schema-generation';

    /** The tables known to exist and when the full list was last read (null: never in this generation). */
    public static function tables(string $scope): array
    {
        self::fresh();
        if (! isset(self::$tables[$scope])) {
            $kept = self::kept('tables|'.$scope);
            $tables = is_array($kept) && isset($kept['tables']) && is_array($kept['tables']) ? $kept['tables'] : [];
            self::$tables[$scope] = ['tables' => $tables, 'folded' => array_change_key_case($tables, CASE_LOWER),
                'complete_at' => isset($kept['complete_at']) ? (int) $kept['complete_at'] : null];
        }
        return self::$tables[$scope];
    }

    /** The full list of the database's tables, just read. */
    public static function rememberTables(string $scope, array $names): void
    {
        self::fresh();
        $tables = array_fill_keys($names, true);
        self::$tables[$scope] = ['tables' => $tables, 'folded' => array_change_key_case($tables, CASE_LOWER), 'complete_at' => self::now()];
        // A database without tables is not kept for the next requests: it is being set up.
        if ($tables !== []) self::keep('tables|'.$scope, ['tables' => $tables, 'complete_at' => self::now()]);
    }

    /** The table's columns as last read, and when; null when not known. */
    public static function columns(string $scope, string $table): ?array
    {
        self::fresh();
        if (! isset(self::$columns[$scope][$table])) {
            $kept = self::kept('columns|'.$scope.'|'.$table);
            if (! is_array($kept) || ! isset($kept['at'], $kept['columns']) || ! is_array($kept['columns'])) return null;
            self::$columns[$scope][$table] = ['at' => (int) $kept['at'], 'columns' => array_values($kept['columns'])];
        }
        return self::$columns[$scope][$table];
    }

    /** The table's columns, just read (none: the table is not there, believed for a minute). */
    public static function rememberColumns(string $scope, string $table, array $columns): array
    {
        self::fresh();
        $known = ['at' => self::now(), 'columns' => array_values($columns)];
        self::$columns[$scope][$table] = $known;
        self::keep('columns|'.$scope.'|'.$table, $known);
        return $known;
    }

    /** Whether something read at that time is still believed missing. */
    public static function stillMissing(?int $readAt): bool
    {
        return $readAt !== null && self::now() - $readAt < self::MISSING_FOR;
    }

    /**
     * Forgets every fact, here and for every later request: after any change of the database's layout. False when the
     * facts kept for later requests could not be forgotten (the cache folder is not writable by this account).
     */
    public static function forgetEverything(): bool
    {
        $forgotten = false;
        try {
            $folder = self::folder();
            if ($folder === null || ! is_dir($folder)) {
                $forgotten = true; // nothing kept anywhere
            } else {
                // A new generation: the facts kept so far are never read again (they expire on their own).
                $file = $folder.'/'.self::GENERATION_FILE;
                $temporary = $file.'.'.bin2hex(random_bytes(6));
                if (@file_put_contents($temporary, bin2hex(random_bytes(8))) !== false) {
                    @chmod($temporary, 0644);
                    $forgotten = @rename($temporary, $file);
                    if (! $forgotten) @unlink($temporary);
                }
            }
        } catch (\Throwable $error) {
            $forgotten = false;
        }
        self::forgetThisProcess();
        return $forgotten;
    }

    /** Forgets what this process holds (the kept facts stay): a fresh start, as a new request has. */
    public static function forgetThisProcess(): void
    {
        self::$tables = [];
        self::$columns = [];
        self::$since = null;
        self::$generation = null;
        self::$shared = null;
    }

    private static function fresh(): void
    {
        $now = self::now();
        if (self::$since !== null && $now - self::$since < self::PROCESS_FOR) return;
        self::forgetThisProcess();
        self::$since = $now;
    }

    private static function now(): int
    {
        return \Illuminate\Support\Carbon::now()->getTimestamp();
    }

    /** The file cache's folder; null when there is none. */
    private static function folder(): ?string
    {
        try {
            $folder = config('cache.stores.file.path');
        } catch (\Throwable $error) {
            return null;
        }
        return is_string($folder) && $folder !== '' ? rtrim($folder, '/') : null;
    }

    /** Whether this process keeps facts for the next requests: only the account that owns the cache folder does. */
    private static function shared(): bool
    {
        if (self::$shared === null) {
            $folder = self::folder();
            $owner = $folder !== null && is_dir($folder) ? @fileowner($folder) : false;
            self::$shared = $owner !== false && (! function_exists('posix_geteuid') || $owner === posix_geteuid());
        }
        return self::$shared;
    }

    private static function generation(): string
    {
        if (self::$generation === null) {
            $folder = self::folder();
            $token = $folder !== null ? @file_get_contents($folder.'/'.self::GENERATION_FILE) : false;
            self::$generation = is_string($token) && preg_match('/^[a-f0-9]{16}$/D', $token) ? $token : 'first';
        }
        return self::$generation;
    }

    private static function kept(string $key)
    {
        if (! self::shared()) return null;
        try {
            return app('cache')->store('file')->get('go-schema:'.self::generation().':'.sha1($key));
        } catch (\Throwable $error) {
            return null;
        }
    }

    private static function keep(string $key, array $value): void
    {
        if (! self::shared()) return;
        try {
            app('cache')->store('file')->put('go-schema:'.self::generation().':'.sha1($key), $value, self::KEEP_FOR);
        } catch (\Throwable $error) {
            // Known for this process only.
        }
    }
}
