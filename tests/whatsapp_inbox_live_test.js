'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'public/js/dashboard-whatsapp-inbox.js'), 'utf8');
const camel = value => value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
class Element {
    constructor(tag = 'div') {
        this.tag = tag; this.children = []; this.parentNode = null; this.dataset = {}; this.attributes = {};
        this.listeners = {}; this.text = ''; this.hidden = false; this.disabled = false; this.checked = false;
        this.top = 0; this.clientHeight = 140; this.height = 70; this._scrollTop = 0; this._scrollHeight = 700;
        this.classList = {values: new Set(), add(value) { this.values.add(value); }, remove(value) { this.values.delete(value); },
            contains(value) { return this.values.has(value); }, toggle(value, enabled) { if (enabled) this.add(value); else this.remove(value); }};
    }
    set textContent(value) { this.text = String(value); this.children.forEach(child => { child.parentNode = null; }); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Private data must never be inserted as HTML'); }
    get firstChild() { return this.children[0] || null; }
    get nextSibling() { if (!this.parentNode) return null; return this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null; }
    get scrollHeight() { return this.measure ? this.measure() : this._scrollHeight; }
    set scrollHeight(value) { this._scrollHeight = value; }
    get scrollTop() { return this._scrollTop; }
    set scrollTop(value) { this._scrollTop = Math.max(0, Math.min(value, Math.max(0, this.scrollHeight - this.clientHeight))); }
    append(...items) { items.forEach(item => this.appendChild(item)); }
    appendChild(item) { return this.insertBefore(item, null); }
    insertBefore(item, before) {
        if (item.tag === 'fragment') { item.children.slice().forEach(child => this.insertBefore(child, before)); return item; }
        if (item.parentNode) item.parentNode.removeChild(item);
        const index = before ? this.children.indexOf(before) : this.children.length;
        this.children.splice(index < 0 ? this.children.length : index, 0, item); item.parentNode = this; return item;
    }
    removeChild(item) { this.children.splice(this.children.indexOf(item), 1); item.parentNode = null; return item; }
    contains(item) { while (item) { if (item === this) return true; item = item.parentNode; } return false; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    removeEventListener(name, callback) { this.listeners[name] = (this.listeners[name] || []).filter(value => value !== callback); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(callback => callback(event)); }
    matches(selector) { const match = selector.match(/^\[data-([^\]]+)\]$/); return match && Object.hasOwn(this.dataset, camel(match[1])); }
    closest(selector) { let current = this; while (current) { if (current.matches(selector)) return current; current = current.parentNode; } return null; }
    getBoundingClientRect() { return {top: this.top, bottom: this.top + this.height, left: 0, right: 500}; }
}
class Event { constructor(type, options = {}) { this.type = type; this.detail = options.detail || {}; } }
function walk(element) { return [element, ...element.children.flatMap(walk)]; }
function find(test, attribute, value) {
    return walk(test.panel).find(element => Object.hasOwn(element.dataset, camel(attribute)) && (value === undefined || element.dataset[camel(attribute)] === String(value)));
}
const message = (id, extras = {}) => Object.assign({id, type: 'text', direction: 'inbound', text: 'Message ' + id,
    sent_at: '2026-10-12T02:00:' + String(id).padStart(2, '0') + 'Z', actions: {reply: true, forward: true, delete: true, react: true}}, extras);
const acceptedReaction = (emoji, replyId = 900) => ({emoji, actor_id: 7, accepted_at: '2026-10-12T02:02:00Z', source: 'dashboard_api', state: 'ACCEPTED', reply_id: replyId});
const savedReply = id => ({reply_id: id, projection_id: id, kind: 'text', state: 'ACCEPTED', source: 'dashboard_api',
    text: 'Historic API reply ' + id, accepted_at: '2026-10-12T02:01:00Z', created_at: '2026-10-12T02:00:00Z',
    actions: {reply: true, forward: true, react: true, delete: true}, projected_message_id: null});

function fixture(options = {}) {
    const document = new Element(), window = new Element(), panel = new Element(), bootstrap = new Element('script'), elements = {};
    for (const name of ['thread-list', 'thread-scroll', 'conversation-sentinel', 'message-sentinel', 'list-status', 'count', 'messages', 'chat-status',
        'title', 'customer', 'alert', 'connection', 'scroll', 'older', 'more-threads', 'refresh', 'back', 'unread-total', 'new-messages',
        'select-mode', 'selection-toolbar', 'selection-count', 'select-all', 'selection-cancel', 'delete-selected']) {
        const element = new Element(); element.dataset[camel('wa-' + name)] = ''; elements[name] = element; panel.appendChild(element);
    }
    panel.querySelector = selector => walk(panel).find(element => element.matches(selector)) || null;
    panel.querySelectorAll = selector => walk(panel).filter(element => element.matches(selector));
    document.getElementById = id => id === 'whatsapp-inbox' ? panel : id === 'whatsapp-inbox-bootstrap' ? bootstrap : null;
    document.documentElement = {lang: 'en'}; document.hidden = false;
    const state = {calls: [], raw: Array.from({length: 10}, (_, index) => message(index + 10)), newer: [], older: Array.from({length: 9}, (_, index) => message(index + 1)),
        nextBefore: 10, nextCursor: options.cursor ? 'page-A' : null, threads: [{id: 1, name: 'Customer 1'}, {id: 2, name: 'Customer 2'}],
        delayThreads: false, threadResolvers: [], delayMessages: false, messageResolvers: [], delayActionState: false, actionResolvers: [],
        status: 200, actionState: 'ACCEPTED', failActionNetwork: false, clipboard: [], images: 0, snapshots: new Map(), snapshotNumber: 0, hidden: new Set(), cleanup: [], observers: [],
        apiHistory: [], hiddenReplies: new Set(), nativeReactions: new Map(), apiReactions: new Map(), reactionRequest: 900, delayHistory: false, historyResolvers: []};
    elements['thread-scroll'].measure = () => elements['thread-list'].children.length * 70;
    elements['thread-scroll'].clientHeight = 100;
    elements.scroll.measure = () => elements.messages.children.reduce((sum, row) => sum + row.height, 0);
    elements.scroll.height = elements.scroll.clientHeight;
    document.createElement = tag => {
        const element = new Element(tag); if (tag === 'img') state.images++;
        element.getBoundingClientRect = () => {
            let row = element;
            while (row.parentNode && row.parentNode !== elements.messages) row = row.parentNode;
            if (row.parentNode === elements.messages) {
                const before = elements.messages.children.slice(0, elements.messages.children.indexOf(row)).reduce((sum, item) => sum + item.height, 0);
                return {top: before - elements.scroll.scrollTop, bottom: before + row.height - elements.scroll.scrollTop, left: 0, right: 500};
            }
            return {top: 0, bottom: 70, left: 0, right: 500};
        }; return element;
    };
    document.createDocumentFragment = () => new Element('fragment');
    window.location = {href: 'https://example.test/admin/whatsapp'}; window.innerWidth = 500; window.innerHeight = 140;
    window.crypto = {randomUUID: () => '00000000-0000-4000-8000-000000000001'};
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => state.cleanup.push(callback)};
    window.IntersectionObserver = class {
        constructor(callback, settings) { this.callback = callback; this.settings = settings; state.observers.push(this); }
        observe(element) { this.element = element; } disconnect() { this.disconnected = true; }
    };
    const navigator = {onLine: true, clipboard: {writeText: async value => { state.clipboard.push(value); }}};
    bootstrap.textContent = JSON.stringify({available: true, poll_interval_ms: 2000, csrf: 'csrf', conversations_url: 'https://example.test/admin/whatsapp/conversations',
        messages_base_url: 'https://example.test/admin/whatsapp/conversations', actions_base_url: 'https://example.test/admin/whatsapp/conversations',
        reply_history_base_url: options.history ? 'https://example.test/admin/whatsapp/conversations' : undefined,
        message_updates_base_url: options.updates ? 'https://example.test/admin/whatsapp/conversations' : undefined,
        unread_url: 'https://example.test/admin/whatsapp/unread', unread_label: 'Unread conversations',
        labels: {loading: 'Loading', customer: 'Customer', business: 'Business', connected: 'Connected', denied: 'Denied', choose_conversation: 'Choose',
            no_messages: 'No messages', unavailable: 'Unavailable', offline: 'Offline', message_unavailable: 'Unavailable media', empty: 'Empty', types: {image: 'Image', text: 'Text', other: 'Message'}}});
    async function fetch(url, request) {
        const parsed = new URL(url), pathname = parsed.pathname, body = request.body ? JSON.parse(request.body) : null;
        state.calls.push({url, pathname, request, body}); assert.strictEqual(request.credentials, 'same-origin'); assert.strictEqual(request.cache, 'no-store');
        if (request.method === 'POST') assert.strictEqual(request.headers['X-CSRF-TOKEN'], 'csrf');
        let data;
        if (pathname.endsWith('/unread')) data = {success: true, total_unread: 50, total_unread_messages: 50, total_unread_conversations: 2,
            conversations: [{id: 1, unread_count: 29}, {id: 2, unread_count: 21}]};
        else if (pathname.endsWith('/selection')) {
            const token = 'snapshot-' + ++state.snapshotNumber, messageIds = [...state.raw, ...state.newer, ...state.older].map(row => row.id).filter(id => !state.hidden.has(id));
            data = {success: true, snapshot_token: token, total_count: messageIds.length + 1, message_count: messageIds.length, reply_count: 1,
                max_message_id: Math.max(0, ...messageIds), max_reply_id: 17, max_projection_id: 17, select_all_available: true}; state.snapshots.set(token, {...data, message_ids: messageIds, reply_ids: [17]});
        } else if (pathname.endsWith('/messages/delete')) {
            const snapshot = state.snapshots.get(body.snapshot_token); assert(snapshot);
            const ids = body.select_all ? snapshot.message_ids : body.message_ids; ids.forEach(id => state.hidden.add(id));
            data = {success: true, dashboard_only: true, hidden_count: ids.length + (body.select_all ? snapshot.reply_ids.length : body.reply_ids.length)};
        } else if (pathname.endsWith('/replies/history')) {
            const before = Number(parsed.searchParams.get('before_reply_id')) || Infinity;
            const rows = state.apiHistory.filter(row => row.reply_id < before && !state.hiddenReplies.has(row.reply_id)).sort((a, b) => b.reply_id - a.reply_id);
            const page = rows.slice(0, 20).map(row => ({...row, reaction: state.apiReactions.get(row.reply_id) || row.reaction || null}));
            data = {success: true, replies: page, next_before_reply_id: rows.length > 20 ? page.at(-1).reply_id : null, has_more: rows.length > 20,
                hidden_reply_ids: parsed.searchParams.getAll('known_reply_ids[]').map(Number).filter(id => state.hiddenReplies.has(id))};
            if (state.delayHistory) await new Promise(resolve => state.historyResolvers.push(resolve));
        } else if (pathname.endsWith('/messages/updates')) {
            const messageIds = parsed.searchParams.getAll('message_ids[]').map(Number), replyIds = parsed.searchParams.getAll('reply_ids[]').map(Number);
            assert(messageIds.length + replyIds.length <= 100);
            data = {success: true, messages: messageIds.map(id => ({id, deleted: state.hidden.has(id), reaction: state.nativeReactions.get(id) || null})),
                replies: replyIds.map(reply_id => ({reply_id, deleted: state.hiddenReplies.has(reply_id), reaction: state.apiReactions.get(reply_id) || null}))};
        } else if (pathname.endsWith('/reply-state')) {
            if (state.delayActionState) await new Promise(resolve => state.actionResolvers.push(resolve));
            data = {success: true, can_reply: true, latest_inbound_id: pathname.includes('/2/') ? 202 : 101};
        } else if (/\/(reaction|forward)$/.test(pathname)) {
            if (state.failActionNetwork) throw new Error('Network interruption'); data = {success: true, state: state.actionState};
            if (pathname.endsWith('/reaction') && state.actionState === 'ACCEPTED') {
                data.reaction = acceptedReaction(body.emoji, ++state.reactionRequest);
                const match = pathname.match(/\/(messages|replies)\/(\d+)\/reaction$/);
                (match[1] === 'messages' ? state.nativeReactions : state.apiReactions).set(Number(match[2]), data.reaction);
            }
        }
        else if (/\/messages\/\d+\/delete$/.test(pathname)) {
            const id = Number(pathname.match(/messages\/(\d+)/)[1]); state.hidden.add(id); data = {success: true, dashboard_only: true, deleted: true, message_id: id};
        } else if (pathname.endsWith('/messages')) {
            const rows = (parsed.searchParams.has('before_id') ? state.older : parsed.searchParams.has('after_id') ? state.newer : state.raw)
                .map(row => state.hidden.has(row.id) ? message(row.id, {deleted: true, text: null, actions: {}}) : {...row});
            if (state.delayMessages) await new Promise(resolve => state.messageResolvers.push(resolve));
            data = {success: true, conversation: {id: Number(pathname.match(/conversations\/(\d+)/)[1]), name: 'Customer'},
                messages: rows, last_id: Math.max(Number(parsed.searchParams.get('after_id')) || 0, ...state.raw.map(row => row.id), ...rows.map(row => row.id)), next_before_id: parsed.searchParams.has('before_id') ? null : state.nextBefore, has_more: false};
        } else {
            if (state.delayThreads) await new Promise(resolve => state.threadResolvers.push(resolve));
            const cursor = parsed.searchParams.get('cursor');
            data = {success: true, conversations: cursor ? [{id: cursor === 'page-A' ? 3 : 4, name: 'Older customer'}] : state.threads,
                total: 4, next_cursor: cursor === 'page-A' ? 'page-B' : cursor ? null : state.nextCursor};
        }
        return {ok: state.status === 200, status: state.status, redirected: false, headers: {get: () => 'application/json'}, json: async () => data};
    }
    let timerId = 0; const timers = new Map();
    const context = {document, window, navigator, fetch, URL, AbortController, CustomEvent: Event, Map, Set, Promise, Date, Uint8Array,
        setTimeout: (callback, delay) => { timers.set(++timerId, {callback, delay}); return timerId; }, clearTimeout: id => timers.delete(id), console};
    vm.createContext(context); vm.runInContext(source, context);
    return {panel, elements, document, window, state, context, timers, navigator};
}
async function settle() { for (let index = 0; index < 12; index++) await new Promise(setImmediate); }
async function scheduledPoll(test) {
    const entry = [...test.timers.entries()].find(([, timer]) => timer.delay === 2000);
    assert(entry, 'An open connected inbox schedules its next live poll');
    test.timers.delete(entry[0]); entry[1].callback(); await settle();
}
function click(test, element) { assert(element, 'Expected clickable control'); test.panel.dispatchEvent({type: 'click', target: element}); }
function choose(test, id = 1) { click(test, find(test, 'wa-thread', id)); }
function action(test, id, value) {
    click(test, find(test, 'wa-action-menu', id)); click(test, find(test, 'wa-action', value));
}
function close(test) { test.window.DashboardWhatsAppInbox.destroy(); }
function publish(test, replies) { test.document.dispatchEvent(new Event('whatsapp:accepted-replies', {detail: {...test.window.DashboardWhatsAppInbox.selectionState(), replies: replies.map(reply => ({projection_id: reply.id, ...reply}))}})); }

async function infiniteThreadsAndCursor() {
    const test = fixture({cursor: true}); await settle();
    test.elements['thread-scroll'].scrollTop = test.elements['thread-scroll'].scrollHeight;
    test.elements['thread-scroll'].dispatchEvent({type: 'scroll'}); await settle();
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('cursor') === 'page-A'));
    test.window.dispatchEvent({type: 'focus'}); await settle();
    test.elements['thread-scroll'].dispatchEvent({type: 'scroll'}); await settle();
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('cursor') === 'page-B'), 'Latest page polling must retain the older conversation cursor');
    assert.strictEqual(test.elements['more-threads'].hidden, true); close(test);
    assert(test.state.observers.every(observer => observer.disconnected));
}
async function historyAnchoringAndNewIndicator() {
    const test = fixture(); await settle(); choose(test); await settle();
    test.elements.scroll.scrollTop = 200; test.elements.scroll.dispatchEvent({type: 'scroll'});
    test.state.newer = [message(20)]; test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(test.elements.scroll.scrollTop, 200, 'Incoming messages never move someone reading history');
    assert.strictEqual(test.elements['new-messages'].hidden, false);
    click(test, test.elements['new-messages']); assert.strictEqual(test.elements.scroll.scrollTop, test.elements.scroll.scrollHeight - test.elements.scroll.clientHeight);
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert.strictEqual(test.elements.scroll.scrollTop, 630, 'Prepending nine real rows keeps the previously visible row anchored');
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('before_id') === '10')); close(test);
}
async function pollingDoesNotOverlap() {
    const test = fixture(); await settle(); test.state.delayThreads = true;
    test.window.dispatchEvent({type: 'focus'}); await settle(); const calls = test.state.calls.length;
    test.window.dispatchEvent({type: 'focus'}); test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(test.state.calls.length, calls, 'Focus refreshes coalesce while a live poll is in flight');
    test.state.delayThreads = false; test.state.threadResolvers.shift()(); await settle();
    assert(test.timers.size <= 1, 'Only one fast poll timer remains');
    assert([...test.timers.values()].some(timer => timer.delay === 2000));
    test.document.hidden = true; test.document.dispatchEvent({type: 'visibilitychange'}); assert.strictEqual(test.timers.size, 0);
    test.document.hidden = false; test.document.dispatchEvent({type: 'visibilitychange'}); await settle(); close(test);
}
async function autonomousPollFindsNativeMessagesAndCustomers() {
    const test = fixture(); await settle(); choose(test); await settle();
    test.state.threads.push({id: 3, name: 'New customer'}); test.state.newer = [message(20)];
    await scheduledPoll(test);
    assert(find(test, 'wa-thread', 3), 'A new customer appears without focus or manual refresh');
    assert(find(test, 'wa-message-id', 20), 'A new native message appears on the scheduled live poll');
    assert([...test.timers.values()].some(timer => timer.delay === 2000)); close(test);
}
async function autonomousPollRefreshesAcceptedRepliesAndRetainsHistory() {
    const test = fixture({history: true}); test.state.raw = []; test.state.older = []; test.state.nextBefore = null;
    test.state.apiHistory = Array.from({length: 75}, (_, index) => savedReply(index + 1));
    await settle(); choose(test); await settle();
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert.strictEqual(test.elements.messages.children.length, 40);
    const anchor = test.elements.scroll.scrollTop;
    test.state.apiHistory.push(savedReply(76), {...savedReply(77), state: 'UNKNOWN', text: 'Unconfirmed external reply'});
    await scheduledPoll(test);
    assert(find(test, 'wa-api-reply-id', 'api-reply:76'), 'An accepted reply from another operator appears without native echo or manual refresh');
    assert(!test.elements.messages.textContent.includes('Unconfirmed external reply'), 'An unknown request cannot become an accepted bubble');
    assert(find(test, 'wa-api-reply-id', 'api-reply:36'), 'Recent polling retains the explicitly loaded older page');
    assert.strictEqual(test.elements.scroll.scrollTop, anchor, 'Recent accepted replies preserve the history reader anchor');
    await scheduledPoll(test);
    assert.strictEqual(walk(test.elements.messages).filter(element => element.dataset.waApiReplyId === 'api-reply:76').length, 1, 'Repeated recent snapshots do not duplicate a receipt');
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('before_reply_id') === '36'), 'Recent polling does not overwrite the older history cursor');
    assert(find(test, 'wa-api-reply-id', 'api-reply:16')); assert(find(test, 'wa-api-reply-id', 'api-reply:76'));
    assert.strictEqual(test.state.calls.filter(call => call.request.method === 'POST').length, 0, 'Accepted history polling never sends or retries a reply'); close(test);
}
async function delayedRecentPollCannotCrossCustomerScope() {
    const test = fixture({history: true}); test.state.apiHistory = [savedReply(17)]; await settle(); choose(test); await settle();
    test.state.delayHistory = true; test.state.apiHistory = [{...savedReply(18), text: 'Private previous customer reply'}];
    await scheduledPoll(test); assert.strictEqual(test.state.historyResolvers.length, 1);
    test.state.apiHistory = [{...savedReply(19), text: 'Current customer reply'}]; choose(test, 2); await settle();
    assert.strictEqual(test.state.historyResolvers.length, 2);
    test.state.delayHistory = false; test.state.historyResolvers[1](); await settle(); test.state.historyResolvers[0](); await settle();
    assert(test.elements.messages.textContent.includes('Current customer reply'));
    assert(!test.elements.messages.textContent.includes('Private previous customer reply'), 'A late recent snapshot is discarded after customer selection changes');
    assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(), 2); close(test);
}
async function unreadCountsCustomers() {
    const test = fixture(); await settle(); assert.strictEqual(test.elements['unread-total'].textContent, 'Unread conversations: 2');
    const badges = walk(test.elements['thread-list']).filter(element => element.className === 'wa-inbox-unread');
    assert.deepStrictEqual(badges.map(element => element.textContent), ['1', '1']);
    assert.deepStrictEqual(badges.map(element => element.attributes['aria-label']), ['Unread conversation', 'Unread conversation'], 'Screen readers count one conversation per row, rather than21 messages as21 conversations');
    assert.strictEqual(test.state.calls.filter(call => call.request.method === 'POST').length, 0, 'Rendering a list never marks history read'); close(test);
}
async function unreadAggregateFollowsActivity() {
    const test = fixture(); await settle();
    const unreadCalls = () => test.state.calls.filter(call => call.pathname.endsWith('/unread')).length;
    const initial = unreadCalls(); assert(initial >= 1, 'Opening the inbox loads the unread count once');
    await scheduledPoll(test); await scheduledPoll(test);
    assert.strictEqual(unreadCalls(), initial, 'Quiet polls reuse the recent unread count instead of rescanning the inbox');
    test.state.threads.push({id: 3, name: 'New customer', last_message_at: '2026-10-29T12:00:00Z'});
    await scheduledPoll(test);
    assert.strictEqual(unreadCalls(), initial + 1, 'A changed conversation list refreshes the unread count on the same poll');
    const realNow = Date.now; Date.now = () => realNow() + 31000;
    try { await scheduledPoll(test); } finally { Date.now = realNow; }
    assert.strictEqual(unreadCalls(), initial + 2, 'The unread count still refreshes every 30 seconds without activity'); close(test);
}
async function authenticatedMediaAndStableNodes() {
    const test = fixture(); test.state.raw[2] = message(12, {type: 'image', text: 'Caption <script>', media: {type: 'image', url: '/admin/whatsapp/conversations/1/messages/12/media'}});
    test.state.raw[3] = message(13, {type: 'image', media: {type: 'image', url: 'https://attacker.test/private'}});
    test.state.raw[4] = message(14, {type: 'image', media: {type: 'image', url: '/admin/whatsapp/conversations/2/messages/14/media'}});
    await settle(); choose(test); await settle();
    assert.strictEqual(stateImages(test).length, 1); const image = stateImages(test)[0];
    assert.strictEqual(image.src, 'https://example.test/admin/whatsapp/conversations/1/messages/12/media');
    const row = find(test, 'wa-message-id', 12); test.state.newer = [test.state.raw[2]];
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-message-id', 12), row, 'Unchanged message DOM is reused during polling'); assert.strictEqual(stateImages(test)[0], image);
    test.elements.scroll.scrollTop = 250; test.elements.scroll.dispatchEvent({type: 'scroll'});
    const before = test.elements.scroll.scrollTop; find(test, 'wa-message-id', 10).height += 40; image.dispatchEvent({type: 'load'});
    assert.strictEqual(test.elements.scroll.scrollTop, before + 40, 'Delayed media height above the visible anchor preserves reading position');
    test.state.status = 403; test.window.dispatchEvent({type: 'focus'}); await settle(); assert.strictEqual(stateImages(test).length, 0); close(test);
}
function stateImages(test) { return walk(test.elements.messages).filter(element => element.tag === 'img'); }
async function copyQuoteAndReaction() {
    const test = fixture(); await settle(); choose(test); await settle();
    action(test, 10, 'copy'); await settle(); assert.deepStrictEqual(test.state.clipboard, ['Message 10']);
    let quote; test.document.addEventListener('whatsapp:reply-context', event => { quote = event.detail; });
    action(test, 11, 'reply'); assert.strictEqual(quote.message_id, 11); assert.strictEqual(quote.reply_id, undefined);
    assert.strictEqual(quote.conversation_id, 1); assert.strictEqual(quote.channel, 'whatsapp');
    action(test, 10, 'react'); const emoji = walk(test.panel).find(element => element.dataset.waEmoji === '👍'); click(test, emoji); await settle();
    const posts = test.state.calls.filter(call => call.pathname.endsWith('/reaction'));
    assert.strictEqual(posts.length, 1); assert.deepStrictEqual(posts[0].body, {emoji: '👍', client_request_id: '00000000-0000-4000-8000-000000000001', expected_inbound_id: 101}); close(test);
}
async function forwardNeedsExplicitTargetAndConfirmation() {
    const test = fixture(); await settle(); choose(test); await settle(); action(test, 10, 'forward');
    let buttons = walk(test.panel).filter(element => element.tag === 'button' && element.textContent === 'Confirm forwarding');
    assert.strictEqual(buttons[0].disabled, true); assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/forward')).length, 0);
    const target = find(test, 'wa-forward-target', 2); target.dispatchEvent({type: 'click'}); assert.strictEqual(buttons[0].disabled, false);
    buttons[0].dispatchEvent({type: 'click'}); await settle();
    const post = test.state.calls.find(call => call.pathname.endsWith('/forward'));
    assert.strictEqual(post.body.target_conversation_id, 2); assert.strictEqual(post.body.expected_inbound_id, 202);
    assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(), 1, 'Forwarding does not replace the selected history'); close(test);
}
async function staleActionsNeverSend() {
    const test = fixture(); await settle(); choose(test); await settle(); test.state.delayActionState = true;
    action(test, 10, 'react'); click(test, walk(test.panel).find(element => element.dataset.waEmoji === '❤️')); await settle();
    choose(test, 2); await settle(); test.state.actionResolvers[0](); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/reaction')).length, 0, 'Changing customers before action preflight prevents the send'); close(test);
}
async function unknownIsNeverRetried() {
    const test = fixture(); await settle(); choose(test); await settle(); test.state.actionState = 'UNKNOWN';
    action(test, 10, 'react'); click(test, walk(test.panel).find(element => element.dataset.waEmoji === '👍')); await settle();
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/reaction')).length, 1); assert(test.elements.alert.textContent.includes('unknown')); close(test);
}
async function selectionSnapshotExcludesFutureRows() {
    const test = fixture(); await settle(); choose(test); await settle();
    publish(test, [{id: 17, kind: 'text', text: 'Accepted audit', source: 'dashboard_api', state: 'ACCEPTED', created_at: '2026-10-12T02:01:00Z', actions: {delete: true}}]);
    click(test, test.elements['select-all']); await settle(); const initial = Number(test.elements['selection-count'].textContent);
    test.state.newer = [message(20)]; test.window.dispatchEvent({type: 'focus'}); await settle();
    const future = find(test, 'wa-message-select', 20); assert(future && future.disabled && !future.checked, 'New messages after Select all stay outside its snapshot');
    assert.strictEqual(Number(test.elements['selection-count'].textContent), initial);
    click(test, test.elements['delete-selected']); assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/messages/delete')).length, 0);
    click(test, find(test, 'wa-bulk-confirm')); await settle();
    const post = test.state.calls.find(call => call.pathname.endsWith('/messages/delete'));
    assert.strictEqual(post.body.select_all, true); assert(!test.state.hidden.has(20)); assert.strictEqual(find(test, 'wa-message-id', 20).dataset.waHidden, undefined);
    assert.strictEqual(test.elements['selection-toolbar'].hidden, true); close(test);
}
async function individualSelectionAndLifecycle() {
    const test = fixture(); await settle(); choose(test); await settle(); click(test, test.elements['select-mode']); await settle();
    const checkbox = find(test, 'wa-message-select', 10); checkbox.checked = true; click(test, checkbox);
    assert.strictEqual(checkbox.disabled, false, 'Completed snapshot preflight enables the actual checkbox');
    assert.strictEqual(test.elements['selection-count'].textContent, '1');
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert.strictEqual(find(test, 'wa-message-select', 10).checked, true, 'Stable selected IDs survive older-message pagination');
    click(test, test.elements['delete-selected']); click(test, find(test, 'wa-bulk-confirm')); await settle();
    assert.deepStrictEqual(test.state.calls.find(call => call.pathname.endsWith('/messages/delete')).body.message_ids, [10]);
    assert.strictEqual(find(test, 'wa-message-id', 10).dataset.waHidden, 'true'); assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/read')).length, 0);
    click(test, test.elements['select-mode']); await settle(); choose(test, 2); await settle(); assert.strictEqual(test.elements['selection-toolbar'].hidden, true);
    close(test); assert.strictEqual(test.elements.messages.textContent, ''); assert.strictEqual(test.window.listeners.focus.length, 0);
}
async function apiReplyActionsUseScopedAuditIds() {
    const test = fixture(); await settle(); choose(test); await settle();
    publish(test, [{id: 17, kind: 'text', text: 'Accepted', source: 'dashboard_api', state: 'ACCEPTED', created_at: '2026-10-12T02:01:00Z', actions: {reply: true, react: true, delete: true}}]);
    let quote; test.document.addEventListener('whatsapp:reply-context', event => { quote = event.detail; });
    action(test, 'api-reply:17', 'reply'); assert.strictEqual(quote.reply_id, 17); assert.strictEqual(quote.message_id, undefined);
    action(test, 'api-reply:17', 'react'); click(test, walk(test.panel).find(element => element.dataset.waEmoji === '👍')); await settle();
    assert(test.state.calls.some(call => call.pathname.endsWith('/replies/17/reaction'))); close(test);
}
async function singleDeleteIsLocalAndConfirmed() {
    const test = fixture(); await settle(); choose(test); await settle(); action(test, 10, 'delete');
    assert.strictEqual(test.state.calls.filter(call => call.request.method === 'POST').length, 0);
    click(test, find(test, 'wa-action', 'delete-confirm')); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/messages/10/delete')).length, 1);
    const tombstone = find(test, 'wa-message-id', 10);
    assert.strictEqual(tombstone.dataset.waHidden, 'true'); assert(tombstone.textContent.includes('dashboard only'));
    assert(!tombstone.textContent.includes('Message 10')); assert(!find(test, 'wa-action-menu', 10));
    assert.strictEqual(test.state.calls.filter(call => /\/(forward|reaction|replies)$/.test(call.pathname)).length, 0); close(test);
}
async function apiImageScopeAndNativeEchoDeduplication() {
    const test = fixture(); await settle(); choose(test); await settle();
    const reply = {id: 17, kind: 'image', text: null, source: 'dashboard_api', state: 'ACCEPTED', created_at: '2026-10-12T02:01:00Z', projected_message_id: 21,
        media: {type: 'image', url: '/admin/whatsapp/conversations/1/replies/17/media'}, actions: {reply: true, delete: true}};
    publish(test, [reply]); assert.strictEqual(stateImages(test).length, 1);
    assert.strictEqual(stateImages(test)[0].src, 'https://example.test/admin/whatsapp/conversations/1/replies/17/media');
    publish(test, [{...reply, media: {type: 'image', url: '/admin/whatsapp/conversations/2/replies/17/media'}}]); assert.strictEqual(stateImages(test).length, 0);
    publish(test, [reply]); test.state.newer = [message(21, {direction: 'outbound', type: 'image', media: {type: 'image', url: '/admin/whatsapp/conversations/1/messages/21/media'}})];
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(stateImages(test).length, 1); assert.strictEqual(find(test, 'wa-api-reply-id', 'api-reply:17'), undefined);
    assert(stateImages(test)[0].src.endsWith('/messages/21/media')); close(test);
}
async function delayedOldMessagesNeverLeak() {
    const test = fixture(); await settle(); test.state.delayMessages = true;
    test.state.raw = [message(10, {text: 'Private customer one'})]; choose(test, 1); await settle();
    test.state.raw = [message(11, {text: 'Private customer two'})]; choose(test, 2); await settle();
    test.state.messageResolvers[1](); await settle(); test.state.messageResolvers[0](); await settle();
    assert(test.elements.messages.textContent.includes('Private customer two'));
    assert(!test.elements.messages.textContent.includes('Private customer one')); close(test);
}
async function networkUncertaintyNeverResends() {
    const test = fixture(); await settle(); choose(test); await settle(); test.state.failActionNetwork = true;
    action(test, 10, 'react'); click(test, walk(test.panel).find(element => element.dataset.waEmoji === '👍')); await settle();
    assert(test.elements.alert.textContent.includes('unknown'));
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/reaction')).length, 1);
    assert(test.elements.alert.textContent.includes('unknown'), 'Uncertainty remains visible across ordinary live polls'); close(test);
}
async function selectAllCoversWholeLargeConversation() {
    const test = fixture(); test.state.raw = Array.from({length: 10}, (_, index) => message(index + 1510));
    test.state.older = Array.from({length: 1500}, (_, index) => message(index + 1));
    await settle(); assert.strictEqual(test.elements['select-mode'].disabled, true); choose(test); await settle();
    assert.strictEqual(test.elements['select-mode'].disabled, false); click(test, test.elements['select-all']); await settle();
    assert.strictEqual(test.elements['selection-count'].textContent, '1511', 'Select all covers the stored conversation beyond loaded pages and 1000 rows');
    assert.strictEqual(test.elements['delete-selected'].disabled, false);
    const snapshot = [...test.state.snapshots.values()][0]; assert.strictEqual(snapshot.max_message_id, 1519);
    test.state.newer = [message(1520)]; test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-message-select', 1520).checked, false);
    click(test, test.elements['delete-selected']); click(test, find(test, 'wa-bulk-confirm')); await settle();
    const post = test.state.calls.find(call => call.pathname.endsWith('/messages/delete'));
    assert.strictEqual(post.body.select_all, true); assert.deepStrictEqual(post.body.message_ids, []); assert.deepStrictEqual(post.body.reply_ids, []);
    assert.strictEqual(test.state.hidden.size, 1510); assert(!test.state.hidden.has(1520)); close(test);
}
async function lateEchoKeepsSnapshotSelectionAndDedupe() {
    const test = fixture(); await settle(); choose(test); await settle();
    publish(test, [{id: 17, kind: 'text', text: 'Accepted audit', source: 'dashboard_api', state: 'ACCEPTED', created_at: '2026-10-12T02:01:00Z', actions: {delete: true}}]);
    click(test, test.elements['select-all']); await settle(); const count = test.elements['selection-count'].textContent;
    test.state.newer = [message(30, {direction: 'outbound', api_reply_id: 17, api_projection_id: 17, text: 'Accepted audit'}), message(31)];
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-api-reply-id', 'api-reply:17'), undefined, 'The validated receipt index dedupes a late native echo before the next audit poll');
    assert.strictEqual(find(test, 'wa-message-select', 'api-reply:17').checked, true, 'An old receipt remains selected when its native projection arrives after the native cutoff');
    assert.strictEqual(find(test, 'wa-message-select', 31).checked, false); assert.strictEqual(test.elements['selection-count'].textContent, count); close(test);
}
async function allModeAndManualLimitAreExplicit() {
    const test = fixture(); test.state.raw = Array.from({length: 110}, (_, index) => message(index + 1)); test.state.older = [];
    await settle(); choose(test); await settle(); click(test, test.elements['select-all']); await settle();
    const allCount = test.elements['selection-count'].textContent, allCheckbox = find(test, 'wa-message-select', 1);
    assert.strictEqual(allCheckbox.disabled, true); allCheckbox.checked = false; click(test, allCheckbox);
    assert.strictEqual(test.elements['selection-count'].textContent, allCount, 'Unchecking a disabled All-mode row cannot silently narrow global selection');
    assert(test.elements['selection-toolbar'].textContent.includes('Cancel Select all'));
    click(test, test.elements['selection-cancel']); await settle();
    assert.strictEqual(test.elements['selection-toolbar'].hidden, false); assert.strictEqual(test.elements['selection-count'].textContent, '0');
    for (let id = 1; id <= 101; id++) { const checkbox = find(test, 'wa-message-select', id); checkbox.checked = true; click(test, checkbox); }
    assert.strictEqual(test.elements['selection-count'].textContent, '100'); assert.strictEqual(find(test, 'wa-message-select', 101).checked, false);
    assert(test.elements['selection-toolbar'].textContent.includes('100')); assert(test.elements.alert.textContent.includes('100'));
    assert.strictEqual(test.state.calls.filter(call => call.request.method === 'POST').length, 0); close(test);
}
async function oldClaimAcceptedAfterSnapshotIsNotSelected() {
    const test = fixture(); await settle(); choose(test); await settle(); click(test, test.elements['select-all']); await settle();
    const count = test.elements['selection-count'].textContent;
    publish(test, [{id: 5, projection_id: 18, kind: 'text', text: 'An older claim completed later', source: 'dashboard_api', state: 'ACCEPTED',
        created_at: '2026-10-12T02:01:00Z', actions: {delete: true}}]);
    assert.strictEqual(find(test, 'wa-message-select', 'api-reply:5').checked, false, 'A reply ID below max_reply_id cannot bypass the acceptance projection cutoff');
    test.state.newer = [message(30, {direction: 'outbound', api_reply_id: 5, api_projection_id: 18, text: 'An older claim completed later'})];
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-message-select', 30).checked, false); assert.strictEqual(test.elements['selection-count'].textContent, count); close(test);
}
async function apiOnlyHistoryPagesBeyondTwenty() {
    const test = fixture({history: true}); test.state.raw = []; test.state.older = []; test.state.nextBefore = null;
    test.state.apiHistory = Array.from({length: 75}, (_, index) => savedReply(index + 1));
    await settle(); choose(test); await settle(); assert.strictEqual(test.elements.messages.children.length, 20);
    publish(test, test.state.apiHistory.slice(-20).map(reply => ({...reply, id: reply.reply_id})));
    assert.strictEqual(test.elements.messages.children.length, 20);
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert.strictEqual(test.elements.messages.children.length, 40);
    publish(test, test.state.apiHistory.slice(-20).map(reply => ({...reply, id: reply.reply_id})));
    assert.strictEqual(test.elements.messages.children.length, 40, 'Recent state20 cannot evict already paged history');
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert.strictEqual(test.elements.messages.children.length, 75, 'An API-only conversation loads older receipts without native rows');
    assert.strictEqual(test.elements.scroll.scrollTop, 1050, 'The final15 older API rows retain the previous visible anchor');
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('before_reply_id') === '56'));
    const historyTop = test.elements.scroll.scrollTop; test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(test.elements.messages.children.length, 75); assert.strictEqual(test.elements.scroll.scrollTop, historyTop);
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('after_id') === '0'), 'API receipts never move the native cursor');
    assert(!test.state.calls.some(call => new URL(call.url).searchParams.has('before_id'))); assert.strictEqual(test.state.calls.filter(call => call.request.method === 'POST').length, 0); close(test);
}
async function cachedOlderApiHidesCannotReappear() {
    const test = fixture({history: true, updates: true}); test.state.raw = []; test.state.older = []; test.state.nextBefore = null;
    test.state.apiHistory = Array.from({length: 75}, (_, index) => savedReply(index + 1));
    await settle(); choose(test); await settle();
    for (let page = 0; page < 3; page++) { test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle(); }
    test.elements.scroll.scrollTop = 0; test.state.hiddenReplies.add(1); test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-api-reply-id', 'api-reply:1'), undefined);
    publish(test, [{...savedReply(1), id: 1}]); assert.strictEqual(find(test, 'wa-api-reply-id', 'api-reply:1'), undefined, 'A late stale recent snapshot cannot revive a confirmed hidden receipt');
    assert(find(test, 'wa-api-reply-id', 'api-reply:25'), 'Unrelated older pages remain cached'); close(test);
}
async function acceptedReactionRendersReloadsAndClears() {
    const test = fixture(); test.state.raw[0].reaction = acceptedReaction('❤️'); await settle(); choose(test); await settle();
    let row = find(test, 'wa-message-id', 10), pill = walk(row).find(element => element.className === 'wa-message-reaction');
    assert(pill && pill.textContent === '❤️'); assert(pill.attributes['aria-label'].includes('accepted by WhatsApp'));
    test.state.newer = [test.state.raw[0]]; test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-message-id', 10), row, 'Equal persisted reactions preserve the bubble DOM');
    action(test, 10, 'react'); click(test, walk(test.panel).find(element => element.dataset.waEmoji === '👍')); await settle();
    assert(walk(find(test, 'wa-message-id', 10)).some(element => element.className === 'wa-message-reaction' && element.textContent === '👍'));
    test.state.newer = []; action(test, 10, 'react'); click(test, walk(test.panel).find(element => element.dataset.waEmoji === '')); await settle();
    assert(!walk(find(test, 'wa-message-id', 10)).some(element => element.className === 'wa-message-reaction'));
    assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/reaction')).length, 2, 'Removal is one explicitly confirmed native action'); close(test);
    const invalid = fixture(); invalid.state.raw[0].reaction = {...acceptedReaction('👍'), state: 'UNKNOWN'};
    await settle(); choose(invalid); await settle(); assert(!walk(invalid.elements.messages).some(element => element.className === 'wa-message-reaction')); close(invalid);
}
async function visibleMetadataUpdatesOlderNativeAndApi() {
    const test = fixture({history: true, updates: true}); test.state.apiHistory = [savedReply(17)]; await settle(); choose(test); await settle();
    test.elements.scroll.scrollTop = 0; test.state.nativeReactions.set(10, acceptedReaction('✅')); test.state.hidden.add(11);
    const untouched = find(test, 'wa-message-id', 12); test.window.dispatchEvent({type: 'focus'}); await settle();
    assert(walk(find(test, 'wa-message-id', 10)).some(element => element.className === 'wa-message-reaction' && element.textContent === '✅'));
    assert.strictEqual(find(test, 'wa-message-id', 11).dataset.waHidden, 'true'); assert.strictEqual(find(test, 'wa-message-id', 12), untouched);
    test.elements.scroll.scrollTop = test.elements.scroll.scrollHeight; test.state.apiReactions.set(17, acceptedReaction('😂')); test.window.dispatchEvent({type: 'focus'}); await settle();
    assert(walk(find(test, 'wa-api-reply-id', 'api-reply:17')).some(element => element.className === 'wa-message-reaction' && element.textContent === '😂'));
    test.state.apiReactions.set(17, acceptedReaction('', 901)); test.window.dispatchEvent({type: 'focus'}); await settle();
    assert(!walk(find(test, 'wa-api-reply-id', 'api-reply:17')).some(element => element.className === 'wa-message-reaction'));
    assert.strictEqual(test.state.calls.filter(call => call.request.method === 'POST').length, 0, 'Refreshing reaction/hide metadata does not send or mark unviewed history'); close(test);
}
async function protectedDocumentLinksStayInScope() {
    const test = fixture(); await settle(); choose(test); await settle();
    const row = {...savedReply(17), id: 17, kind: 'document', text: 'Attached menu caption',
        media: {type: 'document', url: '/admin/whatsapp/conversations/1/replies/17/attachment', mime_type: 'application/pdf', filename: '<menu>.pdf', size: 512},
        actions: {reply: true, react: true, delete: true, forward: false}};
    publish(test, [row]); let links = walk(test.elements.messages).filter(element => element.tag === 'a');
    assert.strictEqual(links.length, 1); assert.strictEqual(links[0].href, 'https://example.test/admin/whatsapp/conversations/1/replies/17/attachment');
    assert.strictEqual(links[0].attributes.download, '<menu>.pdf'); assert(links[0].textContent.includes('<menu>.pdf'));
    assert.strictEqual(test.state.calls.filter(call => call.pathname.endsWith('/attachment')).length, 0, 'A document is downloaded only by the operator, never on each poll');
    action(test, 'api-reply:17', 'copy'); await settle(); assert.deepStrictEqual(test.state.clipboard, ['Attached menu caption']);
    let quoted; test.document.addEventListener('whatsapp:reply-context', event => { quoted = event.detail; });
    action(test, 'api-reply:17', 'reply'); assert.strictEqual(quoted.reply_id, 17); assert.strictEqual(quoted.kind, 'document');
    for (const bad of [{url: '/admin/whatsapp/conversations/2/replies/17/attachment'}, {url: '/admin/whatsapp/conversations/1/replies/18/attachment'},
        {url: 'https://foreign.test/attachment'}, {url: '/admin/whatsapp/conversations/1/replies/17/attachment?token=x'},
        {url: '/admin/whatsapp/conversations/1/replies/17/attachment#fragment'}, {url: 'javascript:alert(1)'},
        {mime_type: 'text/html'}, {filename: '../secret.pdf'}, {filename: 'x'.repeat(121)}, {size: 8388609}, {size: '512'}]) {
        publish(test, [{...row, media: {...row.media, ...bad}}]);
        assert.strictEqual(walk(test.elements.messages).filter(element => element.tag === 'a').length, 0, 'Invalid/foreign document metadata never creates a link');
    }
    publish(test, [row]); action(test, 'api-reply:17', 'delete'); click(test, find(test, 'wa-action', 'delete-confirm')); await settle();
    assert.strictEqual(walk(test.elements.messages).filter(element => element.tag === 'a').length, 0, 'Local hiding removes the protected link'); close(test);
}
async function documentNativeEchoKeepsDownloadAndDedupe() {
    const test = fixture(); await settle(); choose(test); await settle();
    const media = {type: 'document', url: '/admin/whatsapp/conversations/1/replies/17/attachment', mime_type: 'text/plain', filename: 'note.txt', size: 16};
    publish(test, [{...savedReply(17), id: 17, kind: 'document', text: 'Order notes', media}]);
    assert.strictEqual(walk(test.elements.messages).filter(element => element.tag === 'a').length, 1);
    test.state.newer = [message(21, {direction: 'outbound', type: 'document', text: 'Order notes', api_reply_id: 17, api_projection_id: 17, media})];
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(find(test, 'wa-api-reply-id', 'api-reply:17'), undefined, 'Native echo replaces its accepted API placeholder');
    const links = walk(test.elements.messages).filter(element => element.tag === 'a'); assert.strictEqual(links.length, 1);
    assert.strictEqual(links[0].href, 'https://example.test/admin/whatsapp/conversations/1/replies/17/attachment', 'The verified API bridge retains the outgoing document download');
    test.state.newer = [message(22, {direction: 'outbound', type: 'document', media})];
    test.window.dispatchEvent({type: 'focus'}); await settle();
    assert.strictEqual(walk(find(test, 'wa-message-id', 22)).filter(element => element.tag === 'a').length, 0, 'Unbridged native rows cannot borrow a receipt document link'); close(test);
}
async function olderDocumentHistoryUsesProtectedReceipt() {
    const test = fixture({history: true}); test.state.raw = []; test.state.older = []; test.state.nextBefore = null;
    test.state.apiHistory = Array.from({length: 30}, (_, index) => savedReply(index + 1));
    test.state.apiHistory[0] = {...savedReply(1), kind: 'document', text: 'Old caption',
        media: {type: 'document', url: '/admin/whatsapp/conversations/1/replies/1/attachment', mime_type: 'application/pdf', filename: 'old-menu.pdf', size: 512}};
    await settle(); choose(test); await settle(); assert.strictEqual(walk(test.elements.messages).filter(element => element.tag === 'a').length, 0);
    test.elements.scroll.scrollTop = 0; test.elements.scroll.dispatchEvent({type: 'scroll'}); await settle();
    assert.strictEqual(test.elements.messages.children.length, 30);
    assert.strictEqual(walk(test.elements.messages).filter(element => element.tag === 'a').length, 1);
    publish(test, test.state.apiHistory.slice(-20).map(reply => ({...reply, id: reply.reply_id})));
    assert.strictEqual(walk(test.elements.messages).filter(element => element.tag === 'a').length, 1, 'Recent polls preserve loaded older attachment metadata'); close(test);
}
async function lateHistorySelectionCannotLeak() {
    const test = fixture({history: true}); test.state.delayHistory = true; test.state.apiHistory = [{...savedReply(17), text: 'Private old customer receipt'}];
    await settle(); choose(test, 1); await settle(); test.state.apiHistory = [{...savedReply(18), text: 'Current customer receipt'}]; choose(test, 2); await settle();
    test.state.historyResolvers[1](); await settle(); test.state.historyResolvers[0](); await settle();
    assert(test.elements.messages.textContent.includes('Current customer receipt')); assert(!test.elements.messages.textContent.includes('Private old customer receipt'));
    test.document.hidden = true; test.document.dispatchEvent({type: 'visibilitychange'});
    assert(!test.elements.messages.textContent.includes('Current customer receipt')); close(test);
}

(async () => {
    const cases = [infiniteThreadsAndCursor, historyAnchoringAndNewIndicator, pollingDoesNotOverlap, autonomousPollFindsNativeMessagesAndCustomers,
        autonomousPollRefreshesAcceptedRepliesAndRetainsHistory, delayedRecentPollCannotCrossCustomerScope, unreadCountsCustomers, unreadAggregateFollowsActivity, authenticatedMediaAndStableNodes,
        copyQuoteAndReaction, forwardNeedsExplicitTargetAndConfirmation, staleActionsNeverSend, unknownIsNeverRetried, selectionSnapshotExcludesFutureRows,
        individualSelectionAndLifecycle, apiReplyActionsUseScopedAuditIds, singleDeleteIsLocalAndConfirmed, apiImageScopeAndNativeEchoDeduplication,
        delayedOldMessagesNeverLeak, networkUncertaintyNeverResends, selectAllCoversWholeLargeConversation, lateEchoKeepsSnapshotSelectionAndDedupe,
        allModeAndManualLimitAreExplicit, oldClaimAcceptedAfterSnapshotIsNotSelected, apiOnlyHistoryPagesBeyondTwenty, cachedOlderApiHidesCannotReappear,
        acceptedReactionRendersReloadsAndClears, visibleMetadataUpdatesOlderNativeAndApi, lateHistorySelectionCannotLeak,
        protectedDocumentLinksStayInScope, documentNativeEchoKeepsDownloadAndDedupe, olderDocumentHistoryUsesProtectedReceipt];
    for (const check of cases) await check();
    console.log('WHATSAPP_INBOX_LIVE_TESTS=' + cases.length + ' PASS');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });

