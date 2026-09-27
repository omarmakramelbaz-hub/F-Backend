<?php

namespace App\Services\GoStores;

use App\Models\PendingVendor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A reviewed catalog travels with the existing application media, before an owner account exists. */
class ApplicationCatalog
{
    public function validate(Request $request): ?array
    {
        if (!$request->has('storefront')) return null; // Existing app versions remain compatible.
        if ($request->input('profession_key') !== 'store_owner') {
            throw ValidationException::withMessages(['storefront' => 'بيانات المتجر متاحة لطلبات المتاجر فقط.']);
        }
        app(Catalog::class)->ready();
        if (is_string($request->input('storefront'))) {
            $request->merge(['storefront' => json_decode($request->input('storefront'), true)]);
        }
        $price = ['required', 'numeric', 'min:0.01', 'max:1000000', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/D'];
        $data = $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'storefront' => 'required|array',
            'storefront.name' => 'required|string|min:2|max:150',
            'storefront.kind' => 'required|in:supermarket,restaurant,pharmacy',
            'storefront.address' => 'required|string|min:5|max:500',
            'storefront.products' => 'required|array|min:1|max:60',
            'storefront.products.*.name' => 'required|string|min:2|max:150',
            'storefront.products.*.description' => 'nullable|string|max:2000',
            'storefront.products.*.unit' => 'required|string|min:1|max:40',
            'storefront.products.*.price' => $price,
            'storefront.products.*.options' => 'present|array|max:20',
            'storefront.products.*.options.*.label' => 'required|string|min:1|max:60',
            'storefront.products.*.options.*.price' => $price,
            'store_logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:1024|dimensions:max_width=4096,max_height=4096',
            'product_images' => 'required|array|min:1|max:60',
            'product_images.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:1024|dimensions:max_width=4096,max_height=4096',
        ]);
        $store = $data['storefront'];
        if (array_keys($store['products']) !== range(0, count($store['products']) - 1)
            || array_keys($request->file('product_images')) !== array_keys($store['products'])) {
            throw ValidationException::withMessages(['product_images' => 'أضف صورة لكل منتج.']);
        }
        foreach (['name' => 2, 'address' => 5] as $key => $minimum) {
            if (mb_strlen(trim($store[$key])) < $minimum) throw ValidationException::withMessages(['storefront.'.$key => 'أكمل بيانات المتجر.']);
            $store[$key] = trim($store[$key]);
        }
        foreach ($store['products'] as $product) {
            $labels = array_map(fn ($o) => mb_strtolower(trim($o['label'])), $product['options']);
            if (mb_strlen(trim($product['name'])) < 2 || trim($product['unit']) === ''
                || in_array('', $labels, true) || count($labels) !== count(array_unique($labels))) {
                throw ValidationException::withMessages(['storefront.products' => 'راجع اسم المنتج ووحدته، واكتب اسمًا مختلفًا لكل اختيار.']);
            }
        }
        $size = $request->file('store_logo')->getSize() + ($request->file('photo')?->getSize() ?? 0);
        foreach ($request->file('product_images') as $image) $size += $image->getSize();
        if (!$request->attributes->get('go_staged_catalog') && $size > 6 * 1024 * 1024) throw ValidationException::withMessages(['product_images' => 'إجمالي الصور يجب ألا يتجاوز 6 ميجا. قلّل حجم الصور أو عدد المنتجات.']);
        return $store;
    }

    public function capture(PendingVendor $application, Request $request, array $store): void
    {
        $application->addMedia($request->file('store_logo'))->preservingOriginal()->withCustomProperties([
            'catalog_version' => 1, 'name' => $store['name'], 'kind' => $store['kind'], 'address' => $store['address'],
        ])->toMediaCollection('go_store_draft_logo', 'public');
        foreach ($store['products'] as $index => $product) {
            $options = array_map(fn ($option) => ['id' => (string) Str::uuid(), 'label' => trim($option['label']),
                'price_cents' => $this->cents((string) $option['price'])], $product['options']);
            $application->addMedia($request->file('product_images.'.$index))->preservingOriginal()->withCustomProperties([
                'name' => trim($product['name']), 'description' => trim($product['description'] ?? ''),
                'unit' => trim($product['unit']), 'price_cents' => $this->cents((string) $product['price']),
                'options' => $options, 'request_key' => (string) Str::uuid(),
            ])->toMediaCollection('go_store_draft_product', 'public');
        }
    }

    /** Called inside the approval/activation transaction. Moving media ownership retains immutable file paths. */
    public function promote(PendingVendor $application, User $owner): void
    {
        if ($application->profession_key !== 'store_owner') return;
        $logo = $application->getMedia('go_store_draft_logo')->first();
        if (!$logo) return;
        abort_unless($owner->app_scope === 'go_partner' && (int) $owner->pending_vendor_id === (int) $application->id, 409);
        app(Catalog::class)->ready();
        abort_if(DB::table('go_stores')->where('user_id', $owner->id)->exists(), 409, 'يوجد متجر لهذا الحساب بالفعل. راجع بيانات الطلب قبل الموافقة.');
        DB::table('go_stores')->insert([
            'user_id' => $owner->id, 'name' => $logo->getCustomProperty('name'), 'kind' => $logo->getCustomProperty('kind'),
            'address' => $logo->getCustomProperty('address'), 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($application->getMedia('go_store_draft_product') as $media) {
            $root = Storage::disk('public')->path('');
            abort_unless($media->disk === 'public' && Str::startsWith($media->getPath(), $root), 409);
            DB::table('go_store_products')->insert([
                'user_id' => $owner->id, 'request_key' => $media->getCustomProperty('request_key'),
                'name' => $media->getCustomProperty('name'), 'description' => $media->getCustomProperty('description'),
                'unit' => $media->getCustomProperty('unit'), 'price_cents' => $media->getCustomProperty('price_cents'),
                'options' => json_encode($media->getCustomProperty('options'), JSON_UNESCAPED_UNICODE),
                'image_path' => ltrim(substr($media->getPath(), strlen($root)), '/'), 'available' => true,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $media->forceFill(['model_type' => $owner->getMorphClass(), 'model_id' => $owner->id, 'collection_name' => 'go_store_product'])->save();
        }
        $logo->forceFill(['model_type' => $owner->getMorphClass(), 'model_id' => $owner->id, 'collection_name' => 'go_store_logo'])->save();
        $application->unsetRelation('media');
    }

    public function review(PendingVendor $application): ?array
    {
        if ($application->profession_key !== 'store_owner') return null;
        $logo = $application->getMedia('go_store_draft_logo')->first();
        $products = $application->getMedia('go_store_draft_product');
        if (!$logo) {
            $owner = User::withoutGlobalScopes()->where('app_scope', 'go_partner')->where('pending_vendor_id', $application->id)->first();
            if (!$owner) return null;
            $logo = $owner->getMedia('go_store_logo')->first();
            $products = $owner->getMedia('go_store_product');
        }
        if (!$logo) return null;
        return $logo->custom_properties + ['logo_url' => $logo->getUrl(),
            'products' => $products->map(fn ($media) => $media->custom_properties + ['image_url' => $media->getUrl()])->all()];
    }

    private function cents(string $value): int
    {
        $parts = explode('.', $value);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '0', 2, '0');
    }
}
