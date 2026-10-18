<?php

namespace App\Support;

/**
 * A separate customer-authored recap protocol. This never manufactures business
 * messages or the extraction schema's confirmation_ids, and authorizes no ERP write.
 * Prices are customer-quoted hints; native identity, catalog, location, freshness,
 * scope, schedule and duplicate-order checks remain the caller's responsibility.
 */
final class WhatsAppCustomerOrderConfirmation
{
    public const TYPE = 'CUSTOMER_CONFIRMED_RECAP';
    public const VERSION = 1;
    public const SELF_CONFIRMED_BASIS = 'CUSTOMER_SELF_CONFIRMED_RECAP';
    public const BRANCH_SUMMARY_FORMAT = 'BRANCH_ORDER_SUMMARY';
    public const LABELED_BRANCH_SUMMARY_FORMAT = 'LABELED_BRANCH_ORDER_SUMMARY';
    private const MAX_TEXT = 12000;
    private const MAX_ITEMS = 30;

    /**
     * Inspect an authentic ordered snapshot. A copied instruction containing an
     * approval phrase is not approval: every format requires the customer's
     * separate whole-message "كل شئ تمام" after the complete customer recap.
     * Ordinary typed conversation is not order evidence or a replacement recap.
     * All returned IDs still name original customer rows.
     */
    public static function inspect(array $transcript): array
    {
        if (!self::transcript($transcript)) return self::result(false, 'TRANSCRIPT_INVALID');
        $recap = null;
        foreach ($transcript as $position => $row) {
            if ($row['speaker'] !== 'customer' || !is_string($row['text'])) continue;
            $kind = self::headerKind($row['text']);
            if ($kind !== null) {
                // The owner's protocol uses the last customer receipt. Earlier
                // versions are superseded; a malformed latest receipt must fail
                // below rather than falling back to any older confirmation.
                $recap = ['row' => $row, 'position' => $position];
            }
        }
        if ($recap === null) return self::result(false, 'NO_CUSTOMER_RECAP');
        $parsed = self::parseRecap($recap['row']['text']);
        if (!$parsed['ok']) return $parsed;
        $branchSummary = ($parsed['format'] ?? null) === self::BRANCH_SUMMARY_FORMAT;
        $labeledBranchSummary = ($parsed['format'] ?? null) === self::LABELED_BRANCH_SUMMARY_FORMAT;
        $approval = null;
        $inlinePoint = $parsed['data']['textual_location']['point'] ?? null;
        if (($recap['row']['cart'] ?? null) !== null
            || ($recap['row']['location'] !== null && ($inlinePoint === null || !self::samePoint($recap['row']['location'], $inlinePoint))))
            return self::result(false, 'CUSTOMER_CHANGED_AFTER_RECAP');
        foreach ($transcript as $position => $row) {
            if ($position <= $recap['position']) continue;
            $changedReason = $row['speaker'] === 'customer' ? 'CUSTOMER_CHANGED_AFTER_RECAP'
                : ($approval === null ? 'INTERVENING_TURN' : 'BUSINESS_TURN_AFTER_APPROVAL');
            if (($row['cart'] ?? null) !== null) return self::result(false, $changedReason);
            if ($row['speaker'] === 'customer') {
                // This separately approved summary format receives its native
                // delivery pin before the customer's distinct approval. The
                // workflow must authenticate and bind that pin; it is never
                // manufactured as an inline point or treated as approval here.
                if ($branchSummary && $approval === null && $row['text'] === null
                    && $row['location'] !== null && ($row['cart'] ?? null) === null) continue;
                if ($row['text'] === null && $row['location'] !== null && $inlinePoint !== null
                    && self::samePoint($row['location'], $inlinePoint)) continue;
                if ($row['text'] === null || $row['location'] !== null || self::explicitOrderChange($row['text']))
                    return self::result(false, $changedReason);
                // Only this distinct exact phrase is approval. Ordinary chat,
                // including other acknowledgements, is not order authority.
                if (self::positiveApproval($row['text'])) $approval ??= $row;
            } else {
                // Text from the business is never customer approval. Unknown
                // native media, carts and points remain outside this text policy.
                if ($row['text'] === null || $row['location'] !== null)
                    return self::result(false, $changedReason);
            }
        }
        if ($approval === null) return self::result(false, 'CUSTOMER_APPROVAL_REQUIRED');
        $data = $parsed['data'];
        $data['decision'] = 'CONFIRMED';
        if ($data['textual_location'] !== null) $data['textual_location']['source_message_id'] = $recap['row']['id'];
        $receipt = [
            'version' => self::VERSION,
            'recap_id' => $recap['row']['id'],
            'approval_id' => $approval['id'],
            'recap_sha256' => hash('sha256', $recap['row']['text']),
            'approval_sha256' => hash('sha256', $approval['text']),
        ];
        if ($branchSummary) $receipt['summary_format'] = self::BRANCH_SUMMARY_FORMAT;
        if ($labeledBranchSummary) $receipt['summary_format'] = self::LABELED_BRANCH_SUMMARY_FORMAT;
        $receipt['receipt_sha256'] = hash('sha256', json_encode([self::TYPE, $receipt], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $data['evidence_receipt'] = $receipt;
        return self::result(true, null, $data);
    }

    /** Parse the documented labeled format without treating its contents as approval. */
    public static function parseRecap(string $text): array
    {
        if (!self::bounded($text, self::MAX_TEXT) || preg_match('/\bWA-[0-9]{4}\b/i', $text))
            return self::result(false, 'RECAP_TEXT_INVALID');
        $raw = preg_split('/\R/u', $text);
        if (!is_array($raw) || count($raw) > 100) return self::result(false, 'RECAP_TEXT_INVALID');
        $lines = [];
        foreach ($raw as $line) {
            $line = self::displayLine($line);
            if ($line !== '') $lines[] = $line;
        }
        $kind = self::headerKind($text);
        if ($kind === self::LABELED_BRANCH_SUMMARY_FORMAT) return self::parseLabeledBranchSummary($raw);
        if ($kind === self::BRANCH_SUMMARY_FORMAT) return self::parseBranchSummary($lines);
        if (in_array($kind, ['SELF_CONFIRMED_COMPACT', 'MIXED'], true)) return self::parseCompact($text, $lines);
        $header = null;
        foreach ($lines as $index => $line) if (preg_match('/\A(?:ملخص طلبك|ملخص الطلب النهائي|تأكيد الطلب النهائي)\s*[:：]\z/u', $line)) {
            if ($header !== null) return self::result(false, 'RECAP_DUPLICATE_HEADER');
            $header = $index;
        }
        if ($header === null || $header > 2) return self::result(false, 'RECAP_HEADER_REQUIRED');
        foreach (array_slice($lines, 0, $header) as $line) {
            if (!preg_match('/\A(?:تمام|شكر[اًًا]*|شكرا|أهلا|اهلا)(?:\s|،|,|$).*\z/u', $line)
                || preg_match('/[?؟:：0-9٠-٩۰-۹]/u', $line)) return self::result(false, 'RECAP_PREFIX_INVALID');
        }
        $fields = []; $items = []; $total = null; $footer = []; $inFooter = false;
        foreach (array_slice($lines, $header + 1) as $line) {
            if ($inFooter) { $footer[] = $line; continue; }
            if (preg_match('/\A(?:الإجمالي|الاجمالي|الإجمالى|الاجمالى|المجموع)(?:\s+التقريبي)?\s*[:：]?\s*(.+)\z/u', $line, $m)) {
                if ($total !== null || $items === []) return self::result(false, 'RECAP_TOTAL_INVALID');
                $money = self::money($m[1], true);
                if ($money === null) return self::result(false, 'RECAP_TOTAL_INVALID');
                $total = $money; continue;
            }
            if (preg_match('/\A(الاسم|اسم العميل|رقم الهاتف|رقم التواصل|الهاتف|التليفونات|التليفون|التلفونات|أرقام التواصل|ارقام التواصل|العنوان|عنوان التوصيل|المنطقة|المنطقه|الفرع|اللوكيشن|لوكيشن|الموقع|الإحداثيات|الاحداثيات|خط العرض|خط الطول|الملاحظات|ملاحظات)\s*[:：]\s*(.+)\z/u', $line, $m)) {
                if ($total === null) return self::result(false, 'RECAP_FIELD_ORDER_INVALID');
                $key = self::fieldKey($m[1]);
                if (array_key_exists($key, $fields)) return self::result(false, 'RECAP_DUPLICATE_FIELD');
                $fields[$key] = trim($m[2]); continue;
            }
            if ($total === null) {
                $item = self::item($line);
                if ($item === null || count($items) >= self::MAX_ITEMS) return self::result(false, 'RECAP_ITEM_INVALID');
                $items[] = $item; continue;
            }
            $inFooter = true; $footer[] = $line;
        }
        if (!$items || $total === null || !isset($fields['name'], $fields['phone'], $fields['address']))
            return self::result(false, 'RECAP_REQUIRED_FIELDS_MISSING');
        if (preg_match('/(?:تيك\s+اواي|تيك\s+اوي|داخل الفرع|استلام من الفرع|في الصاله|في المطعم|dine[ -]?in|take[ -]?away|pickup)/iu', self::matchingText($text)))
            return self::result(false, 'RECAP_FULFILLMENT_AMBIGUOUS');
        foreach (['name' => 200, 'address' => 1000, 'area' => 200, 'branch' => 200, 'notes' => 1000] as $key => $limit)
            if (isset($fields[$key]) && !self::bounded($fields[$key], $limit)) return self::result(false, 'RECAP_FIELD_INVALID');
        if (!self::footer(implode(' ', $footer))) return self::result(false, 'RECAP_FOOTER_INVALID');
        $phones = self::phones($fields['phone']);
        if ($phones === null) return self::result(false, 'RECAP_PHONE_INVALID');
        if (isset($fields['location']) && (isset($fields['latitude']) || isset($fields['longitude'])))
            return self::result(false, 'RECAP_LOCATION_CONFLICT');
        $location = null;
        if (isset($fields['location'])) {
            $location = self::location($fields['location']);
            if ($location === null) return self::result(false, 'RECAP_LOCATION_INVALID');
        } elseif (isset($fields['latitude']) || isset($fields['longitude'])) {
            if (!isset($fields['latitude'], $fields['longitude'])) return self::result(false, 'RECAP_LOCATION_INVALID');
            $literal = 'خط العرض: '.$fields['latitude'].'، خط الطول: '.$fields['longitude'];
            $location = self::location($literal);
            if ($location === null) return self::result(false, 'RECAP_LOCATION_INVALID');
        }
        $names = [];
        foreach ($items as $item) {
            $identity = self::matchingText($item['name']);
            if (isset($names[$identity])) return self::result(false, 'RECAP_DUPLICATE_ITEM');
            $names[$identity] = true;
        }
        return self::result(true, null, [
            'type' => self::TYPE,
            'decision' => 'DRAFT',
            'customer' => ['name' => $fields['name'], 'phone' => $phones[0], 'address' => $fields['address'],
                'area' => $fields['area'] ?? null, 'notes' => $fields['notes'] ?? null],
            // The documented protocol chooses the first listed contact. Retain all
            // alternates, rather than inferring a preferred one from a profile.
            'alternate_phones' => array_slice($phones, 1),
            'branch_hint' => $fields['branch'] ?? null,
            'fulfillment' => 'DELIVERY',
            'items' => $items,
            'approximate_total' => $total,
            'textual_location' => $location,
            'evidence_receipt' => null,
        ]);
    }

    public static function positiveApproval($text): bool
    {
        if (!is_string($text) || !self::bounded($text, 100)) return false;
        $text = self::matchingText(self::displayLine($text));
        return preg_match('/\Aكل (?:شيء|شئ) تمام[.!]?\z/u', $text) === 1;
    }

    private static function explicitOrderChange(string $text): bool
    {
        if (!self::bounded($text, 1000)) return false;
        $text = self::matchingText(self::displayLine($text));
        // A whole acknowledgement followed by "but" and an explicit imperative
        // is a change, while ordinary thanks after the same prefix is not.
        if (preg_match('/\A(?:(?:تمام|كل (?:شيء|شئ) تمام) )?بس (.+)\z/u', $text, $match)) $text = $match[1];
        // Standalone imperatives, not a substring search through ordinary chat.
        return preg_match('/\A(?:(?:الغي|الغ|الغاء|عايز (?:الغي|الغ)|مش عايز|متبعتش) (?:الطلب|الاوردر)(?: (?:لو سمحت|من فضلك))?|(?:لا|مت|ما) (?:تنفذ|ترسل|تبعت) (?:الطلب|الاوردر)|خلي (?:الطلب|الاوردر|الصنف|الكميه|العنوان|الرنجه|السردين|الفسيخ) [\p{Arabic}0-9][\p{Arabic}0-9 ,،.()\-]{0,190}|(?:بدل|عدل|غير|زود|قلل|احذف|شيل) [\p{Arabic}0-9][\p{Arabic}0-9 ,،.()\-]{0,190})[.!]?\z/u', $text) === 1;
    }

    /** Shared display normalization; the returned format name grants no approval. */
    public static function headerKind(string $text): ?string
    {
        $kind = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $line = self::displayLine($rawLine);
            $found = preg_match('/\Aطلبك المؤكد\s*[:：]\z/u', $line) ? 'SELF_CONFIRMED_COMPACT'
                : (preg_match('/\A(?:ملخص طلبك|ملخص الطلب النهائي|تأكيد الطلب النهائي)\s*[:：]\z/u', $line) ? 'LEGACY' : null);
            if ($found === null && self::branchSummaryHeader($line) !== null) $found = self::BRANCH_SUMMARY_FORMAT;
            if ($found === null && self::labeledBranchHeader($rawLine) !== null) $found = self::LABELED_BRANCH_SUMMARY_FORMAT;
            if ($found === null) continue;
            if ($kind !== null && $kind !== $found) return 'MIXED';
            $kind = $found;
        }
        return $kind;
    }

    private static function labeledBranchDisplayLine(string $line): string
    {
        // Only presentation escapes used by this one copied-message grammar.
        // The original text remains the authority and is hashed without changes.
        $line = preg_replace('/\A\s*\\\\([-–—])\s+/u', '$1 ', $line);
        return self::displayLine(str_replace('\\*', '*', $line));
    }

    private static function labeledBranchHeader(string $line): ?string
    {
        if (!preg_match('/\Aملخص الطلب\s+[–—-]\s+فرع\s+([\p{L}0-9 ]{1,80}?)(?:\s+✅)?\z/u', self::labeledBranchDisplayLine($line), $match)) return null;
        $branch = trim($match[1]);
        return self::compactPlace(self::matchingText($branch)) ? $branch : null;
    }

    /** This separately approved labeled summary cannot grant its own consent. */
    private static function parseLabeledBranchSummary(array $raw): array
    {
        $lines = [];
        foreach ($raw as $line) {
            $line = self::labeledBranchDisplayLine($line);
            if ($line !== '') $lines[] = $line;
        }
        $header = null; $branch = null;
        foreach ($lines as $index => $line) if (($value = self::labeledBranchHeader($line)) !== null) {
            if ($header !== null) return self::result(false, 'RECAP_DUPLICATE_HEADER');
            $header = $index; $branch = $value;
        }
        if ($header === null || $header > 1) return self::result(false, 'RECAP_PREFIX_INVALID');
        if ($header === 1 && !preg_match('/\Aتمام يا استاذ [\p{Arabic} ]{1,80}[،,] دي فورمه طلب فرع '.preg_quote(self::matchingText($branch), '/').' ✅\z/u', self::matchingText($lines[0])))
            return self::result(false, 'RECAP_PREFIX_INVALID');
        $items = []; $names = []; $quotedTotal = 0; $total = null; $fields = []; $footer = false;
        foreach (array_slice($lines, $header + 1) as $line) {
            if ($footer) return self::result(false, 'RECAP_FOOTER_INVALID');
            if ($total === null) {
                if (preg_match('/\A(?:الإجمالي|الاجمالي)\s*[:：]\s*(.+)\s+بدون (?:التوصيل|توصيل)\z/u', $line, $match)) {
                    $total = self::money($match[1], false);
                    if ($total === null || $items === [] || self::moneyCents($total) !== $quotedTotal)
                        return self::result(false, 'RECAP_TOTAL_INVALID');
                    continue;
                }
                if (!preg_match('/\Aالصنف\s*[:：]\s*(.+)\z/u', $line, $match)) return self::result(false, 'RECAP_ITEM_INVALID');
                $item = self::labeledBranchItem($match[1]);
                if ($item === null || count($items) >= self::MAX_ITEMS) return self::result(false, 'RECAP_ITEM_INVALID');
                $name = self::matchingText($item['data']['name']);
                if (isset($names[$name])) return self::result(false, 'RECAP_DUPLICATE_ITEM');
                $names[$name] = true; $items[] = $item['data']; $quotedTotal += $item['quoted_cents']; continue;
            }
            if (isset($fields['delivery'])) {
                if (!preg_match('/\Aانسخ الملخص ده وابعته تاني مع كتابه كل (?:شيء|شئ|شي) تمام علشان (?:ااكد|اكد) الطلب للفرع فورا[.!]?\z/u', self::matchingText($line)))
                    return self::result(false, 'RECAP_FOOTER_INVALID');
                $footer = true; continue;
            }
            if (!preg_match('/\A(الاسم|العنوان|التليفون|اللوكيشن|الفرع|التوصيل)\s*[:：]\s*(.+)\z/u', $line, $match))
                return self::result(false, 'RECAP_FIELD_INVALID');
            $key = match ($match[1]) { 'الاسم' => 'name', 'العنوان' => 'address', 'التليفون' => 'phone', 'اللوكيشن' => 'location', 'الفرع' => 'branch', default => 'delivery' };
            if (isset($fields[$key])) return self::result(false, 'RECAP_DUPLICATE_FIELD');
            $fields[$key] = trim($match[2]);
        }
        if ($total === null || count($fields) !== 6) return self::result(false, 'RECAP_REQUIRED_FIELDS_MISSING');
        foreach (['name' => 200, 'address' => 1000, 'phone' => 200, 'location' => 1000, 'branch' => 200, 'delivery' => 300] as $key => $limit)
            if (!isset($fields[$key]) || !self::bounded($fields[$key], $limit) || preg_match('/[?؟\\\\]/u', $fields[$key]))
                return self::result(false, 'RECAP_FIELD_INVALID');
        if (preg_match('/(?:تيك\s+اواي|تيك\s+اوي|داخل الفرع|استلام من الفرع|في الصاله|في المطعم|dine[ -]?in|take[ -]?away|pickup)/iu', self::matchingText(implode(' ', $fields))))
            return self::result(false, 'RECAP_FULFILLMENT_AMBIGUOUS');
        $phones = self::phones($fields['phone']);
        if ($phones === null) return self::result(false, 'RECAP_PHONE_INVALID');
        $location = self::location($fields['location']);
        if ($location === null) return self::result(false, 'RECAP_LOCATION_INVALID');
        // A descriptive branch name and its displayed contact are informational.
        // No alias or execution branch is inferred from them or from the header.
        if (!preg_match('/\A([\p{L}0-9 ]{1,80})(?:\s+\(([\p{L}0-9 ]{1,80})\))?\s+[–—-]\s+(\+?[0-9][0-9 ()-]{5,24}[0-9])\z/u', self::digits($fields['branch']), $match)
            || !self::compactPlace(self::matchingText(trim($match[1])))
            || (isset($match[2]) && $match[2] !== '' && !self::compactPlace(self::matchingText(trim($match[2])))))
            return self::result(false, 'RECAP_BRANCH_INFORMATION_INVALID');
        $branchPhone = preg_replace('/[ ()-]/', '', $match[3]);
        if (!preg_match('/\A(?:0[1-9][0-9]{7,9}|\+?[1-9][0-9]{7,14})\z/', $branchPhone))
            return self::result(false, 'RECAP_BRANCH_INFORMATION_INVALID');
        if (!preg_match('/\Aخلال ([1-9][0-9]{0,2}) (?:ل|الي) ([1-9][0-9]{0,2}) دقيقه[،,] والتكلفه بيحددها المندوب حسب اللوكيشن\z/u', self::matchingText($fields['delivery']), $match)
            || (int) $match[2] < (int) $match[1]) return self::result(false, 'RECAP_FOOTER_INVALID');
        return self::result(true, null, ['type' => self::TYPE, 'decision' => 'DRAFT',
            'customer' => ['name' => $fields['name'], 'phone' => $phones[0], 'address' => $fields['address'], 'area' => null, 'notes' => null],
            'alternate_phones' => array_slice($phones, 1), 'branch_hint' => $branch, 'fulfillment' => 'DELIVERY', 'items' => $items,
            'approximate_total' => $total, 'textual_location' => $location, 'evidence_receipt' => null]) + ['format' => self::LABELED_BRANCH_SUMMARY_FORMAT];
    }

    private static function labeledBranchItem(string $line): ?array
    {
        $parts = preg_split('/\s+[–—-]\s+/u', $line);
        if (!is_array($parts) || count($parts) !== 2) return null;
        $quoted = self::money($parts[1], false);
        if ($quoted === null) return null;
        $literal = trim($parts[0]); $item = self::item($literal);
        if ($item === null && preg_match('/\A([^()]+?)\s+\(([^()]+)\)\s*([^()]*)\z/u', $literal, $match)) {
            $unit = self::digits(trim($match[2]));
            if (!preg_match('/\A(?:(?:[0-9]{1,5}(?:[.٫][0-9]{1,3})?|نصف|نص|ربع|ثلاثة أرباع|ثلاثه ارباع)\s+)?(?:كيلوغرام|كيلوجرام|كيلو|كجم|كغ|جرام|غرام|جم|قطعة|قطعه|قطع)\z/u', $unit)) return null;
            $item = self::item($unit.' '.trim($match[1].' '.$match[3]));
        }
        if ($item === null || preg_match('/[\\\\()]/u', $item['name'])) return null;
        return ['data' => $item, 'quoted_cents' => self::moneyCents($quoted)];
    }

    private static function branchSummaryHeader(string $line): ?string
    {
        if (!preg_match('/\Aملخص الأوردر\s+[–—-]\s+فرع\s+([\p{L}0-9 ]{1,80})\s+✅\z/u', self::displayLine($line), $match)) return null;
        $branch = trim($match[1]);
        return self::compactPlace(self::matchingText($branch)) ? $branch : null;
    }

    /** The observed branch summary is a draft until a separate customer approval. */
    private static function parseBranchSummary(array $lines): array
    {
        if (count($lines) < 8 || ($branch = self::branchSummaryHeader($lines[0])) === null)
            return self::result(false, 'RECAP_PREFIX_INVALID');
        if (($lines[1] ?? null) !== 'الأصناف:') return self::result(false, 'RECAP_ITEMS_SECTION_REQUIRED');
        $items = []; $names = []; $quotedTotal = 0; $total = null; $dataSection = false; $fields = []; $hours = null;
        foreach (array_slice($lines, 2) as $line) {
            if ($hours !== null) return self::result(false, 'RECAP_FOOTER_INVALID');
            if ($total === null) {
                if (preg_match('/\Aالإجمالي\s*[:：]\s*(.+)\s+بدون التوصيل\s+[–—-]\s+سعر التوصيل يحدده المندوب حسب اللوكيشن\z/u', $line, $match)) {
                    $total = self::money($match[1], false);
                    if ($total === null || $items === [] || self::moneyCents($total) !== $quotedTotal)
                        return self::result(false, 'RECAP_TOTAL_INVALID');
                    continue;
                }
                $item = self::branchSummaryItem($line);
                if ($item === null || count($items) >= self::MAX_ITEMS) return self::result(false, 'RECAP_ITEM_INVALID');
                $name = self::matchingText($item['data']['name']);
                if (isset($names[$name])) return self::result(false, 'RECAP_DUPLICATE_ITEM');
                $names[$name] = true; $items[] = $item['data']; $quotedTotal += $item['quoted_cents']; continue;
            }
            if (!$dataSection) {
                if ($line !== 'البيانات:') return self::result(false, 'RECAP_DATA_SECTION_REQUIRED');
                $dataSection = true; continue;
            }
            if (preg_match('/\Aمواعيد التجهيز\s*[:：]/u', $line)) { $hours = $line; continue; }
            if (!preg_match('/\A(الاسم|العنوان|أرقام التواصل|الفرع)\s*[:：]\s*(.+)\z/u', $line, $match))
                return self::result(false, 'RECAP_FIELD_INVALID');
            $key = match ($match[1]) { 'الاسم' => 'name', 'العنوان' => 'address', 'أرقام التواصل' => 'phone', default => 'branch' };
            if (isset($fields[$key])) return self::result(false, 'RECAP_DUPLICATE_FIELD');
            $fields[$key] = trim($match[2]);
        }
        if (!$dataSection || !isset($fields['name'], $fields['address'], $fields['phone'], $fields['branch']))
            return self::result(false, 'RECAP_REQUIRED_FIELDS_MISSING');
        if (!self::bounded($fields['name'], 200) || !self::bounded($fields['address'], 1000)
            || preg_match('/[?؟]/u', implode(' ', $fields))) return self::result(false, 'RECAP_FIELD_INVALID');
        if (preg_match('/(?:تيك\s+اواي|تيك\s+اوي|داخل الفرع|استلام من الفرع|في الصاله|في المطعم|dine[ -]?in|take[ -]?away|pickup)/iu', self::matchingText($fields['address'])))
            return self::result(false, 'RECAP_FULFILLMENT_AMBIGUOUS');
        $phones = self::phones($fields['phone']);
        if ($phones === null) return self::result(false, 'RECAP_PHONE_INVALID');
        $branchPattern = preg_quote($branch, '/');
        if (!preg_match('/\A'.$branchPattern.'(?: والمصنع \(أمام بوابة '.$branchPattern.'\))?\s+[–—-]\s+(.+)\z/u', $fields['branch'], $match)
            || count(self::phones($match[1]) ?? []) !== 1) return self::result(false, 'RECAP_BRANCH_INFORMATION_INVALID');
        if (!is_string($hours) || !preg_match('/\Aمواعيد التجهيز: من ([1-9]|1[0-2]) (?:صباحا|مساء|مساءا|ليلا) حتي ([1-9]|1[0-2]) (?:صباحا|مساء|مساءا|ليلا)، والدليفري خلال ([1-9][0-9]{0,2}) دقيقه داخل (.{1,80})\z/u', self::matchingText($hours), $match)
            || !self::compactPlace($match[4])) return self::result(false, 'RECAP_FOOTER_INVALID');
        return self::result(true, null, ['type' => self::TYPE, 'decision' => 'DRAFT',
            'customer' => ['name' => $fields['name'], 'phone' => $phones[0], 'address' => $fields['address'], 'area' => null, 'notes' => null],
            'alternate_phones' => array_slice($phones, 1), 'branch_hint' => $branch, 'fulfillment' => 'DELIVERY', 'items' => $items,
            'approximate_total' => $total, 'textual_location' => null, 'evidence_receipt' => null]) + ['format' => self::BRANCH_SUMMARY_FORMAT];
    }

    private static function branchSummaryItem(string $line): ?array
    {
        $parts = preg_split('/\s+[–—-]\s+/u', $line);
        if (!is_array($parts) || count($parts) !== 2 || !preg_match('/\A(.+?)(?: \(الواحد (.+)\))?\z/u', $parts[1], $price)) return null;
        $quoted = self::money($price[1], false); $unit = isset($price[2]) ? self::money($price[2], false) : null;
        if ($quoted === null || (isset($price[2]) && $unit === null)) return null;
        $literal = trim($parts[0]); $normalized = self::digits($literal);
        if (preg_match('/\Aعلبة\s+.+\s+([0-9]{1,5}(?:\.[0-9]{1,3})?)\s+(?:جرام|جم)\z/u', $normalized, $pack)) {
            $grams = self::decimal($pack[1]);
            if ($grams === null || (float) $grams <= 0 || (float) $grams > 10000 || !self::bounded($literal, 200)
                || preg_match('/[|:：?؟*\[\]{}<>]/u', $literal)) return null;
            // The weight describes one complete pack, never a sale by kilogram.
            $item = ['name' => $literal, 'quantity' => '1', 'quantity_mode' => 'piece', 'option_hint' => null,
                'cart_reference' => null, 'conversation_reference' => null];
        } elseif (($item = self::item($literal)) !== null) {
            // Existing explicit unit grammar takes precedence over the observed
            // shorthand, so "نص كيلو" never becomes a product qualifier.
        } elseif (preg_match('/\A(نص|نصف|ربع)\s+(.+)\z/u', $literal, $fraction)) {
            $item = self::item($fraction[1].' كيلو '.$fraction[2]);
        } elseif (preg_match('/\Aساندوتشين\s+(.+)\z/u', $literal, $dual)) {
            $item = self::item('2 قطعة ساندوتش '.$dual[1]);
        } else return null;
        if ($item === null) return null;
        if ($unit !== null && ($item['quantity_mode'] !== 'piece'
            || self::moneyCents($quoted) !== self::moneyCents($unit) * (int) $item['quantity'])) return null;
        return ['data' => $item, 'quoted_cents' => self::moneyCents($quoted)];
    }

    private static function moneyCents(string $value): int
    {
        $parts = explode('.', $value, 2);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    /** One separately defined customer-copy format, not a looser legacy parser. */
    private static function parseCompact(string $text, array $lines): array
    {
        $headers = [];
        foreach ($lines as $index => $line)
            if (preg_match('/\Aطلبك المؤكد\s*[:：]\z/u', $line)) $headers[] = $index;
        if (count($headers) !== 1) return self::result(false, 'RECAP_DUPLICATE_HEADER');
        $header = $headers[0];
        if ($header > 1 || ($header === 1 && $lines[0] !== 'تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً.'))
            return self::result(false, 'RECAP_PREFIX_INVALID');
        if (preg_match('/(?:تيك\s+اواي|تيك\s+اوي|داخل الفرع|استلام من الفرع|في الصاله|في المطعم|dine[ -]?in|take[ -]?away|pickup)/iu', self::matchingText($text)))
            return self::result(false, 'RECAP_FULFILLMENT_AMBIGUOUS');
        $combined = false;
        foreach (array_slice($lines, $header + 1) as $line)
            if (preg_match('/\Aالبيانات\s*[:：]/u', $line)) $combined = true;
        if (!$combined) {
            // Reuse the original strict labeled-field parser. Only this exact
            // confirmed header and a known, nonempty delivery-only footer differ.
            $legacy = array_slice($lines, $header); $legacy[0] = 'ملخص طلبك:';
            $footer = array_pop($legacy);
            if (!is_string($footer) || !self::compactFooter($footer)) return self::result(false, 'RECAP_FOOTER_INVALID');
            $parsed = self::parseRecap(implode("\n", $legacy));
            if (!$parsed['ok']) return $parsed;
            if (!is_array($parsed['data']['textual_location']['point'] ?? null)) return self::result(false, 'RECAP_LOCATION_INVALID');
            if (preg_match('/[?؟]/u', str_replace($parsed['data']['textual_location']['literal'], '', $text))) return self::result(false, 'RECAP_TEXT_INVALID');
            return $parsed + ['self_confirmed' => true];
        }
        $items = []; $total = null; $customer = null; $location = null; $footer = null;
        foreach (array_slice($lines, $header + 1) as $line) {
            if ($footer !== null) return self::result(false, 'RECAP_FOOTER_INVALID');
            if ($customer !== null) { $footer = $line; continue; }
            if ($total === null) {
                if (preg_match('/\A(?:الإجمالي|الاجمالي|الإجمالى|الاجمالى|المجموع)(?:\s+التقريبي)?\s*[:：]?\s*(.+)\z/u', $line, $m)) {
                    $total = self::money($m[1], true);
                    if ($total === null || $items === []) return self::result(false, 'RECAP_TOTAL_INVALID');
                    continue;
                }
                $item = self::item($line);
                if ($item === null || count($items) >= self::MAX_ITEMS)
                    return self::result(false, 'RECAP_ITEM_INVALID');
                $items[] = $item; continue;
            }
            // Separator positions identify fields; retain all literal address
            // words, including internal hyphens, and never infer them from a name.
            if (!preg_match('/\Aالبيانات\s*[:：]\s*([^–—-]+?)\s+[–—-]\s+(.+?)\s+[–—-]\s+([+0-9٠-٩۰-۹ ()-]{7,30})\s+[–—-]\s+اللوكيشن\s*[:：]?\s*(.+)\z/u', $line, $m))
                return self::result(false, 'RECAP_REQUIRED_FIELDS_MISSING');
            $phones = self::phones(trim($m[3]));
            if ($phones === null || count($phones) !== 1) return self::result(false, 'RECAP_PHONE_INVALID');
            $name = trim($m[1]); $address = trim($m[2]);
            if (!self::bounded($name, 200) || !self::bounded($address, 1000)) return self::result(false, 'RECAP_FIELD_INVALID');
            $location = self::location(trim($m[4]));
            if ($location === null) return self::result(false, 'RECAP_LOCATION_INVALID');
            $customer = ['name' => $name, 'phone' => $phones[0], 'address' => $address, 'area' => null, 'notes' => null];
        }
        if ($items === [] || $total === null || $customer === null) return self::result(false, 'RECAP_REQUIRED_FIELDS_MISSING');
        if (!is_string($footer) || !self::compactFooter($footer))
            return self::result(false, 'RECAP_FOOTER_INVALID');
        if (preg_match('/[?؟]/u', str_replace($location['literal'], '', $text))) return self::result(false, 'RECAP_TEXT_INVALID');
        $names = [];
        foreach ($items as $item) {
            $key = self::matchingText($item['name']);
            if (isset($names[$key])) return self::result(false, 'RECAP_DUPLICATE_ITEM');
            $names[$key] = true;
        }
        return self::result(true, null, ['type' => self::TYPE, 'decision' => 'DRAFT', 'customer' => $customer,
            'alternate_phones' => [], 'branch_hint' => null, 'fulfillment' => 'DELIVERY', 'items' => $items,
            'approximate_total' => $total, 'textual_location' => $location, 'evidence_receipt' => null]) + ['self_confirmed' => true];
    }

    private static function samePoint(array $actual, array $expected): bool
    {
        return is_numeric($actual['lat'] ?? null) && is_numeric($actual['long'] ?? null)
            && (float) $actual['lat'] === (float) $expected['lat'] && (float) $actual['long'] === (float) $expected['long'];
    }

    private static function compactFooter(string $footer): bool
    {
        if (!preg_match('/\Aالتوصيل خلال [0-9]{1,3} دقيقه(?: داخل ([\p{L}0-9 ]{1,80}))?[,،]? وسعر التوصيل (?:بياكده|ياكده) المندوب لما يكلمك[.!]?\z/u', self::matchingText($footer), $place)) return false;
        return !isset($place[1]) || $place[1] === '' || self::compactPlace($place[1]);
    }

    /** A bounded descriptive place is informational, never branch authority. */
    private static function compactPlace(string $place): bool
    {
        $words = explode(' ', trim($place));
        if (count($words) < 1 || count($words) > 6 || preg_match('/\A\p{L}+(?: [\p{L}0-9]+){0,5}\z/u', $place) !== 1
            || preg_match('/\A[\p{Arabic}0-9 ]+\z/u', $place) !== 1) return false;
        foreach ($words as $word) if (in_array($word, ['اذا','لو','عشان','لان','حتي','قبل','بعد','غير','بدل','بدلا','الغاء','الغي','الغ',
            'تجاهل','تعليمات','نفذ','تنفيذ','تنفذ','ارسل','ترسل','مجانا','مجاني','خصم','موافق','موافقه','مش','لا','هل','لكن','بس','شرط','بشرط'], true)) return false;
        return true;
    }

    private static function item(string $line): ?array
    {
        // A quoted line price is optional. It is never returned as an ERP price.
        $parts = preg_split('/\s+[–—-]\s+/u', $line);
        if (!is_array($parts) || count($parts) > 2) return null;
        if (count($parts) === 2 && self::money($parts[1], false) === null) return null;
        $literal = trim($parts[0]); $normalized = self::digits($literal);
        $number = '(?:[0-9]{1,5}(?:[.٫][0-9]{1,3})?)';
        if (preg_match('/\A(?:(نصف|نص|ربع|ثلاثة أرباع|ثلاثه ارباع)\s+|('.$number.')\s*)?(كيلوغرام|كيلوجرام|كيلو|كجم|كغ|جرام|غرام|جم|قطعة|قطعه|قطع)\s+(.+)\z/u', $normalized, $m) !== 1) return null;
        $fraction = $m[1] ?? ''; $amount = $m[2] ?? ''; $unit = $m[3];
        $quantity = match ($fraction) { 'نصف', 'نص' => '0.5', 'ربع' => '0.25', 'ثلاثة أرباع', 'ثلاثه ارباع' => '0.75', default => $amount !== '' ? $amount : '1' };
        $quantity = self::decimal($quantity);
        if ($quantity === null || (float) $quantity <= 0 || (float) $quantity > 10000) return null;
        $weight = !in_array($unit, ['قطعة', 'قطعه', 'قطع'], true);
        if (in_array($unit, ['جرام', 'غرام', 'جم'], true)) $quantity = self::decimal((string) ((float) $quantity / 1000));
        if ($quantity === null || (!$weight && str_contains($quantity, '.'))) return null;
        // Keep the exact descriptor and its literal qualifiers, including fish
        // count/size. A kilo with three fish is still one kilogram, not three items.
        $name = trim(mb_substr($literal, mb_strlen($normalized, 'UTF-8') - mb_strlen($m[4], 'UTF-8'), null, 'UTF-8'));
        if (!self::bounded($name, 200) || preg_match('/[|:：?؟*\[\]{}<>]/u', $name)) return null;
        return ['name' => $name, 'quantity' => $quantity, 'quantity_mode' => $weight ? 'weight' : 'piece',
            'option_hint' => null, 'cart_reference' => null, 'conversation_reference' => null];
    }

    private static function phones(string $literal): ?array
    {
        if (!self::bounded($literal, 200)) return null;
        $parts = preg_split('/\s*(?:[،,;؛\/]|و|\s+and\s+)\s*/iu', self::digits($literal));
        if (!is_array($parts) || count($parts) < 1 || count($parts) > 3) return null;
        $phones = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!preg_match('/\A\+?[0-9](?:[0-9 ()-]{5,24})[0-9]\z/', $part)) return null;
            $phone = preg_replace('/[ ()-]/', '', $part);
            if (str_starts_with($phone, '+')) $phone = substr($phone, 1);
            if (preg_match('/\A01[0125][0-9]{8}\z/', $phone)) $phone = '20'.substr($phone, 1);
            elseif (str_starts_with($phone, '00')) $phone = substr($phone, 2);
            if (!preg_match('/\A[1-9][0-9]{7,14}\z/', $phone) || in_array($phone, $phones, true)) return null;
            $phones[] = $phone;
        }
        return $phones;
    }

    private static function location(string $literal): ?array
    {
        if (!self::bounded($literal, 1000)) return null;
        $text = self::digits($literal);
        $n = '[-+]?[0-9]{1,3}(?:\.[0-9]{1,9})?';
        // A numeric pair in the labeled location field follows the documented
        // latitude,longitude convention as written. Never flip coordinates by
        // a guessed city/branch; only explicit reversed labels can reorder them.
        $order = 'LAT_LONG'; $matches = [];
        if (preg_match('/\A(?:خط العرض|latitude|lat)\s*[:：=]\s*('.$n.')\s*[,،;؛]\s*(?:خط الطول|longitude|long|lng|lon)\s*[:：=]\s*('.$n.')\z/iu', $text, $matches)) {
            $values = [(float) $matches[1], (float) $matches[2]]; $order = 'LAT_LONG';
        } elseif (preg_match('/\A(?:خط الطول|longitude|long|lng|lon)\s*[:：=]\s*('.$n.')\s*[,،;؛]\s*(?:خط العرض|latitude|lat)\s*[:：=]\s*('.$n.')\z/iu', $text, $matches)) {
            $values = [(float) $matches[2], (float) $matches[1]]; $order = 'LAT_LONG';
        } elseif (preg_match('/\A('.$n.')\s*[,،]\s*('.$n.')\z/u', $text, $matches)) {
            $values = [(float) $matches[1], (float) $matches[2]];
        } else {
            $values = self::googleMapPoint($text);
            if ($values === null) return null;
            $order = 'LAT_LONG';
        }
        if (!is_finite($values[0]) || !is_finite($values[1]) || $values[0] == 0.0 || $values[1] == 0.0
            || abs($values[0]) > 90 || abs($values[1]) > 180) return null;
        return ['source_message_id' => null, 'literal' => $literal, 'coordinate_order' => $order,
            'values' => $values, 'point' => ['lat' => $values[0], 'long' => $values[1]]];
    }

    /** Read only literal coordinates in an allowlisted Google Maps URL; no network. */
    private static function googleMapPoint(string $text): ?array
    {
        $url = parse_url($text);
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https'
            || !in_array($url['host'] ?? null, ['www.google.com', 'google.com', 'maps.google.com'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '/', ['/', '/maps', '/maps/', '/maps/search', '/maps/search/'], true)) return null;
        $query = [];
        foreach (explode('&', $url['query'] ?? '') as $pair) {
            $part = explode('=', $pair, 2);
            if (count($part) !== 2) return null;
            $key = rawurldecode($part[0]); $value = rawurldecode($part[1]);
            if (!in_array($key, ['q', 'query', 'api'], true) || isset($query[$key])) return null;
            $query[$key] = $value;
        }
        if (isset($query['q'], $query['query']) || (isset($query['api']) && $query['api'] !== '1')) return null;
        $pair = $query['q'] ?? $query['query'] ?? null;
        if (!is_string($pair) || !preg_match('/\A(-?[0-9]{1,2}(?:\.[0-9]{1,9})?),(-?[0-9]{1,3}(?:\.[0-9]{1,9})?)\z/', $pair, $m)) return null;
        return [(float) $m[1], (float) $m[2]];
    }

    private static function money(string $text, bool $total): ?string
    {
        $text = self::digits($text);
        $suffix = $total ? '(?:\s+(?:غير التوصيل|بدون التوصيل|بدون رسوم التوصيل|غير شامل التوصيل|غير شامل رسوم التوصيل))?' : '';
        if (!preg_match('/\A([0-9]{1,7}(?:[.٫][0-9]{1,2})?)\s*(?:جنيه|جنية|جنيها|جنيهاً|ج|EGP)'.$suffix.'\z/iu', trim($text), $m)) return null;
        $value = self::decimal($m[1]);
        return $value !== null && (float) $value > 0 ? $value : null;
    }

    private static function footer(string $footer): bool
    {
        $text = self::matchingText($footer);
        return preg_match('/\A(?:التوصيل خلال [0-9]{1,3} دقيقه وسعر التوصيل بياكده المندوب لما يكلمك[.!]?\s*)?'
            .'(?:انسخ الرساله دي وابعتها هنا تاني واكتب معاها كل (?:شيء|شئ|شي) تمام عشان (?:تاكد|تؤكد|ناكد) طلبك نهائيا[.!]?)?\z/u', $text) === 1;
    }

    private static function fieldKey(string $label): string
    {
        return match ($label) {
            'الاسم', 'اسم العميل' => 'name',
            'رقم الهاتف', 'رقم التواصل', 'الهاتف', 'التليفونات', 'التليفون', 'التلفونات', 'أرقام التواصل', 'ارقام التواصل' => 'phone',
            'العنوان', 'عنوان التوصيل' => 'address',
            'المنطقة', 'المنطقه' => 'area',
            'الفرع' => 'branch', 'خط العرض' => 'latitude', 'خط الطول' => 'longitude',
            'الملاحظات', 'ملاحظات' => 'notes', default => 'location',
        };
    }

    private static function displayLine(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/\A\s*(?:[•●▪◦]\s*|[-–—]\s+|\*\s+)/u', '', $line);
        $line = str_replace(['**', '*', '__'], '', $line);
        return trim(preg_replace('/[\p{Zs}\t]+/u', ' ', $line));
    }

    private static function matchingText(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', self::digits($text));
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function digits(string $text): string
    {
        return strtr($text, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.']);
    }

    private static function decimal(string $value): ?string
    {
        $value = self::digits($value);
        if (!preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,3})?\z/', $value)) return null;
        $parts = explode('.', $value, 2); $whole = ltrim($parts[0], '0'); $whole = $whole === '' ? '0' : $whole;
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        return $whole.($fraction !== '' ? '.'.$fraction : '');
    }

    private static function bounded(string $text, int $max): bool
    {
        return $text !== '' && preg_match('//u', $text) === 1 && mb_strlen($text, 'UTF-8') <= $max
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text);
    }

    private static function transcript(array $rows): bool
    {
        if (!array_is_list($rows) || count($rows) < 1 || count($rows) > 60) return false;
        $ids = []; $last = null; $chars = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/\Am(?:[1-9]|[1-5][0-9]|60)\z/', $row['id'])
                || isset($ids[$row['id']]) || !in_array($row['speaker'] ?? null, ['customer', 'business'], true)
                || !is_string($row['sent_at'] ?? null) || !array_key_exists('text', $row) || !array_key_exists('location', $row)
                || ($row['text'] !== null && (!is_string($row['text']) || !self::bounded($row['text'], 16000)))) return false;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['sent_at'], new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d H:i:s') !== $row['sent_at'] || ($last !== null && strcmp($last, $row['sent_at']) > 0)) return false;
            if ($row['location'] !== null) {
                $point = $row['location'];
                if (!is_array($point) || array_diff(array_keys($point), ['lat', 'long']) || count($point) !== 2
                    || !is_numeric($point['lat'] ?? null) || !is_numeric($point['long'] ?? null)
                    || !is_finite((float) $point['lat']) || !is_finite((float) $point['long'])
                    || abs((float) $point['lat']) > 90 || abs((float) $point['long']) > 180) return false;
            }
            $ids[$row['id']] = true; $last = $row['sent_at']; $chars += $row['text'] === null ? 0 : mb_strlen($row['text'], 'UTF-8');
            if ($chars > 16000) return false;
        }
        return true;
    }

    private static function result(bool $ok, ?string $reason = null, ?array $data = null): array
    {
        return ['ok' => $ok, 'reason' => $reason, 'data' => $data];
    }
}
