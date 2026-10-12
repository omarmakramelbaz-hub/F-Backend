'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

class Element {
    constructor(tag = 'div') { this.tagName = tag; this.value = ''; this.text = ''; this.children = []; this.listeners = {}; this.hidden = false; this.disabled = false; this.attributes = {}; this.style = {}; this.classList = {toggle() {}}; }
    get scrollHeight() { return this.value ? this.value.split('\n').length * 24 + 16 : 40; }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Reply text must never be parsed as markup'); }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    removeEventListener(name, callback) { this.listeners[name] = (this.listeners[name] || []).filter(item => item !== callback); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(callback => callback(event)); }
    removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    contains(child) { return this === child || this.children.some(item => item.contains(child)); }
    focus() { this.focused = true; }
    click() { this.clicks = (this.clicks || 0) + 1; this.dispatchEvent({type: 'click', target: this}); }
    setSelectionRange(from, to) { this.selectionStart = from; this.selectionEnd = to; }
    appendChild(child) { this.children.push(child); return child; }
    pause() {} load() {}
}
class Multipart { constructor() { this.values = []; } append(name, value, filename) { this.values.push({name, value, filename}); } }

function fixture() {
    const document = new Element(), window = new Element(), host = new Element(), panel = new Element(), bootstrap = new Element('script');
    const elements = {};
    ['reply-status', 'reply-form', 'reply-text', 'reply-send', 'reply-refresh', 'reply-count', 'voice-record',
        'voice-stop', 'voice-cancel', 'voice-send', 'voice-preview', 'voice-time', 'voice-status', 'reply-audit', 'reply-audit-list',
        'reply-quote', 'reply-quote-text', 'reply-quote-cancel', 'reply-emoji', 'reply-emoji-picker',
        'attachment-select', 'attachment-input', 'attachment-preview', 'attachment-image', 'attachment-name', 'attachment-size', 'attachment-cancel'].forEach(name => elements['[data-wa-' + name + ']'] = new Element());
    elements['[data-wa-reply-emoji-picker]'].hidden = true;
    panel.querySelector = selector => elements[selector]; host.querySelector = selector => selector === '[data-wa-replies]' ? panel : null;
    document.getElementById = id => id === 'whatsapp-inbox' ? host : id === 'whatsapp-replies-bootstrap' ? bootstrap : null;
    document.hidden = false; window.location = {href: 'https://example.test/admin/whatsapp'};
    document.createElement = tag => new Element(tag); document.documentElement = {lang: 'en'};
    const state = {calls: [], cleanup: [], outcome: 'ACCEPTED', requestState: null, reason: null, canReply: true,
        hold: false, networkFailure: false, redirected: false, status: 200, inboxDestroyed: 0,
        mediaCalls: 0, tracksStopped: 0, recorders: [], supported: ['audio/ogg;codecs=opus'],
        objectUrls: [], revoked: [], timers: new Map(), intervals: new Map(), uuidCalls: 0, voiceReady: true,
        microphoneDeferred: false, releaseMicrophones: [], preclaimFailure: null, latestInbound: 11, attachmentReady: true,
        attachmentTypes: ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'], attachmentMax: 8388608, imageMax: 5242880};
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => state.cleanup.push(callback)};
    window.DashboardWhatsAppInbox = {selection: () => 0, destroy: () => { state.inboxDestroyed++; }};
    window.crypto = {randomUUID: () => '00000000-0000-4000-8000-' + String(++state.uuidCalls).padStart(12, '0')};
    const labels = {};
    ['loading', 'unavailable', 'accepted', 'unknown', 'failed', 'ready', 'offline', 'denied', 'sending',
        'expired', 'not_configured', 'voice_unavailable', 'voice_limit', 'voice_ready', 'recording', 'microphone_denied', 'voice_message', 'record', 'stop', 'quote_reply',
        'attachment_invalid', 'attachment_single', 'attachment_ready', 'attachment_caption'].forEach(name => labels[name] = name.toUpperCase());
    bootstrap.textContent = JSON.stringify({conversations_base_url: 'https://example.test/admin/whatsapp/conversations', csrf: 'fixture-csrf', labels});
    const navigator = {onLine: true, mediaDevices: {getUserMedia: async request => {
        assert.deepStrictEqual(JSON.parse(JSON.stringify(request)), {audio: true}); state.mediaCalls++;
        if (state.microphoneDeferred) await new Promise(resolve => state.releaseMicrophones.push(resolve));
        const track = {readyState: 'live', stop() { state.tracksStopped++; this.readyState = 'ended'; }};
        return {getTracks: () => [track]};
    }}};
    class Recorder {
        constructor(stream, options) { this.stream = stream; this.mimeType = options.mimeType; this.state = 'inactive'; state.recorders.push(this); }
        static isTypeSupported(mime) { return state.supported.includes(mime); }
        start() { this.state = 'recording'; }
        stop() {
            this.state = 'inactive';
            if (this.ondataavailable) this.ondataavailable({data: new Blob([new Uint8Array(16)], {type: this.mimeType})});
            if (this.onstop) this.onstop();
        }
    }
    window.MediaRecorder = Recorder;
    class TestURL extends URL {
        static createObjectURL(blob) { assert(blob instanceof Blob); const url = 'blob:fixture-' + (state.objectUrls.length + 1); state.objectUrls.push(url); return url; }
        static revokeObjectURL(url) { state.revoked.push(url); }
    }
    async function fetch(url, options) {
        state.calls.push({url, options}); assert.strictEqual(options.cache, 'no-store'); assert.strictEqual(options.credentials, 'same-origin');
        assert.strictEqual(options.headers.Authorization, undefined);
        const post = options.method === 'POST';
        const conversation = Number((new URL(url).pathname.match(/conversations\/(\d+)/) || [])[1]) || 1;
        if (post) {
            assert.strictEqual(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
            if (state.hold) await new Promise((resolve, reject) => {
                state.release = resolve;
                options.signal.addEventListener('abort', () => { const error = new Error('Aborted'); error.name = 'AbortError'; reject(error); });
            });
            if (state.networkFailure) throw new Error('Fixture network failure');
        }
        const status = post && state.preclaimFailure ? 409 : post && state.outcome === 'UNKNOWN' ? 202 : state.status;
        if (post && state.preclaimFailure === 'STALE_INBOUND') state.latestInbound = 12;
        return {ok: status === 200, status, redirected: state.redirected,
            headers: {get: () => state.redirected ? 'text/html' : 'application/json'},
            json: async () => post ? state.preclaimFailure ? {success: false, state: 'FAILED', reply_id: null, reason: state.preclaimFailure}
                : {success: state.outcome === 'ACCEPTED', state: state.outcome, replayed: false, reply_id: 17}
                : {success: true, available: true, can_reply: state.canReply, latest_inbound_id: state.latestInbound,
                    reason: state.reason, request_state: state.requestState, voice_ready: state.voiceReady,
                    attachment_ready: state.attachmentReady, attachment_types: state.attachmentTypes,
                    attachment_max_bytes: state.attachmentMax, image_max_bytes: state.imageMax,
                    supported_voice_mime_types: state.voiceReady ? ['audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm;codecs=opus'] : [],
                    recent_replies: state.publicRecent || [{reply_id: 1, kind: 'text', state: 'ACCEPTED', text: '<script>private reply ' + conversation + '</script>', created_at: '2026-10-10 00:00:00'},
                        {reply_id: 2, kind: 'audio', state: 'UNKNOWN', text: null, created_at: '2026-10-10 00:00:01'}]}};
    }
    let timerId = 0;
    const context = {document, window, navigator, URL: TestURL, Map, Set, Promise, Date, Uint8Array, Array, Blob,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        FormData: Multipart, AbortController, fetch, console,
        setTimeout: (callback, delay) => { const id = ++timerId; state.timers.set(id, {callback, delay}); return id; },
        clearTimeout: id => state.timers.delete(id),
        setInterval: callback => { const id = ++timerId; state.intervals.set(id, callback); return id; },
        clearInterval: id => state.intervals.delete(id)};
    vm.createContext(context); vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'public/js/dashboard-whatsapp-replies.js'), 'utf8'), context);
    return {document, window, panel, elements, state, context, navigator};
}
async function settle() { for (let index = 0; index < 10; index++) await new Promise(setImmediate); }
function select(test, id) { test.document.dispatchEvent({type: 'whatsapp:conversation-selected', detail: {conversation: id}}); }
function input(test, value) {
    const text = test.elements['[data-wa-reply-text]']; text.value = value; text.dispatchEvent({type: 'input'});
}
function submit(test) { test.elements['[data-wa-reply-form]'].dispatchEvent({type: 'submit', preventDefault() {}}); }
function click(test, name) { test.elements['[data-wa-' + name + ']'].dispatchEvent({type: 'click'}); }
function key(test, properties = {}) {
    const event = Object.assign({type: 'keydown', key: 'Enter', target: test.elements['[data-wa-reply-text]'],
        preventDefault() { this.prevented = true; }}, properties);
    test.panel.dispatchEvent(event); return event;
}
function releaseKey(test) { test.document.dispatchEvent({type: 'keyup', key: 'Enter'}); }
function scopedSelect(test, id = 1, generation = 4) {
    test.document.dispatchEvent({type: 'whatsapp:conversation-selected', detail: {conversation: id, generation,
        channel: 'whatsapp', namespace: 'https://example.test/admin/whatsapp/conversations'}});
}
function quote(test, extras = {}) {
    test.document.dispatchEvent({type: 'whatsapp:reply-context', detail: Object.assign({conversation_id: 1, generation: 4,
        channel: 'whatsapp', endpoint_namespace: 'https://example.test/admin/whatsapp/conversations',
        message_id: 25, text: '<b>Native quoted text</b>', kind: 'text'}, extras)});
}

async function acceptedAndSingleSend() {
    const test = fixture(); assert.strictEqual(test.state.calls.length, 0); assert.strictEqual(test.state.mediaCalls, 0);
    select(test, 1); await settle(); assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 0);
    assert(test.elements['[data-wa-reply-audit-list]'].textContent.includes('<script>private reply 1</script>'));
    assert(test.elements['[data-wa-reply-audit-list]'].textContent.includes('VOICE_MESSAGE'));
    input(test, '<img src=x onerror=alert(1)>'); test.state.hold = true; submit(test); submit(test); await settle();
    const sent = test.state.calls.filter(call => call.options.method === 'POST'); assert.strictEqual(sent.length, 1);
    assert.deepStrictEqual(JSON.parse(sent[0].options.body), {client_request_id: '00000000-0000-4000-8000-000000000001',
        text: '<img src=x onerror=alert(1)>', expected_inbound_id: 11});
    test.state.release(); await settle(); assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '');
    assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'ACCEPTED', 'Acceptance must not be described as delivered');
    test.state.cleanup[0]();
    assert.strictEqual(test.elements['[data-wa-reply-audit-list]'].textContent, '', 'Reply history is cleared when the page leaves the shell');
}

async function unknownDoesNotRetry() {
    for (const networkFailure of [false, true]) {
        const test = fixture(); select(test, 1); await settle(); input(test, 'Original reply');
        test.state.networkFailure = networkFailure; test.state.outcome = 'UNKNOWN'; submit(test); await settle();
        assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true);
        assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Original reply');
        assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'UNKNOWN');
        const posts = test.state.calls.filter(call => call.options.method === 'POST'); assert.strictEqual(posts.length, 1);
        assert(test.state.calls.some(call => new URL(call.url).searchParams.get('client_request_id') === JSON.parse(posts[0].options.body).client_request_id));
        click(test, 'reply-refresh'); submit(test); await settle();
        assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Unknown outcomes never trigger a resend');
        test.state.requestState = 'ACCEPTED'; click(test, 'reply-refresh'); await settle();
        assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '', 'Only a trusted resolved outcome clears the uncertain text');
        test.state.cleanup[0]();
    }
}

async function staleSwitchAndExpiry() {
    const test = fixture(); select(test, 1); await settle(); input(test, 'Private customer one');
    test.state.hold = true; submit(test); await settle(); const request = test.state.calls.find(call => call.options.method === 'POST');
    select(test, 2); await settle(); input(test, 'Customer two draft');
    assert.strictEqual(request.options.signal.aborted, true); test.state.release(); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Customer two draft', 'A late accepted response cannot clear a different customer draft');
    assert(!test.elements['[data-wa-reply-audit-list]'].textContent.includes('private reply 1'), 'A different customer receives only its own reply history');
    test.state.cleanup[0]();
    for (const redirected of [false, true]) {
        const expired = fixture(); select(expired, 1); await settle(); input(expired, 'Private reply');
        expired.state.redirected = redirected; expired.state.status = redirected ? 200 : 403; submit(expired); await settle();
        assert.strictEqual(expired.elements['[data-wa-reply-text]'].value, ''); assert.strictEqual(expired.state.inboxDestroyed, 1);
        expired.state.cleanup[0]();
    }
}

async function voicePreviewAndLifecycle() {
    const test = fixture(); select(test, 1); await settle(); assert.strictEqual(test.state.mediaCalls, 0, 'Selection never requests the microphone');
    click(test, 'voice-record'); await settle(); assert.strictEqual(test.state.mediaCalls, 1);
    assert.strictEqual(test.state.recorders[0].mimeType, 'audio/ogg;codecs=opus');
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/voice')).length, 0, 'Recording alone never sends audio');
    click(test, 'voice-stop'); await settle();
    assert.strictEqual(test.elements['[data-wa-voice-preview]'].hidden, false); assert.strictEqual(test.state.tracksStopped, 1);
    click(test, 'voice-send'); click(test, 'voice-send'); await settle();
    const uploads = test.state.calls.filter(call => call.url.endsWith('/voice')); assert.strictEqual(uploads.length, 1);
    assert(uploads[0].options.body instanceof Multipart); assert.strictEqual(uploads[0].options.headers['Content-Type'], undefined, 'The browser owns multipart boundaries');
    assert.deepStrictEqual(uploads[0].options.body.values.map(item => item.name), ['audio', 'client_request_id', 'expected_inbound_id']);
    assert.strictEqual(test.state.revoked.length, 1, 'Send releases the private audio object URL');
    assert.strictEqual(test.elements['[data-wa-voice-preview]'].src, '');
    click(test, 'voice-record'); await settle();
    test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'offline'}});
    assert.strictEqual(test.state.tracksStopped >= 2, true, 'Privacy reset stops microphone tracks');
    assert.strictEqual(test.state.timers.size, 0); assert.strictEqual(test.state.intervals.size, 0);
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, ''); test.state.cleanup[0]();
}

async function voiceFormatLimitsAndCancel() {
    const unavailable = fixture(); unavailable.state.voiceReady = false; select(unavailable, 1); await settle();
    assert.strictEqual(unavailable.elements['[data-wa-voice-record]'].disabled, true);
    assert.strictEqual(unavailable.elements['[data-wa-voice-status]'].textContent, 'VOICE_UNAVAILABLE'); unavailable.state.cleanup[0]();
    const webm = fixture(); webm.state.supported = ['audio/webm;codecs=opus']; select(webm, 1); await settle();
    click(webm, 'voice-record'); await settle(); assert.strictEqual(webm.state.recorders[0].mimeType, 'audio/webm;codecs=opus');
    const limit = Array.from(webm.state.timers.values()).find(timer => timer.delay === 60000); assert(limit); limit.callback(); await settle();
    assert.strictEqual(webm.elements['[data-wa-voice-preview]'].hidden, false, 'The 60 second cap stops recording for manual preview');
    click(webm, 'voice-cancel'); assert.strictEqual(webm.state.revoked.length, 1);
    assert.strictEqual(webm.state.calls.filter(call => call.url.endsWith('/voice')).length, 0); webm.state.cleanup[0]();

    const oversized = fixture(); select(oversized, 1); await settle(); click(oversized, 'voice-record'); await settle();
    oversized.state.recorders[0].ondataavailable({data: new Blob([new Uint8Array(8 * 1024 * 1024 + 1)])});
    assert.strictEqual(oversized.elements['[data-wa-reply-status]'].textContent, 'VOICE_LIMIT');
    assert.strictEqual(oversized.elements['[data-wa-voice-preview]'].hidden, true);
    assert.strictEqual(oversized.state.tracksStopped, 1); assert.strictEqual(oversized.state.objectUrls.length, 0);
    assert.strictEqual(oversized.state.calls.filter(call => call.url.endsWith('/voice')).length, 0);
    oversized.state.cleanup[0]();
    const changed = fixture(); select(changed, 1); await settle(); input(changed, 'Retained text draft');
    click(changed, 'voice-record'); await settle(); click(changed, 'voice-record'); await settle();
    changed.state.voiceReady = false; click(changed, 'reply-refresh'); await settle();
    assert.strictEqual(changed.elements['[data-wa-reply-send]'].disabled, true, 'A ready clip loses Send when refreshed server voice availability closes');
    key(changed); await settle(); assert.strictEqual(changed.state.calls.filter(call => call.url.endsWith('/voice')).length, 0);
    click(changed, 'voice-cancel'); assert.strictEqual(changed.elements['[data-wa-reply-text]'].value, 'Retained text draft');
    assert.strictEqual(changed.elements['[data-wa-reply-send]'].disabled, false, 'Cancelling unavailable clip restores the retained text draft');
    changed.state.cleanup[0]();
}

async function cancelledMicrophoneGrant() {
    const test = fixture(); select(test, 1); await settle(); test.state.microphoneDeferred = true;
    click(test, 'voice-record'); await settle(); click(test, 'voice-cancel'); click(test, 'voice-record'); await settle();
    assert.strictEqual(test.state.releaseMicrophones.length, 2);
    test.state.releaseMicrophones[0](); await settle();
    assert.strictEqual(test.state.recorders.length, 0, 'A cancelled old microphone grant cannot start a newer recording');
    assert.strictEqual(test.state.tracksStopped, 1, 'Cancelled late microphone grants release all tracks');
    test.state.releaseMicrophones[1](); await settle();
    assert.strictEqual(test.state.recorders.length, 1); test.state.cleanup[0]();
    assert.strictEqual(test.state.tracksStopped, 2);
}

async function trustedPreclaimFailure() {
    const test = fixture(); select(test, 1); await settle(); input(test, 'Reply before new customer message');
    test.state.preclaimFailure = 'STALE_INBOUND'; submit(test); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'FAILED');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Reply before new customer message');
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Stale inbound failures do not resend automatically');
    test.state.preclaimFailure = null; submit(test); await settle();
    const sends = test.state.calls.filter(call => call.options.method === 'POST'); assert.strictEqual(sends.length, 2);
    assert.strictEqual(JSON.parse(sends[1].options.body).expected_inbound_id, 12);
    assert.notStrictEqual(JSON.parse(sends[0].options.body).client_request_id, JSON.parse(sends[1].options.body).client_request_id);
    test.state.cleanup[0]();
}

async function keyboardAndMicToggle() {
    const test = fixture(); select(test, 1); await settle(); input(test, 'First line\nSecond line');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].style.height, '66px', 'Multiline draft grows above the compact baseline');
    assert.strictEqual(key(test, {shiftKey: true}).prevented, undefined, 'Shift+Enter retains native multiline editing');
    assert.strictEqual(key(test, {isComposing: true}).prevented, undefined);
    assert.strictEqual(key(test, {keyCode: 229}).prevented, undefined);
    test.elements['[data-wa-reply-text]'].dispatchEvent({type: 'compositionstart'}); key(test);
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 0, 'IME composition never submits');
    test.elements['[data-wa-reply-text]'].dispatchEvent({type: 'compositionend'});
    test.state.hold = true; assert.strictEqual(key(test).prevented, true); key(test, {repeat: true}); submit(test); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Held Enter/form submit cannot duplicate the in-flight send');
    assert.strictEqual(JSON.parse(test.state.calls.find(call => call.options.method === 'POST').options.body).text, 'First line\nSecond line');
    test.state.release(); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-text]'].style.height, '42px', 'Accepted send returns to compact height');
    input(test, 'Another draft'); key(test); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Enter remains held until keyup even after acceptance');
    releaseKey(test); test.state.hold = false; key(test); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 2);
    releaseKey(test); click(test, 'voice-record'); await settle();
    assert.strictEqual(test.elements['[data-wa-voice-record]'].attributes['aria-pressed'], 'true');
    assert.strictEqual(test.elements['[data-wa-voice-record]'].attributes['aria-label'], 'STOP');
    key(test); releaseKey(test);
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/voice')).length, 0, 'Enter during active recording never sends or stops a clip');
    assert.strictEqual(test.state.recorders[0].state, 'recording');
    click(test, 'voice-record'); await settle();
    assert.strictEqual(test.elements['[data-wa-voice-preview]'].hidden, false, 'The same mic stops into preview-ready state');
    assert.strictEqual(test.elements['[data-wa-voice-record]'].attributes['aria-pressed'], 'false');
    key(test); key(test, {repeat: true}); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/voice')).length, 1, 'Enter sends exactly one ready voice clip');
    test.state.cleanup[0]();
    const posts = test.state.calls.filter(call => call.options.method === 'POST').length;
    key(test); releaseKey(test); quote(test); click(test, 'voice-record'); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, posts, 'Destroyed composer removes keyboard/action listeners');
}

async function scopedQuotedTextAndVoice() {
    const test = fixture(); scopedSelect(test); await settle();
    for (const bad of [{conversation_id: 2}, {generation: 3}, {channel: 'messenger'}, {endpoint_namespace: 'https://foreign.test/conversations'},
        {message_id: '25'}, {message_id: 0}, {message_id: -1}, {message_id: null}]) {
        quote(test, bad); assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '', 'Stale or cross-account quote context ignored');
    }
    quote(test); assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '<b>Native quoted text</b>');
    input(test, 'Quoted response'); test.state.hold = true; key(test); await settle();
    const body = JSON.parse(test.state.calls.find(call => call.options.method === 'POST').options.body);
    assert.strictEqual(body.reply_to_message_id, 25);
    click(test, 'reply-quote-cancel'); quote(test, {message_id: 26, text: 'Late quote'});
    assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '<b>Native quoted text</b>', 'Sending freezes its quote fingerprint');
    test.state.release(); await settle(); assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, ''); releaseKey(test);
    quote(test); click(test, 'voice-record'); await settle(); click(test, 'voice-record'); await settle(); test.state.hold = false; key(test); await settle();
    const voice = test.state.calls.find(call => call.url.endsWith('/voice'));
    assert.strictEqual(voice.options.body.values.find(item => item.name === 'reply_to_message_id').value, '25');
    assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '', 'Accepted voice clears quoted context');
    quote(test); scopedSelect(test, 2, 5); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '', 'Selection resets native quote');
    test.state.cleanup[0]();

    const failed = fixture(); scopedSelect(failed); await settle(); quote(failed); input(failed, 'Keep quoted draft');
    failed.state.preclaimFailure = 'STALE_INBOUND'; key(failed); await settle();
    assert.strictEqual(failed.elements['[data-wa-reply-quote-text]'].textContent, '<b>Native quoted text</b>', 'Trusted failed send keeps quote and draft');
    assert.strictEqual(failed.elements['[data-wa-reply-text]'].value, 'Keep quoted draft'); failed.state.cleanup[0]();
}

async function timeoutAndQuoteResolution() {
    const test = fixture(); scopedSelect(test); await settle(); quote(test); input(test, 'Timed out quoted reply'); test.state.hold = true; key(test); await settle();
    const timeout = Array.from(test.state.timers.values()).find(timer => timer.delay === 45000); assert(timeout); timeout.callback(); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'UNKNOWN');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Timed out quoted reply');
    assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '<b>Native quoted text</b>');
    releaseKey(test); key(test); click(test, 'reply-quote-cancel'); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Timeout locks the same idempotency ledger without resend');
    test.state.requestState = 'ACCEPTED'; click(test, 'reply-refresh'); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '', 'Only trusted accepted ledger resolution clears uncertain quote');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, ''); test.state.cleanup[0]();
}

async function acceptedAuditQuoteUnion() {
    const test = fixture(); scopedSelect(test); await settle();
    for (const invalid of [{reply_id: 9}, {message_id: null, reply_id: '9'}, {message_id: null, reply_id: 0},
        {message_id: 0, reply_id: 9}, {message_id: null, reply_id: null}]) {
        quote(test, invalid); assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '', 'Audit/native target union rejects ambiguous or invalid IDs');
    }
    quote(test, {message_id: null, reply_id: 9, text: 'Accepted API reply'}); input(test, 'Respond to accepted API message');
    key(test); await settle();
    const body = JSON.parse(test.state.calls.find(call => call.options.method === 'POST').options.body);
    assert.strictEqual(body.reply_to_reply_id, 9); assert.strictEqual(body.reply_to_message_id, undefined);
    assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, ''); releaseKey(test);
    quote(test, {message_id: null, reply_id: 10, text: 'Accepted audio source'}); click(test, 'voice-record'); await settle(); click(test, 'voice-record'); await settle(); key(test); await settle();
    const voice = test.state.calls.find(call => call.url.endsWith('/voice')).options.body.values;
    assert.strictEqual(voice.find(item => item.name === 'reply_to_reply_id').value, '10');
    assert.strictEqual(voice.find(item => item.name === 'reply_to_message_id'), undefined);
    test.state.cleanup[0]();
}

async function emojiDraftAndPrivacy() {
    const test = fixture(); scopedSelect(test); await settle(); input(test, 'Hello world');
    input(test, 'line\n'.repeat(10)); assert.strictEqual(test.elements['[data-wa-reply-text]'].style.height, '120px', 'Long multiline drafts keep bounded composer height'); input(test, 'Hello world');
    test.elements['[data-wa-reply-text]'].setSelectionRange(6, 11);
    click(test, 'reply-emoji'); assert.strictEqual(test.elements['[data-wa-reply-emoji-picker]'].hidden, false);
    const button = new Element('button'); button.setAttribute('data-wa-emoji', '😊'); button.closest = () => button;
    test.elements['[data-wa-reply-emoji-picker]'].appendChild(button);
    test.elements['[data-wa-reply-emoji-picker]'].dispatchEvent({type: 'click', target: button});
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Hello 😊');
    assert.strictEqual(test.elements['[data-wa-reply-count]'].textContent, '7 / 4096');
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 0, 'Emoji inserts draft text without sending');
    button.setAttribute('data-wa-emoji', '<script>'); test.elements['[data-wa-reply-emoji-picker]'].dispatchEvent({type: 'click', target: button});
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Hello 😊', 'Picker accepts only fixed emoji choices');
    input(test, 'x'.repeat(4096)); button.setAttribute('data-wa-emoji', '👍'); test.elements['[data-wa-reply-text]'].setSelectionRange(4096, 4096);
    test.elements['[data-wa-reply-emoji-picker]'].dispatchEvent({type: 'click', target: button}); assert.strictEqual(test.elements['[data-wa-reply-text]'].value.length, 4096);
    click(test, 'reply-emoji'); key(test, {key: 'Escape'}); assert.strictEqual(test.elements['[data-wa-reply-emoji-picker]'].hidden, true);
    quote(test); click(test, 'reply-emoji'); test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'offline'}});
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, ''); assert.strictEqual(test.elements['[data-wa-reply-quote-text]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-reply-emoji-picker]'].hidden, true); assert.strictEqual(test.state.timers.size, 0); test.state.cleanup[0]();
}

async function acceptedMediaSnapshot() {
    const test = fixture(), snapshots = [];
    test.document.addEventListener('whatsapp:accepted-replies', event => snapshots.push(event.detail));
    const row = {reply_id: 17, projection_id: 47, kind: 'image', state: 'ACCEPTED', source: 'dashboard_api', text: null,
        media: {url: '/admin/whatsapp/conversations/1/replies/17/media', type: 'image', mime_type: 'image/png'},
        actions: {copy: false, reply: true, forward: true, delete: true, react: true, unsafe_native_id: true},
        reaction: {emoji: '👍', actor_id: 1, accepted_at: '2026-10-12T01:15:00Z', source: 'dashboard_api',
            state: 'ACCEPTED', reply_id: 29, native_id: 'SHOULD_NOT_BE_PUBLISHED', hash: 'SHOULD_NOT_BE_PUBLISHED'},
        reply_to: {message_id: null, reply_id: 9, text: '<img>quoted audit text', kind: 'text', direction: 'outbound'}};
    test.state.publicRecent = [row]; scopedSelect(test); await settle();
    const snapshot = snapshots.at(-1).replies[0];
    assert.strictEqual(snapshot.media.url, 'https://example.test/admin/whatsapp/conversations/1/replies/17/media');
    assert.strictEqual(snapshot.media.mime_type, 'image/png');
    assert.strictEqual(snapshot.projection_id, 47, 'Stable internal projection cursor is preserved');
    assert.deepStrictEqual(Object.keys(snapshot.actions).sort(), ['copy', 'delete', 'forward', 'react', 'reply']);
    assert.strictEqual(snapshot.reply_to.reply_id, 9); assert.strictEqual(snapshot.reply_to.message_id, null);
    assert.strictEqual(snapshot.reaction.emoji, '👍'); assert.strictEqual(snapshot.reaction.reply_id, 29);
    assert.deepStrictEqual(Object.keys(snapshot.reaction).sort(), ['accepted_at', 'actor_id', 'emoji', 'reply_id', 'source', 'state']);
    for (const bad of [{url: '/admin/whatsapp/conversations/2/replies/17/media'}, {url: '/admin/whatsapp/conversations/1/replies/18/media'},
        {url: 'https://external.test/admin/whatsapp/conversations/1/replies/17/media'}, {url: '/admin/whatsapp/conversations/1/replies/17/media?token=secret'},
        {url: '/admin/whatsapp/conversations/1/replies/17/media#fragment'}, {url: 'https://user:secret@example.test/admin/whatsapp/conversations/1/replies/17/media'},
        {mime_type: 'image/svg+xml'}, {type: 'video'}]) {
        test.state.publicRecent = [Object.assign({}, row, {media: Object.assign({}, row.media, bad)})];
        click(test, 'reply-refresh'); await settle(); assert.strictEqual(snapshots.at(-1).replies[0].media, null, 'Media descriptor must match its authorized internal scope');
    }
    for (const bad of [{emoji: '🔥'}, {state: 'UNKNOWN'}, {state: 'FAILED'}, {source: 'foreign'}, {actor_id: 0},
        {actor_id: '1'}, {reply_id: 0}, {reply_id: '29'}, {accepted_at: 'not a date'}, {accepted_at: '2026-10-12T01:15:00+03:00'},
        {accepted_at: '2026-99-99T01:15:00Z'}]) {
        test.state.publicRecent = [Object.assign({}, row, {reaction: Object.assign({}, row.reaction, bad)})];
        click(test, 'reply-refresh'); await settle(); assert.strictEqual(snapshots.at(-1).replies[0].reaction, null, 'Only a bounded accepted dashboard reaction is published');
    }
    test.state.publicRecent = [Object.assign({}, row, {reaction: Object.assign({}, row.reaction, {emoji: '', accepted_at: '2026-10-12T01:15:00.000000+00:00'})})];
    click(test, 'reply-refresh'); await settle(); assert.strictEqual(snapshots.at(-1).replies[0].reaction.emoji, '', 'Trusted clear survives refresh and suppresses the previous emoji');
    test.state.publicRecent = [row]; click(test, 'reply-refresh'); await settle(); assert.strictEqual(snapshots.at(-1).replies[0].reaction.emoji, '👍', 'Accepted reaction survives repeated reply-state reloads');
    test.state.cleanup[0]();
}

function uploadedFile(name, type, bytes = 16) { return Object.assign(new Blob([new Uint8Array(bytes)], {type}), {name}); }
function chooseFile(test, files) { click(test, 'attachment-select'); const picker = test.elements['[data-wa-attachment-input]']; picker.files = files; picker.dispatchEvent({type: 'change'}); }
function posts(test) { return test.state.calls.filter(call => call.options.method === 'POST'); }
async function attachmentPreviewQuoteAndEnter() {
    const test = fixture(); scopedSelect(test); await settle(); click(test, 'attachment-select');
    assert.strictEqual(test.elements['[data-wa-attachment-input]'].clicks, 1);
    const file = uploadedFile('صورة الطلب.png', 'image/png'); chooseFile(test, [file]);
    assert.strictEqual(posts(test).length, 0, 'Choosing a file never sends a message');
    assert.strictEqual(test.elements['[data-wa-attachment-preview]'].hidden, false);
    assert.strictEqual(test.elements['[data-wa-attachment-image]'].hidden, false);
    assert.strictEqual(test.elements['[data-wa-attachment-name]'].textContent, 'صورة الطلب.png');
    assert.strictEqual(test.elements['[data-wa-voice-record]'].disabled, true, 'Attachment and voice are exclusive');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].readOnly, false, 'The composer edits an optional attachment caption');
    input(test, '<b>Review this order</b>'); quote(test); test.state.hold = true;
    key(test, {shiftKey: true}); key(test, {isComposing: true}); key(test, {keyCode: 229});
    assert.strictEqual(posts(test).length, 0, 'Attachment captions respect Shift+Enter and IME');
    key(test); key(test, {repeat: true}); submit(test); await settle();
    assert.strictEqual(posts(test).length, 1); const sent = posts(test)[0]; assert(sent.url.endsWith('/attachment'));
    assert(sent.options.body instanceof Multipart); assert.strictEqual(sent.options.headers['Content-Type'], undefined);
    assert.deepStrictEqual(sent.options.body.values.map(item => item.name), ['attachment', 'client_request_id', 'expected_inbound_id', 'caption', 'reply_to_message_id']);
    assert.strictEqual(sent.options.body.values[0].value, file); assert.strictEqual(sent.options.body.values[0].filename, file.name);
    assert.strictEqual(sent.options.body.values.find(item => item.name === 'caption').value, '<b>Review this order</b>');
    assert.strictEqual(sent.options.body.values.find(item => item.name === 'reply_to_message_id').value, '25');
    assert.strictEqual(test.state.revoked.length, 1, 'Dispatch releases the private preview object URL');
    assert.strictEqual(test.elements['[data-wa-attachment-image]'].src, '');
    test.state.release(); await settle(); assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '');
    assert.strictEqual(test.elements['[data-wa-reply-quote]'].hidden, true); test.state.cleanup[0]();
}
async function attachmentFileBoundsAndCancel() {
    const test = fixture(); select(test, 1); await settle();
    for (const files of [[uploadedFile('bad.svg', 'image/svg+xml')], [uploadedFile('bad.exe', 'application/octet-stream')],
        [uploadedFile('empty.txt', 'text/plain', 0)], [uploadedFile('large.jpg', 'image/jpeg', 5242881)],
        [uploadedFile('large.pdf', 'application/pdf', 8388609)], [uploadedFile('../secret.txt', 'text/plain')],
        [uploadedFile('x'.repeat(121) + '.txt', 'text/plain')], [uploadedFile('one.png', 'image/png'), uploadedFile('two.png', 'image/png')]]) {
        chooseFile(test, files); assert.strictEqual(test.elements['[data-wa-attachment-preview]'].hidden, true);
        assert.strictEqual(posts(test).length, 0); assert.strictEqual(test.state.objectUrls.length, 0);
        assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true);
    }
    chooseFile(test, [uploadedFile('menu.pdf', '')]); assert.strictEqual(test.elements['[data-wa-attachment-image]'].hidden, true);
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, false, 'A valid file can be sent without caption');
    input(test, 'x'.repeat(1025)); assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true, 'Caption cap is independent from text message cap');
    input(test, 'Draft caption'); click(test, 'attachment-cancel');
    assert.strictEqual(test.elements['[data-wa-attachment-preview]'].hidden, true);
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Draft caption', 'Cancellation preserves the operator text draft');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].maxLength, 4096);
    chooseFile(test, [uploadedFile('photo.jpeg', '', 1024)]); assert.strictEqual(test.state.objectUrls.length, 1);
    chooseFile(test, [uploadedFile('note.txt', 'text/plain')]); assert.strictEqual(test.state.revoked.length, 1, 'Replacing an image frees its preview');
    test.state.attachmentReady = false; click(test, 'reply-refresh'); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true);
    test.state.attachmentReady = true; test.state.attachmentTypes = ['image/png']; click(test, 'reply-refresh'); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true, 'A withdrawn MIME capability cannot send a stale selected file');
    click(test, 'attachment-cancel'); test.state.cleanup[0]();
}
async function attachmentUnknownAndSourcePrivacy() {
    for (const networkFailure of [false, true]) {
        const test = fixture(); scopedSelect(test); await settle(); chooseFile(test, [uploadedFile('menu.pdf', 'application/pdf')]); input(test, 'Customer caption');
        test.state.networkFailure = networkFailure; test.state.outcome = 'UNKNOWN'; key(test); await settle();
        assert.strictEqual(posts(test).length, 1); assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true);
        assert.strictEqual(test.elements['[data-wa-attachment-select]'].disabled, true); assert.strictEqual(test.elements['[data-wa-reply-text]'].readOnly, true);
        const id = posts(test)[0].options.body.values.find(item => item.name === 'client_request_id').value;
        assert(test.state.calls.some(call => new URL(call.url).searchParams.get('client_request_id') === id));
        releaseKey(test); key(test); submit(test); click(test, 'reply-refresh'); await settle(); assert.strictEqual(posts(test).length, 1, 'Unknown uploads never repeat');
        test.state.requestState = 'ACCEPTED'; click(test, 'reply-refresh'); await settle();
        assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '', 'Only trusted acceptance clears the uncertain caption'); test.state.cleanup[0]();
    }
    for (const reason of ['switch', 'denied', 'offline', 'dispose']) {
        const test = fixture(); scopedSelect(test); await settle(); chooseFile(test, [uploadedFile('private.png', 'image/png')]); input(test, 'Private caption');
        if (reason === 'switch') { scopedSelect(test, 2); await settle(); }
        else if (reason === 'dispose') test.state.cleanup[0]();
        else test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason}});
        assert.strictEqual(test.state.revoked.length, 1); assert.strictEqual(test.elements['[data-wa-attachment-image]'].src, '');
        assert.strictEqual(test.elements['[data-wa-attachment-name]'].textContent, ''); assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '');
        assert.strictEqual(posts(test).length, 0); if (reason !== 'dispose') test.state.cleanup[0]();
    }
    const late = fixture(); scopedSelect(late); await settle(); click(late, 'attachment-select'); scopedSelect(late, 2); await settle();
    const picker = late.elements['[data-wa-attachment-input]']; picker.files = [uploadedFile('old-customer.png', 'image/png')]; picker.dispatchEvent({type: 'change'});
    assert.strictEqual(late.state.objectUrls.length, 0); assert.strictEqual(late.elements['[data-wa-attachment-name]'].textContent, '', 'An old file dialog cannot attach data to the newly selected customer');
    assert.strictEqual(posts(late).length, 0); late.state.cleanup[0]();
}
async function attachmentDocumentSnapshot() {
    const test = fixture(), snapshots = []; test.document.addEventListener('whatsapp:accepted-replies', event => snapshots.push(event.detail));
    const row = {reply_id: 17, kind: 'document', text: 'Menu caption', source: 'dashboard_api', state: 'ACCEPTED', accepted_at: '2026-10-12T01:15:00Z',
        media: {type: 'document', url: '/admin/whatsapp/conversations/1/replies/17/attachment', mime_type: 'application/pdf', filename: 'منيو.pdf', size: 512}, actions: {reply: true, delete: true}};
    test.state.publicRecent = [row]; scopedSelect(test); await settle();
    assert.strictEqual(snapshots.at(-1).replies[0].media.filename, 'منيو.pdf');
    assert.strictEqual(snapshots.at(-1).replies[0].media.url, 'https://example.test/admin/whatsapp/conversations/1/replies/17/attachment');
    for (const bad of [{url: '/admin/whatsapp/conversations/2/replies/17/attachment'}, {url: 'https://foreign.test/file'}, {url: '/admin/whatsapp/conversations/1/replies/17/attachment?token=x'},
        {url: '/admin/whatsapp/conversations/1/replies/17/attachment#fragment'}, {mime_type: 'text/html'}, {filename: '../secret.txt'}, {filename: 'x'.repeat(121)}, {size: 8388609}, {size: '512'}]) {
        test.state.publicRecent = [{...row, media: {...row.media, ...bad}}]; click(test, 'reply-refresh'); await settle();
        assert.strictEqual(snapshots.at(-1).replies[0].media, null, 'Only bounded, scoped document descriptors reach the inbox');
    }
    test.state.cleanup[0]();
}
async function lowerServerAttachmentCapsAreAccurate() {
    const test = fixture(); test.state.imageMax = 1048576; test.state.attachmentMax = 2097152; select(test, 1); await settle();
    const button = test.elements['[data-wa-attachment-select]'];
    assert(button.attributes.title.includes('JPG/PNG up to 1 MiB') && button.attributes.title.includes('PDF/TXT up to 2 MiB'));
    assert.strictEqual(button.attributes['aria-label'], button.attributes.title, 'Accessible help reports the server cap instead of the application maximum');
    chooseFile(test, [uploadedFile('photo.jpg', 'image/jpeg', 1048577)]);
    assert.strictEqual(test.elements['[data-wa-attachment-preview]'].hidden, true);
    assert(test.elements['[data-wa-reply-status]'].textContent.includes('JPG/PNG up to 1 MiB'));
    chooseFile(test, [uploadedFile('menu.pdf', 'application/pdf', 2097153)]);
    assert.strictEqual(test.elements['[data-wa-attachment-preview]'].hidden, true);
    assert(test.elements['[data-wa-reply-status]'].textContent.includes('PDF/TXT up to 2 MiB'));
    chooseFile(test, [uploadedFile('menu.pdf', 'application/pdf', 2097152)]);
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, false, 'The exact lower server cap is allowed');
    test.state.attachmentMax = 2097151; click(test, 'reply-refresh'); await settle();
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true, 'A lower refreshed cap also blocks a previously selected file');
    assert(button.attributes.title.includes('PDF/TXT up to 1.99 MiB'), 'Displayed decimals never round above the actual cap');
    click(test, 'attachment-cancel'); test.state.imageMax = 0; test.state.attachmentMax = 0; test.state.attachmentReady = false;
    click(test, 'reply-refresh'); await settle(); assert.strictEqual(button.disabled, true);
    assert(!button.attributes.title.includes('MiB'), 'Unavailable uploads do not advertise an invented fallback size');
    assert.strictEqual(posts(test).length, 0); test.state.cleanup[0]();
}

(async () => {
    await acceptedAndSingleSend(); await unknownDoesNotRetry(); await staleSwitchAndExpiry(); await voicePreviewAndLifecycle(); await voiceFormatLimitsAndCancel(); await cancelledMicrophoneGrant(); await trustedPreclaimFailure();
    await keyboardAndMicToggle(); await scopedQuotedTextAndVoice(); await timeoutAndQuoteResolution(); await acceptedAuditQuoteUnion(); await emojiDraftAndPrivacy(); await acceptedMediaSnapshot();
    await attachmentPreviewQuoteAndEnter(); await attachmentFileBoundsAndCancel(); await attachmentUnknownAndSourcePrivacy(); await attachmentDocumentSnapshot();
    await lowerServerAttachmentCapsAreAccurate();
    console.log('WHATSAPP_REPLIES_UI_PASS 18 scenarios: accepted/uncertain/privacy/voice; Enter/IME/quotes; attachment preview/caption/bounds/cancel; protected document descriptors; no UNKNOWN retry; accurate lower server caps');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });



