/* The owner's GO home: today's figures, net profit, the week's sales, the branches today and alerts.
   The small cards, the command bar and «تحليل الأداء» stay with home-hud.js and hud-commands.js. */
(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('go-hud'), source = document.getElementById('home-overview-bootstrap');
    if (!root || !root.classList.contains('hud-v2') || !source) return;
    var boot; try { boot = JSON.parse(source.textContent); } catch (_) { return; }
    var disposed = false, timers = [], removers = [], today = null, week = null, loading = false;
    var number = new Intl.NumberFormat('en-GB', {maximumFractionDigits: 0});
    var $ = function (s) { return root.querySelector(s); };

    function on(t, n, f) { t.addEventListener(n, f); removers.push(function () { t.removeEventListener(n, f); }); }
    function el(tag, text, cls) { var n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (cls) n.className = cls; return n; }
    function pounds(cents) { return typeof cents === 'number' && isFinite(cents) ? number.format(Math.round(cents / 100)) + ' ج' : '—'; }
    function short(cents) { var v = cents / 100; return v >= 1e6 ? (v / 1e6).toFixed(v >= 1e7 ? 0 : 1) + 'M' : v >= 1e3 ? (v / 1e3).toFixed(v >= 1e5 ? 0 : 1) + 'K' : number.format(v); }
    function safeURL(a) { if (typeof a !== 'string' || !a) return null; try { var u = new URL(a, location.href); return u.origin === location.origin ? u.href : null; } catch (_) { return null; } }

    // Date and time.
    var dayFormat = new Intl.DateTimeFormat('ar-EG', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Cairo', numberingSystem: 'latn'});
    var timeFormat = new Intl.DateTimeFormat('ar-EG', {hour: 'numeric', minute: '2-digit', timeZone: 'Africa/Cairo', numberingSystem: 'latn'});
    function clock() { var now = new Date(); $('[data-hv2-day]').textContent = dayFormat.format(now); $('[data-hv2-time]').textContent = timeFormat.format(now); }

    function kpi(key, value, note, tone) {
        var node = $('[data-hv2-kpi="' + key + '"]'); if (!node) return;
        node.querySelector('b').textContent = value; node.querySelector('small').textContent = note || '';
        node.classList.toggle('is-negative', tone === 'negative');
    }

    function renderToday(data) {
        if (!data || !data.sales) return;
        today = data; var s = data.sales, profit = data.profit;
        var open = data.active ? Object.keys(data.active).reduce(function (n, k) { return n + (data.active[k] || 0); }, 0) : 0;
        kpi('orders', number.format(s.count)); kpi('sales', pounds(s.gross_cents)); kpi('expenses', pounds(s.expenses_cents));
        var salesCard = $('[data-hv2-kpi="sales"]'); if (salesCard) salesCard.title = open ? number.format(open) + ' طلب شغال دلوقتي' + (s.delivery_cents ? ' · منها توصيل ' + pounds(s.delivery_cents) : '') : '';
        if (profit && profit.total) {
            var missing = [];
            if (!profit.rent_set) missing.push('الإيجار'); if (!profit.packaging_set) missing.push('التغليف');
            kpi('profit', pounds(profit.total.net_cents), missing.length ? 'ناقص: ' + missing.join(' و') + ' · اضغط وكمّله' : '', profit.total.net_cents < 0 ? 'negative' : '');
        } else kpi('profit', '—', 'محتاج تحديث السيرفر');
        renderBranches(); renderAlerts();
    }

    function renderChart() {
        var box = $('[data-hv2-chart]'); if (!box || !week || !Array.isArray(week.trend)) return;
        var points = week.trend.slice(-7), w = 420, h = 170, pad = {l: 44, r: 12, t: 12, b: 26};
        var max = Math.max.apply(null, points.map(function (p) { return p.amount_cents || 0; }).concat([1]));
        var step = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / step) * step;
        var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h); svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'المبيعات خلال آخر 7 أيام: ' + points.map(function (p) { return p.label + ' ' + pounds(p.amount_cents); }).join('، '));
        function node(tag, attrs, text) { var n = document.createElementNS(ns, tag); Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); if (text) n.textContent = text; svg.appendChild(n); return n; }
        var defs = node('defs', {}), grad = document.createElementNS(ns, 'linearGradient'); grad.id = 'hv2-fill'; grad.setAttribute('x1', 0); grad.setAttribute('y1', 0); grad.setAttribute('x2', 0); grad.setAttribute('y2', 1);
        [['0', '#ff9b22', '.55'], ['1', '#ff9b22', '0']].forEach(function (s) { var stop = document.createElementNS(ns, 'stop'); stop.setAttribute('offset', s[0]); stop.setAttribute('stop-color', s[1]); stop.setAttribute('stop-opacity', s[2]); grad.appendChild(stop); });
        defs.appendChild(grad);
        var x = function (i) { return pad.l + (w - pad.l - pad.r) * (points.length < 2 ? .5 : i / (points.length - 1)); };
        var y = function (v) { return pad.t + (h - pad.t - pad.b) * (1 - v / top); };
        for (var g = 0; g <= 4; g++) { var gv = top * g / 4; node('line', {x1: pad.l, x2: w - pad.r, y1: y(gv), y2: y(gv), class: 'hv2-grid'}); node('text', {x: pad.l - 6, y: y(gv) + 4, class: 'hv2-axis', 'text-anchor': 'end'}, short(gv)); }
        var line = points.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.amount_cents || 0).toFixed(1); }).join(' ');
        node('path', {d: line + ' L' + x(points.length - 1) + ' ' + y(0) + ' L' + x(0) + ' ' + y(0) + 'Z', fill: 'url(#hv2-fill)'});
        node('path', {d: line, class: 'hv2-line'});
        var days = new Intl.DateTimeFormat('ar-EG', {weekday: 'short', timeZone: 'Africa/Cairo'});
        points.forEach(function (p, i) {
            var dot = node('circle', {cx: x(i), cy: y(p.amount_cents || 0), r: i === points.length - 1 ? 5 : 3.5, class: i === points.length - 1 ? 'hv2-dot is-today' : 'hv2-dot'});
            var tip = document.createElementNS(ns, 'title'); tip.textContent = p.label + ': ' + pounds(p.amount_cents); dot.appendChild(tip);
            var label = p.key && /^\d{4}-\d{2}-\d{2}$/.test(p.key) ? days.format(new Date(p.key + 'T12:00:00Z')) : p.label;
            node('text', {x: x(i), y: h - 6, class: 'hv2-axis', 'text-anchor': 'middle'}, label);
        });
        box.replaceChildren(svg);
    }

    function renderBranches() {
        var list = $('[data-hv2-branches]'); if (!list || !today || !Array.isArray(today.branch_cards)) return;
        var averages = {};
        if (week && Array.isArray(week.branch_cards)) week.branch_cards.forEach(function (b) { if (b.sales) averages[b.value] = b.sales.gross_cents / 7; });
        var rows = today.branch_cards.filter(function (b) { return b.sales; }).map(function (b) { return {name: b.name, value: b.value, gross: b.sales.gross_cents, avg: averages[b.value]}; })
            .filter(function (b) { return b.gross > 0 || b.avg > 0; }).sort(function (a, b) { return b.gross - a.gross; }).slice(0, 6);
        var max = Math.max.apply(null, rows.map(function (r) { return r.gross; }).concat([1]));
        list.replaceChildren();
        if (!rows.length) { list.appendChild(el('li', 'لسه مفيش مبيعات النهارده.', 'hv2-empty')); return; }
        rows.forEach(function (r) {
            var li = el('li'), bar = el('span', undefined, 'hv2-bar'), fill = el('i'); fill.style.width = Math.max(3, r.gross / max * 100) + '%'; bar.appendChild(fill);
            li.append(el('span', r.name, 'hv2-branch-name'), bar, el('b', short(r.gross)));
            // The day is not over: how much of a usual day's sales the branch has made so far.
            if (r.avg > 0) { var share = Math.round(r.gross / r.avg * 100); li.appendChild(share >= 100 ? el('span', '▲ +' + (share - 100) + '%', 'hv2-up') : el('span', share + '%', 'hv2-share')); }
            else li.appendChild(el('span', ''));
            list.appendChild(li);
        });
    }

    function renderAlerts() {
        var list = $('[data-hv2-alerts]'); if (!list || !today) return;
        var items = [], names = {};
        (today.branch_cards || []).forEach(function (b) { names[b.value] = b.name; });
        (today.forecasts || []).slice(0, 2).forEach(function (f) {
            var when = f.days < 1 ? 'النهارده' : f.days < 2 ? 'خلال يوم' : 'خلال ' + Math.ceil(f.days) + ' أيام';
            items.push({tone: 'red', text: 'نقص متوقع في ' + f.name + ' - ' + (f.branch_name || names[f.branch] || '') + ' ' + when, url: boot.hud_links && boot.hud_links.stock});
        });
        if (week && today.branch_cards) {
            var best = null;
            week.branch_cards.forEach(function (b) { var t = (today.branch_cards.find(function (x) { return x.value === b.value; }) || {}).sales; if (!t || !b.sales || b.sales.gross_cents <= 0) return; var c = Math.round((t.gross_cents - b.sales.gross_cents / 7) / (b.sales.gross_cents / 7) * 100); if (c >= 15 && (!best || c > best.c)) best = {c: c, name: b.name}; });
            if (best) items.push({tone: 'green', text: 'مبيعات ' + best.name + ' أعلى بـ ' + best.c + '% من متوسط الأسبوع', url: boot.hud_links && boot.hud_links.sales});
        }
        var labels = {pending_expenses: function (a) { return a.value + ' مصروف مستني الاعتماد'; }, late_orders: function (a) { return a.value + ' طلب متأخر أكتر من ساعة ونص'; },
            negative_stock: function (a) { return 'رصيد سالب: ' + a.name + ' - ' + a.branch; }, empty_stock: function (a) { return 'خلص: ' + a.name + ' - ' + a.branch; },
            missing_recipes: function (a) { return a.value + ' صنف في المينيو من غير وصفة'; }, print_pending: function (a) { return a.value + ' طباعة متأخرة'; }};
        (today.alerts || []).forEach(function (a) { if (labels[a.kind]) items.push({tone: a.tone === 'red' ? 'red' : 'amber', text: labels[a.kind](a), url: a.url}); });
        // Three at most, one of each colour first: shortage (red), a branch doing well (green), then the rest.
        var shown = [], rest = [];
        items.forEach(function (a) { (shown.some(function (s) { return s.tone === a.tone; }) ? rest : shown).push(a); });
        shown = shown.concat(rest).slice(0, 3);
        var count = $('[data-hv2-bell-count]'); if (count) { count.textContent = items.length > 9 ? '9+' : String(items.length); count.hidden = !items.length; }
        list.replaceChildren();
        if (!items.length) { list.appendChild(alertItem({tone: 'green', text: 'كله تمام، مفيش حاجة محتاجة انتباهك دلوقتي.'})); return; }
        shown.forEach(function (a) { list.appendChild(alertItem(a)); });
    }

    var alertIcons = {red: 'M12 3 2 20h20Z M12 9v5 M12 17v.5', green: 'M12 20V5 M5 11l7-7 7 7', amber: 'M9 18h6 M10 21h4 M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3'};
    function alertItem(a) {
        var li = el('li', undefined, 'hv2-alert is-' + a.tone), url = safeURL(a.url), body = el(url ? 'a' : 'span', a.text);
        if (url) body.href = url;
        var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg'), path = document.createElementNS(ns, 'path');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); path.setAttribute('d', alertIcons[a.tone] || alertIcons.amber); svg.appendChild(path);
        li.append(body, svg); return li;
    }

    async function load(period) {
        var url = safeURL(boot.url); if (!url) return null;
        url = new URL(url); url.search = ''; url.searchParams.set('period', period);
        var response = await fetch(url.href, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        if (!response.ok) throw Error('overview'); var data = await response.json(); if (!data.success) throw Error('overview'); return data;
    }
    async function refresh(withWeek) {
        if (disposed || loading || root.hidden || document.hidden) return; loading = true;
        try {
            var jobs = [load('today')]; if (withWeek || !week) jobs.push(load('week'));
            var results = await Promise.all(jobs); if (disposed) return;
            if (results[1]) { week = results[1]; renderChart(); }
            renderToday(results[0]);
        } catch (_) { /* The small cards show the freshness state; keep the last figures. */ }
        finally { loading = false; }
    }

    function voice() { var toggle = root.querySelector('[data-hud-commands-toggle]'); if (toggle) { if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click(); var mic = root.querySelector('[data-hud-command-mic]:not([hidden]):not(:disabled)'); var input = root.querySelector('.hud-command-input'); if (mic) mic.focus(); else if (input) input.focus(); } }
    function dispose() { if (disposed) return; disposed = true; timers.forEach(clearInterval); removers.forEach(function (r) { r(); }); }

    var initial = boot.initial || {};
    if (initial.filters && initial.filters.period === 'today' && !initial.filters.branch) renderToday(initial);
    clock(); timers.push(setInterval(clock, 15000));
    timers.push(setInterval(function () { refresh(false); }, 60000));
    timers.push(setInterval(function () { refresh(true); }, 600000));
    on(document, 'visibilitychange', function () { if (!document.hidden) refresh(false); });
    on(document, 'go:home-data', function (e) { var d = e.detail; if (d && d.filters && d.filters.period === 'today' && !d.filters.branch) renderToday(d); });
    var voiceButton = $('[data-hv2-voice]'); if (voiceButton) on(voiceButton, 'click', voice);
    var bell = $('[data-hv2-bell]'), alertsPanel = $('#hv2-alerts');
    if (bell && alertsPanel) on(bell, 'click', function () { alertsPanel.scrollIntoView({block: 'nearest', behavior: 'smooth'}); alertsPanel.focus({preventScroll: true}); alertsPanel.classList.remove('is-flash'); void alertsPanel.offsetWidth; alertsPanel.classList.add('is-flash'); });
    on(document, 'dashboard:before-unload', dispose); on(window, 'pagehide', dispose);
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(dispose);
    refresh(true);
})();
