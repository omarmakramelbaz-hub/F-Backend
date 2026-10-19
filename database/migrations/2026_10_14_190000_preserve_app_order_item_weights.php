<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreserveAppOrderItemWeights extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('carts') || !Schema::hasColumn('carts', 'qty')) {
            throw new RuntimeException('The application carts quantity column is required.');
        }
        // SQLite accepts fractional values in INTEGER columns. MySQL truncates them,
        // so widen only an integer or a decimal with fewer than three decimal places.
        if (DB::connection()->getDriverName() !== 'mysql') return;
        $column = DB::selectOne('SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable_value, '
            .'COLUMN_DEFAULT AS default_value, EXTRA AS extra_value, COLUMN_COMMENT AS comment_value '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::connection()->getTablePrefix().'carts', 'qty']);
        if (!$column) throw new RuntimeException('Could not inspect the carts quantity column.');
        $type = strtolower((string) $column->type);
        $precision = 24;
        if (preg_match('/^decimal\((\d+),(\d+)\)(?: unsigned)?$/D', $type, $match)) {
            if ((int) $match[2] >= 3) return;
            $precision = max($precision, (int) $match[1] - (int) $match[2] + 3);
        } elseif (preg_match('/^(?:float|double)(?:\([0-9,]+\))?(?: unsigned)?$/D', $type)) {
            return;
        } elseif (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?$/D', $type)) {
            throw new RuntimeException('The carts quantity column has an unsupported numeric type.');
        }
        if ($precision > 65 || trim((string) $column->extra_value) !== '') {
            throw new RuntimeException('The carts quantity column needs manual schema review.');
        }
        $definition = 'DECIMAL('.$precision.',3)'.(str_contains($type, 'unsigned') ? ' UNSIGNED' : '');
        $definition .= $column->nullable_value === 'YES' ? ' NULL' : ' NOT NULL';
        if ($column->default_value !== null) {
            $default = (string) $column->default_value;
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $default)) {
                throw new RuntimeException('The carts quantity default needs manual schema review.');
            }
            $definition .= ' DEFAULT '.$default;
        } elseif ($column->nullable_value === 'YES') {
            $definition .= ' DEFAULT NULL';
        }
        $definition .= ' COMMENT '.DB::connection()->getPdo()->quote((string) $column->comment_value);
        $table = '`'.str_replace('`', '``', DB::connection()->getTablePrefix().'carts').'`';
        DB::statement('ALTER TABLE '.$table.' MODIFY COLUMN `qty` '.$definition);
    }

    public function down(): void
    {
        // Keep recorded gram precision when rolling code back; never truncate orders.
    }
}
