(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('go-hud');
    var source = document.getElementById('home-overview-bootstrap') || document.getElementById('branch-home-bootstrap');
    if (!root || !source) return;
    var boot; try { boot = JSON.parse(source.textContent); } catch (_) { return; }
    var disposed = false, controller = null, timer = 0, timeout = 0, renderTimer = 0, removers = [], cardsByKey = new Map();
    var right = root.querySelector('[data-hud-right]'), left = root.querySelector('[data-hud-left]');
    var freshness = root.querySelector('[data-hud-freshness]'), live = root.querySelector('.hud-live'), voiceButton = root.querySelector('[data-hud-voice]');
    var enter = root.querySelector('[data-hud-enter]'), announcement = root.querySelector('[data-hud-announcement]');
    var robotCanvas = root.querySelector('[data-hud-robot]');
    var robot = null;
    try { if (window.GoHologram) robot = window.GoHologram.mount(robotCanvas, {background: true, fullViewport: true, interactive: false}); } catch (_) { root.dataset.hudRenderer = 'unavailable'; }
    var metric = null, speech = null, speechQueued = false, pendingGreeting = false, greetingDone = false, greetingReady = true, greetingTimer = 0, muted = false, mouthFrame = 0;
    var greetingPoll = 0, greetingTimeout = 0, greetingController = null, greetingEpoch = 0, greetingDeadline = 0, greetingStorageKey = '', greetingNonce = '';
    var currentActor = document.body.dataset.dashboardActor || '';
    var bg = root.querySelector('[data-hud-background]'), bgContext = bg ? bg.getContext('2d') : null, bgFrame = 0, bgLast = 0;
    var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    function background(now) {
        bgFrame = 0; if (disposed || root.hidden || document.hidden || !bgContext) return;
        if (now - bgLast > 50 || !bgLast) {
            bgLast = now; var w = root.clientWidth, h = Math.max(root.clientHeight, root.scrollHeight), t = reduce && reduce.matches ? 0 : now / 1000;
            if (bg.width !== w || bg.height !== h) { bg.width = w; bg.height = h; }
            var c = bgContext; c.clearRect(0, 0, w, h); c.lineWidth = .7;
            for (var i = 0; i < 18; i++) {
                var fromLeft = i % 2 === 0, x0 = fromLeft ? -20 : w + 20, y0 = (i / 18) * h, y1 = h * .54 + Math.sin(t * .25 + i) * h * .03;
                var x1 = w * (fromLeft ? .36 : .64), x2 = w * (fromLeft ? .25 : .75), y2 = y0 + Math.sin(i * 2 + t * .14) * h * .16;
                c.strokeStyle = 'rgba(37,189,225,.15)'; c.beginPath(); c.moveTo(x0, y0); c.bezierCurveTo(x1, y2, x2, y1, w * .5, h * .7); c.stroke();
                var p = (t * .025 + i / 18) % 1, q = 1 - p;
                var x = q*q*q*x0 + 3*q*q*p*x1 + 3*q*p*p*x2 + p*p*p*w*.5, y = q*q*q*y0 + 3*q*q*p*y2 + 3*q*p*p*y1 + p*p*p*h*.7;
                c.fillStyle = i % 7 === 0 ? '#ffb63a' : '#55dff3'; c.globalAlpha = .45; c.beginPath(); c.arc(x, y, 1.4, 0, 6.283); c.fill(); c.globalAlpha = 1;
            }
        }
        if (!reduce || !reduce.matches) bgFrame = requestAnimationFrame(background);
    }
    function wakeBackground() { if (!bgFrame && !disposed && !document.hidden) { bgLast = 0; bgFrame = requestAnimationFrame(background); } }
    var paths = {
        'ch-app': 'M6 2h12v20H6z M9 6h6 M9 10h6 M11 18h2',
        'ch-takeaway': 'M4 7h16l1 15H3z M8 8V5a4 4 0 0 1 8 0v3',
        'ch-phone': 'M3 18a3 3 0 1 0 6 0 3 3 0 0 0-6 0 M16 18a3 3 0 1 0 6 0 3 3 0 0 0-6 0 M6 18l3-8h7l3 8 M9 10l-1-4H4 M15 5h4l-3 5 M9 18h7 M1 11h4 M1 14h3',
        'ch-dine': 'M5 3v6 M2 3v6 M2 9h6 M5 9v13 M15 3v10h5V3 M18 13v9',
        'employees': 'M8 8a4 4 0 1 0 8 0 4 4 0 0 0-8 0 M4 22v-3a8 8 0 0 1 16 0v3Z M3 9a3 3 0 0 0 0 6 M21 9a3 3 0 0 1 0 6',
        'customers': 'M8 6a4 4 0 1 0 8 0 4 4 0 0 0-8 0 M3 22v-2a9 9 0 0 1 18 0v2Z',
        'expenses': 'M2 6c0-4 15-4 15 0s-15 4-15 0 M2 6v10c0 4 15 4 15 0V6 M2 11c0 4 15 4 15 0 M7 18v3c0 3 15 3 15-1v-8',
        'pending-expenses': 'M4 2h11l5 5v6 M4 2v20h8 M14 2v6h6 M7 11h7 M7 15h4 M15 18a4 4 0 1 0 8 0 4 4 0 0 0-8 0 M19 15v3h2',
        'drawer': 'M4 2h11l5 5v6 M4 2v20h8 M14 2v6h6 M7 11h7 M7 15h4 M15 18a4 4 0 1 0 8 0 4 4 0 0 0-8 0 M19 15v3h2',
        'stock': 'm12 1 6 3v7l-6 3-6-3V4z M6 4l6 3 6-3 M12 7v7 M6 11l-6 3v7l6 3 6-3v-7 M0 14l6 3 6-3 M6 17v7 M18 11l6 3v7l-6 3-6-3 M18 17v7 M12 14l6 3 6-3'
    };
    var columns = {right: ['ch-app', 'ch-takeaway', 'ch-phone', 'ch-dine', 'pending-expenses'], left: ['employees', 'customers', 'expenses', 'drawer', 'stock']};
    var number = new Intl.NumberFormat('en-GB');
    var balance = null, balanceTimer = 0, balanceController = null, balanceTimeout = 0, balanceSequence = 0, balanceDenied = false, balanceObserver = null;
    var overviewBalanceLoading = false, overviewBalanceSince = 0;
    function on(target, name, fn, options) { target.addEventListener(name, fn, options); removers.push(function () { target.removeEventListener(name, fn, options); }); }
    function el(tag, text, cls) { var n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
    function fmt(n) { return typeof n === 'number' && isFinite(n) && n >= 0 ? number.format(n) : '—'; }
    function safeURL(address) { if (typeof address !== 'string' || !address.trim()) return null; try { var u = new URL(address, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : null; } catch (_) { return null; } }
    function icon(key) { var n = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), p = document.createElementNS(n.namespaceURI, 'path'); n.setAttribute('viewBox', '0 0 24 24'); n.setAttribute('aria-hidden', 'true'); n.setAttribute('class', 'hud-card-icon'); p.setAttribute('d', paths[key] || paths['ch-app']); n.appendChild(p); return n; }
    function value(n, label, cls) { var part = el('span', undefined, cls); part.appendChild(el('b', fmt(n))); part.appendChild(document.createTextNode(label || '')); return part; }
    function sameKeys(keys, expected) {
        return Array.isArray(keys) && keys.length === expected.length && keys.every(function (key) { return typeof key === 'string'; }) && JSON.stringify(keys.slice().sort()) === JSON.stringify(expected);
    }
    function balanceActive() { return !disposed && !root.hidden && !document.hidden && !balanceDenied && (document.body.dataset.dashboardActor || '') === currentActor; }
    function moneyCents(cents) { var digits = String(Math.abs(cents)); return (cents < 0 ? '-' : '') + number.format(Number(digits.length > 2 ? digits.slice(0, -2) : '0')) + '.' + ('0' + digits).slice(-2); }
    function cancelBalance(clear) {
        clearTimeout(balanceTimer); clearTimeout(balanceTimeout); balanceSequence++;
        if (balanceController) balanceController.abort(); balanceController = null;
        if (clear) { balance = null; overviewBalanceLoading = false; overviewBalanceSince = 0; }
    }
    function denyBalance() {
        balanceDenied = true; cancelBalance(true); stopSpeech(); clearTimeout(timer); clearTimeout(timeout);
        if (controller) controller.abort();
        cardsByKey.forEach(function (node) { node.remove(); }); cardsByKey.clear(); stale();
    }
    function syncBalance(data) {
        if (!data || data.key !== 'drawer' || data.action !== 'owner-balances' || data.value_type !== 'currency' || data.currency !== 'EGP' || data.balance_scope !== 'all') { if (balance) cancelBalance(true); return false; }
        var url = safeURL(data.balance_url), keys = data.balance_branches;
        if (!url || !Array.isArray(keys) || keys.some(function (key) { return typeof key !== 'string' || !key; }) || new Set(keys).size !== keys.length || balanceDenied) { cancelBalance(true); return false; }
        url = new URL(url); url.search = ''; url.hash = ''; url.searchParams.set('section', 'drawers'); keys = keys.slice().sort();
        var identity = JSON.stringify([currentActor, url.href, keys]);
        if (!balance || balance.identity !== identity) {
            cancelBalance(true); balance = {identity: identity, url: url.href, keys: keys, cents: null, updatedAt: '', acceptedAt: 0, stale: false};
            scheduleBalance(0);
        }
        return true;
    }
    function renderBalance(node, data) {
        var ready = balance && Number.isSafeInteger(balance.cents), waiting = !ready && !(balance && balance.stale), state = waiting ? 'loading' : balance && balance.stale ? 'stale' : 'ready';
        var amount = ready ? moneyCents(balance.cents) : '—', message = waiting ? 'جار تحميل الرصيد' : balance && balance.stale ? (ready ? 'تعذّر التحديث · آخر رصيد متاح' : 'تعذّر تحميل الرصيد') : '';
        var signature = ['currency', amount, message].join('|'), values = node.querySelector('.hud-card-values');
        node.dataset.hudOwnerBalances = 'true'; node.dataset.balanceState = state; node.classList.toggle('warn', !!(balance && balance.stale)); node.classList.toggle('unavailable', !ready && !waiting); node.setAttribute('aria-busy', String(waiting || !!balanceController));
        if (node.dataset.signature !== signature) {
            var primary = el('span', undefined, 'hud-primary'), amountNode = el('b', amount); amountNode.dir = 'ltr'; primary.append(amountNode, document.createTextNode('ج.م')); values.replaceChildren(primary);
            if (message) values.appendChild(el('span', message, 'hud-secondary'));
            node.dataset.signature = signature; node.classList.add('changing');
        }
        node.setAttribute('aria-label', data.label + ': ' + amount + ' جنيه مصري' + (message ? '، ' + message : ''));
    }
    function repaintBalance() {
        var node = cardsByKey.get('drawer'), data = metric && metric.cards.find(function (item) { return item && item.key === 'drawer'; });
        if (node && data && balance && node.dataset.hudOwnerBalances === 'true') renderBalance(node, data);
    }
    function scheduleBalance(delay) {
        clearTimeout(balanceTimer);
        if (balance && balanceActive()) balanceTimer = setTimeout(refreshBalance, delay === undefined ? Math.max(0, 45000 - (Date.now() - balance.acceptedAt)) : delay);
    }
    function acceptBalance(result, timestamp) {
        if (!balance || !balanceActive() || !result || !result.filters || result.filters.branch !== '') return false;
        var drawer = result.owner_drawer, stamp = timestamp || (drawer && drawer.updated_at);
        if (!drawer || drawer.loaded !== true || drawer.ready !== true || !drawer.branches || typeof drawer.branches !== 'object' || Array.isArray(drawer.branches)
            || !sameKeys(Object.keys(drawer.branches), balance.keys) || !Number.isSafeInteger(drawer.total_cents) || typeof stamp !== 'string' || !Number.isFinite(Date.parse(stamp))
            || balance.keys.some(function (key) { return !drawer.branches[key] || !Number.isSafeInteger(drawer.branches[key].expected_cents); })
            || (balance.updatedAt && Date.parse(stamp) < Date.parse(balance.updatedAt))) return false;
        var total = 0;
        for (var i = 0; i < balance.keys.length; i++) { total += drawer.branches[balance.keys[i]].expected_cents; if (!Number.isSafeInteger(total)) return false; }
        if (total !== drawer.total_cents) return false;
        var changed = balance.updatedAt !== stamp || balance.cents !== drawer.total_cents;
        balance.cents = drawer.total_cents; balance.updatedAt = stamp;
        // Repeated overview events may contain the same retained finance read.
        if (changed || !balance.acceptedAt || timestamp !== undefined) { balance.acceptedAt = Date.now(); balance.stale = false; }
        repaintBalance(); scheduleBalance(); return true;
    }
    async function refreshBalance() {
        balanceTimer = 0;
        if (!balance || !balanceActive() || balanceController) return;
        if (overviewBalanceLoading && Date.now() - overviewBalanceSince < 17000) { scheduleBalance(500); return; }
        var state = balance, beforeCents = state.cents, beforeStamp = state.updatedAt, current = ++balanceSequence, at = new AbortController(); balanceController = at; repaintBalance();
        balanceTimeout = setTimeout(function () { at.abort(); }, 15000);
        try {
            var response = await fetch(state.url, {credentials: 'same-origin', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store', signal: at.signal});
            if (!balanceActive() || balance !== state || current !== balanceSequence) return;
            if (response.status === 401 || response.status === 403 || response.redirected) { denyBalance(); return; }
            if (!response.ok) throw Error('balances');
            var result = await response.json();
            if (!balanceActive() || balance !== state || current !== balanceSequence) return;
            if (result.success && state.updatedAt && typeof result.updated_at === 'string'
                && (Date.parse(result.updated_at) < Date.parse(state.updatedAt)
                    || ((state.cents !== beforeCents || state.updatedAt !== beforeStamp) && Date.parse(result.updated_at) <= Date.parse(state.updatedAt)))) return;
            if (!result.success || !acceptBalance(result, result.updated_at)) throw Error('balances');
        } catch (_) { if (balanceActive() && balance === state && current === balanceSequence) { state.stale = true; repaintBalance(); } }
        finally {
            if (current === balanceSequence) { clearTimeout(balanceTimeout); balanceController = null; repaintBalance(); scheduleBalance(45000); }
        }
    }
    function onDrawerState(event) {
        var detail = event.detail;
        if (detail && detail.auth_refused) { denyBalance(); return; }
        if (!balance || !detail || !detail.filters || detail.filters.branch !== '' || !sameKeys(detail.branch_keys, balance.keys)) return;
        acceptBalance(detail);
        overviewBalanceLoading = detail.loading === true; if (overviewBalanceLoading) overviewBalanceSince = Date.now();
        else if (!balanceController) scheduleBalance();
    }
    function card(data) {
        var url = safeURL(data.url), node = el(url ? 'a' : 'div', undefined, 'hud-card'); if (url) node.href = url;
        node.dataset.hudKey = data.key; node.appendChild(icon(data.key)); var copy = el('span', undefined, 'hud-card-copy');
        copy.appendChild(el('span', data.label, 'hud-card-label')); copy.appendChild(el('span', undefined, 'hud-card-values')); node.appendChild(copy); return node;
    }
    function fill(next) {
        if (disposed || balanceDenied || !root.isConnected || !next || !Array.isArray(next.cards)) return;
        metric = next; var accepted = new Map(next.cards.filter(function (c) { return c && typeof c.key === 'string'; }).map(function (c) { return [c.key, c]; }));
        var ownerBalance = syncBalance(accepted.get('drawer'));
        if (balanceDenied && accepted.get('drawer') && accepted.get('drawer').action === 'owner-balances') accepted.delete('drawer');
        Object.keys(columns).forEach(function (side) {
            var target = side === 'right' ? right : left;
            columns[side].forEach(function (key) {
                var data = accepted.get(key), node = cardsByKey.get(key);
                if (!data) { if (node) node.remove(); cardsByKey.delete(key); return; }
                var safe = safeURL(data.url);
                if (node && ((safe && node.tagName !== 'A') || (!safe && node.tagName === 'A'))) { node.remove(); node = null; }
                if (!node) { node = card(data); cardsByKey.set(key, node); }
                if (safe) node.href = safe;
                node.querySelector('.hud-card-label').textContent = data.label;
                // Reorder only when necessary, preserving keyboard focus on live updates.
                var index = columns[side].filter(function (k) { return accepted.has(k); }).indexOf(key);
                if (target.children[index] !== node) target.insertBefore(node, target.children[index] || null);
                if (key === 'drawer' && ownerBalance) { renderBalance(node, data); return; }
                delete node.dataset.hudOwnerBalances; delete node.dataset.balanceState; node.removeAttribute('aria-busy');
                var values = node.querySelector('.hud-card-values'), signature = [data.count, data.count_label, data.secondary_count, data.secondary_label, data.available].join('|');
                node.classList.toggle('warn', !!data.warning); node.classList.toggle('unavailable', !data.available);
                if (node.dataset.signature !== signature) {
                    values.replaceChildren(value(data.available ? data.count : null, data.available ? data.count_label : 'غير متاح', 'hud-primary'));
                    if (data.secondary_count !== null && data.secondary_count !== undefined) values.appendChild(value(data.secondary_count, data.secondary_label, 'hud-secondary'));
                    node.dataset.signature = signature; node.classList.add('changing');
                }
                node.setAttribute('aria-label', data.label + ': ' + fmt(data.available ? data.count : null) + ' ' + (data.count_label || '') + (data.secondary_count !== null && data.secondary_count !== undefined ? '، ' + fmt(data.secondary_count) + ' ' + data.secondary_label : ''));
            });
        });
        clearTimeout(renderTimer); renderTimer = setTimeout(function () { cardsByKey.forEach(function (n) { n.classList.remove('changing'); }); }, 600);
        root.querySelector('[data-hud-scope]').textContent = next.scope_label || '';
        freshness.textContent = 'العدّادات مباشرة'; live.classList.remove('stale');
    }
    function stale() { live.classList.add('stale'); freshness.textContent = 'تعذّر التحديث · آخر أرقام متاحة'; }
    function schedule() { clearTimeout(timer); if (!disposed && !balanceDenied && !root.hidden && !document.hidden) timer = setTimeout(refresh, 15000); }
    async function refresh() {
        if (disposed || balanceDenied || root.hidden || document.hidden || controller) return;
        var url = safeURL(boot.url); if (!url) { stale(); return; }
        url = new URL(url); url.search = ''; url.searchParams.set('section', 'hologram'); controller = new AbortController();
        timeout = setTimeout(function () { if (controller) controller.abort(); }, 12000);
        try {
            var response = await fetch(url.href, {credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
            if (disposed) return;
            if (response.status === 401 || response.status === 403 || response.redirected) { denyBalance(); cardsByKey.forEach(function (n) { n.remove(); }); cardsByKey.clear(); stale(); stopSpeech(); return; }
            if (!response.ok) throw Error('metrics'); var data = await response.json(); if (!data.success || !data.hologram) throw Error('metrics'); fill(data.hologram);
        } catch (_) { if (!disposed) stale(); }
        finally { clearTimeout(timeout); controller = null; schedule(); }
    }
    function onData(event) { if (!event.detail) return; if (event.detail.hologram) fill(event.detail.hologram); acceptBalance(event.detail); }
    function alertState(event) { if (robot) robot.setAlert(!!(event.detail && event.detail.pending && event.detail.pending.length)); }
    function speaking(on) {
        if (robot) robot.setSpeaking(on); var refocus = !on && document.activeElement === voiceButton;
        voiceButton.hidden = !on;
        if (on) { voiceButton.dataset.action = 'mute'; voiceButton.textContent = 'كتم الترحيب'; voiceButton.setAttribute('aria-label', 'كتم الترحيب'); }
        if (refocus && !root.hidden) enter.focus({preventScroll: true});
    }
    function mouth() {
        mouthFrame = 0;
        if (!speech || speech.paused || speech.ended || disposed) { if (robot && robot.setVoiceLevel) robot.setVoiceLevel(0); return; }
        var envelope = boot.greeting.audio.envelope, index = Math.floor(speech.currentTime * envelope.sample_rate);
        var level = index >= 0 && index < envelope.values.length ? Number(envelope.values[index]) : 0;
        if (robot && robot.setVoiceLevel) robot.setVoiceLevel(Math.max(0, Math.min(1, isFinite(level) ? level : 0)));
        mouthFrame = requestAnimationFrame(mouth);
    }
    function stopSpeech() {
        clearTimeout(greetingTimer); greetingTimer = 0;
        clearTimeout(greetingPoll); greetingPoll = 0; clearTimeout(greetingTimeout); greetingTimeout = 0; greetingEpoch++;
        if (greetingController) greetingController.abort(); greetingController = null;
        pendingGreeting = false; speechQueued = false; if (mouthFrame) cancelAnimationFrame(mouthFrame); mouthFrame = 0;
        if (speech) { speech.onplaying = speech.onended = speech.onerror = null; speech.pause(); if (speech.removeAttribute) speech.removeAttribute('src'); if (speech.load) speech.load(); }
        speech = null; if (robot && robot.setVoiceLevel) robot.setVoiceLevel(0); speaking(false);
    }
    function greetingActive() { return pendingGreeting && !greetingDone && !muted && !balanceDenied && !disposed && !root.hidden && !document.hidden && (document.body.dataset.dashboardActor || '') === currentActor; }
    function readyAudio(audio) {
        var envelope = audio && audio.envelope;
        return !!(audio && safeURL(audio.url) && envelope && Number.isFinite(envelope.sample_rate) && envelope.sample_rate >= 1 && envelope.sample_rate <= 120
            && Array.isArray(envelope.values) && envelope.values.length && envelope.values.length <= 18000
            && envelope.values.every(function (v) { return typeof v === 'number' && Number.isFinite(v) && v >= 0 && v <= 1; }));
    }
    function scheduleGreeting(delay) {
        clearTimeout(greetingPoll);
        if (greetingActive() && Date.now() < greetingDeadline) greetingPoll = setTimeout(pollGreeting, delay);
        else if (pendingGreeting) stopSpeech();
    }
    async function pollGreeting() {
        greetingPoll = 0;
        if (!greetingActive() || greetingController) return;
        var audio = boot.greeting.audio, url = audio && safeURL(audio.status_url);
        if (!url || Date.now() >= greetingDeadline) { stopSpeech(); return; }
        var epoch = greetingEpoch, at = new AbortController(); greetingController = at;
        greetingTimeout = setTimeout(function () { at.abort(); }, 12000);
        try {
            var response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store', signal: at.signal});
            if (!greetingActive() || epoch !== greetingEpoch) return;
            if (response.status === 401 || response.status === 403 || response.redirected) { denyBalance(); return; }
            if (response.status === 404) { stopSpeech(); return; }
            if (!response.ok) throw Error('greeting');
            var next = await response.json();
            if (!greetingActive() || epoch !== greetingEpoch) return;
            if (next.state === 'ready' && readyAudio(next)) { boot.greeting.audio = next; sayGreeting(); return; }
            if (next.state !== 'pending' || safeURL(next.status_url) !== url) { stopSpeech(); return; }
            if (Number.isFinite(next.expires_at)) greetingDeadline = Math.min(greetingDeadline, next.expires_at * 1000);
            boot.greeting.audio = next;
        } catch (_) { /* A temporary cache/status failure never substitutes another voice. */ }
        finally {
            if (epoch === greetingEpoch) {
                clearTimeout(greetingTimeout); greetingTimeout = 0; greetingController = null;
                if (greetingActive() && !readyAudio(boot.greeting.audio)) {
                    var delay = Number(boot.greeting.audio && boot.greeting.audio.poll_after_ms) || 1500;
                    scheduleGreeting(Math.max(500, Math.min(10000, delay)));
                }
            }
        }
    }
    function sayGreeting() {
        if (!greetingActive() || !greetingReady || speechQueued) return;
        var audio = boot.greeting && boot.greeting.audio, url = audio && safeURL(audio.url);
        if (!readyAudio(audio)) { if (!audio || audio.state !== 'pending') stopSpeech(); return; }
        if (!window.Audio) { stopSpeech(); return; }
        // Only the reviewed, reference-conditioned local recording may speak.
        // Never substitute an unrelated browser/system voice.
        var epoch = greetingEpoch, player = new Audio(url); speech = player; player.preload = 'auto'; player.volume = .85;
        player.onplaying = function () {
            if (epoch !== greetingEpoch || !greetingActive() || speech !== player) { player.pause(); return; }
            player.onplaying = null;
            greetingDone = true; pendingGreeting = false;
            try { sessionStorage.setItem(greetingStorageKey, greetingNonce); } catch (_) { /* Server also consumes this login nonce. */ }
            speaking(true); if (!mouthFrame) mouthFrame = requestAnimationFrame(mouth);
        };
        player.onended = function () { if (speech === player) stopSpeech(); };
        player.onerror = function () { if (speech === player) stopSpeech(); };
        speechQueued = true;
        try {
            var attempt = player.play();
            if (attempt && attempt.catch) attempt.catch(function (error) {
                if (epoch !== greetingEpoch || speech !== player) return;
                player.onplaying = player.onended = player.onerror = null; player.pause();
                speechQueued = false; speech = null;
                if (!error || error.name !== 'NotAllowedError') { stopSpeech(); return; }
                if (greetingActive()) { voiceButton.dataset.action = 'play'; voiceButton.textContent = 'تشغيل الترحيب'; voiceButton.setAttribute('aria-label', 'تشغيل الترحيب'); voiceButton.hidden = false; }
            });
        } catch (_) { stopSpeech(); }
    }
    function greeting() {
        if (!boot.greeting || !boot.greeting.nonce || balanceDenied || disposed || root.hidden || document.hidden) return;
        greetingStorageKey = 'go-hologram-greeting:' + currentActor; greetingNonce = String(boot.greeting.nonce);
        try { if (sessionStorage.getItem(greetingStorageKey) === greetingNonce) return; } catch (_) { /* Server consumes the nonce even without session storage. */ }
        pendingGreeting = true;
        var audio = boot.greeting.audio;
        greetingDeadline = Date.now() + 30 * 60 * 1000;
        if (audio && Number.isFinite(audio.expires_at)) greetingDeadline = Math.min(greetingDeadline, audio.expires_at * 1000);
        if (robot && window.GoHologram.version >= 3 && !(reduce && reduce.matches)) {
            greetingReady = false;
            greetingTimer = setTimeout(function () { greetingTimer = 0; greetingReady = true; sayGreeting(); }, 6200);
        }
        if (audio && audio.state === 'pending' && safeURL(audio.status_url)) scheduleGreeting(0);
        else if (!readyAudio(audio)) { stopSpeech(); return; }
        voiceButton.dataset.action = 'mute'; voiceButton.textContent = 'إلغاء الترحيب'; voiceButton.setAttribute('aria-label', 'إلغاء الترحيب'); voiceButton.hidden = false;
        sayGreeting();
    }
    function leave() { if (disposed) return; root.hidden = true; dispose(); var title = document.querySelector('#ho-title, #branch-home h1'); if (title) { title.tabIndex = -1; title.focus({preventScroll: true}); } }
    function keydown(event) {
        if (event.key === 'Escape') { event.preventDefault(); leave(); return; }
        if (event.key !== 'Tab') return;
        var items = Array.from(root.querySelectorAll('a[href],button:not([hidden]):not([disabled])')); var first = items[0], last = items[items.length - 1];
        if (!root.contains(document.activeElement)) { event.preventDefault(); first.focus(); return; }
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    function visibility() { if (document.hidden) { clearTimeout(timer); cancelBalance(false); if (bgFrame) cancelAnimationFrame(bgFrame); bgFrame = 0; stopSpeech(); } else { wakeBackground(); refresh(); scheduleBalance(); } }
    function dispose() {
        if (disposed) return; disposed = true; root.hidden = true; clearTimeout(timer); clearTimeout(timeout); clearTimeout(renderTimer); if (bgFrame) cancelAnimationFrame(bgFrame); bgFrame = 0; if (controller) controller.abort(); stopSpeech(); if (robot) robot.destroy(); robot = null;
        cancelBalance(true); if (balanceObserver) balanceObserver.disconnect();
        removers.forEach(function (remove) { remove(); }); document.documentElement.classList.remove('go-hud-open');
    }
    document.documentElement.classList.add('go-hud-open');
    on(enter, 'click', leave); on(document, 'keydown', keydown); on(document, 'go:home-data', onData); on(document, 'go:home-drawers-state', onDrawerState); on(document, 'dashboard:order-alert-state', alertState);
    on(root, 'click', function (event) { var node = event.target.closest('[data-hud-owner-balances="true"]'); if (!node || !root.contains(node) || !balance || !balanceActive()) return; event.preventDefault(); leave(); document.dispatchEvent(new CustomEvent('go:owner-drawers-open')); });
    on(document, 'visibilitychange', visibility); on(document, 'dashboard:before-unload', dispose); on(window, 'pagehide', dispose);
    on(window, 'resize', wakeBackground); if (reduce && reduce.addEventListener) on(reduce, 'change', wakeBackground);
    function resumeGreeting(event) { if (!voiceButton.contains(event.target)) sayGreeting(); }
    on(root, 'pointerdown', resumeGreeting, {capture: true}); on(root, 'keydown', resumeGreeting, {capture: true});
    on(voiceButton, 'click', function () { if (voiceButton.dataset.action === 'play') { sayGreeting(); return; } muted = true; stopSpeech(); });
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(dispose);
    if (window.MutationObserver) { balanceObserver = new MutationObserver(function () { if ((document.body.dataset.dashboardActor || '') !== currentActor) { denyBalance(); return; } if (root.hidden) { cancelBalance(false); stopSpeech(); } else scheduleBalance(); }); balanceObserver.observe(root, {attributes: true, attributeFilter: ['hidden']}); balanceObserver.observe(document.body, {attributes: true, attributeFilter: ['data-dashboard-actor']}); }
    fill((boot.initial || {}).hologram); if (!metric) { freshness.textContent = 'جار تحميل العدّادات'; refresh(); } else schedule();
    acceptBalance(boot.initial);
    var overviewRoot = document.getElementById('home-overview'); if (overviewRoot && overviewRoot.goHomeDrawersState) onDrawerState({detail: overviewRoot.goHomeDrawersState});
    if (window.DashboardOrderAlerts) alertState({detail: window.DashboardOrderAlerts.state()});
    wakeBackground(); enter.focus({preventScroll: true}); greeting();
})();
