<?php

namespace App\Support;

/** Dialogue answers are separate from order extraction and can use only supplied business facts. */
final class WhatsAppOrderDialogue
{
    private const OFFICIAL_URLS = ['https://wa.me/c/201285545554', 'https://fasakhaninja.com/downloadApp'];
    public const FIELDS = ['NAME', 'PHONE', 'ADDRESS', 'ITEMS', 'QUANTITY', 'QUANTITY_MODE',
        'FULFILLMENT', 'BRANCH', 'LOCATION'];
    public const KINDS = ['MISSING', 'QUANTITY_ASSOCIATION', 'PRODUCT_CHOICE', 'OPTION_CHOICE',
        'UNREADABLE', 'CONFLICT', 'GREETING', 'CATALOG_INFORMATION', 'PRODUCT_INFORMATION'];

    public static function validContext(array $context): bool
    {
        try {
            $information = ($context['kind'] ?? null) === 'PRODUCT_INFORMATION';
            if (!self::keys($context, $information
                    ? ['field', 'kind', 'item_name', 'candidates', 'transcript', 'facts']
                    : ['field', 'kind', 'item_name', 'candidates', 'transcript'])
                || !in_array($context['field'], self::FIELDS, true)
                || !in_array($context['kind'], self::KINDS, true)
                || ($context['item_name'] !== null && !self::literal($context['item_name'], 200))
                || !is_array($context['candidates']) || !array_is_list($context['candidates'])
                || count($context['candidates']) > 12 || !is_array($context['transcript'])
                || !WhatsAppOrderExtraction::validateTranscript($context['transcript'])['ok']
                || WhatsAppOrderExtraction::hasProbe($context['transcript'])) return false;
            $menu = in_array($context['kind'], ['PRODUCT_CHOICE', 'OPTION_CHOICE', 'CATALOG_INFORMATION'], true);
            if ((!$information && $menu !== ($context['candidates'] !== []))
                || (($menu || $information) && $context['field'] !== 'ITEMS')) return false;
            if ($information) {
                if (!is_array($context['facts']) || !array_is_list($context['facts'])
                    || count($context['facts']) < 1 || count($context['facts']) > 24) return false;
                $factIds = [];
                foreach ($context['facts'] as $fact) {
                    if (!is_array($fact) || !self::keys($fact, ['id', 'text', 'subject'])
                        || !is_string($fact['id']) || !preg_match('/\Af[1-9][0-9]{0,3}\z/', $fact['id'])
                        || isset($factIds[$fact['id']]) || !self::literal($fact['text'], 500)
                        || !self::literal($fact['subject'], 240) || !self::safeInformationText($fact['text'])
                        || !self::safeInformationText($fact['subject'])
                        || !self::groundedInformation($fact['text'], $fact['text'])) return false;
                    $factIds[$fact['id']] = true;
                }
            }
            if ($context['kind'] === 'QUANTITY_ASSOCIATION'
                && (!in_array($context['field'], ['QUANTITY', 'QUANTITY_MODE'], true)
                    || $context['item_name'] === null)) return false;
            if ($context['kind'] === 'GREETING' && ($context['field'] !== 'ITEMS'
                || $context['item_name'] !== null || $context['candidates'] !== [])) return false;
            if ($context['kind'] === 'CATALOG_INFORMATION' && $context['item_name'] !== null) return false;
            $ids = []; $labels = [];
            foreach ($context['candidates'] as $candidate) {
                if (!is_array($candidate) || !self::keys($candidate, ['id', 'label'])
                    || !is_string($candidate['id']) || !preg_match('/\Ac[1-9][0-9]{0,3}\z/', $candidate['id'])
                    || isset($ids[$candidate['id']]) || !self::literal($candidate['label'], 240)
                    || isset($labels[$candidate['label']])) return false;
                $ids[$candidate['id']] = true; $labels[$candidate['label']] = true;
            }
            if ($context['item_name'] !== null) {
                $found = false;
                foreach ($context['transcript'] as $row) {
                    foreach ([$row['text'], $row['cart']['text'] ?? null] as $text)
                        if (is_string($text) && strpos($text, $context['item_name']) !== false) $found = true;
                }
                if (!$found) return false;
            }
            return true;
        } catch (\Throwable $ignore) { return false; }
    }

    /** Return a canonical choice, or null; a valid choice is still no authority to send or dispatch. */
    public static function validateChoice(array $context, array $choice): ?array
    {
        if (($context['kind'] ?? null) === 'PRODUCT_INFORMATION') return self::informationChoice($context, $choice);
        if (!self::validContext($context) || !self::keys($choice, ['action', 'candidate_ids', 'variant'])
            || !in_array($choice['action'], ['ASK', 'WAIT'], true)
            || !in_array($choice['variant'], ['SHORT', 'POLITE'], true)
            || !is_array($choice['candidate_ids']) || !array_is_list($choice['candidate_ids'])
            || count($choice['candidate_ids']) > 6) return null;
        $allowed = array_column($context['candidates'], 'label', 'id'); $seen = [];
        foreach ($choice['candidate_ids'] as $id) {
            if (!is_string($id) || !isset($allowed[$id]) || isset($seen[$id])) return null;
            $seen[$id] = true;
        }
        if ($choice['action'] === 'WAIT' && $choice['candidate_ids'] !== []) return null;
        if ($choice['action'] === 'ASK'
            && (($context['candidates'] !== []) !== ($choice['candidate_ids'] !== []))) return null;
        return ['action' => $choice['action'], 'candidate_ids' => $choice['candidate_ids'], 'variant' => $choice['variant']];
    }

    /** Deterministic, grounded fallback after a reserved attempt; never model-written prose or guessed products. */
    public static function fallback(array $context): array
    {
        if (!self::validContext($context)) throw new \RuntimeException('INVALID_DIALOGUE_CONTEXT');
        if ($context['kind'] === 'PRODUCT_INFORMATION') {
            // This path is deliberately literal when generation fails. Facts must put
            // any missing-detail qualification before optional commercial details.
            $facts = []; $text = '';
            foreach ($context['facts'] as $fact) {
                $next = $text === '' ? $fact['text'] : $text."\n".$fact['text'];
                if (preg_match_all('/./us', $next) > 690 || count($facts) === 6) break;
                $facts[] = $fact['id']; $text = $next;
            }
            if (self::sensitiveInformation($text)) $text = self::factualAnswer($text, 'POLITE');
            return ['action' => 'ANSWER', 'candidate_ids' => [], 'fact_ids' => $facts,
                'text' => $text, 'variant' => 'POLITE'];
        }
        return ['action' => 'ASK', 'candidate_ids' => array_slice(array_column($context['candidates'], 'id'), 0, 6),
            'variant' => 'POLITE'];
    }

    /** WAIT renders empty: the coordinator must not send an empty message. */
    public static function render(array $context, array $choice): string
    {
        $choice = self::validateChoice($context, $choice);
        if ($choice === null) throw new \RuntimeException('INVALID_DIALOGUE_CHOICE');
        if ($choice['action'] === 'WAIT') return '';
        if ($context['kind'] === 'PRODUCT_INFORMATION') return $choice['text'];
        if ($context['kind'] === 'GREETING')
            return 'أهلاً بحضرتك في فسخانستا. تحت أمرك، تحب نساعدك بإيه أو تطلب إيه؟';
        $polite = $choice['variant'] === 'POLITE' ? 'لو سمحت، ' : '';
        $item = $context['item_name'] === null ? '' : 'بالنسبة لـ «'.$context['item_name'].'»، ';
        if ($context['candidates'] !== []) {
            $labels = array_column($context['candidates'], 'label', 'id'); $lines = [];
            foreach ($choice['candidate_ids'] as $id) $lines[] = '• '.$labels[$id];
            $text = $context['kind'] === 'CATALOG_INFORMATION'
                ? "دي بعض الأصناف المتاحة في الفرع:\n".implode("\n", $lines)."\nتحب تعرف تفاصيل عن أنهي صنف؟"
                : $polite.$item."من الاختيارات المتاحة عندنا:\n".implode("\n", $lines)
                    ."\nاكتب اسم ".($context['kind'] === 'OPTION_CHOICE' ? 'النوع' : 'الصنف').' اللي تحبه.';
        } else {
            $questions = ['NAME' => 'ممكن اسم حضرتك علشان نسجّل الطلب؟',
                'PHONE' => 'ممكن رقم هاتف للتواصل بخصوص الطلب؟',
                'ADDRESS' => 'عنوان التوصيل بالتفصيل إيه؟',
                'ITEMS' => 'تحب تطلب إيه؟ اكتب لنا اسم الصنف المقصود.',
                'QUANTITY' => 'تحب قد إيه؟',
                'QUANTITY_MODE' => 'الكمية المطلوبة بالكيلو ولا بالقطعة؟',
                'FULFILLMENT' => 'تحب الطلب دليفري ولا في الصالة أو تيك أواي؟',
                'BRANCH' => 'تقصد أنهي فرع أو مدينة للطلب؟',
                'LOCATION' => 'ممكن تبعت لوكيشن عنوان التوصيل من زر الموقع في واتساب؟'];
            $lead = $context['kind'] === 'UNREADABLE' ? 'معلش، محتاجين توضيح الجزئية دي. '
                : ($context['kind'] === 'CONFLICT' ? 'علشان نتأكد من البيانات الحالية، ' : '');
            $text = $polite.$item.$lead.$questions[$context['field']];
            if ($context['kind'] === 'QUANTITY_ASSOCIATION' && $context['field'] === 'QUANTITY')
                $text .= ' عدد السمك في الكيلو بيحدد الحجم؛ محتاجين كمية الطلب نفسها.';
        }
        if (!self::literal($text, 4096, true)) throw new \RuntimeException('INVALID_DIALOGUE_RENDER');
        return $text;
    }

    private static function informationChoice(array $context, array $choice): ?array
    {
        if (!self::validContext($context) || !self::keys($choice,
            ['action', 'candidate_ids', 'fact_ids', 'text', 'variant'])
            || !in_array($choice['action'], ['ANSWER', 'WAIT'], true) || $choice['candidate_ids'] !== []
            || !in_array($choice['variant'], ['SHORT', 'POLITE', 'FRIENDLY'], true)
            || !is_array($choice['fact_ids']) || !array_is_list($choice['fact_ids'])
            || count($choice['fact_ids']) > 6) return null;
        $allowed = array_column($context['facts'], 'text', 'id'); $selected = []; $seen = [];
        foreach ($choice['fact_ids'] as $id) {
            if (!is_string($id) || !isset($allowed[$id]) || isset($seen[$id])) return null;
            $seen[$id] = true; $selected[] = $allowed[$id];
        }
        if ($choice['action'] === 'WAIT') return $choice['fact_ids'] === [] && $choice['text'] === '' ? $choice : null;
        if ($selected === [] || !self::literal($choice['text'], 700, true)
            || !self::safeInformationText($choice['text'])
            || array_diff(self::informationUrls($choice['text']), self::informationUrls(implode(' ', $selected))) !== []
            || !self::groundedInformation($choice['text'], implode(' ', $selected))) return null;
        // Numeric checks alone cannot bind a price to its product, or preserve a
        // negative availability/preparation fact. Those business clauses are copied
        // exactly with their subjects; generated wording never reassigns them.
        $source = implode("\n", $selected);
        if (self::sensitiveInformation($source)) {
            $choice['text'] = self::factualAnswer($source, $choice['variant']);
            if (!self::literal($choice['text'], 700, true)) return null;
        }
        return $choice;
    }

    private static function factualAnswer(string $facts, string $variant): string
    { return ($variant === 'SHORT' ? '' : 'أكيد، ').$facts; }

    private static function sensitiveInformation(string $facts): bool
    {
        return self::informationUrls($facts) !== [] || preg_match('/[0-9٠-٩۰-۹]|\p{Sc}|(?:سعر|اسعار|جنيه|دولار|يورو|ريال|درهم|دينار|كيلو|جرام|غرام|قطعه|قطع|حبه|حبات|سمكه|سمكات|ربع|نصف|ثلث|متاح|موجود|متوفر|مطبوخ|مطهي|مشوي|مقلي|مسلوق|مدخن|منظف|مخلي|محشي|متبل|جاهز|نيء|ناي|مستورد|بلدي|طازج|صحي|صحه|مفيد|امن|سليم|سعرات|بروتين|دهون|حساسيه|صلاحيه|حامل|حوامل|رضاعه|ضغط|سكر|قلب|كوليسترول|افراد|اشخاص|فرد|شخص|زيت|ليمون|طهينه|بصل|ملح|صوديوم|مطهر|معقم|نظيف|طبخ|تشويح|تحمير|نرويجي|نرويج|هولندي|هولندا|اسكتلندي|اسكتلندا|مصري|روسي|روسيا|سويدي|السويد|دنماركي|الدنمارك|صيني|الصين|ناعمه|سمينه|شوك|جلد|بطارخ|مبطرخ)|\b(?:price|EGP|USD|EUR|SAR|AED|GBP|kg|gram|piece|available|unavailable)\b/iu',
            self::informationWords($facts)) === 1;
    }

    /** Only exact owner-approved public URLs may appear; no arbitrary links or hidden text. */
    private static function safeInformationText(string $text): bool
    {
        if (preg_match('/[<>\p{Cf}\x{00A0}\x{202F}\x{2028}\x{2029}]/u', $text)) return false;
        $remaining = $text;
        foreach (self::informationUrls($text) as $url) {
            if (!in_array($url, self::OFFICIAL_URLS, true)) return false;
            $remaining = str_replace($url, '', $remaining);
        }
        return !preg_match('~\b[a-z][a-z0-9+.-]{1,20}:|//\S|www\.'
            .'|(?:[a-z0-9-]+\.)+[a-z]{2,63}\b|(?:[0-9]{1,3}\.){3}[0-9]{1,3}~iu', $remaining);
    }

    private static function informationUrls(string $text): array
    {
        preg_match_all('~(?<![\p{L}\p{N}])https?://[^\s<>"\x{0027}«»()،؛؟]+~iu', $text, $matches);
        return array_values(array_unique($matches[0]));
    }

    private static function informationWords(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي','ة'=>'ه',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '٫'=>'.']);
    }

    /** Numeric and material business claims need their actual selected source fact. */
    private static function groundedInformation(string $text, string $facts): bool
    {
        $text = self::informationWords($text); $facts = self::informationWords($facts);
        if (preg_match('/(?:طلبك|الطلب|الاوردر|اوردر|order).{0,28}(?:اتأكد|اتاكد|تأكد|تاكد|مؤكد|اتسجل|تسجل|اترسل|اتبعت|اتنفذ|تم|جاهز|خرج|accepted|confirmed|sent|created|dispatched)'
            .'|(?:تم|جاري|هنقوم|هبعت|هنبعت|هبعته|هنبعته|بعتنا|ارسلنا|سجلنا|اكدنا|نفذنا).{0,24}(?:طلب|اوردر|الفرع|للفرع|للمطبخ)'
            .'|(?:ادفع|حول|تحويل|الدفع|رابط دفع|رقم حساب|رقم بطاقه|كلمه المرور|كود التحقق|payment|password|otp)'
            .'|(?:accepted|confirmed|sent|created|dispatched|prepared).{0,20}(?:order|request)'
            .'|(?:انا|احنا).{0,20}(?:موظف|بشري|سالت المطبخ|راجعت المطبخ|كلمت الفرع)/iu', $text)) return false;
        $numbers = static function (string $value): array {
            preg_match_all('/[0-9]+(?:\.[0-9]+)?/', $value, $matches);
            $values = array_map(static function ($number) {
                $parts = explode('.', $number, 2); $whole = ltrim($parts[0], '0'); $whole = $whole === '' ? '0' : $whole;
                $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
                return $whole.($fraction === '' ? '' : '.'.$fraction);
            }, $matches[0]);
            $words = ['صفر'=>'0','واحد'=>'1','واحده'=>'1','اتنين'=>'2','اثنين'=>'2','اثنان'=>'2',
                'تلاته'=>'3','ثلاثه'=>'3','اربعه'=>'4','خمسه'=>'5','سته'=>'6','سبعه'=>'7','تمانيه'=>'8',
                'ثمانيه'=>'8','تسعه'=>'9','عشره'=>'10','عشرين'=>'20','ميه'=>'100','مائه'=>'100',
                'مئه'=>'100','ميتين'=>'200','مئتين'=>'200','الف'=>'1000','نصف'=>'0.5','نص'=>'0.5','ربع'=>'0.25','ثلث'=>'0.333'];
            foreach ($words as $word=>$number)
                if (preg_match('/(?<!\p{L})'.preg_quote($word,'/').'(?!\p{L})/u',$value)) $values[]=$number;
            return array_values(array_unique($values));
        };
        if (array_diff($numbers($text), $numbers($facts)) !== []) return false;
        $claims = [
            ['/(?:جنيه|جنيهات|جنيها|ج\.م|\bEGP\b|(?<!\p{L})ج(?!\p{L}))/iu', '/(?:جنيه|ج\.م|\bEGP\b|(?<!\p{L})ج(?!\p{L}))/iu'],
            ['/(?:دولار|\$|\bUSD\b)/iu', '/(?:دولار|\$|\bUSD\b)/iu'],
            ['/(?:يورو|€|\bEUR\b)/iu', '/(?:يورو|€|\bEUR\b)/iu'],
            ['/(?:ريال|\bSAR\b)/iu', '/(?:ريال|\bSAR\b)/iu'],
            ['/(?:درهم|\bAED\b)/iu', '/(?:درهم|\bAED\b)/iu'],
            ['/(?:دينار|استرليني|\bGBP\b|£)/iu', '/(?:دينار|استرليني|\bGBP\b|£)/iu'],
            ['/(?:كيلو|\bkg\b|kilogram)/iu', '/(?:كيلو|\bkg\b|kilogram)/iu'],
            ['/(?<!كيلو)(?:جرام|غرام|\bgram\b)/iu', '/(?<!كيلو)(?:جرام|غرام|\bgram\b)/iu'],
            ['/(?:لتر|ملليلتر|ميللي|اوقيه|رطل|ounce|pound)/iu', '/(?:لتر|ملليلتر|ميللي|اوقيه|رطل|ounce|pound)/iu'],
            ['/(?:قطعه|قطع|حبه|حبات|سمكه|سمكات|piece)/iu', '/(?:قطعه|قطع|حبه|حبات|سمكه|سمكات|piece)/iu'],
            ['/(?:ربع|نصف|نص\s*كيلو|ثلث)/u', '/(?:ربع|نصف|نص\s*كيلو|ثلث)/u'],
        ];
        foreach ($claims as [$claim, $source]) if (preg_match($claim, $text) && !preg_match($source, $facts)) return false;
        preg_match_all('/\p{Sc}/u',$text,$symbols);
        foreach(array_unique($symbols[0]) as $symbol) if(strpos($facts,$symbol)===false) return false;
        // Name suffixes are not recipes. Preparation, origin, freshness, nutrition
        // and serving claims cannot be introduced by conversational paraphrasing.
        preg_match_all('/(?:مطبوخ|مطهي|مشوي|مقلي|مسلوق|مدخن|منظف|مخلي|مخليه|محشي|متبل|متبله|جاهز|نيء|ناي|مستورد|بلدي|طازج|صحي|صحه|مفيد|امن|سعرات|بروتين|دهون|حساسيه|صلاحيه|حامل|حوامل|رضاعه|ضغط|سكر|قلب|كوليسترول|سليم|افراد|اشخاص|فرد|شخص|زيت|ليمون|طهينه|بصل|ملح|صوديوم|مطهر|معقم|نظيف|طبخ|تشويح|تحمير|متاح|موجود|متوفر|نرويجي|نرويج|هولندي|هولندا|اسكتلندي|اسكتلندا|مصري|روسي|روسيا|سويدي|السويد|دنماركي|الدنمارك|صيني|الصين)/u', $text, $claims);
        foreach (array_unique($claims[0]) as $claim) if (strpos($facts, $claim) === false) return false;
        preg_match_all('/(?:فسيخ|رنجه|هيرنج|سردين|بطارخ|انشوجه|تونه|ملوحه|ماكريل|سلمون|جمبري|كابوريا|استاكوزا|سبيط|محار|ناعمه|سمينه|شوك|جلد)/u',$text,$products);
        foreach(array_unique($products[0]) as $product) if(strpos($facts,$product)===false) return false;
        return true;
    }

    private static function keys(array $value, array $keys): bool
    { return count($value) === count($keys) && array_diff($keys, array_keys($value)) === []; }

    private static function literal($value, int $maximum, bool $newlines = false): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && preg_match_all('/./us', $value) <= $maximum
            && !preg_match($newlines ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value);
    }
}
