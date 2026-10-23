(function () {
    'use strict';
    // GO live home screen. It covers the window over the usual home page (owner/admin overview or branch home),
    // reads the same data and follows that page's refreshes. GO in the middle steps into the usual page;
    // each section opens its own page.
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('go-hud'); if (!root) return;
    var source = document.getElementById('home-overview-bootstrap') || document.getElementById('branch-home-bootstrap'); if (!source) return;
    var boot = JSON.parse(source.textContent), data = boot.initial || {};
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var canvas = root.querySelector('canvas'), ctx = canvas.getContext('2d');
    var core = root.querySelector('[data-hud-core]'), nodesBox = root.querySelector('[data-hud-nodes]'), checksBox = root.querySelector('[data-hud-checks]');
    var tally = root.querySelector('[data-hud-tally]'), skip = root.querySelector('[data-hud-skip]');
    var W = 0, H = 0, stars = [], links = [], particles = [], pulses = [], timers = [], start = performance.now(), revealAt = reduce ? -10 : Infinity;
    var frame = 0, booting = false, desktopMode = '', orbit = null, lastBeat = -1, mode = '', waves = [], leaving = false;
    var enterButton = root.querySelector('[data-hud-enter]'), LINKS = boot.hud_links || {};
    var sun = core.querySelector('canvas.hud-sun'), sctx = sun ? sun.getContext('2d') : null, sunR = 0, sunS = 0, motes = [];
    var surgeUntil = 0, motionTime = 0, lastMotion = 0, seenOrders = new Set();
    function onOrderAlert(event) {
        var keys = event.detail && event.detail.pending; if (!Array.isArray(keys)) return;
        var fresh = false; keys.forEach(function (key) {
            if (typeof key !== 'string' || !/^(legacy|store|service|partner_service):[1-9][0-9]*$/.test(key) || seenOrders.has(key)) return;
            seenOrders.add(key); fresh = true;
        });
        if (fresh && !reduce && !leaving) surgeUntil = performance.now() + 12000;
    }
    var BEAT = 3.2, HALO = 240; // seconds between two heartbeats of the GO sun; the halo's radius in solar-system units
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
        var who = d.branch_home ? ((picked || cards[0] || {}).name || 'الفرع') : (picked ? picked.name : '');
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

    function stackedLayout() { return W < 900 || H < 560; }
    function nodes() {
        var d = data, list = [], m = d.modules || {}, finance = d.can_view_financials && d.sales;
        if (LINKS.employees) list.push(['employees', 'متابعة الموظفين', 'الحضور والرواتب', '']);
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
        return list.slice(0, W && stackedLayout() ? 8 : 12);
    }


    // ---- drawing ----
    function size() {
        root.classList.add('reflow');
        var ratio = Math.min(2, window.devicePixelRatio || 1); W = root.clientWidth; H = root.clientHeight;
        canvas.width = W * ratio; canvas.height = H * ratio; canvas.style.height = H + 'px'; ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        stars = []; for (var i = 0; i < Math.round(W * H / 5200); i++) stars.push({x: Math.random() * W, y: Math.random() * H, r: Math.random() * 1.3 + .2, t: Math.random() * 6});
        // Cosmic waves: long luminous lines across the whole screen, drawn together towards the sun.
        waves = []; var count = W < 760 ? 12 : 22;
        for (var w = 0; w < count; w++) waves.push({o: w / (count - 1) * 2 - 1 + (Math.random() - .5) * .1, a: 10 + Math.random() * 26, k: .0035 + Math.random() * .004,
            s: .25 + Math.random() * .45, p: Math.random() * 6.3, a2: 5 + Math.random() * 14, k2: .009 + Math.random() * .008, s2: .15 + Math.random() * .3,
            p2: Math.random() * 6.3, tilt: (Math.random() - .5) * .22, hue: 186 + Math.random() * 28, alpha: .07 + Math.random() * .12,
            width: .8 + Math.random() * 1.2, run: Math.random(), speed: .035 + Math.random() * .05, left: w % 2 === 0});
        if (!booting && nodesBox.children.length && nodesBox.children.length !== nodes().length) fillNodes(false);
        layout(); root.getBoundingClientRect(); root.classList.remove('reflow');
    }
    // The solar system is drawn in units: the box is 360 wide, the GO mark 300, the halo 240 in radius, Neptune's orbit 660 by 264.
    function layout() {
        var next = stackedLayout() ? 'compact' : W < 1280 ? 'mid' : 'wide', els = nodesBox.children, n = els.length, i, box = checksBox.parentNode;
        if (next !== mode) { root.classList.remove('compact', 'mid', 'wide'); root.classList.add(next); mode = next; root.scrollTop = 0; }
        links = [];
        if (mode === 'compact') {
            // Phones: the sections are tiles under the system, so nothing is placed by hand.
            core.style.left = core.style.top = core.style.width = box.style.marginTop = ''; root.style.removeProperty('--hud-cx');
            for (i = 0; i < n; i++) { els[i].style.left = els[i].style.top = ''; els[i].classList.remove('right', 'left'); }
            var unit = core.offsetWidth / 1380; setSun(HALO * unit);
            orbit = {x: core.offsetLeft + core.offsetWidth / 2, y: core.offsetTop + core.offsetHeight / 2, logo: 150 * unit, rx: 676 * unit, ry: 270 * unit};
            root.style.setProperty('--hud-cy', orbit.y + 'px');
            return;
        }
        // Laptops: the checks sit under the system and the system takes what is left of the screen.
        var ringH = mode === 'mid' ? Math.max(440, H - box.offsetHeight - 110) : H;
        box.style.marginTop = mode === 'mid' ? ringH + 'px' : '';
        var panel = mode === 'wide' ? box.offsetWidth + 40 : 0, cx = (W - panel) / 2, cy = ringH / 2 + 12;
        var labelSpace = 0;
        for (i = 0; i < n; i++) labelSpace = Math.max(labelSpace, els[i].querySelector('.hud-tag').offsetWidth + 34);
        var rx = Math.min(cx - Math.max(150, labelSpace), 520), ry = ringH * .35, scale = Math.max(.3, Math.min(rx * .8 / 660, ry * .76 / 264));
        // Wide screens keep the system in the space beside the check panel, so no section runs under it.
        core.style.left = cx + 'px'; core.style.top = cy + 'px'; core.style.width = Math.round(360 * scale) + 'px';
        root.style.setProperty('--hud-cx', cx + 'px'); root.style.setProperty('--hud-cy', cy + 'px');
        orbit = {x: cx, y: cy, logo: 150 * scale, rx: 676 * scale, ry: 270 * scale}; setSun(HALO * scale);
        for (i = 0; i < n; i++) {
            var a = -Math.PI / 2 + i * 2 * Math.PI / n, cos = Math.cos(a), sin = Math.sin(a), x = cx + cos * rx, y = cy + sin * ry, item = els[i];
            item.style.left = x + 'px'; item.style.top = y + 'px';
            item.classList.toggle('right', cos >= .05); item.classList.toggle('left', cos < .05);
            // Each section hangs from the outermost orbit, so its line never crosses the planets.
            var sx = cx + cos * orbit.rx, sy = cy + sin * orbit.ry;
            links.push({sx: sx, sy: sy, mx: (sx + x) / 2 + sin * 18, my: (sy + y) / 2 - cos * 10, ex: x, ey: y,
                warn: item.classList.contains('warn'), money: item.classList.contains('money')});
        }
    }
    function pulse() { pulses.push((performance.now() - start) / 1000); }
    // The GO sun beats like a heart: a strong beat and a softer echo every few seconds.
    function beatAt(t) { var p = t % BEAT; return Math.exp(-Math.pow((p - .25) / .14, 2)) + .55 * Math.exp(-Math.pow((p - .68) / .18, 2)); }
    function point(l, t) { var u = 1 - t; return {x: u * u * l.sx + 2 * u * t * l.mx + t * t * l.ex, y: u * u * l.sy + 2 * u * t * l.my + t * t * l.ey}; }
    function waveY(v, x, t, cx, cy, spread) {
        var u = Math.min(1, Math.abs(x - cx) / (W * .5)), pinch = .12 + .88 * Math.pow(u, 1.2);
        return cy + v.o * spread * pinch + (x - cx) * v.tilt * pinch + (v.a * Math.sin(x * v.k + t * v.s + v.p) + v.a2 * Math.sin(x * v.k2 - t * v.s2 + v.p2)) * (.3 + .7 * pinch);
    }
    function glow(x, y, r, flat, stops) {
        var g = ctx.createRadialGradient(x, y, 0, x, y, r); stops.forEach(function (s) { g.addColorStop(s[0], s[1]); });
        ctx.fillStyle = g; ctx.save(); ctx.translate(x, y); ctx.scale(1, flat); ctx.beginPath(); ctx.arc(0, 0, r, 0, 6.283); ctx.restore(); ctx.fill();
    }
    // ---- the solar halo around GO: a golden ring of light over a deep blue disc full of sparks ----
    function setSun(radius) {
        if (!sun || Math.abs(radius - sunR) < .5) return;
        var ratio = Math.min(2, window.devicePixelRatio || 1), size = Math.ceil(radius * 3.4);
        sunR = radius; sunS = size; sun.width = size * ratio; sun.height = size * ratio; sun.style.width = sun.style.height = size + 'px'; sctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        motes = []; for (var i = 0, n = W < 760 ? 420 : 950; i < n; i++) {
            var r = Math.sqrt(Math.random()) * .9;
            motes.push({r: r, a: Math.random() * 6.283, w: (.02 + Math.random() * .07) * (Math.random() < .5 ? -1 : 1) * (1.2 - r), z: .45 + Math.random() * (Math.random() < .08 ? 1.3 : .6), tw: Math.random() * 6.283});
        }
    }
    // The halo matches the reference: a lemon-yellow ring of light with a yellow-green glow, a bright sky-blue disc
    // full of fine sparks with a burst at its centre and two faint inner circles, thin cyan rings outside, a light bar under it.
    function ring(g, c, R, width, colour) { g.lineWidth = width; g.strokeStyle = colour; g.beginPath(); g.arc(c, c, R, 0, 6.283); g.stroke(); }
    function drawSun(t, beat, fade, surge) {
        if (!sctx || !sunR) return;
        var g = sctx, c = sunS / 2, R = sunR, i, heat = Math.min(1.1, .84 + .22 * beat + .28 * surge) * fade, a; // steady light that swells gently on the beat: no fast flicker, easy on the eyes
        g.clearRect(0, 0, sunS, sunS); g.globalCompositeOperation = 'lighter';
        // light just outside the ring, and the thin cyan rings around it
        var out = g.createRadialGradient(c, c, R, c, c, R * 1.3);
        out.addColorStop(0, 'rgba(120,200,150,' + (.16 * heat).toFixed(3) + ')'); out.addColorStop(1, 'rgba(120,200,150,0)');
        g.fillStyle = out; g.beginPath(); g.arc(c, c, R * 1.3, 0, 6.283); g.fill();
        ring(g, c, R * 1.13, 1, 'rgba(150,230,255,' + ((.42 + .15 * beat) * fade).toFixed(3) + ')');
        ring(g, c, R * 1.2, 1, 'rgba(150,230,255,' + ((.26 + .12 * beat) * fade).toFixed(3) + ')');
        ring(g, c, R * 1.46, 1, 'rgba(150,230,255,' + ((.14 + .1 * beat) * fade).toFixed(3) + ')');
        for (i = pulses.length - 1; i >= 0; i--) {
            var age = (t - pulses[i]) / 2.6; if (age >= 1 || age < 0) { if (age >= 1) pulses.splice(i, 1); continue; }
            var reach = R * (1.05 + .6 * (1 - Math.pow(1 - age, 2.2)));
            ring(g, c, reach, 1.6, 'rgba(170,240,255,' + (.6 * (1 - age) * fade).toFixed(3) + ')');
            ring(g, c, reach * .965, 1, 'rgba(170,240,255,' + (.3 * (1 - age) * fade).toFixed(3) + ')');
        }
        // the light bar under the halo
        var by = c + R * 1.33, bar = g.createLinearGradient(c - R * .42, 0, c + R * .42, 0);
        bar.addColorStop(0, 'rgba(240,235,110,0)'); bar.addColorStop(.5, 'rgba(240,235,110,' + (.85 * heat).toFixed(3) + ')'); bar.addColorStop(1, 'rgba(240,235,110,0)');
        g.fillStyle = bar; g.fillRect(c - R * .42, by - 1, R * .84, 2);
        g.fillStyle = 'rgba(240,235,110,' + (.25 * heat).toFixed(3) + ')'; g.fillRect(c - R * .3, by - 4, R * .6, 8);
        g.fillStyle = 'rgba(255,250,200,' + (.9 * heat).toFixed(3) + ')'; g.fillRect(c - 1, by - 5, 2, 10);
        // the disc hides whatever passes behind the sun
        g.globalCompositeOperation = 'source-over';
        var disc = g.createRadialGradient(c, c, 0, c, c, R);
        disc.addColorStop(0, 'rgba(0,146,216,' + (.98 * fade).toFixed(3) + ')'); disc.addColorStop(.45, 'rgba(12,140,200,' + (.98 * fade).toFixed(3) + ')');
        disc.addColorStop(.8, 'rgba(12,136,186,' + (.98 * fade).toFixed(3) + ')'); disc.addColorStop(1, 'rgba(12,132,180,' + (.98 * fade).toFixed(3) + ')');
        g.fillStyle = disc; g.beginPath(); g.arc(c, c, R, 0, 6.283); g.fill();
        g.globalCompositeOperation = 'lighter';
        // two faint inner circles
        ring(g, c, R * .56, 1, 'rgba(170,240,255,' + (.3 * fade).toFixed(3) + ')');
        ring(g, c, R * .34, 1, 'rgba(170,240,255,' + (.2 * fade).toFixed(3) + ')');
        // fine sparks, slowly turning
        for (i = 0; i < motes.length; i++) {
            var m = motes[i]; a = m.a + motionTime * m.w;
            var glint = (.18 + .5 * (.5 + .5 * Math.sin(motionTime * 2.1 + m.tw))) * fade;
            g.fillStyle = 'rgba(190,245,255,' + glint.toFixed(3) + ')';
            g.fillRect(c + Math.cos(a) * m.r * R - m.z / 2, c + Math.sin(a) * m.r * R - m.z / 2, m.z, m.z);
        }
        // the burst at the centre
        for (i = 0; i < 24; i++) {
            a = i * 6.283 / 24 + t * .05 + (i % 2) * .09; var len = R * (.16 + .3 * (((i * 37) % 11) / 10)) * (1 + .15 * beat);
            var ray = g.createLinearGradient(c, c, c + Math.cos(a) * len, c + Math.sin(a) * len);
            ray.addColorStop(0, 'rgba(210,250,255,' + ((.35 + .2 * beat) * fade).toFixed(3) + ')'); ray.addColorStop(1, 'rgba(210,250,255,0)');
            g.lineWidth = 1; g.strokeStyle = ray; g.beginPath(); g.moveTo(c, c); g.lineTo(c + Math.cos(a) * len, c + Math.sin(a) * len); g.stroke();
        }
        var heart = g.createRadialGradient(c, c, 0, c, c, R * .3);
        heart.addColorStop(0, 'rgba(200,250,255,' + ((.3 + .25 * beat) * fade).toFixed(3) + ')'); heart.addColorStop(1, 'rgba(200,250,255,0)');
        g.fillStyle = heart; g.beginPath(); g.arc(c, c, R * .3, 0, 6.283); g.fill();
        // yellow-green light spilling inwards from the ring (painted, not added, so it stays yellow on the blue)
        g.globalCompositeOperation = 'source-over';
        var rim = g.createRadialGradient(c, c, R * .7, c, c, R);
        rim.addColorStop(0, 'rgba(200,230,100,0)'); rim.addColorStop(.6, 'rgba(200,230,100,' + (.04 * heat).toFixed(3) + ')'); rim.addColorStop(.85, 'rgba(208,230,98,' + (.16 * heat).toFixed(3) + ')'); rim.addColorStop(1, 'rgba(218,234,96,' + (.45 * Math.min(1, heat)).toFixed(3) + ')');
        g.fillStyle = rim; g.beginPath(); g.arc(c, c, R, 0, 6.283); g.fill();
        // a soft yellow glow on both sides of the ring
        g.globalCompositeOperation = 'lighter';
        [[.42, 'rgba(120,120,20,', .14], [.26, 'rgba(140,140,30,', .22], [.15, 'rgba(160,160,40,', .3]].forEach(function (l) {
            ring(g, c, R, R * l[0], l[1] + Math.min(1, l[2] * heat).toFixed(3) + ')');
        });
        // the lemon-yellow band itself, soft-edged like the reference, with a brighter line in its middle
        g.globalCompositeOperation = 'source-over';
        g.filter = 'blur(' + Math.max(1, R * .025).toFixed(1) + 'px)';
        ring(g, c, R, R * .1, 'rgba(218,228,92,' + (.78 * Math.min(1, heat)).toFixed(3) + ')');
        g.filter = 'blur(' + Math.max(.5, R * .01).toFixed(1) + 'px)';
        ring(g, c, R, R * .045, 'rgba(236,240,104,' + Math.min(1, .95 * heat).toFixed(3) + ')');
        g.filter = 'none';
        ring(g, c, R, R * .014, 'rgba(248,248,170,' + Math.min(1, .7 * heat).toFixed(3) + ')');
        g.globalCompositeOperation = 'source-over';
    }
    function draw(now) {
        frame = 0;
        if (!root.isConnected) { dispose(); return; }
        if (root.clientWidth !== W || root.clientHeight !== H) size();
        var t = (now - start) / 1000, beat = reduce ? .4 : beatAt(t), fade = booting ? Math.min(1, t / 2) : 1, scroll = root.scrollTop, i;
        var surge = reduce ? 0 : Math.max(0, Math.min(1, (surgeUntil - now) / 4000));
        motionTime += Math.min(.05, Math.max(0, (now - (lastMotion || now)) / 1000)) * (1 + 3 * surge); lastMotion = now;
        ctx.clearRect(0, 0, W, H);
        for (i = 0; i < stars.length; i++) { var s = stars[i]; ctx.globalAlpha = .25 + .35 * Math.sin(t * 1.3 + s.t); ctx.fillStyle = '#9fdcff'; ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, 6.283); ctx.fill(); }
        ctx.globalAlpha = 1;
        if (orbit) {
            var cx = orbit.x, cy = orbit.y - scroll;
            ctx.globalCompositeOperation = 'lighter';
            // the bright blue light around the halo, as in the reference, breathing with the beat
            ctx.globalCompositeOperation = 'source-over';
            glow(cx, cy, sunR * (4.1 + .2 * beat), 1, [[0, 'rgba(12,106,176,' + (.8 * fade).toFixed(3) + ')'], [.38, 'rgba(10,98,168,' + (.66 * fade).toFixed(3) + ')'],
                [.62, 'rgba(10,84,148,' + (.36 * fade).toFixed(3) + ')'], [.85, 'rgba(9,68,126,' + (.12 * fade).toFixed(3) + ')'], [1, 'rgba(9,60,120,0)']]);
            ctx.globalCompositeOperation = 'lighter';
            // cosmic waves, brighter on every beat, with sparks of energy running into the sun
            var spread = Math.max(H * .62, orbit.ry * 2.6);
            for (i = 0; i < waves.length; i++) {
                var v = waves[i], x; ctx.lineWidth = v.width;
                ctx.strokeStyle = 'hsla(' + v.hue.toFixed(0) + ',100%,62%,' + (v.alpha * (1 + .7 * beat + surge) * fade).toFixed(3) + ')';
                ctx.beginPath(); for (x = -30; x <= W + 30; x += 14) { var y = waveY(v, x, motionTime, cx, cy, spread); if (x === -30) ctx.moveTo(x, y); else ctx.lineTo(x, y); } ctx.stroke();
                var run = (v.run + motionTime * v.speed) % 1; if (run > .9 || fade < 1) continue;
                for (var j = 0; j < 7; j++) {
                    var along = Math.max(0, run - j * .006), sx = v.left ? along * cx : W - along * (W - cx), dim = (1 - j / 7) * (1 - run / .9);
                    ctx.fillStyle = 'hsla(' + v.hue.toFixed(0) + ',100%,78%,' + (.55 * dim).toFixed(3) + ')';
                    ctx.beginPath(); ctx.arc(sx, waveY(v, sx, motionTime, cx, cy, spread), 1.9 - j * .2, 0, 6.283); ctx.fill();
                }
            }
            ctx.globalCompositeOperation = 'source-over';
            if (!reduce) { var cycle = Math.floor(t / BEAT); if (cycle !== lastBeat && !booting) { lastBeat = cycle; pulse(); } }
            if (surge > 0 && (pulses.length === 0 || t - pulses[pulses.length - 1] > .38)) pulses.push(t);
            drawSun(t, beat, fade, surge);
        }
        ctx.save(); ctx.translate(0, -scroll);
        var grown = Math.min(1, Math.max(0, (t - revealAt) / 1.1));
        for (var q = 0; q < links.length; q++) {
            var l = links[q], end = Math.max(0, Math.min(1, grown * 1.4 - q * .02)); if (!end) continue;
            ctx.strokeStyle = l.warn ? 'rgba(255,194,74,.45)' : l.money ? 'rgba(255,154,46,.5)' : 'rgba(51,225,255,.38)'; ctx.lineWidth = 1.2; ctx.beginPath();
            for (var z = 0; z <= 20; z++) { var p = point(l, end * z / 20); if (z) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y); }
            ctx.stroke();
            if (end === 1) { ctx.fillStyle = ctx.strokeStyle; ctx.beginPath(); ctx.arc(l.sx, l.sy, 2, 0, 6.283); ctx.fill(); }
        }
        if (grown > .6 && links.length) {
            if (particles.length < links.length * 2 && Math.random() < .35) particles.push({l: Math.floor(Math.random() * links.length), p: Math.random() < .5 ? 0 : 1, d: Math.random() < .5 ? 1 : -1, v: .3 + Math.random() * .4});
            for (var m = particles.length - 1; m >= 0; m--) {
                var pa = particles[m], lk = links[pa.l]; if (!lk) { particles.splice(m, 1); continue; }
                pa.p += pa.d * pa.v / 60; if (pa.p < 0 || pa.p > 1) { particles.splice(m, 1); continue; }
                var pt = point(lk, pa.p); ctx.fillStyle = lk.warn ? '#ffc24a' : lk.money ? '#ff9a2e' : '#9ff3ff';
                ctx.shadowColor = ctx.fillStyle; ctx.shadowBlur = 8; ctx.beginPath(); ctx.arc(pt.x, pt.y, 1.8, 0, 6.283); ctx.fill(); ctx.shadowBlur = 0;
            }
        }
        ctx.restore();
        if (!reduce && !document.hidden && !leaving) frame = requestAnimationFrame(draw);
    }
    function loop() { if (!frame && !reduce && !document.hidden && !leaving && root.isConnected) frame = requestAnimationFrame(draw); }

    // ---- GO: the way into the usual home page ----
    function mountGo() {
        var logo = core.querySelector('.hud-logo'); if (!logo || logo.parentNode.classList.contains('hud-go')) return;
        var go = document.createElementNS('http://www.w3.org/2000/svg', 'a');
        go.setAttribute('class', 'hud-go'); go.setAttribute('href', '#home'); go.setAttribute('role', 'button'); go.setAttribute('aria-label', 'GO — الدخول على الرئيسية');
        var hit = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        [['class', 'hud-hit'], ['x', -HALO], ['y', -HALO], ['width', 2 * HALO], ['height', 2 * HALO], ['rx', HALO]].forEach(function (a) { hit.setAttribute(a[0], a[1]); });
        logo.parentNode.insertBefore(go, logo); go.appendChild(hit); go.appendChild(logo);
    }
    // Leaves the live screen and shows the usual home page underneath, untouched, with its sidebar and data.
    function enterHome() {
        if (leaving) return; leaving = true; pulse(); root.classList.add('leaving');
        timers.push(setTimeout(function () { root.hidden = true; dispose(); }, reduce ? 0 : 650));
    }

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
        // A section opens its own page when the account may see it (the same pages as the sidebar).
        var url = LINKS[n[0]], item = el(url ? 'a' : 'div', undefined, 'hud-node ' + n[3]), tag = el('div', undefined, 'hud-tag');
        if (url) { item.href = url; item.setAttribute('aria-label', n[1] + ': ' + n[2] + ' — فتح الصفحة'); }
        item.dataset.key = n[0]; item.appendChild(el('span', undefined, 'hud-dot')); tag.appendChild(el('b', n[2])); tag.appendChild(el('small', n[1])); item.appendChild(tag);
        return item;
    }
    function fillNodes(animated) {
        nodesBox.replaceChildren(); nodes().forEach(function (n) { var item = nodeElement(n); if (!animated) item.classList.add('out'); nodesBox.appendChild(item); });
        layout();
    }
    function ready() {
        booting = false; root.classList.add('ready'); if (skip) skip.hidden = true; if (enterButton) enterButton.hidden = false; pulse();
    }
    function finish() {
        timers.forEach(clearTimeout); timers = [];
        core.classList.add('lit'); checksBox.querySelectorAll('li').forEach(function (li) { li.classList.add('on'); });
        nodesBox.querySelectorAll('.hud-node').forEach(function (n) { n.classList.add('out'); });
        if (revealAt === Infinity) revealAt = (performance.now() - start) / 1000 - 2;
        fillTally(checks()); ready();
    }
    function intro() {
        booting = true; if (skip) skip.hidden = false;
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
        layout(); fillTally(fillChecks(false)); pulse();
    }
    function dispose() {
        timers.forEach(clearTimeout); timers = []; if (frame) cancelAnimationFrame(frame); frame = 0; leaving = true;
        document.documentElement.classList.remove('go-hud-open');
        document.removeEventListener('dashboard:order-alert-state', onOrderAlert); document.removeEventListener('go:home-data', onData); window.removeEventListener('resize', size); document.removeEventListener('visibilitychange', loop);
        document.removeEventListener('keydown', onKey); document.removeEventListener('dashboard:before-unload', dispose);
    }
    function onData(event) { update(event.detail); }
    function onKey(e) { if (e.key === 'Escape' && !e.defaultPrevented) { if (booting) finish(); else enterHome(); } }

    document.addEventListener('dashboard:order-alert-state', onOrderAlert);
    if (window.DashboardOrderAlerts) onOrderAlert({detail: window.DashboardOrderAlerts.state()});
    document.documentElement.classList.add('go-hud-open');
    var who = account(); root.querySelector('[data-hud-who]').textContent = who.who; root.querySelector('[data-hud-day]').textContent = who.day;
    mountGo(); size();
    if (root.dataset.intro === '1' && !reduce) intro(); else settled();
    if (skip) skip.addEventListener('click', finish);
    if (enterButton) enterButton.addEventListener('click', enterHome);
    root.addEventListener('click', function (e) {
        // During the start-up sequence any click finishes it; afterwards GO steps into the usual home page.
        if (booting) { if (e.target !== skip) { e.preventDefault(); finish(); } return; }
        var go = e.target.closest && e.target.closest('.hud-go'); if (go) { e.preventDefault(); enterHome(); }
    });
    document.addEventListener('go:home-data', onData);
    document.addEventListener('keydown', onKey);
    document.addEventListener('dashboard:before-unload', dispose);
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
