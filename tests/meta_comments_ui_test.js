'use strict';
// Exercise the production browser module with a local DOM and Graph-free HTTP fixtures.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
class Classes {
    constructor() { this.values = new Set(); }
    add(value) { this.values.add(value); }
    remove(value) { this.values.delete(value); }
    contains(value) { return this.values.has(value); }
}
function dataKey(value) { return value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); }
class Element {
    constructor(tag='div') { this.tag=tag; this.children=[]; this.parentNode=null; this.listeners={}; this.attributes={}; this.dataset={};
        this.text=''; this.value=''; this.hidden=false; this.disabled=false; this.classList=new Classes(); this.scrollTop=0; }
    set textContent(value) { this.text=String(value); this.children.forEach(child=>{child.parentNode=null;}); this.children=[]; }
    get textContent() { return this.text+this.children.map(child=>child.textContent).join(''); }
    set innerHTML(_) { throw new Error('API content must never become HTML'); }
    appendChild(child) { this.children.push(child); child.parentNode=this; return child; }
    setAttribute(name,value) { this.attributes[name]=String(value); }
    getAttribute(name) { return this.attributes[name]; }
    removeAttribute(name) { delete this.attributes[name]; if(name==='href') this.href=''; }
    addEventListener(type,listener) { (this.listeners[type] ||= []).push(listener); }
    removeEventListener(type,listener) { this.listeners[type]=(this.listeners[type]||[]).filter(item=>item!==listener); }
    dispatchEvent(event) { (this.listeners[event.type]||[]).slice().forEach(listener=>listener(event)); }
    matches(selector) { return selector.startsWith('[data-') && selector.endsWith(']') ? Object.prototype.hasOwnProperty.call(this.dataset,dataKey(selector.slice(6,-1))) : selector===this.tag; }
    closest(selector) { for(let item=this;item;item=item.parentNode) if(item.matches(selector)) return item; return null; }
    contains(target) { return this===target || this.children.some(child=>child.contains(target)); }
    querySelectorAll(selector) { return this.children.flatMap(child=>[child,...walk(child)]).filter(child=>child.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    click() { for(let item=this;item;item=item.parentNode) item.dispatchEvent({type:'click',target:this}); }
    focus() { this.focused=true; }
    setSelectionRange(start,end) { this.selectionStart=start; this.selectionEnd=end; }
}
function walk(root) { return root.children.flatMap(child=>[child,...walk(child)]); }
function mark(parent,name,tag='div') { const item=new Element(tag); item.dataset[dataKey('mc-'+name)]=''; parent.appendChild(item); return item; }
const key=letter=>letter.repeat(64);
const viewer=key('a'), fb=key('b'), ig=key('c'), postA=key('1'), postB=key('2'), commentA=key('3'), commentB=key('4'), replyA=key('5');
const postRow=(id=postA,extra={})=>Object.assign({key:id,handle:'post-'+id[0],text:'عرض الرنجة <img src=x onerror=alert(1)>',created_at:'2026-10-14T20:00:00Z',permalink:'https://www.facebook.com/fasakhansta/posts/123'},extra);
const commentRow=(id=commentA,extra={})=>Object.assign({key:id,handle:'comment-'+id[0],text:'السعر كام؟ <script>unsafe()</script>',author:'عميل',created_at:'2026-10-14T20:01:00Z',replies_count:1,can_reply:true},extra);
function fixture(locale='ar') {
    const document=new Element(),window=new Element(),ids=new Map(),timers=new Map(),storage=new Map();
    const state={calls:[],cleanup:[],delays:new Map(),sendState:'ACCEPTED',sendFail:false,hold:new Map(),resolvers:new Map(),nextPosts:null,nextComments:null,nextReplies:null,
        postRows:[postRow(),postRow(postB,{text:'بوست آخر'})],commentRows:[commentRow(),commentRow(commentB,{author:'عميل آخر'})],replyRows:[commentRow(replyA,{text:'رد الصفحة',author:'فسخانستا'})],denied:false,permissionAccount:null};
    document.documentElement={lang:'ar'}; document.hidden=false; document.getElementById=id=>ids.get(id)||null; document.createElement=tag=>new Element(tag);
    window.location={href:'https://dashboard.test/admin/meta/comments'}; window.sessionStorage={getItem:name=>storage.get(name)||null,setItem:(name,value)=>storage.set(name,value)};
    let requestCounter=0;
    window.crypto={randomUUID:()=> 'aaaaaaaa-bbbb-4ccc-8ddd-'+String(++requestCounter).padStart(12,'0')};
    window.DashboardSPA={isCurrentPage:()=>true,onCleanup:callback=>state.cleanup.push(callback)};
    const labels={all:'الكل',facebook:'فيسبوك',instagram:'إنستجرام',anonymous:'مستخدم',choose_post:'اختر بوست',choose_comment:'اختر تعليق',
        loading:'تحميل',empty_posts:'لا بوستات',empty_comments:'لا تعليقات',empty_accounts:'لا حسابات',unavailable:'تعذر',denied:'ممنوع',reply:'رد',comments:'تعليقات',
        show_replies:'عرض الردود',hide_replies:'إخفاء الردود',more_replies:'ردود إضافية',reply_to:'الرد على',public:'علني باسم',public_hint:'علني',
        send:'نشر',sending:'جاري النشر',accepted:'تم النشر',unknown:'غير مؤكد',failed:'فشل',offline:'غير متصل',limit:'الحد الأقصى',
        emoji_reactions:'وجوه وتفاعلات',emoji_hearts:'قلوب وإشارات',emoji_extras:'رموز ومناسبات'};
    function build(overrides={}) {
        const host=new Element(),fields={};
        ['account','alert','posts','post-count','post-status','more-posts','comments','comment-status','more-comments','post-title','post-link','scroll','form',
            'reply-text','reply-target','reply-preview','public','send','send-label','send-status','letter-count','refresh','back','emoji-toggle','emoji-wrap','emoji-picker','emoji-grid','emoji-tabs'].forEach(name=>fields[name]=mark(host,name));
        fields['emoji-picker'].hidden=true;
        ['', 'facebook','instagram'].forEach(channel=> { const button=mark(host,'channel','button'); button.dataset.mcChannel=channel; });
        const bootstrap=new Element('script'); bootstrap.textContent=JSON.stringify(Object.assign({viewer_key:viewer,csrf:'fixture-csrf',labels,locale},overrides));
        ids.set('meta-comments',host); ids.set('meta-comments-bootstrap',bootstrap); return {host,fields,bootstrap};
    }
    let timerId=0;
    async function fetch(url,options) {
        const parsed=new URL(url), endpoint=parsed.pathname.split('/').pop(); state.calls.push({url,options,endpoint});
        assert.strictEqual(parsed.origin,'https://dashboard.test'); assert.strictEqual(options.credentials,'same-origin'); assert.strictEqual(options.cache,'no-store');
        assert.strictEqual(options.redirect,'error'); assert.strictEqual(options.headers.Accept,'application/json');
        if(state.hold.get(endpoint)) await new Promise(resolve=>{(state.resolvers.get(endpoint)||state.resolvers.set(endpoint,[]).get(endpoint)).push(resolve);});
        if(endpoint==='reply' && state.sendFail) throw new Error('Network result lost');
        let data;
        const more=parsed.searchParams.has('cursor');
        if(endpoint==='accounts') data={success:true,viewer_key:viewer,accounts:[{account_key:fb,channel:'facebook',label:'صفحة فسخانستا'},{account_key:ig,channel:'instagram',label:'Fasakhansta Instagram'}]};
        if(endpoint==='posts') data={success:true,posts:more?[postRow(key('6'),{text:'بوست أقدم'})]:state.postRows,next_cursor:more?null:state.nextPosts};
        if(endpoint==='comments') data={success:true,comments:more?[commentRow(key('7'))]:state.commentRows,next_cursor:more?null:state.nextComments};
        if(endpoint==='replies') data={success:true,replies:more?[commentRow(key('8'),{text:'رد أقدم'})]:state.replyRows,next_cursor:more?null:state.nextReplies};
        if(endpoint==='reply') data={success:state.sendState==='ACCEPTED',state:state.sendState,reply:commentRow(key('9'),{text:'رد مقبول',author:'الصفحة'})};
        const permissionDenied=state.permissionAccount===parsed.searchParams.get('account_key') && endpoint==='posts';
        if(state.denied && endpoint!=='accounts') data={success:false,reason:'ACCESS_DENIED',message:'تحتاج صلاحية التعليقات',required_permissions:[]};
        if(permissionDenied) data={success:false,reason:'PERMISSION_REQUIRED',message:'تحتاج صلاحية الصفحة',required_permissions:['pages_read_user_content']};
        return {ok:!state.denied && !permissionDenied,status:state.denied||permissionDenied?403:200,redirected:false,headers:{get:()=> 'application/json'},json:async()=>data};
    }
    const navigator={onLine:true};
    const context={window,document,navigator,URL,AbortController,Map,Set,Promise,Date,fetch,Uint8Array,console,
        setTimeout:(callback,delay)=>{timers.set(++timerId,callback); state.delays.set(timerId,delay); return timerId;},
        clearTimeout:id=>{timers.delete(id); state.delays.delete(id);}};
    vm.createContext(context); const dom=build();
    const run=()=>vm.runInContext(fs.readFileSync(path.join(__dirname,'..','public','js','dashboard-meta-comments.js'),'utf8'),context);
    run(); return {state,document,window,dom,context,timers,storage,build,run,navigator};
}
async function settle() { for(let i=0;i<14;i++) await new Promise(setImmediate); }
function control(test,name,value) { const candidates=test.dom.host.querySelectorAll('[data-mc-'+name+']'); return value===undefined?candidates[0]:candidates.find(el=>el.dataset[dataKey('mc-'+name)]===value); }
function click(test,name,value) { const el=control(test,name,value); assert(el,'Missing '+name+' '+value); el.click(); }
function selectComment(test,id=commentA) { click(test,'reply',id); }
function draft(test,value) { test.dom.fields['reply-text'].value=value; test.dom.fields['reply-text'].dispatchEvent({type:'input'}); }
function submit(test) { test.dom.fields.form.dispatchEvent({type:'submit',preventDefault(){}}); }
function calls(test,endpoint) { return test.state.calls.filter(call=>call.endpoint===endpoint); }
function release(test,endpoint) { (test.state.resolvers.get(endpoint)||[]).splice(0).forEach(resolve=>resolve()); test.state.hold.set(endpoint,false); }
function dispose(test) { test.window.DashboardMetaComments.destroy(); }
async function initialFilteringSafeRendering() {
    const test=fixture(); await settle();
    assert.strictEqual(test.dom.fields.account.value,fb); assert.strictEqual(calls(test,'posts').length,1);
    assert.strictEqual(new URL(calls(test,'posts')[0].url).searchParams.get('account_key'),fb);
    click(test,'post',postA); await settle(); assert(test.dom.fields.comments.textContent.includes('<script>unsafe()</script>'));
    assert.strictEqual(test.dom.fields['post-link'].href,'https://www.facebook.com/fasakhansta/posts/123');
    selectComment(test); assert(test.dom.fields.public.textContent.includes('صفحة فسخانستا'));
    assert.strictEqual(calls(test,'reply').length,0,'Reading and choosing never sends');
    click(test,'channel','instagram'); await settle(); assert.strictEqual(test.dom.fields.account.value,ig);
    assert.strictEqual(new URL(calls(test,'posts').at(-1).url).searchParams.get('account_key'),ig);
    assert.strictEqual(test.dom.fields['reply-text'].value,''); assert.strictEqual(test.dom.fields.send.disabled,true); dispose(test);
    for(const url of ['javascript:alert(1)','http://facebook.com/a','https://facebook.com.evil.test/a','https://evilfacebook.com/a','https://user:secret@facebook.com/a','https://instagram.com:444/a']) {
        const unsafe=fixture(); unsafe.state.postRows=[postRow(postA,{permalink:url})]; await settle(); click(unsafe,'post',postA); await settle();
        assert.strictEqual(unsafe.dom.fields['post-link'].hidden,true,url); dispose(unsafe);
    }
}
async function paginationAndUnknownReplyCounts() {
    const test=fixture(); test.state.nextPosts='opaque/posts+cursor'; test.state.nextComments='opaque/comments+cursor'; test.state.nextReplies='opaque/replies+cursor';
    test.state.commentRows=[commentRow(commentA,{replies_count:null})]; await settle();
    click(test,'more-posts'); await settle(); assert(control(test,'post',key('6'))); assert.strictEqual(new URL(calls(test,'posts').at(-1).url).searchParams.get('cursor'),'opaque/posts+cursor');
    click(test,'post',postA); await settle(); click(test,'more-comments'); await settle(); assert(control(test,'reply',key('7')));
    assert.strictEqual(new URL(calls(test,'comments').at(-1).url).searchParams.get('cursor'),'opaque/comments+cursor');
    click(test,'toggle-replies',commentA); await settle(); assert(test.dom.fields.comments.textContent.includes('رد الصفحة'),'Instagram replies remain accessible when Meta supplies no count');
    click(test,'more-replies',commentA); await settle(); assert(test.dom.fields.comments.textContent.includes('رد أقدم'));
    assert.strictEqual(new URL(calls(test,'replies').at(-1).url).searchParams.get('cursor'),'opaque/replies+cursor');
    test.state.replyRows=[commentRow(key('0'),{text:'رد جديد من ميتا'})]; click(test,'refresh'); await settle();
    assert(test.dom.fields.comments.textContent.includes('رد جديد من ميتا'),'Refresh includes expanded reply threads');
    assert(test.dom.fields.comments.textContent.includes('رد أقدم'),'Quiet reply refresh keeps already loaded older pages'); dispose(test);
}
async function draftsSurviveRefreshAndNavigation() {
    const test=fixture(); await settle(); click(test,'post',postA); await settle(); selectComment(test); draft(test,'مسودة عميل أول');
    selectComment(test,commentB); draft(test,'مسودة عميل ثان'); selectComment(test); assert.strictEqual(test.dom.fields['reply-text'].value,'مسودة عميل أول');
    test.state.commentRows=[commentRow(commentA,{handle:'fresh-encrypted-handle'}),commentRow(commentB)]; click(test,'refresh'); await settle();
    assert.strictEqual(test.dom.fields['reply-text'].value,'مسودة عميل أول','Polling must not erase drafts when encrypted handles rotate');
    assert.strictEqual(test.dom.fields['reply-text'].focused,true);
    click(test,'channel','instagram'); await settle(); click(test,'channel','facebook'); await settle(); click(test,'post',postA); await settle(); selectComment(test);
    assert.strictEqual(test.dom.fields['reply-text'].value,'مسودة عميل أول');
    dispose(test); test.dom=test.build(); test.run(); await settle(); click(test,'post',postA); await settle(); selectComment(test);
    assert.strictEqual(test.dom.fields['reply-text'].value,'مسودة عميل أول','SPA cleanup retains the draft for the same viewer'); dispose(test);
}
async function acceptedRepliesAndDuplicateSubmit() {
    const test=fixture(); await settle(); click(test,'post',postA); await settle(); selectComment(test); draft(test,'رد الموظف');
    test.state.hold.set('reply',true); submit(test); submit(test); assert.strictEqual(calls(test,'reply').length,1);
    assert.strictEqual(control(test,'pending',commentA).dataset.mcState,'SENDING');
    assert.strictEqual(control(test,'pending',commentA).textContent.includes('تم النشر'),false,'A local pending row never claims acceptance');
    assert.strictEqual(test.dom.fields.send.disabled,true);
    const payload=JSON.parse(calls(test,'reply')[0].options.body); assert.strictEqual(payload.message,'رد الموظف'); assert.strictEqual(payload.comment_handle,'comment-3');
    assert.match(payload.client_request_id,/^[a-f0-9-]{36}$/); assert.strictEqual(calls(test,'reply')[0].options.headers['X-CSRF-TOKEN'],'fixture-csrf');
    release(test,'reply'); await settle(); assert.strictEqual(test.dom.fields['reply-text'].value,''); assert.strictEqual(test.dom.fields['send-status'].textContent,'تم النشر');
    assert.strictEqual(control(test,'pending',commentA),undefined,'The server receipt replaces the pending row');
    assert(test.dom.fields.comments.textContent.includes('رد مقبول'),'Only the server accepted receipt becomes a visible reply'); dispose(test);
}
async function uncertainAndFailedOutcomes() {
    for(const mode of ['UNKNOWN','network']) {
        const test=fixture(); await settle(); click(test,'post',postA); await settle(); selectComment(test); draft(test,'رد لا نكرره');
        if(mode==='network') test.state.sendFail=true; else test.state.sendState='UNKNOWN'; submit(test); await settle();
        assert.strictEqual(test.dom.fields['send-status'].textContent,'غير مؤكد'); assert.strictEqual(test.dom.fields['reply-text'].value,'رد لا نكرره');
        assert.strictEqual(control(test,'pending',commentA).dataset.mcState,'UNKNOWN');
        assert.strictEqual(test.dom.fields['reply-text'].disabled,true); submit(test); assert.strictEqual(calls(test,'reply').length,1);
        click(test,'refresh'); await settle(); selectComment(test,commentB); selectComment(test); submit(test); assert.strictEqual(calls(test,'reply').length,1);
        assert(test.storage.get('meta-comments-pending:'+viewer).includes('UNKNOWN'));
        dispose(test); test.dom=test.build(); test.run(); await settle(); click(test,'post',postA); await settle(); selectComment(test); submit(test);
        assert.strictEqual(calls(test,'reply').length,1,'Uncertain sends stay blocked across SPA remounts'); dispose(test);
    }
    const failed=fixture(); await settle(); click(failed,'post',postA); await settle(); selectComment(failed); draft(failed,'إعادة آمنة'); failed.state.sendState='FAILED'; submit(failed); await settle();
    assert.strictEqual(failed.dom.fields['reply-text'].disabled,false); assert.strictEqual(failed.dom.fields['reply-text'].value,'إعادة آمنة');
    assert.strictEqual(control(failed,'pending',commentA),undefined,'A definitive failure removes the pending row without inventing a public reply');
    failed.state.sendState='ACCEPTED'; submit(failed); await settle(); assert.strictEqual(calls(failed,'reply').length,2,'Only explicit FAILED permits retry'); dispose(failed);
}
async function emojiCaretDraftAndLimits() {
    const test=fixture(); await settle(); assert.strictEqual(test.dom.fields['emoji-toggle'].disabled,true);
    click(test,'emoji-toggle'); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,true);
    click(test,'post',postA); await settle(); selectComment(test); draft(test,'أهلا أحمد');
    test.dom.fields['reply-text'].setSelectionRange(5,9); test.dom.fields['reply-text'].dispatchEvent({type:'select'});
    click(test,'emoji-toggle'); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,false);
    const choice=control(test,'emoji','👍'); assert(choice.getAttribute('aria-label').includes('إعجاب'));
    click(test,'emoji','👍'); assert.strictEqual(test.dom.fields['reply-text'].value,'أهلا 👍');
    assert.strictEqual(test.dom.fields['reply-text'].selectionStart,7); assert.strictEqual(test.dom.fields['reply-text'].selectionEnd,7);
    assert.strictEqual(test.dom.fields['reply-text'].focused,true); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,true);
    assert.strictEqual(calls(test,'reply').length,0,'Choosing an emoji edits a draft only');
    selectComment(test,commentB); selectComment(test); assert.strictEqual(test.dom.fields['reply-text'].value,'أهلا 👍');
    click(test,'emoji-toggle'); click(test,'emoji-category','extras'); assert.strictEqual(test.dom.fields['emoji-grid'].children.length,26);
    assert(control(test,'emoji','🚚').getAttribute('aria-label').includes('توصيل'));
    test.document.dispatchEvent({type:'keydown',key:'Escape',preventDefault(){}}); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,true);
    click(test,'emoji-toggle'); test.document.dispatchEvent({type:'click',target:new Element()}); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,true);
    click(test,'emoji-toggle'); click(test,'post',postB); await settle(); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,true);
    click(test,'post',postA); await settle(); selectComment(test); draft(test,'😀'.repeat(999));
    test.dom.fields['reply-text'].setSelectionRange(1998,1998); click(test,'emoji-toggle'); click(test,'emoji-category','reactions'); click(test,'emoji','👍');
    assert.strictEqual(Array.from(test.dom.fields['reply-text'].value).length,1000); assert.strictEqual(test.dom.fields.send.disabled,false,'The 1,000-character limit counts Unicode code points');
    const fullDraft=test.dom.fields['reply-text'].value; click(test,'emoji-toggle'); click(test,'emoji','👍');
    assert.strictEqual(test.dom.fields['reply-text'].value,fullDraft,'Emoji insertion cannot exceed the Unicode limit or destroy text');
    assert.strictEqual(test.dom.fields['send-status'].textContent,'الحد الأقصى');
    dispose(test); assert.strictEqual(test.dom.fields['emoji-picker'].hidden,true); assert.strictEqual((test.document.listeners.keydown||[]).length,0);
    const english=fixture('en'); await settle(); click(english,'post',postA); await settle(); selectComment(english); click(english,'emoji-toggle');
    assert(control(english,'emoji','👍').getAttribute('aria-label').includes('Like')); dispose(english);
}
async function emojiOnlyAndReadPauseWhileWriting() {
    const test=fixture(); await settle(); click(test,'post',postA); await settle(); selectComment(test);
    click(test,'emoji-toggle'); click(test,'emoji','❤️'); assert.strictEqual(test.dom.fields.send.disabled,false);
    test.state.hold.set('comments',true); click(test,'refresh'); await settle(); const interrupted=calls(test,'comments').at(-1);
    test.state.hold.set('reply',true); submit(test); const readCount=test.state.calls.filter(call=>call.endpoint!=='reply').length;
    assert.strictEqual(JSON.parse(calls(test,'reply')[0].options.body).message,'❤️','An emoji-only reply is sent as its exact Unicode sequence');
    assert.strictEqual(interrupted.options.signal.aborted,true,'Starting a write cancels the background read');
    assert.strictEqual(test.dom.fields['emoji-toggle'].disabled,true); assert.strictEqual(control(test,'pending',commentA).dataset.mcState,'SENDING');
    test.document.dispatchEvent({type:'visibilitychange'}); click(test,'refresh'); click(test,'toggle-replies',commentA); click(test,'more-comments');
    assert.strictEqual(test.state.calls.filter(call=>call.endpoint!=='reply').length,readCount,'Refresh, visible polling and expanded threads cannot queue Graph reads while posting');
    assert.strictEqual(test.timers.size,1,'Only the POST timeout remains; the background polling timer is paused');
    selectComment(test,commentB); draft(test,'رد تالي'); submit(test); assert.strictEqual(calls(test,'reply').length,1,'A second comment cannot create a concurrent POST');
    release(test,'comments'); await settle(); release(test,'reply'); await settle();
    assert.strictEqual(test.state.calls.filter(call=>call.endpoint!=='reply').length,readCount,'Accepted receipts do not trigger an immediate list reload');
    assert.strictEqual(test.dom.fields['reply-text'].value,'رد تالي','The accepted result cannot erase another comment draft');
    assert.strictEqual(test.dom.fields.send.disabled,false); submit(test); await settle(); assert.strictEqual(calls(test,'reply').length,2);
    assert.notStrictEqual(JSON.parse(calls(test,'reply')[0].options.body).client_request_id,JSON.parse(calls(test,'reply')[1].options.body).client_request_id,'Distinct explicit replies get distinct request IDs');
    dispose(test);
}
async function staleReadsAndCleanup() {
    const test=fixture(); await settle(); test.state.hold.set('comments',true); click(test,'post',postA); await settle();
    const held=calls(test,'comments')[0]; click(test,'channel','instagram'); await settle(); assert.strictEqual(held.options.signal.aborted,true);
    release(test,'comments'); await settle(); assert.strictEqual(test.dom.fields.comments.textContent,'','Late response from an old account cannot paint');
    click(test,'post',postA); await settle(); selectComment(test); draft(test,'مسودة قبل مغادرة الصفحة'); test.state.hold.set('reply',true); submit(test);
    const pending=calls(test,'reply')[0]; const before=test.state.calls.length; dispose(test);
    assert.strictEqual(pending.options.signal.aborted,true); assert.strictEqual(test.timers.size,0);
    assert.strictEqual((test.dom.host.listeners.click||[]).length,0); assert.strictEqual((test.document.listeners.visibilitychange||[]).length,0);
    release(test,'reply'); await settle(); assert.strictEqual(test.state.calls.length,before,'Navigation cannot trigger follow-up refresh');
}
async function immediateSwitchAndPartialPermissions() {
    const test=fixture(); test.state.hold.set('posts',true); await settle();
    const oldPosts=calls(test,'posts')[0]; click(test,'channel','instagram'); await settle();
    assert.strictEqual(oldPosts.options.signal.aborted,true); assert.strictEqual(calls(test,'posts').length,2,'Account switching starts the new request before an old abort settles');
    release(test,'posts'); await settle(); assert.strictEqual(test.dom.fields.account.value,ig); assert(control(test,'post',postA));
    test.state.hold.set('comments',true); click(test,'post',postA); await settle(); const oldComments=calls(test,'comments')[0];
    click(test,'post',postB); await settle(); assert.strictEqual(oldComments.options.signal.aborted,true);
    assert.strictEqual(calls(test,'comments').length,2,'Post switching starts comments immediately while old transport is pending');
    assert.strictEqual(new URL(calls(test,'comments')[1].url).searchParams.get('post_handle'),'post-2'); release(test,'comments'); await settle(); dispose(test);
    const partial=fixture(); partial.state.permissionAccount=fb; await settle();
    assert.strictEqual(partial.dom.fields.account.disabled,false,'A missing Facebook Graph permission does not revoke dashboard access');
    assert(partial.dom.fields['post-status'].textContent.includes('pages_read_user_content'));
    click(partial,'channel','instagram'); await settle(); assert.strictEqual(partial.dom.fields.account.value,ig); assert(control(partial,'post',postA)); dispose(partial);
}
async function visibilityAndPermissionErrors() {
    const test=fixture(); await settle(); click(test,'post',postA); await settle(); selectComment(test); draft(test,'تظل المسودة');
    const interval=()=>Array.from(test.timers.entries()).find(([id])=>test.state.delays.get(id)===30000);
    const before=calls(test,'comments').length; test.document.hidden=true; interval()[1](); await settle(); assert.strictEqual(calls(test,'comments').length,before);
    test.document.hidden=false; test.document.dispatchEvent({type:'visibilitychange'}); await settle(); assert.strictEqual(calls(test,'comments').length,before+1);
    assert.strictEqual(test.dom.fields['reply-text'].value,'تظل المسودة'); test.navigator.onLine=false; test.window.dispatchEvent({type:'offline'});
    assert.strictEqual(test.dom.fields.send.disabled,true); submit(test); assert.strictEqual(calls(test,'reply').length,0);
    test.navigator.onLine=true; test.state.denied=true; click(test,'refresh'); await settle();
    assert.strictEqual(test.dom.fields.account.disabled,true); assert.strictEqual(test.dom.fields['reply-text'].disabled,true); assert.strictEqual(test.dom.fields.comments.textContent,'');
    assert(test.dom.fields.alert.textContent.includes('instagram_manage_comments') || test.dom.fields['post-status'].textContent==='ممنوع'); dispose(test);
}
(async()=>{
    await initialFilteringSafeRendering(); await paginationAndUnknownReplyCounts(); await draftsSurviveRefreshAndNavigation();
    await acceptedRepliesAndDuplicateSubmit(); await uncertainAndFailedOutcomes(); await emojiCaretDraftAndLimits(); await emojiOnlyAndReadPauseWhileWriting();
    await staleReadsAndCleanup(); await immediateSwitchAndPartialPermissions(); await visibilityAndPermissionErrors();
    console.log('META_COMMENTS_UI_PASS 10 scenarios: safe account filters; pagination; stable drafts; server-confirmed receipts; duplicate/uncertain guards; emoji caret, names and Unicode limits; emoji-only posting with paused reads; SPA cleanup; immediate switching; permission isolation and visible refresh.');
})().catch(error=>{console.error(error.stack); process.exitCode=1;});
