<?php
/**
 * Owner-requested data-only addition, 2026-10-21.
 * Run from a staged release: php menu_sides_20261021.php --check|--apply --root=/... [--backup=/private/...]
 * No migrations, application file replacement, ingredient quantities or external notifications.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\ResturantProduct;

final class FasakhanstaMenuSides20261021
{
    public const RELEASE = 'menu-sides-20261021';
    private const EXTRAS = ['extra_combo', 'extra_large', 'extra_medium', 'extra_clean', 'extra_clear', 'extra_vacuim'];
    private const ITEMS = [
        'tomato' => ['name'=>'طبق طماطم', 'price'=>15, 'file'=>'tomato-plate.jpg', 'sha256'=>'3130d500075a2329504a8dab4c5313e4dc721b88a8c1611138c89ac5eab59be6', 'aliases'=>['طبق طماطم', 'طماطم']],
        'tahini' => ['name'=>'طبق طحينة', 'price'=>30, 'file'=>'tahini-plate.jpg', 'sha256'=>'1fce3105f48d8518739301f11a403e61e677ef52a6bf0b659f4a5f74ce72053e', 'aliases'=>['طبق طحينة', 'طحينة']],
    ];
    private string $root;
    private string $assets;
    private array $columns = [];
    private string $publicDiskRoot = '';
    private array $appUrl = [];

    public function __construct(string $root, string $assets)
    {
        $this->root = $root;
        $this->assets = $assets;
    }

    public static function normalize(string $value): string
    {
        $value = strtr($value, ['أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ة'=>'ه', 'ى'=>'ي']);
        return trim((string) preg_replace('/\s+/u', ' ', preg_replace('/[ـ\x{064B}-\x{065F}]/u', '', $value)));
    }

    private function requireSchema(): void
    {
        foreach ([
            'users'=>['id'],
            'resturants'=>['id', 'name'],
            'categories'=>['id', 'added_by', 'name_ar', 'status'],
            'products'=>['id', 'added_by', 'category_id', 'name_ar', 'status'],
            'resturant_products'=>['id', 'added_by', 'resturant_id', 'product_id', 'category_id', 'product_name', 'product_price', 'price', 'status'],
            'media'=>['id', 'model_type', 'model_id', 'collection_name', 'disk', 'file_name', 'custom_properties'],
        ] as $table=>$required) {
            if (!Schema::hasTable($table)) throw new RuntimeException('Missing required menu table: '.$table);
            $this->columns[$table] = Schema::getColumnListing($table);
            if (array_diff($required, $this->columns[$table])) throw new RuntimeException('Required runtime menu columns are missing: '.$table);
        }
        $this->requirePublicMediaMapping();
        foreach (self::ITEMS as $item) {
            $path = $this->assets.'/'.$item['file'];
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $item['sha256']) throw new RuntimeException('Menu image integrity failure: '.$item['file']);
        }
    }

    /** Verify existing URL-to-directory mapping; never repair site configuration here. */
    private function requirePublicMediaMapping(): void
    {
        if (config('filesystems.disks.public.driver') !== 'local') throw new RuntimeException('The public media disk must be local for verified installation.');
        $appUrl = parse_url((string) config('app.url'));
        if (!is_array($appUrl) || !isset($appUrl['scheme'], $appUrl['host']) || !in_array(strtolower($appUrl['scheme']), ['http', 'https'], true)
            || isset($appUrl['user']) || isset($appUrl['pass']) || isset($appUrl['query']) || isset($appUrl['fragment'])) throw new RuntimeException('APP_URL must be a valid HTTP origin with an optional base path.');
        $this->appUrl = $appUrl;
        $diskRoot = realpath((string) config('filesystems.disks.public.root'));
        if ($diskRoot === false || !is_dir($diskRoot)) throw new RuntimeException('The configured public media directory is missing.');
        $this->publicDiskRoot = $diskRoot;
        $urlPath = $this->publicUrlPath((string) config('filesystems.disks.public.url'));
        $mapped = realpath($this->root.'/public'.$urlPath);
        if ($mapped === false || !is_dir($mapped) || $mapped !== $diskRoot) throw new RuntimeException('Public media URL does not resolve to the configured media directory through public/. No menu data was changed.');
    }

    private function originPort(array $url): int
    {
        return (int) ($url['port'] ?? (strtolower($url['scheme'] ?? '') === 'https' ? 443 : 80));
    }

    /** Return a physical public/-relative URL path, with APP_URL's base removed. */
    private function publicUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Invalid public media URL.');
        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (!isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== strtolower($this->appUrl['scheme'])
                || strtolower($parts['host']) !== strtolower($this->appUrl['host']) || $this->originPort($parts) !== $this->originPort($this->appUrl)) throw new RuntimeException('Menu images must use the same origin as APP_URL.');
        } elseif (!str_starts_with($url, '/')) throw new RuntimeException('Public media URL must be absolute or root-relative.');
        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $base = rtrim(rawurldecode((string) ($this->appUrl['path'] ?? '')), '/');
        foreach ([$path, $base] as $value) {
            if (str_contains($value, "\0") || str_contains($value, '\\') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $value)) throw new RuntimeException('Unsafe public media URL path.');
        }
        if (!str_starts_with($path, '/')) throw new RuntimeException('Invalid public media URL path.');
        if ($base !== '') {
            if ($path === $base) $path = '/';
            elseif (str_starts_with($path, $base.'/')) $path = substr($path, strlen($base));
            else throw new RuntimeException('Public media URL is outside the APP_URL base path.');
        }
        return $path;
    }

    private function verifyMediaUrl(string $url, string $physicalPath): void
    {
        $path = realpath($physicalPath);
        $mapped = realpath($this->root.'/public'.$this->publicUrlPath($url));
        if ($path === false || !str_starts_with($path, $this->publicDiskRoot.DIRECTORY_SEPARATOR)
            || $mapped === false || $mapped !== $path || !is_file($mapped)) throw new RuntimeException('Menu image URL does not resolve to its verified image file.');
    }

    private function rows(string $table, bool $lock): array
    {
        $query = DB::table($table)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->get()->all();
    }

    private function matches(string $name, array $item): bool
    {
        return in_array(self::normalize($name), array_map([self::class, 'normalize'], $item['aliases']), true);
    }

    private function category(array $categories): object
    {
        $aliases = array_map([self::class, 'normalize'], ['الأطباق الجانبية', 'أطباق جانبية', 'الاطباق الجانبيه', 'اطباق جانبيه']);
        $matches = array_values(array_filter($categories, static fn($row) => in_array(self::normalize((string) $row->name_ar), $aliases, true)));
        if (count($matches) !== 1) throw new RuntimeException('Expected exactly one existing side-dishes category; found '.count($matches).'. No changes made.');
        $category = $matches[0];
        if ($category->status !== 'show' || !empty($category->parent_id)) throw new RuntimeException('The side-dishes category is hidden or is not a top-level category.');
        if (!DB::table('users')->where('id', $category->added_by)->exists()) throw new RuntimeException('The side-dishes category has no valid owner.');
        return $category;
    }

    private function readReceipt(string $directory): ?array
    {
        $receipt = $directory.'/receipt.json';
        $pending = $directory.'/receipt.pending.json';
        if (is_file($receipt)) {
            if (is_link($receipt)) throw new RuntimeException('Receipt must not be a symlink.');
            return json_decode(file_get_contents($receipt), true, 512, JSON_THROW_ON_ERROR);
        }
        if (is_file($pending)) {
            if (is_link($pending)) throw new RuntimeException('Pending receipt must not be a symlink.');
            $data = json_decode(file_get_contents($pending), true, 512, JSON_THROW_ON_ERROR);
            $ids = array_column($data['products'] ?? [], 'id');
            $menuIds = array_column($data['menu'] ?? [], 'id');
            // A process killed before COMMIT leaves no release rows. A process killed
            // after COMMIT leaves all of them and can safely finish the same receipt.
            $productCount = $ids ? DB::table('products')->whereIn('id', $ids)->count() : 0;
            $menuCount = $menuIds ? DB::table('resturant_products')->whereIn('id', $menuIds)->count() : 0;
            if ($productCount === count($ids) && count($ids) === 2 && $menuCount === count($menuIds) && count($menuIds) > 0) return $data;
            throw new RuntimeException('An interrupted installation receipt exists. Its database rows are absent or incomplete; review the private pending receipt before retrying.');
        }
        return null;
    }

    private function retained(array $values, string $table): array
    {
        return array_intersect_key($values, array_flip($this->columns[$table]));
    }

    private function plainPriceOptions(string $json): bool
    {
        $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($values) || count($values) !== count(self::EXTRAS)) return false;
        foreach (self::EXTRAS as $key) if (!array_key_exists($key, $values) || $values[$key] !== null) return false;
        return true;
    }

    private function verify(array $receipt, array $branches, object $category): void
    {
        if (($receipt['release'] ?? '') !== self::RELEASE) throw new RuntimeException('Unrecognized installation receipt.');
        $expectedBranches = array_map(static fn($row)=>(int) $row->id, $branches);
        if ($expectedBranches !== ($receipt['branch_ids'] ?? [])) throw new RuntimeException('Branch coverage changed since installation; review the new scope before reapplying.');
        if ((int) $category->id !== (int) ($receipt['category_id'] ?? 0)) throw new RuntimeException('The side category changed after installation.');
        $products = $receipt['products'] ?? [];
        $menu = $receipt['menu'] ?? [];
        if (count($products) !== 2 || count($menu) !== count($branches) * 2) throw new RuntimeException('Incomplete menu release receipt.');
        $productIds = [];
        foreach ($products as $entry) {
            $item = self::ITEMS[$entry['key'] ?? ''] ?? null;
            $row = DB::table('products')->where('id', $entry['id'] ?? 0)->first();
            if (!$item || !$row || $row->name_ar !== $item['name'] || (int) $row->category_id !== (int) $category->id || $row->status !== 'show') throw new RuntimeException('Installed product identity changed.');
            if (isset($row->has_clean) && (string) $row->has_clean !== '0') throw new RuntimeException('Unexpected cleaning option on installed side product.');
            if (Schema::hasTable('product_features') && DB::table('product_features')->where('product_id', $row->id)->exists()) throw new RuntimeException('Unexpected size features on installed side product.');
            $productIds[$entry['key']] = (int) $row->id;
        }
        $pairs = [];
        foreach ($menu as $entry) {
            $key = $entry['key'] ?? '';
            $item = self::ITEMS[$key] ?? null;
            $row = DB::table('resturant_products')->where('id', $entry['id'] ?? 0)->first();
            if (!$item || !$row || $row->product_name !== $item['name'] || (int) $row->product_id !== ($productIds[$key] ?? 0)
                || (int) $row->resturant_id !== (int) $entry['branch_id'] || !in_array((int) $row->resturant_id, $expectedBranches, true)
                || (int) $row->category_id !== (int) $category->id || $row->status !== 'show' || !is_numeric($row->product_price) || (float) $row->product_price !== (float) $item['price']
                || !$this->plainPriceOptions((string) $row->price)) throw new RuntimeException('Installed menu values changed: '.($entry['id'] ?? '?'));
            $pair = $entry['branch_id'].':'.$key;
            if (isset($pairs[$pair])) throw new RuntimeException('Duplicate branch/item pair in receipt.');
            $pairs[$pair] = true;
            $model = ResturantProduct::withoutGlobalScopes()->findOrFail($row->id);
            $media = $model->getMedia('product_image');
            if ($media->count() !== 1 || (int) $media->first()->id !== (int) ($entry['media_id'] ?? 0)) throw new RuntimeException('Installed menu image changed.');
            $image = $media->first();
            if ($image->disk !== 'public' || $image->getCustomProperty('menu_release') !== self::RELEASE || !is_file($image->getPath())
                || hash_file('sha256', $image->getPath()) !== $item['sha256']) throw new RuntimeException('Installed menu image verification failed.');
            $this->verifyMediaUrl($model->getFirstMediaUrl('product_image'), $image->getPath());
        }
        // Also reject extra matching rows not recorded by this release.
        foreach ($this->rows('products', false) as $row) foreach (self::ITEMS as $key=>$item) {
            if ($this->matches((string) $row->name_ar, $item) && (int) $row->id !== $productIds[$key]) throw new RuntimeException('A duplicate side product exists outside the release.');
        }
        $menuIds = array_map('intval', array_column($menu, 'id'));
        foreach ($this->rows('resturant_products', false) as $row) foreach (self::ITEMS as $item) {
            if ($this->matches((string) $row->product_name, $item) && !in_array((int) $row->id, $menuIds, true)) throw new RuntimeException('A duplicate branch side item exists outside the release.');
        }
    }

    private function writeJson(string $path, array $data): void
    {
        $temp = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) throw new RuntimeException('Could not write private installation record.');
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Could not finalize private installation record.'); }
    }

    public function run(bool $apply, ?string $backup): array
    {
        $this->requireSchema();
        $directory = $this->root.'/storage/app/'.self::RELEASE;
        if (is_link($directory)) throw new RuntimeException('Private release directory must not be a symlink.');
        $lock = null;
        if ($apply) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private release directory.');
            chmod($directory, 0700);
            if (is_link($directory.'/install.lock')) throw new RuntimeException('Installation lock must not be a symlink.');
            $lock = fopen($directory.'/install.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another menu-side installation is running.');
        }
        $committed = false;
        $commitAttempted = false;
        $createdPaths = [];
        try {
            if ($apply) DB::beginTransaction();
            $branches = $this->rows('resturants', $apply);
            if (!$branches) throw new RuntimeException('No Fasakhansta branches were found.');
            $category = $this->category($this->rows('categories', $apply));
            $receipt = $this->readReceipt($directory);
            $summary = ['release'=>self::RELEASE, 'category_id'=>(int) $category->id, 'category'=>$category->name_ar,
                'branch_count'=>count($branches), 'menu_row_count'=>count($branches)*2,
                'branches'=>array_map(static fn($row)=>['id'=>(int) $row->id, 'name'=>$row->name], $branches),
                'items'=>array_map(static fn($item)=>['name'=>$item['name'], 'price_egp'=>$item['price']], self::ITEMS)];
            if ($receipt !== null) {
                $this->verify($receipt, $branches, $category);
                if ($apply) {
                    $commitAttempted = true; DB::commit(); $committed = true;
                    $receipt['phase'] = 'committed';
                    $this->writeJson($directory.'/receipt.json', $receipt);
                    if (is_file($directory.'/receipt.pending.json')) unlink($directory.'/receipt.pending.json');
                }
                return ['status'=>'ALREADY_APPLIED', 'verified'=>true]+$summary;
            }
            // Lock all current menu rows so a concurrent edit cannot race a collision
            // scan. The SQL insert transaction never updates an existing menu row.
            $existingProducts = $this->rows('products', $apply);
            $existingMenu = $this->rows('resturant_products', $apply);
            foreach (self::ITEMS as $item) {
                foreach ($existingProducts as $row) if ($this->matches((string) $row->name_ar, $item)) throw new RuntimeException('Pre-existing matching product requires review: '.$row->id.' / '.$row->name_ar);
                foreach ($existingMenu as $row) if ($this->matches((string) $row->product_name, $item)) throw new RuntimeException('Pre-existing matching branch item requires review: '.$row->id.' / '.$row->product_name);
            }
            if (!$apply) return ['status'=>'CHECK_READY', 'read_only'=>true]+$summary;
            if (!$backup || !is_dir($backup) || is_link($backup) || !is_writable($backup)) throw new RuntimeException('Pass an existing writable private backup directory.');
            $this->writeJson($backup.'/before-menu-sides.json', ['release'=>self::RELEASE, 'recorded_at'=>gmdate('c'), 'scope'=>$summary,
                'categories'=>$this->rows('categories', false), 'products'=>$existingProducts, 'resturant_products'=>$existingMenu]);
            $receipt = ['release'=>self::RELEASE, 'phase'=>'prepared', 'recorded_at'=>gmdate('c'), 'backup'=>$backup,
                'category_id'=>(int) $category->id, 'branch_ids'=>array_map(static fn($row)=>(int) $row->id, $branches), 'products'=>[], 'menu'=>[]];
            $now = gmdate('Y-m-d H:i:s');
            foreach (self::ITEMS as $key=>$item) {
                $productId = DB::table('products')->insertGetId($this->retained([
                    'added_by'=>(int) $category->added_by, 'category_id'=>(int) $category->id, 'subcategory_id'=>null,
                    'name_ar'=>$item['name'], 'name_en'=>$item['name'], 'status'=>'show', 'has_clean'=>0, 'created_at'=>$now, 'updated_at'=>$now,
                ], 'products'));
                $receipt['products'][] = ['id'=>(int) $productId, 'key'=>$key];
                foreach ($branches as $branch) {
                    $addedBy = !empty($branch->added_by) && DB::table('users')->where('id', $branch->added_by)->exists() ? (int) $branch->added_by : (int) $category->added_by;
                    $id = DB::table('resturant_products')->insertGetId($this->retained([
                        'added_by'=>$addedBy, 'resturant_id'=>(int) $branch->id, 'product_id'=>(int) $productId, 'category_id'=>(int) $category->id,
                        'product_name'=>$item['name'], 'product_description'=>$item['name'], 'product_price'=>$item['price'],
                        'price'=>json_encode(array_fill_keys(self::EXTRAS, null), JSON_THROW_ON_ERROR), 'status'=>'show', 'highest_rated'=>'no', 'created_at'=>$now, 'updated_at'=>$now,
                    ], 'resturant_products'));
                    $model = ResturantProduct::withoutGlobalScopes()->findOrFail($id);
                    $media = $model->addMediaFromString(file_get_contents($this->assets.'/'.$item['file']))
                        ->usingName($item['name'])->usingFileName($item['file'])
                        ->withCustomProperties(['menu_release'=>self::RELEASE, 'menu_item'=>$key, 'sha256'=>$item['sha256']])
                        ->toMediaCollection('product_image', 'public');
                    $createdPaths[] = $media->getPath();
                    $receipt['menu'][] = ['id'=>(int) $id, 'branch_id'=>(int) $branch->id, 'key'=>$key, 'media_id'=>(int) $media->id, 'image_path'=>$media->getPath()];
                }
            }
            $this->verify($receipt, $branches, $category);
            // Durable prepared receipt precedes COMMIT. A subsequent run can verify
            // all database rows before finalizing a receipt after process interruption.
            $this->writeJson($directory.'/receipt.pending.json', $receipt);
            $this->writeJson($backup.'/prepared-menu-sides.json', $receipt);
            $commitAttempted = true; DB::commit(); $committed = true;
            $receipt['phase'] = 'committed';
            $this->writeJson($directory.'/receipt.json', $receipt);
            unlink($directory.'/receipt.pending.json');
            $this->writeJson($backup.'/applied-menu-sides.json', $receipt);
            return ['status'=>'MENU_SIDES_READY', 'verified'=>true, 'receipt'=>$directory.'/receipt.json', 'backup'=>$backup]+$summary;
        } catch (Throwable $error) {
            if ($apply && !$committed) {
                try { if (DB::transactionLevel() > 0) DB::rollBack(); } catch (Throwable $rollbackError) { /* Keep the original failure. */ }
                // A COMMIT connection failure can have an unknown outcome. Keep
                // its pending receipt and images for verification on the next run.
                if (!$commitAttempted) {
                    // Files are not transactional. Delete only exact originals created by
                    // this process; never remove shared directories or any existing media.
                    foreach ($createdPaths as $path) if (is_file($path) && !is_link($path)) @unlink($path);
                    if (is_file($directory.'/receipt.pending.json')) @unlink($directory.'/receipt.pending.json');
                }
            }
            throw $error;
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['check', 'apply', 'root:', 'backup:']);
        if (isset($options['check']) === isset($options['apply'])) throw new RuntimeException('Choose exactly one of --check or --apply.');
        $root = realpath($options['root'] ?? '');
        if (!$root || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('The existing Laravel root is invalid.');
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $runner = new FasakhanstaMenuSides20261021($root, __DIR__.'/menu-sides-20261021/assets');
        $result = $runner->run(isset($options['apply']), $options['backup'] ?? null);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'MENU_SIDES_STOPPED: '.$error->getMessage()."\n");
        exit(1);
    }
}
