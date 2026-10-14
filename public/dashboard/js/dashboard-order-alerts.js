(function () {
    'use strict';
    if (window.DashboardOrderAlerts) return;
    var script = document.currentScript, pending = new Map(), scopes = new Map(), arrivals = new Map(), acknowledgements = new Map(), sequence = 0;
    var preference = 'soundEnabled', actor = script && script.dataset.soundActor || 'dashboard';
    var leaseKey = 'dashboardOrderSound:' + actor, ackKey = leaseKey + ':ack', tab = Date.now() + ':' + Math.random();
    var pendingKey = leaseKey + ':pending', feedUrl = script && script.dataset.soundFeed;
    var enabled = read(preference) !== 'false', playing = false, starting = false, unlocked = false, blocked = false;
    var generation = 0, testTimer = null, audio = null, stopped = false, feedTimer = null, feedController = null;
    try { JSON.parse(sessionStorage.getItem(pendingKey) || '[]').forEach(function (key) { pending.set(String(key), new Set(['restored'])); }); } catch (_) {}
    try { audio = new window.Audio(script && script.dataset.soundUrl || '/sounds/mixkit-correct-answer-reward-952.wav'); audio.preload = 'auto'; } catch (_) {}
    function read(key) { try { return localStorage.getItem(key); } catch (_) { return null; } }
    function write(key, value) { try { localStorage.setItem(key, value); return true; } catch (_) { return false; } }
    function lease() { try { return JSON.parse(read(leaseKey) || 'null'); } catch (_) { return null; } }
    function ownsAudio(force) {
        var owner = lease(), now = Date.now();
        if (!force && owner && owner.tab !== tab && owner.until > now) return false;
        write(leaseKey, JSON.stringify({ tab: tab, until: now + 6500 }));
        return true;
    }
    function release() { var owner = lease(); if (owner && owner.tab === tab) write(leaseKey, 'null'); }
    function pause() {
        generation++; starting = false; playing = false; clearTimeout(testTimer); testTimer = null;
        if (audio) { audio.pause(); try { audio.currentTime = 0; } catch (_) {} }
        release();
    }
    function update() {
        try { sessionStorage.setItem(pendingKey, JSON.stringify(Array.from(pending.keys()))); } catch (_) {}
        var button = document.getElementById('toggle-sound'), icon = document.getElementById('sound-icon');
        if (button) {
            button.type = 'button';
            button.title = !enabled ? 'تشغيل واختبار صوت الطلبات' : blocked || !unlocked ? 'اضغط لتفعيل واختبار صوت الطلبات' : 'إيقاف صوت الطلبات';
            button.setAttribute('aria-label', button.title); button.setAttribute('aria-pressed', String(enabled && unlocked));
            button.dataset.soundState = !enabled ? 'muted' : blocked ? 'blocked' : playing ? 'playing' : 'ready';
        }
        if (icon) { icon.classList.toggle('fa-volume-up', enabled); icon.classList.toggle('fa-volume-mute', !enabled); }
        var prompt = document.querySelector('[data-order-sound-unlock]');
        if (!prompt && enabled && blocked && pending.size) {
            prompt = document.createElement('button'); prompt.type = 'button'; prompt.dataset.orderSoundUnlock = '';
            prompt.textContent = 'اضغط لتشغيل صوت الطلبات';
            prompt.style.cssText = 'position:fixed;bottom:85px;inset-inline-end:20px;z-index:1100;padding:10px 16px;border:0;border-radius:8px;background:#f57800;color:#fff;font-weight:bold';
            prompt.addEventListener('click', function () { unlock(true); }); document.body.appendChild(prompt);
        }
        if (prompt) prompt.hidden = !(enabled && blocked && pending.size);
        document.dispatchEvent(new CustomEvent('dashboard:order-alert-state', { detail: { pending: Array.from(pending.keys()) } }));
    }
    // Only a fulfilled play promise means sound is playing. A rejection remains retryable.
    function play(test, gesture) {
        if (stopped || !enabled || !audio || !pending.size && !test) { update(); return; }
        if (!ownsAudio(!!gesture)) { update(); return; }
        audio.loop = pending.size > 0;
        if (playing || starting) { update(); return; }
        var attempt = ++generation; starting = true;
        var promise;
        try { promise = audio.play(); } catch (error) { promise = Promise.reject(error); }
        Promise.resolve(promise).then(function () {
            if (attempt !== generation) return;
            starting = false; playing = true; unlocked = true; blocked = false;
            if (!pending.size) testTimer = setTimeout(function () { if (!pending.size) { pause(); update(); } }, 900);
            update();
        }, function () {
            if (attempt !== generation) return;
            starting = false; playing = false; unlocked = false; blocked = true; release(); update();
        });
    }
    function reconcile() {
        if (!enabled || !pending.size) { if (playing || starting) pause(); update(); return; }
        var owner = lease();
        if (owner && owner.tab !== tab && owner.until > Date.now()) { if (playing || starting) pause(); update(); return; }
        if (!blocked) play(false, false);
        update();
    }
    function unlock(test) {
        enabled = true; write(preference, 'true'); blocked = false;
        // Called directly in the click/key event, while browser user activation is available.
        play(test, true); update();
    }
    function notify(key, scope) {
        if (!key) return;
        key = String(key); scope = scope || 'live';
        arrivals.set(key, ++sequence); acknowledgements.delete(key);
        if (!pending.has(key)) pending.set(key, new Set()); pending.get(key).add(scope);
        blocked = false; reconcile();
    }
    function acknowledge(key, remote) {
        if (!key) return;
        key = String(key);
        acknowledgements.set(key, ++sequence);
        var existed = pending.delete(key); scopes.forEach(function (keys) { keys.delete(key); });
        if (existed && !remote) write(ackKey, JSON.stringify({ key: key, tab: tab, at: Date.now() }));
        reconcile();
    }
    function sync(scope, keys) {
        // Filtered/paginated snapshots are not proof that an absent order was acted on.
        var next = scopes.get(scope) || new Set(); keys.forEach(function (key) { next.add(String(key)); });
        scopes.set(scope, next);
        next.forEach(function (key) { if (!pending.has(key)) pending.set(key, new Set()); pending.get(key).add(scope); });
        reconcile();
    }
    function observeApp(source) {
        if (typeof source === 'string') {
            var snapshot = document.createElement('template'); snapshot.innerHTML = source; source = snapshot.content;
        }
        var keys = Array.from(source.querySelectorAll('[data-column="new"] [data-order-key]')).map(function (card) { return card.dataset.orderKey; });
        source.querySelectorAll('[data-column]:not([data-column="new"]) [data-order-key]').forEach(function (card) { acknowledge(card.dataset.orderKey); });
        sync('app-board', keys);
    }
    function reconcileApp(keys, beforeRequest) {
        var before = typeof beforeRequest === 'number' ? beforeRequest : sequence;
        var valid = function (key) { return typeof key === 'string' && /^(legacy|store|service|partner_service):[1-9][0-9]*$/.test(key); };
        var complete = new Set(keys.filter(valid));
        Array.from(pending.keys()).forEach(function (key) {
            if (valid(key) && !complete.has(key) && (arrivals.get(key) || 0) <= before) acknowledge(key);
        });
        // A response started before a confirmed local action must not resurrect that order.
        sync('app-authoritative', Array.from(complete).filter(function (key) { return (acknowledgements.get(key) || 0) <= before; }));
    }
    async function pollOrders() {
        if (stopped || !feedUrl || typeof window.fetch !== 'function') return;
        // The board owns its own polling while mounted. Other dashboard pages still ring.
        if (!document.getElementById('order-board')) {
            var controller = new AbortController(); feedController = controller;
            var before = sequence;
            var timeout = setTimeout(function () { controller.abort(); }, 15000);
            try {
                var response = await window.fetch(feedUrl, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error('Order feed unavailable');
                var payload = await response.json(), html = payload.html !== undefined ? payload.html : payload.view;
                if (!stopped && Array.isArray(payload.pending_alert_keys)) reconcileApp(payload.pending_alert_keys, before);
                else if (!stopped && typeof html === 'string') observeApp(html);
            } catch (_) { /* Keep existing alerts while the network or session recovers. */ }
            finally { clearTimeout(timeout); if (feedController === controller) feedController = null; }
        }
        if (!stopped) feedTimer = setTimeout(pollOrders, 10000);
    }
    window.DashboardOrderAlerts = {
        notify: notify, acknowledge: acknowledge, sync: sync, observeApp: observeApp, reconcileApp: reconcileApp, checkpoint: function () { return sequence; }, unlock: unlock,
        isReady: function () { return enabled && unlocked && !blocked; },
        retry: function () { blocked = false; reconcile(); },
        state: function () { return { enabled: enabled, playing: playing, blocked: blocked, pending: Array.from(pending.keys()) }; }
    };
    // Compatibility for old push handlers: a signal alone never acknowledges pending orders.
    window.playSound = function () { window.DashboardOrderAlerts.retry(); };
    window.stopSound = function (key) { if (key) acknowledge(key); else reconcile(); };
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('#toggle-sound');
        if (!button) return;
        if (!enabled || blocked || !unlocked) unlock(true);
        else { enabled = false; write(preference, 'false'); pause(); update(); }
    });
    function userGesture(event) {
        if (!enabled || !pending.size || !(blocked || !unlocked)) return;
        if (event.target.closest && event.target.closest('#toggle-sound, [data-order-sound-unlock]')) return;
        unlock(false);
    }
    document.addEventListener('pointerdown', userGesture);
    document.addEventListener('keydown', userGesture);
    window.addEventListener('storage', function (event) {
        if (event.key === preference) { enabled = read(preference) !== 'false'; reconcile(); }
        if (event.key === leaseKey) reconcile();
        if (event.key === ackKey) {
            try { var ack = JSON.parse(event.newValue); if (ack && ack.tab !== tab) acknowledge(ack.key, true); } catch (_) {}
        }
    });
    function tick() {
        if (playing || starting) {
            var owner = lease();
            if (owner && owner.tab !== tab && owner.until > Date.now()) pause();
            else ownsAudio(false);
        }
        reconcile();
    }
    var heartbeat = setInterval(tick, 2000);
    window.addEventListener('pagehide', function () {
        stopped = true; pause(); clearInterval(heartbeat); clearTimeout(feedTimer);
        if (feedController) feedController.abort();
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && stopped) { stopped = false; heartbeat = setInterval(tick, 2000); reconcile(); pollOrders(); }
    });
    update(); reconcile(); feedTimer = setTimeout(pollOrders, 750);
}());
