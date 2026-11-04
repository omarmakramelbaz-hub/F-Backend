<?php

namespace Tests\Feature;

use App\Database\CachedMySqlBuilder;
use App\Database\CachedMySqlConnection;
use App\Database\ForgetsSchemaOnChange;
use App\Database\RemembersSchema;
use App\Database\SchemaMemory;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The site's database connection answers "is this table (or column) there?" from what it already knows: the same
 * answers as the database's, with far fewer questions, and never an old answer after the tables change.
 * (The live site uses MySQL; the same remembering builder is checked here on SQLite.)
 */
class SchemaMemoryTest extends TestCase
{
    private string $folder;
    private RememberingSQLiteConnection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/go-schema-test-'.bin2hex(random_bytes(4));
        mkdir($this->folder, 0755, true);
        config(['cache.stores.file.path' => $this->folder]);
        app('cache')->forgetDriver('file');
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00'));
        SchemaMemory::forgetThisProcess();
        $this->db = new RememberingSQLiteConnection(DB::connection()->getPdo(), ':memory:', '', ['name' => 'remembering', 'driver' => 'sqlite']);
        $this->db->enableQueryLog();
        $this->schema()->create('memo_orders', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->string('Customer_Name')->nullable();
        });
        $this->schema()->create('memo_items', function (Blueprint $table) {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SchemaMemory::forgetThisProcess();
        \Illuminate\Support\Facades\File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function schema(): SQLiteBuilder
    {
        return $this->db->getSchemaBuilder();
    }

    /** The schema questions asked of the database since the last call. */
    private function questions(): array
    {
        $asked = array_values(array_filter(array_column($this->db->getQueryLog(), 'query'),
            fn ($sql) => str_contains($sql, 'sqlite_master') || str_starts_with($sql, 'pragma')));
        $this->db->flushQueryLog();
        return $asked;
    }

    private function newRequest(): void
    {
        SchemaMemory::forgetThisProcess();
    }

    public function test_the_same_answers_as_the_database_with_far_fewer_questions(): void
    {
        $plain = DB::connection()->getSchemaBuilder();
        $this->questions();
        $checks = [
            ['hasTable', ['memo_orders']], ['hasTable', ['memo_items']], ['hasTable', ['memo_missing']], ['hasTable', ['MEMO_ORDERS']],
            ['hasColumn', ['memo_orders', 'status']], ['hasColumn', ['memo_orders', 'STATUS']], ['hasColumn', ['memo_orders', 'customer_name']],
            ['hasColumn', ['memo_orders', 'nope']], ['hasColumn', ['memo_missing', 'id']],
            ['hasColumns', ['memo_orders', ['id', 'status']]], ['hasColumns', ['memo_orders', ['id', 'nope']]], ['hasColumns', ['memo_orders', []]],
            ['getColumnListing', ['memo_orders']], ['getColumnListing', ['memo_items']], ['getColumnListing', ['memo_missing']],
        ];
        for ($round = 0; $round < 50; $round++) {
            foreach ($checks as [$method, $arguments]) {
                $this->assertSame($plain->{$method}(...$arguments), $this->schema()->{$method}(...$arguments), $method.' '.json_encode($arguments));
            }
        }
        // 750 checks: each table and column list read once, the whole table list once, and a differently cased name
        // (which the database alone may answer) each time.
        $asked = $this->questions();
        $this->assertLessThan(60, count($asked));
        $this->assertSame(50, count(array_filter($asked, fn ($sql) => str_contains($sql, 'name = ?'))), 'only MEMO_ORDERS asks each time');
    }

    public function test_a_later_request_uses_what_an_earlier_one_learned(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->assertFalse($this->schema()->hasTable('memo_missing'));
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
        $this->questions();
        foreach (range(1, 3) as $request) {
            $this->newRequest();
            $this->assertTrue($this->schema()->hasTable('memo_orders'));
            $this->assertTrue($this->schema()->hasTable('memo_items'));
            $this->assertFalse($this->schema()->hasTable('memo_missing'));
            $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
            $this->assertSame(['id', 'status', 'Customer_Name'], $this->schema()->getColumnListing('memo_orders'));
        }
        $this->assertSame([], $this->questions());
    }

    public function test_what_is_known_to_exist_needs_no_question_for_ten_minutes(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
        $this->questions();
        foreach ([1, 4, 9] as $minute) {
            Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00')->addMinutes($minute));
            $this->newRequest();
            for ($check = 0; $check < 10; $check++) {
                $this->assertTrue($this->schema()->hasTable('memo_orders'));
                $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
            }
        }
        $this->assertSame([], $this->questions());
    }

    public function test_every_change_of_tables_through_laravel_is_seen_at_once_by_every_request(): void
    {
        $this->assertFalse($this->schema()->hasTable('memo_later'));
        $this->assertFalse($this->schema()->hasColumn('memo_orders', 'extra'));
        $this->schema()->create('memo_later', fn (Blueprint $table) => $table->id());
        $this->assertTrue($this->schema()->hasTable('memo_later'));
        $this->newRequest();
        $this->assertTrue($this->schema()->hasTable('memo_later'));

        $this->schema()->table('memo_orders', fn (Blueprint $table) => $table->string('extra')->nullable());
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'extra'));
        $this->newRequest();
        $this->assertContains('extra', $this->schema()->getColumnListing('memo_orders'));

        $this->schema()->rename('memo_later', 'memo_renamed');
        $this->assertFalse($this->schema()->hasTable('memo_later'));
        $this->assertTrue($this->schema()->hasTable('memo_renamed'));
        $this->schema()->drop('memo_renamed');
        $this->newRequest();
        $this->assertFalse($this->schema()->hasTable('memo_renamed'));

        // Raw statements too.
        $this->db->statement('CREATE TABLE memo_raw (id integer)');
        $this->assertTrue($this->schema()->hasTable('memo_raw'));
        $this->db->statement('ALTER TABLE memo_raw ADD COLUMN note text');
        $this->newRequest();
        $this->assertTrue($this->schema()->hasColumn('memo_raw', 'note'));
        $this->assertTrue($this->schema()->hasTable('memo_raw'));
        $this->db->unprepared('DROP TABLE memo_raw');
        $this->assertFalse($this->schema()->hasTable('memo_raw'));
        $this->newRequest();
        $this->assertFalse($this->schema()->hasTable('memo_raw'));
    }

    public function test_a_temporary_table_or_an_ordinary_statement_forgets_nothing(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
        $this->db->statement('CREATE TEMPORARY TABLE memo_scratch (id integer)');
        $this->db->statement("INSERT INTO memo_orders (status) VALUES ('created and dropped')");
        $this->db->statement('UPDATE memo_orders SET status = ?', ['rename later']);
        $this->questions();
        $this->newRequest();
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
        $this->assertSame([], $this->questions());
    }

    public function test_a_change_made_outside_laravel_is_seen_within_a_minute(): void
    {
        $this->assertFalse($this->schema()->hasTable('memo_outside'));
        $this->assertSame([], $this->schema()->getColumnListing('memo_outside'));
        $this->assertFalse($this->schema()->hasColumn('memo_orders', 'outside'));
        // Straight through PDO: nothing tells the site.
        $this->db->getPdo()->exec('CREATE TABLE memo_outside (id integer)');
        $this->db->getPdo()->exec('ALTER TABLE memo_orders ADD COLUMN outside text');
        $this->newRequest();
        $this->assertFalse($this->schema()->hasTable('memo_outside'));
        $this->assertSame([], $this->schema()->getColumnListing('memo_outside'));
        $this->assertFalse($this->schema()->hasColumn('memo_orders', 'outside'));
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->newRequest();
        $this->assertTrue($this->schema()->hasTable('memo_outside'));
        $this->assertSame(['id'], $this->schema()->getColumnListing('memo_outside'));
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'outside'));
        $this->assertContains('outside', $this->schema()->getColumnListing('memo_orders'));
        // A long-running process reads the kept facts again after a minute, too.
        $this->db->getPdo()->exec('CREATE TABLE memo_worker (id integer)');
        $this->assertFalse($this->schema()->hasTable('memo_worker'));
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->assertTrue($this->schema()->hasTable('memo_worker'));
    }

    public function test_known_tables_and_columns_are_read_again_after_ten_minutes(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->assertSame(['id', 'status', 'Customer_Name'], $this->schema()->getColumnListing('memo_orders'));
        $this->db->getPdo()->exec('ALTER TABLE memo_orders ADD COLUMN later text');
        Carbon::setTestNow(Carbon::now()->addMinutes(9));
        $this->newRequest();
        $this->assertSame(['id', 'status', 'Customer_Name'], $this->schema()->getColumnListing('memo_orders'));
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->newRequest();
        $this->assertSame(['id', 'status', 'Customer_Name', 'later'], $this->schema()->getColumnListing('memo_orders'));
    }

    public function test_pretending_migrations_teach_nothing(): void
    {
        // While pretending, every question is answered "nothing there", as before; none of it is remembered.
        $this->db->pretend(function () {
            $this->assertFalse($this->schema()->hasTable('memo_orders'));
            $this->assertFalse($this->schema()->hasColumn('memo_orders', 'status'));
            $this->assertSame([], $this->schema()->getColumnListing('memo_items'));
        });
        foreach ([false, true] as $later) {
            if ($later) $this->newRequest();
            $this->assertTrue($this->schema()->hasTable('memo_orders'));
            $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
            $this->assertSame(['id'], $this->schema()->getColumnListing('memo_items'));
        }
    }

    public function test_a_long_running_process_sees_another_process_change_within_a_minute(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_items'));
        $this->assertSame(['id'], $this->schema()->getColumnListing('memo_items'));
        // Another process drops the table through Laravel: it starts a new generation of kept facts.
        $this->db->getPdo()->exec('DROP TABLE memo_items');
        file_put_contents($this->folder.'/go-schema-generation', bin2hex(random_bytes(8)));
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->assertTrue($this->schema()->hasTable('memo_items'), 'this process holds what it knew for up to a minute');
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertFalse($this->schema()->hasTable('memo_items'));
        $this->assertSame([], $this->schema()->getColumnListing('memo_items'));
    }

    public function test_another_account_keeps_nothing_in_the_cache_folder_but_can_start_a_new_generation(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) $this->markTestSkipped('needs root to hand the folder to another account');
        chown($this->folder, 65534);
        $this->newRequest();
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->assertTrue($this->schema()->hasColumn('memo_orders', 'status'));
        // Only the generation file (written when the test's tables were made): no kept facts.
        $this->assertSame(['go-schema-generation'], array_values(array_diff(scandir($this->folder), ['.', '..'])), 'nothing kept by this account');
        $mask = umask(0077);
        try {
            $this->assertTrue(SchemaMemory::forgetEverything());
        } finally {
            umask($mask);
        }
        $this->assertSame(['go-schema-generation'], array_values(array_diff(scandir($this->folder), ['.', '..'])));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->folder.'/go-schema-generation')), -4));
    }

    public function test_a_database_without_tables_is_not_remembered_for_later_requests(): void
    {
        // A database being set up: what it gets next is seen by the next request, however it was made.
        $empty = new RememberingSQLiteConnection(new \PDO('sqlite::memory:'), ':memory:', '', ['name' => 'empty', 'driver' => 'sqlite']);
        $this->assertFalse($empty->getSchemaBuilder()->hasTable('memo_orders'));
        $empty->getPdo()->exec('CREATE TABLE memo_orders (id integer)');
        $this->newRequest();
        $this->assertTrue($empty->getSchemaBuilder()->hasTable('memo_orders'));
    }

    public function test_the_forget_command_starts_a_new_generation(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_orders'));
        $this->db->getPdo()->exec('DROP TABLE memo_items');
        $this->newRequest();
        $this->assertTrue($this->schema()->hasTable('memo_items'), 'kept from before');
        $this->artisan('schema:forget-kept')->expectsOutput('{"forgotten":true}')->assertExitCode(0);
        $this->newRequest();
        $this->assertFalse($this->schema()->hasTable('memo_items'));
    }

    public function test_a_migration_forgets_what_it_may_have_changed(): void
    {
        $this->assertTrue($this->schema()->hasTable('memo_items'));
        $this->db->getPdo()->exec('DROP TABLE memo_items');
        event(new \Illuminate\Database\Events\MigrationEnded(new class extends \Illuminate\Database\Migrations\Migration {}, 'up'));
        $this->newRequest();
        $this->assertFalse($this->schema()->hasTable('memo_items'));
    }

    public function test_the_live_mysql_connection_remembers(): void
    {
        $resolver = Connection::getResolver('mysql');
        $this->assertNotNull($resolver);
        $connection = $resolver(fn () => null, 'fasakha_db', '', ['name' => 'mysql', 'driver' => 'mysql']);
        $this->assertInstanceOf(CachedMySqlConnection::class, $connection);
        $this->assertInstanceOf(CachedMySqlBuilder::class, $connection->getSchemaBuilder());
        $this->assertSame('mysql', $connection->getDriverName());
    }

    public function test_mysql_shaped_table_lists_are_read(): void
    {
        $db = new MySqlShapedSQLiteConnection(DB::connection()->getPdo(), ':memory:', '', ['name' => 'shaped', 'driver' => 'sqlite']);
        $this->assertTrue($db->getSchemaBuilder()->hasTable('memo_orders'));
        $this->assertFalse($db->getSchemaBuilder()->hasTable('memo_missing'));
    }
}

class RememberingSQLiteBuilder extends SQLiteBuilder
{
    use RemembersSchema;

    public function getAllTables()
    {
        return $this->connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'");
    }
}

class RememberingSQLiteConnection extends SQLiteConnection
{
    use ForgetsSchemaOnChange;

    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new RememberingSQLiteBuilder($this);
    }
}

/** Rows as MySQL's SHOW FULL TABLES gives them: the name first, under the database's name. */
class MySqlShapedSQLiteBuilder extends SQLiteBuilder
{
    use RemembersSchema;

    public function getAllTables()
    {
        return $this->connection->select("select name as \"Tables_in_fasakha_db\", 'BASE TABLE' as \"Table_type\" from sqlite_master where type = 'table'");
    }
}

class MySqlShapedSQLiteConnection extends SQLiteConnection
{
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new MySqlShapedSQLiteBuilder($this);
    }
}
