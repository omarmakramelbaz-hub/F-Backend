'use strict';
// DOM behavior only: no browser rendering, Meta calls or real message sends.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const {JSDOM} = require('jsdom');
const root = path.join(__dirname,'..');
const key = value => value.repeat(64);
const post = (id,text) => ({key:key(id),handle:'post-'+id,text,created_at:'2026-10-18T20:00:00Z',permalink:'https://facebook.com/posts/123'});
const comment = (id,text) => ({key:key(id),handle:'comment-'+id,text,author:'عميل',created_at:'2026-10-18T20:01:00Z',can_reply:true,replies_count:0});
const settle = async () => { for(let n=0;n<12;n++) await new Promise(resolve=>setImmediate(resolve)); };
function fixture(legacy=false, options={}) {
    const view=fs.readFileSync(path.join(root,'resources/views/admin/meta/comments.blade.php'),'utf8');
    const markup=view.slice(view.indexOf('<div class="content-wrapper'),view.indexOf('@endsection')).replace(/{{[\s\S]*?}}/g,'label');
    const dom=new JSDOM(markup+'<script id="meta-comments-bootstrap" type="application/json"></script>',{url:'https://dashboard.test/admin/meta/comments',runScripts:'outside-only',pretendToBeVisual:true});
    const w=dom.window, host=w.document.getElementById('meta-comments'), calls=[];
    const config={viewer_key:key('a'),csrf:'fixture',locale:'ar',labels:{choose_post:'اختر بوست',choose_comment:'اختر تعليق',reply:'رد',send:'نشر',accepted:'تم',loading:'تحميل'},accounts:[{account_key:key('b'),channel:'facebook',label:'الصفحة'}]};
    w.document.getElementById('meta-comments-bootstrap').textContent=JSON.stringify(config);
    w.DashboardSPA={isCurrentPage:()=>true,onCleanup:()=>{}};
    const rows=options.postRows || [post('1','البوست الأول'),post('2','البوست الثاني')];
    w.fetch=async(url,options)=>{
        const u=new URL(url), endpoint=u.pathname.split('/').pop(); calls.push({endpoint,url:u,options});
        let data={success:true};
        if(endpoint==='posts') Object.assign(data,{posts:u.searchParams.has('cursor')?[post('6','بوست أقدم')]:rows,next_cursor:u.searchParams.has('cursor')?null:'older-page'});
        if(endpoint==='comments') Object.assign(data,{comments:[comment('3','السعر كام؟')],next_cursor:null});
        if(endpoint==='reply') Object.assign(data,{state:'ACCEPTED',reply:comment('9','رد الصفحة')});
        return {ok:true,status:200,redirected:false,headers:{get:()=> 'application/json'},json:async()=>data};
    };
    function itemRect(item) {
        const list=host.querySelector('[data-mc-posts]'), siblings=Array.from(list.children);
        const height=el=>el.querySelector('.mc-comments-panel')?680:180;
        const top=120+siblings.slice(0,siblings.indexOf(item)).reduce((sum,el)=>sum+height(el),0)-host.scrollTop;
        return {top,bottom:top+height(item),height:height(item)};
    }
    w.HTMLElement.prototype.getBoundingClientRect=function() {
        if(this===host) return {top:0,bottom:200,height:200};
        if(this.parentElement===host.querySelector('[data-mc-posts]')) return itemRect(this);
        if(this.hasAttribute('data-mc-more-posts')) {
            const items=Array.from(host.querySelector('[data-mc-posts]').children),top=120+items.reduce((sum,item)=>sum+(item.querySelector('.mc-comments-panel')?680:180),0)-host.scrollTop;
            return {top,bottom:top+30,height:30};
        }
        return {top:0,bottom:100,height:100};
    };
    let legacyMounts=0, legacyDestroys=0;
    if(legacy) {
        const old={mount:()=>{legacyMounts++;},destroy:()=>{legacyDestroys++;}};
        w.DashboardMetaComments=old;
        w.document.addEventListener('dashboard:page-mounted',old.mount);
        w.document.addEventListener('dashboard:before-unload',old.destroy);
    }
    w.eval(fs.readFileSync(path.join(root,'public/js/dashboard-meta-comments.js'),'utf8'));
    host.legacyCounts=()=>({legacyMounts,legacyDestroys});
    return {dom,w,host,calls};
}
async function run() {
    let f=fixture(); await settle();
    let {host,w}=f, list=host.querySelector('[data-mc-posts]'), panel=host.querySelector('.mc-comments-panel');
    assert.equal(list.children.length,2); assert(panel.hidden,'No detached comments area before selection');
    host.querySelector('[data-mc-post="'+key('1')+'"]').click(); await settle();
    assert.equal(panel.parentElement,list.children[0]); assert.equal(panel.hidden,false);
    assert.equal(list.children.length,2); assert.equal(list.children[0].firstElementChild.getAttribute('aria-expanded'),'true');
    assert.equal(panel.parentElement.firstElementChild.dataset.mcPost,key('1'));
    host.querySelector('[data-mc-reply]').click(); await settle();
    const input=host.querySelector('[data-mc-reply-text]');
    input.value='رد محفوظ 🙏'; input.dispatchEvent(new w.Event('input',{bubbles:true})); input.focus(); input.setSelectionRange(3,5);
    host.scrollTop=75; host.querySelector('[data-mc-more-posts]').click(); await settle();
    assert.equal(list.children.length,3); assert.equal(panel.parentElement,list.children[0]);
    assert.equal(w.document.activeElement,input); assert.equal(input.value,'رد محفوظ 🙏'); assert.equal(input.selectionStart,3); assert.equal(input.selectionEnd,5); assert.equal(host.scrollTop,75);
    assert.equal(host.querySelectorAll('.mc-comments-panel').length,1,'Replies use the same guarded live form');
    host.querySelector('[data-mc-form]').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true})); await settle();
    assert.equal(f.calls.filter(c=>c.endpoint==='reply').length,1,'A deliberate submit sends once');
    host.querySelector('[data-mc-post="'+key('2')+'"]').click(); await settle();
    assert.equal(panel.parentElement,list.children[1]); assert.equal(list.children[0].firstElementChild.getAttribute('aria-expanded'),'false');
    assert.equal(list.children[1].firstElementChild.getAttribute('aria-expanded'),'true');
    w.DashboardMetaComments.destroy(); assert.equal(panel.parentElement,host.querySelector('.mc-workspace'));
    const after=f.calls.length; host.dispatchEvent(new w.Event('scroll')); await settle(); assert.equal(f.calls.length,after,'Cleanup stops timeline reads');
    f.dom.window.close();
    f=fixture(); await settle();
    assert.equal(f.calls.filter(c=>c.endpoint==='posts').length,1);
    f.host.scrollTop=200; f.host.dispatchEvent(new f.w.Event('scroll')); await settle();
    assert.equal(f.host.querySelector('[data-mc-posts]').children.length,3,'Near-end scroll loads older posts');
    assert.equal(f.calls.filter(c=>c.endpoint==='posts').length,2);
    assert.equal(f.calls.filter(c=>c.endpoint==='reply').length,0,'Timeline navigation never sends a reply');
    f.dom.window.close();
    f=fixture(true); await settle();
    assert.equal(f.host.legacyCounts().legacyDestroys,1,'The previous SPA module is disposed before upgrading');
    f.w.document.dispatchEvent(new f.w.Event('dashboard:page-mounted')); await settle();
    assert.equal(f.host.legacyCounts().legacyMounts,0,'Legacy SPA listeners are removed');
    assert.equal(f.calls.filter(c=>c.endpoint==='posts').length,1,'Remount does not duplicate reads');
    f.dom.window.close();
    console.log('META_COMMENTS_TIMELINE_PASS selection placement, shared reply form, preserved focus/caret/draft/scroll, deliberate send, older-post scroll, and cleanup; rendered=false');
}
module.exports={fixture,settle,key,post};
if(require.main===module) run().catch(error=>{console.error(error);process.exitCode=1;});
