(function () {
    'use strict';
    // Late delivery orders, everywhere in the dashboard: an order still being prepared at 15 minutes, or still with the
    // courier at 35, shows in a red box and sounds a warning, again every 5 minutes while one stays late. One tab of the
    // account asks the server every minute and rings; the other tabs show its result. The order sound's mute applies.
    if (window.DashboardDeliveryLateness) return;
    var script = document.currentScript, feed = script && script.dataset.lateFeed, page = script && script.dataset.latePage;
    if (!feed) return;
    var actor = script.dataset.actor || 'dashboard', prefix = 'dashboardDeliveryLate:' + actor;
    var leadKey = prefix + ':lead', sharedKey = prefix + ':orders', rungKey = prefix + ':rung';
    var tab = Date.now() + ':' + Math.random(), audio = null, box = null, timer = null, controller = null, repeat = 5, stopped = false;
    try { audio = new window.Audio(script.dataset.lateSound || '/sounds/go-delivery-late.wav'); audio.preload = 'auto'; } catch (_) {}
    function read(key) { try { return localStorage.getItem(key); } catch (_) { return null; } }
    function write(key, value) { try { localStorage.setItem(key, value); } catch (_) {} }
    function json(key) { try { return JSON.parse(read(key) || 'null'); } catch (_) { return null; } }
    function leads() {
        var owner = json(leadKey), now = Date.now();
        if (owner && owner.tab !== tab && owner.until > now && owner.until <= now + 150000) return false;
        write(leadKey, JSON.stringify({ tab: tab, until: now + 150000 }));
        return true;
    }
    function stage(order) { return order.stage === 'courier' ? 'مع المندوب' : 'في التجهيز'; }
    function show(orders) {
        if (!orders.length) { if (box) box.hidden = true; return; }
        if (!box) {
            box = document.createElement(page ? 'a' : 'div'); box.className = 'go-delivery-late'; box.setAttribute('role', 'alert');
            if (page) box.href = page;
            var style = document.createElement('style');
            style.textContent = '.go-delivery-late{position:fixed;left:16px;bottom:52px;z-index:2147480000;display:block;max-width:330px;padding:10px 14px;border-radius:10px;'
                + 'background:#c21f2c;color:#fff!important;font:600 13px/1.6 Almarai,Tahoma,sans-serif;direction:rtl;text-decoration:none!important;box-shadow:0 8px 24px #0005;'
                + 'animation:go-late-pulse 1.4s ease-in-out infinite}.go-delivery-late b{display:block;font-size:14px}.go-delivery-late small{display:block;opacity:.95}'
                + '@keyframes go-late-pulse{50%{box-shadow:0 0 0 6px #c21f2c55}}@media print{.go-delivery-late{display:none}}';
            document.head.appendChild(style); document.body.appendChild(box);
        }
        box.hidden = false; box.replaceChildren();
        var title = document.createElement('b'); title.textContent = '⚠ تأخير في الدليفري: ' + orders.length + (orders.length === 1 ? ' طلب' : ' طلبات'); box.appendChild(title);
        orders.slice(0, 4).forEach(function (order) {
            var line = document.createElement('small');
            line.textContent = '#' + order.number + ' · ' + order.branch + ' · ' + stage(order) + ' ' + order.minutes + ' دقيقة';
            box.appendChild(line);
        });
        if (orders.length > 4) { var more = document.createElement('small'); more.textContent = 'و' + (orders.length - 4) + ' غيرهم'; box.appendChild(more); }
    }
    function ring(orders) {
        if (!orders.length || read('soundEnabled') === 'false') return;
        var rung = json(rungKey) || { at: 0, ids: [] }, now = Date.now();
        var fresh = orders.some(function (order) { return rung.ids.indexOf(order.stage + ':' + order.id) < 0; });
        // Again every 5 minutes while late; at once (at most once a minute) when another order becomes late.
        if (now - rung.at < repeat * 60000 && !(fresh && now - rung.at >= 60000)) return;
        write(rungKey, JSON.stringify({ at: now, ids: orders.map(function (order) { return order.stage + ':' + order.id; }) }));
        if (!audio) return;
        try { audio.currentTime = 0; var playing = audio.play(); if (playing && playing.catch) playing.catch(function () {}); } catch (_) {}
    }
    async function poll() {
        clearTimeout(timer); if (stopped) return;
        timer = setTimeout(poll, window.GODashboardIdle && window.GODashboardIdle.quiet && window.GODashboardIdle.quiet() ? 120000 : 60000); // server load: 1 min, 2 min untouched
        if (!leads()) { var shared = json(sharedKey); if (shared && Date.now() - shared.at < 150000) show(shared.orders || []); return; }
        if (controller) controller.abort();
        controller = new AbortController(); var abort = controller, limit = setTimeout(function () { abort.abort(); }, 20000);
        try {
            var response = await (window.GoPulse ? window.GoPulse.fetch : fetch)(feed, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store', signal: abort.signal });
            if (!response.ok) return;
            var result = await response.json();
            if (!result || !Array.isArray(result.orders)) return;
            if (result.policy && result.policy.repeat_minutes > 0) repeat = Number(result.policy.repeat_minutes);
            write(sharedKey, JSON.stringify({ at: Date.now(), orders: result.orders }));
            show(result.orders); ring(result.orders);
        } catch (_) { /* no internet or signed out: nothing to show */ }
        finally { clearTimeout(limit); }
    }
    window.addEventListener('storage', function (event) {
        if (event.key === sharedKey) { var shared = json(sharedKey); if (shared) show(shared.orders || []); }
    });
    window.addEventListener('pagehide', function () { var owner = json(leadKey); if (owner && owner.tab === tab) write(leadKey, 'null'); });
    window.DashboardDeliveryLateness = { refresh: poll, stop: function () { stopped = true; clearTimeout(timer); if (box) box.hidden = true; } };
    setTimeout(poll, 3000);
})();
