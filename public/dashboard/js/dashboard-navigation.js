(function () {
    'use strict';
    const sidebar = document.getElementById('dashboard-sidebar');
    const navigation = document.querySelector('[data-dashboard-navigation]');
    if (!sidebar || !navigation) return;

    const arabic = document.documentElement.dir === 'rtl';
    const labels = arabic ? {
        catalog: 'المطاعم والقائمة', orders: 'الطلبات والتقارير', people: 'المستخدمون والشركاء',
        communication: 'التواصل وإدارة التطبيق', settings: 'الإعدادات والمالية', back: 'رجوع', close: 'إغلاق القائمة', toggle: 'إظهار أو إخفاء القائمة'
    } : {
        catalog: 'Restaurants & menu', orders: 'Orders & reports', people: 'People & partners',
        communication: 'Communication & app', settings: 'Settings & finance', back: 'Back', close: 'Close navigation', toggle: 'Show or hide navigation'
    };
    const groupIcons = { catalog: 'fa-utensils', orders: 'fa-chart-line', people: 'fa-users', communication: 'fa-bullhorn', settings: 'fa-sliders-h' };
    const rootNodes = Array.from(navigation.children).filter(node => node.classList.contains('nav-item'));
    const paths = node => Array.from(node.querySelectorAll('a[href]')).filter(link => !link.getAttribute('href').startsWith('#')).map(link => {
        try { return new URL(link.href, location.href).pathname; } catch (error) { return ''; }
    });
    const priority = node => paths(node).some(path => /\/admin\/(dashboard|applies-orders|takeaway|dining|phone-orders|branch-orders|branch-expenses|customers|employees|delivery-companies|print-settings|go-stores)\/?$/.test(path));
    function section(node) {
        const routes = paths(node).join(' ');
        if (/\/admin\/(users|roles|pending_vendors)/.test(routes)) return 'people';
        if (/\/admin\/(orders|resturant-reports|reports)/.test(routes)) return 'orders';
        if (/\/admin\/(resturants|categorys|products)/.test(routes)) return 'catalog';
        if (/\/admin\/(fcm_notifications|bulk-notifications|coupon_wheels|chat|banners|slidears|advertisings|services|question_answers|contacts|features)/.test(routes)) return 'communication';
        return 'settings';
    }
    function groupNavigation() {
        if (navigation.dataset.grouped === 'true') return;
        const groups = new Map();
        rootNodes.forEach(node => {
            if (priority(node)) return;
            const key = section(node);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(node);
        });
        ['catalog', 'orders', 'people', 'communication', 'settings'].forEach(key => {
            const nodes = groups.get(key);
            if (!nodes || !nodes.length) return;
            const item = document.createElement('li');
            item.className = 'nav-item dashboard-navigation-group';
            const link = document.createElement('a');
            link.href = '#';
            link.className = 'nav-link';
            const icon = document.createElement('i');
            icon.className = 'nav-icon fas ' + groupIcons[key];
            icon.setAttribute('aria-hidden', 'true');
            const text = document.createElement('p');
            text.append(document.createTextNode(labels[key]));
            if (nodes.some(node => paths(node).some(path => /\/admin\/chat\/?$/.test(path)))) {
                const badge = document.createElement('span');
                badge.className = 'badge dashboard-support-badge';
                badge.setAttribute('data-support-unread', '');
                badge.setAttribute('aria-live', 'polite');
                badge.hidden = true;
                text.append(badge);
            }
            const arrow = document.createElement('i');
            arrow.className = 'fas fa-angle-down left';
            arrow.setAttribute('aria-hidden', 'true');
            text.append(arrow);
            link.append(icon, text);
            const list = document.createElement('ul');
            list.className = 'nav nav-treeview';
            nodes.forEach(node => list.append(node));
            if (list.querySelector('.nav-link.active')) link.classList.add('active');
            item.append(link, list);
            navigation.append(item);
        });
        navigation.dataset.grouped = 'true';
    }

    const popup = document.createElement('section');
    popup.className = 'dashboard-navigation-popup';
    popup.hidden = true;
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-modal', 'false');
    const header = document.createElement('div');
    header.className = 'dashboard-popup-header';
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'dashboard-popup-back';
    back.textContent = labels.back;
    const title = document.createElement('h2');
    title.id = 'dashboard-navigation-title';
    popup.setAttribute('aria-labelledby', title.id);
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'dashboard-popup-close';
    close.setAttribute('aria-label', labels.close);
    close.textContent = '×';
    header.append(back, title, close);
    const content = document.createElement('div');
    content.className = 'dashboard-popup-content';
    popup.append(header, content);
    document.body.append(popup);

    let stack = [];
    const childList = link => link.parentElement && Array.from(link.parentElement.children).find(node => node.matches('ul.nav-treeview'));
    const restore = context => context.parent.insertBefore(context.list, context.next && context.next.parentElement === context.parent ? context.next : null);
    function positionPopup() {
        if (popup.hidden || !stack.length) return;
        const box = sidebar.getBoundingClientRect();
        const anchor = stack[0].trigger.getBoundingClientRect();
        const width = popup.offsetWidth;
        const height = popup.offsetHeight;
        const desiredX = arabic ? box.left - width - 12 : box.right + 12;
        const mobileX = arabic ? innerWidth - width - 12 : 12;
        popup.style.left = Math.max(12, Math.min(innerWidth - width - 12, innerWidth < 992 ? mobileX : desiredX)) + 'px';
        popup.style.top = Math.max(12, Math.min(innerHeight - height - 12, innerWidth < 992 ? 16 : anchor.top)) + 'px';
    }
    function renderContext() {
        const context = stack[stack.length - 1];
        content.append(context.list);
        title.textContent = context.label;
        back.hidden = stack.length < 2;
        popup.hidden = false;
        context.trigger.setAttribute('aria-expanded', 'true');
        positionPopup();
        const first = context.list.querySelector(':scope > .nav-item > .nav-link');
        if (first) first.focus();
    }
    function openList(link) {
        const list = childList(link);
        if (!list) return;
        if (stack.length) restore(stack[stack.length - 1]);
        stack.push({ list, parent: list.parentElement, next: list.nextSibling, trigger: link, label: link.querySelector('p')?.textContent.trim() || link.textContent.trim() });
        renderContext();
    }
    function closePopup(focus) {
        const trigger = stack[0]?.trigger;
        while (stack.length) {
            const context = stack.pop();
            restore(context);
            context.trigger.setAttribute('aria-expanded', 'false');
        }
        popup.hidden = true;
        if (focus && trigger) trigger.focus();
    }
    function goBack() {
        if (stack.length < 2) return closePopup(true);
        const current = stack.pop();
        restore(current);
        current.trigger.setAttribute('aria-expanded', 'false');
        renderContext();
        current.trigger.focus();
    }
    function handleLink(event) {
        const link = event.target.closest('a.nav-link');
        if (!link || !childList(link)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (navigation.contains(link) && stack.length) closePopup(false);
        openList(link);
    }
    navigation.addEventListener('click', handleLink);
    popup.addEventListener('click', handleLink);
    back.addEventListener('click', goBack);
    close.addEventListener('click', () => closePopup(true));
    document.addEventListener('pointerdown', event => {
        if (!popup.hidden && !popup.contains(event.target) && !stack[0]?.trigger.contains(event.target)) closePopup(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !popup.hidden) { event.preventDefault(); closePopup(true); }
        if (event.key === ' ' && event.target.matches('a.nav-link') && childList(event.target)) handleLink(event);
    });

    navigation.removeAttribute('data-widget');
    document.body.classList.add('dashboard-navigation-ready');
    let navigationSequence = 0;
    function prepareLinks() {
        navigation.querySelectorAll('a.nav-link').forEach(link => {
            const list = childList(link);
            if (!list) return;
            if (!list.id) {
                let identifier;
                do { identifier = 'dashboard-navigation-list-' + (++navigationSequence); } while (document.getElementById(identifier));
                list.id = identifier;
            }
            link.setAttribute('role', 'button');
            link.setAttribute('aria-controls', list.id);
            link.setAttribute('aria-expanded', stack.some(context => context.trigger === link) ? 'true' : 'false');
            link.parentElement.classList.remove('menu-open');
        });
    }
    function removeScrollbar() {
        const area = sidebar.querySelector('.sidebar');
        if (window.jQuery?.fn.overlayScrollbars) {
            const instance = window.jQuery(area).overlayScrollbars();
            if (instance && typeof instance.destroy === 'function') instance.destroy();
        }
    }
    function fitNavigation() {
        removeScrollbar();
        if (navigation.children.length > 14) groupNavigation();
        const navBox = navigation.getBoundingClientRect();
        if (navBox.height && navBox.bottom > innerHeight - 12 && navigation.dataset.grouped !== 'true') groupNavigation();
        prepareLinks();
        document.querySelectorAll('[data-widget="pushmenu"]').forEach(toggle => {
            toggle.setAttribute('aria-controls', sidebar.id);
            toggle.setAttribute('aria-label', labels.toggle);
            toggle.setAttribute('aria-expanded', !document.body.classList.contains('sidebar-collapse') && (innerWidth >= 992 || document.body.classList.contains('sidebar-open')) ? 'true' : 'false');
        });
        positionPopup();
    }
    fitNavigation();
    window.addEventListener('load', fitNavigation);
    document.addEventListener('dashboard:before-unload', () => closePopup(false));
    document.addEventListener('dashboard:page-loaded', () => {
        navigation.querySelectorAll('.dashboard-navigation-group').forEach(group => {
            const link = Array.from(group.children).find(node => node.matches('a.nav-link'));
            const list = Array.from(group.children).find(node => node.matches('ul.nav-treeview'));
            if (link && list) link.classList.toggle('active', !!list.querySelector('.nav-link.active'));
        });
        fitNavigation();
    });
    window.addEventListener('resize', () => { closePopup(false); fitNavigation(); });
    new MutationObserver(() => {
        if (document.body.classList.contains('sidebar-collapse') || (innerWidth < 992 && !document.body.classList.contains('sidebar-open'))) closePopup(false);
        fitNavigation();
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
}());
