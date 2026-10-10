<?php
// Read-only inventory. --test-ai makes one paid extraction using fictional data only.
ini_set('display_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || !in_array($argc, [1, 2], true)
        || ($argc === 2 && $argv[1] !== '--test-ai')) {
        throw new \RuntimeException();
    }
    if (!chdir('/home/fasakha/public_html')) throw new \RuntimeException();
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled = static fn ($v) => in_array($v, [true, 1, '1'], true);
    $configured = static fn ($v) => is_string($v) && trim($v) !== '';
    $safeReason = static fn ($v) => $v === null ? null
        : (is_string($v) && preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/', $v) ? $v : 'UNKNOWN');
    $mode = config('whatsapp_orders.mode');
    $report = [
        'diagnostic' => $argc === 2 ? 'READ_ONLY_WITH_SYNTHETIC_AI_CALL' : 'READ_ONLY',
        'orders_enabled' => $enabled(config('whatsapp_orders.enabled')),
        'luna_configured' => config('whatsapp_orders.model') === 'gpt-6-luna',
        'mode' => in_array($mode, ['review', 'auto'], true) ? $mode : 'UNKNOWN',
        'api_key_configured' => $configured(config('whatsapp_orders.api_key')),
        'voice_available' => app(\App\Services\Dashboard\WhatsAppVoiceMedia::class)->available(),
        'manual_replies_enabled' => $enabled(config('whatsapp_replies.enabled')),
        'whatsapp_token_configured' => $configured(config('whatsapp_replies.access_token')),
        'eligible_actor_ids' => [], 'candidate_limit_reached' => false,
        'actor_checks_failed' => 0, 'branches_for_actor_id' => null,
        'branches' => [], 'branches_truncated' => false,
    ];
    $access = app(\App\Services\Dashboard\TakeawayAccess::class);
    $inbox = app(\App\Services\Dashboard\WhatsAppInboxAccess::class);
    $candidates = \App\Models\User::withoutGlobalScopes()->where('account_type', 'admin')
        ->where(function ($q) { $q->whereNull('owner_resturant_id')->orWhere('owner_resturant_id', 0); })
        ->orderBy('id')->limit(100)->get(['id']);
    $report['candidate_limit_reached'] = count($candidates) === 100;
    $first = null;
    foreach ($candidates as $candidate) {
        try {
            $actor = $inbox->actor($candidate);
            if (($access->permissions($actor)['can_checkout'] ?? false) !== true) continue;
            $report['eligible_actor_ids'][] = (int) $actor->id;
            $first ??= $actor;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if (!in_array($e->getStatusCode(), [403, 404], true)) $report['actor_checks_failed']++;
        } catch (\Throwable $e) {
            $report['actor_checks_failed']++;
        }
    }
    if ($first !== null) {
        $report['branches_for_actor_id'] = (int) $first->id;
        $branches = array_values(array_filter($access->branches($first),
            static fn ($b) => ($b['kind'] ?? null) === 'f'));
        $report['branches_truncated'] = count($branches) > 100;
        foreach (array_slice($branches, 0, 100) as $branch) {
            $ready = null;
            try {
                $ready = (app(\App\Services\Dashboard\PhoneDelivery::class)
                    ->settings($branch['value'], $first)['ready'] ?? false) === true;
            } catch (\Throwable $e) {}
            $report['branches'][] = ['id' => (int) $branch['id'],
                'name' => (string) $branch['name'], 'delivery_settings_ready' => $ready];
        }
    }
    if ($argc === 2) {
        // Names, number and address below are fictional fixtures, never a live customer.
        $rows = [];
        $texts = [
            ['customer', 'اسمي محمود سامي، رقم هاتفي 201000000001، العنوان شارع النخيل، المنطقة المنصورة. عايز توصيل من فرع المنصورة: ربع كيلو فسيخ؛ نصف كيلو رنجة.'],
            ['business', 'تم تأكيد الطلب: العميل محمود سامي، رقم الهاتف 201000000001، العنوان شارع النخيل، المنطقة المنصورة، فرع المنصورة. الطلب ربع كيلو فسيخ؛ نصف كيلو رنجة. توصيل. الإجمالي التقريبي 350 جنيه.'],
            ['customer', 'تمام موافق على الطلب المذكور.'],
        ];
        foreach ($texts as $i => $text) $rows[] = ['id' => 'm' . ($i + 1),
            'speaker' => $text[0], 'sent_at' => gmdate('Y-m-d H:i:s', time() - 3 + $i),
            'text' => $text[1], 'location' => null];
        $validation = \App\Support\WhatsAppOrderExtraction::validateTranscript($rows);
        if (!$validation['ok'] || \App\Support\WhatsAppOrderExtraction::hasProbe($rows)) {
            throw new \RuntimeException();
        }
        $result = app(\App\Services\Dashboard\WhatsAppOrderAiProvider::class)->extract($rows);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $weights = ['quarter_feseekh' => false, 'half_renga' => false];
        foreach (($data['items'] ?? []) as $item) {
            if (($item['quantity_mode'] ?? null) !== 'weight') continue;
            if (($item['name'] ?? null) === 'فسيخ') $weights['quarter_feseekh'] =
                in_array($item['quantity'] ?? null, ['0.25', '0.250'], true);
            if (($item['name'] ?? null) === 'رنجة') $weights['half_renga'] =
                in_array($item['quantity'] ?? null, ['0.5', '0.50', '0.500'], true);
        }
        $decision = $data['decision'] ?? null;
        $report['synthetic_extraction'] = [
            'ok' => ($result['ok'] ?? false) === true,
            'reason' => $safeReason(array_key_exists('reason', $result) ? $result['reason'] : 'UNKNOWN'),
            'decision' => in_array($decision, ['NONE', 'DRAFT', 'CONFIRMED', 'CANCELLED'], true) ? $decision : null,
            'customer_name_match' => ($customer['name'] ?? null) === 'محمود سامي',
            'phone_match' => ltrim((string) ($customer['phone'] ?? ''), '+') === '201000000001',
            'address_match' => ($customer['address'] ?? null) === 'شارع النخيل',
            'area_match' => ($customer['area'] ?? null) === 'المنصورة',
            'branch_match' => ($data['branch_hint'] ?? null) === 'المنصورة',
            'delivery_match' => ($data['fulfillment'] ?? null) === 'DELIVERY',
            'two_items' => count($data['items'] ?? []) === 2,
        ] + $weights;
    }
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    echo "READINESS_CHECK_FAILED\n";
    exit(1);
}
