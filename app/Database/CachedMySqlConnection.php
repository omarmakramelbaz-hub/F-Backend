<?php

namespace App\Database;

use Illuminate\Database\MySqlConnection;

/** The site's MySQL connection: its schema builder remembers which tables and columns exist (SchemaMemory). */
class CachedMySqlConnection extends MySqlConnection
{
    use ForgetsSchemaOnChange;

    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new CachedMySqlBuilder($this);
    }
}
