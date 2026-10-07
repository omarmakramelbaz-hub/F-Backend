(function () {
    'use strict';
    if (window.DashboardInvoiceDetails) window.DashboardInvoiceDetails.close();
    var active;
    function close() { if (active) { active.abort.abort(); active.dialog.close(); active.dialog.remove(); if (active.focus && active.focus.isConnected) active.focus.focus(); active = null; } }
    async function open(url) {
        if (!url) return;
        var target = new URL(url, location.href);
        if (target.origin !== location.origin || !/\/(?:receipts|tickets)\/[1-9][0-9]*\/details$/.test(target.pathname)) return;
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
