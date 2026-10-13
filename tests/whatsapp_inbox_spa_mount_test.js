'use strict';

// Real modules run against the shell's PageScope/EventTarget/cache implementation.
// The embedded shell excerpts are from dashboard-spa.js blob
// 28ec997789cfd0da239f69937bcce032eb0e8c88. They need no outside checkout in CI.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.join(__dirname, '..');
const blade = fs.readFileSync(path.join(root, 'resources/views/admin/whatsapp/index.blade.php'), 'utf8');
const initMatches = [...blade.matchAll(/<script data-dashboard-page-init>\s*([\s\S]*?)<\/script>/g)];
assert.strictEqual(initMatches.length, 1, 'One inline visit initializer is required');
const initializer = initMatches[0][1];
const modules = ['inbox', 'orders', 'replies'].map(name => ({
    name, global: 'DashboardWhatsApp' + name[0].toUpperCase() + name.slice(1),
    url: 'https://example.test/js/dashboard-whatsapp-' + name + '.js?v=fixture',
    source: fs.readFileSync(path.join(root, 'public/js/dashboard-whatsapp-' + name + '.js'), 'utf8')
}));
const shellCore = "    function currentScope() {\n        if (active) return active;\n        var script = document.currentScript;\n        return script && (script.hasAttribute('data-dashboard-page-init') || script.hasAttribute('data-dashboard-page-owned')\n            || script.closest('[data-dashboard-page], [data-dashboard-page-scripts]')) ? script.dashboardPageScope || page : null;\n    }\n    function PageScope() {\n        this.id = ++scopeCount;\n        this.closed = false;\n        this.listeners = [];\n        this.timers = new Map();\n        this.frames = new Set();\n        this.cleanups = [];\n        this.globals = new Map();\n        this.leaveGuards = [];\n    }\n    PageScope.prototype.run = function (callback, context, args) {\n        if (this.closed) return;\n        var previous = active;\n        active = this;\n        attachMaps();\n        attachDialogs();\n        try { return callback.apply(context, args || []); } finally { active = previous; }\n    };\n    PageScope.prototype.bind = function (callback) {\n        var scope = this;\n        if (typeof callback !== 'function') return callback;\n        return function () { return scope.run(callback, this, arguments); };\n    };\n    PageScope.prototype.export = function (name, callback) {\n        if (typeof callback !== 'function') return;\n        if (!this.globals.has(name)) this.globals.set(name, { previous: Object.getOwnPropertyDescriptor(window, name), assigned: null });\n        var wrapped = this.bind(callback);\n        this.globals.get(name).assigned = wrapped;\n        window[name] = wrapped;\n    };\n    PageScope.prototype.dispose = function () {\n        if (this.closed) return;\n        this.closed = true;\n        this.listeners.forEach(function (record) { native.remove.call(record.target, record.type, record.wrapped, record.options); });\n        this.timers.forEach(function (kind, id) { (kind === 'interval' ? native.clearInterval : native.clearTimeout)(id); });\n        this.frames.forEach(native.cancelRaf);\n        this.cleanups.reverse().forEach(function (cleanup) { try { cleanup(); } catch (error) { console.warn('Dashboard page cleanup failed', error); } });\n        this.globals.forEach(function (record, name) {\n            if (window[name] !== record.assigned) return;\n            if (record.previous) Object.defineProperty(window, name, record.previous);\n            else { try { if (!delete window[name]) window[name] = undefined; } catch (_) { window[name] = undefined; } }\n        });\n        this.listeners = [];\n        this.timers.clear();\n        this.frames.clear();\n        this.cleanups = [];\n        this.globals.clear();\n        this.leaveGuards = [];\n    };\n\n    EventTarget.prototype.addEventListener = function (type, callback, options) {\n        var scope = currentScope();\n        if (!scope || !callback) return native.add.call(this, type, callback, options);\n        var target = this;\n        var listener = typeof callback === 'function' ? callback : function (event) { return callback.handleEvent(event); };\n        var wrapped = scope.bind(listener);\n        // Legacy Blade page scripts expect this browser event even on later visits.\n        if (started && ((target === document && type === 'DOMContentLoaded') || (target === window && type === 'load'))) {\n            native.timeout(function () { scope.run(listener, target, [new Event(type)]); }, 0);\n            return;\n        }\n        var capture = typeof options === 'boolean' ? options : !!(options && options.capture);\n        var duplicate = scope.listeners.find(function (record) { return record.target === target && record.type === type && record.callback === callback && record.capture === capture; });\n        if (duplicate) return;\n        scope.listeners.push({ target: target, type: type, callback: callback, wrapped: wrapped, options: options, capture: capture });\n        return native.add.call(target, type, wrapped, options);\n    };\n    EventTarget.prototype.removeEventListener = function (type, callback, options) {\n        var capture = typeof options === 'boolean' ? options : !!(options && options.capture);\n        [active, page].filter(Boolean).some(function (scope) {\n            var record = scope.listeners.find(function (item) { return item.target === this && item.type === type && item.callback === callback && item.capture === capture; }, this);\n            if (!record) return false;\n            callback = record.wrapped;\n            scope.listeners.splice(scope.listeners.indexOf(record), 1);\n            return true;\n        }, this);\n        return native.remove.call(this, type, callback, options);\n    };\n    function timer(kind, callback, delay, args) {\n        var scope = currentScope();\n        if (!scope || typeof callback !== 'function') return native[kind].apply(null, [callback, delay].concat(args));\n        var id = native[kind](function () {\n            if (kind === 'timeout') scope.timers.delete(id);\n            scope.run(callback, window, args);\n        }, delay);\n        scope.timers.set(id, kind);\n        return id;\n    }\n    window.setTimeout = function (callback, delay) { return timer('timeout', callback, delay, Array.prototype.slice.call(arguments, 2)); };\n    window.setInterval = function (callback, delay) { return timer('interval', callback, delay, Array.prototype.slice.call(arguments, 2)); };\n    window.requestAnimationFrame = function (callback) {\n        var scope = currentScope();\n        if (!scope) return native.raf(callback);\n        var id = native.raf(function (time) { scope.frames.delete(id); scope.run(callback, window, [time]); });\n        scope.frames.add(id);\n        return id;\n    };\n";
const shellCache = "    function copyAttributes(source, target, names) {\n        names.forEach(function (name) { if (source.hasAttribute(name)) target.setAttribute(name, source.getAttribute(name)); else target.removeAttribute(name); });\n    }\n    function inlineNames(code) {\n        var names = new Set();\n        var expression = /\\bfunction\\s+([A-Za-z_$][\\w$]*)\\s*\\(/g;\n        var match;\n        while ((match = expression.exec(code))) names.add(match[1]);\n        return Array.from(names);\n    }\n\n    function pageScript(script) {\n        var type = (script.getAttribute('type') || '').toLowerCase();\n        return !type || /^(?:text|application)\\/javascript$/.test(type) || type === 'module';\n    }\n    function assetKey(value) {\n        var url = new URL(value, location.href);\n        // Google Maps varies its per-page callback. The SDK itself loads once.\n        if (url.hostname === 'maps.googleapis.com' && url.pathname === '/maps/api/js') url.searchParams.delete('callback');\n        return url.href;\n    }\n    function mapsCallback(url, scope) {\n        var parsed = new URL(url, location.href);\n        if (parsed.hostname !== 'maps.googleapis.com' || parsed.pathname !== '/maps/api/js') return;\n        var callback = parsed.searchParams.get('callback');\n        if (callback && typeof window[callback] === 'function') scope.run(window[callback], window, []);\n    }\n    async function execute(script, scope, url) {\n        if (!pageScript(script)) return;\n        if (script.src || script.hasAttribute('src')) {\n            var source = new URL(script.getAttribute('src'), url).href;\n            var key = assetKey(source);\n            var parsed = new URL(source, url);\n            var isMaps = parsed.hostname === 'maps.googleapis.com' && parsed.pathname === '/maps/api/js';\n            var callbackName = isMaps && parsed.searchParams.get('callback');\n            var perPage = /\\/dashboard\\/js\\//.test(source);\n            if (!perPage && loadedScripts.has(key)) {\n                await loadedScripts.get(key);\n                mapsCallback(source, scope);\n                return;\n            }\n            if (isMaps && callbackName) {\n                var scopedName = 'dashboardMapsReady' + scope.id;\n                scope.export(scopedName, function () { mapsCallback(new URL(script.getAttribute('src'), url).href, scope); });\n                parsed.searchParams.set('callback', scopedName);\n                source = parsed.href;\n            }\n            var wait = new Promise(function (resolve, reject) {\n                var element = document.createElement('script');\n                copyAttributes(script, element, ['type', 'integrity', 'crossorigin', 'referrerpolicy', 'nonce']);\n                element.src = source; element.async = false;\n                if (perPage) { element.setAttribute('data-dashboard-page-owned', ''); element.dashboardPageScope = scope; }\n                native.add.call(element, 'load', function () { resolve(); });\n                native.add.call(element, 'error', function () { reject(new Error('Dashboard dependency could not load: ' + source)); });\n                document.body.appendChild(element);\n                if (perPage) scope.cleanups.push(function () { element.remove(); resolve(); });\n            });\n            if (!perPage) loadedScripts.set(key, wait);\n            try { await wait; } catch (error) { if (!perPage) loadedScripts.delete(key); throw error; }\n            return;\n        }\n        var source = script.textContent;\n        var exports = inlineNames(source).map(function (name) { return 'if(typeof ' + name + ' === \"function\" && dashboardSourceText.indexOf(Function.prototype.toString.call(' + name + ')) >= 0) scope.export(' + JSON.stringify(name) + ',' + name + ');'; }).join('\\n');\n        // Isolate Blade const/let declarations on every visit, while retaining\n        // named callbacks used by existing inline buttons and the Maps SDK.\n        scope.run(new Function('scope', 'dashboardSourceText', source + '\\n' + exports + '\\n//# sourceURL=dashboard-page-inline.js'), window, [scope, source]);\n    }\n";

const camel = value => value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
function fixture() {
    class Target {
        constructor() { this.listeners = new Map(); }
        addEventListener(type, callback) {
            const list = this.listeners.get(type) || [];
            if (!list.includes(callback)) list.push(callback);
            this.listeners.set(type, list);
        }
        removeEventListener(type, callback) { this.listeners.set(type, (this.listeners.get(type) || []).filter(value => value !== callback)); }
        dispatchEvent(event) {
            if (!event.target) event.target = this;
            (this.listeners.get(event.type) || []).slice().forEach(callback => callback.call(this, event));
            return true;
        }
        listenerCount() { return [...this.listeners.values()].reduce((sum, values) => sum + values.length, 0); }
    }
    class Element extends Target {
        constructor(tag = 'div') {
            super(); this.tagName = tag; this.children = []; this.parentNode = null; this.dataset = {}; this.attributes = {};
            this.text = ''; this.value = ''; this.style = {}; this.hidden = false; this.disabled = false; this.checked = false;
            this.clientHeight = 150; this.scrollHeight = 700; this.scrollTop = 0;
            this.classList = {values: new Set(), add(value) { this.values.add(value); }, remove(value) { this.values.delete(value); },
                contains(value) { return this.values.has(value); }, toggle(value, enabled) { if (enabled) this.add(value); else this.remove(value); }};
        }
        set textContent(value) { this.text = String(value ?? ''); this.children.forEach(item => { item.parentNode = null; }); this.children = []; }
        get textContent() { return this.text + this.children.map(item => item.textContent).join(''); }
        set innerHTML(_) { throw new Error('Private text must not be parsed as HTML'); }
        get firstChild() { return this.children[0] || null; }
        get nextSibling() { return this.parentNode && this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null; }
        appendChild(item) { return this.insertBefore(item, null); }
        append(...items) { items.forEach(item => this.appendChild(item)); }
        insertBefore(item, before) {
            if (item.tagName === 'fragment') { item.children.slice().forEach(child => this.insertBefore(child, before)); return item; }
            if (item.parentNode) item.parentNode.removeChild(item);
            const index = before ? this.children.indexOf(before) : this.children.length;
            this.children.splice(index < 0 ? this.children.length : index, 0, item); item.parentNode = this; return item;
        }
        removeChild(item) { this.children.splice(this.children.indexOf(item), 1); item.parentNode = null; }
        remove() { if (this.parentNode) this.parentNode.removeChild(this); }
        contains(item) { while (item) { if (item === this) return true; item = item.parentNode; } return false; }
        matches(selector) {
            const match = selector.match(/^\[data-([^=\]]+)(?:="([^"]*)")?\]$/);
            return Boolean(match && Object.hasOwn(this.dataset, camel(match[1])) && (match[2] === undefined || this.dataset[camel(match[1])] === match[2]));
        }
        closest(selector) { for (let current = this; current; current = current.parentNode) if (current.matches(selector)) return current; return null; }
        querySelector(selector) { return walk(this).find(item => item.matches(selector)) || null; }
        querySelectorAll(selector) { return walk(this).filter(item => item.matches(selector)); }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        getAttribute(name) { return this.attributes[name] ?? null; }
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
        removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
        getBoundingClientRect() { return {top: 0, bottom: 70, left: 0, right: 500}; }
        focus() {} pause() {} load() {}
    }
    function walk(element) { return [element, ...element.children.flatMap(walk)]; }
    class Event { constructor(type, options = {}) { this.type = type; this.detail = options.detail || {}; } }
    const document = new Target(), window = new Target();
    document.documentElement = {lang: 'en'}; document.hidden = false; document.body = new Element('body'); document.currentScript = null;
    document.createElement = tag => new Element(tag); document.createDocumentFragment = () => new Element('fragment');
    let current = null;
    document.getElementById = id => current && current.ids.get(id) || null;
    window.location = {href: 'https://example.test/admin/whatsapp'};
    window.innerWidth = 1200; window.innerHeight = 800;
    window.crypto = {randomUUID: () => '00000000-0000-4000-8000-000000000001'};
    let timerId = 0;
    const timers = new Map(), calls = [], held = [];
    const nativeTimeout = (callback, delay, ...args) => { timers.set(++timerId, {callback: () => callback(...args), delay}); return timerId; };
    window.setTimeout = nativeTimeout; window.clearTimeout = id => timers.delete(id);
    window.setInterval = nativeTimeout; window.clearInterval = id => timers.delete(id);
    window.requestAnimationFrame = callback => nativeTimeout(callback, 16); window.cancelAnimationFrame = window.clearTimeout;
    let holding = false;
    async function fetch(url, options = {}) {
        const pathname = new URL(url).pathname;
        calls.push({url: String(url), pathname, options});
        if (holding) await new Promise(resolve => held.push(resolve));
        const pageId = Number(new URL(url).searchParams.get('page')) || current && current.id || 0;
        const success = {success: true};
        let data;
        if (options.method === 'POST') data = {...success, state: 'ACCEPTED', reply_id: 700};
        else if (pathname.endsWith('/reply-state')) data = {...success, available: true, can_reply: true, latest_inbound_id: 101,
            voice_ready: false, recent_replies: [{reply_id: 701, kind: 'text', state: 'ACCEPTED', source: 'dashboard_api',
                text: 'Private business ' + pageId, created_at: '2026-10-12T18:00:00Z'}]};
        else if (pathname.endsWith('/orders/meta')) data = {...success, available: true, can_checkout: true, ai_ready: true,
            mode: 'auto', branches: [], urls: {}};
        else if (pathname.endsWith('/orders')) data = {...success, available: true, drafts: [], pending_analysis: false};
        else if (pathname.endsWith('/messages')) data = {...success, conversation: {id: 1, name: 'Private customer ' + pageId},
            messages: [{id: 101, type: 'text', direction: 'inbound', text: 'Private message ' + pageId,
                sent_at: '2026-10-12T18:00:00Z', actions: {}}], last_id: 101, next_before_id: null, has_more: false};
        else if (pathname.endsWith('/unread')) data = {...success, total_unread_conversations: 1, total_unread: 1, conversations: [{id: 1, unread_count: 1}]};
        else data = {...success, conversations: [{id: 1, name: 'Private customer ' + pageId}], total: 1, next_cursor: null};
        return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => data};
    }
    const navigator = {onLine: true};
    const context = vm.createContext({document, window, location: window.location, navigator, EventTarget: Target, Element,
        URL, AbortController, CustomEvent: Event, Event, Map, Set, Promise, Date, Uint8Array, Blob, console,
        attachMaps() {}, attachDialogs() {}});
    vm.runInContext(`
        var native = {add: EventTarget.prototype.addEventListener, remove: EventTarget.prototype.removeEventListener,
            timeout: window.setTimeout, clearTimeout: window.clearTimeout, interval: window.setInterval,
            clearInterval: window.clearInterval, raf: window.requestAnimationFrame, cancelRaf: window.cancelAnimationFrame};
        var scopeCount = 0, active = null, page = new PageScope(), started = true, loadedScripts = new Map();
        ${shellCore}
        ${shellCache}
        window.DashboardSPA = {
            onCleanup: function(callback) { var scope = currentScope() || page; if (typeof callback === 'function') scope.cleanups.push(callback); },
            isCurrentPage: function() { var scope = currentScope(); return !scope || scope === page && !scope.closed; }
        };
        window.fixtureShell = {
            scope: function() { return page; },
            replace: function() { page.dispose(); page = new PageScope(); return page; },
            cached: function(url) { loadedScripts.set(assetKey(url), Promise.resolve()); },
            execute: execute
        };
        var setTimeout = window.setTimeout, clearTimeout = window.clearTimeout;
        var setInterval = window.setInterval, clearInterval = window.clearInterval;
    `, context);
    context.fetch = fetch;
    function pageDom(id) {
        const host = new Element(), ids = new Map(), elements = {};
        const add = (target, selector) => {
            const element = new Element(); const match = selector.match(/^\[data-([^=\]]+)(?:="([^"]*)")?\]$/);
            element.dataset[camel(match[1])] = match[2] || ''; target.appendChild(element); elements[selector] = element; return element;
        };
        ids.set('whatsapp-inbox', host);
        const inbox = ['thread-list', 'thread-scroll', 'conversation-sentinel', 'message-sentinel', 'list-status', 'count', 'messages', 'chat-status',
            'title', 'customer', 'alert', 'connection', 'scroll', 'older', 'more-threads', 'refresh', 'back', 'unread-total', 'new-messages',
            'select-mode', 'selection-toolbar', 'selection-count', 'select-all', 'selection-cancel', 'delete-selected'];
        inbox.forEach(name => add(host, '[data-wa-' + name + ']'));
        const orders = add(host, '[data-wa-orders]'); add(host, '[data-wa-order-drawer]');
        ['form', 'status', 'proposed', 'items', 'quote', 'analyze', 'reload', 'calculate', 'dispatch', 'add', 'search', 'search-button', 'more',
            'draft', 'draft-label', 'customer-lookup', 'customer-results', 'address-search', 'address-results', 'map', 'location-status', 'confirm-pin', 'distance']
            .forEach(name => add(orders, '[data-wa-order-' + name + ']'));
        ['customer_name', 'customer_phone', 'address', 'area', 'delivery_notes', 'branch', 'latitude', 'longitude', 'location_confirmed']
            .forEach(name => add(orders, '[data-wa-order-field="' + name + '"]'));
        const replies = add(host, '[data-wa-replies]');
        ['reply-status', 'reply-form', 'reply-text', 'reply-send', 'reply-refresh', 'reply-count', 'voice-record', 'voice-stop', 'voice-cancel',
            'voice-send', 'voice-preview', 'voice-time', 'voice-status', 'reply-audit', 'reply-audit-list', 'reply-quote', 'reply-quote-text',
            'reply-quote-cancel', 'reply-emoji', 'reply-emoji-picker', 'attachment-select', 'attachment-input', 'attachment-preview', 'attachment-image',
            'attachment-name', 'attachment-size', 'attachment-cancel'].forEach(name => add(replies, '[data-wa-' + name + ']'));
        const base = 'https://example.test/admin/whatsapp/conversations';
        const labels = {loading: 'Loading', connected: 'Connected', choose_conversation: 'Choose', empty: 'Empty', no_messages: 'No messages',
            business: 'Business', customer: 'Customer', ready: 'Ready', accepted: 'Accepted', unavailable: 'Unavailable'};
        const config = {
            inbox: {available: true, conversations_url: base + '?page=' + id, messages_base_url: base,
                actions_base_url: base, unread_url: 'https://example.test/admin/whatsapp/unread', csrf: 'csrf-' + id, labels},
            orders: {conversations_base_url: base, meta_url: 'https://example.test/admin/whatsapp/orders/meta', csrf: 'csrf-' + id, labels},
            replies: {conversations_base_url: base, csrf: 'csrf-' + id, labels}
        };
        Object.entries(config).forEach(([name, value]) => {
            const element = new Element('script'); element.textContent = JSON.stringify(value); ids.set('whatsapp-' + name + '-bootstrap', element);
        });
        return {id, host, ids, elements, orders, replies};
    }
    function activate(id) { current = pageDom(id); return current; }
    function runInitializer(scope = window.fixtureShell.scope()) {
        const script = new Element('script'); script.textContent = initializer; script.setAttribute('data-dashboard-page-init', '');
        return window.fixtureShell.execute(script, scope, window.location.href);
    }
    function hardLoad() {
        const script = new Element('script'); script.setAttribute('data-dashboard-page-owned', ''); script.dashboardPageScope = window.fixtureShell.scope();
        document.currentScript = script;
        modules.forEach(module => { vm.runInContext(module.source, context); window.fixtureShell.cached(module.url); });
        document.currentScript = null;
    }
    async function visit(id, initialize = true) {
        document.dispatchEvent(new Event('dashboard:before-unload'));
        window.fixtureShell.replace(); current = id ? pageDom(id) : null;
        if (id) {
            for (const module of modules) { const script = new Element('script'); script.setAttribute('src', module.url); script.src = module.url;
                await window.fixtureShell.execute(script, window.fixtureShell.scope(), window.location.href); }
            if (initialize) { await runInitializer(); await runInitializer(); }
        }
        document.dispatchEvent(new Event('dashboard:page-mounted'));
    }
    function select() { const thread = current.host.querySelector('[data-wa-thread]'); assert(thread); current.host.dispatchEvent({type: 'click', target: thread}); }
    return {window, document, context, calls, timers, activate, hardLoad, visit, runInitializer, select,
        hold() { holding = true; }, release() { holding = false; held.splice(0).forEach(resolve => resolve()); },
        current: () => current, listenerCount: () => document.listenerCount() + window.listenerCount() + (current ? walk(current.host).reduce((sum, item) => sum + item.listenerCount(), 0) : 0)};
}
async function settle() { for (let count = 0; count < 12; count++) await new Promise(setImmediate); }

async function hardLoadIdempotence() {
    const test = fixture(); const first = test.activate(1); test.hardLoad(); await settle();
    const calls = test.calls.length, listeners = test.listenerCount(), timers = test.timers.size;
    await test.runInitializer(); await test.runInitializer(); test.document.dispatchEvent({type: 'dashboard:page-mounted'}); await settle();
    assert.strictEqual(test.calls.length, calls, 'Hard load and duplicate inline execution share one inbox');
    assert.strictEqual(test.listenerCount(), listeners); assert.strictEqual(test.timers.size, timers);
    assert(first.elements['[data-wa-thread-list]'].textContent.includes('Private customer 1'));
}
async function cachedAssetsNeedInitializer() {
    const test = fixture(); test.activate(1); test.hardLoad(); await settle();
    await test.visit(0); await test.visit(2, false); await settle();
    assert.strictEqual(test.current().elements['[data-wa-thread-list]'].textContent, '', 'Removed scoped mount listeners cannot revive cached modules');
    assert.strictEqual(test.timers.size, 0);
    await test.runInitializer(); await settle();
    assert(test.current().elements['[data-wa-thread-list]'].textContent.includes('Private customer 2'), 'Inline visit initialization revives cached module APIs');
}
async function twoReturnCycles() {
    const test = fixture(); test.activate(1); test.hardLoad(); await settle();
    const apis = modules.map(module => test.window[module.global]); let mountedListeners = null, selectedTimers = null;
    for (let id = 1; id <= 3; id++) {
        if (id > 1) { await test.visit(id); await settle(); }
        assert.deepStrictEqual(modules.map(module => test.window[module.global]), apis, 'SPA return uses the same cached module APIs');
        const dom = test.current(); assert(dom.elements['[data-wa-thread-list]'].textContent.includes('Private customer ' + id));
        test.select(); await settle();
        assert(dom.elements['[data-wa-messages]'].textContent.includes('Private message ' + id));
        assert(dom.elements['[data-wa-reply-audit-list]'].textContent.includes('Private business ' + id));
        assert.strictEqual(dom.replies.hidden, false); assert.strictEqual(dom.orders.hidden, false, 'The order module remounts too');
        assert(test.calls.some(call => call.pathname.endsWith('/orders')));
        dom.elements['[data-wa-order-field="customer_name"]'].value = 'Private editable order ' + id;
        dom.elements['[data-wa-reply-text]'].value = 'Private reply draft ' + id;
        if (id > 1) {
            if (mountedListeners === null) { mountedListeners = test.listenerCount(); selectedTimers = test.timers.size; }
            else { assert.strictEqual(test.listenerCount(), mountedListeners, 'Return visits must not grow handlers'); assert.strictEqual(test.timers.size, selectedTimers, 'Return visits must not grow polling timers'); }
        }
        await test.visit(0); await settle();
        assert.strictEqual(test.timers.size, 0, 'Leaving disposes every page poll/timer');
        assert.strictEqual(dom.elements['[data-wa-thread-list]'].textContent, ''); assert.strictEqual(dom.elements['[data-wa-messages]'].textContent, '');
        assert.strictEqual(dom.elements['[data-wa-reply-audit-list]'].textContent, ''); assert.strictEqual(dom.elements['[data-wa-reply-text]'].value, '');
        assert.strictEqual(dom.elements['[data-wa-order-field="customer_name"]'].value, '', 'Private branch/customer drafts are cleared');
        const callCount = test.calls.length; test.document.dispatchEvent({type: 'whatsapp:conversation-selected', detail: {conversation: 1}}); await settle();
        assert.strictEqual(test.calls.length, callCount, 'Disposed handlers cannot send requests from the old page');
    }
}
async function freshCsrfAfterReturn() {
    const test = fixture(); test.activate(1); test.hardLoad(); await settle(); await test.visit(0); await test.visit(2); await settle();
    test.select(); await settle(); const dom = test.current(), text = dom.elements['[data-wa-reply-text]'];
    text.value = 'Fresh reply'; text.dispatchEvent({type: 'input'});
    dom.elements['[data-wa-reply-form]'].dispatchEvent({type: 'submit', preventDefault() {}}); await settle();
    const posts = test.calls.filter(call => call.options.method === 'POST' && call.pathname.endsWith('/replies'));
    assert.strictEqual(posts.length, 1); assert.strictEqual(posts[0].options.headers['X-CSRF-TOKEN'], 'csrf-2', 'Remount reads fresh server bootstrap credentials');
    assert.strictEqual(JSON.parse(posts[0].options.body).text, 'Fresh reply');
}
async function staleScopeCannotMount() {
    const test = fixture(); test.activate(1); test.hardLoad(); await settle(); const oldScope = test.window.fixtureShell.scope();
    await test.visit(0); test.activate(2);
    const currentScope = test.window.fixtureShell.scope();
    // isCurrentPage rejects a superseded but not yet disposed async script scope.
    oldScope.closed = false;
    oldScope.run(() => vm.runInContext(initializer, test.context));
    await settle(); assert.strictEqual(test.current().elements['[data-wa-thread-list]'].textContent, ''); assert.strictEqual(test.timers.size, 0);
    oldScope.closed = true;
    await test.runInitializer(currentScope); await settle();
    assert(test.current().elements['[data-wa-thread-list]'].textContent.includes('Private customer 2'));
}
async function latePrivateResponsesCannotRepopulate() {
    const test = fixture(); const old = test.activate(1); test.hardLoad(); await settle();
    test.hold(); test.select(); await settle(); await test.visit(0); test.release(); await settle();
    assert.strictEqual(old.elements['[data-wa-messages]'].textContent, ''); assert.strictEqual(old.elements['[data-wa-reply-audit-list]'].textContent, '');
    assert.strictEqual(test.timers.size, 0, 'Late responses cannot resume the disposed polling loop');
    await test.visit(2); await settle(); test.select(); await settle();
    assert(test.current().elements['[data-wa-messages]'].textContent.includes('Private message 2'));
    assert(!test.current().elements['[data-wa-messages]'].textContent.includes('Private message 1'));
}
async function withoutSpaOrMissingModule() {
    const test = fixture(); test.activate(1); test.hardLoad(); await settle();
    delete test.window.DashboardSPA;
    vm.runInContext(initializer, test.context); await settle();
    test.window.DashboardWhatsAppOrders = undefined;
    assert.doesNotThrow(() => vm.runInContext(initializer, test.context));
}
async function scriptOrdering() {
    const initPosition = blade.indexOf('<script data-dashboard-page-init>');
    modules.forEach(module => assert(blade.indexOf("asset('js/dashboard-whatsapp-" + module.name + ".js')") < initPosition));
    assert(!initializer.includes('addEventListener')); assert(!initializer.includes('setTimeout')); assert(!initializer.includes('setInterval'));
}
(async () => {
    const cases = [hardLoadIdempotence, cachedAssetsNeedInitializer, twoReturnCycles, freshCsrfAfterReturn,
        staleScopeCannotMount, latePrivateResponsesCannotRepopulate, withoutSpaOrMissingModule, scriptOrdering];
    for (const test of cases) { await test(); console.log('PASS ' + test.name); }
    console.log('WHATSAPP_INBOX_SPA_MOUNT_CHECKS=' + cases.length);
})().catch(error => { console.error(error); process.exitCode = 1; });
