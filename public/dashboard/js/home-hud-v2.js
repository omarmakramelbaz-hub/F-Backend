/* The owner's GO home: today's figures, net profit, the week's sales, the branches today, alerts,
   drawer balances, attendance, stock health and the day's top items.
   The eight live tiles, the command bar and «تحليل الأداء» stay with home-hud.js and hud-commands.js. */
(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('go-hud'), source = document.getElementById('home-overview-bootstrap');
    if (!root || !root.classList.contains('hud-v2') || !source) return;
    var boot; try { boot = JSON.parse(source.textContent); } catch (_) { return; }
    var disposed = false, timers = [], removers = [], today = null, week = null, month = null, loading = false, branch = '', epoch = 0;
    var drawers = null, summary = null, alertTotal = 0;
    var number = new Intl.NumberFormat('en-GB', {maximumFractionDigits: 0});
    var $ = function (s) { return root.querySelector(s); };
    var links = boot.hud_links || {};

    function on(t, n, f) { t.addEventListener(n, f); removers.push(function () { t.removeEventListener(n, f); }); }
    function el(tag, text, cls) { var n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (cls) n.className = cls; return n; }
    function pounds(cents) { return typeof cents === 'number' && isFinite(cents) ? number.format(Math.round(cents / 100)) + ' ج' : '—'; }
    function short(cents) { var v = cents / 100, a = Math.abs(v); return a >= 1e6 ? (v / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M' : a >= 1e3 ? (v / 1e3).toFixed(a >= 1e5 ? 0 : 1) + 'K' : number.format(v); }
    function safeURL(a) { if (typeof a !== 'string' || !a) return null; try { var u = new URL(a, location.href); return u.origin === location.origin ? u.href : null; } catch (_) { return null; } }
    function svgIcon(d, cls) { var ns = 'http://www.w3.org/2000/svg', s = document.createElementNS(ns, 'svg'), p = document.createElementNS(ns, 'path'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); if (cls) s.setAttribute('class', cls); p.setAttribute('d', d); s.appendChild(p); return s; }

    // Date and time.
    var dayFormat = new Intl.DateTimeFormat('ar-EG', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Cairo', numberingSystem: 'latn'});
    var timeFormat = new Intl.DateTimeFormat('ar-EG', {hour: 'numeric', minute: '2-digit', timeZone: 'Africa/Cairo', numberingSystem: 'latn'});
    function clock() { var now = new Date(); $('[data-hv2-day]').textContent = dayFormat.format(now); $('[data-hv2-time]').textContent = timeFormat.format(now); }

    function kpi(key, value, note, tone) {
        var node = $('[data-hv2-kpi="' + key + '"]'); if (!node) return;
        node.querySelector('b').textContent = value;
        var small = node.querySelector('small'); small.replaceChildren();
        if (note) (Array.isArray(note) ? note : [note]).forEach(function (part) { small.append(typeof part === 'string' ? document.createTextNode(part) : part); });
        node.classList.toggle('is-negative', tone === 'negative');
    }
    // Yesterday's whole day, shown as a figure: the day is not over, so a percentage would mislead.
    function yesterday(cents, isCount) {
        if (typeof cents !== 'number' || !isFinite(cents)) return '';
        return 'أمس: ' + (isCount ? number.format(cents) : pounds(cents));
    }

    function renderToday(data) {
        if (!data || !data.sales) return;
        today = data; var s = data.sales, profit = data.profit, prev = data.previous || {};
        kpi('orders', number.format(s.count), yesterday(prev.count, true));
        kpi('sales', pounds(s.gross_cents), yesterday(prev.gross_cents));
        kpi('expenses', pounds(s.expenses_cents), yesterday(prev.expenses_cents));
        if (profit && profit.total) {
            var net = profit.total.net_cents;
            var note = [s.gross_cents > 0 ? el('span', Math.round(net / s.gross_cents * 100) + '% من المبيعات', net >= 0 ? 'up' : 'down') : ''];
            if (profit.goods_coverage != null && profit.goods_coverage < 95) note.push(el('span', 'تكلفة البضاعة متسجلة لـ ' + Math.round(profit.goods_coverage) + '% بس من المبيع', 'hv2-month down'));
            var mp = monthProfit(); if (mp) note.push(el('span', 'من أول الشهر ' + pounds(mp.net) + (mp.pct === null ? '' : ' · ' + mp.pct + '%'), 'hv2-month' + (mp.net < 0 ? ' down' : '')));
            kpi('profit', pounds(net), note, net < 0 ? 'negative' : '');
        } else kpi('profit', '—', branch ? 'لكل الفروع بس' : 'محتاج تحديث السيرفر');
        renderBranches(); renderAlerts(); renderStock(); renderSummary(); renderDrawers();
    }

    // Month to date, from the first of the month through today, on the same sales and costs as today's figure.
    function monthProfit() {
        if (!month || !month.profit || !month.profit.total || !month.sales) return null;
        var net = month.profit.total.net_cents, gross = month.sales.gross_cents;
        return {net: net, pct: gross > 0 ? Math.round(net / gross * 100) : null};
    }
    function renderSummary() {
        var list = $('[data-hv3-summary]'); if (!list || !today || !today.sales) return;
        var s = today.sales, rows = [[number.format(s.count) + ' طلبًا حتى الآن'], ['مبيعات ' + pounds(s.gross_cents)]];
        if (today.profit && today.profit.total) { var mp = monthProfit(); rows.push(['صافي الربح ' + pounds(today.profit.total.net_cents) + (mp ? ' · من أول الشهر ' + pounds(mp.net) : '')]); }
        else rows.push(['مصروفات ' + pounds(s.expenses_cents)]);
        var cash = cashCents(); if (cash !== null) rows.push(['رصيد النقدية ' + pounds(cash)]);
        rows.push(alertTotal ? [alertTotal + (alertTotal > 10 ? ' تنبيه' : alertTotal > 2 ? ' تنبيهات' : ' تنبيه') + ' تحتاج مراجعة', 'is-warn'] : ['مفيش تنبيهات محتاجة مراجعة']);
        list.replaceChildren.apply(list, rows.map(function (r) { var li = el('li', undefined, r[1]); li.append(el('i'), el('span', r[0])); return li; }));
    }

    function renderChart() {
        var box = $('[data-hv2-chart]'); if (!box || !week || !Array.isArray(week.trend)) return;
        var points = week.trend.slice(-7), w = 460, h = 210, pad = {l: 50, r: 22, t: 40, b: 26};
        var max = Math.max.apply(null, points.map(function (p) { return p.amount_cents || 0; }).concat([1]));
        var step = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / step) * step;
        var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h); svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'المبيعات خلال آخر 7 أيام: ' + points.map(function (p) { return p.label + ' ' + pounds(p.amount_cents); }).join('، '));
        function node(tag, attrs, text, parent) { var n = document.createElementNS(ns, tag); Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); if (text) n.textContent = text; (parent || svg).appendChild(n); return n; }
        var defs = node('defs', {}), grad = node('linearGradient', {id: 'hv2-fill', x1: 0, y1: 0, x2: 0, y2: 1}, null, defs);
        [['0', '#ff9b22', '.75'], ['1', '#ff6a00', '.05']].forEach(function (s) { node('stop', {offset: s[0], 'stop-color': s[1], 'stop-opacity': s[2]}, null, grad); });
        var x = function (i) { return pad.l + (w - pad.l - pad.r) * (points.length < 2 ? .5 : i / (points.length - 1)); };
        var y = function (v) { return pad.t + (h - pad.t - pad.b) * (1 - v / top); };
        for (var g = 0; g <= 4; g++) { var gv = top * g / 4; node('line', {x1: pad.l, x2: w - pad.r, y1: y(gv), y2: y(gv), class: 'hv2-grid'}); node('text', {x: pad.l - 8, y: y(gv) + 4, class: 'hv2-axis', 'text-anchor': 'end'}, short(gv)); }
        var line = points.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.amount_cents || 0).toFixed(1); }).join(' ');
        node('path', {d: line + ' L' + x(points.length - 1) + ' ' + y(0) + ' L' + x(0) + ' ' + y(0) + 'Z', fill: 'url(#hv2-fill)'});
        node('path', {d: line, class: 'hv2-line'});
        var days = new Intl.DateTimeFormat('ar-EG', {weekday: 'long', timeZone: 'Africa/Cairo'});
        points.forEach(function (p, i) {
            var last = i === points.length - 1, dot = node('circle', {cx: x(i), cy: y(p.amount_cents || 0), r: last ? 6 : 4.5, class: last ? 'hv2-dot is-today' : 'hv2-dot'});
            node('title', {}, p.label + ': ' + pounds(p.amount_cents), dot);
            var label = p.key && /^\d{4}-\d{2}-\d{2}$/.test(p.key) ? days.format(new Date(p.key + 'T12:00:00Z')) : p.label;
            node('text', {x: x(i), y: h - 6, class: 'hv2-axis', 'text-anchor': 'middle'}, label);
        });
        // The last day's figure in a bubble above its point, as in the mockup.
        var lastPoint = points[points.length - 1];
        if (lastPoint) {
            var text = pounds(lastPoint.amount_cents), bw = text.length * 8.6 + 18, bx = Math.min(w - pad.r - bw / 2, Math.max(pad.l + bw / 2, x(points.length - 1) - bw / 2 + 6)), by = Math.max(2, y(lastPoint.amount_cents || 0) - 38);
            var bubble = node('g', {class: 'hv3-bubble'});
            node('rect', {x: bx - bw / 2, y: by, width: bw, height: 26, rx: 9}, null, bubble);
            node('text', {x: bx, y: by + 18, 'text-anchor': 'middle'}, text, bubble);
        }
        box.replaceChildren(svg);
    }

    function renderBranches() {
        var list = $('[data-hv2-branches]'); if (!list || !today || !Array.isArray(today.branch_cards)) return;
        var art = (list.dataset.storeArt || '').split('|').filter(Boolean), averages = {};
        if (week && Array.isArray(week.branch_cards)) week.branch_cards.forEach(function (b) { if (b.sales) averages[b.value] = b.sales.gross_cents / 7; });
        var total = today.sales ? today.sales.gross_cents : 0;
        var rows = today.branch_cards.filter(function (b) { return b.sales; }).map(function (b) { return {name: b.name, value: b.value, gross: b.sales.gross_cents, count: b.sales.count || 0, avg: averages[b.value]}; })
            .filter(function (b) { return b.gross > 0 || b.avg > 0; }).sort(function (a, b) { return b.gross - a.gross; }).slice(0, 4);
        list.replaceChildren();
        if (!rows.length) { list.appendChild(el('li', 'لسه مفيش مبيعات النهارده.', 'hv2-empty')); return; }
        rows.forEach(function (r, i) {
            var li = el('li', undefined, 'hv3-store'), img = el('img');
            img.src = art[i % art.length] || ''; img.alt = ''; img.width = 128; img.height = 56; img.loading = 'lazy';
            var money = el('span', undefined, 'hv3-store-money'); money.append(el('span', short(r.gross) + ' ج'));
            if (total > 0) money.appendChild(el('em', Math.round(r.gross / total * 100) + '%'));
            money.title = 'نصيب الفرع من مبيعات النهارده';
            var orders = el('span', 'طلب', 'hv3-store-orders'); orders.appendChild(el('b', number.format(r.count)));
            li.append(img, el('strong', r.name), money, orders);
            // The day is not over: how much of a usual day's sales the branch has made so far.
            if (r.avg > 0) {
                var share = Math.round(r.gross / r.avg * 100), ring = el('span', undefined, 'hv3-ring' + (share >= 80 ? '' : share >= 50 ? ' is-mid' : ' is-low'));
                ring.style.setProperty('--p', Math.min(100, share)); ring.appendChild(el('span', share + '%')); ring.title = 'من متوسط يوم كامل في آخر 7 أيام';
                li.appendChild(ring);
            }
            list.appendChild(li);
        });
    }

    function renderAlerts() {
        var list = $('[data-hv2-alerts]'); if (!list || !today) return;
        var items = [], names = {};
        (today.branch_cards || []).forEach(function (b) { names[b.value] = b.name; });
        (today.forecasts || []).slice(0, 2).forEach(function (f) {
            var when = f.days < 1 ? 'النهارده' : f.days < 2 ? 'خلال يوم' : 'خلال ' + Math.ceil(f.days) + ' أيام';
            items.push({tone: 'red', text: 'نقص متوقع في ' + f.name + ' - ' + (f.branch_name || names[f.branch] || ''), sub: when, url: links.stock});
        });
        var stock = stockCounts();
        if (stock && stock.low) items.push({tone: 'amber', icon: 'box', text: 'مخزون منخفض (' + number.format(stock.low + stock.out) + ' صنف)', sub: stock.out ? 'منها ' + number.format(stock.out) + ' خلصت أو بالسالب' : '', url: links.stock});
        if (week && today.branch_cards) {
            var best = null;
            week.branch_cards.forEach(function (b) { var t = (today.branch_cards.find(function (x) { return x.value === b.value; }) || {}).sales; if (!t || !b.sales || b.sales.gross_cents <= 0) return; var c = Math.round((t.gross_cents - b.sales.gross_cents / 7) / (b.sales.gross_cents / 7) * 100); if (c >= 15 && (!best || c > best.c)) best = {c: c, name: b.name}; });
            if (best) items.push({tone: 'green', text: 'مبيعات ' + best.name + ' أعلى بـ' + best.c + '%', sub: 'من متوسط الأسبوع', url: links.sales});
        }
        var labels = {pending_expenses: function (a) { return a.value + ' مصروف مستني الاعتماد'; }, late_orders: function (a) { return a.value + ' طلب متأخر أكتر من ساعة ونص'; },
            missing_recipes: function (a) { return a.value + ' صنف في المينيو من غير وصفة'; }, print_pending: function (a) { return a.value + ' طباعة متأخرة'; }};
        (today.alerts || []).forEach(function (a) { if (labels[a.kind]) items.push({tone: a.tone === 'red' ? 'red' : 'amber', text: labels[a.kind](a), url: a.url}); });
        // Three at most, one of each colour first: shortage (red), stock (amber), a branch doing well (green), then the rest.
        var shown = [], rest = [];
        items.forEach(function (a) { (shown.some(function (s) { return s.tone === a.tone; }) ? rest : shown).push(a); });
        shown = shown.concat(rest).slice(0, 3);
        var warnings = items.filter(function (a) { return a.tone !== 'green'; }).length; alertTotal = warnings;
        [$('[data-hv2-bell-count]'), $('[data-hv3-alert-count]')].forEach(function (count) { if (count) { count.textContent = warnings > 9 ? '9+' : String(warnings); count.hidden = !warnings; } });
        list.replaceChildren();
        if (!items.length) { list.appendChild(alertItem({tone: 'green', text: 'كله تمام، مفيش حاجة محتاجة انتباهك دلوقتي.'})); return; }
        shown.forEach(function (a) { list.appendChild(alertItem(a)); });
    }

    var alertIcons = {red: 'M12 3 2 20h20Z M12 9v5 M12 17v.5', green: 'M4 20 20 4 M11 4h9v9', amber: 'M9 18h6 M10 21h4 M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3',
        box: 'm12 2 9 5v10l-9 5-9-5V7z M3 7l9 5 9-5 M12 12v10'};
    function alertItem(a) {
        var li = el('li', undefined, 'hv2-alert is-' + a.tone), url = safeURL(a.url), body = el(url ? 'a' : 'span', a.text);
        if (url) body.href = url;
        if (a.sub) body.appendChild(el('small', a.sub));
        li.append(body, svgIcon(alertIcons[a.icon || a.tone] || alertIcons.amber)); return li;
    }

    function stockCounts() {
        var inv = today && today.inventory; if (!inv || !Array.isArray(inv.items) || !inv.items.length) return null;
        var out = 0, low = 0;
        inv.items.forEach(function (i) { if ((i.quantity_units || 0) <= 0) out++; else if (i.is_low) low++; });
        return {total: inv.items.length, out: out, low: low, safe: inv.items.length - out - low};
    }
    function renderStock() {
        var box = $('[data-hv3-stock]'); if (!box || !today) return;
        var c = stockCounts();
        if (!c) { box.replaceChildren(el('p', 'المخزون مش متسجل للفروع دي.', 'hv3-muted')); return; }
        var donut = el('div', undefined, 'hv3-donut'), safe = Math.round(c.safe / c.total * 100);
        donut.style.setProperty('--safe', c.safe / c.total * 100); donut.style.setProperty('--low', c.low / c.total * 100);
        donut.append(el('b', safe + '%'), el('small', 'متوفر'));
        donut.setAttribute('role', 'img'); donut.setAttribute('aria-label', safe + '% من الأصناف متوفرة');
        var rows = el('div', undefined, 'hv3-stock-rows');
        [['is-safe', 'أصناف آمنة', c.safe], ['is-low', 'أصناف قليلة', c.low], ['is-out', 'أصناف ناقصة', c.out]].forEach(function (r) {
            var row = el('div', undefined, 'hv3-stock-row ' + r[0]); row.append(el('i'), el('span', r[1]), el('b', number.format(r[2]))); rows.appendChild(row);
        });
        var link = safeURL(links.stock), wrap = link ? el('a') : el('div'); if (link) { wrap.href = link; wrap.style.cssText = 'display:contents;color:inherit;text-decoration:none'; }
        wrap.append(rows, donut); box.replaceChildren(wrap);
    }

    // Cash in the drawers: the chosen branch's drawer, or all drawers together.
    function cashCents() {
        if (!drawers || !drawers.ready) return null;
        if (!branch) return typeof drawers.total_cents === 'number' ? drawers.total_cents : null;
        var row = (drawers.branches || {})[branch];
        if (row && typeof row.expected_cents === 'number') return row.expected_cents;
        var names = {}; ((today && today.branch_cards) || (boot.initial && boot.initial.branches) || []).forEach(function (b) { names[b.value] = b.name; });
        var key = Object.keys(drawers.branches || {}).filter(function (k) { return names[k] === names[branch]; })[0];
        return key && typeof drawers.branches[key].expected_cents === 'number' ? drawers.branches[key].expected_cents : null;
    }

    function renderDrawers() {
        var box = $('[data-hv3-drawers]'); if (!box) return;
        if (!drawers) return;
        if (!drawers.ready) { box.replaceChildren(el('dt', 'الأدراج مش متاحة دلوقتي.')); return; }
        var names = {}; ((today && today.branch_cards) || (boot.initial && boot.initial.branches) || []).forEach(function (b) { names[b.value] = b.name; });
        var rows = Object.keys(drawers.branches || {}).map(function (k) { return {name: names[k] || k, cents: (drawers.branches[k] || {}).expected_cents}; })
            .filter(function (r) { return typeof r.cents === 'number' && (!branch || r.name === names[branch]); }).sort(function (a, b) { return b.cents - a.cents; });
        var list = rows.filter(function (r) { return r.cents !== 0; }).slice(0, rows.length > 4 ? 3 : 4);
        box.replaceChildren();
        if (!list.length) { box.appendChild(el('dt', 'كل الأدراج فاضية.')); return; }
        list.forEach(function (r) { box.append(el('dt', r.name), el('dd', pounds(r.cents))); });
        if (rows.length > 1 && !branch) { box.append(el('dt', 'الإجمالي'), el('dd', pounds(drawers.total_cents))); box.lastChild.previousSibling.style.color = '#ffc04d'; box.lastChild.style.color = '#ffc04d'; }
    }

    function renderOwnerSummary() {
        var att = $('[data-hv3-attendance]'), top = $('[data-hv3-top]');
        if (!summary) {
            if (att) att.replaceChildren(el('p', 'الحضور هيظهر بعد تحديث السيرفر.', 'hv3-muted'));
            if (top) top.replaceChildren(el('li', 'الأصناف هتظهر بعد تحديث السيرفر.', 'hv3-muted'));
            return;
        }
        var a = summary.attendance || {};
        if (att) {
            if (!a.available) att.replaceChildren(el('p', 'الحضور مش متسجل للفروع دي.', 'hv3-muted'));
            else {
                var total = el('div', undefined, 'hv3-att-total'); total.append(svgIcon('M9 8a3 3 0 1 0 0-.1 M3 20v-1a6 6 0 0 1 12 0v1 M17 5a3 3 0 0 1 0 6 M21 20v-1a5 5 0 0 0-4-5 M3 8a2.5 2.5 0 0 1 2-3.5'), el('b', number.format(a.total || 0)), el('small', 'موظف'));
                var rows = el('div', undefined, 'hv3-att-rows');
                [['is-present', '✓', 'حاضر', a.present], ['is-absent', '✕', 'غياب', a.absent], ['is-late', '◷', 'تأخير', a.late]].concat(a.leave ? [['is-leave', '•', 'إجازة', a.leave]] : []).forEach(function (r) {
                    var row = el('div', undefined, 'hv3-att-row ' + r[0]); row.append(el('i', r[1]), el('span', r[2]), el('b', number.format(r[3] || 0))); rows.appendChild(row);
                });
                if (a.unrecorded) rows.title = number.format(a.unrecorded) + ' لسه محدش سجّل حضورهم';
                var link = safeURL(links.employees), wrap = link ? el('a') : el('div'); if (link) { wrap.href = link; wrap.style.cssText = 'display:contents;color:inherit;text-decoration:none'; }
                wrap.append(rows, total); att.replaceChildren(wrap);
            }
        }
        var p = summary.top_products || {};
        if (top) {
            var items = Array.isArray(p.items) ? p.items.slice(0, 3) : [];
            if (!p.available || !items.length) top.replaceChildren(el('li', 'لسه مفيش فواتير مربوطة بالمخزون النهارده.', 'hv3-muted'));
            else top.replaceChildren.apply(top, items.map(function (item, i) {
                var li = el('li'), count = el('b', number.format(item.sales_count)); count.appendChild(el('small', 'طلب'));
                li.append(el('i', String(i + 1)), el('span', item.name), count); return li;
            }));
        }
    }

    function address(params) {
        var url = safeURL(boot.url); if (!url) return null;
        url = new URL(url); url.search = ''; Object.keys(params).forEach(function (k) { if (params[k]) url.searchParams.set(k, params[k]); }); return url.href;
    }
    async function get(params) {
        var url = address(params); if (!url) throw Error('overview');
        var response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        if (!response.ok) throw Error('overview'); var data = await response.json(); if (!data.success) throw Error('overview'); return data;
    }
    async function refresh(withWeek) {
        if (disposed || loading || root.hidden || document.hidden) return; loading = true; var mine = epoch;
        try {
            var wantWeek = withWeek || !week, wantMonth = withWeek || !month;
            var jobs = [get({period: 'today', branch: branch}), wantWeek ? get({period: 'week', branch: branch}) : null, wantMonth ? get({period: 'month', branch: branch}).catch(function () { return null; }) : null];
            var results = await Promise.all(jobs); if (disposed || mine !== epoch) return;
            if (results[1]) { week = results[1]; renderChart(); }
            if (results[2]) month = results[2];
            renderToday(results[0]);
        } catch (_) { /* The tiles show the freshness state; keep the last figures. */ }
        finally { loading = false; }
        loadSummary();
    }
    async function loadDrawers() {
        if (disposed || root.hidden || document.hidden || !boot.initial || !('owner_drawer' in boot.initial)) return;
        try { var d = await get({section: 'drawers'}); if (!disposed && d.owner_drawer) { drawers = d.owner_drawer; renderDrawers(); renderSummary(); } } catch (_) {}
    }
    async function loadSummary() {
        if (disposed || root.hidden || document.hidden) return; var mine = epoch;
        try {
            var url = address({section: 'owner-summary', period: 'today', branch: branch}); if (!url) return;
            var response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            var data = response.ok ? await response.json() : null;
            if (disposed || mine !== epoch) return;
            // An older server answers this with the whole overview: no attendance key, nothing to show yet.
            summary = data && data.success && data.attendance ? data : null; renderOwnerSummary();
        } catch (_) {}
    }

    function voice() { var toggle = root.querySelector('[data-hud-commands-toggle]') || root.querySelector('.hud-command-toggle'); if (toggle) { if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click(); var mic = root.querySelector('[data-hud-command-mic]:not([hidden]):not(:disabled)'); var input = root.querySelector('.hud-command-input'); if (mic) mic.focus(); else if (input) input.focus(); } }
    function dispose() { if (disposed) return; disposed = true; timers.forEach(clearInterval); removers.forEach(function (r) { r(); }); }

    var initial = boot.initial || {};
    if (initial.filters && initial.filters.period === 'today' && !initial.filters.branch) renderToday(initial);
    clock(); timers.push(setInterval(clock, 15000));
    timers.push(setInterval(function () { refresh(false); }, 60000));
    timers.push(setInterval(function () { refresh(true); }, 600000));
    timers.push(setInterval(loadDrawers, 120000));
    on(document, 'visibilitychange', function () { if (!document.hidden) { refresh(false); loadDrawers(); } });
    on(document, 'go:home-data', function (e) { var d = e.detail; if (!branch && d && d.filters && d.filters.period === 'today' && !d.filters.branch) renderToday(d); });
    var select = $('[data-hv3-branch]');
    function pickBranch() { if (select.value === branch) return; branch = select.value; epoch++; week = null; month = null; loading = false; renderDrawers(); refresh(true); }
    if (select) {
        on(select, 'change', pickBranch);
        // The dashboard wraps selects in select2, whose choice reaches jQuery handlers only.
        if (window.jQuery) { window.jQuery(select).on('change.hv3', pickBranch); removers.push(function () { window.jQuery(select).off('change.hv3'); }); }
        // select2 draws its list at the end of <body>; tag it so it takes the home's look.
        if (window.jQuery) {
            var tagList = function () { var s2 = window.jQuery(select).data('select2'); if (s2 && s2.$dropdown) s2.$dropdown.addClass('hv3-branch-list'); };
            window.jQuery(select).on('select2:open.hv3', tagList); tagList();
            removers.push(function () { window.jQuery(select).off('select2:open.hv3'); });
        }
    }
    var voiceButton = $('[data-hv2-voice]'); if (voiceButton) on(voiceButton, 'click', voice);
    var bell = $('[data-hv2-bell]'), alertsPanel = $('#hv2-alerts');
    if (bell && alertsPanel) on(bell, 'click', function () { alertsPanel.scrollIntoView({block: 'nearest', behavior: 'smooth'}); alertsPanel.focus({preventScroll: true}); alertsPanel.classList.remove('is-flash'); void alertsPanel.offsetWidth; alertsPanel.classList.add('is-flash'); });
    var quick = $('.hv3-quick'); if (quick) on(document, 'click', function (e) { if (quick.open && !quick.contains(e.target)) quick.open = false; });
    on(document, 'dashboard:before-unload', dispose); on(window, 'pagehide', dispose);
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(dispose);
    refresh(true); loadDrawers();
})();
