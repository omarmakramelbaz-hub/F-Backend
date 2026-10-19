'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const RemoteAttempts=require('../src/dashboard-remote-attempts.cjs');
const origin='https://board.test';
test('native order-board reservations bind only exact source/positive identity POST actions',()=>{
 const attempts=new RemoteAttempts({});
 for(const source of ['legacy','store','service','partner_service']){
  const url=origin+'/admin/order-board/'+source+'/12/action';assert.equal(attempts.supported({url,method:'POST'}),true);
  for(const method of ['GET','HEAD','PUT','PATCH','DELETE'])assert.equal(attempts.supported({url,method}),false);
 }
 for(const url of ['/admin/order-board/other/12/action','/admin/order-board/legacy/0/action','/admin/order-board/legacy/12/action/','/admin/order-board/legacy/12/items'])
  assert.equal(attempts.supported({url:origin+url,method:'POST'}),false);
});
function browser(local=true){
 const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/order-board.js'),'utf8');
 const start=source.indexOf('    function journalCommand('),end=source.indexOf('\n    function setLive(',start);
 assert.ok(start>=0&&end>start);const records=new Map();
 const context={document:{body:{dataset:{dashboardLocal:local?'1':'0',dashboardRemoteAttempts:'1',dashboardActor:'1:admin'}}},
  window:{FasakhanstaDesktop:{},location:{origin,href:origin+'/admin/applies-orders'}},crypto,URL,translated:key=>key,
  sessionStorage:{getItem:key=>records.get(key)||null,setItem:(key,value)=>records.set(key,value)}};
 vm.createContext(context);vm.runInContext(source.slice(start,end),context);
 const form={dataset:{desktopBoardGeneration:local?crypto.randomUUID():'server'},getAttribute:()=>'/admin/order-board/legacy/12/action'};
 const data=(action='complete',status='accepted')=>new URLSearchParams({_token:crypto.randomUUID(),action,expected_status:status,expected_accepted_notify:'yes',expected_revision:''});
 return {context,records,form,data,run:(form,data)=>context.journalCommand(form,data)};
}
test('actual board intent keeps its UUID across lost replies, DOM replacement and script restart',()=>{
 const f=browser(),first=f.data();f.run(f.form,first);const id=first.get('idempotency_key');assert.match(id,/^[a-f0-9-]{36}$/i);
 const replacement={...f.form,dataset:{...f.form.dataset}},retry=f.data();f.run(replacement,retry);assert.equal(retry.get('idempotency_key'),id);assert.equal(retry.get('_desktop_command'),id);
 vm.runInContext('journalCommand',f.context)(replacement,retry);assert.equal(retry.get('idempotency_key'),id);
 for(const change of [()=>{f.context.document.body.dataset.dashboardActor='2:admin';},()=>{f.form.dataset.desktopBoardGeneration=crypto.randomUUID();}]){
  change();const next=f.data();f.run(f.form,next);assert.notEqual(next.get('idempotency_key'),id);
 }
 const different=f.data('cancel');f.run(f.form,different);assert.notEqual(different.get('idempotency_key'),retry.get('idempotency_key'));
});
test('board intent refuses missing generation or unavailable durable browser storage before sending',()=>{
 const f=browser();f.form.dataset.desktopBoardGeneration='';assert.throws(()=>f.run(f.form,f.data()));
 f.form.dataset.desktopBoardGeneration=crypto.randomUUID();f.context.sessionStorage.setItem=()=>{throw Error('storage denied');};assert.throws(()=>f.run(f.form,f.data()),/storage denied/);
 const connected=browser(false),data=connected.data();connected.run(connected.form,data);assert.ok(data.get('idempotency_key'));
 delete connected.context.window.FasakhanstaDesktop;const ordinary=connected.data();connected.run(connected.form,ordinary);assert.equal(ordinary.has('idempotency_key'),false);
});
