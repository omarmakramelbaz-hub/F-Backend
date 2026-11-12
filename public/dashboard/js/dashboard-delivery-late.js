(function () {
    'use strict';
    // Late delivery orders and late app orders (kind «app»), everywhere in the dashboard: an order still being prepared at
    // 15 minutes, or still with the courier at 35, shows in a red box and sounds a warning, again every 5 minutes while one stays late. One tab of the
    // account asks the server every minute and rings; the other tabs show its result. The order sound's mute applies.
    if (window.DashboardDeliveryLateness) return;
    var script = document.currentScript, feed = script && script.dataset.lateFeed, page = script && script.dataset.latePage, boardPage = script && script.dataset.lateBoard;
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
    function app(order) { return order.kind === 'app'; }
    function key(order) { return order.stage + ':' + (order.key || order.id); }
    // A ringing bell (the count on it) with the late orders' card above it, 2 cm above where the box was.
    var BELL = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true" fill="currentColor"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-1.7 1.7A1 1 0 0 0 4 19.4h16a1 1 0 0 0 .7-1.7Z"/></svg>';
    function show(orders) {
        if (!orders.length) { if (box) box.hidden = true; return; }
        if (!box) {
            box = document.createElement(page || boardPage ? 'a' : 'div'); box.className = 'go-delivery-late'; box.setAttribute('role', 'alert');
            var style = document.createElement('style');
            style.textContent = '.go-delivery-late{position:fixed;left:16px;bottom:128px;z-index:2147480000;display:flex;flex-direction:column;align-items:flex-start;gap:10px;'
                + 'max-width:330px;color:#fff!important;font:600 13px/1.6 Almarai,Tahoma,sans-serif;direction:ltr;text-decoration:none!important}'
                + '.go-late-card{direction:rtl;background:#c21f2c;border-radius:12px;padding:10px 14px;box-shadow:0 8px 24px #0005;position:relative}'
                + '.go-late-card:after{content:"";position:absolute;left:22px;bottom:-7px;width:14px;height:14px;background:#c21f2c;transform:rotate(45deg)}'
                + '.go-late-card b{display:block;font-size:14px}.go-late-card small{display:block;opacity:.95}'
                + '.go-late-bell{position:relative;width:58px;height:58px;border-radius:50%;background:#c21f2c;display:grid;place-items:center;box-shadow:0 8px 24px #0005;'
                + 'animation:go-late-pulse 1.4s ease-in-out infinite}.go-late-bell svg{animation:go-late-ring 1.4s ease-in-out infinite;transform-origin:50% 8%}'
                + '.go-late-count{position:absolute;top:-4px;right:-4px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:#fff;color:#c21f2c;'
                + 'font:800 12px/22px Almarai,Tahoma,sans-serif;text-align:center;box-shadow:0 2px 6px #0004}'
                + '@keyframes go-late-pulse{50%{box-shadow:0 0 0 8px #c21f2c55}}'
                + '@keyframes go-late-ring{0%,50%,100%{transform:rotate(0)}10%,30%{transform:rotate(14deg)}20%,40%{transform:rotate(-14deg)}}'
                + '@media(prefers-reduced-motion:reduce){.go-late-bell,.go-late-bell svg{animation:none}}@media print{.go-delivery-late{display:none}}';
            document.head.appendChild(style); document.body.appendChild(box);
        }
        box.hidden = false; box.replaceChildren();
        // Opens the page of the late orders: the app orders board when they are all app orders.
        var target = orders.every(app) ? (boardPage || page) : (page || boardPage);
        if (box.tagName === 'A' && target) box.href = target;
        var card = document.createElement('div'); card.className = 'go-late-card';
        var title = document.createElement('b'); title.textContent = (orders.every(app) ? 'تأخير في طلبات التطبيق' : orders.some(app) ? 'تأخير في الطلبات' : 'تأخير في الدليفري') + ': ' + orders.length + (orders.length === 1 ? ' طلب' : ' طلبات'); card.appendChild(title);
        orders.slice(0, 4).forEach(function (order) {
            var line = document.createElement('small');
            line.textContent = '#' + order.number + (app(order) ? ' · تطبيق' : '') + (order.branch ? ' · ' + order.branch : '') + ' · ' + stage(order) + ' ' + order.minutes + ' دقيقة';
            card.appendChild(line);
        });
        if (orders.length > 4) { var more = document.createElement('small'); more.textContent = 'و' + (orders.length - 4) + ' غيرهم'; card.appendChild(more); }
        var bell = document.createElement('span'); bell.className = 'go-late-bell'; bell.innerHTML = BELL;
        var count = document.createElement('span'); count.className = 'go-late-count'; count.textContent = String(orders.length); bell.appendChild(count);
        box.appendChild(card); box.appendChild(bell);
    }
    function ring(orders) {
        if (!orders.length || read('soundEnabled') === 'false') return;
        var rung = json(rungKey) || { at: 0, ids: [] }, now = Date.now();
        var fresh = orders.some(function (order) { return rung.ids.indexOf(key(order)) < 0; });
        // Again every 5 minutes while late; at once (at most once a minute) when another order becomes late.
        if (now - rung.at < repeat * 60000 && !(fresh && now - rung.at >= 60000)) return;
        write(rungKey, JSON.stringify({ at: now, ids: orders.map(key) }));
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
