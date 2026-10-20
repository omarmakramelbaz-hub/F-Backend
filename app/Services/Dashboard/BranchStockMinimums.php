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

    /** The physical branch registry excludes retail-store identities and the GO namespace. */
    private function emailBranchKeys(): array
    {
        return array_map(fn($id)=>'f:'.$id, FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS);
    }

    private function emailAlerts(array $branches): \Illuminate\Database\Query\Builder
    {
        return DB::table('branch_stock_minimum_alerts')->whereIn('minimum_id',
            DB::table('branch_stock_minimums')->whereIn('branch', $branches)->select('id'));
    }

    /** One consolidated physical-branch report per run, irrespective of pending item count.
     * The argument remains compatible with the existing scheduled command; it never slices a report.
     * SMTP stays outside inventory transactions, and sent/failed count reports rather than items.
     */
    public function dispatch(int $limit = 100): array
    {
        $result = ['sent'=>0, 'failed'=>0];
        if (!$this->installed() || config('desktop_dashboard.local', false)) return $result;
        abort_unless(DB::transactionLevel() === 0, 503, 'إرسال التنبيه يحتاج معاملة بضاعة مكتملة.');
        if (!$this->emailAlerts($this->emailBranchKeys())->whereNull('sent_at')->whereNull('cancelled_at')->exists()) return $result;
        $claim = $this->claimReport();
        if (!$claim) return $result;
        try {
            $email = $this->ownerEmail();
            if (!$email) throw new \RuntimeException('owner_email_missing');
            if (!$this->deliveryReady()) throw new \RuntimeException('mail_transport_not_delivering');
            Mail::to($email)->send(new BranchStockMinimumAlert($claim['data']));
            if (Mail::failures()) throw new \RuntimeException('mail_recipients_rejected');
            DB::table('branch_stock_minimum_alerts')->where('lease_token', $claim['token'])->update([
                'sent_at'=>now('UTC'), 'lease_token'=>null, 'lease_until'=>null, 'last_error_code'=>null, 'updated_at'=>now('UTC'),
            ]);
            $result['sent'] = 1;
        } catch (\Throwable $error) {
            $code = $error instanceof \RuntimeException && in_array($error->getMessage(), ['owner_email_missing', 'mail_transport_not_delivering', 'mail_recipients_rejected'], true)
                ? $error->getMessage() : 'mail_delivery_failed';
            DB::table('branch_stock_minimum_alerts')->where('lease_token', $claim['token'])->update([
                'lease_token'=>null, 'lease_until'=>null, 'next_attempt_at'=>now('UTC')->addMinutes(min(60, 2 ** min(6, $claim['attempts'] - 1))),
                'last_error_code'=>$code, 'updated_at'=>now('UTC'),
            ]);
            Log::warning('Stock minimum report pending retry', ['alert_count'=>count($claim['ids']), 'reason'=>$code]);
            $result['failed'] = 1;
        }
        return $result;
    }

    /** Every worker takes the existing branch writer locks in the same order, then leases
     * all pending episodes together. An in-flight report blocks another global report even
     * if a sale creates a new episode, or recovery cancels one of the leased episodes.
     */
    private function claimReport(): ?array
    {
        return DB::transaction(function () {
            $restaurants = DB::table('resturants')->whereIn('id', FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS)->orderBy('id')->lockForUpdate()->get();
            if ($restaurants->isEmpty()) return null;
            $now = now('UTC');
            $branches = $restaurants->map(fn($restaurant)=>'f:'.$restaurant->id)->all();
            // Excluded stores cannot trigger, delay, or block a physical-branch report.
            // Keep their stock, thresholds, episodes, and historical outbox rows intact.
            if ($this->emailAlerts($branches)->whereNotNull('lease_token')->where('lease_until', '>', $now)->exists()) return null;
            $pending = $this->emailAlerts($branches)->whereNull('sent_at')->whereNull('cancelled_at')->orderBy('id')->get();
            if ($pending->isEmpty()) return null;
            $branchIds = $restaurants->pluck('id')->map(fn($id)=>(int)$id)->all();
            $ids = [];
            $attempts = 1;
            $waiting = false;
            foreach ($pending as $alert) {
                $minimum = DB::table('branch_stock_minimums')->where('id', $alert->minimum_id)->lockForUpdate()->first();
                $validBranch = $minimum && preg_match('/^f:([1-9][0-9]*)$/D', $minimum->branch, $match)
                    && in_array((int)$match[1], $branchIds, true);
                $item = $validBranch ? $this->item($minimum->branch, $minimum->stock_type, (int)$minimum->stock_item_id, true) : null;
                if ($item) {
                    $this->observe($minimum->branch, $minimum->stock_type, (int)$minimum->stock_item_id, (int)$item->quantity_units, $item->unit);
                    $minimum = DB::table('branch_stock_minimums')->where('id', $minimum->id)->first();
                } elseif ($minimum) {
                    DB::table('branch_stock_minimums')->where('id', $minimum->id)->update(['active_low'=>false]);
                }
                if (!$item || !$minimum->active_low || (int)$minimum->episode !== (int)$alert->episode
                    || $minimum->minimum_units === null || $minimum->unit !== $item->unit
                    || (int)$item->quantity_units > (int)$minimum->minimum_units) {
                    DB::table('branch_stock_minimum_alerts')->where('id', $alert->id)->whereNull('sent_at')->update([
                        'cancelled_at'=>$now, 'lease_token'=>null, 'lease_until'=>null, 'updated_at'=>$now,
                    ]);
                    continue;
                }
                $ids[] = (int)$alert->id;
                $attempts = max($attempts, (int)$alert->attempts + 1);
                if ($alert->next_attempt_at && Carbon::parse($alert->next_attempt_at, 'UTC')->gt($now)) $waiting = true;
            }
            // Preserve retry backoff for the complete batch, including old per-item retries.
            if (!$ids || $waiting) return null;
            $data = $this->stockReport($restaurants->all());
            if (!$data['low_count']) return null;
            $token = bin2hex(random_bytes(24));
            // No pagination or command limit: all triggering rows belong to this one report.
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('branch_stock_minimum_alerts')->whereIn('id', $chunk)->update([
                    'lease_token'=>$token, 'lease_until'=>$now->copy()->addMinutes(15), 'attempts'=>$attempts, 'updated_at'=>$now,
                ]);
            }
            return ['token'=>$token, 'ids'=>$ids, 'attempts'=>$attempts, 'data'=>$data];
        }, 3);
    }

    /** Read actual stock records, never menu recipe availability or a paginated screen.
     * Each ingredient exists in every branch; no receipt means a real zero balance.
     * Recorded direct SKU balances stay in a separate category, without summing units.
     */
    private function stockReport(array $restaurants): array
    {
        $ingredients = Schema::hasTable('stock_ingredients') && Schema::hasTable('branch_inventory')
            ? DB::table('stock_ingredients')->orderBy('position')->orderBy('id')->get()->all() : [];
        $branches = [];
        $total = 0;
        $low = 0;
        foreach ($restaurants as $restaurant) {
            $branch = 'f:'.$restaurant->id;
            $minimums = DB::table('branch_stock_minimums')->where('branch', $branch)->get()->keyBy(fn($r)=>$r->stock_type.':'.$r->stock_item_id)->all();
            $balances = $ingredients ? DB::table('branch_inventory')->where('branch', $branch)->get()->keyBy('ingredient_id')->all() : [];
            $items = [];
            foreach ($ingredients as $ingredient) {
                $items[] = $this->reportItem('ingredient', (int)$ingredient->id, $ingredient->name, $ingredient->unit,
                    (int)($balances[$ingredient->id]->quantity_units ?? 0), $minimums['ingredient:'.$ingredient->id] ?? null);
            }
            if (Schema::hasTable('branch_stock') && Schema::hasTable('resturant_products')) {
                $direct = DB::table('branch_stock')->leftJoin('resturant_products', function ($join) use ($restaurant) {
                    $join->on('resturant_products.id', '=', 'branch_stock.product_id')->where('resturant_products.resturant_id', '=', $restaurant->id);
                })->where('branch_stock.branch', $branch)
                    ->orderBy('branch_stock.product_id')->get(['branch_stock.product_id', 'branch_stock.unit', 'branch_stock.quantity_units', 'resturant_products.product_name']);
                foreach ($direct as $product) {
                    $items[] = $this->reportItem('product', (int)$product->product_id, $product->product_name ?: 'صنف سابق #'.$product->product_id, $product->unit,
                        (int)$product->quantity_units, $minimums['product:'.$product->product_id] ?? null);
                }
            }
            if (!$items) continue;
            // Low items lead each branch; stable ids keep repeated reports easy to scan.
            usort($items, fn($a,$b)=>((int)$b['low_stock'] <=> (int)$a['low_stock'])
                ?: strcmp($a['stock_type'], $b['stock_type']) ?: ($a['id'] <=> $b['id']));
            $branchLow = count(array_filter($items, fn($item)=>$item['low_stock']));
            $branches[] = ['value'=>$branch, 'name'=>(string)$restaurant->name, 'total_items'=>count($items), 'low_count'=>$branchLow,
                'url'=>route('branch-stock.index', ['branch'=>$branch]), 'items'=>$items];
            $total += count($items);
            $low += $branchLow;
        }
        usort($branches, fn($a,$b)=>((int)($b['low_count'] > 0) <=> (int)($a['low_count'] > 0)) ?: ((int)substr($a['value'],2) <=> (int)substr($b['value'],2)));
        return ['generated_at'=>now('UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i'),
            'branch_count'=>count($branches), 'total_items'=>$total, 'low_count'=>$low,
            'url'=>route('branch-stock.index', ['branch'=>'all']), 'branches'=>$branches];
    }

    private function reportItem(string $type, int $id, string $name, string $unit, int $balance, ?object $minimum): array
    {
        $enabled = $minimum && $minimum->minimum_units !== null && $minimum->unit === $unit;
        $low = $enabled && $balance <= (int)$minimum->minimum_units;
        $status = !$enabled ? 'بدون حد أدنى' : ($low ? ($balance === (int)$minimum->minimum_units ? 'عند الحد الأدنى' : 'أقل من الحد الأدنى') : 'أعلى من الحد الأدنى');
        if ($balance <= 0) $status = $balance < 0 ? 'رصيد سالب' : 'نفد الرصيد';
        return ['id'=>$id, 'name'=>$name, 'stock_type'=>$type, 'quantity'=>$this->quantity($balance), 'unit_label'=>$unit === 'kg' ? 'كجم' : 'قطعة',
            'minimum_quantity'=>$enabled ? $this->quantity((int)$minimum->minimum_units) : null, 'low_stock'=>$low, 'status_label'=>$status,
            'shortage_quantity'=>$low ? $this->quantity(max(0, (int)$minimum->minimum_units - $balance)) : null];
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
