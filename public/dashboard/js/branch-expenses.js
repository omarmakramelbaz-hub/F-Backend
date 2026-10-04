(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.getElementById('branch-expenses'); if (!root) return;
    var boot = JSON.parse(document.getElementById('branch-expenses-bootstrap').textContent), labels = JSON.parse(document.getElementById('branch-expenses-labels').textContent);
    var form = root.querySelector('[data-expense-form]'), filters = root.querySelector('[data-expense-filters]'), branch = root.querySelector('[data-expense-branch]'), editor = root.querySelector('[data-expense-editor]'), dialog = root.querySelector('[data-expense-dialog]');
    var current = boot.initial, rows = new Map(), editing = null, dirty = false, busy = false, frozen = null, disposed = false, generation = 0, controller, listeners = [];
    var pendingKey = 'fasakhansta:expense-pending:' + boot.actor_id;
    function t(key) { return labels[key] || key; }
    function el(tag, value, className) { var node = document.createElement(tag); if (value !== undefined) node.textContent = String(value); if (className) node.className = className; return node; }
    function on(target, type, callback) { target.addEventListener(type, callback); listeners.push(function () { target.removeEventListener(type, callback); }); }
    function url(value, params, id) { var u = new URL(id === undefined ? value : value.replace('__EXPENSE__', String(id)), location.href); if (u.origin !== location.origin) throw new Error(t('error')); Object.keys(params || {}).forEach(function (key) { if (params[key] !== '') u.searchParams.set(key, params[key]); }); return u.href; }
    function uuid() { return crypto.randomUUID(); }
    function notice(value, retry) { var box = root.querySelector('[data-expense-message]'); box.hidden = !value; box.querySelector('span').textContent = value || ''; box.querySelector('button').hidden = !retry; }
    function locked() { return busy || !!frozen; }
    function lock() {
        root.querySelectorAll('button,input,textarea,select').forEach(function (control) { control.disabled = locked(); });
        root.querySelector('[data-expense-retry]').disabled = busy;
        if (frozen && !busy && frozen.fileName && !frozen.file) form.elements.attachment.disabled = false;
        if (!locked()) {
            branch.disabled = boot.branches.length === 1; form.elements.branch.disabled = boot.branches.length === 1 || !!editing;
            root.querySelector('[data-expense-previous]').disabled = current.pagination.page <= 1; root.querySelector('[data-expense-next]').disabled = current.pagination.page >= current.pagination.last_page;
            form.querySelectorAll('button').forEach(function (button) { button.disabled = !boot.permissions.can_create; });
        }
        if (branch.selectize) { if (branch.disabled) branch.selectize.disable(); else branch.selectize.enable(); }
        root.querySelector('[data-expense-save]').textContent = t(busy ? 'saving' : 'save');
    }
    function values() { var v = Object.fromEntries(new FormData(filters)); v.branch = branch.value; return v; }
    async function request(address, options) {
        var response = await fetch(address, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }, options || {}));
        var data; try { data = await response.json(); } catch (_) { data = null; }
        if (!response.ok || !data || data.success === false) { var error = new Error(response.status === 401 || response.status === 419 ? t('session') : data && data.message || t('error')); error.status = response.status; throw error; } return data;
    }
    function actionButton(label, callback, icon) { var button = el('button'); button.type = 'button'; button.title = t(label); button.setAttribute('aria-label', t(label)); if (icon) button.appendChild(el('i', undefined, 'fas ' + icon)); else button.textContent = t(label); button.addEventListener('click', callback); return button; }
    function render(data) {
        current = data; rows.clear(); var body = root.querySelector('[data-expense-rows]'); body.replaceChildren();
        data.items.forEach(function (item) {
            rows.set(item.id, item); var row = el('tr'); row.dataset.expenseId = item.id;
            [item.number,item.occurred_on,item.branch_name,t('cat_' + item.category),item.description,item.amount,t('method_' + item.payment_method),item.actor_name].forEach(function (value) { row.appendChild(el('td', value)); });
            var status = el('td'), tag = el('span', t('status_' + item.status), 'ex-tag'); tag.dataset.status = item.status; status.appendChild(tag); row.appendChild(status);
            var fileCell = el('td'); if (item.attachment_url) { var link = el('a', '↧'); link.href = url(item.attachment_url); link.dataset.spaOff = ''; link.title = item.attachment_name; fileCell.appendChild(link); } else fileCell.textContent = '—'; row.appendChild(fileCell);
            var actions = el('td'), group = el('div', undefined, 'ex-row-actions'); group.appendChild(actionButton('details', function () { details(item.id); }, 'fa-eye')); if (item.can_edit) group.appendChild(actionButton('edit', function () { edit(item); }, 'fa-pen')); group.appendChild(actionButton('print', function () { print(item.print_url); }, 'fa-print')); actions.appendChild(group); row.appendChild(actions); body.appendChild(row);
        });
        root.querySelector('[data-expense-empty]').hidden = !!data.items.length;
        root.querySelector('[data-expense-balance]').closest('.ex-balance').hidden = data.summary.cash_balance === undefined;
        root.querySelector('[data-expense-balance]').textContent = data.summary.cash_balance === undefined ? '' : data.summary.cash_balance + ' ' + t('currency');
        root.querySelectorAll('[data-expense-metric]').forEach(function (node) { var key = node.dataset.expenseMetric; node.textContent = key === 'top_category' ? (data.summary.top_category ? t('cat_' + data.summary.top_category) : '—') : data.summary[key]; });
        root.querySelector('[data-expense-top-amount]').textContent = data.summary.top_amount + ' ' + t('currency');
        root.querySelector('[data-expense-period]').textContent = t('period') + ': ' + data.summary.period + ' ' + t('currency') + ' · ' + t('pending') + ': ' + data.summary.pending;
        root.querySelector('[data-expense-page]').textContent = data.pagination.page + ' / ' + data.pagination.last_page + ' · ' + data.pagination.total;
        var users = root.querySelector('[data-expense-actors]'), selected = users.value; users.replaceChildren(el('option', t('all_users'))); users.firstChild.value = ''; data.actors.forEach(function (actor) { var option = el('option', actor.name); option.value = actor.id; users.appendChild(option); }); users.value = selected;
        root.querySelector('[data-expense-export]').href = url(boot.urls.export, values()); lock();
    }
    async function load(page) {
        var token = ++generation; if (controller) controller.abort(); controller = new AbortController();
        try { var data = await request(url(boot.urls.data, Object.assign(values(), { page: page || 1 })), { signal: controller.signal }); if (!disposed && token === generation) render(data); }
        catch (error) { if (!disposed && error.name !== 'AbortError') notice(error.message); }
    }
    function reset() {
        editing = null; form.reset(); form.elements.occurred_on.value = boot.today; form.elements.branch.value = branch.value === 'all' ? boot.branches[0].value : branch.value;
        root.querySelector('[data-expense-existing-file]').hidden = true; root.querySelector('[data-expense-form-title]').textContent = t('new'); dirty = false; lock();
    }
    function edit(item) {
        if (locked() || dirty && !confirm(t('unsaved'))) return; reset(); editing = item;
        Array.from(form.elements).forEach(function (field) { if (field.name && field.type !== 'file' && item[field.name] !== undefined) field.value = item[field.name] || ''; });
        var file = root.querySelector('[data-expense-existing-file]'); file.hidden = !item.attachment_url; if (item.attachment_url) { file.href = url(item.attachment_url); file.textContent = item.attachment_name; }
        editor.hidden = false; root.querySelector('[data-expense-form-title]').textContent = t('edit') + ' · ' + item.number; lock(); form.elements.description.focus();
    }
    async function print(address) { if (locked()) return; try { await window.DashboardPrint.print(url(address)); } catch (error) { notice(error.message || t('error')); } }
    function setPending(operation) { frozen = operation; var saved = Object.assign({}, operation); delete saved.file; sessionStorage.setItem(pendingKey, JSON.stringify(saved)); lock(); }
    function clearPending() { frozen = null; try { sessionStorage.removeItem(pendingKey); } catch (_) {} }
    async function recover(operation, signal) {
        var data = await request(url(boot.urls.recover, { branch: operation.values.branch, idempotency_key: operation.values.idempotency_key }), { signal: signal });
        if (!data.found) return false; await completed(data, true); return true;
    }
    async function completed(data, recovered) {
        clearPending(); busy = false; dirty = false; dialog.close(); if(branch.value!=='all'&&data.expense){branch.value=data.expense.branch;if(branch.selectize)branch.selectize.setValue(branch.value,true);} reset(); notice(t(recovered ? 'recovered' : 'saved')); await load(current.pagination.page); lock();
    }
    async function execute(operation, retry) {
        if (busy || disposed) return; busy = true;
        try { setPending(operation); } catch (_) { frozen = null; busy = false; lock(); notice(t('storage_error')); return; }
        notice(t('saving'));
        var requestController = new AbortController(), timeout = setTimeout(function () { requestController.abort(); }, 25000);
        try {
            if (retry && await recover(operation, requestController.signal)) return;
            var options = { method: 'POST', signal: requestController.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } };
            if (operation.type === 'save') {
                if (operation.fileName && !operation.file) { var chosen = form.elements.attachment.files[0]; if (!chosen || chosen.name !== operation.fileName || chosen.size !== operation.fileSize) throw new Error(t('missing_file')); operation.file = chosen; }
                var body = new FormData(); Object.keys(operation.values).forEach(function (key) { body.append(key, operation.values[key]); }); if (operation.file) body.append('attachment', operation.file); options.body = body;
            } else { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(operation.values); }
            var data = await request(url(operation.url), options); if (!disposed) await completed(data, false);
        } catch (error) {
            if (disposed) return; dialog.close();
            if (!retry && error.status >= 400 && error.status < 500) { clearPending(); notice(error.message); }
            else { notice((error.message === t('missing_file') ? error.message + ' ' : '') + t('uncertain'), true); }
        } finally { clearTimeout(timeout); busy = false; if (!disposed) lock(); }
    }
    function save(approve) {
        if (locked() || !form.reportValidity()) return;
        var v = {}; Array.from(form.elements).forEach(function (field) { if (field.name && field.type !== 'file') v[field.name] = field.value; });
        v.amount = v.amount.trim().replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).replace(/٫/g,'.');
        v.approve = approve ? '1' : '0'; if (approve && !confirm(t('confirm_approve'))) return;
        v.idempotency_key = uuid(); if (editing) { v.expense_id = editing.id; v.expected_revision = editing.revision; }
        var file = form.elements.attachment.files[0]; execute({ type: 'save', url: url(boot.urls.save), values: v, file: file || null, fileName: file && file.name, fileSize: file && file.size }, false);
    }
    function review(item, action) {
        if (locked()) return; var reason = ''; if (action !== 'approve') { reason = prompt(t('reason')); if (!reason || !reason.trim()) return; }
        if (!confirm(t('confirm_' + action))) return;
        execute({ type: 'review', url: url(boot.urls.review, null, item.id), values: { branch: item.branch, expected_revision: item.revision, action: action, reason: reason.trim(), idempotency_key: uuid() } }, false);
    }
    async function details(id) {
        if (locked()) return; var token = ++generation;
        try {
            var data = await request(url(boot.urls.show, null, id)); if (disposed || token !== generation) return;
            var item = data.expense, content = root.querySelector('[data-expense-detail]'); content.replaceChildren(); var grid = el('dl', undefined, 'ex-detail-grid');
            [['number',item.number],['branch',item.branch_name],['date',item.occurred_on],['category',t('cat_'+item.category)],['description',item.description],['amount',item.amount+' '+t('currency')],['payment_method',t('method_'+item.payment_method)],['payment_reference',item.payment_reference],['status',t('status_'+item.status)],['actor',item.actor_name],['supplier',item.supplier],['cost_center',item.cost_center],['notes',item.notes],['reviewer',item.reviewer_name],['review_reason',item.review_reason]].forEach(function (pair) { var cell = el('div'); cell.appendChild(el('dt',t(pair[0]))); cell.appendChild(el('dd',pair[1]||'—')); grid.appendChild(cell); }); content.appendChild(grid);
            if (item.attachment_url) { var attachment = el('a',item.attachment_name,'ex-button'); attachment.href = url(item.attachment_url); attachment.dataset.spaOff = ''; content.appendChild(attachment); }
            var actions = el('div',undefined,'ex-detail-actions'); actions.appendChild(actionButton('print',function(){print(item.print_url);},'fa-print'));
            if (boot.permissions.can_approve) (item.status === 'pending' ? ['approve','reject'] : item.status === 'approved' ? ['void'] : []).forEach(function(action){actions.appendChild(actionButton(action,function(){review(item,action);}));}); content.appendChild(actions);
            content.appendChild(el('h3',t('history'))); var history = el('ul',undefined,'ex-history'); (item.history || []).forEach(function (entry) { var status = entry.snapshot && entry.snapshot.status; history.appendChild(el('li',String(entry.created_at)+' · #'+entry.actor_id+' · '+t('status_'+status)+' · '+(entry.snapshot && entry.snapshot.description || ''))); }); content.appendChild(history); dialog.showModal();
        } catch (error) { if (!disposed) notice(error.message); }
    }
    on(form,'submit',function(event){event.preventDefault();save(false);}); on(form,'input',function(){dirty=true;});
    on(filters,'submit',function(event){event.preventDefault();if(!locked())load(1);});
    function changeBranch(){if(locked() || branch.value === current.filters.branch)return;if(dirty&&!confirm(t('unsaved'))){branch.value=current.filters.branch;if(branch.selectize)branch.selectize.setValue(branch.value,true);return;}reset();filters.elements.actor_id.value='';load(1);}
    on(branch,'change',changeBranch);if(window.jQuery)window.jQuery(branch).on('change.branchExpenses',changeBranch);
    on(root.querySelector('[data-expense-new]'),'click',function(){if(locked()||dirty&&!confirm(t('unsaved')))return;reset();editor.hidden=false;form.elements.category.focus();});
    on(root.querySelector('[data-expense-cancel]'),'click',function(){if(locked()||dirty&&!confirm(t('unsaved')))return;reset();editor.hidden=true;});
    on(root.querySelector('[data-expense-close]'),'click',function(){if(!busy)dialog.close();}); on(dialog,'cancel',function(event){if(locked())event.preventDefault();});
    var approveButton=root.querySelector('[data-expense-save-approve]');if(approveButton)on(approveButton,'click',function(){save(true);});
    on(root.querySelector('[data-expense-previous]'),'click',function(){if(!locked())load(current.pagination.page-1);});on(root.querySelector('[data-expense-next]'),'click',function(){if(!locked())load(current.pagination.page+1);});
    on(root.querySelector('[data-expense-report]'),'click',function(){print(url(boot.urls.report,values()));});on(root.querySelector('[data-expense-retry]'),'click',function(){if(frozen)execute(frozen,true);});
    function mayLeave(){if(locked()){notice(t('uncertain'),true);return false;}return !dirty||confirm(t('unsaved'));}
    on(window,'beforeunload',function(event){if(locked()||dirty){event.preventDefault();event.returnValue='';}});
    if(window.DashboardSPA){window.DashboardSPA.onBeforeLeave(mayLeave);window.DashboardSPA.onCleanup(function(){disposed=true;if(controller)controller.abort();listeners.forEach(function(remove){remove();});if(window.jQuery)window.jQuery(branch).off('.branchExpenses');dialog.close();});}
    reset();Object.keys(boot.initial.filters).forEach(function(key){if(filters.elements[key])filters.elements[key].value=boot.initial.filters[key];});render(boot.initial);
    try { var stored=JSON.parse(sessionStorage.getItem(pendingKey)||'null'); if(stored){frozen=stored;editor.hidden=false;Object.keys(stored.values).forEach(function(key){if(form.elements[key]&&form.elements[key].type!=='file')form.elements[key].value=stored.values[key];});notice(t('uncertain'),true);lock();recover(stored).catch(function(){notice(t('uncertain'),true);});} }catch(_){notice(t('error'));}
}());
