<?php

namespace App\Services\Dashboard;

/** Public-comment safeguards. The sole published number is server-owned, never model-owned. */
class MetaCommentAiReplyPolicy
{
    public const MAX_MESSAGE_CHARACTERS = 600;

    public static function priceRedirect(): string
    {
        return 'لمعرفة الأسعار، ابعت لنا على واتساب رقم 01285545554 وهنوضح لحضرتك التفاصيل.';
    }

    /** Run on the original comment before redaction or any model request. */
    public static function isPriceInquiry(string $text): bool
    {
        if (!preg_match('//u', $text)) return false;
        $text = self::normalize($text);
        if (preg_match('/(?:اسعار|سعر|تسعير|ثمن|تكلف|رخيص|ارخص|اغلي|خصم|جنيه|جنية|جنيها|دولار|ريال|درهم|فلوس|مصاريف|رسوم|المبلغ|اجمالي|فاتوره|الحد\s*الادني\s*للطلب|بكا+م|بكم)|(?<![\p{L}])(?:ال)?تمن(?:ه|ها|هم|كم|ك)?(?![\p{L}])/u', $text)) return true;
        if (preg_match('/\b(?:prices?|pricing|costs?|rates?|expensive|cheap|cheaper|discounts?|egp|usd|dollars?|b[ae]?kam|b[ae]?k[ae]?m)\b|how[\s\p{P}]*much|[$€£]/iu', $text)) return true;
        if (preg_match('/(?:التوصيل|الشحن|الدليفري)\s*(?:مجاني|مجانا|ببلاش)|(?:free\s+(?:shipping|delivery)|(?:shipping|delivery)\s+(?:is\s+)?free|no\s+charge)/iu', $text)) return true;
        if (preg_match('/(?<!يا )غالي/u', $text)) return true;
        // Spaces, diacritics and invisible characters must not conceal the owner's price rule.
        if (preg_match('/(?<![\p{L}])ب[\s.\-_]*ك[\s.\-_]*ا?[\s.\-_]*م(?![\p{L}])|(?<![\p{L}])ب\s*\p{N}+/u', $text)) return true;
        if (preg_match('/(?:كيلو|وجبه|علبه)\s*\p{N}+[\s\p{N}.,]*[؟?]/u', $text)) return true;
        if (preg_match('/(?:عامل|عامله|بيعمل|بتعمل|يعمل|تعمل|بيدفع|ادفع|دفع|ببيع|بتبيع|ببيعوا|بيتباع|بتتباع|علي|على)\s*(?:ب\s*)?كام/u', $text)) return true;
        $amount = '(?:الف(?:ين)?|مئه|مائه|ميه|مئتين|مائتين|ميتين|مائتان|مئتان|متين|عشره|خمسه|عشرين|تلاتين|ثلاثين|اربعين|خمسين|ستين|سبعين|ثمانين|تمانين|تسعين|(?:تلات|ثلاث|اربع|خمس|ست|سبع|ثمان|تمان|تسع)\s*(?:ميه|مائه|مئه))';
        if (preg_match('/(?<![\p{L}])(?:ب|بسعر\s*)' . $amount . '(?![\p{L}])/u', $text)
            && !preg_match('/(?:بالف\s*(?:هنا|هنا وشفا|سلامه|شكر)|بخمسه\s*(?:نجوم|طرق)|بخمس\s*نجوم)/u', $text)) return true;
        if (preg_match('/(?:كيلو|وجبه|علبه|فسيخ|رنجه|سردين)\s+' . $amount . '(?![\p{L}])/u', $text)) return true;
        if (preg_match('/(?:بقد\s*ايه|بقداش|بشحال)/u', $text)) return true;
        if (preg_match('/قد\s*ايه/u', $text) && !preg_match('/(?:وقت|مده|بياخد|ياخد|بتاخد|ساعه|دقيقه)/u', $text)) return true;
        // A bare "كام" is a price question; explicit count/time questions can be answered normally.
        if (preg_match('/(?<![\p{L}])كام(?![\p{L}])/u', $text)
            && !preg_match('/كام\s*(?:فرع|ساعه|ساعات|سنه|سنين|يوم|ايام|نوع|صنف|اصناف|حبه|قطعه|وجبه|واحد|شخص|فرد|دقيقه|جرام|غرام|كيلو)|(?:عندكم|عندك|عندنا|عدد)\s+كام\s+(?:فرع|نوع|صنف|حبه|قطعه|وجبه)|(?:الساعه|ساعه|عدد\s*(?:السمك|الحبات|القطع|الاصناف|الفروع))\s*(?:\S+\s+){0,4}كام|(?:بتفتح|بتقفل|بيفتح|بيقفل)/u', $text)) return true;
        return false;
    }

    public static function priceResult(): array
    {
        return ['ok' => true, 'reason' => null, 'message' => self::priceRedirect(), 'kind' => 'PRICE'];
    }

    /** Validate actual generated prose separately from the model's untrusted kind hint. */
    public static function validateReply(string $message, array $productNames = []): array
    {
        if (!preg_match('//u', $message)) return self::skip('UNSAFE_REPLY');
        $message = trim($message);
        if ($message === '' || self::length($message) > self::MAX_MESSAGE_CHARACTERS
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\p{Cf}]/u', $message)) return self::skip('UNSAFE_REPLY');
        if (self::isPriceInquiry($message)) return self::priceResult();
        if (!preg_match('/\p{Arabic}/u', $message)) return self::skip('UNSAFE_REPLY');
        // Written amounts cannot slip through by omitting a currency symbol or the word "price".
        if (preg_match('/(?<![\p{L}])(?:مئتين|مائتين|ميتين|مائتان|مئتان|متين|الفين|عشرين|تلاتين|ثلاثين|اربعين|خمسين|ستين|سبعين|ثمانين|تمانين|تسعين|(?:تلات|ثلاث|اربع|خمس|ست|سبع|ثمان|تمان|تسع)\s*(?:ميه|مائه|مئه))(?![\p{L}])/u', self::normalize($message))) return self::priceResult();
        // No generated numbers: protects prices, phones, order identifiers and addresses alike.
        if (preg_match('/\p{N}|https?:|www\.|@|\b[a-z0-9-]+\.[a-z]{2,63}\b|(?:wa|t)\.me|[<>]/iu', $message)) return self::skip('UNSAFE_REPLY');
        $normal = self::normalize($message);
        // Menu names prove a name, not live stock, fulfillment, branch details or an order action.
        if (preg_match('/(?:عندنا|لدينا|بنقدم|نقدم|متوفر|موجود|متاح|زورنا|تلاقينا)|(?:هن|سوف\s*ن|تم\s*)(?:وصل|توصيل|تاكيد|الغاء|ارجاع|استرجاع|تحويل|بعت|راجع|تابع|رد|عوض|حل)|(?:طلبك|طلبكم|الطلب)\s*(?:اتقبل|اتجهز|اتشحن|اتلغي|اترد|اتاك|هيجيلك)|(?:هيجيلك|هيوصلك|سيتم\s*(?:تعويض|رد|استرداد))|(?:التوصيل|الدليفري)\s*(?:خلال|في\s*خلال|فوري|مجاني)|(?:عنواننا|عنوانك|عنوان\s*الفرع|(?:فرعنا|الفرع|المطعم|احنا)\s*في|شارع|ميدان|عماره|بجوار|فاتحين\s*من|بنفتح|مفتوحين|بنقفل\s*الساعه)|(?:مضمون|بيعالج|يعالج|يشفي)|(?:رقم|عنوان|حساب|رصيد|طلب)\s*(?:حضرتك|العميل|تليفونك|موبايلك)|(?:رقمك|رقمنا|تليفونك|موبايلك|ايميلك|حسابك|رصيدك|بطاقتك)|(?<![\p{L}])(?:غبي|كذاب|كداب|حمار|اهبل)(?![\p{L}])|(?:كلمه\s*السر|مفتاح\s*الواجهه|system\s*prompt|api\s*key)/iu', $normal)) return self::skip('UNSAFE_REPLY');
        // Common fish/menu nouns may only be mentioned when supplied by the live menu context.
        $grounding = self::normalize(implode(' ', array_filter($productNames, 'is_string')));
        foreach (['فسيخ', 'رنجه', 'سردين', 'سلمون', 'جمبري', 'سوشي', 'تونه', 'كابوريا', 'سيبيا', 'بلطي', 'بوري', 'ماكريل', 'دينيس', 'قاروص', 'برجر', 'بيتزا', 'salmon', 'sushi', 'herring', 'sardine', 'burger', 'pizza'] as $name) {
            if (mb_strpos($normal, $name, 0, 'UTF-8') !== false
                && mb_strpos($grounding, $name, 0, 'UTF-8') === false) return self::skip('UNGROUNDED_REPLY');
        }
        return ['ok' => true, 'reason' => null, 'message' => $message, 'kind' => 'REPLY'];
    }

    public static function skip(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => null, 'kind' => 'SKIP'];
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $text = preg_replace('/[\x{0640}\x{0610}-\x{061a}\x{064b}-\x{065f}\x{0670}\x{06d6}-\x{06ed}\p{Cf}]/u', '', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }
}
