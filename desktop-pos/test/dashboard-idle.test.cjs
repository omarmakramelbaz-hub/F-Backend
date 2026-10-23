'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const code=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/dashboard-idle.js'),'utf8');
function fixture(pathname='/admin/employees',hud=null){
  let now=0,next=0;const timers=new Map(),listeners=new Map(),visits=[];
  const location={pathname,origin:'https://example.test',href:'https://example.test'+pathname,assign:url=>visits.push(url)};
  const context=vm.createContext({window:{},location,URL,Date:{now:()=>now},
    document:{currentScript:{dataset:{homeUrl:'https://example.test/admin/dashboard'}},hidden:false,
      getElementById:()=>hud,addEventListener:(name,fn)=>listeners.set(name,fn)},
    setTimeout:(fn,delay)=>{timers.set(++next,{fn,due:now+delay});return next;},clearTimeout:id=>timers.delete(id)});
  vm.runInContext(code,context);
  return{context,listeners,visits,timers,runAgain:()=>vm.runInContext(code,context),
    advance(ms){const end=now+ms;while(true){const first=[...timers].sort((a,b)=>a[1].due-b[1].due)[0];if(!first||first[1].due>end)break;now=first[1].due;timers.delete(first[0]);first[1].fn();}now=end;}};
}
test('idle waits the full five minutes before returning to the same-origin planets home',()=>{
 const f=fixture();f.advance(299999);assert.equal(f.visits.length,0);f.advance(1);assert.deepEqual(f.visits,['https://example.test/admin/dashboard']);
});
test('mouse movement, clicks, typing, scrolling and touch each restart the idle period',()=>{
 for(const event of ['pointermove','pointerdown','keydown','wheel','touchstart']){const f=fixture();f.advance(299000);f.listeners.get(event)();f.advance(299999);assert.equal(f.visits.length,0,event);f.advance(1);assert.equal(f.visits.length,1,event);}
});
test('home with its planets visible stays open, while the original home returns to the planets',()=>{
 const open=fixture('/admin/dashboard',{hidden:false});open.advance(900000);assert.equal(open.visits.length,0);
 const closed=fixture('/admin/dashboard',{hidden:true});closed.advance(300000);assert.equal(closed.visits.length,1);
});
test('SPA script reuse installs one timer and one set of activity listeners',()=>{
 const f=fixture();f.runAgain();assert.equal(f.timers.size,1);assert.equal(f.listeners.size,6);f.advance(300000);assert.equal(f.visits.length,1);
});
