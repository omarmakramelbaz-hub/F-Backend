<?php
/** Prepare the tahini ingredient, then verify the installed protected purchase category. */
use App\Models\User;
use App\Services\Dashboard\{ExpenseCategories, ExpenseStockPurchases};
use Illuminate\Support\Facades\{DB, Schema};

final class FasakhanstaTahiniPurchases20261021
{
    public const RELEASE = 'tahini-purchases-20261021';
    public const CATEGORY_KEY = 'purchase_tahini';
    private const NAME = 'طحينة';
    private const CATEGORY_SERVICE_SHA256 = '1bf213f622129c9a2402378b2cdfae7f2fb30cb9ce18da924740ca772f8515d9';
    private const BRANCH_IDS = [94,301,307,308,309,310,311,312,313,336,337,338,339,340,341,342,343,344,345,346,347,348,349,350,354,355,356,357,358,359,360,363];
    private string $root;

    public function __construct(string $root) { $this->root = $root; }

    public static function normalize(string $name): string
    {
        $name = strtr($name, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي']);
        return trim((string) preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', preg_replace('/[ـ\x{064B}-\x{065F}]/u', '', $name)));
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing or unsafe tahini purchase record.');
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid tahini purchase record.');
        return $data;
    }

    private function writeBytes(string $path, string $bytes): void
    {
        if (is_link($path)) throw new RuntimeException('Unsafe tahini purchase backup destination.');
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $bytes, LOCK_EX) === false) throw new RuntimeException('Cannot write a private tahini purchase record.');
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Cannot finalize a private tahini purchase record.'); }
    }

    private function writeJson(string $path, array $data): void
    {
        $this->writeBytes($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function requireSchema(): void
    {
        foreach ([
            'users'=>['id','account_type','owner_resturant_id'], 'resturants'=>['id','name'],
            'stock_ingredients'=>['id','name','unit','position','created_at','updated_at'],
            'branch_expense_categories'=>['id','name','name_hash','origin_branch','request_key'],
            'branch_expense_category_settings'=>['category_key','name','active','revision'],
            'branch_expense_category_overrides'=>['branch','category_key','name','active','revision'],
            'branch_expense_category_commands'=>['id','request_key','request_hash','actor_id','action','snapshot'],
            'branch_expenses'=>['id','branch','category','status'],
            'expense_inventory_receipts'=>['id','expense_id','branch','category_key','ingredient_id','item_name','quantity_units','receipt_movement_id','reversal_movement_id','reversed_at','created_at','updated_at'],
            'branch_inventory'=>['id','branch','ingredient_id','quantity_units','revision'],
            'branch_inventory_movements'=>['id','stock_id','branch','ingredient_id','quantity_units','source_type','source_id'],
            'branch_stock_recipes'=>['id','branch','product_id','variants'], 'branch_recipe_sales'=>['id','branch','snapshot'],
        ] as $table=>$columns) {
            if (!Schema::hasTable($table) || array_diff($columns, Schema::getColumnListing($table))) throw new RuntimeException('Required tahini purchase runtime schema is missing: '.$table);
        }
    }

    private function rows(string $table, array $order = ['id'], bool $lock = false): array
    {
        $query = DB::table($table); foreach ($order as $column) $query->orderBy($column);
        if ($lock) $query->lockForUpdate();
        $rows = []; foreach ($query->get() as $row) $rows[] = (array) $row;
        return $rows;
    }

    private function legacyBefore(): array
    {
        $path = $this->root.'/storage/app/tahini-catalog-20261021/before.json';
        if (is_link($path)) throw new RuntimeException('Unsafe original tahini before-record.');
        if (!file_exists($path)) return ['exists'=>false,'sha256'=>null,'size'=>0];
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('The original tahini before-record cannot be retained.');
        return ['exists'=>true,'sha256'=>hash_file('sha256', $path),'size'=>filesize($path)];
    }

    private function retainLegacy(string $backup, array $expected): void
    {
        if ($this->legacyBefore() !== $expected) throw new RuntimeException('The original tahini before-record changed; no data was written.');
        if (!$expected['exists']) return;
        $bytes = file_get_contents($this->root.'/storage/app/tahini-catalog-20261021/before.json');
        if (hash('sha256', $bytes) !== $expected['sha256']) throw new RuntimeException('The original tahini before-record changed during backup.');
        $destination = $backup.'/old-before-tahini-catalog.json';
        if (file_exists($destination) && (!is_file($destination) || is_link($destination) || hash_file('sha256', $destination) !== $expected['sha256'])) throw new RuntimeException('A conflicting original tahini backup already exists.');
        if (!file_exists($destination)) $this->writeBytes($destination, $bytes);
        $this->writeBytes($backup.'/old-before-tahini-catalog.sha256', $expected['sha256']."\n");
    }

    private function runtime(): array
    {
        $categoryFile = (new ReflectionClass(ExpenseCategories::class))->getFileName();
        if (!$categoryFile || !is_file($categoryFile) || hash_file('sha256', $categoryFile) !== self::CATEGORY_SERVICE_SHA256) throw new RuntimeException('The deployed expense-category service differs from the reviewed branch-scoped version.');
        $file = (new ReflectionClass(ExpenseStockPurchases::class))->getFileName(); $file = $file ? realpath($file) : false;
        $deployed = $this->root.'/app/Services/Dashboard/ExpenseStockPurchases.php';
        if (!$file || !is_file($file) || !is_readable($file) || is_link($file) || !is_file($deployed) || !is_readable($deployed) || is_link($deployed)) throw new RuntimeException('The reviewed expense-purchase service file is unavailable.');
        if ((ExpenseStockPurchases::CATEGORIES[self::CATEGORY_KEY] ?? null) !== self::NAME) throw new RuntimeException('The staged purchase registry is missing the protected tahini category.');
        $stock = app(ExpenseStockPurchases::class);
        if ($stock->categoryProduct(self::NAME) !== self::CATEGORY_KEY || $stock->categoryProduct('طحينه') !== self::CATEGORY_KEY) throw new RuntimeException('The staged tahini purchase-name mapping is incomplete.');
        $quantity = $stock->normalizeQuantity(['quantity_kg'=>'1'], self::CATEGORY_KEY);
        if (($quantity['quantity_kg'] ?? null) !== '1' || ($quantity['stock_category'] ?? null) !== self::CATEGORY_KEY) throw new RuntimeException('The staged tahini purchase quantity validation is incomplete.');
        return ['runtime_purchase_service_path'=>$file,'target_purchase_service_sha256'=>hash_file('sha256', $file),
            'preloaded_service_sha256'=>hash_file('sha256', $file),'before_deployed_service_sha256'=>hash_file('sha256', $deployed)];
    }

    private function plan(bool $lock = false): array
    {
        $runtime = $this->runtime(); $service = app(ExpenseCategories::class); $actor = User::withoutGlobalScopes()->find(1);
        if (!$actor || !$service->canCreate($actor)) throw new RuntimeException('The persisted primary owner cannot manage expense categories.');
        $branches = $this->rows('resturants', ['id'], $lock);
        if (array_map(static fn($row)=>(int) $row['id'], $branches) !== self::BRANCH_IDS) throw new RuntimeException('The reviewed 32-branch scope changed.');
        $ingredients = $this->rows('stock_ingredients', ['id'], $lock); $matches = []; $position = 0;
        foreach ($ingredients as $row) { $position = max($position, (int) $row['position']); if (self::normalize($row['name']) === self::normalize(self::NAME)) $matches[] = $row; }
        if (count($matches) > 1) throw new RuntimeException('Multiple matching tahini ingredients require review.');
        $ingredient = $matches[0] ?? null;
        if ($ingredient && ($ingredient['name'] !== self::NAME || $ingredient['unit'] !== 'kg')) throw new RuntimeException('The existing tahini ingredient is not exactly named طحينة in kg; no names, units or balances will be converted.');
        if (!$ingredient && $position >= 65535) throw new RuntimeException('The ingredient ordering range is exhausted.');
        $categories = $this->rows('branch_expense_categories', ['id'], $lock); $stock = app(ExpenseStockPurchases::class);
        foreach ($categories as $row) if (self::normalize($row['name']) === self::normalize(self::NAME)
            || $stock->categoryProduct($row['name']) === self::CATEGORY_KEY) throw new RuntimeException('A historical custom tahini expense category requires review before enabling the protected purchase category.');
        $settings = $this->rows('branch_expense_category_settings', ['category_key'], $lock);
        $overrides = $this->rows('branch_expense_category_overrides', ['branch','category_key'], $lock); $verifiedBranches = [];
        foreach (self::BRANCH_IDS as $id) {
            $branch = 'f:'.$id; $catalog = $service->catalog($branch); $matching = [];
            foreach ($catalog as $key=>$item) if ($item['active'] && (self::normalize($item['name']) === self::normalize(self::NAME)
                || ($item['stock_category'] ?? null) === self::CATEGORY_KEY || $stock->categoryProduct($item['name']) === self::CATEGORY_KEY)) $matching[$key] = $item;
            $item = $catalog[self::CATEGORY_KEY] ?? null;
            if (count($matching) !== 1 || !$item || ($item['key'] ?? '') !== self::CATEGORY_KEY || !($item['active'] ?? false)
                || !($item['protected'] ?? false) || ($item['canonical_key'] ?? '') !== self::CATEGORY_KEY || ($item['stock_category'] ?? '') !== self::CATEGORY_KEY
                || $item['name'] !== self::NAME || !isset($matching[self::CATEGORY_KEY])) throw new RuntimeException('The protected tahini purchase category is unavailable or ambiguous for branch '.$branch.'.');
            $verifiedBranches[] = $branch;
        }
        $before = ['branches'=>array_map(static fn($row)=>['id'=>(int) $row['id'],'name'=>$row['name']], $branches),
            'stock_ingredients'=>$ingredients,'branch_expense_categories'=>$categories,'branch_expense_category_settings'=>$settings,'branch_expense_category_overrides'=>$overrides];
        return ['ingredient'=>$ingredient,'ingredient_position'=>$position+1,'verified_branches'=>$verifiedBranches,'runtime'=>$runtime,
            'before'=>$before,'before_sha256'=>hash('sha256', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),'legacy_before'=>$this->legacyBefore()];
    }

    private function checkRecord(array $record, array $plan): void
    {
        if (($record['release'] ?? '') !== self::RELEASE || ($record['ingredient_unit'] ?? '') !== 'kg' || ($record['branch_ids'] ?? []) !== self::BRANCH_IDS
            || ($record['target_purchase_service_sha256'] ?? '') !== $plan['runtime']['target_purchase_service_sha256']) throw new RuntimeException('The private tahini purchase record belongs to a different scope or target service.');
        if (isset($record['ingredient']['id']) && (!$plan['ingredient'] || (int) $plan['ingredient']['id'] !== (int) $record['ingredient']['id'])) throw new RuntimeException('The prepared tahini ingredient identity changed; no replacement will be created.');
        if (($record['legacy_before'] ?? null) !== $plan['legacy_before']) throw new RuntimeException('The original tahini before-record changed after preparation.');
    }

    private function checkStored(string $directory, array $plan): void
    {
        foreach (['before.json','prepared.json','receipt.json'] as $name) {
            if (is_link($directory.'/'.$name)) throw new RuntimeException('Unsafe private tahini purchase record.');
            if (is_file($directory.'/'.$name)) $this->checkRecord($this->readJson($directory.'/'.$name), $plan);
        }
    }

    private function metadata(array $plan): array
    {
        return ['release'=>self::RELEASE,'ingredient_unit'=>'kg','branch_ids'=>self::BRANCH_IDS,'branch_count'=>32,'category_key'=>self::CATEGORY_KEY,
            'protected_purchase_category'=>true,'verified_branches'=>$plan['verified_branches'],'ingredient'=>$plan['ingredient'],
            'legacy_before'=>$plan['legacy_before'],'target_purchase_service_sha256'=>$plan['runtime']['target_purchase_service_sha256'],
            'preloaded_service_sha256'=>$plan['runtime']['preloaded_service_sha256'],'before_deployed_service_sha256'=>$plan['runtime']['before_deployed_service_sha256'],
            'financial_entries_created'=>0,'stock_movements_created'=>0,'recipes_created'=>0];
    }

    public function run(string $mode, ?string $backup = null): array
    {
        if (!in_array($mode, ['check','prepare','finalize'], true)) throw new RuntimeException('Choose check, prepare or finalize.');
        $this->requireSchema(); $directory = $this->root.'/storage/app/'.self::RELEASE;
        if (is_link($directory)) throw new RuntimeException('Unsafe private tahini purchase state directory.');
        $plan = $this->plan(); $this->checkStored($directory, $plan);
        if ($mode === 'check') return ['status'=>'TAHINI_PURCHASES_CHECK_READY','read_only'=>true,'ingredient_action'=>$plan['ingredient'] ? 'reuse' : 'create']+$this->metadata($plan);
        if (!$backup || !is_dir($backup) || is_link($backup) || !is_writable($backup) || (fileperms($backup) & 0077)) throw new RuntimeException('Pass an existing writable private backup directory with mode 0700.');
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private tahini purchase state directory.');
        if (is_link($directory.'/install.lock')) throw new RuntimeException('Unsafe tahini purchase installation lock.');
        $lock = fopen($directory.'/install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another tahini purchase update is active.');
        try {
            $plan = $this->plan(); $this->checkStored($directory, $plan);
            if ($mode === 'finalize') {
                $prepared = $this->readJson($directory.'/prepared.json'); $this->checkRecord($prepared, $plan);
                $deployed = realpath($this->root.'/app/Services/Dashboard/ExpenseStockPurchases.php');
                if ($plan['runtime']['runtime_purchase_service_path'] !== $deployed || !$plan['ingredient']
                    || $plan['runtime']['target_purchase_service_sha256'] !== $prepared['target_purchase_service_sha256']) throw new RuntimeException('Finalization requires the actual installed purchase service and prepared ingredient.');
                $this->retainLegacy($backup, $plan['legacy_before']);
                $metadata = $this->metadata($plan);
                $metadata['before_deployed_service_sha256'] = $prepared['before_deployed_service_sha256'];
                $result = ['status'=>'TAHINI_CATALOG_READY','verified'=>true,'backup'=>$backup,'receipt'=>$directory.'/receipt.json']+$metadata;
                $this->writeJson($directory.'/receipt.json', $result+['recorded_at'=>gmdate('c')]);
                $this->writeJson($backup.'/applied-tahini-purchases.json', $result+['recorded_at'=>gmdate('c')]);
                return $result;
            }
            DB::beginTransaction();
            try {
                DB::table('users')->where('id', 1)->lockForUpdate()->first();
                $locked = $this->plan(true); $this->checkStored($directory, $locked);
                if ($locked['before_sha256'] !== $plan['before_sha256'] || $locked['legacy_before'] !== $plan['legacy_before']) throw new RuntimeException('The tahini purchase catalog changed during preparation; no rows were written.');
                $record = $this->metadata($locked)+['recorded_at'=>gmdate('c'),'before'=>$locked['before']];
                if (!is_file($directory.'/before.json')) $this->writeJson($directory.'/before.json', $record);
                $this->writeJson($backup.'/current-before-tahini-purchases.json', $record);
                $this->retainLegacy($backup, $locked['legacy_before']);
                $created = !$locked['ingredient'];
                if ($created) DB::table('stock_ingredients')->insertGetId(['name'=>self::NAME,'unit'=>'kg','position'=>$locked['ingredient_position'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                $verified = $this->plan(true);
                if (!$verified['ingredient']) throw new RuntimeException('The tahini ingredient did not verify; database changes were rolled back.');
                DB::commit();
            } catch (Throwable $error) {
                try { if (DB::transactionLevel() > 0) DB::rollBack(); } catch (Throwable $rollbackError) {}
                throw $error;
            }
            if ($this->legacyBefore() !== $plan['legacy_before']) throw new RuntimeException('The original tahini before-record changed after ingredient preparation.');
            $metadata = $this->metadata($verified);
            $metadata['before_deployed_service_sha256'] = $this->readJson($directory.'/before.json')['before_deployed_service_sha256'];
            $result = ['status'=>'TAHINI_INGREDIENT_READY','verified'=>true,'ingredient_created'=>$created,'backup'=>$backup,'prepared'=>$directory.'/prepared.json']+$metadata;
            $this->writeJson($directory.'/prepared.json', $result+['recorded_at'=>gmdate('c')]);
            $this->writeJson($backup.'/prepared-tahini-purchases.json', $result+['recorded_at'=>gmdate('c')]);
            return $result;
        } finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['check','prepare','finalize','root:','backup:','purchase-service:']);
        $modes = array_values(array_filter(['check','prepare','finalize'], static fn($mode)=>isset($options[$mode])));
        if (count($modes) !== 1) throw new RuntimeException('Choose exactly one of --check, --prepare or --finalize.');
        $mode = $modes[0]; $root = realpath($options['root'] ?? '');
        if (!$root || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('Invalid existing Laravel root.');
        if ($mode === 'finalize' && isset($options['purchase-service'])) throw new RuntimeException('Finalization must load the actual deployed purchase service.');
        require $root.'/vendor/autoload.php';
        if (isset($options['purchase-service'])) {
            $candidate = realpath($options['purchase-service']);
            if (!$candidate || !is_file($candidate) || is_link($options['purchase-service']) || class_exists(ExpenseStockPurchases::class, false)) throw new RuntimeException('The staged purchase service cannot be safely preloaded.');
            require $candidate;
            if (realpath((new ReflectionClass(ExpenseStockPurchases::class))->getFileName()) !== $candidate) throw new RuntimeException('The staged purchase service class did not load from its reviewed file.');
        }
        $application = require $root.'/bootstrap/app.php'; $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode((new FasakhanstaTahiniPurchases20261021($root))->run($mode, $options['backup'] ?? null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        $detail = $error instanceof Illuminate\Validation\ValidationException ? ' Fields: '.json_encode(array_keys($error->errors()), JSON_UNESCAPED_UNICODE) : '';
        fwrite(STDERR, 'TAHINI_PURCHASES_STOPPED: '.$error->getMessage().$detail."\n"); exit(1);
    }
}
