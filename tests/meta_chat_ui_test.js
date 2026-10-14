'use strict';

// Dependency-free DOM fixtures exercise privacy and SPA behavior without network calls.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class Classes {
    constructor() { this.values = new Set(); }
    add(value) { this.values.add(value); }
    remove(value) { this.values.delete(value); }
    toggle(value, enabled) { if (enabled) this.add(value); else this.remove(value); }
    contains(value) { return this.values.has(value); }
}

class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.dataset = {};
        this.classList = new Classes();
        this.listeners = {};
        this.attributes = {};
        this.text = '';
        this.scrollHeight = 100;
        this.scrollTop = 0;
        this.clientHeight = 100;
        this.rebuilds = 0;
    }
    set textContent(value) { this.text = String(value); this.children = []; this.rebuilds++; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Unsafe innerHTML assignment'); }
    append(...elements) { elements.forEach(element => this.appendChild(element)); }
    appendChild(element) {
        if (element.tag === 'fragment') this.children.push(...element.children);
        else this.children.push(element);
        return element;
    }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener(key, listener) { (this.listeners[key] ||= []).push(listener); }
    removeEventListener(key, listener) {
        this.listeners[key] = (this.listeners[key] || []).filter(item => item !== listener);
    }
    contains(element) { return true; }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(fn => fn(event)); }
    querySelectorAll(selector) { return walk(this).filter(el => selector.split(',').includes(el.tag)); }
    removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
    pause() { this.paused = true; } load() {}

}

function inboxFixture(withUnread = false, withCart = false) {
    const elements = {};
    ['thread-list', 'list-status', 'count', 'messages', 'chat-status', 'title', 'customer', 'alert',
        'connection', 'scroll', 'older', 'more-threads', 'refresh', 'back', 'unread-total'].forEach(name => {
        elements['[data-wa-' + name + ']'] = new Element();
    });
    ['overlay','overlay-body','overlay-title'].forEach(name => elements['[data-meta-' + name + ']'] = new Element());
    elements['[data-meta-overlay]'].hidden = true;
    const panel = new Element();
    const bootstrap = new Element('script');
    const document = new Element();
    const window = new Element();
    const timers = new Map();
    const state = {calls: [], redirected: false, status: 200, emptyNewer: true};
    const labels = {customer: 'Customer', business: 'Business', loading: 'Loading', denied: 'DENIED',
        choose_conversation: 'Choose', empty: 'Empty', unavailable: 'Unavailable', connected: 'Updated',
        no_messages: 'No messages', offline: 'Offline', types: {text: 'Text', other: 'Message', order: 'Cart'},
        cart_title: 'Submitted cart', cart_product: 'Catalog product', cart_quantity: 'Catalog units',
        cart_unit_price: 'Quoted unit price', cart_total: 'Quoted cart subtotal', cart_note: 'Catalog mapping required'};
    const config = {meta_chat: true, csrf: 'fixture-csrf', available: true, rich_labels: {hidden_message: 'Hidden only locally'},
        conversations_url: 'https://example.test/admin/whatsapp/conversations',
        messages_base_url: 'https://example.test/admin/whatsapp/conversations', labels};
    if (withUnread) Object.assign(config, {unread_url: 'https://example.test/admin/whatsapp/unread',
        read_base_url: 'https://example.test/admin/whatsapp/conversations', unread_label: 'Unread', csrf: 'fixture-csrf'});
    bootstrap.textContent = JSON.stringify(config);
    panel.querySelector = selector => elements[selector];
    document.getElementById = id => id === 'whatsapp-inbox' ? panel
        : id === 'whatsapp-inbox-bootstrap' ? bootstrap : null;
    document.createElement = tag => {
        const element = new Element(tag);
        if (withUnread) element.getBoundingClientRect = () => element.dataset.waMessageId === '3'
            ? {top: 200, bottom: 250, left: 0, right: 500} : {top: 20, bottom: 50, left: 0, right: 500};
        return element;
    };
    if (withUnread) elements['[data-wa-scroll]'].getBoundingClientRect = () => ({top: 0, bottom: 100, left: 0, right: 500});
    document.createDocumentFragment = () => new Element('fragment');
    document.documentElement = {lang: 'en'};
    document.hidden = false;
    window.location = {href: 'https://example.test/admin/whatsapp'};
    window.innerHeight = 100; window.innerWidth = 500;
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => { state.cleanup = callback; }};
    let timerId = 0;
    const thread = {channel: 'messenger', account_key: 'ACCOUNT_A', id: 1, name: 'Fixture Customer', phone: '+201000000001',
        last_message_at: '2026-10-09T22:00:00+00:00',
        preview: {text: 'Fixture message', type: 'text', direction: 'inbound'}};
    async function fetch(url, options) {
        state.calls.push({url, options});
        assert.strictEqual(options.cache, 'no-store');
        assert.strictEqual(options.credentials, 'same-origin');
        if (!new URL(url).pathname.endsWith('/hide') && (!withUnread || !new URL(url).pathname.endsWith('/read'))) {
            assert.strictEqual(options.method, undefined, 'Ordinary inbox reads must not send mutations');
        } else {
            assert.strictEqual(options.method, 'POST');
            assert.strictEqual(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
        }
        const parsed = new URL(url);
        const isMessages = parsed.pathname.endsWith('/messages');
        const isNewer = parsed.searchParams.has('after_id');
        if (parsed.pathname.endsWith('/hide')) return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => ({success: true, hidden: true})};
        if (parsed.pathname.endsWith('/read')) {
            assert.deepStrictEqual(JSON.parse(options.body), {seen_message_id: 1}, 'Only the inbound message in the visible viewport may be acknowledged');
            return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => ({success: true})};
        }
        if (parsed.pathname.endsWith('/unread')) return {ok: true, status: 200, redirected: false,
            headers: {get: () => 'application/json'}, json: async () => ({success: true, total_unread: 3,
                conversations: [{id: 1, unread_count: 3, latest_inbound_id: 3}]})};
        const visibleRows = [{id: 1, direction: 'inbound', type: 'text', text: '<img src=x onerror=alert(1)>', sent_at: thread.last_message_at}];
        if (withCart) Object.assign(visibleRows[0], {type: 'order', text: 'Cart preview', cart: {
            catalog_id: '1234567', text: '<script>Cart note</script>', total_price: '445.00', currency: 'EGP',
            product_items: [
                {product_retailer_id: '<img src=x onerror=alert(1)>', quantity: '2', item_price: '100.50', currency: 'EGP'},
                {product_retailer_id: 'SKU_TWO', name: 'Verified product <b>title</b>', quantity: '1', item_price: '244.00', currency: 'EGP'},
            ],
        }});
        if (withUnread) visibleRows.push({id: 2, direction: 'outbound', type: 'text', text: 'Outbound', sent_at: thread.last_message_at},
            {id: 3, direction: 'inbound', type: 'text', text: 'Outside viewport', sent_at: thread.last_message_at});
        return {ok: state.status === 200, status: state.status, redirected: state.redirected,
            headers: {get: () => state.redirected ? 'text/html' : 'application/json'},
            json: async () => isMessages ? {success: true, conversation: state.conversation || thread,
                messages: isNewer && state.emptyNewer ? [] : (state.messages || visibleRows),
                last_id: 1, has_more: false, next_before_id: null}
                : {success: true, conversations: state.threads || [thread], total: 1, next_cursor: null}};
    }
    const context = {window, document, navigator: {onLine: true}, URL, AbortController, Map, Set, Promise, Date,
        CustomEvent: class { constructor(type, options) {this.type=type; this.detail=options.detail;} }, fetch, console, setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: id => timers.delete(id)};
    vm.createContext(context);
    const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'dashboard-meta-inbox.js'), 'utf8');
    vm.runInContext(source, context);
    return {elements, panel, document, window, timers, state, context};
}

async function settle() {
    for (let index = 0; index < 8; index++) await new Promise(setImmediate);
}

function inboxClick(fixture, attribute, id) {
    const target = {dataset: id ? {waThread: String(id)} : {},
        closest: selector => selector === '[' + attribute + ']' ? target : null};
    const listener = fixture.panel.listeners.click[0];
    assert(listener, 'An active page click listener is required');
    listener({target});
}


function walk(root) { return root.children.flatMap(child => [child, ...walk(child)]); }
function inboxAction(test, attribute, dataset) {
    const target = {dataset: dataset || {}, closest: selector => selector === '[' + attribute + ']' ? target : null};
    test.panel.listeners.click[0]({target});
}
async function richInboxRenderingAndActions() {
    const test = inboxFixture(); await settle();
    test.state.messages = [{id:1,direction:'inbound',type:'image',text:'<script>Customer</script>', media:[
        {index:0,type:'image',url:'/admin/whatsapp/conversations/1/messages/1/media/0',name:'Photo'},
        {index:1,type:'audio',url:'/admin/whatsapp/conversations/1/messages/1/media/1',name:'Voice'},
        {index:2,type:'video',url:'/admin/whatsapp/conversations/1/messages/1/media/2',name:'Video'},
        {index:3,type:'document',url:'/admin/whatsapp/conversations/1/messages/1/media/3',name:'<b>Invoice</b>'},
        {index:4,type:'image',url:'https://bad.test/private',name:'Hostile'},
        {index:5,type:'image',url:'javascript:alert(1)',name:'Hostile'},
    ]}];
    test.state.conversation={id:1,name:'Fixture Customer',channel:'messenger',account_key:'ACCOUNT_A'};
    inboxClick(test,'data-wa-thread',1); await settle();
    inboxClick(test,'data-wa-back'); inboxClick(test,'data-wa-thread',1);
    assert(test.elements['[data-wa-thread-list]'].textContent.includes('Fixture message'),'Selected conversation preserves thread preview after loading a smaller DTO');
    assert(walk(test.elements['[data-wa-thread-list]']).find(el=>el.tag==='time').textContent,'Selected conversation preserves thread timestamp');
    const all=walk(test.elements['[data-wa-messages]']);
    assert.strictEqual(all.filter(el=>el.tag==='img').length,1,'Only expected same-origin authenticated media renders');
    assert.strictEqual(all.find(el=>el.tag==='img').loading,'lazy');
    assert.strictEqual(all.find(el=>el.tag==='audio').preload,'none');
    assert.strictEqual(all.find(el=>el.tag==='video').controls,true);
    assert.strictEqual(all.find(el=>el.tag==='a').download,'<b>Invoice</b>');
    assert(test.elements['[data-wa-messages]'].textContent.includes('<script>Customer</script>'));
    inboxAction(test,'data-meta-image',{metaImage:all.find(el=>el.tag==='img').src,metaImageName:'Photo'});
    assert.strictEqual(test.elements['[data-meta-overlay]'].hidden,false);
    assert.strictEqual(test.elements['[data-meta-overlay-body]'].children[0].tag,'img');
    inboxAction(test,'data-meta-overlay-close');
    inboxAction(test,'data-meta-action',{metaMessage:'1',metaAction:'forward'});
    const parts=walk(test.elements['[data-meta-overlay-body]']).filter(el=>el.dataset.metaForwardPart!==undefined);
    assert.strictEqual(parts.length,5,'Mixed text and four safe attachments require explicit part selection');
    inboxAction(test,'data-meta-forward-part',{metaForwardPart:'2'});
    assert(walk(test.elements['[data-meta-overlay-body]']).some(el=>el.tag==='audio'),'Selected voice attachment gets its own preview');
    inboxAction(test,'data-meta-overlay-close');
    const copied=[]; test.context.navigator.clipboard={writeText:async text=>copied.push(text)};
    inboxAction(test,'data-meta-action',{metaMessage:'1',metaAction:'copy'}); await settle();
    assert.deepStrictEqual(copied,['<script>Customer</script>']);
    inboxAction(test,'data-meta-action',{metaMessage:'1',metaAction:'hide'}); assert.strictEqual(test.state.calls.filter(c=>c.options.method==='POST').length,0,'Hide requires its own confirmation');
    inboxAction(test,'data-meta-hide-confirm',{metaHideConfirm:'1'}); await settle();
    assert(test.elements['[data-wa-messages]'].textContent.includes('Hidden only locally'));
    assert.strictEqual(walk(test.elements['[data-wa-messages]']).filter(el=>el.tag==='img').length,0,'Local tombstone releases media');
    assert.strictEqual(test.elements['[data-wa-messages]'].children[0].dataset.waMessageId,'1','Delete preserves message boundary ID');
    test.state.cleanup(); assert.strictEqual(test.elements['[data-wa-messages]'].textContent,''); assert.strictEqual(test.timers.size,0); assert.strictEqual(test.document.listeners.keydown.length,0);
}
async function forwardAccountIsolation() {
    const test=inboxFixture(); await settle();
    test.state.threads=[{id:1,name:'Origin',channel:'messenger',account_key:'ACCOUNT_A'}, {id:2,name:'Allowed',channel:'messenger',account_key:'ACCOUNT_A'}, {id:3,name:'Other business',channel:'messenger',account_key:'ACCOUNT_B'}, {id:4,name:'Other channel',channel:'instagram',account_key:'ACCOUNT_A'}, {id:5,name:'Missing account',channel:'messenger'}];
    inboxClick(test,'data-wa-refresh'); await settle(); inboxClick(test,'data-wa-thread',1); await settle();
    inboxAction(test,'data-meta-action',{metaMessage:'1',metaAction:'forward'});
    const targets=walk(test.elements['[data-meta-overlay-body]']).filter(el=>el.dataset.metaForwardTarget);
    assert.deepStrictEqual(targets.map(el=>el.dataset.metaForwardTarget),['2'],'Forward only offers same-account, same-channel known recipients');
    let draft=null; test.document.addEventListener('meta:forward-draft',event=>draft=event.detail);
    inboxAction(test,'data-meta-forward-target',{metaForwardTarget:'2',metaMessage:'1'}); await settle();
    assert.strictEqual(draft.conversation,2); assert.strictEqual(draft.source_message_id,1);
    assert.strictEqual(test.state.calls.filter(c=>c.options.method==='POST').length,0,'Choosing forward recipient never sends');
    test.state.status=403; inboxClick(test,'data-wa-refresh'); await settle();
    assert.strictEqual(test.elements['[data-wa-messages]'].textContent,'','Auth loss clears private chat'); assert.strictEqual(test.elements['[data-meta-overlay]'].hidden,true);
    test.state.cleanup();
}
class ReplyElement {
    constructor(tag = 'div') { this.tagName = tag; this.value = ''; this.text = ''; this.children = []; this.listeners = {}; this.hidden = false; this.disabled = false; this.attributes = {}; this.classList = {toggle() {}}; }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Reply text must never be parsed as markup'); }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    removeEventListener(name, callback) { this.listeners[name] = (this.listeners[name] || []).filter(item => item !== callback); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(callback => callback(event)); }
    removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    appendChild(child) { this.children.push(child); return child; }
    pause() {} load() {}
    querySelectorAll(selector) { return this.children.filter(el=>selector.split(',').includes(el.tagName)); }
    click() { this.dispatchEvent({type:'click'}); }
}
class Multipart { constructor() { this.values = []; } append(name, value, filename) { this.values.push({name, value, filename}); } }

function repliesFixture() {
    const document = new ReplyElement(), window = new ReplyElement(), host = new ReplyElement(), panel = new ReplyElement(), bootstrap = new ReplyElement('script');
    const elements = {};
    ['reply-status', 'reply-form', 'reply-text', 'reply-send', 'reply-refresh', 'reply-count', 'voice-record',
        'voice-stop', 'voice-cancel', 'voice-send', 'voice-preview', 'voice-time', 'voice-status', 'reply-audit', 'reply-audit-list'].forEach(name => elements['[data-wa-' + name + ']'] = new ReplyElement());
    ['attachment-pick','attachment-input','attachment-preview','attachment-remove','attachment-send','forward-draft','forward-preview','forward-send','forward-cancel'].forEach(name => elements['[data-meta-'+name+']']=new ReplyElement());
    panel.querySelector = selector => elements[selector]; host.querySelector = selector => selector === '[data-wa-replies]' ? panel : null;
    document.getElementById = id => id === 'whatsapp-inbox' ? host : id === 'whatsapp-replies-bootstrap' ? bootstrap : null;
    document.hidden = false; window.location = {href: 'https://example.test/admin/whatsapp'};
    document.createElement = tag => new ReplyElement(tag); document.documentElement = {lang: 'en'};
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
        'expired', 'not_configured', 'voice_unavailable', 'voice_limit', 'voice_ready', 'attachment_limit', 'attachment_review', 'attachment', 'no_inbound', 'recording', 'microphone_denied', 'voice_message'].forEach(name => labels[name] = name.toUpperCase());
    bootstrap.textContent = JSON.stringify({meta_chat:true,conversations_base_url: 'https://example.test/admin/whatsapp/conversations', csrf: 'fixture-csrf', labels});
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
                    attachment_ready:true,attachment_mime_types:['image/png','application/pdf'], reason: state.reason, request_state: state.requestState, voice_ready: state.voiceReady,
                    supported_voice_mime_types: state.voiceReady ? ['audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm;codecs=opus'] : [],
                    recent_replies: [{reply_id: 1, kind: 'text', state: 'ACCEPTED', text: '<script>private reply ' + conversation + '</script>', created_at: '2026-10-10 00:00:00'},
                        {reply_id: 2, kind: 'audio', state: 'UNKNOWN', text: null, created_at: '2026-10-10 00:00:01'}]}};
    }
    let timerId = 0;
    const context = {document, window, navigator, URL: TestURL, Map, Set, Promise, Date, Uint8Array, Array, Blob,
        CustomEvent: class {constructor(type,opts){this.type=type;this.detail=opts.detail;}}, FormData: Multipart, AbortController, fetch, console,
        setTimeout: (callback, delay) => { const id = ++timerId; state.timers.set(id, {callback, delay}); return id; },
        clearTimeout: id => state.timers.delete(id),
        setInterval: callback => { const id = ++timerId; state.intervals.set(id, callback); return id; },
        clearInterval: id => state.intervals.delete(id)};
    vm.createContext(context); vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'public/js/dashboard-meta-replies.js'), 'utf8'), context);
    return {document, window, panel, elements, state, context, navigator};
}
async function replySettle() { for (let index = 0; index < 10; index++) await new Promise(setImmediate); }
function select(test, id) { test.document.dispatchEvent({type: 'whatsapp:conversation-selected', detail: {conversation: id}}); }
function input(test, value) {
    const text = test.elements['[data-wa-reply-text]']; text.value = value; text.dispatchEvent({type: 'input'});
}
function submit(test) { test.elements['[data-wa-reply-form]'].dispatchEvent({type: 'submit', preventDefault() {}}); }
function replyClick(test, name) { test.elements['[data-wa-' + name + ']'].dispatchEvent({type: 'click'}); }

async function acceptedAndSingleSend() {
    const test = repliesFixture(); assert.strictEqual(test.state.calls.length, 0); assert.strictEqual(test.state.mediaCalls, 0);
    select(test, 1); await replySettle(); assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 0);
    assert(test.elements['[data-wa-reply-audit-list]'].textContent.includes('<script>private reply 1</script>'));
    assert(test.elements['[data-wa-reply-audit-list]'].textContent.includes('VOICE_MESSAGE'));
    input(test, '<img src=x onerror=alert(1)>'); test.state.hold = true; submit(test); submit(test); await replySettle();
    const sent = test.state.calls.filter(call => call.options.method === 'POST'); assert.strictEqual(sent.length, 1);
    assert.deepStrictEqual(JSON.parse(sent[0].options.body), {client_request_id: '00000000-0000-4000-8000-000000000001',
        text: '<img src=x onerror=alert(1)>', expected_inbound_id: 11});
    test.state.release(); await replySettle(); assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '');
    assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'ACCEPTED', 'Acceptance must not be described as delivered');
    test.state.cleanup[0]();
    assert.strictEqual(test.elements['[data-wa-reply-audit-list]'].textContent, '', 'Reply history is cleared when the page leaves the shell');
}

async function unknownDoesNotRetry() {
    for (const networkFailure of [false, true]) {
        const test = repliesFixture(); select(test, 1); await replySettle(); input(test, 'Original reply');
        test.state.networkFailure = networkFailure; test.state.outcome = 'UNKNOWN'; submit(test); await replySettle();
        assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, true);
        assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Original reply');
        assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'UNKNOWN');
        const posts = test.state.calls.filter(call => call.options.method === 'POST'); assert.strictEqual(posts.length, 1);
        assert(test.state.calls.some(call => new URL(call.url).searchParams.get('client_request_id') === JSON.parse(posts[0].options.body).client_request_id));
        replyClick(test, 'reply-refresh'); submit(test); await replySettle();
        assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Unknown outcomes never trigger a resend');
        test.state.requestState = 'ACCEPTED'; replyClick(test, 'reply-refresh'); await replySettle();
        assert.strictEqual(test.elements['[data-wa-reply-text]'].value, '', 'Only a trusted resolved outcome clears the uncertain text');
        test.state.cleanup[0]();
    }
}

async function staleSwitchAndExpiry() {
    const test = repliesFixture(); select(test, 1); await replySettle(); input(test, 'Private customer one');
    test.state.hold = true; submit(test); await replySettle(); const request = test.state.calls.find(call => call.options.method === 'POST');
    select(test, 2); await replySettle(); input(test, 'Customer two draft');
    assert.strictEqual(request.options.signal.aborted, true); test.state.release(); await replySettle();
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Customer two draft', 'A late accepted response cannot clear a different customer draft');
    assert(!test.elements['[data-wa-reply-audit-list]'].textContent.includes('private reply 1'), 'A different customer receives only its own reply history');
    test.state.cleanup[0]();
    for (const redirected of [false, true]) {
        const expired = repliesFixture(); select(expired, 1); await replySettle(); input(expired, 'Private reply');
        expired.state.redirected = redirected; expired.state.status = redirected ? 200 : 403; submit(expired); await replySettle();
        assert.strictEqual(expired.elements['[data-wa-reply-text]'].value, ''); assert.strictEqual(expired.state.inboxDestroyed, 1);
        expired.state.cleanup[0]();
    }
}

async function voicePreviewAndLifecycle() {
    const test = repliesFixture(); select(test, 1); await replySettle(); assert.strictEqual(test.state.mediaCalls, 0, 'Selection never requests the microphone');
    replyClick(test, 'voice-record'); await replySettle(); assert.strictEqual(test.state.mediaCalls, 1);
    assert.strictEqual(test.state.recorders[0].mimeType, 'audio/ogg;codecs=opus');
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/voice')).length, 0, 'Recording alone never sends audio');
    replyClick(test, 'voice-stop'); await replySettle();
    assert.strictEqual(test.elements['[data-wa-voice-preview]'].hidden, false); assert.strictEqual(test.state.tracksStopped, 1);
    replyClick(test, 'voice-send'); replyClick(test, 'voice-send'); await replySettle();
    const uploads = test.state.calls.filter(call => call.url.endsWith('/voice')); assert.strictEqual(uploads.length, 1);
    assert(uploads[0].options.body instanceof Multipart); assert.strictEqual(uploads[0].options.headers['Content-Type'], undefined, 'The browser owns multipart boundaries');
    assert.deepStrictEqual(uploads[0].options.body.values.map(item => item.name), ['audio', 'client_request_id', 'expected_inbound_id']);
    assert.strictEqual(test.state.revoked.length, 1, 'Send releases the private audio object URL');
    assert.strictEqual(test.elements['[data-wa-voice-preview]'].src, '');
    replyClick(test, 'voice-record'); await replySettle();
    test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'offline'}});
    assert.strictEqual(test.state.tracksStopped >= 2, true, 'Privacy reset stops microphone tracks');
    assert.strictEqual(test.state.timers.size, 0); assert.strictEqual(test.state.intervals.size, 0);
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, ''); test.state.cleanup[0]();
}

async function voiceFormatLimitsAndCancel() {
    const unavailable = repliesFixture(); unavailable.state.voiceReady = false; select(unavailable, 1); await replySettle();
    assert.strictEqual(unavailable.elements['[data-wa-voice-record]'].disabled, true);
    assert.strictEqual(unavailable.elements['[data-wa-voice-status]'].textContent, 'VOICE_UNAVAILABLE'); unavailable.state.cleanup[0]();
    const webm = repliesFixture(); webm.state.supported = ['audio/webm;codecs=opus']; select(webm, 1); await replySettle();
    replyClick(webm, 'voice-record'); await replySettle(); assert.strictEqual(webm.state.recorders[0].mimeType, 'audio/webm;codecs=opus');
    const limit = Array.from(webm.state.timers.values()).find(timer => timer.delay === 60000); assert(limit); limit.callback(); await replySettle();
    assert.strictEqual(webm.elements['[data-wa-voice-preview]'].hidden, false, 'The 60 second cap stops recording for manual preview');
    replyClick(webm, 'voice-cancel'); assert.strictEqual(webm.state.revoked.length, 1);
    assert.strictEqual(webm.state.calls.filter(call => call.url.endsWith('/voice')).length, 0); webm.state.cleanup[0]();

    const oversized = repliesFixture(); select(oversized, 1); await replySettle(); replyClick(oversized, 'voice-record'); await replySettle();
    oversized.state.recorders[0].ondataavailable({data: new Blob([new Uint8Array(8 * 1024 * 1024 + 1)])});
    assert.strictEqual(oversized.elements['[data-wa-reply-status]'].textContent, 'VOICE_LIMIT');
    assert.strictEqual(oversized.elements['[data-wa-voice-preview]'].hidden, true);
    assert.strictEqual(oversized.state.tracksStopped, 1); assert.strictEqual(oversized.state.objectUrls.length, 0);
    assert.strictEqual(oversized.state.calls.filter(call => call.url.endsWith('/voice')).length, 0);
    oversized.state.cleanup[0]();
}

async function cancelledMicrophoneGrant() {
    const test = repliesFixture(); select(test, 1); await replySettle(); test.state.microphoneDeferred = true;
    replyClick(test, 'voice-record'); await replySettle(); replyClick(test, 'voice-cancel'); replyClick(test, 'voice-record'); await replySettle();
    assert.strictEqual(test.state.releaseMicrophones.length, 2);
    test.state.releaseMicrophones[0](); await replySettle();
    assert.strictEqual(test.state.recorders.length, 0, 'A cancelled old microphone grant cannot start a newer recording');
    assert.strictEqual(test.state.tracksStopped, 1, 'Cancelled late microphone grants release all tracks');
    test.state.releaseMicrophones[1](); await replySettle();
    assert.strictEqual(test.state.recorders.length, 1); test.state.cleanup[0]();
    assert.strictEqual(test.state.tracksStopped, 2);
}

async function trustedPreclaimFailure() {
    const test = repliesFixture(); select(test, 1); await replySettle(); input(test, 'Reply before new customer message');
    test.state.preclaimFailure = 'STALE_INBOUND'; submit(test); await replySettle();
    assert.strictEqual(test.elements['[data-wa-reply-status]'].textContent, 'FAILED');
    assert.strictEqual(test.elements['[data-wa-reply-text]'].value, 'Reply before new customer message');
    assert.strictEqual(test.state.calls.filter(call => call.options.method === 'POST').length, 1, 'Stale inbound failures do not resend automatically');
    test.state.preclaimFailure = null; submit(test); await replySettle();
    const sends = test.state.calls.filter(call => call.options.method === 'POST'); assert.strictEqual(sends.length, 2);
    assert.strictEqual(JSON.parse(sends[1].options.body).expected_inbound_id, 12);
    assert.notStrictEqual(JSON.parse(sends[0].options.body).client_request_id, JSON.parse(sends[1].options.body).client_request_id);
    test.state.cleanup[0]();
}


async function attachmentAndForwardExplicitSend() {
    const test=repliesFixture(); select(test,1); await replySettle();
    const picked=new Blob(['fixture'],{type:'image/png'}); picked.name='<b>photo.png</b>';
    test.elements['[data-meta-attachment-pick]'].dispatchEvent({type:'click'});
    test.elements['[data-meta-attachment-input]'].files=[picked]; test.elements['[data-meta-attachment-input]'].dispatchEvent({type:'change'});
    assert(test.elements['[data-meta-attachment-preview]'].textContent.includes('<b>photo.png</b>')); assert.strictEqual(test.state.calls.filter(c=>c.options.method==='POST').length,0);
    test.state.outcome='UNKNOWN'; test.state.networkFailure=true;
    test.elements['[data-meta-attachment-send]'].dispatchEvent({type:'click'}); await replySettle();
    const posts=test.state.calls.filter(c=>c.url.endsWith('/attachments')); assert.strictEqual(posts.length,1); assert.strictEqual(posts[0].options.headers['Content-Type'],undefined); assert.deepStrictEqual(posts[0].options.body.values.map(v=>v.name),['attachment','client_request_id','expected_inbound_id']);
    test.elements['[data-meta-attachment-send]'].dispatchEvent({type:'click'}); await replySettle(); assert.strictEqual(test.state.calls.filter(c=>c.url.endsWith('/attachments')).length,1,'Unknown file upload cannot be resent');
    test.state.requestState='ACCEPTED'; replyClick(test,'reply-refresh'); await replySettle(); assert.strictEqual(test.elements['[data-meta-attachment-preview]'].hidden,true); assert.strictEqual(test.state.revoked.length,1);
    test.state.requestState=null; test.state.networkFailure=false; test.state.outcome='ACCEPTED';
    test.document.dispatchEvent({type:'meta:forward-draft',detail:{conversation:1,source_message_id:9,preview:'Forward preview'}}); await replySettle();
    assert.strictEqual(test.state.calls.filter(c=>c.url.endsWith('/forward')).length,0,'Forward preview is never auto-sent');
    test.elements['[data-meta-forward-send]'].dispatchEvent({type:'click'}); await replySettle();
    const forwarded=test.state.calls.filter(c=>c.url.endsWith('/forward')); assert.strictEqual(forwarded.length,1); assert.strictEqual(JSON.parse(forwarded[0].options.body).source_message_id,9); assert.strictEqual(JSON.parse(forwarded[0].options.body).source_kind,'text');
    test.state.cleanup[0](); assert.strictEqual(test.elements['[data-meta-forward-preview]'].textContent,'');
    const noInbound=repliesFixture(); noInbound.state.canReply=false; noInbound.state.reason='NO_INBOUND'; select(noInbound,1); await replySettle(); assert.strictEqual(noInbound.elements['[data-wa-reply-status]'].textContent,'NO_INBOUND'); noInbound.state.cleanup[0]();
}
(async()=>{
 await richInboxRenderingAndActions(); await forwardAccountIsolation();
 await acceptedAndSingleSend(); await unknownDoesNotRetry(); await staleSwitchAndExpiry(); await voicePreviewAndLifecycle(); await voiceFormatLimitsAndCancel(); await cancelledMicrophoneGrant(); await trustedPreclaimFailure(); await attachmentAndForwardExplicitSend();
 console.log('META_CHAT_UI_PASS safe media, account-scoped forward preview, hide tombstones, explicit file/voice sends, unknown reconciliation and SPA privacy');
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
