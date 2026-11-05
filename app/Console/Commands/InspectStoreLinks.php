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
 * Read-only: does every store item sell from Shubra's goods? The stores are online fronts of Shubra: their orders leave
 * Shubra, their money is collected in its drawer and their goods leave its stock. For each store, each item on its menu
 * and each size goes through what delivering an app order does (the item linked to Shubra's item, then Shubra's recipe,
 * goods or stock) and is counted as linked or not, with the reason. Nothing is written.
 */
class InspectStoreLinks extends Command
{
    protected $signature = 'stores:links';
    protected $description = 'Read-only: which store items sell from Shubra\'s goods, and what is missing for the others.';

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

        // The accounts that cover Shubra, and the branches their orders board covers now.
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

        // Every store item and size, as delivering its order would read it.
        $inventory = app(BranchInventory::class);
        $rawName = Closure::bind(fn (string $name) => $this->rawStockName($name), $inventory, BranchInventory::class);
        $stockName = Closure::bind(fn (string $name) => $this->stockName($name), $inventory, BranchInventory::class);
        $goods = [];
        foreach (DB::table('stock_ingredients')->get(['name']) as $ingredient) $goods[$stockName((string) $ingredient->name)] = true;
        $features = Schema::hasTable('product_features');
        $counts = ['linked' => 0, 'unlinked' => 0, 'refused' => 0];
        $problems = [];
        foreach ($stores as $store) {
            $perStore = ['linked' => 0, 'unlinked' => 0, 'refused' => 0];
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
                    $target = '';
                    try {
                        $mapped = FasakhanstaStoreFulfillment::inventoryLines('f:'.$store, [$line]);
                        $target = (string) DB::table('resturant_products')->where('id', $mapped[0]['product_id'])->value('product_name');
                        $result = $inventory->snapshotEach('f:'.$shubra, $mapped)[0];
                        if ($result instanceof Throwable) throw $result;
                        $snapshot = $result['inventory'] ?? [];
                        if (!empty($snapshot['components']) || ($snapshot['stock_source'] ?? '') === 'direct') {
                            $perStore['linked']++;
                            continue;
                        }
                        $perStore['unlinked']++;
                        $wanted = $rawName($target);
                        $reason = 'Shubra\'s item has no recipe, no goods by its name and no stock of its own; goods looked for: "'.$wanted.'"'
                            .(isset($goods[$stockName($wanted)]) ? ' (exists)' : ' (not in the goods list)');
                    } catch (Throwable $error) {
                        $perStore['refused']++;
                        $reason = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getMessage() : get_class($error);
                    }
                    $key = $reason.' | Shubra item: '.($target ?: '-').($featureName !== '' ? ' / '.$featureName : '');
                    $problems[$key] = ($problems[$key] ?? 0) + 1;
                }
            }
            foreach ($perStore as $kind => $n) $counts[$kind] += $n;
            $out[] = '  store #'.$store.' '.($names[$store] ?? '?').': '.$perStore['linked'].' linked, '.$perStore['unlinked'].' not linked, '.$perStore['refused'].' refused';
        }
        $out[] = 'all stores: '.$counts['linked'].' item sizes linked, '.$counts['unlinked'].' not linked, '.$counts['refused'].' refused';
        arsort($problems);
        $out[] = 'what is missing (how many store item sizes):';
        foreach (array_slice($problems, 0, 80, true) as $problem => $n) $out[] = '  '.$n.'x  '.$problem;
        if (count($problems) > 80) $out[] = '  ... and '.(count($problems) - 80).' more';
        return $out;
    }
}
