(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var moduleVersion = '20261020-reply-mode-2';
    var previousModule = window.DashboardMetaAiControls;
    if (previousModule && previousModule.version === moduleVersion && typeof previousModule.mount === 'function') {
        previousModule.mount();
        return;
    }
    if (previousModule && typeof previousModule.destroy === 'function') previousModule.destroy();

    var active = null;
    var channels = ['whatsapp', 'messenger', 'instagram'];
    var words = {
        ar: {enable:'تشغيل تحليل وإرسال الطلبات', enable_replies:'تشغيل الرد والتحليل وإرسال الطلبات', disable:'إيقاف تحليل وإرسال الطلبات', disable_legacy:'إيقاف الرد والتحليل وإرسال الطلبات', loading:'جارٍ تحميل حالة المعالجة',
            mode_unsaved:'اختيار الوضع لم يُحفظ بعد. الوضع المحفوظ هو الذي يعمل حاليًا.',
            mode_analysis_help:'الداشبورد يحلل المحادثة ويرسل الطلب المكتمل بعد تأكيد العميل، ولا يرسل ردودًا تلقائية. الرد يكون من الموظف أو أي مساعد خارجي مفعّل لديك.',
            mode_reply_help:'الداشبورد يرد على الرسائل ويجمع بيانات الطلب، مع مراجعة الأصناف والأسعار وموافقة العميل قبل التنفيذ. أوقف أي مساعد خارجي يرد على القنوات نفسها قبل التفعيل.',
            pause_required:'أوقف المساعد الخارجي على القنوات المحددة، ثم ضع علامة التأكيد أدناه لتفعيل ردود الداشبورد. لم يتغير الوضع المحفوظ.',
            reply_loading:'جارٍ تحميل حالة الردود', reply_unavailable:'حالة الردود غير متاحة حاليًا',
            reply_DISABLED:'الردود التلقائية متوقفة', reply_ACTIVE:'الردود التلقائية مفعّلة حسب الجدول وجاهزية القنوات', reply_SCHEDULED_OFF:'الردود التلقائية خارج مواعيد الرد', reply_ANALYZE_ONLY:'الوضع المحفوظ: تحليل فقط، بدون ردود تلقائية من الداشبورد',
            analysis_loading:'جارٍ تحميل حالة تحليل الرسائل', analysis_unavailable:'حالة تحليل الرسائل غير متاحة حاليًا',
            analysis_DISABLED:'تحليل الرسائل متوقف', analysis_ACTIVE:'تحليل الرسائل مفعّل حسب الجدول', analysis_SCHEDULED_OFF:'تحليل الرسائل خارج مواعيد التحليل',
            replies_pending:'توجد رسالة AI جارٍ إرسالها أو لم تتأكد نتيجتها بعد. انتظر تأكيد حالتها ثم حدّث الحالة وأعد حفظ الوضع. لا تُعد إرسال الرسالة.',
            save:'حفظ الإعدادات', saving:'جارٍ حفظ الإعدادات…',
            dispatch_loading:'جارٍ تحميل حالة إرسال الطلبات', dispatch_unavailable:'حالة إرسال الطلبات غير متاحة حاليًا',
            dispatch_DISABLED:'إرسال الطلبات متوقف', dispatch_ACTIVE:'إرسال الطلبات مفعّل حسب الجدول', dispatch_SCHEDULED_OFF:'إرسال الطلبات خارج مواعيد الإرسال',
            DISABLED:'معالجة الرسائل والطلبات متوقفة', ACTIVE:'معالجة الرسائل والطلبات مفعّلة حسب الجدول', SCHEDULED_OFF:'معالجة الرسائل والطلبات خارج المواعيد', unavailable:'إعدادات AI غير متاحة حاليًا',
            failed:'تعذّر تحميل إعدادات AI. حدّث الحالة للمحاولة مرة أخرى.', save_failed:'تعذّر تأكيد الحفظ. حدّث الحالة قبل أي محاولة جديدة.',
            saved:'تم حفظ الإعدادات.', conflict:'تغيّرت الإعدادات من جلسة أخرى. حمّلنا أحدث نسخة؛ راجعها قبل الحفظ مجددًا.',
            denied:'عرض فقط؛ تعديل الإعدادات متاح للمالك.', invalid:'راجع القنوات والأيام والمواعيد قبل الحفظ.',
            no_channels:'اختر قناة واحدة على الأقل واحفظ الإعدادات أولًا.', current:'الوقت في القاهرة: ',
            whatsapp:'واتساب', messenger:'ماسنجر', instagram:'إنستجرام', ready:'مسموح بالمعالجة حسب الإعدادات',
            ACTOR_UNAVAILABLE:'حساب المعالجة غير متاح', ACCOUNT_UNAVAILABLE:'ربط القناة غير متاح', CAPTURE_UNAVAILABLE:'استقبال الرسائل غير متاح',
            ACTIVATION_REQUIRED:'القناة تحتاج إلى تفعيل', EXTERNAL_AGENT_PAUSE_REQUIRED:'يلزم إيقاف المساعد الخارجي', STATE_CHANGED:'حالة القناة تغيّرت؛ حدّث الحالة', UNAVAILABLE:'الجاهزية غير متاحة'},
        en: {enable:'Enable analysis and order dispatch', enable_replies:'Enable replies, analysis and order dispatch', disable:'Disable analysis and order dispatch', disable_legacy:'Disable replies, analysis and order dispatch', loading:'Loading processing status',
            mode_unsaved:'The selected mode has not been saved. The saved mode is still in effect.',
            mode_analysis_help:'The dashboard analyses conversations and sends complete orders after customer approval; it does not send automatic replies. Replies come from staff or any external assistant you have enabled.',
            mode_reply_help:'The dashboard replies and collects order details, with product and price checks and customer approval before execution. Pause any external assistant replying on the same channels before activation.',
            pause_required:'Pause the external assistant on the selected channels, then check the confirmation below to enable dashboard replies. The saved mode has not changed.',
            reply_loading:'Loading reply status', reply_unavailable:'Reply status is currently unavailable',
            reply_DISABLED:'Automatic replies are off', reply_ACTIVE:'Automatic replies are enabled subject to hours and channel readiness', reply_SCHEDULED_OFF:'Automatic replies are outside reply hours', reply_ANALYZE_ONLY:'Saved mode: analysis only, without automatic dashboard replies',
            analysis_loading:'Loading message analysis status', analysis_unavailable:'Message analysis status is currently unavailable',
            analysis_DISABLED:'Message analysis is off', analysis_ACTIVE:'Message analysis is enabled for these hours', analysis_SCHEDULED_OFF:'Message analysis is outside analysis hours',
            replies_pending:'An AI message is being sent or its result is still uncertain. Wait for its status to be confirmed, refresh the status, then save the mode again. Do not resend the message.',
            save:'Save settings', saving:'Saving settings…',
            dispatch_loading:'Loading order dispatch status', dispatch_unavailable:'Order dispatch status is currently unavailable',
            dispatch_DISABLED:'Order dispatch is off', dispatch_ACTIVE:'Order dispatch is enabled for these hours', dispatch_SCHEDULED_OFF:'Order dispatch is outside dispatch hours',
            DISABLED:'Message and order processing is off', ACTIVE:'Message and order processing is enabled for these hours', SCHEDULED_OFF:'Message and order processing is outside its hours', unavailable:'AI settings are currently unavailable',
            failed:'AI settings could not be loaded. Refresh the status to try again.', save_failed:'The save could not be confirmed. Refresh the status before another attempt.',
            saved:'Settings saved.', conflict:'Settings changed in another session. The latest version has been loaded; review it before saving again.',
            denied:'Read only; only the owner can change these settings.', invalid:'Check the selected channels, days and hours before saving.',
            no_channels:'Select at least one channel and save the settings first.', current:'Cairo time: ',
            whatsapp:'WhatsApp', messenger:'Messenger', instagram:'Instagram', ready:'Processing allowed by settings',
            ACTOR_UNAVAILABLE:'Processing account unavailable', ACCOUNT_UNAVAILABLE:'Channel connection unavailable', CAPTURE_UNAVAILABLE:'Message capture unavailable',
            ACTIVATION_REQUIRED:'Channel activation required', EXTERNAL_AGENT_PAUSE_REQUIRED:'External assistant must be paused', STATE_CHANGED:'Channel state changed; refresh status', UNAVAILABLE:'Readiness unavailable'}
    };

    function exact(value, keys) {
        return value !== null && typeof value === 'object' && !Array.isArray(value)
            && Object.keys(value).length === keys.length && Object.keys(value).every(function (key) { return keys.indexOf(key) !== -1; });
    }
    function validTime(value) { return typeof value === 'string' && /^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(value) && value.length === 5; }
    function validSchedule(schedule) {
        return exact(schedule, ['enabled','days','start','end','all_day']) && typeof schedule.enabled === 'boolean'
            && typeof schedule.all_day === 'boolean' && Array.isArray(schedule.days) && schedule.days.length <= 7
            && !schedule.days.some(function (day, i) { return !Number.isInteger(day) || day < 0 || day > 6 || schedule.days.indexOf(day) !== i; })
            && (!schedule.enabled || schedule.days.length > 0) && validTime(schedule.start) && validTime(schedule.end)
            && (schedule.all_day || schedule.start !== schedule.end);
    }
    function validPolicy(policy) {
        return exact(policy, ['enabled','channels','schedule','dispatch_schedule','mode']) && typeof policy.enabled === 'boolean'
            && ['REPLY_AND_ANALYZE','ANALYZE_ONLY'].indexOf(policy.mode) !== -1
            && Array.isArray(policy.channels) && policy.channels.length <= 3
            && !policy.channels.some(function (channel, i) { return channels.indexOf(channel) === -1 || policy.channels.indexOf(channel) !== i; })
            && (!policy.enabled || policy.channels.length > 0)
            && validSchedule(policy.schedule) && validSchedule(policy.dispatch_schedule);
    }
    function normalizePolicy(policy) {
        if (!exact(policy, ['enabled','channels','schedule']) && !exact(policy, ['enabled','channels','schedule','dispatch_schedule'])) return policy;
        var normalized = clone(policy);
        if (!Object.prototype.hasOwnProperty.call(normalized, 'dispatch_schedule'))
            normalized.dispatch_schedule = {enabled:true, days:[0,1,2,3,4,5,6], start:'09:00', end:'00:30', all_day:false};
        normalized.mode = 'REPLY_AND_ANALYZE';
        return normalized;
    }
    function validState(value, legacyPolicy, legacyDispatch) {
        if (!(value && value.available === true && validPolicy(value.policy)
            && Number.isSafeInteger(value.revision) && value.revision >= 0 && typeof value.can_manage === 'boolean'
            && ['DISABLED','ACTIVE','SCHEDULED_OFF'].indexOf(value.status) !== -1
            && typeof value.effective === 'boolean' && value.effective === (value.status === 'ACTIVE')
            && (value.policy.enabled || value.status === 'DISABLED')
            && typeof value.local_time === 'string' && value.local_time.length <= 40
            && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+(?:02|03):00$/.test(value.local_time))) return false;
        if (Object.prototype.hasOwnProperty.call(value, 'handoff_method')
            && value.handoff_method !== null && ['OWNER_ATTESTED','VERIFIED_WHATSAPP_API'].indexOf(value.handoff_method) === -1) return false;
        if (Object.prototype.hasOwnProperty.call(value, 'handoff_required') && typeof value.handoff_required !== 'boolean') return false;
        if (!Object.prototype.hasOwnProperty.call(value, 'reply_state') && !legacyPolicy) return false;
        if (Object.prototype.hasOwnProperty.call(value, 'reply_state')) {
            var reply = value.reply_state;
            if (!exact(reply, ['effective','status','local_time']) || typeof reply.effective !== 'boolean'
                || ['DISABLED','ACTIVE','SCHEDULED_OFF','ANALYZE_ONLY'].indexOf(reply.status) === -1
                || reply.effective !== (reply.status === 'ACTIVE') || (!value.policy.enabled && reply.status !== 'DISABLED')
                || (reply.effective && !value.effective)
                || (value.policy.enabled && value.policy.mode === 'ANALYZE_ONLY' && reply.status !== 'ANALYZE_ONLY')
                || (value.policy.mode === 'REPLY_AND_ANALYZE' && reply.status === 'ANALYZE_ONLY')
                || typeof reply.local_time !== 'string' || reply.local_time.length > 40
                || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+(?:02|03):00$/.test(reply.local_time)) return false;
        }
        if (!Object.prototype.hasOwnProperty.call(value, 'analysis_state') && !legacyPolicy) return false;
        if (Object.prototype.hasOwnProperty.call(value, 'analysis_state')) {
            var analysis = value.analysis_state;
            if (!exact(analysis, ['effective','status','local_time']) || typeof analysis.effective !== 'boolean'
                || ['DISABLED','ACTIVE','SCHEDULED_OFF'].indexOf(analysis.status) === -1
                || analysis.effective !== (analysis.status === 'ACTIVE') || (!value.policy.enabled && analysis.status !== 'DISABLED')
                || (analysis.effective && !value.effective)
                || typeof analysis.local_time !== 'string' || analysis.local_time.length > 40
                || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+(?:02|03):00$/.test(analysis.local_time)) return false;
        }
        if (!Object.prototype.hasOwnProperty.call(value, 'dispatch_state') && !legacyDispatch) return false;
        if (Object.prototype.hasOwnProperty.call(value, 'dispatch_state')) {
            var dispatch = value.dispatch_state;
            if (!exact(dispatch, ['effective','status','local_time']) || typeof dispatch.effective !== 'boolean'
                || ['DISABLED','ACTIVE','SCHEDULED_OFF'].indexOf(dispatch.status) === -1
                || dispatch.effective !== (dispatch.status === 'ACTIVE') || (!value.policy.enabled && dispatch.status !== 'DISABLED')
                || (value.policy.mode === 'ANALYZE_ONLY' && dispatch.effective && !value.effective)
                || typeof dispatch.local_time !== 'string' || dispatch.local_time.length > 40
                || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+(?:02|03):00$/.test(dispatch.local_time)) return false;
        }
        if (value.policy.mode === 'ANALYZE_ONLY' && value.analysis_state && value.dispatch_state
            && value.effective !== (value.analysis_state.effective || value.dispatch_state.effective)) return false;
        if (!Object.prototype.hasOwnProperty.call(value, 'channels_state')) return true;
        var seen = [];
        return Array.isArray(value.channels_state) && value.channels_state.length <= 3 && value.channels_state.every(function (row) {
            if (!exact(row, ['channel','effective','reason']) || value.policy.channels.indexOf(row.channel) === -1
                || seen.indexOf(row.channel) !== -1 || typeof row.effective !== 'boolean'
                || (row.effective && !value.effective) || (row.reason !== null && (typeof row.reason !== 'string' || row.reason.length > 64))) return false;
            seen.push(row.channel); return true;
        });
    }
    function clone(value) { return JSON.parse(JSON.stringify(value)); }
    function endpoint(value) {
        if (typeof value !== 'string') return null;
        try {
            var url = new URL(value, window.location.href);
            return url.origin === new URL(window.location.href).origin && !url.username && !url.password
                && !url.search && !url.hash && url.pathname.replace(/\/$/, '') === '/admin/meta/ai-control' ? url.href : null;
        } catch (error) { return null; }
    }

    function destroy() {
        if (!active) return;
        active.dead = true;
        if (active.timer !== null) window.clearInterval(active.timer);
        active.listeners.forEach(function (binding) { binding[0].removeEventListener(binding[1], binding[2]); });
        if (active.modeCleanup) active.modeCleanup();
        active = null;
    }

    function mount() {
        if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
        var panel = document.getElementById('meta-ai-controls');
        var bootstrap = document.getElementById('meta-ai-controls-bootstrap');
        if (!panel || !bootstrap) { destroy(); return; }
        if (active && active.panel === panel) return;
        destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (error) { return; }
        var readUrl = endpoint(config.state_url), updateUrl = endpoint(config.update_url);
        if (!readUrl || !updateUrl || typeof config.csrf !== 'string' || !config.csrf || config.csrf.length > 512) return;
        var labels = words[config.lang === 'ar' ? 'ar' : 'en'];
        var toggle = panel.querySelector('[data-meta-ai-toggle]'), save = panel.querySelector('[data-meta-ai-save]');
        var refresh = panel.querySelector('[data-meta-ai-refresh]'), form = panel.querySelector('[data-meta-ai-form]');
        var status = panel.querySelector('[data-meta-ai-status]'), message = panel.querySelector('[data-meta-ai-message]');
        var dispatchStatus = panel.querySelector('[data-meta-ai-dispatch-status]');
        var analysisStatus = panel.querySelector('[data-meta-ai-analysis-status]');
        var replyStatus = panel.querySelector('[data-meta-ai-reply-status]');
        var mode = panel.querySelector('[data-meta-ai-mode]'), modeHelp = panel.querySelector('[data-meta-ai-mode-help]');
        var handoffSettings = panel.querySelector('[data-meta-ai-handoff-settings]');
        var pauseAttestation = panel.querySelector('[data-meta-ai-external-paused]');
        var compatibilityNote = panel.querySelector('[data-meta-ai-compatibility-note]');
        var settings = panel.querySelector('[data-meta-ai-settings]'), currentTime = panel.querySelector('[data-meta-ai-current-time]');
        var channelStatus = panel.querySelector('[data-meta-ai-channel-status]');
        var scheduleEnabled = panel.querySelector('[data-meta-ai-schedule]'), allDay = panel.querySelector('[data-meta-ai-all-day]');
        var start = panel.querySelector('[data-meta-ai-start]'), end = panel.querySelector('[data-meta-ai-end]');
        var dispatchEnabled = panel.querySelector('[data-meta-ai-dispatch-schedule]'), dispatchAllDay = panel.querySelector('[data-meta-ai-dispatch-all-day]');
        var dispatchStart = panel.querySelector('[data-meta-ai-dispatch-start]'), dispatchEnd = panel.querySelector('[data-meta-ai-dispatch-end]');
        var channelInputs = Array.prototype.slice.call(panel.querySelectorAll('[data-meta-ai-channel]'));
        var dayInputs = Array.prototype.slice.call(panel.querySelectorAll('[data-meta-ai-day]'));
        var dispatchDayInputs = Array.prototype.slice.call(panel.querySelectorAll('[data-meta-ai-dispatch-day]'));
        if (!toggle || !save || !refresh || !form || !status || !dispatchStatus || !analysisStatus || !replyStatus || !mode || !modeHelp || !handoffSettings || !pauseAttestation || !compatibilityNote || !message || !settings || !currentTime || !channelStatus
            || !scheduleEnabled || !allDay || !start || !end || !dispatchEnabled || !dispatchAllDay
            || !dispatchStart || !dispatchEnd || channelInputs.length !== 3 || dayInputs.length !== 7 || dispatchDayInputs.length !== 7) return;
        var run = {panel:panel, dead:false, busy:false, saving:false, dirty:false, saved:null, listeners:[], timer:null, forceAttestation:false};
        active = run;

        function alive() { return !run.dead && active === run && document.getElementById('meta-ai-controls') === panel; }
        function showMessage(text) { message.textContent = text || ''; message.hidden = !text; }
        function sameChannels(left, right) {
            return left.length === right.length && left.every(function (channel) { return right.indexOf(channel) !== -1; });
        }
        function needsHandoff(policy) {
            if (!run.saved || !policy.enabled || policy.mode !== 'REPLY_AND_ANALYZE') return false;
            var saved = run.saved;
            return !saved.policy.enabled || saved.policy.mode !== 'REPLY_AND_ANALYZE'
                || saved.handoff_required === true || !saved.handoff_method
                || !sameChannels(policy.channels, saved.policy.channels);
        }
        function needsAttestation(policy) {
            return policy.enabled && policy.mode === 'REPLY_AND_ANALYZE'
                && (run.forceAttestation || (needsHandoff(policy) && policy.channels.some(function (channel) { return channel !== 'whatsapp'; })));
        }
        function resetAttestation() {
            pauseAttestation.checked = false;
            run.forceAttestation = false;
        }
        function controls() {
            var editable = !run.busy && run.saved && run.saved.can_manage;
            toggle.disabled = !editable; save.disabled = !editable;
            if (run.saved) {
                toggle.textContent = labels[run.saved.policy.enabled ? (run.saved.policy.mode === 'ANALYZE_ONLY' ? 'disable' : 'disable_legacy')
                    : (mode.value === 'REPLY_AND_ANALYZE' ? 'enable_replies' : 'enable')];
            }
            mode.disabled = !editable;
            if (window.jQuery) window.jQuery(mode).trigger('change.select2');
            modeHelp.textContent = labels[mode.value === 'REPLY_AND_ANALYZE' ? 'mode_reply_help' : 'mode_analysis_help'];
            var attestationNeeded = !!run.saved && needsAttestation(collectPolicy(true));
            handoffSettings.hidden = !attestationNeeded;
            pauseAttestation.disabled = !editable || !attestationNeeded;
            if (!attestationNeeded) pauseAttestation.checked = false;
            compatibilityNote.textContent = run.saved && mode.value !== run.saved.policy.mode ? labels.mode_unsaved : '';
            compatibilityNote.hidden = !compatibilityNote.textContent;
            save.textContent = labels[run.saving ? 'saving' : 'save'];
            refresh.disabled = run.busy;
            channelInputs.forEach(function (input) { input.disabled = !editable; });
            scheduleEnabled.disabled = !editable;
            dayInputs.forEach(function (input) { input.disabled = !editable || !scheduleEnabled.checked; });
            allDay.disabled = !editable || !scheduleEnabled.checked;
            start.disabled = end.disabled = !editable || !scheduleEnabled.checked || allDay.checked;
            dispatchEnabled.disabled = !editable;
            dispatchDayInputs.forEach(function (input) { input.disabled = !editable || !dispatchEnabled.checked; });
            dispatchAllDay.disabled = !editable || !dispatchEnabled.checked;
            dispatchStart.disabled = dispatchEnd.disabled = !editable || !dispatchEnabled.checked || dispatchAllDay.checked;
        }
        function render(value) {
            run.saved = clone(value);
            run.dirty = false;
            resetAttestation();
            mode.value = value.policy.mode;
            panel.hidden = false; settings.hidden = false; panel.dataset.status = value.status;
            var analysisOnly = value.policy.mode === 'ANALYZE_ONLY';
            status.textContent = labels[value.status];
            analysisStatus.textContent = value.analysis_state ? labels['analysis_' + value.analysis_state.status] : labels.analysis_unavailable;
            replyStatus.textContent = value.reply_state ? labels['reply_' + value.reply_state.status] : labels.reply_unavailable;
            dispatchStatus.textContent = value.dispatch_state ? labels['dispatch_' + value.dispatch_state.status] : labels.dispatch_unavailable;
            toggle.textContent = labels[value.policy.enabled ? (analysisOnly ? 'disable' : 'disable_legacy') : 'enable'];
            toggle.setAttribute('aria-pressed', value.policy.enabled ? 'true' : 'false');
            channelInputs.forEach(function (input) { input.checked = value.policy.channels.indexOf(input.value) !== -1; });
            var schedule = value.policy.schedule;
            scheduleEnabled.checked = schedule.enabled; allDay.checked = schedule.all_day;
            start.value = schedule.start; end.value = schedule.end;
            dayInputs.forEach(function (input) { input.checked = schedule.days.indexOf(Number(input.value)) !== -1; });
            var dispatchSchedule = value.policy.dispatch_schedule;
            dispatchEnabled.checked = dispatchSchedule.enabled; dispatchAllDay.checked = dispatchSchedule.all_day;
            dispatchStart.value = dispatchSchedule.start; dispatchEnd.value = dispatchSchedule.end;
            dispatchDayInputs.forEach(function (input) { input.checked = dispatchSchedule.days.indexOf(Number(input.value)) !== -1; });
            currentTime.textContent = labels.current + value.local_time.slice(0, 16).replace('T', ' ');
            channelStatus.textContent = '';
            value.policy.channels.forEach(function (channel) {
                var row = (value.channels_state || []).filter(function (entry) { return entry.channel === channel; })[0];
                var reason = row && row.reason;
                var allowedReasons = ['ACTOR_UNAVAILABLE','ACCOUNT_UNAVAILABLE','CAPTURE_UNAVAILABLE','ACTIVATION_REQUIRED','EXTERNAL_AGENT_PAUSE_REQUIRED','STATE_CHANGED','UNAVAILABLE','DISABLED','SCHEDULED_OFF'];
                var text = row && row.effective ? labels.ready
                    : (allowedReasons.indexOf(reason) !== -1 ? labels[reason]
                        : (!value.policy.enabled ? labels.DISABLED : (value.status === 'SCHEDULED_OFF' ? labels.SCHEDULED_OFF : labels.UNAVAILABLE)));
                var item = document.createElement('li'); item.textContent = labels[channel] + ': ' + text; channelStatus.appendChild(item);
            });
            controls();
        }
        function unavailable(text) {
            run.saved = null; panel.hidden = false; settings.hidden = true;
            resetAttestation();
            panel.dataset.status = 'DISABLED'; status.textContent = labels.unavailable;
            dispatchStatus.textContent = labels.dispatch_unavailable;
            analysisStatus.textContent = labels.analysis_unavailable;
            replyStatus.textContent = labels.reply_unavailable;
            toggle.textContent = labels.enable; toggle.setAttribute('aria-pressed', 'false');
            currentTime.textContent = ''; showMessage(text); controls();
            channelStatus.textContent = ''; compatibilityNote.textContent = ''; compatibilityNote.hidden = true;
        }
        async function request(url, body) {
            var options = {credentials:'same-origin', cache:'no-store', redirect:'error', headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}};
            if (body !== undefined) {
                options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-TOKEN'] = config.csrf;
                options.body = JSON.stringify(body);
            }
            var response = await fetch(url, options);
            if (!alive()) throw new Error('STALE');
            if (response.redirected) throw new Error('REDIRECT');
            if (!response.ok) {
                var error = new Error('HTTP'); error.status = response.status;
                if (response.status === 422 || response.status === 409) {
                    try {
                        var failure = await response.json();
                        error.repliesPending = failure && (failure.reason === 'REPLIES_PENDING' || failure.code === 'REPLIES_PENDING' || failure.error === 'REPLIES_PENDING');
                        error.pauseRequired = failure && failure.error === 'EXTERNAL_AGENT_PAUSE_REQUIRED';
                    } catch (ignored) { /* Only a known reason may be shown. */ }
                }
                throw error;
            }
            var contentType = response.headers && response.headers.get('content-type');
            if (!contentType || contentType.toLowerCase().indexOf('application/json') === -1) throw new Error('RESPONSE');
            var value = await response.json();
            if (!alive()) throw new Error('STALE');
            if (!value || typeof value.available !== 'boolean') throw new Error('RESPONSE');
            if (!value.available) return null;
            var legacyDispatch = exact(value.policy, ['enabled','channels','schedule']);
            var legacyPolicy = legacyDispatch || exact(value.policy, ['enabled','channels','schedule','dispatch_schedule']);
            value.policy = normalizePolicy(value.policy);
            if (!validState(value, legacyPolicy, legacyDispatch)) throw new Error('RESPONSE');
            return value;
        }
        async function load(note) {
            if (run.busy || !alive()) return;
            run.busy = true; panel.hidden = false; controls();
            if (!run.saved) { status.textContent = labels.loading; analysisStatus.textContent = labels.analysis_loading; replyStatus.textContent = labels.reply_loading; dispatchStatus.textContent = labels.dispatch_loading; }
            showMessage('');
            try {
                var value = await request(readUrl);
                if (value === null) unavailable(labels.unavailable);
                else { render(value); showMessage(note || (value.can_manage ? '' : labels.denied)); }
            } catch (error) { if (alive()) unavailable(note ? note + ' ' + labels.failed : labels.failed); }
            finally { if (alive()) { run.busy = false; controls(); } }
        }
        async function update(policy) {
            if (run.busy || !run.saved || !run.saved.can_manage || !alive()) return;
            if (!validPolicy(policy)) { showMessage(labels.invalid); return; }
            if (needsAttestation(policy) && !pauseAttestation.checked) {
                settings.open = true; showMessage(labels.pause_required); controls(); pauseAttestation.focus(); return;
            }
            // Only a fresh owner gesture can supply this statement. Receipts,
            // the prior saved mode and server readiness never check this box.
            var attested = needsAttestation(policy) && pauseAttestation.checked;
            pauseAttestation.checked = false;
            var revision = run.saved.revision;
            run.busy = true; run.saving = true; showMessage(''); controls();
            try {
                var value = await request(updateUrl, {policy:policy, expected_revision:revision, external_agent_paused:attested});
                if (value === null) unavailable(labels.unavailable);
                else { render(value); showMessage(value.can_manage ? labels.saved : labels.denied); }
            } catch (error) {
                if (!alive()) return;
                if (error.status === 422 && error.pauseRequired) {
                    // WhatsApp-only activation may first use the server's
                    // verified pause API. A rejection needs a new owner statement.
                    run.forceAttestation = true; pauseAttestation.checked = false;
                    run.dirty = true; settings.open = true; showMessage(labels.pause_required);
                } else if ((error.status === 409 || error.status === 422) && error.repliesPending) {
                    settings.open = true; showMessage(labels.replies_pending);
                } else if (error.status === 409) {
                    // Reload once to review the newer policy. Never retry the mutation.
                    run.busy = false; run.saving = false;
                    await load(labels.conflict);
                } else { unavailable(labels.save_failed); }
            } finally { if (alive()) { run.busy = false; run.saving = false; controls(); } }
        }
        function bind(element, event, listener) { element.addEventListener(event, listener); run.listeners.push([element,event,listener]); }
        function dirty() { if (run.saved && run.saved.can_manage && !run.busy) run.dirty = true; }
        function backgroundRefresh() {
            if (alive() && !document.hidden && !run.dirty && !run.busy) load();
        }
        function collectPolicy(enabled) {
            return {enabled:enabled, mode:mode.value,
                channels:channelInputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; }),
                schedule:{enabled:scheduleEnabled.checked,
                    days:dayInputs.filter(function (input) { return input.checked; }).map(function (input) { return Number(input.value); }),
                    start:start.value, end:end.value, all_day:allDay.checked},
                dispatch_schedule:{enabled:dispatchEnabled.checked,
                    days:dispatchDayInputs.filter(function (input) { return input.checked; }).map(function (input) { return Number(input.value); }),
                    start:dispatchStart.value, end:dispatchEnd.value, all_day:dispatchAllDay.checked}};
        }
        bind(toggle, 'click', function () {
            if (!run.saved || !run.saved.can_manage || run.busy) return;
            var policy = run.saved.policy.enabled ? clone(run.saved.policy) : collectPolicy(true);
            if (run.saved.policy.enabled) policy.enabled = false;
            if (policy.enabled && !policy.channels.length) { showMessage(labels.no_channels); return; }
            update(policy);
        });
        bind(form, 'submit', function (event) {
            event.preventDefault();
            // This form owns its async state; the legacy page-wide submit spinner cannot track it.
            event.stopImmediatePropagation();
            controls();
            if (!run.saved || !run.saved.can_manage || run.busy) return;
            update(collectPolicy(run.saved.policy.enabled));
        });
        bind(refresh, 'click', function () { load(); });
        function modeChanged() { resetAttestation(); dirty(); controls(); }
        if (window.jQuery) {
            var jqueryMode = window.jQuery(mode);
            jqueryMode.on('change.fasakhanstaMetaAiControls', modeChanged);
            run.modeCleanup = function () { jqueryMode.off('change.fasakhanstaMetaAiControls', modeChanged); };
        } else bind(mode, 'change', modeChanged);
        bind(pauseAttestation, 'change', dirty);
        bind(scheduleEnabled, 'change', function () { dirty(); controls(); });
        bind(allDay, 'change', function () { dirty(); controls(); });
        bind(dispatchEnabled, 'change', function () { dirty(); controls(); });
        bind(dispatchAllDay, 'change', function () { dirty(); controls(); });
        channelInputs.forEach(function (input) { bind(input, 'change', function () { resetAttestation(); dirty(); controls(); }); });
        dayInputs.concat(dispatchDayInputs).forEach(function (input) { bind(input, 'change', dirty); });
        [start,end,dispatchStart,dispatchEnd].forEach(function (input) { bind(input, 'input', dirty); bind(input, 'change', dirty); });
        bind(window, 'focus', backgroundRefresh);
        bind(document, 'visibilitychange', backgroundRefresh);
        run.timer = window.setInterval(backgroundRefresh, 30000);
        if (window.DashboardSPA && typeof window.DashboardSPA.onCleanup === 'function') window.DashboardSPA.onCleanup(function () { if (active === run) destroy(); });
        controls(); load();
    }
    window.DashboardMetaAiControls = {version:moduleVersion, mount:mount, destroy:destroy};
    mount();
}());
