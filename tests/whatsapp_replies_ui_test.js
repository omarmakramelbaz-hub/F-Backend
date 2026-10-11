'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

class Element {
    constructor(tag = 'div') { this.tagName = tag; this.value = ''; this.text = ''; this.children = []; this.listeners = {}; this.hidden = false; this.disabled = false; this.attributes = {}; this.classList = {toggle() {}}; }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Reply text must never be parsed as markup'); }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    removeEventListener(name, callback) { this.listeners[name] = (this.listeners[name] || []).filter(item => item !== callback); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(callback => callback(event)); }
    removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
    appendChild(child) { this.children.push(child); return child; }
    pause() {} load() {}
}
class Multipart { constructor() { this.values = []; } append(name, value, filename) { this.values.push({name, value, filename}); } }

function fixture() {
    const document = new Element(), window = new Element(), host = new Element(), panel = new Element(), bootstrap = new Element('script');
    const elements = {};
    ['reply-status', 'reply-form', 'reply-text', 'reply-send', 'reply-refresh', 'reply-count', 'voice-record',
        'voice-stop', 'voice-cancel', 'voice-send', 'voice-preview', 'voice-time', 'voice-status', 'reply-audit', 'reply-audit-list'].forEach(name => elements['[data-wa-' + name + ']'] = new Element());
    panel.querySelector = selector => elements[selector]; host.querySelector = selector => selector === '[data-wa-replies]' ? panel : null;
    document.getElementById = id => id === 'whatsapp-inbox' ? host : id === 'whatsapp-replies-bootstrap' ? bootstrap : null;
    document.hidden = false; window.location = {href: 'https://example.test/admin/whatsapp'};
    document.createElement = tag => new Element(tag); document.documentElement = {lang: 'en'};
    const state = {calls: [], cleanup: [], outcome: 'ACCEPTED', requestState: null, reason: null, canReply: true,
        hold: false, networkFailure: false, redirected: false, status: 200, inboxDestroyed: 0,
        mediaCalls: 0, tracksStopped: 0, recorders: [], supported: ['audio/ogg;codecs=opus'],
        objectUrls: [], revoked: [], timers: new Map(), intervals: new Map(), uuidCalls: 0, voiceReady: true,
        microphoneDeferred: false, releaseMicrophones: [], preclaimFailure: null, latestInbound: 11};
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => state.cleanup.push(callback)};
    window.DashboardWhatsAppInbox = {selection: () => 0, destroy: () => { state.inboxDestroyed++; }};
    window.crypto = {randomUUID: () => '00000000-0000-4000-8000-' + String(++state.uuidCalls).padStart(12, '0')};
    const labels = {};
    ['loading', 'unavailable', 'accepted', 'unknown', 'failed', 'ready', 'offline', 'denied', 'sending',
        'expired', 'not_configured', 'voice_unavailable', 'voice_limit', 'voice_ready', 'recording', 'microphone_denied', 'voice_message'].forEach(name => labels[name] = name.toUpperCase());
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
            if (state.hold) await new Promise(resolve => { state.release = resolve; });
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
                    supported_voice_mime_types: state.voiceReady ? ['audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm;codecs=opus'] : [],
                    recent_replies: [{reply_id: 1, kind: 'text', state: 'ACCEPTED', text: '<script>private reply ' + conversation + '</script>', created_at: '2026-10-10 00:00:00'},
                        {reply_id: 2, kind: 'audio', state: 'UNKNOWN', text: null, created_at: '2026-10-10 00:00:01'}]}};
    }
    let timerId = 0;
    const context = {document, window, navigator, URL: TestURL, Map, Set, Promise, Date, Uint8Array, Array, Blob,
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

(async () => {
    await acceptedAndSingleSend(); await unknownDoesNotRetry(); await staleSwitchAndExpiry(); await voicePreviewAndLifecycle(); await voiceFormatLimitsAndCancel(); await cancelledMicrophoneGrant(); await trustedPreclaimFailure();
    console.log('WHATSAPP_REPLIES_UI_PASS explicit single send, unknown ledger checks without retry, stale/expiry privacy, explicit microphone, validated MIME, audio cleanup and 60 second cap');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
