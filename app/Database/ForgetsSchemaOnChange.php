<?php

namespace App\Database;

/**
 * A connection that forgets what is known about its database's tables and columns (SchemaMemory) whenever it runs a
 * statement that changes them: Schema::create, ::table, ::drop and ::rename all run here, and so do raw CREATE, ALTER,
 * DROP and RENAME statements. A temporary table changes nothing kept.
 */
trait ForgetsSchemaOnChange
{
    public function statement($query, $bindings = [])
    {
        try {
            return parent::statement($query, $bindings);
        } finally {
            if (is_string($query) && preg_match('/^\s*(?:CREATE|ALTER|DROP|RENAME)\s+(?!TEMPORARY\b)/i', $query)) SchemaMemory::forgetEverything();
        }
    }

    public function unprepared($query)
    {
        try {
            return parent::unprepared($query);
        } finally {
            // Several statements may come together here: any of them changing a table is enough.
            if (is_string($query) && preg_match('/\b(?:CREATE|ALTER|DROP|RENAME)\s+(?!TEMPORARY\b)/i', $query)) SchemaMemory::forgetEverything();
        }
    }
}
