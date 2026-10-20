<?php
/** Image-only publication for the reviewed visible, non-side Fasakhansta menu. */
use App\Models\ResturantProduct;
use Illuminate\Support\Facades\{DB, Schema, Storage};

final class FasakhanstaMenuPhotoRefresh20261021
{
    public const RELEASE = 'menu-photo-refresh-20261021';
    private string $root;
    private string $package;
    private $httpVerifier;
    private $progress;
    private array $mediaColumns = [];
    private array $appUrl = [];
    private string $manifestSha = '';
    private string $updatedAt = '';

    public function __construct(string $root, ?string $package = null, ?callable $httpVerifier = null, ?callable $progress = null)
    {
        $this->root = $root; $this->package = $package ?? __DIR__.'/'.self::RELEASE;
        $this->httpVerifier = $httpVerifier; $this->progress = $progress;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing or unsafe photo manifest/receipt.');
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid photo manifest/receipt.');
        return $value;
    }

    private function writeJson(string $path, array $data): void
    {
        if (is_link($path)) throw new RuntimeException('Unsafe photo receipt destination.');
        $temp = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) throw new RuntimeException('Cannot write private photo receipt.');
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Cannot finalize private photo receipt.'); }
    }

    private function tell(string $stage, int $completed, int $total): void
    {
        if ($this->progress !== null) ($this->progress)($stage, $completed, $total);
    }

    private function ownUrl(string $url): void
    {
        $parts = parse_url($url);
        $port = static fn(array $value)=>(int) ($value['port'] ?? (strtolower($value['scheme'] ?? '') === 'https' ? 443 : 80));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strtolower($parts['scheme']) !== strtolower($this->appUrl['scheme']) || strtolower($parts['host']) !== strtolower($this->appUrl['host'])
            || $port($parts) !== $port($this->appUrl)) throw new RuntimeException('Photo URLs must use the existing APP_URL origin.');
    }

    /** Use the reviewed web URLs to correct only this CLI process's products URL base. */
    private function useReviewedProductsUrl(array $rows): void
    {
        $canonicalBase = null;
        foreach ($rows as $row) {
            $url = $row['before_image_url'] ?? null;
            if (!is_string($url) || preg_match('/[\x00-\x1F\x7F]/', $url)) throw new RuntimeException('A reviewed before-image URL is missing or contains unsafe control characters.');
            $this->ownUrl($url); $parts = parse_url($url);
            if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
                || !preg_match('~\A/storage/products/([1-9][0-9]*)/([^/]+)\z~u', $parts['path'] ?? '', $match)
                || $match[1] !== (string) (int) ($row['expected_media_id'] ?? 0)) throw new RuntimeException('A reviewed before-image URL has an unexpected products path or media ID.');
            $basename = rawurldecode($match[2]);
            if ($basename === '' || $basename === '.' || $basename === '..' || str_contains($basename, '/') || str_contains($basename, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $basename)) throw new RuntimeException('A reviewed before-image URL has an unsafe basename.');
            $scheme = strtolower($parts['scheme']); $defaultPort = $scheme === 'https' ? 443 : 80;
            $port = (int) ($parts['port'] ?? $defaultPort);
            $base = $scheme.'://'.strtolower($parts['host']).($port === $defaultPort ? '' : ':'.$port).'/storage/products';
            if ($canonicalBase !== null && $canonicalBase !== $base) throw new RuntimeException('Reviewed before-image URLs disagree on the products URL base.');
            $canonicalBase = $base;
        }
        if ($canonicalBase === null) throw new RuntimeException('The reviewed products URL base is missing.');
        config(['filesystems.disks.products.url'=>$canonicalBase]);
        Storage::forgetDisk('products');
    }

    private function setup(): array
    {
        foreach (['resturants'=>['id'], 'resturant_products'=>['id','resturant_id','product_id','category_id','product_name','status'],
            'media'=>['id','model_type','model_id','collection_name','disk','file_name','mime_type','size','custom_properties','updated_at']] as $table=>$required) {
            if (!Schema::hasTable($table) || array_diff($required, Schema::getColumnListing($table))) throw new RuntimeException('Required photo runtime schema is missing: '.$table);
        }
        $this->mediaColumns = Schema::getColumnListing('media');
        if (config('filesystems.disks.products.driver') !== 'local' || !is_dir((string) config('filesystems.disks.products.root'))) throw new RuntimeException('The established local products disk is unavailable.');
        $this->appUrl = parse_url((string) config('app.url')) ?: [];
        if (!isset($this->appUrl['scheme'], $this->appUrl['host']) || !in_array(strtolower($this->appUrl['scheme']), ['http','https'], true)
            || isset($this->appUrl['user']) || isset($this->appUrl['pass'])) throw new RuntimeException('APP_URL must be an HTTP origin.');
        $path = $this->package.'/photo-refresh-manifest.json'; $manifest = $this->readJson($path); $this->manifestSha = hash_file('sha256', $path);
        if (($manifest['release'] ?? '') !== self::RELEASE || ($manifest['schema_version'] ?? 0) !== 1
            || count($manifest['branch_ids'] ?? []) !== 32 || count(array_unique($manifest['branch_ids'] ?? [])) !== 32
            || count($manifest['rows'] ?? []) !== 1473 || count($manifest['assets'] ?? []) !== 42
            || (int) ($manifest['excluded']['side_category_id'] ?? 0) !== 7) throw new RuntimeException('The photo manifest does not match the reviewed 32-branch/1473-image/42-asset scope.');
        $this->useReviewedProductsUrl($manifest['rows']);
        $assets = [];
        foreach ($manifest['assets'] as $asset) {
            $key = $asset['key'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) || strlen($key) > 100 || isset($assets[$key])
                || ($asset['asset_path'] ?? '') !== 'assets/'.$key.'.jpg' || !preg_match('/^[0-9a-f]{64}$/D', $asset['sha256'] ?? '')) throw new RuntimeException('An asset identity or hash is invalid.');
            $file = $this->package.'/'.$asset['asset_path']; $image = is_file($file) && !is_link($file) ? @getimagesize($file) : false;
            if (!$image || ($image['mime'] ?? '') !== 'image/jpeg' || hash_file('sha256', $file) !== $asset['sha256']
                || filesize($file) !== (int) ($asset['size'] ?? -1) || $image[0] !== (int) ($asset['width'] ?? 0) || $image[1] !== (int) ($asset['height'] ?? 0)) throw new RuntimeException('A packaged JPEG failed its byte/dimension/hash checks: '.$key);
            $asset['source'] = $file; $assets[$key] = $asset;
        }
        $manifest['assets_by_key'] = $assets;
        return $manifest;
    }

    /** Compare current menu scope, then construct versioned paths through supported Spatie 9.6 APIs. */
    private function plan(array $manifest): array
    {
        $branches = array_map('intval', DB::table('resturants')->orderBy('id')->get()->pluck('id')->all());
        $wantedBranches = array_map('intval', $manifest['branch_ids']); sort($wantedBranches, SORT_NUMERIC);
        if ($branches !== $wantedBranches) throw new RuntimeException('The live branch scope changed since photo review.');
        $all = DB::table('resturant_products')->orderBy('id')->get()->all(); $live = []; $scope = [];
        foreach ($all as $row) { $live[(int) $row->id] = (array) $row; if ($row->status === 'show' && (int) $row->category_id !== 7) $scope[] = (int) $row->id; }
        $expected = array_map('intval', array_column($manifest['rows'], 'id')); sort($expected, SORT_NUMERIC);
        if (count(array_unique($expected)) !== 1473 || $scope !== $expected) throw new RuntimeException('Visible non-side menu scope changed since photo review.');
        $models = [];
        foreach (ResturantProduct::withoutGlobalScopes()->whereIn('id', $expected)->with('media')->get() as $model) $models[(int) $model->id] = $model;
        $plan = []; $ids = []; $states = []; $usedAssets = [];
        foreach ($manifest['rows'] as $entry) {
            $row = $live[(int) $entry['id']] ?? null; $asset = $manifest['assets_by_key'][$entry['asset_key'] ?? ''] ?? null;
            if (!$row || !$asset || !isset($models[(int) $entry['id']])) throw new RuntimeException('A reviewed menu/photo identity is missing.');
            foreach (['resturant_id','product_id','category_id'] as $field) if ((int) $row[$field] !== (int) ($entry[$field] ?? 0)) throw new RuntimeException('A menu identity changed: '.$entry['id']);
            if ($row['product_name'] !== ($entry['product_name'] ?? null) || $row['status'] !== 'show' || (int) $row['category_id'] === 7) throw new RuntimeException('A reviewed menu name or visibility changed.');
            $model = $models[(int) $entry['id']]; $media = $model->getMedia('product_image')->first();
            if (!$media || (int) $media->id !== (int) ($entry['expected_media_id'] ?? 0) || isset($ids[(int) $media->id])
                || $media->model_type !== $model->getMorphClass() || (int) $media->model_id !== (int) $row['id'] || $media->collection_name !== 'product_image') throw new RuntimeException('A first menu-image identity changed: '.$entry['id']);
            $before = (array) DB::table('media')->where('id', $media->id)->first();
            $source = $media->getPath();
            if (!is_file($source) || is_link($source)) throw new RuntimeException('An existing original photo is missing; retained restoration requires it: '.$media->id);
            $properties = json_decode((string) ($before['custom_properties'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($properties)) throw new RuntimeException('Invalid existing media custom properties.');
            $marker = $properties['menu_photo_refresh'] ?? null;
            $wantedMarker = ['release'=>self::RELEASE,'key'=>$asset['key'],'sha256'=>$asset['sha256']];
            $fileName = 'menu-20261021-'.$asset['key'].'.jpg';
            $already = is_array($marker) && count($marker) === 3 && ($marker['release'] ?? '') === self::RELEASE && ($marker['key'] ?? '') === $asset['key'] && ($marker['sha256'] ?? '') === $asset['sha256'];
            if ($marker !== null && !$already) throw new RuntimeException('An existing photo refresh marker conflicts with this release.');
            if ($already && ($media->disk !== 'products' || $media->file_name !== $fileName || hash_file('sha256', $source) !== $asset['sha256'])) throw new RuntimeException('An installed photo changed after publication.');
            if (!$already && $media->file_name === $fileName) throw new RuntimeException('An untracked versioned image already owns this filename.');
            $properties['menu_photo_refresh'] = $wantedMarker;
            $changes = ['disk'=>'products','file_name'=>$fileName,'mime_type'=>'image/jpeg','size'=>(int) $asset['size'],
                'custom_properties'=>json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),'updated_at'=>$already ? $before['updated_at'] : $this->updatedAt];
            foreach (['conversions_disk'=>'products','generated_conversions'=>'{}','responsive_images'=>'{}'] as $field=>$value) if (in_array($field, $this->mediaColumns, true)) $changes[$field] = $value;
            if ($already) {
                foreach (['conversions_disk','mime_type','size'] as $field) if (array_key_exists($field, $changes) && (string) ($before[$field] ?? '') !== (string) $changes[$field]) throw new RuntimeException('Installed photo metadata changed after publication.');
                foreach (['generated_conversions','responsive_images'] as $field) if (array_key_exists($field, $changes) && json_decode((string) $before[$field], true, 512, JSON_THROW_ON_ERROR) !== []) throw new RuntimeException('Installed photo derivatives changed after publication.');
            }
            $target = clone $media;
            foreach ($changes as $field=>$value) $target->{$field} = $field === 'custom_properties' ? $properties : (in_array($field, ['generated_conversions','responsive_images'], true) ? [] : $value);
            $destination = $target->getPath(); $root = rtrim((string) config('filesystems.disks.products.root'), '/');
            if (!str_starts_with($destination, $root.'/')) throw new RuntimeException('Unexpected products disk image path.');
            $relative = substr($destination, strlen($root) + 1);
            if (dirname($relative) !== (string) $media->id || basename($relative) !== $fileName || str_contains($relative, '\\') || str_contains($relative, "\0")) throw new RuntimeException('Only dedicated media-ID directories may receive new photos.');
            $url = $target->getUrl(); $this->ownUrl($url);
            if (is_link($destination) || is_link(dirname($destination)) || (file_exists($destination) && (!is_file($destination) || hash_file('sha256', $destination) !== $asset['sha256']))) throw new RuntimeException('An existing versioned image conflicts with the approved asset.');
            $plan[] = ['menu_id'=>(int) $row['id'],'media_id'=>(int) $media->id,'asset_key'=>$asset['key'],'family'=>$asset['family'] ?? '',
                'old_source'=>$source,'asset_source'=>$asset['source'],'destination'=>$destination,'relative'=>$relative,'url'=>$url,'sha256'=>$asset['sha256'],'size'=>(int) $asset['size'],
                'before_menu'=>$row,'before_media'=>$before,'changes'=>$changes,'already'=>$already];
            $ids[(int) $media->id] = true; $states[$already ? 'new' : 'old'] = true; $usedAssets[$asset['key']] = true;
        }
        if (count($states) !== 1 || count($usedAssets) !== 42) throw new RuntimeException('The photo release has mixed image states or incomplete asset coverage; review its pending receipt.');
        usort($plan, static fn($a,$b)=>$a['media_id'] <=> $b['media_id']);
        return $plan;
    }

    private function verifyHttp(array $targets): void
    {
        if ($this->httpVerifier !== null) { ($this->httpVerifier)($targets); return; }
        if (!function_exists('curl_multi_init')) throw new RuntimeException('PHP cURL is required to prove the published images.');
        $completed = 0; $lastReport = 0;
        foreach (array_chunk($targets, 12) as $chunk) {
            $multi = curl_multi_init(); $handles = []; $bodies = [];
            try {
                foreach ($chunk as $index=>$target) {
                    $this->ownUrl($target['url']); $bodies[$index] = ''; $handle = curl_init($target['url']);
                    curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_USERAGENT=>'Fasakhansta-Menu-Photo-Verification/1',CURLOPT_WRITEFUNCTION=>static function ($curl,string $bytes) use (&$bodies,$index): int {
                            if (strlen($bodies[$index]) + strlen($bytes) > 4194304) return 0; $bodies[$index] .= $bytes; return strlen($bytes);
                        }]);
                    curl_multi_add_handle($multi,$handle); $handles[$index] = $handle;
                }
                do { $status = curl_multi_exec($multi,$running); if ($running) curl_multi_select($multi,0.2); } while ($running && $status === CURLM_OK);
                foreach ($handles as $index=>$handle) {
                    $httpStatus = (int) curl_getinfo($handle,CURLINFO_HTTP_CODE); $curlError = curl_errno($handle);
                    if ($status !== CURLM_OK || $curlError || $httpStatus !== 200 || hash('sha256',$bodies[$index]) !== $chunk[$index]['sha256']
                        || @getimagesizefromstring($bodies[$index]) === false) throw new RuntimeException('HTTP photo verification failed: media '.$chunk[$index]['media_id']
                            .' (http_status='.$httpStatus.', curl_errno='.$curlError.', multi_status='.$status.'). No image references were changed.');
                }
            } finally { foreach ($handles as $handle) { curl_multi_remove_handle($multi,$handle); curl_close($handle); } curl_multi_close($multi); }
            $completed += count($chunk);
            if ($completed - $lastReport >= 100 || $completed === count($targets)) { $this->tell('http_verified',$completed,count($targets)); $lastReport = $completed; }
        }
    }

    public function run(bool $apply, ?string $backup): array
    {
        $this->updatedAt = date('Y-m-d H:i:s'); $manifest = $this->setup(); $directory = $this->root.'/storage/app/'.self::RELEASE;
        if (is_link($directory)) throw new RuntimeException('Unsafe private photo-state directory.');
        if (is_file($directory.'/before.json') && ($this->readJson($directory.'/before.json')['manifest_sha256'] ?? '') !== $this->manifestSha) throw new RuntimeException('This release already has a different manifest in its private backup.');
        $lock = null;
        if ($apply) {
            if (!$backup || !is_dir($backup) || is_link($backup) || !is_writable($backup)) throw new RuntimeException('Pass an existing writable private photo backup directory.');
            if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Cannot create private photo-state directory.');
            if (is_link($directory.'/install.lock')) throw new RuntimeException('Unsafe photo lock.');
            $lock = fopen($directory.'/install.lock','c'); if (!$lock || !flock($lock,LOCK_EX | LOCK_NB)) throw new RuntimeException('Another menu-photo release is active.');
        }
        try {
            $plan = $this->plan($manifest); $needed = 0; $samples = [];
            foreach ($plan as $entry) { if (!is_file($entry['destination'])) $needed += $entry['size']; if (!isset($samples[$entry['family']])) $samples[$entry['family']] = $entry['url']; }
            $free = disk_free_space((string) config('filesystems.disks.products.root'));
            if ($free === false || $free < $needed + 67108864) throw new RuntimeException('Insufficient free disk space for versioned menu photos and a 64 MiB reserve.');
            $summary = ['release'=>self::RELEASE,'branch_count'=>32,'image_count'=>1473,'asset_count'=>42,'already_applied'=>$plan[0]['already'],
                'planned_new_bytes'=>$needed,'free_bytes'=>(int) $free,'sample_urls'=>$samples];
            if (!$apply) return ['status'=>'MENU_PHOTOS_CHECK_READY','read_only'=>true]+$summary;
            $record = ['release'=>self::RELEASE,'phase'=>'prepared','recorded_at'=>gmdate('c'),'manifest_sha256'=>$this->manifestSha,'images'=>$plan];
            if (!is_file($directory.'/before.json')) $this->writeJson($directory.'/before.json',$record);
            $this->writeJson($backup.'/before-menu-photos.json',$this->readJson($directory.'/before.json'));
            $copied = 0;
            foreach ($plan as $entry) {
                if (!is_file($entry['destination']) && !Storage::disk('products')->put($entry['relative'],file_get_contents($entry['asset_source']))) throw new RuntimeException('Cannot publish a versioned menu photo.');
                if (!Storage::disk('products')->setVisibility($entry['relative'],'public') || !chmod(dirname($entry['destination']),0755) || !chmod($entry['destination'],0644)
                    || hash_file('sha256',$entry['destination']) !== $entry['sha256']) throw new RuntimeException('A copied menu photo failed publication checks.');
                $copied++; if ($copied % 100 === 0 || $copied === 1473) $this->tell('published',$copied,1473);
            }
            $this->verifyHttp($plan); $this->writeJson($directory.'/pending.json',$record); $changed = 0;
            DB::beginTransaction();
            try {
                $menuIds = array_column($plan,'menu_id'); sort($menuIds,SORT_NUMERIC);
                $menus = []; foreach (DB::table('resturant_products')->whereIn('id',$menuIds)->orderBy('id')->lockForUpdate()->get() as $row) $menus[(int) $row->id] = (array) $row;
                $medias = []; foreach (DB::table('media')->whereIn('id',array_column($plan,'media_id'))->orderBy('id')->lockForUpdate()->get() as $row) $medias[(int) $row->id] = (array) $row;
                foreach ($plan as $entry) if (($menus[$entry['menu_id']] ?? null) !== $entry['before_menu'] || ($medias[$entry['media_id']] ?? null) !== $entry['before_media']) throw new RuntimeException('A menu or media record changed during publication; no references switched.');
                foreach ($plan as $entry) if (!$entry['already']) $changed += DB::table('media')->where('id',$entry['media_id'])->update($entry['changes']);
                DB::commit();
            } catch (Throwable $error) { try { if (DB::transactionLevel() > 0) DB::rollBack(); } catch (Throwable $rollbackError) {} throw $error; }
            $verified = $this->plan($manifest);
            foreach ($verified as $index=>$entry) if (!$entry['already'] || $entry['url'] !== $plan[$index]['url']) throw new RuntimeException('Canonical photo URLs need review; original files and pending receipt were retained.');
            $record['phase'] = 'committed'; $record['http_verified_images'] = 1473;
            $this->writeJson($directory.'/receipt.json',$record); $this->writeJson($backup.'/applied-menu-photos.json',$record);
            if (is_file($directory.'/pending.json')) unlink($directory.'/pending.json');
            return ['status'=>'MENU_PHOTOS_READY','verified'=>true,'http_verified_images'=>1473,'changed_media_rows'=>$changed,'receipt'=>$directory.'/receipt.json','backup'=>$backup]+$summary;
        } finally { if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); } }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('',['check','apply','root:','backup:','package:']);
        if (isset($options['check']) === isset($options['apply'])) throw new RuntimeException('Choose exactly one of --check or --apply.');
        $root = realpath($options['root'] ?? '');
        if (!$root || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('Invalid existing Laravel root.');
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $progress = static function (string $stage,int $done,int $total): void { echo 'MENU_PHOTOS_PROGRESS '.$stage.' '.$done.'/'.$total."\n"; if (function_exists('flush')) flush(); };
        $runner = new FasakhanstaMenuPhotoRefresh20261021($root,$options['package'] ?? null,null,$progress);
        echo json_encode($runner->run(isset($options['apply']),$options['backup'] ?? null),JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) { fwrite(STDERR,'MENU_PHOTOS_STOPPED: '.$error->getMessage()."\n"); exit(1); }
}
