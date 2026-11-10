(function () {
    'use strict';
    if (window.DashboardInvoiceDetails) window.DashboardInvoiceDetails.close();
    var active;
    function close() { if (active) { active.abort.abort(); active.dialog.close(); active.dialog.remove(); if (active.focus && active.focus.isConnected) active.focus.focus(); active = null; } }
    async function open(url) {
        if (!url) return;
        var target = new URL(url, location.href);
        if (target.origin !== location.origin || !/\/(?:receipts|tickets|branch-shifts)\/[1-9][0-9]*\/details$/.test(target.pathname)) return;
        close();
        var ar = document.documentElement.lang === 'ar', dialog = document.createElement('dialog'), header = document.createElement('div'), title = document.createElement('strong'), button = document.createElement('button'), message = document.createElement('p');
        dialog.className = 'dashboard-invoice-details'; dialog.setAttribute('aria-label', ar ? 'تفاصيل الفاتورة' : 'Invoice details');
        title.textContent = ar ? 'تفاصيل الفاتورة' : 'Invoice details'; button.type = 'button'; button.textContent = ar ? 'إغلاق' : 'Close'; button.addEventListener('click', close);
        header.append(title, button); dialog.append(header, message); message.textContent = ar ? 'جاري التحميل…' : 'Loading…';
        var current = { dialog: dialog, abort: new AbortController(), focus: document.activeElement }; active = current;
        dialog.addEventListener('keydown', function (event) { event.stopPropagation(); });
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
        document.body.appendChild(dialog); dialog.showModal(); button.focus();
        var timeout = setTimeout(function () { current.abort.abort(); }, 15000);
        try {
            var response = await fetch(target.href, { credentials: 'same-origin', signal: current.abort.signal, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Unavailable');
            var html = await response.text(), documentCopy = new DOMParser().parseFromString(html, 'text/html');
            if (!documentCopy.documentElement.hasAttribute('data-dashboard-invoice-details')) throw new Error('Invalid invoice');
            if (active !== current) return;
            // Details use a script-free, sandboxed document; opening it can never invoke printing.
            documentCopy.querySelectorAll('script,iframe,object,embed,form,base,meta[http-equiv]').forEach(function (el) { el.remove(); });
            documentCopy.querySelectorAll('*').forEach(function (el) { Array.from(el.attributes).forEach(function (attr) { if (/^on/i.test(attr.name)) el.removeAttribute(attr.name); }); });
            var frame = document.createElement('iframe'); frame.title = title.textContent; frame.setAttribute('sandbox', ''); frame.srcdoc = '<!doctype html>' + documentCopy.documentElement.outerHTML;
            message.replaceWith(frame);
        } catch (_) { if (active === current) message.textContent = ar ? 'تعذر تحميل تفاصيل الفاتورة. أغلق النافذة وحاول مرة أخرى.' : 'Could not load invoice details. Close and try again.'; }
        finally { clearTimeout(timeout); }
    }
    var style = document.getElementById('dashboard-details-style') || document.createElement('style'); style.id = 'dashboard-details-style'; style.textContent = '.dashboard-details-button,[data-dining-details],[data-pos-details]{display:inline-flex;align-items:center;gap:6px;margin:4px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#f1f5f9;color:#18314d;font:inherit;font-weight:600;font-size:13px;cursor:pointer}.dashboard-details-button:hover,[data-dining-details]:hover{background:#e2e8f0}' + '.dashboard-invoice-details{width:min(760px,96vw);max-width:96vw;height:90vh;max-height:90vh;padding:0;border:1px solid #d6dce5;border-radius:14px;color:#172137;background:#edf1f6;box-shadow:0 15px 60px #0004}.dashboard-invoice-details::backdrop{background:#07152599}.dashboard-invoice-details>div{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 18px;background:white;height:60px}.dashboard-invoice-details button{font:inherit;padding:7px 16px;border:1px solid #d6dce5;background:#fff;border-radius:7px;cursor:pointer}.dashboard-invoice-details iframe{width:100%;height:calc(100% - 60px);border:0;display:block}.dashboard-invoice-details>p{padding:20px}'; document.head.appendChild(style);
    window.DashboardInvoiceDetails = { open: open, close: close };
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(close);
}());

// invoice-date-review-all-20261106
(function () {
    'use strict';
    function node(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = String(text);
        return element;
    }
    function sameOrigin(url) {
        try { var target = new URL(url, location.href); return target.origin === location.origin ? target.href : null; }
        catch (_) { return null; }
    }
    async function show(options) {
        var content = options.content, labels = options.labels || {};
        function label(key) { return labels[key] || key; }
        function current() { return !options.signal.aborted && options.isCurrent(); }
        if (!current()) return;
        var form = node('form', 'ih-filter'); form.setAttribute('data-spa-off', '');
        var input = node('input'); input.type = 'date'; input.required = true;
        input.setAttribute('data-invoice-history-date', '');
        var initialDate = options.date || options.initialDate || ''; input.value = initialDate;
        var field = node('label'); field.appendChild(node('span', '', label('invoice_date'))); field.appendChild(input); form.appendChild(field);
        var submit = node('button', '', label('show_invoices')); submit.type = 'submit'; form.appendChild(submit);
        var today = node('button', '', label('history_today')); today.type = 'button';
        today.addEventListener('click', function () { if (current()) options.reload(); }); form.appendChild(today);
        form.addEventListener('submit', function (event) { event.preventDefault(); if (current() && input.reportValidity()) options.reload(1, input.value); });
        var results = node('div', 'ih-results go-inv-results'); results.setAttribute('aria-live', 'polite'); results.setAttribute('aria-busy', 'true');
        results.appendChild(node('p', 'ih-empty', label('invoices_loading')));
        content.replaceChildren(form, node('p', 'ih-hint', label('invoice_day_hint')), results);
        var feedback = node('p', 'ih-feedback'); feedback.setAttribute('role', 'status');
        try {
            var url = sameOrigin(options.endpoint(options.url, { branch: options.branch || 'all', page: options.page || 1, per_page: 500, date: options.date || undefined }));
            if (!url) throw new Error(label('forbidden'));
            var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: options.signal, headers: { Accept: 'application/json' } });
            var result = await options.read(response, 'invoices_error');
            if (!current()) return;
            var selectedDate = result.today && result.today.date || options.date || initialDate;
            if (!options.date && input.value === initialDate) input.value = selectedDate;
            results.replaceChildren();
            var summary = node('p', 'ih-summary'); summary.appendChild(node('bdi', '', selectedDate));
            if (result.today) {
                summary.appendChild(node('span', '', label('history_count') + ': ' + result.today.count));
                summary.appendChild(node('span', '', label('total') + ': ' + options.money(result.today.total) + ' ' + label('currency')));
            }
            results.appendChild(summary);
            var items = result.items || result.receipts || [];
            if (!items.length) results.appendChild(node('p', 'ih-empty', label('daily_empty')));
            else {
                if (window.DashboardInvoiceRows) window.DashboardInvoiceRows.styles(); var wrapper = node('div', 'ih-table-wrap go-inv-scroll'), table = node('table', 'ih-table go-inv-table'), head = node('thead'), headings = node('tr'), body = node('tbody');
                ['invoice_number', 'branch', 'channel', 'date', 'payment_method', 'total', 'print_invoice'].forEach(function (key) { headings.appendChild(node('th', '', label(key))); });
                head.appendChild(headings); table.appendChild(head);
                items.forEach(function (item) {
                    var row = node('tr');
                    [item.number || item.id, item.branch && item.branch.name || '—', label(item.channel || 'takeaway')].forEach(function (value) { row.appendChild(node('td', '', value)); });
                    var when = node('td'); when.appendChild(window.DashboardInvoiceRows ? window.DashboardInvoiceRows.when(item.created_at, item.created_label) : document.createTextNode(item.created_label || item.created_at || '')); row.appendChild(when);
                    row.appendChild(node('td', '', item.payment_label || label(item.payment_method === 'mobile_wallet' ? 'wallet' : item.payment_method)));
                    row.appendChild(node('td', 'go-inv-total', options.money(item.total)));
                    var actions = node('td', 'ih-actions go-inv-actions'), receiptURL = item.receipt_url && sameOrigin(item.receipt_url);
                    var printable = item.can_reprint === true && (!options.canReprint || options.canReprint(item));
                    if (printable && receiptURL && (options.print || window.DashboardPrint && window.DashboardPrint.print)) {
                        var print = node('a', '', label('print_invoice')); print.href = receiptURL;
                        print.addEventListener('click', async function (event) {
                            event.preventDefault(); if (!current()) return;
                            try { if (options.print) await options.print(receiptURL); else await window.DashboardPrint.print(receiptURL); }
                            catch (_) { if (current()) feedback.textContent = label('print_error'); }
                        }); actions.appendChild(print);
                    }
                    var detailsURL = item.details_url && sameOrigin(item.details_url);
                    if (detailsURL) {
                        var details = node('button', 'dashboard-details-button', label('details')); details.type = 'button';
                        details.addEventListener('click', function () { if (current() && window.DashboardInvoiceDetails) window.DashboardInvoiceDetails.open(detailsURL); });
                        actions.appendChild(details);
                    }
                    row.appendChild(actions); body.appendChild(row);
                });
                table.appendChild(body); wrapper.appendChild(table); results.appendChild(wrapper);
                if (window.DashboardInvoiceRows) window.DashboardInvoiceRows.stick(table);
            }
            var pages = result.pagination || {};
            if (pages.last_page > 1) {
                var controls = node('nav', 'ih-pagination'); controls.setAttribute('aria-label', label('invoice_history'));
                ['previous', 'next'].forEach(function (direction) {
                    var button = node('button', '', label(direction)); button.type = 'button';
                    button.disabled = direction === 'previous' ? pages.page <= 1 : pages.page >= pages.last_page;
                    button.addEventListener('click', function () { if (current()) options.reload(pages.page + (direction === 'next' ? 1 : -1), selectedDate); }); controls.appendChild(button);
                }); results.appendChild(controls);
            }
            results.appendChild(feedback);
        } catch (error) {
            if (!current() || error.name === 'AbortError') return;
            results.replaceChildren(node('p', 'ih-empty', error.message || label('invoices_error')));
        } finally { results.setAttribute('aria-busy', 'false'); }
    }
    window.DashboardInvoiceHistory = { show: show };
}());

// invoice-review-rows-20261109: time beside the date on one line, the two actions side by side, compact rows.
(function () {
    'use strict';
    var clock = new Intl.DateTimeFormat('en-US', { timeZone: 'Africa/Cairo', hour: 'numeric', minute: '2-digit', hour12: true });
    var day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' });
    function when(value, fallback) {
        var cell = document.createElement('span'), date = value ? new Date(value) : null;
        cell.className = 'go-inv-when';
        if (!date || isNaN(date.getTime())) { cell.textContent = fallback || value || '—'; return cell; }
        var parts = {}; clock.formatToParts(date).forEach(function (part) { parts[part.type] = part.value; });
        var time = document.createElement('b'), small = document.createElement('small');
        time.textContent = parts.hour + ':' + parts.minute + ' ' + (String(parts.dayPeriod).toUpperCase() === 'PM' ? 'م' : 'ص');
        small.textContent = day.format(date);
        cell.append(time, small); return cell;
    }
    function styles() {
        // Always rewrite: a page opened with an older copy of these rules keeps its <style> between pages.
        var style = document.getElementById('go-inv-rows-style');
        if (!style) { style = document.createElement('style'); style.id = 'go-inv-rows-style'; document.head.appendChild(style); }
        var frames = ['.tp-modal', '.dp-modal', '.ph-modal'], has = function (suffix) { return frames.map(function (f) { return f + ':has(.go-inv-table)' + suffix; }).join(','); };
        // One look in the takeaway, dining and delivery reviews: a wider window; the title, date, summary and the
        // heading row stay fixed while only the invoices scroll.
        style.textContent = has('') + '{width:min(920px,100%)!important;max-height:88vh!important;display:flex!important;flex-direction:column!important}'
            + '.tp-modal-content:has(.go-inv-table),.dp-modal-content:has(.go-inv-table),.ph-modal-content:has(.go-inv-table){display:flex!important;flex-direction:column!important;flex:1 1 auto;min-height:0;overflow:hidden!important;gap:0!important;padding:14px!important}'
            + '.go-inv-results{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}'
            + '.go-inv-scroll{flex:1 1 auto;min-height:0;overflow:auto!important;max-width:100%;border:1px solid #e5eaf0;border-radius:10px;background:#fff}'
            + '.go-inv-table{width:100%!important;min-width:0!important;border-collapse:separate!important;border-spacing:0;font-size:12px!important;margin:0!important}'
            + '.go-inv-table th{white-space:nowrap;background:#f4f6fa!important;color:#34465b;font-weight:700;position:sticky;top:0;z-index:2;box-shadow:0 1px 0 #dfe5ee}'
            + '.go-inv-table th,.go-inv-table td{vertical-align:middle;padding:8px 9px!important;text-align:start;border-bottom:1px solid #eef1f5;overflow-wrap:normal!important;word-break:normal!important}'
            + '.go-inv-table tbody tr:nth-child(even){background:#f8fafc}.go-inv-table tbody tr:hover{background:#fff6ea}'
            + '.go-inv-table td:nth-child(-n+3){white-space:nowrap}.go-inv-table td:first-child{font-weight:700;direction:ltr;text-align:right}'
            + '.go-inv-when{display:inline-flex;align-items:baseline;gap:7px;white-space:nowrap}.go-inv-when b{font-weight:700;color:#172137}.go-inv-when small{color:#6c7a91;font-size:11px;direction:ltr;unicode-bidi:isolate}'
            + '.go-inv-table td.go-inv-total{white-space:nowrap;font-weight:700}'
            + '.go-inv-table td.go-inv-actions{white-space:nowrap;min-width:0!important}'
            + '.go-inv-actions>a,.go-inv-actions>button{display:inline-flex!important;align-items:center;margin:0!important;margin-inline-end:6px!important;padding:5px 11px!important;border:1px solid #cbd5e1!important;border-radius:7px!important;background:#f1f5f9!important;color:#18314d!important;font-size:12px!important;font-weight:600;text-decoration:none!important;line-height:1.4;cursor:pointer;vertical-align:middle}'
            + '.go-inv-actions>a{background:#fff4e8!important;border-color:#f6c58f!important;color:#b85a00!important}'
            + '.go-inv-results .ih-summary,.go-inv-results .tp-history-summary{flex:0 0 auto}.go-inv-results .ih-pagination,.go-inv-results .tp-pagination{flex:0 0 auto;margin-top:8px}';
    }
    function stick() {}
    window.DashboardInvoiceRows = { when: when, styles: styles, stick: stick };
}());
