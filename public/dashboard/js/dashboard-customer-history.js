(function () {
    'use strict';
    // A customer's past orders by his number, on the delivery, dine-in, takeaway and app orders pages: a «سجل عميل»
    // button opens a search by number; on the delivery page the customer's number field has its own link.
    if (window.DashboardCustomerHistory) return;
    var script = document.currentScript, feed = script && script.dataset.historyUrl;
    if (!feed) return;
    var PAGES = '#phone-orders,#dining-pos,#takeaway-pos,#order-board', dialog = null, controller = null;
    function el(tag, text, cls) { var n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = String(text); if (cls) n.className = cls; return n; }
    function styles() {
        if (document.getElementById('go-history-styles')) return;
        var s = el('style'); s.id = 'go-history-styles';
        s.textContent = '.go-history-open{position:fixed;left:16px;bottom:12px;z-index:2147479000;border:0;border-radius:20px;padding:7px 14px;background:#10274a;color:#fff;font:700 12px Almarai,Tahoma,sans-serif;box-shadow:0 6px 18px #10274a40;cursor:pointer}'
            + '.go-history-link{white-space:nowrap;flex:0 0 auto;border:0;background:transparent;color:#175abb;text-decoration:underline;font-size:12px;padding:2px 4px;cursor:pointer}'
            + '.go-history{direction:rtl;border:0;border-radius:12px;padding:0;width:min(760px,95vw);max-height:85vh;box-shadow:0 20px 50px #0004;font-family:Almarai,Tahoma,sans-serif}'
            + '.go-history::backdrop{background:#0b142380}.go-history header{display:flex;gap:8px;align-items:center;padding:12px 14px;background:#10274a;color:#fff}'
            + '.go-history header h2{flex:1;margin:0;font-size:15px;color:#fff}.go-history header button{border:0;background:transparent;color:#fff;font-size:22px;cursor:pointer}'
            + '.go-history form{display:flex;gap:8px;padding:12px 14px;border-bottom:1px solid #e6e9ef}.go-history input{flex:1;padding:7px 10px;border:1px solid #cfd6e1;border-radius:6px;font-size:14px;direction:ltr;text-align:right}'
            + '.go-history form button{border:0;border-radius:6px;background:#f57800;color:#fff;padding:7px 16px;font-weight:700;cursor:pointer}'
            + '.go-history-body{padding:0 14px 10px;overflow:auto;max-height:60vh}.go-history-summary{padding-top:10px}.go-history-summary{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:8px;font-size:12px;color:#33435c}.go-history-summary b{color:#10274a}'
            + '.go-history table{width:100%;border-collapse:collapse;font-size:12px}.go-history th,.go-history td{padding:7px 6px;border-bottom:1px solid #eef1f5;text-align:right}.go-history th{background:#f4f6fa;position:sticky;top:0}'
            + '.go-history-details{white-space:nowrap;border:1px solid #175abb;border-radius:6px;background:#fff;color:#175abb;padding:3px 9px;font:700 11px Almarai,Tahoma,sans-serif;cursor:pointer}.go-history-details:hover{background:#175abb;color:#fff}'
            + '.go-history .is-open td{background:#fff8ec}.go-history-empty{color:#6c7a91;padding:14px 0;margin:0}';
        document.head.appendChild(s);
    }
    function build() {
        if (dialog) return dialog;
        styles(); dialog = el('dialog', undefined, 'go-history');
        var head = el('header'), close = el('button', '×'); close.type = 'button'; close.setAttribute('aria-label', 'إغلاق');
        head.appendChild(el('h2', 'سجل طلبات العميل')); head.appendChild(close);
        var form = el('form'), input = el('input'), go = el('button', 'بحث'); input.type = 'tel'; input.placeholder = 'رقم موبايل العميل'; input.maxLength = 30; input.dataset.historyPhone = ''; go.type = 'submit';
        form.appendChild(input); form.appendChild(go);
        var body = el('div', undefined, 'go-history-body'); body.dataset.historyBody = '';
        dialog.appendChild(head); dialog.appendChild(form); dialog.appendChild(body); document.body.appendChild(dialog);
        close.addEventListener('click', function () { dialog.close(); });
        form.addEventListener('submit', function (event) { event.preventDefault(); search(input.value); });
        return dialog;
    }
    function open(phone) {
        var d = build(), input = d.querySelector('[data-history-phone]');
        input.value = phone || ''; d.querySelector('[data-history-body]').replaceChildren();
        if (!d.open) d.showModal();
        if (phone) search(phone); else input.focus();
    }
    async function search(phone) {
        var body = dialog.querySelector('[data-history-body]'); phone = String(phone || '').trim();
        if (!phone) return;
        body.replaceChildren(el('p', 'جاري البحث…', 'go-history-empty'));
        if (controller) controller.abort(); controller = new AbortController();
        try {
            var url = new URL(feed, location.href); url.searchParams.set('phone', phone);
            var response = await fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
            var data = await response.json().catch(function () { return null; });
            if (!response.ok || !data || !data.success) throw new Error((data && data.message) || 'تعذّر جلب السجل.');
            body.replaceChildren();
            var summary = el('div', undefined, 'go-history-summary');
            [['العميل', data.name || '—'], ['الرقم', data.phone], ['عدد الطلبات', data.summary.count], ['إجمالي المدفوع', data.summary.paid_total + ' ج'], ['آخر طلب', data.summary.last || '—']].forEach(function (pair) {
                var span = el('span', pair[0] + ': '); span.appendChild(el('b', pair[1])); summary.appendChild(span);
            });
            body.appendChild(summary);
            if (!data.orders.length) { body.appendChild(el('p', 'مفيش طلبات سابقة بالرقم ده في فروعك.', 'go-history-empty')); return; }
            var table = el('table'), headRow = el('tr');
            ['التاريخ', 'النوع', 'الفرع', 'رقم الطلب', 'الإجمالي', 'الحالة', ''].forEach(function (h) { headRow.appendChild(el('th', h)); });
            var thead = el('thead'); thead.appendChild(headRow); table.appendChild(thead); var tbody = el('tbody');
            data.orders.forEach(function (o) {
                var tr = el('tr', undefined, o.status === 'جاري' ? 'is-open' : '');
                [o.at || '—', o.channel, o.branch, o.number, o.total === null || o.total === undefined ? '—' : o.total + ' ج', o.status].forEach(function (v) { tr.appendChild(el('td', v)); });
                // Beside each order: its invoice details, as the invoice review shows them.
                var cell = el('td');
                if (o.details_url && window.DashboardInvoiceDetails) {
                    var details = el('button', 'تفاصيل الفاتورة', 'go-history-details'); details.type = 'button'; details.dataset.historyDetails = o.details_url;
                    details.addEventListener('click', function () { window.DashboardInvoiceDetails.open(o.details_url); });
                    cell.appendChild(details);
                }
                tr.appendChild(cell);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody); body.appendChild(table);
        } catch (error) { if (error.name !== 'AbortError') body.replaceChildren(el('p', error.message || 'تعذّر جلب السجل.', 'go-history-empty')); }
    }
    function attach() {
        var page = document.querySelector(PAGES), button = document.querySelector('.go-history-open');
        if (!page) { if (button) button.remove(); return; }
        if (!button) { styles(); button = el('button', '🔍 سجل عميل', 'go-history-open'); button.type = 'button'; button.addEventListener('click', function () { open(''); }); document.body.appendChild(button); }
        // The delivery page: a link beside the customer's number.
        var phone = document.querySelector('#phone-orders [data-phone-field="customer_phone"]');
        if (phone && !phone.parentNode.querySelector('.go-history-link')) {
            var link = el('button', 'سجل الطلبات', 'go-history-link'); link.type = 'button';
            link.addEventListener('click', function () { open(phone.value); });
            phone.insertAdjacentElement('afterend', link);
        }
    }
    window.DashboardCustomerHistory = { open: open };
    attach(); setInterval(attach, 1500);
})();
