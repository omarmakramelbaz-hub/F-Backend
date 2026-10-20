<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;

/** Owner-only, versioned factual text. No credentials or customer records are read. */
final class FasakhanstaAiKnowledge
{
    public const REFERENCE = 'fasakhansta-owner-20261020';
    private array $reference = [];
    private string $instructions = '';
    private ?string $messageInstructions = null;
    private DateTimeImmutable $now;

    public function __construct(?array $reference = null, ?string $commentsInstructions = null, ?DateTimeImmutable $now = null, ?string $messageInstructions = null)
    {
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->setTimezone(new DateTimeZone('Africa/Cairo'));
        try {
            if ($reference === null) {
                if (!function_exists('storage_path')) return;
                $directory = storage_path('app/private/fasakhansta-ai-training');
                $json = $this->privateFile($directory, 'knowledge.json', 131072);
                $commentsInstructions = $this->privateFile($directory, 'instructions_comments.txt', 32768);
                $reference = json_decode($json, true, 24, JSON_THROW_ON_ERROR);
                try { $messageInstructions = $this->privateFile($directory, 'instructions_messages.txt', 32768); }
                catch (\Throwable $ignore) { $messageInstructions = null; }
            }
            if (!$this->validReference($reference) || !is_string($commentsInstructions)
                || trim($commentsInstructions) === '' || strlen($commentsInstructions) > 32768
                || !preg_match('//u', $commentsInstructions)) return;
            $this->reference = $reference;
            $this->instructions = trim($commentsInstructions);
            if (is_string($messageInstructions) && trim($messageInstructions) !== ''
                && strlen($messageInstructions) <= 32768 && preg_match('//u', $messageInstructions))
                $this->messageInstructions = trim($messageInstructions);
        } catch (\Throwable $ignore) { /* Missing or invalid training is never silently activated. */ }
    }

    public function available(): bool { return $this->reference !== [] && $this->instructions !== ''; }
    public function data(): array { return $this->reference; }
    public function commentsInstructions(): string { return $this->instructions; }
    public function instructions(string $channel): ?string
    {
        if (!$this->available()) return null;
        return $channel === 'comments' ? $this->instructions : ($channel === 'messages' ? $this->messageInstructions : null);
    }

    public static function detectLanguage(string $text): string
    {
        if (preg_match('/\p{Arabic}/u', $text)) return 'ar';
        if (!preg_match('/[a-z]/iu', $text)) return 'ar';
        return preg_match('/\b(?:fein|fen|ezay|3ay[ez]|3aiz|3nd|enta|ento|el|ya|kbeer|bekam|bkam|menio|law|msh|mesh|3ala|7elw|yalla)\b|[2378][a-z]/iu', $text) ? 'fr' : 'en';
    }

    public function productNames(): array
    {
        if (!$this->available()) return [];
        return ['فسيخ', 'فسيخ نبروه', 'فسيخ دسوق', 'رنجة', 'رنجة هيرنج', 'رنجة هيرنج مبطرخة',
            'رنجة مدخنة على خشب شجر الليمون', 'سردين بلدي', 'بطارخ', 'سندوتش فسيخ دسوقي',
            'وجبة فسيخ', 'وجبة رنجة', 'وجبة سردين'];
    }

    /** A bounded matching-language selection, not a dump of the whole reference. */
    public function publicFacts(string $comment, ?string $language = null): array
    {
        $language = $this->language($language ?? self::detectLanguage($comment));
        $bank = $this->bank($language);
        $normal = self::normalize($comment);
        if ($bank === []) return [];
        $matched = [];
        foreach ($bank as $id => $fact) {
            if ($fact['pattern'] !== '' && preg_match($fact['pattern'], $normal)) {
                $matched[$id] = $fact;
            }
        }
        foreach (self::seriousFactIds($comment) as $id) $matched[$id] = $bank[$id];
        // Serious cases cannot select sales jokes, indicative prices or delivery estimates.
        if (isset($matched['SYMPTOMS']) || isset($matched['PRODUCT_CONCERN']) || isset($matched['COMPLAINT']) || isset($matched['HOME_SALTING'])) {
            $selected = [];
            foreach (['SYMPTOMS','PRODUCT_CONCERN','COMPLAINT','HOME_SALTING'] as $id)
                if (isset($matched[$id])) $selected[$id] = $bank[$id]['text'];
            $selected['MISSING'] = $bank['MISSING']['text'];
            return $selected;
        }
        // Keep exact relevant price subjects before broad product descriptions.
        $selected = [];
        foreach ($matched as $id => $fact) if (strncmp($id, 'PRICE_', 6) === 0 && $id !== 'PRICE_NOTE') $selected[$id] = $fact['text'];
        foreach (['CATALOG', 'APP', 'MISSING'] as $id) $selected[$id] = $bank[$id]['text'];
        foreach ($matched as $id => $fact) if (!isset($selected[$id])) $selected[$id] = $fact['text'];
        return array_slice($selected, 0, 12, true);
    }

    /** Needed for rechecking previously rendered receipts; no model input is trusted. */
    public function allPublicFacts(?string $language = null): array
    {
        if ($language !== null) return array_map(static fn ($fact) => $fact['text'], $this->bank($this->language($language)));
        $all = [];
        foreach (['ar', 'en', 'fr'] as $lang)
            foreach ($this->bank($lang) as $id => $fact) $all[$lang.':'.$id] = $fact['text'];
        return $all;
    }

    private function bank(string $language): array
    {
        if (!$this->available()) return [];
        $r = $this->reference;
        $facts = [];
        $add = static function (string $id, string $topic, string $pattern, string $ar, string $en, string $fr) use (&$facts, $language): void {
            $facts[$id] = ['topic' => $topic, 'pattern' => $pattern, 'text' => ['ar' => $ar, 'en' => $en, 'fr' => $fr][$language]];
        };
        $catalog = $r['official_links']['catalog']; $app = $r['official_links']['application'];
        $add('CATALOG','MENU','/(?:منيو|مينيو|كتالوج|menu|catalog|menio)/iu',
            'المنيو بالصور والأسعار المحدثة في كتالوج واتساب الرسمي: '.$catalog,
            'The official WhatsApp catalog has the menu, photos and current prices: '.$catalog,
            'El menu bel sowar wel as3ar el mo7addasa fel catalog el rasmi: '.$catalog);
        $add('APP','APP','/(?:تطبيق|ابليكيشن|ابلكيشن|تحميل|app|application|download)/iu',
            'رابط تطبيق فسخانستا الرسمي: '.$app,
            'The official Fasakhansta app link is: '.$app,
            'Link app Fasakhansta el rasmi: '.$app);
        $add('MISSING','MISSING','',
            'ابعت لنا على الخاص علشان الفرع يراجع التفاصيل المطلوبة.',
            'Please message us privately so the branch can check the requested details.',
            'Eb3at lena private 3ashan el far3 yerage3 el tafaseel.');
        $add('EMAIL','CONTACT','/(?:ايميل|بريد|email|e-mail)/iu',
            'إيميل فسخانستا الرسمي: faskhansta@gmail.com',
            'The official Fasakhansta email is: faskhansta@gmail.com',
            'Email Fasakhansta el rasmi: faskhansta@gmail.com');
        $add('PRICE_NOTE','PRICE','/(?:سعر|بكام|اسعار|جنيه|price|cost|bekam|bkam|how much)/iu',
            'الأسعار استرشادية وممكن تختلف حسب الفرع والتطبيق؛ السعر المحدث في الكتالوج.',
            'Reference prices are indicative and may vary by branch and app; check the catalog for current prices.',
            'El as3ar estershadiya we momken tekhtalef 7asab el far3 wel app; el mo7addas fel catalog.');
        $add('ORDER_PRIVATE','ORDER','/(?:اطلب|طلب|اوردر|order|3ay[ez]|3aiz)/iu',
            'للطلب، ضيف المنتجات للسلة وابعت الاسم والعنوان بالتفصيل واللوكيشن ورقمين موبايل على الخاص أو واتساب فقط.',
            'To order, add products to your basket and send your name, detailed address, location and two phone numbers privately or on WhatsApp only.',
            'Lel order, deef el products lel salla web3at esmak wel 3enwan wel location we ra2meen mobile private aw WhatsApp bas.');
        $add('APP_ROUTING','ORDER','/(?:اقرب|فرع|تطبيق|app|nearest)/iu',
            'طلب التطبيق بيتنفذ من أقرب فرع حسب التطبيق، وكل فرع له أسعاره الخاصة.',
            'App orders are routed to the nearest branch according to the app, and each branch has its own prices.',
            'Order el app men a2rab far3 7asab el app, we kol far3 leh as3aro.');
        $add('CHAT_ROUTING','ORDER','/(?:واتساب|ماسنجر|شات|whatsapp|messenger|chat)/iu',
            'طلبات الشات وواتساب بتتبعت غالبًا من شبرا الخيمة عبر إن درايف؛ رسوم التوصيل حسب المنطقة وبتحتاج تأكيد.',
            'Chat and WhatsApp orders are usually sent from Shubra El Kheima via inDrive; delivery fees depend on the area and need confirmation.',
            'Orders el chat we WhatsApp ghaleban men Shubra 3an taree2 inDrive; el tawseel 7asab el mante2a we ye7tag ta2keed.');
        $add('NO_PREORDER','ORDER','/(?:حجز|بكره|بكرا|مسبق|preorder|tomorrow|reservation)/iu',
            'مفيش حجز طلبات ليوم أو وقت محدد؛ الطلب بيكون في نفس اليوم.',
            'Advance orders for a specific day or time are not available; orders are for the same day.',
            'Mafeesh 7agz order le yom aw wa2t mo3ayan; el order fe nafs el yom.');
        $add('WHOLESALE','WHOLESALE','/(?:جمله|جملة|wholesale|gomla)/iu',
            'الحد الأدنى للجملة 50 كيلو، والتنسيق مع الفرع مباشرة؛ الجملة مش بتتطلب أونلاين.',
            'Wholesale starts at 50 kg and is arranged directly with the branch, not through online ordering.',
            'El gomla men 50 kilo, wel tansee2 ma3 el far3 mobashara mesh online.');
        $add('AFTER_DISPATCH','ORDER','/(?:المندوب|تحرك|تعديل|ازود|زود|dispatch|change.*order|rider)/iu',
            'الطلب ما بيتعدلش بعد ما يتحرك مع المندوب؛ أي استفسار عن طلب قائم يتراجع مع الفرع.',
            'An order cannot be changed once it leaves with the rider; contact the branch about an existing order.',
            'El order mayet3adelsh ba3d ma yet7arrak ma3 el mandoob; rage3 el far3 fel order el mawgood.');
        $branchEn = ['Nabrouh and factory', 'Mansoura', 'El Mahalla El Kubra', 'Shubra El Kheima'];
        $addressEn = ['opposite Nabrouh gate', 'Abdel Salam Aref Street', 'El Bandar Square', 'El Sharee El Gedid'];
        $patterns = ['/(?:نبروه|nabrouh|nabroh|فروع|branches)/iu', '/(?:منصوره|mansoura|فروع|branches)/iu',
            '/(?:محله|طنطا|mahalla|tanta|فروع|branches)/iu', '/(?:شبرا|shubra|فروع|branches)/iu'];
        foreach ($r['branches'] as $index => $branch) {
            $add('BRANCH_'.$index,'BRANCHES',$patterns[$index],
                'فرع '.$branch['name'].': '.$branch['address'].'. رقم الفرع: '.$branch['phone'].'.',
                $branchEn[$index].' branch: '.$addressEn[$index].'. Branch phone: '.$branch['phone'].'.',
                'Far3 '.$branchEn[$index].': '.$addressEn[$index].'. Telephone el far3: '.$branch['phone'].'.');
        }
        $add('TANTA_NEAREST','BRANCHES','/(?:طنطا|tanta)/iu',
            'أقرب فرع لطنطا هو المحلة الكبرى في ميدان البندر.',
            'The nearest branch to Tanta is El Mahalla El Kubra at El Bandar Square.',
            'A2rab far3 le Tanta howa El Mahalla El Kubra fe El Bandar Square.');
        $add('ONLINE_ONLY','BRANCHES','/(?:تجمع|اكتوبر|معادي|مهندسين|عبور|tagamo|october|maadi|mohand|obour)/iu',
            'التجمع و6 أكتوبر والمعادي والمهندسين والعبور ستورات أونلاين بالتطبيق وليست فروعًا فعلية، وممكن التنسيق لتوصيل من شبرا الخيمة عبر إن درايف.',
            'Tagamoa, 6 October, Maadi, Mohandessin and Obour are online app stores, not physical branches; delivery from Shubra via inDrive can be checked.',
            'El Tagamo3 we 6 October we Maadi we Mohandessin we Obour stores online fel app mesh foro3 fe3liya; tawseel Shubra 3an taree2 inDrive ye7tag morag3a.');
        $add('HOURS_NORTH','HOURS','/(?:مواعيد|فاتح|بتفتح|بتقفل|ساعه|hours|open|close|wa2t)/iu',
            'تجهيز الطلبات في نبروه والمنصورة والمحلة من 10 الصبح لحد 12:30 بعد منتصف الليل بتوقيت القاهرة.',
            'Order preparation in Nabrouh, Mansoura and Mahalla runs from 10 AM to 12:30 AM after midnight, Cairo time.',
            'Tagheez el orders fe Nabrouh we Mansoura we Mahalla men 10 sob7 le 12:30 ba3d nos el leil, taw2eet Cairo.');
        $add('HOURS_CAIRO','HOURS','/(?:مواعيد|فاتح|بتفتح|بتقفل|شبرا|hours|open|close|shubra)/iu',
            'تجهيز طلبات فرع شبرا الخيمة من 12 الظهر لحد 12:30 بعد منتصف الليل بتوقيت القاهرة؛ الطلب خارج المواعيد يبدأ تجهيزه عند فتح الفرع.',
            'Shubra order preparation runs from noon to 12:30 AM after midnight, Cairo time; out-of-hours orders start preparation when the branch opens.',
            'Tagheez Shubra men 12 dohr le 12:30 ba3d nos el leil, taw2eet Cairo; orders barra el mawa3eed tetgahhez lamma el far3 yefta7.');
        $add('SEATING','BRANCHES','/(?:صاله|جلوس|قعده|حجز|seating|dine|reservation)/iu',
            'الفروع الفعلية فيها صالة جلوس، والزيارة خلال مواعيد التشغيل لا تحتاج حجزًا.',
            'Physical branches have seating; visiting during opening hours does not require a reservation.',
            'El foro3 el fe3liya feha sala, wel zeyara fe mawa3eed el 3amal mesh me7taga 7agz.');
        $add('DELIVERY_LOCAL','DELIVERY','/(?:توصيل|دليفري|بيوصل|هيوصل|قليوب|delivery|arrive|tawseel)/iu',
            'الدليفري المحلي للقاهرة الكبرى ومنها قليوب والمنصورة والمحلة ونبروه والمدن القريبة بياخد تقريبًا من 60 إلى 90 دقيقة؛ المدة تقديرية وليست وعدًا مضمونًا.',
            'Local delivery in Greater Cairo including Qalyub, Mansoura, Mahalla, Nabrouh and nearby cities is estimated at 60–90 minutes, not guaranteed.',
            'El delivery el ma7alli lel Cairo el kobra menha Qalyub we Mansoura we Mahalla we Nabrouh we el modon el 2orayeba ta2reeban 60–90 de2ee2a, mesh wa3d madmoon.');
        $add('DELIVERY_GOV','DELIVERY','/(?:شحن|محافظه|طنطا|اسكندريه|شرقيه|shipping|tanta|alexandria|sharkia)/iu',
            'الشحن لباقي المحافظات بيكون على الموقف العمومي، وتقديره من 2 إلى 3 ساعات؛ السائق بيتواصل بمكان الاستلام وبيانات السيارة.',
            'Shipping to other governorates is to the public transport station, estimated at 2–3 hours; the driver provides collection and vehicle details.',
            'El sha7n lel mo7afazat 3al maw2af el 3omoomi, ta2reeban 2–3 sa3at; el sawa2 bey2ool makan el estelam we bayanat el 3arabeya.');
        $add('NO_INTERNATIONAL','DELIVERY','/(?:اردن|خارج مصر|برا مصر|jordan|outside egypt|international)/iu',
            'مفيش شحن خارج مصر.', 'Shipping outside Egypt is not available.', 'Mafeesh sha7n barra Masr.');
        $add('PAYMENT','PAYMENT','/(?:دفع|كاش|فودافون|انستاباي|payment|cash|instapay|vodafone)/iu',
            'طرق السداد: كاش عند الاستلام، أو فودافون كاش وإنستاباي قبل تحرك الطلب؛ بيانات التحويل المعتمدة تتأكد من الفرع.',
            'Payment is cash on delivery, or Vodafone Cash/InstaPay before dispatch; confirm the approved transfer details with the branch.',
            'El dafa3 cash 3and el estelam, aw Vodafone Cash/InstaPay abl el ta7arrok; bayanat el ta7weel el mo3tamada men el far3.');
        $add('RETURNS','RETURNS','/(?:استرجاع|ارجع|يرجع|مرتجع|refund|return)/iu',
            'مراجعة الاسترجاع في الفرع خلال 12 ساعة بشرط حفظ المنتج في الفريزر إلى حين رجوعه؛ الاسترجاع ليس عن طريق الدليفري، وقبول الحالة يراجعه الفرع.',
            'Returns are reviewed at the branch within 12 hours, with the product kept in the freezer until return; returns are not collected by delivery, and acceptance is reviewed by the branch.',
            'El estergag yetrage3 fel far3 khelal 12 sa3a be shart 7efz el montag fel freezer le7ad el rogoo3; mesh bel delivery, wel 2obool ba3d morag3et el far3.');
        $add('SALT','PRODUCT','/(?:نبروه|دسوق|ملح|فرق|nabrouh|desou|salt|difference)/iu',
            'فسيخ نبروه ملحه خفيف ودلع وسمين، وفسيخ دسوق ملحه مظبوط وطعمه متوسط مش حادق؛ الاتنين من نفس المصنع ونفس الجودة وفق مرجع فسخانستا.',
            'Nabrouh feseekh is lightly salted and rich; Desouk feseekh has medium salt. Both come from the same factory and quality standard in the Fasakhansta reference.',
            'Feseekh Nabrouh mel7o khafeef we sameen, we Desouk mel7o mazboot mesh 7ade2; el etneen men nafs el masna3 we nafs el gawda 7asab marge3 Fasakhansta.');
        $add('GROSS_NET','PRODUCT','/(?:وزن|مخلي|صافي|لحم|weight|fillet|net|makhly|safi)/iu',
            'كيلو السمك المخلي بعدد سمكات بيتوزن كاملًا قبل التنظيف، وقد يوصل لحمًا بنحو نصف الكمية تقريبًا؛ «مخلي صافي» يعني وزن اللحم بعد التنظيف. القاعدة للفسيخ والرنجة والسردين.',
            'A kilo sold by fish count and then filleted is weighed whole before cleaning and may yield roughly half as flesh; net fillet means flesh weight after cleaning. This applies to feseekh, herring and sardines.',
            'Kilo el samak bel 3adad makhly beyetzan kamel abl el tanḍeef, we momken yedee ta2reeban noso la7m; makhly safi howa wazn el la7m ba3d el tanḍeef, lel feseekh wel renga wel sardeen.');
        $add('PREPARATION','PRODUCT','/(?:تجهيز|متنضف|مخلي|سنجاري|cleaned|fillet|prepare|tan[dḍ]eef)/iu',
            'التجهيز كامل، أو متنضف مفتوح سنجاري ببطن نظيفة ورأس مقطوعة والجلد موجود، أو مخلي لحم فقط بالزيت المخصوص؛ الاختيارات والتغليف المفرغ حسب المتاح بالفرع.',
            'Preparation can be whole, cleaned and opened with the head removed and skin on, or boneless flesh with the special oil; choices and vacuum packing depend on branch availability.',
            'El tagheez kamel aw metnaddaf maftoo7 sangary el batn nadeefa wel ras ma2too3a wel geld mawgood, aw makhly la7m bas bel zeit; el ekhteyarat wel vacuum 7asab el mota7 fel far3.');
        $add('FISH_SIZES','PRODUCT','/(?:حجم|وسط|حبات|حبتين|سمكه واحده|size|fish count|single fish)/iu',
            'أحجام الفسيخ حسب المتاح: كيلو 4 حبات حوالي 250 جم للحبة، أو 3 حبات «وسط» حوالي 350 جم، أو حبتين حوالي 500–600 جم للحبة، وسمكة كبيرة حوالي 600–900 جم؛ الأوزان تقريبية.',
            'Feseekh sizes depend on availability: 4 fish per kilo about 250 g each, 3 medium fish about 350 g each, 2 fish about 500–600 g each, or a large single fish about 600–900 g; weights are approximate.',
            'A7gam el feseekh 7asab el mota7: kilo 4 7abbat 7awaly 250 gm lel wa7da, 3 7abbat wasat 350 gm, 7abeten 500–600 gm, aw samaka kbeera 600–900 gm; awzan ta2reebiya.');
        $beforeSeason = $this->now->format('Y-m-d') < '2026-11-15';
        $add('FISH_ROE_SEASON','PRODUCT','/(?=.*(?:فسيخ|feseekh|fesekh))(?=.*(?:مبطرخ|موسم|roe|season|mobattar))/iu',
            $beforeSeason ? 'وفق مرجع أكتوبر، موسم الفسيخ المبطرخ المتوقع من 15 نوفمبر؛ الفيديوهات الحالية بشاير بنحو 5% وليست ضمانًا أن السمكة مبطرخة، والتوفر يتأكد من الفرع.' : 'موعد موسم الفسيخ المبطرخ المذكور في مرجع أكتوبر كان 15 نوفمبر 2026؛ التوفر الحالي يحتاج مراجعة الفرع ولا يضمنه الموعد وحده.',
            $beforeSeason ? 'The October reference expects roe feseekh season from 15 November; early videos show about 5% and do not guarantee roe in a fish. Confirm stock with the branch.' : 'The October reference listed 15 November 2026 for roe feseekh season; the date alone does not establish current branch stock.',
            $beforeSeason ? 'Marge3 October ye2ool mawsim el feseekh el mobattarkh motaweqqa3 men 15 November; el video bashayer 7awaly 5% mesh daman, wel mota7 men el far3.' : 'Marge3 October kan kateb 15 November 2026 lel mawsim; el tareekh wa7do mesh daman lel mota7 delwa2ti.');
        $add('HERRING','PRODUCT','/(?:رنجه|رنج|herring|renga)/iu',
            'أنواع الرنجة بالمرجع: هيرنج، هيرنج مبطرخة، ومدخنة على خشب شجر الليمون. المبطرخة خرز أو ناعمة؛ الناعمة سمينة ومش 100% خرز، وعلب المخلي 200 أو 400 جم حسب المتاح.',
            'The reference lists herring, roe herring and lemon-wood-smoked herring; roe types are granular or soft, and soft is rich rather than 100% granular roe. Fillet cans are 200 or 400 g, subject to availability.',
            'Anwa3 el renga fel marge3: herring, mobattarkha, we lemon wood smoked; el mobattarkha kharaz aw na3ma, wel na3ma mesh 100% kharaz. 3elab el makhly 200 aw 400 gm 7asab el mota7.');
        $add('SARDINE','PRODUCT','/(?:سردين|بساريا|sardine|sardeen|besaria)/iu',
            'السردين المذكور بالمرجع بلدي والبساريا غير متاحة وقت المرجع؛ ربع الكيلو حوالي 3–4 حبات، كامل أو متنضف أو مخلي، وعلب مملحة 200 و400 جم؛ التوفر الحالي يتأكد من الفرع.',
            'The reference lists local sardines, with besaria unavailable at the reference date; a quarter kilo is about 3–4 fish. Whole, cleaned, filleted and 200/400 g salted cans depend on current stock.',
            'El sardeen fel marge3 balady wel besaria mesh mota7a wa2t el marge3; rob3 kilo 7awaly 3–4 7abbat, kamel aw metnaddaf aw makhly, we 3elab 200/400 gm 7asab el mota7.');
        $add('ROE_CAN','PRODUCT','/(?:بطارخ|botar|roe)/iu',
            'علبة البطارخ بالمرجع 400 جم فقط، مش بالوزن ولا أقل من علبة. وصف المرجع طازة مش مجمدة بتاريخ قريب وليس بالضرورة تاريخ اليوم؛ حالة العبوة والتوفر الحالي يتأكدوا من الفرع.',
            'The reference sells roe in a 400 g can only, not loose or in smaller portions. Its reference description is fresh, not frozen, with a recent date rather than necessarily today; check the current pack and stock with the branch.',
            '3elbet el botarekh fel marge3 400 gm bas, mesh bel wazn wala a2al men 3elba. Wasf el marge3 taza mesh mogammada be tareekh 2orayeb mesh shart elnaharda; rage3 el far3 fel 3elba wel mota7.');
        $add('SANDWICH','PRODUCT','/(?:سندوتش|سندويتش|كومبو|sandwich|combo)/iu',
            'سندوتش الفسيخ الدسوقي بالمرجع 180 جم بعيش بلدي أو أبيض ومعه رغيف إضافي؛ الكومبو سندوتش وشيبسي وبيبسي، والوجبات معها رز وسلطة وطحينة وعيش حسب المنيو الفعلي.',
            'The reference Desouk feseekh sandwich is 180 g in baladi or white bread with an extra empty loaf; the combo includes chips and Pepsi. Meal sides are rice, salad, tahini and bread, subject to the actual menu.',
            'Sandwich el feseekh el desou2y fel marge3 180 gm 3eish balady aw abyad we regeef zeyada; el combo sandwich we chips we Pepsi. El wagbat ma3aha rozz we salata we ta7eena we 3eish 7asab el menu.');
        foreach ($r['indicative_prices_egp'] as $index => $price) {
            $product = $price['product'];
            $enNames = ['Feseekh meal','Sardine meal','Herring meal','A kilo of feseekh in 2, 3 or 4 fish size','250 g net feseekh fillet',
                '200 g feseekh fillet can','Desouk feseekh sandwich','Two Desouk feseekh sandwiches','A kilo of whole sardines',
                'A kilo of cleaned sardines','A kilo of filleted sardines','400 g roe can'];
            $frNames = ['Wagbet feseekh','Wagbet sardeen','Wagbet renga','Kilo feseekh 2 aw 3 aw 4 7abbat','Rob3 kilo feseekh makhly safi',
                '3elbet feseekh makhly 200 gm','Sandwich feseekh desou2y','Sandwicheen feseekh desou2y','Kilo sardeen kamel',
                'Kilo sardeen metnaddaf','Kilo sardeen makhly','3elbet botarekh 400 gm'];
            $subjectPattern = $this->pricePattern($index);
            if (isset($price['variants'])) {
                $values = $price['variants'];
                $ar = 'السعر الاسترشادي لـ '.$product.': عادي '.$values['عادي'].'، بلس '.$values['بلس'].'، توب '.$values['توب'].' جنيه.';
                $en = 'Indicative '.$enNames[$index].' prices: regular '.$values['عادي'].', Plus '.$values['بلس'].', Top '.$values['توب'].' EGP.';
                $fr = 'Se3r estershady le '.$frNames[$index].': 3ady '.$values['عادي'].', Plus '.$values['بلس'].', Top '.$values['توب'].' geneeh.';
            } else {
                $ar = 'السعر الاسترشادي لـ '.$product.': '.$price['price'].' جنيه.';
                $en = 'The indicative price of '.$enNames[$index].' is '.$price['price'].' EGP.';
                $fr = 'El se3r el estershady le '.$frNames[$index].': '.$price['price'].' geneeh.';
            }
            $add('PRICE_'.$index,'PRICE',$subjectPattern,
                $ar.' السعر يختلف حسب الفرع والتطبيق؛ المحدث في الكتالوج.',
                $en.' Prices vary by branch and app; check the catalog for current prices.',
                $fr.' El se3r yekhtalef 7asab el far3 wel app; el mo7addas fel catalog.');
        }
        $add('PROMOTIONS','PROMOTIONS','/(?:عروض|خصم|خصومات|offer|discount|promotion)/iu',
            $r['promotions_reply'], 'Follow the page for offers as they are announced.', 'Tab3o el page 3ashan el 3orood lamma tenzel.');
        $add('COMPLAINT','COMPLAINT','/(?:شكوي|شكوا|متاخر|اتاخر|تاخر|اتضايق|زعلان|مستني|غلط|late|waiting|complaint|disappointed|bad service|wrong order)/iu',
            'حقك علينا وآسفين على التجربة؛ ابعت لنا رقم الطلب والتفاصيل في الخاص علشان الفرع يراجع الحالة.',
            'We are sorry about your experience. Please send the order number and details privately so the branch can review the case.',
            '7a2ak 3alena we asfeen 3al tagroba; eb3at ra2m el order wel tafaseel private 3ashan el far3 yerage3 el 7ala.');
        $add('PRODUCT_CONCERN','SAFETY','/(?:ريحه|لون|طعم غريب|فاسد|بايظ|سلامه|مجم|smell|strange|spoiled|safe|frozen)/iu',
            'ما نقدرش نأكد سلامة المنتج من الوصف؛ ما تذوقوش للتجربة، وابعت صورته والتفاصيل للفرع في الخاص لمراجعته.',
            'We cannot confirm product safety from a description. Do not taste it to test it; send a photo and details privately to the branch for review.',
            'Ma ne2darsh ne2akked salamet el montag men el wasf; matdoo2oosh lel tagroba, web3at sora we tafaseel private lel far3.');
        $add('SYMPTOMS','SAFETY','/(?:تعبت|اعراض|مش قادر ابلع|تنفس|تسمم|sick|symptoms|swallow|breathe|poison)/iu',
            'لو فيه تعب أو أعراض بعد الأكل، اطلب مساعدة طبية عاجلة، وابعث تفاصيل الحالة للفرع في الخاص. ما نقدرش نشخّص الحالة من الرسائل.',
            'If you feel unwell or have symptoms after eating, seek urgent medical help and send the case details privately to the branch. We cannot diagnose through messages.',
            'Law fe ta3ab aw a3rad ba3d el akl, otlob mosa3da tebbeya 3agela web3at tafaseel el 7ala private lel far3; ma ne2darsh neshakhkhas bel messages.');
        $add('HOME_SALTING','SAFETY','/(?:تمليح.*(?:بيت|منزل)|املح|بملح|salt.*at home|home.*salt)/iu',
            'فريق خدمة العملاء ما بيقدمش إرشادات لتمليح الفسيخ في البيت أو تقييم سلامته؛ راجع جهة مختصة بسلامة الغذاء.',
            'Customer support does not provide home fish-salting instructions or assess its safety; consult a food-safety professional.',
            'Khedmet el 3omala mesh bet2addem ta3leemat tamlee7 feseekh fel beit aw ta2yeem salamto; rage3 mota5asses salamet gheza.');
        return $facts;
    }

    private function pricePattern(int $index): string
    {
        $subject = [
            '(?=.*(?:وجبه|وجبة|meal|wagb))(?=.*(?:فسيخ|feseekh|fesekh))',
            '(?=.*(?:وجبه|وجبة|meal|wagb))(?=.*(?:سردين|sardine|sardeen))',
            '(?=.*(?:وجبه|وجبة|meal|wagb))(?=.*(?:رنجه|رنج|herring|renga))',
            '(?=.*(?:كيلو|kilo|kg))(?=.*(?:فسيخ|feseekh|fesekh))(?!.*(?:صافي|safi|net|ربع|250))',
            '(?=.*(?:فسيخ|feseekh|fesekh))(?=.*(?:صافي|safi|net))(?=.*(?:ربع|250|quarter|rob3))',
            '(?=.*(?:فسيخ|feseekh|fesekh))(?=.*(?:علبه|can|3elba))(?=.*(?:200|صغير|small))',
            '(?=.*(?:سندوتش|سندويتش|sandwich))(?!.*(?:سندوتشين|سندوتشان|two|sandwicheen|2))',
            '(?=.*(?:سندوتشين|سندوتشان|two.*sandwich|sandwicheen|2.*sandwich))',
            '(?=.*(?:سردين|sardine|sardeen))(?=.*(?:كيلو|kilo|kg))(?=.*(?:كامل|whole|kamel))',
            '(?=.*(?:سردين|sardine|sardeen))(?=.*(?:كيلو|kilo|kg))(?=.*(?:متنضف|cleaned|metnaddaf))',
            '(?=.*(?:سردين|sardine|sardeen))(?=.*(?:كيلو|kilo|kg))(?=.*(?:مخلي|fillet|makhly))',
            '(?=.*(?:بطارخ|roe|botar))(?=.*(?:علبه|400|can|3elba))',
        ][$index];
        return '/\A(?=.*(?:سعر|بكام|اسعار|جنيه|price|cost|bekam|bkam|how much))'.$subject.'.*\z/isu';
    }

    private function language(string $language): string { return in_array($language, ['ar','en','fr'], true) ? $language : 'ar'; }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي','ة'=>'ه']);
        return trim((string) preg_replace('/[\x{0640}\x{064b}-\x{065f}\p{Cf}]/u', '', $text));
    }

    /** One shared classifier prevents the prompt and publishing guard from disagreeing. */
    public static function seriousFactIds(string $comment): array
    {
        $text = self::normalize($comment); $ids = [];
        if (preg_match('/(?:تعبت|اعراض|تسمم|اتسمم|تنفس|مش قادر ابلع|بطني|وجع.*بطن|اسهال|قيء|ترجيع|زغلله|sick|symptoms|swallow|breathe|poison|vomit|diarrh|stomach.*(?:pain|hurt))/iu', $text)) $ids[] = 'SYMPTOMS';
        if (preg_match('/(?:ريحه|لون|طعم غريب|فاسد|بايظ|سلامه|مجم|smell|strange|spoiled|safe|frozen)/iu', $text)) $ids[] = 'PRODUCT_CONCERN';
        if (preg_match('/(?:شكوي|شكوا|اشتكي|متاخر|اتاخر|تاخر|اتضايق|متضايق|زعلان|مستاء|محبط|مستني|غلط|late|waiting|complaint|disappointed|upset|bad service|wrong order)'
            .'|(?:محدش|ماحدش|مفيش\s*حد)\s*(?:فيكم\s*)?(?:بيرد|رد|ب\s*يرد)'
            .'|(?:الاكل|الوجبه|الطلب|الاوردر|الطعم|الخدمه|التعامل|السمك|الفسيخ|الرنجه)\s*(?:كان|كانت|طلع|طلعت)?\s*(?:وحش|وحشه|بارد|بارده|بايظ|بايظه|سيء|سيئ|مقرف)'
            .'|(?:الطلب|الاوردر|التوصيل|الدليفري)\s*(?:كان|فضل|لسه|لسا)?\s*(?:ما\s*وصلش|موصلش|مجاش)'
            .'|\b(?:no\s*one|nobody)\s*(?:is\s*)?(?:replying|replies|responds|responding)\b'
            .'|\b(?:food\s*(?:is\s*)?(?:cold|bad)|order\s*(?:is\s*)?(?:wrong|missing))\b/iu', $text)) $ids[] = 'COMPLAINT';
        if (preg_match('/(?:تمليح.*(?:بيت|منزل)|املح|بملح|salt.*at home|home.*salt)/iu', $text)) $ids[] = 'HOME_SALTING';
        return $ids;
    }

    private function validReference($reference): bool
    {
        if (!is_array($reference) || ($reference['schema_version'] ?? null) !== 1
            || ($reference['reference_id'] ?? null) !== self::REFERENCE
            || ($reference['official_links']['catalog'] ?? null) !== 'https://wa.me/c/201285545554'
            || ($reference['official_links']['application'] ?? null) !== 'https://fasakhaninja.com/downloadApp'
            || !is_array($reference['branches'] ?? null) || count($reference['branches']) !== 4
            || !is_array($reference['indicative_prices_egp'] ?? null) || count($reference['indicative_prices_egp']) !== 12
            || ($reference['promotions_reply'] ?? null) !== 'تابعونا، العروض بتنزل على الصفحة أول بأول 😉') return false;
        $branchSubjects = ['نبروه والمصنع','المنصورة','المحلة الكبرى','شبرا الخيمة'];
        $priceSubjects = ['وجبة فسيخ','وجبة سردين','وجبة رنجة','كيلو فسيخ، 2 أو 3 أو 4 حبات',
            'ربع كيلو فسيخ مخلي صافي','علبة فسيخ مخلي 200 جم','سندوتش فسيخ دسوقي','سندوتشان فسيخ دسوقي',
            'كيلو سردين كامل','كيلو سردين متنضف','كيلو سردين مخلي','علبة بطارخ 400 جم'];
        if (array_keys($reference['branches']) !== [0,1,2,3]
            || array_keys($reference['indicative_prices_egp']) !== range(0,11)) return false;
        foreach ($reference['branches'] as $index => $branch) {
            if (($branch['name'] ?? null) !== $branchSubjects[$index]) return false;
            foreach (['name','address','phone'] as $key)
                if (!is_string($branch[$key] ?? null) || $branch[$key] === '' || strlen($branch[$key]) > 500
                    || !preg_match('//u', $branch[$key]) || preg_match('/[\x00-\x1f\x7f<>]/u', $branch[$key])) return false;
            if (!preg_match('/\A0[0-9]{9,10}\z/', $branch['phone'])) return false;
        }
        foreach ($reference['indicative_prices_egp'] as $index => $price) {
            if (($price['product'] ?? null) !== $priceSubjects[$index]) return false;
            if ($index >= 3 && array_key_exists('variants', $price)) return false;
            $values = $index < 3 ? ($price['variants'] ?? null) : ['price' => $price['price'] ?? null];
            if (!is_array($values) || ($index < 3 && array_keys($values) !== ['عادي','بلس','توب'])) return false;
            foreach ($values as $value) if (!is_int($value) || $value < 0 || $value > 1000000) return false;
        }
        return true;
    }

    private function privateFile(string $directory, string $name, int $limit): string
    {
        if (!function_exists('posix_geteuid') || realpath($directory) !== $directory || is_link($directory)) throw new \RuntimeException('PRIVATE_REFERENCE');
        $dir = @lstat($directory);
        if (!$dir || ($dir['mode'] & 0170000) !== 0040000 || ($dir['mode'] & 07777) !== 0700
            || $dir['uid'] !== posix_geteuid()) throw new \RuntimeException('PRIVATE_REFERENCE');
        $path = $directory.'/'.$name; $meta = @lstat($path);
        if (!$meta || ($meta['mode'] & 0170000) !== 0100000 || ($meta['mode'] & 07777) !== 0600
            || $meta['uid'] !== posix_geteuid() || $meta['nlink'] !== 1 || $meta['size'] < 1 || $meta['size'] > $limit) throw new \RuntimeException('PRIVATE_REFERENCE');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('PRIVATE_REFERENCE');
        try {
            $opened = fstat($handle);
            if (!$opened || $opened['ino'] !== $meta['ino'] || $opened['dev'] !== $meta['dev']) throw new \RuntimeException('PRIVATE_REFERENCE');
            $contents = stream_get_contents($handle, $limit + 1);
            if (!is_string($contents) || strlen($contents) !== $meta['size']) throw new \RuntimeException('PRIVATE_REFERENCE');
            return $contents;
        } finally { fclose($handle); }
    }
}
