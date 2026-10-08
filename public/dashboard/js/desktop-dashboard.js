/* Original catalog forms keep their layout and controller, with a stable local operation UUID. */
(() => {
    const eligible = form => {
        const url = new URL(form.action, location.href);
        const method = (form.querySelector('[name="_method"]')?.value || form.method).toUpperCase();
        return url.origin === location.origin && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
            && /^\/admin\/(?:categorys|products)(?:\/\d+)?\/?$/.test(url.pathname);
    };
    const prepare = form => {
        if (!(form instanceof HTMLFormElement) || !eligible(form) || form.querySelector('[name="_desktop_command"]')) return;
        const field = document.createElement('input');
        field.type = 'hidden'; field.name = '_desktop_command'; field.value = crypto.randomUUID(); form.append(field);
    };
    const scan = () => document.querySelectorAll('form').forEach(prepare);
    document.addEventListener('submit', event => prepare(event.target), true);
    document.addEventListener('DOMContentLoaded', scan);
    new MutationObserver(scan).observe(document.documentElement, {childList: true, subtree: true});
    scan();
})();
