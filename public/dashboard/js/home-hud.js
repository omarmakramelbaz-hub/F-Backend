(function () {
    'use strict';
    // GO live control room on the home page. It reads the same data as the page it sits on
    // (owner/admin overview or branch home) and follows that page's refreshes.
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('go-hud'); if (!root) return;
    var source = document.getElementById('home-overview-bootstrap') || document.getElementById('branch-home-bootstrap'); if (!source) return;
    var boot = JSON.parse(source.textContent), data = boot.initial || {};
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var canvas = root.querySelector('canvas.hud-back'), ctx = canvas.getContext('2d'), frontCanvas = root.querySelector('canvas.hud-front'), fctx = frontCanvas.getContext('2d');
    var core = root.querySelector('[data-hud-core]'), nodesBox = root.querySelector('[data-hud-nodes]'), checksBox = root.querySelector('[data-hud-checks]');
    var tally = root.querySelector('[data-hud-tally]'), statusText = root.querySelector('[data-hud-status]'), skip = root.querySelector('[data-hud-skip]');
    var W = 0, H = 0, stars = [], links = [], particles = [], timers = [], start = performance.now(), revealAt = reduce ? -10 : Infinity, frame = 0, booting = false, desktopMode = '', orbitMax = 300;
    // The solar system around the GO mark: share of the orbit band, size, seconds per turn, start angle, lit and shaded colour.
    var PLANETS = [
        {f: 0, size: 3.4, period: 9, phase: .3, c: ['#e2ddd8', '#857e77']},
        {f: .14, size: 5.2, period: 14, phase: 2.1, c: ['#fff0c0', '#c4933f']},
        {f: .29, size: 5.6, period: 19, phase: 4.0, c: ['#a6dbff', '#1d5cbd'], land: true},
        {f: .43, size: 4.4, period: 26, phase: 1.2, c: ['#ffb08a', '#a3341c']},
        {f: .6, size: 11.5, period: 42, phase: 5.1, c: ['#f7dfb8', '#a8703f'], bands: true},
        {f: .74, size: 9.5, period: 58, phase: 3.3, c: ['#fbe7b4', '#b58d4a'], ring: true},
        {f: .87, size: 7.4, period: 76, phase: .9, c: ['#dafcff', '#47b2c0']},
        {f: 1, size: 7.2, period: 96, phase: 2.7, c: ['#aec7ff', '#2445b3']}
    ], TILT = -0.14, flat = .34;
    var numbers = new Intl.NumberFormat('ar-EG-u-nu-latn', {maximumFractionDigits: 0});
    var CHANNELS = {takeaway: 'تيك أواي', dine: 'الصالة', phone: 'الدليفري', app: 'التطبيق'};
    var PERIODS = {today: 'اليوم', week: 'آخر 7 أيام', month: 'هذا الشهر'};

    function el(tag, text, css) { var n = document.createElement(tag); if (text !== undefined) n.textContent = String(text); if (css) n.className = css; return n; }
    function num(v) { return v === null || v === undefined ? '—' : numbers.format(v); }
    function money(cents) { return cents === null || cents === undefined ? '—' : numbers.format(Math.round(cents / 100)) + ' ج'; }
    function sum(o) { var t = 0; Object.keys(o || {}).forEach(function (k) { t += Number(o[k]) || 0; }); return t; }
    function alert(kind) { return (data.alerts || []).filter(function (a) { return a.kind === kind; })[0]; }
    function alertsLike(prefix) { return (data.alerts || []).filter(function (a) { return String(a.kind).indexOf(prefix) === 0; }); }

    function account() {
        var d = data, cards = d.branch_cards || [], branch = d.filters && d.filters.branch;
        var picked = branch ? (d.branches || []).filter(function (b) { return b.value === branch; })[0] : null;
        var who = d.branch_home ? ((picked || cards[0] || {}).name || 'الفرع') : (picked ? picked.name : 'نظرة شاملة على الفروع');
        var period = d.filters ? (PERIODS[d.filters.period] || (d.filters.from + ' — ' + d.filters.to)) : '';
        var day = d.operating_day ? 'يوم التشغيل ' + d.operating_day.date : '';
        return {who: who, day: period && period !== 'اليوم' ? day + ' · ' + period : day};
    }

    function checks() {
        var d = data, list = [], m = d.modules || {}, active = sum(d.active);
        var at = d.updated_at ? new Date(d.updated_at) : null;
        list.push(['البيانات', at && !isNaN(at) ? 'محدّثة ' + at.toLocaleTimeString('ar-EG-u-nu-latn', {hour: '2-digit', minute: '2-digit'}) : 'محدّثة', 0]);
        if (d.operating_day) list.push(['يوم التشغيل', d.operating_day.date, 0]);
        if (!d.branch_home || d.selected_branches > 1) list.push(['الفروع المفتوحة', num(d.open_branches) + ' من ' + num(d.selected_branches), d.open_branches === 0 ? 1 : 0]);
        list.push(['الطلبات النشطة', num(active), 0]);
        if (m.app) { var late = alert('late_orders'); list.push(['طلبات متأخرة', num(late ? late.value : 0), late ? 1 : 0]); }
        if (m.inventory) { var stock = alertsLike('negative_stock').length + alertsLike('empty_stock').length; list.push(['تنبيهات المخزون', stock ? num(stock) + ' صنف' : 'سليم', stock ? 1 : 0]); }
        if (m.expenses) { var pending = alert('pending_expenses'); list.push(['مصروفات منتظرة', num(pending ? pending.value : 0), pending ? 1 : 0]); }
        var print = alert('print_pending'); list.push(['الطباعة', print ? num(print.value) + ' معلّقة' : 'جاهزة', print ? 1 : 0]);
        var recipes = alert('missing_recipes'); if (recipes) list.push(['وصفات ناقصة', num(recipes.value), 1]);
        alertsLike('unavailable_').forEach(function (a) { list.push(['قسم غير متاح', a.kind.replace('unavailable_', ''), 1]); });
        if (desktopMode) list.push(['برنامج الكمبيوتر', desktopMode, 0]);
        return list;
    }

    function nodes() {
        var d = data, list = [], m = d.modules || {}, finance = d.can_view_financials && d.sales;
        if (finance) list.push(['sales', d.branch_home ? 'مبيعات الفرع' : 'المبيعات', money(d.sales.gross_cents), 'money']);
        if (d.owner_drawer && d.owner_drawer.ready) list.push(['drawer', 'أدراج الفروع', money(d.owner_drawer.total_cents), 'money']);
        (d.channels || []).forEach(function (c) { if (CHANNELS[c.key]) list.push(['ch-' + c.key, CHANNELS[c.key], num(c.count), '']); });
        list.push(['active', 'الطلبات النشطة', num(sum(d.active)), '']);
        if (!d.branch_home) list.push(['branches', 'الفروع المفتوحة', num(d.open_branches) + ' من ' + num(d.selected_branches), '']);
        if (m.inventory) { var att = (d.inventory && d.inventory.attention || []).length; list.push(['stock', 'المخزون', att ? num(att) + ' تنبيه' : 'سليم', att ? 'warn' : '']); }
        if (d.today_expenses && d.today_expenses.total_cents !== null) list.push(['expenses-today', 'مصروفات اليوم', money(d.today_expenses.total_cents), '']);
        else if (m.expenses) { var pe = alert('pending_expenses'); list.push(['expenses', 'المصروفات', pe ? num(pe.value) + ' منتظرة' : 'لا يوجد منتظر', pe ? 'warn' : '']); }
        if (d.cancelled) list.push(['cancelled', 'ملغي', num(d.cancelled), 'warn']);
        if (d.customers && !d.branch_home) list.push(['customers', 'العملاء', num(d.customers.total), '']);
        var p = d.owner_platform;
        if (p) {
            if (p.go_stores !== null && p.go_stores !== undefined) list.push(['go-stores', 'متاجر GO', num(p.go_stores), '']);
            if (p.go_partners !== null && p.go_partners !== undefined) list.push(['go-partners', 'شركاء GO', num(p.go_partners), '']);
            if (p.pending_total) list.push(['pending', 'طلبات الانضمام', num(p.pending_total), 'warn']);
        }
        // A phone shows the eight most important sections; a wide screen up to twelve.
        return list.slice(0, W && W < 760 ? 8 : 12);
    }

    // ---- drawing ----
    function centre() { return {x: core.offsetLeft, y: core.offsetTop, r: core.offsetWidth / 2}; }
    function size() {
        var ratio = Math.min(2, window.devicePixelRatio || 1); W = root.clientWidth; H = root.clientHeight;
        canvas.width = frontCanvas.width = W * ratio; canvas.height = frontCanvas.height = H * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0); fctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        stars = []; for (var i = 0; i < Math.round(W * H / 5200); i++) stars.push({x: Math.random() * W, y: Math.random() * H, r: Math.random() * 1.3 + .2, t: Math.random() * 6});
        if (!booting && nodesBox.children.length && nodesBox.children.length !== nodes().length) fillNodes(false);
        layout();
    }
    function layout() {
        var narrow = W < 760, panel = narrow ? 0 : checksBox.parentNode.offsetWidth + 28, side = (W - panel) / 2;
        // On wide screens the core sits in the space beside the check panel, so no section runs under it.
        core.style.left = narrow ? '' : side + 'px';
        var c = centre(), els = nodesBox.children, n = els.length;
        var rx = narrow ? Math.min(W * .39, 200) : Math.max(160, Math.min(side - 150, 470)), ry = narrow ? 200 : Math.min(H * .34, 260);
        flat = narrow ? .5 : .34;
        orbitMax = narrow ? Math.max(c.r * 1.5, rx * .92) : Math.min(c.r * 3.7, rx * .86);
        links = [];
        for (var i = 0; i < n; i++) {
            var a = -Math.PI / 2 + i * 2 * Math.PI / n, x = c.x + Math.cos(a) * rx, y = c.y + Math.sin(a) * ry, item = els[i];
            item.style.left = x + 'px'; item.style.top = y + 'px';
            item.classList.toggle('right', Math.cos(a) >= .05); item.classList.toggle('left', Math.cos(a) < .05);
            links.push({sx: c.x + Math.cos(a) * c.r * 1.05, sy: c.y + Math.sin(a) * c.r * 1.05, mx: (c.x + x) / 2 + Math.sin(a) * 40, my: (c.y + y) / 2 - Math.cos(a) * 24,
                ex: x, ey: y, warn: item.classList.contains('warn'), money: item.classList.contains('money')});
        }
    }
    function point(l, t) { var u = 1 - t; return {x: u * u * l.sx + 2 * u * t * l.mx + t * t * l.ex, y: u * u * l.sy + 2 * u * t * l.my + t * t * l.ey}; }
    function draw(now) {
        frame = 0;
        if (!root.isConnected) { dispose(); return; }
        // The box can grow after its lists fill in (phones); keep the drawing at the box's real size.
        if (root.clientWidth !== W || root.clientHeight !== H) size();
        var t = (now - start) / 1000, c = centre(); ctx.clearRect(0, 0, W, H);
        for (var i = 0; i < stars.length; i++) { var s = stars[i]; ctx.globalAlpha = .25 + .35 * Math.sin(t * 1.3 + s.t); ctx.fillStyle = '#9fdcff'; ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, 6.283); ctx.fill(); }
        ctx.globalAlpha = 1; fctx.clearRect(0, 0, W, H);
        var planets = orbits(c, t), fade = booting ? Math.min(1, t / 1.6) : 1;
        ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(120,175,215,' + (0.16 * fade) + ')';
        planets.forEach(function (o) { ctx.beginPath(); ctx.ellipse(c.x, c.y, o.rx, o.ry, TILT, 0, 6.283); ctx.stroke(); });
        var grown = Math.min(1, Math.max(0, (t - revealAt) / 1.1));
        for (var j = 0; j < links.length; j++) {
            var l = links[j], end = Math.max(0, Math.min(1, grown * 1.4 - j * .02)); if (!end) continue;
            ctx.strokeStyle = l.warn ? 'rgba(255,194,74,.45)' : l.money ? 'rgba(255,154,46,.5)' : 'rgba(51,225,255,.38)'; ctx.lineWidth = 1.2; ctx.beginPath();
            for (var q = 0; q <= 28; q++) { var p = point(l, end * q / 28); if (q) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y); }
            ctx.stroke();
        }
        if (grown > .6 && links.length) {
            if (particles.length < links.length * 3 && Math.random() < .5) particles.push({l: Math.floor(Math.random() * links.length), p: Math.random() < .5 ? 0 : 1, d: Math.random() < .5 ? 1 : -1, v: .25 + Math.random() * .35});
            for (var m = particles.length - 1; m >= 0; m--) {
                var pa = particles[m], lk = links[pa.l]; if (!lk) { particles.splice(m, 1); continue; }
                pa.p += pa.d * pa.v / 60; if (pa.p < 0 || pa.p > 1) { particles.splice(m, 1); continue; }
                var pt = point(lk, pa.p); ctx.fillStyle = lk.warn ? '#ffc24a' : lk.money ? '#ff9a2e' : '#9ff3ff';
                ctx.shadowColor = ctx.fillStyle; ctx.shadowBlur = 8; ctx.beginPath(); ctx.arc(pt.x, pt.y, 1.8, 0, 6.283); ctx.fill(); ctx.shadowBlur = 0;
            }
        }
        planets.forEach(function (o) { planet(o.front ? fctx : ctx, o, c, fade); });
        if (!reduce && !document.hidden) frame = requestAnimationFrame(draw);
    }
    function orbits(c, t) {
        var inner = c.r * 1.12, k = Math.max(.8, Math.min(1.4, c.r / 95));
        return PLANETS.map(function (p) {
            var rx = inner + (orbitMax - inner) * p.f, ry = rx * flat, a = p.phase + (reduce ? 0 : t * 6.283 / p.period);
            var x = Math.cos(a) * rx, y = Math.sin(a) * ry, depth = .85 + .22 * Math.sin(a);
            return {p: p, rx: rx, ry: ry, front: Math.sin(a) > 0, r: p.size * k * depth,
                x: c.x + x * Math.cos(TILT) - y * Math.sin(TILT), y: c.y + x * Math.sin(TILT) + y * Math.cos(TILT)};
        });
    }
    function planet(g, o, c, alpha) {
        var p = o.p, R = o.r, dx = c.x - o.x, dy = c.y - o.y, len = Math.hypot(dx, dy) || 1;
        g.save(); g.globalAlpha = alpha;
        if (p.ring) ringHalf(g, o, R, Math.PI, 2 * Math.PI);
        // lit from the GO "sun" in the middle
        var shade = g.createRadialGradient(o.x + dx / len * R * .45, o.y + dy / len * R * .45, R * .1, o.x, o.y, R * 1.05);
        shade.addColorStop(0, p.c[0]); shade.addColorStop(1, p.c[1]);
        g.shadowColor = p.c[0]; g.shadowBlur = R * 1.2; g.fillStyle = shade; g.beginPath(); g.arc(o.x, o.y, R, 0, 6.283); g.fill(); g.shadowBlur = 0;
        if (p.bands || p.land) {
            g.save(); g.beginPath(); g.arc(o.x, o.y, R, 0, 6.283); g.clip();
            if (p.bands) { g.strokeStyle = 'rgba(140,85,45,.38)'; g.lineWidth = R * .16; [-.45, -.05, .35].forEach(function (b) { g.beginPath(); g.moveTo(o.x - R, o.y + b * R); g.lineTo(o.x + R, o.y + b * R + R * .08); g.stroke(); }); }
            if (p.land) { g.fillStyle = 'rgba(80,175,105,.75)'; g.beginPath(); g.ellipse(o.x - R * .25, o.y - R * .1, R * .38, R * .26, .5, 0, 6.283); g.fill(); g.beginPath(); g.ellipse(o.x + R * .35, o.y + R * .3, R * .22, R * .16, -.4, 0, 6.283); g.fill(); }
            g.restore();
        }
        if (p.ring) ringHalf(g, o, R, 0, Math.PI);
        g.restore();
    }
    function ringHalf(g, o, R, from, to) {
        g.strokeStyle = 'rgba(232,207,152,.85)'; g.lineWidth = Math.max(1, R * .22);
        g.beginPath(); g.ellipse(o.x, o.y, R * 2.1, R * .62, TILT - .25, from, to); g.stroke();
    }
    function loop() { if (!frame && !reduce && !document.hidden && root.isConnected) frame = requestAnimationFrame(draw); }

    // ---- content ----
    function fillChecks(animated) {
        var list = checks(); checksBox.replaceChildren();
        list.forEach(function (c) {
            var li = el('li', undefined, c[2] ? 'warn' : ''); li.appendChild(el('span', undefined, 'hud-box')); li.appendChild(el('span', c[0])); li.appendChild(el('b', c[1]));
            if (!animated) li.classList.add('on'); checksBox.appendChild(li);
        });
        return list;
    }
    function fillTally(list) {
        var warn = list.filter(function (c) { return c[2]; }).length, ok = list.length - warn;
        tally.replaceChildren(el('strong', ok + ' من ' + list.length), document.createTextNode(' سليمة' + (warn ? ' · ' + warn + ' محتاجة متابعة' : '')));
    }
    function nodeElement(n) {
        var item = el('div', undefined, 'hud-node ' + n[3]), tag = el('div', undefined, 'hud-tag');
        item.dataset.key = n[0]; item.appendChild(el('span', undefined, 'hud-dot')); tag.appendChild(el('b', n[2])); tag.appendChild(el('small', n[1])); item.appendChild(tag);
        return item;
    }
    function fillNodes(animated) {
        nodesBox.replaceChildren(); nodes().forEach(function (n) { var item = nodeElement(n); if (!animated) item.classList.add('out'); nodesBox.appendChild(item); });
        layout();
    }
    function ready() {
        booting = false; root.classList.add('ready'); statusText.textContent = 'النظام جاهز'; if (skip) skip.hidden = true;
    }
    function finish() {
        timers.forEach(clearTimeout); timers = [];
        core.classList.add('lit'); checksBox.querySelectorAll('li').forEach(function (li) { li.classList.add('on'); });
        nodesBox.querySelectorAll('.hud-node').forEach(function (n) { n.classList.add('out'); });
        if (revealAt === Infinity) revealAt = (performance.now() - start) / 1000 - 2;
        fillTally(checks()); ready();
    }
    function intro() {
        booting = true; statusText.textContent = 'جاري فحص النظام'; if (skip) skip.hidden = false;
        var list = fillChecks(true); fillNodes(true);
        var after = function (ms, fn) { timers.push(setTimeout(fn, ms)); };
        after(150, function () { core.classList.add('lit'); });
        var items = checksBox.children; for (var i = 0; i < items.length; i++) (function (li, k) { after(500 + k * 230, function () { li.classList.add('on'); }); })(items[i], i);
        var done = 500 + items.length * 230;
        after(done - 300, function () {
            revealAt = (performance.now() - start) / 1000;
            var ns = nodesBox.children; for (var j = 0; j < ns.length; j++) (function (item, k) { after(k * 70, function () { item.classList.add('out'); }); })(ns[j], j);
        });
        after(done + 500, function () { fillTally(list); ready(); });
    }
    function settled() {
        fillTally(fillChecks(false)); fillNodes(false); core.classList.add('lit'); revealAt = -10; ready();
    }
    // Live refresh from the page's own reload: update values in place, rebuild only when sections change.
    function update(next) {
        if (!root.isConnected) { dispose(); return; }
        if (!next || typeof next !== 'object') return; data = next; var who = account();
        root.querySelector('[data-hud-who]').textContent = who.who; root.querySelector('[data-hud-day]').textContent = who.day;
        if (booting) return;
        var fresh = nodes(), current = Array.prototype.map.call(nodesBox.children, function (n) { return n.dataset.key; });
        if (fresh.map(function (n) { return n[0]; }).join() !== current.join()) { particles = []; fillNodes(false); }
        else fresh.forEach(function (n, i) {
            var item = nodesBox.children[i], value = item.querySelector('b');
            item.className = 'hud-node out ' + n[3] + (item.classList.contains('right') ? ' right' : ' left');
            if (value.textContent !== n[2]) { value.textContent = n[2]; value.classList.add('flash'); setTimeout(function () { value.classList.remove('flash'); }, 900); }
        });
        layout(); fillTally(fillChecks(false));
    }
    function dispose() {
        timers.forEach(clearTimeout); timers = []; if (frame) cancelAnimationFrame(frame); frame = 0;
        document.removeEventListener('go:home-data', onData); window.removeEventListener('resize', size); document.removeEventListener('visibilitychange', loop);
    }
    function onData(event) { update(event.detail); }

    var who = account(); root.querySelector('[data-hud-who]').textContent = who.who; root.querySelector('[data-hud-day]').textContent = who.day;
    size();
    if (root.dataset.intro === '1' && !reduce) intro(); else settled();
    if (skip) skip.addEventListener('click', finish);
    root.addEventListener('click', function (e) { if (booting && e.target !== skip) finish(); });
    document.addEventListener('go:home-data', onData);
    window.addEventListener('resize', size);
    document.addEventListener('visibilitychange', loop);
    if (window.FasakhanstaDesktop && typeof window.FasakhanstaDesktop.status === 'function') {
        // The PC program: say whether this screen runs on the device's own copy or directly on the server.
        Promise.resolve(window.FasakhanstaDesktop.status()).then(function (s) {
            if (!s || !root.isConnected) return; desktopMode = s.mode === 'local' ? 'على الجهاز' : 'متصل بالسيرفر';
            if (!booting) fillTally(fillChecks(false));
        }).catch(function () {});
    }
    requestAnimationFrame(draw);
})();
