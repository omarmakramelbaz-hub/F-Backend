'use strict';

// Isolated, dependency-free browser harness: no network, credentials or framework.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '../public/js/dashboard-meta-ai-controls.js'), 'utf8');
let checks = 0;
function check(value, message) { checks++; assert.ok(value, message); }
function equal(actual, expected, message) { checks++; assert.deepStrictEqual(actual, expected, message); }

class Element {
    constructor() { this.listeners = {}; this.dataset = {}; this.hidden = false; this.disabled = false; this.checked = false; this.value = ''; this.attributes = {}; this.text = ''; this.children = []; this.open = false; }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    appendChild(child) { this.children.push(child); return child; }
    set innerHTML(value) { throw new Error('Unsafe HTML assignment'); }
    setAttribute(key, value) { this.attributes[key] = String(value); }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    removeEventListener(type, listener) { this.listeners[type] = (this.listeners[type] || []).filter(item => item !== listener); }
    fire(type) {
        const event = {prevented: false, immediateStopped: false,
            preventDefault() { this.prevented = true; },
            stopImmediatePropagation() { this.immediateStopped = true; }};
        for (const listener of (this.listeners[type] || []).slice()) {
            listener.call(this, event);
            if (event.immediateStopped) break;
        }
        return event;
    }
}
function attachLegacySubmit(f) {
    // Execute the actual shared footer callback, after Blade resolves the fixed translation.
    const footer = fs.readFileSync(path.join(__dirname, '../resources/views/admin/layouts/footer.blade.php'), 'utf8');
    const match = footer.match(/\$\(\s*"form"\s*\)\.not\("\[data-wa-reply-form\]"\)\.submit\(function\(\) \{([\s\S]*?)\n\s*\}\);/);
    check(match, 'actual shared footer submit callback located');
    const body = match[1].replace("@lang('main.under click')", 'قيد التنفيذ');
    const legacy = vm.runInNewContext('(function () {' + body + '\n})', {$: element => ({find: selector => ({html: value => {
        check(element === f.nodes.form && selector === "button[type='submit']", 'legacy footer selects this form submit button');
        f.legacySubmitCalls++;
        f.nodes.save.textContent = value.replace(/<[^>]*>/g, '');
    }})})});
    f.nodes.form.addEventListener('submit', legacy);
}
function policy() {
    return {enabled: false, channels: ['whatsapp', 'messenger', 'instagram'],
        schedule: {enabled: false, days: [0,1,2,3,4,5,6], start: '09:00', end: '23:00', all_day: false},
        dispatch_schedule: {enabled: true, days: [0,1,2,3,4,5,6], start: '09:00', end: '00:30', all_day: false}};
}
function state(change = {}) {
    const value = Object.assign({available: true, policy: policy(), revision: 0, effective: false, status: 'DISABLED', can_manage: true,
        updated_at_utc: null, local_time: '2026-10-14T21:00:00+03:00'}, change);
    if (!Object.prototype.hasOwnProperty.call(change, 'dispatch_state')) value.dispatch_state = {
        effective: !!(value.policy && value.policy.enabled), status:value.policy && value.policy.enabled ? 'ACTIVE' : 'DISABLED', local_time:value.local_time};
    return value;
}
function response(value, status = 200, options = {}) {
    return Object.assign({ok: status >= 200 && status < 300, status, redirected: false,
        headers: {get: () => 'application/json'}, json: async () => value}, options);
}
function fixture(first = state(), changes = {}) {
    const nodes = {};
    ['toggle','save','refresh','form','status','message','settings','current-time','schedule','all-day','start','end','external-paused','channel-status','handoff-state',
        'dispatch-status','dispatch-schedule','dispatch-all-day','dispatch-start','dispatch-end']
        .forEach(name => { nodes[name] = new Element(); });
    const channels = ['whatsapp','messenger','instagram'].map(value => Object.assign(new Element(), {value}));
    const days = [0,1,2,3,4,5,6].map(value => Object.assign(new Element(), {value: String(value)}));
    const dispatchDays = [0,1,2,3,4,5,6].map(value => Object.assign(new Element(), {value: String(value)}));
    const panel = new Element(); panel.hidden = true;
    panel.querySelector = selector => nodes[selector.slice('[data-meta-ai-'.length, -1)] || null;
    panel.querySelectorAll = selector => selector === '[data-meta-ai-channel]' ? channels : selector === '[data-meta-ai-day]' ? days : selector === '[data-meta-ai-dispatch-day]' ? dispatchDays : [];
    const config = Object.assign({state_url: '/admin/meta/ai-control', update_url: '/admin/meta/ai-control', csrf: 'fixture-csrf', lang: 'ar'}, changes.config || {});
    const bootstrap = Object.assign(new Element(), {textContent: JSON.stringify(config)});
    const document = Object.assign(new Element(), {hidden: false, createElement: () => new Element(), getElementById: id => id === 'meta-ai-controls' ? panel : id === 'meta-ai-controls-bootstrap' ? bootstrap : null});
    if (changes.missing) document.getElementById = () => null;
    const timers = new Map(); let timerId = 0;
    const window = Object.assign(new Element(), {location: {href: 'https://dashboard.example/admin/meta'},
        setInterval: (callback, delay) => { const id = ++timerId; timers.set(id, {callback, delay}); return id; },
        clearInterval: id => timers.delete(id),
        DashboardSPA: {isCurrentPage: () => changes.current !== false, onCleanup: callback => { result.cleanup = callback; }}});
    const result = {nodes, channels, days, dispatchDays, panel, config, bootstrap, document, window, timers, calls: [], queue: [response(first)], cleanup: null, legacySubmitCalls: 0};
    const fetch = async (url, options) => {
        result.calls.push({url, options});
        const next = result.queue.shift();
        if (next instanceof Error) throw next;
        if (typeof next === 'function') return next(url, options);
        if (next === undefined) throw new Error('Unexpected request');
        return next;
    };
    result.run = () => vm.runInNewContext(source, {window, document, fetch, URL, console}, {filename: 'dashboard-meta-ai-controls.js'});
    if (changes.legacySubmit === 'before') attachLegacySubmit(result);
    result.run();
    if (changes.legacySubmit === 'after') attachLegacySubmit(result);
    return result;
}
async function settle() { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); }
function sent(f, at = f.calls.length - 1) { return JSON.parse(f.calls[at].options.body); }

(async function () {
    let f = fixture(); await settle();
    equal(f.calls.length, 1, 'one initial GET');
    check(f.calls[0].url === 'https://dashboard.example/admin/meta/ai-control', 'same origin endpoint');
    equal(f.calls[0].options.credentials, 'same-origin', 'session credentials scoped');
    equal(f.calls[0].options.cache, 'no-store', 'policy never cached');
    equal(f.calls[0].options.redirect, 'error', 'no sign-in redirect accepted');
    check(!f.calls[0].options.method && !f.calls[0].options.body, 'initial read never mutates');
    check(!f.panel.hidden && !f.nodes.settings.hidden, 'valid state revealed');
    equal(f.nodes.toggle.textContent, 'تشغيل ردود وتحليل AI', 'disabled toggle label');
    equal(f.nodes.status.textContent, 'AI متوقف', 'disabled status');
    check(!f.nodes.toggle.disabled && !f.nodes.save.disabled, 'owner controls usable');
    check(f.channels.every(input => input.checked), 'default three channels from persisted state');
    check(f.days.every(input => input.disabled) && f.nodes.start.disabled && f.nodes.end.disabled, 'dormant schedule disabled');
    check(!f.nodes['external-paused'].checked, 'attestation not preselected');
    equal(f.nodes['current-time'].textContent, 'الوقت في القاهرة: 2026-10-14 21:00', 'server Cairo time shown');
    equal(f.nodes.save.textContent, 'حفظ الإعدادات', 'save label belongs to the async control');
    check(f.nodes['dispatch-schedule'].checked && !f.nodes['dispatch-all-day'].checked, 'dispatch starts with a separate enabled schedule');
    equal([f.nodes['dispatch-start'].value, f.nodes['dispatch-end'].value], ['09:00','00:30'], 'default dispatch hours cross midnight independently of reply hours');
    check(f.dispatchDays.every(input => input.checked && !input.disabled), 'all seven dispatch start days are independently editable');
    check(!f.nodes['dispatch-start'].disabled && !f.nodes['dispatch-end'].disabled, 'dispatch clocks remain editable when reply scheduling is off');
    equal(f.nodes['dispatch-status'].textContent, 'إرسال الطلبات متوقف', 'master off also describes dispatch as off');

    // A previously stored reply-only policy can be displayed without any automatic save or activation.
    const oldPolicy = policy(); delete oldPolicy.dispatch_schedule;
    const oldState = state({policy:oldPolicy}); delete oldState.dispatch_state;
    let separate = fixture(oldState); await settle();
    equal(separate.calls.length, 1, 'reading a legacy policy does not write a migration');
    equal([separate.nodes['dispatch-start'].value, separate.nodes['dispatch-end'].value], ['09:00','00:30'], 'legacy policy receives the requested dispatch defaults in the form');
    equal(separate.nodes['dispatch-status'].textContent, 'حالة إرسال الطلبات غير متاحة حاليًا', 'legacy state never borrows the reply effective status for dispatch');
    separate.queue.push(response(state({revision:1}))); separate.nodes.form.fire('submit'); await settle();
    equal(sent(separate).policy, policy(), 'explicit legacy save posts the normalized four-key policy');
    check(!sent(separate).policy.enabled, 'normalizing a legacy policy never enables AI');

    // Reply and dispatch schedules collect different days and overnight hours in one atomic save.
    separate = fixture(); await settle();
    separate.nodes.schedule.checked = true; separate.nodes.schedule.fire('change');
    separate.nodes.start.value = '21:00'; separate.nodes.end.value = '06:00';
    separate.days.forEach(input => { input.checked = ['1','3'].includes(input.value); });
    separate.nodes['dispatch-start'].value = '10:15'; separate.nodes['dispatch-end'].value = '00:45';
    separate.dispatchDays.forEach(input => { input.checked = ['2','6'].includes(input.value); });
    const separatePolicy = {enabled:false, channels:policy().channels,
        schedule:{enabled:true, days:[1,3], start:'21:00', end:'06:00', all_day:false},
        dispatch_schedule:{enabled:true, days:[2,6], start:'10:15', end:'00:45', all_day:false}};
    separate.queue.push(response(state({policy:separatePolicy, revision:2})));
    separate.nodes.form.fire('submit'); await settle();
    equal(sent(separate).policy, separatePolicy, 'saving while master off preserves both distinct schedules without enabling');
    check(!separate.nodes['dispatch-schedule'].disabled && !separate.nodes.schedule.disabled, 'both schedules remain configurable while master off');
    equal(separate.days.filter(input => input.checked).map(input => Number(input.value)), [1,3], 'reply days render independently');
    equal(separate.dispatchDays.filter(input => input.checked).map(input => Number(input.value)), [2,6], 'dispatch days render independently');

    separate.nodes['dispatch-start'].value = '12:00'; separate.nodes['dispatch-end'].value = '13:00';
    separate.dispatchDays.forEach(input => { input.checked = input.value === '4'; });
    const separateEnabled = JSON.parse(JSON.stringify(separatePolicy)); separateEnabled.enabled = true;
    separateEnabled.dispatch_schedule = {enabled:true, days:[4], start:'12:00', end:'13:00', all_day:false};
    separate.queue.push(response(state({policy:separateEnabled, revision:3, status:'SCHEDULED_OFF', effective:false,
        local_time:'2026-10-15T12:30:00+03:00', dispatch_state:{status:'ACTIVE', effective:true, local_time:'2026-10-15T12:30:00+03:00'}})));
    separate.nodes.toggle.fire('click'); await settle();
    equal(sent(separate).policy, separateEnabled, 'enable uses unsaved dispatch days and hours while retaining reply schedule');
    equal(separate.nodes.status.textContent, 'AI خارج مواعيد التشغيل', 'reply status can be outside its hours');
    equal(separate.nodes['dispatch-status'].textContent, 'إرسال الطلبات مفعّل حسب الجدول', 'dispatch status can be open while replies are closed');
    separate.queue.push(response(state({policy:separateEnabled, revision:3, status:'ACTIVE', effective:true,
        local_time:'2026-10-12T22:00:00+03:00', dispatch_state:{status:'SCHEDULED_OFF', effective:false, local_time:'2026-10-12T22:00:00+03:00'}})));
    separate.nodes.refresh.fire('click'); await settle();
    equal(separate.nodes['dispatch-status'].textContent, 'إرسال الطلبات خارج مواعيد الإرسال', 'dispatch status can be closed while replies are open');

    separate.nodes['all-day'].checked = true; separate.nodes['all-day'].fire('change');
    check(separate.nodes.start.disabled && !separate.nodes['dispatch-start'].disabled, 'reply all-day affects only reply clocks');
    separate.nodes['all-day'].checked = false; separate.nodes['all-day'].fire('change');
    separate.nodes['dispatch-all-day'].checked = true; separate.nodes['dispatch-all-day'].fire('change');
    check(separate.nodes['dispatch-start'].disabled && !separate.nodes.start.disabled, 'dispatch all-day affects only dispatch clocks');
    check(separate.dispatchDays.every(input => !input.disabled), 'dispatch all-day still uses the selected dispatch days');
    const allDayPolicy = JSON.parse(JSON.stringify(separateEnabled)); allDayPolicy.dispatch_schedule.all_day = true;
    separate.queue.push(response(state({policy:allDayPolicy, revision:4, status:'ACTIVE', effective:true})));
    separate.nodes.form.fire('submit'); await settle();
    equal(sent(separate).policy, allDayPolicy, 'all-day dispatch saves without changing reply hours or its own selected days');
    separate.nodes['dispatch-schedule'].checked = false; separate.nodes['dispatch-schedule'].fire('change');
    check(separate.dispatchDays.every(input => input.disabled) && separate.nodes['dispatch-all-day'].disabled
        && separate.nodes['dispatch-start'].disabled && separate.nodes['dispatch-end'].disabled, 'turning off dispatch scheduling disables only dispatch schedule fields');
    check(separate.days.every(input => !input.disabled) && !separate.nodes.start.disabled, 'reply scheduling remains enabled independently');
    const unrestrictedDispatch = JSON.parse(JSON.stringify(allDayPolicy)); unrestrictedDispatch.dispatch_schedule.enabled = false;
    separate.queue.push(response(state({policy:unrestrictedDispatch, revision:5, status:'ACTIVE', effective:true})));
    separate.nodes.form.fire('submit'); await settle();
    equal(sent(separate).policy, unrestrictedDispatch, 'turning off dispatch scheduling retains its configured hours and days');

    // Immediate master stop always uses the persisted complete policy, even when both forms are invalid.
    separate.nodes.start.value = 'invalid'; separate.nodes.end.value = 'invalid';
    separate.nodes['dispatch-start'].value = 'invalid'; separate.nodes['dispatch-end'].value = 'invalid';
    separate.dispatchDays.forEach(input => { input.checked = false; });
    const stoppedSeparate = JSON.parse(JSON.stringify(unrestrictedDispatch)); stoppedSeparate.enabled = false;
    separate.queue.push(response(state({policy:stoppedSeparate, revision:6})));
    separate.nodes.toggle.fire('click'); await settle();
    equal(sent(separate).policy, stoppedSeparate, 'master stop ignores invalid dirty reply and dispatch edits while preserving both saved schedules');
    equal(separate.nodes['dispatch-status'].textContent, 'إرسال الطلبات متوقف', 'master stop resets dispatch status');

    for (const field of ['dispatch-schedule','dispatch-all-day','dispatch-start','dispatch-end','dispatch-day']) {
        separate = fixture(); await settle();
        const input = field === 'dispatch-day' ? separate.dispatchDays[2] : separate.nodes[field];
        input.fire(field === 'dispatch-start' || field === 'dispatch-end' ? 'input' : 'change');
        const idleTimer = [...separate.timers.values()][0]; idleTimer.callback(); separate.window.fire('focus'); separate.document.fire('visibilitychange'); await settle();
        equal(separate.calls.length, 1, field + ' dirty state suppresses background refreshes');
        separate.cleanup();
        check((input.listeners.change || []).length === 0 && (input.listeners.input || []).length === 0, field + ' listeners are removed by SPA cleanup');
        input.fire('change'); input.fire('input'); idleTimer.callback(); await settle();
        equal(separate.calls.length, 1, field + ' cannot trigger reads after cleanup');
    }

    // The real legacy footer must neither leave a spinner nor cause another async mutation.
    // Exercise both listener orders, including the footer writing before this form handles submit.
    for (const order of ['before', 'after']) {
        let legacy = fixture(state(), {legacySubmit:order}); await settle();
        let finishSave;
        legacy.queue.push(() => new Promise(resolve => { finishSave = resolve; }));
        const submitted = legacy.nodes.form.fire('submit');
        check(submitted.prevented && submitted.immediateStopped, 'async form owns submit propagation: ' + order);
        equal(legacy.nodes.save.textContent, 'جارٍ حفظ الإعدادات…', 'pending save has its own label: ' + order);
        check(legacy.nodes.save.disabled, 'pending save is disabled: ' + order);
        legacy.nodes.form.fire('submit'); await settle();
        equal(legacy.calls.filter(call => call.options.method === 'POST').length, 1, 'double submit posts once: ' + order);
        finishSave(response(state({revision:1}))); await settle();
        equal(legacy.nodes.save.textContent, 'حفظ الإعدادات', 'successful save restores label: ' + order);
        check(!legacy.nodes.save.disabled && legacy.nodes.message.textContent.includes('تم حفظ'), 'save completion is visible and usable: ' + order);
        equal(legacy.legacySubmitCalls, order === 'before' ? 2 : 0, 'later generic callback is stopped while earlier callback is safely overwritten: ' + order);

        for (const failure of ['network', 'http', 'handoff', 'conflict']) {
            legacy = fixture(state(), {legacySubmit:order}); await settle();
            if (failure === 'network') legacy.queue.push(new Error('offline'));
            if (failure === 'http') legacy.queue.push(response({}, 500));
            if (failure === 'handoff') legacy.queue.push(response({reason:'HANDOFF_REQUIRED'}, 422));
            if (failure === 'conflict') legacy.queue.push(response({reason:'STALE_REVISION'}, 409), response(state({revision:3})));
            legacy.nodes.form.fire('submit'); await settle();
            equal(legacy.nodes.save.textContent, 'حفظ الإعدادات', failure + ' restores label: ' + order);
            equal(legacy.calls.filter(call => call.options.method === 'POST').length, 1, failure + ' never retries a mutation: ' + order);
            equal(legacy.calls.length, failure === 'conflict' ? 3 : 2, failure + ' only conflict reloads one read: ' + order);
            check(legacy.nodes.save.disabled === (failure === 'network' || failure === 'http'), failure + ' retains correct edit permissions: ' + order);
            if (failure === 'handoff') check(legacy.nodes.message.textContent.includes('أوقف ردود مساعد ميتا'), '422 keeps handoff instructions: ' + order);
            if (failure === 'conflict') check(legacy.nodes.message.textContent.includes('جلسة أخرى'), '409 keeps conflict instructions: ' + order);
        }

        const enabledPolicy = policy(); enabledPolicy.enabled = true;
        legacy = fixture(state({policy:enabledPolicy, status:'ACTIVE', effective:true}), {legacySubmit:order}); await settle();
        legacy.channels.forEach(input => { input.checked = false; });
        legacy.nodes.form.fire('submit'); await settle();
        equal(legacy.nodes.save.textContent, 'حفظ الإعدادات', 'synchronous validation restores label: ' + order);
        equal(legacy.calls.length, 1, 'invalid policy never posts: ' + order);
        check(!legacy.nodes.save.disabled && legacy.nodes.message.textContent.includes('راجع'), 'invalid policy remains editable with explanation: ' + order);

        legacy = fixture(state({can_manage:false}), {legacySubmit:order}); await settle();
        legacy.nodes.form.fire('submit'); await settle();
        equal(legacy.nodes.save.textContent, 'حفظ الإعدادات', 'read-only synthetic submit restores label: ' + order);
        check(legacy.nodes.save.disabled, 'read-only submit remains disabled: ' + order);
        equal(legacy.calls.length, 1, 'read-only submit never posts: ' + order);
    }

    // Enabling uses exactly the channels/hours shown beside the owner's current attestation.
    f.channels[1].checked = false; f.channels[2].checked = false;
    f.nodes.schedule.checked = true; f.nodes.start.value = '22:00'; f.nodes.end.value = '03:00';
    f.days.forEach(input => { input.checked = input.value === '5'; }); f.nodes['external-paused'].checked = true;
    const enabled = policy(); enabled.enabled = true;
    const selectedEnabled = {enabled:true, channels:['whatsapp'], schedule:{enabled:true, days:[5], start:'22:00', end:'03:00', all_day:false}, dispatch_schedule:policy().dispatch_schedule};
    f.queue.push(response(state({policy: selectedEnabled, revision: 1, effective: true, status: 'ACTIVE'})));
    f.nodes.toggle.fire('click');
    check(f.nodes.toggle.disabled && f.nodes.save.disabled, 'mutation disables controls immediately');
    f.nodes.toggle.fire('click'); await settle();
    equal(f.calls.length, 2, 'double click never duplicates POST');
    equal(sent(f), {policy: selectedEnabled, expected_revision: 0, external_agent_paused: true}, 'enable sends current selected WhatsApp-only policy and attestation with exact contract');
    equal(f.calls[1].options.method, 'POST', 'toggle POST');
    equal(f.calls[1].options.headers['X-CSRF-TOKEN'], 'fixture-csrf', 'CSRF header');
    equal(f.calls[1].options.headers['Content-Type'], 'application/json', 'JSON content type');
    equal(f.nodes.toggle.textContent, 'إيقاف ردود وتحليل AI', 'enabled toggle label');
    equal(f.nodes.toggle.attributes['aria-pressed'], 'true', 'pressed follows saved master state');
    equal(f.nodes.status.textContent, 'AI مفعّل حسب الجدول', 'active describes policy, not an actual AI call');
    check(f.channels[0].checked && !f.channels[1].checked && !f.channels[2].checked && !f.days[2].checked
        && f.nodes.start.value === '22:00', 'confirmed enable retains exactly the selected scope and hours');

    // Form save uses edits while retaining master enabled and the new revision.
    f.channels[2].checked = true; f.nodes.schedule.checked = true; f.nodes.schedule.fire('change');
    check(f.days.every(input => !input.disabled) && !f.nodes.start.disabled, 'schedule fields follow enabled switch');
    f.days.forEach(input => { input.checked = input.value === '5'; });
    f.nodes.start.value = '22:00'; f.nodes.end.value = '03:00'; f.nodes['external-paused'].checked = true;
    const edited = {enabled: true, channels: ['whatsapp','instagram'], schedule: {enabled: true, days: [5], start: '22:00', end: '03:00', all_day: false}, dispatch_schedule:policy().dispatch_schedule};
    f.queue.push(response(state({policy: edited, revision: 2, status: 'SCHEDULED_OFF', effective: false})));
    check(f.nodes.form.fire('submit').prevented, 'form does not navigate'); await settle();
    equal(sent(f), {policy: edited, expected_revision: 1, external_agent_paused: true}, 'save captures form and revision');
    equal(f.nodes.status.textContent, 'AI خارج مواعيد التشغيل', 'scheduled off shown independently of master');
    check(!f.nodes['external-paused'].checked, 'attestation reset after persisted state load');
    f.nodes['all-day'].checked = true; f.nodes['all-day'].fire('change');
    check(f.nodes.start.disabled && f.nodes.end.disabled, 'all day disables clock inputs');

    // Conflict reloads current server state exactly once and never retries a write.
    const newer = policy(); newer.channels = ['messenger'];
    f.queue.push(response({reason: 'STALE_REVISION'}, 409), response(state({policy: newer, revision: 7})));
    f.nodes.form.fire('submit'); await settle();
    equal(f.calls.slice(-2).map(call => call.options.method || 'GET'), ['POST','GET'], '409 reload without mutation retry');
    check(f.nodes.message.textContent.includes('جلسة أخرى'), 'conflict explanation retained after reload');
    check(!f.channels[0].checked && f.channels[1].checked && !f.channels[2].checked, 'conflict renders latest policy');
    f.queue.push(response(state({policy: enabled, revision: 8, status: 'ACTIVE', effective: true})));
    f.nodes.toggle.fire('click'); await settle();
    equal(sent(f).expected_revision, 7, 'next explicit operation uses reloaded revision');

    // Handoff rejection is actionable and never infers that enabling succeeded.
    f = fixture(); await settle(); f.queue.push(response({reason:'HANDOFF_REQUIRED'}, 422));
    f.nodes.toggle.fire('click'); await settle();
    equal(f.calls.length, 2, 'handoff rejection does not retry');
    check(f.nodes.settings.open && f.nodes.message.textContent.includes('أوقف ردود مساعد ميتا'), 'handoff rejection opens explicit attestation');
    equal(f.nodes.status.textContent, 'AI متوقف', 'handoff rejection preserves disabled state');
    check(!f.nodes.toggle.disabled, 'owner may explicitly resolve handoff');
    f.queue.push(response({error:'EXTERNAL_AGENT_PAUSE_REQUIRED'}, 422));
    f.nodes.toggle.fire('click'); await settle();
    equal(f.calls.length, 3, 'backend handoff reason makes no automatic retry');
    check(f.nodes.message.textContent.includes('محادثات واتساب الحالية'), 'backend exact handoff reason explained');
    check(f.nodes.message.textContent.includes('اضغط تشغيل AI') && !f.nodes.message.textContent.includes('احفظ'), 'disabled handoff rejection directs the owner to enable, not save an off policy');

    // Immediate stop uses persisted policy and cannot be blocked by incomplete unsaved edits.
    f = fixture(state({policy:enabled, status:'ACTIVE', effective:true, revision:5})); await settle();
    f.channels.forEach(input => { input.checked = false; }); f.nodes.start.value = 'invalid'; f.nodes.end.value = 'invalid';
    f.days.forEach(input => { input.checked = false; }); f.nodes['external-paused'].checked = false;
    const stopped = JSON.parse(JSON.stringify(enabled)); stopped.enabled = false;
    f.queue.push(response(state({policy:stopped, revision:6})));
    f.nodes.toggle.fire('click'); await settle();
    equal(sent(f), {policy:stopped, expected_revision:5, external_agent_paused:false}, 'disable ignores invalid dirty form and stops the persisted policy');
    equal(f.nodes.status.textContent, 'AI متوقف', 'immediate stop confirmed');

    // Read-only accounts can read status but cannot mutate via synthetic events either.
    f = fixture(state({can_manage:false})); await settle();
    check(f.nodes.toggle.disabled && f.nodes.save.disabled && f.channels.every(input => input.disabled), 'read-only controls disabled');
    check(f.nodes['external-paused'].disabled, 'read-only cannot attest takeover');
    check(['dispatch-schedule','dispatch-all-day','dispatch-start','dispatch-end'].every(key => f.nodes[key].disabled)
        && f.dispatchDays.every(input => input.disabled), 'read-only cannot edit any dispatch schedule field');
    f.nodes.toggle.fire('click'); f.nodes.form.fire('submit'); await settle();
    equal(f.calls.length, 1, 'read-only handlers cannot POST');
    check(f.nodes.message.textContent.includes('عرض فقط'), 'owner-only explanation');

    // Backend unavailable may omit all policy fields; status remains fail closed.
    f = fixture({available:false, reason:'UNAVAILABLE'}); await settle();
    check(f.nodes.toggle.disabled && f.nodes.settings.hidden, 'unavailable controls fail closed');
    f.nodes.toggle.fire('click'); f.nodes.form.fire('submit'); await settle();
    equal(f.calls.length, 1, 'unavailable never mutates');

    // Invalid revisions, schema, local clock, status and explicit types cannot arm controls.
    const invalid = [state({revision:-1}), state({revision:1.5}), state({revision:'1'}), state({revision:Number.MAX_SAFE_INTEGER+1}),
        state({can_manage:1}), state({effective:true}), state({status:'UNKNOWN'}), state({local_time:'<img onerror=alert(1)>'}),
        state({policy:{...policy(), extra:true}}), state({policy:{...policy(), enabled:'true'}}),
        state({policy:{...policy(), channels:['whatsapp','whatsapp']}}),
        state({policy:{...policy(), schedule:{...policy().schedule, days:['1']}}}),
        state({policy:{...policy(), schedule:{...policy().schedule, end:'24:00'}}}),
        state({policy:{...policy(), schedule:{...policy().schedule, end:'09:00'}}}),
        ...[null, {...policy().dispatch_schedule, days:['1']}, {...policy().dispatch_schedule, days:[1,1]},
            {...policy().dispatch_schedule, days:[]}, {...policy().dispatch_schedule, end:'24:00'},
            {...policy().dispatch_schedule, end:'09:00'}, {...policy().dispatch_schedule, all_day:1},
            {...policy().dispatch_schedule, extra:true}].map(dispatch_schedule => state({policy:{...policy(), dispatch_schedule}})),
        ...[null, {effective:true,status:'DISABLED',local_time:'2026-10-14T21:00:00+03:00'},
            {effective:false,status:'UNKNOWN',local_time:'2026-10-14T21:00:00+03:00'},
            {effective:false,status:'DISABLED',local_time:'2026-10-14T21:00:00Z'},
            {effective:false,status:'DISABLED',local_time:'2026-10-14T21:00:00+03:00',extra:true}]
            .map(dispatch_state => state({dispatch_state})), {}, null];
    const missingDispatchState = state(); delete missingDispatchState.dispatch_state; invalid.push(missingDispatchState);
    for (const value of invalid) {
        f = fixture(value); await settle();
        check(f.nodes.toggle.disabled && f.nodes.settings.hidden, 'malformed response cannot enable');
        f.nodes.toggle.fire('click'); equal(f.calls.length, 1, 'malformed state never mutates');
    }

    // Client checks invalid edits instead of sending an unusable schedule.
    f = fixture(state({policy:enabled, effective:true, status:'ACTIVE'})); await settle();
    f.channels.forEach(input => { input.checked = false; }); f.nodes.form.fire('submit'); await settle();
    equal(f.calls.length, 1, 'enabled requires a selected channel');
    check(f.nodes.message.textContent.includes('راجع'), 'invalid form explained');
    f.channels[0].checked = true; f.nodes.schedule.checked = true; f.days.forEach(input => { input.checked = false; });
    f.nodes.form.fire('submit'); await settle(); equal(f.calls.length, 1, 'scheduled mode requires days');
    f.days[1].checked = true; f.nodes.end.value = '09:00'; f.nodes.form.fire('submit'); await settle();
    equal(f.calls.length, 1, 'equal times need explicit all day');
    f.nodes.end.value = '23:00'; f.dispatchDays.forEach(input => { input.checked = false; });
    f.nodes.form.fire('submit'); await settle(); equal(f.calls.length, 1, 'dispatch scheduling independently requires selected days');
    f.dispatchDays[4].checked = true; f.nodes['dispatch-end'].value = '09:00';
    f.nodes.form.fire('submit'); await settle(); equal(f.calls.length, 1, 'equal dispatch times independently need explicit all day');
    equal(f.nodes.save.textContent, 'حفظ الإعدادات', 'dispatch validation never leaves save busy');

    // A failed or uncertain mutation cannot be automatically retried with stale revision.
    f = fixture(); await settle(); f.queue.push(new Error('offline'));
    f.nodes.toggle.fire('click'); await settle();
    check(f.nodes.toggle.disabled && f.nodes.settings.hidden, 'uncertain update locks edits until a fresh read');
    equal(f.calls.length, 2, 'network failure does not retry');
    f.nodes.toggle.fire('click'); await settle(); equal(f.calls.length, 2, 'no stale synthetic retry');
    f.queue.push(response(state({revision:4}))); f.nodes.refresh.fire('click'); await settle();
    check(!f.nodes.toggle.disabled, 'explicit refresh recovers state');
    equal(f.calls.length, 3, 'recovery only GET');

    // Redirect/HTML/error responses stay fail closed without rendering remote content.
    for (const reply of [response({}, 500), response({}, 401), response(state(), 200, {redirected:true}),
        response(state(), 200, {headers:{get: () => 'text/html'}})]) {
        f = fixture(); await settle(); f.queue.push(reply); f.nodes.refresh.fire('click'); await settle();
        check(f.nodes.toggle.disabled && f.nodes.settings.hidden, 'HTTP response cannot bypass state validation');
    }

    // No cross-origin credentials or tokens may be sent even if bootstrap were replaced.
    for (const config of [{state_url:'https://evil.example/admin/meta/ai-control'}, {update_url:'https://evil.example/admin/meta/ai-control'},
        {state_url:'/admin/meta/ai-control?token=secret'}, {state_url:'/admin/meta/ai-control#x'}, {state_url:'/other'}, {csrf:''}]) {
        f = fixture(state(), {config}); await settle(); equal(f.calls.length, 0, 'unsafe bootstrap makes no requests');
    }
    f = fixture(state(), {current:false}); await settle(); equal(f.calls.length, 0, 'inactive SPA page makes no requests');
    f = fixture(state(), {missing:true}); await settle(); equal(f.calls.length, 0, 'other page makes no requests');

    // Cached script mount is idempotent; cleanup removes listeners and rejects late async responses.
    f = fixture(); await settle(); f.run(); await settle(); equal(f.calls.length, 1, 'cached script does not double mount');
    check(typeof f.cleanup === 'function', 'SPA cleanup registered');
    f.cleanup(); f.nodes.toggle.fire('click'); f.nodes.refresh.fire('click'); await settle();
    equal(f.calls.length, 1, 'destroy removes event handlers');
    equal(f.timers.size, 0, 'cleanup removes periodic timer');
    let release;
    f = fixture(); f.queue.length = 0;
    await settle(); // the first successful initial fetch has already captured its response
    f.queue.push(() => new Promise(resolve => { release = resolve; }));
    f.nodes.refresh.fire('click'); await settle();
    check(typeof release === 'function', 'held read started');
    f.cleanup(); const before = f.nodes.status.textContent;
    release(response(state({policy:enabled, status:'ACTIVE', effective:true}))); await settle();
    equal(f.nodes.status.textContent, before, 'destroyed read cannot update stale page');

    // A late successful save cannot remount or rewrite a page left through SPA navigation.
    f = fixture(); await settle();
    f.queue.push(() => new Promise(resolve => { release = resolve; }));
    f.nodes.toggle.fire('click'); await settle();
    f.cleanup(); const previousToggle = f.nodes.toggle.textContent, previousDispatch = f.nodes['dispatch-start'].value;
    release(response(state({policy:enabled, status:'ACTIVE', effective:true, revision:1}))); await settle();
    equal(f.nodes.toggle.textContent, previousToggle, 'late mutation response cannot touch unmounted panel');
    equal(f.nodes['dispatch-start'].value, previousDispatch, 'late mutation cannot rewrite detached dispatch schedule');
    equal(f.calls.length, 2, 'late save does not create retry requests');

    f = fixture(state(), {config:{lang:'en'}}); await settle();
    equal(f.nodes.toggle.textContent, 'Enable AI replies and analysis', 'English label supported');
    equal(f.nodes.save.textContent, 'Save settings', 'English save label supported');
    equal(f.nodes['dispatch-status'].textContent, 'Order dispatch is off', 'English dispatch status supported');
    f.window.DashboardMetaAiControls.destroy(); f.queue.push(response(state({revision:Number.MAX_SAFE_INTEGER})));
    f.window.DashboardMetaAiControls.mount(); await settle();
    check(!f.nodes.toggle.disabled, 'maximum safe revision accepted');
    f.queue.push(response(state({revision:Number.MAX_SAFE_INTEGER})));
    f.nodes.toggle.fire('click');
    equal(f.nodes.save.textContent, 'Saving settings…', 'English pending save label supported');
    await settle();
    equal(f.nodes.save.textContent, 'Save settings', 'English completed save label restored');
    equal(sent(f).expected_revision, Number.MAX_SAFE_INTEGER, 'revision integer preserved exactly');

    // Idle status changes at the next schedule boundary without a manual refresh.
    const scheduled = policy(); scheduled.enabled = true; scheduled.schedule.enabled = true;
    f = fixture(state({policy:scheduled, status:'ACTIVE', effective:true, revision:2})); await settle();
    equal(f.timers.size, 1, 'one status interval mounted');
    const timer = [...f.timers.values()][0]; equal(timer.delay, 30000, 'bounded 30-second status refresh');
    f.queue.push(response(state({policy:scheduled, status:'SCHEDULED_OFF', effective:false, revision:2, local_time:'2026-10-14T23:00:00+03:00'})));
    timer.callback(); await settle();
    equal(f.nodes.status.textContent, 'AI خارج مواعيد التشغيل', 'periodic read updates schedule boundary status');
    equal(f.calls[1].options.method, undefined, 'background status is read only');
    f.nodes.start.value = '10:15'; f.nodes.start.fire('input');
    timer.callback(); f.window.fire('focus'); f.document.fire('visibilitychange'); await settle();
    equal(f.calls.length, 2, 'dirty inputs suppress background reads');
    equal(f.nodes.start.value, '10:15', 'unsaved edits preserved across background triggers');
    f.queue.push(response(state({policy:scheduled, status:'ACTIVE', effective:true, revision:3})));
    f.nodes.refresh.fire('click'); await settle();
    equal(f.nodes.start.value, '09:00', 'explicit refresh may replace unsaved edits');
    f.document.hidden = true; timer.callback(); await settle(); equal(f.calls.length, 3, 'hidden document does not poll');
    f.document.hidden = false; f.queue.push(response(state({policy:scheduled, status:'SCHEDULED_OFF', effective:false, revision:3})));
    f.document.fire('visibilitychange'); await settle();
    equal(f.calls.length, 4, 'returning to visible page refreshes idle status');
    f.queue.push(response(state({policy:scheduled, status:'ACTIVE', effective:true, revision:3})));
    f.window.fire('focus'); await settle(); equal(f.calls.length, 5, 'focus refreshes idle status');
    f.nodes['external-paused'].checked = true; f.nodes['external-paused'].fire('change');
    timer.callback(); await settle(); equal(f.calls.length, 5, 'takeover attestation is also an unsaved edit');
    f.cleanup(); equal(f.timers.size, 0, 'background timer removed on final cleanup');
    f.window.fire('focus'); f.document.fire('visibilitychange'); timer.callback(); await settle();
    equal(f.calls.length, 5, 'no focus/visibility/timer reads after cleanup');

    // Per-channel readiness is separate from schedule policy, with fixed reason labels.
    f = fixture(state({policy:enabled, status:'ACTIVE', effective:true, handoff_method:'OWNER_ATTESTED', handoff_required:false,
        channels_state:[{channel:'whatsapp', effective:true, reason:null},
            {channel:'messenger', effective:false, reason:'ACCOUNT_UNAVAILABLE'},
            {channel:'instagram', effective:false, reason:'<img src=x onerror=alert(1)>'}]})); await settle();
    equal(f.nodes['channel-status'].children.length, 3, 'selected channels have individual status');
    check(f.nodes['channel-status'].textContent.includes('واتساب: مسموح بالمعالجة حسب الإعدادات'), 'channel policy avoids model readiness or actual execution claim');
    check(f.nodes['channel-status'].textContent.includes('ماسنجر: ربط القناة غير متاح'), 'fixed account failure reason');
    check(f.nodes['channel-status'].textContent.includes('إنستجرام: الجاهزية غير متاحة'), 'unknown reason safely generalized');
    check(!f.nodes['channel-status'].textContent.includes('<img'), 'remote reason never displayed');
    check(f.nodes['handoff-state'].textContent.includes('بإقرار المالك'), 'owner attestation clearly identified');
    f.queue.push(response(state({handoff_method:'VERIFIED_WHATSAPP_API', handoff_required:false})));
    f.nodes.refresh.fire('click'); await settle();
    check(f.nodes['handoff-state'].textContent.includes('عبر الاتصال بميتا'), 'verified API handoff distinct from attestation');
    f.queue.push(response(state({handoff_method:null, handoff_required:true})));
    f.nodes.refresh.fire('click'); await settle();
    check(f.nodes['handoff-state'].textContent.includes('يلزم إيقاف'), 'required handoff explicit');
    for (const change of [{channels_state:[{channel:'other', effective:false, reason:null}]},
        {channels_state:[{channel:'whatsapp', effective:true, reason:null}]},
        {channels_state:[{channel:'whatsapp', effective:false, reason:null}, {channel:'whatsapp', effective:false, reason:null}]},
        {handoff_method:'UNVERIFIED'}, {handoff_required:'true'}]) {
        f = fixture(state(change)); await settle();
        check(f.nodes.toggle.disabled && f.nodes.settings.hidden, 'malformed readiness state fails closed');
    }

    const blade = fs.readFileSync(path.join(__dirname, '../resources/views/admin/meta/ai_controls.blade.php'), 'utf8');
    const channelView = fs.readFileSync(path.join(__dirname, '../resources/views/admin/meta/channels.blade.php'), 'utf8');
    check(blade.includes("Route::has('meta-ai-control.state')") && blade.includes("Route::has('meta-ai-control.update')"), 'missing routes hide partial before migration');
    check(blade.includes('JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT'), 'bootstrap encoded for script context');
    check(blade.includes('القنوات ومواعيد الردود وإرسال الطلبات') && blade.includes('Channels, reply hours and order dispatch hours'), 'settings summary names the separate reply and dispatch hours in both languages');
    check(blade.includes('تحديد مواعيد ردود وتحليل AI') && blade.includes('Schedule AI replies and analysis'), 'schedule switch explains that it controls replies and analysis');
    const unansweredNote = blade.match(/<p[^>]* data-meta-ai-unanswered-note>([\s\S]*?)<\/p>/);
    check(unansweredNote && unansweredNote[1].includes('يراجع الرسائل التي لم يُرد عليها') && unansweredNote[1].includes('AI reviews unanswered messages'), 'enable note includes existing unanswered messages in both languages');
    check(unansweredNote[1].includes('ما دامت مهلة الرد على المنصة تسمح بذلك') && unansweredNote[1].includes('while the platform reply window permits'), 'unanswered message note preserves platform reply limits');
    check(unansweredNote[1].includes('الردود وطلب البيانات الناقصة والتحليل تعمل حسب مواعيد الردود') && unansweredNote[1].includes('requests for missing information and analysis follow the reply hours'), 'missing information and analysis follow the selected reply schedule');
    const dispatchNote = blade.match(/<p[^>]* data-meta-ai-dispatch-note>([\s\S]*?)<\/p>/);
    check(dispatchNote && dispatchNote[1].includes('الموعد الافتراضي يوميًا من 9 صباحًا إلى 12:30 بعد منتصف الليل (09:00–00:30) بتوقيت القاهرة') && dispatchNote[1].includes('The default is daily from 9:00 AM to 12:30 AM after midnight (09:00–00:30), Cairo time'), 'default dispatch hours state the editable overnight Cairo boundary in both languages');
    check(dispatchNote[1].includes('بعد تأكيد العميل') && dispatchNote[1].includes('after customer confirmation'), 'dispatch hours never imply bypassing customer confirmation');
    equal((blade.match(/data-meta-ai-start/g) || []).length, 1, 'one existing configurable reply start field remains');
    equal((blade.match(/data-meta-ai-end/g) || []).length, 1, 'one existing configurable reply end field remains');
    check(blade.includes('جدول إرسال الطلبات مستقل عن جدول الردود والتحليل') && blade.includes('Order dispatch has its own schedule, separate from replies and analysis'), 'dispatch scheduling independence is explained in both languages');
    check(blade.includes('عند إيقاف جدولة الإرسال، يمكن إرسال الطلبات طوال اليوم') && blade.includes('Turning off dispatch scheduling allows orders to be sent all day'), 'turning off dispatch scheduling has an explicit all-day meaning');
    for (const field of ['schedule','all-day','start','end','day','status']) {
        equal((blade.match(new RegExp('data-meta-ai-dispatch-' + field + '(?:[ >])', 'g')) || []).length, 1, 'dispatch form has its own unique ' + field + ' field');
    }
    equal((channelView.match(/@include\('admin\.meta\.ai_controls'\)/g) || []).length, 1, 'shared channel navigation includes AI controls exactly once');
    for (const channel of ['', 'whatsapp', 'messenger', 'instagram']) {
        equal((channelView.match(new RegExp('data-hub-channel="' + channel + '"', 'g')) || []).length, 1,
            'shared template retains the unique hub control for ' + (channel || 'all channels'));
    }
    for (const file of ['meta/index.blade.php','whatsapp/index.blade.php']) {
        check(fs.readFileSync(path.join(__dirname, '../resources/views/admin/', file), 'utf8').includes("@include('admin.meta.channels'"), 'both inboxes receive shared controls');
    }
    console.log('Meta AI controls UI: ' + checks + ' checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
