<?php

namespace App\Console\Commands;

use App\Services\Dashboard\BranchInventory;
use App\Services\Dashboard\FasakhanstaStoreFulfillment;
use App\Services\Dashboard\OrderBoardService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only: how the stores' items take Shubra's goods. The stores are online fronts of Shubra: their orders leave
 * Shubra, their money is collected in its drawer and their goods leave its stock. Every store item and size goes
 * through what delivering an app order does and is counted as taking Shubra's stock, sold without stock (kept for
 * review: no recipe, or no single Shubra item for it) or stopping the delivery (a size or unit refused, as at Shubra),
 * with the reason. Then the stores' open orders are tried the same way. Nothing is written.
 */
class InspectStoreLinks extends Command
{
    protected $signature = 'stores:links';
    protected $description = 'Read-only: which store items take Shubra\'s goods, which are kept for review, and why.';

    private const CLOSED = ['completed', 'cancelled', 'declined', 'rejected', 'expired'];

    public function handle(): int
    {
        foreach ($this->report() as $line) $this->line($line);
        return 0;
    }

    public function report(): array
    {
        $shubra = FasakhanstaStoreFulfillment::RESTAURANT_ID;
        $out = ['GO_ERP_STORE_LINKS'];
        $names = DB::table('resturants')->pluck('name', 'id');
        $stores = array_values(array_diff(FasakhanstaStoreFulfillment::sourceRestaurantIdsForBranch($shubra), [$shubra]));
        $out[] = 'Shubra (#'.$shubra.' '.($names[$shubra] ?? '?').') fulfils '.count($stores).' stores: '
            .implode(', ', array_map(fn ($id) => '#'.$id.' '.($names[$id] ?? '?'), $stores));
        $out = array_merge($out, $this->accounts($shubra, $stores));

        $inventory = app(BranchInventory::class);
        $rawName = Closure::bind(fn (string $name) => $this->rawStockName($name), $inventory, BranchInventory::class);
        $stockName = Closure::bind(fn (string $name) => $this->stockName($name), $inventory, BranchInventory::class);
        $goods = [];
        foreach (DB::table('stock_ingredients')->get(['name']) as $ingredient) $goods[$stockName((string) $ingredient->name)] = true;
        $features = Schema::hasTable('product_features');
        $kinds = ['takes stock' => 0, 'kept for review' => 0, 'stops delivery' => 0];
        $problems = [];
        foreach ($stores as $store) {
            $perStore = array_fill_keys(array_keys($kinds), 0);
            $products = DB::table('resturant_products')->where('resturant_id', $store)->where('status', 'show')->orderBy('id')->get();
            foreach ($products as $product) {
                $sizes = [[0, '']];
                if ($features && !empty($product->product_id)) {
                    foreach (DB::table('product_features')->where('product_id', $product->product_id)->orderBy('id')->get() as $feature) {
                        $sizes[] = [(int) $feature->id, (string) $feature->name];
                    }
                }
                foreach ($sizes as [$featureId, $featureName]) {
                    $line = ['product_id' => (int) $product->id, 'name' => (string) $product->product_name, 'quantity_millis' => 1000,
                        'feature_id' => $featureId, 'option_label' => ''];
                    [$kind, $reason, $target] = $this->item($inventory, $store, $shubra, $line, $rawName, $stockName, $goods);
                    $perStore[$kind]++;
                    if ($kind === 'takes stock') continue;
                    $key = $kind.': '.$reason.' | Shubra item: '.($target ?: '-').($featureName !== '' ? ' / '.$featureName : '');
                    $problems[$key] = ($problems[$key] ?? 0) + 1;
                }
            }
            foreach ($perStore as $kind => $n) $kinds[$kind] += $n;
            $out[] = '  store #'.$store.' '.($names[$store] ?? '?').': '.implode(', ', array_map(fn ($kind, $n) => $n.' '.$kind, array_keys($perStore), $perStore));
        }
        $out[] = 'all stores (item sizes): '.implode(', ', array_map(fn ($kind, $n) => $n.' '.$kind, array_keys($kinds), $kinds));
        arsort($problems);
        $out[] = 'what is missing (how many store item sizes):';
        foreach (array_slice($problems, 0, 80, true) as $problem => $n) $out[] = '  '.$n.'x  '.$problem;
        if (count($problems) > 80) $out[] = '  ... and '.(count($problems) - 80).' more';
        return array_merge($out, $this->openOrders($inventory, $stores, $names));
    }

    /** The accounts that cover Shubra, and whether their orders board covers every store. */
    private function accounts(int $shubra, array $stores): array
    {
        $out = [];
        $board = app(OrderBoardService::class);
        $owners = DB::table('users')->where('owner_resturant_id', $shubra)
            ->orWhere('id', (int) DB::table('resturants')->where('id', $shubra)->value('user_id'))->get();
        foreach ($owners as $row) {
            $user = (new \App\Models\User())->forceFill((array) $row);
            try {
                $covered = array_map('intval', $board->restaurantIds($user));
                $missing = array_values(array_diff($stores, $covered));
                $scope = count($covered).' branches'.($missing ? ', not the stores #'.implode(' #', $missing) : ', all the stores');
            } catch (Throwable $error) {
                $scope = 'unreadable ('.get_class($error).')';
            }
            $out[] = '  account #'.$row->id.' '.$row->account_type.' "'.$row->name.'": board covers '.$scope;
        }
        return $out;
    }

    /** One store item size as delivering its order reads it: [kind, reason, Shubra's item]. */
    private function item(BranchInventory $inventory, int $store, int $shubra, array $line, Closure $rawName, Closure $stockName, array $goods): array
    {
        try {
            $mapped = FasakhanstaStoreFulfillment::inventoryLines('f:'.$store, [$line]);
        } catch (Throwable $error) {
            return ['kept for review', $this->reason($error).' (no single Shubra item)', ''];
        }
        $target = (string) DB::table('resturant_products')->where('id', $mapped[0]['product_id'])->value('product_name');
        $result = $inventory->snapshotEach('f:'.$shubra, $mapped)[0];
        if ($result instanceof Throwable) return ['stops delivery', $this->reason($result), $target];
        $snapshot = $result['inventory'] ?? [];
        if (!empty($snapshot['components']) || ($snapshot['stock_source'] ?? '') === 'direct') return ['takes stock', '', $target];
        $wanted = $rawName($target);
        return ['kept for review', 'no recipe for Shubra\'s item, no goods by its name and no stock of its own; goods looked for: "'.$wanted.'"'
            .(isset($goods[$stockName($wanted)]) ? ' (exists)' : ' (not in the goods list)'), $target];
    }

    /** The stores' orders not finished yet, tried as delivering them would take stock. */
    private function openOrders(BranchInventory $inventory, array $stores, $names): array
    {
        if (!Schema::hasTable('orders') || !Schema::hasTable('carts')) return [];
        $orders = DB::table('orders')->whereIn('resturant_id', $stores)->where('type', 'current')->whereNotIn('status', self::CLOSED)
            ->orderByDesc('id')->limit(30)->get();
        $out = ['the stores\' open orders, as delivering them would take stock ('.count($orders).', newest first, at most 30):'];
        foreach ($orders as $order) {
            $label = '  order #'.$order->id.' '.($order->order_no ?? '').' ('.($names[$order->resturant_id] ?? $order->resturant_id).', '.$order->status.'): ';
            try {
                $lines = $inventory->appSaleCheck($order)['lines'];
            } catch (Throwable $error) {
                $out[] = $label.'stops delivery: '.$this->reason($error);
                continue;
            }
            $kept = [];
            foreach ($lines as $line) {
                $snapshot = $line['inventory'] ?? [];
                if (empty($snapshot['components']) && ($snapshot['stock_source'] ?? '') !== 'direct') {
                    $kept[] = $line['name'].(isset($snapshot['reason']) ? ' ('.$snapshot['reason'].')' : ' (no recipe)');
                }
            }
            $out[] = $label.'delivers; '.(count($lines) - count($kept)).' of '.count($lines).' lines take Shubra\'s stock'
                .($kept ? '; kept for review: '.implode('; ', $kept) : '');
        }
        return $out;
    }

    private function reason(Throwable $error): string
    {
        return $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getMessage() : get_class($error);
    }
}
