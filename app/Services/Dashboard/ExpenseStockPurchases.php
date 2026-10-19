<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** An expense and its raw-goods receipt commit together; historical expenses are never inferred. */
class ExpenseStockPurchases
{
    public const CATEGORIES = [
        'purchase_pepper' => 'فلفل',
        'purchase_tomato' => 'طماطم',
        'purchase_lemon' => 'ليمون',
        'purchase_onion' => 'بصل',
        'purchase_oil' => 'زيت',
        'purchase_vinegar' => 'خل',
    ];

    private ?bool $available = null;
    private ?bool $receiptTable = null;
    private array $receipts = [];

    public function ready(): bool
    {
        return $this->available ??= $this->hasReceipts()
            && Schema::hasTable('stock_ingredients')
            && Schema::hasTable('branch_inventory')
            && Schema::hasTable('branch_inventory_movements');
    }

    private function hasReceipts(): bool
    {
        return $this->receiptTable ??= Schema::hasTable('expense_inventory_receipts');
    }

    /** Exact material labels, including the old branch-qualified expense labels. */
    public function categoryProduct(string $label): ?string
    {
        if (isset(self::CATEGORIES[$label])) return $label;
        $label = strtr(mb_strtolower($label), ['أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ة'=>'ه', 'ى'=>'ي']);
        $label = preg_replace('/[ـ\x{064B}-\x{065F}\x{200B}-\x{200F}\x{FEFF}]/u', '', $label);
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        $label = preg_replace('/^(?:مشتريات|شراء|توريد)\s+/u', '', $label);
        $label = preg_replace('/(?:\s+[-–—:|\/،(]?\s*|[-–—:|\/،(]\s*)(?:فرع\s+)?(?:المحله(?: الكبري)?|المنصوره|نبروه|شبرا(?: الخيمه)?|راس البر|الشيخ زايد)\s*\)?$/u', '', $label);
        $label = trim($label);
        foreach ([
            'purchase_pepper'=>['فلفل', 'الفلفل', 'فلفل اخضر', 'pepper'],
            'purchase_tomato'=>['طماطم', 'الطماطم', 'tomato', 'tomatoes'],
            'purchase_lemon'=>['ليمون', 'الليمون', 'lemon'],
            'purchase_onion'=>['بصل', 'البصل', 'onion'],
            'purchase_oil'=>['زيت', 'الزيت', 'زيت طعام', 'oil'],
            'purchase_vinegar'=>['خل', 'الخل', 'vinegar'],
        ] as $key => $names) {
            if (in_array($label, $names, true)) return $key;
        }
        return null;
    }

    /** The trusted category decides the stock link; posted stock identifiers never do. */
    public function normalizeQuantity(array $values, ?string $categoryKey): array
    {
        if ($categoryKey !== null && !isset(self::CATEGORIES[$categoryKey])) {
            throw ValidationException::withMessages(['category'=>'بند مشتريات البضاعة غير صالح.']);
        }
        $value = $values['quantity_kg'] ?? null;
        if ($categoryKey === null) {
            if ($value !== null && $value !== '') {
                throw ValidationException::withMessages(['quantity_kg'=>'عدد الكيلوات متاح لبنود مشتريات الفلفل والطماطم والليمون والبصل والزيت والخل فقط.']);
            }
            $values['quantity_kg'] = null;
        } else {
            $values['quantity_kg'] = $this->quantity($this->units($value));
        }
        $values['stock_category'] = $categoryKey;
        return $values;
    }

    private function units($value): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw ValidationException::withMessages(['quantity_kg'=>'أدخل عدد الكيلوات لهذا الصنف قبل تسجيل المصروف.']);
        }
        $value = strtr(trim((string)$value), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.']);
        if (!preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,3})?$/D', $value)) {
            throw ValidationException::withMessages(['quantity_kg'=>'أدخل كمية موجبة بالكيلو حتى ثلاث منازل عشرية.']);
        }
        $parts = explode('.', $value);
        $units = (int)$parts[0] * 1000000 + (int)str_pad($parts[1] ?? '', 6, '0');
        if ($units <= 0 || $units > 1000000000000) {
            throw ValidationException::withMessages(['quantity_kg'=>'عدد الكيلوات يجب أن يكون أكبر من صفر وحتى مليون كيلو.']);
        }
        return $units;
    }

    private function quantity(int $units): string
    {
        return rtrim(rtrim(intdiv($units, 1000000).'.'.str_pad((string)($units % 1000000), 6, '0', STR_PAD_LEFT), '0'), '.');
    }

    /** Caller owns the authorized branch lock and the enclosing expense transaction. */
    public function receive(int $expenseId, array $values, $actor): void
    {
        $key = $values['stock_category'] ?? (isset(self::CATEGORIES[$values['category'] ?? '']) ? $values['category'] : null);
        $values = $this->normalizeQuantity($values, $key);
        if ($key === null) return;
        abort_unless(DB::transactionLevel() > 0, 503, 'ربط المصروف بالبضاعة يحتاج معاملة واحدة.');
        abort_unless($this->ready(), 503, 'ربط مشتريات البضاعة يحتاج تحديث قاعدة البيانات.');
        $expense = DB::table('branch_expenses')->where('id', $expenseId)->lockForUpdate()->first();
        abort_unless($expense && $expense->branch === ($values['branch'] ?? null), 409, 'فرع المصروف غير مطابق لتوريد البضاعة.');
        abort_unless(preg_match('/^f:[1-9][0-9]*$/D', $expense->branch), 422, 'هذه المشتريات مرتبطة ببضاعة فروع فسخانستا فقط.');
        $units = $this->units($values['quantity_kg']);
        $old = DB::table('expense_inventory_receipts')->where('expense_id', $expenseId)->lockForUpdate()->first();
        if ($old) {
            abort_unless($old->branch === $expense->branch && $old->category_key === $key && (int)$old->quantity_units === $units, 409, 'المصروف مرتبط بتوريد بضاعة مختلف.');
            return;
        }
        abort_unless(in_array($expense->status, ['pending', 'approved'], true), 409, 'حالة المصروف لا تسمح بتوريد بضاعة.');
        $ingredients = DB::table('stock_ingredients')->where('name', self::CATEGORIES[$key])->get();
        abort_unless($ingredients->count() === 1 && $ingredients->first()->unit === 'kg', 503, 'صنف البضاعة غير مضبوط بالكيلو؛ راجع تحديث المشتريات.');
        $ingredient = $ingredients->first();
        $movementId = app(BranchInventory::class)->expenseMovement($expense->branch, (int)$ingredient->id, $units, $expenseId, (int)$actor->id, false, (string)($expense->supplier ?? ''));
        DB::table('expense_inventory_receipts')->insert([
            'expense_id'=>$expenseId, 'branch'=>$expense->branch, 'category_key'=>$key,
            'ingredient_id'=>$ingredient->id, 'item_name'=>self::CATEGORIES[$key], 'quantity_units'=>$units,
            'receipt_movement_id'=>$movementId, 'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
        ]);
        unset($this->receipts[$expenseId]);
    }

    /** Reverse the exact original receipt, including when some goods have since been sold. */
    public function reverse(object $expense, $actor): void
    {
        if (!$this->hasReceipts()) return;
        abort_unless(DB::transactionLevel() > 0, 503, 'عكس مشتريات البضاعة يحتاج معاملة المصروف.');
        $receipt = DB::table('expense_inventory_receipts')->where('expense_id', $expense->id)->lockForUpdate()->first();
        if (!$receipt || $receipt->reversal_movement_id !== null) return;
        abort_unless($this->ready() && $receipt->branch === $expense->branch, 503, 'تعذّر مطابقة توريد البضاعة بالمصروف.');
        $original = DB::table('branch_inventory_movements')->where('id', $receipt->receipt_movement_id)->first();
        abort_unless($original && $original->branch === $receipt->branch && (int)$original->ingredient_id === (int)$receipt->ingredient_id
            && $original->source_type === 'expense' && $original->source_id === (string)$expense->id
            && (int)$original->quantity_units === (int)$receipt->quantity_units, 409, 'حركة توريد المصروف غير مطابقة؛ راجع سجل البضاعة.');
        $movementId = app(BranchInventory::class)->expenseMovement($receipt->branch, (int)$receipt->ingredient_id, (int)$receipt->quantity_units, (int)$expense->id, (int)$actor->id, true);
        DB::table('expense_inventory_receipts')->where('id', $receipt->id)->update([
            'reversal_movement_id'=>$movementId, 'reversed_at'=>now('UTC'), 'updated_at'=>now('UTC'),
        ]);
        unset($this->receipts[(int)$expense->id]);
    }

    /** One metadata query for the listing, rather than one per expense card. */
    public function prime(array $expenseIds): void
    {
        // A surrounding desktop journal may still roll back after show() returns.
        // Never cache metadata observed in an uncommitted transaction.
        if (DB::transactionLevel() > 0) return;
        $ids = array_values(array_unique(array_map('intval', $expenseIds)));
        $missing = array_values(array_filter($ids, fn($id)=>!array_key_exists($id, $this->receipts)));
        if (!$missing) return;
        foreach ($missing as $id) $this->receipts[$id] = null;
        if (!$this->hasReceipts()) return;
        foreach (DB::table('expense_inventory_receipts')->whereIn('expense_id', $missing)->get() as $row) $this->receipts[(int)$row->expense_id] = $row;
    }

    public function present(object $expense): array
    {
        if (DB::transactionLevel() > 0) {
            unset($this->receipts[(int)$expense->id]);
            $receipt = $this->hasReceipts() ? DB::table('expense_inventory_receipts')->where('expense_id', $expense->id)->first() : null;
        } else {
            $this->prime([(int)$expense->id]);
            $receipt = $this->receipts[(int)$expense->id] ?? null;
        }
        return [
            'stock_quantity_kg'=>$receipt ? $this->quantity((int)$receipt->quantity_units) : null,
            'stock_item_name'=>$receipt->item_name ?? null,
            'stock_category'=>$receipt->category_key ?? null,
            'stock_unit'=>$receipt ? 'kg' : null,
            'stock_reversed'=>$receipt ? $receipt->reversal_movement_id !== null : false,
        ];
    }
}
