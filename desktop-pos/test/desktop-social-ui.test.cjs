'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-social.js'),'utf8');
function fixture({native=true,local=true,reject=false}={}){
  const handlers=[],calls=[],notes=[];
  const window={addEventListener:(name,fn,capture)=>handlers.push({name,fn,capture})};
  if(native)window.FasakhanstaDesktop={openLive:async kind=>{calls.push(kind);if(reject)throw Error('الاتصال بالإنترنت مطلوب.');return {live:true};}};
  const host={prepend:node=>notes.push(node)},document={body:{dataset:{dashboardLocal:local?'1':'0'},prepend:host.prepend},
    getElementById:id=>notes.find(note=>note.id===id),querySelector:()=>host,
    createElement:()=>({setAttribute(){},textContent:''})};
  const location={href:'http://127.0.0.1:45123/admin/dashboard',origin:'http://127.0.0.1:45123'};
  vm.runInNewContext(source,{window,document,location,URL});
  async function click(href){const state={prevented:false,stopped:false};const link={href};
    const event={target:{closest:()=>link},preventDefault(){state.prevented=true;},stopImmediatePropagation(){state.stopped=true;}};
    handlers[0]?.fn(event);await Promise.resolve();await Promise.resolve();return state;}
  return {handlers,calls,notes,location,click};
}
test('prepared local social links run at window capture before the SPA and retain the main dashboard URL',async()=>{
  const f=fixture(),before=f.location.href;assert.equal(f.handlers[0].name,'click');assert.equal(f.handlers[0].capture,true);
  for(const href of ['/admin/meta','/admin/meta/comments','/admin/meta/whatsapp','/admin/whatsapp']){
    const click=await f.click(f.location.origin+href);assert.equal(click.prevented,true);assert.equal(click.stopped,true);
  }
  assert.deepEqual(f.calls,['messages','comments','messages','messages']);assert.equal(f.location.href,before);
});
test('ordinary pages, foreign links and unsupported Meta settings remain outside the bounded native live action',async()=>{
  const f=fixture();for(const href of [f.location.origin+'/admin/branch-expenses',f.location.origin+'/admin/meta/connection','https://foreign.test/admin/meta']){
    const click=await f.click(href);assert.equal(click.prevented,false);assert.equal(click.stopped,false);
  }assert.deepEqual(f.calls,[]);
});
test('normal browsers and server views receive no local link override',()=>{
  assert.equal(fixture({native:false}).handlers.length,0);assert.equal(fixture({local:false}).handlers.length,0);
});
test('an offline or expired native session produces text feedback without navigating away from local work',async()=>{
  const f=fixture({reject:true}),before=f.location.href;await f.click(f.location.origin+'/admin/meta');
  assert.equal(f.notes.length,1);assert.equal(f.notes[0].textContent,'الاتصال بالإنترنت مطلوب.');assert.equal(f.notes[0].innerHTML,undefined);assert.equal(f.location.href,before);
});
