<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AlignStoreDeliveryRates extends Migration
{
    private const BACKUP = 'store_delivery_release_20260923';
    private const FIELDS = ['default_0_1', 'default_1_2', 'default_2_3'];

    public function up()
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../data/2026-09-23-store-delivery.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->unsignedBigInteger('resturant_id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->text('before_values');
                $table->text('after_values');
                $table->timestamp('applied_at');
                $table->timestamp('rolled_back_at')->nullable();
            });
        }
        DB::transaction(function () use ($manifest) {
            foreach ($manifest['stores'] as $store) {
                $row = DB::table('resturants')->where('id', $store['id'])->lockForUpdate()->first();
                if (!$row || (int) $row->user_id !== $store['user_id'] || $row->name !== $store['name']
                    || mb_strpos($row->name, 'فرع') !== false) {
                    throw new RuntimeException('Store identity changed: '.$store['id']);
                }
                $current = $this->values($row);
                $backup = DB::table(self::BACKUP)->where('resturant_id', $row->id)->first();
                if ($backup) {
                    if ($backup->rolled_back_at !== null || !$this->same($current, json_decode($backup->after_values, true))) {
                        throw new RuntimeException('Store release already applied and subsequently changed: '.$row->id);
                    }
                    continue;
                }
                if (!$this->same($current, $store['before'])) {
                    throw new RuntimeException('Store delivery rate changed since review: '.$row->id);
                }
                $next = array_fill_keys(self::FIELDS, 50);
                DB::table(self::BACKUP)->insert([
                    'resturant_id' => $row->id, 'user_id' => $row->user_id, 'name' => $row->name,
                    'before_values' => json_encode($current, JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($next, JSON_THROW_ON_ERROR), 'applied_at' => now(),
                ]);
                DB::table('resturants')->where('id', $row->id)->update($next);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::BACKUP)) {
            throw new RuntimeException('Store delivery backup missing.');
        }
        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderBy('resturant_id')->get() as $backup) {
                if ($backup->rolled_back_at !== null) continue;
                $row = DB::table('resturants')->where('id', $backup->resturant_id)->lockForUpdate()->first();
                if (!$row || (int) $row->user_id !== (int) $backup->user_id || $row->name !== $backup->name
                    || !$this->same($this->values($row), json_decode($backup->after_values, true))) {
                    throw new RuntimeException('Store changed after release; rollback stopped: '.$backup->resturant_id);
                }
                DB::table('resturants')->where('id', $row->id)->update(json_decode($backup->before_values, true));
                DB::table(self::BACKUP)->where('resturant_id', $row->id)->update(['rolled_back_at' => now()]);
            }
        });
        // Retain the audit and cancellation receipt to prevent scheduled reapplication.
    }

    private function values($row): array
    {
        return array_intersect_key((array) $row, array_flip(self::FIELDS));
    }

    private function same(array $a, array $b): bool
    {
        foreach (self::FIELDS as $field) {
            if (!isset($a[$field], $b[$field]) || !is_numeric($a[$field]) || !is_numeric($b[$field])
                || (float) $a[$field] !== (float) $b[$field]) return false;
        }
        return true;
    }
}
