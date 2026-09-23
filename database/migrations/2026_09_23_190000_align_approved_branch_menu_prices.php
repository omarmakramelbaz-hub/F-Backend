<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AlignApprovedBranchMenuPrices extends Migration
{
    private const BACKUP = 'menu_price_release_20260923';

    public function up()
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../data/2026-09-23-menu-prices.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->unsignedBigInteger('menu_id')->primary();
                $table->unsignedBigInteger('resturant_id');
                $table->unsignedBigInteger('product_id');
                $table->text('before_values');
                $table->text('after_values');
                $table->timestamp('applied_at');
                $table->timestamp('rolled_back_at')->nullable();
            });
        }

        DB::transaction(function () use ($manifest) {
            foreach ($manifest['changes'] as $change) {
                $row = DB::table('resturant_products')->where('id', $change['id'])->lockForUpdate()->first();
                if (!$row || (int) $row->resturant_id !== $change['branch']
                    || (int) $row->product_id !== $change['product_id'] || $row->product_name !== $change['name']) {
                    throw new RuntimeException('Menu release identity mismatch: '.$change['id']);
                }
                $current = ['product_price' => $row->product_price, 'price' => $row->price];
                $backup = DB::table(self::BACKUP)->where('menu_id', $row->id)->first();
                if ($backup) {
                    if ($backup->rolled_back_at !== null) {
                        throw new RuntimeException('Menu release was rolled back; a new reviewed release is required.');
                    }
                    if (!$this->same($current, json_decode($backup->after_values, true, 512, JSON_THROW_ON_ERROR))) {
                        throw new RuntimeException('Already-applied menu item changed: '.$row->id);
                    }
                    continue;
                }
                if ((float) $row->product_price !== (float) $change['base_before']) {
                    throw new RuntimeException('Menu base price changed since review: '.$row->id);
                }
                $prices = json_decode($row->price, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($prices)) {
                    throw new RuntimeException('Invalid menu price data: '.$row->id);
                }
                $next = $current;
                foreach ($change['changes'] as $field => $values) {
                    if (!in_array($field, ['product_price', 'extra_clean', 'extra_clear', 'extra_combo', 'extra_medium'], true)) {
                        throw new RuntimeException('Unexpected menu price field: '.$field);
                    }
                    $old = $field === 'product_price' ? $row->product_price : ($prices[$field] ?? null);
                    // The API casts unset/blank optional surcharges to 0. Accept that
                    // representation only when the reviewed surcharge was also zero.
                    // Keep genuine numeric changes and malformed text as conflicts.
                    if ($field !== 'product_price' && (float) $values['before'] === 0.0
                        && ($old === null || (is_string($old) && trim($old) === ''))) {
                        $old = 0;
                    }
                    if (!is_numeric($old) || (float) $old !== (float) $values['before']) {
                        throw new RuntimeException('Menu price changed since review: '.$row->id.'/'.$field);
                    }
                    if ($field === 'product_price') {
                        $next['product_price'] = $values['after'];
                    } else {
                        $prices[$field] = $values['after'];
                    }
                }
                $next['price'] = json_encode($prices, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                DB::table(self::BACKUP)->insert([
                    'menu_id' => $row->id, 'resturant_id' => $row->resturant_id, 'product_id' => $row->product_id,
                    'before_values' => json_encode($current, JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($next, JSON_THROW_ON_ERROR), 'applied_at' => now(),
                ]);
                DB::table('resturant_products')->where('id', $row->id)->update($next);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::BACKUP)) {
            throw new RuntimeException('Menu price backup is missing; refusing an unverified rollback.');
        }
        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderBy('menu_id')->get() as $backup) {
                if ($backup->rolled_back_at !== null) {
                    continue;
                }
                $row = DB::table('resturant_products')->where('id', $backup->menu_id)->lockForUpdate()->first();
                $after = json_decode($backup->after_values, true, 512, JSON_THROW_ON_ERROR);
                if (!$row || (int) $row->resturant_id !== (int) $backup->resturant_id
                    || (int) $row->product_id !== (int) $backup->product_id
                    || !$this->same(['product_price' => $row->product_price, 'price' => $row->price], $after)) {
                    throw new RuntimeException('Menu changed after release; rollback stopped: '.$backup->menu_id);
                }
                DB::table('resturant_products')->where('id', $row->id)
                    ->update(json_decode($backup->before_values, true, 512, JSON_THROW_ON_ERROR));
                DB::table(self::BACKUP)->where('menu_id', $row->id)->update(['rolled_back_at' => now()]);
            }
        });
        // Keep the audit and cancellation receipt so the scheduler cannot reapply a rollback.
    }

    private function same(array $a, array $b): bool
    {
        return (float) $a['product_price'] === (float) $b['product_price']
            && json_decode($a['price'], true, 512, JSON_THROW_ON_ERROR) == json_decode($b['price'], true, 512, JSON_THROW_ON_ERROR);
    }
}
