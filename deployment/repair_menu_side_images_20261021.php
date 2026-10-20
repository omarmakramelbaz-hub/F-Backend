<?php
/** Repair only the 64 image records created by menu-sides-20261021. */
use App\Models\ResturantProduct;
use Illuminate\Support\Facades\{DB, Schema, Storage};

final class FasakhanstaMenuSideImageRepair20261021
{
    public const RELEASE = 'menu-side-images-20261021';
    private const SOURCE = 'menu-sides-20261021';
    private const ITEMS = [
        'tomato'=>['name'=>'طبق طماطم','price'=>15,'file'=>'tomato-plate.jpg','sha256'=>'3130d500075a2329504a8dab4c5313e4dc721b88a8c1611138c89ac5eab59be6'],
        'tahini'=>['name'=>'طبق طحينة','price'=>30,'file'=>'tahini-plate.jpg','sha256'=>'1fce3105f48d8518739301f11a403e61e677ef52a6bf0b659f4a5f74ce72053e'],
    ];
    private string $root;
    private $httpVerifier;
    private array $mediaColumns = [];
    private array $appUrl = [];
    private string $diskRoot = '';

    public function __construct(string $root, ?callable $httpVerifier = null)
    {
        $this->root = $root;
        $this->httpVerifier = $httpVerifier;
    }

    private function json(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing or unsafe private receipt.');
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid private receipt.');
        return $value;
    }

    private function writeJson(string $path, array $data): void
    {
        if (is_link($path)) throw new RuntimeException('Unsafe receipt destination.');
        $temp = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) throw new RuntimeException('Could not save image repair receipt.');
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Could not finalize image repair receipt.'); }
    }

    private function setup(): array
    {
        foreach (['products'=>['id','category_id','name_ar','status'], 'resturant_products'=>['id','resturant_id','product_id','category_id','product_name','product_price','price','status'],
            'media'=>['id','model_type','model_id','collection_name','disk','file_name','custom_properties']] as $table=>$columns) {
            if (!Schema::hasTable($table) || array_diff($columns, Schema::getColumnListing($table))) throw new RuntimeException('Required live image/menu schema is missing: '.$table);
        }
        $this->mediaColumns = Schema::getColumnListing('media');
        if (config('filesystems.disks.products.driver') !== 'local') throw new RuntimeException('The existing products disk must be local.');
        $diskRoot = realpath((string) config('filesystems.disks.products.root'));
        if (!$diskRoot || !is_dir($diskRoot)) throw new RuntimeException('The existing products disk directory is missing.');
        $this->diskRoot = $diskRoot;
        $this->appUrl = parse_url((string) config('app.url')) ?: [];
        if (!isset($this->appUrl['scheme'], $this->appUrl['host']) || !in_array(strtolower($this->appUrl['scheme']), ['http','https'], true)
            || isset($this->appUrl['user']) || isset($this->appUrl['pass'])) throw new RuntimeException('APP_URL is not a valid HTTP origin.');
        $receipt = $this->json($this->root.'/storage/app/'.self::SOURCE.'/receipt.json');
        if (($receipt['release'] ?? '') !== self::SOURCE || (int) ($receipt['category_id'] ?? 0) !== 7
            || count($receipt['branch_ids'] ?? []) !== 32 || count(array_unique($receipt['branch_ids'] ?? [])) !== 32
            || count($receipt['products'] ?? []) !== 2 || count($receipt['menu'] ?? []) !== 64) throw new RuntimeException('The original receipt does not match the reviewed 32-branch/64-image scope.');
        return $receipt;
    }

    private function ownUrl(string $url): void
    {
        $parts = parse_url($url);
        $port = static fn(array $value)=>(int) ($value['port'] ?? (strtolower($value['scheme'] ?? '') === 'https' ? 443 : 80));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strtolower($parts['scheme']) !== strtolower($this->appUrl['scheme']) || strtolower($parts['host']) !== strtolower($this->appUrl['host'])
            || $port($parts) !== $port($this->appUrl)) throw new RuntimeException('Image verification URL must use the same origin as APP_URL.');
    }

    private function emptyData($value): bool
    {
        if (is_string($value)) $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return $value === null || $value === [];
    }

    /** Build the destination from the live Spatie model/path generator, never guessed disk paths. */
    private function plan(array $receipt): array
    {
        $productIds = []; $pairs = []; $mediaIds = []; $result = []; $disks = [];
        foreach ($receipt['products'] as $entry) {
            $item = self::ITEMS[$entry['key'] ?? ''] ?? null;
            $product = DB::table('products')->where('id', $entry['id'] ?? 0)->first();
            if (!$item || isset($productIds[$entry['key']]) || !$product || $product->name_ar !== $item['name'] || (int) $product->category_id !== 7 || $product->status !== 'show') throw new RuntimeException('The original side-product identity changed.');
            $productIds[$entry['key']] = (int) $product->id;
        }
        if (count($productIds) !== 2) throw new RuntimeException('Incomplete side-product receipt.');
        foreach ($receipt['menu'] as $entry) {
            $key = $entry['key'] ?? ''; $item = self::ITEMS[$key] ?? null;
            $row = DB::table('resturant_products')->where('id', $entry['id'] ?? 0)->first();
            $pair = ($entry['branch_id'] ?? '').':'.$key;
            if (!$item || !$row || isset($pairs[$pair]) || !in_array((int) ($entry['branch_id'] ?? 0), array_map('intval', $receipt['branch_ids']), true)
                || (int) $row->resturant_id !== (int) $entry['branch_id'] || (int) $row->product_id !== $productIds[$key]
                || $row->product_name !== $item['name'] || (int) $row->category_id !== 7 || $row->status !== 'show'
                || !is_numeric($row->product_price) || (float) $row->product_price !== (float) $item['price']) throw new RuntimeException('The original branch side item changed.');
            $extras = json_decode((string) $row->price, true, 512, JSON_THROW_ON_ERROR);
            $keys = ['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
            if (!is_array($extras) || count($extras) !== 6) throw new RuntimeException('The side-item optional prices changed.');
            foreach ($keys as $extra) if (!array_key_exists($extra, $extras) || $extras[$extra] !== null) throw new RuntimeException('The side-item optional prices changed.');
            $pairs[$pair] = true;
            $model = ResturantProduct::withoutGlobalScopes()->findOrFail($row->id);
            $images = $model->getMedia('product_image');
            $media = $images->first();
            if ($images->count() !== 1 || !$media || (int) $media->id !== (int) ($entry['media_id'] ?? 0) || isset($mediaIds[$media->id])
                || $media->model_type !== $model->getMorphClass() || (int) $media->model_id !== (int) $row->id || $media->collection_name !== 'product_image'
                || $media->file_name !== $item['file'] || !in_array($media->disk, ['public','products'], true)
                || $media->getCustomProperty('menu_release') !== self::SOURCE || $media->getCustomProperty('menu_item') !== $key
                || $media->getCustomProperty('sha256') !== $item['sha256']) throw new RuntimeException('The original side-image identity changed.');
            foreach (['generated_conversions','responsive_images'] as $field) if (in_array($field, $this->mediaColumns, true) && !$this->emptyData($media->{$field})) throw new RuntimeException('Unexpected image derivatives require separate review.');
            if (in_array('conversions_disk', $this->mediaColumns, true) && !in_array($media->conversions_disk, [null,'','public','products'], true)) throw new RuntimeException('Unexpected conversion disk.');
            $source = $media->getPath();
            if (!is_file($source) || is_link($source) || hash_file('sha256', $source) !== $item['sha256']) throw new RuntimeException('The original side-image file failed its SHA check.');
            $target = clone $media; $target->disk = 'products';
            if (in_array('conversions_disk', $this->mediaColumns, true)) $target->conversions_disk = 'products';
            $destination = $target->getPath();
            $configuredRoot = rtrim((string) config('filesystems.disks.products.root'), '/');
            if (!str_starts_with($destination, $configuredRoot.'/')) throw new RuntimeException('Unexpected products media path.');
            // Media::getPath() is supported by the installed Spatie 9.6 API;
            // derive the disk-relative suffix without relying on newer methods.
            $relative = substr($destination, strlen($configuredRoot) + 1);
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0") || preg_match('~(?:^|/)\.\.?(?:/|$)~', $relative)
                || dirname($relative) !== (string) $media->id) throw new RuntimeException('Unexpected non-dedicated media path; no shared directories will be modified.');
            $url = $target->getUrl(); $this->ownUrl($url);
            if (is_link($destination) || is_link(dirname($destination)) || (file_exists($destination) && (!is_file($destination) || hash_file('sha256', $destination) !== $item['sha256']))) throw new RuntimeException('An existing destination image conflicts with the reviewed image.');
            $before = (array) DB::table('media')->where('id', $media->id)->first();
            $changes = ['disk'=>'products'];
            if (in_array('conversions_disk', $this->mediaColumns, true)) $changes['conversions_disk'] = 'products';
            $result[] = ['media_id'=>(int) $media->id, 'menu_id'=>(int) $row->id, 'key'=>$key, 'source'=>$source, 'destination'=>$destination, 'relative'=>$relative,
                'url'=>$url, 'sha256'=>$item['sha256'], 'before'=>$before, 'changes'=>$changes];
            $mediaIds[$media->id] = true; $disks[$media->disk] = true;
        }
        if (count($result) !== 64 || count($disks) !== 1) throw new RuntimeException('The image migration is incomplete or mixes public/products disks; review before retrying.');
        return $result;
    }

    private function verifyHttp(array $targets): void
    {
        if ($this->httpVerifier !== null) { ($this->httpVerifier)($targets); return; }
        if (!function_exists('curl_multi_init')) throw new RuntimeException('PHP cURL is required for image HTTP verification.');
        foreach (array_chunk($targets, 4) as $chunk) {
            $multi = curl_multi_init(); $handles = []; $bodies = [];
            try {
                foreach ($chunk as $index=>$target) {
                    $this->ownUrl($target['url']); $bodies[$index] = '';
                    $handle = curl_init($target['url']);
                    curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
                        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT=>'Fasakhansta-Menu-Image-Verification/1',
                        CURLOPT_WRITEFUNCTION=>static function ($curl, string $bytes) use (&$bodies, $index): int {
                            if (strlen($bodies[$index]) + strlen($bytes) > 1048576) return 0;
                            $bodies[$index] .= $bytes; return strlen($bytes);
                        }]);
                    curl_multi_add_handle($multi, $handle); $handles[$index] = $handle;
                }
                do { $status = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.2); } while ($running && $status === CURLM_OK);
                foreach ($handles as $index=>$handle) {
                    $body = $bodies[$index]; $target = $chunk[$index];
                    if ($status !== CURLM_OK || curl_errno($handle) || (int) curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200
                        || hash('sha256', $body) !== $target['sha256'] || @getimagesizefromstring($body) === false) throw new RuntimeException('HTTP image verification failed for media '.$target['media_id'].'. No media references were switched.');
                }
            } finally {
                foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
                curl_multi_close($multi);
            }
        }
    }

    public function run(bool $apply, ?string $backup): array
    {
        $receipt = $this->setup(); $directory = $this->root.'/storage/app/'.self::SOURCE;
        if (is_link($directory)) throw new RuntimeException('Unsafe private release directory.');
        $lock = null;
        if ($apply) {
            if (!$backup || !is_dir($backup) || is_link($backup) || !is_writable($backup)) throw new RuntimeException('Pass a writable private backup directory.');
            if (is_link($directory.'/install.lock')) throw new RuntimeException('Unsafe installation lock.');
            $lock = fopen($directory.'/install.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another menu/image installation is running.');
        }
        try {
            $plan = $this->plan($receipt);
            $already = $plan[0]['before']['disk'] === 'products';
            $sampleUrls = [];
            foreach ($plan as $entry) if (!isset($sampleUrls[$entry['key']])) $sampleUrls[$entry['key']] = $entry['url'];
            $summary = ['release'=>self::RELEASE, 'branch_count'=>32, 'image_count'=>64, 'destination_disk'=>'products',
                'already_repaired'=>$already, 'sample_urls'=>$sampleUrls];
            if (!$apply) return ['status'=>'IMAGE_REPAIR_CHECK_READY','read_only'=>true]+$summary;
            $record = ['release'=>self::RELEASE, 'phase'=>'prepared', 'recorded_at'=>gmdate('c'), 'source_receipt'=>$receipt, 'images'=>$plan];
            $this->writeJson($backup.'/before-menu-side-images.json', $record);
            foreach ($plan as $entry) {
                if (!is_file($entry['destination'])) {
                    if (!Storage::disk('products')->put($entry['relative'], file_get_contents($entry['source']))) throw new RuntimeException('Could not publish the verified image copy.');
                }
                if (!Storage::disk('products')->setVisibility($entry['relative'], 'public')) throw new RuntimeException('Could not publish image file permissions.');
                if (!chmod(dirname($entry['destination']), 0755) || !chmod($entry['destination'], 0644)
                    || hash_file('sha256', $entry['destination']) !== $entry['sha256']) throw new RuntimeException('Published image copy failed verification.');
            }
            // Publish and prove every URL before changing a single database reference.
            $this->verifyHttp($plan);
            $this->writeJson($directory.'/image-repair-pending.json', $record);
            $changed = 0;
            DB::beginTransaction();
            try {
                foreach ($plan as $entry) {
                    $current = DB::table('media')->where('id', $entry['media_id'])->lockForUpdate()->first();
                    if (!$current || (array) $current !== $entry['before']) throw new RuntimeException('A side-image record changed during repair; no references switched.');
                }
                foreach ($plan as $entry) {
                    $needsChange = false;
                    foreach ($entry['changes'] as $field=>$value) if (($entry['before'][$field] ?? null) !== $value) $needsChange = true;
                    if ($needsChange) $changed += DB::table('media')->where('id', $entry['media_id'])->update($entry['changes']);
                }
                DB::commit();
            } catch (Throwable $error) {
                try { if (DB::transactionLevel() > 0) DB::rollBack(); } catch (Throwable $rollbackError) { /* Pending receipt and copies allow inspection after uncertain COMMIT. */ }
                throw $error;
            }
            // New model instances must expose exactly the URLs already proved by HTTP.
            $verified = $this->plan($receipt);
            foreach ($verified as $index=>$entry) if ($entry['before']['disk'] !== 'products' || $entry['url'] !== $plan[$index]['url']) throw new RuntimeException('Canonical image references need review; retained pending receipt and originals.');
            $record['phase'] = 'committed'; $record['http_verified_images'] = 64;
            $this->writeJson($directory.'/image-repair-receipt.json', $record);
            $this->writeJson($backup.'/applied-menu-side-images.json', $record);
            if (is_file($directory.'/image-repair-pending.json')) unlink($directory.'/image-repair-pending.json');
            return ['status'=>'MENU_SIDE_IMAGES_READY','verified'=>true,'http_verified_images'=>64,'changed_media_rows'=>$changed,
                'receipt'=>$directory.'/image-repair-receipt.json','backup'=>$backup]+$summary;
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['check','apply','root:','backup:']);
        if (isset($options['check']) === isset($options['apply'])) throw new RuntimeException('Choose exactly one of --check or --apply.');
        $root = realpath($options['root'] ?? '');
        if (!$root || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('Invalid existing Laravel root.');
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $result = (new FasakhanstaMenuSideImageRepair20261021($root))->run(isset($options['apply']), $options['backup'] ?? null);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'MENU_SIDE_IMAGES_STOPPED: '.$error->getMessage()."\n");
        exit(1);
    }
}
