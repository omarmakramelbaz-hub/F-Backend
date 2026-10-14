(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardMetaAiControls) { window.DashboardMetaAiControls.mount(); return; }

    var active = null;
    var channels = ['whatsapp', 'messenger', 'instagram'];
    var words = {
        ar: {enable:'تشغيل ردود وتحليل AI', disable:'إيقاف ردود وتحليل AI', loading:'جارٍ تحميل حالة AI',
            save:'حفظ الإعدادات', saving:'جارٍ حفظ الإعدادات…',
            DISABLED:'AI متوقف', ACTIVE:'AI مفعّل حسب الجدول', SCHEDULED_OFF:'AI خارج مواعيد التشغيل', unavailable:'إعدادات AI غير متاحة حاليًا',
            failed:'تعذّر تحميل إعدادات AI. حدّث الحالة للمحاولة مرة أخرى.', save_failed:'تعذّر تأكيد الحفظ. حدّث الحالة قبل أي محاولة جديدة.',
            saved:'تم حفظ الإعدادات.', conflict:'تغيّرت الإعدادات من جلسة أخرى. حمّلنا أحدث نسخة؛ راجعها قبل الحفظ مجددًا.',
            denied:'عرض فقط؛ تعديل الإعدادات متاح للمالك.', invalid:'راجع القنوات والأيام والمواعيد قبل الحفظ.',
            handoff_enable:'أوقف ردود مساعد ميتا في القنوات المحددة، بما فيها محادثات واتساب الحالية، ثم حدّد الإقرار واضغط تشغيل AI.',
            handoff_save:'أوقف ردود مساعد ميتا في القنوات المحددة، بما فيها محادثات واتساب الحالية، ثم حدّد الإقرار واحفظ الإعدادات.',
            no_channels:'اختر قناة واحدة على الأقل واحفظ الإعدادات أولًا.', current:'الوقت في القاهرة: ',
            whatsapp:'واتساب', messenger:'ماسنجر', instagram:'إنستجرام', ready:'مسموح بالمعالجة حسب الإعدادات',
            ACTOR_UNAVAILABLE:'حساب المعالجة غير متاح', ACCOUNT_UNAVAILABLE:'ربط القناة غير متاح', CAPTURE_UNAVAILABLE:'استقبال الرسائل غير متاح',
            ACTIVATION_REQUIRED:'القناة تحتاج إلى تفعيل', EXTERNAL_AGENT_PAUSE_REQUIRED:'يلزم إيقاف المساعد الخارجي', STATE_CHANGED:'حالة القناة تغيّرت؛ حدّث الحالة', UNAVAILABLE:'الجاهزية غير متاحة',
            OWNER_ATTESTED:'إيقاف المساعد الخارجي مؤكّد بإقرار المالك.', VERIFIED_WHATSAPP_API:'إيقاف مساعد واتساب مؤكّد عبر الاتصال بميتا.'},
        en: {enable:'Enable AI replies and analysis', disable:'Disable AI replies and analysis', loading:'Loading AI status',
            save:'Save settings', saving:'Saving settings…',
            DISABLED:'AI is off', ACTIVE:'AI is enabled for these hours', SCHEDULED_OFF:'AI is outside operating hours', unavailable:'AI settings are currently unavailable',
            failed:'AI settings could not be loaded. Refresh the status to try again.', save_failed:'The save could not be confirmed. Refresh the status before another attempt.',
            saved:'Settings saved.', conflict:'Settings changed in another session. The latest version has been loaded; review it before saving again.',
            denied:'Read only; only the owner can change these settings.', invalid:'Check the selected channels, days and hours before saving.',
            handoff_enable:'Stop Meta assistant replies in the selected channels, including existing WhatsApp conversations, then confirm the checkbox and enable AI.',
            handoff_save:'Stop Meta assistant replies in the selected channels, including existing WhatsApp conversations, then confirm the checkbox and save the settings.',
            no_channels:'Select at least one channel and save the settings first.', current:'Cairo time: ',
            whatsapp:'WhatsApp', messenger:'Messenger', instagram:'Instagram', ready:'Processing allowed by settings',
            ACTOR_UNAVAILABLE:'Processing account unavailable', ACCOUNT_UNAVAILABLE:'Channel connection unavailable', CAPTURE_UNAVAILABLE:'Message capture unavailable',
            ACTIVATION_REQUIRED:'Channel activation required', EXTERNAL_AGENT_PAUSE_REQUIRED:'External assistant must be paused', STATE_CHANGED:'Channel state changed; refresh status', UNAVAILABLE:'Readiness unavailable',
            OWNER_ATTESTED:'External assistant pause confirmed by the owner.', VERIFIED_WHATSAPP_API:'WhatsApp assistant pause verified through Meta.'}
    };

    function exact(value, keys) {
        return value !== null && typeof value === 'object' && !Array.isArray(value)
            && Object.keys(value).length === keys.length && Object.keys(value).every(function (key) { return keys.indexOf(key) !== -1; });
    }
    function validTime(value) { return typeof value === 'string' && /^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(value) && value.length === 5; }
    function validPolicy(policy) {
        if (!exact(policy, ['enabled','channels','schedule']) || typeof policy.enabled !== 'boolean'
            || !Array.isArray(policy.channels) || policy.channels.length > 3
            || policy.channels.some(function (channel, i) { return channels.indexOf(channel) === -1 || policy.channels.indexOf(channel) !== i; })
            || (policy.enabled && policy.channels.length === 0)) return false;
        var schedule = policy.schedule;
        return exact(schedule, ['enabled','days','start','end','all_day']) && typeof schedule.enabled === 'boolean'
            && typeof schedule.all_day === 'boolean' && Array.isArray(schedule.days) && schedule.days.length <= 7
            && !schedule.days.some(function (day, i) { return !Number.isInteger(day) || day < 0 || day > 6 || schedule.days.indexOf(day) !== i; })
            && (!schedule.enabled || schedule.days.length > 0) && validTime(schedule.start) && validTime(schedule.end)
            && (schedule.all_day || schedule.start !== schedule.end);
    }
    function validState(value) {
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
        var settings = panel.querySelector('[data-meta-ai-settings]'), currentTime = panel.querySelector('[data-meta-ai-current-time]');
        var channelStatus = panel.querySelector('[data-meta-ai-channel-status]'), handoffState = panel.querySelector('[data-meta-ai-handoff-state]');
        var scheduleEnabled = panel.querySelector('[data-meta-ai-schedule]'), allDay = panel.querySelector('[data-meta-ai-all-day]');
        var externalPaused = panel.querySelector('[data-meta-ai-external-paused]');
        var start = panel.querySelector('[data-meta-ai-start]'), end = panel.querySelector('[data-meta-ai-end]');
        var channelInputs = Array.prototype.slice.call(panel.querySelectorAll('[data-meta-ai-channel]'));
        var dayInputs = Array.prototype.slice.call(panel.querySelectorAll('[data-meta-ai-day]'));
        if (!toggle || !save || !refresh || !form || !status || !message || !settings || !currentTime || !channelStatus || !handoffState
            || !scheduleEnabled || !allDay || !externalPaused || !start || !end || channelInputs.length !== 3 || dayInputs.length !== 7) return;
        var run = {panel:panel, dead:false, busy:false, saving:false, dirty:false, saved:null, listeners:[], timer:null};
        active = run;

        function alive() { return !run.dead && active === run && document.getElementById('meta-ai-controls') === panel; }
        function showMessage(text) { message.textContent = text || ''; message.hidden = !text; }
        function controls() {
            var editable = !run.busy && run.saved && run.saved.can_manage;
            toggle.disabled = !editable; save.disabled = !editable;
            save.textContent = labels[run.saving ? 'saving' : 'save'];
            refresh.disabled = run.busy;
            channelInputs.forEach(function (input) { input.disabled = !editable; });
            scheduleEnabled.disabled = !editable;
            externalPaused.disabled = !editable;
            dayInputs.forEach(function (input) { input.disabled = !editable || !scheduleEnabled.checked; });
            allDay.disabled = !editable || !scheduleEnabled.checked;
            start.disabled = end.disabled = !editable || !scheduleEnabled.checked || allDay.checked;
        }
        function render(value) {
            run.saved = clone(value);
            run.dirty = false;
            panel.hidden = false; settings.hidden = false; panel.dataset.status = value.status;
            status.textContent = labels[value.status];
            toggle.textContent = labels[value.policy.enabled ? 'disable' : 'enable'];
            toggle.setAttribute('aria-pressed', value.policy.enabled ? 'true' : 'false');
            channelInputs.forEach(function (input) { input.checked = value.policy.channels.indexOf(input.value) !== -1; });
            var schedule = value.policy.schedule;
            scheduleEnabled.checked = schedule.enabled; allDay.checked = schedule.all_day;
            externalPaused.checked = false;
            start.value = schedule.start; end.value = schedule.end;
            dayInputs.forEach(function (input) { input.checked = schedule.days.indexOf(Number(input.value)) !== -1; });
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
            var handoff = value.handoff_required ? labels.EXTERNAL_AGENT_PAUSE_REQUIRED : labels[value.handoff_method];
            handoffState.textContent = handoff || ''; handoffState.hidden = !handoff;
            controls();
        }
        function unavailable(text) {
            run.saved = null; panel.hidden = false; settings.hidden = true;
            panel.dataset.status = 'DISABLED'; status.textContent = labels.unavailable;
            toggle.textContent = labels.enable; toggle.setAttribute('aria-pressed', 'false');
            currentTime.textContent = ''; showMessage(text); controls();
            channelStatus.textContent = ''; handoffState.textContent = ''; handoffState.hidden = true;
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
                if (response.status === 422) {
                    try {
                        var failure = await response.json();
                        error.handoff = failure && (failure.reason === 'HANDOFF_REQUIRED' || failure.code === 'HANDOFF_REQUIRED'
                            || failure.error === 'HANDOFF_REQUIRED' || failure.error === 'EXTERNAL_AGENT_PAUSE_REQUIRED');
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
            if (!validState(value)) throw new Error('RESPONSE');
            return value;
        }
        async function load(note) {
            if (run.busy || !alive()) return;
            run.busy = true; panel.hidden = false; controls();
            if (!run.saved) status.textContent = labels.loading;
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
            var revision = run.saved.revision;
            run.busy = true; run.saving = true; showMessage(''); controls();
            try {
                var value = await request(updateUrl, {policy:policy, expected_revision:revision, external_agent_paused:externalPaused.checked === true});
                if (value === null) unavailable(labels.unavailable);
                else { render(value); showMessage(value.can_manage ? labels.saved : labels.denied); }
            } catch (error) {
                if (!alive()) return;
                if (error.status === 409) {
                    // Reload once to review the newer policy. Never retry the mutation.
                    run.busy = false; run.saving = false;
                    await load(labels.conflict);
                } else if (error.status === 422 && error.handoff) {
                    settings.open = true; showMessage(labels[run.saved.policy.enabled ? 'handoff_save' : 'handoff_enable']);
                } else { unavailable(labels.save_failed); }
            } finally { if (alive()) { run.busy = false; run.saving = false; controls(); } }
        }
        function bind(element, event, listener) { element.addEventListener(event, listener); run.listeners.push([element,event,listener]); }
        function dirty() { if (run.saved && run.saved.can_manage && !run.busy) run.dirty = true; }
        function backgroundRefresh() {
            if (alive() && !document.hidden && !run.dirty && !run.busy) load();
        }
        function collectPolicy(enabled) {
            return {enabled:enabled,
                channels:channelInputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; }),
                schedule:{enabled:scheduleEnabled.checked,
                    days:dayInputs.filter(function (input) { return input.checked; }).map(function (input) { return Number(input.value); }),
                    start:start.value, end:end.value, all_day:allDay.checked}};
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
        bind(scheduleEnabled, 'change', function () { dirty(); controls(); });
        bind(allDay, 'change', function () { dirty(); controls(); });
        channelInputs.concat(dayInputs).concat([externalPaused]).forEach(function (input) { bind(input, 'change', dirty); });
        [start,end].forEach(function (input) { bind(input, 'input', dirty); bind(input, 'change', dirty); });
        bind(window, 'focus', backgroundRefresh);
        bind(document, 'visibilitychange', backgroundRefresh);
        run.timer = window.setInterval(backgroundRefresh, 30000);
        if (window.DashboardSPA && typeof window.DashboardSPA.onCleanup === 'function') window.DashboardSPA.onCleanup(function () { if (active === run) destroy(); });
        controls(); load();
    }
    window.DashboardMetaAiControls = {mount:mount, destroy:destroy};
    mount();
}());
