(function () {
    'use strict';
    var root = document.querySelector('[data-app-invoice]');
    if (!root) return;
    var saveButton = root.querySelector('[data-app-save]'), printButton = root.querySelector('[data-app-print]');
    var reloadButton = root.querySelector('[data-app-reload]'), message = root.querySelector('[data-app-message]');
    var order = null, lines = [], busy = false, blocked = false, dirty = false, navigating = false;
    function url(value) {
        var parsed = new URL(value, window.location.href);
        if (parsed.origin !== window.location.origin || !/^https?:$/.test(parsed.protocol)) throw new Error('رابط الفاتورة غير صالح.');
        return parsed.toString();
    }
    function scaled(value, places) {
        var normalized = String(value == null ? '' : value).trim().replace(/[٠-٩]/g, function (digit) { return String(digit.charCodeAt(0) - 1632); }).replace(/[۰-۹]/g, function (digit) { return String(digit.charCodeAt(0) - 1776); }).replace(/٫/g, '.');
        var match = normalized.match(new RegExp('^(\\d{1,10})(?:\\.(\\d{1,' + places + '}))?$'));
        return match ? BigInt(match[1]) * 10n ** BigInt(places) + BigInt((match[2] || '').padEnd(places, '0')) : null;
    }
    function fixed(value, places) {
        var base = 10n ** BigInt(places);
        return String(value / base) + '.' + String(value % base).padStart(places, '0');
    }
    function notify(value) { if (message) { message.textContent = value || ''; message.hidden = !value; } }
    function valid(line) {
        var amount = scaled(line.quantity, 3);
        return !line.invalid && amount !== null && amount > 0n && amount <= 1000000n && !(String(line.quantity_step) === '1' && amount % 1000n !== 0n);
    }
    function lineAmount(line) {
        var amount = scaled(line.quantity, 3), price = scaled(line.unit_price, 2);
        if (!valid(line) || price === null) return null;
        return amount === scaled(line.original_quantity, 3) ? scaled(line.original_total, 2) : (price * amount + 500n) / 1000n;
    }
    function controls() {
        root.querySelectorAll('[data-app-line-quantity]').forEach(function (input) { input.disabled = busy || blocked; });
        if (saveButton) saveButton.disabled = busy || blocked || !order || !dirty || lines.some(function (line) { return !valid(line); });
        if (printButton) printButton.disabled = busy || blocked || !!saveButton && (!order || lines.some(function (line) { return !valid(line); }));
        if (reloadButton) { reloadButton.hidden = !blocked; reloadButton.disabled = busy; }
    }
    function amountRow(label, amount, key) {
        var row = document.createElement('div'), title = document.createElement('span'), value = document.createElement('span'), number = document.createElement('bdi');
        row.className = 'summary-row' + (key === 'total' ? ' total' : ''); number.className = 'number'; number.dataset.appTotal = key;
        title.textContent = label; number.textContent = fixed(amount, 2); value.append(number, document.createTextNode(' ج.م')); row.append(title, value); return row;
    }
    function totals() {
        if (!order) return;
        var invalid = false, subtotal = 0n;
        lines.forEach(function (line) {
            var amount = lineAmount(line); if (amount === null) invalid = true; else subtotal += amount;
            line.row.querySelector('[data-app-line-total]').textContent = amount === null ? '—' : fixed(amount, 2);
        });
        if (invalid) { controls(); return; }
        var pricing = order.pricing || {}, delivery = scaled(pricing.delivery == null ? order.delivery : pricing.delivery, 2) || 0n;
        var tax = !dirty ? scaled(order.tax, 2) || 0n : (subtotal * (scaled(pricing.tax_rate || '0', 2) || 0n) + 5000n) / 10000n;
        var service = !dirty ? scaled(order.service, 2) || 0n : (subtotal * (scaled(pricing.service_rate || '0', 2) || 0n) + 5000n) / 10000n;
        var summary = root.querySelector('[data-app-summary]');
        summary.replaceChildren(amountRow('قيمة الأصناف', subtotal, 'subtotal'));
        [['التوصيل', delivery, 'delivery'], ['الضريبة', tax, 'tax'], ['رسوم الخدمة', service, 'service']].forEach(function (item) { if (item[1]) summary.appendChild(amountRow(item[0], item[1], item[2])); });
        summary.appendChild(amountRow('إجمالي الطلب', subtotal + delivery + tax + service, 'total')); controls();
    }
    async function read(response) {
        var payload; try { payload = await response.json(); } catch (_) { payload = null; }
        if (!response.ok || !payload || payload.success !== true) {
            var text = response.status === 401 || response.status === 419 ? 'انتهت الجلسة؛ أعد تحميل الصفحة.' : response.status === 403 ? 'ليست لديك صلاحية تعديل أو طباعة هذه الفاتورة.' : response.status === 409 ? 'تغيرت الفاتورة أو صدرت للطباعة؛ أعد تحميلها قبل المتابعة.' : payload && payload.message || 'تعذر تأكيد حفظ الفاتورة. أعد تحميلها للتحقق.';
            if (response.status === 422 && payload && payload.errors) text = Object.values(payload.errors).map(function (value) { return Array.isArray(value) ? value[0] : value; }).join('\n');
            var error = new Error(text); error.status = response.ok ? 0 : response.status; throw error;
        }
        return payload;
    }
    async function load() {
        var payload = await read(await fetch(url(root.dataset.appItemsUrl), {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}}));
        if (!payload.order || !/^[a-f0-9]{64}$/.test(payload.order.revision || '') || !Array.isArray(payload.order.items) || !payload.order.items.length || typeof payload.save_url !== 'string' || !payload.save_url) throw new Error('تفاصيل تعديل الفاتورة غير متاحة. أعد تحميل الصفحة.');
        var rows = Array.from(root.querySelectorAll('[data-app-line-id]')), mapped = new Map();
        rows.forEach(function (row) { var key = row.dataset.appLineId; if (key === '' || mapped.has(key)) throw new Error('تغيرت أصناف الفاتورة. أعد تحميل الصفحة.'); mapped.set(key, row); });
        var next = payload.order.items.map(function (saved) {
            var row = mapped.get(String(saved.line_id)); if (!row || scaled(saved.quantity, 3) === null || scaled(saved.unit_price, 2) === null || scaled(saved.line_total, 2) === null) throw new Error('تغيرت أصناف الفاتورة. أعد تحميل الصفحة.');
            mapped.delete(String(saved.line_id));
            return Object.assign({}, saved, {quantity: String(saved.quantity), original_quantity: String(saved.quantity), original_total: saved.line_total, row: row, invalid: false});
        });
        if (mapped.size) throw new Error('تغيرت أصناف الفاتورة. أعد تحميل الصفحة.');
        order = payload.order; order.save_url = url(payload.save_url); lines = next; dirty = false;
        lines.forEach(function (line, index) {
            var input = document.createElement('input'), unit = document.createElement('small'), factor = scaled(line.weight_per_unit, 3) || 0n;
            input.className = 'app-line-quantity'; input.type = 'text'; input.inputMode = factor || line.unit === 'kg' ? 'decimal' : 'numeric'; input.maxLength = 10;
            input.value = factor ? fixed((scaled(line.quantity, 3) * factor + 500n) / 1000n, 3) : line.quantity;
            input.dataset.appLineQuantity = String(index); input.setAttribute('aria-label', (factor || line.unit === 'kg' ? 'الوزن بالكيلو' : 'الكمية') + ' · ' + line.name);
            unit.className = 'app-line-unit'; unit.textContent = factor || line.unit === 'kg' ? 'كجم' : line.unit === 'piece' ? 'قطعة' : 'الكمية';
            line.row.querySelector('[data-app-quantity]').replaceChildren(input, unit);
        });
        totals();
    }
    function goPrint() { navigating = true; window.location.assign(url(root.dataset.appPrintUrl)); }
    async function save(printAfter) {
        if (busy || blocked || !order || lines.some(function (line) { return !valid(line); })) return;
        if (!dirty) { if (printAfter) goPrint(); return; }
        busy = true; controls(); notify('جارٍ حفظ الوزن والكمية…');
        try {
            var csrf = document.querySelector('meta[name="csrf-token"]');
            await read(await fetch(order.save_url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.content : ''}, body: JSON.stringify({expected_revision: order.revision, items: lines.map(function (line) {
                return {line_id: line.line_id, product_id: line.product_id, option_id: line.option_id || '', feature_id: line.feature_id == null ? null : line.feature_id, product_clean: line.product_clean || null, quantity: line.quantity};
            })})}));
            dirty = false;
            if (printAfter) { goPrint(); return; }
            await load(); notify('تم حفظ الوزن والكمية وتحديث الإجمالي.');
        } catch (error) {
            blocked = !error.status || error.status === 401 || error.status === 403 || error.status === 409 || error.status === 419 || error.status >= 500;
            notify(error.message || 'تعذر تأكيد الحفظ. أعد تحميل الفاتورة للتحقق.');
        } finally { busy = false; controls(); }
    }
    root.addEventListener('input', function (event) {
        if (!event.target.matches('[data-app-line-quantity]') || busy || blocked) return;
        var line = lines[Number(event.target.dataset.appLineQuantity)], amount = scaled(event.target.value, 3), factor = scaled(line.weight_per_unit, 3) || 0n;
        var nativeAmount = amount === null ? null : factor ? amount * 1000n / factor : amount;
        line.invalid = amount === null || factor && amount * 1000n % factor !== 0n;
        line.quantity = nativeAmount === null ? '' : fixed(nativeAmount, 3);
        dirty = lines.some(function (item) { return item.invalid || scaled(item.quantity, 3) !== scaled(item.original_quantity, 3); });
        event.target.setAttribute('aria-invalid', String(!valid(line))); notify(valid(line) ? '' : 'أدخل كمية صحيحة أكبر من صفر، والوزن حتى ثلاث منازل عشرية.'); totals();
    });
    root.addEventListener('focusin', function (event) { if (event.target.matches('[data-app-line-quantity]')) event.target.select(); });
    if (saveButton) saveButton.addEventListener('click', function () { save(false); });
    if (printButton) printButton.addEventListener('click', function () { if (saveButton) save(true); else if (!busy && !blocked) goPrint(); });
    if (reloadButton) reloadButton.addEventListener('click', function () { window.location.reload(); });
    window.addEventListener('beforeunload', function (event) { if (!navigating && (dirty || busy)) { event.preventDefault(); event.returnValue = ''; } });
    if (saveButton) {
        busy = true; controls(); notify('جارٍ تحميل بيانات الوزن والكمية…');
        load().then(function () { notify('يمكنك تعديل الوزن والكمية قبل إصدار الفاتورة للطباعة.'); }).catch(function (error) { blocked = true; notify(error.message || 'تعذر تحميل بيانات التعديل. أعد تحميل الفاتورة.'); }).finally(function () { busy = false; controls(); });
    }
}());
