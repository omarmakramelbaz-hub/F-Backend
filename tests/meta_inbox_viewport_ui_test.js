'use strict';

// Geometry fixture for the exact installed unified inbox. No network calls,
// application bootstrap, customer data, or send/AI modules are executed.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');
const root = path.resolve(__dirname, '..');
const pins = {
    hub: '3d1a2a7a2d5af960a9ecdf3fee4e8895ab468aa9',
    whatsappPane: '779f1f80586390f31fd295fecc411e3e6448fa3b',
    socialPane: '84dd676c46ce58ae690599106665600cfbac19ca',
    whatsappReplies: '6386debd6e20836cc1023cefc7868e214ce7f0f0',
    socialReplies: '9251a7defe180ad1477d71a67f1827026b860ef8',
    inboxCss: '639ac2223b4abcd679329000551d9c735e43b5af',
    repliesCss: 'aaf5d4b8a5c46b6aaaf43824c52ddefca00ba854',
    socialCss: '79e8e815e024199316d2f5bf86be56bf21ededfd',
    hubCss: '631727d91df60dc2a65ded92b1b6cdda87de0afe',
};
function blob(name) {
    return cp.execFileSync('git', ['cat-file', 'blob', pins[name]], {cwd: root, encoding: 'utf8'});
}
function plainBlade(source) {
    return source.replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/@foreach\([\s\S]*?@endforeach/g, '')
        .replace(/<\{\{[\s\S]*?\}\}/g, '<div')
        .replace(/<\/\{\{[\s\S]*?\}\}/g, '</div')
        .replace(/\{\{\s*\$metaAiArabic \? '([^']*)' : '[^']*'\s*\}\}/g, '$1')
        .replace(/\{\{[\s\S]*?\}\}/g, 'Chat')
        .replace(/@if\([^\n]*\)/g, '').replace(/@endif/g, '');
}
function replies(name) {
    return plainBlade(blob(name).split('@endphp')[1].split('<script')[0])
        .replace('data-wa-replies hidden', 'data-wa-replies')
        .replace(/(<textarea[^>]*?) disabled/g, '$1')
        .replace(/(<p class="wa-replies-status"[^>]*>)(<\/p>)/, '$1يمكنك إرسال رد من حساب النشاط.$2');
}
function pane(name, replyName, selected) {
    let source = blob(name);
    source = source.replace(/@if\(empty\(\$metaMessageHub\)\)[\s\S]*?@endif/g, '');
    source = source.replace(/@include\('admin\.(?:meta|whatsapp)\.replies'\)/, replies(replyName));
    source = source.replace(/@include\('admin\.(?:meta|whatsapp)\.orders'\)/, '<div style="min-height:200px">تفاصيل الطلب للمراجعة</div>');
    source = plainBlade(source);
    source = source.replace('class="wa-inbox"', 'class="wa-inbox has-selection"')
        .replace('class="wa-inbox meta-chat"', 'class="wa-inbox meta-chat has-selection"');
    return '<section class="meta-hub-pane" data-hub-pane="' + (name === 'whatsappPane' ? 'whatsapp' : 'meta')
        + '"' + (selected ? '' : ' hidden') + '>' + source + '</section>';
}
function minifyCss(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ')
        .replace(/\s*([{};,:])\s*/g, '$1');
}
function buildFixture(options = {}) {
    const selected = options.channel || 'whatsapp';
    assert(['whatsapp', 'messenger', 'instagram'].includes(selected));
    let css = ['inboxCss', 'repliesCss', 'socialCss', 'hubCss'].map(blob).join('\n');
    css += fs.readFileSync(path.join(root, 'public/css/dashboard-meta-ai-controls.css'), 'utf8');
    const patch = options.patched !== false ? fs.readFileSync(path.join(root, 'public/css/dashboard-meta-inbox-viewport.css'), 'utf8') : '';
    let ai = fs.readFileSync(path.join(root, 'resources/views/admin/meta/ai_controls.blade.php'), 'utf8');
    ai = ai.slice(ai.indexOf('<section'), ai.indexOf('@push'));
    ai = plainBlade(ai).replace('id="meta-ai-controls" class="meta-ai-controls"', 'id="meta-ai-controls" class="meta-ai-controls"')
        .replace('settings hidden', 'settings').replace('data-meta-ai-settings hidden', 'data-meta-ai-settings')
        .replace('hidden>', '>');
    ai = ai.replace(/<p[^>]*(?:data-meta-ai-handoff-state|data-meta-ai-message)[\s\S]*?<\/p>/g, '');
    ai = ai.replace('data-meta-ai-status role="status" aria-live="polite">', 'data-meta-ai-status role="status" aria-live="polite">AI مفعّل · ');
    if (options.settingsOpen) ai = ai.replace('<details class="meta-ai-settings"', '<details open class="meta-ai-settings"');
    const base = `html,body{margin:0;font:13px Arial,sans-serif;color:#203d42}*{box-sizing:border-box}.wrapper{min-height:100vh}.main-header{height:48px;padding:12px;background:#fff;border-bottom:1px solid #ddd}.main-footer{height:32px;padding:8px;background:#f7f9fa}.main-sidebar{position:fixed;inset-block:0;inset-inline-end:0;width:260px;background:#102436;color:#fff;padding:20px}.main-header,.main-footer,.content-wrapper{margin-inline-end:260px}.content-wrapper{min-height:800px}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}@media(max-width:720px){.main-sidebar{display:none}.main-header,.main-footer,.content-wrapper{margin-inline-end:0}.main-header{height:40px}.main-footer{height:26px;font-size:10px}}`;
    let outer = blob('hub').split('@section(\'content\')')[1].split('@endsection')[0];
    outer = outer.replace(/@include\('admin\.meta\.channels'[^\n]*/, '<nav class="meta-inbox-channels"><button class="wa-inbox-button">الكل</button><button class="wa-inbox-button">واتساب</button><button class="wa-inbox-button">ماسنجر</button><button class="wa-inbox-button">إنستجرام</button></nav>' + ai);
    outer = outer.replace(/<section class="meta-hub-pane" data-hub-pane="meta" hidden>[\s\S]*?<\/section>/,
        pane('socialPane', 'socialReplies', selected !== 'whatsapp'));
    outer = outer.replace(/<section class="meta-hub-pane" data-hub-pane="whatsapp" hidden>[\s\S]*?<\/section>/,
        pane('whatsappPane', 'whatsappReplies', selected === 'whatsapp'));
    outer = plainBlade(outer).replace('class="wa-inbox meta-message-hub"', 'class="wa-inbox meta-message-hub has-selection"');
    const script = `const channel=document.documentElement.dataset.channel;const host=document.getElementById(channel==='whatsapp'?'whatsapp-inbox':'meta-social-inbox');for(const p of document.querySelectorAll('[data-hub-pane]'))p.hidden=p.dataset.hubPane!==(channel==='whatsapp'?'whatsapp':'meta');document.querySelector('[data-hub-empty]').hidden=true;document.querySelector('[data-hub-status]').hidden=true;for(const p of document.querySelectorAll('[data-wa-chat-status]'))p.hidden=true;const list=document.querySelector('[data-hub-threads]');for(let i=0;i<60;i++){let li=document.createElement('li');li.innerHTML='<button class="wa-inbox-thread"><span class="wa-inbox-avatar">ع</span><span class="wa-inbox-thread-body"><strong>عميل فسخانستا '+i+'</strong><span class="wa-inbox-preview">رسالة جديدة عن الطلب</span></span></button>';list.append(li)}const messages=host.querySelector('[data-wa-messages]');for(let i=0;i<200;i++){let li=document.createElement('li');li.className='wa-inbox-message '+(i%2?'is-inbound':'is-outbound');li.innerHTML='<div class="wa-inbox-bubble"><span class="wa-inbox-author">'+(i%2?'العميل':'فسخانستا')+'</span><p class="wa-inbox-message-text">محتاج أطلب رنجة دليفري للمنصورة. ممكن تفاصيل الأصناف المتاحة؟</p><time>02:10</time></div>';messages.append(li)}host.querySelector('[data-wa-reply-text]').placeholder='اكتب ردًا من حساب فسخانستا…';host.querySelector('[data-wa-reply-text]').disabled=false;host.querySelector('[data-wa-scroll]').scrollTop=host.querySelector('[data-wa-scroll]').scrollHeight;if(document.documentElement.dataset.drawer==='true')host.querySelector('[data-wa-order-drawer]').open=true;if(document.documentElement.dataset.settings==='true')document.querySelector('.meta-ai-settings').open=true;window.observeFixtureGeometry=${observeGeometry.toString()};`;
    return '<!doctype html><html lang="ar" dir="rtl" data-channel="' + selected + '" data-settings="' + Boolean(options.settingsOpen) + '" data-drawer="' + Boolean(options.drawerOpen) + '"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
        + base + minifyCss(css) + '</style><style id="viewport-patch">' + minifyCss(patch) + '</style><body><div class="wrapper"><nav class="main-header">فسخانستا · رسائل ميتا</nav><aside class="main-sidebar">فسخانستا<br><br>الصفحة الرئيسية<br><br>رسائل ميتا</aside>'
        + outer + '<footer class="main-footer">فسخانستا · لوحة الإدارة</footer></div><script>' + script + '</script></body></html>';
}

// Run inside a rendered fixture. Measures actual layout rather than matching CSS.
function observeGeometry() {
    const channel = document.documentElement.dataset.channel || 'whatsapp';
    const host = document.getElementById(channel === 'whatsapp' ? 'whatsapp-inbox' : 'meta-social-inbox');
    const box = element => {const r = element.getBoundingClientRect(); return {top:r.top,bottom:r.bottom,left:r.left,right:r.right,width:r.width,height:r.height};};
    const history = host.querySelector('[data-wa-scroll]');
    const compose = host.querySelector('[data-wa-replies]');
    const text = host.querySelector('[data-wa-reply-text]');
    const threads = document.querySelector('#meta-message-hub > .wa-inbox-workspace > .wa-inbox-threads .wa-inbox-thread-scroll');
    const settings = document.querySelector('.meta-ai-settings[open] > form');
    const drawer = host.querySelector('[data-wa-order-drawer]');
    return {viewport:{width:innerWidth,height:innerHeight},document:{width:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight},
        chat:box(host.querySelector('.wa-inbox-chat')),history:box(history),compose:box(compose),text:box(text),
        historyOverflow:history.scrollHeight>history.clientHeight,historyScroll:history.scrollTop,threadOverflow:threads.scrollHeight>threads.clientHeight,
        textareaDisabled:text.disabled,settings:settings?box(settings):null,drawer:drawer.open?box(drawer):null,
        composerHit:document.elementFromPoint(text.getBoundingClientRect().left+20,text.getBoundingClientRect().top+20)===text};
}

function staticRegression() {
    let checks = 0;
    const check = (value, message) => {assert(value, message); checks++;};
    const css = fs.readFileSync(path.join(root, 'public/css/dashboard-meta-inbox-viewport.css'), 'utf8');
    const strip = value => value.replace(/\/\*[\s\S]*?\*\//g, '');
    const normalized = value => value.replace(/\s+/g, ' ').trim();
    const blocks = source => Array.from(strip(source).matchAll(/([^{}]+)\{([^{}]*)\}/g))
        .map(match => ({selector:normalized(match[1]),declarations:match[2].trim()}));
    const rootSelector = ':is(#meta-message-hub, #whatsapp-inbox, #meta-social-inbox)';
    const declaration = (selector, property, expected, label) => {
        const matching = blocks(css).filter(rule => rule.selector === selector);
        check(matching.some(rule => new RegExp('(?:^|;)\\s*' + property + '\\s*:\\s*' + expected + '\\s*(?:;|$)').test(rule.declarations)), label);
    };
    // Verify the fixture really uses the observed live source rather than a
    // simplified replacement that could conceal the parent/child regression.
    for (const name of Object.keys(pins)) {
        const source = blob(name);
        const bytes = Buffer.from(source);
        const hash = crypto.createHash('sha1').update(Buffer.from('blob ' + bytes.length + '\0')).update(bytes).digest('hex');
        check(hash === pins[name], 'Public source blob must match ' + name);
    }
    const hub = blob('hub'), whatsapp = blob('whatsappPane'), social = blob('socialPane');
    check(hub.includes('id="meta-message-hub" class="wa-inbox meta-message-hub"'), 'Live outer root is the unified hub');
    check(hub.includes('class="meta-hub-chat-area"'), 'Live hub has an intermediate chat area');
    check(hub.includes('data-hub-pane="meta" hidden') && hub.includes('data-hub-pane="whatsapp" hidden'), 'Both provider panes remain initially hidden');
    check(/class="wa-inbox-message-area"><div class="wa-inbox-message-scroll"/.test(whatsapp), 'WhatsApp history is inside a non-flex wrapper');
    check(social.includes('<div class="wa-inbox-message-scroll"'), 'Meta retains its direct history container');
    check(/\.meta-hub-pane \.wa-inbox-message-scroll\s*\{[^}]*height:\s*auto/.test(blob('hubCss')), 'Baseline hub overwrites the wrapped history height');
    check(/\.wa-inbox-message-scroll\s*\{[^}]*height:\s*100%/.test(blob('inboxCss')), 'WhatsApp baseline intended a bounded 100% history');
    declaration(rootSelector + ' .wa-inbox-message-area > .wa-inbox-message-scroll', 'height', '100%', 'Wrapped history regains its parent height');
    declaration(rootSelector + ' .wa-inbox-message-area > .wa-inbox-message-scroll', 'overflow-y', 'auto', 'Long history scrolls internally');
    declaration(rootSelector + ' .wa-inbox-message-area', 'overflow', 'hidden', 'History wrapper cannot paint over composer');
    declaration(rootSelector + ' .wa-inbox-message-area', 'min-height', '0', 'History wrapper can shrink inside the chat');
    declaration(rootSelector + ' .wa-inbox-message-area', 'flex', '1 1 0', 'History receives the remaining height');
    // :is() inherits ID specificity. Both rules address the same height
    // property; the supplement beats the original two-class hub rule.
    check(rootSelector.includes('#meta-message-hub'), 'Correction has ID specificity above the hub class rule');
    check(!strip(css).includes('.meta-hub-pane .wa-inbox-message-scroll {'), 'Correction does not restore an unrestricted hub height override');
    declaration(rootSelector + ' .wa-inbox-chat > .wa-replies', 'position', 'relative', 'Composer remains in normal flow');
    check(blocks(css).some(rule => rule.selector.includes(rootSelector + ' .wa-inbox-chat > .wa-replies') && /flex:\s*0 0 auto/.test(rule.declarations)), 'Composer does not shrink with the transcript');
    declaration(rootSelector + ' > .wa-inbox-workspace', 'min-height', '0', 'Both inner and outer workspaces may fit the remaining screen');
    declaration(rootSelector + ' > .wa-inbox-workspace', 'overflow', 'hidden', 'Workspace content cannot create a page scrollbar');
    declaration(rootSelector + ' .wa-inbox-threads > .wa-inbox-thread-list', 'overflow-y', 'auto', 'Legacy social list remains scrollable');
    check(blocks(css).some(rule => rule.selector.includes(rootSelector + ' .wa-inbox-thread-scroll') && /overflow-y:\s*auto/.test(rule.declarations)), 'Unified conversation list remains scrollable');
    check(strip(css).includes('body:has(.content-wrapper.wa-inbox-wrapper > .wa-inbox)'), 'Full viewport layout is scoped to inbox pages');
    check(!/(?:^|\})\s*(?:html|body|\.wrapper)\s*\{/.test(strip(css)), 'No unscoped global layout override');
    check(/> \.wrapper\s*\{[^}]*height:\s*100dvh/.test(css), 'Root wrapper fits the visible screen');
    check(/> \.content-wrapper\.wa-inbox-wrapper\s*\{[^}]*min-height:\s*0 !important/.test(css), 'Inline AdminLTE minimum height cannot force page scroll');
    declaration(rootSelector + ' #meta-ai-controls .meta-ai-settings[open] > form', 'position', 'absolute', 'Expanded settings do not push composer outside the screen');
    declaration(rootSelector + ' #meta-ai-controls .meta-ai-settings[open] > form', 'overflow', 'auto', 'Expanded settings stay accessible within their own panel');
    check(!/\[hidden\]\s*\{/.test(strip(css)), 'No override makes a hidden provider pane visible');
    check(!/textarea[^{}]*\{[^}]*?(?:visibility|display|pointer-events)/.test(css), 'CSS does not override sender availability or input guards');
    check(css.includes('@supports not selector('), 'Older browsers retain a bounded fallback');
    for (const channel of ['whatsapp', 'messenger', 'instagram']) {
        const fixture = buildFixture({channel});
        check(fixture.includes('data-channel="' + channel + '"'), 'Fixture selects ' + channel);
        check(!/@(?:include|if|endif|foreach|endphp)\b/.test(fixture), 'Fixture contains resolved DOM for ' + channel);
        check(fixture.includes('i<200') && fixture.includes('i<60'), 'Fixture exercises long histories and conversation lists for ' + channel);
        check(!/<(?:script|link)[^>]+(?:src|href)=/.test(fixture), 'Fixture loads no external content for ' + channel);
    }
    const fixture = buildFixture();
    const script = fixture.slice(fixture.lastIndexOf('<script>') + 8, fixture.lastIndexOf('</script>'));
    new (require('vm').Script)(script); checks++;
    // Parse block nesting as a final static syntax check; this is not a claim
    // of a browser render or a geometric measurement.
    let depth = 0;
    for (const character of strip(css)) {if (character === '{') depth++; if (character === '}') depth--; assert(depth >= 0, 'CSS braces remain balanced');}
    check(true, 'CSS braces never close before opening');
    check(depth === 0, 'CSS blocks all close');
    return {status:'PASS',checks,verification:'STATIC_SOURCE_AND_CASCADE_ONLY',rendered:false,external_calls:0};
}

module.exports = {buildFixture, observeGeometry, staticRegression, pins};
if (require.main === module) {
    const output = process.argv[2];
    if (!output) console.log(JSON.stringify(staticRegression()));
    else {
        fs.writeFileSync(output, buildFixture({channel:process.argv[3] || 'whatsapp',patched:!process.argv.includes('--baseline'),settingsOpen:process.argv.includes('--settings'),drawerOpen:process.argv.includes('--drawer')}));
        console.log(JSON.stringify({fixture:output,bytes:fs.statSync(output).size,network_calls:0}));
    }
}
