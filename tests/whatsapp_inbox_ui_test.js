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
}

function fixture(withUnread = false, withCart = false, withHub = false) {
    const elements = {};
    ['thread-list', 'list-status', 'count', 'messages', 'chat-status', 'title', 'customer', 'alert',
        'connection', 'scroll', 'older', 'more-threads', 'refresh', 'back', 'unread-total'].forEach(name => {
        elements['[data-wa-' + name + ']'] = new Element();
    });
    const panel = new Element();
    const bootstrap = new Element('script');
    const document = new Element();
    const window = new Element();
    const timers = new Map();
    const state = {calls: [], redirected: false, status: 200, emptyNewer: true, events: [], nextCursor: withHub ? 'fixture-next' : null};
    const labels = {customer: 'Customer', business: 'Business', loading: 'Loading', denied: 'DENIED',
        choose_conversation: 'Choose', empty: 'Empty', unavailable: 'Unavailable', connected: 'Updated',
        no_messages: 'No messages', offline: 'Offline', types: {text: 'Text', other: 'Message', order: 'Cart'},
        cart_title: 'Submitted cart', cart_product: 'Catalog product', cart_quantity: 'Catalog units',
        cart_unit_price: 'Quoted unit price', cart_total: 'Quoted cart subtotal', cart_note: 'Catalog mapping required'};
    const config = {available: true,
        conversations_url: 'https://example.test/admin/whatsapp/conversations',
        messages_base_url: 'https://example.test/admin/whatsapp/conversations', labels, hub_chat: withHub};
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
    if (withHub) document.dispatchEvent = event => {
        state.events.push(event);
        (document.listeners[event.type] || []).slice().forEach(listener => listener(event));
    };
    window.location = {href: 'https://example.test/admin/whatsapp'};
    window.innerHeight = 100; window.innerWidth = 500;
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => { state.cleanup = callback; }};
    let timerId = 0;
    const thread = {id: 1, name: 'Fixture Customer', phone: '+201000000001',
        last_message_at: '2026-10-09T22:00:00+00:00',
        preview: {text: 'Fixture message', type: 'text', direction: 'inbound'}};
    async function fetch(url, options) {
        state.calls.push({url, options});
        assert.strictEqual(options.cache, 'no-store');
        assert.strictEqual(options.credentials, 'same-origin');
        if (!withUnread || !new URL(url).pathname.endsWith('/read')) {
            assert.strictEqual(options.method, undefined, 'Ordinary inbox reads must not send mutations');
        } else {
            assert.strictEqual(options.method, 'POST');
            assert.strictEqual(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
        }
        const parsed = new URL(url);
        const isMessages = parsed.pathname.endsWith('/messages');
        const isNewer = parsed.searchParams.has('after_id');
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
            json: async () => isMessages ? {success: true, conversation: thread,
                messages: isNewer && state.emptyNewer ? [] : visibleRows,
                last_id: 1, has_more: false, next_before_id: null}
                : {success: true, conversations: [parsed.searchParams.has('cursor') ? {...thread, id: 2, name: 'Second Customer'} : thread],
                    total: withHub ? 2 : 1, next_cursor: parsed.searchParams.has('cursor') ? null : state.nextCursor}};
    }
    const context = {window, document, navigator: {onLine: true}, URL, AbortController, Map, Set, Promise, Date,
        fetch, console, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: id => timers.delete(id)};
    vm.createContext(context);
    const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'dashboard-whatsapp-inbox.js'), 'utf8');
    vm.runInContext(source, context);
    return {elements, panel, document, window, timers, state, context};
}

async function settle() {
    for (let index = 0; index < 8; index++) await new Promise(setImmediate);
}

function click(fixture, attribute, id) {
    const target = {dataset: id ? {waThread: String(id)} : {},
        closest: selector => selector === '[' + attribute + ']' ? target : null};
    const listener = fixture.panel.listeners.click[0];
    assert(listener, 'An active page click listener is required');
    listener({target});
}

async function initialAndSafeRendering() {
    const test = fixture();
    await settle();
    assert.strictEqual(test.state.calls.length, 1);
    assert.strictEqual(test.timers.size, 1, 'Only one polling timer should run');
    assert(test.elements['[data-wa-thread-list]'].textContent.includes('Fixture Customer'));
    click(test, 'data-wa-thread', 1);
    await settle();
    const messages = test.elements['[data-wa-messages]'];
    assert(messages.textContent.includes('<img src=x onerror=alert(1)>'));
    assert.strictEqual(messages.children[0].children[0].children[1].tag, 'p', 'Markup must remain plain paragraph text');
    assert(test.elements['[data-wa-title]'].textContent.includes('Fixture Customer'));
    assert.strictEqual(test.elements['[data-wa-customer]'].textContent, '+201000000001');

    click(test, 'data-wa-back');
    assert.strictEqual(test.panel.classList.contains('has-selection'), false);
    const reads = test.state.calls.length;
    click(test, 'data-wa-thread', 1);
    assert.strictEqual(test.panel.classList.contains('has-selection'), true, 'The same mobile chat should reopen');
    assert.strictEqual(test.state.calls.length, reads, 'Reopening the loaded chat does not need a new read');

    const rebuilds = messages.rebuilds;
    click(test, 'data-wa-refresh');
    await settle();
    assert.strictEqual(messages.rebuilds, rebuilds, 'An empty newer-message poll must not rebuild the chat');
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('after_id') === '1'));

    test.state.cleanup();
    assert.strictEqual(messages.textContent, '');
    assert.strictEqual(test.elements['[data-wa-thread-list]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-title]'].textContent, 'Choose');
    assert.strictEqual(test.elements['[data-wa-customer]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-count]'].textContent, '');
    assert.strictEqual(test.timers.size, 0);
    assert.strictEqual(test.panel.listeners.click.length, 0);
    assert.strictEqual(test.document.listeners.visibilitychange.length, 0);
    assert.strictEqual(test.window.listeners.offline.length, 0);
    assert.strictEqual(test.window.listeners.online.length, 0);
}

async function expiredAccessClears(redirected, status = 403) {
    const test = fixture();
    await settle();
    click(test, 'data-wa-thread', 1);
    await settle();
    assert(test.elements['[data-wa-messages]'].textContent.includes('onerror'));
    test.state.redirected = redirected;
    if (!redirected) test.state.status = status;
    click(test, 'data-wa-refresh');
    await settle();
    assert.strictEqual(test.elements['[data-wa-messages]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-thread-list]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-customer]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-alert]'].textContent, 'DENIED');
    assert.strictEqual(test.timers.size, 0, 'Access expiration must stop polling');
    const reads = test.state.calls.length;
    click(test, 'data-wa-refresh');
    await settle();
    assert.strictEqual(test.state.calls.length, reads, 'Expired access must not continue reading');
    test.state.cleanup();
}

async function visibilityAndOffline() {
    const test = fixture();
    await settle();
    test.document.hidden = true;
    test.document.listeners.visibilitychange[0]();
    assert.strictEqual(test.timers.size, 0, 'Hidden pages should suspend polling');
    const reads = test.state.calls.length;
    test.context.navigator.onLine = false;
    test.document.hidden = false;
    test.window.listeners.offline[0]();
    await settle();
    assert.strictEqual(test.state.calls.length, reads);
    assert.strictEqual(test.elements['[data-wa-alert]'].textContent, 'Offline');
    test.state.cleanup();
}

async function unreadVisibility() {
    const test = fixture(true); await settle();
    assert.strictEqual(test.elements['[data-wa-unread-total]'].textContent, 'Unread: 1', 'The global badge counts unread customer conversations, not messages');
    assert(test.elements['[data-wa-thread-list]'].textContent.includes('1'), 'Each unread customer has one conversation indicator');
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/read')).length, 0, 'Opening the thread list does not mark messages read');
    click(test, 'data-wa-thread', 1); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/read')).length, 1);
    test.document.hidden = true;
    const writes = test.state.calls.filter(call => call.url.endsWith('/read')).length;
    click(test, 'data-wa-thread', 2); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/read')).length, writes, 'Hidden pages do not acknowledge displayed messages');
    test.state.cleanup();
    assert.strictEqual(test.elements['[data-wa-unread-total]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-scroll]'].listeners.scroll.length, 0);

    const offscreen = fixture(true); await settle();
    offscreen.elements['[data-wa-scroll]'].getBoundingClientRect = () => ({top: 200, bottom: 400, left: 0, right: 500});
    click(offscreen, 'data-wa-thread', 1); await settle();
    assert.strictEqual(offscreen.state.calls.filter(call => call.url.endsWith('/read')).length, 0, 'A chat panel outside the browser viewport cannot mark messages read');
    offscreen.state.cleanup();
}

async function cartRendering() {
    const test = fixture(false, true); await settle();
    click(test, 'data-wa-thread', 1); await settle();
    const messages = test.elements['[data-wa-messages]'];
    assert(messages.textContent.includes('Submitted cart'));
    assert(messages.textContent.includes('Catalog units: 2'));
    assert(messages.textContent.includes('Quoted unit price: 100.50 EGP'));
    assert(messages.textContent.includes('Quoted cart subtotal: 445.00 EGP'));
    assert(messages.textContent.includes('SKU_TWO'));
    assert(messages.textContent.includes('Verified product <b>title</b>'), 'Configured public catalog title remains literal text while retailer ID is retained');
    assert(messages.textContent.includes('<img src=x onerror=alert(1)>'), 'Cart retailer IDs remain literal text');
    assert(messages.textContent.includes('<script>Cart note</script>'), 'Cart notes never create DOM markup');
    assert.strictEqual(messages.children[0].children[0].children[2].tag, 'section', 'Submitted cart gets structured presentation');
    test.state.cleanup();
    assert.strictEqual(messages.textContent, '', 'Cart contents cleared on SPA navigation');
}

async function hubCoordinatorAPI() {
    const test = fixture(true, false, true); await settle();
    const api = test.window.DashboardWhatsAppInbox;
    const snapshot = () => JSON.parse(JSON.stringify(api.snapshot()));
    assert.strictEqual(api.selection(), 0, 'A hidden hub source starts without selecting or loading a chat');
    assert.strictEqual(test.state.calls.filter(call => new URL(call.url).pathname.endsWith('/messages')).length, 0);
    let current = snapshot();
    assert.strictEqual(current.source, 'whatsapp');
    assert.strictEqual(current.total, 2); assert.strictEqual(current.has_more, true); assert.strictEqual(current.available, true);
    assert.strictEqual(current.unread['1'], 3);
    assert.strictEqual(current.conversations[0].name, 'Fixture Customer');
    const published = test.state.events.filter(event => event.type === 'meta-hub:threads').at(-1).detail;
    assert.strictEqual(published.total, 2, 'Hub publication uses the latest total and cursor, not the prior load metadata');
    assert.strictEqual(published.has_more, true);
    current.conversations[0].name = 'Changed by coordinator';
    assert.strictEqual(snapshot().conversations[0].name, 'Fixture Customer', 'Coordinator snapshots cannot change stored thread names');
    assert.strictEqual(api.openConversation(999), false, 'Only a loaded conversation can be opened');
    assert.strictEqual(api.openConversation(1), true); await settle();
    assert.strictEqual(api.selection(), 1);
    assert(test.elements['[data-wa-messages]'].textContent.includes('<img src=x onerror=alert(1)>'));
    api.resetSelection();
    assert.strictEqual(api.selection(), 0);
    assert.strictEqual(test.elements['[data-wa-messages]'].textContent, '', 'Switching source releases the previous private chat');
    assert.strictEqual(test.panel.classList.contains('has-selection'), false);
    assert.strictEqual(snapshot().conversations.length, 1, 'Resetting a chat preserves the unified list source');
    const messageReads = test.state.calls.filter(call => new URL(call.url).pathname.endsWith('/messages')).length;
    click(test, 'data-wa-refresh'); await settle();
    assert.strictEqual(test.state.calls.filter(call => new URL(call.url).pathname.endsWith('/messages')).length, messageReads,
        'A deselected pane keeps list polling without loading or acknowledging hidden chat messages');
    await api.loadMore(); await settle();
    assert.strictEqual(snapshot().conversations.length, 2); assert.strictEqual(snapshot().has_more, false);
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('cursor') === 'fixture-next'));
    api.openConversation(1); await settle(); click(test, 'data-wa-back');
    assert.strictEqual(api.selection(), 0);
    assert(test.state.events.some(event => event.type === 'meta-hub:conversation-reset' && event.detail.source === 'whatsapp'));
    test.state.status = 403; click(test, 'data-wa-refresh'); await settle();
    const denied = test.state.events.filter(event => event.type === 'meta-hub:threads').at(-1).detail;
    assert.strictEqual(denied.available, false); assert.strictEqual(denied.conversations.length, 0);
    assert.strictEqual(snapshot().total, 0); assert.strictEqual(api.openConversation(1), false);
    test.state.cleanup();
    assert.strictEqual(api.snapshot().available, false);
    assert.strictEqual(api.snapshot().conversations.length, 0);
}

(async () => {
    await initialAndSafeRendering();
    await expiredAccessClears(true);
    await expiredAccessClears(false);
    await expiredAccessClears(false, 419);
    await visibilityAndOffline();
    await unreadVisibility();
    await cartRendering();
    await hubCoordinatorAPI();
    console.log('WHATSAPP_INBOX_UI_PASS initial reads, escaped chat text, mobile reopen, empty poll, redirect/403 privacy, cleanup, offline');
})().catch(error => {
    console.error(error.stack);
    process.exitCode = 1;
});

