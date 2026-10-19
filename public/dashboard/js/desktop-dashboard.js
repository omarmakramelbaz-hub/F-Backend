/* Original catalog forms keep their layout and controller, with a stable local operation UUID. */
(async () => {
    if (document.body?.dataset.dashboardLocal !== '1') {
        if (!window.FasakhanstaDesktop || document.body?.dataset.dashboardRemoteAttempts !== '1') return;
        try { if (!(await window.FasakhanstaDesktop.status()).prepared) return; } catch { return; }
    }
    const ownedControl = (form, name) => {
        if (form.elements) return Array.from(form.elements).find(control => control.form === form
            && control.name === name && !control.disabled && !control.matches?.(':disabled'));
        const control = form.querySelector('[name="' + name + '"]');
        return control && (!control.form || control.form === form) && !control.disabled ? control : null;
    };
    const effectiveMethod = form => (ownedControl(form, '_method')?.value || form.method).toUpperCase();
    const productRoute = (path, method) => (method === 'POST' && path === '/admin/resturant_products')
        || (method === 'POST' && path === '/admin/resturant/copy/items')
        || (method === 'PUT' && path === '/admin/resturant_products/update')
        || (method === 'DELETE' && /^\/admin\/resturant_products\/[1-9][0-9]{0,18}$/.test(path));
    const eligible = form => {
        const url = new URL(form.action, location.href);
        const method = effectiveMethod(form);
        return url.origin === location.origin && !url.username && !url.password && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
            && (/^\/admin\/(?:areas|categorys|products|question_answers|features|contracts)(?:\/\d+)?\/?$/.test(url.pathname)
                || (form.method.toUpperCase() === 'POST' && productRoute(url.pathname, method))
                ||(method==='POST'&&url.pathname==='/admin/roles')
                ||(method==='POST'&&['/admin/wallets/transfer','/admin/wallets/withdraw/store'].includes(url.pathname))
                ||(['POST','PUT','PATCH','DELETE'].includes(method)&&/^\/admin\/roles\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='DELETE'&&/^\/admin\/resturant_reviews\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='DELETE'&&/^\/admin\/userWishlistsDelete\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='DELETE'&&/^\/admin\/contacts\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='POST'&&/^\/admin\/go-stores\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='POST'&&/^\/admin\/resturantchange(?:Status|UnderContract)\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='POST'&&/^\/admin\/update_sorting_is_featured\/[1-9][0-9]{0,18}$/.test(url.pathname)));
    };
    const prepare = form => {
        if (form instanceof HTMLFormElement && form.hasAttribute('data-desktop-notification-read')) {
            const url = new URL(form.action, location.href), method = (form.querySelector('[name="_method"]')?.value || form.method).toUpperCase();
            if (url.origin !== location.origin || !((method==='PUT'&&/^\/admin\/read\/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(url.pathname))
                ||(method==='POST'&&url.pathname==='/admin/read/all/notification'))) return;
            if (form.querySelector('[name="_desktop_command"]')) return;
            const generation = form.dataset.notificationGeneration;
            if (document.body.dataset.dashboardLocal === '1'
                ? !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(generation || '')
                : generation !== 'server') return;
            let ids;
            try { ids = JSON.parse(form.dataset.notificationIds); } catch { return; }
            if (!Array.isArray(ids) || ids.length>50000 || ids.some(id=>typeof id!=='string'||!/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(id))) return;
            ids.sort();
            const key='fasakhansta.notification-history.'+document.body.dataset.dashboardActor+'.'+generation+'.'+url.pathname+'.'+ids.join(',');
            let command;
            try { command=sessionStorage.getItem(key);if(!command){command=crypto.randomUUID();sessionStorage.setItem(key,command);} }
            catch { return; }
            for (const [name,value] of [['_desktop_command',command],['desktop_notification_ids',JSON.stringify(ids)]]) {
                const field=document.createElement('input');field.type='hidden';field.name=name;field.value=value;form.append(field);
            }
            return;
        }
        if (!(form instanceof HTMLFormElement) || !eligible(form) || ownedControl(form, '_desktop_command')) return;
        const field = document.createElement('input');
        field.type = 'hidden'; field.name = '_desktop_command'; field.value = crypto.randomUUID(); form.append(field);
    };
    const prepareProductLink = link => {
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || url.username || url.password
            || !(url.pathname === '/admin/resturantControl' || /^\/admin\/resturant_products\/[1-9][0-9]{0,18}\/update\/(?:status|highest_rated)$/.test(url.pathname))) return;
        // Keep the operation on the original link until an accepted navigation creates a new page.
        // A lost reply then retries its original UUID, just like the original checkbox forms.
        const command = link.dataset.desktopProductCommand || crypto.randomUUID();
        link.dataset.desktopProductCommand = command;
        url.searchParams.set('_desktop_command', command);
        link.href = url.href;
    };
    const scan = () => {
        document.querySelectorAll('form').forEach(prepare);
        document.querySelectorAll('a[href]').forEach(prepareProductLink);
    };
    const ajax = () => {
        if (!window.jQuery || window.jQuery.fasakhanstaCatalogJournal) return;
        window.jQuery.fasakhanstaCatalogJournal = true;
        window.jQuery.ajaxPrefilter((options, _original, request) => {
            const url = new URL(options.url, location.href);
            if (url.origin===location.origin && String(options.type).toUpperCase()==='POST' && url.pathname==='/admin/post-sortable') {
                const generation=document.querySelector('[data-desktop-category-generation]')?.dataset.desktopCategoryGeneration;
                if (document.body.dataset.dashboardLocal==='1'
                    ? !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(generation||'') : generation!=='server') { request.abort();return; }
                const entries=new URLSearchParams(options.data||''), rows=new Map();
                for (const [name,value] of entries) {
                    const match=/^order\[(\d+)\]\[(id|position)\]$/.exec(name);
                    if(match){const row=rows.get(match[1])||{};row[match[2]]=value;rows.set(match[1],row);}
                }
                const order=[...rows.values()];
                if (!order.length || order.length>200 || order.some(row=>!/^[1-9][0-9]{0,18}$/.test(row.id||'')||!/^[1-9][0-9]{0,6}$/.test(row.position||''))) { request.abort();return; }
                order.sort((a,b)=>a.id.length-b.id.length||a.id.localeCompare(b.id));
                const key='fasakhansta.category-order.'+document.body.dataset.dashboardActor+'.'+generation+'.'+JSON.stringify(order);
                try {
                    let command=sessionStorage.getItem(key);
                    if(!command){command=crypto.randomUUID();sessionStorage.setItem(key,command);}
                    request.setRequestHeader('X-Fasakhansta-Command',command);
                    request.done(value=>{if(value?.status==='success'&&sessionStorage.getItem(key)===command)sessionStorage.removeItem(key);});
                } catch { request.abort(); }
                return;
            }
            if (url.origin !== location.origin || String(options.type).toUpperCase() !== 'DELETE'
                || !/^\/admin\/(?:areas|categorys|products|question_answers|features|contacts|roles)DeleteAll$/.test(url.pathname)) return;
            const values = new URLSearchParams(options.data || ''), ids = (values.get('ids') || '').split(',')
                .sort((a, b) => a.length - b.length || a.localeCompare(b)).join(',');
            const key = 'fasakhansta.catalog.' + document.body.dataset.dashboardActor + '.' + url.pathname + '.' + ids;
            let command = sessionStorage.getItem(key);
            if (!command) { command = crypto.randomUUID(); sessionStorage.setItem(key, command); }
            request.setRequestHeader('X-Fasakhansta-Command', command);
            request.done(value => { if (value && value.success && sessionStorage.getItem(key) === command) sessionStorage.removeItem(key); });
        });
    };
    document.addEventListener('submit', event => {
        const form = event.target;
        prepare(form);
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !eligible(form)
            || !(/^\/admin\/update_sorting_is_featured\/[1-9][0-9]{0,18}$/.test(new URL(form.action, location.href).pathname)
                || productRoute(new URL(form.action, location.href).pathname, effectiveMethod(form)))
            || form.method.toUpperCase() !== 'POST' || form.hasAttribute('data-spa-off')
            || (form.target && form.target !== '_self') || !window.DashboardSPA
            || typeof window.DashboardSPA.ready !== 'function' || !window.DashboardSPA.ready()
            || !document.querySelector('[data-dashboard-page]')) return;
        const submitter = event.submitter;
        if (!submitter && productRoute(new URL(form.action, location.href).pathname, effectiveMethod(form))) return;
        if (submitter && (submitter.form !== form || submitter.name
            || ['formaction','formmethod','formtarget','formenctype'].some(name => submitter.hasAttribute(name)))) return;
        // Capture these original controls before legacy bubbling handlers. The original
        // SPA builds their FormData and retains the form, UUID and selected file on loss.
        event.preventDefault();
        // The SPA's later capture listener snapshots after this call disables buttons.
        // Discard that previous in-flight snapshot so every retry restores current controls.
        delete form.dashboardSubmitButtons;
        HTMLFormElement.prototype.submit.call(form);
    }, true);
    document.addEventListener('DOMContentLoaded', () => { scan(); ajax(); });
    new MutationObserver(scan).observe(document.documentElement, {childList: true, subtree: true});
    scan(); ajax();
})();
