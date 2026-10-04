(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('branch-stock'); if (!root) return;
    var boot = JSON.parse(document.getElementById('branch-stock-bootstrap').textContent);
    var form = root.querySelector('[data-stock-form]'), branch = root.querySelector('[data-stock-branch]'), search = root.querySelector('[data-stock-search]');
    var product = form.elements.ingredient_id, current = boot.initial, items = new Map(), recipeBusy = false, busy = false, loading = false, frozen = null, disposed = false, dirty = false, generation = 0, timer, controller, listeners = [];
    var pendingKey = 'fasakhansta:ingredient-receipt-pending:' + boot.actor_id;
    function el(tag, text, css) { var n = document.createElement(tag); if (text !== undefined) n.textContent = String(text); if (css) n.className = css; return n; }
    function on(target, event, fn) { target.addEventListener(event, fn); listeners.push(function () { target.removeEventListener(event, fn); }); }
    function notice(message, retry) { var box = root.querySelector('[data-stock-message]'); box.hidden = !message; box.textContent = message || ''; root.querySelector('[data-stock-retry]').hidden = !retry; }
    function locked() { return recipeBusy || busy || loading || !!frozen; }
    function lock() {
        root.querySelectorAll('button,input,textarea,select').forEach(function (control) { if (!control.closest('[data-recipe-panel]')) control.disabled = locked(); });
        // Search remains editable while a read is in flight; stale responses are discarded.
        search.disabled = recipeBusy || busy || !!frozen;
        root.querySelector('[data-stock-retry]').disabled = recipeBusy || busy || loading;
        if (!locked()) {
            branch.disabled = boot.branches.length === 1;
            form.elements.unit.disabled = !!(items.get(product.value) || {}).stock;
            root.querySelector('[data-stock-previous]').disabled = current.pagination.page <= 1;
            root.querySelector('[data-stock-next]').disabled = current.pagination.page >= current.pagination.last_page;
        }
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
        output.textContent = stock ? stock.quantity + ' ' + stock.unit_label : item ? 'لم يبدأ تسجيل رصيد هذا الصنف' : 'اختر الصنف';
        output.classList.toggle('is-negative', !!stock && stock.negative);
        if (stock) form.elements.unit.value = stock.unit;
        form.elements.quantity.placeholder = form.elements.unit.value === 'piece' ? '0' : '0.000';
        lock();
    }
    function render(data) {
        var chosen = product.value; current = data; items.clear(); product.replaceChildren(el('option', 'اختر صنف البضاعة')); product.firstChild.value = '';
        var body = root.querySelector('[data-stock-rows]'); body.replaceChildren();
        data.items.forEach(function (item) {
            items.set(String(item.id), item); var stock = item.stock, option = el('option', item.name + ' — ' + (stock ? stock.quantity + ' ' + stock.unit_label : 'دون رصيد مسجل')); option.value = item.id; product.appendChild(option);
            var row = el('tr'), name = el('td'), choose = el('button', item.name, 'bs-item'); choose.type = 'button'; choose.addEventListener('click', function () { if (locked()) return; product.value = String(item.id); selected(); form.elements.quantity.focus(); }); name.appendChild(choose); row.appendChild(name);
            row.appendChild(el('td', stock ? stock.unit_label : '—'));
            row.appendChild(el('td', stock ? stock.quantity : 'لم يبدأ التسجيل', stock && stock.negative ? 'is-negative' : 'bs-quantity'));
            row.appendChild(el('td', item.unit === 'kg' ? 'خامة بالوزن' : 'بضاعة بالقطعة')); body.appendChild(row);
        });
        if (!data.items.length) { var empty = el('tr'), cell = el('td', 'لا توجد بضاعة مطابقة للبحث.'); cell.colSpan = 4; empty.appendChild(cell); body.appendChild(empty); }
        product.value = items.has(chosen) ? chosen : '';
        root.querySelector('[data-stock-page]').textContent = data.pagination.page + ' / ' + data.pagination.last_page + ' · ' + data.pagination.total + ' صنف';
        var history = root.querySelector('[data-stock-history]'); history.replaceChildren();
        data.history.forEach(function (entry) { var row = el('tr'); [entry.created_at, entry.name, entry.source_type === 'receipt' ? 'توريد بضاعة' : entry.source_type === 'app' ? 'بيع تطبيق #' + entry.source_id : 'بيع فاتورة #' + entry.source_id, entry.quantity + ' ' + entry.unit_label, entry.balance + ' ' + entry.unit_label, entry.actor, [entry.supplier, entry.notes].filter(Boolean).join(' · ') || '—'].forEach(function (text, index) { row.appendChild(el('td', text, index === 3 || index === 4 ? 'bs-quantity' : '')); }); history.appendChild(row); });
        if (!data.history.length) { var row = el('tr'), cell = el('td', 'لا توجد حركات بضاعة مسجلة بعد.'); cell.colSpan = 7; row.appendChild(cell); history.appendChild(row); }
        var legacy=root.querySelector('[data-stock-legacy]'), oldRows=root.querySelector('[data-stock-legacy-rows]');legacy.hidden=!(data.legacy||[]).length;oldRows.replaceChildren();(data.legacy||[]).forEach(function(item){var row=el('tr');[item.name,item.quantity,item.unit_label].forEach(function(value){row.appendChild(el('td',value));});oldRows.appendChild(row);});
        root.querySelector('[data-stock-guidance]').textContent='البضاعة مستقلة عن المينيو. '+(data.unconfigured_count ? data.unconfigured_count+' صنف بيع لم تُسجّل وصفته بعد؛ لن تخصم مكونات هذه الأصناف حتى تسجيل الوصفات.' : 'راجع وصفة كل حجم من أحجام المينيو.');
        var unmapped=root.querySelector('[data-stock-unmapped]');unmapped.hidden=!(data.unmapped_sales||[]).length;unmapped.textContent=(data.unmapped_sales||[]).map(function(sale){return (sale.source_type==='app'?'طلب تطبيق #':'فاتورة #')+sale.source_id+': '+sale.items.map(function(i){return i.name;}).join('، ');}).join(' | ');if(!unmapped.hidden)unmapped.prepend(document.createTextNode('مبيعات حديثة بلا وصفة مسجلة (راجع المقادير والمخزون): '));
        selected();
    }
    async function load(page) {
        if (recipeBusy || busy || frozen || disposed) return;
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
        event.preventDefault(); if (locked() || !items.has(product.value)) return;
        var amount = form.elements.quantity.value.trim().replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/٫/g, '.');
        if (!/^\d{1,7}(?:\.\d{1,3})?$/.test(amount) || Number(amount) <= 0 || Number(amount) > 1000000 || form.elements.unit.value === 'piece' && !Number.isInteger(Number(amount))) { notice('أدخل كمية موجبة: القطع أعداد صحيحة والكيلو حتى 3 منازل عشرية.'); form.elements.quantity.focus(); return; }
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
    on(window, 'beforeunload', function (event) { if (dirty || frozen || busy) { event.preventDefault(); event.returnValue = ''; } });
    if (window.DashboardSPA) { window.DashboardSPA.onBeforeLeave(function () { if (frozen || busy) { notice('تحقق من آخر إضافة قبل مغادرة الصفحة.', true); return false; } return !dirty || confirm('مغادرة الصفحة دون تسجيل الإضافة؟'); }); window.DashboardSPA.onCleanup(function () { disposed = true; generation++; clearTimeout(timer); if (controller) controller.abort(); listeners.forEach(function (off) { off(); }); }); }
    on(root,'inventory:busy',function(event){recipeBusy=!!event.detail;lock();});
    on(root,'inventory:recipe-saved',function(){load(1);});
    render(boot.initial);
    try { var stored = JSON.parse(localStorage.getItem(pendingKey) || 'null'); if (stored) { frozen = stored; branch.value = stored.branch; notice('جارٍ التحقق من الإضافة السابقة…', true); lock(); recover(stored); } }
    catch (_) { notice('تعذر قراءة الإضافة السابقة المخزنة على الجهاز.'); }
}());
