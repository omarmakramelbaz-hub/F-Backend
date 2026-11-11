(() => {
'use strict';
const physicalIds = new Set([94, 301, 307, 363]);
const normalize = value => String(value || '').toLowerCase().normalize('NFD').replace(/[\u064B-\u065F\u0670\u0640]/g, '').replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/\s/g, '');
function normalizeEntries(value) {
    if (!Array.isArray(value)) throw new TypeError('Invalid public store directory');
    const seen = new Set();
    return value.slice(0, 1000).flatMap(item => {
        if (!item || !Number.isSafeInteger(Number(item.id)) || Number(item.id) < 1 || typeof item.name !== 'string' || !item.name.trim() || seen.has(Number(item.id)) || item.status === 'hide') return [];
        const id = Number(item.id); seen.add(id);
        return [{id, name: item.name.trim().slice(0, 160), type: physicalIds.has(id) ? 'branch' : 'store'}];
    });
}
function filterEntries(entries, type, query) {
    const term = normalize(query);
    return entries.filter(item => (type === 'all' || item.type === type) && (!term || normalize(item.name).includes(term)));
}
if (typeof module === 'object' && module.exports) module.exports = {normalize, normalizeEntries, filterEntries};
if (typeof document === 'undefined') return;
const root = document.getElementById('fasakhansta-storefront-concept');
if (!root) return;
const reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false;
const motionIcon = kind => {
    const shapes = {
        arrow: 'M7 17 17 7M7 7h10v10',
        down: 'M12 4v16m-6-6 6 6 6-6',
        up: 'M12 20V4m-6 6 6-6 6 6',
        pause: 'M8 5v14M16 5v14',
        play: 'm8 5 11 7-11 7Z',
        menu: 'M4 6h16M4 12h16M4 18h16',
        close: 'm6 6 12 12M6 18 18 6'
    };
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('class', 'motion-icon'); icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', kind === 'play' ? 'currentColor' : 'none');
    icon.setAttribute('stroke', 'currentColor'); icon.setAttribute('stroke-width', kind === 'pause' ? '2.5' : '1.8');
    icon.setAttribute('stroke-linecap', 'round'); icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true'); icon.setAttribute('focusable', 'false');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); path.setAttribute('d', shapes[kind] || shapes.arrow); icon.append(path);
    return icon;
};

const scrollTo = target => target?.scrollIntoView({behavior: reduced ? 'auto' : 'smooth', block: 'start'});
const menu = root.querySelector('[data-mobile-menu]');
const menuToggle = root.querySelector('[data-toggle-menu]');
function closeMenu() {
    if (menu) menu.hidden = true;
    if (menuToggle) { menuToggle.setAttribute('aria-expanded', 'false'); menuToggle.setAttribute('aria-label', 'افتح القائمة'); menuToggle.replaceChildren(motionIcon('menu')); }
}
menuToggle?.addEventListener('click', () => {
    if (!menu) return;
    const open = menu.hidden; menu.hidden = !open;
    menuToggle.setAttribute('aria-expanded', String(open)); menuToggle.setAttribute('aria-label', open ? 'أغلق القائمة' : 'افتح القائمة'); menuToggle.replaceChildren(motionIcon(open ? 'close' : 'menu'));
});
root.querySelectorAll('[data-scroll], [data-action="start-order"], [data-action="choose-store"]').forEach(button => button.addEventListener('click', event => {
    if (button.dataset.action === 'choose-store' && event.target.closest('select')) return;
    const target = button.dataset.scroll ? document.getElementById(button.dataset.scroll) : null;
    if (target && root.contains(target)) scrollTo(target);
    closeMenu();
}));
root.querySelector('[data-back-top]')?.addEventListener('click', () => scrollTo(root));

const video = root.querySelector('[data-hero-video]');
const videoToggle = root.querySelector('[data-video-toggle]');
if (video && videoToggle) {
    video.muted = true;
    const update = () => {
        const playing = !video.paused && !video.ended;
        root.querySelector('[data-video-label]').textContent = playing ? 'إيقاف الفيديو' : 'تشغيل الفيديو';
        root.querySelector('[data-video-icon]').replaceChildren(motionIcon(playing ? 'pause' : 'play'));
        videoToggle.setAttribute('aria-label', playing ? 'إيقاف الفيديو' : 'تشغيل الفيديو');
    };
    const play = () => { const result = video.play(); if (result?.catch) result.catch(update); };
    if (reduced) { video.autoplay = false; video.pause(); } else play();
    videoToggle.addEventListener('click', () => video.paused ? play() : video.pause());
    video.addEventListener('play', update); video.addEventListener('pause', update); video.addEventListener('error', update);
    update();
}

const categories = {
    feseekh: {title:'الفسيخ', en:'FESEEKH / 01', description:'للناس اللي بتحب الأكلة المصرية على أصولها. شوف اختيارات الفسيخ، وحدد الصنف والتجهيز من منيو متجرك.'},
    renga: {title:'الرنجة', en:'RENGA / 02', description:'للّمة اللي الرنجة بتكمّلها. اكتشف الاختيارات المتاحة، واختار النوع والتجهيز اللي يناسب سفرتك من المنيو.'},
    sardines: {title:'السردين', en:'SARDINES / 03', description:'اختيار له ناسه على السفرة. اعرف تفاصيل السردين، وراجع الأصناف والكميات من منيو متجرك قبل الطلب.'},
    bottarga: {title:'البطارخ', en:'BOTTARGA / 04', description:'التفصيلة اللي بتكمّل المزاج. اكتشف اختيارات البطارخ، وحدد العبوة والكمية المتاحة من المنيو.'}
};
root.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => {
    const key = button.dataset.category, item = categories[key]; if (!item) return;
    root.querySelectorAll('[data-category]').forEach(other => other.setAttribute('aria-pressed', String(other === button)));
    const panel = root.querySelector('[data-category-panel]'); if (!panel) return;
    panel.dataset.kind = key;
    root.querySelector('[data-category-title]').textContent = item.title;
    root.querySelector('[data-category-en]').textContent = item.en;
    root.querySelector('[data-category-description]').textContent = item.description;
    root.querySelector('[data-category-url]').href = '/products/' + key;
    if (!reduced) root.querySelector('[data-category-copy]')?.animate?.([{transform:'translateY(9px)',opacity:.5},{transform:'translateY(0)',opacity:1}], {duration:260,easing:'ease-out'});
}));
const locations = {
    mansoura: {name:'المنصورة',url:'https://maps.app.goo.gl/virmer6AWCErHTm49?g_st=ac'},
    mahalla: {name:'المحلة الكبرى',url:'https://maps.app.goo.gl/fzAmYirXeDa4jMA67?g_st=aw'},
    nabroh: {name:'نبروه',url:'https://maps.app.goo.gl/nch1a1jsMu54dvR3A?g_st=aw'},
    shubra: {name:'شبرا الخيمة',url:'https://maps.app.goo.gl/xUWYBGSSYP4yXvvKA?g_st=aw'}
};
root.querySelectorAll('[data-branch]').forEach(button => button.addEventListener('click', () => {
    const item = locations[button.dataset.branch]; if (!item) return;
    root.querySelectorAll('[data-branch]').forEach(other => other.setAttribute('aria-pressed', String(other === button)));
    root.querySelector('[data-branch-title]').textContent = 'فرع ' + item.name;
    const link = root.querySelector('[data-branch-map]'); link.href = item.url; link.setAttribute('aria-label', 'افتح لوكيشن فرع ' + item.name);
}));

const directory = root.querySelector('#f-stores');
if (directory) {
    let entries;
    try { entries = normalizeEntries(JSON.parse(directory.querySelector('.f-store-directory-data').textContent)); } catch (_) { entries = []; }
    const grid = directory.querySelector('.f-stores-grid');
    const filters = [...directory.querySelectorAll('[data-store-filter]')];
    const input = directory.querySelector('#f-store-search');
    const count = directory.querySelector('.f-store-count');
    const more = directory.querySelector('.f-stores-more');
    const empty = directory.querySelector('.f-stores-empty');
    const numbers = new Intl.NumberFormat('ar-EG');
    const state = {type:'store',query:'',expanded:false};
    function card(item) {
        const article = document.createElement('article'); article.className = 'f-store-card'; article.dataset.storeId = String(item.id);
        const top = document.createElement('div'); top.className = 'f-store-card-top';
        const symbol = document.createElement('span'); symbol.className = 'f-store-symbol';
        const image = document.createElement('img'); image.src = directory.dataset.storeLogo; image.alt = ''; image.width = 32; image.height = 32; image.loading = 'lazy'; symbol.append(image);
        const badge = document.createElement('span'); badge.className = 'f-store-type'; badge.textContent = item.type === 'branch' ? 'فرع' : 'ستور'; top.append(symbol,badge);
        const title = document.createElement('h3'); title.textContent = item.name;
        const link = document.createElement('a'); link.className = 'f-store-menu-link'; link.href = '#delivery'; link.dataset.directoryStore = String(item.id); link.setAttribute('aria-label','شوف منيو '+item.name+' بعد تحديد عنوان التوصيل'); link.textContent = 'شوف المنيو ';
        const arrow = document.createElement('span'); arrow.setAttribute('aria-hidden','true'); arrow.append(motionIcon('arrow')); link.append(arrow); article.append(top,title,link); return article;
    }
    function render() {
        const matches = filterEntries(entries,state.type,state.query), visible = state.expanded ? matches : matches.slice(0,8);
        const fragment = document.createDocumentFragment(); visible.forEach(item => fragment.append(card(item))); grid.replaceChildren(fragment);
        const label = state.type === 'store' ? 'ستور' : state.type === 'branch' ? (matches.length === 1 ? 'فرع' : 'فروع') : 'ستور وفرع';
        count.textContent = numbers.format(matches.length)+' '+label+' — ظاهر '+numbers.format(visible.length);
        empty.hidden = !!matches.length; grid.hidden = !matches.length; more.hidden = matches.length <= 8; more.setAttribute('aria-expanded',String(state.expanded));
        directory.querySelector('.f-stores-more-label').textContent = state.expanded ? 'عرض أقل' : state.type === 'store' ? 'عرض كل الإستورز' : 'عرض الكل';
        more.lastElementChild.replaceChildren(motionIcon(state.expanded ? 'up' : 'down'));
        filters.forEach(button => {
            const type = button.dataset.storeFilter;
            button.setAttribute('aria-pressed',String(type === state.type));
            button.querySelector('span').textContent = numbers.format(entries.filter(item => type === 'all' || item.type === type).length);
        });
        const stores = entries.filter(item=>item.type==='store').length, branches = entries.length-stores;
        directory.querySelector('.f-kicker').textContent = numbers.format(stores)+' ستور، و'+numbers.format(branches)+' فروع فسخانستا';
    }
    filters.forEach(button => button.addEventListener('click',()=>{state.type=button.dataset.storeFilter;state.expanded=false;render();}));
    input.addEventListener('input',()=>{state.query=input.value;state.expanded=false;render();});
    more.addEventListener('click',()=>{state.expanded=!state.expanded;render();});
    directory.addEventListener('click', event => {
        const link = event.target.closest('[data-directory-store]');
        if (!link || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (document.body.dataset.storefrontOrderBridge !== 'ready') return;
        event.preventDefault();
        document.dispatchEvent(new CustomEvent('storefront:request-store',{detail:{id:link.dataset.directoryStore}}));
    });
    render();
    // This public directory is separate from the address-scoped ordering catalog.
    // It never updates the eligible branch list, basket, prices, or checkout context.
    try {
        const endpoint = new URL(directory.dataset.directoryEndpoint,location.origin);
        if (endpoint.origin === location.origin && endpoint.pathname === '/api/resturants') {
            fetch(endpoint.href,{headers:{Accept:'application/json',Lang:'ar'},credentials:'omit',cache:'no-store'})
                .then(response=>{if(!response.ok)throw new Error('Directory unavailable');return response.json();})
                .then(payload=>{entries=normalizeEntries(payload.data===null?[]:payload.data);render();})
                .catch(()=>{ /* Retain the public deployment snapshot when offline. */ });
        }
    } catch (_) { /* The visible snapshot still works without directory refresh. */ }
}
if (!reduced && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(items=>items.forEach(item=>{if(item.isIntersecting){item.target.classList.add('f-inview');observer.unobserve(item.target);}}),{threshold:.12});
    root.querySelectorAll('.f-section').forEach(section=>observer.observe(section));
}
})();
