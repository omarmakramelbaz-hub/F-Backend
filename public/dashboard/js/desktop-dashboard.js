/* Original catalog forms keep their layout and controller, with a stable local operation UUID. */
(async () => {
    if (document.body?.dataset.dashboardLocal !== '1') {
        if (!window.FasakhanstaDesktop || document.body?.dataset.dashboardRemoteAttempts !== '1') return;
        try { if (!(await window.FasakhanstaDesktop.status()).prepared) return; } catch { return; }
    }
    const eligible = form => {
        const url = new URL(form.action, location.href);
        const method = (form.querySelector('[name="_method"]')?.value || form.method).toUpperCase();
        return url.origin === location.origin && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
            && (/^\/admin\/(?:areas|categorys|products|question_answers|features|contracts)(?:\/\d+)?\/?$/.test(url.pathname)
                ||(method==='POST'&&url.pathname==='/admin/roles')
                ||(['POST','PUT','PATCH','DELETE'].includes(method)&&/^\/admin\/roles\/[1-9][0-9]{0,18}$/.test(url.pathname))
                || (method==='DELETE'&&/^\/admin\/contacts\/[1-9][0-9]{0,18}$/.test(url.pathname)));
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
        if (!(form instanceof HTMLFormElement) || !eligible(form) || form.querySelector('[name="_desktop_command"]')) return;
        const field = document.createElement('input');
        field.type = 'hidden'; field.name = '_desktop_command'; field.value = crypto.randomUUID(); form.append(field);
    };
    const scan = () => document.querySelectorAll('form').forEach(prepare);
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
                    request.done(value=>{if(value?.status==='success')sessionStorage.removeItem(key);});
                } catch { request.abort(); }
                return;
            }
            if (url.origin !== location.origin || String(options.type).toUpperCase() !== 'DELETE'
                || !/^\/admin\/(?:areas|categorys|products|question_answers|features|contacts)DeleteAll$/.test(url.pathname)) return;
            const values = new URLSearchParams(options.data || ''), ids = (values.get('ids') || '').split(',')
                .sort((a, b) => a.length - b.length || a.localeCompare(b)).join(',');
            const key = 'fasakhansta.catalog.' + document.body.dataset.dashboardActor + '.' + url.pathname + '.' + ids;
            let command = sessionStorage.getItem(key);
            if (!command) { command = crypto.randomUUID(); sessionStorage.setItem(key, command); }
            request.setRequestHeader('X-Fasakhansta-Command', command);
            request.done(value => { if (value && value.success) sessionStorage.removeItem(key); });
        });
    };
    document.addEventListener('submit', event => prepare(event.target), true);
    document.addEventListener('DOMContentLoaded', () => { scan(); ajax(); });
    new MutationObserver(scan).observe(document.documentElement, {childList: true, subtree: true});
    scan(); ajax();
})();
