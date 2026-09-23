<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AlignStorePricesToZayed extends Migration
{
    private const BACKUP = 'zayed_price_release_20260923';
    private const FIELDS = ['product_price', 'extra_clean', 'extra_clear', 'extra_combo', 'extra_medium', 'extra_large', 'extra_vacuim'];

    public function up()
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../data/2026-09-23-zayed-store-prices.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->unsignedBigInteger('menu_id')->primary();
                $table->unsignedBigInteger('resturant_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_name');
                $table->text('before_values');
                $table->text('after_values');
                $table->timestamp('applied_at');
                $table->timestamp('rolled_back_at')->nullable();
            });
        }
        DB::transaction(function () use ($manifest) {
            $stores = [];
            foreach ($manifest['stores'] as $store) {
                $row = DB::table('resturants')->where('id', $store['id'])->lockForUpdate()->first();
                if (!$row || (int) $row->user_id !== $store['user_id'] || $row->name !== $store['name']
                    || mb_strpos($row->name, 'فرع') !== false || $store['id'] === 308) {
                    throw new RuntimeException('Store identity changed: '.$store['id']);
                }
                $stores[$store['id']] = true;
            }
            $sources = [];
            foreach ($manifest['source'] as $item) {
                if ($item['store'] !== 310) throw new RuntimeException('Invalid Zayed source');
                $row = $this->lockedItem($item);
                if ($this->values($row) !== array_map('floatval', $item['values'])) {
                    throw new RuntimeException('Zayed source price changed since review: '.$item['id']);
                }
                $sources[$item['id']] = $item;
            }
            foreach ($manifest['targets'] as $item) {
                $source = $sources[$item['source_id']] ?? null;
                if (!isset($stores[$item['store']]) || $item['store'] === 310 || !$source
                    || $source['product_id'] !== $item['product_id'] || trim($source['name']) !== trim($item['name'])) {
                    throw new RuntimeException('Invalid store product mapping: '.$item['id']);
                }
                $row = $this->lockedItem($item);
                $current = ['product_price' => $row->product_price, 'price' => $row->price];
                $backup = DB::table(self::BACKUP)->where('menu_id', $row->id)->first();
                if ($backup) {
                    if ($backup->rolled_back_at !== null || !$this->same($current, json_decode($backup->after_values, true))) {
                        throw new RuntimeException('Previously applied store price changed: '.$row->id);
                    }
                    continue;
                }
                $before = $this->values($row);
                if ($before !== array_map('floatval', $item['values'])) {
                    throw new RuntimeException('Store menu price changed since review: '.$row->id);
                }
                $after = array_map('floatval', $source['values']);
                if ($before === $after) continue;
                $prices = json_decode($row->price, true, 512, JSON_THROW_ON_ERROR);
                $next = $current;
                foreach (self::FIELDS as $i => $field) {
                    if ($before[$i] === $after[$i]) continue;
                    if ($field === 'product_price') $next[$field] = $after[$i];
                    else $prices[$field] = $after[$i];
                }
                $next['price'] = json_encode($prices, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                DB::table(self::BACKUP)->insert([
                    'menu_id' => $row->id, 'resturant_id' => $row->resturant_id, 'product_id' => $row->product_id,
                    'product_name' => $row->product_name, 'before_values' => json_encode($current, JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($next, JSON_THROW_ON_ERROR), 'applied_at' => now(),
                ]);
                DB::table('resturant_products')->where('id', $row->id)->update($next);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::BACKUP)) throw new RuntimeException('Zayed release backup missing');
        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderBy('menu_id')->get() as $backup) {
                if ($backup->rolled_back_at !== null) continue;
                $row = $this->lockedItem(['id' => (int) $backup->menu_id, 'store' => (int) $backup->resturant_id,
                    'product_id' => (int) $backup->product_id, 'name' => $backup->product_name]);
                if (!$this->same(['product_price' => $row->product_price, 'price' => $row->price], json_decode($backup->after_values, true))) {
                    throw new RuntimeException('Menu changed after Zayed release; rollback stopped: '.$row->id);
                }
                DB::table('resturant_products')->where('id', $row->id)->update(json_decode($backup->before_values, true));
                DB::table(self::BACKUP)->where('menu_id', $row->id)->update(['rolled_back_at' => now()]);
            }
        });
        // Retain cancellation receipts so scheduled execution cannot undo a rollback.
    }

    private function lockedItem(array $item)
    {
        $row = DB::table('resturant_products')->where('id', $item['id'])->lockForUpdate()->first();
        if (!$row || (int) $row->resturant_id !== $item['store'] || (int) $row->product_id !== $item['product_id']
            || $row->product_name !== $item['name']) throw new RuntimeException('Menu identity changed: '.$item['id']);
        return $row;
    }

    private function values($row): array
    {
        $prices = json_decode($row->price, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($prices)) throw new RuntimeException('Invalid price data: '.$row->id);
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $field === 'product_price' ? $row->product_price : ($prices[$field] ?? null);
            // Match the public API's zero representation for unset optional surcharges.
            if ($field !== 'product_price' && ($value === null || (is_string($value) && trim($value) === ''))) $value = 0;
            if (!is_numeric($value)) throw new RuntimeException('Invalid numeric price: '.$row->id.'/'.$field);
            $values[] = (float) $value;
        }
        return $values;
    }

    private function same(array $a, array $b): bool
    {
        return (float) $a['product_price'] === (float) $b['product_price']
            && json_decode($a['price'], true, 512, JSON_THROW_ON_ERROR) == json_decode($b['price'], true, 512, JSON_THROW_ON_ERROR);
    }
}
