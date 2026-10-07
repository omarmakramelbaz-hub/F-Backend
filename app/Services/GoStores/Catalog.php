<?php

namespace App\Services\GoStores;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Catalog
{
    public const KINDS = ['supermarket' => 'سوبر ماركت', 'restaurant' => 'مطعم', 'pharmacy' => 'صيدلية'];

    public static function isStore(User $user): bool
    {
        return $user->app_scope === 'go_partner'
            && ($user->account_type === 'vendor' || ($user->account_type === 'delegate'
                && $user->pending_vendor?->profession_key === 'store_owner'));
    }

    public function ready(): void
    {
        abort_unless(Schema::hasTable('go_stores') && Schema::hasTable('go_store_products'), 503,
            'إدارة المتاجر قيد التجهيز. يرجى المحاولة لاحقًا.');
    }

    public function owner(int $id): User
    {
        $owner = User::withoutGlobalScopes()->findOrFail($id);
        abort_unless(self::isStore($owner), 403, 'هذه الخاصية متاحة لحسابات المتاجر فقط.');
        return $owner;
    }

    public function store(int $id): ?array
    {
        $row = DB::table('go_stores')->where('user_id', $id)->first();
        if (!$row) return null;
        $owner = User::withoutGlobalScopes()->find($id);
        return (array) $row + ['logo_url' => $owner ? $owner->getFirstMediaUrl('go_store_logo') : ''];
    }

    public function saveStore(int $id, Request $request): array
    {
        foreach (['name', 'address'] as $key) {
            if (is_string($request->input($key))) $request->merge([$key => trim($request->input($key))]);
        }
        $data = $request->validate([
            'name' => 'required|string|min:2|max:150',
            'kind' => 'required|in:supermarket,restaurant,pharmacy',
            'address' => 'required|string|min:5|max:500',
            'revision' => 'required|integer|min:0',
        ]);
        return DB::transaction(function () use ($id, $data) {
            DB::table('users')->where('id', $id)->lockForUpdate()->first();
            $row = DB::table('go_stores')->where('user_id', $id)->first();
            abort_unless((int) ($row->revision ?? 0) === (int) $data['revision'], 409, 'تم تعديل بيانات المتجر. حدّث الصفحة قبل الحفظ.');
            $values = ['name' => trim($data['name']), 'kind' => $data['kind'], 'address' => trim($data['address']),
                'revision' => (int) $data['revision'] + 1, 'updated_at' => now()];
            if ($row) DB::table('go_stores')->where('user_id', $id)->update($values);
            else DB::table('go_stores')->insert($values + ['user_id' => $id, 'created_at' => now()]);
            return $this->store($id);
        });
    }

    public function products(int $id)
    {
        return DB::table('go_store_products')->where('user_id', $id)->orderByDesc('id');
    }

    public function product(int $owner, int $id): array
    {
        $row = $this->products($owner)->where('id', $id)->first();
        abort_unless($row, 404);
        return $this->present($row);
    }

    public function present($row): array
    {
        $options = json_decode($row->options, true) ?: [];
        return ['id' => (int) $row->id, 'name' => $row->name, 'description' => $row->description,
            'unit' => $row->unit, 'price' => $this->money((int) $row->price_cents),
            'image_url' => $row->image_path ? Storage::disk('public')->url($row->image_path) : '',
            'available' => (bool) $row->available, 'revision' => (int) $row->revision,
            'options' => array_map(function ($option) {
                return ['id' => $option['id'], 'label' => $option['label'], 'price' => $this->money($option['price_cents'])];
            }, $options)];
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function cents(string $value): int
    {
        $parts = explode('.', $value);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '0', 2, '0');
    }

    public function saveProduct(int $owner, Request $request, ?int $id = null): array
    {
        // Multipart clients send options as JSON, while the dashboard posts arrays.
        if (is_string($request->input('options'))) {
            $request->merge(['options' => json_decode($request->input('options'), true)]);
        }
        foreach (['name', 'unit', 'description'] as $key) {
            if (is_string($request->input($key))) $request->merge([$key => trim($request->input($key))]);
        }
        $priceRules = ['required', 'numeric', 'min:0.01', 'max:1000000', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'];
        $data = $request->validate([
            'name' => 'required|string|min:2|max:150', 'description' => 'nullable|string|max:2000',
            'unit' => 'required|string|min:1|max:40', 'price' => $priceRules,
            'available' => 'required|boolean', 'options' => 'present|array|max:20',
            'options.*.id' => 'nullable|uuid|distinct', 'options.*.label' => 'required|string|min:1|max:60|distinct',
            'options.*.price' => $priceRules, 'revision' => $id ? 'required|integer|min:1' : 'nullable',
            'request_key' => $id ? 'nullable' : 'required|uuid',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096,max_height=4096',
        ]);
        $labels = array_map(fn ($o) => mb_strtolower(trim($o['label'])), $data['options']);
        if (in_array('', $labels, true) || count($labels) !== count(array_unique($labels))) {
            throw ValidationException::withMessages(['options' => 'اكتب اسمًا مختلفًا لكل اختيار.']);
        }
        $newPath = null;
        try {
            return DB::transaction(function () use ($owner, $request, $data, $id, &$newPath) {
                DB::table('users')->where('id', $owner)->lockForUpdate()->first();
                abort_unless($this->store($owner), 409, 'احفظ بيانات المتجر أولًا.');
                if (!$id) {
                    $existing = $this->products($owner)->where('request_key', $data['request_key'])->first();
                    if ($existing) return $this->present($existing);
                }
                $old = $id ? $this->products($owner)->where('id', $id)->lockForUpdate()->first() : null;
                if ($id) abort_unless($old, 404);
                if ($old) abort_unless((int) $old->revision === (int) $data['revision'], 409, 'تم تعديل هذا المنتج. حدّث القائمة قبل التعديل.');
                if (!$old && !$request->hasFile('image')) {
                    throw ValidationException::withMessages(['image' => 'أضف صورة للمنتج.']);
                }
                $oldIds = $old ? array_column(json_decode($old->options, true) ?: [], 'id') : [];
                $options = [];
                foreach ($data['options'] as $option) {
                    if (!empty($option['id']) && !in_array($option['id'], $oldIds, true)) {
                        throw ValidationException::withMessages(['options' => 'اختيار المنتج غير صالح. حدّث الصفحة.']);
                    }
                    $options[] = ['id' => $option['id'] ?? (string) Str::uuid(), 'label' => trim($option['label']),
                        'price_cents' => $this->cents((string) $option['price'])];
                }
                if ($request->hasFile('image')) {
                    $newPath = $request->file('image')->store('go-stores/'.$owner, 'public');
                    abort_unless($newPath, 503, 'تعذر حفظ الصورة. حاول مرة أخرى.');
                }
                $values = ['name' => trim($data['name']), 'description' => $data['description'] ?? null,
                    'unit' => trim($data['unit']), 'price_cents' => $this->cents((string) $data['price']),
                    'image_path' => $newPath ?: $old->image_path, 'available' => (bool) $data['available'],
                    'options' => json_encode($options, JSON_UNESCAPED_UNICODE),
                    'revision' => $old ? (int) $old->revision + 1 : 1, 'updated_at' => now()];
                if ($old) $this->products($owner)->where('id', $id)->update($values);
                else $id = DB::table('go_store_products')->insertGetId($values + [
                    'user_id' => $owner, 'request_key' => $data['request_key'], 'created_at' => now()]);
                // Images are immutable. Retain previous versions for existing order snapshots.
                return $this->product($owner, $id);
            });
        } catch (\Throwable $error) {
            if ($newPath) Storage::disk('public')->delete($newPath);
            throw $error;
        }
    }
}
