<?php
declare(strict_types=1);

/* Standalone central-server report. No application source, balances, or shifts are modified. */
namespace Fasakhansta\DailyDigest;

use App\Models\User;
use App\Services\Dashboard\BranchShiftClosing;
use App\Services\Dashboard\FasakhanstaDeliveryOrigin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

final class Report
{
    public const APPLICATION = '/home/fasakha/public_html';
    public const TIMEZONE = 'Africa/Cairo';
    public const STATE = '/home/fasakha/.local/state/fasakhansta-daily-report';

    public static function escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Integer arithmetic retains every stock micro-unit and every currency cent. */
    public static function quantity(int $units): string
    {
        $n = abs($units);
        $value = intdiv($n, 1000000).'.'.str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
        return ($units < 0 ? '-' : '').rtrim(rtrim($value, '0'), '.');
    }

    public static function money(int $cents): string
    {
        $n = abs($cents);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string)intdiv($n, 100));
        return ($cents < 0 ? '-' : '').$whole.'.'.str_pad((string)($n % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function item(string $type, object $row, int $units, ?object $minimum): array
    {
        $unit = (string)$row->unit;
        if (!in_array($unit, ['kg', 'piece'], true)) throw new \RuntimeException('DAILY_REPORT_UNIT_UNSUPPORTED');
        $enabled = $minimum && $minimum->minimum_units !== null && $minimum->unit === $unit;
        $low = $enabled && $units <= (int)$minimum->minimum_units;
        $status = $units < 0 ? 'negative' : ($units === 0 ? 'zero' : ($low ? 'low' : 'ok'));
        return [
            'id'=>(int)$row->id, 'type'=>$type, 'name'=>(string)$row->name,
            'quantity'=>self::quantity($units), 'unit'=>$unit, 'unit_label'=>$unit === 'kg' ? 'كجم' : 'قطعة',
            'status'=>$status, 'status_label'=>['negative'=>'رصيد سالب', 'zero'=>'نفد', 'low'=>'منخفض', 'ok'=>'متاح'][$status],
            'minimum'=>$enabled ? self::quantity((int)$minimum->minimum_units) : null,
        ];
    }

    public static function config(string $path): array
    {
        if (is_link($path) || !is_file($path)) throw new \RuntimeException('DAILY_REPORT_CONFIG_MISSING');
        $config = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($config['format'] ?? null) !== 1 || !is_string($config['recipient'] ?? null)
            || !filter_var($config['recipient'], FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $config['recipient'])) throw new \RuntimeException('DAILY_REPORT_CONFIG_INVALID');
        return $config;
    }

    public static function bootstrap(): void
    {
        $root = self::APPLICATION;
        if (!is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) {
            throw new \RuntimeException('DAILY_REPORT_APPLICATION_MISSING');
        }
        chdir($root);
        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($app->bound('owner.dashboard.runtime') || config('desktop_dashboard.local', false)) {
            throw new \RuntimeException('DAILY_REPORT_REQUIRES_PRIMARY_SERVER');
        }
    }

    public static function physicalIds(): array
    {
        if (!class_exists(FasakhanstaDeliveryOrigin::class)
            || !defined(FasakhanstaDeliveryOrigin::class.'::PHYSICAL_BRANCH_IDS')) {
            throw new \RuntimeException('DAILY_REPORT_BRANCH_REGISTRY_MISSING');
        }
        $ids = FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS;
        if (!$ids || array_filter($ids, static fn($id)=>!is_int($id) || $id < 1)
            || count(array_unique($ids)) !== count($ids)
            || array_intersect($ids, array_keys(FasakhanstaDeliveryOrigin::CAIRO_ORIGINS))) {
            throw new \RuntimeException('DAILY_REPORT_BRANCH_REGISTRY_INVALID');
        }
        return $ids;
    }

    public static function mailer()
    {
        $name = config('mail.default', 'smtp');
        $settings = config('mail.mailers.'.$name);
        if (!is_array($settings) || !in_array($settings['transport'] ?? $name, ['smtp', 'sendmail', 'ses', 'mailgun', 'postmark'], true)
            || !filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('DAILY_REPORT_MAIL_NOT_CONFIGURED');
        }
        if (($settings['transport'] ?? $name) === 'smtp') {
            $settings['timeout'] = 20;
            $settings['stream']['ssl'] = array_replace($settings['stream']['ssl'] ?? [], [
                'verify_peer'=>true, 'verify_peer_name'=>true, 'allow_self_signed'=>false,
            ]);
        }
        // A separate mailer avoids any previously configured always-to recipient.
        config(['mail.mailers.fasakhansta_daily_digest'=>$settings]);
        return Mail::mailer('fasakhansta_daily_digest');
    }

    /** A single read-only repeatable-read snapshot covers all four branches and drawers. */
    public static function snapshot(string $recipient): array
    {
        $required = [
            'resturants'=>['id', 'name'], 'users'=>['id', 'account_type'],
            'takeaway_tills'=>['branch', 'balance_cents'],
            'stock_ingredients'=>['id', 'name', 'unit', 'position'],
            'branch_inventory'=>['branch', 'ingredient_id', 'quantity_units'],
        ];
        foreach ($required as $table=>$columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) {
                throw new \RuntimeException('DAILY_REPORT_SCHEMA_MISSING');
            }
        }
        if (!Schema::hasTable('branch_shift_sources') || !method_exists(BranchShiftClosing::class, 'ownerBalances')) {
            throw new \RuntimeException('DAILY_REPORT_DRAWER_SERVICE_MISSING');
        }
        $minimumsReady = Schema::hasTable('branch_stock_minimums');
        $directReady = Schema::hasTable('branch_stock') && Schema::hasTable('resturant_products');
        $ids = self::physicalIds();
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql' || $db->transactionLevel() !== 0) {
            throw new \RuntimeException('DAILY_REPORT_DATABASE_UNSUPPORTED');
        }
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            $restaurants = DB::table('resturants')->whereIn('id', $ids)->orderBy('id')->get();
            if ($restaurants->count() !== count($ids)) throw new \RuntimeException('DAILY_REPORT_BRANCH_MISSING');
            $captured = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
            $owner = User::withoutGlobalScopes()->find(1);
            if (!$owner || $owner->account_type !== 'admin' || !empty($owner->owner_resturant_id)
                || (isset($owner->status) && $owner->status !== 'accepted')) {
                throw new \RuntimeException('DAILY_REPORT_OWNER_UNAVAILABLE');
            }
            $keys = $restaurants->map(static fn($r)=>'f:'.$r->id)->all();
            // Use the same live expected-cash calculation as the owner's dashboard.
            // This includes settled Shubra fulfillment cash already belonging to that branch.
            $drawers = app(BranchShiftClosing::class)->ownerBalances($keys, $owner);
            $ingredients = DB::table('stock_ingredients')->orderBy('position')->orderBy('id')->get();
            $inventory = DB::table('branch_inventory')->whereIn('branch', $keys)->get()->groupBy('branch');
            $thresholds = $minimumsReady ? DB::table('branch_stock_minimums')->whereIn('branch', $keys)->get()->groupBy('branch') : collect();
            $branches = [];
            $totalCents = 0;
            foreach ($restaurants as $restaurant) {
                $key = 'f:'.$restaurant->id;
                if (!isset($drawers[$key]['expected_cents'])) throw new \RuntimeException('DAILY_REPORT_DRAWER_MISSING');
                $cents = (int)$drawers[$key]['expected_cents'];
                $balances = ($inventory[$key] ?? collect())->keyBy('ingredient_id');
                $minimums = ($thresholds[$key] ?? collect())->keyBy(static fn($r)=>$r->stock_type.':'.$r->stock_item_id);
                $items = [];
                foreach ($ingredients as $ingredient) {
                    $items[] = self::item('ingredient', $ingredient,
                        (int)($balances[$ingredient->id]->quantity_units ?? 0), $minimums['ingredient:'.$ingredient->id] ?? null);
                }
                // Read real direct SKU records only, never recipe-derived menu availability.
                if ($directReady) {
                    $direct = DB::table('branch_stock')->leftJoin('resturant_products', static function($join) use ($restaurant) {
                        $join->on('resturant_products.id', '=', 'branch_stock.product_id')
                            ->where('resturant_products.resturant_id', '=', $restaurant->id);
                    })->where('branch_stock.branch', $key)->orderBy('branch_stock.product_id')
                        ->get(['branch_stock.product_id as id', 'branch_stock.unit', 'branch_stock.quantity_units', 'resturant_products.product_name as name']);
                    foreach ($direct as $product) {
                        $product->name = $product->name ?: 'صنف سابق #'.$product->id;
                        $items[] = self::item('product', $product, (int)$product->quantity_units, $minimums['product:'.$product->id] ?? null);
                    }
                }
                $branches[] = ['key'=>$key, 'name'=>(string)$restaurant->name, 'drawer_cents'=>$cents, 'items'=>$items];
                $totalCents += $cents;
            }
            $db->commit();
            return ['format'=>1, 'recipient'=>$recipient, 'date'=>$captured->format('Y-m-d'),
                'captured_at'=>$captured->format(\DateTimeInterface::ATOM), 'total_cents'=>$totalCents, 'branches'=>$branches];
        } catch (\Throwable $error) {
            if ($db->transactionLevel() > 0) $db->rollBack();
            throw $error;
        }
    }

    public static function html(array $data): string
    {
        self::validateSnapshot($data);
        ob_start();
        try { require __DIR__.'/template.php'; return (string)ob_get_clean(); }
        catch (\Throwable $error) { ob_end_clean(); throw $error; }
    }

    public static function text(array $data): string
    {
        self::validateSnapshot($data);
        $lines = ['فسخانستا | تقرير الفروع اليومي', 'وقت الأرصدة: '.$data['captured_at'].' (القاهرة)',
            'إجمالي أرصدة الأدراج: '.self::money($data['total_cents']).' جنيه مصري'];
        foreach ($data['branches'] as $branch) {
            $lines[] = '';
            $lines[] = $branch['name'].' | رصيد الدرج: '.self::money($branch['drawer_cents']).' ج.م';
            foreach ($branch['items'] as $item) {
                $lines[] = $item['name'].' ['.($item['type'] === 'ingredient' ? 'بضاعة' : 'رصيد صنف مستقل').']: '
                    .$item['quantity'].' '.$item['unit_label'].' | '.$item['status_label'];
            }
            if (!$branch['items']) $lines[] = 'لا توجد أصناف بضاعة مسجلة.';
        }
        $lines[] = '';
        $lines[] = 'الفروع فقط؛ الإستورز مستبعدة. إرسال يومي 12 منتصف الليل بتوقيت مصر.';
        return implode("\n", $lines);
    }

    public static function validateSnapshot(array $data): void
    {
        if (($data['format'] ?? null) !== 1 || !filter_var($data['recipient'] ?? '', FILTER_VALIDATE_EMAIL)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['date'] ?? '') || empty($data['branches'])
            || !is_int($data['total_cents'] ?? null) || !is_string($data['captured_at'] ?? null)) {
            throw new \RuntimeException('DAILY_REPORT_SNAPSHOT_INVALID');
        }
        $total = 0;
        $keys = [];
        $physical = array_fill_keys(array_map(static fn($id)=>'f:'.$id, self::physicalIds()), true);
        foreach ($data['branches'] as $branch) {
            if (!isset($physical[$branch['key'] ?? '']) || isset($keys[$branch['key']])
                || !is_int($branch['drawer_cents'] ?? null) || !is_array($branch['items'] ?? null)) {
                throw new \RuntimeException('DAILY_REPORT_SNAPSHOT_INVALID');
            }
            $keys[$branch['key']] = true;
            $total += $branch['drawer_cents'];
        }
        if (count($keys) !== count($physical)) throw new \RuntimeException('DAILY_REPORT_BRANCH_MISSING');
        if ($total !== $data['total_cents']) throw new \RuntimeException('DAILY_REPORT_TOTAL_MISMATCH');
    }

    public static function writeJson(string $path, array $data): void
    {
        if (is_link($path)) throw new \RuntimeException('DAILY_REPORT_STATE_INVALID');
        $temp = tempnam(dirname($path), '.report-');
        if ($temp === false) throw new \RuntimeException('DAILY_REPORT_STATE_WRITE_FAILED');
        try {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            if (!chmod($temp, 0600) || file_put_contents($temp, $encoded) !== strlen($encoded) || !rename($temp, $path)) {
                throw new \RuntimeException('DAILY_REPORT_STATE_WRITE_FAILED');
            }
        } finally { if (is_file($temp)) unlink($temp); }
    }

    /** Only allowlisted diagnostics leave the process, never raw server replies or credentials. */
    public static function safeFailure(\Throwable $error): array
    {
        $message = $error->getMessage();
        $code = preg_match('/^DAILY_REPORT_[A-Z_]+$/D', $message) ? $message : 'DAILY_REPORT_FAILED';
        $reply = null;
        if (preg_match('/(?:got code|code|response|message)[\s:="\x27]*(4[0-9]{2}|5[0-9]{2})\b/i', $message, $match)) $reply = $match[1];
        if (preg_match('/certificate|peer name|peer certificate|SSL operation|crypto|TLS/i', $message)) $code = 'DAILY_REPORT_SMTP_TLS_FAILED';
        elseif (preg_match('/535|534|authenticate|authentication/i', $message)) $code = 'DAILY_REPORT_SMTP_AUTH_FAILED';
        elseif (preg_match('/sender.*(?:verif|reject|authoriz)|from address.*(?:verif|reject)/i', $message)) $code = 'DAILY_REPORT_SMTP_SENDER_REJECTED';
        elseif (preg_match('/quota|credit|rate.?limit|daily.*limit|maximum.*message/i', $message)) $code = 'DAILY_REPORT_SMTP_QUOTA_LIMIT';
        elseif (preg_match('/timed? out|timeout/i', $message)) $code = 'DAILY_REPORT_SMTP_TIMEOUT';
        elseif (preg_match('/connection refused/i', $message)) $code = 'DAILY_REPORT_SMTP_CONNECTION_REFUSED';
        elseif (preg_match('/permission denied|operation not permitted/i', $message)) $code = 'DAILY_REPORT_SMTP_PERMISSION_DENIED';
        elseif (preg_match('/could not resolve|name or service not known|php_network_getaddresses/i', $message)) $code = 'DAILY_REPORT_SMTP_DNS_FAILED';
        elseif ($error instanceof \Swift_TransportException) $code = 'DAILY_REPORT_SMTP_TRANSPORT_FAILED';
        return ['code'=>$code, 'smtp_reply'=>$reply];
    }

    public static function send(array $config): void
    {
        $state = self::STATE;
        if (is_link($state) || !is_dir($state) || !is_writable($state)) throw new \RuntimeException('DAILY_REPORT_STATE_MISSING');
        $lockPath = $state.'/run.lock';
        if (is_link($lockPath)) throw new \RuntimeException('DAILY_REPORT_STATE_INVALID');
        $lock = fopen($lockPath, 'c');
        if (!$lock) throw new \RuntimeException('DAILY_REPORT_STATE_WRITE_FAILED');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); echo "DAILY_REPORT_ALREADY_RUNNING\n"; return; }
        try {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
            $pending = glob($state.'/*.pending.json') ?: [];
            $newPath = $state.'/'.$today.'.pending.json';
            if (!is_file($state.'/'.$today.'.sent.json') && !is_file($newPath)) {
                self::writeJson($newPath, self::snapshot($config['recipient']));
                $pending[] = $newPath;
            }
            sort($pending, SORT_STRING);
            $mailer = self::mailer();
            $sent = 0;
            foreach (array_unique($pending) as $path) {
                if (is_link($path)) throw new \RuntimeException('DAILY_REPORT_STATE_INVALID');
                $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                self::validateSnapshot($data);
                if ($data['recipient'] !== $config['recipient'] || basename($path) !== $data['date'].'.pending.json') {
                    throw new \RuntimeException('DAILY_REPORT_SNAPSHOT_INVALID');
                }
                $receipt = $state.'/'.$data['date'].'.sent.json';
                if (is_file($receipt)) { unlink($path); continue; }
                $html = self::html($data);
                $text = self::text($data);
                $subject = 'فسخانستا | تقرير مخزون وأرصدة الفروع | '.$data['date'];
                $mailer->html($html, static function($message) use ($data, $subject, $text) {
                    $message->from(config('mail.from.address'), 'فسخانستا | Fasakhansta')
                        ->to($data['recipient'])->subject($subject);
                    $message->addPart($text, 'text/plain', 'utf-8');
                    $message->getSwiftMessage()->setId('fasakhansta-daily-'.str_replace('-', '', $data['date']).'@'.substr(strrchr(config('mail.from.address'), '@'), 1));
                });
                if (method_exists($mailer, 'failures') && $mailer->failures()) throw new \RuntimeException('DAILY_REPORT_MAIL_REJECTED');
                self::writeJson($receipt, ['format'=>1, 'date'=>$data['date'], 'recipient'=>$config['recipient'],
                    'sent_at'=>(new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format(\DateTimeInterface::ATOM),
                    'snapshot_sha256'=>hash('sha256', (string)file_get_contents($path))]);
                unlink($path);
                ++$sent;
                echo 'DAILY_REPORT_SENT date='.$data['date'].' branches='.count($data['branches'])."\n";
            }
            if ($sent === 0) echo "DAILY_REPORT_ALREADY_SENT\n";
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function main(array $args): int
    {
        umask(0077);
        try {
            if (count($args) !== 3 || !in_array($args[1], ['--check', '--send'], true)) {
                throw new \RuntimeException('DAILY_REPORT_ARGUMENTS_INVALID');
            }
            $config = self::config($args[2]);
            self::bootstrap();
            if ($args[1] === '--check') {
                self::mailer();
                $data = self::snapshot($config['recipient']);
                self::html($data);
                echo 'DAILY_REPORT_CHECK_OK branches='.count($data['branches']).' recipient='.$config['recipient']." no_mail_sent=1\n";
            } else self::send($config);
            return 0;
        } catch (\Throwable $error) {
            $failure = self::safeFailure($error);
            // Never print transport credentials, SQL values, or financial/customer data to the journal.
            fwrite(STDERR, $failure['code'].' error_class='.get_class($error)
                .($failure['smtp_reply'] ? ' smtp_reply='.$failure['smtp_reply'] : '')."\n");
            return 1;
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(Report::main($argv));
}
