(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('branch-stock'); if (!root) return;
    var boot = JSON.parse(document.getElementById('branch-stock-bootstrap').textContent);
    var form = root.querySelector('[data-stock-form]'), branch = root.querySelector('[data-stock-branch]'), search = root.querySelector('[data-stock-search]');
    // Any way a quantity is typed: Arabic or Persian digits, "." or "٫" (or "," "،" when it cannot be thousands), and
    // a fraction without its leading zero (".5" is half a kilo).
    function amountText(value) {
        var text = String(value || '').trim().replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
            .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/٫/g, '.');
        if (/^\d{0,7}[,،]\d{1,3}$/.test(text) && !/^[1-9]\d{0,2}[,،]\d{3}$/.test(text)) text = text.replace(/[,،]/, '.');
        return /^\.\d/.test(text) ? '0' + text : text;
    }
    var product = form.elements.ingredient_id, current = boot.initial, items = new Map(), recipeBusy = false, busy = false, minimumBusy = false, minimumEditor = null, loading = false, frozen = null, disposed = false, dirty = false, generation = 0, timer, refreshTimer, controller, listeners = [];
    var pendingKey = 'fasakhansta:ingredient-receipt-pending:' + boot.actor_id;
    function el(tag, text, css) { var n = document.createElement(tag); if (text !== undefined) n.textContent = String(text); if (css) n.className = css; return n; }
    function on(target, event, fn) { target.addEventListener(event, fn); listeners.push(function () { target.removeEventListener(event, fn); }); }
    function notice(message, retry) { var box = root.querySelector('[data-stock-message]'); box.hidden = !message; box.textContent = message || ''; root.querySelector('[data-stock-retry]').hidden = !retry; }
    function locked() { return recipeBusy || busy || minimumBusy || loading || !!frozen; }
    function lock() {
        var stockBusy = busy || minimumBusy || !!minimumEditor || loading || !!frozen;
        root.dataset.stockBusy = String(stockBusy);
        root.dispatchEvent(new CustomEvent('inventory:stock-busy', { detail: stockBusy }));
        root.querySelectorAll('button,input,textarea,select').forEach(function (control) { if (!control.closest('[data-recipe-panel]')) control.disabled = locked() || !!minimumEditor; });
        // Search remains editable while a read is in flight; stale responses are discarded.
        search.disabled = recipeBusy || busy || minimumBusy || !!minimumEditor || !!frozen;
        root.querySelector('[data-stock-retry]').disabled = recipeBusy || busy || minimumBusy || loading || !!minimumEditor;
        if (!locked() && !minimumEditor) {
            branch.disabled = boot.branches.length === 1 && !boot.can_all;
            if (branch.value === 'all') form.querySelectorAll('button,input,textarea,select').forEach(function (control) { if (control !== search) control.disabled = true; });
            root.querySelectorAll('[data-stock-rows] .bs-item').forEach(function (control) { control.disabled = branch.value === 'all'; });
            form.elements.unit.disabled = branch.value === 'all' || !!(items.get(product.value) || {}).stock;
            root.querySelector('[data-stock-previous]').disabled = current.pagination.page <= 1;
            root.querySelector('[data-stock-next]').disabled = current.pagination.page >= current.pagination.last_page;
        }
        if (minimumEditor) minimumEditor.form.querySelectorAll('button,input').forEach(function (control) { control.disabled = locked(); });
        root.querySelector('[data-stock-save]').textContent = busy ? 'جارٍ تسجيل البضاعة…' : 'إضافة البضاعة لرصيد الفرع';
    }
    function address(value, params) { var url = new URL(value, location.href); if (url.origin !== location.origin) throw new Error('رابط العملية غير صالح.'); Object.keys(params || {}).forEach(function (key) { url.searchParams.set(key, params[key]); }); return url.href; }
    async function request(url, options) {
        var response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }, options || {}));
        var data; try { data = await response.json(); } catch (_) { data = null; }
        if (!response.ok || !data || data.success === false) { var error = new Error(response.status === 401 || response.status === 419 ? 'انتهت الجلسة. سجل الدخول ثم تحقق من آخر إضافة.' : data && data.message || 'تعذر إتمام الطلب. حاول مجددًا.'); error.status = response.status; throw error; }
        return data;
    }
    function selected() {
        var item = items.get(product.value), stock = item && item.stock, output = root.querySelector('[data-stock-current]');
        output.textContent = branch.value === 'all' ? 'اختر الفرع لتسجيل البضاعة' : stock ? stock.quantity + ' ' + stock.unit_label : item ? 'لم يبدأ تسجيل رصيد هذا الصنف' : 'اختر الصنف';
        output.classList.toggle('is-negative', !!stock && stock.negative);
        output.classList.toggle('is-low-stock', !!stock && stock.low_stock === true);
        if (stock) form.elements.unit.value = stock.unit;
        form.elements.quantity.placeholder = form.elements.unit.value === 'piece' ? '0' : '0.000';
        lock();
    }
    function canManageMinimum() { return current.can_manage_minimum === true && current.minimum_alerts_available === true && !!boot.urls.minimum_save; }
    function closeMinimum() { if (!minimumEditor || minimumBusy) return; var button = minimumEditor.button; minimumEditor.form.remove(); button.hidden = false; button.setAttribute('aria-expanded', 'false'); minimumEditor = null; lock(); button.focus(); }
    function editMinimum(cell, button, item) {
        if (locked() || minimumEditor || !canManageMinimum()) return;
        var stock = item.stock || {}, editor = el('form', undefined, 'bs-minimum-editor'), input = el('input'), actions = el('div', undefined, 'bs-minimum-actions'), save = el('button', 'حفظ', 'bs-button bs-minimum-save'), cancel = el('button', 'إلغاء', 'bs-button');
        input.type = 'text'; input.inputMode = stock.unit === 'piece' || item.unit === 'piece' ? 'numeric' : 'decimal'; input.maxLength = 16; input.autocomplete = 'off'; input.value = stock.minimum_quantity == null ? '' : stock.minimum_quantity; input.placeholder = 'بدون تنبيه'; input.setAttribute('aria-label', 'الحد الأدنى: ' + (item.branch_name ? item.branch_name + ' · ' : '') + item.name);
        save.type = 'submit'; cancel.type = 'button'; cancel.addEventListener('click', closeMinimum); actions.appendChild(save); actions.appendChild(cancel); editor.appendChild(input); editor.appendChild(actions); editor.appendChild(el('small', 'فارغ = إيقاف التنبيه · صفر = تنبيه عند النفاد'));
        minimumEditor = { form: editor, input: input, button: button }; button.hidden = true; button.setAttribute('aria-expanded', 'true'); cell.appendChild(editor); lock(); input.focus(); input.select();
        editor.addEventListener('submit', function (event) { event.preventDefault(); saveMinimum(item); });
        editor.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); closeMinimum(); } });
    }
    // Owner only: set a branch balance to the counted figure, with a reason kept in the goods movements.
    function adjustStock(item) {
        if (locked() || boot.can_adjust !== true) return;
        var stock = item.stock || {}, piece = (stock.unit || item.unit) === 'piece', unitLabel = stock.unit_label || (piece ? 'قطعة' : 'كجم'), target = item.branch || current.branch.value;
        if (!target || target === 'all') { notice('اختر الفرع أولًا لتعديل رصيده.'); return; }
        var dialog = el('dialog', undefined, 'bs-adjust'), formNode = el('form'), head = el('header'), close = el('button', '×'); close.type = 'button'; close.setAttribute('aria-label', 'إغلاق');
        head.appendChild(el('h2', 'تعديل رصيد البضاعة')); head.appendChild(close); formNode.appendChild(head);
        var body = el('div', undefined, 'bs-adjust-body'), info = el('div', undefined, 'bs-adjust-info');
        info.appendChild(el('strong', (item.branch_name ? item.branch_name + ' · ' : '') + item.name)); info.appendChild(el('span', 'الرصيد الحالي: ' + (stock.quantity || '0') + ' ' + unitLabel)); body.appendChild(info);
        var amountLabel = el('label', 'الرصيد الجديد (' + unitLabel + ')'), amount = el('input'); amount.type = 'text'; amount.inputMode = 'decimal'; amount.maxLength = 16; amount.required = true; amount.autocomplete = 'off'; amount.value = stock.quantity && !stock.negative ? stock.quantity : '0'; amountLabel.appendChild(amount); body.appendChild(amountLabel);
        var reasonLabel = el('label', 'سبب التعديل'), reason = el('input'); reason.maxLength = 300; reason.required = true; reason.placeholder = 'مثال: جرد فعلي، تالف، تصحيح تسجيل'; reasonLabel.appendChild(reason); body.appendChild(reasonLabel);
        var error = el('p', '', 'bs-adjust-error'); error.hidden = true; body.appendChild(error);
        body.appendChild(el('p', 'يتسجل الفرق كحركة «تعديل رصيد» في آخر حركات البضاعة باسمك والسبب.', 'bs-adjust-note')); formNode.appendChild(body);
        var foot = el('footer'), save = el('button', 'حفظ الرصيد', 'bs-primary'), cancel = el('button', 'إلغاء', 'bs-button'); save.type = 'submit'; cancel.type = 'button'; foot.appendChild(save); foot.appendChild(cancel); formNode.appendChild(foot);
        dialog.appendChild(formNode); document.body.appendChild(dialog);
        var key = crypto.randomUUID(), busyAdjust = false;
        function done() { if (busyAdjust) return; dialog.close(); dialog.remove(); }
        close.addEventListener('click', done); cancel.addEventListener('click', done);
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); done(); });
        dialog.addEventListener('keydown', function (event) { event.stopPropagation(); });
        formNode.addEventListener('submit', async function (event) {
            event.preventDefault(); if (busyAdjust) return;
            var value = amountText(amount.value), why = reason.value.trim(); error.hidden = true;
            if (!/^\d{1,7}(?:\.\d{1,3})?$/.test(value) || Number(value) > 1000000 || piece && !Number.isInteger(Number(value))) { error.textContent = piece ? 'أدخل عدد القطع (رقم صحيح).' : 'أدخل الوزن بالكيلو حتى 3 أرقام بعد العلامة.'; error.hidden = false; amount.focus(); return; }
            if (!why) { error.textContent = 'اكتب سبب تعديل الرصيد.'; error.hidden = false; reason.focus(); return; }
            busyAdjust = true; save.disabled = true; save.textContent = 'جارٍ الحفظ…';
            try {
                await request(address(boot.urls.adjust), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ branch: target, ingredient_id: Number(item.id), quantity: value, reason: why, expected_revision: Number(stock.revision || 0) || null, idempotency_key: key }) });
                busyAdjust = false; done(); notice('تم تعديل الرصيد إلى ' + value + ' ' + unitLabel + '.'); if (!disposed) load(current.pagination.page);
            } catch (failure) {
                busyAdjust = false; save.disabled = false; save.textContent = 'حفظ الرصيد';
                error.textContent = failure.message + (failure.status === 409 ? ' أغلق النافذة وحدّث الصفحة.' : ''); error.hidden = false;
            }
        });
        dialog.showModal(); amount.select();
    }
    async function saveMinimum(item) {
        if (locked() || !minimumEditor || !canManageMinimum()) return;
        var stock = item.stock || {}, amount = amountText(minimumEditor.input.value);
        if (amount !== '' && (!/^\d{1,7}(?:\.\d{1,3})?$/.test(amount) || Number(amount) > 1000000 || (stock.unit || item.unit) === 'piece' && !Number.isInteger(Number(amount)))) { notice('أدخل حدًا أدنى يساوي صفرًا أو أكثر؛ القطع أعداد صحيحة والكيلو حتى 3 منازل عشرية.'); minimumEditor.input.focus(); return; }
        var selectedBranch = item.branch || current.branch.value, page = current.pagination.page, refresh = false;
        minimumBusy = true; lock(); minimumEditor.form.querySelector('[type="submit"]').textContent = 'جارٍ الحفظ…';
        try {
            await request(address(boot.urls.minimum_save), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ branch: selectedBranch, stock_type: stock.stock_type || 'ingredient', stock_item_id: Number(item.id), minimum_quantity: amount === '' ? null : amount, expected_revision: Number(stock.minimum_revision || 0), idempotency_key: crypto.randomUUID() }) });
            notice(amount === '' ? 'تم إيقاف تنبيه الحد الأدنى لهذا الصنف في الفرع.' : 'تم حفظ الحد الأدنى لهذا الصنف في الفرع. يظهر بالأحمر عند الوصول إليه أو أقل.'); refresh = true;
        } catch (error) {
            notice(error.message + (error.status === 409 || !error.status ? ' جارٍ تحديث الرصيد والحد المحفوظ.' : ''));
            refresh = error.status === 409 || !error.status;
        } finally {
            minimumBusy = false;
            if (refresh) closeMinimum();
            else if (minimumEditor) minimumEditor.form.querySelector('[type="submit"]').textContent = 'حفظ';
            lock();
        }
        if (refresh && !disposed) load(page);
    }
    function render(data) {
        var chosen = product.value; current = data; items.clear(); product.replaceChildren(el('option', 'اختر صنف البضاعة')); product.firstChild.value = '';
        var body = root.querySelector('[data-stock-rows]'); body.replaceChildren();
        data.items.forEach(function (item) {
            if (!data.all_branches) items.set(String(item.id), item); var stock = item.stock, option = el('option', item.name + ' — ' + (stock ? stock.quantity + ' ' + stock.unit_label : 'دون رصيد مسجل')); option.value = item.id; product.appendChild(option);
            var row = el('tr', undefined, stock && stock.low_stock === true ? 'bs-low-stock' : ''), name = el('td'), choose = el('button', (item.branch_name ? item.branch_name + ' · ' : '') + item.name, 'bs-item'); choose.disabled = !!data.all_branches; choose.type = 'button'; choose.addEventListener('click', function () { if (locked() || minimumEditor || branch.value === 'all') return; product.value = String(item.id); selected(); form.elements.quantity.focus(); }); name.appendChild(choose); if (stock && stock.low_stock === true) name.appendChild(el('span', 'مخزون منخفض', 'bs-low-badge')); row.appendChild(name);
            row.appendChild(el('td', stock ? stock.unit_label : '—'));
            var quantityCell = el('td', stock ? stock.quantity : 'لم يبدأ التسجيل', stock && stock.negative ? 'is-negative' : 'bs-quantity');
            if (boot.can_adjust === true && boot.urls.adjust) { var adjustButton = el('button', '✎', 'bs-adjust-open'); adjustButton.type = 'button'; adjustButton.title = 'تعديل الرصيد'; adjustButton.setAttribute('aria-label', 'تعديل رصيد ' + (item.branch_name ? item.branch_name + ' · ' : '') + item.name); adjustButton.addEventListener('click', function () { adjustStock(item); }); quantityCell.appendChild(adjustButton); }
            row.appendChild(quantityCell);
            var minimum = el('td', undefined, 'bs-minimum-cell'), minimumText = stock && stock.minimum_quantity != null ? stock.minimum_quantity : 'غير محدد';
            if (canManageMinimum()) { var minimumButton = el('button', minimumText === 'غير محدد' ? 'تحديد' : minimumText, 'bs-button bs-minimum-toggle'); minimumButton.type = 'button'; minimumButton.setAttribute('aria-label', 'تعديل الحد الأدنى: ' + (item.branch_name ? item.branch_name + ' · ' : '') + item.name); minimumButton.setAttribute('aria-expanded', 'false'); minimumButton.addEventListener('click', function () { editMinimum(minimum, minimumButton, item); }); minimum.appendChild(minimumButton); }
            else minimum.appendChild(el('span', minimumText, minimumText === 'غير محدد' ? 'bs-untracked' : 'bs-quantity'));
            row.appendChild(minimum);
            row.appendChild(el('td', item.unit === 'kg' ? 'خامة بالوزن' : 'بضاعة بالقطعة')); body.appendChild(row);
        });
        if (!data.items.length) { var empty = el('tr'), cell = el('td', 'لا توجد بضاعة مطابقة للبحث.'); cell.colSpan = 5; empty.appendChild(cell); body.appendChild(empty); }
        product.value = items.has(chosen) ? chosen : '';
        root.querySelector('[data-stock-page]').textContent = data.pagination.page + ' / ' + data.pagination.last_page + ' · ' + data.pagination.total + ' صنف';
        var history = root.querySelector('[data-stock-history]'); history.replaceChildren();
        data.history.forEach(function (entry) { var row = el('tr'); [entry.created_at, (entry.branch_name ? entry.branch_name + ' · ' : '') + entry.name, entry.source_type === 'receipt' ? 'توريد بضاعة' : entry.source_type === 'expense' ? 'مشتريات مصروف #'+entry.source_id : entry.source_type === 'expense_reverse' ? 'عكس مشتريات مصروف #'+entry.source_id : entry.source_type === 'adjustment' ? 'تعديل رصيد (الأونر)' : entry.source_type === 'app' ? 'بيع تطبيق #' + entry.source_id : 'بيع فاتورة #' + entry.source_id, entry.quantity + ' ' + entry.unit_label, entry.balance + ' ' + entry.unit_label, entry.actor, [entry.supplier, entry.notes].filter(Boolean).join(' · ') || '—'].forEach(function (text, index) { row.appendChild(el('td', text, index === 3 || index === 4 ? 'bs-quantity' : '')); }); history.appendChild(row); });
        if (!data.history.length) { var row = el('tr'), cell = el('td', 'لا توجد حركات بضاعة مسجلة بعد.'); cell.colSpan = 7; row.appendChild(cell); history.appendChild(row); }
        var legacy=root.querySelector('[data-stock-legacy]'), oldRows=root.querySelector('[data-stock-legacy-rows]');legacy.hidden=!(data.legacy||[]).length;oldRows.replaceChildren();(data.legacy||[]).forEach(function(item){var row=el('tr');[(item.branch_name ? item.branch_name + ' · ' : '') + item.name,item.quantity,item.unit_label].forEach(function(value){row.appendChild(el('td',value));});oldRows.appendChild(row);});
        root.querySelector('[data-stock-guidance]').textContent=(data.all_branches ? 'عرض أرصدة جميع الفروع. اختر فرعًا لتسجيل بضاعة أو مراجعة وصفاته. ' : '')+'كروت الأصناف مرتبطة بالبضاعة والوصفات المسجلة. '+(data.unconfigured_count ? data.unconfigured_count+' صنف بيع لم تُسجّل وصفته بعد؛ لن تخصم مكونات هذه الأصناف حتى تسجيل الوصفات.' : 'راجع وصفة كل حجم من أحجام المينيو.');
        var unmapped=root.querySelector('[data-stock-unmapped]');unmapped.hidden=!(data.unmapped_sales||[]).length;unmapped.textContent=(data.unmapped_sales||[]).map(function(sale){return (sale.branch_name ? sale.branch_name + ' · ' : '')+(sale.source_type==='app'?'طلب تطبيق #':'فاتورة #')+sale.source_id+': '+sale.items.map(function(i){return i.name;}).join('، ');}).join(' | ');if(!unmapped.hidden)unmapped.prepend(document.createTextNode('مبيعات حديثة بلا وصفة مسجلة (راجع المقادير والمخزون): '));
        selected();
    }
    async function load(page) {
        if (recipeBusy || busy || minimumBusy || minimumEditor || frozen || disposed) return;
        clearTimeout(timer); if (controller) controller.abort(); controller = new AbortController(); var token = ++generation, selectedBranch = branch.value;
        loading = true; lock();
        try { var data = await request(address(boot.urls.data, { branch: selectedBranch, search: search.value.trim(), page: page || 1 }), { signal: controller.signal }); if (!disposed && token === generation && branch.value === selectedBranch) render(data); }
        catch (error) { if (!disposed && token === generation && error.name !== 'AbortError') { items.clear(); product.replaceChildren(el('option', 'تعذر تحميل الأصناف؛ اضغط تحديث')); product.firstChild.value = ''; notice(error.message); } }
        finally { if (!disposed && token === generation) { loading = false; lock(); } }
    }
    function matches(data, op) { var r = data && data.receipt; return r && r.branch === op.branch && Number(r.ingredient_id) === op.ingredient_id && r.unit === op.unit && r.quantity === op.quantity && r.idempotency_key === op.idempotency_key; }
    function clearPending() { localStorage.removeItem(pendingKey); frozen = null; }
    async function commit(op, retry) {
        if (busy || loading) return;
        try { localStorage.setItem(pendingKey, JSON.stringify(op)); if (localStorage.getItem(pendingKey) !== JSON.stringify(op)) throw new Error(); }
        catch (_) { notice('تعذر حفظ رقم الإضافة على الجهاز. فعّل تخزين المتصفح قبل تسجيل البضاعة.'); return; }
        frozen = op; busy = true; lock(); var completed = false;
        try {
            var data;
            if (retry) { var check = await request(address(boot.urls.recover, { branch: op.branch, idempotency_key: op.idempotency_key })); if (check.found) data = check; }
            if (!data) data = await request(address(boot.urls.receive), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(op) });
            if (!matches(data, op)) throw new Error('تعذر التأكد من نتيجة الإضافة. اضغط التحقق قبل إجراء إضافة أخرى.');
            clearPending(); completed = true; dirty = false; form.reset(); search.value = ''; product.value = ''; branch.value = op.branch;
            notice('تمت إضافة البضاعة. الرصيد الآن: ' + data.stock.quantity + ' ' + data.stock.unit_label);
        } catch (error) {
            // Validation is a definitive failure. Timeouts, conflicts, or lost responses keep the same operation key.
            if ([403,404,422].includes(error.status)) { clearPending(); notice(error.message); }
            else notice(error.message + ' تحقق من آخر إضافة قبل المتابعة.', true);
        } finally { busy = false; lock(); }
        if (completed) load(1);
    }
    async function recover(op) {
        busy = true; lock();
        try {
            var data = await request(address(boot.urls.recover, { branch: op.branch, idempotency_key: op.idempotency_key }));
            if (data.found && matches(data, op)) { clearPending(); dirty = false; form.reset(); branch.value = op.branch; notice('تم التحقق: الإضافة السابقة مسجلة بنجاح.'); }
            else notice('توجد إضافة لم يتم تأكيد نتيجتها. اضغط التحقق لإكمالها بنفس رقم العملية.', true);
        } catch (error) { notice(error.message, true); }
        finally { busy = false; lock(); if (!frozen) load(1); }
    }
    on(form, 'submit', function (event) {
        event.preventDefault(); if (branch.value === 'all') { notice('اختر فرعًا قبل تسجيل البضاعة.'); return; } if (locked() || !items.has(product.value)) return;
        var amount = amountText(form.elements.quantity.value);
        if (!/^\d{1,7}(?:\.\d{1,3})?$/.test(amount) || Number(amount) <= 0 || Number(amount) > 1000000 || form.elements.unit.value === 'piece' && !Number.isInteger(Number(amount))) { notice(form.elements.unit.value === 'piece' ? 'القطع أعداد صحيحة: أدخل عدد القطع.' : 'أدخل الوزن بالكيلو: نص كيلو 0.5، ربع كيلو 0.25، 100 جرام 0.1 (حتى 3 أرقام بعد العلامة؛ للآلاف اكتب الرقم بدون فاصلة).'); form.elements.quantity.focus(); return; }
        commit({ branch: branch.value, ingredient_id: Number(product.value), quantity: amount, unit: form.elements.unit.value, supplier: form.elements.supplier.value.trim(), notes: form.elements.notes.value.trim(), idempotency_key: crypto.randomUUID() }, false);
    });
    on(form, 'input', function (event) { if (event.target !== search) dirty = true; });
    on(product, 'change', selected); on(form.elements.unit, 'change', selected);
    on(search, 'input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
    on(branch, 'change', function () { if (locked()) return; if (dirty && !confirm('هل تريد تغيير الفرع وترك الإضافة غير المسجلة؟')) { branch.value = current.branch.value; return; } var changing=new CustomEvent('inventory:before-branch',{cancelable:true});if(!root.dispatchEvent(changing)){branch.value=current.branch.value;return;}dirty = false; form.reset(); search.value = ''; product.value = ''; load(1);root.dispatchEvent(new Event('inventory:branch')); });
    on(root.querySelector('[data-stock-refresh]'), 'click', function () { load(current.pagination.page); });
    on(root.querySelector('[data-stock-previous]'), 'click', function () { load(current.pagination.page - 1); });
    on(root.querySelector('[data-stock-next]'), 'click', function () { load(current.pagination.page + 1); });
    on(root.querySelector('[data-stock-retry]'), 'click', function () { if (frozen) commit(frozen, true); });
    on(window, 'beforeunload', function (event) { if (dirty || frozen || busy || minimumBusy || minimumEditor) { event.preventDefault(); event.returnValue = ''; } });
    function refreshIdle() { return !disposed && !locked() && !dirty && !minimumEditor; }
    if (window.DashboardSPA) { window.DashboardSPA.onBeforeLeave(function () { if (minimumBusy) { notice('انتظر اكتمال حفظ الحد الأدنى قبل مغادرة الصفحة.'); return false; } if (frozen || busy) { notice('تحقق من آخر إضافة قبل مغادرة الصفحة.', true); return false; } return !(dirty || minimumEditor) || confirm('مغادرة الصفحة دون حفظ التغييرات؟'); }); if (window.DashboardSPA.onIdle) window.DashboardSPA.onIdle(refreshIdle); window.DashboardSPA.onCleanup(function () { disposed = true; generation++; clearTimeout(timer); clearInterval(refreshTimer); if (controller) controller.abort(); listeners.forEach(function (off) { off(); }); }); }
    on(root,'inventory:busy',function(event){recipeBusy=!!event.detail;lock();});
    on(root,'inventory:recipe-saved',function(){load(1);});
    render(boot.initial);
    refreshTimer = setInterval(function () { if (!document.hidden && !disposed && !dirty && !minimumEditor && !locked()) load(current.pagination.page); }, 30000);
    try { var stored = JSON.parse(localStorage.getItem(pendingKey) || 'null'); if (stored) { frozen = stored; branch.value = stored.branch; notice('جارٍ التحقق من الإضافة السابقة…', true); lock(); recover(stored); } }
    catch (_) { notice('تعذر قراءة الإضافة السابقة المخزنة على الجهاز.'); }
}());
