<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class Stock
{
    public function post(Actor $actor, array $data): int
    {
        return $this->move($actor,$data,null);
    }

    public function purchaseReceipt(Actor $actor, array $data, int $supplier): int
    {
        $actor->require('purchasing.manage');
        $data['type']='purchase';
        return $this->move($actor,$data,$supplier);
    }

    private function move(Actor $actor, array $data, ?int $supplier): int
    {
        $actor->require('inventory.manage');
        $type = $data['type'];
        abort_unless(in_array($type, ['opening','receipt','transfer','waste','count'], true) || ($type==='purchase' && $supplier !== null), 422, 'نوع حركة غير صالح.');
        abort_unless(preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $data['request_key'] ?? ''), 422, 'مرجع العملية غير صالح.');
        abort_unless(trim($data['reason'] ?? '') !== '' && mb_strlen($data['reason']) <= 500, 422, 'سبب الحركة مطلوب.');
        $item = DB::table('erp_items')->where('id', $data['item_id'])->where('active', true)->first();
        abort_unless($item, 422, 'الصنف غير متاح.');
        $qty = Decimal::quantity($data['quantity'], $item->unit);
        abort_if($type !== 'count' && $qty === 0, 422, 'الكمية يجب أن تكون أكبر من صفر.');
        $source = $this->warehouse($actor, (int) $data['warehouse_id']);
        $destination = $type === 'transfer' ? $this->warehouse($actor, (int) ($data['destination_id'] ?? 0)) : null;
        abort_if($destination && $destination->id === $source->id, 422, 'اختر مخزنًا مختلفًا للاستلام.');
        $unitCost = in_array($type, ['opening','receipt','count','purchase'], true) ? Decimal::money($data['unit_cost'] ?? '0') : 0;
        $expected = $type === 'count' ? Decimal::quantity($data['expected_quantity'] ?? '', $item->unit) : null;
        $payload = [$type, (int) $item->id, (int) $source->id, $destination ? (int) $destination->id : null, $qty, $unitCost, $expected, trim($data['reason']), $data['reference'] ?? null];
        $hash = hash('sha256', json_encode($payload));

        return DB::transaction(function () use ($actor, $data, $type, $item, $qty, $source, $destination, $unitCost, $expected, $hash, $supplier) {
            Ledger::lock();
            $ids = [(int) $source->id];
            if ($destination) { $ids[] = (int) $destination->id; }
            sort($ids, SORT_NUMERIC);
            $balances = [];
            // Same lock order for opposite transfers; unique row prevents lost first receipts.
            foreach ($ids as $id) {
                DB::table('erp_stock_balances')->insertOrIgnore(['warehouse_id' => $id, 'item_id' => $item->id, 'quantity_milli' => 0, 'value_minor' => 0]);
                $balances[$id] = DB::table('erp_stock_balances')->where('warehouse_id', $id)->where('item_id', $item->id)->lockForUpdate()->first();
            }
            $previous = DB::table('erp_stock_documents')->where('request_key', $data['request_key'])->first();
            if ($previous) {
                abort_unless($previous->actor_key === $actor->key && hash_equals($previous->payload_hash, $hash), 409, 'مرجع العملية مستخدم لبيانات أخرى.');
                return (int) $previous->id;
            }
            $balance = $balances[$source->id];
            $before = (int) $balance->quantity_milli;
            $value = (int) $balance->value_minor;
            if ($type === 'opening') {
                abort_if(DB::table('erp_stock_entries')->where('warehouse_id', $source->id)->where('item_id', $item->id)->exists(), 409, 'تم تسجيل رصيد افتتاحي أو حركة لهذا الصنف؛ استخدم الاستلام أو الجرد.');
            }
            $delta = $qty;
            if ($type === 'count') {
                abort_unless($before === $expected, 409, 'تغيّر الرصيد منذ فتح الجرد؛ حدّث الصفحة ثم أعد الجرد.');
                $delta = $qty - $before;
                abort_if($delta === 0, 422, 'لا يوجد فرق جرد يحتاج تسوية.');
            } elseif (in_array($type, ['transfer','waste'], true)) {
                $delta = -$qty;
            }
            abort_if($before + $delta < 0, 422, 'الرصيد غير كافٍ؛ المخزون السالب غير مسموح.');
            if ($delta > 0) {
                abort_if($unitCost === 0, 422, 'أدخل تكلفة الوحدة للكمية الواردة.');
                $movementValue = intdiv($delta * $unitCost + 500, 1000);
                abort_if($movementValue === 0, 422, 'قيمة الحركة أقل من قرش.');
            } else {
                $movementValue = $before === -$delta ? $value : intdiv($value * (-$delta) + intdiv($before, 2), $before);
            }
            $id = DB::table('erp_stock_documents')->insertGetId([
                'request_key' => $data['request_key'], 'payload_hash' => $hash, 'actor_key' => $actor->key,
                'type' => $type, 'item_id' => $item->id, 'warehouse_id' => $source->id,
                'destination_id' => $destination ? $destination->id : null,
                'quantity_milli' => abs($delta), 'value_minor' => $movementValue,
                'reference' => $data['reference'] ?? null, 'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
            $this->entry($id, $balance, $delta, $delta > 0 ? $movementValue : -$movementValue);
            if ($destination) {
                $this->entry($id, $balances[$destination->id], $qty, $movementValue);
            }
            Ledger::stock($actor,(int)$id,$type,$item,$source,$destination,$delta,$movementValue,$supplier);
            $actor->audit('stock.'.$type, 'stock_document', $id, ['item_id' => $item->id, 'quantity_milli' => $delta, 'value_minor' => $movementValue, 'destination_id' => $destination ? $destination->id : null], $source->branch_id ? (int) $source->branch_id : null);
            return (int) $id;
        }, 3);
    }

    public function warehouse(Actor $actor, int $id)
    {
        $warehouse = DB::table('erp_warehouses')->where('id', $id)->first();
        abort_unless($warehouse, 404, 'المخزن غير موجود.');
        $actor->branch($warehouse->branch_id ? (int) $warehouse->branch_id : null, true);
        return $warehouse;
    }

    public function entry(int $document, $balance, int $qty, int $value): void
    {
        $newQty = (int) $balance->quantity_milli + $qty;
        $newValue = (int) $balance->value_minor + $value;
        // Bounds also keep valuation multiplication safely inside signed 64-bit integers.
        abort_if($newQty < 0 || $newQty > 100000000 || $newValue < 0 || $newValue > 10000000000, 422, 'رصيد الحركة خارج الحدود المسموحة.');
        DB::table('erp_stock_balances')->where('id', $balance->id)->update(['quantity_milli' => $newQty, 'value_minor' => $newValue]);
        DB::table('erp_stock_entries')->insert([
            'document_id' => $document, 'warehouse_id' => $balance->warehouse_id,
            'item_id' => $balance->item_id, 'quantity_milli' => $qty, 'value_minor' => $value, 'created_at' => now(),
        ]);
    }
}
