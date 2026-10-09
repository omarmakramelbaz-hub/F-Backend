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

function fixture() {
    const elements = {};
    ['thread-list', 'list-status', 'count', 'messages', 'chat-status', 'title', 'customer', 'alert',
        'connection', 'scroll', 'older', 'more-threads', 'refresh', 'back'].forEach(name => {
        elements['[data-wa-' + name + ']'] = new Element();
    });
    const panel = new Element();
    const bootstrap = new Element('script');
    const document = new Element();
    const window = new Element();
    const timers = new Map();
    const state = {calls: [], redirected: false, status: 200, emptyNewer: true};
    const labels = {customer: 'Customer', business: 'Business', loading: 'Loading', denied: 'DENIED',
        choose_conversation: 'Choose', empty: 'Empty', unavailable: 'Unavailable', connected: 'Updated',
        no_messages: 'No messages', offline: 'Offline', types: {text: 'Text', other: 'Message'}};
    const config = {available: true,
        conversations_url: 'https://example.test/admin/whatsapp/conversations',
        messages_base_url: 'https://example.test/admin/whatsapp/conversations', labels};
    bootstrap.textContent = JSON.stringify(config);
    panel.querySelector = selector => elements[selector];
    document.getElementById = id => id === 'whatsapp-inbox' ? panel
        : id === 'whatsapp-inbox-bootstrap' ? bootstrap : null;
    document.createElement = tag => new Element(tag);
    document.createDocumentFragment = () => new Element('fragment');
    document.documentElement = {lang: 'en'};
    document.hidden = false;
    window.location = {href: 'https://example.test/admin/whatsapp'};
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => { state.cleanup = callback; }};
    let timerId = 0;
    const thread = {id: 1, name: 'Fixture Customer', phone: '+201000000001',
        last_message_at: '2026-10-09T22:00:00+00:00',
        preview: {text: 'Fixture message', type: 'text', direction: 'inbound'}};
    async function fetch(url, options) {
        state.calls.push({url, options});
        assert.strictEqual(options.cache, 'no-store');
        assert.strictEqual(options.credentials, 'same-origin');
        assert.strictEqual(options.method, undefined, 'The inbox must not send mutations');
        const parsed = new URL(url);
        const isMessages = parsed.pathname.endsWith('/messages');
        const isNewer = parsed.searchParams.has('after_id');
        return {ok: state.status === 200, status: state.status, redirected: state.redirected,
            headers: {get: () => state.redirected ? 'text/html' : 'application/json'},
            json: async () => isMessages ? {success: true, conversation: thread,
                messages: isNewer && state.emptyNewer ? [] : [{id: 1, direction: 'inbound', type: 'text',
                    text: '<img src=x onerror=alert(1)>', sent_at: thread.last_message_at}],
                last_id: 1, has_more: false, next_before_id: null}
                : {success: true, conversations: [thread], total: 1, next_cursor: null}};
    }
    const context = {window, document, navigator: {onLine: true}, URL, AbortController, Map, Set, Promise, Date,
        fetch, console, setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
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

async function expiredAccessClears(redirected) {
    const test = fixture();
    await settle();
    click(test, 'data-wa-thread', 1);
    await settle();
    assert(test.elements['[data-wa-messages]'].textContent.includes('onerror'));
    test.state.redirected = redirected;
    if (!redirected) test.state.status = 403;
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

(async () => {
    await initialAndSafeRendering();
    await expiredAccessClears(true);
    await expiredAccessClears(false);
    await visibilityAndOffline();
    console.log('WHATSAPP_INBOX_UI_PASS initial reads, escaped chat text, mobile reopen, empty poll, redirect/403 privacy, cleanup, offline');
})().catch(error => {
    console.error(error.stack);
    process.exitCode = 1;
});
