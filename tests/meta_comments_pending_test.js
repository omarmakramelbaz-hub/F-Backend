'use strict';
const assert=require('assert');
const {fixture,settle,key}=require('./meta_comments_timeline_test');
function setup(mode='normal') {
    const clock={now:0,next:0,timers:new Map()};
    const f=fixture(false,{beforeEval(w){
        w.Date.now=()=>1800000000000+clock.now;
        w.setTimeout=(fn,delay)=>{const id=++clock.next;clock.timers.set(id,{fn,due:clock.now+delay});return id;};
        w.clearTimeout=id=>clock.timers.delete(id);
    }});
    const base=f.w.fetch, scans=[];
    f.w.fetch=async(url,options)=>{
        const u=new URL(url); if(u.searchParams.get('pending')!=='1') return base(url,options);
        assert.equal(options.method,'GET'); scans.push(u);
        if(mode==='limited') return {ok:false,status:429,headers:{get:()=> 'application/json'},json:async()=>({success:false,reason:'RATE_LIMITED'})};
        let data={success:true};
        if(u.pathname.endsWith('/replies')) Object.assign(data,{has_business_reply:u.searchParams.get('cursor')==='replyMore',unknown_authors:false,next_cursor:u.searchParams.get('cursor')==='replyMore'?null:'replyMore'});
        else if(u.searchParams.get('post_handle')==='post-2') Object.assign(data,{comments:[],next_cursor:null});
        else if(u.searchParams.has('cursor')) Object.assign(data,{comments:[{key:key('6'),handle:'comment-6',status:'pending'}],next_cursor:null});
        else Object.assign(data,{comments:[{key:key('3'),handle:'comment-3',status:'pending'},{key:key('4'),handle:'comment-4',status:'answered'},{key:key('5'),handle:'comment-5',status:'checking',reply_cursor:'replyStart',unknown_authors:false}],next_cursor:'rootOlder'});
        return {ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>data};
    };
    return Object.assign(f,{clock,scans});
}
async function advance(f,ms) {
    const until=f.clock.now+ms;
    for(let n=0;n<300;n++) {
        const next=Array.from(f.clock.timers.entries()).filter(([,v])=>v.due<=until).sort((a,b)=>a[1].due-b[1].due)[0];
        if(!next){f.clock.now=until;return;}
        const[id,task]=next;f.clock.timers.delete(id);f.clock.now=task.due;task.fn();await settle();
    }
    throw new Error('Unbounded counter timer');
}
(async()=>{
    let f=setup();await settle();
    const badge=id=>f.host.querySelector('[data-mc-post="'+key(id)+'"] .mc-unanswered-badge');
    assert.equal(badge('1').textContent,'…');
    await advance(f,1600);assert.equal(badge('1').textContent,'1+','Partial pages are not represented as a completed count');
    await advance(f,1600);assert(badge('2').hidden,'A verified zero has no notification badge');
    await advance(f,5000);assert.equal(badge('1').textContent,'2','Every root and reply page is included');
    assert(!badge('1').classList.contains('is-checking'));
    const button=()=>f.host.querySelector('[data-mc-post="'+key('1')+'"]');
    button().click();await settle();assert.equal(f.host.querySelectorAll('iframe').length,1);
    button().click();await settle();assert.equal(f.host.querySelectorAll('iframe').length,0,'Second press closes the original post and comments');
    assert.equal(badge('1').textContent,'2','Opening/closing never marks a comment replied');
    button().click();await settle();
    f.host.querySelector('[data-mc-reply="'+key('3')+'"]').click();await settle();
    const input=f.host.querySelector('[data-mc-reply-text]');input.value='تم الرد';input.dispatchEvent(new f.w.Event('input',{bubbles:true}));
    f.host.querySelector('[data-mc-form]').dispatchEvent(new f.w.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(badge('1').textContent,'1','Only a confirmed accepted reply decrements the badge');
    const n=f.scans.length;f.w.DashboardMetaComments.destroy();await advance(f,20000);assert.equal(f.scans.length,n,'Cleanup cancels background scans');f.dom.window.close();
    f=setup('limited');await settle();await advance(f,1600);assert.equal(f.scans.length,1);await advance(f,60000);assert.equal(f.scans.length,1,'Provider rate limits pause the whole badge queue');f.dom.window.close();
    f=setup();await settle();Object.defineProperty(f.w.document,'hidden',{value:true,configurable:true});await advance(f,15000);assert.equal(f.scans.length,0,'Hidden pages do not scan');f.dom.window.close();
    console.log('META_PENDING_UI_PASS toggle close, complete/partial/zero counts, all root/reply pages, accepted reply decrement, hidden pause, rate-limit backoff and cleanup; liveMeta=false');
})().catch(e=>{console.error(e);process.exitCode=1;});
