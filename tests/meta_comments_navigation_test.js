'use strict';
// Run the installer-supplied sidebar patch against a small, asynchronous DOM.
// Usage: python prints NAV_BLOCK | node tests/meta_comments_navigation_test.js
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(0, 'utf8');
assert(source.trim(), 'Supply the installer NAV_BLOCK on stdin');

class Events {
    constructor() { this.listeners = new Map(); }
    addEventListener(type, callback, options = {}) {
        const entries = this.listeners.get(type) || [];
        entries.push({callback, once: Boolean(options && options.once)});
        this.listeners.set(type, entries);
    }
    removeEventListener(type, callback) {
        this.listeners.set(type, (this.listeners.get(type) || []).filter(entry => entry.callback !== callback));
    }
    dispatchEvent(event) {
        if (typeof event === 'string') event = {type: event};
        event.target ||= this;
        for (const entry of (this.listeners.get(event.type) || []).slice()) {
            if (entry.once) this.removeEventListener(event.type, entry.callback);
            entry.callback.call(this, event);
        }
        return true;
    }
}

function descendants(root) { return root.children.flatMap(child => [child, ...descendants(child)]); }

function simpleMatch(element, selector) {
    if (selector === '*') return true;
    const attributes = [...selector.matchAll(/\[([\w-]+)(?:([*^$~|]?=)["']?([^\]"']*)["']?)?\]/g)];
    selector = selector.replace(/\[[^\]]+\]/g, '');
    const tag = selector.match(/^[\w-]+/);
    if (tag && element.tagName.toLowerCase() !== tag[0].toLowerCase()) return false;
    for (const match of selector.matchAll(/\.([\w-]+)/g)) if (!element.classList.contains(match[1])) return false;
    for (const match of selector.matchAll(/#([\w-]+)/g)) if (element.getAttribute('id') !== match[1]) return false;
    for (const [, name, operator, expected] of attributes) {
        const actual = element.getAttribute(name);
        if (actual === null) return false;
        if (operator === '=' && actual !== expected) return false;
        if (operator === '^=' && !actual.startsWith(expected)) return false;
        if (operator === '$=' && !actual.endsWith(expected)) return false;
        if (operator === '*=' && !actual.includes(expected)) return false;
        assert(!operator || ['=', '^=', '$=', '*='].includes(operator), 'Unsupported attribute selector: ' + operator);
    }
    const remainder = selector.replace(/^[\w-]+/, '').replace(/[.#][\w-]+/g, '');
    assert(!remainder, 'Unsupported selector: ' + selector);
    return Boolean(tag || selector || attributes.length);
}

function matches(element, selector) {
    return selector.split(',').some(part => {
        const pieces = part.trim().split(/\s+/);
        let candidate = element;
        if (!simpleMatch(candidate, pieces.pop())) return false;
        while (pieces.length) {
            const piece = pieces.pop();
            if (piece === '>') {
                candidate = candidate.parentElement;
                if (!candidate || !simpleMatch(candidate, pieces.pop())) return false;
            } else {
                candidate = candidate.parentElement;
                while (candidate && !simpleMatch(candidate, piece)) candidate = candidate.parentElement;
                if (!candidate) return false;
            }
        }
        return true;
    });
}

class Element extends Events {
    constructor(runtime, tag = 'div') {
        super();
        this.runtime = runtime;
        this.tagName = tag.toUpperCase();
        this.nodeType = tag === '#document' ? 9 : 1;
        this.children = [];
        this.parentNode = null;
        this.attributes = new Map();
        this.tokens = new Set();
        const update = () => this.setAttribute('class', [...this.tokens].join(' '));
        this.classList = {
            contains: token => this.tokens.has(token),
            add: (...tokens) => { tokens.forEach(token => this.tokens.add(token)); update(); },
            remove: (...tokens) => { tokens.forEach(token => this.tokens.delete(token)); update(); },
            toggle: (token, force) => {
                const wanted = force === undefined ? !this.tokens.has(token) : Boolean(force);
                if (wanted !== this.tokens.has(token)) { wanted ? this.tokens.add(token) : this.tokens.delete(token); update(); }
                return wanted;
            },
            [Symbol.iterator]: () => this.tokens.values(),
        };
    }
    get parentElement() { return this.parentNode && this.parentNode.nodeType === 1 ? this.parentNode : null; }
    get nextElementSibling() {
        if (!this.parentNode) return null;
        return this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null;
    }
    get previousElementSibling() {
        if (!this.parentNode) return null;
        return this.parentNode.children[this.parentNode.children.indexOf(this) - 1] || null;
    }
    get nextSibling() { return this.nextElementSibling; }
    get firstElementChild() { return this.children[0] || null; }
    get isConnected() { return this.runtime.document.contains(this); }
    get className() { return this.getAttribute('class') || ''; }
    set className(value) { this.setAttribute('class', value); }
    get href() { return new URL(this.getAttribute('href') || '', this.runtime.window.location.href).href; }
    set href(value) { this.setAttribute('href', value); }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    hasAttribute(name) { return this.attributes.has(name); }
    setAttribute(name, value) {
        this.attributes.set(name, String(value));
        if (name === 'class') this.tokens = new Set(String(value).split(/\s+/).filter(Boolean));
        this.runtime.mutate({type: 'attributes', target: this, attributeName: name});
    }
    removeAttribute(name) {
        if (!this.attributes.delete(name)) return;
        if (name === 'class') this.tokens.clear();
        this.runtime.mutate({type: 'attributes', target: this, attributeName: name});
    }
    contains(child) { return this === child || this.children.some(candidate => candidate.contains(child)); }
    matches(selector) { return matches(this, selector); }
    closest(selector) { for (let item = this; item; item = item.parentElement) if (item.matches(selector)) return item; return null; }
    querySelectorAll(selector) { return descendants(this).filter(element => element.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    removeChild(child) {
        const index = this.children.indexOf(child);
        assert(index >= 0, 'Removed child must belong to its parent');
        this.children.splice(index, 1);
        child.parentNode = null;
        this.runtime.mutate({type: 'childList', target: this, addedNodes: [], removedNodes: [child]});
        return child;
    }
    insertBefore(child, reference) {
        assert(reference === null || this.children.includes(reference), 'Reference must belong to destination');
        if (reference === child) return child;
        if (child.parentNode) child.parentNode.removeChild(child);
        const index = reference === null ? this.children.length : this.children.indexOf(reference);
        this.children.splice(index, 0, child);
        child.parentNode = this;
        this.runtime.mutate({type: 'childList', target: this, addedNodes: [child], removedNodes: []});
        return child;
    }
    appendChild(child) { return this.insertBefore(child, null); }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    insertAdjacentElement(position, child) {
        assert.strictEqual(position, 'afterend', 'Sidebar patch only needs afterend insertion');
        return this.parentNode.insertBefore(child, this.nextElementSibling);
    }
    after(child) { this.insertAdjacentElement('afterend', child); }
    remove() { if (this.parentNode) this.parentNode.removeChild(this); }
}

function runtime(url, readyState = 'loading') {
    const test = {writes: 0, deliveries: 0, frames: 0, observers: [], microtasks: [], rafs: new Map(), timers: new Map(), nextID: 0};
    test.mutate = record => {
        test.writes++;
        for (const observer of test.observers) {
            const included = observer.targets.some(({target, options}) => {
                if (record.target !== target && !(options.subtree && target.contains(record.target))) return false;
                if (record.type === 'childList') return Boolean(options.childList);
                return Boolean(options.attributes && (!options.attributeFilter || options.attributeFilter.includes(record.attributeName)));
            });
            if (!included) continue;
            observer.records.push(record);
            if (observer.pending) continue;
            observer.pending = true;
            test.microtasks.push(() => {
                observer.pending = false;
                const records = observer.takeRecords();
                if (records.length && observer.targets.length) { test.deliveries++; observer.callback(records, observer); }
            });
        }
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; this.records = []; this.targets = []; this.pending = false; test.observers.push(this); }
        observe(target, options) {
            this.targets = this.targets.filter(entry => entry.target !== target);
            this.targets.push({target, options});
        }
        disconnect() { this.targets = []; this.records = []; }
        takeRecords() { const records = this.records; this.records = []; return records; }
    }
    test.document = new Element(test, '#document');
    test.document.readyState = readyState;
    test.document.documentElement = new Element(test, 'html');
    test.document.body = new Element(test, 'body');
    test.document.appendChild(test.document.documentElement);
    test.document.documentElement.appendChild(test.document.body);
    test.document.createElement = tag => new Element(test, tag);
    test.window = new Events();
    test.window.document = test.document;
    test.window.location = new URL(url);
    test.window.MutationObserver = MutationObserver;
    const requestAnimationFrame = callback => { const id = ++test.nextID; test.rafs.set(id, callback); return id; };
    const cancelAnimationFrame = id => test.rafs.delete(id);
    const setTimeout = callback => { const id = ++test.nextID; test.timers.set(id, callback); return id; };
    const clearTimeout = id => test.timers.delete(id);
    Object.assign(test.window, {requestAnimationFrame, cancelAnimationFrame, setTimeout, clearTimeout});
    test.context = vm.createContext({window: test.window, document: test.document, MutationObserver, URL, console,
        requestAnimationFrame, cancelAnimationFrame, setTimeout, clearTimeout, queueMicrotask: callback => test.microtasks.push(callback)});
    test.run = () => vm.runInContext(source, test.context, {timeout: 1000, filename: 'meta-comments-navigation-overlay.js'});
    test.flush = () => {
        for (let round = 0; round < 32; round++) {
            if (!test.microtasks.length && !test.rafs.size && !test.timers.size) return;
            const microtasks = test.microtasks.splice(0);
            microtasks.forEach(callback => callback());
            const frames = [...test.rafs.values()]; test.rafs.clear();
            frames.forEach(callback => { test.frames++; callback(test.frames * 16); });
            const timers = [...test.timers.values()]; test.timers.clear();
            timers.forEach(callback => callback());
        }
        assert.fail('Sidebar patch did not settle: observer/frame loop continues after 32 turns');
    };
    test.fire = type => { test.document.dispatchEvent({type}); test.window.dispatchEvent({type}); };
    test.mounted = () => test.fire('dashboard:page-mounted');
    test.loaded = () => { test.document.readyState = 'complete'; test.fire('DOMContentLoaded'); test.flush(); };
    return test;
}

function fixture(url = 'https://dashboard.test/admin/meta/comments', readyState = 'loading') {
    const test = runtime(url, readyState);
    function element(parent, tag, classes = '') {
        const item = new Element(test, tag);
        if (classes) item.className = classes;
        parent.appendChild(item);
        return item;
    }
    function link(parent, href, active = false) {
        const item = element(parent, 'li', 'nav-item');
        const anchor = element(item, 'a', 'nav-link' + (active ? ' active' : ''));
        anchor.href = href;
        return {item, anchor};
    }
    const sidebar = element(test.document.body, 'aside', 'main-sidebar');
    const nav = element(sidebar, 'ul', 'nav-sidebar');
    const firstGroup = element(nav, 'li', 'nav-item');
    const primary = element(firstGroup, 'ul', 'nav-treeview');
    const whatsapp = link(primary, '/admin/whatsapp');
    const meta = link(primary, '/admin/meta/', true);
    const home = link(primary, '/admin/home');
    const secondGroup = element(nav, 'li', 'nav-item');
    const secondary = element(secondGroup, 'ul', 'nav-treeview');
    const orders = link(secondary, '/admin/orders', true);
    const comments = link(secondary, '/admin/meta/comments?section=posts');
    const content = element(test.document.body, 'main');
    const outsideMeta = link(content, '/admin/meta', true);
    const outsideComments = link(content, '/admin/meta/comments', true);
    // Similar sidebar markup outside the actual main sidebar is not menu scope.
    const preview = element(content, 'ul', 'nav-sidebar');
    const previewMeta = link(preview, '/admin/meta', true);
    const previewComments = link(preview, '/admin/meta/comments', true);
    Object.assign(test, {sidebar, nav, primary, secondary, whatsapp, meta, home, orders, comments,
        content, outsideMeta, outsideComments, preview, previewMeta, previewComments});
    test.writes = 0;
    return test;
}

function assertPlaced(test) {
    assert.strictEqual(test.comments.item.parentElement, test.primary, 'Comments may move across nested ULs in the same nav sidebar');
    assert.strictEqual(test.meta.item.nextElementSibling, test.comments.item, 'Comments is directly after Meta messages');
}
function assertExclusiveComments(test) {
    assert(test.comments.anchor.classList.contains('active'), 'Comments route activates comments');
    assert(!test.meta.anchor.classList.contains('active'), 'Comments route removes the broad Meta route highlight');
    assert(test.orders.anchor.classList.contains('active'), 'Patch does not rewrite unrelated active classes');
}
function assertOutsideUnchanged(test) {
    assert.strictEqual(test.outsideMeta.item.parentElement, test.content);
    assert.strictEqual(test.outsideComments.item.parentElement, test.content);
    assert.strictEqual(test.previewMeta.item.parentElement, test.preview);
    assert.strictEqual(test.previewComments.item.parentElement, test.preview);
    for (const entry of [test.outsideMeta, test.outsideComments, test.previewMeta, test.previewComments]) {
        assert(entry.anchor.classList.contains('active'), 'Unrelated page links retain their classes');
    }
}
function listenerCounts(test) {
    return {
        document: Object.fromEntries([...test.document.listeners].map(([type, entries]) => [type, entries.length])),
        window: Object.fromEntries([...test.window.listeners].map(([type, entries]) => [type, entries.length])),
    };
}
function assertQuiet(test) {
    const writes = test.writes;
    const observers = test.observers.filter(observer => observer.targets.length).length;
    const constructedObservers = test.observers.length;
    const handlers = listenerCounts(test);
    for (let index = 0; index < 5; index++) { test.run(); test.mounted(); test.flush(); }
    assert.strictEqual(test.writes, writes, 'Already-correct adjacency and classes produce no DOM writes');
    assert.strictEqual(test.observers.filter(observer => observer.targets.length).length, observers, 'Repeated mounts do not accumulate live observers');
    assert.strictEqual(test.observers.length, constructedObservers, 'Repeated script execution reuses the original observer');
    assert.deepStrictEqual(listenerCounts(test), handlers, 'Repeated script execution and mounts do not accumulate event handlers');
    assert(test.deliveries < 30 && test.frames < 30, 'Observer/frame delivery remains finite');
}

function scopedInitialPlacement() {
    const test = fixture();
    test.run();
    const handlers = listenerCounts(test);
    test.run(); test.run();
    assert.deepStrictEqual(listenerCounts(test), handlers, 'Repeated execution before DOM readiness keeps one lifecycle handler set');
    test.loaded();
    assertPlaced(test); assertExclusiveComments(test); assertOutsideUnchanged(test); assertQuiet(test);
}

function delayedNativeRegrouping() {
    const test = fixture();
    test.run(); test.loaded();
    assert(test.observers.some(observer => observer.targets.length), 'A live observer must repair delayed native sidebar regrouping');
    // Native navigation can regroup the item after the DOMContentLoaded handler.
    test.window.setTimeout(() => test.secondary.appendChild(test.comments.item), 50);
    test.flush();
    assertPlaced(test);
    // Native highlighting is also asynchronous and uses the broad Meta prefix.
    test.window.setTimeout(() => {
        test.meta.anchor.classList.add('active');
        test.comments.anchor.classList.remove('active');
    }, 50);
    test.flush();
    assertExclusiveComments(test); assertOutsideUnchanged(test); assertQuiet(test);
}

function unrelatedRouteKeepsNativeHighlight() {
    const test = fixture('https://dashboard.test/admin/orders', 'complete');
    test.run(); test.flush();
    assertPlaced(test);
    assert(test.meta.anchor.classList.contains('active'), 'Unrelated route does not override native Meta active state');
    assert(!test.comments.anchor.classList.contains('active'), 'Unrelated route does not activate comments');
    assertOutsideUnchanged(test); assertQuiet(test);
    // SPA route changes do not mutate sidebar DOM; the mount event must update it.
    test.window.location = new URL('https://dashboard.test/admin/meta/comments/');
    test.mounted(); test.flush();
    assertPlaced(test); assertExclusiveComments(test); assertQuiet(test);
}

for (const test of [scopedInitialPlacement, delayedNativeRegrouping, unrelatedRouteKeepsNativeHighlight]) test();
console.log('Meta comments sidebar navigation: 3 scenarios passed');
