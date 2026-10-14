'use strict';

// Run the actual coordinator and both source engines together. No customer network calls.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class Classes {
    constructor() { this.values = new Set(); }
    add(value) { this.values.add(value); }
    remove(value) { this.values.delete(value); }
    contains(value) { return this.values.has(value); }
    toggle(value, enabled) { if (enabled) this.add(value); else this.remove(value); }
}
function dataKey(name) { return name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); }
class Element {
    constructor(tag = 'div') {
        this.tag = tag; this.children = []; this.parentNode = null; this.dataset = {}; this.attributes = {};
        this.classList = new Classes(); this.listeners = {}; this.text = ''; this.hidden = false;
        this.scrollTop = 0; this.scrollHeight = 300; this.clientHeight = 100; this.disabled = false;
    }
    set textContent(value) { this.text = String(value); this.children.forEach(child => { child.parentNode = null; }); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(_) { throw new Error('Unsafe HTML assignment'); }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    appendChild(child) {
        if (child.tag === 'fragment') { child.children.slice().forEach(item => this.appendChild(item)); return child; }
        if (child.parentNode) child.parentNode.removeChild(child);
        this.children.push(child); child.parentNode = this; return child;
    }
    removeChild(child) { this.children = this.children.filter(item => item !== child); child.parentNode = null; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name]; }
    removeAttribute(name) { delete this.attributes[name]; if (name === 'src') this.src = ''; }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    removeEventListener(type, listener) { this.listeners[type] = (this.listeners[type] || []).filter(item => item !== listener); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(listener => listener(event)); }
    matches(selector) {
        if (selector.startsWith('[data-') && selector.endsWith(']')) return Object.prototype.hasOwnProperty.call(this.dataset, dataKey(selector.slice(6, -1)));
        return selector === this.tag;
    }
    closest(selector) { for (let el = this; el; el = el.parentNode) if (el.matches(selector)) return el; return null; }
    contains(target) { return this === target || this.children.some(child => child.contains(target)); }
    querySelectorAll(selector) { return walk(this).filter(el => selector.split(',').some(part => el.matches(part))); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    click() { for (let el = this; el; el = el.parentNode) el.dispatchEvent({type:'click', target:this}); }
    getBoundingClientRect() {
        for (let el = this; el; el = el.parentNode) if (el.hidden) return {top:0,bottom:0,left:0,right:0};
        return {top:10,bottom:90,left:0,right:500};
    }
    pause() { this.paused = true; }
    load() {}
}
function walk(root) { return root.children.flatMap(child => [child, ...walk(child)]); }
function mark(parent, data, tag = 'div') { const el = new Element(tag); el.dataset[data] = ''; parent.appendChild(el); return el; }

function fixture(initialChannel = '') {
    const document = new Element(), window = new Element(), timers = new Map(), ids = new Map();
    const state = {calls:[], events:[], status:{meta:200,whatsapp:200}, cleanup:[], drafts:{meta:'',whatsapp:''},
        messages:null,delayMetaThreads:false,metaThreadResolvers:[],timerDelays:new Map()};
    const labels = {customer:'Customer',business:'Business',loading:'Loading',empty:'Empty',denied:'DENIED',
        unavailable:'Unavailable',connected:'Updated',choose_conversation:'Choose',no_messages:'No messages',offline:'Offline',
        message_unavailable:'Unavailable message',types:{text:'Text',audio:'Audio',other:'Message'}};
    const allThreads = {
        meta:[{id:1,channel:'messenger',name:'Messenger Person',account_key:'PAGE_A',last_message_at:'2026-10-14T19:00:00Z',preview:{type:'text',text:'Messenger preview'}},
            {id:2,channel:'instagram',name:'Instagram Person',account_key:'IG_A',last_message_at:'2026-10-14T20:00:00Z',preview:{type:'text',text:'<script>Instagram preview</script>'}},
            {id:3,channel:'instagram',name:'Older Instagram',account_key:'IG_A',last_message_at:'2026-10-14T17:00:00Z',preview:{type:'text',text:'Older Instagram preview'}}],
        whatsapp:[{id:1,name:'WhatsApp Person',phone:'+201000000001',last_message_at:'2026-10-14T21:00:00Z',preview:{type:'text',text:'WhatsApp preview'}},
            {id:3,name:'Older WhatsApp',phone:'+201000000003',last_message_at:'2026-10-14T18:00:00Z',preview:{type:'text',text:'Older WhatsApp preview'}}],
    };
    function build(channel = '') {
        const host = new Element(), bootstrap = new Element('script');
        const elements = {}, panes = {}, panels = {}, sourceElements = {}, tabs = {};
        ['threads','status','count','more','unread','empty','refresh'].forEach(name => { elements[name] = mark(host, dataKey('hub-' + name), name === 'threads' ? 'ul' : 'div'); });
        ['', 'messenger','instagram','whatsapp'].forEach(name => { tabs[name] = mark(host,'hubChannel','button'); tabs[name].dataset.hubChannel = name; });
        ['meta','whatsapp'].forEach(source => {
            const pane = new Element(); pane.dataset.hubPane = source; pane.hidden = true; host.appendChild(pane); panes[source] = pane;
            const panel = new Element(), sourceBootstrap = new Element('script'), sourceFields = {};
            pane.append(panel,sourceBootstrap); panels[source] = panel; sourceElements[source] = sourceFields;
            ['thread-list','list-status','count','messages','chat-status','title','customer','alert','connection','scroll','older','more-threads',
                'refresh','back','unread-total'].forEach(name => { sourceFields[name] = mark(panel,dataKey('wa-' + name)); });
            if (source === 'meta') ['overlay','overlay-body','overlay-title'].forEach(name => { sourceFields[name] = mark(panel,dataKey('meta-' + name)); });
            const base = 'https://example.test/admin/' + (source === 'meta' ? 'meta' : 'whatsapp');
            sourceBootstrap.textContent = JSON.stringify({available:true,hub_chat:true,meta_chat:source === 'meta',csrf:'fixture-csrf',labels,
                conversations_url:base + '/conversations',messages_base_url:base + '/conversations',unread_url:base + '/unread',unread_label:'Unread'});
            ids.set(source === 'meta' ? 'meta-social-inbox' : 'whatsapp-inbox',panel);
            ids.set(source === 'meta' ? 'meta-social-inbox-bootstrap' : 'whatsapp-inbox-bootstrap',sourceBootstrap);
        });
        bootstrap.textContent = JSON.stringify({labels,initial_channel:channel});
        ids.set('meta-message-hub',host); ids.set('meta-message-hub-bootstrap',bootstrap);
        return {host,elements,panes,panels,sourceElements,tabs};
    }
    document.documentElement = {lang:'en'}; document.hidden = false;
    document.getElementById = id => ids.get(id) || null;
    document.createElement = tag => new Element(tag); document.createDocumentFragment = () => new Element('fragment');
    const originalDispatch = document.dispatchEvent.bind(document);
    document.dispatchEvent = event => { state.events.push(event); originalDispatch(event); };
    window.location = {href:'https://example.test/admin/meta'}; window.innerHeight = 950; window.innerWidth = 1400;
    window.DashboardSPA = {isCurrentPage:() => true,onCleanup:callback => state.cleanup.push(callback)};
    ['meta','whatsapp'].forEach(source => document.addEventListener(source + ':private-reset', () => { state.drafts[source] = ''; }));
    async function fetch(url, options) {
        state.calls.push({url,options});
        assert.strictEqual(options.cache,'no-store'); assert.strictEqual(options.credentials,'same-origin');
        const parsed = new URL(url), source = parsed.pathname.includes('/whatsapp/') ? 'whatsapp' : 'meta', status = state.status[source];
        let data;
        if (parsed.pathname.endsWith('/unread')) data = {success:true,total_unread:source === 'meta' ? 6 : 5,
            conversations:source === 'meta' ? [{id:1,unread_count:2},{id:2,unread_count:4}] : [{id:1,unread_count:5}]};
        else if (parsed.pathname.endsWith('/messages')) {
            const id = Number(parsed.pathname.match(/\/([0-9]+)\/messages$/)[1]);
            const messages = source === 'meta' && state.messages !== null ? state.messages
                : [{id:1,direction:'inbound',type:'text',text:source + ' private message ' + id,sent_at:'2026-10-14T21:00:00Z'}];
            data = {success:true,conversation:allThreads[source].find(thread => thread.id === id),
                messages,last_id:Math.max(Number(parsed.searchParams.get('after_id')) || 0,...messages.map(message => message.id)),has_more:false,next_before_id:null};
        } else {
            if (source === 'meta' && state.delayMetaThreads) await new Promise(resolve => state.metaThreadResolvers.push(resolve));
            let candidates = allThreads[source];
            if (source === 'meta' && parsed.searchParams.get('channel')) candidates = candidates.filter(thread => thread.channel === parsed.searchParams.get('channel'));
            const pageSize = source === 'meta' ? 2 : 1, paged = parsed.searchParams.has('cursor');
            data = {success:true,conversations:paged ? candidates.slice(pageSize) : candidates.slice(0,pageSize),
                total:candidates.length,next_cursor:!paged && candidates.length > pageSize ? source + '-older' : null};
        }
        return {ok:status === 200,status,redirected:false,headers:{get:() => 'application/json'},json:async() => data};
    }
    let timerId = 0;
    const context = {window,document,navigator:{onLine:true},URL,AbortController,Map,Set,Promise,Date,fetch,console,
        CustomEvent:class { constructor(type,options) { this.type = type; this.detail = options.detail; } },
        setTimeout:(callback,delay) => { timers.set(++timerId,callback); state.timerDelays.set(timerId,delay); return timerId; },
        clearTimeout:id => { timers.delete(id); state.timerDelays.delete(id); }};
    vm.createContext(context);
    const run = name => vm.runInContext(fs.readFileSync(path.join(__dirname,'..','public','js',name),'utf8'),context);
    const dom = build(initialChannel);
    run('dashboard-whatsapp-inbox.js'); run('dashboard-meta-inbox.js'); run('dashboard-meta-message-hub.js');
    return {document,window,context,state,timers,dom,build,run,allThreads};
}
async function settle() { for (let i = 0; i < 12; i++) await new Promise(setImmediate); }
function keys(test) { return test.dom.elements.threads.querySelectorAll('[data-hub-thread]').map(el => el.dataset.hubThread); }
function select(test,key) {
    const button = test.dom.elements.threads.querySelectorAll('[data-hub-thread]').find(el => el.dataset.hubThread === key);
    assert(button,'Expected a visible hub conversation ' + key); button.click();
}
function pageCalls(test,source) { return test.state.calls.filter(call => call.url.includes('/' + source + '/') && new URL(call.url).searchParams.has('cursor')); }

async function allSourcesAndSwitching() {
    const test = fixture(); await settle();
    assert.deepStrictEqual(keys(test),['whatsapp:1','meta:2','meta:1'],'All merges three channels by the latest message');
    assert.strictEqual(test.dom.tabs[''].getAttribute('aria-pressed'),'true');
    assert.strictEqual(test.dom.elements.count.textContent,'5');
    assert.strictEqual(test.dom.elements.unread.textContent,'Unread: 11');
    assert(test.dom.elements.threads.textContent.includes('<script>Instagram preview</script>'),'Customer markup stays literal text');
    assert.strictEqual(test.state.calls.filter(call => new URL(call.url).pathname.endsWith('/messages')).length,0,'Initial hidden panes only read the list');
    select(test,'meta:1'); await settle();
    assert.strictEqual(test.window.DashboardMetaInbox.selection(),1); assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(),0);
    assert.strictEqual(test.dom.panes.meta.hidden,false); assert.strictEqual(test.dom.panes.whatsapp.hidden,true);
    assert(test.dom.sourceElements.meta.messages.textContent.includes('meta private message 1'));
    test.state.drafts.meta = 'Unsent social reply'; test.state.drafts.whatsapp = 'Old WhatsApp draft';
    select(test,'whatsapp:1'); await settle();
    assert.strictEqual(test.window.DashboardMetaInbox.selection(),0); assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(),1,
        'Colliding numeric conversation IDs select the engine named by the source key');
    assert.strictEqual(test.state.drafts.meta,''); assert.strictEqual(test.state.drafts.whatsapp,'');
    assert.strictEqual(test.dom.sourceElements.meta.messages.textContent,'');
    assert.strictEqual(test.dom.panes.meta.hidden,true); assert.strictEqual(test.dom.panes.whatsapp.hidden,false);
    test.dom.sourceElements.whatsapp.back.click(); await settle();
    assert.strictEqual(test.dom.host.classList.contains('has-selection'),false); assert.strictEqual(test.dom.elements.empty.hidden,false);
    assert.strictEqual(test.window.DashboardWhatsAppInbox.selection(),0);
    test.window.DashboardMetaMessageHub.destroy(); test.window.DashboardMetaInbox.destroy(); test.window.DashboardWhatsAppInbox.destroy();
}

async function filtersAndPagination() {
    const test = fixture(); await settle();
    test.dom.tabs.instagram.click(); await settle();
    assert.deepStrictEqual(keys(test),['meta:2','meta:3']);
    assert.strictEqual(test.dom.elements.count.textContent,'2'); assert.strictEqual(test.dom.elements.unread.textContent,'Unread: 4');
    assert(test.state.calls.some(call => new URL(call.url).searchParams.get('channel') === 'instagram'));
    test.dom.tabs.messenger.click(); await settle();
    assert.deepStrictEqual(keys(test),['meta:1']); assert.strictEqual(test.dom.elements.count.textContent,'1');
    test.dom.tabs.whatsapp.click(); await settle();
    assert.deepStrictEqual(keys(test),['whatsapp:1']); assert.strictEqual(test.dom.elements.unread.textContent,'Unread: 5');
    const metaBefore = pageCalls(test,'meta').length;
    test.dom.elements.more.click(); await settle();
    assert.strictEqual(pageCalls(test,'meta').length,metaBefore,'WhatsApp pagination never calls the social source');
    assert.strictEqual(pageCalls(test,'whatsapp').length,1); assert.deepStrictEqual(keys(test),['whatsapp:1','whatsapp:3']);
    assert.strictEqual(test.dom.elements.more.hidden,true);
    test.dom.tabs[''].click(); await settle();
    assert.strictEqual(test.dom.elements.more.hidden,false,'All can still load the unpaged Meta source');
    test.dom.elements.more.click(); await settle();
    assert.strictEqual(pageCalls(test,'meta').length,1); assert.strictEqual(pageCalls(test,'whatsapp').length,1);
    assert.deepStrictEqual(keys(test),['whatsapp:1','meta:2','meta:1','whatsapp:3','meta:3']);
    assert.strictEqual(test.dom.elements.more.hidden,true);
    test.window.DashboardMetaMessageHub.destroy(); test.window.DashboardMetaInbox.destroy(); test.window.DashboardWhatsAppInbox.destroy();
}

async function deniedAndRemount() {
    const test = fixture(); await settle(); select(test,'whatsapp:1'); await settle();
    test.state.drafts.meta = 'private draft'; test.state.drafts.whatsapp = 'private draft';
    test.state.status.whatsapp = 403; test.dom.sourceElements.whatsapp.refresh.click(); await settle();
    assert.strictEqual(test.dom.elements.threads.textContent,'','Denied access must clear all merged customer text despite synchronous source reset snapshots');
    assert.strictEqual(test.dom.elements.status.textContent,'DENIED'); assert.strictEqual(test.dom.elements.unread.hidden,true);
    assert.strictEqual(test.dom.panes.meta.hidden,true); assert.strictEqual(test.dom.panes.whatsapp.hidden,true);
    test.dom.sourceElements.meta.refresh.click(); await settle();
    assert.strictEqual(test.dom.elements.threads.textContent,'','An ongoing source poll cannot restore customer text after hub access is denied');
    assert.strictEqual(test.state.drafts.meta,''); assert.strictEqual(test.state.drafts.whatsapp,'');
    test.document.dispatchEvent({type:'dashboard:before-unload'});
    assert.strictEqual(test.dom.elements.threads.textContent,'');
    assert.strictEqual((test.document.listeners['meta-hub:threads'] || []).length,0);
    assert.strictEqual(test.timers.size,0);
    test.state.status.whatsapp = 200;
    test.dom = test.build('messenger');
    test.document.dispatchEvent({type:'dashboard:page-mounted'}); await settle();
    assert.deepStrictEqual(keys(test),['meta:1']);
    assert.strictEqual(test.dom.tabs.messenger.getAttribute('aria-pressed'),'true');
    assert.strictEqual((test.document.listeners['meta-hub:threads'] || []).length,1,'SPA remount attaches exactly one coordinator subscription');
    test.document.dispatchEvent({type:'dashboard:page-mounted'}); await settle();
    assert.strictEqual((test.document.listeners['meta-hub:threads'] || []).length,1,'Repeated mount reuses the active host');
    test.document.dispatchEvent({type:'dashboard:before-unload'});
}

function acceptedReceipt(replyId = 20, extra = {}) {
    return Object.assign({reply_id:replyId,state:'ACCEPTED',kind:'text',text:'Confirmed outgoing reply',
        created_at:'2026-10-14T21:00:02Z',projection_key:'a'.repeat(64),projected_message_id:null,hidden:false},extra);
}
function liveReceipt(test, reply, overrides = {}, name = 'meta:reply-accepted') {
    const scope = test.window.DashboardMetaInbox.selectionState();
    test.document.dispatchEvent(new test.context.CustomEvent(name,{detail:Object.assign({},scope,
        name === 'meta:accepted-replies' ? {replies:reply} : {reply},overrides)}));
}
function receiptRows(test) { return test.dom.sourceElements.meta.messages.children.filter(el => String(el.dataset.waMessageId).startsWith('reply:')); }

async function immediateMetaReceiptsAndScope() {
    const test = fixture(); await settle();
    test.window.DashboardWhatsAppInbox.destroy(); await settle();
    assert.deepStrictEqual(Array.from(test.state.timerDelays.values()),[1000],'The actual Meta inbox polls each second without the dashboard refresh loop');
    select(test,'meta:1'); await settle();
    const scope = test.window.DashboardMetaInbox.selectionState();
    assert.strictEqual(scope.channel,'messenger'); assert.strictEqual(scope.namespace,'https://example.test/admin/meta/conversations');
    const selectionEvent = test.state.events.filter(event => event.type === 'meta:conversation-selected').at(-1);
    assert.strictEqual(selectionEvent.detail.generation,scope.generation,'Composer selection gets the engine generation used to validate reply receipts');
    const timerCallbacks = test.timers.size, calls = test.state.calls.length;
    liveReceipt(test,acceptedReceipt());
    assert.strictEqual(receiptRows(test).length,1,'A confirmed receipt is displayed synchronously before any polling response or scheduled timer');
    assert(receiptRows(test)[0].textContent.includes('Accepted by Meta')); assert(receiptRows(test)[0].textContent.includes('Confirmed outgoing reply'));
    assert(test.state.calls.length > calls,'Confirmed send immediately starts native echo refresh');
    assert(timerCallbacks > 0,'A scheduled periodic poll existed but the test never executes it');
    await settle();
    const beforeForeign = test.state.calls.length;
    [{generation:scope.generation - 1},{namespace:'https://example.test/admin/whatsapp/conversations'},
        {channel:'instagram'},{conversation:2},{generation:null}].forEach(overrides => liveReceipt(test,acceptedReceipt(99,{text:'Foreign private reply'}),overrides));
    await settle();
    assert.strictEqual(test.state.calls.length,beforeForeign,'Stale generation or another source/channel/customer cannot wake this inbox');
    assert(!test.dom.sourceElements.meta.messages.textContent.includes('Foreign private reply'));
    for (const invalid of [acceptedReceipt(30,{state:'UNKNOWN'}),acceptedReceipt(31,{created_at:'not-a-date'}),
        acceptedReceipt('32'),acceptedReceipt(33,{projection_key:'not-a-key'}),acceptedReceipt(34,{kind:'unrecognized'}),
        acceptedReceipt(35,{projected_message_id:-1})]) {
        liveReceipt(test,invalid); assert.strictEqual(receiptRows(test).length,1,'Unknown or malformed sends are never shown as accepted');
        await settle();
    }
    test.document.hidden = true; const hiddenCalls = test.state.calls.length;
    liveReceipt(test,acceptedReceipt(36,{text:'Hidden private reply'})); await settle();
    assert.strictEqual(test.state.calls.length,hiddenCalls); assert(!test.dom.sourceElements.meta.messages.textContent.includes('Hidden private reply'));
    test.document.hidden = false; test.context.navigator.onLine = false;
    liveReceipt(test,acceptedReceipt(37,{text:'Offline private reply'})); await settle();
    assert.strictEqual(test.state.calls.length,hiddenCalls); assert(!test.dom.sourceElements.meta.messages.textContent.includes('Offline private reply'));
    test.context.navigator.onLine = true;
    test.window.DashboardMetaInbox.resetSelection();
    assert.strictEqual(test.dom.sourceElements.meta.messages.textContent,'','Changing source releases local accepted receipts');
    liveReceipt(test,acceptedReceipt(38,{text:'Late deselected private reply'}),scope); await settle();
    assert.strictEqual(test.dom.sourceElements.meta.messages.textContent,'');
    test.document.dispatchEvent({type:'dashboard:before-unload'});
    assert.strictEqual((test.document.listeners['meta:reply-accepted'] || []).length,0);
    assert.strictEqual((test.document.listeners['meta:accepted-replies'] || []).length,0);
}

async function exactProjectionAndHiddenReceipts() {
    const test = fixture(); await settle(); select(test,'meta:1'); await settle();
    liveReceipt(test,acceptedReceipt()); await settle(); assert.strictEqual(receiptRows(test).length,1);
    test.state.messages = [{id:2,direction:'outbound',type:'text',text:'Confirmed outgoing reply',projection_key:'a'.repeat(64),sent_at:'2026-10-14T21:00:02Z'}];
    test.dom.sourceElements.meta.refresh.click(); await settle();
    assert.strictEqual(receiptRows(test).length,0,'The exact projected message key replaces the local accepted shadow');
    assert.strictEqual(test.dom.sourceElements.meta.messages.children.filter(el => el.textContent.includes('Confirmed outgoing reply')).length,1);
    liveReceipt(test,acceptedReceipt(21,{projection_key:'b'.repeat(64)})); await settle();
    assert.strictEqual(receiptRows(test).length,1,'Identical text with a different projection key is a separate confirmed send');
    liveReceipt(test,[acceptedReceipt(21,{projection_key:'b'.repeat(64),hidden:true})],{},'meta:accepted-replies');
    assert.strictEqual(receiptRows(test).length,0,'An authoritative hidden reply-state receipt removes the local shadow');
    liveReceipt(test,[acceptedReceipt(22,{projection_key:'b'.repeat(64),projected_message_id:2})],{},'meta:accepted-replies');
    assert.strictEqual(receiptRows(test).length,0,'An exact loaded projected ID also suppresses the shadow');
    test.state.messages = [{id:2,direction:'outbound',type:'system',text:'Hidden from dashboard',hidden:true,
        projection_key:'a'.repeat(64),sent_at:'2026-10-14T21:00:02Z'}];
    select(test,'meta:2'); await settle(); select(test,'meta:1'); await settle();
    liveReceipt(test,acceptedReceipt()); await settle();
    assert.strictEqual(receiptRows(test).length,0,'A hidden native message retains its projection key and cannot be resurrected by an accepted shadow');
    assert(!test.dom.sourceElements.meta.messages.textContent.includes('Confirmed outgoing reply'));
    assert(test.dom.sourceElements.meta.messages.textContent.includes('Hidden from dashboard'));
    liveReceipt(test,[],{},'meta:accepted-replies'); assert.strictEqual(receiptRows(test).length,0);
    test.document.dispatchEvent({type:'dashboard:before-unload'});
}

async function metaAcceptedBusyCoalescing() {
    const test = fixture(); await settle(); select(test,'meta:1'); await settle();
    test.state.delayMetaThreads = true; const before = test.state.calls.length;
    liveReceipt(test,acceptedReceipt()); await settle();
    assert.strictEqual(test.state.metaThreadResolvers.length,1); const pendingCalls = test.state.calls.length;
    liveReceipt(test,acceptedReceipt(21)); liveReceipt(test,acceptedReceipt(22)); await settle();
    assert.strictEqual(test.state.calls.length,pendingCalls,'Accepted sends coalesce into one follow-up while a Meta poll is active');
    test.state.delayMetaThreads = false; test.state.metaThreadResolvers[0](); await settle();
    assert.strictEqual(test.state.calls.slice(before).filter(call => new URL(call.url).pathname === '/admin/meta/conversations').length,2,
        'The in-flight refresh has exactly one immediate follow-up');
    assert.strictEqual(receiptRows(test).length,3,'Queued refreshes preserve all confirmed local receipts until native projection');
    test.document.dispatchEvent({type:'dashboard:before-unload'});
}

(async () => {
    await allSourcesAndSwitching(); await filtersAndPagination(); await deniedAndRemount();
    await immediateMetaReceiptsAndScope(); await exactProjectionAndHiddenReceipts(); await metaAcceptedBusyCoalescing();
    console.log('META_MESSAGE_HUB_UI_PASS actual engines, all channels, filters, source ID isolation, drafts, unread, pagination, denial and SPA remount');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
