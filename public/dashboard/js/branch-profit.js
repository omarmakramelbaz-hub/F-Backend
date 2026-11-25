/* Goods page, «صافي الربح» tab (owner only): packaging per order, each branch's rent and which expense items count. */
(function () {
    'use strict';
    var root = document.getElementById('branch-stock'), panel = root && root.querySelector('[data-profit-panel]');
    var bootNode = document.getElementById('branch-stock-bootstrap');
    if (!panel || !bootNode) return;
    var boot = JSON.parse(bootNode.textContent), urls = boot.urls || {};
    var form = panel.querySelector('[data-profit-form]'), message = panel.querySelector('[data-profit-message]');
    var branchRows = panel.querySelector('[data-profit-branches]'), expenseRows = panel.querySelector('[data-profit-expenses]');
    var save = panel.querySelector('[data-profit-save]'), packaging = panel.querySelector('[data-profit-packaging]');
    var loaded = false, busy = false;

    function el(tag, text, cls) { var n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
    function note(text, ok) { message.hidden = !text; message.textContent = text || ''; message.classList.toggle('is-success', !!ok); }
    function money(value) { var n = Number(value); return isFinite(n) ? n.toLocaleString('ar-EG', {maximumFractionDigits: 2}) : value; }

    function render(data) {
        packaging.value = data.packaging || '';
        branchRows.textContent = '';
        (data.branches || []).forEach(function (b) {
            var tr = el('tr'), input = el('input');
            input.type = 'text'; input.inputMode = 'decimal'; input.maxLength = 12; input.placeholder = '0'; input.autocomplete = 'off';
            input.value = b.rent || ''; input.dataset.branch = b.value; input.setAttribute('aria-label', 'إيجار ' + b.name);
            var rent = el('td'); rent.appendChild(input);
            var daily = el('td', '', 'bs-daily-rent'), days = Number(data.month_days) || 30;
            var showDaily = function () { var n = Number(String(input.value).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[,،]/g, '').replace('٫', '.')); daily.textContent = isFinite(n) && n > 0 ? money(Math.round(n / days * 100) / 100) : '—'; };
            input.addEventListener('input', showDaily); showDaily();
            tr.append(el('th', b.name), rent, daily, el('td', money(b.salaries)));
            branchRows.appendChild(tr);
        });
        expenseRows.textContent = '';
        if (!(data.expenses || []).length) { var empty = el('tr'), td = el('td', 'مفيش مصروفات معتمدة في آخر 90 يوم.'); td.colSpan = 3; empty.appendChild(td); expenseRows.appendChild(empty); }
        (data.expenses || []).forEach(function (x) {
            var tr = el('tr'), box = el('input'), cell = el('td');
            box.type = 'checkbox'; box.checked = !!x.included; box.disabled = x.sales_transfer === true || x.goods_purchase === true; box.dataset.expense = x.key; box.setAttribute('aria-label', x.name);
            cell.appendChild(box);
            var name = el('td', x.name);
            if (x.sales_transfer === true) name.appendChild(el('small', ' (مبيعات فعلية، مش مصروف)'));
            else if (x.goods_purchase === true) name.appendChild(el('small', ' (بتتحسب في تكلفة البضاعة المباعة)'));
            else if (x.included !== x.default_included) name.appendChild(el('small', x.default_included ? ' (متشال بإيدك)' : ' (مضاف بإيدك)'));
            tr.append(cell, name, el('td', money(x.month)));
            expenseRows.appendChild(tr);
        });
    }

    async function load() {
        if (busy) return; busy = true; note('جارٍ التحميل…');
        try {
            var response = await fetch(urls.profit, {credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store'});
            var data = await response.json();
            if (!response.ok || !data.success) throw Error(data.message || 'تعذر تحميل بيانات الربح.');
            render(data); loaded = true; note('');
        } catch (e) { note(e.message || 'تعذر تحميل بيانات الربح.'); }
        finally { busy = false; }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (busy) return; busy = true; save.disabled = true; note('جارٍ الحفظ…');
        var body = {packaging: packaging.value, rents: {}, expenses: {}};
        branchRows.querySelectorAll('input[data-branch]').forEach(function (i) { body.rents[i.dataset.branch] = i.value; });
        expenseRows.querySelectorAll('input[data-expense]').forEach(function (i) { body.expenses[i.dataset.expense] = i.checked; });
        try {
            var response = await fetch(urls.profit_save, {method: 'POST', credentials: 'same-origin', body: JSON.stringify(body),
                headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}});
            var data = await response.json();
            if (!response.ok || !data.success) {
                var first = data.errors ? Object.values(data.errors)[0] : null;
                throw Error((first && first[0]) || data.message || 'تعذر الحفظ.');
            }
            render(data); note('اتحفظ. الرئيسية هتحسب صافي الربح بالأرقام دي.', true);
        } catch (e) { note(e.message || 'تعذر الحفظ.'); }
        finally { busy = false; save.disabled = false; }
    });

    // Top of the tab: each branch's net profit or loss today and since the first of the month, the same figures as the home.
    var netBox = panel.querySelector('[data-branch-net]'), netList = panel.querySelector('[data-branch-net-list]'), netLoaded = false;
    function pounds(cents) { return Math.round(Math.abs(cents) / 100).toLocaleString('ar-EG') + ' ج'; }
    function netRow(label, cents, gross) {
        var row = el('p', undefined, 'bs-branch-net-row' + (cents < 0 ? ' is-loss' : ''));
        row.append(el('span', label), el('strong', (cents < 0 ? 'خسارة ' : 'ربح ') + pounds(cents)));
        if (gross > 0) row.append(el('em', Math.round(cents / gross * 100) + '%'));
        return row;
    }
    async function overview(period) {
        var url = new URL(urls.overview, location.href); url.searchParams.set('period', period);
        var response = await fetch(url.href, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        var data = await response.json(); if (!response.ok || !data.success || !data.profit || !data.profit.ready) throw Error('overview'); return data;
    }
    async function loadNet() {
        if (!netBox || !urls.overview || netLoaded) return; netLoaded = true;
        try {
            var both = await Promise.all([overview('today'), overview('month')]), day = both[0], month = both[1];
            var names = {}; (month.branches || []).concat(day.branches || []).forEach(function (b) { names[b.value] = b.name; });
            var cards = [{key: '', name: 'كل الفروع', day: day.profit.total, month: month.profit.total}];
            Object.keys(month.profit.branches || {}).forEach(function (key) {
                var d = (day.profit.branches || {})[key] || {}, m = month.profit.branches[key];
                if (!m.gross_cents && !m.net_cents && !d.gross_cents && !d.net_cents) return;
                cards.push({key: key, name: names[key] || key, day: d, month: m});
            });
            netList.textContent = '';
            cards.forEach(function (c) {
                var card = el('article', undefined, 'bs-branch-net-card' + (c.key ? '' : ' is-total'));
                card.append(el('h3', c.name), netRow('النهارده', c.day.net_cents || 0, c.day.gross_cents || 0), netRow('الشهر', c.month.net_cents || 0, c.month.gross_cents || 0));
                netList.appendChild(card);
            });
            var stamp = panel.querySelector('[data-branch-net-day]'); if (stamp && month.filters) stamp.textContent = 'الشهر من ' + month.filters.from + ' لـ ' + month.filters.to;
            netBox.hidden = false;
        } catch (e) { netLoaded = false; }
    }

    root.querySelectorAll('[data-inventory-tab="profit"]').forEach(function (b) { b.addEventListener('click', function () { if (!loaded) load(); loadNet(); }); });
    // «حساب صافي الربح» on the home opens this tab directly (…/branch-stock#profit).
    if (location.hash === '#profit') { var tab = root.querySelector('[data-inventory-tab="profit"]'); if (tab) setTimeout(function () { tab.click(); }, 0); }
})();
