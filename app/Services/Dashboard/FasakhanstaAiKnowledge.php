<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;

/** Owner-only, versioned factual text. No credentials or customer records are read. */
final class FasakhanstaAiKnowledge
{
    public const REFERENCE = 'fasakhansta-owner-20261020';
    private const PRODUCT_CONCERN_PATTERN = '/(?:ريحه|ريحت(?:ه|ها|هم|هن|ك|كم|نا|ي)|رائحه|رائحت(?:ه|ها|هم|هن|ك|كم|نا|ي)|لون|طعم(?:ه|ها|هم|ك|نا|ي)?\s*(?:غريب|متغير|مختلف|وحش)|فاسد|بايظ|سلامه|مجم|smell|strange|spoiled|safe|frozen)'
        .'|\b(?:ri7a|re7a|rey7a|ri7et(?:o|oh|ha)|re7et(?:o|oh|ha)|rey7et(?:o|oh|ha)|ri7t(?:o|ha)|re7t(?:o|ha))\b'
        .'|\bta3m(?:o|oh|ha)?\b.{0,20}\b(?:gharib|ghareeb|ghariba|ghareeba|we7esh|wa7esh|metghayar)\b/iu';
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
            // Public comments have no trusted current branch-menu prices. Keep
            // reference prices only in the full bank for historical receipts.
            if (strncmp($id, 'PRICE_', 6) === 0) continue;
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
        // The official catalog is the current-price source available to comments.
        $selected = [];
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
            'المنيو كله بالصور والأسعار المحدثة هنا: '.$catalog,
            'Here is the menu with photos and current prices: '.$catalog,
            'El menu kolo bel sowar wel as3ar el mo7addasa hena: '.$catalog);
        $add('APP','APP','/(?:تطبيق|ابليكيشن|ابلكيشن|تحميل|app|application|download)/iu',
            'تطبيق فسخانستا تقدر تنزله من هنا: '.$app,
            'You can download the Fasakhansta app here: '.$app,
            'Te2dar tenazzel app Fasakhansta men hena: '.$app);
        $add('MISSING','MISSING','',
            'ابعتلنا على الخاص علشان الفرع يأكدلك التفاصيل.',
            'Message us privately to check the details with the branch.',
            'Eb3atlena private wenshoof el tafaseel ma3 el far3.');
        $add('EMAIL','CONTACT','/(?:ايميل|بريد|email|e-mail)/iu',
            'تقدر تبعتلنا على الإيميل: faskhansta@gmail.com',
            'You can email us at: faskhansta@gmail.com',
            'Te2dar teb3atlena 3al email: faskhansta@gmail.com');
        $add('PRICE_NOTE','PRICE','/(?:سعر|بكام|اسعار|جنيه|price|cost|bekam|bkam|how much)/iu',
            'دي أسعار استرشادية، وممكن تختلف حسب الفرع والتطبيق؛ المحدث دايمًا في الكتالوج.',
            'These are guide prices and may vary by branch and app; the catalog has current prices.',
            'Di as3ar estershadiya, we momken tekhtalef 7asab el far3 wel app; el mo7addas dayman fel catalog.');
        $add('ORDER_PRIVATE','ORDER','/(?:اطلب|طلب|اوردر|order|3ay[ez]|3aiz)/iu',
            'ضيف اللي نفسك فيه للسلة، وابعت اسمك وعنوانك بالتفصيل واللوكيشن ورقم موبايل أساسي، ورقم تاني لو متاح، على الخاص أو واتساب بس.',
            'To order, add products to your basket and send your name, detailed address, location and one main phone number privately or on WhatsApp only; a second number is optional.',
            'Lel order, deef el products lel salla web3at esmak wel 3enwan wel location we ra2m mobile asasy private aw WhatsApp bas; ra2m tany law mota7.');
        $add('APP_ROUTING','ORDER','/(?:اقرب|فرع|تطبيق|app|nearest)/iu',
            'طلب التطبيق بيتبعت من أقرب فرع ليك حسب التطبيق، وكل فرع ليه أسعاره.',
            'App orders come from the nearest branch selected by the app, and each branch has its own prices.',
            'Order el app men a2rab far3 7asab el app, we kol far3 leh as3aro.');
        $add('CHAT_ROUTING','ORDER','/(?:واتساب|ماسنجر|شات|whatsapp|messenger|chat)/iu',
            'طلبات الشات وواتساب غالبًا من شبرا الخيمة مع إن درايف؛ التوصيل حسب منطقتك والفرع بيأكد تكلفته.',
            'Chat and WhatsApp orders usually come from Shubra El Kheima with inDrive; the branch confirms the delivery fee for your area.',
            'Orders el chat we WhatsApp ghaleban men Shubra ma3 inDrive; el tawseel 7asab mante2tak wel far3 beye2akked taklefto.');
        $add('NO_PREORDER','ORDER','/(?:حجز|بكره|بكرا|مسبق|preorder|tomorrow|reservation)/iu',
            'مفيش حجز أوردر ليوم أو ساعة معينة؛ اطلب في نفس اليوم.',
            'Orders are for the same day; we do not take advance bookings for a particular day or time.',
            'Mafeesh 7agz order le yom aw wa2t mo3ayan; el order fe nafs el yom.');
        $add('WHOLESALE','WHOLESALE','/(?:جمله|جملة|wholesale|gomla)/iu',
            'الجملة من 50 كيلو، وبتتنسق مع الفرع مباشرة مش أونلاين.',
            'Wholesale starts at 50 kg and is arranged directly with the branch, not through online ordering.',
            'El gomla men 50 kilo, wel tansee2 ma3 el far3 mobashara mesh online.');
        $add('AFTER_DISPATCH','ORDER','/(?:المندوب|تحرك|تعديل|ازود|زود|dispatch|change.*order|rider)/iu',
            'الأوردر ما بيتعدلش بعد ما يتحرك مع المندوب؛ لو عندك استفسار عن أوردر كلم الفرع.',
            'Once your order leaves with the rider, it cannot be changed. Contact the branch about an existing order.',
            'El order mayet3adelsh ba3d ma yet7arrak ma3 el mandoob; rage3 el far3 fel order el mawgood.');
        $branchEn = ['Nabrouh and factory', 'Mansoura', 'El Mahalla El Kubra', 'Shubra El Kheima'];
        $addressEn = ['opposite Nabrouh gate', 'on Abdel Salam Aref Street', 'at El Bandar Square', 'on El Sharee El Gedid'];
        $addressFr = ['oddam bawabet Nabrouh', 'share3 Abdel Salam Aref', 'Midan El Bandar', 'El Share3 El Gedid'];
        $patterns = ['/(?:نبروه|nabrouh|nabroh|فروع|branches)/iu', '/(?:منصوره|mansoura|فروع|branches)/iu',
            '/(?:محله|طنطا|mahalla|tanta|فروع|branches)/iu', '/(?:شبرا|shubra|فروع|branches)/iu'];
        foreach ($r['branches'] as $index => $branch) {
            $add('BRANCH_'.$index,'BRANCHES',$patterns[$index],
                'فرع '.$branch['name'].': '.$branch['address'].'. تقدر تكلمه على '.$branch['phone'].'.',
                'Our '.$branchEn[$index].' branch is '.$addressEn[$index].'. Call '.$branch['phone'].'.',
                'Far3 '.$branchEn[$index].': '.$addressFr[$index].'. Te2dar tekallemo 3ala '.$branch['phone'].'.');
        }
        $add('TANTA_NEAREST','BRANCHES','/(?:طنطا|tanta)/iu',
            'أقرب فرع ليك من طنطا هو المحلة الكبرى في ميدان البندر.',
            'The nearest branch to Tanta is El Mahalla El Kubra at El Bandar Square.',
            'A2rab far3 le Tanta howa El Mahalla El Kubra fe El Bandar Square.');
        $add('ONLINE_ONLY','BRANCHES','/(?:حلوان|مدينتي|تجمع|اكتوبر|معادي|مهندسين|عبور|helwan|madinat[yi]|madinty|tagamo|october|maadi|mohand|obour)/iu',
            'حلوان ومدينتي والتجمع و6 أكتوبر والمعادي والمهندسين والعبور ستورات أونلاين على التطبيق، مفيش فروع فعلية هناك؛ وممكن تسأل الفرع عن توصيل من شبرا مع إن درايف.',
            'Helwan, Madinaty, Tagamoa, 6 October, Maadi, Mohandessin and Obour have online app stores rather than physical branches. Ask about delivery from Shubra with inDrive.',
            'Helwan we Madinaty we El Tagamo3 we 6 October we Maadi we Mohandessin we Obour stores online fel app mesh foro3 fe3liya; tawseel Shubra 3an taree2 inDrive ye7tag morag3a.');
        $add('HOURS_NORTH','HOURS','/(?:مواعيد|فاتح|بتفتح|بتقفل|ساعه|hours|open|close|wa2t)/iu',
            'نبروه والمنصورة والمحلة بيجهزوا الطلبات من 10 الصبح لحد 12:30 بعد نص الليل بتوقيت القاهرة.',
            'Order preparation in Nabrouh, Mansoura and Mahalla runs from 10 AM to 12:30 AM after midnight, Cairo time.',
            'Tagheez el orders fe Nabrouh we Mansoura we Mahalla men 10 sob7 le 12:30 ba3d nos el leil, taw2eet Cairo.');
        $add('HOURS_CAIRO','HOURS','/(?:مواعيد|فاتح|بتفتح|بتقفل|شبرا|hours|open|close|shubra)/iu',
            'شبرا الخيمة بيجهز الطلبات من 12 الضهر لحد 12:30 بعد نص الليل بتوقيت القاهرة؛ لو طلبت بره المواعيد، التجهيز بيبدأ لما الفرع يفتح.',
            'Shubra prepares orders from noon to 12:30 AM after midnight, Cairo time. Orders placed outside these hours are prepared when the branch opens.',
            'Tagheez Shubra men 12 dohr le 12:30 ba3d nos el leil, taw2eet Cairo; orders barra el mawa3eed tetgahhez lamma el far3 yefta7.');
        $add('SEATING','BRANCHES','/(?:صاله|جلوس|قعده|حجز|seating|dine|reservation)/iu',
            'الفروع الفعلية فيها قعدة، ومش محتاج حجز؛ تنورنا في مواعيد الشغل.',
            'Our physical branches have seating. Come by during opening hours; no reservation needed.',
            'El foro3 el fe3liya feha sala, wel zeyara fe mawa3eed el 3amal mesh me7taga 7agz.');
        $add('DELIVERY_LOCAL','DELIVERY','/(?:توصيل|دليفري|بيوصل|هيوصل|قليوب|delivery|arrive|tawseel)/iu',
            'الدليفري جوه القاهرة الكبرى ومنها قليوب، والمنصورة والمحلة ونبروه والمدن القريبة، حوالي 60–90 دقيقة؛ المدة تقريبية وبتتأكد مع الفرع.',
            'Local delivery in Greater Cairo including Qalyub, Mansoura, Mahalla, Nabrouh and nearby cities is around 60–90 minutes. Check the estimate with the branch.',
            'El delivery gowwa Cairo el kobra menha Qalyub, we Mansoura we Mahalla we Nabrouh wel modon el 2orayeba, 7awaly 60–90 de2ee2a; el modda ta2reebiya we tet2akked ma3 el far3.');
        $add('DELIVERY_GOV','DELIVERY','/(?:شحن|محافظه|طنطا|اسكندريه|شرقيه|shipping|tanta|alexandria|sharkia)/iu',
            'لباقي المحافظات الشحن على الموقف العمومي، حوالي 2–3 ساعات؛ والسواق بيكلمك بمكان الاستلام وبيانات العربية.',
            'Other governorates receive shipments at the public transport station, usually in about 2–3 hours. The driver calls with the pickup and vehicle details.',
            'El sha7n lel mo7afazat 3al maw2af el 3omoomi, ta2reeban 2–3 sa3at; el sawa2 bey2ool makan el estelam we bayanat el 3arabeya.');
        $add('NO_INTERNATIONAL','DELIVERY','/(?:اردن|خارج مصر|برا مصر|jordan|outside egypt|international)/iu',
            'مفيش شحن خارج مصر.', 'Shipping outside Egypt is not available.', 'Mafeesh sha7n barra Masr.');
        $add('PAYMENT','PAYMENT','/(?:دفع|كاش|فودافون|انستاباي|payment|cash|instapay|vodafone)/iu',
            'تقدر تدفع كاش عند الاستلام، أو فودافون كاش وإنستاباي قبل ما الأوردر يتحرك؛ بيانات التحويل خدها من الفرع.',
            'Pay cash on delivery, or use Vodafone Cash/InstaPay before dispatch. Get the transfer details directly from the branch.',
            'El dafa3 cash 3and el estelam, aw Vodafone Cash/InstaPay abl el ta7arrok; bayanat el ta7weel el mo3tamada men el far3.');
        $add('RETURNS','RETURNS','/(?:استرجاع|ارجع|يرجع|مرتجع|refund|return)/iu',
            'الاسترجاع بيتراجع في الفرع خلال 12 ساعة، بشرط المنتج يفضل في الفريزر لحد ما يرجع؛ مش بالدليفري، والفرع بيشوف الحالة ويحدد قبولها.',
            'Bring returns to the branch within 12 hours, keeping the product in the freezer until then. Delivery cannot collect returns; the branch reviews each case.',
            'El estergag fel far3 khelal 12 sa3a, be shart el montag yefdal fel freezer le7ad ma yerga3; mesh bel delivery, wel far3 beyerage3 el 7ala we ye7addad 2oboolha.');
        $add('SALT','PRODUCT','/(?:نبروه|دسوق|ملح|فرق|nabrouh|desou|salt|difference)/iu',
            'فسيخ نبروه وفسيخ دسوق من نفس المصنع ونفس الجودة؛ نبروه ملحه خفيف ودلع وسمين، ودسوق ملحه مظبوط وطعمه متوسط مش حادق.',
            'Both come from the same factory with the same quality. Nabrouh is lightly salted and rich; Desouk has a medium salt level.',
            'El etneen men nafs el masna3 we nafs el gawda; Nabrouh mel7o khafeef we sameen, we Desouk mel7o mazboot mesh 7ade2.');
        $add('GROSS_NET','PRODUCT','/(?:وزن|مخلي|صافي|لحم|weight|fillet|net|makhly|safi)/iu',
            'الكيلو بعدد سمكات مخلي بيتوزن كامل قبل التنضيف، وممكن يطلع لحم حوالي نص الكمية؛ أما «مخلي صافي» فده وزن اللحم بعد التنضيف. نفس الكلام للفسيخ والرنجة والسردين.',
            'A kilo sold by fish count is weighed whole before filleting and gives roughly half its weight as flesh. Net fillet is weighed after cleaning. This applies to feseekh, herring and sardines.',
            'El kilo bel 3adad makhly beyetzan kamel abl el tandeef we beyetla3 ta2reeban noso la7m; makhly safi howa wazn el la7m ba3d el tandeef. Nafs el kalam lel feseekh wel renga wel sardeen.');
        $add('PREPARATION','PRODUCT','/(?:تجهيز|متنضف|مخلي|سنجاري|cleaned|fillet|prepare|tan[dḍ]eef)/iu',
            'اختار كامل، أو متنضف سنجاري ببطن نضيفة ومن غير راس والجلد موجود، أو مخلي لحم بس بالزيت المخصوص؛ التجهيز والتغليف المفرغ حسب المتاح في الفرع.',
            'Preparation can be whole, cleaned and opened with the head removed and skin on, or boneless flesh with the special oil; choices and vacuum packing depend on branch availability.',
            'El tagheez kamel aw metnaddaf maftoo7 sangary el batn nadeefa wel ras ma2too3a wel geld mawgood, aw makhly la7m bas bel zeit; el ekhteyarat wel vacuum 7asab el mota7 fel far3.');
        $add('FISH_SIZES','PRODUCT','/(?:حجم|وسط|حبات|حبتين|سمكه واحده|size|fish count|single fish)/iu',
            'أحجام الفسيخ حسب المتاح: كيلو 4 حبات حوالي 250 جم للحبة، أو 3 حبات وسط حوالي 350 جم، أو حبتين حوالي 500–600 جم للحبة، وسمكة كبيرة حوالي 600–900 جم؛ الأوزان دي تقريبية.',
            'Feseekh sizes depend on availability: 4 fish per kilo about 250 g each, 3 medium fish about 350 g each, 2 fish about 500–600 g each, or a large single fish about 600–900 g; weights are approximate.',
            'A7gam el feseekh 7asab el mota7: kilo 4 7abbat 7awaly 250 gm lel wa7da, 3 7abbat wasat 350 gm, 7abeten 500–600 gm, aw samaka kbeera 600–900 gm; awzan ta2reebiya.');
        $beforeSeason = $this->now->format('Y-m-d') < '2026-11-15';
        $add('FISH_ROE_SEASON','PRODUCT','/(?=.*(?:فسيخ|feseekh|fesekh))(?=.*(?:مبطرخ|موسم|roe|season|mobattar))/iu',
            $beforeSeason ? 'موسم الفسيخ المبطرخ متوقع من 15 نوفمبر 2026؛ اللي في الفيديوهات بشاير حوالي 5%، فمش مضمون السمكة تطلع مبطرخة؛ اسأل الفرع عن المتاح.' : 'موسم الفسيخ المبطرخ كان متوقع من 15 نوفمبر 2026، بس ده لوحده مش ضمان إنه متاح دلوقتي؛ اسأل الفرع عن الموجود.',
            $beforeSeason ? 'Roe feseekh season is expected from 15 November 2026. The early videos show about 5%, so roe is not guaranteed in a fish; check availability with the branch.' : 'Roe feseekh season was expected from 15 November 2026, but that date does not guarantee stock today. Check with the branch.',
            $beforeSeason ? 'Mawsim el feseekh el mobattarkh motaweqqa3 men 15 November 2026; eli fel video bashayer 7awaly 5%, mesh daman el samaka tetla3 mobattarkha. Es2al el far3 3an el mota7.' : 'Mawsim el feseekh el mobattarkh kan motaweqqa3 men 15 November 2026, bas da mesh daman enno mota7 delwa2ti; es2al el far3.');
        $add('HERRING','PRODUCT','/(?:رنجه|رنج|herring|renga)/iu',
            'الرنجة هيرنج، وهيرنج مبطرخة، ومدخنة على خشب شجر الليمون. المبطرخة خرز أو ناعمة؛ الناعمة سمينة ومش 100% خرز، وعلب المخلي 200 أو 400 جم حسب المتاح.',
            'Herring comes as regular, roe or lemon-wood-smoked. Roe types are granular or soft; the soft type is rich but not 100% granular roe. Fillet cans are 200 or 400 g, depending on availability.',
            'El renga herring, mobattarkha, we lemon wood smoked; el mobattarkha kharaz aw na3ma, wel na3ma sameena mesh 100% kharaz. 3elab el makhly 200 aw 400 gm 7asab el mota7.');
        $add('SARDINE','PRODUCT','/(?:سردين|بساريا|sardine|sardeen|besaria)/iu',
            'السردين بلدي، وربع الكيلو حوالي 3–4 حبات؛ كامل أو متنضف أو مخلي، وفيه علب مملحة 200 و400 جم حسب المتاح. البساريا ما كانتش متاحة في أكتوبر 2026؛ اسأل الفرع عن الموجود دلوقتي.',
            'Our sardines are local, with about 3–4 fish per quarter kilo. Whole, cleaned, filleted and 200/400 g salted cans depend on availability. Besaria was unavailable in October 2026; ask the branch about current stock.',
            'El sardeen balady, we rob3 kilo 7awaly 3–4 7abbat; kamel aw metnaddaf aw makhly, we 3elab 200/400 gm 7asab el mota7. El besaria makanetch mota7a fe October 2026; es2al el far3 3an el mawgood delwa2ti.');
        $add('ROE_CAN','PRODUCT','/(?:بطارخ|botar|roe)/iu',
            'البطارخ علبة 400 جم بس، مش بالوزن ولا أقل من علبة. طازة مش مجمدة، وتاريخها قريب مش شرط تاريخ النهارده؛ اسأل الفرع عن حالة وتاريخ العلبة المتاحة.',
            'Roe comes in a 400 g can only, not loose or in smaller portions. It is sold fresh rather than frozen, with a recent date that is not necessarily today; confirm the available can and date with the branch.',
            'El botarekh 3elba 400 gm bas, mesh bel wazn wala a2al men 3elba. Taza mesh mogammada we tareekha 2orayeb mesh shart elnaharda; es2al el far3 3an 7alet we tareekh el 3elba el mota7a.');
        $add('SANDWICH','PRODUCT','/(?:سندوتش|سندويتش|كومبو|sandwich|combo)/iu',
            'سندوتش الفسيخ الدسوقي 180 جم، بعيش بلدي أو أبيض ومعاه رغيف زيادة فاضي. الكومبو معاه شيبسي وبيبسي، والوجبات معاها رز وسلطة وطحينة وعيش حسب المنيو الحالي.',
            'The Desouk feseekh sandwich is 180 g in baladi or white bread, with an extra empty loaf. The combo adds chips and Pepsi; meals come with rice, salad, tahini and bread, subject to the current menu.',
            'Sandwich el feseekh el desou2y 180 gm, 3eish balady aw abyad we ma3ah regeef zeyada fady. El combo ma3ah chips we Pepsi; el wagbat ma3aha rozz we salata we ta7eena we 3eish 7asab el menu el 7ali.');
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
                $ar = $product.' سعرها استرشادي: عادي '.$values['عادي'].'، بلس '.$values['بلس'].'، توب '.$values['توب'].' جنيه.';
                $en = $enNames[$index].' guide prices: regular '.$values['عادي'].', Plus '.$values['بلس'].', Top '.$values['توب'].' EGP.';
                $fr = $frNames[$index].' se3rha estershady: 3ady '.$values['عادي'].', Plus '.$values['بلس'].', Top '.$values['توب'].' geneeh.';
            } else {
                $ar = $product.' بسعر استرشادي '.$price['price'].' جنيه.';
                $en = $enNames[$index].' is around '.$price['price'].' EGP as a guide price.';
                $fr = $frNames[$index].' be se3r estershady '.$price['price'].' geneeh.';
            }
            $add('PRICE_'.$index,'PRICE',$subjectPattern,
                $ar.' السعر ممكن يختلف حسب الفرع والتطبيق؛ المحدث في الكتالوج.',
                $en.' Prices may vary by branch and app; check the catalog for the latest.',
                $fr.' El se3r momken yekhtalef 7asab el far3 wel app; el mo7addas fel catalog.');
        }
        $add('PROMOTIONS','PROMOTIONS','/(?:عروض|خصم|خصومات|offer|discount|promotion)/iu',
            $r['promotions_reply'], 'Follow the page for offers as they are announced.', 'Tab3o el page 3ashan el 3orood lamma tenzel.');
        $add('COMPLAINT','COMPLAINT','/(?:شكوي|شكوا|متاخر|اتاخر|تاخر|اتضايق|زعلان|مستني|غلط|late|waiting|complaint|disappointed|bad service|wrong order)/iu',
            'حقك علينا وآسفين على اللي حصل. ابعتلنا رقم الأوردر والتفاصيل في الخاص علشان الفرع يراجعها.',
            'We are sorry about what happened. Send your order number and the details privately so the branch can review them.',
            '7a2ak 3alena we asfeen 3ala eli 7asal. Eb3atlena ra2m el order wel tafaseel private 3ashan el far3 yerage3ha.');
        $add('PRODUCT_CONCERN','SAFETY',self::PRODUCT_CONCERN_PATTERN,
            'ما نقدرش نطمنك على سلامته من الوصف. ما تذوقوش للتجربة، وابعت صورته والتفاصيل للفرع في الخاص علشان يراجعه.',
            'We cannot confirm it is safe from a description. Do not taste it to test it; send a photo and details to the branch privately for review.',
            'Ma ne2darsh netammenak 3ala salamto men el wasf. Matdoo2oosh lel tagroba, web3at sora we tafaseel lel far3 private 3ashan yerage3o.');
        $add('SYMPTOMS','SAFETY','/(?:تعبت|اعراض|مش قادر ابلع|تنفس|تسمم|sick|symptoms|swallow|breathe|poison)/iu',
            'سلامتك أهم حاجة. لو تعبان أو عندك أعراض بعد الأكل، اطلب مساعدة طبية عاجلة، وابعت التفاصيل للفرع في الخاص. ما نقدرش نشخّص حالتك من الرسائل.',
            'If you feel unwell or have symptoms after eating, seek urgent medical help and send the details to the branch privately. We cannot diagnose over messages.',
            'Law ta3ban aw 3andak a3rad ba3d el akl, otlob mosa3da tebbeya 3agela web3at el tafaseel lel far3 private. Ma ne2darsh neshakhkhas 7altak bel messages.');
        $add('HOME_SALTING','SAFETY','/(?:تمليح.*(?:بيت|منزل)|املح|بملح|salt.*at home|home.*salt)/iu',
            'ما نقدرش نديك نصايح لتمليح الفسيخ في البيت أو نحكم على سلامته؛ اسأل مختص في سلامة الغذاء.',
            'We cannot give home fish-salting instructions or judge its safety; please ask a food-safety professional.',
            'Ma ne2darsh nedeek nasa2e7 tamlee7 feseekh fel beit aw ne7kom 3ala salamto; es2al mota5asses salamet gheza.');
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
        if (preg_match(self::PRODUCT_CONCERN_PATTERN, $text)) $ids[] = 'PRODUCT_CONCERN';
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
