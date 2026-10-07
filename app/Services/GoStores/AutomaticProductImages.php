<?php

namespace App\Services\GoStores;

use App\Models\PendingVendor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AutomaticProductImages
{
    public function capture(PendingVendor $application, array $product, string $kind): void
    {
        DB::table('go_product_image_requests')->insert([
            'application_id' => $application->id, 'request_key' => $product['request_key'], 'store_kind' => $kind,
            'product_data' => json_encode($product, JSON_UNESCAPED_UNICODE), 'status' => 'pending',
            'image_path' => '', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function promote(PendingVendor $application, User $owner): void
    {
        if (!Schema::hasTable('go_product_image_requests')) return;
        foreach (DB::table('go_product_image_requests')->where('application_id', $application->id)->lockForUpdate()->get() as $row) {
            if ($row->product_id) continue;
            $data = json_decode($row->product_data, true);
            $id = DB::table('go_store_products')->insertGetId([
                'user_id' => $owner->id, 'request_key' => $row->request_key,
                'name' => $data['name'], 'description' => $data['description'], 'unit' => $data['unit'],
                'price_cents' => $data['price_cents'], 'options' => json_encode($data['options'], JSON_UNESCAPED_UNICODE),
                'image_path' => $row->image_path, 'available' => true, 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('go_product_image_requests')->where('id', $row->id)->update(['product_id' => $id, 'updated_at' => now()]);
        }
    }

    public function review(PendingVendor $application): array
    {
        if (!Schema::hasTable('go_product_image_requests')) return [];
        return DB::table('go_product_image_requests')->where('application_id', $application->id)->orderBy('id')->get()
            ->map(function ($row) {
                return json_decode($row->product_data, true) + ['request_key' => $row->request_key,
                    'image_status' => $row->status, 'image_url' => $row->image_path ? Storage::disk('public')->url($row->image_path) : ''];
            })->all();
    }

    /** Runs from the existing scheduler, never inside the applicant's HTTP request. */
    public function process(int $limit): int
    {
        if (!Schema::hasTable('go_product_image_requests') || !app(ProductImageSearch::class)->configured()) return 0;
        DB::table('go_product_image_requests')->where('status', 'processing')->where('attempts', '>=', 3)
            ->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'updated_at' => now()]);
        $completed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $row = DB::transaction(function () {
                $row = DB::table('go_product_image_requests')->where(function ($query) {
                    $query->where('status', 'pending')->orWhere(function ($stale) {
                        $stale->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(10));
                    });
                })->where('attempts', '<', 3)->where(function ($query) {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })->orderBy('id')->lockForUpdate()->first();
                if (!$row) return null;
                DB::table('go_product_image_requests')->where('id', $row->id)->update([
                    'status' => 'processing', 'attempts' => $row->attempts + 1, 'updated_at' => now(),
                ]);
                return $row;
            });
            if (!$row) break;
            $data = json_decode($row->product_data, true);
            $application = PendingVendor::find($row->application_id);
            if (!$row->product_id && (!$application || !in_array($application->status, ['pending', 'accepted'], true))) {
                DB::table('go_product_image_requests')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                continue;
            }
            try {
                $image = app(ProductImageSearch::class)->find($data['name'], $row->store_kind);
                DB::transaction(function () use ($row, $image, $data) {
                    // Approval may have promoted the product while the external AI call was running.
                    $current = DB::table('go_product_image_requests')->where('id', $row->id)->lockForUpdate()->first();
                    if (!$current) return;
                    $status = $image ? 'ready' : 'no_match';
                    if ($current->product_id && $image) {
                        $updated = DB::table('go_store_products')->where('id', $current->product_id)
                            ->where('name', $data['name'])->where('image_path', '')->update([
                                'image_path' => $image['path'], 'revision' => DB::raw('revision + 1'), 'updated_at' => now(),
                            ]);
                        if (!$updated) $status = 'superseded'; // Never replace a merchant/admin edit.
                    }
                    DB::table('go_product_image_requests')->where('id', $row->id)->update([
                        'status' => $status, 'image_path' => $image['path'] ?? '',
                        'provenance' => $image ? json_encode($image['provenance'], JSON_UNESCAPED_UNICODE) : null,
                        'next_attempt_at' => null, 'updated_at' => now(),
                    ]);
                });
                $completed++;
            } catch (\Throwable $error) {
                // Do not persist provider errors: they may include credentials or request payloads.
                DB::table('go_product_image_requests')->where('id', $row->id)->update([
                    'status' => $row->attempts >= 2 ? 'failed' : 'pending',
                    'next_attempt_at' => now()->addMinutes(5 * ($row->attempts + 1)), 'updated_at' => now(),
                ]);
            }
        }
        return $completed;
    }
}
