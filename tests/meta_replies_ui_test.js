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

function fixture(hub = false) {
    const document = new Element(), window = new Element(), host = new Element(), panel = new Element(), bootstrap = new Element('script');
    const elements = {};
    ['reply-status', 'reply-form', 'reply-text', 'reply-send', 'reply-refresh', 'reply-count', 'voice-record',
        'voice-stop', 'voice-cancel', 'voice-send', 'voice-preview', 'voice-time', 'voice-status', 'reply-audit', 'reply-audit-list',
        'reply-quote', 'reply-quote-text', 'reply-quote-cancel', 'reply-emoji', 'reply-emoji-picker',
        'reply-send-label'].forEach(name => elements['[data-wa-' + name + ']'] = new Element());
    ['attachment-pick', 'attachment-input', 'attachment-preview', 'attachment-remove', 'attachment-send', 'forward-draft', 'forward-preview', 'forward-send', 'forward-cancel'].forEach(name => elements['[data-meta-' + name + ']'] = new Element());
    elements['[data-meta-attachment-preview]'].querySelectorAll = () => [];
    elements['[data-wa-reply-emoji-picker]'].hidden = true;
    panel.querySelector = selector => elements[selector]; host.querySelector = selector => selector === '[data-wa-replies]' ? panel : null;
    document.getElementById = id => id === (hub ? 'meta-social-inbox' : 'whatsapp-inbox') ? host : id === (hub ? 'meta-social-replies-bootstrap' : 'whatsapp-replies-bootstrap') ? bootstrap : null;
    document.hidden = false; window.location = {href: 'https://example.test/admin/meta'};
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
        'attachment_invalid', 'attachment_single', 'attachment_review', 'attachment_limit', 'attachment_send', 'forward_send', 'send', 'send_voice'].forEach(name => labels[name] = name.toUpperCase());
    window.DashboardMetaInbox = {selection: () => 0, destroy: () => { state.metaInboxDestroyed = (state.metaInboxDestroyed || 0) + 1; }};
    window.DashboardWhatsAppReplies = {destroy: () => { state.waRepliesDestroyed = (state.waRepliesDestroyed || 0) + 1; }};
    bootstrap.textContent = JSON.stringify({meta_chat: true, hub_chat: hub, event_prefix: hub ? 'meta' : 'whatsapp', text_limit: 1000, conversations_base_url: 'https://example.test/admin/meta/conversations', csrf: 'fixture-csrf', labels});
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
                    attachment_ready: state.attachmentReady, attachment_mime_types: state.attachmentTypes,
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
    vm.createContext(context); vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'public/js/dashboard-meta-replies.js'), 'utf8'), context);
    return {document, window, panel, elements, state, context, navigator, prefix: hub ? 'meta' : 'whatsapp'};
}
async function settle() { for (let index = 0; index < 10; index++) await new Promise(setImmediate); }
function select(test, id) { test.document.dispatchEvent({type: test.prefix + ':conversation-selected', detail: {conversation: id}}); }
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
function metaClick(test, name) { test.elements['[data-meta-' + name + ']'].dispatchEvent({type: 'click'}); }
function posts(test) { return test.state.calls.filter(call => call.options.method === 'POST'); }
function chooseFile(test, file) {
    metaClick(test, 'attachment-pick');
    const picker = test.elements['[data-meta-attachment-input]']; picker.files = [file]; picker.dispatchEvent({type: 'change'});
}
function dispose(test) { test.state.cleanup[0](); }

async function guardedKeyboardText() {
    const test = fixture(); select(test, 7); await settle(); input(test, 'رد على العميل');
    for (const modifiers of [{shiftKey: true}, {ctrlKey: true}, {metaKey: true}, {altKey: true}, {isComposing: true}, {keyCode: 229}]) {
        assert.strictEqual(key(test, modifiers).prevented, undefined);
    }
    const text = test.elements['[data-wa-reply-text]']; text.dispatchEvent({type: 'compositionstart'}); key(test);
    assert.strictEqual(posts(test).length, 0, 'IME composition never sends a partial message');
    text.dispatchEvent({type: 'compositionend'});
    test.state.hold = true; key(test); key(test, {repeat: true}); key(test); submit(test); await settle();
    assert.strictEqual(posts(test).length, 1, 'Holding Enter and repeated submit cannot duplicate a send');
    assert(posts(test)[0].url.endsWith('/7/replies'));
    assert.deepStrictEqual(JSON.parse(posts(test)[0].options.body), {client_request_id: '00000000-0000-4000-8000-000000000001', text: 'رد على العميل', expected_inbound_id: 11});
    test.state.release(); await settle(); assert.strictEqual(text.value, ''); releaseKey(test);
    input(test, 'x'.repeat(1001)); key(test); await settle(); assert.strictEqual(posts(test).length, 1, 'The existing Meta text cap still applies');
    dispose(test);
}
async function recordingNeedsStopAndExplicitSend() {
    const test = fixture(); select(test, 3); await settle();
    click(test, 'voice-record'); await settle();
    assert.strictEqual(test.elements['[data-wa-voice-record]'].attributes['aria-pressed'], 'true');
    assert.strictEqual(test.elements['[data-wa-voice-record]'].disabled, false, 'The same microphone button stops the recording');
    key(test); await settle(); assert.strictEqual(posts(test).length, 0, 'Enter while recording does not finalize or send it'); releaseKey(test);
    click(test, 'voice-record'); await settle();
    assert.strictEqual(test.state.tracksStopped, 1);
    assert.strictEqual(test.elements['[data-wa-voice-record]'].attributes['aria-pressed'], 'false');
    assert.strictEqual(test.elements['[data-wa-voice-preview]'].hidden, false);
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, false);
    assert.strictEqual(test.elements['[data-wa-reply-text]'].readOnly, true);
    assert.strictEqual(test.elements['[data-wa-reply-text]'].focused, true, 'A stopped recording returns focus for Enter');
    assert.strictEqual(posts(test).length, 0, 'Stopping offers a review without sending');
    test.state.hold = true; key(test); key(test, {repeat: true}); submit(test); await settle();
    assert.strictEqual(posts(test).length, 1); assert(posts(test)[0].url.endsWith('/3/voice'));
    assert(posts(test)[0].options.body instanceof Multipart);
    assert.deepStrictEqual(posts(test)[0].options.body.values.map(value => value.name), ['audio', 'client_request_id', 'expected_inbound_id']);
    assert.strictEqual(test.state.revoked.length, 1);
    test.state.release(); await settle(); dispose(test);
}
async function recordingCapabilitiesAndPrivacy() {
    for (const outcome of ['withdraw', 'switch', 'deny', 'dispose']) {
        const test = fixture(); select(test, 1); await settle(); click(test, 'voice-record'); await settle(); click(test, 'voice-record'); await settle();
        if (outcome === 'withdraw') { test.state.voiceReady = false; click(test, 'reply-refresh'); await settle(); key(test); }
        if (outcome === 'switch') { select(test, 2); await settle(); }
        if (outcome === 'deny') test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'denied'}});
        if (outcome === 'dispose') dispose(test);
        assert.strictEqual(posts(test).length, 0, 'Unavailable or private voice drafts never send');
        if (outcome === 'withdraw') assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true);
        else { assert.strictEqual(test.state.revoked.length, 1); assert.strictEqual(test.elements['[data-wa-voice-preview]'].src, ''); }
        if (outcome !== 'dispose') dispose(test);
    }
    const late = fixture(); select(late, 1); await settle(); late.state.microphoneDeferred = true;
    click(late, 'voice-record'); select(late, 2); await settle(); late.state.releaseMicrophones[0](); await settle();
    assert.strictEqual(late.state.recorders.length, 0, 'Late microphone grants cannot attach to another customer');
    assert.strictEqual(late.state.tracksStopped, 1); dispose(late);
}
async function attachmentsShareSendWithoutStaleDialogs() {
    const test = fixture(); select(test, 1); await settle();
    const file = {name: 'menu.pdf', type: 'application/pdf', size: 512}; chooseFile(test, file);
    assert.strictEqual(posts(test).length, 0); assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, false);
    assert.strictEqual(test.elements['[data-wa-reply-text]'].readOnly, true);
    key(test); await settle(); assert.strictEqual(posts(test).length, 1); assert(posts(test)[0].url.endsWith('/attachments'));
    assert.strictEqual(posts(test)[0].options.body.values[0].value, file);
    assert.strictEqual(test.elements['[data-meta-attachment-preview]'].hidden, true); releaseKey(test);
    metaClick(test, 'attachment-pick'); select(test, 2); await settle();
    const picker = test.elements['[data-meta-attachment-input]']; picker.files = [file]; picker.dispatchEvent({type: 'change'});
    assert.strictEqual(test.elements['[data-meta-attachment-preview]'].hidden, true, 'An earlier file picker cannot attach to a new customer');
    chooseFile(test, file); test.state.attachmentTypes = ['image/png']; click(test, 'reply-refresh'); await settle();
    key(test); await settle(); assert.strictEqual(posts(test).length, 1, 'Withdrawn file capabilities prevent a stale selected file send');
    dispose(test);
}
async function unknownCannotRepeat() {
    const test = fixture(); select(test, 1); await settle(); input(test, 'Please check this reply'); test.state.networkFailure = true;
    key(test); await settle(); releaseKey(test); key(test); submit(test); await settle();
    assert.strictEqual(posts(test).length, 1, 'Unknown send outcomes require status resolution before retry');
    assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true); dispose(test);
}
async function hubEventsAreIsolated() {
    const test = fixture(true);
    assert.strictEqual(test.state.waRepliesDestroyed || 0, 0, 'Mounting the social composer preserves the WhatsApp composer in the hub');
    test.document.dispatchEvent({type: 'whatsapp:conversation-selected', detail: {conversation: 55}}); await settle();
    assert.strictEqual(test.state.calls.length, 0, 'A WhatsApp row cannot select a social reply endpoint');
    select(test, 8); await settle(); input(test, 'Instagram reply'); key(test); await settle();
    assert(posts(test)[0].url.endsWith('/admin/meta/conversations/8/replies'));
    test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'switch'}});
    assert.strictEqual(test.panel.hidden, false, 'WhatsApp reset events do not erase social selection');
    test.document.dispatchEvent({type: 'meta:private-reset', detail: {reason: 'switch'}});
    assert.strictEqual(test.panel.hidden, true); dispose(test);
    assert.strictEqual((test.panel.listeners.keydown || []).length, 0, 'SPA cleanup removes keyboard listeners');
    const denied = fixture(true); denied.state.status = 403; select(denied, 1); await settle();
    assert.strictEqual(denied.state.metaInboxDestroyed, 1, 'Denied social replies clear the social inbox');
    assert.strictEqual(denied.state.inboxDestroyed, 0, 'The social composer never destroys a separate WhatsApp engine'); dispose(denied);
}
async function emojiDraftNeverSends() {
    const test = fixture(); select(test, 1); await settle(); input(test, 'أهلًا ');
    click(test, 'reply-emoji'); assert.strictEqual(test.elements['[data-wa-reply-emoji-picker]'].hidden, false);
    const choice = new Element('button'); choice.setAttribute('data-wa-emoji', '😊'); choice.closest = () => choice;
    test.elements['[data-wa-reply-emoji-picker]'].appendChild(choice);
    test.elements['[data-wa-reply-emoji-picker]'].dispatchEvent({type: 'click', target: choice});
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'أهلًا 😊'); assert.strictEqual(posts(test).length, 0);
    select(test, 2); await settle(); assert.strictEqual(test.elements['[data-wa-reply-emoji-picker]'].hidden, true);
    dispose(test);
}

(async () => {
    await guardedKeyboardText(); await recordingNeedsStopAndExplicitSend(); await recordingCapabilitiesAndPrivacy();
    await attachmentsShareSendWithoutStaleDialogs(); await unknownCannotRepeat(); await hubEventsAreIsolated(); await emojiDraftNeverSends();
    console.log('META_REPLIES_UI_PASS 7 scenarios: guarded Enter/text/voice/attachments; microphone toggle/review; uncertain send/privacy; independent hub events; emoji drafts');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
