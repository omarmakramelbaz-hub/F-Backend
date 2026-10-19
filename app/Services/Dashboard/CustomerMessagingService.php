<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Approved-template outbox. API ambiguity is durable and is never automatically retried. */
class CustomerMessagingService
{
    private const GRAPH = 'https://graph.facebook.com/v25.0/';
    private const FEEDBACK_TEMPLATE_NAME = 'fasakhansta_order_feedback_v1';
    private const FEEDBACK_TEMPLATE_LANGUAGE = 'ar';
    private const MARKETING_TEMPLATE_PREFIX = 'fasakhansta_promotion_';
    private $transport;
    private $authorizer;
    private $clock;
    private $purchaseGuard;
    private ?array $configuration;
    private ?array $templateCache = null;
    private ?array $rawTemplateCache = null;

    public function __construct(?callable $transport = null, ?callable $authorizer = null,
        ?callable $clock = null, ?array $configuration = null, ?callable $purchaseGuard = null)
    {
        $this->transport = $transport; $this->authorizer = $authorizer;
        $this->clock = $clock; $this->configuration = $configuration; $this->purchaseGuard = $purchaseGuard;
    }

    public function status(): array
    {
        $cfg = $this->cfg();
        $out = ['available' => false, 'reason' => $this->reason(), 'total_count' => 0, 'eligible_count' => 0,
            'feedback_eligible_count' => 0, 'enabled' => (bool) ($cfg['enabled'] ?? false),
            'feedback_enabled' => (bool) ($cfg['feedback_enabled'] ?? false), 'feedback_templates' => [], 'feedback_ready' => false, 'feedback_reason' => null,
            'feedback_template' => $cfg['feedback_template_name'] ?? '',
            'feedback_language' => $cfg['feedback_language'] ?? 'ar',
            'feedback_body' => $this->feedbackBody($cfg), 'marketing_templates' => [], 'campaigns' => [],
            'feedback_setup' => $this->feedbackSetupUnavailable($this->connectionReason()),
            'marketing_setup' => $this->marketingSetupUnavailable($this->connectionReason())];
        if (!$this->tables()) return $out;
        $out['total_count'] = DB::table('crm_customers')->count();
        $out['eligible_count'] = $this->eligible('marketing')->count();
        $out['feedback_eligible_count'] = $this->eligible('service')->count();
        foreach (DB::table('crm_whatsapp_campaigns')->orderByDesc('id')->limit(20)->get() as $campaign) {
            $counts = DB::table('crm_whatsapp_deliveries')->where('campaign_id', $campaign->id)
                ->select('state')->selectRaw('COUNT(*) AS aggregate')->groupBy('state')->pluck('aggregate', 'state');
            $out['campaigns'][] = ['id' => (int) $campaign->id, 'template_name' => $campaign->template_name,
                'language' => $campaign->language, 'status' => $campaign->status, 'total' => (int) $campaign->recipient_count,
                'accepted' => (int) ($counts['accepted'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0),
                'unknown' => (int) ($counts['unknown'] ?? 0), 'skipped' => (int) ($counts['skipped'] ?? 0),
                'queued' => (int) ($counts['queued'] ?? 0), 'created_at' => $campaign->created_at];
        }
        if ($this->connectionReason() !== null) return $out;
        try {
            foreach ($this->templates() as $template) {
                if ($template['category'] === 'MARKETING') $out['marketing_templates'][] = $this->publicTemplate($template);
                if ($this->feedbackMatches($template, $cfg)) {
                    $out['feedback_templates'][] = $this->publicTemplate($template);
                }
            }
            $out['available'] = true; $out['reason'] = null;
            $out['feedback_setup'] = $this->feedbackSetup($this->rawTemplates());
            $out['marketing_setup'] = $this->marketingSetup($this->rawTemplates());
        } catch (\Throwable $error) {
            $out['reason'] = 'TEMPLATE_LOOKUP_UNAVAILABLE';
            $out['feedback_setup'] = $this->feedbackSetupUnavailable($this->lookupReason($error));
            $out['marketing_setup'] = $this->marketingSetupUnavailable($this->lookupReason($error));
            return $out;
        }
        try { $selected = $this->feedbackTemplate(); $out['feedback_ready'] = true; $out['feedback_body'] = $selected['body']; }
        catch (\Throwable $error) { $out['feedback_reason'] = 'APPROVED_FEEDBACK_TEMPLATE_REQUIRED'; }
        return $out;
    }

    /** Explicitly submits the fixed feedback template for review; never enables or sends messages. */
    public function prepareFeedbackTemplate($actor): array
    {
        $this->actor($actor);
        $reason = $this->connectionReason();
        if ($reason !== null) throw new HttpException(422, $reason);
        $cfg = $this->cfg();
        // This operation only provisions the established two-field order feedback contract.
        if (($cfg['feedback_parameters'] ?? []) !== ['source_id', 'branch']) throw new HttpException(422, 'FEEDBACK_TEMPLATE_CONFIGURATION_INVALID');
        $raw = ['name' => self::FEEDBACK_TEMPLATE_NAME, 'language' => self::FEEDBACK_TEMPLATE_LANGUAGE,
            'category' => 'UTILITY', 'status' => 'PENDING',
            'components' => [['type' => 'BODY', 'text' => $this->feedbackBody($cfg),
                'example' => ['body_text' => [['12345', 'شبرا الخيمة']]]]]];
        $candidate = $this->validateTemplate($raw, false);
        if ($candidate === null || $candidate['parameters'] !== 2 || trim($candidate['body']) === '') {
            throw new HttpException(422, 'FEEDBACK_TEMPLATE_CONFIGURATION_INVALID');
        }
        // A new explicit action always checks Meta again, including pending/rejected and unsupported rows.
        $this->rawTemplateCache = $this->templateCache = null;
        try { $templates = $this->rawTemplates(); }
        catch (\Throwable $error) {
            $reason = $this->lookupReason($error);
            throw new HttpException($reason === 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED' ? 403 : ($reason === 'TEMPLATE_PAGINATION_LIMIT' ? 422 : 503), $reason);
        }
        $setup = $this->feedbackSetup($templates);
        if ($setup['state'] === 'conflict') throw new HttpException(409, 'FEEDBACK_TEMPLATE_CONFLICT');
        if (!$setup['can_prepare']) return ['success' => true, 'created' => false, 'feedback_setup' => $setup];
        $payload = array_intersect_key($raw, array_flip(['name', 'language', 'category', 'components']));
        try { $response = $this->request('POST', WhatsAppInboxAccess::WABA_ID . '/message_templates', $payload); }
        catch (\Throwable $error) { throw new HttpException(503, 'FEEDBACK_TEMPLATE_CREATE_UNCONFIRMED'); }
        $body = $response['json'];
        if ($response['status'] >= 400 && $response['status'] < 500 && is_array($body['error'] ?? null)) {
            $code = $body['error']['code'] ?? null;
            $permission = in_array($code, [10, 190, 200], true) || in_array($response['status'], [401, 403], true);
            throw new HttpException($permission ? 403 : 422,
                $permission ? 'TEMPLATE_MANAGEMENT_PERMISSION_REQUIRED' : 'FEEDBACK_TEMPLATE_PROVIDER_REJECTED');
        }
        if (!in_array($response['status'], [200, 201], true) || isset($body['error'])
            || !is_string($body['id'] ?? null) || !preg_match('/\A[1-9][0-9]{0,30}\z/', $body['id'])
            || !is_string($body['status'] ?? null) || !preg_match('/\A[A-Z_]{1,40}\z/', $body['status'])
            || !in_array($body['category'] ?? null, ['UTILITY', 'MARKETING'], true)) {
            throw new HttpException(503, 'FEEDBACK_TEMPLATE_CREATE_UNCONFIRMED');
        }
        // Return review state from the creation receipt. Sending still requires a fresh approved GET.
        $raw['status'] = $body['status']; $raw['category'] = $body['category'];
        $this->rawTemplateCache = $this->templateCache = null;
        return ['success' => true, 'created' => true, 'feedback_setup' => $this->feedbackSetup([$raw])];
    }

    /** Explicitly submits authored static promotional text; creates no campaign or customer message. */
    public function prepareMarketingTemplate($actor, array $input): array
    {
        $this->actor($actor);
        $reason = $this->connectionReason();
        if ($reason !== null) throw new HttpException(422, $reason);
        $body = $input['body'] ?? null;
        if (!is_string($body) || preg_match('//u', $body) !== 1
            || preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x{007F}-\x{009F}{}]/u', $body)) {
            throw new HttpException(422, 'INVALID_MARKETING_TEMPLATE_BODY');
        }
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($body === '' || mb_strlen($body, 'UTF-8') > 1024) throw new HttpException(422, 'INVALID_MARKETING_TEMPLATE_BODY');
        $name = self::MARKETING_TEMPLATE_PREFIX . substr(hash('sha256', $body), 0, 16);
        $this->rawTemplateCache = $this->templateCache = null;
        try { $templates = $this->rawTemplates(); }
        catch (\Throwable $error) {
            $reason = $this->lookupReason($error);
            throw new HttpException($reason === 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED' ? 403 : ($reason === 'TEMPLATE_PAGINATION_LIMIT' ? 422 : 503), $reason);
        }
        $setup = $this->marketingSetup($templates, $body);
        if ($setup['state'] === 'conflict') throw new HttpException(409, 'MARKETING_TEMPLATE_CONFLICT');
        if ($setup['template_status'] !== null) return ['success' => true, 'created' => false, 'marketing_setup' => $setup];
        $raw = ['name' => $name, 'language' => 'ar', 'category' => 'MARKETING', 'status' => 'PENDING',
            'components' => [['type' => 'BODY', 'text' => $body]]];
        $payload = array_intersect_key($raw, array_flip(['name', 'language', 'category', 'components']));
        try { $response = $this->request('POST', WhatsAppInboxAccess::WABA_ID . '/message_templates', $payload); }
        catch (\Throwable $error) { throw new HttpException(503, 'MARKETING_TEMPLATE_CREATE_UNCONFIRMED'); }
        $receipt = $response['json'];
        if ($response['status'] >= 400 && $response['status'] < 500 && is_array($receipt['error'] ?? null)) {
            $code = $receipt['error']['code'] ?? null;
            $permission = in_array($code, [10, 190, 200, '10', '190', '200'], true) || in_array($response['status'], [401, 403], true);
            throw new HttpException($permission ? 403 : 422,
                $permission ? 'TEMPLATE_MANAGEMENT_PERMISSION_REQUIRED' : 'MARKETING_TEMPLATE_PROVIDER_REJECTED');
        }
        if (!in_array($response['status'], [200, 201], true) || isset($receipt['error'])
            || !is_string($receipt['id'] ?? null) || !preg_match('/\A[1-9][0-9]{0,30}\z/', $receipt['id'])
            || !is_string($receipt['status'] ?? null) || !preg_match('/\A[A-Z_]{1,40}\z/', $receipt['status'])
            || !in_array($receipt['category'] ?? null, ['UTILITY', 'MARKETING'], true)) {
            throw new HttpException(503, 'MARKETING_TEMPLATE_CREATE_UNCONFIRMED');
        }
        $raw['status'] = $receipt['status']; $raw['category'] = $receipt['category'];
        $this->rawTemplateCache = $this->templateCache = null;
        return ['success' => true, 'created' => true, 'marketing_setup' => $this->marketingSetup(array_merge($templates, [$raw]), $body)];
    }

    /** Persists an explicit central-admin configuration, without sending or changing AI replies. */
    public function configure($actor, array $input): array
    {
        $actor = $this->actor($actor);
        if (!$this->tables() || !Schema::hasTable('crm_whatsapp_settings')) throw new HttpException(503, 'CRM_SCHEMA_UNAVAILABLE');
        foreach (['enabled', 'feedback_enabled'] as $flag) if (!array_key_exists($flag, $input)
            || !in_array($input[$flag], [true, false, 0, 1, '0', '1'], true)) throw new HttpException(422, 'INVALID_SETTING');
        $enabled = (bool) $input['enabled']; $feedback = (bool) $input['feedback_enabled'];
        $name = (string) ($input['feedback_template_name'] ?? ''); $language = (string) ($input['feedback_language'] ?? 'ar');
        if (($name !== '' && !preg_match('/\A[a-z0-9_]{1,512}\z/', $name))
            || !preg_match('/\A[a-zA-Z_]{2,20}\z/', $language) || ($feedback && !$enabled)) throw new HttpException(422, 'INVALID_SETTING');
        if ($enabled && $this->connectionReason() !== null) throw new HttpException(422, 'WHATSAPP_CONNECTION_UNAVAILABLE');
        if ($feedback) {
            try { $template = $this->template($name, $language, 'UTILITY'); }
            catch (\Throwable $error) { throw new HttpException(422, 'APPROVED_FEEDBACK_TEMPLATE_REQUIRED'); }
            if (!$this->feedbackMatches($template)) throw new HttpException(422, 'FEEDBACK_TEMPLATE_BODY_MISMATCH');
        }
        DB::table('crm_whatsapp_settings')->where('id', 1)->update(['enabled' => $enabled, 'feedback_enabled' => $feedback,
            'feedback_template_name' => $name, 'feedback_language' => $language, 'actor_id' => $actor->id, 'updated_at' => $this->stamp()]);
        return ['success' => true, 'enabled' => $enabled, 'feedback_enabled' => $feedback];
    }

    /** Creates a reviewable campaign snapshot; HTTP request never sends a customer message. */
    public function queueCampaign($actor, array $input): array
    {
        $actor = $this->actor($actor);
        if ($this->reason() !== null) throw new HttpException(503, 'CRM_WHATSAPP_UNAVAILABLE');
        $uuid = strtolower((string) ($input['client_request_id'] ?? ''));
        $name = (string) ($input['template_name'] ?? ''); $language = (string) ($input['language'] ?? '');
        $parameters = $input['parameters'] ?? [];
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)
            || !preg_match('/\A[a-z0-9_]{1,512}\z/', $name) || !preg_match('/\A[a-zA-Z_]{2,20}\z/', $language)
            || !is_array($parameters) || array_values($parameters) !== $parameters || count($parameters) > 10) {
            throw new HttpException(422, 'INVALID_CAMPAIGN');
        }
        foreach ($parameters as $parameter) if (!is_string($parameter) || trim($parameter) === ''
            || mb_strlen($parameter) > 1024 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $parameter)) {
            throw new HttpException(422, 'INVALID_TEMPLATE_PARAMETER');
        }
        $payloadHash = hash('sha256', json_encode([$name, $language, $parameters], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $requestHash = hash('sha256', $actor->id . ':' . $uuid);
        // Retries resolve their existing snapshot even if the provider is temporarily unavailable.
        $old = DB::table('crm_whatsapp_campaigns')->where('request_hash', $requestHash)->first();
        if ($old) return $this->campaignReplay($old, $payloadHash);
        try { $template = $this->template($name, $language, 'MARKETING'); }
        catch (\Throwable $error) { throw new HttpException(422, 'APPROVED_MARKETING_TEMPLATE_REQUIRED'); }
        if ($template['parameters'] !== count($parameters)) throw new HttpException(422, 'TEMPLATE_PARAMETERS_MISMATCH');
        return DB::transaction(function () use ($actor, $name, $language, $parameters, $payloadHash, $requestHash, $template) {
            // Serialize campaign creation and its consent snapshot, including duplicate UUID requests.
            DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->lockForUpdate()->first();
            $old = DB::table('crm_whatsapp_campaigns')->where('request_hash', $requestHash)->lockForUpdate()->first();
            if ($old) return $this->campaignReplay($old, $payloadHash);
            $max = max(1, min(50000, (int) ($this->cfg()['campaign_max_recipients'] ?? 50000)));
            $count = $this->eligible('marketing')->count();
            if ($count < 1) throw new HttpException(422, 'NO_CONSENTED_CUSTOMERS');
            if ($count > $max) throw new HttpException(422, 'CAMPAIGN_RECIPIENT_LIMIT');
            $stamp = $this->stamp();
            $id = DB::table('crm_whatsapp_campaigns')->insertGetId(['actor_id' => $actor->id,
                'request_hash' => $requestHash, 'payload_hash' => $payloadHash, 'template_name' => $name, 'language' => $language,
                'parameters' => Crypt::encryptString(json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'template_hash' => $template['hash'], 'status' => 'queued', 'recipient_count' => 0,
                'expires_at' => $this->now()->addHours(max(1, min(168, (int) ($this->cfg()['campaign_expiry_hours'] ?? 72))))->format('Y-m-d H:i:s'),
                'created_at' => $stamp, 'updated_at' => $stamp]);
            $last = 0; $total = 0;
            do {
                $customers = $this->eligible('marketing')->where('id', '>', $last)->orderBy('id')->limit(500)->get(['id']);
                $rows = [];
                foreach ($customers as $customer) {
                    $last = (int) $customer->id; if (++$total > $max) throw new HttpException(422, 'CAMPAIGN_RECIPIENT_LIMIT');
                    $rows[] = ['kind' => 'campaign', 'customer_id' => $last, 'purchase_id' => null, 'campaign_id' => $id,
                        'state' => 'queued', 'attempts' => 0, 'created_at' => $stamp, 'updated_at' => $stamp];
                }
                if ($rows) DB::table('crm_whatsapp_deliveries')->insert($rows);
            } while (count($customers) === 500);
            DB::table('crm_whatsapp_campaigns')->where('id', $id)->update(['recipient_count' => $total]);
            return ['success' => true, 'campaign_id' => (int) $id, 'recipient_count' => $total, 'state' => 'queued', 'replayed' => false];
        }, 3);
    }

    /** Scheduler-only worker. Configuration remains off until a real approved template is configured. */
    public function process(int $limit = 25): array
    {
        $stats = ['queued_feedback' => 0, 'accepted' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0,
            'deferred' => 0, 'reason' => $this->reason()];
        if ($stats['reason'] !== null) return $stats;
        $limit = max(1, min(100, $limit, (int) ($this->cfg()['batch_size'] ?? 25)));
        $stats['queued_feedback'] = $this->queueFeedback($limit);
        $rows = DB::table('crm_whatsapp_deliveries')->where('state', 'queued')
            ->where(function ($q) { $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $this->stamp()); })
            ->orderBy('id')->limit($limit)->get(['id']);
        foreach ($rows as $row) {
            try { $result = $this->dispatch((int) $row->id); }
            catch (\Throwable $error) { $result = 'deferred'; }
            if (array_key_exists($result, $stats)) $stats[$result]++;
        }
        $this->finishCampaigns();
        return $stats;
    }

    private function queueFeedback(int $limit): int
    {
        if (!filter_var($this->cfg()['feedback_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) return 0;
        try { $this->feedbackTemplate(); } catch (\Throwable $error) { return 0; }
        $ids = DB::table('crm_purchases')->where('feedback_status', 'pending')->where('feedback_due_at', '<=', $this->stamp())
            ->orderBy('id')->limit($limit)->pluck('id'); $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $purchase = DB::table('crm_purchases')->where('id', $id)->lockForUpdate()->first();
                if (!$purchase || $purchase->feedback_status !== 'pending') return 0;
                if (DB::table('crm_whatsapp_deliveries')->where('purchase_id', $id)->exists()) return 0;
                $stamp = $this->stamp();
                DB::table('crm_whatsapp_deliveries')->insert(['kind' => 'feedback', 'customer_id' => $purchase->customer_id,
                    'purchase_id' => $id, 'campaign_id' => null, 'state' => 'queued', 'attempts' => 0,
                    'created_at' => $stamp, 'updated_at' => $stamp]);
                DB::table('crm_purchases')->where('id', $id)->update(['feedback_status' => 'queued', 'updated_at' => $stamp]);
                return 1;
            }, 3);
        }
        return $count;
    }

    private function dispatch(int $id): string
    {
        $row = DB::table('crm_whatsapp_deliveries')->where('id', $id)->first();
        if (!$row || $row->state !== 'queued') return 'deferred';
        try {
            if ($row->kind === 'feedback') {
                if (!filter_var($this->cfg()['feedback_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) return 'deferred';
                $template = $this->feedbackTemplate();
                $purchase = DB::table('crm_purchases')->where('id', $row->purchase_id)->first();
                if (!$purchase || !$this->purchaseCompleted($purchase)) return $this->stop($id, 'skipped', 'PURCHASE_NO_LONGER_COMPLETED');
                $params = [];
                foreach ($this->cfg()['feedback_parameters'] ?? [] as $field) {
                    if (!in_array($field, ['source_id', 'branch', 'channel'], true) || trim((string) ($purchase->$field ?? '')) === '') {
                        return $this->stop($id, 'skipped', 'ORDER_CONTEXT_UNAVAILABLE');
                    }
                    $params[] = $field === 'branch' ? $this->branchLabel((string) $purchase->branch) : (string) $purchase->$field;
                }
            } else {
                $campaign = DB::table('crm_whatsapp_campaigns')->where('id', $row->campaign_id)->first();
                if (!$campaign || $campaign->status === 'cancelled' || $campaign->expires_at <= $this->stamp()) {
                    return $this->stop($id, 'skipped', 'CAMPAIGN_EXPIRED');
                }
                $template = $this->template($campaign->template_name, $campaign->language, 'MARKETING');
                if (!hash_equals($campaign->template_hash, $template['hash'])) return $this->stop($id, 'failed', 'TEMPLATE_CHANGED');
                $params = json_decode(Crypt::decryptString($campaign->parameters), true, 16, JSON_THROW_ON_ERROR);
            }
            if (!is_array($params) || count($params) !== $template['parameters']) return $this->stop($id, 'failed', 'TEMPLATE_PARAMETERS_MISMATCH');
        } catch (\Throwable $error) { return 'deferred'; } // No external POST occurred; metadata may be unavailable.
        $claim = DB::transaction(function () use ($id) {
            $gate = DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('crm_whatsapp_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($this->reason() !== null || !$gate || !$row || $row->state !== 'queued' || ($row->next_attempt_at && $row->next_attempt_at > $this->stamp())) return null;
            $customer = DB::table('crm_customers')->where('id', $row->customer_id)->lockForUpdate()->first();
            $consent = $row->kind === 'feedback' ? 'service' : 'marketing';
            if (!$this->consented($customer, $consent)) { $this->finish($row, 'skipped', 'CONSENT_REQUIRED'); return ['skip' => true]; }
            $phone = ltrim((string) $customer->phone, '+');
            if (!preg_match('/\A[1-9][0-9]{7,14}\z/', $phone)) { $this->finish($row, 'skipped', 'INVALID_PHONE'); return ['skip' => true]; }
            if ($row->kind === 'feedback') {
                $purchase = DB::table('crm_purchases')->where('id', $row->purchase_id)->lockForUpdate()->first();
                $expiry = max(24, min(720, (int) ($this->cfg()['feedback_expiry_hours'] ?? 168)));
                if (!filter_var($this->cfg()['feedback_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) return null;
                if (!$purchase || !$this->purchaseCompleted($purchase) || (int) $purchase->customer_id !== (int) $customer->id || $purchase->feedback_due_at > $this->stamp()
                    || $purchase->feedback_due_at < $this->now()->subHours($expiry)->format('Y-m-d H:i:s')) {
                    $this->finish($row, 'skipped', 'FEEDBACK_EXPIRED_OR_UNAVAILABLE'); return ['skip' => true];
                }
            } else {
                $campaign = DB::table('crm_whatsapp_campaigns')->where('id', $row->campaign_id)->lockForUpdate()->first();
                if (!$campaign || $campaign->status === 'cancelled' || $campaign->expires_at <= $this->stamp()) {
                    $this->finish($row, 'skipped', 'CAMPAIGN_EXPIRED'); return ['skip' => true];
                }
                $cooldown = max(1, min(168, (int) ($this->cfg()['marketing_cooldown_hours'] ?? 24)));
                $recent = DB::table('crm_whatsapp_deliveries')->where('customer_id', $customer->id)->where('kind', 'campaign')
                    ->whereIn('state', ['accepted', 'unknown'])->where('send_started_at', '>', $this->now()->subHours($cooldown)->format('Y-m-d H:i:s'))
                    ->orderByDesc('send_started_at')->first(['send_started_at']);
                if ($recent) {
                    DB::table('crm_whatsapp_deliveries')->where('id', $id)->update(['next_attempt_at' =>
                        CarbonImmutable::parse($recent->send_started_at, 'UTC')->addHours($cooldown)->format('Y-m-d H:i:s'), 'updated_at' => $this->stamp()]);
                    return null;
                }
            }
            if ($gate->blocked_until && $gate->blocked_until > $this->stamp()) return null;
            $inWindow = $gate->window_started_at && $gate->window_started_at > $this->now()->subMinute()->format('Y-m-d H:i:s');
            $used = $inWindow ? (int) $gate->window_count : 0;
            if ($used >= max(1, min(100, (int) ($this->cfg()['per_minute'] ?? 25)))) return null;
            DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->update(['window_started_at' =>
                $inWindow ? $gate->window_started_at : $this->stamp(), 'window_count' => $used + 1]);
            // UNKNOWN is committed before I/O. A crash, timeout, 5xx or malformed success cannot cause a duplicate.
            DB::table('crm_whatsapp_deliveries')->where('id', $id)->update(['state' => 'unknown',
                'attempts' => (int) $row->attempts + 1, 'send_started_at' => $this->stamp(),
                'last_error' => 'DELIVERY_UNCONFIRMED', 'updated_at' => $this->stamp()]);
            $this->purchaseState($row, 'unknown');
            return ['phone' => $phone, 'customer_id' => (int) $customer->id, 'consent' => $consent];
        }, 3);
        if ($claim === null) return 'deferred';
        if (isset($claim['skip'])) return 'skipped';
        // Final guard after the durable claim; no network request has happened yet.
        if ($this->reason() !== null) return $this->stop($id, 'skipped', 'SENDER_DISABLED', null, 'unknown');
        if (!$this->consented(DB::table('crm_customers')->where('id', $claim['customer_id'])->first(), $claim['consent'])) {
            return $this->stop($id, 'skipped', 'CONSENT_REQUIRED', null, 'unknown');
        }
        $payload = ['messaging_product' => 'whatsapp', 'to' => $claim['phone'], 'type' => 'template',
            'template' => ['name' => $template['name'], 'language' => ['code' => $template['language']]]];
        if ($params) $payload['template']['components'] = [['type' => 'body', 'parameters' => array_map(
            static function ($text) { return ['type' => 'text', 'text' => (string) $text]; }, $params)]];
        try { $response = $this->request('POST', WhatsAppInboxAccess::PHONE_ID . '/messages', $payload); }
        catch (\Throwable $error) { return 'unknown'; }
        $body = $response['json']; $code = $response['status'];
        $message = $body['messages'][0]['id'] ?? null;
        if ($code === 200 && ($body['messaging_product'] ?? null) === 'whatsapp' && !isset($body['error'])
            && is_array($body['messages'] ?? null) && count($body['messages']) === 1 && is_string($message)
            && preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $message)) {
            if (isset($body['contacts']) && (!is_array($body['contacts']) || count($body['contacts']) !== 1
                || ($body['contacts'][0]['input'] ?? null) !== $claim['phone']
                || ($body['contacts'][0]['wa_id'] ?? null) !== $claim['phone'])) return 'unknown';
            return $this->stop($id, 'accepted', null, $message, 'unknown');
        }
        // A structured rate-limit rejection confirms no accepted message, unlike 5xx/timeouts.
        if ($code === 429 && is_array($body['error'] ?? null) && empty($body['messages'])) {
            return $this->rateLimited($id);
        }
        if ($code >= 400 && $code < 500 && is_array($body['error'] ?? null) && empty($body['messages'])) {
            return $this->stop($id, 'failed', 'PROVIDER_REJECTED', null, 'unknown');
        }
        return 'unknown';
    }

    private function rateLimited(int $id): string
    {
        return DB::transaction(function () use ($id) {
            DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('crm_whatsapp_deliveries')->where('id', $id)->lockForUpdate()->first();
            $until = $this->now()->addSeconds(max(60, min(86400, (int) ($this->cfg()['rate_limit_backoff_seconds'] ?? 300))))->format('Y-m-d H:i:s');
            DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->update(['blocked_until' => $until]);
            if ((int) $row->attempts >= max(1, min(5, (int) ($this->cfg()['max_rate_limit_attempts'] ?? 3)))) {
                $this->finish($row, 'failed', 'RATE_LIMIT_RETRY_EXHAUSTED'); return 'failed';
            }
            DB::table('crm_whatsapp_deliveries')->where('id', $id)->update(['state' => 'queued', 'next_attempt_at' => $until,
                'last_error' => 'PROVIDER_RATE_LIMITED', 'updated_at' => $this->stamp()]);
            $this->purchaseState($row, 'queued'); return 'deferred';
        }, 3);
    }

    private function stop(int $id, string $state, ?string $reason, ?string $message = null, string $expected = 'queued'): string
    {
        DB::transaction(function () use ($id, $state, $reason, $message, $expected) {
            // Match the receipt projection's lock order before taking its delivery lock.
            if ($state === 'accepted') DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->lockForUpdate()->first();
            $row = DB::table('crm_whatsapp_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row && $row->state === $expected) $this->finish($row, $state, $reason, $message);
        }, 3);
        return $state;
    }

    private function finish(object $row, string $state, ?string $reason, ?string $message = null): void
    {
        $values = ['state' => $state, 'last_error' => $reason, 'updated_at' => $this->stamp()];
        if ($state === 'accepted') $values += ['provider_message_id' => $message, 'accepted_at' => $this->stamp(), 'delivery_status' => 'accepted'];
        if ($state === 'failed') $values['failed_at'] = $this->stamp();
        DB::table('crm_whatsapp_deliveries')->where('id', $row->id)->update($values);
        $this->purchaseState($row, $state, $message);
        if ($state === 'accepted' && is_string($message) && class_exists(CustomerMessageEvents::class)) {
            (new CustomerMessageEvents())->reconcile($message);
        }
    }

    private function purchaseState(object $row, string $state, ?string $message = null): void
    {
        if ($row->kind !== 'feedback' || !$row->purchase_id) return;
        $values = ['feedback_status' => $state, 'updated_at' => $this->stamp()];
        if ($state === 'accepted') $values += ['feedback_sent_at' => $this->stamp(), 'message_id' => $message];
        DB::table('crm_purchases')->where('id', $row->purchase_id)->update($values);
    }

    private function finishCampaigns(): void
    {
        foreach (DB::table('crm_whatsapp_campaigns')->where('status', 'queued')->orderBy('id')->limit(100)->get(['id']) as $campaign) {
            if (!DB::table('crm_whatsapp_deliveries')->where('campaign_id', $campaign->id)->where('state', 'queued')->exists()) {
                $unknown = DB::table('crm_whatsapp_deliveries')->where('campaign_id', $campaign->id)->where('state', 'unknown')->exists();
                DB::table('crm_whatsapp_campaigns')->where('id', $campaign->id)->where('status', 'queued')
                    ->update(['status' => $unknown ? 'review_required' : 'complete', 'updated_at' => $this->stamp()]);
            }
        }
    }

    private function branchLabel(string $branch): string
    {
        if (preg_match('/\A(f|gs):([1-9][0-9]{0,18})\z/', $branch, $match)) {
            $table = $match[1] === 'f' ? 'resturants' : 'go_stores';
            $column = $match[1] === 'f' ? 'id' : 'user_id';
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'name')) {
                $name = trim((string) DB::table($table)->where($column, $match[2])->value('name'));
                if ($name !== '') return mb_substr($name, 0, 150);
            }
            return 'رقم ' . $match[2];
        }
        throw new \RuntimeException('ORDER_BRANCH_UNAVAILABLE');
    }

    private function purchaseCompleted(object $purchase): bool
    {
        return $this->purchaseGuard !== null ? (bool) ($this->purchaseGuard)($purchase)
            : (new CustomerDirectory())->purchaseIsCompleted($purchase);
    }

    private function campaignReplay(object $row, string $hash): array
    {
        if (!hash_equals($row->payload_hash, $hash)) throw new HttpException(409, 'CAMPAIGN_REQUEST_CONFLICT');
        return ['success' => true, 'campaign_id' => (int) $row->id, 'recipient_count' => (int) $row->recipient_count,
            'state' => $row->status, 'replayed' => true];
    }

    private function eligible(string $type)
    {
        return DB::table('crm_customers')->where($type . '_consent', true)->whereNotNull($type . '_consented_at')->whereNull('opted_out_at');
    }

    private function consented($customer, string $type): bool
    {
        $flag = $type . '_consent'; $evidence = $type . '_consented_at';
        return $customer && (bool) $customer->$flag && $customer->$evidence !== null && $customer->opted_out_at === null;
    }

    private function feedbackBody(?array $cfg = null): string
    {
        $cfg = $cfg ?? $this->cfg();
        $body = (string) ($cfg['feedback_body'] ?? '');
        // Meta also treats punctuation-only endings as trailing variables; use actual closing words.
        if (preg_match('/\{\{[1-9][0-9]*\}\}\z/', $body)) return $body . '. شكرًا لاختيارك فسخانستا.';
        if (preg_match('/\{\{[1-9][0-9]*\}\}\.\z/', $body)) return $body . ' شكرًا لاختيارك فسخانستا.';
        return $body;
    }

    private function feedbackMatches(array $template, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? $this->cfg();
        $body = (string) ($cfg['feedback_body'] ?? '');
        $bodies = [$body, $this->feedbackBody($cfg)];
        // Keep both earlier exact variants compatible only where they were previously generated.
        if (preg_match('/\{\{[1-9][0-9]*\}\}\z/', $body)) $bodies[] = $body . '.';
        return $template['category'] === 'UTILITY' && $template['parameters'] === count($cfg['feedback_parameters'] ?? [])
            && in_array($template['body'], $bodies, true);
    }

    private function feedbackTemplate(): array
    {
        $cfg = $this->cfg();
        $template = $this->template((string) ($cfg['feedback_template_name'] ?? ''), (string) ($cfg['feedback_language'] ?? 'ar'), 'UTILITY');
        if (!$this->feedbackMatches($template, $cfg)) {
            throw new \RuntimeException('APPROVED_FEEDBACK_TEMPLATE_REQUIRED');
        }
        return $template;
    }

    private function template(string $name, string $language, string $category): array
    {
        foreach ($this->templates() as $template) if ($template['name'] === $name && $template['language'] === $language && $template['category'] === $category) return $template;
        throw new \RuntimeException('APPROVED_TEMPLATE_REQUIRED');
    }

    private function templates(): array
    {
        if ($this->templateCache !== null) return $this->templateCache;
        $templates = [];
        foreach ($this->rawTemplates() as $raw) {
            $value = $this->validateTemplate($raw); if ($value !== null) $templates[] = $value;
        }
        return $this->templateCache = $templates;
    }

    /** All lifecycle states must be retained to prevent duplicate or conflicting submissions. */
    private function rawTemplates(): array
    {
        if ($this->rawTemplateCache !== null) return $this->rawTemplateCache;
        if ($this->connectionReason() !== null) throw new \RuntimeException('SENDER_UNAVAILABLE');
        $templates = []; $after = null; $seen = [];
        for ($page = 0; $page < 5; $page++) {
            $query = ['fields' => 'name,status,language,category,components', 'limit' => 100];
            if ($after !== null) $query['after'] = $after;
            $response = $this->request('GET', WhatsAppInboxAccess::WABA_ID . '/message_templates', $query);
            if ($response['status'] !== 200 || isset($response['json']['error']) || !is_array($response['json']['data'] ?? null)
                || count($response['json']['data']) > 100) {
                $code = $response['json']['error']['code'] ?? null;
                throw new \RuntimeException(in_array($code, [10, 190, 200], true) || in_array($response['status'], [401, 403], true)
                    ? 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED' : 'TEMPLATE_LOOKUP_UNAVAILABLE');
            }
            foreach ($response['json']['data'] as $raw) $templates[] = $raw;
            if (empty($response['json']['paging']['next'])) break;
            $after = $response['json']['paging']['cursors']['after'] ?? null;
            if (!is_string($after) || !preg_match('/\A[\x21-\x7E]{1,2048}\z/', $after) || isset($seen[$after]) || $page === 4) {
                throw new \RuntimeException('TEMPLATE_PAGINATION_LIMIT');
            }
            $seen[$after] = true;
        }
        return $this->rawTemplateCache = $templates;
    }

    private function marketingSetupUnavailable(?string $reason): array
    {
        return ['state' => 'unavailable', 'name' => null, 'language' => 'ar', 'category' => 'MARKETING', 'body' => '',
            'template_status' => null, 'reason' => $reason ?? 'TEMPLATE_LOOKUP_UNAVAILABLE', 'can_prepare' => false,
            'matches' => [], 'managed_templates' => [], 'supported_count' => 0, 'unsupported_count' => 0, 'unsupported_templates' => []];
    }

    private function marketingLifecycle(array $template): array
    {
        $state = ['APPROVED' => 'approved', 'PENDING' => 'pending', 'REJECTED' => 'rejected'][$template['status']] ?? 'unavailable';
        return $this->publicTemplate($template) + ['category' => $template['category'], 'status' => $template['status'],
            'managed' => $template['language'] === 'ar' && preg_match('/\Afasakhansta_promotion_[a-f0-9]{16}\z/', $template['name']) === 1,
            'state' => $state, 'reason' => $state === 'unavailable' ? 'MARKETING_TEMPLATE_NOT_APPROVED' : null];
    }

    private function marketingBodyMatches($raw, ?array $template, string $body): bool
    {
        // Authored promotions are body-only static text; other formats are never treated as this same content.
        return $template !== null && $template['category'] === 'MARKETING' && $template['body'] === $body
            && $template['language'] === 'ar' && $template['parameters'] === 0
            && is_array($raw['components'] ?? null) && count($raw['components']) === 1
            && strtoupper((string) ($raw['components'][0]['type'] ?? '')) === 'BODY';
    }

    private function marketingSetup(array $rawTemplates, ?string $body = null): array
    {
        $setup = $this->marketingSetupUnavailable(null);
        $setup['state'] = 'missing'; $setup['reason'] = null; $setup['can_prepare'] = true;
        $name = $body !== null ? self::MARKETING_TEMPLATE_PREFIX . substr(hash('sha256', $body), 0, 16) : null;
        $exact = []; $sameName = []; $hasManagedConflict = false; $statuses = [];
        foreach ($rawTemplates as $raw) {
            if (!is_array($raw)) continue;
            $value = $this->validateTemplate($raw, false);
            $managed = is_string($raw['name'] ?? null) && preg_match('/\Afasakhansta_promotion_[a-f0-9]{16}\z/', $raw['name']) === 1
                && ($raw['language'] ?? null) === 'ar';
            if ($body !== null && ($raw['name'] ?? null) === $name && ($raw['language'] ?? null) === 'ar') $sameName[] = $raw;
            if ($body !== null && $this->marketingBodyMatches($raw, $value, $body)) $exact[] = $this->marketingLifecycle($value);
            if ($value !== null && $value['category'] === 'MARKETING') {
                $lifecycle = $this->marketingLifecycle($value);
                $setup['supported_count']++; $statuses[$value['status']] = true;
                if (count($setup['matches']) < 50) $setup['matches'][] = $lifecycle;
                if ($managed && count($setup['managed_templates']) < 50) $setup['managed_templates'][] = $lifecycle;
            } elseif (($raw['category'] ?? null) === 'MARKETING' || $managed) {
                $setup['unsupported_count']++;
                if ($managed) $hasManagedConflict = true;
                if (count($setup['unsupported_templates']) < 20 && is_string($raw['name'] ?? null)
                    && preg_match('/\A[a-z0-9_]{1,512}\z/', $raw['name']) && is_string($raw['language'] ?? null)
                    && preg_match('/\A[a-zA-Z_]{2,20}\z/', $raw['language'])) {
                    $setup['unsupported_templates'][] = ['name' => $raw['name'], 'language' => $raw['language'], 'managed' => $managed,
                        'category' => in_array($raw['category'] ?? null, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true) ? $raw['category'] : 'UNKNOWN',
                        'status' => is_string($raw['status'] ?? null) && preg_match('/\A[A-Z_]{1,40}\z/', $raw['status']) ? $raw['status'] : 'UNKNOWN'];
                }
            }
        }
        if ($body !== null) {
            $setup['name'] = $name; $setup['body'] = $body;
            if (count($sameName) > 1 || ($sameName && !$this->marketingBodyMatches($sameName[0], $this->validateTemplate($sameName[0], false), $body))) {
                $setup['state'] = 'conflict'; $setup['reason'] = 'MARKETING_TEMPLATE_CONFLICT'; return $setup;
            }
            if ($sameName) $selected = $this->marketingLifecycle($this->validateTemplate($sameName[0], false));
            else {
                $selected = null;
                foreach (['APPROVED', 'PENDING', 'REJECTED'] as $status) {
                    foreach ($exact as $match) if ($match['status'] === $status) { $selected = $match; break 2; }
                }
                if ($selected === null && $exact) $selected = $exact[0];
            }
            if ($selected !== null) {
                foreach (['name', 'language', 'body', 'state', 'reason'] as $key) $setup[$key] = $selected[$key];
                $setup['template_status'] = $selected['status'];
            }
            return $setup;
        }
        foreach (['APPROVED' => 'approved', 'PENDING' => 'pending', 'REJECTED' => 'rejected'] as $status => $state) {
            if (isset($statuses[$status])) { $setup['state'] = $state; $setup['template_status'] = $status; return $setup; }
        }
        if ($setup['unsupported_count'] > 0) {
            $setup['state'] = $hasManagedConflict ? 'conflict' : 'unavailable';
            $setup['reason'] = $hasManagedConflict ? 'MARKETING_TEMPLATE_CONFLICT' : 'MARKETING_TEMPLATE_FORMAT_UNSUPPORTED';
        } elseif ($setup['matches']) {
            $setup['state'] = 'unavailable'; $setup['reason'] = 'MARKETING_TEMPLATE_NOT_APPROVED';
        }
        return $setup;
    }

    private function feedbackSetupUnavailable(?string $reason): array
    {
        return ['state' => 'unavailable', 'name' => self::FEEDBACK_TEMPLATE_NAME, 'language' => self::FEEDBACK_TEMPLATE_LANGUAGE,
            'category' => 'UTILITY', 'template_status' => null, 'reason' => $reason ?? 'TEMPLATE_LOOKUP_UNAVAILABLE',
            'matches' => [], 'can_prepare' => false];
    }

    private function lookupReason(\Throwable $error): string
    {
        return in_array($error->getMessage(), ['TEMPLATE_LOOKUP_PERMISSION_REQUIRED', 'TEMPLATE_PAGINATION_LIMIT'], true)
            ? $error->getMessage() : 'TEMPLATE_LOOKUP_UNAVAILABLE';
    }

    private function feedbackSetup(array $rawTemplates): array
    {
        $setup = $this->feedbackSetupUnavailable(null);
        $setup['state'] = 'missing'; $setup['reason'] = null; $setup['can_prepare'] = true;
        $fixed = []; $matches = []; $cfg = $this->cfg();
        foreach ($rawTemplates as $raw) {
            $value = $this->validateTemplate($raw, false);
            $compatible = $value !== null && $this->feedbackMatches($value, $cfg);
            $isFixed = is_array($raw) && ($raw['name'] ?? null) === self::FEEDBACK_TEMPLATE_NAME
                && ($raw['language'] ?? null) === self::FEEDBACK_TEMPLATE_LANGUAGE;
            if ($isFixed) {
                if (!$compatible) {
                    $setup['state'] = 'conflict'; $setup['reason'] = 'FEEDBACK_TEMPLATE_CONFLICT'; $setup['can_prepare'] = false;
                }
                $fixed[] = $value;
            }
            if ($compatible) $matches[] = array_intersect_key($value, array_flip(['name', 'language', 'category', 'status']));
        }
        $setup['matches'] = $matches;
        // Never overwrite an existing fixed-name variant even if another usable template exists.
        if ($setup['state'] === 'conflict' || count($fixed) > 1) {
            $setup['state'] = 'conflict'; $setup['reason'] = 'FEEDBACK_TEMPLATE_CONFLICT'; $setup['can_prepare'] = false;
            return $setup;
        }
        $selected = $fixed[0] ?? null;
        foreach (['APPROVED', 'PENDING'] as $priority) {
            foreach ($matches as $match) if ($match['status'] === $priority) { $selected = $match; break 2; }
        }
        if ($selected === null && $matches) $selected = $matches[0];
        if ($selected !== null) {
            $setup['template_status'] = $selected['status'];
            $setup['can_prepare'] = !$fixed && $selected['status'] === 'REJECTED';
            $setup['state'] = ['APPROVED' => 'approved', 'PENDING' => 'pending', 'REJECTED' => 'rejected'][$selected['status']] ?? 'unavailable';
            if ($setup['state'] === 'unavailable') $setup['reason'] = 'FEEDBACK_TEMPLATE_NOT_APPROVED';
        }
        return $setup;
    }

    private function validateTemplate($raw, bool $approvedOnly = true): ?array
    {
        if (!is_array($raw) || !is_string($raw['status'] ?? null) || !preg_match('/\A[A-Z_]{1,40}\z/', $raw['status'])
            || ($approvedOnly && $raw['status'] !== 'APPROVED') || !in_array($raw['category'] ?? null, ['MARKETING', 'UTILITY'], true)
            || !is_string($raw['name'] ?? null) || !preg_match('/\A[a-z0-9_]{1,512}\z/', $raw['name'])
            || !is_string($raw['language'] ?? null) || !preg_match('/\A[a-zA-Z_]{2,20}\z/', $raw['language'])
            || !is_array($raw['components'] ?? null)) return null;
        $body = null;
        foreach ($raw['components'] as $component) {
            if (!is_array($component)) return null;
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type === 'BODY') { if ($body !== null || !is_string($component['text'] ?? null)) return null; $body = $component['text']; }
            // Only plain text templates are supported; media/button/named parameters need explicit support.
            elseif (!in_array($type, ['HEADER', 'FOOTER'], true) || !is_string($component['text'] ?? null)
                || strpos($component['text'], '{{') !== false || (isset($component['format']) && $component['format'] !== 'TEXT')) return null;
        }
        if ($body === null || mb_strlen($body) > 4096) return null;
        preg_match_all('/\{\{([1-9][0-9]*)\}\}/', $body, $matches);
        $variables = array_values(array_unique(array_map('intval', $matches[1]))); sort($variables);
        $clean = preg_replace('/\{\{[1-9][0-9]*\}\}/', '', $body);
        if (strpos($clean, '{{') !== false || count($variables) > 10
            || ($variables && $variables !== range(1, count($variables)))) return null;
        return ['name' => $raw['name'], 'language' => $raw['language'], 'category' => $raw['category'], 'status' => $raw['status'],
            'body' => $body, 'parameters' => count($variables), 'hash' => hash('sha256', json_encode([
                $raw['name'], $raw['language'], $raw['category'], $raw['components']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    private function publicTemplate(array $template): array
    {
        return array_intersect_key($template, array_flip(['name', 'language', 'body', 'parameters']));
    }

    private function request(string $method, string $resource, array $payload): array
    {
        $url = self::GRAPH . $resource; $token = (string) config('whatsapp_replies.access_token', '');
        if ($this->configuration !== null) $token = (string) ($this->configuration['access_token'] ?? $token);
        if ($this->transport !== null) $response = ($this->transport)($method, $url, $payload, $token);
        else {
            if ($method === 'GET') $url .= '?' . http_build_query($payload);
            $handle = curl_init($url); $body = '';
            if ($handle === false) throw new \RuntimeException('TRANSPORT_UNAVAILABLE');
            curl_setopt_array($handle, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body) {
                    if (strlen($body) + strlen($chunk) > 1048576) return 0; $body .= $chunk; return strlen($chunk);
                }]);
            if ($method === 'POST') curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            try {
                $ok = curl_exec($handle); $response = ['status' => $ok === false ? 0 : (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body];
            } finally { curl_close($handle); }
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)
            || strlen($response['body']) > 1048576) throw new \RuntimeException('INVALID_PROVIDER_RESPONSE');
        $json = json_decode($response['body'], true, 64);
        return ['status' => $response['status'], 'json' => is_array($json) ? $json : []];
    }

    private function actor($actor)
    {
        return $this->authorizer !== null ? ($this->authorizer)($actor) : app(WhatsAppInboxAccess::class)->actor($actor);
    }

    private function reason(): ?string
    {
        $reason = $this->connectionReason(); if ($reason !== null) return $reason;
        if (!filter_var($this->cfg()['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) return 'CRM_SENDER_DISABLED';
        return null;
    }

    private function connectionReason(): ?string
    {
        if (!$this->tables()) return 'CRM_SCHEMA_UNAVAILABLE';
        if (config('desktop_dashboard.local', false)) return 'LOCAL_DEVICE_DISABLED';
        if (filter_var($this->cfg()['emergency_disabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) return 'EMERGENCY_DISABLED';
        $replyEnabled = $this->configuration['sender_enabled'] ?? config('whatsapp_replies.enabled', false);
        $token = $this->configuration['access_token'] ?? config('whatsapp_replies.access_token', '');
        if (!filter_var($replyEnabled, FILTER_VALIDATE_BOOLEAN) || !is_string($token) || trim($token) === '') return 'WHATSAPP_CONNECTION_UNAVAILABLE';
        return null;
    }

    private function tables(): bool
    {
        foreach (['crm_customers', 'crm_purchases', 'crm_whatsapp_campaigns', 'crm_whatsapp_deliveries', 'crm_whatsapp_dispatch_gate'] as $table) {
            if (!Schema::hasTable($table)) return false;
        }
        return true;
    }

    private function cfg(): array
    {
        $cfg = $this->configuration ?? (array) config('crm_whatsapp', []);
        if (Schema::hasTable('crm_whatsapp_settings')) {
            $stored = DB::table('crm_whatsapp_settings')->where('id', 1)->first();
            if ($stored) foreach (['enabled', 'feedback_enabled', 'feedback_template_name', 'feedback_language'] as $key) $cfg[$key] = $stored->$key;
        }
        return $cfg;
    }
    private function now(): CarbonImmutable { return $this->clock !== null ? ($this->clock)() : CarbonImmutable::now('UTC'); }
    private function stamp(): string { return $this->now()->format('Y-m-d H:i:s'); }
}
