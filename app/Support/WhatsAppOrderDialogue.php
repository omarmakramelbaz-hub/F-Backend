<?php

namespace App\Support;

/** Dialogue answers are separate from order extraction and can use only supplied business facts. */
final class WhatsAppOrderDialogue
{
    private const OFFICIAL_URLS = ['https://wa.me/c/201285545554', 'https://fasakhaninja.com/downloadApp'];
    public const FIELDS = ['NAME', 'PHONE', 'ADDRESS', 'ITEMS', 'QUANTITY', 'QUANTITY_MODE',
        'FULFILLMENT', 'BRANCH', 'LOCATION'];
    public const KINDS = ['MISSING', 'QUANTITY_ASSOCIATION', 'PRODUCT_CHOICE', 'OPTION_CHOICE',
        'UNREADABLE', 'CONFLICT', 'GREETING', 'CATALOG_INFORMATION', 'PRODUCT_INFORMATION', 'SOCIAL_REPLY'];

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
            $menu = in_array($context['kind'], ['PRODUCT_CHOICE', 'OPTION_CHOICE', 'CATALOG_INFORMATION', 'SOCIAL_REPLY'], true);
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
            // Customer data can select a reviewed response category, never supply
            // the words of a business reply or create a new response candidate.
            if ($context['kind'] === 'SOCIAL_REPLY' && ($context['item_name'] !== null
                || $context['candidates'] === []
                || $context['candidates'] !== self::socialCandidates($context['transcript']))) return false;
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
        if ($context['kind'] === 'SOCIAL_REPLY' && $choice['action'] === 'ASK'
            && count($choice['candidate_ids']) !== 1) return null;
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
        return ['action' => 'ASK', 'candidate_ids' => array_slice(array_column($context['candidates'], 'id'), 0,
                $context['kind'] === 'SOCIAL_REPLY' ? 1 : 6),
            'variant' => 'POLITE'];
    }

    /** WAIT renders empty: the coordinator must not send an empty message. */
    public static function render(array $context, array $choice): string
    {
        $choice = self::validateChoice($context, $choice);
        if ($choice === null) throw new \RuntimeException('INVALID_DIALOGUE_CHOICE');
        if ($choice['action'] === 'WAIT') return '';
        if ($context['kind'] === 'PRODUCT_INFORMATION') return $choice['text'];
        if ($context['kind'] === 'SOCIAL_REPLY') {
            $labels = array_column($context['candidates'], 'label', 'id');
            return $labels[$choice['candidate_ids'][0]];
        }
        $language = self::replyLanguage($context);
        if ($context['kind'] === 'GREETING')
            return $language === 'en' ? 'Welcome to Fasakhansta. How can we help, or what would you like to order?'
                : ($language === 'fr' ? 'Ahlan be7adretak fe Fasakhansta 😊 Ne2dar nesa3dak fe eh?'
                    : 'أهلاً بحضرتك، نورت فسخانستا 😊 تحب نساعدك بإيه؟');
        if ($language !== 'ar') return self::renderTranslatedQuestion($context, $choice, $language);
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

    /** Fixed wording only; the latest customer text selects language, never facts or authority. */
    private static function replyLanguage(array $context): string
    {
        if (!function_exists('config') || config('fasakhansta_ai_training.messages_enabled', false) !== true
            || !class_exists(\App\Services\Dashboard\FasakhanstaMessageTrainingFacts::class)) return 'ar';
        $latest = null;
        foreach ($context['transcript'] as $row) if ($row['speaker'] === 'customer') $latest = $row['text'];
        return is_string($latest) ? \App\Services\Dashboard\FasakhanstaMessageTrainingFacts::language($latest) : 'ar';
    }

    private static function renderTranslatedQuestion(array $context, array $choice, string $language): string
    {
        $fr = $language === 'fr';
        $polite = $choice['variant'] === 'POLITE' ? ($fr ? 'Law sama7t, ' : 'Please, ') : '';
        $item = $context['item_name'] === null ? '' : ($fr ? 'Bel nesba le «' : 'For «').$context['item_name'].'», ';
        if ($context['candidates'] !== []) {
            $labels = array_column($context['candidates'], 'label', 'id'); $lines = [];
            foreach ($choice['candidate_ids'] as $id) $lines[] = '• «'.$labels[$id].'»';
            if ($context['kind'] === 'CATALOG_INFORMATION')
                $text = ($fr ? "Di ba3d el asnaf el mota7a fel far3:\n" : "These are some items currently available at this branch:\n")
                    .implode("\n", $lines).($fr ? "\nTe7eb tafaseel 3an anhy senf?" : "\nWhich item would you like details about?");
            else
                $text = $polite.$item.($fr ? "di el ekhteyarat el mota7a:\n" : "these are the available options:\n")
                    .implode("\n", $lines).($fr ? "\nEkteb esm " : "\nWrite the ")
                    .($context['kind'] === 'OPTION_CHOICE' ? ($fr ? 'el no3' : 'option') : ($fr ? 'el senf' : 'item'))
                    .($fr ? ' elly te7ebbo.' : ' name you would like.');
        } else {
            $questions = $fr ? [
                'NAME' => 'momken esm 7adretak lel talab?',
                'PHONE' => 'momken ra2m tawasol le tafaseel el talab?',
                'ADDRESS' => '3enwan el tawseel bel tafseel eh?',
                'ITEMS' => 'te7eb totlob eh? Ekteb esm el senf el ma2sood.',
                'QUANTITY' => 'te7eb ad eh?',
                'QUANTITY_MODE' => 'el kammeya bel kilo wala bel ket3a?',
                'FULFILLMENT' => 'te7eb delivery wala fel sala aw takeaway?',
                'BRANCH' => 'te2sod anhy far3 aw madeena lel talab?',
                'LOCATION' => 'momken teb3at location 3enwan el tawseel hena fel chat?',
            ] : [
                'NAME' => 'what name should we use for the request?',
                'PHONE' => 'what phone number can we use to contact you about the request?',
                'ADDRESS' => 'what is the full delivery address?',
                'ITEMS' => 'what would you like to order? Please write the item name.',
                'QUANTITY' => 'how much would you like?',
                'QUANTITY_MODE' => 'is the quantity in kg or pieces?',
                'FULFILLMENT' => 'would you like delivery, dine-in or takeaway?',
                'BRANCH' => 'which branch or city do you mean for the request?',
                'LOCATION' => 'could you share the delivery location here in this chat?',
            ];
            $lead = $context['kind'] === 'UNREADABLE'
                ? ($fr ? 'Ma3lesh, ne7tag nowadda7 el goz2 da. ' : 'Sorry, we need to clarify this part. ')
                : ($context['kind'] === 'CONFLICT'
                    ? ($fr ? '3ashan net2akked men el bayanat el 7aleya, ' : 'To check the current details, ') : '');
            $text = $polite.$item.$lead.$questions[$context['field']];
            if ($context['kind'] === 'QUANTITY_ASSOCIATION' && $context['field'] === 'QUANTITY')
                $text .= $fr ? ' 3adad el samak fel kilo beye7added el 7agm; ne7tag kammeyet el talab nafsaha.'
                    : ' Fish count per kg describes size; we need the actual amount you want to order.';
        }
        if (!self::literal($text, 4096, true)) throw new \RuntimeException('INVALID_DIALOGUE_RENDER');
        return $text;
    }

    /**
     * Reviewed wording only. The model chooses a response, not arbitrary prose.
     * This function never copies customer text, a phone, a name or an order claim
     * into a reply. A customer category is not an order event or a handoff receipt.
     */
    public static function socialCandidates(array $transcript): array
    {
        if (!WhatsAppOrderExtraction::validateTranscript($transcript)['ok']
            || WhatsAppOrderExtraction::hasProbe($transcript)) return [];
        $latest = null;
        foreach ($transcript as $row) if ($row['speaker'] === 'customer') $latest = $row['text'];
        if (!is_string($latest) || trim($latest) === '') return [];
        $text = (string) preg_replace('/[\x{0640}\x{064b}-\x{065f}]/u', '', self::informationWords($latest));
        $language = preg_match('/\p{Arabic}/u', $text) ? 'ar'
            : (preg_match('/\b(?:7elw|7elwa|gamd|gamed|begad|shokran|shokran|3ayez|3awz|ta3ban|t3bt|et2akhar|mota2akher|salam|ezay|ento|enta)\b/iu', $text) ? 'fr' : 'en');
        // Serious cases take precedence over a greeting or compliment in the
        // same message. These replies never promise unperformed follow-up.
        $afterEating = preg_match('/(?:بعد.{0,15}(?:الاكل|اكلنا|اكلت|الوجبه)|اكلت|اكلنا)|\b(?:(?:after|since).{0,20}(?:eating|ate|meal)|(?:I|we)\s+ate)\b/iu', $text) === 1;
        $bodySymptoms = preg_match('/(?:بطن(?:ي|نا|ه|ها)?.{0,20}(?:وجع|الم)|(?:وجع|الم).{0,20}بطن|مغص|اسهال|غثيان)|\b(?:stomach.{0,15}(?:hurts?|aches?|pain)|abdominal.{0,15}pain|nausea|diarrh(?:ea|oea))\b/iu', $text) === 1;
        // A normal/safe question about changed colour, smell or taste needs
        // branch review too. Historical FAQ answers cannot establish safety.
        $sensory = '(?:لون|ريحه|رائحه|طعم|colou?r|smell|odou?r|taste)';
        $concern = '(?:غريب|مختلف|وحش|بايظ|تغير|تغيير|اتغير|متغير|اخضر|مخضر|خضرا|اسود|مسود|ازرق|مزرق|بقع|عفن|طبيعي|سليم|امن|عادي|\b(?:weird|strange|unusual|normal|safe|changed?|green(?:ish)?|black(?:ish)?|blu(?:e|ish)|off|rotten|spoiled)\b)';
        $food = '(?:فسيخ|رنجه|هيرنج|سردين|بطارخ|\b(?:feseekh|fesikh|fesekh|fiseekh|fish|herring|sardines?)\b)';
        $unexpectedColour = '(?:اخضر|مخضر|خضرا|اسود|مسود|ازرق|مزرق|\b(?:green(?:ish)?|black(?:ish)?|blu(?:e|ish))\b)';
        $productReview = preg_match('/'.$sensory.'.{0,60}'.$concern.'|'.$concern.'.{0,60}'.$sensory.'/iu', $text) === 1
            || preg_match('/'.$food.'.{0,60}'.$unexpectedColour.'|'.$unexpectedColour.'.{0,60}'.$food.'/iu', $text) === 1
            || preg_match('/(?:بايظ|فاسد)|\b(?:spoiled|rotten)\b/iu', $text) === 1;
        if (($afterEating && $bodySymptoms)
            || preg_match('/(?:تعبت|تعبان|اعياء|اعيان|تسمم|اتسممت|ترجيع|قيء|دوخه|دوار|تنميل|صعوبه.{0,10}(?:بلع|تنفس))|\b(?:sick|ill|vomit|poison|breathing|swallowing|ta3ban|t3bt)\b/iu', $text)) {
            $category = 'health';
        } elseif ($productReview) {
            $category = 'review';
        } elseif (preg_match('/(?:اتأخر|اتاخر|متأخر|متاخر|تاخير|تأخير|مش\s+وصل|موصلش|ماوصلش|غلط|ناقص|شكوي|شكوى|وحش|سيئ|مش\s+(?:حلو|كويس))|\b(?:late|delay|wrong|missing|complaint|awful|not\s+(?:good|nice)|et2akhar|mota2akher)\b/iu', $text)) {
            $category = 'complaint';
        } elseif (preg_match('/(?:انت|انتي|انتم).{0,15}(?:روبوت|بوت|ذكاء\s+اصطناعي|انسان|بشري)|\b(?:are\s+you\s+(?:a\s+)?(?:bot|human|AI))\b/iu', $text)) {
            $category = 'identity';
        } else {
            // Thanks or praise can accompany an approval, cancellation or wait
            // request. Preserve that turn for the existing order workflow.
            if (preg_match('/(?<![\p{L}\p{N}])(?:موافق|موافقه|اتفقنا|اكد(?:لي|لنا)?|اؤكد|تاكيد|تمام|بلاش|الغي|الغاء|كنسل|ارفض|استني|انتظر|توقف)(?![\p{L}\p{N}])|(?:مش\s+(?:عايز|عاوز|موافق))|\b(?:confirm(?:ed|ing)?|approv(?:e|ed|al)|cancel(?:led|ing|lation)?|stop|wait|ok(?:ay)?|yes|no\s+thanks|tamam|mowafe2|mowafeq|kensel|estanna|estana)\b/iu', $text)) return [];
            // Ordinary product questions and purchase requests stay in the
            // existing FAQ/order path, even if they also contain a compliment.
            if (preg_match('/[?؟]|(?:بكام|سعر|اسعار|الفرق|مواعيد|فرع|عنوان|اطلب|عايز|عاوز|زود|ضيف|حول|ادفع)|\b(?:what|why|how|when|price|order|want|send|pay|transfer|3ayez|3awz)\b/iu', $text)) return [];
            if (preg_match('/\A\s*(?:السلام\s+عليكم|سلام\s+عليكم|اهلا|اهلين|هاي|هالو|صباح\s+(?:الخير|الفل|النور)|مساء\s+(?:الخير|الفل|النور)|hello|hi|hey|salam)[\s\p{P}\p{S}]*\z/iu', $text)) {
                $category = 'greeting';
            } elseif (preg_match('/(?:حلو|جميل|جامد|روعه|تحفه|عظمه|ممتاز|تسلم|بحبكم|شكرا)|\b(?:love|lovely|great|amazing|delicious|thanks|thank\s+you|7elw|7elwa|gamd|gamed|begad|shokran)\b/iu', $text)
                || preg_match('/\A[\s\p{P}\p{S}\x{FE0F}\x{200D}]+\z/u', $text)) {
                $category = 'praise';
            } else return [];
        }
        $phrases = [
            'ar' => [
                'health' => ['آسفين إنك تعبت. اطلب رعاية طبية عاجلة دلوقتي وما تستناش رد الشات. ابعت رقم الطلب والتفاصيل هنا، وتواصل مع الفرع لمراجعة الحالة.'],
                'review' => ['آسفين على اللي حصل. ما تذوقش المنتج للتجربة؛ ابعت صورته ورقم الطلب هنا وتواصل مع الفرع لمراجعته. ما نقدرش نأكد سلامته من الشات.'],
                'complaint' => ['حقك علينا وآسفين على التجربة دي. ابعت رقم الطلب والتفاصيل هنا في الخاص علشان نحدد الحالة ونوجّهك للفرع.',
                    'آسفين على اللي حصل. ممكن رقم الطلب وتفاصيل المشكلة هنا في الخاص؟ من غير بيانات زيادة، علشان نراجع الحالة مع الفرع.'],
                'identity' => ['أنا مساعد آلي لفسخانستا، أقدر أساعدك في المعلومات والطلب، والحالات اللي محتاجة قرار من الفرع نوجّهك له.'],
                'greeting' => ['أهلاً بحضرتك، نورت فسخانستا 😊 تحب نساعدك بإيه؟',
                    'أهلاً وسهلاً يا فندم 😊 اتفضل، تحت أمرك.',
                    'أهلاً يا فندم، تحب تعرف إيه أو تطلب إيه؟'],
                'praise' => ['ده من ذوق حضرتك 😊 نورت فسخانستا!',
                    'تسلم يا فندم، كلام حضرتك يفرّحنا 😊',
                    'شكرًا لذوق حضرتك، دايمًا تنورنا 😊'],
            ],
            'en' => [
                'health' => ['Sorry you feel unwell. Seek urgent medical care now; do not wait for a chat reply. Share your order number and details here privately and contact the branch about the incident.'],
                'review' => ['Sorry about this. Do not taste the product to test it. Share a photo and order number here privately and contact the branch for review. We cannot confirm its safety over chat.'],
                'complaint' => ['Sorry about your experience. Please send your order number and the problem here privately so we can identify the case and direct you to the branch.'],
                'identity' => ['I am Fasakhansta’s automated assistant. I can help with information and orders; decisions needing staff are referred to the branch.'],
                'greeting' => ['Welcome to Fasakhansta 😊 How can we help?', 'Hello, welcome to Fasakhansta. What would you like to know?'],
                'praise' => ['Thank you, that is very kind 😊', 'Your kind words made our day 😊'],
            ],
            'fr' => [
                'health' => ['Asefeen enak ta3ban. Etlob mosa3da tebbeya 3agla delwa2ty, w matestannash rad el chat. Eb3at rakam el order wel tafaseel hena private, w etwasal ma3 el far3.'],
                'review' => ['Asefeen 3ala elly 7asal. Matdo2sh el montag 3ashan tegarab. Eb3at sora w rakam el order hena private, w etwasal ma3 el far3. Man2darsh ne2akked salamto men el chat.'],
                'complaint' => ['7a2ak 3aleena w asefeen 3ala elly 7asal. Eb3at rakam el order wel moshkela hena private, 3ashan ne3raf el 7ala w newaggahak lel far3.'],
                'identity' => ['Ana mosa3ed aly le Fasakhansta. A2dar asa3dak fel ma3loomat wel order, wel 7agat elly me7taga karar men el far3 newaggahak leeh.'],
                'greeting' => ['Ahlan be7adretak fe Fasakhansta 😊 Ne2dar nesa3dak fe eh?', 'Ahlan ya fandem 😊 Etfaddal, ta7t amrak.'],
                'praise' => ['Da men zo2 7adretak 😊 Nawart Fasakhansta!', 'Teslam ya fandem, kalam 7adretak yefarra7na 😊'],
            ],
        ];
        $result = [];
        foreach ($phrases[$language][$category] as $phrase) {
            if (!self::literal($phrase, 240)) return [];
            $result[] = ['id' => 'c'.(count($result) + 1), 'label' => $phrase];
        }
        return $result;
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
        if ($selected === [] || !self::literal($choice['text'], 700, true)) return null;
        // Numeric checks alone cannot bind a price to its product, or preserve a
        // negative availability/preparation fact. Those business clauses are copied
        // exactly with their subjects; generated wording never reassigns them.
        $source = implode("\n", $selected);
        // The existing lexical claim guard was reviewed mainly for Arabic.
        // Reviewed English/Franco facts therefore render literally too. Build
        // this protected output before inspecting discarded generated prose:
        // a restated question subject must not reject an otherwise valid fact
        // selection, and an invented date or price must never reach the reply.
        if (self::sensitiveInformation($source) || !preg_match('/\p{Arabic}/u', $source)
            || preg_match('/\p{Latin}/u', $source)) {
            $choice['text'] = self::factualAnswer($source, $choice['variant']);
        }
        // The returned text, whether canonical or generated, passes the same
        // URL, business-claim and size guards against the selected source facts.
        if (!self::literal($choice['text'], 700, true) || !self::respectfulAddress($choice['text'])
            || !self::safeInformationText($choice['text'])
            || array_diff(self::informationUrls($choice['text']), self::informationUrls($source)) !== []
            || !self::groundedInformation($choice['text'], $source)) return null;
        return $choice;
    }

    /** The owner wants respectful warmth, not slang forms of address. */
    private static function respectfulAddress(string $text): bool
    {
        return preg_match('/(?:يا\s*(?:كبير|كبار|جميل|باشا|هانم|معلم|برنس|وحش|ريس|اسطي|نجم))(?!\p{L})'
            .'|\bya\s+(?:kbeer|kebeer|kbeir|gameel|gamil|basha|pasha|hanem|brens|m3allem)\b/iu',
            (string) preg_replace('/[\x{0640}\x{064b}-\x{065f}]/u', '', self::informationWords($text))) !== 1;
    }

    private static function factualAnswer(string $facts, string $variant): string
    {
        // Friendly phrasing is a fixed presentation wrapper. The selected price,
        // unit, availability and safety sentences remain byte-for-byte intact.
        if (!function_exists('config') || config('fasakhansta_ai_training.messages_enabled', false)!==true
            || $variant!=='FRIENDLY' || substr_count($facts,"\n")>=3) return $facts;
        $words=$facts;
        foreach (self::OFFICIAL_URLS as $url) $words=str_replace($url,'',$words);
        if (!preg_match('/\p{Arabic}/u',$words) || preg_match('/\p{Latin}/u',$words)
            || preg_match('/(?:اعراض|رعايه|(?<!\p{L})طبي(?:ه|ب)?(?!\p{L})|تعب|حساسيه|تنفس|بلع|تسمم|سلامه|ريحه|رائحه|لون|طعم|سليم|مشكله|اسفين|لسه\s+محتاج|غير\s+(?:متاح|متوفر|مؤكد)|مش\s+(?:متاح|موجود|متوفر)|مفيش|لا\s+(?:يوجد|يمكن|تتوفر))/u',self::informationWords($words))) return $facts;
        $prefix='من عنينا يا فندم';
        $reply=$prefix."\n".$facts;
        return preg_match_all('/./us',$reply)<=700?$reply:$facts;
    }

    private static function sensitiveInformation(string $facts): bool
    {
        if (preg_match('/(?:ميعاد|موعد)\s+التوفر|(?:التفصيله|المعلومه)\s+دي\s+محتاجه\s+تاكيد'
            .'|اعراض|رعايه|(?<!\p{L})طبي(?:ه|ب)?(?!\p{L})|تعب|تنفس|بلع|تسمم|سلامه|ريحه|رائحه|مشكله|اسفين/u',
            self::informationWords($facts))) return true;
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
