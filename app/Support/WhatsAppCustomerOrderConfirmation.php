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
    private const MAX_TEXT = 12000;
    private const MAX_ITEMS = 30;

    /**
     * Inspect an authentic ordered snapshot. A copied instruction containing an
     * approval phrase is not approval: a separate whole-positive customer turn is
     * required. All returned IDs still name the original customer rows.
     */
    public static function inspect(array $transcript): array
    {
        if (!self::transcript($transcript)) return self::result(false, 'TRANSCRIPT_INVALID');
        $recap = null;
        foreach ($transcript as $position => $row) {
            if ($row['speaker'] === 'customer' && is_string($row['text'])
                && preg_match('/(?:\A|\R)\s*[*_]*(?:ملخص طلبك|ملخص الطلب النهائي|تأكيد الطلب النهائي)\s*[:：]/u', $row['text'])) {
                $recap = ['row' => $row, 'position' => $position];
            }
        }
        if ($recap === null) return self::result(false, 'NO_CUSTOMER_RECAP');
        $parsed = self::parseRecap($recap['row']['text']);
        if (!$parsed['ok']) return $parsed;
        $approval = null;
        foreach ($transcript as $position => $row) {
            if ($position <= $recap['position']) continue;
            if ($row['speaker'] === 'customer') {
                if (!self::positiveApproval($row['text']) || $row['location'] !== null || ($row['cart'] ?? null) !== null)
                    return self::result(false, 'CUSTOMER_CHANGED_AFTER_RECAP');
                // Repeated positive turns do not replace the original authority.
                $approval ??= $row;
            } elseif ($approval === null) {
                // Even an apparently helpful new business question may change the
                // scope of the next "OK". This protocol does not guess that scope.
                return self::result(false, 'INTERVENING_TURN');
            } else {
                // A media-only business turn can also change the order. Its
                // missing transcript text is not proof of a harmless acknowledgement.
                return self::result(false, 'BUSINESS_TURN_AFTER_APPROVAL');
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
        $text = self::matchingText($text);
        return preg_match('/\A(?:كل (?:شيء|شئ|شي) تمام|موافق علي الطلب|اوافق علي الطلب|اكد طلبي|اؤكد طلبي|تمام موافق|موافق|تمام|نعم|yes|ok|okay)[.!]?\z/iu', $text) === 1;
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
            .'(?:انسخ الرساله دي وابعتها هنا تاني واكتب معاها كل (?:شيء|شئ|شي) تمام عشان (?:تاكد|تؤكد) طلبك نهائيا[.!]?)?\z/u', $text) === 1;
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
