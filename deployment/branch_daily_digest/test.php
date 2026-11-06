<?php
declare(strict_types=1);

// Pure fixture tests: no Laravel boot, SMTP connection, or database access.
namespace App\Services\Dashboard {
    final class FasakhanstaDeliveryOrigin {
        public const PHYSICAL_BRANCH_IDS = [94, 301, 307, 363];
        public const CAIRO_ORIGINS = [309=>'store', 310=>'store'];
    }
}
namespace {
    require __DIR__.'/report.php';
    use Fasakhansta\DailyDigest\Report;
    $checks = 0;
    function check(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        ++$checks;
    }
    function rejects(callable $operation, string $code): void {
        try { $operation(); } catch (\RuntimeException $error) {
            check($error->getMessage() === $code, 'Unexpected rejection: '.$error->getMessage());
            return;
        }
        throw new \RuntimeException('Expected rejection: '.$code);
    }
    check(Report::quantity(1250000) === '1.25', 'Stock micro-unit precision');
    check(Report::quantity(1) === '0.000001', 'Smallest stock unit');
    check(Report::quantity(-250000) === '-0.25', 'Negative stock visibility');
    check(Report::quantity(0) === '0', 'Zero stock');
    check(Report::money(2765000) === '27,650.00', 'Money total');
    check(Report::money(-1) === '-0.01', 'Negative-cent precision');
    check(Report::money(100000000000001) === '1,000,000,000,000.01', 'Large money precision');
    $failure = Report::safeFailure(new \RuntimeException('Failed to authenticate: username PRIVATE_EMAIL password PRIVATE_PASSWORD; got code "535"'));
    check($failure['code'] === 'DAILY_REPORT_SMTP_AUTH_FAILED' && $failure['smtp_reply'] === '535', 'Authentication failure classification');
    check(strpos(json_encode($failure), 'PRIVATE_') === false, 'Credentials do not enter diagnostics');
    check(Report::safeFailure(new \RuntimeException('Peer certificate CN=local did not match expected CN=external'))['code'] === 'DAILY_REPORT_SMTP_TLS_FAILED', 'TLS redirection failure classification');
    check(Report::safeFailure(new \RuntimeException('Connection could not be established: Connection refused'))['code'] === 'DAILY_REPORT_SMTP_CONNECTION_REFUSED', 'Connection failure classification');
    check(Report::safeFailure(new \RuntimeException('DAILY_REPORT_OWNER_UNAVAILABLE'))['code'] === 'DAILY_REPORT_OWNER_UNAVAILABLE', 'Business errors preserved');
    $row = (object)['id'=>1, 'name'=>'فسيخ <script>alert(1)</script> & رنجة', 'unit'=>'kg'];
    $min = (object)['minimum_units'=>2000000, 'unit'=>'kg'];
    $normal = Report::item('ingredient', $row, 5000000, $min);
    $low = Report::item('ingredient', $row, 2000000, $min);
    $zero = Report::item('ingredient', $row, 0, null);
    $negative = Report::item('ingredient', $row, -1000000, null);
    check($normal['status'] === 'ok', 'Available stock');
    check($low['status'] === 'low', 'Minimum boundary included');
    check($zero['status'] === 'zero', 'Unconfigured zero remains visible');
    check($negative['status'] === 'negative', 'Negative stock remains visible');
    check(Report::item('ingredient', $row, 1000000, (object)['minimum_units'=>2000000, 'unit'=>'piece'])['status'] === 'ok', 'Mismatched threshold unit ignored');
    rejects(static fn()=>Report::item('ingredient', (object)['id'=>1,'name'=>'invalid','unit'=>'litre'], 0, null), 'DAILY_REPORT_UNIT_UNSUPPORTED');
    $data = ['format'=>1, 'date'=>'2026-11-06', 'recipient'=>'report@example.test',
        'captured_at'=>'2026-11-06T00:00:05+02:00', 'total_cents'=>2765000,
        'branches'=>[
            ['key'=>'f:94', 'name'=>'المنصورة', 'drawer_cents'=>965000, 'items'=>[$normal,$low]],
            ['key'=>'f:301', 'name'=>'شبرا الخيمة', 'drawer_cents'=>620000, 'items'=>[$zero]],
            ['key'=>'f:307', 'name'=>'المحلة الكبرى', 'drawer_cents'=>720000, 'items'=>[$negative]],
            ['key'=>'f:363', 'name'=>'نبروه', 'drawer_cents'=>460000, 'items'=>[]],
        ]];
    $html = Report::html($data);
    check(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'Names are escaped');
    check(strpos($html, '27,650.00') !== false, 'Email contains total');
    check(strpos($html, 'رصيد سالب') !== false && strpos($html, 'نفد') !== false, 'Status labels present');
    check(strpos($html, 'لا توجد أصناف بضاعة مسجلة') !== false, 'Empty branch remains present');
    check(strpos($html, 'report@example.test') !== false && strpos($html, '12 منتصف الليل') !== false, 'Recipient and schedule footer');
    check(substr_count($html, 'class="fsk-stock"') === 4, 'All branches render');
    check(strpos(Report::text($data), '27,650.00') !== false, 'Plaintext alternative');
    $bad = $data; $bad['branches'][0]['key'] = 'f:309';
    rejects(static fn()=>Report::html($bad), 'DAILY_REPORT_SNAPSHOT_INVALID');
    $bad = $data; $bad['branches'][0]['key'] = 'gs:94';
    rejects(static fn()=>Report::html($bad), 'DAILY_REPORT_SNAPSHOT_INVALID');
    $bad = $data; $bad['branches'][1]['key'] = 'f:94';
    rejects(static fn()=>Report::html($bad), 'DAILY_REPORT_SNAPSHOT_INVALID');
    $bad = $data; array_pop($bad['branches']);
    rejects(static fn()=>Report::html($bad), 'DAILY_REPORT_BRANCH_MISSING');
    $bad = $data; ++$bad['total_cents'];
    rejects(static fn()=>Report::html($bad), 'DAILY_REPORT_TOTAL_MISMATCH');
    if (isset($argv[1]) && $argv[1] === '--render-fixture') {
        echo $html;
    } else echo 'DAILY_REPORT_SELF_TEST_OK checks='.$checks."\n";
}
