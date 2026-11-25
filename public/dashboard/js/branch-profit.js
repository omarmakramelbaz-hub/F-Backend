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
            tr.append(el('th', b.name), rent, el('td', money(b.salaries)));
            branchRows.appendChild(tr);
        });
        expenseRows.textContent = '';
        if (!(data.expenses || []).length) { var empty = el('tr'), td = el('td', 'مفيش مصروفات معتمدة في آخر 90 يوم.'); td.colSpan = 3; empty.appendChild(td); expenseRows.appendChild(empty); }
        (data.expenses || []).forEach(function (x) {
            var tr = el('tr'), box = el('input'), cell = el('td');
            box.type = 'checkbox'; box.checked = !!x.included; box.disabled = x.sales_transfer === true; box.dataset.expense = x.key; box.setAttribute('aria-label', x.name);
            cell.appendChild(box);
            var name = el('td', x.name);
            if (x.sales_transfer === true) name.appendChild(el('small', ' (مبيعات فعلية، مش مصروف)'));
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

    root.querySelectorAll('[data-inventory-tab="profit"]').forEach(function (b) { b.addEventListener('click', function () { if (!loaded) load(); }); });
    // «حساب صافي الربح» on the home opens this tab directly (…/branch-stock#profit).
    if (location.hash === '#profit') { var tab = root.querySelector('[data-inventory-tab="profit"]'); if (tab) setTimeout(function () { tab.click(); }, 0); }
})();
