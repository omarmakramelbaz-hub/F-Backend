<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Edits uncollected application orders while preserving their saved line prices and options. */
class OrderBoardItems
{
    private OrderBoardService $board;
    private TakeawayCatalog $catalog;
    private TakeawayAccess $access;
    private array $management = [];
    private array $features = [];

    public function __construct(OrderBoardService $board, TakeawayCatalog $catalog, TakeawayAccess $access)
    {
        $this->board = $board;
        $this->catalog = $catalog;
        $this->access = $access;
    }

    public function available(string $source, object $row, $actor): bool
    {
        // Display callers and context() have already applied the board's persisted tenant scope.
        if (!$actor || !$this->canManage($actor)) return false;
        if ($source === 'legacy') {
            return ($row->type ?? '') === 'current' && ($row->payment_type ?? '') === 'cash'
                && in_array($row->status ?? '', ['pending', 'new_order', 'another_delegate', 'accepted'], true)
                && empty($row->delegate_id) && empty($row->delegate_from_out) && empty($row->transfer_price_by);
        }
        return $source === 'store' && ($row->payment_method ?? '') === 'cash'
            && ($row->payment_status ?? '') === 'cash_due'
            && in_array($row->status ?? '', ['pending', 'preparing'], true);
    }

    public function read(string $source, int $id, $actor): array
    {
        return DB::transaction(function () use ($source, $id, $actor) {
            [$row, $branch, $lines, $products, $rates] = $this->context($source, $id, $actor, false);
            $items = $this->presentLines($source, $lines, $products);
            $subtotal = array_sum(array_map(fn ($line) => $this->money($line['line_total']), $items));
            $delivery = $source === 'legacy' ? $this->money($row->delivery_price ?? 0) : (int) $row->delivery_cents;
            $tax = $source === 'legacy' ? $this->money($row->user_tax ?? 0) : 0;
            $service = $source === 'legacy' ? Money::commission($subtotal, $rates['service']) : 0;
            return ['success'=>true, 'order'=>[
                'key'=>$source.':'.$id, 'number'=>$row->order_no ?? ($source === 'store' ? 'GS-' : '#').$id,
                'revision'=>$this->revision($row, $lines, $products, $rates), 'items'=>$items,
                'subtotal'=>Money::decimal($subtotal), 'total'=>Money::decimal($subtotal + $delivery + $tax + $service),
                'delivery'=>Money::decimal($delivery), 'tax'=>Money::decimal($tax), 'service'=>Money::decimal($service),
                'pricing'=>['delivery'=>Money::decimal($delivery), 'tax_rate'=>Money::decimal($rates['tax']),
                    'service_rate'=>Money::decimal($rates['service'])],
                'quantity_hint'=>'للأصناف الموزونة أدخل الوزن الفعلي بالكيلو؛ 0.850 تعني 850 جرام. بقية الأصناف بالعدد.'],
                'catalog'=>array_values($products), 'save_url'=>route('order-board.items.save', [$source, $id])];
        });
    }

    public function save(string $source, int $id, array $values, $actor): array
    {
        $values = Validator::make($values, [
            'expected_revision'=>'required|string|size:64', 'items'=>'required|array|min:1|max:100',
            'items.*.line_id'=>'nullable|integer|min:0', 'items.*.product_id'=>'required|integer|min:0',
            'items.*.quantity'=>['required', 'regex:/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/D'],
            'items.*.option_id'=>'nullable|string|max:100', 'items.*.feature_id'=>'nullable|integer|min:0',
            'items.*.product_clean'=>'nullable|in:extra_clear,extra_clean,extra_vacuim',
        ])->validate();
        return DB::transaction(function () use ($source, $id, $values, $actor) {
            [$row, $branch, $lines, $products, $rates] = $this->context($source, $id, $actor, true, array_column($values['items'], 'product_id'));
            abort_unless(hash_equals($this->revision($row, $lines, $products, $rates), $values['expected_revision']),
                409, 'تغيرت بيانات الطلب أو الأسعار. افتح تعديل الأصناف مرة أخرى.');
            $old = [];
            foreach ($lines as $key => $line) $old[$source === 'legacy' ? (int) $line->id : $key] = $line;
            $seen = []; $prepared = []; $subtotal = 0; $baseTotal = 0;
            $catalogRows = $this->catalog->rows($branch, array_column($values['items'], 'product_id'), true);
            foreach ($values['items'] as $input) {
                $millis = $this->quantity($input['quantity']);
                $productId = (int) $input['product_id'];
                $hasLine = array_key_exists('line_id', $input) && $input['line_id'] !== null && $input['line_id'] !== '';
                $lineId = $hasLine ? (int) $input['line_id'] : null;
                $existing = $hasLine ? ($old[$lineId] ?? null) : null;
                abort_unless(!$hasLine || ($existing !== null && !isset($seen[$lineId])), 422, 'أحد الأصناف لا ينتمي إلى الطلب أو مكرر.');
                if ($hasLine) $seen[$lineId] = true;
                if ($existing !== null) {
                    $presented = $this->presentLines($source, [$existing], $products)[0];
                    // Existing options and extras remain intact; replace a line by removing and adding it.
                    abort_unless($presented['product_id'] === $productId, 422, 'لا يمكن تغيير هوية صنف محفوظ؛ احذفه وأضف الصنف المطلوب.');
                    foreach (['option_id', 'feature_id', 'product_clean'] as $key) {
                        if (array_key_exists($key, $input)) abort_unless((string) ($input[$key] ?? '') === (string) ($presented[$key] ?? ''), 422, 'خيارات الصنف المحفوظ تغيرت. أعد فتح الطلب.');
                    }
                    $unitPrice = $this->money($presented['unit_price']);
                    $lineTotal = $millis === $this->quantity($presented['quantity'])
                        ? $this->money($presented['line_total']) : $this->multiply($unitPrice, $millis);
                    $line = $presented + ['quantity_mode'=>$presented['unit'] === 'kg' ? 'weight' : 'piece'];
                    $line['quantity'] = $this->catalog->quantity($millis);
                    $line['quantity_millis'] = $millis;
                    $line['line_total'] = Money::decimal($lineTotal);
                    $line['base_total'] = $this->multiply($unitPrice, $millis);
                    $line['existing'] = $existing;
                    $line['changed'] = $millis !== $this->quantity($presented['quantity']);
                } else {
                    $product = $catalogRows[$productId] ?? null;
                    abort_unless($product, 422, 'الصنف غير موجود في فرع الطلب.');
                    $pricing = array_intersect_key($input, array_flip(['option_id', 'feature_id', 'product_clean']));
                    $quoted = $this->catalog->line($branch, $product, $pricing + [
                        'quantity_millis'=>$millis, 'quantity_mode'=>($products[$productId]['unit'] ?? '') === 'kg' ? 'weight' : 'piece'], true);
                    $line = $quoted + ['line_id'=>null, 'existing'=>null, 'changed'=>true,
                        'feature_id'=>(int) ($input['feature_id'] ?? 0), 'product_clean'=>$input['product_clean'] ?? '',
                        'line_total'=>$quoted['total'], 'base_total'=>$quoted['total_cents']];
                    if ($source === 'legacy' && $quoted['option_id'] !== '') {
                        preg_match('/^f:([0-9]+):(base|extra_clear|extra_clean|extra_vacuim)$/D', $quoted['option_id'], $option);
                        $line['feature_id'] = (int) ($option[1] ?? 0);
                        $line['product_clean'] = ($option[2] ?? 'base') === 'base' ? '' : $option[2];
                    }
                }
                $unit = $products[$productId]['unit'] ?? $line['unit'] ?? '';
                abort_unless($unit !== 'piece' || $millis % 1000 === 0, 422, 'هذا الصنف بالقطعة؛ أدخل عددًا صحيحًا.');
                $line['quantity_mode'] = $unit === 'kg' ? 'weight' : 'piece';
                $subtotal += $this->money($line['line_total']); $baseTotal += $line['base_total'];
                abort_unless($subtotal <= 100000000 && $baseTotal <= 100000000, 422, 'قيمة الطلب أكبر من الحد المسموح.');
                $prepared[] = $line;
            }
            app(BranchStock::class)->validateQuantities($branch['value'], $prepared);
            if ($source === 'legacy') $this->saveLegacy($row, $lines, $prepared, $subtotal, $baseTotal, $rates);
            else $this->saveStore($row, $prepared, $subtotal, $values['expected_revision']);
            return ['from_group'=>$this->phase($source, $row), 'key'=>$source.':'.$id];
        }, 3);
    }

    private function context(string $source, int $id, $actor, bool $lock, array $wanted = []): array
    {
        $actor = $this->access->actor($actor);
        abort_unless($this->canManage($actor), 403);
        $row = $this->board->scopedRow($source, $id, $actor, $lock);
        abort_unless($this->available($source, $row, $actor), 409, 'تعديل الأصناف متاح للطلب الكاش قبل التحصيل وتسليمه للمندوب.');
        $branch = $this->access->branch(($source === 'legacy' ? 'f:'.$row->resturant_id : 'gs:'.$row->store_id), $actor, $lock);
        if ($source === 'legacy') {
            if (Schema::hasTable('payments') && Schema::hasColumns('payments', ['order_id', 'status'])) {
                $payments = DB::table('payments')->where('order_id', $id);
                if ($lock) $payments->lockForUpdate();
                foreach ($payments->get() as $payment) abort_if(in_array(strtolower((string) $payment->status),
                    ['1', 'true', 'paid', 'succeeded', 'success'], true), 409, 'تم دفع هذا الطلب؛ لا يمكن تغيير الأصناف من شاشة الاستقبال.');
            }
            $query = DB::table('carts')->where('order_id', $id)->orderBy('id');
            if ($lock) $query->lockForUpdate();
            $lines = $query->get()->all();
        } else {
            $snapshot = json_decode($row->snapshot ?? '', true);
            abort_unless(is_array($snapshot) && isset($snapshot['items']) && is_array($snapshot['items']), 409, 'تفاصيل الطلب المحفوظة غير صالحة.');
            $lines = array_values($snapshot['items']);
        }
        $listing = $this->catalog->listing(['branch'=>$branch['value'], 'per_page'=>5000, 'lock_catalog'=>$lock], $actor);
        abort_unless($listing['pagination']['total'] <= 5000, 409, 'قائمة الفرع كبيرة جدًا؛ راجع إدارة الأصناف.');
        $products = [];
        foreach ($listing['items'] as $item) {
            $unit = $this->unit($item['unit'] ?? '');
            $options = $item['options'] ?? [];
            foreach ($options as &$option) {
                $option['weight_per_unit'] = $this->weightPerUnit((int) ($option['feature_id'] ?? 0), $unit);
            }
            unset($option);
            $item['options'] = $options;
            $products[(int) $item['id']] = array_intersect_key($item, array_flip(['id','name','price','unit_price','available','options','revision'])) + [
                'product_id'=>(int) $item['id'], 'unit'=>$unit, 'quantity_step'=>$unit === 'piece' ? '1' : '0.001',
                'weight_per_unit'=>$this->weightPerUnit(0, $unit)];
        }
        $rates = ['tax'=>$this->rate('tax', $lock), 'service'=>$this->rate('service_fees', $lock)];
        return [$row, $branch, $lines, $products, $rates];
    }

    private function presentLines(string $source, array $lines, array $products): array
    {
        $items = [];
        foreach ($lines as $key => $raw) {
            $line = (object) $raw;
            $productId = (int) ($source === 'legacy' ? ($line->resturant_product_id ?? 0) : ($line->product_id ?? 0));
            $product = $products[$productId] ?? [];
            $feature = $source === 'legacy' ? (int) ($line->product_feature ?? 0) : 0;
            $clean = $source === 'legacy' ? (string) ($line->product_clean ?? '') : '';
            $optionId = $source === 'legacy' ? ($feature || $clean ? 'f:'.$feature.':'.($clean ?: 'base') : '') : (string) ($line->option_id ?? '');
            $label = (string) ($line->option_label ?? '');
            foreach ($product['options'] ?? [] as $option) if ($option['id'] === $optionId) $label = $option['label'];
            if (!$label && $feature && Schema::hasTable('product_features')) {
                $name = $this->feature($feature);
                $label = ['kilo'=>'كيلو','half'=>'نصف','quarter'=>'ربع','large'=>'كبير','medium'=>'وسط','combo'=>'كومبو'][$name] ?? (string) $name;
            }
            $cleanLabel = ['extra_clear'=>'تنظيف إضافي','extra_clean'=>'تنظيف','extra_vacuim'=>'تغليف مفرغ'][$clean] ?? $clean;
            if ($clean && !str_contains($label, $cleanLabel)) $label .= ($label ? ' / ' : '').$cleanLabel;
            $quantity = $this->quantity($source === 'legacy' ? ($line->qty ?? 0) : ($line->quantity ?? 0));
            $price = $this->money($source === 'legacy' ? ($line->price ?? 0) : ($line->unit_price ?? $line->price ?? 0));
            $total = $source === 'legacy' ? (isset($line->updated_total) ? $this->money($line->updated_total) : $this->multiply($price, $quantity))
                : (isset($line->line_total) ? $this->money($line->line_total) : $this->multiply($price, $quantity));
            $unit = $product['unit'] ?? $this->unit($line->unit ?? '');
            $items[] = ['line_id'=>$source === 'legacy' ? (int) $line->id : $key, 'product_id'=>$productId,
                'name'=>$source === 'legacy' ? ($product['name'] ?? $line->name ?? 'صنف') : ($line->name ?? $product['name'] ?? 'صنف'),
                'quantity'=>$this->catalog->quantity($quantity), 'unit'=>$unit, 'quantity_step'=>$unit === 'piece' ? '1' : '0.001',
                'weight_per_unit'=>$this->weightPerUnit($feature, $unit),
                'feature_id'=>$feature, 'product_clean'=>$clean, 'option_id'=>$optionId, 'option_label'=>trim($label),
                'unit_price'=>Money::decimal($price), 'line_total'=>Money::decimal($total)];
        }
        return $items;
    }

    private function saveLegacy(object $row, array $old, array $lines, int $subtotal, int $baseTotal, array $rates): void
    {
        $kept = [];
        foreach ($lines as $line) {
            if ($line['existing'] !== null) {
                $kept[] = (int) $line['line_id'];
                if (!$line['changed'] && isset($line['existing']->updated_total)) continue;
                $changes = ['qty'=>$line['quantity']];
                // Persist line-level currency rounding so model, receipt, tax and settlement agree.
                if (Schema::hasColumn('carts', 'updated_total')) $changes['updated_total'] = $line['line_total'];
                if ($line['changed'] && Schema::hasColumn('carts', 'reason_update_total')) $changes['reason_update_total'] = null;
                if (Schema::hasColumn('carts', 'updated_at')) $changes['updated_at'] = now();
                DB::table('carts')->where('order_id', $row->id)->where('id', $line['line_id'])->update($changes);
                continue;
            }
            $values = ['order_id'=>$row->id, 'user_id'=>$row->user_id, 'resturant_id'=>$row->resturant_id,
                'resturant_product_id'=>$line['product_id'], 'qty'=>$line['quantity'], 'price'=>$line['unit_price'],
                'updated_total'=>$line['line_total'],
                'product_feature'=>$line['feature_id'] ?: null, 'product_clean'=>$line['product_clean'] ?: null, 'is_order'=>true,
                'created_at'=>now(), 'updated_at'=>now()];
            $values = array_intersect_key($values, array_flip(Schema::getColumnListing('carts')));
            $kept[] = DB::table('carts')->insertGetId($values);
        }
        DB::table('carts')->where('order_id', $row->id)->whereNotIn('id', $kept)->delete();
        $restaurant = DB::table('resturants')->where('id', $row->resturant_id)->first();
        $vendorTax = Money::commission($subtotal, Money::rate($restaurant->service_fees ?? 0));
        $changes = ['total_price'=>Money::decimal($baseTotal), 'vendor_tax'=>Money::decimal($vendorTax),
            'tax'=>Money::decimal(Money::commission($vendorTax, $rates['tax'])),
            'user_tax'=>Money::decimal(Money::commission($subtotal, $rates['tax'])), 'updated_at'=>now()];
        DB::table('orders')->where('id', $row->id)->update(array_intersect_key($changes, array_flip(Schema::getColumnListing('orders'))));
    }

    private function saveStore(object $row, array $lines, int $subtotal, string $revision): void
    {
        $snapshot = json_decode($row->snapshot, true);
        $items = [];
        foreach ($lines as $line) {
            $item = $line['existing'] !== null ? (array) $line['existing'] : array_intersect_key($line,
                array_flip(['product_id','name','option_id','option_label','unit','unit_price']));
            $item['quantity'] = $line['quantity']; $item['line_total'] = $line['line_total'];
            $items[] = $item;
        }
        $commission = Money::commission($subtotal, (int) $row->commission_bps);
        if ($row->status === 'preparing') $this->adjustStoreCommission($row, $commission - (int) $row->commission_cents, $revision);
        $total = $subtotal + (int) $row->delivery_cents;
        $snapshot['items'] = $items; $snapshot['subtotal'] = Money::decimal($subtotal); $snapshot['total'] = Money::decimal($total);
        DB::table('go_store_orders')->where('id', $row->id)->update(['snapshot'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'subtotal_cents'=>$subtotal, 'total_cents'=>$total, 'commission_cents'=>$commission,
            'revision'=>(int) $row->revision + 1, 'updated_at'=>now()]);
    }

    private function adjustStoreCommission(object $row, int $delta, string $revision): void
    {
        if (!$delta) return;
        abort_unless(Schema::hasTable('wallets') && Schema::hasColumn('wallets', 'transfer_reference'), 503, 'تحديث عمولة المتجر غير مجهز.');
        $owner = DB::table('users')->where('id', $row->store_id)->lockForUpdate()->first();
        $setting = DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->lockForUpdate()->first();
        abort_unless($owner && $setting, 503, 'محفظة المتجر أو التطبيق غير مجهزة.');
        $charged = 0;
        $ledger = DB::table('wallets')->where(function ($query) use ($row) {
            $query->where('transfer_reference', 'gs:'.$row->id.':fee')
                ->orWhere('transfer_reference', 'like', 'gs:'.$row->id.':items:%');
        })->lockForUpdate()->get();
        foreach ($ledger as $movement) {
            abort_unless(($movement->status ?? '') === 'completed', 409, 'تسوية عمولة المتجر غير مكتملة.');
            $sign = (int) ($movement->from_user ?? 0) === (int) $row->store_id ? 1 : -1;
            $charged += $sign * $this->money($movement->amount);
        }
        abort_unless($charged === (int) $row->commission_cents, 409, 'راجع تسوية عمولة الطلب قبل تعديل الأصناف.');
        $reference = 'gs:'.$row->id.':items:'.substr($revision, 0, 32);
        abort_if(DB::table('wallets')->where('transfer_reference', $reference)->exists(), 409, 'تم حفظ تعديل الطلب مسبقًا. أعد فتحه.');
        DB::table('users')->where('id', $row->store_id)->decrement('balance', Money::decimal($delta));
        DB::table('settings')->where('id', $setting->id)->update(['payload'=>json_encode(Money::decimal($this->money(json_decode($setting->payload, true)) + $delta))]);
        DB::table('wallets')->insert(['transfer_reference'=>$reference, 'from_user'=>$delta > 0 ? $row->store_id : null,
            'to_user'=>$delta < 0 ? $row->store_id : null, 'amount'=>Money::decimal(abs($delta)), 'status'=>'completed',
            'payment'=>'wallet', 'type'=>'transfer', 'created_at'=>now(), 'updated_at'=>now()]);
    }

    private function revision(object $row, array $lines, array $products, array $rates): string
    {
        return hash_hmac('sha256', json_encode([$row, $lines, $products, $rates], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function canManage($actor): bool
    {
        if (!$this->board->isAdmin($actor)) return true;
        if (!array_key_exists((int) $actor->id, $this->management)) {
            $super = (int) $actor->id === 1 || (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')
                && $actor->roles()->where('guard_name', 'admin')->where('name', 'Super Admin')->exists());
            $this->management[(int) $actor->id] = $super || $actor->can('order-edit');
        }
        return $this->management[(int) $actor->id];
    }

    private function rate(string $name, bool $lock = false): int
    {
        $query = Schema::hasTable('settings') ? DB::table('settings')->where('name', $name) : null;
        if ($query && $lock) $query->lockForUpdate();
        $raw = $query ? ($query->first()->payload ?? null) : null;
        $value = $raw === null ? 0 : json_decode($raw, true);
        return Money::rate(is_scalar($value) ? $value : 0);
    }

    private function unit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        if (in_array($unit, ['piece', 'unit', 'pcs', 'قطعة', 'علبة', 'عبوة', 'عدد'], true)) return 'piece';
        return in_array($unit, ['kg', 'kilogram', 'كيلو', 'كجم', 'كغ'], true) ? 'kg' : '';
    }

    private function feature(int $id): ?string
    {
        if (!array_key_exists($id, $this->features)) {
            $this->features[$id] = $id && Schema::hasTable('product_features')
                ? DB::table('product_features')->where('id', $id)->value('name') : null;
        }
        return $this->features[$id];
    }

    private function weightPerUnit(int $feature, string $unit): ?string
    {
        $name = $this->feature($feature);
        if ($name === 'half') return '0.500';
        if ($name === 'quarter') return '0.250';
        return $name === 'kilo' || $unit === 'kg' ? '1.000' : null;
    }

    private function quantity($value): int
    {
        $value = (string) $value;
        abort_unless(preg_match('/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/D', $value, $match), 422, 'أدخل الوزن أو الكمية حتى ثلاث منازل عشرية.');
        $parts = explode('.', $value);
        $quantity = (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
        abort_unless($quantity > 0 && $quantity <= 1000000, 422, 'الكمية يجب أن تكون أكبر من صفر وحتى 1000.');
        return $quantity;
    }

    private function money($value): int
    {
        try { $amount = Money::minor($value); }
        catch (\InvalidArgumentException $error) { abort(409, 'أحد مبالغ الطلب غير صالح.'); }
        abort_unless($amount >= 0 && $amount <= 100000000, 409, 'أحد مبالغ الطلب خارج الحد المسموح.');
        return $amount;
    }

    private function multiply(int $price, int $quantity): int
    {
        $total = intdiv($price * $quantity + 500, 1000);
        abort_unless($total <= 100000000, 422, 'قيمة الصنف أكبر من الحد المسموح.');
        return $total;
    }

    private function phase(string $source, object $row): string
    {
        return in_array($row->status, ['accepted', 'preparing'], true) || ($source === 'legacy' && ($row->accepted_notify ?? '') === 'yes') ? 'preparing' : 'new';
    }
}
