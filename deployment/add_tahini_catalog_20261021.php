<?php
/** Add shared tahini expense/ingredient choices without recording a purchase or recipe. */
use App\Models\User;
use App\Services\Dashboard\ExpenseCategories;
use Illuminate\Support\Facades\{DB, Schema};

final class FasakhanstaTahiniCatalog20261021
{
    public const RELEASE = 'tahini-catalog-20261021';
    public const CATEGORY_REQUEST = '6aa77a60-173c-4bd1-80dc-f0d17cb56021';
    private const NAME = 'طحينة';
    private const BRANCH_IDS = [94,301,307,308,309,310,311,312,313,336,337,338,339,340,341,342,343,344,345,346,347,348,349,350,354,355,356,357,358,359,360,363];
    private string $root;
    private string $unit;

    public function __construct(string $root, string $ingredientUnit)
    {
        if (!in_array($ingredientUnit, ['kg','piece'], true)) throw new RuntimeException('An explicit ingredient unit of kg or piece is required.');
        $this->root = $root; $this->unit = $ingredientUnit;
    }

    public static function normalize(string $name): string
    {
        $name = strtr($name, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي']);
        $name = preg_replace('/[ـ\x{064B}-\x{065F}]/u', '', $name);
        return trim((string) preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', $name));
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing or unsafe private tahini record.');
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid private tahini record.');
        return $data;
    }

    private function writeJson(string $path, array $data): void
    {
        if (is_link($path)) throw new RuntimeException('Unsafe tahini record destination.');
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) throw new RuntimeException('Cannot write a private tahini record.');
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Cannot finalize a private tahini record.'); }
    }

    private function requireSchema(): void
    {
        foreach ([
            'users'=>['id','account_type','owner_resturant_id'], 'resturants'=>['id','name'],
            'stock_ingredients'=>['id','name','unit','position','created_at','updated_at'],
            'branch_expense_categories'=>['id','name','name_hash','created_by','request_key','created_at','updated_at'],
            'branch_expense_category_settings'=>['category_key','name','active','revision','created_at','updated_at'],
            'branch_expense_category_commands'=>['id','request_key','request_hash','actor_id','action','snapshot','created_at'],
            'branch_inventory'=>['id','branch','ingredient_id','quantity_units'],
            'branch_inventory_movements'=>['id','ingredient_id'], 'branch_stock_recipes'=>['id','branch','product_id','variants'],
            'branch_recipe_sales'=>['id','branch','snapshot'],
        ] as $table=>$columns) {
            if (!Schema::hasTable($table) || array_diff($columns, Schema::getColumnListing($table))) throw new RuntimeException('Required tahini catalog runtime schema is missing: '.$table);
        }
    }

    private function rows(string $table, string $order = 'id', bool $lock = false): array
    {
        $query = DB::table($table)->orderBy($order);
        if ($lock) $query->lockForUpdate();
        $rows = []; foreach ($query->get() as $row) $rows[] = (array) $row;
        return $rows;
    }

    private function owner(ExpenseCategories $service)
    {
        $actor = User::withoutGlobalScopes()->find(1);
        if (!$actor || !$service->canCreate($actor)) throw new RuntimeException('The persisted primary owner cannot manage expense categories.');
        return $actor;
    }

    private function plan(ExpenseCategories $service, bool $lock = false): array
    {
        $this->owner($service);
        $branches = $this->rows('resturants', 'id', $lock);
        if (array_map(static fn($row)=>(int) $row['id'], $branches) !== self::BRANCH_IDS) throw new RuntimeException('The reviewed 32-branch scope changed.');
        $ingredients = $this->rows('stock_ingredients', 'id', $lock); $matches = [];
        foreach ($ingredients as $ingredient) if (self::normalize($ingredient['name']) === self::normalize(self::NAME)) $matches[] = $ingredient;
        if (count($matches) > 1) throw new RuntimeException('Multiple matching tahini ingredients require review.');
        $ingredient = $matches[0] ?? null;
        if ($ingredient && $ingredient['unit'] !== $this->unit) throw new RuntimeException('An existing tahini ingredient has a different unit; balances and recipes will not be converted.');
        $categories = $this->rows('branch_expense_categories', 'id', $lock);
        $settings = $this->rows('branch_expense_category_settings', 'category_key', $lock);
        $catalog = $service->catalog(); $matches = [];
        foreach ($catalog as $category) if (self::normalize($category['name']) === self::normalize(self::NAME)) $matches[] = $category;
        if (count($matches) > 1) throw new RuntimeException('Multiple matching tahini expense categories require review.');
        $category = $matches[0] ?? null;
        $commandQuery = DB::table('branch_expense_category_commands')->where('request_key', self::CATEGORY_REQUEST);
        if ($lock) $commandQuery->lockForUpdate();
        $command = $commandQuery->first(); $command = $command ? (array) $command : null;
        if ($command) {
            $snapshot = json_decode($command['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!$category || (int) $command['actor_id'] !== 1 || $command['action'] !== 'create' || !is_array($snapshot)
                || ($snapshot['key'] ?? null) !== $category['key'] || !($category['active'] ?? false)
                || self::normalize($snapshot['name'] ?? '') !== self::normalize(self::NAME)) throw new RuntimeException('The prior tahini category command conflicts with the current catalog.');
        }
        $categoryName = $category['name'] ?? self::NAME;
        // ExpenseCategories hashes the original display name, not the broader Arabic duplicate check above.
        $serviceName = trim(preg_replace('/\s+/u', ' ', $categoryName));
        $serviceName = mb_strtolower(trim(preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', $serviceName)));
        $commandHash = hash('sha256', json_encode(['create', null, $serviceName, null]));
        if ($command && !hash_equals($command['request_hash'], $commandHash)) throw new RuntimeException('The tahini category request key already belongs to a different name.');
        foreach ($categories as $row) if ($row['request_key'] === self::CATEGORY_REQUEST
            && (!$category || $category['key'] !== 'custom_'.$row['id'] || !hash_equals($row['name_hash'], hash('sha256', $serviceName)))) throw new RuntimeException('The tahini category request key collides with an existing category.');
        $position = 0; foreach ($ingredients as $row) $position = max($position, (int) $row['position']);
        if (!$ingredient && $position >= 65535) throw new RuntimeException('The ingredient ordering range is exhausted.');
        $before = ['branches'=>array_map(static fn($row)=>['id'=>(int) $row['id'],'name'=>$row['name']], $branches),
            'stock_ingredients'=>$ingredients,'branch_expense_categories'=>$categories,'branch_expense_category_settings'=>$settings,'category_command'=>$command];
        return ['ingredient'=>$ingredient,'ingredient_position'=>$position+1,'category'=>$category,'category_name'=>$categoryName,
            'ingredient_action'=>$ingredient ? 'reuse' : 'create','category_action'=>!$category ? 'create' : ($category['active'] ? 'reuse' : 'reactivate'),
            'before'=>$before,'before_sha256'=>hash('sha256', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    private function checkRecord(array $record): void
    {
        if (($record['release'] ?? '') !== self::RELEASE || ($record['ingredient_unit'] ?? '') !== $this->unit
            || ($record['branch_ids'] ?? []) !== self::BRANCH_IDS) throw new RuntimeException('The private tahini record belongs to a different scope or ingredient unit.');
    }

    private function summary(array $plan): array
    {
        return ['release'=>self::RELEASE,'branch_count'=>32,'shared_catalog'=>true,'ingredient_unit'=>$this->unit,
            'ingredient'=>$plan['ingredient'],'expense_category'=>$plan['category'],'ingredient_action'=>$plan['ingredient_action'],
            'expense_category_action'=>$plan['category_action'],'recipe_created'=>false,'financial_entries_created'=>0,'stock_movements_created'=>0];
    }

    private function checkStoredRecords(string $directory): void
    {
        foreach (['before.json','receipt.json'] as $name) {
            if (is_link($directory.'/'.$name)) throw new RuntimeException('Unsafe private tahini record.');
            if (is_file($directory.'/'.$name)) $this->checkRecord($this->readJson($directory.'/'.$name));
        }
    }

    public function run(bool $apply, ?string $backup): array
    {
        $this->requireSchema(); $service = app(ExpenseCategories::class); $directory = $this->root.'/storage/app/'.self::RELEASE;
        if (is_link($directory)) throw new RuntimeException('Unsafe private tahini state directory.');
        $this->checkStoredRecords($directory);
        $lock = null;
        if ($apply) {
            if (!$backup || !is_dir($backup) || is_link($backup) || !is_writable($backup) || (fileperms($backup) & 0077)) throw new RuntimeException('Pass an existing writable private tahini backup directory with mode 0700.');
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private tahini state directory.');
            if (is_link($directory.'/install.lock')) throw new RuntimeException('Unsafe tahini installation lock.');
            $lock = fopen($directory.'/install.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another tahini catalog update is active.');
        }
        try {
            // Another attempt may have finished between the initial read and taking the file lock.
            $this->checkStoredRecords($directory);
            $plan = $this->plan($service);
            if (is_file($directory.'/receipt.json')) {
                $receipt = $this->readJson($directory.'/receipt.json');
                if (!$plan['ingredient'] || !$plan['category'] || !$plan['category']['active']
                    || (int) $plan['ingredient']['id'] !== (int) ($receipt['ingredient']['id'] ?? 0)
                    || $plan['category']['key'] !== ($receipt['expense_category']['key'] ?? null)) throw new RuntimeException('The completed tahini catalog changed; no replacement rows will be created.');
            }
            if (!$apply) return ['status'=>'TAHINI_CATALOG_CHECK_READY','read_only'=>true]+$this->summary($plan);
            $record = ['release'=>self::RELEASE,'ingredient_unit'=>$this->unit,'branch_ids'=>self::BRANCH_IDS,'recorded_at'=>gmdate('c'),'before'=>$plan['before']];
            DB::beginTransaction();
            try {
                DB::table('users')->where('id', 1)->lockForUpdate()->first();
                $locked = $this->plan($service, true);
                if ($locked['before_sha256'] !== $plan['before_sha256']) throw new RuntimeException('The catalog changed during tahini preparation; no rows were written.');
                if (!is_file($directory.'/before.json')) $this->writeJson($directory.'/before.json', $record);
                $this->writeJson($backup.'/before-tahini-catalog.json', $this->readJson($directory.'/before.json'));
                $categoryChanged = $locked['category_action'] !== 'reuse';
                if ($categoryChanged) $service->save(['action'=>'create','name'=>$locked['category_name'],'idempotency_key'=>self::CATEGORY_REQUEST], $this->owner($service));
                $ingredientCreated = !$locked['ingredient'];
                if ($ingredientCreated) DB::table('stock_ingredients')->insertGetId(['name'=>self::NAME,'unit'=>$this->unit,'position'=>$locked['ingredient_position'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                $verified = $this->plan($service, true);
                if (!$verified['ingredient'] || !$verified['category'] || !$verified['category']['active']
                    || $verified['ingredient']['unit'] !== $this->unit) throw new RuntimeException('Tahini catalog verification failed; all database changes were rolled back.');
                DB::commit();
            } catch (Throwable $error) {
                try { if (DB::transactionLevel() > 0) DB::rollBack(); } catch (Throwable $rollbackError) {}
                throw $error;
            }
            $result = ['status'=>'TAHINI_CATALOG_READY','verified'=>true,'ingredient_created'=>$ingredientCreated,'expense_category_changed'=>$categoryChanged,
                'backup'=>$backup,'receipt'=>$directory.'/receipt.json']+$this->summary($verified);
            $receipt = $result+['ingredient_unit'=>$this->unit,'branch_ids'=>self::BRANCH_IDS,'recorded_at'=>gmdate('c')];
            $this->writeJson($directory.'/receipt.json', $receipt); $this->writeJson($backup.'/applied-tahini-catalog.json', $receipt);
            return $result;
        } finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['check','apply','root:','ingredient-unit:','backup:']);
        if (isset($options['check']) === isset($options['apply'])) throw new RuntimeException('Choose exactly one of --check or --apply.');
        $root = realpath($options['root'] ?? '');
        if (!$root || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('Invalid existing Laravel root.');
        require $root.'/vendor/autoload.php'; $application = require $root.'/bootstrap/app.php';
        $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $runner = new FasakhanstaTahiniCatalog20261021($root, $options['ingredient-unit'] ?? '');
        echo json_encode($runner->run(isset($options['apply']), $options['backup'] ?? null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) { fwrite(STDERR, 'TAHINI_CATALOG_STOPPED: '.$error->getMessage()."\n"); exit(1); }
}
