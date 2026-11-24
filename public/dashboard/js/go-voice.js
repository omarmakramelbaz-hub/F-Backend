/* GO voice: one assistant for every dashboard page, living with the persistent shell.
   - "افتح <قسم>" opens a section from the sidebar menu the account can already see.
   - On an order page that registers itself (takeaway, delivery, dining), GO fills the cart:
     "زود 2 كيلو فسيخ كبير", "شيل الرنجه", "اقرا الطلب". Saving the order always stays with the employee.
   - A typed command works the same as a spoken one (for browsers without speech recognition).
   Fixed grammar only: no arbitrary URLs, no inferred mutations, no saving. */
(function (window, document) {
    'use strict';
    if (window.GoVoice) return;

    // Electron has no speech service behind webkitSpeechRecognition, and GO ERP Desktop blocks the microphone: type there.
    var desktop = /\bElectron\//.test(navigator.userAgent);
    var Recognition = desktop ? null : (window.SpeechRecognition || window.webkitSpeechRecognition);
    var NUMBERS = {'واحد': 1, 'واحده': 1, 'اتنين': 2, 'اثنين': 2, 'اتنان': 2, 'اثنان': 2, 'تلاته': 3, 'ثلاثه': 3, 'تلات': 3, 'ثلاث': 3,
        'اربعه': 4, 'اربع': 4, 'خمسه': 5, 'خمس': 5, 'سته': 6, 'ست': 6, 'سبعه': 7, 'سبع': 7, 'تمانيه': 8, 'ثمانيه': 8, 'تمن': 8,
        'تسعه': 9, 'تسع': 9, 'عشره': 10, 'عشر': 10};
    var FRACTIONS = {'نص': 0.5, 'نصف': 0.5, 'ربع': 0.25};
    var KILO = ['كيلو', 'كيلوجرام', 'كيلو جرام', 'كجم', 'كيلوات'];
    var GRAM = ['جرام', 'جم', 'غرام'];
    var PIECES = ['قطعه', 'قطع', 'حته', 'حتت', 'علبه', 'علب', 'طبق', 'اطباق', 'وجبه', 'وجبات', 'عدد'];
    var orders = null, catalogCache = null, pending = null, pendingTimer = 0;
    var ui = null, listening = false, recognition = null, micTimer = 0, epoch = 0, utterance = null, speechTimer = 0, speechEpoch = 0, voiceWait = null;

    function normalize(text) {
        return String(text || '').toLowerCase().replace(/[ً-ٰٟـ]/g, '')
            .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
            .replace(/٫/g, '.').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
            .replace(/[،,؟?!؛;:"'«»()\[\]{}]/g, ' ').replace(/(?<!\d)\.|\.(?!\d)/g, ' ').replace(/\s+/g, ' ').trim();
    }
    function clean(text) { return String(text || '').replace(/\s+/g, ' ').trim(); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
    function amountText(value) { return String(Math.round(value * 1000) / 1000); }

    /* ---------- speech out ---------- */
    function robot() { return window.GoHologramShell && window.GoHologramShell.robot; }
    function robotSpeaking(on) { var r = robot(); if (r && r.setSpeaking) r.setSpeaking(on); }
    function clearVoiceWait() { if (voiceWait) { clearTimeout(voiceWait.timer); if (window.speechSynthesis && window.speechSynthesis.removeEventListener) window.speechSynthesis.removeEventListener('voiceschanged', voiceWait.handler); voiceWait = null; } }
    function stopSpeech() {
        speechEpoch++; clearVoiceWait(); clearTimeout(speechTimer); speechTimer = 0;
        if (utterance) { utterance.onstart = utterance.onend = utterance.onerror = null; utterance = null; if (window.speechSynthesis) window.speechSynthesis.cancel(); }
        robotSpeaking(false);
    }
    function speak(text) {
        stopSpeech();
        if (!window.speechSynthesis || !window.SpeechSynthesisUtterance || document.hidden) return;
        var current = speechEpoch;
        function play() {
            if (current !== speechEpoch) return false;
            var voices = window.speechSynthesis.getVoices(), arabic = voices.filter(function (v) { return /^ar(?:-|$)/i.test(v.lang); });
            var voice = arabic.find(function (v) { return /^ar-EG$/i.test(v.lang); }) || arabic[0];
            if (!voice) return false; // never read Arabic with an English voice
            clearVoiceWait();
            var item = new window.SpeechSynthesisUtterance(text); item.lang = voice.lang; item.voice = voice; item.rate = 1; utterance = item;
            item.onstart = function () { if (utterance === item) robotSpeaking(true); };
            item.onend = item.onerror = function () { if (utterance !== item) return; utterance = null; clearTimeout(speechTimer); robotSpeaking(false); };
            speechTimer = window.setTimeout(stopSpeech, 30000);
            try { window.speechSynthesis.speak(item); } catch (_) { utterance = null; robotSpeaking(false); }
            return true;
        }
        if (play() || !window.speechSynthesis.addEventListener) return;
        voiceWait = {handler: play, timer: window.setTimeout(function () {
            clearVoiceWait();
            if (current === speechEpoch && ui) ui.note.textContent = 'مفيش صوت عربي على الجهاز ده، فهرد كتابة بس. تقدر تضيف صوت عربي من إعدادات الجهاز.';
        }, 1200)};
        window.speechSynthesis.addEventListener('voiceschanged', play);
    }
    function reply(text, voice) {
        if (!ui) return;
        ui.status.textContent = text;
        if (voice !== false) speak(text);
    }

    /* ---------- sections from the sidebar menu ---------- */
    function menuLinks() {
        return Array.from(document.querySelectorAll('#dashboard-sidebar a.nav-link[href], .main-sidebar a.nav-link[href]')).filter(function (link, index, all) {
            if (all.indexOf(link) !== index || link.hasAttribute('download') || (link.target && link.target !== '_self')) return false;
            var href = link.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return false;
            try { var url = new URL(href, window.location.href); if (url.origin !== window.location.origin) return false; } catch (_) { return false; }
            return !!linkLabel(link);
        });
    }
    function linkLabel(link) { var p = link.querySelector('p'); var copy = (p || link).cloneNode(true); copy.querySelectorAll('.badge, .right, i, span.badge').forEach(function (b) { b.remove(); }); return clean(copy.textContent); }
    var SECTION_ALIASES = {
        'تيك اواي': ['تيك اوي', 'التيك اواي', 'التيك اوي', 'الكاشير'], 'الصاله': ['صاله', 'الطاولات', 'الترابيزات'],
        'الدليفري': ['دليفري', 'التوصيل', 'طلبات التليفون', 'طلبات الدليفري'], 'المصروفات': ['المصاريف', 'مصروفات'],
        'الموظفون والرواتب': ['الموظفين', 'المرتبات', 'الرواتب'], 'الرئيسيه': ['الصفحه الرئيسيه', 'الهوم']
    };
    function findSections(term) {
        term = normalize(term).replace(/^(صفحه|قسم|شاشه) /, '');
        var links = menuLinks().map(function (link) { return {link: link, label: linkLabel(link), name: normalize(linkLabel(link))}; });
        Object.keys(SECTION_ALIASES).forEach(function (key) { if (SECTION_ALIASES[key].indexOf(term) !== -1) term = key; });
        var exact = links.filter(function (item) { return item.name === term || item.name === 'ال' + term || 'ال' + item.name === term; });
        if (exact.length) return exact;
        var words = term.split(' ');
        return links.filter(function (item) { var have = item.name.split(' '); return words.every(function (w) { return have.some(function (h) { return h === w || h === 'ال' + w || 'ال' + h === w || (w.length > 3 && h.indexOf(w) === 0); }); }); });
    }
    function openSection(term, quiet) {
        if (/^(الرئيسيه|الصفحه الرئيسيه|الهوم)$/.test(normalize(term))) {
            reply('تمام، بفتح الرئيسية.', false); if (window.DashboardSPA && window.DashboardSPA.visit) window.DashboardSPA.visit('/admin/dashboard'); else window.location.href = '/admin/dashboard'; return true;
        }
        var found = findSections(term), unique = [];
        found.forEach(function (item) { if (!unique.some(function (u) { return u.link.href === item.link.href; })) unique.push(item); });
        if (unique.length === 1) { reply('تمام، بفتح ' + unique[0].label + '.', false); unique[0].link.click(); return true; }
        if (quiet) return false;
        if (unique.length > 1) reply('لقيت أكتر من قسم: ' + unique.slice(0, 4).map(function (u) { return u.label; }).join('، ') + '. قول الاسم كامل.');
        else reply('مش لاقي قسم بالاسم ده في القائمة بتاعتك.');
        return false;
    }

    /* ---------- orders ---------- */
    function parseAmount(words) {
        var i = 0, value = null, gram = false;
        function is(list) { return i < words.length && list.indexOf(words[i]) !== -1; }
        if (i < words.length && /^\d+(\.\d+)?$/.test(words[i])) { value = parseFloat(words[i]); i++; }
        else if (i < words.length && Object.prototype.hasOwnProperty.call(NUMBERS, words[i])) { value = NUMBERS[words[i]]; i++; }
        else if (i < words.length && Object.prototype.hasOwnProperty.call(FRACTIONS, words[i])) { value = FRACTIONS[words[i]]; i++; }
        if (i + 1 < words.length && KILO.indexOf(words[i] + ' ' + words[i + 1]) !== -1) { i += 2; if (value === null) value = 1; }
        else if (is(KILO)) { i++; if (value === null) value = 1; }
        else if (is(GRAM)) { i++; gram = true; }
        else if (is(PIECES)) { i++; if (value === null) value = 1; }
        if (i < words.length && (words[i] === 'ونص' || words[i] === 'ونصف')) { value = (value === null ? 1 : value) + 0.5; i++; }
        else if (i < words.length && words[i] === 'وربع') { value = (value === null ? 1 : value) + 0.25; i++; }
        else if (i + 1 < words.length && words[i] === 'و' && FRACTIONS[words[i + 1]]) { value = (value === null ? 1 : value) + FRACTIONS[words[i + 1]]; i += 2; }
        if (gram && value !== null) value = value / 1000;
        if (i < words.length && words[i] === 'من') i++;
        return {value: value, rest: words.slice(i)};
    }
    function choiceLabel(choice) { return clean(choice && (choice.label || choice.name) || ''); }
    function matchChoice(choices, phrase) {
        phrase = normalize(phrase); if (!phrase) return [];
        var exact = choices.filter(function (c) { return normalize(choiceLabel(c)) === phrase; });
        if (exact.length) return exact;
        return choices.filter(function (c) { var n = normalize(choiceLabel(c)); return n && (n.indexOf(phrase) !== -1 || phrase.indexOf(n) !== -1); });
    }
    function matchProducts(list, phrase) {
        var words = phrase.split(' ').filter(Boolean); if (!words.length) return {kind: 'none', items: []};
        var named = list.map(function (p) { return {product: p, name: normalize(p.name)}; }).filter(function (p) { return p.name; });
        var exact = named.filter(function (p) { return p.name === phrase; });
        if (exact.length) return {kind: 'exact', items: exact.map(function (p) { return {product: p.product, extra: ''}; })};
        // "<name> <choice>": the longest product name the phrase starts with.
        var prefixed = named.filter(function (p) { return phrase.indexOf(p.name + ' ') === 0; }).sort(function (a, b) { return b.name.length - a.name.length; });
        if (prefixed.length && (prefixed.length === 1 || prefixed[0].name.length > prefixed[1].name.length))
            return {kind: 'prefix', items: [{product: prefixed[0].product, extra: phrase.slice(prefixed[0].name.length + 1)}]};
        function covers(name, ws) { var have = name.split(' '); return ws.every(function (w) { return have.some(function (h) { return h === w || h === 'ال' + w || 'ال' + h === w || (w.length > 2 && h.indexOf(w) === 0); }); }); }
        var subset = named.filter(function (p) { return covers(p.name, words); });
        if (subset.length) return {kind: 'subset', items: subset.map(function (p) { return {product: p.product, extra: ''}; })};
        // The last words may name the choice ("فسيخ كبير"): try shorter product phrases.
        for (var cut = words.length - 1; cut > 0; cut--) {
            var head = words.slice(0, cut), shorter = named.filter(function (p) { return covers(p.name, head); });
            if (shorter.length) return {kind: 'subset', items: shorter.map(function (p) { return {product: p.product, extra: words.slice(cut).join(' ')}; })};
        }
        return {kind: 'none', items: []};
    }
    function catalog() {
        var key = orders.key ? String(orders.key()) : '';
        if (catalogCache && catalogCache.owner === orders && catalogCache.key === key && Date.now() - catalogCache.at < 120000) return catalogCache.promise;
        var entry = {owner: orders, key: key, at: Date.now(), promise: null};
        entry.promise = Promise.resolve().then(function () { return orders.catalog(); }).then(function (items) { return Array.isArray(items) ? items : []; },
            function (error) { if (catalogCache === entry) catalogCache = null; throw error; });
        catalogCache = entry; return entry.promise;
    }
    function signature() { try { return JSON.stringify(orders.lines()); } catch (_) { return ''; } }
    function lineText(line) {
        var unit = line.mode === 'weight' ? ' كيلو' : '';
        return line.quantity + unit + ' ' + line.name + (line.option ? ' ' + line.option : '');
    }
    function setPending(value) {
        pending = value; clearTimeout(pendingTimer);
        if (value) pendingTimer = window.setTimeout(function () { pending = null; }, 45000);
    }
    function addResolved(product, choice, amount) {
        var blocked = orders.blocked(); if (blocked) { reply(blocked); return; }
        if (!product.available) { reply(product.name + ' مش متاح دلوقتي.'); return; }
        var piece = product.quantity_mode === 'piece';
        if (piece && Math.round(amount) !== amount) { reply(product.name + ' بيتباع بالعدد. قول عدد صحيح، مثلًا: زود 2 ' + product.name + '.'); return; }
        var before = signature();
        orders.add(product, choice || null, amountText(amount));
        if (signature() === before) { reply('ماقدرتش أضيف ' + product.name + '. راجع الكمية أو حالة الشاشة.'); return; }
        var label = choiceLabel(choice);
        reply('تمام، ضفت ' + amountText(amount) + (piece ? '' : ' كيلو') + ' ' + product.name + (label ? ' ' + label : '') + '. راجع الطلب واحفظه بإيدك لما تخلص.');
    }
    function withChoice(product, extra, amount) {
        var choices = orders.choices(product) || [];
        if (choices.length <= 1) { addResolved(product, null, amount); return; }
        var picked = extra ? matchChoice(choices, extra) : [];
        if (picked.length === 1) { addResolved(product, picked[0], amount); return; }
        setPending({product: product, amount: amount, choices: choices});
        reply((extra ? 'مش لاقي الاختيار ده. ' : '') + 'تحب ' + product.name + ' إيه؟ ' + choices.map(choiceLabel).filter(Boolean).join('، ') + '.');
    }
    function addCommand(body) {
        var parsed = parseAmount(body.split(' ')), amount = parsed.value === null ? 1 : parsed.value;
        var phrase = parsed.rest.join(' ');
        if (!phrase) { reply('قول اسم الصنف، مثلًا: زود 2 كيلو فسيخ.'); return; }
        if (!(amount > 0) || amount > 999) { reply('الكمية دي مش مقبولة.'); return; }
        var blocked = orders.blocked(); if (blocked) { reply(blocked); return; }
        reply('بدوّر على ' + phrase + '…', false);
        var mine = orders;
        catalog().then(function (list) {
            if (orders !== mine) return;
            var result = matchProducts(list, phrase), items = result.items;
            if (!items.length) { reply('مش لاقي صنف اسمه ' + phrase + ' في المنيو.'); return; }
            var distinct = items.filter(function (item, i) { return items.findIndex(function (o) { return String(o.product.id) === String(item.product.id); }) === i; });
            if (distinct.length > 1) {
                var available = distinct.filter(function (i) { return i.product.available; });
                if (available.length === 1) distinct = available;
            }
            if (distinct.length > 1) { reply('لقيت أكتر من صنف: ' + distinct.slice(0, 4).map(function (i) { return i.product.name; }).join('، ') + '. قول الاسم كامل.'); return; }
            withChoice(distinct[0].product, distinct[0].extra, amount);
        }, function () { if (orders === mine) reply('ماقدرتش أحمّل المنيو. جرّب تاني.'); });
    }
    function removeCommand(phrase) {
        var lines = orders.lines(); if (!lines.length) { reply('الطلب فاضي.'); return; }
        var blocked = orders.blocked(); if (blocked) { reply(blocked); return; }
        var words = normalize(phrase).split(' ');
        var hits = lines.map(function (line, index) { return {line: line, index: index, name: normalize(line.name + ' ' + (line.option || ''))}; })
            .filter(function (item) { var have = item.name.split(' '); return words.every(function (w) { return have.some(function (h) { return h === w || h === 'ال' + w || 'ال' + h === w || (w.length > 2 && h.indexOf(w) === 0); }); }); });
        if (hits.length !== 1) { reply(hits.length ? 'فيه أكتر من سطر بالاسم ده: ' + hits.map(function (h) { return lineText(h.line); }).join('، ') + '.' : 'مفيش ' + phrase + ' في الطلب.'); return; }
        var before = signature(); orders.remove(hits[0].index);
        if (signature() === before) { reply('ماقدرتش أشيل ' + hits[0].line.name + ' من هنا.'); return; }
        reply('شلت ' + lineText(hits[0].line) + '.');
    }
    function readOrder() {
        var lines = orders.lines();
        if (!lines.length) { reply('الطلب لسه فاضي.'); return; }
        reply('الطلب فيه: ' + lines.map(lineText).join('، ') + '.');
    }

    /* ---------- commands ---------- */
    function help() {
        reply(orders ? 'جرّب: زود 2 كيلو فسيخ، أو شيل الرنجة، أو اقرا الطلب، أو افتح المصروفات.' : 'جرّب: افتح المصروفات، أو افتح التيك أواي. وفي صفحات الطلبات أقدر أضيف أصناف بصوتك.');
    }
    function execute(text) {
        stopMic(); stopSpeech();
        var raw = clean(text), command = normalize(raw);
        command = command.replace(/^(يا (جو|جيو|go) |لو سمحت |من فضلك |ممكن |بعد اذنك )+/, '').replace(/ (لو سمحت|من فضلك)$/, '');
        if (ui) ui.heard.textContent = raw ? '«' + raw + '»' : '';
        if (!command || raw.length > 160) { help(); return false; }
        if (/^(اسكت|وقف|توقف|وقف الصوت|ايقاف الصوت|الغاء|الغي الامر)$/.test(command)) { setPending(null); reply('تمام.', false); return true; }
        if (/^(مساعده|الاوامر|تقدر تعمل ايه|بتعمل ايه)$/.test(command)) { help(); return true; }
        if (pending && orders) {
            var picked = matchChoice(pending.choices, command.replace(/^(خليه|خليها|عايزه|عايزها|اختار) /, ''));
            if (picked.length === 1) { var p = pending; setPending(null); addResolved(p.product, picked[0], p.amount); return true; }
        }
        setPending(null);
        var match;
        if (orders) {
            if (/^(اقرا الطلب|اقرالي الطلب|الطلب فيه ايه|راجع الطلب|اقرا الفاتوره|الفاتوره فيها ايه)$/.test(command)) { readOrder(); return true; }
            if (/^(ابعت|احفظ|سجل|اكد|اعتمد|اقفل|ادفع|خلص)( الطلب| الفاتوره| الاوردر)?$/.test(command)) { reply('الحفظ والإرسال بإيدك أنت: راجع الطلب واضغط زرار الحفظ.'); return true; }
            if ((match = command.match(/^(?:شيل|امسح|الغي|احذف) (.+)$/))) { removeCommand(match[1]); return true; }
            if ((match = command.match(/^(?:زود|زودلي|ضيف|ضيفلي|اضف|هات|هاتلي|حط|حطلي|عايز|عاوز|اطلب|اكتب) (.+)$/))) { addCommand(match[1]); return true; }
        }
        if ((match = command.match(/^(?:افتح|افتحلي|روح ل|روح علي|روح|اذهب الي|وديني ل|وديني علي|وديني|اعرض صفحه|ادخل علي|ادخل) (.+)$/))) return openSection(match[1]);
        // The home summary answers its own questions ("عدد العملاء كام؟").
        var hud = document.querySelector('[data-hud-commands-toggle]'), hudRoot = hud && hud.parentElement;
        if (hudRoot && hudRoot.goHudCommands && /(كام|كم|قد ايه)$|^(اقرا|قولي|كام|كم) /.test(command)) return hudRoot.goHudCommands.execute(raw);
        if (!orders && /^(زود|ضيف|اضف|هات|حط|عايز|عاوز|اطلب) /.test(command)) { reply('افتح صفحة التيك أواي أو الدليفري أو الصالة الأول، وبعدين قولي الأصناف.'); return false; }
        help(); return false;
    }

    /* ---------- microphone ---------- */
    function stopMic() {
        epoch++; clearTimeout(micTimer); micTimer = 0;
        var previous = recognition; recognition = null; listening = false;
        if (ui) { ui.mic.textContent = Recognition ? 'اتكلم' : 'الصوت غير متاح'; ui.mic.setAttribute('aria-pressed', 'false'); ui.panel.classList.remove('listening'); }
        if (previous) { previous.onresult = previous.onerror = previous.onend = previous.onstart = null; try { previous.abort(); } catch (_) {} }
    }
    function startMic() {
        if (!ui || !Recognition) return;
        if (listening || recognition) { stopMic(); reply('وقفت الاستماع.', false); return; }
        stopSpeech();
        var session = ++epoch, rec;
        try { rec = new Recognition(); } catch (_) { reply('ماقدرتش أشغّل الميكروفون.', false); return; }
        recognition = rec; rec.lang = 'ar-EG'; rec.continuous = false; rec.interimResults = false; rec.maxAlternatives = 1;
        ui.mic.setAttribute('aria-pressed', 'true'); ui.mic.textContent = 'إيقاف'; ui.panel.classList.add('listening'); ui.status.textContent = 'بطلب الميكروفون…';
        rec.onstart = function () { if (session === epoch) { listening = true; ui.status.textContent = 'سامعك… قول أمر واحد.'; } };
        rec.onresult = function (event) {
            if (session !== epoch) return;
            var result = event.results && event.results[event.resultIndex || 0];
            if (result && result.isFinal && result[0] && typeof result[0].transcript === 'string') execute(result[0].transcript);
        };
        rec.onerror = function (event) {
            if (session !== epoch) return; stopMic();
            var code = event.error;
            reply(code === 'not-allowed' || code === 'service-not-allowed' ? 'الميكروفون مش مسموح. فعّله من إعدادات المتصفح.' : code === 'network' ? 'خدمة التعرّف على الصوت مش متاحة دلوقتي. اكتب الأمر بدل ما تقوله.' : code === 'no-speech' ? 'ماسمعتش حاجة. اضغط اتكلم وقول الأمر تاني.' : 'التعرّف على الصوت مش متاح دلوقتي. اكتب الأمر.', false);
        };
        rec.onend = function () { if (session === epoch) { stopMic(); if (ui.status.textContent.indexOf('سامعك') === 0) ui.status.textContent = 'خلص الاستماع. اضغط اتكلم وجرّب تاني.'; } };
        micTimer = window.setTimeout(function () { if (session === epoch) { stopMic(); reply('خلص وقت الاستماع.', false); } }, 15000);
        try { rec.start(); } catch (_) { stopMic(); reply('ماقدرتش أشغّل الميكروفون.', false); }
    }

    /* ---------- panel ---------- */
    function build() {
        if (ui || !document.body) return;
        var toggle = el('button', 'go-voice-toggle'); toggle.type = 'button'; toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-controls', 'go-voice-panel');
        toggle.title = 'تحدّث مع GO (Alt+G)'; toggle.appendChild(el('i', 'fas fa-microphone')); toggle.appendChild(el('span', '', 'GO'));
        var panel = el('section', 'go-voice-panel'); panel.id = 'go-voice-panel'; panel.hidden = true; panel.dir = 'rtl'; panel.setAttribute('aria-label', 'أوامر GO الصوتية');
        var head = el('div', 'go-voice-head'), title = el('strong', '', 'GO معاك'), mode = el('span', 'go-voice-mode'), close = el('button', 'go-voice-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'إغلاق');
        head.append(title, mode, close);
        var heard = el('p', 'go-voice-heard'), status = el('p', 'go-voice-status', ''); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var mic = el('button', 'go-voice-mic', Recognition ? 'اتكلم' : 'الصوت غير متاح'); mic.type = 'button'; mic.setAttribute('aria-pressed', 'false'); mic.disabled = !Recognition; mic.hidden = !Recognition;
        var stop = el('button', 'go-voice-stop', 'اسكت'); stop.type = 'button';
        var form = el('form', 'go-voice-form'), input = el('input', 'go-voice-input'); input.type = 'text'; input.maxLength = 160; input.placeholder = 'أو اكتب الأمر هنا…'; input.setAttribute('aria-label', 'اكتب أمر لـ GO'); input.autocomplete = 'off';
        var send = el('button', 'go-voice-send', 'نفّذ'); send.type = 'submit'; form.append(input, send);
        var controls = el('div', 'go-voice-controls'); controls.append(mic, stop);
        var note = el('p', 'go-voice-note', Recognition ? 'الميكروفون بيشتغل لما تضغط بس، والمتصفح ممكن يستخدم خدمة إنترنت علشان يفهم الكلام.' : (desktop ? 'في البرنامج اكتب الأمر واضغط Enter. الأوامر بالصوت شغالة لما تفتح الداشبورد من Chrome.' : 'المتصفح ده مش بيدعم التعرّف على الصوت، بس تقدر تكتب الأمر.'));
        panel.append(head, controls, form, heard, status, note);
        document.body.append(toggle, panel);
        ui = {toggle: toggle, panel: panel, mode: mode, heard: heard, status: status, mic: mic, input: input, note: note};
        function setOpen(open) {
            panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open));
            if (open) { refreshMode(); if (!status.textContent) help(); (Recognition ? mic : input).focus({preventScroll: true}); }
            else { stopMic(); stopSpeech(); setPending(null); }
        }
        ui.setOpen = setOpen;
        toggle.addEventListener('click', function () { var open = panel.hidden; setOpen(open); if (open && Recognition) startMic(); });
        close.addEventListener('click', function () { setOpen(false); toggle.focus({preventScroll: true}); });
        mic.addEventListener('click', startMic);
        stop.addEventListener('click', function () { stopMic(); stopSpeech(); setPending(null); });
        form.addEventListener('submit', function (event) { event.preventDefault(); var value = input.value; input.value = ''; execute(value); });
        panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setOpen(false); toggle.focus({preventScroll: true}); } });
        // Order pages listen to the keyboard for their keypad; keys typed in GO's panel stay in GO.
        ['keydown', 'keyup', 'keypress'].forEach(function (type) { panel.addEventListener(type, function (event) { if (event.target === input) event.stopPropagation(); }); });
        document.addEventListener('keydown', function (event) {
            if (event.altKey && !event.ctrlKey && !event.metaKey && (event.code === 'KeyG')) { event.preventDefault(); var open = panel.hidden; setOpen(open); if (open && Recognition) startMic(); }
        });
        document.addEventListener('visibilitychange', function () { if (document.hidden) { stopMic(); stopSpeech(); } });
        document.addEventListener('dashboard:before-unload', function () { stopMic(); stopSpeech(); setPending(null); });
        refreshMode();
    }
    function refreshMode() {
        if (!ui) return;
        var hud = document.querySelector('[data-hud-commands-toggle]');
        // The home summary has its own GO panel; it opens sections through this assistant.
        ui.toggle.hidden = !!(hud && hud.offsetParent !== null) && ui.panel.hidden;
        ui.mode.textContent = orders ? 'طلب ' + orders.title : '';
    }

    window.GoVoice = {
        version: 1,
        execute: execute,
        openSection: function (term) { return openSection(term, true); },
        /* An order page registers how GO reads its menu and fills its cart. Returns {unregister}. */
        registerOrders: function (adapter) {
            if (!adapter || typeof adapter.add !== 'function') return null;
            orders = adapter; catalogCache = null; setPending(null); refreshMode();
            return {unregister: function () { if (orders === adapter) { orders = null; catalogCache = null; setPending(null); refreshMode(); } }};
        }
    };
    if (document.body) build(); else document.addEventListener('DOMContentLoaded', build);
    document.addEventListener('dashboard:page-mounted', function () { window.requestAnimationFrame(refreshMode); });
    window.addEventListener('load', refreshMode);
})(window, document);
