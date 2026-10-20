<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure owner-reference FAQ selector for the existing typed private dialogue.
 *
 * It returns businessInformationFacts-compatible rows, not a reply, order,
 * price quote, stock check or authorization to send. The existing workflow
 * assigns f1.. IDs, supplies current branch-menu facts, and validates choices.
 */
final class FasakhanstaMessageTrainingFacts
{
    public const REFERENCE_ID = 'fasakhansta-owner-20261020';

    public static function fromReference(
        array $reference,
        string $question,
        ?DateTimeImmutable $at = null,
        bool $includeIndicativePrices = false,
        ?string $languageOverride = null
    ): array {
        if (($reference['reference_id'] ?? null) !== self::REFERENCE_ID
            || ($reference['schema_version'] ?? null) !== 1
            || strlen($question) > 40000 || !preg_match('//u', $question)
            || ($languageOverride !== null && !in_array($languageOverride, ['ar','en','fr'], true))) return [];

        $language = $languageOverride ?? self::language($question);
        $native = self::normal($question);
        $text = self::semanticQuestion($question);
        $facts = [];
        $add = static function (string $topic, string $subject, string $value) use (&$facts, $language, $reference): void {
            if ($language === 'ar') $value = str_replace('في الخاص', 'هنا', $value);
            else {
                $translated = self::translatedFact($topic, $subject, $value, $language, $reference);
                if ($translated === null) return;
                [$subject, $value] = $translated;
            }
            if (count($facts) >= 12 || !self::literal($subject, 240) || !self::literal($value, 500)) return;
            foreach ($facts as $fact) if ($fact['topic'] === $topic && $fact['subject'] === $subject) return;
            $facts[] = ['topic' => $topic, 'subject' => $subject, 'text' => $value];
        };
        $has = static fn (string $pattern): bool => preg_match($pattern, $text) === 1 || preg_match($pattern, $native) === 1;
        $branches = is_array($reference['branches'] ?? null) ? $reference['branches'] : [];
        $selected = self::selectedBranches($branches, $text);
        $clock = ($at ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->setTimezone(new DateTimeZone('Africa/Cairo'));

        $abdominalPain = $has('/(?:بطن(?:ي|ه|ها)?|معد(?:ه|تي|ته|تها)).{0,18}(?:وجع|الم|مغص)|(?:وجع|الم|مغص).{0,18}(?:بطن|معده)'
            .'|\b(?:stomach|abdomen|abdominal)\b.{0,24}\b(?:hurts?|hurting|pain|aches?|cramps?)\b'
            .'|\b(?:pain|aches?|cramps?)\b.{0,24}\b(?:stomach|abdomen|abdominal)\b/iu');
        $foodContext = $has('/(?:اكلت|اكلنا|بعد\s+(?:الاكل|ما\s+اكلت|الفسيخ|الرنجه|السردين|الوجبه))'
            .'|\b(?:after\s+(?:eating|i\s+ate|our\s+meal)|ate)\b/iu');
        if (($abdominalPain && $foodContext)
            || $has('/(?:تعب|اعياء|اعيا|اعيان|تسمم|اتسممت|ترجيع|قيء|دوخه|دوار|تنميل|بلع|تنفس)|\b(?:sick|ill|vomit|poison|breathing|swallow|ta3ban|t3bt)\b/iu')) {
            $add('HEALTH_ESCALATION', 'أعراض بعد الأكل',
                'لو فيه أعراض أو تعب بعد الأكل، اطلب رعاية طبية عاجلة ولا تنتظر رد الفرع. ابعت تفاصيل الحالة ورقم الطلب في الخاص علشان الفرع يراجع المشكلة.');
            return $facts;
        }
        // Possessive forms keep the customer wording (ريحته، ريحتها، طعمه)
        // connected to the concern; praise about taste alone is not a complaint.
        $sensoryNoun = '(?:لون(?:ه|ها|هم|ك|نا|ي)?|ريحه|ريحت(?:ه|ها|هم|هن|ك|كم|نا|ي)|رائحه|رائحت(?:ه|ها|هم|هن|ك|كم|نا|ي)|طعم(?:ه|ها|هم|ك|نا|ي)?)';
        $sensoryState = '(?:اتغير|تغير|متغير|اخضر|مخضر|خضرا(?:ء)?|طبيعي|عادي|امن|سليم|ينفع|يتاكل)';
        $sensoryConcern = $has('/'.$sensoryNoun.'.{0,45}'.$sensoryState
            .'|(?:اتغير|تغير|متغير|اخضر|مخضر|خضرا(?:ء)?).{0,25}'.$sensoryNoun
            .'|\b(?:colou?r|smells?|tastes?)\b.{0,45}\b(?:change|changed|changing|green|greenish|normal|safe|okay|ok|strange|bad|off)\b'
            .'|\b(?:change|changed|changing|green|greenish|normal|safe|okay|ok|strange|bad|off)\b.{0,45}\b(?:colou?r|smells?|tastes?)\b/iu');
        $greenFish = $has('/(?:فسيخ|رنجه|سردين|بطارخ|سمك|منتج).{0,30}(?:مخضر|اخضر|خضرا(?:ء)?)'
            .'|(?:مخضر|اخضر|خضرا(?:ء)?).{0,30}(?:فسيخ|رنجه|سردين|بطارخ|سمك|منتج)'
            .'|\b(?:feseekh|fesikh|fessikh|herring|sardines?|fish|roe|product)\b.{0,30}\bgreen(?:ish)?\b'
            .'|\bgreen(?:ish)?\b.{0,30}\b(?:feseekh|fesikh|fessikh|herring|sardines?|fish|roe|product)\b/iu');
        if ($sensoryConcern || $greenFish
            || $has('/'.$sensoryNoun.'.{0,20}(?:غريب|مختلف|وحش|بايظ)|(?:بايظ|فاسد)|\b(?:spoiled|strange\s+(?:smell|color|taste))\b/iu')) {
            $add('PRODUCT_REVIEW', 'مراجعة منتج غير معتاد',
                'مينفعش نطمنك على سلامة المنتج من وصف أو صورة. ماتتذوقوش للتجربة؛ ابعت صورة وتفاصيل المشكلة للفرع في الخاص علشان يتراجع المنتج.');
            return $facts;
        }
        if ($has('/(?:اتمني|عايز|عاوز|ازاي|طريقه).{0,20}(?:املح|تمليح)|(?:املح|تمليح).{0,15}(?:البيت|منزلي)|\bhome\s+salting\b/iu')) {
            $add('SAFETY_SCOPE', 'تمليح منزلي', 'خدمة العملاء مابتقدمش إرشادات لتمليح الفسيخ في البيت أو تأكيد سلامته.');
            return $facts;
        }
        if (self::priceSubjectMissing($question)) {
            $add('PRICE_SUBJECT', 'الصنف المقصود بالسعر', 'حضرتك تقصد سعر أنهي صنف؟');
            return $facts;
        }
        if ($language==='ar' && $has('/(?:هيتوفر|يتوفر|متوفر|التوفر|هينزل|ينزل\s+تاني).{0,30}(?:امتي|امتى|امتا|متي)|(?:امتي|امتى|امتا|متي).{0,30}(?:هيتوفر|يتوفر|متوفر|التوفر|هينزل|ينزل\s+تاني)/u')) {
            $add('STOCK_TIMING','ميعاد التوفر','ميعاد التوفر بيتأكد من الفرع. تقصد أنهي فرع أو منطقة؟');
            return $facts;
        }
        if ($has('/(?:فريزر|تخزين|حفظ|احفظ|يفك|تسييح|تجميد|مجمد|بره\s+التلاجه)|\b(?:storage|freez|thaw|defrost)/iu')) {
            if ($has('/(?:رنجه|هيرنج|هيرينج|herring)/iu') && !$has('/(?:فسيخ|feseekh|fesikh)/iu')) {
                $add('STORAGE', 'حفظ الرنجة', 'اسأل الفرع عن سبب تجميد العبوة وتعليمات حفظ وفك تجميد الرنجة المتاحة، واتبع تعليمات الحفظ على العبوة.');
                return $facts;
            }
            $add('STORAGE', 'الحفظ وتعليمات العبوة',
                'الفسيخ يتحفظ في الفريزر. اسأل الفرع عن تعليمات فك التجميد المعتمدة لمنتجك واتبع تعليمات الحفظ على العبوة؛ ماتسيبش السمك يفك في حرارة الغرفة.');
        }

        $closed = [];
        if ($has('/(?:دلوقتي|حالا|الان|توصيل|دليفري|بيوصل|هيوصل|يوصل|بتوصل)|\b(?:now|deliver|arriv)/iu')) {
            foreach ($selected as $branch) {
                // "فسيخ نبروه" is a product type, not a selected fulfillment branch.
                if (strpos(self::normal($branch['name']), 'نبروه') !== false && $has('/فسيخ.{0,8}نبروه/u')
                    && !$has('/(?:فرع|فروع|مواعيد|انا\s+(?:في|من)|من\s+نبروه)/u')) continue;
                if (!self::preparingOpen($branch, $clock)) $closed[] = $branch;
            }
            foreach ($closed as $branch) self::hoursFact($branch, $add);
            if ($closed !== []) $add('AFTER_HOURS', 'التجهيز خارج المواعيد', 'الوقت الحالي خارج مواعيد تجهيز الفرع المذكور؛ التجهيز يبدأ لما الفرع يفتح، ومدة التوصيل مش بتبدأ قبل التجهيز.');
        }

        if ($has('/(?:مواعيد|ساعات\s+العمل|شغال|فاتح|فاتحين|بتفتح|بتقفل|التجهيز|وقت\s+العمل)|\b(?:hours|opening|closing|open|close)\b/iu')) {
            if ($selected !== []) {
                foreach ($selected as $branch) self::hoursFact($branch, $add);
            } else {
                $add('HOURS', 'مواعيد تجهيز نبروه والمنصورة والمحلة',
                    'بنجّهز طلبات نبروه والمنصورة والمحلة من 10:00 الصبح لحد 00:30 بعد نص الليل بتوقيت القاهرة.');
                $add('HOURS', 'مواعيد تجهيز شبرا الخيمة',
                    'بنجّهز طلبات شبرا الخيمة من 12:00 الضهر لحد 00:30 بعد نص الليل بتوقيت القاهرة.');
            }
            $add('AFTER_HOURS', 'الطلبات خارج مواعيد التجهيز',
                'تقدر تبعت طلبك خارج المواعيد، والتجهيز يبدأ أول ما الفرع يفتح.');
        }

        if ($has('/(?:ازاي|كيف).{0,35}(?:اطلب|اوردر|طلب)|(?:اطلب|طلب|اوردر).{0,35}(?:ازاي|كيف)|طريقه\s+الطلب|طرق\s+الطلب|\bhow.{0,25}order\b/iu')) {
            $add('ORDER', 'طريقة الطلب',
                'تقدر تطلب من الكتالوج أو التطبيق أو ماسنجر أو واتساب. اختار الأصناف والكميات، وابعت اسمك والعنوان بالتفصيل واللوكيشن ورقم موبايل أساسي، ورقم تاني لو متاح، في الخاص. تفاصيل الطلب تتراجع معاك قبل التنفيذ.');
            $add('ORDER_ROUTING', 'قناة الطلب والفرع',
                'طلبات التطبيق بتكون من أقرب فرع حسب التطبيق، ولكل فرع أسعاره الخاصة. طلبات الشات وواتساب بتخرج غالبًا من شبرا الخيمة عن طريق إن درايف، والتوصيل حسب المنطقة.');
        }
        if ($has('/(?:منيو|مينيو|كتالوج|كتالوك|قائمه\s+(?:الطعام|الاصناف))|\b(?:menu|catalog)\b/iu')) {
            $url = $reference['official_links']['catalog'] ?? null;
            if ($url === 'https://wa.me/c/201285545554') {
                $add('MENU','كتالوج واتساب الرسمي',
                    'المنيو بالصور والأسعار على كتالوج واتساب الرسمي: '.$url.' . تقدر تضيف اللي يعجبك للسلة وتشوف تفاصيله هناك؛ الأسعار ممكن تختلف حسب الفرع والتطبيق.');
                $add('MENU_DELIVERY','التوصيل والشحن مع المنيو',
                    'الدليفري جوه القاهرة الكبرى والمنصورة والمحلة ونبروه بياخد تقريبًا من 60 لـ90 دقيقة، ومتاح شحن للمحافظات على الموقف في نفس اليوم حسب مواعيد التجهيز والشحن.');
                $add('MENU_FEE','معرفة تكلفة التوصيل',
                    'تكلفة التوصيل بيحددها المندوب حسب العنوان واللوكيشن. تقدر تبعت منطقتك والعنوان بالتفصيل واللوكيشن هنا علشان تتراجع التكلفة.');
            }
        }
        if ($has('/(?:تطبيق|ابليكيشن|ابلكيشن|تنزيل|تحميل)|\b(?:app|application|download)\b/iu')) {
            $url = $reference['official_links']['application'] ?? null;
            if ($url === 'https://fasakhaninja.com/downloadApp') $add('APP', 'تطبيق فسخانستا الرسمي', 'رابط تطبيق فسخانستا الرسمي: '.$url);
        }
        $onlineRegion=$has('/(?:التجمع|اكتوبر|المعادي|المهندسين|العبور|حلوان|مدينتي)/u');
        if ((!$onlineRegion||$selected!==[])&&$has('/(?:فرع|فروع|عناوين|عنوانكم|اماكن|مقر|تليفون|هاتف|ارقام)|\b(?:branch|branches|address|phone)\b/iu')) {
            foreach ($selected ?: $branches as $branch) {
                if (!self::branchValid($branch)) continue;
                $add('BRANCHES', 'فرع '.$branch['name'], 'فرع '.$branch['name'].': '.$branch['address'].'. رقم التواصل: '.$branch['phone'].'.');
            }
            if (strpos($text, 'طنطا') !== false) $add('NEAREST_BRANCH', 'أقرب فرع لطنطا', 'أقرب فرع لطنطا هو المحلة الكبرى في ميدان البندر.');
        }
        if ($onlineRegion) {
            $add('ONLINE', 'الفروع الفعلية والستور الأونلاين',
                'حلوان ومدينتي والتجمع و6 أكتوبر والمعادي والمهندسين والعبور ستورات أونلاين، مش فروع فعلية. تقدر تطلب من التطبيق، أو توصيل من شبرا الخيمة عن طريق إن درايف.');
        }
        if ($has('/(?:صاله|جلوس|قعده|داخل\s+الفرع|حجز\s+ترابيزه)|\b(?:dine|seating|table)\b/iu')) {
            $add('DINE_IN', 'الصالة', 'الفروع الفعلية فيها صالة جلوس ومش محتاجة حجز؛ تنور خلال مواعيد التشغيل. الستورات الأونلاين مفيهاش صالة للزوار.');
        }
        if ($has('/(?:توصيل|دليفري|شحن|موقف|بتوصل|بيوصل|هيوصل|يوصل)|\b(?:deliver|shipping|arriv)/iu')) {
            if ($has('/(?:برا\s+مصر|خارج\s+مصر|الاردن)|\b(?:outside\s+egypt|jordan|international)\b/iu')) {
                $add('INTERNATIONAL', 'الشحن خارج مصر', 'الشحن خارج مصر مش متاح.');
            } else {
                if ($closed === []) $add('ETA', 'مدة الدليفري المحلي',
                    'الدليفري داخل القاهرة الكبرى ومنها قليوب، والمنصورة والمحلة ونبروه والمدن القريبة، بياخد تقريبًا من 60 إلى 90 دقيقة حسب ظروف التوصيل.');
                if ($has('/(?:محافظه|محافظات|طنطا|الشرقيه|اسكندريه|موقف|شحن)|\b(?:governorate|tanta|alexandria|shipping)\b/iu')) {
                    $add('AREAS', 'الشحن للمحافظات',
                        'الشحن لباقي المحافظات زي الشرقية وطنطا وإسكندرية بيكون على الموقف العمومي، ومدته تقريبًا من 2 إلى 3 ساعات. السواق بيكلمك برقم تليفونه ورقم العربية ومكان الاستلام.');
                }
                if ($has('/(?:بكام|تكلفه|رسوم|سعر)|\b(?:cost|price|fee)\b/iu')) $add('DELIVERY_FEE', 'رسوم التوصيل',
                    'تكلفة التوصيل حسب المنطقة. ابعت العنوان في الخاص علشان نقدر نحددها.');
            }
        }
        if ($has('/(?:طرق|طريقه|وسائل).{0,15}(?:دفع|سداد)|(?:فودافون|انستاباي|انستا\s+باي|كاش)|\b(?:Vodafone|InstaPay|payment|cash)\b/iu')) {
            // Existing dialogue proof rejects payment instructions/account destinations.
            $add('METHODS', 'الوسائل المتاحة', 'الوسائل المتاحة: كاش عند الاستلام، وVodafone Cash أو InstaPay قبل خروج المندوب. اسأل الفرع عن البيانات المعتمدة.');
        }
        if ($has('/(?:حجز|احجز|مسبق|بكره|بكرا|يوم\s+(?:تاني|اخر))|\b(?:preorder|reservation|tomorrow)\b/iu')) $add('PREORDER', 'الحجز ليوم أو وقت محدد',
            'حجز طلب ليوم أو وقت محدد غير متاح؛ الطلب يكون في نفس اليوم. زيارة الفرع لا تحتاج حجزًا مسبقًا.');
        if ($has('/(?:جمله|بالجمله)|\bwholesale\b/iu')) $add('WHOLESALE', 'طلبات الجملة',
            'الحد الأدنى لطلبات الجملة 50 كيلو، والتواصل يكون مع الفرع مباشرة؛ طلبات الجملة ليست أونلاين.');
        if ($has('/(?:طلب|اوردر).{0,25}(?:اضافه|ازود|تعديل|المندوب|خرج)|(?:اضافه|ازود|تعديل|المندوب).{0,25}(?:طلب|اوردر)/u')) $add('EXISTING_ORDER', 'تعديل طلب قائم',
            'الطلب مايتعدلش بعد ما يتحرك مع المندوب. لأي استفسار عن طلب قائم، ابعت رقم الطلب في الخاص لمراجعة الفرع.');
        if ($has('/(?:استرجاع|ارجاع|ارجع|يرجع|مرتجع|مرتجعات)|\b(?:return|refund)\b/iu')) $add('RETURNS', 'سياسة الاسترجاع',
            'الاسترجاع خلال 12 ساعة، بشرط حفظ المنتج في الفريزر لحد رجوعه للفرع. الاسترجاع في الفرع ومش عن طريق الدليفري؛ ابعت تفاصيل الحالة للفرع لمراجعتها.');
        if ($has('/(?:عروض|عرض|خصم|خصومات)|\b(?:offers?|promotions?|discounts?)\b/iu')) $add('PROMOTIONS', 'العروض',
            'تابعونا، العروض بتنزل على الصفحة أول بأول 😉');

        $typeComparison = $has('/نبروه/u') && $has('/دسوق/u') && $has('/(?:فرق|يفرق|اختلاف|مقارنه|ملح|ملوحه)/u');
        if (($has('/(?:فسيخ|فسخ)/u') && $has('/(?:نبروه|دسوق|ملح|ملوحه|الفرق|يفرق)/u')) || $typeComparison) $add('SALT', 'فسيخ نبروه ودسوق',
            'فسيخ نبروه ملحه خفيف ودلع وسمين، وفسيخ دسوق ملحه مظبوط وطعمه متوسط مش حادق. الاتنين من نفس المصنع ونفس الجودة، والفرق في الملح.');
        if ($has('/(?:مخلي|متنضف|متنظف|تنظيف|سنجاري)/u')
            || ($has('/تجهيز/u') && $has('/(?:فسيخ|رنجه|سردين|سمك)/u'))) $add('PREPARATION', 'الكامل والمتنضف والمخلي',
            'في تجهيز الفسيخ: الكامل سمكة كاملة، والمتنضف مفتوح سنجاري والبطن نضيفة والرأس مقطوعة والجلد موجود. المخلي لحم بس عليه الزيت المخصوص. اختيارات كل صنف حسب منيو الفرع.');
        if ($has('/(?:صافي|وزن|يتوزن|بعد\s+التنظيف|قبل\s+التنظيف|كيلو.{0,15}مخلي)/u')) $add('WEIGHT', 'الوزن قبل التنظيف والمخلي الصافي',
            'كيلو بعدد سمكات مخلي بيتوزن كامل قبل التنضيف، فبيطلع تقريبًا نص الكمية لحم. أما مخلي صافي فده وزن اللحم بعد التنضيف. نفس الفرق في الفسيخ والرنجة والسردين.');
        if ($has('/(?:فسيخ|فسخ)/u') && $has('/(?:حجم|وسط|حبه|حبات|سمكه|سمكات|نص\s+كيلو|نصف\s+كيلو)/u')) $add('FISH_SIZE', 'أحجام الفسيخ',
            'الأحجام حسب المتاح: كيلو 4 حبات، أو 3 حبات وهو الوسط، أو حبتين. وممكن تسأل الفرع عن سمكة واحدة بالوزن المطلوب. مفيش نصف كيلو كحجم ثابت؛ عدد السمك في الكيلو بيحدد حجم السمكة.');
        if ($has('/(?:فسيخ|فسخ)/u') && $has('/(?:مبطرخ|بطارخ)/u')) {
            if ($clock->format('Y-m-d') < '2026-11-15') $add('ROE', 'موسم الفسيخ المبطرخ',
                'موعد التوفر الموسمي الكامل المتوقع للفسيخ المبطرخ من 15 نوفمبر 2026. قبل كده اللي ظاهر في الفيديوهات بشاير حوالي 5%، فمش مضمون السمكة توصلك مبطرخة. اسأل الفرع عن المتاح دلوقتي.');
            else $add('ROE', 'موسم الفسيخ المبطرخ',
                'موعد بداية الموسم المتوقع كان 15 نوفمبر 2026. التوفر الفعلي للفسيخ المبطرخ دلوقتي حسب مخزون كل فرع؛ ابعتلنا الفرع علشان نراجع المتاح.');
        }
        if ($has('/(?:رنجه|هيرنج|هيرينج|herring)/iu')) $add('HERRING', 'أنواع الرنجة',
            'الأنواع: رنجة هيرنج، ورنجة هيرنج مبطرخة، ورنجة مدخنة على خشب شجر الليمون. التجهيز كامل أو متنضف أو مخلي حسب منيو الفرع، وعلب المخلي 200 و400 جم.');
        if ($has('/(?:رنجه|هيرنج|هيرينج)/u') && $has('/(?:مبطرخ|ناعمه|خرز)/u')) $add('ROE', 'الرنجة الخرز والناعمة',
            'الرنجة المبطرخة الخرز 100% خرز، والناعمة سمينة بس مش 100% خرز. التوفر الحالي حسب منيو الفرع.');
        if ($has('/(?:سردين|بساريا|sardine)/iu')) $add('SARDINE', 'السردين',
            'السردين بلدي، والبساريا مش متاحة. ربع الكيلو حوالي 3 إلى 4 حبات. التجهيز كامل أو متنضف أو مخلي، والعلب المملحة 200 و400 جم؛ التوفر الحالي حسب الفرع.');
        if ($has('/بطارخ/u') && !$has('/(?:فسيخ|رنجه|هيرنج|مبطرخ)/u')) $add('ROE', 'عبوة البطارخ',
            'البطارخ علبة 400 جم بس، ومفيش بيع بالوزن أو عبوة أصغر. التوفر مرتبط بالموسم ومخزون الفرع. تاريخ العبوة قريب، ومش شرط يكون تاريخ النهارده؛ اسأل الفرع عن العبوة المتاحة.');
        if ($has('/(?:سندوتش|سندويتش|ساندوتش)/u') && $has('/فسيخ/u')) $add('SANDWICH', 'سندوتش الفسيخ الدسوقي',
            'سندوتش الفسيخ الدسوقي 180 جم بعيش بلدي أو أبيض ومعاه رغيف زيادة فاضي. الكومبو سندوتش وكيس شيبسي وبيبسي؛ الاختيارات والتوفر حسب منيو الفرع.');
        if ($has('/(?:وجبه|وجبات|معاها|معاهم|اضافات|مكونات)/u')) $add('MEAL', 'الوجبات والإضافات',
            'الوجبات معاها رز وسلطة وطحينة وعيش، ووجبة الفسيخ فيها ربع كيلو فسيخ. ممكن تطلب إضافة عيش وليمون حسب الاختيارات المتاحة في منيو الفرع.');
        if (self::priceQuestion($question)) {
            // A caller may supply indicative FAQ prices only without a current
            // branch catalog. They never become an order quote or a total.
            if ($includeIndicativePrices && $language === 'ar') self::indicativePrices($reference, $text, $add);
            if (in_array('REFERENCE_PRICE', array_column($facts, 'topic'), true))
                $facts=array_values(array_filter($facts,static fn(array $fact):bool=>$fact['topic']==='REFERENCE_PRICE'));
            elseif (!in_array('MENU',array_column($facts,'topic'),true))
                $add('PRICE_POLICY', 'مرجع السعر',
                    $language === 'ar'
                        ? 'الأسعار ممكن تختلف حسب الفرع والتطبيق، والمحدّث في الكتالوج: https://wa.me/c/201285545554'
                        : 'الأسعار الاسترشادية ممكن تختلف حسب الفرع والتطبيق. السعر المحدّث في الكتالوج أو منيو الفرع؛ سعر الصنف لوحده مش إجمالي الطلب والتوصيل.');
        }
        return $facts;
    }

    /** Query spelling is a routing hint only; no aliases become order evidence. */
    public static function language(string $question): string
    {
        if (preg_match('/\p{Arabic}/u', $question) || !preg_match('/[a-z]/iu', $question)) return 'ar';
        if (preg_match('/\A\s*(?:what|where|when|why|how|can|could|do|does|is|are|i|my|we|our)\b/iu', $question)) return 'en';
        if (preg_match('/\b(?:ya|eh|fein|fen|ezay|ezzay|3ayez|3aiz|3awz|3nd|3and|3ala|enta|ento|kbeer|bekam|bkam|menio|law|msh|mesh|7elw|7aga|delwa2ti|delwa2ty|tawseel|sha7n|gomla|ra2m|far3|mawa3eed|mel7|makhly|safi|botarekh|a7gez|bokra|talab|atlob|otlob|a7faz|far2|3orood|ta3ban|t3bt|akalt|batny|lono|lonoh|tabi3i)\b/iu', $question)) return 'fr';
        return preg_match('/\b(?:el|fel|wel)\b/iu', $question) ? 'fr' : 'en';
    }

    /** Public FAQ routing hint. Never use this as extracted native evidence. */
    public static function routingText(string $question): string
    {
        return self::semanticQuestion($question);
    }

    /** A unit-price question is not a request for fish count or ordered quantity. */
    public static function priceQuestion(string $question): bool
    {
        $text=self::semanticQuestion($question);
        if (preg_match('/(?:كام|كم)\s+(?:حبه|حبات|سمكه|سمكات|قطعه|قطع|جرام|غرام|جم|كيلو)|(?:الكيلو|كيلو).{0,10}(?:كام|كم)\s+(?:حبه|حبات|سمكه|سمكات|قطعه|قطع|جرام|غرام|جم)/u',$text)
            && !preg_match('/(?:بكام|بكم|سعر|اسعار|تمن|ثمن)/u',$text)) return false;
        return preg_match('/(?:بكام|بكم|سعر|اسعار|تمن|ثمن)|(?:الكيلو|كيلو|الكجم|كجم|العلبه|علبه|السندوتش|سندوتش|الوجبه|وجبه)\s+(?:ب?كام|بكم)(?!\p{L})|(?<!\p{L})(?:كام|كم)\s+ال(?:كيلو|كجم|علبه|سندوتش|وجبه)(?!\p{L})/u',$text)===1;
    }

    /** Missing customer subject, not missing business knowledge. No catalog may pick it. */
    public static function priceSubjectMissing(string $question): bool
    {
        $text=self::semanticQuestion($question);
        if (!self::priceQuestion($question)
            || preg_match('/(?:توصيل|دليفري|رسوم|تكلفه|شحن|موقف|اجمالي|مجموع|الطلب|الاوردر|منيو|مينيو|كتالوج|هيتوفر|يتوفر|هينزل|امتي|امتى)/u',$text)) return false;
        return !preg_match('/(?:فسيخ|فسخ|رنجه|هيرنج|هيرينج|سردين|بطارخ|سندوتش|وجبه|سمك|تونه|ملوحه|انشوجه|سلمون|جمبري|كابوريا|استاكوزا|سبيط)/u',$text);
    }

    /** FAQ routing context only. It never rewrites a transcript or order evidence. */
    public static function priceInquiryText(string $question, array $transcript, string $turnId): string
    {
        return self::continuedPriceInquiry($question,$transcript,$turnId,0);
    }

    private static function continuedPriceInquiry(string $question,array $transcript,string $turnId,int $depth,?string $anchor=null): string
    {
        if ($depth>=3) return $question;
        $before=[]; $turn=null;
        foreach ($transcript as $row) {
            if (($row['id']??null)===$turnId) { $turn=$row; break; }
            $before[]=$row;
        }
        if (!is_array($turn)||!is_string($turn['sent_at']??null)) return $question;
        $anchor=$anchor??$turn['sent_at'];
        $recent=static function(array $row)use($anchor):bool {
            if (!is_string($row['sent_at']??null)) return false;
            try {
                $age=(new DateTimeImmutable($anchor))->getTimestamp()-(new DateTimeImmutable($row['sent_at']))->getTimestamp();
                return $age>=0&&$age<=1800;
            } catch (\Throwable $ignore) { return false; }
        };
        $previous=$before===[]?null:end($before);
        $latest=self::semanticQuestion($question);
        if (!self::priceQuestion($question)) {
            // Only an immediate answer to the reviewed FAQ question continues
            // pricing. An ordinary product/order message stays with extraction.
            if (($previous['speaker']??null)!=='business'||!is_string($previous['text']??null)||!$recent($previous)) return $question;
            $asked=self::normal($previous['text']);
            $productAnswer=preg_match('/حضرتك تقصد سعر انهي صنف/u',$asked)
                &&preg_match('/\A(?:فسيخ|رنجه|هيرنج|هيرينج|سردين|بطارخ)(?:\s+(?:نبروه|دسوق|دسوقي|بلدي|مخلي|صافي|متنضف|كامل|هيرنج|هيرينج|لو سمحت))*\s*[؟?!.]*\z/u',preg_replace('/\Aال/u','',$latest));
            $branchAnswer=preg_match('/حضرتك في انهي منطقه او فرع/u',$asked)&&self::branchAnswer($latest);
            $prepAnswer=preg_match('/حضرتك تقصد انهي تجهيز/u',$asked)
                &&preg_match('/\A(?:ال)?(?:كامل|عادي|تنظيف|متنضف|مخلي|تغليف مفرغ|نصف|نص|ربع|كومبو|كبير|وسط)(?:\s*(?:\/|و)?\s*(?:كامل|تنظيف|متنضف|مخلي|تغليف مفرغ))*\s*[؟?!.]*\z/u',self::priceMenuText($question));
            if (!$productAnswer&&!$branchAnswer&&!$prepAnswer) return $question;
            foreach (array_reverse(array_slice($before,0,-1)) as $row) {
                if (($row['speaker']??null)!=='customer') continue;
                if (!$recent($row)||!is_string($row['text']??null)||!is_string($row['id']??null)) return $question;
                // A short area answer may follow the product clarification.
                // Rebuild only that reviewed, bounded FAQ chain; each step
                // still requires its own immediate native business question.
                $prior=self::continuedPriceInquiry($row['text'],$before,$row['id'],$depth+1,$anchor);
                return self::priceQuestion($prior)?$prior.' '.$question:$question;
            }
            return $question;
        }
        if (!self::priceSubjectMissing($question)) return $question;
        $prior=[]; foreach ($before as $row) if (($row['speaker']??null)==='customer') $prior[]=$row;
        $skipped=0;
        foreach (array_reverse(array_slice($prior,-3)) as $row) {
            if (!is_string($row['text']??null)||!$recent($row)) return $question;
            $text=self::semanticQuestion($row['text']);
            if ($skipped<1&&self::branchAnswer($text)) { ++$skipped; continue; }
            if (preg_match('/(?:بلاش|الغي|الغاء|كنسل|بدل|غير|موضوع\s+تاني|مش\s+عايز|مقصدش|ماقصدش|cancel|instead)/iu',$text)) return $question;
            preg_match_all('/(?:فسيخ|فسخ|رنجه|هيرنج|هيرينج|سردين|بطارخ|تونه|ملوحه|انشوجه|سلمون|جمبري|كابوريا|استاكوزا|سبيط)/u',$text,$matches);
            $families=[];
            foreach ($matches[0] as $name) $families[preg_match('/رنجه|هيرنج|هيرينج/u',$name)?'رنجه':($name==='فسخ'?'فسيخ':$name)]=true;
            if (count($families)!==1) return $question;
            // Keep every prep/portion qualifier instead of reducing a net or
            // packaged product to an unrelated generic item price.
            return $question.' '.$row['text'];
        }
        return $question;
    }

    /** Remove product-type place words only from this read-only FAQ routing hint. */
    public static function priceRoutingText(string $question): string
    {
        return (string)preg_replace('/فسيخ\s+(?:نبروه|دسوق)(?!\p{L})/u','فسيخ',self::normal($question));
    }

    /** Display-only known area hint; ERP branch resolution remains separate. */
    public static function priceAreaNamed(string $question): bool
    {
        return preg_match('/(?:شبرا(?:\s+الخيمه)?|المنصوره|المحله(?:\s+الكبري)?|نبروه|حلوان|مدينتي|التجمع|اكتوبر|المعادي|المهندسين|العبور)/u',self::priceRoutingText($question))===1;
    }

    public static function priceBranchPrompt(string $language, bool $areaNamed): string
    {
        if ($areaNamed) return $language==='en'
            ?'The current price for that branch needs checking. The updated catalog is https://wa.me/c/201285545554'
            :($language==='fr'?'Se3r el far3 da me7tag ta2keed. El catalog el mo7addas hena https://wa.me/c/201285545554'
                :'سعر الصنف في الفرع ده محتاج تأكيد. كتالوج الأسعار المحدثة هنا: https://wa.me/c/201285545554');
        return $language==='en'?'Menu prices vary by branch. Which area or branch are you asking about?'
            :($language==='fr'?'As3ar el menu betekhtalef 7asab el far3. 7adretak fe anhy mante2a aw far3?'
                :'أسعار المينيو بتختلف حسب الفرع. حضرتك في أنهي منطقة أو فرع؟');
    }

    private static function branchAnswer(string $text): bool
    {
        return preg_match('/\A(?:(?:انا|في|من|منطقه|فرع)\s+)*(?:شبرا(?:\s+الخيمه)?|المنصوره|المحله(?:\s+الكبري)?|نبروه|حلوان|مدينتي|التجمع|اكتوبر|المعادي|المهندسين|العبور)\s*[؟?!.]*\z/u',$text)===1;
    }

    /** Live menu prices are complete option prices, never surcharges or order totals. */
    public static function liveMenuPriceFacts(array $products,string $question,string $language='ar'): array
    {
        $text=self::priceMenuText($question);
        $say=static fn(string $ar,string $en,string $fr):string=>$language==='en'?$en:($language==='fr'?$fr:$ar);
        $fact=static fn(string $subject,string $value):array=>[['subject'=>$subject,'text'=>$value]];
        $names=[]; $exact=[]; $mentions=[]; $longest=0;
        foreach ($products as $product) {
            if (!is_array($product)||!self::literal($product['name']??null,200)) continue;
            $names[]=$product['name']; $name=self::priceMenuText($product['name']);
            if (preg_match('/(?<!\p{L})(?:ال)?'.preg_quote($name,'/').'(?!\p{L})/u',$text,$hit,PREG_OFFSET_CAPTURE)) {
                $mentions[]=['product'=>$product,'start'=>$hit[0][1],'end'=>$hit[0][1]+strlen($hit[0][0])];
                $length=mb_strlen($name);
                if ($length>$longest) { $exact=[]; $longest=$length; }
                if ($length===$longest) $exact[]=$product;
            }
        }
        if (count($exact)===1) {
            $selected=null;
            foreach ($mentions as $mention) if ($mention['product']===$exact[0]) $selected=$mention;
            foreach ($mentions as $mention) if ($mention['start']<$selected['start']||$mention['end']>$selected['end']) {
                // Longest only resolves an overlapping product-name prefix. A
                // second independently named product remains a real choice.
                $exact=array_column($mentions,'product'); break;
            }
        }
        if ($exact!==[]) { $products=$exact; $names=array_column($exact,'name'); }
        if (count($products)!==1) return $fact('الصنف المقصود بالسعر',$say(
            'حضرتك تقصد سعر أنهي صنف؟ '.implode('، ',array_slice($names,0,4)),
            'Which menu product do you mean? '.implode(', ',array_slice($names,0,4)),
            'Te2sod se3r anhy senf? '.implode(', ',array_slice($names,0,4))));
        $product=$products[0]; $name=$product['name']; $native=self::priceMenuText($name);
        if (($product['available']??false)!==true) return $fact($name,$say(
            '«'.$name.'» غير متاح في الفرع حاليًا.', '«'.$name.'» is currently unavailable at this branch.',
            '«'.$name.'» mesh mota7 fel far3 delwa2ty.'));
        $unit=['weight'=>'كيلو','piece'=>'قطعة'][$product['quantity_mode']??'']??null;
        $unknown=static fn():array=>$fact($name,$say(
            'سعر التجهيز المطلوب لـ «'.$name.'» ووحدة بيعه محتاجين تأكيد من منيو الفرع يا فندم.',
            'The requested preparation price and sales unit for «'.$name.'» need confirmation from the branch menu.',
            'Se3r el tagheez el matloob le «'.$name.'» we we7det bei3o me7tageen ta2keed men menu el far3.'));
        if ($unit===null) return $unknown();
        // Filleting does not prove a net yield; a named pack/meal must exist as such.
        foreach (['صافي','علبه','عبوه','وجبه','سندوتش'] as $qualifier)
            if (strpos($text,$qualifier)!==false&&strpos($native,$qualifier)===false) return $unknown();
        if (preg_match('/([0-9٠-٩]+)\s*(?:جرام|غرام|جم)(?!\p{L})/u',$text,$weight)
            &&strpos($native,$weight[0])===false) return $unknown();
        $price=static fn($value):bool=>is_string($value)&&preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,2})?\z/D',$value)===1;
        $choices=[];
        if ($price($product['price']??null)) $choices[]=['id'=>'','label'=>'كامل','price'=>$product['price']];
        foreach ($product['preparation_options']??[] as $choice)
            if (($choice['id']??null)===''&&$price($choice['price']??null)&&self::literal($choice['label']??null,200)) {
                $choices=[['id'=>'','label'=>$choice['label'],'price'=>$choice['price']]]; break;
            }
        foreach ($product['options']??[] as $choice)
            if (is_string($choice['id']??null)&&$choice['id']!==''&&self::literal($choice['label']??null,200)&&$price($choice['price']??null))
                $choices[]=$choice;
        $wanted=[];
        foreach (['extra_clear'=>'/(?:مخلي)/u','extra_clean'=>'/(?:تنظيف|متنضف|منظف)/u',
            'extra_vacuim'=>'/(?:تغليف\s+مفرغ)/u','whole'=>'/(?<!\p{L})(?:كامل|عادي)(?!\p{L})/u'] as $key=>$pattern)
            if (preg_match($pattern,$text)) $wanted[]=$key;
        if (in_array('whole',$wanted,true)&&preg_match('/مخلي|تنظيف|متنضف|منظف|صافي/u',$native)) return $unknown();
        $features=[];
        foreach (['نصف'=>'/(?<!\p{L})(?:نصف|نص)(?!\p{L})/u','ربع'=>'/(?<!\p{L})ربع(?!\p{L})/u',
            'كومبو'=>'/(?<!\p{L})كومبو(?!\p{L})/u','كبير'=>'/(?<!\p{L})كبير(?!\p{L})/u',
            'وسط'=>'/(?<!\p{L})وسط(?!\p{L})/u'] as $key=>$pattern)
            if (preg_match($pattern,$text)&&!preg_match($pattern,$native)) $features[]=$key;
        if (count($wanted)>1||count($features)>1) $choices=[];
        elseif ($wanted!==[]||$features!==[]) {
            $desired=$wanted[0]??null; $feature=$features[0]??null;
            // A preparation already written in the exact product name is its base identity.
            $embedded=$desired==='extra_clear'&&str_contains($native,'مخلي')
                ||$desired==='extra_clean'&&preg_match('/تنظيف|متنضف|منظف/u',$native);
            $choices=array_values(array_filter($choices,static function(array $choice)use($desired,$feature,$embedded):bool {
                $id=$choice['id']; $clean=''; $featureId=0;
                if ($id!==''&&!preg_match('/\Af:([0-9]+):(base|extra_clear|extra_clean|extra_vacuim)\z/D',$id,$m)) return false;
                if ($id!=='') { $featureId=(int)$m[1]; $clean=$m[2]==='base'?'':$m[2]; }
                if ($feature===null&&$featureId!==0) return false;
                $featurePattern=$feature==='نصف'?'(?:نصف|نص)':preg_quote((string)$feature,'/');
                if ($feature!==null&&($featureId===0||!preg_match('/(?<!\p{L})'.$featurePattern.'(?!\p{L})/u',self::semanticQuestion($choice['label'])))) return false;
                return $desired===null||$clean===($embedded||$desired==='whole'?'':$desired);
            }));
        }
        if (count($choices)!==1) {
            if ($choices===[]) return $unknown();
            $labels=array_column(array_slice($choices,0,5),'label');
            return $fact($name,$say('حضرتك تقصد أنهي تجهيز لـ «'.$name.'»: '.implode('، ',$labels).'؟',
                'Which preparation of «'.$name.'» do you mean: '.implode(', ',$labels).'?',
                'Te2sod anhy tagheez le «'.$name.'»: '.implode(', ',$labels).'?'));
        }
        $choice=$choices[0]; $label=$choice['label']; $amount=$choice['price'];
        $feature=$choice['id']!==''&&preg_match('/\Af:([1-9][0-9]*):/D',$choice['id']);
        if ($feature) return $fact($name,$say(
            'اختيار «'.$label.'» لـ «'.$name.'» في منيو الفرع بـ '.$amount.' جنيه للخيار كامل، من غير التوصيل.',
            'The complete «'.$label.'» option for «'.$name.'» is '.$amount.' EGP at this branch, excluding delivery.',
            'Ekhteyar «'.$label.'» kamel le «'.$name.'» fel far3 be '.$amount.' EGP, men gher tawseel.'));
        $unitOther=$unit==='كيلو'?'kg':'piece';
        return $fact($name,$say(
            '«'.$name.'»'.($choice['id']===''?'':' اختيار «'.$label.'»').' في الفرع ده بـ '.$amount.' جنيه لل'.$unit.'. ده سعر الصنف'.($choice['id']===''?'':' شامل التجهيز ده').'، من غير التوصيل.',
            '«'.$name.'»'.($choice['id']===''?'':' with «'.$label.'»').' is '.$amount.' EGP per '.$unitOther.' at this branch, including the named preparation and excluding delivery.',
            '«'.$name.'»'.($choice['id']===''?'':' ekhteyar «'.$label.'»').' fel far3 be '.$amount.' EGP lel '.$unitOther.', shamel el tagheez el mazkoor we men gher tawseel.'));
    }

    /** Existing preparation spellings are display/routing aliases, never evidence edits. */
    private static function priceMenuText(string $text): string
    {
        return strtr(self::semanticQuestion($text),['متنظف'=>'متنضف','منضف'=>'متنضف','تنضيف'=>'تنظيف']);
    }

    /** Translate only the existing server-owned FAQ templates, never free text. */
    public static function workflowText(string $subject, string $value, string $language): ?string
    {
        if (!self::literal($subject, 240) || !self::literal($value, 500) || !in_array($language, ['ar','en','fr'], true)) return null;
        if ($language === 'ar') return self::egyptianWorkflowText($subject,$value);
        if (!preg_match('/\p{Arabic}/u', $value)) return $value;
        $fr = $language === 'fr';
        $static = [
            'لبداية الطلب بنحدد الصنف والكمية، والفرع وطريقة الاستلام، ثم بيانات التواصل. للدليفري نحتاج عنوان التوصيل. بعد اكتمال البيانات بنراجع ملخص الطلب مع العميل للموافقة قبل التنفيذ.' => [
                'To start, choose products, quantities, branch and fulfillment method, then provide contact details here. Delivery needs an address. The full summary is reviewed with you for approval before execution.',
                'Fel bedaya ne7added el asnaf wel kammeyat wel far3 w taree2et el estelam, ba3den bayanat el tawasol hena. Lel delivery ne7tag el 3enwan, wel molakhas yetrage3 ma3ak lel mowaf2a abl el tanfeez.'],
            'مواعيد عمل الفرع غير موجودة في بيانات المنيو لهذا الاستفسار. لا يمكن تأكيد وقت فتح أو إغلاق الفرع من هذه البيانات.' => [
                'The current branch menu does not provide opening or closing hours for this question. Ask the branch for its hours.',
                'Mawa3eed fat7 w 2afl el far3 mesh mawgooda fel menu lel so2al da. Es2al el far3 3an mawa3eedo.'],
            'رسوم التوصيل النهائية غير موجودة في بيانات المنيو لهذا الاستفسار، وتحتاج إلى عنوان العميل وحساب التوصيل الفعلي. لا يمكن تخمين مبلغها.' => [
                'The final delivery fee is not in this menu snapshot. It needs the delivery address and an actual fee calculation; an amount cannot be guessed.',
                'Taklefet el tawseel el neha2eya mesh fel menu da. Ne7tag el 3enwan w 7esab el tawseel el fe3ly, mesh ra2m metkhayal.'],
            'هيرنج هو الاسم الإنجليزي لسمك الرنجة. الاسم وحده لا يحدد طريقة تجهيز الصنف أو مكوناته.' => [
                'Herring is the English fish name. The name alone does not establish preparation or ingredients of a menu item.',
                'Herring howa esm el renga bel English. El esm lewa7do mesh beye7added tagheez el senf aw mokawenato.'],
            'لا يوجد فرع محدد للاستفسار الحالي، ولذلك لا تتوفر قيمة سعر مؤكدة له. يلزم معرفة الفرع أو المدينة أولاً.' => [
                'A branch has not been selected for this question, so there is no verified branch price yet. Which branch or city do you mean?',
                'Mafeesh far3 met7added lel so2al da, fa mafeesh se3r mo2akkad lel far3 lesa. Te2sod anhy far3 aw madeena?'],
            'تفاصيل الصنف وتوفره في فرع معين غير مؤكدة قبل تحديد الفرع أو المدينة.' => [
                'The item details and current stock at a particular branch need the branch or city first.',
                'Tafaseel el senf wel mota7 fe far3 mo3ayan ye7tago ta7deed el far3 aw el madeena el awel.'],
            'لا توجد بيانات مؤكدة تجيب التفصيل المحدد في السؤال الحالي. يمكن سؤال العميل عن الجزئية المقصودة لتوضيحها دون تخمين.' => [
                'The supplied information does not establish that specific detail. Which part would you like clarified?',
                'El ma3loomat el mota7a mesh bet2akked el tafseela di. Te2sod anhy goz2 3ashan nowadda7o men gher takhmeen?'],
            'التفصيلة دي محتاجة تأكيد من الفرع؛ مش هنخمن معلومة مش متأكدين منها.' => [
                'This detail needs confirmation from the branch.',
                'El tafseela de me7taga ta2keed men el far3 ya fandem.'],
            'التفصيلة دي محتاجة تأكيد من الفرع يا فندم.' => [
                'This detail needs confirmation from the branch.',
                'El tafseela de me7taga ta2keed men el far3 ya fandem.'],
            'نحتاج رقم تواصل واحد للتواصل مع حضرتك بخصوص تفاصيل الطلب والتوصيل. رقم إضافي اختياري.' => [
                'One contact phone is needed for the request and delivery details. A second number is optional.',
                'Ne7tag ra2m tawasol wa7ed le tafaseel el talab wel tawseel. Ra2m tany ekhteyary.'],
            'نحتاج تأكيد نقطة التوصيل على الخريطة لحساب المسافة ومراجعة وصول الطلب إلى العنوان المقصود. مشاركة الموقع لا تعني الموافقة على تنفيذ الطلب.' => [
                'The map location is needed to calculate distance and check the intended delivery point. Sharing a location is not approval to execute the request.',
                'Ne7tag el location 3al khareeta le 7esab el masafa w morag3et makan el tawseel. Ersal el location mesh mowaf2a 3ala tanfeez el talab.'],
            'عنوان التوصيل يحدد المكان المقصود لإيصال الطلب ويساعد على مراجعة بيانات الدليفري. كتابة العنوان وحدها لا تؤكد الطلب.' => [
                'The delivery address identifies the destination and helps review delivery details. An address alone is not order approval.',
                '3enwan el tawseel beye7added el makan w beyefeed morag3et bayanat el delivery. El 3enwan lewa7do mesh mowaf2a 3ala el talab.'],
        ];
        if (isset($static[$value])) return $static[$value][$fr ? 1 : 0];
        $match = static function (string $pattern) use ($value, $subject): ?array {
            if (!preg_match($pattern, $value, $m) || ($m['name'] ?? null) !== $subject) return null;
            return $m;
        };
        if (($m = $match('/\Aلا يوجد وصف تفصيلي مسجل لـ «(?<name>[^»]+)»\. لا يمكن استنتاج طريقة التجهيز أو المكونات أو الفرق عن صنف آخر من الاسم فقط\.\z/u')) !== null)
            return $fr ? 'Mafeesh wasf tafseely mesaggal le «'.$subject.'». El esm lewa7do mesh kefaia le ma3refet el tagheez aw el mokawenat aw el far2 3an senf tany.'
                : 'There is no detailed branch description for «'.$subject.'». Its name alone does not establish preparation, ingredients or the difference from another item.';
        if (($m = $match('/\Aاسم الصنف في منيو الفرع هو «(?<name>[^»]+)»، وحالته (?<availability>متاح|غير متاح) في هذا الفرع وقت الاستفسار\.\z/u')) !== null) {
            $available = $m['availability'] === 'متاح';
            return $fr ? 'Esm el senf fel menu howa «'.$subject.'», w howa '.($available ? 'mota7' : 'mesh mota7').' fel far3 da wa2t el estefsar.'
                : 'The branch menu item is «'.$subject.'»; it is currently '.($available ? 'available' : 'unavailable').' at this branch.';
        }
        if (($m = $match('/\Aالوصف المسجل لـ «(?<name>[^»]+)»: (?<description>.+)\z/u')) !== null)
            return $fr ? 'El wasf el asly fel menu le «'.$subject.'» howa «'.$m['description'].'».'
                : 'The original branch description for «'.$subject.'» is «'.$m['description'].'».';
        if (($m = $match('/\Aسعر «(?<name>[^»]+)» المسجل في منيو هذا الفرع هو (?<amount>[0-9]+(?:\.[0-9]{1,2})?) جنيه مصري لكل (?<unit>كيلو|قطعة)\. هذا سعر الصنف وليس إجمالي الطلب أو التوصيل\.\z/u')) !== null) {
            $unit = $m['unit'] === 'كيلو' ? 'kg' : 'piece';
            return $fr ? 'Se3r «'.$subject.'» fel far3 da '.$m['amount'].' EGP lel '.$unit.'. Da se3r el senf, mesh egmaly el talab aw el tawseel.'
                : 'The current branch menu price for «'.$subject.'» is '.$m['amount'].' EGP per '.$unit.'. This is the item price, not the full total or delivery fee.';
        }
        if (($m = $match('/\Aلا يوجد سعر مؤكد مع وحدة بيع واضحة لـ «(?<name>[^»]+)» في البيانات المتاحة؛ لا يمكن تخمينه\.\z/u')) !== null)
            return $fr ? 'Mafeesh se3r mo2akkad be we7det bei3 wad7a le «'.$subject.'» fel ma3loomat el mota7a. Mafeesh takhmeen lel se3r.'
                : 'There is no verified price with a clear sales unit for «'.$subject.'» in the supplied information; it cannot be guessed.';
        if (($m = $match('/\Aقيمة السعر المسجلة بجوار اختيار «(?<option>[^»]+)» للصنف «(?<name>[^»]+)» هي (?<amount>[0-9]+(?:\.[0-9]{1,2})?) جنيه مصري\. (?<unit>وحدة البيع غير موضحة\.|وحدة بيع الصنف (?:كيلو|قطعة)\.) البيانات لا توضح هل هذه القيمة سعر نهائي أم إضافة، ولذلك لا تمثل سعراً نهائياً مؤكداً للطلب\.\z/u')) !== null) {
            $unit = strpos($m['unit'], 'كيلو') !== false ? 'kg' : (strpos($m['unit'], 'قطعة') !== false ? 'piece' : null);
            return $fr ? 'El ra2m mesaggal gamb ekhteyar «'.$m['option'].'» le «'.$subject.'» howa '.$m['amount'].' EGP. '
                    .($unit === null ? 'We7det el bei3 mesh wad7a. ' : 'We7det bei3 el senf '.$unit.'. ')
                    .'Mesh wad7 law da se3r neha2y aw edafa, fa mesh egmaly mo2akkad lel talab.'
                : 'The amount recorded beside option «'.$m['option'].'» for «'.$subject.'» is '.$m['amount'].' EGP. '
                    .($unit === null ? 'The sales unit is unspecified. ' : 'The item sales unit is '.$unit.'. ')
                    .'The data does not establish whether this is a final price or an extra charge, so it is not a verified request total.';
        }
        if (($m = $match('/\Aوحدة بيع «(?<name>[^»]+)» المسجلة هي (?<unit>كيلو|قطعة)\.\z/u')) !== null) {
            $unit = $m['unit'] === 'كيلو' ? 'kg' : 'piece';
            return $fr ? 'We7det bei3 «'.$subject.'» el mesaggala heya '.$unit.'.'
                : 'The recorded sales unit for «'.$subject.'» is '.$unit.'.';
        }
        if (($m = $match('/\Aالاختيارات المسجلة لـ «(?<name>[^»]+)»: (?<options>.+)\. الأسماء وحدها لا توضح تفاصيل تجهيزها\.\z/u')) !== null)
            return $fr ? 'El ekhteyarat el asleya el mesaggala le «'.$subject.'» heya «'.$m['options'].'». El asamy lewa7dha mesh betwadda7 tafaseel el tagheez.'
                : 'The original listed choices for «'.$subject.'» are «'.$m['options'].'». Labels alone do not establish preparation details.';
        return null;
    }

    private static function semanticQuestion(string $question): string
    {
        $text = self::normal($question);
        // Long phrases are mapped first. The untouched native question remains
        // the workflow's transcript and the only possible order evidence.
        $aliases = [
            '/\b(?:how\s+(?:do|can)\s+i|how\s+to|ezay|ezzay)\b/iu'=>'ازاي',
            '/\b(?:home\s+(?:fish\s+)?salting|salt(?:ing)?\s+(?:fish|feseekh|fesikh)\s+(?:at\s+)?home)\b/iu'=>'تمليح فسيخ في البيت',
            '/\b(?:lono|lonoh|loon)\b/iu'=>'لون',
            '/\b(?:a5dar|akhdar|mokhdar|mokhadar)\b/iu'=>'اخضر',
            '/\b(?:tabi3i|3ady|3adi)\b/iu'=>'طبيعي',
            '/\bta3m(?:o|oh|ha)?\b/iu'=>'طعم',
            '/\b(?:ri7a|re7a|rey7a|ri7et(?:o|oh|ha)|re7et(?:o|oh|ha)|rey7et(?:o|oh|ha)|ri7t(?:o|ha)|re7t(?:o|ha))\b/iu'=>'ريحه',
            '/\b(?:gharib|ghareeb|ghariba|ghareeba)\b/iu'=>'غريب',
            '/\b(?:batny|batni)\b/iu'=>'بطني',
            '/\b(?:betewga3ny|betewga3ni|waga3any)\b/iu'=>'وجعاني',
            '/\b(?:akalt|akalna)\b/iu'=>'اكلت',
            '/\b(?:amala7|amalla7)\b/iu'=>'املح',
            '/\b(?:tamlee7)\b/iu'=>'تمليح',
            '/\b(?:beit|beet)\b/iu'=>'البيت',
            '/\b(?:feseekh|fesekh|fesikh|fessikh)\s+meal\b/iu'=>'وجبه فسيخ',
            '/\b(?:herring|renga)\s+meal\b/iu'=>'وجبه رنجه',
            '/\b(?:sardines?|sardeen)\s+meal\b/iu'=>'وجبه سردين',
            '/\b(?:net\s+fillet|makhly\s+safi)\b/iu'=>'مخلي صافي',
            '/\b(?:half\s+(?:a\s+)?kilo|nos\s+kilo|nos\s+el\s+kilo)\b/iu'=>'نصف كيلو',
            '/\b(?:quarter\s+(?:a\s+)?kilo|rob3\s+kilo)\b/iu'=>'ربع كيلو',
            '/\b(?:opening\s+hours|working\s+hours|preparation\s+hours|mawa3eed)\b/iu'=>'مواعيد',
            '/\b(?:fish\s+count|fish\s+per\s+kilo|3adad\s+el\s+samak)\b/iu'=>'حبات',
            '/\b(?:delivery\s+(?:cost|fees?)|tawseel\s+bekam)\b/iu'=>'رسوم التوصيل',
            '/\b(?:outside\s+egypt|barra\s+masr)\b/iu'=>'خارج مصر',
            '/\b(?:advance\s+(?:booking|order)|pre[- ]?orders?|7agz)\b/iu'=>'حجز',
            '/\b(?:change|modify|edit)\s+(?:my|the|an?)\s+order\b/iu'=>'تعديل الطلب',
            '/\b(?:af(?:ter)?\s+dispatch|after\s+the\s+rider\s+leaves)\b/iu'=>'بعد تحرك المندوب',
            '/\b(?:lemon[- ]wood|khashab\s+el\s+lamoon)\b/iu'=>'خشب شجر الليمون',
            '/\b(?:feseekh|fesekh|fesikh|fessikh)\b/iu'=>'فسيخ',
            '/\b(?:herring|renga)\b/iu'=>'رنجه',
            '/\b(?:sardines?|sardeen)\b/iu'=>'سردين',
            '/\b(?:besaria|basaria)\b/iu'=>'بساريا',
            '/\b(?:nabrouh|nabroh|nabro)\b/iu'=>'نبروه',
            '/\b(?:desouk|desouq|desou2|desou2y|desouky)\b/iu'=>'دسوق',
            '/\b(?:shubra|shobra)(?:\s+el\s+kheima)?\b/iu'=>'شبرا',
            '/\bmansoura\b/iu'=>'المنصوره',
            '/\b(?:el\s+)?mahalla(?:\s+el\s+kubra)?\b/iu'=>'المحله',
            '/\btanta\b/iu'=>'طنطا',
            '/\b(?:tagamoa|tagamo3|tagamo)\b/iu'=>'التجمع',
            '/\b(?:6\s+)?october\b/iu'=>'اكتوبر',
            '/\bmaadi\b/iu'=>'المعادي',
            '/\b(?:mohandessin|mohandeseen)\b/iu'=>'المهندسين',
            '/\bobour\b/iu'=>'العبور',
            '/\b(?:helwan|7elwan)\b/iu'=>'حلوان',
            '/\bmadinaty\b/iu'=>'مدينتي',
            '/\b(?:alexandria|eskendereya)\b/iu'=>'اسكندريه',
            '/\bsharkia\b/iu'=>'الشرقيه',
            '/\b(?:jordan|ordon)\b/iu'=>'الاردن',
            '/\b(?:qalyub|qalyoub|2alyoub)\b/iu'=>'قليوب',
            '/\b(?:nearest|a2rab)\b/iu'=>'اقرب',
            '/\b(?:branches|foro3)\b/iu'=>'فروع',
            '/\b(?:branch|far3)\b/iu'=>'فرع',
            '/\b(?:telephone|phone|ra2m|rakam)\b/iu'=>'تليفون',
            '/\b(?:address|3enwan)\b/iu'=>'عنوانكم',
            '/\b(?:difference|compare|comparison|far2|el\s+far2)\b/iu'=>'الفرق',
            '/\b(?:salt|salty|saltiness|mel7|melo7a)\b/iu'=>'ملح',
            '/\b(?:fillet(?:ed)?|boneless|makhly)\b/iu'=>'مخلي',
            '/\b(?:clean(?:ed|ing)?|metnaddaf|metnadef|tandeef)\b/iu'=>'متنضف',
            '/\b(?:net|safi)\b/iu'=>'صافي',
            '/\b(?:weight|weighed|wazn)\b/iu'=>'وزن',
            '/\b(?:sizes?|a7gam|7agm)\b/iu'=>'حجم',
            '/\b(?:medium|wasat)\b/iu'=>'وسط',
            '/\b(?:fish|samak|samaka)\b/iu'=>'سمك',
            '/\b(?:roe|botarekh|botarikh|batarikh)\b/iu'=>'بطارخ',
            '/\b(?:roe[- ]filled|mobattarkh|mobattarkha)\b/iu'=>'مبطرخ',
            '/\b(?:granular|kharaz)\b/iu'=>'خرز',
            '/\b(?:soft|na3ma)\b/iu'=>'ناعمه',
            '/\b(?:sandwich(?:es)?|sandawetsh)\b/iu'=>'سندوتش',
            '/\b(?:meals?|wagba|wagbat)\b/iu'=>'وجبه',
            '/\b(?:ingredients?|components?|mokawenat|ma3aha)\b/iu'=>'مكونات',
            '/\b(?:menu|menio|catalog(?:ue)?)\b/iu'=>'منيو',
            '/\b(?:app|application|ablikeshn)\b/iu'=>'تطبيق',
            '/\b(?:order|orders|atlob|atloub|otlob)\b/iu'=>'طلب',
            '/\b(?:how\s+much|prices?|cost|bekam|bkam)\b/iu'=>'بكام',
            '/\b(?:delivery|deliver(?:y|ing)?|tawseel|byewsal|heyewsal|yewsal)\b/iu'=>'توصيل',
            '/\b(?:shipping|ship|sha7n)\b/iu'=>'شحن',
            '/\b(?:governorates?|mo7afazat)\b/iu'=>'محافظات',
            '/\b(?:station|maw2af)\b/iu'=>'موقف',
            '/\b(?:now|delwa2ti|delwa2ty|7alan)\b/iu'=>'دلوقتي',
            '/\b(?:hours|opening|closing|mawa3id)\b/iu'=>'مواعيد',
            '/\b(?:seating|dine[- ]?in|sala)\b/iu'=>'صاله',
            '/\b(?:reservation|reserve|a7gez)\b/iu'=>'حجز',
            '/\b(?:tomorrow|bokra)\b/iu'=>'بكره',
            '/\b(?:wholesale|gomla)\b/iu'=>'جمله',
            '/\b(?:refund|returns?|estergag|erga3|arga3)\b/iu'=>'استرجاع',
            '/\b(?:rider|courier|mandoob)\b/iu'=>'المندوب',
            '/\b(?:offers?|promotions?|discounts?|3orood|khosomat)\b/iu'=>'عروض',
            '/\b(?:storage|store|freezer|a7faz)\b/iu'=>'فريزر',
            '/\b(?:freez(?:e|ing|en)|frozen|mogammad|mogammada)\b/iu'=>'مجمد',
            '/\b(?:thaw|defrost|afok)\b/iu'=>'يفك',
        ];
        foreach ($aliases as $pattern => $replacement) $text = preg_replace($pattern, $replacement, $text);
        return $text;
    }

    /** Reviewed translations stay indivisible source facts, with the same topic. */
    private static function translatedFact(string $topic, string $subject, string $value, string $language, array $reference): ?array
    {
        $fr = $language === 'fr';
        $branchNames = ['Nabrouh and factory','Mansoura','El Mahalla El Kubra','Shubra El Kheima'];
        $addresses = ['opposite Nabrouh gate','Abdel Salam Aref Street','El Bandar Square','El Sharee El Gedid'];
        if ($topic === 'BRANCHES') {
            foreach ($reference['branches'] ?? [] as $index => $branch) {
                if (!isset($branchNames[$index]) || !self::branchValid($branch) || $subject !== 'فرع '.$branch['name']) continue;
                $name = $branchNames[$index];
                return [$name, $fr ? 'Far3 '.$name.' fe '.$addresses[$index].'. Ra2m el far3 '.$branch['phone'].'.'
                    : $name.' branch is at '.$addresses[$index].'. The branch phone is '.$branch['phone'].'.'];
            }
            return null;
        }
        if ($topic === 'HOURS') {
            $name = null;
            foreach ($reference['branches'] ?? [] as $index => $branch)
                if (isset($branchNames[$index]) && $subject === 'مواعيد تجهيز '.$branch['name']) $name = $branchNames[$index];
            if ($name === null) $name = strpos($value, '12:00') !== false ? 'Shubra El Kheima' : 'Nabrouh, Mansoura and Mahalla';
            $noon = strpos($value, '12:00') !== false;
            return [$fr ? 'Mawa3eed tagheez '.$name : $name.' preparation hours',
                $fr ? 'Tagheez el orders fe '.$name.' men '.($noon ? '12 dohr' : '10 sob7').' le 00:30 ba3d nos el leil, betaw2eet Cairo.'
                    : 'Order preparation at '.$name.' runs from '.($noon ? '12:00 noon' : '10:00 AM').' to 00:30 after midnight, Cairo time.'];
        }
        if ($topic === 'AFTER_HOURS') {
            $closedNow = $subject === 'التجهيز خارج المواعيد';
            return [$fr ? 'Tagheez barra el mawa3eed' : 'Preparation outside opening hours',
                $closedNow
                    ? ($fr ? 'El wa2t delwa2ti barra mawa3eed tagheez el far3 el mazkoor. El tagheez yebda2 lamma el far3 yefta7; el tawseel mesh beyebda2 abl el tagheez.'
                        : 'The selected branch is outside its preparation hours now. Preparation starts when it opens; the delivery estimate does not start before preparation.')
                    : ($fr ? 'Te2dar teb3at el talab hena barra el mawa3eed, wel tagheez yebda2 lamma el far3 yefta7.'
                        : 'You can send your request here outside opening hours; preparation starts when the branch opens.')];
        }
        if ($topic === 'STORAGE') {
            if ($subject === 'حفظ الرنجة') return [$fr ? '7efz el renga' : 'Herring storage',
                $fr ? 'Es2al el far3 3an sabab tagmeed el 3elba w ta3leemat 7efz w fak tagmeed el renga el mota7a, wetbe3 ta3leemat el 3abwa.'
                    : 'Ask the branch why the current herring pack is frozen and for its storage and thawing instructions. Follow the instructions on the pack.'];
            return [$fr ? '7efz el feseekh' : 'Feseekh storage',
                $fr ? 'El feseekh yet7efez fel freezer. Es2al el far3 3an ta3leemat fak el tagmeed lel 3abwa, wetbe3 ta3leemat el 7efz. Matseebsh el samak yefok fe 7araret el oda.'
                    : 'Keep feseekh in the freezer. Ask the branch for the pack-specific thawing instructions and follow the storage instructions. Do not thaw fish at room temperature.'];
        }
        if ($topic === 'ROE') {
            if ($subject === 'موسم الفسيخ المبطرخ') {
                $after = strpos($value, 'كان') !== false;
                return [$fr ? 'Mawsem el feseekh el mobattarkh' : 'Roe feseekh season',
                    $after ? ($fr ? 'Bedayet el mawsem el motaweqqa3a kanet 15 November 2026. El mota7 delwa2ti 7asab makhzoon kol far3; eb3at esm el far3 hena lel morag3a.'
                        : 'The expected season start was 15 November 2026. Current roe feseekh availability depends on each branch stock; tell us the branch here so availability can be checked.')
                    : ($fr ? 'El tawaffor el mawsimy el kamel motaweqqa3 men 15 November 2026. Abl keda, el videos bashayer 7awaly 5%, mesh daman en el samaka tewsal mobattarkha. Es2al el far3 3an el mota7 delwa2ti.'
                        : 'The expected full roe feseekh season starts from 15 November 2026. Earlier videos show about 5% early roe and do not guarantee roe in a fish. Check current branch availability.')];
            }
            if ($subject === 'الرنجة الخرز والناعمة') return [$fr ? 'Renga kharaz w na3ma' : 'Granular and soft roe herring',
                $fr ? 'El renga el mobattarkha kharaz heya 100% kharaz; el na3ma sameena bas mesh 100% kharaz. El mota7 7asab menu el far3.'
                    : 'Granular roe herring is the 100% granular roe type; the soft type is rich but not 100% granular roe. Current availability depends on the branch menu.'];
            if ($subject === 'عبوة البطارخ') return [$fr ? '3elbet el botarekh' : 'Roe pack',
                $fr ? 'El botarekh 3elba 400 gm bas, mesh bel wazn wala 3elba asghar. El mota7 7asab el mawsem w makhzoon el far3. El tareekh 2orayeb, mesh shart elnaharda; es2al el far3 3an el 3elba el mota7a.'
                    : 'Roe comes in a 400 g can only, not loose or in smaller packs. Availability depends on the season and branch stock. The pack date is recent, not necessarily today; check the current pack with the branch.'];
            return null;
        }
        $texts = [
            'HEALTH_ESCALATION' => ['Symptoms after eating',
                'For symptoms or illness after eating, seek urgent medical care and do not wait for the branch reply. Share the incident details and order number here and contact the branch about the product.',
                'Law fe a3rad aw ta3ab ba3d el akl, etlob mosa3da tebbeya 3agla w matestannash rad el far3. Eb3at tafaseel el 7ala w ra2m el order hena, wetwasal ma3 el far3.'],
            'PRODUCT_REVIEW' => ['Product concern',
                'We cannot confirm product safety from a description or photo. Do not taste it to test it. Send a photo and the problem details here, or contact the branch for product review.',
                'Man2darsh ne2akked salamet el montag men wasf aw sora. Matdo2oosh 3ashan tegarab. Eb3at sora w tafaseel el moshkela hena, aw etwasal ma3 el far3 lel morag3a.'],
            'SAFETY_SCOPE' => ['Home fish salting',
                'Customer service does not provide home feseekh salting instructions or confirm its safety.',
                'Khedmet el 3omala mabt2addemsh ta3leemat tamlee7 feseekh fel beit aw ta2keed salamto.'],
            'ORDER' => ['How to order',
                'You can start through the catalog, app, Messenger or WhatsApp. Choose products and quantities, then send your name, detailed address, location and one main phone number here; a second number is optional. The details are reviewed with you before execution.',
                'Te2dar tetlob men el catalog aw el app aw Messenger aw WhatsApp. Ekhtar el asnaf wel kammeyat, web3at esmak wel 3enwan bel tafseel wel location w ra2m mobile asasy hena; ra2m tany law mota7. El tafaseel tetrage3 ma3ak abl el tanfeez.'],
            'ORDER_ROUTING' => ['Order channel and branch',
                'The app routes to the nearest branch according to the app, and each branch has its own prices. Chat and WhatsApp requests usually come from Shubra El Kheima via inDrive; delivery fees depend on the area.',
                'El app beyetlob men a2rab far3 7asab el app, we kol far3 leh as3aro. El chat w WhatsApp ghaleban men Shubra El Kheima 3an taree2 inDrive, wel tawseel 7asab el mante2a.'],
            'MENU' => ['Official WhatsApp catalog',
                'The official WhatsApp catalog has photos and prices at https://wa.me/c/201285545554 . Add items you like to the cart and view their details there; prices may vary by branch and app.',
                'El menu bel sowar wel as3ar 3ala catalog WhatsApp el rasmi hena https://wa.me/c/201285545554 . Deef elly ye3gebak lel salla w shoof tafaseelo henak; el as3ar momken tekhtalef 7asab el far3 wel app.'],
            'PRICE_SUBJECT' => ['Product for the price question',
                'Which product would you like the price for?',
                'Te2sod se3r anhy senf ya fandem?'],
            'MENU_DELIVERY' => ['Delivery and shipping with the menu',
                'Delivery within Greater Cairo, Mansoura, El Mahalla and Nabrouh takes an estimated 60 to 90 minutes. Governorate shipping to the public station can be same-day, subject to preparation and shipping hours.',
                'El delivery gowwa Greater Cairo we Mansoura we Mahalla we Nabrouh ta2reeban 60 le90 de2ee2a. Fe sha7n lel mo7afazat 3ala el maw2af fe nafs el yom 7asab mawa3eed el tagheez wel sha7n.'],
            'MENU_FEE' => ['Delivery fee estimate',
                'The courier sets the delivery fee using your address and location. You can share your area, detailed address and location here so the fee can be checked.',
                'El mandoob beye7added taklefet el tawseel 7asab el 3enwan wel location. Te2dar teb3at mante2tak wel 3enwan bel tafseel wel location hena 3ashan el taklefa tetrage3.'],
            'APP' => ['Official Fasakhansta app',
                'The official Fasakhansta app link is https://fasakhaninja.com/downloadApp',
                'Link app Fasakhansta el rasmi howa https://fasakhaninja.com/downloadApp'],
            'NEAREST_BRANCH' => ['Nearest branch to Tanta',
                'The nearest branch to Tanta is El Mahalla El Kubra at El Bandar Square.',
                'A2rab far3 le Tanta howa El Mahalla El Kubra fe El Bandar Square.'],
            'ONLINE' => ['Online stores and physical branches',
                'Helwan, Madinaty, Tagamoa, 6 October, Maadi, Mohandessin and Obour are online stores with no physical branches. Use the app, or check delivery from Shubra El Kheima via inDrive.',
                'Helwan we Madinaty we Tagamo3 we 6 October we Maadi we Mohandessin we Obour stores online, mesh foro3 fe3liya. Etlob men el app, aw tawseel men Shubra El Kheima 3an taree2 inDrive.'],
            'DINE_IN' => ['Seating',
                'Physical branches have seating and do not require a reservation for a visit during operating hours. Online stores have no visitor seating.',
                'El foro3 el fe3liya feha sala w mesh me7taga 7agz lel zeyara fe mawa3eed el 3amal. El stores online mafehash sala lel zeyara.'],
            'INTERNATIONAL' => ['Shipping outside Egypt',
                'Shipping outside Egypt is not available.',
                'Mafeesh sha7n barra Masr.'],
            'ETA' => ['Local delivery estimate',
                'Local delivery in Greater Cairo including Qalyub, Mansoura, Mahalla, Nabrouh and nearby cities usually takes about 60 to 90 minutes, depending on delivery conditions.',
                'El delivery fel Cairo el kobra menha Qalyub, we Mansoura we Mahalla we Nabrouh wel modon el 2orayeba, ta2reeban 60 le 90 de2ee2a 7asab zoroof el tawseel.'],
            'AREAS' => ['Shipping to other governorates',
                'Shipping to other governorates such as Sharkia, Tanta and Alexandria is to the public transport station, estimated at 2 to 3 hours. The driver provides a phone number, vehicle number and collection point.',
                'El sha7n le ba2y el mo7afazat zay Sharkia we Tanta we Alexandria 3al maw2af el 3omoomi, ta2reeban 2 le 3 sa3at. El sawa2 bey2ool ra2m telephone w ra2m el 3arabeya w makan el estelam.'],
            'DELIVERY_FEE' => ['Delivery fee',
                'Delivery fees depend on the area. Send the address here so the actual fee can be checked.',
                'El tawseel 7asab el mante2a. Eb3at el 3enwan hena 3ashan el taklefa tet7aseb.'],
            'METHODS' => ['Available methods',
                'Available options are cash on delivery, Vodafone Cash or InstaPay before the rider leaves. Ask the branch for the approved details.',
                'El wasa2el el mota7a cash 3and el estelam, aw Vodafone Cash aw InstaPay abl khorog el mandoob. Es2al el far3 3an el bayanat el mo3tamada.'],
            'PREORDER' => ['Advance booking',
                'Advance booking for a specific day or time is not available; requests are for the same day. Visiting a branch does not require a reservation.',
                'Mafeesh 7agz talab le yom aw wa2t mo3ayan; el talab fe nafs el yom. Zeyaret el far3 mesh me7taga 7agz.'],
            'WHOLESALE' => ['Wholesale',
                'Wholesale has a 50 kg minimum and is arranged directly with the branch. Wholesale is not ordered online.',
                'El gomla men 50 kilo, wel tansee2 ma3 el far3 mobashara. El gomla mesh online.'],
            'EXISTING_ORDER' => ['Changes to an existing order',
                'Changes are not available after the rider leaves. For an existing order, send the order number here for branch review.',
                'El talab mayet3adelsh ba3d ma yet7arrak ma3 el mandoob. Law fe estefsar 3an talab mawgood, eb3at ra2m el talab hena lel far3 yerage3o.'],
            'RETURNS' => ['Returns policy',
                'Returns are reviewed at the branch within 12 hours, with the product kept in the freezer until return. Returns are not collected by delivery; share the case details with the branch for review.',
                'El estergag fel far3 khelal 12 sa3a, be shart 7efz el montag fel freezer le7ad el rogoo3. El estergag mesh bel delivery; eb3at el tafaseel lel far3 lel morag3a.'],
            'PROMOTIONS' => ['Offers',
                'Follow the page for offers as they are announced 😉',
                'Tab3o el page, el 3orood betenzal awel be awel 😉'],
            'SALT' => ['Nabrouh and Desouk feseekh',
                'Nabrouh feseekh is lightly salted and rich; Desouk feseekh has medium salt and is not strongly salty. Both come from the same factory and have the same quality; the difference is salt level.',
                'Feseekh Nabrouh mel7o khafeef we sameen, we Desouk mel7o mazboot mesh 7ade2. El etneen men nafs el masna3 we nafs el gawda, wel far2 fel mel7.'],
            'PREPARATION' => ['Feseekh preparation',
                'For feseekh, whole means the whole fish. Cleaned means opened, with the belly cleaned, head removed and skin on. Fillet is flesh with the special oil. Actual choices depend on the branch menu.',
                'Fe tagheez el feseekh, kamel ya3ni samaka kamla. Metnaddaf ya3ni maftoo7 sangary, el batn nadeefa wel ras ma2too3a wel geld mawgood. Makhly la7m bas bel zeit el makhsoos. El ekhteyarat 7asab menu el far3.'],
            'WEIGHT' => ['Whole weight and net fillet',
                'A kilo sold by fish count and then filleted is weighed whole before cleaning and may yield roughly half as flesh. Net fillet means flesh weight after cleaning. This applies to feseekh, herring and sardines.',
                'Kilo bel 3adad makhly beyetzan kamel abl el tandeef, we momken yedee ta2reeban nos el kammiya la7m. Makhly safi ya3ni wazn el la7m ba3d el tandeef. Nafs el far2 lel feseekh wel renga wel sardeen.'],
            'FISH_SIZE' => ['Feseekh sizes',
                'Subject to availability, sizes are 4 fish per kilo, 3 fish per kilo for medium, or 2 fish per kilo. Ask the branch about one fish at the desired weight. Half a kilo is not a fixed size; fish per kilo describes fish size.',
                'El a7gam 7asab el mota7, kilo 4 7abbat aw 3 7abbat wasat aw 7abeten. Momken tes2al el far3 3an samaka wa7da bel wazn el matloob. Mafeesh nos kilo ka 7agm sabet; 3adad el samak fel kilo beye7added 7agm el samaka.'],
            'HERRING' => ['Herring types',
                'Types include herring, roe herring and lemon-wood-smoked herring. Whole, cleaned or filleted choices depend on the branch menu. Fillet cans are 200 and 400 g.',
                'El anwa3 renga herring, renga mobattarkha, we renga modakhana 3ala khashab shagar el lamoon. Kamel aw metnaddaf aw makhly 7asab menu el far3; 3elab el makhly 200 w 400 gm.'],
            'SARDINE' => ['Sardines',
                'Sardines are local; besaria is not available. A quarter kilo is about 3 to 4 fish. Whole, cleaned and filleted choices and 200 or 400 g salted cans depend on current branch availability.',
                'El sardeen balady wel besaria mesh mota7a. Rob3 kilo 7awaly 3 le 4 7abbat. Kamel aw metnaddaf aw makhly, we 3elab momalla7a 200 w 400 gm, 7asab el mota7 fel far3.'],
            'SANDWICH' => ['Desouk feseekh sandwich',
                'The Desouk feseekh sandwich is 180 g in baladi or white bread with an extra empty loaf. The combo includes a sandwich, chips and Pepsi. Choices and stock depend on the branch menu.',
                'Sandwich el feseekh el Desou2y 180 gm be 3eish balady aw abyad we regeef zeyada fady. El combo sandwich we chips we Pepsi; el ekhteyarat wel mota7 7asab menu el far3.'],
            'MEAL' => ['Meals and extras',
                'Meals have rice, salad, tahini and bread; the feseekh meal contains a quarter kilo of feseekh. Extra bread and lemon can be requested, subject to the actual branch menu choices.',
                'El wagbat ma3aha rozz we salata we ta7eena we 3eish, we wagbet el feseekh feha rob3 kilo feseekh. Momken tetlob 3eish we lamoon zeyada 7asab ekhteyarat menu el far3.'],
            'PRICE_POLICY' => ['Price reference',
                'Indicative prices may differ by branch and app. Current prices come from the catalog or branch menu; an item price is not the full total including delivery.',
                'El as3ar estershadiya w momken tekhtalef 7asab el far3 wel app. El mo7addas fel catalog aw menu el far3; se3r el senf mesh egmaly el talab wel tawseel.'],
        ];
        if (!isset($texts[$topic])) return null;
        return [$texts[$topic][0], $texts[$topic][$fr ? 2 : 1]];
    }

    private static function hoursFact(array $branch, callable $add): void
    {
        if (!self::branchValid($branch) || !preg_match('/\A(?:10:00|12:00)\z/', $branch['preparation_opens'] ?? '')
            || ($branch['preparation_closes'] ?? null) !== '00:30') return;
        $add('HOURS', 'مواعيد تجهيز '.$branch['name'], 'تجهيز طلبات '.$branch['name'].' من '.$branch['preparation_opens']
            .' إلى 00:30 بعد منتصف الليل بتوقيت القاهرة. هذه مواعيد تجهيز الطلبات.');
    }

    private static function preparingOpen(array $branch, DateTimeImmutable $clock): bool
    {
        $opening = $branch['preparation_opens'] ?? null;
        if (!in_array($opening, ['10:00','12:00'], true) || ($branch['preparation_closes'] ?? null) !== '00:30') return false;
        $minute = ((int) $clock->format('H')) * 60 + (int) $clock->format('i');
        return $minute < 30 || $minute >= ($opening === '10:00' ? 600 : 720);
    }

    private static function selectedBranches(array $branches, string $text): array
    {
        $selected = [];
        foreach ($branches as $branch) {
            if (!self::branchValid($branch)) continue;
            $name = self::normal($branch['name']);
            foreach (['نبروه', 'المنصوره', 'المحله', 'شبرا'] as $token) {
                if (strpos($name, $token) !== false && strpos($text, $token) !== false) { $selected[] = $branch; break; }
                if ($token === 'المحله' && strpos($name, $token) !== false && strpos($text, 'طنطا') !== false
                    && preg_match('/(?:اقرب|فرع|فروع)/u', $text)) { $selected[] = $branch; break; }
            }
        }
        return $selected;
    }

    private static function branchValid($branch): bool
    {
        return is_array($branch) && self::literal($branch['name'] ?? null, 80)
            && self::literal($branch['address'] ?? null, 160)
            && is_string($branch['phone'] ?? null) && preg_match('/\A0[0-9]{9,10}\z/', $branch['phone']);
    }

    private static function indicativePrices(array $reference, string $text, callable $add): void
    {
        // Match the complete requested subject after a small spelling-only
        // normalization. Extra quantities, preparations, delivery fees and
        // competing variants remain unmatched; no arithmetic or price inference.
        $needle = self::priceSubject($text);
        $aliases = [
            'كيلو فسيخ، 2 أو 3 أو 4 حبات' => ['فسيخ', 'كيلو فسيخ'],
            'سندوتش فسيخ دسوقي' => ['سندوتش فسيخ'],
            'سندوتشان فسيخ دسوقي' => ['سندوتشان فسيخ'],
            'كيلو سردين كامل' => ['سردين كامل'],
            'كيلو سردين متنضف' => ['سردين متنضف'],
            'كيلو سردين مخلي' => ['سردين مخلي'],
            'علبة بطارخ 400 جم' => ['بطارخ', 'علبه بطارخ'],
        ];
        $displayLabels=[
            'كيلو فسيخ، 2 أو 3 أو 4 حبات'=>'كيلو الفسيخ (2 أو 3 أو 4 حبات)',
            'سندوتشان فسيخ دسوقي'=>'سندوتشين فسيخ دسوقي',
        ];
        foreach ($reference['indicative_prices_egp'] ?? [] as $entry) {
            if (!is_array($entry) || !self::literal($entry['product'] ?? null, 160)) continue;
            $product = self::priceSubject($entry['product']);
            if ($product === '') continue;
            if (isset($entry['price']) && is_int($entry['price']) && $entry['price'] >= 0 && $entry['price'] < 1000000) {
                if ($needle !== $product && !in_array($needle, $aliases[$entry['product']] ?? [], true)) continue;
                $display=$displayLabels[$entry['product']]??$entry['product'];
                $add('REFERENCE_PRICE', $entry['product'], $display.' بـ '.$entry['price']
                    .' جنيه (استرشادي)، وممكن يختلف حسب الفرع والتطبيق. المحدّث هنا: https://wa.me/c/201285545554');
                return;
            } elseif (is_array($entry['variants'] ?? null)) {
                foreach ($entry['variants'] as $label => $price) {
                    if (!self::literal($label, 30) || !is_int($price) || $price < 0 || $price >= 1000000) continue;
                    if ($needle !== $product.' '.self::priceSubject($label) && !($label === 'عادي' && $needle === $product)) continue;
                    $display=['وجبة فسيخ'=>'وجبة الفسيخ','وجبة سردين'=>'وجبة السردين','وجبة رنجة'=>'وجبة الرنجة'][$entry['product']]??$entry['product'];
                    $display.=$label==='عادي'?' العادية':' '.$label;
                    $add('REFERENCE_PRICE', $entry['product'].' '.$label, $display.' بـ '.$price
                        .' جنيه (استرشادي)، وممكن يختلف حسب الفرع والتطبيق. المحدّث هنا: https://wa.me/c/201285545554');
                    return;
                }
            }
        }
    }

    /** Translate only exact trusted server templates, preserving quoted labels. */
    private static function egyptianWorkflowText(string $subject,string $value): string
    {
        $static=[
            'لا يوجد فرع محدد للاستفسار الحالي، ولذلك لا تتوفر قيمة سعر مؤكدة له. يلزم معرفة الفرع أو المدينة أولاً.'
                =>'السعر الحالي ممكن يختلف من فرع للتاني. قولنا أنهي فرع أو مدينة علشان نعرف السعر المناسب ليك؟',
            'تفاصيل الصنف وتوفره في فرع معين غير مؤكدة قبل تحديد الفرع أو المدينة.'
                =>'اختيارات الأصناف وتوفرها بتختلف حسب الفرع. قولنا أنهي فرع أو مدينة؟',
            'لا توجد بيانات مؤكدة تجيب التفصيل المحدد في السؤال الحالي. يمكن سؤال العميل عن الجزئية المقصودة لتوضيحها دون تخمين.'
                =>'قولنا تحب تعرف إيه عن الصنف، ونوضحلك اللي نقدر نأكده من الفرع.',
            'التفصيلة دي محتاجة تأكيد من الفرع؛ مش هنخمن معلومة مش متأكدين منها.'
                =>'التفصيلة دي محتاجة تأكيد من الفرع يا فندم.',
            'رسوم التوصيل النهائية غير موجودة في بيانات المنيو لهذا الاستفسار، وتحتاج إلى عنوان العميل وحساب التوصيل الفعلي. لا يمكن تخمين مبلغها.'
                =>'التوصيل حسب المنطقة؛ ابعت عنوان التوصيل علشان تتحسب تكلفته.',
        ];
        if (isset($static[$value])) return $static[$value];
        $patterns = [
            '/\Aاسم الصنف في منيو الفرع هو «(?<name>[^»]+)»، وحالته (?<available>متاح|غير متاح) في هذا الفرع وقت الاستفسار\.\z/u',
            '/\Aالوصف المسجل لـ «(?<name>[^»]+)»: (?<description>.+)\z/u',
            '/\Aسعر «(?<name>[^»]+)» المسجل في منيو هذا الفرع هو (?<amount>[0-9]+(?:\.[0-9]{1,2})?) جنيه مصري لكل (?<unit>كيلو|قطعة)\. هذا سعر الصنف وليس إجمالي الطلب أو التوصيل\.\z/u',
            '/\Aلا يوجد سعر مؤكد مع وحدة بيع واضحة لـ «(?<name>[^»]+)» في البيانات المتاحة؛ لا يمكن تخمينه\.\z/u',
            '/\Aوحدة بيع «(?<name>[^»]+)» المسجلة هي (?<unit>كيلو|قطعة)\.\z/u',
            '/\Aالاختيارات المسجلة لـ «(?<name>[^»]+)»: (?<options>.+)\. الأسماء وحدها لا توضح تفاصيل تجهيزها\.\z/u',
            '/\Aلا يوجد وصف تفصيلي مسجل لـ «(?<name>[^»]+)»\. لا يمكن استنتاج طريقة التجهيز أو المكونات أو الفرق عن صنف آخر من الاسم فقط\.\z/u',
            '/\Aقيمة السعر المسجلة بجوار اختيار «(?<option>[^»]+)» للصنف «(?<name>[^»]+)» هي (?<amount>[0-9]+(?:\.[0-9]{1,2})?) جنيه مصري\. (?<unit>وحدة البيع غير موضحة\.|وحدة بيع الصنف (?:كيلو|قطعة)\.) البيانات لا توضح هل هذه القيمة سعر نهائي أم إضافة، ولذلك لا تمثل سعراً نهائياً مؤكداً للطلب\.\z/u',
        ];
        foreach ($patterns as $index=>$pattern) {
            if (!preg_match($pattern,$value,$m) || $m['name']!==$subject) continue;
            if ($index===0) return '«'.$subject.'» '.$m['available'].' في الفرع ده دلوقتي.';
            if ($index===1) return 'تفاصيل «'.$subject.'» من منيو الفرع: '.$m['description'];
            if ($index===2) return '«'.$subject.'» في الفرع ده بـ '.$m['amount'].' جنيه لل'.$m['unit'].'. ده سعر الصنف لوحده، من غير التوصيل.';
            if ($index===3) return 'سعر «'.$subject.'» ووحدة بيعه لسه محتاجين تأكيد من الفرع؛ مش هنخمن السعر.';
            if ($index===4) return '«'.$subject.'» بيتباع بال'.$m['unit'].'.';
            if ($index===5) return 'اختيارات «'.$subject.'»: '.$m['options'].'. لو محتاج تعرف تفاصيل اختيار، قولنا اسمه.';
            if ($index===6) return 'تفاصيل تجهيز «'.$subject.'» ومكوناته لسه محتاجة تأكيد من الفرع.';
            if ($index===7) return 'مبلغ «'.$m['option'].'» مع «'.$subject.'» هو '.$m['amount'].' جنيه. '.$m['unit']
                .' لسه محتاج تأكيد من الفرع إذا كان سعر الصنف ولا إضافة؛ مش إجمالي الطلب أو التوصيل.';
        }
        return $value;
    }

    /** Only query filler and spelling aliases; never turn amounts into prices. */
    private static function priceSubject(string $text): string
    {
        $text = strtr(self::normal($text), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $text = preg_replace('/(?<=[0-9])(?=\p{L})|(?<=\p{L})(?=[0-9])/u', ' ', $text);
        $text = preg_replace('/[؟?!،,؛;:.()«»]/u', ' ', $text);
        $text = preg_replace('/(?<!\p{L})ال(?=\p{L})/u', '', $text);
        $text = preg_replace('/(?<!\p{L})(?:كيلوجرام|كيلوغرام|كجم)(?!\p{L})/u', 'كيلو', $text);
        $text = preg_replace('/(?<!\p{L})(?:جرام|غرام)(?!\p{L})/u', 'جم', $text);
        $text = preg_replace('/(?<!\p{L})(?:متنظف|منظف|منضف)(?!\p{L})/u', 'متنضف', $text);
        $text = preg_replace('/(?<!\p{L})مخليه(?!\p{L})/u', 'مخلي', $text);
        $text = preg_replace('/(?<!\p{L})(?:سندوتشين|سندويتشين|ساندوتشين|ساندويتشين)(?!\p{L})/u', 'سندوتشان', $text);
        $text = preg_replace('/(?<!\p{L})(?:سندويتش|ساندوتش|ساندويتش)(?!\p{L})/u', 'سندوتش', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])(?:بكام|بكم|كام|سعر|اسعار|تمن|ثمن|هو|هي|ايه|عندكم|عندك|دلوقتي|نهارده|ممكن|اعرف|عايز|عاوز|لو|سمحت|سمحتم|من|فضلك|فضلكم|يا|باشا|جميل)(?![\p{L}\p{N}])/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function normal(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), ['أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ٱ'=>'ا', 'ى'=>'ي', 'ة'=>'ه']);
        $text = preg_replace('/[\x{0640}\x{0610}-\x{061a}\x{064b}-\x{065f}\x{0670}\x{06d6}-\x{06ed}\p{Cf}]/u', '', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function literal($text, int $limit): bool
    {
        return is_string($text) && trim($text) !== '' && preg_match('//u', $text)
            && preg_match_all('/./us', $text) <= $limit && !preg_match('/[\x00-\x1f\x7f<>\p{Cf}]/u', $text);
    }
}
