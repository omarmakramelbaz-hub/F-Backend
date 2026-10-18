<?php

namespace App\Services\Dashboard;

use App\Mail\BranchStockMinimumAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log, Mail, Schema, Validator};

/** Per-branch minimums with transaction-bound low-stock episodes and a durable mail outbox. */
class BranchStockMinimums
{
    public function installed(): bool
    {
        return Schema::hasTable('branch_stock_minimums') && Schema::hasTable('branch_stock_minimum_alerts');
    }

    public function canManage($actor): bool
    {
        $a = app(TakeawayAccess::class)->actor($actor);
        return $a->account_type === 'admin' && empty($a->owner_resturant_id);
    }

    public function metadata($actor): array
    {
        return ['minimum_alerts_available'=>$this->installed(), 'can_manage_minimum'=>$this->canManage($actor)];
    }

    private function quantity(int $units): string
    {
        $n = abs($units);
        $s = intdiv($n, 1000000).'.'.str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
        return ($units < 0 ? '-' : '').rtrim(rtrim($s, '0'), '.');
    }

    private function quantityUnits(string $quantity): int
    {
        $parts = explode('.', ltrim($quantity, '-'));
        return (($quantity[0] ?? '') === '-' ? -1 : 1) * ((int)$parts[0] * 1000000 + (int)str_pad($parts[1] ?? '', 6, '0'));
    }

    /** Balances are keyed by ingredient/product id; preserve the existing stock API fields. */
    public function decorateStocks(string $branch, string $type, array $stocks): array
    {
        $rows = $this->installed() && $stocks
            ? DB::table('branch_stock_minimums')->where('branch', $branch)->where('stock_type', $type)->whereIn('stock_item_id', array_keys($stocks))->get()->keyBy('stock_item_id')->all() : [];
        foreach ($stocks as $id => &$stock) {
            $minimum = $rows[$id] ?? null;
            $enabled = $minimum && $minimum->minimum_units !== null && $minimum->unit === ($stock['unit'] ?? null);
            $stock['stock_type'] = $type;
            $stock['minimum_quantity'] = $enabled ? $this->quantity((int)$minimum->minimum_units) : null;
            $stock['minimum_revision'] = (int)($minimum->revision ?? 0);
            $stock['low_stock'] = $enabled && $this->quantityUnits((string)$stock['quantity']) <= (int)$minimum->minimum_units;
        }
        unset($stock);
        return $stocks;
    }

    public function save(array $values, $actor): array
    {
        abort_unless($this->installed(), 503, 'الحد الأدنى للبضاعة يحتاج تحديث قاعدة البيانات.');
        abort_unless($this->canManage($actor), 403, 'تعديل الحد الأدنى متاح للمالك والإدارة فقط.');
        $ops = app(BranchOperations::class);
        $rules = $ops->rules();
        $rules['expected_revision'] = 'required|integer|min:0';
        $v = Validator::make($values, $rules + [
            'stock_type'=>'required|in:ingredient,product', 'stock_item_id'=>'required|integer|min:1',
            'minimum_quantity'=>'present|nullable|string|max:16',
        ])->validate();
        $minimum = null;
        if ($v['minimum_quantity'] !== null) {
            abort_unless(preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,3})?$/D', $v['minimum_quantity']), 422, 'أدخل حدًا أدنى صحيحًا حتى ثلاث منازل عشرية.');
            $minimum = $this->quantityUnits($v['minimum_quantity']);
            abort_unless($minimum <= 1000000000000, 422, 'الحد الأدنى يجب ألا يتجاوز مليونًا.');
        }
        return $ops->write('stock.minimum', $v, $actor, function ($branch, $a) use ($v, $minimum) {
            abort_unless($branch['kind'] === 'f' && $this->canManage($a), 403);
            $item = $this->item($v['branch'], $v['stock_type'], (int)$v['stock_item_id'], true);
            abort_unless($item, 404, 'صنف البضاعة غير متاح في هذا الفرع.');
            abort_if($item->unit === 'piece' && $minimum !== null && $minimum % 1000000 !== 0, 422, 'الحد الأدنى للقطع يجب أن يكون عددًا صحيحًا.');
            $q = DB::table('branch_stock_minimums')->where('branch', $v['branch'])->where('stock_type', $v['stock_type'])->where('stock_item_id', $v['stock_item_id']);
            $r = (clone $q)->lockForUpdate()->first();
            abort_unless((int)$v['expected_revision'] === (int)($r->revision ?? 0), 409, 'الحد الأدنى تغير؛ حدّث الصفحة قبل الحفظ.');
            $data = ['minimum_units'=>$minimum, 'unit'=>$item->unit, 'revision'=>(int)($r->revision ?? 0)+1, 'updated_by'=>$a->id, 'updated_at'=>now('UTC')];
            if ($r) $q->update($data);
            else DB::table('branch_stock_minimums')->insert($data + ['branch'=>$v['branch'], 'stock_type'=>$v['stock_type'], 'stock_item_id'=>$v['stock_item_id'], 'created_at'=>now('UTC')]);
            $this->observe($v['branch'], $v['stock_type'], (int)$v['stock_item_id'], (int)$item->quantity_units, $item->unit);
            $stock = $this->decorateStocks($v['branch'], $v['stock_type'], [$v['stock_item_id']=>[
                'quantity'=>$this->quantity((int)$item->quantity_units), 'unit'=>$item->unit, 'unit_label'=>$item->unit === 'kg' ? 'كجم' : 'قطعة',
            ]])[$v['stock_item_id']];
            return ['stock'=>$stock, 'operation'=>['branch'=>$v['branch'], 'stock_type'=>$v['stock_type'], 'stock_item_id'=>(int)$v['stock_item_id'], 'idempotency_key'=>$v['idempotency_key']]];
        });
    }

    /** Caller holds the branch/stock locks. The episode and pending email roll back with the movement. */
    public function observe(string $branch, string $type, int $id, int $balance, string $unit): void
    {
        if (!$this->installed()) return;
        $work = function () use ($branch, $type, $id, $balance, $unit) {
            $r = DB::table('branch_stock_minimums')->where('branch', $branch)->where('stock_type', $type)->where('stock_item_id', $id)->lockForUpdate()->first();
            if (!$r) return;
            $low = $r->minimum_units !== null && $r->unit === $unit && $balance <= (int)$r->minimum_units;
            if (!$low) {
                DB::table('branch_stock_minimums')->where('id', $r->id)->update(['active_low'=>false]);
                DB::table('branch_stock_minimum_alerts')->where('minimum_id', $r->id)->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
                return;
            }
            if ($r->active_low) return;
            $item = $this->item($branch, $type, $id);
            $restaurant = DB::table('resturants')->where('id', (int)substr($branch, 2))->first();
            if (!$item || !$restaurant) return;
            $episode = (int)$r->episode + 1;
            DB::table('branch_stock_minimums')->where('id', $r->id)->update(['active_low'=>true, 'episode'=>$episode]);
            DB::table('branch_stock_minimum_alerts')->insert([
                'minimum_id'=>$r->id, 'episode'=>$episode, 'branch_name'=>$restaurant->name, 'item_name'=>$item->name,
                'unit'=>$unit, 'balance_units'=>$balance, 'minimum_units'=>$r->minimum_units,
                'next_attempt_at'=>now('UTC'), 'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
        };
        if (DB::transactionLevel() > 0) $work(); else DB::transaction($work, 3);
    }

    /** Current balances also cover desktop sync and older writers without the movement hook. */
    public function reconcile(): int
    {
        if (!$this->installed() || config('desktop_dashboard.local', false)) return 0;
        $count = 0;
        DB::table('branch_stock_minimums')->orderBy('id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    DB::table('resturants')->where('id', (int)substr($row->branch, 2))->lockForUpdate()->first();
                    $current = DB::table('branch_stock_minimums')->where('id', $row->id)->lockForUpdate()->first();
                    if (!$current) return;
                    $item = $this->item($current->branch, $current->stock_type, (int)$current->stock_item_id, true);
                    $this->observe($current->branch, $current->stock_type, (int)$current->stock_item_id, (int)($item->quantity_units ?? 0), $item->unit ?? '');
                }, 3);
                ++$count;
            }
        });
        return $count;
    }

    private function item(string $branch, string $type, int $id, bool $lock = false): ?object
    {
        if ($type === 'ingredient') {
            if (!Schema::hasTable('stock_ingredients') || !Schema::hasTable('branch_inventory')) return null;
            $ingredientQuery = DB::table('stock_ingredients')->where('id', $id);
            $item = ($lock ? $ingredientQuery->lockForUpdate() : $ingredientQuery)->first();
            if (!$item) return null;
            $stockQuery = DB::table('branch_inventory')->where('branch', $branch)->where('ingredient_id', $id);
            $stock = ($lock ? $stockQuery->lockForUpdate() : $stockQuery)->first();
            return (object)['name'=>$item->name, 'unit'=>$item->unit, 'quantity_units'=>(int)($stock->quantity_units ?? 0)];
        }
        if (!Schema::hasTable('branch_stock') || !Schema::hasTable('resturant_products')) return null;
        $stockQuery = DB::table('branch_stock')->where('branch', $branch)->where('product_id', $id);
        $stock = ($lock ? $stockQuery->lockForUpdate() : $stockQuery)->first();
        $productQuery = DB::table('resturant_products')->where('resturant_id', (int)substr($branch, 2))->where('id', $id);
        $product = ($lock ? $productQuery->lockForUpdate() : $productQuery)->first();
        return $stock && $product ? (object)['name'=>$product->product_name, 'unit'=>$stock->unit, 'quantity_units'=>(int)$stock->quantity_units] : null;
    }

    /** Never run SMTP inside a sale/receipt transaction; claims are short and recover after process failure. */
    public function dispatch(int $limit = 100): array
    {
        $result = ['sent'=>0, 'failed'=>0];
        if (!$this->installed() || config('desktop_dashboard.local', false)) return $result;
        abort_unless(DB::transactionLevel() === 0, 503, 'إرسال التنبيه يحتاج معاملة بضاعة مكتملة.');
        $ids = DB::table('branch_stock_minimum_alerts')->whereNull('sent_at')->whereNull('cancelled_at')
            ->where(function ($q) { $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now('UTC')); })
            ->where(function ($q) { $q->whereNull('lease_until')->orWhere('lease_until', '<=', now('UTC')); })
            ->orderBy('id')->limit(max(1, min(500, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $claim = $this->claim((int)$id);
            if (!$claim) continue;
            try {
                $email = $this->ownerEmail();
                if (!$email) throw new \RuntimeException('owner_email_missing');
                // A log/array transport does not deliver email; leave the alert pending for real SMTP.
                if (!$this->deliveryReady()) throw new \RuntimeException('mail_transport_not_delivering');
                Mail::to($email)->send(new BranchStockMinimumAlert($claim['data']));
                if (Mail::failures()) throw new \RuntimeException('mail_recipients_rejected');
                DB::table('branch_stock_minimum_alerts')->where('id', $id)->where('lease_token', $claim['token'])->update([
                    'sent_at'=>now('UTC'), 'lease_token'=>null, 'lease_until'=>null, 'last_error_code'=>null, 'updated_at'=>now('UTC'),
                ]);
                ++$result['sent'];
            } catch (\Throwable $error) {
                $code = $error instanceof \RuntimeException && in_array($error->getMessage(), ['owner_email_missing', 'mail_transport_not_delivering', 'mail_recipients_rejected'], true)
                    ? $error->getMessage() : 'mail_delivery_failed';
                DB::table('branch_stock_minimum_alerts')->where('id', $id)->where('lease_token', $claim['token'])->update([
                    'lease_token'=>null, 'lease_until'=>null, 'next_attempt_at'=>now('UTC')->addMinutes(min(60, 2 ** min(6, $claim['attempts'] - 1))),
                    'last_error_code'=>$code, 'updated_at'=>now('UTC'),
                ]);
                Log::warning('Stock minimum email pending retry', ['alert_id'=>(int)$id, 'reason'=>$code]);
                ++$result['failed'];
            }
        }
        return $result;
    }

    private function claim(int $id): ?array
    {
        $preview = DB::table('branch_stock_minimum_alerts')->join('branch_stock_minimums', 'branch_stock_minimums.id', '=', 'branch_stock_minimum_alerts.minimum_id')
            ->where('branch_stock_minimum_alerts.id', $id)->select('branch_stock_minimums.branch')->first();
        if (!$preview) return null;
        return DB::transaction(function () use ($id, $preview) {
            DB::table('resturants')->where('id', (int)substr($preview->branch, 2))->lockForUpdate()->first();
            $alert = DB::table('branch_stock_minimum_alerts')->where('id', $id)->first();
            if (!$alert) return null;
            $minimum = DB::table('branch_stock_minimums')->where('id', $alert->minimum_id)->lockForUpdate()->first();
            if (!$minimum) return null;
            $item = $this->item($minimum->branch, $minimum->stock_type, (int)$minimum->stock_item_id, true);
            $this->observe($minimum->branch, $minimum->stock_type, (int)$minimum->stock_item_id, (int)($item->quantity_units ?? 0), $item->unit ?? '');
            $minimum = DB::table('branch_stock_minimums')->where('id', $minimum->id)->first();
            $alert = DB::table('branch_stock_minimum_alerts')->where('id', $id)->lockForUpdate()->first();
            $now = now('UTC');
            if (!$minimum->active_low || (int)$minimum->episode !== (int)$alert->episode || $alert->sent_at || $alert->cancelled_at
                || ($alert->lease_until && Carbon::parse($alert->lease_until, 'UTC')->gt($now))
                || ($alert->next_attempt_at && Carbon::parse($alert->next_attempt_at, 'UTC')->gt($now))) return null;
            $token = bin2hex(random_bytes(24));
            $attempts = (int)$alert->attempts + 1;
            DB::table('branch_stock_minimum_alerts')->where('id', $id)->update(['lease_token'=>$token, 'lease_until'=>$now->copy()->addMinutes(15), 'attempts'=>$attempts, 'updated_at'=>$now]);
            return ['token'=>$token, 'attempts'=>$attempts, 'data'=>[
                'branch_name'=>$alert->branch_name, 'item_name'=>$alert->item_name, 'quantity'=>$this->quantity((int)$item->quantity_units),
                'minimum_quantity'=>$this->quantity((int)$minimum->minimum_units), 'unit_label'=>$alert->unit === 'kg' ? 'كجم' : 'قطعة',
                'detected_at'=>Carbon::parse($alert->created_at, 'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i'),
                'url'=>route('branch-stock.index', ['branch'=>$minimum->branch]),
            ]];
        }, 3);
    }

    protected function deliveryReady(): bool
    {
        $mailer = config('mail.default', 'smtp');
        return is_string($mailer) && in_array(config('mail.mailers.'.$mailer.'.transport', $mailer), ['smtp', 'sendmail', 'ses', 'mailgun', 'postmark'], true);
    }

    private function ownerEmail(): ?string
    {
        if (!Schema::hasColumn('users', 'email')) return null;
        $owner = DB::table('users')->where('id', 1)->where('account_type', 'admin')->first();
        if (!$owner || !empty($owner->owner_resturant_id) || (isset($owner->status) && $owner->status !== 'accepted')) return null;
        $email = trim((string)($owner->email ?? ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
