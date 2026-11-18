(function () {
    'use strict';
    if (window.DashboardMetaCommentAi) { window.DashboardMetaCommentAi.mount(); return; }
    var mounted = null;
    function mount() {
        if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
        var host = document.getElementById('meta-comment-ai'), bootstrap = document.getElementById('meta-comment-ai-bootstrap');
        if (!host || !bootstrap || mounted && mounted.host === host) return;
        if (mounted) mounted.destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (_) { return; }
        var toggle = host.querySelector('[data-mcai-toggle]'), schedule = host.querySelector('[data-mcai-schedule-toggle]');
        var form = host.querySelector('[data-mcai-form]'), state = host.querySelector('[data-mcai-state]');
        var message = host.querySelector('[data-mcai-message]'), worker = host.querySelector('[data-mcai-worker]');
        var mode = host.querySelector('[data-mcai-mode]'), start = host.querySelector('[data-mcai-start]'), end = host.querySelector('[data-mcai-end]');
        var labels = config.labels || {}, current = null, canManage = false, saving = false, disposed = false, controller = null, timer = null, writeVersion = 0;
        function t(key) { return labels[key] || key; }
        function show(text) { message.textContent = text || ''; message.hidden = !text; }
        function endpoint(name) {
            var url = new URL(config.urls && config.urls[name], window.location.href);
            if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) throw new Error();
            return url.toString();
        }
        function valid(value) {
            return value && typeof value.enabled === 'boolean' && ['always','scheduled'].includes(value.mode)
                && Array.isArray(value.days) && value.days.length <= 7 && value.days.every(function (day) { return Number.isInteger(day) && day >= 1 && day <= 7; })
                && typeof value.start === 'string' && /^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(value.start)
                && typeof value.end === 'string' && /^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(value.end)
                && value.timezone === 'Africa/Cairo' && Number.isInteger(value.revision) && value.revision >= 0
                && typeof value.active_now === 'boolean' && typeof value.configured === 'boolean';
        }
        function scheduleFields() {
            host.querySelector('[data-mcai-schedule-fields]').hidden = mode.value !== 'scheduled';
        }
        function render(fill) {
            toggle.disabled = saving || !current || !canManage || !current.configured && !current.enabled;
            schedule.disabled = saving || !current || !canManage;
            form.querySelectorAll('input,select,button').forEach(function (el) { el.disabled = saving || !current || !canManage; });
            toggle.textContent = saving ? t('saving') : current && current.enabled ? t('stop') : t('start');
            toggle.setAttribute('aria-pressed', String(!!(current && current.enabled)));
            host.setAttribute('aria-busy', String(saving));
            if (!current) return;
            var problems={AI_NOT_CONFIGURED:'unconfigured',TRANSPORT_ERROR:'cycle_failed',HTTP_ERROR:'cycle_failed',INCOMPLETE:'cycle_failed',
                RECONNECT_REQUIRED:'reconnect',PERMISSION_REQUIRED:'permissions',ACCESS_REVOKED:'revoked'};
            var problem=Object.prototype.hasOwnProperty.call(problems,current.last_reason) ? problems[current.last_reason] : '';
            state.textContent = !current.configured ? t('unconfigured') : !current.enabled ? t('off') : problem ? t('review') : current.active_now ? t('active') : t('scheduled');
            state.dataset.active = String(current.enabled && current.active_now && current.configured && !problem);
            worker.textContent = problem ? t(problem) : !canManage ? t('owner_only') : current.enabled && !current.worker_seen_at ? t('worker_wait') : '';
            worker.hidden = !worker.textContent;
            if (fill) {
                mode.value = current.mode; start.value = current.start; end.value = current.end;
                form.querySelectorAll('[data-mcai-day]').forEach(function (el) { el.checked = current.days.includes(Number(el.value)); });
            }
            scheduleFields();
        }
        async function call(name, values) {
            var local = new AbortController(), timeout, failed;
            controller = local;
            var options = { method: values ? 'POST' : 'GET', credentials:'same-origin', cache:'no-store', redirect:'error', signal:local.signal,
                headers:{ Accept:'application/json','X-Requested-With':'XMLHttpRequest' } };
            if (values) { options.headers['Content-Type']='application/json'; options.headers['X-CSRF-TOKEN']=config.csrf || ''; options.body=JSON.stringify(values); }
            try {
                return await Promise.race([
                    (async function () {
                        var response = await fetch(endpoint(name),options);
                        if (response.redirected || !/^application\/json\b/i.test(response.headers.get('Content-Type') || '')) throw new Error();
                        var payload = await response.json();
                        if (!response.ok || !payload || payload.success !== true) {
                            failed = new Error(); failed.status=response.status; failed.reason=payload && payload.reason; throw failed;
                        }
                        if (!valid(payload.control) || typeof payload.can_manage !== 'boolean') throw new Error();
                        return payload;
                    }()),
                    new Promise(function (_,reject) { timeout=setTimeout(function () { local.abort(); reject(new Error()); },35000); })
                ]);
            } finally { clearTimeout(timeout); if (controller === local) controller=null; }
        }
        async function load(keepMessage) {
            var version=writeVersion;
            try {
                var payload = await call('status'); if (disposed || version !== writeVersion) return;
                current=payload.control; canManage=payload.can_manage; render(true); if (!keepMessage) show('');
            } catch (error) { if (!disposed && version === writeVersion) { if (!current) state.textContent=t('unavailable'); show(error.status === 403 ? t('denied') : t('unavailable')); render(false); } }
        }
        function values() { return { enabled:current.enabled,mode:current.mode,days:current.days.slice(),start:current.start,end:current.end,expected_revision:current.revision }; }
        async function save(input) {
            if (disposed || saving || !current || !canManage) return;
            writeVersion++; saving=true; show(''); render(false);
            try {
                var payload=await call('update',input); if (disposed) return;
                current=payload.control; canManage=payload.can_manage; form.hidden=true; schedule.setAttribute('aria-expanded','false'); render(true);
            } catch (error) {
                if (disposed) return;
                if (error.status === 409) { await load(true); if (!disposed) show(t(error.reason === 'AI_BUSY' ? 'busy' : 'changed')); }
                else if (error.status === 422) show(error.reason === 'AI_NOT_CONFIGURED' ? t('unconfigured') : t('invalid'));
                else if (error.status === 403) { canManage=false; show(t('denied')); }
                else { await load(true); if (!disposed) show(t('uncertain')); }
            } finally { saving=false; if (!disposed) render(false); }
        }
        function onToggle() { if (!current) return; var input=values(); input.enabled=!current.enabled; save(input); }
        function onSchedule() { if (!canManage || saving) return; form.hidden=!form.hidden; schedule.setAttribute('aria-expanded',String(!form.hidden)); }
        function onSubmit(event) {
            event.preventDefault(); if (!current || !canManage || saving) return;
            var input=values(); input.mode=mode.value; input.start=start.value; input.end=end.value;
            input.days=Array.from(form.querySelectorAll('[data-mcai-day]:checked')).map(function (el) { return Number(el.value); });
            if (input.mode === 'scheduled' && (!input.days.length || input.start === input.end)) { show(t('invalid')); return; }
            if (input.mode === 'always' && !input.days.length) input.days=current.days.slice();
            save(input);
        }
        toggle.addEventListener('click',onToggle); schedule.addEventListener('click',onSchedule); form.addEventListener('submit',onSubmit); mode.addEventListener('change',scheduleFields);
        function destroy() {
            disposed=true; clearInterval(timer); if (controller) controller.abort();
            toggle.removeEventListener('click',onToggle); schedule.removeEventListener('click',onSchedule); form.removeEventListener('submit',onSubmit); mode.removeEventListener('change',scheduleFields);
            if (mounted && mounted.host === host) mounted=null;
        }
        mounted={host:host,destroy:destroy}; if (window.DashboardSPA) window.DashboardSPA.onCleanup(destroy);
        render(false); load(false);
        timer=setInterval(function () { if (!disposed && !saving && form.hidden && !document.hidden && !controller) load(false); },30000);
    }
    window.DashboardMetaCommentAi={mount:mount,destroy:function () { if (mounted) mounted.destroy(); }};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',mount,{once:true}); else mount();
}());
