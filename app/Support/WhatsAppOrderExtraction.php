<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Untrusted extraction hints. Validation does not authorize an ERP write. */
final class WhatsAppOrderExtraction
{
    public const MAX_MESSAGES = 60;
    public const MAX_INPUT_CHARACTERS = 16000;
    public const MAX_ITEMS = 30;
    public const ISSUES = [
        'NO_ORDER', 'TEST_MESSAGE', 'MISSING_CUSTOMER_NAME', 'MISSING_PHONE',
        'MISSING_ADDRESS', 'MISSING_AREA', 'MISSING_BRANCH', 'MISSING_ITEMS',
        'MISSING_QUANTITY', 'AMBIGUOUS_ITEM', 'AMBIGUOUS_BRANCH',
        'AMBIGUOUS_CONFIRMATION', 'UNCONFIRMED', 'UNSUPPORTED_MESSAGE',
        'CONTEXT_INCOMPLETE', 'CONFLICTING_DETAILS', 'PRICE_ESTIMATE_ONLY',
        'LOCATION_REQUIRED', 'PROMPT_INJECTION',
        'CART_ITEM_MAPPING_REQUIRED',
    ];
    private const QUANTITY_PATTERN = '/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?\z/';
    private const MONEY_PATTERN = '/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/';

    public static function schema(): array
    {
        $nullable = static fn (int $max) => ['type' => ['string', 'null'], 'maxLength' => $max];
        $labels = array_map(static fn ($id) => 'm' . $id, range(1, self::MAX_MESSAGES));
        $ids = ['type' => 'array', 'maxItems' => self::MAX_MESSAGES,
            'items' => ['type' => 'string', 'enum' => $labels]];
        return self::object([
            'decision' => ['type' => 'string', 'enum' => ['NONE', 'DRAFT', 'CONFIRMED', 'CANCELLED']],
            'customer' => self::object([
                'name' => $nullable(200), 'phone' => $nullable(40),
                'address' => $nullable(1000), 'area' => $nullable(200), 'notes' => $nullable(1000),
            ]),
            'branch_hint' => $nullable(200),
            'fulfillment' => ['type' => 'string', 'enum' => ['DELIVERY', 'PICKUP', 'UNKNOWN']],
            'items' => ['type' => 'array', 'maxItems' => self::MAX_ITEMS, 'items' => self::object([
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'quantity' => ['type' => ['string', 'null'],
                    'pattern' => '^(?:0|[1-9][0-9]{0,5})(?:\\.[0-9]{1,3})?$'],
                'quantity_mode' => ['type' => 'string', 'enum' => ['piece', 'weight', 'unknown']],
                'option_hint' => $nullable(200),
                'cart_reference' => ['anyOf' => [self::object([
                    'message_id' => ['type' => 'string', 'enum' => $labels],
                    'product_retailer_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                ]), ['type' => 'null']]],
            ])],
            'approximate_total' => ['type' => ['string', 'null'],
                'pattern' => '^(?:0|[1-9][0-9]{0,7})(?:\\.[0-9]{1,2})?$'],
            'evidence' => self::object([
                'request_ids' => $ids, 'confirmation_ids' => $ids, 'customer_acceptance_ids' => $ids,
                'location_id' => ['type' => ['string', 'null'], 'enum' => array_merge($labels, [null])],
            ]),
            'issues' => ['type' => 'array', 'maxItems' => count(self::ISSUES),
                'items' => ['type' => 'string', 'enum' => self::ISSUES]],
        ]);
    }

    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties,
            'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public static function result(bool $ok, ?string $reason = null, ?array $data = null): array
    {
        return ['ok' => $ok, 'reason' => $reason, 'data' => $data];
    }

    public static function none(string $issue = 'NO_ORDER'): array
    {
        return [
            'decision' => 'NONE',
            'customer' => ['name' => null, 'phone' => null, 'address' => null, 'area' => null, 'notes' => null],
            'branch_hint' => null, 'fulfillment' => 'UNKNOWN', 'items' => [], 'approximate_total' => null,
            'evidence' => ['request_ids' => [], 'confirmation_ids' => [],
                'customer_acceptance_ids' => [], 'location_id' => null],
            'issues' => [$issue],
        ];
    }

    /** Only the coordinator may supply rows from one verified conversation segment. */
    public static function validateTranscript(array $transcript): array
    {
        if (!array_is_list($transcript) || count($transcript) < 1 || count($transcript) > self::MAX_MESSAGES) {
            return self::result(false, 'INVALID_INPUT');
        }
        $ids = [];
        $characters = 0;
        $previous = null;
        foreach ($transcript as $row) {
            if (!is_array($row) || (!self::keys($row, ['id', 'speaker', 'sent_at', 'text', 'location'])
                    && !self::keys($row, ['id', 'speaker', 'sent_at', 'text', 'location', 'cart']))
                || !is_string($row['id']) || !preg_match('/\Am(?:[1-9]|[1-5][0-9]|60)\z/', $row['id'])
                || isset($ids[$row['id']]) || !in_array($row['speaker'], ['customer', 'business'], true)
                || !is_string($row['sent_at']) || !self::date($row['sent_at'])
                || ($previous !== null && strcmp($previous, $row['sent_at']) > 0)
                || !self::nullableString($row['text'], self::MAX_INPUT_CHARACTERS)) {
                return self::result(false, 'INVALID_INPUT');
            }
            if ($row['location'] !== null) {
                $location = $row['location'];
                if (!is_array($location) || !self::keys($location, ['lat', 'long'])
                    || !self::coordinate($location['lat'], 90) || !self::coordinate($location['long'], 180)) {
                    return self::result(false, 'INVALID_INPUT');
                }
            }
            if (($row['cart'] ?? null) !== null && ($row['speaker'] !== 'customer'
                || WhatsAppInboxProtocol::normalizedCart($row['cart']) === null)) {
                return self::result(false, 'INVALID_INPUT');
            }
            $ids[$row['id']] = true;
            $previous = $row['sent_at'];
            $characters += $row['text'] === null ? 0 : self::length($row['text']);
            if (($row['cart'] ?? null) !== null) $characters += self::length(json_encode($row['cart'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($characters > self::MAX_INPUT_CHARACTERS) {
                return self::result(false, 'INPUT_TOO_LARGE');
            }
        }
        return self::result(true, null, $transcript);
    }

    public static function hasProbe(array $transcript): bool
    {
        foreach ($transcript as $row) {
            if (preg_match('/\bWA-[0-9]{4}\b/i', self::rowText($row))) {
                return true;
            }
        }
        return false;
    }

    /** The automatic cutover must use real commitment, never a recent name/address-only row. */
    public static function committingRequestIds(array $data, array $transcript): array
    {
        $checked = self::validate($data, $transcript);
        if (!$checked['ok'] || ($checked['data']['decision'] ?? null) !== 'CONFIRMED') return [];
        $rows = array_column($transcript, null, 'id');
        $summary = max(array_map(static fn ($id) => $rows[$id]['sent_at'], $data['evidence']['confirmation_ids']));
        $ids = [];
        foreach ($data['evidence']['request_ids'] as $id) {
            $row = $rows[$id];
            if (strcmp($row['sent_at'], $summary) < 0
                && (self::commitment(self::rowText($row)) || ($row['cart'] ?? null) !== null)) $ids[] = $id;
        }
        foreach ($data['evidence']['customer_acceptance_ids'] as $id) {
            $row = $rows[$id]; $text = self::rowText($row);
            if (strcmp($row['sent_at'], $summary) > 0 && !self::instruction($text)
                && preg_match('/(?:تمام|موافق|أ[ك]?كد|اكد|أكد|اتفقنا|تأكيد|yes|confirm|\bok(?:ay)?\b)/iu', $text)) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    public static function validate(array $data, array $transcript): array
    {
        $input = self::validateTranscript($transcript);
        if (!$input['ok']) {
            return $input;
        }
        if (self::hasProbe($transcript)) {
            return self::result(true, null, self::none('TEST_MESSAGE'));
        }
        if (!self::keys($data, ['decision', 'customer', 'branch_hint', 'fulfillment', 'items',
                'approximate_total', 'evidence', 'issues'])
            || !in_array($data['decision'], ['NONE', 'DRAFT', 'CONFIRMED', 'CANCELLED'], true)
            || !is_array($data['customer'])
            || !self::keys($data['customer'], ['name', 'phone', 'address', 'area', 'notes'])
            || !self::nullableString($data['customer']['name'], 200)
            || !self::nullableString($data['customer']['phone'], 40)
            || !self::nullableString($data['customer']['address'], 1000)
            || !self::nullableString($data['customer']['area'], 200)
            || !self::nullableString($data['customer']['notes'], 1000)
            || !self::nullableString($data['branch_hint'], 200)
            || !in_array($data['fulfillment'], ['DELIVERY', 'PICKUP', 'UNKNOWN'], true)
            || !self::decimal($data['approximate_total'], self::MONEY_PATTERN, false)
            || !is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) > self::MAX_ITEMS
            || !is_array($data['evidence'])
            || !self::keys($data['evidence'], ['request_ids', 'confirmation_ids', 'customer_acceptance_ids', 'location_id'])
            || !is_array($data['issues']) || !array_is_list($data['issues'])
            || count($data['issues']) > count(self::ISSUES)) {
            return self::result(false, 'INVALID_EXTRACTION');
        }
        foreach ($data['issues'] as $issue) {
            if (!in_array($issue, self::ISSUES, true)) {
                return self::result(false, 'INVALID_EXTRACTION');
            }
        }
        if (count(array_unique($data['issues'], SORT_REGULAR)) !== count($data['issues'])) {
            return self::result(false, 'INVALID_EXTRACTION');
        }
        foreach ($data['items'] as $item) {
            if (!is_array($item) || (!self::keys($item, ['name', 'quantity', 'quantity_mode', 'option_hint'])
                    && !self::keys($item, ['name', 'quantity', 'quantity_mode', 'option_hint', 'cart_reference']))
                || !self::string($item['name'], 200) || !self::decimal($item['quantity'], self::QUANTITY_PATTERN, true)
                || !in_array($item['quantity_mode'], ['piece', 'weight', 'unknown'], true)
                || !self::nullableString($item['option_hint'], 200)) {
                return self::result(false, 'INVALID_EXTRACTION');
            }
        }
        $rows = array_column($transcript, null, 'id');
        foreach (['request_ids' => 'customer', 'confirmation_ids' => 'business',
            'customer_acceptance_ids' => 'customer'] as $field => $speaker) {
            $ids = $data['evidence'][$field];
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::MAX_MESSAGES) {
                return self::result(false, 'INVALID_EVIDENCE');
            }
            $seen = [];
            foreach ($ids as $id) {
                if (!is_string($id) || !isset($rows[$id]) || isset($seen[$id])
                    || $rows[$id]['speaker'] !== $speaker
                    || (!self::string(self::rowText($rows[$id]), self::MAX_INPUT_CHARACTERS)
                        && !($field === 'request_ids' && ($rows[$id]['cart'] ?? null) !== null))) {
                    return self::result(false, 'INVALID_EVIDENCE');
                }
                $seen[$id] = true;
            }
        }
        $cartReferences = [];
        foreach ($data['items'] as $item) {
            $ref = $item['cart_reference'] ?? null;
            if ($ref === null) continue;
            if (!is_array($ref) || !self::keys($ref, ['message_id', 'product_retailer_id'])
                || !is_string($ref['message_id']) || !is_string($ref['product_retailer_id'])
                || !in_array($ref['message_id'], $data['evidence']['request_ids'], true)
                || !isset($rows[$ref['message_id']]) || ($rows[$ref['message_id']]['cart'] ?? null) === null) {
                return self::result(false, 'INVALID_EVIDENCE');
            }
            $line = self::cartLine($rows[$ref['message_id']]['cart'], $ref['product_retailer_id']);
            $key = $ref['message_id'] . ':' . $ref['product_retailer_id'];
            if ($line === null || isset($cartReferences[$key])) return self::result(false, 'INVALID_EVIDENCE');
            $cartReferences[$key] = true;
        }
        $locationId = $data['evidence']['location_id'];
        if ($locationId !== null && (!is_string($locationId) || !isset($rows[$locationId])
            || $rows[$locationId]['speaker'] !== 'customer' || $rows[$locationId]['location'] === null)) {
            return self::result(false, 'INVALID_EVIDENCE');
        }
        $evidenceIds = array_merge($data['evidence']['request_ids'], $data['evidence']['confirmation_ids']);
        $texts = array_map(static fn ($id) => self::rowText($rows[$id]), $evidenceIds);
        foreach (['name', 'address', 'area', 'notes'] as $field) {
            if ($data['customer'][$field] !== null && !self::backed($data['customer'][$field], $texts)) {
                return self::result(false, 'INVALID_EVIDENCE');
            }
        }
        if ($data['customer']['phone'] !== null) {
            $phone = self::digits($data['customer']['phone']);
            if (!preg_match('/\A[0-9]{8,15}\z/', $phone)
                || !self::phoneBacked($phone, $texts)) {
                return self::result(false, 'INVALID_EVIDENCE');
            }
        }
        if ($data['branch_hint'] !== null && !self::backed($data['branch_hint'], $texts)) {
            return self::result(false, 'INVALID_EVIDENCE');
        }
        foreach ($data['items'] as $item) {
            if (!self::backed($item['name'], $texts)
                || ($item['option_hint'] !== null && !self::backed($item['option_hint'], $texts))) {
                return self::result(false, 'INVALID_EVIDENCE');
            }
        }
        $confirmationTexts = array_map(static fn ($id) => self::rowText($rows[$id]), $data['evidence']['confirmation_ids']);
        if ($data['approximate_total'] !== null && !self::priceBacked($data['approximate_total'], $confirmationTexts)) {
            return self::result(false, 'INVALID_EVIDENCE');
        }
        if ($data['fulfillment'] !== 'UNKNOWN' && count($evidenceIds) === 0) {
            return self::result(false, 'INVALID_EVIDENCE');
        }
        if ($data['decision'] === 'CONFIRMED' && !self::confirmation($data, $rows)) {
            return self::result(false, 'INVALID_EVIDENCE');
        }
        if ($data['decision'] === 'CANCELLED' && count($data['evidence']['request_ids']) === 0) {
            return self::result(false, 'INVALID_EVIDENCE');
        }
        return self::result(true, null, $data);
    }

    private static function confirmation(array $data, array $rows): bool
    {
        if (count($data['items']) === 0 || !$data['evidence']['request_ids']
            || !$data['evidence']['confirmation_ids'] || self::multipleOrders($rows)) {
            return false;
        }
        $summary = null;
        $summaryTexts = [];
        $summaryMarker = false;
        foreach ($data['evidence']['confirmation_ids'] as $id) {
            $text = $rows[$id]['text'];
            if (self::instruction($text) || self::tentative($text) || self::negativeSummary($text)) {
                return false;
            }
            $summaryMarker = $summaryMarker || self::finalSummary($text);
            if ($summary === null || strcmp($rows[$id]['sent_at'], $summary) > 0) {
                $summary = $rows[$id]['sent_at'];
            }
            $summaryTexts[] = $text;
        }
        if (!$summaryMarker) {
            return false;
        }
        $committingRequest = null;
        $requestTexts = [];
        $cartRows = [];
        foreach ($data['evidence']['request_ids'] as $id) {
            $requestText = self::rowText($rows[$id]);
            if (self::instruction($requestText) || self::negatedRequest($requestText)) {
                return false;
            }
            if (strcmp($rows[$id]['sent_at'], $summary) >= 0) {
                return false;
            }
            $requestTexts[] = $requestText;
            if (($rows[$id]['cart'] ?? null) !== null) $cartRows[$id] = $rows[$id]['cart'];
            if ((self::commitment($requestText) || ($rows[$id]['cart'] ?? null) !== null) && strcmp($rows[$id]['sent_at'], $summary) < 0
                && ($committingRequest === null || strcmp($rows[$id]['sent_at'], $committingRequest) > 0)) {
                $committingRequest = $rows[$id]['sent_at'];
            }
        }
        $accounted = [];
        foreach ($data['items'] as $item) {
            $ref = $item['cart_reference'] ?? null;
            $cartBacked = $ref !== null && isset($cartRows[$ref['message_id']])
                && self::cartLine($cartRows[$ref['message_id']], $ref['product_retailer_id']) !== null;
            if ((!$cartBacked && !self::backed($item['name'], $requestTexts)) || !self::backed($item['name'], $summaryTexts)) {
                return false;
            }
            if ($item['option_hint'] !== null && (!self::optionBacked($item, $data['items'], $summaryTexts)
                || (!$cartBacked && !self::optionBacked($item, $data['items'], $requestTexts)))) return false;
            if ($item['quantity'] !== null && ((!$cartBacked && !self::quantityBacked($item, $data['items'], $requestTexts))
                || !self::quantityBacked($item, $data['items'], $summaryTexts))) {
                return false;
            }
            if ($cartBacked) $accounted[$ref['message_id']][$ref['product_retailer_id']] = true;
        }
        if ($data['customer']['phone'] !== null && !self::summaryPhoneConsistent($data['customer']['phone'], $summaryTexts)) return false;
        foreach ($cartRows as $id => $cart) foreach ($cart['product_items'] as $line) {
            if (!isset($accounted[$id][$line['product_retailer_id']])) return false;
        }
        // One submitted cart per order; repeated/changed carts need a fresh matching summary.
        if (count($cartRows) > 1) return false;
        $positions = array_flip(array_keys($rows));
        $cartPosition = $cartRows ? $positions[array_key_first($cartRows)] : null;
        $finalPosition = max(array_map(static fn ($id) => $positions[$id], $data['evidence']['confirmation_ids']));
        foreach ($rows as $row) {
            if ($row['speaker'] === 'business' && $positions[$row['id']] > $finalPosition
                && self::pendingHandoff($row['text'] ?? '')) return false;
            if ($row['speaker'] === 'customer' && ($row['cart'] ?? null) !== null
                && $cartPosition !== null && $positions[$row['id']] > $cartPosition) return false;
            if ($row['speaker'] === 'business' && strcmp($row['sent_at'], $summary) > 0
                && self::negativeSummary($row['text'] ?? '')) {
                return false;
            }
            if ($row['speaker'] === 'customer' && strcmp($row['sent_at'], $committingRequest ?? $summary) > 0
                && (self::negatedRequest($row['text'] ?? '')
                    || preg_match('/(?:بدل|بدّل|غيّر|غير الطلب|\b(?:change|instead)\b)/iu', $row['text'] ?? '')
                    || ($row['cart'] ?? null) !== null)) {
                return false;
            }
        }
        // Native business summaries may conclude an explicitly requested order without another reply.
        if ($committingRequest !== null) {
            return true;
        }
        foreach ($data['evidence']['customer_acceptance_ids'] as $id) {
            if (strcmp($rows[$id]['sent_at'], $summary) > 0 && !self::instruction($rows[$id]['text'])
                && preg_match('/(?:تمام|موافق|أ[ك]?كد|اكد|أكد|اتفقنا|تأكيد|yes|confirm|\bok(?:ay)?\b)/iu', $rows[$id]['text'])) {
                return true;
            }
        }
        return false;
    }

    private static function multipleOrders(array $rows): bool
    {
        $pendingRequest = false;
        $summaries = 0;
        foreach ($rows as $row) {
            $text = $row['text'] ?? '';
            if ($row['speaker'] === 'customer' && (self::commitment($text) || ($row['cart'] ?? null) !== null) && !self::instruction($text)) {
                $pendingRequest = true;
            }
            if ($row['speaker'] === 'business' && $pendingRequest
                && preg_match('/(?:تم\s+(?:تأكيد|تاكيد|اعتماد)|(?:تأكيد|تاكيد)\s+(?:طلب|الطلب|الأوردر|الاوردر)|'
                    . 'order\s+confirmation|(?:your\s+)?order\s+(?:is\s+)?confirmed)/iu', $text)) {
                $summaries++;
                $pendingRequest = false;
            }
        }
        return $summaries > 1;
    }

    private static function rowText(array $row): string
    {
        return implode("\n", array_filter([$row['text'] ?? null, $row['cart']['text'] ?? null], 'is_string'));
    }

    private static function cartLine(array $cart, string $retailer): ?array
    {
        foreach ($cart['product_items'] as $line) if ($line['product_retailer_id'] === $retailer) return $line;
        return null;
    }

    private static function commitment(string $text): bool
    {
        return !self::negatedRequest($text) && !self::tentative($text)
            && preg_match('/(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|موافق.{0,20}(?:الطلب|الأوردر|الاوردر)|'
            . 'أكد.{0,20}(?:الطلب|الأوردر|الاوردر)|\b(?:want|order|send|confirm)\b)/iu', $text) === 1;
    }

    private static function negatedRequest(string $text): bool
    {
        return preg_match('/(?:مش\s+(?:عايز|عاوز|موافق)|لا\s+(?:أريد|اريد|تأكد|تاكد|تؤكد|ترسل)|'
            . 'إلغ|الغ|لغي|\b(?:do\s+not|don[’\x27]t|not\s+want|cancel|never\s+(?:send|order))\b)/iu', $text) === 1;
    }

    private static function tentative(string $text): bool
    {
        return preg_match('/[?؟]|(?:تحب|هل|عايزني|عاوزني|ممكن).{0,45}(?:أؤكد|اؤكد|اكد|أكد|تأكيد|تاكيد|الطلب|الأوردر|الاوردر)|'
            . '\b(?:would\s+you|shall\s+I|do\s+you|please\s+confirm|pending\s+confirmation)\b/iu', $text) === 1;
    }

    private static function finalSummary(string $text): bool
    {
        return !self::tentative($text) && !self::negativeSummary($text)
            && preg_match('/(?:تم\s+(?:تأكيد|تاكيد|اعتماد)|(?:تأكيد|تاكيد)\s+(?:طلب|الطلب|الأوردر|الاوردر)|'
            . 'order\s+confirmation|(?:your\s+)?order\s+(?:is\s+)?confirmed)/iu', $text) === 1;
    }

    private static function negativeSummary(string $text): bool
    {
        return self::pendingHandoff($text) || preg_match('/(?:إلغ|الغ|لغي|اتلغ|ملغ|(?:^|\s)(?:لم|لن|لا|مش|لسه|لو|إذا|اذا|لما)\s).{0,70}(?:طلب|أوردر|اوردر|تأك|تاك|أكّد|اكد|نأكد|اتأكد)|'
            . '(?:طلب|أوردر|اوردر).{0,40}(?:إلغ|الغ|لغي|اتلغ|ملغ)|'
            . '\b(?:not\s+confirmed|unconfirmed|cancelled|canceled|if|unless|pending)\b/iu', $text) === 1;
    }

    /** A transfer for future verification is not a final order, even under a confirmation heading. */
    private static function pendingHandoff(string $text): bool
    {
        return preg_match('/\b(?:we|they|our\s+team|the\s+team|team)\s*(?:will|[’\x27]ll)\s+'
            . '(?:verify|review|check|confirm)\b.{0,100}\b(?:order|details|information|it)\b|'
            . '\b(?:connected|transferred|passed|forwarded)\b.{0,80}\b(?:team|staff|colleague)\b'
            . '.{0,80}\bto\s+(?:verify|review|check|confirm)\b.{0,80}\b(?:order|details|it)\b|'
            . '(?:إرسال|ارسال|تحويل|إحالة|احالة).{0,80}(?:طلب|أوردر|اوردر).{0,80}'
            . '(?:فريق|موظف|زميل).{0,80}(?:لمراجع|للمراجع|للتحقق|لتأكيد|للتأكيد|للتاكيد|وتأكيد)/ius', $text) === 1;
    }

    private static function instruction(string $text): bool
    {
        return preg_match('/(?:ignore\s+(?:all|previous|above)|system\s+prompt|developer\s+message|'
            . 'تجاهل\s+(?:التعليمات|كل)|تعليمات\s+(?:النظام|المطور)|(?:return|decision).{0,30}CONFIRMED)/iu', $text) === 1;
    }

    private static function backed(string $value, array $texts): bool
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        foreach ($texts as $text) {
            $text = preg_replace('/\s+/u', ' ', $text);
            if (preg_match('/' . preg_quote($value, '/') . '/iu', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function keys(array $array, array $keys): bool
    {
        $actual = array_keys($array);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private static function length(string $value): int
    {
        return preg_match_all('/./us', $value) ?: 0;
    }

    private static function string($value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && self::length($value) <= $max;
    }

    private static function nullableString($value, int $max): bool
    {
        return $value === null || self::string($value, $max);
    }

    private static function decimal($value, string $pattern, bool $positive): bool
    {
        return $value === null || (is_string($value) && preg_match($pattern, $value) === 1
            && (!$positive || (float) $value > 0));
    }

    private static function coordinate($value, int $limit): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= $limit;
    }

    private static function date(string $value): bool
    {
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
            return $date !== false && $date->format('Y-m-d H:i:s') === $value;
        } catch (Throwable $error) {
            return false;
        }
    }

    private static function asciiDigits(string $value): string
    {
        return strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5',
            '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    private static function digits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', self::asciiDigits($value));
    }

    private static function phoneBacked(string $phone, array $texts): bool
    {
        foreach ($texts as $text) {
            preg_match_all('/(?<![0-9])\+?[0-9](?:[ .()\-]?[0-9]){7,14}(?![0-9])/', self::asciiDigits($text), $matches);
            foreach ($matches[0] as $candidate) {
                if (self::canonicalPhone(self::digits($candidate)) === self::canonicalPhone($phone)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function canonicalPhone(string $phone): string
    {
        if (preg_match('/\A00201[0125][0-9]{8}\z/', $phone)) $phone = substr($phone, 2);
        return preg_match('/\A01[0125][0-9]{8}\z/', $phone) ? '20' . substr($phone, 1) : $phone;
    }

    /** A final explicit callback cannot be replaced by an older customer number. */
    private static function summaryPhoneConsistent(string $phone, array $texts): bool
    {
        $wanted = self::canonicalPhone(self::digits($phone));
        foreach ($texts as $text) {
            $text = self::asciiDigits($text);
            preg_match_all('/(?<![0-9])\+?[0-9](?:[ .()\-]?[0-9]){7,14}(?![0-9])/', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$candidate, $offset]) {
                $digits = self::digits($candidate); $canonical = self::canonicalPhone($digits);
                $prefix = substr($text, max(0, $offset - 100), min(100, $offset));
                $explicit = preg_match('/(?:رقم(?:\s+(?:الهاتف|هاتف|التليفون|تليفون|الموبايل|موبايل|التواصل|العميل))?|رقمي|'
                    . 'هاتف|موبايل|phone|mobile|telephone|callback|contact\s+number)\s*[:：\-]?\s*\z/iu', $prefix) === 1;
                if (($explicit || preg_match('/\A201[0125][0-9]{8}\z/', $canonical)) && $canonical !== $wanted) return false;
            }
        }
        return true;
    }

    /** Unique product clauses are shared by quantity and option provenance checks. */
    private static function itemClauses(array $item, array $items, array $texts): array
    {
        $result = [];
        foreach ($texts as $text) {
            $text = str_replace('٫', '.', self::asciiDigits($text));
            $clauses = preg_split('/[\n;؛،,!?؟]+|(?<![0-9])\.(?![0-9])|\s+(?:and|&)\s+|'
                . '\s+و(?=\s*(?:[0-9]|نص|نصف|ربع|كيلو|جرام|قطعة|علبة))/iu', $text);
            foreach ($clauses as $clause) {
                $clause = preg_replace('/\s+/u', ' ', trim($clause));
                preg_match_all('/' . preg_quote($item['name'], '/') . '/iu', $clause, $names, PREG_OFFSET_CAPTURE);
                if (!$names[0]) continue;
                $otherItem = false;
                foreach ($items as $other) {
                    if ($other['name'] === $item['name']) continue;
                    preg_match_all('/' . preg_quote($other['name'], '/') . '/iu', $clause, $others, PREG_OFFSET_CAPTURE);
                    foreach ($others[0] as $otherName) {
                        $withinName = false;
                        foreach ($names[0] as $name) $withinName = $withinName || ($otherName[1] >= $name[1]
                            && $otherName[1] + strlen($otherName[0]) <= $name[1] + strlen($name[0]));
                        if (!$withinName) $otherItem = true;
                    }
                }
                $result[] = ['text' => $clause, 'names' => $names[0], 'shared' => $otherItem];
            }
        }
        return $result;
    }

    /** Exact positive live labels tied to one item; null means attribution is ambiguous. */
    public static function optionClauseLabels(array $item, array $items, array $texts, array $liveLabels): ?array
    {
        if (!self::string($item['name'] ?? null, 200) || count($items) > self::MAX_ITEMS
            || count($texts) > self::MAX_MESSAGES || count($liveLabels) > 100) return null;
        foreach ($items as $other) if (!is_array($other) || !self::string($other['name'] ?? null, 200)) return null;
        foreach ($texts as $text) if (!is_string($text) || strlen($text) > 65536) return null;
        foreach ($liveLabels as $label) if (!self::string($label, 200)) return null;
        $found = []; $declined = []; $sharedDecline = false;
        foreach (self::itemClauses($item, $items, $texts) as $part) {
            $hits = [];
            foreach (array_unique($liveLabels) as $label) {
                preg_match_all('/(?<![\p{L}\p{N}])(?:ب)?(' . preg_quote($label, '/') . ')(?![\p{L}\p{N}])/iu',
                    $part['text'], $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$match, $offset]) {
                    if (self::overlapsName($offset, strlen($match), $part['names'])) continue;
                    $before = substr($part['text'], 0, $offset);
                    $after = substr($part['text'], $offset + strlen($match));
                    // A generic cleaning label is not proof of the distinct extra-cleaning choice.
                    if ($label === 'تنظيف' && preg_match('/\A\s+(?:إضافي|اضافي)(?![\p{L}\p{N}])/u', $after)) continue;
                    $negated = preg_match('/(?:بدون|دون|من\s+غير|غير|لا|مش|ليس|not|no|without)\s*(?:(?:أي|اي)\s+|ب)?\z/iu', $before) === 1;
                    $hits[] = ['label' => $label, 'offset' => $offset, 'length' => strlen($match), 'negated' => $negated];
                }
            }
            foreach ($hits as $hit) {
                $contained = false;
                foreach ($hits as $other) if ($other['length'] > $hit['length'] && $hit['offset'] >= $other['offset']
                    && $hit['offset'] + $hit['length'] <= $other['offset'] + $other['length']) $contained = true;
                if ($contained) continue;
                if ($hit['negated']) {
                    $declined[$hit['label']] = true; $sharedDecline = $sharedDecline || $part['shared']; continue;
                }
                if ($part['shared']) return null;
                if (preg_match('/(?<![\p{L}\p{N}])(?:أو|او|أم|ام|or)(?![\p{L}\p{N}])/iu', $part['text'])) return null;
                $found[$hit['label']] = true;
            }
        }
        if (array_intersect_key($found, $declined) || ($found && $sharedDecline)) return null;
        return array_keys($found);
    }

    private static function optionBacked(array $item, array $items, array $texts): bool
    {
        $hint = $item['option_hint'];
        $labels = array_values(array_unique([$hint, 'تنظيف', 'تنظيف إضافي', 'تغليف مفرغ']));
        $proof = self::optionClauseLabels($item, $items, $texts, $labels);
        if ($proof === null || !in_array($hint, $proof, true)) return false;
        if (in_array($hint, ['تنظيف', 'تنظيف إضافي', 'تغليف مفرغ'], true)) {
            foreach ($proof as $label) if ($label !== $hint && in_array($label, ['تنظيف', 'تنظيف إضافي', 'تغليف مفرغ'], true)) return false;
        }
        return true;
    }

    /** A classification never proves a guessed quantity or a unit selected by the model. */
    private static function quantityBacked(array $item, array $items, array $texts): bool
    {
        if (!in_array($item['quantity_mode'], ['piece', 'weight'], true)) {
            return false;
        }
        $wanted = self::scaled($item['quantity'], 3);
        if ($item['quantity_mode'] === 'piece' && $wanted % 1000 !== 0) {
            return false;
        }
        $proofs = [];
        foreach (self::itemClauses($item, $items, $texts) as $part) {
            if ($part['shared']) continue;
            $amounts = self::clauseAmounts($part['text'], $part['names']);
            if (count($amounts) > 1) return false;
            if (count($amounts) === 1) $proofs[] = $amounts[0];
        }
        if (!$proofs) {
            return false;
        }
        foreach ($proofs as $proof) {
            if ($proof['quantity'] !== $wanted || $proof['mode'] !== $item['quantity_mode']) {
                return false;
            }
        }
        return true;
    }

    private static function clauseAmounts(string $clause, array $names): array
    {
        $units = '(?:كيلو(?:جرام|غرام)?|كجم|كغ|kilograms?|kilos?|kg|جرام|غرام|جم|grams?|g|'
            . 'قطعة|قطع|سمكة|سمكات|علبة|علب|عدد|وجبة|وجبات|ساندوتش|سندوتش|ساندوتشات|سندوتشات|'
            . 'pieces?|pcs|units?|meals?|sandwich(?:es)?)';
        $numbers = '(?:[0-9]+(?:\.[0-9]{1,3})?|نصف|نص|ربع|half|quarter)';
        $pattern = '/(?<![\p{L}\p{N}.])(' . $numbers . ')\s*(' . $units . ')(?![\p{L}\p{N}])/iu';
        preg_match_all($pattern, $clause, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $amounts = [];
        $covered = [];
        foreach ($matches as $match) {
            $covered[] = [$match[0][1], $match[0][1] + strlen($match[0][0])];
            if (!self::overlapsName($match[0][1], strlen($match[0][0]), $names)) {
                $amount = self::unitAmount($match[1][0], $match[2][0]);
                if ($amount !== null) {
                    $amounts[] = $amount;
                }
            }
        }
        // An unqualified singular unit is one; never reuse the unit of an explicit amount.
        preg_match_all('/(?<![\p{L}\p{N}])(' . $units . ')(?![\p{L}\p{N}])/iu', $clause, $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $insideExplicit = false;
            foreach ($covered as $range) {
                $insideExplicit = $insideExplicit || ($match[0][1] >= $range[0] && $match[0][1] < $range[1]);
            }
            if ($insideExplicit || self::overlapsName($match[0][1], strlen($match[0][0]), $names)) {
                continue;
            }
            // Plural/abbreviated units without a number are ambiguous.
            if (!preg_match('/\A(?:كيلو(?:جرام|غرام)?|قطعة|سمكة|علبة|وجبة|ساندوتش|سندوتش|kilogram|kilo|piece|unit|meal|sandwich)\z/iu', $match[1][0])) {
                continue;
            }
            $amounts[] = self::unitAmount('1', $match[1][0]);
        }
        return $amounts;
    }

    private static function overlapsName(int $start, int $length, array $names): bool
    {
        foreach ($names as $name) {
            if ($start < $name[1] + strlen($name[0]) && $start + $length > $name[1]) {
                return true;
            }
        }
        return false;
    }

    private static function unitAmount(string $number, string $unit): ?array
    {
        if (preg_match('/\A(?:نصف|نص|half)\z/iu', $number)) {
            $quantity = 500;
        } elseif (preg_match('/\A(?:ربع|quarter)\z/iu', $number)) {
            $quantity = 250;
        } elseif (preg_match(self::QUANTITY_PATTERN, $number)) {
            $quantity = self::scaled($number, 3);
        } else {
            return null;
        }
        if ($quantity <= 0) {
            return null;
        }
        if (preg_match('/\A(?:كيلو(?:جرام|غرام)?|كجم|كغ|kilograms?|kilos?|kg)\z/iu', $unit)) {
            return ['quantity' => $quantity, 'mode' => 'weight'];
        }
        if (preg_match('/\A(?:جرام|غرام|جم|grams?|g)\z/iu', $unit)) {
            return $quantity % 1000 === 0 ? ['quantity' => intdiv($quantity, 1000), 'mode' => 'weight'] : null;
        }
        return ['quantity' => $quantity, 'mode' => 'piece'];
    }

    private static function scaled(string $value, int $places): int
    {
        $parts = explode('.', $value, 2);
        return (int) $parts[0] * (10 ** $places) + (int) str_pad($parts[1] ?? '', $places, '0');
    }

    /** Only final business money evidence counts; phones, quantities, and substring digits do not. */
    private static function priceBacked(string $value, array $texts): bool
    {
        $wanted = self::scaled($value, 2);
        $labelled = [];
        $currency = [];
        foreach ($texts as $text) {
            $text = str_replace(['٫', '٬'], ['.', ''], self::asciiDigits($text));
            preg_match_all('/(?<![0-9.])(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?(?![0-9.])/', $text, $matches,
                PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                $before = substr($text, 0, $match[1]);
                $after = substr($text, $match[1] + strlen($match[0]));
                $amount = self::scaled($match[0], 2);
                if (preg_match('/(?:الإجمالي|الاجمالي|إجمالي|اجمالي|المجموع|قيمة الطلب|السعر|التكلفة|total|amount|price)'
                    . '\s*(?:[:=]\s*)?(?:(?:التقريبي|تقريبي|تقريبًا|تقريبا|approximately|approx\.?)\s*)?\z/iu', $before)) {
                    $labelled[] = $amount;
                } elseif (preg_match('/\A\s*(?:جنيه(?:ا|ًا)?|ج\.?|EGP|L\.?E\.?|pounds?)(?![\p{L}\p{N}])/iu', $after)) {
                    $currency[] = $amount;
                }
            }
        }
        $proofs = $labelled ?: $currency;
        return count($proofs) > 0 && count(array_filter($proofs, static fn ($amount) => $amount !== $wanted)) === 0;
    }
}
