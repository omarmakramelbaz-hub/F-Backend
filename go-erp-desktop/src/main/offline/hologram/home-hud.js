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
        'drawers': 'M3 10h18v11H3z M3 15h18 M10 12.5h4 M10 18h4 M6 10V5h8v5 M8 7h4 M16 6h3l2 4',
        'drawer': 'M4 2h11l5 5v6 M4 2v20h8 M14 2v6h6 M7 11h7 M7 15h4 M15 18a4 4 0 1 0 8 0 4 4 0 0 0-8 0 M19 15v3h2',
        'stock': 'm12 1 6 3v7l-6 3-6-3V4z M6 4l6 3 6-3 M12 7v7 M6 11l-6 3v7l6 3 6-3v-7 M0 14l6 3 6-3 M6 17v7 M18 11l6 3v7l-6 3-6-3 M18 17v7 M12 14l6 3 6-3'
    };
    var columns = {right: ['ch-app', 'ch-takeaway', 'ch-phone', 'ch-dine', 'drawers', 'pending-expenses'], left: ['employees', 'customers', 'expenses', 'drawer', 'stock']};
    var number = new Intl.NumberFormat('en-GB');
    function on(target, name, fn, options) { target.addEventListener(name, fn, options); removers.push(function () { target.removeEventListener(name, fn, options); }); }
    function el(tag, text, cls) { var n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
    function fmt(n) { return typeof n === 'number' && isFinite(n) && n >= 0 ? number.format(n) : '—'; }
    function safeURL(address) { if (typeof address !== 'string' || !address.trim()) return null; try { var u = new URL(address, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : null; } catch (_) { return null; } }
    function icon(key) { var n = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), p = document.createElementNS(n.namespaceURI, 'path'); n.setAttribute('viewBox', '0 0 24 24'); n.setAttribute('aria-hidden', 'true'); n.setAttribute('class', 'hud-card-icon'); p.setAttribute('d', paths[key] || paths['ch-app']); n.appendChild(p); return n; }
    function value(n, label, cls) { var part = el('span', undefined, cls); part.appendChild(el('b', fmt(n))); part.appendChild(document.createTextNode(label || '')); return part; }
    function card(data) {
        var url = safeURL(data.url), node = el(url ? 'a' : 'div', undefined, 'hud-card'); if (url) node.href = url;
        node.dataset.hudKey = data.key; node.appendChild(icon(data.key)); var copy = el('span', undefined, 'hud-card-copy');
        copy.appendChild(el('span', data.label, 'hud-card-label')); copy.appendChild(el('span', undefined, 'hud-card-values')); node.appendChild(copy); return node;
    }
    function fill(next) {
        if (disposed || !root.isConnected || !next || !Array.isArray(next.cards)) return;
        metric = next; var accepted = new Map(next.cards.filter(function (c) { return c && typeof c.key === 'string'; }).map(function (c) { return [c.key, c]; }));
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
    // Server load: every 30 seconds; every 2 minutes on a device untouched for half an hour (dashboard-idle.js).
    function schedule() { clearTimeout(timer); var idle = window.GODashboardIdle; if (!disposed && !root.hidden && !document.hidden) timer = setTimeout(refresh, idle && idle.quiet && idle.quiet() ? 120000 : 30000); }
    async function refresh() {
        if (disposed || root.hidden || document.hidden || controller) return;
        var url = safeURL(boot.url); if (!url) { stale(); return; }
        url = new URL(url); url.search = ''; url.searchParams.set('section', 'hologram'); controller = new AbortController();
        timeout = setTimeout(function () { if (controller) controller.abort(); }, 12000);
        try {
            var response = await fetch(url.href, {credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
            if (disposed) return;
            if (response.status === 401 || response.status === 403 || response.redirected) { cardsByKey.forEach(function (n) { n.remove(); }); cardsByKey.clear(); stale(); stopSpeech(); return; }
            if (!response.ok) throw Error('metrics'); var data = await response.json(); if (!data.success || !data.hologram) throw Error('metrics'); fill(data.hologram);
        } catch (_) { if (!disposed) stale(); }
        finally { clearTimeout(timeout); controller = null; schedule(); }
    }
    function onData(event) { if (event.detail && event.detail.hologram) fill(event.detail.hologram); }
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
        pendingGreeting = false; speechQueued = false; if (mouthFrame) cancelAnimationFrame(mouthFrame); mouthFrame = 0;
        if (speech) { speech.onplaying = speech.onended = speech.onerror = null; speech.pause(); }
        speech = null; if (robot && robot.setVoiceLevel) robot.setVoiceLevel(0); speaking(false);
    }
    function sayGreeting() {
        if (!pendingGreeting || !greetingReady || speechQueued || disposed || root.hidden || document.hidden || muted || greetingDone) return;
        var audio = boot.greeting && boot.greeting.audio, url = audio && safeURL(audio.url);
        if (!url || !audio.envelope || !Array.isArray(audio.envelope.values) || !audio.envelope.values.length || !window.Audio) { pendingGreeting = false; return; }
        // Only the reviewed, reference-conditioned local recording may speak.
        // Never substitute an unrelated browser/system voice.
        speech = new Audio(url); speech.preload = 'auto'; speech.volume = .85;
        speech.onplaying = function () { greetingDone = true; pendingGreeting = false; speaking(true); if (!mouthFrame) mouthFrame = requestAnimationFrame(mouth); };
        speech.onended = function () { stopSpeech(); };
        speech.onerror = function () { stopSpeech(); };
        speechQueued = true;
        try {
            var attempt = speech.play();
            if (attempt && attempt.catch) attempt.catch(function (error) {
                speechQueued = false; speech = null;
                if (!error || error.name !== 'NotAllowedError') { pendingGreeting = false; return; }
                if (!disposed && !root.hidden) { voiceButton.dataset.action = 'play'; voiceButton.textContent = 'تشغيل الترحيب'; voiceButton.setAttribute('aria-label', 'تشغيل الترحيب'); voiceButton.hidden = false; }
            });
        } catch (_) { stopSpeech(); }
    }
    function greeting() {
        if (!boot.greeting || !boot.greeting.nonce) return;
        var key = 'go-hologram-greeting:' + currentActor, nonce = String(boot.greeting.nonce);
        try { if (sessionStorage.getItem(key) === nonce) return; sessionStorage.setItem(key, nonce); } catch (_) { /* Server consumes the nonce even without session storage. */ }
        pendingGreeting = true;
        if (robot && window.GoHologram.version >= 3 && !(reduce && reduce.matches)) {
            greetingReady = false;
            greetingTimer = setTimeout(function () { greetingTimer = 0; greetingReady = true; sayGreeting(); }, 6200);
        } else sayGreeting();
    }
    function leave() { if (disposed) return; root.hidden = true; dispose(); var title = document.querySelector('#ho-title, #branch-home h1'); if (title) { title.tabIndex = -1; title.focus({preventScroll: true}); } }
    // «رصيد أدراج الفروع» opens the balance of each branch's drawer, on this same page below the opening.
    function showDrawers() {
        leave(); var panel = document.querySelector('.ho-owner-drawers'); if (!panel) return;
        panel.scrollIntoView({block: 'center', behavior: reduce && reduce.matches ? 'auto' : 'smooth'});
        var list = panel.querySelector('[data-ho-drawers]'); if (list) list.focus({preventScroll: true});
    }
    function drawersClick(event) {
        var link = event.target && event.target.closest ? event.target.closest('a[data-hud-key="drawers"]') : null;
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); try { history.replaceState(history.state, '', '#branch-drawers'); } catch (_) {} showDrawers();
    }
    function keydown(event) {
        if (event.key === 'Escape') { event.preventDefault(); leave(); return; }
        if (event.key !== 'Tab') return;
        var items = Array.from(root.querySelectorAll('a[href],button:not([hidden]):not([disabled])')); var first = items[0], last = items[items.length - 1];
        if (!root.contains(document.activeElement)) { event.preventDefault(); first.focus(); return; }
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    function visibility() { if (document.hidden) { clearTimeout(timer); if (bgFrame) cancelAnimationFrame(bgFrame); bgFrame = 0; stopSpeech(); } else { wakeBackground(); refresh(); } }
    function dispose() {
        if (disposed) return; disposed = true; root.hidden = true; clearTimeout(timer); clearTimeout(timeout); clearTimeout(renderTimer); if (bgFrame) cancelAnimationFrame(bgFrame); bgFrame = 0; if (controller) controller.abort(); stopSpeech(); if (robot) robot.destroy(); robot = null;
        removers.forEach(function (remove) { remove(); }); document.documentElement.classList.remove('go-hud-open');
    }
    document.documentElement.classList.add('go-hud-open');
    on(enter, 'click', leave); on(root, 'click', drawersClick); on(document, 'keydown', keydown); on(document, 'go:home-data', onData); on(document, 'dashboard:order-alert-state', alertState);
    on(document, 'visibilitychange', visibility); on(document, 'dashboard:before-unload', dispose); on(window, 'pagehide', dispose);
    on(window, 'resize', wakeBackground); if (reduce && reduce.addEventListener) on(reduce, 'change', wakeBackground);
    function resumeGreeting(event) { if (!voiceButton.contains(event.target)) sayGreeting(); }
    on(root, 'pointerdown', resumeGreeting, {capture: true}); on(root, 'keydown', resumeGreeting, {capture: true});
    on(voiceButton, 'click', function () { if (voiceButton.dataset.action === 'play') { sayGreeting(); return; } muted = true; stopSpeech(); });
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(dispose);
    fill((boot.initial || {}).hologram);
    if (location.hash === '#branch-drawers') { setTimeout(showDrawers, 0); return; }
    if (!metric) { freshness.textContent = 'جار تحميل العدّادات'; refresh(); } else schedule();
    if (window.DashboardOrderAlerts) alertState({detail: window.DashboardOrderAlerts.state()});
    wakeBackground(); enter.focus({preventScroll: true}); greeting();
})();
