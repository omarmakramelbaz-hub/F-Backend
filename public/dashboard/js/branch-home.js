(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('branch-home');
    if (!root) return;
    var boot = JSON.parse(document.getElementById('branch-home-bootstrap').textContent), data = boot.initial, labels = boot.labels;
    var controller, timer, disposed = false, sequence = 0, listeners = [];
    var number = new Intl.NumberFormat(boot.locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    function $(name) { return root.querySelector('[data-bh-' + name + ']'); }
    function el(tag, value) { var node = document.createElement(tag); node.textContent = value; return node; }
    function on(node, name, fn) { if (node) { node.addEventListener(name, fn); listeners.push(function () { node.removeEventListener(name, fn); }); } }
    function money(cents) { return cents === null ? '—' : number.format(cents / 100) + ' ' + labels.currency; }
    function empty(body, text) { var row = el('tr', ''), cell = el('td', text); cell.colSpan = 2; row.appendChild(cell); body.appendChild(row); }
    function stock() {
        var body = $('stock'), query = $('search').value.trim().toLocaleLowerCase(); body.replaceChildren();
        if (!data.modules.inventory) return empty(body, labels.unavailable_inventory);
        data.inventory.items.filter(function (item) { return item.name.toLocaleLowerCase().includes(query); }).forEach(function (item) {
            var row = el('tr', ''), value = item.tracked_branches ? item.quantity + ' ' + labels[item.unit === 'kg' ? 'kg' : 'unit'] : labels.untracked;
            row.append(el('td', item.name), el('td', value));
            if (item.quantity_units < 0) row.className = 'bh-negative';
            body.appendChild(row);
        });
        if (!body.children.length) empty(body, labels.no_stock);
    }
    function render() {
        stock(); var expenses = data.today_expenses, body = $('expenses'); body.replaceChildren();
        $('date').textContent = expenses.date; $('total').textContent = money(expenses.total_cents);
        expenses.items.forEach(function (item) { var row = el('tr', ''); row.append(el('td', item.name), el('td', money(item.amount_cents))); body.appendChild(row); });
        if (!body.children.length) empty(body, data.modules.expenses ? labels.no_today_expenses : labels.unavailable_expenses);
        ['stock', 'expenses'].forEach(function (name) {
            var link = $(name + '-link'), url = new URL(link.href, location.href), branch = data.filters.branch || (data.branches.length === 1 ? data.branches[0].value : '');
            if (branch) url.searchParams.set('branch', branch); else url.searchParams.delete('branch');
            if (name === 'expenses') { url.searchParams.set('from', expenses.date); url.searchParams.set('to', expenses.date); url.searchParams.set('status', 'approved'); }
            link.href = url.href;
        });
    }
    async function load() {
        if (disposed) return;
        clearTimeout(timer); if (controller) controller.abort(); controller = new AbortController(); var current = ++sequence;
        $('refresh').disabled = true;
        try {
            var url = new URL(boot.url, location.href); url.searchParams.set('period', 'today');
            var branch = $('branch') ? $('branch').value : data.filters.branch;
            if (branch) url.searchParams.set('branch', branch); else url.searchParams.delete('branch');
            var response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw Error('load');
            var next = await response.json();
            if (disposed || current !== sequence) return;
            if (!next.success || !next.branch_home || !next.today_expenses) throw Error('payload');
            data = next; render(); $('notice').hidden = true;
        } catch (error) {
            if (!disposed && current === sequence && error.name !== 'AbortError') { $('notice').textContent = labels.stale; $('notice').hidden = false; }
        } finally {
            if (!disposed && current === sequence) { $('refresh').disabled = false; timer = setTimeout(load, 30000); }
        }
    }
    on($('search'), 'input', stock); on($('refresh'), 'click', load); on($('branch'), 'change', load);
    render(); timer = setTimeout(load, 30000);
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(function () { disposed = true; sequence++; clearTimeout(timer); if (controller) controller.abort(); listeners.forEach(function (remove) { remove(); }); });
})();
