<?php
namespace Illuminate\Database\Migrations { class Migration {} }
namespace Illuminate\Support\Facades {
    class Schema {
        public static function hasTable($name) { return true; }
        public static function hasColumn($table, $name) { return true; }
    }
    class DB {
        public static $driver = 'mysql', $column, $sql = [];
        public static function connection() { return new self; }
        public function getDriverName() { return self::$driver; }
        public function getTablePrefix() { return ''; }
        public static function selectOne($sql, $values) { return (object) self::$column; }
        public function getPdo() { return new class { public function quote($value) { return "'".str_replace("'", "''", $value)."'"; } }; }
        public static function statement($sql) { self::$sql[] = $sql; }
    }
}
namespace {
    use Illuminate\Support\Facades\DB;
    require __DIR__.'/../database/migrations/2026_10_14_190000_preserve_app_order_item_weights.php';
    $migration = new \PreserveAppOrderItemWeights;
    function column(string $type, $default = '1', string $nullable = 'NO'): void {
        DB::$sql = []; DB::$column = ['type'=>$type, 'nullable_value'=>$nullable,
            'default_value'=>$default, 'extra_value'=>'', 'comment_value'=>"Order's quantity"];
    }
    function check(bool $condition, string $label): void { if (!$condition) throw new \RuntimeException($label); }
    column('int(11) unsigned'); $migration->up();
    check(count(DB::$sql) === 1 && str_contains(DB::$sql[0], 'DECIMAL(24,3) UNSIGNED NOT NULL DEFAULT 1'), 'Integer widened without losing defaults/sign.');
    check(str_contains(DB::$sql[0], "COMMENT 'Order''s quantity'"), 'Column comment retained.');
    column('bigint(20) unsigned', null, 'YES'); $migration->up();
    check(str_contains(DB::$sql[0], 'DECIMAL(24,3) UNSIGNED NULL DEFAULT NULL'), 'Full bigint range and nullable state retained.');
    column('decimal(30,2)'); $migration->up();
    check(str_contains(DB::$sql[0], 'DECIMAL(31,3)'), 'Existing decimal integer precision retained.');
    foreach (['decimal(12,3)', 'decimal(24,6)', 'double', 'float unsigned'] as $type) {
        column($type); $migration->up(); check(DB::$sql === [], 'Already fractional column unchanged: '.$type);
    }
    foreach (['varchar(10)', 'int zerofill'] as $type) {
        column($type); $failed = false;
        try { $migration->up(); } catch (\RuntimeException $e) { $failed = true; }
        check($failed && DB::$sql === [], 'Unsupported type fails before schema mutation.');
    }
    column('int', 'CURRENT_TIMESTAMP'); $failed = false;
    try { $migration->up(); } catch (\RuntimeException $e) { $failed = true; }
    check($failed && DB::$sql === [], 'Non-numeric default fails before mutation.');
    column('int'); $migration->down(); check(DB::$sql === [], 'Rollback never truncates gram precision.');
    DB::$driver = 'sqlite'; $migration->up(); check(DB::$sql === [], 'SQLite requires no type rewrite.');
    echo "Quantity migration preserves range, defaults, comments, nullability and rollback precision.\n";
}
