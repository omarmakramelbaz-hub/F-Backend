<?php

namespace App\Database;

use Illuminate\Database\Schema\MySqlBuilder;

/** The MySQL schema builder that remembers which tables and columns exist (see RemembersSchema). */
class CachedMySqlBuilder extends MySqlBuilder
{
    use RemembersSchema;
}
