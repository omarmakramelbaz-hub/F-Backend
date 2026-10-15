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
    return {enabled: false, mode: 'ANALYZE_ONLY', channels: ['whatsapp', 'messenger', 'instagram'],
        schedule: {enabled: false, days: [0,1,2,3,4,5,6], start: '09:00', end: '23:00', all_day: false},
        dispatch_schedule: {enabled: true, days: [0,1,2,3,4,5,6], start: '09:00', end: '00:30', all_day: false}};
}
function state(change = {}) {
    const value = Object.assign({available: true, policy: policy(), revision: 0, effective: false, status: 'DISABLED', can_manage: true,
        updated_at_utc: null, local_time: '2026-10-14T21:00:00+03:00'}, change);
    if (!Object.prototype.hasOwnProperty.call(change, 'analysis_state')) value.analysis_state = {effective:value.effective, status:value.status, local_time:value.local_time};
    if (!Object.prototype.hasOwnProperty.call(change, 'reply_state')) value.reply_state = {
        effective: value.policy && value.policy.mode === 'ANALYZE_ONLY' ? false : value.effective,
        status: !value.policy || !value.policy.enabled ? 'DISABLED' : value.policy.mode === 'ANALYZE_ONLY' ? 'ANALYZE_ONLY' : value.status, local_time:value.local_time};
    if (!Object.prototype.hasOwnProperty.call(change, 'dispatch_state')) value.dispatch_state = {
        effective:value.effective, status:value.status, local_time:value.local_time};
    return value;
}
function response(value, status = 200, options = {}) {
    return Object.assign({ok: status >= 200 && status < 300, status, redirected: false,
        headers: {get: () => 'application/json'}, json: async () => value}, options);
}
function fixture(first = state(), changes = {}) {
    const nodes = {};
    ['toggle','save','refresh','form','status','message','settings','current-time','schedule','all-day','start','end','channel-status',
        'dispatch-status','dispatch-schedule','dispatch-all-day','dispatch-start','dispatch-end',
        'analysis-status','analysis-note','compatibility-note','analysis-settings']
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
    const processingOn = 'معالجة الرسائل والطلبات مفعّلة حسب الجدول';
    const processingOff = 'معالجة الرسائل والطلبات متوقفة';
    const processingScheduledOff = 'معالجة الرسائل والطلبات خارج المواعيد';
    const clock = '2026-10-14T21:00:00+03:00';
    const purpose = (status, local_time = clock) => ({effective:status === 'ACTIVE', status, local_time});
    const enabled = policy(); enabled.enabled = true;
    let f = fixture(); await settle();
    equal(f.calls.length, 1, 'one initial GET');
    equal(f.calls[0].url, 'https://dashboard.example/admin/meta/ai-control', 'same origin endpoint');
    equal(f.calls[0].options.credentials, 'same-origin', 'session credentials scoped');
    equal(f.calls[0].options.cache, 'no-store', 'policy never cached');
    equal(f.calls[0].options.redirect, 'error', 'no sign-in redirect accepted');
    check(!f.calls[0].options.method && !f.calls[0].options.body, 'initial read never mutates');
    check(!f.panel.hidden && !f.nodes.settings.hidden, 'valid state revealed');
    equal(f.nodes.toggle.textContent, 'تشغيل تحليل وإرسال الطلبات', 'only analysis activation is offered');
    equal(f.nodes.status.textContent, processingOff, 'disabled processing status');
    equal(f.nodes['analysis-status'].textContent, 'تحليل الرسائل متوقف', 'analysis status shown independently');
    equal(f.nodes['dispatch-status'].textContent, 'إرسال الطلبات متوقف', 'dispatch master off');
    check(!f.nodes.toggle.disabled && !f.nodes.save.disabled, 'owner controls usable');
    check(f.channels.every(input => input.checked), 'saved channel selection rendered');
    check(f.days.every(input => input.disabled) && f.nodes.start.disabled && f.nodes.end.disabled, 'dormant analysis schedule disabled');
    check(f.dispatchDays.every(input => input.checked && !input.disabled), 'dispatch days independently editable');
    check(!f.nodes['dispatch-start'].disabled, 'dispatch clocks editable while analysis scheduling off');
    equal([f.nodes['dispatch-start'].value, f.nodes['dispatch-end'].value], ['09:00','00:30'], 'dispatch preserves overnight default');
    equal(f.nodes['current-time'].textContent, 'الوقت في القاهرة: 2026-10-14 21:00', 'server Cairo time shown');
    check(f.nodes['compatibility-note'].hidden, 'saved analysis mode has no compatibility warning');

    // Old saved modes are observed without a write. Every explicit save or activation adopts analysis-only.
    const legacyFull = policy(); legacyFull.mode = 'REPLY_AND_ANALYZE';
    const legacyFour = JSON.parse(JSON.stringify(legacyFull)); delete legacyFour.mode;
    for (const generation of [3,4,5]) {
        const previous = JSON.parse(JSON.stringify(generation === 5 ? legacyFull : legacyFour));
        if (generation === 3) delete previous.dispatch_schedule;
        previous.schedule = {enabled:true, days:[2,5], start:'22:00', end:'04:30', all_day:true};
        const previousState = state({policy:previous, revision:10});
        if (generation < 5) { delete previousState.reply_state; delete previousState.analysis_state; }
        if (generation === 3) delete previousState.dispatch_state;
        const old = fixture(previousState); await settle();
        equal(old.calls.length, 1, 'legacy ' + generation + '-key GET performs no migration');
        check(!old.nodes['compatibility-note'].hidden && old.nodes['compatibility-note'].textContent.includes('لم يتغير الوضع'), 'legacy role truthfully remains unchanged until save: ' + generation);
        equal([old.nodes.start.value,old.nodes.end.value], ['22:00','04:30'], 'legacy schedule clocks preserved: ' + generation);
        check(old.nodes.schedule.checked && old.nodes['all-day'].checked, 'legacy schedule raw flags preserved: ' + generation);
        check(!old.nodes.schedule.disabled && old.days.filter(input => input.checked).every(input => !input.disabled), 'analysis schedule editable for explicit conversion: ' + generation);
        if (generation < 5) equal(old.nodes['analysis-status'].textContent, 'حالة تحليل الرسائل غير متاحة حاليًا', 'legacy missing purpose state is never guessed');
        const converted = JSON.parse(JSON.stringify(previous)); converted.mode = 'ANALYZE_ONLY';
        if (generation === 3) converted.dispatch_schedule = policy().dispatch_schedule;
        old.queue.push(response(state({policy:converted, revision:11})));
        old.nodes.form.fire('submit'); await settle();
        equal(sent(old), {policy:converted, expected_revision:10, external_agent_paused:false}, 'explicit save converts legacy role without changing untouched schedule or activating: ' + generation);
        check(old.nodes['compatibility-note'].hidden, 'confirmed role conversion clears compatibility note: ' + generation);
    }
    // Reading enabled legacy replies must never claim they have stopped, or silently change that mode.
    const enabledFull = JSON.parse(JSON.stringify(legacyFull)); enabledFull.enabled = true;
    const activeConversion = fixture(state({policy:enabledFull,effective:true,status:'ACTIVE',revision:4})); await settle();
    const convertedActive = JSON.parse(JSON.stringify(enabledFull)); convertedActive.mode = 'ANALYZE_ONLY';
    activeConversion.queue.push(response(state({policy:convertedActive,effective:true,status:'ACTIVE',revision:5})));
    activeConversion.nodes.form.fire('submit'); await settle();
    equal(sent(activeConversion),{policy:convertedActive,expected_revision:4,external_agent_paused:false},'explicit SAVE converts an enabled legacy role while preserving activation and untouched schedules');
    check(activeConversion.nodes['compatibility-note'].hidden,'only successful active conversion clears the old reply-mode notice');
    let legacy = fixture(state({policy:enabledFull, effective:true,status:'ACTIVE',revision:5})); await settle();
    equal(legacy.calls.length, 1, 'enabled full role GET does not mutate');
    check(!legacy.nodes['compatibility-note'].hidden && legacy.nodes['compatibility-note'].textContent.includes('يتضمن ردود'), 'enabled full role remains visible as a compatibility state');
    equal(legacy.nodes.toggle.textContent, 'إيقاف AI', 'emergency stop names all existing AI processing');
    legacy.nodes.start.value = 'invalid'; legacy.nodes['dispatch-start'].value = 'invalid'; legacy.channels.forEach(input => { input.checked = false; });
    const stoppedFull = JSON.parse(JSON.stringify(enabledFull)); stoppedFull.enabled = false;
    legacy.queue.push(response(state({policy:stoppedFull,revision:6})));
    legacy.nodes.toggle.fire('click'); await settle();
    equal(sent(legacy), {policy:stoppedFull,expected_revision:5,external_agent_paused:false}, 'emergency OFF clones persisted legacy policy and bypasses invalid dirty edits');
    const newlyEnabledAnalysis = JSON.parse(JSON.stringify(stoppedFull)); newlyEnabledAnalysis.enabled = true; newlyEnabledAnalysis.mode = 'ANALYZE_ONLY';
    legacy.queue.push(response(state({policy:newlyEnabledAnalysis,revision:7,effective:true,status:'ACTIVE'})));
    legacy.nodes.toggle.fire('click'); await settle();
    equal(sent(legacy), {policy:newlyEnabledAnalysis,expected_revision:6,external_agent_paused:false}, 'subsequent explicit enable can only activate analysis-only');

    // Analysis schedule edits are collected rather than cloned from the old read-only reply schedule.
    let separate = fixture(); await settle();
    separate.nodes.schedule.checked = true; separate.nodes.schedule.fire('change');
    separate.nodes.start.value = '21:00'; separate.nodes.end.value = '06:00';
    separate.days.forEach(input => { input.checked = ['1','3'].includes(input.value); });
    separate.nodes['dispatch-start'].value = '10:15'; separate.nodes['dispatch-end'].value = '00:45';
    separate.dispatchDays.forEach(input => { input.checked = ['2','6'].includes(input.value); });
    const separatePolicy = {enabled:false, mode:'ANALYZE_ONLY', channels:policy().channels,
        schedule:{enabled:true,days:[1,3],start:'21:00',end:'06:00',all_day:false},
        dispatch_schedule:{enabled:true,days:[2,6],start:'10:15',end:'00:45',all_day:false}};
    separate.queue.push(response(state({policy:separatePolicy,revision:2})));
    separate.nodes.form.fire('submit'); await settle();
    equal(sent(separate), {policy:separatePolicy,expected_revision:0,external_agent_paused:false}, 'one explicit atomic save retains two independent editable schedules and leaves master off');
    equal(separate.days.filter(input => input.checked).map(input => Number(input.value)), [1,3], 'analysis days retained');
    equal(separate.dispatchDays.filter(input => input.checked).map(input => Number(input.value)), [2,6], 'dispatch days retained');
    check(!separate.nodes.start.disabled && !separate.nodes['dispatch-start'].disabled, 'both enabled schedule clocks editable');

    const separateEnabled = JSON.parse(JSON.stringify(separatePolicy)); separateEnabled.enabled = true;
    separate.queue.push(response(state({policy:separateEnabled,revision:3,effective:true,status:'ACTIVE',
        analysis_state:purpose('SCHEDULED_OFF'),dispatch_state:purpose('ACTIVE')})));
    separate.nodes.toggle.fire('click'); await settle();
    equal(sent(separate).policy, separateEnabled, 'enable uses the visible independent schedules');
    equal(separate.nodes.status.textContent, processingOn, 'processing remains active when dispatch alone is open');
    equal(separate.nodes['analysis-status'].textContent, 'تحليل الرسائل خارج مواعيد التحليل', 'analysis purpose state describes closed analysis hours');
    equal(separate.nodes['dispatch-status'].textContent, 'إرسال الطلبات مفعّل حسب الجدول', 'dispatch-only readiness remains separately clear');
    for (const [analysisState,dispatchState,overall] of [['ACTIVE','SCHEDULED_OFF','ACTIVE'],['SCHEDULED_OFF','SCHEDULED_OFF','SCHEDULED_OFF'],['ACTIVE','ACTIVE','ACTIVE']]) {
        separate.queue.push(response(state({policy:separateEnabled,revision:3,effective:overall === 'ACTIVE',status:overall,
            analysis_state:purpose(analysisState),dispatch_state:purpose(dispatchState)})));
        separate.nodes.refresh.fire('click'); await settle();
        check(!separate.nodes.settings.hidden, 'public independent clock states remain usable: ' + analysisState + '/' + dispatchState);
        equal(separate.nodes.status.textContent, overall === 'ACTIVE' ? processingOn : processingScheduledOff, 'overall status uses processing union');
        equal(separate.nodes['analysis-status'].textContent, analysisState === 'ACTIVE' ? 'تحليل الرسائل مفعّل حسب الجدول' : 'تحليل الرسائل خارج مواعيد التحليل', 'analysis purpose status exact');
        equal(separate.nodes['dispatch-status'].textContent, dispatchState === 'ACTIVE' ? 'إرسال الطلبات مفعّل حسب الجدول' : 'إرسال الطلبات خارج مواعيد الإرسال', 'dispatch purpose status exact');
    }
    separate.nodes['all-day'].checked = true; separate.nodes['all-day'].fire('change');
    check(separate.nodes.start.disabled && !separate.nodes['dispatch-start'].disabled, 'analysis all-day disables only its clocks');
    separate.nodes['all-day'].checked = false; separate.nodes['all-day'].fire('change');
    separate.nodes['dispatch-all-day'].checked = true; separate.nodes['dispatch-all-day'].fire('change');
    check(!separate.nodes.start.disabled && separate.nodes['dispatch-start'].disabled, 'dispatch all-day disables only its clocks');
    check(separate.dispatchDays.every(input => !input.disabled), 'all-day dispatch still uses selected days');
    separate.nodes['dispatch-schedule'].checked = false; separate.nodes['dispatch-schedule'].fire('change');
    check(separate.dispatchDays.every(input => input.disabled) && separate.nodes['dispatch-all-day'].disabled, 'dispatch scheduling off disables only its form');
    check(!separate.nodes.start.disabled && separate.days.every(input => !input.disabled), 'analysis stays independently editable');
    const unrestricted = JSON.parse(JSON.stringify(separateEnabled)); unrestricted.dispatch_schedule.enabled = false; unrestricted.dispatch_schedule.all_day = true;
    separate.queue.push(response(state({policy:unrestricted,revision:4,effective:true,status:'ACTIVE'})));
    separate.nodes.form.fire('submit'); await settle();
    equal(sent(separate).policy, unrestricted, 'unscheduling retains saved clocks, days and all-day flag exactly');
    separate.nodes.start.value = 'invalid'; separate.nodes['dispatch-start'].value = 'invalid'; separate.channels.forEach(input => {input.checked = false;});
    const stopped = JSON.parse(JSON.stringify(unrestricted)); stopped.enabled = false;
    separate.queue.push(response(state({policy:stopped,revision:5})));
    separate.nodes.toggle.fire('click'); await settle();
    equal(sent(separate), {policy:stopped,expected_revision:4,external_agent_paused:false}, 'analysis emergency OFF ignores all dirty invalid edits and preserves both schedules');

    // Inflight old replies cannot cause an implicit role switch, reload or duplicate send.
    for (const pendingStatus of [409,422]) {
        const pending = fixture(state({policy:enabledFull,revision:8,effective:true,status:'ACTIVE'})); await settle();
        pending.nodes.start.value = '10:15'; pending.nodes.start.fire('input');
        pending.queue.push(response({error:'REPLIES_PENDING'},pendingStatus));
        pending.nodes.form.fire('submit'); await settle();
        equal(pending.calls.length, 2, 'pending replies cause no retry or GET: ' + pendingStatus);
        equal(sent(pending).policy.mode, 'ANALYZE_ONLY', 'pending transition cannot request full replies');
        check(!sent(pending).external_agent_paused, 'pending transition sends no takeover claim');
        check(pending.nodes.message.textContent.includes('لم تتأكد نتيجتها') && pending.nodes.settings.open, 'uncertain old send explained without resend');
        check(!pending.nodes['compatibility-note'].hidden, 'rejected transition keeps persisted full mode visible');
        check(!pending.nodes.save.disabled, 'rejected conversion stays editable');
        [...pending.timers.values()][0].callback(); await settle();
        equal(pending.calls.length, 2, 'dirty rejected conversion is not discarded by polling');
    }

    // Actual shared submit-spinner callback is tested in both listener orders.
    for (const order of ['before','after']) {
        let legacyForm = fixture(state(),{legacySubmit:order}); await settle();
        let finishSave; legacyForm.queue.push(() => new Promise(resolve => {finishSave = resolve;}));
        const submitted = legacyForm.nodes.form.fire('submit');
        check(submitted.prevented && submitted.immediateStopped, 'form owns async submit: ' + order);
        equal(legacyForm.nodes.save.textContent,'جارٍ حفظ الإعدادات…','pending save label: ' + order);
        check(legacyForm.nodes.save.disabled,'pending save disabled: ' + order);
        legacyForm.nodes.form.fire('submit'); await settle();
        equal(legacyForm.calls.filter(call => call.options.method === 'POST').length,1,'double submit posts once: ' + order);
        finishSave(response(state({revision:1}))); await settle();
        equal(legacyForm.nodes.save.textContent,'حفظ الإعدادات','save completion restores label: ' + order);
        check(!legacyForm.nodes.save.disabled && legacyForm.nodes.message.textContent.includes('تم حفظ'),'completion visible: ' + order);
        equal(legacyForm.legacySubmitCalls,order === 'before' ? 2 : 0,'earlier spinner overwritten and later spinner stopped: ' + order);
        for (const failure of ['network','http','pending','conflict']) {
            legacyForm = fixture(state(),{legacySubmit:order}); await settle();
            if (failure === 'network') legacyForm.queue.push(new Error('offline'));
            if (failure === 'http') legacyForm.queue.push(response({},500));
            if (failure === 'pending') legacyForm.queue.push(response({error:'REPLIES_PENDING'},409));
            if (failure === 'conflict') legacyForm.queue.push(response({error:'STALE_REVISION'},409),response(state({revision:3})));
            legacyForm.nodes.form.fire('submit'); await settle();
            equal(legacyForm.nodes.save.textContent,'حفظ الإعدادات',failure + ' restores label: ' + order);
            equal(legacyForm.calls.filter(call => call.options.method === 'POST').length,1,failure + ' never retries mutation: ' + order);
            equal(legacyForm.calls.length,failure === 'conflict' ? 3 : 2,failure + ' only conflict reloads: ' + order);
            equal(legacyForm.nodes.save.disabled,failure === 'network' || failure === 'http',failure + ' correct permissions: ' + order);
        }
        legacyForm = fixture(state({policy:enabled,effective:true,status:'ACTIVE'}),{legacySubmit:order}); await settle();
        legacyForm.channels.forEach(input => {input.checked = false;}); legacyForm.nodes.form.fire('submit'); await settle();
        equal(legacyForm.calls.length,1,'invalid form never posts: ' + order);
        equal(legacyForm.nodes.save.textContent,'حفظ الإعدادات','validation restores save label: ' + order);
        check(!legacyForm.nodes.save.disabled && legacyForm.nodes.message.textContent.includes('راجع'),'invalid form stays editable: ' + order);
    }

    // Exact owner-approved scope, CSRF, revision and no duplicate activation.
    f = fixture(); await settle();
    f.channels[1].checked = false; f.channels[2].checked = false;
    f.nodes.schedule.checked = true; f.nodes.start.value = '22:00'; f.nodes.end.value = '03:00';
    f.days.forEach(input => {input.checked = input.value === '5';});
    const selected = {enabled:true,mode:'ANALYZE_ONLY',channels:['whatsapp'],schedule:{enabled:true,days:[5],start:'22:00',end:'03:00',all_day:false},dispatch_schedule:policy().dispatch_schedule};
    f.queue.push(response(state({policy:selected,revision:1,effective:true,status:'ACTIVE'})));
    f.nodes.toggle.fire('click'); check(f.nodes.save.disabled && f.nodes.toggle.disabled,'activation disables controls immediately');
    f.nodes.toggle.fire('click'); await settle();
    equal(f.calls.length,2,'double activation sends one POST');
    equal(sent(f),{policy:selected,expected_revision:0,external_agent_paused:false},'activation sends exact analysis role, scope and hours without pause attestation');
    equal(f.calls[1].options.headers['X-CSRF-TOKEN'],'fixture-csrf','CSRF header');
    equal(f.calls[1].options.headers['Content-Type'],'application/json','JSON body');
    equal(f.nodes.toggle.attributes['aria-pressed'],'true','pressed follows saved master');
    equal(f.nodes.toggle.textContent,'إيقاف تحليل وإرسال الطلبات','active stop labels analysis role');
    f.nodes.start.value = '21:45'; f.nodes.start.fire('input');
    const edited = JSON.parse(JSON.stringify(selected)); edited.schedule.start = '21:45';
    f.queue.push(response(state({policy:edited,revision:2,status:'SCHEDULED_OFF',effective:false})));
    check(f.nodes.form.fire('submit').prevented,'form does not navigate'); await settle();
    equal(sent(f),{policy:edited,expected_revision:1,external_agent_paused:false},'save captures current analysis edits and revision');
    const newer = policy(); newer.channels = ['messenger'];
    f.queue.push(response({reason:'STALE_REVISION'},409),response(state({policy:newer,revision:7})));
    f.nodes.form.fire('submit'); await settle();
    equal(f.calls.slice(-2).map(call => call.options.method || 'GET'),['POST','GET'],'conflict reloads once and never retries mutation');
    check(f.nodes.message.textContent.includes('جلسة أخرى') && f.channels[1].checked && !f.channels[0].checked,'conflict shows latest server state');
    f.queue.push(response(state({policy:enabled,revision:8,status:'ACTIVE',effective:true})));
    f.nodes.toggle.fire('click'); await settle(); equal(sent(f).expected_revision,7,'next explicit operation uses reloaded revision');

    // Read-only roles cannot edit or force a POST with synthetic events.
    f = fixture(state({can_manage:false})); await settle();
    check(f.nodes.save.disabled && f.nodes.toggle.disabled && f.channels.every(input => input.disabled),'read-only controls disabled');
    check(['schedule','all-day','start','end','dispatch-schedule','dispatch-all-day','dispatch-start','dispatch-end'].every(name => f.nodes[name].disabled),'both schedule forms read-only');
    f.nodes.form.fire('submit'); f.nodes.toggle.fire('click'); await settle();
    equal(f.calls.length,1,'read-only synthetic events never POST'); check(f.nodes.message.textContent.includes('عرض فقط'),'owner-only explanation');

    // Strict response contract: malformed purpose states cannot arm any mutation.
    const invalid = [state({revision:-1}),state({revision:1.5}),state({revision:'1'}),state({revision:Number.MAX_SAFE_INTEGER+1}),
        state({can_manage:1}),state({effective:true}),state({status:'UNKNOWN'}),state({local_time:'<img onerror=alert(1)>'}),
        state({policy:{...policy(),extra:true}}),state({policy:{...policy(),enabled:'true'}}),
        ...[null,'','analyze_only','REPLY_ONLY',true,['ANALYZE_ONLY'],{mode:'ANALYZE_ONLY'}].map(mode => state({policy:{...policy(),mode}})),
        state({policy:{...policy(),channels:['whatsapp','whatsapp']}}),
        ...['schedule','dispatch_schedule'].flatMap(key => [null,{...policy()[key],days:['1']},{...policy()[key],days:[1,1]},
            {...policy()[key],enabled:true,days:[]},{...policy()[key],end:'24:00'},{...policy()[key],end:'09:00'},
            {...policy()[key],all_day:1},{...policy()[key],extra:true}].map(value => state({policy:{...policy(),[key]:value}}))),
        ...['analysis_state','dispatch_state'].flatMap(key => [null,purpose('UNKNOWN'),purpose('ANALYSIS_OUTSIDE_HOURS'),
            {...purpose('DISABLED'),effective:true},{...purpose('DISABLED'),effective:'false'},
            {...purpose('DISABLED'),local_time:'2026-10-14T21:00:00Z'},{...purpose('DISABLED'),extra:true}].map(value => state({[key]:value}))),
        ...[null,purpose('UNKNOWN'),{...purpose('DISABLED'),effective:true},purpose('ANALYZE_ONLY'),
            {...purpose('DISABLED'),local_time:'2026-10-14T21:00:00Z'},{...purpose('DISABLED'),extra:true}].map(reply_state => state({reply_state})),
        state({policy:enabled,effective:true,status:'ACTIVE',reply_state:purpose('ACTIVE')}),
        state({policy:enabled,effective:true,status:'ACTIVE',analysis_state:purpose('SCHEDULED_OFF'),dispatch_state:purpose('SCHEDULED_OFF')}),
        state({policy:enabled,effective:false,status:'SCHEDULED_OFF',analysis_state:purpose('ACTIVE')}),
        state({policy:enabled,effective:false,status:'SCHEDULED_OFF',dispatch_state:purpose('ACTIVE')}),{},null];
    for (const key of ['analysis_state','reply_state','dispatch_state']) {const missing = state(); delete missing[key]; invalid.push(missing);}
    const mixed = policy(); delete mixed.dispatch_schedule; invalid.push(state({policy:mixed}));
    for (const value of invalid) {
        f = fixture(value); await settle();
        check(f.nodes.toggle.disabled && f.nodes.settings.hidden,'malformed response fails closed');
        f.nodes.toggle.fire('click'); f.nodes.form.fire('submit'); await settle(); equal(f.calls.length,1,'malformed state never mutates');
    }
    f = fixture({available:false,reason:'UNAVAILABLE'}); await settle();
    check(f.nodes.toggle.disabled && f.nodes.settings.hidden,'missing schema unavailable fails closed');
    f.nodes.toggle.fire('click'); f.nodes.form.fire('submit'); await settle(); equal(f.calls.length,1,'unavailable never mutates');

    // Invalid edits, including either schedule independently, never reach the endpoint.
    for (const invalidEdit of ['channels','analysis-days','analysis-equal','analysis-time','dispatch-days','dispatch-equal','dispatch-time']) {
        f = fixture(state({policy:enabled,effective:true,status:'ACTIVE'})); await settle();
        if (invalidEdit === 'channels') f.channels.forEach(input => {input.checked = false;});
        if (invalidEdit.startsWith('analysis-')) f.nodes.schedule.checked = true;
        if (invalidEdit === 'analysis-days') f.days.forEach(input => {input.checked = false;});
        if (invalidEdit === 'analysis-equal') f.nodes.end.value = f.nodes.start.value;
        if (invalidEdit === 'analysis-time') f.nodes.start.value = 'bad';
        if (invalidEdit === 'dispatch-days') f.dispatchDays.forEach(input => {input.checked = false;});
        if (invalidEdit === 'dispatch-equal') f.nodes['dispatch-end'].value = f.nodes['dispatch-start'].value;
        if (invalidEdit === 'dispatch-time') f.nodes['dispatch-start'].value = 'bad';
        f.nodes.form.fire('submit'); await settle(); equal(f.calls.length,1,invalidEdit + ' never posts');
        check(f.nodes.message.textContent.includes('راجع') && !f.nodes.save.disabled,invalidEdit + ' explained with form usable');
        equal(f.nodes.save.textContent,'حفظ الإعدادات',invalidEdit + ' validation never leaves spinner');
    }

    // Uncertain saves never retry; redirect and unsafe bootstrap responses never expose credentials.
    f = fixture(); await settle(); f.queue.push(new Error('offline')); f.nodes.toggle.fire('click'); await settle();
    check(f.nodes.toggle.disabled && f.nodes.settings.hidden,'uncertain save locks mutation until new read');
    f.nodes.toggle.fire('click'); await settle(); equal(f.calls.length,2,'uncertain save never automatically retries');
    f.queue.push(response(state({revision:4}))); f.nodes.refresh.fire('click'); await settle(); check(!f.nodes.toggle.disabled,'explicit read recovers state');
    equal(f.calls[2].options.method,undefined,'recovery is GET only');
    for (const reply of [response({},500),response({},401),response(state(),200,{redirected:true}),response(state(),200,{headers:{get:() => 'text/html'}})]) {
        f = fixture(); await settle(); f.queue.push(reply); f.nodes.refresh.fire('click'); await settle();
        check(f.nodes.toggle.disabled && f.nodes.settings.hidden,'untrusted HTTP response fails closed');
    }
    for (const config of [{state_url:'https://evil.example/admin/meta/ai-control'},{update_url:'https://evil.example/admin/meta/ai-control'},
        {state_url:'/admin/meta/ai-control?token=secret'},{state_url:'/admin/meta/ai-control#x'},{state_url:'/other'},{csrf:''}]) {
        f = fixture(state(),{config}); await settle(); equal(f.calls.length,0,'unsafe bootstrap sends no requests');
    }
    for (const changes of [{current:false},{missing:true}]) {f = fixture(state(),changes); await settle(); equal(f.calls.length,0,'unrelated SPA page never requests control');}

    // Schedule edits block background reads; cleanup removes each listener and timer.
    for (const field of ['schedule','all-day','start','end','day','dispatch-schedule','dispatch-all-day','dispatch-start','dispatch-end','dispatch-day']) {
        f = fixture(); await settle();
        const input = field === 'day' ? f.days[2] : field === 'dispatch-day' ? f.dispatchDays[2] : f.nodes[field];
        input.fire(field.endsWith('start') || field.endsWith('end') ? 'input' : 'change');
        const idleTimer = [...f.timers.values()][0]; idleTimer.callback(); f.window.fire('focus'); f.document.fire('visibilitychange'); await settle();
        equal(f.calls.length,1,field + ' dirty edit prevents polling from overwriting form');
        f.cleanup(); check((input.listeners.change || []).length === 0 && (input.listeners.input || []).length === 0,field + ' listeners removed on navigation');
        input.fire('change'); input.fire('input'); idleTimer.callback(); await settle(); equal(f.calls.length,1,field + ' detached events do nothing');
    }
    f = fixture(); await settle(); f.run(); await settle(); equal(f.calls.length,1,'cached script mount idempotent');
    check(typeof f.cleanup === 'function','SPA cleanup registered'); f.cleanup(); equal(f.timers.size,0,'cleanup removes timer');
    for (const mutation of [false,true]) {
        let release; f = fixture(); await settle(); f.queue.push(() => new Promise(resolve => {release = resolve;}));
        f.nodes[mutation ? 'toggle' : 'refresh'].fire('click'); await settle(); check(typeof release === 'function','held async request started');
        const before = f.nodes.status.textContent, beforeStart = f.nodes['dispatch-start'].value; f.cleanup();
        release(response(state({policy:enabled,status:'ACTIVE',effective:true,revision:1}))); await settle();
        equal(f.nodes.status.textContent,before,'late ' + (mutation ? 'save' : 'read') + ' cannot rewrite unmounted status');
        equal(f.nodes['dispatch-start'].value,beforeStart,'late result cannot rewrite detached schedule'); equal(f.calls.length,2,'late result creates no retries');
    }
    const scheduled = JSON.parse(JSON.stringify(enabled)); scheduled.schedule.enabled = true;
    f = fixture(state({policy:scheduled,status:'ACTIVE',effective:true,revision:2})); await settle();
    const timer = [...f.timers.values()][0]; equal(timer.delay,30000,'bounded status refresh interval');
    f.queue.push(response(state({policy:scheduled,status:'SCHEDULED_OFF',effective:false,revision:2,local_time:'2026-10-14T23:00:00+03:00'})));
    timer.callback(); await settle(); equal(f.nodes.status.textContent,processingScheduledOff,'idle schedule boundary refreshes processing status');
    equal(f.nodes['analysis-status'].textContent,'تحليل الرسائل خارج مواعيد التحليل','idle schedule boundary refreshes analysis purpose');
    equal(f.calls[1].options.method,undefined,'background refresh read only');
    f.document.hidden = true; timer.callback(); await settle(); equal(f.calls.length,2,'hidden page does not poll');
    f.document.hidden = false; f.queue.push(response(state({policy:scheduled,status:'ACTIVE',effective:true,revision:3})));
    f.document.fire('visibilitychange'); await settle(); equal(f.calls.length,3,'visible page refreshes idle policy');
    f.queue.push(response(state({policy:scheduled,status:'ACTIVE',effective:true,revision:3}))); f.window.fire('focus'); await settle(); equal(f.calls.length,4,'focus refreshes idle policy');
    f.nodes.start.value = '10:15'; f.nodes.start.fire('input'); timer.callback(); await settle(); equal(f.calls.length,4,'dirty input suppresses polling');
    f.queue.push(response(state({policy:scheduled,status:'ACTIVE',effective:true,revision:4}))); f.nodes.refresh.fire('click'); await settle(); equal(f.nodes.start.value,'09:00','explicit refresh may replace dirty form');
    f.cleanup(); f.window.fire('focus'); f.document.fire('visibilitychange'); timer.callback(); await settle(); equal(f.calls.length,5,'no background events after cleanup');

    // Per-channel readiness uses fixed labels, never remote HTML or raw backend reasons.
    f = fixture(state({policy:enabled,status:'ACTIVE',effective:true,channels_state:[{channel:'whatsapp',effective:true,reason:null},
        {channel:'messenger',effective:false,reason:'ACCOUNT_UNAVAILABLE'},{channel:'instagram',effective:false,reason:'<img onerror=alert(1)>'}]})); await settle();
    equal(f.nodes['channel-status'].children.length,3,'each selected channel has status');
    check(f.nodes['channel-status'].textContent.includes('واتساب: مسموح بالمعالجة حسب الإعدادات'),'channel readiness avoids execution claim');
    check(f.nodes['channel-status'].textContent.includes('ماسنجر: ربط القناة غير متاح'),'known account reason labeled');
    check(f.nodes['channel-status'].textContent.includes('إنستجرام: الجاهزية غير متاحة') && !f.nodes['channel-status'].textContent.includes('<img'),'unknown reasons safely generalized');
    for (const change of [{channels_state:[{channel:'other',effective:false,reason:null}]},{channels_state:[{channel:'whatsapp',effective:true,reason:null}]},
        {channels_state:[{channel:'whatsapp',effective:false,reason:null},{channel:'whatsapp',effective:false,reason:null}]},{handoff_method:'UNVERIFIED'},{handoff_required:'true'}]) {
        f = fixture(state(change)); await settle(); check(f.nodes.toggle.disabled && f.nodes.settings.hidden,'malformed channel readiness fails closed');
    }
    f = fixture(state(),{config:{lang:'en'}}); await settle();
    equal(f.nodes.toggle.textContent,'Enable analysis and order dispatch','English activation labels analysis-only');
    equal(f.nodes['analysis-status'].textContent,'Message analysis is off','English analysis purpose label');
    equal(f.nodes['dispatch-status'].textContent,'Order dispatch is off','English dispatch purpose label');
    f.window.DashboardMetaAiControls.destroy(); f.queue.push(response(state({revision:Number.MAX_SAFE_INTEGER})));
    f.window.DashboardMetaAiControls.mount(); await settle(); check(!f.nodes.toggle.disabled,'maximum safe revision accepted');
    f.queue.push(response(state({revision:Number.MAX_SAFE_INTEGER}))); f.nodes.toggle.fire('click');
    equal(f.nodes.save.textContent,'Saving settings…','English pending label'); await settle();
    equal(f.nodes.save.textContent,'Save settings','English save restored'); equal(sent(f).expected_revision,Number.MAX_SAFE_INTEGER,'revision remains exact');

    // Production Blade has no route for full-reply activation or hidden takeover controls.
    const blade = fs.readFileSync(path.join(__dirname,'../resources/views/admin/meta/ai_controls.blade.php'),'utf8');
    const channelView = fs.readFileSync(path.join(__dirname,'../resources/views/admin/meta/channels.blade.php'),'utf8');
    check(blade.includes("Route::has('meta-ai-control.state')") && blade.includes("Route::has('meta-ai-control.update')"),'absent routes hide partial');
    check(blade.includes('JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT'),'bootstrap script encoded');
    for (const removed of ['data-meta-ai-mode','data-meta-ai-reply-status','data-meta-ai-reply-settings','data-meta-ai-external-paused','data-meta-ai-handoff-settings','data-meta-ai-handoff-state','data-meta-ai-unanswered-note','value="REPLY_AND_ANALYZE"']) check(!blade.includes(removed),'obsolete reply control removed: ' + removed);
    for (const removed of ['schedule:mode.value',"mode:mode.value",'externalPaused.checked',"bind(mode, 'change'"]) check(!source.includes(removed),'script has no full-reply selection or attestation path: ' + removed);
    check(source.includes("mode:'ANALYZE_ONLY'") && source.includes('external_agent_paused:false'),'every collected save and enable pins analysis-only without takeover');
    check(blade.includes('مساعد ميتا مسؤول عن الرد وجمع البيانات وتأكيد الطلب') && blade.includes('Meta assistant is responsible for replies, collecting details and confirming the order'),'role note assigns external conversation owner');
    check(blade.includes('بدون إرسال أي ردود تلقائية من الداشبورد') && blade.includes('without sending automatic dashboard replies'),'role note describes no dashboard automatic replies');
    check(blade.includes('احفظ الإعدادات لتطبيق هذا الدور') && blade.includes('Save the settings to apply this role'),'role note cannot imply an unsaved transition');
    check(blade.includes('القنوات ومواعيد تحليل الرسائل وإرسال الطلبات') && blade.includes('Channels, message analysis hours and order dispatch hours'),'summary describes requested schedules');
    check(blade.includes('جدولة تحليل الرسائل') && blade.includes('Schedule message analysis'),'analysis schedule correctly labeled');
    check(blade.includes('أيام بداية تحليل الرسائل') && blade.includes('Days when message analysis starts'),'analysis start days labeled');
    check(blade.includes('جدول إرسال الطلبات مستقل عن جدول تحليل الرسائل') && blade.includes('Order dispatch has its own schedule, separate from message analysis'),'independent purposes explained');
    check(blade.includes('عند إيقاف جدولة التحليل، يمكن تحليل الرسائل طوال اليوم') && blade.includes('Turning off analysis scheduling allows messages to be analysed all day'),'unscheduling analysis means all-day');
    check(blade.includes('عند إيقاف جدولة الإرسال، يمكن إرسال الطلبات طوال اليوم') && blade.includes('Turning off dispatch scheduling allows orders to be sent all day'),'unscheduling dispatch means all-day');
    for (const field of ['schedule','all-day','start','end','day','status']) {
        const prefix = field === 'status' ? 'analysis-' : '';
        equal((blade.match(new RegExp('data-meta-ai-' + prefix + field + '(?:[ >])','g')) || []).length,1,'unique analysis ' + field + ' field');
        equal((blade.match(new RegExp('data-meta-ai-dispatch-' + field + '(?:[ >])','g')) || []).length,1,'unique dispatch ' + field + ' field');
    }
    check(blade.includes('الموعد الافتراضي يوميًا من 9 صباحًا إلى 12:30 بعد منتصف الليل (09:00–00:30) بتوقيت القاهرة') && blade.includes('The default is daily from 9:00 AM to 12:30 AM after midnight (09:00–00:30), Cairo time'),'dispatch overnight default explained');
    check(blade.includes('بعد تأكيد العميل') && blade.includes('after customer confirmation'),'schedules do not bypass confirmation');
    check(blade.includes('الرد اليدوي متاح دائمًا') && blade.includes('Manual replies remain available'),'manual replies remain separate');
    equal((channelView.match(/@include\('admin\.meta\.ai_controls'\)/g) || []).length,1,'shared navigation includes controls once');
    for (const channel of ['','whatsapp','messenger','instagram']) equal((channelView.match(new RegExp('data-hub-channel="' + channel + '"','g')) || []).length,1,'hub navigation retained for ' + (channel || 'all'));
    for (const file of ['meta/index.blade.php','whatsapp/index.blade.php']) check(fs.readFileSync(path.join(__dirname,'../resources/views/admin/',file),'utf8').includes("@include('admin.meta.channels'"),'both inboxes share controls');
    console.log('Meta AI controls UI: ' + checks + ' checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
