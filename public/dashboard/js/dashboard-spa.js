(function () {
    'use strict';
    if (window.DashboardSPA) return;

    // The Laravel routes remain the authority for permissions and mutations. This
    // controller keeps their existing HTML responses inside one persistent shell.
    var native = {
        add: EventTarget.prototype.addEventListener,
        remove: EventTarget.prototype.removeEventListener,
        timeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window),
        interval: window.setInterval.bind(window), clearInterval: window.clearInterval.bind(window),
        raf: window.requestAnimationFrame.bind(window), cancelRaf: window.cancelAnimationFrame.bind(window),
        fetch: window.fetch.bind(window), submit: HTMLFormElement.prototype.submit,
        observer: window.MutationObserver, resize: window.ResizeObserver, reader: window.FileReader,
        objectURL: URL.createObjectURL.bind(URL), revokeURL: URL.revokeObjectURL.bind(URL)
    };
    var scopeCount = 0;
    var active = null;
    var page = new PageScope();
    var started = false;
    var navigation = null;
    var sequence = 0;
    var submitting = false;
    var loadedScripts = new Map();
    var pageStyles = [];
    var historyKey = 0;
    var historyPosition = 0;
    var committedUrl = location.href;
    var committedState = null;
    var restoringHistory = false;
    var status;
    var pendingHistory = null;
    var currentDialog = null;

    function currentScope() {
        if (active) return active;
        var script = document.currentScript;
        return script && (script.hasAttribute('data-dashboard-page-init') || script.hasAttribute('data-dashboard-page-owned')
            || script.closest('[data-dashboard-page], [data-dashboard-page-scripts]')) ? script.dashboardPageScope || page : null;
    }
    function PageScope() {
        this.id = ++scopeCount;
        this.closed = false;
        this.listeners = [];
        this.timers = new Map();
        this.frames = new Set();
        this.cleanups = [];
        this.globals = new Map();
        this.leaveGuards = [];
    }
    PageScope.prototype.run = function (callback, context, args) {
        if (this.closed) return;
        var previous = active;
        active = this;
        attachMaps();
        attachDialogs();
        try { return callback.apply(context, args || []); } finally { active = previous; }
    };
    PageScope.prototype.bind = function (callback) {
        var scope = this;
        if (typeof callback !== 'function') return callback;
        return function () { return scope.run(callback, this, arguments); };
    };
    PageScope.prototype.export = function (name, callback) {
        if (typeof callback !== 'function') return;
        if (!this.globals.has(name)) this.globals.set(name, { previous: Object.getOwnPropertyDescriptor(window, name), assigned: null });
        var wrapped = this.bind(callback);
        this.globals.get(name).assigned = wrapped;
        window[name] = wrapped;
    };
    PageScope.prototype.dispose = function () {
        if (this.closed) return;
        this.closed = true;
        this.listeners.forEach(function (record) { native.remove.call(record.target, record.type, record.wrapped, record.options); });
        this.timers.forEach(function (kind, id) { (kind === 'interval' ? native.clearInterval : native.clearTimeout)(id); });
        this.frames.forEach(native.cancelRaf);
        this.cleanups.reverse().forEach(function (cleanup) { try { cleanup(); } catch (error) { console.warn('Dashboard page cleanup failed', error); } });
        this.globals.forEach(function (record, name) {
            if (window[name] !== record.assigned) return;
            if (record.previous) Object.defineProperty(window, name, record.previous);
            else { try { if (!delete window[name]) window[name] = undefined; } catch (_) { window[name] = undefined; } }
        });
        this.listeners = [];
        this.timers.clear();
        this.frames.clear();
        this.cleanups = [];
        this.globals.clear();
        this.leaveGuards = [];
    };

    EventTarget.prototype.addEventListener = function (type, callback, options) {
        var scope = currentScope();
        if (!scope || !callback) return native.add.call(this, type, callback, options);
        var target = this;
        var listener = typeof callback === 'function' ? callback : function (event) { return callback.handleEvent(event); };
        var wrapped = scope.bind(listener);
        // Legacy Blade page scripts expect this browser event even on later visits.
        if (started && ((target === document && type === 'DOMContentLoaded') || (target === window && type === 'load'))) {
            native.timeout(function () { scope.run(listener, target, [new Event(type)]); }, 0);
            return;
        }
        var capture = typeof options === 'boolean' ? options : !!(options && options.capture);
        var duplicate = scope.listeners.find(function (record) { return record.target === target && record.type === type && record.callback === callback && record.capture === capture; });
        if (duplicate) return;
        scope.listeners.push({ target: target, type: type, callback: callback, wrapped: wrapped, options: options, capture: capture });
        return native.add.call(target, type, wrapped, options);
    };
    EventTarget.prototype.removeEventListener = function (type, callback, options) {
        var capture = typeof options === 'boolean' ? options : !!(options && options.capture);
        [active, page].filter(Boolean).some(function (scope) {
            var record = scope.listeners.find(function (item) { return item.target === this && item.type === type && item.callback === callback && item.capture === capture; }, this);
            if (!record) return false;
            callback = record.wrapped;
            scope.listeners.splice(scope.listeners.indexOf(record), 1);
            return true;
        }, this);
        return native.remove.call(this, type, callback, options);
    };
    function timer(kind, callback, delay, args) {
        var scope = currentScope();
        if (!scope || typeof callback !== 'function') return native[kind].apply(null, [callback, delay].concat(args));
        var id = native[kind](function () {
            if (kind === 'timeout') scope.timers.delete(id);
            scope.run(callback, window, args);
        }, delay);
        scope.timers.set(id, kind);
        return id;
    }
    window.setTimeout = function (callback, delay) { return timer('timeout', callback, delay, Array.prototype.slice.call(arguments, 2)); };
    window.setInterval = function (callback, delay) { return timer('interval', callback, delay, Array.prototype.slice.call(arguments, 2)); };
    window.requestAnimationFrame = function (callback) {
        var scope = currentScope();
        if (!scope) return native.raf(callback);
        var id = native.raf(function (time) { scope.frames.delete(id); scope.run(callback, window, [time]); });
        scope.frames.add(id);
        return id;
    };
    ['MutationObserver', 'ResizeObserver'].forEach(function (name) {
        var Original = name === 'MutationObserver' ? native.observer : native.resize;
        if (!Original) return;
        window[name] = function (callback) {
            var scope = currentScope();
            var observer = new Original(scope ? scope.bind(callback) : callback);
            if (scope) scope.cleanups.push(function () { observer.disconnect(); });
            return observer;
        };
        window[name].prototype = Original.prototype;
    });
    function inlineEventScope() {
        var target = window.event && window.event.target;
        return !page.closed && target instanceof Element && target.closest('[data-dashboard-page]') ? page : null;
    }
    window.FileReader = function () {
        var reader = new native.reader();
        var scope = currentScope() || inlineEventScope();
        if (scope) {
            ['load', 'loadend', 'error', 'abort', 'progress', 'loadstart'].forEach(function (name) {
                var property = 'on' + name;
                var descriptor = Object.getOwnPropertyDescriptor(native.reader.prototype, property);
                if (!descriptor || !descriptor.set || !descriptor.get) return;
                Object.defineProperty(reader, property, {
                    configurable: true,
                    get: function () { return descriptor.get.call(reader); },
                    set: function (callback) { descriptor.set.call(reader, typeof callback === 'function' ? scope.bind(callback) : callback); }
                });
            });
            scope.cleanups.push(function () {
                ['onload', 'onloadend', 'onerror', 'onabort', 'onprogress', 'onloadstart'].forEach(function (name) { reader[name] = null; });
                if (reader.readyState === native.reader.LOADING) reader.abort();
            });
        }
        return reader;
    };
    window.FileReader.prototype = native.reader.prototype;
    Object.setPrototypeOf(window.FileReader, native.reader);
    URL.createObjectURL = function (object) {
        var value = native.objectURL(object);
        var scope = currentScope() || inlineEventScope();
        if (scope) scope.cleanups.push(function () { native.revokeURL(value); });
        return value;
    };

    window.fetch = function (input, options) {
        var scope = currentScope();
        if (!scope) return native.fetch(input, options);
        var method = options && options.method || input && input.method || 'GET';
        var controller = new AbortController();
        var supplied = options && options.signal || input && input.signal;
        var abort = function () { controller.abort(); };
        if (supplied) {
            if (supplied.aborted) controller.abort();
            else native.add.call(supplied, 'abort', abort, { once: true });
        }
        var safeToAbort = /^(GET|HEAD)$/i.test(method);
        var cleanup = function () { if (safeToAbort) controller.abort(); };
        scope.cleanups.push(cleanup);
        return native.fetch(input, Object.assign({}, options || {}, { signal: controller.signal })).then(function (response) {
            if (scope.closed) throw new DOMException('The dashboard page has changed', 'AbortError');
            return response;
        }).finally(function () {
            if (supplied) native.remove.call(supplied, 'abort', abort);
            var index = scope.cleanups.indexOf(cleanup);
            if (index >= 0) scope.cleanups.splice(index, 1);
        });
    };

    function attachJQuery() {
        var $ = window.jQuery;
        if (!$ || $.dashboardSPA) return;
        $.dashboardSPA = true;
        var ready = $.fn.ready;
        $.fn.ready = function (callback) {
            var scope = currentScope();
            return ready.call(this, scope ? scope.bind(callback) : callback);
        };
        var add = $.event.add;
        $.event.add = function (target, types, handler, data, selector) {
            var scope = currentScope();
            if (!scope) return add.apply(this, arguments);
            var object = handler && handler.handler ? handler : null;
            var original = object ? object.handler : handler;
            if (typeof original !== 'function') return add.apply(this, arguments);
            var wrapped = scope.bind(original);
            original.guid = original.guid || $.guid++;
            wrapped.guid = original.guid;
            var registration = object ? Object.assign({}, object, { handler: wrapped }) : wrapped;
            scope.cleanups.push(function () { $.event.remove(target, types, wrapped, selector); });
            return add.call(this, target, types, registration, data, selector);
        };
        var ajax = $.ajax;
        $.ajax = function (url, options) {
            var scope = currentScope();
            if (!scope) return ajax.apply(this, arguments);
            var settings = typeof url === 'object' ? Object.assign({}, url) : Object.assign({}, options || {}, { url: url });
            ['success', 'error', 'complete', 'beforeSend'].forEach(function (key) {
                if (Array.isArray(settings[key])) settings[key] = settings[key].map(function (callback) { return scope.bind(callback); });
                else if (typeof settings[key] === 'function') settings[key] = scope.bind(settings[key]);
            });
            var xhr = ajax.call(this, settings);
            ['done', 'fail', 'always', 'then'].forEach(function (name) {
                var original = xhr[name];
                if (!original) return;
                xhr[name] = function () {
                    var callbacks = Array.from(arguments).map(function bind(value) {
                        return Array.isArray(value) ? value.map(bind) : typeof value === 'function' ? scope.bind(value) : value;
                    });
                    return original.apply(this, callbacks);
                };
            });
            if (/^(GET|HEAD)$/i.test(settings.method || settings.type || 'GET')) scope.cleanups.push(function () { xhr.abort(); });
            return xhr;
        };
    }

    function scopedPromise(promise, scope) {
        if (!promise || typeof promise.then !== 'function' || promise.dashboardPagePromise === scope) return promise;
        Object.defineProperty(promise, 'dashboardPagePromise', { value: scope, configurable: true });
        ['then', 'catch', 'finally'].forEach(function (name) {
            var original = promise[name];
            if (typeof original !== 'function') return;
            promise[name] = function () {
                var callbacks = Array.from(arguments).map(function (callback) { return typeof callback === 'function' ? scope.bind(callback) : callback; });
                return scopedPromise(original.apply(this, callbacks), scope);
            };
        });
        return promise;
    }
    function attachDialogs() {
        var original = window.swal;
        if (typeof original !== 'function' || original.dashboardSPA) return;
        function dialog() {
            var scope = currentScope();
            var promise = original.apply(this, arguments);
            var owner = { scope: scope };
            currentDialog = owner;
            if (!scope || !promise || typeof promise.then !== 'function') return promise;
            promise.then(function () { if (currentDialog === owner) currentDialog = null; }, function () { if (currentDialog === owner) currentDialog = null; });
            scope.cleanups.push(function () {
                if (currentDialog !== owner) return;
                currentDialog = null;
                if (typeof original.close === 'function') original.close();
            });
            return scopedPromise(promise, scope);
        }
        Object.setPrototypeOf(dialog, original);
        dialog.dashboardSPA = true;
        window.swal = dialog;
    }

    function attachMaps() {
        if (!window.google || !window.google.maps || window.google.maps.dashboardSPA) return;
        var maps = window.google.maps;
        maps.dashboardSPA = true;
        var add = maps.event && maps.event.addListener;
        if (add) maps.event.addListener = function (instance, type, callback) {
            var scope = currentScope();
            var handle = add.call(this, instance, type, scope ? scope.bind(callback) : callback);
            if (scope) scope.cleanups.push(function () { maps.event.removeListener(handle); });
            return handle;
        };
        ['Map', 'Marker', 'Circle', 'InfoWindow'].forEach(function (name) {
            var Original = maps[name];
            if (!Original) return;
            function Constructor() {
                var object = Reflect.construct(Original, Array.from(arguments));
                var scope = currentScope();
                if (scope) scope.cleanups.push(function () {
                    if (maps.event) maps.event.clearInstanceListeners(object);
                    if (typeof object.setMap === 'function') object.setMap(null);
                    if (name === 'InfoWindow' && typeof object.close === 'function') object.close();
                });
                return object;
            }
            Constructor.prototype = Original.prototype;
            Object.setPrototypeOf(Constructor, Original);
            maps[name] = Constructor;
        });
    }

    function eligible(value) {
        var url;
        try { url = new URL(value, location.href); } catch (_) { return false; }
        return url.origin === location.origin && /^\/admin(?:\/|$)/.test(url.pathname)
            && !/\/(?:logout|login|signin|signout|choose_type|download[^/]*|export[^/]*|print[^/]*|payment)(?:\/|$)/i.test(url.pathname)
            && !/\.(?:pdf|csv|xlsx?|zip|png|jpe?g|webp|svg)$/i.test(url.pathname);
    }
    function copyAttributes(source, target, names) {
        names.forEach(function (name) { if (source.hasAttribute(name)) target.setAttribute(name, source.getAttribute(name)); else target.removeAttribute(name); });
    }
    function notify(message, retry, severity) {
        if (!status) return;
        if (['success', 'warning', 'error'].indexOf(severity) !== -1) status.dataset.severity = severity;
        else delete status.dataset.severity;
        status.textContent = '';
        var text = document.createElement('span'); text.textContent = message;
        status.appendChild(text);
        if (retry) {
            var button = document.createElement('button'); button.type = 'button'; button.textContent = document.documentElement.dir === 'rtl' ? 'إعادة المحاولة' : 'Retry';
            native.add.call(button, 'click', retry); status.appendChild(button);
        }
        status.hidden = !message;
    }
    function message(kind) {
        var arabic = document.documentElement.dir === 'rtl';
        var messages = {
            loading: arabic ? 'جارٍ فتح الصفحة…' : 'Opening page…',
            error: arabic ? 'تعذر فتح الصفحة. حاول مرة أخرى.' : 'The page could not be opened. Please try again.',
            saveError: arabic ? 'تعذر تأكيد نتيجة الحفظ. راجع البيانات قبل إعادة الإرسال.' : 'The save result could not be confirmed. Check the data before submitting again.',
            saved: arabic ? 'تم حفظ البيانات.' : 'Changes saved.',
            busy: arabic ? 'جارٍ حفظ البيانات…' : 'Saving changes…'
        };
        return messages[kind];
    }
    function busy(value) {
        document.body.classList.toggle('dashboard-spa-loading', value);
        var region = document.querySelector('[data-dashboard-page]');
        if (region) { region.setAttribute('aria-busy', String(value)); region.inert = value; }
        if (value) notify(message(submitting ? 'busy' : 'loading'));
    }
    function recordScroll() {
        var state = Object.assign({}, history.state || {}, { dashboardSPA: true, key: historyKey,
            dashboardPosition: historyPosition, scroll: [scrollX, scrollY] });
        history.replaceState(state, '', location.href);
        committedUrl = location.href; committedState = state;
    }
    function mayLeave(url, options) {
        // Pages such as a checkout own the decision to discard their local work.
        // Guards are synchronous: returning false keeps the current page intact.
        return page.leaveGuards.slice().every(function (callback) {
            try { return page.run(callback, window, [{ url: url, pop: !!options.pop,
                replace: !!options.replace, afterSubmit: !!options.afterSubmit }]) !== false; }
            catch (error) { console.warn('Dashboard leave guard failed', error); return false; }
        });
    }
    function restoreCommittedHistory() {
        var target = history.state && history.state.dashboardPosition;
        if (typeof target === 'number' && target !== historyPosition) {
            restoringHistory = true;
            history.go(historyPosition - target);
        } else {
            restoringHistory = false;
            history.replaceState(committedState, '', committedUrl);
        }
    }
    function pageCss(doc) {
        var nodes = [];
        var recording = false;
        Array.from(doc.head.childNodes).forEach(function (node) {
            if (node.nodeType === 8 && node.textContent.trim() === 'dashboard-page-css:start') recording = true;
            else if (node.nodeType === 8 && node.textContent.trim() === 'dashboard-page-css:end') recording = false;
            else if (recording && node.nodeType === 1 && node.matches('link[rel="stylesheet"],style')) nodes.push(node);
        });
        // Legacy map partials include Leaflet CSS beside their footer scripts.
        // Stage that CSS too: script-only navigation otherwise drops it, leaving
        // map tiles in normal document flow and covering the form/sidebar.
        doc.querySelectorAll('[data-dashboard-page-scripts] link[rel="stylesheet"], [data-dashboard-page-scripts] style').forEach(function (node) { nodes.push(node); });
        return nodes;
    }
    async function stageCss(doc, url) {
        var incomingNodes = [];
        var waits = pageCss(doc).map(function (node) {
            var incoming = document.importNode(node, true);
            incoming.setAttribute('data-dashboard-page-style', '');
            incoming.dashboardOriginalMedia = incoming.getAttribute('media');
            incoming.setAttribute('media', 'not all');
            if (incoming.href) incoming.href = new URL(node.getAttribute('href'), url).href;
            incomingNodes.push(incoming);
            var wait = incoming.tagName === 'LINK' ? new Promise(function (resolve) {
                native.add.call(incoming, 'load', resolve, { once: true });
                native.add.call(incoming, 'error', resolve, { once: true });
                native.timeout(resolve, 3000);
            }) : Promise.resolve();
            document.head.appendChild(incoming);
            return wait;
        });
        await Promise.all(waits);
        return incomingNodes;
    }
    function commitCss(nodes) {
        pageStyles.forEach(function (node) { node.remove(); });
        pageStyles = nodes;
        nodes.forEach(function (node) {
            if (node.dashboardOriginalMedia === null) node.removeAttribute('media');
            else node.setAttribute('media', node.dashboardOriginalMedia);
        });
    }
    function inlineNames(code) {
        var names = new Set();
        var expression = /\bfunction\s+([A-Za-z_$][\w$]*)\s*\(/g;
        var match;
        while ((match = expression.exec(code))) names.add(match[1]);
        return Array.from(names);
    }
    function pageScript(script) {
        var type = (script.getAttribute('type') || '').toLowerCase();
        return !type || /^(?:text|application)\/javascript$/.test(type) || type === 'module';
    }
    function assetKey(value) {
        var url = new URL(value, location.href);
        // Google Maps varies its per-page callback. The SDK itself loads once.
        if (url.hostname === 'maps.googleapis.com' && url.pathname === '/maps/api/js') url.searchParams.delete('callback');
        return url.href;
    }
    function mapsCallback(url, scope) {
        var parsed = new URL(url, location.href);
        if (parsed.hostname !== 'maps.googleapis.com' || parsed.pathname !== '/maps/api/js') return;
        var callback = parsed.searchParams.get('callback');
        if (callback && typeof window[callback] === 'function') scope.run(window[callback], window, []);
    }
    async function execute(script, scope, url) {
        if (!pageScript(script)) return;
        if (script.src || script.hasAttribute('src')) {
            var source = new URL(script.getAttribute('src'), url).href;
            var key = assetKey(source);
            var parsed = new URL(source, url);
            var isMaps = parsed.hostname === 'maps.googleapis.com' && parsed.pathname === '/maps/api/js';
            var callbackName = isMaps && parsed.searchParams.get('callback');
            var perPage = /\/dashboard\/js\//.test(source);
            if (!perPage && loadedScripts.has(key)) {
                await loadedScripts.get(key);
                mapsCallback(source, scope);
                return;
            }
            if (isMaps && callbackName) {
                var scopedName = 'dashboardMapsReady' + scope.id;
                scope.export(scopedName, function () { mapsCallback(new URL(script.getAttribute('src'), url).href, scope); });
                parsed.searchParams.set('callback', scopedName);
                source = parsed.href;
            }
            var wait = new Promise(function (resolve, reject) {
                var element = document.createElement('script');
                copyAttributes(script, element, ['type', 'integrity', 'crossorigin', 'referrerpolicy', 'nonce']);
                element.src = source; element.async = false;
                if (perPage) { element.setAttribute('data-dashboard-page-owned', ''); element.dashboardPageScope = scope; }
                native.add.call(element, 'load', function () { resolve(); });
                native.add.call(element, 'error', function () { reject(new Error('Dashboard dependency could not load: ' + source)); });
                document.body.appendChild(element);
                if (perPage) scope.cleanups.push(function () { element.remove(); resolve(); });
            });
            if (!perPage) loadedScripts.set(key, wait);
            try { await wait; } catch (error) { if (!perPage) loadedScripts.delete(key); throw error; }
            return;
        }
        var source = script.textContent;
        var exports = inlineNames(source).map(function (name) { return 'if(typeof ' + name + ' === "function" && dashboardSourceText.indexOf(Function.prototype.toString.call(' + name + ')) >= 0) scope.export(' + JSON.stringify(name) + ',' + name + ');'; }).join('\n');
        // Isolate Blade const/let declarations on every visit, while retaining
        // named callbacks used by existing inline buttons and the Maps SDK.
        scope.run(new Function('scope', 'dashboardSourceText', source + '\n' + exports + '\n//# sourceURL=dashboard-page-inline.js'), window, [scope, source]);
    }
    function destroyWidgets(region) {
        var $ = window.jQuery;
        if ($) {
            if ($.fn.dataTable && $.fn.dataTable.isDataTable) $(region).find('table').each(function () {
                if ($.fn.dataTable.isDataTable(this)) $(this).DataTable().destroy();
            });
            if ($.fn.jstree) $(region).find('.jstree').each(function () { var tree = $(this).jstree(true); if (tree) tree.destroy(); });
            $(region).find('select.select2-hidden-accessible').each(function () { try { $(this).select2('destroy'); } catch (_) {} });
            $(region).find('select,input').each(function () { if (this.selectize) this.selectize.destroy(); if (this._flatpickr) this._flatpickr.destroy(); });
            $(region).find('.modal').each(function () { try { $(this).modal('hide').modal('dispose'); } catch (_) {} });
            $(region).find('.note-editor').each(function () { var textarea = $(this).prev(); try { textarea.summernote('destroy'); } catch (_) {} });
            $(region).find('*').addBack().off();
        }
        if (window.CKEDITOR) Object.keys(window.CKEDITOR.instances).forEach(function (key) {
            var instance = window.CKEDITOR.instances[key];
            if (instance.element && region.contains(instance.element.$)) instance.destroy(true);
        });
        if (window.Chart && window.Chart.instances) Object.keys(window.Chart.instances).forEach(function (key) {
            var chart = window.Chart.instances[key];
            if (chart.canvas && region.contains(chart.canvas)) chart.destroy();
        });
        document.querySelectorAll('.modal-backdrop').forEach(function (element) { element.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
    }
    function activeNavigation(url) {
        var current = new URL(url, location.href);
        var links = document.querySelectorAll('[data-dashboard-navigation] a[href], .dashboard-navigation-popup a[href]');
        links.forEach(function (link) {
            var destination = new URL(link.href, location.href);
            var isPage = destination.origin === current.origin && destination.pathname !== '/admin'
                && destination.pathname !== '#' && (destination.pathname === current.pathname || current.pathname.startsWith(destination.pathname.replace(/\/$/, '') + '/'));
            if (isPage && destination.search) {
                var previouslyActive = link.classList.contains('active');
                destination.searchParams.forEach(function (value, key) {
                    if (current.searchParams.get(key) === value) return;
                    if (!current.searchParams.has(key) && current.pathname !== destination.pathname && previouslyActive) return;
                    isPage = false;
                });
            }
            if (link.getAttribute('href').startsWith('#')) isPage = false;
            link.classList.toggle('active', isPage);
            if (isPage) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        document.querySelectorAll('[data-dashboard-navigation] li.nav-item').forEach(function (item) {
            var link = item.querySelector(':scope > .nav-link');
            var child = item.querySelector(':scope > .nav-treeview');
            if (link && child) link.classList.toggle('active', !!child.querySelector('.nav-link.active'));
        });
    }
    async function render(doc, url, options, current) {
        var incoming = doc.querySelector('[data-dashboard-page]');
        var outgoing = document.querySelector('[data-dashboard-page]');
        if (!incoming || !outgoing || !doc.querySelector('.main-header')) throw new Error('Response is not an authenticated dashboard page');
        // Stage slow styles while the current page and its handlers stay alive.
        // A failed newer navigation must leave that page fully usable.
        var styles = await stageCss(doc, url);
        if (current !== sequence) { styles.forEach(function (node) { node.remove(); }); return; }
        if (!options.pop) {
            historyKey++;
            if (!options.replace) historyPosition++;
            history[options.replace ? 'replaceState' : 'pushState']({ dashboardSPA: true, key: historyKey,
                dashboardPosition: historyPosition, scroll: options.scroll || [0, 0] }, '', url);
        } else if (history.state && typeof history.state.dashboardPosition === 'number') {
            historyPosition = history.state.dashboardPosition;
        }
        committedUrl = url; committedState = history.state;
        document.dispatchEvent(new CustomEvent('dashboard:before-unload', { detail: { url: url } }));
        document.dispatchEvent(new CustomEvent('dashboard:before-navigate', { detail: { url: url } }));
        page.dispose();
        destroyWidgets(outgoing);
        commitCss(styles);
        page = new PageScope();
        var scope = page;
        document.title = doc.title || document.title;
        var token = doc.querySelector('meta[name="csrf-token"]');
        if (token) document.querySelector('meta[name="csrf-token"]').content = token.content;
        ['dashboard-home-page', 'app-order-board-page', 'dashboard-takeaway-page', 'dashboard-dining-page', 'dashboard-phone-orders-page'].forEach(function (name) { document.body.classList.toggle(name, doc.body.classList.contains(name)); });
        var scripts = Array.from(incoming.querySelectorAll('script'));
        var extra = doc.querySelector('[data-dashboard-page-scripts]');
        if (extra) scripts = scripts.concat(Array.from(extra.querySelectorAll('script')));
        scripts = scripts.concat(Array.from(doc.querySelectorAll('script[data-dashboard-page-init]')));
        incoming.querySelectorAll('script').forEach(function (script) { script.remove(); });
        outgoing.replaceChildren.apply(outgoing, Array.from(incoming.childNodes).map(function (node) { return document.importNode(node, true); }));
        var oldExtra = document.querySelector('[data-dashboard-page-scripts]');
        if (oldExtra) oldExtra.remove();
        var container = document.createElement('div');
        container.hidden = true; container.setAttribute('data-dashboard-page-scripts', '');
        scripts.filter(function (script) { return !pageScript(script); }).forEach(function (script) { container.appendChild(document.importNode(script, true)); });
        document.body.appendChild(container);
        for (var index = 0; index < scripts.length; index++) {
            if (scope.closed || scope !== page) return;
            try { await execute(scripts[index], scope, url); } catch (error) { console.error('Dashboard page initialization failed', error); }
        }
        if (scope.closed || scope !== page) return;
        activeNavigation(url);
        if (innerWidth < 992) document.body.classList.remove('sidebar-open');
        document.dispatchEvent(new CustomEvent('dashboard:page-loaded', { detail: { url: url } }));
        document.dispatchEvent(new CustomEvent('dashboard:page-mounted', { detail: { url: url } }));
        window.dispatchEvent(new Event('resize'));
        var position = options.scroll || [0, 0];
        window.scrollTo(position[0], position[1]);
        if (current === sequence) outgoing.inert = false;
        if (!options.scroll && current === sequence) {
            var heading = outgoing.querySelector('h1, h2');
            if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
        }
        if (current === sequence) notify('');
    }
    function authRedirect(response) {
        if (!response.redirected) return false;
        var url = new URL(response.url, location.href);
        if (url.origin !== location.origin || !/^\/admin(?:\/|$)/.test(url.pathname) || /\/(?:login|signin|logout|signout)(?:\/|$)/.test(url.pathname)) {
            window.location.assign(url.href); return true;
        }
        return false;
    }
    async function visit(value, options) {
        options = options || {};
        var url = new URL(value, location.href);
        if (!eligible(url.href)) { window.location.assign(url.href); return; }
        if (!mayLeave(url.href, options)) {
            if (options.pop) restoreCommittedHistory();
            return;
        }
        if (submitting && !options.afterSubmit) { if (options.pop) pendingHistory = { url: url.href, options: options }; return; }
        if (navigation) navigation.abort();
        var controller = new AbortController();
        navigation = controller;
        var current = ++sequence;
        if (!options.pop && !options.afterSubmit) recordScroll();
        busy(true);
        try {
            var response = options.response || await native.fetch(url.href, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'text/html', 'X-Dashboard-SPA': '1' } });
            if (current !== sequence) return;
            if (authRedirect(response)) return;
            var contentType = response.headers.get('content-type') || '';
            if (!/text\/html/i.test(contentType)) throw new Error('Dashboard response must be HTML');
            var html = await response.text();
            if (current !== sequence) return;
            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (!doc.querySelector('[data-dashboard-page]') || !doc.querySelector('.main-header')) throw new Error('Response is not an authenticated dashboard page');
            if (!response.ok && !doc.querySelector('[data-dashboard-page]')) throw new Error('Dashboard request failed');
            var destination = response.url || url.href;
            var actor = document.body.getAttribute('data-dashboard-actor');
            if (actor && doc.body.getAttribute('data-dashboard-actor') !== actor) { window.location.assign(destination); return; }
            // render commits the URL together with its staged styles and DOM.
            await render(doc, destination, options, current);
        } catch (error) {
            if (error.name === 'AbortError' || current !== sequence) return;
            console.error('Dashboard navigation failed', error);
            notify(message('error'), function () { visit(url.href, Object.assign({}, options, { response: undefined, replace: true })); });
        } finally {
            if (current === sequence) { navigation = null; busy(false); }
        }
    }
    function submitButtons(form) {
        return Array.from(form.querySelectorAll('button:not([type]),button[type="submit"],input[type="submit"]')).map(function (button) {
            return { element: button, disabled: button.disabled, html: button.tagName === 'BUTTON' ? button.innerHTML : null };
        });
    }
    function restoreButtons(buttons) {
        buttons.forEach(function (item) { item.element.disabled = item.disabled; if (item.html !== null) item.element.innerHTML = item.html; });
    }
    function formTarget(form, submitter) { return submitter && submitter.getAttribute('formtarget') || form.getAttribute('target') || ''; }
    async function submitForm(form, submitter) {
        if (submitting) return;
        var target = formTarget(form, submitter);
        var action = submitter && submitter.getAttribute('formaction') || form.getAttribute('action') || location.href;
        if (target && target !== '_self' || form.hasAttribute('data-spa-off') || !eligible(action)) { native.submit.call(form); return; }
        var buttons = form.dashboardSubmitButtons || submitButtons(form);
        var method = (submitter && submitter.getAttribute('formmethod') || form.getAttribute('method') || 'GET').toUpperCase();
        if (method === 'DIALOG') return;
        if (window.CKEDITOR) Object.keys(window.CKEDITOR.instances).forEach(function (key) { var instance = window.CKEDITOR.instances[key]; if (instance.element && form.contains(instance.element.$)) instance.updateElement(); });
        var data = new FormData(form);
        if (submitter && submitter.name) data.append(submitter.name, submitter.value);
        var url = new URL(action, location.href);
        if (method === 'GET') {
            url.search = '';
            data.forEach(function (value, name) { url.searchParams.append(name, value instanceof File ? value.name : value); });
            return visit(url.href).finally(function () { restoreButtons(buttons); });
        }
        submitting = true;
        if (navigation) navigation.abort();
        ++sequence;
        buttons.forEach(function (item) { item.element.disabled = true; });
        busy(true);
        try {
            var token = document.querySelector('meta[name="csrf-token"]');
            var response = await native.fetch(url.href, { method: method, credentials: 'same-origin', body: data,
                headers: { Accept: 'text/html, application/json;q=0.9', 'X-Dashboard-SPA': '1', 'X-CSRF-TOKEN': token ? token.content : '' } });
            if (authRedirect(response)) return;
            if (/application\/json/i.test(response.headers.get('content-type') || '')) {
                var payload = await response.json();
                if (!response.ok || payload.success === false || payload.error) {
                    var errors = payload.errors && Object.values(payload.errors).flat();
                    notify(errors && errors.length ? errors.join(' · ') : payload.message || payload.error || message('saveError'), null, 'error');
                    return;
                }
                var destination = payload.redirect && eligible(payload.redirect) ? payload.redirect : location.href;
                await visit(destination, { afterSubmit: true, replace: true });
                if (payload.success === true && typeof payload.message === 'string' && !form.isConnected) {
                    notify(payload.message, null, payload.severity === 'warning' ? 'warning' : 'success');
                }
            } else {
                await visit(response.url || location.href, { afterSubmit: true, response: response, replace: true });
            }
        } catch (error) {
            console.error('Dashboard form submission failed', error);
            notify(message('saveError'));
        } finally {
            submitting = false;
            buttons.forEach(function (item) {
                item.element.disabled = item.disabled;
                if (item.html !== null) item.element.innerHTML = item.html;
            });
            busy(false);
            if (pendingHistory) { var pending = pendingHistory; pendingHistory = null; history.replaceState(history.state, '', pending.url); visit(pending.url, pending.options); }
        }
    }
    HTMLFormElement.prototype.submit = function () {
        if (started && !this.isConnected && eligible(this.getAttribute('action') || location.href)) return;
        if (started && document.querySelector('[data-dashboard-page]') && eligible(this.getAttribute('action') || location.href)
            && !this.hasAttribute('data-spa-off') && (!this.target || this.target === '_self')) return submitForm(this, null);
        return native.submit.call(this);
    };
    function start() {
        if (!document.querySelector('[data-dashboard-page]')) return;
        started = true;
        attachJQuery();
        attachMaps();
        attachDialogs();
        // Adopt callbacks declared by the initial Blade page before later visits
        // use isolated function scopes. Late SDK callbacks become harmless on exit.
        document.querySelectorAll('[data-dashboard-page] script:not([src]), [data-dashboard-page-scripts] script:not([src])').forEach(function (script) {
            if (!pageScript(script)) return;
            inlineNames(script.textContent).forEach(function (name) {
                if (typeof window[name] !== 'function') return;
                var callback = window[name];
                if (script.textContent.indexOf(Function.prototype.toString.call(callback)) < 0) return;
                page.export(name, callback); page.globals.get(name).previous = undefined;
            });
        });
        document.documentElement.setAttribute('data-dashboard-spa', 'ready');
        pageStyles = pageCss(document);
        document.querySelectorAll('script[src]').forEach(function (script) {
            var key = assetKey(script.src);
            var mapsPending = script.src.indexOf('maps.googleapis.com/maps/api/js') >= 0 && (!window.google || !window.google.maps);
            loadedScripts.set(key, mapsPending ? new Promise(function (resolve, reject) {
                native.add.call(script, 'load', resolve, { once: true });
                native.add.call(script, 'error', function () { loadedScripts.delete(key); reject(new Error('Maps dependency did not load')); }, { once: true });
            }).catch(function () {}) : Promise.resolve());
        });
        status = document.createElement('div'); status.className = 'dashboard-spa-status'; status.hidden = true;
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        document.body.appendChild(status);
        historyKey = history.state && history.state.key || 0;
        historyPosition = history.state && history.state.dashboardPosition || 0;
        history.replaceState(Object.assign({}, history.state || {}, { dashboardSPA: true, key: historyKey,
            dashboardPosition: historyPosition, scroll: [scrollX, scrollY] }), '', location.href);
        committedUrl = location.href; committedState = history.state;
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        native.add.call(document, 'click', function (event) {
            var link = event.target.closest('a[href]');
            if (event.defaultPrevented || !link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                || link.hasAttribute('download') || link.hasAttribute('data-spa-off') || link.target && link.target !== '_self'
                || link.getAttribute('href').startsWith('#') || !eligible(link.href) || link.matches('[data-board-page],[data-board-reset]')) return;
            event.preventDefault();
            visit(link.href);
        });
        native.add.call(document, 'submit', function (event) { if (event.target instanceof HTMLFormElement) event.target.dashboardSubmitButtons = submitButtons(event.target); }, true);
        native.add.call(document, 'submit', function (event) {
            var form = event.target;
            var submitter = event.submitter;
            var target = formTarget(form, submitter);
            var action = submitter && submitter.getAttribute('formaction') || form.getAttribute('action') || location.href;
            if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.hasAttribute('data-spa-off')
                || target && target !== '_self' || !eligible(action)) return;
            event.preventDefault();
            submitForm(form, submitter);
        });
        native.add.call(window, 'popstate', function (event) {
            if (restoringHistory) {
                if (event.state && event.state.dashboardPosition === historyPosition) restoringHistory = false;
                else restoreCommittedHistory();
                return;
            }
            if (!eligible(location.href)) return;
            visit(location.href, { pop: true, scroll: event.state && event.state.scroll || [0, 0] });
        });
        native.add.call(window, 'pagehide', function () { page.dispose(); });
        native.add.call(window, 'pageshow', function (event) { if (event.persisted) visit(location.href, { replace: true, scroll: [scrollX, scrollY] }); });
    }
    window.DashboardSPA = {
        visit: visit,
        reload: function () { return visit(location.href, { replace: true }); },
        attachJQuery: attachJQuery,
        onCleanup: function (callback) { var scope = currentScope() || page; if (typeof callback === 'function') scope.cleanups.push(callback); },
        onBeforeLeave: function (callback) {
            var scope = currentScope() || page;
            if (typeof callback !== 'function' || scope.closed) return function () {};
            scope.leaveGuards.push(callback);
            var remove = function () {
                var index = scope.leaveGuards.indexOf(callback);
                if (index !== -1) scope.leaveGuards.splice(index, 1);
            };
            scope.cleanups.push(remove);
            return remove;
        },
        ready: function () { return started; },
        isCurrentPage: function () { var scope = currentScope(); return !scope || scope === page && !scope.closed; }
    };
    native.add.call(document, 'DOMContentLoaded', start, { once: true });
}());
