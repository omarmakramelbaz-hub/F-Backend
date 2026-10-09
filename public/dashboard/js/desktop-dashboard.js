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
                || (method==='DELETE'&&/^\/admin\/contacts\/[1-9][0-9]{0,18}$/.test(url.pathname)));
    };
    const prepare = form => {
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
