/* GO commands: deliberate, finite Arabic commands over the actor's current HUD only. */
(function (window, document) {
    'use strict';
    if (window.GoHudCommands) return;
    var serial = 0;
    var aliases = {
        'ch-app': ['طلبات التطبيق', 'التطبيق', 'طلبات الابلكيشن'],
        'ch-takeaway': ['تيك اواي', 'تيك اوي', 'التيك اواي', 'طلبات التيك اواي'],
        'ch-phone': ['دليفري', 'الدليفري', 'طلبات الدليفري', 'التوصيل'],
        'ch-dine': ['صاله', 'الصاله', 'طلبات الصاله'],
        'employees': ['الموظفين', 'الموظفون', 'المرتبات', 'الموظفين والرواتب'],
        'customers': ['العملاء', 'الزبائن', 'عدد العملاء'],
        'expenses': ['المصروفات', 'المصاريف', 'عدد المصروفات'],
        'pending-expenses': ['مصروفات منتظره', 'المصروفات المنتظره', 'المصروفات المعلقه', 'مصروفات بانتظار الاعتماد'],
        'drawer': ['تقفيل الورديه', 'القفله', 'رصيد الفروع', 'رصيد ادراج الفروع', 'ارصده الفروع', 'رصيد الادراج'],
        'stock': ['مخزون البضاعه', 'المخزون', 'مخزون', 'البضاعه', 'الاصناف الناقصه']
    };
    function normalize(text) {
        return String(text || '').toLowerCase().replace(/[\u064b-\u065f\u0670\u0640]/g, '')
            .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
            .replace(/[،,؟?!؛;:."'«»()\[\]{}]/g, ' ').replace(/\s+/g, ' ').trim();
    }
    function clean(text) { return String(text || '').replace(/\s+/g, ' ').trim(); }
    function node(tag, text, cls) { var e = document.createElement(tag); if (text) e.textContent = text; if (cls) e.className = cls; return e; }
    function mount(root, options) {
        if (!root) return null;
        if (root.goHudCommands) return root.goHudCommands;
        options = options || {};
        var dead = false, listening = false, recognition = null, micTimer = 0, speechTimer = 0, voiceWaitTimer = 0, voiceWaitHandler = null, utterance = null, epoch = 0, speechEpoch = 0;
        var voiceRequest = null, replyAudio = null, replyAudioURL = null;
        var actor = document.body.dataset.dashboardActor || '', remove = [], observer = null, lastReadCard = null, lastReadSnapshot = '';
        var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        var uid = 'go-command-panel-' + (++serial), panel = node('section', '', 'hud-command-panel');
        var toggle = node('button', 'تحدّث مع GO', 'hud-command-toggle'); toggle.type = 'button';
        toggle.setAttribute('aria-controls', uid); toggle.setAttribute('aria-expanded', 'false'); toggle.dataset.hudCommandsToggle = '';
        panel.id = uid; panel.hidden = true; panel.dir = 'rtl'; panel.setAttribute('aria-label', 'أوامر GO');
        var header = node('div', '', 'hud-command-header'), title = node('strong', 'أنا معاك'), close = node('button', 'إغلاق', 'hud-command-close'); close.type = 'button';
        header.append(title, close);
        var hint = node('p', 'افتح العملاء · عدد العملاء كام؟ · رصيد الفروع كام؟', 'hud-command-hint');
        var mic = node('button', 'اتكلم', 'hud-command-mic'); mic.type = 'button'; mic.setAttribute('aria-pressed', 'false'); mic.dataset.hudCommandMic = '';
        var stopButton = node('button', 'إيقاف الصوت', 'hud-command-stop'); stopButton.type = 'button';
        var replay = node('button', 'تشغيل الرد', 'hud-command-replay'); replay.type = 'button'; replay.hidden = true; replay.dataset.hudCommandReplay = '';
        var controls = node('div', '', 'hud-command-controls'); controls.append(mic, stopButton, replay);
        var status = node('p', 'أقدر أفتح الأقسام وأقرأ الملخص الظاهر لحسابك.', 'hud-command-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.dataset.hudCommandStatus = '';
        var note = node('p', Recognition ? 'قد يستخدم المتصفح خدمة إنترنت للتعرّف على كلامك. الميكروفون يعمل عند الضغط فقط.' : 'التعرّف الصوتي غير متاح في المتصفح ده. افتح GO من متصفح يدعم الأوامر الصوتية.', 'hud-command-note');
        var heard = node('p', '', 'hud-command-note'); heard.hidden = true; heard.dataset.hudCommandHeard = '';
        if (!Recognition) { mic.disabled = true; mic.textContent = 'الصوت غير متاح'; }
        panel.append(header, hint, controls, heard, status, note); root.append(toggle, panel);
        function on(target, event, fn) { target.addEventListener(event, fn); remove.push(function () { target.removeEventListener(event, fn); }); }
        function active() { return !dead && root.isConnected && !root.hidden && !document.hidden && (document.body.dataset.dashboardActor || '') === actor; }
        function robotSpeaking(value) { if (typeof options.setSpeaking === 'function') options.setSpeaking(value); }
        function duck() { if (typeof options.duckGreeting === 'function') options.duckGreeting(); }
        function clearHeard() { if (heard.textContent) heard.textContent = ''; if (!heard.hidden) heard.hidden = true; }
        function clearVoiceWait() {
            clearTimeout(voiceWaitTimer); voiceWaitTimer = 0;
            if (voiceWaitHandler && window.speechSynthesis && window.speechSynthesis.removeEventListener) window.speechSynthesis.removeEventListener('voiceschanged', voiceWaitHandler);
            voiceWaitHandler = null;
        }
        function stopSpeech() {
            speechEpoch++; clearVoiceWait(); clearTimeout(speechTimer); speechTimer = 0;
            if (voiceRequest) { voiceRequest.abort(); voiceRequest = null; }
            if (replyAudio) { replyAudio.onplaying = replyAudio.onended = replyAudio.onerror = null; replyAudio.pause(); replyAudio.removeAttribute('src'); replyAudio = null; }
            if (replyAudioURL) { URL.revokeObjectURL(replyAudioURL); replyAudioURL = null; }
            replay.hidden = true; replay.goPlayReply = null;
            if (utterance) { utterance.onstart = utterance.onend = utterance.onerror = null; utterance = null; if (window.speechSynthesis) window.speechSynthesis.cancel(); }
            robotSpeaking(false);
        }
        function stopMic() {
            epoch++; clearTimeout(micTimer); micTimer = 0;
            var previous = recognition; recognition = null; listening = false;
            mic.textContent = Recognition ? 'اتكلم' : 'الصوت غير متاح'; mic.setAttribute('aria-pressed', 'false'); panel.classList.remove('listening');
            if (previous) { previous.onresult = previous.onerror = previous.onend = previous.onstart = previous.onnomatch = null; try { previous.abort(); } catch (_) {} }
        }
        function stop() { stopMic(); stopSpeech(); }
        function speak(text) {
            stopSpeech();
            if (!active()) return;
            var current = speechEpoch, fallbackStarted = false;
            function currentReply() { return current === speechEpoch && active() && !expireRead(); }
            function unavailable() {
                if (!currentReply()) return;
                stopSpeech(); status.textContent += ' تعذّر تشغيل الرد الصوتي الآن. اضغط اتكلم وحاول تاني.';
            }
            function playReply() {
                if (!currentReply() || !replyAudio) return;
                var player = replyAudio;
                replay.hidden = true;
                try {
                    var pending = player.play();
                    if (pending && pending.catch) pending.catch(function (error) {
                        if (!currentReply() || replyAudio !== player) return;
                        if (error && error.name === 'NotAllowedError') { replay.hidden = false; robotSpeaking(false); }
                        else unavailable();
                    });
                } catch (_) { unavailable(); }
            }
            replay.goPlayReply = playReply;
            function serverVoice() {
                if (!currentReply() || fallbackStarted) return;
                fallbackStarted = true; clearVoiceWait();
                var csrf = document.querySelector('meta[name="csrf-token"]');
                if (!csrf || !csrf.content || !window.fetch || !window.AbortController || !window.Audio) { unavailable(); return; }
                var endpoint = new URL('/admin/dashboard/hologram-arabic/speak', window.location.href);
                var request = new AbortController(); voiceRequest = request;
                speechTimer = window.setTimeout(unavailable, 12000);
                window.fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: request.signal,
                    headers: {'Content-Type': 'application/json', 'Accept': 'audio/wav', 'X-CSRF-TOKEN': csrf.content}, body: JSON.stringify({text: text})})
                    .then(function (response) {
                        if (!currentReply() || request.signal.aborted) return null;
                        if (!response.ok || !/^audio\/(?:x-)?wav(?:;|$)/i.test(response.headers.get('Content-Type') || '')) throw new Error('voice unavailable');
                        return response.blob();
                    }).then(function (blob) {
                        if (!blob || !currentReply() || request.signal.aborted) return;
                        if (blob.size < 44 || blob.size > 2097152) throw new Error('invalid audio');
                        voiceRequest = null; clearTimeout(speechTimer);
                        var player = new Audio(); replyAudio = player; replyAudioURL = URL.createObjectURL(blob); player.src = replyAudioURL;
                        player.onplaying = function () { if (currentReply() && replyAudio === player) robotSpeaking(true); };
                        player.onended = function () { if (current === speechEpoch && replyAudio === player) stopSpeech(); };
                        player.onerror = unavailable;
                        speechTimer = window.setTimeout(function () { if (currentReply()) stopSpeech(); }, 45000);
                        playReply();
                    }).catch(function () { if (!request.signal.aborted) unavailable(); });
            }
            function playIfReady() {
                if (fallbackStarted || !currentReply() || !window.speechSynthesis || !window.SpeechSynthesisUtterance) return false;
                var voices;
                try { voices = window.speechSynthesis.getVoices(); } catch (_) { return false; }
                var arabic = voices.filter(function (voice) { return /^ar(?:-|$)/i.test(voice.lang); });
                var voice = arabic.find(function (v) { return /^ar-EG$/i.test(v.lang); }) || arabic[0];
                // Do not silently read Arabic with an English system voice.
                if (!voice) return false;
                clearVoiceWait();
                var item = new window.SpeechSynthesisUtterance(text); item.lang = voice.lang; item.voice = voice; item.rate = 1; utterance = item;
                item.onstart = function () {
                    if (utterance !== item || !currentReply()) return;
                    clearTimeout(speechTimer); speechTimer = window.setTimeout(stopSpeech, 25000);
                    robotSpeaking(true);
                };
                function finished() { if (utterance !== item) return; utterance = null; clearTimeout(speechTimer); robotSpeaking(false); }
                function failed() {
                    if (utterance !== item || !currentReply()) return;
                    item.onstart = item.onend = item.onerror = null;
                    finished();
                    try { window.speechSynthesis.cancel(); } catch (_) {}
                    serverVoice();
                }
                item.onend = finished;
                item.onerror = failed;
                // An installed system voice can silently fail without an error event.
                speechTimer = window.setTimeout(failed, 3500);
                try { window.speechSynthesis.speak(item); } catch (_) { failed(); }
                return true;
            }
            if (playIfReady()) return;
            if (current !== speechEpoch || !active()) return;
            // System voices are frequently populated asynchronously on the first request.
            if (window.speechSynthesis && window.speechSynthesis.addEventListener) {
                voiceWaitHandler = playIfReady; window.speechSynthesis.addEventListener('voiceschanged', voiceWaitHandler);
                voiceWaitTimer = window.setTimeout(serverVoice, 1200);
            } else serverVoice();
        }
        function readSnapshot(card) {
            return JSON.stringify(['.hud-card-label', '.hud-card-values'].map(function (selector) { return clean((card.querySelector(selector) || {}).textContent); })
                .concat(clean((root.querySelector('[data-hud-scope]') || {}).textContent),
                    card.dataset.balanceState || '', root.querySelector('.hud-live.stale') ? 'stale' : 'ready'));
        }
        function reply(text, voice, readCard) { if (!active()) return; lastReadCard = readCard || null; lastReadSnapshot = readCard ? readSnapshot(readCard) : ''; status.textContent = text; if (voice !== false) speak(text); }
        function cards() {
            return Array.from(root.querySelectorAll('.hud-card[data-hud-key]')).filter(function (card) {
                var style = window.getComputedStyle(card);
                return card.isConnected && !card.hidden && !card.closest('[hidden]') && style.display !== 'none' && style.visibility !== 'hidden' && card.getClientRects().length > 0 && Object.prototype.hasOwnProperty.call(aliases, card.dataset.hudKey);
            });
        }
        function targetCards(term) {
            return cards().filter(function (card) {
                var names = aliases[card.dataset.hudKey].concat(clean((card.querySelector('.hud-card-label') || {}).textContent));
                return names.some(function (name) { return normalize(name) === term; });
            });
        }
        function readAvailable(card) {
            var values = card.querySelector('.hud-card-values');
            return root.contains(card) && cards().indexOf(card) !== -1 && !!values && !!clean(values.textContent) && !/[—–]/.test(values.textContent)
                && !card.classList.contains('unavailable') && card.dataset.balanceState !== 'loading' && card.getAttribute('aria-busy') !== 'true';
        }
        function expireRead() {
            if (!lastReadCard || (readAvailable(lastReadCard) && readSnapshot(lastReadCard) === lastReadSnapshot)) return false;
            // Authentication/scope refreshes can remove a card without replacing the HUD.
            // Drop both its private readout and a queued voice before it can speak later.
            lastReadCard = null; lastReadSnapshot = ''; stopSpeech();
            status.textContent = 'الملخص ده لم يعد متاحًا حاليًا. جرّب بعد تحديث الأقسام.';
            return true;
        }
        function allowed(card) {
            if (card.tagName !== 'A' || card.hasAttribute('download')) return false;
            var href = card.getAttribute('href'); if (!href) return false;
            try { var url = new URL(href, window.location.href); return /^https?:$/.test(url.protocol) && url.origin === window.location.origin && (!card.target || card.target === '_self'); } catch (_) { return false; }
        }
        function help() { reply('جرّب: افتح العملاء، أو عدد العملاء كام، أو رصيد الفروع كام. أقدر أتعامل مع الأقسام الظاهرة لحسابك فقط.'); }
        function execute(text) {
            if (!active()) return false;
            lastReadCard = null; lastReadSnapshot = ''; stopMic(); stopSpeech(); duck();
            var raw = clean(text);
            var command = normalize(raw);
            // Fixed grammar: no arbitrary URLs, fuzzy matches, or inferred mutations.
            command = command.replace(/^(يا (جو|جيو|go) |لو سمحت |من فضلك |ممكن )+/, '').replace(/ (لو سمحت|من فضلك)$/, '');
            if (!command || raw.length > 120) { help(); return false; }
            if (/^(اسكت|وقف|توقف|وقف الصوت|اوقف الصوت|ايقاف الصوت|الغاء|الغاء الامر)$/.test(command)) { stop(); reply('تمام، وقفت الصوت والميكروفون.', false); return true; }
            if (/^(مساعده|الاوامر|الاوامر المتاحه|تقدر تعمل ايه)$/.test(command)) { help(); return true; }
            if (/^(افتح الرئيسيه|افتح الصفحه الرئيسيه|الرئيسيه|الدخول للرئيسيه|ادخل للرئيسيه)$/.test(command)) {
                var enter = root.querySelector('[data-hud-enter]'); if (!enter || enter.disabled || enter.hidden) { reply('الدخول للرئيسية غير متاح الآن.'); return false; }
                reply('تمام، بفتح الرئيسية.', false); enter.click(); return true;
            }
            var intent = '', term = '', match = command.match(/^(?:افتح(?: لي)?|روح ل|روح|اذهب الي|وديني ل|وديني|اعرض صفحه) (.+)$/);
            if (match) { intent = 'open'; term = match[1]; }
            else {
                match = command.match(/^(?:اقرا|اقرالي|اقرا لي|قولي|قل لي|اعرض|كام|كم) (.+)$/);
                if (match) { intent = 'read'; term = match[1]; }
                else { match = command.match(/^(.+?) (?:كام|كم|قد ايه)$/); if (match) { intent = 'read'; term = match[1]; } }
            }
            if (!intent) { help(); return false; }
            // A redundant question suffix is accepted, but compound requests are not.
            term = term.replace(/ (?:كام|كم|قد ايه)$/, '');
            if (intent === 'open') term = term.replace(/^(?:صفحه|قسم) /, '');
            else term = term.replace(/ عندي$/, '');
            var matches = targetCards(term);
            if (matches.length !== 1) { reply(matches.length ? 'الأمر يحتمل أكثر من قسم. قول اسم القسم كما يظهر على الكارت.' : 'القسم أو الأمر ده مش متاح ضمن الأقسام الظاهرة لحسابك.'); return false; }
            var card = matches[0], label = clean((card.querySelector('.hud-card-label') || {}).textContent);
            if (intent === 'open') {
                if (!allowed(card)) { reply('القسم ده غير متاح للفتح من هنا.'); return false; }
                reply('تمام، بفتح ' + label + '.', false);
                // Use the same DOM action as a manual click: preserves SPA and Owner drawer handlers.
                card.click(); return true;
            }
            var valueRoot = card.querySelector('.hud-card-values');
            var values = valueRoot ? Array.from(valueRoot.children).map(function (part) {
                return Array.from(part.childNodes).map(function (child) { return clean(child.textContent); }).filter(Boolean).join(' ');
            }).join('، ') : '';
            if (!values || /[—–]/.test(values) || card.classList.contains('unavailable') || card.dataset.balanceState === 'loading' || card.getAttribute('aria-busy') === 'true') {
                reply('قيمة ' + label + ' غير جاهزة حاليًا. استنى التحديث وجرّب تاني.'); return false;
            }
            var stale = !!root.querySelector('.hud-live.stale') || card.dataset.balanceState === 'stale';
            var scope = clean((root.querySelector('[data-hud-scope]') || {}).textContent);
            var message = label + ': ' + values + (scope ? '. النطاق: ' + scope : '') + (stale ? '. دي آخر أرقام متاحة؛ تعذّر تحديثها.' : '. حسب الملخص الظاهر حاليًا.');
            reply(message, undefined, card); return true;
        }
        function startMic() {
            if (!active() || !Recognition) return;
            clearHeard();
            if (listening || recognition) { stopMic(); reply('تم إيقاف الميكروفون.', false); return; }
            lastReadCard = null; lastReadSnapshot = ''; stopSpeech(); duck();
            var session = ++epoch, rec;
            try { rec = new Recognition(); } catch (_) { reply('تعذّر تشغيل الميكروفون. اضغط اتكلم وحاول تاني.', false); return; }
            recognition = rec; rec.lang = 'ar-EG'; rec.continuous = false; rec.interimResults = false; rec.maxAlternatives = 1;
            mic.setAttribute('aria-pressed', 'true'); mic.textContent = 'إيقاف الاستماع'; panel.classList.add('listening');
            status.textContent = 'جار طلب الميكروفون…';
            rec.onstart = function () {
                if (session !== epoch) return;
                if (!active()) { stopMic(); return; }
                listening = true; status.textContent = 'سامعك… قول أمر واحد.';
                clearTimeout(micTimer);
                micTimer = window.setTimeout(function () { if (session !== epoch) return; stopMic(); reply('انتهى وقت الاستماع. اضغط اتكلم من جديد.', false); }, 15000);
            };
            rec.onresult = function (event) {
                if (session !== epoch || !active()) return;
                var result = event.results && event.results[event.resultIndex || 0];
                if (!result || !result.isFinal || !result[0] || typeof result[0].transcript !== 'string') return;
                var phrase = clean(result[0].transcript);
                heard.textContent = 'سمعت: ' + phrase.slice(0, 120) + (phrase.length > 120 ? '…' : ''); heard.hidden = false;
                execute(result[0].transcript);
            };
            rec.onerror = function (event) {
                if (session !== epoch) return;
                var code = event.error; stopMic();
                var message = code === 'not-allowed' || code === 'service-not-allowed' ? 'الميكروفون غير مسموح. اسمح باستخدامه لهذا الموقع من علامة الميكروفون جنب العنوان واضغط اتكلم.' : code === 'network' ? 'تعذّر الاتصال بخدمة التعرّف الصوتي. تحقق من الاتصال وحاول لاحقًا.' : code === 'audio-capture' ? 'مش لاقي ميكروفون متاح. وصّله وتأكد إنه مشغّل واضغط اتكلم.' : code === 'no-speech' ? 'ما سمعتش أمر واضح. اضغط اتكلم وقول الأمر تاني.' : 'التعرّف الصوتي غير متاح الآن. حاول لاحقًا.';
                reply(message, false);
            };
            rec.onnomatch = function () { if (session !== epoch) return; stopMic(); reply('ما فهمتش الأمر. اضغط اتكلم وقول الأمر تاني.', false); };
            rec.onend = function () { if (session !== epoch) return; stopMic(); reply('انتهى الاستماع. تقدر تضغط اتكلم وتحاول تاني.', false); };
            // Permission/startup waiting must not consume the speaking window.
            micTimer = window.setTimeout(function () { if (session !== epoch) return; stopMic(); reply('طلب الميكروفون ما اكتملش. اضغط اتكلم واختار سماح لو ظهر طلب الإذن.', false); }, 60000);
            try { rec.start(); } catch (_) { stopMic(); reply('تعذّر تشغيل الميكروفون. اضغط اتكلم وحاول تاني.', false); }
        }
        function setOpen(open) {
            if (dead) return; panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open));
            if (open) (mic.disabled ? close : mic).focus({preventScroll: true}); else { clearHeard(); stop(); if (active()) toggle.focus({preventScroll: true}); }
        }
        on(toggle, 'click', function () { var open = panel.hidden; setOpen(open); if (open) startMic(); }); on(close, 'click', function () { setOpen(false); });
        on(mic, 'click', startMic); on(stopButton, 'click', function () { duck(); stop(); reply('تم إيقاف الصوت والميكروفون.', false); });
        on(replay, 'click', function () { if (replay.goPlayReply) replay.goPlayReply(); });
        on(panel, 'keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setOpen(false); } });
        on(document, 'visibilitychange', function () { if (document.hidden) { clearHeard(); stop(); } });
        on(window, 'pagehide', destroy);
        on(document, 'dashboard:before-unload', destroy);
        if (window.MutationObserver) {
            observer = new MutationObserver(function () {
                if (!root.isConnected || (document.body.dataset.dashboardActor || '') !== actor) { destroy(); return; }
                expireRead();
                if (!active()) clearHeard();
                if (!active() && (recognition || utterance || listening || voiceWaitTimer || voiceRequest || replyAudio)) stop();
            });
            observer.observe(root, {attributes: true, attributeFilter: ['hidden', 'class', 'style', 'data-balance-state', 'aria-busy'], childList: true, subtree: true}); observer.observe(document.body, {attributes: true, attributeFilter: ['data-dashboard-actor'], childList: true, subtree: true});
        }
        function destroy() {
            if (dead) return; dead = true; lastReadCard = null; lastReadSnapshot = ''; clearHeard(); stop(); if (observer) observer.disconnect(); remove.forEach(function (fn) { fn(); }); toggle.remove(); panel.remove(); delete root.goHudCommands;
        }
        var api = {destroy: destroy, execute: execute, stop: stop}; root.goHudCommands = api; return api;
    }
    window.GoHudCommands = {mount: mount, version: 1};
})(window, document);
