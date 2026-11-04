<?php

namespace App\Console\Commands;

use App\Database\SchemaMemory;
use Illuminate\Console\Command;

/** After a change of the database's layout made outside Laravel: forget the tables and columns the site knows of. */
class ForgetKeptSchema extends Command
{
    protected $signature = 'schema:forget-kept';
    protected $description = 'Forget the tables and columns the site knows it has (they are looked up again).';

    public function handle()
    {
        $forgotten = SchemaMemory::forgetEverything();
        $this->line(json_encode(['forgotten' => $forgotten]));
        return $forgotten ? 0 : 1;
    }
}
