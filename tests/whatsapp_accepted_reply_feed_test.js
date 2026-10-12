'use strict';

// Run the two production modules together with deterministic, dependency-free DOM/network fixtures.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class Element {
    constructor(tag = 'div') {
        this.tag = tag; this.children = []; this.dataset = {}; this.listeners = {}; this.attributes = {};
        this.text = ''; this.value = ''; this.hidden = false; this.disabled = false;
        this.scrollHeight = 1000; this.clientHeight = 100; this.scrollTop = 0;
        this.classList = {values: new Set(), add(value) { this.values.add(value); }, remove(value) { this.values.delete(value); },
            toggle(value, enabled) { if (enabled) this.add(value); else this.remove(value); }};
    }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Reply content must stay plain text'); }
    append(...elements) { elements.forEach(element => this.appendChild(element)); }
    appendChild(element) { if (element.tag === 'fragment') this.children.push(...element.children); else this.children.push(element); return element; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] || []).filter(item => item !== listener); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(listener => listener(event)); }
    contains() { return true; }
}
class Event { constructor(type, options) { this.type = type; this.detail = options && options.detail || {}; } }
const accepted = (replyId = 17, extras = {}) => Object.assign({reply_id: replyId, kind: 'text', state: 'ACCEPTED', source: 'dashboard_api',
    text: '<script>accepted reply</script>', created_at: '2026-10-12 00:00:01', actor_id: 7,
    client_request_id: '00000000-0000-4000-8000-000000000001', accepted_at: '2026-10-12T00:00:02Z', projected_message_id: null}, extras);

function fixture(textLimit = 4096, route = 'whatsapp') {
    const document = new Element(), window = new Element(), host = new Element(), replyPanel = new Element();
    const elements = {};
    ['thread-list', 'list-status', 'count', 'messages', 'chat-status', 'title', 'customer', 'alert', 'connection',
        'scroll', 'older', 'more-threads', 'refresh', 'back', 'unread-total', 'reply-status', 'reply-form',
        'reply-text', 'reply-send', 'reply-refresh', 'reply-count', 'reply-audit', 'reply-audit-list'].forEach(name => {
        elements['[data-wa-' + name + ']'] = new Element();
    });
    host.querySelector = selector => selector === '[data-wa-replies]' ? replyPanel : elements[selector] || null;
    const channels = ['', 'messenger', 'instagram'].map(channel => {
        const button = new Element('button'); button.dataset.metaChannel = channel; return button;
    });
    host.querySelectorAll = selector => selector === '[data-meta-channel]' ? channels : [];
    replyPanel.querySelector = selector => elements[selector] || null;
    const bootstrap = new Element(), replyBootstrap = new Element();
    const labels = {customer: 'Customer', business: 'Business', loading: 'Loading', denied: 'Denied', choose_conversation: 'Choose',
        empty: 'Empty', unavailable: 'Unavailable', connected: 'Connected', no_messages: 'No messages', offline: 'Offline',
        types: {text: 'Text', audio: 'Audio', other: 'Message'}};
    bootstrap.textContent = JSON.stringify({available: true, csrf: 'fixture-csrf', labels,
        conversations_url: 'https://example.test/' + route + '/conversations', messages_base_url: 'https://example.test/' + route + '/conversations',
        read_base_url: 'https://example.test/' + route + '/conversations', unread_url: 'https://example.test/unread', unread_label: 'Unread'});
    replyBootstrap.textContent = JSON.stringify({csrf: 'fixture-csrf', text_limit: textLimit, conversations_base_url: 'https://example.test/' + route + '/conversations',
        labels: {loading: 'Loading', accepted: 'ACCEPTED', ready: 'Ready', unavailable: 'Unavailable', sending: 'Sending',
            denied: 'Denied', offline: 'Offline', unknown: 'Unknown', failed: 'Failed', voice_message: 'Voice message',
            api_source: 'Dashboard reply · accepted by WhatsApp'}});
    document.getElementById = id => ({'whatsapp-inbox': host, 'whatsapp-inbox-bootstrap': bootstrap, 'whatsapp-replies-bootstrap': replyBootstrap})[id] || null;
    document.documentElement = {lang: 'en'}; document.hidden = false;
    document.createElement = tag => {
        const element = new Element(tag);
        element.getBoundingClientRect = () => element.dataset.waMessageId === '3'
            ? {top: 200, bottom: 250, left: 0, right: 500} : {top: 20, bottom: 50, left: 0, right: 500};
        return element;
    };
    document.createDocumentFragment = () => new Element('fragment');
    elements['[data-wa-scroll]'].getBoundingClientRect = () => ({top: 0, bottom: 100, left: 0, right: 500});
    window.innerWidth = 500; window.innerHeight = 100; window.location = {href: 'https://example.test/admin/whatsapp'};
    const state = {calls: [], snapshots: [], cleanup: [], recent: [accepted()], raw: [
        {id: 1, type: 'text', direction: 'inbound', text: 'Inbound', sent_at: '2026-10-12T00:00:00Z'},
        {id: 3, type: 'text', direction: 'inbound', text: 'Outside viewport', sent_at: '2026-10-12T00:00:03Z'}
    ], newer: [], older: [], nextBefore: 1, replyStatus: 200, delayState: false, stateResolvers: [],
        delayThreads: false, threadResolvers: [], threadChannel: route === 'meta' ? 'messenger' : null, sendOutcome: 'ACCEPTED', sends: 0};
    document.addEventListener('whatsapp:accepted-replies', event => state.snapshots.push(event.detail));
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => state.cleanup.push(callback)};
    window.crypto = {randomUUID: () => '00000000-0000-4000-8000-000000000001'};
    const navigator = {onLine: true};
    async function fetch(url, options) {
        const parsed = new URL(url), match = parsed.pathname.match(/conversations\/(\d+)/), conversation = match ? Number(match[1]) : 0;
        state.calls.push({url, options});
        assert.strictEqual(options.cache, 'no-store'); assert.strictEqual(options.credentials, 'same-origin');
        let data, status = 200;
        if (parsed.pathname.endsWith('/reply-state')) {
            const recent = state.recent.map(row => Object.assign({}, row)); status = state.replyStatus;
            if (state.delayState) await new Promise(resolve => state.stateResolvers.push(resolve));
            data = {success: status === 200, available: true, can_reply: true, latest_inbound_id: 3, recent_replies: recent};
        } else if (parsed.pathname.endsWith('/replies')) {
            state.sends++; assert.strictEqual(options.method, 'POST');
            state.recent = [accepted(42, {text: JSON.parse(options.body).text, state: state.sendOutcome})];
            data = {success: true, state: state.sendOutcome, reply_id: 42};
        } else if (parsed.pathname.endsWith('/read')) {
            assert.deepStrictEqual(JSON.parse(options.body), {seen_message_id: 1}, 'API IDs and invisible inbound rows never affect read position');
            data = {success: true};
        } else if (parsed.pathname === '/unread') {
            data = {success: true, total_unread: 2, conversations: [{id: 1, unread_count: 2}]};
        } else if (parsed.pathname.endsWith('/messages')) {
            const older = parsed.searchParams.has('before_id'), newer = parsed.searchParams.has('after_id');
            data = {success: true, conversation: {id: conversation, name: 'Customer ' + conversation},
                messages: older ? state.older : newer ? state.newer : state.raw, last_id: newer && state.newer.length ? 4 : 3,
                next_before_id: older ? null : state.nextBefore, has_more: false};
        } else {
            const channel = parsed.searchParams.get('channel');
            if (state.delayThreads) await new Promise(resolve => state.threadResolvers.push(resolve));
            data = {success: true, conversations: channel ? [{id: channel === 'instagram' ? 201 : 101, name: 'Meta customer',
                channel, account_label: channel + ' account'}] : [{id: 1, name: 'Customer 1', channel: state.threadChannel},
                    {id: 2, name: 'Customer 2', channel: state.threadChannel}], total: channel ? 1 : 2, next_cursor: null};
        }
        return {ok: status === 200, status, redirected: false, headers: {get: () => 'application/json'}, json: async () => data};
    }
    let nextTimer = 0; const timers = new Map();
    const context = {window, document, navigator, fetch, URL, AbortController, CustomEvent: Event, Map, Set, Promise, Date,
        console, setTimeout: callback => { timers.set(++nextTimer, callback); return nextTimer; }, clearTimeout: id => timers.delete(id)};
    vm.createContext(context);
    for (const name of ['dashboard-whatsapp-inbox.js', 'dashboard-whatsapp-replies.js']) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'public/js', name), 'utf8'), context);
    }
    return {elements, document, window, host, replyPanel, navigator, state, timers, context, channels};
}
async function settle() { for (let index = 0; index < 12; index++) await new Promise(setImmediate); }
function click(test, attribute, id) {
    const target = {dataset: id ? {waThread: String(id)} : {}, closest: selector => selector === '[' + attribute + ']' ? target : null};
    test.host.dispatchEvent({type: 'click', target});
}
function channelClick(test, channel) {
    const target = test.channels.find(button => button.dataset.metaChannel === channel);
    target.closest = selector => selector === '[data-meta-channel]' ? target : null;
    test.host.dispatchEvent({type: 'click', target});
}
function bubbleRows(test) { return test.elements['[data-wa-messages]'].children; }
function apiRows(test) { return bubbleRows(test).filter(row => row.dataset.waApiReplyId); }
function refreshReplies(test) { test.elements['[data-wa-reply-refresh]'].dispatchEvent({type: 'click'}); }
function postCount(test, suffix) { return test.state.calls.filter(call => call.options.method === 'POST' && (!suffix || new URL(call.url).pathname.endsWith(suffix))).length; }
function publish(test, details) {
    const normalized = Object.assign({}, details);
    if (Array.isArray(normalized.replies)) normalized.replies = normalized.replies.map(reply => Object.assign({}, reply, {id: reply.reply_id}));
    test.document.dispatchEvent(new Event('whatsapp:accepted-replies', {detail: Object.assign({}, test.window.DashboardWhatsAppInbox.selectionState(), normalized)}));
}
function close(test) { test.window.DashboardWhatsAppReplies.destroy(); test.window.DashboardWhatsAppInbox.destroy(); }

async function reloadAndSingleSend() {
    const test = fixture(); await settle(); click(test, 'data-wa-thread', 1); await settle();
    assert.strictEqual(test.state.recent[0].reply_id, 17); assert.strictEqual('id' in test.state.recent[0], false, 'Fixture uses the production reply-state reply_id contract');
    assert.strictEqual(apiRows(test).length, 1, 'An accepted historic API reply is visible as soon as reply-state loads');
    assert(apiRows(test)[0].textContent.includes('<script>accepted reply</script>'));
    assert(apiRows(test)[0].textContent.includes('Dashboard reply · accepted by WhatsApp'));
    assert.strictEqual(apiRows(test)[0].dataset.waMessageId, undefined);
    assert.strictEqual(apiRows(test)[0].dataset.waApiReplyId, 'api-reply:17');
    assert.deepStrictEqual(bubbleRows(test).map(row => row.dataset.waMessageId || row.dataset.waApiReplyId), ['1', 'api-reply:17', '3']);
    assert.strictEqual(postCount(test, '/replies'), 0, 'Loading history never sends a reply');
    const text = test.elements['[data-wa-reply-text]']; text.value = 'New explicit reply'; text.dispatchEvent({type: 'input'});
    test.elements['[data-wa-reply-form]'].dispatchEvent({type: 'submit', preventDefault() {}}); await settle();
    assert.strictEqual(postCount(test, '/replies'), 1);
    assert.strictEqual(apiRows(test).length, 1); assert(apiRows(test)[0].textContent.includes('New explicit reply'));
    assert.strictEqual(apiRows(test)[0].dataset.waApiReplyId, 'api-reply:42');
    assert.strictEqual(text.value, ''); close(test);
}

async function echoDeduplicationAndCursors() {
    const test = fixture(); test.state.recent = [accepted(999999, {projected_message_id: 4})];
    await settle(); click(test, 'data-wa-thread', 1); await settle();
    assert.strictEqual(apiRows(test).length, 1, 'An unloaded projected ID cannot hide the accepted reply');
    test.state.newer = [{id: 4, type: 'text', direction: 'outbound', text: '<script>accepted reply</script>', sent_at: '2026-10-12T00:00:02Z'}];
    click(test, 'data-wa-refresh'); await settle();
    assert.strictEqual(apiRows(test).length, 0, 'The projected echo replaces the API fallback instead of duplicating it');
    assert.strictEqual(bubbleRows(test).filter(row => row.textContent.includes('<script>accepted reply</script>')).length, 1);
    const firstPoll = test.state.calls.find(call => new URL(call.url).searchParams.has('after_id'));
    assert.strictEqual(new URL(firstPoll.url).searchParams.get('after_id'), '3', 'Reply audit IDs never move the raw cursor');
    test.state.newer = []; click(test, 'data-wa-refresh'); await settle();
    const polls = test.state.calls.filter(call => new URL(call.url).searchParams.has('after_id'));
    assert.strictEqual(new URL(polls[polls.length - 1].url).searchParams.get('after_id'), '4');
    assert(test.state.calls.filter(call => new URL(call.url).pathname.endsWith('/read')).every(call => JSON.parse(call.options.body).seen_message_id === 1));
    close(test);
}

async function strictAcceptedSnapshotAndVoice() {
    const test = fixture(); test.state.recent = [accepted(1), accepted(2, {state: 'FAILED'}), accepted(3, {state: 'UNKNOWN'}),
        accepted(4, {kind: 'audio', text: null}), accepted(5, {source: 'untrusted'}), accepted(6, {reply_id: '6'}),
        accepted(7, {accepted_at: null, created_at: 'bad date'}), accepted(8, {reply_id: undefined, id: 8}),
        accepted(9, {reply_id: null}), accepted(10, {reply_id: 0}), accepted(11, {reply_id: ''})];
    await settle(); click(test, 'data-wa-thread', 1); await settle();
    assert.deepStrictEqual(apiRows(test).map(row => row.dataset.waApiReplyId), ['api-reply:1', 'api-reply:4']);
    assert(apiRows(test)[1].textContent.includes('Voice message'));
    assert(!apiRows(test)[1].children.some(row => row.tag === 'audio'), 'Deleted voice media is represented by a placeholder, never fetched again');
    assert.strictEqual(test.state.snapshots[0].replies.some(reply => reply.state !== 'ACCEPTED'), false);
    assert.strictEqual(test.state.snapshots[0].replies.some(reply => 'client_request_id' in reply || 'actor_id' in reply), false);
    test.state.recent = []; refreshReplies(test); await settle(); assert.strictEqual(apiRows(test).length, 0, 'An authoritative empty snapshot clears earlier reply metadata');
    test.state.recent = Array.from({length: 25}, (_, index) => accepted(index + 1)); refreshReplies(test); await settle();
    assert.strictEqual(apiRows(test).length, 20, 'API reply history is bounded by the server policy'); close(test);
}

async function selectionAndLateResponses() {
    const test = fixture(); await settle(); test.state.delayState = true; click(test, 'data-wa-thread', 1); await settle();
    const oldSelection = test.window.DashboardWhatsAppInbox.selectionState();
    test.state.recent = [accepted(29, {text: 'Customer two accepted reply'})]; click(test, 'data-wa-thread', 2); await settle();
    test.state.stateResolvers[1](); await settle(); assert(apiRows(test)[0].textContent.includes('Customer two'));
    test.state.stateResolvers[0](); await settle(); assert(!test.elements['[data-wa-messages]'].textContent.includes('<script>accepted reply</script>'));
    test.state.delayState = false; click(test, 'data-wa-thread', 1); await settle();
    const latest = test.window.DashboardWhatsAppInbox.selectionState(); assert(latest.generation > oldSelection.generation);
    publish(test, {conversation: 1, generation: oldSelection.generation, replies: [accepted(88, {text: 'Stale private reply'})]});
    assert(!test.elements['[data-wa-messages]'].textContent.includes('Stale private reply'));
    publish(test, {conversation: 2, generation: latest.generation, replies: [accepted(89, {text: 'Other customer reply'})]});
    assert(!test.elements['[data-wa-messages]'].textContent.includes('Other customer reply')); close(test);
}

async function privacyCleanupAndDeniedState() {
    for (const reason of ['offline', 'hidden']) {
        const test = fixture(); await settle(); click(test, 'data-wa-thread', 1); await settle(); assert.strictEqual(apiRows(test).length, 1);
        if (reason === 'offline') { test.navigator.onLine = false; test.window.dispatchEvent({type: 'offline'}); }
        else { test.document.hidden = true; test.document.dispatchEvent({type: 'visibilitychange'}); }
        assert.strictEqual(apiRows(test).length, 0); assert.strictEqual(test.elements['[data-wa-reply-audit-list]'].textContent, '');
        publish(test, {replies: [accepted(87, {text: 'Late private reply'})]});
        assert(!test.elements['[data-wa-messages]'].textContent.includes('Late private reply')); close(test);
    }
    const test = fixture(); await settle(); click(test, 'data-wa-thread', 1); await settle();
    test.state.replyStatus = 403; refreshReplies(test); await settle();
    assert.strictEqual(test.elements['[data-wa-messages]'].textContent, ''); assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(), 0);
    assert.strictEqual(test.elements['[data-wa-reply-audit-list]'].textContent, '');
    publish(test, {conversation: 1, generation: 1, replies: [accepted()]}); assert.strictEqual(apiRows(test).length, 0); close(test);
}

async function scrollAndOlderPaging() {
    const test = fixture(); test.state.raw = [test.state.raw[1]]; test.state.nextBefore = 3;
    await settle(); click(test, 'data-wa-thread', 1); await settle();
    const scroll = test.elements['[data-wa-scroll]']; scroll.scrollTop = 180;
    test.state.recent = [accepted(2, {text: 'Accepted while reading older history'})]; refreshReplies(test); await settle();
    assert.strictEqual(scroll.scrollTop, 180, 'An API snapshot does not force the user away from older history');
    test.state.older = [{id: 1, type: 'text', direction: 'inbound', text: 'Older projected message', sent_at: '2026-10-11T23:00:00Z'}];
    click(test, 'data-wa-older'); await settle();
    const request = test.state.calls.find(call => new URL(call.url).searchParams.has('before_id'));
    assert.strictEqual(new URL(request.url).searchParams.get('before_id'), '3', 'Older paging retains the raw message cursor');
    assert.strictEqual(scroll.scrollTop, 180); assert.strictEqual(apiRows(test).length, 1);
    test.window.DashboardWhatsAppReplies.destroy(); assert.strictEqual(apiRows(test).length, 0, 'Reply component cleanup clears its API feed metadata');
    close(test); assert.strictEqual(test.elements['[data-wa-messages]'].textContent, '');
    assert.strictEqual((test.document.listeners['whatsapp:accepted-replies'] || []).length, 1, 'Only the test observer remains after module cleanup');
}

async function liveChannelFiltersAndEpochPrivacy() {
    const test = fixture(); await settle(); click(test, 'data-wa-thread', 1); await settle();
    const selected = test.window.DashboardWhatsAppInbox.selectionState(); assert.strictEqual(apiRows(test).length, 1);
    test.state.delayThreads = true; channelClick(test, 'messenger'); await settle();
    assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(), 0); assert.strictEqual(apiRows(test).length, 0);
    assert.strictEqual(test.elements['[data-wa-reply-audit-list]'].textContent, '', 'Changing Meta channels clears the WhatsApp reply metadata');
    channelClick(test, 'instagram'); await settle();
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('channel') === 'messenger'));
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('channel') === 'instagram'));
    test.state.threadResolvers[1](); await settle();
    assert(test.elements['[data-wa-thread-list]'].textContent.includes('Instagram · instagram account'));
    test.state.threadResolvers[0](); await settle();
    assert(!test.elements['[data-wa-thread-list]'].textContent.includes('Messenger'), 'An older channel response cannot replace the new channel thread list');
    publish(test, {conversation: selected.conversation, generation: selected.generation, replies: [accepted(91, {text: 'Old WhatsApp private reply'})]});
    assert.strictEqual(apiRows(test).length, 0);
    assert.strictEqual(test.channels.find(button => button.dataset.metaChannel === 'instagram').attributes['aria-pressed'], 'true');
    assert.strictEqual(test.channels.find(button => button.dataset.metaChannel === 'messenger').attributes['aria-pressed'], 'false');
    close(test);
}

async function liveUnicodeTextLimits() {
    const test = fixture(3); await settle(); click(test, 'data-wa-thread', 1); await settle();
    const text = test.elements['[data-wa-reply-text]'], count = test.elements['[data-wa-reply-count]'], send = test.elements['[data-wa-reply-send]'];
    assert.strictEqual(count.textContent, '0 / 3');
    text.value = '😀😀😀'; text.dispatchEvent({type: 'input'});
    assert.strictEqual(count.textContent, '3 / 3'); assert.strictEqual(send.disabled, false, 'Configured limits count Unicode code points, preserving the live Meta composer');
    text.value += '😀'; text.dispatchEvent({type: 'input'}); assert.strictEqual(count.textContent, '4 / 3'); assert.strictEqual(send.disabled, true);
    text.value = '😀😀😀'; text.dispatchEvent({type: 'input'});
    test.elements['[data-wa-reply-form]'].dispatchEvent({type: 'submit', preventDefault() {}}); await settle();
    assert.strictEqual(count.textContent, '0 / 3'); assert(apiRows(test)[0].textContent.includes('😀😀😀'));
    test.state.recent = [accepted(17, {text: '😀'.repeat(4096)})]; refreshReplies(test); await settle();
    const body = apiRows(test)[0].children[0].children.find(row => row.tag === 'p');
    assert.strictEqual(Array.from(body.textContent).length, 4096, 'Accepted WhatsApp text stays intact at the Unicode character limit');
    close(test);
}

async function freshMetaIdCollisionAndNamespace() {
    for (const knownChannel of [true, false]) {
        const test = fixture(1000, 'meta'); await settle();
        if (!knownChannel) { test.state.threadChannel = null; click(test, 'data-wa-refresh'); await settle(); }
        click(test, 'data-wa-thread', 1); await settle();
        const selected = test.window.DashboardWhatsAppInbox.selectionState();
        assert.strictEqual(selected.channel, knownChannel ? 'messenger' : '', 'Meta all-filter derives channel from the actual thread and keeps unknown Meta rows ineligible');
        assert.strictEqual(apiRows(test).length, 0, 'A fresh Meta conversation with the same numeric ID cannot render WhatsApp API replies');
        publish(test, {channel: 'whatsapp', replies: [accepted(27, {text: 'Cross-channel WhatsApp reply'})]});
        assert.strictEqual(apiRows(test).length, 0);
        const text = test.elements['[data-wa-reply-text]']; text.value = 'Explicit Meta draft'; text.dispatchEvent({type: 'input'});
        assert.strictEqual(test.elements['[data-wa-reply-send]'].disabled, false, 'The existing Meta composer stays active');
        test.elements['[data-wa-reply-form]'].dispatchEvent({type: 'submit', preventDefault() {}}); await settle();
        assert.strictEqual(postCount(test, '/replies'), 1); assert.strictEqual(apiRows(test).length, 0);
        assert.strictEqual(test.elements['[data-wa-reply-count]'].textContent, '0 / 1000'); close(test);
    }
    const test = fixture(); await settle(); click(test, 'data-wa-thread', 1); await settle();
    publish(test, {namespace: 'https://example.test/meta/conversations', replies: [accepted(51, {text: 'Wrong endpoint namespace'})]});
    assert(!test.elements['[data-wa-messages]'].textContent.includes('Wrong endpoint namespace')); close(test);
}

(async () => {
    await reloadAndSingleSend(); await echoDeduplicationAndCursors(); await strictAcceptedSnapshotAndVoice();
    await selectionAndLateResponses(); await privacyCleanupAndDeniedState(); await scrollAndOlderPaging();
    await liveChannelFiltersAndEpochPrivacy(); await liveUnicodeTextLimits();
    await freshMetaIdCollisionAndNamespace();
    console.log('WHATSAPP_ACCEPTED_REPLY_FEED_PASS reload/send visibility, echo dedupe, strict state/voice, scoped generations, privacy, raw cursors/read positions, scrolling, live Meta channels/Unicode limits and cross-channel isolation');
})().catch(error => { console.error(error); process.exitCode = 1; });

